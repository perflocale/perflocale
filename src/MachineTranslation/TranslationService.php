<?php
/**
 * Machine translation orchestrator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\MachineTranslation\Provider\DeepLProvider;
use PerfLocale\MachineTranslation\Provider\GoogleProvider;
use PerfLocale\MachineTranslation\Provider\LibreTranslateProvider;
use PerfLocale\MachineTranslation\Provider\MicrosoftProvider;
use PerfLocale\MachineTranslation\Provider\ExternalAgencyProvider;
use PerfLocale\MachineTranslation\Provider\WpAiClientProvider;
use PerfLocale\Settings;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates machine translation of posts and strings.
 *
 * Selects the appropriate provider, handles errors, fires hooks,
 * and updates translation status and source metadata.
 */
final class TranslationService {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Registered providers.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;

		$this->register_default_providers();
	}

	/**
	 * Register the built-in providers.
	 *
	 * @return void
	 */
	private function register_default_providers(): void {
		$this->providers['deepl']           = new DeepLProvider( $this->settings );
		$this->providers['google']          = new GoogleProvider( $this->settings );
		$this->providers['microsoft']       = new MicrosoftProvider( $this->settings );
		$this->providers['libretranslate']  = new LibreTranslateProvider( $this->settings );
		$this->providers['external_agency'] = new ExternalAgencyProvider( $this->settings );
		$this->providers['wp_ai_client']    = new WpAiClientProvider( $this->settings );

		/**
		 * Filter the available machine translation providers.
		 *
		 * @param array<string, ProviderInterface> $providers Registered providers.
		 */
		$this->providers = apply_filters( 'perflocale/machine_translation/providers', $this->providers );
	}

	/**
	 * Get a provider by ID.
	 *
	 * @param string $id Provider ID (empty = use configured default).
	 * @return ProviderInterface
	 *
	 * @throws \RuntimeException If provider not found or not configured.
	 */
	public function get_provider( string $id = '' ): ProviderInterface {
		if ( $id === '' ) {
			$id = $this->settings->get_mt_provider();
		}

		if ( $id === '' ) {
			throw new \RuntimeException(
				esc_html__(
					'No machine translation provider has been selected. Open PerfLocale → Settings → Addons → Machine Translation and pick a provider.',
					'perflocale'
				)
			);
		}

		if ( ! isset( $this->providers[ $id ] ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: Provider ID */
						__( 'Unknown translation provider: %s', 'perflocale' ),
						$id
					)
				)
			);
		}

		$provider = $this->providers[ $id ];

		if ( ! $provider->is_configured() ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: Provider name */
						__( '%s is not configured. Check its settings under PerfLocale → Settings → Addons → Machine Translation.', 'perflocale' ),
						$provider->get_name()
					)
				)
			);
		}

		return $provider;
	}

	/**
	 * Sanitize HTML returned from a machine translation provider.
	 *
	 * Derived from WordPress' own post allowlist by SUBTRACTION rather than
	 * hand-rolled, so it tracks core instead of drifting from it. Core already
	 * excludes script, iframe, embed, style, link, form, input and select; on
	 * top of that this drops `object`, `textarea` and `button`, and adds
	 * `source` (core omits it, but the core/video and core/audio blocks emit
	 * it). A hand-maintained list silently stripped figure, figcaption, video,
	 * audio, dl/dt/dd, caption, colgroup, details/summary and the sectioning
	 * elements — which corrupts machine-translated block markup, and turns a
	 * body made entirely of embed markup into an empty string.
	 *
	 * Developers can expand or tighten the allowlist with the
	 * `perflocale/mt/allowed_html` filter.
	 *
	 * @param string $html Raw HTML from the provider.
	 * @return string Sanitized HTML safe for storage as post content.
	 */
	public static function sanitize_mt_html( string $html ): string {
		if ( $html === '' ) {
			return '';
		}

		$allowed = wp_kses_allowed_html( 'post' );

		// Tags core permits that a machine-translation response has no
		// legitimate reason to introduce.
		unset( $allowed['object'], $allowed['textarea'], $allowed['button'] );

		$allowed['source'] = [
			'src'    => true,
			'type'   => true,
			'srcset' => true,
			'sizes'  => true,
			'media'  => true,
		];

		/**
		 * Filter the HTML allowlist applied to machine-translated content.
		 *
		 * @hook perflocale/mt/allowed_html
		 * @param array<string, array<string, bool>> $allowed Allowed tags/attributes.
		 */
		$allowed = apply_filters( 'perflocale/mt/allowed_html', $allowed );

		// Run KSES against the tightened allowlist. wp_kses() also neutralises
		// javascript:/data: URLs in attributes and strips event handlers.
		return wp_kses( $html, $allowed );
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Translate an entire post to a target language.
	 *
	 * Translates title, content, and excerpt. Creates the translation
	 * post if it doesn't exist, or updates it if it does.
	 *
	 * @param int $post_id Source post ID.
	 * @param string $target_lang Target language slug.
	 * @param string $provider_id Provider ID (empty = default).
	 * @return array{post_id: int, title: string, content: string, excerpt: string}
	 *
	 * @throws \RuntimeException On translation failure.
	 */
	/**
	 * Whether at least one provider has an API key / URL configured.
	 *
	 * "MT enabled" in settings doesn’t guarantee a translation can
	 * actually run - the user might have flipped the toggle but
	 * never saved a key. This helper answers the stronger question:
	 * "can we actually dispatch a translation right now?"
	 *
	 * Used by the editor-side block-toolbar to decide whether to show
	 * the language submenu vs a "Set up machine translation" prompt.
	 *
	 * @return bool
	 */
	public function has_any_configured_provider(): bool {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_configured() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the *currently selected* MT provider is ready to translate.
	 *
	 * Stricter than {@see has_any_configured_provider()}: a user can have an
	 * API key set (e.g. via wp-config constant) for DeepL but leave the
	 * Provider dropdown on "-- Select --". In that state any translate
	 * attempt fails at {@see get_provider()} with an empty-ID error. This
	 * predicate lets the editor hide the Translate buttons until the
	 * selected provider can actually run.
	 *
	 * For the WordPress AI Client, "configured" only means the API exists; ready
	 * also needs a connected AI provider that can generate text
	 * ({@see WpAiClientProvider::is_ready_for_text()}).
	 *
	 * @return bool
	 */
	public function is_active_provider_ready(): bool {
		$id = $this->settings->get_mt_provider();

		if ( $id === '' || ! isset( $this->providers[ $id ] ) ) {
			return false;
		}

		$provider = $this->providers[ $id ];

		if ( ! $provider->is_configured() ) {
			return false;
		}

		return $provider instanceof WpAiClientProvider ? $provider->is_ready_for_text() : true;
	}

	/**
	 * Where a "set up machine translation" link sends a site whose selected
	 * provider is not ready.
	 *
	 * With the WordPress AI Client selected, what is missing is a connected
	 * AI provider, so the link goes to core's Settings → Connectors screen
	 * when that screen exists. Every other case gets `$fallback`.
	 *
	 * @param string $fallback PerfLocale settings URL for every other case.
	 * @return string
	 */
	public function setup_url( string $fallback ): string {
		if ( 'wp_ai_client' === $this->settings->get_mt_provider() && self::connectors_screen_exists() ) {
			return admin_url( 'options-connectors.php' );
		}

		return $fallback;
	}

	/**
	 * Whether core's Settings → Connectors screen exists (WordPress 7.0+).
	 *
	 * @return bool
	 */
	public static function connectors_screen_exists(): bool {
		return file_exists( ABSPATH . 'wp-admin/options-connectors.php' );
	}

	/**
	 * Translate a single text snippet - the block-level / inline entry
	 * point used by the Gutenberg block toolbar.
	 *
	 * Unlike `translate_post()` this doesn't persist anything or touch
	 * the per-post translation-group state. It runs the text through the
	 * current provider and returns the result; the monthly
	 * character cap is enforced before the provider call, same as every
	 * other provider-bound path. Caller decides what to do with it.
	 *
	 * @param string $text Source HTML/text to translate.
	 * @param string $source_lang Source language slug (e.g. "en").
	 * @param string $target_lang Target language slug (e.g. "fr").
	 * @param string $provider_id Provider ID (empty = configured default).
	 * @param bool   $fast_fail   Skip provider-internal retries.
	 * @return string Translated text.
	 *
	 * @throws \RuntimeException On provider failure, misconfiguration, or
	 *   when the monthly character cap would be exceeded.
	 */
	public function translate_text( string $text, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false ): string {
		$text = trim( $text );

		if ( $text === '' ) {
			return '';
		}

		$provider = $this->get_provider( $provider_id );

		/**
		 * Filter source text before inline / block-level translation.
		 *
		 * Mirrors the `perflocale/mt/pre_translate` hook used by
		 * translate_post() but at a single-text granularity so callers
		 * can hook it without having to know the full batch shape.
		 *
		 * @hook perflocale/mt/pre_translate_text
		 * @param string $text
		 * @param string $source_lang
		 * @param string $target_lang
		 * @param string $provider_id
		 */
		$text = (string) apply_filters(
			'perflocale/mt/pre_translate_text',
			$text,
			$source_lang,
			$target_lang,
			$provider->get_id()
		);

		// Monthly overage cap — the same gate translate_batch_texts() applies,
		// so single-text callers (block-editor toolbar clicks) can't keep
		// spending provider budget after the cap is hit. Counted on exactly
		// what is sent to the provider.
		if ( $this->would_exceed_limit( mb_strlen( $text ) ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Characters required, %2$s: Monthly limit */
						__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( mb_strlen( $text ) ),
						number_format_i18n( (int) $this->settings->get( 'mt_monthly_char_limit', 500000 ) )
					)
				)
			);
		}

		$translated = $provider->translate( $text, $source_lang, $target_lang, $fast_fail );

		// Same kses allowlist translate_post() runs on every block - keeps
		// provider output from smuggling scripts / iframes into a block
		// attribute that Gutenberg will render as HTML.
		$translated = self::sanitize_mt_html( $translated );

		// A non-empty source that comes back empty is a provider failure, not
		// a translation: the block-editor callers write the return value
		// straight into block attributes, so returning '' here would blank the
		// user's content under a success toast. Fail loudly instead — the same
		// philosophy as translate_batch_texts()'s size-mismatch guard and
		// translate_post()'s "existing content was kept" warning. Runs before
		// the post_translate_text filter so an integration that deliberately
		// empties the result via the filter keeps that ability.
		if ( '' === trim( $translated ) ) {
			throw new \RuntimeException(
				esc_html__( 'The translation provider returned an empty result; aborting so existing content is not overwritten.', 'perflocale' )
			);
		}

		/**
		 * Filter the translated text before return.
		 *
		 * @hook perflocale/mt/post_translate_text
		 * @param string $translated
		 * @param string $source
		 * @param string $source_lang
		 * @param string $target_lang
		 * @param string $provider_id
		 */
		return (string) apply_filters(
			'perflocale/mt/post_translate_text',
			$translated,
			$text,
			$source_lang,
			$target_lang,
			$provider->get_id()
		);
	}

	/**
	 * Batch-translate an array of texts in ONE provider round-trip.
	 *
	 * Mirrors the per-text path of translate_text() (filters, sanitize)
	 * but uses the provider's translate_batch API so the underlying HTTP
	 * call is a single request - DeepL/Google/Microsoft all charge
	 * per-request fees on top of per-character pricing, so batching saves
	 * money proportional to N.
	 *
	 * @param string[] $texts Source HTML/text strings to translate (in order).
	 * @param string   $source_lang
	 * @param string   $target_lang
	 * @param string   $provider_id Provider ID ('' = configured default).
	 * @param bool     $fast_fail   Skip provider-internal retries.
	 * @param string   $format      Destination format hint: 'html' (default —
	 *   provider HTML mode + allowlist sanitiser) or 'text' (provider text
	 *   mode + text sanitiser, for plain-text destinations that must not carry
	 *   entity-escaped output). One format per call; group by format upstream.
	 * @return string[] Translated strings, parallel-indexed to $texts.
	 *
	 * @throws \RuntimeException On provider failure or misconfiguration.
	 */
	public function translate_batch_texts( array $texts, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false, string $format = 'html' ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		// Re-key to 0..N-1 so the result we hand back has the indexing the
		// caller expects.
		$texts          = array_values( $texts );
		$original_count = count( $texts );

		// Separate empty / whitespace-only entries from real ones. Empty inputs
		// cost provider character budget for nothing; some providers (DeepL)
		// also return errors on empty strings. We translate only the non-empty
		// subset and re-expand the result to the caller's parallel indexing
		// at the bottom.
		$non_empty    = [];      // subset_index → text
		$position_map = [];   // subset_index → original_index

		foreach ( $texts as $orig_idx => $t ) {
			$t_str = (string) $t;
			if ( trim( $t_str ) === '' ) {
				continue;
			}
			$position_map[] = $orig_idx;
			$non_empty[]    = $t_str;
		}

		if ( empty( $non_empty ) ) {
			// All inputs were empty. Return parallel-indexed empty strings so
			// callers can still use array indexing without bounds-checking.
			return array_fill( 0, $original_count, '' );
		}

		$provider = $this->get_provider( $provider_id );

		// Pre-translate filter, mirroring translate_text(). Applied per-entry
		// so existing single-text hook callers can be reused without change.
		foreach ( $non_empty as $i => $t ) {
			/** This filter is documented in src/MachineTranslation/TranslationService.php */
			$non_empty[ $i ] = (string) apply_filters(
				'perflocale/mt/pre_translate_text',
				(string) $t,
				$source_lang,
				$target_lang,
				$provider->get_id()
			);
		}

		// Monthly overage cap — the same gate translate_post/MetaTranslator
		// apply before spending provider budget. Enforced HERE so every
		// provider-bound path (term MT, bulk string jobs) honors the cap:
		// $non_empty at this point is exactly the provider-billed subset
		// (empty inputs are already separated out above, and callers that
		// pre-check merely re-read the same option — no double count).
		$provider_chars = 0;
		foreach ( $non_empty as $t ) {
			$provider_chars += mb_strlen( (string) $t );
		}

		if ( $this->would_exceed_limit( $provider_chars ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Characters required, %2$s: Monthly limit */
						__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( $provider_chars ),
						number_format_i18n( (int) $this->settings->get( 'mt_monthly_char_limit', 500000 ) )
					)
				)
			);
		}

		$translated = $provider->translate_batch( $non_empty, $source_lang, $target_lang, $fast_fail, $format );

		// Defend against partial provider failure: some providers / network paths
		// will return a SHORTER array than we sent (truncated response, partial
		// timeout). Falling through silently would leave the missing slots as
		// empty strings, which the block-editor caller writes back to block
		// attributes — destroying source text. Treat any size mismatch as a
		// hard failure rather than a silent no-op.
		if ( count( $translated ) !== count( $non_empty ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: number of translations returned, 2: number sent */
						__( 'Translation provider returned %1$d entries for a batch of %2$d; aborting to avoid overwriting source text with empty strings.', 'perflocale' ),
						count( $translated ),
						count( $non_empty )
					)
				)
			);
		}

		// Sanitize per entry, in the same order.
		foreach ( $translated as $i => $t ) {
			// A 'text' batch (SEO title/description, ACF text/textarea) has a
			// plain-text destination: sanitize_mt_html() runs wp_kses, which
			// normalises a bare '&' to '&amp;' — re-escaping the very characters
			// the text-mode provider call was made to keep literal. Use the
			// text sanitiser there (strips tags, keeps newlines, no entity
			// re-encoding); HTML batches keep the allowlist sanitiser.
			$t = ( 'text' === $format )
				? sanitize_textarea_field( (string) $t )
				: self::sanitize_mt_html( (string) $t );

			/** This filter is documented in src/MachineTranslation/TranslationService.php */
			$translated[ $i ] = (string) apply_filters(
				'perflocale/mt/post_translate_text',
				$t,
				$non_empty[ $i ] ?? '',
				$source_lang,
				$target_lang,
				$provider->get_id()
			);
		}

		// Expand the subset result back to caller's original indexing. Empty
		// inputs map to empty outputs so the result array stays parallel.
		$result = array_fill( 0, $original_count, '' );

		foreach ( $position_map as $subset_i => $orig_i ) {
			$result[ $orig_i ] = isset( $translated[ $subset_i ] ) ? (string) $translated[ $subset_i ] : '';
		}

		return $result;
	}

	public function translate_post( int $post_id, string $target_lang, string $provider_id = '', bool $fast_fail = false, bool $include_meta = false ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			throw new \RuntimeException(
				esc_html__( 'Source post not found.', 'perflocale' )
			);
		}

		// Enforce monthly character limit before making any API calls.
		$this->enforce_character_limit( $post );

		$provider = $this->get_provider( $provider_id );

		// Declare the SOURCE object's OWN language, not the site default.
		// A post that is itself a translation, or an original authored in a
		// non-default language — routine after a WPML / Polylang /
		// TranslatePress import, where pre-existing posts are linked into
		// groups — was being announced to the provider as the site default, so
		// DeepL and Google were told "this is English" about text that is not.
		// The value travels on from here into MetaTranslator and into the
		// perflocale/machine_translation/after hook, so it has to be right.
		//
		// detect_post_language() is the same lookup PostTranslationManager uses
		// when it creates the group (PostTranslationManager.php:767-770), it is
		// memoised per request and blog-keyed, and it returns null for a post
		// with no language row — which is the ONLY case that should fall back
		// to the configured default. In a bulk run the read is an L1 hit:
		// BulkTranslateJob primes every source id before the loop.
		$source_language = ( new PostTranslationManager( $this->cache, $this->settings ) )
			->detect_post_language( $post_id );

		if ( $source_language && ! empty( $source_language->slug ) ) {
			$source_lang = (string) $source_language->slug;
		} else {
			$lang_repo   = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
			$default     = $lang_repo->get_default();
			$source_lang = $default ? (string) $default->slug : 'en';
		}

		// A post is never translated into its own language: the result would
		// be written back over the post itself. Checked here so every caller
		// (REST, abilities, WP-CLI, jobs) is covered.
		if ( strtolower( trim( $source_lang ) ) === strtolower( trim( $target_lang ) ) ) {
			throw new SameLanguageException(
				esc_html__( 'Source and target languages match; nothing to translate.', 'perflocale' )
			);
		}

		/** @hook perflocale/machine_translation/before Fires before machine translation. */
		do_action( 'perflocale/machine_translation/before', $post_id, $provider->get_id() );

		try {
			$texts = [
				$post->post_title,
				$post->post_content,
				$post->post_excerpt,
			];

			/**
			 * Filter texts before sending to the MT provider.
			 *
			 * @param array $texts Array of texts to translate.
			 * @param string $source_lang Source language slug.
			 * @param string $target_lang Target language slug.
			 * @param string $provider_id Provider ID.
			 */
			$texts = apply_filters( 'perflocale/mt/pre_translate', $texts, $source_lang, $target_lang, $provider->get_id() );

			// Title and excerpt are plain-text destinations — request text
			// mode so the provider returns unescaped output (HTML mode
			// contractually entity-escapes markup-significant characters:
			// &amp;, &#39;). Content is an HTML destination and stays in
			// html mode. Providers accept one format per request, so the
			// [title, content, excerpt] triple travels as two calls. Empty
			// slots are skipped client-side: some providers reject empty
			// strings (DeepL — on the html path its neutral-root wrap
			// incidentally hides this), and '' translates to '' anyway.
			$plain_inputs = [];
			$plain_slots  = [];

			foreach ( [ 0, 2 ] as $slot ) {
				$value = (string) ( $texts[ $slot ] ?? '' );

				if ( '' !== $value ) {
					$plain_slots[]  = $slot;
					$plain_inputs[] = $value;
				}
			}

			$plain_out = [] === $plain_inputs
				? []
				: $provider->translate_batch( $plain_inputs, $source_lang, $target_lang, $fast_fail, 'text' );

			$content_src = (string) ( $texts[1] ?? '' );
			$html_out    = '' === $content_src
				? [ '' ]
				: $provider->translate_batch( [ $content_src ], $source_lang, $target_lang, $fast_fail, 'html' );

			// A well-behaved provider returns exactly one translation per input,
			// in order. A short/long response would scramble the positional
			// [title, content, excerpt] mapping below (or silently blank fields
			// via ?? ''), shipping corrupted content. Treat a length mismatch as
			// a provider-contract failure rather than persisting garbage.
			if ( count( $plain_out ) !== count( $plain_inputs ) || count( $html_out ) !== 1 ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: 1: provider id, 2: expected count, 3: actual count. */
							__( 'Translation provider "%1$s" returned %3$d segments for %2$d inputs; aborting to avoid corrupting the translation.', 'perflocale' ),
							$provider->get_id(),
							count( $plain_inputs ) + 1,
							count( $plain_out ) + count( $html_out )
						)
					)
				);
			}

			// Reassemble the positional [title, content, excerpt] triple the
			// downstream filter/sanitize steps are contracted to.
			$translated = [ '', (string) $html_out[0], '' ];

			foreach ( $plain_slots as $i => $slot ) {
				$translated[ $slot ] = (string) ( $plain_out[ $i ] ?? '' );
			}

			/**
			 * Filter texts after receiving from the MT provider.
			 *
			 * @param array $translated Array of translated texts.
			 * @param array $original Array of original texts.
			 * @param string $target_lang Target language slug.
			 * @param string $provider_id Provider ID.
			 */
			$pre_filter = $translated;
			$translated = apply_filters( 'perflocale/mt/post_translate', $translated, [ $post->post_title, $post->post_content, $post->post_excerpt ], $target_lang, $provider->get_id() );

			// The provider lost a "Do not translate" placeholder, so
			// BlockSkipFilter put the source content back in place of the
			// translation. Reported to the caller, and on an existing
			// translation its content is kept (below).
			$content_kept_source = \PerfLocale\Translation\BlockSkipFilter::consume_kept_source( [ $post->post_title, $post->post_content, $post->post_excerpt ] );

			// Validate the POSITIONAL shape, not just is_array(): a string-keyed
			// or short array passes is_array() but then $translated[1] ?? ''
			// below would silently blank the content (and persist empty content
			// as success). Require the exact [0=>title, 1=>content, 2=>excerpt]
			// string triple; anything else falls back to the unfiltered result.
			$valid_shape = is_array( $translated )
				&& array_key_exists( 0, $translated ) && is_string( $translated[0] )
				&& array_key_exists( 1, $translated ) && is_string( $translated[1] )
				&& array_key_exists( 2, $translated ) && is_string( $translated[2] );

			if ( ! $valid_shape ) {
				_doing_it_wrong(
					'apply_filters( "perflocale/mt/post_translate", ... )',
					esc_html(
						sprintf(
							/* translators: %s is the offending return type. */
							__( 'A hook on perflocale/mt/post_translate returned %s — must be an array<int,string> of [title, content, excerpt]. Falling back to the unfiltered translation.', 'perflocale' ),
							is_array( $translated ) ? 'a malformed array (expected positional [title, content, excerpt] strings)' : get_debug_type( $translated )
						)
					),
					'1.0.0'
				);
				$translated = $pre_filter;
			}

			// Sanitize translated content to prevent XSS from compromised or
			// malicious MT providers (response injection, DNS hijack, etc.).
			// Title and excerpt are plain text; content uses a tightened
			// allowlist to defend against unexpected tags that wp_kses_post
			// would otherwise permit.
			//
			// Title/excerpt were requested in text mode, so the provider
			// output is already unescaped plain text — no entity decode here.
			// Decoding on top of text-mode output would DOUBLE-decode: a
			// legitimate literal '&amp;' a user typed as content would come
			// back as '&amp;' and be collapsed to '&'.
			$result = [
				'title'   => \PerfLocale\Helper::sanitize_plain_text_field( $translated[0] ?? '' ),
				'content' => self::sanitize_mt_html( $translated[1] ?? '' ),
				'excerpt' => \PerfLocale\Helper::sanitize_plain_textarea_field( $translated[2] ?? '' ),
			];

			// Create or update the translation post.
			$manager       = new PostTranslationManager( $this->cache, $this->settings );
			$translated_id = $manager->get_translation_id( $post_id, $target_lang );

			// Never persist an empty FIELD over source text that wasn't empty.
			// The count-shape guards upstream only compare array LENGTHS, so a
			// provider returning "" — or an allowlist that strips a body made
			// entirely of embed markup — reaches here as a well-shaped result
			// and would blank an existing, possibly human-reviewed translation
			// while reporting success. Mirrors the "source value kept"
			// semantics MetaTranslator and BulkStringTranslateJob already use:
			// drop only the empty field, keep the rest, and tell the caller.
			//
			// All THREE fields need this, not just the body. Title and excerpt
			// travel in the provider's TEXT batch, and every bundled provider
			// reads that batch positionally with `$row['text'] ?? ''`, so an
			// upstream JSON-key rename (or a proxy rewriting the envelope)
			// produces empty strings behind a 200, with the right COUNT — which
			// is all the mismatch guards above check. Without this loop a
			// re-translate then wrote post_title = '' over a reviewed
			// translation and reported success: the post shows as "(no title)"
			// in every listing, and nothing records what it used to be.
			//
			// On the create path there is nothing to lose, so an empty field is
			// written as-is rather than blocking the translation; `kept` is
			// therefore only reported when a real value was actually preserved.
			$emptied = [];

			foreach (
				[
					'title'   => 'post_title',
					'content' => 'post_content',
					'excerpt' => 'post_excerpt',
				] as $field => $column
			) {
				if ( '' === trim( (string) $result[ $field ] ) && '' !== trim( (string) $post->{$column} ) ) {
					$emptied[ $field ] = $column;
				}
			}

			$content_emptied = isset( $emptied['content'] );

			if ( $content_emptied ) {
				$result['warnings'][] = __( 'The provider returned no translated content; the existing content was kept.', 'perflocale' );
			}

			if ( $content_kept_source ) {
				$result['content_kept_source'] = true;
				$result['warnings'][]          = __( 'A "Do not translate" section was lost in machine translation, so the content was kept unchanged.', 'perflocale' );
			}

			if ( $translated_id ) {
				// The content in hand is the untranslated source. An existing
				// translation keeps the content it has instead of having the
				// source written over it; an empty one receives the source, as
				// a new translation does.
				if ( $content_kept_source ) {
					$existing_content = get_post_field( 'post_content', (int) $translated_id, 'raw' );

					if ( is_string( $existing_content ) && '' !== trim( $existing_content ) ) {
						$emptied['content'] = 'post_content';
					}
				}

				// Machine-readable field list (not user-facing text) so CLI and
				// REST callers can name exactly which values were preserved.
				if ( $emptied !== [] ) {
					$result['kept'] = array_keys( $emptied );
				}

				$update_fields = [
					'ID'           => $translated_id,
					'post_title'   => $result['title'],
					'post_content' => $result['content'],
					'post_excerpt' => $result['excerpt'],
				];

				foreach ( $emptied as $column ) {
					unset( $update_fields[ $column ] );
				}

				$update_result = wp_update_post(
					// wp_slash: provider output is unslashed; wp_update_post()
					// unslashes internally, so backslashes in machine-translated
					// content (code samples, paths) would be silently stripped.
					wp_slash( $update_fields ),
					true
				);

				if ( is_wp_error( $update_result ) ) {
					throw new \RuntimeException( $update_result->get_error_message() );
				}
			} else {
				$inserted      = false;
				$translated_id = $manager->create_translation( $post_id, $target_lang, false, \PerfLocale\Enum\SourceType::MachineTranslation, $inserted );

				if ( ! $translated_id ) {
					// create_translation can return false on race or invalid
					// state (target slug removed mid-call, link transaction
					// failed). Falling through here would mean we ran the MT
					// call (and burned characters) but produced no translation
					// post — a silent loss the caller (CLI / REST / cron)
					// would report as success. Surface the failure.
					throw new \RuntimeException(
						esc_html__( 'Failed to create the translation post; the source-language post may have been moved or its translation group invalidated mid-translation.', 'perflocale' )
					);
				}

				// Write machine output only into a translation this call
				// inserted. One it did not insert already existed (created
				// elsewhere, or missed by the cached check above) and may hold a
				// person's work that the callers' overwrite checks never saw.
				// The write below also skips the update branch's empty-field
				// guard, so such a translation is left as it is.
				if ( ! $inserted ) {
					throw new \RuntimeException(
						esc_html__( 'This translation already exists, so the machine translation was not written to it.', 'perflocale' )
					);
				}

				$update_result = wp_update_post(
					// wp_slash: provider output is unslashed; wp_update_post()
					// unslashes internally, so backslashes in machine-translated
					// content (code samples, paths) would be silently stripped.
					wp_slash(
						[
							'ID'           => $translated_id,
							'post_title'   => $result['title'],
							'post_content' => $result['content'],
							'post_excerpt' => $result['excerpt'],
						]
					),
					true
				);

				if ( is_wp_error( $update_result ) ) {
					throw new \RuntimeException( $update_result->get_error_message() );
				}
			}

			$result['post_id'] = (int) $translated_id;

			// Meta-field MT (SEO titles/descriptions, registered custom
			// fields). Failure-isolated: a meta problem never fails the post
			// translation — it lands in $result['meta']['errors'] + the
			// _perflocale_meta_mt_errors breadcrumb.
			if ( $include_meta ) {
				$result['meta'] = ( new MetaTranslator( $this->settings, $this ) )
					->translate_post_meta( $post_id, (int) $translated_id, $source_lang, $target_lang, $provider_id );
			}

			/**
			 * Fires after successful machine translation.
			 *
			 * @hook perflocale/machine_translation/after
			 * @param int      $post_id     Source post ID.
			 * @param string   $provider_id Provider that translated.
			 * @param array    $result      {title, content, excerpt, post_id}.
			 * @param string   $target_lang Target language slug (added 2026-07-12).
			 * @param string   $source_lang Source language slug (added 2026-07-12).
			 * @param \WP_Post $post        Source post object (added 2026-07-12).
			 */
			do_action( 'perflocale/machine_translation/after', $post_id, $provider->get_id(), $result, $target_lang, $source_lang, $post );

			return $result;
		} catch ( \Throwable $e ) {
			/** @hook perflocale/machine_translation/failed Fires when machine translation fails. */
			do_action( 'perflocale/machine_translation/failed', $post_id, $provider->get_id(), $e );

			throw $e;
		} finally {
			// BlockSkipFilter holds this post's "do not translate" subtrees
			// aside at pre_translate and takes them back at post_translate.
			// An exception in between, or a restore that bailed, would leave
			// them in its static for the rest of the process, so the entry is
			// cleared here. The raw triple is the key both the restore and
			// this call derive; on the success path the restore has normally
			// taken the entry already.
			\PerfLocale\Translation\BlockSkipFilter::discard_stash( [ $post->post_title, $post->post_content, $post->post_excerpt ] );
		}
	}

	/**
	 * Predicate form of the monthly-cap check, for callers that need to
	 * decide whether to silently skip instead of erroring out (cron jobs,
	 * background scoring runs, low-priority suggestion endpoints).
	 *
	 * Returns false when the cap is 0 (unlimited) so callers don't need a
	 * separate "is there a cap?" check. Returns true when either the
	 * current usage already crosses the cap or the requested estimate
	 * would push past it.
	 *
	 * @param int $estimated_chars Characters the caller is about to send.
	 * @return bool True if the request would exceed the monthly cap.
	 */
	public function would_exceed_limit( int $estimated_chars ): bool {
		$limit = (int) $this->settings->get( 'mt_monthly_char_limit', 500000 );

		if ( $limit === 0 ) {
			return false;
		}

		$month_key     = 'perflocale_mt_usage_' . gmdate( 'Y_m' );
		$current_usage = (int) get_option( $month_key, 0 );

		if ( $current_usage >= $limit ) {
			return true;
		}

		return ( $current_usage + max( 0, $estimated_chars ) ) > $limit;
	}

	/**
	 * Whether a machine-translation path sends a password-protected post when
	 * nothing hooks `perflocale/mt/send_password_protected`, by trigger: the
	 * one place the default is decided. A path sends by default only when a
	 * person chose that specific post; automatic runs and runs over every
	 * post do not. A trigger not listed does not send.
	 *
	 *   - 'auto_publish'   auto-translate on first publish
	 *   - 'auto_create'    auto-translate when a translation is created
	 *   - 'site_translate' the site-wide job (Translations screen, REST site_wide)
	 *   - 'bulk'           bulk action on posts selected on the Translations
	 *                      screen (inline or as a background job)
	 *   - 'cli_ids'        `wp perflocale translate <id>` or --post-ids
	 *   - 'cli_all'        `wp perflocale translate --all`, in this process or
	 *                      as the --async background chain
	 *   - 'rest'           POST /machine-translate, /machine-translate/object
	 *                      and the bulk-translate route with post_ids
	 *   - 'ability'        the perflocale/translate-post ability
	 *   - 'editor'         the block editor's "Fill in from source" and the
	 *                      block toolbar's translate on the edited post
	 */
	private const SEND_PROTECTED_BY_DEFAULT = [
		'auto_publish'   => false,
		'auto_create'    => false,
		'site_translate' => false,
		'bulk'           => true,
		'cli_ids'        => true,
		'cli_all'        => false,
		'rest'           => true,
		'ability'        => true,
		'editor'         => true,
	];

	/**
	 * Whether a path sends a password-protected post by default (see
	 * SEND_PROTECTED_BY_DEFAULT).
	 *
	 * @param string $trigger The path's trigger.
	 * @return bool
	 */
	public static function sends_protected_by_default( string $trigger ): bool {
		return self::SEND_PROTECTED_BY_DEFAULT[ $trigger ] ?? false;
	}

	/**
	 * Whether a machine-translation path sends this post's text to the
	 * provider. A post without a password is sent. For a password-protected
	 * one the `perflocale/mt/send_password_protected` filter decides, starting
	 * from the path's default (sends_protected_by_default()).
	 *
	 * @param \WP_Post $post    The post whose text would be sent.
	 * @param string   $trigger The path (a key of SEND_PROTECTED_BY_DEFAULT).
	 * @return bool
	 */
	public static function may_send_post( \WP_Post $post, string $trigger ): bool {
		if ( '' === (string) $post->post_password ) {
			return true;
		}

		/**
		 * Whether a machine-translation path sends a password-protected post's
		 * text to the provider.
		 *
		 * Asked by every path that sends a post's title, content or excerpt,
		 * only for a post that has a password. A path that is refused skips the
		 * post and reports it as it reports any skipped item. Triggers and
		 * their default (TranslationService::SEND_PROTECTED_BY_DEFAULT):
		 *
		 *   - 'auto_publish'   auto-translate on first publish. Default false.
		 *   - 'auto_create'    auto-translate when a translation is created
		 *                      (the Create action). Default false.
		 *   - 'site_translate' the site-wide job (Translations screen, REST
		 *                      site_wide). Default false.
		 *   - 'bulk'           bulk action on posts selected on the Translations
		 *                      screen. Default true.
		 *   - 'cli_ids'        `wp perflocale translate <id>` or --post-ids.
		 *                      Default true.
		 *   - 'cli_all'        `wp perflocale translate --all`, also --async.
		 *                      Default false.
		 *   - 'rest'           POST /machine-translate, /machine-translate/object
		 *                      and the bulk-translate route with post_ids.
		 *                      Default true.
		 *   - 'ability'        the perflocale/translate-post ability. Default true.
		 *   - 'editor'         the block editor's "Fill in from source" and the
		 *                      block toolbar's translate on the edited post.
		 *                      Default true.
		 *
		 * @hook  perflocale/mt/send_password_protected
		 * @since 1.0.7
		 *
		 * @param bool     $send    Whether to send the post: the trigger's default.
		 * @param \WP_Post $post    The password-protected post.
		 * @param string   $trigger One of the triggers above.
		 */
		return (bool) apply_filters( 'perflocale/mt/send_password_protected', self::sends_protected_by_default( $trigger ), $post, $trigger );
	}

	/**
	 * The message a path shows when it skips a password-protected post.
	 *
	 * @return string
	 */
	public static function password_protected_skip_message(): string {
		return __( 'This post is password-protected and was not sent for machine translation.', 'perflocale' );
	}

	/**
	 * Throwing form of the monthly-cap check used by the user-initiated
	 * translation path. Wraps would_exceed_limit() and surfaces a
	 * human-readable error so the admin UI can render the message in a
	 * notice.
	 *
	 * @param \WP_Post $post Post to be translated.
	 * @return void
	 *
	 * @throws \RuntimeException If limit would be exceeded.
	 */
	private function enforce_character_limit( \WP_Post $post ): void {
		$limit = (int) $this->settings->get( 'mt_monthly_char_limit', 500000 );

		if ( $limit === 0 ) {
			return;
		}

		$month_key     = 'perflocale_mt_usage_' . gmdate( 'Y_m' );
		$current_usage = (int) get_option( $month_key, 0 );

		if ( $current_usage >= $limit ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Current usage, %2$s: Monthly limit */
						__( 'Monthly character limit reached (%1$s / %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( $current_usage ),
						number_format_i18n( $limit )
					)
				)
			);
		}

		$estimated = mb_strlen( $post->post_title ) + mb_strlen( $post->post_content ) + mb_strlen( $post->post_excerpt );

		if ( ( $current_usage + $estimated ) > $limit ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Estimated characters, %2$s: Remaining quota */
						__( 'This post requires ~%1$s characters but only %2$s remain in the monthly quota. Reduce content or increase the limit in settings.', 'perflocale' ),
						number_format_i18n( $estimated ),
						number_format_i18n( $limit - $current_usage )
					)
				)
			);
		}
	}
}
