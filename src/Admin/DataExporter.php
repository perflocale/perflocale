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
	 * (redacted) credentials, and only the core exporter may write them.
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
	];

	/**
	 * Pattern identifying credential-shaped setting keys. Shared by the
	 * exporter (which redacts these so secrets never travel in a backup) and
	 * the importer (which preserves the target's existing value for a key the
	 * export redacted, so restoring a backup doesn't blank live credentials).
	 * Keeping it in one place stops the two sides from drifting.
	 *
	 * Matched without regard to case, anywhere in the key: `_api_key`,
	 * `_apikey`, `_secret`, `_password`, `_credential(s)`, `_encrypted`, the
	 * credential names PostTranslationManager keeps out of a translation's
	 * meta. `_token` and `_auth` match only as a whole name part (followed by
	 * `_` or the end), so `max_tokens` and `show_author` are not credentials.
	 * `_key` matches at the end only.
	 */
	public const CREDENTIAL_KEY_PATTERN = '/_api_?key|_secret|_password|_credentials?|_encrypted|_token(?:_|$)|_auth(?:_|$)|_key$/i';

	/**
	 * A URL query parameter name that carries a credential: a whole name,
	 * optionally after a prefix ending in `_` or `-` (`api_key`, `x-api-key`,
	 * `access_token`, `client_secret`, `sig`, `code`). A camelCase name is
	 * matched in its snake_case form (`accessToken` as `access_Token`).
	 */
	private const CREDENTIAL_QUERY_PATTERN = '/^(?:[a-z0-9]+[_-])*(?:api[_-]?key|apikey|key|token|auth|authorization|secret|password|passwd|pass|pwd|sig|signature|code|credentials?)$/i';

	/**
	 * Export sections with their labels and tables, from the data registry
	 * (DataRegistry::sections(), in the order of the export screen).
	 *
	 * The optional `type_scope` narrows a table a section SHARES with another
	 * section down to the slice it owns: table => the value of that table's
	 * polymorphic `type` column. A table claimed WITHOUT a scope by any
	 * selected section is exported whole - the widest claim wins. String
	 * Translations carries the type='string' slice of `translation_groups`
	 * (its strings' groups) next to `strings` and `string_translations`, and
	 * not the string links, which TranslationFileGenerator::repair_orphaned_translations()
	 * rebuilds on import. Media, Menus, Item flags and Add-on data hold no
	 * table: their options and meta travel as row lists (ExportItems).
	 *
	 * @return array<string, array{label: string, tables: list<string>, type_scope?: array<string, string>}>
	 */
	public static function sections(): array {
		return DataRegistry::sections();
	}

	/**
	 * Tables whose rows name a post or term by `object_id`, and the column
	 * that says which (`post`, `term`; a `string` row has no fingerprint):
	 * the registry's `object_ref:<type column>` columns.
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
	 * The options or meta of one kind that a set of sections carries as row
	 * lists, from the registry: [ kind, name (with {lang} or {id}), the
	 * object it sits on or null, the merge rule, the import sanitizer an
	 * add-on's entry declares or null, the name's regular expression ].
	 * Entries that travel under their own
	 * envelope key (settings, roles) are not among them.
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
	 * The options or meta of one kind that one add-on part carries, in the
	 * shape of {@see self::row_list_items()}.
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
	 * The add-on data that has its own checkbox on the export and import
	 * screens: group part (`<owner>/<group id>`) => label.
	 *
	 * @return array<string, string>
	 */
	public static function addon_groups(): array {
		return DataRegistry::addon_groups();
	}

	/**
	 * Whether a value has the shape of an add-on data choice: `addon_data`
	 * (the shared checkbox) or a group part (`<owner>/<group id>`).
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
	 * The add-on parts an export carries, in registry order: the parts
	 * without a group when the sections include Add-on data, and the chosen
	 * group parts.
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
	 * Maximum nesting depth {@see self::redact_credentials()} walks before it
	 * drops a subtree wholesale.
	 *
	 * A settings blob arrives from `get_option()` via `maybe_unserialize()`,
	 * and a serialized payload can carry an `R:` back-reference that makes the
	 * array cyclic - an unbounded walk would then recurse until the stack
	 * dies. Real config is one to three levels deep, so 8 is generous. Past
	 * the cap the value is DROPPED rather than passed through: an un-walked
	 * subtree could hold credentials the walk never checked, so the cap fails
	 * closed.
	 */
	private const REDACT_MAX_DEPTH = 8;

	/**
	 * Temp files a `write_to_file()` call has created but not yet published,
	 * keyed by absolute path.
	 *
	 * Every ordinary exit path - success, validation failure, exception -
	 * removes its own entry, so this is empty except while a write is in
	 * flight. {@see self::sweep_pending_temp_files()} is the net for the
	 * EXTRAordinary exits (a memory_limit fatal or a killed worker mid-export)
	 * which would otherwise leave a partial export sitting in a web-served
	 * uploads directory until the 7-day Helper::gc_stale_upload_files() sweep.
	 *
	 * Paths are absolute, so this static carries no per-blog state.
	 *
	 * @var array<string, bool>
	 */
	private static array $pending_temp_files = [];

	/**
	 * Whether the shutdown sweep has been registered for this request.
	 *
	 * Registered once, not once per call: `wp perflocale export-network` runs
	 * one write_to_file() per site in a single process, and PHP has no way to
	 * unregister a shutdown function.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Export selected sections and send as an HTTP JSON download.
	 *
	 * Thin wrapper around {@see self::write_to()} that writes to php://output
	 * with browser-download headers, then exits. CLI + programmatic callers
	 * should use {@see self::write_to_file()} instead - that path returns
	 * without exiting so the caller can log, verify, and clean up.
	 *
	 * @param array<int, string>      $sections Section keys to export. Empty, with no group either, = all.
	 * @param array<int, string>|null $groups   Add-on group parts to export (DataRegistry::addon_groups()
	 *                                          keys), or null for every group when the sections include
	 *                                          Add-on data.
	 * @return void Dies after sending the file.
	 * @throws \Throwable Re-raised for any non-RuntimeException failure, so a
	 *                    bug in our code or in a hooked third party is not
	 *                    disguised as an aborted-export envelope.
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
			// A table read failed mid-stream (see export_table()). Headers
			// and part of the body are already sent, so there is no clean
			// HTTP error to return - but the envelope is UNTERMINATED, which
			// means what the browser saved is not parseable JSON and
			// DataImporter will refuse it. That is the point: a partial dump
			// must never look like a complete backup. Name the reason in the
			// body so the operator does not have to guess, log it for the
			// host, and stop without the closing brace.
			//
			// Catching here rather than letting it propagate is deliberate:
			// download() is invoked from AdminController's admin_init
			// handler, where an uncaught RuntimeException would be a fatal
			// and a white screen. This route is cap-gated
			// (perflocale_import_export, Administrator-only), so naming the
			// DB error in the body discloses nothing new.
			// Same narrowing as write_to_file(): only export_table()'s
			// RuntimeException is an expected mid-stream failure. Anything else
			// is a bug in our code or in a third party hooked into the export,
			// and must not be disguised as an aborted-export envelope. Re-raise
			// BEFORE writing the abort marker, so the body is not half-written
			// on a path that is about to fatal anyway.
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
	 * Stream the export to a file on disk.
	 *
	 * Used by the WP-CLI `perflocale export` command and by any programmatic
	 * caller that needs a file without the HTTP download dance. Preserves
	 * the streaming memory profile of {@see self::download()}.
	 *
	 * Publication is ATOMIC: the stream goes into a sibling temp file, the
	 * existing flush / size / trailing-brace validation runs on THAT, and only
	 * a file that passed all three is rename()d onto $path. Nothing exists at
	 * $path until this method is about to return the byte count, so a
	 * concurrent reader - the download endpoint, a backup agent, an anonymous
	 * GET on a host that ignores .htaccess - sees either no file or the
	 * previous complete one, never a half-written export.
	 *
	 * Converts the ONE expected failure to `false`: {@see self::export_table()}
	 * raises a RuntimeException on a failed read, and this method turns that
	 * into the same `false` every other write failure returns. It is not
	 * exception-proof - random_bytes() below runs before the try and throws if
	 * the CSPRNG is unavailable, and any non-RuntimeException from a hooked
	 * third party is deliberately re-raised rather than swallowed. Callers
	 * ({@see \PerfLocale\Background\Jobs\DataExportJob}, the CLI `export` and
	 * `export-network` commands) key on that return value.
	 *
	 * @param string             $path Destination file path.
	 * @param array<int, string>      $sections Section keys to export. Empty, with no group either, = all.
	 * @param array<int, string>|null $groups   Add-on group parts to export (DataRegistry::addon_groups()
	 *                                          keys), or null for every group when the sections include
	 *                                          Add-on data.
	 * @return int|false Bytes written, or false on failure.
	 * @throws \Throwable Re-raised for any non-RuntimeException failure; also
	 *                    random_bytes() if the CSPRNG is unavailable.
	 */
	public function write_to_file( string $path, array $sections = [], ?array $groups = null ) {
		[ $sections, $groups ] = $this->normalize_sections( $sections, $groups );

		// Sibling temp, same directory, so rename() below is a same-filesystem
		// move and never a cross-device copy. Same shape as
		// TranslationFileGenerator::write_l10n_file(); the divergence is that
		// THAT function moves through $wp_filesystem because it wrote through
		// $wp_filesystem, whereas this one streams natively (WP_Filesystem has
		// no streaming API), so both halves here stay native and share a UID.
		//
		// 'xb' is O_CREAT|O_EXCL: it refuses to open ANY pre-existing node,
		// so the writer never follows a symlink, and $path itself is never
		// opened. rename() REPLACES a symlink sitting at $path rather than
		// following it. The random component also means the temp name is at
		// least as unguessable as the export name it is derived from.
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
			// export_table() throws on a failed read rather than closing a
			// half-read table cleanly. Nothing was published, so the only
			// cleanup is the temp; the caller gets the same `false` it gets
			// for any other write failure, and DataExportJob turns that into
			// a failed job with a visible error. The DB message goes to the
			// log because the job's own message only carries the path.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to disk requires direct PHP file functions.
			fclose( $out );
			self::discard_temp_file( $tmp );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale DataExporter: export aborted before publication - ' . $e->getMessage() );

			// Only export_table()'s RuntimeException is an expected failure and
			// becomes `false`. A TypeError or any other Throwable raised by a
			// third party hooked into the export must stay loud - swallowing it
			// would report a clean "export failed" for a bug that is not ours
			// and is not a failed read.
			if ( ! $e instanceof \RuntimeException ) {
				throw $e;
			}

			return false;
		}

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
			self::discard_temp_file( $tmp );
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
			self::discard_temp_file( $tmp );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming validation needs fopen; @ suppresses the open-failure warning that the truthiness check on the next line handles.
		$fh = @fopen( $tmp, 'r' );
		if ( $fh ) {
			// Read the last 16 bytes so we can tolerate trailing
			// whitespace (write_to() ends with "}\n"). Anything past
			// the closing brace must be whitespace only - a truncated
			// dump tends to end mid-string / mid-token, never with a
			// well-balanced `}` followed by `\n`.
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

		// Every gate passed - publish. This is the first and only moment the
		// export becomes visible at $path, and rename() is atomic within a
		// filesystem, so no reader can ever observe a partial one.
		// Atomicity IS the point: rename(2) is atomic within a filesystem, so a
		// concurrent reader sees either the previous complete export or the new
		// one, never a partial file. WP_Filesystem::move() gives no such
		// guarantee and can degrade to copy-then-delete, reintroducing the very
		// window this closes - and it would run as a different UID than the
		// fopen() that wrote the temp (see TranslationFileGenerator::write_l10n_file()'s
		// docblock), so both halves of this write must stay native. Sibling
		// path, so never cross-device.
		//
		// The ignore sits directly on the call because phpcs:ignore covers only
		// the line that follows it.
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
	 * Shutdown sweep for temp files no ordinary exit path reached.
	 *
	 * Public only because `register_shutdown_function()` needs a callable. It
	 * is a no-op unless a write died mid-stream - a memory_limit fatal on a
	 * very large export is the realistic case - and it can never touch a
	 * PUBLISHED export: write_to_file() drops the entry the instant rename()
	 * succeeds, and the published name is not the temp name.
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
	 * Every section and every group when the caller chose neither a section
	 * nor a group; otherwise what the caller chose.
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
	 * Strip every setting whose key looks like a credential from the export.
	 *
	 * Covers every key {@see self::CREDENTIAL_KEY_PATTERN} matches (API keys,
	 * tokens, secrets, passwords, credentials, ...) by name, so future
	 * additions are redacted by default. Exports are commonly shared as
	 * backups - credentials must never travel.
	 *
	 * Two rules:
	 *
	 * 1. It RECURSES, so a credential key is stripped however deep its addon
	 *    nests it.
	 * 2. It looks at VALUES, not only keys, for `scheme://user:pass@host`
	 *    userinfo and for a query parameter named like a credential
	 *    ({@see self::CREDENTIAL_QUERY_PATTERN}). `mt_libre_url` /
	 *    `mt_agency_url` are ordinary URL settings matching no credential
	 *    name, and `esc_url_raw()` keeps the `:`, the `@` and the query.
	 *
	 * Both cases OMIT the key rather than rewriting the value, and that is
	 * deliberate. `Settings::update()` merges (`array_merge( $current,
	 * $sanitized )`), so an omitted key leaves the target's live value alone
	 * on import, whereas a rewritten one would OVERWRITE a working endpoint
	 * with a credential-less copy and break the provider silently on every
	 * restore. Omission is also what the importer already expects of this
	 * function: `DataImporter` restores a credential key the export omitted.
	 *
	 * @param array<string, mixed> $settings Raw settings array.
	 * @param int                  $depth    Recursion depth; callers pass
	 *                                       nothing. See REDACT_MAX_DEPTH for
	 *                                       why the walk is bounded and why
	 *                                       the cap DROPS rather than passes
	 *                                       an un-walked subtree through.
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
	 * Does this value parse as a URL carrying inline credentials?
	 *
	 * Deliberately strict: the WHOLE trimmed value must parse as a URL with a
	 * user or pass component. A credential-shaped substring inside a longer
	 * prose value does not qualify - dropping a whole setting because it
	 * happens to mention a URL would be silent data loss in the export, which
	 * is the failure mode this file works hardest to avoid. The two cheap
	 * string tests short-circuit before wp_parse_url() for the ~120 settings
	 * that are not URLs at all.
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
	 * Does this value parse as a URL whose query names a credential?
	 *
	 * As strict as {@see self::has_url_userinfo()}: the WHOLE trimmed value
	 * must parse as a URL, and a parameter name must match
	 * {@see self::CREDENTIAL_QUERY_PATTERN}.
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
	 * Strip credential-shaped fields out of every addon's settings entry.
	 *
	 * Shape: `[ addon_id => [ field_key => value, ... ] ]`. A field the
	 * add-on's settings schema declares as a password is left out whatever
	 * its key ({@see self::password_setting_keys()}). Each inner entry is
	 * then walked through the same key pattern as redact_credentials() so
	 * an addon's `*_api_key` / `*_token` / etc. never ride along on a
	 * staging → prod export. Per-addon credentials stay on the exporting
	 * site; operators re-enter them on import.
	 *
	 * That inner walk is RECURSIVE (see redact_credentials()), so a secret an
	 * addon nested inside its own sub-array is stripped too. Mind the
	 * asymmetry this creates with the importer: `DataImporter` restores a
	 * credential key the export omitted only at the TOP level of an addon
	 * entry, so a NESTED credential is not carried across a restore and has
	 * to be re-entered. Losing it is the intended trade - the alternative is
	 * shipping it in every backup. The addon-id level itself is deliberately
	 * NOT key-matched here: only the entries are walked, exactly as before.
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
	 * The keys of an add-on's settings fields whose type is password.
	 *
	 * Read from the settings schema (get_settings_fields()) of the add-on as
	 * registered in this request; an add-on that is not registered, or whose
	 * schema throws, has none, and its keys are judged by name alone.
	 * DataImporter keeps the target's value for such a key when an import
	 * leaves it out, as it does for a credential-named key.
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
	 * Snapshot the PerfLocale role + capability state for export.
	 *
	 * Captures:
	 * - The Translator role's full capabilities map (in case an admin added
	 *   custom caps via User Role Editor or similar).
	 * - PerfLocale-prefixed caps granted to the roles grant_roles() lists
	 *   (administrator, editor, the `perflocale/roles/cap_roles` roles and
	 *   the Shop Manager), so custom grants come back - e.g. an admin who
	 *   gave Editors `perflocale_manage_languages`.
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
	 * The roles whose PerfLocale capabilities the Roles section carries:
	 * administrator and editor, every role the site lists in
	 * `perflocale/roles/cap_roles`, and WooCommerce's Shop Manager (its grant
	 * record travels with the section). The importer restores grants for the
	 * same list.
	 *
	 * @return array<int, string>
	 */
	public static function grant_roles(): array {
		/** This filter is documented in src/Admin/TranslatorRole.php */
		$roles = (array) apply_filters( 'perflocale/roles/cap_roles', [ 'administrator', 'editor' ] );
		$roles = array_merge( [ 'administrator', 'editor' ], array_map( static fn( $r ): string => sanitize_key( Typed::str( $r ) ), $roles ), [ 'shop_manager' ] );

		return array_values( array_unique( array_filter( $roles ) ) );
	}

	/**
	 * Write one chunk to the export stream, or abort the export.
	 *
	 * `fwrite()` reports a short or refused write in its RETURN VALUE and
	 * nowhere else. Discarding it meant a quota or device error partway
	 * through a dump left a hole in the middle of the file while the final
	 * writes still succeeded - so the flush check passed, the tail check saw
	 * a well-formed `}`, and rename() published unparseable JSON over the
	 * operator's previous, valid backup. The failure surfaced only when they
	 * tried to restore it.
	 *
	 * Throwing keeps the streaming design intact (nothing is buffered to
	 * compare afterwards) and lands in {@see write_to_file()}'s existing
	 * RuntimeException handler, which discards the temp and publishes
	 * nothing; on the download path it emits the abort marker instead.
	 *
	 * @param resource     $out   Open write stream.
	 * @param string|false $chunk Bytes to write, or false from a failed encode.
	 * @return void
	 *
	 * @throws \RuntimeException When the chunk could not be encoded, or could
	 *                           not be written in full.
	 */
	/**
	 * JSON-encode a value for the export stream, or abort the export.
	 *
	 * `JSON_THROW_ON_ERROR` is the whole point. Plain `wp_json_encode()` falls
	 * back to `_wp_json_sanity_check()`, which pushes every string through
	 * `mb_convert_encoding( $s, 'UTF-8', 'UTF-8' )` and replaces each byte it
	 * cannot read with a question mark. In a BACKUP that is the worst outcome
	 * available: the damaged value encodes cleanly, so the size gate, the
	 * tail-brace gate and `rename()` all pass, and the corrupted file is
	 * published over the operator's previous good one. The loss surfaces at
	 * restore time, when the good copy is gone. With the flag the encoder throws
	 * instead, and nothing is published. The flag is passed straight through to
	 * `json_encode()` as wp_json_encode()'s first statement, so the lossy
	 * fallback is unreachable; output for an encodable value is byte-identical.
	 *
	 * Throws rather than returning false because six call sites concatenate the
	 * result into a larger string before handing it to write_chunk(); a `false`
	 * there becomes '' and the concatenation is still a string, so
	 * write_chunk()'s own `is_string()` guard could never fire.
	 *
	 * Scope, stated honestly: this catches bytes that are not valid UTF-8, which
	 * is what a utf8 column read over a non-UTF-8 connection produces for the
	 * connection charset's own repertoire. It CANNOT catch a character MySQL
	 * already replaced with a literal "?" on the wire because the results
	 * charset could not represent it at all - that arrives as valid ASCII and
	 * encodes cleanly. Detecting the latter needs a charset check on the
	 * connection, not on the value.
	 *
	 * JsonException is caught and re-thrown as RuntimeException on purpose:
	 * JsonException extends Exception, and both callers re-raise anything that
	 * is not a RuntimeException, which would turn an unencodable byte into an
	 * uncaught fatal on the admin download route.
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

		// wp_json_encode() can still return false for a depth overrun without
		// raising, so the contract is completed here rather than assumed.
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
	 * The address this export is stamped with, under the `site_url` key.
	 *
	 * The RAW `home` option, not `home_url()`: PerfLocale filters `home_url`
	 * to carry the current language, and outside the admin that filter can
	 * return a language-prefixed path, a language subdomain or an entirely
	 * different configured domain. An export written from such a request
	 * would record an address the importer could not match back to this site,
	 * and the operator would be told their own backup came from somewhere
	 * else. The `home` option is the one value that is the same on every
	 * request.
	 *
	 * The network envelope in `wp perflocale network-export` stamps each
	 * slice with this same value, so the two producers cannot drift.
	 *
	 * @return string Untrailingslashed site address.
	 */
	public static function site_address(): string {
		$home = get_option( 'home' );

		return is_string( $home ) ? untrailingslashit( $home ) : '';
	}

	/**
	 * Shared streaming logic used by download() and write_to_file().
	 *
	 * @param resource                $out      Output stream.
	 * @param array<int, string>      $sections Normalized section keys.
	 * @param array<int, string>|null $groups   Group parts, or null (see export_parts()).
	 * @return void
	 * @throws \RuntimeException Propagated from {@see self::export_table()} when a
	 *                           batch read fails, and from {@see self::write_chunk()}
	 *                           when a stream write is refused or short. Both
	 *                           callers catch it.
	 */
	private function write_to( $out, array $sections, ?array $groups = null ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to a JSON output stream; WP_Filesystem has no streaming API and download() targets php://output.

		self::write_chunk( $out, "{\n" );
		self::write_chunk( $out, '"perflocale_export": true,' . "\n" );
		self::write_chunk( $out, '"version": "' . PERFLOCALE_VERSION . '",' . "\n" );
		self::write_chunk( $out, '"format_version": ' . (int) self::FORMAT_VERSION . ',' . "\n" );
		self::write_chunk( $out, '"exported_at": "' . gmdate( 'c' ) . '",' . "\n" );
		self::write_chunk( $out, '"site_url": ' . self::encode_or_fail( self::site_address(), 'site_url' ) . ',' . "\n" );
		self::write_chunk( $out, '"sections": ' . self::encode_or_fail( $sections, 'sections' ) . ',' . "\n" );

		// The add-on parts: each travels as its own row lists in `data`. The
		// group parts are named, with their labels, next to `sections`.
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

			// Per-addon settings (perflocale_addon_settings, keyed by addon
			// id). Travels with the 'settings' section because it's
			// conceptually the same surface from an operator POV: cloning
			// staging → prod should pick up addon-level config too, not
			// silently drop it. Each addon's entry is redacted with the
			// same CREDENTIAL_KEY_PATTERN as the main settings - any
			// addon's secrets stay on the exporting site.
			$addon_settings = get_option( 'perflocale_addon_settings', [] );

			if ( is_array( $addon_settings ) ) {
				$addon_settings = self::redact_addon_credentials( $addon_settings );
			}

			self::write_chunk( $out, '"addon_settings": ' . self::encode_or_fail( $addon_settings, 'addon_settings' ) . ',' . "\n" );

			// Operator's enable/disable choices per addon. Without this,
			// a staging → prod clone would carry an addon's settings but
			// leave the target's disabled-list intact, producing a silent
			// "configured but disabled" state mismatch. Empty array on
			// the source means "nothing disabled" and must be preserved
			// literally - that's a meaningful operator intent.
			$disabled_addons = (array) get_option( 'perflocale_disabled_addons', [] );
			self::write_chunk( $out, '"disabled_addons": ' . self::encode_or_fail( array_values( array_filter( array_map( 'strval', $disabled_addons ) ) ), 'disabled_addons' ) . ',' . "\n" );
		}

		if ( in_array( 'roles', $sections, true ) ) {
			self::write_chunk( $out, '"roles": ' . self::encode_or_fail( self::snapshot_roles(), 'roles' ) . ',' . "\n" );
		}

		[ $tables, $scopes ] = self::tables_for( $sections );

		// The options and meta the sections carry (ExportItems), as row
		// lists after the tables, then each add-on part's own row lists. An
		// importer that does not know a list skips it.
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
			// Reserved keys can't be overwritten - addons append, never
			// replace. addon_settings/disabled_addons are in the shared list
			// too: both carry redacted credentials, and only the core
			// exporter may write them. Names are also charset-restricted so
			// the symmetric import hook perflocale/import/section/<name>
			// stays well-formed.
			if (
				! is_string( $name )
				|| $name === ''
				|| in_array( $name, self::RESERVED_SECTION_KEYS, true )
				|| ! preg_match( '/^[A-Za-z0-9_-]+$/', $name )
			) {
				continue;
			}

			// Encoded with the same lossless rule as every core section: a
			// section that cannot be encoded stops the export rather than
			// being dropped or written with its bad bytes replaced.
			$encoded = self::encode_or_fail( $payload, 'add-on section ' . $name );

			self::write_chunk( $out, ',' . "\n" );
			// Encode the KEY too so a name with a quote/backslash can never
			// produce invalid JSON (the charset guard already rejects those;
			// this keeps the envelope well-formed by construction anyway).
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

				// Widest claim wins, whatever order the sections arrived in: a
				// section that asks for the table without a type scope needs
				// every row, so its null overrides any narrower claim and can
				// never be overridden by one.
				if ( $scope === null || ! array_key_exists( $table_name, $scopes ) ) {
					$scopes[ $table_name ] = $scope;
				}
			}
		}

		return [ array_values( array_unique( $tables ) ), $scopes ];
	}

	/**
	 * About how many bytes an export of these sections would write.
	 *
	 * Each table's row count times the average encoded size of its first 200
	 * rows: two queries per table, for the Export & Import screen, which warns
	 * when the export would be larger than this site accepts for an import.
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

			if ( $count <= 0 ) {
				continue;
			}

			$sample = null === $scope
				? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT 200', $full_table ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE type = %s LIMIT 200', $full_table, $scope ), ARRAY_A );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! is_array( $sample ) || [] === $sample ) {
				continue;
			}

			$bytes = 0;

			foreach ( $sample as $row ) {
				$bytes += strlen( (string) wp_json_encode( $row ) ) + 2;
			}

			$total += (int) ceil( $bytes / count( $sample ) * $count );
		}

		return $total;
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
	 * Export a single table in batches.
	 *
	 * @param resource    $out Output stream.
	 * @param string      $table_name Short table name (without prefix).
	 * @param bool        $is_last Whether this is the last table.
	 * @param string|null $type_scope Emit only the rows whose polymorphic `type`
	 *                                column holds this value (see sections());
	 *                                null emits the whole table.
	 * @return void
	 * @throws \RuntimeException When a batch SELECT fails. A partial table must
	 *                          never be emitted as a complete one - see the
	 *                          comment on the guard for what a partial export
	 *                          does to a replace-mode restore. Callers:
	 *                          write_to_file() converts this to `false`,
	 *                          download() aborts the stream.
	 */
	private function export_table( $out, string $table_name, bool $is_last, ?string $type_scope = null ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to php://output requires direct PHP file functions.
		global $wpdb;

		$full_table = Schema::table( $table_name );

		self::write_chunk( $out, '"' . $table_name . '": [' . "\n" );

		// Keyset pagination cursors. Every export table has an `id` PK except
		// string_translations, which is keyed on the (string_id, language_id)
		// tuple - paginate on that tuple there.
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
		 *                           vary per-table - heavier rows for
		 *                           `strings`, lighter for `languages`).
		 */
		$batch_size = (int) apply_filters( 'perflocale/export/batch_size', self::BATCH_SIZE, $table_name );
		$batch_size = max( 50, min( 10000, $batch_size ) );
		$fp_column  = self::fingerprint_columns()[ $table_name ] ?? null;

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

			// A FAILED read is not an empty table, and the two used to be
			// indistinguishable here. wpdb::get_results( …, ARRAY_A ) builds
			// `$new_array = array()` and only fills it `if ( $this->last_result )`,
			// so a lost connection, a killed query, a max_allowed_packet
			// overrun or a crashed table hands back `[]` - which sails past
			// the is_array() guard, writes no rows, leaves the keyset cursor
			// where it was and exits this do/while as an ordinary
			// end-of-table. The envelope then closes cleanly, the size and
			// trailing-brace gates in write_to_file() pass, and the job
			// reports success over a backup that is silently missing rows.
			//
			// Restore that in replace mode and it DESTROYS the table with no
			// error: DataImporter wipes first, its `if ( empty( $rows ) )
			// continue;` then skips the refill, and its zero-insert abort
			// needs `count( $rows ) > 0` to fire, so nothing catches it.
			// Failing loudly here is the only place this is catchable.
			//
			// wpdb::query() calls flush() before every query - which resets
			// last_error to '' - and assigns it from mysqli_error()
			// afterwards, so a non-empty value here belongs to the SELECT
			// above and to nothing before it. Costs no extra query. The
			// instanceof is PHPStan narrowing, not a runtime guard (the
			// $wpdb global reads as `mixed` in this file - see the existing
			// "Cannot call method get_results() on mixed" baseline entry);
			// the get_results() call above would already have fataled.
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

			// Rows that name a post or term by ID carry that object's
			// fingerprint, so an import can tell when the ID names a
			// different object (see ObjectFingerprint). One query per kind
			// and batch.
			if ( null !== $fp_column && [] !== $rows ) {
				$rows = self::with_fingerprints( Typed::rows( $rows ), $fp_column );
			}

			foreach ( $rows as $row ) {
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

		self::write_chunk( $out, "\n" . ']' . ( $is_last ? '' : ',' ) . "\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
}
