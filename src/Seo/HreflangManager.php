<?php
/**
 * SEO hreflang manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages SEO-related integrations: Yoast, Rank Math, etc.
 *
 * Disables third-party hreflang output when PerfLocale handles it.
 */
final class HreflangManager {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Strip null values from Rank Math schema data regardless of hreflang
		// setting. Language filtering can cause term lookups to return empty,
		// which Rank Math stores as null in its JSON-LD schema. When Rank Math
		// passes the schema through wp_kses_post_deep(), null values trigger a
		// PHP 8.1+ deprecation in preg_replace().
		if ( class_exists( 'RankMath' ) ) {
			add_filter( 'rank_math/schema/validated_data', [ self::class, 'strip_schema_nulls' ] );
		}

		// Language signalling (og:locale + <html lang>/dir) is independent of
		// hreflang alternates, so it is registered regardless of the hreflang
		// setting. The page's own og:locale is set per SEO plugin by the addons;
		// here we localize the <html lang>/dir attributes and emit the shared,
		// plugin-agnostic og:locale:alternate set for the page's translations.
		if ( $this->settings->get( 'seo_og_locale', true ) ) {
			// Priority 11 runs after default-priority (10) SEO-plugin
			// filters on the same hook, so PerfLocale's lang/dir injection
			// is deterministic regardless of plugin load order.
			add_filter( 'language_attributes', [ $this, 'filter_language_attributes' ], 11 );
			add_action( 'wp_head', [ \PerfLocale\Seo\OgLocale::class, 'emit_alternates' ], 7 );
		}

		// No SEO-plugin hreflang suppression is registered: none of the
		// supported plugins emit head/header hreflang natively, so PerfLocale's
		// own tags never collide with them. Yoast has never output hreflang (it
		// defers to multilingual plugins); Rank Math only emits it through a
		// multilingual integration; AIOSEO 4.x emits none in the head/header
		// (only its sitemap, which PerfLocale wants). The former Yoast/Rank Math
		// `add_filter` calls gated named hooks (`wpseo_output_hreflang`,
		// `rank_math/frontend/disable_hreflang`) that no current version of
		// either plugin fires; the earlier AIOSEO `aioseo_conflicting_shortcodes`
		// hook was actively harmful (it force-executed every shortcode during
		// meta/sitemap generation). All removed.
	}

	/**
	 * Filter the HTML language attributes to include the correct lang.
	 *
	 * @param string $output Language attributes.
	 * @return string Modified attributes.
	 */
	public function filter_language_attributes( string $output ): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $output;
		}

		$current = $plugin->get( 'router' )->get_current_language();

		if ( $current && ! empty( $current->locale ) ) {
			// `<html lang="…">` follows BCP 47 — same canonical form as
			// hreflang (lowercase language + UPPERCASE region).
			$lang     = \PerfLocale\Helper::format_locale_as_bcp47( (string) $current->locale );
			$new_attr = 'lang="' . esc_attr( $lang ) . '"';

			if ( str_contains( $output, 'lang="' ) ) {
				// Replace existing lang attribute safely using string operations.
				$start = strpos( $output, 'lang="' );

				if ( $start !== false ) {
					$end    = strpos( $output, '"', $start + 6 );
					$output = substr( $output, 0, $start ) . $new_attr . substr( $output, $end + 1 );
				}
			} else {
				$output .= ' ' . $new_attr;
			}
		}

		if ( $current && ( $current->text_direction ?? 'ltr' ) === 'rtl' ) {
			if ( ! str_contains( $output, 'dir=' ) ) {
				$output .= ' dir="rtl"';
			}
		}

		return $output;
	}

	/**
	 * Recursively remove null values from Rank Math schema data.
	 *
	 * Prevents PHP 8.1+ deprecation in wp_kses_post_deep() which does
	 * not handle null leaf values.
	 *
	 * @param array<string, mixed> $data Schema data.
	 * @return array<string, mixed> Cleaned data.
	 */
	public static function strip_schema_nulls( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_null( $value ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::strip_schema_nulls( $value );
			}
		}

		return $data;
	}
}
