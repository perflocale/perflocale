<?php
/**
 * Data importer - imports plugin data from JSON export file.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports PerfLocale data from a JSON export file.
 *
 * Validates the file structure, then imports each table in batches
 * with optional replace-all or merge mode.
 *
 * ## Merge-mode limitation for translation_groups (v1)
 *
 * `translation_groups` carries no natural key — its only identifier is the
 * auto-increment `id`, used as an FK target by `translation_links`. In
 * **replace mode** the importer truncates the table first and preserves
 * the export's ids, so re-importing the same export is a no-op.
 *
 * In **merge mode** each group re-imports as a NEW row (auto-increment
 * issues a fresh id), and the matching `translation_links` skip on the
 * `(object_id, language_id)` UNIQUE constraint — so the new groups never
 * get linked to anything. The transient orphan rows are cleaned up by
 * {@see self::sweep_orphan_groups()} which runs once at the end of every
 * merge-mode import, but during the import window an operator who
 * inspects `wp_perflocale_translation_groups` directly will see
 * duplicates of the rows they just re-imported.
 *
 * The full architectural fix (a `(source_site, source_group_id)` natural
 * key persisted across re-imports, OR a content-fingerprint computed from
 * each group's link tuples) is deferred to a post-1.0 release because:
 *
 *   - The transient duplicates are swept automatically — no permanent
 *     data corruption, just brief auto-increment-id waste during the
 *     import.
 *   - Operators concerned with deterministic re-import results should
 *     use **replace mode** (the default for full-site backups).
 *   - The migration importers (WPML / Polylang / TranslatePress) already
 *     get this guarantee via `perflocale_migration_source_map`; the
 *     export/import path is the only operator-facing gap.
 *
 * Documented as a known v1 constraint in the merge-mode admin-UI
 * tooltip and in readme.txt.
 */
final class DataImporter {

	/**
	 * Rows per insert batch.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Foreign-key relationships across import tables.
	 *
	 * In merge mode, parent rows get a fresh auto-increment ID rather than
	 * reusing the export's. Child rows still carry the export-time parent
	 * IDs, so without rewriting we'd insert dangling refs (or run into
	 * composite-PK collisions when a different parent already owned that
	 * id on the target site — exactly the symptom that surfaced when we
	 * tried importing into a site with overlapping IDs).
	 *
	 * This map says "in table X, column Y references table Z's id". The
	 * importer rewrites Y at insert time using the parent id-map captured
	 * during the parent table's pass.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const FK_REFS = [
		'translation_links'   => [
			'group_id'    => 'translation_groups',
			'language_id' => 'languages',
		],
		// strings.group_id references the per-string type='string' group.
		// Without this rewrite, a merge import leaves each imported string
		// pointing at its OLD group id (translation_groups re-insert with
		// fresh ids), so the group is widowed and sweep_orphan_groups deletes
		// it — dropping the string's language links with it. translation_groups
		// precedes strings in TABLES, so its id map is ready by then.
		'strings'             => [ 'group_id' => 'translation_groups' ],
		'string_translations' => [
			'string_id'   => 'strings',
			'language_id' => 'languages',
		],
		'slug_translations'   => [ 'language_id' => 'languages' ],
	];

	/**
	 * Per-import old_id → new_id maps, keyed by parent table short-name.
	 *
	 * Populated as parent tables are imported and consumed when child
	 * tables get their FK columns rewritten. Reset at the start of each
	 * import() call so independent imports don't cross-contaminate.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $id_maps = [];

	/**
	 * Tables to import in order (dependencies first).
	 *
	 * `string_translations` MUST come after `strings` (its string_id FK target)
	 * and after `languages` (its language_id FK target). The current order
	 * satisfies both.
	 */
	private const TABLES = [
		'languages',
		'translation_groups',
		'translation_links',
		'strings',
		'string_translations',
		'slug_translations',
		'content_hashes',
	];

	/**
	 * Import data from a JSON file.
	 *
	 * @param string $file_path Path to the JSON file.
	 * @param bool   $replace Whether to replace all existing data (true) or merge (false).
	 * @return array{imported: int, skipped: int, errors: array<int, string>}
	 */
	public function import( string $file_path, bool $replace = false ): array {
		$result = [
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => [],
		];

		// Reset cross-table id maps so independent import() calls don't
		// cross-contaminate each other (e.g. CLI script doing two imports
		// in a row).
		$this->id_maps = [];

		// Read and validate.
		$fs = \PerfLocale\Helper::filesystem();

		if ( ! $fs ) {
			$result['errors'][] = __( 'Could not initialize the WordPress filesystem.', 'perflocale' );
			return $result;
		}

		$json = $fs->get_contents( $file_path );

		if ( $json === false ) {
			$result['errors'][] = __( 'Could not read the uploaded file.', 'perflocale' );
			return $result;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			$result['errors'][] = __( 'Invalid JSON format.', 'perflocale' );
			return $result;
		}

		// Validate structure.
		$validation = $this->validate( $data );

		if ( ! empty( $validation ) ) {
			$result['errors'] = $validation;
			return $result;
		}

		// Data-quality gate: invalid UTF-8, null bytes, cardinality bombs,
		// oversize values, malformed rows. Fail closed BEFORE any write —
		// a partially-imported corrupt file is worse than a rejected one.
		/**
		 * Filter the data-quality limits applied to imports.
		 *
		 * @hook perflocale/import/quality_limits
		 *
		 * @param array{max_languages?: int, max_rows_per_table?: int, max_value_bytes?: int} $limits Defaults: 500 / 500000 / 1048576.
		 */
		$quality = self::find_data_quality_issues( $data, (array) apply_filters( 'perflocale/import/quality_limits', [] ) );

		if ( ! empty( $quality ) ) {
			$result['errors'] = $quality;
			return $result;
		}

		// Import settings - route through the Settings class so values are
		// validated against the DEFAULTS whitelist and sanitized per type.
		// Use the DI singleton (not `new Settings()`) so the in-process
		// cache held by every other component is updated in-place. A
		// fresh instance would write wp_options correctly but leave the
		// existing singleton's `$this->settings` array stale, so any code
		// later in the same request would still see the pre-import values.
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

			// Drop keys this build no longer knows BEFORE handing the array to
			// update(). An export written by an older version legitimately
			// carries settings for features that have since been removed;
			// Settings::sanitize() treats an unknown key as a programming
			// error and fires _doing_it_wrong() for each one, which would spam
			// a debug-enabled site on an ordinary "restore my backup" action.
			// The values are dropped either way — this only stops a supported
			// user flow from being reported as developer error.
			$known    = array_keys( $settings->get_defaults() );
			$incoming = array_intersect_key( $data['settings'], array_flip( $known ) );

			if ( $incoming !== [] ) {
				$settings->update( $incoming );
			}

			++$result['imported'];
		}

