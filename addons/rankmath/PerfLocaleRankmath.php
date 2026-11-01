<?php
/**
 * PerfLocale Rank Math addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rank Math integration: translatable SEO meta, sitemap alternates and schema.
 *
 * No hreflang suppression: Rank Math emits hreflang only through a multilingual integration.
 */
final class PerfLocaleRankmath implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested Rank Math version (its sitemap filters).
	 */
	private const MIN_VERSION = '1.0.46';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'rankmath';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Rank Math';
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
		return [ 'seo-by-rank-math/rank-math.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		if ( ! class_exists( 'RankMath' ) ) {
			return false;
		}

		if ( defined( 'RANK_MATH_VERSION' ) && version_compare( RANK_MATH_VERSION, self::MIN_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The sitemap code, loaded at the first sitemap hook.
	 *
	 * @var \PerfLocaleRankmathSitemap|null
	 */
	private ?\PerfLocaleRankmathSitemap $sitemap = null;

	/**
	 * The sitemap code; it reads the hreflang service through this add-on.
	 *
	 * @return \PerfLocaleRankmathSitemap
	 */
	private function sitemap(): \PerfLocaleRankmathSitemap {
		if ( null === $this->sitemap ) {
			require_once __DIR__ . '/PerfLocaleRankmathSitemap.php';
			$this->sitemap = new \PerfLocaleRankmathSitemap( fn(): ?\PerfLocale\Frontend\HreflangTags => $this->hreflang() );
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
		// Register Rank Math meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// A field a person empties stays empty: the clear is recorded in the
		// post's seed-cleared marker, which machine translation and the seed honour.
		\PerfLocale\Translation\SyncMeta::track_seed_clears( $this->add_meta_keys( [], '' ) );

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so Rank Math's own og:locale already reflects the current language.

		// Rank Math removes core's rel_canonical, so the missing-translation canonical guard
		// rides Rank Math's filter; HreflangTags leaves an owner-set canonical alone.
		add_filter( 'rank_math/frontend/canonical', [ $this, 'pin_fallback_canonical' ] );

		// PHP_INT_MAX: capture after every URL rewriter, so the map key is the final URL
		// add_sitemap_alternates() looks up.
		add_filter( 'rank_math/sitemap/entry', [ $this, 'capture_sitemap_entry' ], PHP_INT_MAX, 3 );

		// Add alternate URLs to Rank Math's rendered sitemap entries.
		add_filter( 'rank_math/sitemap/url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// All languages' terms in taxonomy sitemaps; registered on sitemap requests only,
		// because get_terms_args runs on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Add xhtml namespace to sitemap urlsets.
		add_action( 'wp_loaded', [ $this, 'register_sitemap_namespace' ] );

		// Schema enrichment, only when on. Rank Math builds its graph on `rank_math/json_ld`
		// (there is no `rank_math/schema/data` filter); priority 100 runs after its own
		// add_schema (10) and connect_schema_entities (99), on the assembled graph.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'rank_math/json_ld', [ $this, 'filter_schema_graph' ], 100, 1 );
		}
	}

	/**
	 * Pin Rank Math's canonical on a missing-translation fallback render.
	 *
	 * @param mixed $canonical Canonical URL Rank Math computed.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical ) {
		$hreflang = $this->hreflang();

		return $hreflang ? $hreflang->filter_seo_plugin_canonical( $canonical ) : $canonical;
	}

	/**
	 * Enrich Rank Math's schema graph via the shared SchemaEnricher.
	 *
	 * Rank Math passes a map keyed by snippet name, which enrich_graph() leaves untouched:
	 * enrich entity by entity and keep the keys. Never replace this loop with enrich_graph().
	 *
	 * @param array<string, array<string, mixed>>|mixed $graph Rank Math schema graph.
	 * @return array<string, array<string, mixed>>|mixed
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
	 * Add Rank Math meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = 'rank_math_title';
		$keys[] = 'rank_math_description';
		$keys[] = 'rank_math_focus_keyword';
		$keys[] = 'rank_math_facebook_title';
		$keys[] = 'rank_math_facebook_description';
		$keys[] = 'rank_math_twitter_title';
		$keys[] = 'rank_math_twitter_description';

		return $keys;
	}

	/**
	 * Machine-translatable SEO meta (titles, descriptions, social), gated by mt_meta_seo.
	 *
	 * The focus keyword is left out on purpose: keyword targeting is a human decision.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	/**
	 * Rank Math meta keys this addon machine-translates, all plain text (text mode).
	 */
	private const MT_TEXT_KEYS = [
		'rank_math_title',
		'rank_math_description',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route Rank Math's plain-text SEO meta to the provider's text mode so the
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
	 * Include all language terms in get_terms() during Rank Math sitemap generation.
	 *
	 * @param array<string, mixed> $args Term query arguments.
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
	 * Pin an untranslated entry's loc, then map that loc to the post or term behind it.
	 *
	 * @param array<string, mixed>|mixed $url_data Sitemap entry data, or what an earlier callback returned.
	 * @param string                     $type Object type ('post', 'term', 'user' or 'local').
	 * @param object|mixed               $object The post or term object.
	 * @return array<string, mixed>|mixed Entry data; anything else unchanged.
	 */
	public function capture_sitemap_entry( $url_data, string $type, $object ) {
		// Untyped on purpose (a typed parameter would fatal): see the helper's capture_sitemap_entry().
		return $this->sitemap()->capture_sitemap_entry( $url_data, $type, $object );
	}

	/**
	 * Register xhtml namespace filter on sitemap urlsets for all public types.
	 */
	public function register_sitemap_namespace(): void {
		foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
			add_filter( "rank_math/sitemap/{$type}_urlset", [ $this, 'add_urlset_xhtml_namespace' ] );
		}
		foreach ( get_taxonomies( [ 'public' => true ] ) as $taxonomy ) {
			add_filter( "rank_math/sitemap/{$taxonomy}_urlset", [ $this, 'add_urlset_xhtml_namespace' ] );
		}
	}

	/**
	 * Add xhtml namespace declaration to a sitemap urlset tag.
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
	 * Add xhtml:link alternates to a Rank Math sitemap entry's rendered XML.
	 *
	 * @param string              $output Sitemap URL entry XML.
	 * @param array<string,mixed> $url URL data array with 'loc', 'mod', 'images' keys.
	 * @return string
	 */
	public function add_sitemap_alternates( string $output, array $url ): string {
		return $this->sitemap()->add_sitemap_alternates( $output, $url );
	}

}
