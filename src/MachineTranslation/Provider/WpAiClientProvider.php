<?php
/**
 * WordPress AI Client translation provider (WP 7.0+).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation\Provider;

use PerfLocale\MachineTranslation\AbstractProvider;
use PerfLocale\MachineTranslation\CallDeadline;
use PerfLocale\MachineTranslation\DeadlineReachedException;
use PerfLocale\MachineTranslation\ContextAwareProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine translation via the WordPress AI Client API, with the AI provider the site has connected.
 *
 * Without wp_ai_client_prompt() (WP 6.x), or when wp_supports_ai() is false, is_configured() is false and the
 * provider is not offered; `perflocale/mt/wp_ai_client_resolver` installs a custom resolver.
 */
final class WpAiClientProvider extends AbstractProvider implements ContextAwareProviderInterface {

	/**
	 * Characters of the current translate call's send (text and context).
	 */
	private int $call_chars = 0;

	/**
	 * Client calls of the current translate call so far.
	 */
	private int $tries = 0;

	/**
	 * Default model preference for core: small models of OpenAI, Anthropic and Google; the first one offered wins.
	 *
	 * Each advertises temperature support, so none drops out while the default temperature is sent; Anthropic is
	 * listed by alias and by dated id because its model list may carry either.
	 *
	 * @var list<string>
	 */
	private const DEFAULT_MODEL_PREFERENCE = [
		'gpt-5.4-mini',
		'gpt-4.1-mini',
		'gpt-4o-mini',
		'claude-haiku-4-5',
		'claude-haiku-4-5-20251001',
		'gemini-2.5-flash',
		'gemini-2.5-flash-lite',
	];

	/**
	 * Transient holding the last readiness answer (is_ready_for_text()); it has an expiry, so it is never autoloaded.
	 */
	private const READY_TRANSIENT = 'perflocale_mt_wp_ai_ready';

	/**
	 * Exception code of this class's own fixed messages, which are shown as they are (upstream text never is).
	 */
	private const OWN_MESSAGE = -1;

	/**
	 * Timeout of the last client call after the deadline clamp, named when it timed out.
	 *
	 * @var float
	 */
	private static float $last_timeout = 0.0;

	/**
	 * Routes (route_key()) whose model refused the temperature in this request.
	 *
	 * Later calls on the route send none instead of paying for a rejected request first.
	 *
	 * @var array<string, true>
	 */
	private static array $temperature_rejected = [];

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return __( 'WordPress AI Client', 'perflocale' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'wp_ai_client';
	}

	/**
	 * Whether the AI Client API is available on this request; cheap (no model listing), as every get_provider() asks.
	 *
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return $this->resolve_client_callback() !== null;
	}

	/**
	 * Whether a connected AI provider can generate text at this moment (stricter than is_configured()).
	 *
	 * Core lists each provider's models to answer (HTTP without an object cache), so the answer is cached per
	 * connectors fingerprint: docs-ai/11-code-rationale.md, "WpAiClientProvider: readiness cache".
	 *
	 * @return bool
	 */
	public function is_ready_for_text(): bool {
		if ( $this->custom_resolver() !== null ) {
			return true;
		}

		if ( $this->resolve_client_callback() === null ) {
			return false;
		}

		$args        = $this->client_args( true );
		$provider    = isset( $args['provider'] ) && is_string( $args['provider'] ) ? trim( $args['provider'] ) : '';
		$fingerprint = self::connectors_fingerprint( $provider );
		$cached      = get_transient( self::READY_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['f'], $cached['r'] ) && $cached['f'] === $fingerprint ) {
			return (bool) $cached['r'];
		}

		$ready = false;

		try {
			// resolve_client_callback() above has confirmed the AI Client.
			$builder = self::new_builder( 'Hello' );

			if ( is_object( $builder ) ) {
				// The snake_case name on core's builder runs wp_supports_ai()
				// and the wp_ai_client_prevent_prompt policy, as generate_text() does.
				$check = [ $builder, method_exists( $builder, '__call' ) ? 'is_supported_for_text_generation' : 'isSupportedForTextGeneration' ];
				$ready = is_callable( $check ) && true === $check();
			}

			// The support check looks across every provider; a call routed to
			// one provider also needs that provider to be configured.
			if ( $ready && $provider !== '' ) {
				$registry_factory = [ '\WordPress\AiClient\AiClient', 'defaultRegistry' ];
				$registry         = is_callable( $registry_factory ) ? $registry_factory() : null;
				$is_configured    = is_object( $registry ) ? [ $registry, 'isProviderConfigured' ] : null;
				$ready            = is_callable( $is_configured ) && true === $is_configured( $provider );
			}
		} catch ( \Throwable $e ) {
			$ready = false;
		}

		set_transient(
			self::READY_TRANSIENT,
			[
				'f' => $fingerprint,
				'r' => $ready ? 1 : 0,
			],
			$ready ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS
		);

