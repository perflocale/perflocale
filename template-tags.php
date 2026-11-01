<?php
/**
 * PerfLocale template tags for themes.
 *
 * @package PerfLocale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a template tag was called before plugins_loaded; reports it through _doing_it_wrong().
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
 * Output the language switcher HTML; perflocale_get_language_switcher() returns it instead.
 *
 * @param array<string, mixed> $args Display arguments.
 * @return void
 */
function perflocale_language_switcher( array $args = [] ): void {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return;
	}
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
 * Takes a post ID, WP_Post or null (the current post); a numeric string is not an ID for strict_types callers.
 * '' when nothing resolves to a post. A non-empty URL is no proof of a translation: without one the source URL is converted.
 *
 * @since 1.0.7.5 $lang_slug also takes a locale or BCP 47 tag; an unknown or inactive value is reported.
 *
 * @param int|WP_Post|null $post_id Post ID, post object, or null for the current post.
 * @param string           $lang_slug Target language slug, locale or BCP 47 tag.
 * @return string Translated permalink.
 */
function perflocale_get_permalink( int|WP_Post|null $post_id, string $lang_slug ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin    = PerfLocale\Plugin::get_instance();
	$permalink = get_permalink( $post_id );

	if ( ! $permalink || ! $plugin->has( 'url_converter' ) ) {
		return (string) $permalink;
	}

	$lang_slug = perflocale()->url_slug( $lang_slug );

	// Prefer the translation's own permalink: a converted source URL keeps the source slug.
	$post        = get_post( $post_id );
	$groups      = $plugin->has( 'group_repo' ) ? $plugin->get( 'group_repo' ) : null;
	$translation = 0;

	// An unknown or inactive language ('') has no member to link, nor posts to prime.
	if ( $lang_slug !== '' && $post instanceof WP_Post && $groups instanceof PerfLocale\Database\Repository\TranslationGroupRepository ) {
		PerfLocale\Frontend\PermalinkLoopPrimer::prime( $post, $lang_slug );

		foreach ( $groups->get_translations( (int) $post->ID, PerfLocale\Enum\ObjectType::Post ) as $member ) {
			if ( isset( $member->language_slug ) && (string) $member->language_slug === $lang_slug ) {
				$translation = (int) $member->object_id;
				break;
			}
		}
	}

	if ( $post instanceof WP_Post && $translation > 0 && $translation !== (int) $post->ID && is_post_publicly_viewable( $translation ) ) {
		$own = get_permalink( $translation );

		if ( $own ) {
			return $own;
		}
	}

	return $plugin->get( 'url_converter' )->convert( $permalink, $lang_slug );
}

/**
 * Get the translated term link for a term in a specific language.
 *
 * Takes a term ID or a WP_Term; '' for an unresolvable term. Without a translation the source link is converted.
 *
 * @since 1.0.7.5 As perflocale_get_permalink().
 *
 * @param int|WP_Term $term_id Term ID or term object.
 * @param string      $lang_slug Target language slug, locale or BCP 47 tag.
 * @return string Translated term link.
 */
function perflocale_get_term_link( int|WP_Term $term_id, string $lang_slug ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin = PerfLocale\Plugin::get_instance();
	$link   = get_term_link( $term_id );

	if ( is_wp_error( $link ) ) {
		return '';
	}

	if ( ! $plugin->has( 'url_converter' ) ) {
		return $link;
	}

	$lang_slug = perflocale()->url_slug( $lang_slug );

	// As in perflocale_get_permalink(): the translated term's own link carries its own slug.
	$term        = $term_id instanceof WP_Term ? $term_id : get_term( $term_id );
	$groups      = $plugin->has( 'group_repo' ) ? $plugin->get( 'group_repo' ) : null;
	$translation = 0;

	if ( $term instanceof WP_Term && $groups instanceof PerfLocale\Database\Repository\TranslationGroupRepository ) {
		foreach ( $groups->get_translations( (int) $term->term_id, PerfLocale\Enum\ObjectType::Term ) as $member ) {
			if ( isset( $member->language_slug ) && (string) $member->language_slug === $lang_slug ) {
				$translation = (int) $member->object_id;
				break;
			}
		}
	}

	if ( $term instanceof WP_Term && $translation > 0 && $translation !== (int) $term->term_id ) {
		$own = get_term_link( $translation, $term->taxonomy );

		if ( is_string( $own ) && $own !== '' ) {
			return $own;
		}
	}

	return $plugin->get( 'url_converter' )->convert( $link, $lang_slug );
}

