<?php
/**
 * Generates .l10n.php translation files from the database string translations.
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
 * Writes WordPress 6.5+ .l10n.php files per domain and locale, plus one combined bundle per locale pre-keyed for
 * TranslationFileLoader, under uploads/perflocale/translations/. Docs: docs-ai/11-code-rationale.md,
 * "TranslationFileGenerator: generation order".
 */
final class TranslationFileGenerator {

	/**
	 * Autoloaded per-blog option listing the .l10n.php basenames on disk after the last generation, so the loader skips
	 * a glob; this class is the directory's only writer.
	 *
	 * @var string
	 */
	public const MANIFEST_OPTION = 'perflocale_l10n_manifest';

	/**
	 * Combined bundle format version the loader requires: bump it on any change to TranslationFileLoader::map_key() or
	 * the bundle's shape, or an older bundle is served with keys that never match.
	 *
	 * @var int
	 */
	public const COMBINED_VERSION = 1;

	/**
	 * Filename prefix of the combined bundle; shared so the loader builds the name from its own suffix without another
	 * sanitize_file_name() call.
	 *
	 * @var string
	 */
	public const COMBINED_PREFIX = '_combined';

	/**
	 * Missing links written per statement by repair_orphaned_translations().
	 *
	 * @var int
	 */
	private const RELINK_BATCH = 200;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Locales whose source translations could not be read in the last generate_all(); their files were kept.
	 *
	 * @var string[]
	 */
	private array $read_failed_locales = [];

	/**
	 * Translation rows the last generate_all()'s orphan repair removed or linked again.
	 *
	 * @var int
	 */
	private int $repaired_count = 0;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Generate the .l10n.php files of every domain and active language, after repairing orphaned translations so the
	 * fetch's JOIN reaches them.
	 *
	 * @param int[]|null $only_language_ids Regenerate only these languages (fetch, repair and manifest scoped to them;
	 *                                      other files untouched), as a single-string save does; null: full run.
	 * @return int Number of per-domain files that were actually written to
	 *             disk on this run. Excludes files skipped because their
	 *             content was already current, and excludes writes that
	 *             failed - see write_l10n_file().
	 */
	public function generate_all( ?array $only_language_ids = null ): int {
		$this->read_failed_locales = [];
		$this->repaired_count      = 0;

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

		// The default language serves source strings and the loader never loads its bundle: no files (step 3 removes old ones).
		$languages = array_values(
			array_filter(
				(array) $languages,
				static fn( $lang ): bool => is_object( $lang ) && empty( $lang->is_default )
			)
		);

		if ( empty( $languages ) ) {
			return 0;
		}

		// Repair orphans first, on every run (idempotent and cheap), so the JOIN below sees them.
		$repair               = $this->repair_orphaned_translations();
		$this->repaired_count = $repair['dangling'] + $repair['repaired_rows'];

		// Fetch all translated strings grouped by domain and language.
		$failed_language_ids = [];
		$all_translations    = $this->fetch_all_translations( $languages, $failed_language_ids );

		// A language whose read failed has an unknown state, not an empty one: it leaves this run, its files and manifest
		// entries stay, and the failure is reported.
		if ( $failed_language_ids !== [] ) {
			$healthy = [];

			foreach ( $languages as $language ) {
				if ( in_array( (int) $language->id, $failed_language_ids, true ) ) {
					$failed_locale               = (string) $language->locale;
					$this->read_failed_locales[] = $failed_locale;
					unset( $all_translations[ $failed_locale ] );
					$this->report_read_failure( $failed_locale );
					continue;
				}

				$healthy[] = $language;
			}

			$languages = $healthy;
		}

		// Step 1: the target state (path => content) without touching disk; empty sets make no file (step 3 removes it).
		// Each locale also gets one combined bundle in map_key() form, kept apart because a stale bundle shadows the files.
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
					// Keys are `context\x04original`; a numeric original is an int array key, so stringify as the loader reads it.
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

		// A changed or obsolete combined bundle is deleted before any per-domain change and the new one written last;
		// an unchanged one stays.
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

		// Step 2: write only changed files (md5 compare), and count only writes that landed: the count is what the
		// Regenerate screen shows.
		$count = 0;

		foreach ( $target as $path => $content ) {
			if ( is_file( $path ) && md5_file( $path ) === md5( $content ) ) {
				continue;
			}

			if ( $this->write_l10n_file( $path, $content ) ) {
				++$count;
			}
		}

		// Step 3: delete files outside the target state, after the writes so no request sees an empty directory. A scoped run
		// (subset, failed reads) keeps other languages' files.
		$subset_suffixes = null;

		if ( $only_language_ids !== null || $this->read_failed_locales !== [] ) {
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
					$kept[] = $existing; // Another language's file.
					continue;
				}
			}

