<?php
/**
 * Menu translation manager - per-language nav menus.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Helper;
use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-language nav menus: menu language and links in term meta, swapped on the front end.
 */
final class MenuManager {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Menu Language field data (JSON) for the Menus screen's footer, or null.
	 *
	 * @var string|null
	 */
	private ?string $menu_lang_json = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param CacheManager   $cache Cache manager.
	 */
	public function __construct( LanguageRouter $router, CacheManager $cache ) {
		$this->router = $router;
		$this->cache  = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Frontend: swap menus based on current language.
		add_filter( 'wp_nav_menu_args', [ $this, 'filter_menu_args' ] );

		// Frontend: point post/term items at the current language's sibling.
		// Priority 9 so the front-page fix below still wins for front-page items.
		add_filter( 'wp_nav_menu_objects', [ $this, 'localize_menu_item_urls' ], 9, 2 );

		// Frontend: fix front page URLs in nav menus to use language homepage.
		add_filter( 'wp_nav_menu_objects', [ $this, 'fix_front_page_urls' ], 10 );

		// Admin: save language on menu update and creation, and on load for edge cases.
		add_action( 'wp_update_nav_menu', [ $this, 'save_menu_language' ] );
		add_action( 'wp_create_nav_menu', [ $this, 'save_menu_language' ] );
		add_action( 'load-nav-menus.php', [ $this, 'save_menu_language_on_load' ] );

		// Admin: Menu Language field and language badges on the Menus screen.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_nav_menus_assets' ], 20 );

		// Admin: the field's data at admin_footer, which runs before the footer scripts print.
		add_action( 'admin_footer', [ $this, 'print_menu_language_data' ] );

		// Admin: filter "Add to Menu" items by menu language; admin_head installs the filters before the metaboxes render.
		add_action( 'admin_head-nav-menus.php', [ $this, 'setup_menu_item_filtering' ] );
	}

	/**
	 * Enqueue the Menu Language field and language-badges scripts on nav-menus.php.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_nav_menus_assets( string $hook ): void {
		if ( $hook !== 'nav-menus.php' ) {
			return;
		}

		$this->inject_menu_language_field();
		$this->inject_language_badges_js();
	}

	/**
	 * Filter wp_nav_menu args to swap to the correct language menu.
	 *
	 * @param array<string, mixed> $args Menu arguments.
	 * @return array<string, mixed>
	 */
	public function filter_menu_args( array $args ): array {
		if ( is_admin() ) {
			return $args;
		}

		$current_slug = $this->router->get_current_slug();

		if ( $current_slug === '' ) {
			return $args;
		}

		if ( ! empty( $args['theme_location'] ) ) {
			$locations = get_nav_menu_locations();
			$menu_id   = $locations[ $args['theme_location'] ] ?? 0;

			if ( $menu_id > 0 ) {
				$lang_menu_id = $this->get_menu_for_language( $menu_id, $current_slug );

				if ( $lang_menu_id && $lang_menu_id !== $menu_id ) {
					$args['menu'] = $lang_menu_id;
				}
			}
		}

		if ( ! empty( $args['menu'] ) && is_numeric( $args['menu'] ) ) {
			$menu_id      = (int) $args['menu'];
			$lang_menu_id = $this->get_menu_for_language( $menu_id, $current_slug );

			if ( $lang_menu_id && $lang_menu_id !== $menu_id ) {
				$args['menu'] = $lang_menu_id;
			}
		}

		return $args;
	}

