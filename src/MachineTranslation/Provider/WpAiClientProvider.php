<?php
/**
 * WordPress AI Client translation provider (WP 7.0+).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation\Provider;

use PerfLocale\MachineTranslation\AbstractProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine translation via the WordPress AI Client API.
 *
 * Delegates to whichever provider the host site has configured under the
 * core AI Client (OpenAI, Anthropic, local Ollama, etc.). Lets PerfLocale
 * sites translate without provisioning a separate MT API key — they reuse
 * the AI key already configured for the rest of core / other plugins.
 *
 * Targets the WP 7.0+ surface — `wp_ai_client_prompt( $prompt )` returns a
 * fluent `WP_AI_Client_Prompt_Builder`, which the wrapper closure inside
 * `resolve_client_callback()` configures (temperature / max tokens /
 * provider / system instruction / model preference / request timeout) and
 * finalises with `->generate_text()`.
 *
 * Feature-detected: when `wp_ai_client_prompt()` is absent (WP 6.x), OR
 * `wp_supports_ai()` returns false on this request,
 * `resolve_client_callback()` returns null and `is_configured()` returns
 * false — the provider stays out of the picker UI. Sites with an exotic
 * AI setup can return a custom resolver via the
 * `perflocale/mt/wp_ai_client_resolver` filter.
 */
final class WpAiClientProvider extends AbstractProvider {

	/**
	 * Default model preference: small, inexpensive models across the common
	 * providers, in order (OpenAI, then Anthropic, then Google). Handed to
	 * core's `using_model_preference()`; the first id that a configured
	 * provider offers wins, and when none is offered core picks its own
	 * model. Every entry advertises temperature support in its provider's
	 * model metadata, so none of them drops out of the candidate list while
	 * the default temperature is sent. Anthropic is listed by alias and by
	 * dated id because its model list may carry either.
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
	 * Transient holding the last readiness answer, see is_ready_for_text().
	 * Written with an expiry, so it is never autoloaded.
	 */
	private const READY_TRANSIENT = 'perflocale_mt_wp_ai_ready';

	/**
	 * Routes whose model refused the temperature in this request, keyed by
	 * blog id and a hash of the provider + model preference. Later calls on
	 * the same route go out without temperature instead of paying for a
	 * rejected request first. Per request only.
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
	 * Whether the AI Client API is available on this request. Deliberately
	 * cheap (no model listing): it runs on every get_provider() call. Whether
	 * a connected provider can actually generate text is
	 * {@see self::is_ready_for_text()}.
	 *
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return $this->resolve_client_callback() !== null;
	}

	/**
	 * Whether a connected AI provider can generate text right now.
	 *
	 * Stricter than is_configured(): asks core's builder whether any model of a
	 * configured provider supports text generation and, when the `provider`
	 * arg names one provider, whether that provider is configured. Core lists
	 * each provider's models to answer, which is an HTTP request per provider
	 * on a site without a persistent object cache, so the answer is kept in a
	 * transient: 10 minutes when ready, 1 minute when not. The stored answer
	 * is tied to a fingerprint of the registered providers, their connector
	 * settings and the `provider` arg, so connecting or removing a provider is
	 * seen on the next call.
	 *
	 * Callers are the block editor's asset config, the editor sidebar and Site
	 * Health (through TranslationService::is_active_provider_ready()). A
	 * custom resolver (`perflocale/mt/wp_ai_client_resolver`) owns its own
	 * routing, so it is taken as ready.
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
	 * Fingerprint of what decides readiness: the providers registered with
	 * the AI Client, the API key of each wherever core reads it from (the
	 * `<ID>_API_KEY` environment variable, the constant of the same name and
	 * core's connector setting `connectors_ai_<id>_api_key`; hashed), and the
	 * `provider` arg.
	 *
	 * @param string $provider Provider id from the args ('' = any).
	 * @return string
	 */
	private static function connectors_fingerprint( string $provider ): string {
		$parts = [ 'provider' => $provider ];

		$registry_factory = [ '\WordPress\AiClient\AiClient', 'defaultRegistry' ];
		$registry         = is_callable( $registry_factory ) ? $registry_factory() : null;
		$list_ids         = is_object( $registry ) ? [ $registry, 'getRegisteredProviderIds' ] : null;

		if ( is_callable( $list_ids ) ) {
			foreach ( (array) $list_ids() as $id ) {
				if ( is_string( $id ) && $id !== '' ) {
					$sanitized = str_replace( '-', '_', $id );
					// Core's naming for AI connector keys (wp-includes/connectors.php).
					$key_name = strtoupper( (string) preg_replace( '/([a-z])([A-Z])/', '$1_$2', $sanitized ) ) . '_API_KEY';

					$parts['keys'][ $id ] = md5(
						(string) wp_json_encode(
							[
								getenv( $key_name ),
								defined( $key_name ) ? constant( $key_name ) : null,
								get_option( 'connectors_ai_' . $sanitized . '_api_key', '' ),
							]
						)
					);
				}
			}
		}

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch(). Expressed to the model as a
	 *   prompt rule (there is no wire-level format switch for LLMs).
	 *
	 * @throws \RuntimeException When the AI Client API is unavailable or the underlying client call fails.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, $this->get_id(), $target_lang );