		// Per-addon settings. Restore as-is — the destination site may
		// not have every addon installed, but that's fine: an absent
		// addon's entry sits dormant in the option until the addon is
		// installed (at which point its own AddonSettings reads pick the
		// value up). Whole-option write so a stale prior import doesn't
		// bleed through. Cache is cleared right after so AddonSettings::all()
		// picks up the fresh option on next read.
		if ( isset( $data['addon_settings'] ) && is_array( $data['addon_settings'] ) ) {
			$incoming = $data['addon_settings'];
			$current  = (array) get_option( 'perflocale_addon_settings', [] );

			// The exporter REDACTS credential-shaped keys (see
			// DataExporter::CREDENTIAL_KEY_PATTERN), so an imported addon entry
			// never carries its *_api_key / *_token / etc. Writing it verbatim
			// would blank the target's live credentials — turning a settings
			// restore into a "now re-enter every API key" incident. Preserve any
			// credential key the target still has when the import omits it. In
			// merge mode also keep addons the export didn't mention at all.
			$merged = $current;

			foreach ( $incoming as $addon => $entry ) {
				if ( ! is_array( $entry ) ) {
					$merged[ $addon ] = $entry;
					continue;
				}

				$existing_entry = ( isset( $current[ $addon ] ) && is_array( $current[ $addon ] ) ) ? $current[ $addon ] : [];

				// In merge mode the imported entry layers over the existing one;
				// in replace mode the imported entry is authoritative except for
				// the redacted credential keys restored below.
				$base = $replace ? $entry : array_merge( $existing_entry, $entry );

				foreach ( $existing_entry as $key => $value ) {
					if ( ! array_key_exists( $key, $entry ) && preg_match( DataExporter::CREDENTIAL_KEY_PATTERN, (string) $key ) ) {
						$base[ $key ] = $value;
					}
				}

				$merged[ $addon ] = $base;
			}

			// Replace mode is authoritative about WHICH addons exist: drop any
			// the export didn't mention. Merge mode keeps target-only addons.
			if ( $replace ) {
				$merged = array_intersect_key( $merged, $incoming );
			}

			update_option( 'perflocale_addon_settings', $merged, false );
			\PerfLocale\Addon\AddonSettings::reset_static_caches();
			++$result['imported'];
		}

		// Operator's per-addon enable/disable list. Whole-list write so the
		// target site's intent matches what was exported (an empty list
		// means "nothing disabled" and must be preserved literally, not
		// merged with the target's existing disabled set). Routed through
		// the registry so it gets the same id-validation, byte cap,
		// write-lock, and bootable-cache flush as the admin/CLI toggles.
		if ( isset( $data['disabled_addons'] ) && is_array( $data['disabled_addons'] ) ) {
			\PerfLocale\Addon\AddonRegistry::set_disabled_list( $data['disabled_addons'] );
			++$result['imported'];
		}

		// Import roles + capabilities snapshot. Restoring this BEFORE table
		// data ensures any current_user_can() checks fired during import (very
		// rare, but cache flushes and admin hooks can trigger them) see the
		// imported permission shape, not the local one.
		if ( isset( $data['roles'] ) && is_array( $data['roles'] ) ) {
			$this->restore_roles( $data['roles'] );
			++$result['imported'];
		}

		// Import tables.
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			// Replace mode wipes the tables that BELONG to the sections in
			// the export, regardless of whether each table happens to have
			// rows in $data['data']. Otherwise a table that was empty at
			// export time would leak post-export rows through the replace.
			//
			// CRITICAL: we restrict the wipe to tables for the EXPORTED
			// sections only. A user importing a strings-only export with
			// `replace` expects "replace the strings"; truncating every
			// PerfLocale table would silently destroy languages, links,
			// etc., across the entire plugin.
			global $wpdb;

			// The replace-mode wipe plan: table short-name => type scope
			// (null means the whole table). Resolved once because it is
			// needed twice — the loop below applies it, and the abort gate
			// inside the import loop has to know which tables were only
			// PARTIALLY cleared.
			$wipe_plan = $replace ? self::tables_to_wipe( $data ) : [];

