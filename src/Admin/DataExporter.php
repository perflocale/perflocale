<?php
/**
 * Data exporter - exports selected plugin data as JSON.
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
 * Exports PerfLocale data (settings, languages, translations, glossary, etc.)
 * as a single JSON file. Uses batched queries to handle large datasets without
 * exceeding memory limits.
 */
final class DataExporter {

	/**
	 * Rows per batch for large tables.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Envelope format version. Bump only when the JSON shape changes in a
	 * non-backward-compatible way (a section is renamed/removed, a column is
	 * dropped). Adding new optional sections does not require a bump - older
	 * importers ignore unknown keys gracefully. The importer rejects exports
	 * with format_version > self::FORMAT_VERSION since they may carry data
	 * shapes this version doesn't understand.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Top-level envelope keys an addon-provided section may NOT use. Shared by
	 * the exporter (skips these when emitting addon sections) and the importer
	 * (skips these when dispatching addon sections) so the two can't drift.
	 * addon_settings + disabled_addons are included because both carry
	 * (redacted) credentials — an addon section of either name would smuggle a
	 * full addon_settings blob past redaction on import.
	 */
	public const RESERVED_SECTION_KEYS = [
		'perflocale_export',
		'version',
		'format_version',
		'exported_at',
		'site_url',
		'sections',
		'settings',
		'addon_settings',
		'disabled_addons',
		'roles',
		'data',
	];

	/**
	 * Suffix pattern identifying credential-shaped setting keys. Shared by the
	 * exporter (which redacts these so secrets never travel in a backup) and
	 * the importer (which preserves the target's existing value for a key the
	 * export redacted, so restoring a backup doesn't blank live credentials).
	 * Keeping it in one place stops the two sides from drifting.
	 */
	public const CREDENTIAL_KEY_PATTERN = '/(?:_api_key|_token|_key|_secret|_password)$/';

