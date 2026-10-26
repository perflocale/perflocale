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
 * Fluent developer API, reached through perflocale().
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class Helper {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Per-request memo of current_language(); reset by reset_static_caches().
	 *
	 * Null = not asked yet, false = asked and there is no current language.
	 *
	 * @var object|false|null
	 */
	private static $current_language_memo = null;

	/**
	 * Post meta flag ('yes') that removes a translation from hreflang and sitemap alternates.
	 *
	 * @var string
	 */
	public const SEO_EXCLUDE_META = '_perflocale_seo_exclude';

	/**
	 * Per-blog memo of the SEO-excluded post id set, keyed by blog id.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $seo_excluded_memo = [];

	/**
	 * Per-request memo of a locale's [ decimal_point, thousands_sep ], or null without WP_Locale data.
	 *
	 * Not blog-keyed: the separators come from language files a network shares.
	 *
	 * @var array<string, array{0: string, 1: string}|null>
	 */
	private static array $number_separator_memo = [];

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
	 * Drop the blog-specific static memos; called by the Bootstrap switch_blog reset.
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
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_seo_excluded( int $post_id ): bool {
		return $post_id > 0 && isset( self::seo_excluded_post_ids()[ $post_id ] );
	}

	/**
	 * The set of post IDs carrying the SEO-exclude flag, as id => true.
	 *
	 * One query per blog per request: sitemaps do not meta-prime siblings, so get_post_meta() would query each.
	 * Same-request writes are not seen; the flag changes only on admin saves, which render no hreflang.
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
	 * Get the current language object, memoised per request.
	 *
	 * Returns null instead of throwing when called before the container is ready.
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
			// A template asking for the language must never fatal.
			$lang = null;
		}

		self::$current_language_memo = $lang === null ? false : $lang;

		return $lang;
	}

	/**
	 * Run a callback with a different language imposed, then restore it, even on a throw.
	 *
	 * Each language change rebuilds the string map: avoid it in loops; use translate() for one string.
	 * Admin contexts never rewrite pattern or navigation references: see docs-ai/11-code-rationale.md.
	 *
	 * @api  Stable API surface - semver-bound.
	 *
	 * @param string|int|\WP_Post|object|null $language Slug, locale, post, language row, or null/'' for no change.
	 * @param callable                        $callback Callback to run.
	 * @return mixed Whatever the callback returns.
	 */
	public function with_language( $language, callable $callback ) {
		$router = $this->safe_router();
		$target = $this->resolve_language( $language );

		// Unresolvable language: run in the current one, but report it so a typo is visible.
		if ( $router === null || $target === null ) {
			if ( $router !== null && $language !== null && $language !== '' ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s: the language identifier that could not be resolved. */
							__( 'Unknown language %s - the callback ran in the current language instead.', 'perflocale' ),
							is_scalar( $language ) ? (string) $language : get_debug_type( $language )
						)
					),
					'1.0.4'
				);
			}

			return $callback();
		}

		$previous = $router->override_current_language( $target );

		try {
			return $callback();
		} finally {
			// In finally: a throw must not leave the rest of the request in the imposed language.
			$router->override_current_language( $previous );
		}
	}

	/**
	 * Translate one registered string, optionally into a named language, without changing request state.
	 *
	 * Only strings already on the Strings screen (domain|context|text) translate; others return unchanged.
	 * The domain defaults to 'perflocale': pass a theme's own domain for its strings.
	 *
	 * @api  Stable API surface - semver-bound.
	 *
	 * @param string                          $text     Source text, exactly as registered.
	 * @param string|int|\WP_Post|object|null $language Slug, locale, post, language row, or ''/null for the current one.
	 * @param array<string, mixed>            $args     Optional `domain`, `context`, and `default` (defaults to $text).
	 * @return string The translation, or the fallback.
	 */
	public function translate( string $text, $language = '', array $args = [] ): string {
		$domain   = isset( $args['domain'] ) ? (string) $args['domain'] : 'perflocale';
		$context  = isset( $args['context'] ) ? (string) $args['context'] : '';
		$fallback = array_key_exists( 'default', $args ) ? (string) $args['default'] : $text;

		if ( $text === '' ) {
			return $fallback;
		}

		try {
			$language_row = $this->resolve_language( $language );

			// The default language returns the source before any query: it is the common case.
			if ( $language_row === null ) {
				$language_row = $this->current_language();
			}

			$default_language = $this->default_language();

			if (
				$language_row === null
				|| ( $default_language !== null && ( $language_row->slug ?? null ) === ( $default_language->slug ?? null ) )
			) {
				return $fallback;
			}

			$plugin = Plugin::get_instance();

			if ( ! $plugin->has( 'cache' ) ) {
				return $fallback;
			}

			$strings = new \PerfLocale\Database\Repository\StringRepository( $plugin->get( 'cache' ) );
			$string  = $strings->find_by_hash( $domain, $context, $text );

			if ( $string === null || empty( $string->id ) ) {
				return $fallback;
			}

			$values      = new \PerfLocale\Database\Repository\StringTranslationRepository( $plugin->get( 'cache' ) );
			$translation = $values->get( (int) $string->id, (int) $language_row->id );

			// '' means no translation stored, never an empty translation.
			return $translation === '' ? $fallback : $translation;
		} catch ( \Throwable $e ) {
			// A template helper returns something renderable rather than fatalling a page.
			return $fallback;
		}
	}

	/**
	 * Resolve whatever a caller passed into a language row.
	 *
	 * Dispatches by type, never by sniffing a string: slugs are operator-chosen and can look like `de_DE`.
	 *
	 * @param string|int|\WP_Post|object|null $language Language identifier.
	 * @return object|null Language row, or null when it cannot be resolved.
	 */
	private function resolve_language( $language ): ?object {
		if ( $language === null || $language === '' ) {
			return null;
		}

		// Already a language row.
		if ( is_object( $language ) && ! ( $language instanceof \WP_Post ) && isset( $language->slug, $language->id ) ) {
			return $language;
		}

		try {
			$plugin = Plugin::get_instance();

			// A post, or a post id: use whatever language that post is in.
			if ( $language instanceof \WP_Post || is_int( $language ) ) {
				$post_id = $language instanceof \WP_Post ? (int) $language->ID : (int) $language;

				if ( $post_id <= 0 || ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
					return null;
				}

				$manager = new \PerfLocale\Translation\PostTranslationManager(
					$plugin->get( 'cache' ),
					$plugin->get( 'settings' )
				);

				return $manager->detect_post_language( $post_id );
			}

			if ( ! is_string( $language ) || ! $plugin->has( 'lang_repo' ) ) {
				return null;
			}

			$repo = $plugin->get( 'lang_repo' );

			// Slug first: it wins any collision with a locale.
			$row = $repo->find_by_slug( $language );

			return $row ?? $repo->find_by_locale( $language );
		} catch ( \Throwable $e ) {
			return null;
		}
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
	 * Get the URL of the current page in another language, as the switcher and hreflang use it.
	 *
	 * Empty when a singular page has no translation in that language, or the plugin is not booted.
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
	 * Untranslated languages stay in the list with is_translated false and an empty url.
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
	 * Batch version of {@see translations()} for loops: at most two queries for any number of posts.
	 *
	 * Every input id gets a key; an unresolvable post maps to an empty array.
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
	 * True if the given post type or taxonomy is translatable per the site's settings.
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
	 * The public API object of a booted addon (HasPublicApi), created on the first call; null when it has none.
	 *
	 * @since 1.0.7.4
	 *
	 * @param string $id Addon ID.
	 * @return object|null
	 */
	public function addon( string $id ): ?object {
		return Addon\AddonPublicApi::lookup( $id );
	}

	/**
	 * The WooCommerce add-on's PHP API. Never null, so a chain cannot fatal: its methods return a WP_Error when inactive.
	 *
	 * @since 1.0.7.4
	 *
	 * @return \PerfLocale\WooCommerce\Api
	 */
	public function woocommerce(): \PerfLocale\WooCommerce\Api {
		return \PerfLocale\WooCommerce\Api::instance();
	}

	/**
	 * Whether an addon booted in this request; always false before the registry boots (init:0).
	 *
	 * @param string $addon_id Addon ID.
	 * @return bool
	 */
	public function is_addon_active( string $addon_id ): bool {
		if ( $addon_id === '' ) {
			return false;
		}

		try {
			$plugin = Plugin::get_instance();
			if ( ! $plugin->has( Plugin::SERVICE_ADDONS ) ) {
				return false;
			}
			$registry = $plugin->addon_registry();
		} catch ( \Throwable $e ) {
			return false;
		}

		return $registry->is_booted( $addon_id );
	}

	/**
	 * Locale-aware number formatting through intl NumberFormatter when available.
	 *
	 * Without intl the requested locale's WordPress separators are used, which needs its language pack.
	 *
	 * @param float|int   $value     Number to format.
	 * @param string|null $lang_slug Optional language slug; defaults to current.
	 * @param int|null    $decimals  Optional decimal-place override.
	 * @return string Formatted number.
	 */
	public function format_number( $value, ?string $lang_slug = null, ?int $decimals = null ): string {
		$locale = $this->resolve_locale_for_format( $lang_slug );

		// Try/catch too: on PHP 8.4 the constructor throws for locales ICU rejects (me_ME, nah ship in data/).
		if ( class_exists( '\\NumberFormatter' ) ) {
			try {
				$fmt = new \NumberFormatter( $locale, \NumberFormatter::DECIMAL );
				if ( $decimals !== null ) {
					$fmt->setAttribute( \NumberFormatter::FRACTION_DIGITS, max( 0, $decimals ) );
				}
				$formatted = $fmt->format( (float) $value );
				if ( is_string( $formatted ) && $formatted !== '' ) {
					return $formatted;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// Derive the decimals (max 3, as intl's DECIMAL style) so both paths keep the fraction.
		$float = (float) $value;

		if ( $decimals === null ) {
			$trimmed  = rtrim( rtrim( number_format( $float, 3, '.', '' ), '0' ), '.' );
			$dot      = strpos( $trimmed, '.' );
			$decimals = ( false === $dot ) ? 0 : strlen( $trimmed ) - $dot - 1;
		}

		return $this->number_format_for_locale( $float, max( 0, $decimals ), $locale );
	}

	/**
	 * Format a number using a specific locale's separators, without intl.
	 *
	 * number_format_i18n() punctuates for the running locale; another locale switches once and is memoised.
	 * Restore only when switch_to_locale() returned true: false pushed no frame.
	 *
	 * @param float  $value    Number.
	 * @param int    $decimals Decimal places.
	 * @param string $locale   Target locale, e.g. 'de_DE'.
	 * @return string
	 */
	private function number_format_for_locale( float $value, int $decimals, string $locale ): string {
		$current = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		if ( $locale === '' || $locale === $current || ! function_exists( 'switch_to_locale' ) ) {
			return number_format_i18n( $value, $decimals );
		}

		if ( ! isset( self::$number_separator_memo[ $locale ] ) ) {
			$switched = switch_to_locale( $locale );

			global $wp_locale;

			$separators = isset( $wp_locale->number_format['decimal_point'], $wp_locale->number_format['thousands_sep'] )
				? [ (string) $wp_locale->number_format['decimal_point'], (string) $wp_locale->number_format['thousands_sep'] ]
				: null;

			if ( $switched ) {
				restore_previous_locale();
			}

			// Store what was found either way, so a locale without a pack does not re-switch per call.
			self::$number_separator_memo[ $locale ] = $separators;
		}

		$separators = self::$number_separator_memo[ $locale ];

		if ( $separators === null ) {
			return number_format_i18n( $value, $decimals );
		}

		$formatted = number_format( $value, $decimals, $separators[0], $separators[1] );

		// Core's filter: this path stands in for number_format_i18n().
		return (string) apply_filters( 'number_format_i18n', $formatted, $value, $decimals ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core 'number_format_i18n' filter; this method stands in for number_format_i18n() on the cross-locale path, so dropping it would make format_number( $x ) and format_number( $x, 'de' ) disagree on a site that customises number punctuation.
	}

	/**
	 * Locale-aware currency formatting through intl NumberFormatter's CURRENCY style.
	 *
	 * Without intl: "{code} {number}" with two decimals, never a guessed symbol.
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

		// See format_number(): the constructor throws on a locale ICU rejects.
		if ( class_exists( '\\NumberFormatter' ) ) {
			try {
				$fmt       = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
				$formatted = $fmt->formatCurrency( (float) $value, $code );
				if ( is_string( $formatted ) && $formatted !== '' ) {
					return $formatted;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// No intl: code + amount, punctuated for the requested locale.
		return $code . ' ' . $this->number_format_for_locale( (float) $value, 2, $locale );
	}

	/**
	 * Resolve a locale from a language slug, the current language, or get_locale(), in that order.
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
	 * Whether this request shows block and widget labels: admin, REST (the editors read them there) or WP-CLI.
	 *
	 * Elsewhere labels register untranslated, so a page view does not load the plugin's catalog.
	 *
	 * @return bool
	 */
	public static function shows_registration_labels(): bool {
		return is_admin() || self::is_rest_request() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * `block_type_metadata` callback: strips `textdomain` from PerfLocale's two blocks where labels are not shown.
	 *
	 * Core translates labels only through that key; every other block's metadata is returned unchanged.
	 *
	 * @param array<string, mixed> $metadata Block metadata from block.json.
	 * @return array<string, mixed>
	 */
	public static function block_metadata_without_textdomain( array $metadata ): array {
		if ( isset( $metadata['name'], $metadata['textdomain'] )
			&& 'perflocale' === $metadata['textdomain']
			&& in_array( $metadata['name'], [ 'perflocale/language-switcher', 'perflocale/if-language' ], true ) ) {
			unset( $metadata['textdomain'] );
		}

		return $metadata;
	}

	/**
	 * Whether a request URI names an XML sitemap: "sitemap" then ".xml" in the same path segment.
	 *
	 * Same answer as preg_match( '/sitemap[^\/]*\.xml/' ) in linear time: visitors choose the URI.
	 *
	 * @param string $uri Request URI.
	 * @return bool
	 */
	public static function is_sitemap_uri( string $uri ): bool {
		if ( ! str_contains( $uri, 'sitemap' ) ) {
			return false;
		}

		foreach ( explode( '/', $uri ) as $segment ) {
			$at = strpos( $segment, 'sitemap' );

			if ( false !== $at && false !== strpos( $segment, '.xml', $at + 7 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current request is a REST API request, usable at boot time.
	 *
	 * REST_REQUEST is defined only at parse_request, after services boot, so the request target is sniffed.
	 *
	 * @return bool
	 */
	public static function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// Core reads `rest_route` from the query string or the POST body, on any permalink structure.
		if ( ! empty( $_GET['rest_route'] ) || ! empty( $_POST['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			return true;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		if ( ! is_string( $path ) || $path === '' ) {
			return false;
		}

		// The prefix may be customised and may sit beneath a subdirectory install.
		$prefix = trim( rest_get_url_prefix(), '/' );

		if ( $prefix === '' ) {
			return false;
		}

		// Core also matches urldecode() of the path, so /%77p-json/ is REST too.
		$pattern = '#(^|/)' . preg_quote( $prefix, '#' ) . '(/|$)#';

		return (bool) preg_match( $pattern, $path ) || (bool) preg_match( $pattern, urldecode( $path ) );
	}

	/**
	 * Deep-slash a meta value, recursing into objects as well as arrays.
	 *
	 * The meta API's wp_unslash() unslashes object properties, which wp_slash() skips (Beaver Builder data).
	 *
	 * @param mixed $value Meta value (string, array, or object graph).
	 * @return mixed Slashed value.
	 */
	public static function deep_slash( mixed $value ): mixed {
		return map_deep( $value, static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v );
	}

	/**
	 * Whether this request can write content, so the write-hook services must boot; never a front-end GET.
	 *
	 * Keep cron (background translate jobs) and REST (block editor saves): nothing else matches there.
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
	 * Whether outward-facing dispatches (webhooks) may fire from this environment.
	 *
	 * Fail-closed outside 'production': staging clones carry the production webhook URLs.
	 *
	 * @param string $feature Dispatch surface ('webhooks'), so a filter can allow one surface only.
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
		 * Fires when an outward-facing dispatch is blocked by the non-production environment gate.
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
	 * Default writing direction for a locale, keyed on its primary subtag.
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
	 * True when `$path` falls inside one of the configured excluded paths.
	 *
	 * A match needs a delimiter (`/api` never excludes `/apifoo`); both sides are percent-decoded,
	 * because settings store `/日本語/` verbatim while the request path arrives encoded.
	 *
	 * @param string             $path    Request path, encoded or not.
	 * @param array<int, string> $needles Configured excluded paths.
	 */
	public static function path_matches_excluded( string $path, array $needles ): bool {
		$normalised = '/' . ltrim( rawurldecode( $path ), '/' );

		foreach ( $needles as $needle ) {
			$candidate = rtrim( rawurldecode( (string) $needle ), '/' );

			if ( $candidate === '' ) {
				continue;
			}

			if ( $normalised === $candidate
				|| str_starts_with( $normalised, $candidate . '/' )
				|| str_starts_with( $normalised, $candidate . '.' )
				|| str_starts_with( $normalised, $candidate . '?' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Put a query-var slug or page path into the percent-encoded form the database holds.
	 *
	 * Query vars arrive urldecoded and core re-encodes only in WP_Query::parse_query(), after our hooks.
	 * Core's get_page_by_path() method, per segment so nested paths keep their slashes; a no-op on ASCII.
	 *
	 * @param string $value Slug or page path, in either form.
	 * @return string The stored form.
	 */
	public static function normalize_slug_for_query( string $value ): string {
		if ( $value === '' ) {
			return '';
		}

		// Fast path: a value already in sanitize_title_for_query()'s output shape comes back unchanged.
		if ( $value[0] !== '-'
			&& $value[ strlen( $value ) - 1 ] !== '-'
			&& strspn( $value, 'abcdefghijklmnopqrstuvwxyz0123456789-_' ) === strlen( $value )
			&& strpos( $value, '--' ) === false
		) {
			return $value;
		}

		$encoded = rawurlencode( urldecode( $value ) );
		$encoded = str_replace( [ '%2F', '%20' ], [ '/', ' ' ], $encoded );

		$parts = array_map( 'sanitize_title_for_query', explode( '/', trim( $encoded, '/' ) ) );

		return implode( '/', $parts );
	}

	/**
	 * Ceiling for a "Rows per page" screen option: 40% of max_input_vars.
	 *
	 * PHP drops POST fields past max_input_vars, so a larger page bulk-acts on a prefix of the selection.
	 *
	 * @param string $option Screen-option name, for the filter.
	 * @return int
	 */
	public static function per_page_ceiling( string $option = '' ): int {
		$max_input_vars = (int) ini_get( 'max_input_vars' );
		$ceiling        = $max_input_vars > 0 ? max( 10, (int) floor( $max_input_vars * 0.4 ) ) : 400;

		/**
		 * Filter the maximum rows-per-page any PerfLocale list screen will use.
		 *
		 * @hook  perflocale/admin/per_page_ceiling
		 * @since 1.0.5
		 *
		 * @param int    $ceiling Computed ceiling.
		 * @param string $option  Screen-option name.
		 * @return int
		 */
		$ceiling = (int) apply_filters( 'perflocale/admin/per_page_ceiling', $ceiling, $option );

		return max( 1, $ceiling );
	}

	/**
	 * Clamp an effective rows-per-page value.
	 *
	 * Applied on read, not only on save, so an oversized stored value is capped; the stored value stays.
	 *
	 * @param int    $value   Raw stored value.
	 * @param int    $default Fallback when unset/invalid.
	 * @param string $option  Screen-option name, for the filter.
	 * @return int
	 */
	public static function normalize_per_page( int $value, int $default = 20, string $option = '' ): int {
		if ( $value < 1 ) {
			$value = $default;
		}

		return min( max( 1, $value ), self::per_page_ceiling( $option ) );
	}

	/**
	 * One line naming post counts by post type, e.g. "Products: 20, Posts: 4".
	 *
	 * Plain text: escape at the point of output.
	 *
	 * @param array<string, int> $counts Post type => count.
	 * @return string
	 */
	public static function post_type_counts_summary( array $counts ): string {
		$parts = [];

		foreach ( $counts as $type => $count ) {
			$object  = get_post_type_object( (string) $type );
			$label   = ( $object instanceof \WP_Post_Type && is_string( $object->labels->name ?? null ) ) ? $object->labels->name : (string) $type;
			$parts[] = sprintf( '%s: %s', $label, number_format_i18n( (int) $count ) );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Shorten an already-sanitised slug to `$length` bytes without cutting inside a percent-escape.
	 *
	 * Walks the encoded slug, never decodes it (decoding breaks `%25`); see docs-ai/11-code-rationale.md.
	 * The byte bound is load-bearing: force_insert_term()'s "-N" walk spins forever otherwise.
	 *
	 * @param string $slug   Slug, already sanitize_title()'d.
	 * @param int    $length Maximum length to keep.
	 * @return string Shortened slug, or the input when it already fits.
	 */
	public static function truncate_slug( string $slug, int $length ): string {
		if ( $length < 1 || strlen( $slug ) <= $length ) {
			return $slug;
		}

		$out = '';
		$i   = 0;
		$n   = strlen( $slug );

		while ( $i < $n ) {
			$char = $slug[ $i ];

			$hex = $char === '%' && $i + 2 < $n ? substr( $slug, $i + 1, 2 ) : '';

			// Strspn, not ctype_xdigit: the plugin does not require ext-ctype.
			if ( $hex !== '' && strspn( $hex, '0123456789abcdefABCDEF' ) === 2 ) {
				$byte = (int) hexdec( $hex );

				// A lead byte >= 0xC0 makes all escapes of its character one indivisible atom.
				if ( $byte >= 0xF0 ) {
					$octets = 4;
				} elseif ( $byte >= 0xE0 ) {
					$octets = 3;
				} elseif ( $byte >= 0xC0 ) {
					$octets = 2;
				} else {
					$octets = 1;
				}

				$atom = substr( $slug, $i, 3 * $octets );
			} elseif ( ord( $char ) >= 0x80 ) {
				// Raw UTF-8 from a third-party `sanitize_title` filter: keep the character whole.
				$lead   = ord( $char );
				$octets = $lead >= 0xF0 ? 4 : ( $lead >= 0xE0 ? 3 : ( $lead >= 0xC0 ? 2 : 1 ) );
				$atom   = substr( $slug, $i, $octets );
			} else {
				$atom = $char;
			}

			if ( strlen( $out ) + strlen( $atom ) > $length ) {
				break;
			}

			$out .= $atom;
			$i   += strlen( $atom );
		}

		return rtrim( $out, '-' );
	}

	/**
	 * Sanitise plain text like sanitize_text_field() but without deleting percent-escapes ("100%25 off").
	 *
	 * The UTF-8 scrub is needed: wpdb refuses a whole write whose value strip_invalid_text() would change.
	 * Takes mixed: REST sanitize_callbacks pass whatever the client sent, and strict_types would throw.
	 *
	 * @param mixed $str           Raw value; anything not a string yields ''.
	 * @param bool  $keep_newlines Keep line breaks (excerpt) or collapse them (title).
	 * @return string Sanitised text.
	 */
	public static function sanitize_plain_text( $str, bool $keep_newlines = false ): string {
		if ( ! is_scalar( $str ) ) {
			return '';
		}

		$str = (string) $str;

		if ( $str === '' ) {
			return '';
		}

		// One PCRE pass proves validity; a valid subject never enters the scrub.
		if ( preg_match( '//u', $str ) !== 1 ) {
			// Same alternation as wpdb::strip_invalid_text(): valid sequences kept, other bytes dropped.
			$str = (string) preg_replace(
				'/([\x00-\x7F]'
				. '|[\xC2-\xDF][\x80-\xBF]'
				. '|\xE0[\xA0-\xBF][\x80-\xBF]'
				. '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
				. '|\xED[\x80-\x9F][\x80-\xBF]'
				. '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
				. '|[\xF1-\xF3][\x80-\xBF]{3}'
				. '|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
				'$1',
				$str
			);
		}

		// Core's tag handling verbatim, to match sanitize_text_field() on markup.
		if ( str_contains( $str, '<' ) ) {
			$str = wp_pre_kses_less_than( $str );
			$str = wp_strip_all_tags( $str, false );
			$str = str_replace( "<\n", '&lt;' . "\n", $str );
		}

		if ( ! $keep_newlines ) {
			$str = (string) preg_replace( '/[\r\n\t ]+/', ' ', $str );
		}

		return trim( $str );
	}

	/**
	 * Single-line variant, shaped for a REST `sanitize_callback`.
	 *
	 * Registering sanitize_plain_text() directly would bind the WP_REST_Request to $keep_newlines.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_plain_text_field( $value ): string {
		return self::sanitize_plain_text( $value, false );
	}

	/**
	 * Multi-line variant for excerpts, shaped like {@see self::sanitize_plain_text_field()}.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_plain_textarea_field( $value ): string {
		return self::sanitize_plain_text( $value, true );
	}

	/**
	 * Sanitize an imported string translation from a user without unfiltered_html, as the Strings screen does.
	 *
	 * Kses runs only on a value with `<`: without one no markup can form, and kses would encode `&` and `>`.
	 * The caller decides who is untrusted; safe for HTML text only, other contexts still escape.
	 *
	 * @param string $value Translation as supplied.
	 * @return string
	 */
	public static function sanitize_untrusted_string_translation( string $value ): string {
		return str_contains( $value, '<' ) ? wp_kses_post( $value ) : $value;
	}

	/**
	 * True when `$ip` is a valid IP literal; built on inet_pton() because ext-filter is optional.
	 *
	 * Matches filter_var( FILTER_VALIDATE_IP ); see docs-ai/11-code-rationale.md.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_ip( string $ip ): bool {
		// Fail closed: disable_functions can remove inet_pton, and a NUL byte makes it throw.
		if ( ! function_exists( 'inet_pton' ) || $ip === '' || strpos( $ip, "\0" ) !== false ) {
			return false;
		}

		return inet_pton( $ip ) !== false;
	}

	/**
	 * True when `$ip` is a syntactically valid IPv6 literal.
	 *
	 * @see is_ip()
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_ipv6( string $ip ): bool {
		if ( ! function_exists( 'inet_pton' ) || $ip === '' || strpos( $ip, "\0" ) !== false ) {
			return false;
		}

		$bin = inet_pton( $ip );

		return $bin !== false && strlen( $bin ) === 16;
	}

	/**
	 * True when `$ip` (IPv4 or IPv6) is public, as filter_var's NO_PRIV_RANGE | NO_RES_RANGE judges it.
	 *
	 * Do not add blocks: GeoRedirect uses it for visitors; outbound guards use is_public_outbound_address().
	 * Rejecting dotted-quad IPv6 reproduces PHP's quirk: dropping it loosens an SSRF guard.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_public_ipv4( string $ip ): bool {
		if ( ! function_exists( 'inet_pton' ) || $ip === '' || strpos( $ip, "\0" ) !== false ) {
			return false;
		}

		$bin = inet_pton( $ip );

		if ( $bin === false ) {
			return false;
		}

		if ( strlen( $bin ) === 4 ) {
			$a = ord( $bin[0] );
			$b = ord( $bin[1] );

			// Unspecified, private class A, and loopback blocks.
			if ( $a === 0 || $a === 10 || $a === 127 ) {
				return false;
			}

			// Link-local, which is where the cloud metadata address lives.
			if ( $a === 169 && $b === 254 ) {
				return false;
			}

			// The private class B block.
			if ( $a === 172 && ( $b & 0xF0 ) === 16 ) {
				return false;
			}

			// The private class C block.
			if ( $a === 192 && $b === 168 ) {
				return false;
			}

			// Reserved space, which also covers the broadcast address.
			if ( ( $a & 0xF0 ) === 240 ) {
				return false;
			}

			return true;
		}

		// PHP rejects every IPv6 literal with an embedded dotted quad.
		if ( strpos( $ip, '.' ) !== false ) {
			return false;
		}

		// The unspecified address and loopback.
		if ( $bin === str_repeat( "\x00", 16 ) || $bin === str_repeat( "\x00", 15 ) . "\x01" ) {
			return false;
		}

		// IPv4-mapped ::ffff:0:0/96, checked in bytes: it also has a hex-only spelling.
		if ( substr( $bin, 0, 10 ) === str_repeat( "\x00", 10 ) && substr( $bin, 10, 2 ) === "\xFF\xFF" ) {
			return false;
		}

		$first = ord( $bin[0] );

		// Unique-local addresses.
		if ( ( $first & 0xFE ) === 0xFC ) {
			return false;
		}

		// Link-local addresses.
		if ( $first === 0xFE && ( ord( $bin[1] ) & 0xC0 ) === 0x80 ) {
			return false;
		}

		return true;
	}

	/**
	 * True when an outbound request (webhook, machine translation) may connect to `$ip`.
	 *
	 * Refuses what is_public_ipv4() refuses plus the IANA blocks that are not globally reachable, as core does.
	 * Internal MT endpoints go through the `perflocale/mt/trusted_hosts` filter, never through this check.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_public_outbound_address( string $ip ): bool {
		if ( ! self::is_public_ipv4( $ip ) ) {
			return false;
		}

		$bin = inet_pton( $ip );

		if ( $bin === false ) {
			return false;
		}

		if ( strlen( $bin ) === 4 ) {
			$a = ord( $bin[0] );
			$b = ord( $bin[1] );
			$c = ord( $bin[2] );

			// Shared address space (carrier-grade NAT), 100.64.0.0/10.
			if ( $a === 100 && ( $b & 0xC0 ) === 64 ) {
				return false;
			}

			// IETF 192.0.0.0/24, documentation 192.0.2.0/24, 6to4 relay 192.88.99.0/24.
			if ( $a === 192 && ( ( $b === 0 && ( $c === 0 || $c === 2 ) ) || ( $b === 88 && $c === 99 ) ) ) {
				return false;
			}

			// Benchmarking 198.18.0.0/15 and documentation 198.51.100.0/24.
			if ( $a === 198 && ( ( $b & 0xFE ) === 18 || ( $b === 51 && $c === 100 ) ) ) {
				return false;
			}

			// Documentation 203.0.113.0/24.
			if ( $a === 203 && $b === 0 && $c === 113 ) {
				return false;
			}

			// Multicast 224.0.0.0/4.
			if ( ( $a & 0xF0 ) === 224 ) {
				return false;
			}

			return true;
		}

		// NAT64 64:ff9b::/96: judged by the IPv4 address in the last four bytes.
		if ( substr( $bin, 0, 12 ) === "\x00\x64\xFF\x9B" . str_repeat( "\x00", 8 ) ) {
			$embedded = inet_ntop( substr( $bin, 12, 4 ) );

			return false !== $embedded && self::is_public_outbound_address( $embedded );
		}

		// 6to4 2002::/16: the IPv4 address is in bytes 2 to 5.
		if ( substr( $bin, 0, 2 ) === "\x20\x02" ) {
			$embedded = inet_ntop( substr( $bin, 2, 4 ) );

			return false !== $embedded && self::is_public_outbound_address( $embedded );
		}

		// IPv4-compatible ::/96 (which also holds :: and ::1).
		if ( substr( $bin, 0, 12 ) === str_repeat( "\x00", 12 ) ) {
			return false;
		}

		// IPv4-translated ::ffff:0:0:0/96.
		if ( substr( $bin, 0, 12 ) === str_repeat( "\x00", 8 ) . "\xFF\xFF\x00\x00" ) {
			return false;
		}

		// Local-use NAT64 64:ff9b:1::/48.
		if ( substr( $bin, 0, 6 ) === "\x00\x64\xFF\x9B\x00\x01" ) {
			return false;
		}

		// Teredo 2001::/32, ORCHID 2001:10::/28 and 2001:20::/28, documentation 2001:db8::/32.
		if ( substr( $bin, 0, 2 ) === "\x20\x01" ) {
			$third  = ord( $bin[2] );
			$fourth = ord( $bin[3] );

			if ( $third === 0x00 && ( $fourth === 0x00 || ( $fourth & 0xF0 ) === 0x10 || ( $fourth & 0xF0 ) === 0x20 ) ) {
				return false;
			}

			if ( $third === 0x0D && $fourth === 0xB8 ) {
				return false;
			}
		}

		// Documentation 3fff::/20.
		if ( substr( $bin, 0, 2 ) === "\x3F\xFF" && ( ord( $bin[2] ) & 0xF0 ) === 0x00 ) {
			return false;
		}

		// Discard-only 100::/64.
		if ( substr( $bin, 0, 8 ) === "\x01\x00" . str_repeat( "\x00", 6 ) ) {
			return false;
		}

		$first = ord( $bin[0] );

		// Site-local fec0::/10.
		if ( $first === 0xFE && ( ord( $bin[1] ) & 0xC0 ) === 0xC0 ) {
			return false;
		}

		// Multicast ff00::/8.
		if ( $first === 0xFF ) {
			return false;
		}

		return true;
	}

	/**
	 * Run wp_remote_request() pinned to addresses the caller already judged, against DNS rebinding.
	 *
	 * cURL gets CURLOPT_RESOLVE; fsockopen https re-checks the lookup, since a rewritten socket breaks SNI.
	 * Nothing is pinned without a valid `$ip` or behind a proxy; see docs-ai/11-code-rationale.md.
	 *
	 * @param string       $url  Request URL.
	 * @param array<mixed> $args wp_remote_request() arguments.
	 * @param string       $ip   Address to connect to; '' for none.
	 * @param array<mixed> $more Further judged addresses of the same host, listed after `$ip`.
	 * @return array<string, mixed>|\WP_Error wp_remote_request()'s result.
	 */
	public static function remote_request_pinned( string $url, array $args, string $ip, array $more = [] ): array|\WP_Error {
		$pins = self::connection_pins( $url, $ip, $more );

		foreach ( $pins as $hook => $pin ) {
			add_action( $hook, $pin, 10, 1 );
		}

		try {
			return wp_remote_request( $url, $args );
		} finally {
			foreach ( $pins as $hook => $pin ) {
				remove_action( $hook, $pin, 10 );
			}
		}
	}

	/**
	 * The two transport callbacks {@see remote_request_pinned()} hooks for one
	 * request, keyed by hook name; empty when nothing is pinned.
	 *
	 * @param string       $url  Request URL.
	 * @param string       $ip   Address to connect to; '' for none.
	 * @param array<mixed> $more Further judged addresses, listed after `$ip`.
	 * @return array<string, \Closure>
	 */
	private static function connection_pins( string $url, string $ip, array $more = [] ): array {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $ip || ! is_string( $host ) || '' === $host || ! self::is_ip( $ip ) ) {
			return [];
		}

		$proxy = new \WP_HTTP_Proxy();

		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return [];
		}

		$host    = strtolower( $host );
		$https   = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$port    = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port    = $port > 0 ? $port : ( $https ? 443 : 80 );
		$address = self::is_ipv6( $ip ) ? '[' . $ip . ']' : $ip;
		$list    = [ $address ];

		foreach ( $more as $other ) {
			if ( is_string( $other ) && self::is_ip( $other ) ) {
				$list[] = self::is_ipv6( $other ) ? '[' . $other . ']' : $other;
			}
		}

		$entry = $host . ':' . $port . ':' . implode( ',', array_unique( $list ) );

		return [
			'http_api_curl'                    => static function ( $handle ) use ( $entry ): void {
				if ( $handle instanceof \CurlHandle ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- one option on the handle WP_Http created for this wp_remote_request().
					curl_setopt( $handle, CURLOPT_RESOLVE, [ $entry ] );
				}
			},
			'requests-fsockopen.remote_socket' => static function ( &$remote_socket ) use ( $host, $port, $address, $https ): void {
				if ( ! is_string( $remote_socket ) ) {
					return;
				}

				$socket = strtolower( $remote_socket );

				if ( ! $https && 'tcp://' . $host . ':' . $port === $socket ) {
					$remote_socket = 'tcp://' . $address . ':' . $port;

					return;
				}

				if ( $https && 'ssl://' . $host . ':' . $port === $socket && ! self::is_public_outbound_address( gethostbyname( $host ) ) ) {
					// The throw skips Requests' own restore of its connect error handler.
					restore_error_handler();

					throw new \WpOrg\Requests\Exception( 'The host resolves to a non-public address.', 'perflocale_pin' );
				}
			},
		];
	}

	/**
	 * True when `$url` parses as a syntactically valid URL; uses filter_var when it exists.
	 *
	 * The fallback is stricter than filter_var, never looser (it guards SSRF checks); see docs-ai/11-code-rationale.md.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_valid_url( string $url ): bool {
		if ( function_exists( 'filter_var' ) ) {
			return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
		}

		$len = strlen( $url );

		if ( $len === 0 || $len > 2048 ) {
			return false;
		}

		// Printable ASCII only, as FILTER_VALIDATE_URL.
		for ( $i = 0; $i < $len; $i++ ) {
			$ord = ord( $url[ $i ] );

			if ( $ord < 0x21 || $ord > 0x7E ) {
				return false;
			}
		}

		// Every per cent must introduce exactly two hex digits, as filter_var requires.
		$pos = 0;

		while ( ( $pos = strpos( $url, '%', $pos ) ) !== false ) {
			if ( $pos + 2 >= $len || strspn( substr( $url, $pos + 1, 2 ), '0123456789abcdefABCDEF' ) !== 2 ) {
				return false;
			}

			$pos += 3;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is deliberately NOT used: this branch runs only when ext-filter is absent, and it must stay loadable without WordPress so the gate can exercise it in a subprocess. The two differ only for protocol-relative and root-relative URLs, which the scheme test below rejects anyway.
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		// Userinfo refused outright: filter_var's own userinfo rule cannot be matched without looser cases.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = (string) $parts['host'];

		if ( $host[0] === '[' ) {
			// Brackets only around IPv6: filter_var rejects a bracketed IPv4, hence the length test.
			if ( substr( $host, -1 ) !== ']' || ! function_exists( 'inet_pton' ) ) {
				return false;
			}

			$literal = substr( $host, 1, -1 );

			if ( $literal === '' || strpos( $literal, "\0" ) !== false ) {
				return false;
			}

			$packed = inet_pton( $literal );

			return $packed !== false && strlen( $packed ) === 16;
		}

		$probe = substr( $host, -1 ) === '.' ? substr( $host, 0, -1 ) : $host;

		if ( $probe === '' || strlen( $probe ) > 253 ) {
			return false;
		}

		foreach ( explode( '.', $probe ) as $label ) {
			$label_len = strlen( $label );

			if ( $label_len === 0 || $label_len > 63 ) {
				return false;
			}

			if ( $label[0] === '-' || $label[ $label_len - 1 ] === '-' ) {
				return false;
			}

			if ( strspn( $label, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-' ) !== $label_len ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Exact native replacement for `filter_var( $v, FILTER_VALIDATE_BOOLEAN )`.
	 *
	 * Not wp_validate_boolean() or rest_sanitize_boolean(): both map "off" and "no" to true.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value === 1.0;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		// Explicit charlist: filter_var trims these, but not NUL as the default trim() does.
		return in_array( strtolower( trim( $value, " \t\n\r\x0B" ) ), [ '1', 'true', 'on', 'yes' ], true );
	}

	/**
	 * True when `$s` is a non-empty run of ASCII letters: ctype_alpha() without ext-ctype.
	 *
	 * ASCII only by intent: callers pass BCP 47 subtags and ISO country codes.
	 *
	 * @param string $s Candidate.
	 */
	public static function is_ascii_alpha( string $s ): bool {
		return $s !== '' && strspn( $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' ) === strlen( $s );
	}

	/**
	 * True when `$s` is a non-empty run of ASCII digits: ctype_digit() without ext-ctype.
	 *
	 * @param string $s Candidate.
	 */
	public static function is_ascii_digits( string $s ): bool {
		return $s !== '' && strspn( $s, '0123456789' ) === strlen( $s );
	}

	/**
	 * Convert a WordPress locale string into a canonical BCP 47 tag (`zh_Hans_CN` -> `zh-Hans-CN`).
	 *
	 * The region is kept (`fr_FR` -> `fr-FR`, never `fr`); malformed subtags pass through lowercased.
	 *
	 * @internal This is an implementation detail consumed by the admin
	 *           Languages form and the SEO hreflang emitter. Despite
	 *           being public-static, it is NOT part of the @api surface
	 *           the rest of this class is bound by - signature and
	 *           behaviour may change between minor releases. Third-party
	 *           code that needs a BCP-47 string should normalise its
	 *           inputs and use WordPress' get_bloginfo('language')
	 *           directly.
	 *
	 * @param string $locale Raw locale string (`en_US`, `pt-BR`, `ar`, etc.).
	 * @return string Canonical BCP 47 tag.
	 */
	public static function format_locale_as_bcp47( string $locale ): string {
		// Uncapped memo: the keys are the site's few locale strings.
		static $memo = [];

		if ( isset( $memo[ $locale ] ) ) {
			return $memo[ $locale ];
		}

		$raw    = $locale;
		$locale = trim( $locale );

		if ( $locale === '' ) {
			return $memo[ $raw ] = '';
		}

		// Drop `_formal`/`_informal` (not IANA subtags); an `@` modifier becomes a variant subtag.
		$locale = (string) preg_replace( '/_(?:formal|informal)$/i', '', $locale );
		if ( str_contains( $locale, '@' ) ) {
			// e.g. ca@valencia -> ca-valencia (variant), sr_RS@latin -> sr-RS-latin.
			$locale = str_replace( '@', '-', $locale );
		}

		// Any run of `_`, `-` or whitespace becomes one hyphen.
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

			// Non-alpha tokens (private use `x-mywp1`) pass through lowercased.
			if ( ! self::is_ascii_alpha( $part ) ) {
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
	 * Suggest a region-qualified slug (`en` -> `en-us`) for a bare slug, from its locale or flag.
	 *
	 * Returns '' when the slug already has a region or none can be derived.
	 *
	 * @internal Admin-form helper for the Languages page. Not part of
	 *           the @api surface - signature and heuristics may change.
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

		if ( count( $parts ) >= 2 && self::is_ascii_alpha( $parts[1] ) ) {
			$region = strtolower( $parts[1] );
		}

		// Fallback for region-less locales (`ar`): the flag column holds an ISO code or a flag emoji.
		if ( $region === '' && $flag !== '' ) {
			if ( self::is_ascii_alpha( $flag ) && strlen( $flag ) === 2 ) {
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
	 * Active bare slugs whose own language family has a region-qualified member (`en` with `en-GB`).
	 *
	 * Same family only: a lone `de` beside `fr-FR` is intentional and is never flagged.
	 *
	 * @internal Admin-form helper for the Languages page rename-nudge UX.
	 *           Not part of the @api surface.
	 *
	 * @param array<int, object> $languages All active languages.
	 * @return array<int, array{language: object, suggested: string}>
	 */
	public static function detect_bare_languages_with_region_siblings( array $languages ): array {
		// Group by family: the slug part before the first `-`.
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
				// No bare member, or no region member: nothing asymmetric.
				continue;
			}

			$suggested = self::suggest_region_qualified_slug( $bare );

			if ( $suggested === '' ) {
				continue; // No region target.
			}

			$out[] = [
				'language'  => $bare,
				'suggested' => $suggested,
			];
		}

		return $out;
	}

	/**
	 * Bare-slug rename candidates for the Add-Language form, with no region-sibling requirement.
	 *
	 * The form is where the sibling gets added, so waiting for one would nudge too late.
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
	 * Convert a 2-letter country code to its Unicode emoji flag ("fr" -> regional indicators F, R).
	 *
	 * @param string $code 2-letter ISO 3166-1 alpha-2 country code.
	 * @return string Emoji flag, or the original code if invalid.
	 */
	public static function country_code_to_emoji( string $code ): string {
		$code = strtoupper( trim( $code ) );

		if ( strlen( $code ) !== 2 || ! self::is_ascii_alpha( $code ) ) {
			return $code;
		}

		// Raw bytes (prefix F0 9F 87), not mb_chr(): core polyfills it only from WP 7.1 and mbstring is optional.
		$first  = "\xF0\x9F\x87" . chr( 0xA6 + ord( $code[0] ) - ord( 'A' ) );
		$second = "\xF0\x9F\x87" . chr( 0xA6 + ord( $code[1] ) - ord( 'A' ) );

		return $first . $second;
	}

	/**
	 * Inverse of {@see country_code_to_emoji()}: a flag emoji pair to a lowercase country code, or ''.
	 *
	 * @param string $emoji Two regional-indicator codepoints.
	 * @return string Lowercase 2-letter region code, or '' on no match.
	 */
	public static function region_code_from_flag_emoji( string $emoji ): string {
		// Byte-level decode, not mb_ord(): core polyfills it only from WP 7.1 and mbstring is optional.
		if ( strlen( $emoji ) !== 8 ) {
			return '';
		}

		$prefix = "\xF0\x9F\x87";

		if ( substr( $emoji, 0, 3 ) !== $prefix || substr( $emoji, 4, 3 ) !== $prefix ) {
			return '';
		}

		$b1 = ord( $emoji[3] );
		$b2 = ord( $emoji[7] );

		// Trailing byte A6..BF maps to A..Z.
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
	 * Like {@see router()} but returns null on any throw, for getters called before boot finishes.
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
	 * Get an initialized WP_Filesystem instance, or null on failure.
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

		/** @var \WP_Filesystem_Base|mixed $wp_filesystem */
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Absolute path of the plugin's per-blog uploads directory, `uploads/perflocale`.
	 *
	 * @return string Absolute path WITHOUT trailing slash.
	 */
	public static function uploads_base_dir(): string {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		return $base === '' ? '' : trailingslashit( $base ) . 'perflocale';
	}

	/**
	 * Generated translation files dir, `uploads/perflocale/translations/`.
	 *
	 * @return string
	 */
	public static function uploads_translations_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/translations';
	}

	/**
	 * Scratch dir for in-flight imports, `uploads/perflocale/temp/`.
	 *
	 * @return string
	 */
	public static function uploads_temp_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/temp';
	}

	/**
	 * Export bundle dir, `uploads/perflocale/exports/`.
	 *
	 * @return string
	 */
	public static function uploads_exports_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/exports';
	}

	/**
	 * Daily backstop sweep of old temp and export files whose own delete event never ran.
	 *
	 * Never deletes the directory's hardening files.
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
	 * Ensure the plugin's base uploads dir exists and is hardened; subdirs harden themselves on creation.
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
	 * Write `.htaccess` (Deny from all) and `index.php` into a plugin-owned directory, once each.
	 *
	 * Defence in depth only (nginx ignores .htaccess): the random file names are the access control,
	 * so never shorten those tokens on the strength of this file. No web.config, by WordPress convention.
	 *
	 * @param string $dir Absolute filesystem path to an existing directory.
	 * @return void
	 */
	public static function harden_directory( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}

		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

		// Without WP_Filesystem (ftpext, no credentials) write directly: this PHP user created the dir.
		$fs    = self::filesystem();
		$files = [
			trailingslashit( $dir ) . '.htaccess' => "Deny from all\n",
			trailingslashit( $dir ) . 'index.php' => "<?php\n// Silence is golden.\n",
		];

		foreach ( $files as $file => $contents ) {
			if ( $fs instanceof \WP_Filesystem_Base ) {
				if ( ! $fs->exists( $file ) ) {
					$fs->put_contents( $file, $contents, $mode );
				}
				continue;
			}

			if ( file_exists( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem being unavailable is the entire reason this branch exists, so there is nothing to route through; @ keeps an unwritable directory from emitting a warning into an AJAX/REST body, exactly as gc_stale_upload_files() does above.
			@file_put_contents( $file, $contents );
		}
	}

	/**
	 * Date format for the current language, with site-default fallback.
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
	 * Render a timestamp with wp_date() in the current language's date format.
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
	 * Resolve the date format for a language: its own column, else the site's date_format option.
	 *
	 * @param object|int|string|null $lang Language row, ID, slug, or null for the current language.
	 * @return string A `wp_date()`-compatible format string.
	 */
	public static function date_format_for_language( $lang = null ): string {
		$resolved = self::resolve_language_arg( $lang );
		$override = $resolved && ! empty( $resolved->date_format ) ? (string) $resolved->date_format : '';
		// A stored format holding markup ('<') is not used: the site option applies.
		$format = $override !== '' && ! str_contains( $override, '<' ) ? $override : (string) get_option( 'date_format', 'F j, Y' );

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
	 * Resolve the time format for a language, as {@see self::date_format_for_language()} does.
	 *
	 * @param object|int|string|null $lang
	 * @return string
	 */
	public static function time_format_for_language( $lang = null ): string {
		$resolved = self::resolve_language_arg( $lang );
		$override = $resolved && ! empty( $resolved->time_format ) ? (string) $resolved->time_format : '';
		// A stored format holding markup ('<') is not used: the site option applies.
		$format = $override !== '' && ! str_contains( $override, '<' ) ? $override : (string) get_option( 'time_format', 'g:i a' );

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
	 * Date format plus time format for the same language.
	 *
	 * @param object|int|string|null $lang
	 * @return string
	 */
	public static function datetime_format_for_language( $lang = null ): string {
		return trim( self::date_format_for_language( $lang ) . ' ' . self::time_format_for_language( $lang ) );
	}

	/**
	 * Turn a language row, id, slug or null (current) into a language row.
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
			// Bootstrap not ready - fall through to global defaults.
			unset( $e );
		}

		return null;
	}

	/**
	 * Load a non-wp.org addon's own `{domain}-{locale}.mo`; call it on init, priority 99.
	 *
	 * Addons hosted on wp.org need no call: core's just-in-time loader handles them.
	 *
	 * @param string $domain Text domain matching the addon's `Text Domain` header.
	 * @param string $mo_dir Absolute path to the directory containing `.mo` files.
	 * @param string $locale Optional explicit locale; defaults to determine_locale().
	 * @return bool True if a `.mo` file was located and loaded.
	 *
	 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
	 */
	public static function load_addon_textdomain( string $domain, string $mo_dir, string $locale = '' ): bool {
		// Name the addon in the notice; core's own early-load notice does not.
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
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		}

		$mofile = trailingslashit( $mo_dir ) . $domain . '-' . $locale . '.mo';

		if ( ! is_readable( $mofile ) ) {
			return false;
		}

		return load_textdomain( $domain, $mofile, $locale );
	}

	/**
	 * Point every `perflocale*` stylesheet at its shipped `-rtl.css` twin on RTL pages.
	 *
	 * Core's is_rtl() needs the core language pack; without one, the front end rewrites our own
	 * handles from the language's text_direction column. See docs-ai/11-code-rationale.md.
	 *
	 * @return void
	 */
	public static function register_rtl_styles(): void {
		$core_swaps = is_rtl();
		$force      = false;

		if ( ! $core_swaps && ! is_admin() ) {
			// Front end only: wp-admin follows the user's profile locale, which core already handles.
			$force = self::get_instance()->is_rtl();
		}

		if ( ! $core_swaps && ! $force ) {
			return;
		}

		$styles = wp_styles();

		// Scheme-relative URL to path mapping; an unmappable host (CDN) just keeps the LTR sheet.
		$content_rel = (string) preg_replace( '#^https?:#', '', content_url() );

		// Map our own sheets via PERFLOCALE_URL to PERFLOCALE_DIR: correct when the plugins dir is relocated.
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

			// Only claim a twin that shipped: a missing one 404s the whole stylesheet.
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

			// Core's swap is off here, so rewrite the source, keeping its cache-busting query string.
			$style->src = substr( $src, 0, -4 ) . '-rtl.css' . substr( (string) $style->src, strlen( $src ) );
		}
	}

	/**
	 * Whether the current user, or the user named by $user_id, may edit a translatable object.
	 *
	 * Not a bare edit_post: a `map_meta_cap => false` host type (WPForms) answers false for everyone,
	 * so the host's own check is asked; callers gate translation rights first. See docs-ai/11-code-rationale.md.
	 *
	 * @since 1.0.5
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $user_id User asked about; null for the current user.
	 * @return bool
	 */
	public static function user_can_edit_object( int $post_id, ?int $user_id = null ): bool {
		return self::object_permission( 'edit', 'edit_post', $post_id, $user_id );
	}

	/**
	 * Whether the current user may delete a translatable object, host-aware as {@see self::user_can_edit_object()}.
	 *
	 * @since 1.0.5
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function user_can_delete_object( int $post_id ): bool {
		return self::object_permission( 'delete', 'delete_post', $post_id );
	}

	/**
	 * Whether the current user may see a translatable object: read it or edit it, host-aware.
	 *
	 * Either half is enough: in core neither edit_post nor read_post implies the other.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function user_can_view_object( int $post_id ): bool {
		return self::object_permission( 'read', 'read_post', $post_id )
			|| self::object_permission( 'edit', 'edit_post', $post_id );
	}

	/**
	 * Whether the current user may have a new translation copied from this post (the copy source).
	 *
	 * Needs both edit and read, host-aware: in core neither implies the other.
	 *
	 * @param int $post_id Post ID the translation would be copied from.
	 * @return bool
	 */
	public static function user_can_copy_translation_source( int $post_id ): bool {
		return self::object_permission( 'edit', 'edit_post', $post_id )
			&& self::object_permission( 'read', 'read_post', $post_id );
	}

	/**
	 * Whether the current user may create a new post of this post's type (its `create_posts`), host-aware.
	 *
	 * A non-string `create_posts` (Pods sets false) refuses everyone, super admins included.
	 *
	 * @param int $post_id Post the new translation would be copied from.
	 * @return bool False when the type is not registered or has no create capability name.
	 */
	public static function user_can_create_like( int $post_id ): bool {
		$type_object = get_post_type_object( (string) get_post_type( $post_id ) );
		$capability  = $type_object instanceof \WP_Post_Type ? ( $type_object->cap->create_posts ?? null ) : null;

		if ( ! is_string( $capability ) ) {
			return false;
		}

		return self::object_permission( 'create', $capability, $post_id );
	}

	/**
	 * Whether the current user may create a term in `$term_id`'s taxonomy, as core rules a new term.
	 *
	 * That is edit_terms, or for a flat taxonomy also assign_terms, never per-term edit_term; wp_insert_term() checks none.
	 *
	 * @param int $term_id Term the new translation would be copied from.
	 * @return bool False when the term or its taxonomy does not exist.
	 */
	public static function user_can_create_term_like( int $term_id ): bool {
		$term     = get_term( $term_id );
		$taxonomy = $term instanceof \WP_Term ? get_taxonomy( $term->taxonomy ) : false;

		if ( ! $taxonomy instanceof \WP_Taxonomy ) {
			return false;
		}

		$caps = $taxonomy->hierarchical ? [ $taxonomy->cap->edit_terms ] : [ $taxonomy->cap->edit_terms, $taxonomy->cap->assign_terms ];

		foreach ( $caps as $capability ) {
			if ( is_string( $capability ) && current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve one object-level permission, giving the host a say.
	 *
	 * Trash goes through here (it is a deletion), publish deliberately does not; see docs-ai/11-code-rationale.md.
	 *
	 * @param string   $action     Semantic action: 'edit', 'delete', 'read' or 'create'.
	 * @param string   $capability Core capability checked by default.
	 * @param int      $post_id    Post ID.
	 * @param int|null $user_id    User asked about; null for the current user. Another user gets core's answer only.
	 * @return bool
	 */
	private static function object_permission( string $action, string $capability, int $post_id, ?int $user_id = null ): bool {
		if ( null !== $user_id && get_current_user_id() !== $user_id ) {
			return user_can( $user_id, $capability, $post_id );
		}

		$can = current_user_can( $capability, $post_id );

		// Only for the filter to switch on: a listener must answer for its own type only.
		$post_type = (string) get_post_type( $post_id );

		if ( $post_type === '' ) {
			return $can;
		}

		/**
		 * Filter whether the current user may act on one translatable object.
		 *
		 * Answer with the host's own authorization function for your own post type; return `$can` for others.
		 * Returning true grants access; 'create' means a new post of this type. See docs-ai/11-code-rationale.md.
		 *
		 * @hook  perflocale/object/user_can
		 * @since 1.0.5
		 *
		 * @param bool   $can       Whether core's capability check passed.
		 * @param string $action    Semantic action: 'edit', 'delete', 'read' or 'create'.
		 * @param int    $post_id   Object being acted on.
		 * @param string $post_type Its post type.
		 * @return bool
		 */
		return (bool) apply_filters( 'perflocale/object/user_can', $can, $action, $post_id, $post_type );
	}
}
