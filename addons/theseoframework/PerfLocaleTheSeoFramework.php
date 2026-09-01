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
 * The SEO Framework (TSF) integration for PerfLocale.
 *
 * Registers SEO meta keys as translatable, ensures schema language
 * properties reflect the current language, and unlocks TSF's own
 * "optimized sitemap" queries so every language's posts are listed.
 * TSF delegates hreflang to multilingual plugins, which PerfLocale
 * already handles natively.
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
	 * Frontend-only container service, so this is null in admin (non-AJAX)
	 * context — where TSF's meta render data is still assembled for its SEO
	 * bar but there is no fallback render to pin.
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

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so TSF's own og:locale already reflects the current language.

		// Missing-translation canonical guard. PerfLocale registers it on
		// core's `get_canonical_url`, but TSF removes core's rel_canonical and
		// emits its own, so that filter never fires and the guard is inert
		// whenever this plugin is active. TSF 5.x exposes no canonical-specific
		// filter — every head tag is assembled into one render-data map keyed
		// by tag id — so the guard rides that map's `canonical` entry.
		add_filter( 'the_seo_framework_meta_render_data', [ $this, 'pin_fallback_canonical' ] );

		// TSF's "optimized sitemap" (on by default, replaces core sitemaps)
		// builds its entries with plain WP_Query calls — without this unlock
		// PerfLocale's language filter stamps the default language onto those
		// queries and every translated post vanishes from the sitemap. hpt =
		// hierarchical post types, nhpt = non-hierarchical; TSF filters both.
		add_filter( 'the_seo_framework_sitemap_hpt_query_args', [ $this, 'include_all_languages' ] );
		add_filter( 'the_seo_framework_sitemap_nhpt_query_args', [ $this, 'include_all_languages' ] );

		// Ensure schema graph reflects the current language. Gated on the
		// setting so no callback runs when enrichment is disabled.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'the_seo_framework_schema_graph_data', [ $this, 'filter_schema_language' ] );
		}
	}

	/**
	 * Pin TSF's canonical on a missing-translation fallback render.
	 *
	 * TSF's own custom-canonical setting (`_genesis_canonical_uri`) is already
	 * resolved into this href by the time the map is filtered, and
	 * HreflangTags leaves an owner-set value alone — it only acts when the
	 * href is the post's own permalink.
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
	 * Add TSF meta keys as translatable.
	 *
	 * TSF stores SEO meta using _genesis_* prefixed keys (shared with
	 * Genesis theme) and its own custom meta keys.
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
	 * Machine-translatable SEO meta: titles/descriptions/social text ONLY.
	 * The focus keyword is deliberately excluded — keyword targeting in the
	 * target language is a human decision (add it back via this same filter
	 * if a site wants it). Gated by the mt_meta_seo setting.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	/**
	 * The SEO Framework meta keys this addon machine-translates — all plain-text
	 * SEO fields, so all route to the provider's text mode via mt_meta_key_format().
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
	 * Enrich TSF's schema graph with inLanguage + translation siblings.
	 *
	 * TSF passes a SEQUENTIAL LIST of graph entities, which is the shape
	 * SchemaEnricher::enrich_graph() handles directly — unlike the Rank Math,
	 * SEOPress and Slim SEO addons, whose hosts pass a keyed map and which
	 * therefore enrich entity by entity.
	 *
	 * Untyped parameter: TSF survives a non-array return from this filter and
	 * keeps rendering — `$graph = apply_filters( 'the_seo_framework_schema_graph_data', … );`
	 * is followed immediately by `if ( empty( $graph ) ) return [];`
	 * (inc/classes/meta/schema.class.php:142-148), so a null from an earlier
	 * callback drops the graph rather than fataling. An `array` declaration
	 * would turn that survivable case into a TypeError raised at ARGUMENT
	 * BINDING, before this body could return — the shape that took down
	 * wpseo_sitemap_entry and wpseo_breadcrumb_links. The other five SEO
	 * addons already hand an unrecognised payload straight back; this is the
	 * last one that did not.
	 *
	 * @param array<int, array<string, mixed>>|mixed $data TSF schema graph (a list of entities).
	 * @return array<int, array<string, mixed>>|mixed Enriched graph, or the
	 *                                                value unchanged when it is
	 *                                                not a graph array.
	 */
	public function filter_schema_language( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		return \PerfLocale\Seo\SchemaEnricher::enrich_graph( $data );
	}
}