/**
 * Get the home URL for a specific language.
 *
 * @since 1.0.7.5 As perflocale_get_permalink().
 *
 * @param string $lang_slug Language slug, locale or BCP 47 tag (empty = current language).
 * @param string $path Optional path to append.
 * @return string Language-specific home URL.
 */
function perflocale_home_url( string $lang_slug = '', string $path = '' ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return '';
	}
	$plugin    = PerfLocale\Plugin::get_instance();
	$lang_slug = $lang_slug === '' ? perflocale()->slug() : perflocale()->url_slug( $lang_slug );

	// Converted even for the current language: query mode and admin leave core's home_url() without it.
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
 * Resolve whichever string-translation service this blog registered (database or files mode).
 *
 * Both ids must be tried (same order as PerfLocaleWooCommerce::translate_attribute_label());
 * null is normal, e.g. on plain wp-admin requests, and callers serve the original string.
 *
 * @internal
 * @return object|null Service exposing get_translation(), or null when neither id is registered.
 */
function perflocale_string_translation_service(): ?object {
	// Memoised per blog (the mode is a per-blog setting); a miss is not memoised,
	// so a call before the services exist does not pin "no service" for the request.
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

		// Checked: an addon can re-register either id, and a blind call would fatal a template.
		if ( method_exists( $service, 'get_translation' ) ) {
			$resolved[ $blog_id ] = $service;

			return $service;
		}
	}

	return null;
}

/**
 * Get a translated string by key from the PerfLocale string translations, in the 'perflocale' domain.
 *
 * Bypasses gettext, so callers need not pass variables to __(); returns the key when nothing is found.
 *
 * @param string                         $key      String key / original text.
 * @param string                         $context  Optional context.
 * @param string|int|WP_Post|object|null $language Optional language; '' for the current one.
 * @return string Translated string (or original if no translation found).
 */
function perflocale_t( string $key, string $context = '', $language = '' ): string {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return $key;
	}

	// The loaded map holds one language only: a named language takes Helper::translate()'s lookup.
	if ( $language !== '' && $language !== null ) {
		return function_exists( 'perflocale' )
			? perflocale()->translate( $key, $language, [ 'context' => $context ] )
			: $key;
	}

	$service = perflocale_string_translation_service();

	// No string service (plain admin, CLI): a with_language() window still translates.
	if ( $service === null ) {
		return perflocale()->translate( $key, '', [ 'context' => $context ] );
	}

	$translated = $service->get_translation( $key, 'perflocale', $context );

	// Type-checked: a re-registered service could return a non-string; '' means no translation.
	if ( is_string( $translated ) && $translated !== '' ) {
		return $translated;
	}

	return $key;
}

/**
 * Whether content should display for the current language.
 *
 * Shares ConditionalContent::should_show() with the if-language block and the [perflocale_if] shortcode.
 *
 * @param string|array<int,string> $languages One slug, comma-separated string, or array.
 * @param bool                     $invert Invert: true = "NOT in the list".
 * @return bool
 */
function perflocale_if_language( string|array $languages, bool $invert = false ): bool {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return false; // Fail closed, as without a current language after plugins_loaded.
	}
	if ( is_string( $languages ) ) {
		$languages = array_map( 'trim', explode( ',', $languages ) );
	}

	return PerfLocale\Frontend\ConditionalContent::should_show( $languages, $invert );
}

/**
 * Get a language's date format, else the site's `date_format` option.
 *
 * @since 1.0.7.5 $lang.
 *
 * @param string|null $lang Language slug or locale (de_DE, de-AT); null for the current language.
 * @return string PHP date format string (e.g. "j F Y").
 */
function perflocale_date_format( ?string $lang = null ): string {
	return PerfLocale\Helper::get_instance()->date_format( $lang );
}

