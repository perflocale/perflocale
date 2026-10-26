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
 * Fetches exchange rates on a schedule from a provider a site registers, and stores them per language.
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
	 * Not in `perflocale_settings`: a sync and a settings save would overwrite each other's writes.
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
	 * Available sync intervals, keyed by interval setting slug.
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
	 * Rates the last sync_rates() call accepted and stored (currency code => rate), for "Sync Now".
	 *
	 * @var array<string, float>
	 */
	private array $accepted_rates = [];

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

		// Void wrapper: sync_rates() returns the rates for the AJAX caller.
		add_action( self::CRON_HOOK, [ $this, 'cron_sync_rates' ] );

		// Reschedule when settings change.
		add_action( 'perflocale/settings/updated', [ $this, 'maybe_reschedule' ] );

		// The AJAX handler is registered in Bootstrap::init(), independent of add-on boot order.

		// Stale-rates notice, hooked only while auto-sync is on.
		if ( (bool) $this->settings->get( 'wc_exchange_rate_auto', false ) ) {
			add_action( 'admin_notices', [ $this, 'maybe_render_stale_notice' ] );
		}

		// Write contexts only, at init 20: before action_scheduler_init BackgroundEvents cannot
		// see Action Scheduler and picks WP-Cron. The settings-update hook is the main path.
		if ( \PerfLocale\Helper::is_write_context() ) {
			if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
				$this->reconcile_on_boot();
			} else {
				add_action(
					'init',
					function (): void {
						$this->reconcile_on_boot();
					},
					20
				);
			}

			add_action(
				'action_scheduler_ensure_recurring_actions',
				function (): void {
					$this->ensure_scheduled();
				}
			);
		}
	}

	/**
	 * Check the schedule once per write-context boot; no query while the event is where the engine keeps it.
	 *
	 * Under Action Scheduler a WP-Cron copy is a duplicate, cleared only once Action Scheduler holds the event.
	 *
	 * @return void
	 */
	private function reconcile_on_boot(): void {
		if ( ! (bool) $this->settings->get( 'wc_exchange_rate_auto', false ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		$wp_cron = wp_next_scheduled( self::CRON_HOOK );

		if ( BackgroundEvents::is_action_scheduler_engine() ) {
			if ( false === $wp_cron ) {
				return;
			}

			// BackgroundEvents::is_scheduled() counts the WP-Cron copy too,
			// so the check here asks Action Scheduler alone.
			$held = BackgroundEvents::is_scheduled_in_action_scheduler( self::CRON_HOOK );

			if ( ! $held ) {
				$this->schedule_event();
				$held = BackgroundEvents::is_scheduled_in_action_scheduler( self::CRON_HOOK );
			}

			if ( $held ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}

			return;
		}

		if ( false !== $wp_cron ) {
			return;
		}

		$this->ensure_scheduled();
	}

	/**
	 * Staleness threshold multiplier applied to the configured interval: one skipped run is tolerated.
	 */
	private const STALE_THRESHOLD_MULTIPLIER = 2;

	/**
	 * Whether the last successful sync is older than the stale threshold.
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
	 * Whether any rate source is wired at all (mirrors do_sync_rates()'s abort condition).
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

		// Without a rate source every sync aborts before fetching: say so, not "check WP-Cron".
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
		// Prefixed: the registry is global. Setting slugs stay unprefixed (cron_schedule_name()).
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
	 * Map an interval setting slug to its WP-Cron schedule name.
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

		$this->schedule_event();
	}

	/**
	 * Schedule the recurring sync at the configured interval, in the engine BackgroundEvents picks.
	 *
	 * @return bool Whether the scheduler admitted the event.
	 */
	private function schedule_event(): bool {
		$interval = (string) $this->settings->get( 'wc_exchange_rate_interval', 'daily' );

		if ( ! isset( self::INTERVALS[ $interval ] ) ) {
			$interval = 'daily';
		}

		return BackgroundEvents::enqueue_recurring(
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
	 * Cron callback: sync_rates() without its return value.
	 *
	 * @return void
	 */
	public function cron_sync_rates(): void {
		$this->sync_rates();
	}

	/**
	 * Sync exchange rates from the configured provider.
	 *
	 * @return array<string, float> Fetched rates (currency_code => rate), empty on failure.
	 */
	public function sync_rates(): array {
		$this->accepted_rates = [];

		// During uninstall the options this touches may be partly dropped.
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return [];
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			$this->log_sync_error( 'WooCommerce is not active.' );
			return [];
		}

		// The breaker covers provider failures only: config errors (no key, no targets) bypass it,
		// as retrying cannot fix them.
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

		// Serialise ticks so two workers do not both spend provider quota and fire the synced action.
		$result = \PerfLocale\Concurrency\Lock::with(
			'exchange_rate_sync',
			5 * MINUTE_IN_SECONDS,
			function (): array {
				return $this->do_sync_rates();
			}
		);

		// Lock held: another tick is in flight and records the breaker outcome.
		return $result ?? [];
	}

	/**
	 * Keep stored rates the current reply did not cover: an omission means "unchanged", not "gone".
	 *
	 * Only while the base and the language's currency match the last record, from that record's own pairs.
	 * See docs-ai/11-code-rationale.md, "ExchangeRateSync: carried rates".
	 *
	 * @param array<string, float> $fresh          Rates resolved from this reply.
	 * @param array<string, mixed> $currencies     Per-language currency config.
	 * @param string               $base_currency  Store base currency.
	 * @return array<string, float> Fresh rates, plus any still-valid carried ones.
	 */
	private function carry_forward_rates( array $fresh, array $currencies, string $base_currency ): array {
		// This request's `notoptions` may predate another request's sync that created the rows:
		// unlist both (the rates row is written next).
		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && ( isset( $notoptions[ self::LAST_SYNC_OPTION ] ) || isset( $notoptions[ self::RATES_OPTION ] ) ) ) {
			\PerfLocale\Database\NotOptions::unlist( [ self::LAST_SYNC_OPTION, self::RATES_OPTION ] );
		}

		$last = (array) get_option( self::LAST_SYNC_OPTION, [] );

		// Base changed (or never recorded): never mix two bases in one map.
		if ( (string) ( $last['base'] ?? '' ) !== $base_currency ) {
			return $fresh;
		}

		// Numbers and their currencies from the same record; a record without slug_rates has no bound pair.
		$previous = is_array( $last['slug_rates'] ?? null ) ? $last['slug_rates'] : [];
		$codes    = (array) ( $last['codes'] ?? [] );
		$carried  = [];

		foreach ( $currencies as $slug => $config ) {
			$slug = (string) $slug;
			$code = (string) ( $config['currency_code'] ?? '' );

			// This reply covered it (the fresh value wins), or no rate is needed.
			if ( isset( $fresh[ $slug ] ) || $code === '' || $code === $base_currency ) {
				continue;
			}

			// The recorded rate of this currency, under whichever slug it was synced (a rename keeps it).
			foreach ( array_keys( $codes, $code, true ) as $synced ) {
				$rate = $previous[ $synced ] ?? null;

				if ( is_numeric( $rate ) && is_finite( (float) $rate ) && (float) $rate > 0 ) {
					$carried[ $slug ] = (float) $rate;
					break;
				}
			}
		}

		// Union, not array_merge(), which renumbers integer-like slugs. Fresh rates win.
		return $fresh + $carried;
	}

	/**
	 * Body of sync_rates(), run under the `exchange_rate_sync` lock.
	 *
	 * @return array<string, float> Fetched rates (currency_code => rate),
	 *                              empty on failure.
	 */
	private function do_sync_rates(): array {
		$breaker_key = 'fx_sync';

		// Track recurring-handler timing for the Jobs admin panel.
		$started_at = time();

		// Captured up front so a blog switch mid-sync cannot write one blog's rates into another.
		$origin_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		// Raw option, not get_woocommerce_currency(): MultiCurrency filters that to the language's currency.
		$base_currency = (string) get_option( 'woocommerce_currency', 'USD' );

		if ( is_multisite() && $origin_blog_id > 0 && $origin_blog_id !== (int) get_current_blog_id() ) {
			$this->log_sync_error( 'Aborting sync: blog context changed mid-request.' );
			return [];
		}
		$providers   = $this->get_providers();
		$provider_id = (string) $this->settings->get( 'wc_exchange_rate_provider', '' );
		$provider    = $providers[ $provider_id ] ?? null;

		// Abort only when neither a registered provider nor the exchange_rates_fetched filter is
		// wired: a site may supply rates through the filter alone.
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

		// Target codes from the per-language config, with entries for languages added since the last save.
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

			// Manual-rate currencies are not fetched: the overrides loop below discards them anyway.
			if ( empty( $config['manual_rate'] ) ) {
				$target_codes[ $code ] = true;
			}
		}

		if ( empty( $target_codes ) ) {
			// Every non-base currency is manual (valid) versus none at all.
			if ( $has_non_base ) {
				// All manual: record a successful sync so is_stale() does not warn forever.
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

		// Without a provider the filter below is the only source.
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
			// Provider failure: feed the breaker.
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'fetch_empty' );
			return [];
		}

		// Not record_success() yet: a non-empty reply can still be all invalid, and must still trip the breaker.
		$rate_overrides = [];
		$rejected       = [];
		$accepted       = [];

		foreach ( $currencies as $slug => $config ) {
			$code = $config['currency_code'] ?? '';

			if ( $code === '' || $code === $base_currency ) {
				continue;
			}

			// Manual rates win, matching the read-side merge in MultiCurrency.
			if ( ! empty( $config['manual_rate'] ) ) {
				continue;
			}

			// Not isset(): a null rate is a provider fault to report.
			if ( ! array_key_exists( $code, $rates ) ) {
				continue;
			}

			$raw = $rates[ $code ];

			// Arbitrary input: a bare (float) cast turns 'unavailable' into 0.0 and '4,2513' into 4.0.
			if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
				$rejected[ $code ] = is_scalar( $raw ) ? (string) $raw : get_debug_type( $raw );
				continue;
			}

			// Checked as stored: a tiny rate rounds to 0.
			$value = MultiCurrency::usable_rate( round( (float) $raw, 6 ) );

			if ( null === $value ) {
				$rejected[ $code ] = (string) $raw;
				continue;
			}

			$rate_overrides[ $slug ] = $value;
			$accepted[ $code ]       = $value;
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

		// Every target currency came back invalid: a provider fault, not a success.
		if ( $rate_overrides === [] && $rejected !== [] ) {
			\PerfLocale\Concurrency\Breaker::record_failure( $breaker_key, 'fetch_invalid' );
			return [];
		}

		// Past validation: the run counts as a success here, not before.
		\PerfLocale\Concurrency\Breaker::record_success( $breaker_key );

		if ( ! empty( $rate_overrides ) ) {
			// The option is written whole: carry forward rates this reply omitted.
			$rate_overrides = $this->carry_forward_rates( $rate_overrides, $currencies, $base_currency );

			// Dedicated option, not autoloaded (see RATES_OPTION).
			update_option( self::RATES_OPTION, $rate_overrides, false );

			// The currency each slug's rate was fetched for, read by carry_forward_rates().
			$synced_codes = [];

			foreach ( $currencies as $code_slug => $code_config ) {
				$code_for_slug = (string) ( $code_config['currency_code'] ?? '' );

				if ( $code_for_slug !== '' ) {
					$synced_codes[ (string) $code_slug ] = $code_for_slug;
				}
			}

			// Store last sync metadata.
			update_option(
				self::LAST_SYNC_OPTION,
				[
					'timestamp'  => time(),
					'provider'   => $provider_id,
					'rates'      => $rates,
					'base'       => $base_currency,
					'codes'      => $synced_codes,
					'slug_rates' => $rate_overrides,
				],
				false
			);

			/** @hook perflocale/woocommerce/exchange_rates_synced Fires after rates are saved. */
			do_action( 'perflocale/woocommerce/exchange_rates_synced', $rates, $base_currency, $provider_id );

			$this->accepted_rates = $accepted;
		}

		// Completion and duration for the Jobs panel, on a successful sync only.
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

		// No bundled providers: only a registered fetch_callback or the exchange_rates_fetched filter.
		return [];
	}

	/**
	 * Remove credentials from sync error text before it is stored or logged.
	 *
	 * Provider text can carry the API key in a URL: the key goes as typed and URL-encoded, then SecretMasker runs.
	 *
	 * @param string $message Error text.
	 * @return string
	 */
	private function redact_secrets( string $message ): string {
		$providers   = $this->get_providers();
		$provider_id = (string) $this->settings->get( 'wc_exchange_rate_provider', '' );
		$key_setting = (string) ( $providers[ $provider_id ]['key_setting'] ?? '' );
		$api_key     = $key_setting !== '' ? (string) $this->settings->get( $key_setting, '' ) : '';
		$trimmed     = trim( $api_key );

		if ( $trimmed !== '' ) {
			$forms = array_unique( [ $api_key, $trimmed, rawurlencode( $trimmed ) ] );

			// Longest first, so a form that contains another is replaced whole.
			usort( $forms, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

			$message = str_replace( $forms, '[REDACTED]', $message );
		}

		return \PerfLocale\Util\SecretMasker::mask( $message );
	}

	/**
	 * Log a sync error (debug mode only) and store it for the admin UI, credentials removed.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function log_sync_error( string $message ): void {
		$message = $this->redact_secrets( $message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PerfLocale Exchange Rate Sync: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Written only when the message changes: an unwired site fails every run. Do not unschedule
		// here: wiring a rate source is a code change that fires no settings save.
		// See docs-ai/11-code-rationale.md, "ExchangeRateSync: last error write".
		$existing = get_option( 'perflocale_exchange_rate_last_error', [] );

		if ( is_array( $existing ) && isset( $existing['message'] ) && $existing['message'] === $message ) {
			return;
		}

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
		// Nonce first (dies on failure), then capability.
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

		// The settings screen writes these into its inputs: accepted rates only, never a rejected value.
		wp_send_json_success(
			[
				'rates'     => $this->accepted_rates,
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
		 * Each takes name, needs_key, key_setting and fetch_callback( $base, $targets, $key ) returning code => rate.
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
