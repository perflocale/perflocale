<?php
/**
 * Bootstrap's admin, AJAX, REST and WP-CLI wiring.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service registration for admin and WP-CLI requests, the REST routes, the page-dropdown labels and the AJAX
 * column and term-language handlers of Bootstrap, loaded only on those requests.
 *
 * @internal
 */
final class BootstrapAdmin {

	/**
	 * Register admin-only services.
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	public static function register_admin_services( Plugin $plugin ): void {
		$plugin->register(
			'admin_controller',
			fn( Plugin $p ) => new Admin\AdminController( $p->get( 'settings' ) ),
			true
		);

		$plugin->register(
			'admin_assets',
			fn() => new Admin\Assets(),
			false
		);

		// Hook forwarders of admin_assets and term_meta_box; eager in this slot so their callbacks keep their order.
		$plugin->register(
			'admin_service_hooks',
			fn() => new Admin\AdminServiceHooks(),
			true
		);

		// Dashboard widget: built when the Dashboard sets up its widgets.
		$plugin->register(
			'dashboard_widget',
			fn( Plugin $p ) => new Admin\DashboardWidget( $p->get( 'settings' ) ),
			false
		);
		add_action( 'wp_dashboard_setup', [ self::class, 'dashboard_widget_setup' ] );

		// Quarantined add-on notice: eager so its admin_notices hooks register in time.
		$plugin->register(
			'quarantine_notice',
			fn() => new Admin\QuarantineNotice(),
			true
		);

		$plugin->register(
			'meta_box',
			fn( Plugin $p ) => new Admin\MetaBox(
				$p->get( 'router' ),
				$p->get( 'cache' ),
			),
			true
		);

		$plugin->register(
			'post_list_columns',
			fn( Plugin $p ) => new Admin\PostListColumns(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

		$plugin->register(
			'term_meta_box',
			fn( Plugin $p ) => new Admin\TermMetaBox(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			false
		);

		$plugin->register(
			'term_list_columns',
			fn( Plugin $p ) => new Admin\TermListColumns(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

		// Editor sidebar: built when a block editor enqueues its assets.
		$plugin->register(
			'editor_sidebar',
			fn() => new Admin\EditorSidebar(),
			false
		);
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_sidebar_assets' ] );
	}

	/**
	 * `wp_dashboard_setup`: the dashboard_widget service adds its widget.
	 *
	 * @return void
	 */
	public static function dashboard_widget_setup(): void {
		/** @var Admin\DashboardWidget $widget */
		$widget = Plugin::get_instance()->get( 'dashboard_widget' );
		$widget->maybe_add_widget();
	}

	/**
	 * `enqueue_block_editor_assets`: the editor_sidebar service enqueues its panel.
	 *
	 * @return void
	 */
	public static function editor_sidebar_assets(): void {
		/** @var Admin\EditorSidebar $sidebar */
		$sidebar = Plugin::get_instance()->get( 'editor_sidebar' );
		$sidebar->enqueue_sidebar();
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	public static function register_cli_services( Plugin $plugin ): void {
		$plugin->register(
			'cli',
			fn( Plugin $p ) => new Cli\PerfLocaleCommand( $p ),
			true
		);

		// Add-on lifecycle subcommands (wp perflocale addon ...), a separate service.
		$plugin->register(
			'cli_addon',
			fn() => new Cli\AddonCommand(),
			true
		);

		// Per-add-on settings subcommands (wp perflocale addon settings ...), their own namespace
		// so WP-CLI groups get/set/list under `settings`.
		$plugin->register(
			'cli_addon_settings',
			fn() => new Cli\AddonSettingsCommand(),
			true
		);
	}

	/**
	 * Register REST API routes on rest_api_init.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		// Prevent double registration if rest_api_init fires multiple times.
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;

		$controllers = [
			new Api\LanguagesController(),
			new Api\TranslationsController(),
			new Api\StringsController(),
			new Api\XliffController(),
			new Api\WebhookController(),
			new Api\JobsController(),
		];

		// MT routes register unconditionally: the handlers check mt_enabled() per request and
		// answer `mt_disabled`, not `rest_no_route`, to an editor that cached the route.
		$controllers[] = new Api\MachineTranslateController();
		$controllers[] = new Api\BlockTranslateController();

		// The public edge-config endpoint exists only when edge integration is enabled.
		if ( Plugin::get_instance()->get( 'settings' )->edge_integration_enabled() ) {
			$controllers[] = new Api\ConfigController();
		}

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}

		// The language of each navigation menu in the Site Editor's edit-context data.
		( new Api\NavigationLanguageField() )->register();
	}

	/**
	 * Add language labels ("[EN]") to wp_dropdown_pages options.
	 *
	 * @param string $output Dropdown HTML.
	 * @return string Modified HTML.
	 */
	public static function add_language_labels_to_dropdown( string $output ): string {
		if ( ! is_admin() || empty( $output ) ) {
			return $output;
		}

		// Per-request memo of page id to language code (the dropdown renders several times).
		// Keyed by blog id: a network-admin render may switch blogs between dropdowns.
		static $page_lang_map = [];

		$blog_id = get_current_blog_id();

		if ( ! isset( $page_lang_map[ $blog_id ] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			global $wpdb;

			$links_table  = Database\Schema::table( 'translation_links' );
			$groups_table = Database\Schema::table( 'translation_groups' );
			$langs_table  = Database\Schema::table( 'languages' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.object_id, UPPER(lang.slug) AS lang_code
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id AND g.type = 'post'
					INNER JOIN %i lang ON l.language_id = lang.id
					INNER JOIN %i p ON l.object_id = p.ID AND p.post_type = 'page'
					LIMIT 500",
					$links_table,
					$groups_table,
					$langs_table,
					$wpdb->posts
				)
			);

			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$blog_map = [];

			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					$blog_map[ (int) $row->object_id ] = $row->lang_code;
				}
			}

			$page_lang_map[ $blog_id ] = $blog_map;
		}

