<?php
/**
 * Generates .l10n.php translation files from database string translations.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates WordPress 6.5+ .l10n.php translation files per domain per locale,
 * plus one combined per-locale bundle pre-keyed for TranslationFileLoader's
 * O(1) fast path.
 *
 * Files are written to wp-content/uploads/perflocale/translations/ and loaded
 * by TranslationFileLoader on the frontend via WP_Translation_Controller.
 */
final class TranslationFileGenerator {

	/**
	 * Autoloaded option holding the exact list of .l10n.php basenames that
	 * exist on disk after the last generation. The loader reads this to skip a
	 * per-request directory glob; this generator is the sole writer/deleter of
	 * the directory, so the manifest reflects disk by construction. Per-blog on
	 * multisite (autoloaded options are blog-scoped, matching the per-blog
	 * uploads dir).
	 *
	 * @var string
	 */
	public const MANIFEST_OPTION = 'perflocale_l10n_manifest';

	/**
	 * Combined-bundle format version embedded in every combined per-locale
	 * file and required by TranslationFileLoader before it trusts one. The
	 * bundle persists TranslationFileLoader::map_key() composite keys, so any
	 * change to that key format (or to the bundle's array shape) must bump
	 * this — otherwise a bundle written by an older plugin version would be
	 * served with keys the current lookup can never match.
	 *
	 * @var int
	 */
	public const COMBINED_VERSION = 1;

