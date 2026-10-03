<?php
/**
 * Bulk machine-translation job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tier-2 job: translate a set of source posts into one or more target
 * languages using the configured machine-translation provider.
 *
 * Dispatched from the Translations admin page bulk-action handler. Below
 * the threshold the work runs inline so the operator sees counts on the
 * redirect; above it, control returns immediately and the worker hook
 * grinds through the matrix in the background — visible under PerfLocale
 * → Jobs.
 *
 * Args shape:
 *   - 'source_ids'      : int[]  Post IDs to translate FROM (default-lang
 *                                rows; one or more).
 *   - 'target_lang_ids' : int[]  Language IDs to translate INTO.
 *   - 'trigger'         : string Optional. The path that runs the pairs,
 *                                asked about a password-protected source
 *                                (TranslationService::may_send_post()):
 *                                'site_translate' or 'cli_all' (SiteTranslateJob),
 *                                'rest' (the bulk-translate route); absent
 *                                or anything else, 'bulk' (the Translations
 *                                screen's bulk action).
 *
 * Skip rule: a (source, target) pair is skipped when the source already
 * has a translation in that target language — no overwrites.
 *
 * args_size() returns `count(source_ids) * count(target_lang_ids)` — the
 * total number of provider calls this dispatch will potentially fan out
 * to. That matches what an operator intuits as "how big is this job"
 * and pairs naturally with the per-job threshold setting.
 *
 * Run by the background worker, the job runs in slices (see
 * {@see AbstractJob::supports_continuation()}): on a web request a slice
 * stops after SLICE_SECONDS or SLICE_MAX_PAIRS pairs, and the same job
 * continues from the stored counts in the next worker run. Under WP-CLI a
 * slice has no budget and drops the runtime caches every
 * RELEASE_EVERY_PAIRS pairs. Inline runs and SiteTranslateJob's chunks run
 * every pair in one call.
 */
final class BulkTranslateJob extends AbstractJob {

	/**
	 * Wall-clock budget of one slice on a web request, in seconds. Leaves
	 * margin under a 30 s execution or request limit; the same value as
	 * SiteTranslateJob's chunk budget.
	 */
	private const SLICE_SECONDS = 20;

	/**
	 * Most pairs one slice runs on a web request, a backstop for work so fast
	 * that the clock never binds.
	 */
	private const SLICE_MAX_PAIRS = 500;

