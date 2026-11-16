<?php
/**
 * Bulk machine-translation job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\MachineTranslation\CallDeadline;
use PerfLocale\MachineTranslation\DeadlineReachedException;
use PerfLocale\MachineTranslation\NotSavedException;
use PerfLocale\MachineTranslation\TargetLanguageGuard;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine-translates source posts (`source_ids`) into languages (`target_lang_ids`), skipping a pair that has a
 * translation: inline below the threshold, else as a background job run in slices. Args, triggers and slices:
 * docs-ai/11-code-rationale.md, "BulkTranslateJob: args and slices".
 */
final class BulkTranslateJob extends AbstractJob {

	/**
	 * Wall-clock budget of one web slice, in seconds: margin under a 30 s limit (SiteTranslateJob's chunk budget too).
	 */
	private const SLICE_SECONDS = 20;

	/**
	 * Most pairs one web slice runs: a backstop for work so fast that the clock never binds.
	 */
	private const SLICE_MAX_PAIRS = 500;

	/**
	 * Pairs between two releases under WP-CLI (no slice budget there): each settles the target-language guard and drops
	 * the runtime caches, bounding a long run's memory.
	 */
	private const RELEASE_EVERY_PAIRS = TargetLanguageGuard::SETTLE_EVERY;

	/**
	 * Provider waits in a row (Retry-After or breaker cooldown, no answer between) after which the job stops.
	 */
	private const PACED_STOPS = 10;

	/**
	 * Notes about pairs an earlier stopped run left that a result keeps (`redo_notes`); the rest are counted.
	 */
	private const REDO_NOTES = 10;

	/**
	 * Bytes those notes may take: the result also carries a slice's kept answers within JobState::MAX_RESULT_BYTES.
	 */
	private const REDO_NOTES_BYTES = 8192;

	/**
	 * The run's guard against a target language deleted or deactivated while it runs (TargetLanguageGuard).
	 *
	 * @var TargetLanguageGuard|null
	 */
	private ?TargetLanguageGuard $guard = null;

	/**
	 * Unix time a slice stopped by a provider wait continues at; 0 for at once.
	 *
	 * @var int
	 */
	private int $continue_at = 0;

	/**
	 * The slice stopped at the provider deadline inside a pair (not a rate-limit wait).
	 *
	 * @var bool
	 */
	private bool $unit_stopped = false;

	/**
	 * Position of the pair a provider deadline stopped this job inside (-1: none); its young pending mark is this
	 * job's.
	 *
	 * @var int
	 */
	private int $cut = -1;

	/**
	 * Translations marked pending (TranslationService::PENDING_META_KEY), read once per run: pending_translations().
	 *
	 * @var array<int, bool|null>|null
	 */
	private ?array $pending = null;

	/**
	 * Provider answers kept for the pair a stop left unfinished: post id, language slug, packed answers and their count.
	 *
	 * @var array{post: int, lang: string, data: string, n: int}|null
	 */
	private ?array $pieces = null;

	/**
	 * Failure message for a deadline stop at the same call (JobResume::count_stop()); '' for the general one.
	 *
	 * @var string
	 */
	private string $stop_note = '';

	/**
	 * What a checkpoint write inside a provider call threw (a pause, a cancel, a refused write): thrown again once the
	 * slice stopped, so it reaches the worker and not the pair's error handling.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $abort = null;

	/**
	 * The one pair this job may overwrite (the `overwrite` arg PiecesHandOff::overwrite_args() wrote), or null.
	 *
	 * @var array{post: int, lang: int, translation: int, modified: string, sum: string}|null
	 */
	private ?array $overwrite = null;

	/**
	 * The pair's answers no longer fit the job row's result cap: the next slice re-sends the ones not kept.
	 *
	 * @var bool
	 */
	private bool $kept_full = false;

	/**
	 * Provider waits in a row since the last answer or finished pair.
	 *
	 * @var int
	 */
	private int $paced = 0;

	/**
	 * Why the job stops for good at the current pair (checkpoint kept for Retry); '' for none.
	 *
	 * @var string
	 */
	private string $job_stop = '';

	/**
	 * This run's notes about pairs an earlier stopped run left (redo note, kept edit), in row order.
	 *
	 * @var list<string>
	 */
	private array $redo_notes = [];

