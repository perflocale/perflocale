<?php
/**
 * Content the URL bases compiler checks values against.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Admin\Typed;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Direct indexed SELECTs (never the object cache): page paths, root-routed posts, categories and authors whose
 * address a base would take (3.10 rule 2), the shop page's translations and children (3.9), site paths (rule 5).
 *
 * @internal
 */
class Content {

	/**
	 * Parent levels walked for a page URI.
	 */
	private const MAX_DEPTH = 20;

	/**
	 * Posts named alike read per lookup; more means it cannot tell, which counts as taken.
	 */
	private const MAX_NAMED = 500;

	/**
	 * Post id => [ post_name, post_parent, post_status ] memo.
	 *
	 * @var array<int, array{0:string,1:int,2:string}>
	 */
	private array $pages = [];

	/**
	 * Table => language ids that have slug rows (lets the slug lookups use slug_lookup, which leads with language_id).
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $slug_languages = [];

	/**
	 * A lookup failed: the answers of this instance prove nothing (the compile reports `read_failed`).
	 *
	 * @var bool
	 */
	public bool $failed = false;

	/**
	 * The path of a published page whose address starts with the value (DB or display slug per level), or null.
	 *
	 * @param string $value Value in norm form.
	 * @return string|null
	 */
	public function page_with_prefix( string $value ): ?string {
		$segs    = explode( '/', $value );
		$parents = [ 0 ];
		$path    = [];

		foreach ( $segs as $seg ) {
			$ids = $this->children_named( $parents, $seg, 'page' );

			if ( [] === $ids ) {
				return null;
			}

			// Too many pages of this name to walk: the address counts as taken.
			if ( count( $ids ) > self::MAX_NAMED ) {
				return $value;
			}

			$path[]  = $seg;
			$parents = $ids;
		}

		return implode( '/', $path );
	}

