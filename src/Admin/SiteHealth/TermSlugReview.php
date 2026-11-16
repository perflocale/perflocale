<?php
/**
 * Site Health review of term URL slugs that no longer match their Slug.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\SiteHealth;

use PerfLocale\Admin\Typed;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\SlugTranslationWrites;
use PerfLocale\Database\Schema;
use PerfLocale\Plugin;
use PerfLocale\Translation\TermSlugFollow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists translated terms whose URL slug in their own language neither follows their Slug nor was ever set apart: the
 * state a Slug change leaves when the URL slug did not follow. Read-only; required by SiteHealthHooks when it runs.
 *
 * @internal
 * @since 1.0.8
 */
final class TermSlugReview {

	/**
	 * Test id, also the admin-ajax action suffix (health-check-<action>).
	 */
	public const ACTION = 'perflocale-term-slug-review';

	/**
	 * Rows one run reads.
	 */
	private const CAP = 5000;

	/**
	 * Terms listed in the result.
	 */
	private const SHOWN = 20;

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
	 * The test result.
	 *
	 * @return array<string, mixed>
	 */
	public static function test(): array {
		require_once dirname( __DIR__, 2 ) . '/Translation/TermSlugFollow.php';

		$wpdb = Typed::wpdb();
		$st   = Schema::table( 'slug_translations' );
		// One read, capped; DB slug comparisons in PHP (wp_terms and plugin tables can differ in collation). Listed only
		// when another term of the group shows the same value (now or as an old one): creation wrote it, not a person.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.object_id, st.object_subtype, st.slug, t.slug AS db_slug, t.name, g.slug AS lang, g.name AS lang_name
				FROM %i st
				INNER JOIN %i l ON l.type = 'term' AND l.object_id = st.object_id AND l.language_id = st.language_id
				INNER JOIN %i t ON t.term_id = st.object_id
				INNER JOIN %i g ON g.id = st.language_id
				WHERE st.object_type = 'term'
				  AND EXISTS ( SELECT 1 FROM %i l2 INNER JOIN %i s2 ON s2.object_id = l2.object_id AND s2.language_id = l2.language_id WHERE l2.group_id = l.group_id AND l2.id <> l.id AND l2.type = 'term' AND s2.object_type IN ('term','term_h1','term_h2','term_h3','term_h4','term_h5') AND s2.slug = st.slug )
				  AND NOT EXISTS ( SELECT 1 FROM %i h WHERE h.object_type IN ('term_h1','term_h2','term_h3','term_h4','term_h5') AND h.object_id = st.object_id AND h.language_id = st.language_id )
				ORDER BY st.object_id
				LIMIT %d",
				$st,
				Schema::table( 'translation_links' ),
				$wpdb->terms,
				Schema::table( 'languages' ),
				Schema::table( 'translation_links' ),
				$st,
				$st,
				self::CAP
			),
			ARRAY_A
		);
		$rows   = Typed::rows( $rows );
		$active = [];

		foreach ( ( new LanguageRepository( Plugin::get_instance()->cache() ) )->get_active() as $lang ) {
			if ( is_string( $lang->slug ?? null ) ) {
				$active[] = $lang->slug;
			}
		}

		$found = [];

		foreach ( $rows as $r ) {
			$display = Typed::str( $r['slug'] ?? '' );
			$db      = Typed::str( $r['db_slug'] ?? '' );

			if ( '' !== $display && ! TermSlugFollow::follows( $display, $db, Typed::str( $r['lang'] ?? '' ), $active ) ) {
				$found[] = $r;
			}
		}

		$result = [
			'label'       => __( 'Term addresses match their slugs', 'perflocale' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Translation', 'perflocale' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Every translated term\'s address in its language follows its Slug or was set on purpose.', 'perflocale' ) . '</p>',
			'actions'     => '',
			'test'        => self::ACTION,
		];

		if ( count( $rows ) >= self::CAP ) {
			/* translators: %s: number of terms checked. */
			$result['description'] .= '<p>' . esc_html( sprintf( __( 'Only the first %s translated terms were checked.', 'perflocale' ), number_format_i18n( self::CAP ) ) ) . '</p>';
		}

		$hidden = self::hidden( $wpdb, $st );

		if ( [] === $found && [] === $hidden ) {
			return $result;
		}

		$result['label']  = __( 'Some term addresses do not match their slugs', 'perflocale' );
		$result['status'] = 'recommended';

		if ( [] === $found ) {
			$result['description'] = self::hidden_html( $hidden );

			return $result;
		}

		$items = '';

		foreach ( array_slice( $found, 0, self::SHOWN ) as $r ) {
			$link   = get_edit_term_link( Typed::int( $r['object_id'] ?? 0 ), Typed::str( $r['object_subtype'] ?? '' ) );
			$name   = esc_html( Typed::str( $r['name'] ?? '' ) );
			$items .= '<li>' . ( is_string( $link ) && '' !== $link ? '<a href="' . esc_url( $link . '#perflocale_url_slug' ) . '">' . $name . '</a>' : $name )
				. ' (' . esc_html( Typed::str( $r['lang_name'] ?? '' ) ) . '): '
				/* translators: 1: URL slug, 2: Slug. */
				. sprintf( esc_html__( 'URL slug %1$s, Slug %2$s', 'perflocale' ), '<code>' . esc_html( urldecode( Typed::str( $r['slug'] ?? '' ) ) ) . '</code>', '<code>' . esc_html( urldecode( Typed::str( $r['db_slug'] ?? '' ) ) ) . '</code>' ) . '</li>';
		}

		$more = count( $found ) - self::SHOWN;

		$result['description'] = '<p>' . esc_html__( 'These terms keep an address in their language that differs from their Slug, usually because the Slug was changed while the address stayed. Open each term and check "URL slug in" its language: keep it when it is on purpose, or change it to match. Nothing is changed automatically.', 'perflocale' ) . '</p>'
			. '<ul>' . $items . '</ul>'
			/* translators: %d: number of further terms. */
			. ( $more > 0 ? '<p>' . esc_html( sprintf( _n( 'And %d more term.', 'And %d more terms.', $more, 'perflocale' ), $more ) ) . '</p>' : '' )
			. ( count( $rows ) >= self::CAP ? '<p>' . esc_html( sprintf( /* translators: %s: number of terms checked. */ __( 'Only the first %s translated terms were checked.', 'perflocale' ), number_format_i18n( self::CAP ) ) ) . '</p>' : '' )
			. ( [] !== $hidden ? self::hidden_html( $hidden ) : '' );

		return $result;
	}

	/**
	 * Terms without a URL slug in their language whose Slug another term of it shows as its URL slug (that term's page
	 * answers their address). One capped read of URL slugs, one bound read of the terms holding them as Slug.
	 *
	 * @param \wpdb  $wpdb Database.
	 * @param string $st   slug_translations table.
	 * @return list<array{0: int, 1: string, 2: string, 3: int, 4: string}> Term id, taxonomy, slug, holder id, language name.
	 */
	private static function hidden( \wpdb $wpdb, string $st ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$shown = Typed::rows( $wpdb->get_results( $wpdb->prepare( "SELECT st.object_id, st.object_subtype, st.language_id, st.slug, g.name AS lang_name FROM %i st INNER JOIN %i t ON t.term_id = st.object_id INNER JOIN %i g ON g.id = st.language_id LEFT JOIN %i l ON l.type = 'term' AND l.object_id = st.object_id WHERE st.object_type = 'term' AND ( l.id IS NULL OR l.language_id = st.language_id ) ORDER BY st.id LIMIT %d", $st, $wpdb->terms, Schema::table( 'languages' ), Schema::table( 'translation_links' ), self::CAP ), ARRAY_A ) );
		$by    = [];
		$has   = [];

		foreach ( $shown as $r ) {
			$by[ Typed::int( $r['language_id'] ?? 0 ) . '|' . Typed::str( $r['object_subtype'] ?? '' ) . '|' . SlugTranslationWrites::fold( Typed::str( $r['slug'] ?? '' ) ) ] = $r;
			$has[ Typed::int( $r['object_id'] ?? 0 ) . '|' . Typed::int( $r['language_id'] ?? 0 ) ] = true;
		}

		$out = [];

		foreach ( array_chunk( array_values( array_unique( array_map( static fn( array $r ): string => Typed::str( $r['slug'] ?? '' ), $shown ) ) ), 200 ) as $chunk ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$terms = Typed::rows( $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.slug, tt.taxonomy, l.language_id FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id INNER JOIN %i l ON l.type = 'term' AND l.object_id = t.term_id WHERE t.slug IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', array_merge( [ $wpdb->terms, $wpdb->term_taxonomy, Schema::table( 'translation_links' ) ], $chunk ) ), ARRAY_A ) );
			// phpcs:enable

			foreach ( $terms as $t ) {
				$id     = Typed::int( $t['term_id'] ?? 0 );
				$lang   = Typed::int( $t['language_id'] ?? 0 );
				$holder = $by[ $lang . '|' . Typed::str( $t['taxonomy'] ?? '' ) . '|' . SlugTranslationWrites::fold( Typed::str( $t['slug'] ?? '' ) ) ] ?? null;

				if ( null !== $holder && Typed::int( $holder['object_id'] ?? 0 ) !== $id && ! isset( $has[ $id . '|' . $lang ] ) ) {
					$out[] = [ $id, Typed::str( $t['taxonomy'] ?? '' ), Typed::str( $t['slug'] ?? '' ), Typed::int( $holder['object_id'] ?? 0 ), Typed::str( $holder['lang_name'] ?? '' ) ];
				}
			}
		}

		return $out;
	}

	/**
	 * The hidden terms as a list.
	 *
	 * @param list<array{0: int, 1: string, 2: string, 3: int, 4: string}> $hidden Rows of hidden().
	 * @return string HTML.
	 */
	private static function hidden_html( array $hidden ): string {
		$items = '';

		foreach ( array_slice( $hidden, 0, self::SHOWN ) as [ $id, $taxonomy, $slug, $holder, $lang ] ) {
			$term   = get_term( $id, $taxonomy );
			$other  = get_term( $holder, $taxonomy );
			$link   = get_edit_term_link( $id, $taxonomy );
			$name   = esc_html( $term instanceof \WP_Term ? $term->name : '#' . $id );
			$items .= '<li>' . ( is_string( $link ) && '' !== $link ? '<a href="' . esc_url( $link . '#perflocale_url_slug' ) . '">' . $name . '</a>' : $name ) . ' (' . esc_html( $lang ) . '): '
				/* translators: 1: Slug, 2: name of the other term. */
				. sprintf( esc_html__( 'Slug %1$s is the URL slug of %2$s', 'perflocale' ), '<code>' . esc_html( urldecode( $slug ) ) . '</code>', esc_html( $other instanceof \WP_Term ? $other->name : '#' . $holder ) ) . '</li>';
		}

		$more = count( $hidden ) - self::SHOWN;

		return '<p>' . esc_html__( 'These terms have no address of their own in their language: another term of it uses their Slug as its URL slug, so their links open that term. Set a "URL slug in" their language.', 'perflocale' ) . '</p>'
			. '<ul>' . $items . '</ul>'
			/* translators: %d: number of further terms. */
			. ( $more > 0 ? '<p>' . esc_html( sprintf( _n( 'And %d more term.', 'And %d more terms.', $more, 'perflocale' ), $more ) ) . '</p>' : '' );
	}
}
