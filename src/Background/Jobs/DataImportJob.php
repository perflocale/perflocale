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
 *   - 'allow_foreign_ids' : bool  The operator ticked "this site is a copy of
 *                            the site the file was exported from" on the
 *                            import form, so DataImporter's site-identity gate
 *                            is skipped. Recorded in the args, not decided at
 *                            run time, because it is a statement about the
 *                            FILE the operator chose — a replay has to keep
 *                            the same answer.
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
 *
 * A bundle the importer refuses on the site-identity gate never becomes a
 * result: `execute()` throws instead, so the job ends `failed` with the
 * refusal sentence in its `error` column where both the Jobs list and the
 * sync dispatcher already show it.
 *
 * The pipeline has no terminal-failure signal, so the worker retries a
 * thrown job with backoff up to `perflocale/jobs/max_attempts` (5) — the
 * same shape the two path guards below already have. A refusal is
 * deterministic, so each retry re-reads the file, re-decodes it and refuses
 * again; DataImporter runs the site-identity gate AHEAD of its per-value
 * data-quality scan precisely so that those retries do not each walk the
 * whole envelope. Until the cap is reached the Jobs list shows the row as
 * queued with no reason, because it renders `error` only on a failed row.
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
	 * @throws \RuntimeException When the import path is unsafe or unreadable,
	 *                           or the importer refused the bundle before
	 *                           writing anything.
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
			[ 'allow_foreign_ids' => ! empty( $args['allow_foreign_ids'] ) ]
		);

		// A refusal that never reached the first write is a failure, not a
		// finished import.
		//
		// This throw exists for the ASYNC path only. Dispatch compares
		// self::args_size() — the file size divided by 200 — against
		// self::get_default_threshold() (1000), so a bundle under roughly
		// 200 KB runs INLINE, and there the refusal already surfaces without
		// help: Dispatcher::execute_inline returns the importer's array and
		// AdminController redirects on its `error`. Above the threshold the
		// operator is redirected to a job instead, and a job that RETURNED
		// the refusal would be marked complete — "0 imported", with the
		// reason readable only inside the result JSON in the detail drawer.
		// Throwing puts the sentence in the job row's `error`, which the Jobs
		// list renders beside the failed row.
		//
		// Keyed on the importer's own `refused` flag rather than on "zero
		// imported plus some errors": a replace that wiped its tables and
		// then failed every row ends that way too, and the worker's retry
		// would re-run that wipe. A site-identity refusal wrote nothing and
		// refuses identically on a replay, so re-running it costs a file
		// parse. The importer always pairs the flag with the sentence that
		// explains it.
		//
		// esc_html() is required here by WordPress.Security.EscapeOutput: an
		// exception message is treated as output. That makes this the one
		// import error that reaches the operator pre-escaped — every other one
		// is plain __() text — so the refusal sentences quote with typographic
		// marks, which survive the second escape the Jobs screen applies. An
		// `&` inside an address the FILE recorded still renders as `&amp;`;
		// cosmetic, and the address is the operator's own upload.
		if ( ! empty( $result['refused'] ) ) {
			throw new \RuntimeException( esc_html( (string) ( $result['errors'][0] ?? '' ) ) );
		}

		// Flush post-import caches — see MigrationCacheHelper for the
		// full sequence + rationale. Deliberately below the refusal above: a
		// refused import wrote nothing, and this regenerates every
		// translation file in files mode.
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
