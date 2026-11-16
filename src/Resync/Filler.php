<?php
/**
 * Fill a missing or empty translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Automation\ChangeService;
use PerfLocale\Automation\PostTarget;
use PerfLocale\Background\Jobs\ContentResyncJob;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Helper;
use PerfLocale\MachineTranslation\CostEstimator;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Translation\BlockTemplateSupport;
use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Translation\StubTitle;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills one pair whose translation is missing or empty, as the current user, with the bulk job's checks: machine
 * translated or created empty. An existing empty translation is filled only while not live and is read again just
 * before the write (one a person changed meanwhile is left: `stale`).
 */
final class Filler {

	/** Posts, pages and other types. */
	public const ROUTE_POST = 'post';

	/** Templates and template parts. */
	public const ROUTE_TEMPLATE = 'template';

	/** Synced patterns and navigation menus. */
	public const ROUTE_COPY = 'copy';

	/** Types copied by the translation manager with their content. */
	private const COPY_TYPES = [ 'wp_block', 'wp_navigation' ];

	/** Exception message that stops translate_post() before it writes over a changed translation. */
	private const CHANGED = 'perflocale_fill_translation_changed';

	/** Exception message that stops translate_post() before it writes after an import. */
	private const IMPORTED = 'perflocale_fill_import_ran';

	/**
	 * The route of a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function route( string $post_type ): string {
		if ( BlockTemplateSupport::is_template_type( $post_type ) ) {
			return self::ROUTE_TEMPLATE;
		}

		return in_array( $post_type, self::COPY_TYPES, true ) ? self::ROUTE_COPY : self::ROUTE_POST;
	}

	/**
	 * Whether an existing empty translation of this status is filled: not while live (that needs a reviewed update).
	 *
	 * @param string $post_status The translation's status.
	 * @return bool
	 */
	public static function fills_empty( string $post_status ): bool {
		return ! in_array( $post_status, [ 'publish', 'future', 'private' ], true );
	}

	/**
	 * The transient key of a machine fill's kept outputs, per blog, source, language and route (no translation yet).
	 *
	 * @param int    $source_id   Source id.
	 * @param int    $language_id Language id.
	 * @param string $route       The type's route.
	 * @return string
	 */
	public static function units_key( int $source_id, int $language_id, string $route ): string {
		return 'perflocale_resync_units_' . md5( get_current_blog_id() . ':fill:' . $source_id . ':' . $language_id . ':' . $route );
	}

	/**
	 * Whether an existing empty translation, read now, is still empty, not live and (given) still with `$title`.
	 *
	 * @param int         $translation_id Translation id.
	 * @param string|null $title          The title the fill's selection read; null: not checked.
	 * @return bool
	 */
	private static function still_empty( int $translation_id, ?string $title = null ): bool {
		wp_cache_delete( $translation_id, 'posts' );
		$now = get_post( $translation_id );

		return $now instanceof \WP_Post && '' === $now->post_content && self::fills_empty( $now->post_status ) && ( null === $title || $title === $now->post_title );
	}

	/**
	 * Whether a machine fill keeps an existing empty translation's title. A title that is a copy (the source's, the
	 * recorded stub title, or a source revision's) is filled; any other is a person's and is neither sent nor written.
	 *
	 * @param \WP_Post $translation The existing empty translation, as the selection read it.
	 * @param \WP_Post $source      The source.
	 * @param string   $slug        The translation's language.
	 * @return bool
	 */
	public static function keeps_title( \WP_Post $translation, \WP_Post $source, string $slug ): bool {
		$keeps = self::typed_title( $translation, $source );

		/**
		 * Filters whether a machine fill of an existing empty translation keeps its title instead of translating it.
		 * Default: kept when not empty and not a copy of a source or stub title. Only tightens: true keeps it; false or a
		 * non-bool keeps the default.
		 *
		 * @internal
		 * @hook  perflocale/resync/fill_keeps_title
		 * @since 1.0.8
		 *
		 * @param bool   $keeps          Whether the title is kept.
		 * @param int    $translation_id The translation.
		 * @param int    $source_id      The source.
		 * @param string $language_slug  The translation's language.
		 */
		$filtered = apply_filters( 'perflocale/resync/fill_keeps_title', $keeps, (int) $translation->ID, (int) $source->ID, $slug );

		return $keeps || true === $filtered;
	}

