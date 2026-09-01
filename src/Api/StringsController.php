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
 * REST API endpoints for string translation management.
 *
 * GET /perflocale/v1/strings - List strings.
 * POST /perflocale/v1/strings/scan - Trigger string scan.
 * POST /perflocale/v1/strings/machine-translate - Queue bulk MT for strings.
 *
 * String translations are WRITTEN via the admin Strings page (AdminController)
 * — there is deliberately no REST write route for individual strings.
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
				// sanitize_callbacks mirror get_strings() exactly (absint /
				// sanitize_text_field). Because REST sanitises BEFORE it
				// type-validates, a non-numeric per_page/offset is coerced to 0
				// by absint and then passes the integer check — identical to the
				// handler's own absint() coercion, so no previously-accepted
				// request is rejected. `page` is intentionally absent: the
				// handler reads offset, not page.
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
				'permission_callback' => [ $this, 'mt_permissions_check' ],
				// Only the unambiguous scalar params are declared. `overwrite` is
				// deliberately left undeclared: the handler treats ONLY '1'
				// (string) or true (bool) as "overwrite", so a REST
				// boolean-coercion would change how 'true'/'0'/1 are read. The
				// array params (target_lang_ids, string_ids) and the nested
				// `filter` object are also left to the handler's own (array)
				// cast — declaring type=array would newly reject a scalar the
				// (array) cast currently wraps.
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
	 * Dispatch a bulk MT-translate-strings job.
	 *
	 * Accepts one of three selection modes:
	 *
	 *   - `mode=ids`    + `string_ids[]=N`             — selected rows
	 *   - `mode=filter` + `filter={domain,context,search}` — current filter set
	 *   - `mode=all`                                    — entire strings table
	 *
	 * Plus the universal `target_lang_ids[]=N` list. Routes through the
	 * Dispatcher which decides sync vs async based on the bulk_string_translate
	 * threshold (default 50 source × target pairs).
	 *
	 * Returns `mode=async` + `job_id` for queued runs, or `mode=sync` +
	 * `result` for inline runs.
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
				'context'     => sanitize_text_field( (string) ( $filter['context'] ?? '' ) ),
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

		// Reflect the dispatch outcome in the HTTP status. Without this a denied
		// (cap) or errored dispatch returns 200 and the UI reports the failure
		// as a successful zero-work run. The body still carries mode + error.
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

		// Whitelist allowed scan targets - prevents path traversal attacks.
		// User-supplied paths are NEVER accepted.
		$target = sanitize_key( $request->get_param( 'target' ) ?? 'theme' );

		$path = match ( $target ) {
			'theme' => get_stylesheet_directory(),
			// WP_PLUGIN_DIR honours installs that relocate the plugins
			// directory via the WP_PLUGIN_DIR constant — WP_CONTENT_DIR
			// would silently miss those.
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
}