/**
 * Get a language's time format, else the site default.
 *
 * @since 1.0.7.5 $lang.
 *
 * @param string|null $lang Language slug or locale; null for the current language.
 * @return string
 */
function perflocale_time_format( ?string $lang = null ): string {
	return PerfLocale\Helper::get_instance()->time_format( $lang );
}

/**
 * Render a timestamp in a language's date format.
 *
 * @since 1.0.7.5 $lang.
 *
 * @param int|string|DateTimeInterface|null $timestamp Unix timestamp, date string (site time unless it has an offset), or null for now.
 * @param string|null                       $lang      Language slug or locale; null for the current language.
 * @return string
 */
function perflocale_format_date( $timestamp = null, ?string $lang = null ): string {
	return PerfLocale\Helper::get_instance()->format_date( $timestamp, $lang );
}

/**
 * Render a timestamp in a language's time format.
 *
 * @since 1.0.7.5 $lang.
 *
 * @param int|string|DateTimeInterface|null $timestamp See perflocale_format_date().
 * @param string|null                       $lang      Language slug or locale; null for the current language.
 * @return string
 */
function perflocale_format_time( $timestamp = null, ?string $lang = null ): string {
	return PerfLocale\Helper::get_instance()->format_time( $timestamp, $lang );
}

/**
 * Render a timestamp in a language's combined datetime format.
 *
 * @since 1.0.7.5 $lang.
 *
 * @param int|string|DateTimeInterface|null $timestamp See perflocale_format_date().
 * @param string|null                       $lang      Language slug or locale; null for the current language.
 * @return string
 */
function perflocale_format_datetime( $timestamp = null, ?string $lang = null ): string {
	return PerfLocale\Helper::get_instance()->format_datetime( $timestamp, $lang );
}

/**
 * Get the current page's URL in another language, as the switcher uses it.
 *
 * Empty when the current singular page has no translation in that language.
 *
 * @since 1.0.7.5 As perflocale_get_permalink().
 *
 * @param string $lang_slug Target language slug, locale or BCP 47 tag.
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
 * @return array<int, array{slug:string,locale:string,name:string,native_name:string,flag:string,text_direction:string,url:string,is_current:bool,is_translated:bool}>
 */
function perflocale_switcher_links(): array {
	if ( perflocale_doing_it_wrong_too_early( __FUNCTION__ ) ) {
		return [];
	}
	return PerfLocale\Helper::get_instance()->switcher_links();
}

/**
 * Output hreflang tags manually (if automatic head injection is disabled); once per request with wp_head's.
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

/**
 * The copy groups whose stock differs across language copies. Reads only.
 *
 * Same as perflocale()->woocommerce()->stock_drift(): a WP_Error when WooCommerce, the add-on or Inventory Sync is off.
 *
 * @since 1.0.7.4
 *
 * @param array{product_ids?: list<int>, limit?: int} $args `product_ids`: only these products (any
 *        language; 1-500 parent products, not variations; empty: every product, a full scan). `limit` (an int):
 *        stop after this many drifting groups (0: all); it limits the result, not the products scanned. Other keys
 *        are refused.
 * @return list<array{group: int, parent_id: int, members: list<array{product_id: int, parent_id: int, language: string, stock: float}>}>|WP_Error
 */
function perflocale_stock_drift( array $args = [] ): array|WP_Error {
	return perflocale()->woocommerce()->stock_drift( $args );
}

/**
 * Set the stock of each differing language copy to the reference value. Dry run by default.
 *
 * Same as perflocale()->woocommerce()->align_stock(): checks no user capability, like WooCommerce's own stock functions.
 * In this version it always returns a WP_Error (`perflocale_stock_align_off`).
 *
 * @since 1.0.7.4
 *
 * @param array{from?: string|callable, product_ids?: list<int>, dry_run?: bool} $args `from`: 'default', an
 *        active language slug, or a callable returning the value for a group (null skips it).
 * @return array<string, list<array<string, mixed>>>|WP_Error
 */
function perflocale_align_stock( array $args = [] ): array|WP_Error {
	return perflocale()->woocommerce()->align_stock( $args );
}
