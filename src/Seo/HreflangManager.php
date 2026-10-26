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
 * Registers the language attributes, og:locale alternates and the Rank Math schema fix.
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
		// Language filtering can leave nulls in Rank Math's schema, which wp_kses_post_deep() deprecates on.
		if ( class_exists( 'RankMath' ) ) {
			add_filter( 'rank_math/schema/validated_data', [ self::class, 'strip_schema_nulls' ] );
		}

		// Independent of the hreflang setting.
		if ( $this->settings->get( 'seo_og_locale', true ) ) {
			// Priority 11: after SEO plugins' filters, whatever the load order.
			add_filter( 'language_attributes', [ $this, 'filter_language_attributes' ], 11 );
			add_action( 'wp_head', [ \PerfLocale\Seo\OgLocale::class, 'emit_alternates' ], 7 );
		}

		// No SEO-plugin hreflang suppression: see docs-ai/11-code-rationale.md, "HreflangManager: no hreflang suppression".
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
			// BCP 47, the same form as hreflang.
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
