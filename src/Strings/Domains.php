<?php
/**
 * String domains: the names, the sets and the per-domain capability.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The text domains PerfLocale owns, for admin, job and add-on code.
 * A translated page view loads only the string service of its mode, never this class.
 */
final class Domains {

	/** ACF options pages, widgets and custom storage ids. */
	public const ACF_OPTIONS = StringTranslation::ACF_OPTIONS;

	/** Meta Box settings pages. */
	public const MB_SETTINGS = StringTranslation::MB_SETTINGS;

	/** ACF field settings; ACF calls __() on it. */
	public const ACF_FIELDS = StringTranslation::ACF_FIELDS;

	/** Meta Box choice and form labels. */
	public const MB_FIELDS = StringTranslation::MB_FIELDS;

	/** Post type and taxonomy labels. */
	public const LABELS = StringTranslation::LABELS;

	/** Domains served by lookup only. */
	public const LOOKUP_ONLY = StringTranslation::LOOKUP_ONLY;

	/**
	 * Domains the orphan-scope sweep may run on (scope = first context segment).
	 * Not LABELS: its first segment is a kind, so a sweep would remove every row.
	 */
	public const SWEEPABLE = [
		self::ACF_OPTIONS,
		self::MB_SETTINGS,
	];

	/**
	 * Domains kept in `perflocale_gc_protected_domains`: registered by hand, never rediscovered
	 * by the scanner, so the protection must hold while the host plugin is inactive.
	 */
	public const GC_PROTECTED = [
		self::ACF_OPTIONS,
		self::MB_SETTINGS,
		self::ACF_FIELDS,
		self::MB_FIELDS,
		self::LABELS,
	];

	/**
	 * Restricted-domain lists of this request, keyed by blog id and user id.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $restricted = [];

	/**
	 * Whether a domain is served by lookup only.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	public static function is_lookup_only( string $domain ): bool {
		return StringTranslation::is_lookup_only( $domain );
	}

	/**
	 * Whether the gettext filters attach for a loaded map.
	 *
	 * @param bool $gettext_rows Whether the map holds a row of a gettext domain.
	 * @param bool $any_rows     Whether the map holds any row.
	 * @return bool
	 */
	public static function should_attach_gettext( bool $gettext_rows, bool $any_rows ): bool {
		return StringTranslation::should_attach_gettext( $gettext_rows, $any_rows );
	}

	/**
	 * The domains the current user may not see, resolved once per request and user.
	 *
	 * @return array<int, string>
	 */
	public static function restricted_for_current_user(): array {
		// Nothing restricts a domain until a site names a capability: no user lookups then.
		if ( ! has_filter( 'perflocale/strings/domain_capability' ) ) {
			return [];
		}

		$key = get_current_blog_id() . ':' . get_current_user_id();

		if ( isset( self::$restricted[ $key ] ) ) {
			return self::$restricted[ $key ];
		}

		/**
		 * The capability a user needs to list, save, export, import or translate a domain's strings.
		 *
		 * An unnamed domain is visible to everyone the entry point admits. Keys match the domain byte for byte.
		 *
		 * @hook perflocale/strings/domain_capability
		 * @since 1.0.8
		 * @param array<string, mixed> $map Text domain => capability.
		 */
		$map  = apply_filters( 'perflocale/strings/domain_capability', self::no_capabilities() );
		$deny = [];

		foreach ( (array) $map as $domain => $capability ) {
			$domain = Scalar::str( $domain );

			if ( '' === $domain || ! is_string( $capability ) || '' === $capability ) {
				continue;
			}

			if ( ! current_user_can( $capability ) ) {
				$deny[] = $domain;
			}
		}

		self::$restricted[ $key ] = $deny;

		return $deny;
	}

	/**
	 * Default of `perflocale/strings/domain_capability`: none.
	 *
	 * @return array<string, mixed>
	 */
	private static function no_capabilities(): array {
		return [];
	}

	/**
	 * Whether the current user may not see a domain.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	public static function is_restricted( string $domain ): bool {
		return in_array( $domain, self::restricted_for_current_user(), true );
	}

	/**
	 * Drop the per-request memo (user switch inside a request, tests).
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$restricted = [];
	}

	/**
	 * The label the Strings page shows for a domain.
	 *
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function label( string $domain ): string {
		$label = self::LABELS === $domain ? __( 'Post type and taxonomy labels', 'perflocale' ) : $domain;

		/**
		 * A label for a text domain on the Strings page.
		 *
		 * @hook perflocale/strings/domain_label
		 * @since 1.0.8
		 * @param string $label  The label (the raw domain by default).
		 * @param string $domain The text domain.
		 */
		$filtered = Scalar::str( apply_filters( 'perflocale/strings/domain_label', $label, $domain ) );

		return '' !== $filtered ? $filtered : $domain;
	}

	/**
	 * Add GC_PROTECTED to the stored protected-domains option; idempotent.
	 *
	 * Other writers (the WPML importer) share the row, so it only adds: a failed read writes nothing,
	 * insert never overwrites, update is compare-and-set on the value read (up to three tries).
	 *
	 * @return bool Whether the row holds every GC_PROTECTED domain.
	 */
	public static function ensure_site_options(): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$name = 'perflocale_gc_protected_domains';

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$row = self::option_row( $wpdb, $name );

			if ( null === $row ) {
				return false;
			}

			$current = $row['exists'] ? array_values( array_filter( Scalar::strings( maybe_unserialize( $row['value'] ) ) ) ) : [];
			$merged  = array_values( array_unique( array_merge( $current, self::GC_PROTECTED ) ) );

			if ( $merged === $current ) {
				return true;
			}

			$value = maybe_serialize( $merged );
			$sql   = $row['exists']
				? $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, $name, $row['value'] )
				: $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, $value, 'no' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; the options caches are dropped below.
			$written = is_string( $sql ) && '' !== $sql ? $wpdb->query( $sql ) : false;

			wp_cache_delete( $name, 'options' );
			\PerfLocale\Database\NotOptions::unlist( [ $name ] );

			// 0 rows: another writer changed the row in between, so read again.
			if ( ! is_int( $written ) || $written > 0 ) {
				return is_int( $written );
			}
		}

		return false;
	}

	/**
	 * An option's row as the database holds it, read with a checked query.
	 *
	 * @param \wpdb  $wpdb Database.
	 * @param string $name Option name.
	 * @return array{exists: bool, value: string}|null Null when the read failed.
	 */
	private static function option_row( \wpdb $wpdb, string $name ): ?array {
		$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; a checked read of the row, past the options caches.
		$read = is_string( $sql ) && '' !== $sql ? $wpdb->query( $sql ) : false;

		if ( ! is_int( $read ) ) {
			return null;
		}

		return [
			'exists' => $read > 0,
			'value'  => $read > 0 ? Scalar::str( $wpdb->get_var() ) : '', // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The result of the checked read above; no query runs here.
		];
	}
}
