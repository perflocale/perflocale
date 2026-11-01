<?php
/**
 * Menu translation manager - per-language nav menus.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
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

	// Cold paths: one-line forwarders keep each hook's [ $this, method ] callback; the bodies load on first call.

	/**
	 * Label a set of menus that translate each other and link them all to each other.
	 *
	 * @param array<string, int> $set Language slug => nav menu term ID.
	 * @return array{written: int, conflict: string|null} Meta rows written, and why nothing was written.
	 */
	public static function link_imported_menus( array $set ): array {
		return MenuAdmin::link_imported_menus( $set );
	}

	/**
	 * Fallback: save menu language on load, for when the save action misses the field or the ID changes.
	 *
	 * @return void
	 */
	public function save_menu_language_on_load(): void {
		MenuAdmin::save_menu_language_on_load();
	}

	/**
	 * Save menu language and linked menus when a menu is updated.
	 *
	 * @param int $menu_id Menu ID.
	 * @return void
	 */
	public function save_menu_language( int $menu_id ): void {
		MenuAdmin::save_menu_language( $menu_id );
	}

	/**
	 * Show only items in the menu's language in the "Add to Menu" metaboxes.
	 *
	 * @return void
	 */
	public function setup_menu_item_filtering(): void {
		MenuAdmin::setup_menu_item_filtering();
	}

	/**
	 * Enqueue the language badges script for "Add to Menu" items.
	 *
	 * @return void
	 */
	public function inject_language_badges_js(): void {
		MenuAdmin::inject_language_badges_js();
	}

	/**
	 * Inject the language selector into the Menu Settings section via JS.
	 *
	 * @return void
	 */
	public function inject_menu_language_field(): void {
		$this->menu_lang_json = MenuAdmin::inject_menu_language_field();
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
