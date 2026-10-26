<?php
/**
 * Translations REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints of post translations: GET and POST /translations/{type}/{id}, PUT and DELETE
 * /translations/{type}/{id}/{lang}, assign-language and bulk-translate.
 */
final class TranslationsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'translations';

	/**
	 * Ceiling of each site-wide estimate total: the largest integer JavaScript keeps exact.
	 */
	private const ESTIMATE_MAX = 9007199254740991;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<type>[a-z]+)/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_translations' ],
					'permission_callback' => [ $this, 'object_permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_translation' ],
					'permission_callback' => [ $this, 'object_permissions_check' ],
					// target_lang is not `required`: the handler returns its own 'missing_lang' error. copy_content is undeclared:
					// the handler's (bool) cast differs from REST boolean coercion ('0').
					'args'                => [
						'target_lang'  => [
							'type'              => 'string',
							'description'       => __( 'Target language slug for the new translation.', 'perflocale' ),
							'sanitize_callback' => 'sanitize_key',
						],
						'copy_content' => [
							'description' => __( 'Whether to copy the source content into the new translation.', 'perflocale' ),
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<type>[a-z]+)/(?P<id>\d+)/(?P<lang>[a-z]{2,3}(?:-[a-z]{2,3})?)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_translation' ],
					'permission_callback' => [ $this, 'object_permissions_check' ],
					// All optional (partial update). `content` has no sanitize_callback: the handler applies wp_kses_post, and
					// sanitize_text_field would strip every tag first.
					'args'                => [
						'title'   => [
							'type'              => 'string',
							'description'       => __( 'Translated post title.', 'perflocale' ),
							// Keeps percent signs; sanitize_text_field() deletes %XX.
							'sanitize_callback' => [ \PerfLocale\Helper::class, 'sanitize_plain_text_field' ],
						],
						'content' => [
							'type'        => 'string',
							'description' => __( 'Translated post content (HTML allowed; sanitised with wp_kses_post by the handler).', 'perflocale' ),
						],
						'excerpt' => [
							'type'              => 'string',
							'description'       => __( 'Translated post excerpt.', 'perflocale' ),
							'sanitize_callback' => [ \PerfLocale\Helper::class, 'sanitize_plain_textarea_field' ],
						],
						'status'  => [
							'type'              => 'string',
							'description'       => __( 'Post status to set (e.g. draft, publish).', 'perflocale' ),
							'sanitize_callback' => 'sanitize_key',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_translation' ],
					'permission_callback' => [ $this, 'object_permissions_check' ],
				],
			]
		);

		// Assign a language to a post without one; refused when it has one, so a post never leaves its group by accident.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<type>[a-z]+)/(?P<id>\d+)/language',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'assign_language' ],
					'permission_callback' => [ $this, 'object_permissions_check' ],
					// As assign_language()'s sanitize_key.
					'args'                => [
						'slug' => [
							'type'              => 'string',
							'description'       => __( 'Language slug to assign to the (currently unassigned) post.', 'perflocale' ),
							'sanitize_callback' => 'sanitize_key',
						],
					],
				],
			]
		);

		// Bulk machine translation, as the Translations screen's bulk action: the same BulkTranslateJob, the Dispatcher's
		// budget gate; estimate_only=true returns the CostEstimator envelope and dispatches nothing.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-translate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_translate' ],
				'permission_callback' => [ $this, 'manage_translations_permissions_check' ],
				'args'                => [
					'post_ids'        => [
						'description' => __( 'Source post IDs to translate.', 'perflocale' ),
					],
					'target_lang_ids' => [
						'description' => __( 'Target language IDs.', 'perflocale' ),
					],
					'include_meta'    => [
						'description' => __( 'Also machine-translate registered meta fields.', 'perflocale' ),
					],
					'estimate_only'   => [
						'description' => __( 'Return the character/cost estimate without dispatching.', 'perflocale' ),
					],
					'site_wide'       => [
						'description' => __( 'Translate ALL published posts of the given post types (chunked background chain) instead of an explicit ID list.', 'perflocale' ),
					],
					'post_types'      => [
						'description' => __( 'Post types for site_wide mode (default: post, page).', 'perflocale' ),
					],
					'after_id'        => [
						'description' => __( 'Continue the site-wide estimate after this post ID.', 'perflocale' ),
						'type'        => 'integer',
						'minimum'     => 0,
					],
					'totals'          => [
						'description' => __( 'Totals of the earlier estimate requests.', 'perflocale' ),
						'type'        => 'object',
						'properties'  => array_fill_keys(
							[ 'chars', 'items', 'skipped_existing', 'builder_layouts', 'checked' ],
							[
								'type'    => 'integer',
								'minimum' => 0,
							]
						),
					],
				],
			]
		);

	}

	/**
	 * The posts of $post_ids the current user may edit (host-aware), primed 200 at a time.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int[]
	 */
	private function editable_posts( array $post_ids ): array {
		$out = [];

		foreach ( array_chunk( $post_ids, 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );

			foreach ( $chunk as $id ) {
				if ( \PerfLocale\Helper::user_can_edit_object( $id ) ) {
					$out[] = $id;
				}
			}

			\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
		}

		return $out;
	}

	/**
	 * Manage-translations gate for the bulk route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function manage_translations_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * POST /translations/bulk-translate: machine translation of a selection or the whole site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_translate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->get( 'settings' );

		if ( ! $settings->mt_enabled() ) {
			return $this->error( 'mt_disabled', __( 'Machine translation is disabled.', 'perflocale' ), 403 );
		}

		$lang_ids     = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'target_lang_ids' ) ) ) );
		$include_meta = (bool) $request->get_param( 'include_meta' );
		$site_wide    = (bool) $request->get_param( 'site_wide' );
		$post_ids     = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'post_ids' ) ) ) );

		if ( $lang_ids === [] ) {
			return $this->error( 'invalid_params', __( 'target_lang_ids is required.', 'perflocale' ) );
		}

		if ( ! $site_wide && $post_ids === [] ) {
			return $this->error( 'invalid_params', __( 'post_ids is required unless site_wide is set.', 'perflocale' ) );
		}

		$estimator = new \PerfLocale\MachineTranslation\CostEstimator( $settings );

		if ( (bool) $request->get_param( 'estimate_only' ) ) {
			if ( $site_wide ) {
				// Only active languages, as the job translates into those only; that bounds the pair loop.
				$repo   = $plugin->get( 'lang_repo' );
				$active = [];
				foreach ( $repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $repo->get_active() : [] as $lang ) {
					$id       = get_object_vars( $lang )['id'] ?? null;
					$active[] = is_numeric( $id ) ? (int) $id : 0;
				}
				$lang_ids = array_values( array_intersect( array_unique( $lang_ids ), $active ) );

				if ( $lang_ids === [] ) {
					return $this->error( 'invalid_params', __( 'target_lang_ids is required.', 'perflocale' ) );
				}

				// Site-wide estimate: the selection's ids in bounded keyset pages, never one large array.
				global $wpdb;
				$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $request->get_param( 'post_types' ) ?: [ 'post', 'page' ] ) ) ) );
				// Settings::get_site_translate_post_types() is the one definition of what a site run acts on.
				$allowed_types = \PerfLocale\Plugin::get_instance()->get( 'settings' )->get_site_translate_post_types();
				$post_types    = array_values( array_unique( array_intersect( $post_types, $allowed_types ) ) );

					// No type left: an empty IN () is a SQL error, and a zero estimate would be wrong.
					if ( $post_types === [] ) {
						return $this->error( 'invalid_params', __( 'No valid post types supplied.', 'perflocale' ), 400 );
					}

					$tph        = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
				// At most 5 pages after `after_id` per request, added to the client's running `totals`; `next_after_id` (0 when
				// done) continues. Only posts the caller may edit are counted, as the job does.
				$after  = $request->get_param( 'after_id' );
				$after  = is_numeric( $after ) ? max( 0, (int) $after ) : 0;
				$carry  = (array) $request->get_param( 'totals' );
				$totals = [];
				foreach ( [ 'chars', 'items', 'skipped_existing', 'builder_layouts', 'checked' ] as $key ) {
					$value = $carry[ $key ] ?? 0;
					// The schema passes a too-large JSON number on as a wrapped integer: refuse what is out of range.
					if ( ! is_int( $value ) || $value < 0 || $value > self::ESTIMATE_MAX ) {
						return $this->error( 'invalid_params', __( 'Invalid request.', 'perflocale' ) );
					}
					$totals[ $key ] = $value;
				}
				$next = 0;
				for ( $pages = 0; $pages < 5; $pages++ ) {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$page = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$tph}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 2000", array_merge( $post_types, [ $after ] ) ) ) );
					// phpcs:enable
					if ( $page === [] ) {
						$next = 0;
						break;
					}
					$after = (int) end( $page );
					$next  = count( $page ) < 2000 ? 0 : $after;
					$est   = $estimator->estimate_posts( $this->editable_posts( $page ), $lang_ids, $include_meta );
					$add   = [
						'checked'          => count( $page ),
						'chars'            => (int) $est['chars'],
						'items'            => (int) $est['items'],
						'skipped_existing' => (int) $est['skipped_existing'],
						'builder_layouts'  => is_int( $est['builder_layouts'] ?? null ) ? $est['builder_layouts'] : 0,
					];
					foreach ( $add as $key => $n ) {
						if ( $n > self::ESTIMATE_MAX - $totals[ $key ] ) {
							return $this->error( 'invalid_params', __( 'Invalid request.', 'perflocale' ) );
						}
						$totals[ $key ] += $n;
					}
					if ( 0 === $next ) {
						break;
					}
				}

				return $this->success(
					$estimator->summarize( $totals['chars'], $totals['items'], $totals['skipped_existing'] )
					+ \PerfLocale\MachineTranslation\CostEstimator::builder_fields( $totals['builder_layouts'] )
					+ [
						'checked'       => $totals['checked'],
						'next_after_id' => $next,
					]
				);
			}

			// The estimate exposes length aggregates of the named posts: read access to each, as
			// MachineTranslateController::estimate().
			if ( ! $this->can_read_posts( $post_ids ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot access one of the requested posts.', 'perflocale' ), 403 );
			}

			return $this->success( $estimator->estimate_posts( $post_ids, $lang_ids, $include_meta ) );
		}

		if ( $site_wide ) {
			$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $request->get_param( 'post_types' ) ?: [ 'post', 'page' ] ) ) ) );
				// Settings::get_site_translate_post_types() is the one definition of what a site run acts on.
				$allowed_types = \PerfLocale\Plugin::get_instance()->get( 'settings' )->get_site_translate_post_types();
				$post_types    = array_values( array_intersect( $post_types, $allowed_types ) );

			// Refused here instead of dispatching a job that would do nothing.
			if ( $post_types === [] ) {
				return $this->error( 'invalid_params', __( 'No valid post types supplied.', 'perflocale' ), 400 );
			}

			$outcome    = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\SiteTranslateJob(),
				[
					'post_types'      => $post_types,
					'target_lang_ids' => $lang_ids,
					'include_meta'    => $include_meta,
					'after_id'        => 0,
				]
			);
		} else {
			$outcome = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\BulkTranslateJob(),
				[
					'source_ids'      => $post_ids,
					'target_lang_ids' => $lang_ids,
					'include_meta'    => $include_meta,
					'trigger'         => 'rest',
				]
			);
		}

		$status = 202;
		if ( ( $outcome['mode'] ?? '' ) === 'denied' ) {
			$status = 403;
		} elseif ( ( $outcome['mode'] ?? '' ) === 'error' ) {
			$status = 500;
		} elseif ( ( $outcome['mode'] ?? '' ) === 'sync' ) {
			$status = 200;
		}

		return $this->success( $outcome, $status );
	}

	/**
	 * Permission of /translations/<type>/<id>: `perflocale_translate`, then edit_post or edit_term on the named object
	 * (strings: the capability only). The handlers check again, some more strictly on the translation itself.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function object_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->translate_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		// Path segments only: get_param() prefers the query, which could point the check at another object.
		$type = sanitize_key( $request->get_url_params()['type'] ?? '' );
		$id   = absint( $request->get_url_params()['id'] ?? 0 );

		// Same 400 for an unknown type as the handlers.
		if ( ! in_array( $type, [ 'post', 'term', 'string' ], true ) ) {
			return new \WP_Error(
				'invalid_type',
				__( 'Invalid object type.', 'perflocale' ),
				[ 'status' => 400 ]
			);
		}

		if ( $type === 'post' && ! \PerfLocale\Helper::user_can_edit_object( $id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You cannot access this post.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		if ( $type === 'term' && ! current_user_can( 'edit_term', $id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You cannot access this term.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		// Strings have no per-object owner: the capability is the gate.
		return true;
	}

	/**
	 * Every language of an object with whether it has a translation. A sibling the user may neither read nor edit
	 * (Helper::user_can_view_object()) shows language and existence only: post_id, statuses and edit_url are null.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_translations( \WP_REST_Request $request ): \WP_REST_Response {
		// Path segments only, as object_permissions_check() read them.
		$type = sanitize_key( $request->get_url_params()['type'] ?? '' );
		$id   = absint( $request->get_url_params()['id'] ?? 0 );

		$allowed_types = [ 'post', 'term', 'string' ];

		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_type',
					'message' => __( 'Invalid object type.', 'perflocale' ),
				],
				400
			);
		}

		// Posts only: every field below is post-shaped.
		if ( $type !== 'post' ) {
			return new \WP_REST_Response(
				[
					'code'    => 'unsupported_type',
					'message' => __( 'Only post translations can be listed here.', 'perflocale' ),
				],
				400
			);
		}

		// Access to this object.
		if ( ! \PerfLocale\Helper::user_can_edit_object( $id ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'rest_forbidden',
					'message' => __( 'You cannot access this post.', 'perflocale' ),
				],
				403
			);
		}

		$plugin       = \PerfLocale\Plugin::get_instance();
		$lang_repo    = $plugin->get( 'lang_repo' );
		$manager      = $this->get_manager();
		$translations = $manager->get_translations( $id );
		$post_lang    = $manager->detect_post_language( $id );
		$languages    = $lang_repo->get_active();

		$result  = [];
		$default = $lang_repo->get_default();

		// Prime the post cache for every translation in one query; $id is appended below, so the list is never empty.
		$all_translation_ids = array_filter( array_map( 'intval', array_values( $translations ) ) );

		if ( ! in_array( $id, $all_translation_ids, true ) ) {
			$all_translation_ids[] = $id;
		}

		_prime_post_caches( $all_translation_ids, true, true );

		// Statuses computed once, outside the loop.
		$status_cache = [];

		foreach ( $languages as $lang ) {
			if ( isset( $translations[ $lang->slug ] ) ) {
				$status_cache[ $lang->slug ] = $manager->get_translation_status( $id, $lang->slug );
			}
		}

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $has_translation ? $translations[ $lang->slug ] : null;
			$translated_post = $translated_id ? get_post( $translated_id ) : null; // Primed above.

			// Skip links to deleted posts.
			if ( $has_translation && ! $translated_post ) {
				$has_translation = false;
				$translated_id   = null;
			}

			$is_current = ( $post_lang && $post_lang->slug === $lang->slug );
			$is_default = ( $default && $lang->slug === $default->slug );

			// The current post has a post_id even when the map lacks it.
			if ( $is_current && ! $translated_id ) {
				$translated_id   = $id;
				$translated_post = get_post( $id );
				$has_translation = true;
			}


			// Precomputed above.
			$status_obj = $status_cache[ $lang->slug ] ?? null;

			// A sibling the user may not see: language and existence only.
			if ( $translated_post && (int) $translated_id !== $id && ! \PerfLocale\Helper::user_can_view_object( (int) $translated_id ) ) {
				$translated_id   = null;
				$translated_post = null;
				$status_obj      = null;
			}

			$result[] = [
				'slug'            => $lang->slug,
				// BCP 47 form for editor-sidebar.js labels, so the lowercase slug is not upper-cased again.
				'bcp47'           => \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->slug ),
				'language_id'     => (int) $lang->id,
				// Completes the Language object of the editor's JavaScript hooks ({ slug, locale, name, nativeName, isDefault }).
				'locale'          => is_object( $lang ) && is_string( $lang->locale ?? null ) ? $lang->locale : '',
				'name'            => $lang->name,
				'native_name'     => $lang->native_name ?? $lang->name,
				'is_current'      => $is_current,
				'is_default'      => $is_default,
				'has_translation' => $has_translation,
				'post_id'         => $translated_id,
				'status'          => $status_obj ? $status_obj->value : null,
				'status_label'    => $status_obj ? $status_obj->label() : null,
				// The post's own status, not the link's (written at creation and not kept current): the Site Editor panel needs
				// it to say whether a translation is in use (a draft template renders the source).
				'post_status'     => $translated_post ? $translated_post->post_status : null,
				'edit_url'        => $translated_post ? ( \PerfLocale\Admin\ObjectLinks::edit_url( (int) $translated_id ) ?: null ) : null,
			];
		}

		return $this->success(
			[
				'languages'     => $result,
				// A post without a language: the sidebar offers "Set as <lang>" instead of "+ Create".
				'is_unassigned' => ( $type === 'post' ) ? ( $post_lang === null ) : false,
			]
		);
	}

	/**
	 * Create a translation for an object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_translation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// `type` and `id` from the path (as the permission check); target_lang and copy_content from the body.
		$type        = $request->get_url_params()['type'] ?? '';
		$id          = absint( $request->get_url_params()['id'] ?? 0 );
		$target_lang = sanitize_key( $request->get_param( 'target_lang' ) ?? '' );
		$copy        = (bool) $request->get_param( 'copy_content' );

		// Posts only: another type would create a translation of a post the caller did not name.
		if ( 'post' !== $type ) {
			return $this->error( 'unsupported_type', __( 'Only post translations can be created here.', 'perflocale' ) );
		}

		// Edit rights on the source post.
		if ( ! \PerfLocale\Helper::user_can_edit_object( $id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this post.', 'perflocale' ), 403 );
		}

		if ( $target_lang === '' ) {
			return $this->error( 'missing_lang', __( 'Target language is required.', 'perflocale' ) );
		}

		$source_post = get_post( $id );
		if ( $source_post && 'auto-draft' === $source_post->post_status ) {
			return $this->error( 'source_not_saved', __( 'Save the post before creating translations.', 'perflocale' ) );
		}

		$manager = $this->get_manager();

		// The copy comes from the group's default-language member, not necessarily $id: without access to it the create
		// is refused, so a translation's starting content never depends on who clicked.
		$copy_from = $manager->get_copy_source_id( $id, $target_lang );

		if ( $copy_from > 0 && $copy_from !== $id && ! \PerfLocale\Helper::user_can_copy_translation_source( $copy_from ) ) {
			return $this->error( 'source_forbidden', __( 'You cannot edit the original this translation is copied from.', 'perflocale' ), 403 );
		}

		// wp_insert_post() checks no capability: the copy source type's own create gate.
		if ( $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_like( $copy_from ) ) {
			return $this->error( 'cannot_create_type', __( 'You do not have permission to create translations of this content.', 'perflocale' ), 403 );
		}

		$created = false;
		$new_id  = $manager->create_translation( $id, $target_lang, $copy, \PerfLocale\Enum\SourceType::Manual, $created );

		if ( $new_id === false ) {
			return $this->error( 'create_failed', __( 'Failed to create translation.', 'perflocale' ), 500 );
		}

		return $this->success( self::created_response( (int) $new_id, true === $created ), 201 );
	}

	/**
	 * The create response: a translation that already existed is named only to a user who may see it.
	 *
	 * @param int  $post_id Translation create_translation() returned.
	 * @param bool $created Whether this request inserted it.
	 * @return array{post_id: int|null, edit_url: string|null}
	 */
	private static function created_response( int $post_id, bool $created ): array {
		if ( ! $created && ! \PerfLocale\Helper::user_can_view_object( $post_id ) ) {
			return [
				'post_id'  => null,
				'edit_url' => null,
			];
		}

		return [
			'post_id'  => $post_id,
			'edit_url' => \PerfLocale\Admin\ObjectLinks::edit_url( $post_id ) ?: null,
		];
	}

	/**
	 * The create-translation Ability's output by the same rule; here so it loads only when the ability runs.
	 *
	 * @param int       $post_id  Translation create_translation() returned.
	 * @param string    $language Target language slug.
	 * @param bool|null $created  Whether this request inserted it.
	 * @return array{translated_post_id: int|null, language: string, edit_url: string|null}
	 */
	public static function ability_created( int $post_id, string $language, ?bool $created ): array {
		$named = self::created_response( $post_id, true === $created );

		return [
			'translated_post_id' => $named['post_id'],
			'language'           => $language,
			'edit_url'           => $named['edit_url'],
		];
	}

	/**
	 * Assign a language to a post with none (created before activation or by a flow that skips assignment), so it can be
	 * listed and translated. Refused when the post has a language, so it never leaves its group by accident.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function assign_language( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// type and id from the path (as the permission check); slug from the body.
		$type = sanitize_key( $request->get_url_params()['type'] ?? '' );
		$id   = absint( $request->get_url_params()['id'] ?? 0 );
		$slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );

		if ( $type !== 'post' ) {
			return $this->error( 'invalid_type', __( 'Only posts support direct language assignment.', 'perflocale' ) );
		}

		if ( ! \PerfLocale\Helper::user_can_edit_object( $id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this post.', 'perflocale' ), 403 );
		}

		if ( $slug === '' ) {
			return $this->error( 'missing_lang', __( 'Language slug is required.', 'perflocale' ) );
		}

		$plugin    = \PerfLocale\Plugin::get_instance();
		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$lang      = $lang_repo->find_by_slug( $slug );

		if ( ! $lang || empty( $lang->is_active ) ) {
			return $this->error( 'unknown_lang', __( 'Unknown or inactive language.', 'perflocale' ), 404 );
		}

		$manager = $this->get_manager();

		// Checked in the repository, not detect_post_language(), whose per-request memo can still answer null after an
		// assignment and let a concurrent call overwrite it.
		$group_repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );

		// Check and assign under one lock per post, so a concurrent second request sees the first's row and gets 409.
		$outcome = \PerfLocale\Concurrency\Lock::with(
			'assign_language_post_' . $id,
			5,
			function () use ( $group_repo, $id, $manager, $slug ) {
				if ( $group_repo->find_for_object( $id, \PerfLocale\Enum\ObjectType::Post ) !== null ) {
					return $this->error( 'already_assigned', __( 'This post already has a language assigned.', 'perflocale' ), 409 );
				}

				if ( ! $manager->set_post_language( $id, $slug ) ) {
					return $this->error( 'assign_failed', __( 'Failed to assign language.', 'perflocale' ), 500 );
				}

				return $this->success(
					[
						'post_id' => $id,
						'slug'    => $slug,
					],
					200
				);
			}
		);

		// Lock taken by a concurrent assignment: 409.
		if ( $outcome === null ) {
			return $this->error( 'assign_busy', __( 'Another language assignment for this post is in progress. Please retry.', 'perflocale' ), 409 );
		}

		return $outcome;
	}

	/**
	 * Update a translation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_translation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Path segments only: `lang` is also the plugin's public query var, so a stray ?lang= must not pick another
		// language's translation.
		$type = $request->get_url_params()['type'] ?? '';
		$id   = absint( $request->get_url_params()['id'] ?? 0 );
		$lang = sanitize_key( $request->get_url_params()['lang'] ?? '' );

		// Posts only: `id` is read as a post id.
		if ( 'post' !== $type ) {
			return $this->error( 'unsupported_type', __( 'Only post translations can be changed here.', 'perflocale' ) );
		}

		$manager       = $this->get_manager();
		$translated_id = $manager->get_translation_id( $id, $lang );

		if ( ! $translated_id ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
		}

		// Edit rights on this translation post.
		if ( ! \PerfLocale\Helper::user_can_edit_object( (int) $translated_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this translation.', 'perflocale' ), 403 );
		}

		$update_data = [];

		if ( $request->has_param( 'title' ) ) {
			$update_data['post_title'] = \PerfLocale\Helper::sanitize_plain_text_field( $request->get_param( 'title' ) );
		}

		if ( $request->has_param( 'content' ) ) {
			$update_data['post_content'] = \PerfLocale\Translation\ProtectedUnits::sanitize_around( is_scalar( $request->get_param( 'content' ) ) ? (string) $request->get_param( 'content' ) : '', $id, 'wp_kses_post' );
		}

		if ( $request->has_param( 'excerpt' ) ) {
			$update_data['post_excerpt'] = \PerfLocale\Helper::sanitize_plain_textarea_field( $request->get_param( 'excerpt' ) );
		}

		if ( $request->has_param( 'status' ) ) {
			// A registered status only; an unknown one would orphan the post from every list.
			$requested_status = sanitize_key( $request->get_param( 'status' ) );
			$status_obj       = get_post_status_object( $requested_status );

			// An unregistered status is ignored, not refused, and the other fields still apply (documented contract).
			// is_object(), not instanceof stdClass, so a future WP_Post_Status class keeps the gates below.
			if ( is_object( $status_obj ) ) {
				// A status change is not an edit: publishing and trashing need their own capabilities, as on wp/v2/posts, mapped
				// from the target's own post type (WP_REST_Posts_Controller::handle_status_param()). For the default language the
				// target can be the source post itself.
				$target_type = get_post_type_object( (string) get_post_type( $translated_id ) );

				if ( ! $target_type instanceof \WP_Post_Type ) {
					return $this->error(
						'invalid_status',
						__( 'Unknown post type for this translation.', 'perflocale' ),
						400
					);
				}

				// Trash is a deletion: the gate of delete_translation().
				if ( 'trash' === $requested_status && ! \PerfLocale\Helper::user_can_delete_object( (int) $translated_id ) ) {
					return $this->error(
						'rest_forbidden',
						__( 'You cannot trash this translation.', 'perflocale' ),
						403
					);
				}

				// Editorial statuses only: never one core registers as `internal` (auto-draft is deleted by core's cleanup,
				// inherit takes a parent's status). Trash is internal too but gated above.
				if ( ! empty( $status_obj->internal ) && 'trash' !== $requested_status ) {
					return $this->error(
						'rest_forbidden',
						__( 'That status cannot be set through this endpoint.', 'perflocale' ),
						403
					);
				}

				// Public, private or scheduled is a publish (`public` covers other plugins' statuses); draft and pending are not.
				$is_publish_transition = ! empty( $status_obj->public )
					|| ! empty( $status_obj->private )
					|| 'future' === $requested_status;

				if ( $is_publish_transition && ! current_user_can( $target_type->cap->publish_posts ) ) {
					return $this->error(
						'rest_forbidden',
						__( 'You are not allowed to publish this translation.', 'perflocale' ),
						403
					);
				}

				$update_data['post_status'] = $requested_status;
			}
		}

		if ( ! empty( $update_data ) ) {
			$update_data['ID'] = $translated_id;
			// An empty page_template keeps the stored one: a template the theme lacks would stop the save before its hooks.
			$update_data['page_template'] = '';
			// wp_update_post() unslashes and REST params arrive unslashed: without wp_slash every backslash is lost.
			$result            = wp_update_post( wp_slash( $update_data ), true );

			if ( is_wp_error( $result ) ) {
				// WordPress refusing the client's own status value is a 4xx, not a 5xx (a 5xx sends clients into retries);
				// nothing was written.
				if ( isset( $update_data['post_status'] ) ) {
					return $this->error(
						'translation_status_rejected',
						sprintf(
							/* translators: 1: requested post status, 2: reason reported by WordPress */
							__( 'WordPress refused the status "%1$s" for this post: %2$s', 'perflocale' ),
							(string) $update_data['post_status'],
							$result->get_error_message()
						),
						400
					);
				}

				return $this->error( 'translation_update_failed', $result->get_error_message(), 500 );
			}
		}

		return $this->success(
			[
				'updated' => true,
				'post_id' => $translated_id,
			]
		);
	}

	/**
	 * Delete a translation's post for good (wp_delete_post() with force).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_translation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Path segments only (see update_translation()).
		$type = $request->get_url_params()['type'] ?? '';
		$id   = absint( $request->get_url_params()['id'] ?? 0 );
		$lang = sanitize_key( $request->get_url_params()['lang'] ?? '' );

		// Posts only: the delete removes a post for good.
		if ( 'post' !== $type ) {
			return $this->error( 'unsupported_type', __( 'Only post translations can be changed here.', 'perflocale' ) );
		}

		$manager       = $this->get_manager();
		$translated_id = $manager->get_translation_id( $id, $lang );

		if ( ! $translated_id ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
		}

		// Delete rights on this translation post.
		if ( ! \PerfLocale\Helper::user_can_delete_object( (int) $translated_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot delete this translation.', 'perflocale' ), 403 );
		}

		wp_delete_post( $translated_id, true );

		return $this->success( [ 'deleted' => true ] );
	}

	/**
	 * Get the post translation manager.
	 *
	 * @return PostTranslationManager
	 */
	private function get_manager(): PostTranslationManager {
		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );

		$settings = $plugin->get( 'settings' );

		return new PostTranslationManager( $cache, $settings );
	}
}
