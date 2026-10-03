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
use PerfLocale\Strings\PluralRules;
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
 * With skip-existing (the default), `filter` and `all` select only rows
 * that still miss a translation in a target language, so repeated runs
 * work through a table larger than the per-dispatch cap.
 *
 * Providers that translate a list in one request are called once per
 * chunk via {@see TranslationService::translate_batch_texts}; the others
 * once per string. Tag-free strings are sent and sanitised as plain text,
 * the rest as HTML. Placeholders (`%s`, `%1$d`, `{var}`, inline
 * `<a>`/`<strong>`) are masked before MT and restored after via
 * {@see PlaceholderMasker}; translations that lose a placeholder are
 * rejected rather than silently shipping a broken string to every page on
 * the site. Plural rows get every plural form of the target language
 * (see {@see self::plural_form_texts()}).
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
 *
 * @phpstan-type StringItem array{id: int, row: object, original: string, format: string, texts: array<int, string>, phs: array<int, array<int, string>>, numbers: array<int, int>|null, only: array<int, bool>, token: string}
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
	 * The capability post bulk machine translation requires: a bulk run can
	 * overwrite every string translation on the site. The REST route also
	 * requires `perflocale_use_mt`.
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
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
	 * to report the rows left beyond the per-dispatch cap.
	 *
	 * @param array<string, mixed> $args Dispatch args.
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

		return $repo->count( $this->selection_filter( $args ) );
	}

	/**
	 * Resolve the string IDs a dispatch with these args WOULD translate —
	 * public so the pre-dispatch budget gate and the /machine-translate/estimate
	 * endpoint can estimate cost with the job's exact selection semantics
	 * (mode=ids|filter|all, the skip-existing selection and the per-dispatch
	 * cap). Read-only.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int[] String row IDs.
	 */
	public function resolve_ids_for_estimate( array $args ): array {
		return $this->resolve_string_ids( $args );
	}

	/**
	 * Resolve the actual list of string IDs to translate, applying the
	 * MAX_STRINGS_PER_DISPATCH ceiling.
	 *
	 * Filter mode is routed through StringRepository so every filter that
	 * works on the admin page (domain, context, search + search_mode,
	 * status, language_id) behaves identically here — no SQL drift.
	 *
	 * With skip-existing (the default) `all` and `filter` select only rows
	 * that still miss a translation in at least one target language, so each
	 * run advances past the rows the previous run translated. Overwrite runs
	 * select the first rows in table order.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int[]
	 */
	private function resolve_string_ids( array $args ): array {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';
		$cap  = (int) apply_filters( 'perflocale/mt/bulk_string_max_per_dispatch', self::MAX_STRINGS_PER_DISPATCH );

		if ( $mode === 'ids' ) {
			$ids = (array) ( $args['string_ids'] ?? [] );
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			return array_slice( $ids, 0, $cap );
		}

		$repo = new StringRepository( Plugin::get_instance()->get( 'cache' ) );

		$rows = $repo->find_all(
			array_merge(
				$this->selection_filter( $args ),
				[
					'limit'  => $cap,
					'offset' => 0,
				]
			)
		);

		return array_values( array_map( static fn( $r ): int => (int) $r->id, $rows ) );
	}

	/**
	 * StringRepository filter for the `all` and `filter` modes.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @param bool                 $with_missing Add the skip-existing selection (rows still missing a target translation).
	 * @return array<string, mixed>
	 */
	private function selection_filter( array $args, bool $with_missing = true ): array {
		$filter = ( ( $args['mode'] ?? '' ) === 'filter' ) ? $this->normalize_filter( $args ) : [];

		$skip_existing = isset( $args['skip_existing'] ) ? (bool) $args['skip_existing'] : true;
		$targets       = array_values( array_filter( wp_parse_id_list( (array) ( $args['target_lang_ids'] ?? [] ) ) ) );

		if ( $with_missing && $skip_existing && $targets !== [] ) {
			$filter['missing_translation_language_ids'] = $targets;
		}

		return $filter;
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
	 * Why a dispatch selected no rows.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return string
	 */
	private function nothing_selected_reason( array $args ): string {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';

		if ( $mode !== 'ids' ) {
			$filter = $this->selection_filter( $args );

			if ( isset( $filter['missing_translation_language_ids'] ) ) {
				$repo = new StringRepository( Plugin::get_instance()->get( 'cache' ) );

				if ( $repo->count( $this->selection_filter( $args, false ) ) > 0 ) {
					return __( 'Every selected string already has a translation in the chosen languages.', 'perflocale' );
				}
			}
		}

		return __( 'No matching strings to translate.', 'perflocale' );
	}

	/**
	 * Execute the bulk translation.
	 *
	 * Providers that translate a whole list in one request (DeepL, Google,
	 * Microsoft) get one call per chunk and destination format. Every other
	 * provider (the WordPress AI Client, LibreTranslate, providers built on
	 * AbstractProvider's per-text loop) is called once per string: a failure
	 * then fails that string only, and a cancel lands before the next string.
	 *
	 * A run in which nothing was translated and something failed (or the
	 * provider's breaker stopped it) returns `run_failed => true`, which the
	 * worker records as a failed job, retryable from the Jobs page.
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $progress
	 * @return array<string, mixed>
	 * @throws \RuntimeException When machine translation is disabled or no default language is configured.
	 * @throws \PerfLocale\Background\JobCanceledException When the operator cancels or pauses; it carries the counts so far.
	 */
	public function execute( array $args, callable $progress ): array {
		$target_lang_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );
		$provider_id     = sanitize_key( (string) ( $args['provider_id'] ?? '' ) );
		$skip_existing   = isset( $args['skip_existing'] ) ? (bool) $args['skip_existing'] : true;

		if ( $target_lang_ids === [] ) {
			return $this->empty_result( __( 'No target languages provided.', 'perflocale' ) );
		}

		$string_ids = $this->resolve_string_ids( $args );
		if ( $string_ids === [] ) {
			return $this->empty_result( $this->nothing_selected_reason( $args ) );
		}

		// Surface (never silently swallow) any rows beyond the per-dispatch
		// cap. resolve_string_ids() truncates to MAX_STRINGS_PER_DISPATCH; if
		// the selection actually matched more, report how many are left so the
		// operator knows to re-run rather than assuming the whole set was
		// translated.
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

		$translation_repo = new StringTranslationRepository( $cache );
		$group_repo       = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );

		$per_string = ! $this->provider_batches_natively( $service, $provider_id );

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
		$breaker_stopped    = false;

		// Throttle progress to ~100 callbacks total — see BulkTranslateJob
		// for the rationale; same shape. Count-based AND wall-clock-based: the
		// progress callback is the ONLY place the job/type locks refresh and the
		// cancel/pause probes run, and one BATCH_SIZE chunk can block for minutes
		// under a rate-limited provider — a pure count-based gap (total/100) can
		// then exceed the 1800s lock TTL and let a second same-type job reclaim
		// the type lock mid-run. One microtime comparison per chunk is the cost.
		// Per-string mode calls $progress directly after every provider call
		// instead, so a cancel lands before the next string is sent.
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
						$first_error = sprintf(
							/* translators: %d: language ID. */
							__( 'Unknown target language id: %d', 'perflocale' ),
							(int) $target_lang_id
						);
					}
					$tick( $processed );
					continue;
				}

				$target_lang_slug = (string) $target_lang->slug;
				$target_vars      = is_object( $target_lang ) ? get_object_vars( $target_lang ) : [];
				$target_locale    = is_string( $target_vars['locale'] ?? null ) && $target_vars['locale'] !== '' ? $target_vars['locale'] : $target_lang_slug;

				// Walk the ID list in BATCH_SIZE chunks.
				foreach ( array_chunk( $string_ids, self::BATCH_SIZE ) as $chunk_ids ) {
					$items = [];

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

						if ( trim( (string) $row->original ) === '' ) {
							++$skipped;
							++$processed;
							continue;
						}

						$items[] = self::build_item( $row, $target_locale );
					}

					if ( $items === [] ) {
						$tick( $processed );
						continue;
					}

					if ( $per_string ) {
						foreach ( $items as $item ) {
							try {
								$outputs = $service->translate_batch_texts( $item['texts'], $source_lang_slug, $target_lang_slug, $provider_id, false, $item['format'] );
							} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
								// Provider breaker tripped: every further call would
								// throw instantly. Stop, leaving the un-attempted
								// strings untranslated (not failed) for a re-run.
								if ( $first_error === '' ) {
									$first_error = $e->getMessage();
								}
								$breaker_stopped = true;
								break 3;
							} catch ( \Throwable $e ) {
								++$failed;
								++$processed;
								if ( $first_error === '' ) {
									$first_error = $e->getMessage();
								}
								$progress( $processed, $total );
								continue;
							}

							$this->settle_item( $group_repo, $translation_repo, $item, $outputs, $target_lang_id, $translated, $failed, $processed, $first_error );
							$progress( $processed, $total );
						}

						continue;
					}

					// One provider call per destination format for the chunk.
					$keys_by_format = [];
					foreach ( $items as $k => $item ) {
						$keys_by_format[ $item['format'] ][] = $k;
					}

					foreach ( $keys_by_format as $format => $keys ) {
						$flat   = [];
						$slices = [];
						foreach ( $keys as $k ) {
							$slices[ $k ] = [ count( $flat ), count( $items[ $k ]['texts'] ) ];
							foreach ( $items[ $k ]['texts'] as $text ) {
								$flat[] = $text;
							}
						}

						try {
							$results = $service->translate_batch_texts( $flat, $source_lang_slug, $target_lang_slug, $provider_id, false, (string) $format );
						} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
							if ( $first_error === '' ) {
								$first_error = $e->getMessage();
							}
							$breaker_stopped = true;
							break 3;
						} catch ( \Throwable $e ) {
							// The whole request failed: every string in it was attempted.
							$failed    += count( $keys );
							$processed += count( $keys );
							if ( $first_error === '' ) {
								$first_error = $e->getMessage();
							}
							continue;
						}

						foreach ( $keys as $k ) {
							$this->settle_item( $group_repo, $translation_repo, $items[ $k ], array_slice( $results, $slices[ $k ][0], $slices[ $k ][1] ), $target_lang_id, $translated, $failed, $processed, $first_error );
						}
					}

					$tick( $processed );
				}
			}

			// Final tick — ensures the UI sees 100% even when total isn't
			// divisible by tick_every. Can throw on cancel too — the finally still runs.
			$progress( $processed, $total );
		} catch ( \PerfLocale\Background\JobCanceledException $e ) {
			// The worker stores these counts on the canceled job.
			$e->set_result( $this->run_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, false ) );
			throw $e;
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
				 * that derive state from strings (e.g. per-language bundles)
				 * invalidate it after bulk MT too.
				 *
				 * @hook perflocale/strings/changed
				 *
				 * @param string $origin What changed the strings ('bulk_mt').
				 */
				do_action( 'perflocale/strings/changed', 'bulk_mt' );
			}
		}

		$run_failed = $translated === 0 && ( $failed > 0 || $breaker_stopped ) && $first_error !== '';

		return $this->run_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $run_failed );
	}

	/**
	 * Apply one item's provider answer and update the run's counters.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo       Group/link repository.
	 * @param StringTranslationRepository                                $translation_repo Value repository.
	 * @param array<string, mixed>                                       $item             From build_item().
	 * @phpstan-param StringItem $item
	 * @param string[]                                                   $outputs          Provider answers, parallel to the item's texts.
	 * @param int                                                        $language_id      Target language id.
	 * @param int                                                        $translated       Saved count (by ref).
	 * @param int                                                        $failed           Failed count (by ref).
	 * @param int                                                        $processed        Processed count (by ref).
	 * @param string                                                     $first_error      First failure reason (by ref).
	 * @return void
	 */
	private function settle_item(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		StringTranslationRepository $translation_repo,
		array $item,
		array $outputs,
		int $language_id,
		int &$translated,
		int &$failed,
		int &$processed,
		string &$first_error
	): void {
		$outcome = $this->persist_item( $group_repo, $translation_repo, $item, $outputs, $language_id );

		++$processed;

		if ( $outcome['error'] !== '' && $first_error === '' ) {
			$first_error = $outcome['error'];
		}

		if ( ! $outcome['saved'] ) {
			++$failed;
			return;
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
			$item['id'],
			$language_id,
			$outcome['translation'],
			$item['original']
		);
	}

	/**
	 * The result array execute() returns.
	 *
	 * @param int    $total       Rows × targets.
	 * @param int    $translated  Saved.
	 * @param int    $skipped     Skipped (already translated, blank, missing row).
	 * @param int    $failed      Attempted and failed.
	 * @param int    $targets     Target language count.
	 * @param int    $capped      Rows still missing beyond the per-dispatch cap.
	 * @param string $first_error First failure reason.
	 * @param bool   $run_failed  Nothing was translated and the run failed.
	 * @return array<string, mixed>
	 */
	private function run_result( int $total, int $translated, int $skipped, int $failed, int $targets, int $capped, string $first_error, bool $run_failed ): array {
		$result = [
			'total'       => $total,
			'translated'  => $translated,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'targets'     => $targets,
			'capped'      => $capped,
			'first_error' => $first_error,
		];

		if ( $run_failed ) {
			$result['run_failed'] = true;
		}

		return $result;
	}

	/**
	 * Whether the provider translates a list in one request.
	 *
	 * AbstractProvider::translate_batch() loops translate() text by text, so a
	 * provider that inherits it gains nothing from a chunk call and loses the
	 * already-paid texts when one text fails.
	 *
	 * @param TranslationService $service     Service.
	 * @param string             $provider_id Provider id ('' = configured).
	 * @return bool
	 */
	private function provider_batches_natively( TranslationService $service, string $provider_id ): bool {
		try {
			$provider = $service->get_provider( $provider_id );
			$method   = new \ReflectionMethod( $provider, 'translate_batch' );
		} catch ( \Throwable $e ) {
			unset( $e );
			// The chunk call reports the provider error for every row, as before.
			return true;
		}

		return $method->getDeclaringClass()->getName() !== \PerfLocale\MachineTranslation\AbstractProvider::class;
	}

	/**
	 * Build the provider input for one string row.
	 *
	 * A plural row (context `plural` or `… (plural)`) whose original holds
	 * exactly one count placeholder (`%d`, `%s`, `%1$d`, …) is sent once per
	 * plural form 1..N-1 of a target language with three or more forms, with a
	 * sample count in place of the placeholder ("%d items" → "2 items",
	 * "5 items" for Polish), so the provider writes each grammatical form.
	 * Every other row is sent once, as its own text.
	 *
	 * @param object $row    Source `strings` row (id, context, original).
	 * @param string $locale Target locale or language slug.
	 * @return array<string, mixed>
	 * @phpstan-return StringItem
	 */
	private static function build_item( object $row, string $locale ): array {
		$original = (string) $row->original;
		$forms    = self::plural_form_texts( $original, (string) ( $row->context ?? '' ), $locale );

		$item = [
			'id'       => (int) $row->id,
			'row'      => $row,
			'original' => $original,
			'format'   => 'html',
			'texts'    => [],
			'phs'      => [],
			'numbers'  => null,
			'only'     => [],
			'token'    => '',
		];

		$sources = $forms === null ? [ $original ] : array_column( $forms, 'text' );

		if ( $forms !== null ) {
			$item['numbers'] = array_column( $forms, 'number' );
			$item['only']    = array_column( $forms, 'only' );
			$item['token']   = (string) self::plural_count_token( $original );
		}

		foreach ( $sources as $source ) {
			[ $masked, $phs ] = PlaceholderMasker::mask( $source );
			$item['texts'][]  = $masked;
			$item['phs'][]    = $phs;
		}

		$item['format'] = self::destination_format( $original, $item['texts'][0] );

		return $item;
	}

	/**
	 * Destination format for a string: `text` for tag-free sources, so the
	 * text sanitiser keeps `&` and quotes literal; `html` for sources with
	 * markup or entities, and for masked text a text sanitiser would alter
	 * (percent-encoded octets).
	 *
	 * @param string $original Source text.
	 * @param string $masked   Masked source text.
	 * @return string 'text' or 'html'.
	 */
	private static function destination_format( string $original, string $masked ): string {
		if ( str_contains( $original, '<' )
			|| preg_match( '/&(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#x[0-9a-fA-F]+);/', $original )
			|| preg_match( '/%[0-9a-fA-F]{2}/', $masked )
		) {
			return 'html';
		}

		return 'text';
	}

	/**
	 * The single count placeholder of a plural original, or null when it has
	 * none or more than one printf placeholder.
	 *
	 * @param string $original Plural original.
	 * @return string|null
	 */
	private static function plural_count_token( string $original ): ?string {
		if ( preg_match_all( '/%(?:\d+\$)?[sdfgxXcouebE%]/', $original, $all ) !== 1 ) {
			return null;
		}

		$token = (string) $all[0][0];

		return preg_match( '/^%(?:\d+\$)?[ds]$/', $token ) ? $token : null;
	}

	/**
	 * The texts a plural row is sent as for a locale, one per plural form
	 * 1..N-1 with its sample count substituted, or null when the row is sent
	 * as one text (not a plural row, fewer than three forms, or not exactly one
	 * count placeholder).
	 *
	 * Public so the cost estimator counts exactly what is sent.
	 *
	 * @param string $original Source text.
	 * @param string $context  Row context.
	 * @param string $locale   Target locale or language slug.
	 * @return array<int, array{text: string, number: int, only: bool}>|null
	 */
	public static function plural_form_texts( string $original, string $context, string $locale ): ?array {
		if ( $context !== 'plural' && ! str_ends_with( $context, ' (plural)' ) ) {
			return null;
		}

		$nplurals = PluralRules::nplurals( $locale );

		if ( $nplurals < 3 ) {
			return null;
		}

		$token = self::plural_count_token( $original );

		if ( $token === null ) {
			return null;
		}

		$at    = (int) strpos( $original, $token );
		$texts = [];

		for ( $form = 1; $form < $nplurals; $form++ ) {
			$number = self::plural_sample( $locale, $form );

			if ( $number < 0 ) {
				return null;
			}

			$texts[] = [
				'text'   => substr_replace( $original, (string) $number, $at, strlen( $token ) ),
				'number' => $number,
				'only'   => self::only_count_for_form( $locale, $form ),
			];
		}

		return $texts;
	}

	/**
	 * The smallest count that selects a plural form in a locale: 2..200
	 * first, then 0 and 1. -1 when no count selects it.
	 *
	 * @param string $locale Locale or slug.
	 * @param int    $form   Form index.
	 * @return int
	 */
	private static function plural_sample( string $locale, int $form ): int {
		/**
		 * Sample count per "locale|form".
		 *
		 * @var array<string, int> $memo
		 */
		static $memo = [];

		$key = $locale . '|' . $form;

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		foreach ( array_merge( range( 2, 200 ), [ 0, 1 ] ) as $n ) {
			if ( PluralRules::form_index( $locale, $n ) === $form ) {
				$memo[ $key ] = $n;

				return $n;
			}
		}

		$memo[ $key ] = -1;

		return -1;
	}

	/**
	 * Whether exactly one count selects a plural form in a locale (Arabic
	 * forms 1 and 2: n = 1, n = 2). Such a form may name its count in words
	 * with no number ("عنصران"), as gettext allows. Counts 0..1000 are
	 * checked, which covers every rule's `n % 100` cycle.
	 *
	 * @param string $locale Locale or slug.
	 * @param int    $form   Form index.
	 * @return bool
	 */
	private static function only_count_for_form( string $locale, int $form ): bool {
		/**
		 * Answer per "locale|form".
		 *
		 * @var array<string, bool> $memo
		 */
		static $memo = [];

		$key = $locale . '|' . $form;

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$hits = 0;

		for ( $n = 0; $n <= 1000 && $hits < 2; $n++ ) {
			if ( PluralRules::form_index( $locale, $n ) === $form ) {
				++$hits;
			}
		}

		$memo[ $key ] = 1 === $hits;

		return $memo[ $key ];
	}

	/**
	 * Restore, check and save one item's provider answer.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo       Group/link repository.
	 * @param StringTranslationRepository                                $translation_repo Value repository.
	 * @param array<string, mixed>                                       $item             From build_item().
	 * @phpstan-param StringItem $item
	 * @param string[]                                                   $outputs          Provider answers, parallel to the item's texts.
	 * @param int                                                        $language_id      Target language id.
	 * @return array{saved: bool, error: string, translation: string}
	 */
	private function persist_item(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		StringTranslationRepository $translation_repo,
		array $item,
		array $outputs,
		int $language_id
	): array {
		$string_id   = (int) $item['id'];
		$original    = (string) $item['original'];
		$forms       = [];
		$forms_error = '';

		$count = count( $item['texts'] );

		for ( $i = 0; $i < $count; $i++ ) {
			$out = (string) ( $outputs[ $i ] ?? '' );

			if ( $out === '' ) {
				if ( $i === 0 ) {
					return [
						'saved'       => false,
						'error'       => sprintf(
							/* translators: %d: string ID. */
							__( 'Empty translation returned for string #%d', 'perflocale' ),
							$string_id
						),
						'translation' => '',
					];
				}

				break;
			}

			$text         = PlaceholderMasker::restore( $out, $item['phs'][ $i ] ?? [] );
			$check_source = $original;

			if ( is_array( $item['numbers'] ) ) {
				$number = (int) $item['numbers'][ $i ];

				if ( ! empty( $item['only'][ $i ] ) && 0 === self::count_occurrences( $text, $number ) ) {
					// A form only one count selects, answered without the
					// number: kept as it is; every other placeholder is still
					// required.
					$token        = (string) $item['token'];
					$check_source = substr_replace( $original, '', (int) strpos( $original, $token ), strlen( $token ) );
				} else {
					$text = self::put_back_count( $text, $number, (string) $item['token'] );
				}

				if ( $text === null ) {
					if ( $i === 0 ) {
						return [
							'saved'       => false,
							'error'       => sprintf(
								/* translators: 1: plural form number, 2: id of the row in the plugin's strings table. */
								__( 'Plural form %1$d of string #%2$d could not be matched to its count; it was not saved.', 'perflocale' ),
								$i + 1,
								$string_id
							),
							'translation' => '',
						];
					}

					$forms_error = sprintf(
						/* translators: 1: plural form number, 2: id of the row in the plugin's strings table. */
						__( 'Plural form %1$d of string #%2$d could not be matched to its count; it was not saved.', 'perflocale' ),
						$i + 1,
						$string_id
					);
					break;
				}
			}

			// Integrity gate: reject translations that lost a placeholder.
			// Better to mark the row failed than to ship a malformed gettext
			// string to every visitor.
			if ( ! PlaceholderMasker::preserves_placeholders( $check_source, $text ) ) {
				if ( $i === 0 ) {
					return [
						'saved'       => false,
						'error'       => sprintf(
							/* translators: %d: string ID. */
							__( 'Translation for string #%d dropped a placeholder; rejected.', 'perflocale' ),
							$string_id
						),
						'translation' => '',
					];
				}

				$forms_error = sprintf(
					/* translators: %d: string ID. */
					__( 'Translation for string #%d dropped a placeholder; rejected.', 'perflocale' ),
					$string_id
				);
				break;
			}

			if ( $item['format'] === 'text' ) {
				$text = self::keep_edge_whitespace( $original, $text );
			}

			$forms[] = $text;
		}

		// Persist the pair. save_translation() repairs a string whose
		// group_id cannot legally carry a link BEFORE it writes
		// anything, and writes the link before the value, so a row
		// this job counts as translated is a row the front end can
		// actually serve. Anything it could not complete comes back
		// as an operator-facing reason and the row is counted failed
		// — a job that spends money must not report an attempt as a
		// success. See the method for the ordering rules.
		$save_error = $this->save_translation( $group_repo, $translation_repo, $item['row'], $language_id, $forms[0] );

		if ( $save_error !== '' ) {
			return [
				'saved'       => false,
				'error'       => $save_error,
				'translation' => '',
			];
		}

		$error = $forms_error;

		// Forms 2..N of a plural row. When a later form failed the row keeps
		// its previous extra forms; form 1 is saved.
		if ( is_array( $item['numbers'] ) && $error === '' && count( $forms ) > 1 ) {
			if ( ! $translation_repo->set_extra_forms( $string_id, $language_id, array_slice( $forms, 1 ) ) ) {
				$error = sprintf(
					/* translators: %d: string ID. */
					__( 'Failed to persist the plural forms of string #%d.', 'perflocale' ),
					$string_id
				);
			}
		}

		return [
			'saved'       => true,
			'error'       => $error,
			'translation' => $forms[0],
		];
	}

	/**
	 * Put the count placeholder back where the provider wrote the sample
	 * count. Exactly one standalone occurrence of the number must exist.
	 *
	 * @param string $text   Restored translation.
	 * @param int    $number Sample count that was sent.
	 * @param string $token  Original placeholder.
	 * @return string|null Null when the number is missing or ambiguous.
	 */
	private static function put_back_count( string $text, int $number, string $token ): ?string {
		if ( self::count_occurrences( $text, $number ) !== 1 ) {
			return null;
		}

		return (string) preg_replace_callback( self::count_pattern( $number ), static fn(): string => $token, $text, 1 );
	}

	/**
	 * How many times a count stands alone in a text.
	 *
	 * @param string $text   Restored translation.
	 * @param int    $number Sample count that was sent.
	 * @return int
	 */
	private static function count_occurrences( string $text, int $number ): int {
		return (int) preg_match_all( self::count_pattern( $number ), $text );
	}

	/**
	 * Pattern for a count standing alone: not part of a longer number, a
	 * decimal ("5.5") or a grouped number ("1,000"); sentence punctuation
	 * after it ("5." / "5,") is allowed.
	 *
	 * @param int $number Sample count.
	 * @return string
	 */
	private static function count_pattern( int $number ): string {
		return '/(?<!\d)(?<!\d[.,])' . $number . '(?![.,]?\d)/';
	}

	/**
	 * Keep the source's leading and trailing whitespace on a plain-text
	 * translation (the text sanitiser trims both ends).
	 *
	 * @param string $source      Source text.
	 * @param string $translation Translation.
	 * @return string
	 */
	private static function keep_edge_whitespace( string $source, string $translation ): string {
		if ( $translation === '' ) {
			return $translation;
		}

		if ( preg_match( '/^\s+/u', $source, $lead ) && ! preg_match( '/^\s/u', $translation ) ) {
			$translation = $lead[0] . $translation;
		}

		if ( preg_match( '/\s+$/u', $source, $trail ) && ! preg_match( '/\s$/u', $translation ) ) {
			$translation .= $trail[0];
		}

		return $translation;
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
			return sprintf(
				/* translators: %d: string ID. */
				__( 'Failed to persist translation for string #%d.', 'perflocale' ),
				$string_id
			);
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