	/**
	 * Pairs between two runtime-cache releases under WP-CLI, where a slice
	 * has no budget by default. Bounds the memory a long run holds.
	 */
	private const RELEASE_EVERY_PAIRS = 500;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'bulk_translate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Same capability the Translations bulk-action handler gates on, so
	 * the worker re-check inside the job pipeline matches the dispatch-
	 * side gate.
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * 25 is the inline-execution threshold for bulk-translate: below it
	 * the loop runs synchronously inside the admin request with plenty
	 * of headroom under PHP's max_execution_time; above it the dispatch
	 * routes async to dodge PHP-FPM timeouts.
	 *
	 * Tunable per site via Settings → Performance → Background Thresholds.
	 */
	public function get_default_threshold(): int {
		return 25;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/** {@inheritDoc} */
	protected function args_size( array $args ): int {
		$sources = is_array( $args['source_ids'] ?? null ) ? $args['source_ids'] : [];
		$targets = is_array( $args['target_lang_ids'] ?? null ) ? $args['target_lang_ids'] : [];

		return count( $sources ) * count( $targets );
	}

	/**
	 * Execute one batch of (source × target) translations.
	 *
	 * Per-pair semantics: `translate_post()` per (source, target), skip
	 * when an existing translation is already present, `fast_fail=false`
	 * so retryable errors don't abort the whole batch.
	 *
	 * With `__perflocale_slice` in the args (a worker run) the call is one
	 * slice: it resumes from `__perflocale_slice['resume']` and, when its
	 * budget runs out before the last pair, returns the counts so far with
	 * `__perflocale_continue => true`.
	 *
	 * @param array<string, mixed> $args     `source_ids` + `target_lang_ids`
	 *                                       per the class-level docblock.
	 * @param callable             $progress `function(int, int): void` —
	 *                                       reports (processed, total).
	 * @return array<string, mixed> `created`, `skipped` (of which
	 *                               `skipped_permission` were refused for
	 *                               permissions), `failed`, `first_error`,
	 *                               `kept` (created rows whose content stayed
	 *                               in the source language because a "Do not
	 *                               translate" section was lost) and
	 *                               `first_warning` (the first such row's
	 *                               warning).
	 * @throws \RuntimeException When machine translation is disabled for the site
	 *                            after the job was queued.
	 */
	public function execute( array $args, callable $progress ): array {
		// Meta-field MT is per-dispatch opt-in (the curated key registry is
		// additionally setting-gated, so true with everything off is a no-op).
		$include_meta = ! empty( $args['include_meta'] );

		// Which path dispatched the run; only these values are accepted.
		$trigger = in_array( $args['trigger'] ?? null, [ 'site_translate', 'cli_all', 'rest' ], true ) ? (string) $args['trigger'] : 'bulk';

		$source_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['source_ids'] ?? [] ) ) ) );
		$target_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );

		if ( $source_ids === [] || $target_ids === [] ) {
			return [
				'created'       => 0,
				'skipped'       => 0,
				'failed'        => 0,
				'first_error'   => '',
				'kept'          => 0,
				'first_warning' => '',
			];
		}

		$plugin   = Plugin::get_instance();
		$settings = $plugin->get( 'settings' );
		$cache    = $plugin->get( 'cache' );

		// Re-check the MASTER switch at worker time, not just at dispatch. A
		// bulk run can sit in the queue for hours; an operator who turns machine
		// translation off in Settings expects the queued work to stop, not to
		// keep spending provider budget. WorkerRegistry re-validates the
		// dispatching user's capability before execute() but knows nothing about
		// MT settings. Same exception and same message as
		// BulkStringTranslateJob::execute(), so both bulk paths report an
		// operator disable identically, and SiteTranslateJob inherits the gate
		// because it runs every chunk through this method.
		if ( ! $settings->mt_enabled() ) {
			throw new \RuntimeException( esc_html__( 'Machine translation is disabled in settings.', 'perflocale' ) );
		}

		// Resolve language ID → object once so the inner loop is just
		// dictionary lookups.
		$lang_repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang_by_id = [];

		foreach ( $lang_repo->get_active() as $lang ) {
			$lang_by_id[ (int) $lang->id ] = $lang;
		}

		$service = new TranslationService( $settings, $cache );
		$manager = new PostTranslationManager( $cache, $settings );

		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		$n_targets   = count( $target_ids );
		$total       = count( $source_ids ) * $n_targets;
		$processed   = 0;
		$created     = 0;
		$skipped     = 0;
		$failed      = 0;
		$first_error = '';
		// The part of $skipped the dispatching user was not allowed to translate.
		$skipped_permission = 0;
		// The part of $created whose content was kept in the source language.
		$kept          = 0;
		$first_warning = '';
		// Last FULLY processed source id.
		$cursor = 0;

		// Pairs a priming window covers; 0 covers every remaining source.
		$window          = 0;
		$before_pair     = null;
		$slice_seconds   = 0.0;
		$slice_max_pairs = 0;
		$release_every   = 0;
		$slice           = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : null;

		if ( null !== $slice ) {
			// Resume from the checkpoint the previous slice stored: the counts
			// of this method's result. Pairs run in a fixed order (sources in
			// dispatch order, each source's targets in order), so `processed`
			// alone is the position.
			$resume = is_array( $slice['resume'] ?? null ) ? $slice['resume'] : [];

			if ( [] !== $resume ) {
				$point = self::checkpoint( $resume, $source_ids, $n_targets );

				if ( null === $point ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a checkpoint that does not match the job.
					error_log( '[PerfLocale] Bulk translation: the stored progress does not match the job, so this run starts again at the first pair. Pairs already translated are skipped without a provider call.' );
				} else {
					[
						'processed'          => $processed,
						'created'            => $created,
						'skipped'            => $skipped,
						'skipped_permission' => $skipped_permission,
						'failed'             => $failed,
						'first_error'        => $first_error,
						'kept'               => $kept,
						'first_warning'      => $first_warning,
						'cursor'             => $cursor,
					] = $point;
				}
			}

			$cli = 'cli' === PHP_SAPI;

			/**
			 * Filter the wall-clock budget of one slice of a background bulk
			 * translation, in seconds.
			 *
			 * A slice stops before its next pair once it has run at least one
			 * pair and reached this budget or its pair cap; the same job then
			 * continues in the next worker run. 0 or less: no time budget.
			 *
			 * @hook perflocale/jobs/bulk_translate/slice_seconds
			 * @param float $seconds Default 20 on web requests, 0 under WP-CLI.
			 */
			$slice_seconds = (float) apply_filters( 'perflocale/jobs/bulk_translate/slice_seconds', $cli ? 0 : self::SLICE_SECONDS );

			/**
			 * Filter the most pairs one slice of a background bulk translation
			 * runs. 0 or less: no pair cap.
			 *
			 * @hook perflocale/jobs/bulk_translate/slice_max_pairs
			 * @param int $pairs Default 500 on web requests, 0 under WP-CLI.
			 */
			$slice_max_pairs = (int) apply_filters( 'perflocale/jobs/bulk_translate/slice_max_pairs', $cli ? 0 : self::SLICE_MAX_PAIRS );

			$release_every = $cli ? self::RELEASE_EVERY_PAIRS : 0;
			$window        = $slice_max_pairs > 0 ? $slice_max_pairs : $release_every;
		}

		$start_source = intdiv( $processed, $n_targets );
		$start_target = $processed % $n_targets;

		// Bulk-prime the L1 translation cache for the sources in one SELECT.
		// A slice primes the window its pairs can reach, from its first pair.
		// Without this, get_translation_id() inside the inner loop pays the
		// cold-path cost (~1-2 ms) on every first hit per source — N source
		// posts × that cost is wasted work on sites above the
		// eager-link-map cap. Below the cap this is a no-op (eager map
		// serves these in µs).
		$prime = static function ( int $from_source, int $from_target ) use ( $repo, $source_ids, $n_targets, $window ): void {
			$count = $window > 0
				? intdiv( $from_target + $window + $n_targets - 1, $n_targets )
				: count( $source_ids ) - $from_source;

			$repo->prime_translations(
				\PerfLocale\Enum\ObjectType::Post,
				array_map( 'intval', array_slice( $source_ids, $from_source, $count ) )
			);
		};

		if ( null !== $slice ) {
			$slice_start  = $processed;
			$slice_began  = microtime( true );
			$next_release = $release_every;

			// Checked before each pair. Every slice runs at least one pair.
			$before_pair = static function ( int $source_index, int $target_index ) use ( &$processed, $slice_start, $slice_began, $slice_seconds, $slice_max_pairs, $release_every, &$next_release, $prime ): bool {
				$done = $processed - $slice_start;

				if ( $done < 1 ) {
					return false;
				}

				if ( ( $slice_max_pairs > 0 && $done >= $slice_max_pairs )
					|| ( $slice_seconds > 0 && microtime( true ) - $slice_began >= $slice_seconds )
				) {
					return true;
				}

				// Under WP-CLI, drop the runtime caches every
				// RELEASE_EVERY_PAIRS pairs and prime the next window.
				if ( $release_every > 0 && $done >= $next_release ) {
					\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
					$prime( $source_index, $target_index );
					$next_release = $done + $release_every;
				}

				return false;
			};
		}

		$prime( $start_source, $start_target );

		// Throttle progress emission to cap DB writes: a 1000-row dispatch
		// emitting every row produces 4k+ queries solely for accounting.
		// Tick every 1% (min 1) so we still get smooth progress in the UI
		// without flooding the jobs table with progress UPDATEs.
		// Count-based AND wall-clock-based: the progress callback is the ONLY
		// place the job/type locks refresh and the cancel/pause probes run.
		// Under a slow provider (~93-600s per failed row) a pure count-based
		// gap (total/100 rows) can exceed the 1800s lock TTL — the type lock
		// gets reclaimed mid-run and a second same-type job starts
		// concurrently. One microtime comparison per row is the entire cost.
		$tick_every   = max( 1, (int) floor( $total / 100 ) );
		$last_tick    = -1;
		$last_tick_at = microtime( true );
		$first_tick   = $processed;
		$tick         = static function ( int $done ) use ( &$last_tick, &$last_tick_at, $tick_every, $total, $progress, $first_tick ): void {
			if ( $done === $first_tick
				|| $done === $total
				|| ( $done - $last_tick ) >= $tick_every
				|| ( microtime( true ) - $last_tick_at ) >= 30.0
			) {
				$progress( $done, $total );
				$last_tick    = $done;
				$last_tick_at = microtime( true );
			}
		};

		// A resumed slice reports its starting position first, so the Jobs
		// page bar never goes back.
		$tick( $processed );

		// Suspend the eager link map for the loop: every created link
		// invalidates it, and the next row's get_translation_id read would
		// otherwise rebuild-and-persist the WHOLE map — O(rows × map size).
		// Readers fall back to the JOIN path while suspended; one
		// invalidation after the loop leaves the next request to rebuild a
		// fresh map once.
		\PerfLocale\Database\Repository\TranslationGroupRepository::suspend_eager_link_map();

		// ⭐ SOURCE-BOUNDARY YIELD. SiteTranslateJob needs to stop on a wall
		// clock while keeping a resumable, source-atomic cursor. It used to get
		// that by calling execute() ONCE PER SOURCE — which re-ran everything
		// above per source and measured 10.55x slower on an all-skip chunk
		// (120ms/274 queries -> 1,267ms/1,809 queries for 50 sources), because
		// each call rebuilt the service objects, re-primed, recomputed the
		// progress threshold against a 10-pair total (so every row ticked), and
		// ran its own suspend/resume/invalidate cycle: 300 eager-option SELECTs
		// plus 50 INSERTs and 50 DELETEs.
		//
		// Yielding from INSIDE the loop keeps one context, one prime, one
		// progress policy and one eager-map lifecycle, and still checkpoints
		// only on a fully-processed source.
		//
		// ⚠️ Do NOT instead wrap an outer suspend_eager_link_map() around
		// repeated calls: suspend/resume set a BOOLEAN, not a nesting counter,
		// so the inner resume would end the outer scope early.
		$yield   = $args['yield_after_source'] ?? null;
		$stopped = false;

		try {
			$stopped = $this->run_rows( $source_ids, $target_ids, $lang_by_id, $manager, $service, $settings, $include_meta, $total, $tick, $processed, $created, $skipped, $failed, $first_error, is_callable( $yield ) ? $yield : null, $cursor, $skipped_permission, $kept, $first_warning, $start_source, $start_target, $before_pair, $trigger );
		} finally {
			\PerfLocale\Database\Repository\TranslationGroupRepository::resume_eager_link_map();
			\PerfLocale\Plugin::get_instance()->get( 'group_repo' )->invalidate_eager_link_map();
		}

		// Bound worker memory across slices (runtime cache + SAVEQUERIES log
		// only; persistent cache untouched).
		if ( null !== $slice ) {
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		$result = [
			'created'            => $created,
			'skipped'            => $skipped,
			'skipped_permission' => $skipped_permission,
			'failed'             => $failed,
			'first_error'        => $first_error,
			'kept'               => $kept,
			'first_warning'      => $first_warning,
			'cursor'             => $cursor,
			'processed'          => $processed,
		];

		if ( $stopped ) {
			$result['__perflocale_continue'] = true;
		}

		return $result;
	}

	/**
	 * The counts a slice resumes from, when the stored checkpoint matches
	 * the job.
	 *
	 * It matches when `processed` is a position inside the job and `cursor`
	 * is the last source it fully processed (0 before the first). A caller
	 * that gets null starts again at the first pair, which is safe: pairs
	 * already translated are skipped without a provider call.
	 *
	 * @param array<mixed>    $resume     Result stored by the previous slice.
	 * @param array<int, int> $source_ids Source post ids.
	 * @param int             $n_targets  Number of target languages (> 0).
	 * @return array{processed: int, created: int, skipped: int, skipped_permission: int, failed: int, first_error: string, kept: int, first_warning: string, cursor: int}|null
	 */
	private static function checkpoint( array $resume, array $source_ids, int $n_targets ): ?array {
		$count = static fn( string $key ): int => is_int( $resume[ $key ] ?? null ) ? max( 0, $resume[ $key ] ) : 0;
		$text  = static fn( string $key ): string => is_string( $resume[ $key ] ?? null ) ? $resume[ $key ] : '';

		$processed = is_int( $resume['processed'] ?? null ) ? $resume['processed'] : -1;
		$cursor    = is_int( $resume['cursor'] ?? null ) ? $resume['cursor'] : -1;

		if ( $processed < 0 || $processed >= count( $source_ids ) * $n_targets ) {
			return null;
		}

		$done_sources = intdiv( $processed, $n_targets );

		if ( $cursor !== ( $done_sources > 0 ? (int) $source_ids[ $done_sources - 1 ] : 0 ) ) {
			return null;
		}

		return [
			'processed'          => $processed,
			'created'            => $count( 'created' ),
			'skipped'            => $count( 'skipped' ),
			'skipped_permission' => $count( 'skipped_permission' ),
			'failed'             => $count( 'failed' ),
			'first_error'        => $text( 'first_error' ),
			'kept'               => $count( 'kept' ),
			'first_warning'      => $text( 'first_warning' ),
			'cursor'             => $cursor,
		];
	}

	/**
	 * The bulk row loop — split from execute() so the eager-link-map
	 * suspension can wrap it in a single try/finally.
	 *
	 * @param array<int,int>            $source_ids  Source post IDs.
	 * @param array<int,int>            $target_ids  Target language IDs.
	 * @param array<int,object>         $lang_by_id  Language rows keyed by id.
	 * @param object                    $manager     Post translation manager.
	 * @param object                    $service     MT translation service.
	 * @param object                    $settings    Plugin settings.
	 * @param bool                      $include_meta Whether to translate meta.
	 * @param int                       $total       Total row count (rows × languages).
	 * @param callable                  $tick        Progress emitter.
	 * @param int                       $processed   Running processed count (by ref).
	 * @param int                       $created     Running created count (by ref).
	 * @param int                       $skipped     Running skipped count (by ref).
	 * @param int                       $failed      Running failed count (by ref).
	 * @param string                    $first_error First error message (by ref).
	 * @param callable|null             $yield       Called with the just-completed
	 *                                               source id; returning true stops
	 *                                               the loop at that boundary.
	 * @param int                       $cursor      Last FULLY processed source id (by ref).
	 * @param int               $skipped_permission Part of $skipped refused for permissions (by ref).
	 * @param int               $kept          Part of $created whose content was kept in the
	 *                                         source language (by ref).
	 * @param string            $first_warning Warning of the first such row (by ref).
	 * @param int               $start_source  Index in $source_ids of the first pair to run.
	 * @param int               $start_target  Index in $target_ids of the first pair to run.
	 * @param callable|null     $before_pair   Called with the next pair's source and target
	 *                                         indexes before it runs; returning true stops
	 *                                         the loop before that pair.
	 * @param string            $trigger       The dispatching path; the pairs of a
	 *                                         password-protected source it may not send
	 *                                         (TranslationService::may_send_post()) count
	 *                                         as skipped.
	 * @return bool True when $before_pair stopped the loop.
	 */
	private function run_rows( array $source_ids, array $target_ids, array $lang_by_id, object $manager, object $service, object $settings, bool $include_meta, int $total, callable $tick, int &$processed, int &$created, int &$skipped, int &$failed, string &$first_error, ?callable $yield = null, int &$cursor = 0, int &$skipped_permission = 0, int &$kept = 0, string &$first_warning = '', int $start_source = 0, int $start_target = 0, ?callable $before_pair = null, string $trigger = 'bulk' ): bool {
		// `perflocale_use_mt` is the capability that authorises SPENDING the
		// provider; `perflocale_manage_translations` — the one WorkerRegistry
		// re-validates at worker time — only authorises running the job. A queued
		// bulk run can sit for hours, so evaluate the MT capability here instead
		// of trusting the dispatch-time decision. Evaluated ONCE (the identity
		// cannot change inside the loop) and folded into the existing per-row
		// gate, so a revoked capability produces exactly the "skipped" accounting
		// an unauthorised post already produces rather than a new failure shape.
		$can_use_mt = current_user_can( 'perflocale_use_mt' );

		// Say WHY the run did nothing. Without this the job completes with every
		// row "skipped" and an empty error, which reads as "there was nothing to
		// translate" rather than "the dispatching user lost the capability" — the
		// operator has no way to tell those apart from the Jobs page or the REST
		// detail endpoint.
		if ( ! $can_use_mt && $first_error === '' ) {
			$first_error = __( 'Machine translation is not permitted for the dispatching user (perflocale_use_mt).', 'perflocale' );
		}

		foreach ( array_slice( $source_ids, $start_source, null, true ) as $source_index => $source_id ) {
			// A resumed slice starts inside its first source.
			$targets = ( $source_index === $start_source && $start_target > 0 )
				? array_slice( $target_ids, $start_target, null, true )
				: $target_ids;

			// Re-check the per-row capability inside the worker. The
			// dispatch-side capability gate is `perflocale_manage_translations`;
			// individual posts still respect `edit_post` so a user can't
			// trigger MT for content they couldn't otherwise edit.
			if ( ! $can_use_mt || ! current_user_can( 'edit_post', $source_id ) ) {
				if ( null !== $before_pair && $before_pair( $source_index, (int) array_key_first( $targets ) ) ) {
					return true;
				}

				$skipped            += count( $targets );
				$skipped_permission += count( $targets );
				$processed          += count( $targets );
				$tick( $processed );

				// A source skipped for permissions is still FULLY processed —
				// the cursor must pass it or the chain re-reads it forever.
				$cursor = (int) $source_id;

				if ( $yield !== null && $yield( (int) $source_id, $processed ) ) {
					return false;
				}

				continue;
			}

			// Whether the dispatching user may have translations of this source
			// copied from the group's default-language post. Resolved once per
			// source, at the first row that would create a translation, and
			// reused for its remaining targets. A later row can add the
			// default-language member itself; that copy derives from $source_id,
			// which the user may edit, so the answer stays sound.
			$copy_allowed = null;

			// Whether this run may send this source, resolved at its first row
			// that would be translated, so a source whose rows all exist costs
			// nothing.
			$send_allowed = null;

			foreach ( $targets as $target_index => $target_lang_id ) {
				if ( null !== $before_pair && $before_pair( $source_index, $target_index ) ) {
					return true;
				}

				$target_lang = $lang_by_id[ $target_lang_id ] ?? null;

				if ( $target_lang === null ) {
					// Unresolvable language id: count as FAILED (silently
					// dropping it let a bogus-language site-wide chain grind
					// through every chunk reporting all-green zeros).
					++$failed;
					if ( $first_error === '' ) {
						$first_error = sprintf(
							/* translators: %d: language ID. */
							__( 'Unknown target language id %d.', 'perflocale' ),
							$target_lang_id
						);
					}
					++$processed;
					$tick( $processed );
					continue;
				}

				// Don't overwrite an existing translation.
				if ( $manager->get_translation_id( $source_id, $target_lang->slug ) !== null ) {
					++$skipped;
					++$processed;
					$tick( $processed );
					continue;
				}

				// The row creates a translation, which is copied from the group's
				// default-language post rather than necessarily from $source_id,
				// so edit_post on $source_id above is not enough. A 0 answer
				// (nothing would be copied) is not remembered: it can depend on
				// the target.
				if ( $copy_allowed === null ) {
					$copy_from = $manager->get_copy_source_id( (int) $source_id, (string) $target_lang->slug );

					if ( $copy_from > 0 ) {
						$copy_allowed = $copy_from === (int) $source_id || \PerfLocale\Helper::user_can_copy_translation_source( $copy_from );
					}
				}

				if ( $copy_allowed === false ) {
					++$skipped;
					++$skipped_permission;
					++$processed;
					$tick( $processed );

					if ( $first_error === '' ) {
						$first_error = __( 'You cannot edit the original this translation is copied from.', 'perflocale' );
					}

					continue;
				}

				if ( null === $send_allowed ) {
					$source_post  = get_post( (int) $source_id );
					$send_allowed = ! $source_post instanceof \WP_Post || TranslationService::may_send_post( $source_post, $trigger );
				}

				if ( ! $send_allowed ) {
					++$skipped;
					++$processed;
					$tick( $processed );
					continue;
				}

				$row_context = [
					'source_id'   => (int) $source_id,
					'target_slug' => (string) $target_lang->slug,
					'target_id'   => (int) $target_lang->id,
					'provider'    => (string) ( $settings->get( 'mt_provider', '' ) ?: '' ),
					'processed'   => $processed,
					'total'       => $total,
				];

				/**
				 * Short-circuit a single (source post, target language) row
				 * before the default machine-translation call.
				 *
				 * Match WordPress's `pre_*` filter convention: return `null`
				 * (the default) to let the regular flow run. Return ANY other
				 * value to skip the default `TranslationService::translate_post()`
				 * call for this row — the returned value is treated as the
				 * row's result:
				 *
				 *   - `[ 'post_id' => int ]`      — counted as `created`.
				 *   - `'skip'` or any non-array   — counted as `skipped`.
				 *   - `false`                     — counted as `skipped`.
				 *
				 * Useful for per-row policy decisions: skip products on a
				 * lock-list, route specific languages through a different
				 * provider, attach pre-translation metadata, etc.
				 *
				 * @hook  perflocale/mt/bulk/before_translate
				 * @since 1.0.0
				 *
				 * @param mixed $pre `null` to run the default flow; any other
				 *                   value to short-circuit and use as the
				 *                   row's result.
				 * @param int    $source_id   The source post ID.
				 * @param string $target_slug The target language slug.
				 * @param array  $context     Row metadata: target_id, provider,
				 *                            processed-so-far, total.
				 */
				$pre = apply_filters(
					'perflocale/mt/bulk/before_translate',
					null,
					(int) $source_id,
					(string) $target_lang->slug,
					$row_context
				);

				if ( $pre !== null ) {
					if ( is_array( $pre ) && ! empty( $pre['post_id'] ) ) {
						++$created;
					} else {
						++$skipped;
					}

					/** This action is documented below the regular code path. */
					do_action(
						'perflocale/mt/bulk/after_translate',
						(int) $source_id,
						(string) $target_lang->slug,
						is_array( $pre ) ? $pre : [],
						array_merge(
							$row_context,
							[
								'short_circuited' => true,
								'error'           => '',
							]
						)
					);

					++$processed;
					$tick( $processed );
					continue;
				}

				$row_result = [];
				$row_error  = '';

				try {
					// fast_fail=false lets the provider's retry loop
					// catch transient errors (rate limit, network blip).
					$row_result = $service->translate_post( $source_id, $target_lang->slug, '', false, $include_meta );

					if ( ! empty( $row_result['post_id'] ) ) {
						++$created;

						// A lost "Do not translate" section keeps the source
						// content; the row counts as created and is reported.
						if ( ! empty( $row_result['content_kept_source'] ) ) {
							++$kept;

							$row_warnings = array_filter( (array) ( $row_result['warnings'] ?? [] ), 'is_string' );

							if ( $first_warning === '' && $row_warnings !== [] ) {
								$first_warning = (string) end( $row_warnings );
							}
						}
					} else {
						++$failed;
						$row_error = __( 'Provider returned no post ID', 'perflocale' );

						if ( $first_error === '' ) {
							$first_error = $row_error;
						}
					}
				} catch ( \Throwable $e ) {
					++$failed;
					$row_error = $e->getMessage();

					if ( $first_error === '' ) {
						$first_error = $row_error;
					}
				}

				/**
				 * Fires after each (source post, target language) row, whether
				 * the translation succeeded, failed, or was short-circuited
				 * by `perflocale/mt/bulk/before_translate`.
				 *
				 * Use for per-row observability: emit a metric, push to a
				 * monitoring pipeline, write a workflow event, etc.
				 *
				 * @hook  perflocale/mt/bulk/after_translate
				 * @since 1.0.0
				 *
				 * @param int    $source_id   Source post ID.
				 * @param string $target_slug Target language slug.
				 * @param array  $result      The TranslationService result
				 *                            array (`['post_id' => int]` on
				 *                            success). Empty array on failure.
				 * @param array  $context     Row metadata. Includes `error`
				 *                            (string, empty on success),
				 *                            `short_circuited` (bool — was
				 *                            this from before_translate?),
				 *                            and the keys passed to the
				 *                            before_translate filter.
				 */
				do_action(
					'perflocale/mt/bulk/after_translate',
					(int) $source_id,
					(string) $target_lang->slug,
					is_array( $row_result ) ? $row_result : [],
					array_merge(
						$row_context,
						[
							'short_circuited' => false,
							'error'           => $row_error,
						]
					)
				);

				++$processed;
				$tick( $processed );
			}

			// Every target for this source has been attempted — only now is it
			// safe to move the cursor past it.
			$cursor = (int) $source_id;

			if ( $yield !== null && $yield( (int) $source_id, $processed ) ) {
				return false;
			}
		}

		return false;
	}
}
