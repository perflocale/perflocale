<?php
/**
 * Bulk MT translation of gettext strings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Database\Schema;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Translation\PlaceholderMasker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tier-2 job: MT-translate gettext strings into one or more target languages.
 *
 * Three selection modes:
 *
 *   1. `mode = 'ids'`    — operator selected specific rows via the admin
 *                          checkbox column. `string_ids` carries the list.
 *   2. `mode = 'filter'` — apply every active filter on the strings page
 *                          (domain, context, search, status) and translate
 *                          every row that matches. `filter` carries the
 *                          filter args.
 *   3. `mode = 'all'`    — translate every string in the table. Useful
 *                          after a fresh scan when nothing is translated
 *                          yet.
 *
 * Provider calls are batched via {@see TranslationService::translate_batch_texts}
 * (one provider request per N strings, not per string). Placeholders
 * (`%s`, `%1$d`, `{var}`, inline `<a>`/`<strong>`) are masked before MT
 * and restored after via {@see PlaceholderMasker}; translations that
 * lose a placeholder are rejected rather than silently shipping a
 * broken string to every page on the site.
 *
 * Source provenance: the translated VALUE goes to `string_translations`
 * (which has no provenance column of its own); the provenance lives on the
 * `translation_links` row this job upserts alongside it, as
 * `source = 'mt'` — {@see \PerfLocale\Enum\SourceType::MachineTranslation} —
 * with `status = 'translated'`. That link row is not bookkeeping: the DB-mode
 * gettext map, the files-mode generator and the strings grid's status filter
 * all INNER JOIN it, so a value written without one is never served.
 *
 * A link can only hang off a group whose type is 'string', and
 * `strings.group_id` is an unenforced FK that a scan, an import or a deleted
 * group can leave pointing at nothing (or at a live post/term group). Such a
 * string is therefore healed onto a fresh string-type group at the moment of
 * its first write — never up-front, and never counted as translated when the
 * heal fails. See {@see self::save_translation()}.
 */
final class BulkStringTranslateJob extends AbstractJob {

	/**
	 * Batch size for provider calls. 25 fits comfortably inside DeepL's
	 * 128 KB request limit even at 5 KB strings, keeps Google's per-call
	 * quota happy, and gives the progress callback enough granularity
	 * that a UI poll never sees a >5s freeze.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * Hard ceiling on how many strings any single dispatch can target.
	 * Prevents a misclick on a 100k-row table from costing $200 in
	 * provider fees. Filterable for sites that genuinely want bigger
	 * batches.
	 */
	private const MAX_STRINGS_PER_DISPATCH = 5000;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'bulk_string_translate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Same cap the per-row MT endpoint gates on, so the worker re-check
	 * inside the job pipeline matches the dispatch-side gate.
	 */
	public function get_required_capability(): string {
		return 'perflocale_use_mt';
	}

