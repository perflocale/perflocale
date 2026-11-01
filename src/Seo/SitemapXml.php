<?php
/**
 * Sitemap urlset XML with xhtml:link hreflang alternates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SitemapRenderer's urlset body, loaded only when a sitemap page renders (core builds the renderer on every request).
 *
 * @internal
 */
final class SitemapXml {

	/**
	 * Render the XML body for a sitemap page (urlset), declaring `xmlns:xhtml` on the root.
	 *
	 * @param array<int, array<string, mixed>> $url_list   Array of URL items.
	 * @param string                           $stylesheet The renderer's stylesheet processing instruction.
	 * @return string XML body.
	 */
	public static function urlset( array $url_list, string $stylesheet ): string {
		// No SimpleXML guard: the inherited render_sitemap() checks for it and stops first.
		$urlset = new \SimpleXMLElement(
			sprintf(
				'%1$s%2$s%3$s',
				'<?xml version="1.0" encoding="UTF-8" ?>',
				$stylesheet,
				'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" />'
			)
		);

		foreach ( $url_list as $url_item ) {
			$url = $urlset->addChild( 'url' );

			foreach ( $url_item as $name => $value ) {
				if ( 'loc' === $name ) {
					$url->addChild( $name, esc_url( (string) $value ) );
				} elseif ( in_array( $name, array( 'lastmod', 'changefreq', 'priority' ), true ) ) {
					$url->addChild( $name, esc_xml( (string) $value ) );
				} elseif ( 'xhtml:link' === $name && is_array( $value ) ) {
					foreach ( $value as $link ) {
						if ( empty( $link['href'] ) || empty( $link['hreflang'] ) ) {
							continue;
						}

						$link_node = $url->addChild( 'link', '', 'http://www.w3.org/1999/xhtml' );
						$link_node->addAttribute( 'rel', 'alternate' );
						$link_node->addAttribute( 'hreflang', (string) $link['hreflang'] );
						// Not esc_url(): addAttribute() escapes '&' itself, so &#038; would double-escape.
						$link_node->addAttribute( 'href', esc_url_raw( (string) $link['href'] ) );
					}
				}
				// Unknown keys are dropped, as in the parent.
			}
		}

		$xml = $urlset->asXML();

		return is_string( $xml ) ? $xml : '';
	}
}
