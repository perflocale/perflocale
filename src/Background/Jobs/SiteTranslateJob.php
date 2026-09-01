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
 *   }
 *
 * Each execution resolves the next CHUNK_SIZE source IDs keyset-style
 * (WHERE ID > after_id ORDER BY ID), runs them through BulkTranslateJob's
 * proven per-pair pipeline INLINE (same skip-existing / per-row edit-cap /
 * error semantics), then RE-ENQUEUES ITSELF with the advanced cursor. The job
 * completes when the selection runs dry.
 *
 * Resumability falls out of the design: a retry-from-zero re-skips finished
 * pairs at ZERO provider cost — the skip-existing rule returns before the
 * provider is ever reached, so a re-run over an already-translated selection
 * makes no API calls at all — and the cursor bounds each execution's runtime
 * under the job lock TTL. Cancel is cooperative per chunk; canceling the
 * parent stops the chain (no further re-enqueue), and so does canceling any
 * still-queued chunk, since each chunk only enqueues the next one from inside
 * its own running execute().
 */
final class SiteTranslateJob extends AbstractJob {

	/**
	 * Source posts per chunk. Bounds per-execution runtime + memory; the
	 * chain re-enqueues until dry.
	 */
	private const CHUNK_SIZE = 100;

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
	 */
	public function execute( array $args, callable $progress ): array {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['post_types'] ?? [ 'post', 'page' ] ) ) ) );
		$lang_ids   = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );
		$after_id   = max( 0, (int) ( $args['after_id'] ?? 0 ) );

		if ( $post_types === [] || $lang_ids === [] ) {
			return [
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'done'    => true,
				'error'   => 'Empty selection.',
			];
		}

		$tph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// Keyset page: strictly-increasing IDs so a re-run/retry never
		// re-reads earlier pages. Publish-only mirrors the CLI --all rule.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type IN ({$tph}) AND post_status = 'publish' AND ID > %d
					 ORDER BY ID ASC
					 LIMIT %d",
					array_merge( $post_types, [ $after_id, self::CHUNK_SIZE ] )
				)
			)
		);
		// phpcs:enable

		if ( $ids === [] ) {
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
		// the admin bulk action). The progress callback is passed through, so
		// cancel propagates cooperatively into the chunk.
		$chunk_result = ( new BulkTranslateJob() )->execute(
			[
				'source_ids'      => $ids,
				'target_lang_ids' => $lang_ids,
				'include_meta'    => ! empty( $args['include_meta'] ),
			],
			$progress
		);

		// Bound worker memory across the chain on huge sites (runtime cache +
		// SAVEQUERIES log only; persistent cache untouched).
		\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

		$cursor = (int) end( $ids );

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
				'created' => (int) ( $chunk_result['created'] ?? 0 ),
				'skipped' => (int) ( $chunk_result['skipped'] ?? 0 ),
				'failed'  => (int) ( $chunk_result['failed'] ?? 0 ),
				'cursor'  => $cursor,
				'done'    => true,
				'error'   => __( 'Stopped: monthly machine-translation character limit reached. Re-run after raising the limit to resume from this cursor.', 'perflocale' ),
			];
		}

		// Chain the next chunk. A canceled parent never reaches this point
		// (the progress callback throws JobCanceledException inside execute()),
		// so cancel stops the chain by construction. Dispatch failure is
		// surfaced in the result rather than silently ending the chain.
		$next = Dispatcher::dispatch(
			$this,
			[
				'post_types'      => $post_types,
				'target_lang_ids' => $lang_ids,
				'include_meta'    => ! empty( $args['include_meta'] ),
				'after_id'        => $cursor,
			]
		);

		return [
			'created'  => (int) ( $chunk_result['created'] ?? 0 ),
			'skipped'  => (int) ( $chunk_result['skipped'] ?? 0 ),
			'failed'   => (int) ( $chunk_result['failed'] ?? 0 ),
			'cursor'   => $cursor,
			'done'     => false,
			'next_job' => $next['job_id'] ?? null,
			'next'     => $next['mode'] ?? 'unknown',
		];
	}
}
