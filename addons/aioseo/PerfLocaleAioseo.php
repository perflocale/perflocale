<?php
/**
 * PerfLocale All in One SEO addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All in One SEO integration: translatable SEO meta, sitemap alternates and schema.
 */
final class PerfLocaleAioseo implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested AIOSEO version.
	 */
	private const MIN_VERSION = '4.0.0';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'aioseo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'All in One SEO';
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
		return [ 'all-in-one-seo-pack/all_in_one_seo_pack.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		// The main class means AIOSEO is running; AIOSEO_VERSION gates the minimum version.
		if ( ! class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
			return false;
		}

		if ( defined( 'AIOSEO_VERSION' ) && version_compare( AIOSEO_VERSION, self::MIN_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The sitemap code, loaded at the first sitemap hook.
	 *
	 * @var \PerfLocaleAioseoSitemap|null
	 */
	private ?\PerfLocaleAioseoSitemap $sitemap = null;

	/**
	 * The sitemap code; it reads the hreflang service through this add-on.
	 *
	 * @return \PerfLocaleAioseoSitemap
	 */
	private function sitemap(): \PerfLocaleAioseoSitemap {
		if ( null === $this->sitemap ) {
			require_once __DIR__ . '/PerfLocaleAioseoSitemap.php';
			$this->sitemap = new \PerfLocaleAioseoSitemap( fn(): ?\PerfLocale\Frontend\HreflangTags => $this->hreflang() );
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
		// Register AIOSEO meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// A field a person empties stays empty: the clear is recorded in the
		// post's seed-cleared marker, which machine translation and the seed honour.
		\PerfLocale\Translation\SyncMeta::track_seed_clears( $this->add_meta_keys( [], '' ) );

		// AIOSEO renders from its `aioseo_posts` table, not the `_aioseo_*` meta PerfLocale
		// translates: overlay that meta on the in-memory model, never write their table.
		add_filter( 'aioseo_get_post', [ $this, 'overlay_translated_meta' ] );

		// No hreflang suppression: `aioseo_disable` switches off all AIOSEO head output,
		// and AIOSEO emits head hreflang only through a multilingual integration.

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so AIOSEO's own og:locale already reflects the current language.

		// AIOSEO removes core's rel_canonical, so the missing-translation canonical guard
		// rides AIOSEO's filter. An owner's `canonical_url` passes through the same filter:
		// HreflangTags' permalink-equality test, not the call site, leaves it alone.
		add_filter( 'aioseo_canonical_url', [ $this, 'pin_fallback_canonical' ] );

		// Sitemap alternates. PHP_INT_MAX: after any URL rewriter, so `languages` matches the
		// final entry. 4 args: post sitemaps pass 4, some callers 3 (the 4th has a default).
		add_filter( 'aioseo_sitemap_post', [ $this, 'add_sitemap_alternates' ], PHP_INT_MAX, 4 );

		// Inject alternate URLs into AIOSEO taxonomy sitemap entries.
		add_filter( 'aioseo_sitemap_term', [ $this, 'add_taxonomy_sitemap_alternates' ], PHP_INT_MAX, 4 );

		// All languages' terms in taxonomy sitemaps; registered on sitemap requests only,
		// because get_terms_args runs on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Schema inLanguage and translation siblings, registered only when enrichment is on.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'aioseo_schema_output', [ $this, 'filter_schema_graph' ] );
		}
	}

	/**
	 * Pin AIOSEO's canonical on a missing-translation fallback render.
	 *
	 * @param mixed $canonical Canonical URL AIOSEO computed.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical ) {
		$hreflang = $this->hreflang();

		return $hreflang ? $hreflang->filter_seo_plugin_canonical( $canonical ) : $canonical;
	}

	/**
	 * Enrich AIOSEO's schema graph via the shared SchemaEnricher.
	 *
	 * @param array<int, array<string, mixed>>|mixed $graph AIOSEO schema graph.
	 * @return array<int, array<string, mixed>>|mixed
	 */
	public function filter_schema_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		return \PerfLocale\Seo\SchemaEnricher::enrich_graph( $graph );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add AIOSEO meta keys as translatable.
	 *
	 * AIOSEO also stores these fields in post meta, besides its `aioseo_posts` table.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_aioseo_title';
		$keys[] = '_aioseo_description';
		$keys[] = '_aioseo_og_title';
		$keys[] = '_aioseo_og_description';
		$keys[] = '_aioseo_twitter_title';
		$keys[] = '_aioseo_twitter_description';

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
	 * AIOSEO meta keys this addon machine-translates, all plain text (text mode).
	 */
	private const MT_TEXT_KEYS = [
		'_aioseo_title',
		'_aioseo_description',
		'_aioseo_og_title',
		'_aioseo_og_description',
		'_aioseo_twitter_title',
		'_aioseo_twitter_description',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route AIOSEO's plain-text SEO meta to the provider's text mode.
	 *
	 * A stored '&amp;' would be escaped again by AIOSEO on output.
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
	 * Overlay PerfLocale-translated SEO meta onto the model AIOSEO renders from.
	 *
	 * An existing `aioseo_posts` row stays authoritative. Render reads only: write flows
	 * save the model, which would persist the overlay into AIOSEO's table.
	 *
	 * @param \AIOSEO\Plugin\Common\Models\Post|mixed $model AIOSEO post model.
	 * @return \AIOSEO\Plugin\Common\Models\Post|mixed
	 */
	public function overlay_translated_meta( $model ) {
		if ( ! is_object( $model ) || empty( $model->post_id ) ) {
			return $model;
		}

		if ( method_exists( $model, 'exists' ) && $model->exists() ) {
			return $model;
		}

		$columns = [
			'title'               => '_aioseo_title',
			'description'         => '_aioseo_description',
			'og_title'            => '_aioseo_og_title',
			'og_description'      => '_aioseo_og_description',
			'twitter_title'       => '_aioseo_twitter_title',
			'twitter_description' => '_aioseo_twitter_description',
		];

		$overlay = [];

		foreach ( $columns as $column => $meta_key ) {
			$value = get_post_meta( (int) $model->post_id, $meta_key, true );

			if ( is_string( $value ) && '' !== $value ) {
				$overlay[ $column ] = $value;
			}
		}

		if ( [] === $overlay || \PerfLocale\Helper::is_write_context() ) {
			return $model;
		}

		foreach ( $overlay as $column => $value ) {
			$model->{$column} = $value;
		}

		return $model;
	}

	/**
	 * Include all language terms in get_terms() during AIOSEO sitemap generation.
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
	 * Add alternate language URLs to AIOSEO sitemap entries.
	 *
	 * @param array<string, mixed>|mixed $entry Sitemap entry.
	 * @param int                        $post_id Post ID.
	 * @param string                     $post_type Post type.
	 * @param string                     $type Object type label from AIOSEO (optional).
	 * @return array<string, mixed>|mixed Entry, unchanged when it is not an
	 *                                    entry array.
	 */
	public function add_sitemap_alternates( $entry, int $post_id, string $post_type, string $type = 'post' ) {
		// Untyped on purpose (a typed parameter would fatal): see the helper's add_sitemap_alternates().
		return $this->sitemap()->add_sitemap_alternates( $entry, $post_id, $post_type, $type );
	}

	/**
	 * Add alternate language URLs to AIOSEO taxonomy sitemap entries.
	 *
	 * @param array<string, mixed>|mixed $entry Sitemap entry.
	 * @param int                        $term_id Term ID.
	 * @param string                     $taxonomy Taxonomy name.
	 * @param string                     $type Object type identifier.
	 * @return array<string, mixed>|mixed Entry, unchanged when it is not an
	 *                                    entry array.
	 */
	public function add_taxonomy_sitemap_alternates( $entry, int $term_id, string $taxonomy, string $type ) {
		// Untyped on purpose (a typed parameter would fatal): see the helper's add_taxonomy_sitemap_alternates().
		return $this->sitemap()->add_taxonomy_sitemap_alternates( $entry, $term_id, $taxonomy, $type );
	}

}