		$map = $page_lang_map[ $blog_id ];

		if ( $map === [] ) {
			return $output;
		}

		// Append language code to each <option> label.
		return preg_replace_callback(
			'/(<option[^>]*value=["\'](\d+)["\'][^>]*>)([^<]+)(<\/option>)/i',
			static function ( array $m ) use ( $map ): string {
				$page_id = (int) $m[2];

				if ( isset( $map[ $page_id ] ) ) {
					// Escape late: the slug goes straight into the dropdown's HTML.
					return $m[1] . $m[3] . ' [' . esc_html( $map[ $page_id ] ) . ']' . $m[4];
				}

				return $m[0];
			},
			$output
		) ?? $output;
	}

	/**
	 * AJAX term creation (add-tag): save the new term's language from the PerfLocale fields (admin_init).
	 *
	 * @return void
	 */
	public static function term_language_on_add_tag() : void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['perflocale_term_nonce'] ) || empty( $_POST['perflocale_term_language'] ) ) {
			return;
		}

		$plugin   = Plugin::get_instance();
		$settings = $plugin->get( 'settings' );

		foreach ( $settings->get_translatable_taxonomies() as $taxonomy ) {
			add_action(
				"created_{$taxonomy}",
				static function ( int $term_id ) use ( $plugin ): void {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
					if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_term_nonce'] ?? '' ), 'perflocale_term_lang' ) ) {
						return;
					}

					// The new term's own edit right (follows the taxonomy's caps).
					if ( ! current_user_can( 'edit_term', $term_id ) ) {
						return;
					}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
					$language = sanitize_key( $_POST['perflocale_term_language'] ?? '' );

					if ( $language !== '' ) {
						$manager = new Translation\TermTranslationManager( $plugin->get( 'cache' ) );

						if ( ! $manager->set_term_language( $term_id, $language ) ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point.
							error_log( sprintf( 'PerfLocale: language save failed for new term %d (language "%s").', $term_id, $language ) );
						}
					}
				}
			);
		}
	}

	/**
	 * AJAX: save the hidden language columns of the Strings screen.
	 *
	 * @return void
	 */
	public static function save_hidden_columns() : void {
		// Default $die=true: an invalid nonce ends the request before any input is read.
		check_ajax_referer( 'perflocale_hidden_columns', '_wpnonce' );

		// Per-user UI state: `read` is the minimum authenticated capability.
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		// Cap the payload so user meta cannot be flooded. sanitize_text_field() keeps the
		// JSON punctuation; per-element sanitize_key() runs after decode.
		$raw = isset( $_POST['hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['hidden'] ) ) : '[]';

		if ( strlen( $raw ) > 4096 ) {
			wp_send_json_error( 'Payload too large.' );
		}

		$hidden = json_decode( $raw, true );

		if ( ! is_array( $hidden ) ) {
			$hidden = [];
		}

		$hidden = array_map( 'sanitize_key', $hidden );

		update_user_meta( get_current_user_id(), 'perflocale_strings_hidden_langs', $hidden );

		wp_send_json_success();
	}

	/**
	 * AJAX: save an allow-listed screen's hidden language columns.
	 *
	 * @return void
	 */
	public static function save_hidden_columns_generic() : void {
		$meta_key_raw = isset( $_POST['meta_key'] ) ? sanitize_key( wp_unslash( $_POST['meta_key'] ) ) : '';

		$allowed = [
			'perflocale_translations_hidden_langs' => 'perflocale_translations_hidden_cols',
		];

		if ( ! isset( $allowed[ $meta_key_raw ] ) ) {
			wp_send_json_error( 'Invalid meta key.' );
		}

		// Default $die=true: an invalid nonce ends the request before any input is read.
		check_ajax_referer( $allowed[ $meta_key_raw ], '_wpnonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		$raw = isset( $_POST['hidden'] ) ? (string) wp_unslash( $_POST['hidden'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field applied below on the serialized-JSON string before decode.

		if ( strlen( $raw ) > 4096 ) {
			wp_send_json_error( 'Payload too large.' );
		}

		$hidden = json_decode( sanitize_text_field( $raw ), true );

		if ( ! is_array( $hidden ) ) {
			$hidden = [];
		}

		$hidden = array_map( 'sanitize_key', $hidden );

		update_user_meta( get_current_user_id(), $meta_key_raw, $hidden );

		wp_send_json_success();
	}
}