	/**
	 * Filename prefix of the combined per-locale bundle. Shared as a constant
	 * because the loader derives the bundle name as
	 * `COMBINED_PREFIX . $suffix` from its already-built per-domain suffix —
	 * one shared literal instead of a second sanitize_file_name() call on the
	 * hot path.
	 *
	 * @var string
	 */
	public const COMBINED_PREFIX = '_combined';

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Generate .l10n.php files for all domains and all active languages.
	 *
	 * Self-heals orphaned data before generating: strings that have rows in
	 * `string_translations` but are missing their `translation_groups` /
	 * `translation_links` connection are auto-relinked so the generator can
	 * produce files for them on this run. This prevents the historical
	 * "Regenerate Translation Files reports 0" loop where translations
	 * existed in the DB but the generator's JOIN could not reach them.
	 *
	 * @param int[]|null $only_language_ids When set, regenerate only these
	 *                                      languages' files: the fetch, the
	 *                                      orphan cleanup, and the manifest
	 *                                      update are all scoped to them and
	 *                                      other languages' files stay
	 *                                      untouched on disk. A single-string
	 *                                      admin save otherwise re-fetched
	 *                                      EVERY language's full translation
	 *                                      set — O(total translations
	 *                                      sitewide) per save. Null = full run
	 *                                      (Regenerate button, mode switch).
	 * @return int Number of per-domain files that were actually written to
	 *             disk on this run. Excludes files skipped because their
	 *             content was already current, and excludes writes that
	 *             failed — see write_l10n_file().
	 */
	public function generate_all( ?array $only_language_ids = null ): int {
		$this->ensure_directory();

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( empty( $languages ) ) {
			return 0;
		}

		if ( $only_language_ids !== null ) {
			$only      = array_map( 'intval', $only_language_ids );
			$languages = array_values(
				array_filter(
					(array) $languages,
					static fn( $lang ): bool => is_object( $lang ) && in_array( (int) $lang->id, $only, true )
				)
			);

			if ( empty( $languages ) ) {
				return 0;
			}
		}

		// The default language serves source strings unchanged — the loader's
		// activate() fast-path never loads a default-locale bundle — so
		// generating one is wasted work and leaves a dead, confusing file
		// (e.g. a default-locale bundle for a string a user "translated" into
		// the source language). Exclude it here; the Step-3 cleanup below then
		// reclaims any default-locale file a prior run may have written.
		$languages = array_values(
			array_filter(
				(array) $languages,
				static fn( $lang ): bool => is_object( $lang ) && empty( $lang->is_default )
			)
		);

		if ( empty( $languages ) ) {
			return 0;
		}

		// Self-heal orphans first so the JOIN-based fetch below can see
		// them. We deliberately do this on every Regenerate run, not just
		// when there is a problem — repair is idempotent and cheap when
		// nothing needs fixing.
		$this->repair_orphaned_translations();

		// Fetch all translated strings grouped by domain and language.
		$all_translations = $this->fetch_all_translations( $languages );

		// Step 1: compute the full target state (path => content) without
		// touching disk. Strings whose `messages` array is empty don't
		// produce a file; if such a file existed on disk it'll be cleaned
		// up by Step 3 below.
		//
		// Alongside the per-domain files, each locale gets ONE combined
		// bundle: the same messages re-keyed into the loader's composite
		// map_key() format, so load_translations() can bind a single
		// OPcache-shared array instead of re-walking every message of every
		// domain on each request. Kept out of $target because its write
		// ordering differs — a stale bundle would SHADOW fresh per-domain
		// files, so it is deleted before any per-domain mutation and
		// rewritten last.
		$target          = [];
		$combined_target = [];

		foreach ( $all_translations as $locale => $domains ) {
			$combined_map = [];

			foreach ( $domains as $domain => $messages ) {
				if ( empty( $messages ) ) {
					continue;
				}

				$path            = $this->compute_l10n_path( $domain, $locale );
				$target[ $path ] = $this->compute_l10n_content( $messages );

				foreach ( $messages as $key => $value ) {
					// .l10n.php keys are `context\x04original`; a purely
					// numeric original is stored by PHP as an int array key,
					// so stringify to match the loader's read side exactly.
					$context  = '';
					$original = $key;

					if ( is_string( $key ) && str_contains( $key, "\x04" ) ) {
						[ $context, $original ] = explode( "\x04", $key, 2 );
					}

					$combined_map[ TranslationFileLoader::map_key( $domain, $context, strval( $original ) ) ] = $value;
				}
			}

			if ( $combined_map !== [] ) {
				$combined_target[ $this->compute_combined_path( (string) $locale ) ] = $this->compute_combined_content( $combined_map );
			}
		}

		// A combined bundle takes priority over per-domain files in the
		// loader, so a stale one must never outlive a change to its locale's
		// files: delete a changed/obsolete bundle BEFORE any per-domain write
		// or delete (readers fall back to the per-domain path meanwhile) and
		// write the fresh bundle last, after Step 3 has settled the
		// directory. Unchanged bundles stay live untouched — no write, no
		// OPcache invalidation.
		$combined_pending = [];

		foreach ( $languages as $language ) {
			$locale = isset( $language->locale ) ? (string) $language->locale : '';

			if ( $locale === '' ) {
				continue;
			}

			$cpath = $this->compute_combined_path( $locale );
			$fresh = $combined_target[ $cpath ] ?? null;

			if ( $fresh !== null && is_file( $cpath ) && md5_file( $cpath ) === md5( $fresh ) ) {
				continue;
			}

			if ( is_file( $cpath ) ) {
				wp_delete_file( $cpath );

				if ( function_exists( 'opcache_invalidate' ) ) {
					opcache_invalidate( $cpath, true );
				}
			}

			if ( $fresh !== null ) {
				$combined_pending[ $cpath ] = $fresh;
			}
		}

		// Step 2: write only files whose content actually changed.
		// Comparing md5 of existing-file vs new-content (each .l10n.php
		// is small — typically 1-50 KB) costs ~50-200 µs per file but
		// saves a full disk write + opcache invalidation when content is
		// identical. On a typical save that touches one string in one
		// language, this collapses 15 file-writes into 1.
		// Only a write that actually landed is counted. write_l10n_file()
		// reports its own failures through `perflocale/strings/file_write_failed`;
		// what must not happen here is counting the attempt, because this
		// number is what the Regenerate screen shows the operator.
		$count = 0;

		foreach ( $target as $path => $content ) {
			if ( is_file( $path ) && md5_file( $path ) === md5( $content ) ) {
				continue;
			}

			if ( $this->write_l10n_file( $path, $content ) ) {
				++$count;
			}
		}

		// Step 3: delete orphans — any .l10n.php on disk that no longer
		// belongs (its domain was removed, its language was deleted, or
		// every translation in that bucket was cleared). Doing this AFTER
		// the writes eliminates the "transient empty directory" window
		// that the old clean_all-then-write flow had — if a frontend
		// request landed during that window, every translated string
		// briefly fell back to its source.
		//
		// On a language-scoped run, files belonging to OTHER languages are
		// out of scope: $target only covers the subset, so deleting
		// everything not in it would wipe every other language's bundles.
		$subset_suffixes = null;

		if ( $only_language_ids !== null ) {
			$subset_suffixes = array_map(
				static fn( $lang ): string => '-' . sanitize_file_name( (string) $lang->locale ) . '.l10n.php',
				$languages
			);
		}

		$dir  = $this->get_translations_dir();
		$kept = [];

		foreach ( (array) glob( $dir . '/*.l10n.php' ) as $existing ) {
			if ( $subset_suffixes !== null ) {
				$in_subset = false;

				foreach ( $subset_suffixes as $suffix ) {
					if ( str_ends_with( $existing, $suffix ) ) {
						$in_subset = true;
						break;
					}
				}

				if ( ! $in_subset ) {
					$kept[] = $existing; // Another language's file — keep untouched.
					continue;
				}
			}

			if ( ! isset( $target[ $existing ] ) && ! isset( $combined_target[ $existing ] ) ) {
				wp_delete_file( $existing );

				// Drop opcache for the deleted file too.
				if ( function_exists( 'opcache_invalidate' ) ) {
					opcache_invalidate( $existing, true );
				}
			}
		}

		// Write the fresh combined bundles LAST — per-domain writes and
		// orphan deletion are settled, so a reader that binds a bundle sees
		// exactly this run's final state. Not counted in $count: bundles are
		// an internal cache tier, not user-visible domain files.
		foreach ( $combined_pending as $cpath => $content ) {
			$this->write_l10n_file( $cpath, $content );
		}

		// Step 4: persist the file manifest so the loader can skip its
		// per-request directory glob. $target's and $combined_target's keys
		// are the subset's intended files; $kept holds the out-of-scope files
		// Step 3 left alone (empty on a full run). Autoloaded + per-blog; the
		// loader falls back to glob when this option is absent. A combined
		// bundle absent from the manifest is never trusted by the loader, so
		// listing it here is what activates the fast path.
		//
		// The list is filtered through is_file() rather than taken on trust:
		// an intended path is not a written path. On a host where the writes
		// above failed (read-only uploads, no WP_Filesystem credentials) the
		// unfiltered list published basenames that do not exist, and the
		// loader — which prefers the manifest over a glob — then walked a set
		// of phantom files. Its realpath guard skips each one, so the outcome
		// was untranslated output rather than an error, but the option is
		// documented as reflecting disk and now does by observation.
		// clearstatcache() first: the writes just went through
		// WP_Filesystem::move(), which does not invalidate PHP's stat cache
		// for the destination path.
		clearstatcache();

		$manifest = [];

		foreach ( array_merge( array_keys( $target ), array_keys( $combined_target ), $kept ) as $manifest_path ) {
			if ( is_file( $manifest_path ) ) {
				$manifest[] = basename( $manifest_path );
			}
		}

		$manifest = array_values( array_unique( $manifest ) );
		sort( $manifest );
		update_option( self::MANIFEST_OPTION, $manifest, true );

		return $count;
	}

