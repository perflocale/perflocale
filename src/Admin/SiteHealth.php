<?php
/**
 * WordPress Site Health integration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Schema;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds PerfLocale-specific entries to WordPress' Site Health screen.
 *
 * Exposes two surfaces:
 *
 * - Status tab - individual tests (good/recommended/critical) covering
 * the configuration + runtime states that matter for translation to
 * actually work. All tests are "direct" (synchronous) and cheap.
 * - Info tab - a dedicated `perflocale` section with a diagnostic dump
 * of the whole translation stack. Useful for support tickets.
 *
 * API keys, secrets, and tokens are always redacted - the Info dump
 * reports "configured" or "not configured", never the raw value.
 *
 * Heavy lookups (row counts) are cached for five minutes in a transient
 * so repeatedly opening the Site Health screen doesn't hammer the DB.
 */
final class SiteHealth {

	/**
	 * Transient key used to cache aggregate counts.
	 */
	private const COUNTS_TRANSIENT = 'perflocale_site_health_counts';

	/**
	 * Transient key used to cache the hreflang sanity probe result.
	 */
	private const HREFLANG_TRANSIENT = 'perflocale_site_health_hreflang';

	/**
	 * Transient key used to cache the stuck-translations row-count probe.
	 * Heavy: COUNT(*) on translation_links with a status+date predicate.
	 */
	private const STUCK_TRANSIENT = 'perflocale_site_health_stuck';

	/**
	 * Transient key used to cache the orphan-rows probe (LEFT JOIN to wp_posts
	 * and wp_terms looking for translation rows whose target object vanished).
	 * Heavy: scans the entire translation_links + slug_translations tables.
	 */
	private const ORPHANS_TRANSIENT = 'perflocale_site_health_orphans';

	/**
	 * Legacy transient key, kept only so {@see self::flush_counts_cache()} can
	 * reap it. It cached the MT-quality-scoring COUNT(*) pair; that card was
	 * removed with the quality-scoring feature, so no code writes this key any
	 * more and an upgraded site is the only place a row can still exist.
	 */
	private const MT_SCORING_TRANSIENT = 'perflocale_site_health_mt_scoring';

	/**
	 * Transient key used to cache the translation-files probe COUNTs (two
	 * 4-table JOINs plus a full COUNT(*) on string_translations). Runs on
	 * every Status load in files mode without the cache.
	 */
	private const FILES_TRANSIENT = 'perflocale_site_health_files';

	/**
	 * Counts cache TTL in seconds.
	 */
	private const COUNTS_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Heavy-query cache TTL (stuck-translations + orphan-rows). Longer than
	 * COUNTS_TTL because these run full-table scans on large sites; the
	 * Site Health card surfaces last-checked timestamp so operators know
	 * how fresh the answer is.
	 */
	private const HEAVY_TTL = HOUR_IN_SECONDS;

	/**
	 * Eager-link-map autoloaded-blob size at which we start surfacing a
	 * Site Health recommendation. Per the perf-tuning notes, this option
	 * is the dominant frontend optimization but grows linearly with
	 * translation-group count; past this threshold the alloptions cost
	 * starts to dominate the savings.
	 */
	private const EAGER_LINK_MAP_WARN_BYTES = 262144; // 256 KB

	/**
	 * Upper bound on the number of language-hostname lookups
	 * test_multisite_language_hosts() will perform in one run. Each lookup is
	 * one cached WP_Site_Query; the cap keeps a network with a very long
	 * language list from turning the scheduled health check into a scan.
	 * Anything beyond the cap is reported as unchecked, never as resolving.
	 */
	private const MAX_HOST_PROBES = 20;

	/**
	 * Stuck-translation age threshold. Translations whose row was created
	 * more than this many days ago and still sit in `in_progress` or
	 * `pending` status are surfaced as a recommendation.
	 */
	private const STUCK_AGE_DAYS = 7;

	/**
	 * MT usage thresholds (as a fraction of the monthly limit).
	 */
	private const MT_WARN_THRESHOLD     = 0.80; // recommended at 80%+
	private const MT_CRITICAL_THRESHOLD = 0.95; // critical at 95%+

	/**
	 * Third-party multilingual plugin constants that conflict with us.
	 *
	 * Mirrored from Bootstrap - duplicated to keep this class decoupled
	 * from the bootstrap flow.
	 */
	private const CONFLICTING_PLUGINS = [
		'ICL_SITEPRESS_VERSION' => 'WPML',
		'POLYLANG_VERSION'      => 'Polylang',
		'TRP_PLUGIN_VERSION'    => 'TranslatePress',
	];

	/**
	 * PHP extensions that gate one optional PerfLocale feature each. Absent
	 * ones cost that feature and nothing else - the rest of the plugin runs
	 * unchanged - so this reports a recommendation, not a critical failure.
	 *
	 * Two extensions an earlier revision listed here are deliberately gone:
	 *
	 * - `mbstring`: the only multibyte calls left in the plugin are
	 *   mb_strlen() and mb_substr(), and WordPress core polyfills both in
	 *   wp-includes/compat.php at every version PerfLocale supports. Nothing
	 *   degrades without the extension, so flagging it raised a critical
	 *   failure that was never true. Core's own Site Health agrees: it marks
	 *   mbstring `required => false`.
	 * - `json`: cannot be disabled on PHP 8.0+, and this plugin's floor is
	 *   8.1, so the branch was unreachable.
	 *
	 * `xmlwriter` is new here: XLIFF export builds its document with it, and
	 * the old list omitted it while naming `simplexml`, which the plugin only
	 * ever touches through core's already-guarded sitemap renderer.
	 *
	 * @var array<int, string>
	 */
	private const FEATURE_PHP_EXTENSIONS = [ 'dom', 'libxml', 'xmlwriter', 'simplexml', 'filter', 'intl' ];

	/**
	 * MT provider API hosts for DNS reachability checks. Keyed by the
	 * provider slug returned by Settings::get_mt_provider().
	 *
	 * @var array<string, string>
	 */
	private const MT_PROVIDER_HOSTS = [
		'deepl'      => 'api.deepl.com',
		'deepl_free' => 'api-free.deepl.com',
		'google'     => 'translation.googleapis.com',
		'microsoft'  => 'api.cognitive.microsofttranslator.com',
	];

	/**
	 * API-key / secret setting names - anything listed here is redacted
	 * to "configured / not configured" in the Info dump.
	 *
	 * @var array<int, string>
	 */
	private const REDACTED_SETTINGS = [
		'mt_deepl_api_key',
		'mt_google_api_key',
		'mt_microsoft_api_key',
		'mt_libre_api_key',
		'mt_agency_api_key',
	];

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
		add_filter( 'debug_information', [ $this, 'register_info' ] );

		// When settings change, our cached counts might no longer match
		// what the user expects to see in the Info dump - best-effort
		// invalidation keeps things fresh without adding extra queries
		// on the hot path.
		add_action( 'perflocale/settings/updated', [ $this, 'flush_counts_cache' ] );

