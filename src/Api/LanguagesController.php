<?php
/**
 * Languages REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for language management.
 *
 * GET /perflocale/v1/languages - List all languages.
 * GET /perflocale/v1/languages/{slug} - Get a single language.
 * POST /perflocale/v1/languages - Create a language.
 * PUT /perflocale/v1/languages/{slug} - Update a language.
 * DELETE /perflocale/v1/languages/{slug} - Delete a language.
 */
final class LanguagesController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'languages';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'public_read_permission_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'languages_permissions_check' ],
					'args'                => [
						'slug'           => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static function ( $value ): bool {
								return is_string( $value ) && preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/', (string) $value ) === 1;
							},
						],
						'locale'         => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ): bool {
								return is_string( $value ) && preg_match( '/^[a-z]{2,3}(_[A-Z]{2,3})?$/', (string) $value ) === 1;
							},
						],
						'name'           => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ): bool {
								return is_string( $value ) && strlen( trim( $value ) ) > 0;
							},
						],
						'native_name'    => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
						'flag'           => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
						'is_default'     => [
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						],
						'is_active'      => [
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						],
						'text_direction' => [
							'required' => false,
							'type'     => 'string',
							'enum'     => [ 'ltr', 'rtl' ],
							'default'  => 'ltr',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[a-z]{2,3}(?:-[a-z]{2,3})?)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'public_read_permission_check' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'languages_permissions_check' ],
					// Documentation only. update_item() passes these fields to
					// the repository which applies its own sanitisation on
					// update (the create path sanitises up-front; update relies
					// on the model + the manage-languages capability gate). We
					// therefore add NO sanitize_callback and no strict type on
					// the numeric fields — that preserves the exact
					// currently-accepted input shape.
					'args'                => [
						'name'           => [
							'type'        => 'string',
							'description' => __( 'Display name.', 'perflocale' ),
						],
						'native_name'    => [
							'type'        => 'string',
							'description' => __( 'Native display name.', 'perflocale' ),
						],
						'flag'           => [
							'type'        => 'string',
							'description' => __( 'Flag code.', 'perflocale' ),
						],
						'is_active'      => [
							'description' => __( 'Whether the language is active.', 'perflocale' ),
						],
						'sort_order'     => [
							'description' => __( 'Display sort order.', 'perflocale' ),
						],
						'text_direction' => [
							'type'        => 'string',
							'description' => __( 'Text direction (ltr or rtl).', 'perflocale' ),
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'languages_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder_items' ],
				'permission_callback' => [ $this, 'languages_permissions_check' ],
				'args'                => [
					'order'  => [
						'required'          => true,
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'validate_callback' => static function ( $value ): bool {
							if ( ! is_array( $value ) || empty( $value ) ) {
								return false;
							}
							foreach ( $value as $id ) {
								if ( ! is_numeric( $id ) || (int) $id < 1 ) {
									return false;
								}
							}
							return true;
						},
					],
					'offset' => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
						'minimum'  => 0,
					],
				],
			]
		);
	}

	/**
	 * Permission check for the public read endpoints.
	 *
	 * The language list and single-language lookup are public by default because
	 * every field they expose (slug, locale, name, native name, flag, RTL flag,
	 * default flag) is already rendered to anonymous visitors via the language
	 * switcher, hreflang tags, and URL structure. Site owners who want to gate
	 * anonymous access can hook the `perflocale/api/languages_public` filter and
	 * return false - requests will then require the `read` capability (any
	 * authenticated user).
	 *
	 * @hook perflocale/api/languages_public
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function public_read_permission_check( \WP_REST_Request $request ): bool|\WP_Error {
		/**
		 * Whether `GET /perflocale/v1/languages` and `GET /perflocale/v1/languages/{slug}`
		 * may be read by anonymous visitors.
		 *
		 * Returning false requires the `read` capability instead.
		 *
		 * @param bool $public Default true.
		 * @param \WP_REST_Request $request Current request.
		 */
		$public = (bool) apply_filters( 'perflocale/api/languages_public', true, $request );

		if ( $public ) {
			return true;
		}

		if ( ! current_user_can( 'read' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to access this endpoint.', 'perflocale' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Get all languages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		$repo      = $this->get_repo();
		$languages = $repo->get_active();

		return $this->success( $languages );
	}

	/**
	 * Get a single language by slug.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ): \WP_REST_Response|\WP_Error {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$repo     = $this->get_repo();
		$language = $repo->find_by_slug( $slug );

		if ( ! $language ) {
			return $this->error( 'not_found', __( 'Language not found.', 'perflocale' ), 404 );
		}

		return $this->success( $language );
	}

	/**
	 * Create a new language.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ): \WP_REST_Response|\WP_Error {
		$repo = $this->get_repo();

		$locale = sanitize_text_field( $request->get_param( 'locale' ) ?? '' );

		$wants_default = Helper::to_bool( $request->get_param( 'is_default' ) );
		$is_active     = absint( $request->get_param( 'is_active' ) ?? 1 );

		// Refuse the impossible combination BEFORE the insert, not after.
		// set_default() rejects an inactive target (get_default() reads the
		// active-only bootstrap, so an inactive default resolves to NULL), and
		// the create used to swallow that refusal: 201 Created with
		// is_default=0 and no hint that half the request was dropped. Failing
		// up front also means no half-applied write to unpick.
		if ( $wants_default && $is_active === 0 ) {
			return $this->error(
				'invalid_default_inactive',
				__( 'is_default requires is_active — an inactive language cannot be the default.', 'perflocale' ),
				400
			);
		}

		$slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );

		// Pre-flight BOTH unique keys before touching the table. The languages
		// table has two: slug and locale. Without this, a duplicate rode
		// straight into the INSERT, which failed on the UNIQUE key, printed a
		// raw wpdb error under WP_DEBUG, and came back as a generic 500 —
		// indistinguishable from a real outage. The admin form and the CLI
		// both pre-flight the same two keys; this brings REST into line, with
		// 409 Conflict so a client can tell "already exists" from "broken".
		if ( $repo->find_by_slug( $slug ) ) {
			return $this->error(
				'slug_exists',
				sprintf(
					/* translators: %s: slug that already exists */
					__( 'A language with the slug %s already exists. Each language must have a unique URL identifier.', 'perflocale' ),
					$slug
				),
				409
			);
		}

		if ( $repo->find_by_locale( $locale ) ) {
			return $this->error(
				'locale_exists',
				sprintf(
					/* translators: %s: locale that already exists */
					__( 'A language with the locale %s already exists. Each language must have a unique WordPress locale.', 'perflocale' ),
					$locale
				),
				409
			);
		}

		$id = $repo->insert(
			[
				'slug'           => $slug,
				'locale'         => $locale,
				'name'           => sanitize_text_field( $request->get_param( 'name' ) ?? '' ),
				'native_name'    => sanitize_text_field( $request->get_param( 'native_name' ) ?? '' ),
				// NOT sanitize_key(): the arg already declares
				// sanitize_text_field, and the repository sanitises again on
				// write. A second pass through sanitize_key() destroyed emoji
				// flags outright, so this route stored '' where the admin screen
				// and the update route both keep the flag. Helper's
				// region_code_from_flag_emoji() exists precisely because a flag
				// may be stored as an emoji.
				'flag'           => (string) ( $request->get_param( 'flag' ) ?? '' ),
				'is_active'      => $is_active,
				// Explicit ltr/rtl wins; otherwise derive a sensible default from
				// the locale so RTL languages aren't silently created left-to-right.
				'text_direction' => in_array( $request->get_param( 'text_direction' ), [ 'ltr', 'rtl' ], true )
					? $request->get_param( 'text_direction' )
					: \PerfLocale\Helper::default_text_direction( $locale ),
			]
		);

		if ( $id === false ) {
			return $this->error( 'create_failed', __( 'Failed to create language.', 'perflocale' ), 500 );
		}

		// The insert above cannot set the default flag — set_default() promotes
		// this language and demotes the prior default in a single transaction.
		// Honor an explicit is_default=true so the REST path can set the default
		// language like the CLI does; previously it was silently dropped.
		//
		// The guard above rules out the one predictable refusal, so a false
		// here means the promotion failed on its own (the row vanished under a
		// concurrent delete, or a DB error rolled the transaction back). Say so
		// rather than returning 201 over a half-applied request: the language
		// exists, the default did not move, and the caller has to know which.
		if ( $wants_default && ! $repo->set_default( (int) $id ) ) {
			return $this->error(
				'default_not_set',
				__( 'The language was created but could not be made the default. Set it as default in a separate request.', 'perflocale' ),
				500
			);
		}

		$language = $repo->find( $id );

		return $this->success( $language, 201 );
	}

	/**
	 * Update a language.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ): \WP_REST_Response|\WP_Error {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$repo     = $this->get_repo();
		$language = $repo->find_by_slug( $slug );

		if ( ! $language ) {
			return $this->error( 'not_found', __( 'Language not found.', 'perflocale' ), 404 );
		}

		// Sanitize each user-supplied value before write. Without this the
		// route's permission_callback (manage cap) is the only filter on
		// what gets stored — fine for trusted admins but no defense-in-depth
		// against an admin's session being misused, and an inconsistent
		// contract vs create_item which DOES enum-validate text_direction.
		$candidates = [
			'name'           => $request->get_param( 'name' ),
			'native_name'    => $request->get_param( 'native_name' ),
			'flag'           => $request->get_param( 'flag' ),
			'is_active'      => $request->get_param( 'is_active' ),
			'sort_order'     => $request->get_param( 'sort_order' ),
			'text_direction' => $request->get_param( 'text_direction' ),
		];

		$data = [];

		foreach ( $candidates as $key => $value ) {
			if ( $value === null ) {
				continue;
			}

			switch ( $key ) {
				case 'name':
				case 'native_name':
					$data[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'flag':
					// sanitize_text_field, matching create_item(), the admin screen
					// and the repository's own sanitise. sanitize_key() has no /u
					// modifier and strips every byte of an emoji flag, so this route
					// stored '' where the other two keep it.
					$data[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'is_active':
					$data[ $key ] = absint( $value );
					break;
				case 'sort_order':
					$data[ $key ] = (int) $value;
					break;
				case 'text_direction':
					if ( ! in_array( $value, [ 'ltr', 'rtl' ], true ) ) {
						return $this->error(
							'invalid_text_direction',
							__( 'text_direction must be "ltr" or "rtl".', 'perflocale' ),
							400
						);
					}
					$data[ $key ] = $value;
					break;
			}
		}

		$written = $repo->update( (int) $language->id, $data );

		// update() returns false only when the write actually failed - it
		// returns true for a write that changed no rows - so a false here means
		// the row on disk does NOT match what we are about to echo back. A PATCH
		// that carried no recognised field is a different thing: $data is empty,
		// wpdb::update() builds an UPDATE with no SET clause and returns false
		// for a request that asked for nothing. Only report a failure the caller
		// actually asked for, so the no-op PATCH keeps its 200 (and its cache
		// flush and perflocale/language/updated hook) exactly as before.
		if ( $data !== [] && ! $written ) {
			return $this->error(
				'update_failed',
				__( 'The language could not be updated; nothing was changed.', 'perflocale' ),
				500
			);
		}

		$updated = $repo->find( (int) $language->id );

		return $this->success( $updated );
	}

	/**
	 * Delete a language.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		$slug     = sanitize_key( $request->get_param( 'slug' ) );
		$repo     = $this->get_repo();
		$language = $repo->find_by_slug( $slug );

		if ( ! $language ) {
			return $this->error( 'not_found', __( 'Language not found.', 'perflocale' ), 404 );
		}

		if ( (int) $language->is_default === 1 ) {
			return $this->error( 'cannot_delete_default', __( 'Cannot delete the default language.', 'perflocale' ) );
		}

		// delete() runs its cascade in a transaction and returns false after a
		// ROLLBACK — the language and every one of its links are still there.
		// Reporting `{"deleted": true}` with HTTP 200 over that told every REST
		// and CLI consumer the opposite of what the database holds.
		if ( ! $repo->delete( (int) $language->id ) ) {
			return $this->error(
				'delete_failed',
				__( 'The language could not be deleted; nothing was changed.', 'perflocale' ),
				500
			);
		}

		return $this->success( [ 'deleted' => true ] );
	}

	/**
	 * Bulk-update sort_order based on a positional ID array. Drives the
	 * drag-handle reorder UI on the Languages admin list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reorder_items( $request ): \WP_REST_Response|\WP_Error {
		$order  = (array) $request->get_param( 'order' );
		$offset = (int) $request->get_param( 'offset' );
		$repo   = $this->get_repo();
		$count  = $repo->reorder( array_map( 'intval', $order ), $offset );

		if ( $count === 0 ) {
			return $this->error( 'reorder_failed', __( 'Reorder failed. The submitted IDs may not all exist.', 'perflocale' ) );
		}

		return $this->success( [ 'reordered' => $count ] );
	}

	/**
	 * Get the language repository.
	 *
	 * @return LanguageRepository
	 */
	private function get_repo(): LanguageRepository {
		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );

		return \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
	}
}
