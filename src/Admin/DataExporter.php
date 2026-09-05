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
	 * Maximum nesting depth {@see self::redact_credentials()} walks before it
	 * drops a subtree wholesale.
	 *
	 * A settings blob arrives from `get_option()` via `maybe_unserialize()`,
	 * and a serialized payload can carry an `R:` back-reference that makes the
	 * array cyclic — an unbounded walk would then recurse until the stack
	 * dies. Real config is one to three levels deep, so 8 is generous. Past
	 * the cap the value is DROPPED rather than passed through: an un-walked
	 * subtree is exactly the leak the recursion exists to close, so the cap
	 * fails closed.
	 */
	private const REDACT_MAX_DEPTH = 8;

	/**
	 * Temp files a `write_to_file()` call has created but not yet published,
	 * keyed by absolute path.
	 *
	 * Every ordinary exit path — success, validation failure, exception —
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
	 * @param array<int, string> $sections Section keys to export. Empty = all.
	 * @return void Dies after sending the file.
	 * @throws \Throwable Re-raised for any non-RuntimeException failure, so a
	 *                    bug in our code or in a hooked third party is not
	 *                    disguised as an aborted-export envelope.
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

		try {
			$this->write_to( $out, $sections );
		} catch ( \Throwable $e ) {
			// A table read failed mid-stream (see export_table()). Headers
			// and part of the body are already sent, so there is no clean
			// HTTP error to return — but the envelope is UNTERMINATED, which
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
	 * concurrent reader — the download endpoint, a backup agent, an anonymous
	 * GET on a host that ignores .htaccess — sees either no file or the
	 * previous complete one, never a half-written export.
	 *
	 * Converts the ONE expected failure to `false`: {@see self::export_table()}
	 * raises a RuntimeException on a failed read, and this method turns that
	 * into the same `false` every other write failure returns. It is not
	 * exception-proof — random_bytes() below runs before the try and throws if
	 * the CSPRNG is unavailable, and any non-RuntimeException from a hooked
	 * third party is deliberately re-raised rather than swallowed. Callers
	 * ({@see \PerfLocale\Background\Jobs\DataExportJob}, the CLI `export` and
	 * `export-network` commands) key on that return value.
	 *
	 * @param string             $path Destination file path.
	 * @param array<int, string> $sections Section keys to export. Empty = all.
	 * @return int|false Bytes written, or false on failure.
	 * @throws \Throwable Re-raised for any non-RuntimeException failure; also
	 *                    random_bytes() if the CSPRNG is unavailable.
	 */
	public function write_to_file( string $path, array $sections = [] ) {
		$sections = $this->normalize_sections( $sections );

		// Sibling temp, same directory, so rename() below is a same-filesystem
		// move and never a cross-device copy. Same shape as
		// TranslationFileGenerator::write_l10n_file(); the divergence is that
		// THAT function moves through $wp_filesystem because it wrote through
		// $wp_filesystem, whereas this one streams natively (WP_Filesystem has
		// no streaming API), so both halves here stay native and share a UID.
		//
		// 'xb' is O_CREAT|O_EXCL: it refuses to open ANY pre-existing node,
		// which is what closes the symlink hole — the writer can no longer be
		// steered through a planted final-component symlink the way
		// fopen( $path, 'w' ) could, because $path is never opened at all now.
		// rename() REPLACES a symlink sitting at $path rather than following
		// it. The random component also means the temp name is at least as
		// unguessable as the export name it is derived from.
		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to disk; WP_Filesystem has no streaming API.
		$out = fopen( $tmp, 'xb' );

		if ( ! $out ) {
			return false;
		}

		self::track_temp_file( $tmp );

		try {
			$this->write_to( $out, $sections );
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
			// third party hooked into the export must stay loud — swallowing it
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
				error_log( 'PerfLocale DataExporter: produced truncated JSON (tail: ' . var_export( $tail, true ) . ') - dropping the temp for ' . $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
				self::discard_temp_file( $tmp );
				return false;
			}
		}

		// Every gate passed — publish. This is the first and only moment the
		// export becomes visible at $path, and rename() is atomic within a
		// filesystem, so no reader can ever observe a partial one.
		// Atomicity IS the point: rename(2) is atomic within a filesystem, so a
		// concurrent reader sees either the previous complete export or the new
		// one, never a partial file. WP_Filesystem::move() gives no such
		// guarantee and can degrade to copy-then-delete, reintroducing the very
		// window this closes — and it would run as a different UID than the
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
	 * is a no-op unless a write died mid-stream — a memory_limit fatal on a
	 * very large export is the realistic case — and it can never touch a
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
	 * Two things this does that the flat version did not:
	 *
	 * 1. It RECURSES. The old walk read `array_keys()` at the top level only,
	 *    so `addon_settings[x]['nested']['access_token']` travelled verbatim
	 *    while `addon_settings[x]['access_token']` was stripped — whether a
	 *    secret survived depended on how deep its addon happened to nest it.
	 * 2. It looks at VALUES, not only keys, for `scheme://user:pass@host`
	 *    userinfo. `mt_libre_url` / `mt_agency_url` are ordinary URL settings
	 *    matching no credential suffix, and `esc_url_raw()` keeps both the
	 *    `:` and the `@`, so a self-hosted endpoint configured with inline
	 *    HTTP auth used to export its password in plain sight.
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

			if ( is_string( $value ) && self::has_url_userinfo( $value ) ) {
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
	 * prose value does not qualify — dropping a whole setting because it
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
	 * Strip credential-shaped fields out of every addon's settings entry.
	 *
	 * Shape: `[ addon_id => [ field_key => value, ... ] ]`. Walks each
	 * inner entry through the same suffix list as redact_credentials() so
	 * an addon's `*_api_key` / `*_token` / etc. never ride along on a
	 * staging → prod export. Per-addon credentials stay on the exporting
	 * site; operators re-enter them on import.
	 *
	 * That inner walk is RECURSIVE (see redact_credentials()), so a secret an
	 * addon nested inside its own sub-array is stripped too. Mind the
	 * asymmetry this creates with the importer: `DataImporter` restores a
	 * credential key the export omitted only at the TOP level of an addon
	 * entry, so a NESTED credential is not carried across a restore and has
	 * to be re-entered. Losing it is the intended trade — the alternative is
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
	 * Shared streaming logic used by download() and write_to_file().
	 *
	 * @param resource           $out Output stream.
	 * @param array<int, string> $sections Normalized section keys (non-empty).
	 * @return void
	 * @throws \RuntimeException Propagated from {@see self::export_table()} when a
	 *                           batch read fails, and from {@see self::write_chunk()}
	 *                           when a stream write is refused or short. Both
	 *                           callers catch it.
	 */
	private function write_to( $out, array $sections ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to a JSON output stream; WP_Filesystem has no streaming API and download() targets php://output.

		self::write_chunk( $out, "{\n" );
		self::write_chunk( $out, '"perflocale_export": true,' . "\n" );
		self::write_chunk( $out, '"version": "' . PERFLOCALE_VERSION . '",' . "\n" );
		self::write_chunk( $out, '"format_version": ' . (int) self::FORMAT_VERSION . ',' . "\n" );
		self::write_chunk( $out, '"exported_at": "' . gmdate( 'c' ) . '",' . "\n" );
		self::write_chunk( $out, '"site_url": ' . wp_json_encode( home_url() ) . ',' . "\n" );
		self::write_chunk( $out, '"sections": ' . wp_json_encode( $sections ) . ',' . "\n" );

		if ( in_array( 'settings', $sections, true ) ) {
			$settings = get_option( 'perflocale_settings', [] );

			if ( is_array( $settings ) ) {
				$settings = self::redact_credentials( $settings );
			}

			self::write_chunk( $out, '"settings": ' . wp_json_encode( $settings ) . ',' . "\n" );

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

			self::write_chunk( $out, '"addon_settings": ' . wp_json_encode( $addon_settings ) . ',' . "\n" );

			// Operator's enable/disable choices per addon. Without this,
			// a staging → prod clone would carry an addon's settings but
			// leave the target's disabled-list intact, producing a silent
			// "configured but disabled" state mismatch. Empty array on
			// the source means "nothing disabled" and must be preserved
			// literally — that's a meaningful operator intent.
			$disabled_addons = (array) get_option( 'perflocale_disabled_addons', [] );
			self::write_chunk( $out, '"disabled_addons": ' . wp_json_encode( array_values( array_filter( array_map( 'strval', $disabled_addons ) ) ) ) . ',' . "\n" );
		}

		if ( in_array( 'roles', $sections, true ) ) {
			self::write_chunk( $out, '"roles": ' . wp_json_encode( self::snapshot_roles() ) . ',' . "\n" );
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

		self::write_chunk( $out, '"data": {' . "\n" );

		$table_count = count( $tables );
		$i           = 0;

		foreach ( $tables as $table_name ) {
			$is_last = ( $i === $table_count - 1 );
			$this->export_table( $out, $table_name, $is_last, $scopes[ $table_name ] ?? null );
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

			self::write_chunk( $out, ',' . "\n" );
			// Encode the KEY too so a name with a quote/backslash can never
			// produce invalid JSON (the charset guard already rejects those;
			// this keeps the envelope well-formed by construction anyway).
			self::write_chunk( $out, wp_json_encode( (string) $name ) . ': ' . $encoded );
		}

		self::write_chunk( $out, "\n}\n" );
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
	 * @throws \RuntimeException When a batch SELECT fails. A partial table must
	 *                          never be emitted as a complete one — see the
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

			// A FAILED read is not an empty table, and the two used to be
			// indistinguishable here. wpdb::get_results( …, ARRAY_A ) builds
			// `$new_array = array()` and only fills it `if ( $this->last_result )`,
			// so a lost connection, a killed query, a max_allowed_packet
			// overrun or a crashed table hands back `[]` — which sails past
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
			// wpdb::query() calls flush() before every query — which resets
			// last_error to '' — and assigns it from mysqli_error()
			// afterwards, so a non-empty value here belongs to the SELECT
			// above and to nothing before it. Costs no extra query. The
			// instanceof is PHPStan narrowing, not a runtime guard (the
			// $wpdb global reads as `mixed` in this file — see the existing
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

			foreach ( $rows as $row ) {
				if ( ! $first_row ) {
					self::write_chunk( $out, ',' . "\n" );
				}

				self::write_chunk( $out, wp_json_encode( $row ) );
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