	/**
	 * Point classic menu post/term items at the current language's published sibling.
	 *
	 * An item renders its saved object's permalink, which resolves to that object's own language.
	 *
	 * @param array<int, \WP_Post> $items Menu items.
	 * @param object|null          $args  wp_nav_menu args (carries the menu term).
	 * @return array<int, \WP_Post>
	 */
	public function localize_menu_item_urls( array $items, $args = null ): array {
		if ( is_admin() || $items === [] ) {
			return $items;
		}

		$current_slug = $this->router->get_current_slug();

		if ( $current_slug === '' ) {
			return $items;
		}

		// A menu with an explicit language is curated for it: item mapping serves only a shared menu.
		$menu = is_object( $args ) && isset( $args->menu ) ? $args->menu : null;

		if ( $menu instanceof \WP_Term && (string) get_term_meta( $menu->term_id, '_perflocale_language', true ) !== '' ) {
			return $items;
		}

		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		foreach ( $items as $item ) {
			$object_id = (int) ( $item->object_id ?? 0 );
			$type      = (string) ( $item->type ?? '' );

			if ( $object_id <= 0 || ( $type !== 'post_type' && $type !== 'taxonomy' ) ) {
				continue; // Custom links / archives - nothing to localize.
			}

			$is_term = $type === 'taxonomy';
			$links   = $repo->get_translations(
				$object_id,
				$is_term ? \PerfLocale\Enum\ObjectType::Term : \PerfLocale\Enum\ObjectType::Post
			);

			$sibling_id = 0;

			foreach ( (array) $links as $link ) {
				if ( isset( $link->language_slug ) && $link->language_slug === $current_slug ) {
					$sibling_id = (int) $link->object_id;
					break;
				}
			}

			if ( $sibling_id <= 0 || $sibling_id === $object_id ) {
				continue; // No sibling, or already in this language.
			}

			if ( $is_term ) {
				$term = get_term( $sibling_id );
				$url  = $term instanceof \WP_Term ? get_term_link( $term ) : '';
			} else {
				if ( get_post_status( $sibling_id ) !== 'publish' ) {
					continue; // Draft sibling - keep the working saved URL.
				}
				$url = get_permalink( $sibling_id );
			}

			if ( is_string( $url ) && $url !== '' ) {
				$item->url = $url;
			}
		}

		return $items;
	}

	/**
	 * Point front page menu items (and their translations) at the language homepage, not the page's slug URL.
	 *
	 * @param array<int, \WP_Post> $items Menu items.
	 * @return array<int, \WP_Post>
	 */
	public function fix_front_page_urls( array $items ): array {
		if ( is_admin() ) {
			return $items;
		}

		$front_page_id = (int) get_option( 'page_on_front' );

		if ( get_option( 'show_on_front' ) !== 'page' || $front_page_id === 0 ) {
			return $items;
		}

		// Get all page IDs in the same translation group as the front page.
		$front_page_ids = [ $front_page_id ];

		$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$links = $repo->get_translations( $front_page_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			$front_page_ids[] = (int) $link->object_id;
		}

		$front_page_ids = array_unique( $front_page_ids );
		$settings       = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$lang_repo      = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$url_converter  = \PerfLocale\Plugin::get_instance()->get( 'url_converter' );
		$default_lang   = $lang_repo->get_default();

		// Raw option: the home_url filter would re-prefix it with the request's language.
		$home_base = trailingslashit( (string) get_option( 'home' ) );

		// Build a quick lookup: page_id → language object.
		$page_lang_map = [];

		foreach ( $links as $link ) {
			if ( ! empty( $link->language_slug ) ) {
				$lang_obj = $lang_repo->find_by_slug( $link->language_slug );

				if ( $lang_obj ) {
					$page_lang_map[ (int) $link->object_id ] = $lang_obj;
				}
			}
		}

		foreach ( $items as $item ) {
			if ( $item->type !== 'post_type' || $item->object !== 'page' ) {
				continue;
			}

			$page_id = (int) $item->object_id;

			if ( ! in_array( $page_id, $front_page_ids, true ) ) {
				continue;
			}

			// Resolve this page's language.
			$page_lang = $page_lang_map[ $page_id ] ?? $default_lang;

			if ( ! $page_lang ) {
				continue;
			}

			// The converter gets the home right in every URL mode, not only path prefixes.
			$item->url = $url_converter->convert( $home_base, $page_lang->slug );
		}

		return $items;
	}