	/**
	 * Notes not kept in $redo_notes (over REDO_NOTES or REDO_NOTES_BYTES).
	 *
	 * @var int
	 */
	private int $redo_more = 0;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'bulk_translate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The Translations bulk action's capability, so the worker's re-check matches the dispatch gate.
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Below 25 pairs the loop runs inline with headroom under max_execution_time; above, it goes async to avoid PHP-FPM
	 * timeouts. Tunable under Settings > Performance > Background Thresholds.
	 */
	public function get_default_threshold(): int {
		return 25;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A hand-off of one post into one language (PiecesHandOff) needs what translating it in the request needs: every pair
	 * is checked again as the requester when it runs (edit rights, copy and create rights, an overwrite's edit_post).
	 */
	public function required_capability_for( array $args ): string {
		return \PerfLocale\Background\JobAccess::is_handoff( $this->get_type(), $args ) ? 'perflocale_use_mt' : $this->get_required_capability();
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function resumes_after_kill(): bool {
		return true;
	}

	/** {@inheritDoc} */
	protected function args_size( array $args ): int {
		$sources = is_array( $args['source_ids'] ?? null ) ? $args['source_ids'] : [];
		$targets = is_array( $args['target_lang_ids'] ?? null ) ? $args['target_lang_ids'] : [];

		return count( $sources ) * count( $targets );
	}

	/**
	 * Run (source x target) translations: translate_post() per pair, `fast_fail=false` so a retryable error does not
	 * abort the batch. A worker call (`__perflocale_slice`) is one slice: it resumes from the stored counts and, when
	 * its budget runs out, returns the counts with `__perflocale_continue => true`.
	 *
	 * @param array<string, mixed> $args     `source_ids` + `target_lang_ids` per the class-level docblock.
	 * @param callable             $progress `function(int, int): void` - reports (processed, total).
	 * @return array<string, mixed> `created`, `skipped` (of which `skipped_permission` were refused for permissions),
	 *                               `failed`, `first_error`, `kept` (created rows left in the source language because a
	 *                               "Do not translate" section was lost), `first_warning` (the first such warning),
	 *                               and `redo_notes` / `redo_notes_more` when pairs an earlier stopped run left need a check.
	 * @throws \RuntimeException When machine translation is disabled for the site
	 *                            after the job was queued.
	 */
	public function execute( array $args, callable $progress ): array {
		// Meta translation is opt-in per dispatch (the key registry is also setting-gated, so true with all off is a
		// no-op).
		$include_meta = ! empty( $args['include_meta'] );

		// Which path dispatched the run; only these values are accepted.
		$trigger = in_array( $args['trigger'] ?? null, [ 'site_translate', 'cli_all', 'rest', 'auto_create' ], true ) ? (string) $args['trigger'] : 'bulk';

		// The perflocale/mt/include_meta path per row; '' for a hand-off, whose path already decided.
		$meta_path = empty( $args['handoff'] ) ? ( 'cli_all' === $trigger ? 'cli' : 'bulk' ) : '';

		$this->overwrite = self::overwrite_arg( $args['overwrite'] ?? null );

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

		// The master switch is re-checked at worker time: a run can wait for hours, and turning machine translation off
		// must stop its spending (WorkerRunner re-checks only the capability). Same exception as
		// BulkStringTranslateJob::execute(); SiteTranslateJob's chunks pass here too.
		if ( ! $settings->mt_enabled() ) {
			throw new \RuntimeException( esc_html__( 'Machine translation is disabled in settings.', 'perflocale' ) );
		}

		// Language id -> row once, so the loop only looks up.
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
		// Last fully processed source id.
		$cursor = 0;

		// Pairs a priming window covers; 0 covers every remaining source.
		$window          = 0;
		$before_pair     = null;
		$slice_seconds   = 0.0;
		$slice_max_pairs = 0;
		$deadline        = null;
		$window_seconds  = null;
		$release_every   = 0;
		$run_guard       = new TargetLanguageGuard();
		$slice           = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : null;
		$resume          = [];
		$point           = null;

		if ( null !== $slice ) {
			// Resume from the previous slice's checkpoint: pairs run in a fixed order (sources, then each source's
			// targets), so `processed` alone is the position.
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

			[ $slice_seconds, $slice_max_pairs, $deadline, $window_seconds ] = self::slice_budget( $cli );

			$release_every = $cli ? self::RELEASE_EVERY_PAIRS : 0;
			$window        = $slice_max_pairs > 0 ? $slice_max_pairs : $release_every;
		}

		$start_source = intdiv( $processed, $n_targets );
		$start_target = $processed % $n_targets;

		// Prime the translation cache for the window this slice can reach in one SELECT; above the eager-link-map cap
		// each cold get_translation_id() costs about 1-2 ms per source (below it this is a no-op).
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
			$before_pair = static function ( int $source_index, int $target_index ) use ( &$processed, &$created, &$failed, &$first_error, $slice_start, $slice_began, $slice_seconds, $slice_max_pairs, $release_every, &$next_release, $prime, $run_guard ): bool {
				$done = $processed - $slice_start;

				if ( $done < 1 ) {
					return false;
				}

				if ( ( $slice_max_pairs > 0 && $done >= $slice_max_pairs )
					|| ( $slice_seconds > 0 && microtime( true ) - $slice_began >= $slice_seconds )
				) {
					return true;
				}

				// Under WP-CLI, every RELEASE_EVERY_PAIRS pairs: settle the translations written since, drop the
				// runtime caches and prime the next window. A change another process made counts as a checkpoint
				// change: the next pair re-reads its target.
				if ( $release_every > 0 && $done >= $next_release ) {
					self::settle_guard( $run_guard, $created, $failed, $first_error );

					if ( \PerfLocale\Background\MigrationCacheHelper::release_batch_memory() ) {
						$run_guard->changed_elsewhere();
					}

					$prime( $source_index, $target_index );
					$next_release = $done + $release_every;
				}

				return false;
			};
		}

		$prime( $start_source, $start_target );

		// Progress every 1% and by clock: a tick per row costs 4k+ queries on 1000 rows, and the tick is the only place
		// the locks refresh and cancel/pause probes run, so a slow provider (93-600 s per failed row) must not outlast
		// the 1800 s lock TTL between ticks.
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

		// A resumed slice reports its starting position first, so the Jobs page bar never goes back.
		$tick( $processed );

		// Suspend the eager link map for the loop: every created link invalidates it, and rebuilding it per row is
		// O(rows x map size). Readers use the JOIN path meanwhile; one invalidation after the loop leaves one rebuild.
		\PerfLocale\Database\Repository\TranslationGroupRepository::suspend_eager_link_map();

		// Source-boundary yield for SiteTranslateJob: one loop lifecycle, a checkpoint only after a whole source; never
		// an outer suspend_eager_link_map() around repeated calls (a boolean, not a counter).
		// Why: docs-ai/11-code-rationale.md, "BulkTranslateJob: the source-boundary yield".
		$yield   = $args['yield_after_source'] ?? null;
		$stopped = false;

		$this->guard       = $run_guard;
		$this->continue_at  = 0;
		$this->unit_stopped = false;
		$this->pending     = null;
		$this->stop_note    = '';
		$this->abort        = null;
		$this->pieces       = null !== $point ? self::stored_pieces( $resume ) : null;
		$this->paced        = null !== $point && is_int( $resume['__perflocale_paced'] ?? null ) ? max( 0, $resume['__perflocale_paced'] ) : 0;
		$this->job_stop     = '';

		[ $this->redo_notes, $this->redo_more ] = null !== $point ? self::stored_notes( $resume ) : [ [], 0 ];

		// The latency rule reads this job's recent call times, or the one a hand-off measured (never its answers).
		CallDeadline::times( $resume['__perflocale_latency'] ?? ( is_int( $args['latency'] ?? null ) ? [ $args['latency'] ] : [] ) );
		$this->cut          = null !== $point ? self::cut_at( $resume, $slice ?? [], $processed ) : -1;
		$previous_deadline = null !== $deadline ? CallDeadline::set( $deadline, $window_seconds ) : null;

		// A worker slice reports its checkpoint before each pair it sends (that pair's position) and after each provider
		// answer it keeps for the pair; one that would not fit the row's result cap is not written ($must false).
		$job  = $this;
		$save = null === $slice ? null : static function ( int $source_id, string $slug, ?array $pieces = null, bool $must = true ) use ( &$processed, &$created, &$skipped, &$skipped_permission, &$failed, &$first_error, &$kept, &$first_warning, $progress, $total, $source_ids, $n_targets, $job ): bool {
			$point = self::with_pieces( self::checkpoint_of( $processed, $created, $skipped, $skipped_permission, $failed, $first_error, $kept, $first_warning, $source_ids, $n_targets ) + $job->notes() + $job->run_state(), $pieces );

			if ( null === $point[0] && ! $must ) {
				return false;
			}

			$progress(
				$processed,
				$total,
				[
					'checkpoint' => $point[0] ?? $point[1],
					/* translators: 1: post id, 2: target language slug. Names the item a background job was on. */
					'unit'       => sprintf( __( 'post %1$d into %2$s', 'perflocale' ), $source_id, $slug ),
				]
			);

			return null !== $point[0];
		};

		// Content ReSync's captures of the loop's translations are written in batches, one commit each (SyncRecorder).
		$capture = \PerfLocale\Util\ResyncSwitch::on();

		if ( $capture ) {
			\PerfLocale\Resync\SyncRecorder::begin_capture_batch();
		}

		try {
			$stopped = $this->run_rows( $source_ids, $target_ids, $lang_by_id, $manager, $service, $settings, $include_meta, $total, $tick, $processed, $created, $skipped, $failed, $first_error, is_callable( $yield ) ? $yield : null, $cursor, $skipped_permission, $kept, $first_warning, $start_source, $start_target, $before_pair, $trigger, $save, $meta_path );
		} finally {
			if ( $capture ) {
				\PerfLocale\Resync\SyncRecorder::end_capture_batch();
			}

			if ( null !== $deadline ) {
				CallDeadline::set( $previous_deadline );
			}

			\PerfLocale\Database\Repository\TranslationGroupRepository::resume_eager_link_map();
			\PerfLocale\Plugin::get_instance()->get( 'group_repo' )->invalidate_eager_link_map();
			$this->settle( $created, $failed, $first_error );
			$this->guard = null;
		}

		if ( null !== $this->abort ) {
			throw $this->abort;
		}

		// A hand-off's requester sees a failed or kept pair on the post's edit screen.
		if ( ! $stopped && ! empty( $args['handoff'] ) && 1 === $total ) {
			$item = $failed > 0 ? [ 'reason' => $first_error ] : [ 'reason' => $first_warning, 'warning' => true ];

			$language = $lang_by_id[ $target_ids[0] ] ?? null;

			self::notify( get_current_user_id(), $source_ids[0], is_object( $language ) ? $language : null, $item );
		}

		// Bound worker memory across slices (runtime cache and SAVEQUERIES log only; persistent cache untouched).
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
		] + $this->notes();

		// Stopped for good (provider key refused, too many waits, too slow for a run): failed with the checkpoint and the
		// kept answers, so Retry continues at this pair; it starts without the waits and call times.
		if ( $stopped && '' !== $this->job_stop ) {
			$point  = self::with_pieces( $result + [ 'cut' => $processed ], $this->pieces );
			$result = $point[0] ?? $point[1];

			$result['run_failed']                 = true;
			$result['__perflocale_checkpoint']    = true;
			$result['__perflocale_failed_reason'] = $this->job_stop;

			return $result;
		}

		if ( $stopped ) {
			$result['__perflocale_continue'] = true;
			$result                         += $this->run_state();

			if ( $this->continue_at > 0 ) {
				$result['__perflocale_continue_at'] = $this->continue_at;
			}

			// The worker counts it like a kill at this pair.
			if ( $this->unit_stopped ) {
				$result['__perflocale_unit_stop'] = true;
			}

			// The next slice redoes the pair the deadline stopped inside, under its own young mark, with the answers kept.
			if ( $this->cut === $processed ) {
				$result['cut'] = $this->cut;
				$result        = self::with_pieces( $result, $this->pieces )[0] ?? $result;
			}

			if ( $this->unit_stopped && '' !== $this->stop_note ) {
				$result['__perflocale_stop_note'] = $this->stop_note;
			}
		}

		return $result;
	}

	/**
	 * [ $point with the kept answers, $point ]; the first is null when they would pass the row's result cap (with room
	 * for the resume state), or there are none.
	 *
	 * @param array<string, mixed> $point  Checkpoint.
	 * @param array<mixed>|null    $pieces Kept answers (answers()).
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
	 */
	private static function with_pieces( array $point, ?array $pieces ): array {
		if ( null === $pieces ) {
			return [ $point, $point ];
		}

		$with = $point + [
			'pieces'                                    => $pieces,
			\PerfLocale\Background\JobResume::PARTIAL_KEY => is_int( $pieces['n'] ?? null ) ? $pieces['n'] : 0,
		];
		$json = wp_json_encode( $with );

		return [ is_string( $json ) && strlen( $json ) + 2048 <= \PerfLocale\Background\JobState::MAX_RESULT_BYTES ? $with : null, $point ];
	}

	/**
	 * The result's `redo_notes`, and `redo_notes_more` when some were not kept (each only when there is one).
	 *
	 * @return array<string, mixed>
	 */
	private function notes(): array {
		return array_filter(
			[
				'redo_notes'      => $this->redo_notes,
				'redo_notes_more' => $this->redo_more,
			]
		);
	}

	/**
	 * The notes a checkpoint carries: [ notes, count not kept ], within the caps.
	 *
	 * @param array<mixed> $resume The stored checkpoint.
	 * @return array{0: list<string>, 1: int}
	 */
	private static function stored_notes( array $resume ): array {
		$notes = array_values( array_filter( is_array( $resume['redo_notes'] ?? null ) ? $resume['redo_notes'] : [], 'is_string' ) );
		$more  = is_int( $resume['redo_notes_more'] ?? null ) ? max( 0, $resume['redo_notes_more'] ) : 0;

		return [ array_slice( $notes, 0, self::REDO_NOTES ), $more + max( 0, count( $notes ) - self::REDO_NOTES ) ];
	}

	/**
	 * Keep a note about a pair an earlier stopped run left; one over the caps is counted only.
	 *
	 * @param string $note Note.
	 * @return void
	 */
	private function note_redo( string $note ): void {
		if ( count( $this->redo_notes ) < self::REDO_NOTES && strlen( (string) wp_json_encode( [ ...$this->redo_notes, $note ] ) ) <= self::REDO_NOTES_BYTES ) {
			$this->redo_notes[] = $note;
		} else {
			++$this->redo_more;
		}
	}

	/**
	 * Run state the next slice reads: recent call times and provider waits in a row (only when there are any).
	 *
	 * @return array<string, mixed>
	 */
	private function run_state(): array {
		return array_filter(
			[
				'__perflocale_latency' => CallDeadline::times(),
				'__perflocale_paced'   => $this->paced,
			]
		);
	}

	/**
	 * The kept answers a checkpoint carries, or null.
	 *
	 * @param array<mixed> $resume The stored checkpoint.
	 * @return array{post: int, lang: string, data: string, n: int}|null
	 */
	private static function stored_pieces( array $resume ): ?array {
		$p = $resume['pieces'] ?? null;

		return is_array( $p ) && is_int( $p['post'] ?? null ) && is_string( $p['lang'] ?? null ) && is_string( $p['data'] ?? null ) && is_int( $p['n'] ?? null )
			? [
				'post' => $p['post'],
				'lang' => $p['lang'],
				'data' => $p['data'],
				'n'    => $p['n'],
			]
			: null;
	}

	/**
	 * The answers kept for a pair: the stored ones when they are this pair's (others are dropped), and each new one
	 * written into the job's row by $checkpoint, while they fit.
	 *
	 * @param int      $source_id  Source post id.
	 * @param string   $slug       Target language slug.
	 * @param object   $settings   Plugin settings.
	 * @param callable $checkpoint The slice's checkpoint writer.
	 * @return \PerfLocale\MachineTranslation\PieceAnswers
	 */
	private function answers( int $source_id, string $slug, object $settings, callable $checkpoint ): \PerfLocale\MachineTranslation\PieceAnswers {
		require_once __DIR__ . '/../../MachineTranslation/PieceAnswers.php';

		$stored = null !== $this->pieces && $this->pieces['post'] === $source_id && $this->pieces['lang'] === $slug ? $this->pieces : null;
		$why    = null === $stored ? '' : \PerfLocale\MachineTranslation\PieceAnswers::unreadable( $stored['data'], $stored['n'] );

		if ( '' !== $why ) {
			throw new \RuntimeException(
				esc_html(
					'zlib' === $why
						? sprintf(
							/* translators: %d: post ID */
							__( 'Bulk translation stopped at post %d: the answers the earlier run saved for it cannot be read, as PHP on this server has no zlib extension. Ask your host to enable zlib, then resume the job (resuming without it would pay the provider again).', 'perflocale' ),
							$source_id
						)
						: sprintf(
							/* translators: %d: post ID */
							__( 'Bulk translation stopped at post %d: the answers the earlier run saved for it are damaged and cannot be read. Cancel this job and start it again to translate the post anew (the provider is paid again for it).', 'perflocale' ),
							$source_id
						)
				)
			);
		}

		$this->pieces = $stored;
		$this->kept_full = false;

		return new \PerfLocale\MachineTranslation\PieceAnswers(
			null === $stored ? [] : \PerfLocale\MachineTranslation\PieceAnswers::unpack( $stored['data'] ),
			$settings,
			function ( \PerfLocale\MachineTranslation\PieceAnswers $answers ) use ( $source_id, $slug, $checkpoint ): void {
				$this->paced = 0;

				if ( $this->kept_full ) {
					return;
				}

				$entry = [
					'post' => $source_id,
					'lang' => $slug,
					'data' => $answers->pack(),
					'n'    => $answers->count(),
				];

				try {
					$written = '' !== $entry['data'] && $checkpoint( $source_id, $slug, $entry, false );
				} catch ( \Throwable $e ) {
					// Stops the slice here; execute() throws it again.
					$this->abort = $e;

					throw new DeadlineReachedException();
				}

				// Once one does not fit, none does: the map only grows.
				if ( $written ) {
					$this->pieces = $entry;
				} else {
					$this->kept_full = true;
				}
			}
		);
	}

	/**
	 * Show a hand-off's failure or warning to its requester on the source's edit screen (MtCreateNotice).
	 *
	 * @param int                  $user_id   Requester.
	 * @param int                  $source_id Source post id.
	 * @param object|null          $language  Target language row.
	 * @param array<string, mixed> $item      `reason` ('' for none), and `warning` for a saved or kept translation.
	 * @return void
	 */
	private static function notify( int $user_id, int $source_id, ?object $language, array $item ): void {
		if ( '' === ( $item['reason'] ?? '' ) ) {
			return;
		}

		$vars = null !== $language ? get_object_vars( $language ) : [];
		$name = $vars['name'] ?? ( $vars['slug'] ?? '' );

		\PerfLocale\Admin\MtCreateNotice::remember_publish( $user_id, $source_id, [ $item + [ 'language' => is_string( $name ) ? $name : '' ] ] );
	}

	/**
	 * End of a hand-off run that failed for good ({@see JobState} run_end): its requester is told on the post's screen.
	 *
	 * @param array<string, mixed> $row Job row.
	 * @return void
	 */
	public static function run_ended( array $row ): void {
		$args    = is_array( $row['args'] ?? null ) ? $row['args'] : [];
		$sources = is_array( $args['source_ids'] ?? null ) ? $args['source_ids'] : [];
		$targets = is_array( $args['target_lang_ids'] ?? null ) ? $args['target_lang_ids'] : [];

		if ( 'failed' !== ( $row['status'] ?? null ) || empty( $args['handoff'] ) || 1 !== count( $sources ) || 1 !== count( $targets ) || ! is_numeric( $sources[0] ) || ! is_numeric( $targets[0] ) ) {
			return;
		}

		$repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang = $repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $repo->find( (int) $targets[0] ) : null;

		self::notify( is_int( $row['created_by'] ?? null ) ? $row['created_by'] : 0, (int) $sources[0], $lang, [ 'reason' => is_string( $row['error'] ?? null ) ? $row['error'] : '' ] );
	}

	/**
	 * A valid `overwrite` arg, or null.
	 *
	 * @param mixed $arg The arg.
	 * @return array{post: int, lang: int, translation: int, modified: string, sum: string}|null
	 */
	private static function overwrite_arg( mixed $arg ): ?array {
		if ( ! is_array( $arg ) || ! is_int( $arg['post'] ?? null ) || ! is_int( $arg['lang'] ?? null ) || ! is_int( $arg['translation'] ?? null ) || ! is_string( $arg['modified'] ?? null ) || ! is_string( $arg['sum'] ?? null ) ) {
			return null;
		}

		return [
			'post'        => $arg['post'],
			'lang'        => $arg['lang'],
			'translation' => $arg['translation'],
			'modified'    => $arg['modified'],
			'sum'         => $arg['sum'],
		];
	}

	/**
	 * Whether a pair with a translation is overwritten: 'ok' for the `overwrite` pair while its translation is the one
	 * queued, unchanged, and the requester (the job runs as them) may edit it; else 'denied', 'changed' or 'none' (any
	 * other pair), with the reason.
	 *
	 * @param int         $source_id Source post id.
	 * @param int         $lang_id   Target language id.
	 * @param object      $manager   Post translation manager.
	 * @param object|null $language  Language row.
	 * @return array{0: string, 1: string}
	 */
	private function may_overwrite( int $source_id, int $lang_id, object $manager, ?object $language ): array {
		$o = $this->overwrite;

		if ( null === $o || $o['post'] !== $source_id || $o['lang'] !== $lang_id ) {
			return [ 'none', '' ];
		}

		if ( ! current_user_can( 'edit_post', $o['translation'] ) ) {
			return [ 'denied', __( 'You do not have permission to overwrite this translation.', 'perflocale' ) ];
		}

		require_once __DIR__ . '/../../MachineTranslation/PiecesHandOff.php';

		$slug  = null !== $language ? TargetLanguageGuard::slug_of( $language ) : '';
		$now   = '' !== $slug && method_exists( $manager, 'get_translation_id' ) ? $manager->get_translation_id( $source_id, $slug ) : null;
		$state = \PerfLocale\MachineTranslation\PiecesHandOff::state( $o['translation'] );

		if ( is_numeric( $now ) && (int) $now === $o['translation'] && [ 'modified' => $o['modified'], 'sum' => $o['sum'] ] === $state ) {
			return [ 'ok', '' ];
		}

		return [
			'changed',
			sprintf(
				/* translators: 1: ID of the translation post, 2: ID of the source post. */
				__( 'The translation (post %1$d) of post %2$d changed after this job was queued, so it was kept as it is. Translate it again to replace it.', 'perflocale' ),
				$o['translation'],
				$source_id
			),
		];
	}

	/**
	 * Count a provider wait; the job stop reason once PACED_STOPS come in a row, else ''.
	 *
	 * @param int $wait Seconds the provider asked to wait.
	 * @return string
	 */
	private function paced_stop( int $wait ): string {
		if ( ++$this->paced < self::PACED_STOPS ) {
			return '';
		}

		return sprintf(
			/* translators: 1: number of waits, 2: seconds of the last wait */
			__( 'The provider asked to wait %1$d times in a row (rate limit; last wait %2$d seconds), so the job was stopped. Retry it later from the Jobs screen or with wp perflocale jobs retry.', 'perflocale' ),
			self::PACED_STOPS,
			$wait
		);
	}

	/**
	 * A provider state no pair can get past: a refused key stops the job (the reason), an open breaker is waited for
	 * (continue_at set, counted as a provider wait); '' and no wait for any other failure.
	 *
	 * @param \Throwable $e The pair's failure.
	 * @return string
	 */
	private function provider_stop( \Throwable $e ): string {
		$auth = in_array( $e->getCode(), [ 401, 403 ], true ) && ! $e instanceof NotSavedException;

		if ( $e instanceof \PerfLocale\Concurrency\BreakerOpenException ) {
			$status = \PerfLocale\Concurrency\Breaker::status( $e->get_breaker_key() );
			$auth   = 'auth' === ( $status['reason'] ?? '' );

			if ( ! $auth ) {
				$wait              = max( 1, is_int( $status['cooldown_remaining'] ?? null ) ? $status['cooldown_remaining'] : 1 );
				$this->continue_at = time() + $wait;

				return $this->paced_stop( $wait );
			}
		}

		return $auth
			? __( 'The provider refused the API key or access, so the job was stopped before sending more posts. Check the key under PerfLocale > Settings > Addons > Machine Translation (or Settings > Connectors for the WordPress AI client), then retry the job.', 'perflocale' )
			: '';
	}

	/**
	 * The failure message for stops at one provider call: the job row is full (more time does not help), or the ways
	 * to give the call more time.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $slug      Target language slug.
	 * @param string $call      The call, such as "content part 4 of 9".
	 * @param bool   $full      The pair's answers no longer fit the job row.
	 * @return string
	 */
	private static function stop_note( int $source_id, string $slug, string $call, bool $full = false ): string {
		if ( $full ) {
			return sprintf(
				/* translators: 1: post id, 2: target language slug */
				__( 'Post %1$d into %2$s: the job cannot keep more of this post\'s translated parts between background runs, so it was stopped. Run background jobs with WP-CLI (system cron, no time limit) or shorten the post; then retry the job.', 'perflocale' ),
				$source_id,
				$slug
			);
		}

		return sprintf(
			/* translators: 1: post id, 2: target language slug, 3: the provider call, such as "content part 4 of 9" */
			__( 'Post %1$d into %2$s: %3$s was not translated within one background run, so the job was stopped. Run background jobs with WP-CLI (system cron, no time limit), allow longer runs with the perflocale/jobs/bulk_translate/slice_seconds filter (if your host allows longer requests), or send smaller parts with the perflocale/mt/piece_chars filter; then retry the job.', 'perflocale' ),
			$source_id,
			$slug,
			$call
		);
	}

	/**
	 * The resume position when this job's own stop left its pair there (a deadline cut, or a kill counted there), else
	 * -1.
	 *
	 * @param array<mixed> $resume    The stored checkpoint.
	 * @param array<mixed> $slice     The worker's slice args.
	 * @param int          $processed The resume position.
	 * @return int
	 */
	private static function cut_at( array $resume, array $slice, int $processed ): int {
		$stops  = is_array( $slice['stops'] ?? null ) ? $slice['stops'] : [];
		$killed = ( $stops['at'] ?? null ) === $processed && is_int( $stops['count'] ?? null ) && $stops['count'] > 0;

		return $killed || ( $resume['cut'] ?? null ) === $processed ? $processed : -1;
	}

	/**
	 * The budget of one slice: seconds and pairs (each 0 for none) and, on a web request, the time its
	 * provider calls must end by; the seconds also end before the request's limit ({@see CallDeadline}).
	 *
	 * @param bool $cli Whether the run is under WP-CLI.
	 * @return array{0: float, 1: int, 2: float|null, 3: float|null}
	 */
	private static function slice_budget( bool $cli ): array {
		/**
		 * Filter the wall-clock budget of one slice of a background bulk translation, in seconds: a slice stops before
		 * its next pair once it ran one and reached this or its pair cap. 0 or less: no time budget under WP-CLI; on
		 * the web never past perflocale/jobs/web_slice_max_seconds (60 s).
		 *
		 * @hook perflocale/jobs/bulk_translate/slice_seconds
		 * @param float $seconds Default 20 on web requests, 0 under WP-CLI.
		 */
		$seconds = (float) apply_filters( 'perflocale/jobs/bulk_translate/slice_seconds', $cli ? 0 : self::SLICE_SECONDS );

		/**
		 * Filter the most pairs one slice of a background bulk translation runs. 0 or less: no pair cap.
		 *
		 * @hook perflocale/jobs/bulk_translate/slice_max_pairs
		 * @param int $pairs Default 500 on web requests, 0 under WP-CLI.
		 */
		$pairs = (int) apply_filters( 'perflocale/jobs/bulk_translate/slice_max_pairs', $cli ? 0 : self::SLICE_MAX_PAIRS );

		$deadline = null;
		$window   = null;

		if ( ! $cli ) {
			[ $seconds, $deadline, $window ] = CallDeadline::for_slice( $seconds );
		}

		return [ $seconds, $pairs, $deadline, $window ];
	}

	/**
	 * Remove the translations written for a target language that is gone (TargetLanguageGuard::settle()); each counts
	 * as failed.
	 *
	 * @param int    $created     Translations created.
	 * @param int    $failed      Pairs failed.
	 * @param string $first_error First error.
	 * @return void
	 */
	private function settle( int &$created, int &$failed, string &$first_error ): void {
		if ( null !== $this->guard ) {
			self::settle_guard( $this->guard, $created, $failed, $first_error );
		}
	}

	/**
	 * Settle a run's guard (TargetLanguageGuard::settle()) and count each translation it removed as failed.
	 *
	 * @param TargetLanguageGuard $guard       The run's guard.
	 * @param int                 $created     Translations created.
	 * @param int                 $failed      Pairs failed.
	 * @param string              $first_error First error.
	 * @return void
	 */
	private static function settle_guard( TargetLanguageGuard $guard, int &$created, int &$failed, string &$first_error ): void {
		$removed = count( $guard->settle() );

		if ( $removed > 0 ) {
			$created -= $removed;
			$failed  += $removed;

			if ( $first_error === '' ) {
				$first_error = TargetLanguageGuard::reason();
			}
		}
	}

	/**
	 * The counts a slice resumes from, when the checkpoint matches the job (`processed` inside it, `cursor` the last
	 * source fully done). Null starts at the first pair, which is safe: translated pairs are skipped without a call.
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
	 * The checkpoint at a position: the counts so far and the last source fully done (checkpoint() reads it).
	 *
	 * @param int             $processed          Pairs done.
	 * @param int             $created            Created.
	 * @param int             $skipped            Skipped.
	 * @param int             $skipped_permission Skipped for permissions.
	 * @param int             $failed             Failed.
	 * @param string          $first_error        First error.
	 * @param int             $kept               Kept in the source language.
	 * @param string          $first_warning      First warning.
	 * @param array<int, int> $source_ids         Source post ids.
	 * @param int             $n_targets          Target languages (> 0).
	 * @return array<string, int|string>
	 */
	private static function checkpoint_of( int $processed, int $created, int $skipped, int $skipped_permission, int $failed, string $first_error, int $kept, string $first_warning, array $source_ids, int $n_targets ): array {
		$done = intdiv( $processed, $n_targets );

		return [
			'created'            => $created,
			'skipped'            => $skipped,
			'skipped_permission' => $skipped_permission,
			'failed'             => $failed,
			'first_error'        => $first_error,
			'kept'               => $kept,
			'first_warning'      => $first_warning,
			'cursor'             => $done > 0 ? (int) $source_ids[ $done - 1 ] : 0,
			'processed'          => $processed,
		];
	}

	/**
	 * Whether a source already has a complete translation in a language this run knows (null: none), by the
	 * language's id. One still marked pending (a worker stopped while writing it) is redone, not skipped.
	 *
	 * @param object      $manager   Post translation manager.
	 * @param int         $source_id Source post id.
	 * @param object|null $language  Language row.
	 * @param bool        $own       The pair is the one a deadline stopped this job inside.
	 * @param int|null    $found     Set to the translation's id when a pending mark was read for it.
	 * @return bool
	 */
	private function has_translation( object $manager, int $source_id, ?object $language, bool $own = false, ?int &$found = null ): bool {
		$vars = null !== $language ? get_object_vars( $language ) : [];
		$id   = $vars['id'] ?? null;

		if ( ! is_numeric( $id ) || ! method_exists( $manager, 'has_translation_in' ) || ! $manager->has_translation_in( $source_id, (int) $id ) ) {
			return false;
		}

		$this->pending ??= self::pending_translations();

		if ( [] === $this->pending || ! is_string( $vars['slug'] ?? null ) ) {
			return true;
		}

		$translation = method_exists( $manager, 'get_translation_id' ) ? $manager->get_translation_id( $source_id, $vars['slug'] ) : null;
		$found       = is_numeric( $translation ) ? (int) $translation : null;

		return ! $this->unfinished( $translation, $own );
	}

	/**
	 * For a skipped translation a stopped run left unfinished and a person edited since: report it once and drop its
	 * mark, as it is theirs now.
	 *
	 * @param int|null $translation   Translation post id.
	 * @param int      $source_id     Source post id.
	 * @param string   $first_warning First warning (by ref).
	 * @return void
	 */
	private function kept_edit( ?int $translation, int $source_id, string &$first_warning ): void {
		if ( null === $translation || ! is_array( $this->pending ) || ! array_key_exists( $translation, $this->pending ) || null !== $this->pending[ $translation ] ) {
			return;
		}

		unset( $this->pending[ $translation ] );

		if ( current_user_can( 'edit_post', $translation ) ) {
			delete_post_meta( $translation, TranslationService::PENDING_META_KEY );
		}

		$note = sprintf(
			/* translators: 1: ID of the translation post, 2: ID of the source post. */
			__( 'The translation (post %1$d) of post %2$d was edited after an earlier run stopped while writing it, so it was kept as it is; its custom fields may not be translated. Check it in its editor.', 'perflocale' ),
			$translation,
			$source_id
		);

		$this->note_redo( $note );

		if ( '' === $first_warning ) {
			$first_warning = $note;
		}
	}

	/**
	 * Whether a translation's pending mark makes the pair run again: a stopped worker's (older than a lease), or a
	 * young one on the pair a deadline stopped this job inside ($own).
	 *
	 * @param mixed $translation Translation post id.
	 * @param bool  $own         The pair is the one a deadline stopped this job inside.
	 * @return bool
	 */
	private function unfinished( mixed $translation, bool $own ): bool {
		$this->pending ??= self::pending_translations();
		$stale           = is_numeric( $translation ) ? ( $this->pending[ (int) $translation ] ?? null ) : null;

		return true === $stale || ( $own && false === $stale );
	}

	/**
	 * Marked translations by id: true when the stored row equals the mark's fingerprint and the mark is a lease old or
	 * more (a stopped worker's), false when younger (a live call's, or a deadline-stopped pair's); null when old and
	 * changed or without a fingerprint (a person's now: kept). One read.
	 *
	 * @return array<int, bool|null>
	 */
	private static function pending_translations(): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$pending = [];

		// The whole posts row after the mark, as TranslationService::stored_text() reads it for the fingerprint.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed read per run, only when a translation exists.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.post_id, m.meta_value, p.* FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s', $wpdb->postmeta, $wpdb->posts, TranslationService::PENDING_META_KEY ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$value = $row['meta_value'] ?? null;
			$mark  = TranslationService::pending_time( $value );
			$seal  = TranslationService::pending_seal( $value );

			// The posts table has no such columns: what is left is the row.
			unset( $row['post_id'], $row['meta_value'] );

			if ( $mark <= 0 || ! is_numeric( $row['ID'] ?? null ) ) {
				continue;
			}

			$stale = time() - $mark >= \PerfLocale\Background\JobResume::LEASE;

			if ( '' !== $seal && hash_equals( $seal, TranslationService::row_fingerprint( $row ) ) ) {
				$pending[ (int) $row['ID'] ] = $stale;
			} elseif ( $stale ) {
				$pending[ (int) $row['ID'] ] = null;
			}
		}

