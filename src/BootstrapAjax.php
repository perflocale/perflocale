<?php
/**
 * Bootstrap's admin-ajax wiring.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handlers admin-ajax needs while AdminController and TermMetaBox do not load; the list-column services boot only
 * for the core actions that save or render a list row.
 *
 * @internal
 */
final class BootstrapAjax {

	/**
	 * Core actions that save or render a post list row (`fetch-list` is the generic list-table refill).
	 */
	private const POST_ROW_ACTIONS = [ 'inline-save', 'fetch-list' ];

	/**
	 * Core actions that save or render a term list row.
	 */
	private const TERM_ROW_ACTIONS = [ 'inline-save-tax', 'add-tag', 'fetch-list' ];

	/**
	 * The request's AJAX action, sanitised; '' when none.
	 *
	 * @return string
	 */
	private static function action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; each handler checks its nonce.
		return isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	}

	/**
	 * Whether this AJAX request may run a job: Action Scheduler's async runner (an anonymous POST that runs the queue)
	 * or a PerfLocale action. Another plugin's action, heartbeat among them, binds no job worker.
	 *
	 * @return bool
	 */
	public static function runs_jobs(): bool {
		$action = self::action();

		return 'as_async_request_queue_runner' === $action || str_starts_with( $action, 'perflocale_' );
	}

	/**
	 * Register the AJAX services and handlers for this request's action.
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {
		$action = self::action();

		// Both ids resolve on every AJAX request (an extension may get() them); each boots, wiring its Quick Edit
		// save and column hooks, only for its row actions.
		$plugin->register(
			'post_list_columns',
			fn( Plugin $p ) => new Admin\PostListColumns(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			in_array( $action, self::POST_ROW_ACTIONS, true )
		);

		$plugin->register(
			'term_list_columns',
			fn( Plugin $p ) => new Admin\TermListColumns(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			in_array( $action, self::TERM_ROW_ACTIONS, true )
		);

		if ( 'add-tag' === $action ) {
			// Term language save for AJAX term creation (add-tag); TermMetaBox does not load here.
			add_action(
				'admin_init',
				[ BootstrapAdmin::class, 'term_language_on_add_tag' ]
			);
		}

		// The import form's chunked upload (Settings > Export & Import). The class loads only
		// when the action runs.
		add_action( 'wp_ajax_perflocale_import_upload', [ Admin\ImportUpload::class, 'handle_ajax' ] );

		add_action(
			'wp_ajax_perflocale_save_hidden_columns',
			[ BootstrapAdmin::class, 'save_hidden_columns' ]
		);

		// Generic hidden-columns saver; `meta_key` is allow-listed so no arbitrary user meta
		// key can be written.
		add_action(
			'wp_ajax_perflocale_save_hidden_columns_generic',
			[ BootstrapAdmin::class, 'save_hidden_columns_generic' ]
		);

		// Exchange rate sync AJAX: registered here so it works even when the WooCommerce add-on
		// does not boot; the handler checks WooCommerce itself.
		add_action(
			'wp_ajax_perflocale_sync_exchange_rates',
			static function (): void {
				$p        = Plugin::get_instance();
				$settings = $p->get( 'settings' );
				$sync     = new WooCommerce\ExchangeRateSync( $settings );
				$sync->ajax_sync();
			}
		);

		// URL bases compile triggers where they are in use (LifecycleHooks::in_use(), inline so no class loads), not the heartbeat.
		$all = wp_load_alloptions();

		if ( 'heartbeat' !== $action && ( isset( $all['perflocale_url_bases_map'] ) || isset( $all['perflocale_url_bases_dirty'] ) ) ) {
			add_action( 'admin_init', [ self::class, 'url_bases' ], 0 );
		} elseif ( 'heartbeat' !== $action ) {
			// A Shop page set later may start them (LifecycleHooks::on_shop_option(), loaded then).
			add_action( 'add_option_woocommerce_shop_page_id', [ Router\UrlBases\LifecycleHooks::class, 'on_shop_option' ] );
			add_action( 'update_option_woocommerce_shop_page_id', [ Router\UrlBases\LifecycleHooks::class, 'on_shop_option' ] );
		}

		// Bulk tools on Settings > Translation; BulkTools loads only when one runs.
		add_action( 'wp_ajax_perflocale_create_wc_pages', [ Admin\BulkTools::class, 'ajax_create_wc_page_translations' ] );
		add_action( 'wp_ajax_perflocale_create_taxonomy_translations', [ Admin\BulkTools::class, 'ajax_create_taxonomy_translations' ] );
		add_action( 'wp_ajax_perflocale_assign_post_languages', [ Admin\BulkTools::class, 'ajax_assign_post_languages' ] );
		add_action( 'wp_ajax_perflocale_generate_missing_translations', [ Admin\BulkTools::class, 'ajax_generate_missing_translations' ] );
	}

	/**
	 * `admin_init` in AJAX: register the URL bases compile triggers for an editor or administrator (rule 13: never for a
	 * customer's or subscriber's call).
	 *
	 * @return void
	 */
	public static function url_bases(): void {
		if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ) {
			Router\UrlBases\LifecycleHooks::register();
		}
	}
}
