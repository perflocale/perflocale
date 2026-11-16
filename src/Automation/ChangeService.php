<?php
/**
 * Automation change service.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\Repository\PendingChangeRepository as Repo;
use PerfLocale\Database\Repository\ResyncRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Helper;
use PerfLocale\Resync\ChangeBuilder;
use PerfLocale\Resync\HumanEdit;
use PerfLocale\Resync\SaveProbe;
use PerfLocale\Resync\Store;
use PerfLocale\Resync\SyncRecorder;
use PerfLocale\Translation\ContentChangeDetector;
use PerfLocale\Translation\PlaceholderMasker;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records, holds, approves, rejects and undoes automatic changes. A decision acts as and checks the current user, under
 * the target's lock (re-read, check, write, then a compare-and-set status change); writes run inside
 * {@see self::writing()}.
 *
 * @phpstan-import-type ChangeRow from Repo
 * @phpstan-import-type LiveRead from PostTarget
 * @phpstan-import-type Payload from PostPayload
 * @phpstan-import-type BaseRow from ResyncRepository
 * @phpstan-import-type Stored from SaveProbe
 * @phpstan-import-type StringState from StringTarget as StringTargetState
 * @phpstan-type WriteResult array{ok: bool, reason: string, read: LiveRead|null, previous: array<string, mixed>, intent: string, state: array<string, mixed>, bases: array<int, int>, written: list<string>, base: BaseRow|null, source_hash: string, quality: string, expected: array{post_hash: string, fields_digest: string, fields: array<string, string|null>}}
 */
final class ChangeService {

	/** Seconds a string target's lock lives without release. */
	private const LOCK_TTL = 60;

	/** Default TTL of a post target's lock without renewal, and the bounds of its filter. */
	public const TARGET_LOCK_TTL     = 300;
	public const TARGET_LOCK_TTL_MIN = 60;
	public const TARGET_LOCK_TTL_MAX = 3600;

	/** The reason of a write that stopped because it lost its target lock. */
	private const LOCK_LOST = 'lock_lost';

	/** A settled string intent: the decision goes on from the state read. */
	private const SETTLED_CLEAR = 'clear';

	/** A settled string intent: another writer's text stands. */
	private const SETTLED_MOVED = 'moved';

	/** Key of a reverting string row's `previous` holding the state an Undo stopped between value and forms leaves. */
	private const STOPPED_UNDO = 'stopped_undo';

	/** Key of an applied string row's `previous` naming the target it was written into (original_hash, locale). */
	private const TARGET = 'target';

	/** The areas of a resync write to a post. */
	public const RESYNC_AREAS = [ 'published_update', 'draft_update' ];

	/**
	 * Object cache key and group of the pending count. The stored key carries the group's generation
	 * (badge_cache_key()), so CacheManager::flush_all() drops it too.
	 */
	public const BADGE_KEY   = 'automation_pending_count';
	public const BADGE_GROUP = 'perflocale';

	/**
	 * Nesting depth of automatic writes in this request.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Posts automatic writes are writing now, `{blog}:{post}` => nesting depth.
	 *
	 * @var array<string, int>
	 */
	private static array $writing_posts = [];

	/**
	 * Blogs whose pending count changed since the last end_batch().
	 *
	 * @var array<int, true>
	 */
	private static array $badge_dirty = [];

	/**
	 * Whether this blog has the automation tables (database version 2 or later).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return \PerfLocale\Database\Repository\PendingChangeReads::available();
	}

	/**
	 * The TTL of a post target's lock, read once per write: take and renew use the same value.
	 *
	 * @return int Seconds, between TARGET_LOCK_TTL_MIN and TARGET_LOCK_TTL_MAX.
	 */
	public static function target_lock_ttl(): int {
		/**
		 * Filters how long a ReSync write's lock on its translation lives without renewal (60 to 3600).
		 *
		 * @internal Not public: a safety lock; a wrong value lets two writers collide.
		 * @hook  perflocale/resync/target_lock_ttl
		 * @since 1.0.8
		 *
		 * @param int $ttl Seconds. Default 300.
		 */
		/**
		 * A filter may return anything; only a whole number is taken.
		 *
		 * @var mixed $ttl
		 */
		$ttl = apply_filters( 'perflocale/resync/target_lock_ttl', self::TARGET_LOCK_TTL );

		if ( is_string( $ttl ) ) {
			$ttl = Scalar::parse_int( $ttl );
		}

		if ( ! is_int( $ttl ) ) {
			return self::TARGET_LOCK_TTL;
		}

		return min( self::TARGET_LOCK_TTL_MAX, max( self::TARGET_LOCK_TTL_MIN, $ttl ) );
	}

	/**
	 * Run an automatic write; automation listeners return while it runs. With a post id, that post is
	 * marked as written by automation, so ContentSync takes no Sync Fields action on its saves meanwhile.
	 *
	 * @param callable $callback Write.
	 * @param int      $post_id  The post the write writes, 0 for none (a string write).
	 * @return mixed The callback's return value.
	 */
	public static function writing( callable $callback, int $post_id = 0 ): mixed {
		$key = $post_id > 0 ? get_current_blog_id() . ':' . $post_id : '';

		++self::$writing;

		if ( '' !== $key ) {
			self::$writing_posts[ $key ] = ( self::$writing_posts[ $key ] ?? 0 ) + 1;
		}

		try {
			return $callback();
		} finally {
			--self::$writing;

			if ( '' !== $key && --self::$writing_posts[ $key ] <= 0 ) {
				unset( self::$writing_posts[ $key ] );
			}
		}
	}

	/**
	 * Whether an automatic write is running in this request.
	 *
	 * @return bool
	 */
	public static function is_writing(): bool {
		return self::$writing > 0;
	}

	/**
	 * Whether an automatic write is writing this post of the current blog now.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_writing_post( int $post_id ): bool {
		return isset( self::$writing_posts[ get_current_blog_id() . ':' . $post_id ] );
	}

	/**
	 * Record changes the producer already wrote (status applied).
	 *
	 * One INSERT per 100 rows, or one by one while `perflocale/automation/applied` has a listener.
	 *
	 * @param array<int, array<string, mixed>> $changes Change rows (see PendingChangeRepository).
	 * @return int Rows recorded.
	 */
	public static function record_applied( array $changes ): int {
		if ( [] === $changes || ! self::available() ) {
			return 0;
		}

		$now  = Repo::now();
		$rows = [];

		foreach ( $changes as $change ) {
			$change['status']     = Repo::STATUS_APPLIED;
			$change['decided_at'] = $now;
			$change['decided_by'] = 0;
			$change['payload']    = '';
			$rows[]               = $change;
		}

		$repo = new Repo();

		$count = 0;

		if ( ! has_action( 'perflocale/automation/applied' ) ) {
			foreach ( array_chunk( $rows, 100 ) as $chunk ) {
				$count += $repo->insert_many( $chunk );
			}
		} else {
			foreach ( $rows as $row ) {
				$id = $repo->insert( $row );

				if ( $id <= 0 ) {
					continue;
				}

				++$count;
				self::fire( 'applied', $repo->find( $id ) );
			}
		}

		if ( $count < count( $rows ) ) {
			self::log( sprintf( '%d of %d applied changes could not be recorded.', count( $rows ) - $count, count( $rows ) ) );
		}

		return $count;
	}

	/**
	 * Write a line to the debug log (only with WP_DEBUG_LOG on).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'PerfLocale automation: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only log; the caller's result is unchanged.
		}
	}

	/**
	 * Record one refused change (for example one stopped by the machine translation limit).
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return int The new id, or 0.
	 */
	public static function record_refused( array $change ): int {
		if ( ! self::available() ) {
			return 0;
		}

		$change['status']     = Repo::STATUS_REFUSED;
		$change['decided_at'] = Repo::now();
		$change['decided_by'] = 0;
		$change['payload']    = '';

		return ( new Repo() )->insert( $change );
	}

	/**
	 * Whether a post change was computed from its source as stored now, by the hashes approve() checks.
	 *
	 * @param array<string, mixed> $change Change row with its summary.
	 * @return bool
	 */
	private static function source_current( array $change ): bool {
		$summary = is_array( $change['summary'] ?? null ) ? $change['summary'] : Repo::decode( Scalar::str( $change['summary'] ?? '' ) );
		$source  = Scalar::int( $change['source_id'] ?? 0 );
		$hash    = Scalar::str( $summary['source_hash'] ?? '' );
		$fields  = Scalar::str( $summary['fields_hash'] ?? '' );
		$keys    = Scalar::strings( $summary['fields'] ?? [] );
		$read    = '' === $hash ? null : PostTarget::read( $source, [] );

		if ( '' !== $hash && ( null === $read || Store::content_hash( $read['post']->post_title, $read['post']->post_content, $read['post']->post_excerpt ) !== $hash ) ) {
			return false;
		}

		return '' === $fields || Store::digest_for_keys( $keys, PostTarget::field_values( $source, $keys ) ) === $fields;
	}

	/**
	 * Hold a change for review, superseding the target's older pending change.
	 *
	 * @param array<string, mixed> $change  Change row with its payload.
	 * @param array<string, mixed> $options `import_generation`: the producer's import_fence(); an import since refuses the hold.
	 * @return array{status: string, id: int, reason?: string} status: held, busy (the target is locked; reason `import`
	 *                                        when an import ran or runs), stale (a post change computed from a source
	 *                                        saved since), failed, target_gone (its language was deleted) or unavailable.
	 */
	public static function hold( array $change, array $options = [] ): array {
		if ( ! self::available() ) {
			return [
				'status' => 'unavailable',
				'id'     => 0,
			];
		}

		$fence = self::fence_option( $options );

		// Read before the target lock too: on SQLite that lock waits on an import's writer lock instead of answering.
		if ( false !== $fence ? self::import_moved( $fence ) : self::import_running() ) {
			return [
				'status' => 'busy',
				'id'     => 0,
				'reason' => 'import',
			];
		}

		$type   = Scalar::str( $change['object_type'] ?? '' );
		$object = Scalar::int( $change['object_id'] ?? 0 );
		$lang   = Scalar::int( $change['language_id'] ?? 0 );

		$change['status']     = Repo::STATUS_PENDING;
		$change['decided_at'] = null;
		$change['decided_by'] = 0;

		$result = null;
		$ran    = Lock::with(
			self::lock_name( $type, $object, $lang ),
			'post' === $type ? self::target_lock_ttl() : self::LOCK_TTL,
			static function () use ( $change, $type, $object, $lang, $fence, &$result ): bool {
				// During an import (a Replace wipe locks the rows a hold writes) the hold is refused, not left waiting; a
				// fenced hold asks inside its transaction instead.
				if ( false === $fence && self::import_running() ) {
					$result = [
						'status' => 'busy',
						'id'     => 0,
						'reason' => 'import',
					];

					return true;
				}

				$repo = new Repo();

				// A string approval that stopped is settled before its row is
				// superseded, so the value it replaced is never lost with it.
				if ( 'string' === $type && ! self::settle_target_intent( $repo, $object, $lang ) ) {
					$result = [
						'status' => 'busy',
						'id'     => 0,
					];

					return true;
				}

				// A run that finished late never replaces a newer proposal with one approve() would close (Codex ASTRA-12).
				if ( 'post' === $type && ! self::source_current( $change ) ) {
					$result = [
						'status' => 'stale',
						'id'     => 0,
					];

					return true;
				}

				// The new row and the supersede of the older one land together or not at all; an import counted since
				// the producer's read refuses it inside that transaction (one that starts later waits for it).
				$moved = false;
				$id    = $repo->insert_superseding( $change, is_int( $fence ) ? static fn (): bool => \PerfLocale\Admin\ImportLock::still( $fence ) : null, $moved );

				if ( $id <= 0 ) {
					$result = [
						'status' => $moved ? 'busy' : 'failed',
						'id'     => 0,
					] + ( $moved ? [ 'reason' => 'import' ] : [] );

					return true;
				}

				// After the insert: a language delete commits its row before it reads the pending ids, so a row it missed sees the language gone.
				if ( false === $repo->language_exists( $lang ) ) {
					$repo->close_pending( [ $id ], 'target_gone' );
					$result = [
						'status' => 'target_gone',
						'id'     => 0,
					];

					return true;
				}

				$result = [
					'status' => 'held',
					'id'     => $id,
				];

				return true;
			}
		);

		if ( 'string' === $type ) {
			StringTarget::flush();
		}

		if ( true !== $ran || null === $result ) {
			return [
				'status' => 'busy',
				'id'     => 0,
			];
		}

		if ( 'held' === $result['status'] ) {
			self::mark_badge_dirty();

			if ( has_action( 'perflocale/automation/held' ) ) {
				self::fire( 'held', ( new Repo() )->find( $result['id'] ) );
			}
		}

		return $result;
	}

	/**
	 * Approve a held change as the current user.
	 *
	 * @param int                       $id      Change id.
	 * @param array<string, mixed>|null $edited  For a string: ['translation' => string, 'extra_forms' => string[]] written instead of the proposal. Refused for a post.
	 * @param bool                      $kses_ok For a post: the approver acknowledged that their save filters change the proposal.
	 * @return array{ok: bool, code: string, message: string}
	 */
	public static function approve( int $id, ?array $edited = null, bool $kses_ok = false ): array {
		$outcome = self::decide( $id, 'approve', $edited, $kses_ok );
		self::end_batch();

		return $outcome;
	}

	/**
	 * Reject a held change as the current user. Nothing is written.
	 *
	 * @param int $id Change id.
	 * @return array{ok: bool, code: string, message: string}
	 */
	public static function reject( int $id ): array {
		$outcome = self::decide( $id, 'reject', null );
		self::end_batch();

		return $outcome;
	}

	/**
	 * Undo an applied change as the current user.
	 *
	 * @param int $id Change id.
	 * @return array{ok: bool, code: string, message: string}
	 */
	public static function undo( int $id ): array {
		$outcome = self::decide( $id, 'undo', null );
		self::end_batch();

		return $outcome;
	}

	/**
	 * Take one decision without flushing (the batch runner flushes once).
	 *
	 * @param int                       $id      Change id.
	 * @param string                    $action  approve, reject or undo.
	 * @param array<string, mixed>|null $edited  See approve().
	 * @param bool                      $kses_ok See approve().
	 * @return array{ok: bool, code: string, message: string}
	 */
	public static function decide( int $id, string $action, ?array $edited = null, bool $kses_ok = false ): array {
		if ( ! self::available() ) {
			return self::outcome( 'unavailable' );
		}

		$repo = new Repo();
		$row  = $repo->find( $id );

		if ( null === $row ) {
			return self::outcome( 'not_found' );
		}

		$blocked = 'undo' === $action ? self::undo_blocked( $row ) : '';

		if ( '' !== $blocked ) {
			return self::outcome( $blocked );
		}

		if ( ! self::may_decide( $row, $action ) ) {
			return self::outcome( 'forbidden' );
		}

		$result = null;
		$ttl    = 'post' === $row['object_type'] ? self::target_lock_ttl() : self::LOCK_TTL;
		$ran    = Lock::with(
			self::lock_name( $row['object_type'], $row['object_id'], $row['language_id'] ),
			$ttl,
			static function () use ( $repo, $id, $action, $edited, $kses_ok, $ttl, &$result ): bool {
				if ( self::import_running() ) {
					$result = self::outcome( 'import_running' );

					return true;
				}

				$fresh = $repo->find( $id );

				$result = null === $fresh ? self::outcome( 'not_found' ) : match ( $action ) {
					'approve' => 'post' === $fresh['object_type'] ? self::approve_post_locked( $repo, $fresh, $edited, $kses_ok, $ttl ) : self::approve_locked( $repo, $fresh, $edited ),
					'reject'  => self::reject_locked( $repo, $fresh, $ttl ),
					'undo'    => self::undo_locked( $repo, $fresh, $ttl ),
					default   => self::outcome( 'unsupported' ),
				};

				return true;
			}
		);

		if ( true !== $ran || null === $result ) {
			return self::outcome( 'busy' );
		}

		if ( $result['ok'] ) {
			$event = [
				'approve' => 'applied',
				'reject'  => 'rejected',
				'undo'    => 'reverted',
			][ $action ] ?? '';

			if ( '' !== $event && has_action( 'perflocale/automation/' . $event ) ) {
				self::fire( $event, $repo->find( $id ) );
			}
		}

		return $result;
	}

