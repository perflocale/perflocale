<?php
/**
 * Binds a machine translation write to the values it read, in core's own UPDATE.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the expected column values to the WHERE of the UPDATE a write sends, so a row changed since the read is not
 * matched. The write is recognised by its hook level (nested queries are never it); a write at that level of another
 * shape is refused. Only on a charset the scanner cannot read (unbound_charset()) do callers send it unbound.
 */
final class ExpectedWrite {

	/** Blanks MySQL and wpdb's rows_affected test both skip. */
	private const BLANKS = " \t\n\r\x0B\f";

	/**
	 * Charsets the scanner reads byte for byte: single-byte ones, and multibyte ones whose trail bytes are 0x80+ or letters.
	 * On any other (big5, cp932, gb18030, gbk, sjis, an unknown name) writes are sent unbound. Reasons:
	 * docs-ai/11-code-rationale.md, ExpectedWrite.
	 */
	private const CHARSETS = [ 'utf8', 'utf8mb3', 'utf8mb4', 'binary', 'ascii', 'euckr', 'gb2312', 'ujis', 'eucjpms', 'armscii8', 'cp1250', 'cp1251', 'cp1256', 'cp1257', 'cp850', 'cp852', 'cp866', 'dec8', 'geostd8', 'greek', 'hebrew', 'hp8', 'keybcs2', 'koi8r', 'koi8u', 'latin1', 'latin2', 'latin5', 'latin7', 'macce', 'macroman', 'swe7', 'tis620' ];

	/**
	 * The server's client and connection charsets as read for a wpdb object: [wpdb, "client,connection"].
	 *
	 * @var array{0?: object, 1?: string}
	 */
	private static array $server = [];

	/**
	 * The unbound_charset() verdict per wpdb object: [wpdb, name or null].
	 *
	 * @var array{0?: object, 1?: string|null}
	 */
	private static array $unbound = [];

	/**
	 * Hook stack a query sent by the current code runs under.
	 *
	 * @param string $hook Hook that replaces 'query' (the action the write's own query follows).
	 * @return list<mixed>
	 */
	public static function level( string $hook = 'query' ): array {
		$stack = $GLOBALS['wp_current_filter'] ?? [];

		return array_merge( is_array( $stack ) ? array_values( $stack ) : [], [ $hook ] );
	}

	/**
	 * Whether the hook running now is at $level.
	 *
	 * @param array<int, mixed> $level From level().
	 * @return bool
	 */
	public static function at( array $level ): bool {
		$stack = $GLOBALS['wp_current_filter'] ?? null;

		return is_array( $stack ) && array_values( $stack ) === $level;
	}

	/**
	 * With an empty DB_CHARSET wpdb sends no SET NAMES: read the server's charsets once per request, at guard setup, not
	 * inside the query filter. Never on SQLite (no MySQL session variables; the driver lexes UTF-8). A failed read is retried.
	 *
	 * @return void
	 */
	public static function read_charset(): void {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		if ( '' !== (string) $wpdb->charset || is_a( $wpdb, 'WP_SQLite_DB' ) || ( self::$server[0] ?? null ) === $wpdb ) {
			return;
		}

		$quiet = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session variables, no table.
		$names = $wpdb->get_var( "SELECT CONCAT_WS(',', @@character_set_client, @@character_set_connection)" );
		$wpdb->suppress_errors( $quiet );

		if ( is_string( $names ) ) {
			self::$server = [ $wpdb, $names ];
		}
	}

	/**
	 * The connection charset writes are sent unbound on (one the scanner cannot read), or null: bind as usual. Reads the
	 * server first when needed; memoized per connection, a failed read is null (bind() then refuses) and read again.
	 *
	 * @return string|null
	 */
	public static function unbound_charset(): ?string {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		if ( ( self::$unbound[0] ?? null ) === $wpdb ) {
			return self::$unbound[1] ?? null;
		}

		self::read_charset();
		$names = self::charsets();

		if ( null === $names ) {
			return null;
		}

		$off           = array_values( array_diff( $names, self::CHARSETS ) );
		self::$unbound = [ $wpdb, $off[0] ?? null ];

		return $off[0] ?? null;
	}

	/**
	 * Whether a column of $table is stored in another charset than the connection's: MD5() then hashes other bytes than
	 * PHP read, so holds() never matches and the write goes unbound. Only the server's own answer confirms a mismatch:
	 * get_col_charset() may be filtered or cached from before a table conversion.
	 *
	 * @param string             $table   Table.
	 * @param array<int, string> $columns Columns holds() reads.
	 * @return bool
	 */
	public static function converts( string $table, array $columns ): bool {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$names = self::charsets();

		if ( null === $names || is_a( $wpdb, 'WP_SQLite_DB' ) ) {
			return false;
		}

		$cached = array_map( static fn( string $column ) => $wpdb->get_col_charset( $table, $column ), $columns );

		if ( ! self::differs( $cached, $names ) ) {
			return false;
		}

		$quiet = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The live column charsets, past the filter and cache.
		$read = $wpdb->query( (string) $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ) );
		$wpdb->suppress_errors( $quiet );
		// A query a filter cancelled returns false and leaves an earlier query's rows.
		$rows = false === $read ? [] : $wpdb->last_result;
		$now  = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$row       = get_object_vars( $row );
			$collation = $row['Collation'] ?? null;

