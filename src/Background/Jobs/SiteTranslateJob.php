<?php
/**
 * Site-wide machine-translation orchestrator job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\Dispatcher;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translate EVERY (selected) post on the site — as a chain of bounded chunks.
 *
 * Args are the SELECTION QUERY, never ID lists (job args are capped at 100 KB
 * JSON, ~12k IDs — a selection query scales to any site size):
 *
 *   {
 *     post_types:      string[]  (default ['post','page'])
 *     target_lang_ids: int[]     (required)
 *     include_meta:    bool      (default false)
 *     after_id:        int       (keyset cursor, default 0)
 *     trigger:         string    ('cli_all' for a chain started by
 *                                `wp perflocale translate --all --async`;
 *                                absent otherwise)
 *   }
 *
 * Each execution resolves the next CHUNK_SIZE source IDs keyset-style
 * (WHERE ID > after_id ORDER BY ID; published posts that are not in a
 * non-default language), runs them through BulkTranslateJob's
 * proven per-pair pipeline INLINE (same skip-existing / per-row edit-cap /
 * error semantics), then RE-ENQUEUES ITSELF with the advanced cursor. The job
 * completes when the selection runs dry.
 *
 * Resumability falls out of the design: a retry-from-zero re-skips finished
 * pairs at ZERO provider cost — the skip-existing rule returns before the
 * provider is ever reached, so a re-run over an already-translated selection
 * makes no API calls at all — and the cursor bounds each execution's runtime
 * under the job lock TTL. Cancel is cooperative per chunk. Canceling a
 * queued chunk stops the chain, since each chunk only enqueues the next one
 * from inside its own running execute(). Canceling a running chunk stops it
 * at its next progress tick, before it enqueues anything. A cancel that lands
 * after the last tick, while the next chunk is being enqueued, is handled by
 * the worker: it records the cancel and also cancels the chunk this execution
 * queued, found through the `next_job` and `next_duplicate` result keys.
 */
final class SiteTranslateJob extends AbstractJob {

	/**
	 * Upper bound on source IDs fetched per execution. This is a FETCH cap,
	 * not the work bound — see CHUNK_SECONDS.
	 */
	private const CHUNK_SIZE = 100;

	/**
	 * Wall-clock budget for one execution, in seconds.
	 *
	 * ⚠️ THIS IS A SOFT, SOURCE-ATOMIC BUDGET, NOT A HARD EXECUTION BOUND, and
	 * it is deliberately described that way. The check runs AFTER a source
	 * completes, so one execution can overshoot by up to a whole source: with a
	 * 0.02 s budget and ten languages at 25 ms per pair, a measured execution
	 * ran 835 ms. A pair-level checkpoint would not fix this either — nothing
	 * here can interrupt a provider call that is already in flight.
	 *
	 * The alternative, persisting an in-source target position, was considered
	 * and not taken: the cursor must never leap over unattempted target
	 * languages, and source-atomic checkpointing is what makes that impossible
	 * by construction. Size the budget for the maximum expected fan-out of a
	 * single source rather than treating it as a timeout guarantee.
	 *
	 * ⚠️ The work bound must be TIME, not a count of sources, and not even a
	 * count of pairs. Two reasons, both measured:
	 *
	 *  1. A source count is wrong by a factor of the language count. The old
	 *     `CHUNK_SIZE = 100` bounded SOURCES and then handed all of them plus
	 *     EVERY target language to BulkTranslateJob, so a 10-language site ran
	 *     1,000 pairs in one execution — ~28 s of stub work at the measured
	 *     28.3 ms/pair, and hours of it under machine translation.
	 *  2. A pair count cannot fix the MT case either: one provider call is
	 *     0.2-2 s, so 100 MT pairs is 20-200 s. Only a clock bounds both.
	 *
	 * Nothing in src/Background/ raises PHP's max_execution_time, and a
	 * progress tick refreshes the JOB LOCK, not the execution timer. 20 s
	 * leaves margin under a stock 30 s limit and sits 90x under the 1800 s
	 * lock TTL.
	 */
	private const CHUNK_SECONDS = 20;

	/**
	 * Hard ceiling on pairs per execution, as a backstop for the case where
	 * work is so fast that the clock never binds (stub creation on a warm
	 * local DB). Belt to CHUNK_SECONDS' braces.
	 */
	private const CHUNK_MAX_PAIRS = 500;