	/**
	 * Whether the current user may take this decision on this change.
	 *
	 * Approve and reject need the right to edit the target (a string: perflocale_manage_translations and an
	 * unrestricted domain); Undo needs the right its write needs (Trash needs the delete right).
	 *
	 * @param array<string, mixed> $row    Change row.
	 * @param string               $action approve, reject or undo.
	 * @return bool
	 */
	public static function may_decide( array $row, string $action ): bool {
		$type   = Scalar::str( $row['object_type'] ?? '' );
		$object = Scalar::int( $row['object_id'] ?? 0 );

		if ( 'string' === $type ) {
			return current_user_can( 'perflocale_manage_translations' ) && ! StringTarget::is_restricted( $object );
		}

		if ( 'post' !== $type || $object <= 0 ) {
			return false;
		}

		if ( 'undo' === $action && PostTarget::undo_trashes( $row ) ) {
			return Helper::user_can_delete_object( $object );
		}

		return Helper::user_can_edit_object( $object );
	}

	/**
	 * Why a post change cannot be undone whatever the user ('' when nothing blocks it): a stored `previous`
	 * that is no valid snapshot answers `no_previous` first, so it never leads to an empty write or the Trash.
	 *
	 * @param array<string, mixed> $row Change row.
	 * @return string '' or `no_previous`.
	 */
	public static function undo_blocked( array $row ): string {
		if ( 'post' !== Scalar::str( $row['object_type'] ?? '' ) || PostTarget::undo_trashes( $row ) ) {
			return '';
		}

		$raw = Scalar::str( $row['previous'] ?? '' );

		// A list row carries no value; only the full row can be judged.
		if ( '' === $raw ) {
			return '';
		}

		return PostTarget::previous_valid( Repo::decode( $raw ) ) ? '' : 'no_previous';
	}

	/**
	 * Whether the current user may see this change in the lists.
	 *
	 * @param array<string, mixed> $row Change row.
	 * @return bool
	 */
	public static function may_see( array $row ): bool {
		$source = Scalar::int( $row['source_id'] ?? 0 );

		if ( 'post' === ( $row['object_type'] ?? '' ) && Scalar::int( $row['object_id'] ?? 0 ) <= 0 && $source > 0 ) {
			return Helper::user_can_edit_object( $source );
		}

		return self::may_decide( $row, 'approve' );
	}

	/**
	 * Settle the applying intent left on a target's pending string row, if any. Under the target lock.
	 *
	 * @param Repo $repo        Repository.
	 * @param int  $string_id   String id.
	 * @param int  $language_id Language id.
	 * @return bool False when a read failed or the intent could not be settled.
	 */
	private static function settle_target_intent( Repo $repo, int $string_id, int $language_id ): bool {
		$open = $repo->pending_intent_for_target( 'string', $string_id, $language_id );

		if ( false === $open ) {
			return false;
		}

		if ( null === $open ) {
			return true;
		}

		$state = StringTarget::state( $string_id, $language_id );

		if ( false === $state ) {
			return false;
		}

		return in_array( self::settle_applying( $repo, $open, $state ), [ self::SETTLED_CLEAR, self::SETTLED_MOVED, 'applied' ], true );
	}

	/**
	 * Approve under the target lock.
	 *
	 * A string approval writes ahead: before the target write the row stores the replaced value and the
	 * write (INTENT_APPLYING); the move to applied is the done marker. A left intent is settled first.
	 *
	 * @param Repo                      $repo   Repository.
	 * @param array<string, int|string> $row    Fresh row.
	 * @phpstan-param ChangeRow $row
	 * @param array<string, mixed>|null $edited Edited values.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function approve_locked( Repo $repo, array $row, ?array $edited ): array {
		if ( Repo::STATUS_PENDING !== $row['status'] ) {
			return self::outcome( 'not_pending' );
		}

		if ( 'string' !== $row['object_type'] ) {
			return self::outcome( 'unsupported' );
		}

		$id        = $row['id'];
		$string_id = $row['object_id'];
		$lang      = $row['language_id'];

		$language = \PerfLocale\Plugin::get_instance()->lang_repo()->find( $lang );
		// The cached lookup answers a failed read as null too: the table decides.
		$locale = null === $language ? StringTarget::language_locale( $lang ) : Scalar::str( Scalar::prop( $language, 'locale' ) );

		if ( false === $locale ) {
			return self::outcome( 'busy' );
		}

		if ( null === $locale ) {
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		$state = StringTarget::state( $string_id, $lang );

		if ( false === $state ) {
			return self::outcome( 'busy' );
		}

		// A string id that names another source text (a Replace import reuses ids) is a gone target; checked before a
		// left intent is settled, so nothing of it is written into the other string.
		$made_for = Scalar::str( Repo::decode( $row['summary'] )['source_hash'] ?? '' );

		if ( is_array( $state ) && '' !== $made_for && ! \PerfLocale\Strings\StrIdentMigration::same_source( $made_for, $state ) ) {
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		if ( Repo::INTENT_APPLYING === $row['result_status'] ) {
			$settled = self::settle_applying( $repo, $row, $state );

			if ( self::SETTLED_CLEAR !== $settled ) {
				return self::outcome( self::SETTLED_MOVED === $settled ? 'edited_since' : $settled );
			}
		}

		if ( null === $state || ( '' !== $made_for && ! \PerfLocale\Strings\StrIdentMigration::same_source( $made_for, $state ) ) ) {
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		// The translation changed since the change was made.
		if ( StringTarget::hash( $state['translation'], $state['extra_forms'] ) !== $row['base_hash'] ) {
			$repo->set_reason( $id, Repo::STATUS_PENDING, 'human_edit' );

			return self::outcome( 'edited_since' );
		}

		// A machine proposal passes the MT allowlist whoever approves it; the original's own held elements are kept under
		// the approver's rules.
		$mt = static function ( string $value ) use ( $state ): string {
			$clean = \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_value_kept( $value, $state['original'], $kept );

			return $kept ? SaveProbe::as_user( $clean ) : $clean;
		};

		$payload  = Repo::decode( $row['payload'] );
		$source   = SourceType::MachineTranslation;
		$reason   = 'approved';
		$proposal = [
			'translation' => $mt( Scalar::str( $payload['translation'] ?? '' ) ),
			'extra_forms' => is_array( $payload['extra_forms'] ?? null ) ? array_map( $mt, Scalar::strings( $payload['extra_forms'] ) ) : null,
		];
		$values   = $proposal;

		// A submission equal to the proposal (browser line breaks) approves it as it is, before sanitising
		// could make the two differ.
		if ( null !== $edited && self::matches_proposal( $edited, $proposal ) ) {
			$edited = null;
		}

		if ( null !== $edited ) {
			$clean = self::clean_edit( $edited, $state['original'] );

			if ( null === $clean ) {
				return self::outcome( 'invalid_edit' );
			}

			// An edit that leaves the proposal as it is approves the proposal.
			$same = $clean['translation'] === $proposal['translation']
				&& \PerfLocale\Database\Repository\StringTranslationRepository::normalize_extra_forms( $clean['extra_forms'] ) === \PerfLocale\Database\Repository\StringTranslationRepository::normalize_extra_forms( $proposal['extra_forms'] ?? [] );

			if ( ! $same ) {
				$values = $clean;
				$source = SourceType::Manual;
				$reason = 'edited';
			}
		}

		if ( '' === $values['translation'] ) {
			return self::outcome( 'invalid_edit' );
		}

		// The value an Undo restores, with its target: a Replace import keeps ids, so Undo compares them first.
		$previous = StringTarget::previous_of( $state ) + [
			self::TARGET => [
				'source' => $state['original_hash'],
				'locale' => $locale,
			],
		];
		$by       = get_current_user_id();
		$intent   = [
			'translation' => $values['translation'],
			// Forms left out (null) keep the stored ones.
			'hash'        => StringTarget::hash( $values['translation'], $values['extra_forms'] ?? $state['extra_forms'] ),
			'reason'      => $reason,
		];

		// The approver is in decided_by from here on, so the eraser reaches it while the intent is open.
		if ( ! $repo->mark_intent( $id, Repo::STATUS_PENDING, Repo::INTENT_APPLYING, $previous + [ 'intent' => $intent ], $by ) ) {
			return self::outcome( 'write_failed' );
		}

		// Only while the cell is as compared above: a save that landed since is kept.
		$read  = $state['stored'];
		$error = Scalar::str(
			self::writing(
				static fn (): string => StringTarget::write( $string_id, $lang, $values['translation'], $values['extra_forms'], $source, $read, $previous[ self::TARGET ] )
			)
		);

		if ( StringTarget::TARGET_GONE === $error ) {
			$repo->clear_intent( $id, Repo::STATUS_PENDING, Repo::INTENT_APPLYING, true );
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		if ( StringTarget::CELL_MOVED === $error ) {
			$repo->clear_intent( $id, Repo::STATUS_PENDING, Repo::INTENT_APPLYING, true );
			$repo->set_reason( $id, Repo::STATUS_PENDING, 'human_edit' );

			return self::outcome( 'edited_since' );
		}

		if ( '' !== $error ) {
			// A later step failed: put the target back only over the cell as read or this Apply's own value. The intent stays while that fails too.
			$after = null;
			$now   = StringTarget::state( $string_id, $lang );
			$mine  = is_array( $now ) && ( $now['stored'] === $read || StringTarget::hash( $now['translation'], $now['extra_forms'] ) === $intent['hash'] );
			$code  = $mine ? self::roll_back_applying( $repo, $id, $string_id, $lang, $previous, $now, $after ) : '';

			if ( self::SETTLED_MOVED === $code || ( is_array( $now ) && ! $mine && self::settle_moved( $repo, $id ) ) ) {
				return self::outcome( 'edited_since' );
			}

			$repo->set_reason( $id, Repo::STATUS_PENDING, 'write_failed' );

			return self::outcome( 'write_failed', $error );
		}

		$after = StringTarget::state( $string_id, $lang );

		if ( ! is_array( $after ) ) {
			// Unconfirmed write: the intent stays for the next decision or the housekeeping to settle.
			return self::outcome( 'busy' );
		}

		// The result is the value this Apply wrote, not a later save (Undo then answers edited_since).
		$moved = $repo->transition(
			$id,
			Repo::STATUS_PENDING,
			Repo::STATUS_APPLIED,
			[
				'payload'       => '',
				'previous'      => $previous,
				'result_hash'   => $intent['hash'],
				'result_status' => '',
				'decision'      => Policy::PUBLISH,
				'reason'        => $reason,
				'decided_by'    => $by,
			]
		);

		if ( ! $moved ) {
			$now = $repo->find( $id );

			// A worker that took the lock after this one's expired settled this very write: it stays.
			if ( is_array( $now ) && Repo::STATUS_APPLIED === $now['status'] && $now['result_hash'] === $intent['hash'] ) {
				self::mark_badge_dirty();

				return self::outcome( 'applied' );
			}

			// Decided elsewhere meanwhile: put the value back, only over the value this Apply left and into its string.
			// A later save (the cell takes no target lock) stands.
			if ( StringTarget::hash( $after['translation'], $after['extra_forms'] ) !== $intent['hash'] ) {
				return self::outcome( 'conflict' );
			}

			$refused = '';
			self::writing( static fn (): string => StringTarget::restore_into( $string_id, $lang, $previous, $previous[ self::TARGET ], $refused, $after['stored'] ) );

			return self::outcome( 'conflict' );
		}

		self::mark_badge_dirty();

		return self::outcome( 'applied' );
	}

	/**
	 * Settle a pending string row holding the applying intent against the live state (under the target lock):
	 * intended result live = applied; the replaced cell unchanged = written back; else another writer's text stands
	 * (`human_edit`).
	 *
	 * @param Repo                      $repo  Repository.
	 * @param array<string, int|string> $row   The row.
	 * @phpstan-param ChangeRow $row
	 * @param array<string, mixed>|null $state The live state (null: the string is gone); after a write-back, the state read back.
	 * @phpstan-param StringTargetState|null $state
	 * @return string SETTLED_CLEAR (go on), SETTLED_MOVED, `applied`, `conflict`, `busy` or `write_failed`.
	 */
	private static function settle_applying( Repo $repo, array $row, ?array &$state ): string {
		$stored = Repo::decode( $row['previous'] );
		$intent = self::applying_intent( $stored );

		if ( null === $intent ) {
			// A marker without the record it needs is no intent.
			return $repo->clear_intent( $row['id'], Repo::STATUS_PENDING, Repo::INTENT_APPLYING, true ) ? self::SETTLED_CLEAR : 'busy';
		}

		if ( null === $state ) {
			return self::SETTLED_CLEAR;
		}

		$previous = StringTarget::previous_from( $stored ) + self::target_record( $stored );
		$live     = StringTarget::hash( $state['translation'], $state['extra_forms'] );

		if ( $live === $intent['hash'] ) {
			$moved = $repo->transition(
				$row['id'],
				Repo::STATUS_PENDING,
				Repo::STATUS_APPLIED,
				[
					'payload'       => '',
					'previous'      => $previous,
					'result_hash'   => $live,
					'result_status' => '',
					'decision'      => Policy::PUBLISH,
					'reason'        => $intent['reason'],
				]
			);

			if ( ! $moved ) {
				return 'conflict';
			}

			// The writer died before its flush: flush the map and file here.
			StringTarget::touch( $row['language_id'] );
			self::mark_badge_dirty();

			return 'applied';
		}

		// Only the replaced cell as it was (value, forms and label) is rolled back: a unit writes value and forms in
		// one statement, so any other cell, a partial pair or a same-text save under another label, is a person's.
		$untouched = StringTarget::hash( $previous['translation'], $previous['extra_forms'] ) === $live
			&& ( '' === $previous['link']['source'] || $previous['link']['source'] === $state['link']['source'] );

		if ( ! $untouched ) {
			return self::settle_moved( $repo, $row['id'] ) ? self::SETTLED_MOVED : 'busy';
		}

		$code = self::roll_back_applying( $repo, $row['id'], $row['object_id'], $row['language_id'], $previous, $state, $state );

		return '' === $code ? self::SETTLED_CLEAR : $code;
	}

	/**
	 * Write an applying intent's replaced value back, read it back and drop the intent.
	 *
	 * @param Repo                      $repo      Repository.
	 * @param int                       $id        Row id.
	 * @param int                       $string_id String id.
	 * @param int                       $lang      Language id.
	 * @param array<string, mixed>      $previous  The replaced value (previous_of()).
	 * @param array<string, mixed>      $read      The state the decision compared: written back only while the cell is so.
	 * @phpstan-param StringTargetState $read
	 * @param array<string, mixed>|null $after     Set to the state read back.
	 * @phpstan-param StringTargetState|null $after
	 * @return string '' when rolled back, SETTLED_MOVED (a later save stands, intent dropped), else `write_failed` or `busy` (the intent stays).
	 */
	private static function roll_back_applying( Repo $repo, int $id, int $string_id, int $lang, array $previous, array $read, ?array &$after ): string {
		$stored = $read['stored'];
		$error  = Scalar::str( self::writing( static fn (): string => StringTarget::restore( $string_id, $lang, $previous, $stored ) ) );

		if ( StringTarget::CELL_MOVED === $error ) {
			return self::settle_moved( $repo, $id ) ? self::SETTLED_MOVED : 'busy';
		}

		if ( '' !== $error ) {
			return 'write_failed';
		}

		$read = StringTarget::state( $string_id, $lang );

		if ( ! is_array( $read ) || ! StringTarget::holds( $read, $previous ) ) {
			return 'busy';
		}

		if ( ! $repo->clear_intent( $id, Repo::STATUS_PENDING, Repo::INTENT_APPLYING, true ) ) {
			return 'busy';
		}

		$after = $read;

		return '';
	}

