<?php
/**
 * Notices naming the published root posts a language prefix takes, after a change that can create the clash.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Schema;
use PerfLocale\Plugin;
use PerfLocale\Settings;
use PerfLocale\Translation\LanguageCodeSlugs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: required on activation, on a new site, on the Permalinks and PerfLocale Settings saves and on the landing page
 * that carries ARG. Stores nothing: the landing URL carries the site IDs and the notice reads the clash again.
 *
 * @since 1.0.8
 * @internal
 */
final class SlugClashNotice {

	/** Query arg of the landing page: the IDs of the sites to report. */
	public const ARG = 'perflocale_slug_clash';

	/** Sites named at most (the URL carries their IDs). */
	private const MAX_SITES = 20;

	/**
	 * Lines found by the Permalinks save, for its settings notice.
	 *
	 * @var list<string>
	 */
	private static array $pending = [];

	/**
	 * This site's clashes: each published root post at the code of any language row (LanguageCodeSlugs::clash_notes()).
	 *
	 * @param Settings $settings This site's settings.
	 * @return list<string>
	 */
	public static function lines( Settings $settings ): array {
		global $wpdb;

		// Not Schema::tables_exist(): a new site's setup request may hold its "no" from before the tables were made.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation and save paths only.
		if ( ! $wpdb instanceof \wpdb || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( 'languages' ) ) ) ) {
			return [];
		}

		// Read here: activation runs before the plugin's services exist.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT slug, locale, name FROM %i', Schema::table( 'languages' ) ) );
		// phpcs:enable
		$names = [];

		require_once dirname( __DIR__ ) . '/Translation/LanguageCodeSlugs.php';

		// Keyed by every root path a language takes: its code and, in locale form, its URL prefix.
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_string( $row->slug ?? null ) && '' !== $row->slug ) {
				foreach ( LanguageCodeSlugs::paths( $row->slug, is_string( $row->locale ?? null ) ? $row->locale : '', $settings ) as $path ) {
					$names[ $path ] ??= is_string( $row->name ?? null ) ? $row->name : $row->slug;
				}
			}
		}

		return [] === $names ? [] : LanguageCodeSlugs::clash_notes( $names, $settings );
	}

	/**
	 * After this site was set up (activation, network pass, new site): WP-CLI warns at once; true when it has a clash.
	 *
	 * @param bool $name_site Start each WP-CLI line with the site's name (network pass).
	 * @return bool
	 */
	public static function check_site( bool $name_site = false ): bool {
		try {
			$lines = self::lines( new Settings() );
		} catch ( \Throwable $e ) {
			// Never stops an activation: Site Health still lists the clash.
			return false;
		}

		if ( [] !== $lines && defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			foreach ( $lines as $line ) {
				\WP_CLI::warning( $name_site ? self::on_site( $line ) : $line );
			}
		}

		return [] !== $lines;
	}

	/**
	 * Carry the sites with a clash to the Plugins or Add New Site page this request redirects to.
	 *
	 * @param array<int, int> $blog_ids Sites with a clash.
	 * @return void
	 */
	public static function carry( array $blog_ids ): void {
		if ( [] === $blog_ids || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$value = implode( ',', array_slice( array_map( 'intval', $blog_ids ), 0, self::MAX_SITES ) );

		add_filter(
			'wp_redirect',
			static fn( $location ) => is_string( $location ) && ( str_contains( $location, 'plugins.php' ) || str_contains( $location, 'site-new.php' ) ) ? add_query_arg( self::ARG, $value, $location ) : $location
		);
	}

	/**
	 * Landing page hooks: the notice, and ARG dropped from the address bar so a reload does not show it again.
	 *
	 * @return void
	 */
	public static function landing_hooks(): void {
		add_action( is_network_admin() ? 'network_admin_notices' : 'admin_notices', [ self::class, 'render' ] );
		add_filter( 'removable_query_args', static fn( array $args ): array => array_merge( $args, [ self::ARG ] ) );
	}

	/**
	 * The landing notice, to users who can manage plugins; the clash is read again, so a fixed one is not shown.
	 *
	 * @return void
	 */
	public static function render(): void {
		$network = is_multisite() && is_network_admin();

		if ( ! current_user_can( $network ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: nothing is written.
		$raw = isset( $_GET[ self::ARG ] ) && is_string( $_GET[ self::ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ARG ] ) ) : '';

		if ( ! $network ) {
			self::box( self::lines( Plugin::get_instance()->settings() ) );

			return;
		}

		$items = [];

		foreach ( array_slice( wp_parse_id_list( $raw ), 0, self::MAX_SITES ) as $blog_id ) {
			if ( ! get_site( $blog_id ) instanceof \WP_Site ) {
				continue;
			}

			switch_to_blog( $blog_id );

			try {
				foreach ( self::lines( new Settings() ) as $line ) {
					$items[] = self::on_site( $line );
				}
			} finally {
				restore_current_blog();
			}
		}

		self::box( $items );
	}

	/**
	 * A clash line with the current site's name in front (network screens and passes).
	 *
	 * @param string $line Clash line.
	 * @return string
	 */
	private static function on_site( string $line ): string {
		/* translators: 1: site name, 2: one clash line. */
		return sprintf( __( '%1$s: %2$s', 'perflocale' ), get_bloginfo( 'name' ), $line );
	}

	/**
	 * A dismissible warning listing $lines (nothing when empty).
	 *
	 * @param array<int, string> $lines Clash lines.
	 * @return void
	 */
	public static function box( array $lines ): void {
		if ( [] === $lines ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>' . self::message( $lines ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- message() escapes.
	}

	/**
	 * Permalinks save hooks (load-options-permalink.php): a changed structure adds the clash to core's saved notices.
	 *
	 * @return void
	 */
	public static function permalink_hooks(): void {
		add_action(
			'permalink_structure_changed',
			static function (): void {
				self::$pending = self::lines( Plugin::get_instance()->settings() );
			}
		);
		add_filter( 'pre_set_transient_settings_errors', [ self::class, 'add_to_settings_errors' ] );
	}

	/**
	 * Append the pending clash to the notices core keeps for the page after the save.
	 *
	 * @param mixed $errors get_settings_errors().
	 * @return mixed
	 */
	public static function add_to_settings_errors( mixed $errors ): mixed {
		if ( [] === self::$pending || ! is_array( $errors ) ) {
			return $errors;
		}

		$errors[] = [
			'setting' => 'perflocale',
			'code'    => 'perflocale_slug_clash',
			'message' => self::message( self::$pending ),
			'type'    => 'warning',
		];

		return $errors;
	}

	/**
	 * Escaped notice text: what happened, then one line per post.
	 *
	 * @param array<int, string> $lines Clash lines.
	 * @return string HTML.
	 */
	private static function message( array $lines ): string {
		return '<strong>PerfLocale</strong>: ' . esc_html__( 'These published posts have a language code as their address, so visitors get that language instead:', 'perflocale' )
			. '<br>' . implode( '<br>', array_map( 'esc_html', $lines ) );
	}
}
