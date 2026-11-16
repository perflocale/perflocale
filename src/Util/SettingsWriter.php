<?php
/**
 * Settings writes: sanitize and the locked save behind Settings::update().
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded on the first Settings::update() or sanitize(), so a page view does not compile it.
 *
 * @internal
 */
final class SettingsWriter {

	private const OPTION_KEY = 'perflocale_settings';

	/**
	 * Writes update() tries; each lost compare-and-set re-reads the row.
	 */
	private const WRITE_ATTEMPTS = 5;

	/**
	 * Settings stored as slug => row maps (a list or a field map of scalars), sanitized by sanitize_nested_array().
	 */
	private const NESTED_MAPS = [ 'wc_currencies', 'language_fallbacks' ];

	/**
	 * Rows the last wc_currencies sanitize refused, as "slug (CODE)", for the settings notice.
	 *
	 * @var array<int, string>
	 */
	private static array $refused_rates = [];

	/**
	 * Auto-sync state the sanitized wc_currencies rows are judged by: this update's value, else the stored one.
	 *
	 * @var bool
	 */
	private bool $auto_sync = false;

	/**
	 * Bind the writer to one Settings instance.
	 *
	 * @param Settings $settings Settings instance.
	 * @param \Closure $load     Settings::load() on that instance.
	 * @param \Closure $remember Stores the saved settings as the instance memo.
	 * @phpstan-param \Closure(): array<string, mixed> $load
	 * @phpstan-param \Closure(array<string, mixed>): void $remember
	 */
	public function __construct( private Settings $settings, private \Closure $load, private \Closure $remember ) {}

	/**
	 * Merge sanitized values onto the stored row and save under the settings lock.
	 *
	 * @param array<string, mixed> $sanitized Values from sanitize().
	 * @return array{ok: bool, noop: bool, merged: array<string, mixed>, current: array<string, mixed>, refused: array<int, string>}|null Null when another save held the lock.
	 */
	public function update( array $sanitized ): ?array {
		// Locked read-modify-write: concurrent saves would overwrite each other's keys.
		return \PerfLocale\Concurrency\Lock::with(
			'settings_update',
			10,
			function () use ( $sanitized ): array {
				// Fresh read plus compare-and-set: see docs-ai/11-code-rationale.md, "Settings: update read and write".
				$expected = false;

				for ( $attempt = 0; ; $attempt++ ) {
					$this->settings->reset_cache();
					wp_cache_delete( self::OPTION_KEY, 'options' );
					wp_cache_delete( 'alloptions', 'options' );

					$current               = ( $this->load )();
					[ $applied, $refused ] = \PerfLocale\Util\OutboundUrl::provider_address_rules( $this->settings, $current, $sanitized );
					$merged                = array_merge( $current, $applied );

					// Later attempts compare against the row itself: a filtered value costs one attempt only.
					$written = $this->write_row( $merged, $expected );

					if ( null !== $written || $attempt + 1 >= self::WRITE_ATTEMPTS ) {
						break;
					}

					$expected = self::stored_row();
				}

				$saved = true === $written;

				// Memo only what is stored: a failed write must not leave get() ahead of the database.
				if ( (bool) $saved ) {
					( $this->remember )( $merged );
				} elseif ( $merged === $current ) {
					// A no-op save: the merged value is already stored.
					( $this->remember )( $merged );
				}

				// Flush rewrite rules only when a URL-affecting setting changed.
				if ( (bool) $saved && $this->url_affecting_change( $current, $merged ) ) {
					update_option( 'perflocale_flush_rules', 1, true );
				}

				return [
					'ok'      => (bool) $saved,
					'noop'    => $merged === $current,
					'merged'  => $merged,
					'current' => $current,
					'refused' => $refused,
				];
			}
		);
	}

