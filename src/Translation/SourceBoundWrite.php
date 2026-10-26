<?php
/**
 * Makes the write of kept bytes conditional on the source, in the same SQL statement.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes kept source bytes only while the source still holds them, checked in the same SQL statement.
 *
 * A token in the post data becomes `CASE WHEN <source holds what was checked> THEN kept ELSE plain END` in the save's
 * own posts-table write only; no query stores the token. See docs-ai/11-code-rationale.md, "SourceBoundWrite: token rewrite".
 */
final class SourceBoundWrite {

	/**
	 * Token standing in for the value.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Column the token stands in for.
	 *
	 * @var string
	 */
	private string $column;

	/**
	 * Row the write updates; 0 when the write is the save's INSERT.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Blog of the save, recorded by arm().
	 *
	 * @var int
	 */
	private int $blog_id = 0;

	/**
	 * Posts table of the save, recorded by arm().
	 *
	 * @var string
	 */
	private string $table = '';

	/**
	 * Hook stack the write runs under (the arming data filter's, with `query` in its place); null when armed
	 * outside a post data filter, so no query is the write.
	 *
	 * @var list<mixed>|null
	 */
	private ?array $stack = null;

	/**
	 * Value written when the source still holds what was checked.
	 *
	 * @var string
	 */
	private string $kept;

	/**
	 * Value written without keeping (unslashed); null keeps the column's stored value.
	 *
	 * @var string|null
	 */
	private ?string $plain;

	/**
	 * The plain value as a quoted SQL literal, built once when needed.
	 *
	 * @var string|null
	 */
	private ?string $plain_literal = null;

	/**
	 * The condition, built when armed.
	 *
	 * @var string
	 */
	private string $condition;

	/**
	 * Registered filter.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $filter = null;

	/**
	 * Whether the write carrying the token went out with the condition.
	 *
	 * @var bool
	 */
	private bool $sent = false;

	/**
	 * Whether another query carried the token before the write.
	 *
	 * @var bool
	 */
	private bool $foreign = false;

	/**
	 * Post a write that keeps nothing is logged for; 0 logs nothing.
	 *
	 * @var int
	 */
	private int $log_post;

	/**
	 * Constructor.
	 *
	 * @param string      $column    post_content or post_excerpt.
	 * @param int         $post_id   Post the save updates; 0 for a save that inserts.
	 * @param string      $kept      Value to write (unslashed).
	 * @param string|null $plain     Value written without keeping (unslashed); null keeps the stored column value, and the write is not sent after another query carried the token.
	 * @param string      $condition SQL condition from self::source_holds().
	 * @param int         $log_post  Post a write that keeps nothing is logged for; 0 logs nothing.
	 */
	public function __construct( string $column, int $post_id, string $kept, ?string $plain, string $condition, int $log_post = 0 ) {
		$this->token     = 'pfl-c12-write-' . wp_generate_uuid4();
		$this->column    = 'post_excerpt' === $column ? 'post_excerpt' : 'post_content';
		$this->post_id   = max( 0, $post_id );
		$this->kept      = $kept;
		$this->plain     = $plain;
		$this->condition = $condition;
		$this->log_post  = $log_post;
	}

	/**
	 * The token to put in the post data in place of the value.
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Whether the write went out with the condition.
	 *
	 * @return bool
	 */
	public function sent(): bool {
		return $this->sent;
	}

	/**
	 * Add the query filter, from the `wp_insert_post_data` (or `wp_insert_attachment_data`) callback of the save.
	 *
	 * @return void
	 */
	public function arm(): void {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		if ( null !== $this->filter ) {
			return;
		}

		$stack = $GLOBALS['wp_current_filter'] ?? [];
		$stack = is_array( $stack ) ? array_values( $stack ) : [];
		$hook  = array_pop( $stack );

		$this->blog_id = get_current_blog_id();
		$this->table   = $wpdb->posts;
		$this->stack   = in_array( $hook, [ 'wp_insert_post_data', 'wp_insert_attachment_data' ], true ) ? array_merge( $stack, [ 'query' ] ) : null;
		$this->filter  = fn( mixed $query ): mixed => $this->rewrite( $query );

		add_filter( 'query', $this->filter, PHP_INT_MAX, 1 );
	}