	/**
	 * Drop a pending row's applying intent because another writer's value stands.
	 *
	 * @param Repo $repo Repository.
	 * @param int  $id   Row id.
	 * @return bool False when the intent could not be cleared.
	 */
	private static function settle_moved( Repo $repo, int $id ): bool {
		if ( ! $repo->clear_intent( $id, Repo::STATUS_PENDING, Repo::INTENT_APPLYING, true ) ) {
			return false;
		}

		$repo->set_reason( $id, Repo::STATUS_PENDING, 'human_edit' );

		return true;
	}

	/**
	 * The write an applying intent records, or null when the stored value holds none in its shape.
	 *
	 * @param array<mixed> $stored The decoded `previous` of the row.
	 * @return array{translation: string, hash: string, reason: string}|null
	 */
	private static function applying_intent( array $stored ): ?array {
		$intent = $stored['intent'] ?? null;

		if ( ! is_array( $intent ) || ! is_string( $stored['translation'] ?? null ) || ! is_string( $intent['translation'] ?? null ) ) {
			return null;
		}

		$hash = $intent['hash'] ?? null;

		if ( ! is_string( $hash ) || ! StateHash::is_valid( $hash ) ) {
			return null;
		}

		return [
			'translation' => $intent['translation'],
			'hash'        => $hash,
			'reason'      => 'edited' === ( $intent['reason'] ?? '' ) ? 'edited' : 'approved',
		];
	}

	/**
	 * Settle string rows left with a write-ahead intent, each under its target lock (a held lock waits
	 * for the next run). For the daily housekeeping.
	 *
	 * @param int $batch Maximum rows.
	 * @return int Rows settled.
	 */
	public static function settle_string_intents( int $batch ): int {
		if ( ! self::available() ) {
			return 0;
		}

		$repo    = new Repo();
		$rows    = $repo->string_intents( $batch );
		$settled = 0;

		foreach ( $rows ?? [] as $row ) {
			Lock::with(
				self::lock_name( 'string', $row['object_id'], $row['language_id'] ),
				self::LOCK_TTL,
				static function () use ( $repo, $row, &$settled ): bool {
					$fresh = $repo->find( $row['id'] );

					if ( null === $fresh || 'string' !== $fresh['object_type'] ) {
						return true;
					}

					$state = StringTarget::state( $fresh['object_id'], $fresh['language_id'] );

					if ( false === $state ) {
						return true;
					}

					$code     = '';
					$made_for = Scalar::str( Repo::decode( $fresh['summary'] )['source_hash'] ?? '' );

					if ( Repo::STATUS_PENDING === $fresh['status'] && Repo::INTENT_APPLYING === $fresh['result_status'] && is_array( $state ) && '' !== $made_for && ! \PerfLocale\Strings\StrIdentMigration::same_source( $made_for, $state ) ) {
						// The id names another source text now: nothing of the intent is written into it.
						$repo->clear_intent( $fresh['id'], Repo::STATUS_PENDING, Repo::INTENT_APPLYING, true );
						$repo->close_pending( [ $fresh['id'] ], 'target_gone' );
						$code = self::SETTLED_MOVED;
					} elseif ( Repo::STATUS_PENDING === $fresh['status'] && Repo::INTENT_APPLYING === $fresh['result_status'] ) {
						$code = self::settle_applying( $repo, $fresh, $state );
					} elseif ( Repo::STATUS_APPLIED === $fresh['status'] && Repo::INTENT_REVERTING === $fresh['result_status'] ) {
						$code = self::settle_reverting( $repo, $fresh, $state );
					}

					if ( in_array( $code, [ self::SETTLED_CLEAR, self::SETTLED_MOVED, 'applied', 'reverted' ], true ) ) {
						++$settled;
					}

					return true;
				}
			);
		}

		return $settled;
	}

	/**
	 * Reject under the target lock.
	 *
	 * @param Repo                      $repo Repository.
	 * @param array<string, int|string> $row  Fresh row.
	 * @phpstan-param ChangeRow $row
	 * @param int                       $ttl  The TTL the target lock was taken with.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function reject_locked( Repo $repo, array $row, int $ttl ): array {
		if ( Repo::STATUS_PENDING !== $row['status'] ) {
			return self::outcome( 'not_pending' );
		}

		if ( 'post' === $row['object_type'] ) {
			// An Apply of this row that died after its write is reconciled first: a write that landed is
			// recorded as applied and offered Undo, never left written under a rejected row.
			if ( in_array( SyncRecorder::reconcile_locked( $row['object_id'], $ttl ), [ 'failed', 'busy' ], true ) ) {
				return self::outcome( 'busy' );
			}

			if ( Repo::STATUS_PENDING !== Scalar::str( $repo->find( $row['id'] )['status'] ?? '' ) ) {
				return self::outcome( 'not_pending' );
			}
		} elseif ( Repo::INTENT_APPLYING === $row['result_status'] ) {
			// The same for a string approval that stopped (settle_applying()).
			$state   = StringTarget::state( $row['object_id'], $row['language_id'] );
			$settled = false === $state ? 'busy' : self::settle_applying( $repo, $row, $state );

			if ( 'applied' === $settled ) {
				return self::outcome( 'not_pending' );
			}

			if ( self::SETTLED_CLEAR !== $settled && self::SETTLED_MOVED !== $settled ) {
				return self::outcome( 'busy' );
			}
		}

		$moved = $repo->transition(
			$row['id'],
			Repo::STATUS_PENDING,
			Repo::STATUS_REJECTED,
			[
				'payload'    => '',
				'decided_by' => get_current_user_id(),
			]
		);

		if ( ! $moved ) {
			return self::outcome( 'conflict' );
		}

		self::mark_badge_dirty();

		return self::outcome( 'rejected' );
	}

	/**
	 * Undo under the target lock.
	 *
	 * @param Repo                      $repo Repository.
	 * @param array<string, int|string> $row  Fresh row.
	 * @phpstan-param ChangeRow $row
	 * @param int                       $ttl  The TTL the target lock was taken with.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function undo_locked( Repo $repo, array $row, int $ttl ): array {
		if ( Repo::STATUS_APPLIED !== $row['status'] ) {
			return self::outcome( 'not_applied' );
		}

		if ( 'string' === $row['object_type'] ) {
			return self::undo_string_locked( $repo, $row );
		}

		if ( 'post' !== $row['object_type'] ) {
			return self::outcome( 'unsupported' );
		}

		$id     = $row['id'];
		$object = $row['object_id'];

		$blocked = self::undo_blocked( $row );

		if ( '' !== $blocked ) {
			return self::outcome( $blocked );
		}

		if ( in_array( $row['area'], self::RESYNC_AREAS, true ) ) {
			return self::undo_resync_locked( $repo, $row, $ttl );
		}

		// The digests and slug are read before the undo's own read: its write lands only on that row.
		$read  = PostTarget::row_read( $object );
		$check = PostTarget::unchanged_since( $row );

		// An Undo that restored the target and stopped before its record move is recorded now.
		if ( '' !== $check && PostTarget::holds_undo( $row ) ) {
			$moved = $repo->transition( $id, Repo::STATUS_APPLIED, Repo::STATUS_REVERTED, [ 'decided_by' => get_current_user_id() ] );

			return self::outcome( $moved ? 'reverted' : 'conflict' );
		}

		if ( '' !== $check ) {
			return self::outcome( $check );
		}

		if ( null === $read ) {
			return self::outcome( 'write_failed', PostTarget::ROW_UNREADABLE );
		}

		$error = Scalar::str( self::writing( static fn (): string => PostTarget::undo( $row, $read ), $object ) );

		if ( PostTarget::ROW_MOVED === $error ) {
			// A save landed after the undo's read: nothing was written.
			return self::outcome( 'edited_since' );
		}

		if ( '' !== $error ) {
			return self::outcome( 'write_failed', $error );
		}

		$moved = $repo->transition(
			$id,
			Repo::STATUS_APPLIED,
			Repo::STATUS_REVERTED,
			[ 'decided_by' => get_current_user_id() ]
		);

		return self::outcome( $moved ? 'reverted' : 'conflict' );
	}

	/**
	 * Undo a string change under the target lock, written ahead like an approval (INTENT_REVERTING, then
	 * reverted once StringTarget::holds() reads the restore back). Never writes into another string or language.
	 *
	 * @param Repo                      $repo Repository.
	 * @param array<string, int|string> $row  Fresh row (applied).
	 * @phpstan-param ChangeRow $row
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function undo_string_locked( Repo $repo, array $row ): array {
		$id       = $row['id'];
		$object   = $row['object_id'];
		$lang     = $row['language_id'];
		$previous = Repo::decode( $row['previous'] );
		$state    = StringTarget::state( $object, $lang );

		if ( false === $state ) {
			return self::outcome( 'busy' );
		}

		if ( null === $state ) {
			return self::outcome( 'target_gone' );
		}

		if ( Repo::INTENT_REVERTING === $row['result_status'] ) {
			$settled = self::settle_reverting( $repo, $row, $state );

			if ( self::SETTLED_CLEAR !== $settled ) {
				return self::outcome( self::SETTLED_MOVED === $settled ? 'edited_since' : $settled );
			}
		}

		if ( StringTarget::hash( $state['translation'], $state['extra_forms'] ) !== $row['result_hash'] ) {
			return self::outcome( 'edited_since' );
		}

		if ( [] === $previous ) {
			return self::outcome( 'no_previous' );
		}

		$moved = self::target_moved( $row, $previous, $state );

		if ( '' !== $moved ) {
			return self::outcome( $moved );
		}

		// The mark carries the one partial state the restore can leave (value written, old forms still stored),
		// so a later edit of the forms is never taken for it.
		$current = StringTarget::previous_of( $state );

		if ( ! $repo->mark_intent( $id, Repo::STATUS_APPLIED, Repo::INTENT_REVERTING, self::with_stopped_undo( $previous, $current ) ) ) {
			return self::outcome( 'write_failed' );
		}

		// The restore lands only while the ids still name the change's target (a Replace import can run
		// in between); otherwise nothing is written and the mark is dropped.
		$target  = self::undo_target( $row, $previous );
		$refused = '';
		$read    = $state['stored'];
		$error   = Scalar::str(
			self::writing(
				static function () use ( $object, $lang, $previous, $target, $read, &$refused ): string {
					return StringTarget::restore_into( $object, $lang, $previous, $target, $refused, $read );
				}
			)
		);

		if ( '' !== $refused || StringTarget::CELL_MOVED === $error ) {
			$repo->clear_intent( $id, Repo::STATUS_APPLIED, Repo::INTENT_REVERTING, false );

			return self::outcome( '' !== $refused ? $refused : 'edited_since' );
		}

		if ( '' !== $error ) {
			// A later step failed: put back what the change left. The mark stays while that fails too.
			// Only over the cell as the Undo read it: a save since keeps it, and the mark stays for the settle.
			$back = Scalar::str(
				self::writing(
					static function () use ( $object, $lang, $current, $target, $read ): string {
						$ignored = '';

						return StringTarget::restore_into( $object, $lang, $current, $target, $ignored, $read );
					}
				)
			);
			$read = '' === $back ? StringTarget::state( $object, $lang ) : false;

			if ( is_array( $read ) && StringTarget::hash( $read['translation'], $read['extra_forms'] ) === $row['result_hash'] ) {
				$repo->clear_intent( $id, Repo::STATUS_APPLIED, Repo::INTENT_REVERTING, false );
			}

			return self::outcome( 'write_failed', $error );
		}

		return self::outcome( self::finish_revert( $repo, $row, $previous ) );
	}

	/**
	 * Read a restored string back and record the Undo when it holds the restored value, forms and link.
	 *
	 * @param Repo                      $repo     Repository.
	 * @param array<string, int|string> $row      The applied row.
	 * @phpstan-param ChangeRow $row
	 * @param array<mixed>              $previous The value restored.
	 * @return string `reverted`, `conflict`, `busy` (the read failed) or `write_failed` (the read differs); the mark stays unless reverted.
	 */
	private static function finish_revert( Repo $repo, array $row, array $previous ): string {
		$read = StringTarget::state( $row['object_id'], $row['language_id'] );

		if ( ! is_array( $read ) ) {
			return 'busy';
		}

		if ( ! StringTarget::holds( $read, $previous ) ) {
			return 'write_failed';
		}

		$moved = $repo->transition(
			$row['id'],
			Repo::STATUS_APPLIED,
			Repo::STATUS_REVERTED,
			[
				'decided_by'    => get_current_user_id(),
				'result_status' => '',
			]
		);

		return $moved ? 'reverted' : 'conflict';
	}

	/**
	 * Settle an applied string row holding the reverting mark against the live state (under the target lock):
	 * change's result live = mark dropped; restored value, or STOPPED_UNDO state = restore again and record
	 * reverted (same target only, else `target_gone`); else another writer's text stands, still applied.
	 *
	 * @param Repo                      $repo  Repository.
	 * @param array<string, int|string> $row   The row.
	 * @phpstan-param ChangeRow $row
	 * @param array<string, mixed>|null $state The live state (null: the string is gone).
	 * @phpstan-param StringTargetState|null $state
	 * @return string SETTLED_CLEAR (go on), SETTLED_MOVED, `reverted`, `target_gone`, `conflict`, `busy` or `write_failed`.
	 */
	private static function settle_reverting( Repo $repo, array $row, ?array $state ): string {
		$previous = Repo::decode( $row['previous'] );
		$live     = null === $state ? '' : StringTarget::hash( $state['translation'], $state['extra_forms'] );
		$restored = [] === $previous ? '' : StringTarget::hash( Scalar::str( $previous['translation'] ?? '' ), Scalar::strings( $previous['extra_forms'] ?? [] ) );
		$stopped  = Scalar::str( $previous[ self::STOPPED_UNDO ] ?? '' );

		$finish = null !== $state && '' !== $restored && ( $live === $restored || ( '' !== $stopped && $live === $stopped ) ) && $live !== $row['result_hash'];
		$moved  = $finish ? self::target_moved( $row, $previous, $state ) : '';
		$saved  = false;

		if ( 'busy' === $moved ) {
			return 'busy';
		}

		if ( $finish && '' === $moved ) {
			$object  = $row['object_id'];
			$lang    = $row['language_id'];
			$target  = self::undo_target( $row, $previous );
			$refused = '';
			$read    = $state['stored'];
			$error   = Scalar::str(
				self::writing(
					static function () use ( $object, $lang, $previous, $target, $read, &$refused ): string {
						return StringTarget::restore_into( $object, $lang, $previous, $target, $refused, $read );
					}
				)
			);

			if ( 'busy' === $refused ) {
				return 'busy';
			}

			$saved = '' === $refused && StringTarget::CELL_MOVED === $error;

			if ( '' === $refused && ! $saved ) {
				return '' === $error ? self::finish_revert( $repo, $row, $previous ) : 'write_failed';
			}

			$moved = $refused;
		}

		if ( ! $repo->clear_intent( $row['id'], Repo::STATUS_APPLIED, Repo::INTENT_REVERTING, false ) ) {
			return 'busy';
		}

		if ( '' !== $moved ) {
			return $moved;
		}

		// $saved: a save after the settle's read stands.
		return ! $saved && ( null === $state || $live === $row['result_hash'] ) ? self::SETTLED_CLEAR : self::SETTLED_MOVED;
	}

