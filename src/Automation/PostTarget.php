<?php
/**
 * Automation target: post translations.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\MachineTranslation\ExpectedWrite;
use PerfLocale\Translation\ContentChangeDetector;
use PerfLocale\Translation\Fields\PostMetaStorage;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and restores one post translation for the automation service.
 *
 * Undo acts only while the translation still has the state (content hash, status, modified GMT) the change
 * left, so a later edit by anyone is never undone. Undo of a change that created the translation trashes it.
 *
 * @phpstan-import-type ChangeRow from \PerfLocale\Database\Repository\PendingChangeRepository
 * @phpstan-type LiveRead array{post: \WP_Post, hash: string, status: string, modified: string, fields: array<string, string|null>, digest: string}
 * @phpstan-type RowRead array{digest: string, trash: string, post_name: string}
 * @phpstan-type CasState array{moved: bool, landed: bool, unsure: bool, taken: bool}
 */
final class PostTarget {

	/**
	 * SQL digest of a posts row: MD5 over the MD5 of each column core's post UPDATE writes, via COALESCE.
	 * The database computes both sides of the compare-and-set, so charsets never enter it.
	 */
	public const ROW_DIGEST_SQL = 'MD5(CONCAT('
		. "MD5(COALESCE(`post_author`, '')), "
		. "MD5(COALESCE(`post_date`, '')), "
		. "MD5(COALESCE(`post_date_gmt`, '')), "
		. "MD5(COALESCE(`post_content`, '')), "
		. "MD5(COALESCE(`post_content_filtered`, '')), "
		. "MD5(COALESCE(`post_title`, '')), "
		. "MD5(COALESCE(`post_excerpt`, '')), "
		. "MD5(COALESCE(`post_status`, '')), "
		. "MD5(COALESCE(`post_type`, '')), "
		. "MD5(COALESCE(`comment_status`, '')), "
		. "MD5(COALESCE(`ping_status`, '')), "
		. "MD5(COALESCE(`post_password`, '')), "
		. "MD5(COALESCE(`post_name`, '')), "
		. "MD5(COALESCE(`to_ping`, '')), "
		. "MD5(COALESCE(`pinged`, '')), "
		. "MD5(COALESCE(`post_modified`, '')), "
		. "MD5(COALESCE(`post_modified_gmt`, '')), "
		. "MD5(COALESCE(`post_parent`, '')), "
		. "MD5(COALESCE(`menu_order`, '')), "
		. "MD5(COALESCE(`post_mime_type`, '')), "
		. "MD5(COALESCE(`guid`, ''))"
		. '))';

	/**
	 * The digest without `post_name`, for a move to the Trash: core renames the slug in its own statement
	 * first. The slug is compared earlier, on `pre_trash_post` (trash_guard()).
	 */
	public const ROW_DIGEST_SQL_TRASH = 'MD5(CONCAT('
		. "MD5(COALESCE(`post_author`, '')), "
		. "MD5(COALESCE(`post_date`, '')), "
		. "MD5(COALESCE(`post_date_gmt`, '')), "
		. "MD5(COALESCE(`post_content`, '')), "
		. "MD5(COALESCE(`post_content_filtered`, '')), "
		. "MD5(COALESCE(`post_title`, '')), "
		. "MD5(COALESCE(`post_excerpt`, '')), "
		. "MD5(COALESCE(`post_status`, '')), "
		. "MD5(COALESCE(`post_type`, '')), "
		. "MD5(COALESCE(`comment_status`, '')), "
		. "MD5(COALESCE(`ping_status`, '')), "
		. "MD5(COALESCE(`post_password`, '')), "
		. "MD5(COALESCE(`to_ping`, '')), "
		. "MD5(COALESCE(`pinged`, '')), "
		. "MD5(COALESCE(`post_modified`, '')), "
		. "MD5(COALESCE(`post_modified_gmt`, '')), "
		. "MD5(COALESCE(`post_parent`, '')), "
		. "MD5(COALESCE(`menu_order`, '')), "
		. "MD5(COALESCE(`post_mime_type`, '')), "
		. "MD5(COALESCE(`guid`, ''))"
		. '))';

	/** The state of cas() before any UPDATE. */
	private const CAS = [
		'moved'  => false,
		'landed' => false,
		'unsure' => false,
		'taken'  => false,
	];

	/** The meta keys core adds to a post it trashes before the move's UPDATE. */
	private const TRASH_META_KEYS = [ '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug' ];

	/** A write refused because its row changed after the read (no match): nothing written, no save hook ran. */
	public const ROW_MOVED = 'row_moved';

	/** A write refused because its row digest could not be read or its UPDATE bound: nothing was written. */
	public const ROW_UNREADABLE = 'row_unreadable';

	/** A write that may have landed without its compare-and-set holding: never read as "nothing written". */
	public const ROW_UNCONFIRMED = 'row_unconfirmed';

