<?php
/**
 * Imports plugin data from a JSON export file.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\DataRegistry;
use PerfLocale\Database\Repository\SlugTranslationWrites;
use PerfLocale\Database\Schema;
use PerfLocale\Database\Txn;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports PerfLocale data from a JSON export file, read in pieces (ImportReader), in Replace or Merge mode.
 *
 * Merge re-inserts groups (orphans swept); object ids hold only on the source site or a copy; only Replace applies
 * configuration. Docs: docs-ai/11-code-rationale.md, "DataImporter: reading, merge groups and foreign ids".
 *
 * @phpstan-type ImportResult array{imported: int, skipped: int, errors: array<int, string>, sanitized: int, refused?: bool, not_applied?: array<int, string>, notice?: string, refused_addresses?: array<int, string>, address_notice?: string, notes?: array<int, string>, default_language_kept?: string, skipped_reasons?: array<string, int>, kept_settings?: array<int, string>, unread_sections?: array<int, string>, undeclared_sections?: array<int, string>, changed_matches?: array<string, int>}
 */
final class DataImporter {

	/**
	 * Rows per insert batch.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Crashed ReSync writes a Replace repairs before its open-intent check (the housekeeping's batch).
	 */
	private const RESYNC_REPAIR_BATCH = 250;

	/**
	 * Fewest and most rows per piece (perflocale/import/batch_size, and
	 * `wp perflocale import --batch-size`).
	 */
	public const BATCH_SIZE_MIN = 50;
	public const BATCH_SIZE_MAX = 5000;

	/**
	 * KEY_LOOKUP: per table, unique key index (DataRegistry::unique_keys()) => the column of its per-piece IN list. Tables
	 * not listed (languages, slug_translations) have text keys read whole once, compared as the database compares them.
	 */
	/**
	 * Largest row id an import accepts (2^53 - 1): Replace keeps the file's ids, and one near the BIGINT ceiling would move
	 * the next id there.
	 */
	private const MAX_ROW_ID = 9007199254740991;

	/**
	 * Fewest bytes a table row takes in an export file (`{}` and a comma), for the data-quality gate.
	 */
	private const MIN_ROW_BYTES = 3;

	/**
	 * Memory a Merge keeps per row of the file's largest table until it ends (id maps, anchors, linked posts), with a
	 * margin over the about 220 bytes measured per link row.
	 */
	private const RETAINED_BYTES_PER_ROW = 512;

	/**
	 * Share of the memory free at the start that the retained state may take, in percent.
	 */
	private const RETAINED_MEMORY_SHARE = 80;

	/**
	 * The row ceiling of 1.0.7.2 and earlier: the memory-based default never refuses a file those accepted.
	 */
	private const LEGACY_MAX_ROWS = 500000;

	/**
	 * Tables whose `type = 'string'` rows belong to String Translations, though Translation Links exports them whole.
	 */
	private const STRING_SLICE_TABLES = [ 'translation_groups', 'translation_links' ];

	/**
	 * Top-level keys that carry configuration (Replace only, apply_configuration()).
	 */
	private const CONFIGURATION_KEYS = [ 'settings', 'addon_settings', 'disabled_addons', 'roles' ];

	/**
	 * First version whose exports always record the site address and fingerprint every id-keyed row whose object exists;
	 * a file from it on lacking either is treated as from another site (import_from()).
	 */
	private const IDENTITY_SINCE = '1.0.7.3';

	private const KEY_LOOKUP = [
		'translation_groups'  => [ 0 => 'id' ],
		'translation_links'   => [
			0 => 'id',
			1 => 'group_id',
			2 => 'object_id',
		],
		'strings'             => [
			0 => 'id',
			1 => 'original_hash',
		],
		'string_translations' => [ 0 => 'string_id' ],
		'content_hashes'      => [
			0 => 'id',
			1 => 'object_id',
		],
	];

	/**
	 * The import's per-row bookkeeping while its tables are written (null otherwise): Merge's old => new id maps and link
	 * anchors, Replace's moved groups, and the post ids the links name (TemplateBlockRefs); a TEMPORARY table, so memory
	 * does not grow with the file.
	 *
	 * @var ImportWorkTable|null
	 */
	private ?ImportWorkTable $work = null;

	/**
	 * Languages table not cleared: this site's default language code, read at the file's first default row ('' for none;
	 * null until then).
	 *
	 * @var string|null
	 */
	private ?string $site_default = null;

	/**
	 * Languages table not cleared: the file's default language code when it was added as an ordinary language, else ''.
	 *
	 * @var string
	 */
	private string $default_kept = '';

	/**
	 * Whether this import cleared the languages table (Replace with the Languages section): only then may a file row
	 * become the default; otherwise import_piece() keeps this site's default.
	 *
	 * @var bool
	 */
	private bool $languages_cleared = false;

	/**
	 * Replace: what LanguageWrites::release_slugs() released for languages the file does not bring back; null when nothing.
	 *
	 * @var array{values: string, map: array{0: array<mixed>, 1: array<mixed>}|null}|null
	 */
	private ?array $released = null;

	/**
	 * Replace: the release holds option publication until this transaction ends (OptionWriter::hold()).
	 *
	 * @var bool
	 */
	private bool $release_held = false;

	/**
	 * Rows a check left out on purpose, by reason (see skip_reason_notes()).
	 *
	 * @var array<string, int>
	 */
	private array $skip_reasons = [];

	/**
	 * Whether this import carries `strings` rows (Merge then maps every string link through their id map).
	 *
	 * @var bool
	 */
	private bool $strings_carried = false;

	/**
	 * Whether this import added, replaced or removed language rows.
	 *
	 * @var bool
	 */
	private bool $languages_changed = false;

	/**
	 * Active language rows this import wrote, code => [ locale, name ]: the report names root posts their prefix takes.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $added_codes = [];

	/**
	 * Whether a Merge began writing: when it stops after that, what it wrote is finished (finish_stopped_merge()).
	 *
	 * @var bool
	 */
	private bool $merge_writing = false;

	/**
	 * Whether a Replace cleared translation_groups or translation_links (whole or part); the string-link reap and repair
	 * then run.
	 *
	 * @var bool
	 */
	private bool $replaced_link_tables = false;

	/**
	 * Whether a Replace cleared only the post and term rows of translation_groups (Translation Links without String
	 * Translations), so this site's string groups stay and can hold an id the file gives a post or term group.
	 *
	 * @var bool
	 */
	private bool $groups_keep_strings = false;

	/**
	 * Whether a Replace cleared only the post and term rows of translation_links, so this site's string links stay and
	 * can hold an id the file gives a post or term link.
	 *
	 * @var bool
	 */
	private bool $links_keep_strings = false;

	/**
	 * This site's language ids as keys, read on first use (after the languages table was imported).
	 *
	 * @var array<int, true>|null
	 */
	private ?array $language_id_set = null;

	/**
	 * Rows whose post or term changed since the export but was still recognised: matched part (guid, slug, date, name)
	 * => rows.
	 *
	 * @var array<string, int>
	 */
	private array $changed_matches = [];

	/**
	 * Term URL slug rows of slug_translations held back for restore_term_slugs(), with their sanitized flag.
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: bool}>
	 */
	private array $term_slug_rows = [];

	/**
	 * Replace: the open transaction's connection guard (null outside it), so a write wpdb resends on a new connection
	 * changes nothing.
	 *
	 * @var ConnectionGuard|null
	 */
	private ?ConnectionGuard $guard = null;

	/**
	 * Import data from a JSON file. Result keys: docs-ai/11-code-rationale.md, "DataImporter: configuration and the
	 * result".
	 *
	 * @param string               $file_path        Path to the JSON file.
	 * @param bool                 $replace          Replace all existing data (true) or merge; only Replace applies the
	 *                                               file's configuration.
	 * @param bool                 $sanitize_strings Sanitize string translation, language, slug and pending-change rows as the plugin's
	 *                                               own writers do for a user without `unfiltered_html` (the admin job
	 *                                               decides; WP-CLI passes false).
	 * @param array<string, mixed> $options          `allow_foreign_ids` (the operator confirmed this site is a copy of
	 *                                               the source; read with `! empty()`, a replayed job's value is 1, '1'
	 *                                               or true) and `addon_choices` (`addon_data` and `<owner>/<group id>`
	 *                                               parts to import; null: all the file carries).
	 * @return array{imported: int, skipped: int, errors: array<int, string>, sanitized: int, refused?: bool, not_applied?: array<int, string>, notice?: string, refused_addresses?: array<int, string>, address_notice?: string, notes?: array<int, string>, default_language_kept?: string, skipped_reasons?: array<string, int>, kept_settings?: array<int, string>, unread_sections?: array<int, string>, undeclared_sections?: array<int, string>, changed_matches?: array<string, int>, slug_clashes?: array<int, string>}
	 *         `refused`: stopped before its first write (a failure that changed nothing); `errors` says why.
	 *         A Replace is also refused while a Content ReSync write intent is open (after the crashed ones are repaired).
	 */
	public function import( string $file_path, bool $replace = false, bool $sanitize_strings = false, array $options = [] ): array {
		$result = [
			'imported'  => 0,
			'skipped'   => 0,
			'errors'    => [],
			'sanitized' => 0,
		];

		// Reset the id maps so two import() calls in one process never mix.
		$this->work                 = null;
		$this->site_default         = null;
		$this->default_kept         = '';
		$this->languages_cleared    = false;
		$this->released             = null;
		$this->release_held         = false;
		$this->skip_reasons         = [];
		$this->strings_carried      = false;
		$this->languages_changed    = false;
		$this->added_codes          = [];
		$this->merge_writing        = false;
		$this->replaced_link_tables = false;
		$this->groups_keep_strings  = false;
		$this->links_keep_strings   = false;
		$this->language_id_set      = null;
		$this->changed_matches      = [];
		$this->guard                = null;
		$this->term_slug_rows       = [];

		// Inside another plugin transaction (a hook): refused before anything is sent; its START TRANSACTION would commit that one.
		if ( $replace && \PerfLocale\Database\Tx::open() ) {
			$result['errors'][] = __( 'Replace import aborted: the database could not open a transaction; rolling back so no data is lost.', 'perflocale' );
			$result['refused']  = true;

			return $result;
		}

		// One import per site at a time, whatever started it, from before the file is read until the last write. A URL-slug
		// write holds the same lock briefly: three tries, a second apart.
		for ( $try = 1; ! ImportLock::acquire(); ++$try ) {
			if ( $try >= 3 || ImportLock::held() || '' !== ImportLock::refused() ) {
				$result['errors'][] = '' !== ImportLock::refused() ? ImportLock::refused() : ImportLock::busy_message();
				$result['refused']  = true;

				return $result;
			}

			sleep( 1 );
		}

		try {
			return $this->import_locked( $file_path, $replace, $sanitize_strings, $options, $result );
		} finally {
			ImportLock::release();
		}
	}

	/**
	 * The import, under the site's import lock.
	 *
	 * @param string               $file_path        Path to the JSON file.
	 * @param bool                 $replace          Replace mode.
	 * @param bool                 $sanitize_strings See {@see self::import()}.
	 * @param array<string, mixed> $options          See {@see self::import()}.
	 * @param array<string, mixed> $result           Result so far.
	 * @phpstan-param array{imported: int, skipped: int, errors: array<int, string>, sanitized: int} $result
	 * @return array{imported: int, skipped: int, errors: array<int, string>, sanitized: int, refused?: bool, not_applied?: array<int, string>, notice?: string, refused_addresses?: array<int, string>, address_notice?: string, notes?: array<int, string>, default_language_kept?: string, skipped_reasons?: array<string, int>, kept_settings?: array<int, string>, unread_sections?: array<int, string>, undeclared_sections?: array<int, string>, changed_matches?: array<string, int>}
	 * @throws \RuntimeException See {@see self::import_from()}.
	 * @throws \Throwable See {@see self::import_from()}.
	 */
	private function import_locked( string $file_path, bool $replace, bool $sanitize_strings, array $options, array $result ): array {
		$limits = self::quality_limits();

		// The structure: envelope and small sections decoded, table rows only located and counted (decoded one at a time later).
		$reader = ImportReader::open( $file_path );

		if ( null === $reader ) {
			$result['errors'][] = __( 'Could not read the uploaded file.', 'perflocale' );
			$result['refused']  = true;
			return $result;
		}

		try {
			$read_error = self::read_structure( $reader, $limits );

			if ( null !== $read_error ) {
				$result['errors'][] = $read_error;
				$result['refused']  = true;
				return $result;
			}

			return $this->import_from( $reader, $file_path, $replace, $sanitize_strings, $options, $limits, $result );
		} catch ( \Throwable $e ) {
			if ( $this->merge_writing ) {
				$this->finish_stopped_merge();
			}

			throw $e;
		} finally {
			if ( null !== $this->work ) {
				$this->work->close();
				$this->work = null;
			}

			$reader->close();
		}
	}

	/**
	 * The data-quality and reader limits, as perflocale/import/quality_limits returns them.
	 *
	 * @return array<array-key, mixed>
	 */
	public static function quality_limits(): array {
		/**
		 * Filter the data-quality limits applied to imports (also `wp perflocale network-import`).
		 *
		 * @hook perflocale/import/quality_limits
		 *
		 * @param array{max_languages?: int, max_rows_per_table?: int, max_value_bytes?: int, max_section_bytes?: int, max_config_bytes?: int, max_captured_bytes?: int, max_top_level_members?: int, max_data_members?: int} $limits
		 *        Defaults: 500 / the rows that fit in memory_limit (a Merge keeps about 512 bytes per row of the largest table
		 *        and may use 80% of the free memory; never fewer than 500000, never more than the file ceiling / 3) / 1048576 /
		 *        33554432 / 4194304 / 67108864 / 1000 / 100000. Section bytes: the largest row or add-on section read; config:
		 *        the largest top-level key; captured: all kept while reading the structure; members: top-level sections and
		 *        `data` entries (network-import: sites).
		 */
		return (array) apply_filters( 'perflocale/import/quality_limits', [] );
	}

	/**
	 * The limits the reader applies to a file's structure.
	 *
	 * @param array<array-key, mixed> $limits Quality limits (quality_limits()).
	 * @return array{max_section_bytes: int, max_config_bytes: int, max_captured_bytes: int, max_top_level_members: int, max_data_members: int}
	 */
	public static function reader_limits( array $limits ): array {
		return [
			'max_section_bytes'     => self::max_section_bytes( $limits ),
			'max_config_bytes'      => max( 65536, Typed::int( $limits['max_config_bytes'] ?? null, 4 * 1048576 ) ),
			'max_captured_bytes'    => max( 65536, Typed::int( $limits['max_captured_bytes'] ?? null, 64 * 1048576 ) ),
			'max_top_level_members' => max( 1, Typed::int( $limits['max_top_level_members'] ?? null, 1000 ) ),
			'max_data_members'      => max( 1, Typed::int( $limits['max_data_members'] ?? null, 100000 ) ),
		];
	}

	/**
	 * Whether the importer reads a top-level member: a core envelope key or an add-on section restored through
	 * `perflocale/import/section/<name>`; others are walked over and named as not imported.
	 *
	 * @param string $key Member key.
	 * @return bool
	 */
	public static function reads_member( string $key ): bool {
		return in_array( $key, DataExporter::RESERVED_SECTION_KEYS, true )
			|| ( '' !== $key && false !== has_action( 'perflocale/import/section/' . $key ) );
	}

	/**
	 * Index an export file, returning the error to report when it cannot be read.
	 *
	 * @param ImportReader            $reader Open reader.
	 * @param array<array-key, mixed> $limits Quality limits.
	 * @return string|null Null when the file was indexed.
	 */
	private static function read_structure( ImportReader $reader, array $limits ): ?string {
		$bounds = self::reader_limits( $limits );

		$reader->limit( $bounds['max_captured_bytes'], $bounds['max_top_level_members'], $bounds['max_data_members'] );

		try {
			$reader->index(
				$bounds['max_section_bytes'],
				[ self::class, 'reads_member' ],
				// The envelope's own keys hold settings and lists: the smaller config cap. Add-on sections keep the section cap.
				static function ( string $key ) use ( $bounds ): ?int {
					return in_array( $key, DataExporter::RESERVED_SECTION_KEYS, true ) ? $bounds['max_config_bytes'] : null;
				}
			);
		} catch ( \UnexpectedValueException $e ) {
			return self::read_error_message( $e, $limits );
		}

		return null;
	}

	/**
	 * The import error for a file the reader refused.
	 *
	 * @param \UnexpectedValueException $e      Reader error.
	 * @param array<array-key, mixed>   $limits Quality limits in force.
	 * @return string
	 */
	public static function read_error_message( \UnexpectedValueException $e, array $limits ): string {
		$bounds = self::reader_limits( $limits );

		switch ( $e->getCode() ) {
			case ImportReader::ERR_READ:
				return __( 'Could not read the uploaded file.', 'perflocale' );

			case ImportReader::ERR_TOO_LARGE:
				return sprintf(
					/* translators: %s: size limit, for example "32 MB". */
					__( 'The file holds a single section or row larger than %s, which the importer does not read. The file is corrupt or was not written by PerfLocale.', 'perflocale' ),
					size_format( $bounds['max_section_bytes'] )
				);

			case ImportReader::ERR_TOO_LARGE_SETTING:
				return sprintf(
					/* translators: 1: a top-level key of the export file, such as settings; 2: a size such as 4 MB. "max_config_bytes" and "perflocale/import/quality_limits" are code names and stay in English. */
					__( 'Import refused: the file\'s %1$s section is larger than %2$s, more than these settings can need. A developer can raise the limit (max_config_bytes) with the perflocale/import/quality_limits filter.', 'perflocale' ),
					$e->getMessage(),
					size_format( $bounds['max_config_bytes'] )
				);

			case ImportReader::ERR_TOO_MUCH_KEPT:
				return sprintf(
					/* translators: %s: size limit, for example "64 MB". "perflocale/import/quality_limits" and "max_captured_bytes" are code names and stay in English. */
					__( 'Import refused: the file holds more than %s outside its table rows, more than this importer reads. A developer can raise the limit (max_captured_bytes) with the perflocale/import/quality_limits filter.', 'perflocale' ),
					size_format( $bounds['max_captured_bytes'] )
				);

			case ImportReader::ERR_TOO_MANY_MEMBERS:
				return sprintf(
					/* translators: %s: a number. "perflocale/import/quality_limits" and "max_top_level_members" are code names and stay in English. */
					__( 'Import refused: the file has more than %s top-level sections, more than this importer reads. A developer can raise the limit (max_top_level_members) with the perflocale/import/quality_limits filter.', 'perflocale' ),
					number_format_i18n( $bounds['max_top_level_members'] )
				);

			case ImportReader::ERR_TOO_MANY_DATA_MEMBERS:
				return sprintf(
					/* translators: %s: a number. "perflocale/import/quality_limits" and "max_data_members" are code names and stay in English. */
					__( 'Import refused: the file\'s data section has more than %s tables and row lists, more than this importer reads. A developer can raise the limit (max_data_members) with the perflocale/import/quality_limits filter.', 'perflocale' ),
					number_format_i18n( $bounds['max_data_members'] )
				);
		}

		return __( 'Invalid JSON format.', 'perflocale' );
	}

	/**
	 * Largest single row or non-table section the importer reads, in bytes.
	 *
	 * @param array<array-key, mixed> $limits Quality limits.
	 * @return int
	 */
	private static function max_section_bytes( array $limits ): int {
		$max = isset( $limits['max_section_bytes'] ) && is_numeric( $limits['max_section_bytes'] ) ? (int) $limits['max_section_bytes'] : 32 * 1048576;

		return max( 65536, $max );
	}

	/**
	 * Rows per piece for one table, also the interval of the per-piece work (look-ups, cache release).
	 *
	 * @param string $table_name Table short name.
	 * @return int
	 */
	private static function batch_size( string $table_name ): int {
		/**
		 * Filter the rows the data importer reads and checks per piece: one look-up per unique key per piece, so collisions
		 * skip before any write; larger pieces mean fewer look-ups, smaller ones less memory. Clamped 50-5000;
		 * `wp perflocale import --batch-size` sets it for one run.
		 *
		 * @hook  perflocale/import/batch_size
		 * @since 1.0.7.3
		 *
		 * @param int    $size       Default 500.
		 * @param string $table_name Table being imported (for example `translation_links`).
		 */
		$size = (int) apply_filters( 'perflocale/import/batch_size', self::BATCH_SIZE, $table_name );

		return max( self::BATCH_SIZE_MIN, min( self::BATCH_SIZE_MAX, $size ) );
	}

