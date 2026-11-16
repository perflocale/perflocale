<?php
/**
 * Strings-screen cell writes conditional on the row as the save read it.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

use PerfLocale\Admin\Typed;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\SourceType;

defined( 'ABSPATH' ) || exit;

/**
 * A person's cell save: never replaces a value another person stored after the read; replaces a machine translation.
 * Loaded by the Strings save and by StringTarget's compare-and-set writes (Apply, Undo).
 *
 * @since 1.0.7.5
 * @phpstan-type UnitHow array{over_mt: bool, label: SourceType, strict: bool, check: callable|null, then: callable|null, link: string|null, same: bool}
 */
final class StringCellWrite {

	/**
	 * Save a cell while the row is as read, or over a machine translation, then label the link $label. MySQL/MariaDB hold
	 * the link row, SQLite a cell lock, for the whole unit, so another person's save never lands between value and label.
	 *
	 * @param TranslationGroupRepository $repo        Group/link repository.
	 * @param int                        $group_id    The string's group (a string group).
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param string|null                $read_text   Form 1 as read; null when there was no row.
	 * @param string|null                $read_forms  extra_forms as read.
	 * @param string                     $text        Form 1 to write, not ''.
	 * @param string|false|null          $forms       extra_forms to write; false leaves them, and they are not compared.
	 * @param bool                       $over_mt     Only over a machine translation (the page showed an older value).
	 * @param SourceType                 $label       Label the unit sets on the link.
	 * @param bool                       $strict      Only while the row is as read, never over a machine translation that moved.
	 * @param callable|null              $check       fn(): bool, run inside the unit before the value statement; false refuses it.
	 * @param callable|null              $then        fn( string $guarded ): bool, run inside the unit after its statements with the
	 *                                                unit's connection condition (' AND ...' or ''); false rolls it back.
	 * @param string|null                $link        Link status set in the unit; a cell with no link is linked in it (null: the
	 *                                                caller links an 'unlinked' cell, status 'translated').
	 * @return string 'saved', 'unlinked' (written; the caller links it), 'same', 'stale', 'mismatch', 'refused' or 'failed'.
	 */
	public static function save( TranslationGroupRepository $repo, int $group_id, int $string_id, int $language_id, ?string $read_text, ?string $read_forms, string $text, string|false|null $forms, bool $over_mt, SourceType $label = SourceType::Manual, bool $strict = false, ?callable $check = null, ?callable $then = null, ?string $link = null ): string {
		$outcome = 'failed';
		$first   = null;

		// A unit lost to the lock, the database or a reconnect, or whose COMMIT was not acknowledged: one more try.
		for ( $try = 0; $try < 2 && ( 'failed' === $outcome || 'unsure' === $outcome || 'unsure_label' === $outcome ); $try++ ) {
			$outcome = self::unit(
				$repo,
				$group_id,
				$string_id,
				$language_id,
				/** @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence */
				static fn( array $fence ): int|false => self::write( $string_id, $language_id, $read_text, $read_forms, $text, $forms, $fence ),
				/** @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence */
				static fn( array $fence ): int|false => self::write_over( $string_id, $language_id, $text, $forms, $fence ),
				$text,
				$forms,
				[
					'over_mt' => $over_mt,
					'label'   => $label,
					'strict'  => $strict,
					'check'   => $check,
					'then'    => $then,
					'link'    => $link,
					'same'    => ! $over_mt && $read_text === $text && ( false === $forms || $read_forms === $forms ),
				]
			);
			$first ??= $outcome;
		}

		$outcome = self::retried( $repo, (string) $first, $outcome, $group_id, $string_id, $language_id, $text, $forms, $label );

		return self::why_not( $outcome, $string_id, $language_id, $read_text, $read_forms, $text, $forms );
	}

