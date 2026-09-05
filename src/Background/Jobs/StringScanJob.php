<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Strings\StringScanner}.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Plugin;
use PerfLocale\Strings\StringScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the gettext string scanner.
 *
 * StringScanner walks `*.php` and `*.twig` files looking for `__()`,
 * `_e()`, `esc_html__()`, and friends. On a large WordPress directory
 * (thousands of files) this is the slowest sync call in the plugin
 * outside of MT — perfect candidate for backgrounding.
 *
 * Two operating modes:
 *
 *   1. Single-directory: `args['directory'] = '/absolute/path'`
 *      Scans one directory tree. Useful for "rescan one plugin / theme"
 *      flows and the REST endpoint.
 *
 *   2. Scan-everything: `args['mode'] = 'all'`
 *      Iterates: parent theme → child theme (if different) → every
 *      active plugin (skipping perflocale itself) → mu-plugins.
 *      Mirrors the AdminController `process_string_scan` handler so
 *      the admin Scan button can route through Dispatcher.
 *
 * Args shape:
 *   - 'mode'       : string   'directory' (default) | 'all'
 *   - 'directory'  : string   Required when mode='directory'. Must
 *                              resolve inside WP_CONTENT_DIR.
 *   - 'domain'     : string   Optional text-domain filter; empty = all.
 *   - 'batch_size' : int      StringScanner batch (default 500).
 */
final class StringScanJob extends AbstractJob {

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'string_scan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Mirrors StringsController's REST permission.
	 */
	public function get_required_capability(): string {
		return 'perflocale_translate';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		// 1000 strikes a balance: small themes + a handful of plugins
		// (200–600 files × 2) stay sync so the operator gets immediate
		// feedback in the admin; bigger installs (15+ heavy plugins, all
		// of WooCommerce) fall through to async to dodge PHP-FPM
		// timeouts. The user-facing override (Settings → Performance →
		// Background processing thresholds) tunes from here.
		return 1000;
	}