	/**
	 * The `previous` an Undo's reverting mark stores: the restored value plus, under STOPPED_UNDO, the hash of
	 * the state a restore stopped between value and forms leaves ('' when the restore removes the value).
	 *
	 * @param array<mixed> $previous The value the Undo restores.
	 * @param array<mixed> $current  The target as the change left it (previous_of()).
	 * @return array<string, mixed>
	 */
	private static function with_stopped_undo( array $previous, array $current ): array {
		$out   = self::map( $previous );
		$value = Scalar::str( $out['translation'] ?? '' );

		$out[ self::STOPPED_UNDO ] = '' === $value ? '' : StringTarget::hash( $value, Scalar::strings( $current['extra_forms'] ?? [] ) );

		return $out;
	}

	/**
	 * The TARGET record (source hash, locale) of a stored `previous`, or [] when it holds none in its shape.
	 *
	 * @param array<mixed> $stored The decoded `previous`.
	 * @return array<string, array{source: string, locale: string}>
	 */
	private static function target_record( array $stored ): array {
		$target = $stored[ self::TARGET ] ?? null;

		if ( ! is_array( $target ) || ! is_string( $target['source'] ?? null ) || ! is_string( $target['locale'] ?? null ) ) {
			return [];
		}

		return [
			self::TARGET => [
				'source' => $target['source'],
				'locale' => $target['locale'],
			],
		];
	}

	/**
	 * Why an applied string change may not be restored now: a Replace import keeps ids, so they can name
	 * another source text or language. A row that records neither is not compared.
	 *
	 * @param array<string, int|string> $row      The applied row.
	 * @phpstan-param ChangeRow $row
	 * @param array<mixed>              $previous Its decoded `previous`.
	 * @param array<string, mixed>      $state    The live state.
	 * @phpstan-param StringTargetState $state
	 * @return string '' (the same target), `target_gone`, or `busy` when the language read failed.
	 */
	private static function target_moved( array $row, array $previous, array $state ): string {
		$record = self::target_record( $previous )[ self::TARGET ] ?? null;
		$source = null === $record ? Scalar::str( Repo::decode( $row['summary'] )['source_hash'] ?? '' ) : $record['source'];

		if ( '' !== $source && ! \PerfLocale\Strings\StrIdentMigration::same_source( $source, $state ) ) {
			return 'target_gone';
		}

		if ( null === $record || '' === $record['locale'] ) {
			return '';
		}

		$locale = StringTarget::language_locale( $row['language_id'] );

		if ( false === $locale ) {
			return 'busy';
		}

		return $locale === $record['locale'] ? '' : 'target_gone';
	}

	/**
	 * The target an Undo writes into, as target_moved() compares it ('' locale: not compared).
	 *
	 * @param array<string, int|string> $row      The applied row.
	 * @phpstan-param ChangeRow $row
	 * @param array<mixed>              $previous Its decoded `previous`.
	 * @return array{source: string, locale: string}
	 */
	private static function undo_target( array $row, array $previous ): array {
		$record = self::target_record( $previous )[ self::TARGET ] ?? null;

		return [
			'source' => null === $record ? Scalar::str( Repo::decode( $row['summary'] )['source_hash'] ?? '' ) : $record['source'],
			'locale' => null === $record ? '' : $record['locale'],
		];
	}

	/**
	 * Apply a resync change the producer already decided to a post now, under the target lock, with the
	 * acting user's rights and HTML rules, and a write-ahead intent. Never asks the policy.
	 *
	 * @param array<string, mixed> $change  The change row (area, object_type `post`, object_id, source_id, language_id, origin, trigger_name, job_id, user_id, actor, decision, reason, base_hash, summary with `fields`, `complete`, `base_quality`, `fields_unknown`, `edited_fields`).
	 * @param array<string, mixed> $payload The proposal (post_title?, post_content, post_excerpt?, meta, base_id, source_hash, base_fields_hash, block_map?).
	 * @param array<string, mixed> $options `ignore_own_lock`: the one post id whose fresh lock by the acting user does not count;
	 *                                      `import_generation`: the producer's import_fence(), checked again after the intent.
	 * @return array{status: string, id: int, reason: string} status: applied, stale, edited, post_locked, missed_schedule, kses, target_gone, forbidden, source_hidden, failed, busy, invalid or unavailable; id: the applied row.
	 */
	public static function apply( array $change, array $payload, array $options = [] ): array {
		$answer = static fn ( string $status, int $id = 0, string $reason = '' ): array => [
			'status' => $status,
			'id'     => $id,
			'reason' => $reason,
		];

		if ( ! self::available() ) {
			return $answer( 'unavailable' );
		}

		$translation_id = Scalar::int( $change['object_id'] ?? 0 );
		$source_id      = Scalar::int( $change['source_id'] ?? 0 );
		$language_id    = Scalar::int( $change['language_id'] ?? 0 );

		if ( 'post' !== Scalar::str( $change['object_type'] ?? '' ) || $translation_id <= 0 || $source_id <= 0 || $language_id <= 0
			|| ! in_array( Scalar::str( $change['area'] ?? '' ), self::RESYNC_AREAS, true )
		) {
			return $answer( 'invalid', 0, 'invalid_change' );
		}

		$summary = self::map( is_array( $change['summary'] ?? null ) ? $change['summary'] : Repo::decode( Scalar::str( $change['summary'] ?? '' ) ) );
		$keys    = Scalar::strings( $summary['fields'] ?? [] );
		$source  = get_post( $source_id );

		if ( ! $source instanceof \WP_Post ) {
			// The allowed keys come from the pair; without a source the change can never be written.
			return $answer( 'target_gone' );
		}

		// Only keys the pair allows: one a person cleared on the translation, or outside the registry, is refused.
		$clean = PostPayload::validate( $payload, PostPayload::approvable_keys( $source, $translation_id, $keys ) );

		if ( null === $clean ) {
			return $answer( 'invalid', 0, 'invalid_payload' );
		}

		$change['summary'] = $summary;
		$result            = null;
		$ttl               = self::target_lock_ttl();
		$ran               = Lock::with(
			self::lock_name( 'post', $translation_id, $language_id ),
			$ttl,
			static function () use ( $change, $clean, $keys, $options, $ttl, $source, $answer, &$result ): bool {
				$fence = self::fence_option( $options );

				// No write while an import runs, nor one prepared before an import that has run since.
				if ( false !== $fence ? self::import_moved( $fence ) : self::import_running() ) {
					$result = $answer( 'busy', 0, 'import' );

					return true;
				}

				$result = self::apply_locked( $change, $clean, $keys, $options, $ttl, $source );

				return true;
			}
		);

		if ( true !== $ran || ! is_array( $result ) ) {
			return $answer( 'busy' );
		}

		if ( 'applied' === $result['status'] ) {
			self::mark_badge_dirty();

			if ( $result['id'] > 0 && has_action( 'perflocale/automation/applied' ) ) {
				self::fire( 'applied', ( new Repo() )->find( $result['id'] ) );
			}
		}

		return $result;
	}

	/**
	 * Whether a translation is still its pair's: its link in the language names it, the language exists and the
	 * group's default-language member is the source.
	 *
	 * @param int $translation_id Translation post id.
	 * @param int $source_id      Source post id.
	 * @param int $language_id    Language id.
	 * @return string '' when it is; 'busy' when a link read failed; 'target_gone' otherwise.
	 */
	private static function pair_link( int $translation_id, int $source_id, int $language_id ): string {
		$plugin = \PerfLocale\Plugin::get_instance();
		$groups = $plugin->group_repo();
		$link   = $groups->find_translation_link_uncached( $translation_id, ObjectType::Post, $language_id );

		if ( false === $link ) {
			return 'busy';
		}

		if ( null === $link || Scalar::int( $link['object_id'] ) !== $translation_id || null === $plugin->lang_repo()->find( $language_id ) ) {
			return 'target_gone';
		}

		$default_id = Scalar::int( Scalar::prop( $plugin->lang_repo()->get_default(), 'id' ) );
		$source_now = $default_id > 0 ? $groups->find_translation_link_uncached( $translation_id, ObjectType::Post, $default_id ) : false;

		if ( false === $source_now ) {
			return 'busy';
		}

		return null === $source_now || Scalar::int( $source_now['object_id'] ) !== $source_id ? 'target_gone' : '';
	}

	/**
	 * Apply under the target lock.
	 *
	 * @param array<string, mixed> $change  The change row with its decoded summary.
	 * @param array<string, mixed> $clean   The validated payload.
	 * @phpstan-param Payload $clean
	 * @param array<int, string>   $keys    The pair's field keys.
	 * @param array<string, mixed> $options See apply().
	 * @param int                  $ttl     The TTL the target lock was taken with.
	 * @param \WP_Post             $source  The source post.
	 * @return array{status: string, id: int, reason: string}
	 */
	private static function apply_locked( array $change, array $clean, array $keys, array $options, int $ttl, \WP_Post $source ): array {
		$answer = static fn ( string $status, int $id = 0, string $reason = '' ): array => [
			'status' => $status,
			'id'     => $id,
			'reason' => $reason,
		];

		$translation_id = Scalar::int( $change['object_id'] );
		$source_id      = Scalar::int( $change['source_id'] );
		$language_id    = Scalar::int( $change['language_id'] );
		$user_id        = Scalar::int( $change['user_id'] ?? 0 );

		if ( in_array( SyncRecorder::reconcile_locked( $translation_id, $ttl ), [ 'failed', 'busy' ], true ) ) {
			// A crash record that could not be put back stands, or the lock was lost: nothing is written over it.
			return $answer( 'busy' );
		}

		// The row digest is read before the locked read: the write lands only on that row.
		$digest = PostTarget::row_digest( $translation_id );
		$read   = PostTarget::read( $translation_id, $keys );

		if ( null === $read || 'trash' === $read['status'] ) {
			return $answer( 'target_gone' );
		}

		if ( null === $digest ) {
			return $answer( 'failed', 0, PostTarget::ROW_UNREADABLE );
		}

		// A language change or relink during the provider call leaves the post row as it was: the links decide.
		$pair = self::pair_link( $translation_id, $source_id, $language_id );

		if ( '' !== $pair ) {
			return $answer( $pair );
		}

		// The write uses the acting user's rights.
		if ( ! Helper::user_can_edit_object( $translation_id ) ) {
			return $answer( 'forbidden' );
		}

		$summary = self::map( $change['summary'] ?? [] );

		// Source-language text is written only by a user who can read the source text.
		if ( ! \PerfLocale\Util\ObjectPermission::read_text( $source ) && PostPayload::holds_source_text( $clean['parts'], $summary, $clean['parts_complete'] ) ) {
			return $answer( 'source_hidden', 0, 'source_hidden' );
		}

		if ( $read['hash'] !== Scalar::str( $change['base_hash'] ?? '' ) || $read['digest'] !== $clean['base_fields_hash'] ) {
			return $answer( 'stale' );
		}

		// The area was decided from the status the producer read: a translation published since then
		// changes only through review.
		if ( ChangeBuilder::area( $read['status'] ) !== Scalar::str( $change['area'] ?? '' ) ) {
			return $answer( 'stale' );
		}

		// Per field too: a key whose translation value a person wrote is never overwritten automatically.
		if ( HumanEdit::since_last_automatic_write( $translation_id, $language_id, $read ) || [] !== Scalar::strings( $summary['edited_fields'] ?? [] ) ) {
			return $answer( 'edited', 0, 'human_edit' );
		}

		if ( PostTarget::post_locked( $translation_id, $user_id, Scalar::int( $options['ignore_own_lock'] ?? 0 ) === $translation_id ) ) {
			return $answer( 'post_locked', 0, 'post_locked' );
		}

		if ( PostTarget::missed_schedule( $read['post'] ) ) {
			return $answer( 'missed_schedule', 0, 'missed_schedule' );
		}

		// A write the acting user's HTML rules hold is never made automatically; otherwise it stores what they store.
		$stored = self::stored_as_user( $clean, $read['post'], $translation_id, $source_id );

		// Before the rules hold: such a change is never written, so it is held with that reason.
		if ( self::adds_script( self::written_texts( $clean, $stored, $read['post'] ), $read, $source, $keys ) ) {
			return $answer( 'unsafe_markup', 0, 'unsafe_markup' );
		}

		if ( self::rules_hold( $stored ) ) {
			return $answer( 'kses', 0, 'kses' );
		}

		$row = $change;
		unset( $row['payload'], $row['id'], $row['status'], $row['decided_at'] );

		$fence   = self::fence_option( $options );
		$written = self::resync_write( 'apply', $row, $clean, $summary, $keys, $read, 0, $source_id, $language_id, $ttl, $stored, $digest, false === $fence ? null : static fn (): bool => self::import_moved( $fence ) );

		if ( ! $written['ok'] ) {
			if ( 'import' === $written['reason'] ) {
				return $answer( 'busy', 0, 'import' );
			}

			if ( self::LOCK_LOST === $written['reason'] ) {
				return $answer( 'busy', 0, self::LOCK_LOST );
			}

			if ( PostTarget::ROW_MOVED === $written['reason'] ) {
				return $answer( 'stale' );
			}

			return $answer( 'invalid_payload' === $written['reason'] ? 'invalid' : 'failed', 0, $written['reason'] );
		}

		$after = $written['read'];

		if ( null === $after ) {
			return $answer( 'failed', 0, 'write_failed' );
		}

		$summary                       = self::note_stored_bytes( $summary, $written, $after );
		$summary['intent']             = $written['intent'];
		$summary['fields_written']     = $written['written'];
		$summary['result_fields_hash'] = $after['digest'];

		// Recorded only while this writer still holds the lock; after a takeover the new owner records it.
		if ( ! self::still_owner( 'apply', $translation_id, $language_id, $ttl ) ) {
			return $answer( 'busy', 0, self::LOCK_LOST );
		}

		$repo = new Repo();
		$id   = $repo->insert(
			array_merge(
				$row,
				[
					'status'          => Repo::STATUS_APPLIED,
					'payload'         => '',
					'summary'         => $summary,
					'previous'        => $written['previous'],
					'result_hash'     => $after['hash'],
					'result_status'   => $after['status'],
					'result_modified' => $after['modified'],
					'decided_at'      => Repo::now(),
					'decided_by'      => 0,
				]
			)
		);

		// While the intent stands: a Replace import refuses to start over it, so it never closes the file's own rows.
		$repo->supersede_pending_for_target( 'post', $translation_id, $language_id, 0 );

		if ( $id <= 0 ) {
			// The write happened and its intent stands: the reconciler records it from the intent.
			self::log( sprintf( 'the applied row of translation %d could not be inserted; recorded from its intent.', $translation_id ) );
			SyncRecorder::reconcile_locked( $translation_id, $ttl );
		} else {
			self::finish_resync_write( $translation_id, $source_id, $language_id, $written, $after );
		}

		return $answer( 'applied', max( 0, $id ) );
	}

