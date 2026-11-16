<?php
/**
 * Data exporter - exports selected plugin data as JSON.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\DataRegistry;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports the selected PerfLocale sections as one JSON envelope, streamed from batched reads.
 */
final class DataExporter {

	/** pending_changes columns before payload and after summary, in DataRegistry's order (ExportPendingChangesFetchTest holds them equal). */
	private const PENDING_CHANGES_HEAD = 'id, area, object_type, object_id, source_id, language_id, origin, trigger_name, job_id, user_id, actor, decision, reason, status';

	/** See PENDING_CHANGES_HEAD. */
	private const PENDING_CHANGES_TAIL = 'base_hash, result_hash, result_status, result_modified, NULL AS previous, created_at, decided_at, decided_by';

	/** Bytes of payload and summary one pending_changes page holds; a larger value is read alone, one row at a time. */
	private const PENDING_PAGE_BYTES = 8388608;

	/**
	 * Rows per batch for large tables.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Envelope format version written: bump only for an incompatible shape change (not a new optional section).
	 *
	 * 1: string hashes in 1.0.7.5's rule, so 1.0.7.5 imports them matched; DataImporter re-hashes every string row.
	 */
	public const FORMAT_VERSION = 1;

	/** Highest format DataImporter reads; 2: a 1.0.8 pre-release file ("\x04" hashes, re-hashed on import). */
	public const READ_VERSION = 2;

	/**
	 * The importer's default limit for one value, in bytes (DataImporter::find_data_quality_issues()).
	 */
	private const MAX_IMPORT_VALUE_BYTES = 1048576;

	/**
	 * Envelope keys an add-on section may not use, shared by exporter and importer.
	 *
	 * The addon_settings and disabled_addons keys carry redacted credentials, so only the core exporter writes them.
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
		'addon_groups',
		'object_max_ids',
	];

	/**
	 * Credential-shaped setting keys: redacted on export; the importer keeps the target's value for a redacted key.
	 *
	 * `_token` and `_auth` match only as a whole name part, so `max_tokens` and `show_author` are not credentials.
	 */
	public const CREDENTIAL_KEY_PATTERN = '/_api_?key|_secret|_password|_credentials?|_encrypted|_token(?:_|$)|_auth(?:_|$)|_key$/i';

	/**
	 * A credential URL query parameter name: a whole name, optionally after a prefix ending in `_` or `-`.
	 *
	 * A camelCase name is matched in its snake_case form (`accessToken` as `access_Token`).
	 */
	private const CREDENTIAL_QUERY_PATTERN = '/^(?:[a-z0-9]+[_-])*(?:api[_-]?key|apikey|key|token|auth|authorization|secret|password|passwd|pass|pwd|sig|signature|code|credentials?)$/i';

	/**
	 * Export sections with their labels and tables (DataRegistry::sections(), in export screen order).
	 *
	 * `type_scope` limits a shared table to the `type` slice a section owns; an unscoped claim exports the table whole.
	 * See docs-ai/11-code-rationale.md, "DataExporter: section type scope".
	 *
	 * @return array<string, array{label: string, tables: list<string>, type_scope?: array<string, string>}>
	 */
	public static function sections(): array {
		return DataRegistry::sections();
	}

	/**
	 * Tables whose rows name a post or term by `object_id`, with the type column that says which (`object_ref:` columns).
	 *
	 * @return array<string, string> Table => type column.
	 */
	public static function fingerprint_columns(): array {
		$out = [];

		foreach ( DataRegistry::import_tables() as $table ) {
			foreach ( DataRegistry::table_columns( $table ) as $class ) {
				if ( 0 === strpos( $class, 'object_ref:' ) ) {
					$out[ $table ] = substr( $class, strlen( 'object_ref:' ) );
				}
			}
		}

		return $out;
	}

	/**
	 * The options or meta of one kind the sections carry as row lists: [ kind, name, object, merge rule, sanitizer, regex ].
	 *
	 * Entries with their own envelope key (settings, roles) are left out.
	 *
	 * @param string             $kind     `option`, `post_meta`, `term_meta` or `order_meta`.
	 * @param array<int, string> $sections Section keys.
	 * @return array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5: string}>
	 */
	public static function row_list_items( string $kind, array $sections ): array {
		$out = [];

		foreach ( $sections as $section ) {
			foreach ( DataRegistry::section_entries( (string) $section, $kind ) as $id => $entry ) {
				if ( isset( DataRegistry::ENVELOPE_KEYS[ $id ] ) ) {
					continue;
				}

				$out[] = self::item( $kind, (string) $id, $entry );
			}
		}

		return $out;
	}

	/**
	 * The options or meta of one kind that one add-on part carries, shaped as row_list_items() returns them.
	 *
	 * @param string $kind `option`, `post_meta`, `term_meta` or `order_meta`.
	 * @param string $part Part key (DataRegistry::addon_parts()).
	 * @return array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5: string}>
	 */
	public static function part_items( string $kind, string $part ): array {
		$out = [];

		foreach ( DataRegistry::addon_parts()[ $part ]['entries'] ?? [] as $id => $entry ) {
			if ( $kind === $entry['kind'] ) {
				$out[] = self::item( $kind, (string) $id, $entry );
			}
		}

		return $out;
	}

	/**
	 * One registry entry as a row list item.
	 *
	 * @param string               $kind  Entry kind.
	 * @param string               $id    Entry id.
	 * @param array<string, mixed> $entry Entry.
	 * @return array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5: string}
	 */
	private static function item( string $kind, string $id, array $entry ): array {
		$import = is_array( $entry['import'] ?? null ) ? $entry['import'] : [];
		$name   = Typed::str( $entry['name'] ?? '' );

		return [ $kind, $name, isset( $import['object'] ) ? Typed::str( $import['object'] ) : null, isset( $import['merge'] ) ? Typed::str( $import['merge'] ) : null, DataRegistry::import_sanitizer( $id ), DataRegistry::to_regex( $name ) ];
	}

	/**
	 * The add-on data with its own export and import checkbox: group part (`<owner>/<group id>`) => label.
	 *
	 * @return array<string, string>
	 */
	public static function addon_groups(): array {
		return DataRegistry::addon_groups();
	}

	/**
	 * Whether a value is an add-on data choice: `addon_data` (the shared checkbox) or a group part (`<owner>/<group id>`).
	 *
	 * @param string $choice Value.
	 * @return bool
	 */
	public static function is_addon_choice( string $choice ): bool {
		if ( DataRegistry::ADDON_SECTION === $choice ) {
			return true;
		}

		$parsed = DataRegistry::parse_part_member( DataRegistry::part_member( $choice, DataRegistry::ROW_LISTS['option'] ) );

		return null !== $parsed && str_contains( $parsed[0], '/' );
	}