		return $pending;
	}

	/**
	 * The bulk row loop, apart from execute() so one try/finally wraps the eager-link-map suspension.
	 *
	 * @param array<int,int>            $source_ids  Source post IDs.
	 * @param array<int,int>            $target_ids  Target language IDs.
	 * @param array<int,object>         $lang_by_id  Language rows keyed by id.
	 * @param object                    $manager     Post translation manager.
	 * @param TranslationService        $service     MT translation service.
	 * @param object                    $settings    Plugin settings.
	 * @param bool                      $include_meta Whether to translate meta.
	 * @param int                       $total       Total row count (rows × languages).
	 * @param callable                  $tick        Progress emitter.
	 * @param int                       $processed   Running processed count (by ref).
	 * @param int                       $created     Running created count (by ref).
	 * @param int                       $skipped     Running skipped count (by ref).
	 * @param int                       $failed      Running failed count (by ref).
	 * @param string                    $first_error First error message (by ref).
	 * @param callable|null             $yield       Called with the just-completed source id; true stops at that boundary.
	 * @param int                       $cursor      Last FULLY processed source id (by ref).
	 * @param int               $skipped_permission Part of $skipped refused for permissions (by ref).
	 * @param int               $kept          Part of $created left in the source language (by ref).
	 * @param string            $first_warning Warning of the first such row (by ref).
	 * @param int               $start_source  Index in $source_ids of the first pair to run.
	 * @param int               $start_target  Index in $target_ids of the first pair to run.
	 * @param callable|null     $before_pair   Called with the next pair's indexes; true stops the loop before it.
	 * @param string            $trigger       The dispatching path; a password-protected source it may not send
	 *                                         (TranslationService::may_send_post()) counts as skipped.
	 * @param callable|null      $checkpoint    Writes the slice's checkpoint: called with the source id and target slug
	 *                                         before a pair is sent, and with the kept answers after each one.
	 * @param string             $meta_path     Path for TranslationService::include_meta(); '' keeps $include_meta.
	 * @return bool True when $before_pair, or a provider wait past the slice's deadline, stopped the loop.
	 * @throws DeadlineReachedException In a run without $before_pair (not a slice): the deadline is its caller's.
	 * @phpstan-impure
	 */
	private function run_rows( array $source_ids, array $target_ids, array $lang_by_id, object $manager, TranslationService $service, object $settings, bool $include_meta, int $total, callable $tick, int &$processed, int &$created, int &$skipped, int &$failed, string &$first_error, ?callable $yield = null, int &$cursor = 0, int &$skipped_permission = 0, int &$kept = 0, string &$first_warning = '', int $start_source = 0, int $start_target = 0, ?callable $before_pair = null, string $trigger = 'bulk', ?callable $checkpoint = null, string $meta_path = '' ): bool {
		// `perflocale_use_mt` authorizes spending the provider; WorkerRunner re-checks only
		// `perflocale_manage_translations`. Read once here (the user cannot change in the loop) and folded into the
		// per-row gate, so a revoked capability counts as skipped, as an unauthorized post does.
		$can_use_mt = current_user_can( 'perflocale_use_mt' );

		// Drafts kept from stopped runs stay listed on the Jobs page: this result shows only the first.
		\PerfLocale\Translation\CreationMark::hold_reports();
		$gone_ids   = [];

		// Say why the run did nothing: all "skipped" with no error reads as "nothing to translate", not as a lost
		// capability.
		if ( ! $can_use_mt && $first_error === '' ) {
			$first_error = __( 'Machine translation is not permitted for the dispatching user (perflocale_use_mt).', 'perflocale' );
		}

		foreach ( array_slice( $source_ids, $start_source, null, true ) as $source_index => $source_id ) {
			// A resumed slice starts inside its first source.
			$targets = ( $source_index === $start_source && $start_target > 0 )
				? array_slice( $target_ids, $start_target, null, true )
				: $target_ids;

			// Checked here, at execution (rights may change after queueing): the post sent needs edit and read, host-aware.
			if ( ! $can_use_mt || ! \PerfLocale\Helper::user_can_copy_translation_source( (int) $source_id ) ) {
				if ( null !== $before_pair && $before_pair( $source_index, (int) array_key_first( $targets ) ) ) {
					return true;
				}

				$skipped            += count( $targets );
				$skipped_permission += count( $targets );
				$processed          += count( $targets );
				$tick( $processed );

				// A source skipped for permissions is fully processed: the cursor must pass it or the chain re-reads it
				// forever.
				$cursor = (int) $source_id;

				if ( $yield !== null && $yield( (int) $source_id, $processed ) ) {
					return false;
				}

				continue;
			}

			// Whether the user may have translations of this source copied from the group's default-language post:
			// resolved once per source, at its first creating row. A later row adding that member copies from
			// $source_id, so it holds.
			$copy_allowed  = null;
			$create_denied = false;
			$copy_hidden   = false;

			// Whether this run may send this source, resolved at its first row to translate (free when every row
			// exists).
			$send_allowed = null;

			foreach ( $targets as $target_index => $target_lang_id ) {
				if ( null !== $before_pair && $before_pair( $source_index, $target_index ) ) {
					return true;
				}

				// The pair a stopped worker was inside: its draft (CreationMark) is recovered, also when the pair was linked since.
				if ( $processed === $this->cut && '' !== TargetLanguageGuard::slug_of( $lang_by_id[ $target_lang_id ] ?? null ) ) {
					foreach ( \PerfLocale\Translation\CreationMark::recover_pair( (int) $source_id, TargetLanguageGuard::slug_of( $lang_by_id[ $target_lang_id ] ?? null ) ) as $draft => $outcome ) {
						if ( '' === $first_warning ) {
							$first_warning = \PerfLocale\Translation\CreationMark::message( (int) $draft, (int) $source_id, $outcome );
						}
					}
				}

				// Never overwrite an existing translation, except the one pair of an `overwrite` arg while it is as queued;
				// checked by the target's id before the checkpoint, so a skip costs no query.
				$found       = null;
				$overwriting = false;

				if ( ! isset( $gone_ids[ (int) $target_lang_id ] ) && $this->has_translation( $manager, (int) $source_id, $lang_by_id[ $target_lang_id ] ?? null, $processed === $this->cut, $found ) ) {
					[ $over, $why ] = $this->may_overwrite( (int) $source_id, (int) $target_lang_id, $manager, $lang_by_id[ $target_lang_id ] ?? null );

					// One the dispatching user may not view: refused as the create routes refuse it, never named or "skipped".
					$slug = TargetLanguageGuard::slug_of( $lang_by_id[ $target_lang_id ] ?? null );

					if ( null === $found && '' !== $slug && method_exists( $manager, 'get_translation_id' ) ) {
						$looked = $manager->get_translation_id( (int) $source_id, $slug );
						$found  = is_numeric( $looked ) ? \PerfLocale\Util\Scalar::int( $looked ) : null;
					}

					$hidden = get_current_user_id() > 0 && \PerfLocale\Api\TranslationsController::hidden_translation( (int) $source_id, $found );

					if ( $hidden ) {
						$over = 'denied';
						$why  = \PerfLocale\Api\TranslationsController::unavailable_message( $slug );
					}

					if ( 'ok' !== $over ) {
						if ( ! $hidden ) {
							$this->kept_edit( $found, (int) $source_id, $first_warning );
						}

						++$skipped;
						++$processed;
						$tick( $processed );

						if ( 'denied' === $over ) {
							++$skipped_permission;
							$first_error = '' === $first_error ? $why : $first_error;
						} elseif ( '' === $first_warning ) {
							$first_warning = $why;
						}

						continue;
					}

					$overwriting = true;
				}

				// Before every pair that calls the provider or writes: after a change by another process the pair reads
				// its target as it is now, and a translation written for a language gone since is removed again
				// (settle()).
				$guard     = $this->guard;
				$refreshed = false;

				if ( null !== $guard && $guard->checkpoint() ) {
					$this->settle( $created, $failed, $first_error );

					// Every target is read again: a language deleted or deactivated meanwhile fails its remaining
					// pairs.
					foreach ( array_keys( $lang_by_id ) as $known_id ) {
						$fresh = $guard->live_target( (int) $known_id );

						if ( null === $fresh ) {
							unset( $lang_by_id[ $known_id ] );
							$gone_ids[ (int) $known_id ] = true;
						} else {
							$lang_by_id[ $known_id ] = $fresh;
						}
					}

					$refreshed = true;
				}

				if ( isset( $gone_ids[ (int) $target_lang_id ] ) ) {
					++$failed;

					if ( $first_error === '' ) {
						$first_error = TargetLanguageGuard::gone_reason();
					}

					++$processed;
					$tick( $processed );
					continue;
				}

				$target_lang = $lang_by_id[ $target_lang_id ] ?? null;

				if ( $target_lang === null ) {
					// An unresolvable language id counts as failed, so a bad-language chain cannot report all-green
					// zeros.
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

				// The languages were read again: the pair's translation is looked up under the target as it is now; one
				// marked pending is redone, as above.
				$now = $refreshed ? $manager->get_translation_id( $source_id, $target_lang->slug ) : null;

				if ( null !== $now && ! $this->unfinished( $now, $processed === $this->cut ) && ! ( $overwriting && is_numeric( $now ) && (int) $now === ( $this->overwrite['translation'] ?? 0 ) ) ) {
					$this->kept_edit( is_numeric( $now ) ? (int) $now : null, (int) $source_id, $first_warning );
					++$skipped;
					++$processed;
					$tick( $processed );
					continue;
				}

				// The row creates a translation copied from the group's default-language post (not necessarily
				// $source_id) as a post of that type, so the copy permission and the type's create capability apply as
				// on the single-create route. A 0 answer is not remembered: it can depend on the target.
				if ( $copy_allowed === null ) {
					$copy_from = $manager->get_copy_source_id( (int) $source_id, (string) $target_lang->slug );

					if ( $copy_from > 0 ) {
						$copy_allowed  = \PerfLocale\Helper::user_can_copy_translation_source( $copy_from );
						$copy_hidden   = ! $copy_allowed && ! \PerfLocale\Helper::user_can_view_object( \PerfLocale\Util\Scalar::int( $copy_from ) );
						$create_denied = $copy_allowed && ( ! is_int( $copy_from ) || ! \PerfLocale\Helper::user_can_create_like( $copy_from ) );

						if ( $create_denied ) {
							$copy_allowed = false;
						}
					}
				}

				if ( $copy_allowed === false ) {
					++$skipped;
					++$skipped_permission;
					++$processed;
					$tick( $processed );

					if ( $first_error === '' ) {
						// A copy source the user may not open: the refusal of a hidden translation, naming none.
						$first_error = $create_denied
							? __( 'You do not have permission to create translations of this content.', 'perflocale' )
							: ( $copy_hidden ? \PerfLocale\Api\TranslationsController::source_unavailable_message( TargetLanguageGuard::slug_of( $target_lang ) ) : __( 'You need read and edit rights on the original this translation is copied from.', 'perflocale' ) );
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
				 * Short-circuit one (source post, target language) row before the default machine translation call, as
				 * WordPress's `pre_*` filters: null runs the default; `['post_id' => int]` counts as created; anything
				 * else (`'skip'`, `false`, a non-array) as skipped. For per-row policy such as lock-lists.
				 *
				 * @hook  perflocale/mt/bulk/before_translate
				 * @since 1.0.0
				 *
				 * @param mixed $pre `null` to run the default flow; any other value is the row's result.
				 * @param int    $source_id   The source post ID.
				 * @param string $target_slug The target language slug.
				 * @param array  $context     Row metadata: target_id, provider, processed-so-far, total.
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

				// The translation found unfinished: written only while its row is the one its mark fingerprints.
				$redo = $refreshed ? ( is_numeric( $now ) ? (int) $now : null ) : $found;

				$answers = null;

				if ( null !== $checkpoint ) {
					$slug    = TargetLanguageGuard::slug_of( $target_lang );
					$answers = $this->answers( (int) $source_id, $slug, $settings, $checkpoint );
					$checkpoint( (int) $source_id, $slug, $this->pieces );
				}

				$service->keep_answers( $answers );

				try {
					// fast_fail=false lets the provider's retry loop catch transient errors (rate limit, network blip).
					$inserted   = false;
					$row_slug   = TargetLanguageGuard::slug_of( $target_lang );
					$row_meta   = '' === $meta_path ? $include_meta : TranslationService::include_meta( $include_meta, $meta_path, (int) $source_id, $row_slug );
					$row_result = $service->translate_post( $source_id, $row_slug, '', false, $row_meta, $inserted, null !== $redo );

					if ( ! empty( $row_result['post_id'] ) ) {
						++$created;

						// Only a translation this run inserted: one it updated existed before the run and is never
						// removed.
						if ( $inserted && null !== $this->guard ) {
							$this->guard->written( is_numeric( $row_result['post_id'] ) ? (int) $row_result['post_id'] : 0, (int) $target_lang_id );
						}

						// A stopped creation's copy steps that failed again or were left out (an edit since): the row's own field.
						if ( is_string( $row_result['redo_note'] ?? null ) ) {
							$this->note_redo( $row_result['redo_note'] );

							if ( $first_warning === '' ) {
								$first_warning = $row_result['redo_note'];
							}
						}

						// A lost "Do not translate" section or a builder layout keeps the source content; the row
						// counts as created and is reported.
						if ( ! empty( $row_result['content_kept_source'] ) || in_array( 'builder', (array) ( $row_result['kept'] ?? [] ), true ) ) {
							++$kept;

							$row_warnings = array_filter( (array) ( $row_result['warnings'] ?? [] ), 'is_string' );

							if ( $first_warning === '' && $row_warnings !== [] ) {
								$first_warning = (string) end( $row_warnings );
								$length        = $row_result['length_warning'] ?? '';

								// A kept row's shortened title or excerpt is reported too.
								if ( is_string( $length ) && '' !== $length && $length !== $first_warning ) {
									$first_warning .= ' ' . $length;
								}
							}
						} elseif ( $first_warning === '' && is_string( $row_result['length_warning'] ?? null ) ) {
							$first_warning = $row_result['length_warning'];
						}

						// A stopped run's draft of this pair, trashed or kept (CreationMark).
						if ( $first_warning === '' && is_string( $row_result['creation_note'] ?? null ) ) {
							$first_warning = $row_result['creation_note'];
						}
					} else {
						++$failed;
						$row_error = __( 'Provider returned no post ID', 'perflocale' );

						if ( $first_error === '' ) {
							$first_error = $row_error;
						}
					}
				} catch ( DeadlineReachedException $e ) {
					// Not the pair's failure: the next slice translates it again, or the job stops at it.
					if ( null === $before_pair ) {
						throw $e;
					}

					$this->job_stop = '' !== $e->job_stop() ? $e->job_stop() : ( $e->paced() ? $this->paced_stop( $e->wait() ) : '' );

					if ( '' !== $this->job_stop ) {
						$this->cut = $processed;
						$service->keep_answers( null );

						return true;
					}

					$this->continue_at  = $e->retry_at();
					$this->unit_stopped = ! $e->paced();
					$this->cut          = $processed;
					$this->stop_note    = null === $answers || '' === $answers->in_flight ? '' : self::stop_note( (int) $source_id, TargetLanguageGuard::slug_of( $target_lang ), $answers->in_flight, $this->kept_full );
					$service->keep_answers( null );

					return true;
				} catch ( \Throwable $e ) {
					// An open breaker is waited for, a refused key stops the job: no pair fails for either.
					$this->job_stop = null === $before_pair ? '' : $this->provider_stop( $e );

					if ( '' !== $this->job_stop || $this->continue_at > 0 ) {
						$this->cut          = $processed;
						$this->unit_stopped = false;
						$service->keep_answers( null );

						return true;
					}

					$row_error = $e->getMessage();

					// Edited or finished since this run read its mark: skipped. Kept and reported only for a mark no live call
					// holds: a stopped worker's, this job's own stopped pair's, or one never redone (no fingerprint).
					if ( null !== $redo && $e instanceof NotSavedException && TranslationService::KEPT_ON_REDO === $e->getCode() ) {
						++$skipped;

						$mark = TranslationService::stored_mark( $redo );

						if ( null !== $mark && '' !== $mark && ( '' === TranslationService::pending_seal( $mark ) || time() - TranslationService::pending_time( $mark ) >= \PerfLocale\Background\JobResume::LEASE || $processed === $this->cut ) ) {
							$this->pending[ $redo ] = null;
							$this->kept_edit( $redo, (int) $source_id, $first_warning );
						}
					} else {
						++$failed;

						if ( $first_error === '' ) {
							$first_error = $row_error;
						}
					}
				}

				// The pair is done: its kept answers go with the next checkpoint.
				$service->keep_answers( null );
				$this->pieces = null;
				$this->paced  = 0;

				/**
				 * Fires after each (source post, target language) row: translated, failed or short-circuited by
				 * `perflocale/mt/bulk/before_translate`. For per-row metrics, monitoring or workflow events.
				 *
				 * @hook  perflocale/mt/bulk/after_translate
				 * @since 1.0.0
				 *
				 * @param int    $source_id   Source post ID.
				 * @param string $target_slug Target language slug.
				 * @param array  $result      The TranslationService result (`['post_id' => int]` on success); empty on failure.
				 * @param array  $context     Row metadata: `error` (empty on success), `short_circuited` (from before_translate) and
				 *                            the keys passed to the before_translate filter.
				 */
				do_action(
					'perflocale/mt/bulk/after_translate',
					(int) $source_id,
					(string) $target_lang->slug,
					$row_result,
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

			// Every target of this source was attempted: the cursor may pass it.
			$cursor = (int) $source_id;

			if ( $yield !== null && $yield( (int) $source_id, $processed ) ) {
				return false;
			}
		}

		return false;
	}
}