	/**
	 * Job type id.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'site_translate';
	}

	/**
	 * Site-wide MT is an operator action.
	 *
	 * @return string
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
	}

	/**
	 * Always async — a site-wide run never belongs in a web request.
	 * (args_size is pinned above every realistic threshold.)
	 *
	 * @return int
	 */
	public function get_default_threshold(): int {
		return 1;
	}

	/**
	 * Pinned high so should_run_async() always says async.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int
	 */
	public function args_size( array $args ): int {
		return PHP_INT_MAX;
	}

	/**
	 * Always async — the recursive chunk chain would otherwise run the WHOLE
	 * site inside the triggering web request and time out (no JobState row, no
	 * cancel, cursor lost). The `never` background-processing setting is
	 * deliberately ignored for this operator-explicit action, and args_size()
	 * is pinned high for the same intent; overriding here is required because
	 * AbstractJob::should_run_async() short-circuits `never` to inline BEFORE
	 * the size comparison.
	 *
	 * @param array<string, mixed> $args     Unused — the decision is unconditional.
	 * @param Settings             $settings Unused.
	 * @return bool
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Unconditional async; signature fixed by AbstractJob.
	public function should_run_async( array $args, Settings $settings ): bool {
		return true;
	}

	/**
	 * Execute one chunk, then re-enqueue with the advanced cursor.
	 *
	 * @param array<string, mixed> $args     Job args (see class docblock).
	 * @param callable             $progress Progress callback (throws on cancel).
	 * @return array<string, mixed>
	 * @throws \PerfLocale\Background\JobCanceledException When the operator cancels or pauses mid-chunk.
	 * @throws \RuntimeException When the keyset SELECT fails; the worker retries the chunk.
	 */
	public function execute( array $args, callable $progress ): array {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['post_types'] ?? [ 'post', 'page' ] ) ) ) );
		$lang_ids   = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );
		$after_id   = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$trigger    = 'cli_all' === ( $args['trigger'] ?? null ) ? 'cli_all' : 'site_translate';

		if ( $post_types === [] || $lang_ids === [] ) {
			return [
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'done'    => true,
				'error'   => __( 'Empty selection.', 'perflocale' ),
			];
		}

		$tph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// One source costs one pair per target language, so the number of
		// sources worth FETCHING falls as languages rise. Capped at
		// CHUNK_SIZE so a single-language run still pages sanely.
		$budget      = (float) apply_filters( 'perflocale/jobs/chunk_seconds', self::CHUNK_SECONDS );
		$max_pairs   = max( 1, (int) apply_filters( 'perflocale/jobs/chunk_max_pairs', self::CHUNK_MAX_PAIRS ) );
		$per_source  = max( 1, count( $lang_ids ) );
		$fetch_limit = max( 1, min( self::CHUNK_SIZE, (int) ceil( $max_pairs / $per_source ) ) );

		// Keyset page: strictly-increasing IDs so a re-run/retry never
		// re-reads earlier pages. Publish-only, and sources only: a post whose
		// language is not the default language is a translation (or an
		// original written in that language) and is never a source, the same
		// rule as the CLI --all selection. A post with no language row counts
		// as default-language content. The NOT EXISTS probe uses the
		// (type, object_id, language_id) unique key of translation_links.
		$lang_repo    = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$default      = $lang_repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $lang_repo->get_default() : null;
		$default_vars = is_object( $default ) ? get_object_vars( $default ) : [];
		$default_id   = is_numeric( $default_vars['id'] ?? null ) ? (int) $default_vars['id'] : 0;

		$source_sql  = '';
		$source_args = [];

		if ( $default_id > 0 ) {
			$source_sql  = ' AND NOT EXISTS ( SELECT 1 FROM %i pl_sl WHERE pl_sl.type = %s AND pl_sl.object_id = p.ID AND pl_sl.language_id <> %d )';
			$source_args = [ \PerfLocale\Database\Schema::table( 'translation_links' ), \PerfLocale\Enum\ObjectType::Post->value, $default_id ];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 WHERE p.post_type IN ({$tph}) AND p.post_status = 'publish' AND p.ID > %d{$source_sql}
					 ORDER BY p.ID ASC
					 LIMIT %d",
					array_merge( $post_types, [ $after_id ], $source_args, [ $fetch_limit ] )
				)
			)
		);
		// phpcs:enable

		if ( $ids === [] ) {
			// get_col() also answers [] when the SELECT failed (wpdb::query()
			// flushes last_result first), so only an empty error channel means
			// the selection ran dry. last_error belongs to the SELECT above:
			// wpdb resets it at the start of every query. Throw OUTSIDE the try
			// below, so the worker marks this chunk failed and retries it with
			// the same after_id. The instanceof is PHPStan narrowing ($wpdb
			// reads as mixed here).
			$db_error = $wpdb instanceof \wpdb ? (string) $wpdb->last_error : '';

			if ( $db_error !== '' ) {
				throw new \RuntimeException( esc_html__( 'Could not read the next batch of posts from the database. Nothing was skipped; a retry resumes from the same point.', 'perflocale' ) );
			}

			// Selection ran dry — the chain is complete.
			return [
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'done'    => true,
			];
		}

		// Run the chunk through the proven per-pair pipeline INLINE (same
		// skip-existing, per-row edit-cap re-check, and error accounting as
		// the admin bulk action) — but ONE SOURCE AT A TIME, so the wall-clock
		// budget can stop the execution at a source boundary.
		//
		// ⭐ The cursor invariant: it only ever advances past a source whose
		// every target language has been attempted. A partially-processed
		// source is never skipped, because the budget is checked AFTER a
		// source completes, never during it. That also guarantees at least one
		// source advances per execution, which is what stops a slow source
		// from re-enqueueing the same cursor forever.
		// ⭐ ONE bulk call for the whole chunk, stopped at a source boundary by
		// the budget. The previous shape called execute() once per source,
		// which re-ran the entire per-call lifecycle each time and measured
		// 10.55x slower on an all-skip chunk (120ms/274 queries -> 1,267ms/
		// 1,809 queries over 50 sources): 50 service constructions, 50 primes,
		// 300 eager-option SELECTs + 50 INSERTs + 50 DELETEs, and 550 progress
		// writes instead of 101 because the tick threshold was recomputed
		// against a single source's pair count.
		$started     = microtime( true );
		$chunk_total = count( $ids ) * $per_source;
		$processed   = 0;

		$yield = static function ( int $source_id, int $done ) use ( $started, $budget, $max_pairs, &$processed ): bool {
			unset( $source_id );
			$processed = $done;

			return ( microtime( true ) - $started ) >= $budget || $done >= $max_pairs;
		};

		// ⚠️ An operator disabling machine translation mid-chunk used to throw
		// straight out of execute(), so the completed-source CHECKPOINT and the
		// cumulative counts were lost and the retry restarted at after_id=0.
		// The translations themselves survived and were skipped on retry, but
		// the run reported the wrong creations and re-walked finished work.
		//
		// Treat an operator stop as an explicit, resumable outcome — while
		// letting JobCanceledException (which EXTENDS RuntimeException) keep
		// propagating, because cancel must still cancel.
		$last_done = $after_id;

		$track = static function ( int $source_id, int $done ) use ( $yield, &$last_done ): bool {
			$last_done = $source_id;

			return $yield( $source_id, $done );
		};

		// The `trigger` names the path for a password-protected source
		// (TranslationService::may_send_post()): 'cli_all' for a chain started by
		// `wp perflocale translate --all --async`, else the automatic
		// 'site_translate'.
		try {
			$chunk_result = ( new BulkTranslateJob() )->execute(
				[
					'source_ids'         => $ids,
					'target_lang_ids'    => $lang_ids,
					'include_meta'       => ! empty( $args['include_meta'] ),
					'yield_after_source' => $track,
					'trigger'            => $trigger,
				],
				$progress
			);
		} catch ( \PerfLocale\Background\JobCanceledException $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

			return [
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'cursor'  => (int) $last_done,
				'done'    => true,
				'error'   => sprintf(
					/* translators: %s: reason the run stopped. */
					__( 'Stopped: %s Re-run to resume from this cursor; translations already created are kept and skipped.', 'perflocale' ),
					$e->getMessage()
				),
			];
		}

		// Bound worker memory across the chain on huge sites (runtime cache +
		// SAVEQUERIES log only; persistent cache untouched).
		\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

		// The cursor comes from the bulk loop, which only advances it past a
		// source whose every target language was attempted. Falling back to
		// $after_id (not to end($ids)) matters: if nothing completed, the chain
		// must not leap over unattempted sources.
		$cursor = (int) ( $chunk_result['cursor'] ?? 0 );

		if ( $cursor <= 0 ) {
			$cursor = $after_id;
		}

		$first_error = (string) ( $chunk_result['first_error'] ?? '' );

		// ⚠️ Reconcile the total before finishing. The chunk total is computed
		// from every FETCHED source, so a budget stop after one source used to
		// complete the worker row at progress=100 while still advertising
		// total=500 — "100%, 10/500". Report what was actually attempted; the
		// chain's remaining work is represented by the cursor, not by an
		// inflated denominator on a finished row.
		$attempted = (int) ( $chunk_result['processed'] ?? 0 );

		if ( $attempted > 0 && $attempted < $chunk_total ) {
			$progress( $attempted, $attempted );
		}

		unset( $chunk_total, $processed );

		// NOTE: a budget stop is deliberately NOT signalled here. The chain
		// always re-enqueues, and terminates when the NEXT execution's keyset
		// page comes back empty — the same termination rule as before, so a
		// short execution and a full one are indistinguishable downstream.

		// A chunk that produced NOTHING but failures means every pair is
		// hitting the same wall (bogus language id, provider outage, breaker
		// open) — chaining on would grind through the whole site repeating
		// the failure. Stop with a resumable cursor instead.
		if ( (int) ( $chunk_result['failed'] ?? 0 ) > 0
			&& (int) ( $chunk_result['created'] ?? 0 ) === 0
			&& (int) ( $chunk_result['skipped'] ?? 0 ) === 0 ) {
			return [
				'created' => 0,
				'skipped' => 0,
				'failed'  => (int) $chunk_result['failed'],
				'cursor'  => $cursor,
				'done'    => true,
				'error'   => sprintf(
					/* translators: %s: first error message from the failed chunk. */
					__( 'Stopped: an entire chunk failed (%s). Fix the cause and re-run to resume from this cursor.', 'perflocale' ),
					(string) ( $chunk_result['first_error'] ?? '' )
				),
			];
		}

		// Stop the chain when the monthly cap is already exhausted: every
		// further translate_post would throw pre-API anyway — ending here
		// turns silent churn into an explicit, resumable stop (re-dispatch
		// after raising the limit picks up at this cursor).
		$settings = new Settings();
		$service  = new \PerfLocale\MachineTranslation\TranslationService( $settings, \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
		if ( $service->would_exceed_limit( 1 ) ) {
			return [
				'created'       => (int) ( $chunk_result['created'] ?? 0 ),
				'skipped'       => (int) ( $chunk_result['skipped'] ?? 0 ),
				'failed'        => (int) ( $chunk_result['failed'] ?? 0 ),
				'kept'          => (int) ( $chunk_result['kept'] ?? 0 ),
				'first_warning' => (string) ( $chunk_result['first_warning'] ?? '' ),
				'cursor'        => $cursor,
				'done'          => true,
				'error'         => __( 'Stopped: monthly machine-translation character limit reached. Re-run after raising the limit to resume from this cursor.', 'perflocale' ),
			];
		}

		// Chain the next chunk. A cancel caught at a progress tick never
		// reaches this point (the callback throws JobCanceledException), but
		// one that lands after the last tick does: the worker then cancels
		// the chunk queued here, using `next_job`. `next_duplicate` marks a
		// dispatch that was folded into a job already in flight, which is not
		// this chunk's to cancel. Dispatch failure is surfaced in the result
		// rather than silently ending the chain.
		$next_args = [
			'post_types'      => $post_types,
			'target_lang_ids' => $lang_ids,
			'include_meta'    => ! empty( $args['include_meta'] ),
			'after_id'        => $cursor,
		];

		if ( 'cli_all' === $trigger ) {
			$next_args['trigger'] = 'cli_all';
		}

		$next = Dispatcher::dispatch( $this, $next_args );

		return [
			'created'        => (int) ( $chunk_result['created'] ?? 0 ),
			'skipped'        => (int) ( $chunk_result['skipped'] ?? 0 ),
			'failed'         => (int) ( $chunk_result['failed'] ?? 0 ),
			'kept'           => (int) ( $chunk_result['kept'] ?? 0 ),
			'first_warning'  => (string) ( $chunk_result['first_warning'] ?? '' ),
			'cursor'         => $cursor,
			'done'           => false,
			'next_job'       => $next['job_id'] ?? null,
			'next_duplicate' => ! empty( $next['duplicate'] ),
			'next'           => $next['mode'] ?? 'unknown',
		];
	}
}