	/**
	 * The add-on parts an export carries, in registry order: ungrouped parts with Add-on data, plus the chosen group parts.
	 *
	 * @param array<int, string>      $sections Section keys.
	 * @param array<int, string>|null $groups   Group parts (DataRegistry::addon_groups() keys), or null for
	 *                                          every group when the sections include Add-on data.
	 * @return list<string>
	 */
	public static function export_parts( array $sections, ?array $groups ): array {
		$shared = in_array( DataRegistry::ADDON_SECTION, $sections, true );
		$out    = [];

		foreach ( DataRegistry::addon_parts() as $part => $p ) {
			$chosen = null === $p['group'] || null === $groups ? $shared : in_array( $part, $groups, true );

			if ( $chosen ) {
				$out[] = (string) $part;
			}
		}

		return $out;
	}

	/**
	 * Data no section carries, with the reason (a `*` name is a prefix): every Schema::REQUIRED_TABLES table no section
	 * names (a unit test holds the lists together) and the ReSync and field-layer meta, options and transients.
	 */
	public const NOT_EXPORTED = [
		'jobs'                               => 'Table. This site\'s background job rows (runs in progress, their results and logs).',
		'migration_source_map'               => 'Table. The ids a WPML, Polylang or TranslatePress import on this site mapped to its translation groups; a re-run of that import reads it.',
		'resync_base'                        => 'Table. Copies of post content keyed by post ids, rebuilt by the next resync; cleared with the translation links on a Replace (DataImporter).',
		'resync_state'                       => 'Table. Per-translation sync state keyed by post ids, rebuilt by the next resync; cleared with the translation links on a Replace (DataImporter).',
		'_perflocale_stub_title'             => 'Post meta of a translation PerfLocale created empty. The export carries no posts or post meta: it travels with its post (a WXR export or a database copy).',
		'_perflocale_field_override'         => 'Post meta of a translation (the field keys it keeps apart from its source). The export carries no posts or post meta: it travels with its post (a WXR export or a database copy).',
		'perflocale_scope_removals_*'        => 'Option. A string scope\'s deferred removals and pending copies, naming this site\'s string ids; a Replace of the strings deletes them and the next sync of the scope derives them again.',
		'perflocale_resync_run_*'            => 'Option. A running ReSync job\'s cursor, bound to a job row of this site (jobs are not exported).',
		'perflocale_resync_pending_pass'     => 'Option. Where this site\'s housekeeping pass over pending post payloads continues.',
		'perflocale_resync_stale_after'      => 'Option. Where this site\'s next housekeeping run continues its repairs of stale intents.',
		'perflocale_automation_housekeeping' => 'Option. Where this site\'s automation housekeeping continues its checks of old changes and ReSync records.',
		'perflocale_resync_kept_*'           => 'Option. The index of kept machine-translation outputs (transients) of this site\'s runs; they expire.',
		'perflocale_resync_units_*'          => 'Transient. A kept machine-translation output of a run, keyed by this site\'s post ids and versions; it expires, and the next machine run rebuilds it.',
		'perflocale_resync_request_*'        => 'Transient (30 minutes). A run request between its estimate and its start.',
		'perflocale_resync_result_*'         => 'Transient (5 minutes). A run\'s result for the next screen of its user.',
		'perflocale_string_tables_engine'    => 'Option. This database\'s storage engine check of the string tables, read again per plugin version.',
	];

	/**
	 * Deepest level redact_credentials() walks: an unserialized `R:` back-reference can make a settings array cyclic.
	 *
	 * A deeper subtree is dropped, not passed through: it could hold credentials the walk never checked.
	 */
	private const REDACT_MAX_DEPTH = 8;

	/**
	 * Temp files write_to_file() created but has not published, by absolute path (so no per-blog state).
	 *
	 * The shutdown sweep removes them after a fatal, which would leave a partial export in web-served uploads.
	 *
	 * @var array<string, bool>
	 */
	private static array $pending_temp_files = [];

	/**
	 * Whether the shutdown sweep is registered: once per request, since network export writes one file per site per process.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Rows per table that the last estimate_bytes() counted.
	 *
	 * @var array<string, int>
	 */
	private array $counted = [];

	/**
	 * The format the running export writes: FORMAT_VERSION, or 2 when two of its strings share 1.0.7.5's hash.
	 *
	 * @var int
	 */
	private int $format = self::FORMAT_VERSION;

	/**
	 * Format 1: every emitted 1.0.7.5 hash, raw, in 16 php://temp buckets by its first nibble (bounded memory at any size).
	 *
	 * @var array<int, resource>
	 */
	private array $legacy_parts = [];

	/**
	 * Format 1: hashes written per bucket, so a short read of one is never taken for the whole record.
	 *
	 * @var array<int, int>
	 */
	private array $legacy_counts = [];

