<?php
/**
 * Upload of a data-import file in pieces.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives a Settings > Export & Import file in pieces (assets/js/admin-settings.js), so a file over PHP's upload
 * limits can be imported: staged per user in the hardened temp directory, in order, never past the announced size,
 * under per-user and per-site quotas. Docs: docs-ai/11-code-rationale.md, "ImportUpload: staged pieces and quotas".
 */
final class ImportUpload {

	/**
	 * Admin-ajax action.
	 */
	public const ACTION = 'perflocale_import_upload';

	/**
	 * Nonce action (the import form's own).
	 */
	public const NONCE = 'perflocale_import_data';

	/**
	 * Token shape: user ID, announced size, random part.
	 */
	private const TOKEN_PATTERN = '/^u([0-9]+)-s([0-9]+)-([A-Za-z0-9]{24})$/';

	/**
	 * Largest piece, in bytes.
	 */
	private const MAX_PIECE = 8388608;

	/**
	 * Option of the uploads in progress: token => user id, announced bytes, reservation expiry.
	 */
	public const QUOTA_OPTION = 'perflocale_import_uploads';

	/**
	 * Lock (Concurrency\Lock) under which reservations change.
	 */
	private const QUOTA_LOCK = 'import_uploads';

	/**
	 * Orders the count of staged import files with the staging of a new one.
	 */
	private const STAGE_LOCK = 'import_stage';

	/**
	 * Seconds a reservation lasts without a piece.
	 */
	private const IDLE_SECONDS = 3600;

	/**
	 * Key of the stamp a damaged reservation row is replaced with.
	 */
	private const DAMAGED_SINCE = 'damaged_since';

	/**
	 * What read_stored() found.
	 */
	private const STORED_ABSENT  = 0;
	private const STORED_VALID   = 1;
	private const STORED_INVALID = 2;
	private const STORED_FAILED  = 3;

	/**
	 * Answers of with_reservations() when the reservations were not changed.
	 */
	private const UNREADABLE = -1;
	private const DAMAGED    = -2;
	private const UNSAVED    = -3;

	/**
	 * Smallest piece, in bytes; the browser halves a piece refused as too large down to this.
	 */
	public const MIN_PIECE = 262144;

	/**
	 * What append_piece() did with a piece.
	 */
	private const APPENDED     = 0;
	private const OUT_OF_ORDER = 1;
	private const BAD_SIZE     = 2;
	private const WRITE_FAILED = 3;

	/**
	 * Bytes per piece: three quarters of the smaller PHP upload limit, or the filtered size, within 256 KB to 8 MB.
	 *
	 * @return int
	 */
	public static function piece_bytes(): int {
		$limit = AdminController::max_upload_bytes();

		// Room for the multipart envelope and other fields; without a known PHP limit, the smallest piece.
		$cap   = $limit > 0 ? (int) floor( $limit * 0.75 ) : self::MIN_PIECE;
		$bytes = max( self::MIN_PIECE, min( self::MAX_PIECE, $cap ) );

		/**
		 * Filters the piece size in which the Export & Import screen sends an import file too large for one request: lower
		 * it when a proxy or firewall refuses smaller requests than PHP does. Kept within 256 KB to 8 MB and at most three
		 * quarters of the smaller of upload_max_filesize and post_max_size.
		 *
		 * @hook  perflocale/import/upload_piece_bytes
		 * @since 1.0.7.3
		 *
		 * @param int $bytes Default: three quarters of the smaller of PHP's
		 *                   upload limits, between 256 KB and 8 MB.
		 */
		$filtered = (int) apply_filters( 'perflocale/import/upload_piece_bytes', $bytes );

		return max( self::MIN_PIECE, min( self::MAX_PIECE, $cap, $filtered ) );
	}

