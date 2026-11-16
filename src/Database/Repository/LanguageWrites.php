<?php
/**
 * Language repository writes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The writes of LanguageRepository (add, edit, rename, reorder, delete, default), which forwards to this class, so a
 * page view does not compile them.
 *
 * @internal
 */
final class LanguageWrites {

	/**
	 * Redirect map writes a rename tries, each after a lost compare-and-set re-reads the row.
	 */
	private const REDIRECT_WRITE_ATTEMPTS = 5;

	/**
	 * Option writes change_option() tries, each after a lost compare-and-set re-reads the row.
	 */
	private const OPTION_CAS_ATTEMPTS = 5;

	/**
	 * Values lease of a slug change, in seconds: longer than InnoDB's default 50 s lock wait, so a waiting slug change keeps
	 * other writers spaced out (its transaction, not the lease, is the fence).
	 */
	private const SLUG_LOCK_TTL = 60;

	/**
	 * Why the last slug change was not done ('busy', 'data', 'data_new', 'changed', 'unknown', 'failed'), '' when it was or
	 * when the slug was invalid; 'delete_data' after a refused delete, 'delete_unknown' after an unconfirmed one.
	 *
	 * @var string
	 */
	private static string $refused = '';

	/**
	 * Per request: whether the options and languages tables (by name pair) both roll back.
	 *
	 * @var array<string, bool>
	 */
	private static array $transactional = [];

	/** Engines that roll back a transaction (information_schema.TABLES.ENGINE, lowercased). */
	private const TRANSACTIONAL_ENGINES = [ 'innodb', 'ndbcluster', 'ndb', 'rocksdb', 'tokudb' ];

