<?php
/**
 * RTL stylesheet twins.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stylesheet swap of Helper::register_rtl_styles(), loaded only on a right-to-left page.
 */
final class RtlStyles {

	/**
	 * Point every `perflocale*` stylesheet at its shipped `-rtl.css` twin.
	 *
	 * @param bool $core_swaps Whether core's is_rtl() holds, so core does the swap given the `rtl` data.
	 * @return void
	 */
	public static function swap( bool $core_swaps ): void {
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
}