	/**
	 * {@inheritDoc}
	 *
	 * 50 picks the dividing line between "translator wants the page to
	 * stay open and watch the count tick up" and "this is going to run
	 * for a coffee break, hand control back to the admin." Tunable per
	 * site via Settings → Performance → Background Thresholds.
	 */
	public function get_default_threshold(): int {
		return 50;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Returns `string_count × target_lang_count` — the same product the
	 * threshold compares against.
	 */
	protected function args_size( array $args ): int {
		$string_count = $this->resolve_string_count( $args );
		$targets      = is_array( $args['target_lang_ids'] ?? null ) ? $args['target_lang_ids'] : [];
		return $string_count * count( $targets );
	}

	/**
	 * Resolve how many strings this dispatch will touch, without actually
	 * loading their rows. Used by args_size() pre-dispatch and by execute()
	 * to bound the work it'll do post-dispatch.
	 *
	 * @param array<string, mixed> $args
	 * @return int
	 */
	private function resolve_string_count( array $args ): int {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';

		if ( $mode === 'ids' ) {
			$ids = is_array( $args['string_ids'] ?? null ) ? $args['string_ids'] : [];
			// Count with the SAME positive-int filter resolve_string_ids()
			// uses, so the reported 'capped' shortfall is exact.
			return count( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		}

		$repo = new StringRepository( Plugin::get_instance()->get( 'cache' ) );

		if ( $mode === 'all' ) {
			return $repo->count();
		}

		if ( $mode === 'filter' ) {
			return $repo->count( $this->normalize_filter( $args ) );
		}

		return 0;
	}

	/**
	 * Resolve the actual list of string IDs to translate, applying the
	 * MAX_STRINGS_PER_DISPATCH ceiling.
	 *
	 * Filter mode is routed through StringRepository so every filter that
	 * works on the admin page (domain, context, search + search_mode,
	 * status, language_id) behaves identically here — no SQL drift.
	 *
	 * @param array<string, mixed> $args
	 * @return int[]
	 */
	/**
	 * Resolve the string IDs a dispatch with these args WOULD translate —
	 * public so the pre-dispatch budget gate and the /machine-translate/estimate
	 * endpoint can estimate cost with the job's exact selection semantics
	 * (mode=ids|filter|all, including the per-dispatch cap). Read-only.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int[] String row IDs.
	 */
	public function resolve_ids_for_estimate( array $args ): array {
		return $this->resolve_string_ids( $args );
	}

	private function resolve_string_ids( array $args ): array {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';
		$cap  = (int) apply_filters( 'perflocale/mt/bulk_string_max_per_dispatch', self::MAX_STRINGS_PER_DISPATCH );

		if ( $mode === 'ids' ) {
			$ids = (array) ( $args['string_ids'] ?? [] );
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			return array_slice( $ids, 0, $cap );
		}

		$repo   = new StringRepository( Plugin::get_instance()->get( 'cache' ) );
		$filter = $mode === 'filter' ? $this->normalize_filter( $args ) : [];

		$rows = $repo->find_all(
			array_merge(
				$filter,
				[
					'limit'  => $cap,
					'offset' => 0,
				]
			)
		);

		return array_values( array_map( static fn( $r ): int => (int) $r->id, $rows ) );
	}

	/**
	 * Coerce the inbound filter payload to the shape StringRepository expects.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function normalize_filter( array $args ): array {
		$filter = is_array( $args['filter'] ?? null ) ? $args['filter'] : [];

		return [
			'domain'      => (string) ( $filter['domain'] ?? '' ),
			'context'     => (string) ( $filter['context'] ?? '' ),
			'search'      => (string) ( $filter['search'] ?? '' ),
			'search_mode' => (string) ( $filter['search_mode'] ?? 'contains' ),
			'status'      => (string) ( $filter['status'] ?? '' ),
			'language_id' => (int) ( $filter['language_id'] ?? 0 ),
		];
	}

	/**
	 * Execute the bulk translation.
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $progress
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the active MT provider returns an unrecoverable error mid-batch.
	 */
	public function execute( array $args, callable $progress ): array {
		$target_lang_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );
		$provider_id     = sanitize_key( (string) ( $args['provider_id'] ?? '' ) );
		$skip_existing   = isset( $args['skip_existing'] ) ? (bool) $args['skip_existing'] : true;

		if ( $target_lang_ids === [] ) {
			return $this->empty_result( 'No target languages provided.' );
		}

		$string_ids = $this->resolve_string_ids( $args );
		if ( $string_ids === [] ) {
			return $this->empty_result( 'No matching strings to translate.' );
		}

		// Surface (never silently swallow) any rows beyond the per-dispatch
		// cap. resolve_string_ids() truncates to MAX_STRINGS_PER_DISPATCH; if
		// the filter/all set actually matched more, report the shortfall in the
		// result so the operator knows to re-run rather than assuming the whole
		// set was translated.
		$cap     = (int) apply_filters( 'perflocale/mt/bulk_string_max_per_dispatch', self::MAX_STRINGS_PER_DISPATCH );
		$dropped = 0;
		if ( count( $string_ids ) >= $cap ) {
			$dropped = max( 0, $this->resolve_string_count( $args ) - count( $string_ids ) );
		}

		$plugin   = Plugin::get_instance();
		$settings = $plugin->get( 'settings' );
		$cache    = $plugin->get( 'cache' );

		if ( ! $settings->mt_enabled() ) {
			throw new \RuntimeException( esc_html__( 'Machine translation is disabled in settings.', 'perflocale' ) );
		}

		$lang_repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang_by_id = [];
		foreach ( $lang_repo->get_active() as $lang ) {
			$lang_by_id[ (int) $lang->id ] = $lang;
		}

		// Default language is the source for MT.
		$default = $lang_repo->get_default();
		if ( ! $default ) {
			throw new \RuntimeException( esc_html__( 'No default language configured; cannot determine the MT source locale.', 'perflocale' ) );
		}
		$source_lang_slug = (string) $default->slug;

		$service = new TranslationService( $settings, $cache );

		$string_repo      = new StringRepository( $cache );
		$translation_repo = new StringTranslationRepository( $cache );
		$group_repo       = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );

		// Bulk-load every source row once. resolve_string_ids() has already
		// bounded the set to MAX_STRINGS_PER_DISPATCH so the SELECT-IN
		// stays tractable.
		$strings_by_id = $this->load_strings_by_ids( $string_ids );

		$total      = count( $string_ids ) * count( $target_lang_ids );
		$processed  = 0;
		$translated = 0;
		$skipped    = 0;
		// Rows skipped because a translation ALREADY exists for the target
		// (the skip-existing branch). On a crash-resume that finds every row
		// already persisted this is > 0 while $translated stays 0 — yet the
		// completion cache-bust/files-regen still has to run, or the prior
		// run's writes stay invisible on the front end. Tracked separately
		// from $skipped so pure empty-source/missing-row runs don't force a
		// needless file regeneration.
		$already_translated = 0;
		$failed             = 0;
		$first_error        = '';

		// Throttle progress to ~100 callbacks total — see BulkTranslateJob
		// for the rationale; same shape. Count-based AND wall-clock-based: the
		// progress callback is the ONLY place the job/type locks refresh and the
		// cancel/pause probes run, and one BATCH_SIZE chunk can block for minutes
		// under a rate-limited provider — a pure count-based gap (total/100) can
		// then exceed the 1800s lock TTL and let a second same-type job reclaim
		// the type lock mid-run. One microtime comparison per chunk is the cost.
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

		// try/finally: the $tick()/$progress() callbacks THROW JobCanceledException
	// when the operator cancels — without the finally, a cancel at e.g. 80%
	// skipped the post-loop cache sync below, leaving every ALREADY-translated
	// string invisible on the front end until the cache expired on its own.
	try {
	foreach ( $target_lang_ids as $target_lang_id ) {
			$target_lang = $lang_by_id[ $target_lang_id ] ?? null;
			if ( $target_lang === null ) {
				$processed += count( $string_ids );
				$failed    += count( $string_ids );
				if ( $first_error === '' ) {
					$first_error = 'Unknown target language id: ' . (int) $target_lang_id;
				}
				$tick( $processed );
				continue;
			}

			$target_lang_slug = (string) $target_lang->slug;

			// Walk the ID list in BATCH_SIZE chunks. Each chunk is one
			// provider call.
			foreach ( array_chunk( $string_ids, self::BATCH_SIZE ) as $chunk_ids ) {
				$batch_inputs     = []; // batch index → masked source text.
				$batch_string_ids = []; // batch index → string ID.
				$batch_phs        = []; // batch index → list of placeholder tokens.

				// Prefetch existing translations for the whole chunk in ONE
				// query (was one get() per string). Missing key === no/empty
				// translation === translatable, matching get()==='' semantics.
				$existing_for_chunk = $skip_existing
					? $translation_repo->get_many( $chunk_ids, (int) $target_lang->id )
					: [];

				foreach ( $chunk_ids as $sid ) {
					$row = $strings_by_id[ $sid ] ?? null;
					if ( ! $row ) {
						++$skipped;
						++$processed;
						continue;
					}

					// Skip rule: existing non-empty translation for this
					// (string, target language) — never overwrite a
					// human-edited translation with MT output.
					if ( $skip_existing && ( $existing_for_chunk[ (int) $row->id ] ?? '' ) !== '' ) {
						++$skipped;
						++$already_translated;
						++$processed;
						continue;
					}

					$source = (string) $row->original;
					if ( trim( $source ) === '' ) {
						++$skipped;
						++$processed;
						continue;
					}

					[ $masked, $phs ] = PlaceholderMasker::mask( $source );

					$batch_inputs[]     = $masked;
					$batch_string_ids[] = (int) $row->id;
					$batch_phs[]        = $phs;
				}

				if ( $batch_inputs === [] ) {
					$tick( $processed );
					continue;
				}

				// One provider call per batch.
				try {
					$results = $service->translate_batch_texts(
						$batch_inputs,
						$source_lang_slug,
						$target_lang_slug,
						$provider_id,
						/* fast_fail */ false
					);
				} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
					// Provider breaker tripped mid-run: every remaining call
					// would throw instantly. Abort so the un-attempted strings
					// stay untranslated (not failed) for a later re-run, rather
					// than the generic catch below flooding them all as failures.
					if ( $first_error === '' ) {
						$first_error = $e->getMessage();
					}
					break 2;
				} catch ( \Throwable $e ) {
					$failed    += count( $batch_inputs );
					$processed += count( $batch_inputs );
					if ( $first_error === '' ) {
						$first_error = $e->getMessage();
					}
					$tick( $processed );
					continue;
				}

				// Per-batch-entry post-processing: restore placeholders,
				// verify integrity, persist.
				foreach ( $batch_inputs as $i => $_input ) {
					$string_id         = $batch_string_ids[ $i ] ?? 0;
					$phs               = $batch_phs[ $i ] ?? [];
					$row               = $strings_by_id[ $string_id ] ?? null;
					$translated_masked = (string) ( $results[ $i ] ?? '' );

					if ( ! $row || $translated_masked === '' ) {
						++$failed;
						++$processed;
						if ( $first_error === '' ) {
							$first_error = 'Empty translation returned for string #' . $string_id;
						}
						continue;
					}

					$translated_text = PlaceholderMasker::restore( $translated_masked, $phs );

					// Integrity gate: reject translations that lost a
					// placeholder. Better to mark the row failed than to
					// ship a malformed gettext string to every visitor.
					if ( ! PlaceholderMasker::preserves_placeholders( (string) $row->original, $translated_text ) ) {
						++$failed;
						++$processed;
						if ( $first_error === '' ) {
							$first_error = sprintf(
								'Translation for string #%d dropped a placeholder; rejected.',
								$string_id
							);
						}
						continue;
					}

					// Persist the pair. save_translation() repairs a string whose
					// group_id cannot legally carry a link BEFORE it writes
					// anything, and writes the link before the value, so a row
					// this job counts as translated is a row the front end can
					// actually serve. Anything it could not complete comes back
					// as an operator-facing reason and the row is counted failed
					// — a job that spends money must not report an attempt as a
					// success. See the method for the ordering rules.
					$save_error = $this->save_translation(
						$group_repo,
						$translation_repo,
						$row,
						(int) $target_lang->id,
						$translated_text
					);

					if ( $save_error !== '' ) {
						++$failed;
						++$processed;

						if ( $first_error === '' ) {
							$first_error = $save_error;
						}

						continue;
					}

					++$translated;

					/**
					 * Fires after each successful string MT save. Lets
					 * 3rd-party code mark the row, trigger review
					 * workflows, etc.
					 *
					 * @hook  perflocale/mt/string_translated
					 * @param int    $string_id
					 * @param int    $target_lang_id
					 * @param string $translation
					 * @param string $source
					 */
					do_action(
						'perflocale/mt/string_translated',
						$string_id,
						(int) $target_lang->id,
						$translated_text,
						(string) $row->original
					);

					++$processed;
				}

				$tick( $processed );
			}
		}

		// Final tick — ensures the UI sees 100% even when total isn't
		// divisible by tick_every. Can throw on cancel too — the finally still runs.
		$progress( $processed, $total );
	} finally {
		// Runs on normal completion AND on cancel/exception: saving via the
		// repository skips the cache invalidation the
		// single-string admin path performs, so bust the per-language bulk
		// translation cache here — otherwise the new strings stay invisible on
		// the front end until the cache expires. In files mode also regenerate
		// the `*.l10n.php` files, which are the source of truth there.
		if ( $translated > 0 || $already_translated > 0 ) {
			foreach ( $target_lang_ids as $tlid ) {
				$cache->delete( "all_string_translations_{$tlid}", 'perflocale_strings' );
			}

			if ( (string) $settings->get( 'string_translation_mode', '' ) === 'files' ) {
				set_transient( 'perflocale_strings_regenerating', 1, 5 * MINUTE_IN_SECONDS );
				/** @hook perflocale/strings/regenerate_files Regenerate the files-mode translation files after bulk string MT. */
				do_action( 'perflocale/strings/regenerate_files', $cache );
			}

			/**
			 * Fires after a bulk string-translation job changes the
			 * `string_translations` table — parity with the admin Strings
			 * save (AdminController) and PO import (PoSync) paths so addons
			 * that derive state from strings (e.g. the Visual Editor's
			 * per-language bundles) invalidate it after bulk MT too.
			 *
			 * @hook perflocale/strings/changed
			 *
			 * @param string $origin What changed the strings ('bulk_mt').
			 */
			do_action( 'perflocale/strings/changed', 'bulk_mt' );
		}
	}

