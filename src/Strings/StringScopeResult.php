<?php
/**
 * Result of a string-scope sync.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What StringRepository::sync_string_scope() did; callers show or log every status but `ok`.
 * lock_busy and capped write/delete nothing; db_error keeps earlier writes; deferred hands the rest of the
 * removals to `global_fields_sync`; skipped = the sweep met a row newer than the enumeration.
 */
final class StringScopeResult {

	public const OK        = 'ok';
	public const LOCK_BUSY = 'lock_busy';
	public const DB_ERROR  = 'db_error';
	public const CAPPED    = 'capped';
	public const DEFERRED  = 'deferred';
	public const SKIPPED   = 'skipped';

	/** Every status. */
	public const STATUSES = [ self::OK, self::LOCK_BUSY, self::DB_ERROR, self::CAPPED, self::DEFERRED, self::SKIPPED ];

	/**
	 * The counts of one sync.
	 *
	 * @param string             $status    One of STATUSES.
	 * @param int                $inserted  New rows inserted (new texts and the new rows of edits).
	 * @param int                $kept      Old rows whose text is still a member.
	 * @param int                $migrated  Edits whose translations moved or were copied to the new row.
	 * @param int                $deleted   Old rows deleted in this call.
	 * @param int                $deferred  Removals handed to the background job.
	 * @param int                $unmatched Scanned names no descriptor claimed (set by the enumeration).
	 * @param array<int, string> $refused   Members refused with their reason.
	 */
	public function __construct(
		public readonly string $status,
		public readonly int $inserted = 0,
		public readonly int $kept = 0,
		public readonly int $migrated = 0,
		public readonly int $deleted = 0,
		public readonly int $deferred = 0,
		public readonly int $unmatched = 0,
		public readonly array $refused = []
	) {
	}

	/**
	 * A result that wrote nothing.
	 *
	 * @param string $status LOCK_BUSY, DB_ERROR, CAPPED or SKIPPED.
	 * @return self
	 */
	public static function failed( string $status ): self {
		return new self( $status );
	}

	/**
	 * Whether the scope matches the set (removals may still run in the job).
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return self::OK === $this->status || self::DEFERRED === $this->status;
	}

	/**
	 * Whether a row was inserted, moved or deleted.
	 *
	 * @return bool
	 */
	public function changed(): bool {
		return $this->inserted > 0 || $this->migrated > 0 || $this->deleted > 0;
	}

	/**
	 * The result as the job log and the panel status line store it.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'status'    => $this->status,
			'inserted'  => $this->inserted,
			'kept'      => $this->kept,
			'migrated'  => $this->migrated,
			'deleted'   => $this->deleted,
			'deferred'  => $this->deferred,
			'unmatched' => $this->unmatched,
			'refused'   => $this->refused,
		];
	}
}
