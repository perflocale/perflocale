<?php
/**
 * PerfLocale template tags.
 *
 * Public functions for theme developers to use in templates.
 *
 * @package PerfLocale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal: dev-mode guard that nudges callers who hit a template tag before
 * the plugin container has booted. Returns true (caller should bail with the
 * type-appropriate default) when the call is too early, false otherwise. The
 * `_doing_it_wrong` notice is gated on WP_DEBUG by core, so production is a
 * pure boolean check.
 *
 * @internal
 * @param string $function Calling function name (pass `__FUNCTION__`).
 * @return bool True when the call is too early to proceed.
 */
function perflocale_doing_it_wrong_too_early( string $function ): bool {
	if ( did_action( 'plugins_loaded' ) ) {
		return false;
	}
	_doing_it_wrong(
		esc_html( $function ),
		esc_html__( 'PerfLocale template tags must be called after the plugins_loaded action has fired. Move your call to the init hook (or later).', 'perflocale' ),
		'1.0.0'
	);
	return true;
}

/**
 * Output the language switcher HTML.
 *
 * Echoes the switcher directly. Use perflocale_get_language_switcher()
 * to get the HTML as a string instead.
 *
 * @param array<string, mixed> $args Display arguments.
 * @return void
 */
function perflocale_language_switcher( array $args = [] ): void {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return;
	}
	// perflocale_get_language_switcher() now applies kses_switcher() itself,
	// so its return is already an SVG-aware-sanitized string. The memo
	// keyed by sha1 inside kses_switcher() makes double-wrap calls free,
	// but here we just echo the pre-sanitized return directly.
	echo perflocale_get_language_switcher( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- return value of perflocale_get_language_switcher() is wp_kses'd via LanguageSwitcherBlock::kses_switcher().
}

/**
 * Get the language switcher HTML as a string.
 *
 * @param array<string, mixed> $args Display arguments.
 * @return string HTML output.
 */
function perflocale_get_language_switcher( array $args = [] ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( ! $plugin->has( 'language_switcher' ) ) {
		return '';
	}

	$switcher = $plugin->get( 'language_switcher' );

	return PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $switcher->render( $args ) );
}

/**
 * Get the current language object.
 *
 * @return object|null Language object with properties: slug, locale, name, native_name, etc.
 */
function perflocale_current_language(): ?object {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return null;
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( ! $plugin->has( 'router' ) ) {
		return null;
	}

	return $plugin->get( 'router' )->get_current_language();
}

/**
 * Get all active languages.
 *
 * @return array<int, object> Array of language objects.
 */
function perflocale_get_languages(): array {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return [];
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( ! $plugin->has( 'router' ) ) {
		return [];
	}

	return $plugin->get( 'router' )->get_active_languages();
}

/**
 * Get the translated permalink for a post in a specific language.
 *
 * Accepts whatever `get_permalink()` accepts — a post ID, a WP_Post, or null
 * for the current post — because that is what it hands the value to, and a
 * template that passes `$post` or `get_queried_object()` must not fatal on
 * the way in. Anything that does not resolve to a post yields an empty
 * string, the same as an unknown ID.
 *
 * @param int|WP_Post|null $post_id Post ID, post object, or null for the current post.
 * @param string           $lang_slug Target language slug.
 * @return string Translated permalink.
 */
function perflocale_get_permalink( int|WP_Post|null $post_id, string $lang_slug ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( ! $plugin->has( 'url_converter' ) ) {
		return get_permalink( $post_id ) ?: '';
	}

	$permalink = get_permalink( $post_id );

	if ( ! $permalink ) {
		return '';
	}

	return $plugin->get( 'url_converter' )->convert( $permalink, $lang_slug );
}

/**
 * Get the translated term link for a term in a specific language.
 *
 * Accepts a term ID or a WP_Term, matching `get_term_link()`, which is where
 * the value goes: `get_the_terms()` and `get_queried_object()` both hand a
 * theme WP_Term objects, and a template tag that fataled on one would take
 * the whole page down. A term that cannot be resolved yields an empty string.
 *
 * @param int|WP_Term $term_id Term ID or term object.
 * @param string      $lang_slug Target language slug.
 * @return string Translated term link.
 */
function perflocale_get_term_link( int|WP_Term $term_id, string $lang_slug ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( ! $plugin->has( 'url_converter' ) ) {
		$link = get_term_link( $term_id );
		return is_wp_error( $link ) ? '' : $link;
	}

	$link = get_term_link( $term_id );

	if ( is_wp_error( $link ) ) {
		return '';
	}

	return $plugin->get( 'url_converter' )->convert( $link, $lang_slug );
}

