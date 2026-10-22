<?php
/**
 * Keeps a long machine-translation run from leaving translations in a
 * language that was deleted or deactivated while it ran.
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
 * Used by the loops that write one translation per (source post, target
 * language) pair for a long time: BulkTranslateJob (and SiteTranslateJob,
 * which runs it) and `wp perflocale translate`.
 *
 * - checkpoint() before each pair: CacheManager::refresh_if_changed(), so
 *   the pair reads the languages, settings and links as they are now, and a
 *   language deleted or deactivated meanwhile no longer resolves. One query.
 * - written() after each translation the run inserted (one it updated
 *   existed before the run and is never removed), and settle() at the end of
 *   the run, whenever a checkpoint finds a change, and after every
 *   SETTLE_EVERY pairs of a long run (so the translations it holds stay
 *   bounded): one query reads which of the target languages still exist,
 *   and every translation written for one that does not is removed again
 *   (its link and its post), unless a second query, run only then, finds it
 *   linked again or filed under another language. A
 *   deactivated target keeps what was written for it; its remaining pairs are
 *   not translated. A language deleted between a
 *   checkpoint and the write is found this way: the delete removes the
 *   language row and its links in one transaction, so a translation written
 *   after it committed is caught here, and one written before it lost its
 *   link to the delete itself.
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
	 * A change another process made was found outside checkpoint() (a cache
	 * release of the run); the next checkpoint() reports it.
	 *
	 * @var bool
	 */
	private bool $missed = false;

	/**
	 * Bring the process up to date before a pair; true when another process
	 * changed cached data (the caller re-resolves the target language).
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
	 * Note a change another process made that the run found while dropping
	 * its runtime caches, which brought the process up to date as
	 * checkpoint() does: the next checkpoint() returns true.
	 *
	 * @return void
	 */
	public function changed_elsewhere(): void {
		$this->missed = true;
	}

	/**
	 * The language, when it exists and is active, read from the process's
	 * (checkpointed) language cache; null otherwise.
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
	 * Record a translation this run just inserted. A translation the run
	 * updated existed before it and is not recorded.
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
	 * Remove every translation recorded since the last call whose language
	 * no longer exists. A deactivated language keeps what was written for
	 * it (an inactive language keeps its translations linked, and can be
	 * activated again); its remaining pairs are not translated
	 * (live_target()). A translation linked again since, or filed under
	 * another language, is kept. An entry is cleared once its language (and,
	 * for a language that is gone, its link) was read and the entry handled;
	 * when a read fails, nothing is removed and the entries not yet handled
	 * are kept for the next call.
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

		// A failed read answers with no rows, which is not "no language
		// exists": nothing is removed, and every entry stays for the next
		// settle(). A translation kept for a language that is gone is an
		// orphan, not a loss.
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

		// Each post's link as it is now, read before anything is removed: a
		// post linked again since, or filed under another language, is kept.
		// A failed read keeps every entry for the next settle().
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
	 * Whether the statement that just ran failed (wpdb clears last_error at
	 * the start of every query).
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
	 * The reason a pair is reported failed when its language went away after
	 * its translation was written.
	 *
	 * @return string
	 */
	public static function reason(): string {
		return __( 'The target language was deleted while the translation ran; the translation made for it was removed.', 'perflocale' );
	}

	/**
	 * The reason a pair is reported failed when its language had gone before
	 * the pair was translated.
	 *
	 * @return string
	 */
	public static function gone_reason(): string {
		return __( 'The target language was deleted or deactivated while the run went on; this pair was not translated.', 'perflocale' );
	}
}
