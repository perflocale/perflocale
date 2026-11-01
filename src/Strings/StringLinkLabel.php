<?php
/**
 * Compare-and-set of a string link's label against the stored value.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\SourceType;

defined( 'ABSPATH' ) || exit;

/**
 * Labels a string link only while form 1 is the writer's text, so the link and the value it describes agree.
 * Loaded by string writes only (bulk MT job, Strings save, PO import).
 *
 * @since 1.0.7.5
 */
final class StringLinkLabel {

	/** Seconds a SQLite cell lock outlives a request that died holding it. */
	private const CELL_LOCK_TTL = 15;

	/** Seconds a SQLite cell writer waits for another writer of the same cell. */
	private const CELL_LOCK_WAIT = 5.0;

	/**
	 * Label the link translated with $source while it is as $read (else: while it has another label) and form 1 is
	 * byte-equal $text. MySQL/MariaDB lock the link row first (alone, the UPDATE may test the value before it waits) and
	 * relabel nothing when the lock fails; there never inside a caller's transaction. On SQLite one statement, which may
	 * run inside the caller's transaction (StringCellWrite).
	 *
	 * @param TranslationGroupRepository                                 $group_repo  Group/link repository.
	 * @param int                                                        $group_id    String group.
	 * @param int                                                        $string_id   String.
	 * @param int                                                        $language_id Language.
	 * @param SourceType                                                 $source      Label to set.
	 * @param string                                                     $text        Form 1 the caller wrote.
	 * @param array{source: string, status: string, object_id: int}|null $read        The link as the caller read it.
	 * @param bool                                                       $announce    False: the caller runs announce() itself.
	 * @return bool|null Whether the link was relabelled; null when the relabel could not run (lock, database, connection).
	 */
	public static function claim( TranslationGroupRepository $group_repo, int $group_id, int $string_id, int $language_id, SourceType $source, string $text, ?array $read = null, bool $announce = true ): ?bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$guard = ConnectionGuard::applies() ? new ConnectionGuard( 'string_label' ) : null;

		if ( null !== $guard && false === self::lock( $wpdb, $guard, $group_id, $language_id ) ) {
			return null;
		}

		$updated = self::relabel( $wpdb, $guard, $group_id, $string_id, $language_id, $source, $text, $read );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		// A COMMIT wpdb resent on a new connection commits nothing: the relabel went with the lost one.
		if ( null !== $guard && ( ! is_int( $updated ) || false === $wpdb->query( 'COMMIT' ) || ! $guard->kept() ) ) {
			$wpdb->query( 'ROLLBACK' );
			$updated = false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$guard?->end();

		if ( ! is_int( $updated ) ) {
			return null;
		}

		if ( $updated < 1 ) {
			return false;
		}

		if ( $announce ) {
			self::announce( $group_repo, $group_id, $string_id, $language_id );
		}

		return true;
	}

	/**
	 * A run's fill of an empty slot and its relabel as one unit (the link row on MySQL/MariaDB, the cell lock on SQLite),
	 * so a person's save of the cell waits for both and then finds the label it may replace.
	 *
	 * @param TranslationGroupRepository                                 $group_repo  Group/link repository.
	 * @param int                                                        $group_id    String group.
	 * @param int                                                        $string_id   String.
	 * @param int                                                        $language_id Language.
	 * @param SourceType                                                 $source      Label to set.
	 * @param string                                                     $text        Form 1 the fill writes.
	 * @param array{source: string, status: string, object_id: int}|null $read        The link as the caller read it; null: fill only.
	 * @param callable                                                   $fill        fn( string $marker ): ?bool, the value statement with the marker appended.
	 * @param string|null                                                $forms       extra_forms the fill writes; null: not compared.
	 * @return array{0: bool|null, 1: bool|null} [ filled (null: nothing written), relabelled (null: SQLite kept the value
	 *                                       but could not label it) ].
	 */
	public static function fill_and_claim( TranslationGroupRepository $group_repo, int $group_id, int $string_id, int $language_id, SourceType $source, string $text, ?array $read, callable $fill, ?string $forms = null ): array {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return [ null, false ];
		}

