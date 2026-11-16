<?php
/**
 * Bulk machine translation of gettext strings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Database\Schema;
use PerfLocale\MachineTranslation\BlockStructureGuard;
use PerfLocale\MachineTranslation\CallDeadline;
use PerfLocale\MachineTranslation\DeadlineReachedException;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Strings\PluralRules;
use PerfLocale\Translation\PlaceholderMasker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine-translates gettext strings: mode 'ids', 'filter' or 'all' (with skip-existing only rows still missing a
 * target), or 'outdated' (answers held for review, never written). See docs-ai/11-code-rationale.md,
 * "BulkStringTranslateJob: placeholders, links and groups".
 *
 * @phpstan-type StringItem array{id: int, row: object, original: string, format: string, texts: array<int, string>, phs: array<int, array<int, string>>, numbers: array<int, int>|null, only: array<int, bool>, token: string, base?: string}
 */
final class BulkStringTranslateJob extends AbstractJob {

	/**
	 * Strings per provider call: fits DeepL's 128 KB request limit even at 5 KB strings, suits Google's per-call quota,
	 * and keeps progress ticks under 5 s apart.
	 */
	private const BATCH_SIZE = 25;

	/** String ids per statement when excluded domains are filtered out of an ids selection. */
	private const EXCLUSION_CHUNK = 1000;

	/** Rows per statement of the outdated selection (keyset pages in string id order: memory stays bounded). */
	private const OUTDATED_PAGE = 50000;

	/**
	 * The options hash of the running outdated run, stored with each change it holds.
	 *
	 * @var string
	 */
	private string $options_hash = '';

	/**
	 * Checkpoint keys of a chunk's settled strings => stored or kept (true) or failed (false).
	 */
	private const SETTLED_KEYS = [
		'chunk_done'   => true,
		'chunk_failed' => false,
	];

	/**
	 * Seconds one slice runs on a web request (as BulkTranslateJob).
	 */
	private const SLICE_SECONDS = 20;

	/**
	 * Pairs one slice runs on a web request: a backstop when the clock never binds.
	 */
	private const SLICE_MAX_PAIRS = 500;

	/**
	 * Largest encoded id list a checkpoint carries; above it the run is not sliced,
	 * because JobState drops a result over its cap and the next slice would restart.
	 */
	private const CHECKPOINT_IDS_MAX_BYTES = 40000;

	/**
	 * Most characters of the first failure reason a result stores (a filtered provider may throw any message).
	 */
	private const FIRST_ERROR_MAX_CHARS = 500;

	/**
	 * A string of Unicode spaces, line breaks or NUL only: blank to a provider. Kept in PHP: the selection SQL stays the
	 * same on every engine, so such a string is selected and keeps its source.
	 */
	private const SPACE_ONLY = '/^[\p{Z}\t\n\v\f\r\x{85}\x00]++$/u';

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'bulk_string_translate';
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function resumes_after_kill(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * As post bulk machine translation: a run can overwrite every string translation (REST also needs
	 * `perflocale_use_mt`).
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * 50 pairs: below it the translator watches the count, above it the run goes async. Tunable under Settings >
	 * Performance > Background Thresholds.
	 */
	public function get_default_threshold(): int {
		return 50;
	}

	/**
	 * {@inheritDoc}
	 *
	 * `string_count x target_lang_count`, the product the threshold compares.
	 */
	protected function args_size( array $args ): int {
		$string_count = $this->resolve_string_count( $args );
		$targets      = is_array( $args['target_lang_ids'] ?? null ) ? $args['target_lang_ids'] : [];
		return $string_count * count( $targets );
	}

	/**
	 * How many strings this dispatch touches, without loading rows: for args_size() and execute()'s count of rows left
	 * beyond the per-dispatch cap.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int
	 */
	private function resolve_string_count( array $args ): int {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';

		if ( $mode === 'outdated' ) {
			return $this->outdated_of_args( $args, 0 )['strings'];
		}

		if ( $mode === 'ids' ) {
			$ids = is_array( $args['string_ids'] ?? null ) ? $args['string_ids'] : [];
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			// The filters resolve_string_ids() uses, so the reported 'capped' shortfall is exact.
			return count( $this->without_excluded_domains( $ids, $this->excluded_domains( $args ), PHP_INT_MAX ) );
		}

		$repo = new StringRepository( Plugin::get_instance()->get( 'cache' ) );

		return $repo->count( $this->selection_filter( $args ) );
	}

	/**
	 * The most strings one dispatch translates (BulkStringCap).
	 *
	 * @return int
	 */
	public static function max_per_dispatch(): int {
		return BulkStringCap::max_per_dispatch();
	}

	/**
	 * The string ids a dispatch with these args would translate, read-only, with the job's selection (mode,
	 * skip-existing, cap): for the pre-dispatch budget gate and the /machine-translate/estimate endpoint.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int[] String row IDs.
	 */
	public function resolve_ids_for_estimate( array $args ): array {
		return $this->resolve_string_ids( $args );
	}

	/**
	 * The string ids to translate, capped at max_per_dispatch(). Filter mode goes through StringRepository, so the
	 * admin page's filters behave the same; with skip-existing each run advances past the rows the previous one
	 * translated (an overwrite run takes table order).
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return int[]
	 */
	private function resolve_string_ids( array $args ): array {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';
		$cap  = self::max_per_dispatch();

		if ( $mode === 'outdated' ) {
			return $this->outdated_of_args( $args, self::keep_of( $cap ) )['ids'];
		}

		if ( $mode === 'ids' ) {
			$ids = (array) ( $args['string_ids'] ?? [] );
			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			return $this->without_excluded_domains( $ids, $this->excluded_domains( $args ), $cap );
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

		$excluded = $this->excluded_domains( $args );

		if ( $excluded !== [] ) {
			$filter['exclude_domains'] = $excluded;
		}

		return $filter;
	}

	/**
	 * The domains the dispatching user may not send to a provider (`exclude_domains`), deduplicated.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return array<int, string>
	 */
	private function excluded_domains( array $args ): array {
		$out = [];

		foreach ( (array) ( $args['exclude_domains'] ?? [] ) as $domain ) {
			if ( is_string( $domain ) && '' !== $domain ) {
				$out[ $domain ] = $domain;
			}
		}

		return array_values( $out );
	}

	/**
	 * The first $cap ids, in order, whose string is outside every excluded domain.
	 *
	 * @param array<int, int>    $ids      String ids.
	 * @param array<int, string> $excluded Excluded domains.
	 * @param int                $cap      Most ids returned.
	 * @return array<int, int>
	 */
	private function without_excluded_domains( array $ids, array $excluded, int $cap ): array {
		if ( $ids === [] || $excluded === [] ) {
			return array_slice( $ids, 0, max( 0, $cap ) );
		}

		$repo = new StringRepository( Plugin::get_instance()->cache() );
		$out  = [];

		// A bounded list per statement, until the cap is filled.
		foreach ( array_chunk( $ids, self::EXCLUSION_CHUNK ) as $chunk ) {
			if ( count( $out ) >= $cap ) {
				break;
			}

			$out = array_merge( $out, $repo->ids_outside_domains( $chunk, $excluded ) );
		}

		return array_slice( $out, 0, max( 0, $cap ) );
	}

	/**
	 * The inbound filter payload in the shape StringRepository expects.
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
	 * The outdated selection of a run's arguments (outdated_split()).
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @param int|null             $keep Strings kept; null: the dispatch cap.
	 * @return array{send: array<int, list<int>>, rejected: array<int, list<int>>, ids: list<int>, strings: int, pairs: int, rejected_pairs: int}
	 */
	private function outdated_of_args( array $args, ?int $keep ): array {
		$langs = [];

		// Outdated strings are Content ReSync's: none while it is off.
		if ( ! \PerfLocale\Util\ResyncSwitch::on() ) {
			return [
				'send'           => [],
				'rejected'       => [],
				'ids'            => [],
				'strings'        => 0,
				'pairs'          => 0,
				'rejected_pairs' => 0,
			];
		}

		foreach ( (array) ( $args['target_lang_ids'] ?? [] ) as $lang ) {
			$langs[] = \PerfLocale\Util\Scalar::int( $lang );
		}

		return $this->outdated_split( $langs, $this->excluded_domains( $args ), sanitize_key( \PerfLocale\Util\Scalar::str( $args['provider_id'] ?? '' ) ), $keep );
	}

	/**
	 * The strings a run keeps of the dispatch cap: every string when the cap is not positive.
	 *
	 * @param int $cap max_per_dispatch().
	 * @return int
	 */
	private static function keep_of( int $cap ): int {
		return $cap > 0 ? $cap : PHP_INT_MAX;
	}

	/**
	 * The string ids of per-language pairs, ascending, each once.
	 *
	 * @param array<int, list<int>> $pairs Language id => string ids.
	 * @return array<int, int>
	 */
	private static function ids_of( array $pairs ): array {
		$ids = [];

		foreach ( $pairs as $lang_ids ) {
			foreach ( $lang_ids as $id ) {
				$ids[ $id ] = $id;
			}
		}

		ksort( $ids );

		return array_values( $ids );
	}

	/**
	 * The `send` part of outdated_split(), read-only.
	 *
	 * @param array<int, int>    $target_lang_ids Target languages.
	 * @param array<int, string> $exclude_domains Domains left out.
	 * @param string             $provider_id     Provider id ('' = configured).
	 * @return array<int, list<int>> Language id => string ids, ascending.
	 */
	public function outdated_pairs( array $target_lang_ids, array $exclude_domains, string $provider_id = '' ): array {
		return $this->outdated_split( $target_lang_ids, $exclude_domains, $provider_id )['send'];
	}

	/**
	 * The string translations flagged Outdated per target language (needs_update link, non-empty translation, outside
	 * the excluded domains, no pending change), split into `send` and `rejected`. Keyset pages never split a string's
	 * pairs; the first `$keep` strings to send are kept, every page is counted. Public for the Strings page estimate.
	 *
	 * @param array<int, int>                              $target_lang_ids Target languages.
	 * @param array<int, string>                           $exclude_domains Domains left out.
	 * @param string                                       $provider_id     Provider id ('' = configured).
	 * @param int|null                                     $keep            Strings kept; null: the dispatch cap; 0 keeps none.
	 * @param (callable(array<int, list<int>>): void)|null $each_page       Receives each page's `send` pairs.
	 * @param array<int, int>|null                         $only_ids        Only these strings (a resumed slice's selection).
	 * @return array{send: array<int, list<int>>, rejected: array<int, list<int>>, ids: list<int>, strings: int, pairs: int, rejected_pairs: int}
	 */
	public function outdated_split( array $target_lang_ids, array $exclude_domains, string $provider_id = '', ?int $keep = null, ?callable $each_page = null, ?array $only_ids = null ): array {
		$langs = array_values( array_unique( array_filter( $target_lang_ids, static fn( int $id ): bool => $id > 0 ) ) );
		$wpdb  = $GLOBALS['wpdb'] ?? null;
		$out   = [
			'send'           => [],
			'rejected'       => [],
			'ids'            => [],
			'strings'        => 0,
			'pairs'          => 0,
			'rejected_pairs' => 0,
		];

		if ( $langs === [] || ! $wpdb instanceof \wpdb || [] === $only_ids ) {
			return $out;
		}

		$keep       = null === $keep ? self::keep_of( self::max_per_dispatch() ) : max( 0, $keep );
		$lang_ph    = implode( ',', array_fill( 0, count( $langs ), '%d' ) );
		$exclude    = array_values( array_filter( $exclude_domains, static fn( string $d ): bool => '' !== $d ) );
		$domain_sql = $exclude === [] ? '' : ' AND s.domain NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')';
		$only       = null === $only_ids ? [] : array_values( array_map( 'intval', $only_ids ) );
		$only_sql   = $only === [] ? '' : ' AND l.object_id IN (' . implode( ',', array_fill( 0, count( $only ), '%d' ) ) . ')';
		$store      = \PerfLocale\Automation\ChangeService::available();
		$changes    = Schema::table( 'pending_changes' );
		$string     = \PerfLocale\Enum\ObjectType::String->value;
		$pending    = \PerfLocale\Database\Repository\PendingChangeRepository::STATUS_PENDING;
		$held_sql   = $store ? ' AND NOT EXISTS (SELECT 1 FROM %i p WHERE p.object_type = %s AND p.object_id = l.object_id AND p.language_id = l.language_id AND p.status = %s)' : '';
		// The pair's Reject or Undo that no later decision follows (one row at most).
		$declined   = \PerfLocale\Database\Repository\PendingChangeRepository::PERSON_DECLINED;
		$reject_col = $store ? ', r.base_hash AS rejected_hash, r.summary AS rejected_summary, CASE WHEN r.id IS NULL THEN NULL ELSE s.original_hash END AS rejected_source, CASE WHEN r.id IS NULL THEN NULL ELSE t.translation END AS rejected_translation, CASE WHEN r.id IS NULL THEN NULL ELSE t.extra_forms END AS rejected_forms' : '';
		$reject_sql = $store ? ' LEFT JOIN %i r ON r.object_type = %s AND r.object_id = l.object_id AND r.language_id = l.language_id AND r.status IN (' . implode( ',', array_fill( 0, count( $declined ), '%s' ) ) . ') AND NOT EXISTS (SELECT 1 FROM %i n WHERE n.object_type = %s AND n.object_id = l.object_id AND n.language_id = l.language_id AND n.status <> %s AND n.id > r.id)' : '';
		$values     = array_merge(
			[ Schema::table( 'translation_links' ), Schema::table( 'strings' ), Schema::table( 'string_translations' ) ],
			$store ? array_merge( [ $changes, $string ], $declined, [ $changes, $string, $pending ] ) : [],
			[ $string, \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value ],
			$langs,
			$exclude,
			$only,
			$store ? [ $changes, $string, $pending ] : []
		);
		$options    = $store ? self::current_options_hash( $provider_id ) : '';
		// A string has at most one row per language, so a full page always holds more than one string.
		$limit = max( self::OUTDATED_PAGE, count( $langs ) + 1 );
		$after = 0;
		$first = true;

		do {
			$from = $after;
			// First page: no index may give the order (a small selection is sorted, as without LIMIT). Later pages
			// follow the strings primary key after the last string read, so each stops after its rows.
			$no_order = $first ? ' USE INDEX FOR ORDER BY ()' : '';
			$keyset   = $first ? '' : ' AND l.object_id > %d';
			$order    = $first ? 'l.object_id' : 's.id';
			$bound    = $first ? [ $limit ] : [ $after, $limit ];
			$first    = false;
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The lists are placeholders only; the values follow the placeholder order.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.object_id, l.language_id{$reject_col} FROM %i l{$no_order}
					 INNER JOIN %i s{$no_order} ON s.id = l.object_id
					 INNER JOIN %i t ON t.string_id = l.object_id AND t.language_id = l.language_id{$reject_sql}
					 WHERE l.type = %s AND l.status = %s AND l.language_id IN ({$lang_ph}) AND t.translation <> ''{$domain_sql}{$only_sql}{$held_sql}{$keyset}
					 ORDER BY {$order} ASC
					 LIMIT %d",
					array_merge( $values, $bound )
				),
				ARRAY_A
			);
			// phpcs:enable

			$rows = is_array( $rows ) ? $rows : [];
			$more = count( $rows ) >= $limit;

			if ( $more ) {
				// The page may end inside the last string's pairs: that string is read again with the next page.
				$last = self::row_string( $rows[ count( $rows ) - 1 ] );
				$cut  = count( $rows );

				while ( $cut > 0 && self::row_string( $rows[ $cut - 1 ] ) === $last ) {
					--$cut;
				}

				if ( $cut > 0 ) {
					$rows  = array_slice( $rows, 0, $cut );
					$after = self::row_string( $rows[ $cut - 1 ] );
				} else {
					$after = $last;
				}
			}

			$page = self::split_outdated( $rows, $options );
			unset( $rows );

			if ( null !== $each_page ) {
				$each_page( $page['send'] );
			}

			self::add_page( $out, $page, $keep );
		} while ( $more && $after > $from );

