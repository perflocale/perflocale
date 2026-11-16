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
 * REST routes for machine translation: one post, one object (post or term), and the cost estimate.
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
				// Sanitizers mirror translate(). Not required: the handler answers a missing param with its own 400.
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

		// Read-only cost estimate (no provider call, no write); POST because the ID arrays do not fit a query string.
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
					// Array params have no type: type=array would reject a scalar the handler's (array) cast accepts.
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
					'overwrite'       => [
						'description' => __( 'Count strings that already have a translation too, as an Overwrite run sends them (kind=strings).', 'perflocale' ),
					],
				],
			]
		);

		// One post or term with its meta (front-end Content scope). An existing translation answers status=exists
		// unless the caller sends overwrite=true: overwriting is never this route's own decision.
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
	 * The MT capability plus perflocale_translate, as every other route that creates or overwrites a translation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	private function write_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->mt_permissions_check( $request );

		if ( is_wp_error( $base ) || current_user_can( 'perflocale_translate' ) ) {
			return $base;
		}

		return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to perform this action.', 'perflocale' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Per-object permission gate for /machine-translate/object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function translate_object_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->write_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$id   = absint( $request->get_param( 'object_id' ) );

		if ( $id <= 0 ) {
			return true; // The handler returns the descriptive 400.
		}

		if ( $type === 'post' && ! \PerfLocale\Util\ObjectPermission::edit_narrowed( $id ) ) {
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
	 * POST /machine-translate/object - translate one post or term (Content scope).
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

		// Before the rate limiter, so a refused request spends no quota.
		$denied = $this->creation_denial( $type === 'post' ? 'post' : 'term', $id, $target_lang );

		if ( $denied instanceof \WP_Error ) {
			return $denied;
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

		// Validated before any provider spend: the post path would otherwise pay for a call and fail later.
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

			// The page may be a sibling, and translate_post() reads its input as default-language text: use the
			// default-language sibling. A post without one is its own source.
			if ( $default_row ) {
				$default_sibling = $ptm->get_translation_id( $id, $default_row->slug );

				if ( $default_sibling && (int) $default_sibling !== $id ) {
					$id = (int) $default_sibling;

					if ( ! \PerfLocale\Util\ObjectPermission::edit_narrowed( $id ) ) {
						return \PerfLocale\Helper::user_can_view_object( $id )
							? $this->error( 'rest_forbidden', __( 'You cannot translate this post.', 'perflocale' ), 403 )
							: TranslationsController::source_unavailable( $target_lang );
					}
				}
			}

			// The post sent: edit and read on it, also into an existing translation.
			if ( ! \PerfLocale\Helper::user_can_copy_translation_source( $id ) ) {
				return TranslationsController::source_refusal( $id, $target_lang );
			}

			$existing = $ptm->get_translation_id( $id, $target_lang );

			// Again at the read the write uses: the group may have been relinked since creation_denial().
			if ( TranslationsController::hidden_translation( $id, $existing ) ) {
				return TranslationsController::unavailable( $target_lang );
			}

			if ( $existing && ! $overwrite ) {
				return $this->success( self::exists_response( (int) $existing ) );
			}

			// Overwriting rewrites the translation in place: it needs edit_post on the translation, not only the source.
			if ( $existing && ! \PerfLocale\Util\ObjectPermission::edit_narrowed( (int) $existing ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot overwrite this translation.', 'perflocale' ), 403 );
			}

			// A password-protected post is sent unless the perflocale/mt/send_password_protected filter refuses it.
			$source = get_post( $id );

			if ( $source instanceof \WP_Post && ! TranslationService::may_send_post( $source, 'rest' ) ) {
				return $this->error( 'password_protected', TranslationService::password_protected_skip_message(), 403 );
			}

			try {
				// Fast fail: an interactive call gets a quick retryable error, not a long retry loop.
				$meta   = TranslationService::include_meta( true, 'rest', $id, $target_lang );
				$result = self::translate_or_queue( $service, $provider_id, static fn(): array => $service->translate_post( $id, $target_lang, $provider_id, true, $meta ) );
			} catch ( \Throwable $e ) {
				return $this->error( 'mt_failed', $e->getMessage(), \PerfLocale\MachineTranslation\AbstractProvider::rest_status( $e ) );
			}

			if ( isset( $result['job_id'] ) ) {
				return $this->success( $result, 202 );
			}

			return $this->success(
				[
					'status'         => 'translated',
					'translation_id' => (int) ( $result['post_id'] ?? 0 ),
					'meta'           => $result['meta'] ?? null,
					'warnings'       => $result['warnings'] ?? [],
					'kept'           => $result['kept'] ?? [],
				],
				201
			);
		}

		// Term.
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			return $this->error( 'not_found', __( 'Term not found.', 'perflocale' ), 404 );
		}

		// UI-visible taxonomies only: a nav_menu term would clone a whole menu.
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy || ! $taxonomy->show_ui ) {
			return $this->error( 'invalid_taxonomy', __( 'This taxonomy cannot be translated here.', 'perflocale' ), 400 );
		}

		$ttm = new \PerfLocale\Translation\TermTranslationManager( $cache );

		// As for posts: send the default-language term's text, never a sibling's labeled as the source language.
		if ( $default_row ) {
			$default_sibling = $ttm->get_translation_id( $id, $default_row->slug );

			if ( $default_sibling && (int) $default_sibling !== $id ) {
				$id = (int) $default_sibling;

				// A term the caller may not edit is left out of its maps: refused naming none.
				if ( ! current_user_can( 'edit_term', $id ) ) {
					return TranslationsController::source_unavailable( $target_lang );
				}

				// Re-read the resolved source term and re-run the taxonomy gate on it.
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

		// Again at the read the write uses: the group may have been relinked since creation_denial().
		if ( $sibling && (int) $sibling !== $id && ! current_user_can( 'edit_term', (int) $sibling ) ) {
			return TranslationsController::unavailable( $target_lang );
		}

		if ( $sibling && ! $overwrite ) {
			return $this->success( self::term_exists_response( (int) $sibling ) );
		}

		// Translate before creating the sibling: a term has no draft, so a failed call would leave a live
		// untranslated sibling that a retry reports as 'exists'.
		try {
			$default     = $plugin->get( 'lang_repo' )->get_default();
			$source_lang = $default ? $default->slug : 'en';
			$translated  = $service->translate_term_fields(
				[
					'name'        => (string) $term->name,
					'description' => (string) $term->description,
				],
				$source_lang,
				$target_lang,
				$provider_id,
				true
			);
		} catch ( \Throwable $e ) {
			return $this->error( 'mt_failed', $e->getMessage(), \PerfLocale\MachineTranslation\AbstractProvider::rest_status( $e ) );
		}

		if ( ! $sibling ) {
			$inserted = false;
			$created  = $ttm->create_translation( $id, $term->taxonomy, $target_lang, true, \PerfLocale\Enum\SourceType::MachineTranslation, $inserted );
			if ( ! $created ) {
				return $this->error( 'create_failed', __( 'Failed to create the term translation.', 'perflocale' ), 500 );
			}
			$sibling = (int) $created;

			// Another request created the sibling while the provider ran, after
			// the checks above: apply them to it before writing.
			if ( ! $inserted ) {
				if ( ! current_user_can( 'edit_term', $sibling ) ) {
					return TranslationsController::unavailable( $target_lang );
				}

				if ( ! $overwrite ) {
					return $this->success( self::term_exists_response( (int) $sibling ) );
				}
			}
		}

		// The provider call may be slow: the link and rights are read again, uncached (another process may have changed
		// them), right before the write. No answer refuses.
		$now = $plugin->group_repo()->find_translation_link_uncached( $id, \PerfLocale\Enum\ObjectType::Term, \PerfLocale\Util\Scalar::int( \PerfLocale\Util\Scalar::prop( $target_row, 'id' ) ) );

		if ( false === $now ) {
			return $this->error( 'translation_busy', __( 'The translation could not be checked. Please retry.', 'perflocale' ), 503 );
		}

		if ( ! current_user_can( 'edit_term', $id ) ) {
			return TranslationsController::source_unavailable( $target_lang );
		}

		if ( ! current_user_can( 'edit_term', (int) $sibling ) ) {
			return TranslationsController::unavailable( $target_lang );
		}

		if ( null === $now || $now['object_id'] !== (int) $sibling ) {
			return $this->error( 'translation_changed', __( 'The translation changed while it was being translated. Please retry.', 'perflocale' ), 409 );
		}

		$update = [];
		// A blank field comes back as given: nothing to write.
		if ( '' !== trim( $translated['name'] ) ) {
			$update['name'] = wp_slash( $translated['name'] );
		}
		if ( '' !== trim( $translated['description'] ) ) {
			$update['description'] = wp_slash( $translated['description'] );
		}

		if ( $update !== [] ) {
			// The slug is not touched: the '-{lang}' slug and the display slug (SlugManager) stay as created.
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
	 * The skip-existing response for a post the caller may view (creation_denial() refused a hidden one).
	 *
	 * @param int $existing Linked translation.
	 * @return array{status: string, translation_id: int}
	 */
	private static function exists_response( int $existing ): array {
		return [
			'status'         => 'exists',
			'translation_id' => $existing,
		];
	}

	/**
	 * The skip-existing response for a term the caller may edit (creation_denial() refused another).
	 *
	 * @param int $sibling Linked translation term.
	 * @return array{status: string, translation_id: int}
	 */
	private static function term_exists_response( int $sibling ): array {
		return [
			'status'         => 'exists',
			'translation_id' => $sibling,
		];
	}

	/**
	 * POST /machine-translate/estimate - pre-dispatch character/cost estimate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function estimate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );

		$estimator = new CostEstimator( \PerfLocale\Plugin::get_instance()->get( 'settings' ) );

		$target_lang_ids = array_map( 'intval', (array) $request->get_param( 'target_lang_ids' ) );

		// Clients that know only slugs (the visual editor) may pass target_langs.
		foreach ( (array) $request->get_param( 'target_langs' ) as $slug ) {
			$lang = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' )->find_by_slug( sanitize_key( (string) $slug ) );
			if ( $lang ) {
				$target_lang_ids[] = (int) $lang->id;
			}
		}

		if ( $kind === 'posts' ) {
			$post_ids = array_map( 'intval', (array) $request->get_param( 'post_ids' ) );

			// The estimate reveals the posts' text lengths: read access to every named post is required.
			if ( ! $this->can_read_posts( $post_ids ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot access one of the requested posts.', 'perflocale' ), 403 );
			}

			return $this->success(
				$estimator->estimate_posts( $post_ids, $target_lang_ids, (bool) $request->get_param( 'include_meta' ), true )
			);
		}

		$string_ids = array_map( 'intval', (array) $request->get_param( 'string_ids' ) );

		// The job's own selection, cut at its per-dispatch cap and without the domains the user may not see.
		$selected = ( new \PerfLocale\Background\Jobs\BulkStringTranslateJob() )->resolve_ids_for_estimate(
			[
				'mode'            => 'ids',
				'string_ids'      => $string_ids,
				'exclude_domains' => \PerfLocale\Strings\Domains::restricted_for_current_user(),
			]
		);
		$estimate = $estimator->estimate_strings( $selected, $target_lang_ids, in_array( $request->get_param( 'overwrite' ), [ true, 1, '1', 'true' ], true ) );

		// The ids left out: over the cap, or (with a restricted domain) hidden or missing alike.
		$estimate['capped'] = max( 0, count( array_filter( $string_ids, static fn( int $id ): bool => $id > 0 ) ) - count( $selected ) );

		return $this->success( $estimate );
	}

	/**
	 * Permission gate for /machine-translate: the MT cap, then edit_post on `post_id`.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function translate_post_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->write_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 ) {
			// The handler returns the more descriptive 400 for a missing ID.
			return true;
		}

		if ( ! \PerfLocale\Util\ObjectPermission::edit_narrowed( $post_id ) ) {
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

		// Edit rights on this post, not any post.
		if ( ! \PerfLocale\Util\ObjectPermission::edit_narrowed( $post_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot translate this post.', 'perflocale' ), 403 );
		}

		// The post sent: edit and read on it, also into an existing translation.
		if ( ! \PerfLocale\Helper::user_can_copy_translation_source( $post_id ) ) {
			return TranslationsController::source_refusal( $post_id, $target_lang );
		}

		// A password-protected post is sent unless the perflocale/mt/send_password_protected filter refuses it.
		$source = get_post( $post_id );

		if ( $source instanceof \WP_Post && ! TranslationService::may_send_post( $source, 'rest' ) ) {
			return $this->error( 'password_protected', TranslationService::password_protected_skip_message(), 403 );
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		// A new translation copies shell, meta and terms from the default-language post, which may be another post; a
		// hidden translation gets the create routes' refusal. Before the rate limiter, so a refusal spends no quota.
		$denied = $this->creation_denial( 'post', $post_id, $target_lang );

		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		// Overwriting rewrites the translation in place: it needs edit_post on the translation, not only the source.
		$existing = ( new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) ) )
			->get_translation_id( $post_id, $target_lang );

		if ( $existing && (int) $existing !== $post_id && ! \PerfLocale\Util\ObjectPermission::edit_narrowed( (int) $existing ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot overwrite this translation.', 'perflocale' ), 403 );
		}

		// Per-user hourly cap protects the provider bill from runaway scripts.
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
			$meta   = TranslationService::include_meta( true, 'rest', $post_id, $target_lang );
			$result = self::translate_or_queue( $service, $provider_id, static fn(): array => $service->translate_post( $post_id, $target_lang, $provider_id, true, $meta ) );

			return $this->success( $result, isset( $result['job_id'] ) ? 202 : 200 );
		} catch ( \PerfLocale\MachineTranslation\SameLanguageException $e ) {
			// A request error: the post is already in the target language.
			return $this->error( 'same_language', $e->getMessage(), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'translation_failed', $e->getMessage(), \PerfLocale\MachineTranslation\AbstractProvider::rest_status( $e ) );
		}
	}

	/**
	 * $translate (a translate_post() call) with the interactive piece limit: content in more pieces is queued as a job
	 * (status, job_id, parts, message; translation_id and post_id: the translation it overwrites, or null), or translated
	 * here when it cannot be.
	 *
	 * @param TranslationService $service     The service $translate calls.
	 * @param string             $provider_id Requested provider: the job uses the configured one.
	 * @param callable           $translate   The call.
	 * @phpstan-param callable(): array<mixed> $translate
	 * @return array<mixed>
	 */
	private static function translate_or_queue( TranslationService $service, string $provider_id, callable $translate ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( '' === $provider_id || ( $settings instanceof \PerfLocale\Settings && $settings->get_mt_provider() === $provider_id ) ) {
			$service->hand_off_above( TranslationService::interactive_pieces() );
		}

		try {
			return $translate();
		} catch ( \PerfLocale\MachineTranslation\PiecesHandOff $e ) {
			$job = $e->queue( 'rest' );

			if ( '' === $job ) {
				if ( '' !== $e->unqueued() ) {
					throw new \RuntimeException( esc_html( $e->unqueued() ), 'wait' === $e->reason ? 429 : 504 );
				}

				$service->hand_off_above( 0 );

				return $translate();
			}

			return [
				'status'         => 'queued',
				'job_id'         => $job,
				'parts'          => $e->pieces,
				'message'        => $e->notice(),
				'translation_id' => $e->exists ? $e->translation : null,
				'post_id'        => $e->exists ? $e->translation : null,
			];
		}
	}

	/**
	 * Refuse a request whose new translation the user may not create.
	 *
	 * A new translation copies from the default-language member, maybe the named object: the user needs edit and read
	 * on it (terms: edit_term) and the create rule of its type or taxonomy. An existing translation is not checked here.
	 *
	 * @param string $type        'post' or 'term'.
	 * @param int    $id          Object ID the request named.
	 * @param string $target_lang Target language slug.
	 * @return \WP_Error|null WP_Error on denial, null when allowed.
	 */
	private function creation_denial( string $type, int $id, string $target_lang ): ?\WP_Error {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $type === 'post' ) {
			$ptm = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );

			// A translation the caller may not view: the refusal of the create routes, with or without overwrite.
			if ( TranslationsController::hidden_translation( $id, $ptm->get_translation_id( $id, $target_lang ) ) ) {
				return TranslationsController::unavailable( $target_lang );
			}

			$copy_from = $ptm->get_copy_source_id( $id, $target_lang );
			$allowed   = $copy_from <= 0 || \PerfLocale\Helper::user_can_copy_translation_source( $copy_from );

			if ( $allowed && $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_like( $copy_from ) ) {
				return $this->error( 'cannot_create_type', __( 'You do not have permission to create translations of this content.', 'perflocale' ), 403 );
			}
		} else {
			$term    = get_term( $id );
			$ttm     = new \PerfLocale\Translation\TermTranslationManager( $plugin->get( 'cache' ) );
			$sibling = $term instanceof \WP_Term ? $ttm->get_translation_id( $id, $target_lang ) : null;

			// A sibling the caller may not edit is left out of its maps: refused the same way, naming none.
			if ( $sibling && (int) $sibling !== $id && ! current_user_can( 'edit_term', (int) $sibling ) ) {
				return TranslationsController::unavailable( $target_lang );
			}

			$copy_from = $term instanceof \WP_Term ? $ttm->get_copy_source_id( $id, $term->taxonomy, $target_lang ) : 0;
			$allowed   = $copy_from <= 0 || $copy_from === $id || current_user_can( 'edit_term', $copy_from );

			if ( $allowed && $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_term_like( $copy_from ) ) {
				return $this->error( 'cannot_create_type', __( 'You do not have permission to create translations of this content.', 'perflocale' ), 403 );
			}
		}

		if ( $allowed ) {
			return null;
		}

		// A copy source the caller may not open is refused naming none (terms: edit_term is the view rule).
		return $type === 'post' ? TranslationsController::source_refusal( $copy_from, $target_lang ) : TranslationsController::source_unavailable( $target_lang );
	}

	/**
	 * Per-user hourly MT rate limit (HTTP 429 past the budget).
	 *
	 * @param int $user_id Current user ID.
	 * @return \WP_Error|null WP_Error on denial, null when allowed.
	 */
	private function enforce_rate_limit( int $user_id ): ?\WP_Error {
		// MtRateLimiter is the one limiter shared with block translation, the Ability and the admin action.
		return MtRateLimiter::admit( $user_id );
	}
}
