<?php
/**
 * Background job for StringScanner.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Plugin;
use PerfLocale\Settings;
use PerfLocale\Strings\StringScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the gettext string scanner (`*.php` and `*.twig`), the slowest call outside machine translation on a large
 * install. Args: `mode` ('directory', default, or 'all': themes, active plugins, mu-plugins), `directory` (required
 * for 'directory', inside a code root), `domain` (empty = all), `batch_size` (default 500).
 */
final class StringScanJob extends AbstractJob {

	/**
	 * Scanner used for the dispatch estimate's file count.
	 *
	 * @var StringScanner|null
	 */
	private ?StringScanner $counter = null;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'string_scan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * As StringsController's REST permission.
	 */
	public function get_required_capability(): string {
		return 'perflocale_translate';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		// Small themes and a few plugins (200-600 files x 2) stay inline for immediate feedback; bigger installs go
		// async to avoid PHP-FPM timeouts. Settings > Performance > Background processing thresholds tunes it.
		return 1000;
	}

	/**
	 * {@inheritDoc}
	 *
	 * "Scan all" always runs in the background, whatever the estimate or the setting: one request cannot scan a
	 * large install, and a killed request leaves no record.
	 *
	 * @param array<string, mixed> $args     Dispatch args.
	 * @param Settings             $settings Plugin settings.
	 * @return bool
	 */
	public function should_run_async( array $args, Settings $settings ): bool {
		return 'all' === ( $args['mode'] ?? null ) || parent::should_run_async( $args, $settings );
	}