/**
 * Get the home URL for a specific language.
 *
 * @param string $lang_slug Language slug (empty = current language).
 * @param string $path Optional path to append.
 * @return string Language-specific home URL.
 */
function perflocale_home_url( string $lang_slug = '', string $path = '' ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( $lang_slug === '' || ! $plugin->has( 'url_converter' ) ) {
		return home_url( $path );
	}

	return $plugin->get( 'url_converter' )->convert( home_url( $path ), $lang_slug );
}

/**
 * Check if the current language is RTL.
 *
 * @return bool
 */
function perflocale_is_rtl(): bool {
	$lang = perflocale_current_language();

	if ( ! $lang ) {
		return is_rtl();
	}

	return ( $lang->text_direction ?? 'ltr' ) === 'rtl';
}

/**
 * Internal: resolve whichever string-translation service this blog is running.
 *
 * String translation ships as two interchangeable implementations and
 * `Bootstrap::register_frontend_services()` registers exactly ONE of them,
 * picked by the `string_translation_mode` setting: `Strings\StringTranslation`
 * under the id `string_translation` (database mode), and
 * `Strings\TranslationFileLoader` under `translation_file_loader` (files mode
 * - the default, and what a stock install runs). Both expose the same
 * `get_translation( string $text, string $domain, string $context ): ?string`,
 * so a caller only ever needs whichever one is there. Looking up a single id
 * is how perflocale_t() came to be inert on every files-mode site; the id
 * order below is the same walk
 * `PerfLocaleWooCommerce::translate_attribute_label()` makes, so the two
 * cannot drift apart.
 *
 * Returning null is a normal outcome, not an error: the frontend service
 * block is skipped wholesale on plain wp-admin requests, and a site can be
 * mid-mode-switch with neither id yet registered. Callers serve the original
 * string. Nothing here throws - these tags run inside theme templates, where
 * an exception takes the page down instead of losing one translation.
 *
 * @internal
 * @return object|null Service exposing get_translation(), or null when neither id is registered.
 */
function perflocale_string_translation_service(): ?object {
	// Resolve once per request, per blog. perflocale_t() is a loop tag - a
	// theme can call it hundreds of times on one page - and the container
	// walk below measures 0.106us against the 0.020us lookup it guards, so
	// repeating it is the dominant cost of the tag. Keyed by blog id and
	// never a bare static: a static holding blog-affine data across
	// switch_to_blog() is this plugin's most repeated defect, and
	// `string_translation_mode` is a per-blog setting. A MISS is deliberately
	// not memoised, so nothing is pinned to "no service" for the rest of a
	// request that resolved before the services existed - which also keeps
	// the negative path re-checking the container on every call rather than
	// needing a reset hook.
	static $resolved = [];

	$blog_id = get_current_blog_id();

	if ( isset( $resolved[ $blog_id ] ) ) {
		return $resolved[ $blog_id ];
	}

	$plugin = PerfLocale\Plugin::get_instance();

	foreach ( [ 'string_translation', 'translation_file_loader' ] as $service_id ) {
		if ( ! $plugin->has( $service_id ) ) {
			continue;
		}

		$service = $plugin->get( $service_id );

		// Checked, not assumed: either id can be re-registered through the
		// container by an addon or a site, and this tag needs only the one
		// method. Calling it blind would fatal inside a theme template.
		if ( method_exists( $service, 'get_translation' ) ) {
			$resolved[ $blog_id ] = $service;

			return $service;
		}
	}

	return null;
}

/**
 * Get a translated string by key from the PerfLocale string translations.
 *
 * Looks up translations directly from PerfLocale's string translation
 * engine rather than through WordPress gettext. This avoids using variable
 * parameters in __() / _x() which would violate i18n guidelines.
 *
 * Works in both string-translation modes: it asks
 * {@see perflocale_string_translation_service()} for whichever service this
 * blog registered instead of assuming the database-mode one, and returns the
 * key untouched when neither is available.
 *
 * @param string $key String key / original text.
 * @param string $context Optional context.
 * @return string Translated string (or original if no translation found).
 */