	/**
	 * Available export sections with labels and associated tables.
	 *
	 * The optional `type_scope` key narrows a table the section SHARES with
	 * another section down to the slice it owns: table => the value of that
	 * table's polymorphic `type` column. Only id-keyed tables can be
	 * scoped (every shared table has an `id` primary key). A table claimed
	 * WITHOUT a scope by any selected section is always exported whole - the
	 * widest claim wins - so a full bundle is byte-for-byte what it was
	 * before scoping existed.
	 */
	public const SECTIONS = [
		'settings'     => [
			'label'  => 'Settings',
			'tables' => [],
		],
		'roles'        => [
			'label'  => 'Roles & Capabilities',
			'tables' => [],
		],
		'languages'    => [
			'label'  => 'Languages',
			'tables' => [ 'languages' ],
		],
		'translations' => [
			'label'  => 'Translation Links',
			'tables' => [ 'translation_groups', 'translation_links' ],
		],
		'strings'      => [
			'label'      => 'String Translations',
			// `strings` holds the source rows; `string_translations` holds the
			// actual per-language translated values (string_id, language_id,
			// translation). Exporting only `strings` would drop every
			// translation on import - the most painful possible silent data
			// loss for a multilingual plugin. Both must travel together.
			//
			// `translation_groups` travels with them because a
			// `strings.group_id` is a reference INTO that table, and without
			// the parent rows a strings-only bundle restores strings whose
			// group_id names a group id from the SOURCE site: dangling on a
			// fresh target, and - since the id-space is shared with post and
			// term groups - silently adopted by an unrelated post group when
			// the ids happen to overlap.
			//
			// Not every `strings.group_id` resolves, though, and this section
			// does not assume it does. A scanner-created string keeps the
			// group id it was stamped with even after sweep_orphan_groups()
			// reclaims that group for owning no string, so on a site that has
			// run a string scan the DANGLING ids are typically the large
			// majority - a group id that resolves is the exception, not the
			// rule. Those rows export and import with their dangling id
			// intact and carrying the groups changes nothing for them. What
			// the parent rows buy is the minority that DO still resolve:
			// those keep their real group instead of landing on whatever the
			// target happens to store under that id. Either way, a string
			// that actually carries translations is re-grouped on arrival by
			// repair_orphaned_translations().
			'tables'     => [ 'translation_groups', 'strings', 'string_translations' ],
			// `translation_groups` is POLYMORPHIC (post, term and string rows
			// share one table and one id-space) and it is also the
			// `translations` section's table, so a strings-only bundle may
			// only carry the type='string' slice it owns. Exporting the whole
			// table here would make "replace my strings" wipe and overwrite
			// every post and term group on the target site. When both sections
			// are selected the unscoped claim from `translations` wins and the
			// table exports whole.
			//
			// The per-language `translation_links` rows are deliberately NOT
			// carried. WHICH string in WHICH language a link stands for is fully
			// derivable from (strings, string_translations), and
			// TranslationFileGenerator::repair_orphaned_translations() - which
			// DataImporter runs after every merge import and after any replace
			// import that landed strings - re-creates one link per
			// (group, language) pair that has a translation row but no link.
			// Only pairs whose string sits in a live type='string' group are
			// re-linked; a string whose group_id dangles is first given a
			// fresh group by the same pass, and only when it has at least one
			// translation to justify the group.
			//
			// What a link ALSO holds and nothing can derive is its own `status`
			// and `source`, so a rebuilt link comes back 'published'/'manual':
			// on a CROSS-site restore a string the source site had flagged
			// `needs_update` (StringRepository's list filter reads that column)
			// arrives unflagged. That is the accepted v1 trade. A same-site
			// restore keeps its real statuses because the wipe is scoped to
			// `translation_groups` and never touches the links at all; carrying
			// them would instead mean scoping a SECOND wipe at the shared links
			// table, for rows the repair can already rebuild.
			'type_scope' => [ 'translation_groups' => 'string' ],
		],
		'slugs'        => [
			'label'  => 'Slug Translations',
			'tables' => [ 'slug_translations' ],
		],
		'hashes'       => [
			'label'  => 'Content Hashes',
			'tables' => [ 'content_hashes' ],
		],
	];