		$client = $this->resolve_client_callback();

		if ( $client === null ) {
			throw new \RuntimeException(
				esc_html__( 'The WordPress AI Client API is not available on this site.', 'perflocale' )
			);
		}

		// Circuit-breaker gate. WpAiClient bypasses AbstractProvider::make_request()
		// (which is the canonical breaker site for HTTP-based providers) because
		// the WP AI Client is invoked via a PHP callback, not wp_remote_*. Without
		// the explicit gate here, a sustained upstream outage would burn through
		// the user's API quota one call at a time — every visitor / translator
		// keeps hitting the failing provider until the budget cap stops them.
		// Same breaker key (`mt_<provider_id>`) the Site Health card surfaces.
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

		// This route's model already refused the temperature in this request:
		// send without it straight away.
		if ( isset( self::$temperature_rejected[ $route ] ) ) {
			unset( $args['temperature'] );
		}

		try {
			$result = $client( $prompt, $args );
		} catch ( \Throwable $e ) {
			// A model that does not take a temperature is not a provider
			// fault. Some models reject the parameter (HTTP 400, not billed);
			// when every connected model lacks it, core finds no model at all.
			// Either way the request is repeated exactly once without the
			// temperature, and this first rejection is NOT recorded on the
			// breaker: only the retry's outcome counts.
			if ( ! isset( $args['temperature'] ) || ! ( self::is_temperature_rejection( $e ) || self::is_no_model_error( $e ) ) ) {
				$this->fail( $e, $breaker_key );
			}

			self::$temperature_rejected[ $route ] = true;
			unset( $args['temperature'] );

			try {
				$result = $client( $prompt, $args );
			} catch ( \Throwable $retry_error ) {
				$this->fail( $retry_error, $breaker_key );
			}
		}

