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
 * Enables per-language nav menus.
 *
 * Stores the language for each nav menu via term meta.
 * On the frontend, filters wp_nav_menu to show the correct language version.
 * On the admin Menus screen, injects a language dropdown into Menu Settings.
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

		// Frontend: point post/term menu items at the current language's
		// sibling (the classic-menu twin of the FSE navigation-link fix —
		// classic items render their SAVED object's permalink, which
		// resolves to the object's OWN language). Priority 9 so the
		// front-page fix below still wins for front-page items.
		add_filter( 'wp_nav_menu_objects', [ $this, 'localize_menu_item_urls' ], 9, 2 );

		// Frontend: fix front page URLs in nav menus to use language homepage.
		add_filter( 'wp_nav_menu_objects', [ $this, 'fix_front_page_urls' ], 10 );

		// Admin: save language on menu update and creation.
		// Priority 10 for normal updates, also hook into load for edge cases.
		add_action( 'wp_update_nav_menu', [ $this, 'save_menu_language' ] );
		add_action( 'wp_create_nav_menu', [ $this, 'save_menu_language' ] );
		add_action( 'load-nav-menus.php', [ $this, 'save_menu_language_on_load' ] );

		// Admin: attach the Menu Language field + language badges behaviour
		// as inline scripts on the carrier handle registered by Assets.php.
		// Priority 20 so the carrier handle is registered (by Assets.php at
		// priority 10) before we attach inline payloads to it. Inline scripts
		// added at this stage print in the normal admin_print_footer_scripts
		// cycle - unlike admin_footer-{hook_suffix}, which fires AFTER scripts
		// are printed and silently drops any wp_add_inline_script calls.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_nav_menus_assets' ], 20 );

		// Admin: filter "Add to Menu" items by menu language (still needs
		// admin_head to install get_pages + pre_get_posts filters before WP
		// starts rendering the metaboxes).
		add_action( 'admin_head-nav-menus.php', [ $this, 'setup_menu_item_filtering' ] );
	}

	/**
	 * Attach Menu Language field + language-badges inline scripts on the
	 * nav-menus.php admin screen.
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
	 * Point classic menu post/term items at the current language's sibling.
	 *
	 * A classic menu item renders its saved object's permalink, which the
	 * permalink filters resolve to the object's OWN language — so on a
	 * translated page every explicit item linked back to the source
	 * language. When a published sibling exists in the current language,
	 * swap the item URL for the sibling's permalink (mirrors the FSE
	 * navigation-link fix). Menus with an explicit language assignment are
	 * skipped — those are curated per language.
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

		// A menu with an EXPLICIT language assignment is curated for that
		// language — its items were authored deliberately; leave them
		// untouched. (filter_menu_args already swapped to the per-language
		// menu when one exists; item-level mapping only serves the shared
		// single-menu setup.)
		$menu = is_object( $args ) && isset( $args->menu ) ? $args->menu : null;

		if ( $menu instanceof \WP_Term && (string) get_term_meta( $menu->term_id, '_perflocale_language', true ) !== '' ) {
			return $items;
		}

		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		foreach ( $items as $item ) {
			$object_id = (int) ( $item->object_id ?? 0 );
			$type      = (string) ( $item->type ?? '' );

			if ( $object_id <= 0 || ( $type !== 'post_type' && $type !== 'taxonomy' ) ) {
				continue; // Custom links / archives — nothing to localize.
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
					continue; // Draft sibling — keep the working saved URL.
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
	 * Fix front page URLs in nav menu items.
	 *
	 * When the static front page (or its translation) is added as a menu item,
	 * WordPress links to /en/homepage/ instead of /en/. This replaces those
	 * URLs with the correct language homepage URL.
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

		// Raw (unfiltered) site home — the home_url filter re-prefixes with the
		// current request language, so we pass the bare option to the converter,
		// which re-derives the correct per-language home for the active url mode.
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

			// Delegate to the canonical URL converter so the per-language home is
			// correct in EVERY url mode — subdirectory (path prefix, with the
			// default hidden), subdomain (de.host), and per-language domain
			// (host.de) — instead of unconditionally appending a path prefix.
			// convert() also suppresses the home_url filter internally, so the
			// current request language can't re-prefix the result.
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

			// The reverse pointer can go stale when the target menu's language
			// is later changed or the menu is deleted (save_menu_language does
			// not reconcile siblings). Reject it only when the target has been
			// RE-LABELED to a different language. An UNLABELED target ('') is
			// the normal linked-menu case — the admin sets the language on one
			// menu of the group and links the rest without labelling each — so
			// we still trust it, but only if the menu actually still exists
			// (a deleted menu's term meta is gone, so it too reads '', and we
			// must not serve a dangling pointer).
			if ( ( $target_lang === '' || $target_lang === $lang_slug ) && is_nav_menu( $linked_menu ) ) {
				return $linked_menu;
			}
		}

		return null;
	}

	/**
	 * Save menu language and linked menus when a menu is updated.
	 *
	 * @param int $menu_id Menu ID.
	 * @return void
	 */
	/**
	 * Fallback: save menu language during page load processing.
	 *
	 * Handles edge cases where wp_update_nav_menu fires before our
	 * JS-injected field is processed, or during new menu creation
	 * where the menu ID changes mid-request.
	 *
	 * @return void
	 */
	public function save_menu_language_on_load(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_POST['perflocale_menu_language'] ) ) {
			return;
		}

		// Verify core's nav-menu save nonce: field `update-nav-menu-nonce`,
		// action `update-nav_menu` — the exact pair nav-menus.php posts.
		// Empty and invalid nonces are both rejected outright.
		// Canonical WP order: nonce (CSRF) first, then capability. We use
		// silent `return` on either failure because this hooks into core's
		// nav-menu save flow - no-op is the correct response if our slot
		// is absent or the user lacks our specific cap.
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
		// Self-contained CSRF check. WordPress core already verifies this same
		// `update-nav_menu` nonce before firing wp_update_nav_menu /
		// wp_create_nav_menu, and save_menu_language_on_load() verifies it too;
		// re-checking here keeps the CSRF guarantee LOCAL and visible instead of
		// relying on an upstream check a static analyser can't see. A
		// programmatic menu save (importer, WP-CLI) carries neither this nonce
		// NOR our POST fields, so the early return loses nothing there — the
		// perflocale_menu_language isset() guard below would no-op anyway.
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
	 * Inject the language selector into the Menu Settings section via JS.
	 *
	 * This places the dropdown after the "Display location" checkboxes,
	 * inside the existing form, so it saves properly with the menu.
	 *
	 * @return void
	 */
	/**
	 * Set up filtering of "Add to Menu" items when a menu language is set.
	 *
	 * Hooks into page/post queries on the nav-menus.php screen so that
	 * only items in the menu's language are shown in the metabox panels.
	 * Also injects JS to show language badges on all menu item labels.
	 *
	 * @return void
	 */
	public function setup_menu_item_filtering(): void {
		// Hooked on admin_head-nav-menus.php — gate by capability so the
		// $_REQUEST['menu'] read only happens for users who can already
		// view the nav-menus screen, then the URL parameter is treated
		// purely as a display filter (no state mutation).
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// (language-badges JS is enqueued from enqueue_nav_menus_assets so
		// it rides the normal script-print cycle instead of admin_footer-*)

		// Resolve the menu being edited. WP core's nav-menus.php falls
		// back to "the first menu" when no ?menu=N is in the URL, so we
		// mirror that - otherwise the filter would silently bail on a
		// bare /wp-admin/nav-menus.php load. $_REQUEST['menu'] is the
		// SAME parameter core's own nav-menus.php screen reads — display-
		// only filter on an already-capability-gated admin page.
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

		// Only filter when the menu has an EXPLICIT _perflocale_language
		// term-meta set via the "Menu Language" dropdown in Menu Settings.
		// Sites with a single mixed-language menu intentionally leave this
		// unset; auto-falling-back to the default language would force-empty
		// those sites' menu pickers for every non-default menu.
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

				// Strict mode: pickers here should NOT show unmanaged/unlinked
				// posts - otherwise every nav-menu picker leaks pre-PerfLocale
				// content into every language's menu. PostQueryFilter reads
				// this flag in modify_query_clauses and omits the OR-NULL
				// fallback when it's set.
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
					// Strict: only pages explicitly linked to this language. Unlinked
					// pages are intentionally excluded - same rationale as the
					// perflocale_strict_language query flag for WP_Query above. A
					// nav menu representing the "ar" locale should NOT silently
					// include every pre-PerfLocale page.
					return isset( $matching_set[ $page->ID ] );
				}
			)
		);
	}

	/**
	 * Inject JS to show language badges on all "Add to Menu" items.
	 *
	 * Fetches language info for visible post/page items via a single AJAX call
	 * and appends language codes as badges next to item labels.
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

		// Only fetch published pages/posts that the nav-menu metabox can
		// actually show. Biased to the most recently modified rows so large
		// sites still see badges on items they're likely to add. The limit
		// is filterable; set to 0 to load all rows (use carefully).
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

		// Bind the LIMIT through prepare() rather than concatenating the
		// cast int directly. A filter value of 0 means "unlimited", so the
		// LIMIT clause is omitted entirely in that case.
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

		// Enqueue the static JS asset and seed the post-id -> language-slug
		// map via wp_localize_script. The behaviour file lives under
		// assets/js/ so it's covered by the standard WP scripts pipeline
		// rather than a heredoc'd inline blob.
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

		// Detect the current menu ID. WordPress may load a menu even without
		// ?menu=X in the URL (it defaults to the most recently edited menu).
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

				// Match the Menu Language dropdown format ("flag NativeName (SLUG)")
				// so both selectors read consistently at a glance.
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

		// Enqueue the static behaviour asset and pass the per-language data
		// as a JSON payload. The pre-rendered options/linked-menus HTML is
		// server-built (per-language flags, native names, currently selected
		// slug) so it has to travel as data; the script file itself is static
		// and runs the DOM injection.
		wp_enqueue_script(
			'perflocale-menu-lang-field',
			PERFLOCALE_URL . 'assets/js/menu-lang-field.js',
			[],
			PERFLOCALE_VERSION,
			true
		);
		// NOT wp_localize_script(): WP_Scripts::localize() runs every scalar
		// through html_entity_decode() (wp-includes/class-wp-scripts.php), which
		// UNDOES the esc_html() applied to the language labels above — and the
		// consumer assigns these strings to innerHTML. Language names come from
		// the languages table, so a label containing markup would be re-inflated
		// into live HTML on the way out. wp_json_encode() performs no entity
		// decoding, so the escaping survives to the browser intact.
		wp_add_inline_script(
			'perflocale-menu-lang-field',
			'var perflocaleMenuLang = ' . wp_json_encode(
				[
					'legendLabel' => __( 'Menu Language', 'perflocale' ),
					'langOptions' => $lang_options,
					'linkedHtml'  => $linked_section,
					'description' => __( 'If no menu is linked for a language, the default menu will be shown.', 'perflocale' ),
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}
}
