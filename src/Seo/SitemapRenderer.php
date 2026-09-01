<?php
/**
 * PerfLocale sitemap renderer with xhtml:link hreflang support.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends WordPress core's sitemap renderer to support `xhtml:link` alternate
 * hreflang entries inside `<url>` elements.
 *
 * WP core's default renderer (`WP_Sitemaps_Renderer::get_sitemap_xml()`) only
 * accepts `loc`, `lastmod`, `changefreq`, and `priority` keys on each entry
 * and drops anything else with a `_doing_it_wrong` notice. This renderer
 * additionally accepts `xhtml:link` - an array of `[ href, hreflang ]`
 * items - and serializes each one as a child element:
 *
 * <xhtml:link rel="alternate" hreflang="de" href="https://…/de/…"/>
 *
 * This is the pattern documented at sitemaps.org and required by Google
 * Search Central for hreflang-in-sitemap signalling.
 */
final class SitemapRenderer extends \WP_Sitemaps_Renderer {

	/**
	 * Render the XML body for a sitemap page (urlset).
	 *
	 * Overrides the parent which doesn't know about xhtml:link. We build the
	 * same SimpleXMLElement structure but recognize the xhtml:link key and
	 * emit an `xmlns:xhtml` declaration on the root so the child elements
	 * are namespace-correct.
	 *
	 * @param array<int, array<string, mixed>> $url_list Array of URL items.
	 * @return string XML body.
	 */
	public function get_sitemap_xml( $url_list ): string {
		$urlset = new \SimpleXMLElement(
			sprintf(
				'%1$s%2$s%3$s',
				'<?xml version="1.0" encoding="UTF-8" ?>',
				$this->stylesheet,
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
						// esc_url_raw, NOT esc_url: addAttribute() XML-escapes
						// '&' itself, so esc_url's display encoding (&#038;)
						// would double-escape into a corrupt &amp;#038; for any
						// href with query args (Plain permalinks, query mode).
						$link_node->addAttribute( 'href', esc_url_raw( (string) $link['href'] ) );
					}
				}
				// Unknown keys are silently dropped (same as parent behavior).
			}
		}

		$xml = $urlset->asXML();

		return is_string( $xml ) ? $xml : '';
	}
}
