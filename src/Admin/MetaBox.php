<?php
/**
 * Translation meta box for the classic editor.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a translation meta box to the classic post editor.
 *
 * Shows all language versions and their status, with links to
 * create or edit translations.
 */
final class MetaBox {

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
		$this->cache = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_meta_copy_errors' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_language_save_error' ] );
		// NOTE: the `_perflocale_meta_copy_errors` save_post cleanup is
		// registered in Bootstrap at always-loaded scope (NOT here),
		// because MetaBox itself is admin-only (registered behind
		// `is_admin() && ! wp_doing_ajax()`) and the meta needs to clear
		// on REST API saves (block editor) and WP-CLI saves too — neither
		// of which loads MetaBox. See Bootstrap::register_post_meta_cleanup_hooks().
		add_action( 'admin_footer-post.php', [ $this, 'inject_term_language_badges' ] );
		add_action( 'admin_footer-post-new.php', [ $this, 'inject_term_language_badges' ] );

		// Filter the category/tag checklist on post.php + post-new.php so
		// translators only see terms matching the post's current language -
		// prevents accidentally assigning an English term to a Bulgarian post.
		add_filter( 'wp_terms_checklist_args', [ $this, 'filter_terms_checklist_by_language' ], 10, 2 );
	}

	/**
	 * Restrict the taxonomy checklist on post edit screens to terms in the
	 * same language as the post being edited.
	 *
	 * Core's `wp_terms_checklist()` calls `get_terms()` with the args we
	 * return here. We can add a lightweight flag that PostQueryFilter +
	 * TermQueryFilter recognize as "treat this as a language-scoped query"
	 * (same mechanism the public frontend uses, reused here in admin).
	 *
	 * The filter is gated by:
	 *   - only firing inside post.php / post-new.php for translatable post types
	 *   - only active when there are 2+ active languages
	 *   - the `perflocale/admin/filter_terms_checklist` filter (opt-out escape hatch for
	 *     operators who want the full flat list).
	 *
	 * @param mixed $args    Checklist args. Core documents this as `array|string`
	 *                       (it is whatever the caller handed
	 *                       `wp_terms_checklist()`, BEFORE core's own
	 *                       `wp_parse_args()`), and `wp_parse_args()` also
	 *                       accepts an object — so anything can arrive.
	 * @param int   $post_id Post ID the checklist is for.
	 * @return mixed The args, language-scoped when they were an array,
	 *               otherwise returned exactly as received.
	 */
	public function filter_terms_checklist_by_language( $args, int $post_id ) {
		// `wp_terms_checklist( $post_id, 'taxonomy=category' )` is a documented,
		// supported call — core's own filter docblock says `array|string $args`
		// and its `wp_parse_args()` takes a query string or an object too. An
		// `array` type declaration turned any such caller into a TypeError at
		// argument binding, before this method's own guards could run. Hand a
		// non-array shape straight back UNCHANGED: coercing it to an array here
		// would silently discard the caller's arguments instead.
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$screen = get_current_screen();

		if ( ! $screen || ( $screen->base !== 'post' && $screen->id !== 'post' ) ) {
			return $args;
		}

		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! in_array( $screen->post_type, $settings->get_translatable_post_types(), true ) ) {
			return $args;
		}

		/**
		 * Filter whether to scope the taxonomy checklist to the post's language.
		 *
		 * Return false to restore core WordPress behaviour (show all terms
		 * regardless of language).
		 *
		 * @hook perflocale/admin/filter_terms_checklist
		 * @param bool $enabled Default true when 2+ languages active.
		 * @param int  $post_id Post ID.
		 * @param array $args    Checklist args.
		 */
		$enabled = apply_filters( 'perflocale/admin/filter_terms_checklist', true, $post_id, $args );

		if ( ! $enabled ) {
			return $args;
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );

		if ( count( $lang_repo->get_active() ) < 2 ) {
			return $args;
		}

		$manager   = new PostTranslationManager( $this->cache, $settings );
		$post_lang = $manager->detect_post_language( $post_id );

		if ( ! $post_lang ) {
			// Post has no language yet (fresh "Add new") - fall back to
			// default language so the checklist doesn't span all languages.
			$default = $lang_repo->get_default();
			if ( ! $default ) {
				return $args;
			}
			$post_lang = $default;
		}

		// The checklist's get_terms() call is routed through TermQueryFilter,
		// which reads the `perflocale_language_id` arg as the highest-priority
		// language signal. Passing it explicitly covers both post.php (where
		// TermQueryFilter would detect the language from the post anyway) AND
		// post-new.php for a fresh "Add New" with no post context yet.
		$args['perflocale_language_id'] = (int) $post_lang->id;

		return $args;
	}

	/**
	 * Register and attach the metabox styles on the classic post editor.
	 *
	 * Uses the standard wp_register_style( handle, false, ... ) + wp_add_inline_style
	 * pattern so the CSS rides on WordPress' normal print-styles pipeline
	 * (emitted during wp_head()) rather than being echoed ad-hoc from the
	 * metabox body.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			// Block editor uses its own React sidebar panel.
			return;
		}

		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! $screen || ! in_array( $screen->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'perflocale-metabox',
			PERFLOCALE_URL . 'assets/css/metabox.css',
			[],
			PERFLOCALE_VERSION
		);

	}

	/**
	 * Add the translation meta box to translatable post types.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		// Skip in block editor - the Gutenberg PluginDocumentSettingPanel handles it.
		$screen = get_current_screen();

		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		$settings   = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$post_types = $settings->get_translatable_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'perflocale-translations',
				__( 'Translations', 'perflocale' ),
				[ $this, 'render' ],
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$lang_repo    = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$manager      = new PostTranslationManager( $this->cache, \PerfLocale\Plugin::get_instance()->get( 'settings' ) );
		$languages    = $lang_repo->get_active();
		$translations = $manager->get_translations( $post->ID );

		wp_nonce_field( 'perflocale_meta_box', 'perflocale_meta_nonce' );

		$post_lang = $manager->detect_post_language( $post->ID );

		echo '<ul class="perflocale-mb-list">';

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $translations[ $lang->slug ] ?? null;

			if ( $has_translation && $translated_id && ! get_post( $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			$is_current = ( $post_lang && $post_lang->slug === $lang->slug );
			$row_class  = 'perflocale-mb-item' . ( $is_current ? ' perflocale-mb-item--current' : '' );

			echo '<li class="' . esc_attr( $row_class ) . '">';
			echo '<span class="perflocale-mb-left">';
			echo '<span class="perflocale-mb-badge">' . esc_html( \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ) ) . '</span>';
			echo '<span class="perflocale-mb-native">' . esc_html( $lang->native_name ?: $lang->name ) . '</span>';
			echo '</span>';

			if ( $is_current ) {
				echo '<span class="perflocale-mb-pill perflocale-mb-pill--current">' . esc_html__( 'Current', 'perflocale' ) . '</span>';
			} elseif ( $has_translation && $translated_id ) {
				$edit_url = get_edit_post_link( $translated_id );

				if ( $edit_url ) {
					echo '<a href="' . esc_url( $edit_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--edit">' . esc_html__( 'Edit', 'perflocale' ) . '</a>';
				}
			} elseif ( 'auto-draft' === $post->post_status ) {
				echo '<span class="perflocale-mb-pill perflocale-mb-pill--disabled" title="' . esc_attr__( 'Save the post before creating translations.', 'perflocale' ) . '">+ ' . esc_html__( 'Create', 'perflocale' ) . '</span>';
			} else {
				$create_url = add_query_arg(
					[
						'action'      => 'perflocale_create_translation',
						'source_id'   => $post->ID,
						'target_lang' => $lang->slug,
						'_wpnonce'    => wp_create_nonce( 'perflocale_create_' . $post->ID ),
					],
					admin_url( 'admin-post.php' )
				);

				echo '<a href="' . esc_url( $create_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--create">+ ' . esc_html__( 'Create', 'perflocale' ) . '</a>';
			}

			echo '</li>';
		}

		echo '</ul>';

		echo '<div class="perflocale-mb-footer">';
		$post_type_obj  = get_post_type_object( $post->post_type );
		$singular_label = $post_type_obj ? $post_type_obj->labels->singular_name : __( 'Post', 'perflocale' );
		/* translators: %s: post type singular name (e.g. "Product", "Page") */
		echo '<label for="perflocale_post_language">' . esc_html( sprintf( __( '%s Language', 'perflocale' ), $singular_label ) ) . '</label>';
		echo '<select id="perflocale_post_language" name="perflocale_post_language">';

		$select_allowed = [
			'option' => [
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			],
		];
		foreach ( $languages as $lang ) {
			echo wp_kses(
				'<option value="' . esc_attr( $lang->slug ) . '"' . selected( $post_lang && $post_lang->slug === $lang->slug, true, false ) . '>' . esc_html( $lang->name ) . '</option>',
				$select_allowed
			);
		}

		echo '</select>';
		echo '</div>';

		// Per-post sync opt-out. Products are skipped: the WooCommerce
		// Advanced-tab checkbox owns the same meta key there, and rendering
		// both would double the control on one screen.
		if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			$optout = get_post_meta( $post->ID, \PerfLocale\Translation\ContentSync::SYNC_OPTOUT_META, true ) === 'yes';

			echo '<div class="perflocale-mb-footer">';
			echo '<label style="display:flex;gap:6px;align-items:flex-start;">';
			echo '<input type="checkbox" name="perflocale_sync_optout" value="yes"' . checked( $optout, true, false ) . ' style="margin-top:2px;">';
			echo '<span>' . esc_html__( 'Independent across languages — do not sync this post\'s shared fields (featured image, builder layout, configured sync fields) with its translations, in either direction.', 'perflocale' ) . '</span>';
			echo '</label>';
			// Marker so programmatic saves that never render this box cannot
			// clear the flag (an unchecked checkbox is indistinguishable from
			// an absent one without it).
			echo '<input type="hidden" name="perflocale_sync_optout_present" value="1">';
			echo '</div>';
		}

		// Per-post SEO opt-out — drops this translation from hreflang tags
		// and sitemap alternate links. Rendered for products too (unlike the
		// sync opt-out above, no other UI owns this flag).
		$seo_excluded = get_post_meta( $post->ID, \PerfLocale\Helper::SEO_EXCLUDE_META, true ) === 'yes';

		echo '<div class="perflocale-mb-footer">';
		echo '<label style="display:flex;gap:6px;align-items:flex-start;">';
		echo '<input type="checkbox" name="perflocale_seo_exclude" value="yes"' . checked( $seo_excluded, true, false ) . ' style="margin-top:2px;">';
		echo '<span>' . esc_html__( 'Hide from hreflang and sitemap alternates — search engines will not be told this translation is an alternate of its siblings.', 'perflocale' ) . '</span>';
		echo '</label>';
		echo '<input type="hidden" name="perflocale_seo_exclude_present" value="1">';
		echo '</div>';
	}

	/**
	 * Save meta box data when the post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function save_meta_box( int $post_id, ?\WP_Post $post = null ): void {
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! isset( $_POST['perflocale_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_meta_nonce'] ), 'perflocale_meta_box' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Sync opt-out checkbox — only when the box that renders it actually
		// submitted (marker present), so REST/CLI saves never clear the flag.
		if ( isset( $_POST['perflocale_sync_optout_present'] ) ) {
			if ( isset( $_POST['perflocale_sync_optout'] ) ) {
				update_post_meta( $post_id, \PerfLocale\Translation\ContentSync::SYNC_OPTOUT_META, 'yes' );
			} else {
				delete_post_meta( $post_id, \PerfLocale\Translation\ContentSync::SYNC_OPTOUT_META );
			}
		}

		// SEO-exclude checkbox — same marker guard. Stale hreflang transients
		// are cleared by CacheInvalidator::on_save_post (post + siblings) on
		// this same save request, so no extra invalidation is needed here.
		if ( isset( $_POST['perflocale_seo_exclude_present'] ) ) {
			if ( isset( $_POST['perflocale_seo_exclude'] ) ) {
				update_post_meta( $post_id, \PerfLocale\Helper::SEO_EXCLUDE_META, 'yes' );
			} else {
				delete_post_meta( $post_id, \PerfLocale\Helper::SEO_EXCLUDE_META );
			}
		}

		$language = sanitize_key( $_POST['perflocale_post_language'] ?? '' );

		if ( $language !== '' ) {
			$manager = new PostTranslationManager( $this->cache, \PerfLocale\Plugin::get_instance()->get( 'settings' ) );

			if ( ! $manager->set_post_language( $post_id, $language ) ) {
				// Surface the failure to the user who just clicked Update —
				// without this, the editor silently shows the OLD language
				// on next load and the user assumes their choice stuck.
				// Same one-shot transient pattern as maybe_show_language_save_error.
				$user_id = get_current_user_id();

				if ( $user_id > 0 ) {
					set_transient(
						'perflocale_lang_save_error_' . $user_id,
						sprintf(
							/* translators: 1: post ID, 2: language slug. */
							__( 'PerfLocale could not save the language selection ("%2$s") for post %1$d. The language may have been deleted, or a database error occurred — please try again.', 'perflocale' ),
							$post_id,
							$language
						),
						60
					);
				}
			}
		}

	}

	/**
	 * One-shot error notice when a post or term language save failed.
	 *
	 * Written by save_meta_box() here and TermMetaBox::save_term_language()
	 * — both funnel through the same per-user transient so this single
	 * renderer covers post AND term screens (admin_notices fires on both).
	 *
	 * @return void
	 */
	public function maybe_show_language_save_error(): void {
		$user_id = get_current_user_id();
		if ( $user_id === 0 ) {
			return;
		}

		$key     = 'perflocale_lang_save_error_' . $user_id;
		$message = get_transient( $key );

		if ( ! $message ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( (string) $message )
		);
	}

	/**
	 * Surface copy_* failures from `create_translation()` to the
	 * translator who just opened the freshly-created translation post.
	 *
	 * `PostTranslationManager::create_translation()` stores per-step
	 * failures in the `_perflocale_meta_copy_errors` post meta when
	 * any of copy_post_meta / copy_featured_image / copy_taxonomy_terms
	 * throw. Without surfacing them, the translator sees a translation
	 * with missing featured image / ACF fields / categories and no
	 * explanation. We render a one-shot warning and delete the meta so
	 * the notice only shows once.
	 *
	 * @return void
	 */
	public function maybe_show_meta_copy_errors(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'post' ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this method is purely read-only since the state-change (delete_post_meta) was moved to clear_meta_copy_errors_on_save on the save_post hook (which WP-core has already nonce-verified). The capability check below is belt-and-braces defensive depth, not the primary security boundary.
		if ( $post_id <= 0 ) {
			return;
		}

		// Defensive capability check on the post_id parsed from $_GET. WP's
		// admin_notices hook fires after the post.php pre-screen capability
		// gate (an unauthorized user gets the "Sorry, you are not allowed to
		// edit this item." page before we run), so reaching this code path
		// already implies the user can edit $post_id. Repeating the check
		// here makes the intent explicit.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$errors = get_post_meta( $post_id, '_perflocale_meta_copy_errors', true );
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return;
		}

		// State change moved to clear_meta_copy_errors_on_save (save_post
		// hook). The notice persists across views until the user takes a
		// real save action — better educational UX and removes the
		// "GET-driven state change" pattern entirely from this callback.

		$step_label_map = [
			'post_meta'      => __( 'post meta + custom fields', 'perflocale' ),
			'featured_image' => __( 'featured image', 'perflocale' ),
			'taxonomy_terms' => __( 'categories and tags', 'perflocale' ),
		];

		$step_labels = [];
		foreach ( $errors as $err ) {
			$step                 = (string) ( $err['step'] ?? '' );
			$step_labels[ $step ] = $step_label_map[ $step ] ?? $step;
		}

		// Escape late: the dynamic value is escaped with esc_html() at the
		// point of output, inside the wp_kses_post()-allowed notice markup.
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s is a comma-separated list of items (e.g. "featured image, categories and tags"). */
					__( 'PerfLocale created this translation, but the following items did not copy from the source post: %s. Please fill them in manually.', 'perflocale' ),
					implode( ', ', array_values( $step_labels ) )
				)
			)
		);
	}

	/**
	 * Inject JavaScript to add language badges to taxonomy checklist items.
	 *
	 * Shows a small language code (e.g. "EN", "DE") next to each term name
	 * in the category/tag checklists on the post edit screen.
	 *
	 * @return void
	 */
	public function inject_term_language_badges(): void {
		$settings   = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$post_types = $settings->get_translatable_post_types();
		$screen     = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return;
		}

		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$langs_table  = \PerfLocale\Database\Schema::table( 'languages' );

		// Only fetch term-language mappings for taxonomies registered on this post type.
		$taxonomies = get_object_taxonomies( $screen->post_type, 'names' );

		if ( empty( $taxonomies ) ) {
			return;
		}

		$tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.object_id, lang.slug AS lang_slug
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id AND g.type = 'term'
				INNER JOIN %i lang ON l.language_id = lang.id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = l.object_id
				WHERE tt.taxonomy IN ({$tax_placeholders})",
				$links_table,
				$groups_table,
				$langs_table,
				...$taxonomies
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$term_lang_map = [];

		foreach ( $results as $row ) {
			$term_lang_map[ (int) $row->object_id ] = \PerfLocale\Helper::format_locale_as_bcp47( (string) $row->lang_slug );
		}

		if ( empty( $term_lang_map ) ) {
			return;
		}

		// Get the default language slug to hide badges for default-language terms.
		$default_lang = $lang_repo->get_default();
		$default_slug = $default_lang ? \PerfLocale\Helper::format_locale_as_bcp47( $default_lang->slug ) : '';

		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleTermBadges=' . wp_json_encode(
				[
					'langMap'     => $term_lang_map,
					'defaultLang' => $default_slug,
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);

		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var langMap=perflocaleTermBadges.langMap;' .
				'var defaultLang=perflocaleTermBadges.defaultLang;' .
				'function addTermBadges(){' .
					'var labels=document.querySelectorAll(".categorychecklist li label, .tagchecklist li");' .
					'labels.forEach(function(label){' .
						'if(label.dataset.perflocaleTermLabeled)return;' .
						'label.dataset.perflocaleTermLabeled="1";' .
						'var input=label.querySelector(\'input[type="checkbox"], input[type="radio"]\');' .
						'if(!input)return;' .
						'var termId=parseInt(input.value,10);' .
						'if(!termId||!langMap[termId])return;' .
						'if(langMap[termId]===defaultLang)return;' .
						'var badge=document.createElement("span");' .
						'badge.style.cssText="display:inline-block;background:#e5e7eb;color:#374151;font-size:10px;font-weight:600;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;";' .
						'badge.textContent=langMap[termId];' .
						'label.appendChild(badge);' .
					'});' .
				'}' .
				'addTermBadges();' .
				'var observer=new MutationObserver(function(){setTimeout(addTermBadges,50);});' .
				'var containers=document.querySelectorAll(".categorydiv, .tagsdiv");' .
				'containers.forEach(function(el){' .
					'observer.observe(el,{childList:true,subtree:true});' .
				'});' .
			'})();'
		);
	}
}
