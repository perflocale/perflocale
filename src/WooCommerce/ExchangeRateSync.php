<?php
/**
 * Automatic exchange rate synchronisation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches live exchange rates from a configurable provider on a
 * scheduled interval and updates the per-language currency settings.
 *
 * Providers can be extended via the
 * {@see perflocale/woocommerce/exchange_rate_providers} filter.
 */
final class ExchangeRateSync {

	/**
	 * WordPress cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'perflocale_exchange_rate_sync';

	/**
	 * Option key for last sync metadata.
	 *
	 * @var string
	 */
	public const LAST_SYNC_OPTION = 'perflocale_exchange_rates_last_sync';

	/**
	 * Dedicated option holding machine-fetched rates keyed by language slug.
	 *
	 * Kept separate from the main `perflocale_settings` blob so concurrent
	 * writers (cron + manual sync + admin settings save) can't clobber each
	 * other's updates in a read-modify-write cycle on the settings array.
	 *
	 * @var string
	 */
	public const RATES_OPTION = 'perflocale_exchange_rates';

	/**
	 * Built-in provider definitions.
	 *
	 * @var array<string, array{name: string, needs_key: bool, key_setting: string}>
	 */
	private const PROVIDERS = [];

	/**
	 * Available sync intervals.
	 *
	 * Keys map to WordPress cron schedule names.
	 *
	 * @var array<string, string>
	 */
	private const INTERVALS = [
		'hourly'         => 'Every Hour',
		'every_2_hours'  => 'Every 2 Hours',
		'every_4_hours'  => 'Every 4 Hours',
		'every_6_hours'  => 'Every 6 Hours',
		'every_8_hours'  => 'Every 8 Hours',
		'every_12_hours' => 'Every 12 Hours',
		'daily'          => 'Once Daily',
		'weekly'         => 'Once Weekly',
		'monthly'        => 'Once Monthly',
	];

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Register custom cron schedules.
		add_filter( 'cron_schedules', [ $this, 'register_schedules' ] );

		// Handle the cron event via a thin void wrapper. The hook ignores
		// return values; sync_rates() returns the rates array because the
		// AJAX caller at line ~872 needs them. Splitting keeps PHPStan happy
		// (action callbacks shouldn't appear to return data) without losing
		// the typed return for the AJAX path.
		add_action( self::CRON_HOOK, [ $this, 'cron_sync_rates' ] );

		// Reschedule when settings change.
		add_action( 'perflocale/settings/updated', [ $this, 'maybe_reschedule' ] );

		// NOTE: The AJAX handler (wp_ajax_perflocale_sync_exchange_rates) is
		// registered directly in Bootstrap::init() so it works regardless of
		// addon boot order. See Bootstrap.php.

		// Surface a notice when auto-sync is enabled but rates have gone
		// stale - e.g. a low-traffic site where WP-Cron missed runs, or a
		// provider has been returning errors. Only register the hook when
		// auto-sync is actually on, so sites that run rates manually don't
		// pay the per-admin-page callback cost.
		if ( (bool) $this->settings->get( 'wc_exchange_rate_auto', false ) ) {
			add_action( 'admin_notices', [ $this, 'maybe_render_stale_notice' ] );
		}