	/**
	 * Get the language-specific version of a menu.
	 *
	 * @param int    $menu_id Original menu ID.
	 * @param string $lang_slug Target language slug.
	 * @return int|null
	 */
	public function get_menu_for_language( int $menu_id, string $lang_slug ): ?int {
		$menu_lang = get_term_meta( $menu_id, '_perflocale_language', true );

		if ( $menu_lang === $lang_slug ) {
			return $menu_id;
		}

		$linked_menu = (int) get_term_meta( $menu_id, '_perflocale_menu_' . $lang_slug, true );

		if ( $linked_menu > 0 ) {
			$target_lang = (string) get_term_meta( $linked_menu, '_perflocale_language', true );

			// Pointers can go stale (siblings are not reconciled): reject a relabelled target, and trust an
			// unlabelled one (the normal case) only while the menu exists, as a deleted one also reads ''.
			if ( ( $target_lang === '' || $target_lang === $lang_slug ) && is_nav_menu( $linked_menu ) ) {
				return $linked_menu;
			}
		}

		return null;
	}

	/**
	 * Label a set of menus that translate each other and link them all to each other.
	 *
	 * Writes nothing when a menu has another language or a pointer to another existing menu: curated sets win.
	 *
	 * @param array<string, int> $set Language slug => nav menu term ID.
	 * @return array{written: int, conflict: string|null} Meta rows written, and why nothing was written.
	 */
	public static function link_imported_menus( array $set ): array {
		$menus = [];

		foreach ( $set as $slug => $menu_id ) {
			$slug    = sanitize_key( (string) $slug );
			$menu_id = (int) $menu_id;

			if ( $slug !== '' && $menu_id > 0 && is_nav_menu( $menu_id ) ) {
				$menus[ $slug ] = $menu_id;
			}
		}

		if ( count( $menus ) < 2 ) {
			return [
				'written'  => 0,
				'conflict' => null,
			];
		}

		foreach ( $menus as $slug => $menu_id ) {
			$label = (string) get_term_meta( $menu_id, '_perflocale_language', true );

			if ( $label !== '' && $label !== $slug ) {
				return [
					'written'  => 0,
					'conflict' => 'language',
				];
			}

			foreach ( $menus as $other_slug => $other_id ) {
				if ( $other_slug === $slug ) {
					continue;
				}

				$pointer = (int) get_term_meta( $menu_id, '_perflocale_menu_' . $other_slug, true );

				if ( $pointer > 0 && $pointer !== $other_id && is_nav_menu( $pointer ) ) {
					return [
						'written'  => 0,
						'conflict' => 'link',
					];
				}
			}
		}

		$written = 0;

		foreach ( $menus as $slug => $menu_id ) {
			if ( (string) get_term_meta( $menu_id, '_perflocale_language', true ) !== $slug ) {
				update_term_meta( $menu_id, '_perflocale_language', $slug );
				++$written;
			}

			foreach ( $menus as $other_slug => $other_id ) {
				if ( $other_slug !== $slug && (int) get_term_meta( $menu_id, '_perflocale_menu_' . $other_slug, true ) !== $other_id ) {
					update_term_meta( $menu_id, '_perflocale_menu_' . $other_slug, $other_id );
					++$written;
				}
			}
		}

		return [
			'written'  => $written,
			'conflict' => null,
		];
	}

