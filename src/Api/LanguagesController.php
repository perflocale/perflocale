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
 * REST routes for languages: list, get, create, update, delete, reorder.
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
					// Documentation only: update_item() sanitizes each field, and no strict numeric type keeps the accepted input shape.
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
	 * Permission check for the public read routes: every field is already shown to visitors (switcher, hreflang).
	 *
	 * @hook perflocale/api/languages_public
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function public_read_permission_check( \WP_REST_Request $request ): bool|\WP_Error {
		/**
		 * Whether the `/perflocale/v1/languages` read routes and the `perflocale/list-languages` ability are public.
		 *
		 * False requires the `read` capability; the ability stays listed and answers only logged-in users.
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
		// The URL segment only: get_param() prefers a body or query `slug`, which could name another language.
		$slug     = sanitize_key( $request->get_url_params()['slug'] ?? '' );
		$repo     = $this->get_repo();
		$language = $repo->find_by_slug( $slug );

		// An inactive language is shown only to language managers, as the list route returns active ones only.
		if ( ! $language || ( empty( $language->is_active ) && ! current_user_can( 'perflocale_manage_languages' ) ) ) {
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

		// Refused before the insert: set_default() rejects an inactive language, which would leave a half-applied create.
		if ( $wants_default && $is_active === 0 ) {
			return $this->error(
				'invalid_default_inactive',
				__( 'is_default requires is_active - an inactive language cannot be the default.', 'perflocale' ),
				400
			);
		}

		$slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );

		// Both unique keys (slug, locale) are checked first, so a duplicate is a 409, not a failed INSERT's 500.
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
				// Not sanitize_key(): it strips emoji flags. The arg and the repository already sanitize.
				'flag'           => (string) ( $request->get_param( 'flag' ) ?? '' ),
				'is_active'      => $is_active,
				// Explicit ltr/rtl wins; otherwise the locale decides, so an RTL language is not created left-to-right.
				'text_direction' => in_array( $request->get_param( 'text_direction' ), [ 'ltr', 'rtl' ], true )
					? $request->get_param( 'text_direction' )
					: \PerfLocale\Helper::default_text_direction( $locale ),
			]
		);

		if ( $id === false ) {
			return $this->error( 'create_failed', __( 'Failed to create language.', 'perflocale' ), 500 );
		}

		// set_default() swaps the default in one transaction. A false here is a race or DB error: the language exists,
		// the default did not move, and the caller must know.
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
		// The URL segment only: get_param() prefers a body or query `slug`, which could name another language.
		$slug     = sanitize_key( $request->get_url_params()['slug'] ?? '' );
		$repo     = $this->get_repo();
		$language = $repo->find_by_slug( $slug );

		if ( ! $language ) {
			return $this->error( 'not_found', __( 'Language not found.', 'perflocale' ), 404 );
		}

		// Each value is sanitized here: the route declares no sanitize_callback.
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
					// Not sanitize_key(): it strips emoji flags.
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

		// update() is false on a failed write and on a PATCH with no recognised field (no SET clause): that no-op keeps its 200.
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
		// The URL segment only: get_param() prefers a body or query `slug`, which could name another language.
		$slug     = sanitize_key( $request->get_url_params()['slug'] ?? '' );
		$repo     = $this->get_repo();
		$language = $repo->find_by_slug( $slug );

		if ( ! $language ) {
			return $this->error( 'not_found', __( 'Language not found.', 'perflocale' ), 404 );
		}

		if ( (int) $language->is_default === 1 ) {
			return $this->error( 'cannot_delete_default', __( 'Cannot delete the default language.', 'perflocale' ) );
		}

		// A language that still has posts is refused with 409 and the counts (see LanguageRepository::delete_blockers()).
		// A failed count is a failed delete with no change.
		$blockers = $repo->delete_blockers( (int) $language->id );

		if ( null === $blockers ) {
			return $this->error(
				'delete_failed',
				__( 'The language could not be deleted; nothing was changed.', 'perflocale' ),
				500
			);
		}

		if ( $blockers['posts'] !== [] ) {
			return new \WP_Error(
				'language_has_content',
				__( 'The language still has content. Move its posts to the Trash first, or deactivate the language instead (is_active false): an inactive language keeps its translations linked.', 'perflocale' ),
				[
					'status'      => 409,
					'posts'       => $blockers['posts'],
					'attachments' => $blockers['attachments'],
					'terms'       => $blockers['terms'],
					'templates'   => $blockers['templates'],
				]
			);
		}

		// False means the cascade rolled back: the language and its links are all still there.
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
	 * Set sort_order from a positional ID array (the drag-and-drop order on the Languages screen).
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
