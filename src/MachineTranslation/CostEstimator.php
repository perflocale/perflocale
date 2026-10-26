<?php
/**
 * Machine-translation cost estimate before dispatch.
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
 * The characters a bulk machine translation will send, before any spend, for the confirm dialogs and the budget
 * gate: SQL length sums, never a get_post() loop. Approximate (masking, provider tag wrapping, unsent placeholder-only
 * values, a skip-existing count that reserves nothing); the monthly usage counter is what enforces.
 */
final class CostEstimator {

	/**
	 * Longest meta value (characters) read whole when it may be serialized.
	 */
	private const META_WHOLE_MAX = 1024;

	/**
	 * Characters read from each end of a longer value that may be serialized.
	 */
	private const META_EDGE = 64;

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
	 * Estimate a posts bulk translation (BulkTranslateJob): pairs that already have a translation are skipped by the
	 * job, so their characters are subtracted.
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
			return $this->summarize( 0, 0, 0 ) + self::builder_fields( 0 );
		}

		$ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// One aggregate query over title, content and excerpt, the fields translate_post() sends.
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

		// Meta as MetaTranslator sends it: its own key resolution, once per post type (per-post registry expansions are
		// ignored); the first row per (post, key), non-blank strings only.
		$meta_translator = $include_meta && $chars_by_post !== [] ? $this->meta_translator() : null;

		if ( $meta_translator !== null ) {
			$keys_by_type = [];
			foreach ( array_unique( $type_by_post ) as $ptype ) {
				$keys = $meta_translator->get_mt_meta_keys( $ptype );
				if ( $keys !== [] ) {
					$keys_by_type[ $ptype ] = $keys;
				}
			}

			foreach ( $keys_by_type as $ptype => $keys ) {
				$ids_of_type = array_keys( array_filter( $type_by_post, static fn( string $t ): bool => $t === $ptype ) );
				if ( $ids_of_type === [] ) {
					continue;
				}
				foreach ( self::meta_chars( $ids_of_type, $keys ) as $post_id => $chars ) {
					$chars_by_post[ $post_id ] = ( $chars_by_post[ $post_id ] ?? 0 ) + $chars;
				}
			}
		}

		// Pairs that exist (the job skips them): one join over each source group's sibling languages.
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

		$chars    = 0;
		$items    = 0;
		$skipped  = 0;
		$layouts  = 0;
		$builders = BuilderLayout::names_by_post( array_keys( $chars_by_post ) );

		foreach ( $chars_by_post as $pid => $post_chars ) {
			foreach ( $target_lang_ids as $lang_id ) {
				if ( isset( $existing[ $pid ][ $lang_id ] ) ) {
					++$skipped;
					continue;
				}
				$chars += $post_chars;
				++$items;
				$layouts += isset( $builders[ $pid ] ) ? 1 : 0;
			}
		}

		return $this->summarize( $chars, $items, $skipped ) + self::builder_fields( $layouts );
	}

	/**
	 * The estimate's page builder fields: items whose builder layout is not sent, and the sentence for them.
	 *
	 * @param int $items Items.
	 * @return array{builder_layouts: int, builder_note: string}
	 */
	public static function builder_fields( int $items ): array {
		return [
			'builder_layouts' => max( 0, $items ),
			'builder_note'    => self::builder_note( $items ),
		];
	}

	/**
	 * The sentence for items whose page builder layout is not sent, or '' for none.
	 *
	 * @param int $items Items.
	 * @return string
	 */
	public static function builder_note( int $items ): string {
		if ( $items <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of items (post and language pairs). */
			_n( 'The page builder layout of %d item is not included: machine translation does not send it.', 'The page builder layouts of %d items are not included: machine translation does not send them.', $items, 'perflocale' ),
			$items
		);
	}

	/**
	 * Meta characters MetaTranslator sends, per post, for one post type's keys.
	 *
	 * One SELECT; a long maybe-serialized value returns only its edges. First row per (post, key) counts.
	 *
	 * @param array<int, int> $ids_of_type Post IDs of the type.
	 * @param array<string>   $keys        The type's MT meta keys.
	 * @return array<int, int> Post ID => characters (posts with 0 left out).
	 */
	private static function meta_chars( array $ids_of_type, array $keys ): array {
		global $wpdb;

		$idph  = implode( ',', array_fill( 0, count( $ids_of_type ), '%d' ) );
		$keyph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// The blank test mirrors PHP's trim() character set.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$meta_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge(), which WPCS cannot count: the five bounds, then the IDs, then the keys.
			$wpdb->prepare(
				"SELECT post_id, meta_key, CHAR_LENGTH(meta_value) AS chars,
				        TRIM( REPLACE( REPLACE( REPLACE( REPLACE( REPLACE( meta_value, CHAR(9), '' ), CHAR(10), '' ), CHAR(13), '' ), CHAR(11), '' ), CHAR(0), '' ) ) = '' AS blank,
				        CASE WHEN LEFT( meta_value, 2 ) IN ( 'a:', 'O:', 'C:', 'b:', 'i:', 'd:', 's:', 'S:', 'E:', 'N;' ) AND CHAR_LENGTH(meta_value) <= %d THEN meta_value ELSE NULL END AS raw,
				        CASE WHEN LEFT( meta_value, 2 ) IN ( 'a:', 'O:', 'C:', 'b:', 'i:', 'd:', 's:', 'S:', 'E:', 'N;' ) AND CHAR_LENGTH(meta_value) > %d THEN LEFT( meta_value, %d ) ELSE NULL END AS head,
				        CASE WHEN LEFT( meta_value, 2 ) IN ( 'a:', 'O:', 'C:', 'b:', 'i:', 'd:', 's:', 'S:', 'E:', 'N;' ) AND CHAR_LENGTH(meta_value) > %d THEN SUBSTR( meta_value, -%d ) ELSE NULL END AS tail
				 FROM {$wpdb->postmeta}
				 WHERE post_id IN ({$idph}) AND meta_key IN ({$keyph})
				 ORDER BY meta_id ASC",
				array_merge( [ self::META_WHOLE_MAX, self::META_WHOLE_MAX, self::META_EDGE, self::META_WHOLE_MAX, self::META_EDGE ], $ids_of_type, $keys )
			),
			ARRAY_A
		);
		// phpcs:enable

		$out  = [];
		$seen = [];
		foreach ( is_array( $meta_rows ) ? $meta_rows : [] as $mr ) {
			if ( ! is_array( $mr ) ) {
				continue;
			}

			$post_id = self::to_int( $mr['post_id'] ?? 0 );
			$pk      = $post_id . '|' . self::to_string( $mr['meta_key'] ?? '' );
			if ( isset( $seen[ $pk ] ) ) {
				continue;
			}
			$seen[ $pk ] = true;

			$raw   = $mr['raw'] ?? null;
			$head  = $mr['head'] ?? null;
			$tail  = $mr['tail'] ?? null;
			$chars = self::to_int( $mr['chars'] ?? 0 );

			$blank = self::to_int( $mr['blank'] ?? 0 ) === 1;

			if ( ! $blank && is_string( $head ) && is_string( $tail ) ) {
				$chars = self::long_meta_chars( $chars, $head, $tail );
			} else {
				$chars = self::sent_meta_chars( $chars, $blank, is_string( $raw ) ? $raw : null );
			}

			if ( $chars > 0 ) {
				$out[ $post_id ] = ( $out[ $post_id ] ?? 0 ) + $chars;
			}
		}

		return $out;
	}

	/**
	 * Characters sent for a long maybe-serialized value, decided from its edges as is_serialized_string() would;
	 * an upper bound when the edges cannot settle it (wrong length, blank string, long number).
	 *
	 * @param int    $chars CHAR_LENGTH of the stored value.
	 * @param string $head  Its first META_EDGE characters.
	 * @param string $tail  Its last META_EDGE characters.
	 * @return int
	 */
	private static function long_meta_chars( int $chars, string $head, string $tail ): int {
		$end      = rtrim( $tail, " \t\n\r\0\x0B" );
		$trailing = strlen( $tail ) - strlen( $end );

		if ( strlen( $end ) < 2 || strlen( $head ) < 4 || ':' !== $head[1] ) {
			return $chars;
		}

		$last = substr( $end, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return $chars;
		}

		$token = $head[0];

		if ( 'a' === $token || 'O' === $token || 'E' === $token ) {
			return preg_match( '/^' . $token . ':[0-9]+:/', $head ) ? 0 : $chars;
		}

		if ( 's' !== $token || '"' !== substr( $end, -2, 1 ) || ! preg_match( '/^s:[0-9]+:/', $head ) ) {
			return $chars;
		}

		// Serialized: only a string whose header opens a quote unserializes to text.
		if ( ';' !== $last || ! preg_match( '/^s:([0-9]+):"/', $head, $m ) ) {
			return 0;
		}

		$text  = $chars - strlen( $m[0] ) - 2 - $trailing;
		$bytes = (int) $m[1];

		// UTF-8 text of $text characters takes $text to 4 x $text bytes.
		return $text > 0 && $bytes >= $text && $bytes <= 4 * $text ? $text : 0;
	}

	/**
	 * Characters MetaTranslator sends for one meta row.
	 *
	 * @param int         $chars CHAR_LENGTH of the stored value.
	 * @param bool        $blank The stored value is whitespace only.
	 * @param string|null $raw   The stored value when it may be serialized.
	 * @return int 0 when the value is not sent.
	 */
	private static function sent_meta_chars( int $chars, bool $blank, ?string $raw ): int {
		if ( $blank ) {
			return 0;
		}

		if ( $raw === null || ! is_serialized( $raw ) ) {
			return $chars;
		}

		// A serialized string unserializes to text and is sent; arrays, objects, numbers and bools are skipped.
		if ( ! is_serialized_string( $raw ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- a serialized scalar string, classes refused.
		$value = unserialize( $raw, [ 'allowed_classes' => false ] );

		return is_string( $value ) && trim( $value ) !== '' ? mb_strlen( $value ) : 0;
	}

	/**
	 * MetaTranslator for its key resolution, or null without the cache service (then no meta is counted).
	 *
	 * @return MetaTranslator|null
	 */
	private function meta_translator(): ?MetaTranslator {
		$cache = \PerfLocale\Plugin::get_instance()->get( 'cache' );

		if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
			return null;
		}

		return new MetaTranslator( $this->settings, new TranslationService( $this->settings, $cache ) );
	}

	/**
	 * A database value as an int (0 when it is not numeric).
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function to_int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A database value as a string ('' when it is not scalar).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function to_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Estimate a strings bulk translation (BulkStringTranslateJob): with skip_existing, strings already translated for
	 * a language are subtracted; a plural row counts every form sent for a language with three or more forms.
	 *
	 * @param int[] $string_ids      String row IDs (pre-resolved by the caller;
	 *                               mode=filter/all callers resolve first).
	 * @param int[] $target_lang_ids Target language IDs.
	 * @param bool  $overwrite       Overwrite run (skip_existing=false).
	 * @return array<string, mixed>
	 */
	public function estimate_strings( array $string_ids, array $target_lang_ids, bool $overwrite = false ): array {
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

		// Volume already translated per target language (skip_existing).
		$done = $overwrite ? [] : $wpdb->get_results(
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

		// Plural rows: one text per form 1..N-1 in a language with three or more forms. ' (plural)' has no LIKE wildcard.
		$plural_found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, context, original FROM %i
				 WHERE id IN ({$sph}) AND ( context = 'plural' OR context LIKE %s )",
				array_merge( [ $strings_table ], $string_ids, [ '% (plural)' ] )
			),
			ARRAY_A
		);

		$plural_rows = [];
		foreach ( is_array( $plural_found ) ? $plural_found : [] as $pr ) {
			if ( is_array( $pr ) ) {
				$plural_rows[] = [
					'id'       => self::to_int( $pr['id'] ?? 0 ),
					'context'  => self::to_string( $pr['context'] ?? '' ),
					'original' => self::to_string( $pr['original'] ?? '' ),
				];
			}
		}

		$plural_done = [];
		if ( ! $overwrite && $plural_rows !== [] ) {
			$pids = array_column( $plural_rows, 'id' );
			$pph  = implode( ',', array_fill( 0, count( $pids ), '%d' ) );

			$pairs_done = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT string_id, language_id FROM %i
					 WHERE string_id IN ({$pph}) AND language_id IN ({$lph}) AND translation <> ''",
					array_merge( [ $st_table ], $pids, $target_lang_ids )
				),
				ARRAY_A
			);

			foreach ( is_array( $pairs_done ) ? $pairs_done : [] as $pd ) {
				if ( is_array( $pd ) ) {
					$plural_done[ self::to_int( $pd['language_id'] ?? 0 ) ][ self::to_int( $pd['string_id'] ?? 0 ) ] = true;
				}
			}
		}
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

		$lang_repo = $plural_rows !== [] ? \PerfLocale\Plugin::get_instance()->get( 'lang_repo' ) : null;
		$lang_repo = $lang_repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $lang_repo : null;

		foreach ( $target_lang_ids as $lang_id ) {
			$d        = $done_by_lang[ $lang_id ] ?? [
				'cnt'   => 0,
				'chars' => 0,
			];
			$chars   += max( 0, $total_chars - $d['chars'] );
			$items   += max( 0, $total_cnt - $d['cnt'] );
			$skipped += $d['cnt'];

			if ( $lang_repo === null ) {
				continue;
			}

			$language = $lang_repo->find( $lang_id );
			$vars     = is_object( $language ) ? get_object_vars( $language ) : [];
			$locale   = self::to_string( $vars['locale'] ?? '' );
			$locale   = $locale !== '' ? $locale : self::to_string( $vars['slug'] ?? '' );

			if ( $locale === '' ) {
				continue;
			}

			foreach ( $plural_rows as $row ) {
				if ( isset( $plural_done[ $lang_id ][ $row['id'] ] ) ) {
					continue;
				}

				$forms = \PerfLocale\Background\Jobs\BulkStringTranslateJob::plural_form_texts( $row['original'], $row['context'], $locale );

				if ( $forms === null ) {
					continue;
				}

				$sent = 0;
				foreach ( $forms as $form ) {
					$sent += mb_strlen( $form['text'] );
				}

				$chars += $sent - mb_strlen( $row['original'] );
			}
		}

		return $this->summarize( $chars, $items, $skipped );
	}

	/**
	 * A character count with the monthly budget context the confirm dialogs and the dispatch gate need.
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
			// As TranslationService::would_exceed_limit(), including its used >= limit answer at chars 0.
			'would_exceed'      => $limit !== 0 && ( $used >= $limit || ( $used + $chars ) > $limit ),
		];
	}
}
