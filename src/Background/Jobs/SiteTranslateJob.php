<?php
/**
 * Site-wide machine translation as a chain of bounded chunks.
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
 * Translates every selected post as a chain of chunks: args are the selection query (`post_types`, `target_lang_ids`,
 * `include_meta`, `after_id` cursor, `trigger`), never id lists (100 KB args cap). A chunk runs BulkTranslateJob's
 * pipeline, then queues the next. Design: docs-ai/11-code-rationale.md, "SiteTranslateJob: the chunk chain".
 */
final class SiteTranslateJob extends AbstractJob {

	/**
	 * Most source ids fetched per execution: a fetch cap, not the work bound (CHUNK_SECONDS).
	 */
	private const CHUNK_SIZE = 100;

	/**
	 * Wall-clock budget of one execution, in seconds: soft and source-atomic (checked after a source, so it can
	 * overshoot by one source). Time, not a count, bounds the work; 20 s fits a stock 30 s limit. Why:
	 * docs-ai/11-code-rationale.md, "SiteTranslateJob: the time budget".
	 */
	private const CHUNK_SECONDS = 20;

	/**
	 * Hard ceiling on pairs per execution, for work so fast that the clock never binds (stubs on a warm local
	 * database).
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
	 * Site-wide machine translation is an operator action.
	 *
	 * @return string
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
	}

	/**
	 * Always async: a site-wide run never belongs in a web request (args_size() is pinned above any threshold).
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
	 * Always async, even with the `never` setting: inline, the chain would run the whole site in the triggering request
	 * and time out (no row, no cancel, cursor lost). The override is needed because AbstractJob::should_run_async()
	 * returns inline for `never` before the size comparison.
	 *
	 * @param array<string, mixed> $args     Unused - the decision is unconditional.
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

		// One source costs one pair per target language, so fewer sources are worth fetching as languages rise; capped
		// at CHUNK_SIZE so a single-language run still pages sanely.
		$budget      = (float) apply_filters( 'perflocale/jobs/chunk_seconds', self::CHUNK_SECONDS );
		$max_pairs   = max( 1, (int) apply_filters( 'perflocale/jobs/chunk_max_pairs', self::CHUNK_MAX_PAIRS ) );
		$per_source  = max( 1, count( $lang_ids ) );
		$fetch_limit = max( 1, min( self::CHUNK_SIZE, (int) ceil( $max_pairs / $per_source ) ) );

		// Keyset page (increasing ids, so a retry never re-reads earlier pages): published sources only. A post in a
		// non-default language is a translation, never a source (as CLI --all); no language row counts as default. The
		// NOT EXISTS probe uses translation_links' (type, object_id, language_id) unique key.
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
			// get_col() also answers [] when the SELECT failed, so only an empty last_error (this SELECT's) means the
			// selection ran dry. Thrown outside the try below, so the worker fails this chunk and retries it from the
			// same after_id. The instanceof narrows $wpdb for PHPStan.
			$db_error = $wpdb instanceof \wpdb ? (string) $wpdb->last_error : '';

			if ( $db_error !== '' ) {
				throw new \RuntimeException( esc_html__( 'Could not read the next batch of posts from the database. Nothing was skipped; a retry resumes from the same point.', 'perflocale' ) );
			}

			// Selection ran dry: the chain is complete.
			return [
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'done'    => true,
			];
		}

		// One bulk call per chunk, stopped by the budget at a source boundary: the cursor passes only fully attempted
		// sources, and one source always advances.
		// Docs: docs-ai/11-code-rationale.md, "SiteTranslateJob: the chunk chain".
		$started     = microtime( true );
		$chunk_total = count( $ids ) * $per_source;
		$processed   = 0;

		$yield = static function ( int $source_id, int $done ) use ( $started, $budget, $max_pairs, &$processed ): bool {
			unset( $source_id );
			$processed = $done;

			return ( microtime( true ) - $started ) >= $budget || $done >= $max_pairs;
		};

		// An operator disabling machine translation mid-chunk is a resumable stop that keeps the checkpoint and counts;
		// JobCanceledException (a RuntimeException) still propagates, because cancel must cancel.
		$last_done = $after_id;

		$track = static function ( int $source_id, int $done ) use ( $yield, &$last_done ): bool {
			$last_done = $source_id;

			return $yield( $source_id, $done );
		};

		// `trigger` names the path for a password-protected source (TranslationService::may_send_post()): 'cli_all' for
		// `wp perflocale translate --all --async`, else 'site_translate'.
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

		// Bound worker memory across the chain (runtime cache and SAVEQUERIES log only; persistent cache untouched).
		\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

		// The bulk loop's cursor passed only fully attempted sources; the fallback is $after_id, not end($ids), so a
		// chunk that completed nothing never leaps over unattempted sources.
		$cursor = (int) ( $chunk_result['cursor'] ?? 0 );

		if ( $cursor <= 0 ) {
			$cursor = $after_id;
		}

		$first_error = (string) ( $chunk_result['first_error'] ?? '' );

		// The total is what was attempted, not every fetched source: a budget stop would otherwise finish the row at
		// "100%, 10/500". The chain's remaining work is in the cursor.
		$attempted = (int) ( $chunk_result['processed'] ?? 0 );

		if ( $attempted > 0 && $attempted < $chunk_total ) {
			$progress( $attempted, $attempted );
		}

		unset( $chunk_total, $processed );

		// A budget stop is not signalled: the chain always re-queues and ends when the next keyset page is empty, so
		// short and full executions look the same downstream.

		// Nothing but failures means every pair hits the same wall (bad language id, provider outage, open breaker):
		// stop with a resumable cursor instead of grinding through the site.
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

		// An exhausted monthly cap stops the chain explicitly (every translate_post would throw before the API anyway);
		// a re-dispatch after raising the limit resumes at this cursor.
		$settings = new Settings();
		$service  = new \PerfLocale\MachineTranslation\TranslationService( $settings, \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
		if ( $service->would_exceed_limit( 1 ) ) {
			return [
				'created'       => (int) ( $chunk_result['created'] ?? 0 ),
				'skipped'       => (int) ( $chunk_result['skipped'] ?? 0 ),
				'failed'        => (int) ( $chunk_result['failed'] ?? 0 ),
				'kept'          => (int) ( $chunk_result['kept'] ?? 0 ),
				'first_warning' => (string) ( $chunk_result['first_warning'] ?? '' ),
				'first_error'   => $first_error,
				'cursor'        => $cursor,
				'done'          => true,
				'error'         => __( 'Stopped: monthly machine-translation character limit reached. Re-run after raising the limit to resume from this cursor.', 'perflocale' ),
			];
		}

		// Chain the next chunk. A cancel after the last tick reaches here: the worker then cancels the chunk queued
		// here through `next_job` (`next_duplicate`: folded into a job in flight, not this chunk's to cancel). A failed
		// dispatch is reported in the result.
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
			'first_error'    => $first_error,
			'cursor'         => $cursor,
			'done'           => false,
			'next_job'       => $next['job_id'] ?? null,
			'next_duplicate' => ! empty( $next['duplicate'] ),
			'next'           => $next['mode'] ?? 'unknown',
		];
	}
}