	/**
	 * The Strings screen's save(), only while the string row exists (checked in the unit); a row deleted since the read
	 * (the identity switch-over folds an empty twin) is saved once to the one other row of its tuple, else 'gone' (nothing written).
	 *
	 * @param TranslationGroupRepository             $repo        Group/link repository.
	 * @param int                                    $group_id    The string's group (a string group).
	 * @param int                                    $string_id   String.
	 * @param int                                    $language_id Language.
	 * @param string|null                            $read_text   Form 1 as read; null when there was no row.
	 * @param string|null                            $read_forms  extra_forms as read.
	 * @param string                                 $text        Form 1 to write, not ''.
	 * @param string|false|null                      $forms       extra_forms to write; false leaves them.
	 * @param bool                                   $over_mt     Only over a machine translation.
	 * @param array{0: string, 1: string, 2: string} $tuple       The row's domain, context and original as read.
	 * @return array{0: string, 1: int, 2: int} save()'s outcome or 'gone', and the string and group written.
	 */
	public static function save_live( TranslationGroupRepository $repo, int $group_id, int $string_id, int $language_id, ?string $read_text, ?string $read_forms, string $text, string|false|null $forms, bool $over_mt, array $tuple ): array {
		$read    = 'ok';
		$outcome = self::save( $repo, $group_id, $string_id, $language_id, $read_text, $read_forms, $text, $forms, $over_mt, SourceType::Manual, false, self::alive( $string_id, $read ) );

		if ( 'refused' !== $outcome ) {
			return [ $outcome, $string_id, $group_id ];
		}

		$to = 'ok' === $read ? self::survivor( $string_id, $tuple ) : false;

		if ( null === $to || false === $to || ! $repo->is_string_group( $to[1] ) ) {
			return [ false === $to ? 'failed' : 'gone', $string_id, $group_id ];
		}

		// The same as-read condition: the person saw the twin's cell, so a value the survivor holds is reported, not replaced.
		$outcome = self::save( $repo, $to[1], $to[0], $language_id, $read_text, $read_forms, $text, $forms, $over_mt, SourceType::Manual, false, self::alive( $to[0], $read ) );

		return [ 'refused' !== $outcome ? $outcome : ( 'ok' === $read ? 'gone' : 'failed' ), $to[0], $to[1] ];
	}

	/**
	 * A unit check: the string row still exists, by a locking read on MySQL/MariaDB (it waits for a fold's batch).
	 *
	 * @param int    $string_id String.
	 * @param string $read      Set to 'error' on a failed read.
	 * @return callable(): bool
	 */
	private static function alive( int $string_id, string &$read ): callable {
		return static function () use ( $string_id, &$read ): bool {
			$wpdb = Typed::wpdb();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Inside the unit, by primary key; the suffix is a literal.
			$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d' . ConnectionGuard::lock_suffix(), Schema::table( 'strings' ), $string_id ) );
			$read = '' === $wpdb->last_error ? 'ok' : 'error';

			return 'ok' === $read && null !== $id;
		};
	}

