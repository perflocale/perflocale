<?php
/**
 * PerfLocale Helper - fluent API for developers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a clean, fluent API for accessing PerfLocale data.
 *
 * Usage:
 * perflocale()->current_language() - Full language object.
 * perflocale()->locale() - e.g. "fr_FR"
 * perflocale()->slug() - e.g. "fr"
 * perflocale()->name() - e.g. "French"
 * perflocale()->native_name() - e.g. "Français"
 * perflocale()->display_name() - "French (Français)"
 * perflocale()->flag() - Flag emoji or code.
 * perflocale()->is_default() - Is current language the default?
 * perflocale()->is_rtl() - Is current language RTL?
 * perflocale()->default_language() - Default language object.
 * perflocale()->languages() - All active languages.
 * perflocale()->permalink( $id, $slug ) - Translated post permalink.
 * perflocale()->term_link( $id, $slug ) - Translated term link.
 * perflocale()->home_url( $slug, $path ) - Language-specific home URL.
 * perflocale()->switcher( $args ) - Language switcher HTML.
 * perflocale()->translations( $post_id ) - [slug => post_id] map.
 * perflocale()->is_language( $slug ) - Check if current language matches.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class Helper {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Per-request memo for the current-language lookup. Populated lazily
	 * by current_language(); cleared by reset_static_caches() on the
	 * switch_blog hook (multisite) and on language admin events that
	 * could change which language wins. Saves N container-lookups when
	 * themes call perflocale()->locale() / slug() / native_name() etc.
	 * inside a template loop — each downstream getter routes through
	 * current_language() and would otherwise re-walk the DI every call.
	 *
	 * Three states: unset (never queried), false (queried and got null —
	 * distinguishes "no current language" from "haven't asked yet"),
	 * object (the language).
	 *
	 * @var object|false|null
	 */
	private static $current_language_memo = null;

	/**
	 * Post meta flag that removes a translation from hreflang tags and
	 * sitemap alternate-link sets ('yes' = excluded). Registered for the
	 * REST API in Bootstrap; toggled from the language meta box and the
	 * block-editor sidebar.
	 *
	 * @var string
	 */
	public const SEO_EXCLUDE_META = '_perflocale_seo_exclude';

	/**
	 * Per-blog memo of the flagged-post id set, keyed by blog id. Loaded
	 * with a single indexed postmeta query on first use; the flag is rare,
	 * so the set stays tiny while sitemap loops (up to ~2000 entries × N
	 * siblings) get O(1) membership checks instead of a get_post_meta()
	 * (and, unprimed, a DB query) per sibling.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $seo_excluded_memo = [];

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Drop every static memo this class holds. Called from the central
	 * Bootstrap reset sweep on switch_blog (multisite) so themes calling
	 * perflocale()->locale() on the new blog don't read the previous
	 * blog's cached current language.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$current_language_memo = null;
		self::$seo_excluded_memo     = [];
	}

	/**
	 * Whether a post is flagged out of hreflang / sitemap alternates.
	 *
	 * Membership check against the per-blog flagged-id set — see
	 * seo_excluded_post_ids() for the loading strategy.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_seo_excluded( int $post_id ): bool {
		return $post_id > 0 && isset( self::seo_excluded_post_ids()[ $post_id ] );
	}

	/**
	 * The set of post IDs carrying the SEO-exclude flag, as id => true.
	 *
	 * One indexed postmeta query per request (meta_key index), memoised
	 * per blog. Consumers run inside sitemap/hreflang rendering where the
	 * per-sibling alternative — get_post_meta() on ids the sitemap prime
	 * deliberately does NOT meta-prime — would cost a query per sibling.
	 * Same-request writes to the flag are invisible to the memo; the flag
	 * is only toggled on admin save requests, which never render hreflang
	 * or sitemaps.
	 *
	 * @return array<int, true>
	 */
	public static function seo_excluded_post_ids(): array {
		$blog_id = get_current_blog_id();

		if ( isset( self::$seo_excluded_memo[ $blog_id ] ) ) {
			return self::$seo_excluded_memo[ $blog_id ];
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single indexed lookup, memoised per request; WP has no batch "all posts with this meta" API short of WP_Query overhead.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes'",
				self::SEO_EXCLUDE_META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$set = [];

		foreach ( (array) $ids as $id ) {
			$set[ (int) $id ] = true;
		}

		self::$seo_excluded_memo[ $blog_id ] = $set;

		return $set;
	}

	/**
	 * Get the current language object.
	 *
	 * Memoised per request — the underlying router lookup is cheap, but
	 * locale() / slug() / name() / native_name() / display_name() / flag()
	 * all route through here, and a template loop calling several of
	 * those per item would otherwise re-walk the DI container hundreds
	 * of times. reset_static_caches() invalidates the memo on switch_blog.
	 *
	 * Defensive against early-call / mid-deactivation scenarios: any
	 * throw from the router lookup is swallowed and treated as "no
	 * current language" so a theme calling this from plugins_loaded
	 * priority 1 (before our DI is fully ready) sees null instead of a
	 * fatal.
	 *
	 * @return object|null
	 */
	public function current_language(): ?object {
		if ( self::$current_language_memo !== null ) {
			return self::$current_language_memo === false ? null : self::$current_language_memo;
		}

		try {
			$lang = $this->safe_router()?->get_current_language();
		} catch ( \Throwable $e ) {
			// Bootstrap not ready, DI factory threw, router returned a
			// half-initialised state, etc. None of these should fatal
			// a theme/template that just wants the current language.
			$lang = null;
		}

		self::$current_language_memo = $lang === null ? false : $lang;

		return $lang;
	}

	/**
	 * Get the current locale (e.g. "fr_FR").
	 *
	 * @return string
	 */
	public function locale(): string {
		return $this->current_language()?->locale ?? get_locale();
	}

	/**
	 * Get the current language slug (e.g. "fr").
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->current_language()?->slug ?? '';
	}

	/**
	 * Get the current language English name (e.g. "French").
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->current_language()?->name ?? '';
	}

	/**
	 * Get the current language native name (e.g. "Français").
	 *
	 * @return string
	 */
	public function native_name(): string {
		$lang = $this->current_language();

		return $lang?->native_name ?? $lang?->name ?? '';
	}

	/**
	 * Get a display string like "French (Français)".
	 *
	 * @return string
	 */
	public function display_name(): string {
		$lang = $this->current_language();

		if ( ! $lang ) {
			return '';
		}

		if ( ! empty( $lang->native_name ) && $lang->native_name !== $lang->name ) {
			return $lang->name . ' (' . $lang->native_name . ')';
		}

		return $lang->name;
	}

	/**
	 * Get the current language flag (emoji or code).
	 *
	 * @return string
	 */
	public function flag(): string {
		return $this->current_language()?->flag ?? '';
	}

	/**
	 * Check if the current language is the default.
	 *
	 * @return bool
	 */
	public function is_default(): bool {
		return $this->router()?->is_default_language() ?? true;
	}

	/**
	 * Check if the current language is RTL.
	 *
	 * @return bool
	 */
	public function is_rtl(): bool {
		$lang = $this->current_language();

		return ( $lang?->text_direction ?? 'ltr' ) === 'rtl';
	}

	/**
	 * Check if the current language matches a given slug.
	 *
	 * @param string $slug Language slug to check.
	 * @return bool
	 */
	public function is_language( string $slug ): bool {
		return $this->slug() === $slug;
	}

	/**
	 * Get the default language object.
	 *
	 * @return object|null
	 */
	public function default_language(): ?object {
		return $this->router()?->get_default_language();
	}

	/**
	 * Get all active languages.
	 *
	 * @return array<int, object>
	 */
	public function languages(): array {
		return $this->router()?->get_active_languages() ?? [];
	}

	/**
	 * Get a translated post permalink.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang_slug Target language slug.
	 * @return string
	 */
	public function permalink( int $post_id, string $lang_slug ): string {
		return perflocale_get_permalink( $post_id, $lang_slug );
	}

	/**
	 * Get a translated term link.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang_slug Target language slug.
	 * @return string
	 */
	public function term_link( int $term_id, string $lang_slug ): string {
		return perflocale_get_term_link( $term_id, $lang_slug );
	}

	/**
	 * Get a language-specific home URL.
	 *
	 * @param string $lang_slug Language slug (empty = current).
	 * @param string $path Optional path.
	 * @return string
	 */
	public function home_url( string $lang_slug = '', string $path = '' ): string {
		return perflocale_home_url( $lang_slug, $path );
	}

	/**
	 * Render the language switcher.
	 *
	 * @param array<string, mixed> $args Display arguments.
	 * @return string HTML output.
	 */
	public function switcher( array $args = [] ): string {
		if ( ! did_action( 'plugins_loaded' ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'PerfLocale\\Helper::switcher() must be called after the plugins_loaded action has fired. Move your call to the init hook (or later).', 'perflocale' ),
				'1.0.0'
			);
			return '';
		}
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'language_switcher' ) ) {
			return '';
		}

		return \PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher(
			$plugin->get( 'language_switcher' )->render( $args )
		);
	}

	/**
	 * Get the URL of the current page in another language.
	 *
	 * Returns the same canonical translated URL the bundled language
	 * switcher and hreflang tags use, so a custom switcher built with
	 * this method stays in lock-step with the rest of the plugin. On
	 * singular pages where the target language has no translation,
	 * returns an empty string — callers can fall through to
	 * `perflocale()->home_url( $lang_slug )` or render an "untranslated"
	 * UI as they prefer.
	 *
	 * Empty string is also returned when the URL converter service is
	 * unavailable (plugin bootstrap incomplete) or when `$lang_slug` is
	 * empty.
	 *
	 *     if ( $de_url = perflocale()->current_url( 'de' ) ) {
	 *         echo '<a href="' . esc_url( $de_url ) . '">Deutsch</a>';
	 *     }
	 *
	 * @param string $lang_slug Target language slug.
	 * @return string Translated URL, or empty string when none is available.
	 */
	public function current_url( string $lang_slug ): string {
		if ( $lang_slug === '' ) {
			return '';
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return '';
		}

		$urls = $plugin->get( 'url_converter' )->get_translations_for_current_page();

		return (string) ( $urls[ $lang_slug ] ?? '' );
	}

	/**
	 * Build a structured list of language-switcher items for the current page.
	 *
	 * Returns the same data the bundled switcher renders, exposed as
	 * an array so themes can build their own markup without re-
	 * implementing URL conversion or translation-availability checks.
	 * The shape of each entry is stable across releases:
	 *
	 *     [
	 *         'slug'           => 'fr',
	 *         'locale'         => 'fr_FR',
	 *         'name'           => 'French',
	 *         'native_name'    => 'Français',
	 *         'flag'           => '🇫🇷',
	 *         'text_direction' => 'ltr',
	 *         'url'            => 'https://example.com/fr/about/',
	 *         'is_current'     => false,
	 *         'is_translated'  => true,
	 *     ]
	 *
	 * Languages without a translation on the current singular page are
	 * still included with `is_translated => false` and an empty `url`
	 * — themes that want the bundled switcher's hide-untranslated
	 * behaviour can filter on that flag.
	 *
	 * @return array<int, array{
	 *     slug: string,
	 *     locale: string,
	 *     name: string,
	 *     native_name: string,
	 *     flag: string,
	 *     text_direction: string,
	 *     url: string,
	 *     is_current: bool,
	 *     is_translated: bool,
	 * }>
	 */
	public function switcher_links(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return [];
		}

		$router       = $plugin->get( 'router' );
		$languages    = $router->get_active_languages();
		$current_slug = $router->get_current_slug();
		$urls         = $plugin->has( 'url_converter' )
			? $plugin->get( 'url_converter' )->get_translations_for_current_page()
			: [];

		$out = [];

		foreach ( $languages as $lang ) {
			$slug = (string) ( $lang->slug ?? '' );

			if ( $slug === '' ) {
				continue;
			}

			$url         = (string) ( $urls[ $slug ] ?? '' );
			$native_name = (string) ( $lang->native_name ?? '' );
			$name        = (string) ( $lang->name ?? '' );

			$out[] = [
				'slug'           => $slug,
				'locale'         => (string) ( $lang->locale ?? '' ),
				'name'           => $name,
				'native_name'    => $native_name !== '' ? $native_name : $name,
				'flag'           => self::get_flag_emoji( $lang ),
				'text_direction' => (string) ( $lang->text_direction ?? 'ltr' ),
				'url'            => $url,
				'is_current'     => $slug === $current_slug,
				'is_translated'  => $url !== '',
			];
		}

		return $out;
	}

	/**
	 * Get all translations of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int> [language_slug => post_id] map.
	 */
	public function translations( int $post_id ): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return [];
		}

		$manager = new Translation\PostTranslationManager(
			$plugin->get( 'cache' ),
			$plugin->get( 'settings' )
		);

		return $manager->get_translations( $post_id );
	}

	/**
	 * Batch version of {@see translations()} — resolve translations for
	 * many posts in a single round-trip. Returns a map keyed by source
	 * post id, with the same `[language_slug => post_id]` shape as
	 * `translations()` for each entry.
	 *
	 * Use this on archive / loop templates that need each post's sibling
	 * translations. Looping {@see translations()} per item issues one
	 * cache (and on a cold cache, two DB) round-trip per post; this
	 * issues at most two queries total regardless of input size, thanks
	 * to the batch path on the underlying repository.
	 *
	 * Posts that have no translations (or whose entry can't be resolved
	 * — invalid id, post type isn't translatable) get an empty array
	 * for that key, never a missing key, so callers can iterate without
	 * isset() guards:
	 *
	 *   foreach ( perflocale()->translations_many( $post_ids ) as $id => $map ) {
	 *       foreach ( $map as $slug => $sibling_id ) { … }
	 *   }
	 *
	 * @param array<int, int> $post_ids List of post IDs.
	 * @return array<int, array<string, int>> source_id => [language_slug => post_id]
	 */
	public function translations_many( array $post_ids ): array {
		$out = [];
		foreach ( $post_ids as $pid ) {
			$out[ (int) $pid ] = [];
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( $i ) => $i > 0 ) ) );

		if ( empty( $post_ids ) ) {
			return $out;
		}

		try {
			$plugin = Plugin::get_instance();
			if ( ! $plugin->has( 'group_repo' ) ) {
				return $out;
			}

			$grouped = $plugin->get( 'group_repo' )->get_translations_for_objects(
				$post_ids,
				Enum\ObjectType::Post
			);
		} catch ( \Throwable $e ) {
			return $out;
		}

		foreach ( $grouped as $source_id => $links ) {
			$map = [];
			foreach ( $links as $link ) {
				if ( isset( $link->language_slug ) ) {
					$map[ (string) $link->language_slug ] = (int) $link->object_id;
				}
			}
			$out[ (int) $source_id ] = $map;
		}

		return $out;
	}

	/**
	 * True if the given post type or taxonomy is translatable per the
	 * site's settings. Saves callers from reaching into the Settings
	 * service for the canonical lists.
	 *
	 *   perflocale()->is_translatable( 'post', 'post_type' )      // bool
	 *   perflocale()->is_translatable( 'category', 'taxonomy' )   // bool
	 *
	 * Returns false when the plugin's settings service isn't reachable
	 * (early-boot, mid-deactivation) — same conservative default the
	 * rest of the Helper API uses.
	 *
	 * @param string $name Post type slug OR taxonomy slug.
	 * @param string $type 'post_type' (default) or 'taxonomy'.
	 * @return bool
	 */
	public function is_translatable( string $name, string $type = 'post_type' ): bool {
		if ( $name === '' ) {
			return false;
		}

		try {
			$plugin = Plugin::get_instance();
			if ( ! $plugin->has( 'settings' ) ) {
				return false;
			}
			$settings = $plugin->get( 'settings' );
		} catch ( \Throwable $e ) {
			return false;
		}

		$list = $type === 'taxonomy'
			? (array) $settings->get_translatable_taxonomies()
			: (array) $settings->get_translatable_post_types();

		return in_array( $name, $list, true );
	}

	/**
	 * Locale-aware number formatting. Uses PHP's intl NumberFormatter
	 * when available so output matches the user's regional conventions
	 * (1,234.56 in en-US, 1.234,56 in de-DE, 1 234,56 in fr-FR, etc.).
	 * Falls back to {@see number_format_i18n()} when intl isn't loaded.
	 *
	 *   perflocale()->format_number( 1234.5 );             // current language
	 *   perflocale()->format_number( 1234.5, 'de' );       // explicit
	 *   perflocale()->format_number( 1234.5, null, 2 );    // decimals
	 *
	 * @param float|int   $value     Number to format.
	 * @param string|null $lang_slug Optional language slug; defaults to current.
	 * @param int|null    $decimals  Optional decimal-place override.
	 * @return string Formatted number.
	 */
	public function format_number( $value, ?string $lang_slug = null, ?int $decimals = null ): string {
		$locale = $this->resolve_locale_for_format( $lang_slug );

		if ( class_exists( '\\NumberFormatter' ) ) {
			$fmt = new \NumberFormatter( $locale, \NumberFormatter::DECIMAL );
			if ( $decimals !== null ) {
				$fmt->setAttribute( \NumberFormatter::FRACTION_DIGITS, max( 0, $decimals ) );
			}
			$formatted = $fmt->format( (float) $value );
			if ( is_string( $formatted ) && $formatted !== '' ) {
				return $formatted;
			}
		}

		// Fallback: WP's built-in i18n number formatter respects the
		// thousands and decimal separators of the active site locale.
		return number_format_i18n( (float) $value, $decimals ?? 0 );
	}

	/**
	 * Locale-aware currency formatting. Same back-end as format_number
	 * but routes through NumberFormatter's CURRENCY style so the symbol,
	 * symbol position, and fraction digits match the locale's
	 * conventions for the supplied ISO 4217 code.
	 *
	 *   perflocale()->format_currency( 19.99, 'USD' );         // "$19.99"
	 *   perflocale()->format_currency( 19.99, 'EUR', 'de' );   // "19,99 €"
	 *   perflocale()->format_currency( 1234, 'JPY' );          // "¥1,234" (no decimals)
	 *
	 * When intl is unavailable, falls back to "{code} {number}" with
	 * a conservative 2-decimal default. Callers that need a guaranteed
	 * native format should require intl in their plugin's composer.json.
	 *
	 * @param float|int   $value         Amount to format.
	 * @param string      $currency_code ISO 4217 code (USD, EUR, JPY, …).
	 * @param string|null $lang_slug     Optional language slug; defaults to current.
	 * @return string Formatted amount with currency symbol.
	 */
	public function format_currency( $value, string $currency_code, ?string $lang_slug = null ): string {
		$locale = $this->resolve_locale_for_format( $lang_slug );
		$code   = strtoupper( trim( $currency_code ) );

		if ( $code === '' ) {
			return (string) $value;
		}

		if ( class_exists( '\\NumberFormatter' ) ) {
			$fmt       = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
			$formatted = $fmt->formatCurrency( (float) $value, $code );
			if ( is_string( $formatted ) && $formatted !== '' ) {
				return $formatted;
			}
		}

		// Conservative fallback when intl isn't loaded — code + amount,
		// no symbol guessing (we'd be wrong as often as right).
		return $code . ' ' . number_format_i18n( (float) $value, 2 );
	}

	/**
	 * Resolve a NumberFormatter-friendly locale string from a language
	 * slug, the current language, or get_locale() in that order.
	 *
	 * @param string|null $lang_slug
	 * @return string
	 */
	private function resolve_locale_for_format( ?string $lang_slug ): string {
		if ( $lang_slug !== null && $lang_slug !== '' ) {
			try {
				$plugin = Plugin::get_instance();
				if ( $plugin->has( 'lang_repo' ) ) {
					$lang = $plugin->get( 'lang_repo' )->find_by_slug( $lang_slug );
					if ( $lang && ! empty( $lang->locale ) ) {
						return (string) $lang->locale;
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through to current-language / get_locale().
			}
		}

		$current = $this->current_language();
		if ( $current && ! empty( $current->locale ) ) {
			return (string) $current->locale;
		}

		return get_locale();
	}

	/**
	 * Whether the current request is a REST API request — usable at BOOT time.
	 *
	 * The `REST_REQUEST` constant is only defined on `parse_request` (deep
	 * inside `rest_api_loaded()`), long AFTER plugins load and register their
	 * services. Service-boot gates that relied on `defined( 'REST_REQUEST' )`
	 * therefore evaluated to false for every REST request and silently failed
	 * to boot write-context services (content sync, change detection, the
	 * "do not translate" block filter, the webhook dispatcher, …) on Gutenberg
	 * saves and REST translation ops — is_admin() is also false during REST, so
	 * NO gate matched. This helper answers the question early by sniffing the
	 * request target, falling back to the constant once it exists.
	 *
	 * @return bool
	 */
	public static function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// The `?rest_route=` form works on any permalink structure and is set
		// before parse_request, so it's the most reliable early signal.
		if ( ! empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		if ( ! is_string( $path ) || $path === '' ) {
			return false;
		}

		// Pretty-permalink REST lives under the rest prefix (default wp-json),
		// possibly beneath a subdirectory install: /wp-json/… or /sub/wp-json/….
		// rest_get_url_prefix() is available this early (a plain filter on the
		// 'wp-json' default), so honour a customised prefix too.
		$prefix = trim( rest_get_url_prefix(), '/' );

		if ( $prefix === '' ) {
			return false;
		}

		return (bool) preg_match( '#(^|/)' . preg_quote( $prefix, '#' ) . '(/|$)#', $path );
	}

	/**
	 * Deep-slash a meta value, recursing into OBJECTS as well as arrays.
	 *
	 * wp_slash() recurses arrays but returns objects untouched, whereas
	 * add_post_meta()/update_post_meta() run wp_unslash() → stripslashes_deep()
	 * → map_deep(), which DOES recurse into objects and strips a backslash
	 * level from their string properties. So a serialized stdClass payload
	 * (Beaver Builder's `_fl_builder_data` / `_fl_builder_data_settings`) loses
	 * one level of slashing on every copy/mirror unless we pre-slash its object
	 * properties too. map_deep mirrors exactly what wp_unslash will later undo.
	 *
	 * @param mixed $value Meta value (string, array, or object graph).
	 * @return mixed Slashed value.
	 */
	public static function deep_slash( mixed $value ): mixed {
		return map_deep( $value, static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v );
	}

	/**
	 * Whether the current request is a WRITE / translate context — i.e. one
	 * where content can be created or edited and PerfLocale's write-hook
	 * services (content sync, change detection, the block "do not translate"
	 * filter, term-assignment filtering, the webhook dispatcher) must be
	 * booted. Deliberately EXCLUDES the hot frontend GET, so booting these
	 * services here adds no cost to visitor pageviews.
	 *
	 * The set mirrors the webhook dispatcher's long-standing gate. Two members
	 * are easy to miss and each caused a silent-no-op bug in the past:
	 *   - wp_doing_cron(): bulk-translate + scheduled-publish jobs run inside
	 *     the WP-Cron worker, where nothing else matches. Without it, a
	 *     translation created by a background job fired no sync / no webhook.
	 *   - is_rest_request(): Gutenberg saves and REST translation ops are REST
	 *     requests, where is_admin() is false and REST_REQUEST isn't defined
	 *     yet at boot. {@see self::is_rest_request()}.
	 *
	 * @return bool
	 */
	public static function is_write_context(): bool {
		return is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| self::is_rest_request()
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Whether outward-facing dispatches (webhooks) may fire from this
	 * environment.
	 *
	 * Staging / development clones of production sites carry the production
	 * webhook URLs in their cloned database. Without this
	 * gate, an editor testing on a managed-host staging clone (WP Engine,
	 * Kinsta, etc. set WP_ENVIRONMENT_TYPE=staging on clones) silently
	 * fires production webhooks from a staging clone.
	 *
	 * Fail-closed: anything other than 'production' blocks dispatch unless
	 * the site owner opts in via the filter. WP defaults the environment
	 * type to 'production' when unset, so ordinary single-environment
	 * sites are unaffected.
	 *
	 * @param string $feature Dispatch surface ('webhooks', …) —
	 *                        lets a filter callback allow one surface but
	 *                        not another.
	 * @return bool True when dispatch may proceed.
	 */
	public static function is_outbound_dispatch_allowed( string $feature ): bool {
		$environment = wp_get_environment_type();

		if ( $environment === 'production' ) {
			return true;
		}

		/**
		 * Allow outward-facing dispatches from non-production environments.
		 *
		 * Default false: staging/development clones do not fire webhooks.
		 * Return true to opt a surface (or every surface)
		 * back in — e.g. a dedicated QA environment that owns its own
		 * webhook endpoints.
		 *
		 * @hook perflocale/dispatch/allow_non_production
		 *
		 * @param bool   $allow       Default false.
		 * @param string $feature     Dispatch surface ('webhooks').
		 * @param string $environment Current environment type.
		 */
		if ( (bool) apply_filters( 'perflocale/dispatch/allow_non_production', false, $feature, $environment ) ) {
			return true;
		}

		/**
		 * Fires when an outward-facing dispatch is blocked by the
		 * non-production environment gate. Observability hook — lets a
		 * logger or admin notice surface that webhooks are intentionally
		 * muted on this clone.
		 *
		 * @hook perflocale/dispatch/blocked
		 *
		 * @param string $feature     Dispatch surface that was blocked.
		 * @param string $environment Current environment type.
		 */
		do_action( 'perflocale/dispatch/blocked', $feature, $environment );

		return false;
	}

	/**
	 * Default writing direction for a locale.
	 *
	 * Returns 'rtl' for right-to-left language subtags, else 'ltr'. Keyed on
	 * the primary subtag (so `ar`, `ar_EG`, `ckb-IR` all resolve), and kept in
	 * sync with the RTL entries in the bundled data/languages.php. Used as the
	 * default when a language is created without an explicit direction (REST,
	 * WP-CLI); the admin form pre-fills direction from the chosen preset.
	 *
	 * @param string $locale Locale string (`ar`, `he_IL`, `ckb-IR`, etc.).
	 * @return string 'rtl' or 'ltr'.
	 */
	public static function default_text_direction( string $locale ): string {
		$parts   = preg_split( '/[_\-]/', trim( $locale ), 2 );
		$primary = strtolower( (string) ( $parts[0] ?? '' ) );

		// RTL primary language subtags (mirrors data/languages.php RTL rows).
		static $rtl = [ 'ar', 'arc', 'bal', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi' ];

		return in_array( $primary, $rtl, true ) ? 'rtl' : 'ltr';
	}

	/**
	 * Convert a WordPress locale string into a canonical BCP 47 language
	 * tag for hreflang / Content-Language / sitemap / JSON-LD output.
	 *
	 * BCP 47 / RFC 5646 conventions (case-insensitive at match time, but
	 * the canonical form is what tools, validators, and SEO crawlers
	 * expect — and what every reference site emits):
	 *
	 *   - language subtag (2-3 letters):   lowercase  (`en`, `de`, `zh`)
	 *   - region subtag (2 letters):       UPPERCASE  (`US`, `GB`, `BR`)
	 *   - script subtag (4 letters):       Title-case (`Hans`, `Latn`)
	 *
	 * Examples:
	 *   `en_US`        → `en-US`
	 *   `pt_BR`        → `pt-BR`
	 *   `zh_Hans_CN`   → `zh-Hans-CN`
	 *   `ar`           → `ar`
	 *   `fr_FR`        → `fr-FR`  (NOT collapsed to `fr` — keeping the
	 *                              redundant region helps regional CDNs
	 *                              + matches Google's reference output)
	 *
	 * Also handles the variants WordPress and SEO plugins sometimes emit:
	 *   - already-hyphenated input (`en-us`, `EN-GB`)
	 *   - mixed separators (`en_us-US`)
	 *   - leading/trailing whitespace
	 *
	 * Anything obviously malformed (empty, only-separators, non-alpha
	 * subtags) returns the trimmed input lowercased — graceful degrade,
	 * no fatal.
	 *
	 * @internal This is an implementation detail consumed by the admin
	 *           Languages form and the SEO hreflang emitter. Despite
	 *           being public-static, it is NOT part of the @api surface
	 *           the rest of this class is bound by — signature and
	 *           behaviour may change between minor releases. Third-party
	 *           code that needs a BCP-47 string should normalise its
	 *           inputs and use WordPress' get_bloginfo('language')
	 *           directly.
	 *
	 * @param string $locale Raw locale string (`en_US`, `pt-BR`, `ar`, etc.).
	 * @return string Canonical BCP 47 tag.
	 */
	public static function format_locale_as_bcp47( string $locale ): string {
		// Pure deterministic transform of the raw arg; memo keyed on the raw
		// input. Key space is bounded by the site's handful of locale strings
		// (not post count), so no cap needed. Memoising cannot change output.
		static $memo = [];

		if ( isset( $memo[ $locale ] ) ) {
			return $memo[ $locale ];
		}

		$raw    = $locale;
		$locale = trim( $locale );

		if ( $locale === '' ) {
			return $memo[ $raw ] = '';
		}

		// Strip WordPress pseudo-locale MODIFIERS that are not IANA subtags:
		// the `_formal`/`_informal` politeness suffixes (de_DE_formal) and the
		// `@`-modifier form (ca@valencia, sr@latin) which glibc/WP use. A bare
		// `de-DE-formal` hreflang is invalid; `@valencia` should become a
		// variant subtag. Applied before separator normalisation.
		$locale = (string) preg_replace( '/_(?:formal|informal)$/i', '', $locale );
		if ( str_contains( $locale, '@' ) ) {
			// e.g. ca@valencia -> ca-valencia (variant), sr_RS@latin -> sr-RS-latin.
			$locale = str_replace( '@', '-', $locale );
		}

		// Normalise separators: any run of `_`, `-`, or whitespace becomes a
		// single hyphen. Catches both PHP-style underscores AND the
		// occasional pasted "en US" / "en _ US" hand-typed input.
		$tag = preg_replace( '/[_\-\s]+/', '-', $locale );

		if ( $tag === null || $tag === '' ) {
			return $memo[ $raw ] = strtolower( $locale );
		}

		$parts = array_values( array_filter( explode( '-', $tag ), static fn( $p ) => $p !== '' ) );

		if ( empty( $parts ) ) {
			return $memo[ $raw ] = strtolower( $locale );
		}

		$out = [];

		foreach ( $parts as $i => $part ) {
			$len = strlen( $part );

			// Bail out on non-alpha tokens — let them pass through
			// lowercased rather than try to "fix" something we don't
			// understand (e.g. private-use subtags `x-mywp1`).
			if ( ! ctype_alpha( $part ) ) {
				$out[] = strtolower( $part );
				continue;
			}

			if ( $i === 0 ) {
				// Language subtag: always lowercase.
				$out[] = strtolower( $part );
			} elseif ( $len === 4 ) {
				// Script subtag: title-case per BCP 47, e.g. Hans or Latn.
				$out[] = ucfirst( strtolower( $part ) );
			} elseif ( $len === 2 || $len === 3 ) {
				// Region subtag: UPPERCASE.
				$out[] = strtoupper( $part );
			} else {
				// Variant or extension subtag: lowercase per BCP 47.
				$out[] = strtolower( $part );
			}
		}

		return $memo[ $raw ] = implode( '-', $out );
	}

	/**
	 * Suggest a region-qualified slug for a bare-language slug, derived
	 * from the language's WordPress locale.
	 *
	 * Used by the "rename your default" UX nudge: a user with a `en` default
	 * who adds `en-gb` will see a friendlier UI if they rename `en` → `en-us`
	 * for visual symmetry. The suggestion only fires when:
	 *   1. The slug has no region subtag (no hyphen), AND
	 *   2. The locale carries one (`xx_YY`), AND
	 *   3. The implied slug differs from the current one.
	 *
	 * Returns an empty string when no upgrade is meaningful, so callers
	 * can simply check `if ( $suggestion !== '' )`.
	 *
	 * @internal Admin-form helper for the Languages page. Not part of
	 *           the @api surface — signature and heuristics may change.
	 *
	 * @param object $language Language row (`->slug`, `->locale`).
	 * @return string Suggested slug, e.g. `en-us`, or empty string.
	 */
	public static function suggest_region_qualified_slug( object $language ): string {
		$slug   = strtolower( (string) ( $language->slug ?? '' ) );
		$locale = (string) ( $language->locale ?? '' );
		$flag   = strtolower( (string) ( $language->flag ?? '' ) );

		if ( $slug === '' || strpos( $slug, '-' ) !== false ) {
			return '';
		}

		// Primary: derive region from the WP locale (`en_US` → `us`).
		$region = '';
		$parts  = explode( '_', $locale );

		if ( count( $parts ) >= 2 && ctype_alpha( $parts[1] ) ) {
			$region = strtolower( $parts[1] );
		}

		// Fallback: use the language's flag/country-code field. WordPress
		// locales like `ar` (Arabic) carry no region in the locale string
		// itself, but the language row's `flag` column carries the country
		// in one of two formats: a 2-letter ISO code (`sa`) OR a regional-
		// indicator emoji pair (`🇸🇦`). Both forms are seeded by different
		// code paths (data/languages.php uses ISO codes; the flag-emoji
		// renderer stores the emoji form). Accept both so the suggester
		// doesn't refuse to nudge despite the data being right there.
		if ( $region === '' && $flag !== '' ) {
			if ( ctype_alpha( $flag ) && strlen( $flag ) === 2 ) {
				$region = $flag;
			} else {
				$decoded = self::region_code_from_flag_emoji( $flag );

				if ( $decoded !== '' ) {
					$region = $decoded;
				}
			}
		}

		if ( $region === '' ) {
			return '';
		}

		$suggested = $slug . '-' . $region;

		return $suggested === $slug ? '' : $suggested;
	}

	/**
	 * Detect every active bare-language slug that **shares its language
	 * family** with at least one other active region-qualified slug.
	 *
	 * Family = the language part of a slug (everything before the first
	 * `-`). So `en` and `en-GB` share family `en`; `en` and `de-DE` do
	 * NOT — they're different languages. Cross-family matches were the
	 * source of false-positive nudges (a site running just `de`, `fr`,
	 * `es` was being told to rename them all to `de-DE`, `fr-FR`, etc.,
	 * even when those single-variant choices were intentional and there
	 * was no actual visual asymmetry).
	 *
	 * Concrete behaviour:
	 *   - `en` + `en-GB` active → flag `en` (suggest `en-US`)
	 *   - `en-US` + `ar` (no `ar-XX`) → flag NOTHING (ar stands alone)
	 *   - `de`, `fr`, `es` (all bare, no region siblings) → flag NOTHING
	 *   - `ar` + `ar-MA` → flag `ar` (suggest `ar-SA`)
	 *
	 * @internal Admin-form helper for the Languages page rename-nudge UX.
	 *           Not part of the @api surface.
	 *
	 * @param array<int, object> $languages All active languages.
	 * @return array<int, array{language: object, suggested: string}>
	 */
	public static function detect_bare_languages_with_region_siblings( array $languages ): array {
		// Group active languages by family (language subtag). A bare
		// `en` and `en-GB` belong to family `en`; we only flag `en`
		// when its family has at least one other region-qualified
		// member.
		$families = [];

		foreach ( $languages as $lang ) {
			$slug = strtolower( (string) ( $lang->slug ?? '' ) );

			if ( $slug === '' ) {
				continue;
			}

			$dash   = strpos( $slug, '-' );
			$family = $dash === false ? $slug : substr( $slug, 0, $dash );

			$families[ $family ][] = $lang;
		}

		$out = [];

		foreach ( $families as $family => $members ) {
			$bare           = null;
			$has_region_var = false;

			foreach ( $members as $m ) {
				$slug = strtolower( (string) $m->slug );

				if ( $slug === $family ) {
					$bare = $m;
				} elseif ( strpos( $slug, '-' ) !== false ) {
					$has_region_var = true;
				}
			}

			if ( $bare === null || ! $has_region_var ) {
				// Either no bare member (`en-US` + `en-GB` is fine — no
				// asymmetry), OR no region member yet (`de` standing
				// alone is intentional, leave it alone).
				continue;
			}

			$suggested = self::suggest_region_qualified_slug( $bare );

			if ( $suggested === '' ) {
				continue; // locale doesn't expose a region target
			}

			$out[] = [
				'language'  => $bare,
				'suggested' => $suggested,
			];
		}

		return $out;
	}

	/**
	 * Bare-slug rename CANDIDATES for the Add-Language form.
	 *
	 * Same shape as {@see detect_bare_languages_with_region_siblings()},
	 * but DROPS the "has region-qualified sibling" requirement. At form-
	 * render time the user is about to add a region-qualified sibling
	 * — that's the action that will create the imbalance. Surfacing the
	 * rename checkbox only when the imbalance already exists makes the
	 * nudge fire one beat too late (after the user has already submitted).
	 *
	 * The form's JS reveals the relevant candidate (matched by slug
	 * prefix to what the user is typing); the rest stay hidden.
	 *
	 * @internal Admin-form helper for the Languages page. Not part of the
	 *           @api surface.
	 *
	 * @param array<int, object> $languages All active languages.
	 * @return array<int, array{language: object, suggested: string}>
	 */
	public static function bare_language_rename_candidates_for_form( array $languages ): array {
		$out = [];

		foreach ( $languages as $lang ) {
			$slug = strtolower( (string) ( $lang->slug ?? '' ) );

			if ( $slug === '' || strpos( $slug, '-' ) !== false ) {
				continue;
			}

			$suggested = self::suggest_region_qualified_slug( $lang );

			if ( $suggested === '' ) {
				continue;
			}

			$out[] = [
				'language'  => $lang,
				'suggested' => $suggested,
			];
		}

		return $out;
	}

	/**
	 * Get the emoji flag for a language object.
	 *
	 * Tries the flag field first, then derives from locale or slug.
	 *
	 * @param object $lang Language object with flag, locale, slug properties.
	 * @return string Emoji flag.
	 */
	public static function get_flag_emoji( object $lang ): string {
		// Use explicit flag field if set.
		$code = trim( $lang->flag ?? '' );

		// Fall back: derive country code from locale (fr_FR → FR).
		if ( $code === '' && ! empty( $lang->locale ) ) {
			$parts = explode( '_', $lang->locale );

			if ( isset( $parts[1] ) && strlen( $parts[1] ) === 2 ) {
				$code = $parts[1];
			}
		}

		// Fall back: use the slug if it's 2 letters.
		if ( $code === '' && strlen( $lang->slug ?? '' ) === 2 ) {
			$code = $lang->slug;
		}

		if ( $code === '' ) {
			return '';
		}

		return self::country_code_to_emoji( $code );
	}

	/**
	 * Convert a 2-letter country code to its Unicode emoji flag.
	 *
	 * Each letter is mapped to a Regional Indicator Symbol (U+1F1E6–U+1F1FF).
	 * For example: "us" → "🇺🇸", "fr" → "🇫🇷", "de" → "🇩🇪".
	 *
	 * @param string $code 2-letter ISO 3166-1 alpha-2 country code.
	 * @return string Emoji flag, or the original code if invalid.
	 */
	public static function country_code_to_emoji( string $code ): string {
		$code = strtoupper( trim( $code ) );

		if ( strlen( $code ) !== 2 || ! ctype_alpha( $code ) ) {
			return $code;
		}

		// Regional Indicator Symbols U+1F1E6–U+1F1FF form a contiguous 4-byte
		// UTF-8 block whose leading three bytes are constant (F0 9F 87); only
		// the trailing continuation byte varies. Encoding the pair directly
		// avoids a hard dependency on mb_chr() — which has no WP core polyfill,
		// so a host built without mbstring would fatal here — and never depends
		// on mb_internal_encoding().
		$first  = "\xF0\x9F\x87" . chr( 0xA6 + ord( $code[0] ) - ord( 'A' ) );
		$second = "\xF0\x9F\x87" . chr( 0xA6 + ord( $code[1] ) - ord( 'A' ) );

		return $first . $second;
	}

	/**
	 * Inverse of {@see country_code_to_emoji()}. Decodes a regional-indicator
	 * emoji pair (e.g. `🇸🇦`) back into a lowercase ISO 3166-1 alpha-2 code
	 * (`sa`). Returns empty string if the input is not exactly two regional-
	 * indicator codepoints in the U+1F1E6..U+1F1FF range.
	 *
	 * Used by {@see suggest_region_qualified_slug()} so the bare-default
	 * detector can still derive a rename target when the language row stores
	 * its flag as the rendered emoji rather than a 2-letter code.
	 *
	 * @param string $emoji Two regional-indicator codepoints.
	 * @return string Lowercase 2-letter region code, or '' on no match.
	 */
	public static function region_code_from_flag_emoji( string $emoji ): string {
		// Byte-level decode, the exact inverse of country_code_to_emoji()
		// above. Regional Indicator Symbols U+1F1E6-U+1F1FF are always 4-byte
		// UTF-8 sharing the constant prefix F0 9F 87, with only the trailing
		// continuation byte varying across A6..BF — so a valid pair is exactly
		// 8 bytes and needs no multibyte functions.
		//
		// Deliberately avoids mb_ord()/mb_strlen()/mb_substr(): mb_ord() is
		// polyfilled by WordPress only from 7.1 (wp-includes/compat.php) while
		// this plugin supports 6.4+, and the whole family is absent on a PHP
		// build without ext-mbstring. Dropping them also removes the old
		// function_exists() early return, so the region suggester now works on
		// mbstring-less hosts instead of silently returning nothing.
		if ( strlen( $emoji ) !== 8 ) {
			return '';
		}

		$prefix = "\xF0\x9F\x87";

		if ( substr( $emoji, 0, 3 ) !== $prefix || substr( $emoji, 4, 3 ) !== $prefix ) {
			return '';
		}

		$b1 = ord( $emoji[3] );
		$b2 = ord( $emoji[7] );

		// Trailing byte A6..BF maps to A..Z; anything else is not a regional
		// indicator pair.
		if ( $b1 < 0xA6 || $b1 > 0xBF || $b2 < 0xA6 || $b2 > 0xBF ) {
			return '';
		}

		return chr( ord( 'a' ) + ( $b1 - 0xA6 ) ) . chr( ord( 'a' ) + ( $b2 - 0xA6 ) );
	}

	/**
	 * Get the router instance.
	 *
	 * @return Router\LanguageRouter|null
	 */
	private function router(): ?Router\LanguageRouter {
		$plugin = Plugin::get_instance();

		return $plugin->has( 'router' ) ? $plugin->get( 'router' ) : null;
	}

	/**
	 * Like {@see router()} but swallows ANY exception from the DI lookup.
	 * Use from getters that themes / addons might call before our
	 * bootstrap finishes (mu-plugins, plugins_loaded priority 1, etc.).
	 * Returns null on the same set of conditions plus the throw cases:
	 *   - Plugin::get_instance() unreachable
	 *   - Container has no 'router' factory
	 *   - Factory closure threw during instantiation
	 *
	 * @return Router\LanguageRouter|null
	 */
	private function safe_router(): ?Router\LanguageRouter {
		try {
			return $this->router();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Get an initialized WP_Filesystem instance.
	 *
	 * Handles require, initialization, and type validation in one place.
	 * Returns null on failure so callers can handle gracefully.
	 *
	 * @return \WP_Filesystem_Base|null Filesystem instance or null on failure.
	 */
	public static function filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return null;
		}

		// $wp_filesystem is declared global mixed by WP core; assert the
		// expected class so the instanceof check is meaningful to static
		// analysis as well as runtime.
		/** @var \WP_Filesystem_Base|mixed $wp_filesystem */
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Absolute path to the plugin's container directory under
	 * `wp-content/uploads/perflocale/`. Honors `wp_upload_dir()` so the path
	 * is per-blog on multisite (subsites land under `uploads/sites/<id>/`).
	 *
	 * Single source of truth — callers should always go through this helper
	 * (and the `uploads_temp_dir` / `uploads_exports_dir` /
	 * `uploads_translations_dir` siblings) rather than hard-coding paths.
	 *
	 * @return string Absolute path WITHOUT trailing slash.
	 */
	public static function uploads_base_dir(): string {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		return $base === '' ? '' : trailingslashit( $base ) . 'perflocale';
	}

	/**
	 * Translations output dir — `uploads/perflocale/translations/`. The
	 * generator writes `.mo`/`.l10n.php` files here for built-in plugin
	 * strings.
	 *
	 * @return string
	 */
	public static function uploads_translations_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/translations';
	}

	/**
	 * Scratch dir for in-flight imports — `uploads/perflocale/temp/`.
	 * Files staged here survive request teardown until the async worker
	 * picks them up; backed by a 24h delayed `perflocale_cleanup_temp_import`
	 * event so abandoned uploads don't accumulate.
	 *
	 * @return string
	 */
	public static function uploads_temp_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/temp';
	}

	/**
	 * Export bundle dir — `uploads/perflocale/exports/`. Single-use:
	 * `process_export_download()` unlinks the file after streaming.
	 *
	 * @return string
	 */
	public static function uploads_exports_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/exports';
	}

	/**
	 * Daily safety-net sweep for abandoned temp / export files. Each upload
	 * path schedules its own delete event (24h for imports, single-use for
	 * exports) but if cron is disabled, the scheduled event is lost, or the
	 * import handler crashes before queuing the cleanup, the file lingers
	 * forever. This sweep catches anything older than `$max_age_days` in
	 * the temp + exports dirs as a backstop. Called from the daily
	 * perflocale_jobs_gc cron handler.
	 *
	 * Skips the .htaccess + index.php hardening files (they shouldn't be
	 * old — they're refreshed by harden_directory() — but defensively
	 * named-skip them so a no-op call here can't accidentally delete the
	 * directory's defense-in-depth files).
	 *
	 * @param int $max_age_days Files older than this (default: 7) are removed.
	 * @return int Number of files deleted.
	 */
	public static function gc_stale_upload_files( int $max_age_days = 7 ): int {
		$cutoff  = time() - ( $max_age_days * DAY_IN_SECONDS );
		$dirs    = [ self::uploads_temp_dir(), self::uploads_exports_dir() ];
		$skip    = [ '.htaccess', 'index.php', 'web.config' ];
		$removed = 0;

		foreach ( $dirs as $dir ) {
			if ( $dir === '' || ! is_dir( $dir ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @ suppresses the warning when the dir is unreadable mid-sweep; the === false check on the next line skips that dir.
			$dh = @opendir( $dir );
			if ( $dh === false ) {
				continue;
			}
			while ( ( $entry = readdir( $dh ) ) !== false ) {
				if ( $entry === '.' || $entry === '..' || in_array( $entry, $skip, true ) ) {
					continue;
				}
				$path = trailingslashit( $dir ) . $entry;
				if ( ! is_file( $path ) ) {
					continue;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @ silences the stat warning when a file disappears between readdir() and this call; the === false check below skips it.
				$mtime = @filemtime( $path );
				if ( $mtime === false || $mtime > $cutoff ) {
					continue;
				}
				if ( wp_delete_file( $path ) || ! file_exists( $path ) ) {
					++$removed;
				}
			}
			closedir( $dh );
		}

		return $removed;
	}

	/**
	 * Ensure the plugin's base uploads dir exists and is hardened. Called
	 * by the activator on every (re-)activation. The subdirs (temp /
	 * exports / translations) come into existence lazily when their
	 * consumers first need them; each consumer calls `harden_directory()`
	 * after `wp_mkdir_p()` itself.
	 *
	 * @return void
	 */
	public static function ensure_uploads_base_dir(): void {
		$base = self::uploads_base_dir();
		if ( $base === '' ) {
			return;
		}
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		self::harden_directory( $base );
	}

	/**
	 * Write defense-in-depth protection files into a plugin-owned directory.
	 *
	 * Two files (idempotent — won't overwrite existing):
	 *   - `.htaccess` with `Deny from all` for Apache hosts
	 *   - `index.php` "silence is golden" against `Options +Indexes` autoindex
	 *
	 * This matches WordPress core's `wp-content/plugins/index.php` convention
	 * and WooCommerce's `woocommerce_uploads/` pattern (`class-wc-install.php`
	 * `create_files()`). Nginx hosts: `.htaccess` is ignored at the server
	 * layer, but `autoindex off` (Nginx default) blocks directory listings
	 * anyway, and the random filename tokens we use in temp/exports filenames
	 * (62^16 ≈ 5×10^28) provide the real access control.
	 *
	 * IIS / `web.config`: deliberately NOT written. The WP plugin convention
	 * is `.htaccess` + `index.*` only; IIS is a vanishing minority of WP
	 * hosts and operators on those hosts rely on server-level config (which
	 * WP core writes for them via `iis7_save_url_rewrite_rules` for
	 * permalinks). Adding our own web.config would diverge from convention
	 * for marginal benefit.
	 *
	 * Cheap to call on every directory creation: each file is written once
	 * (existence check short-circuits subsequent calls) so the cost is the
	 * two `exists` probes — negligible compared to the work the caller
	 * is doing.
	 *
	 * @param string $dir Absolute filesystem path to an existing directory.
	 * @return void
	 */
	public static function harden_directory( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}

		$fs = self::filesystem();
		if ( ! $fs instanceof \WP_Filesystem_Base ) {
			return;
		}

		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! $fs->exists( $htaccess ) ) {
			$fs->put_contents( $htaccess, "Deny from all\n", $mode );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! $fs->exists( $index ) ) {
			$fs->put_contents( $index, "<?php\n// Silence is golden.\n", $mode );
		}
	}

	/**
	 * Date format for the current language, with site-default fallback.
	 *
	 * Fluent-API instance wrapper around {@see self::date_format_for_language()}.
	 * Use this in templates where you'd otherwise call `get_option('date_format')`:
	 *
	 *   echo wp_date( perflocale()->date_format(), $timestamp );
	 *
	 * @return string
	 */
	public function date_format(): string {
		return self::date_format_for_language( $this->current_language() );
	}

	/**
	 * Time format for the current language. See {@see self::date_format()}.
	 *
	 * @return string
	 */
	public function time_format(): string {
		return self::time_format_for_language( $this->current_language() );
	}

	/**
	 * Combined date+time format for the current language.
	 *
	 * @return string
	 */
	public function datetime_format(): string {
		return self::datetime_format_for_language( $this->current_language() );
	}

	/**
	 * Render a timestamp using the current language's date format.
	 *
	 * Wraps `wp_date()` so the result is locale-translated (month/day names
	 * etc.) and formatted per the active language. Pass a Unix timestamp or
	 * a `strtotime()`-parseable string.
	 *
	 * @param int|string|null $timestamp Unix timestamp, parseable string, or null for now.
	 * @return string Formatted date, or empty string on parse failure.
	 */
	public function format_date( $timestamp = null ): string {
		$ts = $this->normalize_timestamp( $timestamp );
		if ( $ts === null ) {
			return '';
		}
		return (string) wp_date( $this->date_format(), $ts );
	}

	/**
	 * Render a timestamp using the current language's time format.
	 *
	 * @param int|string|null $timestamp
	 * @return string
	 */
	public function format_time( $timestamp = null ): string {
		$ts = $this->normalize_timestamp( $timestamp );
		if ( $ts === null ) {
			return '';
		}
		return (string) wp_date( $this->time_format(), $ts );
	}

	/**
	 * Render a timestamp using the current language's combined datetime format.
	 *
	 * @param int|string|null $timestamp
	 * @return string
	 */
	public function format_datetime( $timestamp = null ): string {
		$ts = $this->normalize_timestamp( $timestamp );
		if ( $ts === null ) {
			return '';
		}
		return (string) wp_date( $this->datetime_format(), $ts );
	}

	/**
	 * Coerce a caller-supplied timestamp into a Unix int, or null on failure.
	 *
	 * @param int|string|null $timestamp
	 * @return int|null
	 */
	private function normalize_timestamp( $timestamp ): ?int {
		if ( $timestamp === null || $timestamp === '' ) {
			return time();
		}
		if ( is_int( $timestamp ) ) {
			return $timestamp;
		}
		if ( is_numeric( $timestamp ) ) {
			return (int) $timestamp;
		}
		$parsed = strtotime( (string) $timestamp );
		return $parsed === false ? null : $parsed;
	}

	/**
	 * Resolve the date format for a given language with sensible fallbacks.
	 *
	 * Lookup order:
	 *   1. The language row's `date_format` column (admin override).
	 *   2. The site's global `get_option( 'date_format' )` (WP default).
	 *
	 * Pass `null` (the default) to use the current language. The result
	 * is always a non-empty string suitable for `wp_date()` /
	 * `date_i18n()`.
	 *
	 * Filterable via `perflocale/date_format` so addons can inject
	 * locale-specific overrides without storing them in the DB:
	 *
	 *   add_filter( 'perflocale/date_format', function ( $fmt, $lang ) {
	 *       return $lang && $lang->slug === 'ja' ? 'Y年n月j日' : $fmt;
	 *   }, 10, 2 );
	 *
	 * @param object|int|string|null $lang Language row, ID, slug, or null
	 *     for the current language.
	 * @return string A `wp_date()`-compatible format string.
	 */
	public static function date_format_for_language( $lang = null ): string {
		$resolved = self::resolve_language_arg( $lang );
		$override = $resolved && ! empty( $resolved->date_format ) ? (string) $resolved->date_format : '';
		$format   = $override !== '' ? $override : (string) get_option( 'date_format', 'F j, Y' );

		/**
		 * Filter the resolved date format for a language.
		 *
		 * @hook perflocale/date_format
		 * @param string      $format The format string about to be returned.
		 * @param object|null $lang   The language row, or null if none was found.
		 */
		return (string) apply_filters( 'perflocale/date_format', $format, $resolved );
	}

	/**
	 * Resolve the time format for a given language. Same lookup +
	 * fallback semantics as {@see self::date_format_for_language()}.
	 *
	 * @param object|int|string|null $lang
	 * @return string
	 */
	public static function time_format_for_language( $lang = null ): string {
		$resolved = self::resolve_language_arg( $lang );
		$override = $resolved && ! empty( $resolved->time_format ) ? (string) $resolved->time_format : '';
		$format   = $override !== '' ? $override : (string) get_option( 'time_format', 'g:i a' );

		/**
		 * Filter the resolved time format for a language.
		 *
		 * @hook perflocale/time_format
		 * @param string      $format The format string about to be returned.
		 * @param object|null $lang   The language row, or null if none was found.
		 */
		return (string) apply_filters( 'perflocale/time_format', $format, $resolved );
	}

	/**
	 * Convenience: full datetime format = date_format + ' ' + time_format
	 * resolved for the same language. Mirrors what WP-admin shows on
	 * post lists, comments, etc.
	 *
	 * @param object|int|string|null $lang
	 * @return string
	 */
	public static function datetime_format_for_language( $lang = null ): string {
		return trim( self::date_format_for_language( $lang ) . ' ' . self::time_format_for_language( $lang ) );
	}

	/**
	 * Internal: turn whatever the caller passed (object / id / slug / null)
	 * into a language row. Used by the format helpers above.
	 *
	 * @param object|int|string|null $lang
	 * @return object|null
	 */
	private static function resolve_language_arg( $lang ): ?object {
		if ( is_object( $lang ) ) {
			return $lang;
		}

		try {
			$plugin = \PerfLocale\Plugin::get_instance();
			$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;
			if ( ! $cache ) {
				return null;
			}
			$repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );

			if ( $lang === null || $lang === '' ) {
				$current = self::get_instance()->current_language();
				return $current ?: $repo->get_default();
			}

			if ( is_int( $lang ) ) {
				return $repo->find( $lang );
			}

			if ( is_string( $lang ) ) {
				return $repo->find_by_slug( $lang );
			}
		} catch ( \Throwable $e ) {
			// Bootstrap not ready — fall through to global defaults.
			unset( $e );
		}

		return null;
	}

	/**
	 * Load an addon's `.mo` file the WordPress 6.7+-friendly way.
	 *
	 * `load_plugin_textdomain()` has been effectively deprecated since
	 * WordPress 4.6 — Core's just-in-time loader resolves wp.org-hosted
	 * `.mo` files on the first `__()` call without any plugin opt-in. On
	 * 6.7+ calling `load_plugin_textdomain()` (or `load_textdomain()`)
	 * before the `init` action triggers a `_doing_it_wrong` notice.
	 *
	 * For external/non-wp.org addons that ship their own `.mo` files we
	 * still need to register them explicitly. The right place is `init`
	 * at priority 99 — late enough that the user's locale is finalised,
	 * but early enough to win over PHP code that calls `__()` shortly
	 * after.
	 *
	 * Usage (call from your addon's boot() method):
	 *
	 *   add_action( 'init', static function () {
	 *       Helper::load_addon_textdomain(
	 *           'my-addon',
	 *           plugin_dir_path( __FILE__ ) . 'languages'
	 *       );
	 *   }, 99 );
	 *
	 * For wp.org-hosted addons: don't call this at all — Core's
	 * just-in-time loader has been doing it for you since WP 4.6.
	 *
	 * @param string $domain Text domain matching the addon's `Text Domain` header.
	 * @param string $mo_dir Absolute path to the directory containing `.mo` files.
	 *                       File naming convention: `{domain}-{locale}.mo`.
	 * @param string $locale Optional explicit locale; defaults to determine_locale().
	 * @return bool True if a `.mo` file was located and loaded.
	 *
	 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
	 */
	public static function load_addon_textdomain( string $domain, string $mo_dir, string $locale = '' ): bool {
		// Calling load_textdomain() before `init` triggers WP 6.7+'s own
		// `_doing_it_wrong` for early-loaded translations. Surface the same
		// nudge here so the addon author sees WHICH addon caused it, not
		// the generic core message.
		if ( ! did_action( 'init' ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the addon textdomain. */
						__( 'Helper::load_addon_textdomain( "%s", ... ) was called before the init action fired. Wrap your call in add_action( "init", ..., 99 ) so WP 6.7+ does not strip the textdomain.', 'perflocale' ),
						$domain
					)
				),
				'1.0.0'
			);
		}

		$domain = trim( $domain );

		if ( $domain === '' || $mo_dir === '' ) {
			return false;
		}

		if ( $locale === '' ) {
			// `determine_locale()` ships in WP 5.0+; the plugin already
			// requires 6.2 (composer.json + readme.txt), so this is safe.
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		}

		$mofile = trailingslashit( $mo_dir ) . $domain . '-' . $locale . '.mo';

		if ( ! is_readable( $mofile ) ) {
			return false;
		}

		return load_textdomain( $domain, $mofile, $locale );
	}

	/**
	 * Register the shipped `-rtl.css` twins for every plugin stylesheet on
	 * RTL locales.
	 *
	 * One late hook instead of a wp_style_add_data() call at each of the
	 * dozen registration sites: iterate the registered styles, and for
	 * every `perflocale*` handle whose `-rtl.css` twin exists on disk, set
	 * the core `rtl => replace` extra — WP then swaps `.css` for
	 * `-rtl.css` at print time. Covers the visual-editor addon's handles
	 * too (same prefix, same URL→path mapping).
	 *
	 * Two independent notions of "this page is RTL" meet here and they can
	 * legitimately disagree. Core's `is_rtl()` reads `$wp_locale`, which only
	 * learns a locale is RTL from the *core language pack*
	 * (`_x( 'ltr', 'text direction' )` comes out of the default textdomain).
	 * PerfLocale's own `text_direction` column needs no pack, and is what
	 * `HreflangManager::filter_language_attributes()` reads to put `dir="rtl"`
	 * on `<html>` — whenever the `seo_og_locale` setting is on, which is its
	 * default. A site that adds Persian as a translation language without
	 * installing the `fa_IR` core pack — which nothing in this plugin does
	 * for you — therefore serves an RTL document
	 * dressed in the LTR stylesheets, because both halves of core's swap
	 * (this `rtl` extra AND `WP_Styles::do_item()`'s own
	 * `'rtl' === $this->text_direction` gate) hang off that same `is_rtl()`.
	 * `$force` is that second case: front end only, and handled by pointing
	 * our own handles straight at the twin rather than asking core to. It keys
	 * on the column alone, so on a site that turns `seo_og_locale` OFF the
	 * plugin's own sheets still go RTL while the document carries no `dir` at
	 * all. That asymmetry is deliberate: the content is RTL either way, and
	 * coupling a cosmetic decision about our own handles to an SEO setting
	 * would put a settings-service lookup on this path for no correctness gain.
	 *
	 * The LTR fast path is no longer free, so do not describe it as such: the
	 * `$force` probe is one `Helper::is_rtl()`, i.e. a memoised current-language
	 * lookup with no query behind it. Measured on real front-end requests to
	 * this plugin's own test site it is the FIRST such lookup of the request —
	 * nothing warms the memo earlier — and the whole hook costs ~0.02 ms and
	 * zero additional queries. Repeat calls in the same request are ~0.00006 ms.
	 *
	 * @return void
	 */
	public static function register_rtl_styles(): void {
		$core_swaps = is_rtl();
		$force      = false;

		if ( ! $core_swaps && ! is_admin() ) {
			// Front end only. In wp-admin the interface direction is core's
			// call: determine_locale() prefers the *user's* profile locale,
			// which is routinely LTR on a site whose default content language
			// is RTL — flipping the plugin's admin CSS there would be wrong.
			// On the front end the current language IS the document language,
			// and the plugin is already the thing asserting its direction.
			$force = self::get_instance()->is_rtl();
		}

		if ( ! $core_swaps && ! $force ) {
			return;
		}

		$styles = wp_styles();

		// Loop-invariant: compute the scheme-relative content URL once. We map
		// each stylesheet URL back to its on-disk path to test for the twin,
		// comparing scheme-relative so an http/https difference between the
		// enqueued src and content_url() (proxied / mixed-TLS configs) doesn't
		// defeat the match. On CDN or domain-mapped setups where the host itself
		// differs there is no reliable URL→path mapping, so the match fails and
		// we skip: the LTR stylesheet still loads, only the RTL swap is skipped
		// — a safe, cosmetic-only degradation.
		$content_rel = (string) preg_replace( '#^https?:#', '', content_url() );

		// Our own stylesheets are all registered from PERFLOCALE_URL, so map
		// them back through the plugin_dir_url()/plugin_dir_path() pair. That
		// stays correct even when the plugins directory is relocated outside
		// wp-content, which the content mapping below cannot handle; the
		// content branch remains for sibling addon plugins sharing the prefix.
		$plugin_rel = (string) preg_replace( '#^https?:#', '', PERFLOCALE_URL );

		if ( $content_rel === '' && $plugin_rel === '' ) {
			return;
		}

		foreach ( $styles->registered as $handle => $style ) {
			if ( strpos( (string) $handle, 'perflocale' ) !== 0 || isset( $style->extra['rtl'] ) ) {
				continue;
			}

			$src = (string) strtok( (string) $style->src, '?' );

			if ( ! str_ends_with( $src, '.css' ) || str_ends_with( $src, '-rtl.css' ) ) {
				continue;
			}

			$src_rel = (string) preg_replace( '#^https?:#', '', $src );

			// Only claim the twin when it actually shipped — a 'replace' entry
			// without the file 404s the whole stylesheet on RTL.
			if ( $plugin_rel !== '' && str_starts_with( $src_rel, $plugin_rel ) ) {
				$path = PERFLOCALE_DIR . substr( $src_rel, strlen( $plugin_rel ) );
			} elseif ( $content_rel !== '' && str_starts_with( $src_rel, $content_rel ) ) {
				$path = WP_CONTENT_DIR . substr( $src_rel, strlen( $content_rel ) );
			} else {
				continue;
			}

			if ( ! is_file( substr( $path, 0, -4 ) . '-rtl.css' ) ) {
				continue;
			}

			if ( $core_swaps ) {
				$styles->add_data( $handle, 'rtl', 'replace' );
				continue;
			}

			// core's swap is off for this request, so the `rtl` extra alone
			// would print nothing different. Rewrite the source instead —
			// scoped to `perflocale*` handles, so core's and the theme's
			// stylesheets keep whatever direction WordPress chose for them.
			// The query string is carried over verbatim: it is where the
			// cache-buster lives on handles registered with one.
			$style->src = substr( $src, 0, -4 ) . '-rtl.css' . substr( (string) $style->src, strlen( $src ) );
		}
	}
}
