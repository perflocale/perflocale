<?php
/**
 * Automation target: string translations.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Database\Repository\TranslationGroupWrites;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Plugin;
use PerfLocale\Strings\Domains;
use PerfLocale\Strings\StringCellWrite;
use PerfLocale\Strings\StringLinkLabel;
use PerfLocale\Strings\StringValueWriter;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, writes and restores one string translation for the automation service.
 *
 * A pair's hash covers its value and plural forms 2..N (what a person edits on the Strings screen).
 * Writes compare the cell first (write_as_read()); caches, files and `perflocale/strings/changed` flush once per batch.
 *
 * @phpstan-type LinkState array{exists: bool, status: string, source: string}
 * @phpstan-type StoredCell array{0: string|null, 1: string|null}
 * @phpstan-type StringState array{group_id: int, domain: string, context: string, original: string, original_hash: string, translation: string, extra_forms: array<int, string>, link: LinkState, stored: StoredCell}
 * @phpstan-type Previous array{translation: string, extra_forms: array<int, string>, link: LinkState}
 */
final class StringTarget {

	/** What write() and restore() answer when the cell moved since the caller's read (nothing written). */
	public const CELL_MOVED = 'cell_moved';

	/** write() with a target: the string id now names another source text or the language changed. */
	public const TARGET_GONE = 'target_gone';

	/**
	 * Languages written since the last flush.
	 *
	 * @var array<int, array<int, true>> blog id => language id => true
	 */
	private static array $touched = [];

	/**
	 * The hash of a value and its plural forms 2..N.
	 *
	 * @param string             $translation Value.
	 * @param array<int, string> $extra_forms Forms 2..N.
	 * @return string
	 */
	public static function hash( string $translation, array $extra_forms ): string {
		return StateHash::stored( $translation, (string) StringTranslationRepository::normalize_extra_forms( $extra_forms ) );
	}

	/**
	 * hash() of a value and its forms as the table stores them.
	 *
	 * @param string $translation      Value.
	 * @param string $extra_forms_json The stored `extra_forms` ('' for none).
	 * @return string
	 */
	public static function stored_hash( string $translation, string $extra_forms_json ): string {
		return self::hash( $translation, self::stored_forms( $extra_forms_json ) );
	}

	/**
	 * Plural forms 2..N from the stored `extra_forms` JSON.
	 *
	 * @param string $json Stored value ('' for none).
	 * @return array<int, string>
	 */
	private static function stored_forms( string $json ): array {
		return '' === $json ? [] : Scalar::strings( json_decode( $json, true ) );
	}

