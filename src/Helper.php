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
	 * Only a found language: before parse_request detection there may be none yet.
	 *
	 * @var object|null
	 */
	private static ?object $current_language_memo = null;

	/**
	 * Post meta flag ('yes') that removes a translation from hreflang and sitemap alternates.
	 *
	 * @var string
	 */
	public const SEO_EXCLUDE_META = '_perflocale_seo_exclude';

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

		// Loaded classes only: a reset must not load them.
		foreach ( [ Util\FormatLocale::class, Util\SeoExclusion::class, Util\LanguageValue::class ] as $class ) {
			if ( class_exists( $class, false ) ) {
				$class::reset();
			}
		}
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
	 * The set of post IDs carrying the SEO-exclude flag, as id => true; read once per blog and request.
	 *
	 * @return array<int, true>
	 * @see Util\SeoExclusion::ids()
	 */
	public static function seo_excluded_post_ids(): array {
		return Util\SeoExclusion::ids();
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
			return self::$current_language_memo;
		}

		try {
			$lang = $this->safe_router()?->get_current_language();
		} catch ( \Throwable $e ) {
			// A template asking for the language must never fatal.
			$lang = null;
		}

		self::$current_language_memo = $lang;

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
	 * @since 1.0.7.5 BCP 47 tags (fr-FR) and locales in any case.
	 *
	 * @param string|int|\WP_Post|object|null $language Slug, locale or tag, post or post ID, language row, or null/'' for no change.
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

		$previous = $router->get_current_language();

		try {
			$router->override_current_language( $target );

			return $callback();
		} finally {
			// In finally, entry included: a throw (a listener's too) must not leave the request in the imposed language.
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
	 * @since 1.0.7.5 BCP 47 tags; an unknown slug or locale is reported (debug only).
	 *
	 * @param string                          $text     Source text, exactly as registered.
	 * @param string|int|\WP_Post|object|null $language Slug, locale or tag, post or post ID, language row, or ''/null for the current one.
	 * @param array<string, mixed>            $args     Optional `domain`, `context`, and `default` (defaults to $text).
	 * @return string The translation, or the fallback.
	 */
	public function translate( string $text, $language = '', array $args = [] ): string {
		$fallback = array_key_exists( 'default', $args ) ? (string) $args['default'] : $text;

		if ( $text === '' ) {
			return $fallback;
		}

		try {
			$language_row     = $this->resolve_language( $language ) ?? $this->current_language();
			$default_language = $this->default_language();

			// The default language returns the source before any query: it is the common case.
			if (
				$language_row === null
				|| ( $default_language !== null && ( $language_row->slug ?? null ) === ( $default_language->slug ?? null ) )
			) {
				return $fallback;
			}

			$translation = Util\StringLookup::find(
				isset( $args['domain'] ) ? (string) $args['domain'] : 'perflocale',
				isset( $args['context'] ) ? (string) $args['context'] : '',
				$text,
				$language_row
			);

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
			return $repo->find_by_slug( $language ) ?? Util\LanguageValue::by_locale( $repo, $language );
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
	 * Get the current language's stored flag code (e.g. "us"); {@see self::flag_emoji()} for the emoji.
	 *
	 * @return string
	 */
	public function flag(): string {
		return $this->current_language()?->flag ?? '';
	}

	/**
	 * A language's flag emoji, as the switcher shows it.
	 *
	 * @since 1.0.7.5
	 *
	 * @param string|null $lang Language slug or locale; null for the current language.
	 * @return string Emoji, or '' for an unknown language.
	 */
	public function flag_emoji( ?string $lang = null ): string {
		return Util\FormatLocale::flag_emoji( $lang );
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
	 * Check if the current language is RTL; without one, core's is_rtl() (as perflocale_is_rtl()).
	 *
	 * @return bool
	 */
	public function is_rtl(): bool {
		$lang = $this->current_language();

		return $lang ? ( $lang->text_direction ?? 'ltr' ) === 'rtl' : is_rtl();
	}

	/**
	 * Whether the current language is the one a slug, locale or BCP 47 tag names.
	 *
	 * @since 1.0.7.5 Locales and tags; $match_base.
	 *
	 * @param string $slug       Language slug, locale or BCP 47 tag (fr, fr_FR, fr-FR).
	 * @param bool   $match_base Also match a language with the same base language ('fr' matches fr_CA).
	 * @return bool
	 */
	public function is_language( string $slug, bool $match_base = false ): bool {
		$now = $this->slug();

		return $now === $slug || ( $now && ( $match_base ? Util\LanguageValue::slug( $slug, true ) : $this->url_slug( $slug ) ) === $now );
	}

	/**
	 * The active slug a slug, locale or BCP 47 tag names, else '' (reported once).
	 *
	 * @internal For the URL template tags; an active slug as written needs no other lookup.
	 * @param string $value Language value.
	 * @return string
	 */
	public function url_slug( string $value ): string {
		return isset( Plugin::get_instance()->get( 'lang_repo' )->get_slug_map()[ $value ] ) ? $value : Util\LanguageValue::slug( $value );
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
	 * @param int|\WP_Post|null $post_id   Post ID, post object, or null for the current post.
	 * @param string            $lang_slug Target language slug, locale or BCP 47 tag.
	 * @return string
	 */
	public function permalink( int|\WP_Post|null $post_id, string $lang_slug ): string {
		return perflocale_get_permalink( $post_id, $lang_slug );
	}

	/**
	 * Get a translated term link.
	 *
	 * @param int|\WP_Term $term_id   Term ID or term object.
	 * @param string       $lang_slug Target language slug, locale or BCP 47 tag.
	 * @return string
	 */
	public function term_link( int|\WP_Term $term_id, string $lang_slug ): string {
		return perflocale_get_term_link( $term_id, $lang_slug );
	}

	/**
	 * Get a language-specific home URL.
	 *
	 * @param string $lang_slug Language slug, locale or BCP 47 tag (empty = current).
	 * @param string $path Optional path.
	 * @return string
	 */
	public function home_url( string $lang_slug = '', string $path = '' ): string {
		return perflocale_home_url( $lang_slug, $path );
	}

	/**
	 * Render the language switcher.
	 *
	 * @since 1.0.7.5 untranslated_link, include and exclude.
	 *
	 * @param array<string, mixed> $args Display arguments, as the [perflocale_switcher] shortcode's.
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
	 * @param string $lang_slug Target language slug, locale or BCP 47 tag; an unknown value is reported.
	 * @return string Translated URL, or empty string when none is available.
	 */
	public function current_url( string $lang_slug ): string {
		$plugin = Plugin::get_instance();

		if ( $lang_slug === '' || ! $plugin->has( 'url_converter' ) ) {
			return '';
		}

		$urls = $plugin->get( 'url_converter' )->get_translations_for_current_page();

		return (string) ( $urls[ $this->url_slug( $lang_slug ) ] ?? '' );
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
	 * Get all translations of a post, drafts and private posts included unless $public_only.
	 *
	 * @since 1.0.7.5 $public_only.
	 *
	 * @param int  $post_id     Post ID.
	 * @param bool $public_only Only published members of a viewable post type (core visibility: not passwords, other
	 *                          plugins' access rules or the current user's rights).
	 * @return array<string, int> [language_slug => post_id] map.
	 */
	public function translations( int $post_id, bool $public_only = false ): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return [];
		}

		$manager = new Translation\PostTranslationManager(
			$plugin->get( 'cache' ),
			$plugin->get( 'settings' )
		);

		$map = $manager->get_translations( $post_id );

		return $public_only ? Util\PublicTranslations::filter( [ $map ] )[0] : $map;
	}

	/**
	 * Batch version of {@see translations()} for loops: at most two queries for any number of posts.
	 *
	 * Every input id gets a key; an unresolvable post maps to an empty array.
	 *
	 * @since 1.0.7.5 $public_only.
	 *
	 * @param array<int, int> $post_ids    List of post IDs.
	 * @param bool            $public_only As in {@see self::translations()}; reads the members in one more query.
	 * @return array<int, array<string, int>> source_id => [language_slug => post_id]
	 */
	public function translations_many( array $post_ids, bool $public_only = false ): array {
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

		return $public_only ? Util\PublicTranslations::filter( $out ) : $out;
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
	 * intl output can hold no-break spaces (U+00A0, U+202F).
	 *
	 * @since 1.0.7.5 $lang_slug also takes a locale or BCP 47 tag.
	 *
	 * @param float|int   $value     Number to format.
	 * @param string|null $lang_slug Language slug or locale (de_DE, de-AT); null for the current language.
	 * @param int|null    $decimals  Optional decimal-place override.
	 * @return string Formatted number.
	 */
	public function format_number( $value, ?string $lang_slug = null, ?int $decimals = null ): string {
		return Util\FormatLocale::number( $value, $lang_slug, $decimals, __METHOD__ );
	}

	/**
	 * Locale-aware currency formatting through intl NumberFormatter's CURRENCY style.
	 *
	 * Without intl: "{code} {number}" with two decimals, never a guessed symbol.
	 *
	 * @since 1.0.7.5 $lang_slug also takes a locale or BCP 47 tag.
	 *
	 * @param float|int   $value         Amount to format.
	 * @param string      $currency_code ISO 4217 code (USD, EUR, JPY, …).
	 * @param string|null $lang_slug     Language slug or locale (de_DE, de-AT); null for the current language.
	 * @return string Formatted amount with currency symbol.
	 */
	public function format_currency( $value, string $currency_code, ?string $lang_slug = null ): string {
		return Util\FormatLocale::currency( $value, $currency_code, $lang_slug, __METHOD__ );
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
	 * @param string $feature Dispatch surface ('webhooks'), so a filter can allow one surface only.
	 * @return bool True when dispatch may proceed.
	 * @see Util\OutboundUrl::is_outbound_dispatch_allowed()
	 */
	public static function is_outbound_dispatch_allowed( string $feature ): bool {
		return Util\OutboundUrl::is_outbound_dispatch_allowed( $feature );
	}

	/**
	 * Default writing direction for a locale, keyed on its primary subtag.
	 *
	 * @param string $locale Locale string (`ar`, `he_IL`, `ckb-IR`, etc.).
	 * @return string 'rtl' or 'ltr'.
	 * @see Util\LanguageForm::default_text_direction()
	 */
	public static function default_text_direction( string $locale ): string {
		return Util\LanguageForm::default_text_direction( $locale );
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
	 * @param string $option Screen-option name, for the filter.
	 * @return int
	 * @see Util\ListScreen::per_page_ceiling()
	 */
	public static function per_page_ceiling( string $option = '' ): int {
		return Util\ListScreen::per_page_ceiling( $option );
	}

	/**
	 * Clamp an effective rows-per-page value.
	 *
	 * @param int    $value   Raw stored value.
	 * @param int    $default Fallback when unset/invalid.
	 * @param string $option  Screen-option name, for the filter.
	 * @return int
	 * @see Util\ListScreen::normalize_per_page()
	 */
	public static function normalize_per_page( int $value, int $default = 20, string $option = '' ): int {
		return Util\ListScreen::normalize_per_page( $value, $default, $option );
	}

	/**
	 * One line naming post counts by post type, e.g. "Products: 20, Posts: 4".
	 *
	 * @param array<string, int> $counts Post type => count.
	 * @return string
	 * @see Util\ListScreen::post_type_counts_summary()
	 */
	public static function post_type_counts_summary( array $counts ): string {
		return Util\ListScreen::post_type_counts_summary( $counts );
	}

	/**
	 * Shorten an already-sanitised slug to `$length` bytes without cutting inside a percent-escape.
	 *
	 * @param string $slug   Slug, already sanitize_title()'d.
	 * @param int    $length Maximum length to keep.
	 * @return string Shortened slug, or the input when it already fits.
	 * @see Util\WriteText::truncate_slug()
	 */
	public static function truncate_slug( string $slug, int $length ): string {
		return Util\WriteText::truncate_slug( $slug, $length );
	}

	/**
	 * Sanitise plain text like sanitize_text_field() but without deleting percent-escapes ("100%25 off").
	 *
	 * @param mixed $str           Raw value; a scalar is cast to a string, anything else yields ''.
	 * @param bool  $keep_newlines Keep line breaks (excerpt) or collapse them (title).
	 * @return string Sanitised text.
	 * @see Util\WriteText::sanitize_plain_text()
	 */
	public static function sanitize_plain_text( $str, bool $keep_newlines = false ): string {
		return Util\WriteText::sanitize_plain_text( $str, $keep_newlines );
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
		return Util\WriteText::sanitize_plain_text( $value, false );
	}

	/**
	 * Multi-line variant for excerpts, shaped like {@see self::sanitize_plain_text_field()}.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_plain_textarea_field( $value ): string {
		return Util\WriteText::sanitize_plain_text( $value, true );
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
	 * @param string $ip Candidate address.
	 * @return bool
	 * @see Util\OutboundUrl::is_ipv6()
	 */
	public static function is_ipv6( string $ip ): bool {
		return Util\OutboundUrl::is_ipv6( $ip );
	}

	/**
	 * True when `$ip` (IPv4 or IPv6) is public, as filter_var's NO_PRIV_RANGE | NO_RES_RANGE judges it.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function is_public_ipv4( string $ip ): bool {
		return Util\IpAddress::is_public_ipv4( $ip );
	}

	/**
	 * True when an outbound request (webhook, machine translation) may connect to `$ip`.
	 *
	 * @param string $ip Candidate address.
	 * @return bool
	 * @see Util\OutboundUrl::is_public_outbound_address()
	 */
	public static function is_public_outbound_address( string $ip ): bool {
		return Util\OutboundUrl::is_public_outbound_address( $ip );
	}

	/**
	 * Run wp_remote_request() pinned to addresses the caller already judged, against DNS rebinding.
	 *
	 * @param string       $url  Request URL.
	 * @param array<mixed> $args wp_remote_request() arguments.
	 * @param string       $ip   Address to connect to; '' for none.
	 * @param array<mixed> $more Further judged addresses of the same host, listed after `$ip`.
	 * @return array<string, mixed>|\WP_Error wp_remote_request()'s result.
	 * @see Util\OutboundUrl::remote_request_pinned()
	 */
	public static function remote_request_pinned( string $url, array $args, string $ip, array $more = [] ): array|\WP_Error {
		return Util\OutboundUrl::remote_request_pinned( $url, $args, $ip, $more );
	}

	/**
	 * True when `$url` parses as a syntactically valid URL; uses filter_var when it exists.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 * @see Util\OutboundUrl::is_valid_url()
	 */
	public static function is_valid_url( string $url ): bool {
		return Util\OutboundUrl::is_valid_url( $url );
	}

	/**
	 * Exact native replacement for `filter_var( $v, FILTER_VALIDATE_BOOLEAN )`.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 * @see Util\WriteText::to_bool()
	 */
	public static function to_bool( $value ): bool {
		return Util\WriteText::to_bool( $value );
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
	 * @see Util\LanguageForm::region_code_from_flag_emoji()
	 */
	public static function region_code_from_flag_emoji( string $emoji ): string {
		return Util\LanguageForm::region_code_from_flag_emoji( $emoji );
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
	 * @see Util\Uploads::filesystem()
	 */
	public static function filesystem(): ?\WP_Filesystem_Base {
		return Util\Uploads::filesystem();
	}

	/**
	 * Absolute path of the plugin's per-blog uploads directory, `uploads/perflocale`.
	 *
	 * @return string Absolute path WITHOUT trailing slash.
	 * @see Util\Uploads::uploads_base_dir()
	 */
	public static function uploads_base_dir(): string {
		return Util\Uploads::uploads_base_dir();
	}

	/**
	 * Generated translation files dir, `uploads/perflocale/translations/`.
	 *
	 * @return string
	 * @see Util\Uploads::uploads_translations_dir()
	 */
	public static function uploads_translations_dir(): string {
		return Util\Uploads::uploads_translations_dir();
	}

	/**
	 * Scratch dir for in-flight imports, `uploads/perflocale/temp/`.
	 *
	 * @return string
	 * @see Util\Uploads::uploads_temp_dir()
	 */
	public static function uploads_temp_dir(): string {
		return Util\Uploads::uploads_temp_dir();
	}

	/**
	 * Export bundle dir, `uploads/perflocale/exports/`.
	 *
	 * @return string
	 * @see Util\Uploads::uploads_exports_dir()
	 */
	public static function uploads_exports_dir(): string {
		return Util\Uploads::uploads_exports_dir();
	}

	/**
	 * Daily backstop sweep of old temp and export files whose own delete event never ran.
	 *
	 * @param int $max_age_days Files older than this (default: 7) are removed.
	 * @return int Number of files deleted.
	 * @see Util\Uploads::gc_stale_upload_files()
	 */
	public static function gc_stale_upload_files( int $max_age_days = 7 ): int {
		return Util\Uploads::gc_stale_upload_files( $max_age_days );
	}

	/**
	 * Ensure the plugin's base uploads dir exists and is hardened; subdirs harden themselves on creation.
	 *
	 * @return void
	 * @see Util\Uploads::ensure_uploads_base_dir()
	 */
	public static function ensure_uploads_base_dir(): void {
		Util\Uploads::ensure_uploads_base_dir();
	}

	/**
	 * Write `.htaccess` (Util\Uploads::HTACCESS) and `index.php` into a plugin-owned directory, once each.
	 *
	 * @param string $dir Absolute filesystem path to an existing directory.
	 * @return void
	 * @see Util\Uploads::harden_directory()
	 */
	public static function harden_directory( string $dir ): void {
		Util\Uploads::harden_directory( $dir );
	}

	/**
	 * Date format for a language, with site-default fallback.
	 *
	 * @since 1.0.7.5 $lang.
	 *
	 * @param string|null $lang Language slug or locale (de_DE, de-AT); null for the current language.
	 * @return string
	 */
	public function date_format( ?string $lang = null ): string {
		return Util\FormatLocale::date_format( $lang, __METHOD__ );
	}

	/**
	 * Time format for a language. See {@see self::date_format()}.
	 *
	 * @since 1.0.7.5 $lang.
	 *
	 * @param string|null $lang Language slug or locale; null for the current language.
	 * @return string
	 */
	public function time_format( ?string $lang = null ): string {
		return Util\FormatLocale::time_format( $lang, __METHOD__ );
	}

	/**
	 * Combined date+time format for a language.
	 *
	 * @since 1.0.7.5 $lang.
	 *
	 * @param string|null $lang Language slug or locale; null for the current language.
	 * @return string
	 */
	public function datetime_format( ?string $lang = null ): string {
		return Util\FormatLocale::datetime_format( $lang, __METHOD__ );
	}

	/**
	 * Render a timestamp with wp_date() in a language's date format and month names.
	 *
	 * @since 1.0.7.5 $lang.
	 *
	 * @param int|string|\DateTimeInterface|null $timestamp Unix timestamp, date string (site time unless it has an offset), or null for now.
	 * @param string|null                        $lang      Language slug or locale; null for the current language.
	 * @return string Formatted date, or empty string on parse failure.
	 */
	public function format_date( $timestamp = null, ?string $lang = null ): string {
		return Util\FormatLocale::timestamp( $timestamp, $this->date_format( $lang ), $lang );
	}

	/**
	 * Render a timestamp using a language's time format.
	 *
	 * @since 1.0.7.5 $lang.
	 *
	 * @param int|string|\DateTimeInterface|null $timestamp See {@see self::format_date()}.
	 * @param string|null                        $lang      Language slug or locale; null for the current language.
	 * @return string
	 */
	public function format_time( $timestamp = null, ?string $lang = null ): string {
		return Util\FormatLocale::timestamp( $timestamp, $this->time_format( $lang ), $lang );
	}

	/**
	 * Render a timestamp using a language's combined datetime format.
	 *
	 * @since 1.0.7.5 $lang.
	 *
	 * @param int|string|\DateTimeInterface|null $timestamp See {@see self::format_date()}.
	 * @param string|null                        $lang      Language slug or locale; null for the current language.
	 * @return string
	 */
	public function format_datetime( $timestamp = null, ?string $lang = null ): string {
		return Util\FormatLocale::timestamp( $timestamp, $this->datetime_format( $lang ), $lang );
	}

	/**
	 * Resolve the date format for a language: its own column, else the site's date_format option.
	 *
	 * @since 1.0.7.5 $lang also takes a locale or BCP 47 tag.
	 *
	 * @param object|int|string|null $lang Language row, language ID (not a post ID), slug, locale, or null for the current language.
	 * @return string A `wp_date()`-compatible format string.
	 */
	public static function date_format_for_language( $lang = null ): string {
		return Util\FormatLocale::date_format( $lang, __METHOD__ );
	}

	/**
	 * Resolve the time format for a language, as {@see self::date_format_for_language()} does.
	 *
	 * @param object|int|string|null $lang
	 * @return string
	 */
	public static function time_format_for_language( $lang = null ): string {
		return Util\FormatLocale::time_format( $lang, __METHOD__ );
	}

	/**
	 * Date format plus time format for the same language.
	 *
	 * @param object|int|string|null $lang
	 * @return string
	 */
	public static function datetime_format_for_language( $lang = null ): string {
		return Util\FormatLocale::datetime_format( $lang, __METHOD__ );
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

		Util\RtlStyles::swap( $core_swaps );
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
		return Util\ObjectPermission::check( 'edit', 'edit_post', $post_id, $user_id );
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
		return Util\ObjectPermission::check( 'delete', 'delete_post', $post_id );
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
		return Util\ObjectPermission::check( 'read', 'read_post', $post_id )
			|| Util\ObjectPermission::check( 'edit', 'edit_post', $post_id );
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
		return Util\ObjectPermission::check( 'edit', 'edit_post', $post_id )
			&& Util\ObjectPermission::check( 'read', 'read_post', $post_id );
	}

	/**
	 * Whether the current user may create a new post of this post's type (its `create_posts`), host-aware.
	 *
	 * @param int $post_id Post the new translation would be copied from.
	 * @return bool False when the type is not registered or has no create capability name.
	 * @see Util\ObjectPermission::create_like()
	 */
	public static function user_can_create_like( int $post_id ): bool {
		return Util\ObjectPermission::create_like( $post_id );
	}

	/**
	 * Whether the current user may create a term in `$term_id`'s taxonomy, as core rules a new term.
	 *
	 * @param int $term_id Term the new translation would be copied from.
	 * @return bool False when the term or its taxonomy does not exist.
	 * @see Util\ObjectPermission::create_term_like()
	 */
	public static function user_can_create_term_like( int $term_id ): bool {
		return Util\ObjectPermission::create_term_like( $term_id );
	}
}