			if ( is_string( $row['Field'] ?? null ) && in_array( strtolower( $row['Field'] ), $columns, true ) ) {
				$now[] = is_string( $collation ) && '' !== $collation ? explode( '_', $collation )[0] : false;
			}
		}

		return self::differs( $now, $names );
	}

	/**
	 * Whether a string column charset in $charsets differs from one of $names. utf8mb3 bytes read the same over utf8mb4;
	 * a utf8mb4 column read over utf8mb3 turns 4-byte characters into '?'.
	 *
	 * @param array<int, mixed>  $charsets Column charsets; anything but a string is not a string column.
	 * @param array<int, string> $names    The connection's charsets.
	 * @return bool
	 */
	private static function differs( array $charsets, array $names ): bool {
		$alias = static fn( string $name ): string => 'utf8' === $name ? 'utf8mb3' : $name;

		foreach ( $charsets as $charset ) {
			$charset = is_string( $charset ) ? $alias( strtolower( $charset ) ) : 'binary';

			foreach ( 'binary' === $charset ? [] : array_map( $alias, $names ) as $name ) {
				if ( 'binary' !== $name && $name !== $charset && ! ( 'utf8mb4' === $name && 'utf8mb3' === $charset ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether the connection's charsets are known (false: no DB_CHARSET and the server read failed, so bind() refuses).
	 *
	 * @return bool
	 */
	public static function charset_known(): bool {
		return null !== self::charsets();
	}

	/**
	 * The lower-case charsets the statement is escaped, lexed and converted in; null when the server was not read. The
	 * client library's own charset is included: wpdb::set_charset() from a plugin, or a failed one, keeps $wpdb->charset.
	 *
	 * @return list<string>|null
	 */
	private static function charsets(): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$name = (string) $wpdb->charset;

		if ( '' === $name && ( self::$server[0] ?? null ) !== $wpdb ) {
			return null;
		}

		$names = '' !== $name ? [ $name ] : explode( ',', (string) ( self::$server[1] ?? '' ) );
		$dbh   = $wpdb->__get( 'dbh' );

		if ( $dbh instanceof \mysqli ) {
			$names[] = (string) $dbh->character_set_name();
		}

		return array_map( 'strtolower', $names );
	}

	/**
	 * Whether every charset of the connection is one the scanner reads byte for byte.
	 *
	 * @return bool
	 */
	private static function readable_charset(): bool {
		$names = self::charsets();

		return null !== $names && [] === array_diff( $names, self::CHARSETS );
	}

	/**
	 * Whether a statement may change rows (anything but a read).
	 *
	 * @param string $query SQL.
	 * @return bool
	 */
	public static function writes( string $query ): bool {
		// Only plain comments are skipped: an executable one, or a line comment some engine ends at CR, counts as a write.
		$start = (string) preg_replace( '#\A(?:\s+|/\*(?![!+]|M!).*?\*/|(?:--\s|\#)[^\n\r\0]*(?:\n|\z))*#si', '', $query );

		return 1 !== preg_match( '/\A(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN(?!\s+ANALYZE))\b/i', $start );
	}

	/**
	 * $query with " AND $condition" ending its WHERE, or null when it is not "$head... <one of $tails>" followed only by
	 * blanks and plain comments, read as the connection lexes it (quotes escaped by backslash or doubled).
	 *
	 * @param string             $query     SQL.
	 * @param string             $head      Start of the statement, after blanks only (wpdb reads rows_affected then).
	 * @param array<int, string> $tails     Exact ends of the statement's WHERE.
	 * @param string             $condition SQL condition.
	 * @return string|null
	 */
	public static function bind( string $query, string $head, array $tails, string $condition ): ?string {
		$quote = esc_sql( "'" );
		$start = strspn( $query, self::BLANKS );
		$from  = $start + strlen( $head );

		if ( ( "\\'" !== $quote && "''" !== $quote ) || ! self::readable_charset() || substr( $query, $start, strlen( $head ) ) !== $head ) {
			return null;
		}

		$slash = "\\'" === $quote;
		$end   = self::code_end( $query, $from, strlen( $query ), $slash );

		foreach ( $tails as $tail ) {
			$cut = null === $end ? 0 : $end - strlen( $tail );

			// The tail ends the code and starts outside every literal and bracket.
			if ( null !== $end && $cut > $from && substr( $query, $cut, strlen( $tail ) ) === $tail && null !== self::code_end( $query, $from, $cut, $slash ) ) {
				return substr( $query, 0, $end ) . ' AND ' . $condition . substr( $query, $end );
			}
		}

		return null;
	}

	/**
	 * End of the code in $sql from $at to $to when only blanks and closed plain comments follow it; null when the span
	 * ends in a literal or bracket, or holds `;`, `"`, `\`, `$`, a `--` before no blank, a line comment holding CR or NUL,
	 * or a nested, unclosed or executable comment.
	 *
	 * @param string $sql   SQL.
	 * @param int    $at    Offset outside literals and comments.
	 * @param int    $to    End of the span.
	 * @param bool   $slash Whether a backslash escapes the next byte in a '' literal (no NO_BACKSLASH_ESCAPES).
	 * @return int|null
	 */
	private static function code_end( string $sql, int $at, int $to, bool $slash ): ?int {
		$end     = $at;
		$depth   = 0;
		$comment = false;

		while ( $at < $to ) {
			$c    = $sql[ $at ];
			$next = $at + 1 < $to ? $sql[ $at + 1 ] : '';

			if ( str_contains( self::BLANKS, $c ) ) {
				++$at;
				continue;
			}

			if ( '/' === $c && '*' === $next ) {
				$close = strpos( $sql, '*/', $at + 2 );

				if ( false === $close || $close + 2 > $to || 1 === preg_match( '#\G/\*(?:[!+]|M!)#i', $sql, $m, 0, $at ) || str_contains( substr( $sql, $at + 2, $close - $at - 2 ), '/*' ) ) {
					return null;
				}

				$comment = true;
				$at      = $close + 2;
				continue;
			}

			// "--" before a blank only; a CR or NUL in the comment is refused (SQLite's lexer ends the comment at CR).
			if ( '#' === $c || ( '-' === $c && '-' === $next ) ) {
				$line = strpos( $sql, "\n", $at );
				$stop = false === $line || $line >= $to ? $to : $line;

				if ( ( '#' !== $c && ( $at + 2 >= $to || ! str_contains( self::BLANKS, $sql[ $at + 2 ] ) ) ) || strcspn( $sql, "\r\0", $at, $stop - $at ) < $stop - $at ) {
					return null;
				}

				$at      = $stop === $to ? $to : $stop + 1;
				$comment = true;
				continue;
			}

			if ( $comment ) {
				return null;
			}

			if ( "'" === $c || '`' === $c ) {
				$stop = "'" === $c && $slash ? "'\\" : $c;

				// A backslash skips the next byte; a doubled quote is one quote.
				for ( $i = $at + 1; $i < $to; $i += 2 ) {
					$i += strcspn( $sql, $stop, $i, $to - $i );

					if ( $i < $to && $c === $sql[ $i ] && $c !== ( $sql[ $i + 1 ] ?? '' ) ) {
						break;
					}
				}

				if ( $i >= $to ) {
					return null;
				}

				$at  = $i + 1;
				$end = $at;
				continue;
			}

			if ( '(' === $c ) {
				++$depth;
			} elseif ( ( ')' === $c && --$depth < 0 ) || str_contains( ';"\\$', $c ) ) {
				return null;
			}

			$end = ++$at;
		}

		return 0 === $depth ? $end : null;
	}

	/**
	 * SQL condition: $column holds exactly $value, byte for byte (`=` would follow the collation); null is SQL NULL.
	 * IS NOT NULL: SQLite's md5() hashes NULL as ''.
	 *
	 * @param string      $column Column name.
	 * @param string|null $value  Value as stored.
	 * @return string
	 */
	public static function holds( string $column, ?string $value ): string {
		return null === $value ? '`' . $column . '` IS NULL' : '`' . $column . '` IS NOT NULL AND MD5(`' . $column . "`) = '" . md5( $value ) . "'";
	}

	/**
	 * A value as a quoted SQL literal, as wpdb::prepare() puts it once the query filters run.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function literal( string $value ): string {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		return "'" . $wpdb->remove_placeholder_escape( esc_sql( $value ) ) . "'";
	}

	/**
	 * Log a write refused because its statement was not recognised, or because the connection charset is unknown.
	 *
	 * @param string $what What was not saved.
	 * @return void
	 */
	public static function refused( string $what ): void {
		$why = self::charset_known() ? 'another plugin changed the database query.' : 'the database server did not report its connection charset. Set DB_CHARSET to utf8mb4 in wp-config.php.';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the write is refused instead of being sent unchecked.
		error_log( sprintf( 'PerfLocale: %s was not saved because %s', $what, $why ) );
	}
}
