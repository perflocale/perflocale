<?php
/**
 * Machine translation REST API controller.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Concurrency\Lock;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\MachineTranslation\CostEstimator;
use PerfLocale\Translation\MtRateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoint for triggering machine translation.
 *
 * POST /perflocale/v1/machine-translate
 */
final class MachineTranslateController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/machine-translate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'translate' ],
				'permission_callback' => [ $this, 'translate_post_permissions_check' ],
				// Documentation + defense-in-depth schema. Each sanitize_callback
				// mirrors EXACTLY what translate() already applies (absint /
				// sanitize_key), so behaviour is unchanged for every accepted
				// request; the handler keeps its own sanitisation as well.
				// Params are intentionally NOT marked required: the per-post
				// permission gate already rejects a missing/invalid post_id,
				// and preserving that path keeps the existing error response.
				'args'                => [
					'post_id'     => [
						'type'              => 'integer',
						'description'       => __( 'ID of the source post to machine-translate.', 'perflocale' ),
						'sanitize_callback' => 'absint',
					],
					'target_lang' => [
						'type'              => 'string',
						'description'       => __( 'Target language slug.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
					'provider'    => [
						'type'              => 'string',
						'description'       => __( 'Machine-translation provider id; defaults to the configured provider when omitted.', 'perflocale' ),
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		// Pre-dispatch cost estimate: read-only aggregate (no provider spend,
		// no writes). POST because the payload carries ID arrays / a filter
		// object that don't fit query strings. Powers the confirm dialogs and
		// lets clients avoid enqueueing a job the budget gate would veto.
		register_rest_route(
			$this->namespace,
			'/machine-translate/estimate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'estimate' ],
				'permission_callback' => [ $this, 'mt_permissions_check' ],
				'args'                => [
					'kind'            => [
						'type'              => 'string',
						'description'       => __( 'What to estimate: posts or strings.', 'perflocale' ),
						'enum'              => [ 'posts', 'strings' ],
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
					// Array params intentionally undeclared beyond docs — the
					// handler applies its own (array)+intval normalization, and
					// a strict type would reject scalars the cast accepts.
					'post_ids'        => [
						'description' => __( 'Source post IDs (kind=posts).', 'perflocale' ),
					],
					'string_ids'      => [
						'description' => __( 'String row IDs (kind=strings).', 'perflocale' ),
					],
					'target_lang_ids' => [
						'description' => __( 'Target language IDs.', 'perflocale' ),
					],
					'target_langs'    => [
						'description' => __( 'Target language slugs (alternative to IDs).', 'perflocale' ),
					],
					'include_meta'    => [
						'description' => __( 'Include MT-able meta characters (kind=posts).', 'perflocale' ),
					],
				],
			]
		);

		// Scope-orchestration endpoint: translate ONE object (post or term)
		// including registered meta — the Content scope of the visual editor's
		// "Translate page" flow. Skip-existing by default: an existing
		// translation returns status=exists and the client must explicitly
		// re-call with overwrite=true (translate_post OVERWRITES — the caller
		// owns that decision, never this endpoint silently).
		register_rest_route(
			$this->namespace,
			'/machine-translate/object',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'translate_object' ],
				'permission_callback' => [ $this, 'translate_object_permissions_check' ],
				'args'                => [
					'object_type' => [
						'type'              => 'string',
						'enum'              => [ 'post', 'term' ],
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'object_id'   => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'target_lang' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'overwrite'   => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Overwrite an existing translation (posts only; default returns status=exists instead).', 'perflocale' ),
					],
					'provider'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Per-object permission gate for /machine-translate/object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function translate_object_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->mt_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$id   = absint( $request->get_param( 'object_id' ) );

		if ( $id <= 0 ) {
			return true; // handler returns the descriptive 400.
		}

		if ( $type === 'post' && ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You cannot translate this post.', 'perflocale' ), [ 'status' => 403 ] );
		}

		if ( $type === 'term' ) {
			$term = get_term( $id );
			if ( $term instanceof \WP_Term && ! current_user_can( 'edit_term', $id ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You cannot translate this term.', 'perflocale' ), [ 'status' => 403 ] );
			}
		}

		return true;
	}

	/**
	 * POST /machine-translate/object — translate one post or term (Content scope).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function translate_object( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type        = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$id          = absint( $request->get_param( 'object_id' ) );
		$target_lang = sanitize_key( (string) $request->get_param( 'target_lang' ) );
		$overwrite   = (bool) $request->get_param( 'overwrite' );
		$provider_id = sanitize_key( (string) ( $request->get_param( 'provider' ) ?? '' ) );

		if ( $id <= 0 || $target_lang === '' ) {
			return $this->error( 'invalid_params', __( 'object_id and target_lang are required.', 'perflocale' ) );
		}

		$limited = $this->enforce_rate_limit( get_current_user_id() );
		if ( $limited instanceof \WP_Error ) {
			return $limited;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->get( 'settings' )->mt_enabled() ) {
			return $this->error( 'mt_disabled', __( 'Machine translation is disabled.', 'perflocale' ), 403 );
		}

		$settings = $plugin->get( 'settings' );
		$cache    = $plugin->get( 'cache' );
		$service  = new TranslationService( $settings, $cache );

		// Validate the target language BEFORE any provider spend: the post
		// pipeline would otherwise burn a provider call and then fail inside
		// create_translation with a misleading error.
		$lang_repo   = $plugin->get( 'lang_repo' );
		$target_row  = $lang_repo->find_by_slug( $target_lang );
		$default_row = $lang_repo->get_default();

		if ( ! $target_row ) {
			return $this->error( 'unknown_language', __( 'Unknown target language.', 'perflocale' ), 400 );
		}

		if ( $default_row && $target_row->slug === $default_row->slug ) {
			return $this->error( 'same_language', __( 'The target language is the source language.', 'perflocale' ), 400 );
		}

		if ( $type === 'post' ) {
			$ptm = new \PerfLocale\Translation\PostTranslationManager( $cache, $settings );

			// The visual editor calls this from ANY language's page, so the
			// queried object may be a translation SIBLING. translate_post()
			// always treats its input as default-language text — feeding it a
			// sibling would send e.g. German text labeled as English (wrong-
			// direction garbage). Resolve to the default-language sibling; a
			// post with no default-language row translates as-is (unlinked
			// posts are their own source).
			if ( $default_row ) {
				$default_sibling = $ptm->get_translation_id( $id, $default_row->slug );

				if ( $default_sibling && (int) $default_sibling !== $id ) {
					$id = (int) $default_sibling;

					if ( ! current_user_can( 'edit_post', $id ) ) {
						return $this->error( 'rest_forbidden', __( 'You cannot translate this post.', 'perflocale' ), 403 );
					}
				}
			}

			$existing = $ptm->get_translation_id( $id, $target_lang );

			if ( $existing && ! $overwrite ) {
				return $this->success(
					[
						'status'         => 'exists',
						'translation_id' => (int) $existing,
					]
				);
			}

			// Overwrite guard: translate_post() rewrites the existing
			// translation in place; edit_post on the source is not authority
			// to overwrite a translation post the caller can't edit.
			if ( $existing && ! current_user_can( 'edit_post', (int) $existing ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot overwrite this translation.', 'perflocale' ), 403 );
			}

			try {
				// fast_fail: this is an interactive UI call — better a quick
				// retryable error than a minutes-long retry loop.
				$result = $service->translate_post( $id, $target_lang, $provider_id, true, true );
			} catch ( \Throwable $e ) {
				return $this->error( 'mt_failed', $e->getMessage(), 500 );
			}

			return $this->success(
				[
					'status'         => 'translated',
					'translation_id' => (int) ( $result['post_id'] ?? 0 ),
					'meta'           => $result['meta'] ?? null,
				],
				201
			);
		}

		// term
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			return $this->error( 'not_found', __( 'Term not found.', 'perflocale' ), 404 );
		}

		// Only UI-visible taxonomies: without this, nav_menu terms were
		// translatable and a machine-translated MENU clone appeared under
		// Appearance → Menus.
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy || ! $taxonomy->show_ui ) {
			return $this->error( 'invalid_taxonomy', __( 'This taxonomy cannot be translated here.', 'perflocale' ), 400 );
		}

		$ttm = new \PerfLocale\Translation\TermTranslationManager( $cache );

		// Same sibling-resolution as the post path: the VE calls from any
		// language's term archive, so the queried term may be a translation
		// sibling. Resolve to the default-language term before reading
		// name/description, or a non-default term would be sent to the
		// provider labeled as the source language (wrong-direction garbage).
		if ( $default_row ) {
			$default_sibling = $ttm->get_translation_id( $id, $default_row->slug );

			if ( $default_sibling && (int) $default_sibling !== $id ) {
				$id = (int) $default_sibling;

				if ( ! current_user_can( 'edit_term', $id ) ) {
					return $this->error( 'rest_forbidden', __( 'You cannot translate this term.', 'perflocale' ), 403 );
				}

				// Re-fetch the resolved source term (name/description/taxonomy)
				// and re-run the taxonomy gate on it.
				$term = get_term( $id );
				if ( ! $term instanceof \WP_Term ) {
					return $this->error( 'not_found', __( 'Term not found.', 'perflocale' ), 404 );
				}

				$taxonomy = get_taxonomy( $term->taxonomy );
				if ( ! $taxonomy || ! $taxonomy->show_ui ) {
					return $this->error( 'invalid_taxonomy', __( 'This taxonomy cannot be translated here.', 'perflocale' ), 400 );
				}
			}
		}

		$sibling = $ttm->get_translation_id( $id, $target_lang );

		if ( $sibling && ! $overwrite ) {
			return $this->success(
				[
					'status'         => 'exists',
					'translation_id' => (int) $sibling,
				]
			);
		}

		// Overwrite guard: wp_update_term below rewrites the sibling's
		// name/description; edit_term on the source is not authority to
		// overwrite a translation term the caller can't edit.
		if ( $sibling && ! current_user_can( 'edit_term', (int) $sibling ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot overwrite this translation.', 'perflocale' ), 403 );
		}

		// Translate BEFORE creating the sibling (the post path's deliberate
		// ordering). Terms have no draft state — a sibling created first
		// would go live with source-language text, and on provider failure
		// it would persist; the default overwrite=false retry would then
		// report 'exists' without ever translating it.
		try {
			$default     = $plugin->get( 'lang_repo' )->get_default();
			$source_lang = $default ? $default->slug : 'en';
			$texts       = [ (string) $term->name, (string) $term->description ];
			$translated  = $service->translate_batch_texts( $texts, $source_lang, $target_lang, $provider_id, true );
		} catch ( \Throwable $e ) {
			return $this->error( 'mt_failed', $e->getMessage(), 500 );
		}

		if ( ! $sibling ) {
			$created = $ttm->create_translation( $id, $term->taxonomy, $target_lang, true, \PerfLocale\Enum\SourceType::MachineTranslation );
			if ( ! $created ) {
				return $this->error( 'create_failed', __( 'Failed to create the term translation.', 'perflocale' ), 500 );
			}
			$sibling = (int) $created;
		}

		$update = [];
		if ( ! empty( $translated[0] ) ) {
			$update['name'] = wp_slash( (string) $translated[0] );
		}
		if ( ! empty( $translated[1] ) ) {
			$update['description'] = wp_slash( (string) $translated[1] );
		}

		if ( $update !== [] ) {
			// Never touches the DB slug: the '-{lang}' suffixed slug + the
			// separate display-slug (SlugManager) stay as created.
			$updated = wp_update_term( $sibling, $term->taxonomy, $update );
			if ( is_wp_error( $updated ) ) {
				return $this->error( 'term_update_failed', $updated->get_error_message(), 500 );
			}
		}

		return $this->success(
			[
				'status'         => 'translated',
				'translation_id' => (int) $sibling,
			],
			201
		);
	}

	/**
	 * POST /machine-translate/estimate — pre-dispatch character/cost estimate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function estimate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );

		$estimator = new CostEstimator( \PerfLocale\Plugin::get_instance()->get( 'settings' ) );

		$target_lang_ids = array_map( 'intval', (array) $request->get_param( 'target_lang_ids' ) );

		// Slug convenience: clients that only know language slugs (the visual
		// editor) may pass target_langs instead of numeric IDs.
		foreach ( (array) $request->get_param( 'target_langs' ) as $slug ) {
			$lang = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' )->find_by_slug( sanitize_key( (string) $slug ) );
			if ( $lang ) {
				$target_lang_ids[] = (int) $lang->id;
			}
		}

		if ( $kind === 'posts' ) {
			$post_ids = array_map( 'intval', (array) $request->get_param( 'post_ids' ) );

			// The estimate leaks CHAR_LENGTH aggregates of the named posts, so
			// require read access to every named source (mirrors the job's own
			// per-row edit checks without being as strict — this is read-only).
			foreach ( $post_ids as $pid ) {
				if ( $pid > 0 && ! current_user_can( 'read_post', $pid ) ) {
					return $this->error( 'rest_forbidden', __( 'You cannot access one of the requested posts.', 'perflocale' ), 403 );
				}
			}

			return $this->success(
				$estimator->estimate_posts( $post_ids, $target_lang_ids, (bool) $request->get_param( 'include_meta' ) )
			);
		}

		$string_ids = array_map( 'intval', (array) $request->get_param( 'string_ids' ) );

		return $this->success( $estimator->estimate_strings( $string_ids, $target_lang_ids ) );
	}

	/**
	 * Per-post permission gate. Runs the MT cap check first, then verifies
	 * the caller can edit the specific `post_id` they're trying to
	 * translate. Hoists the in-handler check at translate() line 54 up
	 * to the routing layer so the request is rejected before the handler
	 * runs.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function translate_post_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->mt_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 ) {
			// Handler will return 400 invalid_params for missing IDs; keep
			// the cap-only response here so the routing layer doesn't pre-empt
			// the more descriptive validation error.
			return true;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You cannot translate this post.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Translate a post via machine translation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function translate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id     = absint( $request->get_param( 'post_id' ) );
		$target_lang = sanitize_key( $request->get_param( 'target_lang' ) ?? '' );
		$provider_id = sanitize_key( $request->get_param( 'provider' ) ?? '' );

		if ( $post_id === 0 || $target_lang === '' ) {
			return $this->error( 'invalid_params', __( 'post_id and target_lang are required.', 'perflocale' ) );
		}

		// Verify user can edit this specific post (not just any post).
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot translate this post.', 'perflocale' ), 403 );
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		// Overwrite guard: translate_post() rewrites an EXISTING target-language
		// translation in place, so edit_post on the source is not authority to
		// overwrite a translation post the caller can't edit — mirrors
		// PUT /translations/{type}/{id}/{lang}, which requires edit_post on the
		// translation before writing to it.
		$existing = ( new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) ) )
			->get_translation_id( $post_id, $target_lang );

		if ( $existing && (int) $existing !== $post_id && ! current_user_can( 'edit_post', (int) $existing ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot overwrite this translation.', 'perflocale' ), 403 );
		}

		// Budget guard - cap per-user MT requests per hour. A runaway UI
		// script or accidental loop across thousands of posts can exhaust
		// provider quota fast; the rate limit protects the site owner's
		// bill.
		$limited = $this->enforce_rate_limit( get_current_user_id() );

		if ( $limited instanceof \WP_Error ) {
			return $limited;
		}

		if ( ! $plugin->get( 'settings' )->mt_enabled() ) {
			return $this->error( 'mt_disabled', __( 'Machine translation is disabled.', 'perflocale' ), 403 );
		}

		$service = new TranslationService(
			$plugin->get( 'settings' ),
			$plugin->get( 'cache' )
		);

		try {
			$result = $service->translate_post( $post_id, $target_lang, $provider_id, true );

			return $this->success( $result );
		} catch ( \Throwable $e ) {
			return $this->error( 'translation_failed', $e->getMessage(), 500 );
		}
	}

	/**
	 * Sliding-window rate limit for per-user MT requests.
	 *
	 * Protects the site owner from runaway scripts or mis-clicks that
	 * would otherwise drain provider quota. Returns a WP_Error with
	 * HTTP 429 when the user has exceeded the window budget, or true
	 * (implicitly, no return) when the request may proceed.
	 *
	 * State is stored in a single transient per user. A window is 1
	 * hour; when the window expires, the count resets on the next call.
	 *
	 * @param int $user_id Current user ID.
	 * @return \WP_Error|null WP_Error on denial, null when allowed.
	 */
	private function enforce_rate_limit( int $user_id ): ?\WP_Error {
		// Delegates to the shared policy. This method used to hold the whole
		// implementation, and BlockTranslateController held a second copy;
		// the `perflocale/translate-post` Ability, added later, had no copy at
		// all and therefore no rate limit. Keeping a thin wrapper here
		// preserves this class's gate ordering and error codes exactly while
		// leaving exactly one implementation to change.
		return MtRateLimiter::admit( $user_id );
	}
}
