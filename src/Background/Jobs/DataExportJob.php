<?php
/**
 * Background job for DataExporter.
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
 * Writes a full-site export to a file in uploads for download from PerfLocale > Jobs (the inline download streams).
 * Args: `sections` (section keys; empty = all), `addon_groups` (DataExporter::addon_groups() keys; absent = all when
 * Add-on data is chosen), `path` (optional, confined to the uploads tree; default: a random name in the exports dir).
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
	 * The export streams and small sites finish in under a second, so only a large one goes async.
	 */
	public function get_default_threshold(): int {
		return 5000;
	}

	/**
	 * Two hours: one call streams tens of thousands of rows, past the 30-minute default on the largest sites.
	 */
	public function get_lock_ttl(): int {
		return 2 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The rows of the tables the chosen sections export: a settings-only export counts 0.
	 */
	protected function args_size( array $args ): int {
		$sections = isset( $args['sections'] ) && is_array( $args['sections'] ) ? \PerfLocale\Admin\Typed::strs( $args['sections'] ) : [];
		$groups   = isset( $args['addon_groups'] ) && is_array( $args['addon_groups'] ) ? \PerfLocale\Admin\Typed::strs( $args['addon_groups'] ) : null;

		return ( new DataExporter() )->row_count( $sections, $groups );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the export path is outside wp-content/uploads/.
	 */
	public function execute( array $args, callable $progress ): array {
		$sections = isset( $args['sections'] ) && is_array( $args['sections'] ) ? $args['sections'] : [];
		$groups   = isset( $args['addon_groups'] ) && is_array( $args['addon_groups'] ) ? \PerfLocale\Admin\Typed::strs( $args['addon_groups'] ) : null;
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
		$bytes    = $exporter->write_to_file( $path, $sections, $groups );

		if ( $bytes === false ) {
			throw new \RuntimeException( esc_html( sprintf( 'Failed to write export to: %s', $path ) ) );
		}

		$progress( 1, 1 );

		/**
		 * Fires after the job wrote the export file, before the result is stored: for offsite backups, webhooks or
		 * audit logs. The file stays at $path until the Jobs screen's download serves and deletes it.
		 *
		 * @hook  perflocale/export/written
		 * @since 1.0.0
		 *
		 * @param string             $path     Absolute path to the written export file (inside uploads/).
		 * @param int                $bytes    Size of the written file.
		 * @param array<int,string>  $sections Exported section keys (DataExporter::sections()); empty: a full export.
		 */
		// A throwing listener must not lose the record of the written file (an orphan export only the 7-day sweep
		// removes): the result is kept and the listener's failure is reported beside it.
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

		// Record the file's identity, not only its name, so JobState::delete_owned_artifact() refuses to unlink another
		// file that later appears at this path. lstat(), not stat(): a symlink swapped in later cannot match.
		$identity = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file is an expected race, not an error worth surfacing.

		if ( is_array( $identity ) && isset( $identity['ino'], $identity['dev'] ) ) {
			// Both halves: an inode number is only unique within a device.
			$result['ino'] = (string) $identity['ino'];
			$result['dev'] = (string) $identity['dev'];
		}

		if ( $hook_error !== '' ) {
			// Truncated and apart from `path`/`bytes`, so consumers of the result shape are unaffected.
			$result['hook_error'] = mb_substr( $hook_error, 0, 500 );
		}

		return $result;
	}

	/**
	 * A default export path inside the uploads exports dir.
	 *
	 * @return string
	 */
	private static function default_export_path(): string {
		$dir = \PerfLocale\Helper::uploads_exports_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		\PerfLocale\Helper::harden_directory( $dir );

		// 32 random characters: two exports in one second never share a file, and the name is the secret (uploads is
		// web-served and not every server honours harden_directory()'s .htaccess), as for WP core's privacy exports.
		// Do not shorten it.
		$suffix = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false, false )
			: bin2hex( random_bytes( 16 ) );

		return $dir . '/perflocale-export-' . gmdate( 'Y-m-d-His' ) . '-' . $suffix . '.json';
	}

	/**
	 * DataImportJob's upload-path guard, but a path to write may not exist yet: realpath runs on its dirname().
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

		// Any path inside the uploads tree, not only `uploads/perflocale/`; the realpath check below confines it.
		$uploads = realpath( (string) $upload['basedir'] );

		if ( $uploads === false ) {
			return false;
		}

		return str_starts_with( $real, rtrim( $uploads, '/\\' ) . DIRECTORY_SEPARATOR )
			|| $real === rtrim( $uploads, '/\\' );
	}
}
