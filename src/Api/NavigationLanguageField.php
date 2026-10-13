<?php
/**
 * Language of a navigation menu in the Site Editor's REST data.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds `perflocale_language` to wp_navigation REST responses in the edit
 * context.
 *
 * The Site Editor's Navigation list reads its menus from
 * GET /wp/v2/navigation in the edit context, and a translation created from a
 * menu keeps that menu's name. The field gives each menu's language slug (null
 * for a menu with no language, or while menus are not translatable), so the
 * list can show the language next to the name (the second part of
 * assets/js/site-editor-sidebar.js). The view and embed contexts do not carry
 * it, and the title is not changed anywhere: the name the list, the
 * Rename field and the site's navigation use stays the stored one.
 *
 * The field is read-only. Readers of the edit context already hold the
 * menu's edit rights, which core checks before the field is prepared. A list's
 * languages are read from the link cache that UrlConverter::preload_object_languages()
 * fills for the list query's posts in one batch.
 */
final class NavigationLanguageField {

	/**
	 * Field name in the REST response.
	 */
	public const FIELD = 'perflocale_language';

	/**
	 * Register the field.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_field(
			'wp_navigation',
			self::FIELD,
			[
				'get_callback' => [ $this, 'get_language' ],
				'schema'       => [
					'description' => __( 'Language slug of the navigation menu, or null when it has none.', 'perflocale' ),
					'type'        => [ 'string', 'null' ],
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * The menu's language slug.
	 *
	 * @param array<string, mixed> $prepared Prepared response data.
	 * @return string|null
	 */
	public function get_language( array $prepared ): ?string {
		$post_id = isset( $prepared['id'] ) && is_numeric( $prepared['id'] ) ? (int) $prepared['id'] : 0;
		$plugin  = Plugin::get_instance();

		if ( $post_id <= 0 || ! $plugin->has( 'group_repo' ) || ! $this->menus_translatable() ) {
			return null;
		}

		$groups = $plugin->get( 'group_repo' );

		if ( ! $groups instanceof TranslationGroupRepository ) {
			return null;
		}

		foreach ( $groups->get_translations( $post_id, ObjectType::Post ) as $link ) {
			$row = is_object( $link ) ? get_object_vars( $link ) : [];
			$id  = $row['object_id'] ?? 0;

			if ( is_numeric( $id ) && (int) $id === $post_id && isset( $row['language_slug'] ) && is_string( $row['language_slug'] ) && '' !== $row['language_slug'] ) {
				return $row['language_slug'];
			}
		}

		return null;
	}

	/**
	 * Whether navigation menus are translatable on this site.
	 *
	 * @return bool
	 */
	private function menus_translatable(): bool {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return false;
		}

		$settings = $plugin->get( 'settings' );

		return $settings instanceof Settings && in_array( 'wp_navigation', $settings->get_translatable_post_types(), true );
	}
}