	/**
	 * The import, from an indexed file.
	 *
	 * @param ImportReader         $reader           Indexed reader.
	 * @param string               $file_path        File path (for the section hooks).
	 * @param bool                 $replace          Replace mode.
	 * @param bool                 $sanitize_strings See {@see self::import()}.
	 * @param array<string, mixed> $options          See {@see self::import()}.
	 * @param array<array-key, mixed> $limits        Quality limits.
	 * @param array<string, mixed> $result           Result so far.
	 * @phpstan-param array{imported: int, skipped: int, errors: array<int, string>, sanitized: int} $result
	 * @return array{imported: int, skipped: int, errors: array<int, string>, sanitized: int, refused?: bool, not_applied?: array<int, string>, notice?: string, refused_addresses?: array<int, string>, address_notice?: string, notes?: array<int, string>, default_language_kept?: string, skipped_reasons?: array<string, int>, kept_settings?: array<int, string>, unread_sections?: array<int, string>, undeclared_sections?: array<int, string>, changed_matches?: array<string, int>}
	 * @throws \RuntimeException When a replace import cannot open, read or confirm its transaction, or would leave other than one default language (after the rollback).
	 * @throws \Throwable A replace import's failure, after the rollback (import() reports it).
	 */
	private function import_from( ImportReader $reader, string $file_path, bool $replace, bool $sanitize_strings, array $options, array $limits, array $result ): array {
		// The envelope as the checks read it: members decoded, `data` as table => row count (or the member when not a list).
		$data = Typed::map( $reader->envelope() );

		if ( $reader->has_table_data() ) {
			$data['data'] = $reader->table_summary();
		}

		// A file with a `sections` list is read for those only; the rest is left unread and named.
		$undeclared = self::undeclared_members( $data, array_keys( $reader->table_summary() ) );

		foreach ( $undeclared['envelope'] as $key ) {
			unset( $data[ $key ] );
		}

		if ( [] !== $undeclared['data'] ) {
			$reader->forget_tables( $undeclared['data'] );
			$data['data'] = $reader->table_summary();
		}

		// Structure.
		$validation = $this->validate( $data );

		if ( ! empty( $validation ) ) {
			$result['errors']  = $validation;
			$result['refused'] = true;
			return $result;
		}

		// Site identity gate, before the data-quality scan: an address comparison, and a refusal leaves configuration and
		// the transaction untouched. The rows name posts, terms and strings by id, which mean nothing on another site.
		$allow_foreign_ids = ! empty( $options['allow_foreign_ids'] );
		$recorded_url      = $data['site_url'] ?? null;
		$addon             = self::addon_plan( $reader, $data, $options['addon_choices'] ?? null );

		// No address (absent or '') is the legacy shape and not checked here; an address yielding no host identifies nothing
		// and is a mismatch (as network-import judges its slices).
		$has_recorded_url = isset( $data['site_url'] ) && '' !== $data['site_url'];
		$identity         = self::bundle_matches_this_site( $recorded_url );

		// A file from IDENTITY_SINCE on always records its address: without one it is treated as from another site
		// (refused, without the copy confirmation). Older files keep the legacy rule.
		$identity_rules = self::records_identity( $data['version'] ?? null );

		if ( ! $allow_foreign_ids && $identity_rules && ! $has_recorded_url
			&& ( ( $replace && [] !== self::tables_to_wipe( $data ) )
				|| self::carries_object_ids( $data, array_keys( $addon['members'] ), $replace )
				|| ( self::carries_language_refs( $data, $replace ) && ! self::has_rows( $data, 'languages' ) ) ) ) {
			$result['errors'][] = __( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. This file records no site address, so it cannot be matched to this site. Tick the box only when this site is a copy of the site the file was exported from and its post and term IDs still match.', 'perflocale' );
			$result['refused']  = true;

			return $result;
		}

		if ( ! $allow_foreign_ids
			&& ( false === $identity || ( $has_recorded_url && null === $identity ) ) ) {
			// The normalised identity, never the file's raw string (it travels through a redirect and a CLI warning). Messages
			// lead with refusal and remedy: the Jobs screen shows only the first ~137 characters.
			$theirs = self::site_identity( $recorded_url );

			if ( null === $theirs ) {
				/* translators: stands in for the address recorded in the export file when the recorded value is not a readable address. */
				$theirs = __( 'an unreadable address', 'perflocale' );
			}

			$ours = (string) ( self::site_identity( get_option( 'home' ) ) ?? self::site_identity( home_url() ) );

			// Replace's wipe follows the row lists the file carries, empty or not, so a foreign file of empty lists would still
			// empty this site's tables: test the plan (a pure static, no query).
			$foreign_wipe_plan = $replace ? self::tables_to_wipe( $data ) : [];

			if ( [] !== $foreign_wipe_plan ) {
				$result['errors'][] = sprintf(
					/* translators: 1: site address recorded in the export file, 2: this site's address. The quoted phrase is the import form's checkbox label and must match it word for word. */
					__( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. This file was exported from %1$s, not this site (%2$s), and Replace mode deletes this site\'s rows for every section the file declares - including the sections it carries no rows for. Its translation links, translated slugs and content hashes name posts and terms by numeric ID, and on a site that was set up separately the same IDs belong to different content. Tick the box only when this site is a copy of %1$s and its post and term IDs still match - a staging clone, a domain migration, or a backup of this site that records a language-specific address. The box is labelled “This site is a copy of the site the file was exported from”.', 'perflocale' ),
					$theirs,
					$ours
				);
				$result['refused'] = true;

				return $result;
			}

			if ( self::carries_object_ids( $data, array_keys( $addon['members'] ), $replace ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: site address recorded in the export file, 2: this site's address. The quoted phrase is the import form's checkbox label and must match it word for word. */
					__( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. This file was exported from %1$s, not this site (%2$s). Its translation links, translated slugs and content hashes name posts and terms by numeric ID, and on a site that was set up separately the same IDs belong to different content. Tick the box only when this site is a copy of %1$s and its post and term IDs still match - a staging clone, a domain migration, or a backup of this site that records a language-specific address. The box is labelled “This site is a copy of the site the file was exported from”.', 'perflocale' ),
					$theirs,
					$ours
				);
				$result['refused'] = true;

				return $result;
			}

			// Rows naming a language by id with no languages section to resolve them: a German translation would land under
			// whatever language has that id here.
			if ( self::carries_language_refs( $data, $replace ) && ! self::has_rows( $data, 'languages' ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: site address recorded in the export file, 2: this site's address. "Languages" names a section checkbox on the export screen: use the same word as that checkbox. The quoted phrase is the import form's checkbox label and must match it word for word. */
					__( 'Import refused. Export again from %1$s with the Languages section included, or tick the copy-confirmation box on the import form (WP-CLI: --force). This file was exported from %1$s, not this site (%2$s), and it carries no Languages section. Its translations name their language by numeric ID, and the same ID belongs to a different language here. Tick the box only when this site is a copy of %1$s, or for a backup of this site that records a language-specific address. The box is labelled “This site is a copy of the site the file was exported from”.', 'perflocale' ),
					$theirs,
					$ours
				);
				$result['refused'] = true;

				return $result;
			}
		}

		// Data-quality gate, fail closed before any write; it also decodes every row once and notes whether the id-keyed
		// rows carry fingerprints. Docs: docs-ai/11-code-rationale.md, "DataImporter: the replace transaction".
		$presence = [
			'rows' => false,
			'fp'   => false,
		];

		try {
			$quality = self::stream_quality_issues( $reader, $data, $limits, ( ! $allow_foreign_ids && $identity_rules ) ? self::fingerprint_sources( $addon['members'], $replace ) : [], $presence );
		} catch ( \UnexpectedValueException $e ) {
			$quality = [ self::read_error_message( $e, $limits ) ];
		}

		if ( ! empty( $quality ) ) {
			$result['errors']  = $quality;
			$result['refused'] = true;
			return $result;
		}

		// A setting of the wrong shape would be stored coerced ("Array"): Replace applies settings, so it refuses first.
		$misshapen = $replace ? self::misshapen_settings( $data ) : [];

		if ( [] !== $misshapen ) {
			$result['errors'][] = sprintf(
				/* translators: %s: comma-separated setting names, such as mt_provider. */
				__( 'Import refused: the file holds a value of the wrong type for these settings: %s. Nothing was changed.', 'perflocale' ),
				implode( ', ', $misshapen )
			);
			$result['refused']  = true;

			return $result;
		}

		// Id-keyed rows without any fingerprint cannot be checked against this site's content: treated as foreign.
		if ( $presence['rows'] && ! $presence['fp'] ) {
			$result['errors'][] = __( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. The rows of this file that name posts, terms or orders by ID carry no fingerprints, so they cannot be checked against this site\'s content. Tick the box only when this site is a copy of the site the file was exported from and its IDs still match.', 'perflocale' );
			$result['refused']  = true;

			return $result;
		}

		ImportLock::heartbeat();

		$max_row               = self::max_section_bytes( $limits );
		$this->strings_carried = $reader->count( 'strings' ) > 0;

		// Replace would first clear this site's rows, so a file whose fingerprinted rows mostly name other content is
		// refused; Merge only skips and names them (check_fingerprints()).
		if ( $replace ) {
			try {
				[ $checked, $mismatched ] = $this->fingerprint_mismatch_share( $reader, $max_row, $addon['members'] );
			} catch ( \UnexpectedValueException $e ) {
				$result['errors'][] = self::read_error_message( $e, $limits );
				$result['refused']  = true;
				return $result;
			}

			if ( $checked > 0 && $mismatched * 2 > $checked ) {
				$result['errors'][] = sprintf(
					/* translators: 1: rows that name a different item, 2: rows checked. "Merge" is the import mode on the Settings → Export & Import screen. */
					__( 'Import refused: %1$d of the %2$d rows that name a post or term by ID name a different item on this site, so this file most likely comes from another site, or from this site before its content was rebuilt. Replace would remove this site\'s own rows for those sections, so nothing was changed. Choose the right file, or use Merge, which skips such rows and imports the rest.', 'perflocale' ),
					$mismatched,
					$checked
				);
				$result['refused'] = true;

				return $result;
			}
		}

		// A Replace that clears the post links (both ReSync tables go with them) or the pending changes would delete a
		// ReSync write's intent: crashed writes are repaired first; an intent still open refuses before the first write.
		if ( $replace ) {
			$intent_refusal = self::open_resync_intents( self::tables_to_wipe( $data ) );

			if ( '' !== $intent_refusal ) {
				$result['errors'][] = $intent_refusal;
				$result['refused']  = true;

				return $result;
			}
		}

		// Merge never changes configuration: each part the file carries is counted as skipped and named.
		if ( ! $replace ) {
			$not_applied = [];

			foreach ( self::CONFIGURATION_KEYS as $config_part ) {
				if ( isset( $data[ $config_part ] ) && is_array( $data[ $config_part ] ) ) {
					$not_applied[] = $config_part;
				}
			}

			if ( [] !== $not_applied ) {
				$result['skipped']    += count( $not_applied );
				$result['not_applied'] = $not_applied;
				/* translators: "Merge" and "Replace" are the import modes on the Settings → Export & Import screen. */
				$result['notice'] = __( 'Settings, add-on settings, the add-on list and roles in the file were not applied (Merge). Use Replace to apply them; a file with only those sections deletes no rows.', 'perflocale' );
			}

			// The automation history names this site's changes by id and has no key to merge on: Merge leaves it out.
			$automation_rows = $reader->count( 'pending_changes' );

			if ( $automation_rows > 0 ) {
				$result['skipped']      += $automation_rows;
				$result['not_applied'][] = 'automation';
				/* translators: "Merge" and "Replace" are the import modes on the Settings → Export & Import screen. */
				$automation_notice = __( 'The automation history in the file was not applied (Merge). Use Replace to restore it.', 'perflocale' );
				$result['notice']  = isset( $result['notice'] ) ? $result['notice'] . ' ' . $automation_notice : $automation_notice;
			}
		}

		// Tables.
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			// Replace wipes the tables of the exported sections that the file carries as lists, empty or not (an empty list must
			// not leak later rows), and never others: a strings-only Replace must not empty languages and links.
			$wpdb = Typed::wpdb();

			// Table => type scope (null: whole table), resolved once for the wipe and for the abort gate (partly cleared tables).
			$wipe_plan      = $replace ? self::tables_to_wipe( $data ) : [];
			$intent_refused = '';

			$this->languages_cleared   = array_key_exists( 'languages', $wipe_plan );
			$this->groups_keep_strings = '!string' === ( $wipe_plan['translation_groups'] ?? null );
			$this->links_keep_strings  = '!string' === ( $wipe_plan['translation_links'] ?? null );

			// The wipe shares the import's try, so a failed statement reaches the same ROLLBACK; configuration only after COMMIT.
			ImportLock::heartbeat();

			if ( $replace ) {
				ImportLock::hold_for_transaction();
			}

			// Created before the first write and before START TRANSACTION (on SQLite nothing inside may fail). A reconnect ends
			// it: Merge stops at the next heartbeat, Replace through the connection guard.
			$this->work = new ImportWorkTable( $replace ? self::connection_lost_message() : self::merge_lost_message() );
			$this->work->open();

			if ( ! $replace ) {
				ImportLock::bind_session( self::merge_lost_message() );
			}

			$slugs_before = null;
			$committed    = false;

			try {
				if ( $replace ) {
					// Wipe and re-import in one transaction, with DELETE (TRUNCATE commits implicitly); ids are kept. Checked against
					// false only (0 is success). The guard is set first, so a statement resent on a new connection matches nothing.
					$this->guard = new ConnectionGuard( 'import' );

					// The intent fence below holds by gap locks, which follow this transaction's level: REPEATABLE READ
					// on a READ COMMITTED server too (next transaction only; a reconnect after it fails the guard).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( ( ConnectionGuard::applies() && false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) || ! $this->guard->begin( true ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
						self::log_db_error( 'START TRANSACTION', (string) $wpdb->last_error );

						throw new \RuntimeException( __( 'Replace import aborted: the database could not open a transaction; rolling back so no data is lost.', 'perflocale' ) );
					}

					$was_suppressing = $wpdb->suppress_errors( true );

					// finally: the connection's error reporting goes back as found, whatever leaves the loop.
					try {
						// The check above, repeated with a locking read: an intent begun since refuses here, and one
						// begun later waits until this transaction ends.
						if ( ( self::wipes_resync_intents( $wipe_plan ) && 0 !== ( new \PerfLocale\Database\Repository\ResyncRepository() )->open_intents_for_update() )
							|| ( self::wipes_string_intents( $wipe_plan ) && 0 !== ( new \PerfLocale\Database\Repository\PendingChangeRepository() )->string_intents_for_update() )
						) {
							$intent_refused = self::resync_writing_refusal();

							throw new \RuntimeException( $intent_refused );
						}

						// Rewriting the languages: every language row by id next (the global order, Inputs::lock_languages()), whatever
						// order the file lists its sections in. After the intent fence: a string writer holds its intent, then a language row.
						if ( array_key_exists( 'languages', $wipe_plan ) && ! \PerfLocale\Router\UrlBases\Inputs::lock_languages() ) {
							self::log_db_error( 'locking the languages', (string) $wpdb->last_error );

							// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
							throw new \RuntimeException(
								sprintf(
									/* translators: %s: table name */
									__( 'Replace import aborted: the existing rows of table "%s" could not be read; the whole import was rolled back, nothing was changed.', 'perflocale' ),
									'languages'
								)
							);
							// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
						}

						// The slugs before the wipe, under the same locks: one the file does not bring back is released before COMMIT.
						if ( array_key_exists( 'languages', $wipe_plan ) ) {
							$slugs_before = \PerfLocale\Router\UrlBases\Inputs::slugs( true );

							if ( null === $slugs_before ) {
								// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
								throw new \RuntimeException(
									sprintf(
										/* translators: %s: table name */
										__( 'Replace import aborted: the existing rows of table "%s" could not be read; the whole import was rolled back, nothing was changed.', 'perflocale' ),
										'languages'
									)
								);
								// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
							}
						}

						// A scope's deferred-removal record names string ids the file's strings reuse: it goes with the strings
						// (the next sync of the scope derives it again). Before the wipe: a removal worker locks its record
						// first, then the rows, and this takes them in the same order.
						if ( array_key_exists( 'strings', $wipe_plan ) && ! self::clear_scope_removal_records() ) {
							throw new \RuntimeException( __( 'Replace import aborted: the records of deferred string removals could not be cleared; rolling back so no data is lost.', 'perflocale' ) );
						}

						foreach ( $wipe_plan as $table_name => $type_scope ) {
							// From a class allow-list, sanitized to a bare identifier: prepare() cannot bind a table name.
							$full_table = Schema::sanitize_table( Schema::table( $table_name ) );

							if ( $type_scope !== null ) {
								// A shared table loses only the slice the sections own (the type bound as a value). Links to the groups this drops
								// are in a table the file does not own: reaped after the commit (reap_orphan_string_links()).
								$wipe = str_starts_with( $type_scope, '!' )
									? $wpdb->prepare( 'DELETE FROM %i WHERE type <> %s', $full_table, substr( $type_scope, 1 ) )
									: $wpdb->prepare( 'DELETE FROM %i WHERE type = %s', $full_table, $type_scope );
							} else {
								$wipe = $wpdb->prepare( 'DELETE FROM %i', $full_table );
							}

							$guarded = $this->connection_guard()->condition();

							if ( null !== $wipe && '' !== $guarded ) {
								$wipe .= ( null !== $type_scope ? ' AND ' : ' WHERE ' ) . $guarded;
							}

							$this->stop_unless_connection_kept();

							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The replace wipe, prepared above (table bound with %i, the type as a value); the guard's condition is prepared by the guard.
							$wiped = null === $wipe ? false : $wpdb->query( $wipe );

							// An uncleared table turns the restore into a merge, and a DELETE that ended the transaction would let the next one
							// commit alone: stop at the first failure.
							if ( false === $wiped ) {
								self::log_db_error( 'clearing ' . $table_name, (string) $wpdb->last_error );

								throw new \RuntimeException(
									sprintf(
										/* translators: %s: table name */
										__( 'Replace import aborted: table "%s" could not be cleared; rolling back so no data is lost.', 'perflocale' ),
										$table_name
									)
								);
							}
						}

						// The ReSync tables copy post content by post id and are not exported: they go with the post links.
						$this->stop_unless_connection_kept();
						$resync_error = array_key_exists( 'translation_links', $wipe_plan ) && self::wipes_resync_intents( $wipe_plan )
							? ( new \PerfLocale\Database\Repository\ResyncRepository() )->wipe( $this->connection_guard()->condition() )
							: '';

						if ( '' !== $resync_error ) {
							self::log_db_error( 'clearing resync tables', $resync_error );

							throw new \RuntimeException(
								sprintf(
									/* translators: %s: table name */
									__( 'Replace import aborted: table "%s" could not be cleared; rolling back so no data is lost.', 'perflocale' ),
									'resync_state'
								)
							);
						}

						// Pending string changes name string and language ids the file's rows may reuse: closed (target_gone);
						// the file's own pending changes are imported after this.
						$this->stop_unless_connection_kept();
						if ( self::closes_pending_strings( $wipe_plan ) && ! self::close_pending_strings( $this->connection_guard()->condition() ) ) {
							throw new \RuntimeException( __( 'Replace import aborted: the pending string changes could not be closed; rolling back so no data is lost.', 'perflocale' ) );
						}
					} finally {
						$wpdb->suppress_errors( $was_suppressing );
					}

					// A DELETE on a new connection matches nothing, as on an empty table: the marker tells them apart.
					$this->stop_unless_connection_verified();
				}

				$this->merge_writing = ! $replace;

				foreach ( DataRegistry::import_tables() as $table_name ) {
					if ( ! $reader->is_row_list( $table_name ) ) {
						continue;
					}

					// Counted and named above.
					if ( ! $replace && 'pending_changes' === $table_name ) {
						continue;
					}

					$row_count = $reader->count( $table_name );

					if ( 0 === $row_count ) {
						continue;
					}

					// Replace keeps ids (the links' group ids stay valid); the wipe was done above, so import_table() skips its own.
					if ( 'translation_links' === $table_name && ! $replace ) {
						$this->plan_link_anchors( $reader->rows( $table_name, $max_row ) );
					}

					// A `!type` wipe kept that slice: the file's rows of that type are not this section's to restore.
					$scope        = $wipe_plan[ $table_name ] ?? null;
					$exclude_type = ( is_string( $scope ) && str_starts_with( $scope, '!' ) ) ? substr( $scope, 1 ) : '';

					$table_result = $this->import_table( $table_name, $reader->rows( $table_name, $max_row ), $replace, $sanitize_strings, $exclude_type );

					if ( 'slug_translations' === $table_name ) {
						$this->restore_term_slugs( $replace, $table_result );
					}

					$result['imported']  += $table_result['imported'];
					$result['skipped']   += $table_result['skipped'];
					$result['sanitized'] += $table_result['sanitized'];

					if ( ! empty( $table_result['errors'] ) ) {
						foreach ( $table_result['errors'] as $err ) {
							$result['errors'][] = $table_name . ': ' . $err;
						}
					}

					// A wiped table whose non-empty section inserted nothing failed systematically: abort. A scoped table only on a real
					// insert failure: its surviving rows can collide on ids (one id space for all group types) as an ordinary skip.
					$scoped_wipe = ( ( $wipe_plan[ $table_name ] ?? null ) !== null );

					// A restore is all or nothing: a refused row after its table was deleted is lost, so any real failure aborts
					// (duplicate skips are counted apart and never reach here).
					if ( $replace && $table_result['failed'] > 0 ) {
						throw new \RuntimeException(
							sprintf(
								/* translators: 1: table name, 2: failed row count, 3: total row count, 4: the first row's error message */
								__( 'Replace import aborted: table "%1$s" could not insert %2$d of %3$d rows; rolling back so no data is lost. %4$s', 'perflocale' ),
								$table_name,
								$table_result['failed'],
								$row_count,
								! empty( $table_result['errors'] ) ? (string) $table_result['errors'][0] : ''
							)
						);
					}

					// A wiped (unscoped) table whose section inserted nothing, not counting rows left out on purpose: fatal.
					if ( $replace && $table_result['imported'] === 0 && ! $scoped_wipe && $row_count > $table_result['dropped'] ) {
						throw new \RuntimeException(
							sprintf(
								/* translators: 1: table name, 2: row count, 3: the first row's error message */
								__( 'Replace import aborted: table "%1$s" inserted 0 of %2$d rows. %3$s', 'perflocale' ),
								$table_name,
								$row_count,
								! empty( $table_result['errors'] ) ? (string) $table_result['errors'][0] : ''
							)
						);
					}
				}

				// Languages the file does not bring back: their URL bases data and map entries go in this transaction, never to a
				// language added later under the slug.
				if ( $replace && null !== $slugs_before ) {
					$this->release_removed_slugs( $slugs_before );
				}

				// A Replace that skipped a held pair's twin or refused a strings row leaves their rows naming no string.
				if ( $replace && ( $this->work_table()->has( ImportWorkTable::TWIN ) || $this->work_table()->has( ImportWorkTable::REFUSED ) ) ) {
					ImportStringTwins::settle(
						$this->work_table(),
						$this->connection_guard()->condition(),
						/* translators: %s: table name */
						sprintf( __( 'Replace import aborted: a row of table "%s" could not be saved because of a database error; the whole import was rolled back, nothing was changed.', 'perflocale' ), 'strings' )
					);
				}

				// A Replace that touched languages must leave exactly one default (none loses unprefixed URLs and fallbacks, two
				// pick one by read order); checked inside the transaction.
				if ( $replace && ( $this->languages_cleared || $this->languages_changed ) ) {
					$defaults = $this->count_default_languages();

					if ( 1 !== $defaults ) {
						throw new \RuntimeException(
							0 === $defaults
								? __( 'Import refused: after this import the site would have no default language. Nothing was changed.', 'perflocale' )
								: __( 'Import refused: after this import the site would have more than one default language. Nothing was changed.', 'perflocale' )
						);
					}
				}

				if ( $replace ) {
					$this->stop_unless_connection_verified();

					// A refused COMMIT usually means the server discarded the transaction; the catch's ROLLBACK covers an open one.
					// No configuration written yet.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( false === $wpdb->query( 'COMMIT' ) ) {
						self::log_db_error( 'COMMIT', (string) $wpdb->last_error );

						throw new \RuntimeException( __( 'Replace import not confirmed: the database reported an error while saving it. Run the import again.', 'perflocale' ) );
					}

					// A COMMIT resent on a new connection committed nothing: the server rolled back with the lost connection.
					if ( ! $this->connection_guard()->kept() ) {
						throw new \RuntimeException( self::connection_lost_message() );
					}

					$committed = true;
				}
			} catch ( \Throwable $e ) {
				if ( $replace ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'ROLLBACK' );

					// After a lost connection, reads on the new one can stop the import for a reason that is not the real one.
					if ( null !== $this->guard && ! $this->guard->holds() && self::connection_lost_message() !== $e->getMessage() ) {
						throw new \RuntimeException( self::connection_lost_message(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
					}
				}

				// Tables back where they were and configuration never applied: the site is as it was.

				// A refusal rolled back before its first DELETE: nothing was imported.
				if ( '' !== $intent_refused ) {
					$result['errors'][] = $intent_refused;
					$result['refused']  = true;

					return $result;
				}

				throw $e;
			} finally {
				if ( null !== $this->guard ) {
					$guard = $this->guard;
					$this->guard->end();
					$this->guard = null;

					// The release's held publication: after a confirmed COMMIT, else every cached form of what it wrote goes. The
					// caches of what committed go before any listener of the held writes runs (one may throw).
					if ( $this->release_held ) {
						$this->release_held = false;
						$released           = $this->released;
						$then               = static function () use ( $committed, $released ): void {
							if ( ! $committed ) {
								return;
							}

							$cache = \PerfLocale\Plugin::get_instance()->has( 'cache' ) ? \PerfLocale\Plugin::get_instance()->get( 'cache' ) : null;

							// Each step runs when another throws. The committed tables' caches too (the string files follow at the end).
							\PerfLocale\Database\Tx::each(
								static function () use ( $released ): void {
									if ( 'stored' === ( $released['values'] ?? '' ) ) {
										\PerfLocale\Router\UrlBases\Publication::reset_dry_run();
									}
								},
								static function () use ( $released ): void {
									if ( null !== ( $released['map'] ?? null ) ) {
										\PerfLocale\Router\UrlBases\Publication::map_released();
									}
								},
								static function () use ( $cache ): void {
									if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
										$cache->flush_languages();
									}
								},
								static fn() => \PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches( false )
							);
						};

						if ( [] !== \PerfLocale\Database\OptionWriter::settle( $committed, $guard, $then ) ) {
							\PerfLocale\Router\UrlBases\Publication::reset_dry_run();
						}
					}
				}

				ImportLock::bind_session( '' );
			}

			// After COMMIT, as LanguageWrites::delete() after its release (its caches went at settle()).
			if ( null !== $this->released ) {
				if ( 'stored' === $this->released['values'] ) {
					\PerfLocale\Router\UrlBases\Lifecycle::clear_none();
				}

				if ( null !== $this->released['map'] ) {
					\PerfLocale\Router\UrlBases\Publication::slug_released( $this->released['map'][0], $this->released['map'][1] );
				}
			}

			ImportLock::heartbeat();

			// Clearing the languages table changed the codes even with no row written.
			if ( array_key_exists( 'languages', $wipe_plan ) ) {
				$this->languages_changed = true;
			}

			if ( array_key_exists( 'translation_groups', $wipe_plan ) || array_key_exists( 'translation_links', $wipe_plan ) ) {
				$this->replaced_link_tables = true;
			}

			// A replace that cleared groups removes source-map rows whose group is gone, so a migration re-run cannot join a
			// group by a recorded id.
			if ( array_key_exists( 'translation_groups', $wipe_plan ) ) {
				$this->drop_stale_source_map();
			}

			// The cleared strings table holds only rows this import hashed, so its counts start over.
			if ( array_key_exists( 'strings', $wipe_plan ) ) {
				\PerfLocale\Strings\StrIdentMigration::replaced();
			}

			// Merge strands groups (no natural key; their links skip on object_lang): swept once, after every table, so no
			// group is swept before its links land.
			if ( ! $replace ) {
				$this->sweep_orphan_groups();
			}

			// A restored automation history, or pending string changes the Replace closed, change the pending count.
			if ( array_key_exists( 'pending_changes', $wipe_plan ) || self::closes_pending_strings( $wipe_plan ) ) {
				\PerfLocale\Automation\ChangeService::mark_badge_dirty();
				\PerfLocale\Automation\ChangeService::end_batch();
			}
		}