	/**
	 * Export selected sections as an HTTP JSON download, then exit; CLI and code callers use write_to_file().
	 *
	 * @param array<int, string>      $sections Section keys to export. Empty, with no group either, = all.
	 * @param array<int, string>|null $groups   Add-on group parts to export (DataRegistry::addon_groups()
	 *                                          keys), or null for every group when the sections include
	 *                                          Add-on data.
	 * @return void Dies after sending the file.
	 * @throws \Throwable Any non-RuntimeException, so a bug is not disguised as an aborted-export envelope.
	 */
	public function download( array $sections = [], ?array $groups = null ): void {
		[ $sections, $groups ] = $this->normalize_sections( $sections, $groups );
		$filename = 'perflocale-export-' . gmdate( 'Y-m-d-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		nocache_headers();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to php://output requires direct PHP file functions.
		$out = fopen( 'php://output', 'w' );

		if ( ! $out ) {
			wp_die( esc_html__( 'Failed to create export stream.', 'perflocale' ) );
		}

		try {
			$this->write_to( $out, $sections, $groups );
		} catch ( \Throwable $e ) {
			// Body already sent: end without the closing brace, so a partial dump is not valid JSON and the importer refuses it.
			// Caught because admin_init would white-screen; the route is admin-only, so naming the DB error is safe.
			// Only export_table()'s RuntimeException is expected: re-raise anything else before the abort marker.
			if ( ! $e instanceof \RuntimeException ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to php://output requires direct PHP file functions.
				fclose( $out );
				throw $e;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to php://output requires direct PHP file functions.
			fwrite( $out, "\n" . '"__perflocale_export_aborted": ' . wp_json_encode( $e->getMessage() ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to php://output requires direct PHP file functions.
			fclose( $out );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExporter: streamed export aborted - ' . $e->getMessage() );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to php://output requires direct PHP file functions.
		fclose( $out );
		exit;
	}

	/**
	 * Stream the export to a file on disk, for WP-CLI and code callers.
	 *
	 * Atomic: a sibling temp is checked (flush, size, closing brace) and then rename()d onto $path; a failed read gives false.
	 * See docs-ai/11-code-rationale.md, "DataExporter: atomic export file".
	 *
	 * @param string             $path Destination file path.
	 * @param array<int, string>      $sections Section keys to export. Empty, with no group either, = all.
	 * @param array<int, string>|null $groups   Add-on group parts to export (DataRegistry::addon_groups()
	 *                                          keys), or null for every group when the sections include
	 *                                          Add-on data.
	 * @return int|false Bytes written, or false on failure.
	 * @throws \Throwable Any non-RuntimeException; also random_bytes() when the CSPRNG is unavailable.
	 */
	public function write_to_file( string $path, array $sections = [], ?array $groups = null ) {
		[ $sections, $groups ] = $this->normalize_sections( $sections, $groups );

		// Sibling temp, so rename() never crosses devices; both halves stay native to share a UID.
		// 'xb' (O_EXCL) never opens an existing node, so no symlink is followed; rename() replaces a symlink at $path.
		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to disk; WP_Filesystem has no streaming API.
		$out = fopen( $tmp, 'xb' );

		if ( ! $out ) {
			return false;
		}

		self::track_temp_file( $tmp );

		try {
			$this->write_to( $out, $sections, $groups );
		} catch ( \Throwable $e ) {
			// Nothing was published, so only the temp goes; the DB error is logged because the job's message names only the path.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to disk requires direct PHP file functions.
			fclose( $out );
			self::discard_temp_file( $tmp );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExporter: export aborted before publication - ' . $e->getMessage() );

			// Only export_table()'s RuntimeException becomes `false`; any other error (a hooked third party) must stay loud.
			if ( ! $e instanceof \RuntimeException ) {
				throw $e;
			}

			return false;
		}

		// Flush before fclose(): a full disk surfaces only at flush, not from each fwrite().
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Streaming to disk requires direct PHP file functions.
		$flushed = fflush( $out );
		$stat    = fstat( $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to disk requires direct PHP file functions.
		$closed = fclose( $out );

		if ( $flushed === false || $closed === false ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExporter: flush/close failed on ' . $path . ' - disk full or quota exhausted?' );
			self::discard_temp_file( $tmp );
			return false;
		}

		// A valid export ends with `}`: a truncated file is dropped, never handed back.
		$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		if ( $size < 2 ) {
			self::discard_temp_file( $tmp );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming validation needs fopen; @ suppresses the open-failure warning that the truthiness check on the next line handles.
		$fh = @fopen( $tmp, 'r' );
		if ( $fh ) {
			// The last 16 bytes: only whitespace may follow the closing brace (write_to() ends with "}\n").
			$tail_len = min( 16, $size );
			fseek( $fh, max( 0, $size - $tail_len ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading the closing bytes of a file we just wrote; WP_Filesystem reads the whole file which would defeat the streaming-validation purpose.
			$tail = fread( $fh, $tail_len );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.

			$trimmed = is_string( $tail ) ? rtrim( $tail ) : '';

			if ( $trimmed === '' || substr( $trimmed, -1 ) !== '}' ) {
				error_log( 'PerfLocale DataExporter: produced truncated JSON (tail: ' . var_export( $tail, true ) . ') - dropping the temp for ' . $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
				self::discard_temp_file( $tmp );
				return false;
			}
		}

		// Native rename() is atomic on one filesystem, so a reader never sees a partial export.
		// Not WP_Filesystem::move(): it can degrade to copy-then-delete, as another UID than the fopen() that wrote the temp.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- See above.
		if ( ! rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExporter: could not publish the export to ' . $path . ' - leaving it unwritten.' );
			self::discard_temp_file( $tmp );
			return false;
		}

		unset( self::$pending_temp_files[ $tmp ] );

		return $size;
	}

	/**
	 * Remember an in-flight temp file and arm the shutdown sweep once.
	 *
	 * @param string $tmp Absolute path to the temp file just created.
	 * @return void
	 */
	private static function track_temp_file( string $tmp ): void {
		self::$pending_temp_files[ $tmp ] = true;

		if ( self::$shutdown_registered ) {
			return;
		}

		self::$shutdown_registered = true;
		register_shutdown_function( [ self::class, 'sweep_pending_temp_files' ] );
	}

	/**
	 * Delete an unpublished temp file and forget it.
	 *
	 * @param string $tmp Absolute path to the temp file.
	 * @return void
	 */
	private static function discard_temp_file( string $tmp ): void {
		unset( self::$pending_temp_files[ $tmp ] );
		wp_delete_file( $tmp );
	}

	/**
	 * Shutdown sweep for temp files left by a write that died mid-stream (a memory_limit fatal).
	 *
	 * Public only for register_shutdown_function(); a published export is safe: its entry goes when rename() succeeds.
	 *
	 * @return void
	 */
	public static function sweep_pending_temp_files(): void {
		foreach ( array_keys( self::$pending_temp_files ) as $tmp ) {
			unset( self::$pending_temp_files[ $tmp ] );

			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Every section and every group when the caller chose neither; otherwise what the caller chose.
	 *
	 * @param array<int, string>      $sections Raw section keys.
	 * @param array<int, string>|null $groups   Group parts, or null.
	 * @return array{0: array<int, string>, 1: array<int, string>|null}
	 */
	private function normalize_sections( array $sections, ?array $groups ): array {
		if ( empty( $sections ) && empty( $groups ) ) {
			return [ array_keys( self::sections() ), null ];
		}

		return [ $sections, null === $groups ? null : array_values( $groups ) ];
	}

	/**
	 * Strip every setting whose key looks like a credential, at any depth, and every credential-carrying URL value.
	 *
	 * The key is omitted, never rewritten: Settings::update() merges, so an import keeps the target's live value.
	 * See docs-ai/11-code-rationale.md, "DataExporter: credential redaction".
	 *
	 * @param array<string, mixed> $settings Raw settings array.
	 * @param int                  $depth    Recursion depth; callers pass nothing (see REDACT_MAX_DEPTH).
	 * @return array<string, mixed>
	 */
	public static function redact_credentials( array $settings, int $depth = 0 ): array {
		foreach ( $settings as $key => $value ) {
			if ( preg_match( self::CREDENTIAL_KEY_PATTERN, (string) $key ) ) {
				unset( $settings[ $key ] );
				continue;
			}

			if ( is_array( $value ) ) {
				if ( $depth >= self::REDACT_MAX_DEPTH ) {
					unset( $settings[ $key ] );
					continue;
				}

				// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
				/** @var array<string, mixed> $value */
				$settings[ $key ] = self::redact_credentials( $value, $depth + 1 );
				continue;
			}

			if ( is_string( $value ) && ( self::has_url_userinfo( $value ) || self::has_url_query_credential( $value ) ) ) {
				unset( $settings[ $key ] );
			}
		}

		return $settings;
	}

	/**
	 * Whether the whole trimmed value parses as a URL with a user or pass part.
	 *
	 * Whole value only: dropping a prose setting that mentions a URL would be silent data loss in the export.
	 *
	 * @param string $value Raw setting value.
	 * @return bool
	 */
	private static function has_url_userinfo( string $value ): bool {
		$value = trim( $value );

		if ( $value === '' || ! str_contains( $value, '@' ) || ! str_contains( $value, '://' ) ) {
			return false;
		}

		$parts = wp_parse_url( $value );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		return ( isset( $parts['user'] ) && $parts['user'] !== '' )
			|| ( isset( $parts['pass'] ) && $parts['pass'] !== '' );
	}

	/**
	 * Whether the whole trimmed value parses as a URL with a query parameter named like a credential.
	 *
	 * @param string $value Raw setting value.
	 * @return bool
	 */
	private static function has_url_query_credential( string $value ): bool {
		$value = trim( $value );

		if ( $value === '' || ! str_contains( $value, '?' ) || ! str_contains( $value, '://' ) ) {
			return false;
		}

		$parts = wp_parse_url( $value );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['query'] ) ) {
			return false;
		}

		foreach ( explode( '&', (string) $parts['query'] ) as $pair ) {
			$name = rawurldecode( (string) strstr( $pair . '=', '=', true ) );
			$name = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', $name );

			if ( '' !== $name && 1 === preg_match( self::CREDENTIAL_QUERY_PATTERN, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip password fields and credential-shaped keys out of every add-on's settings entry (`addon_id => fields`).
	 *
	 * The add-on id level is not key-matched; a nested credential is lost on restore, as the importer restores top-level keys only.
	 *
	 * @param array<string, mixed> $addon_settings
	 * @return array<string, mixed>
	 */
	public static function redact_addon_credentials( array $addon_settings ): array {
		foreach ( $addon_settings as $addon_id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( self::password_setting_keys( (string) $addon_id ) as $password_key ) {
				unset( $entry[ $password_key ] );
			}
			$addon_settings[ $addon_id ] = self::redact_credentials( $entry );
		}
		return $addon_settings;
	}

	/**
	 * The keys of an add-on's settings fields whose type is password, from its registered schema (none when unavailable).
	 *
	 * DataImporter keeps the target's value for such a key when an import leaves it out.
	 *
	 * @param string $addon_id Add-on ID.
	 * @return array<int, string>
	 */
	public static function password_setting_keys( string $addon_id ): array {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$registry = $plugin->has( 'addon_registry' ) ? $plugin->get( 'addon_registry' ) : null;
		$addon    = $registry instanceof \PerfLocale\Addon\AddonRegistry ? ( $registry->get_addons()[ $addon_id ] ?? null ) : null;

		if ( ! $addon instanceof \PerfLocale\Addon\AddonInterface ) {
			return [];
		}

		try {
			$fields = $addon->get_settings_fields();
		} catch ( \Throwable $e ) {
			return [];
		}

		$keys = [];

		foreach ( $fields as $key => $field ) {
			if ( 'password' === ( $field['type'] ?? '' ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Snapshot the PerfLocale role and capability state: the full Translator role, caps_version, grant_roles() grants.
	 *
	 * Other roles' caps are filtered to `perflocale_*`, so no unrelated plugin's capabilities leak into the export.
	 *
	 * @return array{caps_version:int, translator:array<string,bool>, role_grants:array<string, array<string,bool>>}
	 */
	public static function snapshot_roles(): array {
		$translator_caps = [];
		$translator      = get_role( TranslatorRole::ROLE_SLUG );

		if ( $translator instanceof \WP_Role ) {
			foreach ( (array) $translator->capabilities as $cap => $grant ) {
				$translator_caps[ (string) $cap ] = (bool) $grant;
			}
		}

		$role_grants = [];
		foreach ( self::grant_roles() as $role_slug ) {
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
	 * The roles whose PerfLocale capabilities the Roles section carries (and the importer restores).
	 *
	 * Administrator, editor, the `perflocale/roles/cap_roles` roles and Shop Manager, whose grant record travels too.
	 *
	 * @return array<int, string>
	 */
	public static function grant_roles(): array {
		/** This filter is documented in src/Database/SiteCleanup.php. */
		$roles = (array) apply_filters( 'perflocale/roles/cap_roles', [ 'administrator', 'editor' ] );
		$roles = array_merge( [ 'administrator', 'editor' ], array_map( static fn( $r ): string => sanitize_key( Typed::str( $r ) ), $roles ), [ 'shop_manager' ] );

		return array_values( array_unique( array_filter( $roles ) ) );
	}

	/**
	 * Write one chunk to the export stream, or abort the export.
	 *
	 * A short fwrite() shows only in its return value; a mid-file hole would pass the flush and tail checks.
	 *
	 * @param resource     $out   Open write stream.
	 * @param string|false $chunk Bytes to write, or false from a failed encode.
	 * @return void
	 *
	 * @throws \RuntimeException When the chunk could not be encoded, or could not be written in full.
	 */
	/**
	 * JSON-encode a value for the export stream, or abort the export.
	 *
	 * JSON_THROW_ON_ERROR skips wp_json_encode()'s lossy fallback, which would turn invalid UTF-8 into "?" in a backup.
	 * See docs-ai/11-code-rationale.md, "DataExporter: lossless JSON encoding".
	 *
	 * @param mixed  $value   Value to encode.
	 * @param string $context Human-readable name of what is being encoded.
	 * @return string Encoded JSON.
	 * @throws \RuntimeException When the value cannot be encoded losslessly.
	 */
	private static function encode_or_fail( $value, string $context ): string {
		try {
			$encoded = wp_json_encode( $value, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: what was being encoded, 2: the JSON encoder's message. */
						'PerfLocale export: %1$s could not be encoded as JSON (%2$s). Refusing to publish an export with unreadable bytes in it - the previous export file has been left untouched. This usually means the database connection charset does not match the column charset; compare DB_CHARSET in wp-config.php with the tables\' own charset before changing either.',
						$context,
						$e->getMessage()
					)
				),
				0,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- FALSE POSITIVE. The sniff treats every argument of a thrown exception as output. This is the third constructor argument, $previous: a Throwable object that is never rendered. The MESSAGE argument above is wrapped in esc_html(), which is the one the rule exists to protect. Escaping $e here would be a type error.
				$e
			);
		}

		// wp_json_encode() can still return false without raising (a depth overrun).
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: what was being encoded. */
						'PerfLocale export: %s could not be encoded as JSON. Refusing to publish a partial export.',
						$context
					)
				)
			);
		}

		return $encoded;
	}

	private static function write_chunk( $out, $chunk ): void {
		if ( ! is_string( $chunk ) ) {
			throw new \RuntimeException( 'Export aborted: a section could not be encoded as JSON.' );
		}

		$length = strlen( $chunk );

		if ( 0 === $length ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write; WP_Filesystem has no streaming API.
		$written = fwrite( $out, $chunk );

		if ( false === $written || $written < $length ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						'Export aborted: wrote %1$d of %2$d bytes (disk full, quota exhausted, or the stream refused the write).',
						(int) $written,
						$length
					)
				)
			);
		}
	}

	/**
	 * The address this export is stamped with (`site_url`), shared with network-export's slices.
	 *
	 * The raw `home` option, not home_url(): PerfLocale's filter can add a language path, subdomain or domain.
	 *
	 * @return string Untrailingslashed site address.
	 */
	public static function site_address(): string {
		$home = get_option( 'home' );

		return is_string( $home ) ? untrailingslashit( $home ) : '';
	}

	/**
	 * Whether two strings may share 1.0.7.5's hash: the switch-over is not `done` (twins it has not counted yet), P1 kept
	 * twin pairs, or a bar context's `d|c|t` is another bar context's or a bar-free tuple's identity (stored under it, or
	 * under its pre-release form).
	 *
	 * @return bool True also when a read failed.
	 */
	private static function legacy_collision(): bool {
		$wpdb  = Typed::wpdb();
		$state = json_decode( Typed::str( get_option( \PerfLocale\Strings\StrIdentMigration::OPTION, '' ) ), true );

		if ( ! is_array( $state ) || 'done' !== ( $state['phase'] ?? '' ) || ! empty( $state['held_n'] ) ) {
			return true;
		}

		$table = Schema::table( 'strings' );
		$seen  = [];
		$after = 0;

		do {
			$rows = self::read_rows( (string) $wpdb->prepare( 'SELECT id, domain, context, original FROM %i WHERE id > %d AND context LIKE %s ORDER BY id LIMIT 500', $table, $after, '%' . $wpdb->esc_like( '|' ) . '%' ) );

			if ( null === $rows ) {
				return true;
			}

			$probe = [];

			foreach ( $rows as $row ) {
				$id                          = Typed::int( $row['id'] ?? null );
				[ $domain, $context, $text ] = [ Typed::str( $row['domain'] ?? '' ), Typed::str( $row['context'] ?? '' ), Typed::str( $row['original'] ?? '' ) ];
				$legacy                      = \PerfLocale\Strings\StringTranslation::identity_hash( $domain, $context, $text, true );
				$bar                         = (int) strpos( $context, '|' );

				if ( isset( $seen[ $legacy ] ) ) {
					return true;
				}

				$seen[ $legacy ]  = true;
				$probe[ $legacy ] = $id;
				// The bar-free tuple whose identity is $legacy, under its pre-release form.
				$probe[ \PerfLocale\Strings\StringTranslation::identity_hash( $domain, substr( $context, 0, $bar ), substr( $context, $bar + 1 ) . '|' . $text, true ) ] = 0;
				$after = $id;
			}

			if ( [] === $probe ) {
				break;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One %s per hash.
			$held = self::read_rows( (string) $wpdb->prepare( 'SELECT id, original_hash FROM %i WHERE original_hash IN (' . implode( ',', array_fill( 0, count( $probe ), '%s' ) ) . ')', $table, ...array_keys( $probe ) ) );

			if ( null === $held ) {
				return true;
			}

			foreach ( $held as $row ) {
				// Only the row itself, still under its 1.0.7.5 hash, holds its own legacy hash.
				if ( ( $probe[ Typed::str( $row['original_hash'] ?? '' ) ] ?? 0 ) !== Typed::int( $row['id'] ?? null ) ) {
					return true;
				}
			}
		} while ( 500 === count( $rows ) );

		return false;
	}

	/**
	 * Format 1: a batch of strings rows under 1.0.7.5's `domain|context|text` (the other form for a context with a bar),
	 * each hash recorded for legacy_unique().
	 *
	 * @param array<mixed> $rows A batch of strings rows.
	 * @return list<array<string, mixed>>
	 * @throws \RuntimeException When a hash cannot be recorded.
	 */
	private function legacy_rows( array $rows ): array {
		$out = [];

		foreach ( Typed::rows( $rows ) as $row ) {
			$context = Typed::str( $row['context'] ?? '' );
			$legacy  = \PerfLocale\Strings\StringTranslation::identity_hash( Typed::str( $row['domain'] ?? '' ), $context, Typed::str( $row['original'] ?? '' ), str_contains( $context, '|' ) );
			$raw     = (string) hex2bin( $legacy );
			$part    = ord( $raw[0] ) >> 4;

			if ( ! isset( $this->legacy_parts[ $part ] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A php://temp scratch stream.
				$stream = fopen( 'php://temp/maxmemory:262144', 'w+b' );

				if ( false === $stream ) {
					throw new \RuntimeException( 'PerfLocale export: recording a string identity failed - refusing to emit a partial export.' );
				}

				$this->legacy_parts[ $part ] = $stream;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A php://temp scratch stream.
			if ( 32 !== fwrite( $this->legacy_parts[ $part ], $raw ) ) {
				throw new \RuntimeException( 'PerfLocale export: recording a string identity failed - refusing to emit a partial export.' );
			}

			$this->legacy_counts[ $part ] = ( $this->legacy_counts[ $part ] ?? 0 ) + 1;

			$row['original_hash'] = $legacy;
			$out[]                 = $row;
		}

		return $out;
	}

	/**
	 * Format 1: no two emitted strings share a 1.0.7.5 hash (1.0.7.5 would keep one of them), also for rows added, or
	 * deleted and added, while the export ran.
	 *
	 * One bucket is loaded at a time: measured 20 MB peak for a 1M-row strings table, the supported scale.
	 *
	 * @return void
	 * @throws \RuntimeException On a shared hash or a read of the record that is not whole.
	 */
	private function legacy_unique(): void {
		$parts               = $this->legacy_parts;
		$counts              = $this->legacy_counts;
		$this->legacy_parts  = [];
		$this->legacy_counts = [];

		foreach ( $parts as $i => $part ) {
			$data = is_resource( $part ) && rewind( $part ) ? stream_get_contents( $part ) : false;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A php://temp scratch stream.
			is_resource( $part ) && fclose( $part );

			if ( ! is_string( $data ) || strlen( $data ) !== 32 * ( $counts[ $i ] ?? 0 ) ) {
				throw new \RuntimeException( 'PerfLocale export: reading the recorded string identities failed - refusing to emit a partial export.' );
			}

			$list = '' === $data ? [] : str_split( $data, 32 );

			if ( count( $list ) !== count( array_unique( $list ) ) ) {
				throw new \RuntimeException( 'PerfLocale export: two strings share a 1.0.7.5 identity - refusing to emit a file that would lose one.' );
			}
		}
	}

	/**
	 * A read's rows (ARRAY_A), or null when it failed.
	 *
	 * @param string $sql Prepared statement.
	 * @return list<array<string, mixed>>|null
	 */
	private static function read_rows( string $sql ): ?array {
		$wpdb = Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the caller.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) && '' === $wpdb->last_error ? Typed::rows( $rows ) : null;
	}

	/**
	 * Shared streaming logic used by download() and write_to_file().
	 *
	 * @param resource                $out      Output stream.
	 * @param array<int, string>      $sections Normalized section keys.
	 * @param array<int, string>|null $groups   Group parts, or null (see export_parts()).
	 * @return void
	 * @throws \RuntimeException When a batch read fails or a stream write is refused or short; both callers catch it.
	 */
	private function write_to( $out, array $sections, ?array $groups = null ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to a JSON output stream; WP_Filesystem has no streaming API and download() targets php://output.

		// A blog below database version 2 has no automation table: the file does not declare the section, so a
		// Replace import of it leaves the target's automation history alone.
		if ( ! \PerfLocale\Automation\ChangeService::available() ) {
			$sections = array_values( array_diff( $sections, [ 'automation' ] ) );
		}

		// 1.0.7.5 would keep one of two strings sharing its hash: such a file is format 2, which 1.0.7.5 refuses before any write.
		$this->format        = in_array( 'strings', self::tables_for( $sections )[0], true ) && self::legacy_collision() ? 2 : self::FORMAT_VERSION;
		$this->legacy_parts  = [];
		$this->legacy_counts = [];

		self::write_chunk( $out, "{\n" );
		self::write_chunk( $out, '"perflocale_export": true,' . "\n" );
		self::write_chunk( $out, '"version": "' . PERFLOCALE_VERSION . '",' . "\n" );
		self::write_chunk( $out, '"format_version": ' . $this->format . ',' . "\n" );
		self::write_chunk( $out, '"exported_at": "' . gmdate( 'c' ) . '",' . "\n" );
		self::write_chunk( $out, '"site_url": ' . self::encode_or_fail( self::site_address(), 'site_url' ) . ',' . "\n" );
		self::write_chunk( $out, '"sections": ' . self::encode_or_fail( $sections, 'sections' ) . ',' . "\n" );
		self::write_chunk( $out, '"object_max_ids": ' . self::encode_or_fail( ExportItems::object_max_ids(), 'object_max_ids' ) . ',' . "\n" );

		// Each add-on part travels as its own row lists in `data`; group parts are named, with labels, next to `sections`.
		$parts  = self::export_parts( $sections, $groups );
		$labels = array_intersect_key( DataRegistry::addon_groups(), array_flip( $parts ) );

		if ( [] !== $labels ) {
			self::write_chunk( $out, '"' . DataRegistry::ADDON_GROUPS_KEY . '": ' . self::encode_or_fail( $labels, DataRegistry::ADDON_GROUPS_KEY ) . ',' . "\n" );
		}

		if ( in_array( 'settings', $sections, true ) ) {
			$settings = get_option( 'perflocale_settings', [] );

			if ( is_array( $settings ) ) {
				$settings = self::redact_credentials( $settings );
			}

			self::write_chunk( $out, '"settings": ' . self::encode_or_fail( $settings, 'settings' ) . ',' . "\n" );

			// Add-on settings travel with 'settings', redacted the same way, so a staging to prod clone keeps add-on config.
			$addon_settings = get_option( 'perflocale_addon_settings', [] );

			if ( is_array( $addon_settings ) ) {
				$addon_settings = self::redact_addon_credentials( $addon_settings );
			}

			self::write_chunk( $out, '"addon_settings": ' . self::encode_or_fail( $addon_settings, 'addon_settings' ) . ',' . "\n" );

			// The disabled add-ons travel too, else a clone is "configured but disabled"; an empty list means none disabled.
			$disabled_addons = (array) get_option( 'perflocale_disabled_addons', [] );
			self::write_chunk( $out, '"disabled_addons": ' . self::encode_or_fail( array_values( array_filter( array_map( 'strval', $disabled_addons ) ) ), 'disabled_addons' ) . ',' . "\n" );
		}

		if ( in_array( 'roles', $sections, true ) ) {
			self::write_chunk( $out, '"roles": ' . self::encode_or_fail( self::snapshot_roles(), 'roles' ) . ',' . "\n" );
		}

		[ $tables, $scopes ] = self::tables_for( $sections );

		// The sections' options and meta (ExportItems) follow the tables as row lists, then each add-on part's lists.
		$items   = DataRegistry::section_row_lists( ...array_values( array_map( 'strval', $sections ) ) );
		$members = [];

		foreach ( $parts as $part ) {
			foreach ( DataRegistry::part_row_lists( $part ) as $list ) {
				$members[] = [ $part, $list ];
			}
		}

		self::write_chunk( $out, '"data": {' . "\n" );

		$table_count = count( $tables ) + count( $items ) + count( $members );
		$i           = 0;

		foreach ( $tables as $table_name ) {
			$is_last = ( $i === $table_count - 1 );
			$this->export_table( $out, $table_name, $is_last, $scopes[ $table_name ] ?? null );
			++$i;
		}

		$shared = array_values( array_diff( $sections, [ DataRegistry::ADDON_SECTION ] ) );

		foreach ( $items as $pseudo ) {
			$is_last = ( $i === $table_count - 1 );
			$kind    = (string) array_search( $pseudo, DataRegistry::ROW_LISTS, true );
			self::export_item_rows( $out, $pseudo, $pseudo, self::row_list_items( $kind, $shared ), $is_last );
			++$i;
		}

		foreach ( $members as [ $part, $pseudo ] ) {
			$is_last = ( $i === $table_count - 1 );
			$kind    = (string) array_search( $pseudo, DataRegistry::ROW_LISTS, true );
			self::export_item_rows( $out, DataRegistry::part_member( $part, $pseudo ), $pseudo, self::part_items( $kind, $part ), $is_last );
			++$i;
		}

		self::write_chunk( $out, '}' );

		/**
		 * Filter the extra top-level sections an add-on adds to the export envelope.
		 *
		 * Section name => JSON-serialisable value; the `perflocale/import/section/<name>` action imports it.
		 * Names in DataExporter::RESERVED_SECTION_KEYS are dropped.
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
				'format_version' => $this->format,
			]
		);

		foreach ( $external_sections as $name => $payload ) {
			// Add-ons append, never replace a reserved key; the charset keeps the import hook name well-formed.
			if (
				! is_string( $name )
				|| $name === ''
				|| in_array( $name, self::RESERVED_SECTION_KEYS, true )
				|| ! preg_match( '/^[A-Za-z0-9_-]+$/', $name )
			) {
				continue;
			}

			// Lossless, as every core section: an unencodable section stops the export.
			$encoded = self::encode_or_fail( $payload, 'add-on section ' . $name );

			self::write_chunk( $out, ',' . "\n" );
			// The key is encoded too, so the envelope is well-formed by construction.
			self::write_chunk( $out, self::encode_or_fail( (string) $name, 'addon section name' ) . ': ' . $encoded );
		}

		self::write_chunk( $out, "\n}\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * The tables a set of sections exports, and the type scope of each.
	 *
	 * @param array<int, string> $sections Section keys.
	 * @return array{0: array<int, string>, 1: array<string, string|null>} Tables in export order; table => type scope (null = whole table).
	 */
	private static function tables_for( array $sections ): array {
		$tables = [];
		$scopes = [];

		$all = self::sections();

		foreach ( $sections as $section ) {
			if ( ! isset( $all[ $section ] ) ) {
				continue;
			}

			foreach ( $all[ $section ]['tables'] as $table_name ) {
				$tables[] = $table_name;

				$scope = $all[ $section ]['type_scope'][ $table_name ] ?? null;

				// Widest claim wins in any section order: an unscoped claim (null) needs every row.
				if ( $scope === null || ! array_key_exists( $table_name, $scopes ) ) {
					$scopes[ $table_name ] = $scope;
				}
			}
		}

		return [ array_values( array_unique( $tables ) ), $scopes ];
	}

	/**
	 * Rows the tables of these sections hold (each type-scoped table counts its slice): an export's size in rows.
	 *
	 * Options and meta row lists are not counted. One COUNT per table.
	 *
	 * @param array<int, string>      $sections Section keys. Empty, with no group either, = all.
	 * @param array<int, string>|null $groups   Group parts, or null.
	 * @return int
	 */
	public function row_count( array $sections = [], ?array $groups = null ): int {
		$wpdb = Typed::wpdb();

		[ $tables, $scopes ] = self::tables_for( $this->normalize_sections( $sections, $groups )[0] );

		$total = 0;

		foreach ( $tables as $table_name ) {
			$scope = $scopes[ $table_name ] ?? null;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = null === $scope
				? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( $table_name ) ) )
				: $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s', Schema::table( $table_name ), $scope ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$total += is_numeric( $count ) ? max( 0, (int) $count ) : 0;
		}

		return $total;
	}

	/**
	 * About how many bytes an export of these sections would write, for the screen's import size warning.
	 *
	 * Each table's row count times the average encoded size of its first 200 rows: two queries per table.
	 *
	 * @param array<int, string> $sections Section keys. Empty = all.
	 * @return int
	 */
	public function estimate_bytes( array $sections = [] ): int {
		$wpdb = Typed::wpdb();

		[ $tables, $scopes ] = self::tables_for( $this->normalize_sections( $sections, null )[0] );

		// The envelope, settings and roles: small next to any table.
		$total = 8192;

		foreach ( $tables as $table_name ) {
			$full_table = Schema::table( $table_name );
			$scope      = $scopes[ $table_name ] ?? null;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = null === $scope
				? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $full_table ) )
				: $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s', $full_table, $scope ) );
			$count = is_numeric( $count ) ? (int) $count : 0;

			$this->counted[ $table_name ] = $count;

			if ( $count <= 0 ) {
				continue;
			}

			// pending_changes values are counted by their sizes, never read: one can be megabytes.
			[ $select, $select_args ] = self::select_of( $table_name, 0 );

			$sample = null === $scope
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- select_of()'s fixed SQL.
				? $wpdb->get_results( $wpdb->prepare( 'SELECT ' . $select . ' FROM %i LIMIT 200', array_merge( $select_args, [ $full_table ] ) ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE type = %s LIMIT 200', $full_table, $scope ), ARRAY_A );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! is_array( $sample ) || [] === $sample ) {
				continue;
			}

			$bytes = 0;

			foreach ( $sample as $row ) {
				$bytes += strlen( (string) wp_json_encode( 'pending_changes' === $table_name ? self::automation_export_row( (array) $row ) : $row ) ) + 2;

				foreach ( [ 'payload', 'summary' ] as $column ) {
					$size   = \PerfLocale\Util\Scalar::int( ( (array) $row )[ $column . '_bytes' ] ?? 0 );
					$bytes += $size <= self::MAX_IMPORT_VALUE_BYTES && ( 'summary' === $column || 'pending' === ( ( (array) $row )['status'] ?? '' ) ) ? $size : 0;
				}
			}

			$total += (int) ceil( $bytes / count( $sample ) * $count );
		}

		return $total;
	}

	/**
	 * Rows the last estimate_bytes() counted in a table (0 when it did not count it).
	 *
	 * @param string $table_name Table name without prefix.
	 * @return int
	 */
	public function counted_rows( string $table_name ): int {
		return $this->counted[ $table_name ] ?? 0;
	}

	/**
	 * Write one row list of options or meta (see ExportItems).
	 *
	 * @param resource                                                                                             $out     Output stream.
	 * @param string                                                                                               $member  The `data` member it is written as.
	 * @param string                                                                                               $pseudo  Row list (ROW_LISTS value).
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5: string}> $items   The items it carries.
	 * @param bool                                                                                                 $is_last Whether this is the last member of `data`.
	 * @return void
	 * @throws \RuntimeException When a read fails or a row cannot be encoded.
	 */
	private static function export_item_rows( $out, string $member, string $pseudo, array $items, bool $is_last ): void {
		self::write_chunk( $out, self::encode_or_fail( $member, 'row list name' ) . ': [' . "\n" );

		$first = true;

		foreach ( ExportItems::export_rows( $pseudo, $items ) as $row ) {
			self::write_chunk( $out, ( $first ? '' : ',' . "\n" ) . self::encode_or_fail( $row, $member . ' row' ) );
			$first = false;
		}

		self::write_chunk( $out, "\n" . ']' . ( $is_last ? '' : ',' ) . "\n" );
	}

	/**
	 * Add each row's object fingerprint (ObjectFingerprint::FIELD).
	 *
	 * @param array<int, array<string, mixed>> $rows        Rows of one batch.
	 * @param string                           $type_column The column that says whether `object_id` is a post or a term.
	 * @return array<int, array<string, mixed>>
	 */
	public static function with_fingerprints( array $rows, string $type_column ): array {
		$ids = [];

		foreach ( $rows as $row ) {
			$kind = ObjectFingerprint::kind( $row[ $type_column ] ?? null );

			if ( '' !== $kind && is_numeric( $row['object_id'] ?? null ) ) {
				$ids[ $kind ][] = (int) $row['object_id'];
			}
		}

		$found = [];

		foreach ( $ids as $kind => $list ) {
			$found[ $kind ] = ObjectFingerprint::for_ids( $kind, $list );
		}

		foreach ( $rows as $i => $row ) {
			$kind = ObjectFingerprint::kind( $row[ $type_column ] ?? null );
			$id   = is_numeric( $row['object_id'] ?? null ) ? (int) $row['object_id'] : 0;

			if ( '' !== $kind && isset( $found[ $kind ][ $id ] ) ) {
				$rows[ $i ][ ObjectFingerprint::FIELD ] = $found[ $kind ][ $id ];
			}
		}

		return $rows;
	}

	/**
	 * The select list of a table read and its arguments: `*`, or for pending_changes its columns with `previous`, a
	 * decided row's `payload` and any value over `$inline` bytes left in the database (megabytes per row), plus their sizes.
	 *
	 * @param string $table_name Unprefixed table name.
	 * @param int    $inline     Bytes up to which payload and summary are read in the row.
	 * @return array{0: literal-string, 1: list<int>}
	 */
	private static function select_of( string $table_name, int $inline ): array {
		if ( 'pending_changes' !== $table_name ) {
			return [ '*', [] ];
		}

		$sqlite = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE;
		$p      = $sqlite ? 'LENGTH(HEX(payload)) / 2' : 'LENGTH(payload)';
		$s      = $sqlite ? 'LENGTH(HEX(summary)) / 2' : 'LENGTH(summary)';

		return [ self::PENDING_CHANGES_HEAD . ", CASE WHEN status = 'pending' AND {$p} <= %d THEN payload ELSE '' END AS payload, CASE WHEN {$s} <= %d THEN summary ELSE '' END AS summary, " . self::PENDING_CHANGES_TAIL . ", CASE WHEN status = 'pending' THEN {$p} ELSE 0 END AS payload_bytes, {$s} AS summary_bytes", [ $inline, $inline ] ];
	}

	/**
	 * A pending_changes row with each value its page left out read alone (one at a time, so a page never holds them);
	 * a value over the importer's limit stays out (automation_export_row() sees its size).
	 *
	 * @param array<mixed> $row    Row of a select_of() read.
	 * @param int          $inline The bytes the page read inline.
	 * @param string       $table  Full table name.
	 * @return array<mixed>
	 * @throws \RuntimeException When the read fails, so a value is never exported empty.
	 */
	private static function with_large_values( array $row, int $inline, string $table ): array {
		$read = [];

		foreach ( [ 'payload', 'summary' ] as $column ) {
			$size = \PerfLocale\Util\Scalar::int( $row[ $column . '_bytes' ] ?? 0 );

			if ( $size > $inline && $size <= self::MAX_IMPORT_VALUE_BYTES ) {
				$read[] = $column;
			}
		}

		if ( [] === $read ) {
			return $row;
		}

		$wpdb = Typed::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column names from the fixed list above.
		$values = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ', ', $read ) . ' FROM %i WHERE id = %d', $table, \PerfLocale\Util\Scalar::int( $row['id'] ?? 0 ) ), ARRAY_A );

		if ( '' !== (string) $wpdb->last_error || ! is_array( $values ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'PerfLocale export: reading a value of table "%1$s" failed (%2$s) - refusing to emit a partial export.', $table, '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'row gone' ) ) );
		}

		foreach ( $read as $column ) {
			$row[ $column ] = \PerfLocale\Util\Scalar::str( $values[ $column ] ?? '' );
		}

		return $row;
	}

	/**
	 * The exported form of one automation change: never `previous`, `payload` only for a pending change, no intent.
	 * A pending payload or summary over the importer's per-value limit travels as superseded (`export_too_large`),
	 * so the rest of the file is never refused.
	 *
	 * @param array<mixed> $row Table row.
	 * @return array<mixed>
	 */
	private static function automation_export_row( array $row ): array {
		$row['previous'] = null;
		$summary_large   = \PerfLocale\Util\Scalar::int( $row['summary_bytes'] ?? 0 ) > self::MAX_IMPORT_VALUE_BYTES || strlen( \PerfLocale\Util\Scalar::str( $row['summary'] ?? '' ) ) > self::MAX_IMPORT_VALUE_BYTES;
		$payload_large   = \PerfLocale\Util\Scalar::int( $row['payload_bytes'] ?? 0 ) > self::MAX_IMPORT_VALUE_BYTES || strlen( \PerfLocale\Util\Scalar::str( $row['payload'] ?? '' ) ) > self::MAX_IMPORT_VALUE_BYTES;

		unset( $row['payload_bytes'], $row['summary_bytes'] );

		if ( $summary_large ) {
			$row['summary'] = '{"export_too_large":true}';
		}

		if ( 'string' === ( $row['object_type'] ?? '' ) && in_array( $row['result_status'] ?? '', \PerfLocale\Database\Repository\PendingChangeRepository::INTENTS, true ) ) {
			$row['result_status'] = '';
		}

		if ( 'pending' !== ( $row['status'] ?? '' ) ) {
			$row['payload'] = '';

			return $row;
		}

		if ( $summary_large || $payload_large ) {
			$row['payload']    = '';
			$row['status']     = 'superseded';
			$row['reason']     = 'export_too_large';
			$row['decided_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		return $row;
	}

	/**
	 * Export a single table in batches.
	 *
	 * @param resource    $out Output stream.
	 * @param string      $table_name Short table name (without prefix).
	 * @param bool        $is_last Whether this is the last table.
	 * @param string|null $type_scope Emit only the rows whose `type` column holds this value; null emits the whole table.
	 * @return void
	 * @throws \RuntimeException When a batch SELECT fails, so a partial table never passes as a complete one.
	 */
	private function export_table( $out, string $table_name, bool $is_last, ?string $type_scope = null ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to php://output requires direct PHP file functions.
		global $wpdb;

		$full_table = Schema::table( $table_name );

		self::write_chunk( $out, '"' . $table_name . '": [' . "\n" );

		// Keyset cursors: every export table has an `id` PK except string_translations, keyed on (string_id, language_id).
		$has_id    = ( 'string_translations' !== $table_name );
		$last_id   = 0;
		$last_str  = 0;
		$last_lang = 0;
		$first_row = true;

		/**
		 * Rows read per batch during a streaming export, clamped to 50-10000.
		 *
		 * Higher values mean fewer queries; lower values a smaller peak memory.
		 *
		 * @hook perflocale/export/batch_size
		 * @param int    $size       Default 1000.
		 * @param string $table_name Table being exported, so callers can vary the size per table.
		 */
		$batch_size = (int) apply_filters( 'perflocale/export/batch_size', self::BATCH_SIZE, $table_name );
		$batch_size = max( 50, min( 10000, $batch_size ) );
		$fp_column  = self::fingerprint_columns()[ $table_name ] ?? null;
		$inline     = intdiv( self::PENDING_PAGE_BYTES, 2 * $batch_size );

		[ $select, $select_args ] = self::select_of( $table_name, $inline );

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// Keyset, not OFFSET: OFFSET is O(n^2), and a concurrent write shifts its window so rows are skipped or duplicated.
			if ( $has_id && $type_scope !== null ) {
				// Owned slice of a polymorphic table: the type is bound as a value, so the scope cannot widen the query.
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
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- select_of()'s SQL brings its own placeholders; the array holds their values.
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- select_of()'s fixed SQL.
						'SELECT ' . $select . ' FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
						array_merge( $select_args, [ $full_table, $last_id, $batch_size ] )
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

			// A failed read returns [] like an empty table; a replace restore of that backup would empty the table.
			// wpdb resets last_error before each query, so it is this SELECT's; the instanceof only narrows for PHPStan.
			// See docs-ai/11-code-rationale.md, "DataExporter: failed batch read".
			$db_error = $wpdb instanceof \wpdb ? (string) $wpdb->last_error : '';

			if ( $db_error !== '' || ! is_array( $rows ) ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							'PerfLocale export: reading table "%1$s" failed (%2$s) - refusing to emit a partial export.',
							$table_name,
							$db_error !== '' ? $db_error : 'no result set'
						)
					)
				);
			}

			// Rows naming a post or term by ID carry its fingerprint (ObjectFingerprint), one query per kind and batch.
			if ( null !== $fp_column && [] !== $rows ) {
				$rows = self::with_fingerprints( Typed::rows( $rows ), $fp_column );
			}

			if ( 1 === $this->format && 'strings' === $table_name ) {
				$rows = $this->legacy_rows( $rows );
			}

			foreach ( $rows as $row ) {
				if ( 'pending_changes' === $table_name && is_array( $row ) ) {
					$row = self::automation_export_row( self::with_large_values( $row, $inline, $full_table ) );
				}

				if ( ! $first_row ) {
					self::write_chunk( $out, ',' . "\n" );
				}

				self::write_chunk( $out, self::encode_or_fail( $row, 'table row' ) );
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

		if ( 1 === $this->format && 'strings' === $table_name ) {
			$this->legacy_unique();
		}

		self::write_chunk( $out, "\n" . ']' . ( $is_last ? '' : ',' ) . "\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
}
