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
 * Registers and enqueues the Gutenberg/Block Editor sidebar panel.
 *
 * The sidebar panel is a React component that shows all language
 * versions and their status, with actions to create, edit, or
 * machine-translate.
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
	 * Enqueue the sidebar script for the block editor.
	 *
	 * @return void
	 */
	public function enqueue_sidebar(): void {
		$screen = get_current_screen();

		if ( ! $screen || $screen->base !== 'post' ) {
			return;
		}

		$settings   = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$post_types = $settings->get_translatable_post_types();

		if ( ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		$asset_file = PERFLOCALE_DIR . 'assets/js/editor-sidebar.asset.php';
		$deps       = [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch' ];
		$version    = PERFLOCALE_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset   = require $asset_file;
			$deps    = $asset['dependencies'] ?? $deps;
			$version = $asset['version'] ?? $version;
		}

		wp_enqueue_script(
			'perflocale-editor-sidebar',
			PERFLOCALE_URL . 'assets/js/editor-sidebar.js',
			$deps,
			$version,
			true
		);

		// Load the JS translations for this handle. Without it the wp.i18n.__()
		// strings in the script file stay untranslated no matter what a
		// translator supplies — WordPress only wires a script's JSON language
		// pack when the handle is registered here. No-ops until a language pack
		// exists, so it is safe on a fresh install.
		wp_set_script_translations( 'perflocale-editor-sidebar', 'perflocale' );

		// Resolve the singular label of the post type being edited so the
		// "no language yet" prompt can read naturally on custom post types
		// ("This movie…", "This event…") instead of always saying "post".
		// Fall back to the generic word when no singular label is registered.
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

		// Editor-sidebar context needs only the Translations Document Panel
		// ruleset (+ spinner keyframe). Ship the dedicated ~6 KB file
		// rather than the full ~92 KB admin.css. Extracted styles stay in
		// sync with admin.css's "Gutenberg Document Panel - Translations".
		wp_enqueue_style(
			'perflocale-editor-sidebar',
			PERFLOCALE_URL . 'assets/css/editor-sidebar.css',
			[],
			PERFLOCALE_VERSION
		);

		// Separate "Block translation" sidebar panel: batch-translate
		// every block in the open post via the recursive walker exposed by
		// block-toolbar.js. Loaded as its own JS file so the existing sidebar's
		// translation-management panel stays a standalone concern.
		//
		// Visibility: only enqueue when the user has opted into machine
		// translation (Settings → Addons → Machine Translation → Enable). On a fresh
		// install, or for sites that translate manually, the panel stays
		// hidden so the editor sidebar isn't cluttered with a feature that
		// can't be used.
		if ( ! $settings->mt_enabled() ) {
			return;
		}

		$mt_ready    = $this->mt_has_provider_configured();
		$mt_provider = $mt_ready ? (string) $settings->get_mt_provider() : '';

		// Build a compact language map mirroring what block-toolbar receives:
		// just enough for the sidebar to render the "Translate to X" buttons.
		// Plugin::get() throws on missing services, so the truthy check on its
		// return value is statically dead — use has() to gate.
		$plugin_for_repo = \PerfLocale\Plugin::get_instance();
		$lang_repo       = $plugin_for_repo->has( 'cache' )
			? new \PerfLocale\Database\Repository\LanguageRepository( $plugin_for_repo->get( 'cache' ) )
			: null;
		$languages = [];

		if ( $lang_repo !== null ) {
			foreach ( $lang_repo->get_active() as $l ) {
				$languages[] = [
					'slug'  => (string) $l->slug,
					// Canonical BCP 47 form, used by the JS for visible
					// labels (e.g. confirm() dialogs). `slug` is reserved
					// for identifier comparisons.
					'bcp47' => \PerfLocale\Helper::format_locale_as_bcp47( (string) $l->slug ),
					'name'  => (string) ( $l->name ?? $l->native_name ?? $l->slug ),
				];
			}
		}

		// Resolve the open post's source language for the sidebar's "exclude
		// source language from the buttons" filter. Mirrors the block toolbar's
		// POST_SOURCE_LANG resolution so both UIs agree on what "source" is.
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

		// Sibling-aware payload: mirrors the block-toolbar's resolution so
		// the sidebar's "Translate entire post" can switch to a single
		// "Fill all from source" action when the open post is a translation.
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
				$group_repo   = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
				$translations = $group_repo->get_translations( $current_post_id, \PerfLocale\Enum\ObjectType::Post );

				foreach ( (array) $translations as $link ) {
					if ( (int) ( $link->language_id ?? 0 ) !== (int) $default_lang->id ) {
						continue;
					}

					$candidate_id = (int) ( $link->object_id ?? 0 );

					if ( $candidate_id > 0 && get_post( $candidate_id ) instanceof \WP_Post ) {
						$source_post_id_for_sibling = $candidate_id;
						$is_sibling                 = true;
						break;
					}
				}
			} catch ( \Throwable $e ) {
				$is_sibling = false;
			}
		}

		wp_enqueue_script(
			'perflocale-block-translate-sidebar',
			PERFLOCALE_URL . 'assets/js/block-translate-sidebar.js',
			// wp-block-editor for the block-toolbar API surface that this
			// panel calls into via window.perflocaleBlockTranslate.
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-data', 'wp-block-editor' ],
			PERFLOCALE_VERSION,
			true
		);

		// Load the JS translations for this handle. Without it the wp.i18n.__()
		// strings in the script file stay untranslated no matter what a
		// translator supplies — WordPress only wires a script's JSON language
		// pack when the handle is registered here. No-ops until a language pack
		// exists, so it is safe on a fresh install.
		wp_set_script_translations( 'perflocale-block-translate-sidebar', 'perflocale' );

		wp_localize_script(
			'perflocale-block-translate-sidebar',
			'perflocaleBlockTranslateSidebar',
			[
				'languages'      => $languages,
				'postSourceLang' => $post_source_lang,
				'mtReady'        => $mt_ready,
				'mtProvider'     => $mt_provider,
				'mtSettingsUrl'  => admin_url( 'admin.php?page=perflocale-settings&tab=translation' ),
				'isSibling'      => $is_sibling,
				'sourceLang'     => $default_lang_slug,
				'sourcePostId'   => $source_post_id_for_sibling,
				'targetPostId'   => $current_post_id,
			]
		);
	}

	/**
	 * Whether at least one MT provider has its API key configured.
	 *
	 * Mirrors the same check Bootstrap.php runs to set the `mtReady` flag
	 * for block-toolbar.js, kept in sync so both sidebars agree on
	 * whether the MT actions should appear.
	 *
	 * @return bool
	 */
	/**
	 * Apply sprintf with a single %s placeholder, falling back to the
	 * original (English) format string if the translated version dropped or
	 * miscounted the placeholder. Avoids the PHP notice that vanilla
	 * `sprintf( __( ... ) )` emits on every editor load when a translator
	 * omits %s.
	 *
	 * @param string $format Translated format string.
	 * @param string $arg    Singular substitution value.
	 * @return string
	 */
	private function safe_sprintf_singular( string $format, string $arg ): string {
		// Count *literal* %s occurrences (not %d, %1$s, etc.). One is the
		// expected shape; anything else means the translation diverged
		// from the contract and we should bail out gracefully.
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

		// Tighter than `has_any_configured_provider()`: the *selected*
		// provider must be ready, not just any provider with a key. Keeps
		// the editor's Translate buttons aligned with what a click can
		// actually do at request time. See TranslationService docblock.
		return $service->is_active_provider_ready();
	}
}
