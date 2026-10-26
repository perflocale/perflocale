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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine translation via the WordPress AI Client API, with the AI provider the site has connected.
 *
 * Without wp_ai_client_prompt() (WP 6.x), or when wp_supports_ai() is false, is_configured() is false and the
 * provider is not offered; `perflocale/mt/wp_ai_client_resolver` installs a custom resolver.
 */
final class WpAiClientProvider extends AbstractProvider {

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
	 * Replace every connector API key, as stored, trimmed or URL-encoded, with `[REDACTED]`.
	 *
	 * A key of 1-3 characters only as a whole token, so the words around it stay readable; a longer one anywhere.
	 *
	 * @param string $text Error text.
	 * @return string
	 */
	private static function mask_connector_keys( string $text ): string {
		$forms = [];
		$short = [];

		foreach ( self::connector_keys() as $keys ) {
			foreach ( $keys as $key ) {
				$trimmed = is_string( $key ) ? trim( $key ) : '';

				if ( '' === $trimmed ) {
					continue;
				}

				if ( mb_strlen( $trimmed ) <= 3 ) {
					array_push( $short, $trimmed, rawurlencode( $trimmed ) );
				} else {
					array_push( $forms, $key, $trimmed, rawurlencode( $trimmed ) );
				}
			}
		}

		// Longest first, so a form that contains another is replaced whole.
		$forms = array_unique( $forms );
		usort( $forms, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$text = str_replace( $forms, '[REDACTED]', $text );

		if ( [] === $short ) {
			return $text;
		}

		$quoted = array_map( static fn( string $f ): string => preg_quote( $f, '/' ), array_unique( $short ) );
		$masked = preg_replace( '/(?<![\p{L}\p{N}_])(?:' . implode( '|', $quoted ) . ')(?![\p{L}\p{N}_])/u', '[REDACTED]', $text );

		// Text that is not UTF-8 fails the match: masked everywhere instead.
		return null !== $masked ? $masked : str_replace( $short, '[REDACTED]', $text );
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
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, $this->get_id(), $target_lang );

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

		$prompt = $this->build_prompt( $text, $source_lang, $target_lang, $format );
		$args   = $this->client_args( $fast_fail );
		$route  = self::route_key( $args );

		// This route's model refused the temperature in this request: send none straight away.
		if ( isset( self::$temperature_rejected[ $route ] ) ) {
			unset( $args['temperature'] );
		}

		try {
			$result = self::call_client( $client, $prompt, $args );
		} catch ( DeadlineReachedException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// A model refusing the temperature (HTTP 400, not billed), or core finding no model with it, is no provider
			// fault: retry once without it; only the retry's outcome counts on the breaker.
			if ( ! isset( $args['temperature'] ) || ! ( self::is_temperature_rejection( $e ) || self::is_no_model_error( $e ) ) ) {
				$this->fail( $e, $breaker_key );
			}

			self::$temperature_rejected[ $route ] = true;
			unset( $args['temperature'] );

			try {
				$result = self::call_client( $client, $prompt, $args );
			} catch ( DeadlineReachedException $retry_error ) {
				throw $retry_error;
			} catch ( \Throwable $retry_error ) {
				$this->fail( $retry_error, $breaker_key );
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

		$this->track_usage( $text );

		return apply_filters( 'perflocale/machine_translation/result', $translated, $text, $this->get_id() );
	}

	/**
	 * Record a failed call on the breaker and throw the surfaced error.
	 *
	 * @param \Throwable $e           What the client threw.
	 * @param string     $breaker_key Breaker key for this provider.
	 * @return never
	 *
	 * @throws \RuntimeException Always.
	 */
	private function fail( \Throwable $e, string $breaker_key ): never {
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

		// No model: say what to do. Otherwise mask credentials before the message is shown or stored on a job row:
		// make_request() masks only the HTTP providers' errors, and upstreams echo keys (OpenAI's 401 shows `sk-...`).
		// classify_error() ran on the raw message, so masking cannot change the category.
		$safe_message = self::is_no_model_error( $e )
			? __( 'No AI provider is connected. Connect one under Settings → Connectors.', 'perflocale' )
			: self::mask_credentials( self::mask_connector_keys( $e->getMessage() ) );

		throw new \RuntimeException(
			esc_html(
				sprintf(
					/* translators: 1: classified category (auth/rate-limit/transient/invalid_request/unknown), 2: underlying error message */
					__( 'AI Client translation failed [%1$s]: %2$s', 'perflocale' ),
					$reason,
					$safe_message
				)
			)
		);
	}

	/**
	 * Call the client with its timeout cut to a slice's deadline ({@see CallDeadline}); a call that failed after
	 * using up a short window stops the slice instead of counting as a provider failure.
	 *
	 * @param callable             $client Resolver closure.
	 * @param string               $prompt Prompt.
	 * @param array<string, mixed> $args   Client args, after the wp_ai_client_args filter.
	 * @return mixed
	 * @throws DeadlineReachedException When the call does not fit or the deadline cut it.
	 * @throws \Throwable What the client threw otherwise.
	 */
	private static function call_client( callable $client, string $prompt, array $args ): mixed {
		$requested = is_numeric( $args['timeout'] ?? null ) && (float) $args['timeout'] > 0 ? (float) $args['timeout'] : 60.0;
		$timeout   = CallDeadline::timeout( $requested );

		if ( $timeout < $requested ) {
			$args['timeout'] = $timeout;
		}

		try {
			return $client( $prompt, $args );
		} catch ( \Throwable $e ) {
			$left = CallDeadline::left();

			if ( null !== $left && $left < CallDeadline::MIN_CALL_SECONDS && $timeout < CallDeadline::SLOW_CALL_SECONDS ) {
				throw new DeadlineReachedException();
			}

			throw $e;
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
	 * @return string
	 */
	private function build_prompt( string $text, string $source_lang, string $target_lang, string $format = 'html' ): string {
		$source = $this->normalize_language_code( $source_lang );
		$target = $this->normalize_language_code( $target_lang );

		// Plain-text destinations must not receive markup or entity escapes from the model.
		$format_rule = 'text' === $format
			? "- The input is plain text, not HTML. Output plain text only: no markup, and no HTML entity escaping (write & as &, not &amp;).\n"
			: "- Preserve any HTML tags exactly.\n";

		$prompt = sprintf(
			"You are translating user-facing content for a WordPress site.\n\n" .
			"Translate the input from %1\$s to %2\$s.\n\n" .
			"Rules:\n" .
			"- Output only the translation. No prefix, suffix, quotes, or commentary.\n" .
			'%3$s' .
			"- Preserve placeholder tokens that look like [[PFL_PH_N]] verbatim.\n" .
			"- Keep printf-style tokens (%%s, %%d, %%1\$s) verbatim.\n" .
			"- Match the tone, register, and capitalization of the source.\n\n" .
			"Input:\n%4\$s",
			$source,
			$target,
			$format_rule,
			$text
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
						esc_html__( 'wp_ai_client_prompt() did not return a builder object.', 'perflocale' )
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
					esc_html__( 'WordPress AI Client returned no text - likely no AI provider configured, or the prompt was blocked by a wp_ai_client_prevent_prompt filter.', 'perflocale' )
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
