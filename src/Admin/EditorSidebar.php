<?php
/**
 * Gutenberg editor sidebar panel.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the block editor and Site Editor Translations panels and the Block translation panel.
 */
final class EditorSidebar {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_sidebar' ] );
	}

	/**
	 * Enqueue the Site Editor translations panel.
	 *
	 * Static configuration only: the Site Editor changes entity without a reload, so the script reads it from
	 * `core/editor`. It uses the post panel's REST route and its capability checks.
	 *
	 * @return void
	 */
	private function enqueue_site_editor_sidebar(): void {
		$settings   = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$post_types = $settings->get_translatable_post_types();

		// Only the types the Site Editor opens; the others have the post-editor panel.
		$site_editor_types = array_values(
			array_intersect(
				$post_types,
				[ 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ]
			)
		);

		// Nothing the Site Editor edits is translatable here: no script.
		if ( $site_editor_types === [] ) {
			return;
		}

		wp_enqueue_script(
			'perflocale-site-editor-sidebar',
			PERFLOCALE_URL . 'assets/js/site-editor-sidebar.js',
			[ 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices', 'wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-components' ],
			PERFLOCALE_VERSION,
			true
		);

		wp_set_script_translations( 'perflocale-site-editor-sidebar', 'perflocale' );

		wp_localize_script(
			'perflocale-site-editor-sidebar',
			'perflocaleSiteEditor',
			[
				'postTypes' => $site_editor_types,
				'i18n'      => [
					'panelTitle'    => __( 'Translations', 'perflocale' ),
					'current'       => __( 'Current', 'perflocale' ),
					'published'     => __( 'Published', 'perflocale' ),
					'draft'         => __( 'Draft', 'perflocale' ),
					'trashed'       => __( 'Trashed', 'perflocale' ),
					'none'          => __( 'None', 'perflocale' ),
					'edit'          => __( 'Edit', 'perflocale' ),
					'create'        => __( 'Create', 'perflocale' ),
					'created'       => __( 'Translation created.', 'perflocale' ),
					'createError'   => __( 'Failed to create translation.', 'perflocale' ),
					'loading'       => __( 'Loading...', 'perflocale' ),
					'loadError'     => __( 'Could not load translations.', 'perflocale' ),
					'draftHint'     => __( 'Not used until published - the original renders instead.', 'perflocale' ),
					'notCustomised' => __( 'This still comes from the theme. Save a change to customise it, then its translations appear here.', 'perflocale' ),
					/* translators: %s: language name, for example "English". */
					'renameMenu'    => __( 'The %s menu still has the original name. Rename it in its language: screen readers read the menu name aloud to visitors.', 'perflocale' ),
					/* translators: %s: language name, for example "English". */
					'renamePattern' => __( 'The %s pattern still has the original name. Rename it in its language, so the pattern lists tell them apart.', 'perflocale' ),
					'close'         => __( 'Close', 'perflocale' ),
					/* translators: 1: language name of the original menu, for example "English"; 2: language name of the open template part, for example "Deutsch". */
					'sharedMenu'    => __( 'This menu is shared with %1$s. Edit its %2$s translation instead.', 'perflocale' ),
					/* translators: 1: language name of the original pattern, for example "English"; 2: language name of the open template part, for example "Deutsch". */
					'sharedPattern' => __( 'This pattern is shared with %1$s. Edit its %2$s translation instead.', 'perflocale' ),
					'open'          => __( 'Open', 'perflocale' ),
					'publish'       => __( 'Publish', 'perflocale' ),
					'publishedNote' => __( 'Translation published.', 'perflocale' ),
					'publishError'  => __( 'Could not publish the translation.', 'perflocale' ),
				],
			]
		);

		wp_enqueue_style(
			'perflocale-editor-sidebar',
			PERFLOCALE_URL . 'assets/css/editor-sidebar.css',
			[],
			PERFLOCALE_VERSION
		);
	}

	/**
	 * Enqueue the sidebar script for the block editor.
	 *
	 * @return void
	 */
	public function enqueue_sidebar(): void {
		$screen = get_current_screen();

		// The Site Editor changes entity without a reload, so it has its own panel.
		if ( $screen && $screen->base === 'site-editor' ) {
			$this->enqueue_site_editor_sidebar();

			return;
		}

		if ( ! $screen || $screen->base !== 'post' ) {
			return;
		}

		$settings   = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$post_types = $settings->get_translatable_post_types();

		if ( ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		$asset_file = PERFLOCALE_DIR . 'assets/js/editor-sidebar.asset.php';
		$deps       = [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-hooks', 'wp-i18n', 'wp-api-fetch' ];
		$version    = PERFLOCALE_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset   = require $asset_file;
			$deps    = $asset['dependencies'] ?? $deps;
			$version = $asset['version'] ?? $version;
		}

		// The script fires its perflocale.* JavaScript hooks through wp.hooks.
		$deps = is_array( $deps ) ? array_values( array_filter( $deps, 'is_string' ) ) : [];
		if ( ! in_array( 'wp-hooks', $deps, true ) ) {
			$deps[] = 'wp-hooks';
		}

		wp_enqueue_script(
			'perflocale-editor-sidebar',
			PERFLOCALE_URL . 'assets/js/editor-sidebar.js',
			$deps,
			$version,
			true
		);

		// Without this the script's wp.i18n strings never load their JSON language pack.
		wp_set_script_translations( 'perflocale-editor-sidebar', 'perflocale' );

		// The type's singular label for the "no language yet" prompt ("This movie..."), else "post".
		$post_type_obj = get_post_type_object( $screen->post_type );
		$singular      = $post_type_obj instanceof \WP_Post_Type && ! empty( $post_type_obj->labels->singular_name )
			? strtolower( (string) $post_type_obj->labels->singular_name )
			: __( 'post', 'perflocale' );

		wp_localize_script(
			'perflocale-editor-sidebar',
			'perflocaleEditor',
			[
				'restUrl'         => rest_url( 'perflocale/v1/' ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'postId'          => get_the_ID(),
				'postType'        => $screen->post_type,
				'mtCreateNotice' => AdminController::mt_create_notice_waiting( (int) get_the_ID() ) ? MtCreateNotice::editor_message( (int) get_the_ID() ) : '',
				// SCRIPT_DEBUG reports a failing JavaScript hook callback once per hook in the console.
				'debug'           => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				'i18n'            => [
					'panelTitle'     => __( 'Translations', 'perflocale' ),
					'create'         => __( 'Create', 'perflocale' ),
					'edit'           => __( 'Edit', 'perflocale' ),
					'translate'      => __( 'Machine Translate', 'perflocale' ),
					'current'        => __( 'Current', 'perflocale' ),
					'published'      => __( 'Published', 'perflocale' ),
					'draft'          => __( 'Draft', 'perflocale' ),
					'empty'          => __( 'Empty', 'perflocale' ),
					'needsUpdate'    => __( 'Needs Update', 'perflocale' ),
					'setAs'          => __( 'Set as', 'perflocale' ),
					'unassignedNote' => $this->safe_sprintf_singular(
						/* translators: %s: singular post-type label, already lowercased (e.g. "post", "page", "movie", "product"). MUST contain exactly one %s placeholder. */
						__( 'This %s has no language yet. Pick the language it is written in:', 'perflocale' ),
						$singular
					),
				],
			]
		);

		// A small file instead of admin.css; keep it in step with admin.css's "Gutenberg Document Panel - Translations".
		wp_enqueue_style(
			'perflocale-editor-sidebar',
			PERFLOCALE_URL . 'assets/css/editor-sidebar.css',
			[],
			PERFLOCALE_VERSION
		);

		// The Block translation panel (every block through block-toolbar.js's walker), only with MT enabled.
		if ( ! $settings->mt_enabled() ) {
			return;
		}

		$mt_ready    = $this->mt_has_provider_configured();
		$mt_provider = $mt_ready ? (string) $settings->get_mt_provider() : '';

		// The block toolbar's language map, trimmed. has(), because get() throws for a missing service.
		$plugin_for_repo = \PerfLocale\Plugin::get_instance();
		$lang_repo       = $plugin_for_repo->has( 'cache' )
			? new \PerfLocale\Database\Repository\LanguageRepository( $plugin_for_repo->get( 'cache' ) )
			: null;
		$languages = [];

		if ( $lang_repo !== null ) {
			foreach ( $lang_repo->get_active() as $l ) {
				$languages[] = [
					'slug'  => (string) $l->slug,
					// Display only; `slug` is for comparisons.
					'bcp47' => \PerfLocale\Helper::format_locale_as_bcp47( (string) $l->slug ),
					'name'  => (string) ( $l->name ?? $l->native_name ?? $l->slug ),
				];
			}
		}

		// The edited post's language, resolved as the block toolbar does so both agree on the source.
		$post_source_lang = '';
		$plugin           = \PerfLocale\Plugin::get_instance();
		$current_post_id  = (int) get_the_ID();

		if ( $lang_repo !== null && $plugin->has( 'cache' ) ) {
			$manager   = new \PerfLocale\Translation\PostTranslationManager(
				$plugin->get( 'cache' ),
				$plugin->get( 'settings' )
			);
			$post_lang = $manager->detect_post_language( $current_post_id );

			if ( $post_lang && isset( $post_lang->slug ) ) {
				$post_source_lang = (string) $post_lang->slug;
			}
		}

		// As in the block toolbar: a translation gets "Fill all from source" instead of "Translate entire post".
		$default_lang               = $lang_repo !== null ? $lang_repo->get_default() : null;
		$default_lang_slug          = ( $default_lang && ! empty( $default_lang->slug ) ) ? (string) $default_lang->slug : '';
		$is_sibling                 = false;
		$source_post_id_for_sibling = 0;

		if (
			$default_lang_slug !== ''
			&& $post_source_lang !== ''
			&& $post_source_lang !== $default_lang_slug
			&& $current_post_id > 0
			&& $plugin->has( 'cache' )
		) {
			try {
				$group_repo                 = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
				$source_post_id_for_sibling = self::viewable_source_post_id(
					(array) $group_repo->get_translations( $current_post_id, \PerfLocale\Enum\ObjectType::Post ),
					(int) $default_lang->id
				);
				$is_sibling                 = $source_post_id_for_sibling > 0;
			} catch ( \Throwable $e ) {
				$is_sibling = false;
			}
		}

		wp_enqueue_script(
			'perflocale-block-translate-sidebar',
			PERFLOCALE_URL . 'assets/js/block-translate-sidebar.js',
			// The panel calls block-toolbar.js through window.perflocaleBlockTranslate.
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-data', 'wp-block-editor' ],
			PERFLOCALE_VERSION,
			true
		);

		// Without this the script's wp.i18n strings never load their JSON language pack.
		wp_set_script_translations( 'perflocale-block-translate-sidebar', 'perflocale' );

		wp_localize_script(
			'perflocale-block-translate-sidebar',
			'perflocaleBlockTranslateSidebar',
			[
				'languages'      => $languages,
				'postSourceLang' => $post_source_lang,
				'mtReady'        => $mt_ready,
				'mtProvider'     => $mt_provider,
				'mtSettingsUrl'  => $mt_ready ? admin_url( 'admin.php?page=perflocale-settings&tab=translation' ) : $this->mt_setup_url( admin_url( 'admin.php?page=perflocale-settings&tab=translation' ) ),
				'isSibling'      => $is_sibling,
				'sourceLang'     => $default_lang_slug,
				'sourcePostId'   => $source_post_id_for_sibling,
				'targetPostId'   => $current_post_id,
			]
		);
	}

	/**
	 * The group's default-language post, if it exists and the user may view it; otherwise 0.
	 *
	 * 0 keeps the id of a source the user may not view out of every script and hook.
	 *
	 * @param array<mixed> $links           The group's translation links.
	 * @param int          $default_lang_id The default language's id.
	 * @return int The source post id, or 0.
	 */
	public static function viewable_source_post_id( array $links, int $default_lang_id ): int {
		foreach ( $links as $link ) {
			if ( ! is_object( $link ) || ! is_numeric( $link->language_id ?? null ) || (int) $link->language_id !== $default_lang_id ) {
				continue;
			}

			$candidate_id = is_numeric( $link->object_id ?? null ) ? (int) $link->object_id : 0;

			if ( $candidate_id > 0 && get_post( $candidate_id ) instanceof \WP_Post && \PerfLocale\Helper::user_can_view_object( $candidate_id ) ) {
				return $candidate_id;
			}
		}

		return 0;
	}

	/**
	 * Whether the selected MT provider is ready, as BlockToolbarAssets checks it.
	 *
	 * @return bool
	 */
	/**
	 * Apply sprintf with one %s, using the English format when a translation miscounts it.
	 *
	 * @param string $format Translated format string.
	 * @param string $arg    Singular substitution value.
	 * @return string
	 */
	private function safe_sprintf_singular( string $format, string $arg ): string {
		// Exactly one literal %s (not %%s, %d or %1$s).
		$matches = preg_match_all( '/(?<!%)%s/', $format );

		if ( $matches !== 1 ) {
			$fallback = 'This %s has no language yet. Pick the language it is written in:';
			return sprintf( $fallback, $arg );
		}

		return sprintf( $format, $arg );
	}

	private function mt_has_provider_configured(): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return false;
		}

		$service = new \PerfLocale\MachineTranslation\TranslationService(
			$plugin->get( 'settings' ),
			$plugin->get( 'cache' )
		);

		// The selected provider, not any provider with a key: a click must work at request time.
		return $service->is_active_provider_ready();
	}

	/**
	 * Setup link for a site whose selected provider is not ready.
	 *
	 * @param string $fallback PerfLocale settings URL.
	 * @return string
	 */
	private function mt_setup_url( string $fallback ): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return $fallback;
		}

		$service = new \PerfLocale\MachineTranslation\TranslationService(
			$plugin->get( 'settings' ),
			$plugin->get( 'cache' )
		);

		return $service->setup_url( $fallback );
	}
}
