<?php
/**
 * PerfLocale Slim SEO addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slim SEO integration: canonical guard, all-language sitemaps and schema.
 *
 * No hreflang suppression: Slim SEO leaves hreflang to multilingual plugins.
 */
final class PerfLocaleSlimSeo implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested Slim SEO version.
	 */
	private const MIN_VERSION = '4.0';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'slimseo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Slim SEO';
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
		return [ 'slim-seo/slim-seo.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		// Either main class name: Slim SEO 4.x has SlimSEO\Core, older builds SlimSEO\Plugin.
		// SLIM_SEO_VER is the version gate.
		if ( ! class_exists( 'SlimSEO\\Core' ) && ! class_exists( 'SlimSEO\\Plugin' ) ) {
			return false;
		}

		if ( defined( 'SLIM_SEO_VER' ) && version_compare( SLIM_SEO_VER, self::MIN_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so Slim SEO's own og:locale already reflects the current language.

		// Slim SEO removes core's rel_canonical, so the missing-translation canonical guard
		// rides Slim SEO's filter; HreflangTags leaves a `slim_seo[canonical]` override alone.
		add_filter( 'slim_seo_canonical_url', [ $this, 'pin_fallback_canonical' ] );

		// Slim SEO's sitemap (replaces core's) uses plain WP_Query/get_terms calls, which
		// PerfLocale would limit to one language.
		add_filter( 'slim_seo_sitemap_post_type_query_args', [ $this, 'include_all_languages' ] );
		add_filter( 'slim_seo_taxonomy_query_args', [ $this, 'include_all_languages' ] );

		// Schema enrichment, only when on. `slim_seo_schema_graph`, never `slim_seo_schema_entities`:
		// that one holds entity objects, and an array added to it fatals on is_active().
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'slim_seo_schema_graph', [ $this, 'filter_schema_language' ] );
		}
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
	 * Pin Slim SEO's canonical on a missing-translation fallback render.
	 *
	 * @param mixed $canonical Canonical URL Slim SEO computed.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical ) {
		$hreflang = $this->hreflang();

		return $hreflang ? $hreflang->filter_seo_plugin_canonical( $canonical ) : $canonical;
	}

	/**
	 * Unlock Slim SEO sitemap queries so all languages' content is listed.
	 *
	 * @param array<string, mixed> $args WP_Query / get_terms arguments.
	 * @return array<string, mixed>
	 */
	public function include_all_languages( array $args ): array {
		$args['perflocale_all_languages'] = true;

		return $args;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Enrich Slim SEO's schema graph with inLanguage and translation siblings.
	 *
	 * A map keyed by context ('website', 'webpage'), which enrich_graph() leaves untouched:
	 * enrich entity by entity and keep the keys.
	 *
	 * @param array<string, array<string, mixed>>|mixed $graph Slim SEO schema map.
	 * @return array<string, array<string, mixed>>|mixed Enriched map; anything else unchanged.
	 */
	public function filter_schema_language( $graph ) {
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
}