	/**
	 * The settings row's bytes read from the database, past any cache; null when there is no row.
	 *
	 * @return string|null
	 */
	private static function stored_row(): ?string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row itself, past any cached copy.
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OPTION_KEY ) );

		return is_string( $row ) ? $row : null;
	}

	/**
	 * Write the merged settings as update_option() would, but only while the row still holds $expected.
	 *
	 * @param array<string, mixed> $merged   Settings to store.
	 * @param string|false|null    $expected The row's bytes the merge was built from; false: the
	 *                                       value get_option() serves, serialized; null: no row.
	 * @return bool|null True when written; false when there was nothing to write; null when the
	 *                   row did not hold $expected (or the write failed).
	 */
	private function write_row( array $merged, $expected ): ?bool {
		$old_value = get_option( self::OPTION_KEY );

		if ( false === $expected ) {
			$expected = false === $old_value ? null : \PerfLocale\Database\OptionWriter::bytes( $old_value );
		}

		return \PerfLocale\Database\OptionWriter::compare_and_set( self::OPTION_KEY, $merged, $old_value, $expected, true );
	}


	/**
	 * Keys that, when changed, require a rewrite-rule flush.
	 *
	 * @var array<int, string>
	 */
	private const URL_AFFECTING_KEYS = [
		'url_mode',
		'url_prefix_type',
		'hide_default_prefix',
		'translatable_post_types',
		'translatable_taxonomies',
		'translate_slugs',
		'language_domains',
		'excluded_paths',
	];

	/**
	 * Whether any URL-affecting setting changed between two snapshots.
	 *
	 * @param array<string, mixed> $before Previous settings.
	 * @param array<string, mixed> $after New settings (merged).
	 * @return bool
	 */
	private function url_affecting_change( array $before, array $after ): bool {
		foreach ( self::URL_AFFECTING_KEYS as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize setting values.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, mixed> Sanitized values.
	 */
	public function sanitize( array $values ): array {
		$sanitized       = [];
		$defaults        = $this->settings->get_defaults();
		$this->auto_sync = (bool) ( array_key_exists( 'wc_exchange_rate_auto', $values ) && is_scalar( $values['wc_exchange_rate_auto'] ) ? $values['wc_exchange_rate_auto'] : $this->settings->get( 'wc_exchange_rate_auto', false ) );

		foreach ( $values as $key => $value ) {
			// Only allow known keys.
			if ( ! array_key_exists( $key, $defaults ) ) {
				_doing_it_wrong(
					'PerfLocale\\Settings::sanitize',
					esc_html(
						sprintf(
							/* translators: %s is the unknown settings key being silently dropped. */
							__( 'Settings::update() received unknown key "%s" - silently dropped. Verify the key against Settings::DEFAULTS, or extend the schema.', 'perflocale' ),
							(string) $key
						)
					),
					'1.0.0'
				);
				continue;
			}

			// An overridden key is not stored: the override would win on the next read.
			if ( $this->settings->is_overridden( $key ) ) {
				continue;
			}

			// A value of the wrong shape keeps the stored one: a cast would store "Array" or a coerced number.
			if ( ! self::fits_shape( $key, $value, $defaults[ $key ] ) ) {
				continue;
			}

			// HTML-safe keys that use wp_kses_post instead of sanitize_text_field.
			$is_html_key = false;

			// Nested maps: the flat-array branch would turn each row into the string "Array".
			$is_nested_array = in_array( $key, self::NESTED_MAPS, true );

			$sanitized[ $key ] = match ( true ) {
				$is_nested_array => $this->sanitize_nested_array( $key, (array) $value ),
				'mt_agency_max_chars' === $key => \PerfLocale\MachineTranslation\Provider\ExternalAgencyProvider::max_chars_value( $value ),
				is_bool( $defaults[ $key ] ) => (bool) $value,
				is_int( $defaults[ $key ] ) => absint( $value ),
				is_array( $defaults[ $key ] ) => $this->sanitize_flat_array( (array) $value ),
				$is_html_key => wp_kses_post( (string) $value ),
				is_string( $defaults[ $key ] ) => sanitize_text_field( (string) $value ),
				default => $value,
			};
		}

		return $sanitized;
	}

	/**
	 * Whether a value has its setting's shape: the default's type (an integer as a non-negative int or digits), a list of
	 * scalars for an array, rows of scalars for NESTED_MAPS. The Replace import refuses a file by the same rule.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Value to store.
	 * @param mixed  $default The setting's default (Settings::get_defaults()).
	 * @return bool
	 */
	public static function fits_shape( string $key, mixed $value, mixed $default ): bool {
		if ( in_array( $key, self::NESTED_MAPS, true ) ) {
			if ( ! is_array( $value ) ) {
				return false;
			}

			foreach ( $value as $row ) {
				if ( ! is_array( $row ) || ! self::all_scalar( $row ) ) {
					return false;
				}
			}

			return true;
		}

		if ( is_array( $default ) ) {
			return is_array( $value ) && self::all_scalar( $value );
		}

		// ExternalAgencyProvider::max_chars_value() reads any scalar (empty or text is 0).
		if ( is_int( $default ) && 'mt_agency_max_chars' !== $key ) {
			return ( is_int( $value ) && $value >= 0 ) || ( is_string( $value ) && \PerfLocale\Helper::is_ascii_digits( $value ) );
		}

		return is_scalar( $value );
	}

	/**
	 * Whether every value of an array is a scalar.
	 *
	 * @param array<mixed> $values Values.
	 * @return bool
	 */
	private static function all_scalar( array $values ): bool {
		foreach ( $values as $v ) {
			if ( ! is_scalar( $v ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize a flat settings array, keys included, and drop non-scalar values.
	 *
	 * String keys are slugs: sanitize_key() stops a crafted POST storing arbitrary bytes as option keys.
	 *
	 * @param array<int|string, mixed> $value Raw array.
	 * @return array<int|string, string> Sanitized array.
	 */
	private function sanitize_flat_array( array $value ): array {
		$out = [];

		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) ) {
				$k = sanitize_key( $k );

				if ( $k === '' ) {
					continue;
				}
			}

			if ( ! is_scalar( $v ) ) {
				continue;
			}

			$out[ $k ] = sanitize_text_field( (string) $v );
		}

		return $out;
	}

	/**
	 * Sanitize a nested settings array with the sanitizer for its key.
	 *
	 * @param string               $key Setting key.
	 * @param array<string, mixed> $value Raw value array.
	 * @return array<string, mixed> Sanitized array.
	 */
	private function sanitize_nested_array( string $key, array $value ): array {
		if ( $key === 'wc_currencies' ) {
			return $this->sanitize_wc_currencies( $value );
		}

		if ( $key === 'language_fallbacks' ) {
			return $this->sanitize_language_fallbacks( $value );
		}

		return [];
	}

	/**
	 * Sanitize the fallback map: no self-references, empties or duplicates; chains clipped to MAX_FALLBACK_DEPTH.
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, array<int, string>>
	 */
	private function sanitize_language_fallbacks( array $raw ): array {
		$out = [];

		foreach ( $raw as $slug => $list ) {
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' ) {
				continue;
			}

			if ( ! is_array( $list ) ) {
				continue;
			}

			$clean = [];

			foreach ( $list as $entry ) {
				$entry = sanitize_key( (string) $entry );

				if ( $entry === '' || $entry === $slug ) {
					continue;
				}

				if ( in_array( $entry, $clean, true ) ) {
					continue;
				}

				$clean[] = $entry;

				if ( count( $clean ) >= Settings::MAX_FALLBACK_DEPTH ) {
					break;
				}
			}

			if ( $clean !== [] ) {
				$out[ $slug ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * Currency rows the last wc_currencies sanitize refused for an unusable rate, as "slug (CODE)".
	 *
	 * @return array<int, string>
	 */
	public static function last_refused_rates(): array {
		return self::$refused_rates;
	}

	/**
	 * Sanitize the per-language currency configuration array.
	 *
	 * Expected input: `[ 'de' => [ 'currency_code' => 'EUR', 'exchange_rate' => '1.2' ], ... ]`
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, array<mixed>> Sanitized currencies; a refused manual row keeps its stored row as stored.
	 */
	private function sanitize_wc_currencies( array $raw ): array {
		$out                 = [];
		self::$refused_rates = [];

		// The stored rows: a refused rate keeps the stored rate of its row.
		$stored = (array) $this->settings->get( 'wc_currencies', [] );

		foreach ( $raw as $slug => $data ) {
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' || ! is_array( $data ) ) {
				continue;
			}

			// Validated, never byte-truncated: half a UTF-8 character makes wpdb refuse the whole settings write.
			$raw_code = sanitize_text_field( (string) ( $data['currency_code'] ?? '' ) );
			$code     = preg_match( '/^[A-Za-z]{3}$/', $raw_code ) === 1 ? strtoupper( $raw_code ) : '';

			if ( $code === '' ) {
				continue;
			}

			$display  = sanitize_key( (string) ( $data['display'] ?? 'symbol' ) );
			$position = sanitize_key( (string) ( $data['position'] ?? 'default' ) );
			$manual   = ! empty( $data['manual_rate'] );
			$rate     = \PerfLocale\WooCommerce\MultiCurrency::rate_to_store( $data['exchange_rate'] ?? 1.0, $stored[ $slug ] ?? null, $code );

			// No usable rate, and none will come (manual, or auto-sync off): refused whole, as 1.0 would price 100 USD as 100 AED.
			if ( null === $rate && ( $manual || ! $this->auto_sync ) ) {
				self::$refused_rates[] = $slug . ' (' . $code . ')';

				if ( isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ) {
					$out[ $slug ] = $stored[ $slug ];
				}

				continue;
			}

			$out[ $slug ] = [
				'currency_code' => $code,
				// The 1.0 placeholder is never priced while auto-sync has no rate for it (MultiCurrency::load_currencies()).
				'exchange_rate' => $rate ?? 1.0,
				// Pins this currency's rate against auto-sync.
				'manual_rate'   => $manual,
				'display'       => in_array( $display, [ 'symbol', 'code' ], true ) ? $display : 'symbol',
				'position'      => in_array( $position, [ 'default', 'left', 'left_space', 'right', 'right_space' ], true ) ? $position : 'default',
			];
		}

		return $out;
	}
}