	/**
	 * Two hours: scanning all of wp-content on a heavy install can take 10-30 minutes.
	 */
	public function get_lock_ttl(): int {
		return 2 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Twice the PHP files the scan walks (nested ones included, excluded directories not), counted only up to the
	 * threshold: that directory for mode='directory', scan_all()'s targets for mode='all'.
	 */
	protected function args_size( array $args ): int {
		return $this->scan_size( $args, (int) ceil( $this->effective_threshold( $args ) / 2 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function estimated_size( array $args, int $count_to = 0 ): int {
		return $this->scan_size( $args, (int) max( ceil( $this->effective_threshold( $args ) / 2 ), ceil( $count_to / 2 ) ) );
	}

	/**
	 * The size of args_size(), counting at most $budget files.
	 *
	 * @param array<string, mixed> $args   Dispatch args.
	 * @param int                  $budget Files at which the count stops.
	 * @return int
	 */
	private function scan_size( array $args, int $budget ): int {
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'directory';

		if ( $mode === 'all' ) {
			return $this->estimate_all_targets_size( $budget );
		}

		$dir = isset( $args['directory'] ) ? (string) $args['directory'] : '';

		if ( $dir === '' || ! is_dir( $dir ) ) {
			return 0;
		}

		return 2 * $this->count_php( $dir, $budget );
	}

	/**
	 * The mode='all' estimate over the target list scan_all() walks.
	 *
	 * @param int $budget Files at which the count stops.
	 * @return int
	 */
	private function estimate_all_targets_size( int $budget ): int {
		$parent_theme = get_template_directory();
		$child_theme  = get_stylesheet_directory();
		$dirs         = [ $parent_theme ];

		if ( $child_theme !== $parent_theme ) {
			$dirs[] = $child_theme;
		}

		$dirs = array_merge( $dirs, $this->plugin_target_dirs() );

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$dirs[] = WPMU_PLUGIN_DIR;
		}

		$total = 0;

		foreach ( $dirs as $dir ) {
			if ( $total >= $budget ) {
				break;
			}

			if ( is_dir( $dir ) ) {
				$total += $this->count_php( $dir, $budget - $total );
			}
		}

		return 2 * $total;
	}

	/**
	 * PHP files the scanner would walk in one directory, up to $limit.
	 *
	 * @param string $dir   Directory.
	 * @param int    $limit Stop counting here.
	 * @return int
	 */
	private function count_php( string $dir, int $limit ): int {
		if ( null === $this->counter ) {
			$cache = Plugin::get_instance()->get( 'cache' );

			if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
				return $limit;
			}

			$this->counter = new StringScanner( $cache );
		}

		return $this->counter->count_php_files( $dir, $limit );
	}

	/**
	 * Plugin directories this scan must cover: site-active plus network-active (`active_sitewide_plugins`). A missed
	 * plugin would still arm the full-scan marker, and the 90-day GC would delete its strings and translations.
	 * Shared with estimate_all_targets_size(), so the estimate measures the set the run walks.
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
			// Skip PerfLocale itself so its strings stay out of the catalogue.
			if ( str_starts_with( (string) $plugin_file, 'perflocale/' ) ) {
				continue;
			}

			$relative = dirname( (string) $plugin_file );

			// A single-file plugin's dirname is '.', the plugins root, and the recursive scan would walk every plugin:
			// skip it (it has no directory of its own), as an empty or root dirname from a malformed entry.
			if ( $relative === '.' || $relative === '' || $relative === DIRECTORY_SEPARATOR ) {
				continue;
			}

			$plugin_dir = $plugins_root . $relative;

			if ( is_dir( $plugin_dir ) ) {
				$dirs[] = $plugin_dir;
			}
		}

		// A plugin can be both site- and network-active, and symlinks can resolve the same.
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
	 * Scans parent theme, child theme (if different), active plugins, then mu-plugins.
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

		// Parent theme, then child theme, then plugins, so child-theme overrides win when both define a string.
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

		// Duplicates in active_plugins or symlinks that resolve the same.
		$targets = array_values( array_unique( $targets ) );

		$total          = count( $targets );
		$processed      = 0;
		$total_found    = 0;
		$total_inserted = 0;
		$total_unmarked = 0;

		$progress( 0, max( 1, $total ) );

		$skipped = [];

		foreach ( $targets as $dir ) {
			if ( ! self::is_safe_content_path( $dir ) ) {
				// Record the skip: a skipped target denies full coverage (the GC marker below).
				$skipped[] = $dir;
				++$processed;
				$progress( $processed, $total );
				continue;
			}

			$r               = $scanner->scan( $dir, $domain, $batch_size );
			$total_found    += (int) ( $r['found'] ?? 0 );
			$total_inserted += (int) ( $r['inserted'] ?? 0 );
			$total_unmarked += $scanner->last_scan_unmarked_batches();

			// Part of the target could not be read: its unseen strings kept their old last_seen_at, so it denies full
			// coverage as a path-guard skip does.
			if ( ! $scanner->last_scan_complete() ) {
				$skipped[] = $dir;
			}

			++$processed;
			$progress( $processed, $total );
		}

		// Add-ons register non-gettext strings as part of the scan.
		do_action( 'perflocale/strings/after_scan' );

		// Arm the full-scan marker gc_stale_strings() depends on only when every target was scanned in full and every
		// batch re-marked as seen; otherwise the 90-day GC could delete strings and translations still in use. An
		// earlier marker still applies. Why: docs-ai/11-code-rationale.md, "StringScanJob: coverage and the GC marker".
		if ( $total_unmarked > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a partial scan.
			error_log(
				sprintf(
					'[PerfLocale] String scan could not re-mark %d batch(es) of stored strings as seen because of a database error, so this scan does not re-arm the stale-string GC. Run the scan again.',
					$total_unmarked
				)
			);
		}

		if ( $skipped === [] && $total_unmarked === 0 && $domain === '' ) {
			update_option( 'perflocale_strings_last_full_scan', time(), false );
		} elseif ( $skipped === [] && $domain !== '' ) {
			// A run filtered to one text domain re-stamped only that domain's rows, so arming the global marker would
			// let the GC delete every other domain's strings. No shipped surface sends mode='all' with a domain; this
			// guard keeps it safe.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a filtered scan.
			error_log(
				sprintf(
					'[PerfLocale] String scan was filtered to text domain "%s"; it carries no liveness signal for other domains, so this scan does not re-arm the stale-string GC.',
					$domain
				)
			);
		} elseif ( $skipped !== [] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a partial scan.
			error_log(
				sprintf(
					'[PerfLocale] String scan read %d/%d targets in full; %d were skipped or only partly read, so this scan does not re-arm the stale-string GC: %s',
					$total - count( $skipped ),
					$total,
					count( $skipped ),
					implode( ', ', array_slice( $skipped, 0, 5 ) )
				)
			);
		}

		// The database total, so callers get the post-scan row count without a transient.
		$string_repo = new StringRepository( $cache );
		$db_total    = $string_repo->count();

		// `targets` counts what was scanned in full, as the GC marker does. `skipped_targets` and a bounded path sample
		// are the operator's only signal on PerfLocale > Jobs (error_log is out of reach on managed hosts);
		// `unmarked_batches` counts batches a database error kept from being re-marked as seen.
		$result = [
			'found'            => $total_found,
			'inserted'         => $total_inserted,
			'scan_new'         => $total_inserted,
			'scan_total'       => $db_total,
			'targets'          => $total - count( $skipped ),
			'targets_total'    => $total,
			'skipped_targets'  => count( $skipped ),
			'unmarked_batches' => $total_unmarked,
		];

		if ( $skipped !== [] ) {
			// WorkerRunner::redact_paths()'s prefix substitution: the array is stored and shown, and the host's
			// directory layout is not the operator's business. A target under neither root stays whole: naming it is
			// the message.
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
				/* translators: 1: number of targets that could not be fully read, 2: total number of targets. */
				__( 'Partial scan: %1$d of %2$d targets could not be fully read, so this scan does not count as a full scan for the stale-string cleanup. Check that every plugin/theme directory resolves inside wp-content and that PHP can read all of its folders and files.', 'perflocale' ),
				count( $skipped ),
				$total
			);
		}

		return $result;
	}

	/**
	 * Roots a scan target may live under: wp-content plus the plugin, must-use plugin and theme roots WordPress
	 * reports, all relocatable. Absolute values only: get_theme_roots() returns a relative '/themes' on a single-root
	 * install.
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
	 * Only paths at or below a code root (allowed_scan_roots()): the literal path or its realpath(), so a symlinked
	 * plugin or theme dir (Bedrock, shared mounts) passes, as wp_register_plugin_realpath() follows them. Relative
	 * traversal is rejected. Why: docs-ai/11-code-rationale.md, "StringScanJob: coverage and the GC marker".
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
