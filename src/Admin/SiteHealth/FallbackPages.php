<?php
/**
 * Site Health test: published content without a published version in a language.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\SiteHealth;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Plugin;
use PerfLocale\Settings;
use PerfLocale\Translation\UrlLanguageServe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts, per active language, the published translatable content a visitor reaches there only as a fallback. Loaded by
 * Site Health and the Settings screen only; the screen shows the stored result and never counts.
 *
 * @since 1.0.8
 */
final class FallbackPages {

	/**
	 * Test ID.
	 */
	public const ID = 'perflocale_fallback_pages';

	/**
	 * Stored result {at, key, counts} (non-autoloaded option); a visibility change or a settings save deletes it.
	 */
	public const OPTION = 'perflocale_fallback_counts';

	/**
	 * Seconds a stored result is used.
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Admin-ajax endpoint of the Status screen, with core's Site Health nonce and capability.
	 *
	 * @return void
	 */
	public static function ajax(): void {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( self::test() );
	}

	/**
	 * Run the test.
	 *
	 * @return array<string, mixed>
	 */
	public static function test(): array {
		$result  = self::counts( true );
		$counts  = $result['counts'];
		$unknown = in_array( null, $counts, true );
		$total   = array_sum( array_map( 'intval', $counts ) );
		$action  = self::settings()?->get_missing_translation_action() ?? 'show_default';

		$items = '';
		foreach ( $counts as $name => $n ) {
			$items .= '<li>' . esc_html( self::line( (string) $name, $n ) ) . '</li>';
		}

		$desc = '<p>' . esc_html__( 'Visitors and search engines reach this content in these languages only as a fallback (the original, or what the Missing Translation setting does).', 'perflocale' ) . '</p>'
			. ( $items !== '' ? '<ul>' . $items . '</ul>' : '' );

		if ( $total > 0 && $action === 'show_default' ) {
			$desc .= '<p>' . esc_html__( 'These addresses show the original and can be indexed. To keep them out of search results until they are translated, choose "not indexed (noindex)" under Missing Translation.', 'perflocale' ) . '</p>';
		}

		return [
			'label'       => $unknown
				? __( 'Untranslated content could not be counted', 'perflocale' )
				: ( $total > 0 ? __( 'Some content has no published translation in a language', 'perflocale' ) : __( 'All published content has a published version in every language', 'perflocale' ) ),
			'status'      => $unknown || $total > 0 ? 'recommended' : 'good',
			'badge'       => [
				'label' => __( 'Translation', 'perflocale' ),
				'color' => 'blue',
			],
			'description' => $desc,
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=url-routing' ) ) . '">' . esc_html__( 'Missing Translation setting', 'perflocale' ) . '</a></p>',
			'test'        => self::ID,
		];
	}

	/**
	 * The line under the Missing Translation setting: the stored count, never a new one.
	 *
	 * @return void
	 */
	public static function setting_line(): void {
		$result = self::counts( false );

		if ( $result['at'] === 0 ) {
			echo '<p class="description">' . esc_html__( 'Published content without a published version in a language: counted on Tools > Site Health.', 'perflocale' ) . '</p>';

			return;
		}

		$parts = [];
		foreach ( $result['counts'] as $name => $n ) {
			$parts[] = self::line( (string) $name, $n );
		}

		$date = get_option( 'date_format' );
		$time = get_option( 'time_format' );
		$when = wp_date( ( is_string( $date ) ? $date : 'Y-m-d' ) . ' ' . ( is_string( $time ) ? $time : 'H:i' ), $result['at'] );

		/* translators: 1: list of "Published content without a published version in <language>: <n>" items, 2: date and time. */
		echo '<p class="description">' . esc_html( sprintf( __( '%1$s (counted %2$s on Tools > Site Health).', 'perflocale' ), implode( '; ', $parts ), is_string( $when ) ? $when : '' ) ) . '</p>';
	}

	/**
	 * One language's count as text.
	 *
	 * @param string   $name Language name.
	 * @param int|null $n    Count, null when it could not be read.
	 * @return string
	 */
	private static function line( string $name, ?int $n ): string {
		return null === $n
			/* translators: %s: language name. */
			? sprintf( __( 'Published content without a published version in %s: unknown (the count failed)', 'perflocale' ), $name )
			/* translators: 1: language name, 2: number of items. */
			: sprintf( __( 'Published content without a published version in %1$s: %2$d', 'perflocale' ), $name, $n );
	}