		return $out;
	}

	/**
	 * The string id of a row of the outdated selection.
	 *
	 * @param mixed $row A row.
	 * @return int
	 */
	private static function row_string( $row ): int {
		return \PerfLocale\Util\Scalar::int( is_array( $row ) ? ( $row['object_id'] ?? 0 ) : 0 );
	}

	/**
	 * Add one page to the pass: its counts, and its strings with a pair to send while fewer than `$keep` are kept.
	 *
	 * @param array{send: array<int, list<int>>, rejected: array<int, list<int>>, ids: list<int>, strings: int, pairs: int, rejected_pairs: int} $out  The pass so far (by ref).
	 * @param array{send: array<int, list<int>>, rejected: array<int, list<int>>}                                                                $page One page, from split_outdated().
	 * @param int                                                                                                                                $keep Strings kept.
	 * @return void
	 */
	private static function add_page( array &$out, array $page, int $keep ): void {
		$ids = self::ids_of( $page['send'] );

		$out['strings']        += count( $ids );
		$out['pairs']          += array_sum( array_map( 'count', $page['send'] ) );
		$out['rejected_pairs'] += array_sum( array_map( 'count', $page['rejected'] ) );

		$room = $keep - count( $out['ids'] );

		if ( $room <= 0 || $ids === [] ) {
			return;
		}

		$take = array_fill_keys( array_slice( $ids, 0, $room ), true );

		foreach ( array_keys( $take ) as $id ) {
			$out['ids'][] = $id;
		}

		foreach ( [ 'send', 'rejected' ] as $part ) {
			foreach ( $page[ $part ] as $lang => $lang_ids ) {
				foreach ( $lang_ids as $id ) {
					if ( isset( $take[ $id ] ) ) {
						$out[ $part ][ $lang ][] = $id;
					}
				}
			}
		}
	}

	/**
	 * Split outdated selection rows: `rejected` when the pair's newest decision is a person's Reject or Undo of the
	 * proposal this run would make (same source text, options and live translation), else `send`.
	 *
	 * @param array<int, mixed> $rows         Rows with object_id, language_id and, with the store, the rejected_* columns.
	 * @param string            $options_hash The run's options hash (current_options_hash()).
	 * @return array{send: array<int, list<int>>, rejected: array<int, list<int>>} Language id => string ids, in row order.
	 */
	public static function split_outdated( array $rows, string $options_hash ): array {
		$out = [
			'send'     => [],
			'rejected' => [],
		];

		foreach ( $rows as $row ) {
			$row  = is_array( $row ) ? $row : [];
			$part = self::same_rejected_proposal( $row, $options_hash ) ? 'rejected' : 'send';

			$out[ $part ][ \PerfLocale\Util\Scalar::int( $row['language_id'] ?? 0 ) ][] = \PerfLocale\Util\Scalar::int( $row['object_id'] ?? 0 );
		}

		return $out;
	}

	/**
	 * Whether a selection row carries a person's Reject or Undo of the same proposal.
	 *
	 * @param array<mixed> $row          A row of the outdated selection.
	 * @param string       $options_hash The run's options hash.
	 * @return bool
	 */
	private static function same_rejected_proposal( array $row, string $options_hash ): bool {
		$hash = \PerfLocale\Util\Scalar::str( $row['rejected_hash'] ?? '' );

		if ( '' === $hash || null === ( $row['rejected_translation'] ?? null ) ) {
			return false;
		}

		$summary = json_decode( \PerfLocale\Util\Scalar::str( $row['rejected_summary'] ?? '' ), true );
		$summary = is_array( $summary ) ? $summary : [];
		$source  = \PerfLocale\Util\Scalar::str( $summary['source_hash'] ?? '' );

		return '' !== $source
			&& \PerfLocale\Strings\StrIdentMigration::same_source_id( $source, \PerfLocale\Util\Scalar::str( $row['rejected_source'] ?? '' ), \PerfLocale\Util\Scalar::int( $row['object_id'] ?? 0 ) )
			&& hash_equals( $options_hash, \PerfLocale\Util\Scalar::str( $summary['options_hash'] ?? '' ) )
			&& hash_equals( $hash, \PerfLocale\Automation\StringTarget::stored_hash( \PerfLocale\Util\Scalar::str( $row['rejected_translation'] ), \PerfLocale\Util\Scalar::str( $row['rejected_forms'] ?? '' ) ) );
	}

	/**
	 * The hash of the options that shape an outdated proposal: context on or off and, when on, its character limit.
	 *
	 * @param bool $with_context The run sends context (outdated_context_on()).
	 * @param int  $max_chars    The context character limit.
	 * @return string
	 */
	public static function outdated_options_hash( bool $with_context, int $max_chars ): string {
		return hash( 'sha256', (string) wp_json_encode( $with_context ? [ 'context', $max_chars ] : [] ) );
	}

	/**
	 * The options hash an outdated run would have now.
	 *
	 * @param string $provider_id Provider id ('' = configured).
	 * @return string
	 */
	public static function current_options_hash( string $provider_id ): string {
		$plugin  = Plugin::get_instance();
		$service = new TranslationService( $plugin->settings(), $plugin->cache() );
		$on      = self::outdated_context_on( $service, $provider_id );

		return self::outdated_options_hash( $on, $on ? \PerfLocale\MachineTranslation\ContextSettings::max_chars( $plugin->settings() ) : 0 );
	}

	/**
	 * The summary of a held outdated change, which a later run compares after a person's Reject or Undo.
	 *
	 * @param string $source_hash  The string's `original_hash`.
	 * @param string $options_hash The run's options hash.
	 * @return array{outdated: true, source_hash: string, options_hash: string}
	 */
	public static function hold_summary( string $source_hash, string $options_hash ): array {
		return [
			'outdated'     => true,
			'source_hash'  => $source_hash,
			'options_hash' => $options_hash,
		];
	}

	/**
	 * The pairs a person's Reject or Undo leaves out: every page's count of a pass, or one split's `rejected` pairs.
	 *
	 * @param array{send: array<int, list<int>>, rejected: array<int, list<int>>, rejected_pairs?: int} $split From outdated_split() or split_outdated().
	 * @return int
	 */
	public static function rejected_count( array $split ): int {
		if ( isset( $split['rejected_pairs'] ) ) {
			return $split['rejected_pairs'];
		}

		$count = 0;

		foreach ( $split['rejected'] as $ids ) {
			$count += count( $ids );
		}

		return $count;
	}

	/**
	 * Why a dispatch selected no rows.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return string
	 */
	private function nothing_selected_reason( array $args ): string {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'ids';

		if ( $mode === 'outdated' ) {
			return __( 'No string translation in the chosen languages is flagged Outdated.', 'perflocale' );
		}

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
	 * Batching providers (DeepL, Google, Microsoft) get one call per chunk and format, the others one per string: a
	 * failure fails that string only. Nothing translated with a failure returns `run_failed`. A worker slice stops
	 * before a unit at its budget (`__perflocale_continue`) or at a provider deadline (`__perflocale_continue_at`).
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $progress
	 * @return array<string, mixed>
	 * @throws \RuntimeException When machine translation is disabled or no default language is configured.
	 * @throws \PerfLocale\Background\JobCanceledException When the operator cancels or pauses; it carries the counts so far.
	 * @throws DeadlineReachedException In a run that is not a slice: the deadline is its caller's.
	 */
	public function execute( array $args, callable $progress ): array {
		// Rights again at run time: a queued run outlives a role change.
		if ( ! current_user_can( 'perflocale_manage_translations' ) || ! current_user_can( 'perflocale_use_mt' ) ) {
			$reason = current_user_can( 'perflocale_manage_translations' )
				? __( 'Machine translation is not permitted for the dispatching user (perflocale_use_mt).', 'perflocale' )
				: __( 'Permission revoked or dispatching user no longer has access.', 'perflocale' );

			return $this->run_result( 0, 0, 0, 0, 0, 0, $reason, true );
		}

		// Domains this user may not see now are left out, whatever the dispatch recorded.
		$args['exclude_domains'] = array_values( array_unique( array_merge( $this->excluded_domains( $args ), \PerfLocale\Strings\Domains::restricted_for_current_user() ) ) );

		$target_lang_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['target_lang_ids'] ?? [] ) ) ) );
		$provider_id     = sanitize_key( (string) ( $args['provider_id'] ?? '' ) );
		$outdated        = ( $args['mode'] ?? '' ) === 'outdated';

		if ( $outdated && ! \PerfLocale\Util\ResyncSwitch::on() ) {
			return $this->run_result( 0, 0, 0, 0, 0, 0, \PerfLocale\Util\ResyncOff::refused_text(), true );
		}

		// An outdated run sends strings that have a translation: it never skips them.
		$skip_existing = ! $outdated && ( isset( $args['skip_existing'] ) ? (bool) $args['skip_existing'] : true );

		if ( $target_lang_ids === [] ) {
			return $this->empty_result( __( 'No target languages provided.', 'perflocale' ) );
		}

		$slice  = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : null;
		$resume = null;

		if ( null !== $slice && is_array( $slice['resume'] ?? null ) && [] !== $slice['resume'] ) {
			// Resolved once, by the first slice: the skip-existing selection shrinks as the run translates.
			$resume = self::checkpoint( $slice['resume'], count( $target_lang_ids ) );

			if ( null === $resume ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a checkpoint that does not match the job.
				error_log( '[PerfLocale] Bulk string translation: the stored progress does not match the job, so this run starts again at the first string.' );
			}
		}

		// An outdated run reads its selection in one pass; a resumed slice reads its pairs among the stored strings.
		$split              = null;
		$rejected           = null !== $resume ? ( $resume['skipped_rejected'] ?? 0 ) : 0;
		$this->options_hash = '';

		if ( $outdated ) {
			$this->options_hash = self::current_options_hash( $provider_id );
			$split              = $this->outdated_split( $target_lang_ids, $this->excluded_domains( $args ), $provider_id, self::keep_of( self::max_per_dispatch() ), null, null !== $resume ? $resume['string_ids'] : null );
			self::stop_on_failed_read();
			$rejected = null !== $resume ? $rejected : self::rejected_count( $split );
		}

		$string_ids = null !== $resume ? $resume['string_ids'] : ( null !== $split ? $split['ids'] : $this->resolve_string_ids( $args ) );
		if ( $string_ids === [] ) {
			$result = $this->empty_result(
				$rejected > 0
					? __( 'No outdated string translation in the chosen languages is sent again: the machine translation of each was rejected or undone by a person, and its source text, its translation and the context sent with it have not changed since.', 'perflocale' )
					: $this->nothing_selected_reason( $args )
			);

			if ( $rejected > 0 ) {
				$result['skipped_rejected'] = $rejected;
			}

			return $result;
		}

		// Report rows beyond the per-dispatch cap, so the operator knows to run again.
		$dropped = 0;
		if ( null !== $resume ) {
			$dropped = $resume['capped'];
		} elseif ( count( $string_ids ) >= self::max_per_dispatch() ) {
			$dropped = max( 0, ( null !== $split ? $split['strings'] : $this->resolve_string_count( $args ) ) - count( $string_ids ) );
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

		// The default language is the source.
		$default = $lang_repo->get_default();
		if ( ! $default ) {
			throw new \RuntimeException( esc_html__( 'No default language configured; cannot determine the MT source locale.', 'perflocale' ) );
		}
		$source_lang_slug = (string) $default->slug;

		$service = new TranslationService( $settings, $cache );

		$translation_repo = new StringTranslationRepository( $cache );
		$group_repo       = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );

		$per_string = ! $this->provider_batches_natively( $service, $provider_id );

		// With context each string's live translation goes with it: one request per string, progress after each.
		$with_context = $outdated && self::outdated_context_on( $service, $provider_id );
		$context_max  = $with_context ? \PerfLocale\MachineTranslation\ContextSettings::max_chars( $plugin->settings() ) : 0;
		$per_string   = $per_string || $with_context;

		// Load every source row once; the set is already capped, so the SELECT-IN stays tractable.
		$strings_by_id = $this->load_strings_by_ids( $string_ids, $outdated );

		// An outdated run's pairs to send per language (a pair a person's Reject or Undo leaves out is not among them).
		$outdated_in = [];

		foreach ( null === $split ? [] : $split['send'] as $lang_id => $lang_ids ) {
			$outdated_in[ $lang_id ] = array_fill_keys( $lang_ids, true );
		}

		$n_ids      = count( $string_ids );
		$total      = $n_ids * count( $target_lang_ids );
		$processed  = 0;
		$translated = 0;
		$skipped    = 0;
		// Rows skipped because a translation exists. A resume that finds every row stored still needs the completion
		// cache sync and file regeneration; apart from $skipped so empty-source runs regenerate nothing.
		$already_translated = 0;
		$failed             = 0;
		$held               = 0;
		$first_error        = '';
		$breaker_stopped    = false;
		$breaker_error      = '';

		if ( null !== $resume ) {
			[
				'processed'          => $processed,
				'translated'         => $translated,
				'skipped'            => $skipped,
				'already_translated' => $already_translated,
				'failed'             => $failed,
				'first_error'        => $first_error,
			] = $resume;

			$held = $resume['held'] ?? 0;
		}

		// Strings of the resumed chunk its stopped run settled: id => true (stored or kept) or false (failed).
		$chunk_settled = null !== $resume ? $resume['settled'] : [];
		$unit_settled  = [];
		$stop_settled  = [];

		// A worker run's checkpoints carry the selection; one too large for a checkpoint is neither sliced nor
		// checkpointed.
		$slice_seconds   = 0.0;
		$slice_max_pairs = 0;
		$deadline        = null;
		$encoded_ids     = '';

		if ( null !== $slice ) {
			[ $slice_seconds, $slice_max_pairs, $deadline ] = self::slice_budget( 'cli' === PHP_SAPI );

			$encoded_ids = self::encode_ids( $string_ids );

			if ( strlen( $encoded_ids ) > self::CHECKPOINT_IDS_MAX_BYTES ) {
				$slice_seconds   = 0.0;
				$slice_max_pairs = 0;
				$deadline        = null;
				$encoded_ids     = '';
			}
		}

		$slice_start        = $processed;
		$slice_began        = microtime( true );
		$stopped            = false;
		$continue_at        = 0;
		$unit_stopped       = false;
		$start_target_index = intdiv( $processed, $n_ids );
		$start_offset       = $processed % $n_ids;

		// About 100 ticks, by count and by clock: a tick is the only place locks refresh and cancel/pause probes run,
		// and one chunk can block for minutes under a rate-limited provider (past the 1800 s lock TTL). Per-string mode
		// calls $progress after every provider call instead.
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

		// Start at the resumed position so the Jobs page bar never goes back.
		$tick( $processed );

		$previous_deadline = null !== $deadline ? CallDeadline::set( $deadline ) : null;

		// try/finally: a cancel throws from $tick()/$progress(), and the cache sync below must still run, or translated
		// strings stay invisible until the cache expires.
		try {
			foreach ( $target_lang_ids as $target_index => $target_lang_id ) {
				// A resumed slice starts inside its first target language.
				if ( $target_index < $start_target_index ) {
					continue;
				}

				// The resumed chunk's settled strings belong to its own language.
				if ( $target_index > $start_target_index ) {
					$chunk_settled = [];
				}

				$offset      = $target_index === $start_target_index ? $start_offset : 0;
				$target_lang = $lang_by_id[ $target_lang_id ] ?? null;
				if ( $target_lang === null ) {
					$processed += $n_ids - $offset;
					$failed    += $n_ids - $offset;
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

				// Walk the ID list in BATCH_SIZE batches; a per-string provider's unit is one string,
				// so the slice gate runs before each of its calls.
				foreach ( array_chunk( array_slice( $string_ids, $offset ), self::BATCH_SIZE ) as $batch_ids ) {
					$existing_for_batch = null;

					foreach ( array_chunk( $batch_ids, $per_string ? 1 : self::BATCH_SIZE ) as $chunk_ids ) {
						// Slice gate, after at least one pair. A checkpoint over the result cap
						// would be dropped (restart), so the run then goes on in this call.
						$done = $processed - $slice_start;

						if ( $done >= 1
							&& ( ( $slice_max_pairs > 0 && $done >= $slice_max_pairs )
								|| ( $slice_seconds > 0 && microtime( true ) - $slice_began >= $slice_seconds ) )
							&& self::fits_result_cap( $this->stopped_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $processed, $already_translated, $encoded_ids, $held, $rejected ) )
						) {
							$stopped      = true;
							$stop_settled = $chunk_settled;
							break 3;
						}

						$items        = [];
						$unit_start   = [ $processed, $translated, $skipped, $already_translated, $failed, $first_error, $held ];
						$unit_settled = [];

						// The batch's existing translations in one query; a missing key is no or an empty translation,
						// as get() === ''.
						if ( null === $existing_for_batch ) {
							$existing_for_batch = $skip_existing || $with_context
								? $translation_repo->get_many( $batch_ids, (int) $target_lang->id )
								: [];

							// Unread is not "untranslated": skip-existing would overwrite.
							if ( $skip_existing || $with_context ) {
								self::stop_on_failed_read();
							}
						}

						foreach ( $chunk_ids as $sid ) {
							// Settled by the stopped run: counted, not sent again.
							if ( isset( $chunk_settled[ $sid ] ) ) {
								if ( $chunk_settled[ $sid ] && $outdated ) {
									++$held;
								} elseif ( $chunk_settled[ $sid ] ) {
									++$skipped;
									++$already_translated;
								} else {
									++$failed;
								}

								++$processed;
								continue;
							}

							// An outdated run sends only the pairs flagged Outdated in this language.
							if ( $outdated && ! isset( $outdated_in[ $target_lang_id ][ $sid ] ) ) {
								++$skipped;
								++$processed;
								continue;
							}

							$row = $strings_by_id[ $sid ] ?? null;
							if ( ! $row ) {
								++$skipped;
								++$processed;
								continue;
							}

							// Skip-existing: never overwrite a non-empty (possibly human-edited) translation with
							// machine output.
							if ( $skip_existing && ( $existing_for_batch[ (int) $row->id ] ?? '' ) !== '' ) {
								++$skipped;
								++$already_translated;
								++$processed;
								continue;
							}

							if ( trim( (string) $row->original, StringRepository::BLANK_CHARS ) === '' ) {
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

						if ( '' !== $encoded_ids ) {
							try {
								$this->unit_checkpoint( $progress, $unit_start, $total, count( $target_lang_ids ), $dropped, $encoded_ids, (int) $items[0]['id'], $target_lang_slug, $chunk_settled, $rejected );
							} catch ( \PerfLocale\Background\JobCanceledException $e ) {
								// A cancel here stores the unit's start: strings the chunk only skipped can lie before
								// unsent ones.
								[ $processed, $translated, $skipped, $already_translated, $failed, $first_error, $held ] = $unit_start;
								throw $e;
							}
						}

						// Each held answer is based on the state sent, so a save during the provider call reads as an edit.
						if ( $outdated ) {
							$sent = \PerfLocale\Automation\StringTarget::snapshot( array_column( $items, 'id' ), $target_lang_id );

							foreach ( $items as $k => $item ) {
								$state               = $sent[ $item['id'] ] ?? null;
								$items[ $k ]['base'] = null === $state ? '' : \PerfLocale\Automation\StringTarget::hash( $state['translation'], $state['extra_forms'] );
							}
						}

						// A string without a letter (digits, punctuation, placeholders only) keeps its source, unsent, unless the filter sends it.
						// An outdated run holds it for review like any answer.
						foreach ( $items as $k => $item ) {
							if ( BlockStructureGuard::has_letter( str_replace( PlaceholderMasker::mask( $item['original'] )[1], '', $item['original'] ) ) ) {
								continue;
							}

							$send = false;

							if ( 1 !== preg_match( self::SPACE_ONLY, $item['original'] ) ) {
								/**
								 * Filter whether a string without a letter (digits, punctuation, placeholders) goes to machine translation,
								 * for example to localize digits. Blank strings (only spaces of any kind, tabs, line breaks or NUL) are
								 * never sent and never reach it.
								 *
								 * @hook  perflocale/strings/translate_letterless
								 * @since 1.0.8
								 *
								 * @param bool   $send          Default false: the string keeps its source.
								 * @param string $original      The string's original.
								 * @param string $language_code Target language code.
								 * @return bool
								 */
								$send = (bool) apply_filters( 'perflocale/strings/translate_letterless', false, $item['original'], $target_lang_slug );
							}

							if ( $send ) {
								continue;
							}

							$failed_before = $failed;
							$this->settle_item( $group_repo, $translation_repo, $item, $item['texts'], $target_lang_id, $translated, $failed, $processed, $first_error, $skip_existing, $skipped, $already_translated, $outdated, $held );
							$unit_settled[ $item['id'] ] = $failed === $failed_before;
							unset( $items[ $k ] );
						}

						$items = array_values( $items );

						if ( [] === $items ) {
							$tick( $processed );
							continue;
						}

						// The context of each item: its live translation (an outdated run with context only).
						$contexts = [];

						if ( $with_context ) {
							foreach ( $items as $k => $item ) {
								$contexts[ $k ] = self::string_context( $item, $existing_for_batch[ $item['id'] ] ?? '', $context_max, $source_lang_slug, $target_lang_slug, $service, $provider_id );
							}
						}

						if ( $per_string ) {
							foreach ( $items as $k => $item ) {
								try {
									$labels  = array_fill( 0, count( $item['texts'] ), self::item_label( $item ) );
									$outputs = '' === ( $contexts[ $k ] ?? '' )
										? $service->translate_batch_texts( $item['texts'], $source_lang_slug, $target_lang_slug, $provider_id, false, $item['format'], $labels )
										: $service->translate_batch_texts_with_context( $item['texts'], array_fill( 0, count( $item['texts'] ), $contexts[ $k ] ), $source_lang_slug, $target_lang_slug, $provider_id, false, $item['format'], $labels );
								} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
									// Provider breaker tripped: every further call would throw at once. The job pauses
									// at this unit.
									$breaker_error   = $e->getMessage();
									$breaker_stopped = true;
									$stop_settled    = $chunk_settled;
									break 4;
								} catch ( DeadlineReachedException $e ) {
									// Not the string's failure: the next slice sends it again.
									if ( '' === $encoded_ids ) {
										throw $e;
									}

									[ $processed, $translated, $skipped, $already_translated, $failed, $first_error, $held ] = $unit_start;
									$continue_at  = $e->retry_at();
									$unit_stopped = ! $e->paced();
									$stopped      = true;
									$stop_settled = $chunk_settled;
									break 4;
								} catch ( \Throwable $e ) {
									++$failed;
									++$processed;
									if ( $first_error === '' ) {
										$first_error = $e->getMessage();
									}
									$progress( $processed, $total );
									continue;
								}

								$this->settle_item( $group_repo, $translation_repo, $item, $outputs, $target_lang_id, $translated, $failed, $processed, $first_error, $skip_existing, $skipped, $already_translated, $outdated, $held );
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
							$labels = [];
							$slices = [];
							foreach ( $keys as $k ) {
								$slices[ $k ] = [ count( $flat ), count( $items[ $k ]['texts'] ) ];
								foreach ( $items[ $k ]['texts'] as $text ) {
									$flat[]   = $text;
									$labels[] = self::item_label( $items[ $k ] );
								}
							}

							// A run with context goes one string at a time (per_string): a batch has none.
							try {
								$results = $service->translate_batch_texts( $flat, $source_lang_slug, $target_lang_slug, $provider_id, false, (string) $format, $labels );
							} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
								// The chunk runs again on Retry, its settled format groups named, so its counts go back
								// too.
								[ $processed, $translated, $skipped, $already_translated, $failed, $first_error, $held ] = $unit_start;
								$breaker_error   = $e->getMessage();
								$breaker_stopped = true;
								$stop_settled    = $unit_settled + $chunk_settled;
								break 4;
							} catch ( DeadlineReachedException $e ) {
								// The chunk is sent again in the next slice, its settled format groups named, so its
								// counts go back too.
								if ( '' === $encoded_ids ) {
									throw $e;
								}

								[ $processed, $translated, $skipped, $already_translated, $failed, $first_error, $held ] = $unit_start;
								$continue_at  = $e->retry_at();
								$unit_stopped = ! $e->paced();
								$stopped      = true;
								$stop_settled = $unit_settled + $chunk_settled;
								break 4;
							} catch ( \Throwable $e ) {
								// The whole request failed: every string in it was attempted.
								$failed    += count( $keys );
								$processed += count( $keys );
								if ( $first_error === '' ) {
									$first_error = $e->getMessage();
								}
								foreach ( $keys as $k ) {
									$unit_settled[ (int) $items[ $k ]['id'] ] = false;
								}
								continue;
							}

							foreach ( $keys as $k ) {
								$failed_before = $failed;
								$this->settle_item( $group_repo, $translation_repo, $items[ $k ], array_slice( $results, $slices[ $k ][0], $slices[ $k ][1] ), $target_lang_id, $translated, $failed, $processed, $first_error, $skip_existing, $skipped, $already_translated, $outdated, $held );
								$unit_settled[ (int) $items[ $k ]['id'] ] = $failed === $failed_before;
							}
						}

						$tick( $processed );
					}
				}
			}

			// Final tick, so the UI reaches 100%; it can throw on cancel, and the finally still runs.
			if ( '' === $encoded_ids || $stopped || $breaker_stopped ) {
				$progress( $processed, $total );
			} else {
				// The end step's checkpoint: a kill while publishing resumes past the last string and names the step.
				$point = $this->stopped_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $processed, $already_translated, $encoded_ids, $held, $rejected );
				unset( $point['__perflocale_continue'] );

				$progress(
					$processed,
					$total,
					[
						'checkpoint' => $point,
						/* translators: Names the step a background job was on when its worker stopped. */
						'unit'       => __( 'publishing the translated strings', 'perflocale' ),
					]
				);
			}
		} catch ( \PerfLocale\Background\JobCanceledException $e ) {
			// The worker stores these counts on the canceled job; a slice's are a checkpoint (every unit before it
			// settled).
			if ( '' === $encoded_ids ) {
				$e->set_result( $this->run_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, false, $held, $rejected ) );
			} else {
				// The resumed chunk's settled strings still count when the cancel came at its start.
				$point = self::with_settled( $this->stopped_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $processed, $already_translated, $encoded_ids, $held, $rejected ), $chunk_settled );
				unset( $point['__perflocale_continue'] );
				$e->set_result( [ '__perflocale_checkpoint' => true ] + $point );
			}

			throw $e;
		} finally {
			if ( null !== $deadline ) {
				CallDeadline::set( $previous_deadline );
			}

			// Published once, when the run ends: a slice stopping for its continuation
			// publishes nothing (publish_after_run() covers a run ending between slices).
			if ( ! $stopped && ( $translated > 0 || $already_translated > 0 ) ) {
				self::publish_strings( $target_lang_ids );
			}
		}

		// Bound worker memory across slices (runtime cache and query log only).
		if ( null !== $slice ) {
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		if ( $stopped ) {
			$result = self::with_settled( $this->stopped_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $processed, $already_translated, $encoded_ids, $held, $rejected ), $stop_settled );

			if ( $continue_at > 0 ) {
				$result['__perflocale_continue_at'] = $continue_at;
			}

			// The worker counts it like a kill at this unit.
			if ( $unit_stopped ) {
				$result['__perflocale_unit_stop'] = true;
			}

			return $result;
		}

		// An open breaker with strings left fails the run (never complete), at a checkpoint Retry continues from.
		if ( $breaker_stopped ) {
			$result = '' !== $encoded_ids
				? self::with_settled( $this->stopped_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $processed, $already_translated, $encoded_ids, $held, $rejected ), $stop_settled )
				: $this->run_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, false, $held, $rejected );

			unset( $result['__perflocale_continue'] );
			$result['run_failed']                 = true;
			$result['__perflocale_checkpoint']    = '' !== $encoded_ids;
			$result['__perflocale_failed_reason'] = sprintf(
				/* translators: %s: the provider breaker's reason, e.g. "The provider is failing; calls are paused for a few minutes." */
				__( 'Paused by the provider breaker (%s). Retry the job to continue.', 'perflocale' ),
				$breaker_error
			);

			return $result;
		}

		$run_failed = $translated === 0 && $held === 0 && $failed > 0 && $first_error !== '';

		return $this->run_result( $total, $translated, $skipped, $failed, count( $target_lang_ids ), $dropped, $first_error, $run_failed, $held, $rejected );
	}

	/**
	 * Whether an outdated run sends each string's live translation as context: the context setting is not Off,
	 * "Include the previous translation" is on and the provider takes context.
	 *
	 * @param TranslationService $service     Service.
	 * @param string             $provider_id Provider id ('' = configured).
	 * @return bool
	 */
	public static function outdated_context_on( TranslationService $service, string $provider_id ): bool {
		$settings = Plugin::get_instance()->settings();

		if ( 'off' === \PerfLocale\MachineTranslation\ContextSettings::mode( $settings ) || ! \PerfLocale\MachineTranslation\ContextSettings::previous( $settings ) ) {
			return false;
		}

		try {
			return $service->provider_supports_context( $service->get_provider( $provider_id ) );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return false;
		}
	}

	/**
	 * The context of one outdated string: its live translation, through `perflocale/mt/context`.
	 *
	 * @param array<string, mixed> $item        From build_item().
	 * @phpstan-param StringItem $item
	 * @param string               $previous    The live translation.
	 * @param int                  $max_chars   The character limit.
	 * @param string               $source_lang Source language slug.
	 * @param string               $target_lang Target language slug.
	 * @param TranslationService   $service     Service.
	 * @param string               $provider_id Provider id ('' = configured).
	 * @return string
	 */
	private static function string_context( array $item, string $previous, int $max_chars, string $source_lang, string $target_lang, TranslationService $service, string $provider_id ): string {
		try {
			$provider = $service->get_provider( $provider_id )->get_id();
		} catch ( \RuntimeException $e ) {
			unset( $e );
			$provider = $provider_id;
		}

		return \PerfLocale\Resync\ContextBuilder::for_string(
			(string) ( $item['texts'][0] ?? '' ),
			$previous,
			$item['format'],
			$max_chars,
			[
				'object_type'               => 'string',
				'object_id'                 => $item['id'],
				'source_object_id'          => $item['id'],
				'post_type'                 => '',
				'source_language'           => $source_lang,
				'target_language'           => $target_lang,
				'trigger'                   => 'bulk_string_translate',
				'provider'                  => $provider,
				'provider_supports_context' => true,
				'job_id'                    => '',
				'user_id'                   => get_current_user_id(),
			]
		);
	}

	/**
	 * The context characters an outdated run in one language sends and the provider bills (the Strings page
	 * estimate): each string's context once per text it is sent as; 0 without billed context.
	 *
	 * @param array<int, int> $ids         The outdated string ids of the language.
	 * @param int             $language_id Target language id.
	 * @return int
	 */
	public function outdated_context_chars( array $ids, int $language_id ): int {
		$plugin   = Plugin::get_instance();
		$settings = $plugin->settings();
		$service  = new TranslationService( $settings, $plugin->cache() );

		if ( $ids === [] || ! self::outdated_context_on( $service, '' ) ) {
			return 0;
		}

		try {
			if ( ! $service->context_billed( $service->get_provider() ) ) {
				return 0;
			}
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return 0;
		}

		$lang    = [];
		$default = $plugin->lang_repo()->get_default();

		foreach ( $plugin->lang_repo()->get_active() as $active ) {
			$vars = get_object_vars( $active );

			if ( \PerfLocale\Util\Scalar::int( $vars['id'] ?? 0 ) === $language_id ) {
				$lang = $vars;
			}
		}

		if ( [] === $lang || ! $default ) {
			return 0;
		}

		$slug        = \PerfLocale\Util\Scalar::str( $lang['slug'] ?? '' );
		$locale      = is_string( $lang['locale'] ?? null ) && $lang['locale'] !== '' ? $lang['locale'] : $slug;
		$source_slug = \PerfLocale\Util\Scalar::str( get_object_vars( $default )['slug'] ?? '' );
		$live        = ( new StringTranslationRepository( $plugin->cache() ) )->get_many( $ids, $language_id );
		$max_chars   = \PerfLocale\MachineTranslation\ContextSettings::max_chars( $settings );
		$chars       = 0;

		foreach ( $this->load_strings_by_ids( $ids ) as $row ) {
			$item   = self::build_item( $row, $locale );
			$chars += \PerfLocale\Util\Utf8::length( self::string_context( $item, $live[ $item['id'] ] ?? '', $max_chars, $source_slug, $slug, $service, '' ) ) * count( $item['texts'] );
		}

		return $chars;
	}

	/**
	 * The budget of one slice: seconds and pairs (each 0 for none) and, on a web request, the time its
	 * provider calls must end by; the seconds also end before the request's limit ({@see CallDeadline}).
	 *
	 * @param bool $cli Whether the run is under WP-CLI.
	 * @return array{0: float, 1: int, 2: float|null}
	 */
	private static function slice_budget( bool $cli ): array {
		$default_seconds = $cli ? 0 : self::SLICE_SECONDS;
		$default_pairs   = $cli ? 0 : self::SLICE_MAX_PAIRS;

		/**
		 * Filter the seconds one slice of a background bulk string translation runs.
		 *
		 * 0 or less: no time budget under WP-CLI; on the web never past perflocale/jobs/web_slice_max_seconds (60 s).
		 * A value that is not a number keeps the default.
		 *
		 * @hook perflocale/jobs/bulk_string_translate/slice_seconds
		 * @param float $seconds Default 20 on web requests, 0 under WP-CLI.
		 */
		$seconds = apply_filters( 'perflocale/jobs/bulk_string_translate/slice_seconds', $default_seconds );

		/**
		 * Filter the most (string, target language) pairs one slice of a background bulk string translation runs.
		 *
		 * 0 or less: no pair cap; a value that is not a number keeps the default.
		 *
		 * @hook perflocale/jobs/bulk_string_translate/slice_max_pairs
		 * @param int $pairs Default 500 on web requests, 0 under WP-CLI.
		 */
		$pairs = apply_filters( 'perflocale/jobs/bulk_string_translate/slice_max_pairs', $default_pairs );

		$seconds  = (float) self::number_or( $seconds, $default_seconds );
		$deadline = null;

		if ( ! $cli ) {
			[ $seconds, $deadline ] = CallDeadline::for_slice( $seconds );
		}

		return [ $seconds, (int) self::number_or( $pairs, $default_pairs ), $deadline ];
	}

	/**
	 * A filter's return when it is a number, else the default.
	 *
	 * @param mixed     $value   Filtered value.
	 * @param int|float $default Default.
	 * @return int|float
	 */
	private static function number_or( mixed $value, int|float $default ): int|float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return is_string( $value ) && is_numeric( $value ) ? (float) $value : $default;
	}

	/**
	 * Make a run's strings visible: repository saves skip the strings cache invalidation, and files mode regenerates
	 * the `*.l10n.php` files.
	 *
	 * @param array<int> $target_lang_ids Target language ids of the run.
	 * @return void
	 */
	public static function publish_strings( array $target_lang_ids ): void {
		$plugin   = Plugin::get_instance();
		$cache    = $plugin->get( 'cache' );
		$settings = $plugin->get( 'settings' );

		foreach ( $target_lang_ids as $tlid ) {
			$cache->delete( 'all_str2_translations_' . (int) $tlid, 'perflocale_strings' );
		}

		if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			require_once __DIR__ . '/../../Cache/CacheDiagnostics.php';
			\PerfLocale\Cache\CacheDiagnostics::forget_map_sizes( $cache, $target_lang_ids );
		}

		if ( (string) $settings->get( 'string_translation_mode', '' ) === 'files' ) {
			set_transient( 'perflocale_strings_regenerating', 1, 5 * MINUTE_IN_SECONDS );
			/** @hook perflocale/strings/regenerate_files Regenerate the files-mode translation files after bulk string MT. */
			do_action( 'perflocale/strings/regenerate_files', $cache );
		}

		/**
		 * Fires after a bulk string-translation job changes `string_translations`, as the admin Strings save and PO
		 * import do, so add-ons that derive state from strings invalidate it.
		 *
		 * @hook perflocale/strings/changed
		 *
		 * @param string $origin What changed the strings ('bulk_mt').
		 */
		do_action( 'perflocale/strings/changed', 'bulk_mt' );
	}

	/**
	 * Publish the strings of a run that ended outside execute() (JobState's end-of-run step).
	 *
	 * @param array<mixed> $row The job row (JobState::get()).
	 * @return void
	 */
	public static function publish_after_run( array $row ): void {
		$started = $row['started_at'] ?? 0;

		if ( ! is_numeric( $started ) || (int) $started <= 0 ) {
			return;
		}

		$args    = is_array( $row['args'] ?? null ) ? $row['args'] : [];
		$targets = [];

		foreach ( (array) ( $args['target_lang_ids'] ?? [] ) as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$targets[] = (int) $id;
			}
		}

		if ( [] !== $targets ) {
			self::publish_strings( $targets );
		}
	}

	/**
	 * The counts and selection a slice resumes from, or null for a checkpoint this job did not write.
	 *
	 * @param array<mixed> $resume    Result stored by the previous slice.
	 * @param int          $n_targets Target languages of the job.
	 * @return array{string_ids: array<int, int>, processed: int, translated: int, skipped: int, already_translated: int, failed: int, first_error: string, capped: int, settled: array<int, bool>, held?: int, skipped_rejected?: int}|null
	 */
	private static function checkpoint( array $resume, int $n_targets ): ?array {
		$ids = is_string( $resume['string_ids'] ?? null ) ? self::decode_ids( $resume['string_ids'] ) : null;

		if ( null === $ids || [] === $ids ) {
			return null;
		}

		$count = static fn( string $key ): int => is_int( $resume[ $key ] ?? null ) ? $resume[ $key ] : -1;
		// An outdated run's counts; absent in every other run's checkpoint.
		$extra = static fn( string $key ): int => array_key_exists( $key, $resume ) ? $count( $key ) : 0;
		$held  = $extra( 'held' );
		$left  = $extra( 'skipped_rejected' );
		$out   = [
			'string_ids'         => $ids,
			'processed'          => $count( 'processed' ),
			'translated'         => $count( 'translated' ),
			'skipped'            => $count( 'skipped' ),
			'already_translated' => $count( 'already_translated' ),
			'failed'             => $count( 'failed' ),
			'first_error'        => is_string( $resume['first_error'] ?? null ) ? $resume['first_error'] : '',
			'capped'             => $count( 'capped' ),
		];

		if ( min( $out['processed'], $out['translated'], $out['skipped'], $out['already_translated'], $out['failed'], $out['capped'], $held, $left ) < 0
			|| $out['processed'] > count( $ids ) * $n_targets
			|| $out['processed'] !== $out['translated'] + $out['skipped'] + $out['failed'] + $held
			|| $out['already_translated'] > $out['skipped']
		) {
			return null;
		}

		$out['settled'] = self::settled( $resume );

		if ( $held > 0 ) {
			$out['held'] = $held;
		}

		if ( $left > 0 ) {
			$out['skipped_rejected'] = $left;
		}

		return $out;
	}

	/**
	 * The strings of a checkpoint's chunk its run settled (id => stored or kept); none unless each id is named once
	 * and there are at most a chunk's (the chunk is then sent again).
	 *
	 * @param array<mixed> $resume Stored checkpoint.
	 * @return array<int, bool>
	 */
	private static function settled( array $resume ): array {
		$settled = [];

		foreach ( self::SETTLED_KEYS as $key => $value ) {
			if ( ! isset( $resume[ $key ] ) ) {
				continue;
			}

			$list = is_string( $resume[ $key ] ) ? self::decode_ids( $resume[ $key ] ) : null;

			if ( null === $list || count( $settled ) + count( $list ) > self::BATCH_SIZE ) {
				return [];
			}

			foreach ( $list as $id ) {
				if ( isset( $settled[ $id ] ) ) {
					return [];
				}

				$settled[ $id ] = $value;
			}
		}

		return $settled;
	}

	/**
	 * A checkpoint naming the strings of its chunk that settled (settled()).
	 *
	 * @param array<string, mixed> $point   Checkpoint.
	 * @param array<int, bool>     $settled Id => stored or kept.
	 * @return array<string, mixed>
	 */
	private static function with_settled( array $point, array $settled ): array {
		ksort( $settled );

		foreach ( self::SETTLED_KEYS as $key => $value ) {
			$list = array_keys( $settled, $value, true );

			if ( [] !== $list ) {
				$point[ $key ] = self::encode_ids( $list );
			}
		}

		return $point;
	}

	/**
	 * An id list as short text: base-36 ids and "first-last" runs, comma separated, in order.
	 *
	 * @param array<int> $ids Ids.
	 * @return string
	 */
	private static function encode_ids( array $ids ): string {
		$ids   = array_values( $ids );
		$parts = [];
		$count = count( $ids );

		for ( $i = 0; $i < $count; ) {
			$first = (int) $ids[ $i ];
			$last  = $first;

			while ( $i + 1 < $count && (int) $ids[ $i + 1 ] === $last + 1 ) {
				++$i;
				++$last;
			}

			$parts[] = $last === $first ? base_convert( (string) $first, 10, 36 ) : base_convert( (string) $first, 10, 36 ) . '-' . base_convert( (string) $last, 10, 36 );
			++$i;
		}

		return implode( ',', $parts );
	}

	/**
	 * The id list encode_ids() wrote, or null for text it did not write.
	 *
	 * @param string $text Encoded list.
	 * @return array<int, int>|null
	 */
	private static function decode_ids( string $text ): ?array {
		if ( '' === $text || 1 !== preg_match( '/\A[0-9a-z]+(?:-[0-9a-z]+)?(?:,[0-9a-z]+(?:-[0-9a-z]+)?)*\z/', $text ) ) {
			return null;
		}

		$ids = [];
		$cap = self::max_per_dispatch();

		foreach ( explode( ',', $text ) as $part ) {
			$range = explode( '-', $part );
			$first = (int) base_convert( $range[0], 36, 10 );
			$last  = isset( $range[1] ) ? (int) base_convert( $range[1], 36, 10 ) : $first;

			if ( $first <= 0 || $last < $first || count( $ids ) + ( $last - $first + 1 ) > $cap ) {
				return null;
			}

			for ( $id = $first; $id <= $last; $id++ ) {
				$ids[] = $id;
			}
		}

		return $ids;
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
	 * @param bool                                                       $skip_existing    Keep a value saved meanwhile.
	 * @param int                                                        $skipped          Skipped count (by ref).
	 * @param int                                                        $already          Already-translated count (by ref).
	 * @param bool                                                       $hold             Hold the answer for review instead of writing it (an outdated run).
	 * @param int                                                        $held             Held count (by ref).
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
		string &$first_error,
		bool $skip_existing,
		int &$skipped,
		int &$already,
		bool $hold = false,
		int &$held = 0
	): void {
		$outcome = $this->persist_item( $group_repo, $translation_repo, $item, $outputs, $language_id, $hold, $skip_existing );

		++$processed;

		if ( ! empty( $outcome['kept'] ) ) {
			++$skipped;
			++$already;
			return;
		}

		if ( $outcome['error'] !== '' && $first_error === '' ) {
			$first_error = $outcome['error'];
		}

		if ( ! $outcome['saved'] ) {
			++$failed;
			return;
		}

		if ( $hold ) {
			++$held;
			return;
		}

		++$translated;

		/**
		 * Fires after each successful string machine translation save: mark the row, start a review, etc.
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
	 * @param int    $held        Held for review (an outdated run).
	 * @param int    $rejected    Outdated pairs a person's Reject or Undo left out.
	 * @return array<string, mixed>
	 */
	private function run_result( int $total, int $translated, int $skipped, int $failed, int $targets, int $capped, string $first_error, bool $run_failed, int $held = 0, int $rejected = 0 ): array {
		// Cut so the result always fits the jobs row's result cap.
		if ( mb_strlen( $first_error ) > self::FIRST_ERROR_MAX_CHARS ) {
			$first_error = mb_substr( $first_error, 0, self::FIRST_ERROR_MAX_CHARS ) . '...';
		}

		$result = [
			'total'       => $total,
			'translated'  => $translated,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'targets'     => $targets,
			'capped'      => $capped,
			'first_error' => $first_error,
		];

		if ( $held > 0 ) {
			$result['held'] = $held;
		}

		if ( $rejected > 0 ) {
			$result['skipped_rejected'] = $rejected;
		}

		if ( $run_failed ) {
			$result['run_failed'] = true;
		}

		return $result;
	}

	/**
	 * The result of a slice that stops before the last pair (counts, position, selection, continuation).
	 *
	 * @param int    $total              Pairs in the run.
	 * @param int    $translated         Saved.
	 * @param int    $skipped            Skipped.
	 * @param int    $failed             Failed.
	 * @param int    $targets            Target languages.
	 * @param int    $capped             Rows beyond the per-dispatch cap.
	 * @param string $first_error        First failure reason.
	 * @param int    $processed          Pairs done.
	 * @param int    $already_translated Skipped as already translated.
	 * @param string $encoded_ids        The selection (encode_ids()).
	 * @param int    $held               Held for review.
	 * @param int    $rejected           Outdated pairs a person's Reject or Undo left out.
	 * @return array<string, mixed>
	 */
	private function stopped_result( int $total, int $translated, int $skipped, int $failed, int $targets, int $capped, string $first_error, int $processed, int $already_translated, string $encoded_ids, int $held = 0, int $rejected = 0 ): array {
		$result = $this->run_result( $total, $translated, $skipped, $failed, $targets, $capped, $first_error, false, $held, $rejected );

		$result['processed']             = $processed;
		$result['already_translated']    = $already_translated;
		$result['string_ids']            = $encoded_ids;
		$result['__perflocale_continue'] = true;

		return $result;
	}

	/**
	 * Report the checkpoint before a unit's provider call: the unit's start, so a kill re-sends that unit only.
	 *
	 * @param callable               $progress    Progress callback.
	 * @param array<int, int|string> $at          Processed, translated, skipped, already translated, failed, first error, held at the unit's start.
	 * @param int                    $total       Pairs in the run.
	 * @param int                    $targets     Target languages.
	 * @param int                    $capped      Rows beyond the per-dispatch cap.
	 * @param string                 $encoded_ids The selection (encode_ids()).
	 * @param int                    $string_id   The unit's first string.
	 * @param string                 $slug        Target language slug.
	 * @param array<int, bool>       $settled     Strings of the chunk a stopped run settled.
	 * @param int                    $rejected    Outdated pairs a person's Reject or Undo left out.
	 * @phpstan-param array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string, 6: int} $at
	 * @return void
	 */
	private function unit_checkpoint( callable $progress, array $at, int $total, int $targets, int $capped, string $encoded_ids, int $string_id, string $slug, array $settled, int $rejected = 0 ): void {
		[ $processed, $translated, $skipped, $already_translated, $failed, $first_error, $held ] = $at;

		$point = self::with_settled( $this->stopped_result( $total, $translated, $skipped, $failed, $targets, $capped, $first_error, $processed, $already_translated, $encoded_ids, $held, $rejected ), $settled );
		unset( $point['__perflocale_continue'] );

		$progress(
			$processed,
			$total,
			[
				'checkpoint' => $point,
				/* translators: 1: string id, 2: target language slug. Names the item a background job was on. */
				'unit'       => sprintf( __( 'string %1$d into %2$s', 'perflocale' ), $string_id, $slug ),
			]
		);
	}

	/**
	 * Whether JobState stores the result as is (it drops one over its cap).
	 *
	 * @param array<string, mixed> $result Result.
	 * @return bool
	 */
	private static function fits_result_cap( array $result ): bool {
		$json = wp_json_encode( $result );

		return is_string( $json ) && strlen( $json ) <= \PerfLocale\Background\JobState::MAX_RESULT_BYTES;
	}

	/**
	 * Whether the provider translates a list in one request: a per-text provider gains nothing from a chunk call and
	 * loses the already-paid texts when one fails.
	 *
	 * @param TranslationService $service     Service.
	 * @param string             $provider_id Provider id ('' = configured).
	 * @return bool
	 */
	private function provider_batches_natively( TranslationService $service, string $provider_id ): bool {
		try {
			$provider = $service->get_provider( $provider_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			// The chunk call reports the provider error for every row.
			return true;
		}

		return ! $provider instanceof \PerfLocale\MachineTranslation\AbstractProvider || $provider->batches_natively();
	}

	/**
	 * A string's name in a failure message.
	 *
	 * @param array<string, mixed> $item Provider input of a string row.
	 * @phpstan-param StringItem $item
	 * @return string
	 */
	private static function item_label( array $item ): string {
		/* translators: %d: string ID */
		return sprintf( __( 'string #%d', 'perflocale' ), $item['id'] );
	}

	/**
	 * The provider input for one string row. A plural row with one count placeholder is sent once per plural form
	 * 1..N-1 of a target with three or more forms, a sample count in place of the placeholder ("%d items" -> "2 items",
	 * "5 items" for Polish), so the provider writes each form; every other row is sent once.
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
	 * `text` for a tag-free source, so the text sanitizer keeps `&` and quotes literal; `html` for markup or entities,
	 * and for masked text a text sanitizer would alter (percent-encoded octets).
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
	 * The single count placeholder of a plural original, or null when it has none or more than one printf placeholder.
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
	 * The texts a plural row is sent as for a locale, one per plural form 1..N-1 with its sample count, or null when it
	 * is sent as one text (not plural, fewer than three forms, not exactly one count placeholder). Public so the cost
	 * estimator counts what is sent.
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
	 * The smallest count that selects a plural form in a locale (2..200 first, then 0 and 1); -1 when none does.
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
	 * Whether exactly one count selects a plural form (Arabic forms 1 and 2): such a form may name its count in words,
	 * as gettext allows. Counts 0..1000 cover every rule's `n % 100` cycle.
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
	 * @param bool                                                       $hold             Hold the checked answer for review instead of saving it.
	 * @param bool                                                       $skip_existing    Keep a value saved meanwhile.
	 * @return array{saved: bool, error: string, translation: string, kept?: bool}
	 */
	private function persist_item(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		StringTranslationRepository $translation_repo,
		array $item,
		array $outputs,
		int $language_id,
		bool $hold = false,
		bool $skip_existing = false
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

				$forms_error = sprintf(
					/* translators: %d: string ID. */
					__( 'Empty translation returned for string #%d', 'perflocale' ),
					$string_id
				);
				break;
			}

			// The MT allowlist with the placeholders back, the source form's own held elements kept.
			$form_source  = PlaceholderMasker::restore( (string) ( $item['texts'][ $i ] ?? '' ), $item['phs'][ $i ] ?? [] );
			$text         = TranslationService::sanitize_mt_value_kept( PlaceholderMasker::restore( $out, $item['phs'][ $i ] ?? [] ), $form_source, $kept );
			$check_source = $original;

			if ( is_array( $item['numbers'] ) ) {
				$number = (int) $item['numbers'][ $i ];

				if ( ! empty( $item['only'][ $i ] ) && 0 === self::count_occurrences( $text, $number ) ) {
					// A form only one count selects, answered without the number, is kept as it is; every other
					// placeholder is still required.
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

			// Reject a translation that lost a placeholder: a failed row beats a malformed string on every page.
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

			// Kept source markup under the acting user's rules (the Strings screen's KSES without unfiltered_html).
			$forms[] = $kept ? \PerfLocale\Resync\SaveProbe::as_user( $text ) : $text;
		}

		if ( $hold ) {
			$hold_error = self::hold_item( $item, $language_id, $forms, $forms_error, $this->options_hash );

			return [
				'saved'       => '' === $hold_error,
				'error'       => '' === $hold_error ? $forms_error : $hold_error,
				'translation' => $forms[0],
			];
		}

		// As stored now: a person may have saved a value while the provider worked.
		$now = self::stored_now( $string_id, $language_id );

		if ( null === $now ) {
			return [
				'saved'       => false,
				'error'       => sprintf(
					/* translators: %d: string ID. */
					__( 'Failed to persist translation for string #%d.', 'perflocale' ),
					$string_id
				),
				'translation' => '',
			];
		}

		if ( $skip_existing && '' !== $now['translation'] ) {
			return [
				'saved'       => false,
				'error'       => '',
				'translation' => '',
				'kept'        => true,
			];
		}

		if ( $skip_existing ) {
			$extra = is_array( $item['numbers'] ) && $forms_error === '' && count( $forms ) > 1
				? StringTranslationRepository::normalize_extra_forms( array_slice( $forms, 1 ) )
				: null;
			$saved = $this->save_if_empty( $group_repo, $item['row'], $string_id, $language_id, $forms[0], $extra, $now );

			if ( false === $saved ) {
				return [
					'saved'       => false,
					'error'       => '',
					'translation' => '',
					'kept'        => true,
				];
			}

			return [
				'saved'       => true === $saved,
				'error'       => true === $saved ? $forms_error : $saved,
				'translation' => true === $saved ? $forms[0] : '',
			];
		}

		// The group as stored now: health-check --fix may have given the string its own one meanwhile.
		$save_error = $this->save_translation( $group_repo, $translation_repo, $item['row'], $language_id, $forms[0], $now['group_id'] );

		if ( $save_error !== '' ) {
			return [
				'saved'       => false,
				'error'       => $save_error,
				'translation' => '',
			];
		}

		$error = $forms_error;

		// Forms 2..N of a plural row; when a later form fails the row keeps its previous extra forms, and form 1 is
		// saved.
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
	 * Hold one outdated string's new translation for review: a pending string change based on the translation the
	 * run sent (item base), so Apply refuses it once a person changed that translation. Nothing live changes.
	 *
	 * @param array<string, mixed> $item         From build_item().
	 * @phpstan-param StringItem $item
	 * @param int                  $language_id  Target language id.
	 * @param array<int, string>   $forms        The checked translation, form 1 first.
	 * @param string               $forms_error  Why a later plural form was dropped ('' when none).
	 * @param string               $options_hash The run's options hash (hold_summary()).
	 * @return string '' when held, else the reason.
	 */
	private static function hold_item( array $item, int $language_id, array $forms, string $forms_error, string $options_hash ): string {
		$string_id = (int) $item['id'];
		$state     = \PerfLocale\Automation\StringTarget::state( $string_id, $language_id );
		$base      = $item['base'] ?? '';

		if ( null === $state || ( false !== $state && '' === $state['translation'] ) ) {
			return sprintf(
				/* translators: %d: string ID. */
				__( 'String #%d has no translation to update any more.', 'perflocale' ),
				$string_id
			);
		}

		if ( false === $state || '' === $base ) {
			return sprintf(
				/* translators: %d: string ID. */
				__( 'String #%d could not be read, so its new translation was not held for review.', 'perflocale' ),
				$string_id
			);
		}

		// Plural forms 2..N only when every form came back; otherwise the stored forms stay.
		$extra = is_array( $item['numbers'] ) && '' === $forms_error && count( $forms ) > 1 ? array_slice( $forms, 1 ) : null;

		$held = \PerfLocale\Automation\ChangeService::hold(
			[
				'area'         => \PerfLocale\Automation\Policy::AREA_STRING,
				'object_type'  => \PerfLocale\Enum\ObjectType::String->value,
				'object_id'    => $string_id,
				'source_id'    => 0,
				'language_id'  => $language_id,
				'origin'       => 'machine',
				'trigger_name' => 'resync_strings',
				'job_id'       => class_exists( \PerfLocale\Background\WorkerRunner::class, false ) ? \PerfLocale\Background\WorkerRunner::current_job_id( 'bulk_string_translate' ) : '',
				'user_id'      => get_current_user_id(),
				'actor'        => \PerfLocale\Automation\Policy::current_actor(),
				'decision'     => \PerfLocale\Automation\Policy::REVIEW,
				'reason'       => 'outdated',
				'payload'      => [
					'translation' => $forms[0],
					'extra_forms' => $extra,
				],
				'summary'      => self::hold_summary( \PerfLocale\Util\Scalar::str( $item['row']->original_hash ?? '' ), $options_hash ),
				'base_hash'    => $base,
			]
		);

		if ( 'held' === $held['status'] ) {
			return '';
		}

		return sprintf(
			/* translators: 1: string ID, 2: reason code. */
			__( 'The new translation of string #%1$d could not be held for review (%2$s).', 'perflocale' ),
			$string_id,
			$held['status']
		);
	}

	/**
	 * Put the count placeholder back where the provider wrote the sample count; the number must stand alone exactly
	 * once.
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
	 * Pattern for a count standing alone: not part of a longer, decimal ("5.5") or grouped ("1,000") number; sentence
	 * punctuation after it ("5." / "5,") is allowed.
	 *
	 * @param int $number Sample count.
	 * @return string
	 */
	private static function count_pattern( int $number ): string {
		return '/(?<!\d)(?<!\d[.,])' . $number . '(?![.,]?\d)/';
	}

	/**
	 * Keep the source's edge whitespace on a plain-text translation (the text sanitizer trims both ends).
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
	 * Persist one machine-translated string so it can be served, in a load-bearing order: heal an unusable group
	 * (mint_string_group()), then the link (the value alone is never served), then the value. Why:
	 * docs-ai/11-code-rationale.md, "BulkStringTranslateJob: save order and group repair".
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo       Group/link repository.
	 * @param StringTranslationRepository                                $translation_repo Translation-value repository.
	 * @param object                                                     $row              Source `strings` row; its `group_id` is updated in place when healed, so the caller's remaining target languages reuse the new group instead of minting another.
	 * @param int                                                        $language_id      Target language id.
	 * @param string                                                     $translated_text  Text to store.
	 * @param int                                                        $group_id         The string's group as stored right before the save.
	 * @return string Empty string on success; otherwise the operator-facing reason nothing was stored.
	 */
	private function save_translation(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		StringTranslationRepository $translation_repo,
		object $row,
		int $language_id,
		string $translated_text,
		int $group_id
	): string {
		$string_id         = (int) $row->id;
		$original_group_id = $group_id;
		$minted_group_id   = 0;

		if ( ! $group_repo->is_string_group( $group_id ) ) {
			$own             = false;
			$group_id        = $this->mint_string_group( $string_id, $original_group_id, $own );
			$minted_group_id = $own ? $group_id : 0;

			if ( $group_id === 0 ) {
				return sprintf(
					/* translators: %d: id of the row in the plugin's strings table. */
					__( 'Could not create a translation group for string #%d; its translation was not saved. Re-run to retry.', 'perflocale' ),
					$string_id
				);
			}

			$row->group_id = $group_id;
		}

		// For string groups object_id is the string id; upsert_link() is one upsert, so sibling-language links survive.
		$linked = $group_repo->upsert_link(
			$group_id,
			$string_id,
			$language_id,
			'translated',
			\PerfLocale\Enum\SourceType::MachineTranslation
		);

		if ( $linked === false ) {
			// A stale type='string' link of this string in another group blocks the object_lang key: drop it and
			// retry once. Never this group's link: after a failed write it still serves the stored translation.
			global $wpdb;

			if ( $wpdb instanceof \wpdb ) {
				$sql = $wpdb->prepare( 'DELETE FROM %i WHERE type = %s AND object_id = %d AND language_id = %d AND group_id != %d', Schema::table( 'translation_links' ), \PerfLocale\Enum\ObjectType::String->value, $string_id, $language_id, $group_id );

				if ( is_string( $sql ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Debris removal on a self-heal path (prepared above); no cache to consult.
					$wpdb->query( $sql );
				}
			}

			$linked = $group_repo->upsert_link(
				$group_id,
				$string_id,
				$language_id,
				'translated',
				\PerfLocale\Enum\SourceType::MachineTranslation
			);
		}

		if ( $linked === false ) {
			if ( $minted_group_id > 0 ) {
				self::undo_group_repair( $row, $string_id, $minted_group_id, $original_group_id );
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

		// A person's save between the link and value writes would leave its label over this text: the label follows the value.
		if ( null === self::claim_mt( $group_repo, $group_id, $string_id, $language_id, $translated_text ) ) {
			return self::not_labelled( $string_id );
		}

		return '';
	}

	/**
	 * Skip-existing save: the value statement itself writes only into an empty slot, so a value another request
	 * committed after the last read is kept, at any isolation level. Without a link the link goes first (a stopped run
	 * leaves a slot the next run fills); an existing one is relabelled after the value, only while it is as read.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo  Group/link repository.
	 * @param object                                                     $row         Source `strings` row; `group_id` follows a repair.
	 * @param int                                                        $string_id   Its id.
	 * @param int                                                        $language_id Target language id.
	 * @param string                                                     $text        Form 1.
	 * @param string|null                                                $extra       Forms 2..N JSON; null leaves them.
	 * @param array<string, mixed>                                       $now         From stored_now().
	 * @phpstan-param array{group_id: int, translation: string, link: array{source: string, status: string, object_id: int}|null} $now
	 * @return bool|string True saved, false kept (another writer's value), else why nothing was saved.
	 */
	private function save_if_empty(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		object $row,
		int $string_id,
		int $language_id,
		string $text,
		?string $extra,
		array $now
	): bool|string {
		$group_id = $now['group_id'];
		$link     = $now['link'];
		$minted   = 0;

		if ( ! $group_repo->is_string_group( $group_id ) ) {
			$own    = false;
			$minted = $this->mint_string_group( $string_id, $now['group_id'], $own );

			if ( $minted === 0 ) {
				return sprintf(
					/* translators: %d: id of the row in the plugin's strings table. */
					__( 'Could not create a translation group for string #%d; its translation was not saved. Re-run to retry.', 'perflocale' ),
					$string_id
				);
			}

			if ( $row instanceof \stdClass ) {
				$row->group_id = $minted;
			}

			$group_id = $minted;
			$link     = null;
		}

		if ( null === $link ) {
			if ( ! self::link_if_free( $group_repo, $group_id, $string_id, $language_id ) ) {
				if ( $minted > 0 && $own ) {
					self::undo_group_repair( $row, $string_id, $minted, $now['group_id'] );
				}

				return self::not_linked( $string_id );
			}

			return self::fill_empty( $string_id, $language_id, $text, $extra ) ?? self::not_saved( $string_id );
		}

		$as_mt = \PerfLocale\Enum\SourceType::MachineTranslation->value === $link['source'] && 'translated' === $link['status'] && $string_id === $link['object_id'];

		$filled     = null;
		$relabelled = false;

		// Fill and relabel as one unit (once more when it could not run): a person's save between them would find the
		// value but not yet the mt label.
		for ( $try = 0; $try < 2 && null === $filled; $try++ ) {
			[ $filled, $relabelled ] = \PerfLocale\Strings\StringLinkLabel::fill_and_claim(
				$group_repo,
				$group_id,
				$string_id,
				$language_id,
				\PerfLocale\Enum\SourceType::MachineTranslation,
				$text,
				$as_mt ? null : $link,
				static fn( string $marker ): ?bool => self::fill_empty( $string_id, $language_id, $text, $extra, $marker ),
				$extra
			);
		}

		if ( true !== $filled ) {
			return $filled ?? self::not_saved( $string_id );
		}

		if ( null === $relabelled ) {
			return self::not_labelled( $string_id );
		}

		// A value replaced meanwhile is another writer's: kept, with its link's label.
		if ( ! $as_mt && ! $relabelled && ! self::holds( $string_id, $language_id, $text ) ) {
			return false;
		}

		// A link changed meanwhile keeps its label; one removed meanwhile is written again, so the value is served.
		if ( ! $relabelled && ! self::link_if_free( $group_repo, $group_id, $string_id, $language_id ) ) {
			return self::not_linked( $string_id );
		}

		return true;
	}

	/**
	 * Why a saved string's link could not be labelled.
	 *
	 * @param int $string_id String.
	 * @return string
	 */
	private static function not_labelled( int $string_id ): string {
		return sprintf(
			/* translators: %d: id of the row in the plugin's strings table. */
			__( 'String #%d: the translation was saved, but it could not be marked as a machine translation (a database error). Run the job again.', 'perflocale' ),
			$string_id
		);
	}

	/**
	 * Label the link machine translation (StringLinkLabel::claim()), once more when the relabel could not run.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo  Group/link repository.
	 * @param int                                                        $group_id    String group.
	 * @param int                                                        $string_id   String.
	 * @param int                                                        $language_id Language.
	 * @param string                                                     $text        Form 1 the run wrote.
	 * @param array{source: string, status: string, object_id: int}|null $read        The link as read.
	 * @return bool|null Relabelled; null when it could not run twice.
	 */
	private static function claim_mt( \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo, int $group_id, int $string_id, int $language_id, string $text, ?array $read = null ): ?bool {
		return \PerfLocale\Strings\StringLinkLabel::claim( $group_repo, $group_id, $string_id, $language_id, \PerfLocale\Enum\SourceType::MachineTranslation, $text, $read )
			?? \PerfLocale\Strings\StringLinkLabel::claim( $group_repo, $group_id, $string_id, $language_id, \PerfLocale\Enum\SourceType::MachineTranslation, $text, $read );
	}

	/**
	 * The reason a string's link could not be written.
	 *
	 * @param int $string_id String.
	 * @return string
	 */
	private static function not_linked( int $string_id ): string {
		return sprintf(
			/* translators: %d: id of the row in the plugin's strings table. */
			__( 'Could not link string #%d to the target language; its translation was not saved. Re-run to retry.', 'perflocale' ),
			$string_id
		);
	}

	/**
	 * The reason a string's value could not be written.
	 *
	 * @param int $string_id String.
	 * @return string
	 */
	private static function not_saved( int $string_id ): string {
		return sprintf(
			/* translators: %d: string ID. */
			__( 'Failed to persist translation for string #%d.', 'perflocale' ),
			$string_id
		);
	}

	/**
	 * Form 1, and forms 2..N when given, only into an empty slot: INSERT IGNORE where no row is, else an UPDATE whose
	 * WHERE needs the stored value empty. The database decides both on the row as stored at the write.
	 *
	 * @param int         $string_id   String.
	 * @param int         $language_id Language.
	 * @param string      $text        Form 1.
	 * @param string|null $extra       Forms 2..N JSON; null leaves the stored ones.
	 * @param string      $marker      A ConnectionGuard condition (" AND ..."), appended after prepare(); '' for none.
	 * @return bool|null True written, false a stored value kept, null a database error.
	 */
	private static function fill_empty( int $string_id, int $language_id, string $text, ?string $extra, string $marker = '' ): ?bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$table = Schema::table( 'string_translations' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Conditional writes, prepared here; the UPDATE's replacements arrive as one array; the marker is the guard's hex-token condition.
		// With a marker the INSERT selects its values, so it can carry the condition.
		$values = '' === $marker ? ' VALUES (%d, %d, %s' : ' SELECT %d, %d, %s';
		$sql    = null === $extra
			? $wpdb->prepare( 'INSERT IGNORE INTO %i (string_id, language_id, translation)' . $values . ( '' === $marker ? ')' : ' FROM DUAL WHERE 1 = 1' ), $table, $string_id, $language_id, $text )
			: $wpdb->prepare( 'INSERT IGNORE INTO %i (string_id, language_id, translation, extra_forms)' . $values . ', %s' . ( '' === $marker ? ')' : ' FROM DUAL WHERE 1 = 1' ), $table, $string_id, $language_id, $text, $extra );

		$inserted = is_string( $sql ) ? $wpdb->query( $sql . $marker ) : false;

		if ( ! is_int( $inserted ) ) {
			return null;
		}

		if ( $inserted > 0 ) {
			return true;
		}

		// On SQLite the write-time fragment compares with a second binding of the text.
		$touch = Schema::touch_write_time( [ [ 'translation', '%s', 'text' ] ] );
		$sql   = $wpdb->prepare(
			'UPDATE %i SET translation = %s' . ( null === $extra ? '' : ', extra_forms = %s' ) . $touch . ' WHERE string_id = %d AND language_id = %d AND LENGTH(translation) = 0',
			array_merge( [ $table, $text ], null === $extra ? [] : [ $extra ], '' === $touch ? [] : [ $text ], [ $string_id, $language_id ] )
		);

		$updated = is_string( $sql ) ? $wpdb->query( $sql . $marker ) : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_int( $updated ) ? $updated > 0 : null;
	}

	/**
	 * Link a group to a language as MT unless a link already holds the key, which then stays as it is. A stale link of
	 * this string in another group (object_lang) is dropped and the insert retried once, as upsert_link() does.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo  Group/link repository.
	 * @param int                                                        $group_id    String group.
	 * @param int                                                        $string_id   String.
	 * @param int                                                        $language_id Language.
	 * @return bool Whether the group has a link in the language now.
	 */
	private static function link_if_free( \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo, int $group_id, int $string_id, int $language_id ): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Insert-only link write and its debris removal, prepared here.
		for ( $try = 0; $try < 2; $try++ ) {
			$sql      = $wpdb->prepare( "INSERT IGNORE INTO %i (group_id, object_id, language_id, type, status, source) VALUES (%d, %d, %d, 'string', 'translated', %s)", $links, $group_id, $string_id, $language_id, \PerfLocale\Enum\SourceType::MachineTranslation->value );
			$inserted = is_string( $sql ) ? $wpdb->query( $sql ) : false;

			if ( is_int( $inserted ) && $inserted > 0 ) {
				$group_repo->invalidate_group_cache( $group_id );

				/** @hook perflocale/translation/linked Fires after an object is linked to a group. */
				do_action( 'perflocale/translation/linked', $group_id, $string_id, $language_id );

				return true;
			}

			if ( 0 === $inserted && is_numeric( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d AND language_id = %d LIMIT 1', $links, $group_id, $language_id ) ) ) ) {
				return true;
			}

			// Never this group's link: after a failed write it still serves the stored translation.
			$sql = $wpdb->prepare( 'DELETE FROM %i WHERE type = %s AND object_id = %d AND language_id = %d AND group_id != %d', $links, \PerfLocale\Enum\ObjectType::String->value, $string_id, $language_id, $group_id );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return false;
	}

	/**
	 * Whether form 1 is still this run's text; a failed read answers yes, so the save is reported.
	 *
	 * @param int    $string_id   String.
	 * @param int    $language_id Language.
	 * @param string $text        Form 1 this run wrote.
	 * @return bool
	 */
	private static function holds( int $string_id, int $language_id, string $text ): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read after a refused compare-and-set; never cached.
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT translation FROM %i WHERE string_id = %d AND language_id = %d', Schema::table( 'string_translations' ), $string_id, $language_id ) );

		return ! is_string( $stored ) || $stored === $text;
	}

	/**
	 * Take back a group this run minted after its link failed, only while no link uses it (another request may have
	 * adopted it), and point the string back only while it still names that group.
	 *
	 * @param object $row       Source `strings` row.
	 * @param int    $string_id Its id.
	 * @param int    $minted    Group this run created.
	 * @param int    $original  The string's group before the repair.
	 * @return void
	 */
	private static function undo_group_repair( object $row, int $string_id, int $minted, int $original ): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional take-back of this run's own repair, prepared here.
		$sql     = $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND NOT EXISTS ( SELECT 1 FROM %i WHERE group_id = %d )', Schema::table( 'translation_groups' ), $minted, Schema::table( 'translation_links' ), $minted );
		$deleted = is_string( $sql ) ? $wpdb->query( $sql ) : false;

		if ( 1 !== $deleted ) {
			return;
		}

		$sql = $wpdb->prepare( 'UPDATE %i SET group_id = %d WHERE id = %d AND group_id = %d', Schema::table( 'strings' ), $original, $string_id, $minted );

		if ( is_string( $sql ) ) {
			$wpdb->query( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( $row instanceof \stdClass ) {
			$row->group_id = $original;
		}
	}

	/**
	 * Mint a string-type group and repoint one string at it (one INSERT, one UPDATE, as the admin save and
	 * TranslationFileGenerator's repair do). Both are checked: a failure reclaims the group and returns 0, so the row
	 * counts failed. Why: docs-ai/11-code-rationale.md, "BulkStringTranslateJob: save order and group repair".
	 *
	 * @param int  $string_id Row in the `strings` table to repair.
	 * @param int  $from      The group the caller read: the string moves only while it names it.
	 * @param bool $own       Receives whether the group is this call's (false: another writer's repair won).
	 * @return int New group id, the winner's string group, or 0 when the repair could not be completed.
	 */
	private function mint_string_group( int $string_id, int $from, bool &$own = false ): int {
		global $wpdb;

		if ( $string_id <= 0 || ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write; there is no cache to consult and no repository accessor that mints a bare string group.
		$inserted = $wpdb->insert(
			Schema::table( 'translation_groups' ),
			[ 'type' => \PerfLocale\Enum\ObjectType::String->value ],
			[ '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		// insert_id only after a confirmed INSERT: on a failed one some mysqli drivers keep a prior row's id (as in
		// StringRepository::bulk_insert()).
		$group_id = (int) $wpdb->insert_id;

		if ( $group_id <= 0 ) {
			return 0;
		}

		$taken = \PerfLocale\Database\Repository\StringGroupAssigner::repoint( $wpdb, $string_id, $from, $group_id );

		if ( 0 === $taken ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reclaiming the group this method just created, by primary key.
			$wpdb->delete( Schema::table( 'translation_groups' ), [ 'id' => $group_id ], [ '%d' ] );

			return 0;
		}

		$own = $taken === $group_id;

		return $taken;
	}

	/**
	 * Load every source-string row in one query.
	 *
	 * @param int[] $ids
	 * @param bool  $with_hash Also read `original_hash` (an outdated run's hold summary).
	 * @return array<int, object> string_id => row
	 */
	private function load_strings_by_ids( array $ids, bool $with_hash = false ): array {
		if ( $ids === [] ) {
			return [];
		}

		global $wpdb;
		$table        = Schema::table( 'strings' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, group_id, domain, context, original' . ( $with_hash ? ', original_hash' : '' ) . " FROM %i WHERE id IN ({$placeholders})",
				$table,
				...$ids
			)
		);
		// phpcs:enable

		// Unread is not "missing": every string would be counted skipped and the run completed.
		self::stop_on_failed_read();

		$by_id = [];
		foreach ( (array) $rows as $r ) {
			$by_id[ (int) $r->id ] = $r;
		}
		return $by_id;
	}

	/**
	 * Stop the run when the last read failed.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it failed.
	 */
	private static function stop_on_failed_read(): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'Could not read the strings to translate from the database, so the run stopped. Nothing was skipped; retry the job.', 'perflocale' ) );
		}
	}

	/**
	 * A string's group, its value and its group's link in one language as stored now (one read); null when the string
	 * is gone or the read failed. A string-type group is remembered, so the save needs no type read.
	 *
	 * @param int $string_id   String.
	 * @param int $language_id Language.
	 * @return array{group_id: int, translation: string, link: array{source: string, status: string, object_id: int}|null}|null
	 */
	private static function stored_now( int $string_id, int $language_id ): ?array {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row right before the write.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT s.group_id, g.type AS group_type, t.translation, l.source AS link_source, l.status AS link_status, l.object_id AS link_object FROM %i s LEFT JOIN %i g ON g.id = s.group_id LEFT JOIN %i t ON t.string_id = s.id AND t.language_id = %d LEFT JOIN %i l ON l.group_id = s.group_id AND l.language_id = %d WHERE s.id = %d', Schema::table( 'strings' ), Schema::table( 'translation_groups' ), Schema::table( 'string_translations' ), $language_id, Schema::table( 'translation_links' ), $language_id, $string_id ) );

		if ( ! is_object( $row ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$group_id = is_numeric( $row->group_id ?? null ) ? (int) $row->group_id : 0;

		if ( 'string' === ( $row->group_type ?? null ) ) {
			\PerfLocale\Database\Repository\TranslationGroupRepository::remember_string_group( $group_id );
		}

		return [
			'group_id'    => $group_id,
			'translation' => is_scalar( $row->translation ?? null ) ? (string) $row->translation : '',
			'link'        => is_scalar( $row->link_source ?? null ) ? [
				'source'    => (string) $row->link_source,
				'status'    => is_scalar( $row->link_status ?? null ) ? (string) $row->link_status : '',
				'object_id' => is_numeric( $row->link_object ?? null ) ? (int) $row->link_object : 0,
			] : null,
		];
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
