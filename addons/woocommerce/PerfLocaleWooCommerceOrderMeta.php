<?php
/**
 * PerfLocale WooCommerce addon: order item meta display, loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variation attribute values in order item meta (order views and e-mails); its own file because every checkout renders them.
 */
final class PerfLocaleWooCommerceOrderMeta {

	/**
	 * Translate variation attribute values in order item meta display.
	 *
	 * @param string         $html  Formatted HTML of item meta.
	 * @param \WC_Order_Item $item  Order item.
	 * @param \Closure       $terms Returns the add-on's TermTranslationManager.
	 * @return string Translated HTML.
	 */
	public static function translate_order_item_meta( string $html, $item, \Closure $terms ): string {
		if ( ! method_exists( $item, 'get_meta_data' ) || $html === '' ) {
			return $html;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $html;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return $html;
		}

		// Skip on default language - meta values are already correct.
		$default = $router->get_default_language();

		if ( $default && $current_slug === $default->slug ) {
			return $html;
		}

		$term_manager = $terms();

		$meta_data = $item->get_meta_data();

		foreach ( $meta_data as $meta ) {
			$data     = $meta->get_data();
			$meta_key = $data['key'] ?? '';
			$value    = $data['value'] ?? '';

			if ( $value === '' ) {
				continue;
			}

			// Attribute meta keys start with "pa_" for taxonomy-based attributes.
			$taxonomy = $meta_key;

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', $value, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				// Value might be the term name, not slug.
				$term = get_term_by( 'name', $value, $taxonomy );
			}

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$translated_id = $term_manager->get_translation_id( $term->term_id, $current_slug );

			if ( $translated_id !== null && $translated_id !== $term->term_id ) {
				$translated_term = get_term( $translated_id );

				if ( $translated_term instanceof \WP_Term && $translated_term->name !== $term->name ) {
					// Replace the escaped original term name with the translated one.
					$escaped_original   = esc_html( $term->name );
					$escaped_translated = esc_html( $translated_term->name );

					// Replace only the first occurrence to avoid unintended matches.
					$pos = strpos( $html, $escaped_original );

					if ( $pos !== false ) {
						$html = substr_replace( $html, $escaped_translated, $pos, strlen( $escaped_original ) );
					}
				}
			}
		}

		return $html;
	}
}