function perflocale_t( string $key, string $context = '' ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return $key;
	}

	$service = perflocale_string_translation_service();

	if ( $service === null ) {
		return $key;
	}

	$translated = $service->get_translation( $key, 'perflocale', $context );

	// Type-checked as well as null-checked. Both shipped implementations
	// return null for "no translation" and never an empty string, so this is
	// a no-op for them; it matters because the service comes out of a
	// container an addon can re-register, and this function is declared
	// `: string` - a non-string from a replacement service would surface in a
	// theme as a TypeError on the way out rather than as a missing
	// translation. An empty string is treated as "no translation" for the
	// same reason both implementations already do: a template must not
	// silently render nothing.
	if ( is_string( $translated ) && $translated !== '' ) {
		return $translated;
	}

	return $key;
}

/**
 * Whether content should display for the current language.
 *
 * Accepts any of:
 * - Single slug: perflocale_if_language( 'bg' )
 * - Comma-separated string: perflocale_if_language( 'bg,de' )
 * - Array of slugs: perflocale_if_language( [ 'bg', 'de' ] )
 *
 * Pass `$invert=true` to flip the semantics: "show everywhere EXCEPT these".
 *
 * Usage in a theme template:
 *
 * if ( perflocale_if_language( [ 'bg', 'de' ] ) ) {
 * echo 'Shown only to Bulgarian or German visitors.';
 * }
 *
 * This is the canonical entry point shared with the Gutenberg block
 * `perflocale/if-language` and the `[perflocale_if]` shortcode - all three
 * delegate to the same core predicate so semantics can't drift.
 *
 * @param string|array<int,string> $languages One slug, comma-separated string, or array.
 * @param bool                     $invert Invert: true = "NOT in the list".
 * @return bool
 */
function perflocale_if_language( string|array $languages, bool $invert = false ): bool {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return $invert; // Matches inverted-default semantics (show when invert=true and no language matched).
	}
	if ( is_string( $languages ) ) {
		$languages = array_map( 'trim', explode( ',', $languages ) );
	}

	return PerfLocale\Frontend\ConditionalContent::should_show( $languages, $invert );
}

/**
 * Get the date format for the current language.
 *
 * Falls back to the site's `date_format` option when the language has no
 * override.
 *
 * @return string PHP date format string (e.g. "j F Y").
 */
function perflocale_date_format(): string {
	return PerfLocale\Helper::get_instance()->date_format();
}

/**
 * Get the time format for the current language. Falls back to site default.
 *
 * @return string
 */
function perflocale_time_format(): string {
	return PerfLocale\Helper::get_instance()->time_format();
}

/**
 * Render a timestamp using the current language's date format.
 *
 * @param int|string|null $timestamp Unix timestamp, parseable string, or null for now.
 * @return string
 */
function perflocale_format_date( $timestamp = null ): string {
	return PerfLocale\Helper::get_instance()->format_date( $timestamp );
}

/**
 * Render a timestamp using the current language's time format.
 *
 * @param int|string|null $timestamp
 * @return string
 */
function perflocale_format_time( $timestamp = null ): string {
	return PerfLocale\Helper::get_instance()->format_time( $timestamp );
}

/**
 * Render a timestamp using the current language's combined datetime format.
 *
 * @param int|string|null $timestamp
 * @return string
 */
function perflocale_format_datetime( $timestamp = null ): string {
	return PerfLocale\Helper::get_instance()->format_datetime( $timestamp );
}

/**
 * Get the current page's URL in another language.
 *
 * Returns the canonical translated URL the language switcher uses;
 * empty string if there is no translation in the target language on
 * the current singular page (callers can fall back to
 * {@see perflocale_home_url()} or render an "untranslated" UI).
 *
 * @param string $lang_slug Target language slug.
 * @return string
 */
function perflocale_current_url( string $lang_slug ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	return PerfLocale\Helper::get_instance()->current_url( $lang_slug );
}

/**
 * Build a structured list of switcher items for the current page.
 *
 * See {@see PerfLocale\Helper::switcher_links()} for the entry shape.
 *
 * @return array<int, array{slug:string,locale:string,name:string,native_name:string,flag:string,text_direction:string,url:string,is_current:bool,is_translated:bool}>
 */
function perflocale_switcher_links(): array {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return [];
	}
	return PerfLocale\Helper::get_instance()->switcher_links();
}

/**
 * Output hreflang tags manually (if automatic head injection is disabled).
 *
 * @return void
 */
function perflocale_hreflang_tags(): void {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return;
	}
	$plugin = PerfLocale\Plugin::get_instance();

	if ( ! $plugin->has( 'hreflang' ) ) {
		return;
	}

	$plugin->get( 'hreflang' )->output_hreflang();
}