		if ( ! ConnectionGuard::applies() ) {
			$cell = self::cell_lock( $group_id, $language_id );

			if ( null === $cell ) {
				return [ null, false ];
			}

			try {
				$filled  = self::bool_or_null( $fill( '' ) );
				$claimed = true === $filled && null !== $read
					? self::claim( $group_repo, $group_id, $string_id, $language_id, $source, $text, $read, false ) ?? self::claim( $group_repo, $group_id, $string_id, $language_id, $source, $text, $read, false )
					: false;
			} finally {
				\PerfLocale\Concurrency\Lock::release( $cell );
			}

			if ( true === $claimed ) {
				self::announce( $group_repo, $group_id, $string_id, $language_id );
			}

			return [ $filled, $claimed ];
		}

		$guard = new ConnectionGuard( 'string_fill' );

		if ( false === self::lock( $wpdb, $guard, $group_id, $language_id ) ) {
			return [ null, false ];
		}

		$filled  = self::bool_or_null( $fill( ' AND ' . $guard->condition() ) );
		$updated = true === $filled && null !== $read ? self::relabel( $wpdb, $guard, $group_id, $string_id, $language_id, $source, $text, $read ) : 0;
		$sent    = null !== $filled && is_int( $updated );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		// A failed relabel takes the fill with it; a COMMIT wpdb resent on a new connection committed neither.
		$done = $sent && false !== $wpdb->query( 'COMMIT' ) && $guard->kept();

		if ( ! $done ) {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$guard->end();

		// A COMMIT sent after both statements but not acknowledged may have committed: when the cell (and label) read as
		// this unit set them, it did.
		if ( ! $done && $sent && true === $filled && self::reads( $group_id, $string_id, $language_id, $text, $forms, $updated > 0 ? $source : null ) ) {
			self::reconciled( $group_repo, $group_id, $string_id, $language_id, $updated > 0 ? $source : null, true );

			return [ true, $updated > 0 ];
		}

		if ( ! $done ) {
			return [ null, false ];
		}

		if ( $updated > 0 ) {
			self::announce( $group_repo, $group_id, $string_id, $language_id );
		}

		return [ $filled, $updated > 0 ];
	}

	/**
	 * The fill callable's answer as declared: true, false or null.
	 *
	 * @param mixed $value Its return value.
	 * @return bool|null
	 */
	private static function bool_or_null( mixed $value ): ?bool {
		return is_bool( $value ) ? $value : null;
	}

	/**
	 * SQLite: take the per-cell lock the Strings save and a run's fill share; null when another holder keeps it past the wait.
	 *
	 * @param int $group_id    String group.
	 * @param int $language_id Language.
	 * @return string|null The lock name to release.
	 */
	public static function cell_lock( int $group_id, int $language_id ): ?string {
		$name     = 'string_cell_' . $group_id . '_' . $language_id;
		$deadline = microtime( true ) + self::CELL_LOCK_WAIT;

		while ( ! \PerfLocale\Concurrency\Lock::acquire( $name, self::CELL_LOCK_TTL ) ) {
			if ( microtime( true ) >= $deadline ) {
				return null;
			}

			usleep( 50000 );
		}

		return $name;
	}