		return $ready;
	}

	/**
	 * Characters per piece of long content for the AI provider core will use: the largest size whose answers came back
	 * within 7 s at p90 on the real models (OpenAI 2,500; Anthropic and any other 1,000). No request is made.
	 *
	 * @since 1.0.8
	 * @return int
	 */
	public function default_piece_chars(): int {
		return 'openai' === $this->text_provider() ? 2500 : 1000;
	}

	/**
	 * The AI provider core routes a call to: the `provider` arg, else the first model preference whose provider is
	 * registered with a key (env var, constant or Connectors setting); '' when unknown (a custom resolver).
	 *
	 * @return string
	 */
	private function text_provider(): string {
		if ( null !== $this->custom_resolver() ) {
			return '';
		}

		$args = $this->client_args( false );

		if ( is_string( $args['provider'] ?? null ) && '' !== trim( $args['provider'] ) ) {
			return strtolower( trim( $args['provider'] ) );
		}

		$keyed = [];

		foreach ( self::connector_keys() as $id => $keys ) {
			if ( [] !== array_filter( $keys, static fn( $key ): bool => is_string( $key ) && '' !== trim( $key ) ) ) {
				$keyed[ strtolower( (string) $id ) ] = true;
			}
		}

		foreach ( is_array( $args['model'] ?? null ) ? $args['model'] : [] as $entry ) {
			$id = match ( true ) {
				is_array( $entry ) && is_string( $entry[0] ?? null ) => strtolower( $entry[0] ),
				is_string( $entry ) => self::provider_of_model( $entry ),
				default => '',
			};

			if ( isset( $keyed[ $id ] ) ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * The provider id of a model id by its family name; '' when unknown.
	 *
	 * @param string $model Model id.
	 * @return string
	 */
	private static function provider_of_model( string $model ): string {
		$model = strtolower( $model );

		return match ( true ) {
			str_starts_with( $model, 'gpt-' ) || 1 === preg_match( '/^o\d/', $model ) => 'openai',
			str_starts_with( $model, 'claude-' ) => 'anthropic',
			str_starts_with( $model, 'gemini-' ) => 'google',
			default => '',
		};
	}

	/**
	 * Fingerprint of what decides readiness: the registered providers, each one's API key wherever core reads it
	 * (`<ID>_API_KEY` env var or constant, option `connectors_ai_<id>_api_key`; hashed), and the `provider` arg.
	 *
	 * @param string $provider Provider id from the args ('' = any).
	 * @return string
	 */
	private static function connectors_fingerprint( string $provider ): string {
		$parts = [ 'provider' => $provider ];

		foreach ( self::connector_keys() as $id => $keys ) {
			$parts['keys'][ $id ] = md5( (string) wp_json_encode( $keys ) );
		}

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Each registered connector's API key wherever core reads it: id => [ env var, constant, option ].
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private static function connector_keys(): array {
		$registry_factory = [ '\WordPress\AiClient\AiClient', 'defaultRegistry' ];
		$registry         = is_callable( $registry_factory ) ? $registry_factory() : null;
		$list_ids         = is_object( $registry ) ? [ $registry, 'getRegisteredProviderIds' ] : null;
		$out              = [];

		foreach ( is_callable( $list_ids ) ? (array) $list_ids() : [] as $id ) {
			if ( is_string( $id ) && $id !== '' ) {
				$sanitized = str_replace( '-', '_', $id );
				// Core's naming for AI connector keys (wp-includes/connectors.php).
				$key_name = strtoupper( (string) preg_replace( '/([a-z])([A-Z])/', '$1_$2', $sanitized ) ) . '_API_KEY';

				$out[ $id ] = [
					getenv( $key_name ),
					defined( $key_name ) ? constant( $key_name ) : null,
					get_option( 'connectors_ai_' . $sanitized . '_api_key', '' ),
				];
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'), sent to the model as a prompt rule.
	 *
	 * @throws \RuntimeException When the AI Client API is unavailable or the underlying client call fails.
	 * @throws DeadlineReachedException When the call does not fit before a background slice's deadline.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		return $this->translate_one( $text, $source_lang, $target_lang, $fast_fail, $format, '' );
	}

	/**
	 * Translate texts, each with its context: one prompt per text, as translate_batch() sends them; the context goes
	 * into the prompt as reference material (build_prompt()).
	 *
	 * @since 1.0.8
	 *
	 * @param array<int, string> $texts       Texts to translate.
	 * @param array<int, string> $contexts    Context per text, parallel to `$texts`; '' for none.
	 * @param string             $source_lang Source language code.
	 * @param string             $target_lang Target language code.
	 * @param bool               $fast_fail   Cap retries and timeout.
	 * @param string             $format      'html' or 'text'.
	 * @return array<int, string> Translated texts, in the order of `$texts`.
	 *
	 * @throws \RuntimeException When the AI Client API is unavailable or the underlying client call fails.
	 */
	public function translate_batch_with_context( array $texts, array $contexts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		$contexts = array_values( $contexts );
		$results  = [];

		foreach ( array_values( $texts ) as $i => $text ) {
			$context   = $contexts[ $i ] ?? '';
			$results[] = $this->translate_one( (string) $text, $source_lang, $target_lang, $fast_fail, $format, $context );
		}

		return $results;
	}

	/**
	 * Whether the context is billed: it is part of the prompt, which the AI provider bills.
	 *
	 * @since 1.0.8
	 *
	 * @return bool
	 */
	public function context_billed(): bool {
		return true;
	}

	/**
	 * Translate one text, with its context when there is one. A reply holding the context's boundary token repeats the
	 * context: it comes back '' for the caller's quality check to reject. A `text_before_send` or `result` filter that
	 * answers with no string fails the text.
	 *
	 * @param string $text        Text to translate.
	 * @param string $source_lang Source language slug.
	 * @param string $target_lang Target language slug.
	 * @param bool   $fast_fail   Cap the timeout.
	 * @param string $format      'html' or 'text'.
	 * @param string $context     Context, '' for none.
	 * @return string
	 *
	 * @throws \RuntimeException When the AI Client API is unavailable or the underlying client call fails.
	 */
	private function translate_one( string $text, string $source_lang, string $target_lang, bool $fast_fail, string $format, string $context ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, $this->get_id(), $target_lang );

		if ( ! is_string( $text ) ) {
			throw new \RuntimeException( esc_html__( 'A filter on the text before it is sent returned no text; nothing was sent.', 'perflocale' ) );
		}

		if ( '' !== $context ) {
			/** This filter is documented in DeepLProvider::translate(). */
			$context = \PerfLocale\Util\Scalar::str( apply_filters( 'perflocale/machine_translation/text_before_send', $context, $this->get_id(), $target_lang ) );
		}

		$this->guard_send( \PerfLocale\Util\Utf8::length( $text ) + \PerfLocale\Util\Utf8::length( $context ) );

		$client = $this->resolve_client_callback();

		if ( $client === null ) {
			throw new \RuntimeException(
				esc_html__( 'The WordPress AI Client API is not available on this site.', 'perflocale' )
			);
		}

		// Breaker gate: this provider calls a PHP callback, not make_request() (the HTTP providers' gate), and an
		// outage would otherwise burn the API quota one call at a time. Same key as the Site Health card.
		$breaker_key = 'mt_' . $this->get_id();

		if ( \PerfLocale\Concurrency\Breaker::is_open( $breaker_key ) ) {
			$status = \PerfLocale\Concurrency\Breaker::status( $breaker_key );
			throw new \PerfLocale\Concurrency\BreakerOpenException(
				esc_html( $breaker_key ),
				esc_html(
					sprintf(
						/* translators: 1: Provider name, 2: seconds until probe */
						__( 'Translation provider %1$s is temporarily unreachable (circuit breaker open; retry in %2$ds).', 'perflocale' ),
						$this->get_name(),
						(int) ( $status['cooldown_remaining'] ?? 0 )
					)
				)
			);
		}

		// The send guard_send() admitted, for each client call below.
		$this->call_chars = $this->sending;
		$this->sending    = 0;
		$this->tries      = 0;

		$boundary = '' === $context ? '' : self::boundary();
		$prompt   = $this->build_prompt( $text, $source_lang, $target_lang, $format, $context, $boundary );
		$args     = $this->client_args( $fast_fail );
		$route    = self::route_key( $args );

		// This route's model refused the temperature in this request: send none straight away.
		if ( isset( self::$temperature_rejected[ $route ] ) ) {
			unset( $args['temperature'] );
		}

		try {
			$result = $this->paced_call( $client, $prompt, $args, $breaker_key, $fast_fail );
		} catch ( DeadlineReachedException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// A model refusing the temperature (HTTP 400, not billed), or core finding no model with it, is no provider
			// fault: retry once without it; only the retry's outcome counts on the breaker.
			if ( ! isset( $args['temperature'] ) || ! ( self::is_temperature_rejection( $e ) || self::is_no_model_error( $e ) ) ) {
				$this->fail( $e, $breaker_key, self::$last_timeout );
			}

			self::$temperature_rejected[ $route ] = true;
			unset( $args['temperature'] );

			try {
				$result = $this->paced_call( $client, $prompt, $args, $breaker_key, $fast_fail );
			} catch ( DeadlineReachedException $retry_error ) {
				throw $retry_error;
			} catch ( \Throwable $retry_error ) {
				$this->fail( $retry_error, $breaker_key, self::$last_timeout );
			}
		}

		// A success only after extraction: an unreadable answer must count on the breaker, or a model that stopped
		// answering usefully keeps being paid for.
		try {
			$translated = $this->extract_translation_from_response( $result, $text );
		} catch ( \Throwable $e ) {
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'malformed' );
			throw $e;
		}

		\PerfLocale\Concurrency\Breaker::record_success( $breaker_key );

		if ( '' === $context ) {
			$this->track_usage( $text );
		} else {
			$this->track_usage_chars( \PerfLocale\Util\Utf8::length( $text ) + \PerfLocale\Util\Utf8::length( $context ) );

			if ( str_contains( $translated, $boundary ) ) {
				return '';
			}
		}

		$result = apply_filters( 'perflocale/machine_translation/result', $translated, $text, $this->get_id() );

		if ( ! is_string( $result ) ) {
			throw new \RuntimeException( esc_html__( 'A filter on the translation returned no text.', 'perflocale' ) );
		}

		return $result;
	}

	/**
	 * A random token that marks where the context of a prompt starts and ends.
	 *
	 * @return string
	 */
	private static function boundary(): string {
		return 'PFLCTX' . strtoupper( bin2hex( random_bytes( 8 ) ) );
	}

	/**
	 * Record a failed call on the breaker and throw the surfaced error.
	 *
	 * @param \Throwable $e           What the client threw.
	 * @param string     $breaker_key Breaker key for this provider.
	 * @param float      $timeout     The call's timeout in seconds, named when it timed out.
	 * @return never
	 *
	 * @throws \RuntimeException Always; its code is the HTTP status when known (504: timed out, 401: refused key).
	 */
	private function fail( \Throwable $e, string $breaker_key, float $timeout = 0.0 ): never {
		// Recorded before the throw, so the next caller meets the open breaker; an auth error opens it on the first
		// hit, as no retry fixes a bad key.
		$reason             = self::classify_error( $e );
		$threshold_override = $reason === 'auth' ? 1 : 0;
		\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, $reason, $threshold_override );

		// An auth failure (a revoked key or approval) makes a cached "ready"
		// answer wrong: drop it so the next readiness check asks core again.
		if ( $reason === 'auth' ) {
			delete_transient( self::READY_TRANSIENT );
		}

		// No model: say what to do. Upstream text goes nowhere (it can echo the prompt or keys): its category, status and a
		// fixed hint do. classify_error() read the raw text. Own messages only when thrown in this file.
		if ( self::is_no_model_error( $e ) ) {
			$safe_message = __( 'No AI provider is connected. Connect one under Settings → Connectors.', 'perflocale' );
		} elseif ( self::OWN_MESSAGE === $e->getCode() && __FILE__ === $e->getFile() ) {
			$safe_message = $e->getMessage();
		} elseif ( $timeout > 0 && self::timed_out( $e ) ) {
			$this->log_error( 'timeout', $reason );
			$safe_message = self::timeout_error( $timeout );
		} else {
			$code = $e->getCode();
			$this->log_error( is_int( $code ) && $code >= 400 && $code < 600 ? 'error HTTP ' . $code : 'error', $reason );
			$safe_message = self::shown_error( $reason, $code );
		}

		throw new \RuntimeException(
			esc_html(
				sprintf(
					/* translators: 1: classified category (auth/rate-limit/transient/invalid_request/unknown), 2: underlying error message */
					__( 'AI Client translation failed [%1$s]: %2$s', 'perflocale' ),
					$reason,
					$safe_message
				)
			),
			self::status_of( $e, $reason, $timeout ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int.
		);
	}

	/**
	 * The HTTP status a failed call stands for: 504 when it timed out, 401 for a refused key, else core's status (0: none).
	 *
	 * @param \Throwable $e       What the client threw.
	 * @param string     $reason  classify_error() category.
	 * @param float      $timeout The call's timeout.
	 * @return int
	 */
	private static function status_of( \Throwable $e, string $reason, float $timeout ): int {
		$code = $e->getCode();

		return match ( true ) {
			$timeout > 0 && self::timed_out( $e ) => 504,
			is_int( $code ) && $code >= 400 && $code < 600 && 503 !== $code => $code,
			'auth' === $reason => 401,
			is_int( $code ) && 503 === $code => 503,
			default => 0,
		};
	}

	/**
	 * Whether a failed call timed out (cURL error 28 in core's network error).
	 *
	 * @param \Throwable $e What the client threw.
	 * @return bool
	 */
	private static function timed_out( \Throwable $e ): bool {
		return 1 === preg_match( '/cURL error 28\b|timed out/i', html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * call_client() honouring a provider's Retry-After on a 429 or 503 (read from core's HTTP response): one retry after
	 * a wait slept when it fits, else a paced stop; neither is a failure or a breaker record.
	 *
	 * @param callable             $client      Resolver closure.
	 * @param string               $prompt      Prompt.
	 * @param array<string, mixed> $args        Client args.
	 * @param string               $breaker_key Breaker key.
	 * @param bool                 $fast_fail   No retry.
	 * @return mixed
	 * @throws DeadlineReachedException Paced, or when the call does not fit.
	 * @throws \Throwable What the client threw otherwise.
	 */
	private function paced_call( callable $client, string $prompt, array $args, string $breaker_key, bool $fast_fail ): mixed {
		$wait = null;

		try {
			return $this->invoke( $client, $prompt, $args, $wait );
		} catch ( DeadlineReachedException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			if ( null === $wait ) {
				throw $e;
			}
		}

		$asked = $wait;
		$wait  = min( $wait, self::retry_after_max() );

		if ( CallDeadline::pause( $wait, ! $fast_fail ) ) {
			$again = null;

			try {
				return $this->invoke( $client, $prompt, $args, $again );
			} catch ( DeadlineReachedException $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				if ( null === $again ) {
					throw $e;
				}

				$asked = $again;
				$wait  = min( $again, self::retry_after_max() );
			}
		}

		\PerfLocale\Concurrency\Breaker::record_paced( $breaker_key );

		throw new DeadlineReachedException( time() + $wait, true, '', $asked ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ints; the message is escaped in the class.
	}

	/**
	 * One client call: a retry is reserved again (or not sent), and every invoked call counts as used whatever its
	 * outcome (a success's own count clears it).
	 *
	 * @param callable             $client Client callback.
	 * @param string               $prompt Prompt.
	 * @param array<string, mixed> $args   Client arguments.
	 * @param int|null             $paced  Set to the provider's wait for a paced 429/503.
	 * @return mixed
	 */
	private function invoke( callable $client, string $prompt, array $args, ?int &$paced ): mixed {
		if ( $this->tries++ > 0 && $this->call_chars > 0 ) {
			self::reserve_send( $this->call_chars );
		}

		// Counted before the call: a resolver may bill upstream and still throw any error.
		self::bill_attempt( $this->call_chars );

		return self::call_client( $client, $prompt, $args, $paced );
	}

	/**
	 * The shown text of an upstream error: its HTTP status, if known, and a fixed hint for its category.
	 *
	 * @param string $reason Category from classify_error().
	 * @param mixed  $code   Exception code (core puts the HTTP status there).
	 * @return string
	 */
	private static function shown_error( string $reason, mixed $code ): string {
		$hint = match ( $reason ) {
			'auth' => __( 'The AI provider refused the key or access: check it under Settings → Connectors.', 'perflocale' ),
			'rate_limit' => __( 'The AI provider is limiting requests: try again later.', 'perflocale' ),
			'transient' => __( 'The AI provider could not be reached or had a server error: try again later.', 'perflocale' ),
			'invalid_request' => __( 'The AI provider refused this request.', 'perflocale' ),
			default => __( 'The AI provider returned an error.', 'perflocale' ),
		};

		/* translators: 1: HTTP status, 2: what happened and what to do */
		return is_int( $code ) && $code >= 400 && $code < 600 ? sprintf( __( 'HTTP %1$d. %2$s', 'perflocale' ), $code, $hint ) : $hint;
	}

	/**
	 * Call the client with its timeout cut to the room ({@see CallDeadline}); a call that failed after using up a short
	 * window stops the slice, and one cut by an interactive request's clamp hands the post off, instead of counting as a
	 * provider failure. $paced is set to the usable Retry-After seconds of the last 429 or 503 core received in the call.
	 *
	 * @param callable             $client Resolver closure.
	 * @param string               $prompt Prompt.
	 * @param array<string, mixed> $args   Client args, after the wp_ai_client_args filter.
	 * @param int|null             $paced  Set to the Retry-After seconds (at least 1), else null.
	 * @return mixed
	 * @throws DeadlineReachedException When the call does not fit or the deadline cut it.
	 * @throws \Throwable What the client threw otherwise.
	 */
	private static function call_client( callable $client, string $prompt, array $args, ?int &$paced = null ): mixed {
		$requested = is_numeric( $args['timeout'] ?? null ) && (float) $args['timeout'] > 0 ? (float) $args['timeout'] : 60.0;
		$timeout   = CallDeadline::timeout( $requested );
		$paced     = null;

		self::$last_timeout = $timeout;

		if ( $timeout < $requested ) {
			$args['timeout'] = $timeout;
		}

		// Core's client hides the response headers in its exception: the last 429 or 503 response of this call is read here.
		$listen = static function ( $response ) use ( &$paced ): void {
			if ( ! is_array( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), [ 429, 503 ], true ) ) {
				return;
			}

			$seconds = self::retry_after( wp_remote_retrieve_header( $response, 'retry-after' ), wp_remote_retrieve_header( $response, 'date' ) );
			$paced   = null !== $seconds && $seconds >= 1 ? $seconds : null;
		};

		add_action( 'http_api_debug', $listen, 10, 1 );

		try {
			return $client( $prompt, $args );
		} catch ( \Throwable $e ) {
			$left = CallDeadline::left();

			if ( null !== $left && $left < CallDeadline::MIN_CALL_SECONDS && $timeout < CallDeadline::SLOW_CALL_SECONDS ) {
				throw new DeadlineReachedException();
			}

			if ( null === $paced && $timeout < $requested && null === CallDeadline::window() && self::timed_out( $e ) ) {
				throw new DeadlineReachedException();
			}

			throw $e;
		} finally {
			remove_action( 'http_api_debug', $listen, 10 );
		}
	}

	/**
	 * Route key (blog + provider + model preference) of the temperature memo; blog-keyed, as multisite requests switch.
	 *
	 * @param array<string, mixed> $args Client args.
	 * @return string
	 */
	private static function route_key( array $args ): string {
		return get_current_blog_id() . '|' . md5( (string) wp_json_encode( [ $args['provider'] ?? '', $args['model'] ?? [] ] ) );
	}

	/**
	 * Whether a failed call was the model refusing the temperature parameter (HTTP 400/422 or the SDK's local check).
	 *
	 * A custom resolver throws with code 0, so the status is then read from the HTML-decoded message. Any other 400
	 * is no match.
	 *
	 * @param \Throwable $e What the client threw.
	 * @return bool
	 */
	public static function is_temperature_rejection( \Throwable $e ): bool {
		$msg = strtolower( html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( ! str_contains( $msg, 'temperature' ) ) {
			return false;
		}

		$code = (int) $e->getCode();

		if ( 400 === $code || 422 === $code ) {
			return true;
		}

		if ( 0 !== $code ) {
			return false;
		}

		foreach ( [ '(400)', '(422)', 'unsupported', 'not supported', 'does not support', 'cannot be combined' ] as $needle ) {
			if ( str_contains( $msg, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether core found no model able to answer (none connected, or none supports what the prompt asks for).
	 *
	 * @param \Throwable $e What the client threw.
	 * @return bool
	 */
	public static function is_no_model_error( \Throwable $e ): bool {
		return str_contains( strtolower( $e->getMessage() ), 'no models found' );
	}

	/**
	 * Test the connection by translating a short fixture; a failing key surfaces as a RuntimeException.
	 *
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the probe translate() call fails (auth, network, or unsupported configuration).
	 */
	public function test_connection(): bool {
		try {
			$this->translate( 'Hello', 'en', 'es', true );

			return true;
		} catch ( \RuntimeException $e ) {
			throw $e;
		}
	}

	/**
	 * Build the prompt: short and strict (only the translation, placeholders verbatim, no commentary); filterable.
	 *
	 * @param string $text        Source text.
	 * @param string $source_lang Source language slug.
	 * @param string $target_lang Target language slug.
	 * @param string $format      Destination format hint ('html'|'text').
	 * @param string $context     Context for reference, '' for none: placed between two lines holding `$boundary`.
	 * @param string $boundary    The context's boundary token ('' without context).
	 * @return string
	 */
	private function build_prompt( string $text, string $source_lang, string $target_lang, string $format = 'html', string $context = '', string $boundary = '' ): string {
		$source = $this->normalize_language_code( $source_lang );
		$target = $this->normalize_language_code( $target_lang );

		// Plain-text destinations must not receive markup or entity escapes from the model.
		$format_rule = 'text' === $format
			? "- The input is plain text, not HTML. Output plain text only: no markup, and no HTML entity escaping (write & as &, not &amp;).\n"
			: "- Preserve any HTML tags exactly.\n- Keep every HTML comment (<!-- ... -->) exactly as it is.\n";

		$prompt = sprintf(
			"You are translating user-facing content for a WordPress site.\n\n" .
			"Translate the input from %1\$s to %2\$s.\n\n" .
			"Rules:\n" .
			"- Output only the translation. No prefix, suffix, quotes, or commentary.\n" .
			'%3$s' .
			"- Preserve placeholder tokens that look like [[PFL_PH_N]] verbatim.\n" .
			"- Keep printf-style tokens (%%s, %%d, %%1\$s) verbatim.\n" .
			"- Match the tone, register, and capitalization of the source.\n\n" .
			'%5$s' .
			"Input:\n%4\$s",
			$source,
			$target,
			$format_rule,
			$text,
			'' === $context || '' === $boundary ? '' : sprintf(
				"Context for reference only; do not translate or output it. It is the text between the two lines that read %1\$s.\n%1\$s\n%2\$s\n%1\$s\n\n",
				$boundary,
				$context
			)
		);

		/**
		 * Filter the prompt sent to the WordPress AI Client.
		 *
		 * Keep the default rules: placeholder ([[PFL_PH_N]]) and printf tokens, and in html format every HTML comment
		 * (block and "Do not translate" markers), must come back verbatim, or the answer is not saved.
		 *
		 * @hook perflocale/mt/wp_ai_client_prompt
		 *
		 * @param string $prompt      The full prompt.
		 * @param string $text        Source text.
		 * @param string $source_lang Normalised source language.
		 * @param string $target_lang Normalised target language.
		 * @param string $format      Destination format: 'html' (markup kept) or 'text' (plain text, no markup or entities). Passed to callbacks that accept five arguments.
		 */
		return (string) apply_filters( 'perflocale/mt/wp_ai_client_prompt', $prompt, $text, $source, $target, $format );
	}

	/**
	 * Build the args the resolver closure maps onto the wp_ai_client_prompt() builder's using* methods.
	 *
	 * @param bool $fast_fail Whether to cap retries / timeout for synchronous callers.
	 * @return array<string, mixed>
	 */
	private function client_args( bool $fast_fail ): array {
		/**
		 * Filter the capability tag passed to the WordPress AI Client.
		 *
		 * Only a custom resolver reads it: core's builder has no capability option.
		 *
		 * @hook perflocale/mt/wp_ai_client_capability
		 *
		 * @param string $capability Default 'text-generation'.
		 */
		$capability = (string) apply_filters( 'perflocale/mt/wp_ai_client_capability', 'text-generation' );

		$args = [
			'capability'  => $capability,
			'temperature' => 0.2,
			'timeout'     => $fast_fail ? 10 : 60,
			'model'       => self::DEFAULT_MODEL_PREFERENCE,
		];

		/**
		 * Filter the argument array passed to the WordPress AI Client call.
		 *
		 * Keys `model`, `provider`, `temperature` (0.2; null sends none, which a model without temperature support needs),
		 * `max_tokens`, `system_instruction`, `timeout`: docs-ai/11-code-rationale.md, "WpAiClientProvider: client args".
		 *
		 * @hook perflocale/mt/wp_ai_client_args
		 *
		 * @param array<string, mixed> $args      Default args.
		 * @param bool                 $fast_fail Whether the caller asked for fast fail.
		 */
		$args = (array) apply_filters( 'perflocale/mt/wp_ai_client_args', $args, $fast_fail );

		$args['model'] = self::normalize_model_preference( $args['model'] ?? null );

		return $args;
	}

	/**
	 * Reduce a `model` arg to what core's model preference accepts: non-empty ids and `[ provider, model ]` pairs.
	 *
	 * One invalid entry puts core's builder into an error state that fails the whole call.
	 *
	 * @param mixed $model Raw `model` arg.
	 * @return list<string|array{0: string, 1: string}>
	 */
	private static function normalize_model_preference( mixed $model ): array {
		if ( is_string( $model ) ) {
			$model = trim( $model );

			return $model === '' ? [] : [ $model ];
		}

		if ( ! is_array( $model ) ) {
			return [];
		}

		$preference = [];

		foreach ( $model as $entry ) {
			if ( is_string( $entry ) ) {
				$entry = trim( $entry );

				if ( $entry !== '' ) {
					$preference[] = $entry;
				}

				continue;
			}

			if (
				is_array( $entry )
				&& array_keys( $entry ) === [ 0, 1 ]
				&& is_string( $entry[0] )
				&& is_string( $entry[1] )
				&& trim( $entry[0] ) !== ''
				&& trim( $entry[1] ) !== ''
			) {
				$preference[] = [ trim( $entry[0] ), trim( $entry[1] ) ];
			}
		}

		return $preference;
	}

	/**
	 * The model's translation from whatever the client returned: a string, or a common array or object shape.
	 *
	 * @param mixed  $result Whatever the client returned.
	 * @param string $source Original text, for artifact stripping.
	 * @return string
	 *
	 * @throws \RuntimeException If we can't extract a translation.
	 */
	private function extract_translation_from_response( mixed $result, string $source ): string {
		if ( is_string( $result ) ) {
			return self::strip_response_artifacts( $result, $source );
		}

		if ( is_array( $result ) ) {
			foreach ( [ 'translation', 'text', 'content', 'output' ] as $key ) {
				if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
					return self::strip_response_artifacts( $result[ $key ], $source );
				}
			}

			// Chat-completion shape: choices[0].message.content
			if ( isset( $result['choices'][0]['message']['content'] ) && is_string( $result['choices'][0]['message']['content'] ) ) {
				return self::strip_response_artifacts( $result['choices'][0]['message']['content'], $source );
			}

			// Anthropic-style: content[0].text
			if ( isset( $result['content'][0]['text'] ) && is_string( $result['content'][0]['text'] ) ) {
				return self::strip_response_artifacts( $result['content'][0]['text'], $source );
			}
		}

		if ( is_object( $result ) ) {
			foreach ( [ 'translation', 'text', 'content', 'output' ] as $key ) {
				if ( isset( $result->$key ) && is_string( $result->$key ) ) {
					return self::strip_response_artifacts( $result->$key, $source );
				}
			}

			if ( method_exists( $result, 'get_text' ) ) {
				$value = $result->get_text();

				if ( is_string( $value ) ) {
					return self::strip_response_artifacts( $value, $source );
				}
			}
		}

		throw new \RuntimeException(
			esc_html__( 'AI Client returned an unexpected response format.', 'perflocale' )
		);
	}

	/**
	 * Trim artifacts chat models add around a translation: a code fence, wrapping quotes, a "Translation:" prefix.
	 *
	 * Conservative: quotes go only when the first and last character match, and never for a quote-wrapped source.
	 *
	 * @param string $text   Raw model output.
	 * @param string $source Source text; when quote-wrapped, quotes in the output are content and stay.
	 * @return string
	 */
	private static function strip_response_artifacts( string $text, string $source = '' ): string {
		$text = trim( $text );

		// Strip a markdown code-fence wrapper if the model returned one around the translation.
		if ( str_starts_with( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-z]*\n?/i', '', $text ) ?? $text;
			$text = (string) preg_replace( '/\n?```$/', '', $text );
			$text = trim( $text );
		}

		// A quote-wrapped source (testimonial, pull-quote): quotes in the output are content, not decoration.
		$strip_quotes = ! self::is_quote_wrapped( trim( $source ) );

		// Quotes go before the prefix sniff: models emit `"Translation: ..."` and the regex matches at offset 0 only.
		if (
			$strip_quotes
			&& strlen( $text ) >= 2
			&& ( ( $text[0] === '"' && $text[-1] === '"' ) || ( $text[0] === "'" && $text[-1] === "'" ) )
		) {
			$text = substr( $text, 1, -1 );
		}

		// The colon is required: a bare leading word ("Output settings saved") is a real translation. A source that
		// starts with a label of its own ("Résultat : approuvé") has its translated label kept.
		if ( 1 !== preg_match( '/^\s*+[^\s:]{1,40}(?:\s++[^\s:]{1,40}){0,2}\s*+[:\x{FF1A}]/u', $source ) ) {
			$text = (string) preg_replace( '/^(translation|translated|output|result)\s*:\s*/i', '', $text );
		}

		// A second pass for a quoted inner string (`Translation: "Bonjour"`).
		if (
			$strip_quotes
			&& strlen( $text ) >= 2
			&& ( ( $text[0] === '"' && $text[-1] === '"' ) || ( $text[0] === "'" && $text[-1] === "'" ) )
		) {
			$text = substr( $text, 1, -1 );
		}

		return trim( $text );
	}

	/**
	 * Whether a string is wrapped in quotation marks: straight, curly or guillemets.
	 *
	 * Any convention counts, as the model may convert the quotes to the target language's style.
	 *
	 * @param string $text Text to check.
	 * @return bool
	 */
	private static function is_quote_wrapped( string $text ): bool {
		return (bool) preg_match(
			'/^["\'\x{00AB}\x{201C}\x{2018}\x{201E}\x{2039}].*["\'\x{00BB}\x{201D}\x{2019}\x{201C}\x{203A}]$/su',
			$text
		);
	}

	/**
	 * The custom resolver a site or test installed, or null.
	 *
	 * @return null|callable(string, array<string, mixed>): mixed
	 */
	private function custom_resolver(): ?callable {
		/**
		 * Filter the callable that invokes the WordPress AI Client.
		 *
		 * Return a callable `(string $prompt, array $args)` that returns the model output (string, array or object),
		 * for tests or custom routing.
		 *
		 * @hook perflocale/mt/wp_ai_client_resolver
		 *
		 * @param null|callable $resolver Default null (auto-detect).
		 */
		$custom = apply_filters( 'perflocale/mt/wp_ai_client_resolver', null );

		return is_callable( $custom ) ? $custom : null;
	}

	/**
	 * The callable that drives this provider (a custom resolver, else core's wp_ai_client_prompt()), or null.
	 *
	 * It maps `temperature`, `max_tokens`, `provider`, `system_instruction`, `model` and `timeout` onto the builder's
	 * using* methods; other keys, `capability` included, are ignored.
	 *
	 * @return null|callable(string, array<string, mixed>): mixed
	 */
	private function resolve_client_callback(): ?callable {
		$custom = $this->custom_resolver();

		if ( $custom !== null ) {
			return $custom;
		}

		// wp_supports_ai() lets the host disable AI per request: respect it before building a prompt. Called by name
		// because the AI Client exists on WP 7.0+ only; function_exists() is the real check.
		$prompt_fn   = 'wp_ai_client_prompt';
		$supports_fn = 'wp_supports_ai';

		if (
			function_exists( $prompt_fn )
			&& ( ! function_exists( $supports_fn ) || $supports_fn() )
		) {
			return static function ( string $prompt, array $args ): string {
				$builder = self::new_builder( $prompt );

				// Any non-object is malformed: fail rather than call methods on it.
				if ( ! is_object( $builder ) ) {
					throw new \RuntimeException(
						esc_html__( 'wp_ai_client_prompt() did not return a builder object.', 'perflocale' ),
						self::OWN_MESSAGE // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant.
					);
				}

				// The builder's methods mutate it and return it, so it is never reassigned. The guard MUST be
				// is_callable(), not method_exists(): core's builder routes the using* methods through __call(), so
				// method_exists() is false for each one and every option would be dropped.
				$apply = static function ( object $target, string $method, $value ): void {
					if ( ! is_callable( [ $target, $method ] ) ) {
						return;
					}

					// An array callable, so static analysis need not resolve a dynamic method on a bare object.
					$call = [ $target, $method ];
					$call( $value );
				};

				if ( isset( $args['temperature'] ) && is_numeric( $args['temperature'] ) ) {
					$apply( $builder, 'usingTemperature', (float) $args['temperature'] );
				}
				if ( isset( $args['max_tokens'] ) && is_int( $args['max_tokens'] ) ) {
					$apply( $builder, 'usingMaxTokens', $args['max_tokens'] );
				}
				if ( isset( $args['provider'] ) && is_string( $args['provider'] ) && $args['provider'] !== '' ) {
					$apply( $builder, 'usingProvider', $args['provider'] );
				}
				if ( isset( $args['system_instruction'] ) && is_string( $args['system_instruction'] ) && $args['system_instruction'] !== '' ) {
					$apply( $builder, 'usingSystemInstruction', $args['system_instruction'] );
				}

				// Variadic, so not through $apply; client_args() validated the entries (an invalid one breaks the builder).
				$prefer = [ $builder, 'usingModelPreference' ];

				if ( isset( $args['model'] ) && is_array( $args['model'] ) && $args['model'] !== [] && is_callable( $prefer ) ) {
					$prefer( ...array_values( $args['model'] ) );
				}

				// PerfLocale's own request timeout replaces core's default one.
				$options_class   = '\WordPress\AiClient\Providers\Http\DTO\RequestOptions';
				$options_factory = [ $options_class, 'fromArray' ];

				if (
					isset( $args['timeout'] )
					&& is_numeric( $args['timeout'] )
					&& (float) $args['timeout'] > 0
					&& class_exists( $options_class )
					&& is_callable( $options_factory )
				) {
					$apply( $builder, 'usingRequestOptions', $options_factory( [ 'timeout' => (float) $args['timeout'] ] ) );
				}

				// The snake_case name: core's builder applies wp_supports_ai() and `wp_ai_client_prevent_prompt` only in
				// __call(), which camelCase generateText() skips. A blocked or provider-less prompt returns a WP_Error or
				// the builder instead of throwing: turned into a RuntimeException below, so the breaker sees the cause.
				$result = method_exists( $builder, '__call' )
					? $builder->generate_text()
					: $builder->generateText();

				if ( is_string( $result ) ) {
					return $result;
				}

				// The WP_Error's HTTP status (core's exception_to_wp_error()) becomes the code is_temperature_rejection() reads.
				if ( $result instanceof \WP_Error ) {
					$data = $result->get_error_data();

					throw new \RuntimeException(
						esc_html( $result->get_error_message() ?: 'wp_ai_client error' ),
						is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) ? (int) $data['status'] : 0
					);
				}

				throw new \RuntimeException(
					esc_html__( 'WordPress AI Client returned no text - likely no AI provider configured, or the prompt was blocked by a wp_ai_client_prevent_prompt filter.', 'perflocale' ),
					self::OWN_MESSAGE // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant.
				);
			};
		}

		return null;
	}

	/**
	 * A new core prompt builder; called by name, as the AI Client exists on WP 7.0+ only and every caller checks first.
	 *
	 * @param string $prompt Prompt text.
	 * @return mixed The builder (an object), as wp_ai_client_prompt() returns it.
	 */
	private static function new_builder( string $prompt ): mixed {
		$prompt_fn = 'wp_ai_client_prompt';

		return $prompt_fn( $prompt );
	}

	/**
	 * Classify an AI-client error for the job log and Site Health (auth also covers a connector not approved for use).
	 *
	 * A message heuristic: ambiguous text stays 'unknown' rather than being misclassified.
	 *
	 * @param \Throwable $e Original exception from the AI client.
	 * @return string Category tag (one of: auth, rate_limit, transient, invalid_request, unknown).
	 */
	public static function classify_error( \Throwable $e ): string {
		$msg = strtolower( $e->getMessage() );

		// Auth failures - checked first because some providers return 401 with
		// a "rate limit"-shaped message; the auth case is more urgent.
		if (
			str_contains( $msg, 'unauthorized' )
			|| str_contains( $msg, 'authentication' )
			|| str_contains( $msg, 'api key' )
			|| str_contains( $msg, 'api_key' )
			|| str_contains( $msg, 'invalid key' )
			|| str_contains( $msg, 'forbidden' )
			|| str_contains( $msg, ' 401' )
			|| str_contains( $msg, ' 403' )
			|| str_contains( $msg, 'not been approved' )
		) {
			return 'auth';
		}

		if (
			str_contains( $msg, 'rate limit' )
			|| str_contains( $msg, 'ratelimit' )
			|| str_contains( $msg, 'rate-limit' )
			|| str_contains( $msg, 'quota' )
			|| str_contains( $msg, 'too many requests' )
			|| str_contains( $msg, ' 429' )
		) {
			return 'rate_limit';
		}

		if (
			str_contains( $msg, 'timeout' )
			|| str_contains( $msg, 'timed out' )
			|| str_contains( $msg, 'connection' )
			|| str_contains( $msg, 'network' )
			|| str_contains( $msg, ' 500' )
			|| str_contains( $msg, ' 502' )
			|| str_contains( $msg, ' 503' )
			|| str_contains( $msg, ' 504' )
			|| str_contains( $msg, '(500)' )
			|| str_contains( $msg, '(502)' )
			|| str_contains( $msg, '(503)' )
			|| str_contains( $msg, '(504)' )
			|| str_contains( $msg, '(529)' )
			|| str_contains( $msg, 'service unavailable' )
			|| str_contains( $msg, 'gateway' )
		) {
			return 'transient';
		}

		// The SDK words a client error as "Bad Request (400) - …".
		if ( str_contains( $msg, '(400)' ) ) {
			return 'invalid_request';
		}

		return 'unknown';
	}
}
