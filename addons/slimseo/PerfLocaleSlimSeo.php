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
 * Slim SEO integration for PerfLocale.
 *
 * Ensures schema language properties are correct and unlocks Slim
 * SEO's own sitemap queries so every language's content is listed
 * (Slim SEO replaces WP core sitemaps with its /sitemap.xml tree
 * built from plain WP_Query/get_terms calls). Slim SEO delegates
 * hreflang to multilingual plugins, which PerfLocale's core
 * handles natively.
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
		// Slim-SEO 4.x renamed its main class from SlimSEO\Plugin to
		// SlimSEO\Core. Accept either so the addon stays compatible with
		// both the older and the current naming. The SLIM_SEO_VER constant
		// is the version gate.
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

		// Missing-translation canonical guard. PerfLocale registers it on
		// core's `get_canonical_url`, but Slim SEO removes core's
		// rel_canonical and emits its own, so that filter never fires and the
		// guard is inert whenever this plugin is active. Re-register it on
		// Slim SEO's own canonical filter; HreflangTags decides whether the
		// value is PerfLocale's to correct, so the per-post
		// `slim_seo[canonical]` override (already applied upstream) is left
		// alone. Only the URL argument is needed; the queried-object id passed
		// as the second arg is ignored.
		add_filter( 'slim_seo_canonical_url', [ $this, 'pin_fallback_canonical' ] );

		// Slim SEO's /sitemap.xml tree replaces WP core sitemaps and builds
		// its entries with plain WP_Query/get_terms calls — without this
		// unlock PerfLocale's language filter stamps the default language
		// onto those queries and every translated post/term vanishes from
		// the sitemap.
		add_filter( 'slim_seo_sitemap_post_type_query_args', [ $this, 'include_all_languages' ] );
		add_filter( 'slim_seo_taxonomy_query_args', [ $this, 'include_all_languages' ] );

		// Set schema inLanguage to current language. Gated on the
		// setting so no callback runs when enrichment is disabled.
		//
		// `slim_seo_schema_graph`, NOT `slim_seo_schema_entities`. Manager::output()
		// fires the entities filter over `$this->entities` — Schema\Types\Base
		// OBJECTS keyed by `$entity->context` — and only turns them into schema
		// arrays two lines later (src/Schema/Manager.php:32 vs 37). A schema
		// enricher hooked there is handed objects it cannot read and silently
		// changes nothing; worse, anything it added to that array would reach
		// `$entity->is_active()` on line 34 as a fatal.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'slim_seo_schema_graph', [ $this, 'filter_schema_language' ] );
		}
	}

	/**
	 * Lazily resolve PerfLocale's hreflang service.
	 *
	 * Frontend-only container service, so this is null in admin (non-AJAX)
	 * context — where Slim SEO's canonical filter never fires anyway.
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
	 * Enrich Slim SEO's schema graph with inLanguage + translation siblings.
	 *
	 * Slim SEO builds the graph with `array_map()` over its context-keyed
	 * entity map, and `array_map()` preserves those string keys — so what
	 * arrives here is a MAP ('website' => [...], 'webpage' => [...]), not the
	 * sequential list Yoast, AIOSEO and TSF pass. SchemaEnricher::enrich_graph()
	 * recognises an `@graph` envelope, a list, or one entity; a keyed map falls
	 * through to its single-entity branch, whose `@type` lookup misses and
	 * returns the map untouched. Enrich each entity individually and keep the
	 * keys, exactly as the Rank Math and SEOPress addons do for their own
	 * keyed maps. Slim SEO re-indexes with array_values() straight after this
	 * filter, so the keys are ours to preserve.
	 *
	 * @param array<string, array<string, mixed>>|mixed $graph Slim SEO schema map.
	 * @return array<string, array<string, mixed>>|mixed Enriched map, or the
	 *                                                   value unchanged when it
	 *                                                   is not a graph array.
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