	/**
	 * The relabel statement: while the link is as read (else: while it has another label) and form 1 is byte-equal $text.
	 *
	 * @param \wpdb                                                      $wpdb        Database.
	 * @param ConnectionGuard|null                                       $guard       The unit's marker; null on SQLite.
	 * @param int                                                        $group_id    String group.
	 * @param int                                                        $string_id   String.
	 * @param int                                                        $language_id Language.
	 * @param SourceType                                                 $source      Label to set.
	 * @param string                                                     $text        Form 1 the caller wrote.
	 * @param array{source: string, status: string, object_id: int}|null $read        The link as the caller read it.
	 * @return int|false Rows relabelled, false on a database error.
	 */
	private static function relabel( \wpdb $wpdb, ?ConnectionGuard $guard, int $group_id, int $string_id, int $language_id, SourceType $source, string $text, ?array $read ): int|false {
		$label = [ $string_id, 'translated', $source->value ];
		$touch = Schema::touch_write_time( [ [ 'object_id', '%d', 'int' ], [ 'status', '%s', 'text' ], [ 'source', '%s', 'text' ] ] );

		// On a connection wpdb replaced since the lock (a resend after a lost connection), the marker matches nothing.
		$marker = null === $guard ? '' : ' AND ' . $guard->condition();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-set, prepared here; the replacements arrive as one array; the marker is the guard's hex-token condition.
		$sql = $wpdb->prepare(
			'UPDATE %i SET object_id = %d, status = %s, source = %s' . $touch . ' WHERE group_id = %d AND language_id = %d AND '
				. ( null === $read ? 'NOT ( object_id = %d AND status = %s AND source = %s )' : 'object_id = %d AND status = %s AND source = %s' )
				. ' AND EXISTS ( SELECT 1 FROM %i WHERE string_id = %d AND language_id = %d AND CAST(translation AS BINARY) = CAST(%s AS BINARY) )',
			array_merge(
				[ Schema::table( 'translation_links' ) ],
				$label,
				'' === $touch ? [] : $label,
				[ $group_id, $language_id ],
				null === $read ? $label : [ $read['object_id'], $read['status'], $read['source'] ],
				[ Schema::table( 'string_translations' ), $string_id, $language_id, $text ]
			)
		);

		$updated = is_string( $sql ) ? $wpdb->query( $sql . $marker ) : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_int( $updated ) ? $updated : false;
	}

	/**
	 * After a unit whose COMMIT was not acknowledged but committed: drop the string's cached links, and announce when
	 * the link now reads (string, translated, $source).
	 *
	 * @param TranslationGroupRepository $group_repo  Group/link repository.
	 * @param int                        $group_id    String group.
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @param SourceType|null            $source      The label the unit set; null: none.
	 * @param bool                       $checked     The caller already read the link as $source.
	 * @return void
	 */
	public static function reconciled( TranslationGroupRepository $group_repo, int $group_id, int $string_id, int $language_id, ?SourceType $source, bool $checked = false ): void {
		if ( null !== $source && ( $checked || self::reads( $group_id, $string_id, $language_id, null, null, $source ) ) ) {
			self::announce( $group_repo, $group_id, $string_id, $language_id );
			self::forget_string( $group_repo, $string_id );
		} else {
			self::forget( $group_repo, $group_id, $string_id );
		}
	}

	/**
	 * Drop the group's cached links and the string's own: after a link delete the group's read no longer finds it.
	 *
	 * @param TranslationGroupRepository $group_repo Group/link repository.
	 * @param int                        $group_id   String group.
	 * @param int                        $string_id  String.
	 * @return void
	 */
	public static function forget( TranslationGroupRepository $group_repo, int $group_id, int $string_id ): void {
		$group_repo->invalidate_group_cache( $group_id );
		self::forget_string( $group_repo, $string_id );
	}

	/**
	 * Drop the string's cached links and its group lookup.
	 *
	 * @param TranslationGroupRepository $group_repo Group/link repository.
	 * @param int                        $string_id  String.
	 * @return void
	 */
	private static function forget_string( TranslationGroupRepository $group_repo, int $string_id ): void {
		\PerfLocale\Plugin::get_instance()->cache()->flush_object( $string_id, 'string' );
		$group_repo->invalidate_find_cache( $string_id, \PerfLocale\Enum\ObjectType::String );
	}