		if ( '' !== $this->default_kept ) {
			// The report's first note.
			$result['default_language_kept'] = $this->default_kept;
			$result['notes']                 = [
				$replace
					? sprintf(
						/* translators: 1: the file's default language code, 2: this site's default language code. "Languages" names a section checkbox on the export screen: use the same word as that checkbox. */
						__( 'The file\'s default language (%1$s) was added as an ordinary language; this site\'s default language (%2$s) is unchanged, because the file does not include the Languages section.', 'perflocale' ),
						$this->default_kept,
						(string) $this->site_default
					)
					: sprintf(
						/* translators: 1: the file's default language code, 2: this site's default language code. "Merge" is the import mode on the Settings → Export & Import screen. */
						__( 'The file\'s default language (%1$s) was added as an ordinary language; this site\'s default language (%2$s) is unchanged (Merge).', 'perflocale' ),
						$this->default_kept,
						(string) $this->site_default
					),
			];
		}

		// $wpdb->insert() fires no language hook: mark the rewrite rebuild a language change needs (as
		// CacheInvalidator::on_language_changed()).
		if ( $this->languages_changed ) {
			self::mark_rewrite_flush();
		}

		// Replace applies the configuration once the tables are in (apply_configuration()).
		if ( $replace ) {
			$this->apply_configuration( $data, $result );
		}

		ImportLock::heartbeat();

		// The declared sections' options and meta (ExportItems), each on its own after tables and configuration; pre-part
		// files' add-on rows belong to the shared Add-on data choice.
		$declared = array_values( array_filter( Typed::strs( $data['sections'] ?? [] ) ) );
		$lists    = [];

		if ( ! $addon['shared'] ) {
			$declared = array_values( array_diff( $declared, [ DataRegistry::ADDON_SECTION ] ) );
		}

		foreach ( DataRegistry::ROW_LISTS as $kind => $list ) {
			$lists[ $list ] = DataExporter::row_list_items( $kind, $declared );
		}

		/**
		 * Filter whether the import's own meta writes start Sync Fields mirroring. Off (default): each post gets the file's
		 * value, so the import reproduces the file. On: values are copied to translations as a save would, so the result can
		 * differ from the file (a Merge can change existing rows, a held-back translation is overwritten).
		 *
		 * @hook  perflocale/import/sync_fields_mirroring
		 * @since 1.0.7.3
		 *
		 * @param bool $mirror Default false.
		 * @return bool
		 */
		$mirror = (bool) apply_filters( 'perflocale/import/sync_fields_mirroring', false );

		if ( ! $mirror ) {
			\PerfLocale\Translation\ContentSync::suspend_meta_write_sync();
		}

		// Matched to this site or a confirmed copy: only then is this site's own redirect history replaced.
		$same_site = true === $identity || $allow_foreign_ids;

		try {
			$items = ExportItems::import( $reader, $lists + $addon['members'], $replace, $max_row, self::batch_size( 'options' ), $same_site );
		} finally {
			if ( ! $mirror ) {
				\PerfLocale\Translation\ContentSync::resume_meta_write_sync();
			}
		}

		$result['imported'] += $items['imported'];
		$result['skipped']  += $items['skipped'];

		foreach ( $items['errors'] as $error ) {
			$result['errors'][] = $error;
		}

		foreach ( $items['reasons'] as $reason => $count ) {
			$this->note_skip( (string) $reason, (int) $count );
		}

		foreach ( $items['notes'] as $note ) {
			$result['notes'][] = $note;
		}

		foreach ( $items['matched'] as $part => $count ) {
			$this->changed_matches[ (string) $part ] = ( $this->changed_matches[ (string) $part ] ?? 0 ) + (int) $count;
		}

		if ( [] !== $this->changed_matches ) {
			$result['changed_matches'] = $this->changed_matches;
			$result['notes'][]         = self::changed_match_notice( $this->changed_matches );
		}

		if ( [] !== $this->skip_reasons ) {
			$result['skipped_reasons'] = $this->skip_reasons;

			foreach ( self::skip_reason_notes( $this->skip_reasons ) as $note ) {
				$result['notes'][] = $note;
			}
		}

		// A section nothing here reads (a newer core section, an inactive add-on) is named, not dropped silently.
		$unread = [];

		foreach ( array_keys( (array) ( $data['data'] ?? [] ) ) as $table_name ) {
			if ( ! in_array( $table_name, DataRegistry::import_tables(), true ) && ! in_array( $table_name, DataRegistry::ROW_LISTS, true ) && null === DataRegistry::parse_part_member( (string) $table_name ) && $reader->count( $table_name ) > 0 ) {
				$unread[] = (string) $table_name;
			}
		}

		// An add-on part with rows whose add-on declares no such data here.
		foreach ( $addon['inactive'] as $name ) {
			$unread[] = $name;
		}

		// Top-level sections the reader walked over: neither core nor hooked by an active add-on.
		foreach ( $reader->skipped() as $name ) {
			$unread[] = $name;
		}

		if ( [] !== $addon['unchosen'] ) {
			$result['notes'][] = sprintf(
				/* translators: %s: comma-separated names of add-on data in the file (the labels of the export screen's checkboxes) */
				__( 'Not imported, as chosen: %s.', 'perflocale' ),
				implode( ', ', $addon['unchosen'] )
			);
		}