	/**
	 * Resolve the path for a (domain, locale) translation file.
	 *
	 * @param string $domain Text domain.
	 * @param string $locale Locale (e.g. fr_FR).
	 * @return string Absolute filesystem path.
	 */
	private function compute_l10n_path( string $domain, string $locale ): string {
		return $this->get_translations_dir()
			. '/' . sanitize_file_name( $domain )
			. '-' . sanitize_file_name( $locale )
			. '.l10n.php';
	}

	/**
	 * Build the .l10n.php content body for a translation set.
	 *
	 * Kept pure (no I/O, no globals) so generate_all can hash the result
	 * against existing files on disk before deciding whether to write.
	 *
	 * @param array<string, string> $messages Translations [original => translation].
	 * @return string PHP source ready for file_put_contents.
	 */
	private function compute_l10n_content( array $messages ): string {
		$data = [
			'messages' => $messages,
		];

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		return "<?php\n// Generated by PerfLocale - do not edit manually.\nreturn " . var_export( $data, true ) . ";\n";
	}

	/**
	 * Basename of a locale's combined bundle (every domain's messages
	 * re-keyed into TranslationFileLoader::map_key() format).
	 *
	 * Ends in the same `-<locale>.l10n.php` suffix as the per-domain files ON
	 * PURPOSE: every locale-wide cleanup glob (clean_all(), the
	 * language-delete cleanup in LanguageRepository) then removes the bundle
	 * together with the per-domain files it was derived from — a bundle that
	 * outlived its locale would shadow fresh state if that locale were ever
	 * re-added. The `_` prefix is reserved: fetch_all_translations() skips
	 * `_`-prefixed (internal) domains, so generated per-domain files never
	 * carry it.
	 *
	 * @param string $locale Locale (e.g. fr_FR).
	 * @return string
	 */
	public static function combined_basename( string $locale ): string {
		return self::COMBINED_PREFIX . '-' . sanitize_file_name( $locale ) . '.l10n.php';
	}

