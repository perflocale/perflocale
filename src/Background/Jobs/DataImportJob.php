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
 * The job is a pure wrapper around {@see DataImporter::import()} -
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
 *                            Must be inside `wp-content/uploads/` -
 *                            validated below.
 *   - 'replace'   : bool    Whether to TRUNCATE-then-restore (true) or
 *                            merge into existing rows (false).
 *   - 'allow_foreign_ids' : bool  The operator ticked "this site is a copy of
 *                            the site the file was exported from" on the
 *                            import form, so DataImporter's site-identity gate
 *                            is skipped. Recorded in the args, not decided at
 *                            run time, because it is a statement about the
 *                            FILE the operator chose - a replay has to keep
 *                            the same answer.
 *   - 'addon_choices' : string[]  The add-on data the operator ticked on the
 *                            import form (DataImporter::import() option
 *                            `addon_choices`). Absent = everything the file
 *                            carries.
 *
 * Result shape (matches DataImporter::import()):
 *   - 'imported'  : int      Rows successfully imported.
 *   - 'skipped'   : int      Rows skipped (e.g. duplicates, validation fail).
 *   - 'errors'    : string[] Per-row errors, capped by DataImporter.
 *   - 'sanitized' : int      Stored string translation, language and
 *                            translated-slug rows the import's sanitizers
 *                            changed because the dispatching user lacks
 *                            `unfiltered_html`.
 *   - 'not_applied', 'notice' : after a merge of a file that carries
 *                            configuration, the parts left unapplied
 *                            (settings, add-on settings, the add-on list,
 *                            roles) and the sentence that says so; the job's
 *                            details on PerfLocale → Jobs show both.
 *   - 'refused_addresses', 'address_notice' : the provider address settings
 *                            the settings update refused (multisite, key
 *                            supplied from outside the database, a dispatching
 *                            user who is not a super admin) and the sentence
 *                            that names them; shown in the job's details
 *                            (PerfLocale → Jobs, `wp perflocale jobs get`).
 *   - 'run_failed', 'first_error' : set when the importer refused the file
 *                            before its first write (`refused`), or wrote no
 *                            row and reported errors. The worker then records
 *                            the job as failed with `first_error` as its
 *                            reason (shown on PerfLocale → Jobs) and does not
 *                            re-run it automatically.
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
	 * Matches the cap the admin import form and CLI command require -
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
	 * conservative - over-counts compared to actually parsing the JSON,
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
	 * @param callable             $progress Called ONCE, before the import:
	 *                                       DataImporter does not expose
	 *                                       per-table progress callbacks.
	 *
	 *   The progress callback is the only place the worker probes for an
	 *   operator cancel or a queue pause, so it is not called again once the
	 *   import has run: every row is committed by then, and a cancel or pause
	 *   seen there would record a finished import as canceled with no result,
	 *   or re-queue it to run a second time. A cancel that arrives while the
	 *   import runs is reported by the worker as one that came after the work
	 *   had finished; a pause takes effect for the jobs after this one. Cancel
	 *   stops a QUEUED import (WorkerRegistry bails on a terminal status
	 *   before execute()), and the tick before the import stops one that was
	 *   canceled or paused while it started. `get_lock_ttl()` is 4h because no
	 *   tick refreshes the lock during the import.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the import path is unsafe or unreadable.
	 */
	public function execute( array $args, callable $progress ): array {
		$file    = isset( $args['file_path'] ) ? (string) $args['file_path'] : '';
		$replace = ! empty( $args['replace'] );

		// Path traversal guard - the dispatching user already has the
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

		// String translations, languages and translated slugs in the file are
		// stored at the trust level of the user who asked for the import, and
		// that user is the current user on both paths:
		// Dispatcher::execute_inline() runs inside their request,
		// and WorkerRegistry::run_on_current_blog() calls wp_set_current_user()
		// with the job's `created_by` before execute(). Decided at run time
		// rather than from a flag in the stored args, so nothing in the args
		// can change the answer. WP-CLI calls DataImporter directly, at the
		// shell-access trust level.
		$sanitize_strings = ! current_user_can( 'unfiltered_html' );

		$importer = new DataImporter();
		$result   = $importer->import(
			$file,
			$replace,
			$sanitize_strings,
			// ! empty(): JobState round-trips args through JSON, so a ticked
			// checkbox comes back as true, 1 or '1' depending on the path.
			[
				'allow_foreign_ids' => ! empty( $args['allow_foreign_ids'] ),
				'addon_choices'     => isset( $args['addon_choices'] ) && is_array( $args['addon_choices'] ) ? \PerfLocale\Admin\Typed::strs( $args['addon_choices'] ) : null,
			]
		);

		// An import that was refused before its first write, or that wrote
		// no row and reported errors, is a failed run: the worker records the
		// job as failed with the first error as its reason, which the Jobs
		// list shows beside the row, and keeps the whole result for the
		// details. A failed run is not re-run automatically: a refusal is
		// deterministic, and a replace that removed rows and then stored none
		// must not run again without the operator. The inline path reads the
		// same `errors` and shows them on the import screen.
		$first = trim( $result['errors'][0] ?? '' );

		if ( '' !== $first && ( ! empty( $result['refused'] ) || 0 === $result['imported'] ) ) {
			$result['run_failed']  = true;
			$result['first_error'] = $first;
		}

		// DataImporter::import() ends with the post-import cache flush (and,
		// in files mode, the translation file regeneration) after its string
		// repair, so nothing is flushed or regenerated a second time here.
		// No progress tick follows the import (see the doc above).

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
