<?php
/**
 * Admin service hook wiring.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hooks of Assets (`admin_assets`) and TermMetaBox (`term_meta_box`) as static forwarders, so each class loads only
 * when one of its hooks fires: no admin_enqueue_scripts on admin-post.php or a form POST, no term form elsewhere.
 *
 * @internal
 */
final class AdminServiceHooks {

	/**
	 * Same hooks, priorities and argument counts as Assets::register_hooks() and TermMetaBox::register_hooks().
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_init', [ self::class, 'register_taxonomy_hooks' ] );
	}

	/**
	 * As TermMetaBox::register_taxonomy_hooks(), with forwarders.
	 *
	 * @return void
	 */
	public static function register_taxonomy_hooks(): void {
		$taxonomies = Plugin::get_instance()->settings()->get_translatable_taxonomies();

		foreach ( $taxonomies as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", [ self::class, 'render_add_fields' ] );
			add_action( "{$taxonomy}_edit_form_fields", [ self::class, 'render_edit_fields' ], 10, 2 );
			add_action( "created_{$taxonomy}", [ self::class, 'save_term_language' ] );
			add_action( "edited_{$taxonomy}", [ self::class, 'save_term_language' ] );
		}
	}

	/**
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		self::assets()->enqueue( $hook );
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function render_add_fields( string $taxonomy ): void {
		self::term_meta_box()->render_add_fields( $taxonomy );
	}

	/**
	 * @param \WP_Term $term     Term being edited.
	 * @param string   $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function render_edit_fields( \WP_Term $term, string $taxonomy ): void {
		self::term_meta_box()->render_edit_fields( $term, $taxonomy );
	}

	/**
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function save_term_language( int $term_id ): void {
		self::term_meta_box()->save_term_language( $term_id );
	}

	/**
	 * The `admin_assets` service.
	 *
	 * @return Assets
	 */
	private static function assets(): Assets {
		/** @var Assets $assets */
		$assets = Plugin::get_instance()->get( 'admin_assets' );

		return $assets;
	}

	/**
	 * The `term_meta_box` service.
	 *
	 * @return TermMetaBox
	 */
	private static function term_meta_box(): TermMetaBox {
		/** @var TermMetaBox $box */
		$box = Plugin::get_instance()->get( 'term_meta_box' );

		return $box;
	}
}
