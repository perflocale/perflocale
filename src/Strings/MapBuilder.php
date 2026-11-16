<?php
/**
 * The database-mode string map, built on a cache miss.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

use PerfLocale\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a language's hash => translation map for StringTranslation; loaded only on a cache miss (off the classmap).
 *
 * While the identity switch-over runs (state option not `done`), each row is keyed from its columns,
 * so a row still under its other-form hash is served by the key a lookup computes. Uses no Util class, to load one class.
 */
final class MapBuilder {

	/**
	 * Fewest rows per map read: below this a miss sends too many statements.
	 *
	 * @var int
	 */
	private const MIN_CHUNK_ROWS = 256;

	/**
	 * Most rows per map read: above this one range's rows bring back the whole-language memory peak.
	 *
	 * @var int
	 */
	private const MAX_CHUNK_ROWS = 8192;

	/**
	 * One language's map: the current language (driven from string_translations), or a fallback chain entry.
	 *
	 * @param int  $language_id Language id.
	 * @param bool $fallback    Whether it is a fallback language (the plan driven from strings).
	 * @return array<mixed>
	 */
	public static function build( int $language_id, bool $fallback ): array {
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$bridge = self::bridging();
		$tables = [ Schema::table( 'strings' ), Schema::table( 'translation_links' ), Schema::table( 'translation_groups' ), Schema::table( 'string_translations' ) ];
		$select = "SELECT s.original_hash, st.translation AS translated_text, st.extra_forms, s.domain,
						CASE WHEN s.domain IN (%s, %s, %s, %s) THEN s.context END AS context,
						CASE WHEN s.domain IN (%s, %s, %s, %s) THEN s.file_path END AS file_path,
						CASE WHEN %d = 1 THEN s.context END AS b_ctx, CASE WHEN %d = 1 THEN s.original END AS b_text";

		// The current language is driven from string_translations, so its key narrows to this language before the other
		// joins; a fallback language from strings. See docs-ai/11-code-rationale.md, "StringTranslation: preload query plan".
		$sql = $fallback
			? $select . "
						FROM %i s
						INNER JOIN %i g ON g.id = s.group_id AND g.type = 'string'
						INNER JOIN %i l ON l.group_id = s.group_id AND l.language_id = %d
						INNER JOIN %i st ON st.string_id = s.id AND st.language_id = %d
						WHERE st.translation != '' AND s.id > %d AND s.id <= %d"
			: $select . "
						FROM %i st
						INNER JOIN %i s
							ON s.id = st.string_id
						INNER JOIN %i g
							ON g.id = s.group_id AND g.type = 'string'
						INNER JOIN %i l
							ON l.group_id = s.group_id AND l.language_id = st.language_id
						WHERE st.language_id = %d
						AND st.translation != '' AND st.string_id > %d AND st.string_id <= %d";
		$args = $fallback
			? [ $tables[0], $tables[2], $tables[1], $language_id, $tables[3], $language_id ]
			: [ $tables[3], $tables[0], $tables[2], $tables[1], $language_id ];

		$chunk    = self::chunk_rows();
		$after    = 0;
		$map      = [];
		$extra    = [];
		$domains  = [];
		$prefixes = [];
		$gettext  = false;

		// Read in string id ranges of $chunk rows, so only one range's rows are held at a time; null ends the last range.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A per-range bound, uncached by design.
			$last = $wpdb->get_var( $wpdb->prepare( 'SELECT string_id FROM %i WHERE language_id = %d AND string_id > %d ORDER BY string_id LIMIT 1 OFFSET %d', $tables[3], $language_id, $after, $chunk - 1 ) );
			$upto = null === $last ? PHP_INT_MAX : (int) $last;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The positioned domains are spread in twice, once per CASE list.
			$results = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( StringTranslation::POSITIONED, StringTranslation::POSITIONED, [ $bridge, $bridge ], $args, [ $after, $upto ] ) ) );
			$after   = $upto;

			foreach ( is_array( $results ) ? $results : [] as $row ) {
				$domain = self::as_string( $row->domain ?? null );

				// Internal "_" domains (`_pfl_dyn`) are output-buffer served: they must not register the filters.
				if ( str_starts_with( $domain, '_' ) ) {
					continue;
				}

				$hash = self::row_key( $row );

				// An other-form row never overrides its twin's value under the identity both are keyed by.
				if ( isset( $map[ $hash ] ) && $hash !== self::as_string( $row->original_hash ?? null ) ) {
					continue;
				}

				// The winner's value comes with its own plural forms only, never a replaced twin's.
				$map[ $hash ] = self::as_string( $row->translated_text ?? null );
				unset( $extra[ $hash ] );

				if ( StringTranslation::is_lookup_only( $domain ) ) {
					$domains[ $domain ] = '1';

					// Site title and tagline rows (option name as context, no position) have no prefix.
					$prefix = self::context_prefix( $row );

					if ( '' !== $prefix ) {
						$prefixes[ $domain ][ $prefix ] = '1';
					}
				} else {
					$gettext = true;
				}

				// Plural forms 2..N live in extra_forms JSON (NULL for 2-form languages).
				$forms = self::as_string( $row->extra_forms ?? null );

				if ( '' !== $forms ) {
					$decoded = json_decode( $forms, true );

					if ( is_array( $decoded ) && $decoded !== [] ) {
						$extra[ $hash ] = self::as_strings( $decoded );
					}
				}
			}

			$results = null;
		} while ( null !== $last );

		// Reserved keys start with NUL (no hash collision); a missing key reads the pessimistic default.
		if ( $extra !== [] ) {
			$map[ StringTranslation::EXTRA_FORMS_KEY ] = $extra;
		}

		if ( $map !== [] ) {
			$map[ StringTranslation::GETTEXT_ROWS_KEY ]     = $gettext ? '1' : '0';
			$map[ StringTranslation::DOMAINS_KEY ]          = $domains;
			$map[ StringTranslation::CONTEXT_PREFIXES_KEY ] = $prefixes;
		}

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $map;
	}

	/**
	 * Rows per map read: one per 256 KiB of memory_limit, MIN_CHUNK_ROWS to MAX_CHUNK_ROWS (2,048 at 512M).
	 *
	 * @return int
	 */
	private static function chunk_rows(): int {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$rows  = $limit > 0 ? min( self::MAX_CHUNK_ROWS, max( self::MIN_CHUNK_ROWS, intdiv( $limit, 262144 ) ) ) : self::MAX_CHUNK_ROWS;

		/**
		 * Filter the rows read per query when a language's string map is built (once per build, on a cache miss).
		 *
		 * @internal Not public: the default already follows memory_limit.
		 * @hook  perflocale/strings/map_chunk_rows
		 * @since 1.0.8
		 *
		 * @param int $rows Default from memory_limit. Clamped to 256..8,192; a value that is not a number keeps the default.
		 */
		/**
		 * A filter may return anything; only a number is taken.
		 *
		 * @var mixed $value
		 */
		$value = apply_filters( 'perflocale/strings/map_chunk_rows', $rows );

		// Clamped as a float, so a huge or infinite number never meets an out-of-range int cast.
		$n = is_numeric( $value ) ? (float) $value : (float) $rows;

		return is_nan( $n ) ? $rows : (int) min( self::MAX_CHUNK_ROWS, max( self::MIN_CHUNK_ROWS, $n ) );
	}

	/**
	 * Whether map keys are bridged: 1 until the switch-over is `done` (autoloaded then), and after it while P1 kept twin
	 * pairs, whose other-form row's stored hash can be another tuple's identity.
	 *
	 * @return int 1 or 0 (a query parameter).
	 */
	public static function bridging(): int {
		$state = json_decode( self::as_string( get_option( 'perflocale_str_ident', '' ) ), true );

		return is_array( $state ) && 'done' === ( $state['phase'] ?? '' ) && empty( $state['held_n'] ) ? 0 : 1;
	}

	/**
	 * A map row's key. While bridging (b_text set): a row whose stored hash is its columns' other-form hash is
	 * keyed by its identity hash; any other row by its stored hash. Else the stored hash.
	 *
	 * @param \stdClass $row Map row (original_hash, domain, b_ctx, b_text).
	 * @return string
	 */
	public static function row_key( \stdClass $row ): string {
		$stored = self::as_string( $row->original_hash ?? null );

		if ( ! isset( $row->b_text ) ) {
			return $stored;
		}

		[ $domain, $context, $text ] = [ self::as_string( $row->domain ?? null ), self::as_string( $row->b_ctx ?? null ), self::as_string( $row->b_text ) ];

		return StringTranslation::identity_hash( $domain, $context, $text, true ) === $stored ? StringTranslation::identity_hash( $domain, $context, $text ) : $stored;
	}

	/**
	 * A lookup-only row's context prefix: the context through its last `|` (`post_type|book|`), else the
	 * `mb:{box id}` head of a file_path starting with `mb:`.
	 *
	 * @param \stdClass $row A map row (its context and file_path columns).
	 * @return string '' when the row has neither.
	 */
	private static function context_prefix( \stdClass $row ): string {
		$context   = self::as_string( $row->context ?? null );
		$file_path = self::as_string( $row->file_path ?? null );
		$bar       = strrpos( $context, '|' );

		if ( false !== $bar ) {
			return substr( $context, 0, $bar + 1 );
		}

		if ( str_starts_with( $file_path, 'mb:' ) ) {
			$slash = strpos( $file_path, '/' );

			return false === $slash ? $file_path : substr( $file_path, 0, $slash );
		}

		return '';
	}

	/**
	 * A scalar as a string, anything else ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function as_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * An array as a list of strings (non-scalars as ''); a non-array as [].
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private static function as_strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];

		foreach ( $value as $item ) {
			$out[] = self::as_string( $item );
		}

		return $out;
	}
}