	/**
	 * The digest of a post's row as the database holds it now. A writer reads it right before its locked read;
	 * its post UPDATE then lands only on a row that still has it.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $trash   The digest of a move to the Trash (ROW_DIGEST_SQL_TRASH).
	 * @return string|null Null when it cannot be read (no row, a database error, an answer that is not 32 hex digits).
	 */
	public static function row_digest( int $post_id, bool $trash = false ): ?string {
		global $wpdb;

		if ( $post_id <= 0 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The digest must come from the table, never a cache; both expressions are constants of fixed column names.
		$digest = $trash
			? $wpdb->get_var( $wpdb->prepare( 'SELECT ' . self::ROW_DIGEST_SQL_TRASH . ' FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT ' . self::ROW_DIGEST_SQL . ' FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_string( $digest ) && self::digest_valid( $digest ) ? $digest : null;
	}

	/**
	 * What an undo reads of a post's row before its own read, in one statement: both digests and the slug.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, string>|null Null when it cannot be read (no row, a database error, a digest that is not 32 hex digits).
	 * @phpstan-return RowRead|null
	 */
	public static function row_read( int $post_id ): ?array {
		global $wpdb;

		if ( $post_id <= 0 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The digests must come from the table, never a cache; both expressions are constants of fixed column names.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::ROW_DIGEST_SQL . ' AS d, ' . self::ROW_DIGEST_SQL_TRASH . ' AS t, post_name AS n FROM %i WHERE ID = %d', $wpdb->posts, $post_id ), ARRAY_A );

		if ( ! is_array( $row ) || ! self::digest_valid( $row['d'] ?? null ) || ! self::digest_valid( $row['t'] ?? null ) || ! is_string( $row['n'] ?? null ) ) {
			return null;
		}

		return [
			'digest'    => Scalar::str( $row['d'] ),
			'trash'     => Scalar::str( $row['t'] ),
			'post_name' => $row['n'],
		];
	}

	/**
	 * Whether a put-back may write `$slug` back over the slug a write's own save left: only while the live slug is still
	 * that one and core keeps `$slug` for this post (no other post of its language took it meanwhile).
	 *
	 * @param int    $post_id Post id.
	 * @param mixed  $slug    The slug to restore.
	 * @param string $own     The slug the write's own save left ('' none).
	 * @param mixed  $live    The live slug, read in one statement with the put-back's row digest (row_read()).
	 * @return bool
	 */
	public static function slug_restorable( int $post_id, mixed $slug, string $own, mixed $live ): bool {
		if ( ! is_string( $slug ) || '' === $slug || '' === $own || $live !== $own ) {
			return false;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post && wp_unique_post_slug( $slug, $post_id, $post->post_status, $post->post_type, $post->post_parent ) === $slug;
	}

	/**
	 * Whether a value is a row digest (32 lower-case hex digits).
	 *
	 * @param mixed $digest Value.
	 * @return bool
	 */
	public static function digest_valid( mixed $digest ): bool {
		return is_string( $digest ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $digest );
	}

	/**
	 * Run a save of one post whose core UPDATE lands only while the row still has `$digest` (cas()); no match sets
	 * `$moved` and wp_insert_post() fails before any save hook. `$save` calls core's save directly, not from a hook.
	 *
	 * @param int      $post_id Post id.
	 * @param string   $digest  The digest read before the save's read.
	 * @param callable $save    The save (wp_update_post(), wp_trash_post()).
	 * @param bool     $moved   Set when the row changed and nothing was written.
	 * @param bool     $trash   Whether the save moves the post to the Trash (`$digest` is then row_digest( id, true )).
	 * @param string   $why     Set to ROW_UNREADABLE (refused, nothing written) or ROW_UNCONFIRMED (a WP_Error is returned).
	 * @return mixed The save's return value.
	 */
	public static function guarded_save( int $post_id, string $digest, callable $save, bool &$moved, bool $trash = false, string &$why = '' ): mixed {
		$refused = '';
		$cas     = self::CAS;
		$remove  = self::cas( $post_id, $digest, $trash, $refused, $cas );
		$unpin   = $trash ? null : self::pin( $post_id );

		try {
			$result = $save();
		} finally {
			$remove();

			if ( null !== $unpin ) {
				$unpin();
			}
		}

		$moved = $cas['moved'];
		$why   = self::unconfirmed( $post_id, $cas, $refused, ! is_wp_error( $result ) && false !== $result && null !== $result && 0 !== $result ) ? self::ROW_UNCONFIRMED : $refused;

		return self::ROW_UNCONFIRMED === $why ? new \WP_Error( self::ROW_UNCONFIRMED, __( 'The update of the translation could not be confirmed.', 'perflocale' ) ) : $result;
	}

	/**
	 * The slug pin (MachineTranslation\SlugPin, out of the classmap) for the save started next.
	 *
	 * @param int $post_id Post id.
	 * @return \Closure(): void Removes it.
	 */
	private static function pin( int $post_id ): \Closure {
		require_once __DIR__ . '/../MachineTranslation/SlugPin.php';

		return \PerfLocale\MachineTranslation\SlugPin::keep( $post_id );
	}

	/**
	 * Whether a save may have written without its compare-and-set holding: an UPDATE is unsure (one landed before it, or a
	 * later filter changed it), or a save that answered success never sent its own UPDATE through the filter (logged).
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string, bool> $cas     cas()'s outcome.
	 * @phpstan-param CasState $cas
	 * @param string              $refused The refusal reason.
	 * @param bool                $saved   Whether the save answered success.
	 * @return bool
	 */
	private static function unconfirmed( int $post_id, array $cas, string $refused, bool $saved ): bool {
		if ( $cas['unsure'] ) {
			return true;
		}

		if ( ! $saved || '' !== $refused || $cas['moved'] || $cas['taken'] ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: a save the compare-and-set never saw is answered as failed.
		error_log( sprintf( 'PerfLocale: the update of post %d was not checked against newer edits because another plugin changed how it is saved; it is treated as failed.', $post_id ) );

		return true;
	}

	/**
	 * Make the posts UPDATE of the save started next, and of each save a hook starts before it, a compare-and-set over
	 * `$digest`; after one of them changed the row the rest are refused. An UPDATE is the first write at its
	 * pre_post_update's hook level (a hook's query runs deeper); saves after the call's own UPDATE go through as in core.
	 *
	 * @param int                 $post_id   Post id.
	 * @param string|null         $digest    The digest the row must still have; null: refusal only.
	 * @param bool                $trash     The digest is ROW_DIGEST_SQL_TRASH's.
	 * @param string              $refused   A refusal reason, read when an UPDATE comes; set to ROW_UNREADABLE for an UPDATE the filter cannot bind.
	 * @param array<string, bool> $cas       Set to: moved (nothing landed, the row changed), landed (an UPDATE changed the row), unsure (one may have landed unchecked), taken (the call's own UPDATE was taken).
	 * @phpstan-param CasState $cas
	 * @phpstan-param-out CasState $cas
	 * @param callable|null       $at_update Called once, on the first pre_post_update; '' or a refusal reason.
	 * @return \Closure(): void Removes the hooks.
	 */
	private static function cas( int $post_id, ?string $digest, bool $trash, string &$refused, array &$cas, ?callable $at_update = null ): \Closure {
		ExpectedWrite::read_charset();

		// A save hook that switches blog and saves the same id there is another post.
		$blog    = get_current_blog_id();
		$own     = ExpectedWrite::level( 'pre_post_update' );
		$expect  = $digest;
		$first   = true;
		$done    = false;
		$pending = [];
		$cas     = self::CAS;
		$arrive  = static function ( $id ) use ( $post_id, $blog, $own, $digest, $trash, $at_update, &$expect, &$first, &$done, &$pending, &$refused, &$cas ): void {
			if ( $done || Scalar::int( $id ) !== $post_id || get_current_blog_id() !== $blog ) {
				return;
			}

			$mine = ExpectedWrite::at( $own );
			$done = $mine;

			if ( $first && null !== $at_update ) {
				$reason  = Scalar::str( $at_update() );
				$refused = '' === $refused ? $reason : $refused;
			}

			$first = false;

			if ( null !== $digest || '' !== $refused ) {
				$filter    = self::update_filter( $post_id, array_merge( array_slice( ExpectedWrite::level(), 0, -2 ), [ 'query' ] ), $mine, $trash, $expect, $refused, $cas );
				$pending[] = $filter;
				add_filter( 'query', $filter, PHP_INT_MAX );
			}
		};

		add_action( 'pre_post_update', $arrive, PHP_INT_MAX, 1 );

		return static function () use ( $arrive, &$pending ): void {
			remove_action( 'pre_post_update', $arrive, PHP_INT_MAX );

			foreach ( $pending as $filter ) {
				remove_filter( 'query', $filter, PHP_INT_MAX );
			}

			$pending = [];
		};
	}

	/**
	 * The one-shot `query` filter of one save's UPDATE: runs it with `AND <digest> = '$expect'` itself. On a match (or
	 * an unchanged row still at `$expect`) core gets its statement with `AND 0`; otherwise '' (wpdb answers false, no
	 * save hook runs). A statement it cannot bind outside literals and comments is refused and logged.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<int, mixed>   $level   Hook stack of the save's UPDATE.
	 * @param bool                $mine    The call's own save.
	 * @param bool                $trash   The digest is ROW_DIGEST_SQL_TRASH's.
	 * @param string|null         $expect  The digest the row must have; set to null by a landed write.
	 * @param string              $refused See cas().
	 * @param array<string, bool> $cas     See cas().
	 * @phpstan-param CasState $cas
	 * @phpstan-param-out CasState $cas
	 * @return \Closure(mixed): mixed
	 */
	private static function update_filter( int $post_id, array $level, bool $mine, bool $trash, ?string &$expect, string &$refused, array &$cas ): \Closure {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$head   = 'UPDATE `' . $wpdb->posts . '` SET ';
		$tails  = [ ' WHERE `ID` = ' . $post_id, " WHERE `ID` = '" . $post_id . "'" ];
		$filter = null;
		$filter = static function ( $query ) use ( $post_id, $level, $mine, $trash, $head, $tails, &$expect, &$refused, &$cas, &$filter ) {
			/**
			 * WordPress database.
			 *
			 * @var \wpdb $wpdb
			 */
			global $wpdb;

			if ( ! is_string( $query ) || '' === $query || ! ExpectedWrite::at( $level ) || ! ExpectedWrite::writes( $query ) ) {
				return $query;
			}

			remove_filter( 'query', $filter, PHP_INT_MAX );
			$cas['taken'] = $cas['taken'] || $mine;

			if ( '' !== $refused || $cas['moved'] || $cas['unsure'] || null === $expect ) {
				$cas['unsure'] = $cas['unsure'] || ( $cas['landed'] && null === $expect );

				return '';
			}

			// Core's own statement needs no scan (any charset); another shape is bound outside literals and comments.
			$exact = str_starts_with( $query, $head ) && str_ends_with( $query, $tails[0] );
			$with  = static fn( string $condition ): ?string => $exact ? $query . ' AND ' . $condition : ExpectedWrite::bind( $query, $head, $tails, $condition );
			$check = $with( ( $trash ? self::ROW_DIGEST_SQL_TRASH : self::ROW_DIGEST_SQL ) . " = '" . $expect . "'" );

			if ( null === $check ) {
				ExpectedWrite::refused( sprintf( 'the update of post %d', $post_id ) );
				$refused = self::ROW_UNREADABLE;

				return '';
			}

			// Added last, so it sees the statement as sent: rows changed only count for that one.
			$sent = null;
			$seen = static function ( $q ) use ( &$sent ) {
				$sent = $q;

				return $q;
			};
			add_filter( 'query', $seen, PHP_INT_MAX );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core's own prepared UPDATE with a fixed expression and a validated 32-hex digest.
			$changed = $wpdb->query( $check );
			remove_filter( 'query', $seen, PHP_INT_MAX );

			if ( false === $changed ) {
				return '';
			}

			// No read can tell the row this wrote from a save landing right after it: a later UPDATE of the call is refused.
			if ( $sent === $check && $changed > 0 ) {
				$expect        = null;
				$cas['landed'] = true;

				return (string) $with( '0' );
			}

			if ( self::row_digest( $post_id, $trash ) === $expect ) {
				return (string) $with( '0' );
			}

			if ( $sent === $check ) {
				$cas['moved'] = true;
			} else {
				$cas['unsure'] = true;
			}

			return '';
		};

		return $filter;
	}

	/**
	 * The state of a post: hash, status, modified time (GMT).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{hash: string, status: string, modified: string}
	 */
	public static function state_of( \WP_Post $post ): array {
		return [
			'hash'     => ContentChangeDetector::post_hash( $post ),
			'status'   => $post->post_status,
			'modified' => $post->post_modified_gmt,
		];
	}

	/**
	 * The live state of a post, read fresh from the database.
	 *
	 * @param int $post_id Post id.
	 * @return array{hash: string, status: string, modified: string}|null Null when the post does not exist.
	 */
	public static function state( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}

		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? self::state_of( $post ) : null;
	}

	/**
	 * The fields an undo of a stub fill writes back.
	 *
	 * @param \WP_Post $post The translation before the automatic write.
	 * @return array{post_title: string, post_content: string, post_excerpt: string, post_status: string}
	 */
	public static function previous_of( \WP_Post $post ): array {
		return [
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
		];
	}

	/**
	 * Whether undoing this change trashes the translation (the change created it).
	 *
	 * @param array<string, mixed> $row Change row.
	 * @return bool
	 */
	public static function undo_trashes( array $row ): bool {
		$raw = \PerfLocale\Util\Scalar::str( $row['previous'] ?? '' );

		// A list row carries only whether previous is NULL. A stored value, whatever its shape, never means the Trash.
		if ( '' === $raw ) {
			return 1 === \PerfLocale\Util\Scalar::int( $row['previous_null'] ?? 1 );
		}

		return false;
	}

	/**
	 * Whether the translation holds what undo() leaves: the restored text and status, or no live translation.
	 *
	 * @param array<string, int|string> $row Change row.
	 * @phpstan-param ChangeRow $row
	 * @return bool
	 */
	public static function holds_undo( array $row ): bool {
		$read = self::read_checked( $row['object_id'], [] );

		if ( false === $read ) {
			return false;
		}

		if ( self::undo_trashes( $row ) ) {
			return null === $read || 'trash' === $read['status'];
		}

		$previous = \PerfLocale\Database\Repository\PendingChangeRepository::decode( $row['previous'] );

		return null !== $read && self::previous_valid( $previous )
			&& $read['hash'] === \PerfLocale\Resync\Store::content_hash( Scalar::str( $previous['post_title'] ), Scalar::str( $previous['post_content'] ), Scalar::str( $previous['post_excerpt'] ) )
			&& $read['status'] === $previous['post_status'];
	}

	/**
	 * Why the translation cannot be undone now ('' when it can): it must exist with the hash, status, modified
	 * time and recorded field values the change left.
	 *
	 * @param array<string, int|string> $row Change row.
	 * @phpstan-param ChangeRow $row
	 * @return string '' or an outcome code (target_gone, edited_since).
	 */
	public static function unchanged_since( array $row ): string {
		$summary = \PerfLocale\Database\Repository\PendingChangeRepository::decode( $row['summary'] );
		$keys    = Scalar::strings( $summary['fields'] ?? [] );
		$read    = self::read( $row['object_id'], $keys );

		if ( null === $read || 'trash' === $read['status'] ) {
			return 'target_gone';
		}

		if ( $read['hash'] !== $row['result_hash']
			|| $read['status'] !== $row['result_status']
			|| $read['modified'] !== $row['result_modified']
			|| ! self::fields_match( $keys, Scalar::str( $summary['result_fields_hash'] ?? '' ), $read['fields'] )
		) {
			return 'edited_since';
		}

		return '';
	}

	/**
	 * Whether the live values of the recorded keys still give the recorded digest (no keys: true; no digest: false).
	 *
	 * @param array<int, string>         $keys   The keys the change recorded.
	 * @param string                     $digest The digest it recorded.
	 * @param array<string, string|null> $live   The live values.
	 * @return bool
	 */
	public static function fields_match( array $keys, string $digest, array $live ): bool {
		if ( [] === $keys ) {
			return true;
		}

		return '' !== $digest && \PerfLocale\Resync\Store::digest_for_keys( $keys, $live ) === $digest;
	}

	/**
	 * The user holding a fresh edit lock, read as core reads `_edit_lock`, or 0.
	 *
	 * @param string $raw    The `_edit_lock` meta value.
	 * @param int    $now    Now (Unix time).
	 * @param int    $window The `wp_check_post_lock_window` seconds.
	 * @return int
	 */
	public static function lock_holder( string $raw, int $now, int $window ): int {
		if ( '' === $raw || ! preg_match( '/^(\d+):(\d+)$/', $raw, $m ) ) {
			return 0;
		}

		$time = (int) $m[1];
		$user = (int) $m[2];

		return $time > $now - $window && $user > 0 ? $user : 0;
	}

	/**
	 * Whether another user (or anyone, unless the acting user's own lock is ignored) holds a fresh edit lock.
	 * Read directly: wp_check_post_lock() is admin-only.
	 *
	 * @param int  $post_id    Post id.
	 * @param int  $user_id    The acting user.
	 * @param bool $ignore_own Whether the acting user's own lock is ignored.
	 * @return bool
	 */
	public static function post_locked( int $post_id, int $user_id, bool $ignore_own ): bool {
		$holder = self::lock_user( $post_id );

		if ( 0 === $holder ) {
			return false;
		}

		return ! ( $ignore_own && $user_id > 0 && $holder === $user_id );
	}

	/**
	 * The user holding a fresh edit lock on a post, or 0: `_edit_lock` read from the table, not the meta cache.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function lock_user( int $post_id ): int {
		wp_cache_delete( $post_id, 'post_meta' );
		$raw = get_post_meta( $post_id, '_edit_lock', true );

		/** This filter is documented in wp-admin/includes/ajax-actions.php */
		$window = Scalar::int( apply_filters( 'wp_check_post_lock_window', 150 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied as wp_check_post_lock() applies it.

		return self::lock_holder( is_string( $raw ) ? $raw : '', time(), $window );
	}

	/**
	 * Whether a scheduled post's date is under two minutes away or past: core publishes a future post on any
	 * update within a minute, and the second minute covers the gap until core's check.
	 *
	 * @param string $status   post_status.
	 * @param string $date_gmt post_date_gmt.
	 * @param int    $now      Now (Unix time).
	 * @return bool
	 */
	public static function schedule_missed( string $status, string $date_gmt, int $now ): bool {
		if ( 'future' !== $status || '' === $date_gmt || str_starts_with( $date_gmt, '0000-00-00' ) ) {
			return false;
		}

		$time = strtotime( $date_gmt . ' UTC' );

		return false !== $time && $time - $now < 2 * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether a post is scheduled with its date under two minutes away or past.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function missed_schedule( \WP_Post $post ): bool {
		return self::schedule_missed( $post->post_status, $post->post_date_gmt, time() );
	}

	/**
	 * Undo an applied change on a post translation; the write lands only while the row is still the one read
	 * (ROW_MOVED otherwise, ROW_UNREADABLE when nothing was read).
	 *
	 * @param array<string, int|string>  $row  Change row.
	 * @phpstan-param ChangeRow $row
	 * @param array<string, string>|null $read The row read before the undo's read (row_read()).
	 * @phpstan-param RowRead|null $read
	 * @return string Empty string on success, else the reason.
	 */
	public static function undo( array $row, ?array $read ): string {
		$post_id = $row['object_id'];

		if ( null === $read || ! self::digest_valid( $read['digest'] ) || ! self::digest_valid( $read['trash'] ) ) {
			return self::ROW_UNREADABLE;
		}

		if ( self::undo_trashes( $row ) ) {
			return self::undo_trash( $post_id, $read, $row['source_id'], $row['language_id'] );
		}

		$digest = $read['digest'];
		$moved  = false;
		$why    = '';

		$previous = \PerfLocale\Database\Repository\PendingChangeRepository::decode( $row['previous'] );
		$post     = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return __( 'The translation no longer exists.', 'perflocale' );
		}

		$result = self::guarded_save(
			$post_id,
			$digest,
			static fn () => wp_update_post(
				wp_slash(
					[
						'ID'            => $post_id,
						'post_title'    => Scalar::str( $previous['post_title'] ?? '' ),
						'post_content'  => Scalar::str( $previous['post_content'] ?? '' ),
						'post_excerpt'  => Scalar::str( $previous['post_excerpt'] ?? '' ),
						'post_status'   => Scalar::str( $previous['post_status'] ?? $post->post_status ),
						'post_name'     => $post->post_name,
						// An empty page_template keeps the stored one: a template the theme lost would stop the save.
						'page_template' => '',
					]
				),
				true
			),
			$moved,
			false,
			$why
		);

		if ( $moved ) {
			return self::ROW_MOVED;
		}

		if ( '' !== $why ) {
			return $why;
		}

		return is_wp_error( $result ) ? $result->get_error_message() : '';
	}

	/**
	 * Trash a translation for an undo, only while its row is the one read and it is still the change's translation.
	 *
	 * The trash_guard() filter runs last on `pre_trash_post` (and `pre_delete_post` when EMPTY_TRASH_DAYS is 0) before any
	 * write; the UPDATE is then a compare-and-set on the Trash digest, and unwind_trash() undoes core's earlier writes.
	 *
	 * @param int                   $post_id     Post id.
	 * @param array<string, string> $read        The row read before the undo's read (row_read()).
	 * @phpstan-param RowRead $read
	 * @param int                   $source_id   The change's source.
	 * @param int                   $language_id The change's language.
	 * @return string Empty string on success, else the reason (ROW_MOVED, ROW_UNREADABLE, ROW_UNCONFIRMED or a message).
	 */
	private static function undo_trash( int $post_id, array $read, int $source_id, int $language_id ): string {
		// Without a Trash the move is a permanent delete, which no compare-and-set guards (Codex ASTRA-11): the post stays.
		if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			return __( 'This site has no Trash, so the translation this change created was kept. Delete it yourself if you do not need it.', 'perflocale' );
		}

		$refused = '';
		$moved   = false;
		$why     = '';
		$added   = [];
		$guard   = self::trash_guard( $post_id, $read['digest'], $refused, $source_id, $language_id );
		$note    = static function ( $meta_id, $object_id, $meta_key ) use ( $post_id, &$added ): void {
			if ( Scalar::int( $object_id ) === $post_id && in_array( $meta_key, self::TRASH_META_KEYS, true ) ) {
				$added[] = Scalar::int( $meta_id );
			}
		};

		add_filter( 'pre_trash_post', $guard, PHP_INT_MAX, 2 );
		add_filter( 'pre_delete_post', $guard, PHP_INT_MAX, 2 );
		add_action( 'added_post_meta', $note, PHP_INT_MAX, 3 );

		try {
			$trashed = self::guarded_save( $post_id, $read['trash'], static fn () => wp_trash_post( $post_id ), $moved, true, $why );
		} finally {
			remove_filter( 'pre_trash_post', $guard, PHP_INT_MAX );
			remove_filter( 'pre_delete_post', $guard, PHP_INT_MAX );
			remove_action( 'added_post_meta', $note, PHP_INT_MAX );
		}

		if ( '' !== $refused ) {
			return $refused;
		}

		if ( $moved || self::ROW_UNREADABLE === $why ) {
			self::unwind_trash( $post_id, $read['post_name'], $added );

			return $moved ? self::ROW_MOVED : $why;
		}

		if ( '' !== $why ) {
			return $why;
		}

		return $trashed instanceof \WP_Post ? '' : __( 'The translation could not be moved to the Trash.', 'perflocale' );
	}

	/**
	 * The `pre_trash_post` / `pre_delete_post` guard of an undo's Trash move: false (core returns before any
	 * write) and `$refused` set unless the post is still the change's translation with the digest the undo read.
	 *
	 * @param int    $post_id     Post id.
	 * @param string $digest      The digest the undo read (row_read()).
	 * @param string $refused     Set to the reason when the guard refuses.
	 * @param int    $source_id   The change's source.
	 * @param int    $language_id The change's language.
	 * @return \Closure(mixed, mixed=): mixed
	 */
	private static function trash_guard( int $post_id, string $digest, string &$refused, int $source_id, int $language_id ): \Closure {
		return static function ( $check, $post = null ) use ( $post_id, $digest, &$refused, $source_id, $language_id ) {
			if ( null !== $check || ! $post instanceof \WP_Post || (int) $post->ID !== $post_id ) {
				return $check;
			}

			$linked = self::link_holds( $post_id, $source_id, $language_id );

			if ( true !== $linked ) {
				if ( '' === $refused ) {
					$refused = null === $linked ? self::ROW_UNREADABLE : __( 'The post is no longer the translation this change created (its language or translation group changed), so it was not moved to the Trash.', 'perflocale' );
				}

				return false;
			}

			$now = self::row_digest( $post_id );

			if ( $now === $digest ) {
				return null;
			}

			if ( '' === $refused ) {
				$refused = null === $now ? self::ROW_UNREADABLE : self::ROW_MOVED;
			}

			return false;
		};
	}

	/**
	 * Whether a post is still the translation a change created: in its language and its source's group.
	 * Read through query() so a failed read is not "moved".
	 *
	 * @param int $post_id     Post id.
	 * @param int $source_id   The change's source (0: not named).
	 * @param int $language_id The change's language.
	 * @return bool|null Null when the link could not be read.
	 */
	public static function link_holds( int $post_id, int $source_id, int $language_id ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$links = \PerfLocale\Database\Schema::table( 'translation_links' );
		$sql   = $source_id > 0
			? $wpdb->prepare(
				"SELECT t.object_id FROM %i t INNER JOIN %i s ON s.group_id = t.group_id AND s.type = 'post' AND s.object_id = %d
				 WHERE t.type = 'post' AND t.object_id = %d AND t.language_id = %d LIMIT 1",
				$links,
				$links,
				$source_id,
				$post_id,
				$language_id
			)
			: $wpdb->prepare( "SELECT t.object_id FROM %i t WHERE t.type = 'post' AND t.object_id = %d AND t.language_id = %d LIMIT 1", $links, $post_id, $language_id );

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; the link must come from the table, never a cache.
		$read = $wpdb->query( $sql );

		return is_int( $read ) ? $read > 0 : null;
	}

	/**
	 * Undo what core wrote before a refused Trash UPDATE: the slug back only while it holds core's `__trashed`
	 * slug (a slug written since stays), and the meta rows added for the move deleted by id.
	 *
	 * @param int        $post_id  Post id.
	 * @param string     $slug     The slug the undo read.
	 * @param array<int> $meta_ids The ids of the meta rows core added for the move.
	 * @return void
	 */
	private static function unwind_trash( int $post_id, string $slug, array $meta_ids ): void {
		global $wpdb;

		if ( $wpdb instanceof \wpdb && ! str_ends_with( $slug, '__trashed' ) ) {
			$put_back = $wpdb->prepare( 'UPDATE %i SET post_name = %s WHERE ID = %d AND post_name = %s', $wpdb->posts, $slug, $post_id, _truncate_post_slug( $slug, 191 ) . '__trashed' );

			if ( is_string( $put_back ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A compare-and-set on the slug core wrote, prepared above; the post's cache is cleaned below.
				$wpdb->query( $put_back );
			}
		}

		foreach ( $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'post', $meta_id );
		}

		clean_post_cache( $post_id );
	}

	/**
	 * The live post with its hash and the digest of the listed fields, read fresh via wp_cache_delete()
	 * (clean_post_cache() would bump `last_changed` on every read; a primed meta cache hides a later save).
	 *
	 * @param int                $post_id Post id.
	 * @param array<int, string> $keys    Meta keys the digest covers.
	 * @return array<string, mixed>|null Null when the post does not exist (or could not be read: see read_checked()).
	 * @phpstan-return LiveRead|null
	 */
	public static function read( int $post_id, array $keys ): ?array {
		$read = self::read_checked( $post_id, $keys );

		return false === $read ? null : $read;
	}

	/**
	 * The live read of read(), telling a missing post (null) from a failed read (false). A failed meta read
	 * is not left in the meta cache.
	 *
	 * @param int                $post_id Post id.
	 * @param array<int, string> $keys    Meta keys the digest covers.
	 * @return array<string, mixed>|false|null
	 * @phpstan-return LiveRead|false|null
	 */
	public static function read_checked( int $post_id, array $keys ): array|false|null {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return null;
		}

		wp_cache_delete( $post_id, 'posts' );
		$post = get_post( $post_id );

		// get_post() answers null for a missing row and a failed read alike; last_error tells them apart.
		if ( ! $post instanceof \WP_Post ) {
			return $wpdb instanceof \wpdb && '' !== $wpdb->last_error ? false : null;
		}

		if ( [] !== $keys ) {
			wp_cache_delete( $post_id, 'post_meta' );
			update_meta_cache( 'post', [ $post_id ] );

			if ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
				wp_cache_delete( $post_id, 'post_meta' );

				return false;
			}
		}

		$fields = self::field_values( $post_id, $keys );

		return [
			'post'     => $post,
			'hash'     => ContentChangeDetector::post_hash( $post ),
			'status'   => $post->post_status,
			'modified' => $post->post_modified_gmt,
			'fields'   => $fields,
			'digest'   => \PerfLocale\Resync\Store::digest_for_keys( $keys, $fields ),
		];
	}

	/**
	 * The content hash of a post as stored now, read fresh from the database.
	 *
	 * @param int $post_id Post id.
	 * @return string|null Null when the post does not exist.
	 */
	public static function stored_hash( int $post_id ): ?string {
		$read = self::read( $post_id, [] );

		return null === $read ? null : $read['hash'];
	}

	/**
	 * Each listed key's value: the string of one string row, else null (no row, several rows or non-string: no unit).
	 *
	 * @param int                $post_id Post id.
	 * @param array<int, string> $keys    Meta keys.
	 * @return array<string, string|null>
	 */
	public static function field_values( int $post_id, array $keys ): array {
		$out = [];

		foreach ( $keys as $key ) {
			$rows        = get_post_meta( $post_id, $key, false );
			$out[ $key ] = is_array( $rows ) && 1 === count( $rows ) && is_string( reset( $rows ) ) ? reset( $rows ) : null;
		}

		return $out;
	}

	/**
	 * The listed keys with no row at all, read after a meta load checked for a database error; false when it failed.
	 *
	 * @param int                $post_id Post id.
	 * @param array<int, string> $keys    Meta keys.
	 * @return list<string>|false
	 */
	public static function rowless_keys( int $post_id, array $keys ): array|false {
		global $wpdb;

		wp_cache_delete( $post_id, 'post_meta' );
		update_meta_cache( 'post', [ $post_id ] );

		if ( ! $wpdb instanceof \wpdb || '' !== $wpdb->last_error ) {
			wp_cache_delete( $post_id, 'post_meta' );

			return false;
		}

		$out = [];

		foreach ( $keys as $key ) {
			if ( [] === get_post_meta( $post_id, $key, false ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * The listed keys with rows but no unit (several rows, or a non-string value): a write would replace
	 * every row and an undo delete them all, so such a key is refused. A key without rows is writable.
	 *
	 * @param int                $post_id Post id.
	 * @param array<int, string> $keys    Meta keys.
	 * @return list<string>
	 */
	public static function keys_without_unit( int $post_id, array $keys ): array {
		$out = [];

		foreach ( $keys as $key ) {
			$rows = get_post_meta( $post_id, $key, false );

			if ( is_array( $rows ) && [] !== $rows && ! ( 1 === count( $rows ) && is_string( reset( $rows ) ) ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * The first restored key whose table rows are not the value written ('' when all hold it); a failed read counts.
	 *
	 * @param int                        $post_id  Post id.
	 * @param array<string, string|null> $restored Key => the value written, null for a delete.
	 * @return string
	 */
	private static function unrestored_key( int $post_id, array $restored ): string {
		global $wpdb;

		wp_cache_delete( $post_id, 'post_meta' );

		foreach ( $restored as $key => $value ) {
			$rows = get_post_meta( $post_id, (string) $key, false );

			if ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
				return (string) $key;
			}

			$rows = is_array( $rows ) ? array_values( $rows ) : [];

			if ( null === $value ? [] !== $rows : [ $value ] !== $rows ) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * The stored version a resync write replaces: title, content, excerpt, status, slug, and per key its row.
	 *
	 * @param \WP_Post           $post The post before the write.
	 * @param array<int, string> $keys Meta keys.
	 * @return array{post_title: string, post_content: string, post_excerpt: string, post_status: string, post_name: string, meta: array<string, array{exists: bool, value: string}>}
	 */
	public static function previous_with_meta( \WP_Post $post, array $keys ): array {
		$meta = [];

		foreach ( self::field_values( $post->ID, $keys ) as $key => $value ) {
			$meta[ $key ] = [
				'exists' => null !== $value,
				'value'  => null === $value ? '' : $value,
			];
		}

		return [
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_name'    => $post->post_name,
			'meta'         => $meta,
		];
	}

	/**
	 * Whether a stored `previous` is a snapshot an undo can write back.
	 *
	 * @param array<mixed> $previous Decoded previous.
	 * @return bool
	 */
	public static function previous_valid( array $previous ): bool {
		foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_status' ] as $field ) {
			if ( ! is_string( $previous[ $field ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write fields into a post. The slug never goes to core (SlugPin::keep(), and a one-shot `wp_insert_post_data` filter puts
	 * it back, in raw mode with the exact text after every other save filter); each meta key is a compare-and-set through
	 * PostMetaStorage. `$before_post` / `$at_update` can refuse core's UPDATE.
	 *
	 * @param int                        $post_id     Post id.
	 * @param array<mixed>               $fields      post_title, post_content, post_excerpt, post_status (raw only), post_name (raw only), meta, expected, source_id, row_digest.
	 * @param bool                       $raw         Byte-exact mode.
	 * @param callable|null              $after_post  Called with no argument after the post write; returns '' to go on, else the reason to stop.
	 * @param callable|null              $before_post Called with the hash of the post being written, first on core's pre_post_update; returns '' to go on, else the reason to refuse the UPDATE.
	 * @param callable|null              $at_update   Called with no argument, last on core's pre_post_update; returns '' to go on, else the reason to refuse the UPDATE.
	 * @param array<string, string|null> $values      Set per written key to the value it stored (null: deleted).
	 * @param callable|null              $before_meta Called with no argument before each meta key; returns '' to go on, else the reason to stop.
	 * @return string '' on success, else the reason.
	 */
	public static function write( int $post_id, array $fields, bool $raw = false, ?callable $after_post = null, ?callable $before_post = null, ?callable $at_update = null, array &$values = [], ?callable $before_meta = null ): string {
		$digest = null;

		if ( array_key_exists( 'row_digest', $fields ) ) {
			if ( ! self::digest_valid( $fields['row_digest'] ) ) {
				return self::ROW_UNREADABLE;
			}

			$digest = Scalar::str( $fields['row_digest'] );
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return __( 'The translation no longer exists.', 'perflocale' );
		}

		$data = [ 'ID' => $post_id ];

		foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $field ) {
			if ( is_string( $fields[ $field ] ?? null ) ) {
				$data[ $field ] = $fields[ $field ];
			}
		}

		if ( $raw && is_string( $fields['post_status'] ?? null ) && '' !== $fields['post_status'] ) {
			$data['post_status'] = $fields['post_status'];
		}

		$slug = $raw && is_string( $fields['post_name'] ?? null ) ? $fields['post_name'] : $post->post_name;
		// A raw put-back that names its slug restores it over a slug a save filter changed.
		$force = $raw && is_string( $fields['post_name'] ?? null );

		// A save hook that switches blog and saves the same id there is another post.
		$blog     = get_current_blog_id();
		$put_back = static function ( $row, $postarr ) use ( $post_id, $blog, $data, $raw, $slug, $force ) {
			if ( ! is_array( $row ) || ! is_array( $postarr ) || Scalar::int( $postarr['ID'] ?? 0 ) !== $post_id || get_current_blog_id() !== $blog ) {
				return $row;
			}

			if ( $raw ) {
				foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $field ) {
					if ( array_key_exists( $field, $data ) ) {
						$row[ $field ] = wp_slash( $data[ $field ] );
					}
				}
			}

			if ( '' !== $slug && ( $force || '' === Scalar::str( $row['post_name'] ?? '' ) ) ) {
				$row['post_name'] = $slug;
			}

			return $row;
		};

		$writing = null;
		$remove  = null;
		$refused = '';
		$cas     = self::CAS;
		$saving  = count( $data ) > 1;

		if ( null !== $before_post || null !== $at_update || null !== $digest ) {
			global $wpdb;

			if ( ! $wpdb instanceof \wpdb ) {
				return __( 'The write could not be recorded.', 'perflocale' );
			}

			// Core passes the unslashed row its UPDATE stores; a later save of the post is not this write's.
			$fired   = false;
			$writing = static function ( $id, $row ) use ( $post_id, $blog, $before_post, &$refused, &$fired ): void {
				if ( null === $before_post || $fired || Scalar::int( $id ) !== $post_id || get_current_blog_id() !== $blog || ! is_array( $row ) ) {
					return;
				}

				$fired   = true;
				$reason  = Scalar::str( $before_post( \PerfLocale\Resync\Store::content_hash( Scalar::str( $row['post_title'] ?? '' ), Scalar::str( $row['post_content'] ?? '' ), Scalar::str( $row['post_excerpt'] ?? '' ) ) ) );
				$refused = '' === $refused ? $reason : $refused;
			};
			add_action( 'pre_post_update', $writing, PHP_INT_MIN, 2 );
			$remove = self::cas( $post_id, $digest, false, $refused, $cas, $at_update );
		}

		add_filter( 'wp_insert_post_data', $put_back, PHP_INT_MAX, 2 );
		$unpin = $saving ? self::pin( $post_id ) : null;

		try {
			// An empty page_template keeps the stored one: a template the theme lost would stop the save.
			$result = $saving ? wp_update_post( wp_slash( $data + [ 'page_template' => '' ] ), true ) : $post_id;
		} finally {
			remove_filter( 'wp_insert_post_data', $put_back, PHP_INT_MAX );

			if ( null !== $unpin ) {
				$unpin();
			}

			if ( null !== $writing ) {
				remove_action( 'pre_post_update', $writing, PHP_INT_MIN );
			}

			if ( null !== $remove ) {
				$remove();
			}
		}

		if ( null !== $digest && $saving && self::unconfirmed( $post_id, $cas, $refused, ! is_wp_error( $result ) ) ) {
			return self::ROW_UNCONFIRMED;
		}

		if ( '' !== $refused ) {
			return $refused;
		}

		if ( $cas['moved'] ) {
			return self::ROW_MOVED;
		}

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		if ( null !== $after_post ) {
			$stop = Scalar::str( $after_post() );

			if ( '' !== $stop ) {
				return $stop;
			}
		}

		$meta     = is_array( $fields['meta'] ?? null ) ? $fields['meta'] : [];
		$expected = is_array( $fields['expected'] ?? null ) ? $fields['expected'] : null;
		$written  = [];
		$restored = [];

		if ( null !== $expected && [] !== $meta ) {
			// Rows are compared with the values read under the lock: a save hook may have changed one since.
			wp_cache_delete( $post_id, 'post_meta' );
		}

		foreach ( $meta as $key => $spec ) {
			$key = (string) $key;

			if ( $raw ) {
				if ( ! is_array( $spec ) ) {
					continue;
				}

				$value = empty( $spec['exists'] ) ? null : Scalar::str( $spec['value'] ?? '' );

				// A resync never writes a key without a unit, so it is not deleted.
				if ( null === $value && [] !== self::keys_without_unit( $post_id, [ $key ] ) ) {
					continue;
				}

				$restored[ $key ] = $value;
			} elseif ( null === $spec || is_string( $spec ) ) {
				$value = $spec;
			} else {
				continue;
			}

			// A writer that stalled in an earlier key's hooks stops before this one (its lock may be another's now).
			$stop = null === $before_meta ? '' : Scalar::str( $before_meta() );

			if ( '' !== $stop ) {
				clean_post_cache( $post_id );

				return $stop;
			}

			// Compare-and-set over the value read under the lock (else the rows read now): another writer's
			// value is never overwritten or deleted. A raw value is written as it is.
			$storage = new PostMetaStorage();
			$rows    = null !== $expected ? $storage->read_table( $post_id, $key ) : $storage->read( $post_id, $key );

			if ( null === $rows ) {
				$state = PostMetaStorage::FAILED;
			} elseif ( null !== $expected && array_key_exists( $key, $expected ) && $rows !== ( is_string( $expected[ $key ] ) ? [ $expected[ $key ] ] : [] ) ) {
				$state = PostMetaStorage::CONFLICT;
			} elseif ( null === $value ) {
				$state = $storage->delete( $post_id, $key, $rows );
			} else {
				$state = $storage->write( $post_id, $key, $value, $rows );
			}

			if ( PostMetaStorage::CONFLICT === $state || PostMetaStorage::FAILED === $state ) {
				if ( ! $raw ) {
					return sprintf(
						/* translators: %s: meta key */
						__( 'The field %s could not be written.', 'perflocale' ),
						$key
					);
				}

				clean_post_cache( $post_id );

				return sprintf(
					/* translators: %s: meta key */
					__( 'The field %s could not be restored.', 'perflocale' ),
					$key
				);
			}

			// The value as this write stored it (a sanitized scalar as its string), not as a later save left it.
			$stored         = null === $value ? null : sanitize_meta( $key, $value, 'post', get_object_subtype( 'post', $post_id ) );
			$written[]      = $key;
			$values[ $key ] = is_scalar( $stored ) ? (string) $stored : null;
		}

		// Core answers false for a refused write and an unchanged value alike, and a filter can
		// short-circuit either: the table decides.
		$unrestored = [] === $restored ? '' : self::unrestored_key( $post_id, $restored );

		if ( '' !== $unrestored ) {
			clean_post_cache( $post_id );

			return sprintf(
				/* translators: %s: meta key */
				__( 'The field %s could not be restored.', 'perflocale' ),
				$unrestored
			);
		}

		if ( [] !== $written ) {
			/** This action is documented in src/MachineTranslation/MetaTranslator.php */
			do_action( 'perflocale/mt/meta_translated', $post_id, $written, Scalar::int( $fields['source_id'] ?? 0 ) );
		}

		clean_post_cache( $post_id );

		return '';
	}
}
