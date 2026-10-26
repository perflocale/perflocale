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
	 * Seconds a reservation lasts without a piece.
	 */
	private const IDLE_SECONDS = 3600;

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

		$token = 'u' . get_current_user_id() . '-s' . $size . '-' . wp_generate_password( 24, false, false );
		$path  = self::path_for( $token );

		$refusal = self::reserve( $token, get_current_user_id(), $size );

		if ( '' !== $refusal ) {
			wp_send_json_error( [ 'message' => $refusal ], 429 );
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
	 * off the upload's path (take()); '' when the reservation has expired.
	 *
	 * @param string $token Upload token from the form.
	 * @return string|null Absolute path; null when the quota lock stayed busy
	 *                     (the upload is kept).
	 */
	public static function claim( string $token ): ?string {
		$path = self::complete_path( $token );

		if ( '' === $path ) {
			return '';
		}

		$released = self::release( $token );

		if ( null === $released ) {
			return null;
		}

		return $released ? self::take( $path, self::announced_size( $token ) ) : '';
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
		 * past a limit is refused.
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
	 * @return string '' when reserved; otherwise the reason, for the screen.
	 */
	private static function reserve( string $token, int $user, int $bytes ): string {
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

		return is_string( $refusal ) ? $refusal : self::busy_message();
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
	 * first) and returns them as stored, with its result.
	 *
	 * @param callable(array<string, array{user: int, bytes: int, expires: int}>): array{0: array<string, array{user: int, bytes: int, expires: int}>, 1: mixed} $change Change.
	 * @return mixed The callback's result, or null when the lock stayed busy.
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

			$stored = get_option( self::QUOTA_OPTION, [] );
			$held   = [];
			$now    = time();

			foreach ( is_array( $stored ) ? $stored : [] as $token => $entry ) {
				if ( ! is_string( $token ) || ! is_array( $entry ) ) {
					continue;
				}

				$entry = [
					'user'    => Typed::int( $entry['user'] ?? null ),
					'bytes'   => max( 0, Typed::int( $entry['bytes'] ?? null ) ),
					'expires' => Typed::int( $entry['expires'] ?? null ),
				];

				if ( $entry['expires'] < $now ) {
					self::discard( self::path_for( $token ), false );
					continue;
				}

				$held[ $token ] = $entry;
			}

			[ $after, $result ] = $change( $held );

			if ( $after !== $stored ) {
				if ( [] === $after ) {
					delete_option( self::QUOTA_OPTION );
				} else {
					update_option( self::QUOTA_OPTION, $after, false );
				}
			}

			return $result;
		} finally {
			\PerfLocale\Concurrency\Lock::release( self::QUOTA_LOCK );
		}
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