	/**
	 * Counts by language name, from the stored result when it still matches the settings.
	 *
	 * @param bool $compute Count when nothing usable is stored (Site Health); false only reads.
	 * @return array{at: int, counts: array<string, int|null>}
	 */
	private static function counts( bool $compute ): array {
		$settings = self::settings();
		$none     = [
			'at'     => 0,
			'counts' => [],
		];

		if ( ! $settings instanceof Settings || ! Schema::tables_exist() ) {
			return $none;
		}

		$languages = ( new LanguageRepository( Plugin::get_instance()->cache() ) )->get_active();
		$types     = array_values( array_filter( $settings->get_translatable_post_types(), 'is_string' ) );
		$wc_ids    = self::wc_page_ids();
		$key       = md5( (string) wp_json_encode( [ $types, $wc_ids, wp_list_pluck( $languages, 'id' ) ] ) );
		$stored    = get_option( self::OPTION );

		$at = is_array( $stored ) && is_int( $stored['at'] ?? null ) ? $stored['at'] : 0;

		if ( is_array( $stored ) && ( $stored['key'] ?? '' ) === $key && is_array( $stored['counts'] ?? null ) && $at > time() - self::TTL ) {
			$counts = [];

			foreach ( $stored['counts'] as $name => $n ) {
				$counts[ (string) $name ] = is_int( $n ) ? $n : null;
			}

			return [
				'at'     => $at,
				'counts' => $counts,
			];
		}

		if ( ! $compute || $types === [] || count( $languages ) < 2 ) {
			return $none;
		}

		$default  = ( new LanguageRepository( Plugin::get_instance()->cache() ) )->get_default();
		$unlinked = self::count_unlinked( $types, $wc_ids );
		$counts   = [];

		$default_id = $default !== null && isset( $default->id ) && is_numeric( $default->id ) ? (int) $default->id : 0;

		foreach ( $languages as $lang ) {
			$id      = isset( $lang->id ) && is_numeric( $lang->id ) ? (int) $lang->id : 0;
			$name    = isset( $lang->name ) && is_string( $lang->name ) && $lang->name !== '' ? $lang->name : ( isset( $lang->slug ) && is_string( $lang->slug ) ? $lang->slug : (string) $id );
			$groups  = self::count_groups( $id, $types, $wc_ids );
			$is_dflt = $id === $default_id;

			// A failed read is unknown, never 0: the setting must not look safe because a query failed.
			$counts[ $name ] = ( null === $groups || ( ! $is_dflt && null === $unlinked ) ) ? null : $groups + ( $is_dflt ? 0 : (int) $unlinked );
		}

		$result = [
			'at'     => time(),
			'key'    => $key,
			'counts' => $counts,
		];

		// A failed count is not stored: the next run tries again.
		if ( ! in_array( null, $counts, true ) ) {
			update_option( self::OPTION, $result, false );
		}

		return [
			'at'     => $result['at'],
			'counts' => $counts,
		];
	}

	/**
	 * Translation groups with a published member of a translatable type and no published member in one language.
	 *
	 * @param int        $language_id Language.
	 * @param list<string> $types     Translatable post types.
	 * @param list<int>  $wc_ids      WooCommerce system pages (served in the URL's language, never a fallback).
	 * @return int|null Null when the query failed.
	 */
	private static function count_groups( int $language_id, array $types, array $wc_ids ): ?int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$links  = Schema::table( 'translation_links' );
		$groups = Schema::table( 'translation_groups' );
		$in     = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$wc     = $wc_ids === [] ? '' : ' AND NOT EXISTS ( SELECT 1 FROM %i fbwc WHERE fbwc.group_id = s.group_id AND fbwc.object_id IN ( ' . implode( ', ', array_fill( 0, count( $wc_ids ), '%d' ) ) . ' ) )';
		$args   = array_merge( [ $links, $groups, $wpdb->posts ], $types, [ $links, $wpdb->posts, $language_id ], $wc_ids === [] ? [] : array_merge( [ $links ], $wc_ids ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in and $wc hold only placeholders.
		$sql = "SELECT COUNT(DISTINCT s.group_id) FROM %i s
			INNER JOIN %i g ON g.id = s.group_id AND g.type = 'post'
			INNER JOIN %i p ON p.ID = s.object_id
			WHERE s.type = 'post' AND p.post_status = 'publish' AND p.post_type IN ( $in )
			AND NOT EXISTS ( SELECT 1 FROM %i fbtgt INNER JOIN %i q ON q.ID = fbtgt.object_id
				WHERE fbtgt.group_id = s.group_id AND fbtgt.language_id = %d AND q.post_status = 'publish' )$wc";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built above from literals and placeholders.
		$n = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		return ( $wpdb->last_error !== '' || ! is_numeric( $n ) ) ? null : (int) $n;
	}

	/**
	 * Published posts of translatable types with no language: default-language content, a fallback in every other one.
	 *
	 * @param list<string> $types  Translatable post types.
	 * @param list<int>    $wc_ids WooCommerce system pages.
	 * @return int|null Null when the query failed.
	 */
	private static function count_unlinked( array $types, array $wc_ids ): ?int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$wc   = $wc_ids === [] ? '' : ' AND p.ID NOT IN ( ' . implode( ', ', array_fill( 0, count( $wc_ids ), '%d' ) ) . ' )';
		$args = array_merge( [ $wpdb->posts ], $types, [ Schema::table( 'translation_links' ), Schema::table( 'translation_groups' ) ], $wc_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in and $wc hold only placeholders.
		$sql = "SELECT COUNT(*) FROM %i p
			WHERE p.post_status = 'publish' AND p.post_type IN ( $in )
			AND NOT EXISTS ( SELECT 1 FROM %i fbtgt INNER JOIN %i g ON g.id = fbtgt.group_id AND g.type = 'post'
				WHERE fbtgt.type = 'post' AND fbtgt.object_id = p.ID )$wc";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built above from literals and placeholders.
		$n = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		return ( $wpdb->last_error !== '' || ! is_numeric( $n ) ) ? null : (int) $n;
	}

	/**
	 * WooCommerce cart, checkout, account and terms page IDs, as stored.
	 *
	 * @return list<int>
	 */
	private static function wc_page_ids(): array {
		if ( ! function_exists( 'WC' ) ) {
			return [];
		}

		$ids = [];
		foreach ( UrlLanguageServe::WC_PAGES as $option ) {
			$value = get_option( $option );

			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The settings service.
	 *
	 * @return Settings|null
	 */
	private static function settings(): ?Settings {
		$settings = Plugin::get_instance()->get( 'settings' );

		return $settings instanceof Settings ? $settings : null;
	}
}