	/**
	 * Resolve the path for a locale's combined bundle.
	 *
	 * @param string $locale Locale (e.g. fr_FR).
	 * @return string Absolute filesystem path.
	 */
	private function compute_combined_path( string $locale ): string {
		return $this->get_translations_dir() . '/' . self::combined_basename( $locale );
	}

	/**
	 * Build the combined-bundle content body for a pre-keyed composite map.
	 *
	 * Deliberately a different shape from compute_l10n_content() ('v' +
	 * 'map', no 'messages') so the loader's per-domain fallback loop skips a
	 * bundle it does not trust instead of mis-parsing it as a text domain.
	 *
	 * @param array<string, string> $map Composite map [map_key => translation].
	 * @return string PHP source ready for file_put_contents.
	 */
	private function compute_combined_content( array $map ): string {
		$data = [
			'v'   => self::COMBINED_VERSION,
			'map' => $map,
		];

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		return "<?php\n// Generated by PerfLocale - do not edit manually.\nreturn " . var_export( $data, true ) . ";\n";
	}

	/**
	 * Remove all generated translation files.
	 *
	 * Also sweeps `*.l10n.php.<hex>.tmp` staging files. write_l10n_file()
	 * writes to a randomly-suffixed sibling and then moves it onto the final
	 * name, and it deletes its own temp on both failure paths — but a process
	 * killed between the write and the move (max_execution_time on a large
	 * regeneration, an OOM, a deploy restart) leaves the temp behind. Those
	 * names do not end in `.l10n.php`, so neither this method's original glob
	 * nor generate_all()'s orphan sweep could ever see them and the debris
	 * accumulated for the life of the install. Switching string mode back to
	 * "database" is the operator's "remove everything" action, so it is the
	 * right place to reclaim them.
	 *
	 * @return void
	 */
	public function clean_all(): void {
		$dir = $this->get_translations_dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_merge(
			(array) glob( $dir . '/*.l10n.php' ),
			(array) glob( $dir . '/*.l10n.php.*.tmp' )
		);

		foreach ( $files as $file ) {
			if ( is_string( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Get the translations directory path.
	 *
	 * @return string
	 */
	public function get_translations_dir(): string {
		return \PerfLocale\Helper::uploads_translations_dir();
	}

	/**
	 * Ensure the translations directory exists with defense-in-depth
	 * protection files (.htaccess + index.php + web.config) installed via
	 * the shared helper. Also hardens the parent `uploads/perflocale/` dir
	 * since `wp_mkdir_p` creates the parent implicitly without protection.
	 *
	 * @return void
	 */
	private function ensure_directory(): void {
		$dir = $this->get_translations_dir();

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return;
			}
		}

		\PerfLocale\Helper::harden_directory( $dir );

		// Also harden the parent `uploads/perflocale/` dir which
		// `wp_mkdir_p` created implicitly. Without this, an autoindex-on
		// host would expose the `translations/` subdir name from the
		// parent listing.
		$parent = dirname( $dir );
		if ( $parent !== '' && $parent !== '.' && $parent !== '/' ) {
			\PerfLocale\Helper::harden_directory( $parent );
		}
	}

	/**
	 * Self-heal `string_translations` rows that have lost their connection
	 * to `translation_groups` / `translation_links`. Repairs three shapes
	 * (idempotent — running it when nothing is broken is a no-op):
	 *
	 *   1. Dangling rows where `string_translations.string_id` points at a
	 *      `strings.id` that no longer exists → DELETE the row (the source
	 *      string is gone; we cannot recover the translation usefully).
	 *   2. Strings with `group_id = 0` or pointing at a non-string-type
	 *      group → create a fresh `translation_groups` row of type='string'
	 *      and point the string at it.
	 *   3. Strings that ARE in a string group, AND have a translation row,
	 *      but the matching `translation_links` row is missing → INSERT it
	 *      so the generator's JOIN sees the pair.
	 *
	 * @return array{dangling: int, regrouped: int, relinked: int} Repair counts.
	 */
	public function repair_orphaned_translations(): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$strings_table = Schema::table( 'strings' );
		$groups_table  = Schema::table( 'translation_groups' );
		$links_table   = Schema::table( 'translation_links' );
		$st_table      = Schema::table( 'string_translations' );

		$out = [
			'dangling'  => 0,
			'regrouped' => 0,
			'relinked'  => 0,
		];

		// (1) Dangling: string_translations rows whose string_id is gone.
		$out['dangling'] = (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE st FROM %i st
				LEFT JOIN %i s ON s.id = st.string_id
				WHERE s.id IS NULL',
				$st_table,
				$strings_table
			)
		);

		// (2) Strings that have at least one translation, but no usable
		// group. Give them a fresh string-type group apiece.
		$ungrouped = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT s.id FROM %i s
				INNER JOIN %i st ON st.string_id = s.id
				WHERE s.group_id = 0
				OR s.group_id NOT IN (SELECT id FROM %i WHERE type = 'string')",
				$strings_table,
				$st_table,
				$groups_table
			)
		);

		foreach ( (array) $ungrouped as $string_id ) {
			$string_id = (int) $string_id;
			if ( $string_id <= 0 ) {
				continue;
			}
			$inserted = $wpdb->insert(
				$groups_table,
				[ 'type' => 'string' ],
				[ '%s' ]
			);
			if ( ! $inserted ) {
				continue;
			}
			$group_id = (int) $wpdb->insert_id;
			$wpdb->update(
				$strings_table,
				[ 'group_id' => $group_id ],
				[ 'id' => $string_id ],
				[ '%d' ],
				[ '%d' ]
			);
			++$out['regrouped'];
		}

		// (3) Missing translation_links: there is a (string, language)
		// pair with a translation row, the string lives in a string-type
		// group, but no link row exists for that (group, language).
		// `object_id` on the link is the string_id (per the
		// TranslationGroupRepository::link_object contract for
		// string groups).
		//
		// translation_links carries TWO unique keys (Schema.php:123-124), and
		// the LEFT JOIN below only tests one of them:
		//
		// - group_lang (group_id, language_id) — what "l.id IS NULL" tests.
		// - object_lang (type, object_id, language_id) — untested here.
		//
		// So a row absent from this group but present for the SAME
		// (type='string', string_id, language) under a DIFFERENT group would
		// collide on object_lang, and INSERT IGNORE would swallow it: the
		// string stays unreachable to fetch_all_translations()'s
		// `l.group_id = s.group_id` join and count_repairable_orphans() keeps
		// counting it, run after run. No code path was found that can produce
		// that state — groups are immutable (TranslationGroupRepository::update()
		// returns false), every group delete removes its links first, and step
		// (2) above is the only writer of strings.group_id — so this is
		// recorded, not guarded against. Do not "simplify" the comment back to
		// naming one key: believing translation_links has a single unique key
		// is what produced the upsert defect fixed in release week.
		$missing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS string_id, s.group_id, st.language_id
				FROM %i st
				INNER JOIN %i s ON s.id = st.string_id
				INNER JOIN %i g ON g.id = s.group_id AND g.type = 'string'
				LEFT JOIN %i l
				ON l.group_id = s.group_id AND l.language_id = st.language_id
				WHERE l.id IS NULL",
				$st_table,
				$strings_table,
				$groups_table,
				$links_table
			)
		);

		foreach ( (array) $missing as $row ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					// `type` mirrors the owning group's type (Schema.php:89); this
					// repair only ever re-links string-type groups.
					'INSERT IGNORE INTO %i
					 (group_id, object_id, language_id, type, status, source)
					 VALUES (%d, %d, %d, \'string\', %s, %s)',
					$links_table,
					(int) $row->group_id,
					(int) $row->string_id,
					(int) $row->language_id,
					'published',
					'manual'
				)
			);
			if ( $inserted ) {
				++$out['relinked'];
			}
		}
		// phpcs:enable

		// No explicit cache flush needed: `fetch_all_translations()` reads
		// the relevant tables directly with wpdb, not through CacheManager.

		return $out;
	}

	/**
	 * Count rows that the generator currently cannot compile (orphans of
	 * any of the three shapes that `repair_orphaned_translations()`
	 * handles). Used by Site Health so the message can name the actual
	 * size of the problem in plain language.
	 *
	 * @return int
	 */
	public function count_repairable_orphans(): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$strings_table = Schema::table( 'strings' );
		$groups_table  = Schema::table( 'translation_groups' );
		$links_table   = Schema::table( 'translation_links' );
		$st_table      = Schema::table( 'string_translations' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i st
				LEFT JOIN %i s ON s.id = st.string_id
				LEFT JOIN %i  g ON g.id = s.group_id AND g.type = 'string'
				LEFT JOIN %i   l ON l.group_id = s.group_id AND l.language_id = st.language_id
				WHERE s.id IS NULL
				OR g.id IS NULL
				OR l.id IS NULL",
				$st_table,
				$strings_table,
				$groups_table,
				$links_table
			)
		);
		// phpcs:enable
	}

	/**
	 * Fetch all string translations grouped by locale and domain.
	 *
	 * @param array<int, object> $languages Active languages.
	 * @return array<string, array<string, array<string, string>>> [locale => [domain => [original => translation]]].
	 */
	private function fetch_all_translations( array $languages ): array {
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$strings_table     = Schema::table( 'strings' );
		$links_table       = Schema::table( 'translation_links' );
		$groups_table      = Schema::table( 'translation_groups' );
		$translations_repo = new \PerfLocale\Database\Repository\StringTranslationRepository( $this->cache );

		$result = [];

		foreach ( $languages as $language ) {
			$language_id = (int) $language->id;
			$locale      = $language->locale;

			if ( empty( $locale ) ) {
				continue;
			}

			// Get all strings with translations for this language.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id AS string_id, s.original, s.context, s.domain
					FROM %i s
					INNER JOIN %i g
						ON g.id = s.group_id AND g.type = 'string'
					INNER JOIN %i l
						ON l.group_id = s.group_id AND l.language_id = %d",
					$strings_table,
					$groups_table,
					$links_table,
					$language_id
				)
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				continue;
			}

			// Batch-fetch translations from the dedicated table.
			// Using StringTranslationRepository::get_map() runs one query
			// keyed on (string_id, language_id) PRIMARY KEY - was N SELECTs
			// against wp_options via CONCAT-joins before.
			$string_ids = array_map( static fn( $row ): int => (int) $row->string_id, $rows );
			$tr_map     = $translations_repo->get_map( $string_ids, $language_id );
			// Plural forms 2..N (Polish/Russian/Arabic) for the rows that
			// have them — usually empty.
			$extra_map = $translations_repo->get_extra_forms_map( $string_ids, $language_id );

			// Group by domain.
			foreach ( $rows as $row ) {
				$value = $tr_map[ (int) $row->string_id ] ?? '';

				if ( $value === '' ) {
					continue;
				}

				// Skip internal, output-buffer-served domains (a leading "_",
				// e.g. the Visual Editor's "_pfl_dyn"). These are never resolved
				// through __()/gettext — only via that addon's own DB-sourced
				// maps — so writing a .l10n.php for them just leaks a dead file
				// and makes the gettext filters register on languages that have
				// no real string translations.
				if ( isset( $row->domain[0] ) && $row->domain[0] === '_' ) {
					continue;
				}

				$domain = $row->domain ?: 'default';

				// A plural-context row that carries extra forms is stored
				// NUL-joined (form1\0form2\0…) — the gettext convention the
				// loader splits back apart per CLDR form index. Only the
				// plural-context rows of 3+ form languages hit this.
				$extra = $extra_map[ (int) $row->string_id ] ?? null;

				if ( is_array( $extra ) && $extra !== [] ) {
					$value = $value . "\0" . implode( "\0", $extra );
				}

				// WordPress .l10n.php uses context\x04original as key for contextual strings.
				$key = ! empty( $row->context ) ? $row->context . "\x04" . $row->original : $row->original;

				$result[ $locale ][ $domain ][ $key ] = $value;
			}
		}

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result;
	}

	/**
	 * Write a pre-computed .l10n.php file to disk.
	 *
	 * Path and content are computed by compute_l10n_path() and
	 * compute_l10n_content() respectively, so generate_all can decide
	 * whether the write is needed (via md5 compare) before calling here.
	 *
	 * Every failure path here is survivable — the site keeps serving source
	 * strings — but it must not be invisible. This used to return void, so
	 * generate_all() counted an attempt as a file and reported that count to
	 * the operator: on a host where the uploads directory is read-only, or
	 * where WP_Filesystem cannot initialise without FTP credentials, the
	 * Regenerate screen said "15 translation files generated" while nothing
	 * whatsoever reached disk, and files mode silently never worked.
	 *
	 * @param string $path    Absolute filesystem path.
	 * @param string $content PHP source to write.
	 * @return bool True when the file is on disk with this content.
	 */
	private function write_l10n_file( string $path, string $content ): bool {
		$wp_filesystem = \PerfLocale\Helper::filesystem();

		if ( ! $wp_filesystem ) {
			$this->report_write_failure( $path, 'no_filesystem' );

			return false;
		}

		// Write to a sibling temp file, then atomic-move onto $path so a
		// concurrent frontend `include $file` (TranslationFileLoader) can
		// never see a truncated or half-written PHP file. put_contents() is
		// fopen('wb')+fwrite+fclose under the hood, which truncates the
		// existing inode in place — a reader landing in that window gets a
		// parse error. Both the write AND the move route through $wp_filesystem
		// so they share the same UID — on FTPext/SSH2 backends, mixing
		// $wp_filesystem->put_contents() with native PHP rename() would write
		// the temp as the FTP user and try to rename it as the web-server
		// user, which fails. The random suffix isolates concurrent writers.
		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// FS_CHMOD_FILE is defined by WP_Filesystem(), not by WordPress boot.
		// Helper::filesystem() returns an already-populated `$wp_filesystem`
		// global untouched, and a plugin that assembles one itself (to skip
		// the credentials form) leaves the constant undefined — referencing it
		// bare is then an "Undefined constant" Error, i.e. a fatal inside the
		// Regenerate handler. Mirrors the guard Helper.php:1542 already uses.
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

		$written = $wp_filesystem->put_contents( $tmp, $content, $mode );

		if ( ! $written ) {
			$wp_filesystem->delete( $tmp );
			$this->report_write_failure( $path, 'put_contents' );

			return false;
		}

		// $wp_filesystem->move($source, $destination, $overwrite=true). On the
		// Direct backend this is a native rename() — atomic on POSIX same-fs.
		// On FTPext/SSH2 it's a backend-protocol rename — stays within the
		// FTP/SSH UID that wrote the temp, so it actually succeeds where the
		// previous native rename() would silently fail on FTP-only hosts.
		if ( ! $wp_filesystem->move( $tmp, $path, true ) ) {
			$wp_filesystem->delete( $tmp );
			$this->report_write_failure( $path, 'move' );

			return false;
		}

		// Clear OPcache for this file so the new content is picked up.
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}

		return true;
	}

	/**
	 * Report a translation file that did not reach disk.
	 *
	 * Mirrors SlugTranslationRepository::report_write_failure(): an action so
	 * an integrator, a bulk job or Site Health can react, plus a log line
	 * gated behind WP_DEBUG so a large regeneration on a broken host cannot
	 * flood a production log with one line per locale per domain.
	 *
	 * @param string $path   Absolute path the write targeted.
	 * @param string $reason One of: no_filesystem, put_contents, move.
	 * @return void
	 */
	private function report_write_failure( string $path, string $reason ): void {
		/**
		 * Fires when a generated .l10n.php file could not be written.
		 *
		 * @hook perflocale/strings/file_write_failed
		 *
		 * @param string $path   Absolute path the write targeted.
		 * @param string $reason Failure reason: 'no_filesystem', 'put_contents' or 'move'.
		 */
		do_action( 'perflocale/strings/file_write_failed', $path, $reason );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic on a write-failure path.
		error_log( 'PerfLocale: failed to write translation file (' . $reason . '): ' . $path );
	}
}