		// Fires once per non-core top-level key for add-ons that ship sections (`perflocale/export/sections`), with the
		// exporter's reserved-key list so the two cannot drift.
		foreach ( $data as $section_name => $section_data ) {
			if ( '' === $section_name || in_array( $section_name, DataExporter::RESERVED_SECTION_KEYS, true ) ) {
				continue;
			}

			if ( ! has_action( 'perflocale/import/section/' . $section_name ) ) {
				$unread[] = $section_name;
			}

			/**
			 * Restore an add-on's export section during a data import; fires once per non-core top-level key of the envelope.
			 * Use the same `<name>` as the `perflocale/export/sections` key, so the round trip works.
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

		$left_out = array_merge( $undeclared['envelope'], $undeclared['data'] );

		if ( [] !== $left_out ) {
			$result['undeclared_sections'] = $left_out;
			$result['notes'][]             = sprintf(
				/* translators: %s: comma-separated names of parts of the export file, for example "roles, languages". */
				__( 'Not imported: %s. The file\'s list of sections does not include them. Export again with those sections selected to import them.', 'perflocale' ),
				implode( ', ', $left_out )
			);
		}

		if ( [] !== $unread ) {
			$result['unread_sections'] = $unread;
			$result['notes'][]         = sprintf(
				/* translators: %s: comma-separated section names from the file */
				__( 'Not imported: %s. This version of PerfLocale, or the add-ons active on this site, do not read these sections of the file. Update PerfLocale or activate the add-on, then import the file again to restore them.', 'perflocale' ),
				implode( ', ', $unread )
			);
		}

		$carries_strings = self::carries_strings( $data );

		// Reap links pointing at groups that are gone: a strings Replace or cleared link tables, and every Merge (its
		// orphan sweep removes string groups without strings). Before the repair, which cannot relink a string whose
		// object_lang slot a dangling row holds. Docs: docs-ai/11-code-rationale.md, "DataImporter: after the tables".
		if ( $carries_strings || ! $replace || $this->replaced_link_tables ) {
			$this->reap_orphan_string_links();
		}

		// String-graph repair (idempotent): a Merge onto an existing string leaves its links on the new unowned group, and a
		// Replace carries groups but not their links (derivable) into another site; repair_orphaned_translations() rebuilds.
		if ( ! $replace || $carries_strings || $this->replaced_link_tables ) {
			$cache = \PerfLocale\Plugin::get_instance()->has( 'cache' )
				? \PerfLocale\Plugin::get_instance()->get( 'cache' )
				: null;

			if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
				( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->repair_orphaned_translations();
			}
		}

		// Through the migration helper: all eager-link maps, not only L2, inside import() so network-import's
		// switch_to_blog() targets each blog; after the repair, so files mode generates once.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		// Template menu and pattern references follow the links this restore wrote, from the work table a page at a time;
		// without the table (a reconnect) they stay, and the Site Editor notice shows it.
		if ( null !== $this->work && $this->work->has( ImportWorkTable::LINKED_POST ) ) {
			try {
				$types = [];

				foreach ( $this->work->pages( ImportWorkTable::LINKED_POST ) as $page ) {
					$types += \PerfLocale\Translation\TemplateBlockRefs::restored_types( $page );
				}

				\PerfLocale\Translation\TemplateBlockRefs::process_restored( $types );
			} catch ( \RuntimeException $e ) {
				unset( $e );
			}
		}

		foreach ( self::urlbases_after_import() as $note ) {
			$result['notes'][] = $note;
		}

		// Added codes are never refused mid-import: the report names the root posts their prefix now takes.
		if ( [] !== $this->added_codes && \PerfLocale\Plugin::get_instance()->has( 'settings' ) ) {
			require_once dirname( __DIR__ ) . '/Translation/LanguageCodeSlugs.php';
			$settings = \PerfLocale\Plugin::get_instance()->settings();
			$names    = [];

			foreach ( $this->added_codes as $code => [ $locale, $name ] ) {
				foreach ( \PerfLocale\Translation\LanguageCodeSlugs::paths( (string) $code, $locale, $settings ) as $path ) {
					$names[ $path ] = $name;
				}
			}

			$clashes = \PerfLocale\Translation\LanguageCodeSlugs::clash_notes( $names, $settings );

			if ( [] !== $clashes ) {
				$result['slug_clashes'] = $clashes;
				$result['notes']        = array_merge( $result['notes'] ?? [], $clashes );
			}
		}

		/**
		 * Fires after DataImporter::import() restored rows and flushed caches, for both the inline and the background path
		 * (add-on caches, audit logs, notifications).
		 *
		 * @hook  perflocale/import/completed
		 * @since 1.0.0
		 *
		 * @param array{
		 *     imported:     int,
		 *     skipped:      int,
		 *     errors:       array<int,string>,
		 *     sanitized:    int,
		 *     not_applied?: array<int,string>,
		 *     notice?:      string,
		 * } $result The DataImporter result. `sanitized`: rows the untrusted-user sanitizers changed (0 for WP-CLI);
		 *           `not_applied` and `notice`: configuration a merge left unapplied.
		 * @param string $file_path Absolute path to the imported file.
		 * @param bool   $replace   Whether the import ran in replace mode.
		 */
		do_action( 'perflocale/import/completed', $result, $file_path, $replace );

		return $result;
	}

	/**
	 * Compile the URL bases the import may have changed (values, languages, pages); notes name what is not in use.
	 *
	 * @return list<string>
	 */
	private static function urlbases_after_import(): array {
		\PerfLocale\Router\UrlBases\Lifecycle::validate_none();

		if ( false === get_option( \PerfLocale\Router\UrlBases\Values::OPTION, false ) && false === get_option( \PerfLocale\Router\UrlBases\Publication::MAP, false ) && ! has_filter( \PerfLocale\Router\UrlBases\CodeValues::HOOK ) ) {
			return [];
		}

		try {
			$compiled = \PerfLocale\Router\UrlBases\Lifecycle::compile_now( 'import' );
		} catch ( \Throwable $e ) {
			// The import itself succeeded: the next admin page load compiles again.
			\PerfLocale\Router\UrlBases\Publication::mark_dirty( 'import' );

			return [ __( 'URL bases: the update after the import failed; it is retried on the next admin page load.', 'perflocale' ) ];
		}

		$issues = [] !== $compiled['report'] ? \PerfLocale\Admin\UrlBases\Health::issues( $compiled['report'] ) : [];
		$notes  = [];

		foreach ( array_slice( $issues, 0, 10 ) as $line ) {
			/* translators: %s: why a URL base is not in use */
			$notes[] = sprintf( __( 'URL bases: %s', 'perflocale' ), $line );
		}

		if ( count( $issues ) > 10 ) {
			$notes[] = __( 'URL bases: more values are not in use; see PerfLocale > Settings > URL & Routing > URL Bases.', 'perflocale' );
		}

		return $notes;
	}

	/**
	 * Mark the rewrite rules, whose pattern lists the language codes, for a rebuild.
	 *
	 * @return void
	 */
	private static function mark_rewrite_flush(): void {
		update_option( 'perflocale_flush_rules', 1, true );
		delete_transient( 'perflocale_rewrites_verified' );
	}

	/**
	 * A Merge stopped after it began writing (no transaction, its rows stay): mark the rewrite rules for a new language
	 * and flush caches, as a finished Merge does; the import's own error is reported. Sweep, reap and the DB-mode repair
	 * wait for the next run (the error asks for one; a job retries).
	 *
	 * @return void
	 */
	private function finish_stopped_merge(): void {
		try {
			if ( $this->languages_changed ) {
				self::mark_rewrite_flush();
			}

			\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Apply the file's configuration (settings, add-on settings, disabled add-ons, role grants), Replace only, after the
	 * table transaction committed: a worker killed inside it leaves both as they were, and the retry runs it all again.
	 *
	 * @param array<array-key, mixed> $data   Envelope.
	 * @param array<string, mixed>    $result Import result (updated).
	 * @phpstan-param ImportResult $result
	 * @phpstan-param-out ImportResult $result
	 * @return void
	 */
	private function apply_configuration( array $data, array &$result ): void {
		// Through the Settings singleton (validated against DEFAULTS and sanitized), so every component's in-process copy
		// is updated; a fresh instance would leave it stale.
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

			// Drop keys this build does not know first (Settings::sanitize() would fire _doing_it_wrong() for each on a plain
			// backup restore), and the secret keys: this site keeps its own API keys.
			$incoming = self::applicable_settings( $data['settings'], array_keys( $settings->get_defaults() ) );

			// Settings describing this site (domains, provider endpoints, uninstall choice, paused queue) keep its values, so a
			// staging file cannot route production's languages to staging; differing keys are named.
			$kept = [];

			foreach ( self::kept_setting_keys() as $key ) {
				if ( ! array_key_exists( $key, $incoming ) ) {
					continue;
				}

				if ( ! self::same_setting_value( $incoming[ $key ], $settings instanceof \PerfLocale\Settings ? $settings->get( $key ) : null ) ) {
					$kept[] = $key;
				}

				unset( $incoming[ $key ] );
			}

			if ( [] !== $kept ) {
				$result['kept_settings'] = $kept;
				$result['notes'][]       = self::kept_settings_notice( $kept );
			}

			// The settings screen's rule: a new agency URL must be https; this site's own value stays, http or not.
			// Settings::update() judges it again against the row it locks.
			$http_refused = array_key_exists( 'mt_agency_url', $incoming ) && \PerfLocale\Util\OutboundUrl::refuses_new_plain_http( $incoming['mt_agency_url'], $settings instanceof \PerfLocale\Settings ? $settings->get( 'mt_agency_url' ) : null );

			if ( $http_refused ) {
				unset( $incoming['mt_agency_url'] );
			}

			$saved = true;

			if ( $incoming !== [] ) {
				// False also for an unchanged save; only a held settings lock or a refused write leaves them unapplied.
				$saved        = $settings->update( $incoming ) || \PerfLocale\Settings::last_update_was_noop();
				$http_refused = $http_refused || ( $saved && array_key_exists( 'mt_agency_url', $incoming ) && \PerfLocale\Util\OutboundUrl::refused_plain_http() );

				// A provider address the update refused (multisite, key from outside the database, not a super admin) is named.
				$refused = $saved ? \PerfLocale\Settings::last_update_refused_addresses() : [];

				if ( [] !== $refused ) {
					$result['refused_addresses'] = $refused;
					$result['address_notice']    = self::refused_address_notice( $refused );
				}
			}

			if ( $http_refused ) {
				/* translators: %s: filter name */
				$result['notes'][] = sprintf( __( 'Not imported: Agency Endpoint URL, because it does not start with https:// (http:// only for a host added to the %s filter). This site keeps its own value; enter an https:// address under PerfLocale → Settings if needed.', 'perflocale' ), 'perflocale/mt/trusted_hosts' );
			}

			if ( $saved ) {
				++$result['imported'];
			} else {
				$cause             = \PerfLocale\Settings::last_update_was_busy() ? 'busy' : ( \PerfLocale\Database\OptionWriter::last_failure( 'perflocale_settings' )['reason'] ?? '' );
				$result['notes'][] = self::refusal_note( 'settings', $cause );
			}
		}

		// Add-on settings restored whole (an absent add-on's entry waits for it), then the cache cleared for the next read.
		if ( isset( $data['addon_settings'] ) && is_array( $data['addon_settings'] ) ) {
			// Through the exporter's redaction: a credential in a file is dropped; an unregistered add-on's entry is kept with
			// its credential-shaped keys stripped by name (DataExporter::CREDENTIAL_KEY_PATTERN).
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
			/** @var array<string, mixed> $file_addons */
			$file_addons = $data['addon_settings'];
			$cause       = self::write_addon_settings( DataExporter::redact_addon_credentials( $file_addons ) );

			if ( '' === $cause ) {
				++$result['imported'];
			} else {
				$result['notes'][] = self::refusal_note( 'addon_settings', $cause );
			}

			\PerfLocale\Addon\AddonSettings::reset_static_caches();
		}

		// The disabled add-on list, whole (an empty list means none disabled), through the registry for its id checks, byte
		// cap, lock and cache flush.
		if ( isset( $data['disabled_addons'] ) && is_array( $data['disabled_addons'] ) ) {
			if ( \PerfLocale\Addon\AddonRegistry::set_disabled_list( $data['disabled_addons'] ) ) {
				++$result['imported'];
			} else {
				$result['notes'][] = self::refusal_note( 'disabled_addons', \PerfLocale\Addon\AddonDisabledList::last_failure() );
			}
		}

		// Roles and capabilities.
		if ( isset( $data['roles'] ) && is_array( $data['roles'] ) ) {
			$dropped = $this->restore_roles( $data['roles'] );
			++$result['imported'];

			if ( [] !== $dropped ) {
				$result['notes'][] = sprintf(
					/* translators: %s: comma-separated capability names */
					__( 'Translator role: not restored from the file: %s. An import restores only PerfLocale capabilities and the editing capabilities the role needs; add any other capability again with your role editor.', 'perflocale' ),
					implode( ', ', $dropped )
				);
			}
		}
	}

	/**
	 * The file's settings a Replace may apply: keys this build knows, without the secret ones (this site's stay).
	 *
	 * @param array<array-key, mixed>   $file_settings The file's `settings`.
	 * @param array<int, int|string>    $known         Setting keys this build knows.
	 * @return array<array-key, mixed>
	 */
	private static function applicable_settings( array $file_settings, array $known ): array {
		$incoming = array_intersect_key( $file_settings, array_flip( $known ) );

		foreach ( DataRegistry::secret_settings() as $key ) {
			unset( $incoming[ $key ] );
		}

		return $incoming;
	}

	/**
	 * The file's applicable settings whose value does not have the setting's shape (SettingsWriter::fits_shape()).
	 *
	 * @param array<array-key, mixed> $data Envelope.
	 * @return array<int, string>
	 */
	private static function misshapen_settings( array $data ): array {
		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return [];
		}

		// The keys apply_configuration() stores: known, not secret, not kept as this site has them, not overridden.
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$defaults = method_exists( $settings, 'get_defaults' ) ? Typed::map( $settings->get_defaults() ) : [];
		$applied  = array_diff_key( self::applicable_settings( $data['settings'], array_keys( $defaults ) ), array_flip( self::kept_setting_keys() ) );
		$keys     = [];

		foreach ( $applied as $key => $value ) {
			if ( $settings instanceof \PerfLocale\Settings && $settings->is_overridden( (string) $key ) ) {
				continue;
			}

			if ( ! \PerfLocale\Util\SettingsWriter::fits_shape( (string) $key, $value, $defaults[ (string) $key ] ?? null ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * The settings a Replace import leaves as this site has them.
	 *
	 * @return array<int, string>
	 */
	public static function kept_setting_keys(): array {
		$defaults = [ 'language_domains', 'mt_libre_url', 'mt_agency_url', 'mt_agency_batch', 'mt_agency_max_chars', 'delete_data_on_uninstall', 'background_paused' ];

		/**
		 * Filters the settings a Replace import keeps as this site has them: those describing the site, not its data
		 * (language domains, provider endpoints, uninstall choice, paused queue); the report names any the file had
		 * differently. Return [] to apply them all, except a new agency URL that is not https (or http:// to a
		 * perflocale/mt/trusted_hosts host); API keys are never in a file.
		 *
		 * @hook  perflocale/import/keep_target_settings
		 * @since 1.0.7.3
		 *
		 * @param array<int, string> $keys Setting keys. Default: language_domains,
		 *                                 mt_libre_url, mt_agency_url, mt_agency_batch,
		 *                                 mt_agency_max_chars, delete_data_on_uninstall,
		 *                                 background_paused.
		 * @return array<int, string>
		 */
		$keys = apply_filters( 'perflocale/import/keep_target_settings', $defaults );

		return self::setting_keys_from( $keys, $defaults );
	}

	/**
	 * The keys a keep_target_settings callback returned: its non-empty strings, or the defaults for a non-array.
	 *
	 * @param mixed              $keys     What the filter returned.
	 * @param array<int, string> $defaults The default keys.
	 * @return array<int, string>
	 */
	private static function setting_keys_from( $keys, array $defaults ): array {
		return is_array( $keys ) ? array_values( array_filter( Typed::strs( $keys ), static fn( string $k ): bool => '' !== $k ) ) : $defaults;
	}

	/**
	 * Whether a setting's value in the file equals this site's.
	 *
	 * @param mixed $file Value in the file.
	 * @param mixed $site This site's value.
	 * @return bool
	 */
	private static function same_setting_value( $file, $site ): bool {
		if ( is_bool( $site ) ) {
			return (bool) $file === $site;
		}

		if ( is_array( $site ) || is_array( $file ) ) {
			$a = is_array( $file ) ? $file : [];
			$b = is_array( $site ) ? $site : [];
			ksort( $a );
			ksort( $b );

			return wp_json_encode( $a ) === wp_json_encode( $b );
		}

		return ( is_scalar( $file ) ? (string) $file : '' ) === ( is_scalar( $site ) ? (string) $site : '' );
	}

	/**
	 * The report sentence for the settings a Replace kept as this site has them.
	 *
	 * @param array<int, string> $kept Setting keys.
	 * @return string
	 */
	public static function kept_settings_notice( array $kept ): string {
		$labels = [
			'language_domains'         => __( 'Per-language domain', 'perflocale' ),
			'mt_libre_url'             => __( 'LibreTranslate URL', 'perflocale' ),
			'mt_agency_url'            => __( 'Agency Endpoint URL', 'perflocale' ),
			'mt_agency_batch'          => __( 'My endpoint accepts batches', 'perflocale' ),
			'mt_agency_max_chars'      => __( 'Maximum characters per request', 'perflocale' ),
			'delete_data_on_uninstall' => __( 'Delete all plugin data when uninstalling', 'perflocale' ),
			'background_paused'        => __( 'Pause queue', 'perflocale' ),
		];
		$names  = [];

		foreach ( $kept as $key ) {
			$names[] = $labels[ $key ] ?? $key;
		}

		return sprintf(
			/* translators: %s: comma-separated setting names, for example "Per-language domain, Pause queue". */
			__( 'Kept this site\'s own value for: %s. These settings belong to the site, so Replace does not take them from the file; change them under PerfLocale → Settings if needed.', 'perflocale' ),
			implode( ', ', $names )
		);
	}

	/**
	 * The report sentence for the provider address settings the update refused.
	 *
	 * @param array<int, string> $refused Refused settings (mt_libre_url, mt_agency_url).
	 * @return string
	 */
	public static function refused_address_notice( array $refused ): string {
		$labels = [
			'mt_libre_url'  => __( 'LibreTranslate URL', 'perflocale' ),
			'mt_agency_url' => __( 'Agency Endpoint URL', 'perflocale' ),
		];
		$names  = [];

		foreach ( $refused as $key ) {
			$names[] = $labels[ $key ] ?? $key;
		}

		return sprintf(
			/* translators: %s: the provider address settings that were not imported, for example "LibreTranslate URL". */
			__( 'Not imported: %s. This provider\'s key is set by the network administrator; ask them to change the address.', 'perflocale' ),
			implode( ', ', $names )
		);
	}

	/**
	 * The configuration keys and `data` members no declared section carries (DataRegistry::section_transports()). No
	 * `sections`: everything kept; a non-list declares nothing. Add-on part lists belong to Add-on data, a group part
	 * also when named in `addon_groups`; an unknown member goes to the unread report.
	 *
	 * @param array<array-key, mixed>  $data    Envelope.
	 * @param array<int, int|string>   $members Members of `data`.
	 * @return array{envelope: list<string>, data: list<string>}
	 */
	private static function undeclared_members( array $data, array $members ): array {
		$out = [
			'envelope' => [],
			'data'     => [],
		];

		if ( ! array_key_exists( 'sections', $data ) ) {
			return $out;
		}

		$declared = array_values( array_unique( array_filter( Typed::strs( $data['sections'] ) ) ) );
		$allowed  = [];

		foreach ( $declared as $section ) {
			foreach ( DataRegistry::section_transports( $section ) as $transport ) {
				$allowed[ $transport ] = true;
			}
		}

		foreach ( self::CONFIGURATION_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) && ! isset( $allowed[ $key ] ) ) {
				$out['envelope'][] = $key;
			}
		}

		$addon_declared = in_array( DataRegistry::ADDON_SECTION, $declared, true );
		$group_parts    = Typed::map( $data[ DataRegistry::ADDON_GROUPS_KEY ] ?? [] );

		foreach ( $members as $member ) {
			$member = (string) $member;
			$part   = DataRegistry::parse_part_member( $member );

			if ( null !== $part ) {
				if ( ! $addon_declared && ! array_key_exists( $part[0], $group_parts ) ) {
					$out['data'][] = $member;
				}

				continue;
			}

			$known = in_array( $member, DataRegistry::import_tables(), true ) || in_array( $member, DataRegistry::ROW_LISTS, true );

			if ( $known && ! isset( $allowed[ 'data.' . $member ] ) ) {
				$out['data'][] = $member;
			}
		}

		return $out;
	}

	/**
	 * The number of failed statements in wpdb's global $EZSQL_ERROR.
	 *
	 * @return int
	 */
	private static function db_error_count(): int {
		return isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] ) ? count( $GLOBALS['EZSQL_ERROR'] ) : 0;
	}

	/**
	 * Drop the duplicate-key failures added to $EZSQL_ERROR after the first $from (a Merge's expected skips, about 1 KB
	 * each, would grow with the file); other failures stay, in order.
	 *
	 * @param int $from Entries to keep untouched.
	 * @return void
	 */
	private static function forget_duplicate_errors( int $from ): void {
		if ( ! isset( $GLOBALS['EZSQL_ERROR'] ) || ! is_array( $GLOBALS['EZSQL_ERROR'] ) || count( $GLOBALS['EZSQL_ERROR'] ) <= $from ) {
			return;
		}

		$kept = [];

		foreach ( array_splice( $GLOBALS['EZSQL_ERROR'], $from ) as $entry ) {
			$text = is_array( $entry ) && isset( $entry['error_str'] ) && is_string( $entry['error_str'] ) ? $entry['error_str'] : '';

			if ( ! str_starts_with( $text, 'Duplicate entry' ) && ! str_contains( $text, 'UNIQUE constraint failed' ) ) {
				$kept[] = $entry;
			}
		}

		if ( [] !== $kept ) {
			array_splice( $GLOBALS['EZSQL_ERROR'], $from, 0, $kept );
		}
	}

	/**
	 * The replace wipe plan from the envelope's `sections` (DataExporter::sections()): table => type scope. None or empty
	 * wipes nothing. The widest claim wins; a scoped slice is wiped only when the file carries rows for it, an unscoped
	 * table when carried as a list; Translation Links without String Translations gets `!string`.
	 *
	 * @param array<array-key, mixed> $data Envelope; `data` maps each table to
	 *                                      its rows or to its row count.
	 * @return array<string, string|null> Table short-name => type scope (null =
	 *                                    whole table; `!type` = every row whose
	 *                                    type is not `type`).
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

		$all = DataExporter::sections();

		foreach ( $sections as $section_key ) {
			if ( ! isset( $all[ $section_key ] ) ) {
				continue;
			}
			foreach ( $all[ $section_key ]['tables'] as $tbl ) {
				$tbl   = (string) $tbl;
				$scope = $all[ $section_key ]['type_scope'][ $tbl ] ?? null;

				if ( $scope !== null && empty( $carried[ $tbl ] ) ) {
					continue;
				}

				// A row list is a count (the reader's summary) or the rows; an absent table is not carried.
				if ( $scope === null && ! is_int( $carried[ $tbl ] ?? null ) && ! is_array( $carried[ $tbl ] ?? null ) ) {
					continue;
				}

				if ( $scope === null || ! array_key_exists( $tbl, $scopes ) ) {
					$scopes[ $tbl ] = $scope;
				}
			}
		}

		if ( in_array( 'translations', $sections, true ) && ! in_array( 'strings', $sections, true ) ) {
			foreach ( self::STRING_SLICE_TABLES as $tbl ) {
				if ( array_key_exists( $tbl, $scopes ) && null === $scopes[ $tbl ] ) {
					$scopes[ $tbl ] = '!string';
				}
			}
		}

		// In import order, so parents come before children whatever order `sections` has.
		$plan = [];
		foreach ( DataRegistry::import_tables() as $table_name ) {
			if ( array_key_exists( $table_name, $scopes ) ) {
				$plan[ $table_name ] = $scopes[ $table_name ];
			}
		}

		return $plan;
	}

	/**
	 * What the import does with the file's add-on parts (`addon_data/<part>/<row list>`): a chosen part this site
	 * declares is read against that part's entries only; an undeclared part of an add-on with other parts against those;
	 * otherwise `inactive`. A chosen-out part with rows is `unchosen`.
	 *
	 * @param ImportReader            $reader  Indexed reader.
	 * @param array<array-key, mixed> $data    Envelope.
	 * @param mixed                   $choices The `addon_choices` option.
	 * @return array{shared: bool, members: array<string, array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5: string}>>, inactive: list<string>, unchosen: list<string>}
	 */
	private static function addon_plan( ImportReader $reader, array $data, $choices ): array {
		$chosen = null === $choices ? null : array_values( array_filter( is_array( $choices ) ? $choices : [], 'is_string' ) );
		$shared = null === $chosen || in_array( DataRegistry::ADDON_SECTION, $chosen, true );
		$parts  = DataRegistry::addon_parts();
		$out    = [
			'shared'   => $shared,
			'members'  => [],
			'inactive' => [],
			'unchosen' => [],
		];

		if ( ! $shared && in_array( DataRegistry::ADDON_SECTION, Typed::strs( $data['sections'] ?? [] ), true ) ) {
			/* translators: export section: data add-ons store, for example Gravity Forms translations. */
			$out['unchosen'][] = __( 'Add-on data', 'perflocale' );
		}

		$labels = [];

		foreach ( Typed::map( $data[ DataRegistry::ADDON_GROUPS_KEY ] ?? [] ) as $part => $label ) {
			$label = is_string( $label ) ? sanitize_text_field( $label ) : '';

			if ( '' !== $label && strlen( $label ) <= DataRegistry::GROUP_LABEL_MAX_BYTES ) {
				$labels[ (string) $part ] = $label;
			}
		}

		foreach ( array_keys( $reader->table_summary() ) as $member ) {
			$parsed = DataRegistry::parse_part_member( (string) $member );

			if ( null === $parsed ) {
				continue;
			}

			[ $part, $list ] = $parsed;

			$grouped = str_contains( $part, '/' );
			$rows    = $reader->count( $member ) > 0;

			// Not declared here while its add-on declares other parts (groups changed since the export): read against that
			// add-on's chosen parts, each row matched by name.
			if ( ! isset( $parts[ $part ] ) ) {
				$owner    = (string) strtok( $part, '/' );
				$declared = false;
				$siblings = [];
				$unticked = [];

				foreach ( $parts as $sibling => $p ) {
					if ( $owner !== $p['owner'] ) {
						continue;
					}

					$declared = true;

					if ( null === $p['group'] ? $shared : ( null === $chosen || in_array( $sibling, $chosen, true ) ) ) {
						$siblings[] = (string) $sibling;
					} elseif ( null !== $p['group'] ) {
						$unticked[] = (string) $p['label'];
					}
				}

				if ( [] !== $siblings ) {
					$kind  = (string) array_search( $list, DataRegistry::ROW_LISTS, true );
					$items = [];

					foreach ( $siblings as $sibling ) {
						$items = array_merge( $items, DataExporter::part_items( $kind, $sibling ) );
					}

					$out['members'][ (string) $member ] = $items;

					continue;
				}

				// All its add-on's parts chosen out: named as not chosen.
				if ( $declared ) {
					if ( $rows ) {
						$out['unchosen'] = array_merge( $out['unchosen'], $unticked );
					}

					continue;
				}

				// Its add-on declares nothing here (no checkbox): named as not active whatever was chosen.
				if ( $rows ) {
					/* translators: %s: an add-on's id as the export file names it, for example ext:acme. */
					$out['inactive'][] = $grouped ? ( $labels[ $part ] ?? $part ) : sprintf( __( 'Add-on data (%s)', 'perflocale' ), $part );
				}

				continue;
			}

			if ( ! ( $grouped ? null === $chosen || in_array( $part, $chosen, true ) : $shared ) ) {
				if ( $grouped && $rows ) {
					$out['unchosen'][] = (string) $parts[ $part ]['label'];
				}

				continue;
			}

			$kind = (string) array_search( $list, DataRegistry::ROW_LISTS, true );

			$out['members'][ (string) $member ] = DataExporter::part_items( $kind, $part );
		}

		$out['inactive'] = array_values( array_unique( $out['inactive'] ) );
		$out['unchosen'] = array_values( array_unique( $out['unchosen'] ) );

		return $out;
	}

	/**
	 * Delete every string scope's deferred-removal record (PENDING_REMOVALS_PREFIX options) in the Replace transaction.
	 * The names are read first so their options caches are dropped too.
	 *
	 * @return bool False when a statement failed (logged).
	 */
	private static function clear_scope_removal_records(): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$like = $wpdb->esc_like( \PerfLocale\Database\Repository\StringRepository::PENDING_REMOVALS_PREFIX ) . '%';
		$sql  = $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $like );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$read  = is_string( $sql ) && '' !== $sql ? $wpdb->query( $sql ) : false;
		$names = is_int( $read ) ? array_map( static fn( $name ): string => is_scalar( $name ) ? (string) $name : '', $wpdb->get_col() ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The result of the checked read above; no query runs here.

		if ( null === $names ) {
			self::log_db_error( 'reading the string scope records', $wpdb->last_error );

			return false;
		}

		if ( [] === $names ) {
			return true;
		}

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; the options caches are dropped below.
		$deleted = is_string( $sql ) && '' !== $sql ? $wpdb->query( $sql ) : false;

		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		\PerfLocale\Database\NotOptions::unlist( $names );

		if ( false === $deleted ) {
			self::log_db_error( 'clearing the string scope records', $wpdb->last_error );

			return false;
		}

		return true;
	}

	/**
	 * Close the site's pending string changes (target_gone) in the Replace transaction.
	 *
	 * @param string $guard The transaction's connection-guard condition.
	 * @return bool False when the statement failed (logged).
	 */
	private static function close_pending_strings( string $guard ): bool {
		if ( null !== ( new \PerfLocale\Database\Repository\PendingChangeRepository() )->close_pending_of_type( 'string', 'target_gone', $guard ) ) {
			return true;
		}

		$wpdb = $GLOBALS['wpdb'] ?? null;
		self::log_db_error( 'closing pending string changes', $wpdb instanceof \wpdb ? $wpdb->last_error : '' );

		return false;
	}

	/**
	 * Whether this wipe plan closes the pending string changes: it clears the strings or languages their ids name.
	 *
	 * @param array<string, string|null> $wipe_plan See tables_to_wipe().
	 * @return bool
	 */
	private static function closes_pending_strings( array $wipe_plan ): bool {
		return ( array_key_exists( 'strings', $wipe_plan ) || array_key_exists( 'languages', $wipe_plan ) ) && \PerfLocale\Automation\ChangeService::available();
	}

	/**
	 * Why a Replace with this wipe plan must wait for Content ReSync, or ''.
	 * Stale intents (crashed writes) are reconciled first; any intent still open refuses (a write in progress, or one
	 * not repairable yet). The transaction repeats the check before its first DELETE.
	 *
	 * @param array<string, string|null> $wipe_plan See tables_to_wipe().
	 * @return string The refusal, '' to go on.
	 */
	private static function open_resync_intents( array $wipe_plan ): string {
		// A string Apply or Undo in flight: its put-back would land over the imported value.
		if ( self::wipes_string_intents( $wipe_plan ) ) {
			\PerfLocale\Automation\ChangeService::settle_string_intents( self::RESYNC_REPAIR_BATCH );

			if ( 0 !== ( new \PerfLocale\Database\Repository\PendingChangeRepository() )->string_intents_for_update() ) {
				return self::resync_writing_refusal();
			}
		}

		if ( ! self::wipes_resync_intents( $wipe_plan ) ) {
			return '';
		}

		// A driver ReSync does not run on cannot repair an interrupted write: an intent left from another driver refuses.
		if ( ! \PerfLocale\Resync\Store::available() ) {
			return 0 === ( new \PerfLocale\Database\Repository\ResyncRepository() )->open_intents_for_update() ? '' : \PerfLocale\Resync\Store::unavailable_text();
		}

		\PerfLocale\Resync\SyncRecorder::reconcile_stale( static fn (): bool => true, self::RESYNC_REPAIR_BATCH );

		$open = \PerfLocale\Resync\SyncRecorder::open_intents();

		if ( null !== $open && 0 === $open['fresh'] && 0 === $open['stale'] ) {
			return '';
		}

		if ( null !== $open && 0 === $open['fresh'] ) {
			return __( 'Import refused. Content ReSync could not repair an interrupted update of a translation yet, and a Replace import would delete the record it needs for that. Run the import again in a few minutes.', 'perflocale' );
		}

		return self::resync_writing_refusal();
	}

	/**
	 * The refusal of a Replace while a Content ReSync write is in progress.
	 *
	 * @return string
	 */
	private static function resync_writing_refusal(): string {
		return __( 'Import refused. Content ReSync is writing an update to a translation right now, and a Replace import would delete the record that keeps that write safe. Run the import again in a few minutes.', 'perflocale' );
	}

	/**
	 * Whether this wipe plan replaces what a string Apply or Undo writes or records: the strings, their values, links
	 * or the pending changes.
	 *
	 * @param array<string, string|null> $wipe_plan See tables_to_wipe().
	 * @return bool
	 */
	private static function wipes_string_intents( array $wipe_plan ): bool {
		return [] !== array_intersect_key( $wipe_plan, array_flip( [ 'strings', 'string_translations', 'translation_links', 'pending_changes' ] ) ) && \PerfLocale\Automation\ChangeService::available();
	}

	/**
	 * Whether this wipe plan deletes ReSync intents: it clears `translation_links` or `pending_changes`.
	 *
	 * @param array<string, string|null> $wipe_plan See tables_to_wipe().
	 * @return bool
	 */
	private static function wipes_resync_intents( array $wipe_plan ): bool {
		return ( array_key_exists( 'translation_links', $wipe_plan ) || array_key_exists( 'pending_changes', $wipe_plan ) ) && \PerfLocale\Resync\Store::has_tables();
	}

	/**
	 * Does the envelope carry `strings` rows? Gates the Replace half of the string reap and repair; Merge runs both
	 * always (its orphan sweep can strand links in any merge).
	 *
	 * @param array<string,mixed> $data Decoded envelope.
	 * @return bool True when the envelope's `data` holds at least one strings row.
	 */
	private static function carries_strings( array $data ): bool {
		return self::has_rows( $data, 'strings' );
	}

	/**
	 * Does the envelope's `data` carry a row for this table?
	 *
	 * @param array<array-key, mixed> $data  Envelope: `data` maps each table
	 *                                       to its row count (import()), or
	 *                                       to its decoded rows.
	 * @param string                  $table Table short-name.
	 * @return bool
	 */
	private static function has_rows( array $data, string $table ): bool {
		$carried = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : [];
		$rows    = $carried[ $table ] ?? null;

		return is_int( $rows ) ? $rows > 0 : ( is_array( $rows ) && $rows !== [] );
	}

	/**
	 * Whether the envelope carries rows keyed by a post, term or string id (translation_links, slug_translations,
	 * content_hashes, meta lists, add-on parts; in Replace mode pending_changes). Strings match on original_hash and are
	 * portable. Pure: network-import reuses one instance.
	 *
	 * @param array<array-key, mixed> $data    Decoded envelope.
	 * @param array<int, string>      $members The add-on part row lists the import reads.
	 * @param bool                    $replace Replace mode (Merge skips the automation history).
	 * @return bool
	 */
	private static function carries_object_ids( array $data, array $members = [], bool $replace = true ): bool {
		$sections = Typed::strs( $data['sections'] ?? [] );

		foreach ( $members as $member ) {
			if ( self::has_rows( $data, $member ) ) {
				return true;
			}
		}

		foreach ( DataRegistry::object_id_tables() as $table ) {
			if ( ( $replace || 'pending_changes' !== $table ) && self::has_rows( $data, $table ) ) {
				return true;
			}
		}

		// Meta lists name posts, terms and orders; Add-on data's option rows name forms.
		foreach ( DataRegistry::ROW_LISTS as $kind => $list ) {
			if ( 'option' !== $kind && self::has_rows( $data, $list ) ) {
				return true;
			}
		}

		$id_keyed = array_values( array_intersect( $sections, DataRegistry::id_keyed_sections() ) );

		return [] !== DataExporter::row_list_items( 'option', $id_keyed ) && self::has_rows( $data, DataRegistry::ROW_LISTS['option'] );
	}

	/**
	 * Does the envelope carry rows naming a language by numeric id (the tables whose language_id fk_refs() points at
	 * languages)? Those ids are remapped only while the file's own languages section is imported, in Merge.
	 *
	 * @param array<array-key, mixed> $data    Decoded envelope.
	 * @param bool                    $replace Replace mode (Merge skips the automation history).
	 * @return bool
	 */
	private static function carries_language_refs( array $data, bool $replace = true ): bool {
		foreach ( DataRegistry::language_ref_tables() as $table ) {
			if ( ( $replace || 'pending_changes' !== $table ) && self::has_rows( $data, $table ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does the address recorded in a file name this site? The comparison the import gate and network-import's slice guard
	 * share; null (absent, or no host) is for the caller to judge. Scheme-agnostic, port-sensitive; checked against the
	 * raw `home` option and home_url() (older files carry the filtered form). Catches an honest mistake, no more.
	 *
	 * @param mixed $site_url The envelope's `site_url` value, exactly as the
	 *                        JSON decoder produced it.
	 * @return bool|null True or false when the value names an address; null
	 *                   when it is absent or has no host to compare. The
	 *                   caller decides what null means.
	 */
	public static function bundle_matches_this_site( $site_url ): ?bool {
		$theirs = self::site_identity( $site_url );

		if ( null === $theirs ) {
			return null;
		}

		foreach ( [ get_option( 'home' ), home_url() ] as $ours ) {
			if ( $theirs === self::site_identity( $ours ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A site address as the gate compares it: lowercase host, a non-default port, the path without a trailing slash.
	 *
	 * @param mixed $url Candidate address. Anything at all: the envelope's
	 *                   value is whatever the file put there, and the quality
	 *                   gate never looks at it.
	 * @return string|null Normalized identity, or null when there is no host.
	 */
	private static function site_identity( $url ): ?string {
		if ( ! is_string( $url ) || trim( $url ) === '' ) {
			return null;
		}

		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		// Only the scheme's own default port is implicit: http://host:443 is another address than http://host.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}

		return strtolower( (string) $parts['host'] )
			. ( $port > 0 ? ':' . $port : '' )
			. untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * Validate the export structure.
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

		// Refuse files from a newer major version: their schema may have tables or columns this one lacks.
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

		// The envelope format version changes only with an incompatible shape: newer is refused, older accepted (every
		// section's shape is validated below).
		if ( isset( $data['format_version'] ) ) {
			$incoming = (int) $data['format_version'];

			if ( $incoming > DataExporter::READ_VERSION ) {
				$errors[] = sprintf(
					/* translators: 1: incoming format version, 2: current format version */
					__( 'This export uses format version %1$d, but this build only understands up to %2$d. Update PerfLocale before importing.', 'perflocale' ),
					$incoming,
					DataExporter::READ_VERSION
				);
			}
		}

		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			$errors[] = __( 'Export data section is missing or invalid.', 'perflocale' );
		}

		return $errors;
	}

	/**
	 * The default `max_rows_per_table`: the smaller of the size ceiling (largest accepted file / MIN_ROW_BYTES) and the
	 * memory ceiling (RETAINED_BYTES_PER_ROW per row within RETAINED_MEMORY_SHARE % of free memory; none without a
	 * memory_limit), never below LEGACY_MAX_ROWS. A quality_limits value replaces it.
	 *
	 * @return int
	 */
	private static function default_max_rows(): int {
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;

		return self::max_rows_for( intdiv( max( 0, AdminController::import_max_bytes() ), self::MIN_ROW_BYTES ), $limit, memory_get_usage() );
	}

	/**
	 * The default row ceiling for a size ceiling, a memory limit and the memory in use (default_max_rows()).
	 *
	 * @param int $size_rows Rows the largest accepted file can hold.
	 * @param int $limit     PHP's memory_limit in bytes; 0 or less for none.
	 * @param int $used      Memory in use now, in bytes.
	 * @return int
	 */
	private static function max_rows_for( int $size_rows, int $limit, int $used ): int {
		$size_rows = max( self::LEGACY_MAX_ROWS, $size_rows );

		if ( $limit <= 0 ) {
			return $size_rows;
		}

		// The share from quotient and remainder, so no step exceeds the free bytes (the product would overflow on 32-bit PHP).
		$free        = max( 0, $limit - $used );
		$memory_rows = intdiv( intdiv( $free, 100 ) * self::RETAINED_MEMORY_SHARE + intdiv( $free % 100 * self::RETAINED_MEMORY_SHARE, 100 ), self::RETAINED_BYTES_PER_ROW );

		return min( $size_rows, max( self::LEGACY_MAX_ROWS, $memory_rows ) );
	}

	/**
	 * Data-quality check before any write: invalid UTF-8 (MySQL truncates at the bad byte), null bytes, cardinality
	 * bombs, oversize values and malformed rows, which json_decode() accepts. Pure; reports stop after
	 * MAX_QUALITY_ERRORS.
	 *
	 * @param array<string, mixed> $data   Decoded export envelope.
	 * @param array<string, int>   $limits Override limits (used by tests):
	 *                                     max_languages, max_rows_per_table,
	 *                                     max_value_bytes.
	 * @return array<int, string> Quality errors (empty = clean).
	 */
	public static function find_data_quality_issues( array $data, array $limits = [] ): array {
		$tables = ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : [];
		$shape  = [];

		foreach ( $tables as $table => $rows ) {
			$shape[ $table ] = is_array( $rows ) ? count( $rows ) : null;
		}

		return self::quality_issues(
			$shape,
			static fn( int|string $table ): array => is_array( $tables[ $table ] ?? null ) ? $tables[ $table ] : [],
			static fn( int|string $table ) => $tables[ $table ] ?? null,
			$data,
			$limits
		);
	}

	/**
	 * The data-quality gate over a file read in pieces (the checks and messages of find_data_quality_issues()), each row
	 * decoded once; also notes whether $sources rows name objects ($presence['rows']) and carry fingerprints ('fp').
	 *
	 * @param ImportReader                                  $reader   Indexed reader.
	 * @param array<string, mixed>                          $data     Envelope (top-level members).
	 * @param array<array-key, mixed>                       $limits   Quality limits.
	 * @param array<string, array{0: string, 1: string}>    $sources  Row lists to watch (fingerprint_sources()).
	 * @param array{rows: bool, fp: bool}                   $presence What the watched rows hold (updated).
	 * @return array<int, string>
	 * @throws \UnexpectedValueException When a row is not valid JSON or is over the size limit.
	 */
	private static function stream_quality_issues( ImportReader $reader, array $data, array $limits, array $sources = [], array &$presence = [ 'rows' => false, 'fp' => false ] ): array {
		$summary = $reader->table_summary();
		$shape   = [];

		foreach ( array_keys( $summary ) as $table ) {
			$shape[ $table ] = $reader->is_row_list( $table ) ? $reader->count( $table ) : null;
		}

		$max_row = self::max_section_bytes( $limits );

		$rows_of = static function ( int|string $table ) use ( $reader, $max_row, $sources, &$presence ): iterable {
			$rows = self::beating( $reader->rows( $table, $max_row ) );

			return isset( $sources[ (string) $table ] ) ? self::watch_fingerprints( $rows, $sources[ (string) $table ], $presence ) : $rows;
		};

		return self::quality_issues(
			$shape,
			$rows_of,
			static fn( int|string $table ) => $summary[ $table ] ?? null,
			$data,
			$limits
		);
	}

	/**
	 * Pass rows through, keeping the import lock alive as they are read.
	 *
	 * @param iterable<array-key, mixed> $rows Rows.
	 * @return \Generator<array-key, mixed>
	 */
	private static function beating( iterable $rows ): \Generator {
		foreach ( $rows as $key => $row ) {
			ImportLock::heartbeat();

			yield $key => $row;
		}
	}

	/**
	 * Pass rows through, noting whether the id-keyed ones carry a fingerprint (stream_quality_issues()).
	 *
	 * @param iterable<array-key, mixed>   $rows     Rows.
	 * @param array{0: string, 1: string}  $source   [ the column naming the object's kind, or '' ; the fixed kind, or '' ].
	 * @param array{rows: bool, fp: bool}  $presence What the rows hold (updated).
	 * @return \Generator<array-key, mixed>
	 */
	private static function watch_fingerprints( iterable $rows, array $source, array &$presence ): \Generator {
		[ $column, $fixed ] = $source;

		foreach ( $rows as $key => $row ) {
			if ( ! $presence['fp'] && is_array( $row ) && is_numeric( $row['object_id'] ?? null )
				&& '' !== ( '' !== $fixed ? $fixed : ObjectFingerprint::kind( $row[ $column ] ?? null ) ) ) {
				$presence['rows'] = true;
				$presence['fp']   = isset( $row[ ObjectFingerprint::FIELD ] );
			}

			yield $key => $row;
		}
	}

	/**
	 * Whether a file's `version` is IDENTITY_SINCE or later (by its leading numbers; none means older).
	 *
	 * @param mixed $version The envelope's `version`.
	 * @return bool
	 */
	private static function records_identity( $version ): bool {
		if ( ! is_string( $version ) || 1 !== preg_match( '/^[0-9]+(?:\.[0-9]+)*/', $version, $m ) ) {
			return false;
		}

		return version_compare( $m[0], self::IDENTITY_SINCE, '>=' );
	}

	/**
	 * Row lists whose rows can carry a fingerprint: list => [ column naming the kind, or '' ; fixed kind, or '' ].
	 *
	 * @param array<string, mixed> $members The add-on part row lists the import reads (addon_plan()).
	 * @param bool                 $replace Replace mode (Merge skips the automation history, so it is not watched).
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function fingerprint_sources( array $members, bool $replace = true ): array {
		$sources = [];

		foreach ( DataExporter::fingerprint_columns() as $table => $column ) {
			$sources[ $table ] = [ $column, '' ];
		}

		if ( ! $replace ) {
			unset( $sources['pending_changes'] );
		}

		$kinds = [
			'post_meta'  => 'post',
			'term_meta'  => 'term',
			'order_meta' => 'order',
		];

		foreach ( $kinds as $list => $kind ) {
			$sources[ $list ] = [ '', $kind ];
		}

		foreach ( array_keys( $members ) as $member ) {
			$list = DataRegistry::parse_part_member( (string) $member )[1] ?? '';

			if ( isset( $kinds[ $list ] ) ) {
				$sources[ (string) $member ] = [ '', $kinds[ $list ] ];
			}
		}

		return $sources;
	}

	/**
	 * The data-quality checks, over any source of rows.
	 *
	 * @param array<array-key, int|null>                       $shape    Table => row count, or null when the member is not a row list.
	 * @param callable(int|string): iterable<array-key, mixed> $rows_of  The table's rows, row key => row.
	 * @param callable(int|string): mixed                      $value_of The member's value when it is not a row list.
	 * @param array<array-key, mixed>                          $data     Envelope (settings, addon_settings and roles are scanned).
	 * @param array<array-key, mixed>                          $limits   Quality limits.
	 * @return array<int, string>
	 */
	private static function quality_issues( array $shape, callable $rows_of, callable $value_of, array $data, array $limits ): array {
		$max_languages = Typed::int( $limits['max_languages'] ?? 500, 500 );
		$default_rows  = self::default_max_rows();
		$max_rows      = Typed::int( $limits['max_rows_per_table'] ?? $default_rows, $default_rows );
		$max_value     = Typed::int( $limits['max_value_bytes'] ?? 1048576, 1048576 ); // 1 MB per scalar value.
		$max_errors    = 20;

		$errors = [];

		// Cardinality first: cheap, and it stops a bomb before the value scan walks it.
		if ( isset( $shape['languages'] ) && $shape['languages'] > $max_languages ) {
			$errors[] = sprintf(
				/* translators: 1: language count in the file, 2: maximum allowed. */
				__( 'The export contains %1$s languages; the importer accepts at most %2$s. This usually indicates a corrupt or non-PerfLocale file.', 'perflocale' ),
				number_format_i18n( $shape['languages'] ),
				number_format_i18n( $max_languages )
			);
		}

		foreach ( $shape as $table => $count ) {
			if ( null === $count ) {
				$errors[] = sprintf(
					/* translators: %s: table name. */
					__( 'Table "%s" is not a row list.', 'perflocale' ),
					(string) $table
				);
				continue;
			}

			if ( $count > $max_rows ) {
				$errors[] = sprintf(
					/* translators: 1: table name, 2: row count, 3: maximum allowed. "perflocale/import/quality_limits" and "max_rows_per_table" are code names and stay in English. */
					__( 'Table "%1$s" has %2$s rows, more than the %3$s this importer accepts per table. A developer can raise the limit (max_rows_per_table) with the perflocale/import/quality_limits filter.', 'perflocale' ),
					(string) $table,
					number_format_i18n( $count ),
					number_format_i18n( $max_rows )
				);
			}
		}

		if ( count( $errors ) >= $max_errors ) {
			return $errors;
		}

		// Every string of every row, then of settings, add-on settings and roles: invalid UTF-8, null bytes, size. Every
		// row is read here, so invalid JSON stops the import before a write; shape errors are reported after.
		$shape_errors = [];

		foreach ( $shape as $table => $count ) {
			if ( null === $count ) {
				$errors = array_merge( $errors, self::scan_value( $value_of( $table ), 'data.' . (string) $table, $max_errors - count( $errors ), $max_value ) );
			} else {
				foreach ( $rows_of( $table ) as $i => $row ) {
					if ( ! is_array( $row ) && count( $shape_errors ) < $max_errors ) {
						$shape_errors[] = sprintf(
							/* translators: 1: table name, 2: row index. */
							__( 'Table "%1$s" row %2$d is not a column map - the file is malformed.', 'perflocale' ),
							(string) $table,
							(int) $i
						);
					}

					// Replace keeps ids, and an id near the column's top moves the next id there so every later insert fails.
					if ( is_array( $row ) && isset( $row['id'] ) && ( ! is_numeric( $row['id'] ) || (float) $row['id'] > self::MAX_ROW_ID ) && count( $shape_errors ) < $max_errors ) {
						$shape_errors[] = sprintf(
							/* translators: 1: table name, 2: row index. */
							__( 'Table "%1$s" row %2$d has an ID that is not a number or is larger than any real ID - the file is malformed.', 'perflocale' ),
							(string) $table,
							(int) $i
						);
					}

					$errors = array_merge( $errors, self::scan_value( $row, 'data.' . (string) $table . '.' . (string) $i, $max_errors - count( $errors ), $max_value ) );

					if ( count( $errors ) >= $max_errors ) {
						return $errors;
					}
				}
			}

			if ( count( $errors ) >= $max_errors ) {
				return $errors;
			}
		}

		foreach ( [ 'settings', 'addon_settings', 'roles' ] as $section ) {
			if ( isset( $data[ $section ] ) && is_array( $data[ $section ] ) ) {
				$errors = array_merge( $errors, self::scan_value( $data[ $section ], $section, $max_errors - count( $errors ), $max_value ) );

				if ( count( $errors ) >= $max_errors ) {
					return $errors;
				}
			}
		}

		return array_merge( $errors, array_slice( $shape_errors, 0, $max_errors - count( $errors ) ) );
	}

	/**
	 * Check a value and every string inside it for a null byte, invalid UTF-8 and size; recursion passes the error
	 * budget down, so the cap holds at every level.
	 *
	 * @param mixed  $value     Value.
	 * @param string $path      JSON path of the value, for the messages.
	 * @param int    $budget    Most errors to return.
	 * @param int    $max_value Largest string, in bytes.
	 * @return array<int, string>
	 */
	private static function scan_value( $value, string $path, int $budget, int $max_value ): array {
		if ( $budget <= 0 ) {
			return [];
		}

		if ( is_string( $value ) ) {
			if ( strpos( $value, "\0" ) !== false ) {
				return [
					sprintf(
						/* translators: %s: JSON path of the offending value. */
						__( 'Null byte found at %s - the file is corrupt or has been tampered with.', 'perflocale' ),
						$path
					),
				];
			}

			// An empty /u pattern fails on invalid UTF-8: the fastest check without mbstring.
			if ( $value !== '' && preg_match( '//u', $value ) === false ) {
				return [
					sprintf(
						/* translators: %s: JSON path of the offending value. */
						__( 'Invalid UTF-8 at %s - importing it would silently truncate the value in MySQL.', 'perflocale' ),
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
				$found = array_merge( $found, self::scan_value( $v, $path . '.' . (string) $k, $budget - count( $found ), $max_value ) );

				if ( count( $found ) >= $budget ) {
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Restore the role snapshot: the Translator role with its imported caps, and the file's `perflocale_` grants to the
	 * roles an export carries (DataExporter::grant_roles(); other slugs ignored). perflocale_caps_version is set to the
	 * file's, at most TranslatorRole::CAPS_VERSION, so install_caps() neither rewrites nor skips. Foreign caps dropped.
	 *
	 * @param array<string,mixed> $roles Snapshot from DataExporter::snapshot_roles().
	 * @return array<int, string> Translator capabilities in the file that were not restored (outside the allowlist).
	 */
	private function restore_roles( array $roles ): array {
		$dropped = [];

		// Translator role from the imported caps, allow-listed to `perflocale_*` and the editing caps it needs.
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
				} elseif ( $grant ) {
					$dropped[] = sanitize_key( $cap_str );
				}
			}

			if ( ! empty( $safe_caps ) ) {
				remove_role( TranslatorRole::ROLE_SLUG );
				add_role( TranslatorRole::ROLE_SLUG, __( 'Translator', 'perflocale' ), $safe_caps );
			}
		}

		// PerfLocale grants of the roles the export carries.
		if ( isset( $roles['role_grants'] ) && is_array( $roles['role_grants'] ) ) {
			$grant_roles = DataExporter::grant_roles();

			foreach ( $roles['role_grants'] as $role_slug => $grants ) {
				$role_slug = sanitize_key( (string) $role_slug );

				if ( ! is_array( $grants ) || ! in_array( $role_slug, $grant_roles, true ) ) {
					continue;
				}

				$role = get_role( $role_slug );

				if ( ! $role instanceof \WP_Role ) {
					continue;
				}

				foreach ( $grants as $cap => $grant ) {
					$cap_str = (string) $cap;
					// Foreign caps dropped: a tampered export could grant arbitrary caps to admins and editors.
					if ( ! str_starts_with( $cap_str, 'perflocale_' ) ) {
						continue;
					}
					$role->add_cap( $cap_str, (bool) $grant );
				}
			}
		}

		if ( isset( $roles['caps_version'] ) ) {
			update_option( 'perflocale_caps_version', min( (int) $roles['caps_version'], TranslatorRole::CAPS_VERSION ), false );
		}

		return array_values( array_filter( $dropped ) );
	}

	/**
	 * Each column's wpdb placeholder from its SQL type (memoised): integers %d, decimals %f, others %s, so all-digit text
	 * round-trips verbatim.
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

		// DESCRIBE, not SHOW COLUMNS: the SQLite 2.2.x legacy translator answers SHOW COLUMNS of a quoted name with no rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full_table is Schema::table(); schema introspection only.
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				'DESCRIBE %i',
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
	 * Write the file's add-on settings over the committed row under the add-on settings lock, as AddonSettings writes.
	 *
	 * @param array<string, mixed> $incoming The file's entries, credentials redacted.
	 * @return string '' when stored or unchanged, else 'busy', 'database' or 'unchanged' (a filter kept the stored value).
	 */
	private static function write_addon_settings( array $incoming ): string {
		$cause = \PerfLocale\Concurrency\Lock::with(
			\PerfLocale\Addon\AddonSettings::LOCK_NAME,
			\PerfLocale\Addon\AddonSettings::LOCK_TTL,
			static function () use ( $incoming ): string {
				// The committed row, not a cached copy or a stale `notoptions` entry.
				wp_cache_delete( 'perflocale_addon_settings', 'options' );
				\PerfLocale\Database\NotOptions::unlist( [ 'perflocale_addon_settings' ] );

				$current = (array) get_option( 'perflocale_addon_settings', [] );
				$merged  = self::merge_addon_settings( $current, $incoming );

				// update_option() is false for an unchanged value too; only a changed one that did not commit is refused.
				if ( update_option( 'perflocale_addon_settings', $merged, false ) || maybe_serialize( $merged ) === maybe_serialize( $current ) ) {
					return '';
				}

				global $wpdb;

				$error = $wpdb instanceof \wpdb ? (string) $wpdb->last_error : '';
				self::log_db_error( 'saving the add-on settings', $error );

				return '' !== $error ? 'database' : 'unchanged';
			}
		);

		return is_string( $cause ) ? $cause : 'busy';
	}

	/**
	 * The file's add-on settings over the stored ones: the file decides which add-ons exist and their values, except
	 * the redacted credential keys, which keep this site's values (a verbatim write would blank them).
	 *
	 * @param array<array-key, mixed> $current  Stored entries.
	 * @param array<string, mixed>    $incoming The file's entries.
	 * @return array<array-key, mixed>
	 */
	private static function merge_addon_settings( array $current, array $incoming ): array {
		$merged = $current;

		foreach ( $incoming as $addon => $entry ) {
			if ( ! is_array( $entry ) ) {
				$merged[ $addon ] = $entry;
				continue;
			}

			$existing_entry = ( isset( $current[ $addon ] ) && is_array( $current[ $addon ] ) ) ? $current[ $addon ] : [];
			$password_keys  = $existing_entry !== [] ? DataExporter::password_setting_keys( (string) $addon ) : [];
			$base           = $entry;

			foreach ( $existing_entry as $key => $value ) {
				if ( ! array_key_exists( $key, $entry ) && ( preg_match( DataExporter::CREDENTIAL_KEY_PATTERN, (string) $key ) || in_array( (string) $key, $password_keys, true ) ) ) {
					$base[ $key ] = $value;
				}
			}

			$merged[ $addon ] = $base;
		}

		return array_intersect_key( $merged, $incoming );
	}

	/**
	 * The import note for settings, add-on settings or the add-on list the site did not store, naming the cause.
	 *
	 * @param string $part  'settings', 'addon_settings' or 'disabled_addons'.
	 * @param string $cause 'busy', 'too_large', 'database', 'changed', 'unchanged', or '' when unknown.
	 * @return string
	 */
	private static function refusal_note( string $part, string $cause ): string {
		$settings = 'addon_settings' === $part;
		$global   = 'settings' === $part;
		$option   = $global ? 'perflocale_settings' : ( $settings ? 'perflocale_addon_settings' : 'perflocale_disabled_addons' );
		$what     = $global ? __( 'The settings from the file were not saved', 'perflocale' ) : ( $settings ? __( 'The add-on settings from the file were not saved', 'perflocale' ) : __( 'The add-on list from the file was not saved', 'perflocale' ) );

		switch ( $cause ) {
			case 'busy':
				$why = $global
					? __( 'another change to the settings was being saved at the same time. Import the file again to apply them.', 'perflocale' )
					: ( $settings
						? __( 'another add-on settings save was running at the same moment. Import the file again to apply them.', 'perflocale' )
						: __( 'another change to the add-on list was being saved at the same moment. Import the file again to apply it.', 'perflocale' ) );
				break;
			case 'changed':
				$why = __( 'another plugin or request kept changing the stored settings during the import. Import the file again.', 'perflocale' );
				break;
			case 'too_large':
				/* translators: %s: a size, for example "4 KB" */
				$why = sprintf( __( 'the list is larger than the %s the site stores. Remove add-ons from the file\'s list, then import it again.', 'perflocale' ), size_format( \PerfLocale\Addon\AddonDisabledList::DISABLED_OPTION_MAX_BYTES ) );
				break;
			case 'database':
				/* translators: %s: option name */
				$why = sprintf( __( 'the database refused to store the option %s, so a value may hold characters it cannot store. Check them in the file, then import it again.', 'perflocale' ), $option )
					. ' ' . ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? __( 'The database error is in the debug log.', 'perflocale' ) : __( 'To log the database error, turn on WP_DEBUG_LOG and import again.', 'perflocale' ) );
				break;
			case 'unchanged':
				/* translators: %s: option name */
				$why = sprintf( __( 'a filter on the option %s kept the stored values. Check the plugins that filter it, then import the file again.', 'perflocale' ), $option );
				break;
			default:
				$why = __( 'the stored values are unchanged. Import the file again.', 'perflocale' );
		}

		return $what . ': ' . $why;
	}

	/**
	 * Log a database error when WP_DEBUG_LOG is on: what was running and a fixed code, never the database's text, which
	 * can quote imported values.
	 *
	 * @param string $where What was running (operation, table, export ID), for the log line.
	 * @param string $error The database error.
	 * @return void
	 */
	private static function log_db_error( string $where, string $error ): void {
		if ( $error === '' || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic, only when WP_DEBUG_LOG is on.
		error_log( 'PerfLocale DataImporter: database error while ' . $where . ' - code ' . \PerfLocale\Util\DbErrorCode::of( $error ) );
	}

	/**
	 * The unique keys a table holds, read 5,000 rows a page, as row_keys() entries; for the text-keyed tables KEY_LOOKUP
	 * does not list (small by nature).
	 *
	 * @param string $table_name Short table name.
	 * @param string $full_table Full table name.
	 * @return array<string, true>
	 */
	private function taken_keys( string $table_name, string $full_table ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$keys    = DataRegistry::unique_keys( $table_name );
		$columns = $keys === [] ? [] : array_values( array_unique( array_merge( ...$keys ) ) );
		$taken   = [];

		if ( $columns === [] ) {
			return $taken;
		}

		// One %i per column of the class constant.
		$select = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Columns and table bound with %i; the replacements arrive as one list.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM %i LIMIT %d OFFSET %d", array_merge( $columns, [ $full_table, 5000, $offset ] ) ), ARRAY_A );
			$page = count( $rows );

			foreach ( $rows as $row ) {
				$taken += self::row_keys( $table_name, (array) $row );
			}

			$offset += 5000;
		} while ( 5000 === $page );

		return $taken;
	}

	/**
	 * The unique keys this piece's rows would take that the table holds: one query per key on KEY_LOOKUP's column with
	 * the piece's values (earlier rows of this import are found too, on the same connection).
	 *
	 * @param string                           $table_name  Short table name.
	 * @param string                           $full_table  Full table name.
	 * @param array<int, array<string, mixed>> $rows        The piece's rows, as they will be written.
	 * @param array<string, string>            $col_formats Column => placeholder.
	 * @return array<string, true>
	 * @throws \RuntimeException When a look-up fails (inside a replace import's transaction, which must then stop).
	 */
	private function taken_in_piece( string $table_name, string $full_table, array $rows, array $col_formats ): array {
		global $wpdb;

		$taken = [];

		if ( ! $wpdb instanceof \wpdb ) {
			return $taken;
		}

		foreach ( self::KEY_LOOKUP[ $table_name ] ?? [] as $key_index => $in_col ) {
			$columns = DataRegistry::unique_keys( $table_name )[ $key_index ] ?? [];
			$values  = [];

			foreach ( $rows as $row ) {
				$value = $row[ $in_col ] ?? null;

				if ( is_scalar( $value ) ) {
					$values[ (string) $value ] = true;
				}
			}

			if ( [] === $columns || [] === $values ) {
				continue;
			}

			// Columns, table and key column as %i; values %d for an integer key column, else %s.
			$select  = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
			$numeric = '%d' === ( $col_formats[ $in_col ] ?? '%s' );

			foreach ( array_chunk( array_keys( $values ), 500 ) as $part ) {
				$placeholders = $numeric ? implode( ', ', array_fill( 0, count( $part ), '%d' ) ) : implode( ', ', array_fill( 0, count( $part ), '%s' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders only: columns, table and key column bound with %i, values one placeholder each.
				$found = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM %i WHERE %i IN ({$placeholders})", ...$columns, ...[ $full_table, $in_col ], ...$part ), ARRAY_A );

				if ( '' !== (string) $wpdb->last_error ) {
					self::log_db_error( 'reading the keys of ' . $table_name, (string) $wpdb->last_error );

					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: table name */
							__( 'Replace import aborted: the existing rows of table "%s" could not be read; the whole import was rolled back, nothing was changed.', 'perflocale' ),
							$table_name
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}

				foreach ( (array) $found as $row ) {
					$taken += self::row_keys( $table_name, (array) $row );
				}
			}
		}

		return $taken;
	}

	/**
	 * A row's unique keys as "key index|normalised values", compared as the indexes compare ASCII.
	 *
	 * A key MySQL folds further is missed (its insert fails, the import rolls back); NULL columns leave the key out.
	 * Docs: docs-ai/11-code-rationale.md, "DataImporter: rows as they are written".
	 *
	 * @param string              $table_name Short table name.
	 * @param array<mixed, mixed> $row        Row (column => value).
	 * @return array<string, true>
	 */
	private static function row_keys( string $table_name, array $row ): array {
		$out = [];

		foreach ( DataRegistry::unique_keys( $table_name ) as $i => $columns ) {
			$parts = [];

			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? null;

				if ( ! is_scalar( $value ) ) {
					continue 2;
				}

				$value   = rtrim( (string) $value, ' ' );
				$value   = 'slug' === $column ? mb_substr( $value, 0, 191 ) : $value;
				$parts[] = strtolower( $value );
			}

			$out[ $i . '|' . implode( "\x1f", $parts ) ] = true;
		}

		return $out;
	}

	/**
	 * Import one table's rows, a piece at a time.
	 *
	 * @param string   $table_name       Short table name.
	 * @param iterable $rows             The table's rows, decoded one at a time.
	 * @param bool     $replace          Replace mode (keep ids, skip rows whose keys are taken, stop at the first failure).
	 * @param bool     $sanitize_strings See {@see self::import()}.
	 * @param string   $exclude_type     Leave out the rows whose `type` is this (the slice a `!type` wipe kept).
	 * @phpstan-param iterable<array-key, mixed> $rows
	 * @return array{imported: int, skipped: int, failed: int, dropped: int, errors: array<int, string>, sanitized: int}
	 *
	 * @throws \RuntimeException In replace mode, when a row cannot be saved (the caller rolls the import back).
	 *         `failed` counts the skips that were not duplicate-key collisions, for import()'s abort gate.
	 */
	private function import_table( string $table_name, iterable $rows, bool $replace, bool $sanitize_strings = false, string $exclude_type = '' ): array {
		global $wpdb;

		$full_table = Schema::table( $table_name );
		$result     = [
			'imported'  => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'dropped'   => 0,
			'errors'    => [],
			'sanitized' => 0,
		];

		// Rows left out on purpose (by reason) are not failed inserts: the zero-insert gate discounts them.
		$reasons_before = array_sum( $this->skip_reasons );

		// Merge expects duplicate-key collisions (intended skips); suppressed, or wpdb echoes raw SQL errors into the
		// redirect page. The wipe is the caller's.
		$was_suppressing = $wpdb->suppress_errors( true );

		// wpdb keeps every failed statement in $EZSQL_ERROR, suppressed ones too (about 1 KB per skipped duplicate):
		// dropped after each piece (forget_duplicate_errors()).
		$errors_before = self::db_error_count();

		try {
			// Natural-key map of a parent table, so a duplicate-skipped parent still maps to the existing id and child FKs stay
			// valid.
			$natural_key_lookup = $this->build_natural_key_lookup( $table_name );

			// One schema read per table, for formats and for dropping unknown columns.
			$col_formats = $this->column_formats( $full_table );

			// In Replace no statement may fail (SQLite ends the transaction), so a row whose key is taken is skipped before the
			// write; any failure left is real and aborts. Text-keyed tables read whole once, others per piece.
			$whole_keys = ( $replace && ! isset( self::KEY_LOOKUP[ $table_name ] ) ) ? $this->taken_keys( $table_name, $full_table ) : null;
			$size       = self::batch_size( $table_name );
			$piece      = [];

			foreach ( $rows as $row ) {
				if ( '' !== $exclude_type && is_array( $row ) && $exclude_type === ( $row['type'] ?? null ) ) {
					++$result['skipped'];
					$this->note_skip( 'string_slice_kept' );
					continue;
				}

				$piece[] = $row;

				if ( count( $piece ) >= $size ) {
					$this->import_piece( $table_name, $full_table, $piece, $replace, $sanitize_strings, $natural_key_lookup, $col_formats, $whole_keys, $result );
					$piece = [];
					self::forget_duplicate_errors( $errors_before );

					// Not inside Replace's transaction.
					if ( ! $replace ) {
						ImportLock::heartbeat();
					}
				}
			}

			if ( [] !== $piece ) {
				$this->import_piece( $table_name, $full_table, $piece, $replace, $sanitize_strings, $natural_key_lookup, $col_formats, $whole_keys, $result );
			}
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
			self::forget_duplicate_errors( $errors_before );
		}

		$result['dropped'] = array_sum( $this->skip_reasons ) - $reasons_before;

		return $result;
	}

	/**
	 * Import one piece of a table's rows.
	 *
	 * @param string                                                                                                    $table_name         Short table name.
	 * @param string                                                                                                    $full_table         Full table name.
	 * @param array<int, mixed>                                                                                         $piece              Decoded rows.
	 * @param bool                                                                                                      $replace            Replace mode.
	 * @param bool                                                                                                      $sanitize_strings   See {@see self::import()}.
	 * @param callable|null                                                                                             $natural_key_lookup See {@see self::build_natural_key_lookup()}.
	 * @param array<string, string>                                                                                     $col_formats        Column => placeholder.
	 * @param array<string, true>|null                                                                                  $whole_keys         Replace mode, text-keyed tables: every key the table holds (grows as rows are written).
	 * @param array{imported: int, skipped: int, failed: int, dropped: int, errors: array<int, string>, sanitized: int} $result             Table result (updated).
	 * @return void
	 * @throws \RuntimeException In replace mode, when a row cannot be saved.
	 */
	private function import_piece( string $table_name, string $full_table, array $piece, bool $replace, bool $sanitize_strings, ?callable $natural_key_lookup, array $col_formats, ?array &$whole_keys, array &$result ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prepared       = [];
		$fk_refs        = DataRegistry::fk_refs( $table_name );
		$names_language = in_array( $table_name, DataRegistry::language_ref_tables(), true );
		$fp_column      = DataExporter::fingerprint_columns()[ $table_name ] ?? null;
		$work           = $this->work_table();

		// Parent ids this piece's FK columns name, one read per parent table (Merge), and the groups a Replace moved.
		$fk_maps = [];

		// Merge: file ids of parent rows not saved; a child naming one is left out (it would land on another row here).
		$not_saved = [];

		if ( ! $replace && [] !== $fk_refs ) {
			foreach ( $fk_refs as $col => $parent_table ) {
				$fk_maps[ $parent_table ]   = ( $fk_maps[ $parent_table ] ?? [] ) + $work->get( $work->id_map( $parent_table ), self::column_ints( $piece, $col ) );
				$not_saved[ $parent_table ] = ( $not_saved[ $parent_table ] ?? [] ) + $work->get( $work->not_saved( $parent_table ), self::column_ints( $piece, $col ) );
			}
		}

		$records_parents = ! $replace && $this->is_parent_table( $table_name );
		$parent_rows     = [];

		$moved_groups = $replace && 'translation_links' === $table_name && $work->has( ImportWorkTable::MOVED_GROUP )
			? $work->get( ImportWorkTable::MOVED_GROUP, self::column_ints( $piece, 'group_id' ) )
			: [];

		// This piece's rows for the work table, written once at its end.
		$linked_posts = [];
		$piece_map    = [];
		$piece_moved  = [];
		$piece_twins  = [];

		foreach ( $piece as $row ) {
			if ( ! is_array( $row ) ) {
				++$result['skipped'];
				continue;
			}

			$old_id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$file_row = $row;

			if ( $records_parents && $old_id > 0 ) {
				$parent_rows[ $old_id ] = 0;
			}

			// Template menu and pattern references follow the links this restore wrote, after the import.
			if ( 'translation_links' === $table_name && 'post' === ( $row['type'] ?? '' ) && isset( $row['object_id'] ) && is_numeric( $row['object_id'] ) && (int) $row['object_id'] > 0 ) {
				$linked_posts[ (int) $row['object_id'] ] = 0;
			}

			// Merge: FK columns from the old parent id to the new one; an unmapped parent keeps the file's value (a section can
			// be exported without languages). Replace never rewrites; the identity gate covers foreign files.
			if ( ! $replace && [] !== $fk_refs ) {
				foreach ( $fk_refs as $col => $parent_table ) {
					if ( isset( $row[ $col ], $fk_maps[ $parent_table ][ (int) $row[ $col ] ] ) ) {
						$row[ $col ] = $fk_maps[ $parent_table ][ (int) $row[ $col ] ];
					} elseif ( isset( $row[ $col ], $not_saved[ $parent_table ][ self::fk_int( $row[ $col ] ) ] ) ) {
						++$result['skipped'];
						$this->note_skip( 'parent_not_saved' );
						continue 2;
					}
				}
			}

			// A link of a group written under a new id (a kept string group holds the file's id) goes into that group.
			$file_group = [] !== $moved_groups ? Typed::int( $row['group_id'] ?? null ) : 0;

			if ( $file_group > 0 && isset( $moved_groups[ $file_group ] ) ) {
				$row['group_id'] = $moved_groups[ $file_group ];
			}

			// A row naming a language this site lacks would be in no language: left out and counted.
			if ( $names_language && ! isset( $this->language_ids()[ is_numeric( $row['language_id'] ?? null ) ? (int) $row['language_id'] : 0 ] ) ) {
				++$result['skipped'];
				$this->note_skip( 'unknown_language' );
				continue;
			}

			// Merge: drop the id for a new auto-increment one; Replace keeps it.
			if ( ! $replace ) {
				unset( $row['id'] );
			}

			// Drop columns the target lacks: a newer minor version can add one, and MySQL would refuse every row (1054),
			// which in Replace would commit an emptied table.
			$row = array_intersect_key( $row, $col_formats );

			if ( $row === [] ) {
				++$result['skipped'];
				continue;
			}

			// is_default stored as 0 or 1, as the importer's checks read it.
			if ( 'languages' === $table_name && array_key_exists( 'is_default', $row ) ) {
				$row['is_default'] = empty( $row['is_default'] ) ? 0 : 1;
			}

			// Read before sanitizing: the refusal below is for a code sanitizing emptied.
			$had_code = $sanitize_strings && 'languages' === $table_name && is_string( $row['slug'] ?? null ) && '' !== $row['slug'];

			// After the FK rewrite and column filter, before the duplicate lookup. Stored even when empty (an empty value reads
			// as untranslated; skipping would leave a link without a value), except a language code (below).
			// An imported automation change never carries a replaced value or a result to undo against.
			if ( 'pending_changes' === $table_name ) {
				self::prepare_pending_change_row( $row );
			}

			// A tuple every write path refuses (it would share another string's identity) is left out.
			if ( 'strings' === $table_name && \PerfLocale\Database\Repository\StringRepository::refused( Typed::str( $row['domain'] ?? '' ), Typed::str( $row['context'] ?? '' ), Typed::str( $row['original'] ?? '' ) ) ) {
				++$result['skipped'];
				$this->note_skip( 'string_separator' );

				// Replace: its translations, links, pending rows and group are removed after the tables (Merge leaves them out).
				if ( $replace && $old_id > 0 ) {
					$piece_twins[ ImportWorkTable::REFUSED ][ $old_id ] = Typed::int( $row['group_id'] ?? null );
				}

				continue;
			}

			// The identity hash comes from the row, never from the file (an older export carries the old format).
			if ( 'strings' === $table_name && is_string( $row['domain'] ?? null ) && is_string( $row['context'] ?? null ) && is_string( $row['original'] ?? null ) ) {
				$row['original_hash'] = \PerfLocale\Database\Repository\StringRepository::compute_hash( $row['domain'], $row['context'], $row['original'] );
			}

			$row_sanitized = $sanitize_strings && match ( $table_name ) {
				'string_translations' => self::sanitize_string_translation_row( $row ),
				'languages'           => self::sanitize_language_row( $row ),
				'slug_translations'   => self::sanitize_slug_translation_row( $row ),
				'pending_changes'     => self::sanitize_pending_change_row( $row ),
				default               => false,
			};

			// A code sanitizing emptied is refused and counted as a failure (the routing key; '' reaches no URL). A code the
			// file leaves empty is stored, as on the trusted path; non-string codes fare as there.
			if ( $had_code && '' === $row['slug'] ) {
				++$result['skipped'];
				++$result['failed'];

				if ( count( $result['errors'] ) < 20 ) {
					$result['errors'][] = sprintf(
						/* translators: %d: the language's ID in the export file. */
						__( 'The language with export ID %d was not imported: nothing was left of its code after removing the characters a language code cannot contain.', 'perflocale' ),
						$old_id
					);
				}

				if ( ! $replace && $old_id > 0 && $natural_key_lookup !== null ) {
					$existing_id = $natural_key_lookup( $row );

					if ( is_int( $existing_id ) && $existing_id > 0 ) {
						$piece_map[ $old_id ] = $existing_id;
					}
				}

				continue;
			}

			// An import that did not clear languages never changes the default: the file's default becomes an ordinary
			// language (LanguageRepository serves the last default row it reads).
			$demoted = false;

			if ( 'languages' === $table_name && ! $this->languages_cleared && ! empty( $row['is_default'] ) ) {
				if ( null === $this->site_default ) {
					$this->site_default = $this->read_site_default();
				}

				if ( '' !== $this->site_default ) {
					$row['is_default'] = 0;
					$demoted           = true;
				}
			}

			$prepared[] = [
				'old_id'    => $old_id,
				'row'       => $row,
				'sanitized' => $row_sanitized,
				'demoted'   => $demoted,
				'file'      => $file_row,
			];
		}

		if ( null !== $fp_column ) {
			$prepared = $this->check_fingerprints( $prepared, $fp_column, $result );
		}

		if ( 'translation_links' === $table_name && ! $replace ) {
			$prepared = $this->check_merge_links( $prepared, $result );
		}

		// Term URL slug rows: restored once, after the table's other rows, as one namespace state (section 4).
		if ( 'slug_translations' === $table_name ) {
			foreach ( $prepared as $i => $entry ) {
				// Routed on the stored form whatever the trust: MySQL readers match the type in any case.
				$type = is_string( $entry['row']['object_type'] ?? null ) ? sanitize_key( $entry['row']['object_type'] ) : null;

				if ( in_array( $type, SlugTranslationWrites::TERM_TYPES, true ) ) {
					$entry['row']['object_type'] = $type;
					$this->term_slug_rows[]      = [ $entry['row'], $entry['sanitized'] ];
					unset( $prepared[ $i ] );
				}
			}

			$prepared = array_values( $prepared );
		}

		// Replace: the keys this piece would take that the table holds (import_table()).
		$piece_keys = ( $replace && null === $whole_keys ) ? $this->taken_in_piece( $table_name, $full_table, array_column( $prepared, 'row' ), $col_formats ) : [];

		foreach ( $prepared as $entry ) {
			$old_id        = $entry['old_id'];
			$row           = $entry['row'];
			$row_sanitized = $entry['sanitized'];
			$demoted       = $entry['demoted'];

			$moved  = false;
			$new_id = false;
			$keys   = [];

			if ( $replace ) {
				$keys = self::row_keys( $table_name, $row );

				// The row's few keys first: array_intersect_key() walks its first argument.
				if ( array_intersect_key( $keys, null !== $whole_keys ? $whole_keys : $piece_keys ) !== [] ) {
					// translation_groups' one key is its id: with only post and term groups cleared, a taken id is a kept string group's
					// (or one this import moved there), so the file's group gets a new id and its links follow.
					if ( 'translation_groups' === $table_name && $this->groups_keep_strings && $old_id > 0 && 'string' !== ( $row['type'] ?? null ) ) {
						unset( $row['id'] );
						$moved  = true;
						$new_id = true;
					} elseif (
						// Likewise a post or term link whose id alone is taken gets a new id; its group and object keys are still checked.
						'translation_links' === $table_name && $this->links_keep_strings && $old_id > 0 && 'string' !== ( $row['type'] ?? null )
						&& array_intersect_key( self::row_keys( $table_name, array_diff_key( $row, [ 'id' => true ] ) ), null !== $whole_keys ? $whole_keys : $piece_keys ) === []
					) {
						unset( $row['id'] );
						$new_id = true;
					} else {
						// A held pair's twin (its hash taken by a row of this file): its rows join that row after the tables.
						$kept = 'strings' === $table_name && $old_id > 0 ? ImportStringTwins::holder( $full_table, Typed::str( $row['original_hash'] ?? '' ) ) : 0;

						if ( $kept > 0 && $kept !== $old_id ) {
							$piece_twins[ ImportWorkTable::TWIN ][ $old_id ]       = $kept;
							$piece_twins[ ImportWorkTable::TWIN_GROUP ][ $old_id ] = Typed::int( $row['group_id'] ?? null );

							if ( ( $entry['file']['original_hash'] ?? null ) === $row['original_hash'] ) {
								$piece_twins[ ImportWorkTable::TWIN_SERVED ][ $old_id ] = $kept;
							}
						}

						++$result['skipped'];
						continue;
					}
				}
			}

			// Formats from the column type, not the value: "00100" in a string column must not become 100.
			$format = [];

			foreach ( $row as $col => $value ) {
				$format[] = $col_formats[ $col ] ?? '%s';
			}

			// Merge writes outside a transaction: after a reconnect the lock is taken again before this row, or the import
			// stops (ImportLock::heartbeat()).
			if ( ! $replace ) {
				ImportLock::heartbeat();
			}

			// Replace writes through the guard: 0 rows without an error means a new connection, outside the transaction.
			$guard = $this->guard;

			if ( $replace && null !== $guard ) {
				$this->stop_unless_connection_kept();

				$inserted = $guard->insert( $full_table, $row, $format );

				if ( 0 === $inserted ) {
					throw new \RuntimeException( self::connection_lost_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
				}
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$inserted = $wpdb->insert( $full_table, $row, $format );
			}

			if ( $new_id && $inserted !== false && $wpdb instanceof \wpdb ) {
				if ( $moved ) {
					$piece_moved[ $old_id ] = (int) $wpdb->insert_id;
				}

				$keys = self::row_keys( $table_name, [ 'id' => $wpdb->insert_id ] + $row );
			}

			if ( $replace && $inserted !== false ) {
				if ( null !== $whole_keys ) {
					$whole_keys += $keys;
				} else {
					$piece_keys += $keys;
				}
			}

			if ( $replace && $inserted === false ) {
				// Inside the transaction: stop, so the next statement is the caller's ROLLBACK.
				if ( $wpdb instanceof \wpdb ) {
					self::log_db_error( 'inserting into ' . $table_name . ( $old_id > 0 ? ' (export ID ' . $old_id . ')' : '' ), (string) $wpdb->last_error );
				}

				$message = $old_id > 0
					? sprintf(
						/* translators: 1: table name, 2: the row's ID in the export file */
						__( 'Replace import aborted: the row of table "%1$s" with export ID %2$d could not be saved because of a database error; the whole import was rolled back, nothing was changed.', 'perflocale' ),
						$table_name,
						(int) $old_id
					)
					: sprintf(
						/* translators: %s: table name */
						__( 'Replace import aborted: a row of table "%s" could not be saved because of a database error; the whole import was rolled back, nothing was changed.', 'perflocale' ),
						$table_name
					);

				throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
			}

			if ( $inserted !== false ) {
				++$result['imported'];

				// Marked at once: a Merge stopping after this row still flushes the rewrite rules.
				if ( 'languages' === $table_name ) {
					$this->languages_changed = true;

					if ( ! empty( $row['is_active'] ) && is_string( $row['slug'] ?? null ) ) {
						$this->added_codes[ $row['slug'] ] = [ is_string( $row['locale'] ?? null ) ? $row['locale'] : '', is_string( $row['name'] ?? null ) && '' !== $row['name'] ? $row['name'] : $row['slug'] ];
					}
				}

				if ( $row_sanitized ) {
					++$result['sanitized'];
				}

				if ( $demoted ) {
					$this->default_kept = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : '';
				} elseif ( 'languages' === $table_name && ! $this->languages_cleared && ! empty( $row['is_default'] ) ) {
					// A site without a default takes the file's; further default rows of the file are demoted.
					$this->site_default = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : '';
				}

				// Parent old => new id, for the child tables.
				if ( ! $replace && $old_id > 0 && $this->is_parent_table( $table_name ) ) {
					$piece_map[ $old_id ] = (int) $wpdb->insert_id;
				}
			} else {
				++$result['skipped'];

				// A duplicate key is an expected Merge skip; anything else (unknown column, charset, strict mode) is a real failure.
				$last_error = (string) $wpdb->last_error;

				if ( $last_error !== '' && ! Schema::is_duplicate_key_error( $last_error ) ) {
					++$result['failed'];

					if ( count( $result['errors'] ) < 20 ) {
						self::log_db_error( 'inserting into ' . $table_name . ( $old_id > 0 ? ' (export ID ' . $old_id . ')' : '' ), $last_error );

						$result['errors'][] = $old_id > 0
							? sprintf(
								/* translators: %d: the row's ID in the export file. */
								__( 'The row with export ID %d could not be saved because of a database error.', 'perflocale' ),
								$old_id
							)
							: __( 'A row could not be saved because of a database error.', 'perflocale' );
					}
				}

				// A duplicate-skipped parent still needs its existing id (by natural key) for the children; Merge only.
				if ( $old_id > 0 && $natural_key_lookup !== null ) {
					$existing_id = Typed::int( $natural_key_lookup( $row ) );
					if ( $existing_id > 0 ) {
						$piece_map[ $old_id ] = $existing_id;
					}
				}
			}
		}

		$work->put( $work->id_map( $table_name ), $piece_map );
		$work->put( $work->not_saved( $table_name ), array_diff_key( $parent_rows, $piece_map ) );
		$work->put( ImportWorkTable::MOVED_GROUP, $piece_moved );
		$work->put( ImportWorkTable::LINKED_POST, $linked_posts );

		foreach ( $piece_twins as $kind => $map ) {
			$work->put( $kind, $map );
		}

		// Free memory per piece with wp_cache_flush_runtime(), not wp_cache_flush(), which would empty a persistent backend
		// for the whole site each piece (core 6.1+).
		\PerfLocale\Cache\CacheManager::flush_runtime();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Restore the held-back term URL slug rows: inside the Replace transaction through its guard, or (Merge) one
	 * transaction per namespace under the import lock. Counts go into the table result before its abort gates.
	 *
	 * @param bool                                                                                                      $replace Replace mode.
	 * @param array{imported: int, skipped: int, failed: int, dropped: int, errors: array<int, string>, sanitized: int} $result  Table result (updated).
	 * @return void
	 * @throws \RuntimeException When a row cannot be written (Replace: the caller rolls back).
	 */
	private function restore_term_slugs( bool $replace, array &$result ): void {
		$rows                 = $this->term_slug_rows;
		$this->term_slug_rows = [];

		if ( [] === $rows ) {
			return;
		}

		if ( $replace ) {
			$this->stop_unless_connection_kept();
		}

		try {
			$done = SlugTranslationWrites::restore_term_slugs( array_column( $rows, 0 ), $replace ? Txn::CALLER : Txn::OWN, $replace ? $this->guard : null );
		} catch ( \RuntimeException $e ) {
			// The import lock lost or the Merge's connection gone (ImportLock::heartbeat()): its own message.
			if ( in_array( $e->getMessage(), [ esc_html( ImportLock::busy_message() ), esc_html( self::merge_lost_message() ) ], true ) ) {
				throw $e;
			}

			if ( $replace && null !== $this->guard && ! $this->guard->holds() ) {
				throw new \RuntimeException( self::connection_lost_message(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
			}

			self::log_db_error( 'restoring term URL slugs', $e->getMessage() );

			throw new \RuntimeException(
				$replace
					? esc_html__( 'Replace import aborted: the URL slugs of terms could not be saved because of a database error; the whole import was rolled back, nothing was changed.', 'perflocale' )
					: esc_html__( 'Import stopped: the URL slugs of terms could not be saved because of a database error. Rows imported before them are kept; import the file again.', 'perflocale' ),
				0,
				$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception, never printed.
			);
		}

		$result['imported'] += $done['imported'];
		$result['skipped']  += $done['skipped'] + $done['dropped'];
		$result['dropped']  += $done['dropped'];

		if ( $done['dropped'] > 0 ) {
			$this->note_skip( 'url_slug_taken', $done['dropped'] );
		}

		foreach ( $rows as [ $row, $sanitized ] ) {
			if ( $sanitized && in_array( Typed::int( $row['language_id'] ?? 0 ) . '|' . Typed::int( $row['object_id'] ?? 0 ), $done['written'], true ) ) {
				++$result['sanitized'];
			}
		}
	}

	/**
	 * Replace: release the URL bases data and map entries of slugs that were language rows under the lock before the wipe
	 * and are none after the file's rows (an explicit list: deleting needs positive proof), inside the transaction.
	 *
	 * @param array<int, string> $before Slugs read under the language locks before the wipe.
	 * @return void
	 * @throws \RuntimeException When the rows cannot be read again or the release fails: the import rolls back.
	 */
	private function release_removed_slugs( array $before ): void {
		$this->stop_unless_connection_kept();

		try {
			$after = \PerfLocale\Router\UrlBases\Inputs::slugs( true );

			if ( null === $after ) {
				throw new \RuntimeException( 'release: the language rows could not be read' );
			}

			$gone = array_values( array_diff( $before, $after ) );

			if ( [] === $gone ) {
				return;
			}

			// Its writes publish caches and actions only after COMMIT.
			if ( ! \PerfLocale\Database\OptionWriter::hold( $this->connection_guard() ) ) {
				throw new \RuntimeException( 'release: option writes are held by another transaction' );
			}

			$this->release_held = true;
			$this->released     = \PerfLocale\Database\Repository\LanguageWrites::release_slugs( $gone, $this->connection_guard() );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( __( 'Replace import aborted: the translated URL bases of the removed languages could not be released; rolling back so no data is lost.', 'perflocale' ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
		}
	}

	/**
	 * The connection guard of the open Replace transaction.
	 *
	 * @return ConnectionGuard
	 * @throws \LogicException Outside the transaction.
	 */
	private function connection_guard(): ConnectionGuard {
		if ( null === $this->guard ) {
			throw new \LogicException( 'DataImporter: no connection guard outside the Replace transaction.' );
		}

		return $this->guard;
	}

	/**
	 * The work table of the running import.
	 *
	 * @return ImportWorkTable
	 * @throws \LogicException Outside an import's table phase.
	 */
	private function work_table(): ImportWorkTable {
		if ( null === $this->work ) {
			throw new \LogicException( 'DataImporter: no work table outside the table phase.' );
		}

		return $this->work;
	}

	/**
	 * Positive integer values of one column of a piece's rows (a superset of the ids its look-ups use).
	 *
	 * @param array<array-key, mixed> $rows   Rows (non-arrays are ignored).
	 * @param string                  $column Column.
	 * @return array<int, int>
	 */
	private static function column_ints( array $rows, string $column ): array {
		$out = [];

		foreach ( $rows as $row ) {
			$id = is_array( $row ) && isset( $row[ $column ] ) ? self::fk_int( $row[ $column ] ) : 0;

			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * A column value as the FK rewrite's (int) cast reads it (a decoded JSON value).
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function fk_int( $value ): int {
		if ( is_array( $value ) ) {
			return [] === $value ? 0 : 1;
		}

		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * The error of a Merge whose connection was lost while its tables were written.
	 *
	 * @return string
	 */
	private static function merge_lost_message(): string {
		return __( 'Import stopped: the connection to the database was lost. The rows imported before that stay; import the file again to add the rest.', 'perflocale' );
	}

	/**
	 * The error of a Replace whose connection was lost inside its transaction.
	 *
	 * @return string
	 */
	private static function connection_lost_message(): string {
		return __( 'Replace import aborted: the connection to the database was lost; the whole import was rolled back, nothing was changed. Run the import again.', 'perflocale' );
	}

	/**
	 * Replace: stop when wpdb has left the transaction's connection (no query), before a read there can drive a
	 * skip or a write land outside the transaction.
	 *
	 * @return void
	 * @throws \RuntimeException When the connection was lost.
	 */
	private function stop_unless_connection_kept(): void {
		if ( null !== $this->guard && ! $this->guard->holds() ) {
			throw new \RuntimeException( self::connection_lost_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
		}
	}

	/**
	 * Replace: stop unless the transaction's marker is still set on the connection (one read).
	 *
	 * @return void
	 * @throws \RuntimeException When the connection was lost.
	 */
	private function stop_unless_connection_verified(): void {
		if ( null !== $this->guard && ! $this->guard->verify() ) {
			throw new \RuntimeException( self::connection_lost_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
		}
	}

	/**
	 * Sanitize one imported `string_translations` row in place: `translation` through
	 * Helper::sanitize_untrusted_string_translation(), each string of the decoded `extra_forms` likewise (positions and
	 * empty forms kept). Undecodable extra_forms with a `<` becomes NULL; unchanged values keep their bytes.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return bool True when sanitizing changed the row.
	 */
	private static function sanitize_string_translation_row( array &$row ): bool {
		$changed = false;

		if ( isset( $row['translation'] ) && is_string( $row['translation'] ) ) {
			$clean = \PerfLocale\Helper::sanitize_untrusted_string_translation( $row['translation'] );

			if ( $clean !== $row['translation'] ) {
				$row['translation'] = $clean;
				$changed            = true;
			}
		}

		if ( isset( $row['extra_forms'] ) && is_string( $row['extra_forms'] ) && $row['extra_forms'] !== '' ) {
			$forms = json_decode( $row['extra_forms'], true );

			if ( is_array( $forms ) ) {
				$clean = map_deep(
					$forms,
					static fn( $form ) => is_string( $form ) ? \PerfLocale\Helper::sanitize_untrusted_string_translation( $form ) : $form
				);

				if ( $clean !== $forms ) {
					$encoded            = wp_json_encode( $clean );
					$row['extra_forms'] = is_string( $encoded ) ? $encoded : null;
					$changed            = true;
				}
			} elseif ( str_contains( $row['extra_forms'], '<' ) ) {
				$row['extra_forms'] = null;
				$changed            = true;
			}
		}

		return $changed;
	}

	/**
	 * Prepare one imported automation change in place, whatever the sanitising flag.
	 * `previous` is dropped so no file can make an undo write a value of its choosing; a decided change also loses its
	 * proposal and result hash (a restored history is read-only). Pending changes keep the proposal; approve re-checks.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return void
	 */
	private static function prepare_pending_change_row( array &$row ): void {
		if ( array_key_exists( 'previous', $row ) ) {
			$row['previous'] = null;
		}

		if ( 'string' === ( $row['object_type'] ?? '' ) && in_array( $row['result_status'] ?? '', \PerfLocale\Database\Repository\PendingChangeRepository::INTENTS, true ) ) {
			$row['result_status'] = '';
		}

		$status = is_string( $row['status'] ?? null ) ? $row['status'] : '';

		if ( ! in_array( $status, \PerfLocale\Database\Repository\PendingChangeRepository::STATUSES, true ) ) {
			$row['status'] = \PerfLocale\Database\Repository\PendingChangeRepository::STATUS_SUPERSEDED;
			$status        = $row['status'];
		}

		// The column is latin1: a job id is a UUID or ''.
		if ( array_key_exists( 'job_id', $row ) && ( ! is_string( $row['job_id'] ) || 1 !== preg_match( '/^(?:[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})?$/', $row['job_id'] ) ) ) {
			$row['job_id'] = '';
		}

		if ( \PerfLocale\Database\Repository\PendingChangeRepository::STATUS_PENDING !== $status ) {
			$row['payload']     = '';
			$row['result_hash'] = '';

			// Retention prunes by decided_at, so a decided row always has one: its created time, else now.
			$datetime = '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/';

			if ( ! is_string( $row['decided_at'] ?? null ) || 1 !== preg_match( $datetime, $row['decided_at'] ) ) {
				$row['decided_at'] = is_string( $row['created_at'] ?? null ) && 1 === preg_match( $datetime, $row['created_at'] ) ? $row['created_at'] : gmdate( 'Y-m-d H:i:s' );
			}

			return;
		}

		// Pending reads filter on `decided_at IS NULL`: a file's time would hide the row from them.
		if ( array_key_exists( 'decided_at', $row ) ) {
			$row['decided_at'] = null;
		}

		// Base ids do not travel between sites: base 0 makes the next resync bootstrap. An imported parts list is not
		// the job's own, so `parts_complete` goes and the proposal counts as source text for a viewer who cannot read it.
		if ( 'post' === ( $row['object_type'] ?? '' ) && is_string( $row['payload'] ?? null ) && '' !== $row['payload'] ) {
			$payload = json_decode( $row['payload'], true );

			if ( is_array( $payload ) ) {
				unset( $payload['parts_complete'] );

				$payload['base_id'] = 0;
				$encoded            = wp_json_encode( $payload );
				$row['payload']     = is_string( $encoded ) ? $encoded : '';
			}

			$summary = is_string( $row['summary'] ?? null ) ? json_decode( $row['summary'], true ) : null;

			if ( is_array( $summary ) && array_key_exists( 'base_id', $summary ) ) {
				$summary['base_id'] = 0;
				$encoded            = wp_json_encode( $summary );
				$row['summary']     = is_string( $encoded ) ? $encoded : '';
			}
		}
	}

	/**
	 * Sanitize one imported pending post proposal in place for an importer without `unfiltered_html`.
	 * Sensitive and `_perflocale_*` meta and `parts` are dropped; an absent field is never defaulted to ''.
	 *
	 * @param array<string, mixed> $row     Row, after prepare_pending_change_row().
	 * @param array<mixed>         $payload Its decoded payload.
	 * @return bool True when the row was changed.
	 */
	private static function sanitize_pending_post_row( array &$row, array $payload ): bool {
		$clean = $payload;

		if ( is_string( $clean['post_title'] ?? null ) ) {
			$clean['post_title'] = wp_kses( $clean['post_title'], 'data' );
		}

		foreach ( [ 'post_content', 'post_excerpt' ] as $field ) {
			if ( is_string( $clean[ $field ] ?? null ) ) {
				$clean[ $field ] = wp_kses_post( $clean[ $field ] );
			}
		}

		if ( is_array( $clean['meta'] ?? null ) ) {
			$patterns  = \PerfLocale\Translation\PostTranslationManager::sensitive_meta_patterns();
			$post_type = is_string( $payload['post_type'] ?? null ) ? $payload['post_type'] : '';
			$meta      = [];

			foreach ( $clean['meta'] as $key => $value ) {
				$key = (string) $key;

				if ( str_starts_with( $key, '_perflocale_' ) || \PerfLocale\Translation\PostTranslationManager::is_sensitive_meta_key( $key, $patterns ) ) {
					continue;
				}

				if ( is_string( $value ) ) {
					/** This filter is documented in src/MachineTranslation/MetaTranslator.php */
					$format = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, $post_type, \PerfLocale\Util\Scalar::int( $row['source_id'] ?? 0 ) );
					$value  = 'text' === $format ? sanitize_textarea_field( $value ) : wp_kses_post( $value );
				} elseif ( null !== $value ) {
					continue;
				}

				$meta[ $key ] = $value;
			}

			$clean['meta'] = $meta;
		}

		unset( $clean['parts'] );

		if ( $clean === $payload ) {
			return false;
		}

		$encoded        = wp_json_encode( $clean );
		$row['payload'] = is_string( $encoded ) ? $encoded : '';

		return true;
	}

	/**
	 * Sanitize one imported pending string proposal in place, as imported string translations are.
	 *
	 * @param array<string, mixed> $row Row, after prepare_pending_change_row().
	 * @return bool True when the row was changed.
	 */
	private static function sanitize_pending_change_row( array &$row ): bool {
		if ( ! is_string( $row['payload'] ?? null ) || '' === $row['payload'] ) {
			return false;
		}

		$payload = json_decode( $row['payload'], true );

		if ( ! is_array( $payload ) ) {
			$row['payload'] = '';

			return true;
		}

		if ( 'post' === ( $row['object_type'] ?? '' ) ) {
			return self::sanitize_pending_post_row( $row, $payload );
		}

		$clean = [
			'translation' => is_string( $payload['translation'] ?? null ) ? \PerfLocale\Helper::sanitize_untrusted_string_translation( $payload['translation'] ) : '',
			// null keeps the stored forms on approve; only a list is cleaned.
			'extra_forms' => is_array( $payload['extra_forms'] ?? null ) ? array_map(
				static fn ( $form ): string => is_string( $form ) ? \PerfLocale\Helper::sanitize_untrusted_string_translation( $form ) : '',
				array_values( $payload['extra_forms'] )
			) : null,
		];

		if ( $clean === $payload ) {
			return false;
		}

		$encoded        = wp_json_encode( $clean );
		$row['payload'] = is_string( $encoded ) ? $encoded : '';

		return true;
	}

	/**
	 * Sanitize one imported `languages` row's string cells with LanguageRepository::sanitize_data() (as the Languages
	 * screen and REST store them), without the code's shape check, so a restore can keep an older or empty code. A value
	 * that outgrows its column (`<` becomes `&lt;`) is refused by wpdb and counts as failed.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return bool True when sanitizing changed the row.
	 */
	private static function sanitize_language_row( array &$row ): bool {
		$changed = false;

		foreach ( \PerfLocale\Database\Repository\LanguageRepository::sanitize_data( array_filter( $row, 'is_string' ) ) as $column => $clean ) {
			if ( is_string( $clean ) && $clean !== $row[ $column ] ) {
				$row[ $column ] = $clean;
				$changed        = true;
			}
		}

		return $changed;
	}

	/**
	 * Sanitize one imported `slug_translations` row in place as SlugTranslationRepository::insert() does: sanitize_title()
	 * on the slug, sanitize_key() on the object type, object_subtype raw (readers match its bytes). An empty slug is
	 * stored (no translation); a second one for the same key collides and is skipped.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return bool True when sanitizing changed the row.
	 */
	private static function sanitize_slug_translation_row( array &$row ): bool {
		$changed = false;

		if ( isset( $row['slug'] ) && is_string( $row['slug'] ) ) {
			$clean = sanitize_title( $row['slug'] );

			if ( $clean !== $row['slug'] ) {
				$row['slug'] = $clean;
				$changed     = true;
			}
		}

		if ( isset( $row['object_type'] ) && is_string( $row['object_type'] ) ) {
			$clean = sanitize_key( $row['object_type'] );

			if ( $clean !== $row['object_type'] ) {
				$row['object_type'] = $clean;
				$changed            = true;
			}
		}

		return $changed;
	}

	/**
	 * Whether other tables reference this table's ids.
	 *
	 * @param string $table_name Short table name.
	 * @return bool
	 */
	private function is_parent_table( string $table_name ): bool {
		foreach ( DataRegistry::fk_refs() as $children ) {
			if ( in_array( $table_name, $children, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A closure resolving a row to an existing parent id by natural key (languages.slug or locale,
	 * strings.original_hash); null for a table without one (translation_groups: every Merge row gets a new id).
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
					// languages has two unique keys: a new slug with an existing locale fails on the locale, so fall back to it, or
					// child FKs would name a missing id.
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
				$db  = Typed::wpdb();
				return static function ( array $row ) use ( $db, $tbl ): int {
					if ( empty( $row['original_hash'] ) ) {
						return 0; }
					// An other-form row of the tuple moves to its new hash first, so the merge matches it.
					\PerfLocale\Strings\StrIdentMigration::adopt( [ \PerfLocale\Util\Scalar::str( $row['original_hash'] ) => $row ] );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$held = $db->get_row(
						$db->prepare(
							'SELECT id, domain, context, original, original_hash FROM %i WHERE original_hash = %s LIMIT 1',
							$tbl,
							$row['original_hash']
						)
					);

					// Another tuple's row under its own form (a kept twin) is never this string: left unmapped.
					return is_object( $held ) && ! \PerfLocale\Database\Repository\StringRepository::foreign( $held, \PerfLocale\Util\Scalar::str( $row['original_hash'] ) ) ? \PerfLocale\Util\Scalar::int( \PerfLocale\Util\Scalar::prop( $held, 'id' ) ) : 0;
				};

			default:
				// translation_groups has no natural key: merged groups are fresh rows, reclaimed by sweep_orphan_groups(). Other
				// parents here have no FK children.
				return null;
		}
	}

	/**
	 * Merge checks on one piece of translation links before any is written. String links point at the string's id here
	 * (through the strings' id map) and its own group; with strings carried, an unmapped one is left out. A post or term
	 * link giving an object a second language is left out; one whose group has a member here joins that member's group.
	 *
	 * @param array<int, array{old_id: int, row: array<string, mixed>, sanitized: bool, demoted: bool, file: array<array-key, mixed>}> $prepared Rows of the piece.
	 * @param array{imported: int, skipped: int, failed: int, dropped: int, errors: array<int, string>, sanitized: int}                $result   Table result (updated).
	 * @return array<int, array{old_id: int, row: array<string, mixed>, sanitized: bool, demoted: bool, file: array<array-key, mixed>}>
	 */
	private function check_merge_links( array $prepared, array &$result ): array {
		$wpdb = Typed::wpdb();

		$links_table   = Schema::table( 'translation_links' );
		$strings_table = Schema::table( 'strings' );
		$string_ids    = [];
		$work          = $this->work_table();
		$file_strings  = [];
		$file_groups   = [];

		foreach ( $prepared as $entry ) {
			if ( 'string' === ( $entry['row']['type'] ?? '' ) ) {
				$file_strings[] = is_numeric( $entry['file']['object_id'] ?? null ) ? (int) $entry['file']['object_id'] : 0;
			} else {
				$file_groups[] = is_numeric( $entry['file']['group_id'] ?? null ) ? (int) $entry['file']['group_id'] : 0;
			}
		}

		$strings_map = $work->get( $work->id_map( 'strings' ), $file_strings );
		$anchors     = $work->get( ImportWorkTable::ANCHOR, $file_groups );

		foreach ( $prepared as $i => $entry ) {
			if ( 'string' !== ( $entry['row']['type'] ?? '' ) ) {
				continue;
			}

			$file_string = is_numeric( $entry['file']['object_id'] ?? null ) ? (int) $entry['file']['object_id'] : 0;

			if ( isset( $strings_map[ $file_string ] ) ) {
				$prepared[ $i ]['row']['object_id'] = $strings_map[ $file_string ];
				$string_ids[]                       = $strings_map[ $file_string ];
			} elseif ( $this->strings_carried ) {
				unset( $prepared[ $i ] );
				++$result['skipped'];
				$this->note_skip( 'unmapped_string' );
			}
		}

		if ( [] !== $string_ids ) {
			$groups = [];

			foreach ( array_chunk( array_values( array_unique( $string_ids ) ), 500 ) as $ids ) {
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table bound with %i; ids bound one %d each.
				foreach ( Typed::rows( $wpdb->get_results( $wpdb->prepare( "SELECT id, group_id FROM %i WHERE id IN ({$placeholders})", $strings_table, ...$ids ), ARRAY_A ) ) as $found ) {
					$group = Typed::int( $found['group_id'] ?? null );

					if ( $group > 0 ) {
						$groups[ Typed::int( $found['id'] ?? null ) ] = $group;
					}
				}
			}

			foreach ( $prepared as $i => $entry ) {
				$string_id = Typed::int( $entry['row']['object_id'] ?? null );

				if ( 'string' === ( $entry['row']['type'] ?? '' ) && isset( $groups[ $string_id ] ) ) {
					$prepared[ $i ]['row']['group_id'] = $groups[ $string_id ];
				}
			}
		}

		// Post and term links: the language each object already has here.
		$objects = [];

		foreach ( $prepared as $entry ) {
			$type   = $entry['row']['type'] ?? '';
			$object = is_numeric( $entry['row']['object_id'] ?? null ) ? (int) $entry['row']['object_id'] : 0;

			if ( is_string( $type ) && '' !== $type && 'string' !== $type && $object > 0 ) {
				$objects[ $type ][ $object ] = true;
			}
		}

		$has = [];

		foreach ( $objects as $type => $ids ) {
			foreach ( array_chunk( array_keys( $ids ), 500 ) as $chunk ) {
				$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table bound with %i; type and ids bound as values.
				foreach ( Typed::rows( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, language_id FROM %i WHERE type = %s AND object_id IN ({$placeholders})", $links_table, (string) $type, ...$chunk ), ARRAY_A ) ) as $found ) {
					if ( isset( $found['object_id'], $found['language_id'] ) ) {
						$has[ $type . '|' . Typed::int( $found['object_id'] ) ] = Typed::int( $found['language_id'] );
					}
				}
			}
		}

		foreach ( $prepared as $i => $entry ) {
			$type = $entry['row']['type'] ?? '';

			if ( ! is_string( $type ) || '' === $type || 'string' === $type ) {
				continue;
			}

			$key      = $type . '|' . ( is_numeric( $entry['row']['object_id'] ?? null ) ? (int) $entry['row']['object_id'] : 0 );
			$language = is_numeric( $entry['row']['language_id'] ?? null ) ? (int) $entry['row']['language_id'] : 0;

			if ( isset( $has[ $key ] ) && $has[ $key ] !== $language ) {
				unset( $prepared[ $i ] );
				++$result['skipped'];
				$this->note_skip( 'language_differs' );
				continue;
			}

			$has[ $key ] = $language;

			$file_group = is_numeric( $entry['file']['group_id'] ?? null ) ? (int) $entry['file']['group_id'] : 0;

			if ( ( $anchors[ $file_group ] ?? 0 ) > 0 ) {
				$prepared[ $i ]['row']['group_id'] = $anchors[ $file_group ];
			}
		}

		return array_values( $prepared );
	}

	/**
	 * Leave out rows whose post or term is another object here: a row's fingerprint (ObjectFingerprint) must match the
	 * object at its id, or it would attach to the wrong content. Rows without one (older files, string links) are not
	 * checked. Both modes; one query per kind and piece.
	 *
	 * @param array<int, array{old_id: int, row: array<string, mixed>, sanitized: bool, demoted: bool, file: array<array-key, mixed>}> $prepared    Rows of the piece.
	 * @param string                                                                                                                   $type_column The column that names the object's kind.
	 * @param array{imported: int, skipped: int, failed: int, dropped: int, errors: array<int, string>, sanitized: int}                $result      Table result (updated).
	 * @return array<int, array{old_id: int, row: array<string, mixed>, sanitized: bool, demoted: bool, file: array<array-key, mixed>}>
	 */
	private function check_fingerprints( array $prepared, string $type_column, array &$result ): array {
		$ids = [];

		foreach ( $prepared as $entry ) {
			$kind = ObjectFingerprint::kind( $entry['file'][ $type_column ] ?? null );

			if ( '' !== $kind && isset( $entry['file'][ ObjectFingerprint::FIELD ] ) && is_numeric( $entry['row']['object_id'] ?? null ) ) {
				$ids[ $kind ][] = (int) $entry['row']['object_id'];
			}
		}

		if ( [] === $ids ) {
			return $prepared;
		}

		$here = [];

		foreach ( $ids as $kind => $list ) {
			$here[ $kind ] = ObjectFingerprint::for_ids( $kind, $list );
		}

		foreach ( $prepared as $i => $entry ) {
			$kind = ObjectFingerprint::kind( $entry['file'][ $type_column ] ?? null );

			if ( '' === $kind || ! isset( $entry['file'][ ObjectFingerprint::FIELD ] ) ) {
				continue;
			}

			$id = is_numeric( $entry['row']['object_id'] ?? null ) ? (int) $entry['row']['object_id'] : 0;

			$cmp = ObjectFingerprint::compare( $entry['file'][ ObjectFingerprint::FIELD ], $here[ $kind ][ $id ] ?? null );

			if ( null === $cmp ) {
				continue;
			}

			if ( ! $cmp['match'] ) {
				unset( $prepared[ $i ] );
				++$result['skipped'];
				$this->note_skip( 'object_changed' );
			} elseif ( $cmp['changed'] ) {
				$this->note_changed_match( $cmp['parts'] );
			}
		}

		return array_values( $prepared );
	}

	/**
	 * Merge: for each file group, the group here that already holds its members. A file group whose existing members all
	 * sit in one group here joins it (else it keeps its new id), so its other members are not split from their pair.
	 * Read before the links are written, one query per type and piece.
	 *
	 * @param iterable $rows The file's translation links.
	 * @phpstan-param iterable<array-key, mixed> $rows
	 * @return void
	 */
	private function plan_link_anchors( iterable $rows ): void {
		$wpdb = Typed::wpdb();

		$links_table = Schema::table( 'translation_links' );
		$size        = self::batch_size( 'translation_links' );
		$piece       = [];
		$work        = $this->work_table();

		$plan = function ( array $piece ) use ( $wpdb, $links_table, $work ): void {
			$want      = [];
			$languages = $work->get( $work->id_map( 'languages' ), self::column_ints( $piece, 'language_id' ) );
			$anchor    = $work->get( ImportWorkTable::ANCHOR, self::column_ints( $piece, 'group_id' ) );
			$changed   = [];

			foreach ( $piece as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$type       = is_string( $row['type'] ?? null ) ? $row['type'] : '';
				$object     = is_numeric( $row['object_id'] ?? null ) ? (int) $row['object_id'] : 0;
				$file_group = is_numeric( $row['group_id'] ?? null ) ? (int) $row['group_id'] : 0;
				$language   = is_numeric( $row['language_id'] ?? null ) ? (int) $row['language_id'] : 0;

				if ( '' === $type || 'string' === $type || $object <= 0 || $file_group <= 0 ) {
					continue;
				}

				$want[ $type ][ $object ][] = [ $file_group, $languages[ $language ] ?? $language ];
			}

			foreach ( $want as $type => $members ) {
				foreach ( array_chunk( array_keys( $members ), 500 ) as $chunk ) {
					$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table bound with %i; type and ids bound as values.
					foreach ( Typed::rows( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, language_id, group_id FROM %i WHERE type = %s AND object_id IN ({$placeholders})", $links_table, (string) $type, ...$chunk ), ARRAY_A ) ) as $found ) {
						if ( ! isset( $found['object_id'], $found['language_id'], $found['group_id'] ) ) {
							continue;
						}

						foreach ( $members[ Typed::int( $found['object_id'] ) ] ?? [] as [ $file_group, $language ] ) {
							if ( Typed::int( $found['language_id'] ) !== (int) $language ) {
								continue;
							}

							$here = Typed::int( $found['group_id'] );

							if ( ! isset( $anchor[ $file_group ] ) ) {
								$anchor[ $file_group ]  = $here;
								$changed[ $file_group ] = $here;
							} elseif ( $anchor[ $file_group ] !== $here ) {
								$anchor[ $file_group ]  = 0;
								$changed[ $file_group ] = 0;
							}
						}
					}
				}
			}

			$work->put( ImportWorkTable::ANCHOR, $changed );
		};

		foreach ( $rows as $row ) {
			$piece[] = $row;

			if ( count( $piece ) >= $size ) {
				$plan( $piece );
				$piece = [];
			}
		}

		if ( [] !== $piece ) {
			$plan( $piece );
		}
	}

	/**
	 * Count a row whose object changed since the export but was recognised, per part that still matched.
	 *
	 * @param array<int, string> $parts Matched parts.
	 * @return void
	 */
	private function note_changed_match( array $parts ): void {
		foreach ( $parts as $part ) {
			$this->changed_matches[ $part ] = ( $this->changed_matches[ $part ] ?? 0 ) + 1;
		}

		$this->changed_matches['_rows'] = ( $this->changed_matches['_rows'] ?? 0 ) + 1;
	}

	/**
	 * The sentence for rows whose object changed but was recognised by an unchanged part; '' for none.
	 *
	 * @param array<string, int> $matched Part => rows, and `_rows` => rows in all.
	 * @return string
	 */
	public static function changed_match_notice( array $matched ): string {
		$rows = (int) ( $matched['_rows'] ?? 0 );

		if ( $rows <= 0 ) {
			return '';
		}

		$labels = [
			/* translators: a WordPress post's GUID, the permanent identifier stored with every post. */
			'guid' => __( 'GUID', 'perflocale' ),
			/* translators: a post's or term's URL slug. */
			'slug' => __( 'slug', 'perflocale' ),
			/* translators: a post's or order's creation date. */
			'date' => __( 'date', 'perflocale' ),
			/* translators: a term's name. */
			'name' => __( 'name', 'perflocale' ),
		];
		$parts  = [];

		foreach ( $labels as $key => $label ) {
			if ( ! empty( $matched[ $key ] ) ) {
				/* translators: 1: what matched (GUID, slug, date or name), 2: number of rows. */
				$parts[] = sprintf( __( '%1$s %2$d', 'perflocale' ), $label, (int) $matched[ $key ] );
			}
		}

		return sprintf(
			/* translators: 1: number of rows, 2: what still matched, with counts, for example "GUID 3, date 1". */
			_n(
				'%1$d row names a post or term that changed since the export; it was recognised by what did not change (%2$s) and imported.',
				'%1$d rows name a post or term that changed since the export; each was recognised by what did not change (%2$s) and imported.',
				$rows,
				'perflocale'
			),
			$rows,
			implode( ', ', $parts )
		);
	}

	/**
	 * Replace: how many of the file's id-keyed rows carry a fingerprint and how many name another object here; read
	 * before any write, one query per kind and piece.
	 *
	 * @param ImportReader                                     $reader  Indexed reader.
	 * @param int                                              $max_row Largest row read.
	 * @param array<string, array<int, array<int, mixed>>>     $members The add-on part row lists the import reads (addon_plan()).
	 * @return array{0: int, 1: int} Rows checked, rows naming a different object.
	 */
	private function fingerprint_mismatch_share( ImportReader $reader, int $max_row, array $members = [] ): array {
		$checked = 0;
		$changed = 0;

		foreach ( self::fingerprint_sources( $members, true ) as $table => [ $column, $fixed ] ) {
			if ( ! $reader->is_row_list( $table ) || 0 === $reader->count( $table ) ) {
				continue;
			}

			$piece = [];

			foreach ( self::beating( $reader->rows( $table, $max_row ) ) as $row ) {
				if ( ! is_array( $row ) || ! isset( $row[ ObjectFingerprint::FIELD ] ) || ! is_numeric( $row['object_id'] ?? null ) ) {
					continue;
				}

				$kind = '' !== $fixed ? $fixed : ObjectFingerprint::kind( $row[ $column ] ?? null );

				if ( '' === $kind ) {
					continue;
				}

				$piece[] = [ $kind, (int) $row['object_id'], $row[ ObjectFingerprint::FIELD ] ];

				if ( count( $piece ) >= self::batch_size( $table ) ) {
					self::count_fingerprints( $piece, $checked, $changed );
					$piece = [];
				}
			}

			if ( [] !== $piece ) {
				self::count_fingerprints( $piece, $checked, $changed );
			}
		}

		return [ $checked, $changed ];
	}

	/**
	 * Compare one piece of fingerprinted rows with this site's objects.
	 *
	 * @param list<array{0: string, 1: int, 2: mixed}> $piece   [ object kind, object id, the row's fingerprint ].
	 * @param int                                      $checked Rows compared (updated).
	 * @param int                                      $changed Rows that name a different object here (updated).
	 * @return void
	 */
	private static function count_fingerprints( array $piece, int &$checked, int &$changed ): void {
		$ids = [];

		foreach ( $piece as [ $kind, $id ] ) {
			$ids[ $kind ][] = $id;
		}

		$here = [];

		foreach ( $ids as $kind => $list ) {
			$here[ $kind ] = ObjectFingerprint::for_ids( $kind, $list );
		}

		foreach ( $piece as [ $kind, $id, $fp ] ) {
			$cmp = ObjectFingerprint::compare( $fp, $here[ $kind ][ $id ] ?? null );

			if ( null !== $cmp ) {
				++$checked;
				$changed += $cmp['match'] ? 0 : 1;
			}
		}
	}

	/**
	 * Count one row a check left out on purpose.
	 *
	 * @param string $reason Reason key (see skip_reason_notes()).
	 * @param int    $count  Rows.
	 * @return void
	 */
	private function note_skip( string $reason, int $count = 1 ): void {
		$this->skip_reasons[ $reason ] = ( $this->skip_reasons[ $reason ] ?? 0 ) + $count;
	}

	/**
	 * One sentence per reason rows were left out on purpose.
	 *
	 * @param array<string, int> $reasons Reason => rows.
	 * @return array<int, string>
	 */
	public static function skip_reason_notes( array $reasons ): array {
		$notes = [];

		foreach ( $reasons as $reason => $count ) {
			$count = (int) $count;

			if ( $count <= 0 ) {
				continue;
			}

			switch ( $reason ) {
				case 'not_restorable':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d option or meta row was not imported: its name is not one the sections in the file restore, or the row is malformed.', '%d option or meta rows were not imported: their name is not one the sections in the file restore, or the rows are malformed.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'bad_value':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d row was not imported because its value is not one PerfLocale stores there (for example an image that does not exist on this site).', '%d rows were not imported because their value is not one PerfLocale stores there (for example an image that does not exist on this site).', $count, 'perflocale' ),
						$count
					);
					break;
				case 'addon_inactive':
					$notes[] = sprintf(
						/* translators: %d: number of forms */
						_n( 'The Gravity Forms translations of %d form were not imported: Gravity Forms and its PerfLocale add-on must be active. Activate them and import the file again.', 'The Gravity Forms translations of %d forms were not imported: Gravity Forms and its PerfLocale add-on must be active. Activate them and import the file again.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'string_separator':
					$notes[] = sprintf(
						/* translators: %d: number of strings */
						_n( '%d string was not imported because its context or text holds a control character that gettext reserves (U+0004 or U+0000).', '%d strings were not imported because their context or text holds a control character that gettext reserves (U+0004 or U+0000).', $count, 'perflocale' ),
						$count
					);
					break;
				case 'unknown_language':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d row was not imported because the language it names does not exist on this site. Import the file with its Languages section to bring the language too.', '%d rows were not imported because the language they name does not exist on this site. Import the file with its Languages section to bring the language too.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'url_slug_taken':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d term URL slug was not imported because another term of the same language already uses it.', '%d term URL slugs were not imported because another term of the same language already uses them.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'object_changed':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d row was not imported because the post or term it names is a different item on this site, or no longer exists.', '%d rows were not imported because the post or term they name is a different item on this site, or no longer exists.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'language_differs':
					$notes[] = sprintf(
						/* translators: %d: number of translation links */
						_n( '%d translation link was not imported because its post or term already has a different language on this site.', '%d translation links were not imported because their post or term already has a different language on this site.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'string_slice_kept':
					$notes[] = sprintf(
						/* translators: %d: number of rows. "String Translations" names a section checkbox on the export screen: use the same words as that checkbox. */
						_n( '%d string group or string link in the file was not imported: this site keeps its own, because the file does not carry the String Translations section.', '%d string groups and string links in the file were not imported: this site keeps its own, because the file does not carry the String Translations section.', $count, 'perflocale' ),
						$count
					);
					break;
				case 'parent_not_saved':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d row was not imported because the row it belongs to could not be saved (see the errors above).', '%d rows were not imported because the rows they belong to could not be saved (see the errors above).', $count, 'perflocale' ),
						$count
					);
					break;
				case 'unmapped_string':
					$notes[] = sprintf(
						/* translators: %d: number of string translation links */
						_n( '%d string link was not imported because its string is not in the file.', '%d string links were not imported because their string is not in the file.', $count, 'perflocale' ),
						$count
					);
					break;
				default:
					break;
			}
		}

		return $notes;
	}

	/**
	 * The language ids this site has, read once per import.
	 *
	 * @return array<int, true>
	 */
	private function language_ids(): array {
		$wpdb = Typed::wpdb();

		if ( null === $this->language_id_set ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per import; table bound with %i.
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', Schema::table( 'languages' ) ) );

			$this->language_id_set = array_fill_keys( Typed::ints( $ids ), true );
		}

		return $this->language_id_set;
	}

	/**
	 * Rows of the languages table marked default (any non-zero is_default, as LanguageRepository reads it).
	 *
	 * @return int
	 */
	private function count_default_languages(): int {
		$wpdb = Typed::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per Replace import, inside its transaction; the table name is bound with %i.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_default <> 0', Schema::table( 'languages' ) ) );
	}

	/**
	 * This site's default language code, or '' when it has none.
	 *
	 * @return string
	 */
	private function read_site_default(): string {
		$wpdb = Typed::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per import; the table name is bound with %i.
		$slug = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE is_default <> 0 ORDER BY sort_order DESC, id DESC LIMIT 1', Schema::table( 'languages' ) ) );

		return is_scalar( $slug ) ? (string) $slug : '';
	}

	/**
	 * Reclaim groups a merge stranded, through TranslationGroupRepository::sweep_orphan_groups() (shared with the upgrade
	 * self-heal).
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
	 * Delete migration source-map rows whose translation group is gone.
	 *
	 * @return void
	 */
	private function drop_stale_source_map(): void {
		$wpdb = Typed::wpdb();

		$was = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// One table per DELETE: the SQLite integration's 2.2.x legacy driver mishandles a DELETE holding JOIN.
		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE group_id NOT IN ( SELECT id FROM %i )',
			Schema::table( 'migration_source_map' ),
			Schema::table( 'translation_groups' )
		);

		if ( is_string( $sql ) ) {
			$wpdb->query( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$wpdb->suppress_errors( $was );
	}

	/**
	 * Delete string links whose group is gone (a strings Replace clears groups without owning links; the merge sweep
	 * deletes string groups and leaves their links). Nothing else reclaims them, and they hold their object_lang slot,
	 * so the repair's INSERT IGNORE under a new group would be dropped. One statement over the string slice.
	 *
	 * @return int Rows deleted.
	 */
	private function reap_orphan_string_links(): int {
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// Tables from Schema::table() as %i, the type as a value; a multi-line prepare() needs the disable/enable form.
		// One table per DELETE, as drop_stale_source_map().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE type = %s AND group_id NOT IN ( SELECT id FROM %i )',
				$links_table,
				'string',
				$groups_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return max( 0, (int) $deleted );
	}
}