			if ( $replace ) {
				// Atomic replace: the wipe AND the re-import run inside ONE
				// transaction so a mid-restore failure rolls back instead of
				// leaving the tables emptied or half-populated. DELETE (not
				// TRUNCATE) because TRUNCATE is DDL and implicitly commits — it
				// would silently end the transaction and re-open the data-loss
				// window. IDs are preserved either way (replace keeps ids), so
				// not resetting AUTO_INCREMENT is correct.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'START TRANSACTION' );

				$was_suppressing = $wpdb->suppress_errors( true );

				foreach ( $wipe_plan as $table_name => $type_scope ) {
					// $full_table is Schema::table() mapped from a class-
					// constant allow-list, never user input; sanitized to a
					// bare identifier before interpolation since prepare()
					// cannot bind a table name.
					$full_table = Schema::sanitize_table( Schema::table( $table_name ) );

					if ( $type_scope !== null ) {
						// Shared polymorphic table: clear ONLY the slice the
						// exported sections own, so "replace my strings"
						// leaves every post and term group standing. The type
						// is bound as a value, so the scope can never widen.
						//
						// Links pointing at the groups this drops live in
						// a table the bundle does NOT own, so they are
						// left dangling here and reaped after the commit
						// — see reap_orphan_string_links().
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier (not a value): sanitized above; prepare() cannot bind identifiers.
						$wpdb->query(
							$wpdb->prepare(
								'DELETE FROM %i WHERE type = %s',
								$full_table,
								$type_scope
							)
						);
						continue;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier (not a value): sanitized above; prepare() cannot bind identifiers.
					$wpdb->query(
						$wpdb->prepare(
							'DELETE FROM %i',
							$full_table
						)
					);
				}

				$wpdb->suppress_errors( $was_suppressing );
			}

			try {
				foreach ( self::TABLES as $table_name ) {
					if ( ! isset( $data['data'][ $table_name ] ) || ! is_array( $data['data'][ $table_name ] ) ) {
						continue;
					}

					$rows = $data['data'][ $table_name ];

					if ( empty( $rows ) ) {
						continue;
					}

					// $replace semantics:
					// wipe → handled above (covers empty-in-export tables)
					// keep ids → needed so translation_links FK-ish pointers
					// into translation_groups stay valid
					// Signal both via the second arg; import_table still treats
					// $replace==true as "preserve IDs" but now NO-OPs the wipe
					// (we already did it).
					$table_result        = $this->import_table( $table_name, $rows, $replace );
					$result['imported'] += $table_result['imported'];
					$result['skipped']  += $table_result['skipped'];

					if ( ! empty( $table_result['errors'] ) ) {
						foreach ( $table_result['errors'] as $err ) {
							$result['errors'][] = $table_name . ': ' . $err;
						}
					}

					// Replace mode already DELETEd this table inside the
					// transaction. If a non-empty export inserted zero rows,
					// something systematic failed (unknown column, charset,
					// strict-mode) — committing now would leave the table
					// permanently empty. Abort so the catch below ROLLBACKs
					// the wipe and the operator keeps their pre-import data.
					//
					// A table whose wipe was SCOPED is the one exception:
					// only its own slice was cleared, so the rows that
					// survived can legitimately collide with the incoming
					// ids on the primary key. `translation_groups` shares
					// one id-space across post, term and string groups, so
					// a strings-only bundle whose group ids all happen to
					// exist on the target as post groups inserts nothing
					// while nothing is actually wrong: every row
					// duplicate-SKIPS, and repair_orphaned_translations()
					// below re-groups the strings that lost their carried
					// group. Aborting there would roll back an ordinary
					// partial import. So for a scoped table demand a REAL
					// insert failure — import_table() counts those
					// separately from duplicate-entry skips. Unscoped tables
					// keep the original guard: every one of their rows is
					// gone, so zero inserts is always fatal.
					$scoped_wipe = ( ( $wipe_plan[ $table_name ] ?? null ) !== null );

					if (
						$replace
						&& $table_result['imported'] === 0
						&& count( $rows ) > 0
						&& ( ! $scoped_wipe || $table_result['failed'] > 0 )
					) {
						throw new \RuntimeException(
							sprintf(
								/* translators: 1: table name, 2: row count */
								'Replace import aborted: table "%1$s" inserted 0 of %2$d rows. %3$s',
								$table_name,
								count( $rows ),
								! empty( $table_result['errors'] ) ? (string) $table_result['errors'][0] : ''
							)
						);
					}
				}

				if ( $replace ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'COMMIT' );
				}
			} catch ( \Throwable $e ) {
				if ( $replace ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'ROLLBACK' );
				}
				throw $e;
			}

			// Merge mode strands groups: translation_groups have no natural
			// key, so each re-inserts as a NEW row while its links skip on the
			// (object_id, language_id) unique key - leaving the fresh group
			// with nothing pointing at it. (Replace mode truncates first, so it
			// never strands.) Run ONCE here, after the whole loop - especially
			// after translation_links - so we never sweep freshly-imported
			// groups before their links have landed.
			if ( ! $replace ) {
				$this->sweep_orphan_groups();
			}
		}

		// Per-section import hook for addon-shipped data. Fires once for
		// every top-level key in the envelope that is NOT a core section,
		// so an addon that registered a `perflocale/export/sections` filter
		// can hook the matching action to restore its own rows. Uses the same
		// reserved-key list the exporter enforces so the two can't drift
		// (addon_settings + disabled_addons included — they are handled above
		// and must never be dispatched as an addon section).
		foreach ( $data as $section_name => $section_data ) {
			if ( ! is_string( $section_name ) || $section_name === '' || in_array( $section_name, DataExporter::RESERVED_SECTION_KEYS, true ) ) {
				continue;
			}

			/**
			 * Restore an addon-shipped envelope section during a data import.
			 *
			 * Fires once for each top-level key in the export envelope that
			 * isn't a core section (settings/roles/data/etc.). Addons that
			 * register a `perflocale/export/sections` filter to ship their
			 * data should hook the matching `perflocale/import/section/<name>`
			 * action to restore it.
			 *
			 * The action name is dynamic — `<name>` is the section key the
			 * addon used in the export. Use the SAME key on both sides so
			 * round-tripping works automatically.
			 *
			 * @hook  perflocale/import/section/<name>
			 * @since 1.0.0
			 *
			 * @param mixed $section_data The decoded JSON payload the addon
			 *                            wrote to this section. Same shape
			 *                            the export filter returned.
			 * @param array{
			 *     replace: bool,
			 *     format_version: int,
			 *     file_path: string,
			 * } $context The import context: whether `replace` mode is on,
			 *            the envelope's format version, and the path to the
			 *            uploaded file.
			 */
			do_action(
				'perflocale/import/section/' . $section_name,
				$section_data,
				[
					'replace'        => $replace,
					'format_version' => isset( $data['format_version'] ) ? (int) $data['format_version'] : 0,
					'file_path'      => $file_path,
				]
			);
		}

		// Flush all caches. Routed through the migration helper (which itself
		// calls CacheManager::flush_all) so the autoloaded eager-link maps for
		// ALL object types are purged, not just L2 — a plain flush_all() leaves
		// the pre-import eager maps in wp_options and long-lived CLI/cron
		// workers keep serving stale group memos. Runs here (inside import(),
		// while switch_to_blog() is active for network-import) so the
		// delete_option() calls hit each TARGET blog's options table.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		$carries_strings = self::carries_strings( $data );

		// Reap the links left pointing at groups that no longer exist. Two
		// producers, and the gate has to cover BOTH.
		//
		// REPLACE: a strings bundle wipes the type='string' slice of
		// translation_groups without owning translation_links.
		//
		// MERGE: sweep_orphan_groups() above deletes every type='string'
		// group that owns no `strings` row, and it runs on EVERY merge
		// import whatever sections the envelope carried, because the sweep
		// itself is unconditional. A merge of the `translations` section
		// alone therefore strands string links while carrying no strings at
		// all — the shape the old `$carries_strings`-only gate never looked
		// at, leaving those links to block the UNIQUE object_lang slot the
		// next repair needs.
		//
		// Runs BEFORE the repair below, which cannot re-link a string while a
		// dangling row still holds its slot in that key. A replace-mode
		// import that landed no strings ran no sweep and can have stranded
		// nothing, so it still pays nothing.
		if ( $carries_strings || ! $replace ) {
			$this->reap_orphan_string_links();
		}

		// String-graph repair. Two callers, one idempotent pass:
		//
		// MERGE: when the target site ALREADY had a string row (same
		// original_hash), the imported string maps onto the EXISTING row —
		// which keeps its own group_id — while the imported translation_links
		// rows were rewritten onto the freshly-inserted (now unowned) group.
		// The translation value lands but its 'translated' link points at the
		// wrong group, so it is never served.
		//
		// REPLACE: the strings section carries `translation_groups` but NOT
		// the per-language `translation_links` (see DataExporter::SECTIONS),
		// because a link holds nothing that isn't derivable from the rows that
		// did travel. On a restore into the SAME site the links are still
		// there with their real statuses and this pass finds nothing to do; on
		// a restore into a DIFFERENT site there are none for the imported
		// strings, and this is what rebuilds them. It also catches the
		// residue when a carried group id collided with a post/term group the
		// target already had, which duplicate-skips the group row.
		//
		// repair_orphaned_translations() re-creates the missing
		// (group, language) links from the value rows; it is idempotent and a
		// no-op when nothing is orphaned, so the cost on a healthy full-bundle
		// restore is a scan-and-delete of dangling value rows plus two SELECTs
		// that return nothing. The `$carries_strings` gate applies to REPLACE
		// only — a replace of other sections skips the pass entirely, while
		// merge mode still runs it unconditionally exactly as it did before
		// the strings section started carrying groups.
		if ( ! $replace || $carries_strings ) {
			$cache = \PerfLocale\Plugin::get_instance()->has( 'cache' )
				? \PerfLocale\Plugin::get_instance()->get( 'cache' )
				: null;

			if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
				( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->repair_orphaned_translations();
			}
		}

		/**
		 * Fires after `DataImporter::import()` has finished restoring rows
		 * and flushing caches, with the final result stats.
		 *
		 * Use this to invalidate addon-side caches, emit audit-log entries,
		 * or notify a Slack channel that the
		 * import completed. Fires for BOTH sync and async (data_import job)
		 * code paths.
		 *
		 * @hook  perflocale/import/completed
		 * @since 1.0.0
		 *
		 * @param array{
		 *     imported: int,
		 *     skipped:  int,
		 *     errors:   array<int,string>,
		 * } $result The DataImporter result.
		 * @param string $file_path Absolute path to the imported file.
		 * @param bool   $replace   Whether the import ran in replace mode.
		 */
		do_action( 'perflocale/import/completed', $result, $file_path, $replace );

		return $result;
	}

	/**
	 * Build the table wipe plan a replace-mode import should apply.
	 *
	 * Reads the envelope's `sections` field (an array of section keys
	 * present in the export) and resolves it through DataExporter::SECTIONS
	 * to a `table short-name => type scope` map. A null scope means the whole
	 * table; a string means only the rows whose polymorphic `type` column
	 * holds that value (see DataExporter::SECTIONS' `type_scope`). An absent
	 * or empty `sections` list wipes nothing — that's the safer default for a
	 * malformed envelope than truncating every PerfLocale table.
	 *
	 * Two rules keep a partial bundle from over-reaching:
	 *
	 * - The WIDEST claim wins. A full bundle selects both `translations`
	 *   (which owns all of `translation_groups`) and `strings` (which owns
	 *   only the type='string' slice), so the table is wiped whole — exactly
	 *   what happened before scoping existed.
	 * - A scoped slice is wiped only when the envelope actually CARRIES rows
	 *   for that table. Unscoped tables are wiped even when empty in the
	 *   export, otherwise post-export rows leak through the replace; but a
	 *   scope exists precisely because the table is SHARED, and a bundle
	 *   written before the scope was introduced names the section without
	 *   carrying the slice. Wiping on its behalf would delete the target's
	 *   groups with nothing left to restore them.
	 *
	 * @param array<string,mixed> $data Decoded envelope.
	 * @return array<string, string|null> Table short-name => type scope (null = whole table).
	 */
	private static function tables_to_wipe( array $data ): array {
		$sections = isset( $data['sections'] ) && is_array( $data['sections'] )
			? array_filter( array_map( 'strval', $data['sections'] ) )
			: [];

		if ( empty( $sections ) ) {
			return [];
		}

		$carried = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : [];
		$scopes  = [];

		foreach ( $sections as $section_key ) {
			if ( ! isset( DataExporter::SECTIONS[ $section_key ] ) ) {
				continue;
			}
			foreach ( DataExporter::SECTIONS[ $section_key ]['tables'] as $tbl ) {
				$tbl   = (string) $tbl;
				$scope = DataExporter::SECTIONS[ $section_key ]['type_scope'][ $tbl ] ?? null;

				if ( $scope !== null && empty( $carried[ $tbl ] ) ) {
					continue;
				}

				if ( $scope === null || ! array_key_exists( $tbl, $scopes ) ) {
					$scopes[ $tbl ] = $scope;
				}
			}
		}

		// Walking TABLES keeps the canonical order so dependency order
		// (parents before children) is preserved even if `sections` arrived
		// in a weird order.
		$plan = [];
		foreach ( self::TABLES as $table_name ) {
			if ( array_key_exists( $table_name, $scopes ) ) {
				$plan[ $table_name ] = $scopes[ $table_name ];
			}
		}

		return $plan;
	}

	/**
	 * Does this envelope carry `strings` rows?
	 *
	 * Gates the REPLACE half of both post-import string passes — the
	 * orphan-link reap and the string-graph repair — so a replace bundle
	 * that landed no strings doesn't pay for work that has nothing to fix.
	 * Merge mode runs both unconditionally: its sweep_orphan_groups() call
	 * is itself unconditional, so any merge import can strand string links
	 * whether or not the envelope carried strings.
	 *
	 * @param array<string,mixed> $data Decoded envelope.
	 * @return bool True when the envelope's `data` holds at least one strings row.
	 */
	private static function carries_strings( array $data ): bool {
		$carried = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : [];

		return isset( $carried['strings'] )
			&& is_array( $carried['strings'] )
			&& $carried['strings'] !== [];
	}

	/**
	 * Validate the export data structure.
	 *
	 * @param array<string, mixed> $data Decoded JSON data.
	 * @return array<int, string> Validation errors (empty = valid).
	 */
	private function validate( array $data ): array {
		$errors = [];

		if ( empty( $data['perflocale_export'] ) ) {
			$errors[] = __( 'This file is not a PerfLocale export.', 'perflocale' );
		}

		if ( empty( $data['version'] ) ) {
			$errors[] = __( 'Export version is missing.', 'perflocale' );
		}

		// Check version compatibility: block imports from newer major versions
		// (their schema may have tables/columns this version doesn't support).
		if ( ! empty( $data['version'] ) && defined( 'PERFLOCALE_VERSION' ) ) {
			$export_major  = (int) explode( '.', (string) $data['version'] )[0];
			$current_major = (int) explode( '.', PERFLOCALE_VERSION )[0];

			if ( $export_major > $current_major ) {
				$errors[] = sprintf(
					/* translators: 1: export version, 2: current plugin version */
					__( 'This export was created with PerfLocale v%1$s, but you are running v%2$s. Please update the plugin before importing.', 'perflocale' ),
					$data['version'],
					PERFLOCALE_VERSION
				);
			}
		}

		// Envelope format-version gate. Bumped only when the JSON shape
		// changes incompatibly (renamed/removed sections or columns).
		// Newer-than-known is rejected; unknown/older is accepted because
		// we validate every section's shape independently below.
		if ( isset( $data['format_version'] ) ) {
			$incoming = (int) $data['format_version'];

			if ( $incoming > DataExporter::FORMAT_VERSION ) {
				$errors[] = sprintf(
					/* translators: 1: incoming format version, 2: current format version */
					__( 'This export uses format version %1$d, but this build only understands up to %2$d. Update PerfLocale before importing.', 'perflocale' ),
					$incoming,
					DataExporter::FORMAT_VERSION
				);
			}
		}

		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			$errors[] = __( 'Export data section is missing or invalid.', 'perflocale' );
		}

		return $errors;
	}

	/**
	 * Data-quality validation: reject corrupt or hostile payloads BEFORE
	 * any DB write.
	 *
	 * Catches what json_decode() happily accepts but the importer would
	 * persist verbatim: invalid UTF-8 byte sequences (MySQL truncates the
	 * value at the bad byte — silent data loss), null bytes (classic
	 * injection / downstream-corruption vector), cardinality bombs
	 * (500k-language files exhausting memory or wedging the languages
	 * UI), oversized single values, and malformed row shapes.
	 *
	 * Pure function — no DB access, no WP state beyond __() — so it's
	 * directly unit-testable and trivially safe to call on any payload.
	 *
	 * Error reporting stops after MAX_QUALITY_ERRORS so a garbage file
	 * produces a readable report, not a megabyte of notices.
	 *
	 * @param array<string, mixed> $data   Decoded export envelope.
	 * @param array<string, int>   $limits Override limits (used by tests):
	 *                                     max_languages, max_rows_per_table,
	 *                                     max_value_bytes.
	 * @return array<int, string> Quality errors (empty = clean).
	 */
	public static function find_data_quality_issues( array $data, array $limits = [] ): array {
		$max_languages = $limits['max_languages'] ?? 500;
		$max_rows      = $limits['max_rows_per_table'] ?? 500000;
		$max_value     = $limits['max_value_bytes'] ?? 1048576; // 1 MB per scalar value.
		$max_errors    = 20;

		$errors = [];

		$tables = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : [];

		// Cardinality gates first — cheap, and they catch the bomb case
		// before the per-value scan walks half a million rows.
		if ( isset( $tables['languages'] ) && is_array( $tables['languages'] ) && count( $tables['languages'] ) > $max_languages ) {
			$errors[] = sprintf(
				/* translators: 1: language count in the file, 2: maximum allowed. */
				__( 'The export contains %1$s languages; the importer accepts at most %2$s. This usually indicates a corrupt or non-PerfLocale file.', 'perflocale' ),
				number_format_i18n( count( $tables['languages'] ) ),
				number_format_i18n( $max_languages )
			);
		}

		foreach ( $tables as $table => $rows ) {
			if ( ! is_array( $rows ) ) {
				$errors[] = sprintf(
					/* translators: %s: table name. */
					__( 'Table "%s" is not a row list.', 'perflocale' ),
					(string) $table
				);
				continue;
			}

			if ( count( $rows ) > $max_rows ) {
				$errors[] = sprintf(
					/* translators: 1: table name, 2: row count, 3: maximum allowed. */
					__( 'Table "%1$s" contains %2$s rows; the importer accepts at most %3$s per table.', 'perflocale' ),
					(string) $table,
					number_format_i18n( count( $rows ) ),
					number_format_i18n( $max_rows )
				);
			}
		}

		if ( count( $errors ) >= $max_errors ) {
			return $errors;
		}

		// Per-value scan: every string in every section (tables AND the
		// settings / addon_settings sections that import() writes before
		// the table loop) is checked for invalid UTF-8, null bytes, and
		// oversize. Row shape is checked along the way.
		$scan_sections = [ 'data' => $tables ];

		foreach ( [ 'settings', 'addon_settings', 'roles' ] as $section ) {
			if ( isset( $data[ $section ] ) && is_array( $data[ $section ] ) ) {
				$scan_sections[ $section ] = $data[ $section ];
			}
		}

		// Budget-passing recursion: each call returns the errors it found,
		// capped at $budget. Return-based (no by-ref accumulator) so the
		// growth of $errors stays visible to static analysis AND the cap
		// is enforced at every recursion level.
		$scan = static function ( $value, string $path, int $budget ) use ( &$scan, $max_value ): array {
			if ( $budget <= 0 ) {
				return [];
			}

			if ( is_string( $value ) ) {
				if ( strpos( $value, "\0" ) !== false ) {
					return [
						sprintf(
							/* translators: %s: JSON path of the offending value. */
							__( 'Null byte found at %s — the file is corrupt or has been tampered with.', 'perflocale' ),
							$path
						),
					];
				}

				// preg_match with the `u` modifier fails (returns false) on
				// invalid UTF-8 — the fastest validity probe PHP offers
				// without mbstring.
				if ( $value !== '' && preg_match( '//u', $value ) === false ) {
					return [
						sprintf(
							/* translators: %s: JSON path of the offending value. */
							__( 'Invalid UTF-8 at %s — importing it would silently truncate the value in MySQL.', 'perflocale' ),
							$path
						),
					];
				}

				if ( strlen( $value ) > $max_value ) {
					return [
						sprintf(
							/* translators: 1: JSON path of the offending value, 2: size limit. */
							__( 'Value at %1$s exceeds the %2$s single-value limit.', 'perflocale' ),
							$path,
							size_format( $max_value )
						),
					];
				}

				return [];
			}

			$found = [];

			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					$found = array_merge( $found, $scan( $v, $path . '.' . (string) $k, $budget - count( $found ) ) );

					if ( count( $found ) >= $budget ) {
						break;
					}
				}
			}

			return $found;
		};

		foreach ( $scan_sections as $section => $value ) {
			$errors = array_merge( $errors, $scan( $value, (string) $section, $max_errors - count( $errors ) ) );

			if ( count( $errors ) >= $max_errors ) {
				return $errors;
			}
		}

		// Row-shape check on the table rows specifically: each row must
		// itself be an array (column => value). Scalars or nested junk at
		// row position mean a hand-edited or corrupt file. The cap is
		// enforced solely by the inner break-2 — any path reaching
		// $max_errors exits both loops there, so no outer guard is needed.
		foreach ( $tables as $table => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $i => $row ) {
				if ( ! is_array( $row ) ) {
					$errors[] = sprintf(
						/* translators: 1: table name, 2: row index. */
						__( 'Table "%1$s" row %2$d is not a column map — the file is malformed.', 'perflocale' ),
						(string) $table,
						(int) $i
					);

					if ( count( $errors ) >= $max_errors ) {
						break 2;
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Restore the role + capability snapshot.
	 *
	 * Recreates the Translator role with its imported capability map, then
	 * re-applies any PerfLocale-prefixed caps that were granted to other
	 * built-in roles (administrator + editor). Sets `perflocale_caps_version`
	 * to the imported value so `TranslatorRole::ensure_roles_exist()` sees
	 * a matching version on next admin_init and doesn't immediately wipe
	 * what we just restored.
	 *
	 * Foreign caps (anything not prefixed with `perflocale_`) are filtered
	 * out so an export crafted maliciously can't grant arbitrary WP caps.
	 *
	 * @param array<string,mixed> $roles Snapshot from DataExporter::snapshot_roles().
	 * @return void
	 */
	private function restore_roles( array $roles ): void {
		// Translator role: rebuild from imported caps (allow-listed to
		// `perflocale_*` plus the standard editing caps the role needs).
		$translator_caps = isset( $roles['translator'] ) && is_array( $roles['translator'] )
			? $roles['translator']
			: [];

		if ( ! empty( $translator_caps ) ) {
			$safe_caps        = [];
			$base_editor_caps = [
				'read',
				'edit_posts',
				'edit_others_posts',
				'edit_published_posts',
				'edit_pages',
				'edit_others_pages',
				'edit_published_pages',
				'upload_files',
			];
			foreach ( $translator_caps as $cap => $grant ) {
				$cap_str = (string) $cap;
				if ( str_starts_with( $cap_str, 'perflocale_' ) || in_array( $cap_str, $base_editor_caps, true ) ) {
					$safe_caps[ $cap_str ] = (bool) $grant;
				}
			}

			if ( ! empty( $safe_caps ) ) {
				remove_role( TranslatorRole::ROLE_SLUG );
				add_role( TranslatorRole::ROLE_SLUG, __( 'Translator', 'perflocale' ), $safe_caps );
			}
		}

		// Other roles' PerfLocale grants.
		if ( isset( $roles['role_grants'] ) && is_array( $roles['role_grants'] ) ) {
			foreach ( $roles['role_grants'] as $role_slug => $grants ) {
				if ( ! is_array( $grants ) ) {
					continue;
				}

				$role = get_role( sanitize_key( (string) $role_slug ) );

				if ( ! $role instanceof \WP_Role ) {
					continue;
				}

				foreach ( $grants as $cap => $grant ) {
					$cap_str = (string) $cap;
					// Foreign caps filtered defensively - a tampered export
					// could otherwise grant arbitrary WP caps to admin/editor.
					if ( ! str_starts_with( $cap_str, 'perflocale_' ) ) {
						continue;
					}
					$role->add_cap( $cap_str, (bool) $grant );
				}
			}
		}

		if ( isset( $roles['caps_version'] ) ) {
			update_option( 'perflocale_caps_version', (int) $roles['caps_version'], false );
		}
	}

	/**
	 * Map each column of a table to its wpdb placeholder, derived from the SQL
	 * column TYPE (memoised per request). Integer columns => %d, decimal/float
	 * => %f, everything else (varchar/text/etc.) => %s, so all-digit string
	 * content round-trips verbatim instead of being silently int-cast.
	 *
	 * @param string $full_table Prefixed table name (from Schema::table()).
	 * @return array<string, string> column => '%d'|'%f'|'%s'
	 */
	private function column_formats( string $full_table ): array {
		static $cache = [];

		if ( isset( $cache[ $full_table ] ) ) {
			return $cache[ $full_table ];
		}

		global $wpdb;
		$formats = [];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full_table is Schema::table(); schema introspection only.
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i',
				$full_table
			)
		) as $c ) {
			$type  = strtolower( (string) ( $c->Type ?? '' ) );
			$field = (string) ( $c->Field ?? '' );

			if ( preg_match( '/^(?:tiny|small|medium|big)?int\b/', $type ) ) {
				$formats[ $field ] = '%d';
			} elseif ( str_starts_with( $type, 'decimal' ) || str_starts_with( $type, 'float' ) || str_starts_with( $type, 'double' ) ) {
				$formats[ $field ] = '%f';
			} else {
				$formats[ $field ] = '%s';
			}
		}

		$cache[ $full_table ] = $formats;

		return $formats;
	}

	/**
	 * Import a single table's data.
	 *
	 * @param string                          $table_name Short table name.
	 * @param array<int, array<string,mixed>> $rows Row data.
	 * @param bool                            $replace Whether to truncate first.
	 * @return array{imported: int, skipped: int, failed: int, errors: array<int, string>}
	 *         `skipped` counts every row that did not insert; `failed` counts
	 *         the subset that failed for a reason OTHER than a duplicate-key
	 *         collision. The replace-mode abort gate in import() reads
	 *         `failed` to tell a systematic insert failure from the benign
	 *         duplicate-skips a scoped wipe leaves behind.
	 */
	private function import_table( string $table_name, array $rows, bool $replace ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$full_table = Schema::table( $table_name );
		$result     = [
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'errors'   => [],
		];

		// Merge-mode is expected to see duplicate-unique-key collisions (e.g.
		// `languages.slug` already holding "en"). Those are INTENDED skips -
		// merge adds, it doesn't overwrite. Without this suppression, wpdb
		// would echo raw SQL errors into the admin UI redirect page.
		$was_suppressing = $wpdb->suppress_errors( true );
		// Truncation is now handled by the caller ({@see self::import()}) so
		// replace covers every TABLES entry, including tables that happened
		// to be empty at export time. This method is row-insert only.

		// Insert in batches.
		$batch_count = 0;

		// Pre-fetch the natural-key map for parent tables so duplicate-skip
		// collisions still populate $id_maps with the existing target id.
		// Without this, a re-import on a target that already has the same
		// languages would leave $id_maps['languages'] empty and child FKs
		// would point at non-existent ids.
		$natural_key_lookup = $this->build_natural_key_lookup( $table_name );

		// One schema fetch for the whole table (identical every row), reused
		// for format binding and for filtering unknown columns below.
		$col_formats = $this->column_formats( $full_table );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$result['skipped'];
				continue;
			}

			$old_id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			// Rewrite FK columns from old parent id to the new one captured
			// during the parent table's pass. If a parent id wasn't mapped
			// (parent table wasn't in the export) the row keeps its export
			// value — that's the same fragile behavior as before, but only
			// reachable when a partial export breaks dependency order.
			if ( ! $replace && isset( self::FK_REFS[ $table_name ] ) ) {
				foreach ( self::FK_REFS[ $table_name ] as $col => $parent_table ) {
					if ( isset( $row[ $col ], $this->id_maps[ $parent_table ][ (int) $row[ $col ] ] ) ) {
						$row[ $col ] = $this->id_maps[ $parent_table ][ (int) $row[ $col ] ];
					}
				}
			}

			// Remove auto-increment ID for clean insert (let MySQL assign new IDs).
			// Keep ID only in replace mode to preserve references.
			if ( ! $replace ) {
				unset( $row['id'] );
			}

			// Drop columns the target schema doesn't have. An export from a
			// NEWER minor version can carry an added column (the exporter
			// deliberately does NOT bump FORMAT_VERSION for backward-compatible
			// additions), and passing an unknown column to $wpdb->insert makes
			// MySQL reject EVERY row with error 1054. In replace mode the table
			// was already wiped inside the transaction, so a systematic failure
			// would COMMIT an emptied table — silent total data loss. Filtering
			// to known columns honors the exporter's forward-compat promise and
			// keeps the insert alive.
			$row = array_intersect_key( $row, $col_formats );

			if ( $row === [] ) {
				++$result['skipped'];
				++$batch_count;
				continue;
			}

			// Build the format array from the COLUMN TYPE, not the value shape.
			// Value-shape detection (ctype_digit => %d) silently corrupts string
			// columns holding all-digit content: a leading-zero SKU/zip/EAN like
			// "00100" int-casts to "100", and magnitudes above PHP_INT_MAX clamp.
			// String columns must bind as %s so the exported value round-trips
			// verbatim.
			$format = [];

			foreach ( $row as $col => $value ) {
				$format[] = $col_formats[ $col ] ?? '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert( $full_table, $row, $format );

			if ( $inserted !== false ) {
				++$result['imported'];

				// Capture parent's old→new id mapping for child table rewrites.
				if ( ! $replace && $old_id > 0 && $this->is_parent_table( $table_name ) ) {
					$this->id_maps[ $table_name ][ $old_id ] = (int) $wpdb->insert_id;
				}
			} else {
				++$result['skipped'];

				// A duplicate-unique-key collision is an EXPECTED merge skip.
				// Anything else (unknown column, charset rejection, strict-mode
				// zero-date…) is a real failure that must not masquerade as a
				// benign skip — record it so the caller can react (and, in
				// replace mode, abort before committing a wiped table).
				$last_error = (string) $wpdb->last_error;

				if ( $last_error !== '' && stripos( $last_error, 'Duplicate entry' ) === false ) {
					++$result['failed'];

					if ( count( $result['errors'] ) < 20 ) {
						$result['errors'][] = $last_error;
					}
				}

				// Duplicate-skip on a parent: still need the existing id so
				// child FKs resolve. Look it up by natural key (slug/hash).
				if ( ! $replace && $old_id > 0 && $natural_key_lookup !== null ) {
					$existing_id = $natural_key_lookup( $row );
					if ( $existing_id > 0 ) {
						$this->id_maps[ $table_name ][ $old_id ] = $existing_id;
					}
				}
			}

			++$batch_count;

			// Periodically free memory.
			if ( $batch_count % self::BATCH_SIZE === 0 ) {
				wp_cache_flush();
			}
		}

		$wpdb->suppress_errors( $was_suppressing );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result;
	}

	/**
	 * Is this a parent table whose ids are referenced elsewhere?
	 */
	private function is_parent_table( string $table_name ): bool {
		foreach ( self::FK_REFS as $children ) {
			if ( in_array( $table_name, $children, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Closure that resolves a row to an existing parent id on the target
	 * site via its natural key (e.g. languages.slug, strings.original_hash,
	 * translation_groups.id).
	 *
	 * Returns null when the table has no natural key we can rely on
	 * (translation_groups uses id alone, so duplicate-skip in merge mode
	 * is impossible — every row gets a fresh id).
	 *
	 * @param string $table_name
	 * @return callable|null fn(array $row): int  ID or 0 if not found.
	 */
	private function build_natural_key_lookup( string $table_name ): ?callable {
		global $wpdb;

		switch ( $table_name ) {
			case 'languages':
				$tbl = Schema::table( 'languages' );
				return static function ( array $row ) use ( $wpdb, $tbl ): int {
					// languages has TWO unique keys (slug AND locale). A merge
					// import of a language whose slug is new but whose locale
					// already exists (e.g. importing "de-formal"/de_DE onto a
					// site with "de"/de_DE) fails the INSERT on the locale
					// unique key; a slug-only recovery would then miss it and
					// leave child FKs (translation_links.language_id, …)
					// pointing at a non-existent id. Fall back to locale so the
					// existing language is mapped correctly.
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( ! empty( $row['slug'] ) ) {
						$id = (int) $wpdb->get_var(
							$wpdb->prepare(
								'SELECT id FROM %i WHERE slug = %s LIMIT 1',
								$tbl,
								$row['slug']
							)
						);

						if ( $id > 0 ) {
							return $id;
						}
					}

					if ( ! empty( $row['locale'] ) ) {
						return (int) $wpdb->get_var(
							$wpdb->prepare(
								'SELECT id FROM %i WHERE locale = %s LIMIT 1',
								$tbl,
								$row['locale']
							)
						);
					}

					return 0;
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				};

			case 'strings':
				$tbl = Schema::table( 'strings' );
				return static function ( array $row ) use ( $wpdb, $tbl ): int {
					if ( empty( $row['original_hash'] ) ) {
						return 0; }
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					return (int) $wpdb->get_var(
						$wpdb->prepare(
							'SELECT id FROM %i WHERE original_hash = %s LIMIT 1',
							$tbl,
							$row['original_hash']
						)
					);
				};

			default:
				// translation_groups has no natural key, so merge mode can't
				// dedup them - each re-inserts as a fresh row and may widow.
				// sweep_orphan_groups() (called after the merge) reclaims them.
				// Other parents here have no FK children, so no map needed.
				return null;
		}
	}

	/**
	 * Reclaim widow translation groups stranded by a merge import.
	 *
	 * Delegates to the canonical {@see TranslationGroupRepository::sweep_orphan_groups()}
	 * so the cleanup logic lives in one place (shared with the upgrade self-heal).
	 *
	 * @return void
	 */
	private function sweep_orphan_groups(): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		( new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) ) )
			->sweep_orphan_groups();
	}

	/**
	 * Delete string links whose owning translation group is gone.
	 *
	 * The mirror image of {@see self::sweep_orphan_groups()}: that reclaims
	 * groups nothing points at, this reclaims links pointing at nothing.
	 * Both shapes are produced by an import — a replace-mode strings bundle
	 * clears the type='string' slice of `translation_groups` without owning
	 * `translation_links`, and the merge-mode sweep deletes string groups
	 * that no longer own a string while leaving their links behind.
	 *
	 * Nothing else reclaims them: the sweep only deletes groups, and
	 * `repair_orphaned_translations()` never deletes a link — its one DELETE
	 * targets `string_translations` rows whose string is gone, and its two
	 * write paths INSERT a group (plus the owning string's group_id UPDATE)
	 * and INSERT IGNORE a link. Left in place they are
	 * not merely dead weight — they still occupy their slot in the UNIQUE
	 * `object_lang` (type, object_id, language_id) key, so the repair's
	 * INSERT IGNORE for the same string under a new group id is silently
	 * dropped and the translation stops being served.
	 *
	 * One statement, bounded to the string slice by the leading column of
	 * that same key.
	 *
	 * @return int Rows deleted.
	 */
	private function reap_orphan_string_links(): int {
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// Table names come from Schema::table() (a class-controlled allow-list)
		// and travel as %i identifiers; the type is bound as a value. A
		// single-line ignore annotation would cover only the line after it, so
		// the multi-line prepare() below needs the disable/enable block form.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE l FROM %i l
				LEFT JOIN %i g ON g.id = l.group_id
				WHERE g.id IS NULL AND l.type = %s',
				$links_table,
				$groups_table,
				'string'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return max( 0, (int) $deleted );
	}
}
