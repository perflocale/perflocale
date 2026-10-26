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
 * PerfLocale's Site Health entries: Status tests (the language-pack test asynchronous, the rest direct and cheap)
 * and an Info section for support. Secrets show as configured or not, never their value; heavy counts are cached.
 */
final class SiteHealth {

	/**
	 * Transient of the aggregate counts.
	 */
	private const COUNTS_TRANSIENT = 'perflocale_site_health_counts';

	/**
	 * Transient of the hreflang probe result.
	 */
	private const HREFLANG_TRANSIENT = 'perflocale_site_health_hreflang';

	/**
	 * Transient of the stuck-translations count (a COUNT on translation_links by status and date).
	 */
	private const STUCK_TRANSIENT = 'perflocale_site_health_stuck';

	/**
	 * Transient of the orphan-rows probe (translation and slug rows whose object is gone; scans both tables).
	 */
	private const ORPHANS_TRANSIENT = 'perflocale_site_health_orphans';

	/**
	 * Transient flush_counts_cache() still deletes; nothing writes it (its card is gone), but old sites may hold a row.
	 */
	private const MT_SCORING_TRANSIENT = 'perflocale_site_health_mt_scoring';

	/**
	 * Transient of the translation-files probe counts (two 4-table JOINs and a full COUNT), else run on every Status load
	 * in files mode.
	 */
	private const FILES_TRANSIENT = 'perflocale_site_health_files';

	/**
	 * Counts cache TTL in seconds.
	 */
	private const COUNTS_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * TTL of the heavy probes (stuck translations, orphan rows), which scan whole tables; the card shows when it last ran.
	 */
	private const HEAVY_TTL = HOUR_IN_SECONDS;

	/**
	 * Size of the autoloaded eager-link map at which a recommendation shows: it grows with the group count, and past
	 * this its alloptions cost outweighs what it saves.
	 */
	private const EAGER_LINK_MAP_WARN_BYTES = 262144; // 256 KB.

	/**
	 * Most language hostname lookups test_multisite_language_hosts() makes per run (one cached WP_Site_Query each);
	 * the rest is reported as unchecked, never as resolving.
	 */
	private const MAX_HOST_PROBES = 20;

	/**
	 * Locale shape the language-pack test puts in its suggested WP-CLI command (WordPress.org shapes: de_DE,
	 * de_DE_formal, pt_PT_ao90, ca_valencia, fi, ary); a stored locale can hold any text, so others are left out of it.
	 */
	private const CLI_LOCALE = '/\A[a-z]{2,3}(?:_[A-Za-z0-9]{2,8})*\z/';

	/**
	 * The language-pack test's name as core's script sends it (the admin-ajax action is "health-check-" + this).
	 */
	private const LANGUAGE_PACKS_ACTION = 'perflocale-language-packs';

	/**
	 * Days after which a translation still `in_progress` or `pending` is reported as stuck.
	 */
	private const STUCK_AGE_DAYS = 7;

	/**
	 * MT usage thresholds (as a fraction of the monthly limit).
	 */
	private const MT_WARN_THRESHOLD     = 0.80; // Recommended at 80%.
	private const MT_CRITICAL_THRESHOLD = 0.95; // Critical at 95%.

	/**
	 * Constants of conflicting multilingual plugins (as in Bootstrap, duplicated to keep this class apart).
	 */
	private const CONFLICTING_PLUGINS = [
		'ICL_SITEPRESS_VERSION' => 'WPML',
		'POLYLANG_VERSION'      => 'Polylang',
		'TRP_PLUGIN_VERSION'    => 'TranslatePress',
	];

	/**
	 * PHP extensions that each gate one optional feature, so a missing one is a recommendation. Not mbstring (core
	 * polyfills mb_strlen() and mb_substr(); its own Site Health has it not required) and not json (always on in PHP 8).
	 *
	 * @var array<int, string>
	 */
	private const FEATURE_PHP_EXTENSIONS = [ 'dom', 'libxml', 'xmlwriter', 'simplexml', 'filter', 'intl' ];

	/**
	 * How late a recurring event may be before the cron card takes it as nothing running the queue: the shortest event
	 * is hourly and system crontabs fire every 5-15 minutes, so an hour absorbs slow ticks.
	 */
	private const CRON_OVERDUE_GRACE = HOUR_IN_SECONDS;

	/**
	 * Failed jobs in 24 hours needed before the jobs card says the pipeline is dead, so one retryable error on a quiet
	 * site does not show red.
	 */
	private const JOBS_ALL_DEAD_MIN = 3;

