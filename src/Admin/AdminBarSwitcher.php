<?php
/**
 * Admin bar language switcher.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Helper;
use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a language switcher to the WordPress admin toolbar.
 *
 * Context-aware behavior:
 * - Frontend: shows current page language, dropdown links to translated pages
 * - Admin list pages (posts/pages/terms): shows active filter language or "All Languages",
 * dropdown items filter the list by language
 * - Admin post/term editor: shows the post's language, dropdown links to edit translations
 * - Admin other pages: shows site default language, quick links only
 */
final class AdminBarSwitcher {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param Settings       $settings Plugin settings.
	 * @param CacheManager   $cache Cache manager.
	 */
	public function __construct( LanguageRouter $router, Settings $settings, CacheManager $cache ) {
		$this->router   = $router;
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! (bool) $this->settings->get( 'admin_bar_switcher' ) ) {
			return;
		}

		add_action( 'admin_bar_menu', [ $this, 'add_menu' ], 90 );
		add_action( 'wp_enqueue_scripts', [ $this, 'inline_css' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'inline_css' ] );
	}

	/**
	 * Add language switcher items to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_menu( \WP_Admin_Bar $wp_admin_bar ): void {
		$languages = $this->router->get_active_languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		if ( is_admin() ) {
			$this->build_admin_menu( $wp_admin_bar, $languages );
		} else {
			$this->build_frontend_menu( $wp_admin_bar, $languages );
		}
	}

	/**
	 * Build the frontend admin bar menu.
	 *
	 * Shows the current page's language and links to translated versions.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @param array<object> $languages Active languages.
	 * @return void
	 */
	private function build_frontend_menu( \WP_Admin_Bar $wp_admin_bar, array $languages ): void {
		$current = $this->router->get_current_language();

		if ( ! $current ) {
			return;
		}

		$this->add_parent_node( $wp_admin_bar, $current );

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return;
		}

		$urls = $plugin->get( 'url_converter' )->get_translations_for_current_page();

		foreach ( $languages as $lang ) {
			if ( $lang->slug === $current->slug ) {
				continue;
			}

			$url = $urls[ $lang->slug ] ?? '';

			if ( empty( $url ) ) {
				continue;
			}

			$this->add_language_node(
				$wp_admin_bar,
				$lang,
				$url,
				sprintf(
				/* translators: %s: language name */
					__( 'View in %s', 'perflocale' ),
					$lang->native_name ?: $lang->name
				)
			);
		}
	}

	/**
	 * Build the admin area menu.
	 *
	 * Adapts based on current screen:
	 * - List pages: shows filter state, dropdown filters by language
	 * - Post editor: shows post language, dropdown links to translations
	 * - Term editor: shows term language, dropdown links to translations
	 * - Other: shows default language, quick links only
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @param array<object> $languages Active languages.
	 * @return void
	 */
	private function build_admin_menu( \WP_Admin_Bar $wp_admin_bar, array $languages ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Post/page/CPT list screen - act as a language filter.
		if ( $screen && $screen->base === 'edit' ) {
			$this->build_list_filter_menu( $wp_admin_bar, $languages, 'post' );
			return;
		}

		// Term list screen - act as a language filter.
		if ( $screen && $screen->base === 'edit-tags' ) {
			$this->build_list_filter_menu( $wp_admin_bar, $languages, 'term' );
			return;
		}

		// Post editor - show translation edit links.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $screen && $screen->base === 'post' && ! empty( $_GET['post'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] );
			$this->build_editor_menu( $wp_admin_bar, $languages, $post_id, 'post' );
			return;
		}

		// Term editor - show translation edit links.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $screen && $screen->base === 'term' && ! empty( $_GET['tag_ID'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$term_id = absint( $_GET['tag_ID'] );
			$this->build_editor_menu( $wp_admin_bar, $languages, $term_id, 'term' );
			return;
		}

		// PerfLocale admin list pages - Strings, Translations, Assignments.
		// Same filter-by-language UX as native post/term list screens, with
		// the canonical ?perflocale_lang=<slug> query param so bookmarks and
		// switcher URLs work identically across every list in the plugin.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$gp = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		if ( in_array( $gp, [ 'perflocale-strings', 'perflocale-translations' ], true ) ) {
			$this->build_list_filter_menu( $wp_admin_bar, $languages, 'perflocale' );
			return;
		}

		// Other admin pages - no translation context, don't show the switcher.
	}

	/**
	 * Build the language filter menu for list screens (posts, pages, terms).
	 *
	 * The parent node shows the currently active filter (or "All Languages").
	 * Each dropdown item is a link that filters the list by that language.
	 * An "All Languages" option clears the filter.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @param array<object> $languages Active languages.
	 * @param string        $context 'post' or 'term'.
	 * @return void
	 */
	private function build_list_filter_menu( \WP_Admin_Bar $wp_admin_bar, array $languages, string $context ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';
		$active_lang   = null;

		if ( $active_filter !== '' ) {
			foreach ( $languages as $lang ) {
				if ( $lang->slug === $active_filter ) {
					$active_lang = $lang;
					break;
				}
			}
		}

		// Parent node: show filtered language or "All Languages".
		if ( $active_lang ) {
			$this->add_parent_node( $wp_admin_bar, $active_lang );
		} else {
			$wp_admin_bar->add_node(
				[
					'id'    => 'perflocale-lang',
					'title' => '<span class="perflocale-ab-flag">🌐</span> ' . esc_html__( 'All Languages', 'perflocale' ),
					'href'  => false,
					'meta'  => [ 'title' => __( 'Filter by language', 'perflocale' ) ],
				]
			);
		}

		// Build base URL for filter links (current list page without the
		// lang filter or pagination). Pagination is dropped so switching
		// language always lands the user on page 1 of the new result set.
		$base_url = remove_query_arg( [ 'perflocale_lang', 'paged' ] );

		// "All Languages" option (clears the filter).
		if ( $active_filter !== '' ) {
			$wp_admin_bar->add_node(
				[
					'parent' => 'perflocale-lang',
					'id'     => 'perflocale-lang-all',
					'title'  => '<span class="perflocale-ab-flag">🌐</span> ' . esc_html__( 'All Languages', 'perflocale' ),
					'href'   => esc_url( $base_url ),
					'meta'   => [ 'title' => __( 'Show all languages', 'perflocale' ) ],
				]
			);
		}

		// Each language as a filter link.
		foreach ( $languages as $lang ) {
			if ( $active_lang && $lang->slug === $active_lang->slug ) {
				continue;
			}

			$filter_url = add_query_arg( 'perflocale_lang', $lang->slug, $base_url );

			$this->add_language_node(
				$wp_admin_bar,
				$lang,
				$filter_url,
				sprintf(
				/* translators: %s: language name */
					__( 'Show %s only', 'perflocale' ),
					$lang->native_name ?: $lang->name
				)
			);
		}
	}

	/**
	 * Build the editor menu showing translation edit links.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @param array<object> $languages Active languages.
	 * @param int           $object_id Post or term ID.
	 * @param string        $type 'post' or 'term'.
	 * @return void
	 */
	private function build_editor_menu( \WP_Admin_Bar $wp_admin_bar, array $languages, int $object_id, string $type ): void {
		$repo        = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$object_type = $type === 'post' ? ObjectType::Post : ObjectType::Term;
		$links       = $repo->get_translations( $object_id, $object_type );

		// Detect current object's language.
		$current_lang = null;

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $object_id && ! empty( $link->language_slug ) ) {
				foreach ( $languages as $lang ) {
					if ( $lang->slug === $link->language_slug ) {
						$current_lang = $lang;
						break 2;
					}
				}
			}
		}

		// Fall back to default language.
		if ( ! $current_lang ) {
			$current_lang = $this->router->get_default_language();
		}

		if ( $current_lang ) {
			$this->add_parent_node( $wp_admin_bar, $current_lang );
		}

		// Build edit URLs for translations.
		$edit_urls = [];

		foreach ( $links as $link ) {
			if ( ! empty( $link->language_slug ) && (int) $link->object_id !== $object_id ) {
				// Only show edit links for objects the user can actually edit.
				if ( $type === 'post' ) {
					if ( ! current_user_can( 'edit_post', (int) $link->object_id ) ) {
						continue;
					}

					$url = get_edit_post_link( (int) $link->object_id, 'raw' );
				} else {
					if ( ! current_user_can( 'edit_term', (int) $link->object_id ) ) {
						continue;
					}

					$term = get_term( (int) $link->object_id );
					$url  = ( $term instanceof \WP_Term ) ? get_edit_term_link( $term->term_id, $term->taxonomy ) : '';
				}

				if ( $url ) {
					$edit_urls[ $link->language_slug ] = $url;
				}
			}
		}

		// Only show languages that have an actual translation to link to.
		foreach ( $languages as $lang ) {
			if ( $current_lang && $lang->slug === $current_lang->slug ) {
				continue;
			}

			$url = $edit_urls[ $lang->slug ] ?? '';

			if ( empty( $url ) ) {
				continue;
			}

			$this->add_language_node(
				$wp_admin_bar,
				$lang,
				$url,
				sprintf(
				/* translators: %s: language name */
					__( 'Edit %s translation', 'perflocale' ),
					$lang->native_name ?: $lang->name
				)
			);
		}

		// If no translation links exist, don't show the parent node either.
		if ( empty( $edit_urls ) ) {
			$wp_admin_bar->remove_node( 'perflocale-lang' );
		}
	}

	/**
	 * Add the parent (top-level) language node.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @param object        $lang Language object.
	 * @return void
	 */
	private function add_parent_node( \WP_Admin_Bar $wp_admin_bar, object $lang ): void {
		$flag = Helper::get_flag_emoji( $lang );
		$name = $lang->native_name ?: $lang->name;

		$wp_admin_bar->add_node(
			[
				'id'    => 'perflocale-lang',
				'title' => '<span class="perflocale-ab-flag">' . esc_html( $flag ) . '</span> ' . esc_html( $name ),
				'href'  => false,
				'meta'  => [ 'title' => __( 'Current Language', 'perflocale' ) ],
			]
		);
	}

	/**
	 * Add a language child node with a link.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @param object        $lang Language object.
	 * @param string        $url Link URL.
	 * @param string        $tooltip Tooltip text.
	 * @return void
	 */
	private function add_language_node( \WP_Admin_Bar $wp_admin_bar, object $lang, string $url, string $tooltip ): void {
		$flag = Helper::get_flag_emoji( $lang );
		$name = $lang->native_name ?: $lang->name;

		$wp_admin_bar->add_node(
			[
				'parent' => 'perflocale-lang',
				'id'     => 'perflocale-lang-' . $lang->slug,
				'title'  => '<span class="perflocale-ab-flag">' . esc_html( $flag ) . '</span> ' . esc_html( $name ),
				'href'   => esc_url( $url ),
				'meta'   => [ 'title' => $tooltip ],
			]
		);
	}

	/**
	 * Output minimal inline CSS for the admin bar language items.
	 *
	 * @return void
	 */
	public function inline_css(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar .perflocale-ab-flag{margin-right:4px;font-size:16px;vertical-align:middle;line-height:1;}#wpadminbar #wp-admin-bar-perflocale-lang .ab-submenu .ab-item{padding-top:4px;padding-bottom:4px;}'
		);
	}
}