		// Ensure the cron is scheduled on first load. Reconciliation costs an
		// Action Scheduler table query when auto-sync is on (or an unschedule
		// sweep when off), so it only runs in write contexts — admin, AJAX,
		// cron, REST, WP-CLI. The authoritative scheduling paths are the
		// settings-update hook above and activation; this boot-time call is a
		// self-heal for lost schedules, and plain frontend GETs never pay it.
		if ( \PerfLocale\Helper::is_write_context() ) {
			$this->ensure_scheduled();
		}
	}

	/**
	 * Staleness threshold multiplier applied to the configured interval.
	 *
	 * If rates haven't synced within `interval × STALE_THRESHOLD_MULTIPLIER`,
	 * the admin notice fires. 2× tolerates one skipped cron run before
	 * alerting.
	 */
	private const STALE_THRESHOLD_MULTIPLIER = 2;

	/**
	 * Determine whether the last successful FX sync is beyond the stale
	 * threshold for the configured interval.
	 *
	 * @return bool True if rates are stale (or never synced while auto-sync enabled).
	 */
	public function is_stale(): bool {
		if ( ! (bool) $this->settings->get( 'wc_exchange_rate_auto', false ) ) {
			return false;
		}

		$last = get_option( self::LAST_SYNC_OPTION, [] );

		if ( ! is_array( $last ) || empty( $last['timestamp'] ) ) {
			return true;
		}

		$interval = (string) $this->settings->get( 'wc_exchange_rate_interval', 'daily' );
		$seconds  = $this->interval_seconds( $interval );

		return ( time() - (int) $last['timestamp'] ) > ( $seconds * self::STALE_THRESHOLD_MULTIPLIER );
	}

	/**
	 * Whether any rate source is wired at all.
	 *
	 * Mirrors the abort condition in do_sync_rates(): rates can only arrive
	 * from a provider registered through `exchange_rate_providers` and then
	 * selected in settings, or straight from the `exchange_rates_fetched`
	 * filter. No provider ships with the plugin, so a site that enables
	 * auto-sync without wiring either seam can never complete a sync — and
	 * must be told that, rather than sent to check WP-Cron.
	 *
	 * @return bool True when a sync could actually fetch something.
	 */
	public function has_rate_source(): bool {
		$providers   = $this->get_providers();
		$provider_id = (string) $this->settings->get( 'wc_exchange_rate_provider', '' );

		if ( isset( $providers[ $provider_id ] ) ) {
			return true;
		}

		return has_filter( 'perflocale/woocommerce/exchange_rates_fetched' );
	}

	/**
	 * Resolve a cron-interval slug to a second count.
	 *
	 * @param string $interval Interval slug.
	 * @return int Seconds.
	 */
	private function interval_seconds( string $interval ): int {
		return match ( $interval ) {
			'hourly' => HOUR_IN_SECONDS,
			'every_2_hours' => 2 * HOUR_IN_SECONDS,
			'every_4_hours' => 4 * HOUR_IN_SECONDS,
			'every_6_hours' => 6 * HOUR_IN_SECONDS,
			'every_8_hours' => 8 * HOUR_IN_SECONDS,
			'every_12_hours' => 12 * HOUR_IN_SECONDS,
			'daily' => DAY_IN_SECONDS,
			'weekly' => WEEK_IN_SECONDS,
			'monthly' => 30 * DAY_IN_SECONDS,
			default => DAY_IN_SECONDS,
		};
	}

	/**
	 * Render the stale-rates admin notice for shop managers.
	 *
	 * @return void
	 */
	public function maybe_render_stale_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->is_stale() ) {
			return;
		}

		$last        = (array) get_option( self::LAST_SYNC_OPTION, [] );
		$timestamp   = (int) ( $last['timestamp'] ?? 0 );
		$last_synced = $timestamp > 0
			? human_time_diff( $timestamp ) . ' ' . __( 'ago', 'perflocale' )
			: __( 'never', 'perflocale' );

		// Two very different causes produce the same stale state, and the
		// remedies are not interchangeable: if no rate source is wired, both
		// the scheduled sync and the Sync Now button abort before any network
		// call, so telling the operator to check WP-Cron sends them chasing a
		// working cron. Say which one it actually is.
		$message = $this->has_rate_source()
			? sprintf(
				/* translators: %s: Human-readable time since last sync, or "never". */
				__( 'Exchange rates have not been updated recently (last sync: %s). Check that WP-Cron is running, or sync manually from the Currencies settings.', 'perflocale' ),
				$last_synced
			)
			: sprintf(
				/* translators: %s: Human-readable time since last sync, or "never". */
				__( 'Automatic exchange-rate sync is on, but no rate source is configured, so rates cannot update (last sync: %s). Register a provider with the perflocale/woocommerce/exchange_rate_providers filter, supply rates directly with perflocale/woocommerce/exchange_rates_fetched, or turn auto-sync off in the Currencies settings.', 'perflocale' ),
				$last_synced
			);

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'PerfLocale:', 'perflocale' ),
			esc_html( $message )
		);
	}

	// ------------------------------------------------------------------
	// Cron scheduling
	// ------------------------------------------------------------------

	/**
	 * Register custom cron intervals.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_schedules( array $schedules ): array {
		// Schedule names are registered in WordPress's shared, global
		// cron-schedules registry, so they carry the plugin's `perflocale_`
		// prefix to avoid colliding with intervals registered by other
		// plugins. The user-facing interval *setting* slugs (see INTERVALS)
		// stay unprefixed and are mapped to these names by cron_schedule_name().
		$custom = [
			'perflocale_every_2_hours'  => [
				'interval' => 2 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 2 Hours', 'perflocale' ),
			],
			'perflocale_every_4_hours'  => [
				'interval' => 4 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 4 Hours', 'perflocale' ),
			],
			'perflocale_every_6_hours'  => [
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 Hours', 'perflocale' ),
			],
			'perflocale_every_8_hours'  => [
				'interval' => 8 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 8 Hours', 'perflocale' ),
			],
			'perflocale_every_12_hours' => [
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 12 Hours', 'perflocale' ),
			],
			'perflocale_monthly'        => [
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'perflocale' ),
			],
		];

		foreach ( $custom as $key => $schedule ) {
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = $schedule;
			}
		}

		return $schedules;
	}

	/**
	 * Map an interval setting slug to the WP-Cron schedule name used by the
	 * native-cron fallback path.
	 *
	 * Stock WordPress schedules (`hourly`, `daily`, `weekly`) are returned
	 * as-is; the plugin's custom intervals resolve to their prefixed
	 * `cron_schedules` registration name (see register_schedules()).
	 *
	 * @param string $interval Interval setting slug.
	 * @return string WP-Cron schedule name.
	 */
	private function cron_schedule_name( string $interval ): string {
		return match ( $interval ) {
			'hourly', 'daily', 'weekly' => $interval,
			'every_2_hours', 'every_4_hours', 'every_6_hours',
			'every_8_hours', 'every_12_hours', 'monthly' => 'perflocale_' . $interval,
			default => 'daily',
		};
	}

	/**
	 * Ensure the cron event is scheduled when auto-sync is enabled.
	 *
	 * @return void
	 */
	private function ensure_scheduled(): void {
		if ( ! (bool) $this->settings->get( 'wc_exchange_rate_auto', false ) ) {
			// Auto-sync disabled - clear any existing schedule.
			$this->unschedule();
			return;
		}

		if ( BackgroundEvents::is_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$interval = (string) $this->settings->get( 'wc_exchange_rate_interval', 'daily' );

		if ( ! isset( self::INTERVALS[ $interval ] ) ) {
			$interval = 'daily';
		}

		// Route through BackgroundEvents so Action Scheduler picks it up
		// when loaded (more reliable than WP-Cron on low-traffic stores -
		// AS persists scheduled actions and catches up after missed runs).
		// WP-Cron is used as fallback when AS isn't available.
		BackgroundEvents::enqueue_recurring(
			self::CRON_HOOK,
			time(),
			$this->interval_seconds( $interval ),
			$this->cron_schedule_name( $interval )
		);
	}

	/**
	 * Reschedule when settings change (interval or auto-sync toggle).
	 *
	 * @return void
	 */
	public function maybe_reschedule(): void {
		$this->unschedule();
		$this->ensure_scheduled();
	}

	/**
	 * Clear the scheduled cron event in both back-ends.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		BackgroundEvents::unschedule_recurring( self::CRON_HOOK );
	}

	// ------------------------------------------------------------------
	// Rate fetching
	// ------------------------------------------------------------------

	/**
	 * Void-return cron callback. Delegates to sync_rates() and discards
	 * the returned rates array (cron has no consumer). Pairs with the
	 * AJAX handler which still calls sync_rates() directly for the array.
	 *
	 * @return void
	 */
	public function cron_sync_rates(): void {
		$this->sync_rates();
	}

	/**
	 * Sync exchange rates from the configured provider.
	 *
	 * Called manually via AJAX (which uses the return value) or indirectly
	 * via cron_sync_rates() (which discards it).
	 *
	 * @return array<string, float> Fetched rates (currency_code => rate), empty on failure.
	 */
	public function sync_rates(): array {
		// Bail out if the plugin is being uninstalled - the WC/options
		// state we touch may be partially dropped.
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return [];
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			$this->log_sync_error( 'WooCommerce is not active.' );
			return [];
		}

		// Circuit breaker: if recent fetches failed enough to trip the
		// breaker, skip this tick. The breaker covers the FX provider
		// only — config errors below (no API key, no targets) bypass
		// it because those are operator misconfigurations that retrying
		// won't fix. Default threshold of 3 failures opens for 5min
		// (so a daily-cron sync only loses one tick per breaker cycle);
		// override via `perflocale/breaker/cooldown_seconds/fx_sync`.
		$breaker_key = 'fx_sync';

		if ( \PerfLocale\Concurrency\Breaker::is_open( $breaker_key ) ) {
			$status = \PerfLocale\Concurrency\Breaker::status( $breaker_key );
			$this->log_sync_error(
				sprintf(
				/* translators: %d: seconds until probe */
					'Sync skipped (circuit breaker open; retry in %ds).',
					(int) ( $status['cooldown_remaining'] ?? 0 )
				)
			);
			return [];
		}

		// Serialise concurrent ticks so two AS workers can't both hit
		// the upstream provider (double-billing the quota), both write
		// to RATES_OPTION (race-free thanks to update_option being
		// atomic, but wasteful), and both fire the synced action twice.
		// 5min lock TTL is more than enough — the full fetch + parse
		// path runs in well under a second even with a slow provider.
		$result = \PerfLocale\Concurrency\Lock::with(
			'exchange_rate_sync',
			5 * MINUTE_IN_SECONDS,
			function (): array {
				return $this->do_sync_rates();
			}
		);

		// Lock contention → another tick is in flight. Treat as no-op;
		// the other tick's record_success/failure is canonical.
		return $result ?? [];
	}

	/**
	 * Body of sync_rates(), executed under the `exchange_rate_sync`
	 * lock so two concurrent ticks can't double-hit the provider.
	 *
	 * @return array<string, float> Fetched rates (currency_code => rate),
	 *                              empty on failure.
	 */
	private function do_sync_rates(): array {
		$breaker_key = 'fx_sync';

		// Track recurring-handler timing for the Jobs admin panel.
		$started_at = time();

		// Capture blog context up front. On multisite with switch_to_blog,
		// a stale context would cause us to read one blog's WC currency
		// while writing another blog's rates - a subtle source of cross-
		// site bleed. Single-site installs skip the check entirely.
		$origin_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		// Read the raw WC currency option - NOT get_woocommerce_currency() which
		// passes through the woocommerce_currency filter. MultiCurrency hooks
		// that filter to return the current language's currency (e.g. BGN),
		// which would cause rates to be fetched relative to the wrong base.
		$base_currency = (string) get_option( 'woocommerce_currency', 'USD' );

		// If something switched us mid-flight, bail rather than persisting
		// rates into the wrong blog's options.
		if ( is_multisite() && $origin_blog_id > 0 && $origin_blog_id !== (int) get_current_blog_id() ) {
			$this->log_sync_error( 'Aborting sync: blog context changed mid-request.' );
			return [];
		}
		$providers   = $this->get_providers();
		$provider_id = (string) $this->settings->get( 'wc_exchange_rate_provider', '' );
		$provider    = $providers[ $provider_id ] ?? null;

		// No rate providers ship with the plugin. Rates come from EITHER a
		// provider registered through `exchange_rate_providers` (which gets
		// this class's caching, breaker and key handling) OR straight from
		// the `exchange_rates_fetched` filter. Only abort when NEITHER seam
		// is wired — otherwise a site that supplies rates purely through the
		// filter could never sync, because the filter runs further down.
		$has_rate_filter = has_filter( 'perflocale/woocommerce/exchange_rates_fetched' );

		if ( null === $provider && ! $has_rate_filter ) {
			$this->log_sync_error(
				$provider_id === ''
					? 'No exchange-rate provider configured. Register one via the perflocale/woocommerce/exchange_rate_providers filter, or supply rates directly with perflocale/woocommerce/exchange_rates_fetched.'
					: 'Unknown provider: ' . $provider_id
			);
			return [];
		}

		// Resolve API key.
		$api_key = '';

		if ( null !== $provider && ! empty( $provider['needs_key'] ) && ! empty( $provider['key_setting'] ) ) {
			$api_key = (string) $this->settings->get( $provider['key_setting'], '' );

			if ( $api_key === '' ) {
				$this->log_sync_error( 'API key missing for ' . ( $provider['name'] ?? $provider_id ) );
				return [];
			}
		}

		// Collect target currency codes from the per-language config.
		// Ensure all active languages have entries - languages added after the
		// last settings save would otherwise be skipped and revert to defaults.
		$currencies = (array) $this->settings->get( 'wc_currencies', [] );
		$plugin     = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'cache' ) ) {
			$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
			$all_langs = $lang_repo->get_active();

			foreach ( $all_langs as $lang ) {
				if ( ! isset( $currencies[ $lang->slug ] ) ) {
					$currencies[ $lang->slug ] = [
						'currency_code' => $base_currency,
						'exchange_rate' => 1.0,
					];
				}
			}
		}

		$target_codes = [];
		$has_non_base = false;

		foreach ( $currencies as $config ) {
			$code = $config['currency_code'] ?? '';

			if ( $code === '' || $code === $base_currency ) {
				continue;
			}

			$has_non_base = true;

			// Skip manual-rate currencies — the overrides loop below discards the
			// provider feed for them anyway, so fetching them only burns provider
			// quota (and risks a rate-limit lockout) for no benefit.
			if ( empty( $config['manual_rate'] ) ) {
				$target_codes[ $code ] = true;
			}
		}

		if ( empty( $target_codes ) ) {
			// $has_non_base distinguishes "every non-base currency is manual" (a
			// valid config — nothing to fetch) from "no non-base currency at all".
			if ( $has_non_base ) {
				// All rates are manual: record a successful sync so is_stale()
				// doesn't warn forever, and skip the pointless provider request
				// that would otherwise run every tick and never persist a result.
				update_option(
					self::LAST_SYNC_OPTION,
					[
						'timestamp' => time(),
						'provider'  => $provider_id,
						'rates'     => [],
						'base'      => $base_currency,
					],
					false
				);

				return [];
			}

			$this->log_sync_error( 'No target currencies configured. Set currency codes in the exchange rate table that differ from the store base (' . $base_currency . ').' );
			return [];
		}

		// Fetch rates from the registered provider (if any). With no provider
		// the array stays empty and the filter below is the sole source.
		$rates = null !== $provider
			? $this->fetch_rates( $provider_id, $provider, $base_currency, array_keys( $target_codes ), $api_key )
			: [];

		/**
		 * Filter fetched exchange rates before they are saved.
		 *
		 * @param array<string, float> $rates Currency code => rate.
		 * @param string $base_currency WooCommerce base currency.
		 * @param string $provider_id Selected provider ID.
		 */
		$rates = (array) apply_filters( 'perflocale/woocommerce/exchange_rates_fetched', $rates, $base_currency, $provider_id );

		if ( empty( $rates ) ) {
			$this->log_sync_error( 'No rates returned from ' . ( $provider['name'] ?? ( $provider_id !== '' ? $provider_id : 'the exchange_rates_fetched filter' ) ) );
			// Provider failure — feed the breaker so a persistently-broken
			// provider trips after the configured threshold.
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'fetch_empty' );
			return [];
		}

		// Successful fetch — reset any accumulated failure counter so a
		// past hiccup doesn't take us halfway to OPEN forever.
		\PerfLocale\Concurrency\Breaker::record_success( $breaker_key );

		// Build the overrides map directly from the language configuration
		// and the freshly fetched rates. No mutation of $currencies - that
		// would be dead work now that we persist only to RATES_OPTION.
		$rate_overrides = [];
		$rejected       = [];

		foreach ( $currencies as $slug => $config ) {
			$code = $config['currency_code'] ?? '';

			if ( $code === '' || $code === $base_currency ) {
				continue;
			}

			// Respect manual rates - user-overridden values win over the
			// provider feed, matching the read-side merge in MultiCurrency.
			if ( ! empty( $config['manual_rate'] ) ) {
				continue;
			}

			// array_key_exists, not isset: a null rate is a provider fault we
			// want to report, not silently skip.
			if ( ! array_key_exists( $code, $rates ) ) {
				continue;
			}

			$raw = $rates[ $code ];

			// Rates come from a site-registered provider or filter, so they are
			// arbitrary input. A bare (float) cast turns 'unavailable' into 0.0
			// and '4,2513' into 4.0 — both of which then silently price every
			// product wrong (0, or off by ~100x). Reject anything non-numeric
			// or non-positive instead of persisting it.
			if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
				$rejected[ $code ] = is_scalar( $raw ) ? (string) $raw : get_debug_type( $raw );
				continue;
			}

			$value = (float) $raw;

			if ( ! is_finite( $value ) || $value <= 0 ) {
				$rejected[ $code ] = (string) $raw;
				continue;
			}

			$rate_overrides[ $slug ] = round( $value, 6 );
		}

		if ( $rejected !== [] ) {
			$pairs = [];

			foreach ( $rejected as $code => $value ) {
				$pairs[] = $code . '=' . $value;
			}

			$this->log_sync_error(
				sprintf(
					/* translators: %s: comma-separated list of currency=value pairs that were rejected. */
					__( 'Ignored invalid exchange rates (must be a positive number): %s', 'perflocale' ),
					implode( ', ', $pairs )
				)
			);
		}

		// Every target currency came back invalid — that is a provider fault,
		// not a success, so feed the breaker rather than recording a clean run.
		if ( $rate_overrides === [] && $rejected !== [] ) {
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'fetch_invalid' );
			return [];
		}

		if ( ! empty( $rate_overrides ) ) {
			// Persist machine-fetched rates to a dedicated option (autoload
			// off - only loaded when WooCommerce needs currency conversion).
			// This isolates cron/manual-sync writes from concurrent writes to
			// the shared settings blob, eliminating the prior race condition.
			update_option( self::RATES_OPTION, $rate_overrides, false );

			// Store last sync metadata.
			update_option(
				self::LAST_SYNC_OPTION,
				[
					'timestamp' => time(),
					'provider'  => $provider_id,
					'rates'     => $rates,
					'base'      => $base_currency,
				],
				false
			);

			/** @hook perflocale/woocommerce/exchange_rates_synced Fires after rates are saved. */
			do_action( 'perflocale/woocommerce/exchange_rates_synced', $rates, $base_currency, $provider_id );
		}

		// Record completion + duration for the Jobs admin observability
		// panel. Only on a successful sync (when $rates is non-empty).
		if ( ! empty( $rates ) ) {
			\PerfLocale\Background\BackgroundEvents::record_run( self::CRON_HOOK, $started_at );
		}

		return $rates;
	}

	/**
	 * Fetch rates from the selected provider.
	 *
	 * @param string                                                                                $provider_id Provider slug.
	 * @param array{name: string, needs_key: bool, key_setting: string, fetch_callback?: callable} $provider Provider definition.
	 * @param string                                                                                $base Base currency code.
	 * @param array<int, string>                                                                    $targets Target currency codes.
	 * @param string                                                                                $api_key API key (empty if not needed).
	 * @return array<string, float> Currency code => exchange rate.
	 */
	private function fetch_rates( string $provider_id, array $provider, string $base, array $targets, string $api_key ): array {
		// Allow custom providers to supply a callback.
		if ( isset( $provider['fetch_callback'] ) && is_callable( $provider['fetch_callback'] ) ) {
			try {
				return (array) call_user_func( $provider['fetch_callback'], $base, $targets, $api_key );
			} catch ( \Throwable $e ) {
				$this->log_sync_error( 'Custom provider error: ' . $e->getMessage() );
				return [];
			}
		}

		// No bundled providers: rates come from a provider the site registers
		// via perflocale/woocommerce/exchange_rate_providers (with a
		// fetch_callback), or from the exchange_rates_fetched filter.
		return [];
	}

	/**
	 * Cross-calculate rates when the API returns a fixed base (e.g., USD or EUR).
	 *
	 * Converts: rate_from_api_base / rate_of_store_base = rate relative to store base.
	 *
	 * @param array<string, float> $all_rates All rates from the API (relative to API base).
	 * @param string               $base Store's base currency.
	 * @param array<int, string>   $targets Target currency codes.
	 * @return array<string, float>
	 */
	private function cross_calculate( array $all_rates, string $base, array $targets ): array {
		// If the base is the same as the API base, no conversion needed.
		$base_rate = $all_rates[ $base ] ?? null;

		if ( $base_rate === null || $base_rate <= 0 ) {
			// Store base not in API response - cannot cross-calculate.
			return [];
		}

		$rates = [];

		foreach ( $targets as $code ) {
			if ( isset( $all_rates[ $code ] ) && $all_rates[ $code ] > 0 ) {
				$rates[ $code ] = $all_rates[ $code ] / $base_rate;
			}
		}

		return $rates;
	}

	/**
	 * Log a sync error (debug mode only).
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function log_sync_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PerfLocale Exchange Rate Sync: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Store last error for display in the admin UI. Not autoloaded — it's
		// read only on the admin settings screen, never on the front end.
		update_option(
			'perflocale_exchange_rate_last_error',
			[
				'timestamp' => time(),
				'message'   => $message,
			],
			false
		);
	}

	// ------------------------------------------------------------------
	// AJAX handler
	// ------------------------------------------------------------------

	/**
	 * AJAX: trigger a manual exchange rate sync.
	 *
	 * @return void
	 */
	public function ajax_sync(): void {
		// Canonical WP-handbook order: nonce (CSRF) first, then capability.
		// Default $die=true so an invalid nonce terminates the request
		// before any input is read or any state mutates.
		check_ajax_referer( 'perflocale_sync_rates', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'perflocale' ) ], 403 );
		}

		// Clear any previous error so we get a fresh diagnostic.
		delete_option( 'perflocale_exchange_rate_last_error' );

		$rates = $this->sync_rates();

		if ( empty( $rates ) ) {
			$error = get_option( 'perflocale_exchange_rate_last_error', [] );
			$msg   = $error['message'] ?? __( 'No rates returned. Check your provider settings and API key.', 'perflocale' );

			wp_send_json_error( [ 'message' => $msg ] );
		}

		$last_sync = get_option( self::LAST_SYNC_OPTION, [] );

		wp_send_json_success(
			[
				'rates'     => $rates,
				'timestamp' => $last_sync['timestamp'] ?? time(),
				'message'   => __( 'Exchange rates updated successfully.', 'perflocale' ),
			]
		);
	}

	// ------------------------------------------------------------------
	// Public accessors (for admin UI)
	// ------------------------------------------------------------------

	/**
	 * Get all available providers (built-in + custom via filter).
	 *
	 * @return array<string, array{name: string, needs_key: bool, key_setting: string}>
	 */
	public function get_providers(): array {
		/**
		 * Filter exchange rate providers.
		 *
		 * Add custom providers with a `fetch_callback` key:
		 *
		 * add_filter( 'perflocale/woocommerce/exchange_rate_providers', function( $providers ) {
		 * $providers['my_api'] = [
		 * 'name' => 'My Custom API',
		 * 'needs_key' => true,
		 * 'key_setting' => 'wc_my_api_key',
		 * 'fetch_callback' => function( string $base, array $targets, string $key ): array {
		 * // Return [ 'EUR' => 0.85, 'GBP' => 0.73 ]
		 * },
		 * ];
		 * return $providers;
		 * } );
		 *
		 * @param array<string, array> $providers Provider definitions.
		 */
		return (array) apply_filters( 'perflocale/woocommerce/exchange_rate_providers', self::PROVIDERS );
	}

	/**
	 * Get available sync intervals.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function get_intervals(): array {
		return self::INTERVALS;
	}

	/**
	 * Get the last sync metadata.
	 *
	 * @return array{timestamp: int, provider: string, rates: array<string, float>, base: string}|array{}
	 */
	public static function get_last_sync(): array {
		$data = get_option( self::LAST_SYNC_OPTION, [] );

		return is_array( $data ) ? $data : [];
	}

}