	/**
	 * Handle one upload request (`step`: start, piece or abort).
	 *
	 * @return void Sends JSON and ends the request.
	 */
	public static function handle_ajax(): void {
		check_ajax_referer( self::NONCE, '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'perflocale_import_export' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'perflocale' ) ], 403 );
		}

		$step = isset( $_POST['step'] ) && is_string( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		switch ( $step ) {
			case 'start':
				self::start();
				break;
			case 'piece':
				self::piece();
				break;
			case 'abort':
				self::abort();
				break;
			default:
				wp_send_json_error( [ 'message' => __( 'Unknown upload step.', 'perflocale' ) ], 400 );
		}
	}

	/**
	 * Start an upload: check the announced size, create the empty file, schedule its cleanup.
	 *
	 * @return void
	 */
	private static function start(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_ajax().
		$size = isset( $_POST['size'] ) && is_numeric( $_POST['size'] ) ? (int) $_POST['size'] : 0;
		$max  = AdminController::import_max_bytes();

		if ( $size <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'No file was received. Choose a file and submit the form again.', 'perflocale' ) ], 400 );
		}

		if ( $size > $max ) {
			wp_send_json_error( [ 'message' => self::too_large_message() ], 413 );
		}

		// Before any piece: a file the import request cannot finish is refused now, not after the upload.
		$too_slow = \PerfLocale\Background\WebLimit::units_refusal( 'data_import', intdiv( $size, 200 ), 'wp perflocale import <file>' );

		if ( '' !== $too_slow ) {
			wp_send_json_error( [ 'message' => $too_slow ], 413 );
		}

		$queued = self::import_queued_refusal();

		if ( '' !== $queued ) {
			wp_send_json_error( [ 'message' => $queued ], 409 );
		}

		$dir = \PerfLocale\Helper::uploads_temp_dir();

		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not create temporary upload directory.', 'perflocale' ) ], 500 );
		}

		\PerfLocale\Helper::harden_directory( $dir );

		// Refuse up front when the disk cannot hold the file and its import; with disk_free_space() disabled the pieces'
		// write checks report a full disk.
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled or failing on some hosts; false is handled.

		if ( is_numeric( $free ) && (float) $free < $size * 1.1 ) {
			wp_send_json_error( [ 'message' => __( 'There is not enough free disk space on the server for this file. Nothing was uploaded.', 'perflocale' ) ], 507 );
		}

		$space = self::space_refusal( $size );

		if ( '' !== $space ) {
			wp_send_json_error( [ 'message' => $space ], 507 );
		}

		$token = 'u' . get_current_user_id() . '-s' . $size . '-' . wp_generate_password( 24, false, false );
		$path  = self::path_for( $token );

		$refusal = self::reserve( $token, get_current_user_id(), $size );

		if ( '' !== $refusal[1] ) {
			wp_send_json_error( [ 'message' => $refusal[1] ], $refusal[0] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'xb' refuses an existing node; WP_Filesystem cannot.
		$handle = fopen( $path, 'xb' );

		if ( ! $handle ) {
			self::release( $token );
			wp_send_json_error( [ 'message' => __( 'Could not stage uploaded file.', 'perflocale' ) ], 500 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.
		fclose( $handle );

		// A never-finished upload is deleted by this event, as a one-request upload is.
		\PerfLocale\Background\BackgroundEvents::enqueue( 'perflocale_cleanup_temp_import', [ $path ], DAY_IN_SECONDS );

		wp_send_json_success(
			[
				'token' => $token,
				'piece' => self::piece_bytes(),
			]
		);
	}

	/**
	 * Receive one piece (append_piece() has the offset and size rules).
	 *
	 * @return void
	 */
	private static function piece(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle_ajax().
		$token  = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) && is_numeric( $_POST['offset'] ) ? (int) $_POST['offset'] : -1;
		$file   = Typed::map( $_FILES['piece'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read with type checks below; tmp_name is a server-controlled path, checked with is_uploaded_file().
		$tmp    = Typed::str( $file['tmp_name'] ?? '' );
		$error  = Typed::int( $file['error'] ?? null, UPLOAD_ERR_NO_FILE );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$size = self::announced_size( $token );
		$path = $size > 0 ? self::path_for( $token ) : '';

		if ( '' === $path || ! is_file( $path ) ) {
			self::fail( '', __( 'The upload has expired or does not belong to you. Choose the file and import it again.', 'perflocale' ), 404 );
			return;
		}

		// Each piece needs and extends the reservation; a busy quota lock is not an expiry: retryable, file kept.
		$renewed = self::renew( $token );

		if ( null === $renewed ) {
			wp_send_json_error( [ 'message' => self::busy_message() ], 503 );
		}

		if ( ! $renewed ) {
			self::fail( $path, __( 'The upload has expired or does not belong to you. Choose the file and import it again.', 'perflocale' ), 404 );
			return;
		}

		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::fail( $path, UPLOAD_ERR_OK !== $error ? AdminController::upload_error_message( $error ) : __( 'Invalid file upload.', 'perflocale' ), 400 );
			return;
		}

		$status = self::append_piece( $path, $tmp, $offset, $size );

		wp_delete_file( $tmp );

		if ( self::OUT_OF_ORDER === $status ) {
			self::fail( $path, __( 'A piece of the file arrived out of order, so the upload was stopped. Import the file again.', 'perflocale' ), 409 );
			return;
		}

		if ( self::BAD_SIZE === $status ) {
			self::fail( $path, __( 'A piece of the file did not match the size announced for it, so the upload was stopped. Import the file again.', 'perflocale' ), 400 );
			return;
		}

		if ( self::APPENDED !== $status ) {
			self::fail( $path, __( 'Could not stage the uploaded file: the server could not write a full copy of it (the disk may be full). Nothing was imported.', 'perflocale' ), 507 );
			return;
		}

		clearstatcache( true, $path );
		self::clean_space_cache();

		wp_send_json_success( [ 'received' => (int) filesize( $path ) ] );
	}

	/**
	 * Write a piece at its offset, at or before the file's end and never past the announced size; staged bytes are
	 * kept, so a retry must repeat them. The size is read under the lock, so two requests never both append.
	 *
	 * @param string $path   Staged file.
	 * @param string $tmp    The piece.
	 * @param int    $offset Where the piece starts, as the browser sent it.
	 * @param int    $size   The upload's announced size.
	 * @return int APPENDED, OUT_OF_ORDER, BAD_SIZE or WRITE_FAILED.
	 */
	private static function append_piece( string $path, string $tmp, int $offset, int $size ): int {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming, locked append into the hardened temp directory; WP_Filesystem has no locks or streams. Open failures are handled.
		$bytes = (int) @filesize( $tmp );

		// r+b: a file another request has removed is not created again.
		$out = @fopen( $path, 'r+b' );

		if ( ! $out ) {
			return self::WRITE_FAILED;
		}

		flock( $out, LOCK_EX );

		$stat = fstat( $out );
		$have = is_array( $stat ) ? (int) $stat['size'] : -1;

		// The path must still name the opened file: claim() may have moved it while this piece waited.
		clearstatcache( true, $path );
		$now    = @stat( $path );
		$status = self::APPENDED;

		if ( ! is_array( $stat ) || ! is_array( $now ) || $now['ino'] !== $stat['ino'] || $now['dev'] !== $stat['dev'] ) {
			$status = self::WRITE_FAILED;
		} elseif ( $offset < 0 || $offset > $have ) {
			$status = self::OUT_OF_ORDER;
		} elseif ( $bytes <= 0 || $bytes > self::MAX_PIECE || $offset + $bytes > $size ) {
			$status = self::BAD_SIZE;
		}

		$in = self::APPENDED === $status ? @fopen( $tmp, 'rb' ) : false;

		// A retry (cut off mid-append, or its answer lost): only the bytes past the file's end are written.
		if ( false !== $in && $offset < $have && ! self::repeats( $out, $in, $offset, min( $bytes, $have - $offset ) ) ) {
			fclose( $in );
			$in     = false;
			$status = self::OUT_OF_ORDER;
		}

		if ( false !== $in ) {
			$ok = 0 === fseek( $out, 0, SEEK_END );

			while ( $ok && ! feof( $in ) ) {
				$chunk = fread( $in, 65536 );

				if ( false === $chunk || strlen( $chunk ) !== fwrite( $out, $chunk ) ) {
					$ok = false;
				}
			}

			fclose( $in );

			$status = $ok && fflush( $out ) ? self::APPENDED : self::WRITE_FAILED;
		} elseif ( self::APPENDED === $status ) {
			$status = self::WRITE_FAILED;
		}

		flock( $out, LOCK_UN );

		if ( ! fclose( $out ) && self::APPENDED === $status ) {
			$status = self::WRITE_FAILED;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged

		return $status;
	}

	/**
	 * Whether the next $len bytes of the piece equal the staged bytes at $offset.
	 *
	 * @param resource $out    Staged file, locked.
	 * @param resource $in     The piece, at its start; left after the compared bytes.
	 * @param int      $offset Where the piece starts.
	 * @param int      $len    Bytes to compare.
	 * @return bool
	 */
	private static function repeats( $out, $in, int $offset, int $len ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Locked staged file; see append_piece().
		if ( 0 !== fseek( $out, $offset ) ) {
			return false;
		}

		while ( $len > 0 ) {
			$n     = min( 65536, $len );
			$piece = fread( $in, $n );

			if ( false === $piece || strlen( $piece ) !== $n || fread( $out, $n ) !== $piece ) {
				return false;
			}

			$len -= $n;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fread

		return true;
	}

	/**
	 * Delete an unfinished upload.
	 *
	 * @return void
	 */
	private static function abort(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_ajax().
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( self::announced_size( $token ) > 0 ) {
			self::discard( self::path_for( $token ) );
		}

		wp_send_json_success();
	}

	/**
	 * The assembled file of the current user's finished upload, for the import form; '' when the token is not theirs,
	 * the file is gone or it is short of the announced size.
	 *
	 * @param string $token Upload token from the form.
	 * @return string Absolute path.
	 */
	public static function complete_path( string $token ): string {
		$size = self::announced_size( $token );

		if ( $size <= 0 || $size > AdminController::import_max_bytes() ) {
			return '';
		}

		$path = self::path_for( $token );

		clearstatcache( true, $path );

		return is_file( $path ) && (int) filesize( $path ) === $size ? $path : '';
	}

	/**
	 * Take a finished upload's file for an import: as complete_path(), with the reservation released and the file moved
	 * off the upload's path (take()) under stage_within_quota(); '' when the reservation has expired or $refused is set.
	 *
	 * @param string $token   Upload token from the form.
	 * @param string $refused Set to the refusal: the upload is kept, or deleted when the site's upload space is full.
	 * @return string|null Absolute path; null when the quota lock stayed busy
	 *                     (the upload is kept).
	 */
	public static function claim( string $token, string &$refused = '' ): ?string {
		$path = self::complete_path( $token );

		if ( '' === $path ) {
			return '';
		}

		// The file already counts in the site's upload space; over it, the upload is deleted, or it would keep holding it.
		$refused = self::space_refusal( 0 );

		if ( '' !== $refused ) {
			self::discard( $path );

			return '';
		}

		$size    = self::announced_size( $token );
		$taken   = '';
		$refused = self::stage_within_quota(
			$size,
			static function () use ( $token, $path, $size, &$taken ): bool {
				$released = self::release( $token );
				$taken    = null === $released ? null : ( $released ? self::take( $path, $size ) : '' );

				return true;
			},
			$size
		);

		return '' === $refused ? $taken : '';
	}

	/**
	 * Run $stage under STAGE_LOCK when a web import may stage $bytes more: no data import of this blog is queued or
	 * running, the staged import files stay within quota()'s site limits, and on multisite within the site's upload
	 * space. A failed read refuses.
	 *
	 * @param int              $bytes   Size of the file to stage.
	 * @param callable(): bool $stage   Stages it; false when it could not.
	 * @param int              $on_disk Bytes of it already on disk (a finished chunked upload).
	 * @return string '' when staged, else the reason for the screen.
	 */
	public static function stage_within_quota( int $bytes, callable $stage, int $on_disk = 0 ): string {
		for ( $try = 0; $try < 50; $try++ ) {
			$answer = \PerfLocale\Concurrency\Lock::with( self::STAGE_LOCK, 30, static fn(): string => self::stage_now( $bytes, $stage, $on_disk ) );

			if ( is_string( $answer ) ) {
				return $answer;
			}

			usleep( 100000 );
		}

		return self::busy_message();
	}

	/**
	 * Run $dispatch (queueing a web import) under STAGE_LOCK after checking again that no data import of this blog is
	 * queued or running: the staging check alone leaves a window until the job exists. A busy lock or failed read refuses.
	 *
	 * @param callable(): array{mode: string, job_id?: string, duplicate?: bool, status?: string, updated_at?: int, replaced?: string, result?: array<string,mixed>, error?: string} $dispatch Dispatcher::dispatch() of the import.
	 * @return array{mode: string, job_id?: string, duplicate?: bool, status?: string, updated_at?: int, replaced?: string, result?: array<string,mixed>, error?: string} Its result, or mode `refused` with the reason in `error`.
	 */
	public static function dispatch_alone( callable $dispatch ): array {
		for ( $try = 0; $try < 50; $try++ ) {
			if ( ! \PerfLocale\Concurrency\Lock::acquire( self::STAGE_LOCK, 30 ) ) {
				usleep( 100000 );
				continue;
			}

			try {
				$queued = self::import_queued_refusal();

				return '' !== $queued ? [
					'mode'  => 'refused',
					'error' => $queued,
				] : $dispatch();
			} finally {
				\PerfLocale\Concurrency\Lock::release( self::STAGE_LOCK );
			}
		}

		return [
			'mode'  => 'refused',
			'error' => self::busy_message(),
		];
	}

	/**
	 * The body of stage_within_quota(), under its lock.
	 *
	 * @param int              $bytes   Size of the file to stage.
	 * @param callable(): bool $stage   Stages it.
	 * @param int              $on_disk Bytes of it already on disk.
	 * @return string
	 */
	private static function stage_now( int $bytes, callable $stage, int $on_disk ): string {
		$queued = self::import_queued_refusal();

		if ( '' !== $queued ) {
			return $queued;
		}

		$staged = self::staged_totals();

		if ( null === $staged ) {
			return __( 'Import refused: the server could not read its folder of import files. Import the file again in a moment.', 'perflocale' );
		}

		$quota = self::quota();

		if ( $staged[0] + 1 > $quota['site_uploads'] || $staged[1] + $bytes > $quota['site_bytes'] ) {
			return sprintf(
				/* translators: 1: number of files, 2: a size, for example "2 GB". */
				__( 'Import refused: this site already holds import files that wait to be imported or are kept for Retry (at most %1$d files and %2$s at a time). Wait for queued imports to finish, or an hour for the file of a failed import to be removed, then import the file again.', 'perflocale' ),
				$quota['site_uploads'],
				AdminController::format_bytes( $quota['site_bytes'] )
			);
		}

		$space = self::space_refusal( $bytes - $on_disk );

		if ( '' !== $space ) {
			return $space;
		}

		if ( ! $stage() ) {
			return __( 'Could not stage uploaded file.', 'perflocale' );
		}

		self::clean_space_cache();

		return '';
	}

	/**
	 * On multisite with the upload space check on, why $extra more bytes do not fit in this site's upload space
	 * (core's get_space_allowed(), get_space_used(): staged files count, as media do); '' otherwise. A failed size read
	 * refuses.
	 *
	 * @param int $extra Bytes to add.
	 * @return string
	 */
	private static function space_refusal( int $extra ): string {
		if ( ! is_multisite() || get_site_option( 'upload_space_check_disabled' ) ) {
			return '';
		}

		self::clean_space_cache();

		$base = Typed::str( wp_upload_dir()['basedir'] );

		// get_space_used() counts a failed read as 0 bytes; only its own filter may answer without reading.
		if ( ! has_filter( 'pre_get_space_used' ) && ( '' === $base || ! is_int( get_dirsize( $base ) ) ) ) {
			return __( 'Import refused: the server could not measure this site\'s upload space. Import the file again in a moment.', 'perflocale' );
		}

		$allowed = max( 0, (float) get_space_allowed() ) * MB_IN_BYTES;
		$used    = (float) get_space_used() * MB_IN_BYTES;

		if ( $used + $extra <= $allowed ) {
			return '';
		}

		return sprintf(
			/* translators: 1: this site's upload space, for example "100 MB", 2: the space in use. "wp perflocale import" is a command and stays in English. */
			__( 'Import refused: this site\'s upload space (%1$s, %2$s in use) cannot hold this file. Free up space in the Media Library, ask the network administrator for more upload space, or import the file with WP-CLI: wp perflocale import <file>.', 'perflocale' ),
			AdminController::format_bytes( $allowed ),
			AdminController::format_bytes( $used )
		);
	}

	/**
	 * Drop core's cached size of the temp folder and its parents (get_dirsize()), so staged import files count at once.
	 *
	 * @return void
	 */
	private static function clean_space_cache(): void {
		$dir = \PerfLocale\Helper::uploads_temp_dir();

		if ( is_multisite() && '' !== $dir && function_exists( 'clean_dirsize_cache' ) ) {
			clean_dirsize_cache( $dir );
		}
	}

	/**
	 * Create $path for writing at its full $size, so the next staging count sees it before the copy ends.
	 *
	 * @param string $path File to create ('xb': never an existing one).
	 * @param int    $size Bytes it will hold.
	 * @return resource|false Handle at offset 0; false (nothing left behind) when it could not be created.
	 */
	public static function open_reserved( string $path, int $size ) {
		if ( $size < 0 ) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create; WP_Filesystem cannot. Failures are handled.
		$handle = @fopen( $path, 'xb' );

		if ( false === $handle ) {
			return false;
		}

		if ( ! ftruncate( $handle, $size ) ) {
			fclose( $handle );
			wp_delete_file( $path );

			return false;
		}
		// phpcs:enable

		return $handle;
	}

	/**
	 * Why a new web import may not start now: a data import of this blog is queued or running, or the jobs could not
	 * be read; '' otherwise.
	 *
	 * @return string
	 */
	private static function import_queued_refusal(): string {
		$live = \PerfLocale\Background\Jobs\DataImportJob::any_live();

		if ( null === $live ) {
			return __( 'Import refused: the list of import jobs could not be read. Import the file again in a moment.', 'perflocale' );
		}

		return $live ? __( 'Import refused: another import of this site is queued or running. Wait for it to finish, or cancel it on the PerfLocale Jobs screen, then import the file again.', 'perflocale' ) : '';
	}

	/**
	 * Count and bytes of the staged import files (DataImportJob::STAGED_NAME) in the temp folder; null when the folder
	 * or a file's size could not be read.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function staged_totals(): ?array {
		$dir   = \PerfLocale\Helper::uploads_temp_dir();
		$names = '' === $dir ? false : @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is handled: a failed listing refuses.

		if ( false === $names ) {
			return null;
		}

		$files = 0;
		$bytes = 0;

		foreach ( $names as $name ) {
			if ( 1 !== preg_match( \PerfLocale\Background\Jobs\DataImportJob::STAGED_NAME, $name ) ) {
				continue;
			}

			$path = $dir . '/' . $name;

			clearstatcache( true, $path );

			$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false refuses, also for a file deleted since the listing (retried in a moment).

			if ( false === $size ) {
				return null;
			}

			++$files;
			$bytes += $size;
		}

		return [ $files, $bytes ];
	}

	/**
	 * Move a finished upload's file to its claimed name, so no delayed piece reaches it, and check its size under
	 * the pieces' lock (a piece writing at the move finishes first); '' and the file deleted when the size is wrong.
	 *
	 * @param string $path Staged file.
	 * @param int    $size Announced size.
	 * @return string Claimed file, or ''.
	 */
	private static function take( string $path, int $size ): string {
		// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic move in the hardened temp directory and the pieces' flock; WP_Filesystem has neither. Failures are handled.
		$claimed = substr( $path, 0, -5 ) . '-claimed.json';

		if ( ! @rename( $path, $claimed ) ) {
			return '';
		}

		$handle = @fopen( $claimed, 'r+b' );
		$whole  = false;

		if ( $handle ) {
			flock( $handle, LOCK_EX );
			$stat  = fstat( $handle );
			$whole = is_array( $stat ) && (int) $stat['size'] === $size;
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged

		// The upload's cleanup event names the old path; the import schedules its own.
		\PerfLocale\Background\BackgroundEvents::unschedule( 'perflocale_cleanup_temp_import', [ $path ] );

		if ( ! $whole ) {
			wp_delete_file( $claimed );

			return '';
		}

		return $claimed;
	}

	/**
	 * Release the reservation of the upload a staged file belongs to (the cleanup event's file); other paths are ignored.
	 *
	 * @param string $path Staged file.
	 * @return void
	 */
	public static function release_path( string $path ): void {
		$name = basename( $path );

		if ( str_starts_with( $name, 'import-' ) && str_ends_with( $name, '.json' ) ) {
			$token = substr( $name, 7, -5 );

			if ( 1 === preg_match( self::TOKEN_PATTERN, $token ) ) {
				self::release( $token );
			}
		}
	}

	/**
	 * The limits on uploads in progress.
	 *
	 * @return array{user_uploads: int, user_bytes: int, site_uploads: int, site_bytes: int}
	 */
	public static function quota(): array {
		$max = max( 1, AdminController::import_max_bytes() );
		// Clamped: past PHP_INT_MAX (512 MB x 4 on 32-bit PHP) the product is a float.
		$defaults = [
			'user_uploads' => 3,
			'user_bytes'   => $max > intdiv( PHP_INT_MAX, 2 ) ? PHP_INT_MAX : 2 * $max,
			'site_uploads' => 10,
			'site_bytes'   => $max > intdiv( PHP_INT_MAX, 4 ) ? PHP_INT_MAX : 4 * $max,
		];

		/**
		 * Filters the limits on import file uploads in progress (files the Export & Import screen sends in pieces). An
		 * upload counts its announced size from its start until it is imported, cancelled, fails or idles an hour; a start
		 * past a limit is refused. The site limits also bound the files staged for import (queued, or a failed import's
		 * file kept for Retry): a web import past them is refused.
		 *
		 * @hook  perflocale/import/upload_quota
		 * @since 1.0.7.3
		 *
		 * @param array{user_uploads: int, user_bytes: int, site_uploads: int, site_bytes: int} $quota
		 *        Defaults: 3 uploads and twice the import file ceiling
		 *        (`perflocale/import/max_file_bytes`) per user; 10 uploads and
		 *        four times the ceiling for the site.
		 */
		$filtered = Typed::arr( apply_filters( 'perflocale/import/upload_quota', $defaults ) );
		$out      = $defaults;

		foreach ( $defaults as $key => $value ) {
			$out[ $key ] = max( 1, Typed::int( $filtered[ $key ] ?? null, $value ) );
		}

		return $out;
	}

	/**
	 * Reserve a place for a new upload, after dropping expired reservations and their files.
	 *
	 * @param string $token Upload token.
	 * @param int    $user  User ID.
	 * @param int    $bytes Announced size.
	 * @return array{0: int, 1: string} HTTP status and the reason, for the screen; '' when reserved.
	 */
	private static function reserve( string $token, int $user, int $bytes ): array {
		$quota = self::quota();

		$refusal = self::with_reservations(
			static function ( array $held ) use ( $token, $user, $bytes, $quota ): array {
				$mine_count = 0;
				$mine_bytes = 0;
				$all_bytes  = 0;

				foreach ( $held as $entry ) {
					$all_bytes += $entry['bytes'];

					if ( $entry['user'] === $user ) {
						++$mine_count;
						$mine_bytes += $entry['bytes'];
					}
				}

				if ( $mine_count + 1 > $quota['user_uploads'] || $mine_bytes + $bytes > $quota['user_bytes'] ) {
					return [
						$held,
						sprintf(
							/* translators: 1: number of uploads, 2: a size, for example "1 GB". */
							__( 'Upload refused: you already have uploads of import files in progress (at most %1$d at a time and %2$s in all). Let them finish, or wait an hour for an abandoned one to expire, then import the file again.', 'perflocale' ),
							$quota['user_uploads'],
							AdminController::format_bytes( $quota['user_bytes'] )
						),
					];
				}

				if ( count( $held ) + 1 > $quota['site_uploads'] || $all_bytes + $bytes > $quota['site_bytes'] ) {
					return [
						$held,
						sprintf(
							/* translators: 1: number of uploads, 2: a size, for example "2 GB". */
							__( 'Upload refused: this site already has uploads of import files in progress (at most %1$d at a time and %2$s in all). Wait for them to finish, or an hour for an abandoned one to expire, then import the file again.', 'perflocale' ),
							$quota['site_uploads'],
							AdminController::format_bytes( $quota['site_bytes'] )
						),
					];
				}

				$held[ $token ] = [
					'user'    => $user,
					'bytes'   => $bytes,
					'expires' => time() + self::IDLE_SECONDS,
				];

				return [ $held, '' ];
			}
		);

		if ( is_string( $refusal ) ) {
			return [ 429, $refusal ];
		}

		return [ 503, self::unreserved_message( $refusal ) ];
	}

	/**
	 * Why with_reservations() could not place an upload.
	 *
	 * @param mixed $answer Its answer.
	 * @return string
	 */
	private static function unreserved_message( $answer ): string {
		return match ( $answer ) {
			self::UNREADABLE => __( 'The upload was not started: the list of uploads in progress could not be read. Import the file again in a moment.', 'perflocale' ),
			self::DAMAGED    => __( 'The upload was not started: the list of uploads in progress is damaged. It is cleared within an hour; import the file again then.', 'perflocale' ),
			self::UNSAVED    => __( 'The upload was not started: its place in the list of uploads in progress could not be saved. Import the file again in a moment.', 'perflocale' ),
			default          => self::busy_message(),
		};
	}

	/**
	 * The message for a request that found the upload quota lock busy.
	 *
	 * @return string
	 */
	public static function busy_message(): string {
		return __( 'The server is busy with another upload. Import the file again in a moment.', 'perflocale' );
	}

	/**
	 * Extend an upload's reservation for another piece.
	 *
	 * @param string $token Upload token.
	 * @return bool|null False when the reservation is gone or has expired (it
	 *                   is dropped), null when the quota lock stayed busy.
	 */
	private static function renew( string $token ): ?bool {
		$renewed = self::with_reservations(
			static function ( array $held ) use ( $token ): array {
				if ( ! isset( $held[ $token ] ) ) {
					return [ $held, false ];
				}

				$held[ $token ]['expires'] = time() + self::IDLE_SECONDS;

				return [ $held, true ];
			}
		);

		return is_bool( $renewed ) ? $renewed : null;
	}

	/**
	 * Drop an upload's reservation.
	 *
	 * @param string $token Upload token.
	 * @return bool|null Whether the upload held a live reservation; null when
	 *                   the quota lock stayed busy.
	 */
	private static function release( string $token ): ?bool {
		$held = self::with_reservations(
			static function ( array $held ) use ( $token ): array {
				$had = isset( $held[ $token ] );

				unset( $held[ $token ] );

				return [ $held, $had ];
			}
		);

		return is_bool( $held ) ? $held : null;
	}

	/**
	 * Change the reservations under the lock: the callback gets the live ones (expired ones and their files dropped
	 * first) and returns them as stored, with its result. An unreadable row changes nothing and runs no callback.
	 *
	 * @param callable(array<string, array{user: int, bytes: int, expires: int}>): array{0: array<string, array{user: int, bytes: int, expires: int}>, 1: mixed} $change Change.
	 * @return mixed The callback's result; null when the lock stayed busy; UNREADABLE, DAMAGED or UNSAVED otherwise.
	 */
	private static function with_reservations( callable $change ) {
		$tries = 0;

		while ( ! \PerfLocale\Concurrency\Lock::acquire( self::QUOTA_LOCK, 30 ) ) {
			if ( ++$tries >= 50 ) {
				return null;
			}

			usleep( 100000 );
		}

		try {
			// The stored row, not this request's copy: another request may have changed it.
			wp_cache_delete( self::QUOTA_OPTION, 'options' );
			\PerfLocale\Database\NotOptions::unlist( [ self::QUOTA_OPTION ] );

			[ $state, $stored, $found, $raw ] = self::read_stored();

			if ( self::STORED_FAILED === $state ) {
				return self::UNREADABLE;
			}

			if ( self::STORED_INVALID === $state && ! self::damage_expired( $found, (string) $raw ) ) {
				return self::DAMAGED;
			}

			$held = [];
			$now  = time();

			foreach ( $stored as $token => $entry ) {
				if ( $entry['expires'] < $now ) {
					self::discard( self::path_for( $token ), false );
					continue;
				}

				$held[ $token ] = $entry;
			}

			[ $after, $result ] = $change( $held );

			$unchanged = self::STORED_INVALID !== $state && $after === $stored;

			return $unchanged || self::persist( $after, $found, $raw ) ? $result : self::UNSAVED;
		} finally {
			\PerfLocale\Concurrency\Lock::release( self::QUOTA_LOCK );
		}
	}

	/**
	 * The reservation row as stored, past every cache: its STORED_* state, the reservations ([] unless valid), the
	 * decoded value and the exact stored bytes (null without a row).
	 *
	 * @return array{0: int, 1: array<string, array{user: int, bytes: int, expires: int}>, 2: mixed, 3: string|null}
	 */
	private static function read_stored(): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [ self::STORED_FAILED, [], null, null ];
		}

		$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::QUOTA_OPTION );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above. get_option() answers the same for no row and a failed read, and caches both as absent.
		$rows = null !== $sql ? $wpdb->query( $sql ) : false;

		if ( false === $rows ) {
			return [ self::STORED_FAILED, [], null, null ];
		}

		if ( 0 === $rows ) {
			return [ self::STORED_ABSENT, [], null, null ];
		}

		$raw   = Typed::str( $wpdb->last_result[0]->option_value ?? null );
		$value = maybe_unserialize( $raw );
		$held  = self::reservations_in( $value );

		return null === $held ? [ self::STORED_INVALID, [], $value, $raw ] : [ self::STORED_VALID, $held, $value, $raw ];
	}

	/**
	 * A stored value's reservations; null unless all of it is usable, so nothing in it goes uncounted.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string, array{user: int, bytes: int, expires: int}>|null
	 */
	private static function reservations_in( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$held = [];

		foreach ( $value as $token => $entry ) {
			if ( ! is_string( $token ) || 1 !== preg_match( self::TOKEN_PATTERN, $token ) || ! is_array( $entry ) ) {
				return null;
			}

			$user    = $entry['user'] ?? null;
			$bytes   = $entry['bytes'] ?? null;
			$expires = $entry['expires'] ?? null;

			if ( ! is_int( $user ) || ! is_int( $bytes ) || ! is_int( $expires ) || $bytes < 0 ) {
				return null;
			}

			$held[ $token ] = [
				'user'    => $user,
				'bytes'   => $bytes,
				'expires' => $expires,
			];
		}

		return $held;
	}

	/**
	 * Whether a damaged row can be replaced: a past stamp alone in the row, one idle period old. Any other value is newly
	 * found damage and is stamped; no reservation renews while the row is damaged, so none can still be live then.
	 *
	 * @param mixed  $found The damaged value.
	 * @param string $raw   Its stored bytes.
	 * @return bool
	 */
	private static function damage_expired( $found, string $raw ): bool {
		$since = is_array( $found ) && [ self::DAMAGED_SINCE ] === array_keys( $found ) ? $found[ self::DAMAGED_SINCE ] : null;

		if ( is_int( $since ) && $since > 0 && $since <= time() ) {
			return time() - $since >= self::IDLE_SECONDS;
		}

		// Its result does not matter: this request refuses either way, and a stamp that failed only delays the hour.
		\PerfLocale\Database\OptionWriter::compare_and_set( self::QUOTA_OPTION, [ self::DAMAGED_SINCE => time() ], $found, $raw, false );

		return false;
	}

	/**
	 * Store the reservations over the exact bytes read, so a request that lost its lock changes nothing. A write that
	 * matched no row counts only when the row now holds what was asked (wpdb reran it after a lost reply).
	 *
	 * @param array<string, array{user: int, bytes: int, expires: int}> $after Reservations.
	 * @param mixed                                                     $found The value read.
	 * @param string|null                                               $raw   Its stored bytes; null: no row.
	 * @return bool
	 */
	private static function persist( array $after, $found, ?string $raw ): bool {
		if ( [] === $after ) {
			return null !== $raw && self::delete_row( $raw );
		}

		if ( true === \PerfLocale\Database\OptionWriter::compare_and_set( self::QUOTA_OPTION, $after, $found, $raw, false ) ) {
			return true;
		}

		[ $state, $stored ] = self::read_stored();

		return self::STORED_VALID === $state && $stored === $after;
	}

	/**
	 * Delete the row only while it holds $raw; a delete that matched no row did not clear it.
	 *
	 * @param string $raw The row's bytes as read.
	 * @return bool
	 */
	private static function delete_row( string $raw ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete; the options cache is dropped below.
		$done = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, self::QUOTA_OPTION, $raw ) ?? '' );

		wp_cache_delete( self::QUOTA_OPTION, 'options' );

		return 1 === $done;
	}

	/**
	 * The size a well-formed token of the current user announces; 0 otherwise.
	 *
	 * @param string $token Token.
	 * @return int
	 */
	private static function announced_size( string $token ): int {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token, $m ) ) {
			return 0;
		}

		if ( (int) $m[1] !== get_current_user_id() || get_current_user_id() <= 0 ) {
			return 0;
		}

		return (int) $m[2];
	}

	/**
	 * Where a token's file is assembled, named like every staged import (`import-<token>.json`) so the temp cleanup
	 * covers it.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function path_for( string $token ): string {
		return \PerfLocale\Helper::uploads_temp_dir() . '/import-' . $token . '.json';
	}

	/**
	 * Delete the upload's file and answer with an error.
	 *
	 * @param string $path    The upload's file, or '' when there is none.
	 * @param string $message Error.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	private static function fail( string $path, string $message, int $status ): void {
		if ( '' !== $path ) {
			self::discard( $path );
		}

		wp_send_json_error( [ 'message' => $message ], $status );
	}

	/**
	 * Delete an upload's file, its cleanup event and its reservation.
	 *
	 * @param string $path    File.
	 * @param bool   $release Also drop the reservation (false while the
	 *                        reservations are already being changed).
	 * @return void
	 */
	private static function discard( string $path, bool $release = true ): void {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}

		\PerfLocale\Background\BackgroundEvents::unschedule( 'perflocale_cleanup_temp_import', [ $path ] );

		if ( $release ) {
			self::release_path( $path );
		}
	}

	/**
	 * The message for a file larger than this site imports through the screen (WP-CLI is left).
	 *
	 * @return string
	 */
	public static function too_large_message(): string {
		return sprintf(
			/* translators: %s: largest file this site imports through the screen, e.g. "512 MB". "wp perflocale import" is a command and stays in English. */
			__( 'This file is larger than this site imports through this screen (%s). Import it with WP-CLI instead: wp perflocale import <file>.', 'perflocale' ),
			AdminController::format_bytes( AdminController::import_max_bytes() )
		);
	}
}