	/**
	 * Fallback: save menu language on load, for when the save action misses the field or the ID changes.
	 *
	 * @return void
	 */
	public function save_menu_language_on_load(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_POST['perflocale_menu_language'] ) ) {
			return;
		}

		// Core's nav-menu save nonce, then the capability; silent return because this rides core's save flow.
		$nonce = isset( $_POST['update-nav-menu-nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) )
			: '';

		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'update-nav_menu' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// Determine the menu ID from POST or GET. Nonce verified above.
		$menu_id = 0;

		if ( ! empty( $_POST['menu'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$menu_id = absint( $_POST['menu'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		} elseif ( ! empty( $_REQUEST['menu'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
			$menu_id = absint( $_REQUEST['menu'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		}

		if ( $menu_id > 0 ) {
			$this->save_menu_language( $menu_id );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Save menu language and linked menus when a menu is updated.
	 *
	 * @param int $menu_id Menu ID.
	 * @return void
	 */
	public function save_menu_language( int $menu_id ): void {
		// Local CSRF check, though core verified it: programmatic saves carry neither the nonce nor our fields.
		$nonce = isset( $_POST['update-nav-menu-nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) )
			: '';

		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'update-nav_menu' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified immediately above (and by WordPress core before wp_update_nav_menu fires).
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if ( isset( $_POST['perflocale_menu_language'] ) ) {
			$language = sanitize_key( wp_unslash( $_POST['perflocale_menu_language'] ) );
			update_term_meta( $menu_id, '_perflocale_language', $language );

			// Handle linked menus - propagate links between ALL menus in the group.
			if ( $language !== '' && isset( $_POST['perflocale_linked_menus'] ) && is_array( $_POST['perflocale_linked_menus'] ) ) {
				$linked_menus = array_map( 'absint', wp_unslash( $_POST['perflocale_linked_menus'] ) );

				// Build the full group: lang_slug → menu_id (including the current menu).
				$group = [ $language => $menu_id ];

				foreach ( $linked_menus as $lang_slug => $linked_id ) {
					$lang_slug = sanitize_key( $lang_slug );

					if ( $linked_id > 0 ) {
						$group[ $lang_slug ] = $linked_id;
					}
				}

				// Save direct links from the current menu.
				foreach ( $linked_menus as $lang_slug => $linked_id ) {
					$lang_slug = sanitize_key( $lang_slug );

					if ( $linked_id > 0 ) {
						update_term_meta( $menu_id, '_perflocale_menu_' . $lang_slug, $linked_id );
					} else {
						delete_term_meta( $menu_id, '_perflocale_menu_' . $lang_slug );
					}
				}

				// Propagate: every menu in the group gets links to all OTHER menus.
				foreach ( $group as $slug_a => $id_a ) {
					foreach ( $group as $slug_b => $id_b ) {
						if ( $slug_a === $slug_b ) {
							continue;
						}

						update_term_meta( $id_a, '_perflocale_menu_' . $slug_b, $id_b );
					}
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Show only items in the menu's language in the "Add to Menu" metaboxes.
	 *
	 * @return void
	 */
	public function setup_menu_item_filtering(): void {
		// The menu parameter is only a display filter, read after the capability check.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// Without ?menu=N core edits the first menu by name, so mirror that.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display filter on an already cap-gated nav-menus admin screen; matches WP core's own use of $_REQUEST['menu'] in nav-menus.php.
		$menu_id = isset( $_REQUEST['menu'] ) ? absint( $_REQUEST['menu'] ) : 0;

		if ( $menu_id === 0 ) {
			$nav_menus = wp_get_nav_menus( [ 'orderby' => 'name' ] );
			if ( ! empty( $nav_menus ) ) {
				$menu_id = (int) $nav_menus[0]->term_id;
			}
		}

		if ( $menu_id === 0 ) {
			return;
		}

		// Explicit menu language only: defaulting would empty the pickers of a single mixed-language menu.
		$menu_lang_slug = (string) get_term_meta( $menu_id, '_perflocale_language', true );

		if ( $menu_lang_slug === '' ) {
			return;
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang      = $lang_repo->find_by_slug( $menu_lang_slug );

		if ( ! $lang ) {
			return;
		}

		$language_id = (int) $lang->id;

		// Filter get_pages() calls on the nav-menus screen (Pages metabox).
		add_filter(
			'get_pages',
			function ( array $pages ) use ( $language_id ): array {
				return $this->filter_pages_by_language( $pages, $language_id );
			},
			10
		);

		// Filter post queries on the nav-menus screen (Posts, custom post type metaboxes).
		add_action(
			'pre_get_posts',
			function ( \WP_Query $query ) use ( $language_id ): void {
				// Only filter on the nav-menus screen, not nested/recursive queries.
				if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
					return;
				}

				$screen = get_current_screen();

				if ( ! $screen || $screen->base !== 'nav-menus' ) {
					return;
				}

				// Strict: PostQueryFilter omits its unlinked-post fallback, so pickers do not leak them.
				$query->set( 'perflocale_strict_language', true );

				$query->set( 'perflocale_language_id', $language_id );
				$query->set( 'suppress_filters', false );
			}
		);
	}

	/**
	 * Filter pages to only show those in the menu's language.
	 *
	 * @param array<int, \WP_Post> $pages Pages returned by get_pages().
	 * @param int                  $language_id Target language ID.
	 * @return array<int, \WP_Post>
	 */
	private function filter_pages_by_language( array $pages, int $language_id ): array {
		if ( empty( $pages ) ) {
			return $pages;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		$page_ids     = array_map( static fn( \WP_Post $p ): int => $p->ID, $pages );
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

		// Get all page IDs that belong to this language.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$matching_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT l.object_id FROM %i l
				INNER JOIN %i g ON l.group_id = g.id AND g.type = 'post'
				WHERE l.object_id IN ({$placeholders}) AND l.language_id = %d",
				$links_table,
				$groups_table,
				...array_merge( $page_ids, [ $language_id ] )
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$matching_set = array_flip( array_map( 'intval', $matching_ids ) );

		return array_values(
			array_filter(
				$pages,
				static function ( \WP_Post $page ) use ( $matching_set ): bool {
					// Strict, like perflocale_strict_language: unlinked pages are excluded.
					return isset( $matching_set[ $page->ID ] );
				}
			)
		);
	}

	/**
	 * Enqueue the language badges script for "Add to Menu" items, with a post ID to language map.
	 *
	 * @return void
	 */
	public function inject_language_badges_js(): void {
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return;
		}

		// Build a JS-friendly map of post ID → language code from the DB.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$langs_table  = \PerfLocale\Database\Schema::table( 'languages' );

		// Published rows only, most recently modified first, so large sites badge the likely items.
		/**
		 * Filter the maximum number of posts loaded for nav-menu language badges.
		 *
		 * @hook perflocale/menu/badge_post_limit
		 * @param int $limit Maximum rows fetched. 0 = unlimited.
		 */
		$badge_limit = (int) apply_filters( 'perflocale/menu/badge_post_limit', 5000 );

		$sql = "SELECT l.object_id, lang.slug AS lang_slug
			FROM {$links_table} l
			INNER JOIN {$groups_table} g ON l.group_id = g.id AND g.type = 'post'
			INNER JOIN {$langs_table} lang ON l.language_id = lang.id
			INNER JOIN {$wpdb->posts} p ON l.object_id = p.ID AND p.post_status = 'publish'
			ORDER BY p.post_modified DESC";

		// A limit of 0 means unlimited: no LIMIT clause.
		if ( $badge_limit > 0 ) {
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', $badge_limit ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk load for menu metabox; not suitable for object cache.
		$results = $wpdb->get_results( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$post_lang_map = [];

		foreach ( $results as $row ) {
			$post_lang_map[ (int) $row->object_id ] = Helper::format_locale_as_bcp47( (string) $row->lang_slug );
		}

		if ( empty( $post_lang_map ) ) {
			return;
		}

		// Static script, seeded with the post ID to language map.
		wp_enqueue_script(
			'perflocale-menu-badges',
			PERFLOCALE_URL . 'assets/js/menu-badges.js',
			[],
			PERFLOCALE_VERSION,
			true
		);
		wp_localize_script(
			'perflocale-menu-badges',
			'perflocaleMenuBadges',
			[ 'langMap' => $post_lang_map ]
		);
	}

	/**
	 * Inject the language selector into the Menu Settings section via JS.
	 *
	 * @return void
	 */
	public function inject_menu_language_field(): void {
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();
		$nav_menus = wp_get_nav_menus();

		// Detect the current menu ID; core loads a menu even without ?menu=X.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$menu_id = isset( $_REQUEST['menu'] ) ? absint( $_REQUEST['menu'] ) : 0;

		if ( $menu_id === 0 ) {
			// WordPress picks the first menu from wp_get_nav_menus() when no ID is in the URL.
			$all_menus = wp_get_nav_menus( [ 'orderby' => 'name' ] );

			if ( ! empty( $all_menus ) ) {
				$menu_id = (int) $all_menus[0]->term_id;
			}
		}

		$menu_lang = $menu_id > 0 ? (string) get_term_meta( $menu_id, '_perflocale_language', true ) : '';

		// Build the options HTML.
		$lang_options = '<option value="">' . esc_html__( 'Not set', 'perflocale' ) . '</option>';

		foreach ( $languages as $lang ) {
			$flag          = Helper::get_flag_emoji( $lang );
			$selected      = selected( $menu_lang, $lang->slug, false );
			$lang_options .= '<option value="' . esc_attr( $lang->slug ) . '"' . $selected . '>'
				. esc_html( $flag . ' ' . ( $lang->native_name ?: $lang->name ) . ' (' . Helper::format_locale_as_bcp47( $lang->slug ) . ')' )
				. '</option>';
		}

		// Build linked menus HTML.
		$linked_html = '';

		if ( $menu_id > 0 && $menu_lang !== '' ) {
			foreach ( $languages as $lang ) {
				if ( $lang->slug === $menu_lang ) {
					continue;
				}

				$linked = (int) get_term_meta( $menu_id, '_perflocale_menu_' . $lang->slug, true );
				$flag   = Helper::get_flag_emoji( $lang );

				// Same label format as the Menu Language dropdown.
				$lang_label = $flag . ' ' . ( $lang->native_name ?: $lang->name ) . ' (' . Helper::format_locale_as_bcp47( $lang->slug ) . ')';

				$linked_html .= '<p class="menu-settings-input-container" style="margin:6px 0;"><label style="display:inline-block;min-width:170px;">';
				$linked_html .= esc_html( $lang_label ) . ':</label> ';
				$linked_html .= '<select name="perflocale_linked_menus[' . esc_attr( $lang->slug ) . ']" style="max-width:240px;">';
				$linked_html .= '<option value="0">' . esc_html__( '- None -', 'perflocale' ) . '</option>';

				foreach ( $nav_menus as $nav_menu ) {
					$sel          = selected( $linked, $nav_menu->term_id, false );
					$linked_html .= '<option value="' . esc_attr( (string) $nav_menu->term_id ) . '"' . $sel . '>'
						. esc_html( $nav_menu->name )
						. '</option>';
				}

				$linked_html .= '</select></p>';
			}
		}

		$linked_section = '';

		if ( $linked_html !== '' ) {
			$linked_section = '<p class="menu-settings-input" style="margin:10px 0 0;"><strong style="display:block;margin-bottom:4px;">'
				. esc_html__( 'Linked Menus', 'perflocale' ) . '</strong></p>' . $linked_html;
		}

		// The server-built options HTML travels as JSON data to the static script.
		\PerfLocale\Admin\Assets::enqueue_script( 'perflocale-menu-lang-field', 'menu-lang-field.js' );

		// Not wp_localize_script(): it html_entity_decode()s every scalar, undoing esc_html() before innerHTML.
		// JSON_HEX_* keeps the escaping intact through esc_attr() and JSON.parse().
		$json = wp_json_encode(
			[
				'legendLabel' => __( 'Menu Language', 'perflocale' ),
				'langOptions' => $lang_options,
				'linkedHtml'  => $linked_section,
				'description' => __( 'If no menu is linked for a language, the default menu will be shown.', 'perflocale' ),
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		$this->menu_lang_json = is_string( $json ) ? $json : null;
	}

	/**
	 * Print the Menu Language field's JSON in a hidden data attribute for menu-lang-field.js, once.
	 *
	 * @return void
	 */
	public function print_menu_language_data(): void {
		if ( null === $this->menu_lang_json ) {
			return;
		}

		printf(
			'<div id="perflocale-menu-lang-data" hidden data-perflocale-menu-lang="%s"></div>' . "\n",
			esc_attr( $this->menu_lang_json )
		);

		$this->menu_lang_json = null;
	}
}
