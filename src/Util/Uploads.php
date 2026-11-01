<?php
/**
 * The plugin's uploads directories.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's uploads directories and WP_Filesystem access.
 *
 * Kept out of Helper so visitor pages do not compile it; Helper forwards its @api methods here.
 */
final class Uploads {

	/**
	 * Guard file: Apache 2.4 reads the first block (without mod_access_compat a bare "Deny" answers 500), 2.2 the second.
	 */
	public const HTACCESS = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";

	/**
	 * Get an initialized WP_Filesystem instance, or null on failure.
	 *
	 * @return \WP_Filesystem_Base|null Filesystem instance or null on failure.
	 */
	public static function filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return null;
		}

		/** @var \WP_Filesystem_Base|mixed $wp_filesystem */
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}


	/**
	 * Absolute path of the plugin's per-blog uploads directory, `uploads/perflocale`.
	 *
	 * @return string Absolute path WITHOUT trailing slash.
	 */
	public static function uploads_base_dir(): string {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		return $base === '' ? '' : trailingslashit( $base ) . 'perflocale';
	}


	/**
	 * Generated translation files dir, `uploads/perflocale/translations/`.
	 *
	 * @return string
	 */
	public static function uploads_translations_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/translations';
	}


	/**
	 * Scratch dir for in-flight imports, `uploads/perflocale/temp/`.
	 *
	 * @return string
	 */
	public static function uploads_temp_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/temp';
	}


	/**
	 * Export bundle dir, `uploads/perflocale/exports/`.
	 *
	 * @return string
	 */
	public static function uploads_exports_dir(): string {
		$base = self::uploads_base_dir();
		return $base === '' ? '' : $base . '/exports';
	}


	/**
	 * Daily backstop sweep of old temp and export files whose own delete event never ran.
	 *
	 * Never deletes the directory's hardening files, nor the file of a queued or running XLIFF import.
	 *
	 * @param int $max_age_days Files older than this (default: 7) are removed.
	 * @return int Number of files deleted.
	 */
	public static function gc_stale_upload_files( int $max_age_days = 7 ): int {
		$cutoff  = time() - ( $max_age_days * DAY_IN_SECONDS );
		$dirs    = [ self::uploads_temp_dir(), self::uploads_exports_dir() ];
		$skip    = [ '.htaccess', 'index.php', 'web.config' ];
		$removed = 0;
		$live    = null;

		foreach ( $dirs as $dir ) {
			if ( $dir === '' || ! is_dir( $dir ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @ suppresses the warning when the dir is unreadable mid-sweep; the === false check on the next line skips that dir.
			$dh = @opendir( $dir );
			if ( $dh === false ) {
				continue;
			}
			while ( ( $entry = readdir( $dh ) ) !== false ) {
				if ( $entry === '.' || $entry === '..' || in_array( $entry, $skip, true ) ) {
					continue;
				}
				$path = trailingslashit( $dir ) . $entry;
				if ( ! is_file( $path ) ) {
					continue;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @ silences the stat warning when a file disappears between readdir() and this call; the === false check below skips it.
				$mtime = @filemtime( $path );
				if ( $mtime === false || $mtime > $cutoff ) {
					continue;
				}
				// Read on the first old XLIFF file; false (no answer) keeps them all.
				if ( str_starts_with( $entry, 'xliff-' ) ) {
					$live ??= \PerfLocale\Background\Jobs\XliffImportJob::live_file_names() ?? false;
					if ( $live === false || isset( $live[ $entry ] ) ) {
						continue;
					}
				}
				if ( wp_delete_file( $path ) || ! file_exists( $path ) ) {
					++$removed;
				}
			}
			closedir( $dh );
		}

		return $removed;
	}


	/**
	 * Ensure the plugin's base uploads dir exists and is hardened; subdirs harden themselves on creation.
	 *
	 * @return void
	 */
	public static function ensure_uploads_base_dir(): void {
		$base = self::uploads_base_dir();
		if ( $base === '' ) {
			return;
		}
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		self::harden_directory( $base );
	}


	/**
	 * Run harden_directory() on the base dir and each of its subdirectories that has a `.htaccess` (upgrade task).
	 *
	 * @return void
	 * @throws \RuntimeException When an old guard could not be replaced: the task fails and is retried.
	 */
	public static function harden_existing(): void {
		$base = self::uploads_base_dir();

		if ( $base === '' || ! is_dir( $base ) ) {
			return;
		}

		$subdirs = glob( $base . '/*', GLOB_ONLYDIR );
		$failed  = [];

		foreach ( array_merge( [ $base ], is_array( $subdirs ) ? $subdirs : [] ) as $dir ) {
			if ( is_file( $dir . '/.htaccess' ) && ! self::harden( $dir ) ) {
				$failed[] = 'perflocale' . substr( $dir, strlen( $base ) );
			}
		}

		if ( [] !== $failed ) {
			throw new \RuntimeException(
				/* translators: %s: folder names, such as perflocale/temp */
				esc_html( sprintf( __( 'The .htaccess file in uploads/%s could not be replaced safely. Make the folder and the file readable and writable for PHP, then use Retry now.', 'perflocale' ), implode( ', uploads/', $failed ) ) ),
				\PerfLocale\Database\UpgradeRunner::OWN_ERROR // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant.
			);
		}
	}

	/**
	 * Write `.htaccess` (HTACCESS) and `index.php` into a plugin-owned directory, once each; a `.htaccess` that holds
	 * only "Deny from all" gets HTACCESS.
	 *
	 * Defence in depth only (nginx ignores .htaccess): the random file names are the access control,
	 * so never shorten those tokens on the strength of this file. No web.config, by WordPress convention.
	 *
	 * @param string $dir Absolute filesystem path to an existing directory.
	 * @return void
	 */
	public static function harden_directory( string $dir ): void {
		self::harden( $dir );
	}

	/**
	 * harden_directory(); false when an old guard is still in place.
	 *
	 * @param string $dir Absolute filesystem path to an existing directory.
	 * @return bool
	 */
	private static function harden( string $dir ): bool {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return true;
		}

		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

		// Without WP_Filesystem (ftpext, no credentials) write directly: this PHP user created the dir.
		$fs    = self::filesystem();
		$guard = trailingslashit( $dir ) . '.htaccess';
		$files = [
			$guard                                => self::HTACCESS,
			trailingslashit( $dir ) . 'index.php' => "<?php\n// Silence is golden.\n",
		];
		$ok    = true;

		foreach ( $files as $file => $contents ) {
			if ( $fs instanceof \WP_Filesystem_Base ? $fs->exists( $file ) : file_exists( $file ) ) {
				if ( $file === $guard ) {
					$ok = self::replace_legacy_guard( $guard, (int) $mode );
				}
				continue;
			}

			if ( $fs instanceof \WP_Filesystem_Base ) {
				$fs->put_contents( $file, $contents, $mode );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem being unavailable is the entire reason this branch exists, so there is nothing to route through; @ keeps an unwritable directory from emitting a warning into an AJAX/REST body, exactly as gc_stale_upload_files() does above.
			@file_put_contents( $file, $contents );
		}

		return $ok;
	}

	/**
	 * Replace a guard holding only "Deny from all" with HTACCESS: a verified sibling file renamed over it, so the folder
	 * never holds a partial guard; true when the guard is read and is not, or no longer, the old one. Native calls only,
	 * as WP_Filesystem's move() deletes the destination first.
	 *
	 * @param string $guard Guard path.
	 * @param int    $mode  File mode when the guard's own is unreadable.
	 * @return bool
	 */
	private static function replace_legacy_guard( string $guard, int $mode ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic rename needs the native calls; @ keeps warnings out of AJAX/REST bodies.
		$held = @file_get_contents( $guard );

		if ( ! self::is_legacy( $held ) ) {
			return false !== $held;
		}

		if ( ! is_writable( dirname( $guard ) ) || ! is_writable( $guard ) ) {
			return false;
		}

		$tmp   = $guard . '.pfl-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$perms = @fileperms( $guard );
		$ok    = strlen( self::HTACCESS ) === @file_put_contents( $tmp, self::HTACCESS ) && self::HTACCESS === @file_get_contents( $tmp );

		if ( $ok ) {
			@chmod( $tmp, false === $perms ? $mode : $perms & 0777 );

			// Read again last: a hand edit since the first read is kept.
			$held = @file_get_contents( $guard );

			if ( ! self::is_legacy( $held ) ) {
				@unlink( $tmp );
				return false !== $held;
			}
		}

		if ( ! $ok || ! @rename( $tmp, $guard ) ) {
			@unlink( $tmp );
			return false;
		}

		return self::HTACCESS === @file_get_contents( $guard );
		// phpcs:enable
	}

	/**
	 * Whether a guard's content is the old bare "Deny from all".
	 *
	 * @param mixed $held File content, or false.
	 * @return bool
	 */
	private static function is_legacy( mixed $held ): bool {
		return is_string( $held ) && 'deny from all' === strtolower( trim( $held ) );
	}
}
