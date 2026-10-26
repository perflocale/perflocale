<?php
/**
 * Base REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for PerfLocale REST API controllers: namespace, permission checks, responses.
 */
abstract class RestController extends \WP_REST_Controller {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'perflocale/v1';

	/**
	 * Check if the current user can manage options (admin-level).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function admin_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check if the current user can translate content.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function translate_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_translate' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check if the current user can use machine translation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function mt_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_use_mt' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check if the current user can manage languages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function languages_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_manage_languages' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check if the current user can import/export data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function import_export_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_import_export' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Format a success response.
	 *
	 * @param mixed $data    Response data.
	 * @param int   $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	protected function success( mixed $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Format an error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return \WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Whether the user may read every post; primes 200 at a time, since each read_post check loads its post.
	 *
	 * @param int[] $post_ids Post ids; ids below 1 are skipped.
	 * @return bool
	 */
	protected function can_read_posts( array $post_ids ): bool {
		$ids = array_values( array_unique( array_filter( $post_ids, static fn( int $id ): bool => $id > 0 ) ) );

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );

			foreach ( $chunk as $pid ) {
				if ( ! current_user_can( 'read_post', $pid ) ) {
					return false;
				}
			}

			\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
		}

		return true;
	}

	/**
	 * Paginated response with core's `X-WP-Total` and `X-WP-TotalPages` headers.
	 *
	 * @param mixed $data     Response body.
	 * @param int   $total    Total number of rows matching the query.
	 * @param int   $per_page Rows per page (>= 1).
	 * @return \WP_REST_Response
	 */
	protected function paginated( mixed $data, int $total, int $per_page ): \WP_REST_Response {
		$per_page    = max( 1, $per_page );
		$total_pages = (int) ceil( $total / $per_page );

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}
}
