<?php
/**
 * Detaches the code in memory once an upgrader changes this plugin's files in the same request.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * After our files change on disk they belong to another version: a class loaded from them can clash with the classes
 * in memory (fatal). So from then on no class of ours loads from disk and none of our hooks runs.
 *
 * @since 1.0.8
 */
final class OwnFilesReplaced {

	/**
	 * Whether our files changed in this request (the code in memory is detached).
	 *
	 * @var bool
	 */
	private static bool $replaced = false;

	/**
	 * Fingerprint of our files when a replacement became pending; null: none pending.
	 *
	 * @var string|null
	 */
	private static ?string $before = null;

	/** Buffered work flushed by register_shutdown_function(), outside WordPress hooks: run early when loaded. */
	private const FLUSHES = [
		[ \PerfLocale\WooCommerce\StockDriftFlag::class, 'flush' ],
		[ \PerfLocale\Translation\TermSlugRetry::class, 'flush' ],
		[ \PerfLocale\Resync\SyncRecorder::class, 'flush_captures' ],
		[ \PerfLocale\Database\Repository\TranslationGroupWrites::class, 'end_all_deferred_link_invalidation' ],
		[ \PerfLocale\Cache\CacheManager::class, 'announce_changes' ],
	];

	/**
	 * Whether our files changed in this request.
	 *
	 * @return bool
	 */
	public static function replaced(): bool {
		return self::$replaced;
	}

	/**
	 * Hooked to upgrader_source_selection last: nothing on disk has changed yet.
	 *
	 * @param mixed $source        The package's folder, or a WP_Error.
	 * @param mixed $remote_source Unused.
	 * @param mixed $upgrader      Unused.
	 * @param mixed $hook_extra    The upgrader's hook_extra.
	 * @return mixed $source unchanged.
	 */
	public static function on_source_selection( mixed $source, mixed $remote_source = '', mixed $upgrader = null, mixed $hook_extra = [] ): mixed {
		if ( self::$replaced || ! is_string( $source ) ) {
			return $source;
		}

		$own = plugin_basename( self::own_file() );

		// An update of this plugin (any package folder name), or a package for our folder name (upload Replace).
		if ( ( is_array( $hook_extra ) && ( $hook_extra['plugin'] ?? null ) === $own ) || basename( untrailingslashit( $source ) ) === dirname( $own ) ) {
			self::pending();
		}

		return $source;
	}

	/**
	 * Hooked to upgrader_clear_destination and upgrader_install_package_result last: detach once our files changed
	 * (a failed clear or update that left them intact detaches nothing; a partial one does).
	 *
	 * @param mixed $result Passed through.
	 * @return mixed $result unchanged.
	 */
	public static function check( mixed $result ): mixed {
		if ( ! self::$replaced && null !== self::$before && self::fingerprint() !== self::$before ) {
			self::detach();
		}

		return $result;
	}

	/**
	 * A replacement may follow: flush buffered work while our files are intact, then watch for them changing.
	 *
	 * @return void
	 */
	private static function pending(): void {
		if ( null !== self::$before ) {
			return;
		}

		self::$before = self::fingerprint();
		self::flush_buffered();

		add_filter( 'upgrader_clear_destination', [ self::class, 'check' ], PHP_INT_MAX );
		add_filter( 'upgrader_install_package_result', [ self::class, 'check' ], PHP_INT_MAX );
	}

	/**
	 * Run the request's buffered work now (our shutdown-hook callbacks, then the loaded FLUSHES). Each empties its
	 * buffer, so the run at shutdown finds nothing new when the replacement fails.
	 *
	 * @return void
	 */
	private static function flush_buffered(): void {
		global $wp_filter;

		$hook = is_array( $wp_filter ) ? ( $wp_filter['shutdown'] ?? null ) : null;

		if ( $hook instanceof \WP_Hook ) {
			$callbacks = $hook->callbacks;
			ksort( $callbacks );

			foreach ( $callbacks as $at_priority ) {
				foreach ( is_array( $at_priority ) ? $at_priority : [] as $callback ) {
					$function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;

					if ( is_callable( $function ) && self::is_own( $function ) ) {
						// As do_action( 'shutdown' ) calls it: one empty-string argument at most.
						$args = $callback['accepted_args'] ?? 1;
						call_user_func_array( $function, is_int( $args ) && $args < 1 ? [] : [ '' ] );
					}
				}
			}
		}

		foreach ( self::FLUSHES as $flush ) {
			if ( class_exists( $flush[0], false ) ) {
				call_user_func( $flush );
			}
		}
	}

