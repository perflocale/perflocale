<?php
/**
 * Strings REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Strings\StringScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for strings: list, scan, bulk machine translation.
 *
 * Deliberately no REST write route for one string: translations are written on the admin Strings page.
 */
final class StringsController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/strings',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_strings' ],
				'permission_callback' => [ $this, 'translate_permissions_check' ],
				// Sanitizers mirror get_strings(): REST sanitizes before it validates, so absint keeps non-numeric input
				// accepted as 0. No `page`: the handler reads offset.
				'args'                => [
					'per_page' => [
						'type'              => 'integer',
						'description'       => __( 'Rows per page (1–100, default 50).', 'perflocale' ),
						'sanitize_callback' => 'absint',
					],
					'offset'   => [
						'type'              => 'integer',
						'description'       => __( 'Row offset for pagination.', 'perflocale' ),
						'sanitize_callback' => 'absint',
					],
					'domain'   => [
						'type'              => 'string',
						'description'       => __( 'Filter by text domain.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/strings/scan',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'scan_strings' ],
				'permission_callback' => [ $this, 'admin_permissions_check' ],
				'args'                => [
					'domain' => [
						'type'              => 'string',
						'description'       => __( 'Restrict the scan to a text domain.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'target' => [
						'type'              => 'string',
						'description'       => __( 'Scan target (defaults to theme).', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/strings/machine-translate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'machine_translate_strings' ],
				'permission_callback' => [ $this, 'bulk_mt_permissions_check' ],
				// `overwrite` stays undeclared: only '1' or true mean overwrite, and REST boolean coercion would change that.
				// Array params stay undeclared too: type=array would reject a scalar the handler's (array) cast wraps.
				'args'                => [
					'mode'        => [
						'type'              => 'string',
						'description'       => __( 'Selection mode: ids, filter, or all.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
					'provider_id' => [
						'type'              => 'string',
						'description'       => __( 'Machine-translation provider id.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Dispatch a bulk string machine-translation job (mode ids, filter or all, plus target_lang_ids).
	 *
	 * The Dispatcher runs it inline or queues it by its threshold.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function machine_translate_strings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->get( 'settings' );

		if ( ! $settings->mt_enabled() ) {
			return new \WP_Error(
				'mt_disabled',
				__( 'Machine translation is disabled in Settings.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! $settings->mt_bulk_strings_enabled() ) {
			return new \WP_Error(
				'mt_bulk_disabled',
				__( 'Bulk MT translation for strings is disabled in Settings.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
		if ( ! in_array( $mode, [ 'ids', 'filter', 'all' ], true ) ) {
			return new \WP_Error(
				'invalid_mode',
				__( 'Selection mode must be one of: ids, filter, all.', 'perflocale' ),
				[ 'status' => 400 ]
			);
		}

		$target_lang_ids = (array) $request->get_param( 'target_lang_ids' );
		$target_lang_ids = array_values( array_filter( array_map( 'intval', $target_lang_ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( $target_lang_ids === [] ) {
			return new \WP_Error(
				'no_targets',
				__( 'At least one target language is required.', 'perflocale' ),
				[ 'status' => 400 ]
			);
		}

		$args = [
			'mode'            => $mode,
			'target_lang_ids' => $target_lang_ids,
			'provider_id'     => sanitize_key( (string) ( $request->get_param( 'provider_id' ) ?? '' ) ),
			'skip_existing'   => ! ( $request->get_param( 'overwrite' ) === '1' || $request->get_param( 'overwrite' ) === true ),
		];

		if ( $mode === 'ids' ) {
			$ids                = (array) $request->get_param( 'string_ids' );
			$args['string_ids'] = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			if ( $args['string_ids'] === [] ) {
				return new \WP_Error(
					'no_strings',
					__( 'At least one string_id is required for mode=ids.', 'perflocale' ),
					[ 'status' => 400 ]
				);
			}
		} elseif ( $mode === 'filter' ) {
			$filter        = (array) $request->get_param( 'filter' );
			$allowed_modes = [ 'contains', 'not_contains', 'exact', 'starts_with', 'not_starts_with', 'ends_with', 'not_ends_with' ];
			$search_mode   = sanitize_key( (string) ( $filter['search_mode'] ?? 'contains' ) );

			if ( ! in_array( $search_mode, $allowed_modes, true ) ) {
				$search_mode = 'contains';
			}

			$allowed_status = [ '', 'translated', 'untranslated', 'needs_update' ];
			$status         = sanitize_key( (string) ( $filter['status'] ?? '' ) );

			if ( ! in_array( $status, $allowed_status, true ) ) {
				$status = '';
			}

			$args['filter'] = [
				'domain'      => sanitize_text_field( (string) ( $filter['domain'] ?? '' ) ),
				'context'     => is_string( $filter['context'] ?? null ) ? $filter['context'] : '', // As stored: matched exactly, never output.
				'search'      => sanitize_text_field( (string) ( $filter['search'] ?? '' ) ),
				'search_mode' => $search_mode,
				'status'      => $status,
				'language_id' => absint( $filter['language_id'] ?? 0 ),
			];
		}

		$result = \PerfLocale\Background\Dispatcher::dispatch(
			new \PerfLocale\Background\Jobs\BulkStringTranslateJob(),
			$args
		);

		$response = rest_ensure_response( $result );

		// The HTTP status reflects a denied or failed dispatch, so the UI does not show it as an empty success.
		$mode = (string) $result['mode'];

		if ( 'denied' === $mode ) {
			$response->set_status( rest_authorization_required_code() );
		} elseif ( 'error' === $mode || ( 'sync' === $mode && isset( $result['error'] ) ) ) {
			$response->set_status( 500 );
		}

		return $response;
	}

	/**
	 * Get translatable strings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_strings( \WP_REST_Request $request ): \WP_REST_Response {
		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );
		$repo   = new StringRepository( $cache );

		$per_page = min( max( 1, absint( $request->get_param( 'per_page' ) ?? 50 ) ), 100 );

		$args = [
			'domain' => sanitize_text_field( $request->get_param( 'domain' ) ?? '' ),
			'limit'  => $per_page,
			'offset' => absint( $request->get_param( 'offset' ) ?? 0 ),
		];

		$strings = $repo->find_all( $args );
		$total   = $repo->count( [ 'domain' => $args['domain'] ] );

		return $this->paginated( $strings, $total, $per_page );
	}

	/**
	 * Trigger a string scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_strings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$plugin  = \PerfLocale\Plugin::get_instance();
		$cache   = $plugin->get( 'cache' );
		$scanner = new StringScanner( $cache );

		$domain = sanitize_text_field( $request->get_param( 'domain' ) ?? '' );

		// Fixed scan targets only: a user-supplied path is never accepted (path traversal).
		$target = sanitize_key( $request->get_param( 'target' ) ?? 'theme' );

		$path = match ( $target ) {
			'theme' => get_stylesheet_directory(),
			// WP_PLUGIN_DIR, not WP_CONTENT_DIR: it follows a relocated plugins directory.
			'plugins' => WP_PLUGIN_DIR,
			'parent' => get_template_directory(),
			default => get_stylesheet_directory(),
		};

		if ( ! is_dir( $path ) ) {
			return $this->error( 'invalid_target', __( 'Scan target directory not found.', 'perflocale' ) );
		}

		$result = $scanner->scan( $path, $domain );

		return $this->success( $result );
	}

	/**
	 * Bulk string MT needs `perflocale_manage_translations` as well as `perflocale_use_mt`: it can overwrite every string.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function bulk_mt_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return $this->mt_permissions_check( $request );
	}
}
