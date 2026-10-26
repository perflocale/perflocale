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
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'data_import';
	}

	/**
	 * {@inheritDoc}
	 *
	 * `perflocale_import_export`, as the import form and the CLI command.
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
}