	/**
	 * The one row other than $gone stored for the tuple under either hash rule, matched byte for byte.
	 *
	 * @param int                                    $gone  The deleted string id.
	 * @param array{0: string, 1: string, 2: string} $tuple Domain, context, original.
	 * @return array{0: int, 1: int}|false|null [ id, group_id ]; null: none or more than one; false: the read failed.
	 */
	private static function survivor( int $gone, array $tuple ): array|false|null {
		$wpdb          = Typed::wpdb();
		[ $new, $old ] = StringRepository::identity_hashes( ...$tuple );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the fold's commit.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, group_id, domain, context, original FROM %i WHERE original_hash IN (%s, %s) AND id <> %d', Schema::table( 'strings' ), $new, $old, $gone ), ARRAY_A );

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return false;
		}

		$rows = array_values( array_filter( Typed::rows( $rows ), static fn( array $r ): bool => [ $r['domain'] ?? null, $r['context'] ?? null, $r['original'] ?? null ] === $tuple ) );

		return 1 === count( $rows ) ? [ Typed::int( $rows[0]['id'] ?? 0 ), Typed::int( $rows[0]['group_id'] ?? 0 ) ] : null;
	}

	/**
	 * Clear a cell while the row is as read, or over a machine translation, then drop the link while no value row exists.
	 *
	 * @param TranslationGroupRepository $repo        Group/link repository.
	 * @param int                        $group_id    The string's group (a string group).
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param string|null                $read_text   Form 1 as read; null when there was no row.
	 * @param string|false|null          $read_forms  extra_forms as read; false: not compared.
	 * @param bool                       $over_mt     Only over a machine translation.
	 * @param bool                       $strict      Only while the row is as read, never over a machine translation that moved.
	 * @param callable|null              $check       fn(): bool, run inside the unit before the value statement; false refuses it.
	 * @param callable|null              $then        fn( string $guarded ): bool, as save()'s.
	 * @return string 'saved' (deleted), 'none' (no row was read), 'same', 'stale', 'mismatch', 'refused' or 'failed'.
	 */
	public static function remove( TranslationGroupRepository $repo, int $group_id, int $string_id, int $language_id, ?string $read_text, string|false|null $read_forms, bool $over_mt, bool $strict = false, ?callable $check = null, ?callable $then = null ): string {
		$outcome = 'failed';
		$first   = null;

		for ( $try = 0; $try < 2 && ( 'failed' === $outcome || 'unsure' === $outcome || 'unsure_label' === $outcome ); $try++ ) {
			$outcome = self::unit(
				$repo,
				$group_id,
				$string_id,
				$language_id,
				/** @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence */
				static fn( array $fence ): int|false => null === $read_text ? 2 : self::clear( $string_id, $language_id, $read_text, $read_forms, $fence ),
				/** @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence */
				static fn( array $fence ): int|false => self::delete_over( $string_id, $language_id, $fence ),
				null,
				false,
				[
					'over_mt' => $over_mt,
					'label'   => SourceType::Manual,
					'strict'  => $strict,
					'check'   => $check,
					'then'    => $then,
					'link'    => null,
					'same'    => false,
				]
			);
			$first ??= $outcome;
		}

		$outcome = self::retried( $repo, (string) $first, $outcome, $group_id, $string_id, $language_id, null, false, SourceType::Manual );

		if ( 'saved' === $outcome && null === $read_text ) {
			return 'none';
		}

		return self::why_not( $outcome, $string_id, $language_id, $read_text, is_string( $read_forms ) ? $read_forms : null, null, false === $read_forms ? false : null );
	}

	/**
	 * Write form 1 (and forms 2..N) while the row is as read.
	 *
	 * @param int                                    $string_id   String.
	 * @param int                                    $language_id Language.
	 * @param string|null                            $read_text   Form 1 as read; null when there was no row (then only inserts).
	 * @param string|null                            $read_forms  extra_forms as read.
	 * @param string                                 $text        Form 1 to write, not ''.
	 * @param string|false|null                      $forms       extra_forms to write; false leaves them, and they are not compared.
	 * @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence       Extra WHERE condition, its replacements, and the connection marker added after prepare().
	 * @return int|false 1 written, 0 the row changed since the read, false on a database error.
	 */
	public static function write( int $string_id, int $language_id, ?string $read_text, ?string $read_forms, string $text, string|false|null $forms, array $fence = [ '', [], '' ] ): int|false {
		$wpdb = Typed::wpdb();

		if ( null !== $read_text ) {
			[ $where, $where_args ] = self::as_read( $read_text, false === $forms ? false : $read_forms );

			return self::write_over( $string_id, $language_id, $text, $forms, [ $where . $fence[0], array_merge( $where_args, $fence[1] ), $fence[2] ] );
		}

		$with   = is_string( $forms );
		$values = $with ? '%d, %d, %s, %s' : '%d, %d, %s';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Insert-if-free, prepared here; the replacements arrive as one array.
		$sql    = $wpdb->prepare(
			'INSERT IGNORE INTO %i (string_id, language_id, translation' . ( $with ? ', extra_forms)' : ')' )
				. ( '' === $fence[0] && '' === $fence[2] ? ' VALUES (' . $values . ')' : ' SELECT ' . $values . ' FROM DUAL WHERE 1 = 1' . $fence[0] ),
			array_merge( [ Schema::table( 'string_translations' ), $string_id, $language_id, $text ], $with ? [ $forms ] : [], $fence[1] )
		);
		$result = is_string( $sql ) ? $wpdb->query( $sql . $fence[2] ) : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_int( $result ) ? min( 1, $result ) : false;
	}

	/**
	 * Delete the row while it is as read.
	 *
	 * @param int                                    $string_id   String.
	 * @param int                                    $language_id Language.
	 * @param string                                 $read_text   Form 1 as read.
	 * @param string|false|null                      $read_forms  extra_forms as read; false: not compared.
	 * @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence       Extra WHERE condition, its replacements, and the connection marker added after prepare().
	 * @return int|false 1 deleted, 0 the row changed since the read, false on a database error.
	 */
	public static function clear( int $string_id, int $language_id, string $read_text, string|false|null $read_forms, array $fence = [ '', [], '' ] ): int|false {
		[ $where, $where_args ] = self::as_read( $read_text, $read_forms );

		return self::delete_over( $string_id, $language_id, [ $where . $fence[0], array_merge( $where_args, $fence[1] ), $fence[2] ] );
	}

	/**
	 * Whether the group has a link in the language.
	 *
	 * @param int $group_id    String group.
	 * @param int $language_id Language.
	 * @return bool
	 */
	public static function has_link( int $group_id, int $language_id ): bool {
		$wpdb = Typed::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see other requests' writes.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d AND language_id = %d', Schema::table( 'translation_links' ), $group_id, $language_id ) );
	}

	/**
	 * Link a cell that has none, in the caller's unit: never over another writer's link (INSERT IGNORE on group_lang).
	 *
	 * @param int        $group_id    String group.
	 * @param int        $string_id   String.
	 * @param int        $language_id Language.
	 * @param string     $status      Link status.
	 * @param SourceType $label       Link source.
	 * @param string     $guarded     The unit's connection condition (' AND ...'), '' on SQLite.
	 * @return int|false 1 linked, 2 another writer's link stands, 0 no link (another group's object_lang row: the caller's
	 *                   upsert_link() reaps it), false on a database error.
	 */
	public static function link_if_none( int $group_id, int $string_id, int $language_id, string $status, SourceType $label, string $guarded ): int|false {
		$wpdb  = Typed::wpdb();
		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared here; the marker is a checked literal (ConnectionGuard::condition()).
		$sql  = $wpdb->prepare(
			'INSERT IGNORE INTO %i (group_id, object_id, language_id, type, status, source) ' . ( '' === $guarded ? 'VALUES (%d, %d, %d, %s, %s, %s)' : 'SELECT %d, %d, %d, %s, %s, %s FROM DUAL WHERE 1 = 1' ),
			$links,
			$group_id,
			$string_id,
			$language_id,
			'string',
			sanitize_key( $status ),
			$label->value
		);
		$done = is_string( $sql ) ? $wpdb->query( $sql . $guarded ) : false;

		if ( ! is_int( $done ) ) {
			return false;
		}

		if ( $done > 0 ) {
			return 1;
		}

		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d AND language_id = %d' . ConnectionGuard::lock_suffix(), $links, $group_id, $language_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $id ? 0 : 2;
	}

	/**
	 * One unit. SQLite has no row locks: a per-cell lock serializes the Strings saves of one cell, and one transaction
	 * holds the unit, so a save that took the lock over after its TTL cannot write until this one ends.
	 *
	 * @param TranslationGroupRepository $repo        Group/link repository.
	 * @param int                        $group_id    String group.
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param callable                   $as_read     Value statement while the row is as read: fn( fence ): int|false.
	 * @param callable                   $over        Value statement over a machine translation: fn( fence ): int|false.
	 * @param string|null                $text        Form 1 written; null clears the cell.
	 * @param string|false|null          $forms       extra_forms written; false: not compared.
	 * @param array<string, mixed>       $how         over_mt, label, strict, check, then (save()'s parameters).
	 * @phpstan-param UnitHow $how
	 * @return string 'saved', 'unlinked', 'zero' (as read matched nothing, or value and link were already as wanted),
	 *                'stale', 'refused', 'failed', or on MySQL/MariaDB 'unsure' / 'unsure_label' (COMMIT sent, not
	 *                acknowledged; the latter relabelled a link).
	 */
	private static function unit( TranslationGroupRepository $repo, int $group_id, int $string_id, int $language_id, callable $as_read, callable $over, ?string $text, string|false|null $forms, array $how ): string {
		if ( ConnectionGuard::applies() ) {
			return self::locked_unit( $repo, $group_id, $string_id, $language_id, $as_read, $over, $text, $forms, $how );
		}

		$name = StringLinkLabel::cell_lock( $group_id, $language_id );

		if ( null === $name ) {
			return 'failed';
		}

		try {
			$outcome = self::transaction_unit( $repo, $group_id, $string_id, $language_id, $as_read, $over, $text, $forms, $how );
		} finally {
			Lock::release( $name );
		}

		// After the release: a slow listener would otherwise hold the cell past the lock's TTL.
		if ( 'claimed' === $outcome ) {
			StringLinkLabel::announce( $repo, $group_id, $string_id, $language_id );

			return 'saved';
		}

		if ( 'dropped' === $outcome ) {
			StringLinkLabel::forget( $repo, $group_id, $string_id );

			return 'saved';
		}

		return $outcome;
	}

	/**
	 * SQLite: locked_unit() in one transaction, which takes the write lock with its first statement (a no-op UPDATE: a
	 * deferred BEGIN would upgrade at the value write and fail busy at once); a failed statement or COMMIT rolls it back.
	 *
	 * @param TranslationGroupRepository $repo        Group/link repository.
	 * @param int                        $group_id    String group.
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param callable                   $as_read     Value statement while the row is as read: fn( fence ): int|false.
	 * @param callable                   $over        Value statement over a machine translation: fn( fence ): int|false.
	 * @param string|null                $text        Form 1 written; null clears the cell.
	 * @param string|false|null          $forms       extra_forms written; false: not compared.
	 * @param array<string, mixed>       $how         over_mt, label, strict, check, then (save()'s parameters).
	 * @phpstan-param UnitHow $how
	 * @return string As locked_unit(), but 'unlabelled' is rolled back as 'failed'.
	 */
	private static function transaction_unit( TranslationGroupRepository $repo, int $group_id, int $string_id, int $language_id, callable $as_read, callable $over, ?string $text, string|false|null $forms, array $how ): string {
		$wpdb    = Typed::wpdb();
		$errors  = Schema::query_error_count();
		$outcome = 'failed';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return 'failed';
		}

		try {
			$sql     = $wpdb->prepare( 'UPDATE %i SET option_value = option_value WHERE 1 = 0', $wpdb->options );
			$hold    = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare() output.
			$outcome = false === $hold ? 'failed' : self::locked_unit( $repo, $group_id, $string_id, $language_id, $as_read, $over, $text, $forms, $how );
		} finally {
			$commit = in_array( $outcome, [ 'claimed', 'dropped', 'saved', 'unlinked' ], true ) && ! Schema::transaction_lost( $errors );

			if ( $commit && null !== $how['then'] && true !== ( $how['then'] )( '' ) ) {
				$commit  = false;
				$outcome = 'refused';
			}

			// 'stale', 'zero' and 'refused' wrote nothing; anything else leaves no half unit behind.
			if ( ! $commit || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				$outcome = in_array( $outcome, [ 'stale', 'zero', 'refused' ], true ) ? $outcome : 'failed';
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $outcome;
	}

	/**
	 * The unit's statements: the link's source (locked on MySQL/MariaDB), the value statement, then the label or the link delete.
	 *
	 * @param TranslationGroupRepository $repo        Group/link repository.
	 * @param int                        $group_id    String group.
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param callable                   $as_read     Value statement while the row is as read: fn( fence ): int|false.
	 * @param callable                   $over        Value statement over a machine translation: fn( fence ): int|false.
	 * @param string|null                $text        Form 1 written; null clears the cell.
	 * @param string|false|null          $forms       extra_forms written; false: not compared.
	 * @param array<string, mixed>       $how         over_mt, label, strict, check, then (save()'s parameters).
	 * @phpstan-param UnitHow $how
	 * @return string As unit(), or on SQLite 'claimed' (saved and relabelled) or 'dropped' (link deleted): unit() drops
	 *                the caches after the COMMIT and the cell lock.
	 */
	private static function locked_unit( TranslationGroupRepository $repo, int $group_id, int $string_id, int $language_id, callable $as_read, callable $over, ?string $text, string|false|null $forms, array $how ): string {
		$wpdb  = Typed::wpdb();
		$links = Schema::table( 'translation_links' );
		$guard = ConnectionGuard::applies() ? new ConnectionGuard( 'string_cell' ) : null;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared here; the replacements arrive as one array.
		if ( null === $guard ) {
			$source = $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM %i WHERE group_id = %d AND language_id = %d', $links, $group_id, $language_id ) );
			$source = '' !== $wpdb->last_error ? false : ( null === $source ? null : (string) $source );
		} else {
			$source = StringLinkLabel::lock( $wpdb, $guard, $group_id, $language_id );
		}

		if ( false === $source ) {
			return 'failed';
		}

		$is_mt  = SourceType::MachineTranslation->value === $source;
		// On a connection wpdb replaced since the lock (a resend after a lost connection), the marker matches nothing.
		$guarded = null === $guard ? '' : ' AND ' . $guard->condition();
		$marker  = [ '', [], $guarded ];
		// SQLite reads the source unlocked, so the write over a machine translation checks it again in its own statement.
		$mt_fence = null === $guard ? [ ' AND EXISTS ( SELECT 1 FROM %i WHERE group_id = %d AND language_id = %d AND source = %s )', [ $links, $group_id, $language_id, SourceType::MachineTranslation->value ], '' ] : $marker;

		$over_mt = $how['over_mt'];

		if ( $over_mt && ! $is_mt ) {
			self::end( $wpdb, $guard, false );

			return 'stale';
		}

		$label = $how['label'];

		if ( null !== $how['check'] && true !== ( $how['check'] )() ) {
			self::end( $wpdb, $guard, false );

			return 'refused';
		}

		$done = $over_mt ? 0 : $as_read( $marker );
		$zero = 0 === $done ? 'zero' : 'stale';

		if ( 0 === $done && $is_mt && ! $how['strict'] ) {
			$done = $over( $mt_fence );
			$zero = 'stale';

			// 0 rows: MySQL counts changed rows (the cell may already hold the person's text); on SQLite the row or the mt label is gone.
			if ( 0 === $done ) {
				$now  = self::now( $string_id, $language_id, true );
				$done = false === $now ? false : (int) self::as_wanted( $now, $text, $forms );
			}
		}

		// No row on a new connection: the marker went with the lost one, so the cell did not move.
		if ( 0 === $done && null !== $guard && ! $guard->holds() ) {
			self::end( $wpdb, $guard, false );

			return 'failed';
		}

		// The value as read is the value wanted: MySQL counts no changed row, SQLite one matched row. Either way the label
		// step runs unless the link is already as wanted, so 'same' means value and link.
		if ( $how['same'] && ( 0 === $done || 1 === $done ) ) {
			$done = self::same_cell( $string_id, $language_id, $group_id, $text, $forms, $how['link'], $label );
			$zero = 'zero';
		}

		if ( 1 !== $done && 2 !== $done ) {
			self::end( $wpdb, $guard, false );

			return false === $done ? 'failed' : $zero;
		}

		if ( null === $source && null !== $text ) {
			// Linked inside the unit: a save that links the cell after the COMMIT keeps its own status and label.
			$made = null === $how['link'] ? 0 : self::link_if_none( $group_id, $string_id, $language_id, $how['link'], $label, $guarded );

			if ( false === $made || ( null !== $guard && null !== $how['then'] && true !== ( $how['then'] )( $guarded ) ) ) {
				self::end( $wpdb, $guard, false );

				return false === $made ? 'failed' : 'refused';
			}

			if ( ! self::end( $wpdb, $guard, true ) ) {
				return null === $guard ? 'failed' : 'unsure';
			}

			if ( 1 !== $made ) {
				return 0 === $made ? 'unlinked' : 'saved';
			}

			if ( null === $guard ) {
				return 'claimed';
			}

			StringLinkLabel::announce( $repo, $group_id, $string_id, $language_id );

			return 'saved';
		}

		$sql = null;

		if ( null === $text ) {
			// Only while no value row exists: a run's fill since the delete keeps the link that serves it.
			$sql = $wpdb->prepare(
				'DELETE FROM %i WHERE group_id = %d AND language_id = %d AND NOT EXISTS ( SELECT 1 FROM %i WHERE string_id = %d AND language_id = %d )',
				$links,
				$group_id,
				$language_id,
				Schema::table( 'string_translations' ),
				$string_id,
				$language_id
			);
		} elseif ( null !== $guard ) {
			$set   = [ $string_id, $how['link'] ?? 'translated', $label->value ];
			$touch = Schema::touch_write_time( [ [ 'object_id', '%d', 'int' ], [ 'status', '%s', 'text' ], [ 'source', '%s', 'text' ] ] );
			$sql   = $wpdb->prepare(
				'UPDATE %i SET object_id = %d, status = %s, source = %s' . $touch . ' WHERE group_id = %d AND language_id = %d',
				array_merge( [ $links ], $set, '' === $touch ? [] : $set, [ $group_id, $language_id ] )
			);
		}

		$linked = null === $sql ? 0 : ( is_string( $sql ) ? $wpdb->query( $sql . $guarded ) : false );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( null !== $guard ) {
			if ( is_int( $linked ) && null !== $how['then'] && true !== ( $how['then'] )( $guarded ) ) {
				self::end( $wpdb, $guard, false );

				return 'refused';
			}

			// MySQL/MariaDB: a failed label is rolled back with the value; a COMMIT not acknowledged may have committed both.
			if ( ! self::end( $wpdb, $guard, is_int( $linked ) ) ) {
				if ( ! is_int( $linked ) ) {
					return 'failed';
				}

				return null !== $text && null !== $source && $label->value !== $source ? 'unsure_label' : 'unsure';
			}
		} elseif ( ! is_int( $linked ) ) {
			return 'unlabelled';
		} elseif ( null !== $text ) {
			// SQLite: the label in one statement, only while form 1 is the person's text; a failure fails the whole unit.
			$claimed = StringLinkLabel::claim( $repo, $group_id, $string_id, $language_id, $label, $text, null, false );

			if ( null === $claimed ) {
				return 'unlabelled';
			}

			if ( $claimed ) {
				return 'claimed';
			}

			return self::has_link( $group_id, $language_id ) ? 'saved' : 'unlinked';
		}

		if ( $linked > 0 ) {
			if ( null === $guard ) {
				return 'dropped';
			}

			if ( null === $text ) {
				StringLinkLabel::forget( $repo, $group_id, $string_id );
			} else {
				$repo->invalidate_group_cache( $group_id );

				/**
				 * Fires after an object is linked to a group.
				 *
				 * @hook perflocale/translation/linked
				 */
				do_action( 'perflocale/translation/linked', $group_id, $string_id, $language_id );
			}
		}

		return 'saved';
	}

	/**
	 * Inside a unit whose value write keeps the value as read: 1 when the cell holds it and the link is not yet as the unit
	 * labels it (the label step runs), 0 when nothing is left to write or the cell moved, false on a failed read.
	 *
	 * @param int               $string_id   String.
	 * @param int               $language_id Language.
	 * @param int               $group_id    String group.
	 * @param string|null       $text        Form 1 wanted.
	 * @param string|false|null $forms       extra_forms wanted; false: not compared.
	 * @param string|null       $status      Link status the unit sets; null: never taken as set.
	 * @param SourceType        $label       Link source the unit sets.
	 * @return int|false
	 */
	private static function same_cell( int $string_id, int $language_id, int $group_id, ?string $text, string|false|null $forms, ?string $status, SourceType $label ): int|false {
		$now = self::now( $string_id, $language_id, true );

		if ( false === $now ) {
			return false;
		}

		if ( ! self::as_wanted( $now, $text, $forms ) ) {
			return 0;
		}

		$wpdb = Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Inside the unit; the lock suffix is a literal.
		$link = $wpdb->get_row( $wpdb->prepare( 'SELECT object_id, status, source FROM %i WHERE group_id = %d AND language_id = %d' . ConnectionGuard::lock_suffix(), Schema::table( 'translation_links' ), $group_id, $language_id ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		$set = null !== $status && is_array( $link ) && [ $string_id, $status, $label->value ] === [ Typed::int( $link['object_id'] ?? 0 ), Typed::str( $link['status'] ?? '' ), Typed::str( $link['source'] ?? '' ) ];

		return $set ? 0 : 1;
	}

	/**
	 * Commit or roll back a MySQL/MariaDB unit; true on SQLite.
	 *
	 * @param \wpdb                $wpdb   Database.
	 * @param ConnectionGuard|null $guard  The unit's marker; null on SQLite.
	 * @param bool                 $commit Commit, else roll back.
	 * @return bool Whether the unit committed on the connection that holds it.
	 */
	private static function end( \wpdb $wpdb, ?ConnectionGuard $guard, bool $commit ): bool {
		if ( null === $guard ) {
			return true;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		// A COMMIT wpdb resent on a new connection commits nothing: the unit went with the lost one.
		$done = $commit && false !== $wpdb->query( 'COMMIT' ) && $guard->kept();

		if ( ! $done ) {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$guard->end();

		return $done;
	}

	/**
	 * Whether a unit's COMMIT was sent but not acknowledged (MySQL/MariaDB): it may have committed.
	 *
	 * @param string $outcome A unit's outcome.
	 * @return bool
	 */
	private static function unsure( string $outcome ): bool {
		return 'unsure' === $outcome || 'unsure_label' === $outcome;
	}

	/**
	 * After a try whose COMMIT was not acknowledged, the string's cached links are dropped; a cell found as wanted after
	 * it (a retry's 'zero' or 'stale', or the last try's own unacknowledged COMMIT) was saved: saved (or unlinked, so the
	 * caller links it), never 'same' or 'stale', and its relabel is announced.
	 *
	 * @param TranslationGroupRepository $repo        Group/link repository.
	 * @param string                     $first       The first try's outcome.
	 * @param string                     $outcome     The last try's outcome; 'zero', 'stale' and unacknowledged are reconciled.
	 * @param int                        $group_id    String group.
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param string|null                $text        Form 1 wanted; null: no row.
	 * @param string|false|null          $forms       extra_forms wanted; false: not compared.
	 * @param SourceType                 $label       Label the unit sets on the link.
	 * @return string
	 */
	private static function retried( TranslationGroupRepository $repo, string $first, string $outcome, int $group_id, int $string_id, int $language_id, ?string $text, string|false|null $forms, SourceType $label ): string {
		$last    = $outcome;
		$outcome = self::unsure( $last ) ? 'failed' : $last;

		if ( ! self::unsure( $first ) && ! self::unsure( $last ) ) {
			return $outcome;
		}

		$now = self::unsure( $last ) || 'zero' === $outcome || 'stale' === $outcome ? self::now( $string_id, $language_id, false ) : false;

		if ( false === $now || ! self::as_wanted( $now, $text, $forms ) ) {
			StringLinkLabel::forget( $repo, $group_id, $string_id );

			return $outcome;
		}

		StringLinkLabel::reconciled( $repo, $group_id, $string_id, $language_id, 'unsure_label' === $first || 'unsure_label' === $last ? $label : null );

		return null === $text || self::has_link( $group_id, $language_id ) ? 'saved' : 'unlinked';
	}

	/**
	 * Why the as-read statement matched no row, from the row now: as wanted ('same'); still as read, so the database
	 * compared other bytes than PHP read ('mismatch': table and connection character sets differ); else 'stale'.
	 *
	 * @param string            $outcome     The unit's outcome; only 'zero' is examined.
	 * @param int               $string_id   String.
	 * @param int               $language_id Language.
	 * @param string|null       $read_text   Form 1 as read.
	 * @param string|null       $read_forms  extra_forms as read.
	 * @param string|null       $text        Form 1 wanted; null: no row.
	 * @param string|false|null $forms       extra_forms wanted; false: not compared.
	 * @return string
	 */
	private static function why_not( string $outcome, int $string_id, int $language_id, ?string $read_text, ?string $read_forms, ?string $text, string|false|null $forms ): string {
		if ( 'zero' !== $outcome ) {
			return $outcome;
		}

		$now = self::now( $string_id, $language_id, false );

		if ( false === $now ) {
			return 'failed';
		}

		if ( self::as_wanted( $now, $text, $forms ) ) {
			return 'same';
		}

		return null !== $read_text && $now[0] === $read_text && ( false === $forms || $now[1] === $read_forms ) ? 'mismatch' : 'stale';
	}

	/**
	 * The cell as stored now, read on the primary: [ form 1, extra_forms ], [ null, null ] without a row.
	 *
	 * @param int  $string_id   String.
	 * @param int  $language_id Language.
	 * @param bool $locked      Inside a unit: a locking read on MySQL/MariaDB, so it sees the latest committed row.
	 * @return array{0: string|null, 1: string|null}|false False on a database error (an absent row is not a failed read).
	 */
	private static function now( int $string_id, int $language_id, bool $locked ): array|false {
		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Must see other requests' writes; the lock suffix is a literal.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT translation, extra_forms FROM %i WHERE string_id = %d AND language_id = %d' . ( $locked ? ConnectionGuard::lock_suffix() : '' ), Schema::table( 'string_translations' ), $string_id, $language_id ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return [
			is_array( $row ) ? Typed::str( $row['translation'] ?? '' ) : null,
			is_array( $row ) && null !== ( $row['extra_forms'] ?? null ) ? Typed::str( $row['extra_forms'] ) : null,
		];
	}

	/**
	 * Whether the cell holds what the person saved: form 1 (null: no row) and, unless not compared, the forms.
	 *
	 * @param array{0: string|null, 1: string|null} $now   The cell now.
	 * @param string|null                           $text  Form 1 wanted; null: no row.
	 * @param string|false|null                     $forms extra_forms wanted; false: not compared.
	 * @return bool
	 */
	private static function as_wanted( array $now, ?string $text, string|false|null $forms ): bool {
		return $now[0] === $text && ( null === $text || false === $forms || $now[1] === $forms );
	}

	/**
	 * Write form 1 (and forms) under $fence only.
	 *
	 * @param int                                    $string_id   String.
	 * @param int                                    $language_id Language.
	 * @param string                                 $text        Form 1.
	 * @param string|false|null                      $forms       extra_forms; false leaves them.
	 * @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence       WHERE condition, its replacements, and the connection marker added after prepare().
	 * @return int|false
	 */
	private static function write_over( int $string_id, int $language_id, string $text, string|false|null $forms, array $fence ): int|false {
		$wpdb = Typed::wpdb();
		$set  = 'translation = %s';
		$args = [ Schema::table( 'string_translations' ), $text ];
		$cols = [ [ 'translation', '%s', 'text' ] ];
		$bind = [ $text ];

		if ( false !== $forms ) {
			$set   .= null === $forms ? ', extra_forms = NULL' : ', extra_forms = %s';
			$args   = null === $forms ? $args : array_merge( $args, [ $forms ] );
			$cols[] = null === $forms ? [ 'extra_forms', 'NULL', 'text_null' ] : [ 'extra_forms', '%s', 'text' ];
			$bind   = null === $forms ? $bind : array_merge( $bind, [ $forms ] );
		}

		$touch = Schema::touch_write_time( $cols );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-set, prepared here; the replacements arrive as one array.
		$sql    = $wpdb->prepare(
			'UPDATE %i SET ' . $set . $touch . ' WHERE string_id = %d AND language_id = %d' . $fence[0],
			array_merge( $args, '' === $touch ? [] : $bind, [ $string_id, $language_id ], $fence[1] )
		);
		$result = is_string( $sql ) ? $wpdb->query( $sql . $fence[2] ) : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_int( $result ) ? min( 1, $result ) : false;
	}

	/**
	 * Delete the row under $fence only.
	 *
	 * @param int                                    $string_id   String.
	 * @param int                                    $language_id Language.
	 * @param array{0: literal-string, 1: array<int, mixed>, 2: string} $fence       WHERE condition, its replacements, and the connection marker added after prepare().
	 * @return int|false
	 */
	private static function delete_over( int $string_id, int $language_id, array $fence ): int|false {
		$wpdb = Typed::wpdb();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-delete, prepared here.
		$sql    = $wpdb->prepare(
			'DELETE FROM %i WHERE string_id = %d AND language_id = %d' . $fence[0],
			array_merge( [ Schema::table( 'string_translations' ), $string_id, $language_id ], $fence[1] )
		);
		$result = is_string( $sql ) ? $wpdb->query( $sql . $fence[2] ) : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_int( $result ) ? min( 1, $result ) : false;
	}

	/**
	 * The WHERE condition "row as read": form 1 bytewise, extra_forms bytewise or NULL.
	 *
	 * @param string            $read_text  Form 1 as read.
	 * @param string|false|null $read_forms extra_forms as read; false: not compared.
	 * @return array{0: literal-string, 1: array<int, string>}
	 */
	private static function as_read( string $read_text, string|false|null $read_forms ): array {
		$where = ' AND CAST(translation AS BINARY) = CAST(%s AS BINARY)';
		$args  = [ $read_text ];

		if ( null === $read_forms ) {
			$where .= ' AND extra_forms IS NULL';
		} elseif ( is_string( $read_forms ) ) {
			$where .= ' AND CAST(extra_forms AS BINARY) = CAST(%s AS BINARY)';
			$args[] = $read_forms;
		}

		return [ $where, $args ];
	}
}