	/**
	 * Approve a held post change under the target lock.
	 *
	 * @param Repo                      $repo    Repository.
	 * @param array<string, int|string> $row     Fresh row.
	 * @phpstan-param ChangeRow $row
	 * @param array<string, mixed>|null $edited  Refused for a post.
	 * @param bool                      $kses_ok The approver's acknowledgement of their save filters.
	 * @param int                       $ttl     The TTL the target lock was taken with.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function approve_post_locked( Repo $repo, array $row, ?array $edited, bool $kses_ok, int $ttl ): array {
		if ( Repo::STATUS_PENDING !== $row['status'] ) {
			return self::outcome( 'not_pending' );
		}

		$id             = $row['id'];
		$translation_id = $row['object_id'];
		$source_id      = $row['source_id'];
		$language_id    = $row['language_id'];

		if ( in_array( SyncRecorder::reconcile_locked( $translation_id, $ttl ), [ 'failed', 'busy' ], true ) ) {
			return self::outcome( 'busy' );
		}

		if ( Repo::STATUS_PENDING !== Scalar::str( $repo->find( $id )['status'] ?? '' ) ) {
			return self::outcome( 'not_pending' );
		}

		$summary = self::map( Repo::decode( $row['summary'] ) );
		$keys    = Scalar::strings( $summary['fields'] ?? [] );
		$source  = get_post( $source_id );

		if ( ! $source instanceof \WP_Post ) {
			// The allowed keys come from the pair; without a source the change can never be written.
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		// The summary's keys the pair still allows: what previous, expected and the stale check cover.
		$clean = PostPayload::validate( Repo::decode( $row['payload'] ), PostPayload::approvable_keys( $source, $translation_id, $keys ) );

		if ( null === $clean ) {
			return self::outcome( 'invalid_payload' );
		}

		if ( null !== $edited ) {
			return self::outcome( 'invalid_edit' );
		}

		// The row digest is read before the locked read: the write lands only on that row.
		$digest = PostTarget::row_digest( $translation_id );
		$read   = PostTarget::read( $translation_id, $keys );
		$link   = \PerfLocale\Plugin::get_instance()->group_repo()->find_translation_link_uncached( $translation_id, ObjectType::Post, $language_id );

		if ( false === $link ) {
			return self::outcome( 'busy' );
		}

		if ( null === $read || 'trash' === $read['status'] || null === $link || Scalar::int( $link['object_id'] ) !== $translation_id
			|| null === \PerfLocale\Plugin::get_instance()->lang_repo()->find( $language_id )
		) {
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		// The translation must still be in the source's group (a relink or a new default language makes
		// the proposal another group's).
		$default_id = Scalar::int( Scalar::prop( \PerfLocale\Plugin::get_instance()->lang_repo()->get_default(), 'id' ) );
		$source_now = $default_id > 0 ? \PerfLocale\Plugin::get_instance()->group_repo()->find_translation_link_uncached( $translation_id, ObjectType::Post, $default_id ) : false;

		if ( false === $source_now ) {
			return self::outcome( 'busy' );
		}

		if ( null === $source_now || Scalar::int( $source_now['object_id'] ) !== $source_id ) {
			$repo->close_pending( [ $id ], 'target_gone' );
			self::mark_badge_dirty();

			return self::outcome( 'target_gone' );
		}

		// The update was computed from the source as it was; a source saved since needs a fresh one. Closed, so
		// the next run proposes against the current source.
		$was_source = Scalar::str( $summary['source_hash'] ?? '' );
		$was_fields = Scalar::str( $summary['fields_hash'] ?? '' );

		if ( ( '' !== $was_source && \PerfLocale\Resync\Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt ) !== $was_source )
			|| ( '' !== $was_fields && \PerfLocale\Resync\Store::digest_for_keys( $keys, PostTarget::field_values( $source_id, $keys ) ) !== $was_fields )
		) {
			$repo->close_pending( [ $id ], 'source_changed' );
			self::mark_badge_dirty();

			return self::outcome( 'source_changed' );
		}

		if ( null === $digest ) {
			return self::outcome( 'write_failed', PostTarget::ROW_UNREADABLE );
		}

		if ( PostTarget::missed_schedule( $read['post'] ) ) {
			return self::outcome( 'missed_schedule' );
		}

		// A translation another user has open is never written: their next save would overwrite it.
		if ( PostTarget::post_locked( $translation_id, get_current_user_id(), true ) ) {
			return self::outcome( 'post_locked' );
		}

		// The translation or its fields changed since the proposal.
		if ( $read['hash'] !== $row['base_hash'] || $read['digest'] !== $clean['base_fields_hash'] ) {
			$repo->set_reason( $id, Repo::STATUS_PENDING, 'human_edit' );

			return self::outcome( 'edited_since' );
		}

		if ( ! self::may_decide( $row, 'approve' ) ) {
			return self::outcome( 'forbidden' );
		}

		// Source-language text is applied only by an approver who can read the source text: nothing
		// written, the row stays pending, no intent.
		if ( ! \PerfLocale\Util\ObjectPermission::read_text( $source ) && PostPayload::holds_source_text( $clean['parts'], $summary, $clean['parts_complete'] ) ) {
			return self::outcome( 'source_hidden' );
		}

		// The approver's HTML rules: save filters over every re-filtered field, and the custom-field values.
		$stored = self::stored_as_user( $clean, $read['post'], $translation_id, $source_id );

		// Never written, whoever approves (an acknowledgement of the save filters included): the row stays pending.
		if ( self::adds_script( self::written_texts( $clean, $stored, $read['post'] ), $read, $source, $keys ) ) {
			$repo->set_reason( $id, Repo::STATUS_PENDING, 'unsafe_markup' );

			return self::outcome( 'unsafe_markup' );
		}

		if ( self::rules_hold( $stored ) && ! $kses_ok ) {
			$repo->set_reason( $id, Repo::STATUS_PENDING, 'kses' );

			return self::outcome( 'kses' );
		}

		$written = self::resync_write( 'approve', [], $clean, $summary, $keys, $read, $id, $source_id, $language_id, $ttl, $stored, $digest );

		if ( ! $written['ok'] ) {
			if ( 'invalid_payload' === $written['reason'] ) {
				return self::outcome( 'invalid_payload' );
			}

			if ( PostTarget::ROW_MOVED === $written['reason'] ) {
				// A save landed after the read: nothing was written, the change stays pending.
				return self::outcome( 'stale' );
			}

			if ( self::LOCK_LOST === $written['reason'] ) {
				// The writer lost its lock (stop_lost() reconciled, or left it to the new owner): still pending.
				return self::outcome( 'busy' );
			}

			$repo->set_reason( $id, Repo::STATUS_PENDING, 'write_failed' );

			return self::outcome( 'write_failed', $written['reason'] );
		}

		$after = $written['read'];

		if ( null === $after ) {
			return self::outcome( 'write_failed' );
		}

		// The summary Undo compares travels in the compare-and-set: a crash right after leaves nothing to add.
		$summary                       = self::note_stored_bytes( $summary, $written, $after );
		$summary['intent']             = $written['intent'];
		$summary['fields_written']     = $written['written'];
		$summary['result_fields_hash'] = $after['digest'];

		// As for an Apply: recorded only while this writer still holds the lock.
		if ( ! self::still_owner( 'approve', $translation_id, $language_id, $ttl ) ) {
			return self::outcome( 'busy' );
		}

		$moved = $repo->transition(
			$id,
			Repo::STATUS_PENDING,
			Repo::STATUS_APPLIED,
			[
				'payload'         => '',
				'previous'        => $written['previous'],
				'result_hash'     => $after['hash'],
				'result_status'   => $after['status'],
				'result_modified' => $after['modified'],
				'reason'          => 'approved',
				'decided_by'      => get_current_user_id(),
				'summary'         => $summary,
			]
		);

		if ( ! $moved ) {
			// Decided elsewhere meanwhile: put the translation back. The intent, marked restoring, stands while
			// the put-back fails, so the reconciler finishes it.
			// Without the restoring mark a crash mid put-back would be read as this write's result: nothing is put back.
			if ( ! SyncRecorder::mark_restoring( $translation_id, $written['intent'] ) ) {
				self::log( sprintf( 'translation %d could not be marked for its put-back after a lost decision; its intent stands.', $translation_id ) );
			} elseif ( '' === self::put_back_own( 'approve', $translation_id, $language_id, $ttl, $written['previous'], $keys, $written['expected']['fields'], $written['intent'] ) ) {
				SyncRecorder::clear_intent( $translation_id, $written['intent'] );
			} else {
				self::log( sprintf( 'translation %d could not be put back after a lost decision; its intent stands.', $translation_id ) );
			}

			return self::outcome( 'conflict' );
		}

		self::finish_resync_write( $translation_id, $source_id, $language_id, $written, $after );
		self::mark_badge_dirty();

		return self::outcome( 'applied' );
	}

	/**
	 * Undo a resync change under the target lock: title, content, excerpt and fields written back byte for
	 * byte (never the status or slug, nor a key whose live rows have no unit), then link status and state row.
	 *
	 * @param Repo                      $repo Repository.
	 * @param array<string, int|string> $row  Fresh row.
	 * @phpstan-param ChangeRow $row
	 * @param int                       $ttl  The TTL the target lock was taken with.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function undo_resync_locked( Repo $repo, array $row, int $ttl ): array {
		$id             = $row['id'];
		$translation_id = $row['object_id'];
		$language_id    = $row['language_id'];
		$previous       = Repo::decode( $row['previous'] );
		$summary        = self::map( Repo::decode( $row['summary'] ) );
		$keys           = Scalar::strings( $summary['fields'] ?? [] );

		// Never the status or slug: a row the reconciler recorded after a person changed the status
		// holds the older status in previous.
		unset( $previous['post_status'], $previous['post_name'] );

		if ( in_array( SyncRecorder::reconcile_locked( $translation_id, $ttl ), [ 'failed', 'busy' ], true ) ) {
			return self::outcome( 'busy' );
		}

		if ( Repo::STATUS_APPLIED !== Scalar::str( $repo->find( $id )['status'] ?? '' ) ) {
			return self::outcome( 'not_applied' );
		}

		// The row digest is read before the undo's reads: its write lands only on that row.
		$digest = PostTarget::row_digest( $translation_id );
		$check  = PostTarget::unchanged_since( $row );

		if ( '' !== $check ) {
			return self::outcome( $check );
		}

		$read = PostTarget::read( $translation_id, $keys );

		if ( null === $read ) {
			return self::outcome( 'target_gone' );
		}

		// The put-back compares the fields with this read: a field saved between the two reads is a person's.
		if ( ! PostTarget::fields_match( $keys, Scalar::str( $summary['result_fields_hash'] ?? '' ), $read['fields'] ) ) {
			return self::outcome( 'edited_since' );
		}

		if ( null === $digest ) {
			return self::outcome( 'write_failed', PostTarget::ROW_UNREADABLE );
		}

		if ( PostTarget::missed_schedule( $read['post'] ) ) {
			return self::outcome( 'missed_schedule' );
		}

		// A translation another user has open is never written.
		if ( PostTarget::post_locked( $translation_id, get_current_user_id(), true ) ) {
			return self::outcome( 'post_locked' );
		}

		$meta = is_array( $previous['meta'] ?? null ) ? $previous['meta'] : [];

		// The undo restores bytes exactly, so only a user who could save every changed text may make it.
		if ( [] !== SaveProbe::unsavable( self::undo_changes( $previous, $read ), self::undo_field_changes( $keys, $meta, $read['fields'] ), $translation_id, $row['source_id'] ) ) {
			return self::outcome( 'undo_kses' );
		}

		$current = PostTarget::previous_with_meta( $read['post'], $keys );
		$values  = [];
		$hashes  = [];

		foreach ( $keys as $key ) {
			$spec           = $meta[ $key ] ?? null;
			$values[ $key ] = is_array( $spec ) && ! empty( $spec['exists'] ) ? Scalar::str( $spec['value'] ?? '' ) : null;
			$hashes[ $key ] = null === $values[ $key ] ? null : hash( 'sha256', $values[ $key ] );
		}

		$group_repo = \PerfLocale\Plugin::get_instance()->group_repo();
		$expect     = ( $group_repo->get_link_state( $translation_id, $language_id )['status'] ?? '' );
		$state      = is_array( $previous['state'] ?? null ) ? $previous['state'] : null;
		$lost       = false;
		$renew      = self::renewal( $translation_id, $language_id, $ttl, $lost );

		// Renewed before the intent: a writer that stalled never records an intent after a takeover.
		$stop = $renew();

		if ( '' !== $stop ) {
			self::stop_lost( 'undo', $translation_id, $language_id, $ttl, false );

			return self::outcome( 'busy' );
		}

		$intent_id = SyncRecorder::begin_intent(
			$translation_id,
			$row['source_id'],
			$language_id,
			[
				'post_written'  => null,
				'kind'          => 'undo',
				'row_id'        => $id,
				'previous'      => $previous,
				'prior'         => [
					'post_hash'     => $read['hash'],
					'fields_digest' => $read['digest'],
					'fields'        => array_map( static fn ( ?string $v ): ?string => null === $v ? null : hash( 'sha256', $v ), $read['fields'] ),
				],
				'expected'      => [
					'post_hash'     => Store::content_hash( Scalar::str( $previous['post_title'] ), Scalar::str( $previous['post_content'] ), Scalar::str( $previous['post_excerpt'] ) ),
					'fields_digest' => Store::digest_for_keys( $keys, $values ),
					'fields'        => $hashes,
				],
				'keys'          => $keys,
				'restore_state' => $state,
				'link'          => [
					'status' => Scalar::str( $previous['link_status'] ?? '' ),
					'expect' => $expect,
				],
			]
		);

		if ( '' === $intent_id ) {
			return self::outcome( 'write_failed' );
		}

		// Renewed again right before the post UPDATE: a stalled writer never writes its post after a takeover.
		$stored     = null;
		$unwatch    = self::watch_own_update( $translation_id, $stored );
		$after_post = self::after_post_write( $translation_id, $language_id, $ttl, $intent_id, $lost, $stored );

		try {
			$error = SyncRecorder::put_back( $translation_id, $previous, [], $after_post, $renew, $digest, $read['fields'] );
		} finally {
			$unwatch();
		}

		if ( $lost ) {
			// After a takeover the new owner reconciles this undo; without one this request reconciles it now.
			self::stop_lost( 'undo', $translation_id, $language_id, $ttl, true );

			return self::outcome( 'busy' );
		}

		if ( PostTarget::ROW_MOVED === $error ) {
			// A save landed after the undo's read: nothing was written or put back; the change stays applied.
			SyncRecorder::clear_intent( $translation_id, $intent_id );

			return self::outcome( 'edited_since' );
		}

		if ( '' !== $error ) {
			// A later step failed: put back what the change left. The intent stands while that fails too.
			if ( self::put_back_or_keep( $translation_id, $language_id, $ttl, $current, $intent_id, $keys, $hashes ) ) {
				self::keep_undoable( $repo, $row );
			}

			return self::outcome( 'write_failed', $error );
		}

		$moved = $repo->transition( $id, Repo::STATUS_APPLIED, Repo::STATUS_REVERTED, [ 'decided_by' => get_current_user_id() ] );

		if ( ! $moved ) {
			self::put_back_or_keep( $translation_id, $language_id, $ttl, $current, $intent_id, $keys, $hashes );

			return self::outcome( 'conflict' );
		}

		if ( ! SyncRecorder::after_undo( $translation_id, $language_id, $state, Scalar::str( $previous['link_status'] ?? '' ), $expect ) ) {
			self::log( sprintf( 'the undo of translation %d is written; its state is finished by the reconciler.', $translation_id ) );
		}

		return self::outcome( 'reverted' );
	}

	/**
	 * The title, content and excerpt an undo writes back that differ from the live post.
	 *
	 * @param array<mixed>         $previous The decoded previous version.
	 * @param array<string, mixed> $read     The live read.
	 * @phpstan-param LiveRead $read
	 * @return array<string, string>
	 */
	private static function undo_changes( array $previous, array $read ): array {
		$out = [];

		foreach ( SaveProbe::FIELDS as $field ) {
			$old = Scalar::str( $previous[ $field ] ?? '' );

			if ( $old !== Scalar::str( $read['post']->$field ) ) {
				$out[ $field ] = $old;
			}
		}

		return $out;
	}