		return [
			'total'       => $total,
			'translated'  => $translated,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'targets'     => count( $target_lang_ids ),
			'capped'      => $dropped,
			'first_error' => $first_error,
		];
	}

	/**
	 * Persist one machine-translated string, and make sure it can be SERVED.
	 *
	 * Three writes, in an order that is load-bearing:
	 *
	 *   1. When `strings.group_id` does not resolve to a string-type group,
	 *      heal it onto a fresh one ({@see self::mint_string_group()}). That FK
	 *      is unenforced and three shapes fail it — 0 (never grouped), an id
	 *      whose group row is gone, and an id that collides with a live
	 *      post/term group — and a string link cannot legally hang off any of
	 *      them. The heal is deferred to here, the first write for the row,
	 *      exactly as `AdminController::process_string_translations()` defers
	 *      it: minting for every selected string would create thousands of
	 *      groups on a site whose strings are mostly untranslated. A row whose
	 *      group is already correct pays NO extra query for the test —
	 *      `is_string_group()` is memoised per request, and this is the same
	 *      call the link write made before, moved ahead of the value write.
	 *   2. The `translation_links` row, BEFORE the value. The value alone is
	 *      never served: the DB-mode gettext map, the files-mode generator
	 *      fetch and the strings grid's status filter all INNER JOIN links
	 *      through the group. The order is also the recovery contract — a value
	 *      with no link is skipped by the skip-existing branch on every later
	 *      run and so stays unservable forever, whereas a link with no value is
	 *      simply re-attempted next run and serves nothing in the meantime,
	 *      because the read path drives from `string_translations`.
	 *   3. The value itself.
	 *
	 * Before this, the job wrote the value, silently skipped the link whenever
	 * the group was unusable, and still counted the row translated. On a site
	 * where 15,414 of 15,419 strings had a group_id that resolved to nothing,
	 * that meant database mode served source text forever and the operator was
	 * billed for every one of them.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo       Group/link repository.
	 * @param StringTranslationRepository                                $translation_repo Translation-value repository.
	 * @param object                                                     $row              Source `strings` row; its `group_id` is updated in place when healed, so the caller's remaining target languages reuse the new group instead of minting another.
	 * @param int                                                        $language_id      Target language id.
	 * @param string                                                     $translated_text  Text to store.
	 * @return string Empty string on success; otherwise the operator-facing reason nothing was stored.
	 */
	private function save_translation(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		StringTranslationRepository $translation_repo,
		object $row,
		int $language_id,
		string $translated_text
	): string {
		$string_id         = (int) $row->id;
		$group_id          = (int) ( $row->group_id ?? 0 );
		$original_group_id = $group_id;
		$minted_group_id   = 0;

		if ( ! $group_repo->is_string_group( $group_id ) ) {
			$group_id        = $this->mint_string_group( $string_id );
			$minted_group_id = $group_id;

			if ( $group_id === 0 ) {
				return sprintf(
					/* translators: %d: id of the row in the plugin's strings table. */
					__( 'Could not create a translation group for string #%d; its translation was not saved. Re-run to retry.', 'perflocale' ),
					$string_id
				);
			}

			$row->group_id = $group_id;
		}

		// For string groups object_id is the string id. upsert_link() is a single
		// INSERT … ON DUPLICATE KEY UPDATE, so this string's sibling-language
		// links survive untouched.
		$linked = $group_repo->upsert_link(
			$group_id,
			$string_id,
			$language_id,
			'translated',
			\PerfLocale\Enum\SourceType::MachineTranslation
		);

		if ( $linked === false ) {
			// translation_links carries TWO unique keys — group_lang
			// (group_id, language_id) AND object_lang (type, object_id,
			// language_id). When a heal has just moved this string to a new
			// group but an orphaned type='string' link for the same
			// (object_id, language_id) still points at the OLD group, the
			// INSERT collides on object_lang instead: the ON DUPLICATE KEY
			// UPDATE rewrites that stale row while leaving its group_id on the
			// dead group, so upsert_link()'s own re-SELECT against the NEW
			// group finds nothing and reports false.
			//
			// The stale row is unreachable debris — its group no longer exists
			// or is no longer this string's — so drop it and retry once. Scoped
			// by type, exactly as DataImporter::reap_orphan_string_links()
			// does: object_id is polymorphic and a post/term id collides freely
			// with a string id.
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Debris removal on a self-heal path; no cache to consult.
			$wpdb->delete(
				Schema::table( 'translation_links' ),
				[
					'type'        => \PerfLocale\Enum\ObjectType::String->value,
					'object_id'   => $string_id,
					'language_id' => $language_id,
				],
				[ '%s', '%d', '%d' ]
			);

			$linked = $group_repo->upsert_link(
				$group_id,
				$string_id,
				$language_id,
				'translated',
				\PerfLocale\Enum\SourceType::MachineTranslation
			);
		}

		if ( $linked === false ) {
			// Leave nothing widowed: if this call minted the group moments ago,
			// reclaim it by primary key and put the row back where it was, so a
			// failed save is a true no-op rather than a fresh orphan.
			if ( $minted_group_id > 0 ) {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback of a write this method just made.
				$wpdb->delete(
					Schema::table( 'translation_groups' ),
					[ 'id' => $minted_group_id ],
					[ '%d' ]
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback of a write this method just made.
				$wpdb->update(
					Schema::table( 'strings' ),
					[ 'group_id' => $original_group_id ],
					[ 'id' => $string_id ],
					[ '%d' ],
					[ '%d' ]
				);

				$row->group_id = $original_group_id;
			}

			return sprintf(
				/* translators: %d: id of the row in the plugin's strings table. */
				__( 'Could not link string #%d to the target language; its translation was not saved. Re-run to retry.', 'perflocale' ),
				$string_id
			);
		}

		if ( ! $translation_repo->set( $string_id, $language_id, $translated_text ) ) {
			return sprintf( 'Failed to persist translation for string #%d.', $string_id );
		}

		return '';
	}

	/**
	 * Mint a fresh string-type translation group and repoint one string at it.
	 *
	 * `strings.group_id` is an unenforced foreign key, so a row can point at
	 * nothing at all or at a live post/term group whose links a string write
	 * would repoint. Both are repaired the same way, and it is the same pair of
	 * statements in the same order that
	 * {@see \PerfLocale\Admin\AdminController::process_string_translations()}
	 * and {@see \PerfLocale\Strings\TranslationFileGenerator::repair_orphaned_translations()}
	 * shape (2) run — one INSERT of a `type = 'string'` group, one UPDATE
	 * moving the string onto it.
	 *
	 * Both statements are checked, because this job reports counts an operator
	 * spends money against. A failed INSERT leaves the string exactly as it
	 * was. A failed UPDATE — or a zero-row one, meaning the `strings` row went
	 * away between the batch load and now — would leave the string still
	 * pointing at the unusable id while a usable group sat under a different
	 * one, so the group is reclaimed rather than widowed. Either way the caller
	 * gets 0 and counts the row FAILED. The provider call for that row has
	 * already been made and cannot be refunded — what this buys the operator is
	 * a truthful count and a first_error naming the row, instead of a success
	 * tally for a translation nothing can serve.
	 *
	 * @param int $string_id Row in the `strings` table to repair.
	 * @return int New group id, or 0 when the repair could not be completed.
	 */
	private function mint_string_group( int $string_id ): int {
		if ( $string_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write; there is no cache to consult and no repository accessor that mints a bare string group.
		$inserted = $wpdb->insert(
			Schema::table( 'translation_groups' ),
			[ 'type' => \PerfLocale\Enum\ObjectType::String->value ],
			[ '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		// insert_id is only read after a confirmed INSERT: on a failed one it
		// can still hold a PRIOR row's id rather than 0, depending on the
		// mysqli driver — the same reason StringRepository::bulk_insert()
		// refuses to trust it when a group insert fails.
		$group_id = (int) $wpdb->insert_id;

		if ( $group_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write, keyed on the primary key.
		$updated = $wpdb->update(
			Schema::table( 'strings' ),
			[ 'group_id' => $group_id ],
			[ 'id' => $string_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( ! is_int( $updated ) || $updated < 1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reclaiming the group this method just created, by primary key.
			$wpdb->delete( Schema::table( 'translation_groups' ), [ 'id' => $group_id ], [ '%d' ] );

			return 0;
		}

		return $group_id;
	}

	/**
	 * Bulk-load every source-string row in one query.
	 *
	 * @param int[] $ids
	 * @return array<int, object> string_id => row
	 */
	private function load_strings_by_ids( array $ids ): array {
		if ( $ids === [] ) {
			return [];
		}

		global $wpdb;
		$table        = Schema::table( 'strings' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, group_id, domain, context, original FROM %i WHERE id IN ({$placeholders})",
				$table,
				...$ids
			)
		);
		// phpcs:enable

		$by_id = [];
		foreach ( (array) $rows as $r ) {
			$by_id[ (int) $r->id ] = $r;
		}
		return $by_id;
	}

	private function empty_result( string $reason ): array {
		return [
			'total'       => 0,
			'translated'  => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'targets'     => 0,
			'capped'      => 0,
			'first_error' => $reason,
		];
	}
}
