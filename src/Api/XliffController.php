<?php
/**
 * XLIFF import/export REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Xliff\XliffExporter;
use PerfLocale\Xliff\XliffImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for XLIFF import and export.
 *
 * POST /perflocale/v1/xliff/export - Export translations as XLIFF.
 * POST /perflocale/v1/xliff/import - Import translations from XLIFF.
 */
final class XliffController extends RestController {

	/**
	 * Posts loaded per statement by export_permissions_check().
	 *
	 * @var int
	 */
	private const PERMISSION_CHUNK = 100;

	/**
	 * Export requests whose post check passed: the user and post IDs it
	 * passed for, by request.
	 *
	 * @var \WeakMap<\WP_REST_Request, array{0: int, 1: string}>|null
	 */
	private static ?\WeakMap $export_allowed = null;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/xliff/export',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'export' ],
				'permission_callback' => [ $this, 'export_permissions_check' ],
				// Documentation + defense-in-depth. source_lang/target_lang
				// sanitize_callbacks mirror export()'s sanitize_key exactly;
				// post_ids stays a bare array (export() maps absint over it -
				// no scalar sanitizer applies). Not required: the permission
				// gate already rejects an empty/invalid post_ids set.
				'args'                => [
					// post_ids: documentation only, no strict type. export()
					// does array_map( 'absint', (array) $post_ids ), so a scalar
					// is accepted and wrapped - declaring type=array would newly
					// reject that currently-valid shape.
					'post_ids'    => [
						'description' => __( 'IDs of the source posts to export.', 'perflocale' ),
					],
					'source_lang' => [
						'type'              => 'string',
						'description'       => __( 'Source language slug (defaults to en).', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
					'target_lang' => [
						'type'              => 'string',
						'description'       => __( 'Target language slug.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/xliff/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import' ],
				'permission_callback' => [ $this, 'import_export_permissions_check' ],
				// `xliff` is deliberately schema-typed but NOT given a
				// sanitize_callback: import() feeds the raw XLIFF XML straight
				// to the DOM parser, so any REST-layer string sanitiser would
				// corrupt the document. Documentation only here.
				'args'                => [
					'xliff' => [
						'type'        => 'string',
						'description' => __( 'Raw XLIFF 2.0 document to import.', 'perflocale' ),
					],
				],
			]
		);
	}

	/**
	 * Per-post permission gate for `/xliff/export`. Runs the broad
	 * import/export cap first, then verifies the caller has `edit_post`
	 * on every post in the body's `post_ids[]` array. Hoists the
	 * in-handler loop at export() lines 62-66 to the routing layer.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function export_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->import_export_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$post_ids = array_map( 'absint', (array) $request->get_param( 'post_ids' ) );
		$checked  = [ get_current_user_id(), implode( ',', $post_ids ) ];

		// Core runs this check again after the handler, for the response's
		// Allow header (rest_send_allow_header()). The export released its
		// posts by then, so the same request, user and posts are answered
		// from the first check instead of loading every post again.
		self::$export_allowed ??= new \WeakMap();

		if ( ( self::$export_allowed[ $request ] ?? null ) === $checked ) {
			return true;
		}

		// Empty/missing post_ids is a missing-params 400 from the handler;
		// don't pre-empt it here. Only enforce edit_post on the IDs the
		// caller did supply.
		// current_user_can( 'edit_post' ) loads the post, so each chunk's
		// posts are loaded in one query before its checks. A refused post
		// ends the check before the next chunk is loaded.
		foreach ( array_chunk( $post_ids, self::PERMISSION_CHUNK ) as $chunk ) {
			$ids = array_values( array_filter( $chunk ) );

			if ( $ids !== [] && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, false, false );
			}

			foreach ( $chunk as $pid ) {
				if ( $pid > 0 && ! current_user_can( 'edit_post', $pid ) ) {
					return new \WP_Error(
						'rest_forbidden',
						__( 'You do not have permission to export one or more of the specified posts.', 'perflocale' ),
						[ 'status' => 403 ]
					);
				}
			}
		}

		self::$export_allowed[ $request ] = $checked;

		return true;
	}

	/**
	 * Export translations as XLIFF.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_ids    = array_map( 'absint', (array) $request->get_param( 'post_ids' ) );
		$source_lang = sanitize_key( $request->get_param( 'source_lang' ) ?? 'en' );
		$target_lang = sanitize_key( $request->get_param( 'target_lang' ) ?? '' );

		if ( empty( $post_ids ) || $target_lang === '' ) {
			return $this->error( 'invalid_params', __( 'post_ids and target_lang are required.', 'perflocale' ) );
		}

		// Verify the user can edit each post before exporting its content.
		foreach ( $post_ids as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				return $this->error( 'rest_forbidden', __( 'You do not have permission to export one or more of the specified posts.', 'perflocale' ), 403 );
			}
		}

		$exporter = new XliffExporter();

		try {
			$xliff = $exporter->export( $post_ids, $source_lang, $target_lang );
		} catch ( \Throwable $e ) {
			// The export builder needs ext-xmlwriter, which a stripped PHP
			// build can lack. Nothing above this frame catches, so an
			// uncaught Error here would end the request as a fatal instead
			// of a REST envelope. 500 is right: the request was valid and
			// the server is the part that is missing something.
			return $this->error( 'export_failed', $e->getMessage(), 500 );
		}

		return $this->success(
			[
				'xliff'    => $xliff,
				'filename' => sprintf( 'perflocale_%s_%s_%s.xliff', $source_lang, $target_lang, gmdate( 'Ymd' ) ),
			]
		);
	}

	/**
	 * Import translations from XLIFF.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$xliff_content = $request->get_param( 'xliff' );

		if ( empty( $xliff_content ) ) {
			return $this->error( 'missing_xliff', __( 'XLIFF content is required.', 'perflocale' ) );
		}

		$importer = new XliffImporter();

		try {
			$result = $importer->import( $xliff_content );

			return $this->success( $result );
		} catch ( \PerfLocale\Xliff\XliffFormatException $e ) {
			// The client sent something that can never parse - empty body,
			// broken XML, declared entities, an unknown trgLang. That is a
			// 400: retrying the identical request cannot succeed, and a 500
			// tells an integrator (and every uptime monitor) the opposite.
			return $this->error( 'invalid_xliff', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'import_failed', $e->getMessage(), 500 );
		}
	}
}