	/**
	 * The default of keeps_title(): whether no stub or source title explains the translation's title.
	 *
	 * @param \WP_Post $translation The translation.
	 * @param \WP_Post $source      The source.
	 * @return bool
	 */
	private static function typed_title( \WP_Post $translation, \WP_Post $source ): bool {
		$title = trim( (string) $translation->post_title );

		if ( '' === $title || trim( (string) $source->post_title ) === $title ) {
			return false;
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		// One statement: the stub title (on every row) and source revisions with this title. A MySQL collation
		// ignores letter case, so matches are compared again in PHP.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per fill decision, on an admin or job run only; the revisions change with every save.
			$wpdb->prepare(
				"SELECT stub.meta_value AS stub, post_title FROM ( SELECT ( SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1 ) AS meta_value ) AS stub LEFT JOIN %i ON post_parent = %d AND post_type = 'revision' AND TRIM( post_title ) = %s",
				$wpdb->postmeta,
				(int) $translation->ID,
				StubTitle::KEY,
				$wpdb->posts,
				(int) $source->ID,
				$title
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : [];
		$stub = $rows[0]['stub'] ?? null;

		if ( is_string( $stub ) && StubTitle::carries( $title, maybe_unserialize( $stub ) ) ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( is_string( $row['post_title'] ?? null ) && trim( $row['post_title'] ) === $title ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Fill one pair.
	 *
	 * @param \WP_Post   $source      The source (the default-language member of its group).
	 * @param int        $language_id The language.
	 * @param string     $mode        `machine` or `empty`.
	 * @param RunOptions $o           The run's options.
	 * @return Outcome `filled` (reason `title_kept` when a title was kept), `created`, `linked` (a copy existed: a resync pair), `fill` (dry run), `stale`, or why not.
	 */
	public static function fill( \WP_Post $source, int $language_id, string $mode, RunOptions $o ): Outcome {
		if ( ! Store::available() ) {
			return new Outcome( 'unavailable' );
		}

		// No fill while an import runs; one that starts later refuses every write below.
		$import_generation = ChangeService::import_fence();

		if ( null === $import_generation ) {
			return new Outcome( 'busy', 'import' );
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$langs    = $plugin->lang_repo();
		$language = $langs->find( $language_id );
		$default  = $langs->get_default();
		$slug     = Scalar::str( Scalar::prop( $language, 'slug' ) );
		$def_slug = Scalar::str( Scalar::prop( $default, 'slug' ) );

		if ( '' === $slug || '' === $def_slug || $slug === $def_slug ) {
			return new Outcome( 'no_source', 'language' );
		}

		if ( in_array( $source->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return new Outcome( 'no_source', 'source' );
		}

		$manager      = new PostTranslationManager( $plugin->cache(), $plugin->settings() );
		$translations = GroupLinks::of( $source->ID );

		if ( ( $translations[ $def_slug ] ?? 0 ) !== $source->ID ) {
			return new Outcome( 'no_source', 'not_default' );
		}

		if ( 'publish' !== $source->post_status && ! $o->per_post ) {
			return new Outcome( 'no_source', 'unpublished' );
		}

		$scope_args = [
			'trigger'      => $o->trigger,
			'language_ids' => [ $language_id ],
			'user_id'      => get_current_user_id(),
		];

		if ( ! Scope::includes( $source, $scope_args ) ) {
			return new Outcome( 'out_of_scope' );
		}

		$translation_id = Scalar::int( $translations[ $slug ] ?? 0 );
		$translation    = $translation_id > 0 ? get_post( $translation_id ) : null;

		if ( $translation instanceof \WP_Post && 'trash' === $translation->post_status ) {
			return new Outcome( 'target_gone', '', $translation_id );
		}

		$exists = $translation instanceof \WP_Post;

		if ( $exists && '' !== $translation->post_content ) {
			return new Outcome( 'linked', '', $translation_id );
		}

		if ( $exists && ! self::fills_empty( $translation->post_status ) ) {
			return new Outcome( 'empty', 'published', $translation_id );
		}

		// The bulk job's checks.
		if ( ! Helper::user_can_copy_translation_source( $source->ID ) ) {
			return new Outcome( 'skipped_permission', '', $translation_id );
		}

		if ( $exists ) {
			if ( ! Helper::user_can_edit_object( $translation_id ) ) {
				return new Outcome( 'skipped_permission', '', $translation_id );
			}
		} else {
			$copy_from = $manager->get_copy_source_id( $source->ID, $slug );

			if ( $copy_from > 0 && ! Helper::user_can_copy_translation_source( $copy_from ) ) {
				return new Outcome( 'skipped_permission' );
			}

			if ( ! Helper::user_can_create_like( $copy_from > 0 ? $copy_from : $source->ID ) ) {
				return new Outcome( 'skipped_permission' );
			}
		}

		$route = self::route( $source->post_type );

		if ( ContentResyncJob::FILL_EMPTY === $mode ) {
			return self::fill_empty( $source, $slug, $route, $translation_id, $o, $manager, $import_generation );
		}

		$mt_why = self::mt_unavailable( $plugin->settings() );

		// A password-protected source the site does not send counts as MT unavailable for it.
		if ( '' === $mt_why && ! TranslationService::may_send_post( $source, 'resync_fill' ) ) {
			$mt_why = 'password_protected';
		}

		if ( '' !== $mt_why ) {
			return new Outcome( $exists ? 'empty' : 'missing', 'mt_unavailable', $translation_id, 0, '', [], [], 0, 0, $mt_why );
		}

		// Decided before anything is sent: a person's title is neither sent nor written.
		$keep_title = $translation instanceof \WP_Post && self::ROUTE_POST === $route && self::keeps_title( $translation, $source, $slug );
		$fields     = Scope::fields_included( $scope_args );
		$chars      = self::chars( $source, $fields ) - ( $keep_title ? \PerfLocale\Util\Utf8::length( $source->post_title ) : 0 );

		if ( $o->dry_run ) {
			return new Outcome( 'fill', '', $translation_id, 0, '', [ 'created' => $exists ? 0 : 1 ], [], $chars );
		}

		if ( null !== $o->mt_budget && $chars > $o->mt_budget ) {
			return new Outcome( 'needs_estimate', '', $translation_id, 0, '', [], [], $chars );
		}

		$service = new TranslationService( $plugin->settings(), $plugin->cache() );

		// translate_post() counts a kept title against the monthly limit too, so check that sum before any request.
		$limit_chars = $keep_title ? max( $chars, \PerfLocale\Util\Utf8::length( $source->post_title ) + \PerfLocale\Util\Utf8::length( $source->post_content ) + \PerfLocale\Util\Utf8::length( $source->post_excerpt ) ) : $chars;

		if ( $service->would_exceed_limit( $limit_chars ) ) {
			return new Outcome( 'limit', '', $translation_id, 0, '', [], [], $chars );
		}

		if ( self::ROUTE_POST === $route ) {
			return self::machine_post( $source, $slug, $language_id, $translation_id, $fields, $chars, $o, $service, $import_generation, $translation instanceof \WP_Post ? $translation->post_title : null, $keep_title );
		}

		$kept    = [
			'key'    => '',
			'chunks' => 0,
		];
		$outcome = self::machine_copy( $source, $slug, $language_id, $route, $translation_id, $o, $service, $manager, $kept, $import_generation );

		UnitTranslator::settle( $kept, $outcome->status );

		return $outcome;
	}

	/**
	 * An empty fill: a missing post becomes an empty draft, a missing template, part, pattern or menu its copy.
	 *
	 * @param \WP_Post               $source         Source.
	 * @param string                 $slug           Target language slug.
	 * @param string                 $route          The type's route.
	 * @param int                    $translation_id The existing empty translation, 0 when missing.
	 * @param RunOptions             $o              Options.
	 * @param PostTranslationManager $manager        Translation manager.
	 * @param int                    $import_generation The fill's import_fence().
	 * @return Outcome
	 */
	private static function fill_empty( \WP_Post $source, string $slug, string $route, int $translation_id, RunOptions $o, PostTranslationManager $manager, int $import_generation ): Outcome {
		if ( $translation_id > 0 ) {
			return new Outcome( 'empty', '', $translation_id );
		}

		if ( $o->dry_run ) {
			return new Outcome( 'fill', '', 0, 0, '', [ 'created' => 1 ] );
		}

		if ( ChangeService::import_moved( $import_generation ) ) {
			return new Outcome( 'busy', 'import' );
		}

		$created = false;

		if ( self::ROUTE_TEMPLATE === $route ) {
			$id = \PerfLocale\Translation\BlockTemplateWrites::ensure_translation( $source, $slug, $created );
		} else {
			$id = $manager->create_translation( $source->ID, $slug, self::ROUTE_COPY === $route, \PerfLocale\Enum\SourceType::Manual, $created );
		}

		$id = is_int( $id ) ? $id : 0;

		if ( $id <= 0 ) {
			return new Outcome( 'missing', self::ROUTE_TEMPLATE === $route ? 'not_created' : 'failed' );
		}

		return $created ? new Outcome( 'created', '', $id ) : new Outcome( 'linked', '', $id );
	}

	/**
	 * A machine fill of a post-like type through whole-post translation, as the bulk job runs it. The job's clock
	 * is asked once before translate_post(), which sends several requests, so the pair can overrun its slice.
	 *
	 * @param \WP_Post           $source         Source.
	 * @param string             $slug           Target language slug.
	 * @param int                $language_id    Language.
	 * @param int                $translation_id The existing empty translation, 0 when missing.
	 * @param bool               $fields         Whether custom fields are in scope.
	 * @param int                $chars          Characters it sends.
	 * @param RunOptions         $o              Options.
	 * @param TranslationService $service        The service.
	 * @param int                $import_generation The fill's import_fence().
	 * @param string|null        $title          The existing translation's title as the selection read it, null when missing.
	 * @param bool               $keep_title     Whether the fill keeps that title.
	 * @return Outcome
	 */
	private static function machine_post( \WP_Post $source, string $slug, int $language_id, int $translation_id, bool $fields, int $chars, RunOptions $o, TranslationService $service, int $import_generation, ?string $title = null, bool $keep_title = false ): Outcome {
		if ( null !== $o->before_request && ! ( $o->before_request )( 0 ) ) {
			return new Outcome( 'deferred', '', $translation_id );
		}

		self::ensure_skip_filter();

		$plugin  = \PerfLocale\Plugin::get_instance();
		$context = [
			'source_id'   => $source->ID,
			'target_slug' => $slug,
			'target_id'   => $language_id,
			'provider'    => Scalar::str( $plugin->settings()->get( 'mt_provider', '' ) ),
			'processed'   => 0,
			'total'       => 1,
		];

		/** This filter is documented in src/Background/Jobs/BulkTranslateJob.php */
		$pre = apply_filters( 'perflocale/mt/bulk/before_translate', null, $source->ID, $slug, $context );

		if ( null !== $pre ) {
			/** This action is documented in src/Background/Jobs/BulkTranslateJob.php */
			do_action(
				'perflocale/mt/bulk/after_translate',
				$source->ID,
				$slug,
				is_array( $pre ) ? $pre : [],
				array_merge(
					$context,
					[
						'short_circuited' => true,
						'error'           => '',
					]
				)
			);

			$id = is_array( $pre ) ? Scalar::int( $pre['post_id'] ?? 0 ) : 0;

			return $id > 0 ? new Outcome( 'filled', 'short_circuited', $id ) : new Outcome( 'skipped_decided', 'short_circuited', $translation_id );
		}

		$result  = [];
		$error   = '';
		$status  = 'failed';
		$reason  = 'provider';
		$unsent  = null;
		$restore = null;

		// After the provider, before translate_post() creates or writes anything.
		$guard = static function ( $translated ) use ( $translation_id, $title, $import_generation ) {
			if ( ChangeService::import_moved( $import_generation ) ) {
				throw new \RuntimeException( self::IMPORTED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A fixed marker, caught below.
			}

			if ( $translation_id > 0 && ! self::still_empty( $translation_id, $title ) ) {
				throw new \RuntimeException( self::CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A fixed marker, caught below.
			}

			return $translated;
		};

		add_filter( 'perflocale/mt/post_translate', $guard, PHP_INT_MAX );

		// A kept title is not sent (an empty slot is skipped) and is written back as the guard just read it.
		if ( $keep_title && null !== $title ) {
			$unsent  = static function ( $texts ) {
				if ( is_array( $texts ) && array_key_exists( 0, $texts ) ) {
					$texts[0] = '';
				}

				return $texts;
			};
			$restore = static function ( $data, $postarr ) use ( $translation_id, $title ) {
				if ( is_array( $data ) && is_array( $postarr ) && $translation_id === Scalar::int( $postarr['ID'] ?? 0 ) ) {
					$data['post_title'] = wp_slash( $title );
				}

				return $data;
			};

			add_filter( 'perflocale/mt/pre_translate', $unsent, PHP_INT_MAX );
			add_filter( 'wp_insert_post_data', $restore, PHP_INT_MAX, 2 );
		}

		try {
			$result = $service->translate_post( $source->ID, $slug, '', true, $fields );
			$status = Scalar::int( $result['post_id'] ?? 0 ) > 0 ? 'filled' : 'failed';
		} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
			$error  = $e->getMessage();
			$reason = 'provider_unavailable';
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();

			if ( self::CHANGED === $error ) {
				$status = 'stale';
				$reason = 'changed';
			} elseif ( self::IMPORTED === $error || ChangeService::import_moved( $import_generation ) ) {
				// An import that landed after the guard (it restored the link: "already exists") is that import's refusal.
				$status = 'busy';
				$reason = 'import';
			}
		} finally {
			remove_filter( 'perflocale/mt/post_translate', $guard, PHP_INT_MAX );

			if ( null !== $unsent ) {
				remove_filter( 'perflocale/mt/pre_translate', $unsent, PHP_INT_MAX );
				remove_filter( 'wp_insert_post_data', $restore, PHP_INT_MAX );
			}
		}

		/** This action is documented in src/Background/Jobs/BulkTranslateJob.php */
		do_action(
			'perflocale/mt/bulk/after_translate',
			$source->ID,
			$slug,
			$result,
			array_merge(
				$context,
				[
					'short_circuited' => false,
					'error'           => $error,
				]
			)
		);

		if ( 'stale' === $status || 'busy' === $status ) {
			return new Outcome( $status, $reason, $translation_id, 0, '', [], [], $chars, 1 );
		}

		if ( 'filled' !== $status ) {
			ChangeService::log( sprintf( 'the fill of source %d into %s failed: %s', $source->ID, $slug, $error ) );

			return new Outcome( 'failed', $reason, $translation_id, 0, '', [], [], $chars, 1 );
		}

		$id = Scalar::int( $result['post_id'] ?? 0 );

		return new Outcome( 'filled', ! empty( $result['created'] ) ? 'created' : ( $keep_title ? 'title_kept' : '' ), $id, 0, '', [ 'created' => ! empty( $result['created'] ) ? 1 : 0 ], [], $chars, 1 );
	}

	/**
	 * A machine fill of a template, part, pattern or menu: the engine translates every unit of a copy in place and
	 * the fill is recorded as the pair's state. Outputs are kept per request, so a stopped fill pays no unit twice.
	 *
	 * @param \WP_Post               $source         Source.
	 * @param string                 $slug           Target language slug.
	 * @param int                    $language_id    Language.
	 * @param string                 $route          The type's route.
	 * @param int                    $translation_id The existing empty translation, 0 when missing.
	 * @param RunOptions             $o              Options.
	 * @param TranslationService     $service        The service.
	 * @param PostTranslationManager $manager        Translation manager.
	 * @param array                  $kept           Set to the fill's kept outputs, when any.
	 * @phpstan-param array{key: string, chunks: int} $kept
	 * @param int                    $import_generation The import generation read when the fill began.
	 * @return Outcome
	 */
	private static function machine_copy( \WP_Post $source, string $slug, int $language_id, string $route, int $translation_id, RunOptions $o, TranslationService $service, PostTranslationManager $manager, array &$kept, int $import_generation ): Outcome {
		// Before anything is sent: a template with an orphan translation the save would take back is a resync pair.
		$created   = false;
		$was_empty = $translation_id > 0;

		if ( $translation_id <= 0 && self::ROUTE_TEMPLATE === $route ) {
			$found = \PerfLocale\Translation\BlockTemplateWrites::ensure_translation( $source, $slug, $created, false );

			if ( 0 === $found ) {
				return new Outcome( 'missing', 'not_created' );
			}

			if ( $found > 0 ) {
				return new Outcome( 'linked', '', $found );
			}
		}

		$segment  = [
			'post_type'                      => $source->post_type,
			Segmenter::OPTION_PATTERN_MARKED => PatternMarks::resolver( $language_id ),
		];
		$prepared = Engine::prepare(
			[ 'content' => '' ],
			[ 'content' => '' ],
			[ 'content' => $source->post_content ],
			[
				'post_type' => $source->post_type,
				'segment'   => $segment,
			]
		);

		if ( $prepared instanceof Proposal ) {
			return new Outcome( 'unsupported', $prepared->reason, $translation_id );
		}

		$requests = [];

		foreach ( $prepared->units_needing_text() as $unit ) {
			$requests[] = [
				'index'  => $unit['index'],
				'text'   => $unit['text'],
				'format' => $unit['format'],
			];
		}

		$texts       = [];
		$mt_chars    = 0;
		$mt_requests = 0;
		$rejected    = false;

		if ( [] !== $requests ) {
			$plugin      = \PerfLocale\Plugin::get_instance();
			$source_lang = Scalar::str( Scalar::prop( $plugin->lang_repo()->get_default(), 'slug' ) );
			$store_key   = self::units_key( $source->ID, $language_id, $route );
			$version     = UnitTranslator::version( $service, $plugin->settings(), $source_lang, $slug, [ Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt ) ] );
			$reuse       = UnitTranslator::kept( $store_key, $version, $requests );
			$outputs     = $reuse['texts'];
			$refused     = $reuse['rejected'];
			$kept        = $reuse['kept'];

			if ( [] !== $reuse['pending'] ) {
				try {
					$result = ( new UnitTranslator() )->translate( $service, $reuse['pending'], $source_lang, $slug, $o->before_request, UnitTranslator::checkpoint( $store_key, $version, $requests, $reuse, $kept ) );
				} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
					return new Outcome( 'failed', 'provider_unavailable', $translation_id );
				} catch ( \Throwable $e ) {
					ChangeService::log( sprintf( 'the fill of source %d into %s failed: %s', $source->ID, $slug, $e->getMessage() ) );

					return new Outcome( 'failed', 'provider', $translation_id );
				}

				$outputs     = $outputs + $result['texts'];
				$refused     = array_merge( $refused, $result['rejected'] );
				$mt_chars    = $result['chars'];
				$mt_requests = $result['requests'];

				// Nothing is created before every part has its text. Unkept outputs would be sent again every
				// slice, so such a fill fails instead of deferring.
				if ( $result['stopped'] ) {
					if ( $result['unkept'] ) {
						ChangeService::log( sprintf( 'the fill of source %d into %s stopped and its outputs could not be kept', $source->ID, $slug ) );

						return new Outcome( 'failed', 'not_kept', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
					}

					return new Outcome( 'deferred', '', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
				}
			}

			$rejected = [] !== $refused;

			foreach ( $outputs as $index => $text ) {
				$texts[ (int) $index ] = $text;
			}
		}

		$proposal = Engine::complete( $prepared, $texts, [] );

		if ( Proposal::STATUS_PROPOSAL !== $proposal->status ) {
			return new Outcome( $proposal->status, $proposal->reason, $translation_id );
		}

		if ( $translation_id <= 0 ) {
			// Before the copy is created: a refused write below would leave it behind.
			if ( ChangeService::import_moved( $import_generation ) ) {
				return new Outcome( 'busy', 'import', 0, 0, '', [], [], $mt_chars, $mt_requests );
			}

			if ( self::ROUTE_TEMPLATE === $route ) {
				$translation_id = \PerfLocale\Translation\BlockTemplateWrites::ensure_translation( $source, $slug, $created );
			} else {
				$id             = $manager->create_translation( $source->ID, $slug, true, \PerfLocale\Enum\SourceType::MachineTranslation, $created );
				$translation_id = is_int( $id ) ? $id : 0;
			}

			if ( $translation_id <= 0 ) {
				return new Outcome( 'missing', 'not_created', 0, 0, '', [], [], $mt_chars, $mt_requests );
			}

			if ( ! $created ) {
				return new Outcome( 'linked', '', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
			}
		}

		$written = Lock::with(
			ChangeService::lock_name( 'post', $translation_id, $language_id ),
			ChangeService::target_lock_ttl(),
			static function () use ( $translation_id, $proposal, $was_empty, $import_generation, $source ): string {
				if ( ChangeService::import_moved( $import_generation ) ) {
					return 'import';
				}

				// Script markup neither the source nor the copy holds is never written (a composer fault).
				if ( [] !== WriteTripwire::added( [ $proposal->content ], [ $source->post_content, Scalar::str( get_post_field( 'post_content', $translation_id, 'raw' ) ) ] ) ) {
					return 'unsafe_markup';
				}

				// Digest before the check's read: the write lands only on that row (compare-and-set).
				$digest = PostTarget::row_digest( $translation_id );

				if ( $was_empty && ! self::still_empty( $translation_id ) ) {
					return 'stale';
				}

				if ( null === $digest ) {
					return PostTarget::ROW_UNREADABLE;
				}

				$moved = false;
				$saved = ChangeService::writing(
					static function () use ( $translation_id, $digest, $proposal, &$moved ) {
						return PostTarget::guarded_save(
							$translation_id,
							$digest,
							static fn () => wp_update_post(
								wp_slash(
									[
										'ID'            => $translation_id,
										'post_content'  => $proposal->content,
										// An empty page_template leaves the stored template alone: one
										// the theme no longer has would stop the save before its hooks.
										'page_template' => '',
									]
								),
								true
							),
							$moved
						);
					},
					$translation_id
				);

				if ( $moved ) {
					// A save landed after the check's read: nothing was written.
					return 'stale';
				}

				return ! is_wp_error( $saved ) && Scalar::int( $saved ) > 0 ? 'written' : 'failed';
			}
		);

		if ( 'written' !== $written ) {
			if ( null === $written || 'import' === $written ) {
				return new Outcome( 'busy', null === $written ? 'lock' : 'import', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
			}

			if ( 'stale' === $written ) {
				return new Outcome( 'stale', 'changed', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
			}

			if ( 'unsafe_markup' === $written ) {
				ChangeService::log( sprintf( 'the fill of source %d into translation %d was refused: it adds script markup the source does not hold', $source->ID, $translation_id ) );

				return new Outcome( 'failed', 'unsafe_markup', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
			}

			return new Outcome( 'failed', PostTarget::ROW_UNREADABLE === $written ? PostTarget::ROW_UNREADABLE : 'write_failed', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
		}

		wp_cache_delete( $translation_id, 'posts' );
		$stored = get_post( $translation_id );

		if ( $stored instanceof \WP_Post ) {
			SyncRecorder::after_fill( $stored, $source, $language_id, [], $proposal->complete && ! $rejected, $proposal->block_map );
		}

		$counts            = array_filter( $proposal->counts, 'is_int' );
		$counts['created'] = $created ? 1 : 0;

		return new Outcome( 'filled', $created ? 'created' : '', $translation_id, 0, '', $counts, $rejected ? [ \PerfLocale\Automation\Policy::FLAG_QUALITY ] : [], $mt_chars, $mt_requests );
	}

	/**
	 * The characters a machine fill sends, as the cost estimator counts a missing pair.
	 *
	 * @param \WP_Post $source Source.
	 * @param bool     $fields Whether custom fields are in scope.
	 * @return int
	 */
	public static function chars( \WP_Post $source, bool $fields ): int {
		$by_post = ( new CostEstimator( \PerfLocale\Plugin::get_instance()->settings() ) )->chars_by_post( [ $source->ID ], $fields );

		return $by_post[ $source->ID ] ?? 0;
	}

	/**
	 * Why machine translation cannot run for the acting user; '' when it can.
	 *
	 * @param \PerfLocale\Settings $settings Settings.
	 * @return string
	 */
	private static function mt_unavailable( \PerfLocale\Settings $settings ): string {
		if ( ! $settings->mt_enabled() ) {
			return 'mt_disabled';
		}

		if ( ! current_user_can( 'perflocale_use_mt' ) ) {
			return 'no_capability';
		}

		try {
			( new TranslationService( $settings, \PerfLocale\Plugin::get_instance()->cache() ) )->get_provider();
		} catch ( \RuntimeException $e ) {
			return 'no_provider';
		}

		return '';
	}

	/**
	 * Register the "Do not translate" masking before translate_post(): load registers it only in write contexts,
	 * and a worker under ALTERNATE_WP_CRON is not one.
	 *
	 * @return void
	 */
	private static function ensure_skip_filter(): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'block_skip_filter' ) ) {
			return;
		}

		$filter = $plugin->get( 'block_skip_filter' );

		if ( $filter instanceof \PerfLocale\Translation\BlockSkipFilter && false === has_filter( 'perflocale/mt/pre_translate', [ $filter, 'mask_before' ] ) ) {
			$filter->register_hooks();
		}
	}
}