		// A returned-without-throwing call is not yet a success: the AI
		// client can hand back a shape this provider cannot read at all.
		// Clearing the counter before extraction made that failure invisible
		// to the breaker - every call reset the streak, so a model that had
		// stopped answering usefully kept being paid for. Extract first.
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
		// Record the failure on the breaker BEFORE re-throwing so the
		// next caller in this request (or shortly after) gets the open
		// breaker instead of another failing upstream call. Auth errors
		// trip on the first hit (threshold_override=1) — no number of
		// retries fixes a bad API key, so the breaker should open fast
		// and let the operator see it in Site Health.
		$reason             = self::classify_error( $e );
		$threshold_override = $reason === 'auth' ? 1 : 0;
		\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, $reason, $threshold_override );

		// An auth failure (a revoked key or approval) makes a cached "ready"
		// answer wrong: drop it so the next readiness check asks core again.
		if ( $reason === 'auth' ) {
			delete_transient( self::READY_TRANSIENT );
		}

		// Core answers "No models found…" when no connected provider can
		// generate text; say what to do about it instead.
		//
		// Otherwise mask credential-shaped runs BEFORE the message is
		// surfaced. This provider bypasses AbstractProvider::make_request(),
		// which is where every HTTP provider's error body gets masked — so
		// without this call the raw upstream text is what gets thrown, and for
		// a background job it is persisted verbatim on the job row that the
		// Jobs page and the REST detail endpoint render. Real upstreams do
		// echo key material: OpenAI's 401 reads `Incorrect API key
		// provided: sk-…`. classify_error() above runs on the RAW message
		// so redaction can't change the category.
		$safe_message = self::is_no_model_error( $e )
			? __( 'No AI provider is connected. Connect one under Settings → Connectors.', 'perflocale' )
			: self::mask_credentials( $e->getMessage() );

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
	 * Key of a route (blog + provider + model preference) for the
	 * temperature-rejection memo. Blog-keyed: a per-request memo is shared by
	 * every blog a multisite request switches to.
	 *
	 * @param array<string, mixed> $args Client args.
	 * @return string
	 */
	private static function route_key( array $args ): string {
		return get_current_blog_id() . '|' . md5( (string) wp_json_encode( [ $args['provider'] ?? '', $args['model'] ?? [] ] ) );
	}

	/**
	 * Whether a failed call was the model refusing the temperature parameter.
	 *
	 * Matches the provider's HTTP 400/422 answer ("Bad Request (400) -
	 * Unsupported parameter: 'temperature' is not supported with this
	 * model.") and the SDK's local check ("The parameter(s) "temperature"
	 * cannot be combined with reasoning effort…"). The exception code carries
	 * the HTTP status on the built-in path; a custom resolver throws with code
	 * 0, so the status is then read from the message. The message may arrive
	 * HTML-escaped, so it is decoded first. Any other 400 is not a match.
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
	 * Whether core found no model able to answer: no provider is connected,
	 * or no connected model supports what the prompt asks for.
	 *
	 * @param \Throwable $e What the client threw.
	 * @return bool
	 */
	public static function is_no_model_error( \Throwable $e ): bool {
		return str_contains( strtolower( $e->getMessage() ), 'no models found' );
	}

	/**
	 * Test connection by translating a known-cheap fixture string. Real key
	 * validation happens inside the AI client itself — a failing key surfaces
	 * as a RuntimeException from the call.
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
	 * Build the per-request prompt sent to the AI client.
	 *
	 * The prompt is deliberately short and constraint-heavy: we ask the model
	 * to return ONLY the translation, preserve placeholders exactly, and not
	 * add commentary. Long instructions are filterable so site owners can
	 * inject brand-voice / domain-specific guidance per their use case.
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

		// Plain-text destinations must not receive markup or entity escapes
		// from the model.
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
		 * @hook perflocale/mt/wp_ai_client_prompt
		 *
		 * @param string $prompt      The full prompt.
		 * @param string $text        Source text.
		 * @param string $source_lang Normalised source language.
		 * @param string $target_lang Normalised target language.
		 */
		return (string) apply_filters( 'perflocale/mt/wp_ai_client_prompt', $prompt, $text, $source, $target );
	}

	/**
	 * Build the args array consumed by the resolver closure
	 * (`resolve_client_callback()`), which maps them onto the WP 7.0
	 * `wp_ai_client_prompt()` builder via `using*` methods.
	 *
	 * @param bool $fast_fail Whether to cap retries / timeout for synchronous callers.
	 * @return array<string, mixed>
	 */
	private function client_args( bool $fast_fail ): array {
		/**
		 * Filter the capability tag passed to the WordPress AI Client.
		 *
		 * Defaults to `'text-generation'`. Future versions of WP Connectors
		 * may add `'translation'`, `'multilingual'`, or domain-specific tags;
		 * site owners running a multi-capability AI gateway can route
		 * PerfLocale's calls to a specialised provider by changing this.
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
		 * Recognised keys:
		 * - `model`: a model id (`'gpt-5.4-mini'`) or an ordered list of
		 *   preferences, each a model id or a `[ provider_id, model_id ]` pair
		 *   (`[ 'gpt-5.4-mini', [ 'anthropic', 'claude-haiku-4-5' ] ]`). Passed
		 *   to core's model preference: the first one a connected provider
		 *   offers is used, otherwise core chooses. The default is a list of
		 *   small models; `null` or `[]` leaves the choice to core. Entries of
		 *   any other shape are dropped.
		 * - `provider`: a provider id, to use only that provider.
		 * - `temperature`: default 0.2; `null` sends none. A model that does
		 *   not advertise temperature is not chosen while one is sent, so an
		 *   explicit `model` of that kind needs `'temperature' => null` too.
		 *   When a model refuses the temperature, the call is repeated once
		 *   without it.
		 * - `max_tokens`, `system_instruction`.
		 * - `timeout`: seconds for the provider request (10 for editor calls,
		 *   60 otherwise).
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
	 * Reduce a `model` arg to the entries core's model preference accepts: a
	 * non-empty model id, or a list of non-empty ids and `[ provider, model ]`
	 * pairs. Anything else is dropped here, because one invalid entry puts
	 * core's builder into an error state that fails the whole call.
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
	 * Normalise the assorted possible return shapes from the WP 7.0
	 * `wp_ai_client_prompt()->generateText()` call (or a custom
	 * resolver registered via `perflocale/mt/wp_ai_client_resolver`).
	 *
	 * The exact response shape is provider-specific (text-completion,
	 * chat-completion, structured output, etc.). Probe the common shapes
	 * in order; the goal is to return the model's translation as a string.
	 *
	 * @param mixed  $result Whatever the client returned.
	 * @param string $source Original text — informs artifact stripping and
	 *   fallback error messages.
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
	 * Trim leading/trailing artifacts that text-completion models sometimes
	 * smuggle in: surrounding double-quotes, "Translation:" prefixes,
	 * <result>…</result> wrappers from system-prompt-tuned models, etc.
	 *
	 * Conservative — only strips when the artifact is unambiguous. The MT
	 * integrity gate (PlaceholderMasker) is the second line of defence.
	 *
	 * @param string $text   Raw model output.
	 * @param string $source Source text — a quote-wrapped source means quotes
	 *   in the output are faithful content, so quote stripping is skipped.
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

		// A quote-wrapped SOURCE (testimonial, pull-quote) means quotes in
		// the output were faithfully carried over, not chat decoration.
		$strip_quotes = ! self::is_quote_wrapped( trim( $source ) );

		// Strip wrapping quotes BEFORE the prefix sniff — chat-tuned models
		// commonly emit `"Translation: …"` (both decorations at once) and the
		// regex only matches at offset 0, so quotes have to go first.
		if (
			$strip_quotes
			&& strlen( $text ) >= 2
			&& ( ( $text[0] === '"' && $text[-1] === '"' ) || ( $text[0] === "'" && $text[-1] === "'" ) )
		) {
			$text = substr( $text, 1, -1 );
		}

		// "Translation: …" prefix when the model couldn't help itself. The
		// colon is REQUIRED: a bare leading word is a legitimate translation
		// shape ("Output settings saved"), not an unambiguous artifact.
		$text = (string) preg_replace( '/^(translation|translated|output|result)\s*:\s*/i', '', $text );

		// One more quote-strip pass for the case where the inner string was
		// quoted but the outer wasn't (e.g. `Translation: "Bonjour"`).
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
	 * Whether a string is wrapped in quotation marks — straight, curly, or
	 * guillemets. A source quoted in ANY convention makes quotes in the
	 * translated output content rather than decoration (the model may
	 * legitimately convert «…» / „…“ to the target language's quote style).
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
		 * Filter the callable used to invoke the WordPress AI Client.
		 *
		 * Return any callable accepting `(string $prompt, array $args)` and
		 * returning the model output (string, array, or object — the
		 * response normaliser handles all three). Useful for unit tests,
		 * custom routing, or as a forward-compatibility shim if core
		 * renames the API.
		 *
		 * @hook perflocale/mt/wp_ai_client_resolver
		 *
		 * @param null|callable $resolver Default null (auto-detect).
		 */
		$custom = apply_filters( 'perflocale/mt/wp_ai_client_resolver', null );

		return is_callable( $custom ) ? $custom : null;
	}

	/**
	 * Locate the runtime that drives this provider, or null when the AI
	 * Client API isn't available on this WP install.
	 *
	 * Probed for in order:
	 *   1. The `perflocale/mt/wp_ai_client_resolver` filter (tests / custom)
	 *   2. The canonical `wp_ai_client_prompt()` function (WP 7.0+)
	 *
	 * The returned callable normalises the WP 7.0 fluent-builder pattern
	 * to a simple `(string $prompt, array $args): string` signature so the
	 * rest of this class doesn't need to know about the builder. Args keys
	 * recognised (each gated by is_callable so a stripped / future
	 * builder build can't fatal — see the ⚠️ note at the call site for why
	 * method_exists is the WRONG guard here):
	 *
	 *   - `temperature`        → `->usingTemperature( float )`
	 *   - `max_tokens`         → `->usingMaxTokens( int )`
	 *   - `provider`           → `->usingProvider( string )`
	 *   - `system_instruction` → `->usingSystemInstruction( string )`
	 *   - `model`              → `->usingModelPreference( ...$preference )`
	 *   - `timeout`            → `->usingRequestOptions( RequestOptions )`,
	 *                            when the SDK's RequestOptions class exists
	 *
	 * Anything else in `$args` is ignored; `capability` has no builder
	 * equivalent.
	 *
	 * @return null|callable(string, array<string, mixed>): mixed
	 */
	private function resolve_client_callback(): ?callable {
		$custom = $this->custom_resolver();

		if ( $custom !== null ) {
			return $custom;
		}

		// WP 7.0+ canonical API. wp_supports_ai() lets the host disable AI
		// per-request (WP_AI_SUPPORT constant + `wp_supports_ai` filter),
		// so respect it before invoking the builder — saves an upstream
		// call that core has already decided to refuse.
		// Called by name (WP 7.0 AI Client is optional progressive
		// enhancement on a 6.4-minimum plugin); the function_exists() guards
		// remain the real safety check.
		$prompt_fn   = 'wp_ai_client_prompt';
		$supports_fn = 'wp_supports_ai';

		if (
			function_exists( $prompt_fn )
			&& ( ! function_exists( $supports_fn ) || $supports_fn() )
		) {
			return static function ( string $prompt, array $args ): string {
				$builder = self::new_builder( $prompt );

				// Type-narrow for PHPStan + defensive at runtime. The
				// builder API may evolve in WP 7.x and any non-object
				// return is malformed — treat as a fatal error rather
				// than calling methods on a non-object.
				if ( ! is_object( $builder ) ) {
					throw new \RuntimeException(
						esc_html__( 'wp_ai_client_prompt() did not return a builder object.', 'perflocale' )
					);
				}

				// Builder methods mutate `$this` in-place and return $this
				// for chaining (see WP_AI_Client_Prompt_Builder::__call —
				// `return $this`), so each option is applied for side-effect
				// and the builder is never reassigned.
				//
				// ⚠️ The guard MUST be is_callable(), not method_exists().
				// Core's WP_AI_Client_Prompt_Builder declares usingTemperature
				// / usingMaxTokens / usingProvider / usingSystemInstruction as
				// @method docblock entries only and routes them through
				// __call(), so method_exists() answers FALSE for every one of
				// them on the only builder that ships in WordPress. Guarding
				// with it therefore dropped EVERY option silently: translations
				// ran at the provider's default temperature instead of 0.2, and
				// the `provider` / `model` routing that the
				// perflocale/mt/wp_ai_client_args docblock tells site owners to
				// use did nothing at all. is_callable() answers true for a
				// magic method and false for a genuinely absent one, which is
				// the question being asked. Found by
				// tools/regression-tests/wp-ai-client-roundtrip.php C.6, which
				// is the first test to drive core's real builder — the stubbed
				// resolver in cov-machine-translation.php never touched it.
				$apply = static function ( object $target, string $method, $value ): void {
					if ( ! is_callable( [ $target, $method ] ) ) {
						return;
					}

					// Invoked as an array callable rather than
					// `$target->$method()` so static analysis does not have to
					// resolve a dynamic method on a bare object.
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

				// Model preference: variadic, so not routed through $apply. The
				// entries were validated in client_args(); an invalid one would
				// put core's builder into its error state.
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

				// Call the SNAKE_CASE method. Core's WP_AI_Client_Prompt_Builder
				// applies wp_supports_ai() and the site-wide
				// `wp_ai_client_prevent_prompt` policy filter ONLY inside its
				// __call() proxy, i.e. only for snake_case names; the camelCase
				// generateText() is the underlying SDK method and reaches the
				// provider without them. A builder without __call (a non-core
				// SDK object) has no policy layer, so it keeps the direct call.
				//
				// When no AI provider is configured / available / the prompt is
				// blocked, __call returns `$this->error` (a WP_Error) or the
				// builder itself instead of throwing — so we detect the failure
				// shape and convert it to a RuntimeException for the outer
				// try / breaker. Without this, the strict `: string` return type
				// triggers a TypeError that the breaker classifier reads as
				// 'unknown' rather than the real cause.
				$result = method_exists( $builder, '__call' )
					? $builder->generate_text()
					: $builder->generateText();

				if ( is_string( $result ) ) {
					return $result;
				}

				// The WP_Error carries the HTTP status (core's
				// exception_to_wp_error()); it travels on as the exception
				// code, which is_temperature_rejection() reads.
				if ( $result instanceof \WP_Error ) {
					$data = $result->get_error_data();

					throw new \RuntimeException(
						esc_html( $result->get_error_message() ?: 'wp_ai_client error' ),
						is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) ? (int) $data['status'] : 0
					);
				}

				throw new \RuntimeException(
					esc_html__( 'WordPress AI Client returned no text — likely no AI provider configured, or the prompt was blocked by a wp_ai_client_prevent_prompt filter.', 'perflocale' )
				);
			};
		}

		return null;
	}

	/**
	 * A new core prompt builder for a prompt. Called by name: the AI Client
	 * exists on WP 7.0+ only, and every caller checks for it first.
	 *
	 * @param string $prompt Prompt text.
	 * @return mixed The builder (an object), as wp_ai_client_prompt() returns it.
	 */
	private static function new_builder( string $prompt ): mixed {
		$prompt_fn = 'wp_ai_client_prompt';

		return $prompt_fn( $prompt );
	}

	/**
	 * Classify an upstream AI-client Throwable into a coarse category the
	 * cron log / Site Health card can act on. Returns one of:
	 *   - 'auth'            → bad / missing / revoked API key (admin must
	 *                         rotate), or a connector this plugin has not been
	 *                         approved to use
	 *   - 'rate_limit'      → provider throttled the call (operator can wait)
	 *   - 'transient'       → network / timeout / 5xx (retries help)
	 *   - 'invalid_request' → the provider refused the request itself (400)
	 *   - 'unknown'         → couldn't classify; surface raw message verbatim
	 *
	 * Heuristic only — every AI provider phrases errors differently. We
	 * inspect the message + HTTP status hints from `WP_Error`-style codes
	 * when present. Conservative: ambiguous strings fall through to
	 * 'unknown' rather than misclassify and mask a real issue.
	 *
	 * @param \Throwable $e Original exception from the AI client.
	 * @return string Category tag (one of: auth, rate_limit, transient, invalid_request, unknown).
	 */
	public static function classify_error( \Throwable $e ): string {
		$msg = strtolower( $e->getMessage() );

		// Auth failures — checked first because some providers return 401 with
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