	/**
	 * Unregister our autoloader and remove every callback of ours from every hook, for the rest of the request.
	 *
	 * @return void
	 */
	private static function detach(): void {
		self::$replaced = true;

		// Read by the entry points that load a file by a literal require.
		if ( ! defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			define( 'PERFLOCALE_FILES_REPLACED', true );
		}

		foreach ( spl_autoload_functions() as $loader ) {
			if ( $loader instanceof \Closure && self::own_file() === ( new \ReflectionFunction( $loader ) )->getFileName() ) {
				spl_autoload_unregister( $loader );
			}
		}

		global $wp_filter;

		foreach ( is_array( $wp_filter ) ? $wp_filter : [] as $hook => $wp_hook ) {
			if ( ! $wp_hook instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( is_array( $callbacks ) ? $callbacks : [] as $callback ) {
					$function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;

					if ( is_callable( $function, true ) && self::is_own( $function ) ) {
						remove_filter( (string) $hook, $function, (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * Path, size, mtime and inode of every PHP file of ours (dev-only folders aside); 'missing' without the folder.
	 *
	 * @return string
	 */
	private static function fingerprint(): string {
		$dir = self::own_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 'missing';
		}

		clearstatcache();
		$rows = [];

		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					static fn( \SplFileInfo $f ): bool => ! $f->isDir() || ! in_array( $f->getFilename(), [ 'vendor', 'node_modules', 'tests', 'tools', '.git' ], true )
				)
			);

			foreach ( $files as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$rows[] = $file->getPathname() . '|' . $file->getSize() . '|' . $file->getMTime() . '|' . $file->getInode();
				}
			}
		} catch ( \UnexpectedValueException | \RuntimeException ) {
			// A folder removed while it is read: the tree changed.
			return 'unreadable';
		}

		sort( $rows );

		// Compared whole, not hashed: this runs only while one of our replacements is pending.
		return implode( "\n", $rows );
	}

	/**
	 * Whether a hook callback is defined in this plugin's folder.
	 *
	 * @param mixed $callback A hook callback.
	 * @return bool
	 */
	private static function is_own( mixed $callback ): bool {
		if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
			$callback = explode( '::', $callback, 2 );
		}

		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$file = ( new \ReflectionFunction( $callback ) )->getFileName();
			} else {
				$target = is_array( $callback ) ? ( $callback[0] ?? null ) : $callback;

				if ( is_object( $target ) || ( is_string( $target ) && class_exists( $target, false ) ) ) {
					$file = ( new \ReflectionClass( $target ) )->getFileName();
				} elseif ( is_string( $target ) ) {
					// A class-name callback not loaded yet (it cannot load now): ours are in our namespace.
					return 0 === stripos( ltrim( $target, '\\' ), 'PerfLocale\\' );
				} else {
					return false;
				}
			}
		} catch ( \ReflectionException ) {
			return false;
		}

		$dir = self::own_dir();

		return '' !== $dir && is_string( $file ) && str_starts_with( $file, $dir );
	}

	/**
	 * This plugin's main file.
	 *
	 * @return string
	 */
	private static function own_file(): string {
		return defined( 'PERFLOCALE_FILE' ) && is_string( PERFLOCALE_FILE ) ? PERFLOCALE_FILE : '';
	}

	/**
	 * This plugin's folder, with a trailing slash.
	 *
	 * @return string
	 */
	private static function own_dir(): string {
		return defined( 'PERFLOCALE_DIR' ) && is_string( PERFLOCALE_DIR ) ? PERFLOCALE_DIR : '';
	}
}