	/**
	 * Export selected sections and send as an HTTP JSON download.
	 *
	 * Thin wrapper around {@see self::write_to()} that writes to php://output
	 * with browser-download headers, then exits. CLI + programmatic callers
	 * should use {@see self::write_to_file()} instead - that path returns
	 * without exiting so the caller can log, verify, and clean up.
	 *
	 * @param array<int, string> $sections Section keys to export. Empty = all.
	 * @return void Dies after sending the file.
	 */
	public function download( array $sections = [] ): void {
		$sections = $this->normalize_sections( $sections );
		$filename = 'perflocale-export-' . gmdate( 'Y-m-d-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		nocache_headers();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to php://output requires direct PHP file functions.
		$out = fopen( 'php://output', 'w' );

		if ( ! $out ) {
			wp_die( esc_html__( 'Failed to create export stream.', 'perflocale' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to php://output requires direct PHP file functions.
		$this->write_to( $out, $sections );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to php://output requires direct PHP file functions.
		fclose( $out );
		exit;
	}

	/**
	 * Stream the export to a file on disk.
	 *
	 * Used by the WP-CLI `perflocale export` command and by any programmatic
	 * caller that needs a file without the HTTP download dance. Preserves
	 * the streaming memory profile of {@see self::download()}.
	 *
	 * @param string             $path Destination file path.
	 * @param array<int, string> $sections Section keys to export. Empty = all.
	 * @return int|false Bytes written, or false on failure.
	 */
	public function write_to_file( string $path, array $sections = [] ) {
		$sections = $this->normalize_sections( $sections );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to disk; WP_Filesystem has no streaming API.
		$out = fopen( $path, 'w' );

		if ( ! $out ) {
			return false;
		}

		$this->write_to( $out, $sections );

		// Force any buffered bytes to disk and capture the success flag
		// BEFORE fclose - a disk-full condition only surfaces at flush
		// time, not from each individual fwrite. Without this check,
		// truncated/corrupt exports get returned as "success" with
		// fstat() showing the pre-flush byte count.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Streaming to disk requires direct PHP file functions.
		$flushed = fflush( $out );
		$stat    = fstat( $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to disk requires direct PHP file functions.
		$closed = fclose( $out );

		if ( $flushed === false || $closed === false ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExporter: flush/close failed on ' . $path . ' - disk full or quota exhausted?' );
			wp_delete_file( $path );
			return false;
		}

		// Sanity-check the resulting file: a valid PerfLocale export is
		// JSON that starts with `{` and ends with `}`. If the file is
		// truncated mid-write (disk full between fwrites, before our
		// flush check runs), the tail will be missing the closing
		// brace. Drop the partial file rather than handing back a
		// corrupted archive the user will try to import later.
		$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		if ( $size < 2 ) {
			wp_delete_file( $path );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming validation needs fopen; @ suppresses the open-failure warning that the truthiness check on the next line handles.
		$fh = @fopen( $path, 'r' );
		if ( $fh ) {
			// Read the last 16 bytes so we can tolerate trailing
			// whitespace (write_to() ends with "}\n"). Anything past
			// the closing brace must be whitespace only — a truncated
			// dump tends to end mid-string / mid-token, never with a
			// well-balanced `}` followed by `\n`.
			$tail_len = min( 16, $size );
			fseek( $fh, max( 0, $size - $tail_len ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading the closing bytes of a file we just wrote; WP_Filesystem reads the whole file which would defeat the streaming-validation purpose.
			$tail = fread( $fh, $tail_len );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.

			$trimmed = is_string( $tail ) ? rtrim( $tail ) : '';

			if ( $trimmed === '' || substr( $trimmed, -1 ) !== '}' ) {
				error_log( 'PerfLocale DataExporter: produced truncated JSON (tail: ' . var_export( $tail, true ) . ') - dropping ' . $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
				wp_delete_file( $path );
				return false;
			}
		}

		return $size;
	}

	/**
	 * Ensure `$sections` is the full list when the caller passed an empty array.
	 *
	 * @param array<int, string> $sections Raw section keys.
	 * @return array<int, string>
	 */
	private function normalize_sections( array $sections ): array {
		return empty( $sections ) ? array_keys( self::SECTIONS ) : $sections;
	}

	/**
	 * Strip every setting whose key looks like a credential from the export.
	 *
	 * Covers all *_api_key / *_token / *_key / *_secret / *_password settings
	 * via suffix match so future additions are redacted by default. Exports
	 * are commonly shared as backups - credentials must never travel.
	 *
	 * @param array<string, mixed> $settings Raw settings array.
	 * @return array<string, mixed>
	 */
	public static function redact_credentials( array $settings ): array {
		foreach ( array_keys( $settings ) as $key ) {
			if ( preg_match( self::CREDENTIAL_KEY_PATTERN, (string) $key ) ) {
				unset( $settings[ $key ] );
			}
		}

		return $settings;
	}

	/**
	 * Strip credential-shaped fields out of every addon's settings entry.
	 *
	 * Shape: `[ addon_id => [ field_key => value, ... ] ]`. Walks each
	 * inner entry through the same suffix list as redact_credentials() so
	 * an addon's `*_api_key` / `*_token` / etc. never ride along on a
	 * staging → prod export. Per-addon credentials stay on the exporting
	 * site; operators re-enter them on import.
	 *
	 * @param array<string, mixed> $addon_settings
	 * @return array<string, mixed>
	 */
	public static function redact_addon_credentials( array $addon_settings ): array {
		foreach ( $addon_settings as $addon_id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$addon_settings[ $addon_id ] = self::redact_credentials( $entry );
		}
		return $addon_settings;
	}

	/**
	 * Snapshot the PerfLocale role + capability state for export.
	 *
	 * Captures:
	 * - The Translator role's full capabilities map (in case an admin added
	 *   custom caps via User Role Editor or similar).
	 * - PerfLocale-prefixed caps granted to administrator + editor (lets
	 *   us restore custom grants — e.g. an admin who gave Editors
	 *   `perflocale_manage_languages`).
	 * - The `perflocale_caps_version` option so the importer can decide
	 *   whether `ensure_roles_exist()` should re-run on next admin_init.
	 *
	 * Foreign-role caps are filtered to the `perflocale_*` prefix so we
	 * never leak unrelated plugins' capabilities into our export.
	 *
	 * @return array{caps_version:int, translator:array<string,bool>, role_grants:array<string, array<string,bool>>}
	 */
	public static function snapshot_roles(): array {
		$translator_caps = [];
		$translator      = get_role( TranslatorRole::ROLE_SLUG );

		if ( $translator instanceof \WP_Role ) {
			$translator_caps = array_map( static fn( $v ) => (bool) $v, (array) $translator->capabilities );
		}

		$role_grants = [];
		foreach ( [ 'administrator', 'editor' ] as $role_slug ) {
			$role = get_role( $role_slug );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			$grants = [];
			foreach ( (array) $role->capabilities as $cap => $grant ) {
				if ( str_starts_with( (string) $cap, 'perflocale_' ) ) {
					$grants[ (string) $cap ] = (bool) $grant;
				}
			}

			if ( ! empty( $grants ) ) {
				$role_grants[ $role_slug ] = $grants;
			}
		}

		return [
			'caps_version' => (int) get_option( 'perflocale_caps_version', 0 ),
			'translator'   => $translator_caps,
			'role_grants'  => $role_grants,
		];
	}

	/**
	 * Shared streaming logic used by download() and write_to_file().
	 *
	 * @param resource           $out Output stream.
	 * @param array<int, string> $sections Normalized section keys (non-empty).
	 * @return void
	 */
	private function write_to( $out, array $sections ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to a JSON output stream; WP_Filesystem has no streaming API and download() targets php://output.

		fwrite( $out, "{\n" );
		fwrite( $out, '"perflocale_export": true,' . "\n" );
		fwrite( $out, '"version": "' . PERFLOCALE_VERSION . '",' . "\n" );
		fwrite( $out, '"format_version": ' . (int) self::FORMAT_VERSION . ',' . "\n" );
		fwrite( $out, '"exported_at": "' . gmdate( 'c' ) . '",' . "\n" );
		fwrite( $out, '"site_url": ' . wp_json_encode( home_url() ) . ',' . "\n" );
		fwrite( $out, '"sections": ' . wp_json_encode( $sections ) . ',' . "\n" );

		if ( in_array( 'settings', $sections, true ) ) {
			$settings = get_option( 'perflocale_settings', [] );

			if ( is_array( $settings ) ) {
				$settings = self::redact_credentials( $settings );
			}

			fwrite( $out, '"settings": ' . wp_json_encode( $settings ) . ',' . "\n" );

			// Per-addon settings (perflocale_addon_settings, keyed by addon
			// id). Travels with the 'settings' section because it's
			// conceptually the same surface from an operator POV: cloning
			// staging → prod should pick up addon-level config too, not
			// silently drop it. Each addon's entry is redacted with the
			// same _api_key / _token / _key / _secret / _password rules
			// as the main settings — any addon's secrets stay on the
			// exporting site.
			$addon_settings = get_option( 'perflocale_addon_settings', [] );

			if ( is_array( $addon_settings ) ) {
				$addon_settings = self::redact_addon_credentials( $addon_settings );
			}

			fwrite( $out, '"addon_settings": ' . wp_json_encode( $addon_settings ) . ',' . "\n" );

			// Operator's enable/disable choices per addon. Without this,
			// a staging → prod clone would carry an addon's settings but
			// leave the target's disabled-list intact, producing a silent
			// "configured but disabled" state mismatch. Empty array on
			// the source means "nothing disabled" and must be preserved
			// literally — that's a meaningful operator intent.
			$disabled_addons = (array) get_option( 'perflocale_disabled_addons', [] );
			fwrite( $out, '"disabled_addons": ' . wp_json_encode( array_values( array_filter( array_map( 'strval', $disabled_addons ) ) ) ) . ',' . "\n" );
		}

		if ( in_array( 'roles', $sections, true ) ) {
			fwrite( $out, '"roles": ' . wp_json_encode( self::snapshot_roles() ) . ',' . "\n" );
		}

		$tables = [];
		$scopes = [];

		foreach ( $sections as $section ) {
			if ( ! isset( self::SECTIONS[ $section ] ) ) {
				continue;
			}

			foreach ( self::SECTIONS[ $section ]['tables'] as $table_name ) {
				$tables[] = $table_name;

				$scope = self::SECTIONS[ $section ]['type_scope'][ $table_name ] ?? null;

				// Widest claim wins, whatever order the sections arrived in: a
				// section that asks for the table without a type scope needs
				// every row, so its null overrides any narrower claim and can
				// never be overridden by one.
				if ( $scope === null || ! array_key_exists( $table_name, $scopes ) ) {
					$scopes[ $table_name ] = $scope;
				}
			}
		}

		$tables = array_unique( $tables );

		fwrite( $out, '"data": {' . "\n" );

		$table_count = count( $tables );
		$i           = 0;

		foreach ( $tables as $table_name ) {
			$is_last = ( $i === $table_count - 1 );
			$this->export_table( $out, $table_name, $is_last, $scopes[ $table_name ] ?? null );
			++$i;
		}

		fwrite( $out, '}' );

		/**
		 * Filter the extra top-level sections an addon wants to add to the
		 * site-export JSON envelope.
		 *
		 * Returned array is keyed by section name; each value must be a
		 * JSON-serialisable array/scalar. Each entry is appended to the
		 * envelope as `"<section_name>": <json>` at the top level (a sibling
		 * of `data`, `settings`, `roles`). Use this to let your addon ship
		 * its own rows alongside a `wp perflocale export`. Symmetric
		 * counterpart: the `perflocale/import/section/<name>` action that
		 * fires when an importer encounters your section.
		 *
		 * Reserved core keys (perflocale_export, version, format_version,
		 * exported_at, site_url, sections, settings, roles, data) are
		 * silently dropped if a filter callback tries to overwrite them.
		 *
		 * @hook  perflocale/export/sections
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $external_sections Empty by default.
		 * @param array{
		 *     requested: array<int,string>,
		 *     format_version: int,
		 * } $context Information about the in-progress export.
		 * @return array<string, mixed>
		 */
		$external_sections = (array) apply_filters(
			'perflocale/export/sections',
			[],
			[
				'requested'      => $sections,
				'format_version' => (int) self::FORMAT_VERSION,
			]
		);

		foreach ( $external_sections as $name => $payload ) {
			// Reserved keys can't be overwritten — addons append, never
			// replace. addon_settings/disabled_addons are in the shared list
			// too: both carry redacted credentials, so an addon-provided
			// section of either name would let a crafted addon smuggle a full
			// addon_settings blob (secrets included) past redaction on import.
			// Names are also charset-restricted so the symmetric import hook
			// perflocale/import/section/<name> stays well-formed.
			if (
				! is_string( $name )
				|| $name === ''
				|| in_array( $name, self::RESERVED_SECTION_KEYS, true )
				|| ! preg_match( '/^[A-Za-z0-9_-]+$/', $name )
			) {
				continue;
			}

			$encoded = wp_json_encode( $payload );

			if ( $encoded === false ) {
				continue;
			}

			fwrite( $out, ',' . "\n" );
			// Encode the KEY too so a name with a quote/backslash can never
			// produce invalid JSON (the charset guard already rejects those;
			// this keeps the envelope well-formed by construction anyway).
			fwrite( $out, wp_json_encode( (string) $name ) . ': ' . $encoded );
		}

		fwrite( $out, "\n}\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * Export a single table in batches.
	 *
	 * @param resource    $out Output stream.
	 * @param string      $table_name Short table name (without prefix).
	 * @param bool        $is_last Whether this is the last table.
	 * @param string|null $type_scope Emit only the rows whose polymorphic `type`
	 *                                column holds this value (see SECTIONS);
	 *                                null emits the whole table.
	 * @return void
	 */
	private function export_table( $out, string $table_name, bool $is_last, ?string $type_scope = null ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to php://output requires direct PHP file functions.
		global $wpdb;

		$full_table = Schema::table( $table_name );

		fwrite( $out, '"' . $table_name . '": [' . "\n" );

		// Keyset pagination cursors. Every export table has an `id` PK except
		// string_translations, which is keyed on the (string_id, language_id)
		// tuple — paginate on that tuple there.
		$has_id    = ( 'string_translations' !== $table_name );
		$last_id   = 0;
		$last_str  = 0;
		$last_lang = 0;
		$first_row = true;

		/**
		 * Rows read from the source table per LIMIT clause during streaming
		 * export. Default 1000. Higher values cut query count for big tables
		 * (e.g. 50k strings → 50 queries at 1000, 500 at 100); lower values
		 * keep peak PHP memory smaller. The bottleneck is usually the
		 * `wp_json_encode` loop, not the SELECT.
		 *
		 * Clamped to 50–10000.
		 *
		 * @hook perflocale/export/batch_size
		 * @param int    $size       Default 1000.
		 * @param string $table_name Table being exported (so callers can
		 *                           vary per-table — heavier rows for
		 *                           `strings`, lighter for `languages`).
		 */
		$batch_size = (int) apply_filters( 'perflocale/export/batch_size', self::BATCH_SIZE, $table_name );
		$batch_size = max( 50, min( 10000, $batch_size ) );

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// Keyset (WHERE key > cursor), NOT LIMIT/OFFSET: OFFSET re-reads and
			// re-skips every prior row (O(n^2)) AND, worse, a concurrent
			// INSERT/DELETE mid-export shifts the offset window so rows are
			// silently skipped or duplicated. Keyset windows are disjoint and
			// complete regardless of concurrent writes.
			if ( $has_id && $type_scope !== null ) {
				// Owned slice of a polymorphic table. Same keyset window as
				// the branch below, with the owning type bound as a VALUE -
				// never interpolated - so the scope can't widen the query.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND id > %d ORDER BY id ASC LIMIT %d',
						$full_table,
						$type_scope,
						$last_id,
						$batch_size
					),
					ARRAY_A
				);
			} elseif ( $has_id ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
						$full_table,
						$last_id,
						$batch_size
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE string_id > %d OR ( string_id = %d AND language_id > %d ) ORDER BY string_id ASC, language_id ASC LIMIT %d',
						$full_table,
						$last_str,
						$last_str,
						$last_lang,
						$batch_size
					),
					ARRAY_A
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! $first_row ) {
					fwrite( $out, ',' . "\n" );
				}

				fwrite( $out, wp_json_encode( $row ) );
				$first_row = false;
			}

			// Advance the keyset cursor to this batch's last row.
			if ( $rows !== [] ) {
				$tail = end( $rows );

				if ( $has_id ) {
					$last_id = (int) $tail['id'];
				} else {
					$last_str  = (int) $tail['string_id'];
					$last_lang = (int) $tail['language_id'];
				}
			}

			$got_full_batch = ( count( $rows ) === $batch_size );
		} while ( $got_full_batch );

		fwrite( $out, "\n" . ']' . ( $is_last ? '' : ',' ) . "\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
}
