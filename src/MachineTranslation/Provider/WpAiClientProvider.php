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
 * provider / system instruction) and finalises with `->generateText()`.
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
	 * Available only when the host site has the AI Client API loaded AND a
	 * provider configured behind it. We can't introspect "is a provider
	 * configured" without calling the API, so the looser check (function
	 * exists) is what gates the picker UI; an unconfigured client surfaces
	 * later as a RuntimeException at translate time.
	 *
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return $this->resolve_client_callback() !== null;
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

		try {
			$result = $client( $prompt, $this->client_args( $fast_fail ) );
		} catch ( \Throwable $e ) {
			// Record the failure on the breaker BEFORE re-throwing so the
			// next caller in this request (or shortly after) gets the open
			// breaker instead of another failing upstream call. Auth errors
			// trip on the first hit (threshold_override=1) — no number of
			// retries fixes a bad API key, so the breaker should open fast
			// and let the operator see it in Site Health.
			$reason             = self::classify_error( $e );
			$threshold_override = $reason === 'auth' ? 1 : 0;
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, $reason, $threshold_override );

			// Mask credential-shaped runs BEFORE the message is surfaced.
			// This provider bypasses AbstractProvider::make_request(), which is
			// where every HTTP provider's error body gets masked — so without
			// this call the raw upstream text is what gets thrown, and for a
			// background job it is persisted verbatim on the job row that the
			// Jobs page and the REST detail endpoint render. Real upstreams do
			// echo key material: OpenAI's 401 reads `Incorrect API key
			// provided: sk-…`. classify_error() above runs on the RAW message
			// so redaction can't change the category.
			$safe_message = self::mask_credentials( $e->getMessage() );

			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: classified category (auth/rate-limit/transient/unknown), 2: underlying error message */
						__( 'AI Client translation failed [%1$s]: %2$s', 'perflocale' ),
						$reason,
						$safe_message
					)
				)
			);
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
		];

		/**
		 * Filter the argument array passed to the WordPress AI Client call.
		 *
		 * Site owners can route translation to a specific provider/model
		 * (e.g. `[ 'provider' => 'anthropic', 'model' => 'claude-opus-4-7' ]`),
		 * adjust temperature, or set timeouts.
		 *
		 * @hook perflocale/mt/wp_ai_client_args
		 *
		 * @param array<string, mixed> $args      Default args.
		 * @param bool                 $fast_fail Whether the caller asked for fast fail.
		 */
		return (array) apply_filters( 'perflocale/mt/wp_ai_client_args', $args, $fast_fail );
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
	 * recognised (each gated by method_exists so a stripped / future
	 * builder build can't fatal):
	 *
	 *   - `temperature`        → `->usingTemperature( float )`
	 *   - `max_tokens`         → `->usingMaxTokens( int )`
	 *   - `provider`           → `->usingProvider( string )`
	 *   - `system_instruction` → `->usingSystemInstruction( string )`
	 *
	 * Anything else in `$args` is ignored. `timeout` and `capability` —
	 * carried in the args for older API shapes — are intentionally NOT
	 * passed to the builder; the WP 7.0 builder has no equivalent
	 * properties for them.
	 *
	 * @return null|callable(string, array): string
	 */
	private function resolve_client_callback(): ?callable {
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

		if ( is_callable( $custom ) ) {
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
			return static function ( string $prompt, array $args ) use ( $prompt_fn ): string {
				$builder = $prompt_fn( $prompt );

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
				// `return $this`). Don't reassign — the return type from
				// each method-via-__call is mixed, which would widen
				// $builder back to mixed and break the subsequent
				// method_exists() narrowing. Calling for side-effect is
				// equivalent because the underlying state lives on the
				// same builder instance.
				if ( isset( $args['temperature'] ) && is_numeric( $args['temperature'] ) && method_exists( $builder, 'usingTemperature' ) ) {
					$builder->usingTemperature( (float) $args['temperature'] );
				}
				if ( isset( $args['max_tokens'] ) && is_int( $args['max_tokens'] ) && method_exists( $builder, 'usingMaxTokens' ) ) {
					$builder->usingMaxTokens( $args['max_tokens'] );
				}
				if ( isset( $args['provider'] ) && is_string( $args['provider'] ) && $args['provider'] !== '' && method_exists( $builder, 'usingProvider' ) ) {
					$builder->usingProvider( $args['provider'] );
				}
				if ( isset( $args['system_instruction'] ) && is_string( $args['system_instruction'] ) && $args['system_instruction'] !== '' && method_exists( $builder, 'usingSystemInstruction' ) ) {
					$builder->usingSystemInstruction( $args['system_instruction'] );
				}

				// Call the SNAKE_CASE method. Core's WP_AI_Client_Prompt_Builder
				// applies wp_supports_ai() and the site-wide
				// `wp_ai_client_prevent_prompt` policy filter ONLY inside its
				// __call() proxy, i.e. only for snake_case names. The camelCase
				// generateText() is the underlying SDK method and reaches the
				// provider with no policy check at all — so a site that had
				// blocked AI prompts globally was still being prompted by this
				// plugin (found by tools/regression-tests/cov-machine-translation.php
				// I15f). A builder without __call (a non-core SDK object) has no
				// policy layer to bypass, so it keeps the direct call.
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

				if ( $result instanceof \WP_Error ) {
					throw new \RuntimeException( esc_html( $result->get_error_message() ?: 'wp_ai_client error' ) );
				}

				throw new \RuntimeException(
					esc_html__( 'WordPress AI Client returned no text — likely no AI provider configured, or the prompt was blocked by a wp_ai_client_prevent_prompt filter.', 'perflocale' )
				);
			};
		}

		return null;
	}

	/**
	 * Classify an upstream AI-client Throwable into a coarse category the
	 * cron log / Site Health card can act on. Returns one of:
	 *   - 'auth'      → bad / missing / revoked API key (admin must rotate)
	 *   - 'rate_limit'→ provider throttled the call (operator can wait)
	 *   - 'transient' → network / timeout / 5xx (retries help)
	 *   - 'unknown'   → couldn't classify; surface raw message verbatim
	 *
	 * Heuristic only — every AI provider phrases errors differently. We
	 * inspect the message + HTTP status hints from `WP_Error`-style codes
	 * when present. Conservative: ambiguous strings fall through to
	 * 'unknown' rather than misclassify and mask a real issue.
	 *
	 * @param \Throwable $e Original exception from the AI client.
	 * @return string Category tag (one of: auth, rate_limit, transient, unknown).
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
			|| str_contains( $msg, 'service unavailable' )
			|| str_contains( $msg, 'gateway' )
		) {
			return 'transient';
		}

		return 'unknown';
	}
}