	/**
	 * Remove the filter. Safe to call more than once.
	 *
	 * @return void
	 */
	public function disarm(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter, PHP_INT_MAX );
			$this->filter = null;
		}
	}

	/**
	 * SQL condition: the source still holds $value and $status, is not in the trash and is linked as checked.
	 *
	 * The status check stops a source made private after the read check. `=` follows the case-insensitive
	 * collation, so MD5() makes it exact. wpdb's '%' placeholder escape is removed: the filter that strips it has run.
	 *
	 * @param int    $source_id  Source post id.
	 * @param string $column     post_content or post_excerpt.
	 * @param string $value      Value checked.
	 * @param string $status     Status checked.
	 * @param int    $target_id  Translation post id, 0 for no link condition.
	 * @param bool   $as_default The source must be the group's default-language post.
	 * @return string
	 */
	public static function source_holds( int $source_id, string $column, string $value, string $status, int $target_id = 0, bool $as_default = false ): string {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$column = 'post_excerpt' === $column ? 'post_excerpt' : 'post_content';
		$links  = '`' . esc_sql( \PerfLocale\Database\Schema::table( 'translation_links' ) ) . '`';
		$join   = '';

		if ( $target_id > 0 ) {
			$join = ' INNER JOIN ' . $links . " pfl_s ON pfl_s.type = 'post' AND pfl_s.object_id = pfl_p.ID INNER JOIN " . $links . " pfl_t ON pfl_t.group_id = pfl_s.group_id AND pfl_t.type = 'post' AND pfl_t.object_id = " . $target_id;

			if ( $as_default ) {
				$join .= ' INNER JOIN `' . esc_sql( \PerfLocale\Database\Schema::table( 'languages' ) ) . '` pfl_l ON pfl_l.id = pfl_s.language_id AND pfl_l.is_default = 1';
			}
		}

		$literal = self::literal( $value );

		return 'EXISTS ( SELECT 1 FROM ( SELECT pfl_p.ID FROM `' . esc_sql( $wpdb->posts ) . '` pfl_p' . $join
			. ' WHERE pfl_p.ID = ' . $source_id
			. " AND pfl_p.post_status <> 'trash'"
			. ' AND pfl_p.post_status = ' . self::literal( $status )
			. ' AND pfl_p.' . $column . ' = ' . $literal
			. ' AND MD5( pfl_p.' . $column . " ) = '" . md5( $value ) . "'"
			. ' LIMIT 1 ) pfl_c12 )';
	}

	/**
	 * A value as a quoted SQL literal, escaped as wpdb escapes.
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
	 * `query` callback.
	 *
	 * @param mixed $query SQL.
	 * @return mixed
	 */
	private function rewrite( mixed $query ): mixed {
		if ( ! is_string( $query ) || ! str_contains( $query, $this->token ) ) {
			return $query;
		}

		$quoted = "'" . $this->token . "'";
		$count  = substr_count( $query, $this->token );
		$whole  = $count === substr_count( $query, $quoted );

		if ( ! $this->sent && ! $this->foreign ) {
			if ( 1 === $count && $whole && $this->is_write( $query, $quoted ) ) {
				$this->sent = true;

				return str_replace( $quoted, '( CASE WHEN ' . $this->condition . ' THEN ' . self::literal( $this->kept ) . ' ELSE ' . ( null === $this->plain ? $this->column : $this->plain_literal() ) . ' END )', $query );
			}

			$this->foreign = true;

			if ( $this->log_post > 0 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent loss: the save writes its filtered content.
				error_log( sprintf( 'PerfLocale: the protected blocks of post %d were not saved because another plugin changed the database query.', $this->log_post ) );
			}
		}

		if ( null === $this->plain || ! $whole ) {
			return '';
		}

		return str_replace( $quoted, $this->plain_literal(), $query );
	}

	/**
	 * Whether a query carrying the token once, quoted, is the save's own write: same blog, posts table and hook
	 * stack, with the token as the whole value of the save's column in its post's UPDATE or its INSERT.
	 *
	 * @param string $query  SQL.
	 * @param string $quoted The token as a quoted literal.
	 * @return bool
	 */
	private function is_write( string $query, string $quoted ): bool {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$stack = $GLOBALS['wp_current_filter'] ?? null;

		if ( null === $this->stack || get_current_blog_id() !== $this->blog_id || $wpdb->posts !== $this->table || ! is_array( $stack ) || array_values( $stack ) !== $this->stack ) {
			return false;
		}

		$start  = (string) preg_replace( '#\A(?:\s+|/\*.*?\*/)*#s', '', $query );
		$table  = '`' . $this->table . '`';
		$column = '`' . $this->column . '` = ' . $quoted;
		$at     = strpos( $start, $quoted );

		if ( false === $at || 1 !== preg_match( '#\G(?:\s+|/\*.*?\*/)*(?:,|\)|WHERE\s)#s', $start, $m, 0, $at + strlen( $quoted ) ) ) {
			return false;
		}

		if ( $this->post_id > 0 ) {
			return str_starts_with( $start, 'UPDATE ' . $table . ' SET ' )
				&& ( str_contains( $start, ' SET ' . $column ) || str_contains( $start, ', ' . $column ) )
				&& self::where_id( $start ) === $this->post_id;
		}

		$head = 'INSERT INTO ' . $table . ' (';

		if ( ! str_starts_with( $start, $head ) ) {
			return false;
		}

		$values = strpos( $start, ') VALUES (', strlen( $head ) );

		if ( false === $values ) {
			return false;
		}

		$index = array_search( '`' . $this->column . '`', explode( ', ', substr( $start, strlen( $head ), $values - strlen( $head ) ) ), true );

		return false !== $index && self::value_index( $start, $values + strlen( ') VALUES (' ), $at ) === $index;
	}

	/**
	 * The post id of an UPDATE's `WHERE `ID` = <id>` tail (trailing blanks and comments aside), or 0.
	 *
	 * @param string $query SQL.
	 * @return int
	 */
	private static function where_id( string $query ): int {
		$tail = rtrim( $query );

		while ( str_ends_with( $tail, '*/' ) ) {
			$open = strrpos( $tail, '/*' );

			if ( false === $open ) {
				return 0;
			}

			$tail = rtrim( substr( $tail, 0, $open ) );
		}

		return 1 === preg_match( "#\sWHERE `ID` = '?([1-9][0-9]*)'?\z#", substr( $tail, -64 ), $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Index, in an INSERT's value list, of the top-level value that starts at $at, or null.
	 *
	 * Skips quoted literals, comments and parentheses; string functions only, linear in the bytes read.
	 *
	 * @param string $query SQL.
	 * @param int    $from  Offset right after "VALUES (".
	 * @param int    $at    Offset of the value.
	 * @return int|null
	 */
	private static function value_index( string $query, int $from, int $at ): ?int {
		$index = 0;
		$depth = 0;
		$fresh = true;

		while ( $from < $at ) {
			$char = $query[ $from ];

			if ( "'" === $char ) {
				$end = self::literal_end( $query, $from );

				if ( null === $end ) {
					return null;
				}

				$from  = $end;
				$fresh = false;
			} elseif ( str_starts_with( substr( $query, $from, 2 ), '/*' ) ) {
				$end = strpos( $query, '*/', $from + 2 );

				if ( false === $end ) {
					return null;
				}

				$from = $end + 2;
			} elseif ( '(' === $char || ')' === $char || ',' === $char ) {
				++$from;

				if ( '(' === $char ) {
					++$depth;
					$fresh = false;
				} elseif ( ')' === $char ) {
					if ( --$depth < 0 ) {
						return null;
					}
				} elseif ( 0 === $depth ) {
					++$index;
					$fresh = true;
				}
			} else {
				$blank = strspn( $query, " \t\r\n", $from );

				if ( $blank > 0 ) {
					$from += $blank;

					continue;
				}

				$from += max( 1, strcspn( $query, " \t\r\n'(),/", $from ) );
				$fresh = false;
			}
		}

		return $from === $at && 0 === $depth && $fresh ? $index : null;
	}

	/**
	 * Offset right after the quoted literal at $start (a quote after an even number of backslashes ends it), or null.
	 *
	 * @param string $query SQL.
	 * @param int    $start Offset of the opening quote.
	 * @return int|null
	 */
	private static function literal_end( string $query, int $start ): ?int {
		$from = $start + 1;

		while ( false !== ( $quote = strpos( $query, "'", $from ) ) ) {
			$slashes = 0;

			for ( $i = $quote - 1; $i > $start && '\\' === $query[ $i ]; $i-- ) {
				++$slashes;
			}

			if ( 0 === $slashes % 2 ) {
				return $quote + 1;
			}

			$from = $quote + 1;
		}

		return null;
	}

	/**
	 * The plain value as a quoted SQL literal.
	 *
	 * @return string
	 */
	private function plain_literal(): string {
		return $this->plain_literal ??= self::literal( (string) $this->plain );
	}
}