	/**
	 * Provider API hosts for the DNS check, by Settings::get_mt_provider() slug.
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
	 * Secret settings shown in the Info dump as configured or not configured.
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

		// Settings changes can change the cached counts: drop them.
		add_action( 'perflocale/settings/updated', [ $this, 'flush_counts_cache' ] );

		// String edits, PO imports and bulk runs change the counts, hreflang and files cards: drop their caches (admin and
		// background writes only).
		add_action( 'perflocale/strings/changed', [ $this, 'flush_counts_cache' ] );

		add_action( 'admin_notices', [ $this, 'render_leftover_notice' ] );

		// The Status screen runs the language-pack test through this admin-ajax action.
		if ( wp_doing_ajax() ) {
			add_action( 'wp_ajax_health-check-' . self::LANGUAGE_PACKS_ACTION, [ $this, 'ajax_language_packs' ] );
		}
	}

	// Status tab: test registration.

	/**
	 * @param array<string, array<string, array<string, mixed>>> $tests
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function register_tests( array $tests ): array {
		$direct = [
			'perflocale_exports_exposed'   => [
				'label' => __( 'PerfLocale export files are not web-readable', 'perflocale' ),
				'test'  => [ $this, 'test_exports_not_public' ],
				// Writes a canary file and fetches it over HTTP: fine when an admin opens the tab, not on every weekly cron run.
				'skip_cron' => true,
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
			'perflocale_leftover_plugins'   => [
				'label' => __( 'WPML add-ons are still active', 'perflocale' ),
				'test'  => [ $this, 'test_leftover_plugins' ],
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
				// Blocking DNS lookup with no timeout of its own.
				'skip_cron' => true,
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
				// Loopback GET of the site's own home page.
				'skip_cron' => true,
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
			'perflocale_webhook_failures'  => [
				'label' => __( 'PerfLocale webhook delivery', 'perflocale' ),
				'test'  => [ $this, 'test_webhook_failures' ],
			],
		];

		foreach ( $direct as $id => $entry ) {
			$tests['direct'][ $id ] = $entry;
		}

		// Asynchronous, so the Status screen renders without waiting: the screen posts admin-ajax "health-check-" . $test;
		// the weekly check calls async_direct_test.
		$tests['async']['perflocale_language_packs'] = [
			'label'             => __( 'PerfLocale language packs', 'perflocale' ),
			'test'              => self::LANGUAGE_PACKS_ACTION,
			'has_rest'          => false,
			'async_direct_test' => [ $this, 'test_language_packs' ],
		];

		return $tests;
	}

	/**
	 * Admin-ajax endpoint of the language-pack test, with core's Site Health ajax nonce and capability checks.
	 *
	 * @return void
	 */
	public function ajax_language_packs(): void {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->test_language_packs() );
	}

	/**
	 * Are the WordPress.org language packs of the site's active languages (en_US excepted) installed?
	 *
	 * Recommended when a WordPress pack is missing or has no WordPress.org translation, or a theme or plugin pack waits
	 * on Dashboard > Updates; LanguagePacks::report() says what each state means.
	 *
	 * @return array<mixed>
	 */
	public function test_language_packs(): array {
		$id     = 'perflocale_language_packs';
		$plugin = Plugin::get_instance();
		$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
			return $this->pass(
				$id,
				__( 'Language pack check skipped', 'perflocale' ),
				__( 'Service container not fully booted - check again after the page loads.', 'perflocale' )
			);
		}

		$repo    = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		$labels  = [];
		$locales = [];

		foreach ( $repo->get_active() as $language ) {
			$locale = isset( $language->locale ) && is_string( $language->locale ) ? $language->locale : '';

			if ( '' === $locale || 'en_US' === $locale ) {
				continue;
			}

			$name              = isset( $language->name ) && is_string( $language->name ) && '' !== $language->name ? $language->name : $locale;
			$labels[ $locale ] = esc_html( $name ) . ' (' . esc_html( $locale ) . ')';
			$locales[]         = $locale;
		}

		if ( [] === $locales ) {
			return $this->pass(
				$id,
				__( 'No site language needs a language pack', 'perflocale' ),
				esc_html__( 'Every active language uses the en_US locale, the language WordPress is written in.', 'perflocale' )
			);
		}

		$report       = LanguagePacks::report( $locales );
		$core_missing = [];
		$core_absent  = [];
		$core_ok      = [];
		$offered      = [];
		$not_offered  = [];

		foreach ( $report['locales'] as $locale => $row ) {
			$label    = $labels[ $locale ] ?? esc_html( $locale );
			$findings = LanguagePacks::findings( $row );

			if ( LanguagePacks::INSTALLED === $findings['core'] ) {
				$core_ok[] = $label;
			} elseif ( LanguagePacks::NOT_AVAILABLE === $findings['core'] ) {
				$core_absent[] = $label;
			} elseif ( LanguagePacks::MISSING === $findings['core'] ) {
				$core_missing[] = $locale;
			}

			$ready = LanguagePacks::OFFERED === $findings['core'] ? [ esc_html__( 'WordPress', 'perflocale' ) ] : [];
			$none  = [];

			foreach ( $findings['offered'] as $item ) {
				$ready[] = esc_html( $item['name'] );
			}

			foreach ( $findings['not_offered'] as $item ) {
				$none[] = esc_html( $item['name'] );
			}

			if ( [] !== $ready ) {
				$offered[] = $label . ': ' . implode( ', ', $ready );
			}

			if ( [] !== $none ) {
				$not_offered[] = $label . ': ' . implode( ', ', $none );
			}
		}

		$updates_url = LanguagePacks::updates_url();
		$desc        = '';

		if ( [] !== $core_missing ) {
			$missing_labels = [];
			$cli_locales    = [];

			foreach ( $core_missing as $locale ) {
				$missing_labels[] = $labels[ $locale ] ?? esc_html( $locale );

				if ( 1 === preg_match( self::CLI_LOCALE, $locale ) ) {
					$cli_locales[] = $locale;
				}
			}

			$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings → General', 'perflocale' ) . '</a>';

			if ( [] !== $cli_locales ) {
				$install = sprintf(
					/* translators: 1: "Settings → General" link, 2: WP-CLI command */
					esc_html__( 'To install one, choose it as Site Language in %1$s and save, then set the Site Language back. With WP-CLI: %2$s. WordPress then offers the theme and plugin packs for the language on Dashboard → Updates.', 'perflocale' ),
					$settings_link,
					'<code>wp language core install ' . esc_html( implode( ' ', $cli_locales ) ) . '</code>'
				);
			} else {
				$install = sprintf(
					/* translators: %s: "Settings → General" link */
					esc_html__( 'To install one, choose it as Site Language in %s and save, then set the Site Language back. WordPress then offers the theme and plugin packs for the language on Dashboard → Updates.', 'perflocale' ),
					$settings_link
				);
			}

			$desc .= '<p><strong>' . esc_html__( 'The WordPress language pack is not installed for:', 'perflocale' ) . '</strong> ' . implode( ', ', $missing_labels ) . '</p>';
			$desc .= '<p>' . $install . ' ' . esc_html__( 'A language missing from the Site Language list has no WordPress translation on WordPress.org; check its locale.', 'perflocale' ) . '</p>';
		}

		if ( [] !== $core_absent ) {
			$desc .= '<p><strong>' . esc_html__( 'WordPress.org has no WordPress translation for:', 'perflocale' ) . '</strong> ' . implode( ', ', $core_absent ) . '</p>';
			$desc .= '<p>' . sprintf(
				/* translators: %s: "PerfLocale → Languages" link */
				esc_html__( 'Check the locale of these languages in %s.', 'perflocale' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ) . '">' . esc_html__( 'PerfLocale → Languages', 'perflocale' ) . '</a>'
			) . '</p>';
		}

		if ( [] !== $offered ) {
			$desc .= '<p><strong>' . esc_html__( 'Ready to install on Dashboard → Updates:', 'perflocale' ) . '</strong> ' . implode( '; ', $offered ) . '</p>';
		}

		$problem = '' !== $desc;
		$info    = '';

		if ( [] !== $not_offered ) {
			$info .= '<p><strong>' . sprintf(
				/* translators: %s: date and time of WordPress's last update check */
				esc_html__( 'Not offered at the last update check (%s):', 'perflocale' ),
				esc_html( LanguagePacks::checked_at( $report['last_checked'] ) )
			) . '</strong> ' . implode( '; ', $not_offered ) . '</p>';
			$info .= '<p>' . esc_html__( 'WordPress.org has no pack for these, unless the language was installed after that check: Dashboard → Updates checks again.', 'perflocale' ) . '</p>';
		}

		if ( ! $report['checked']['theme'] && ! $report['checked']['plugin'] ) {
			$info .= '<p>' . esc_html__( 'Theme and plugin packs were not checked: WordPress has no update data yet. Dashboard → Updates runs the update check.', 'perflocale' ) . '</p>';
		} elseif ( ! $report['checked']['theme'] || ! $report['checked']['plugin'] ) {
			$info .= '<p>' . esc_html__( 'Some theme or plugin packs were not checked: WordPress has no update data for them yet. Dashboard → Updates runs the update check.', 'perflocale' ) . '</p>';
		}

		$actions = '' !== $desc . $info ? sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $updates_url ),
			esc_html__( 'Open Dashboard → Updates', 'perflocale' )
		) : '';

		if ( $problem ) {
			return $this->recommended(
				$id,
				__( 'Language packs are missing for some site languages', 'perflocale' ),
				'<p>' . esc_html__( 'Visitors see English text where a language pack is missing.', 'perflocale' ) . '</p>' . $desc . $info,
				$actions
			);
		}

		return $this->pass(
			$id,
			__( 'Language packs are installed for the site languages', 'perflocale' ),
			'<p><strong>' . esc_html__( 'The WordPress language pack is installed for:', 'perflocale' ) . '</strong> ' . implode( ', ', $core_ok ) . '</p>' . $info,
			$actions
		);
	}

	// Status tests.

	/**
	 * Are all of the plugin's custom tables present?
	 *
	 * @return array<string, mixed>
	 */
	public function test_tables_exist(): array {
		// All nine tables, not Schema::tables_exist() (the per-request guard checks one table, usually short-circuited by an
		// option, which kept this card green after a partial restore). Docs: docs-ai/11-code-rationale.md, "SiteHealth:
		// tables and rewrite checks".
		$missing = Schema::missing_tables();

		if ( [] === $missing ) {
			return $this->pass(
				'perflocale_tables',
				__( 'PerfLocale database tables are present', 'perflocale' ),
				sprintf(
					/* translators: %d: number of database tables that were checked. */
					esc_html__( 'All %d PerfLocale database tables exist. Translation storage is healthy.', 'perflocale' ),
					count( Schema::REQUIRED_TABLES )
				)
			);
		}

		$items = '';

		foreach ( $missing as $table ) {
			$items .= '<li><code>' . esc_html( $table ) . '</code></li>';
		}

		return $this->critical(
			'perflocale_tables',
			sprintf(
				/* translators: %d: number of missing database tables. */
				_n( '%d PerfLocale database table is missing', '%d PerfLocale database tables are missing', count( $missing ), 'perflocale' ),
				count( $missing )
			),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				sprintf(
					/* translators: 1: number of missing tables, 2: total number of tables. */
					esc_html__( '%1$d of the %2$d tables PerfLocale needs are not in the database:', 'perflocale' ),
					count( $missing ),
					count( Schema::REQUIRED_TABLES )
				),
				$items,
				esc_html__( 'This usually happens after restoring a backup from a time before the plugin was activated, when the database user lacks CREATE TABLE privileges, or when a migration was interrupted. Deactivate and reactivate the plugin to recreate them; existing data in the surviving tables is left alone.', 'perflocale' )
			)
		);
	}

	/**
	 * Is a language marked as default?
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
	 * In subdirectory mode, are the language-prefixed rewrite rules present?
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
			// The filtered query variable, as RewriteManager writes it, or a renamed one reports rules missing.
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

		// Plain permalinks work only when the server routes prefixed paths (/de/) to WordPress (Apache without the
		// WordPress .htaccess does not); pretty permalinks are the fix either way.
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
	 * On multisite, do the hostnames of `subdomain` / `domain` mode resolve to a site of the network? ms-settings.php
	 * resolves the host before any plugin loads, so an unregistered one never reaches PerfLocale. Resolved for real,
	 * so domain mapping counts. Docs: docs-ai/11-code-rationale.md, "SiteHealth: language hostnames on multisite".
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

		// Every hostname checked: only then is "they resolve" about all of them.
		if ( $unchecked === 0 ) {
			return $this->pass(
				$id,
				__( 'Language hostnames resolve to sites in this network', 'perflocale' ),
				sprintf(
					esc_html(
						/* translators: 1: URL mode label, 2: number of hostnames checked, 3: comma-separated list of hostnames */
						_n(
							'PerfLocale is in %1$s URL mode and the %2$d language hostname it generates resolves to a site registered in this network: %3$s. Requests for translated URLs reach WordPress instead of the "site does not exist" screen.',
							'PerfLocale is in %1$s URL mode and all %2$d language hostnames it generates resolve to a site registered in this network: %3$s. Requests for translated URLs reach WordPress instead of the "site does not exist" screen.',
							count( $probes ),
							'perflocale'
						)
					),
					'<code>' . esc_html( $mode ) . '</code>',
					count( $probes ),
					implode( ', ', $listed )
				)
			);
		}

		// The probe cap was hit: say what was checked, and recommended, not good: partial knowledge is not a pass.
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
	 * The sentence naming how many hostnames the run did not check.
	 *
	 * @param int $unchecked How many hostnames were left unprobed.
	 * @return string HTML fragment, or an empty string when nothing was skipped.
	 */
	private static function unchecked_note( int $unchecked ): string {
		if ( $unchecked < 1 ) {
			return '';
		}

		return ' ' . sprintf(
			esc_html(
				/* translators: %d: number of language hostnames left unchecked */
				_n(
					'%d further language hostname was not checked, so this test stays cheap enough to run on every scheduled health check.',
					'%d further language hostnames were not checked, so this test stays cheap enough to run on every scheduled health check.',
					$unchecked,
					'perflocale'
				)
			),
			$unchecked
		);
	}

	/**
	 * Resolve every language hostname of this URL mode against the network's sites and report what was looked at;
	 * shared with the Settings screen's URL-mode warning (the mode breaks a network at save time).
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

		// Single site: WordPress serves any hostname that reaches it.
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

		// Only the host-rewriting modes generate other hosts.
		if ( $mode !== 'subdomain' && $mode !== 'domain' ) {
			$report['label']       = __( 'URL mode is safe on a multisite network', 'perflocale' );
			$report['description'] = sprintf(
				/* translators: %s: URL mode label */
				__( 'Current URL mode: %s. Translated URLs keep this site\'s hostname, so the network resolves them exactly as it resolves an untranslated one.', 'perflocale' ),
				'<code>' . esc_html( $mode ) . '</code>'
			);

			return $report;
		}

		// Built as UrlConverter builds them, from the stored `home` option (apply_subdomain() reads home_url() with its own
		// filter suppressed), so the mode being checked cannot rewrite the check.
		$home      = (string) get_option( 'home' );
		$home_host = wp_parse_url( $home, PHP_URL_HOST );
		$host      = is_string( $home_host ) ? strtolower( $home_host ) : '';

		// ms-settings.php strips only :80 and :443, and UrlConverter keeps other ports: so does the lookup.
		$home_port   = wp_parse_url( $home, PHP_URL_PORT );
		$port_suffix = ( is_int( $home_port ) && 80 !== $home_port && 443 !== $home_port ) ? ':' . $home_port : '';

		$repo         = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->cache() );
		$default      = $repo->get_default();
		$default_slug = ( $default !== null && ! empty( $default->slug ) ) ? (string) $default->slug : '';

		/**
		 * Hostnames to probe, deduplicated (two languages can share a domain).
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
				// apply_subdomain() keeps the default language on the base host, this site's own.
				if ( $slug === $default_slug || $host === '' ) {
					continue;
				}

				$target = strtolower( $slug . '.' . $host . $port_suffix );
			} else {
				// apply_domain() has no default exemption: a default-language domain replaces the base host too.
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

		// The lookup's path (`/` or `/sub/`) from wp_blogs via get_site(), the column ms-settings.php matches.
		$current_site = get_site();
		$site_path    = ( $current_site !== null && $current_site->path !== '' ) ? $current_site->path : '/';

		foreach ( array_keys( $probes ) as $target ) {
			$target              = (string) $target;
			$report['checked'][] = $target;

			// The call ms_load_current_site_and_network() makes, filters included, so a mapped domain resolves here too.
			if ( empty( get_site_by_path( $target, $site_path ) ) ) {
				$report['unresolved'][] = $target;
			}
		}

		return $report;
	}

	/**
	 * The authority WordPress compares for a configured language domain (operators paste schemes and slashes, and
	 * apply_domain() uses the value verbatim): the host, normalised, plus a nonstandard port.
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

		// wp_parse_url() reads a bare `example.fr` as a path: add a protocol-relative prefix.
		if ( ! str_contains( $domain, '//' ) ) {
			$domain = '//' . $domain;
		}

		$parsed = wp_parse_url( $domain );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		// The router's own normaliser (lowercase and punycode), so card and router compare the same host.
		$authority = \PerfLocale\Router\LanguageRouter::normalize_host( (string) $parsed['host'] );

		if ( '' === $authority ) {
			return '';
		}

		$port = $parsed['port'] ?? null;

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
	 * WooCommerce Multilingual or WPML String Translation left active.
	 *
	 * @return array<mixed>
	 */
	public function test_leftover_plugins(): array {
		$active = LeftoverPluginCheck::active();

		if ( $active === [] ) {
			return $this->pass(
				'perflocale_leftover_plugins',
				__( 'No WPML add-ons are active', 'perflocale' ),
				esc_html__( 'WooCommerce Multilingual and WPML String Translation are not active.', 'perflocale' )
			);
		}

		$desc    = '';
		$actions = '';

		foreach ( $active as $name => $file ) {
			$desc .= '<p>' . esc_html( LeftoverPluginCheck::describe( $name ) ) . '</p>';
			$url   = LeftoverPluginCheck::deactivate_url( $name, $file );

			if ( $url !== '' ) {
				$actions .= $this->leftover_button( $name, $url );
			}
		}

		return $this->recommended(
			'perflocale_leftover_plugins',
			__( 'WPML add-ons are still active', 'perflocale' ),
			$desc,
			$actions
		);
	}

	/**
	 * Notice for leftover WPML add-ons on PerfLocale's screens and the Plugins screen, for users who can activate plugins.
	 *
	 * @return void
	 */
	public function render_leftover_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		$id     = $screen !== null ? (string) $screen->id : '';

		if ( $id !== 'plugins' && ! str_contains( $id, 'perflocale' ) ) {
			return;
		}

		$active = LeftoverPluginCheck::active();

		if ( $active === [] ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'WPML add-ons are still active', 'perflocale' ) . '</strong></p>';

		foreach ( $active as $name => $file ) {
			echo '<p>' . esc_html( LeftoverPluginCheck::describe( $name ) ) . '</p>';
			$url = LeftoverPluginCheck::deactivate_url( $name, $file );

			if ( $url !== '' ) {
				echo '<p>' . $this->leftover_button( $name, $url ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in leftover_button().
			}
		}

		echo '</div>';
	}

	/**
	 * "Deactivate <plugin>" link to core's Plugins action.
	 *
	 * @param string $name Plugin display name.
	 * @param string $url  Nonced deactivate URL.
	 * @return string
	 */
	private function leftover_button( string $name, string $url ): string {
		return '<a class="button" href="' . esc_url( $url ) . '">' . esc_html(
			sprintf(
				/* translators: %s: plugin name, e.g. "WooCommerce Multilingual" */
				__( 'Deactivate %s', 'perflocale' ),
				$name
			)
		) . '</a>';
	}

	/**
	 * Report add-ons that are not running: quarantined, incompatible, or needing a newer PerfLocale.
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

		// Quarantine is one way an integration stops; an incompatible add-on or one needing a newer PerfLocale is skipped
		// as silently, and is reported too.
		$incompatible = method_exists( $registry, 'get_incompatible_ids' )
			? array_keys( $registry->get_incompatible_ids() )
			: [];
		$outdated     = method_exists( $registry, 'get_version_mismatches' )
			? $registry->get_version_mismatches()
			: [];

		if ( empty( $ids ) && [] === $incompatible && [] === $outdated ) {
			return $this->pass(
				'perflocale_addon_quarantine',
				__( 'PerfLocale addons are running normally', 'perflocale' ),
				__( 'No PerfLocale addons have been auto-disabled, and none was skipped as incompatible. All active integrations are booting without errors.', 'perflocale' )
			);
		}

		if ( empty( $ids ) ) {
			$skipped = '';

			foreach ( $incompatible as $id ) {
				$skipped .= sprintf(
					'<li><code>%s</code> - %s</li>',
					esc_html( $id ),
					esc_html__( 'reported itself incompatible with this site', 'perflocale' )
				);
			}

			foreach ( $outdated as $id => $required ) {
				$skipped .= sprintf(
					'<li><code>%1$s</code> - %2$s</li>',
					esc_html( (string) $id ),
					esc_html( sprintf(
						/* translators: %s: the PerfLocale version the addon requires. */
						__( 'needs PerfLocale %s or newer', 'perflocale' ),
						(string) $required
					) )
				);
			}

			return $this->recommended(
				'perflocale_addon_quarantine',
				__( 'Some PerfLocale integrations are installed but not running', 'perflocale' ),
				sprintf(
					'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
					esc_html__( 'These integrations were skipped at load, so the features they add are not active:', 'perflocale' ),
					$skipped,
					esc_html__( 'This is not an error - it is how PerfLocale avoids running an integration against a version it does not support. Updating the plugin it integrates with, or PerfLocale itself, is usually what clears it.', 'perflocale' )
				)
			);
		}

		$labels = $this->resolve_addon_labels( $ids, $registry );
		$items  = '';

		foreach ( $ids as $id ) {
			$items .= '<li><code>' . esc_html( $labels[ $id ] ?? $id ) . '</code></li>';
		}

		// The registry's own filter, so the copy names the configured threshold.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Plugin-prefixed hook; see AddonRegistry.
		$threshold = max( 1, (int) apply_filters( 'perflocale/addons/quarantine_threshold', 3 ) );

		return $this->recommended(
			'perflocale_addon_quarantine',
			__( 'One or more PerfLocale addons are disabled', 'perflocale' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				esc_html( sprintf(
					/* translators: %d: number of consecutive boot failures that trigger auto-disable. */
					_n(
						'The following addons have been auto-disabled after %d consecutive boot failure:',
						'The following addons have been auto-disabled after %d consecutive boot failures:',
						$threshold,
						'perflocale'
					),
					$threshold
				) ),
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

		// Critical only once requests are refused (at 100%); 95% of a limit the operator chose is no failure.
		if ( $percent >= 1.0 ) {
			return $this->critical(
				'perflocale_mt_usage',
				__( 'The monthly machine-translation limit has been reached', 'perflocale' ),
				$formatted . '<br>' . esc_html__( 'Translation requests are now being refused and will keep failing until the limit is raised in Settings → Addons → Machine Translation, or until the monthly counter resets.', 'perflocale' )
			);
		}

		if ( $percent >= self::MT_CRITICAL_THRESHOLD ) {
			return $this->recommended(
				'perflocale_mt_usage',
				__( 'Machine-translation usage is near the monthly limit', 'perflocale' ),
				// Says when it bites: requests stop at 100%, not at the 95% that turns the card amber.
				$formatted . '<br>' . esc_html__( 'Translation requests are still being sent. They stop once the limit is reached - raise it in Settings → Addons → Machine Translation, or wait for the monthly reset.', 'perflocale' )
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
	 * Circuit breaker status of every breaker (MT providers, webhook delivery, WooCommerce FX sync, geo-IP): all closed
	 * is good, any half-open recommended, any open critical with its cooldown and a reset link (capability and nonce,
	 * AdminController::handle_reset_breaker_action()).
	 *
	 * @return array<string, mixed>
	 */
	public function test_circuit_breakers(): array {
		// Not autoloaded yet on a very early probe: no-op.
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
			$desc  = '<p>' . esc_html__( 'One or more circuit breakers are OPEN - calls to the listed external services are being refused to prevent piling retries onto a failing dependency.', 'perflocale' ) . '</p>';
			$desc .= '<ul>';
			foreach ( $open as $key => $status ) {
				$reason    = (string) ( $status['reason'] ?? 'unknown' );
				$retry_in  = (int) ( $status['cooldown_remaining'] ?? 0 );
				$reset_url = self::breaker_reset_url( $key );

				$desc .= sprintf(
					'<li><code>%1$s</code> - %2$s (%3$s) - %4$s</li>',
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
	 * A nonce'd admin-post URL that force-closes one breaker; '' for a user without the capability (the handler checks
	 * too).
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
		// WooCommerce itself, not PerfLocale's always-autoloadable class, or a site without WooCommerce hears of stale rates.
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( '\\PerfLocale\\WooCommerce\\ExchangeRateSync' ) ) {
			return $this->pass(
				'perflocale_fx_staleness',
				__( 'Exchange-rate sync inactive', 'perflocale' ),
				__( 'WooCommerce is not active, so there are no currencies to keep in sync.', 'perflocale' )
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

		// No rate source: every sync aborts before the network, a different remedy than WP-Cron.
		if ( ! $sync->has_rate_source() ) {
			// Red only when money is affected: a non-base currency charged at the base number. Docs:
			// docs-ai/11-code-rationale.md, "SiteHealth: exchange-rate severity".
			$base       = (string) get_option( 'woocommerce_currency', '' );
			$currencies = (array) $settings->get( 'wc_currencies', [] );
			$foreign    = [];

			foreach ( $currencies as $slug => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}

				$code = (string) ( $data['currency_code'] ?? '' );
				$rate = (float) ( $data['exchange_rate'] ?? 1.0 );

				// A money defect needs all three: a non-base currency, no pinned manual rate, and a stored rate still 1.0 (a
				// hand-entered rate converts through MultiCurrency's stored-rate fallback).
				if (
					'' !== $code
					&& '' !== $base
					&& $code !== $base
					&& empty( $data['manual_rate'] )
					&& ( $rate <= 0.0 || abs( $rate - 1.0 ) < 0.000001 )
				) {
					$foreign[ $code ] = true;
				}
			}

			if ( [] !== $foreign ) {
				return $this->critical(
					'perflocale_fx_staleness',
					__( 'Foreign-currency prices are being charged at the wrong rate', 'perflocale' ),
					sprintf(
						'<p>%1$s</p><p>%2$s</p>',
						sprintf(
							/* translators: 1: comma-separated currency codes, 2: the store's base currency, 3: time since last sync. */
							esc_html__( 'Automatic exchange-rate sync is on but no rate source is configured, and %1$s are priced against a %2$s base with no manual rate set. Those prices are being charged at the base-currency figure - a %2$s amount taken as though it were the customer\'s currency (last sync: %3$s).', 'perflocale' ),
							'<code>' . esc_html( implode( ', ', array_keys( $foreign ) ) ) . '</code>',
							'<code>' . esc_html( $base ) . '</code>',
							'<code>' . esc_html( $last_sync ) . '</code>'
						),
						sprintf(
							/* translators: 1: providers filter name, 2: rates filter name */
							esc_html__( 'No rate provider ships with the plugin. Register one with the %1$s filter, supply rates directly with the %2$s filter, set a manual rate per language in the currency settings, or turn auto-sync off.', 'perflocale' ),
							'<code>perflocale/woocommerce/exchange_rate_providers</code>',
							'<code>perflocale/woocommerce/exchange_rates_fetched</code>'
						)
					)
				);
			}

			return $this->recommended(
				'perflocale_fx_staleness',
				__( 'No exchange-rate source is configured', 'perflocale' ),
				sprintf(
					/* translators: 1: time since last sync, 2: providers filter name, 3: rates filter name */
					esc_html__( 'Automatic exchange-rate sync is on, but no rate source is configured, so rates cannot update (last sync: %1$s). Nothing is mispriced right now because no language is set to a currency other than the store base, but adding one would be. No provider ships with the plugin: register one with the %2$s filter, supply rates directly with the %3$s filter, or turn auto-sync off in the currency settings.', 'perflocale' ),
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
	 * Recommend files mode when database mode's per-language whole-map blob (all_string_translations_{lang}) gets large
	 * enough to cost every request memory and CPU; never switched automatically. Docs: docs-ai/11-code-rationale.md,
	 * "SiteHealth: string mode and translation files".
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
		 * Filter the rows per language at which the database-mode blob warning shows (default 20,000, about 4-6 MB).
		 *
		 * @hook perflocale/site_health/string_blob_threshold
		 * @param int $threshold Rows per language.
		 */
		$threshold = (int) apply_filters( 'perflocale/site_health/string_blob_threshold', 20000 );

		// The heaviest language decides; its GROUP BY scans the table on every synchronous Status render: cached an hour.
		$max_rows = get_transient( 'perflocale_string_blob_max' );

		if ( false === $max_rows ) {
			global $wpdb;
			$st_table = \PerfLocale\Database\Schema::table( 'string_translations' );

			// $st_table is Schema::table(): no input, nothing to bind.
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
	 * Files mode: are the `.l10n.php` files there and current? Database mode, or files mode with nothing to compile,
	 * passes; a compiled language without a file, or one whose newest translation is newer than its newest file, is
	 * recommended with the regenerate link.
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

		// The directory from the generator itself, so this test cannot drift from its output.
		$generator = new \PerfLocale\Strings\TranslationFileGenerator( $plugin->get( 'cache' ) );
		$files_dir = $generator->get_translations_dir();

		$files      = is_dir( $files_dir ) ? glob( $files_dir . '/*.l10n.php' ) : [];
		$file_count = is_array( $files ) ? count( $files ) : 0;

		// "Translations exist" as the generator's JOIN walk sees them, or orphans it skips would keep "regenerate" stuck;
		// heavy, so cached briefly (dropped on settings and string changes).
		$cached = get_transient( self::FILES_TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['compilable'], $cached['total_st'], $cached['newest_by_language'], $cached['per_language'] ) && is_array( $cached['per_language'] ) && is_array( $cached['newest_by_language'] ) ) {
			$compilable_translations = (int) $cached['compilable'];
			$total_st_rows           = (int) $cached['total_st'];
			$newest_by_language      = $cached['newest_by_language'];
			$per_language            = $cached['per_language'];
		} else {
			global $wpdb;
			$strings_table = \PerfLocale\Database\Schema::table( 'strings' );
			$groups_table  = \PerfLocale\Database\Schema::table( 'translation_groups' );
			$links_table   = \PerfLocale\Database\Schema::table( 'translation_links' );
			$st_table      = \PerfLocale\Database\Schema::table( 'string_translations' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Compilable rows (non-empty values), per language and by internal domain (no file for those), plus each language's
			// newest translation as UNIX_TIMESTAMP() in the session time zone every writer uses.
			$compiled_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.language_id AS lid, LEFT( s.domain, 1 ) = '_' AS is_internal, COUNT(*) AS cnt, UNIX_TIMESTAMP( MAX( st.updated_at ) ) AS newest FROM %i s
					INNER JOIN %i g ON g.id = s.group_id AND g.type = 'string'
					INNER JOIN %i l ON l.group_id = s.group_id
					INNER JOIN %i st ON st.string_id = s.id AND st.language_id = l.language_id
					WHERE st.translation <> ''
					GROUP BY l.language_id, is_internal",
					$strings_table,
					$groups_table,
					$links_table,
					$st_table
				)
			);

			$compilable_translations = 0;
			$per_language            = [];
			$newest_by_language      = [];

			foreach ( (array) $compiled_rows as $compiled_row ) {
				$row   = is_object( $compiled_row ) ? get_object_vars( $compiled_row ) : [];
				$count = is_numeric( $row['cnt'] ?? null ) ? (int) $row['cnt'] : 0;
				$lid   = is_numeric( $row['lid'] ?? null ) ? (int) $row['lid'] : 0;

				$compilable_translations += $count;

				if ( empty( $row['is_internal'] ) ) {
					$per_language[ $lid ]       = $count;
					$newest_by_language[ $lid ] = is_numeric( $row['newest'] ?? null ) ? (int) $row['newest'] : 0;
				}
			}

			// The table total; its difference to the compilable count is the orphan count, reported apart.
			$total_st_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i',
					$st_table
				)
			);

			// phpcs:enable

			set_transient(
				self::FILES_TRANSIENT,
				[
					'compilable'         => $compilable_translations,
					'total_st'           => $total_st_rows,
					'newest_by_language' => $newest_by_language,
					'per_language'       => $per_language,
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

		// Case 1: nothing compilable and no files; orphans are named, and Regenerate repairs them and writes the files.
		if ( ! $translations_exist && $file_count === 0 ) {
			if ( $orphan_count > 0 ) {
				return $this->recommended(
					'perflocale_translation_files',
					__( 'Some translations need to be re-linked', 'perflocale' ),
					sprintf(
						esc_html(
							/* translators: %d: number of stranded translations */
							_n(
								'%d saved translation is not connected to the file-generation pipeline, so the compiled files cannot be produced yet. Click "Regenerate translation files" - it will reconnect them and write the files in one step.',
								'%d saved translations are not connected to the file-generation pipeline, so the compiled files cannot be produced yet. Click "Regenerate translation files" - it will reconnect them and write the files in one step.',
								$orphan_count,
								'perflocale'
							)
						),
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

		// Case 2: a non-default language with compiled translations has no file. Also each language's newest file: a run
		// rewrites only changed files, so current means its newest file is not older than its newest translation.
		$missing = [];
		$stale   = [];

		foreach ( (array) $plugin->get( 'lang_repo' )->get_active() as $language ) {
			if ( ! is_object( $language ) || ! empty( $language->is_default ) || empty( $language->locale ) ) {
				continue;
			}

			$language_id = (int) $language->id;

			if ( (int) ( $per_language[ $language_id ] ?? 0 ) <= 0 ) {
				continue;
			}

			$name        = (string) $language->name;
			$locale      = (string) $language->locale;
			$suffix      = '-' . sanitize_file_name( $locale ) . '.l10n.php';
			$newest_file = 0;

			foreach ( is_array( $files ) ? $files : [] as $file ) {
				if ( str_ends_with( (string) $file, $suffix ) ) {
					$newest_file = max( $newest_file, (int) filemtime( (string) $file ) );
				}
			}

			if ( $newest_file > 0 ) {
				$newest_row = $newest_by_language[ $language_id ] ?? 0;
				$newest_row = is_numeric( $newest_row ) ? (int) $newest_row : 0;

				// Grace for files written in the same request as the row.
				if ( $newest_row > 0 && ( $newest_row - $newest_file ) > 60 ) {
					$stale[] = [ $name, $locale, $newest_row, $newest_file ];
				}

				continue;
			}

			$missing[] = sprintf( '%1$s (%2$s)', $name, $locale );
		}

		if ( $missing !== [] ) {
			return $this->recommended(
				'perflocale_translation_files',
				sprintf(
					/* translators: %s: comma-separated list of languages, each as "Name (locale)". */
					__( 'Compiled translation files are missing for: %s', 'perflocale' ),
					implode( ', ', $missing )
				),
				esc_html__( 'String translations exist for these languages, but no compiled `.l10n.php` file was found for their locale, so visitors see these strings untranslated. Regenerate the translation files; if they stay missing, check that the uploads directory is writable.', 'perflocale' ),
				$regen_link
			);
		}

		// Case 3: a language's newest translation is newer than its newest file.
		if ( $stale !== [] ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T';
			$lines  = [];

			foreach ( $stale as [ $stale_name, $stale_locale, $newest_row, $newest_file ] ) {
				$lines[] = sprintf(
					/* translators: 1: language name, 2: locale, 3: newest translation absolute time, 4: newest file absolute time */
					__( '%1$s (%2$s): newest translation %3$s, newest compiled file %4$s.', 'perflocale' ),
					$stale_name,
					$stale_locale,
					(string) wp_date( $format, $newest_row ),
					(string) wp_date( $format, $newest_file )
				);
			}

			return $this->recommended(
				'perflocale_translation_files',
				__( 'Translation files are stale', 'perflocale' ),
				'<p>' . esc_html__( 'These languages have translations newer than their compiled files. Regenerate so the file cache reflects the current DB state.', 'perflocale' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>',
				$regen_link
			);
		}

		// Case 4: healthy.
		return $this->pass(
			'perflocale_translation_files',
			__( 'Translation files are present', 'perflocale' ),
			sprintf(
				esc_html(
					/* translators: 1: file count, 2: filesystem path */
					_n(
						'%1$d compiled `.l10n.php` file at %2$s.',
						'%1$d compiled `.l10n.php` files at %2$s.',
						$file_count,
						'perflocale'
					)
				),
				$file_count,
				'<code>' . esc_html( str_replace( ABSPATH, '', $files_dir ) ) . '</code>'
			)
		);
	}

	/**
	 * Does the stored `perflocale_db_version` match PERFLOCALE_DB_VERSION? A mismatch means activation did not finish
	 * or a migration is pending.
	 *
	 * @return array<string, mixed>
	 */
	public function test_db_version(): array {
		// The schema version only; test_tables_exist() checks the tables. A version stamped ahead of the tables is the
		// half-run activation case.
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
	 * Report the optional PHP extensions that are missing and the one feature each costs; never critical.
	 *
	 * @return array<string, mixed>
	 */
	public function test_php_extensions(): array {
		// Next to the constant, not in it: __() cannot run in a class constant.
		$features = [
			'dom'       => __( 'XLIFF import', 'perflocale' ),
			'libxml'    => __( 'XLIFF import', 'perflocale' ),
			'xmlwriter' => __( 'XLIFF export', 'perflocale' ),
			'simplexml' => __( 'XML sitemaps (WordPress core needs this one too)', 'perflocale' ),
			// Only Helper::is_valid_url() uses filter_var(), with a fallback: the narrow description is the true one.
			'filter'    => __( 'stricter URL syntax validation when adding a webhook (a simpler built-in check is used without it)', 'perflocale' ),
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

		// The php-xml hint only for the four XML extensions: name the package that ships what is missing.
		$xml_family = array_intersect( $missing, [ 'dom', 'libxml', 'xmlwriter', 'simplexml' ] );
		$others     = array_diff( $missing, [ 'dom', 'libxml', 'xmlwriter', 'simplexml' ] );

		if ( [] === $others ) {
			$hint = esc_html__( 'On most hosts all of these arrive together in the php-xml package.', 'perflocale' );
		} elseif ( [] === $xml_family ) {
			$hint = sprintf(
				/* translators: %s: comma-separated list of PHP package names, e.g. "php-intl, php-curl". */
				esc_html__( 'These are separate packages on most hosts: %s.', 'perflocale' ),
				esc_html( implode( ', ', array_map( static fn( $e ): string => 'php-' . $e, $others ) ) )
			);
		} else {
			$hint = sprintf(
				/* translators: %s: comma-separated list of PHP package names, e.g. "php-intl". */
				esc_html__( 'The XML extensions above arrive together in the php-xml package; the rest are separate packages: %s.', 'perflocale' ),
				esc_html( implode( ', ', array_map( static fn( $e ): string => 'php-' . $e, $others ) ) )
			);
		}

		return $this->recommended(
			'perflocale_php_extensions',
			__( 'Some optional PHP extensions are not installed', 'perflocale' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				esc_html__( 'PerfLocale runs without these. Each one you add enables the feature listed beside it; everything else works either way.', 'perflocale' ),
				$items,
				$hint
			)
		);
	}

	/**
	 * Report booted add-ons with HasSchema whose stored version is behind their declared one (a migration not applied).
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
	 * Can the active provider's API host be resolved (egress firewall, DNS)? Auth and rate errors show on the request
	 * path instead.
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

		// Credentials first: without a key no request is made, so a resolvable host proves nothing. The editor's own
		// is_active_provider_ready() decides.
		if ( $plugin->has( 'cache' ) ) {
			try {
				$mt_service = new \PerfLocale\MachineTranslation\TranslationService( $settings, $plugin->get( 'cache' ) );

				if ( ! $mt_service->is_active_provider_ready() ) {
					// The AI Client has no credential: it needs a connected provider under Settings > Connectors.
					if ( 'wp_ai_client' === $provider ) {
						$connect = esc_html__( 'Connect an AI provider under Settings → Connectors.', 'perflocale' );

						return $this->critical(
							'perflocale_mt_reachability',
							__( 'Machine translation is on but the provider cannot run', 'perflocale' ),
							sprintf(
								'<p>%1$s</p><p>%2$s</p>',
								esc_html__( 'Machine translation is enabled and set to the WordPress AI Client, but no connected AI provider can generate text, so every translation request fails before it is sent.', 'perflocale' ),
								\PerfLocale\MachineTranslation\TranslationService::connectors_screen_exists()
									? '<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . $connect . '</a>'
									: $connect
							)
						);
					}

					return $this->critical(
						'perflocale_mt_reachability',
						__( 'Machine translation is on but the provider cannot run', 'perflocale' ),
						sprintf(
							'<p>%1$s</p><p>%2$s</p>',
							'' === $provider
								? esc_html__( 'Machine translation is enabled but no provider is selected, so every translation request fails before it is sent.', 'perflocale' )
								: sprintf(
									/* translators: %s: the selected machine-translation provider slug. */
									esc_html__( 'Machine translation is enabled and set to %s, but that provider has no API credential saved - so every translation request fails before it is sent.', 'perflocale' ),
									'<code>' . esc_html( $provider ) . '</code>'
								),
							esc_html__( 'Add the credential under PerfLocale → Settings → Addons → Machine Translation, or turn machine translation off so queued jobs stop retrying.', 'perflocale' )
						)
					);
				}
			} catch ( \Throwable $e ) {
				// The service could not be built: no verdict either way, go on to connectivity.
				unset( $e );
			}
		}

		$host = self::MT_PROVIDER_HOSTS[ $provider ] ?? '';

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

		// Cached, both outcomes: gethostbyname() blocks without a timeout of its own, and the failing case is the slow one.
		$dns_key  = 'perflocale_sh_mt_dns_' . \PerfLocale\Cache\CacheManager::flush_scoped_hash( $host );
		$resolved = get_transient( $dns_key );

		if ( ! is_string( $resolved ) || '' === $resolved ) {
			$resolved = gethostbyname( $host );

			set_transient( $dns_key, $resolved, self::COUNTS_TTL );
		}

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
	 * Files mode: is the upload target writable? Otherwise regeneration skips the site.
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

		// The generator's real directory, uploads/perflocale/translations/ (or its writable parent while missing).
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
	 * The object cache setting against the backend: on without a backend is per-request only; a backend with the
	 * setting off leaves no persistent caching at all (L2 and L3 both out).
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
			// With a drop-in, CacheManager writes no L3 transient fallback (transients live in the backend's shared group):
			// setting off plus a backend means no persistent caching at all. Reported, not green.
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
	 * Background jobs health: the daily GC event scheduled, an external cron when WP-Cron runs with DISABLE_WP_CRON,
	 * failing jobs, web refusals, and a paused queue (shown for support).
	 * Docs: docs-ai/11-code-rationale.md, "SiteHealth: failing jobs and untyped filter arguments".
	 *
	 * @return array<string, mixed>
	 */
	public function test_bg_jobs_health(): array {
		// Any engine, any args: on Action Scheduler the GC event carries a blog-id arg, so wp_next_scheduled() would false-alarm.
		$gc_scheduled = \PerfLocale\Background\BackgroundEvents::is_scheduled( 'perflocale_jobs_gc' );
		$engine       = \PerfLocale\Background\JobRunnerFactory::pick()->get_engine_name();
		$cron_off     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$plugin       = Plugin::get_instance();
		$paused       = $plugin->has( 'settings' )
			&& (bool) $plugin->get( 'settings' )->get( 'background_paused', false );

		// Jobs that ran and failed: the only signal of a pipeline failing everything (a revoked key passes the credential
		// check, and the breaker forgets after its window).
		global $wpdb;

		$jobs_table = \PerfLocale\Database\Schema::table( 'jobs' );
		$since      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site Health runs on a page view or a weekly cron tick, and a cached answer would defeat the point. `status` is indexed.
		$recent_failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND updated_at >= %s',
				$jobs_table,
				'failed',
				$since
			)
		);

		if ( $recent_failed > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
			$recent_ok = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE status = %s AND updated_at >= %s',
					$jobs_table,
					'complete',
					$since
				)
			);

			$last_error = self::last_job_error( $jobs_table, $since );
			$killed     = self::killed_job_errors( $jobs_table, $since );

			// "Everything failing" needs a sample (JOBS_ALL_DEAD_MIN); fewer failures are amber "some jobs are failing".
			$total    = $recent_failed + $recent_ok;
			$all_dead = 0 === $recent_ok && $recent_failed >= self::JOBS_ALL_DEAD_MIN;

			$body = sprintf(
				'<p>%1$s</p>%2$s',
				esc_html( sprintf(
					/* translators: 1: number of failed jobs, 2: total jobs run in the same period. */
					_n(
						'%1$d of the %2$d background jobs run in the last 24 hours failed.',
						'%1$d of the %2$d background jobs run in the last 24 hours failed.',
						$recent_failed,
						'perflocale'
					),
					$recent_failed,
					$total
				) ),
				'' !== $last_error
					? '<p>' . sprintf(
						/* translators: %s: the most recent error message recorded, e.g. for a failed job or a webhook delivery. */
						esc_html__( 'Most recent error: %s', 'perflocale' ),
						'<code>' . esc_html( mb_substr( $last_error, 0, 200 ) ) . '</code>'
					) . '</p>'
					: ''
			);

			foreach ( $killed as $killed_error ) {
				$body .= '<p>' . esc_html( $killed_error ) . '</p>';
			}

			if ( $all_dead ) {
				return $this->critical(
					'perflocale_bg_jobs_health',
					__( 'Every background job is failing', 'perflocale' ),
					$body . '<p>' . esc_html__( 'No job has completed in the last 24 hours. If machine translation is configured, check that its API credential is still valid - a revoked key fails every request while still looking present in the settings.', 'perflocale' ) . '</p>'
				);
			}

			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'Some background jobs are failing', 'perflocale' ),
				$body
			);
		}

		// Work refused on a web request in the last week because one request cannot finish it (WebLimit).
		$web_refused = [];

		foreach ( (array) get_option( 'perflocale_web_limit_refused', [] ) as $refusal ) {
			if ( is_array( $refusal ) && is_string( $refusal[0] ?? null ) && is_int( $refusal[1] ?? null ) && $refusal[1] > time() - WEEK_IN_SECONDS ) {
				$web_refused[] = $refusal[0];
			}
		}

		if ( [] !== $web_refused ) {
			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'Some tasks are too large for one web request on this site', 'perflocale' ),
				'<p>' . esc_html__( 'They were refused because they need longer than this site lets one web request run. Run them with WP-CLI:', 'perflocale' ) . '</p><ul><li><code>'
					. implode( '</code></li><li><code>', array_map( 'esc_html', $web_refused ) ) . '</code></li></ul>'
			);
		}

		// GC event missing first.
		if ( ! $gc_scheduled ) {
			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'Background-jobs garbage collection is not scheduled', 'perflocale' ),
				__( 'The daily perflocale_jobs_gc cron event is missing. Stuck jobs and stale lock rows will not be cleaned up. PerfLocale re-registers it automatically on any admin page load (checked at most once every 6 hours); saving a PerfLocale setting forces an immediate re-check.', 'perflocale' )
			);
		}

		// WP-Cron engine with DISABLE_WP_CRON and no external trigger.
		if ( $engine === 'wp_cron' && $cron_off ) {
			return $this->recommended(
				'perflocale_bg_jobs_health',
				__( 'WP-Cron is disabled and jobs are queued in WP-Cron', 'perflocale' ),
				// Name the real reason: "Force WP-Cron" bypasses an installed Action Scheduler, so "install it" would change nothing.
				( 'force_wp_cron' === (string) $plugin->get( 'settings' )->get( 'background_engine', '' ) )
					? __( 'Background jobs are queued in WP-Cron because the background engine is set to "Force WP-Cron", and DISABLE_WP_CRON is also set - so nothing will run them. Either point a system cron at wp-cron.php every minute, or change the background engine back to automatic in Settings → Performance so Action Scheduler can be used when it is available.', 'perflocale' )
					: __( 'Background jobs are enqueued in WP-Cron but DISABLE_WP_CRON is set. Configure an external system cron to hit wp-cron.php every minute, install WooCommerce or the Action Scheduler plugin, or jobs will sit in the queue indefinitely.', 'perflocale' )
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
	 * Errors of the jobs failed since $since because their worker was killed 3 times at one item (each names it),
	 * of the jobs the current user may open (JobAccess).
	 *
	 * @param string $jobs_table Jobs table.
	 * @param string $since      UTC MySQL time.
	 * @return array<int, string>
	 */
	private static function killed_job_errors( string $jobs_table, string $since ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site Health runs on a page view or a weekly cron tick; `status` is indexed.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, created_by, args, error FROM %i WHERE status = %s AND updated_at >= %s AND log LIKE %s ORDER BY updated_at DESC LIMIT 3',
				$jobs_table,
				'failed',
				$since,
				'%' . $wpdb->esc_like( '"k":"worker_killed"' ) . '%'
			),
			ARRAY_A
		);

		return array_values( array_filter( array_map( [ self::class, 'job_error_for_viewer' ], (array) $rows ), static fn( string $error ): bool => '' !== $error ) );
	}

	/**
	 * Error of the newest job failed since $since, '' when the current user may not open that job (JobAccess).
	 *
	 * @param string $jobs_table Jobs table.
	 * @param string $since      UTC MySQL time.
	 * @return string
	 */
	private static function last_job_error( string $jobs_table, string $since ): string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site Health runs on a page view or a weekly cron tick; `status` is indexed.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT type, created_by, args, error FROM %i WHERE status = %s AND updated_at >= %s ORDER BY updated_at DESC LIMIT 1',
				$jobs_table,
				'failed',
				$since
			),
			ARRAY_A
		);

		return self::job_error_for_viewer( $row );
	}

	/**
	 * A job row's error (type, created_by, args, error), '' when the current user may not open the job.
	 *
	 * @param mixed $row Row.
	 * @return string
	 */
	private static function job_error_for_viewer( mixed $row ): string {
		if ( ! is_array( $row ) || ! is_string( $row['error'] ?? null ) ) {
			return '';
		}

		$job = [
			'type'       => $row['type'] ?? '',
			'created_by' => $row['created_by'] ?? 0,
			'args'       => is_string( $row['args'] ?? null ) ? json_decode( $row['args'], true ) : null,
		];

		return \PerfLocale\Background\JobAccess::may_handle( $job ) ? $row['error'] : '';
	}

	/**
	 * With hreflang on, does the public home page emit an alternate `<link rel="alternate" hreflang>` per active language?
	 * Cached 5 minutes.
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
			// Older cache entries lack has_x_default: they omit the x-default sentence until they expire.
			return $this->hreflang_result(
				(int) $cached['count'],
				(int) $cached['expected'],
				! empty( $cached['has_x_default'] )
			);
		}

		$response = wp_remote_get(
			home_url( '/' ),
			[
				'timeout'             => 5,
				'redirection'         => 2,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name (defined in WP_Http::request). Renaming would break integration with WP core + third-party plugins that expect this canonical hook.
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'             => [ 'X-PerfLocale-Health-Check' => '1' ],
				// The <head> comes first; more of the page adds nothing.
				'limit_response_size' => MB_IN_BYTES,
			]
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return $this->recommended(
				'perflocale_hreflang_output',
				__( 'Homepage could not be fetched for the hreflang sanity check', 'perflocale' ),
				__( 'The Site Health probe could not reach the homepage over HTTP, so hreflang output could not be verified. This is usually a loopback-block or a self-signed-certificate issue.', 'perflocale' )
			);
		}

		// HreflangTags sends Link headers with $replace = false: a string or an array of them.
		$link_values = [];

		foreach ( (array) wp_remote_retrieve_header( $response, 'link' ) as $link_value ) {
			if ( is_string( $link_value ) && '' !== $link_value ) {
				$link_values[] = $link_value;
			}
		}

		$codes = self::hreflang_codes( (string) wp_remote_retrieve_body( $response ), $link_values );

		$has_x_default = isset( $codes['x-default'] );
		unset( $codes['x-default'] );

		$count = count( $codes );

		set_transient(
			self::HREFLANG_TRANSIENT,
			[
				'count'         => $count,
				'expected'      => $active_count,
				'has_x_default' => $has_x_default,
			],
			self::COUNTS_TTL
		);

		return $this->hreflang_result( $count, $active_count, $has_x_default );
	}

	/**
	 * Hreflang codes of the page's alternate <link> tags and Link header items, lowercased, as a set (x-default and a
	 * language in both places count once). Only the first MB is scanned (a pre_http_request answer is not cut).
	 *
	 * @param string             $body        Page HTML.
	 * @param array<int, string> $link_values Link header values.
	 * @return array<string, true>
	 */
	private static function hreflang_codes( string $body, array $link_values ): array {
		$codes = [];
		$tags  = new \WP_HTML_Tag_Processor( substr( $body, 0, MB_IN_BYTES ) );

		while ( $tags->next_tag( [ 'tag_name' => 'LINK' ] ) ) {
			$rel  = $tags->get_attribute( 'rel' );
			$code = $tags->get_attribute( 'hreflang' );

			if ( is_string( $rel ) && is_string( $code ) && '' !== trim( $code ) && in_array( 'alternate', (array) preg_split( '/\s+/', strtolower( $rel ), -1, PREG_SPLIT_NO_EMPTY ), true ) ) {
				$codes[ strtolower( trim( $code ) ) ] = true;
			}
		}

		// Item by item: one pattern across a whole header value is not linear without PCRE JIT.
		foreach ( $link_values as $link_value ) {
			foreach ( explode( ',', (string) $link_value ) as $item ) {
				if ( 1 === preg_match( '#\brel=["\']?alternate\b#i', $item ) && 1 === preg_match( '#\bhreflang=["\']?([A-Za-z0-9_-]+)#i', $item, $hm ) ) {
					$codes[ strtolower( $hm[1] ) ] = true;
				}
			}
		}

		return $codes;
	}

	/**
	 * The hreflang test's result row, shared by the cached and fresh paths.
	 *
	 * @param int $count Number of hreflang alternate links found.
	 * @param int $expected Number of active languages.
	 * @return array<string, mixed>
	 */
	private function hreflang_result( int $count, int $expected, bool $has_x_default = false ): array {
		if ( $count === 0 ) {
			return $this->recommended(
				'perflocale_hreflang_output',
				__( 'No hreflang alternate links detected on the homepage', 'perflocale' ),
				sprintf(
					/* translators: %s: the HTML tag being looked for, already escaped. */
					esc_html__( 'Hreflang output is enabled in settings but the homepage did not render any %s tags. Check theme or cache rules that might strip head elements, and make sure the homepage is translatable.', 'perflocale' ),
					// The tag escaped, or the browser would parse it and the sentence lose its subject.
					'<code>' . esc_html( '<link rel="alternate" hreflang="…">' ) . '</code>'
				)
			);
		}

		$x_note = $has_x_default
			? ' ' . esc_html__( 'An x-default link is also present; it points at the default-language version and is not counted as a language.', 'perflocale' )
			: '';

		if ( $count < $expected ) {
			// Not a fault: HreflangTags emits only languages with a translation of the page, so a site mid-translation shows
			// fewer links. Reported as information.
			return $this->pass(
				'perflocale_hreflang_output',
				__( 'Hreflang links are present for the translated languages', 'perflocale' ),
				sprintf(
					/* translators: 1: number of hreflang links found, 2: number of active languages. */
					esc_html__( 'The homepage links %1$d of %2$d active languages. PerfLocale only emits a language once that page has a translation, so this is expected while a site is still being translated. If a language you have already translated is missing here, check for a caching layer serving a pre-translation copy.', 'perflocale' ),
					$count,
					$expected
				) . $x_note
			);
		}

		return $this->pass(
			'perflocale_hreflang_output',
			__( 'Hreflang output matches the active-language list', 'perflocale' ),
			sprintf(
				/* translators: %d: number of hreflang alternate links found. */
				_n(
					'The homepage rendered %d hreflang alternate link - search engines can discover the translated languages.',
					'The homepage rendered %d hreflang alternate links - search engines can discover the translated languages.',
					$count,
					'perflocale'
				),
				$count
			) . $x_note
		);
	}

	// Info tab: PerfLocale's section.

	/**
	 * Add PerfLocale's section to the Site Health Info tab.
	 *
	 * `$info` is untyped on purpose: another plugin's callback that forgets to return passes null, and an `array` hint
	 * would make that a TypeError here; the value is returned unchanged. register_tests() keeps its hint (core merges).
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

		// Plugin and schema.
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

		// Routing and languages.
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

		// Translatable scope.
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

		// Translation data (cached counts).
		$counts = $this->get_counts();

		foreach ( $counts as $key => $value ) {
			$fields[ 'counts_' . $key ] = [
				'label' => $this->count_label( $key ),
				'value' => number_format_i18n( (int) $value ),
			];
		}

		// Machine translation.
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

		// SEO.
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

		// Integrations.
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

			// Size of the add-on settings option (the 16 KiB cap is per add-on entry; the total shows what grows).
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

		// WooCommerce (only when active).
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

		// Performance.
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
				? __( 'Yes (affects scheduled FX sync, webhook delivery, and background jobs when Action Scheduler is not loaded - make sure an external cron hits wp-cron.php)', 'perflocale' )
				: __( 'No', 'perflocale' ),
		];

		// Background jobs: engine and queue state, so a pasted report answers "my import is not running".
		$bg_engine     = \PerfLocale\Background\JobRunnerFactory::pick()->get_engine_name();
		$bg_as_loaded  = \PerfLocale\Background\JobRunnerFactory::action_scheduler_available();
		$bg_paused     = (bool) $settings->get( 'background_paused', false );
		$bg_processing = (string) $settings->get( 'background_processing', 'auto' );
		$bg_counts     = Schema::tables_exist()
			? \PerfLocale\Background\JobState::count_by_status( [ 'queued', 'running' ] )
			: null;

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
			'value' => null === $bg_counts ? __( 'unknown', 'perflocale' ) : (string) array_sum( $bg_counts ),
		];

		// PHP extensions: one line with every optional extension, present or absent, for support reports.
		$ext_state = [];

		foreach ( self::FEATURE_PHP_EXTENSIONS as $ext ) {
			$ext_state[] = $ext . ( extension_loaded( $ext ) ? ' ✓' : ' ✗' );
		}

		$fields['php_extensions'] = [
			'label' => __( 'Optional PHP extensions', 'perflocale' ),
			'value' => implode( ', ', $ext_state ),
		];

		// The importers' effective batch sizes (a filter override explains most slow or out-of-memory imports).
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

		// Secrets: configured or not, never the value.
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

	// Helpers.

	/**
	 * Flush the cached counts (settings and string changes).
	 *
	 * @return void
	 */
	public function flush_counts_cache(): void {
		delete_transient( self::COUNTS_TRANSIENT );
		delete_transient( self::HREFLANG_TRANSIENT );
		delete_transient( self::FILES_TRANSIENT );

		// Nothing writes this key; deleting it removes an upgraded site's leftover row.
		delete_transient( self::MT_SCORING_TRANSIENT );
	}

	/**
	 * Translation, string and memory counts, cached 5 minutes (the COUNTs add up on large sites).
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
	 * The label of a get_counts() key; an unknown key shows as itself, never mislabelled.
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
	 * Report webhook deliveries given up on (exhausted retries, refused by the delivery-time safety check) in the last
	 * 24 hours, from the log WebhookController::record_failure() writes; nothing else reads it, so this keeps a dropped
	 * event visible. Docs: docs-ai/11-code-rationale.md, "SiteHealth: cron and stuck checks".
	 *
	 * @return array<string, mixed>
	 */
	public function test_webhook_failures(): array {
		$id  = 'perflocale_webhook_failures';
		$log = get_option( 'perflocale_webhook_failures', [] );

		if ( ! is_array( $log ) || [] === $log ) {
			return $this->pass(
				$id,
				__( 'No webhook delivery failures recorded', 'perflocale' ),
				esc_html__( 'PerfLocale has not given up on any webhook delivery. This card stays green when no webhooks are configured.', 'perflocale' )
			);
		}

		$cutoff  = time() - DAY_IN_SECONDS;
		$recent  = [];
		$by_hook = [];

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// failed_at is written with gmdate('Y-m-d H:i:s'), so parse as UTC.
			$when = strtotime( (string) ( $entry['failed_at'] ?? '' ) . ' UTC' );

			if ( ! is_int( $when ) || $when < $cutoff ) {
				continue;
			}

			$recent[] = $entry;

			$hook             = (string) ( $entry['webhook_id'] ?? '?' );
			$by_hook[ $hook ] = ( $by_hook[ $hook ] ?? 0 ) + 1;
		}

		if ( [] === $recent ) {
			return $this->pass(
				$id,
				__( 'No recent webhook delivery failures', 'perflocale' ),
				sprintf(
					/* translators: %d: number of older failure entries still in the log. */
					esc_html( _n(
						'There is %d older delivery failure in the log, but none in the last 24 hours.',
						'There are %d older delivery failures in the log, but none in the last 24 hours.',
						count( $log ),
						'perflocale'
					) ),
					count( $log )
				)
			);
		}

		$last  = end( $recent );
		$items = '';

		foreach ( $by_hook as $hook => $count ) {
			$items .= sprintf(
				'<li><code>%1$s</code> - %2$s</li>',
				esc_html( $hook ),
				esc_html( sprintf(
					/* translators: %d: number of failed deliveries for one webhook. */
					_n( '%d failed delivery', '%d failed deliveries', $count, 'perflocale' ),
					$count
				) )
			);
		}

		return $this->recommended(
			$id,
			__( 'Webhook deliveries are failing', 'perflocale' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul><p>%3$s</p>',
				esc_html( sprintf(
					/* translators: %d: number of failed deliveries in the last 24 hours. */
					_n(
						'%d webhook delivery was abandoned in the last 24 hours after its retries were exhausted or its address was refused.',
						'%d webhook deliveries were abandoned in the last 24 hours after their retries were exhausted or their addresses were refused.',
						count( $recent ),
						'perflocale'
					),
					count( $recent )
				) ),
				$items,
				sprintf(
					/* translators: %s: the most recent error message recorded, e.g. for a failed job or a webhook delivery. */
					esc_html__( 'Most recent error: %s', 'perflocale' ),
					'<code>' . esc_html( (string) ( $last['error'] ?? '' ) ) . '</code>'
				)
			)
		);
	}

	/**
	 * @return string
	 */
	private function detect_builder(): string {
		$candidates = [
			'ELEMENTOR_VERSION' => 'Elementor',
			'FLBuilder'         => 'Beaver Builder',
			'BRICKS_VERSION'    => 'Bricks',
			// oxygen/functions.php defines CT_VERSION.
			'CT_VERSION'        => 'Oxygen Classic',
		];

		$found = [];

		foreach ( $candidates as $key => $name ) {
			if ( defined( $key ) || class_exists( $key ) ) {
				$found[] = $name;
			}
		}

		// Oxygen 6 / Breakdance: a top-level const __BREAKDANCE_VERSION (defined() sees it) and BREAKDANCE_MODE 'oxygen'
		// when shipped as Oxygen, which tells the two apart.
		if ( defined( '__BREAKDANCE_VERSION' ) || defined( 'BREAKDANCE_MODE' ) ) {
			$mode = defined( 'BREAKDANCE_MODE' ) ? (string) constant( 'BREAKDANCE_MODE' ) : '';

			$found[] = 'oxygen' === $mode ? 'Oxygen 6' : 'Breakdance';
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

	// Eager-link map, cron, stuck and orphan tests.

	/**
	 * Report the autoloaded eager-link map's state: it speeds up the front end but grows with the group count, and past a
	 * few hundred KB its alloptions cost dominates and the loader writes the 'too_large' sentinel that turns it off.
	 *
	 * @return array<string, mixed>
	 */
	public function test_eager_link_map(): array {
		$id           = 'perflocale_eager_link_map';
		// Options `perflocale_eager_links_{type}` (TranslationGroupRepository::get_eager_link_map()) hold the map array, the
		// 'too_large' or 'empty' string sentinel, or nothing; keep the is_array() guard (a string cast of the array would
		// warn and measure 5 bytes). The size is the serialized form.
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
	 * Are the four recurring events scheduled, and is anything running them (DISABLE_WP_CRON without Action Scheduler)?
	 *
	 * @return array<string, mixed>
	 */
	public function test_cron_schedule(): array {
		$id      = 'perflocale_cron_schedule';
		$expects = [
			'perflocale_jobs_watchdog' => __( 'background-jobs watchdog', 'perflocale' ),
			'perflocale_jobs_gc'       => __( 'background-jobs garbage collector', 'perflocale' ),
			'perflocale_lock_cleanup'  => __( 'expired-lock reaper', 'perflocale' ),
			// The fourth unconditional recurring hook: ensure_recurring_schedules() creates it on every site.
			'perflocale_mt_usage_gc'   => __( 'machine-translation usage cleanup', 'perflocale' ),
		];

		$missing = [];
		foreach ( array_keys( $expects ) as $hook ) {
			// Any args, both engines (see test_bg_jobs_health()).
			if ( ! \PerfLocale\Background\BackgroundEvents::is_scheduled( $hook ) ) {
				$missing[] = $hook;
			}
		}

		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$as_available     = class_exists( '\\ActionScheduler' ) || function_exists( 'as_enqueue_async_action' );

		// DISABLE_WP_CRON with a system crontab is the recommended setup, so it proves nothing alone: an event overdue by
		// more than CRON_OVERDUE_GRACE is the proof that nothing runs the queue.
		if ( $wp_cron_disabled && ! $as_available ) {
			$now     = time();
			$overdue = [];

			foreach ( array_keys( $expects ) as $hook ) {
				$next = \PerfLocale\Background\BackgroundEvents::next_run( $hook );

				// Only a timestamp is evidence; null (nothing pending) is the $missing branch's.
				if ( is_int( $next ) && ( $now - $next ) > self::CRON_OVERDUE_GRACE ) {
					$overdue[] = $expects[ $hook ];
				}
			}

			if ( $overdue !== [] ) {
				return $this->critical(
					$id,
					__( 'PerfLocale background jobs are not running', 'perflocale' ),
					sprintf(
						'<p>%1$s</p><p>%2$s</p>',
						sprintf(
							/* translators: 1: comma-separated list of overdue event names, 2: how long they are overdue, e.g. "3 hours". */
							esc_html__( 'These recurring events are more than %2$s past their scheduled time and have not run: %1$s.', 'perflocale' ),
							esc_html( implode( ', ', $overdue ) ),
							esc_html( human_time_diff( 0, self::CRON_OVERDUE_GRACE ) )
						),
						esc_html__( 'DISABLE_WP_CRON is set and Action Scheduler is not available, so WordPress will not run these by itself. If you use a system cron, check that it still reaches wp-cron.php. Otherwise install Action Scheduler (WooCommerce ships it) or set DISABLE_WP_CRON back to false.', 'perflocale' )
					)
				);
			}

			// Events are current, so something runs them (usually the operator's cron): say what was observed.
			if ( $missing === [] ) {
				return $this->pass(
					$id,
					__( 'PerfLocale cron schedules are healthy', 'perflocale' ),
					esc_html__( 'DISABLE_WP_CRON is set and Action Scheduler is not available, but every PerfLocale recurring event is scheduled and none is overdue - so an external cron is reaching wp-cron.php as intended. This is the recommended production setup.', 'perflocale' )
				);
			}
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
			// Names every event the test checks.
			esc_html__( 'All PerfLocale recurring events (watchdog, garbage collector, lock reaper, machine-translation usage cleanup) are scheduled and will run on their normal cadence.', 'perflocale' )
		);
	}

	/**
	 * Count translations in `in_progress` or `pending` for more than STUCK_AGE_DAYS, cached HEAVY_TTL.
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

			// Aged by the database clock: the column is stamped by the database in its own time zone, so a gmdate() string
			// would mis-age rows by its UTC offset.
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
	 * Count translation_links rows whose post or term is gone (direct imports, destructive plugins, manual deletes),
	 * cached HEAVY_TTL.
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
	 * The result shape every test returns.
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
	 * Is the export directory protected on this server? nginx and Caddy ignore its .htaccess, so a random canary is
	 * written and fetched over HTTP, then deleted; cached an hour. Docs: docs-ai/11-code-rationale.md, "SiteHealth:
	 * export exposure probe".
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
		 * Filter whether to run the export exposure probe (a temporary file and one loopback request). False skips only the
		 * measurement, for hosts where loopback is blocked and the verdict cannot be cached; the advice still shows, and an
		 * unverified state is never `good`.
		 *
		 * @hook perflocale/site_health/probe_export_exposure Set false to skip the active export-exposure probe.
		 * @param bool $probe Whether to write the canary and make the request. Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/site_health/probe_export_exposure', true ) ) {
			return $this->recommended(
				$id,
				__( 'PerfLocale export exposure check is disabled', 'perflocale' ),
				esc_html__( 'The active check has been switched off on this site, so PerfLocale has not verified whether export files are reachable over the web. If this site runs on nginx or Caddy, add the export deny rule from the installation notes and confirm it yourself - .htaccess alone does not protect that directory on those servers.', 'perflocale' )
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

		// An inconclusive run is cached too (for less), or hosts that block loopback pay the timeout on every load.
		if ( 'unknown' === $cached ) {
			return $this->recommended(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'This site could not make a request to itself, so the check was skipped. That is common on hosts that block loopback requests and does not by itself indicate a problem. If your server is nginx or Caddy, add the export deny rule from the installation notes anyway.', 'perflocale' )
			);
		}

		$dir = \PerfLocale\Helper::uploads_exports_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			// Not cached: the directory appearing should make the check run for real.
			return $this->pass(
				$id,
				__( 'PerfLocale export directory does not exist yet', 'perflocale' ),
				esc_html__( 'No export has been created on this site, so there is nothing to expose. This check runs again once the directory exists.', 'perflocale' )
			);
		}

		// Random name and body: the body proves the file was served, not a 200 error page.
		$token  = wp_generate_password( 24, false, false );
		$name   = 'perflocale-healthcheck-' . $token . '.txt';
		$path   = trailingslashit( $dir ) . $name;
		$body   = 'perflocale-canary-' . $token;
		$upload = wp_upload_dir();

		if ( ! is_array( $upload ) || empty( $upload['baseurl'] ) ) {
			set_transient( 'perflocale_exports_exposed', 'unknown', 15 * MINUTE_IN_SECONDS );

			return $this->recommended(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'The uploads directory URL is unavailable, so PerfLocale could not verify whether exports are reachable over the web. This is not itself evidence of a problem, but it is not evidence of safety either. If this site runs on nginx or Caddy, add the export deny rule from the installation notes.', 'perflocale' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A canary write that must not raise on a read-only directory; failure is handled immediately below.
		$written = @file_put_contents( $path, $body );

		if ( false === $written ) {
			set_transient( 'perflocale_exports_exposed', 'unknown', 15 * MINUTE_IN_SECONDS );

			return $this->recommended(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'The export directory is not writable from PHP, so PerfLocale could not place its test file. A directory that cannot be written cannot receive new exports, but any export already in it is still served by the web server. If this site runs on nginx or Caddy, add the export deny rule from the installation notes.', 'perflocale' )
			);
		}

		$url = trailingslashit( $upload['baseurl'] ) . 'perflocale/exports/' . $name;

		$response = wp_remote_get(
			$url,
			[
				// A static file on this very server; slower means the host blocks loopback (the WP_Error branch).
				'timeout'     => 3,
				'redirection' => 0,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name (defined in WP_Http::request).
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [ 'X-PerfLocale-Health-Check' => '1' ],
			]
		);

		// The canary always goes.
		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			set_transient( 'perflocale_exports_exposed', 'unknown', 15 * MINUTE_IN_SECONDS );

			return $this->recommended(
				$id,
				__( 'PerfLocale export exposure could not be checked', 'perflocale' ),
				esc_html__( 'The site could not make a request to itself, so PerfLocale could not verify whether exports are reachable over the web. That is common on hosts that block loopback requests and is not itself evidence of a problem - but it is not evidence of safety either, and .htaccess does not protect this directory on nginx or Caddy. Add the export deny rule from the installation notes.', 'perflocale' )
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
