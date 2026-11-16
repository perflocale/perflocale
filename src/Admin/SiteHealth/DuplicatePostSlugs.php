<?php
/**
 * Site Health test: published posts that share one address in one language.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\SiteHealth;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Plugin;
use PerfLocale\Settings;
use PerfLocale\Translation\LanguageCodeSlugs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists same-language duplicates (same type and slug; same parent for hierarchical types). Lists only: renaming one
 * changes a live URL, and only the site owner knows which post keeps it. Loaded by Site Health only.
 *
 * @since 1.0.8
 */
final class DuplicatePostSlugs {

	/**
	 * Test ID and its admin-ajax action suffix.
	 */
	public const ID = 'perflocale_duplicate_post_slugs';

	/**
	 * Groups listed at most.
	 */
	private const CAP = 50;

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
		$rows   = Schema::tables_exist() ? self::find() : [];
		$hidden = self::hidden();
		$good   = [] === $rows && [] === $hidden;

		// One query for the posts the list links to.
		_prime_post_caches( array_merge( array_column( $rows, 0 ), array_column( $rows, 1 ) ), false, false );

		$items = '';
		foreach ( array_slice( $rows, 0, self::CAP ) as [ $first, $last, $n ] ) {
			$more   = $n - 2;
			$items .= '<li><code>' . esc_html( (string) get_permalink( $first ) ) . '</code>: '
				. self::edit_link( $first ) . ', ' . self::edit_link( $last )
				/* translators: %d: number of further posts with the same address. */
				. ( $more > 0 ? ' ' . esc_html( sprintf( _n( 'and %d more', 'and %d more', $more, 'perflocale' ), $more ) ) : '' ) . '</li>';
		}

		$desc = $good
			? '<p>' . esc_html__( 'No two published posts share an address in one language.', 'perflocale' ) . '</p>'
			: ( [] !== $rows ? '<p>' . esc_html__( 'These posts share one address in one language, so visitors see only one of them. Change the slug of all but one; the other keeps its address.', 'perflocale' ) . '</p><ul>' . $items . '</ul>'
				. ( count( $rows ) > self::CAP ? '<p>' . esc_html__( 'More are not listed.', 'perflocale' ) . '</p>' : '' ) : '' );

		if ( [] !== $hidden ) {
			$list = '';
			foreach ( $hidden as [ $code, $post_id ] ) {
				$list .= '<li><code>/' . esc_html( $code ) . '/</code>: ' . self::edit_link( $post_id ) . '</li>';
			}
			$desc .= '<p>' . esc_html__( 'These posts have a language\'s address, so visitors get that language\'s home page instead. Change their slug.', 'perflocale' ) . '</p><ul>' . $list . '</ul>';
		}

		return [
			'label'       => $good ? __( 'Every post address is unique per language', 'perflocale' ) : __( 'Some posts share an address in one language', 'perflocale' ),
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => [
				'label' => __( 'Translation', 'perflocale' ),
				'color' => 'blue',
			],
			'description' => $desc,
			'actions'     => '',
			'test'        => self::ID,
		];
	}

	/**
	 * Same-language duplicate groups, newest first, at most CAP + 1; cached until a post changes (posts last_changed).
	 *
	 * @return list<array{0: int, 1: int, 2: int}> First ID, last ID, count.
	 */
	private static function find(): array {
		$settings = Plugin::get_instance()->get( 'settings' );
		$types    = $settings instanceof Settings ? $settings->get_translatable_post_types() : [];
		$key      = 'dup_post_slugs_' . md5( (string) wp_json_encode( $types ) . wp_cache_get_last_changed( 'posts' ) );
		$cached   = wp_cache_get( $key, 'perflocale' );

		if ( is_array( $cached ) ) {
			return array_map( static fn( $r ): array => [ self::int( $r[0] ?? 0 ), self::int( $r[1] ?? 0 ), self::int( $r[2] ?? 0 ) ], array_values( array_filter( $cached, 'is_array' ) ) );
		}

		$out = [];
		foreach ( $types as $type ) {
			$out = array_merge( $out, self::find_type( $type ) );
		}

		usort( $out, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );
		$out = array_slice( $out, 0, self::CAP + 1 );

		wp_cache_set( $key, $out, 'perflocale', DAY_IN_SECONDS );

		return $out;
	}

	/**
	 * Duplicate groups of one type: same slug and language, and for hierarchical types the same parent.
	 *
	 * @param string $type Post type.
	 * @return list<array{0: int, 1: int, 2: int}>
	 */
	private static function find_type( string $type ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$sql = is_post_type_hierarchical( $type )
			? "SELECT MIN(p.ID) AS first_id, MAX(p.ID) AS last_id, COUNT(*) AS n FROM %i p
				INNER JOIN %i tl ON tl.object_id = p.ID INNER JOIN %i tg ON tg.id = tl.group_id AND tg.type = 'post'
				WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'future', 'private' ) AND p.post_name <> ''
				GROUP BY p.post_name, tl.language_id, p.post_parent HAVING COUNT(*) > 1 ORDER BY first_id DESC LIMIT %d"
			: "SELECT MIN(p.ID) AS first_id, MAX(p.ID) AS last_id, COUNT(*) AS n FROM %i p
				INNER JOIN %i tl ON tl.object_id = p.ID INNER JOIN %i tg ON tg.id = tl.group_id AND tg.type = 'post'
				WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'future', 'private' ) AND p.post_name <> ''
				GROUP BY p.post_name, tl.language_id HAVING COUNT(*) > 1 ORDER BY first_id DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $wpdb->posts, Schema::table( 'translation_links' ), Schema::table( 'translation_groups' ), $type, self::CAP + 1 ) );

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$row   = get_object_vars( $row );
			$out[] = [ self::int( $row['first_id'] ?? 0 ), self::int( $row['last_id'] ?? 0 ), self::int( $row['n'] ?? 0 ) ];
		}

		return $out;
	}

	/**
	 * Root posts whose slug is a root path a language takes, at most 5 per path.
	 *
	 * @return list<array{0: string, 1: int}> Code, post ID.
	 */
	private static function hidden(): array {
		$settings = Plugin::get_instance()->get( 'settings' );

		if ( ! $settings instanceof Settings || ! Schema::tables_exist() ) {
			return [];
		}

		require_once dirname( __DIR__, 2 ) . '/Translation/LanguageCodeSlugs.php';

		$out = [];
		foreach ( LanguageCodeSlugs::root_paths( new LanguageRepository( Plugin::get_instance()->cache() ), $settings ) as $code ) {
			foreach ( LanguageCodeSlugs::holders( $code, $settings ) as $post ) {
				$out[] = [ $code, $post->ID ];
			}
		}

		return $out;
	}

	/**
	 * A numeric SQL value as int.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Edit link of one post (or its title when the user may not edit it).
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	private static function edit_link( int $post_id ): string {
		/* translators: 1: post title, 2: post ID. */
		$label = esc_html( sprintf( __( '%1$s (#%2$d)', 'perflocale' ), get_the_title( $post_id ), $post_id ) );
		$url   = get_edit_post_link( $post_id, 'raw' );

		return $url ? '<a href="' . esc_url( $url ) . '">' . $label . '</a>' : $label;
	}
}