	/**
	 * The custom-field values an undo writes back that differ from the live ones (a delete restores no text).
	 *
	 * @param array<int, string>         $keys The change's keys.
	 * @param array<mixed>               $meta previous.meta.
	 * @param array<string, string|null> $live The live values.
	 * @return array<string, string>
	 */
	private static function undo_field_changes( array $keys, array $meta, array $live ): array {
		$out = [];

		foreach ( $keys as $key ) {
			$spec = $meta[ $key ] ?? null;

			if ( ! is_array( $spec ) || empty( $spec['exists'] ) ) {
				continue;
			}

			$old = Scalar::str( $spec['value'] ?? '' );

			if ( $old !== ( $live[ $key ] ?? null ) ) {
				$out[ $key ] = $old;
			}
		}

		return $out;
	}

	/**
	 * Put a translation back and clear its intent, or keep the intent when the put-back fails, so a
	 * half-written translation never loses its crash record. Marked restoring first.
	 *
	 * @param int                        $translation_id Translation id.
	 * @param int                        $language_id    Language id.
	 * @param int                        $ttl            The TTL the target lock was taken with.
	 * @param array<mixed>               $previous       The version to write back.
	 * @param string                     $intent_id      The intent id.
	 * @param array<int, string>         $keys           The change's keys.
	 * @param array<string, string|null> $hashes         Per key the hash of the value the undo writes, null for a delete.
	 * @return bool Whether the version was put back.
	 */
	private static function put_back_or_keep( int $translation_id, int $language_id, int $ttl, array $previous, string $intent_id, array $keys, array $hashes ): bool {
		if ( ! SyncRecorder::mark_restoring( $translation_id, $intent_id, $previous ) ) {
			self::log( sprintf( 'translation %d could not be marked for its put-back; its intent stands.', $translation_id ) );

			return false;
		}

		if ( '' === self::put_back_own( 'undo', $translation_id, $language_id, $ttl, $previous, $keys, $hashes, $intent_id ) ) {
			SyncRecorder::clear_intent( $translation_id, $intent_id );

			return true;
		}

		self::log( sprintf( 'translation %d could not be put back; its intent stands.', $translation_id ) );

		return false;
	}

	/**
	 * After a failed Undo put the translation back as the change left it, record the put-back's new
	 * modified time so the change stays undoable; any other difference is left (Undo: edited_since).
	 *
	 * @param Repo                      $repo Repository.
	 * @param array<string, int|string> $row  The applied row.
	 * @phpstan-param ChangeRow $row
	 * @return void
	 */
	private static function keep_undoable( Repo $repo, array $row ): void {
		$summary = self::map( Repo::decode( $row['summary'] ) );
		$keys    = Scalar::strings( $summary['fields'] ?? [] );
		$read    = PostTarget::read( $row['object_id'], $keys );

		if ( null === $read || $read['hash'] !== $row['result_hash'] || $read['status'] !== $row['result_status']
			|| ! PostTarget::fields_match( $keys, Scalar::str( $summary['result_fields_hash'] ?? '' ), $read['fields'] )
			|| $read['modified'] === $row['result_modified']
		) {
			return;
		}

		$repo->set_result_modified( $row['id'], Repo::STATUS_APPLIED, $read['modified'] );
	}

	/**
	 * A writer's own put-back under its target lock. A field at a value neither version holds is another
	 * writer's and stays; the text only while the post holds this write's or its prior bytes; never the status,
	 * the slug only over the one this write's save left (PostTarget::slug_restorable()). A failed read, gone translation
	 * or foreign intent writes nothing.
	 *
	 * @param string                     $kind           apply, approve or undo.
	 * @param int                        $translation_id Translation id.
	 * @param int                        $language_id    Language id.
	 * @param int                        $ttl            The TTL the target lock was taken with.
	 * @param array<mixed>               $previous       The version to write back.
	 * @param array<int, string>         $keys           The change's keys.
	 * @param array<string, string|null> $hashes         Per written key the hash of the value the write wrote, null for a delete.
	 * @param string                     $intent_id      The writer's intent id.
	 * @param string                     $own_slug       The slug this write's save changed the post to ('' none): the previous one is put back over it.
	 * @return string '' on success, else the reason.
	 */
	private static function put_back_own( string $kind, int $translation_id, int $language_id, int $ttl, array $previous, array $keys, array $hashes, string $intent_id, string $own_slug = '' ): string {
		// The row digest (and slug) is read before the fields: the put-back lands only on the row that read saw.
		$row    = PostTarget::row_read( $translation_id );
		$digest = null === $row ? null : $row['digest'];
		$live   = PostTarget::read_checked( $translation_id, $keys );

		if ( false === $live ) {
			return PostTarget::ROW_UNREADABLE;
		}

		if ( null === $live ) {
			return __( 'The translation no longer exists.', 'perflocale' );
		}

		if ( null === $digest ) {
			return PostTarget::ROW_UNREADABLE;
		}

		$state  = ( new ResyncRepository() )->state( $translation_id );
		$intent = null === $state ? [] : Repo::decode( $state['intent'] );

		if ( '' === $intent_id || Scalar::str( $intent['id'] ?? '' ) !== $intent_id ) {
			return PostTarget::ROW_UNREADABLE;
		}

		$plan = SyncRecorder::restore_plan(
			$intent,
			[
				'post_hash'     => $live['hash'],
				'fields_digest' => $live['digest'],
				'fields'        => $live['fields'],
			]
		);

		unset( $previous['post_status'] );

		if ( ! PostTarget::slug_restorable( $translation_id, $previous['post_name'] ?? null, $own_slug, $row['post_name'] ) ) {
			if ( '' !== $own_slug ) {
				self::log( sprintf( 'the slug of translation %d is not put back: it changed again or another post holds it now.', $translation_id ) );
			}

			unset( $previous['post_name'] );
		}

		if ( ! $plan['post'] ) {
			unset( $previous['post_title'], $previous['post_content'], $previous['post_excerpt'] );
		}

		$keep = SyncRecorder::foreign_fields( $keys, self::map( $previous['meta'] ?? null ), $hashes, $live['fields'] );

		return self::put_back_locked( $kind, $translation_id, $language_id, $ttl, $previous, $keep, $live['fields'], $digest );
	}

	/**
	 * A writer's own put-back under its target lock, renewed right before the post UPDATE so a stalled
	 * writer never overwrites a new owner's text; a lost lock goes to stop_lost().
	 *
	 * @param string                     $kind           apply, approve or undo.
	 * @param int                        $translation_id Translation id.
	 * @param int                        $language_id    Language id.
	 * @param int                        $ttl            The TTL the target lock was taken with.
	 * @param array<mixed>               $previous       The version to write back.
	 * @param array<int, string>         $keep           Fields left as they are.
	 * @param array<string, string|null> $expected       Per field the value read under the lock (see PostTarget::write()).
	 * @param string                     $digest         The row digest read before the fields (PostTarget::row_digest()).
	 * @return string '' on success, else the reason.
	 */
	private static function put_back_locked( string $kind, int $translation_id, int $language_id, int $ttl, array $previous, array $keep, array $expected, string $digest ): string {
		$lost  = false;
		$error = SyncRecorder::put_back( $translation_id, $previous, $keep, null, self::renewal( $translation_id, $language_id, $ttl, $lost ), $digest, $expected );

		if ( $lost ) {
			self::stop_lost( $kind, $translation_id, $language_id, $ttl, true );
		}

		return $error;
	}

	/**
	 * The `stored_differs` flag: a `wp_insert_post_data` filter or meta listener stored other bytes.
	 *
	 * @param array<string, mixed> $summary The summary.
	 * @param array<string, mixed> $written The result of resync_write().
	 * @phpstan-param WriteResult $written
	 * @param array<string, mixed> $after   The read-back.
	 * @phpstan-param LiveRead $after
	 * @return array<string, mixed>
	 */
	private static function note_stored_bytes( array $summary, array $written, array $after ): array {
		if ( $after['hash'] === $written['expected']['post_hash'] && $after['digest'] === $written['expected']['fields_digest'] ) {
			return $summary;
		}

		$flags = Scalar::strings( $summary['flags'] ?? [] );

		if ( ! in_array( 'stored_differs', $flags, true ) ) {
			$flags[] = 'stored_differs';
		}

		$summary['flags'] = $flags;

		return $summary;
	}

