<?php
/**
 * PerfLocale SEOPress addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEOPress integration: translatable SEO meta, sitemap alternates and schema.
 *
 * No hreflang suppression: SEOPress leaves hreflang to multilingual plugins.
 */
final class PerfLocaleSeopress implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested SEOPress version.
	 */
	private const MIN_VERSION = '6.0';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'SEOPress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'wp-seopress/seopress.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		if ( ! function_exists( 'seopress_get_service' ) && ! class_exists( 'SEOPRESS_Functions' ) ) {
			return false;
		}

		if ( defined( 'SEOPRESS_VERSION' ) && version_compare( SEOPRESS_VERSION, self::MIN_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The sitemap code, loaded at the first sitemap hook.
	 *
	 * @var \PerfLocaleSeopressSitemap|null
	 */
	private ?\PerfLocaleSeopressSitemap $sitemap = null;

	/**
	 * The sitemap code; it reads the hreflang service through this add-on.
	 *
	 * @return \PerfLocaleSeopressSitemap
	 */
	private function sitemap(): \PerfLocaleSeopressSitemap {
		if ( null === $this->sitemap ) {
			require_once __DIR__ . '/PerfLocaleSeopressSitemap.php';
			$this->sitemap = new \PerfLocaleSeopressSitemap( fn(): ?\PerfLocale\Frontend\HreflangTags => $this->hreflang() );
		}

		return $this->sitemap;
	}

	/**
	 * Lazily resolve PerfLocale's hreflang service.
	 *
	 * Null in admin (non-AJAX): the service is front-end only.
	 *
	 * @return \PerfLocale\Frontend\HreflangTags|null
	 */
	private function hreflang(): ?\PerfLocale\Frontend\HreflangTags {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'hreflang' ) ) {
			return null;
		}

		$service = $plugin->get( 'hreflang' );

		return $service instanceof \PerfLocale\Frontend\HreflangTags ? $service : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register SEOPress meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// A field a person empties stays empty: the clear is recorded in the
		// post's seed-cleared marker, which machine translation and the seed honour.
		\PerfLocale\Translation\SyncMeta::track_seed_clears( $this->add_meta_keys( [], '' ) );

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so SEOPress's own og:locale already reflects the current language.

		// SEOPress removes core's rel_canonical, so the missing-translation canonical guard
		// rides SEOPress's filter; HreflangTags leaves a `_seopress_robots_canonical` override alone.
		add_filter( 'seopress_titles_canonical', [ $this, 'pin_fallback_canonical' ] );

		// Inject alternate language URLs into SEOPress sitemap entries.
		add_filter( 'seopress_sitemaps_url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// Add xhtml namespace to SEOPress sitemap urlset.
		add_filter( 'seopress_sitemaps_urlset', [ $this, 'add_urlset_xhtml_namespace' ] );

		// All languages' terms in taxonomy sitemaps; registered on sitemap requests only,
		// because get_terms_args runs on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Schema enrichment, only when on. SEOPress has no `seopress_schema_graph` filter:
		// its decoded JSON-LD passes through `seopress_json_schema_generator_get_jsons`.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'seopress_json_schema_generator_get_jsons', [ $this, 'filter_schema_graph' ] );
		}
	}

	/**
	 * Pin SEOPress's canonical on a missing-translation fallback render.
	 *
	 * SEOPress passes the whole `<link>` element, its href htmlspecialchars( urldecode() ):
	 * the guard gets the href still percent-decoded (re-encoding is lossy; HreflangTags
	 * decodes both sides). An unchanged href returns the tag byte for byte.
	 *
	 * @param mixed $canonical_tag Canonical `<link>` element SEOPress built.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical_tag ) {
		if ( ! is_string( $canonical_tag ) || $canonical_tag === '' ) {
			return $canonical_tag;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $canonical_tag;
		}

		if ( ! preg_match( '/href="([^"]*)"/', $canonical_tag, $matches ) ) {
			return $canonical_tag;
		}

		$current = wp_specialchars_decode( $matches[1], ENT_QUOTES );
		$pinned  = $hreflang->filter_seo_plugin_canonical( $current );

		if ( ! is_string( $pinned ) || $pinned === $current ) {
			return $canonical_tag;
		}

		return str_replace(
			'href="' . $matches[1] . '"',
			'href="' . esc_attr( $pinned ) . '"',
			$canonical_tag
		);
	}

	/**
	 * On sitemap requests, make PerfLocale's term-query filter include every language's terms.
	 *
	 * @param array<string, mixed> $args get_terms() query args.
	 * @return array<string, mixed>
	 */
	public function include_all_languages_in_sitemap( array $args ): array {
		if ( $this->is_sitemap_request() ) {
			$args['perflocale_all_languages'] = true;
		}

		return $args;
	}

	/**
	 * Whether the current request renders an XML sitemap.
	 *
	 * Memoised: REQUEST_URI is fixed before plugins_loaded.
	 *
	 * @return bool
	 */
	private function is_sitemap_request(): bool {
		static $is_sitemap = null;

		if ( $is_sitemap === null ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$is_sitemap  = \PerfLocale\Helper::is_sitemap_uri( $request_uri );
		}

		return $is_sitemap;
	}

	/**
	 * Enrich SEOPress's schema graph via the shared SchemaEnricher.
	 *
	 * A map keyed by schema slug (null for an empty schema): enrich entity by entity, keep the keys.
	 *
	 * @param array<string, array<string, mixed>|null>|mixed $graph SEOPress schema map.
	 * @return array<string, array<string, mixed>|null>|mixed
	 */
	public function filter_schema_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		$enriched = [];

		foreach ( $graph as $key => $entity ) {
			$enriched[ $key ] = is_array( $entity )
				? \PerfLocale\Seo\SchemaEnricher::enrich_single( $entity )
				: $entity;
		}

		return $enriched;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add SEOPress meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_seopress_titles_title';
		$keys[] = '_seopress_titles_desc';
		$keys[] = '_seopress_social_fb_title';
		$keys[] = '_seopress_social_fb_desc';
		$keys[] = '_seopress_social_twitter_title';
		$keys[] = '_seopress_social_twitter_desc';

		return $keys;
	}

	/**
	 * Machine-translatable SEO meta (titles, descriptions, social), gated by mt_meta_seo.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	/**
	 * SEOPress meta keys this addon machine-translates, all plain text (text mode).
	 */
	private const MT_TEXT_KEYS = [
		'_seopress_titles_title',
		'_seopress_titles_desc',
		'_seopress_social_fb_title',
		'_seopress_social_fb_desc',
		'_seopress_social_twitter_title',
		'_seopress_social_twitter_desc',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route SEOPress's plain-text SEO meta to the provider's text mode so the
	 * stored translation keeps a literal '&' instead of an entity-escaped one.
	 *
	 * @param string $format    Inherited format ('html' default).
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @return string 'text' for this addon's SEO keys, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		return in_array( $key, self::MT_TEXT_KEYS, true ) ? 'text' : $format;
	}


	/**
	 * Add xhtml namespace declaration to the SEOPress sitemap urlset tag.
	 *
	 * @param string $urlset Urlset opening tag.
	 * @return string
	 */
	public function add_urlset_xhtml_namespace( string $urlset ): string {
		if ( strpos( $urlset, 'xmlns:xhtml' ) === false ) {
			$urlset = str_replace(
				'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"',
				'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml"',
				$urlset
			);
		}

		return $urlset;
	}

	/**
	 * Add xhtml:link alternates to a SEOPress sitemap entry's rendered XML.
	 *
	 * @param string              $output Sitemap URL entry XML.
	 * @param array<string,mixed> $url URL data array with 'loc', 'mod', 'images' keys.
	 * @return string
	 */
	public function add_sitemap_alternates( string $output, array $url ): string {
		return $this->sitemap()->add_sitemap_alternates( $output, $url );
	}

}
