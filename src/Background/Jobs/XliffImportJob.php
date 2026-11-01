<?php
/**
 * Background job for an XLIFF import.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Admin\Typed;
use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Background\JobCanceledException;
use PerfLocale\Helper;
use PerfLocale\Xliff\XliffFormatException;
use PerfLocale\Xliff\XliffImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a staged XLIFF file (args: `file`, the key stage() returned) in slices of whole posts, resuming after a kill
 * from the post it stopped at. The result is the import report: imported, skipped, errors, errors_more, posts.
 */
final class XliffImportJob extends AbstractJob {

	/**
	 * Wall-clock budget of one web slice, in seconds: margin under a 30 s limit.
	 */
	private const SLICE_SECONDS = 20;

	/**
	 * Most posts one web slice applies: a backstop for posts so fast that the clock never binds.
	 */
	private const SLICE_MAX_POSTS = 500;

	/**
	 * Most error messages a job result keeps; the rest are counted (the result must stay under 64 KB).
	 */
	private const MAX_ERRORS = 50;

	/**
	 * Cleanup of a staged file no worker action deletes (a failed job purged, a lost hook); cleanup().
	 */
	private const CLEANUP_HOOK = 'perflocale_cleanup_temp_import';

	/**
	 * Most XLIFF files staged at once on a site: queued, running, paused and failed imports keep theirs.
	 */
	public const MAX_STAGED_FILES = 20;

	/**
	 * Most bytes of XLIFF files staged at once on a site: four documents at XliffImporter::MAX_BYTES.
	 */
	public const MAX_STAGED_BYTES = 4 * XliffImporter::MAX_BYTES;

	/**
	 * Most staged files the quota filter may allow: each request lists the folder.
	 */
	private const STAGED_FILES_CEILING = 200;

	/**
	 * Most staged bytes the quota filter may allow, in documents at XliffImporter::MAX_BYTES (2 GB).
	 */
	private const STAGED_DOCUMENTS_CEILING = 64;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'xliff_import';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The REST route's capability.
	 */
	public function get_required_capability(): string {
		return 'perflocale_import_export';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Unused: the REST route always queues this job.
	 */
	public function get_default_threshold(): int {
		return 0;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A redone post writes nothing new: its translation exists, and a save it already holds is skipped.
	 */
	public function resumes_after_kill(): bool {
		return true;
	}

	/**
	 * Write a document XliffImporter::load() accepted to the hardened temp folder, with a cleanup event a day later.
	 *
	 * @param string $xml XLIFF document.
	 * @return string The file's key, the job's `file` arg.
	 * @throws \RuntimeException When the folder or the file cannot be written; code 429 when the site's staged files
	 *                           are at MAX_STAGED_FILES or MAX_STAGED_BYTES, 503 when the staging lock stays busy.
	 */
	public static function stage( string $xml ): string {
		$dir = Helper::uploads_temp_dir();

		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create temporary upload directory.', 'perflocale' ) );
		}

		Helper::harden_directory( $dir );

		// Counted and written under one lock: concurrent requests cannot pass the quota together.
		for ( $try = 0; $try < 20; $try++ ) {
			$key = \PerfLocale\Concurrency\Lock::with( 'xliff_stage', 30, static fn(): string => self::write( $dir, $xml ) );

			if ( is_string( $key ) ) {
				self::arm_cleanup( $key );

				return $key;
			}

			usleep( 100000 );
		}

		throw new \RuntimeException( esc_html__( 'XLIFF import refused: the server is busy storing another XLIFF file. Send the file again in a moment.', 'perflocale' ), 503 );
	}

	/**
	 * Write the staged file once the site's staged files leave room for it.
	 *
	 * @param string $dir Temp folder.
	 * @param string $xml XLIFF document.
	 * @return string The file's key.
	 * @throws \RuntimeException As stage().
	 */
	private static function write( string $dir, string $xml ): string {
		$quota = self::staging_quota();
		$files = 0;
		$bytes = strlen( $xml );

		foreach ( (array) scandir( $dir ) as $name ) {
			if ( 1 === preg_match( '/^xliff-[0-9a-f]{32}\.xlf$/', (string) $name ) ) {
				++$files;
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A worker may delete the file since scandir(); false counts 0.
				$bytes += (int) @filesize( $dir . '/' . $name );
			}
		}

		if ( $files >= $quota['files'] || $bytes > $quota['bytes'] ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: number of files, 2: a size, for example "128 MB". */
					esc_html__( 'XLIFF import refused: this site already holds XLIFF files waiting to be imported (at most %1$s files and %2$s at a time). Wait for queued imports to finish, cancel the ones you do not need, or delete failed ones on the Jobs screen, then send the file again.', 'perflocale' ),
					esc_html( number_format_i18n( $quota['files'] ) ),
					esc_html( (string) size_format( $quota['bytes'] ) )
				),
				429
			);
		}