	/**
	 * Whether the latest committed cell has form 1 $text and extra_forms $forms (null: not checked) and, with $source,
	 * the link reads (string, translated, $source). Locking reads on MySQL/MariaDB: they wait for a unit still committing.
	 *
	 * @param int             $group_id    String group.
	 * @param int             $string_id   String.
	 * @param int             $language_id Language.
	 * @param string|null     $text        Form 1; null: not checked.
	 * @param string|null     $forms       extra_forms; null: not checked.
	 * @param SourceType|null $source      Label; null: not checked.
	 * @return bool False on a failed read.
	 */
	private static function reads( int $group_id, int $string_id, int $language_id, ?string $text, ?string $forms, ?SourceType $source ): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$lock = ConnectionGuard::lock_suffix();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Must see the committed rows; the lock suffix is a literal.
		if ( null !== $text ) {
			$cell = $wpdb->get_row( $wpdb->prepare( 'SELECT translation, extra_forms FROM %i WHERE string_id = %d AND language_id = %d' . $lock, Schema::table( 'string_translations' ), $string_id, $language_id ), ARRAY_A );

			if ( '' !== $wpdb->last_error || ! is_array( $cell ) || ( $cell['translation'] ?? null ) !== $text || ( null !== $forms && ( $cell['extra_forms'] ?? null ) !== $forms ) ) {
				return false;
			}
		}

		if ( null !== $source ) {
			$link = $wpdb->get_row( $wpdb->prepare( 'SELECT object_id, status, source FROM %i WHERE group_id = %d AND language_id = %d' . $lock, Schema::table( 'translation_links' ), $group_id, $language_id ), ARRAY_A );

			if ( '' !== $wpdb->last_error || ! is_array( $link ) || ! is_numeric( $link['object_id'] ?? null ) || (int) $link['object_id'] !== $string_id || 'translated' !== ( $link['status'] ?? '' ) || $source->value !== ( $link['source'] ?? '' ) ) {
				return false;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return true;
	}

	/**
	 * Drop the group's cached links and tell listeners the link changed.
	 *
	 * @param TranslationGroupRepository $group_repo  Group/link repository.
	 * @param int                        $group_id    String group.
	 * @param int                        $string_id   String.
	 * @param int                        $language_id Language.
	 * @return void
	 */
	public static function announce( TranslationGroupRepository $group_repo, int $group_id, int $string_id, int $language_id ): void {
		$group_repo->invalidate_group_cache( $group_id );

		/** @hook perflocale/translation/linked Fires after an object is linked to a group. */
		do_action( 'perflocale/translation/linked', $group_id, $string_id, $language_id );
	}

	/**
	 * Mark the connection, then open a transaction holding the link row; false (nothing open) when a step fails.
	 *
	 * @param \wpdb           $wpdb        Database.
	 * @param ConnectionGuard $guard       The unit's connection marker.
	 * @param int             $group_id    String group.
	 * @param int             $language_id Language.
	 * @return string|false|null The link's source, null without a link, false when nothing is held.
	 */
	public static function lock( \wpdb $wpdb, ConnectionGuard $guard, int $group_id, int $language_id ): string|false|null {
		// HyperDB and LudicrousDB route a SELECT to a replica otherwise.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Transaction control and a locking read; never cached; the lock suffix is a literal.
		if ( ! $guard->begin() || false === $wpdb->query( 'START TRANSACTION' ) ) {
			$guard->end();

			return false;
		}

		$source = $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM %i WHERE group_id = %d AND language_id = %d' . ConnectionGuard::lock_suffix(), Schema::table( 'translation_links' ), $group_id, $language_id ) );

		if ( '' !== $wpdb->last_error || ! $guard->kept() ) {
			$wpdb->query( 'ROLLBACK' );
			$guard->end();

			return false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return null === $source ? null : (string) $source;
	}
}
