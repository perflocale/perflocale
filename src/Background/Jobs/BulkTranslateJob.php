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
 *
 * Skip rule: a (source, target) pair is skipped when the source already
 * has a translation in that target language — no overwrites.
 *
 * args_size() returns `count(source_ids) * count(target_lang_ids)` — the
 * total number of provider calls this dispatch will potentially fan out
 * to. That matches what an operator intuits as "how big is this job"
 * and pairs naturally with the per-job threshold setting.
 */
final class BulkTranslateJob extends AbstractJob {

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
	 * @param array<string, mixed> $args     `source_ids` + `target_lang_ids`
	 *                                       per the class-level docblock.
	 * @param callable             $progress `function(int, int): void` —
	 *                                       reports (processed, total).
	 * @return array<string, mixed> `created`, `skipped`, `failed`, `first_error`.
	 * @throws \RuntimeException When machine translation is disabled for the site
	 *                            after the job was queued.
	 */
	public function execute( array $args, callable $progress ): array {
		// Meta-field MT is per-dispatch opt-in (the curated key registry is
		// additionally setting-gated, so true with everything off is a no-op).
		$include_meta = ! empty( $args['include_meta'] );

		$source_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['source_ids'] ?? [] ) ) ) );
		$target_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );

		if ( $source_ids === [] || $target_ids === [] ) {
			return [
				'created'     => 0,
				'skipped'     => 0,
				'failed'      => 0,
				'first_error' => '',
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

		// Bulk-prime the L1 translation cache for every source post in
		// one SELECT. Without this, get_translation_id() inside the
		// inner loop pays the cold-path cost (~1-2 ms) on every first
		// hit per source — N source posts × that cost is wasted work on
		// sites above the eager-link-map cap. Below the cap this is a
		// no-op (eager map serves these in µs).
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$repo->prime_translations(
			\PerfLocale\Enum\ObjectType::Post,
			array_map( 'intval', $source_ids )
		);

		$total       = count( $source_ids ) * count( $target_ids );
		$processed   = 0;
		$created     = 0;
		$skipped     = 0;
		$failed      = 0;
		$first_error = '';

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
		$tick         = static function ( int $done ) use ( &$last_tick, &$last_tick_at, $tick_every, $total, $progress ): void {
			if ( $done === 0
				|| $done === $total
				|| ( $done - $last_tick ) >= $tick_every
				|| ( microtime( true ) - $last_tick_at ) >= 30.0
			) {
				$progress( $done, $total );
				$last_tick    = $done;
				$last_tick_at = microtime( true );
			}
		};

		$tick( 0 );

		// Suspend the eager link map for the loop: every created link
		// invalidates it, and the next row's get_translation_id read would
		// otherwise rebuild-and-persist the WHOLE map — O(rows × map size).
		// Readers fall back to the JOIN path while suspended; one
		// invalidation after the loop leaves the next request to rebuild a
		// fresh map once.
		\PerfLocale\Database\Repository\TranslationGroupRepository::suspend_eager_link_map();

		try {
			$this->run_rows( $source_ids, $target_ids, $lang_by_id, $manager, $service, $settings, $include_meta, $total, $tick, $processed, $created, $skipped, $failed, $first_error );
		} finally {
			\PerfLocale\Database\Repository\TranslationGroupRepository::resume_eager_link_map();
			\PerfLocale\Plugin::get_instance()->get( 'group_repo' )->invalidate_eager_link_map();
		}

		return [
			'created'     => $created,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'first_error' => $first_error,
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
	 * @return void
	 */
	private function run_rows( array $source_ids, array $target_ids, array $lang_by_id, object $manager, object $service, object $settings, bool $include_meta, int $total, callable $tick, int &$processed, int &$created, int &$skipped, int &$failed, string &$first_error ): void {
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

		foreach ( $source_ids as $source_id ) {
			// Re-check the per-row capability inside the worker. The
			// dispatch-side capability gate is `perflocale_manage_translations`;
			// individual posts still respect `edit_post` so a user can't
			// trigger MT for content they couldn't otherwise edit.
			if ( ! $can_use_mt || ! current_user_can( 'edit_post', $source_id ) ) {
				$skipped   += count( $target_ids );
				$processed += count( $target_ids );
				$tick( $processed );
				continue;
			}

			foreach ( $target_ids as $target_lang_id ) {
				$target_lang = $lang_by_id[ $target_lang_id ] ?? null;

				if ( $target_lang === null ) {
					// Unresolvable language id: count as FAILED (silently
					// dropping it let a bogus-language site-wide chain grind
					// through every chunk reporting all-green zeros).
					++$failed;
					if ( $first_error === '' ) {
						$first_error = sprintf( 'Unknown target language id %d.', $target_lang_id );
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
		}
	}
}