		$key  = wp_generate_password( 32, false );
		$path = $dir . '/' . self::file_name( $key );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged -- 'x' never opens an existing file; WP_Filesystem has no exclusive create. Failures are handled.
		$handle  = @fopen( $path, 'xb' );
		$written = false !== $handle && strlen( $xml ) === fwrite( $handle, $xml );

		if ( false !== $handle ) {
			$written = fclose( $handle ) && $written;
		}
		// phpcs:enable

		if ( ! $written ) {
			if ( false !== $handle ) {
				wp_delete_file( $path );
			}

			throw new \RuntimeException( esc_html__( 'Could not stage the uploaded file: the server could not write a full copy of it (the disk may be full). Nothing was imported.', 'perflocale' ) );
		}

		return $key;
	}

	/**
	 * The staging limits as filtered, clamped: at least one file of the largest accepted size, at most the ceilings.
	 *
	 * @return array{files: int, bytes: int}
	 */
	public static function staging_quota(): array {
		$defaults = [
			'files' => self::MAX_STAGED_FILES,
			'bytes' => self::MAX_STAGED_BYTES,
		];

		/**
		 * Filters the limits on XLIFF files staged at once on a site by POST /xliff/import: a file past either is refused
		 * (429) before it is written. Queued, running, paused and failed imports keep their files.
		 *
		 * @hook  perflocale/import/xliff_staging_quota
		 * @since 1.0.7.5
		 *
		 * @param array{files: int, bytes: int} $quota Defaults: 20 files and 128 MB. Clamped to 1-200 files and to 32 MB
		 *                                             (one document at the size limit) to 2 GB.
		 */
		$filtered = Typed::arr( apply_filters( 'perflocale/import/xliff_staging_quota', $defaults ) );
		$ceiling  = (int) min( PHP_INT_MAX, self::STAGED_DOCUMENTS_CEILING * XliffImporter::MAX_BYTES );

		return [
			'files' => max( 1, min( self::STAGED_FILES_CEILING, Typed::int( $filtered['files'] ?? null, $defaults['files'] ) ) ),
			'bytes' => max( XliffImporter::MAX_BYTES, min( $ceiling, Typed::int( $filtered['bytes'] ?? null, $defaults['bytes'] ) ) ),
		];
	}

	/**
	 * Delete a canceled import's staged file: a cancel recorded outside a worker (REST, CLI) leaves no run to do it.
	 *
	 * @param array<mixed> $args The job's args.
	 * @return void
	 */
	public static function canceled( array $args ): void {
		if ( is_string( $args['file'] ?? null ) ) {
			self::discard( $args['file'] );
		}
	}

	/**
	 * Cleanup event: delete the file unless a queued, running or failed import holds its key (a paused queue, a late
	 * start, a Retry), which looks again a day later.
	 *
	 * @param string $key Key from stage().
	 * @return void
	 */
	public static function cleanup( string $key ): void {
		$live = self::live_keys();

		// A failed read keeps it: a live import's file is never deleted on a guess.
		if ( null === $live || isset( $live[ $key ] ) ) {
			if ( '' !== self::path_of( $key ) ) {
				self::arm_cleanup( $key );
			}

			return;
		}

		self::discard( $key );
	}

	/**
	 * The file names of this blog's queued, running and failed imports, as name => true, for the stale-upload sweep;
	 * null when the jobs could not be read.
	 *
	 * @return array<string, true>|null
	 */
	public static function live_file_names(): ?array {
		$live = self::live_keys();

		if ( null === $live ) {
			return null;
		}

		$names = [];

		foreach ( array_keys( $live ) as $key ) {
			$names[ self::file_name( (string) $key ) ] = true;
		}

		return $names;
	}

	/**
	 * The keys of this blog's queued, running and failed imports (a failed one keeps its file for Retry until the job is
	 * deleted or purged), as key => true; null when the read failed.
	 *
	 * @return array<string, true>|null
	 */
	private static function live_keys(): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The jobs table, read fresh by a daily cleanup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT args FROM %i WHERE type = %s AND status IN ('queued', 'running', 'failed') AND blog_id = %d", \PerfLocale\Database\Schema::table( 'jobs' ), 'xliff_import', get_current_blog_id() ), ARRAY_A );

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$keys = [];

		foreach ( $rows as $row ) {
			$args = is_string( $row['args'] ?? null ) ? json_decode( $row['args'], true ) : null;
			$key  = is_array( $args ) && is_string( $args['file'] ?? null ) ? $args['file'] : '';

			if ( self::is_key( $key ) ) {
				$keys[ $key ] = true;
			}
		}

		return $keys;
	}

	/**
	 * Whether a value has the form of a key stage() returns.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_key( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $value );
	}

	/**
	 * The staged file's name: derived from the key with a site secret, so no job arg or event names it (where
	 * .htaccess is ignored, the unguessable name is what keeps the file from HTTP).
	 *
	 * @param string $key Key from stage().
	 * @return string
	 */
	private static function file_name( string $key ): string {
		return 'xliff-' . substr( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), 0, 32 ) . '.xlf';
	}

	/**
	 * Delete a staged file and its cleanup event.
	 *
	 * @param string $key The job's `file` arg.
	 * @return void
	 */
	public static function discard( string $key ): void {
		$path = self::path_of( $key );

		if ( '' === $path ) {
			return;
		}

		wp_delete_file( $path );
		BackgroundEvents::unschedule( self::CLEANUP_HOOK, [ $key ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Applies whole posts until the slice budget runs out (none under WP-CLI); deletes the file once the result is
	 * stored, refused or canceled.
	 *
	 * @param array<string, mixed> $args     `file`, and the worker's slice.
	 * @param callable             $progress `function(int, int, array): void`, before each post with its checkpoint.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, callable $progress ): array {
		$key   = is_string( $args['file'] ?? null ) ? $args['file'] : '';
		$path  = self::path_of( $key );
		$slice = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : null;
		$from  = null !== $slice && is_array( $slice['resume'] ?? null ) ? \PerfLocale\Background\JobResume::keyed( $slice['resume'] ) : [];

		if ( '' === $path ) {
			return self::refused( $from, __( 'The XLIFF file of this import is no longer on the server, so nothing more was imported. Import the file again.', 'perflocale' ) );
		}

		$importer = new XliffImporter();

		try {
			// One byte over the limit is enough for load() to refuse it.
			[ $dom, $target_slug ] = $importer->load( (string) file_get_contents( $path, false, null, 0, XliffImporter::MAX_BYTES + 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the hardened temp folder.
		} catch ( XliffFormatException $e ) {
			self::discard( $key );

			return self::refused( $from, $e->getMessage() );
		}

		$web   = null !== $slice && 'cli' !== PHP_SAPI;
		$began = microtime( true );
		$ran   = 0;
		$last  = $from;

		/**
		 * Before each post: stop once the slice ran one and spent its budget, else store the checkpoint (a cancel stops
		 * here).
		 *
		 * @param array<string, mixed> $state     The state before this post.
		 * @param int                  $source_id The post.
		 */
		$before_post = static function ( array $state, int $source_id ) use ( $web, $began, &$ran, &$last, $progress, $target_slug ): bool {
			if ( $web && $ran > 0 && ( $ran >= self::SLICE_MAX_POSTS || microtime( true ) - $began >= self::SLICE_SECONDS ) ) {
				return true;
			}

			$last = $state;

			$progress(
				$state['done'],
				$state['posts'],
				[
					'checkpoint' => $state,
					/* translators: 1: post id, 2: target language slug. Names the item a background job was on. */
					'unit'       => sprintf( __( 'post %1$d into %2$s', 'perflocale' ), $source_id, $target_slug ),
				]
			);

			++$ran;

			return false;
		};

		try {
			// No titles: supervisors read other users' job results.
			$state = $importer->apply( $dom, $target_slug, $from, $before_post, self::MAX_ERRORS, false );
		} catch ( JobCanceledException $e ) {
			// Only a cancel deletes the file (the worker fires the action then); a pause or a takeover keeps it.
			self::discard_on( 'perflocale/jobs/canceled', $key );
			$e->set_result( \PerfLocale\Background\JobResume::keyed( $last ) + [ '__perflocale_checkpoint' => true ] );

			throw $e;
		}

		unset( $dom );

		if ( null !== $slice ) {
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		if ( $state['done'] < $state['posts'] ) {
			// A day after this slice, so a long import keeps its file.
			self::arm_cleanup( $key );

			return $state + [ '__perflocale_continue' => true ];
		}

		// After the completion is stored: a run that ends before that resumes from the file.
		self::discard_on( 'perflocale/jobs/completed', $key );

		return self::report( $state );
	}

	/**
	 * The report fields of a state.
	 *
	 * @param array<string, mixed> $state State from XliffImporter::apply().
	 * @return array{imported: int, skipped: int, errors: array<int, string>, errors_more: int, posts: int}
	 */
	private static function report( array $state ): array {
		return [
			'imported'    => Typed::int( $state['imported'] ?? 0 ),
			'skipped'     => Typed::int( $state['skipped'] ?? 0 ),
			'errors'      => Typed::strs( $state['errors'] ?? [] ),
			'errors_more' => Typed::int( $state['errors_more'] ?? 0 ),
			'posts'       => Typed::int( $state['posts'] ?? 0 ),
		];
	}

	/**
	 * A failed run (never retried automatically): the report so far plus the reason.
	 *
	 * @param array<string, mixed> $from    The stored state, or [].
	 * @param string               $message Why.
	 * @return array<string, mixed>
	 */
	private static function refused( array $from, string $message ): array {
		$report             = self::report( $from );
		$report['errors'][] = $message;

		return $report + [
			'run_failed'  => true,
			'first_error' => $message,
		];
	}

	/**
	 * The staged file of a key, or '' when the value is not a key or no plain file has its name (job args are replayed
	 * from the database).
	 *
	 * @param string $key The job's `file` arg.
	 * @return string
	 */
	private static function path_of( string $key ): string {
		$temp = Helper::uploads_temp_dir();

		if ( '' === $temp || ! self::is_key( $key ) ) {
			return '';
		}

		$dir = realpath( $temp );

		if ( false === $dir ) {
			return '';
		}

		$path = $dir . DIRECTORY_SEPARATOR . self::file_name( $key );

		return is_file( $path ) && ! is_link( $path ) ? $path : '';
	}

	/**
	 * (Re)schedule the cleanup of a staged file a day from now.
	 *
	 * @param string $key Key from stage().
	 * @return void
	 */
	private static function arm_cleanup( string $key ): void {
		BackgroundEvents::unschedule( self::CLEANUP_HOOK, [ $key ] );
		BackgroundEvents::enqueue( self::CLEANUP_HOOK, [ $key ], DAY_IN_SECONDS );
	}

	/**
	 * Delete the staged file when the worker fires $hook (canceled, completed) for this job.
	 *
	 * @param string $hook Worker action.
	 * @param string $key  The job's `file` arg.
	 * @return void
	 */
	private static function discard_on( string $hook, string $key ): void {
		$listener = static function ( $job_id, $type ) use ( $hook, $key, &$listener ): void {
			if ( 'xliff_import' !== $type || ! is_string( $job_id ) ) {
				return;
			}

			// This job's own file only: a listener a pause or a refused write left behind must not take another job's.
			$args = \PerfLocale\Background\JobState::args( [ $job_id ] )[ $job_id ] ?? [];

			if ( ( $args['file'] ?? null ) === $key ) {
				remove_action( $hook, $listener );
				self::discard( $key );
			}
		};

		add_action( $hook, $listener, 10, 2 );
	}
}
