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
 * All in One SEO (AIOSEO) integration for PerfLocale.
 *
 * Registers SEO meta keys as translatable, disables AIOSEO's own
 * hreflang output (PerfLocale handles it), sets OG locale to the
 * current language, and injects alternate URLs into AIOSEO sitemaps.
 */
final class PerfLocaleAioseo implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested AIOSEO version.
	 */
	private const MIN_VERSION = '4.0.0';

	/**
	 * Translation group repository - lazily resolved.
	 *
	 * @var \PerfLocale\Database\Repository\TranslationGroupRepository|null
	 */
	private ?\PerfLocale\Database\Repository\TranslationGroupRepository $repo = null;

	/**
	 * Language repository - lazily resolved.
	 *
	 * @var \PerfLocale\Database\Repository\LanguageRepository|null
	 */
	private ?\PerfLocale\Database\Repository\LanguageRepository $lang_repo = null;

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
		// AIOSEO defines AIOSEO_VERSION at load time; that's the most
		// reliable signal that the plugin is actually running. The old
		// AIOSEO_FILE constant is redundant and was dropped from the
		// post-4.0 bootstrap.
		if ( ! class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
			return false;
		}

		if ( defined( 'AIOSEO_VERSION' ) && version_compare( AIOSEO_VERSION, self::MIN_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Lazily resolve the translation group repository.
	 *
	 * @return \PerfLocale\Database\Repository\TranslationGroupRepository
	 */
	private function repo(): \PerfLocale\Database\Repository\TranslationGroupRepository {
		if ( $this->repo === null ) {
			$cache      = \PerfLocale\Plugin::get_instance()->get( 'cache' );
			$this->repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );
		}

		return $this->repo;
	}

	/**
	 * Lazily resolve the language repository.
	 *
	 * @return \PerfLocale\Database\Repository\LanguageRepository
	 */
	private function lang_repo(): \PerfLocale\Database\Repository\LanguageRepository {
		if ( $this->lang_repo === null ) {
			$cache           = \PerfLocale\Plugin::get_instance()->get( 'cache' );
			$this->lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		}

		return $this->lang_repo;
	}

	/**
	 * Lazily resolve PerfLocale's hreflang service.
	 *
	 * Frontend-only container service, so this is null in admin (non-AJAX)
	 * context — where AIOSEO's canonical filter still fires for its own
	 * analysis passes but there is no fallback render to pin.
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

		// AIOSEO renders from its own `aioseo_posts` table, not from the
		// `_aioseo_*` post-meta mirror that PerfLocale translates, so a
		// translation post (which has no row there) would fall back to
		// auto-generated titles/descriptions. Overlay the translated meta
		// onto the in-memory model instead of writing into their table.
		add_filter( 'aioseo_get_post', [ $this, 'overlay_translated_meta' ] );

		// NOTE: AIOSEO's `aioseo_disable` filter is a global off-switch
		// (disables all AIOSEO head output - title, description, schema,
		// hreflang), not a hreflang-specific one. There's no way to use
		// it to suppress only hreflang. PerfLocale emits its own hreflang
		// via HreflangManager; AIOSEO's own hreflang output requires a
		// multilingual plugin integration anyway, so in practice this is
		// a non-issue.

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so AIOSEO's own og:locale already reflects the current language.

		// Missing-translation canonical guard. PerfLocale registers it on
		// core's `get_canonical_url`, but AIOSEO removes core's rel_canonical
		// and emits its own, so that filter never fires and the guard is inert
		// whenever this plugin is active. Re-register it on AIOSEO's own
		// canonical filter. AIOSEO fires that filter at three call sites, and
		// the `canonical_url` column a site owner filled in travels through one
		// of them — so it is the permalink-equality gate inside HreflangTags,
		// not the call site, that leaves such an override alone, exactly as it
		// leaves the empty string AIOSEO passes on 404/search.
		add_filter( 'aioseo_canonical_url', [ $this, 'pin_fallback_canonical' ] );

		// Inject alternate URLs into AIOSEO sitemap entries.
		// AIOSEO fires this with 4 args in post-content sitemaps
		// (entry, post_id, post_type, 'post') and 3 in legacy paths -
		// register for 4 so we don't silently truncate the type label.
		// PHP_INT_MAX so we run AFTER any third-party URL rewriter on the
		// same filter; otherwise our `languages` array would key off a
		// pre-rewrite URL that doesn't match the final emitted entry.
		add_filter( 'aioseo_sitemap_post', [ $this, 'add_sitemap_alternates' ], PHP_INT_MAX, 4 );

		// Inject alternate URLs into AIOSEO taxonomy sitemap entries.
		add_filter( 'aioseo_sitemap_term', [ $this, 'add_taxonomy_sitemap_alternates' ], PHP_INT_MAX, 4 );

		// Include all language terms in AIOSEO taxonomy sitemaps.
		// Sitemap predicate is request-constant (REQUEST_URI is fixed before
		// plugins_loaded) — register the get_terms_args callback only on
		// sitemap requests instead of no-op dispatching on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Enrich AIOSEO's schema graph with inLanguage + translation siblings.
		// Hook name stable across AIOSEO 4.x. Gated on the setting to
		// avoid a no-op filter callback on every request when disabled.
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
	 * AIOSEO primarily uses a custom `aioseo_posts` table, but also
	 * stores data in post meta for compatibility.
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
	 * The AIOSEO meta keys this addon machine-translates. All plain-text SEO
	 * fields (title / description / social), so all route to the provider's
	 * text mode via mt_meta_key_format().
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
	 * Route AIOSEO's plain-text SEO meta to the provider's text mode so the
	 * stored translation isn't entity-escaped (a '&' stays literal instead of
	 * becoming '&amp;' that AIOSEO would double-escape on output).
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
	 * Every AIOSEO output surface (title, meta description, og:*, twitter:*)
	 * reads this model, so one seam covers them all — unlike the
	 * `aioseo_title`/`aioseo_description` value filters, which carry no post
	 * context and also fire for other objects prepared in the same request.
	 * An existing `aioseo_posts` row means the post's SEO fields were edited
	 * directly in AIOSEO and stays authoritative. Write flows (admin, AJAX,
	 * REST, cron, CLI) fetch the model to mutate-and-save it, which would
	 * persist the overlay into AIOSEO's table — render reads only.
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
	 * REQUEST_URI is fixed before plugins_loaded, so the verdict is
	 * request-constant — used both to gate the get_terms_args registration
	 * and inside the callback for callers that invoke it directly.
	 *
	 * @return bool
	 */
	private function is_sitemap_request(): bool {
		static $is_sitemap = null;

		if ( $is_sitemap === null ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$is_sitemap  = (bool) preg_match( '/sitemap[^\\/]*\.xml/', $request_uri );
		}

		return $is_sitemap;
	}

	/**
	 * Add alternate language URLs to AIOSEO sitemap entries.
	 *
	 * AIOSEO's `aioseo_sitemap_post` filter is inconsistent across the
	 * plugin: post sitemaps pass 4 args (entry, id, post_type, 'post'),
	 * while a few specialised callers pass 3. Registering for 4 handles
	 * both thanks to PHP's trailing-default; the 4th arg is optional.
	 *
	 * @param array<string, mixed>|mixed $entry Sitemap entry.
	 * @param int                        $post_id Post ID.
	 * @param string                     $post_type Post type.
	 * @param string                     $type Object type label from AIOSEO (optional).
	 * @return array<string, mixed>|mixed Entry, unchanged when it is not an
	 *                                    entry array.
	 */
	public function add_sitemap_alternates( $entry, int $post_id, string $post_type, string $type = 'post' ) {
		// AIOSEO carries whatever this filter returns straight through to the
		// renderer without an array check - Sitemap/Helpers.php:661-671 only
		// tests `isset( $item['loc'] )`, and Views/sitemap/xml/default.php:23
		// then does `if ( empty( $entry['loc'] ) ) { continue; }`. A falsy
		// return is therefore how you drop an entry from an AIOSEO sitemap,
		// exactly as it is on Yoast's `wpseo_sitemap_entry`. This callback runs
		// at PHP_INT_MAX - after every other one - so it binds whatever they
		// returned, and declaring `array` made PHP throw at argument binding,
		// before any guard in this body could run. That is the identical shape
		// that turned an excluded Yoast sitemap entry into a live fatal.
		// Anything that is not an entry array goes back exactly as it came in.
		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		// The filter is not post-only. AIOSEO also fires it for BuddyPress
		// activities, groups and members (Content.php 776/835/891), passing a
		// BuddyPress row id and one of its pseudo post types ('bp-activity',
		// 'bp-group', 'bp-member') — none of which is a registered post type.
		// Such an id says nothing about PerfLocale's translation links, so
		// both things keyed off it here are wrong for those entries: an
		// alternate set built from a coincidental id collision, and the
		// unlinked-loc pin, which would rewrite a component URL PerfLocale
		// never routes onto the default host. Leave the entry as AIOSEO
		// built it.
		if ( ! post_type_exists( $post_type ) ) {
			return $entry;
		}

		return $this->build_sitemap_languages( $entry, $post_id, \PerfLocale\Enum\ObjectType::Post );
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
		// Same exclusion contract as add_sitemap_alternates() above -
		// Sitemap/Content.php:385 fires this filter into the same renderer,
		// and this callback is likewise registered at PHP_INT_MAX. Return an
		// entry we do not recognise unchanged instead of throwing on it.
		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		return $this->build_sitemap_languages( $entry, $term_id, \PerfLocale\Enum\ObjectType::Term );
	}

	/**
	 * Build language alternates for an AIOSEO sitemap entry.
	 *
	 * @param array<string, mixed>        $entry Sitemap entry.
	 * @param int                         $object_id Post or term ID.
	 * @param \PerfLocale\Enum\ObjectType $object_type Object type.
	 * @return array<string, mixed>
	 */
	private function build_sitemap_languages( array $entry, int $object_id, \PerfLocale\Enum\ObjectType $object_type ): array {
		$plugin = \PerfLocale\Plugin::get_instance();
		$links  = $this->repo()->get_translations( $object_id, $object_type );

		if ( $links === [] ) {
			// Unlinked object = default-language content by definition, but
			// AIOSEO builds every loc from get_permalink()/get_term_link() in
			// the REQUEST context. In subdomain/domain URL modes each language
			// host's sitemap would then advertise the same object under its
			// own host (cross-host duplicate content). Same guard core's
			// SitemapIntegration applies to its own entries, for the sitemap
			// AIOSEO replaces it with. Being unlinked is also what makes the
			// pin safe — not the URL mode: pin_sitemap_url_to_default()
			// strips a language prefix from any URL it is handed, so in
			// path-based modes it comes out a no-op only because an unlinked
			// object's loc has no prefix to strip.
			return $this->pin_untranslated_loc( $entry );
		}

		if ( count( $links ) < 2 ) {
			return $entry;
		}

		// Per-post opt-out: a flagged post advertises no alternates and is
		// skipped as a sibling below, so excluded translations never appear
		// in any alternate set.
		if ( $object_type === \PerfLocale\Enum\ObjectType::Post && \PerfLocale\Helper::is_seo_excluded( $object_id ) ) {
			return $entry;
		}

		// Front page detection only applies to posts.
		$url_converter = null;

		if ( $object_type === \PerfLocale\Enum\ObjectType::Post ) {
			$front_page_id = (int) get_option( 'page_on_front' );
			$is_front_page = false;

			if ( $front_page_id > 0 ) {
				foreach ( $links as $link ) {
					if ( (int) $link->object_id === $front_page_id ) {
						$is_front_page = true;
						break;
					}
				}
			}

			$url_converter = ( $is_front_page && $plugin->has( 'url_converter' ) )
				? $plugin->get( 'url_converter' )
				: null;
		}

		// Active languages only. get_slug_map() is the bootstrap bundle's
		// active-only slug -> row hash — the same source core's hreflang and
		// sitemap paths filter on. find_by_slug() resolves DEACTIVATED
		// languages too, which would advertise them as alternates; the map
		// lookup is also O(1) instead of a per-sibling cache descent.
		$slug_map = $this->lang_repo()->get_slug_map();

		$languages = [];

		foreach ( $links as $link ) {
			if ( empty( $link->language_slug ) ) {
				continue;
			}

			if ( $object_type === \PerfLocale\Enum\ObjectType::Post && get_post_status( (int) $link->object_id ) !== 'publish' ) {
				continue;
			}

			if ( $object_type === \PerfLocale\Enum\ObjectType::Post && \PerfLocale\Helper::is_seo_excluded( (int) $link->object_id ) ) {
				continue;
			}

			if ( $url_converter ) {
				$permalink = $url_converter->convert( home_url( '/' ), $link->language_slug );
			} elseif ( $object_type === \PerfLocale\Enum\ObjectType::Term ) {
				$term_link = get_term_link( (int) $link->object_id );
				$permalink = is_wp_error( $term_link ) ? '' : $term_link;
			} else {
				$permalink = get_permalink( (int) $link->object_id );
			}

			$lang_obj = $slug_map[ (string) $link->language_slug ] ?? null;

			if ( $permalink && $lang_obj ) {
				$hreflang    = $this->locale_to_hreflang( $lang_obj->locale );
				$languages[] = [
					'language' => $hreflang,
					'location' => $permalink,
				];
			}
		}

		if ( count( $languages ) > 1 ) {
			$entry['languages'] = $languages;
		}

		return $entry;
	}

	/**
	 * Pin an unlinked entry's loc to the default language's URL shape.
	 *
	 * @param array<string, mixed> $entry Sitemap entry.
	 * @return array<string, mixed>
	 */
	private function pin_untranslated_loc( array $entry ): array {
		if ( empty( $entry['loc'] ) ) {
			return $entry;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $entry;
		}

		$entry['loc'] = $hreflang->pin_sitemap_url_to_default( (string) $entry['loc'] );

		return $entry;
	}

	/**
	 * Convert a WordPress locale to an hreflang value.
	 *
	 * Simplifies redundant region codes (fr_FR → fr, de_DE → de)
	 * while preserving meaningful ones (en_US → en-us, pt_BR → pt-br).
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private function locale_to_hreflang( string $locale ): string {
		return \PerfLocale\Helper::format_locale_as_bcp47( $locale );
	}
}
