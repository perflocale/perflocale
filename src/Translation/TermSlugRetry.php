<?php
/**
 * Term URL slugs a creation or a DB slug claim could not write while an import held the lock, retried by a one-shot event.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Admin\Typed;
use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Database\Repository\SlugTranslationWrites;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: required by SlugTranslationWrites (busy creation write), TermSlugFollow (busy claim) and BootstrapMaintenance
 * (the event), not in the map.
 *
 * @since 1.0.8
 */
final class TermSlugRetry {

	/**
	 * Event hook; its arguments are the items and the attempt number.
	 */
	public const HOOK = 'perflocale_term_slug_retry';

	/**
	 * Items per event, and attempts before an item is left to `wp perflocale` backfill.
	 */
	private const BATCH = 200;

	private const MAX_TRIES = 20;

	/**
	 * Items queued in this request: blog id => "term:language" => [ taxonomy, term id, language id, slug ].
	 *
	 * @var array<int, array<string, array{0: string, 1: int, 2: int, 3: string}>>
	 */
	private static array $queued = [];

	/**
	 * Queue one URL slug write for an event at the end of the request.
	 *
	 * @param string $taxonomy    Taxonomy.
	 * @param int    $term_id     Term.
	 * @param int    $language_id Language.
	 * @param string $slug        URL slug; '' for a claim (the DB slug another term shows gets its own "-N").
	 * @return void
	 */
	public static function queue( string $taxonomy, int $term_id, int $language_id, string $slug ): void {
		if ( [] === self::$queued ) {
			register_shutdown_function( [ self::class, 'flush' ] );
		}

		self::$queued[ get_current_blog_id() ][ $term_id . ':' . $language_id ] = [ $taxonomy, $term_id, $language_id, $slug ];
	}

	/**
	 * Shutdown: one event per blog and BATCH items, a minute out.
	 *
	 * @internal
	 * @return void
	 */
	public static function flush(): void {
		$queued       = self::$queued;
		self::$queued = [];

		foreach ( $queued as $blog => $items ) {
			$switched = is_multisite() && get_current_blog_id() !== $blog;

			if ( $switched ) {
				switch_to_blog( $blog );
			}

			foreach ( array_chunk( array_values( $items ), self::BATCH ) as $chunk ) {
				BackgroundEvents::enqueue( self::HOOK, [ $chunk, 1 ], MINUTE_IN_SECONDS );
			}

			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The event: write the items still linked in their language, insert-only, and run the claims; a busy lock queues the
	 * rest again.
	 *
	 * @param mixed $items Items from the event.
	 * @param mixed $try   Attempt number.
	 * @return void
	 */
	public static function run( mixed $items, mixed $try = 1 ): void {
		$try  = max( 1, Typed::int( $try ) );
		$todo = [];

		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( is_array( $item ) && 4 === count( $item ) && is_string( $item[0] ) && is_numeric( $item[1] ) && is_numeric( $item[2] ) && is_string( $item[3] ) ) {
				$todo[ (int) $item[1] ] = [ $item[0], (int) $item[1], (int) $item[2], $item[3] ];
			}
		}

		$todo = array_slice( $todo, 0, self::BATCH, true );

		if ( [] === $todo || ! Schema::tables_exist() ) {
			return;
		}

		// A term deleted or moved since then owns its rows.
		$wpdb = Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$linked = $wpdb->get_results( $wpdb->prepare( "SELECT l.object_id, l.language_id FROM %i l INNER JOIN %i t ON t.term_id = l.object_id WHERE l.type = 'term' AND l.object_id IN (" . implode( ',', array_fill( 0, count( $todo ), '%d' ) ) . ')', array_merge( [ Schema::table( 'translation_links' ), $wpdb->terms ], array_keys( $todo ) ) ), ARRAY_A );
		$write  = [];

		foreach ( Typed::rows( $linked ) as $row ) {
			$item = $todo[ Typed::int( $row['object_id'] ?? 0 ) ] ?? null;

			if ( null !== $item && $item[2] === Typed::int( $row['language_id'] ?? 0 ) ) {
				$write[] = $item;
			}
		}

		$claims = array_values( array_filter( $write, static fn( array $i ): bool => '' === $i[3] ) );
		$write  = array_values( array_filter( $write, static fn( array $i ): bool => '' !== $i[3] ) );
		$left   = [];
		SlugTranslationWrites::backfill_term_url_slugs( $write, $left );

		foreach ( $claims as $n => [ $taxonomy, $term_id, $language_id ] ) {
			$term = get_term( $term_id, $taxonomy );
			$r    = $term instanceof \WP_Term ? SlugTranslationWrites::move_term_url_slug( $taxonomy, $term_id, 0, $language_id, $term->slug ) : null;

			// Still busy: this claim and the ones after it wait for the next event.
			if ( null !== $r && 'busy' === $r['error'] ) {
				$left = array_merge( $left, array_slice( $claims, $n ) );
				break;
			}
		}

		if ( [] !== $left && $try < self::MAX_TRIES ) {
			BackgroundEvents::enqueue( self::HOOK, [ $left, $try + 1 ], min( 15 * MINUTE_IN_SECONDS, MINUTE_IN_SECONDS << min( $try, 4 ) ) );
		}
	}
}