	/**
	 * A decoded value as a string-keyed map (other keys dropped).
	 *
	 * @param mixed $value Value.
	 * @return array<string, mixed>
	 */
	private static function map( mixed $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $item;
			}
		}

		return $out;
	}

	/**
	 * The title, content and excerpt a validated payload writes.
	 *
	 * @param array<string, mixed> $clean The validated payload.
	 * @phpstan-param Payload $clean
	 * @return array<string, string>
	 */
	private static function proposal_fields( array $clean ): array {
		$fields = [ 'post_content' => Scalar::str( $clean['post_content'] ) ];

		foreach ( [ 'post_title', 'post_excerpt' ] as $field ) {
			if ( is_string( $clean[ $field ] ?? null ) ) {
				$fields[ $field ] = $clean[ $field ];
			}
		}

		return $fields;
	}

	/**
	 * The texts a write of a validated payload holds: as composed and as the acting user's save stores them.
	 *
	 * @param array<string, mixed> $clean  The validated payload.
	 * @phpstan-param Payload $clean
	 * @param array<string, mixed> $stored What the write stores (SaveProbe::stored()).
	 * @param \WP_Post             $post   The translation as read.
	 * @return list<mixed>
	 */
	private static function written_texts( array $clean, array $stored, \WP_Post $post ): array {
		return array_merge(
			array_values( SaveProbe::fields_of( $clean, $post->post_title, $post->post_excerpt ) ),
			array_values( $clean['meta'] ),
			array_values( (array) ( $stored['fields'] ?? [] ) ),
			array_values( (array) ( $stored['meta'] ?? [] ) )
		);
	}

	/**
	 * Whether texts a write holds carry a script construct that neither the source nor the stored translation holds
	 * (a composer fault): WriteTripwire.
	 *
	 * @param array<int|string, mixed> $written The texts written.
	 * @param array<string, mixed>     $read    The translation as read.
	 * @param \WP_Post                 $source  The source.
	 * @param array<int, string>       $keys    The pair's field keys.
	 * @return bool
	 */
	public static function adds_script( array $written, array $read, \WP_Post $source, array $keys ): bool {
		$post  = ( $read['post'] ?? null ) instanceof \WP_Post ? $read['post'] : null;
		$known = array_merge(
			[ $source->post_title, $source->post_content, $source->post_excerpt ],
			null === $post ? [] : [ $post->post_title, $post->post_content, $post->post_excerpt ],
			array_values( PostTarget::field_values( (int) $source->ID, $keys ) ),
			array_values( (array) ( $read['fields'] ?? [] ) )
		);

		if ( [] === \PerfLocale\Resync\WriteTripwire::added( $written, $known ) ) {
			return false;
		}

		self::log( sprintf( 'a ReSync write to translation %d was stopped: it adds script markup that neither the source nor the translation holds', null === $post ? 0 : $post->ID ) );

		return true;
	}

	/**
	 * What a write of a validated payload as the acting user stores (SaveProbe::stored()).
	 *
	 * @param array<string, mixed> $clean The validated payload.
	 * @phpstan-param Payload $clean
	 * @param \WP_Post             $post    The translation as read under the lock.
	 * @param int                  $post_id The translation id.
	 * @param int                  $source_id The source id.
	 * @return array<string, mixed>
	 * @phpstan-return Stored
	 */
	private static function stored_as_user( array $clean, \WP_Post $post, int $post_id, int $source_id ): array {
		return SaveProbe::stored( SaveProbe::fields_of( $clean, $post->post_title, $post->post_excerpt ), $clean['meta'], $post_id, $source_id );
	}

	/**
	 * Whether the acting user's HTML rules hold a text field or custom-field value for review.
	 *
	 * @param array<string, mixed> $stored stored_as_user().
	 * @phpstan-param Stored $stored
	 * @return bool
	 */
	private static function rules_hold( array $stored ): bool {
		return [] !== $stored['held_fields'] || [] !== $stored['held_meta'];
	}

	/**
	 * The write of a resync change: base check, previous version, write-ahead intent, revision, the write as
	 * the acting user under the Outdated flag hold, read-back, and put-back when a step failed. The UPDATE lands
	 * only on the row `$digest` was read from; otherwise PostTarget::ROW_MOVED with the intent cleared.
	 *
	 * @param string               $kind    apply or approve.
	 * @param array<string, mixed> $change  apply: the applied row to insert (without summary.intent).
	 * @param array<string, mixed> $clean   The validated payload.
	 * @phpstan-param Payload $clean
	 * @param array<string, mixed> $summary The change's summary.
	 * @param array<int, string>   $keys    The pair's field keys.
	 * @param array<string, mixed> $read    The live read before the write.
	 * @phpstan-param LiveRead $read
	 * @param int                  $row_id      approve: the pending row.
	 * @param int                  $source_id   Source id.
	 * @param int                  $language_id Language id.
	 * @param int                  $ttl         The TTL the target lock was taken with.
	 * @param array<string, mixed> $stored      What the acting user's write stores (stored_as_user()).
	 * @phpstan-param Stored $stored
	 * @param string               $digest      The row digest read before `$read` (PostTarget::row_digest()).
	 * @param callable|null        $moved       fn(): bool, whether an import ran since the producer's read (asked after the intent).
	 * @return array<string, mixed>
	 * @phpstan-return WriteResult
	 */
	private static function resync_write( string $kind, array $change, array $clean, array $summary, array $keys, array $read, int $row_id, int $source_id, int $language_id, int $ttl, array $stored, string $digest, ?callable $moved = null ): array {
		$translation_id = Scalar::int( $read['post']->ID );
		$post           = $read['post'];
		$fail           = static fn ( string $reason ): array => [
			'ok'          => false,
			'reason'      => $reason,
			'read'        => null,
			'previous'    => [],
			'intent'      => '',
			'state'       => [],
			'bases'       => [],
			'written'     => [],
			'base'        => null,
			'source_hash' => '',
			'quality'     => '',
			'expected'    => [
				'post_hash'     => '',
				'fields_digest' => '',
				'fields'        => [],
			],
		];

		// A key with several rows or a non-string value has no unit: a write would replace every row
		// and an undo delete them all.
		if ( [] !== PostTarget::keys_without_unit( $translation_id, array_map( 'strval', array_keys( $clean['meta'] ) ) ) ) {
			return $fail( 'invalid_payload' );
		}

		$resync_repo = new ResyncRepository();
		$base_id     = Scalar::int( $clean['base_id'] );
		$base        = $base_id > 0 ? $resync_repo->base( $base_id ) : null;

		// The base must belong to the source and hold the hash the proposal names.
		if ( null !== $base && ( $base['source_id'] !== $source_id || $base['source_hash'] !== $clean['source_hash'] ) ) {
			$base    = null;
			$base_id = 0;
		}

		$state_row = $resync_repo->state( $translation_id );
		$state_old = null;

		if ( null !== $state_row ) {
			$state_old = $state_row;
			unset( $state_old['intent'], $state_old['intent_at'] );
		}

		$previous                = PostTarget::previous_with_meta( $post, $keys );
		$previous['link_status'] = ( \PerfLocale\Plugin::get_instance()->group_repo()->get_link_state( $translation_id, $language_id )['status'] ?? '' );
		$previous['state']       = $state_old;

		// What the acting user's save stores for the text fields and custom fields: what is written and expected.
		$fields = self::proposal_fields( $clean );
		$probed = SaveProbe::fields_of( $clean, $post->post_title, $post->post_excerpt );
		$text   = array_merge( $probed, array_intersect_key( $stored['fields'], $probed ) );
		$meta   = array_intersect_key( $stored['meta'], $clean['meta'] ) + $clean['meta'];
		$merged = $read['fields'];
		$hashes = [];

		foreach ( $meta as $key => $value ) {
			$merged[ $key ] = $value;
			$hashes[ $key ] = is_string( $value ) ? hash( 'sha256', $value ) : null;
		}

		$expected = [
			'post_hash'     => Store::content_hash( $text['post_title'], $text['post_content'], $text['post_excerpt'] ),
			'fields_digest' => Store::digest_for_keys( $keys, $merged ),
			'fields'        => $hashes,
		];

		// Owned fields: every written value, plus an unwritten key whose live value still has its recorded hash.
		$owned = [];

		foreach ( Repo::decode( Scalar::str( $state_old['fields_owned'] ?? '' ) ) as $key => $hash ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, $clean['meta'] ) && is_string( $read['fields'][ $key ] ?? null ) && hash( 'sha256', $read['fields'][ $key ] ) === $hash ) {
				$owned[ $key ] = $hash;
			}
		}

		foreach ( $meta as $key => $value ) {
			if ( is_string( $value ) ) {
				$owned[ $key ] = hash( 'sha256', $value );
			}
		}

		// The new base is the source snapshot, quality exact; the proposal's base quality decides the
		// Outdated flag for this write alone.
		$prev_base = Scalar::int( $state_old['base_id'] ?? 0 );
		$quality   = Scalar::str( $summary['base_quality'] ?? SyncRecorder::QUALITY_EXACT );
		$state     = [];

		if ( null !== $base ) {
			$state = [
				'base_id'                 => $base_id,
				'prev_base_id'            => $prev_base,
				'source_hash'             => $base['source_hash'],
				'fields_hash'             => $base['fields_hash'],
				'translation_hash'        => $expected['post_hash'],
				'translation_fields_hash' => $expected['fields_digest'],
				'how'                     => 'applied',
				'base_quality'            => SyncRecorder::QUALITY_EXACT,
				'complete'                => empty( $summary['complete'] ) || ( 'apply' === $kind && ! empty( $summary['open_before'] ) ) ? 0 : 1,
				'fields_owned'            => $owned,
				'fields_unknown'          => Scalar::strings( $summary['fields_unknown'] ?? [] ),
				'block_map'               => $clean['block_map'],
			];
		}

		$bases = array_values( array_filter( [ $base_id, $prev_base ] ) );

		$intent = [
			'post_written' => null,
			'kind'         => $kind,
			'row_id'       => $row_id,
			'previous'     => $previous,
			'prior'        => [
				'post_hash'     => $read['hash'],
				'fields_digest' => $read['digest'],
			],
			'expected'     => $expected,
			'keys'         => $keys,
			'written'      => array_map( 'strval', array_keys( $clean['meta'] ) ),
			'state'        => $state,
			'bases'        => $bases,
			'quality'      => $quality,
		];

		if ( 'apply' === $kind ) {
			// The resync tables hold no user id: a row the reconciler records from this is for user 0.
			unset( $change['user_id'] );
			$change['summary'] = $summary;
			$intent['change']  = $change;
		}

		$lost  = false;
		$renew = self::renewal( $translation_id, $language_id, $ttl, $lost );

		// Last on pre_post_update: the lock renewed, then the post still the pair's (a relink takes no ReSync lock).
		$at_update = static function () use ( $renew, $translation_id, $source_id, $language_id ): string {
			$stop = $renew();

			return '' !== $stop || true === PostTarget::link_holds( $translation_id, $source_id, $language_id ) ? $stop : PostTarget::ROW_MOVED;
		};

		// Renewed before the intent: a writer that stalled never records an intent after a takeover.
		$stop = $renew();

		if ( '' !== $stop ) {
			self::stop_lost( $kind, $translation_id, $language_id, $ttl, false );

			return $fail( self::LOCK_LOST );
		}

		$intent_id = SyncRecorder::begin_intent( $translation_id, $source_id, $language_id, $intent );

		if ( '' === $intent_id ) {
			return $fail( 'intent' );
		}

		// Asked again once the intent stands: a Replace's intent fence makes that upsert wait until the import commits.
		if ( null !== $moved && $moved() ) {
			SyncRecorder::clear_intent( $translation_id, $intent_id );

			return $fail( 'import' );
		}

		$write = array_merge(
			$fields,
			[
				'meta'       => $meta,
				'expected'   => array_intersect_key( $read['fields'], $meta ),
				'source_id'  => $source_id,
				'row_digest' => $digest,
			]
		);

		$unwritten   = false;
		$writing     = null;
		$stored      = null;
		$own         = null;
		$values      = [];
		$unwatch     = self::watch_own_update( $translation_id, $stored, $own );
		$after_post  = self::after_post_write( $translation_id, $language_id, $ttl, $intent_id, $lost, $stored );
		$before_post = self::before_post_write( $translation_id, $intent_id, $unwritten, $writing );

		ContentChangeDetector::hold_flag( $translation_id );

		try {
			$error = Scalar::str(
				self::writing(
					static function () use ( $translation_id, $write, $after_post, $before_post, $at_update, $renew, &$values ): string {
						wp_save_post_revision( $translation_id );

						// Renewed again right before the post UPDATE, before each field and after the last one: a writer
						// that stalled (revision step, a save filter, a field's hooks) writes nothing more after a takeover.
						$error = PostTarget::write( $translation_id, $write, false, $after_post, $before_post, $at_update, $values, $renew );

						return '' === $error ? $renew() : $error;
					},
					$translation_id
				)
			);
			$after = $lost ? null : PostTarget::read( $translation_id, $keys );
		} finally {
			ContentChangeDetector::release_flag( $translation_id );
			$unwatch();
		}

		if ( $lost ) {
			// Nothing more is written and the change is offered again. After a takeover the new owner
			// reconciles this intent; without one this request reconciles it now.
			self::stop_lost( $kind, $translation_id, $language_id, $ttl, true );

			return $fail( self::LOCK_LOST );
		}

		if ( $unwritten ) {
			// The post UPDATE was refused: nothing landed, so nothing is put back over another process's write.
			SyncRecorder::clear_intent( $translation_id, $intent_id );
			self::log( sprintf( 'the resync write to translation %d stopped before its post UPDATE: its intent no longer stands or its hash could not be stored.', $translation_id ) );

			return $fail( 'write_failed' );
		}

		if ( PostTarget::ROW_MOVED === $error || PostTarget::ROW_UNREADABLE === $error ) {
			// The compare-and-set refused the post UPDATE before any save hook: nothing of this write landed.
			SyncRecorder::clear_intent( $translation_id, $intent_id );
			self::log( sprintf( 'the resync write to translation %d was not made: %s.', $translation_id, PostTarget::ROW_MOVED === $error ? 'its row changed after the write read it' : 'its row digest could not be read' ) );

			return $fail( $error );
		}

		// The slug and status this write's own UPDATE left; a later change is another writer's and stays.
		if ( null === $own && null !== $after ) {
			$own = [
				'post_name'   => Scalar::str( $after['post']->post_name ),
				'post_status' => Scalar::str( $after['status'] ),
			];
		}

		$own_slug = null !== $own && $own['post_name'] !== $previous['post_name'] ? $own['post_name'] : '';

		if ( '' === $error && null !== $own && ( '' !== $own_slug || $own['post_status'] !== $previous['post_status'] ) ) {
			$error = 'slug or status changed';
		}

		if ( '' !== $error || null === $after ) {
			// Something was written: put it back, except a field another writer changed meanwhile.
			// The intent stands while the put-back fails.
			$restored = true;

			if ( null === $after || $after['hash'] !== $read['hash'] || $after['digest'] !== $read['digest'] || $after['status'] !== $read['status'] || '' !== $own_slug ) {
				$restored = SyncRecorder::mark_restoring( $translation_id, $intent_id, null, $own_slug )
					&& '' === self::put_back_own( $kind, $translation_id, $language_id, $ttl, $previous, $keys, $hashes, $intent_id, $own_slug );
			}

			if ( $restored ) {
				SyncRecorder::clear_intent( $translation_id, $intent_id );
			}

			self::log( sprintf( 'the resync write to translation %d failed: %s%s', $translation_id, '' === $error ? 'no read-back' : $error, $restored ? '' : '; it could not be put back and its intent stands' ) );

			return $fail( 'write_failed' );
		}

		// A save that landed after this write's UPDATE is not its result: keep the hash this write stored.
		$mine = $stored ?? $writing;

		if ( null !== $mine && $after['hash'] !== $mine && $after['hash'] !== $writing ) {
			$after['hash'] = $mine;
		}

		// Likewise each written field's result is its value right after its write.
		$after['digest'] = Store::digest_for_keys( $keys, $values + $read['fields'] );

		return [
			'ok'          => true,
			'reason'      => '',
			'read'        => $after,
			'previous'    => $previous,
			'intent'      => $intent_id,
			'state'       => $state,
			'bases'       => $bases,
			'written'     => array_map( 'strval', array_keys( $clean['meta'] ) ),
			'base'        => $base,
			'source_hash' => Scalar::str( $clean['source_hash'] ),
			'quality'     => $quality,
			'expected'    => $expected,
		];
	}

	/**
	 * The step between a resync write's post write and its meta writes: renew the lock by compare-and-set,
	 * then store the stored post's hash in the intent. A lost lock sets `$lost` and stops the write.
	 *
	 * @param int         $translation_id Translation id.
	 * @param int         $language_id    Language id.
	 * @param int         $ttl            The TTL the target lock was taken with.
	 * @param string      $intent_id      The writer's intent id.
	 * @param bool        $lost           Set when the lock was lost.
	 * @param string|null $stored         The hash stored right after the UPDATE when known (watch_own_update()); set to the hash recorded.
	 * @return \Closure(): string '' to go on, else the reason to stop.
	 */
	private static function after_post_write( int $translation_id, int $language_id, int $ttl, string $intent_id, bool &$lost, ?string &$stored = null ): \Closure {
		$renew = self::renewal( $translation_id, $language_id, $ttl, $lost );

		return static function () use ( $translation_id, $intent_id, $renew, &$stored ): string {
			$stop = $renew();

			if ( '' !== $stop ) {
				return $stop;
			}

			$hash   = $stored ?? PostTarget::stored_hash( $translation_id );
			$stored = $hash;

			if ( null === $hash || ! SyncRecorder::mark_post_written( $translation_id, $intent_id, $hash ) ) {
				return __( 'The write could not be recorded.', 'perflocale' );
			}

			return '';
		};
	}

	/**
	 * Read the stored hash, slug and status right after each UPDATE of the post (clean_post_cache, before the save
	 * hooks): a save another request makes in those hooks is not the writer's.
	 *
	 * @param int                                                $translation_id Translation id.
	 * @param string|null                                        $stored         Set to the hash read.
	 * @param array{post_name: string, post_status: string}|null $own            Set to the slug and status read.
	 * @return \Closure(): void Removes the listeners.
	 */
	private static function watch_own_update( int $translation_id, ?string &$stored, ?array &$own = null ): \Closure {
		$armed = false;
		$arm   = static function ( $id ) use ( $translation_id, &$armed ): void {
			$armed = $armed || Scalar::int( $id ) === $translation_id;
		};
		$take  = static function ( $id ) use ( $translation_id, &$armed, &$stored, &$own ): void {
			if ( $armed && Scalar::int( $id ) === $translation_id ) {
				$armed  = false;
				$read   = PostTarget::read( $translation_id, [] );
				$stored = null === $read ? null : $read['hash'];
				$own    = null === $read ? null : [
					'post_name'   => Scalar::str( $read['post']->post_name ),
					'post_status' => Scalar::str( $read['status'] ),
				];
			}
		};

		add_action( 'pre_post_update', $arm, PHP_INT_MAX, 1 );
		add_action( 'clean_post_cache', $take, PHP_INT_MIN, 1 );

		return static function () use ( $arm, $take ): void {
			remove_action( 'pre_post_update', $arm, PHP_INT_MAX );
			remove_action( 'clean_post_cache', $take, PHP_INT_MIN );
		};
	}

	/**
	 * Renew a post target's lock by compare-and-set; a lock this request no longer holds sets `$lost`
	 * and the closure answers the reason to stop.
	 *
	 * @param int  $translation_id Translation id.
	 * @param int  $language_id    Language id.
	 * @param int  $ttl            The TTL the target lock was taken with.
	 * @param bool $lost           Set when the lock was lost.
	 * @return \Closure(): string '' to go on, else the reason to stop.
	 */
	public static function renewal( int $translation_id, int $language_id, int $ttl, bool &$lost ): \Closure {
		return static function () use ( $translation_id, $language_id, $ttl, &$lost ): string {
			if ( ! Lock::renew( self::lock_name( 'post', $translation_id, $language_id ), $ttl ) ) {
				$lost = true;

				return self::LOCK_LOST;
			}

			return '';
		};
	}

	/**
	 * Renew the target lock right before a writer records its landed write; a lost lock goes to stop_lost().
	 *
	 * @param string $kind           apply or approve.
	 * @param int    $translation_id Translation id.
	 * @param int    $language_id    Language id.
	 * @param int    $ttl            The TTL the target lock was taken with.
	 * @return bool False when the lock was lost (nothing may be recorded).
	 */
	private static function still_owner( string $kind, int $translation_id, int $language_id, int $ttl ): bool {
		$lost = false;

		if ( '' === self::renewal( $translation_id, $language_id, $ttl, $lost )() ) {
			return true;
		}

		self::stop_lost( $kind, $translation_id, $language_id, $ttl, true );

		return false;
	}

	/**
	 * Stop a writer whose renewal failed. With a new owner, it reconciles; with none, this request takes the
	 * lock back and reconciles now, so a half-written post is put back at once. Otherwise left to the next owner.
	 *
	 * @param string $kind           apply, approve or undo.
	 * @param int    $translation_id Translation id.
	 * @param int    $language_id    Language id.
	 * @param int    $ttl            The TTL the target lock was taken with.
	 * @param bool   $intent         Whether the writer had recorded its intent.
	 * @return string The reconcile outcome, '' after a takeover.
	 */
	private static function stop_lost( string $kind, int $translation_id, int $language_id, int $ttl, bool $intent ): string {
		$outcome = Lock::regain( self::lock_name( 'post', $translation_id, $language_id ), $ttl ) ? SyncRecorder::reconcile_locked( $translation_id, $ttl ) : '';

		self::log(
			sprintf(
				'the %1$s of translation %2$d stopped%3$s: %4$s.',
				$kind,
				$translation_id,
				$intent ? '' : ' before its intent',
				'' === $outcome ? 'its target lock was lost and could not be taken back (another process holds it, or the database refused), so the translation is reconciled by the lock\'s next owner' : 'its target lock was lost with no other owner, so it was taken back and the translation reconciled at once (' . $outcome . ')'
			)
		);

		return $outcome;
	}

	/**
	 * The step right before core's post UPDATE: store the hash it carries in the intent, so a writer dying in
	 * the save hooks is known to have written even if a filter changed the bytes. A failed store refuses it.
	 *
	 * @param int         $translation_id Translation id.
	 * @param string      $intent_id      The writer's intent id.
	 * @param bool        $unwritten      Set when the UPDATE is refused.
	 * @param string|null $writing        Set to the hash of the post that UPDATE carries.
	 * @return \Closure(string): string '' to go on, else the reason to refuse the UPDATE.
	 */
	private static function before_post_write( int $translation_id, string $intent_id, bool &$unwritten, ?string &$writing = null ): \Closure {
		return static function ( string $post_hash ) use ( $translation_id, $intent_id, &$unwritten, &$writing ): string {
			$writing = $post_hash;

			if ( SyncRecorder::mark_post_writing( $translation_id, $intent_id, $post_hash ) ) {
				return '';
			}

			$unwritten = true;

			return __( 'The write could not be recorded.', 'perflocale' );
		};
	}

	/**
	 * After a recorded resync write: the state row with the hashes read back, both bases touched, the Outdated flag.
	 *
	 * @param int                  $translation_id Translation id.
	 * @param int                  $source_id      Source id.
	 * @param int                  $language_id    Language id.
	 * @param array<string, mixed> $written        The result of resync_write().
	 * @phpstan-param WriteResult $written
	 * @param array<string, mixed> $after          The read-back.
	 * @phpstan-param LiveRead $after
	 * @return bool False when the state write failed: the intent stands, so the reconciler records the write.
	 */
	private static function finish_resync_write( int $translation_id, int $source_id, int $language_id, array $written, array $after ): bool {
		$state = $written['state'];

		if ( [] === $state || null === $written['base'] ) {
			// No usable base: no state is kept; the next resync bootstraps.
			if ( ! ( new ResyncRepository() )->delete_state( $translation_id ) ) {
				self::log( sprintf( 'the state of translation %d could not be dropped after its write; its intent stands.', $translation_id ) );

				return false;
			}

			SyncRecorder::clear_intent( $translation_id );

			return true;
		}

		$state['translation_hash']        = $after['hash'];
		$state['translation_fields_hash'] = $after['digest'];
		$base_id                          = Scalar::int( $state['base_id'] );
		unset( $state['base_id'] );

		// The intent ends after the flag is settled: a crash between the two leaves it for the reconciler.
		if ( SyncRecorder::after_write( $translation_id, $source_id, $language_id, $base_id, $state, false ) <= 0 ) {
			self::log( sprintf( 'the state of translation %d could not be recorded after its write; its intent stands.', $translation_id ) );

			return false;
		}

		self::settle_flag( $translation_id, $language_id, $source_id, $written, $after );
		SyncRecorder::clear_intent( $translation_id, $written['intent'] );

		return true;
	}

	/**
	 * The Outdated flag after a resync write, through the recorder's settle step the reconciler runs too.
	 *
	 * @param int                  $translation_id Translation id.
	 * @param int                  $language_id    Language id.
	 * @param int                  $source_id      Source id.
	 * @param array<string, mixed> $written        The result of resync_write().
	 * @phpstan-param WriteResult $written
	 * @param array<string, mixed> $after          The read-back.
	 * @phpstan-param LiveRead $after
	 * @return bool Whether the flag was cleared.
	 */
	private static function settle_flag( int $translation_id, int $language_id, int $source_id, array $written, array $after ): bool {
		$state = $written['state'];
		$base  = $written['base'];

		if ( null === $base ) {
			return false;
		}

		return SyncRecorder::settle_flag( $translation_id, $language_id, $source_id, $base, ! empty( $state['complete'] ), $written['quality'], $after['status'] );
	}

	/**
	 * Whether a submitted edit is the proposal itself once line breaks are normalised (browsers send CR LF).
	 *
	 * @param array<string, mixed>                                             $edited   ['translation' => string, 'extra_forms' => string[]].
	 * @param array{translation: string, extra_forms: array<int, string>|null} $proposal The held proposal.
	 * @return bool
	 */
	private static function matches_proposal( array $edited, array $proposal ): bool {
		$eol   = static fn ( string $text ): string => str_replace( [ "\r\n", "\r" ], "\n", $text );
		$forms = static fn ( mixed $value ): ?string => \PerfLocale\Database\Repository\StringTranslationRepository::normalize_extra_forms( array_map( $eol, Scalar::strings( $value ) ) );

		return $eol( Scalar::str( $edited['translation'] ?? '' ) ) === $eol( $proposal['translation'] )
			&& $forms( $edited['extra_forms'] ?? [] ) === $forms( $proposal['extra_forms'] ?? [] );
	}

	/**
	 * Sanitise an edited string value as the Strings screen's save does (wp_kses_post; empty is refused).
	 *
	 * A value without plural forms must keep every placeholder of its original; plural forms are not
	 * checked, because a form a single count selects may leave the count out.
	 *
	 * @param array<string, mixed> $edited   ['translation' => string, 'extra_forms' => string[]].
	 * @param string               $original The string's original.
	 * @return array{translation: string, extra_forms: array<int, string>}|null Null when refused.
	 */
	private static function clean_edit( array $edited, string $original ): ?array {
		$translation = wp_kses_post( Scalar::str( $edited['translation'] ?? '' ) );
		$forms       = [];

		foreach ( Scalar::strings( $edited['extra_forms'] ?? [] ) as $form ) {
			$forms[] = wp_kses_post( $form );
		}

		if ( '' === trim( $translation ) ) {
			return null;
		}

		if ( null === \PerfLocale\Database\Repository\StringTranslationRepository::normalize_extra_forms( $forms )
			&& ! PlaceholderMasker::preserves_placeholders( $original, $translation )
		) {
			return null;
		}

		return [
			'translation' => $translation,
			'extra_forms' => $forms,
		];
	}

	/**
	 * The number of held changes on this blog, cached in the object cache.
	 *
	 * @return int
	 */
	public static function pending_count(): int {
		if ( ! self::available() ) {
			return 0;
		}

		$found = false;
		$count = wp_cache_get( self::badge_cache_key(), self::BADGE_GROUP, false, $found );

		if ( $found && is_int( $count ) ) {
			return $count;
		}

		$count = ( new Repo() )->count_pending();

		// A failed count is no count: nothing is cached, the next read counts.
		if ( null === $count ) {
			return 0;
		}

		wp_cache_set( self::badge_cache_key(), $count, self::BADGE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * The object cache key of the pending count, with the group's generation.
	 *
	 * @return string
	 */
	public static function badge_cache_key(): string {
		return \PerfLocale\Cache\CacheManager::l2_key( self::BADGE_KEY, self::BADGE_GROUP );
	}

	/**
	 * Mark this blog's pending count as changed. The first mark hooks end_dirty_batches() to shutdown, so
	 * a writer that never ends a batch (a job) still drops the cached count.
	 *
	 * @return void
	 */
	public static function mark_badge_dirty(): void {
		self::$badge_dirty[ (int) get_current_blog_id() ] = true;

		if ( false === has_action( 'shutdown', [ self::class, 'end_dirty_batches' ] ) ) {
			add_action( 'shutdown', [ self::class, 'end_dirty_batches' ], 1, 0 );
		}
	}

	/**
	 * Drop the cached pending count of every blog still marked changed, each on its own blog.
	 *
	 * @return void
	 */
	public static function end_dirty_batches(): void {
		foreach ( array_keys( self::$badge_dirty ) as $blog_id ) {
			$switch = is_multisite() && $blog_id !== (int) get_current_blog_id();

			if ( $switch ) {
				switch_to_blog( $blog_id );
			}

			wp_cache_delete( self::badge_cache_key(), self::BADGE_GROUP );

			if ( $switch ) {
				restore_current_blog();
			}
		}

		self::$badge_dirty = [];
	}

	/**
	 * End a batch of decisions: drop the changed pending count and flush the string caches once.
	 *
	 * @return void
	 */
	public static function end_batch(): void {
		$blog_id = (int) get_current_blog_id();

		if ( isset( self::$badge_dirty[ $blog_id ] ) ) {
			unset( self::$badge_dirty[ $blog_id ] );
			wp_cache_delete( self::badge_cache_key(), self::BADGE_GROUP );
		}

		StringTarget::flush();
	}

	/**
	 * The lock name of a target; a post's is per translation (its intent and state are), whatever language is named.
	 *
	 * @param string $type      Object type.
	 * @param int    $object_id Object id.
	 * @param int    $lang      Language id (not part of a post's name).
	 * @return string
	 */
	public static function lock_name( string $type, int $object_id, int $lang ): string {
		return 'automation_' . sanitize_key( $type ) . '_' . $object_id . ( 'post' === $type ? '' : '_' . $lang );
	}

	/**
	 * Fire an automation action with a stored row.
	 *
	 * @param string                    $event held, applied, rejected or reverted.
	 * @param array<string, mixed>|null $row   The row.
	 * @return void
	 */
	private static function fire( string $event, ?array $row ): void {
		if ( null === $row ) {
			return;
		}

		unset( $row['payload'], $row['previous'] );

		// A listener that throws never changes the decision already made.
		try {
			switch ( $event ) {
				case 'held':
					/**
					 * Fires after a change is held for review.
					 *
					 * @hook perflocale/automation/held
					 * @since 1.0.8
					 *
					 * @param array<string, mixed> $change The stored change (no payload, no previous value).
					 */
					do_action( 'perflocale/automation/held', $row );
					break;

				case 'applied':
					/**
					 * Fires after a change is applied: at once by the policy (decided_by 0) or by a person's approval.
					 *
					 * @hook perflocale/automation/applied
					 * @since 1.0.8
					 *
					 * @param array<string, mixed> $change The stored change (no payload, no previous value).
					 */
					do_action( 'perflocale/automation/applied', $row );
					break;

				case 'rejected':
					/**
					 * Fires after a held change is rejected.
					 *
					 * @hook perflocale/automation/rejected
					 * @since 1.0.8
					 *
					 * @param array<string, mixed> $change The stored change (no payload, no previous value).
					 */
					do_action( 'perflocale/automation/rejected', $row );
					break;

				case 'reverted':
					/**
					 * Fires after an applied change is undone.
					 *
					 * @hook perflocale/automation/reverted
					 * @since 1.0.8
					 *
					 * @param array<string, mixed> $change The stored change (no payload, no previous value).
					 */
					do_action( 'perflocale/automation/reverted', $row );
					break;
			}
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'a perflocale/automation/%s listener failed for change %d: %s', $event, Scalar::int( $row['id'] ?? 0 ), $e->getMessage() ) );
		}
	}

	/**
	 * The status of a change as people read it; an unknown status is returned as it is.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		return match ( $status ) {
			Repo::STATUS_PENDING    => __( 'Waiting for review', 'perflocale' ),
			Repo::STATUS_APPLIED    => __( 'Applied', 'perflocale' ),
			Repo::STATUS_REJECTED   => __( 'Rejected', 'perflocale' ),
			Repo::STATUS_REVERTED   => __( 'Undone', 'perflocale' ),
			Repo::STATUS_SUPERSEDED => __( 'Replaced by a newer update', 'perflocale' ),
			Repo::STATUS_REFUSED    => __( 'Refused', 'perflocale' ),
			default                 => $status,
		};
	}

	/**
	 * Whether an import (a data import or a migration) is running on this site: ReSync then writes nothing.
	 *
	 * @return bool
	 */
	public static function import_running(): bool {
		return \PerfLocale\Admin\ImportLock::running() || \PerfLocale\Migration\MigrationLock::seconds_remaining() > 0;
	}

	/**
	 * The import generation ReSync work is fenced by. An odd count read while the lock is held is a running counted
	 * import; an even one is settled, whoever holds the lock (a URL-slug write writes no row ReSync reads).
	 *
	 * @return int|null Null while a counted import or a migration runs, or when the count could not be read.
	 */
	public static function import_fence(): ?int {
		// One statement for both rows: every pair and every write asks.
		$read = \PerfLocale\Admin\ImportLock::fence_generation_with( \PerfLocale\Migration\MigrationLock::option_name() );

		if ( null === $read || \PerfLocale\Migration\MigrationLock::remaining_of( $read['other'] ) > 0 || \PerfLocale\Admin\ImportLock::counting( $read['generation'] ) ) {
			return null;
		}

		return $read['generation'];
	}

	/**
	 * A write's `import_generation` option.
	 *
	 * @param array<string, mixed> $options The write's options.
	 * @return int|false|null False when absent (no fence), null when not a count (an import cannot be ruled out).
	 */
	private static function fence_option( array $options ): int|false|null {
		if ( ! array_key_exists( 'import_generation', $options ) ) {
			return false;
		}

		return is_int( $options['import_generation'] ) ? $options['import_generation'] : null;
	}

	/**
	 * Whether an import ran or runs since import_fence() gave `$generation` (null: one could not be ruled out then).
	 *
	 * @param int|null $generation The work's import_fence().
	 * @return bool
	 */
	public static function import_moved( ?int $generation ): bool {
		return null === $generation || self::import_fence() !== $generation;
	}

	/**
	 * A decision outcome with its message.
	 *
	 * @param string $code   Outcome code.
	 * @param string $detail Extra detail (a write error).
	 * @return array{ok: bool, code: string, message: string}
	 */
	public static function outcome( string $code, string $detail = '' ): array {
		$messages = [
			'applied'         => __( 'The change was approved and written.', 'perflocale' ),
			'rejected'        => __( 'The change was rejected.', 'perflocale' ),
			'reverted'        => __( 'The change was undone.', 'perflocale' ),
			'unavailable'     => __( 'Automation is not ready on this site yet. Reload the page and try again.', 'perflocale' ),
			'not_found'       => __( 'The change no longer exists.', 'perflocale' ),
			'forbidden'       => __( 'You are not allowed to do this.', 'perflocale' ),
			'busy'            => __( 'This translation is being changed right now. Try again in a few minutes.', 'perflocale' ),
			'source_changed'  => __( 'The source changed after this update was computed, so it was not applied. Run Resync again for an update of the current source.', 'perflocale' ),
			'import_running'  => __( 'An import is running on this site, so nothing was written. Try again when it has finished.', 'perflocale' ),
			'not_pending'     => __( 'The change is no longer waiting for review.', 'perflocale' ),
			'not_applied'     => __( 'Only an applied change can be undone.', 'perflocale' ),
			'target_gone'     => __( 'The translation or its language no longer exists.', 'perflocale' ),
			'edited_since'    => __( 'The translation was changed after this change was made, so it was not overwritten. Compare both versions.', 'perflocale' ),
			'invalid_edit'    => __( 'The translation is empty or lost a placeholder of the original.', 'perflocale' ),
			'write_failed'    => __( 'The translation could not be written.', 'perflocale' ),
			'no_previous'     => __( 'There is no earlier version to restore.', 'perflocale' ),
			'conflict'        => __( 'The change was decided elsewhere at the same time. Reload the page.', 'perflocale' ),
			'unsupported'     => __( 'This change cannot be decided here.', 'perflocale' ),
			'invalid_payload' => __( 'The stored update is not in a shape that can be written.', 'perflocale' ),
			'missed_schedule' => __( 'The translation is scheduled to publish within two minutes or its date has passed, so a save could publish it. Publish or reschedule it first.', 'perflocale' ),
			'kses'            => __( 'Your account cannot save part of this update as proposed. Review what would be removed and apply it from the review screen.', 'perflocale' ),
			'stale'           => __( 'The translation or its fields changed after this update was computed.', 'perflocale' ),
			'post_locked'     => __( 'Nothing was written: another user is currently editing this translation.', 'perflocale' ),
			'source_hidden'   => __( 'This update keeps text from a source you cannot read. Only a user who can read the source can apply it.', 'perflocale' ),
			'unsafe_markup'   => __( 'This update was not written: it adds code (a script, an event attribute or a script link) that neither the source nor the translation holds. Run ReSync again; if it repeats, edit the translation by hand.', 'perflocale' ),
			'undo_kses'       => __( 'The earlier version holds HTML your account cannot save, so it cannot be restored exactly. Ask a user who can save unfiltered HTML to undo it. The translation\'s revisions may also hold its earlier title, content and excerpt.', 'perflocale' ),
		];

		$ok      = in_array( $code, [ 'applied', 'rejected', 'reverted' ], true );
		$message = $messages[ $code ] ?? $messages['unsupported'];

		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return [
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
		];
	}
}
