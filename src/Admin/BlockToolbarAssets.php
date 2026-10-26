<?php
/**
 * Block editor: the block-toolbar "Translate" script and its data.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Helper;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the block-toolbar extension on `enqueue_block_editor_assets`; loaded only when the block editor opens.
 */
final class BlockToolbarAssets {

	/**
	 * Enqueue the toolbar script, style and data for a translator editing a translatable post type.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! current_user_can( 'perflocale_translate' ) ) {
			return;
		}

		// Skip the Site, Widgets and Pattern editors and untranslatable types, as EditorSidebar does.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base ) {
			return;
		}

		$plugin       = Plugin::get_instance();
		$translatable = $plugin->has( 'settings' )
		? (array) $plugin->get( 'settings' )->get_translatable_post_types()
		: [];

		if ( ! in_array( $screen->post_type, $translatable, true ) ) {
			return;
		}

		/**
		 * Filter whether the PerfLocale block-toolbar extension loads.
		 *
		 * @hook perflocale/block_toolbar/enabled
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'perflocale/block_toolbar/enabled', true ) ) {
			return;
		}

		wp_enqueue_script(
			'perflocale-block-toolbar',
			PERFLOCALE_URL . 'assets/js/block-toolbar.js',
			[ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-api-fetch' ],
			PERFLOCALE_VERSION,
			true
		);

		// Without this the script's wp.i18n strings never load their JSON language pack.
		wp_set_script_translations( 'perflocale-block-toolbar', 'perflocale' );

		wp_enqueue_style(
			'perflocale-block-toolbar-editor',
			PERFLOCALE_URL . 'assets/css/block-toolbar-editor.css',
			[],
			PERFLOCALE_VERSION
		);

		// One LanguageRepository serves the language list and the sibling check below.
		$has_cache = $plugin->has( 'cache' );
		$cache_svc = $has_cache ? $plugin->get( 'cache' ) : null;
		$lang_repo = ( $cache_svc !== null ) ? new LanguageRepository( $cache_svc ) : null;

		$languages = [];
		$current   = '';

		if ( $lang_repo !== null ) {
			$languages = array_map(
				static fn( $lang ): array => [
					'slug'   => (string) $lang->slug,
					// Display only: `slug` stays lowercase because REST keys, URLs and the router compare it.
					'bcp47'  => Helper::format_locale_as_bcp47( (string) $lang->slug ),
					'locale'     => (string) $lang->locale,
					'name'       => (string) ( $lang->name ?? $lang->native_name ?? $lang->slug ),
					// The Language object shape the JavaScript hooks pass.
					'nativeName' => is_string( $lang->native_name ?? null ) && '' !== $lang->native_name ? $lang->native_name : ( is_string( $lang->name ?? null ) ? $lang->name : '' ),
					'isDefault'  => ! empty( $lang->is_default ),
				],
				$lang_repo->get_active()
			);
		}

		if ( $plugin->has( 'router' ) ) {
			$cur = $plugin->get( 'router' )->get_current_language();
			if ( is_object( $cur ) ) {
				$current = (string) ( $cur->slug ?? '' );
			}
		}

		// Block MT translates from the edited post's language, not the admin router language.
		$post_source_lang = '';
		$edited_post      = get_post();

		if ( $edited_post instanceof \WP_Post && $cache_svc !== null ) {
			$manager   = new PostTranslationManager(
				$cache_svc,
				$plugin->get( 'settings' )
			);
			$post_lang = $manager->detect_post_language( (int) $edited_post->ID );

			if ( $post_lang && isset( $post_lang->slug ) ) {
				$post_source_lang = (string) $post_lang->slug;
			}
		}

		// Ready only when the selected provider has its key: a key for an unselected one would fail at request time.
		$mt_ready   = false;
		$mt_service = null;

		if ( $plugin->get( 'settings' )->mt_enabled() && $cache_svc !== null ) {
			$mt_service = new TranslationService(
				$plugin->get( 'settings' ),
				$cache_svc
			);
			$mt_ready   = $mt_service->is_active_provider_ready();
		}

		// The setup link opens the MT subtab, or Settings > Connectors for an AI Client site without a provider.
		$mt_settings_url = admin_url( 'admin.php?page=perflocale-settings&tab=addons&subtab=machine-translation' );

		if ( $mt_service !== null && ! $mt_ready ) {
			$mt_settings_url = $mt_service->setup_url( $mt_settings_url );
		}

		// A translation with a default-language source gets "Fill in from source" instead of "Translate to".
		// Resolved here so the script needs no REST call for it.
		$default_language           = $lang_repo !== null ? $lang_repo->get_default() : null;
		$source_post_id_for_sibling = 0;
		$is_sibling                 = false;

		$default_lang_slug = ( $default_language && ! empty( $default_language->slug ) )
		? (string) $default_language->slug
		: '';

		if (
		$default_lang_slug !== ''
		&& $post_source_lang !== ''
		&& $post_source_lang !== $default_lang_slug
		&& $edited_post instanceof \WP_Post
		&& $cache_svc !== null
		) {
			try {
				$group_repo = new TranslationGroupRepository( $cache_svc );
				// A source the user may not view stays 0, so the editor never learns its id.
				$source_post_id_for_sibling = EditorSidebar::viewable_source_post_id(
					(array) $group_repo->get_translations( (int) $edited_post->ID, ObjectType::Post ),
					(int) $default_language->id
				);
				$is_sibling                 = $source_post_id_for_sibling > 0;
			} catch ( \Throwable $e ) {
				// A hint only: never fail the editor load over it.
				$is_sibling = false;
			}
		}

		wp_localize_script(
			'perflocale-block-toolbar',
			'perflocaleBlockToolbar',
			[
				'languages'       => $languages,
				'currentLang'     => $current,
				'postSourceLang'  => $post_source_lang,
				'mtReady'         => $mt_ready,
				'mtSettingsUrl'   => $mt_settings_url,
				// Provider id, '' when MT is not ready so the toolbar shows a generic label.
				'mtProvider'      => $mt_ready ? (string) $plugin->get( 'settings' )->get_mt_provider() : '',
				// Sibling-detection payload.
				'isSibling'       => $is_sibling,
				'sourceLang'      => $default_lang_slug,
				'sourcePostId'    => $source_post_id_for_sibling,
				// The JavaScript hooks pass sourcePost only when this is true.
				'sourcePostViewable' => $source_post_id_for_sibling > 0,
				'targetPostId'    => ( $edited_post instanceof \WP_Post ) ? (int) $edited_post->ID : 0,
				'postType'        => ( $edited_post instanceof \WP_Post ) ? (string) $edited_post->post_type : '',
				// SCRIPT_DEBUG reports a failing JavaScript hook callback once per hook in the console.
				'debug'           => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				'supportedBlocks' => [
					'core/paragraph',
					'core/heading',
					'core/list-item',
					'core/quote',
					'core/pullquote',
					'core/button',
					'core/preformatted',
					'core/verse',
					'core/code',
					// Only the `summary` attribute: the body is inner blocks with their own types.
					'core/details',
				],
				'i18n'            => [
					'translating'  => __( 'Translating…', 'perflocale' ),
					/* translators: %s: target language name */
					'translatedTo' => __( 'Translated to %s', 'perflocale' ),
					'wrapTitle'    => __( 'Show only for certain languages', 'perflocale' ),
					'setUpMt'      => __( 'Set up machine translation…', 'perflocale' ),
				],
			]
		);
	}
}
