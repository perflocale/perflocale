<?php
/**
 * PerfLocale The SEO Framework addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The SEO Framework (TSF) integration: translatable SEO meta, all-language sitemaps and schema.
 *
 * No hreflang suppression: TSF leaves hreflang to multilingual plugins.
 */
final class PerfLocaleTheSeoFramework implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'theseoframework';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'The SEO Framework';
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
		return [ 'autodescription/autodescription.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		if ( ! function_exists( 'the_seo_framework' ) && ! function_exists( 'tsf' ) ) {
			return false;
		}

		// The schema graph filter we target has been stable since 5.0.5.
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) && version_compare( THE_SEO_FRAMEWORK_VERSION, '5.0.5', '<' ) ) {
			return false;
		}

		return true;
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
		// Register TSF meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// A field a person empties stays empty: the clear is recorded in the
		// post's seed-cleared marker, which machine translation and the seed honour.
		\PerfLocale\Translation\SyncMeta::track_seed_clears( $this->add_meta_keys( [], '' ) );

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so TSF's own og:locale already reflects the current language.

		// TSF removes core's rel_canonical and has no canonical filter, so the missing-translation
		// canonical guard rides the `canonical` entry of its head render-data map.
		add_filter( 'the_seo_framework_meta_render_data', [ $this, 'pin_fallback_canonical' ] );

		// TSF's optimized sitemap (default, replaces core's) uses plain WP_Query calls, which
		// PerfLocale would limit to one language. hpt/nhpt: (non-)hierarchical post types.
		add_filter( 'the_seo_framework_sitemap_hpt_query_args', [ $this, 'include_all_languages' ] );
		add_filter( 'the_seo_framework_sitemap_nhpt_query_args', [ $this, 'include_all_languages' ] );

		// Schema inLanguage and translation siblings, registered only when enrichment is on.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'the_seo_framework_schema_graph_data', [ $this, 'filter_schema_language' ] );
		}
	}

	/**
	 * Pin TSF's canonical on a missing-translation fallback render.
	 *
	 * The href already holds `_genesis_canonical_uri`, which HreflangTags leaves alone.
	 *
	 * @param array<string, mixed>|mixed $data Meta render data keyed by tag id.
	 * @return array<string, mixed>|mixed
	 */
	public function pin_fallback_canonical( $data ) {
		if ( ! is_array( $data ) || empty( $data['canonical']['attributes']['href'] ) ) {
			return $data;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $data;
		}

		$pinned = $hreflang->filter_seo_plugin_canonical( (string) $data['canonical']['attributes']['href'] );

		if ( is_string( $pinned ) && $pinned !== '' ) {
			$data['canonical']['attributes']['href'] = $pinned;
		}

		return $data;
	}

	/**
	 * Unlock TSF sitemap queries so posts from every language are listed.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
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
	 * Add TSF meta keys as translatable (`_genesis_*` keys are shared with the Genesis theme).
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_genesis_title';
		$keys[] = '_genesis_description';
		$keys[] = '_open_graph_title';
		$keys[] = '_open_graph_description';
		$keys[] = '_twitter_title';
		$keys[] = '_twitter_description';

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
	 * TSF meta keys this addon machine-translates, all plain text (text mode).
	 */
	private const MT_TEXT_KEYS = [
		'_genesis_title',
		'_genesis_description',
		'_open_graph_title',
		'_open_graph_description',
		'_twitter_title',
		'_twitter_description',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route The SEO Framework's plain-text SEO meta to the provider's text mode
	 * so the stored translation keeps a literal '&' instead of an entity one.
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
	 * Enrich TSF's schema graph with inLanguage and translation siblings.
	 *
	 * A list, so enrich_graph() handles it. Untyped on purpose: TSF drops an empty graph,
	 * so an earlier callback may return null, and a typed parameter would fatal.
	 *
	 * @param array<int, array<string, mixed>>|mixed $data TSF schema graph (a list of entities).
	 * @return array<int, array<string, mixed>>|mixed Enriched graph; anything else unchanged.
	 */
	public function filter_schema_language( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		return \PerfLocale\Seo\SchemaEnricher::enrich_graph( $data );
	}
}
