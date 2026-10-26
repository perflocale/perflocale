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
 * Adds the translation meta box to the classic post editor.
 */
final class MetaBox {

	/**
	 * Redirect query argument: how many translations a save or Bulk Edit's Sync Fields did not reach.
	 */
	public const SYNC_SKIPPED_ARG = 'perflocale_sync_skipped';

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
		add_action( 'admin_notices', [ $this, 'maybe_show_sync_skipped' ] );
		add_filter( 'redirect_post_location', [ $this, 'add_sync_skipped_arg' ], 10, 2 );
		add_filter( 'wp_redirect', [ $this, 'add_bulk_sync_skipped_arg' ], 10, 1 );
		add_filter( 'removable_query_args', [ $this, 'remove_sync_skipped_arg' ] );
		// The `_perflocale_meta_copy_errors` cleanup is a save_post hook in Bootstrap: REST and CLI saves skip MetaBox.

		// The term checklist shows only terms in the post's language.
		add_filter( 'wp_terms_checklist_args', [ $this, 'filter_terms_checklist_by_language' ], 10, 2 );
	}

	/**
	 * Restrict the post editor's term checklist to the post's language (two or more languages, opt-out filter).
	 *
	 * @param mixed $args    Checklist args as the caller passed them: an array, a query string or an object.
	 * @param int   $post_id Post ID the checklist is for.
	 * @return mixed The args, language-scoped when they were an array, otherwise unchanged.
	 */
	public function filter_terms_checklist_by_language( $args, int $post_id ) {
		// A query string or object is valid input (no array type hint): return it unchanged, not coerced.
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
		 * Return false to show all terms, as core does.
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
			// A new post has no language yet: use the default language.
			$default = $lang_repo->get_default();
			if ( ! $default ) {
				return $args;
			}
			$post_lang = $default;
		}

		// TermQueryFilter reads this arg first; explicit so post-new.php, without a post context, is scoped too.
		$args['perflocale_language_id'] = (int) $post_lang->id;

		return $args;
	}

	/**
	 * Enqueue the metabox stylesheet on the classic editor of a translatable post type.
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
		$plugin    = \PerfLocale\Plugin::get_instance();
		$lang_repo = $plugin->get( 'lang_repo' );
		$manager   = new PostTranslationManager( $this->cache, $plugin->get( 'settings' ) );
		$languages = $lang_repo->get_active();

		wp_nonce_field( 'perflocale_meta_box', 'perflocale_meta_nonce' );

		$post_lang = $manager->detect_post_language( $post->ID );

		// The shared language list. The inputs below stay here: only save_meta_box() handles them, see
		// docs-ai/11-code-rationale.md, "TranslationsPanel: host editors".
		$plugin->get( 'translations_panel' )->render( $post->ID, [ 'context' => 'metabox' ] );

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

		// Not on products: the WooCommerce Advanced-tab checkbox owns the same meta key there.
		if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			$optout = get_post_meta( $post->ID, \PerfLocale\Translation\SyncMeta::SYNC_OPTOUT_META, true ) === 'yes';

			echo '<div class="perflocale-mb-footer">';
			echo '<label style="display:flex;gap:6px;align-items:flex-start;">';
			echo '<input type="checkbox" name="perflocale_sync_optout" value="yes"' . checked( $optout, true, false ) . ' style="margin-top:2px;">';
			echo '<span>' . esc_html__( 'Independent across languages - do not sync this post\'s shared fields (featured image, configured sync fields) with its translations, in either direction.', 'perflocale' ) . '</span>';
			echo '</label>';
			// Marker: an unchecked box sends nothing, so only a submit with this marker may clear the flag.
			echo '<input type="hidden" name="perflocale_sync_optout_present" value="1">';
			echo '</div>';
		}

		// SEO opt-out (hreflang and sitemap alternates), on products too: no other UI owns this flag.
		$seo_excluded = get_post_meta( $post->ID, \PerfLocale\Helper::SEO_EXCLUDE_META, true ) === 'yes';

		echo '<div class="perflocale-mb-footer">';
		echo '<label style="display:flex;gap:6px;align-items:flex-start;">';
		echo '<input type="checkbox" name="perflocale_seo_exclude" value="yes"' . checked( $seo_excluded, true, false ) . ' style="margin-top:2px;">';
		echo '<span>' . esc_html__( 'Hide from hreflang and sitemap alternates - search engines will not be told this translation is an alternate of its siblings.', 'perflocale' ) . '</span>';
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
		// Nullable: core passes null for a post deleted after the write, and some plugins pass one argument.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! isset( $_POST['perflocale_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_meta_nonce'] ), 'perflocale_meta_box' ) ) {
			return;
		}

		// The form is for its own post (post_ID), not a sibling or stub saved in the same request.
		$form_post = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( $form_post !== $post_id ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only when the box submitted (marker present), so REST and CLI saves never clear the flag.
		if ( isset( $_POST['perflocale_sync_optout_present'] ) ) {
			if ( isset( $_POST['perflocale_sync_optout'] ) ) {
				update_post_meta( $post_id, \PerfLocale\Translation\SyncMeta::SYNC_OPTOUT_META, 'yes' );
			} else {
				delete_post_meta( $post_id, \PerfLocale\Translation\SyncMeta::SYNC_OPTOUT_META );
			}
		}

		// Same marker guard; CacheInvalidator::on_save_post clears the hreflang caches on this save.
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
				// Shown once by maybe_show_language_save_error(); otherwise the editor silently shows the old language.
				$user_id = get_current_user_id();

				if ( $user_id > 0 ) {
					set_transient(
						'perflocale_lang_save_error_' . $user_id,
						sprintf(
							/* translators: 1: post ID, 2: language slug. */
							__( 'PerfLocale could not save the language selection ("%2$s") for post %1$d. The language may have been deleted, or a database error occurred - please try again.', 'perflocale' ),
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
	 * TermMetaBox::save_term_language() writes the same per-user transient, so this covers term screens too.
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
	 * Add the skipped-translations count (ContentSync::skipped_siblings()) to the edit-screen save redirect.
	 *
	 * @param string|mixed $location Redirect URL.
	 * @param int|mixed    $post_id  Saved post.
	 * @return string|mixed
	 */
	public function add_sync_skipped_arg( mixed $location = '', mixed $post_id = 0 ): mixed {
		if ( ! is_string( $location ) || ! is_numeric( $post_id ) ) {
			return $location;
		}

		$count = \PerfLocale\Translation\ContentSync::skipped_siblings( (int) $post_id );

		return $count > 0 ? add_query_arg( self::SYNC_SKIPPED_ARG, $count, $location ) : $location;
	}

	/**
	 * Add the skipped-translations count (ContentSync::bulk_skipped()) to the Bulk Edit redirect.
	 *
	 * Core builds that redirect from the list's address, so an earlier count is removed first.
	 *
	 * @param string|mixed $location Redirect URL.
	 * @return string|mixed
	 */
	public function add_bulk_sync_skipped_arg( mixed $location = '' ): mixed {
		$count = \PerfLocale\Translation\ContentSync::bulk_skipped();

		if ( null === $count || ! is_string( $location ) ) {
			return $location;
		}

		$location = remove_query_arg( self::SYNC_SKIPPED_ARG, $location );

		return $count > 0 && did_action( 'bulk_edit_posts' ) > 0 ? add_query_arg( self::SYNC_SKIPPED_ARG, $count, $location ) : $location;
	}

	/**
	 * Core removes the notice argument from the address bar once shown.
	 *
	 * @param array<int, string>|mixed $args Removable query arguments.
	 * @return array<int, string>|mixed
	 */
	public function remove_sync_skipped_arg( mixed $args = [] ): mixed {
		if ( is_array( $args ) ) {
			$args[] = self::SYNC_SKIPPED_ARG;
		}

		return $args;
	}

	/**
	 * Warning that a save or Bulk Edit did not copy Sync Fields to translations the user may not edit.
	 *
	 * Display only: the count comes from that save's redirect.
	 *
	 * @return void
	 */
	public function maybe_show_sync_skipped(): void {
		if ( ! in_array( $GLOBALS['pagenow'] ?? '', [ 'post.php', 'edit.php' ], true ) ) {
			return;
		}

		$count = isset( $_GET[ self::SYNC_SKIPPED_ARG ] ) && is_string( $_GET[ self::SYNC_SKIPPED_ARG ] ) ? absint( wp_unslash( $_GET[ self::SYNC_SKIPPED_ARG ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: a number shown back to the user who saved.

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of translations. */
					_n( 'Sync Fields were not copied to %d translation that you cannot edit.', 'Sync Fields were not copied to %d translations that you cannot edit.', $count, 'perflocale' ),
					$count
				)
			)
		);
	}

	/**
	 * Warn which steps of create_translation() failed to copy (`_perflocale_meta_copy_errors`).
	 *
	 * Read-only: Bootstrap's save_post hook clears the meta on the next real save.
	 *
	 * @return void
	 */
	public function maybe_show_meta_copy_errors(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'post' ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: Bootstrap's save_post callback clears the warning meta behind core's save nonce; the capability check below is defence in depth.
		if ( $post_id <= 0 ) {
			return;
		}

		// Defence in depth: post.php already refused a user who may not edit this post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$errors = get_post_meta( $post_id, '_perflocale_meta_copy_errors', true );
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return;
		}

		// No state change on this GET: the notice stays until the next real save.

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

		// Escaped late, at the output.
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
}