	/**
	 * Whether core's verbose page check (class-wp.php:242-256) passes for a path: get_page_by_path() on DB slugs (page
	 * or attachment, any status on the way), then the found post's status flags; an unregistered status counts.
	 *
	 * @param string $path Path in norm form.
	 * @return bool
	 */
	public function core_page( string $path ): bool {
		$wpdb  = Typed::wpdb();
		$parts = explode( '/', trim( $path, '/' ) );
		$in    = implode( ',', array_fill( 0, count( $parts ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Compile input; $in holds %s placeholders only.
		$rows  = $this->seen( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type, post_status FROM %i WHERE post_name IN ({$in}) AND post_type IN ('page','attachment')", $wpdb->posts, ...$parts ), ARRAY_A ) );
		$pages = [];
		$rev   = array_reverse( $parts );
		$found = null;

		foreach ( Typed::rows( $rows ) as $row ) {
			$pages[ Typed::int( $row['ID'] ?? null ) ] = [ Typed::str( $row['post_name'] ?? null ), Typed::int( $row['post_parent'] ?? null ), Typed::str( $row['post_type'] ?? null ), Typed::str( $row['post_status'] ?? null ) ];
		}

		// Core's ancestry walk: the last part's post whose parents name the parts before it, a page before an attachment.
		foreach ( $pages as $page ) {
			if ( $page[0] !== $rev[0] ) {
				continue;
			}

			$p     = $page;
			$count = 0;

			while ( 0 !== $p[1] && isset( $pages[ $p[1] ] ) ) {
				$parent = $pages[ $p[1] ];

				if ( ! isset( $rev[ ++$count ] ) || $parent[0] !== $rev[ $count ] ) {
					break;
				}

				$p = $parent;
			}

			if ( 0 === $p[1] && count( $rev ) === $count + 1 && $p[0] === $rev[ $count ] ) {
				$found = $page;

				if ( 'page' === $page[2] ) {
					break;
				}
			}
		}

		$status = null !== $found ? get_post_status_object( $found[3] ) : null;

		return null !== $found && ( null === $status || $status->public || $status->protected || $status->private || ! $status->exclude_from_search );
	}

	/**
	 * Whether a published post of the type has the slug (DB or display slug).
	 *
	 * @param string $type Post type.
	 * @param string $seg  Slug in norm form.
	 * @return bool
	 */
	public function post_named( string $type, string $seg ): bool {
		return [] !== $this->children_named( null, $seg, $type );
	}

	/**
	 * Whether a post of the type (DB or display slug) has a slug `$tail` matches as `/<slug>`; true when it cannot tell.
	 *
	 * Candidates by the tail's literal head (`comment-page-`) or, for a numbered tail, by a leading digit; at most 500. A tail
	 * with an alternative cannot pick them.
	 *
	 * @param string $type Post type.
	 * @param string $tail Regex tail of a page rule (map pgs).
	 * @return bool
	 */
	public function post_matching( string $type, string $tail ): bool {
		if ( str_contains( $tail, '|' ) ) {
			return true;
		}

		$wpdb = Typed::wpdb();
		$head = Rules::literal_prefix( $tail );

		// A leading group gives the head only when it is required: (?:/abc)? also matches without /abc.
		if ( str_starts_with( $tail, '(?:' ) ) {
			$end  = self::group_end( $tail );
			$head = null !== $end && ! in_array( $tail[ $end + 1 ] ?? '', [ '?', '*', '{' ], true ) ? Rules::literal_prefix( substr( $tail, 3 ) ) : '';
		}

		$st    = Schema::table( 'slug_translations' );
		$langs = $this->slug_languages( $st );
		$in    = $this->language_in( $langs );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Compile input, past any cache; $in holds %d placeholders only.
		if ( strlen( $head ) > 1 && '/' === $head[0] ) {
			$like  = $wpdb->esc_like( substr( $head, 1 ) ) . '%';
			$names = array_merge(
				Typed::strs( $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT post_name FROM %i WHERE post_type = %s AND post_status = 'publish' AND post_name LIKE %s LIMIT 501", $wpdb->posts, $type, $like ) ) ) ),
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in holds only %d placeholders (language_in()).
				[] === $langs ? [] : Typed::strs( $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT slug FROM %i WHERE {$in}object_type = 'post' AND object_subtype = %s AND slug LIKE %s LIMIT 501", $st, ...array_merge( $langs ?? [], [ $type, $like ] ) ) ) ) )
			);
		} elseif ( 1 === @preg_match( '#^' . $tail . '#', '/2' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed plugin rule counts as a match.
			// Slugs that start with a digit; LIKE, not a range: Unicode collations sort ":" before the digits.
			$digits = [ '0%', '1%', '2%', '3%', '4%', '5%', '6%', '7%', '8%', '9%' ];
			$names  = array_merge(
				Typed::strs( $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT post_name FROM %i WHERE post_type = %s AND post_status = 'publish' AND ( post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s ) LIMIT 501", $wpdb->posts, $type, ...$digits ) ) ) ),
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in holds only %d placeholders (language_in()).
				[] === $langs ? [] : Typed::strs( $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT slug FROM %i WHERE {$in}object_type = 'post' AND object_subtype = %s AND ( slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s ) LIMIT 501", $st, ...array_merge( $langs ?? [], [ $type ], $digits ) ) ) ) )
			);
		} else {
			return true;
		}
		// phpcs:enable

		if ( count( $names ) > 500 ) {
			return true;
		}

		foreach ( $names as $name ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.
			if ( 0 !== @preg_match( '#^' . $tail . '#', '/' . $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Offset of the `)` that closes the group opening at offset 0, past escapes and classes; null when unclosed.
	 *
	 * @param string $re Regex.
	 * @return int|null
	 */
	private static function group_end( string $re ): ?int {
		$depth = 0;
		$n     = strlen( $re );

		for ( $i = 0; $i < $n; $i++ ) {
			$c = $re[ $i ];

			if ( '\\' === $c ) {
				++$i;
			} elseif ( '[' === $c ) {
				// A class ends at the first unescaped ] after its optional ^ and a leading literal ].
				$i += '^' === ( $re[ $i + 1 ] ?? '' ) ? 2 : 1;
				$i += ']' === ( $re[ $i ] ?? '' ) ? 1 : 0;

				while ( $i < $n && ']' !== $re[ $i ] ) {
					$i += '\\' === $re[ $i ] ? 2 : 1;
				}
			} elseif ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c && 0 === --$depth ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * A published post (permalinks starting with %postname%) whose slug is the segment, or null.
	 *
	 * @param string $seg Segment.
	 * @return string|null
	 */
	public function root_post( string $seg ): ?string {
		return [] !== $this->children_named( null, $seg, 'post' ) ? $seg : null;
	}

	/**
	 * A term of the taxonomy whose slug (DB or display, any language) is the segment, or null.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $seg      Segment.
	 * @return string|null
	 */
	public function term( string $taxonomy, string $seg ): ?string {
		$wpdb = Typed::wpdb();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compile input, past any cache.
		$hit = $this->seen( $wpdb->get_var( $wpdb->prepare( 'SELECT t.term_id FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id AND tt.taxonomy = %s WHERE t.slug = %s LIMIT 1', $wpdb->terms, $wpdb->term_taxonomy, $taxonomy, $seg ) ) );

		if ( null === $hit ) {
			$st    = Schema::table( 'slug_translations' );
			$langs = $this->slug_languages( $st );
			$in    = $this->language_in( $langs );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in holds %d placeholders only.
			$hit = [] === $langs ? null : $this->seen( $wpdb->get_var( $wpdb->prepare( "SELECT object_id FROM %i WHERE {$in}object_type = 'term' AND object_subtype = %s AND slug = %s LIMIT 1", $st, ...array_merge( $langs ?? [], [ $taxonomy, $seg ] ) ) ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $hit ? $seg : null;
	}

	/**
	 * A user whose nicename is the segment, or null.
	 *
	 * @param string $seg Segment.
	 * @return string|null
	 */
	public function author( string $seg ): ?string {
		$wpdb = Typed::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compile input, past any cache.
		$hit = $this->seen( $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE user_nicename = %s LIMIT 1', $wpdb->users, $seg ) ) );

		return null !== $hit ? $seg : null;
	}

	/**
	 * Published posts of a type named `$seg` (DB slug or display slug), under one of the parents when given.
	 *
	 * @param array<int, int>|null $parents Parent ids, or null for any.
	 * @param string               $seg     Slug in norm form.
	 * @param string               $type    Post type.
	 * @return array<int, int>
	 */
	private function children_named( ?array $parents, string $seg, string $type ): array {
		$wpdb = Typed::wpdb();

		$where = '';
		$args  = [ $wpdb->posts, $type, $seg ];

		if ( null !== $parents ) {
			$where = ' AND post_parent IN (' . implode( ',', array_fill( 0, count( $parents ), '%d' ) ) . ')';
			$args  = array_merge( $args, $parents );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Compile input; $where and $in hold only %d placeholders.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders.
		$ids = $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status = 'publish' AND post_name = %s{$where} LIMIT %d", ...array_merge( $args, [ self::MAX_NAMED + 1 ] ) ) ) );

		// The display read takes no LIMIT: slug_lookup is unique (one row per language); a cut before the filters depends on the plan.
		$st      = Schema::table( 'slug_translations' );
		$langs   = $this->slug_languages( $st );
		$in      = $this->language_in( $langs );
		$display = [] === $langs ? [] : $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM %i WHERE {$in}object_type = 'post' AND object_subtype = %s AND slug = %s", $st, ...array_merge( $langs ?? [], [ $type, $seg ] ) ) ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in holds only %d placeholders (language_in()).
		// phpcs:enable

		$ids = Typed::ints( $ids );

		$display = Typed::ints( $display );
		$this->prime( $display );

		foreach ( $display as $id ) {
			$row = $this->page_row( $id );

			if ( null !== $row && ( null === $parents || in_array( $row[1], $parents, true ) ) && 'publish' === $row[2] ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Every language id with slug rows in the table, read once; a lookup names all of them to match in any language.
	 *
	 * A failed read is not kept: null, so the lookup sends no language condition.
	 *
	 * @param string $st Table.
	 * @return array<int, int>|null
	 */
	private function slug_languages( string $st ): ?array {
		if ( isset( $this->slug_languages[ $st ] ) ) {
			return $this->slug_languages[ $st ];
		}

		$wpdb = Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compile input, past any cache.
		$ids = $this->seen( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT language_id FROM %i', $st ) ) );

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$this->slug_languages[ $st ] = Typed::ints( $ids );

		return $this->slug_languages[ $st ];
	}

	/**
	 * `language_id IN (%d, ...) AND ` for the ids; '' for null (not read).
	 *
	 * @param array<int, int>|null $langs Language ids.
	 * @return literal-string
	 */
	private function language_in( ?array $langs ): string {
		return null === $langs ? '' : 'language_id IN (' . implode( ',', array_fill( 0, count( $langs ), '%d' ) ) . ') AND ';
	}

	/**
	 * Fill the post memo for ids not in it, in one read.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return void
	 */
	private function prime( array $ids ): void {
		$ids = array_values( array_diff( array_unique( $ids ), array_keys( $this->pages ) ) );

		if ( [] === $ids ) {
			return;
		}

		$wpdb = Typed::wpdb();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Compile input, past any cache; %d placeholders only.
			$rows = $this->seen( $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_name, post_parent, post_status FROM %i WHERE ID IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( [ $wpdb->posts ], $chunk ) ), ARRAY_N ) );

			foreach ( (array) $rows as $row ) {
				$this->pages[ Typed::int( $row[0] ?? null ) ] = [ Typed::str( $row[1] ?? null ), Typed::int( $row[2] ?? null ), Typed::str( $row[3] ?? null ) ];
			}
		}
	}

	/**
	 * [ post_name, post_parent, post_status ] of a post, read directly.
	 *
	 * @param int $id Post id.
	 * @return array{0:string,1:int,2:string}|null
	 */
	private function page_row( int $id ): ?array {
		$wpdb = Typed::wpdb();

		if ( ! isset( $this->pages[ $id ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compile input, past any cache.
			$row = $this->seen( $wpdb->get_row( $wpdb->prepare( 'SELECT post_name, post_parent, post_status FROM %i WHERE ID = %d', $wpdb->posts, $id ), ARRAY_N ) );

			if ( ! is_array( $row ) ) {
				return null;
			}

			$this->pages[ $id ] = [ Typed::str( $row[0] ?? null ), Typed::int( $row[1] ?? null ), Typed::str( $row[2] ?? null ) ];
		}

		return $this->pages[ $id ];
	}

	/**
	 * Whether a post is published (a draft, private, scheduled or trashed Shop translation has no public address).
	 *
	 * @param int $id Post id.
	 * @return bool
	 */
	public function published( int $id ): bool {
		return 'publish' === ( $this->page_row( $id )[2] ?? '' );
	}

	/**
	 * A page's URI from DB slugs, the page's own segment replaced by its display slug in a language when one exists.
	 *
	 * @param int $id          Page id.
	 * @param int $language_id Language id for the display slug, 0 for none.
	 * @return string|null Norm form.
	 */
	public function page_uri( int $id, int $language_id = 0 ): ?string {
		$segs = [];
		$cur  = $id;

		for ( $depth = 0; $cur > 0 && $depth < self::MAX_DEPTH; $depth++ ) {
			$row = $this->page_row( $cur );

			if ( null === $row || '' === $row[0] ) {
				return null;
			}

			array_unshift( $segs, $row[0] );
			$cur = $row[1];
		}

		if ( $language_id > 0 && [] !== $segs ) {
			$display = $this->display_slug( $id, $language_id );

			if ( null !== $display ) {
				$segs[ count( $segs ) - 1 ] = $display;
			}
		}

		$uri = Validator::norm_path( implode( '/', $segs ) );

		return '' === $uri ? null : $uri;
	}

	/**
	 * A post's display slug in a language, or null.
	 *
	 * @param int $id          Post id.
	 * @param int $language_id Language id.
	 * @return string|null
	 */
	private function display_slug( int $id, int $language_id ): ?string {
		$wpdb = Typed::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compile input, past any cache.
		$slug = $this->seen( $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM %i WHERE object_type = 'post' AND object_id = %d AND language_id = %d LIMIT 1", Schema::table( 'slug_translations' ), $id, $language_id ) ) );

		return is_string( $slug ) && '' !== $slug ? $slug : null;
	}

	/**
	 * Translations of a post: language id => post id (the post itself included).
	 *
	 * @param int $id Post id.
	 * @return array<int, int>
	 */
	public function translations( int $id ): array {
		$wpdb = Typed::wpdb();

		$links = Schema::table( 'translation_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compile input, past any cache.
		$rows = $this->seen( $wpdb->get_results( $wpdb->prepare( "SELECT b.language_id, b.object_id FROM %i a INNER JOIN %i b ON b.group_id = a.group_id AND b.type = a.type WHERE a.type = 'post' AND a.object_id = %d", $links, $links, $id ), ARRAY_N ) );

		$out = [];

		foreach ( Typed::arr( $rows ) as $row ) {
			$row                                  = Typed::arr( $row );
			$out[ Typed::int( $row[0] ?? null ) ] = Typed::int( $row[1] ?? null );
		}

		return $out;
	}

	/**
	 * Published or private descendants of a page as URIs relative to it, at most `$cap + 1` (caller checks the cap).
	 *
	 * @param int $id          Page id.
	 * @param int $language_id Language id for display slugs, 0 for none.
	 * @param int $cap         Cap.
	 * @return array<int, string>
	 */
	public function descendants( int $id, int $language_id, int $cap ): array {
		$wpdb = Typed::wpdb();

		$base  = $this->page_uri( $id, $language_id );
		$out   = [];
		$level = [ $id ];
		$depth = 0;

		$found = 0;

		while ( [] !== $level && $found <= $cap && $depth++ < self::MAX_DEPTH ) {
			$in = implode( ',', array_fill( 0, count( $level ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Compile input; $in holds %d placeholders only.
			$ids = $this->seen( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'page' AND post_status IN ('publish','private') AND post_parent IN ({$in}) LIMIT %d", $wpdb->posts, ...array_merge( $level, [ $cap + 1 ] ) ) ) );

			$level = Typed::ints( $ids );

			foreach ( $level as $child ) {
				$uri = $this->page_uri( $child, $language_id );

				if ( null !== $uri && null !== $base && str_starts_with( $uri, $base . '/' ) ) {
					$out[] = substr( $uri, strlen( $base ) + 1 );
					++$found;
				}
			}
		}

		return array_slice( $out, 0, $cap + 1 );
	}

	/**
	 * A lookup's result, noting a failed query in `$failed`.
	 *
	 * @template T
	 * @param T $result Query result.
	 * @return T
	 */
	private function seen( mixed $result ): mixed {
		global $wpdb;

		if ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
			$this->failed = true;
		}

		return $result;
	}

	/**
	 * Whether a path of this site selects another site before plugins load (subdirectory multisite only), with this
	 * site's domain and the segment count ms_load_current_site_and_network() uses.
	 *
	 * @param string $path Path starting and ending with "/".
	 * @return string|null The other site's path, or null.
	 */
	public function other_site( string $path ): ?string {
		if ( ! is_multisite() || is_subdomain_install() ) {
			return null;
		}

		$network = get_network();
		$blog    = get_site();

		if ( ! $network instanceof \WP_Network || ! $blog instanceof \WP_Site ) {
			return null;
		}

		$net_domain = $network->domain;
		$net_path   = $network->path;

		if ( defined( 'DOMAIN_CURRENT_SITE' ) && defined( 'PATH_CURRENT_SITE' ) ) {
			$net_domain = Typed::str( constant( 'DOMAIN_CURRENT_SITE' ) );
			$net_path   = Typed::str( constant( 'PATH_CURRENT_SITE' ) );
			$segments   = '/' !== $net_path && 0 === strcasecmp( $net_domain, $blog->domain ) && 0 === stripos( $path, $net_path ) ? 1 + count( explode( '/', trim( $net_path, '/' ) ) ) : 1;
		} else {
			$segments = substr_count( $net_path, '/' );
		}

		$site = $this->seen( get_site_by_path( $blog->domain, $path, $segments ) );

		if ( $site instanceof \WP_Site && (int) $site->blog_id !== (int) $blog->blog_id ) {
			return $site->path;
		}

		return null;
	}
}