	/**
	 * The live state of several strings in one language, in one query.
	 *
	 * A refused read is null, never []: get_results() returns [] for a failed SELECT, so `last_error` decides.
	 *
	 * @param array<int|string, mixed> $string_ids  String ids.
	 * @param int                      $language_id Language id.
	 * @return array<int, array<string, mixed>>|null Keyed by string id; strings that do not exist are absent. Null when the read failed.
	 * @phpstan-return array<int, StringState>|null
	 */
	public static function snapshot( array $string_ids, int $language_id ): ?array {
		$ids = [];

		foreach ( $string_ids as $id ) {
			$id = Scalar::int( $id );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( [] === $ids || $language_id <= 0 ) {
			return [];
		}

		$wpdb = self::db();
		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.group_id, s.domain, s.context, s.original, s.original_hash, t.translation, t.extra_forms, l.status AS link_status, l.source AS link_source, l.id AS link_id
				 FROM %i s
				 LEFT JOIN %i t ON t.string_id = s.id AND t.language_id = %d
				 LEFT JOIN %i l ON l.type = %s AND l.object_id = s.id AND l.language_id = %d
				 WHERE s.id IN ({$in})",
				array_merge(
					[
						Schema::table( 'strings' ),
						Schema::table( 'string_translations' ),
						$language_id,
						Schema::table( 'translation_links' ),
						ObjectType::String->value,
						$language_id,
					],
					$ids
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( $rows as $row ) {
			$out[ Scalar::int( $row['id'] ?? 0 ) ] = [
				'group_id'      => Scalar::int( $row['group_id'] ?? 0 ),
				'domain'        => Scalar::str( $row['domain'] ?? '' ),
				'context'       => Scalar::str( $row['context'] ?? '' ),
				'original'      => Scalar::str( $row['original'] ?? '' ),
				'original_hash' => Scalar::str( $row['original_hash'] ?? '' ),
				'translation'   => Scalar::str( $row['translation'] ?? '' ),
				'extra_forms'   => self::stored_forms( Scalar::str( $row['extra_forms'] ?? '' ) ),
				// The bytes write() and restore() compare: null without a value row, or for NULL forms.
				'stored'        => [
					null === ( $row['translation'] ?? null ) ? null : Scalar::str( $row['translation'] ),
					null === ( $row['extra_forms'] ?? null ) ? null : Scalar::str( $row['extra_forms'] ),
				],
				'link'          => [
					'exists' => null !== ( $row['link_id'] ?? null ),
					'status' => Scalar::str( $row['link_status'] ?? '' ),
					'source' => Scalar::str( $row['link_source'] ?? '' ),
				],
			];
		}

		return $out;
	}

	/**
	 * The live state of one string translation.
	 *
	 * @param int $string_id   String id.
	 * @param int $language_id Language id.
	 * @return array<string, mixed>|false|null Null when the string does not exist, false when the read failed.
	 * @phpstan-return StringState|false|null
	 */
	public static function state( int $string_id, int $language_id ): array|false|null {
		$states = self::snapshot( [ $string_id ], $language_id );

		if ( null === $states ) {
			return false;
		}

		return $states[ $string_id ] ?? null;
	}

	/**
	 * The locale of a language row, read from the table (no cache).
	 *
	 * @param int $language_id Language id.
	 * @return string|false|null
	 */
	public static function language_locale( int $language_id ): string|false|null {
		if ( $language_id <= 0 ) {
			return null;
		}

		$wpdb = self::db();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$locale = $wpdb->get_var( $wpdb->prepare( 'SELECT locale FROM %i WHERE id = %d', Schema::table( 'languages' ), $language_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $locale ? null : Scalar::str( $locale );
	}

	/**
	 * The value an undo restores, taken from a state.
	 *
	 * @param array<string, mixed> $state State.
	 * @phpstan-param StringState $state
	 * @return array<string, mixed>
	 * @phpstan-return Previous
	 */
	public static function previous_of( array $state ): array {
		return [
			'translation' => $state['translation'],
			'extra_forms' => $state['extra_forms'],
			'link'        => $state['link'],
		];
	}

	/**
	 * The value an undo restores, from a stored `previous`.
	 *
	 * @param array<mixed> $stored The decoded value.
	 * @return array<string, mixed>
	 * @phpstan-return Previous
	 */
	public static function previous_from( array $stored ): array {
		$link = is_array( $stored['link'] ?? null ) ? $stored['link'] : [];

		return [
			'translation' => Scalar::str( $stored['translation'] ?? '' ),
			'extra_forms' => Scalar::strings( $stored['extra_forms'] ?? [] ),
			'link'        => [
				'exists' => ! empty( $link['exists'] ),
				'status' => Scalar::str( $link['status'] ?? '' ),
				'source' => Scalar::str( $link['source'] ?? '' ),
			],
		];
	}

	/**
	 * Whether a state read back holds what restore() writes: value, forms, and the link as restore() writes it
	 * (a previous link without a status is not written, so not compared).
	 *
	 * @param array<string, mixed> $state    The state read back.
	 * @phpstan-param StringState $state
	 * @param array<mixed>         $previous The value restored (previous_of()).
	 * @return bool
	 */
	public static function holds( array $state, array $previous ): bool {
		if ( self::hash( $state['translation'], $state['extra_forms'] ) !== self::hash( Scalar::str( $previous['translation'] ?? '' ), Scalar::strings( $previous['extra_forms'] ?? [] ) ) ) {
			return false;
		}

		$link = is_array( $previous['link'] ?? null ) ? $previous['link'] : [];

		if ( empty( $link['exists'] ) ) {
			return ! $state['link']['exists'];
		}

		$status = sanitize_key( Scalar::str( $link['status'] ?? '' ) );
		$source = sanitize_key( Scalar::str( $link['source'] ?? '' ) );

		if ( '' === $status ) {
			return true;
		}

		return $state['link']['exists'] && $status === $state['link']['status'] && ( '' === $source || $source === $state['link']['source'] );
	}

	/**
	 * The previous value of a translation that did not exist.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Previous
	 */
	public static function nothing(): array {
		return [
			'translation' => '',
			'extra_forms' => [],
			'link'        => [
				'exists' => false,
				'status' => '',
				'source' => '',
			],
		];
	}

	/**
	 * Write a value and its forms (an approved change).
	 *
	 * @param int                                        $string_id   String id.
	 * @param int                                        $language_id Language id.
	 * @param string                                     $translation Value (not empty).
	 * @param array<int, string>|null                    $extra_forms Forms 2..N ([] clears stored forms; null keeps them).
	 * @param SourceType                                 $source      Provenance written on the link.
	 * @param array{0: string|null, 1: string|null}      $read   The cell as the caller compared it (snapshot()'s `stored`):
	 *                                                          written only while it is so.
	 * @param array{source: string, locale: string}|null $target Checked inside the write unit (a Replace import keeps ids).
	 * @return string Empty string on success, CELL_MOVED, TARGET_GONE, else the reason nothing (or not everything) was stored.
	 */
	public static function write( int $string_id, int $language_id, string $translation, ?array $extra_forms, SourceType $source, array $read, ?array $target = null ): string {
		if ( '' === $translation ) {
			return __( 'An empty translation cannot be saved.', 'perflocale' );
		}

		$row = self::string_row( $string_id );

		if ( null === $row ) {
			return __( 'The string no longer exists.', 'perflocale' );
		}

		$restriction = self::restriction( $row );

		if ( '' !== $restriction ) {
			return $restriction;
		}

		return self::write_as_read( $row, $language_id, $translation, $extra_forms, $source, $read, $target );
	}

	/**
	 * Restore a previous state (an undone change): no previous value deletes it, and the link when none
	 * existed; a link write the database refuses fails the restore.
	 *
	 * @param int                                        $string_id   String id.
	 * @param int                                        $language_id Language id.
	 * @param array<mixed>                               $previous    From previous_of().
	 * @param array{0: string|null, 1: string|null}|null $read        The cell as the caller compared it; null: unconditional.
	 * @return string Empty string on success, CELL_MOVED, else the reason.
	 */
	public static function restore( int $string_id, int $language_id, array $previous, ?array $read = null ): string {
		return self::restore_checked( $string_id, $language_id, $previous, $read, null );
	}

	/**
	 * Restore, with $check run inside the compare-and-set unit before its value statement (false: nothing written).
	 *
	 * @param int                                        $string_id   String id.
	 * @param int                                        $language_id Language id.
	 * @param array<mixed>                               $previous    From previous_of().
	 * @param array{0: string|null, 1: string|null}|null $read        The cell as read; null: unconditional, $check unused.
	 * @param callable|null                              $check       fn(): bool.
	 * @return string As restore(); '' when $check refused.
	 */
	private static function restore_checked( int $string_id, int $language_id, array $previous, ?array $read, ?callable $check ): string {
		$row = self::string_row( $string_id );

		if ( null === $row ) {
			return __( 'The string no longer exists.', 'perflocale' );
		}

		$restriction = self::restriction( $row );

		if ( '' !== $restriction ) {
			return $restriction;
		}

		if ( null !== $read ) {
			return self::restore_as_read( $row, $language_id, $previous, $read, $check );
		}

		$translation = Scalar::str( $previous['translation'] ?? '' );
		$forms       = Scalar::strings( $previous['extra_forms'] ?? [] );
		$link        = is_array( $previous['link'] ?? null ) ? $previous['link'] : [];
		$plugin      = Plugin::get_instance();
		$repo        = new StringTranslationRepository( $plugin->cache() );

		if ( '' === $translation ) {
			if ( ! $repo->delete( $string_id, $language_id ) ) {
				return __( 'The translation could not be removed.', 'perflocale' );
			}
		} else {
			if ( ! $repo->set( $string_id, $language_id, $translation ) ) {
				return __( 'The previous translation could not be written back.', 'perflocale' );
			}

			if ( ! $repo->set_extra_forms( $string_id, $language_id, [] === $forms ? null : $forms ) ) {
				return __( 'The plural forms could not be written back.', 'perflocale' );
			}
		}

		self::touch( $language_id );

		if ( empty( $link['exists'] ) ) {
			return self::delete_link( $string_id, $language_id, $row->group_id ) ? '' : __( 'The previous translation could not be written back.', 'perflocale' );
		}

		$status = Scalar::str( $link['status'] ?? '' );

		if ( '' !== $status && ! $plugin->group_repo()->update_link_status( $string_id, $language_id, $status, Scalar::str( $link['source'] ?? '' ), ObjectType::String ) ) {
			return __( 'The previous translation could not be written back.', 'perflocale' );
		}

		return '';
	}

	/**
	 * Restore a previous state only into the string and language the change was written into.
	 *
	 * In one transaction the rows are read with locking reads (a Replace import waits or has committed),
	 * compared with the target, then restore() runs; a mismatch writes nothing and sets $refused.
	 *
	 * @param int                                        $string_id   String id.
	 * @param int                                        $language_id Language id.
	 * @param array<mixed>                               $previous    From previous_of().
	 * @param array{source: string, locale: string}      $target The string's original_hash and the language's locale the change was written into ('' is not compared).
	 * @param string                                     $refused     Receives `target_gone` (another target) or `busy` (a read failed) when nothing was written for the target; '' otherwise.
	 * @param array{0: string|null, 1: string|null}|null $read   The cell as the caller compared it: then the target is compared
	 *                                                           inside the compare-and-set unit (its own transaction).
	 * @return string Empty string on success, CELL_MOVED, else the reason (as restore()).
	 */
	public static function restore_into( int $string_id, int $language_id, array $previous, array $target, string &$refused = '', ?array $read = null ): string {
		$refused = '';
		$wpdb    = self::db();
		$own     = ! TranslationGroupWrites::in_transaction();

		// A START TRANSACTION inside a caller's would commit it on MySQL: there the unconditional restore runs.
		if ( null !== $read && $own ) {
			$check = static function () use ( $string_id, $language_id, $target, &$refused ): bool {
				$refused = self::target_differs( $string_id, $language_id, $target );

				return '' === $refused;
			};

			return self::restore_checked( $string_id, $language_id, $previous, $read, $check );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		if ( $own && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return __( 'The previous translation could not be written back.', 'perflocale' );
		}

		$refused = self::target_differs( $string_id, $language_id, $target );
		$error   = '' === $refused ? self::restore( $string_id, $language_id, $previous ) : '';

		if ( ! $own ) {
			return $error;
		}

		if ( '' !== $refused || '' !== $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );

			return $error;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );

			return __( 'The previous translation could not be written back.', 'perflocale' );
		}

		return '';
	}

	/**
	 * Whether the ids still name a recorded target, read with locking reads (none on SQLite, one writer at a
	 * time). The string's row is locked whether or not a source is recorded.
	 *
	 * @param int                                   $string_id   String id.
	 * @param int                                   $language_id Language id.
	 * @param array{source: string, locale: string} $target      The recorded target ('' is not compared).
	 * @return string '' (the same target), `target_gone` or `busy` (a read failed).
	 */
	private static function target_differs( int $string_id, int $language_id, array $target ): string {
		$wpdb    = self::db();
		$locking = ! ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The locking clause is a fixed literal.
		$hash = self::read_value( $wpdb->prepare( 'SELECT original_hash FROM %i WHERE id = %d' . ( $locking ? ' FOR UPDATE' : '' ), Schema::table( 'strings' ), $string_id ) );

		if ( false === $hash ) {
			return 'busy';
		}

		if ( null === $hash || ( '' !== $target['source'] && ! \PerfLocale\Strings\StrIdentMigration::same_source_id( $target['source'], $hash, $string_id ) ) ) {
			return 'target_gone';
		}

		if ( '' === $target['locale'] ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The locking clause is a fixed literal.
		$locale = self::read_value( $wpdb->prepare( 'SELECT locale FROM %i WHERE id = %d' . ( $locking ? ' LOCK IN SHARE MODE' : '' ), Schema::table( 'languages' ), $language_id ) );

		if ( false === $locale ) {
			return 'busy';
		}

		return $locale === $target['locale'] ? '' : 'target_gone';
	}

	/**
	 * One value read by a prepared statement: null when no row holds it, false when the read failed.
	 *
	 * @param string|null $sql Prepared SQL.
	 * @return string|false|null
	 */
	private static function read_value( ?string $sql ): string|false|null {
		$wpdb = self::db();

		if ( null === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The caller prepared it.
		$value = $wpdb->get_var( $sql );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $value ? null : Scalar::str( $value );
	}

	/**
	 * Write while the cell is as $read, in one StringCellWrite unit: never over another writer's value.
	 *
	 * @param \stdClass                             $row         The string row (id, group_id, domain).
	 * @param int                                   $language_id Language id.
	 * @param string                                $translation Value.
	 * @param array<int, string>|null               $extra_forms Forms 2..N (null: the forms as read).
	 * @param SourceType                            $source      Label of the link.
	 * @param array{0: string|null, 1: string|null} $read        The cell as read.
	 * @param array{source: string, locale: string}|null $target Checked inside the write unit.
	 * @return string As write().
	 */
	private static function write_as_read( \stdClass $row, int $language_id, string $translation, ?array $extra_forms, SourceType $source, array $read, ?array $target = null ): string {
		$groups   = Plugin::get_instance()->group_repo();
		$group_id = self::string_group( $groups, $row );

		if ( $group_id <= 0 ) {
			return __( 'The translation could not be saved: its translation group could not be repaired.', 'perflocale' );
		}

		$forms   = null === $extra_forms ? $read[1] : StringTranslationRepository::normalize_extra_forms( $extra_forms );
		$id      = Scalar::int( $row->id );
		$why     = '';
		$check   = null === $target ? null : static function () use ( $id, $language_id, $target, &$why ): bool {
			$why = self::target_differs( $id, $language_id, $target );

			return '' === $why;
		};
		$outcome = StringCellWrite::save( $groups, $group_id, $id, $language_id, $read[0], $read[1], $translation, $forms, false, $source, true, $check, null, 'translated' );

		if ( '' !== $why ) {
			return 'target_gone' === $why ? self::TARGET_GONE : __( 'The translation could not be saved: its string could not be read.', 'perflocale' );
		}

		return self::cell_written( $outcome, $groups, $group_id, $id, $language_id, $translation, $forms, $read, 'translated', $source );
	}

	/**
	 * Restore while the cell is as $read: value (or its removal), label and the previous link status in one
	 * StringCellWrite unit, so an Undo never writes past another writer or (with $check) into another target.
	 *
	 * @param \stdClass                             $row         The string row (id, group_id, domain).
	 * @param int                                   $language_id Language id.
	 * @param array<mixed>                          $previous    From previous_of().
	 * @param array{0: string|null, 1: string|null} $read        The cell as read.
	 * @param callable|null                         $check       Run inside the unit; false refuses it.
	 * @param bool                                  $refused     Set when $check refused the unit (nothing written).
	 * @return string As restore_checked().
	 */
	private static function restore_as_read( \stdClass $row, int $language_id, array $previous, array $read, ?callable $check, bool &$refused = false ): string {
		$groups      = Plugin::get_instance()->group_repo();
		$group_id    = self::string_group( $groups, $row );
		$translation = Scalar::str( $previous['translation'] ?? '' );
		$forms       = StringTranslationRepository::normalize_extra_forms( Scalar::strings( $previous['extra_forms'] ?? [] ) );
		$link        = is_array( $previous['link'] ?? null ) ? $previous['link'] : [];
		$status      = Scalar::str( $link['status'] ?? '' );
		$label       = SourceType::tryFrom( Scalar::str( $link['source'] ?? '' ) ) ?? SourceType::Manual;
		$string_id   = Scalar::int( $row->id );
		$checked     = null === $check ? null : static function () use ( $check, &$refused ): bool {
			$refused = true !== $check();

			return ! $refused;
		};

		if ( $group_id <= 0 ) {
			return __( 'The previous translation could not be written back.', 'perflocale' );
		}

		// The previous link as it was: none (remove() already drops it while no value row exists), or its status and source.
		// On MySQL/MariaDB ($guarded set) save() writes the status in its own guarded statement and this one carries the marker.
		$made_link = false;
		$relink    = static function ( string $guarded = '' ) use ( $group_id, $groups, $string_id, $language_id, $translation, $link, $status, $label, &$made_link ): bool {
			$made_link = false;

			if ( empty( $link['exists'] ) ) {
				return '' === $translation || self::delete_link( $string_id, $language_id, $group_id, $guarded );
			}

			if ( '' === $status || ( '' !== $guarded && '' !== $translation ) ) {
				return true;
			}

			if ( '' === $translation && '' !== $guarded ) {
				$made      = StringCellWrite::link_if_none( $group_id, $string_id, $language_id, $status, $label, $guarded );
				$made_link = 1 === $made;

				return false !== $made;
			}

			if ( '' === $translation ) {
				return false !== $groups->upsert_link( $group_id, $string_id, $language_id, $status, $label );
			}

			return $groups->update_link_status( $string_id, $language_id, $status, Scalar::str( $link['source'] ?? '' ), ObjectType::String );
		};

		$relinks = ! empty( $link['exists'] ) ? ( '' === $status ? 'translated' : $status ) : null;

		$outcome = '' === $translation
			? StringCellWrite::remove( $groups, $group_id, $string_id, $language_id, $read[0], $read[1], false, true, $checked, $relink )
			: StringCellWrite::save( $groups, $group_id, $string_id, $language_id, $read[0], $read[1], $translation, $forms, false, $label, true, $checked, $relink, $relinks );

		// Listeners hear of a link the unit made once it is committed, never inside its transaction.
		if ( $made_link && 'saved' === $outcome ) {
			StringLinkLabel::announce( $groups, $group_id, $string_id, $language_id );
		}

		if ( 'refused' === $outcome ) {
			return $refused ? '' : __( 'The previous translation could not be written back.', 'perflocale' );
		}

		return 'none' === $outcome ? '' : self::cell_written( $outcome, $groups, $group_id, $string_id, $language_id, $translation, $forms, $read, '' === $status ? 'translated' : $status, $label, ! empty( $link['exists'] ) );
	}

	/**
	 * The answer of write_as_read() and restore_as_read() from a StringCellWrite outcome; an `unlinked` cell is linked, or
	 * put back as read when that fails.
	 *
	 * @param string                                                     $outcome     StringCellWrite's outcome.
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $groups      Group/link repository.
	 * @param int                                                        $group_id    String group.
	 * @param int                                                        $string_id   String id.
	 * @param int                                                        $language_id Language id.
	 * @param string                                                     $text        Form 1 written ('' : removed).
	 * @param string|null                                                $forms       extra_forms written.
	 * @param array{0: string|null, 1: string|null}                      $read        The cell as read.
	 * @param string                                                     $status      Status of a link created here.
	 * @param SourceType                                                 $source      Label of a link created here.
	 * @param bool                                                       $link        Whether an unlinked cell gets a link.
	 * @return string '' when the cell holds the wanted value, CELL_MOVED, or the reason.
	 */
	private static function cell_written( string $outcome, \PerfLocale\Database\Repository\TranslationGroupRepository $groups, int $group_id, int $string_id, int $language_id, string $text, ?string $forms, array $read, string $status, SourceType $source, bool $link = true ): string {
		if ( 'stale' === $outcome || 'mismatch' === $outcome ) {
			return self::CELL_MOVED;
		}

		if ( 'unlinked' === $outcome && ( ! $link || false !== $groups->upsert_link( $group_id, $string_id, $language_id, $status, $source ) ) ) {
			$outcome = 'saved';
		}

		if ( 'unlinked' === $outcome ) {
			// No link serves the value: put the row back while it is still this write's.
			$back = null === $read[0]
				? StringCellWrite::clear( $string_id, $language_id, $text, $forms )
				: StringCellWrite::write( $string_id, $language_id, $text, $forms, $read[0], $read[1] );

			if ( 1 !== $back ) {
				self::touch( $language_id );
			}

			return __( 'The translation could not be linked to its language, so it was not saved.', 'perflocale' );
		}

		if ( 'saved' !== $outcome && 'same' !== $outcome ) {
			return __( 'The translation could not be written.', 'perflocale' );
		}

		self::touch( $language_id );

		return '';
	}

	/**
	 * The string's group, repaired into a string group first when it is not one (StringValueWriter::mint_group()).
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $groups Group/link repository.
	 * @param \stdClass                                                  $row    The string row; its group_id is updated.
	 * @return int The group, or 0 when the repair failed.
	 */
	private static function string_group( \PerfLocale\Database\Repository\TranslationGroupRepository $groups, \stdClass $row ): int {
		$group_id = Scalar::int( $row->group_id ?? 0 );

		if ( ! $groups->is_string_group( $group_id ) ) {
			$group_id      = StringValueWriter::mint_group( Scalar::int( $row->id ?? 0 ), $group_id );
			$row->group_id = $group_id;
		}

		return $group_id;
	}

	/**
	 * Flush the string caches and files for every language written since the last flush, and fire
	 * `perflocale/strings/changed` once, as the Strings screen's save does.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$blog_id = (int) get_current_blog_id();

		if ( empty( self::$touched[ $blog_id ] ) ) {
			return;
		}

		$touched = array_keys( self::$touched[ $blog_id ] );
		unset( self::$touched[ $blog_id ] );

		$plugin = Plugin::get_instance();
		$cache  = $plugin->cache();

		$languages = $plugin->lang_repo()->get_active();

		foreach ( $languages as $lang ) {
			$cache->delete( 'all_str2_translations_' . Scalar::int( Scalar::prop( $lang, 'id' ) ), 'perflocale_strings' );
		}

		require_once __DIR__ . '/../Cache/CacheDiagnostics.php';
		\PerfLocale\Cache\CacheDiagnostics::forget_map_sizes( $cache, array_column( $languages, 'id' ) );

		if ( 'files' === Scalar::str( $plugin->settings()->get( 'string_translation_mode', '' ) ) ) {
			( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->generate_all( $touched );
		}

		/**
		 * Fires after string translations change.
		 *
		 * @hook perflocale/strings/changed
		 *
		 * @param string $origin What changed the strings ('automation').
		 */
		do_action( 'perflocale/strings/changed', 'automation' );
	}

	/**
	 * Remember a written language for the next flush.
	 *
	 * @param int $language_id Language id.
	 * @return void
	 */
	public static function touch( int $language_id ): void {
		self::$touched[ (int) get_current_blog_id() ][ $language_id ] = true;
	}

	/**
	 * WordPress's database object.
	 *
	 * @return \wpdb
	 * @throws \RuntimeException When it is missing.
	 */
	private static function db(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		return $wpdb;
	}

	/**
	 * The `strings` row the writer needs (id and group id).
	 *
	 * @param int $string_id String id.
	 * @return \stdClass|null
	 */
	private static function string_row( int $string_id ): ?\stdClass {
		if ( $string_id <= 0 ) {
			return null;
		}

		$wpdb = self::db();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, group_id, domain FROM %i WHERE id = %d', Schema::table( 'strings' ), $string_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $row ) ) {
			return null;
		}

		$out           = new \stdClass();
		$out->id       = Scalar::int( $row['id'] ?? 0 );
		$out->group_id = Scalar::int( $row['group_id'] ?? 0 );
		$out->domain   = Scalar::str( $row['domain'] ?? '' );

		return $out;
	}

	/**
	 * Why the acting user may not write a string (a domain `perflocale/strings/domain_capability` keeps), or ''.
	 *
	 * @param \stdClass $row The string row (id, group_id, domain).
	 * @return string
	 */
	private static function restriction( \stdClass $row ): string {
		if ( Domains::is_restricted( Scalar::str( $row->domain ?? '' ) ) ) {
			return __( 'The text domain of this string is not available to your account.', 'perflocale' );
		}

		return '';
	}

	/**
	 * Whether the string's domain is kept from the acting user. No query while no domain is restricted.
	 *
	 * @param int $string_id String id.
	 * @return bool
	 */
	public static function is_restricted( int $string_id ): bool {
		if ( [] === Domains::restricted_for_current_user() ) {
			return false;
		}

		$row = self::string_row( $string_id );

		return null !== $row && '' !== self::restriction( $row );
	}

	/**
	 * Delete the link of a string in a language (the object_lang key).
	 *
	 * @param int    $string_id   String id.
	 * @param int    $language_id Language id.
	 * @param mixed  $group_id    The string's group, whose cache is dropped.
	 * @param string $guarded     A StringCellWrite unit's connection condition (' AND ...'), '' outside one.
	 * @return bool False when the database refused the DELETE.
	 */
	private static function delete_link( int $string_id, int $language_id, mixed $group_id, string $guarded = '' ): bool {
		$wpdb = self::db();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Removes the link an undone change created; the marker is a checked literal.
		if ( '' === $guarded ) {
			$deleted = $wpdb->delete(
				Schema::table( 'translation_links' ),
				[
					'type'        => ObjectType::String->value,
					'object_id'   => $string_id,
					'language_id' => $language_id,
				],
				[ '%s', '%d', '%d' ]
			);
		} else {
			$sql     = $wpdb->prepare( 'DELETE FROM %i WHERE type = %s AND object_id = %d AND language_id = %d', Schema::table( 'translation_links' ), ObjectType::String->value, $string_id, $language_id );
			$deleted = is_string( $sql ) ? $wpdb->query( $sql . $guarded ) : false; // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepare() result; $guarded is the unit's checked condition.
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$group_id = Scalar::int( $group_id );

		if ( $group_id > 0 ) {
			Plugin::get_instance()->group_repo()->invalidate_group_cache( $group_id );
		}

		return false !== $deleted;
	}
}
