<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Admin\DataImporter}.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Admin\DataImporter;
use PerfLocale\Background\AbstractJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a full-site data import in the background when the upload is big
 * enough to warrant it (Auto mode), or whenever the admin has chosen
 * Always-async background processing.
 *
 * The job is a pure wrapper around {@see DataImporter::import()} —
 * the underlying import code already streams its way through the file
 * and is bounded in memory; the AbstractJob layer adds:
 *
 *   - Cap re-check using the dispatching user's `created_by`.
 *   - Runner abstraction (AS or WP-Cron, decided by `background_engine`).
 *   - Crash recovery via {@see JobLock} TTL.
 *   - Retry-with-backoff on uncaught exceptions (up to 5 attempts).
 *   - Status visibility in *PerfLocale → Jobs*.
 *
 * Args shape:
 *   - 'file_path' : string  Absolute path to the JSON export to import.
 *                            Must be inside `wp-content/uploads/` —
 *                            validated below.
 *   - 'replace'   : bool    Whether to TRUNCATE-then-restore (true) or
 *                            merge into existing rows (false).
 *
 * Result shape (matches DataImporter::import()):
 *   - 'imported' : int      Rows successfully imported.
 *   - 'skipped'  : int      Rows skipped (e.g. duplicates, validation fail).
 *   - 'errors'   : string[] Per-row errors, capped by DataImporter.
 */
final class DataImportJob extends AbstractJob {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'data_import';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Matches the cap the admin import form and CLI command require —
	 * `perflocale_import_export`.
	 */
	public function get_required_capability(): string {
		return 'perflocale_import_export';
	}

	/**
	 * {@inheritDoc}
	 *
	 * 1000 rows is the Auto-mode cutoff. Smaller imports run inline (the
	 * admin clicked "Import" and expects to see the result); larger ones
	 * go async to dodge PHP-FPM request timeouts. Filterable per-site via
	 * `background_thresholds` setting or `perflocale/jobs/threshold/data_import`.
	 */
	public function get_default_threshold(): int {
		return 1000;
	}

	/**
	 * Data imports often do a TRUNCATE-then-restore (`replace=true`) which
	 * is decidedly NOT idempotent; if the lock expires mid-run and a
	 * second worker reclaims, the second TRUNCATE could destroy data the
	 * first worker just wrote. Bump TTL to comfortably exceed worst-case
	 * import duration.
	 */
	public function get_lock_ttl(): int {
		return 4 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * For DataImport, the natural cost dimension is the import file size.
	 * We translate bytes → rough row count assuming an average 200-byte
	 * JSON row (translation table dumps land in that ballpark). Cheap +
	 * conservative — over-counts compared to actually parsing the JSON,
	 * but parsing now would defeat the purpose of a cheap pre-dispatch
	 * decision.
	 *
	 * @param array<string, mixed> $args
	 * @return int Estimated row count.
	 */
	protected function args_size( array $args ): int {
		$file = isset( $args['file_path'] ) ? (string) $args['file_path'] : '';

		if ( $file === '' || ! is_readable( $file ) ) {
			return 0;
		}

		$bytes = (int) filesize( $file );

		// Approx 200 bytes per JSON-encoded row in a typical translation export.
		return intdiv( max( 0, $bytes ), 200 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $progress Called exactly TWICE — once before
	 *                                       the import and once after it —
	 *                                       because DataImporter doesn't yet
	 *                                       expose per-table progress
	 *                                       callbacks. Promoting this to true
	 *                                       incremental progress is a
	 *                                       follow-up task (see backlog).
	 *
	 *   Consequence worth knowing before you rely on Cancel: the progress
	 *   callback is also the ONLY place the worker probes for an operator
	 *   cancel, so a cancel issued WHILE the import is running is not seen
	 *   until the final tick — by which point DataImporter has already
	 *   finished and committed every row, and the post-import cache flush has
	 *   run. The cancel is honoured at that point (the tick throws), so the
	 *   job row ends up `canceled` with no stored result even though the
	 *   import fully succeeded. Cancel therefore reliably stops a QUEUED
	 *   import (WorkerRegistry bails on a terminal status before execute()),
	 *   but cannot interrupt a running one. `get_lock_ttl()` is 4h for the
	 *   same reason: no tick means no lock refresh for the whole import.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the import path is unsafe or unreadable.
	 */
	public function execute( array $args, callable $progress ): array {
		$file    = isset( $args['file_path'] ) ? (string) $args['file_path'] : '';
		$replace = ! empty( $args['replace'] );

		// Path traversal guard — the dispatching user already has the
		// `perflocale_import_export` cap, but defence-in-depth pays off
		// when args are re-played from JobState after a queue + crash.
		if ( ! self::is_safe_upload_path( $file ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Import file path is not inside wp-content/uploads/: %s', $file ) )
			);
		}

		if ( ! is_readable( $file ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Import file not readable: %s', $file ) )
			);
		}

		$progress( 0, 1 );

		$importer = new DataImporter();
		$result   = $importer->import( $file, $replace );

		// Flush post-import caches — see MigrationCacheHelper for the
		// full sequence + rationale.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		$progress( 1, 1 );

		// Normalise to a plain array (DataImporter already returns the
		// right shape, but assert it for the JobState write).
		return is_array( $result ) ? $result : [];
	}

	/**
	 * Guard against re-played job args pointing at an arbitrary file path.
	 *
	 * The path MUST resolve to a file inside the WP uploads directory.
	 * Without this, a worker re-run after a state mutation could end up
	 * reading anything on disk the PHP user has access to.
	 *
	 * @param string $file Candidate absolute path.
	 * @return bool
	 */
	private static function is_safe_upload_path( string $file ): bool {
		$real = realpath( $file );

		if ( $real === false ) {
			return false;
		}

		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		$uploads = realpath( (string) $upload_dir['basedir'] );

		if ( $uploads === false ) {
			return false;
		}

		// Use DIRECTORY_SEPARATOR-agnostic prefix check.
		return str_starts_with( $real, rtrim( $uploads, '/\\' ) . DIRECTORY_SEPARATOR );
	}
}
