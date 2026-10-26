<?php
/**
 * Settings that feed no language or hreflang data.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings keys a save may change without flushing the language and hreflang caches.
 * Loaded only by a settings save (CacheInvalidator::on_settings_changed()).
 */
final class OperationalSettings {

	/**
	 * Keys no language, URL, slug or hreflang derivation reads: jobs, machine translation, exchange-rate
	 * schedule, abilities, dashboard widget, uninstall. A key missing here only costs a flush.
	 *
	 * @var list<string>
	 */
	public const KEYS = [
		'background_paused',
		'background_processing',
		'background_engine',
		'background_thresholds',
		'mt_enabled',
		'mt_provider',
		'mt_deepl_api_key',
		'mt_deepl_formality',
		'mt_google_api_key',
		'mt_microsoft_api_key',
		'mt_microsoft_region',
		'mt_libre_url',
		'mt_libre_api_key',
		'mt_agency_url',
		'mt_agency_api_key',
		'mt_auto_translate_on_publish',
		'mt_auto_translate_languages',
		'mt_auto_translate_on_create',
		'mt_monthly_char_limit',
		'mt_bulk_strings_enabled',
		'mt_enforce_cap_on_bulk',
		'mt_meta_seo',
		'mt_meta_custom_fields',
		'wc_exchange_rate_auto',
		'wc_exchange_rate_provider',
		'wc_exchange_rate_interval',
		'abilities_enabled',
		'abilities_write_enabled',
		'dashboard_widget_enabled',
		'delete_data_on_uninstall',
	];

	/**
	 * Whether the save changed no key outside self::KEYS (true for a save that changed nothing).
	 *
	 * @param array<mixed> $new Settings after the save.
	 * @param array<mixed> $old Settings before it.
	 * @return bool
	 */
	public static function only_these_changed( array $new, array $old ): bool {
		$allowed = array_flip( self::KEYS );

		foreach ( array_keys( $new + $old ) as $key ) {
			if ( ( $new[ $key ] ?? null ) !== ( $old[ $key ] ?? null ) && ! isset( $allowed[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}
}
