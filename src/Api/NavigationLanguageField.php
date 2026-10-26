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
 * Adds the read-only `perflocale_language` slug to wp_navigation REST responses (edit context only).
 *
 * The Site Editor list shows it next to the name (site-editor-sidebar.js); the title is never changed. Core checks
 * edit rights before the field is prepared.
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