		// A string edit / PO import / bulk MT run changes the numbers behind the
		// counts, hreflang and translation-files cards. Bust their short-TTL
		// caches so a just-completed change shows fresh figures. Fires only on
		// admin / background string writes, never on a frontend request.
		add_action( 'perflocale/strings/changed', [ $this, 'flush_counts_cache' ] );
	}

	// -------------------------------------------------------------------------
	// Status tab - register tests
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, array<string, array<string, mixed>>> $tests
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function register_tests( array $tests ): array {
		$direct = [
			'perflocale_exports_exposed'   => [
				'label' => __( 'PerfLocale export files are not web-readable', 'perflocale' ),
				'test'  => [ $this, 'test_exports_not_public' ],
			],
			'perflocale_tables'            => [
				'label' => __( 'PerfLocale database tables', 'perflocale' ),
				'test'  => [ $this, 'test_tables_exist' ],
			],
			'perflocale_db_version'        => [
				'label' => __( 'PerfLocale database schema version', 'perflocale' ),
				'test'  => [ $this, 'test_db_version' ],
			],
			'perflocale_php_extensions'    => [
				'label' => __( 'PerfLocale PHP extensions', 'perflocale' ),
				'test'  => [ $this, 'test_php_extensions' ],
			],
			'perflocale_default_language'  => [
				'label' => __( 'PerfLocale default language', 'perflocale' ),
				'test'  => [ $this, 'test_default_language' ],
			],
			'perflocale_string_mode_scale' => [
				'label' => __( 'PerfLocale string translation scale', 'perflocale' ),
				'test'  => [ $this, 'test_string_mode_scale' ],
			],
			'perflocale_rewrite_rules'     => [
				'label' => __( 'PerfLocale rewrite rules', 'perflocale' ),
				'test'  => [ $this, 'test_rewrite_rules' ],
			],
			'perflocale_multisite_hosts'    => [
				'label' => __( 'PerfLocale multisite language hosts', 'perflocale' ),
				'test'  => [ $this, 'test_multisite_language_hosts' ],
			],
			'perflocale_conflicting'       => [
				'label' => __( 'PerfLocale plugin conflicts', 'perflocale' ),
				'test'  => [ $this, 'test_conflicting_plugin' ],
			],
			'perflocale_addon_quarantine'  => [
				'label' => __( 'PerfLocale addon health', 'perflocale' ),
				'test'  => [ $this, 'test_addon_quarantine' ],
			],
			'perflocale_addon_schema'      => [
				'label' => __( 'PerfLocale addon schema', 'perflocale' ),
				'test'  => [ $this, 'test_addon_schema_drift' ],
			],
			'perflocale_mt_usage'          => [
				'label' => __( 'PerfLocale MT usage', 'perflocale' ),
				'test'  => [ $this, 'test_mt_usage' ],
			],
			'perflocale_mt_reachability'   => [
				'label' => __( 'PerfLocale MT provider reachability', 'perflocale' ),
				'test'  => [ $this, 'test_mt_reachability' ],
			],
			'perflocale_fx_staleness'      => [
				'label' => __( 'PerfLocale exchange-rate freshness', 'perflocale' ),
				'test'  => [ $this, 'test_fx_stale' ],
			],
			'perflocale_translation_files' => [
				'label' => __( 'PerfLocale translation files', 'perflocale' ),
				'test'  => [ $this, 'test_translation_files' ],
			],
			'perflocale_uploads_writable'  => [
				'label' => __( 'PerfLocale translation files directory', 'perflocale' ),
				'test'  => [ $this, 'test_uploads_writable' ],
			],
			'perflocale_object_cache'      => [
				'label' => __( 'PerfLocale object cache', 'perflocale' ),
				'test'  => [ $this, 'test_object_cache' ],
			],
			'perflocale_hreflang_output'   => [
				'label' => __( 'PerfLocale hreflang output', 'perflocale' ),
				'test'  => [ $this, 'test_hreflang_output' ],
			],
			'perflocale_bg_jobs_health'    => [
				'label' => __( 'PerfLocale background-jobs health', 'perflocale' ),
				'test'  => [ $this, 'test_bg_jobs_health' ],
			],

			'perflocale_circuit_breakers'  => [
				'label' => __( 'PerfLocale circuit breakers', 'perflocale' ),
				'test'  => [ $this, 'test_circuit_breakers' ],
			],
			'perflocale_eager_link_map'    => [
				'label' => __( 'PerfLocale eager-link-map state', 'perflocale' ),
				'test'  => [ $this, 'test_eager_link_map' ],
			],
			'perflocale_cron_schedule'     => [
				'label' => __( 'PerfLocale background cron schedule', 'perflocale' ),
				'test'  => [ $this, 'test_cron_schedule' ],
			],
			'perflocale_stuck_translations' => [
				'label' => __( 'PerfLocale stuck translations', 'perflocale' ),
				'test'  => [ $this, 'test_stuck_translations' ],
			],
			'perflocale_orphan_rows'       => [
				'label' => __( 'PerfLocale orphan translation rows', 'perflocale' ),
				'test'  => [ $this, 'test_orphan_rows' ],
			],
		];

		foreach ( $direct as $id => $entry ) {
			$tests['direct'][ $id ] = $entry;
		}

		return $tests;
	}

	// -------------------------------------------------------------------------
	// Status tests
	// -------------------------------------------------------------------------

	/**
	 * Are all of the plugin's custom tables present?
	 *
	 * @return array<string, mixed>
	 */
	public function test_tables_exist(): array {
		if ( Schema::tables_exist() ) {
			return $this->pass(
				'perflocale_tables',
				__( 'PerfLocale database tables are present', 'perflocale' ),
				__( 'All required PerfLocale database tables exist. Translation storage is healthy.', 'perflocale' )
			);
		}

		return $this->critical(
			'perflocale_tables',
			__( 'PerfLocale database tables are missing', 'perflocale' ),
			__( 'One or more PerfLocale tables weren\'t created. This usually happens after restoring a backup from a time before the plugin was activated, or when the database user lacks CREATE TABLE privileges. Deactivate and reactivate the plugin to recreate them.', 'perflocale' )
		);
	}

	/**
	 * Is there a language marked as default?
	 *
	 * @return array<string, mixed>
	 */
	public function test_default_language(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return $this->pass(
				'perflocale_default_language',
				__( 'Default language check skipped', 'perflocale' ),
				__( 'Service container not fully booted - check again after the page loads.', 'perflocale' )
			);
		}

		$repo    = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$default = $repo->get_default();

		if ( $default && ! empty( $default->slug ) ) {
			return $this->pass(
				'perflocale_default_language',
				__( 'Default language is set', 'perflocale' ),
				sprintf(
					/* translators: %s: language slug */
					__( 'Default language: %s. Translation routing will fall back to this language when a visitor\'s preference isn\'t available.', 'perflocale' ),
					'<code>' . esc_html( $default->slug ) . '</code>'
				)
			);
		}

		return $this->critical(
			'perflocale_default_language',
			__( 'No default language is set', 'perflocale' ),
			__( 'PerfLocale cannot resolve URLs without a default language. Add a language in PerfLocale → Languages and mark it as default.', 'perflocale' )
		);
	}

	/**
	 * In subdirectory URL mode, are language-prefixed rewrite rules present?
	 *
	 * @return array<string, mixed>
	 */
	public function test_rewrite_rules(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_rewrite_rules',
				__( 'Rewrite rule check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$mode = $plugin->get( 'settings' )->get_url_mode();

		// Only subdirectory mode depends on rewrite rules.
		if ( $mode !== 'subdirectory' ) {
			return $this->pass(
				'perflocale_rewrite_rules',
				__( 'URL mode doesn\'t require rewrite rules', 'perflocale' ),
				sprintf(
					/* translators: %s: URL mode label */
					__( 'Current URL mode: %s. Rewrite rules are only used in subdirectory mode.', 'perflocale' ),
					'<code>' . esc_html( $mode ) . '</code>'
				)
			);
		}

		$rules = get_option( 'rewrite_rules' );

		if ( is_array( $rules ) ) {
			// RewriteManager writes the filtered query-variable name, so the
			// needle has to be built the same way or a renamed site reports
			// "rewrite rules missing" while they are present and correct.
			$lang_needle = \PerfLocale\Router\UrlConverter::query_var() . '=$matches[1]';

			foreach ( $rules as $rewrite ) {
				if ( is_string( $rewrite ) && str_contains( $rewrite, $lang_needle ) ) {
					return $this->pass(
						'perflocale_rewrite_rules',
						__( 'Language-prefixed rewrite rules are active', 'perflocale' ),
						__( 'Rewrite rules contain PerfLocale language prefixes - translated URLs resolve correctly.', 'perflocale' )
					);
				}
			}
		}

		// Plain permalinks: the language prefix is parsed from the raw request
		// URI (no rewrite rules involved) and object URLs use the ?p= form, so
		// the plugin works — PROVIDED the web server routes prefixed paths like
		// /de/ to WordPress. nginx catch-all configs do; Apache without the
		// standard WordPress .htaccess block returns a server-level 404 before
		// PHP runs. Pretty permalinks make WordPress maintain that routing
		// itself, which is why they are the recommended fix either way.
		if ( (string) get_option( 'permalink_structure' ) === '' ) {
			global $is_apache;

			$routing_uncertain = $is_apache && ! got_url_rewrite();

			return $this->recommended(
				'perflocale_rewrite_rules',
				$routing_uncertain
					? __( 'Plain permalinks on Apache may break language URLs', 'perflocale' )
					: __( 'Plain permalinks work, but pretty permalinks are recommended', 'perflocale' ),
				$routing_uncertain
					? __( 'This site uses Plain permalinks on Apache without URL rewriting available. Language-prefixed URLs such as /de/ can return a server-level 404 before WordPress loads. Enable pretty permalinks (which sets up the routing automatically), or ensure the standard WordPress .htaccess rewrite block is present.', 'perflocale' )
					: __( 'This site uses Plain permalinks. Language URLs work as long as the server routes all paths to WordPress (typical for nginx and Apache with the WordPress .htaccess block). Enabling pretty permalinks makes WordPress guarantee that routing itself.', 'perflocale' ),
				sprintf(
					'<p><a href="%s">%s</a></p>',
					esc_url( admin_url( 'options-permalink.php' ) ),
					esc_html__( 'Go to Permalinks', 'perflocale' )
				)
			);
		}

		return $this->recommended(
			'perflocale_rewrite_rules',
			__( 'Language-prefixed rewrite rules are missing', 'perflocale' ),
			__( 'Visit Settings → Permalinks and click Save to force WordPress to flush rewrite rules. Without this, translated URLs will return 404.', 'perflocale' ),
			sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'options-permalink.php' ) ),
				esc_html__( 'Go to Permalinks', 'perflocale' )
			)
		);
	}

	/**
	 * On multisite, do the hostnames that `subdomain` / `domain` URL mode
	 * generates actually resolve to a site in the network?
	 *
	 * This is not a URL-builder check - the builders are correct, and
	 * tools/regression-tests/mu-url-modes.php asserts that `apply_subdomain()`
	 * produces `de.sub.example.com` and that `detect_from_subdomain()` reads it
	 * back. The failure sits upstream of the plugin entirely: on multisite,
	 * ms-settings.php resolves the requested hostname against the network's
	 * site list (`get_site_by_path()`) BEFORE a single plugin file is loaded.
	 * A language hostname with no site row never reaches PHP-land at all - the
	 * visitor gets core's "site does not exist" screen or a wp-signup.php
	 * redirect, and no plugin code can intercept that.
	 *
	 * Registering those hostnames as network sites - or mapping them with a
	 * domain-mapping plugin, which hooks the same `pre_get_site_by_path` filter
	 * this test calls through - is a legitimate configuration. So the test
	 * RESOLVES each hostname for real instead of pattern-matching the setting:
	 * a network where the operator did that work reports good.
	 *
	 * Cost: zero queries on single sites and in subdirectory / query URL mode -
	 * both return before the first lookup. Otherwise one WP_Site_Query per
	 * DISTINCT language hostname, capped at self::MAX_HOST_PROBES, each cached
	 * in core's `site-queries` group - so the `wp_scheduled_health_check` run
	 * hits a warm cache. No HTTP request and no DNS lookup: whether the host
	 * resolves in DNS is the operator's business, not something a health check
	 * should block on.
	 *
	 * @return array<string, mixed>
	 */
	public function test_multisite_language_hosts(): array {
		$id     = 'perflocale_multisite_hosts';
		$report = self::language_host_report();

		if ( ! $report['applies'] ) {
			return $this->pass( $id, $report['label'], $report['description'] );
		}

		$mode      = $report['mode'];
		$total     = $report['total'];
		$probes    = $report['checked'];
		$unchecked = $total - count( $probes );

		if ( $report['unresolved'] !== [] ) {
			$listed = [];

			foreach ( $report['unresolved'] as $unresolved_host ) {
				$listed[] = '<code>' . esc_html( $unresolved_host ) . '</code>';
			}

			return $this->critical(
				$id,
				__( 'Language hostnames are not registered in this network', 'perflocale' ),
				sprintf(
					/* translators: 1: URL mode label, 2: comma-separated list of hostnames */
					esc_html__( 'PerfLocale is in %1$s URL mode and generates language hostnames this network cannot resolve: %2$s. On multisite, WordPress matches the requested hostname against the network\'s site list before any plugin loads, and none of these is registered there - a visitor following a translated link gets WordPress\'s "site does not exist" screen instead of a translation. Either add each hostname as a site in the network (and point its DNS or server alias at this install), or switch PerfLocale to subdirectory or query URL mode, which keep this site\'s hostname.', 'perflocale' ),
					'<code>' . esc_html( $mode ) . '</code>',
					implode( ', ', $listed )
				) . self::unchecked_note( $unchecked ),
				sprintf(
					'<p><a href="%1$s">%2$s</a></p><p><a href="%3$s">%4$s</a></p>',
					esc_url( network_admin_url( 'site-new.php' ) ),
					esc_html__( 'Add a site to the network', 'perflocale' ),
					esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=url-routing' ) ),
					esc_html__( 'Change the URL mode', 'perflocale' )
				)
			);
		}

		$listed = [];

		foreach ( $probes as $target ) {
			$listed[] = '<code>' . esc_html( $target ) . '</code>';
		}

		// Every hostname was checked: the only case in which "they resolve" is
		// a statement about all of them.
		if ( $unchecked === 0 ) {
			return $this->pass(
				$id,
				__( 'Language hostnames resolve to sites in this network', 'perflocale' ),
				sprintf(
					/* translators: 1: URL mode label, 2: number of hostnames checked, 3: comma-separated list of hostnames */
					esc_html__( 'PerfLocale is in %1$s URL mode and all %2$d language hostname(s) it generates resolve to a site registered in this network: %3$s. Requests for translated URLs reach WordPress instead of the "site does not exist" screen.', 'perflocale' ),
					'<code>' . esc_html( $mode ) . '</code>',
					count( $probes ),
					implode( ', ', $listed )
				)
			);
		}

		// The probe cap bit. Reporting `good` here would be a card claiming
		// hostnames "resolve" about hostnames it never looked at - the exact
		// silent-success shape this test exists to remove. Say what was
		// actually checked, and take the status down to `recommended`, because
		// partial knowledge is not a pass.
		return $this->recommended(
			$id,
			sprintf(
				/* translators: 1: number of hostnames checked, 2: total number of hostnames generated */
				__( 'Checked %1$d of %2$d language hostnames', 'perflocale' ),
				count( $probes ),
				$total
			),
			sprintf(
				/* translators: 1: URL mode label, 2: number of hostnames checked, 3: total generated, 4: comma-separated list of hostnames */
				esc_html__( 'PerfLocale is in %1$s URL mode and generates %3$d language hostnames. This test resolves at most %2$d of them per run so it stays cheap enough for every scheduled health check, and the ones it checked all resolve to a site in this network: %4$s. The remaining hostnames were NOT checked - if a visitor gets WordPress\'s "site does not exist" screen on a translated link, verify by hand that every language hostname is registered as a site in the network.', 'perflocale' ),
				'<code>' . esc_html( $mode ) . '</code>',
				count( $probes ),
				$total,
				implode( ', ', $listed )
			),
			sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( network_admin_url( 'sites.php' ) ),
				esc_html__( 'Review the network\'s site list', 'perflocale' )
			)
		);
	}

	/**
	 * Trailing sentence naming the hostnames a run did not get to.
	 *
	 * @param int $unchecked How many hostnames were left unprobed.
	 * @return string HTML fragment, or an empty string when nothing was skipped.
	 */
	private static function unchecked_note( int $unchecked ): string {
		if ( $unchecked < 1 ) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: %d: number of language hostnames left unchecked */
			esc_html__( '%d further language hostname(s) were not checked, so this test stays cheap enough to run on every scheduled health check.', 'perflocale' ),
			$unchecked
		);
	}

	/**
	 * Resolve every language hostname this URL mode generates against the
	 * network's site list, and report exactly what was looked at.
	 *
	 * Extracted so the Site Health card and the URL-mode warning on the
	 * Settings screen ask the same question of the same code. The Status tab
	 * only fires when somebody opens it; an operator who switches to subdomain
	 * or domain mode breaks their network at SAVE time, so
	 * {@see \PerfLocale\Admin\Pages\SettingsPage::render()} runs this too.
	 *
	 * Cost: zero queries on single sites and in subdirectory / query URL mode -
	 * both return `applies => false` before the first lookup. Otherwise one
	 * WP_Site_Query per DISTINCT language hostname, capped at
	 * self::MAX_HOST_PROBES, each cached in core's `site-queries` group. No
	 * HTTP request and no DNS lookup: whether the host resolves in DNS is the
	 * operator's business, not something a health check should block on.
	 *
	 * @return array{applies:bool,mode:string,total:int,checked:array<int,string>,unresolved:array<int,string>,label:string,description:string}
	 *         `applies` false means there is nothing to resolve, and `label` /
	 *         `description` carry the reason; otherwise `total` counts every
	 *         DISTINCT hostname generated, `checked` lists the ones actually
	 *         probed (at most MAX_HOST_PROBES of them) and `unresolved` the
	 *         subset of THOSE with no site row.
	 */
	public static function language_host_report(): array {
		$report = [
			'applies'     => false,
			'mode'        => '',
			'total'       => 0,
			'checked'     => [],
			'unresolved'  => [],
			'label'       => '',
			'description' => '',
		];

		// Single site: WordPress serves whatever hostname reaches it, so no
		// hostname the plugin generates can fail to resolve for this reason.
		if ( ! is_multisite() ) {
			$report['label']       = __( 'Language hostname check does not apply', 'perflocale' );
			$report['description'] = __( 'This is a single-site install. WordPress serves whichever hostname reaches it, so PerfLocale\'s URL modes never depend on a network site list.', 'perflocale' );

			return $report;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			$report['label']       = __( 'Language hostname check skipped', 'perflocale' );
			$report['description'] = __( 'Required services not available.', 'perflocale' );

			return $report;
		}

		$settings       = $plugin->settings();
		$mode           = $settings->get_url_mode();
		$report['mode'] = $mode;

		// Only the two host-rewriting modes can generate a hostname that is
		// not this site's own. Subdirectory and query mode keep the host.
		if ( $mode !== 'subdomain' && $mode !== 'domain' ) {
			$report['label']       = __( 'URL mode is safe on a multisite network', 'perflocale' );
			$report['description'] = sprintf(
				/* translators: %s: URL mode label */
				__( 'Current URL mode: %s. Translated URLs keep this site\'s hostname, so the network resolves them exactly as it resolves an untranslated one.', 'perflocale' ),
				'<code>' . esc_html( $mode ) . '</code>'
			);

			return $report;
		}

		// Build each hostname the way UrlConverter does. apply_subdomain()
		// derives its base from home_url() with the converter's own `home_url`
		// filter suppressed by its re-entrancy guard, which is the stored
		// `home` option - read that directly so this test cannot be rewritten
		// by the very mode it is checking.
		$home      = (string) get_option( 'home' );
		$home_host = wp_parse_url( $home, PHP_URL_HOST );
		$host      = is_string( $home_host ) ? strtolower( $home_host ) : '';

		// ms-settings.php strips `:80` / `:443` from the request host and
		// nothing else, so a nonstandard port (staging, local installs) stays
		// part of the authority WordPress looks up - and UrlConverter leaves
		// that port on the rewritten URL. The lookup has to carry it too.
		$home_port   = wp_parse_url( $home, PHP_URL_PORT );
		$port_suffix = ( is_int( $home_port ) && 80 !== $home_port && 443 !== $home_port ) ? ':' . $home_port : '';

		$repo         = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->cache() );
		$default      = $repo->get_default();
		$default_slug = ( $default !== null && ! empty( $default->slug ) ) ? (string) $default->slug : '';

		/**
		 * Hostnames to probe, deduplicated - two languages can be configured
		 * with the same domain, and one probe answers for both.
		 *
		 * @var array<string, string> Hostname => language slug.
		 */
		$targets = [];

		foreach ( $repo->get_active() as $language ) {
			$slug = ! empty( $language->slug ) ? (string) $language->slug : '';

			if ( $slug === '' ) {
				continue;
			}

			if ( $mode === 'subdomain' ) {
				// apply_subdomain() leaves the default language on the base
				// host, which is by definition this site's own registered one.
				if ( $slug === $default_slug || $host === '' ) {
					continue;
				}

				$target = strtolower( $slug . '.' . $host . $port_suffix );
			} else {
				// apply_domain() has NO default-language exemption: a domain
				// configured for the default language replaces the base host
				// too, and is just as unresolvable when it is not registered.
				$target = self::normalize_authority( $settings->get_language_domain( $slug ) );

				if ( $target === '' || $target === $host . $port_suffix ) {
					continue;
				}
			}

			if ( ! isset( $targets[ $target ] ) ) {
				$targets[ $target ] = $slug;
			}
		}

		if ( $targets === [] ) {
			$report['label']       = __( 'No language-specific hostnames are generated', 'perflocale' );
			$report['description'] = sprintf(
				/* translators: %s: URL mode label */
				esc_html__( 'PerfLocale is in %s URL mode but this site generates no language-specific hostname, so there is nothing for the network to resolve. In domain mode that also means every language shares one hostname - set a domain per language on the URL & Routing settings tab if that is not intended.', 'perflocale' ),
				'<code>' . esc_html( $mode ) . '</code>'
			);

			return $report;
		}

		$report['applies'] = true;
		$report['total']   = count( $targets );

		$probes = array_slice( $targets, 0, self::MAX_HOST_PROBES, true );

		// The path half of the lookup: `/` on a subdomain network, `/sub/` on
		// a subdirectory one. Taken from wp_blogs via get_site() rather than
		// from a URL, because that is the column ms-settings.php matches on.
		$current_site = get_site();
		$site_path    = ( $current_site !== null && $current_site->path !== '' ) ? $current_site->path : '/';

		foreach ( array_keys( $probes ) as $target ) {
			$target              = (string) $target;
			$report['checked'][] = $target;

			// The exact call ms_load_current_site_and_network() makes, filter
			// hooks included - so a domain-mapped hostname reports resolvable
			// here for the same reason it resolves in a real request.
			if ( empty( get_site_by_path( $target, $site_path ) ) ) {
				$report['unresolved'][] = $target;
			}
		}

		return $report;
	}

	/**
	 * Reduce a configured per-language domain to the authority WordPress would
	 * compare against the network's site list.
	 *
	 * Operators paste schemes and trailing slashes into that settings field,
	 * and apply_domain() substitutes the value verbatim into the URL - so the
	 * hostname a visitor actually requests is whatever survives the browser's
	 * own parsing. Mirror that: take the host, lowercase it, and keep a
	 * nonstandard port, because ms-settings.php strips only `:80` and `:443`.
	 *
	 * @param string $domain Configured domain, possibly with scheme or path.
	 * @return string Authority (host, optionally `host:port`), or '' when the
	 *                value carries no hostname at all.
	 */
	private static function normalize_authority( string $domain ): string {
		$domain = trim( $domain );

		if ( $domain === '' ) {
			return '';
		}

		// wp_parse_url() reads a bare `example.fr` as a PATH, so give it a
		// protocol-relative prefix before asking for the host.
		if ( ! str_contains( $domain, '//' ) ) {
			$domain = '//' . $domain;
		}

		$parsed = wp_parse_url( $domain );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$authority = strtolower( (string) $parsed['host'] );
		$port      = $parsed['port'] ?? null;

		if ( is_int( $port ) && 80 !== $port && 443 !== $port ) {
			$authority .= ':' . $port;
		}

		return $authority;
	}

	/**
	 * Detect conflicting multilingual plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function test_conflicting_plugin(): array {
		foreach ( self::CONFLICTING_PLUGINS as $constant => $name ) {
			if ( defined( $constant ) ) {
				return $this->critical(
					'perflocale_conflicting',
					sprintf(
						/* translators: %s: conflicting plugin name */
						__( 'Conflicting multilingual plugin: %s', 'perflocale' ),
						$name
					),
					sprintf(
						/* translators: %s: conflicting plugin name */
						__( 'PerfLocale cannot run while %s is active - they would fight over URL routing, query filtering, and post meta. Deactivate one of the two.', 'perflocale' ),
						'<strong>' . esc_html( $name ) . '</strong>'
					)
				);
			}
		}

		return $this->pass(
			'perflocale_conflicting',
			__( 'No conflicting multilingual plugins detected', 'perflocale' ),
			__( 'PerfLocale is the only multilingual plugin active. Routing and translation filtering run without contention.', 'perflocale' )
		);
	}

	/**
	 * Surface quarantined addons.
	 *
	 * @return array<string, mixed>
	 */
	public function test_addon_quarantine(): array {
		$registry = $this->addon_registry();

		if ( $registry === null ) {
			return $this->pass(
				'perflocale_addon_quarantine',
				__( 'Addon registry not loaded', 'perflocale' ),
				__( 'Addon health can\'t be checked right now - try again after the page fully loads.', 'perflocale' )
			);
		}

		$ids = $registry->get_quarantined_ids();

		if ( empty( $ids ) ) {
			return $this->pass(
				'perflocale_addon_quarantine',
				__( 'PerfLocale addons are running normally', 'perflocale' ),
				__( 'No PerfLocale addons have been auto-disabled. All active integrations are booting without errors.', 'perflocale' )
			);
		}

		$labels = $this->resolve_addon_labels( $ids, $registry );
		$items  = '';

		foreach ( $ids as $id ) {
			$items .= '<li><code>' . esc_html( $labels[ $id ] ?? $id ) . '</code></li>';
		}

		return $this->recommended(
			'perflocale_addon_quarantine',
			__( 'One or more PerfLocale addons are disabled', 'perflocale' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				esc_html__( 'The following addons have been auto-disabled after three consecutive boot failures:', 'perflocale' ),
				$items,
				esc_html__( 'Check the error log for the root cause, then click "Retry" in the PerfLocale admin notice to restore them.', 'perflocale' )
			),
			sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=perflocale-addons' ) ),
				esc_html__( 'Go to PerfLocale Addons', 'perflocale' )
			)
		);
	}

	/**
	 * Warn when monthly machine-translation usage is close to the limit.
	 *
	 * @return array<string, mixed>
	 */
	public function test_mt_usage(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_mt_usage',
				__( 'MT usage check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$settings = $plugin->get( 'settings' );

		if ( ! $settings->mt_enabled() ) {
			return $this->pass(
				'perflocale_mt_usage',
				__( 'Machine translation is disabled', 'perflocale' ),
				__( 'No MT usage to track while machine translation is off.', 'perflocale' )
			);
		}

		$limit = (int) $settings->get( 'mt_monthly_char_limit', 500000 );

		if ( $limit === 0 ) {
			return $this->pass(
				'perflocale_mt_usage',
				__( 'Machine translation is unlimited', 'perflocale' ),
				__( 'The monthly character limit is set to 0 (unlimited) - no threshold warnings apply.', 'perflocale' )
			);
		}

		$usage   = (int) get_option( 'perflocale_mt_usage_' . gmdate( 'Y_m' ), 0 );
		$percent = $limit > 0 ? ( $usage / $limit ) : 0.0;

		$formatted = sprintf(
			/* translators: 1: used characters, 2: limit, 3: percentage */
			__( '%1$s / %2$s characters used this month (%3$s).', 'perflocale' ),
			number_format_i18n( $usage ),
			number_format_i18n( $limit ),
			number_format_i18n( $percent * 100, 1 ) . '%'
		);

		if ( $percent >= self::MT_CRITICAL_THRESHOLD ) {
			return $this->critical(
				'perflocale_mt_usage',
				__( 'Machine-translation usage is near the monthly limit', 'perflocale' ),
				$formatted . '<br>' . esc_html__( 'Further translation requests will fail until next month or until you raise the limit in Settings → Addons → Machine Translation.', 'perflocale' )
			);
		}

		if ( $percent >= self::MT_WARN_THRESHOLD ) {
			return $this->recommended(
				'perflocale_mt_usage',
				__( 'Machine-translation usage is high', 'perflocale' ),
				$formatted . '<br>' . esc_html__( 'You\'re on track to hit the monthly limit. Review upcoming bulk translations or raise the limit.', 'perflocale' )
			);
		}

		return $this->pass(
			'perflocale_mt_usage',
			__( 'Machine-translation usage is within limits', 'perflocale' ),
			$formatted
		);
	}

	/**

	/**
	 * Aggregate circuit-breaker status across all wired callsites: MT providers
	 * ({@see \PerfLocale\MachineTranslation\AbstractProvider}), webhook
	 * delivery ({@see \PerfLocale\Api\WebhookController}), WooCommerce FX sync
	 * and geo-IP providers. Those four are the whole list — nothing else in the
	 * plugin opens a breaker.
	 *
	 * Three states surfaced as Site Health categories:
	 *   - All CLOSED → green pass card with the count of registered breakers.
	 *   - Any HALF_OPEN (probing) → recommended (yellow) so the operator
	 *     knows recovery is in progress but not yet confirmed.
	 *   - Any OPEN → critical (red) with cooldown countdowns + a manual
	 *     reset URL per open breaker.
	 *
	 * Manual reset is gated by capability + nonce (see
	 * {@see handle_reset_breaker_action} in AdminController).
	 *
	 * @return array<string, mixed>
	 */
	public function test_circuit_breakers(): array {
		// Class may not yet be autoloaded on tiny pre-init Site Health
		// probes — defensive guard so the test is a clean no-op.
		if ( ! class_exists( '\\PerfLocale\\Concurrency\\Breaker' ) ) {
			return $this->pass(
				'perflocale_circuit_breakers',
				__( 'Circuit breakers inactive', 'perflocale' ),
				__( 'The PerfLocale circuit-breaker subsystem is not loaded.', 'perflocale' )
			);
		}

		$breakers = \PerfLocale\Concurrency\Breaker::list_all();

		if ( $breakers === [] ) {
			return $this->pass(
				'perflocale_circuit_breakers',
				__( 'No active circuit breakers', 'perflocale' ),
				__( 'Circuit breakers protect external calls (MT providers, webhook receivers, FX APIs, geo-IP services). None have tripped recently; the table is empty.', 'perflocale' )
			);
		}

		$open      = [];
		$half_open = [];
		$closed    = 0;

		foreach ( $breakers as $key => $status ) {
			$state = (string) ( $status['state'] ?? 'closed' );

			if ( $state === 'open' ) {
				$open[ $key ] = $status;
			} elseif ( $state === 'half_open' ) {
				$half_open[ $key ] = $status;
			} else {
				++$closed;
			}
		}

		if ( $open !== [] ) {
			$desc  = '<p>' . esc_html__( 'One or more circuit breakers are OPEN — calls to the listed external services are being refused to prevent piling retries onto a failing dependency.', 'perflocale' ) . '</p>';
			$desc .= '<ul>';
			foreach ( $open as $key => $status ) {
				$reason    = (string) ( $status['reason'] ?? 'unknown' );
				$retry_in  = (int) ( $status['cooldown_remaining'] ?? 0 );
				$reset_url = self::breaker_reset_url( $key );

				$desc .= sprintf(
					'<li><code>%1$s</code> — %2$s (%3$s) — %4$s</li>',
					esc_html( $key ),
					esc_html(
						sprintf(
							/* translators: %s: reason category */
							__( 'reason: %s', 'perflocale' ),
							$reason
						)
					),
					esc_html(
						sprintf(
							/* translators: %d: seconds */
							__( 'retry in %ds', 'perflocale' ),
							$retry_in
						)
					),
					$reset_url !== ''
						? sprintf(
							'<a href="%1$s">%2$s</a>',
							esc_url( $reset_url ),
							esc_html__( 'Reset now', 'perflocale' )
						)
						: ''
				);
			}
			$desc .= '</ul>';

			return $this->critical(
				'perflocale_circuit_breakers',
				sprintf(
					/* translators: %d: count of open circuit breakers */
					_n(
						'%d circuit breaker is OPEN',
						'%d circuit breakers are OPEN',
						count( $open ),
						'perflocale'
					),
					count( $open )
				),
				$desc
			);
		}

		if ( $half_open !== [] ) {
			$keys = implode( ', ', array_map( 'esc_html', array_keys( $half_open ) ) );

			return $this->recommended(
				'perflocale_circuit_breakers',
				sprintf(
					/* translators: %d: count of probing circuit breakers */
					_n(
						'%d circuit breaker is probing recovery',
						'%d circuit breakers are probing recovery',
						count( $half_open ),
						'perflocale'
					),
					count( $half_open )
				),
				sprintf(
					/* translators: %s: comma-separated list of breaker keys */
					esc_html__( 'The following breakers cooled down and are now allowing the next call as a probe: %s. If the probe succeeds the breaker closes; if it fails it re-opens for another cooldown cycle.', 'perflocale' ),
					$keys
				)
			);
		}

		return $this->pass(
			'perflocale_circuit_breakers',
			__( 'All circuit breakers closed', 'perflocale' ),
			sprintf(
				/* translators: %d: count of closed breakers */
				_n(
					'%d breaker is tracked and operating normally.',
					'%d breakers are tracked and operating normally.',
					$closed,
					'perflocale'
				),
				$closed
			)
		);
	}

	/**
	 * Build a nonce-protected admin-post URL the operator can hit to
	 * force-close one breaker. Returns empty string when the current
	 * user lacks the manage cap so the link doesn't render at all
	 * (defence-in-depth — the handler also checks).
	 *
	 * @param string $key Breaker key.
	 * @return string
	 */
	private static function breaker_reset_url( string $key ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				[
					'action' => 'perflocale_reset_breaker',
					'key'    => rawurlencode( $key ),
				],
				admin_url( 'admin-post.php' )
			),
			'perflocale_reset_breaker_' . $key
		);
	}

	/**
	 * When WooCommerce auto-FX is enabled, warn if rates are stale.
	 *
	 * @return array<string, mixed>
	 */
	public function test_fx_stale(): array {
		if ( ! class_exists( '\\PerfLocale\\WooCommerce\\ExchangeRateSync' ) ) {
			return $this->pass(
				'perflocale_fx_staleness',
				__( 'Exchange-rate sync inactive', 'perflocale' ),
				__( 'WooCommerce is not active or the exchange-rate sync module is not loaded.', 'perflocale' )
			);
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_fx_staleness',
				__( 'FX freshness check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$settings = $plugin->get( 'settings' );

		if ( ! (bool) $settings->get( 'wc_exchange_rate_auto', false ) ) {
			return $this->pass(
				'perflocale_fx_staleness',
				__( 'Automatic exchange-rate sync is disabled', 'perflocale' ),
				__( 'Exchange rates are managed manually - no freshness warning applies.', 'perflocale' )
			);
		}

		$sync = new \PerfLocale\WooCommerce\ExchangeRateSync( $settings );

		if ( ! $sync->is_stale() ) {
			$last = (array) get_option( \PerfLocale\WooCommerce\ExchangeRateSync::LAST_SYNC_OPTION, [] );
			$ts   = (int) ( $last['timestamp'] ?? 0 );

			return $this->pass(
				'perflocale_fx_staleness',
				__( 'Exchange rates are up to date', 'perflocale' ),
				$ts > 0
					? sprintf(
						/* translators: 1: human-readable time diff, 2: absolute timestamp in site locale + timezone */
						esc_html__( 'Last synced %1$s ago (%2$s).', 'perflocale' ),
						esc_html( human_time_diff( $ts ) ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $ts ) )
					)
					: esc_html__( 'Last sync timestamp unavailable but no staleness detected.', 'perflocale' )
			);
		}

		$last      = (array) get_option( \PerfLocale\WooCommerce\ExchangeRateSync::LAST_SYNC_OPTION, [] );
		$ts        = (int) ( $last['timestamp'] ?? 0 );
		$last_sync = $ts > 0
			? sprintf(
				/* translators: 1: human-readable time diff (e.g. "2 hours"), 2: absolute timestamp formatted in site's locale + timezone */
				__( '%1$s ago (%2$s)', 'perflocale' ),
				human_time_diff( $ts ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $ts )
			)
			: __( 'never', 'perflocale' );

		// A stale state with no rate source wired is a different problem with a
		// different remedy: every sync path aborts before the network call, so
		// pointing the operator at WP-Cron would be a dead end.
		if ( ! $sync->has_rate_source() ) {
			return $this->recommended(
				'perflocale_fx_staleness',
				__( 'No exchange-rate source is configured', 'perflocale' ),
				sprintf(
					/* translators: 1: time since last sync, 2: providers filter name, 3: rates filter name */
					esc_html__( 'Automatic exchange-rate sync is on, but no rate source is configured, so rates cannot update (last sync: %1$s). No provider ships with the plugin: register one with the %2$s filter, supply rates directly with the %3$s filter, or turn auto-sync off in the currency settings.', 'perflocale' ),
					'<code>' . esc_html( $last_sync ) . '</code>',
					'<code>perflocale/woocommerce/exchange_rate_providers</code>',
					'<code>perflocale/woocommerce/exchange_rates_fetched</code>'
				)
			);
		}

		return $this->recommended(
			'perflocale_fx_staleness',
			__( 'Exchange rates are stale', 'perflocale' ),
			sprintf(
				/* translators: %s: time since last sync */
				esc_html__( 'Exchange rates have not been updated recently (last sync: %s). Check that WP-Cron is running, or sync manually from the currency settings.', 'perflocale' ),
				'<code>' . esc_html( $last_sync ) . '</code>'
			)
		);
	}

	/**
	 * Warn when database string mode holds so many per-language rows that
	 * the whole-map blob (all_string_translations_{lang}) becomes a
	 * per-request memory/CPU cost.
	 *
	 * Database mode loads a language's ENTIRE string-translation map as one
	 * serialized blob: StringTranslation::activate() preloads it eagerly once
	 * language detection completes on a request whose detected language is
	 * not the default (default-language requests skip the preload and the
	 * gettext filters), the gettext path reloads it lazily if the memo was
	 * reset (switch_blog), and each fallback in the current language's
	 * chain adds one more map. At an estimated ~200 bytes/entry serialized
	 * that is 4-10 MB at 20k-50k rows, and the unserialized in-request
	 * footprint is several times larger. Files mode avoids this — it serves
	 * opcache-compiled, per-domain .l10n.php files. We RECOMMEND switching
	 * (never auto-switch: files mode needs a writable uploads dir, and DB
	 * mode is a legitimate choice on read-only filesystems).
	 *
	 * @return array<string, mixed>
	 */
	public function test_string_mode_scale(): array {
		$id     = 'perflocale_string_mode_scale';
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass( $id, __( 'String-scale check skipped', 'perflocale' ), __( 'Settings service not available.', 'perflocale' ) );
		}

		$mode = (string) $plugin->get( 'settings' )->get( 'string_translation_mode', 'files' );

		if ( $mode !== 'database' ) {
			return $this->pass(
				$id,
				__( 'String translations are served from compiled files', 'perflocale' ),
				__( 'Files mode serves each language/domain from an opcache-compiled file, so the string count does not affect per-request memory.', 'perflocale' )
			);
		}

		/**
		 * Rows-per-language at which the DB-mode whole-map blob is worth a
		 * warning. Default 20,000 (~4-6 MB serialized). Filterable for
		 * sites with unusual row sizes.
		 *
		 * @hook perflocale/site_health/string_blob_threshold
		 * @param int $threshold Rows per language.
		 */
		$threshold = (int) apply_filters( 'perflocale/site_health/string_blob_threshold', 20000 );

		// The heaviest single language decides the verdict. That query
		// (GROUP BY on an unindexed LONGTEXT predicate) full-scans the very
		// large table this check targets, and Site Health runs direct tests
		// synchronously on every status render — so cache it for an hour.
		$max_rows = get_transient( 'perflocale_string_blob_max' );

		if ( false === $max_rows ) {
			global $wpdb;
			$st_table = \PerfLocale\Database\Schema::table( 'string_translations' );

			// $st_table is Schema::table() (class-owned identifier), no user
			// input, no values to bind.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$max_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) AS c FROM %i WHERE translation <> '' GROUP BY language_id ORDER BY c DESC LIMIT 1",
					$st_table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			set_transient( 'perflocale_string_blob_max', $max_rows, HOUR_IN_SECONDS );
		}

		$max_rows = (int) $max_rows;

		if ( $max_rows < $threshold ) {
			return $this->pass(
				$id,
				__( 'Database string mode is within a comfortable size', 'perflocale' ),
				sprintf(
					/* translators: 1: row count, 2: threshold */
					esc_html__( 'The largest language holds %1$s translated strings, under the %2$s recommended for database mode.', 'perflocale' ),
					number_format_i18n( $max_rows ),
					number_format_i18n( $threshold )
				)
			);
		}

		$actions = sprintf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=performance' ) ),
			esc_html__( 'Open PerfLocale performance settings', 'perflocale' )
		);

		return $this->recommended(
			$id,
			__( 'Database string mode is loading a large per-request blob', 'perflocale' ),
			sprintf(
				/* translators: 1: row count, 2: threshold */
				esc_html__( 'The largest language holds %1$s translated strings (over %2$s). In database mode, PerfLocale loads a language\'s entire string map on the first translated string of every page request. Switching the string-translation mode to "Files" serves each language and text domain from an opcache-compiled file instead, removing that per-request cost. Files mode requires a writable uploads directory.', 'perflocale' ),
				number_format_i18n( $max_rows ),
				number_format_i18n( $threshold )
			),
			$actions
		);
	}

	/**
	 * When string translation mode is "files", verify the compiled
	 * `.l10n.php` files exist and are not stale relative to the newest
	 * translation row.
	 *
	 * The check has four outcomes:
	 *  - **DB mode**: no files needed, always pass.
	 *  - **Files mode, no string translations in DB**: nothing to compile,
	 *    pass with an explanatory note (this fixes the historical false-
	 *    positive where a fresh install always saw "regenerate" forever).
	 *  - **Files mode, translations exist but no compiled file**:
	 *    recommended; expose the regenerate link.
	 *  - **Files mode, files exist but the newest translation is newer
	 *    than the oldest file**: recommended (stale); expose the link.
	 *
	 * @return array<string, mixed>
	 */
	public function test_translation_files(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_translation_files',
				__( 'Translation-file check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$mode = (string) $plugin->get( 'settings' )->get( 'string_translation_mode', 'files' );

		if ( $mode !== 'files' ) {
			return $this->pass(
				'perflocale_translation_files',
				__( 'String translation mode is not file-based', 'perflocale' ),
				__( 'Strings resolve directly from the database - no compiled files to keep in sync.', 'perflocale' )
			);
		}

		// Resolve the canonical directory from the generator itself so this
		// test can't drift from the generator's actual output.
		$generator = new \PerfLocale\Strings\TranslationFileGenerator( $plugin->get( 'cache' ) );
		$files_dir = $generator->get_translations_dir();

		$files       = is_dir( $files_dir ) ? glob( $files_dir . '/*.l10n.php' ) : [];
		$file_count  = is_array( $files ) ? count( $files ) : 0;
		$oldest_file = 0;

		if ( $file_count > 0 ) {
			$oldest_file = PHP_INT_MAX;
			foreach ( $files as $f ) {
				$mtime = (int) filemtime( $f );
				if ( $mtime > 0 && $mtime < $oldest_file ) {
					$oldest_file = $mtime;
				}
			}
			if ( $oldest_file === PHP_INT_MAX ) {
				$oldest_file = 0;
			}
		}

		// "Translations exist" here MUST match what the generator can actually
		// write, so it mirrors the generator's JOIN walk:
		// strings → translation_groups (type='string') → translation_links
		// → string_translations (via string_id + language_id)
		// Counting string_translations rows alone would include orphans the
		// generator skips, leaving the "regenerate → 0 files" notice stuck.
		//
		// Two 4-table JOIN COUNTs plus a full COUNT(*) is heavy on large sites,
		// so cache the trio on a short TTL (busted on settings-updated and
		// strings-changed) instead of re-running them on every Status load.
		$cached = get_transient( self::FILES_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['compilable'], $cached['total_st'], $cached['newest'] ) ) {
			$compilable_translations = (int) $cached['compilable'];
			$total_st_rows           = (int) $cached['total_st'];
			$newest_translation      = (int) $cached['newest'];
		} else {
			global $wpdb;
			$strings_table = \PerfLocale\Database\Schema::table( 'strings' );
			$groups_table  = \PerfLocale\Database\Schema::table( 'translation_groups' );
			$links_table   = \PerfLocale\Database\Schema::table( 'translation_links' );
			$st_table      = \PerfLocale\Database\Schema::table( 'string_translations' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Compilable: at least one fully-joinable row the generator would
			// emit. Note we DO require st.translation <> '' because the
			// generator skips empty values.
			$compilable_translations = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i s
					INNER JOIN %i g ON g.id = s.group_id AND g.type = 'string'
					INNER JOIN %i l ON l.group_id = s.group_id
					INNER JOIN %i st ON st.string_id = s.id AND st.language_id = l.language_id
					WHERE st.translation <> ''",
					$strings_table,
					$groups_table,
					$links_table,
					$st_table
				)
			);

			// Total rows in `string_translations`. The difference between this
			// and `compilable_translations` is the orphan count — surfaced as a
			// separate diagnostic so users know something needs cleanup instead
			// of staring at a button that does nothing.
			$total_st_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i',
					$st_table
				)
			);

			// Newest row across the compilable set (for staleness check).
			$newest_translation = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT UNIX_TIMESTAMP(MAX(st.updated_at)) FROM %i s
					INNER JOIN %i g ON g.id = s.group_id AND g.type = 'string'
					INNER JOIN %i l ON l.group_id = s.group_id
					INNER JOIN %i st ON st.string_id = s.id AND st.language_id = l.language_id
					WHERE st.translation <> ''",
					$strings_table,
					$groups_table,
					$links_table,
					$st_table
				)
			);
			// phpcs:enable

			set_transient(
				self::FILES_TRANSIENT,
				[
					'compilable' => $compilable_translations,
					'total_st'   => $total_st_rows,
					'newest'     => $newest_translation,
				],
				self::COUNTS_TTL
			);
		}

		$translations_exist = $compilable_translations > 0;
		$orphan_count       = max( 0, $total_st_rows - $compilable_translations );

		$regen_link = sprintf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=performance' ) ),
			esc_html__( 'Go to Performance settings', 'perflocale' )
		);

		// Case 1: no compilable translations and no files. If there are
		// orphan rows, tell the user — plain English, one number, one
		// button. The "Regenerate translation files" action self-heals
		// orphans before generating, so a single click fixes both the
		// stranded data AND the missing files.
		if ( ! $translations_exist && $file_count === 0 ) {
			if ( $orphan_count > 0 ) {
				return $this->recommended(
					'perflocale_translation_files',
					__( 'Some translations need to be re-linked', 'perflocale' ),
					sprintf(
						/* translators: %d: number of stranded translations */
						esc_html__( '%d saved translation(s) are not connected to the file-generation pipeline, so the compiled files cannot be produced yet. Click "Regenerate translation files" - it will reconnect them and write the files in one step.', 'perflocale' ),
						$orphan_count
					),
					$regen_link
				);
			}
			return $this->pass(
				'perflocale_translation_files',
				__( 'No translation files needed yet', 'perflocale' ),
				__( 'File-based mode is enabled but no string translations have been added yet, so there is nothing to compile. Files will generate automatically once you translate a string.', 'perflocale' )
			);
		}

		// Case 2: translations exist but no .l10n.php files on disk.
		if ( $translations_exist && $file_count === 0 ) {
			return $this->recommended(
				'perflocale_translation_files',
				__( 'Compiled translation files are missing', 'perflocale' ),
				__( 'String translations exist in the database but no compiled `.l10n.php` files were found. Files normally regenerate automatically when a translation is saved - this may indicate a file-permission problem on the uploads directory.', 'perflocale' ),
				$regen_link
			);
		}

		// Case 3: files exist; check staleness (newest translation newer
		// than oldest file by more than a short grace window).
		if ( $newest_translation > 0 && $oldest_file > 0 && ( $newest_translation - $oldest_file ) > 60 ) {
			return $this->recommended(
				'perflocale_translation_files',
				__( 'Translation files are stale', 'perflocale' ),
				sprintf(
					/* translators: 1: count, 2: newest translation absolute time, 3: oldest file absolute time */
					esc_html__( '%1$d compiled file(s) on disk, but the newest translation row (%2$s) is newer than the oldest file (%3$s). Regenerate so the file cache reflects the current DB state.', 'perflocale' ),
					$file_count,
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $newest_translation ) ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $oldest_file ) )
				),
				$regen_link
			);
		}

		// Case 4: healthy.
		return $this->pass(
			'perflocale_translation_files',
			__( 'Translation files are present', 'perflocale' ),
			sprintf(
				/* translators: 1: file count, 2: filesystem path */
				esc_html__( '%1$d compiled `.l10n.php` file(s) at %2$s.', 'perflocale' ),
				$file_count,
				'<code>' . esc_html( str_replace( ABSPATH, '', $files_dir ) ) . '</code>'
			)
		);
	}

	/**
	 * The stored `perflocale_db_version` option must match the
	 * `PERFLOCALE_DB_VERSION` constant shipped with this release. A
	 * mismatch means activation didn't finish or a migration is pending.
	 *
	 * @return array<string, mixed>
	 */
	public function test_db_version(): array {
		$expected = defined( 'PERFLOCALE_DB_VERSION' ) ? (int) PERFLOCALE_DB_VERSION : 0;
		$stored   = (int) get_option( 'perflocale_db_version', 0 );

		if ( $expected === 0 ) {
			return $this->pass(
				'perflocale_db_version',
				__( 'Database schema version check skipped', 'perflocale' ),
				__( 'The plugin version constant is not defined yet - the page may have loaded before bootstrap finished.', 'perflocale' )
			);
		}

		if ( $stored === 0 ) {
			return $this->critical(
				'perflocale_db_version',
				__( 'Database schema has never been installed', 'perflocale' ),
				__( 'The `perflocale_db_version` option is missing. This usually means the activation hook did not run. Deactivate and reactivate the plugin to create the tables and stamp the version.', 'perflocale' )
			);
		}

		if ( $stored < $expected ) {
			return $this->critical(
				'perflocale_db_version',
				__( 'A pending database migration was not applied', 'perflocale' ),
				sprintf(
					/* translators: 1: stored version, 2: expected version */
					__( 'Stored schema version %1$d is behind the expected version %2$d. Deactivate and reactivate the plugin, or run `wp perflocale db-migrate`, so the migration completes.', 'perflocale' ),
					$stored,
					$expected
				)
			);
		}

		if ( $stored > $expected ) {
			return $this->recommended(
				'perflocale_db_version',
				__( 'Database schema is newer than the installed plugin', 'perflocale' ),
				sprintf(
					/* translators: 1: stored version, 2: expected version */
					__( 'The database reports schema version %1$d but the installed plugin expects %2$d. This usually indicates a plugin downgrade. Re-install the newer version or the schema may not match what the code expects.', 'perflocale' ),
					$stored,
					$expected
				)
			);
		}

		return $this->pass(
			'perflocale_db_version',
			__( 'Database schema is up to date', 'perflocale' ),
			sprintf(
				/* translators: %d: schema version */
				__( 'Schema version %d matches the installed plugin.', 'perflocale' ),
				$stored
			)
		);
	}

	/**
	 * Report which optional PHP extensions are absent, and name the single
	 * feature each one costs.
	 *
	 * Never critical. Every code path behind these extensions now checks for
	 * them and raises a readable exception, so a missing one narrows what the
	 * plugin can do without breaking what it already does.
	 *
	 * @return array<string, mixed>
	 */
	public function test_php_extensions(): array {
		// Kept next to the constant rather than inside it so the feature names
		// stay translatable - __() cannot run in a class constant.
		$features = [
			'dom'       => __( 'XLIFF import', 'perflocale' ),
			'libxml'    => __( 'XLIFF import', 'perflocale' ),
			'xmlwriter' => __( 'XLIFF export', 'perflocale' ),
			'simplexml' => __( 'XML sitemaps (WordPress core needs this one too)', 'perflocale' ),
			'filter'    => __( 'machine translation and webhooks (their address checks fail closed without it)', 'perflocale' ),
			'intl'      => __( 'locale-aware number and currency formatting (WordPress formatting is used instead without it)', 'perflocale' ),
		];

		$missing = [];

		foreach ( self::FEATURE_PHP_EXTENSIONS as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}

		if ( $missing === [] ) {
			return $this->pass(
				'perflocale_php_extensions',
				__( 'Optional PHP extensions are all available', 'perflocale' ),
				sprintf(
					/* translators: %s: comma-separated extension list */
					esc_html__( 'Every optional extension PerfLocale can use is installed: %s.', 'perflocale' ),
					'<code>' . esc_html( implode( ', ', self::FEATURE_PHP_EXTENSIONS ) ) . '</code>'
				)
			);
		}

		$items = '';

		foreach ( $missing as $ext ) {
			$items .= sprintf(
				'<li><code>%1$s</code> - %2$s</li>',
				esc_html( $ext ),
				esc_html( $features[ $ext ] ?? '' )
			);
		}

		return $this->recommended(
			'perflocale_php_extensions',
			__( 'Some optional PHP extensions are not installed', 'perflocale' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				esc_html__( 'PerfLocale runs without these. Each one you add enables the feature listed beside it; everything else works either way.', 'perflocale' ),
				$items,
				esc_html__( 'On most hosts all of them arrive together in the php-xml package.', 'perflocale' )
			)
		);
	}

	/**
	 * When any booted addon implements HasSchema and its stored_version
	 * is behind its declared schema version, a migration is queued but
	 * has not yet been applied - surface that loudly.
	 *
	 * @return array<string, mixed>
	 */
	public function test_addon_schema_drift(): array {
		$registry = $this->addon_registry();

		if ( $registry === null ) {
			return $this->pass(
				'perflocale_addon_schema',
				__( 'Addon registry not loaded', 'perflocale' ),
				__( 'Addon schema drift can\'t be checked right now - try again after the page fully loads.', 'perflocale' )
			);
		}

		$drift = [];

		foreach ( $registry->get_addons() as $id => $addon ) {
			if ( ! $addon instanceof \PerfLocale\Addon\HasSchema ) {
				continue;
			}

			if ( ! $registry->is_booted( $id ) ) {
				continue;
			}

			try {
				$declared = (int) $addon->get_schema_version();
				$stored   = \PerfLocale\Addon\AddonSchemaManager::get_stored_version( $id );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( $stored < $declared ) {
				$drift[] = [
					'id'       => $id,
					'stored'   => $stored,
					'declared' => $declared,
				];
			}
		}

		if ( $drift === [] ) {
			return $this->pass(
				'perflocale_addon_schema',
				__( 'Addon schemas are up to date', 'perflocale' ),
				__( 'Every active addon that manages its own tables is at the version declared in its code.', 'perflocale' )
			);
		}

		$items = '';

		foreach ( $drift as $entry ) {
			$items .= sprintf(
				'<li><code>%1$s</code>: %2$s</li>',
				esc_html( $entry['id'] ),
				esc_html(
					sprintf(
					/* translators: 1: stored version, 2: declared version */
						__( 'stored %1$d, declared %2$d', 'perflocale' ),
						$entry['stored'],
						$entry['declared']
					)
				)
			);
		}

		return $this->recommended(
			'perflocale_addon_schema',
			__( 'One or more PerfLocale addons have pending schema migrations', 'perflocale' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				esc_html__( 'The following addons declare a newer schema version than what is stored. Their tables may be missing recent columns or indexes:', 'perflocale' ),
				$items,
				esc_html__( 'Deactivate and reactivate the affected addons, or run `wp perflocale addon migrate <id>` for each one, to bring their schemas current.', 'perflocale' )
			)
		);
	}

	/**
	 * DNS-level reachability check for the active MT provider's API host.
	 * Only flags configuration problems where the host can't be resolved
	 * at all (egress firewall, DNS misconfig); actual auth/rate-limit
	 * errors surface in the MT request path instead.
	 *
	 * @return array<string, mixed>
	 */
	public function test_mt_reachability(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_mt_reachability',
				__( 'MT reachability check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$settings = $plugin->get( 'settings' );

		if ( ! $settings->mt_enabled() ) {
			return $this->pass(
				'perflocale_mt_reachability',
				__( 'Machine translation is disabled', 'perflocale' ),
				__( 'No provider reachability test runs while MT is off.', 'perflocale' )
			);
		}

		$provider = (string) $settings->get_mt_provider();
		$host     = self::MT_PROVIDER_HOSTS[ $provider ] ?? '';

		if ( $host === '' ) {
			return $this->pass(
				'perflocale_mt_reachability',
				__( 'MT provider reachability check skipped', 'perflocale' ),
				sprintf(
					/* translators: %s: provider slug */
					esc_html__( 'No DNS endpoint is registered for provider "%s" - this test covers only hosted providers.', 'perflocale' ),
					esc_html( $provider )
				)
			);
		}

		$resolved = gethostbyname( $host );

		if ( $resolved === $host ) {
			return $this->recommended(
				'perflocale_mt_reachability',
				__( 'The configured MT provider host does not resolve', 'perflocale' ),
				sprintf(
					/* translators: 1: host, 2: provider slug */
					esc_html__( 'DNS lookup for %1$s (provider: %2$s) returned no address. This usually means an outbound firewall or a container without DNS. Translations to this provider will fail.', 'perflocale' ),
					'<code>' . esc_html( $host ) . '</code>',
					'<code>' . esc_html( $provider ) . '</code>'
				)
			);
		}

		return $this->pass(
			'perflocale_mt_reachability',
			__( 'MT provider host resolves', 'perflocale' ),
			sprintf(
				/* translators: 1: host, 2: IP */
				esc_html__( '%1$s resolved to %2$s. Auth and rate-limit errors, if any, will appear at request time.', 'perflocale' ),
				'<code>' . esc_html( $host ) . '</code>',
				'<code>' . esc_html( $resolved ) . '</code>'
			)
		);
	}

	/**
	 * When string translation mode is "files", the upload target must be
	 * writable or regeneration silently skips the site.
	 *
	 * @return array<string, mixed>
	 */
	public function test_uploads_writable(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_uploads_writable',
				__( 'Uploads writable check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$mode = (string) $plugin->get( 'settings' )->get( 'string_translation_mode', 'files' );

		if ( $mode !== 'files' ) {
			return $this->pass(
				'perflocale_uploads_writable',
				__( 'Translation-files directory not required', 'perflocale' ),
				__( 'String translation mode is not file-based, so no writable directory is needed.', 'perflocale' )
			);
		}

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return $this->critical(
				'perflocale_uploads_writable',
				__( 'The WordPress uploads directory is not available', 'perflocale' ),
				sprintf(
					/* translators: %s: error reported by wp_upload_dir */
					esc_html__( 'WordPress reported: %s', 'perflocale' ),
					'<code>' . esc_html( (string) $upload_dir['error'] ) . '</code>'
				)
			);
		}

		// The generator writes .l10n.php bundles into
		// uploads/perflocale/translations/ (Helper::uploads_translations_dir) —
		// NOT 'perflocale-languages/', which is only an admin-page slug and no
		// filesystem code ever touches. Probing the real dir (or its writable
		// parent when it doesn't exist yet) is what actually gates files mode.
		$files_dir = trailingslashit( \PerfLocale\Helper::uploads_translations_dir() );
		$check_dir = is_dir( $files_dir ) ? $files_dir : $upload_dir['basedir'];

		if ( ! wp_is_writable( $check_dir ) ) {
			return $this->critical(
				'perflocale_uploads_writable',
				__( 'Translation-files directory is not writable', 'perflocale' ),
				sprintf(
					/* translators: %s: filesystem path */
					esc_html__( 'PerfLocale cannot write compiled .l10n.php files into %s. Ask your host to make the uploads directory writable by PHP, or switch Settings → Performance → String translation mode to "Database".', 'perflocale' ),
					'<code>' . esc_html( str_replace( ABSPATH, '', $check_dir ) ) . '</code>'
				)
			);
		}

		return $this->pass(
			'perflocale_uploads_writable',
			__( 'Translation-files directory is writable', 'perflocale' ),
			sprintf(
				/* translators: %s: filesystem path */
				esc_html__( 'PerfLocale can write compiled .l10n.php translation files into %s.', 'perflocale' ),
				'<code>' . esc_html( str_replace( ABSPATH, '', $check_dir ) ) . '</code>'
			)
		);
	}

	/**
	 * When the operator has enabled the object cache layer but no
	 * external backend (Redis, Memcached, etc.) is present, WordPress'
	 * default object cache is per-request only - every page view still
	 * hits the database.
	 *
	 * The mirror image is worse and used to report green: with a backend
	 * present and the setting switched OFF, L2 and L3 are both out (see
	 * the branch below), leaving no persistent caching at all.
	 *
	 * @return array<string, mixed>
	 */
	public function test_object_cache(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $this->pass(
				'perflocale_object_cache',
				__( 'Object cache check skipped', 'perflocale' ),
				__( 'Settings service not available.', 'perflocale' )
			);
		}

		$enabled = (bool) $plugin->get( 'settings' )->get( 'cache_object_enabled', true );

		if ( ! $enabled ) {
			// Unchecking the setting does not merely skip L2. CacheManager
			// writes its L3 transient fallback to wp_options only while
			// wp_using_ext_object_cache() is false - with a drop-in present
			// transients live in the object cache's shared `transient`
			// group, which no PerfLocale group flush can reach, so the L3
			// write is skipped by design. Setting off + drop-in present
			// therefore leaves the site with NO persistent caching at all:
			// every language, translation and slug lookup is recomputed on
			// every request. That is a real, invisible performance cliff, so
			// say so rather than reporting green.
			if ( wp_using_ext_object_cache() ) {
				return $this->recommended(
					'perflocale_object_cache',
					__( 'PerfLocale is caching nothing between requests', 'perflocale' ),
					__( 'This site has a persistent object cache (Redis, Memcached, or equivalent), but PerfLocale\'s "Object Cache" setting is unchecked. With a drop-in installed PerfLocale skips its database transient fallback entirely, so this setting is its only persistent layer - unchecking it leaves no persistent caching at all, and every language, translation and slug lookup is recomputed on each request. Re-enable it under Settings → Performance → Object Cache unless you are deliberately isolating PerfLocale from a misbehaving cache backend.', 'perflocale' )
				);
			}

			return $this->pass(
				'perflocale_object_cache',
				__( 'PerfLocale object cache is disabled in settings', 'perflocale' ),
				__( 'Object cache look-ups are skipped by configuration - no backend-compatibility warning applies. This site has no persistent object cache, so PerfLocale still caches to transients in the database.', 'perflocale' )
			);
		}

		if ( wp_using_ext_object_cache() ) {
			return $this->pass(
				'perflocale_object_cache',
				__( 'External object cache detected', 'perflocale' ),
				__( 'PerfLocale\'s cached lookups are backed by a persistent object cache (Redis, Memcached, or equivalent).', 'perflocale' )
			);
		}

		return $this->recommended(
			'perflocale_object_cache',
			__( 'Object cache is enabled in settings but no persistent backend is active', 'perflocale' ),
			__( 'PerfLocale is configured to use the object cache, but WordPress is running with the default per-request cache. Install Redis Object Cache or a comparable drop-in for the performance benefits - or disable the setting under Settings → Performance.', 'perflocale' )
		);
	}

	/**
	 * Background-jobs health: surface conditions that would prevent
	 * dispatched jobs from running. Specifically:
	 *
	 *   - Daily GC cron is scheduled (otherwise stuck jobs never recover,
	 *     stale lock rows accumulate, completed jobs never get pruned).
	 *   - With WP-Cron forced or AS unavailable AND DISABLE_WP_CRON is on,
	 *     warn that an external cron is needed to drain the queue.
	 *   - Queue isn't paused (informational — not a real problem, just
	 *     surfaces an operator-set state so it shows up in support
	 *     pastes when someone asks "why aren't my jobs running").
	 *
	 * @return array<string, mixed>
	 */
	public function test_bg_jobs_health(): array {
		// Engine-agnostic, any-args probe: the GC event lives in Action
		// Scheduler (with a blog-id arg) when AS is available, so a bare
		// wp_next_scheduled() false-alarms on every AS site.
		$gc_scheduled = \PerfLocale\Background\BackgroundEvents::is_scheduled( 'perflocale_jobs_gc' );
		$engine       = \PerfLocale\Background\JobRunnerFactory::pick()->get_engine_name();
		$cron_off     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$plugin       = Plugin::get_instance();
		$paused       = $plugin->has( 'settings' )
			&& (bool) $plugin->get( 'settings' )->get( 'background_paused', false );

		// Hardest case first: GC cron missing.
		if ( ! $gc_scheduled ) {
			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'Background-jobs garbage collection is not scheduled', 'perflocale' ),
				__( 'The daily perflocale_jobs_gc cron event is missing. Stuck jobs and stale lock rows will not be cleaned up. PerfLocale re-registers it automatically on any admin page load (checked at most once every 6 hours); saving a PerfLocale setting forces an immediate re-check.', 'perflocale' )
			);
		}

		// Worst-cron case: WP-Cron engine + DISABLE_WP_CRON + no external trigger.
		if ( $engine === 'wp_cron' && $cron_off ) {
			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'WP-Cron is disabled and no Action Scheduler is loaded', 'perflocale' ),
				__( 'Background jobs are enqueued in WP-Cron but DISABLE_WP_CRON is set. Configure an external system cron to hit wp-cron.php every minute, install WooCommerce or the Action Scheduler plugin, or jobs will sit in the queue indefinitely.', 'perflocale' )
			);
		}

		if ( $paused ) {
			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'Background-jobs queue is paused', 'perflocale' ),
				__( 'Workers are re-queueing jobs every 5 minutes instead of running them. Visit PerfLocale → Settings → Performance and uncheck "Pause queue" to resume processing.', 'perflocale' )
			);
		}

		return $this->pass(
			'perflocale_bg_jobs_health',
			__( 'Background jobs are healthy', 'perflocale' ),
			sprintf(
				/* translators: 1: engine name (action_scheduler / wp_cron) */
				__( 'GC cron scheduled, engine: %1$s, queue not paused.', 'perflocale' ),
				$engine
			)
		);
	}

	/**
	 * When hreflang output is enabled, sanity-check the public homepage
	 * emits at least one `<link rel="alternate" hreflang>` per active
	 * language. Cached for 5 minutes alongside the counts transient.
	 *
	 * @return array<string, mixed>
	 */
	public function test_hreflang_output(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return $this->pass(
				'perflocale_hreflang_output',
				__( 'Hreflang check skipped', 'perflocale' ),
				__( 'Required services not available.', 'perflocale' )
			);
		}

		$settings = $plugin->get( 'settings' );

		if ( ! $settings->hreflang_enabled() ) {
			return $this->pass(
				'perflocale_hreflang_output',
				__( 'Hreflang output is disabled in settings', 'perflocale' ),
				__( 'No hreflang tags are expected, so this check is skipped.', 'perflocale' )
			);
		}

		$repo         = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$active_count = count( $repo->get_active() );

		if ( $active_count < 2 ) {
			return $this->pass(
				'perflocale_hreflang_output',
				__( 'Hreflang check skipped (fewer than 2 active languages)', 'perflocale' ),
				__( 'Hreflang tags are only meaningful on sites with at least two active languages.', 'perflocale' )
			);
		}

		$cached = get_transient( self::HREFLANG_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['count'], $cached['expected'] ) ) {
			return $this->hreflang_result( (int) $cached['count'], (int) $cached['expected'] );
		}

		$response = wp_remote_get(
			home_url( '/' ),
			[
				'timeout'     => 5,
				'redirection' => 2,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name (defined in WP_Http::request). Renaming would break integration with WP core + third-party plugins that expect this canonical hook.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [ 'X-PerfLocale-Health-Check' => '1' ],
			]
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return $this->recommended(
				'perflocale_hreflang_output',
				__( 'Homepage could not be fetched for the hreflang sanity check', 'perflocale' ),
				__( 'The Site Health probe could not reach the homepage over HTTP, so hreflang output could not be verified. This is usually a loopback-block or a self-signed-certificate issue.', 'perflocale' )
			);
		}

		// Count BOTH placements. With seo_hreflang_placement = http_header the
		// tags are emitted only as Link: headers and the body carries none, so a
		// body-only probe would report zero and hand the operator theme/caching
		// advice for a correctly configured site.
		$body  = (string) wp_remote_retrieve_body( $response );
		$count = preg_match_all( '#<link\s+[^>]*rel=["\']alternate["\'][^>]*hreflang=["\'][^"\']+["\']#i', $body );
		$count = is_int( $count ) ? $count : 0;

		// HreflangTags emits its Link headers with $replace = false, so
		// wp_remote_retrieve_header() can hand back either a single string or an
		// array of them depending on how many were sent — normalise both shapes.
		$link_header = wp_remote_retrieve_header( $response, 'link' );
		$link_values = is_array( $link_header ) ? $link_header : ( '' !== (string) $link_header ? [ (string) $link_header ] : [] );

		foreach ( $link_values as $link_value ) {
			$header_hits = preg_match_all( '#rel=["\']?alternate["\']?[^,]*hreflang=#i', (string) $link_value );
			$count      += is_int( $header_hits ) ? $header_hits : 0;
		}

		set_transient(
			self::HREFLANG_TRANSIENT,
			[
				'count'    => $count,
				'expected' => $active_count,
			],
			self::COUNTS_TTL
		);

		return $this->hreflang_result( $count, $active_count );
	}

	/**
	 * Format the Site-Health result row for the hreflang test. Kept
	 * separate so the cached + fresh paths share wording.
	 *
	 * @param int $count Number of hreflang alternate links found.
	 * @param int $expected Number of active languages.
	 * @return array<string, mixed>
	 */
	private function hreflang_result( int $count, int $expected ): array {
		if ( $count === 0 ) {
			return $this->recommended(
				'perflocale_hreflang_output',
				__( 'No hreflang alternate links detected on the homepage', 'perflocale' ),
				__( 'Hreflang output is enabled in settings but the homepage did not render any `<link rel="alternate" hreflang>` tags. Check theme or cache rules that might strip head elements, and make sure the homepage is translatable.', 'perflocale' )
			);
		}

		if ( $count < $expected ) {
			return $this->recommended(
				'perflocale_hreflang_output',
				__( 'Hreflang output is thinner than the active-language list', 'perflocale' ),
				sprintf(
					/* translators: 1: links found, 2: active languages */
					esc_html__( 'The homepage rendered %1$d hreflang link(s) but %2$d languages are active. Translations for missing languages may not be linked yet, or a caching layer is serving a pre-translation copy.', 'perflocale' ),
					$count,
					$expected
				)
			);
		}

		return $this->pass(
			'perflocale_hreflang_output',
			__( 'Hreflang output matches the active-language list', 'perflocale' ),
			sprintf(
				/* translators: %d: hreflang link count */
				esc_html__( 'Homepage rendered %d hreflang alternate link(s) - search engines can discover all active languages.', 'perflocale' ),
				$count
			)
		);
	}

	// -------------------------------------------------------------------------
	// Info tab - dedicated PerfLocale section
	// -------------------------------------------------------------------------

	/**
	 * Add PerfLocale's own section to the Site Health Info tab.
	 *
	 * `$info` is deliberately untyped. WordPress does `$info = apply_filters(
	 * 'debug_information', $info ); return $info;` and never re-validates the
	 * result, so a third-party callback written like an action — edit the array,
	 * forget the `return` — hands the NEXT callback null. Core survives that
	 * (site-health-info.php's foreach warns and renders nothing); an `array`
	 * hint here would make PHP throw a TypeError at ARGUMENT BINDING, before any
	 * guard in this body could run, turning somebody else's missing `return`
	 * into a fatal on Tools -> Site Health with PerfLocale's name on it. The
	 * guard returns the value UNCHANGED — coercing it to an array would hide the
	 * other plugin's bug and silently drop whatever the previous callbacks had
	 * assembled.
	 *
	 * `register_tests()` is deliberately NOT widened the same way: core's
	 * `site_status_tests` filter is immediately followed by an `array_merge()`
	 * on the result, so a non-array there is a fatal with or without this
	 * plugin, and a guard would only move the stack trace.
	 *
	 * @param mixed $info Debug-information sections as the previous callbacks left them; an array in every supported case.
	 * @return mixed The same value, with PerfLocale's section added when it was an array.
	 */
	public function register_info( mixed $info ): mixed {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $info;
		}

		$settings = $plugin->get( 'settings' );
		$fields   = [];

		// ---- Plugin / schema ----
		$fields['version']    = [
			'label' => __( 'Version', 'perflocale' ),
			'value' => defined( 'PERFLOCALE_VERSION' ) ? PERFLOCALE_VERSION : __( 'unknown', 'perflocale' ),
		];
		$fields['db_version'] = [
			'label' => __( 'Database schema version', 'perflocale' ),
			'value' => (string) get_option( 'perflocale_db_version', '-' ),
		];
		$fields['multisite']  = [
			'label' => __( 'Multisite', 'perflocale' ),
			'value' => is_multisite() ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['tables']     = [
			'label' => __( 'Tables present', 'perflocale' ),
			'value' => Schema::tables_exist() ? __( 'Yes', 'perflocale' ) : __( 'No - needs repair', 'perflocale' ),
		];

		// ---- Routing & languages ----
		$fields['url_mode']           = [
			'label' => __( 'URL mode', 'perflocale' ),
			'value' => (string) $settings->get_url_mode(),
		];
		$fields['url_prefix_type']    = [
			'label' => __( 'URL prefix type', 'perflocale' ),
			'value' => (string) $settings->get_url_prefix_type(),
		];
		$fields['hide_default']       = [
			'label' => __( 'Hide prefix for default language', 'perflocale' ),
			'value' => $settings->hide_default_prefix() ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['detection_order']    = [
			'label' => __( 'Language detection order', 'perflocale' ),
			'value' => implode( ' → ', (array) $settings->get_detection_order() ),
		];
		$fields['redirect_browser']   = [
			'label' => __( 'Redirect by browser language', 'perflocale' ),
			'value' => (bool) $settings->get( 'redirect_browser_lang' ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['redirect_geo']       = [
			'label' => __( 'Redirect by GeoIP', 'perflocale' ),
			'value' => (bool) $settings->get( 'redirect_geo_enabled' ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['redirect_edge_hint'] = [
			'label' => __( 'Redirect by edge hint', 'perflocale' ),
			'value' => (bool) $settings->get( 'redirect_edge_hint_enabled' ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['redirect_priority']  = [
			'label' => __( 'Redirect priority', 'perflocale' ),
			'value' => implode( ' → ', (array) $settings->get_redirect_priority_order() ),
		];
		$fields['edge_integration']   = [
			'label' => __( 'Edge integration enabled', 'perflocale' ),
			'value' => $settings->edge_integration_enabled() ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];

		$lang_repo = $plugin->has( 'cache' )
			? new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) )
			: null;

		if ( $lang_repo !== null ) {
			$default   = $lang_repo->get_default();
			$active    = $lang_repo->get_active();
			$lang_list = [];

			foreach ( $active as $lang ) {
				$lang_list[] = sprintf( '%s (%s)', \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->slug ), $lang->locale );
			}

			$fields['default_language'] = [
				'label' => __( 'Default language', 'perflocale' ),
				'value' => $default ? sprintf( '%s (%s)', \PerfLocale\Helper::format_locale_as_bcp47( (string) $default->slug ), $default->locale ) : __( 'not set', 'perflocale' ),
			];
			$fields['active_languages'] = [
				'label' => __( 'Active languages', 'perflocale' ),
				'value' => $lang_list === [] ? __( 'none', 'perflocale' ) : implode( ', ', $lang_list ),
			];
		}

		// ---- Translatable scope ----
		$fields['translatable_post_types'] = [
			'label' => __( 'Translatable post types', 'perflocale' ),
			'value' => implode( ', ', $settings->get_translatable_post_types() ) ?: __( 'none', 'perflocale' ),
		];
		$fields['translatable_taxonomies'] = [
			'label' => __( 'Translatable taxonomies', 'perflocale' ),
			'value' => implode( ', ', $settings->get_translatable_taxonomies() ) ?: __( 'none', 'perflocale' ),
		];

		$meta_keys                              = $settings->get_translatable_meta_keys();
		$fields['translatable_meta_keys_count'] = [
			'label' => __( 'Translatable meta keys (post-filter)', 'perflocale' ),
			'value' => (string) count( $meta_keys ),
		];

		// ---- Translation data (cached counts) ----
		$counts = $this->get_counts();

		foreach ( $counts as $key => $value ) {
			$fields[ 'counts_' . $key ] = [
				'label' => $this->count_label( $key ),
				'value' => number_format_i18n( (int) $value ),
			];
		}

		// ---- Machine translation ----
		$fields['mt_enabled']  = [
			'label' => __( 'Machine translation', 'perflocale' ),
			'value' => $settings->mt_enabled() ? __( 'Enabled', 'perflocale' ) : __( 'Disabled', 'perflocale' ),
		];
		$fields['mt_provider'] = [
			'label' => __( 'MT provider', 'perflocale' ),
			'value' => (string) $settings->get_mt_provider() ?: __( 'none', 'perflocale' ),
		];

		if ( $settings->mt_enabled() ) {
			$limit      = (int) $settings->get( 'mt_monthly_char_limit', 500000 );
			$usage      = (int) get_option( 'perflocale_mt_usage_' . gmdate( 'Y_m' ), 0 );
			$limit_text = $limit === 0 ? __( 'unlimited', 'perflocale' ) : number_format_i18n( $limit );

			$fields['mt_usage']        = [
				'label' => __( 'MT usage this month', 'perflocale' ),
				/* translators: 1: used characters, 2: limit */
				'value' => sprintf( __( '%1$s / %2$s characters', 'perflocale' ), number_format_i18n( $usage ), $limit_text ),
			];
			$fields['mt_auto_publish'] = [
				'label' => __( 'Auto-translate on publish', 'perflocale' ),
				'value' => (bool) $settings->get( 'mt_auto_translate_on_publish' ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
			];

		}

		// ---- SEO ----
		$fields['seo_hreflang']  = [
			'label' => __( 'Hreflang output', 'perflocale' ),
			'value' => $settings->hreflang_enabled() ? __( 'Enabled', 'perflocale' ) : __( 'Disabled', 'perflocale' ),
		];
		$fields['seo_x_default'] = [
			'label' => __( 'x-default hreflang', 'perflocale' ),
			'value' => (bool) $settings->get( 'seo_x_default', true ) ? __( 'Enabled', 'perflocale' ) : __( 'Disabled', 'perflocale' ),
		];
		$fields['seo_sitemap']   = [
			'label' => __( 'Sitemap integration', 'perflocale' ),
			'value' => (bool) $settings->get( 'seo_sitemap_enabled', true ) ? __( 'Enabled', 'perflocale' ) : __( 'Disabled', 'perflocale' ),
		];
		$fields['seo_plugin']    = [
			'label' => __( 'SEO plugin preference', 'perflocale' ),
			'value' => (string) $settings->get( 'seo_plugin', 'none' ),
		];

		// ---- Integrations ----
		$fields['detected_seo']     = [
			'label' => __( 'SEO plugin detected', 'perflocale' ),
			'value' => $this->detect_seo_plugin(),
		];
		$fields['detected_builder'] = [
			'label' => __( 'Builder detected', 'perflocale' ),
			'value' => $this->detect_builder(),
		];
		$fields['detected_theme']   = [
			'label' => __( 'Theme detected', 'perflocale' ),
			'value' => $this->detect_theme(),
		];

		$registry = $this->addon_registry();

		if ( $registry !== null ) {
			$booted_ids      = array_keys( array_filter( $registry->get_addons(), fn( $addon ): bool => $registry->is_booted( $addon->get_id() ) ) );
			$quarantined_ids = $registry->get_quarantined_ids();
			$disabled_ids    = \PerfLocale\Addon\AddonRegistry::get_disabled();
			$vmismatch_ids   = array_keys( $registry->get_version_mismatches() );

			$fields['addons_active'] = [
				'label' => __( 'Active addons', 'perflocale' ),
				'value' => $booted_ids === [] ? __( 'none', 'perflocale' ) : implode( ', ', $booted_ids ),
			];

			if ( ! empty( $quarantined_ids ) ) {
				$fields['addons_quarantined'] = [
					'label' => __( 'Quarantined addons', 'perflocale' ),
					'value' => implode( ', ', $quarantined_ids ),
				];
			}

			if ( ! empty( $disabled_ids ) ) {
				$fields['addons_disabled'] = [
					'label' => __( 'Addons disabled by operator', 'perflocale' ),
					'value' => implode( ', ', $disabled_ids ),
				];
			}

			if ( ! empty( $vmismatch_ids ) ) {
				$fields['addons_version_mismatch'] = [
					'label' => __( 'Addons needing newer PerfLocale', 'perflocale' ),
					'value' => implode( ', ', $vmismatch_ids ),
				];
			}

			// Autoloaded-option size reporting so operators can see when an
			// addon is pushing the storage option towards the per-addon
			// cap. The 16 KiB cap is per-addon entry; the total is shown
			// here so big numbers stand out in the Site Health view.
			$settings_raw   = (array) get_option( 'perflocale_addon_settings', [] );
			$settings_total = strlen( (string) maybe_serialize( $settings_raw ) );
			$disabled_raw   = (array) get_option( 'perflocale_disabled_addons', [] );
			$disabled_total = strlen( (string) maybe_serialize( $disabled_raw ) );

			$fields['addons_settings_option_size'] = [
				'label' => __( 'Addon settings option size', 'perflocale' ),
				'value' => sprintf(
					/* translators: 1: total serialised bytes, 2: per-addon byte cap */
					__( '%1$s bytes total (per-addon cap %2$s bytes)', 'perflocale' ),
					number_format_i18n( $settings_total ),
					number_format_i18n( 16384 )
				),
			];

			$fields['addons_disabled_option_size'] = [
				'label' => __( 'Disabled-addons option size', 'perflocale' ),
				'value' => sprintf(
					/* translators: 1: serialised bytes, 2: option byte cap */
					__( '%1$s of %2$s bytes used', 'perflocale' ),
					number_format_i18n( $disabled_total ),
					number_format_i18n( 4096 )
				),
			];
		}

		// ---- WooCommerce (only when active) ----
		if ( class_exists( 'WooCommerce' ) ) {
			$fields['wc_base_currency']     = [
				'label' => __( 'WooCommerce base currency', 'perflocale' ),
				'value' => (string) get_option( 'woocommerce_currency', '-' ),
			];
			$fields['wc_sync_stock']        = [
				'label' => __( 'Stock sync across translations', 'perflocale' ),
				'value' => (bool) $settings->get( 'wc_sync_stock', true ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
			];
			$fields['wc_sync_prices']       = [
				'label' => __( 'Price sync across translations', 'perflocale' ),
				'value' => (bool) $settings->get( 'wc_sync_prices', true ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
			];
			$fields['wc_currency_per_lang'] = [
				'label' => __( 'Per-language currencies', 'perflocale' ),
				'value' => (bool) $settings->get( 'wc_currency_per_lang', false ) ? __( 'Enabled', 'perflocale' ) : __( 'Disabled', 'perflocale' ),
			];
			$fields['wc_fx_auto']           = [
				'label' => __( 'Automatic FX sync', 'perflocale' ),
				'value' => (bool) $settings->get( 'wc_exchange_rate_auto', false ) ? __( 'Enabled', 'perflocale' ) : __( 'Disabled', 'perflocale' ),
			];

			$last_sync = (array) get_option( \PerfLocale\WooCommerce\ExchangeRateSync::LAST_SYNC_OPTION, [] );
			$ts        = (int) ( $last_sync['timestamp'] ?? 0 );

			$fields['wc_fx_last_sync'] = [
				'label' => __( 'FX (foreign-exchange rates) last synced', 'perflocale' ),
				'value' => $ts > 0
					? sprintf(
						/* translators: 1: human-readable time diff (e.g. "2 hours"), 2: absolute timestamp formatted in site's locale + timezone */
						__( '%1$s ago (%2$s)', 'perflocale' ),
						human_time_diff( $ts ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $ts )
					)
					: __( 'never', 'perflocale' ),
			];
		}

		// ---- Performance ----
		$fields['string_mode']      = [
			'label' => __( 'String translation mode', 'perflocale' ),
			'value' => (string) $settings->get( 'string_translation_mode', 'files' ),
		];
		$fields['cache_object']     = [
			'label' => __( 'Object cache enabled in settings', 'perflocale' ),
			'value' => (bool) $settings->get( 'cache_object_enabled', true ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['cache_preload']    = [
			'label' => __( 'Preload translated slugs', 'perflocale' ),
			'value' => (bool) $settings->get( 'cache_preload_slugs', true ) ? __( 'Yes', 'perflocale' ) : __( 'No', 'perflocale' ),
		];
		$fields['wp_cron_disabled'] = [
			'label' => __( 'WP-Cron disabled', 'perflocale' ),
			'value' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
				? __( 'Yes (affects scheduled FX sync, webhook delivery, and background jobs when Action Scheduler is not loaded — make sure an external cron hits wp-cron.php)', 'perflocale' )
				: __( 'No', 'perflocale' ),
		];

		// ---- Background-jobs subsystem status ----
		// Surfaces engine + queue state so an operator pasting Site Health
		// info into a support thread gives enough signal to diagnose "my
		// import isn't running" without asking for follow-up.
		$bg_engine     = \PerfLocale\Background\JobRunnerFactory::pick()->get_engine_name();
		$bg_as_loaded  = \PerfLocale\Background\JobRunnerFactory::action_scheduler_available();
		$bg_paused     = (bool) $settings->get( 'background_paused', false );
		$bg_processing = (string) $settings->get( 'background_processing', 'auto' );
		$bg_index      = (array) get_option( 'perflocale_active_jobs', [] );
		$bg_active     = count( $bg_index );

		$fields['bg_engine']     = [
			'label' => __( 'Background jobs: engine', 'perflocale' ),
			'value' => $bg_engine . ( $bg_as_loaded ? '' : __( ' (Action Scheduler not loaded)', 'perflocale' ) ),
		];
		$fields['bg_processing'] = [
			'label' => __( 'Background jobs: processing mode', 'perflocale' ),
			'value' => $bg_processing . ( $bg_paused ? __( ' (queue PAUSED)', 'perflocale' ) : '' ),
		];
		$fields['bg_active']     = [
			'label' => __( 'Background jobs: active queue depth', 'perflocale' ),
			'value' => (string) $bg_active . __( ' (bounded to 50)', 'perflocale' ),
		];

		// ---- Migration importer batch sizes ----
		// Resolve each importer's batch_size filter so support threads see the
		// effective value (helps diagnose "my import is OOMing" or
		// "my import is slow" — both usually trace back to a filter override).
		$mig_wpml = (int) apply_filters( 'perflocale/migration/wpml/batch_size', 100 );
		$mig_pll  = (int) apply_filters( 'perflocale/migration/polylang/batch_size', 100 );
		$mig_tp   = (int) apply_filters( 'perflocale/migration/translatepress/batch_size', 50 );

		$fields['migration_batch_sizes'] = [
			'label' => __( 'Migration importer batch sizes', 'perflocale' ),
			'value' => sprintf(
				/* translators: 1: WPML batch, 2: Polylang batch, 3: TranslatePress batch */
				__( 'WPML=%1$d, Polylang=%2$d, TranslatePress=%3$d', 'perflocale' ),
				$mig_wpml,
				$mig_pll,
				$mig_tp
			),
		];

		// ---- API keys / secrets status (never leak the value) ----
		foreach ( self::REDACTED_SETTINGS as $key ) {
			$value = (string) $settings->get( $key, '' );

			$fields[ 'secret_' . $key ] = [
				'label' => $this->secret_label( $key ),
				'value' => $value === '' ? __( 'not configured', 'perflocale' ) : __( 'configured', 'perflocale' ),
			];
		}

		$info['perflocale'] = [
			'label'       => __( 'PerfLocale', 'perflocale' ),
			'description' => __( 'Translation, routing, and integration state for the PerfLocale multilingual plugin. Paste this section when asking for support.', 'perflocale' ),
			'fields'      => $fields,
		];

		return $info;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Flush the counts cache - called when settings change.
	 *
	 * @return void
	 */
	public function flush_counts_cache(): void {
		delete_transient( self::COUNTS_TRANSIENT );
		delete_transient( self::HREFLANG_TRANSIENT );
		delete_transient( self::FILES_TRANSIENT );

		// Legacy key: the quality-scoring card that wrote it was removed, so
		// nothing sets this any more. The delete stays so an upgraded site's
		// leftover row is reaped rather than sitting in wp_options forever.
		delete_transient( self::MT_SCORING_TRANSIENT );
	}

	/**
	 * Get translation + string + memory counts, cached for 5 minutes.
	 *
	 * Running these `COUNT(*)` queries on every Site Health page load
	 * would be cheap individually but sums up on sites with millions
	 * of rows; the transient amortises the cost.
	 *
	 * @return array<string, int>
	 */
	private function get_counts(): array {
		$cached = get_transient( self::COUNTS_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = [
			'groups'            => $this->count_rows( Schema::table( 'translation_groups' ) ),
			'links'             => $this->count_rows( Schema::table( 'translation_links' ) ),
			'strings'           => $this->count_rows( Schema::table( 'strings' ) ),
			'slug_translations' => $this->count_rows( Schema::table( 'slug_translations' ) ),
		];

		set_transient( self::COUNTS_TRANSIENT, $counts, self::COUNTS_TTL );

		return $counts;
	}

	/**
	 * Count rows in a prefixed plugin table (returns 0 on any failure).
	 *
	 * @param string $table Fully-qualified table name from Schema::table().
	 * @return int
	 */
	private function count_rows( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table from Schema::table() is a controlled constant.
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * Human label for one {@see self::get_counts()} key.
	 *
	 * The arms mirror that method's four keys exactly; anything else falls
	 * through to the raw key rather than being silently mislabelled. (A
	 * 'memory' arm survived here after translation memory was removed —
	 * a label in the POT for a feature the plugin does not ship.)
	 *
	 * @param string $key Counts key.
	 * @return string
	 */
	private function count_label( string $key ): string {
		return match ( $key ) {
			'groups' => __( 'Translation groups', 'perflocale' ),
			'links' => __( 'Translation links', 'perflocale' ),
			'strings' => __( 'Source strings', 'perflocale' ),
			'slug_translations' => __( 'Slug translations', 'perflocale' ),
			default => $key,
		};
	}

	/**
	 * Human label for a redacted secret setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function secret_label( string $key ): string {
		$labels = [
			'mt_deepl_api_key'      => 'DeepL API key',
			'mt_google_api_key'     => 'Google API key',
			'mt_microsoft_api_key'  => 'Microsoft API key',
			'mt_libre_api_key'      => 'LibreTranslate API key',
			'mt_agency_api_key'     => 'External agency API key',
		];

		return $labels[ $key ] ?? $key;
	}

	/**
	 * @return string
	 */
	private function detect_seo_plugin(): string {
		$candidates = [
			'WPSEO_VERSION'             => 'Yoast SEO',
			'AIOSEO_VERSION'            => 'All in One SEO',
			'RANK_MATH_VERSION'         => 'Rank Math',
			'SEOPRESS_VERSION'          => 'SEOPress',
			'SLIM_SEO_VER'              => 'Slim SEO',
			'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
		];

		foreach ( $candidates as $constant => $name ) {
			if ( defined( $constant ) ) {
				return $name . ' ' . constant( $constant );
			}
		}

		return __( 'none', 'perflocale' );
	}

	/**
	 * @return string
	 */
	private function detect_builder(): string {
		$candidates = [
			'ELEMENTOR_VERSION'     => 'Elementor',
			'FLBuilder'             => 'Beaver Builder',
			'BRICKS_VERSION'        => 'Bricks',
			'CT_VERSION'            => 'Oxygen Classic',
			'BREAKDANCE_DB_VERSION' => 'Oxygen 6 / Breakdance',
		];

		$found = [];

		foreach ( $candidates as $key => $name ) {
			if ( defined( $key ) || class_exists( $key ) ) {
				$found[] = $name;
			}
		}

		return $found === [] ? __( 'none', 'perflocale' ) : implode( ', ', $found );
	}

	/**
	 * @return string
	 */
	private function detect_theme(): string {
		$theme = get_template();
		$known = [ 'blocksy', 'neve', 'kadence', 'astra', 'generatepress' ];

		return in_array( $theme, $known, true ) ? $theme : (string) $theme;
	}

	// -------------------------------------------------------------------------
	// Eager-link-map / cron / stuck / orphan tests
	// -------------------------------------------------------------------------

	/**
	 * Surface the autoloaded eager-link-map state. The blob is the dominant
	 * frontend-perf optimization (sub-µs lookups vs DB roundtrips) but grows
	 * linearly with translation-group count. Past a few hundred KB the
	 * alloptions cost starts to dominate the savings, AND the loader trips a
	 * `'too_large'` sentinel that effectively disables the optimization. Both
	 * states are operator-visible signals.
	 *
	 * @return array<string, mixed>
	 */
	public function test_eager_link_map(): array {
		$id           = 'perflocale_eager_link_map';
		// Real option keys are `perflocale_eager_links_{type}` (see
		// TranslationGroupRepository::get_eager_link_map, which writes the
		// 'too_large' sentinel there). The old `_eager_link_map_` names never
		// existed, so this Site Health size check silently never fired.
		// The option holds EITHER the link-map array, the 'too_large' string
		// sentinel, the 'empty' string sentinel (written when a build proved the
		// map genuinely empty, so a real emptiness is never confused with a
		// failed read), or is absent. The is_array() guard below is what makes
		// the string forms safe — do not remove it. Casting
		// the array form to string would throw an "Array to string conversion"
		// warning on every Status load AND collapse the byte count to strlen(
		// "Array") = 5, which permanently dead-ends the size-envelope branch
		// below. Measure the real autoloaded footprint via the serialized form.
		$post_option  = get_option( 'perflocale_eager_links_post', '' );
		$term_option  = get_option( 'perflocale_eager_links_term', '' );
		$post_too_big = $post_option === 'too_large';
		$term_too_big = $term_option === 'too_large';
		$post_bytes   = is_array( $post_option ) ? strlen( (string) maybe_serialize( $post_option ) ) : 0;
		$term_bytes   = is_array( $term_option ) ? strlen( (string) maybe_serialize( $term_option ) ) : 0;
		$total_bytes  = $post_bytes + $term_bytes;

		if ( $post_too_big || $term_too_big ) {
			$which = [];
			if ( $post_too_big ) {
				$which[] = 'post';
			}
			if ( $term_too_big ) {
				$which[] = 'term';
			}

			return $this->recommended(
				$id,
				__( 'PerfLocale eager-link-map is over the size sentinel', 'perflocale' ),
				sprintf(
					/* translators: %s is a comma-separated list of object types (post, term). */
					esc_html__( 'The eager-link-map autoloaded option for %s tripped its size sentinel and is currently disabled. Lookups fall back to per-row DB queries, which is correct but slower. This typically means the site has more translation groups than the per-blog cap. Consider archiving stale translation groups, or override the cap via the perflocale/cache/eager_map_byte_cap filter if your hosting can absorb the alloptions cost.', 'perflocale' ),
					esc_html( implode( ', ', $which ) )
				)
			);
		}

		if ( $total_bytes > self::EAGER_LINK_MAP_WARN_BYTES ) {
			return $this->recommended(
				$id,
				__( 'PerfLocale eager-link-map is large', 'perflocale' ),
				sprintf(
					/* translators: 1: total autoloaded bytes (formatted), 2: KB threshold. */
					esc_html__( 'PerfLocale\'s eager-link-map autoloaded options total %1$s, above the recommended %2$s threshold. The blob is loaded on every page via alloptions, so growth above this point starts to eat into the optimization\'s benefit. Consider archiving old translation groups; the loader self-disables (via its size sentinel) before runtime cost becomes meaningful.', 'perflocale' ),
					esc_html( size_format( $total_bytes ) ),
					esc_html( size_format( self::EAGER_LINK_MAP_WARN_BYTES ) )
				)
			);
		}

		return $this->pass(
			$id,
			__( 'PerfLocale eager-link-map is healthy', 'perflocale' ),
			sprintf(
				/* translators: %s is the formatted byte size. */
				esc_html__( 'The eager-link-map is populated and well within the recommended size envelope (%s). Frontend translation-lookup hot path is on the fast (autoload + sub-µs lookup) branch.', 'perflocale' ),
				esc_html( size_format( max( 1, $total_bytes ) ) )
			)
		);
	}

	/**
	 * Surface whether PerfLocale's three recurring cron events are actually
	 * scheduled, AND whether DISABLE_WP_CRON is set without Action Scheduler
	 * as a fallback (in which case no background work runs at all).
	 *
	 * @return array<string, mixed>
	 */
	public function test_cron_schedule(): array {
		$id      = 'perflocale_cron_schedule';
		$expects = [
			'perflocale_jobs_watchdog' => __( 'background-jobs watchdog', 'perflocale' ),
			'perflocale_jobs_gc'       => __( 'background-jobs garbage collector', 'perflocale' ),
			'perflocale_lock_cleanup'  => __( 'expired-lock reaper', 'perflocale' ),
			// The fourth unconditionally-scheduled recurring hook. It was
			// missing here, so a refused or lost machine-translation usage GC
			// was the one recurring event nothing reported. Safe to list
			// unconditionally: ensure_recurring_schedules() creates it on every
			// site, with no feature gate.
			'perflocale_mt_usage_gc'   => __( 'machine-translation usage cleanup', 'perflocale' ),
		];

		$missing = [];
		foreach ( array_keys( $expects ) as $hook ) {
			// Any-args, both engines — see test_bg_jobs_health().
			if ( ! \PerfLocale\Background\BackgroundEvents::is_scheduled( $hook ) ) {
				$missing[] = $hook;
			}
		}

		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$as_available     = class_exists( '\\ActionScheduler' ) || function_exists( 'as_enqueue_async_action' );

		// Worst case: cron disabled AND no Action Scheduler — background work
		// can never run regardless of whether the events are scheduled.
		if ( $wp_cron_disabled && ! $as_available ) {
			return $this->critical(
				$id,
				__( 'PerfLocale background jobs cannot run', 'perflocale' ),
				esc_html__( 'DISABLE_WP_CRON is set to true AND Action Scheduler is not available — neither of PerfLocale\'s job runners can execute. Background translations, watchdog sweeps, and lock cleanup will all stall. Either install/enable Action Scheduler, set up a real OS-level cron pointing at wp-cron.php, or set DISABLE_WP_CRON back to false.', 'perflocale' )
			);
		}

		if ( $missing !== [] ) {
			$labels = array_map(
				static fn ( string $hook ): string => $expects[ $hook ],
				$missing
			);

			return $this->recommended(
				$id,
				__( 'PerfLocale cron schedules are missing', 'perflocale' ),
				sprintf(
					/* translators: %s is a comma-separated list of human-readable cron-event names. */
					esc_html__( 'These PerfLocale recurring events are not scheduled and won\'t run: %s. PerfLocale re-registers them automatically on any admin page load (checked at most once every 6 hours), so loading wp-admin again is usually enough. If they stay missing, saving any PerfLocale setting forces an immediate re-check, and deactivating then reactivating the plugin rebuilds them from scratch.', 'perflocale' ),
					esc_html( implode( ', ', $labels ) )
				)
			);
		}

		return $this->pass(
			$id,
			__( 'PerfLocale cron schedules are healthy', 'perflocale' ),
			esc_html__( 'All PerfLocale recurring events (watchdog, garbage collector, lock reaper) are scheduled and will run on their normal cadence.', 'perflocale' )
		);
	}

	/**
	 * Count translations that have been stuck in `in_progress` or `pending`
	 * for longer than {@see STUCK_AGE_DAYS}. Heavy enough to warrant a
	 * {@see HEAVY_TTL}-bounded transient cache so opening Site Health twice
	 * in quick succession doesn't double-pay the COUNT(*).
	 *
	 * @return array<string, mixed>
	 */
	public function test_stuck_translations(): array {
		$id     = 'perflocale_stuck_translations';
		$cached = get_transient( self::STUCK_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['count'], $cached['checked_at'] ) ) {
			$count      = (int) $cached['count'];
			$checked_at = (int) $cached['checked_at'];
		} else {
			global $wpdb;
			$table = Schema::table( 'translation_links' );
			if ( ! Schema::tables_exist() ) {
				return $this->pass(
					$id,
					__( 'PerfLocale stuck-translations check unavailable', 'perflocale' ),
					esc_html__( 'Translation tables are not present yet; the check will start running once the plugin\'s tables are created.', 'perflocale' )
				);
			}

			// Age the rows against the DATABASE clock, not PHP's. This column
			// is stamped by MySQL (`ON UPDATE CURRENT_TIMESTAMP`) in the DB
			// server's timezone, so comparing it with a gmdate() string
			// mis-ages every row by the server's UTC offset — on a DB several
			// hours behind UTC, rows updated moments ago count as "stuck".
			// DATE_SUB(NOW(), ...) keeps both sides in the same clock whatever
			// that timezone is.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is bound as a %i identifier; the interval is an int class constant; result is transient-cached for an hour.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i
					 WHERE status IN ( 'in_progress', 'pending' )
					   AND updated_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
					$table,
					self::STUCK_AGE_DAYS
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$checked_at = time();
			set_transient(
				self::STUCK_TRANSIENT,
				[
					'count'      => $count,
					'checked_at' => $checked_at,
				],
				self::HEAVY_TTL
			);
		}

		$checked_at_human = human_time_diff( $checked_at, time() );

		if ( $count === 0 ) {
			return $this->pass(
				$id,
				__( 'No translations are stuck', 'perflocale' ),
				sprintf(
					/* translators: 1: STUCK_AGE_DAYS, 2: how long ago the check ran (human-readable). */
					esc_html__( 'No translations have been in the in_progress or pending state for more than %1$d days. (Checked %2$s ago.)', 'perflocale' ),
					self::STUCK_AGE_DAYS,
					esc_html( $checked_at_human )
				)
			);
		}

		return $this->recommended(
			$id,
			__( 'Some translations have been stuck for over a week', 'perflocale' ),
			sprintf(
				/* translators: 1: row count, 2: STUCK_AGE_DAYS, 3: how long ago the check ran (human-readable). */
				esc_html( _n(
					'%1$d translation row has been in in_progress or pending status for more than %2$d days. This usually means a background job crashed mid-run, an MT provider stopped responding, or a worker was assigned the row but never returned. Check the PerfLocale Jobs admin page for failed/retried entries, then either re-dispatch or manually mark the row. (Checked %3$s ago; the count refreshes hourly.)',
					'%1$d translation rows have been in in_progress or pending status for more than %2$d days. This usually means background jobs crashed mid-run, an MT provider stopped responding, or workers were assigned the rows but never returned. Check the PerfLocale Jobs admin page for failed/retried entries, then either re-dispatch or manually mark them. (Checked %3$s ago; the count refreshes hourly.)',
					$count,
					'perflocale'
				) ),
				$count,
				self::STUCK_AGE_DAYS,
				esc_html( $checked_at_human )
			)
		);
	}

	/**
	 * Detect orphan translation rows — translation_links rows whose target
	 * post/term has been deleted out from under PerfLocale (typically by a
	 * direct DB import, a destructive plugin, or a manual `DELETE FROM
	 * wp_posts`). LEFT JOIN + IS NULL is heavy enough to warrant the
	 * {@see HEAVY_TTL}-bounded transient cache.
	 *
	 * @return array<string, mixed>
	 */
	public function test_orphan_rows(): array {
		$id     = 'perflocale_orphan_rows';
		$cached = get_transient( self::ORPHANS_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['posts'], $cached['terms'], $cached['checked_at'] ) ) {
			$post_orphans = (int) $cached['posts'];
			$term_orphans = (int) $cached['terms'];
			$checked_at   = (int) $cached['checked_at'];
		} else {
			if ( ! Schema::tables_exist() ) {
				return $this->pass(
					$id,
					__( 'PerfLocale orphan-rows check unavailable', 'perflocale' ),
					esc_html__( 'Translation tables are not present yet; the check will start running once the plugin\'s tables are created.', 'perflocale' )
				);
			}

			global $wpdb;
			$links_table  = Schema::table( 'translation_links' );
			$groups_table = Schema::table( 'translation_groups' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $links_table / $groups_table from class-controlled Schema::table(); join targets are core WP tables.
			$post_orphans = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i l
					INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
					LEFT JOIN %i p ON p.ID = l.object_id
					WHERE p.ID IS NULL",
					$links_table,
					$groups_table,
					$wpdb->posts
				)
			);
			$term_orphans = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i l
					INNER JOIN %i g ON g.id = l.group_id AND g.type = 'term'
					LEFT JOIN %i t ON t.term_id = l.object_id
					WHERE t.term_id IS NULL",
					$links_table,
					$groups_table,
					$wpdb->terms
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$checked_at = time();
			set_transient(
				self::ORPHANS_TRANSIENT,
				[
					'posts'      => $post_orphans,
					'terms'      => $term_orphans,
					'checked_at' => $checked_at,
				],
				self::HEAVY_TTL
			);
		}

		$total            = $post_orphans + $term_orphans;
		$checked_at_human = human_time_diff( $checked_at, time() );

		if ( $total === 0 ) {
			return $this->pass(
				$id,
				__( 'No orphan translation rows', 'perflocale' ),
				sprintf(
					/* translators: %s is how long ago the check ran (human-readable). */
					esc_html__( 'Every translation row points at a real post/term. (Checked %s ago.)', 'perflocale' ),
					esc_html( $checked_at_human )
				)
			);
		}

		return $this->recommended(
			$id,
			__( 'PerfLocale has orphan translation rows', 'perflocale' ),
			sprintf(
				/* translators: 1: post-orphan count, 2: term-orphan count, 3: how long ago the check ran. */
				esc_html__( 'Found %1$d post-translation rows and %2$d term-translation rows pointing at posts/terms that no longer exist. This usually happens after a direct DB import, a destructive bulk-delete that bypassed wp_delete_post()/wp_delete_term(), or a partial restore. Run `wp perflocale health-check` to review, then `wp perflocale health-check --fix` to clean up. The fix deletes the dangling link rows (and any other stale database references it finds); your posts, terms and their translations are not touched. (Checked %3$s ago; the count refreshes hourly.)', 'perflocale' ),
					$post_orphans,
					$term_orphans,
					esc_html( $checked_at_human )
			)
		);
	}

	/**
	 * @return \PerfLocale\Addon\AddonRegistry|null
	 */
	private function addon_registry(): ?\PerfLocale\Addon\AddonRegistry {
		try {
			$plugin = Plugin::get_instance();

			if ( ! $plugin->has( 'addon_registry' ) ) {
				return null;
			}

			$registry = $plugin->get( 'addon_registry' );

			return $registry instanceof \PerfLocale\Addon\AddonRegistry ? $registry : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * @param array<int, string>              $ids
	 * @param \PerfLocale\Addon\AddonRegistry $registry
	 * @return array<string, string>
	 */
	private function resolve_addon_labels( array $ids, \PerfLocale\Addon\AddonRegistry $registry ): array {
		$labels = [];
		$addons = $registry->get_addons();

		foreach ( $ids as $id ) {
			$labels[ $id ] = isset( $addons[ $id ] ) && method_exists( $addons[ $id ], 'get_name' )
				? (string) $addons[ $id ]->get_name()
				: $id;
		}

		return $labels;
	}

	/**
	 * Common result skeleton - every test returns this shape with status
	 * + label + description filled in.
	 *
	 * @param string $id Test ID (must match the array key in register_tests).
	 * @param string $status 'good' | 'recommended' | 'critical'.
	 * @param string $label Short human label.
	 * @param string $desc HTML description.
	 * @param string $actions Optional HTML action block.
	 * @return array<string, mixed>
	 */
	private function build( string $id, string $status, string $label, string $desc, string $actions = '' ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Translation', 'perflocale' ),
				'color' => 'blue',
			],
			'description' => str_starts_with( ltrim( $desc ), '<' ) ? $desc : '<p>' . $desc . '</p>',
			'actions'     => $actions,
			'test'        => $id,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	/**
	 * Is the export directory actually protected, on THIS server?
	 *
	 * WHY THIS ASKS INSTEAD OF ASSUMING
	 *   A data export is a full dump of the site's translation data. It is
	 *   written under wp-content/uploads, which is web-served, and the only
	 *   thing standing between it and the internet is the `Deny from all`
	 *   .htaccess that Helper::harden_directory() writes. Apache and LiteSpeed
	 *   honour that file. **nginx and Caddy ignore it completely.** On those
	 *   servers the export is fetchable by anyone who knows its URL, and the
	 *   plugin cannot tell from PHP which server it is behind or what that
	 *   server's configuration says.
	 *
	 *   So it stops guessing and measures: write a short-lived random canary
	 *   into the export directory, ask for it over HTTP the way a stranger
	 *   would, and see whether the bytes come back. That turns an invisible,
	 *   host-dependent exposure into a concrete result with the exact snippet
	 *   needed to close it.
	 *
	 *   The canary is random, carries no site data, and is deleted immediately
	 *   whatever the outcome. The result is cached for an hour so opening Site
	 *   Health does not repeat the write and the request.
	 *
	 * @return array<string, mixed>
	 */
	public function test_exports_not_public(): array {
		$id = 'perflocale_exports_exposed';

		$fix = sprintf(
			/* translators: 1: nginx configuration snippet, 2: Caddy configuration snippet */
			__( 'Add a rule to your server configuration that denies access to the directory. For nginx: %1$s For Caddy: %2$s After adding it, reload the server and re-run this check.', 'perflocale' ),
			'<br><code>location ~* /wp-content/uploads/perflocale/exports/ { deny all; return 404; }</code><br>',
			'<br><code>@perflocale_exports path /wp-content/uploads/perflocale/exports/*</code><br><code>respond @perflocale_exports 404</code><br>'
		);

		/**
		 * Run the ACTIVE probe, or only give the advice?
		 *
		 * The probe writes a temporary file and makes one loopback request. Both
		 * are cheap and the verdict is cached, but there is a host shape where
		 * that stops being true: loopback blocked AND transients not persisting.
		 * Then every visit to Site Health pays the timeout again and never
		 * learns anything. An administrator on such a host cannot fix either
		 * condition, so give them a way to stop paying for it.
		 *
		 * Returning false disables only the MEASUREMENT. The check still
		 * appears, and still tells you to add the server rule — a filter must
		 * not be able to turn a real exposure green, only to stop testing for
		 * it. Verify the rule yourself if you turn this off.
		 *
		 * @hook perflocale/site_health/probe_export_exposure Set false to skip the active export-exposure probe.
		 * @param bool $probe Whether to write the canary and make the request. Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/site_health/probe_export_exposure', true ) ) {
			return $this->pass(
				$id,
				__( 'PerfLocale export exposure check is disabled', 'perflocale' ),
				esc_html__( 'The active check has been switched off on this site, so PerfLocale has not verified whether export files are reachable over the web. If this site runs on nginx or Caddy, add the export deny rule from the installation notes and confirm it yourself — .htaccess alone does not protect that directory on those servers.', 'perflocale' )
			);
		}

		$cached = get_transient( 'perflocale_exports_exposed' );

		if ( 'open' === $cached ) {
			return $this->critical(
				$id,
				__( 'PerfLocale export files are readable over the web', 'perflocale' ),
				esc_html__( 'A test file placed in the export directory was served over HTTP, so anyone who learns or guesses an export URL can download that export without logging in. Export filenames carry 32 characters of randomness, so they cannot realistically be guessed, but they can leak through server logs, browser history, referrers or backups.', 'perflocale' ),
				$fix
			);
		}

		if ( 'closed' === $cached ) {
			return $this->pass(
				$id,
				__( 'PerfLocale export files are not web-readable', 'perflocale' ),
				esc_html__( 'A test file placed in the export directory was refused over HTTP, so exports are only reachable through the authenticated download in the admin.', 'perflocale' )
			);
		}

		// An inconclusive run is cached too, and that is the point. Plenty of
		// hosts block or stall loopback requests; without this the probe would
		// pay its full timeout on EVERY Site Health page load on exactly those
		// hosts. A shorter TTL than a definite answer, so a temporary network
		// problem is re-checked sooner than a settled verdict.
		if ( 'unknown' === $cached ) {
			return $this->pass(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'This site could not make a request to itself, so the check was skipped. That is common on hosts that block loopback requests and does not by itself indicate a problem. If your server is nginx or Caddy, add the export deny rule from the installation notes anyway.', 'perflocale' )
			);
		}

		$dir = \PerfLocale\Helper::uploads_exports_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			// Not cached: the directory appearing is exactly the event that
			// should make this check run for real.
			return $this->pass(
				$id,
				__( 'PerfLocale export directory does not exist yet', 'perflocale' ),
				esc_html__( 'No export has been created on this site, so there is nothing to expose. This check runs again once the directory exists.', 'perflocale' )
			);
		}

		// Random name AND random body: the body is what proves the file was
		// served rather than a 404 page that happens to return 200.
		$token  = wp_generate_password( 24, false, false );
		$name   = 'perflocale-healthcheck-' . $token . '.txt';
		$path   = trailingslashit( $dir ) . $name;
		$body   = 'perflocale-canary-' . $token;
		$upload = wp_upload_dir();

		if ( ! is_array( $upload ) || empty( $upload['baseurl'] ) ) {
			set_transient( 'perflocale_exports_exposed', 'unknown', 15 * MINUTE_IN_SECONDS );

			return $this->pass(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'The uploads directory URL is unavailable, so this check was skipped. It does not indicate a problem.', 'perflocale' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A canary write that must not raise on a read-only directory; failure is handled immediately below.
		$written = @file_put_contents( $path, $body );

		if ( false === $written ) {
			set_transient( 'perflocale_exports_exposed', 'unknown', 15 * MINUTE_IN_SECONDS );

			return $this->pass(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'The export directory is not writable from PHP, so this check was skipped. A directory that cannot be written also cannot receive new exports.', 'perflocale' )
			);
		}

		$url = trailingslashit( $upload['baseurl'] ) . 'perflocale/exports/' . $name;

		$response = wp_remote_get(
			$url,
			[
				// Short: this is a static file on the very server running the
				// request. Anything slower than this is a host that does not
				// want loopback, which the WP_Error branch handles and caches.
				'timeout'     => 3,
				'redirection' => 0,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name (defined in WP_Http::request).
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [ 'X-PerfLocale-Health-Check' => '1' ],
			]
		);

		// Always remove the canary, whatever happened.
		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			set_transient( 'perflocale_exports_exposed', 'unknown', 15 * MINUTE_IN_SECONDS );

			return $this->pass(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'The site could not make a request to itself, so this check was skipped. That is common on hosts that block loopback requests and does not by itself indicate a problem.', 'perflocale' )
			);
		}

		$served = 200 === (int) wp_remote_retrieve_response_code( $response )
			&& str_contains( (string) wp_remote_retrieve_body( $response ), $body );

		set_transient( 'perflocale_exports_exposed', $served ? 'open' : 'closed', HOUR_IN_SECONDS );

		if ( $served ) {
			return $this->critical(
				$id,
				__( 'PerfLocale export files are readable over the web', 'perflocale' ),
				esc_html__( 'A test file placed in the export directory was served over HTTP, so anyone who learns or guesses an export URL can download that export without logging in. Export filenames carry 32 characters of randomness, so they cannot realistically be guessed, but they can leak through server logs, browser history, referrers or backups.', 'perflocale' ),
				$fix
			);
		}

		return $this->pass(
			$id,
			__( 'PerfLocale export files are not web-readable', 'perflocale' ),
			esc_html__( 'A test file placed in the export directory was refused over HTTP, so exports are only reachable through the authenticated download in the admin.', 'perflocale' )
		);
	}

	private function pass( string $id, string $label, string $desc, string $actions = '' ): array {
		return $this->build( $id, 'good', $label, $desc, $actions );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function recommended( string $id, string $label, string $desc, string $actions = '' ): array {
		return $this->build( $id, 'recommended', $label, $desc, $actions );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function critical( string $id, string $label, string $desc, string $actions = '' ): array {
		return $this->build( $id, 'critical', $label, $desc, $actions );
	}
}
