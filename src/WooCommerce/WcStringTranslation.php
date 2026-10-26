<?php
/**
 * WooCommerce-specific string translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates admin-entered WooCommerce strings (gateway titles, shipping labels) that bypass `__()`.
 *
 * WooCommerce sanitizes these before the hooks run, so a `gettext` translation gets the same sanitizer.
 */
final class WcStringTranslation {

	/**
	 * Markup a translated gateway title may keep: WC_Payment_Gateway::get_title()'s own rules.
	 *
	 * @var array<string, array<string, true>>
	 */
	private const GATEWAY_TITLE_HTML = [
		'br'   => [],
		'img'  => [
			'alt'   => true,
			'class' => true,
			'src'   => true,
			'title' => true,
		],
		'p'    => [
			'class' => true,
		],
		'span' => [
			'class' => true,
			'title' => true,
		],
	];

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param CacheManager   $cache Cache manager.
	 */
	public function __construct( LanguageRouter $router, CacheManager $cache ) {
		$this->router = $router;
	}

	/**
	 * Register WooCommerce string translation hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Payment gateway titles and descriptions stored in WooCommerce settings.
		add_filter( 'woocommerce_gateway_title', [ $this, 'translate_with_id' ], 20, 2 );
		add_filter( 'woocommerce_gateway_description', [ $this, 'translate_with_id' ], 20, 2 );

		// Shipping rate labels shown at checkout and cart.
		add_filter( 'woocommerce_shipping_rate_label', [ $this, 'translate_plain' ], 20 );
		add_filter( 'woocommerce_cart_shipping_method_full_label', [ $this, 'translate_plain' ], 20 );
	}

	/**
	 * Translate a string that provides a secondary identifier (gateway ID, etc.).
	 *
	 * @param string $text String to translate.
	 * @param mixed  $id Gateway or method identifier (passed to filter).
	 * @return string
	 */
	public function translate_with_id( string $text, mixed $id = '' ): string {
		return $this->translate( $text, (string) $id );
	}

	/**
	 * Translate a plain string with no secondary identifier.
	 *
	 * @param string $text String to translate.
	 * @return string
	 */
	public function translate_plain( string $text ): string {
		return $this->translate( $text, '' );
	}

	/**
	 * Attempt to translate a WooCommerce string.
	 *
	 * @param string $text Original text.
	 * @param string $context Extra context (gateway ID, etc.).
	 * @return string Translated text or original if no translation found.
	 */
	private function translate( string $text, string $context ): string {
		if ( $text === '' ) {
			return $text;
		}

		$slug = $this->router->get_current_slug();

		if ( $slug === '' ) {
			return $text;
		}

		/**
		 * @hook perflocale/woocommerce/translate_string
		 * Override WooCommerce string translation for a specific language.
		 *
		 * @param string|null $translated Null to continue, string to override.
		 * @param string $text Original text.
		 * @param string $slug Current language slug.
		 * @param string $context Gateway ID or empty string.
		 */
		$override = apply_filters( 'perflocale/woocommerce/translate_string', null, $text, $slug, $context );

		if ( is_string( $override ) && $override !== '' ) {
			return $override;
		}

		// StringTranslation answers on `gettext` for strings registered in String Translation.
		$translated = (string) apply_filters( 'gettext', $text, $text, 'woocommerce' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core 'gettext' filter.

		if ( $translated !== $text ) {
			return $this->sanitize_for_slot( $translated );
		}

		return $text;
	}

	/**
	 * Pass a translation through the sanitizer WooCommerce applies on the running hook.
	 *
	 * A full shipping label's translation is label text, without WooCommerce's price markup: plain text.
	 * Descriptions keep the translation as found: stored translations are already wp_kses_post-filtered.
	 *
	 * @param string $translated Translation found for the original text.
	 * @return string
	 */
	private function sanitize_for_slot( string $translated ): string {
		return match ( current_filter() ) {
			'woocommerce_gateway_title' => wp_kses( force_balance_tags( $translated ), self::GATEWAY_TITLE_HTML ),
			'woocommerce_shipping_rate_label', 'woocommerce_cart_shipping_method_full_label' => sanitize_text_field( $translated ),
			default => $translated,
		};
	}
}
