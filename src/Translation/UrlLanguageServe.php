<?php
/**
 * Pages served under the URL's language without a translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce links its cart, checkout, my account and terms pages in every language, translated or not; also the
 * fallback page's action, so FallbackResponse loads only where it acts.
 *
 * Cold: loaded only on the missing-translation path.
 *
 * @since 1.0.8
 */
final class UrlLanguageServe {

	/**
	 * WooCommerce system page options (`shop` is an archive, not a missing-translation case).
	 */
	public const WC_PAGES = [
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
	];

	/**
	 * Whether the post is served as it is, skipping the fallback chain and the missing-translation action.
	 *
	 * @param \WP_Post        $post             Post reached without a translation in the URL's language.
	 * @param array<int, int> $translations_map Its translations, language id => post id.
	 * @param string          $language_slug    The URL's language.
	 * @param string          $action           The configured missing-translation action.
	 * @return bool
	 */
	public static function applies( \WP_Post $post, array $translations_map, string $language_slug, string $action ): bool {
		$system = self::wc_system_page( $post, $translations_map );

		/**
		 * Whether a post without a translation in the URL's language is served there, instead of the fallback chain
		 * and the missing-translation action.
		 *
		 * @hook perflocale/fallback/serve_in_url_language
		 * @since 1.0.8
		 *
		 * @param bool     $serve         Default true for WooCommerce's cart, checkout, my account and terms pages.
		 * @param \WP_Post $post          The post reached without a translation.
		 * @param string   $language_slug The URL's language.
		 * @param string   $action        The action: 'show_default', 'show_default_noindex', 'show_404' or 'redirect_default'.
		 * @param string   $reason        'wc_system_page' or 'other'.
		 */
		return (bool) apply_filters( 'perflocale/fallback/serve_in_url_language', $system, $post, $language_slug, $action, $system ? 'wc_system_page' : 'other' );
	}

	/**
	 * The missing-translation action after `perflocale/fallback/action`. FallbackResponse loads only for a hooked filter
	 * or the noindex action: no other fallback page uses it.
	 *
	 * @param \WP_Post $post          Post reached without a published translation in the URL's language.
	 * @param string   $language_slug The URL's language.
	 * @param string   $setting       The setting.
	 * @return string
	 */
	public static function action( \WP_Post $post, string $language_slug, string $setting ): string {
		if ( 'show_default_noindex' !== $setting && ! has_filter( 'perflocale/fallback/action' ) ) {
			return $setting;
		}

		require_once __DIR__ . '/../Frontend/FallbackResponse.php';

		return \PerfLocale\Frontend\FallbackResponse::action( $post, $language_slug, $setting );
	}

	/**
	 * Apply a served fallback's action: only show_default_noindex adds anything.
	 *
	 * @param string $action Effective action (from action()).
	 * @return void
	 */
	public static function serve( string $action ): void {
		if ( 'show_default_noindex' === $action ) {
			require_once __DIR__ . '/../Frontend/FallbackResponse.php';
			\PerfLocale\Frontend\FallbackResponse::noindex();
		}
	}

	/**
	 * A declined own-sibling hop serves the page: as the handler would, WooCommerce system pages stay unmarked.
	 *
	 * @param \WP_Post        $post             Post served.
	 * @param array<int, int> $translations_map Language id => post id.
	 * @param string          $language_slug    The URL's language.
	 * @param string          $setting          The missing-translation setting.
	 * @return void
	 */
	public static function serve_declined( \WP_Post $post, array $translations_map, string $language_slug, string $setting ): void {
		$action = self::action( $post, $language_slug, $setting );

		if ( ! self::applies( $post, $translations_map, $language_slug, $action ) ) {
			self::serve( $action );
		}
	}

	/**
	 * Whether the post or one of its translations is a WooCommerce system page.
	 *
	 * Any member of the group: the add-on's option filter returns the current language's member.
	 *
	 * @param \WP_Post        $post             Post.
	 * @param array<int, int> $translations_map Language id => post id.
	 * @return bool
	 */
	private static function wc_system_page( \WP_Post $post, array $translations_map ): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$group   = array_map( 'intval', array_values( $translations_map ) );
		$group[] = (int) $post->ID;

		// The add-on's per-request set when it is loaded: the same four options, read once per request.
		$ids = class_exists( 'PerfLocaleWooCommerce', false ) ? array_keys( (array) \PerfLocaleWooCommerce::funnel_page_ids() ) : self::wc_page_ids();

		return [] !== array_intersect( $ids, $group );
	}

	/**
	 * The four page IDs as stored (through get_option() filters), without the add-on.
	 *
	 * @return list<int>
	 */
	private static function wc_page_ids(): array {
		// One multi-get for any of the four that is not autoloaded.
		wp_prime_option_caches( self::WC_PAGES );

		$ids = [];

		foreach ( self::WC_PAGES as $option ) {
			$value = get_option( $option );
			$id    = is_numeric( $value ) ? (int) $value : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
