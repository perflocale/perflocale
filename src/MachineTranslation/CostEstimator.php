<?php
/**
 * Pre-dispatch machine-translation cost estimator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Database\Schema;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes the character volume a bulk MT operation is ABOUT to send —
 * before any provider spend — so confirm dialogs can show real numbers and
 * the dispatch-time budget gate can veto over-cap jobs up front.
 *
 * Estimates are aggregate SQL (CHAR_LENGTH sums), never N get_post() loops,
 * so estimating a 10k-post site costs a handful of indexed queries.
 *
 * Estimates are documented as APPROXIMATE, not billing-grade: placeholder
 * masking and provider-side tag wrapping (DeepL) shift billed characters
 * slightly, and the skip-existing subtraction mirrors — but does not
 * atomically reserve against — the jobs' own skip rules. Enforcement truth
 * remains the atomic monthly usage counter.
 */
final class CostEstimator {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Estimate a posts bulk translation (the BulkTranslateJob shape).
	 *
	 * Mirrors the job's semantics: (source, target-language) pairs that
	 * already have a translation are SKIPPED (BulkTranslateJob never
	 * overwrites), so their characters are subtracted from the estimate.
	 *
	 * @param int[] $post_ids        Source post IDs.
	 * @param int[] $target_lang_ids Target language IDs.
	 * @param bool  $include_meta    Include MT-able meta characters (keys from
	 *                               the perflocale/mt/translatable_meta_keys
	 *                               registry, resolved per post type).
	 * @return array<string, mixed> See summarize() for the envelope shape.
	 */
	public function estimate_posts( array $post_ids, array $target_lang_ids, bool $include_meta = false ): array {
		global $wpdb;

		$post_ids        = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $i ): bool => $i > 0 ) ) );
		$target_lang_ids = array_values( array_unique( array_filter( array_map( 'intval', $target_lang_ids ), static fn( int $i ): bool => $i > 0 ) ) );

		if ( $post_ids === [] || $target_lang_ids === [] ) {
			return $this->summarize( 0, 0, 0 );
		}

		$ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Per-post character volume in ONE aggregate query (title+content+excerpt,
		// the exact fields translate_post() sends to the provider).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type,
				        ( CHAR_LENGTH(post_title) + CHAR_LENGTH(post_content) + CHAR_LENGTH(post_excerpt) ) AS chars
				 FROM {$wpdb->posts}
				 WHERE ID IN ({$ph})",
				$post_ids
			),
			ARRAY_A
		);

		$chars_by_post = [];
		$type_by_post  = [];
		foreach ( (array) $rows as $r ) {
			$chars_by_post[ (int) $r['ID'] ] = (int) $r['chars'];
			$type_by_post[ (int) $r['ID'] ]  = (string) $r['post_type'];
		}

		// Optional meta volume, resolved once per post TYPE (the registry's
		// per-post expansions are ignored here — estimate-grade approximation).
		if ( $include_meta && $chars_by_post !== [] ) {
			$keys_by_type = [];
			foreach ( array_unique( $type_by_post ) as $ptype ) {
				/** This filter is documented in src/MachineTranslation/MetaTranslator.php */
				$keys = (array) apply_filters( 'perflocale/mt/translatable_meta_keys', [], $ptype, 0 );
				$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
				if ( $keys !== [] ) {
					$keys_by_type[ $ptype ] = $keys;
				}
			}

			foreach ( $keys_by_type as $ptype => $keys ) {
				$ids_of_type = array_keys( array_filter( $type_by_post, static fn( string $t ): bool => $t === $ptype ) );
				if ( $ids_of_type === [] ) {
					continue;
				}
				$idph  = implode( ',', array_fill( 0, count( $ids_of_type ), '%d' ) );
				$keyph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

				$meta_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, SUM( CHAR_LENGTH(meta_value) ) AS chars
						 FROM {$wpdb->postmeta}
						 WHERE post_id IN ({$idph}) AND meta_key IN ({$keyph})
						 GROUP BY post_id",
						array_merge( $ids_of_type, $keys )
					),
					ARRAY_A
				);
				foreach ( (array) $meta_rows as $mr ) {
					$chars_by_post[ (int) $mr['post_id'] ] = ( $chars_by_post[ (int) $mr['post_id'] ] ?? 0 ) + (int) $mr['chars'];
				}
			}
		}

		// Which (source, language) pairs already exist — the job skips them.
		// One join across the translation graph: every sibling language of
		// every source post's group.
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$pairs        = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$wpdb->prepare(
				"SELECT l1.object_id AS source_id, l2.language_id
				 FROM %i l1
				 INNER JOIN %i g ON g.id = l1.group_id AND g.type = 'post'
				 INNER JOIN %i l2 ON l2.group_id = l1.group_id
				 WHERE l1.object_id IN ({$ph})",
				array_merge( [ $links_table, $groups_table, $links_table ], $post_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$existing = [];
		foreach ( (array) $pairs as $p ) {
			$existing[ (int) $p['source_id'] ][ (int) $p['language_id'] ] = true;
		}

		$chars   = 0;
		$items   = 0;
		$skipped = 0;

		foreach ( $chars_by_post as $pid => $post_chars ) {
			foreach ( $target_lang_ids as $lang_id ) {
				if ( isset( $existing[ $pid ][ $lang_id ] ) ) {
					++$skipped;
					continue;
				}
				$chars += $post_chars;
				++$items;
			}
		}

		return $this->summarize( $chars, $items, $skipped );
	}

	/**
	 * Estimate a strings bulk translation (the BulkStringTranslateJob shape).
	 *
	 * Mirrors skip_existing=true semantics: strings that already carry a
	 * non-empty translation for a target language are subtracted.
	 *
	 * @param int[] $string_ids      String row IDs (pre-resolved by the caller;
	 *                               mode=filter/all callers resolve first).
	 * @param int[] $target_lang_ids Target language IDs.
	 * @return array<string, mixed>
	 */
	public function estimate_strings( array $string_ids, array $target_lang_ids ): array {
		global $wpdb;

		$string_ids      = array_values( array_unique( array_filter( array_map( 'intval', $string_ids ), static fn( int $i ): bool => $i > 0 ) ) );
		$target_lang_ids = array_values( array_unique( array_filter( array_map( 'intval', $target_lang_ids ), static fn( int $i ): bool => $i > 0 ) ) );

		if ( $string_ids === [] || $target_lang_ids === [] ) {
			return $this->summarize( 0, 0, 0 );
		}

		$strings_table = Schema::table( 'strings' );
		$st_table      = Schema::table( 'string_translations' );
		$sph           = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );
		$lph           = implode( ',', array_fill( 0, count( $target_lang_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE( SUM( CHAR_LENGTH(original) ), 0 ) AS chars
				 FROM %i WHERE id IN ({$sph})",
				array_merge( [ $strings_table ], $string_ids )
			),
			ARRAY_A
		);

		// Already-translated volume per target language (skip_existing).
		$done = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.language_id, COUNT(*) AS cnt, COALESCE( SUM( CHAR_LENGTH(s.original) ), 0 ) AS chars
				 FROM %i st
				 INNER JOIN %i s ON s.id = st.string_id
				 WHERE st.string_id IN ({$sph}) AND st.language_id IN ({$lph}) AND st.translation <> ''
				 GROUP BY st.language_id",
				array_merge( [ $st_table, $strings_table ], $string_ids, $target_lang_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$total_cnt   = (int) ( $totals['cnt'] ?? 0 );
		$total_chars = (int) ( $totals['chars'] ?? 0 );

		$chars   = 0;
		$items   = 0;
		$skipped = 0;

		$done_by_lang = [];
		foreach ( (array) $done as $d ) {
			$done_by_lang[ (int) $d['language_id'] ] = [
				'cnt'   => (int) $d['cnt'],
				'chars' => (int) $d['chars'],
			];
		}

		foreach ( $target_lang_ids as $lang_id ) {
			$d        = $done_by_lang[ $lang_id ] ?? [
				'cnt'   => 0,
				'chars' => 0,
			];
			$chars   += max( 0, $total_chars - $d['chars'] );
			$items   += max( 0, $total_cnt - $d['cnt'] );
			$skipped += $d['cnt'];
		}

		return $this->summarize( $chars, $items, $skipped );
	}

	/**
	 * Wrap a raw character count in the monthly-budget context every confirm
	 * dialog and the dispatch gate need.
	 *
	 * @param int $chars   Characters about to be sent.
	 * @param int $items   Translatable (source, language) work items.
	 * @param int $skipped Items skipped by the skip-existing rules.
	 * @return array{chars:int, items:int, skipped_existing:int, monthly_used:int, monthly_limit:int, monthly_remaining:int, would_exceed:bool}
	 */
	public function summarize( int $chars, int $items, int $skipped ): array {
		$limit = (int) $this->settings->get( 'mt_monthly_char_limit', 500000 );
		$used  = (int) get_option( 'perflocale_mt_usage_' . gmdate( 'Y_m' ), 0 );

		return [
			'chars'             => $chars,
			'items'             => $items,
			'skipped_existing'  => $skipped,
			'monthly_used'      => $used,
			'monthly_limit'     => $limit,
			'monthly_remaining' => $limit === 0 ? -1 : max( 0, $limit - $used ),
			// Mirrors TranslationService::would_exceed_limit exactly (incl.
			// its used >= limit early return, which matters at chars=0).
			'would_exceed'      => $limit !== 0 && ( $used >= $limit || ( $used + $chars ) > $limit ),
		];
	}
}