	/**
	 * Scanning all of wp-content (themes + plugins + mu-plugins) on a
	 * heavy install can take 10-30 minutes; bump to 2h for safety.
	 */
	public function get_lock_ttl(): int {
		return 2 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Threshold check estimates the actual scan size in both modes —
	 * count of top-level PHP files (× 2 fudge factor for nested dirs)
	 * across the target(s). Returning a real number (rather than the
	 * old `PHP_INT_MAX` shortcut for mode='all') is what makes the
	 * per-type threshold override actually take effect on the
	 * "Scan all" button.
	 *
	 *   - mode='directory': count top-level PHP files in that dir.
	 *   - mode='all'      : sum top-level PHP files across theme,
	 *                       child theme (if different), every active
	 *                       plugin (minus PerfLocale itself), and the
	 *                       mu-plugins directory.
	 */
	protected function args_size( array $args ): int {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'directory';

		if ( $mode === 'all' ) {
			return $this->estimate_all_targets_size();
		}

		$dir = isset( $args['directory'] ) ? (string) $args['directory'] : '';

		if ( $dir === '' || ! is_dir( $dir ) ) {
			return 0;
		}

		return $this->count_top_level_php( $dir ) * 2;
	}

	/**
	 * Estimate the file-count cost of a mode='all' scan. Same shape as
	 * the target list `scan_all()` actually walks, so the threshold
	 * comparison is meaningful.
	 *
	 * @return int
	 */
	private function estimate_all_targets_size(): int {
		$total = 0;

		$parent_theme = get_template_directory();

		if ( is_dir( $parent_theme ) ) {
			$total += $this->count_top_level_php( $parent_theme );
		}

		$child_theme = get_stylesheet_directory();

		if ( $child_theme !== $parent_theme && is_dir( $child_theme ) ) {
			$total += $this->count_top_level_php( $child_theme );
		}

		foreach ( $this->plugin_target_dirs() as $plugin_dir ) {
			$total += $this->count_top_level_php( $plugin_dir );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$total += $this->count_top_level_php( WPMU_PLUGIN_DIR );
		}

		// × 2 fudge factor matches the directory-mode estimate so
		// thresholds are comparable across both call sites.
		return $total * 2;
	}

	/**
	 * Count top-level PHP files in a directory. Fast (single glob).
	 *
	 * @param string $dir
	 * @return int
	 */
	private function count_top_level_php( string $dir ): int {
		$files = glob( rtrim( $dir, '/' ) . '/*.php' );

		return is_array( $files ) ? count( $files ) : 0;
	}

	/**
	 * Plugin directories this scan must cover: site-active PLUS network-active.
	 *
	 * `active_plugins` alone misses every NETWORK-activated plugin on
	 * multisite — those live in the network-wide `active_sitewide_plugins`
	 * site-option, keyed by plugin file with the activation timestamp as the
	 * value. Their strings were therefore never scanned and never translatable,
	 * and, worse, scan_all() still armed `perflocale_strings_last_full_scan`
	 * afterwards. That marker is the ONLY liveness signal
	 * StringRepository::gc_stale_strings() has, so strings the scan never
	 * reached could age past the 90-day retention and be deleted together with
	 * their translations.
	 *
	 * Shared with {@see estimate_all_targets_size()} so the dispatch-threshold
	 * estimate measures exactly the set the run walks.
	 *
	 * @return list<string> Absolute plugin directories, deduped.
	 */
	private function plugin_target_dirs(): array {
		$plugins_root = trailingslashit( WP_PLUGIN_DIR );
		$plugin_files = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$plugin_files = array_merge(
				$plugin_files,
				array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) )
			);
		}

		$dirs = [];

		foreach ( $plugin_files as $plugin_file ) {
			// Skip our own plugin so its strings stay out of the catalogue.
			if ( str_starts_with( (string) $plugin_file, 'perflocale/' ) ) {
				continue;
			}

			$relative = dirname( (string) $plugin_file );

			// A SINGLE-FILE plugin (`hello.php`) has a dirname of '.', which
			// resolves to the plugins ROOT — and the scanner recurses, so that
			// one entry would walk every plugin on the site on every scan.
			// There is no directory of its own to scan, so skip it rather than
			// turn one file into a full-tree crawl. Same reasoning for an
			// empty or absolute-root dirname from a malformed entry.
			if ( $relative === '.' || $relative === '' || $relative === DIRECTORY_SEPARATOR ) {
				continue;
			}

			$plugin_dir = $plugins_root . $relative;

			if ( is_dir( $plugin_dir ) ) {
				$dirs[] = $plugin_dir;
			}
		}

		// Dedupe — a plugin can be both site- and network-active, and symlinks
		// can resolve identically.
		return array_values( array_unique( $dirs ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the scan directory is outside the allowed content path.
	 */
	public function execute( array $args, callable $progress ): array {
		$mode       = isset( $args['mode'] ) ? (string) $args['mode'] : 'directory';
		$domain     = isset( $args['domain'] ) ? (string) $args['domain'] : '';
		$batch_size = isset( $args['batch_size'] ) ? max( 1, (int) $args['batch_size'] ) : 500;

		if ( $mode === 'all' ) {
			return $this->scan_all( $domain, $batch_size, $progress );
		}

		// Single-directory mode.
		$dir = isset( $args['directory'] ) ? (string) $args['directory'] : '';

		if ( ! self::is_safe_content_path( $dir ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Scan directory is not inside wp-content/: %s', $dir ) )
			);
		}

		$progress( 0, 1 );

		$scanner = new StringScanner( Plugin::get_instance()->get( 'cache' ) );
		$result  = $scanner->scan( $dir, $domain, $batch_size );

		$progress( 1, 1 );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Iterate: parent theme → child theme (if different) → active plugins
	 * → mu-plugins. Same set the AdminController sync handler covered.
	 *
	 * @param string   $domain     Domain filter (empty = all).
	 * @param int      $batch_size StringScanner batch size.
	 * @param callable $progress   Progress reporter.
	 * @return array<string, mixed>
	 */
	private function scan_all( string $domain, int $batch_size, callable $progress ): array {
		$plugin  = Plugin::get_instance();
		$cache   = $plugin->get( 'cache' );
		$scanner = new StringScanner( $cache );

		// Build target list. Parent theme → child theme → plugins so
		// child-theme overrides win when both define the same string.
		$targets = [];

		$parent_theme = get_template_directory();
		$targets[]    = $parent_theme;

		$child_theme = get_stylesheet_directory();
		if ( $child_theme !== $parent_theme ) {
			$targets[] = $child_theme;
		}

		foreach ( $this->plugin_target_dirs() as $plugin_dir ) {
			$targets[] = $plugin_dir;
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$targets[] = WPMU_PLUGIN_DIR;
		}

		// Dedupe — defensive in case the active_plugins list contains dupes
		// or symlinks resolve identically.
		$targets = array_values( array_unique( $targets ) );

		$total          = count( $targets );
		$processed      = 0;
		$total_found    = 0;
		$total_inserted = 0;

		$progress( 0, max( 1, $total ) );

		$skipped = [];

		foreach ( $targets as $dir ) {
			if ( ! self::is_safe_content_path( $dir ) ) {
				// Record the skip — see the GC marker below. Silently skipping
				// made the scan claim full coverage it did not have.
				$skipped[] = $dir;
				++$processed;
				$progress( $processed, $total );
				continue;
			}

			$r               = $scanner->scan( $dir, $domain, $batch_size );
			$total_found    += (int) ( $r['found'] ?? 0 );
			$total_inserted += (int) ( $r['inserted'] ?? 0 );

			++$processed;
			$progress( $processed, $total );
		}

		// Lets addons register non-gettext strings as part of the scan.
		do_action( 'perflocale/strings/after_scan' );

		// Record that a full scan just completed. gc_stale_strings() gates its
		// delete pass on this marker: without a recent full scan, last_seen_at
		// carries no liveness signal, so the GC must not delete strings that
		// were simply never scanned (e.g. imported via PO/migration on a site
		// the operator never scans). Unix epoch = timezone-free comparison.
		//
		// Only arm it when coverage was ACTUALLY complete. A target rejected
		// by the path guard (symlinked plugin dir, relocated wp-content) never
		// re-marked its strings' last_seen_at, so arming the marker would let
		// the 90-day GC delete strings — and their translations — that are
		// still very much in use. Leaving the marker untouched keeps the GC
		// disarmed, which fails safe.
		if ( $skipped === [] && $domain === '' ) {
			update_option( 'perflocale_strings_last_full_scan', time(), false );
		} elseif ( $skipped === [] ) {
			// Every target was readable, but the run was filtered to ONE text
			// domain, so it only re-stamped last_seen_at for that domain's rows;
			// every other domain's strings look untouched to the GC. Arming the
			// GLOBAL marker would let gc_stale_strings() delete them, and their
			// translations, once they aged past the retention window. No shipped
			// surface dispatches mode='all' with a domain — AdminController::
			// process_string_scan passes ['mode' => 'all'] and nothing else — so
			// this guard is here to make sure adding one later cannot silently
			// turn the GC into a data-loss path.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a filtered scan.
			error_log(
				sprintf(
					'[PerfLocale] String scan was filtered to text domain "%s"; it carries no liveness signal for other domains, so the stale-string GC stays disarmed.',
					$domain
				)
			);
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a partial scan.
			error_log(
				sprintf(
					'[PerfLocale] String scan covered %d/%d targets; %d unreadable path(s) skipped, so the stale-string GC stays disarmed: %s',
					$total - count( $skipped ),
					$total,
					count( $skipped ),
					implode( ', ', array_slice( $skipped, 0, 5 ) )
				)
			);
		}

		// Compute the DB total so callers get the post-scan row count
		// directly instead of going via a transient.
		$string_repo = new StringRepository( $cache );
		$db_total    = $string_repo->count();

		// `targets` is what was actually SCANNED, not what was selected. A
		// target the path guard rejected contributed no strings, so counting
		// it here made the stored result claim coverage the run did not have —
		// the same shape as the disarmed-GC marker above, which is already
		// conditioned on $skipped. `skipped_targets` (and a bounded sample of
		// the paths) is the operator's only in-UI signal: the error_log line
		// is unreachable on most managed hosts, and the Jobs → Details panel
		// renders this array.
		$result = [
			'found'           => $total_found,
			'inserted'        => $total_inserted,
			'scan_new'        => $total_inserted,
			'scan_total'      => $db_total,
			'targets'         => $total - count( $skipped ),
			'targets_total'   => $total,
			'skipped_targets' => count( $skipped ),
		];

		if ( $skipped !== [] ) {
			// Same prefix substitution WorkerRegistry::redact_paths() applies
			// before a job error is stored: this array is persisted on the job
			// row and rendered by the Jobs page and the REST detail endpoint,
			// and the host's directory layout is not the operator's business.
			// A target under neither root (a relocated theme root) is left
			// whole — naming the unreadable directory IS the message.
			$result['skipped_paths'] = array_map(
				static function ( string $path ): string {
					return str_replace(
						[ rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/', rtrim( (string) ABSPATH, '/\\' ) . '/' ],
						[ '<content>/', '<wp>/' ],
						$path
					);
				},
				array_slice( $skipped, 0, 5 )
			);
			$result['first_error']   = sprintf(
				/* translators: 1: number of unreadable targets, 2: total number of targets. */
				__( 'Partial scan: %1$d of %2$d targets could not be read, so the stale-string cleanup stays disabled. Check that every plugin/theme directory resolves inside wp-content.', 'perflocale' ),
				count( $skipped ),
				$total
			);
		}

		return $result;
	}

	/**
	 * Roots a scan target may live under: wp-content plus every location
	 * WordPress itself reports for plugins, must-use plugins and themes.
	 *
	 * WP_PLUGIN_DIR / WPMU_PLUGIN_DIR / the theme roots are normally inside
	 * WP_CONTENT_DIR, but all four are relocatable, so they are listed
	 * explicitly rather than assumed. Only absolute values are used:
	 * get_theme_roots() returns a RELATIVE fragment ('/themes') on the
	 * common single-root install, so the registered-roots global and
	 * get_theme_root() are read instead.
	 *
	 * @return array<int, string> Absolute directory paths (no trailing slash).
	 */
	private static function allowed_scan_roots(): array {
		$roots = [ WP_CONTENT_DIR ];

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$roots[] = WP_PLUGIN_DIR;
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$roots[] = WPMU_PLUGIN_DIR;
		}

		$registered = $GLOBALS['wp_theme_directories'] ?? [];

		if ( is_array( $registered ) ) {
			foreach ( $registered as $theme_root ) {
				if ( is_string( $theme_root ) && $theme_root !== '' ) {
					$roots[] = $theme_root;
				}
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			$roots[] = get_theme_root();
		}

		$normalized = [];

		foreach ( $roots as $root ) {
			$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );

			if ( $root !== '' ) {
				$normalized[ $root ] = true;
			}
		}

		return array_keys( $normalized );
	}

	/**
	 * Whether $path sits at or below $root, comparing as normalized strings.
	 *
	 * @param string $path Absolute path.
	 * @param string $root Absolute root (no trailing slash).
	 * @return bool
	 */
	private static function path_within( string $path, string $root ): bool {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	/**
	 * Guard against scans of arbitrary filesystem locations. Only allow
	 * paths at or below a WordPress code root (see allowed_scan_roots()).
	 *
	 * A target counts as safe when EITHER its literal path or its resolved
	 * realpath() sits under a root. The literal form matters: plugin and
	 * theme directories are frequently symlinks pointing outside wp-content
	 * (Composer/Bedrock layouts, shared plugin mounts, local development),
	 * and resolving those first made the guard reject them. The consequence
	 * was silent: the target was skipped without a notice, its strings were
	 * never re-seen, yet the run still recorded a completed full scan — so
	 * gc_stale_strings() treated last_seen_at as authoritative and deleted
	 * those strings AND their translations after the retention window.
	 * WordPress core follows symlinked plugin directories in exactly the
	 * same way (see wp_register_plugin_realpath()).
	 *
	 * Relative traversal is rejected outright so the literal comparison
	 * cannot be walked back out of a root.
	 *
	 * @param string $dir Candidate directory to scan.
	 * @return bool
	 */
	private static function is_safe_content_path( string $dir ): bool {
		if ( $dir === '' ) {
			return false;
		}

		$candidate = str_replace( '\\', '/', $dir );

		if ( $candidate === '..' || str_starts_with( $candidate, '../' ) || str_contains( $candidate, '/../' ) || str_ends_with( $candidate, '/..' ) ) {
			return false;
		}

		$real = realpath( $dir );

		if ( $real === false ) {
			return false;
		}

		foreach ( self::allowed_scan_roots() as $root ) {
			if ( self::path_within( $candidate, $root ) ) {
				return true;
			}

			$root_real = realpath( $root );

			if ( $root_real !== false && self::path_within( $real, $root_real ) ) {
				return true;
			}
		}

		return false;
	}
}
