<?php
/**
 * Background job for DataImporter.
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
 * Runs DataImporter::import() in the background for a large upload (Auto mode) or always (Always-async). Args:
 * `file_path` (inside uploads), `replace`, `allow_foreign_ids`, `addon_choices`; the result is the importer's.
 * Args, result keys and failed runs: docs-ai/11-code-rationale.md, "DataImportJob: args and result".
 */
final class DataImportJob extends AbstractJob {

	/**
	 * The names the import screen stages in the temp folder (AdminForms, ImportUpload::take()): only these are deleted.
	 */
	public const STAGED_NAME = '/^import-(?:[A-Za-z0-9]{16}|u[0-9]+-s[0-9]+-[A-Za-z0-9]{24}-claimed)\.json\z/';

	/**
	 * How long a failed import keeps its file for Retry.
	 */
	private const KEEP_FOR_RETRY = HOUR_IN_SECONDS;

	private const CLEANUP_HOOK = 'perflocale_cleanup_temp_import';

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'data_import';
	}

	/**
	 * {@inheritDoc}
	 *
	 * `perflocale_import_export`, as the import form and the CLI command; manage_options too (JobAccess::ALSO_REQUIRES):
	 * a Retry applies settings, add-ons and roles.
	 */
	public function get_required_capability(): string {
		return 'perflocale_import_export';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The Auto-mode cutoff: a smaller import runs inline (the admin sees the result), a larger one avoids PHP-FPM
	 * timeouts. Filters: the `background_thresholds` setting, `perflocale/jobs/threshold/data_import`.
	 */
	public function get_default_threshold(): int {
		return 1000;
	}

	/**
	 * Four hours, past the longest import: no tick refreshes the lock while it runs, and a second worker's replace
	 * (TRUNCATE then restore) after a reclaim could destroy what the first one wrote.
	 */
	public function get_lock_ttl(): int {
		return 4 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The file size at about 200 bytes per JSON row: an over-count, but parsing the file would defeat a cheap decision.
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

		// About 200 bytes per JSON row in a typical translation export.
		return intdiv( max( 0, $bytes ), 200 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $progress Called once, before the import: no tick after it may see a cancel or pause
	 *                                       (docs-ai/11-code-rationale.md, "DataImportJob: one progress tick").
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the import path is unsafe or unreadable.
	 */
	public function execute( array $args, callable $progress ): array {
		$file    = isset( $args['file_path'] ) ? (string) $args['file_path'] : '';
		$replace = ! empty( $args['replace'] );

		// Path guard: the user has the cap, but args replayed from JobState after a crash are checked again.
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

		// The staged copy is a full export of a site: it goes when this job ends, or an hour after a failure (Retry).
		self::on_end( 'perflocale/jobs/completed', $file );
		self::on_end( 'perflocale/jobs/canceled', $file );
		self::on_end( 'perflocale/jobs/failed', $file );

		$progress( 0, 1 );

		// Stored at the requesting user's trust level, the current user on both paths (inline: their request; worker:
		// WorkerRunner switches to `created_by`). Decided at run time, so nothing in the args changes it; WP-CLI calls
		// DataImporter directly at shell trust.
		$sanitize_strings = ! current_user_can( 'unfiltered_html' );

		$importer = new DataImporter();
		$result   = $importer->import(
			$file,
			$replace,
			$sanitize_strings,
			// ! empty(): JobState round-trips args through JSON, so a ticked checkbox comes back as true, 1 or '1'.
			[
				'allow_foreign_ids' => ! empty( $args['allow_foreign_ids'] ),
				'addon_choices'     => isset( $args['addon_choices'] ) && is_array( $args['addon_choices'] ) ? \PerfLocale\Admin\Typed::strs( $args['addon_choices'] ) : null,
			]
		);

		// Refused before its first write, or no row written with errors: a failed run with the first error as its
		// reason, never re-run automatically (a refusal is deterministic; a replace that removed rows needs the
		// operator).
		$first = trim( $result['errors'][0] ?? '' );

		if ( '' !== $first && ( ! empty( $result['refused'] ) || 0 === $result['imported'] ) ) {
			$result['run_failed']  = true;
			$result['first_error'] = $first;
		}

		// DataImporter::import() already flushed caches (and in files mode regenerated the files) after its string
		// repair, so nothing runs again here; no progress tick follows the import.

		// Normalize to a plain array for the JobState write.
		return is_array( $result ) ? $result : [];
	}

	/**
	 * Replayed job args must resolve to a file inside the uploads dir, or a re-run could read any file PHP can.
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

		// The uploads path plus a separator, so a sibling such as `uploads-old` does not match.
		return str_starts_with( $real, rtrim( $uploads, '/\\' ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * On the worker's $hook for the job that holds $file: delete it (completed, canceled) or keep it for Retry (failed).
	 *
	 * @param string $hook Worker action.
	 * @param string $file The job's `file_path` arg.
	 * @return void
	 */
	private static function on_end( string $hook, string $file ): void {
		$listener = static function ( $job_id, $type ) use ( $hook, $file, &$listener ): void {
			if ( 'data_import' !== $type || ! is_string( $job_id ) ) {
				return;
			}

			// This job's own file only, read from its row: a listener left by an earlier job in this process takes nothing.
			$args = \PerfLocale\Background\JobState::args( [ $job_id ] )[ $job_id ] ?? [];

			if ( ( $args['file_path'] ?? null ) !== $file ) {
				return;
			}

			remove_action( $hook, $listener );

			if ( 'perflocale/jobs/failed' === $hook ) {
				self::keep_for_retry( $file );
			} else {
				self::discard_file( $file );
			}
		};

		add_action( $hook, $listener, 10, 2 );
	}

	/**
	 * A canceled job no worker took: delete its staged file now (nothing else will before the day-out event).
	 *
	 * @param string $job_id UUID of a job JobState::cancel() just canceled.
	 * @return void
	 */
	public static function canceled_unstarted( string $job_id ): void {
		$state = \PerfLocale\Background\JobState::get( $job_id );

		// A failed read deletes nothing; a started job's worker or the cleanup event handles its file.
		if ( null === $state || 'data_import' !== $state['type'] || 'canceled' !== $state['status'] || 0 !== $state['started_at'] ) {
			return;
		}

		$args = is_array( $state['args'] ?? null ) ? $state['args'] : [];
		$file = $args['file_path'] ?? null;

		if ( is_string( $file ) ) {
			self::discard_file( $file );
		}
	}

	/**
	 * Whether this blog has a data import queued or running; null when the jobs could not be read.
	 *
	 * @return bool|null
	 */
	public static function any_live(): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The jobs table, read fresh before staging a web import.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = %s AND status IN ('queued', 'running') AND blog_id = %d", \PerfLocale\Database\Schema::table( 'jobs' ), 'data_import', get_current_blog_id() ) );

		if ( ! is_numeric( $count ) || '' !== $wpdb->last_error ) {
			return null;
		}

		return (int) $count > 0;
	}

	/**
	 * Whether a queued or running import of this blog still needs $file; true also when the jobs cannot be read.
	 *
	 * @param string $file Staged file path.
	 * @return bool
	 */
	public static function holds( string $file ): bool {
		$held = self::held_names();

		return null === $held || isset( $held[ basename( $file ) ] );
	}

	/**
	 * Delete the old temp files (daily sweep) no queued or running import names, read under the import lock; a busy
	 * lock or a failed read deletes none.
	 *
	 * @param list<string> $paths Files directly in the temp folder, past the sweep's age.
	 * @return int Files deleted.
	 */
	public static function sweep_stale( array $paths ): int {
		if ( ! self::lock_files( 1 ) ) {
			return 0;
		}

		$removed = 0;

		try {
			$held = self::held_names();

			if ( null === $held ) {
				return 0;
			}

			foreach ( $paths as $path ) {
				if ( ! isset( $held[ basename( $path ) ] ) && ( wp_delete_file( $path ) || ! file_exists( $path ) ) ) {
					++$removed;
				}
			}
		} finally {
			\PerfLocale\Admin\ImportLock::release();
		}

		return $removed;
	}

	/**
	 * Run $reset (this failed or canceled import back to queued) under the import lock, only while its staged file
	 * exists: no cleanup deletes the file between its read and the reset.
	 *
	 * @param array<string, mixed> $state Row read before the reset.
	 * @param callable(): bool     $reset The reset.
	 * @param string               $why   Set to the refusal's message.
	 * @return bool Whether it was reset.
	 */
	public static function requeue( array $state, callable $reset, string &$why ): bool {
		$args = is_array( $state['args'] ?? null ) ? $state['args'] : [];
		$file = $args['file_path'] ?? null;

		if ( ! self::lock_files( 5 ) ) {
			$why = __( 'This import cannot be retried now: another import, or a cleanup of import files, is running. Retry when it has finished.', 'perflocale' );

			return false;
		}

		try {
			clearstatcache();

			if ( ! is_string( $file ) || ! is_file( $file ) ) {
				$why = __( 'This import cannot run again: its uploaded file was deleted. Import the file again on Settings > Export & Import.', 'perflocale' );

				return false;
			}

			return $reset();
		} finally {
			\PerfLocale\Admin\ImportLock::release();
		}
	}

	/**
	 * Take the import lock (uncounted, as a URL-slug write) for a read-then-delete or a re-queue of staged files; an
	 * import holds it from reading its file to its last write. Release with ImportLock::release().
	 *
	 * @param int $tries Attempts, 200 ms apart.
	 * @return bool False when busy or without a database.
	 */
	public static function lock_files( int $tries ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		for ( $try = 1; ! \PerfLocale\Admin\ImportLock::acquire( 60, false ); ++$try ) {
			if ( $try >= $tries ) {
				return false;
			}

			usleep( 200000 );
		}

		return true;
	}

	/**
	 * The file names (basenames: the path may differ by context) of this blog's queued and running imports, as
	 * name => true; null when the jobs or a row's args cannot be read.
	 *
	 * @return array<string, true>|null
	 */
	private static function held_names(): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The jobs table, read fresh by the cleanups.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT args FROM %i WHERE type = %s AND status IN ('queued', 'running') AND blog_id = %d", \PerfLocale\Database\Schema::table( 'jobs' ), 'data_import', get_current_blog_id() ) );

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$names = [];

		foreach ( $rows as $raw ) {
			$args = is_string( $raw ) ? json_decode( $raw, true ) : null;
			$file = is_array( $args ) ? ( $args['file_path'] ?? null ) : null;

			if ( ! is_string( $file ) ) {
				return null;
			}

			$names[ basename( $file ) ] = true;
		}

		return $names;
	}

	/**
	 * Keep a failed import's file for Retry, then let its cleanup event delete it.
	 *
	 * @param string $file Staged file path.
	 * @return void
	 */
	public static function keep_for_retry( string $file ): void {
		\PerfLocale\Background\BackgroundEvents::unschedule( self::CLEANUP_HOOK, [ $file ] );
		\PerfLocale\Background\BackgroundEvents::enqueue( self::CLEANUP_HOOK, [ $file ], self::KEEP_FOR_RETRY );
	}

	/**
	 * Delete a staged import file and its cleanup event: only a regular file with a staged name directly in this blog's
	 * temp folder (a job's args could name any file in uploads).
	 *
	 * @param string $file Staged file path.
	 * @return bool True when the file was deleted.
	 */
	private static function discard_file( string $file ): bool {
		$temp = \PerfLocale\Helper::uploads_temp_dir();
		$dir  = '' === $temp ? false : realpath( $temp );

		if ( false === $dir || 1 !== preg_match( self::STAGED_NAME, basename( $file ) ) || realpath( dirname( $file ) ) !== $dir
			|| is_link( $file ) || ! is_file( $file ) ) {
			return false;
		}

		wp_delete_file( $file );
		\PerfLocale\Background\BackgroundEvents::unschedule( self::CLEANUP_HOOK, [ $file ] );

		clearstatcache( true, $file );

		return ! file_exists( $file );
	}
}
