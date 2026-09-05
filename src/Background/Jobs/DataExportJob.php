<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Admin\DataExporter}.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Admin\DataExporter;
use PerfLocale\Background\AbstractJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams a full-site export to a file in the uploads directory.
 *
 * Sync `download()` path still works as-is — small admins clicking
 * "Download export" get an inline streaming response, no Jobs UI
 * involved. This job covers the OTHER pattern: kick off a background
 * export to disk + grab it from *PerfLocale → Jobs* when finished.
 *
 * Args shape:
 *   - 'sections' : string[]  Section keys to include (e.g. ['settings',
 *                            'translations']). Empty = full export.
 *   - 'path'     : string    Optional override; default = uploads dir
 *                            with timestamp filename. Validated to live
 *                            inside `wp_upload_dir()['basedir']`.
 */
final class DataExportJob extends AbstractJob {

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'data_export';
	}

	/** {@inheritDoc} */
	public function get_required_capability(): string {
		return 'perflocale_import_export';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The export streams; size is bounded by translation count. The
	 * threshold is conservative — small sites complete in under a
	 * second and shouldn't be forced async.
	 */
	public function get_default_threshold(): int {
		return 5000;
	}

	/**
	 * Exports stream tens of thousands of rows in one call; a 30-min cap
	 * isn't enough for biggest sites. 2-hour TTL covers worst case.
	 */
	public function get_lock_ttl(): int {
		return 2 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Estimate by counting translation_links rows — the dominant cost
	 * driver in an export.
	 */
	protected function args_size( array $args ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$wpdb->prefix . 'perflocale_translation_links'
			)
		);
		return $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the export path is outside wp-content/uploads/.
	 */
	public function execute( array $args, callable $progress ): array {
		$sections = isset( $args['sections'] ) && is_array( $args['sections'] ) ? $args['sections'] : [];
		$path     = isset( $args['path'] ) ? (string) $args['path'] : '';

		if ( $path === '' ) {
			$path = self::default_export_path();
		}

		if ( ! self::is_safe_upload_path( $path, true ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Export path is not inside wp-content/uploads/: %s', $path ) )
			);
		}

		$progress( 0, 1 );

		$exporter = new DataExporter();
		$bytes    = $exporter->write_to_file( $path, $sections );

		if ( $bytes === false ) {
			throw new \RuntimeException( esc_html( sprintf( 'Failed to write export to: %s', $path ) ) );
		}

		$progress( 1, 1 );

		/**
		 * Fires after `DataExportJob` has successfully written the export
		 * file to disk, before the job result is returned to the worker
		 * and stored on the JobState row.
		 *
		 * Useful for offsite-backup integrations: copy the file to S3 /
		 * Dropbox / a remote shell, push a webhook to your monitoring
		 * pipeline, append an audit-log entry, etc.
		 *
		 * The file is still readable at $path when this action fires; it
		 * will be served (and deleted) later by the download endpoint when
		 * the operator clicks Download on PerfLocale → Jobs.
		 *
		 * @hook  perflocale/export/written
		 * @since 1.0.0
		 *
		 * @param string             $path     Absolute path to the written
		 *                                     export file (inside uploads/).
		 * @param int                $bytes    Size of the written file.
		 * @param array<int,string>  $sections The section keys that were
		 *                                     exported — a subset of
		 *                                     {@see DataExporter::SECTIONS}
		 *                                     (settings, roles, languages,
		 *                                     translations, strings). Empty
		 *                                     when the dispatch asked for a
		 *                                     full export.
		 */
		// A listener that throws must not cost us the record of the file we
		// just wrote. Before this, an exception from a third-party
		// backup/audit integration propagated out of execute(), the job was
		// marked FAILED, and the result — including `path` — was never stored:
		// a complete export left on disk with nothing owning it, invisible to
		// the Jobs screen and to the download endpoint, removed only by the
		// 7-day age sweep. The export itself had already succeeded, so the
		// honest outcome is to keep the result and surface the listener's
		// failure alongside it.
		$hook_error = '';

		try {
			do_action( 'perflocale/export/written', $path, (int) $bytes, $sections );
		} catch ( \Throwable $e ) {
			$hook_error = $e->getMessage();

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExportJob: a perflocale/export/written listener threw - ' . $hook_error );
		}

		$result = [
			'path'  => $path,
			'bytes' => (int) $bytes,
		];

		// Record the file's IDENTITY, not just its name, so the cleanup in
		// JobState::delete_owned_artifact() can refuse to unlink a different
		// file that has since appeared at the same pathname. A path is a label
		// that anything can be moved to; an inode is the file itself.
		//
		// lstat(), not stat(): a symlink must report as the link, so a symlink
		// swapped in later cannot match what was recorded here.
		$identity = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file is an expected race, not an error worth surfacing.

		if ( is_array( $identity ) && isset( $identity['ino'], $identity['dev'] ) ) {
			// Both halves: an inode number is only unique within a device.
			$result['ino'] = (string) $identity['ino'];
			$result['dev'] = (string) $identity['dev'];
		}

		if ( $hook_error !== '' ) {
			// Truncated and kept out of `path`/`bytes` so existing consumers
			// of the result shape are unaffected.
			$result['hook_error'] = mb_substr( $hook_error, 0, 500 );
		}

		return $result;
	}

	/**
	 * Compose a default export path inside the uploads dir.
	 *
	 * @return string
	 */
	private static function default_export_path(): string {
		$dir = \PerfLocale\Helper::uploads_exports_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		\PerfLocale\Helper::harden_directory( $dir );

		// A 32-character CSPRN, doing two jobs.
		//
		// 1. Collision avoidance: two operators kicking off an export in
		// the same wall-clock second must land on distinct files, or the
		// second write corrupts the first's output. The per-job lock only
		// covers same-job-id concurrency; distinct dispatches from
		// different operators are otherwise unprotected at the filesystem
		// layer.
		// 2. ACCESS CONTROL. This file lands in wp-content/uploads, which is
		// web-served. Helper::harden_directory() drops a `Deny from all`
		// .htaccess beside it, but nginx, Caddy and Apache configured with
		// `AllowOverride None` ignore that file completely: an anonymous
		// GET of the exact URL returns 200 with the whole export body.
		// Until process_export_download() streams and unlinks it, the
		// filename IS the secret — and an export nobody downloads survives
		// until Helper::gc_stale_upload_files() sweeps it, 7 days later.
		//
		// It used to be 6 characters: 62^6 = ~5.7e10, brute-forceable, while
		// harden_directory()'s docblock justified the arrangement with 62^16
		// — a figure true only of the import temp names. 32 is what WP core
		// uses for the privacy exports it stores in this same public tree
		// (wp_privacy_generate_personal_data_export_file()). Do not shorten
		// it; nothing parses this filename, so length costs nothing.
		$suffix = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false, false )
			: bin2hex( random_bytes( 16 ) );

		return $dir . '/perflocale-export-' . gmdate( 'Y-m-d-His' ) . '-' . $suffix . '.json';
	}

	/**
	 * Same upload-path guard as {@see DataImportJob} but accepts paths
	 * that don't exist yet (writing creates them). Use `dirname()` for
	 * the realpath check so we validate the parent dir.
	 *
	 * @param string $file
	 * @param bool   $for_write Set true to allow not-yet-existing paths.
	 * @return bool
	 */
	private static function is_safe_upload_path( string $file, bool $for_write = false ): bool {
		$check = $for_write ? dirname( $file ) : $file;
		$real  = realpath( $check );

		if ( $real === false ) {
			return false;
		}

		$upload = wp_upload_dir();

		if ( empty( $upload['basedir'] ) ) {
			return false;
		}

		// Accept any path inside the uploads tree, not just
		// `uploads/perflocale/`; the realpath check below confines it.
		$uploads = realpath( (string) $upload['basedir'] );

		if ( $uploads === false ) {
			return false;
		}

		return str_starts_with( $real, rtrim( $uploads, '/\\' ) . DIRECTORY_SEPARATOR )
			|| $real === rtrim( $uploads, '/\\' );
	}
}
