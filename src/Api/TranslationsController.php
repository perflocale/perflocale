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
						'target_lang'         => [
							'type'              => 'string',
							'description'       => __( 'Target language slug for the new translation.', 'perflocale' ),
							'sanitize_callback' => 'sanitize_key',
						],
						'copy_content'        => [
							'description' => __( 'Whether to copy the source content into the new translation.', 'perflocale' ),
						],
						'translate_on_create' => [
							'type'              => 'boolean',
							'description'       => __( 'Machine-translate a new copied translation when the "Translate on create" setting allows it.', 'perflocale' ),
							// Runs before core's boolean coercion, which reads "1" and "true" as true: only a JSON true opts in.
							'validate_callback' => static fn( $value ): bool|\WP_Error => is_bool( $value ) ? true : new \WP_Error( 'rest_invalid_param', __( 'translate_on_create must be true or false (a JSON boolean).', 'perflocale' ), [ 'status' => 400 ] ),
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
	 * The posts of $post_ids the current user may edit (host-aware), primed 200 at a time, read up to the post that
	 * fills $room with posts the user may view: posts it may not view take no room, so they change no answer.
	 *
	 * @param int[] $post_ids Post IDs, ascending.
	 * @param int   $room     Viewable posts still to read.
	 * @return array{0: int[], 1: int, 2: int} Editable ids, viewable posts read, and the id that filled $room (0: none).
	 */
	private function editable_posts( array $post_ids, int $room ): array {
		$out  = [];
		$seen = 0;

		foreach ( array_chunk( $post_ids, 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );

			foreach ( $chunk as $id ) {
				if ( \PerfLocale\Helper::user_can_edit_object( $id ) ) {
					$out[] = $id;
				} elseif ( ! \PerfLocale\Helper::user_can_view_object( $id ) ) {
					continue;
				}

				if ( ++$seen >= $room ) {
					\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );

					return [ $out, $seen, $id ];
				}
			}

			\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
		}

		return [ $out, $seen, 0 ];
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
				// 10,000 posts the caller may view per request (pages of 2,000 ids), added to the client's running `totals`;
				// `next_after_id` (0 when done) continues. Only posts the caller may edit are counted, as the job does. A post it
				// may not view takes no room, so the answers equal those of a site without it.
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
				$room = 10000;
				while ( true ) {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$page = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$tph}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 2000", array_merge( $post_types, [ $after ] ) ) ) );
					// phpcs:enable
					if ( $page === [] ) {
						$next = 0;
						break;
					}
					$after = (int) end( $page );

					[ $editable, $seen, $filled ] = $this->editable_posts( $page, $room );

					$room -= $seen;
					$next  = $filled;
					$est   = $estimator->estimate_posts( $editable, $lang_ids, $include_meta, true );
					$add   = [
						'checked'          => count( $editable ),
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
					if ( $filled > 0 || count( $page ) < 2000 ) {
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

			return $this->success( $estimator->estimate_posts( $post_ids, $lang_ids, $include_meta, true ) );
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
		if ( 'denied' === $outcome['mode'] ) {
			$status = 403;
		} elseif ( ! empty( $outcome['busy'] ) ) {
			$status = 409;
		} elseif ( 'error' === $outcome['mode'] ) {
			$status = 500;
		} elseif ( 'sync' === $outcome['mode'] ) {
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
	 * (Helper::user_can_view_object()) is listed exactly as a missing translation, so its existence is not disclosed.
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

			// A sibling the user may not see: the row of a missing translation.
			if ( $translated_post && (int) $translated_id !== $id && ! \PerfLocale\Helper::user_can_view_object( (int) $translated_id ) ) {
				$has_translation = false;
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

		// A translation the user may not open is listed as missing; Create says why nothing can be created, naming no post.
		$existing = $manager->get_translation_id( $id, $target_lang );

		if ( self::hidden_translation( $id, $existing ) ) {
			return self::unavailable( $target_lang );
		}

		// The copy comes from the group's default-language member, maybe $id itself: edit and read on it, or the create
		// is refused, so a translation's starting content never depends on who clicked.
		$copy_from = $manager->get_copy_source_id( $id, $target_lang );

		if ( $copy_from > 0 && ! \PerfLocale\Helper::user_can_copy_translation_source( $copy_from ) ) {
			return self::source_refusal( $copy_from, $target_lang );
		}

		// wp_insert_post() checks no capability: the copy source type's own create gate.
		if ( $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_like( $copy_from ) ) {
			return $this->error( 'cannot_create_type', __( 'You do not have permission to create translations of this content.', 'perflocale' ), 403 );
		}

		$created = false;
		$new_id  = false;
		$mt      = null;

		// The block sidebar asks for the classic panel's "translate on create" of the post copied; a failure gives the copy
		// with the reason.
		if ( $copy && $copy_from > 0 && null === $existing && true === $request->get_param( 'translate_on_create' ) ) {
			$plugin = \PerfLocale\Plugin::get_instance();
			$plain  = static function () use ( $manager, $id, $target_lang ): array {
				$made = false;
				$new  = $manager->create_translation( $id, $target_lang, true, \PerfLocale\Enum\SourceType::Manual, $made );

				return [ $new, true === $made ];
			};
			$mt     = \PerfLocale\MachineTranslation\MtOnCreate::run( $plugin->settings(), $plugin->cache(), $copy_from, $target_lang, $plain );

			// Another Create of the pair is paying the provider: a copy now would take the slot it fills.
			if ( $mt['busy'] ) {
				return $this->error( 'translation_in_progress', $mt['reason'], 409 );
			}

			// No answer on the pair's jobs: retryable, nothing made.
			if ( $mt['unread'] ) {
				return $this->error( 'translation_check_failed', $mt['warning'], 503 );
			}

			if ( $mt['queued'] ) {
				\PerfLocale\Admin\MtCreateNotice::remember_warning( $id, $mt['warning'] );

				return $this->success(
					[
						'post_id'   => null,
						'edit_url'  => null,
						'queued'    => true,
						'mt_notice' => $mt['warning'],
					],
					202
				);
			}

			if ( $mt['id'] > 0 ) {
				$new_id  = $mt['id'];
				$created = $mt['inserted'];
			}
		}

		if ( false === $new_id ) {
			$new_id = $manager->create_translation( $id, $target_lang, $copy, \PerfLocale\Enum\SourceType::Manual, $created );
		}

		if ( $new_id === false ) {
			// A failed read of the existing translation is transient: busy, not broken.
			if ( \PerfLocale\Translation\PostTranslationWrites::create_was_unread() ) {
				return $this->error( 'translation_unreadable', __( 'The translation was not created: its existing translation could not be checked. Try again in a moment.', 'perflocale' ), 503 );
			}

			return $this->error( 'create_failed', __( 'Failed to create translation.', 'perflocale' ), 500 );
		}

		$body = self::created_response( (int) $new_id, true === $created );

		// Also on the translation's own screen, as after the classic panel's Create.
		if ( null !== $mt && '' !== $mt['reason'] ) {
			\PerfLocale\Admin\MtCreateNotice::remember( (int) $new_id, $mt['reason'], $mt['own'] );
			$body['mt_notice'] = $mt['reason'];
		} elseif ( null !== $mt && '' !== $mt['warning'] ) {
			\PerfLocale\Admin\MtCreateNotice::remember_warning( (int) $new_id, $mt['warning'] );
			$body['mt_notice'] = $mt['warning'];
		}

		return $this->success( $body, 201 );
	}

	/**
	 * Whether $existing, the source's translation in a language, is one the current user may not view: every read
	 * lists it as missing, so a write over it answers unavailable().
	 *
	 * @param int      $source_id Source.
	 * @param int|null $existing  Linked translation, or null.
	 * @return bool
	 */
	public static function hidden_translation( int $source_id, ?int $existing ): bool {
		return null !== $existing && $existing > 0 && $existing !== $source_id && get_post( $existing ) instanceof \WP_Post && ! \PerfLocale\Helper::user_can_view_object( $existing );
	}

	/**
	 * The refusal of a write whose copy source or sent post the user may not use: one the user may not view gets
	 * source_unavailable(), naming none.
	 *
	 * @param int    $copy_from Copy source post.
	 * @param string $lang      Target language slug.
	 * @return \WP_Error 403.
	 */
	public static function source_refusal( int $copy_from, string $lang ): \WP_Error {
		return \PerfLocale\Helper::user_can_view_object( $copy_from )
			? new \WP_Error( 'source_forbidden', __( 'You need read and edit rights on the original this translation is copied from.', 'perflocale' ), [ 'status' => 403 ] )
			: self::source_unavailable( $lang );
	}

	/**
	 * The refusal of a write whose original (the post or term sent or copied) the user may not view: names none.
	 *
	 * @param string $lang Language slug.
	 * @return \WP_Error 403.
	 */
	public static function source_unavailable( string $lang ): \WP_Error {
		return new \WP_Error( 'translation_unavailable', self::source_unavailable_message( $lang ), [ 'status' => 403 ] );
	}

	/**
	 * The text of source_refusal() for an original the user may not view: names no post.
	 *
	 * @param string $lang Language slug.
	 * @return string
	 */
	public static function source_unavailable_message( string $lang ): string {
		/* translators: %s: language code, for example DE */
		return sprintf( __( 'The %s translation cannot be created or updated: you cannot open its original. Ask an editor who can open it.', 'perflocale' ), strtoupper( \PerfLocale\Helper::format_locale_as_bcp47( $lang ) ) );
	}

	/**
	 * The one refusal of a write over a translation the user may not view (REST, Abilities, machine translation, jobs).
	 *
	 * @param string $lang Language slug.
	 * @return \WP_Error 403, naming no object.
	 */
	public static function unavailable( string $lang ): \WP_Error {
		return new \WP_Error( 'translation_unavailable', self::unavailable_message( $lang ), [ 'status' => 403 ] );
	}

	/**
	 * The text of unavailable().
	 *
	 * @param string $lang Language slug.
	 * @return string
	 */
	public static function unavailable_message( string $lang ): string {
		/* translators: %s: language code, for example DE */
		return sprintf( __( 'The %s translation cannot be created: this content has a translation you cannot open. Ask an editor who can open it.', 'perflocale' ), strtoupper( \PerfLocale\Helper::format_locale_as_bcp47( $lang ) ) );
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

				// A published post's URL before and after, for the editor's moved-URL notice.
				$before = 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';

				if ( ! $manager->set_post_language( $id, $slug ) ) {
					return $this->error( 'assign_failed', __( 'Failed to assign language.', 'perflocale' ), 500 );
				}

				// The link memos still hold the language from before the change.
				\PerfLocale\Router\UrlConverter::reset_static_caches();

				$after = '' !== $before ? (string) get_permalink( $id ) : '';

				return $this->success(
					[
						'post_id' => $id,
						'slug'    => $slug,
						'moved'   => '' !== $after && $after !== $before ? [
							'from' => $before,
							'to'   => $after,
						] : null,
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

		// One the user may not open answers exactly as a missing one, as the map lists it.
		if ( ! $translated_id || self::hidden_translation( $id, (int) $translated_id ) ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
		}

		// Edit rights on this translation post.
		if ( ! \PerfLocale\Helper::user_can_edit_object( (int) $translated_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this translation.', 'perflocale' ), 403 );
		}

		$update_data = [];
		$status_obj  = null;

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
				$refused = $this->status_refusal( (int) $translated_id, $requested_status, $status_obj );

				if ( null !== $refused ) {
					return $refused;
				}

				$update_data['post_status'] = $requested_status;
			}
		}

		if ( ! empty( $update_data ) ) {
			$update_data['ID'] = $translated_id;
			// An empty page_template keeps the stored one: a template the theme lacks would stop the save before its hooks.
			$update_data['page_template'] = '';
			require_once __DIR__ . '/../MachineTranslation/SlugPin.php';

			$rights = function () use ( $id, $translated_id, $update_data, $status_obj ): ?\WP_Error {
				if ( self::hidden_translation( $id, (int) $translated_id ) ) {
					return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
				}

				if ( ! \PerfLocale\Helper::user_can_edit_object( (int) $translated_id ) ) {
					return $this->error( 'rest_forbidden', __( 'You cannot edit this translation.', 'perflocale' ), 403 );
				}

				return isset( $update_data['post_status'] ) && is_object( $status_obj ) ? $this->status_refusal( (int) $translated_id, (string) $update_data['post_status'], $status_obj ) : null;
			};

			$result = $this->pair_write(
				$id,
				$lang,
				(int) $translated_id,
				$rights,
				static function () use ( $translated_id, $update_data ) {
					// Core would rename a published translation whose slug a same-language draft holds; a publish keeps core's check.
					$unpin = \PerfLocale\MachineTranslation\SlugPin::keep( (int) $translated_id );

					try {
						// wp_update_post() unslashes and REST params arrive unslashed: without wp_slash every backslash is lost.
						return wp_update_post( wp_slash( $update_data ), true );
					} finally {
						$unpin();
					}
				}
			);

			[ $refused, $result ] = $result;

			if ( null !== $refused ) {
				return $refused;
			}

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

		// One the user may not open answers exactly as a missing one, as the map lists it.
		if ( ! $translated_id || self::hidden_translation( $id, (int) $translated_id ) ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
		}

		// Delete rights on this translation post.
		if ( ! \PerfLocale\Helper::user_can_delete_object( (int) $translated_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot delete this translation.', 'perflocale' ), 403 );
		}

		$rights = function () use ( $id, $translated_id ): ?\WP_Error {
			if ( self::hidden_translation( $id, (int) $translated_id ) ) {
				return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
			}

			return \PerfLocale\Helper::user_can_delete_object( (int) $translated_id ) ? null : $this->error( 'rest_forbidden', __( 'You cannot delete this translation.', 'perflocale' ), 403 );
		};

		[ $refused, $deleted ] = $this->pair_write( $id, $lang, (int) $translated_id, $rights, static fn() => wp_delete_post( (int) $translated_id, true ) );

		if ( null !== $refused ) {
			return $refused;
		}

		// Core answers false or null when it deleted nothing (a pre_delete_post refusal, a post gone meanwhile).
		if ( ! $deleted instanceof \WP_Post ) {
			return $this->error( 'translation_delete_failed', __( 'The translation was not deleted.', 'perflocale' ), 500 );
		}

		return $this->success( [ 'deleted' => true ] );
	}

	/**
	 * Run a write to $source_id's $lang translation under the pair's creation lock, once the link read from the database
	 * still names $translated_id and $rights (null: allowed) passes again; else nothing is written.
	 *
	 * @param int                    $source_id     Source named in the route.
	 * @param string                 $lang          Language slug.
	 * @param int                    $translated_id Translation the request looked up.
	 * @param callable(): ?\WP_Error $rights        The object rights, checked again under the lock.
	 * @param callable(): mixed      $write         The write; its result is returned.
	 * @return array{0: \WP_Error|null, 1: mixed} The refusal (null when the write ran) and the write's result.
	 */
	private function pair_write( int $source_id, string $lang, int $translated_id, callable $rights, callable $write ): array {
		$plugin  = \PerfLocale\Plugin::get_instance();
		$found   = ( new \PerfLocale\Database\Repository\LanguageRepository( $plugin->cache() ) )->find_by_slug( $lang );
		$lang_id = null === $found ? 0 : \PerfLocale\Util\Scalar::int( \PerfLocale\Util\Scalar::prop( $found, 'id' ) );
		$groups  = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->cache() );

		// The pair's creation lock, as PostTranslationWrites::create_translation() and CreationMark::recover_pair() name it.
		$out = \PerfLocale\Concurrency\Lock::with(
			'create_xlat_post_' . $source_id . '_' . $lang,
			60,
			function () use ( $source_id, $lang_id, $translated_id, $groups, $rights, $write ): array {
				// The post row from the database too: rights and the write must not use this request's cached copy.
				wp_cache_delete( $translated_id, 'posts' );

				// From the table, right before the write: only Create takes this lock, other relinks none.
				$link = $lang_id > 0 ? $groups->find_translation_link_uncached( $source_id, \PerfLocale\Enum\ObjectType::Post, $lang_id ) : false;

				if ( false === $link ) {
					return [ $this->error( 'translation_unreadable', __( 'The translation link could not be read, so nothing was changed. Please retry.', 'perflocale' ), 503 ), null ];
				}

				if ( null === $link || $link['object_id'] !== $translated_id || ! get_post( $translated_id ) instanceof \WP_Post ) {
					return [ $this->error( 'translation_moved', __( 'This translation was relinked or removed meanwhile, so nothing was changed. Reload and try again.', 'perflocale' ), 409 ), null ];
				}

				$refused = $rights();

				return null !== $refused ? [ $refused, null ] : [ null, $write() ];
			}
		);

		if ( ! is_array( $out ) ) {
			return [ $this->error( 'translation_busy', __( 'Another change to this translation is in progress. Please retry.', 'perflocale' ), 409 ), null ];
		}

		[ $refused, $result ] = $out + [ null, null ];

		return [ $refused instanceof \WP_Error ? $refused : null, $result ];
	}

	/**
	 * The refusal of a status change on a translation, or null: its own capabilities, as on wp/v2/posts.
	 *
	 * @param int    $translated_id    Translation post.
	 * @param string $requested_status Registered status.
	 * @param object $status_obj       Its status object.
	 * @return \WP_Error|null
	 */
	private function status_refusal( int $translated_id, string $requested_status, object $status_obj ): ?\WP_Error {
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

		return null;
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
