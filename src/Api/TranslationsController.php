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
 * REST API endpoints for translation management.
 *
 * GET /perflocale/v1/translations/{type}/{id} - Get translations for an object.
 * POST /perflocale/v1/translations/{type}/{id} - Create a translation.
 * PUT /perflocale/v1/translations/{type}/{id}/{lang} - Update a translation.
 */
final class TranslationsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'translations';

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
					// target_lang sanitize mirrors create_translation()'s
					// sanitize_key. NOT marked required: the handler returns a
					// specific 'missing_lang' error we must preserve.
					// copy_content is left undeclared — the handler uses a (bool)
					// cast, which differs from REST boolean coercion for values
					// like '0'.
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
					// All fields optional (partial update). title/excerpt/status
					// sanitize_callbacks mirror update_translation() exactly.
					// `content` is documentation-only WITH NO sanitize_callback:
					// the handler runs it through wp_kses_post (rich HTML), so a
					// REST-layer sanitize_text_field would strip every tag before
					// the handler ever saw it.
					'args'                => [
						'title'   => [
							'type'              => 'string',
							'description'       => __( 'Translated post title.', 'perflocale' ),
							'sanitize_callback' => 'sanitize_text_field',
						],
						'content' => [
							'type'        => 'string',
							'description' => __( 'Translated post content (HTML allowed; sanitised with wp_kses_post by the handler).', 'perflocale' ),
						],
						'excerpt' => [
							'type'              => 'string',
							'description'       => __( 'Translated post excerpt.', 'perflocale' ),
							'sanitize_callback' => 'sanitize_textarea_field',
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

		// Assign a language to an existing, currently-unassigned post.
		// Rejected if the post already has a language (to prevent accidental
		// re-parenting of an existing translation group).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<type>[a-z]+)/(?P<id>\d+)/language',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'assign_language' ],
					'permission_callback' => [ $this, 'object_permissions_check' ],
					// slug sanitize mirrors assign_language()'s sanitize_key.
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

		// Bulk MT: REST parity with the Translations-page admin bulk action.
		// Dispatches the SAME BulkTranslateJob shape (skip-existing per pair),
		// with the pre-dispatch budget gate applied by the Dispatcher filter.
		// estimate_only=true returns the CostEstimator envelope without
		// dispatching anything.
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
				],
			]
		);

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
	 * POST /translations/bulk-translate — subset or site-wide MT dispatch.
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
				// Site-wide estimate: resolve the selection's IDs in bounded
				// keyset pages so the estimate never materialises a giant array.
				global $wpdb;
				$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $request->get_param( 'post_types' ) ?: [ 'post', 'page' ] ) ) ) );
				// Same public + show_ui whitelist (minus attachment) the admin
				// site-translate panel enforces — parity + defense in depth.
				$allowed_types = array_diff( array_keys( get_post_types( [ 'public' => true, 'show_ui' => true ], 'names' ) ), [ 'attachment' ] );
				$post_types    = array_values( array_intersect( $post_types, $allowed_types ) );

					// All requested types filtered out: an empty IN () list is a
					// MySQL syntax error, so bail rather than run it and return a
					// bogus zero-cost estimate.
					if ( $post_types === [] ) {
						return $this->error( 'invalid_params', __( 'No valid post types supplied.', 'perflocale' ), 400 );
					}

					$tph        = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
				$after      = 0;
				$totals     = [ 'chars' => 0, 'items' => 0, 'skipped_existing' => 0 ];
				do {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$page = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$tph}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 2000", array_merge( $post_types, [ $after ] ) ) ) );
					// phpcs:enable
					if ( $page === [] ) {
						break;
					}
					$after = (int) end( $page );
					$est   = $estimator->estimate_posts( $page, $lang_ids, $include_meta );
					$totals['chars']            += (int) $est['chars'];
					$totals['items']            += (int) $est['items'];
					$totals['skipped_existing'] += (int) $est['skipped_existing'];
				} while ( true );

				return $this->success( $estimator->summarize( $totals['chars'], $totals['items'], $totals['skipped_existing'] ) );
			}

			// The estimate exposes CHAR_LENGTH aggregates of the named posts;
			// require read access to each, mirroring
			// MachineTranslateController::estimate().
			foreach ( $post_ids as $pid ) {
				if ( $pid > 0 && ! current_user_can( 'read_post', $pid ) ) {
					return $this->error( 'rest_forbidden', __( 'You cannot access one of the requested posts.', 'perflocale' ), 403 );
				}
			}

			return $this->success( $estimator->estimate_posts( $post_ids, $lang_ids, $include_meta ) );
		}

		if ( $site_wide ) {
			$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $request->get_param( 'post_types' ) ?: [ 'post', 'page' ] ) ) ) );
				// Same public + show_ui whitelist (minus attachment) the admin
				// site-translate panel enforces — parity + defense in depth.
				$allowed_types = array_diff( array_keys( get_post_types( [ 'public' => true, 'show_ui' => true ], 'names' ) ), [ 'attachment' ] );
				$post_types    = array_values( array_intersect( $post_types, $allowed_types ) );

			// Reject at the REST layer instead of dispatching a no-op job the
			// worker would internally discard.
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
	 * Object-level permission gate for /translations/<type>/<id>...
	 *
	 * Runs the broad `perflocale_translate` cap check (so a non-translator
	 * gets a 403 before the handler runs), then adds the per-object check
	 * for the source post / term named in the route. This puts the
	 * per-object decision at the routing layer rather than relying solely
	 * on the in-handler check.
	 *
	 * Per-object semantics:
	 *   - type=post  → user must have edit_post($id)
	 *   - type=term  → user must have edit_term($id)
	 *   - type=string→ cap-only; strings have no underlying WP object
	 *
	 * The handler ALSO checks (defense in depth + keeps direct PHP callers
	 * gated) and may apply a stricter check on the *translation* post
	 * (e.g. delete_post on the translation, not the source). That stays.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function object_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->translate_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		// Path segments only — get_param() prefers GET over URL params, so a
		// stray query arg could point the capability check at a DIFFERENT
		// object than the one the handler will act on.
		$type = sanitize_key( $request->get_url_params()['type'] ?? '' );
		$id   = absint( $request->get_url_params()['id'] ?? 0 );

		// Whitelist; handlers reject unknown types with 400, so we mirror
		// that here. Keeps the rejection codes consistent across layers.
		if ( ! in_array( $type, [ 'post', 'term', 'string' ], true ) ) {
			return new \WP_Error(
				'invalid_type',
				__( 'Invalid object type.', 'perflocale' ),
				[ 'status' => 400 ]
			);
		}

		if ( $type === 'post' && ! current_user_can( 'edit_post', $id ) ) {
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

		// type=string: the cap check above is sufficient — strings have
		// no per-object owner; `perflocale_translate` is the gate.
		return true;
	}

	/**
	 * Get all translations for an object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_translations( \WP_REST_Request $request ): \WP_REST_Response {
		// Path segments only — must match what object_permissions_check saw.
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

		// This endpoint serves only the POST translation graph: get_manager()
		// returns a PostTranslationManager and every field below (get_post,
		// edit_url) is post-shaped. A term or string
		// ID would be read through the POST link map, returning a different
		// object's graph under a weaker gate than the type=post edit_post
		// check (edit_term for terms, cap-only for strings). Reject the
		// unsupported types rather than leak post data through them.
		if ( $type !== 'post' ) {
			return new \WP_REST_Response(
				[
					'code'    => 'unsupported_type',
					'message' => __( 'Only post translations can be listed here.', 'perflocale' ),
				],
				400
			);
		}

		// Verify the user can access this specific object.
		if ( ! current_user_can( 'edit_post', $id ) ) {
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

		// Batch-prime the WP post cache for all translation IDs in one query.
		// This eliminates N per-language get_post() queries. The source $id
		// is always appended below, so the array is guaranteed non-empty by
		// the time we prime — no empty() guard needed.
		$all_translation_ids = array_filter( array_map( 'intval', array_values( $translations ) ) );

		if ( ! in_array( $id, $all_translation_ids, true ) ) {
			$all_translation_ids[] = $id;
		}

		_prime_post_caches( $all_translation_ids, true, true );

		// Pre-compute translation statuses outside the loop (avoids duplicate calls).
		$status_cache = [];

		foreach ( $languages as $lang ) {
			if ( isset( $translations[ $lang->slug ] ) ) {
				$status_cache[ $lang->slug ] = $manager->get_translation_status( $id, $lang->slug );
			}
		}

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $has_translation ? $translations[ $lang->slug ] : null;
			$translated_post = $translated_id ? get_post( $translated_id ) : null; // Hits WP cache (primed above).

			// Skip stale links to deleted posts.
			if ( $has_translation && ! $translated_post ) {
				$has_translation = false;
				$translated_id   = null;
			}

			$is_current = ( $post_lang && $post_lang->slug === $lang->slug );
			$is_default = ( $default && $lang->slug === $default->slug );

			// For the current post, ensure post_id is set even if not in translations map.
			if ( $is_current && ! $translated_id ) {
				$translated_id   = $id;
				$translated_post = get_post( $id );
				$has_translation = true;
			}


			// Use pre-computed status (avoids duplicate DB lookups).
			$status_obj = $status_cache[ $lang->slug ] ?? null;

			$result[] = [
				'slug'            => $lang->slug,
				// Canonical BCP 47 form, used by editor-sidebar.js for
				// visible labels (badges, "Set as …" buttons) without
				// re-uppercasing the lowercase URL slug.
				'bcp47'           => \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->slug ),
				'language_id'     => (int) $lang->id,
				'name'            => $lang->name,
				'native_name'     => $lang->native_name ?? $lang->name,
				'is_current'      => $is_current,
				'is_default'      => $is_default,
				'has_translation' => $has_translation,
				'post_id'         => $translated_id,
				'status'          => $status_obj ? $status_obj->value : null,
				'status_label'    => $status_obj ? $status_obj->label() : null,
				'edit_url'        => $translated_post ? get_edit_post_link( $translated_id, 'raw' ) : null,
			];
		}

		return $this->success(
			[
				'languages'     => $result,
				// True when this post isn't attached to any language yet.
				// The sidebar uses this to offer "Set as <lang>" buttons
				// instead of "+ Create" (which would mint a new translation
				// under an unassigned source, which is meaningless).
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
		// `id` is a path segment (must match object_permissions_check);
		// target_lang / copy_content are legitimately body params.
		$id          = absint( $request->get_url_params()['id'] ?? 0 );
		$target_lang = sanitize_key( $request->get_param( 'target_lang' ) ?? '' );
		$copy        = (bool) $request->get_param( 'copy_content' );

		// Verify the user can edit the source post.
		if ( ! current_user_can( 'edit_post', $id ) ) {
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
		$new_id  = $manager->create_translation( $id, $target_lang, $copy );

		if ( $new_id === false ) {
			return $this->error( 'create_failed', __( 'Failed to create translation.', 'perflocale' ), 500 );
		}

		return $this->success(
			[
				'post_id'  => $new_id,
				'edit_url' => get_edit_post_link( $new_id, 'raw' ),
			],
			201
		);
	}

	/**
	 * Assign a language to a post that currently has no language set.
	 *
	 * This fixes the case where posts created before PerfLocale was
	 * activated (or through flows that bypass language assignment) get
	 * stuck without a language - they don't render on list pages and
	 * can't be translated because the plugin has no anchor for them.
	 *
	 * Rejected if the post already has a language to avoid accidentally
	 * moving it out of an established translation group.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function assign_language( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// type/id are path segments (must match object_permissions_check);
		// slug is legitimately a body param.
		$type = sanitize_key( $request->get_url_params()['type'] ?? '' );
		$id   = absint( $request->get_url_params()['id'] ?? 0 );
		$slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );

		if ( $type !== 'post' ) {
			return $this->error( 'invalid_type', __( 'Only posts support direct language assignment.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
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

		// Existence check goes through the repository (DB-backed) rather
		// than detect_post_language() which memoizes per-request. In
		// long-running processes or test harnesses the memo can still
		// return null after a successful assignment, which would let a
		// second concurrent call silently overwrite the first.
		$group_repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );

		// Serialize check-then-assign per post: without the lock, two
		// concurrent requests can both pass the find_for_object() check and
		// both call set_post_language(), the second silently overwriting the
		// first (TOCTOU). The lock makes the second see the first's row and
		// return 409 instead.
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

		// Lock unavailable (a concurrent assignment is mid-flight) → 409.
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
		// Read the path segments explicitly: get_param() prefers GET over URL
		// params, and `lang` is the plugin's own public routing query var — a
		// stray ?lang= on the request must not shadow the route and mutate a
		// different language's translation.
		$id   = absint( $request->get_url_params()['id'] ?? 0 );
		$lang = sanitize_key( $request->get_url_params()['lang'] ?? '' );

		$manager       = $this->get_manager();
		$translated_id = $manager->get_translation_id( $id, $lang );

		if ( ! $translated_id ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
		}

		// Verify the user can edit this specific translation post.
		if ( ! current_user_can( 'edit_post', $translated_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this translation.', 'perflocale' ), 403 );
		}

		$update_data = [];

		if ( $request->has_param( 'title' ) ) {
			$update_data['post_title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}

		if ( $request->has_param( 'content' ) ) {
			$update_data['post_content'] = wp_kses_post( $request->get_param( 'content' ) );
		}

		if ( $request->has_param( 'excerpt' ) ) {
			$update_data['post_excerpt'] = sanitize_textarea_field( $request->get_param( 'excerpt' ) );
		}

		if ( $request->has_param( 'status' ) ) {
			// Only accept a registered post status; an arbitrary sanitize_key
			// string (e.g. "bogus") would write a status no query matches,
			// orphaning the translation from the admin list and the front end.
			$requested_status = sanitize_key( $request->get_param( 'status' ) );
			$status_obj       = get_post_status_object( $requested_status );

			// An UNREGISTERED status is ignored, not rejected — the other
			// fields in the same request still apply. That leniency is the
			// documented contract and is pinned by cov-api-abilities I5/I6,
			// which sends status=bogus alongside a title and content and
			// requires both to land. Turning it into a 400 is an announced API
			// change, not part of this security fix, so it is deliberately not
			// made here. Nothing is written for an unknown status, so no
			// capability question arises for it.
			//
			// is_object(), not instanceof stdClass: core builds these with a
			// cast today, and a future WP_Post_Status class must not silently
			// disable the capability gates below.
			if ( is_object( $status_obj ) ) {
				// A STATUS TRANSITION IS NOT AN EDIT. Reaching this method proves
				// `perflocale_translate` plus `edit_post` on the target, and until
				// now that was the only gate — so a role holding edit rights but
				// deliberately denied publish/delete rights (the bundled Translator
				// role is exactly that) could publish or trash content through
				// here. Core refuses the same transition on wp/v2/posts.
				//
				// Worse, the target is not always a translation: for the DEFAULT
				// language PostTranslationManager::get_translation_id() resolves a
				// post to ITSELF, so the source post was reachable too.
				//
				// Mirror WP_REST_Posts_Controller::handle_status_param(): map the
				// capability from the TARGET's own post type so custom post types
				// with a custom capability_type are gated by their own caps, not
				// by hard-coded 'post' names.
				$target_type = get_post_type_object( (string) get_post_type( $translated_id ) );

				if ( ! $target_type instanceof \WP_Post_Type ) {
					return $this->error(
						'invalid_status',
						__( 'Unknown post type for this translation.', 'perflocale' ),
						400
					);
				}

				// Trash is a deletion. Use the same gate delete_translation() uses,
				// so the two routes cannot disagree about who may remove content.
				if ( 'trash' === $requested_status && ! current_user_can( 'delete_post', $translated_id ) ) {
					return $this->error(
						'rest_forbidden',
						__( 'You cannot trash this translation.', 'perflocale' ),
						403
					);
				}

				// THE POLICY THIS ENDPOINT IMPLEMENTS: it sets EDITORIAL statuses
				// only. Editorial means anything a person picks in a status
				// dropdown — draft, pending, a workflow plugin's "in review", a
				// custom public or private state. It does NOT mean the statuses
				// core registers with `internal => true`, which are lifecycle
				// bookkeeping and are never a legitimate target here.
				//
				// Denying them is not tidiness. `auto-draft` is COLLECTED BY
				// CORE: wp_delete_auto_drafts(), on the daily
				// wp_scheduled_auto_draft_delete cron, runs
				// `wp_delete_post( $id, true )` — a FORCE delete, no trash, no
				// undo — over every auto-draft whose POST_DATE is more than
				// seven days old. post_date, not post_modified. An article
				// published last month already satisfies that, so setting it to
				// auto-draft does not buy a week's grace: core destroys it on
				// the next cron run.
				//
				// So a role holding edit rights but deliberately denied delete
				// rights — the bundled Translator role is exactly that — could
				// set a translation, or via get_translation_id() a
				// DEFAULT-language SOURCE post, to auto-draft and have core
				// permanently delete it within a day. The trash gate above
				// cannot see that coming, because auto-draft is not trash.
				//
				// `inherit` is the other one: it makes the post take its
				// parent's status, and on a post with no parent the result is
				// content that editorial queries no longer return.
				//
				// `trash` is `internal` too, but it is a real editorial action
				// and is gated on delete_post immediately above, so it is
				// exempt here rather than double-handled.
				if ( ! empty( $status_obj->internal ) && 'trash' !== $requested_status ) {
					return $this->error(
						'rest_forbidden',
						__( 'That status cannot be set through this endpoint.', 'perflocale' ),
						403
					);
				}

				// Anything publicly visible, privately published, or scheduled is a
				// publish action. `public` also covers custom statuses registered by
				// other plugins, so this needs no allowlist. `draft` and `pending`
				// are deliberately NOT gated here — drafting is the translator's job.
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
			// wp_slash: wp_update_post() unslashes internally, and REST params arrive
			// UNSLASHED — without this, every backslash in the submitted content
			// (Windows paths, LaTeX, code snippets) is silently stripped.
			$result            = wp_update_post( wp_slash( $update_data ), true );

			if ( is_wp_error( $result ) ) {
				// A refusal caused by the CLIENT'S OWN status value is a 4xx, not
				// a 5xx. The gate above admits any registered, non-internal
				// editorial status — including the protected workflow states other
				// plugins register, which is deliberate — but WordPress itself can
				// still refuse one (a status hidden from both admin lists is the
				// case that surfaced this). Reporting the server as broken for a
				// value the caller chose sends clients into retry loops and buries
				// real 500s in the log. Nothing was written: the post keeps its
				// previous status either way.
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
	 * Delete a translation link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_translation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Path segments only — see update_translation(): a stray ?lang= must
		// never redirect a force-delete onto a different language's post.
		$id   = absint( $request->get_url_params()['id'] ?? 0 );
		$lang = sanitize_key( $request->get_url_params()['lang'] ?? '' );

		$manager       = $this->get_manager();
		$translated_id = $manager->get_translation_id( $id, $lang );

		if ( ! $translated_id ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'perflocale' ), 404 );
		}

		// Verify the user can delete this specific translation post.
		if ( ! current_user_can( 'delete_post', $translated_id ) ) {
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
