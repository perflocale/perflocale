<?php
/**
 * Keeps a long machine-translation run from leaving translations in a language deleted or deactivated while it ran.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Used by the per-pair loops of BulkTranslateJob (and SiteTranslateJob) and `wp perflocale translate`.
 *
 * Call checkpoint() before each pair, written() after each insert, settle() to remove what a deleted language left:
 * docs-ai/11-code-rationale.md, "TargetLanguageGuard: checkpoint and settle".
 */
final class TargetLanguageGuard {

	/**
	 * Pairs a long run handles between two settle() calls at most.
	 */
	public const SETTLE_EVERY = 500;

	/**
	 * Translations written since the last settle(): post id => language id.
	 *
	 * @var array<int, int>
	 */
	private array $pending = [];

	/**
	 * A change another process made was found outside checkpoint(); the next checkpoint() reports it.
	 *
	 * @var bool
	 */
	private bool $missed = false;

	/**
	 * Bring the process up to date before a pair; true when another process changed cached data (re-resolve the target).
	 *
	 * @return bool
	 */
	public function checkpoint(): bool {
		$changed = CacheManager::refresh_if_changed();

		if ( $this->missed ) {
			$this->missed = false;

			return true;
		}

		return $changed;
	}

	/**
	 * Note a change another process made, found while the run dropped its runtime caches: the next checkpoint() returns true.
	 *
	 * @return void
	 */
	public function changed_elsewhere(): void {
		$this->missed = true;
	}

	/**
	 * The language when it exists and is active, from the checkpointed language cache; null otherwise.
	 *
	 * @param int $language_id Language id.
	 * @return object|null
	 */
	public function live_target( int $language_id ): ?object {
		$repo = Plugin::get_instance()->get( 'lang_repo' );

		if ( ! $repo instanceof LanguageRepository ) {
			return null;
		}

		$language = $repo->find( $language_id );

		return null !== $language && ! empty( get_object_vars( $language )['is_active'] ) ? $language : null;
	}

	/**
	 * A language's slug ('' when it has none).
	 *
	 * @param object|null $language Language row.
	 * @return string
	 */
	public static function slug_of( ?object $language ): string {
		$slug = null === $language ? null : ( get_object_vars( $language )['slug'] ?? null );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * A language's id (0 when it has none).
	 *
	 * @param object|null $language Language row.
	 * @return int
	 */
	public static function id_of( ?object $language ): int {
		$id = null === $language ? null : ( get_object_vars( $language )['id'] ?? null );

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * Record a translation this run just inserted (an updated one existed before the run and is never removed).
	 *
	 * @param int $post_id     The translation's post id.
	 * @param int $language_id Its language id.
	 * @return void
	 */
	public function written( int $post_id, int $language_id ): void {
		if ( $post_id > 0 && $language_id > 0 ) {
			$this->pending[ $post_id ] = $language_id;
		}
	}

	/**
	 * Remove every translation recorded since the last call whose language does not exist.
	 *
	 * A deactivated language keeps its translations; one linked again or filed under another language is kept too.
	 * A failed read removes nothing and keeps the entries not yet handled for the next call.
	 *
	 * @return array<int, int> The removed translations: post id => language id.
	 */
	public function settle(): array {
		if ( [] === $this->pending ) {
			return [];
		}

		global $wpdb;

		$group = Plugin::get_instance()->get( 'group_repo' );

		if ( ! $wpdb instanceof \wpdb || ! $group instanceof TranslationGroupRepository ) {
			return [];
		}

		$ids = array_values( array_unique( $this->pending ) );

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The language rows as committed; one %d placeholder per id.
		$live = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE id IN ({$in})", Schema::table( 'languages' ), ...$ids ) );

		// A failed read answers with no rows, which is not "no language exists": remove nothing (an orphan, not a loss).
		if ( self::failed( $wpdb ) ) {
			return [];
		}

		$live = array_map( static fn( $id ): int => is_numeric( $id ) ? (int) $id : 0, $live );
		$gone = [];

		foreach ( $this->pending as $post_id => $language_id ) {
			if ( in_array( $language_id, $live, true ) ) {
				unset( $this->pending[ $post_id ] );
			} else {
				$gone[ $post_id ] = $language_id;
			}
		}

		if ( [] === $gone ) {
			return [];
		}

		// Each post's link as it is now, read before anything is removed; a failed read keeps every entry.
		$posts = array_keys( $gone );
		$in    = implode( ',', array_fill( 0, count( $posts ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The links as committed; one %d placeholder per post id.
		$links = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, language_id FROM %i WHERE type = 'post' AND object_id IN ({$in})", Schema::table( 'translation_links' ), ...$posts ), ARRAY_A );

		if ( self::failed( $wpdb ) || ! is_array( $links ) ) {
			return [];
		}

		$relinked = [];

		foreach ( $links as $link ) {
			$object = $link['object_id'] ?? null;
			$lang   = $link['language_id'] ?? null;

			if ( is_numeric( $object ) && is_numeric( $lang ) && (int) $lang !== ( $gone[ (int) $object ] ?? 0 ) ) {
				$relinked[ (int) $object ] = true;
			}
		}

		$removed = [];

		foreach ( $gone as $post_id => $language_id ) {
			unset( $this->pending[ $post_id ] );

			if ( isset( $relinked[ $post_id ] ) ) {
				continue;
			}

			$group->unlink_by_object_id( (int) $post_id );
			wp_delete_post( (int) $post_id, true );
			$removed[ (int) $post_id ] = $language_id;
		}

		return $removed;
	}

	/**
	 * Whether the statement that just ran failed (wpdb clears last_error on every query).
	 *
	 * @phpstan-impure
	 *
	 * @param \wpdb $wpdb Database.
	 * @return bool
	 */
	private static function failed( \wpdb $wpdb ): bool {
		return '' !== $wpdb->last_error;
	}

	/**
	 * The reason a pair failed when its language went away after its translation was written.
	 *
	 * @return string
	 */
	public static function reason(): string {
		return __( 'The target language was deleted while the translation ran; the translation made for it was removed.', 'perflocale' );
	}

	/**
	 * The reason a pair failed when its language had gone before the pair was translated.
	 *
	 * @return string
	 */
	public static function gone_reason(): string {
		return __( 'The target language was deleted or deactivated while the run went on; this pair was not translated.', 'perflocale' );
	}
}
