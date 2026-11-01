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
 * Core sitemap renderer that also writes `xhtml:link` hreflang alternates in each `<url>`.
 *
 * Core's renderer drops any entry key except loc, lastmod, changefreq and priority. The XML is built in SitemapXml.
 */
final class SitemapRenderer extends \WP_Sitemaps_Renderer {

	/**
	 * Render the XML body for a sitemap page (urlset), declaring `xmlns:xhtml` on the root.
	 *
	 * @param array<int, array<string, mixed>> $url_list Array of URL items.
	 * @return string XML body.
	 */
	public function get_sitemap_xml( $url_list ): string {
		return SitemapXml::urlset( (array) $url_list, (string) $this->stylesheet );
	}
}