			if ( ! isset( $target[ $existing ] ) && ! isset( $combined_target[ $existing ] ) ) {
				wp_delete_file( $existing );

				// And its OPcache entry.
				if ( function_exists( 'opcache_invalidate' ) ) {
					opcache_invalidate( $existing, true );
				}
			}
		}

		// Combined bundles last, once the directory is settled; not counted (an internal cache tier).
		foreach ( $combined_pending as $cpath => $content ) {
			$this->write_l10n_file( $cpath, $content );
		}

		// Step 4: the manifest, filtered through is_file() (an intended path is not a written one) after clearstatcache()
		// (WP_Filesystem::move() leaves the stat cache stale). A bundle not listed is never trusted.
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
	 * Locales whose source translations could not be read in the last generate_all(); their files were kept.
	 *
	 * @return string[]
	 */
	public function get_read_failed_locales(): array {
		return $this->read_failed_locales;
	}

	/**
	 * Translation rows the last generate_all() repaired (dangling rows deleted plus rows linked again): what
	 * count_repairable_orphans() stops counting. 0 when the run returned before the repair.
	 *
	 * @return int
	 */
	public function get_repaired_count(): int {
		return $this->repaired_count;
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
	 * The .l10n.php content of a translation set; pure, so generate_all() can compare it with the file first.
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
	 * Basename of a locale's combined bundle. Same `-<locale>.l10n.php` suffix as the domain files on purpose, so every
	 * locale-wide cleanup glob removes it too; the `_` prefix is reserved (internal domains are skipped).
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
	 * The combined bundle's content: 'v' + 'map', no 'messages', so the loader's per-domain loop skips one it does not
	 * trust instead of reading it as a domain.
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
	 * Remove every generated file, including `*.l10n.php.<hex>.tmp` left by a process killed between write and move
	 * (switching back to database mode is the "remove everything" action).
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
	 * Create the translations directory with its protection files (.htaccess, index.php, web.config), also in the
	 * parent `uploads/perflocale/` that wp_mkdir_p() creates bare.
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

		// The parent too: with autoindex on, its listing would show `translations/`.
		$parent = dirname( $dir );
		if ( $parent !== '' && $parent !== '.' && $parent !== '/' ) {
			\PerfLocale\Helper::harden_directory( $parent );
		}
	}

	/**
	 * Repair translation rows the generator cannot reach (idempotent): delete rows whose string is gone, give strings
	 * without a string group a new one, insert missing links of translated languages.
	 *
	 * @return array{dangling: int, regrouped: int, relinked: int, repaired_rows: int} Repair counts: `relinked`
	 *         counts inserted links, `repaired_rows` the translation rows those links made reachable.
	 */
	public function repair_orphaned_translations(): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$strings_table = Schema::table( 'strings' );
		$groups_table  = Schema::table( 'translation_groups' );
		$links_table   = Schema::table( 'translation_links' );
		$st_table      = Schema::table( 'string_translations' );

		$out = [
			'dangling'      => 0,
			'regrouped'     => 0,
			'relinked'      => 0,
			'repaired_rows' => 0,
		];

		// (1) Rows whose string is gone. One table per DELETE: the SQLite integration's 2.2.x legacy driver mishandles a JOIN.
		$out['dangling'] = (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE string_id NOT IN ( SELECT id FROM %i )',
				$st_table,
				$strings_table
			)
		);

		// (2) Translated strings without a usable group: a new string group each.
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

		// (3) Missing links (object_id = string id). translation_links has TWO unique keys: the JOIN tests group_lang only,
		// and a row under another group would collide on object_lang. Docs: docs-ai/11-code-rationale.md,
		// "TranslationFileGenerator: orphan repair and the two link keys".
		if ( ! $wpdb instanceof \wpdb ) {
			return $out;
		}

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

		// Several rows can share one (group, language) slot: one link reaches them all, so the slot is credited once.
		$rows     = [];
		$per_slot = [];
		foreach ( (array) $missing as $row ) {
			if ( ! isset( $row->string_id, $row->group_id, $row->language_id ) || ! is_numeric( $row->string_id ) || ! is_numeric( $row->group_id ) || ! is_numeric( $row->language_id ) ) {
				continue;
			}
			$rows[]            = [ (int) $row->string_id, (int) $row->group_id, (int) $row->language_id ];
			$slot              = (int) $row->group_id . ':' . (int) $row->language_id;
			$per_slot[ $slot ] = ( $per_slot[ $slot ] ?? 0 ) + 1;
		}
		$linked = [];

		// One INSERT IGNORE per batch, in read order: a row is skipped when its slot is linked (group_lang) or its string
		// has a link in that language under another group (object_lang).
		foreach ( array_chunk( $rows, self::RELINK_BATCH ) as $batch ) {
			$values = [];
			$slots  = [];
			$groups = [];
			foreach ( $batch as [ $string_id, $group_id, $language_id ] ) {
				$slot = $group_id . ':' . $language_id;
				if ( isset( $linked[ $slot ] ) ) {
					continue;
				}
				// `type` is the group's type: this repair links string groups only.
				$value = $wpdb->prepare( '(%d, %d, %d, %s, %s, %s)', $group_id, $string_id, $language_id, 'string', 'published', 'manual' );
				if ( ! is_string( $value ) ) {
					continue;
				}
				$values[]            = $value;
				$slots[ $slot ]      = true;
				$groups[ $group_id ] = true;
			}
			if ( $values === [] ) {
				continue;
			}
			$inserted = (int) $wpdb->query(
				$wpdb->prepare( 'INSERT IGNORE INTO %i (group_id, object_id, language_id, type, status, source) VALUES ', $links_table )
				. implode( ', ', $values )
			);
			if ( $inserted <= 0 ) {
				continue;
			}
			// A slot takes one row: when every slot took one the count says which; else the linked slots are read back.
			$done = array_keys( $slots );
			if ( $inserted < count( $slots ) ) {
				$done  = [];
				$found = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d placeholder per group of the batch.
					$wpdb->prepare(
						'SELECT group_id, language_id FROM %i WHERE type = %s AND group_id IN (' . implode( ',', array_fill( 0, count( $groups ), '%d' ) ) . ')',
						$links_table,
						'string',
						...array_keys( $groups )
					)
				);
				foreach ( (array) $found as $link ) {
					if ( ! isset( $link->group_id, $link->language_id ) || ! is_numeric( $link->group_id ) || ! is_numeric( $link->language_id ) ) {
						continue;
					}
					$slot = (int) $link->group_id . ':' . (int) $link->language_id;
					if ( isset( $slots[ $slot ] ) ) {
						$done[] = $slot;
					}
				}
			}
			foreach ( $done as $slot ) {
				++$out['relinked'];
				$out['repaired_rows'] += $per_slot[ $slot ];
				$linked[ $slot ]       = true;
			}
		}
		// phpcs:enable

		// No cache flush: fetch_all_translations() reads the tables directly.

		return $out;
	}

	/**
	 * Rows the generator cannot compile (the three shapes repair_orphaned_translations() handles), for the regression
	 * suites; the Regenerate notice uses get_repaired_count().
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
	 * Every string translation by locale and domain; a language whose reads fail is left out and its id added to
	 * $failed_language_ids, so a database error never reads as no translations.
	 *
	 * @param array<int, object> $languages           Active languages.
	 * @param int[]              $failed_language_ids Receives the ids of languages whose reads failed.
	 * @return array<string, array<string, array<string, string>>> [locale => [domain => [original => translation]]].
	 */
	private function fetch_all_translations( array $languages, array &$failed_language_ids ): array {
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

			// The language's translated strings.
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

			if ( ! is_array( $rows ) || $this->last_read_failed() ) {
				$failed_language_ids[] = $language_id;
				continue;
			}

			if ( $rows === [] ) {
				continue;
			}

			// One query on the (string_id, language_id) primary key (StringTranslationRepository::get_map()).
			$string_ids = array_map( static fn( $row ): int => (int) $row->string_id, $rows );
			$tr_map     = $translations_repo->get_map( $string_ids, $language_id );

			if ( $this->last_read_failed() ) {
				$failed_language_ids[] = $language_id;
				continue;
			}

			// Plural forms 2..N (Polish, Russian, Arabic), usually none.
			$extra_map = $translations_repo->get_extra_forms_map( $string_ids, $language_id );

			if ( $this->last_read_failed() ) {
				$failed_language_ids[] = $language_id;
				continue;
			}

			// By domain.
			foreach ( $rows as $row ) {
				$value = $tr_map[ (int) $row->string_id ] ?? '';

				if ( $value === '' ) {
					continue;
				}

				// Internal output-buffer domains (leading "_", such as "_pfl_dyn") never go through gettext: no file for them.
				if ( isset( $row->domain[0] ) && $row->domain[0] === '_' ) {
					continue;
				}

				$domain = $row->domain ?: 'default';

				// A plural row with extra forms is stored NUL-joined (form1\0form2...), which the loader splits per CLDR form.
				$extra = $extra_map[ (int) $row->string_id ] ?? null;

				if ( is_array( $extra ) && $extra !== [] ) {
					$value = $value . "\0" . implode( "\0", $extra );
				}

				// `context\x04original` for a string with context.
				$key = ! empty( $row->context ) ? $row->context . "\x04" . $row->original : $row->original;

				$result[ $locale ][ $domain ][ $key ] = $value;
			}
		}

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result;
	}

	/**
	 * Whether the read that just ran failed: wpdb clears last_error at the start of every query and returns an empty
	 * result on an error.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private function last_read_failed(): bool {
		global $wpdb;

		return $wpdb instanceof \wpdb && $wpdb->last_error !== '';
	}

	/**
	 * Write a computed .l10n.php file. A failure is survivable (source strings are served) but reported, so the
	 * Regenerate count never claims files that did not reach disk.
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

		// A random sibling then a move, so a concurrent include never reads a half-written file; both through
		// $wp_filesystem so FTP and SSH backends write and move as the same user. Docs: docs-ai/11-code-rationale.md,
		// "TranslationFileGenerator: atomic file writes".
		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// FS_CHMOD_FILE is defined only by WP_Filesystem(); a plugin that builds $wp_filesystem itself leaves it undefined.
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

		$written = $wp_filesystem->put_contents( $tmp, $content, $mode );

		if ( ! $written ) {
			$wp_filesystem->delete( $tmp );
			$this->report_write_failure( $path, 'put_contents' );

			return false;
		}

		// Direct: a native rename(), atomic on one filesystem; FTP and SSH: the backend's rename as the writing user.
		if ( ! $wp_filesystem->move( $tmp, $path, true ) ) {
			$wp_filesystem->delete( $tmp );
			$this->report_write_failure( $path, 'move' );

			return false;
		}

		// Drop the OPcache copy so the new content is read.
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}

		return true;
	}

	/**
	 * Report a file that did not reach disk: an action (integrators, bulk jobs, Site Health) and, under WP_DEBUG only,
	 * a log line (as SlugTranslationRepository::report_write_failure()).
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
		 * @param string $reason Failure reason: 'no_filesystem', 'put_contents' or 'move'. 'source_read_failed'
		 *                       when the language's translations could not be read from the database; $path is
		 *                       then the language's combined bundle, and its existing files were kept.
		 */
		do_action( 'perflocale/strings/file_write_failed', $path, $reason );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic on a write-failure path.
		error_log( 'PerfLocale: failed to write translation file (' . $reason . '): ' . $path );
	}

	/**
	 * Report a language whose source translations could not be read: the write-failure action with its combined bundle
	 * path and `source_read_failed`, and a WP_DEBUG log line. Its files stay.
	 *
	 * @param string $locale Locale whose read failed.
	 * @return void
	 */
	private function report_read_failure( string $locale ): void {
		/** This action is documented in src/Strings/TranslationFileGenerator.php */
		do_action( 'perflocale/strings/file_write_failed', $this->compute_combined_path( $locale ), 'source_read_failed' );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic on a read-failure path.
		error_log( 'PerfLocale: could not read string translations for ' . $locale . '; its translation files were kept.' );
	}
}