	/**
	 * The repository this writes for.
	 *
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $repo;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepository $repo  The repository.
	 * @param CacheManager       $cache Its cache manager.
	 * @param \wpdb              $wpdb  Its connection.
	 */
	public function __construct( LanguageRepository $repo, CacheManager $cache, \wpdb $wpdb ) {
		$this->repo  = $repo;
		$this->cache = $cache;
		$this->wpdb  = $wpdb;
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'languages' );
	}

	/**
	 * Insert a language.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @return int|false Inserted ID or false.
	 */
	public function insert( array $data ): int|false {
		$sanitized = self::sanitize_data( $data );

		// The full slug shape, as update() and rename_slug() check it: an empty or odd slug matches no rewrite rule and could
		// never be edited (sanitize_key() empties a non-ASCII slug).
		if ( ! isset( $sanitized['slug'] ) || ! is_string( $sanitized['slug'] ) || ! preg_match( LanguageRepository::SLUG_PATTERN, $sanitized['slug'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->table(), $sanitized );

		if ( $result === false ) {
			return false;
		}

		$id = (int) $this->wpdb->insert_id;

		$this->cache->flush_languages();

		// A slug made live must not stay a redirect source (as apply_slug_rename()): the Add Language flow renames a language
		// out of the way and inserts the new one under the freed slug.
		$this->drop_stale_slug_redirect(
			isset( $sanitized['slug'] ) && is_string( $sanitized['slug'] ) ? $sanitized['slug'] : ''
		);

		$language = $this->repo->find( $id );

		/** @hook perflocale/language/added Fires after a new language is added. */
		do_action( 'perflocale/language/added', $language );

		return $id;
	}

	/**
	 * Remove a now-live slug from the 301 redirect map, for insert() and update()'s slug filled in from ''
	 * (apply_slug_rename() prunes in its own write); a compare-and-set write, none when the map has no entry for the slug.
	 *
	 * @param string $slug Slug that just became live.
	 * @return void
	 */
	private function drop_stale_slug_redirect( string $slug ): void {
		if ( $slug === '' ) {
			return;
		}

		$wrote = false;

		$this->write_slug_redirects(
			static function ( array $redirects ) use ( $slug ): array {
				unset( $redirects[ $slug ] );

				return $redirects;
			},
			false,
			$wrote
		);

		if ( $wrote ) {
			$this->forget_cached_slug_redirects();
		}
	}

	/**
	 * Rename a language's slug and record an old => new 301 so `/old-slug/...` URLs keep working. Data-cheap (tables use
	 * the id); the cost is the rewrite flush and the redirect the front end reads.
	 *
	 * @param int    $id       Language ID.
	 * @param string $new_slug Proposed new slug (lowercase, alpha + hyphens).
	 * @return bool True on success.
	 */
	public function rename_slug( int $id, string $new_slug ): bool {
		self::$refused = '';
		$new_slug      = strtolower( trim( $new_slug ) );

		// The shared SLUG_PATTERN, as update().
		if ( ! preg_match( LanguageRepository::SLUG_PATTERN, $new_slug ) ) {
			return false;
		}

		$old = $this->repo->find( $id );

		if ( ! $old || (string) $old->slug === $new_slug ) {
			return false;
		}

		// Another language owns the slug: a clean false instead of a constraint error.
		if ( $this->repo->find_by_slug( $new_slug ) ) {
			return false;
		}

		$old_slug = (string) $old->slug;

		if ( true !== $this->slug_change( $id, $old_slug, $new_slug, [ 'slug' => $new_slug ] ) ) {
			return false;
		}

		// Everything beyond the row write lives in apply_slug_rename(), shared with update().
		$this->apply_slug_rename( $id, $old_slug, $new_slug );

		return true;
	}

	/**
	 * A slug change as one transaction: the row (locked), then the URL bases values (locked) and the map, then the row
	 * UPDATE guarded by the old slug and the connection. Refused while the values or the map hold anything for either slug
	 * (they are keyed by slug and never moved). Every outcome but done after the row was read stale or written resets the
	 * language caches (done: the caller's follow-up does). Docs: docs-ai/11-code-rationale.md, "LanguageRepository: slug renames and deletes".
	 *
	 * @param int                  $id       Language ID.
	 * @param string               $old_slug Slug read before the lock.
	 * @param string               $new_slug Slug after the change.
	 * @param array<string, mixed> $data     Columns to write (sanitize_data()), the slug among them.
	 * @return bool|null True when written, null when not (refused() says why).
	 */
	private function slug_change( int $id, string $old_slug, string $new_slug, array $data ): ?bool {
		self::$refused = '';

		// Inside another plugin transaction (a hook): refused before the lock row or START TRANSACTION.
		if ( \PerfLocale\Database\Tx::open() ) {
			self::$refused = 'busy';

			return null;
		}

		$wpdb      = $this->wpdb;
		$table     = $this->table();
		$outcome   = null;
		$attempted = false;

		// A settings save may hold the values lock: three tries.
		for ( $try = 0; $try < 3 && null === $outcome; $try++ ) {
			if ( $try > 0 ) {
				usleep( 200000 );
			}

			$outcome = \PerfLocale\Database\Tx::leased(
				\PerfLocale\Router\UrlBases\Values::LOCK,
				self::SLUG_LOCK_TTL,
				// A closure, not an arrow function: `$attempted` must reach this scope by reference.
				static function () use ( $wpdb, $table, $id, $old_slug, $new_slug, $data, &$attempted ): string {
					return self::slug_change_transaction( $wpdb, $table, $id, $old_slug, $new_slug, $data, $attempted );
				}
			);
		}

		if ( 'unknown' === $outcome ) {
			$this->after_unknown_slug_write( $id, $old_slug, $new_slug );
		} elseif ( $attempted && 'done' !== $outcome ) {
			$this->forget_language_caches( $id, $old_slug, $new_slug );
		}

		if ( 'done' === $outcome ) {
			return true;
		}

		// A conflict (another edit landed at the same slug) has the 'changed' notice.
		self::$refused = 'conflict' === $outcome ? 'changed' : ( is_string( $outcome ) ? $outcome : 'busy' );

		return null;
	}

	/**
	 * The slug change's transaction (the caller holds the values lease): COMMIT only after the row UPDATE on the
	 * connection it began on; every other outcome rolls back.
	 *
	 * @param \wpdb                $wpdb      Connection.
	 * @param string               $table     Languages table.
	 * @param int                  $id        Language ID.
	 * @param string               $old_slug  Slug read before the lock.
	 * @param string               $new_slug  Slug after the change.
	 * @param array<string, mixed> $data      Columns to write.
	 * @param bool                 $attempted Set when the caller's caches may hold a row that is not the stored one.
	 * @return string 'done', 'data', 'data_new', 'busy', 'changed', 'conflict', 'failed' or 'unknown'.
	 */
	private static function slug_change_transaction( \wpdb $wpdb, string $table, int $id, string $old_slug, string $new_slug, array $data, bool &$attempted ): string {
		$outcome = 'busy';
		$result  = \PerfLocale\Database\Tx::run(
			'language',
			// A closure, not an arrow function: `$outcome` and `$attempted` must reach this scope by reference.
			static function ( \PerfLocale\Database\Tx $tx ) use ( $wpdb, $table, $id, $old_slug, $new_slug, $data, &$outcome, &$attempted ): bool {
				$guard = $tx->guard();

				// Every language row first, in id order (the global lock order), then this row's slug.
				if ( ! \PerfLocale\Router\UrlBases\Inputs::lock_languages() || ! $guard->holds() ) {
					$outcome = $guard->holds() ? $outcome : 'failed';

					return false;
				}

				// The row, locked until COMMIT: a values save that read this slug has committed or waits behind us.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table bound with %i; the lock suffix is a literal.
				$now = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE id = %d' . \PerfLocale\Database\ConnectionGuard::lock_suffix(), $table, $id ) );

				// A lost connection (the server rolled back): nothing more is sent.
				if ( ! $guard->holds() ) {
					$outcome = 'failed';

					return false;
				}

				if ( '' !== $wpdb->last_error ) {
					return false;
				}

				// Deleted or renamed since the caller read it: its caches may hold that row.
				if ( 0 === $wpdb->num_rows || ! is_scalar( $now ) || (string) $now !== $old_slug ) {
					$attempted = true;
					$outcome   = 'changed';

					return false;
				}

				$outcome = self::url_bases_hold( $old_slug, $new_slug );

				if ( ! $guard->holds() ) {
					$outcome = 'failed';

					return false;
				}

				if ( '' !== $outcome ) {
					return false;
				}

				$attempted = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- slug_update_sql(): prepared, plus the guard condition.
				$rows = $wpdb->query( self::slug_update_sql( $wpdb, $table, $id, $old_slug, $data, $guard->condition() ) );

				// A lost connection, or a failed statement (the unit rolls back; no re-read inside it): nothing saved.
				if ( ! $guard->holds() || false === $rows ) {
					$outcome = 'failed';

					return false;
				}

				$outcome = is_int( $rows ) && $rows > 0 ? 'done' : self::slug_reread( $wpdb, $table, $id, $data, $old_slug, $new_slug, false );

				// A connection lost on the way: the server rolled the transaction back, so nothing was saved.
				if ( 'done' !== $outcome ) {
					$outcome = $guard->kept() ? $outcome : 'failed';
				}

				return 'done' === $outcome;
			},
			// COMMIT unconfirmed: the transaction is over (rolled back, or committed with its reply lost); the row says which.
			static function () use ( $wpdb, $table, $id, $data, $old_slug, $new_slug ): ?bool {
				$now = self::slug_reread( $wpdb, $table, $id, $data, $old_slug, $new_slug, false );

				return 'done' === $now ? true : ( 'unknown' === $now ? null : false );
			}
		);

		if ( 'done' === $result->status || 'unknown' === $result->status || 'busy' === $result->status ) {
			return $result->status;
		}

		// The body's own refusal; a failed statement, an ended transaction or an unconfirmed COMMIT is not saved.
		return 'body' === $result->why ? $outcome : 'failed';
	}

	/**
	 * The slug UPDATE: one fixed SET fragment per column sanitize_data() can return, values prepared, matched by id and old
	 * slug, plus the transaction's guard condition.
	 *
	 * @param \wpdb                $wpdb      Connection.
	 * @param string               $table     Languages table.
	 * @param int                  $id        Language ID.
	 * @param string               $old_slug  Slug the row must hold.
	 * @param array<string, mixed> $data      Columns to write.
	 * @param string               $condition ConnectionGuard::condition(); '' for none.
	 * @return string
	 * @throws \InvalidArgumentException For any other column.
	 */
	private static function slug_update_sql( \wpdb $wpdb, string $table, int $id, string $old_slug, array $data, string $condition ): string {
		$fragments = [
			'slug'           => '`slug` = %s',
			'locale'         => '`locale` = %s',
			'name'           => '`name` = %s',
			'native_name'    => '`native_name` = %s',
			'flag'           => '`flag` = %s',
			'date_format'    => '`date_format` = %s',
			'time_format'    => '`time_format` = %s',
			'text_direction' => '`text_direction` = %s',
			'is_default'     => '`is_default` = %d',
			'is_active'      => '`is_active` = %d',
			'sort_order'     => '`sort_order` = %d',
		];
		$set       = [];
		$args      = [ $table ];

		foreach ( $data as $column => $value ) {
			if ( ! isset( $fragments[ $column ] ) ) {
				throw new \InvalidArgumentException( 'slug_change: invalid column.' );
			}

			$set[]  = $fragments[ $column ];
			$args[] = is_scalar( $value ) ? $value : '';
		}

		$args[] = $id;
		$args[] = $old_slug;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One placeholder per column; names checked above.
		$sql = $wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE `id` = %d AND `slug` = %s', ...$args );

		return ( is_string( $sql ) ? $sql : '' ) . ( '' !== $condition ? ' AND ' . $condition : '' );
	}

	/**
	 * After a slug UPDATE that reported no row or an error: the intended columns re-read (two tries) decide; both reads
	 * failed is 'unknown'.
	 *
	 * @param \wpdb                $wpdb     Connection.
	 * @param string               $table    Languages table.
	 * @param int                  $id       Language ID.
	 * @param array<string, mixed> $data     Columns written.
	 * @param string               $old_slug Slug before.
	 * @param string               $new_slug Slug intended.
	 * @param bool                 $errored  Whether the UPDATE reported an error.
	 * @return string
	 */
	private static function slug_reread( \wpdb $wpdb, string $table, int $id, array $data, string $old_slug, string $new_slug, bool $errored ): string {
		$columns = implode( ', ', array_fill( 0, count( $data ), '%i' ) );

		for ( $read = 0; $read < 2; $read++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $columns holds one %i per column.
			$now = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM %i WHERE id = %d", ...array_merge( array_keys( $data ), [ $table, $id ] ) ), ARRAY_A );

			if ( '' === $wpdb->last_error ) {
				return self::row_outcome( is_array( $now ) ? $now : null, $data, $old_slug, $new_slug, $errored );
			}
		}

		return 'unknown';
	}

	/**
	 * What the re-read row says after a slug UPDATE that matched no row: 'done' (every intended column is stored: a resent
	 * statement landed), 'conflict' (the new slug with other columns: another edit), 'failed' (the old slug after an
	 * error) or 'changed' (gone or anything else).
	 *
	 * @param array<mixed>|null    $row      The row's intended columns as read; null when gone.
	 * @param array<string, mixed> $data     The intended columns.
	 * @param string               $old_slug Slug before.
	 * @param string               $new_slug Slug intended.
	 * @param bool                 $errored  Whether the UPDATE reported an error.
	 * @return string
	 */
	private static function row_outcome( ?array $row, array $data, string $old_slug, string $new_slug, bool $errored ): string {
		if ( null === $row ) {
			return 'changed';
		}

		$same = true;

		foreach ( $data as $column => $want ) {
			$have = $row[ $column ] ?? null;
			$have = is_scalar( $have ) ? (string) $have : null;
			// Ints through (int): the driver returns strings; strings byte-equal (sanitize_data() gave the stored form).
			$same = $same && null !== $have && ( is_int( $want ) ? (int) $have === $want : is_scalar( $want ) && $have === (string) $want );
		}

		$slug = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : null;

		if ( $same ) {
			return 'done';
		}

		if ( $new_slug === $slug ) {
			return 'conflict';
		}

		return $errored && $old_slug === $slug ? 'failed' : 'changed';
	}

	/**
	 * After a slug UPDATE whose reply was lost and whose row could not be read: either slug may be live, so every cache
	 * that holds one goes and the rules are rebuilt; no 301 (if the UPDATE did not land, a 301 would break the old slug).
	 *
	 * @param int    $id       Language ID.
	 * @param string $old_slug Slug before.
	 * @param string $new_slug Slug intended.
	 * @return void
	 */
	private function after_unknown_slug_write( int $id, string $old_slug, string $new_slug ): void {
		$this->forget_language_caches( $id, $old_slug, $new_slug );
		$this->flush_translation_link_caches();
		update_option( 'perflocale_flush_rules', 1, true );
	}

	/**
	 * Drop every cached copy of the language rows: in this request (static) and in the object cache (generation bump,
	 * which also re-reads a generation another request bumped). The group flush plus explicit key deletes: some persistent
	 * backends do not fully honour flush_group().
	 *
	 * @param int    $id       Language ID.
	 * @param string $old_slug Slug before.
	 * @param string $new_slug Slug intended.
	 * @return void
	 */
	private function forget_language_caches( int $id, string $old_slug, string $new_slug ): void {
		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );
		$this->cache->delete( "language_slug_{$old_slug}", 'perflocale_langs' );
		$this->cache->delete( "language_slug_{$new_slug}", 'perflocale_langs' );
	}

	/**
	 * Why a slug change must wait: 'busy' (the values or the map unread), 'data' (URL bases data for the old slug),
	 * 'data_new' (for the new one), '' for none. Inside the slug change's transaction: the values row is read locked.
	 *
	 * @param string $old_slug Slug now.
	 * @param string $new_slug Slug after the change.
	 * @return string
	 */
	private static function url_bases_hold( string $old_slug, string $new_slug ): string {
		$slugs = [
			'data'     => $old_slug,
			'data_new' => $new_slug,
		];
		$row   = \PerfLocale\Database\OptionRead::of( \PerfLocale\Router\UrlBases\Values::OPTION, true );

		if ( $row->failed ) {
			return 'busy';
		}

		if ( null !== $row->bytes ) {
			$raw = maybe_unserialize( $row->bytes );

			// Bytes that are no value set prove nothing absent.
			if ( ! is_array( $raw ) ) {
				return 'busy';
			}

			foreach ( $slugs as $reason => $slug ) {
				if ( self::url_bases_name( $raw, $slug ) ) {
					return $reason;
				}
			}
		}

		// Addresses the map serves that the record does not hold yet (a compile records them later).
		$served = \PerfLocale\Router\UrlBases\Values::published();

		if ( null === $served ) {
			return 'busy';
		}

		foreach ( $slugs as $reason => $slug ) {
			foreach ( $served as $by_lang ) {
				if ( ! empty( $by_lang[ $slug ] ) ) {
					return $reason;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a successful read finds no language row with this id or slug; a failed read is false.
	 *
	 * @param \wpdb      $wpdb   Connection.
	 * @param string     $table  Languages table.
	 * @param string     $column 'id' or 'slug'.
	 * @param int|string $value  The id or slug.
	 * @param bool       $locked A locking read (ConnectionGuard::lock_suffix()), inside a transaction.
	 * @return bool
	 */
	private static function no_language_row( \wpdb $wpdb, string $table, string $column, int|string $value, bool $locked = false ): bool {
		$lock = $locked && \PerfLocale\Database\ConnectionGuard::applies();

		// One literal statement per column and lock (SQLite: no FOR UPDATE, see ConnectionGuard::lock_suffix()).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'id' === $column ) {
			$found = $lock
				? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $table, $value ) )
				: $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $value ) );
		} else {
			$found = $lock
				? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s FOR UPDATE', $table, $value ) )
				: $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $table, $value ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return '' === $wpdb->last_error && null === $found;
	}

	/**
	 * Whether the slug holds URL bases data the delete's release would write: values naming it or map entries for it.
	 *
	 * @param \PerfLocale\Database\OptionRead $values The values row as read.
	 * @param \PerfLocale\Database\OptionRead $map    The map row as read.
	 * @param string                          $slug   Language slug.
	 * @return bool
	 */
	private static function url_bases_data( \PerfLocale\Database\OptionRead $values, \PerfLocale\Database\OptionRead $map, string $slug ): bool {
		$raw = null !== $values->bytes ? maybe_unserialize( $values->bytes ) : null;

		return ( is_array( $raw ) && self::url_bases_name( $raw, $slug ) ) || \PerfLocale\Router\UrlBases\Publication::holds_slug( $map->bytes, $slug );
	}

	/**
	 * Whether this blog's options and languages tables both roll back (cached per request); SQLite counts as yes.
	 *
	 * @return bool|null Null when the engines could not be read.
	 */
	private function coupled_atomic(): ?bool {
		if ( ! \PerfLocale\Database\ConnectionGuard::applies() ) {
			return true;
		}

		$wpdb   = $this->wpdb;
		$tables = [ $wpdb->options, $this->table() ];
		$key    = implode( '|', $tables );

		if ( isset( self::$transactional[ $key ] ) ) {
			return self::$transactional[ $key ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached per request above.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)', $tables[0], $tables[1] ), ARRAY_A );

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}

		$engines = [];

		foreach ( $rows as $row ) {
			$engines[ strtolower( (string) ( is_scalar( $row['TABLE_NAME'] ?? null ) ? $row['TABLE_NAME'] : '' ) ) ] = strtolower( (string) ( is_scalar( $row['ENGINE'] ?? null ) ? $row['ENGINE'] : '' ) );
		}

		// A table not listed (a view, another schema) proves nothing: not transactional.
		$atomic = true;

		foreach ( $tables as $table ) {
			$atomic = $atomic && in_array( $engines[ strtolower( $table ) ] ?? '', self::TRANSACTIONAL_ENGINES, true );
		}

		self::$transactional[ $key ] = $atomic;

		return $atomic;
	}

	/**
	 * Release the URL bases data of slugs that are no language any more, inside the caller's open transaction (language
	 * rows locked, then deleted or replaced): the values without them, then the published map without them.
	 *
	 * @internal LanguageWrites::delete() and the Replace import only.
	 * @param list<string>                         $slugs      Slugs the caller removed: an explicit list (deleting needs proof).
	 * @param \PerfLocale\Database\ConnectionGuard $guard      The open transaction's guard.
	 * @param (callable(): bool)|null              $also_prove A further proof under the lock (the delete: no row with its id).
	 * @return array{values: string, map: array{0: array<mixed>, 1: array<mixed>}|null}
	 * @throws \RuntimeException When a slug is not proven free or a write is not confirmed: the caller rolls back.
	 */
	public static function release_slugs( array $slugs, \PerfLocale\Database\ConnectionGuard $guard, ?callable $also_prove = null ): array {
		$wpdb  = \PerfLocale\Admin\Typed::wpdb();
		$table = Schema::table( 'languages' );
		$proof = false;
		$named = false;

		$released = \PerfLocale\Router\UrlBases\Values::write_in_transaction(
			static function ( array $v, array $live ) use ( $wpdb, $table, $slugs, $guard, $also_prove, &$proof, &$named ): array {
				// Check again under the lock: the caller's own DELETE is in this transaction, so every read must find nothing.
				$proof = null === $also_prove || $also_prove();
				$named = false;

				foreach ( $slugs as $slug ) {
					$proof = $proof && $guard->holds() && self::no_language_row( $wpdb, $table, 'slug', $slug, true ) && ! in_array( $slug, $live, true );
					$named = $named || self::url_bases_name( $v, $slug );
				}

				foreach ( $proof ? $slugs : [] as $slug ) {
					$v = self::url_bases_without( $v, $slug );
				}

				return $v;
			},
			$guard
		);

		// No proof, a failed write, or values that named a slug and were not stored (a filter vetoed the write): no release.
		if ( ! $proof || 'failed' === $released || ( $named && 'stored' !== $released ) ) {
			throw new \RuntimeException( 'release_slugs: the URL bases data could not be released' );
		}

		// The row as option filters and the write left it, under the lock: a partial veto keeps a slug's data.
		$stored = \PerfLocale\Database\OptionRead::of( \PerfLocale\Router\UrlBases\Values::OPTION, true );
		$raw    = null !== $stored->bytes ? maybe_unserialize( $stored->bytes ) : null;

		if ( $stored->failed || ! $guard->holds() ) {
			throw new \RuntimeException( 'release_slugs: the stored URL bases values could not be read' );
		}

		foreach ( $slugs as $slug ) {
			if ( is_array( $raw ) && self::url_bases_name( $raw, $slug, false ) ) {
				throw new \RuntimeException( 'release_slugs: the stored URL bases values still hold a released slug' );
			}
		}

		// The published map's entries too (lock order: after the values row): a compile after COMMIT finds none to carry.
		$map = \PerfLocale\Router\UrlBases\Publication::release_slugs( $slugs, $guard );

		if ( ! $guard->holds() ) {
			throw new \RuntimeException( 'release_slugs: the connection was lost' );
		}

		return [
			'values' => $released,
			'map'    => $map,
		];
	}

	/**
	 * A value set without anything keyed by this language slug.
	 *
	 * @param array<string, mixed> $v    Value set.
	 * @param string               $slug Language slug.
	 * @return array<string, mixed>
	 */
	private static function url_bases_without( array $v, string $slug ): array {
		foreach ( [ 'keys', 'pub' ] as $part ) {
			$entries = is_array( $v[ $part ] ?? null ) ? $v[ $part ] : [];

			foreach ( $entries as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				// keys: the value and history under the slug; pub: the slug's recorded addresses.
				if ( 'pub' === $part ) {
					unset( $entry[ $slug ] );
				} else {
					foreach ( [ 'tr', 'hist' ] as $field ) {
						$by_lang = $entry[ $field ] ?? null;

						if ( is_array( $by_lang ) ) {
							unset( $by_lang[ $slug ] );
							$entry[ $field ] = $by_lang;
						}
					}
				}

				$entries[ $key ] = $entry;
			}

			$v[ $part ] = $entries;
		}

		return \PerfLocale\Router\UrlBases\Values::shape( $v );
	}

	/**
	 * Whether a stored URL bases value set names a language slug: a value, history or published address under it, or a
	 * pre-release `ren` record naming it. Malformed parts count.
	 *
	 * @param array<mixed> $raw  Stored value set, unserialized.
	 * @param string       $slug Language slug.
	 * @param bool         $ren  Count `ren` records (the release removes none: false for its confirmation).
	 * @return bool
	 */
	private static function url_bases_name( array $raw, string $slug, bool $ren = true ): bool {
		foreach ( is_array( $raw['keys'] ?? null ) ? $raw['keys'] : [] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			foreach ( [ 'tr', 'hist' ] as $field ) {
				if ( isset( $entry[ $field ] ) && ( ! is_array( $entry[ $field ] ) || ! empty( $entry[ $field ][ $slug ] ) ) ) {
					return true;
				}
			}
		}

		foreach ( is_array( $raw['pub'] ?? null ) ? $raw['pub'] : [] as $by_lang ) {
			if ( ! is_array( $by_lang ) || ! empty( $by_lang[ $slug ] ) ) {
				return true;
			}
		}

		foreach ( $ren && is_array( $raw['ren'] ?? null ) ? $raw['ren'] : [] as $r ) {
			if ( ! is_array( $r ) || in_array( $slug, $r, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why the last slug change was not done (see $refused); '' when it was.
	 *
	 * @return string
	 */
	public static function refused(): string {
		return self::$refused;
	}

	/**
	 * The notice for a refused slug change (refused() when no reason is given); '' for none.
	 *
	 * @param string|null $reason 'busy', 'data', 'data_new', 'changed', 'unknown', 'failed', 'delete_data' or 'delete_unknown'.
	 * @param string      $slug   The new slug, named by 'data_new'.
	 * @return string
	 */
	public static function refusal( ?string $reason = null, string $slug = '' ): string {
		$reason ??= self::$refused;

		switch ( $reason ) {
			case 'data':
				return __( 'The language slug was not changed: this language has translated URL bases. Under Settings > URL & Routing > URL Bases, clear them and stop redirecting their old addresses, change the slug, then enter them again.', 'perflocale' );
			case 'data_new':
				return '' !== $slug
					/* translators: %s: the new language slug. */
					? sprintf( __( 'The language slug was not changed: the slug %s still has translated URL bases. Choose another slug.', 'perflocale' ), $slug )
					: __( 'The language slug was not changed: the new slug still has translated URL bases. Choose another slug.', 'perflocale' );
			case 'changed':
				return __( 'The language slug was not changed: this language was changed somewhere else at the same time. Check it and try again.', 'perflocale' );
			case 'busy':
				return __( 'The language slug was not changed: the translated URL bases settings could not be read or saved just now. Try again.', 'perflocale' );
			case 'unknown':
				return __( 'The language slug change could not be confirmed: the database did not answer. Reload the Languages list to see whether it was saved.', 'perflocale' );
			case 'failed':
				return __( 'The language slug was not changed: the database did not save it. Try again.', 'perflocale' );
			case 'delete_data':
				return __( 'The language was not deleted: it has translated URL bases, and this site\'s database cannot remove them together with the language. Under Settings > URL & Routing > URL Bases, clear them and stop redirecting their old addresses, then delete the language.', 'perflocale' );
			case 'delete_unknown':
				return __( 'Deleting the language could not be confirmed: the database did not answer. Reload the Languages list to see whether it was deleted.', 'perflocale' );
		}

		return '';
	}

	/**
	 * Everything a slug change implies beyond the row UPDATE: the 301 record, the reference migration, cache
	 * invalidation and the rewrite flush flag; shared by rename_slug() and update() (the Edit form's slug field).
	 * Docs: docs-ai/11-code-rationale.md, "LanguageRepository: slug renames and deletes".
	 *
	 * @param int    $id       Language ID whose slug just changed.
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return void
	 */
	private function apply_slug_rename( int $id, string $old_slug, string $new_slug ): void {
		// The redirect record, in one option (a site renames a handful of slugs).
		$recorded = $this->record_slug_redirect( $old_slug, $new_slug );

		// Every slug held as a key or value outside the id graph (attachments, menus, settings, user preferences).
		$this->migrate_slug_references( $old_slug, $new_slug );

		// Read the row after these writes: a delete that read before them would leave redirects and options for a dead
		// prefix. Still there: a later delete's clean-up sees the writes; gone: that clean-up runs here.
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $this->table(), $id ) );
		// A failed read is not a gone row (as in delete()): the settings stay.
		$gone = '' === $wpdb->last_error && 0 === $wpdb->num_rows;

		if ( $gone ) {
			$this->drop_slug_options( $new_slug );
		}

		// The cached map copies go once the row holds it, after the migration so its option reads reuse this request's list.
		if ( $recorded ) {
			$this->forget_cached_slug_redirects();
		}

		$this->forget_language_caches( $id, $old_slug, $new_slug );

		// The eager link map has the old slug in every link row of this language and outlives any TTL.
		$this->flush_translation_link_caches();

		// Rewrite rules are built from the active slugs: flush them on the next request.
		update_option( 'perflocale_flush_rules', 1, true );

		/** @hook perflocale/language/slug_renamed Fires after a language slug rename succeeds. */
		do_action( 'perflocale/language/slug_renamed', $id, $old_slug, $new_slug );
	}

	/**
	 * Write old => new into the redirect map with a compare-and-set (write_slug_redirects()).
	 *
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return bool True once the row holds the map; the caller then drops
	 *              the cached copies with forget_cached_slug_redirects().
	 */
	private function record_slug_redirect( string $old_slug, string $new_slug ): bool {
		return $this->write_slug_redirects(
			static fn( array $redirects ): array => self::redirect_map_after_rename( $redirects, $old_slug, $new_slug ),
			true
		);
	}

	/**
	 * Change the autoloaded redirect map with a compare-and-set on its row (a cached copy can be older): the UPDATE
	 * matches only the value it was derived from, and a lost comparison re-reads the row and retries, bounded. An
	 * unchanged map writes nothing, confirmed against the row with $confirm_unchanged. An empty map stays as a row: a
	 * missing one costs a query per front-end request without a persistent object cache.
	 *
	 * @param callable $transform         Returns the map after the change, given the map as read.
	 * @param bool     $confirm_unchanged Whether an unchanged map read from the cached copy is confirmed
	 *                                    against the row.
	 * @param bool     $wrote             Set to true when a write of the row matched.
	 * @phpstan-param callable(array<mixed>): array<mixed> $transform
	 * @return bool True once the row holds the changed map, or nothing had to
	 *              change; false after the bounded attempts.
	 */
	private function write_slug_redirects( callable $transform, bool $confirm_unchanged, bool &$wrote = false ): bool {
		$wpdb     = $this->wpdb;
		$cached   = get_option( LanguageRepository::REDIRECTS_OPTION, null );
		$current  = null === $cached ? null : maybe_serialize( $cached );
		$expected = is_string( $current ) ? $current : null;
		$from_row = false;
		$settled  = false;
		$wrote    = false;
		// Autoloaded: SlugRedirector reads it on every front-end request; the 50-entry cap keeps it small.
		$autoload = function_exists( 'wp_determine_option_autoload_value' ) ? 'on' : 'yes';

		for ( $attempt = 0; $attempt < self::REDIRECT_WRITE_ATTEMPTS; $attempt++ ) {
			$map   = null === $expected ? [] : (array) maybe_unserialize( $expected );
			$value = maybe_serialize( $transform( $map ) );

			if ( $value === $expected ) {
				// Nothing to write: proven by a row read, or by the cached copy when the caller accepts it.
				if ( $from_row || ! $confirm_unchanged ) {
					$settled = true;
					break;
				}
			} elseif ( null === $expected ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of an autoloaded option; the caller purges the options cache.
				$affected = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, LanguageRepository::REDIRECTS_OPTION, $value, $autoload ) ?? '' );

				if ( 1 === $affected ) {
					$settled = true;
					$wrote   = true;
					break;
				}
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of an autoloaded option; the caller purges the options cache.
				$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $value, $autoload, LanguageRepository::REDIRECTS_OPTION, $expected ) ?? '' );

				if ( 1 === $affected ) {
					$settled = true;
					$wrote   = true;
					break;
				}
			}

			if ( $attempt + 1 >= self::REDIRECT_WRITE_ATTEMPTS ) {
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row itself, past any cached copy.
			$row      = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, LanguageRepository::REDIRECTS_OPTION ) );
			$expected = null === $row ? null : (string) $row;
			$from_row = true;
		}

		return $settled;
	}

	/**
	 * The settings with a renamed slug moved from old to new wherever it is a key or a value.
	 *
	 * @param array<mixed> $settings Settings as stored.
	 * @param string       $old_slug Pre-rename slug.
	 * @param string       $new_slug Post-rename slug.
	 * @return array<mixed>
	 */
	private static function settings_after_rename( array $settings, string $old_slug, string $new_slug ): array {
		// geo_country_map: [slug => "CC,CC"], the slug as key (form field geo_country_map[<slug>]).
		if ( ! empty( $settings['geo_country_map'] )
			&& is_array( $settings['geo_country_map'] )
			&& isset( $settings['geo_country_map'][ $old_slug ] )
		) {
			$settings['geo_country_map'][ $new_slug ] = $settings['geo_country_map'][ $old_slug ];
			unset( $settings['geo_country_map'][ $old_slug ] );
		}

		// language_fallbacks: [slug => [fallback slugs]], the slug as key and as value.
		if ( ! empty( $settings['language_fallbacks'] ) && is_array( $settings['language_fallbacks'] ) ) {
			$rekeyed = [];

			foreach ( $settings['language_fallbacks'] as $key => $fallbacks ) {
				$new_key = $key === $old_slug ? $new_slug : $key;

				if ( is_array( $fallbacks ) ) {
					$fallbacks = array_map(
						static fn( $s ) => $s === $old_slug ? $new_slug : $s,
						$fallbacks
					);
				}

				$rekeyed[ $new_key ] = $fallbacks;
			}

			$settings['language_fallbacks'] = $rekeyed;
		}

		// language_domains [slug => domain] and wc_currencies [slug => rates], the slug as key; without the move the
		// currency override stops applying.
		foreach ( [ 'language_domains', 'wc_currencies' ] as $map ) {
			if ( ! empty( $settings[ $map ] ) && is_array( $settings[ $map ] ) && array_key_exists( $old_slug, $settings[ $map ] ) ) {
				$settings[ $map ][ $new_slug ] = $settings[ $map ][ $old_slug ];
				unset( $settings[ $map ][ $old_slug ] );
			}
		}

		return $settings;
	}

	/**
	 * The settings without a deleted slug's keys and values, the inverse of migrate_slug_references() (a stale
	 * wc_currencies or language_domains entry silently stops applying or routes nowhere).
	 *
	 * @param array<mixed> $settings Settings as stored.
	 * @param string       $slug     Deleted language's slug.
	 * @return array<mixed>
	 */
	private static function settings_without_language( array $settings, string $slug ): array {
		// geo_country_map: the slug as key.
		if ( ! empty( $settings['geo_country_map'] ) && is_array( $settings['geo_country_map'] ) ) {
			unset( $settings['geo_country_map'][ $slug ] );
		}

		// language_fallbacks: the slug as key and as value.
		if ( ! empty( $settings['language_fallbacks'] ) && is_array( $settings['language_fallbacks'] ) ) {
			unset( $settings['language_fallbacks'][ $slug ] );

			foreach ( $settings['language_fallbacks'] as $key => $fallbacks ) {
				if ( ! is_array( $fallbacks ) ) {
					continue;
				}
				$pruned = array_values(
					array_filter(
						$fallbacks,
						static fn( $s ): bool => $s !== $slug
					)
				);
				if ( $pruned !== $fallbacks ) {
					$settings['language_fallbacks'][ $key ] = $pruned;
				}
			}
		}

		// language_domains and wc_currencies: the slug as key.
		foreach ( [ 'language_domains', 'wc_currencies' ] as $map ) {
			if ( ! empty( $settings[ $map ] ) && is_array( $settings[ $map ] ) ) {
				unset( $settings[ $map ][ $slug ] );
			}
		}

		return $settings;
	}

	/**
	 * Change an array option with a compare-and-set on its row, as write_slug_redirects() does; through
	 * OptionWriter::compare_and_set(), so listeners see update_option()'s filters and actions. A missing row or a
	 * non-array value is left alone.
	 *
	 * @param string    $option    Option name.
	 * @param callable  $transform Returns the array after the change, given the array as read.
	 * @param bool|null $autoload  As update_option()'s $autoload: null keeps the row's.
	 * @phpstan-param callable(array<mixed>): array<mixed> $transform
	 * @return bool True when a write of the row matched.
	 */
	private function change_option( string $option, callable $transform, ?bool $autoload = null ): bool {
		$wpdb     = $this->wpdb;
		$cached   = get_option( $option, null );
		$expected = is_array( $cached ) ? maybe_serialize( $cached ) : null;
		$current  = $cached;

		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			if ( null === $expected ) {
				return false;
			}

			$before = maybe_unserialize( $expected );

			if ( ! is_array( $before ) ) {
				return false;
			}

			$after = $transform( $before );

			if ( $after === $before ) {
				return false;
			}

			// As update_option() writes it (filters may change or veto), only while the row holds what was read.
			$written = \PerfLocale\Database\OptionWriter::compare_and_set( $option, $after, $current, $expected, $autoload );

			if ( null !== $written ) {
				return $written;
			}

			if ( $attempt + 1 >= self::OPTION_CAS_ATTEMPTS ) {
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row itself, past any cached copy.
			$row      = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $option ) );
			$expected = null === $row ? null : (string) $row;

			// The hooks get the old value through `option_{$option}`, as update_option() passes it; the change is on the row.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter for this option.
			$current = null === $row ? null : apply_filters( "option_{$option}", maybe_unserialize( (string) $row ), $option );
		}

		return false;
	}

	/**
	 * Drop a gone language's slug from options: perflocale_settings, perflocale_exchange_rates and every redirect naming
	 * it. Run by delete(), and by apply_slug_rename() when the language was deleted meanwhile.
	 *
	 * @param string $slug The slug.
	 * @return void
	 */
	private function drop_slug_options( string $slug ): void {
		// Every settings reference holding the slug as key or value, written with change_option()'s compare-and-set: the
		// copy read can be older than the row, and a plain write would undo another save.
		$settings_written = $this->change_option(
			'perflocale_settings',
			static fn( array $settings ): array => self::settings_without_language( $settings, $slug )
		);

		if ( $settings_written ) {
			// Reload the Settings singleton before anything later in this request reads it.
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$plugin->get( 'settings' )->reset_cache();
			}
		}

		// WooCommerce's fetched exchange rates, keyed by slug (ExchangeRateSync would drop it only at its next sync).
		$this->change_option(
			'perflocale_exchange_rates',
			static function ( array $rates ) use ( $slug ): array {
				unset( $rates[ $slug ] );

				return $rates;
			},
			false
		);

		// Drop every redirect whose key or value is the slug: one into a dead prefix is a permanent 301 to 404. With the
		// compare-and-set of write_slug_redirects(), confirmed against the row (a redirect recorded since is only there).
		$wrote = false;

		$this->write_slug_redirects(
			static function ( array $redirects ) use ( $slug ): array {
				$pruned = [];

				foreach ( $redirects as $from => $to ) {
					if ( (string) $from === $slug || (string) $to === $slug ) {
						continue;
					}
					$pruned[ $from ] = $to;
				}

				return $pruned;
			},
			true,
			$wrote
		);

		if ( $wrote ) {
			$this->forget_cached_slug_redirects();
		}
	}

	/**
	 * Drop every cached copy of the redirect map (alloptions, its key, its `notoptions` entry) so the next read loads
	 * the row.
	 *
	 * @return void
	 */
	private function forget_cached_slug_redirects(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( LanguageRepository::REDIRECTS_OPTION, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ LanguageRepository::REDIRECTS_OPTION ] ) ) {
			unset( $notoptions[ LanguageRepository::REDIRECTS_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * The redirect map once a slug is renamed.
	 *
	 * @param array<mixed> $redirects Map as stored, old slug => new slug.
	 * @param string       $old_slug  Pre-rename slug.
	 * @param string       $new_slug  Post-rename slug.
	 * @return array<mixed>
	 */
	private static function redirect_map_after_rename( array $redirects, string $old_slug, string $new_slug ): array {
		// Compress chains: entries pointing at the old slug point at the new one, so one 301 lands at the end.
		foreach ( $redirects as $existing_old => $existing_new ) {
			if ( $existing_new === $old_slug ) {
				$redirects[ $existing_old ] = $new_slug;
			}
		}

		$redirects[ $old_slug ] = $new_slug;

		// The new slug is live: never a redirect source (left by an earlier rename or a slug swap).
		unset( $redirects[ $new_slug ] );

		// Drop self-mappings compression can leave.
		$redirects = array_filter(
			$redirects,
			static fn( $v, $k ) => $v !== $k,
			ARRAY_FILTER_USE_BOTH
		);

		// The last 50 only, to bound the option.
		if ( count( $redirects ) > 50 ) {
			$redirects = array_slice( $redirects, -50, null, true );
		}

		return $redirects;
	}

	/**
	 * Drop every cache that copies language columns into link rows: the autoloaded eager map has no TTL and is read
	 * first, so a stale slug there drops the language from hreflang, sitemap alternates and the switcher until
	 * invalidated at the write site.
	 *
	 * @return void
	 */
	private function flush_translation_link_caches(): void {
		$group_repo = new TranslationGroupRepository( $this->cache );
		$group_repo->invalidate_eager_link_map();
		TranslationGroupRepository::reset_static_caches();
		$this->cache->flush_translations();
	}

	/**
	 * Move a renamed slug wherever it is stored outside the languages table: per-language meta key suffixes, the
	 * `_perflocale_language` values (menus, orders incl. HPOS), the settings arrays, exchange rates and user preferences,
	 * with the caches the direct writes skip. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: what a slug rename migrates".
	 *
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return void
	 */
	private function migrate_slug_references( string $old_slug, string $new_slug ): void {
		global $wpdb;

		// 1. Attachment meta keys with the slug: a direct UPDATE of meta_key (indexed range, few rows, admin only), so the
		// slow-query sniff is a false positive.
		$post_meta_prefixes = [
			'_perflocale_alt_',
			'_perflocale_caption_',
			'_perflocale_description_',
		];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		foreach ( $post_meta_prefixes as $prefix ) {
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_key' => $prefix . $new_slug ],
				[ 'meta_key' => $prefix . $old_slug ],
				[ '%s' ],
				[ '%s' ]
			);
		}

		// 2. Linked-menu term meta, the same way.
		$wpdb->update(
			$wpdb->termmeta,
			[ 'meta_key' => '_perflocale_menu_' . $new_slug ],
			[ 'meta_key' => '_perflocale_menu_' . $old_slug ],
			[ '%s' ],
			[ '%s' ]
		);

		// 2b. Term meta `_perflocale_language` (a nav menu's language) holds the slug as value; SELECT first for the cache.
		$affected_term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
				'_perflocale_language',
				$old_slug
			)
		);

		if ( ! empty( $affected_term_ids ) ) {
			// A one-off admin migration: the meta_value warning does not justify a dedicated table.
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$wpdb->termmeta,
				[ 'meta_value' => $new_slug ],
				[
					'meta_key'   => '_perflocale_language',
					'meta_value' => $old_slug,
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			// Direct updates fire no meta hooks: delete the term meta caches (wp_cache_delete_multiple() on WP 6.0+).
			$ids_int = array_map( 'intval', (array) $affected_term_ids );

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $ids_int, 'term_meta' );
			} else {
				foreach ( $ids_int as $tid ) {
					wp_cache_delete( $tid, 'term_meta' );
				}
			}
		}

		// 2c. Post meta `_perflocale_language` (WooCommerce orders, for the order email language); generic for any consumer.
		$affected_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_perflocale_language',
				$old_slug
			)
		);

		if ( ! empty( $affected_post_ids ) ) {
			// A one-off admin migration (as the term meta above).
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => $new_slug ],
				[
					'meta_key'   => '_perflocale_language',
					'meta_value' => $old_slug,
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			$ids_int = array_map( 'intval', (array) $affected_post_ids );

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $ids_int, 'post_meta' );
			} else {
				foreach ( $ids_int as $pid ) {
					wp_cache_delete( $pid, 'post_meta' );
				}
			}
		}

		// 2d. The same tag in `{prefix}wc_orders_meta`, where HPOS stores it; whenever the table exists, as compatibility
		// mode keeps both copies. Missed, later status emails go out in the default language.
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$has_orders_meta = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) ) === $orders_meta;

		if ( $has_orders_meta ) {
			$affected_order_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %s',
					$orders_meta,
					'_perflocale_language',
					$old_slug
				)
			);

			if ( ! empty( $affected_order_ids ) ) {
				$wpdb->update(
					$orders_meta,
					[ 'meta_value' => $new_slug ],
					[
						'meta_key'   => '_perflocale_language',
						'meta_value' => $old_slug,
					],
					[ '%s' ],
					[ '%s', '%s' ]
				);

				// As WooCommerce invalidates a direct meta write, plus the cached order object through its own OrderCache (the keys
				// are namespaced: deleting the bare id deletes nothing and the old slug stays served for an hour).
				$order_cache = null;

				if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
					try {
						$order_cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );
					} catch ( \Throwable $e ) {
						$order_cache = null;   // Container not booted, or the class not registered.
					}
				}

				foreach ( array_map( 'intval', (array) $affected_order_ids ) as $oid ) {
					if ( class_exists( '\WC_Order' ) && method_exists( '\WC_Order', 'generate_meta_cache_key' ) ) {
						wp_cache_delete( \WC_Order::generate_meta_cache_key( $oid, 'orders' ), 'orders' );
					}

					if ( null !== $order_cache ) {
						$order_cache->remove( $oid );
					}

					// For a WooCommerce without OrderCache; harmless otherwise.
					wp_cache_delete( $oid, 'orders' );
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		// 3. Settings and 4b. WooCommerce's fetched exchange rates (else 1:1 until the next sync), both with the
		// compare-and-set of change_option(), so a save committed since the read is not undone.
		$settings_written = $this->change_option(
			'perflocale_settings',
			static fn( array $settings ): array => self::settings_after_rename( $settings, $old_slug, $new_slug )
		);

		if ( $settings_written ) {
			// Reload the Settings singleton before anything reads it again in this request.
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$plugin->get( 'settings' )->reset_cache();
			}
		}

		$this->change_option(
			'perflocale_exchange_rates',
			static function ( array $rates ) use ( $old_slug, $new_slug ): array {
				if ( array_key_exists( $old_slug, $rates ) ) {
					$rates[ $new_slug ] = $rates[ $old_slug ];
					unset( $rates[ $old_slug ] );
				}

				return $rates;
			},
			false
		);

		// 4. Per-user hidden-language arrays (Strings and Translations columns): only the rows holding the slug; network-wide
		// per user, as delete()'s sweep.
		$user_meta_keys = [
			'perflocale_strings_hidden_langs',
			'perflocale_translations_hidden_langs',
		];

		foreach ( $user_meta_keys as $meta_key ) {
			// One-off per-key scan.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta}
					WHERE meta_key = %s AND meta_value LIKE %s",
					$meta_key,
					'%' . $wpdb->esc_like( $old_slug ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$arr = maybe_unserialize( $row->meta_value );

				if ( ! is_array( $arr ) ) {
					continue;
				}

				$mutated = false;
				$next    = [];

				foreach ( $arr as $entry ) {
					if ( $entry === $old_slug ) {
						$next[]  = $new_slug;
						$mutated = true;
					} else {
						$next[] = $entry;
					}
				}

				if ( $mutated ) {
					update_user_meta( (int) $row->user_id, $meta_key, $next );
				}
			}
		}
	}

	/**
	 * Update a language.
	 *
	 * @param int $id Language ID.
	 * @param array<string, mixed> $data Data to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		self::$refused = '';
		$old           = $this->repo->find( $id );

		if ( ! $old ) {
			return false;
		}

		$sanitized = self::sanitize_data( $data );
		$before    = (array) $old;
		$old_slug  = isset( $before['slug'] ) && is_string( $before['slug'] ) ? $before['slug'] : '';
		$posted    = isset( $sanitized['slug'] ) && is_string( $sanitized['slug'] ) ? $sanitized['slug'] : '';

		// An unchanged slug is never written: a resubmitted form, or a resent statement, would undo a concurrent rename.
		if ( isset( $sanitized['slug'] ) && $posted === $old_slug ) {
			unset( $sanitized['slug'] );
		}

		$slug_changed = isset( $sanitized['slug'] );
		$new_slug     = $slug_changed ? $posted : $old_slug;

		// A changed slug matches SLUG_PATTERN ('' included); an unchanged legacy slug keeps saving the other columns.
		if ( $slug_changed && ! preg_match( LanguageRepository::SLUG_PATTERN, $new_slug ) ) {
			return false;
		}

		// Name and native name ride on every cached link row too.
		$labels_changed = ( isset( $sanitized['name'] ) && $sanitized['name'] !== ( $before['name'] ?? null ) )
			|| ( isset( $sanitized['native_name'] ) && $sanitized['native_name'] !== ( $before['native_name'] ?? null ) );

		// An is_active toggle (the REST PATCH sends it alone) changes routing: drop the eager map too, which has no TTL.
		$active_changed = isset( $sanitized['is_active'] )
			&& (int) $sanitized['is_active'] !== (int) ( $before['is_active'] ?? 0 );

		if ( $slug_changed ) {
			// Guarded by the old slug, '' included (a legacy row): never by the id alone.
			$written = $this->slug_change( $id, $old_slug, $new_slug, $sanitized );

			if ( null === $written ) {
				return false;
			}

			$result = $written ? 1 : false;
		} elseif ( [] === $sanitized ) {
			return true;
		} else {
			// The id alone: SET never holds the slug here.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->update(
				$this->table(),
				$sanitized,
				[ 'id' => $id ],
				null,
				[ '%d' ]
			);
		}

		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		// Only after a successful update, so a UNIQUE collision records no 301 for a rename that did not land.
		if ( $result !== false ) {
			if ( $slug_changed && '' !== $old_slug ) {
				$this->apply_slug_rename( $id, $old_slug, $new_slug );
			} elseif ( $slug_changed || $labels_changed || $active_changed ) {
				// From '' no 301 (keyed by '' it never fires) and no LIKE '%%' migration; the newly live slug stops being a
				// redirect source. A label or active edit never touches redirects: its slug may be renamed since its read.
				if ( $slug_changed ) {
					$this->drop_stale_slug_redirect( $new_slug );
				}
				$this->flush_translation_link_caches();
			}
		}

		$language = $this->repo->find( $id );

		/** @hook perflocale/language/updated Fires after a language is updated. */
		do_action( 'perflocale/language/updated', $language, $before );

		return $result !== false;
	}

	/**
	 * Rewrite sort_order for these ids in their order, starting at `$offset + 1` (a paginated slice keeps other pages'
	 * range). Every id is validated before any write, so a bogus one cannot half-apply.
	 *
	 * @param array<int, int> $ordered_ids Language IDs in their new visual order.
	 * @param int             $offset      Starting position (0-based). Sort orders begin at `offset + 1`.
	 * @return int Number of rows updated.
	 */
	public function reorder( array $ordered_ids, int $offset = 0 ): int {
		$ordered_ids = array_values( array_map( 'intval', $ordered_ids ) );

		if ( empty( $ordered_ids ) ) {
			return 0;
		}

		// Every id must exist; duplicates dropped.
		$ordered_ids  = array_values( array_unique( $ordered_ids ) );
		$placeholders = implode( ',', array_fill( 0, count( $ordered_ids ), '%d' ) );

		// In the global lock order (Inputs::lock_languages()); the check reads under that lock. Guarded like delete(): after a
		// lost connection nothing more is sent but the ROLLBACK, and an unconfirmed COMMIT counts as not written.
		$offset    = max( 0, $offset );
		$attempted = false;
		$outcome   = \PerfLocale\Database\Tx::run(
			'language',
			// A closure, not an arrow function: `$attempted` must reach this scope by reference.
			function ( \PerfLocale\Database\Tx $tx ) use ( $ordered_ids, $offset, $placeholders, &$attempted ): bool {
				if ( ! \PerfLocale\Router\UrlBases\Inputs::lock_languages() || ! $tx->guard()->holds() ) {
					return false;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
				// Class-owned table, placeholders from validated int ids.
				$existing = (array) $this->wpdb->get_col(
					$this->wpdb->prepare( "SELECT id FROM %i WHERE id IN ($placeholders)", array_merge( [ $this->table() ], $ordered_ids ) )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( ! $tx->guard()->holds() || count( $existing ) !== count( $ordered_ids ) ) {
					return false;
				}

				// One UPDATE ... CASE id WHEN ... END instead of one query per language.
				$cases = [];
				$args  = [];

				foreach ( $ordered_ids as $position => $id ) {
					$cases[] = 'WHEN %d THEN %d';
					$args[]  = $id;
					$args[]  = $offset + $position + 1;
				}

				$args      = array_merge( $args, $ordered_ids );
				$and       = $tx->and();
				$attempted = true;

				// Every part is plugin-built ('WHEN %d THEN %d', array_fill('%d')); $args carries the ints; $and is the guard condition.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$result = $this->wpdb->query(
					( $this->wpdb->prepare(
						'UPDATE %i SET sort_order = CASE id ' . implode( ' ', $cases ) . " END WHERE id IN ({$placeholders})",
						array_merge( [ $this->table() ], $args )
					) ?? '' ) . $and
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				return false !== $result;
			}
		);

		// A loss before the UPDATE: nothing was written, as a failed lock read before (no hook).
		if ( ! $attempted ) {
			return 0;
		}

		// flush_languages() clears the whole group, per-id entries included.
		$updated = 'done' === $outcome->status ? count( $ordered_ids ) : 0;

		$this->cache->flush_languages();

		/** @hook perflocale/languages/reordered Fires after the language list is reordered. */
		do_action( 'perflocale/languages/reordered', $ordered_ids, $offset );

		return $updated;
	}

	/**
	 * Count what delete() would remove for a language, for the delete preview: each key mirrors one cascade step of
	 * delete(); keep the two in step.
	 *
	 * @param int $id Language ID.
	 * @return array<string, int> Table-ish label => row count.
	 */
	public function count_cascade( int $id ): array {
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$counts = [
			'translation_links'   => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', $links_table, $id )
			),
			// Groups left empty once this language's links go, as delete()'s group GC removes them (string groups excluded).
			'translation_groups'  => (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i g
					 WHERE g.type != 'string'
					   AND EXISTS ( SELECT 1 FROM %i l1 WHERE l1.group_id = g.id AND l1.language_id = %d )
					   AND NOT EXISTS ( SELECT 1 FROM %i l2 WHERE l2.group_id = g.id AND l2.language_id != %d )",
					$groups_table,
					$links_table,
					$id,
					$links_table,
					$id
				)
			),
			'string_translations' => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', Schema::table( 'string_translations' ), $id )
			),
			'slug_translations'   => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', Schema::table( 'slug_translations' ), $id )
			),
		];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $counts;
	}

	/**
	 * Count the content that blocks deleting a language: its posts, which would become default-language duplicates with
	 * split WooCommerce stock (trash them or deactivate the language). Attachments, terms and templates are reported
	 * only. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: deleting a language".
	 *
	 * @param int $id Language ID.
	 * @return array{posts: array<string, int>, attachments: int, terms: int, templates: int}|null
	 *         Blocking post counts by post type, plus the informational counts;
	 *         null when the count query failed (callers must refuse).
	 */
	public function delete_blockers( int $id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uncached on purpose: a delete decision reads the live rows; tables bound through %i.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT g.type AS group_type, p.post_type AS post_type, COUNT(*) AS n
				 FROM %i l
				 INNER JOIN %i g ON g.id = l.group_id
				 LEFT JOIN %i p ON g.type = 'post' AND p.ID = l.object_id
				 WHERE l.language_id = %d
				   AND (
				     g.type = 'term'
				     OR ( g.type = 'post' AND p.ID IS NOT NULL AND p.post_status NOT IN ( 'trash', 'auto-draft' ) )
				   )
				 GROUP BY g.type, p.post_type",
				Schema::table( 'translation_links' ),
				Schema::table( 'translation_groups' ),
				$this->wpdb->posts,
				$id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) || $this->wpdb->last_error !== '' ) {
			return null;
		}

		$blockers = [
			'posts'       => [],
			'attachments' => 0,
			'terms'       => 0,
			'templates'   => 0,
		];

		foreach ( $rows as $row ) {
			$count = (int) $row->n;

			if ( (string) $row->group_type === 'term' ) {
				$blockers['terms'] += $count;
			} elseif ( (string) $row->post_type === 'attachment' ) {
				$blockers['attachments'] += $count;
			} elseif ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) $row->post_type ) ) {
				$blockers['templates'] += $count;
			} else {
				$blockers['posts'][ (string) $row->post_type ] = $count;
			}
		}

		return $blockers;
	}

	/**
	 * Delete a language and everything that references it.
	 *
	 * @param int $id Language ID.
	 * @return bool False when not deleted, or not confirmed (refused() 'delete_unknown').
	 */
	public function delete( int $id ): bool {
		self::$refused = '';
		$language      = $this->repo->find( $id );

		if ( ! $language ) {
			return false;
		}

		// Every cascade DELETE and the URL bases release in one unit, so a timeout or lost connection cannot leave links
		// pointing at a removed language or a free slug holding its data; any failure rolls back and returns false.
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$slug_table   = Schema::table( 'slug_translations' );
		$wpdb         = $this->wpdb;
		$table        = $this->table();
		$slug_now     = null;
		$read_slug    = is_string( $language->slug ) ? $language->slug : '';
		/**
		 * Slug rows the body deletes, read by the invalidation after COMMIT.
		 *
		 * @var array<int, \stdClass> $slug_rows
		 */
		$slug_rows = [];
		/**
		 * What the body's release wrote, read by the invalidation and after the unit.
		 *
		 * @var array{values: string, map: array{0: array<mixed>, 1: array<mixed>}|null}|null $released
		 */
		$released = null;

		$outcome = \PerfLocale\Database\Tx::run(
			'language',
			// A closure, not an arrow function: the results must reach this scope by reference.
			function ( \PerfLocale\Database\Tx $tx ) use ( $wpdb, $table, $id, $links_table, $groups_table, $slug_table, $read_slug, &$slug_now, &$slug_rows, &$released ): bool {
				try {
					// Every DELETE is guarded: resent on a new connection (the server rolled back) it matches nothing.
					$and = $tx->and();

					// Global lock order: every language row by id, then (below) the values row and the map row, the cascade, other options.
					if ( ! \PerfLocale\Router\UrlBases\Inputs::lock_languages() ) {
						throw new \RuntimeException( 'delete_language: failed to lock the languages rows' );
					}

					$tx->check();

					// The slug as the row holds it, under a row lock: a concurrent rename is seen here or waits and cleans up after itself.
					// Row lock on MySQL only (see ConnectionGuard::lock_suffix()).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table bound with %i; the lock suffix is a literal.
					$slug_now = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE id = %d' . \PerfLocale\Database\ConnectionGuard::lock_suffix(), $table, $id ) );
					$tx->check();

					// A gone row was deleted by another request.
					if ( 0 === $wpdb->num_rows ) {
						throw new \RuntimeException( 'delete_language: the languages row is gone' );
					}

					$slug = is_string( $slug_now ) ? $slug_now : $read_slug;

					// The values row, then the map row, locked before the cascade (lock order); a failed read proves nothing.
					if ( '' !== $slug ) {
						$values_row = \PerfLocale\Database\OptionRead::of( \PerfLocale\Router\UrlBases\Values::OPTION, true );
						$tx->check();
						$map_row = \PerfLocale\Database\OptionRead::of( \PerfLocale\Router\UrlBases\Publication::MAP, true );
						$tx->check();

						if ( $values_row->failed || $map_row->failed ) {
							throw new \RuntimeException( 'delete_language: the URL bases rows could not be read' );
						}

						// The release must roll back with the delete: refused on a table without transactions (MyISAM wp_options).
						if ( self::url_bases_data( $values_row, $map_row, $slug ) ) {
							$atomic = $this->coupled_atomic();
							$tx->check();

							if ( true !== $atomic ) {
								self::$refused = false === $atomic ? 'delete_data' : '';

								throw new \RuntimeException( 'delete_language: URL bases data and a table without transactions' );
							}
						}
					}

					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; $and is the guard condition.
					// The locked row: exactly one (0 without an error is a statement resent on a new connection).
					if ( 1 !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $table, $id ) . $and ) ) {
						throw new \RuntimeException( 'delete_language: failed to delete languages row' );
					}

					$tx->check();

					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE language_id = %d', $links_table, $id ) . $and ) ) {
						throw new \RuntimeException( 'delete_language: failed to delete translation_links' );
					}

					$tx->check();

					// Remove the non-string groups left empty, in one pass. One table per DELETE: the SQLite integration's 2.2.x legacy
					// driver rejects `DELETE g FROM` and mishandles a JOIN.
					if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE type != 'string' AND id NOT IN ( SELECT group_id FROM %i )", $groups_table, $links_table ) . $and ) ) {
						throw new \RuntimeException( 'delete_language: failed to garbage-collect orphan translation_groups' );
					}

					$tx->check();
					( new StringTranslationRepository( $this->cache ) )->delete_for_language( $id, $and );
					$tx->check();

					// The slug rows' identifiers before their DELETE, for the cache invalidation after the commit.
					$slug_rows_raw = $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, object_id FROM %i WHERE language_id = %d', $slug_table, $id ) );
					$tx->check();
					$slug_rows = (array) $slug_rows_raw;

					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE language_id = %d', $slug_table, $id ) . $and ) ) {
						throw new \RuntimeException( 'delete_language: failed to delete slug_translations' );
					}

					$tx->check();
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

					// The slug's URL bases data, last before COMMIT: an insert or rename to the freed slug waits on the unique index
					// until then. Never inherited by a language added later under the same slug.
					if ( '' !== $slug ) {
						$guard    = $tx->guard();
						$released = self::release_slugs( [ $slug ], $guard, static fn(): bool => self::no_language_row( $wpdb, $table, 'id', $id, true ) && $guard->holds() );
						$tx->check();
					}

					return true;
				} catch ( \Throwable $e ) {
					// Any failure (a Stop included): rolled back by the unit.
					return false;
				}
			},
			// COMMIT unconfirmed: a row still there proves the rollback; no row is no proof this transaction committed (another
			// request may have deleted the id), so unknown: no post-COMMIT cleanup.
			static function () use ( $wpdb, $table, $id ): ?bool {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $id ) );

				return '' === $wpdb->last_error && null !== $found ? false : null;
			},
			'',
			// Committed or maybe committed (unknown: every cache, no destructive step), before any listener of the held writes runs:
			// no request may route to or link a deleted id, or serve released URL bases or removed links and slugs, from a cache.
			// Each step runs when another throws; the two that notify listeners last.
			function () use ( $id, &$slug_rows, &$released ): void {
				$cache = $this->cache;

				\PerfLocale\Database\Tx::each(
					static fn() => $cache->flush_languages(),
					static fn() => $cache->delete( "language_{$id}", 'perflocale_langs' ),
					static function () use ( &$released ): void {
						if ( 'stored' === ( $released['values'] ?? '' ) ) {
							\PerfLocale\Router\UrlBases\Publication::reset_dry_run();
						}
					},
					static function () use ( &$released ): void {
						if ( null !== ( $released['map'] ?? null ) ) {
							\PerfLocale\Router\UrlBases\Publication::map_released();
						}
					},
					// Per-row L2 keys as SlugTranslationRepository::get_slug() builds them: "slug_{type}_{id}_{lang}" in 'perflocale_slugs'.
					static function () use ( $cache, $id, &$slug_rows ): void {
						foreach ( (array) $slug_rows as $slug_row ) {
							$cache->delete( sprintf( 'slug_%s_%d_%d', (string) $slug_row->object_type, (int) $slug_row->object_id, $id ), 'perflocale_slugs' );
						}
					},
					static fn() => SlugTranslationRepository::reset_static_caches(),
					// The eager map and translations_* caches still hold the removed links (hreflang, the switcher).
					static fn() => ( new TranslationGroupRepository( $cache ) )->invalidate_eager_link_map(),
					static fn() => TranslationGroupRepository::reset_static_caches(),
					static fn() => $cache->flush_slugs(),
					static fn() => $cache->flush_translations()
				);
			}
		);

		// Not committed or unknown: every cached form of the values and map rows written went (settle).
		if ( [] !== $outcome->forgotten ) {
			\PerfLocale\Router\UrlBases\Publication::reset_dry_run();
		}

		if ( 'done' !== $outcome->status ) {
			wp_cache_delete( \PerfLocale\Router\UrlBases\Values::OPTION, 'options' );
			self::$refused = 'unknown' === $outcome->status ? 'delete_unknown' : self::$refused;

			return false;
		}

		// The slug as the locked row held it: a concurrent rename was seen there.
		if ( is_string( $slug_now ) && $slug_now !== $read_slug ) {
			$language       = clone $language;
			$language->slug = $slug_now;
		}

		// As Values::update() after a store.
		if ( 'stored' === ( $released['values'] ?? '' ) ) {
			\PerfLocale\Router\UrlBases\Lifecycle::clear_none();
		}

		// The map write's actions only after COMMIT (its caches went above).
		if ( null !== ( $released['map'] ?? null ) ) {
			\PerfLocale\Router\UrlBases\Publication::slug_released( $released['map'][0], $released['map'][1] );
		}

		// Source-map rows of the removed groups, best effort and after the commit (inside, a failure ends the transaction on
		// SQLite); the importer's dead-group guard covers any left.
		$source_map_table = Schema::table( 'migration_source_map' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE group_id NOT IN ( SELECT id FROM %i )',
				$source_map_table,
				$groups_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Everything here is keyed by the slug; with '' the meta sweeps would match every row: skipped.
		if ( '' !== (string) $language->slug ) {
			// Slug-keyed attachment and menu meta, through delete_metadata( $type, 0, $key, '', true ) so the meta caches go too.
			$post_meta_prefixes = [
				'_perflocale_alt_',
				'_perflocale_caption_',
				'_perflocale_description_',
			];

			foreach ( $post_meta_prefixes as $prefix ) {
				delete_metadata( 'post', 0, $prefix . $language->slug, '', true );
			}

			delete_metadata( 'term', 0, '_perflocale_menu_' . $language->slug, '', true );

			// `_perflocale_language` term meta holds the slug as value: remove every row with it.
			delete_metadata( 'term', 0, '_perflocale_language', $language->slug, true );

			// Hidden-language user meta, as the rename does but removing the slug, only rows containing it. Unprefixed, so on a
			// network one array per user serves every site (as core's column preferences).
			$user_meta_keys = [
				'perflocale_strings_hidden_langs',
				'perflocale_translations_hidden_langs',
			];

			foreach ( $user_meta_keys as $meta_key ) {
				// usermeta is core's own table; the key and slug go through %s.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT umeta_id, user_id, meta_value FROM {$this->wpdb->usermeta}
						WHERE meta_key = %s AND meta_value LIKE %s",
						$meta_key,
						'%' . $this->wpdb->esc_like( $language->slug ) . '%'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( ! is_array( $rows ) ) {
					continue;
				}

				foreach ( $rows as $row ) {
					$arr = maybe_unserialize( $row->meta_value );

					if ( ! is_array( $arr ) ) {
						continue;
					}

					$next = array_values(
						array_filter(
							$arr,
							static fn( $entry ): bool => $entry !== $language->slug
						)
					);

					if ( $next !== $arr ) {
						update_user_meta( (int) $row->user_id, $meta_key, $next );
					}
				}
			}
		}

		// The settings, exchange rates and slug redirects that name the slug.
		$this->drop_slug_options( is_string( $language->slug ) ? $language->slug : '' );

		// The generated `<domain>-<locale>.l10n.php` files of this locale.
		$locale = isset( $language->locale ) ? (string) $language->locale : '';

		if ( $locale !== '' ) {
			$upload_dir = wp_upload_dir();
			$base       = $upload_dir['basedir'] . '/perflocale/translations';
			$pattern    = $base . '/*-' . sanitize_file_name( $locale ) . '.l10n.php';
			$files      = glob( $pattern );

			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					wp_delete_file( $file );
				}
			}
		}

		// Changes held for review in this language can no longer be approved; while ReSync is off the housekeeping
		// closes them once it is back on.
		if ( \PerfLocale\Util\ResyncSwitch::on() && \PerfLocale\Automation\ChangeService::available() ) {
			$closed = ( new PendingChangeRepository() )->close_pending_for_language( $id );

			// A failed close may follow closed windows; the housekeeping's orphan pass closes the rest.
			if ( null === $closed ) {
				\PerfLocale\Automation\ChangeService::log( sprintf( 'The held changes of deleted language %d could not all be closed; the daily housekeeping closes the rest.', $id ) );
			}

			if ( 0 !== $closed ) {
				\PerfLocale\Automation\ChangeService::mark_badge_dirty();
				\PerfLocale\Automation\ChangeService::end_batch();
			}
		}

		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		/** @hook perflocale/language/deleted Fires after a language is deleted. */
		do_action( 'perflocale/language/deleted', $id, $language->slug );

		return true;
	}

	/**
	 * Make a language the default (unsetting the current one).
	 *
	 * @param int $id Language ID to make default.
	 * @return bool
	 */
	public function set_default( int $id ): bool {
		// Refuse a missing or inactive target first: update() answers 0 for no match, which would leave no default, and an
		// inactive default reads as null.
		$target = $this->repo->find( $id );

		if ( $target === null || empty( $target->is_active ) ) {
			return false;
		}

		$old_default    = $this->repo->get_default();
		$old_default_id = $old_default ? (int) $old_default->id : 0;

		// Both UPDATEs in one unit, so a failure between them cannot leave the site without a default. Guarded like
		// delete(): a statement resent on a new connection (autocommit) matches nothing.
		$outcome = \PerfLocale\Database\Tx::run(
			'language',
			function ( \PerfLocale\Database\Tx $tx ) use ( $id ): bool {
				$and = $tx->and();

				// The global lock order first (Inputs::lock_languages()): the demotion alone would lock the old default first.
				if ( ! \PerfLocale\Router\UrlBases\Inputs::lock_languages() ) {
					return false;
				}

				$tx->check();

				// Unset every default (any non-zero value, as readers count it).
				$unset_sql = $this->wpdb->prepare( 'UPDATE %i SET is_default = 0 WHERE is_default <> 0', $this->table() );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared on the line above; $and is the guard condition (a checked marker).
				$unset_ok = is_string( $unset_sql ) ? $this->wpdb->query( $unset_sql . $and ) : false;
				$tx->check();

				if ( false === $unset_ok ) {
					return false;
				}

				// The new default, only while still active (the early check ran before the lock).
				$set_sql = $this->wpdb->prepare( 'UPDATE %i SET is_default = 1 WHERE id = %d AND is_active <> 0', $this->table(), $id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared on the line above; $and is the guard condition (a checked marker).
				$set_ok = is_string( $set_sql ) ? $this->wpdb->query( $set_sql . $and ) : false;
				$tx->check();

				// False or 0 rows: the target went or was deactivated meanwhile; committing would leave no active default.
				return false !== $set_ok && (int) $set_ok >= 1;
			}
		)->status;

		// COMMIT unconfirmed (rolled back, or landed with its reply lost): never reported as done; the caches go either way.
		if ( 'unknown' === $outcome ) {
			$this->cache->flush_languages();
		}

		if ( 'done' !== $outcome ) {
			return false;
		}

		$this->cache->flush_languages();

		// Flush the per-language caches so is_default is fresh.
		if ( $old_default_id > 0 ) {
			$this->cache->delete( "language_{$old_default_id}", 'perflocale_langs' );
		}

		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		$new_default = $this->repo->find( $id );

		/** @hook perflocale/default_language/changed Fires when the default language changes. */
		do_action( 'perflocale/default_language/changed', $new_default, $old_default );

		return true;
	}

	/**
	 * Sanitize language data for insert and update; public so the data import stores an untrusted user's language row
	 * by these rules. Sanitizes only: the slug shape is checked by insert() and update().
	 *
	 * @internal
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed> Sanitized data.
	 */
	public static function sanitize_data( array $data ): array {
		$sanitized = [];

		$string_fields = [ 'slug', 'locale', 'name', 'native_name', 'flag', 'date_format', 'time_format', 'text_direction' ];
		$int_fields    = [ 'is_default', 'is_active', 'sort_order' ];

		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		foreach ( $int_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = absint( $data[ $field ] );
			}
		}

		// Lowercase, trimmed to the VARCHAR(10) column, so callers see the stored value, not a silent truncation.
		if ( isset( $sanitized['slug'] ) ) {
			$sanitized['slug'] = mb_substr( sanitize_key( $sanitized['slug'] ), 0, 10 );
		}

		if ( isset( $sanitized['locale'] ) ) {
			$sanitized['locale'] = mb_substr( $sanitized['locale'], 0, 20 );
		}

		if ( isset( $sanitized['flag'] ) ) {
			$sanitized['flag'] = mb_substr( $sanitized['flag'], 0, 10 );
		}

		// Text direction.
		if ( isset( $sanitized['text_direction'] ) && ! in_array( $sanitized['text_direction'], [ 'ltr', 'rtl' ], true ) ) {
			$sanitized['text_direction'] = 'ltr';
		}

		return $sanitized;
	}
}
