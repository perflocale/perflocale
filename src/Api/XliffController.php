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
 * REST routes for XLIFF export and import.
 */
final class XliffController extends RestController {

	/**
	 * Posts loaded per statement by export_permissions_check().
	 *
	 * @var int
	 */
	private const PERMISSION_CHUNK = 100;

	/**
	 * Most distinct posts one export reads.
	 *
	 * @var int
	 */
	private const MAX_EXPORT_POSTS = 50000;

	/**
	 * Export requests whose post check passed, with the user and post IDs it passed for.
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
				// Sanitizers mirror export(); nothing is required here because the handler answers missing params.
				'args'                => [
					// No type: export() wraps a scalar post_ids, which type=array would reject.
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
				// No sanitize_callback on `xliff`: a string sanitizer would corrupt the raw XML the parser reads.
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
	 * Permission gate for `/xliff/export`: the import/export cap, then `edit_post` on every requested post.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function export_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->import_export_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$post_ids = self::requested_post_ids( $request );
		$too_many = self::too_many_posts( $post_ids );

		if ( null !== $too_many ) {
			return $too_many;
		}

		$checked = [ get_current_user_id(), implode( ',', $post_ids ) ];

		// Core runs this check again for the Allow header after the handler released the posts: answer from the first run.
		self::$export_allowed ??= new \WeakMap();

		if ( ( self::$export_allowed[ $request ] ?? null ) === $checked ) {
			return true;
		}

		// Empty post_ids is left to the handler's 400. Each chunk is primed in one query, as edit_post loads the post.
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
		$post_ids    = self::requested_post_ids( $request );
		$source_lang = sanitize_key( $request->get_param( 'source_lang' ) ?? 'en' );
		$target_lang = sanitize_key( $request->get_param( 'target_lang' ) ?? '' );

		if ( empty( $post_ids ) || $target_lang === '' ) {
			return $this->error( 'invalid_params', __( 'post_ids and target_lang are required.', 'perflocale' ) );
		}

		$too_many = self::too_many_posts( $post_ids );

		if ( null !== $too_many ) {
			return $too_many;
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
			// A PHP build without ext-xmlwriter throws here; nothing above catches, so answer 500 instead of a fatal.
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
	 * The requested post ids, each once, in first-seen order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int, int>
	 */
	private static function requested_post_ids( \WP_REST_Request $request ): array {
		return array_values( array_unique( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) ) );
	}

	/**
	 * The refusal for an export over MAX_EXPORT_POSTS distinct posts, else null.
	 *
	 * @param array<int, int> $post_ids Distinct post ids.
	 * @return \WP_Error|null
	 */
	private static function too_many_posts( array $post_ids ): ?\WP_Error {
		if ( count( $post_ids ) <= self::MAX_EXPORT_POSTS ) {
			return null;
		}

		return new \WP_Error(
			'xliff_too_many_posts',
			sprintf(
				/* translators: 1: number of posts requested, 2: most posts per export */
				__( 'XLIFF export refused: %1$s posts requested, at most %2$s per file. Export them in several requests.', 'perflocale' ),
				number_format_i18n( count( $post_ids ) ),
				number_format_i18n( self::MAX_EXPORT_POSTS )
			),
			[ 'status' => 400 ]
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
			$result = $importer->import( $xliff_content, XliffImporter::WEB_MAX_UNITS );

			return $this->success( $result );
		} catch ( \PerfLocale\Xliff\XliffFormatException $e ) {
			// A document that can never parse is the client's error (400, or 413 over the size or unit limit), not a 500.
			return $this->error( 'invalid_xliff', $e->getMessage(), 413 === $e->getCode() ? 413 : 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'import_failed', $e->getMessage(), 500 );
		}
	}
}
