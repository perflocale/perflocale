<?php
/**
 * Data importer - imports plugin data from JSON export file.
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
 * Imports PerfLocale data from a JSON export file.
 *
 * Validates the file structure, then imports each table in batches
 * with optional replace-all or merge mode.
 *
 * ## Reading the file in pieces
 *
 * The file is read through {@see ImportReader}: one pass records the
 * envelope and where each table's rows are, the data-quality pass decodes
 * every row once (so nothing is written from a file that is not valid
 * JSON), and each table is then imported a piece at a time
 * (`perflocale/import/batch_size` rows). Memory follows the piece and the
 * id maps a merge keeps, not the size of the file.
 *
 * ## Merge-mode limitation for translation_groups (v1)
 *
 * `translation_groups` carries no natural key - its only identifier is the
 * auto-increment `id`, used as an FK target by `translation_links`. In
 * **replace mode** the importer truncates the table first and preserves
 * the export's ids, so re-importing the same export is a no-op.
 *
 * In **merge mode** each group re-imports as a NEW row (auto-increment
 * issues a fresh id), and the matching `translation_links` skip on the
 * `object_lang (type, object_id, language_id)` UNIQUE constraint - so the
 * new groups never get linked to anything. The transient orphan rows are
 * cleaned up by
 * {@see self::sweep_orphan_groups()} which runs once at the end of every
 * merge-mode import, but during the import window an operator who
 * inspects `wp_perflocale_translation_groups` directly will see
 * duplicates of the rows they just re-imported.
 *
 * A file group with a member that already exists here (same object, same
 * language) joins that member's group instead, so the members the file adds
 * are paired with it ({@see self::plan_link_anchors()}); and a link that
 * would give a post or term a second language is left out and counted
 * ({@see self::check_merge_links()}).
 *
 * The full architectural fix (a `(source_site, source_group_id)` natural
 * key persisted across re-imports, OR a content-fingerprint computed from
 * each group's link tuples) is deferred to a post-1.0 release because:
 *
 *   - The transient duplicates are swept automatically - no permanent
 *     data corruption, just brief auto-increment-id waste during the
 *     import.
 *   - Operators concerned with deterministic re-import results should
 *     choose **replace mode** (merge is what both the admin form and the
 *     CLI default to).
 *   - The migration importers (WPML / Polylang / TranslatePress) already
 *     get this guarantee via `perflocale_migration_source_map`; the
 *     export/import path is the only operator-facing gap.
 *
 * Documented as a known v1 constraint in the merge-mode admin-UI tooltip
 * ({@see \PerfLocale\Admin\Pages\SettingsPage::render_export_import_tab()}).
 *
 * ## Numeric IDs do not travel between sites
 *
 * `translation_links`, `slug_translations` and `content_hashes` name a post
 * or a term by its numeric `object_id`, and the registry's FK map
 * (DataRegistry::fk_refs()) has no
 * entry for that column because no natural key exists to resolve one site's
 * IDs to another's. The same holds for a `type='string'` link, whose
 * `object_id` is the exporting site's `strings.id`, unless a merge carries
 * the strings too: then the link follows its string's new id. Rows that name a
 * language by `language_id` are remapped only while the bundle's own
 * `languages` section is being imported, and only in merge mode.
 *
 * So a bundle carrying those rows is meaningful on the site that wrote it
 * and on a copy of that site, and nowhere else. {@see self::import()}
 * refuses such a bundle when the envelope's recorded address is not this
 * site's, unless the operator confirms the copy relationship
 * (`$options['allow_foreign_ids']`). Settings and roles name nothing by id,
 * so they never cause a refusal (see "Configuration" below for when they are
 * applied); strings match on `original_hash` and travel too, as long as the
 * `languages` section their `language_id` points into travels with them.
 *
 * In REPLACE mode the same refusal fires on a foreign bundle whose row
 * lists are all empty, because {@see self::tables_to_wipe()} plans a DELETE
 * for every table of a declared section that the file carries as a row
 * list, empty or not: an otherwise empty file from another site would
 * silently empty this site's tables.
 *
 * What the gate does NOT cover, because a language row names nothing by id
 * and is matched by slug: a foreign bundle whose only section is
 * `languages` imports in merge mode, and in replace mode is refused only
 * for the wipe it declares. A CONFIRMED foreign replace that carries
 * `languages` still re-inserts the exporting site's language ids, and the
 * destination rows the bundle does not own keep pointing at the ids those
 * numbers used to mean.
 *
 * The gate reads the sections this class owns. A section an addon shipped
 * through `perflocale/export/sections` is dispatched to its own
 * `perflocale/import/section/<name>` listener AFTER the gate, and nothing
 * here inspects its payload - whether those rows survive a move to another
 * site is the addon's question to answer.
 *
 * ## Configuration: Replace applies it, Merge never does
 *
 * The file's settings, add-on settings, disabled-add-on list and role grants
 * (the `settings` and `roles` export sections) are applied in REPLACE mode
 * only. A MERGE import adds rows and leaves this site's configuration as it
 * is: each of those four parts the file carries is counted in `skipped` and
 * named in the result's `not_applied`, with a `notice` for the operator. A
 * file that carries only those sections owns no table, so a Replace import
 * of it deletes no row. The settings that describe the site itself (its
 * domains, provider endpoints, uninstall choice and paused queue; see
 * {@see self::kept_setting_keys()}) keep this site's values in Replace too,
 * and the report names those the file had differently. Replace applies the configuration after its table
 * transaction has committed, so an import that fails or dies inside the
 * transaction changes neither the tables nor the configuration.
 *
 * @phpstan-type ImportResult array{imported: int, skipped: int, errors: array<int, string>, sanitized: int, refused?: bool, not_applied?: array<int, string>, notice?: string, refused_addresses?: array<int, string>, address_notice?: string, notes?: array<int, string>, default_language_kept?: string, skipped_reasons?: array<string, int>, kept_settings?: array<int, string>, unread_sections?: array<int, string>, undeclared_sections?: array<int, string>, changed_matches?: array<string, int>}
 */
final class DataImporter {

	/**
	 * Rows per insert batch.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Fewest and most rows per piece (perflocale/import/batch_size, and
	 * `wp perflocale import --batch-size`).
	 */
	public const BATCH_SIZE_MIN = 50;
	public const BATCH_SIZE_MAX = 5000;

	/**
	 * How a replace import looks up each unique key per piece: table =>
	 * [ key index in DataRegistry::unique_keys() => the column whose values form the IN list ].
	 * A table not listed here (languages, slug_translations) has text keys
	 * that must compare the way the database's indexes compare them, so its
	 * keys are read whole once instead; both tables stay small.
	 */
	/**
	 * Largest row id an import accepts (2^53 - 1). A replace keeps the file's
	 * ids, and an id near the BIGINT ceiling would push the table's next id
	 * there.
	 */
	private const MAX_ROW_ID = 9007199254740991;

	/**
	 * Fewest bytes a table row the data-quality gate accepts takes in an
	 * export file: an empty column map (`{}` or `[]`) and the comma after it.
	 */
	private const MIN_ROW_BYTES = 3;

	/**
	 * Memory a Merge keeps for each row of a file's largest table until the
	 * import ends (id maps, link anchors, linked posts and the like), with a
	 * margin: measured at about 220 bytes per translation link row (0.44 KB
	 * per translation group with two links).
	 */
	private const RETAINED_BYTES_PER_ROW = 512;

	/**
	 * The share of the memory still free when the import starts that the
	 * retained state may take, in percent.
	 */
	private const RETAINED_MEMORY_SHARE = 80;

	/**
	 * The row ceiling of 1.0.7.2 and earlier: the memory-based default never
	 * refuses a file those versions accepted.
	 */
	private const LEGACY_MAX_ROWS = 500000;

	/**
	 * Tables whose `type = 'string'` rows belong to the String Translations
	 * section even though Translation Links exports the tables whole.
	 */
	private const STRING_SLICE_TABLES = [ 'translation_groups', 'translation_links' ];

	/**
	 * Top-level keys of a file that carry configuration (applied by Replace
	 * only, see apply_configuration()).
	 */
	private const CONFIGURATION_KEYS = [ 'settings', 'addon_settings', 'disabled_addons', 'roles' ];

	/**
	 * The first plugin version whose exports always record the site address
	 * and fingerprint every ID-keyed row whose object exists. A file from
	 * this version on that lacks either is treated as a copy from another
	 * site (see import_from()).
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
	 * Post ids the file's translation links name, as keys; collected while
	 * the links are read, for TemplateBlockRefs::after_restore().
	 *
	 * @var array<int, true>
	 */
	private array $linked_posts = [];

	/**
	 * When the languages table is not cleared: this site's default language
	 * code, read when the first default row of the file arrives ('' when the
	 * site has none); null until then.
	 *
	 * @var string|null
	 */
	private ?string $site_default = null;

	/**
	 * When the languages table is not cleared: the code of the file's
	 * default language when it was added as an ordinary language; ''
	 * otherwise.
	 *
	 * @var string
	 */
	private string $default_kept = '';

	/**
	 * Whether this import cleared the languages table (a Replace whose file
	 * carries the Languages section). Only then may a row of the file become
	 * the site's default language as it stands; otherwise the guard in
	 * import_piece() keeps this site's default.
	 *
	 * @var bool
	 */
	private bool $languages_cleared = false;

	/**
	 * Rows a check left out on purpose, by reason (see skip_reason_notes()).
	 *
	 * @var array<string, int>
	 */
	private array $skip_reasons = [];

	/**
	 * Whether this import carries `strings` rows (a merge then maps every
	 * string link through the strings' id map).
	 *
	 * @var bool
	 */
	private bool $strings_carried = false;

	/**
	 * Merge mode: file group id => the group on this site that already holds
	 * one of its members (same object, same language), or 0 when its members
	 * sit in different groups here. See plan_link_anchors().
	 *
	 * @var array<int, int>
	 */
	private array $link_anchor = [];

	/**
	 * Whether this import added, replaced or removed language rows.
	 *
	 * @var bool
	 */
	private bool $languages_changed = false;

	/**
	 * Whether a replace import cleared translation_groups or
	 * translation_links (whole or in part); the string-link reap and repair
	 * then run whatever the file carried.
	 *
	 * @var bool
	 */
	private bool $replaced_link_tables = false;

	/**
	 * Whether this replace import cleared only the post and term rows of
	 * translation_groups (a Translation Links file without String
	 * Translations), so this site's string groups stay and can hold an id
	 * the file gives a post or term group.
	 *
	 * @var bool
	 */
	private bool $groups_keep_strings = false;

	/**
	 * Whether this replace import cleared only the post and term rows of
	 * translation_links, so this site's string links stay and can hold the
	 * id the file gives a post or term link.
	 *
	 * @var bool
	 */
	private bool $links_keep_strings = false;

	/**
	 * Replace mode: file group id => the id the group was written under,
	 * for each post or term group of the file whose id a kept string group
	 * holds. The file's links of that group are written into it under the
	 * new id.
	 *
	 * @var array<int, int>
	 */
	private array $moved_groups = [];

	/**
	 * Language ids this site has, as keys; read on first use, after the
	 * languages table has been imported.
	 *
	 * @var array<int, true>|null
	 */
	private ?array $language_id_set = null;

	/**
	 * Rows whose post or term changed since the export but was still
	 * recognised: matched part (guid, slug, date, name) => rows.
	 *
	 * @var array<string, int>
	 */
	private array $changed_matches = [];

	/**
	 * Import data from a JSON file.
	 *
	 * @param string               $file_path        Path to the JSON file.
	 * @param bool                 $replace          Whether to replace all existing data (true) or merge (false).
	 *                                               Only replace applies the file's settings,
	 *                                               add-on settings, add-on list and roles.
	 * @param bool                 $sanitize_strings Run the text columns of three tables through
	 *                                               the sanitizers the plugin's own writers
	 *                                               apply for a user without `unfiltered_html`:
	 *                                               `string_translations` values (the
	 *                                               translation and each extra plural form)
	 *                                               through Helper::sanitize_untrusted_string_translation();
	 *                                               `languages` rows through
	 *                                               LanguageRepository::sanitize_data(), with a
	 *                                               row refused when sanitizing empties its code
	 *                                               (the code's shape check is not applied);
	 *                                               `slug_translations` slugs through
	 *                                               sanitize_title() and object types through
	 *                                               sanitize_key(), as
	 *                                               SlugTranslationRepository::insert() does.
	 *                                               The flag changes nothing else in the file.
	 *                                               The caller decides: the admin import job
	 *                                               passes true for a user without
	 *                                               `unfiltered_html`; WP-CLI passes false
	 *                                               and restores the file's bytes.
	 * @param array<string, mixed> $options          Import options. One key is read:
	 *                                               `allow_foreign_ids` - the operator has
	 *                                               confirmed this site is a copy of the site
	 *                                               the bundle came from, so the site-identity
	 *                                               gate below is skipped. Read with
	 *                                               `! empty()`: a replayed job's args come
	 *                                               back out of JSON as `1`, `'1'` or `true`.
	 *                                               `addon_choices` - the add-on data to import:
	 *                                               a list of `addon_data` (the parts on the
	 *                                               shared "Add-on data" checkbox, and every
	 *                                               add-on row of a file from before add-on
	 *                                               parts) and group parts (`<owner>/<group
	 *                                               id>`). Absent or null: everything the file
	 *                                               carries. A part whose add-on declares no
	 *                                               such data on this site is skipped and named
	 *                                               in `notes`, as is a part left out by choice.
	 * @return array{imported: int, skipped: int, errors: array<int, string>, sanitized: int, refused?: bool, not_applied?: array<int, string>, notice?: string, refused_addresses?: array<int, string>, address_notice?: string, notes?: array<int, string>, default_language_kept?: string, skipped_reasons?: array<string, int>, kept_settings?: array<int, string>, unread_sections?: array<int, string>, undeclared_sections?: array<int, string>, changed_matches?: array<string, int>}
	 *         `notes` holds one sentence for each thing the import left out,
	 *         kept or changed on purpose (the admin notice and WP-CLI print
	 *         them). `default_language_kept` is the file's default language
	 *         when an import that did not clear the languages table (a merge,
	 *         or a Replace without the Languages section) added it as an
	 *         ordinary language because this site already has a default.
	 *         `sanitized` counts stored string translation, language and
	 *         translated-slug rows that `$sanitize_strings` changed, whether
	 *         markup was removed or a value was only reformatted.
	 *         `not_applied` and `notice` are present only after a merge import
	 *         of a file that carries configuration: `not_applied` lists the
	 *         parts left unapplied (`settings`, `addon_settings`,
	 *         `disabled_addons`, `roles`, each also counted in `skipped`), and
	 *         `notice` says so in a sentence for the operator.
	 *         `refused_addresses` and `address_notice` are present only when
	 *         the settings update refused a provider address change
	 *         (Settings::last_update_refused_addresses(): a multisite network,
	 *         the provider's key supplied from outside the database, an
	 *         importer who is not a super admin): the refused settings and the
	 *         sentence that names them.
	 *         `undeclared_sections` names the configuration keys and the
	 *         members of `data` a file with a `sections` list carries
	 *         outside every section it declares; they are not read.
	 *         `refused` is present only when the import stopped before its
	 *         first write: the file could not be read, failed the structure
	 *         or data-quality checks, or a gate (site identity, the replace
	 *         fingerprint share) refused it. A caller reports such a result as
	 *         a failure that changed nothing; `errors` holds the reason.
	 */
	public function import( string $file_path, bool $replace = false, bool $sanitize_strings = false, array $options = [] ): array {
		$result = [
			'imported'  => 0,
			'skipped'   => 0,
			'errors'    => [],
			'sanitized' => 0,
		];

		// Reset cross-table id maps so independent import() calls don't
		// cross-contaminate each other (e.g. CLI script doing two imports
		// in a row).
		$this->id_maps              = [];
		$this->linked_posts         = [];
		$this->site_default         = null;
		$this->default_kept         = '';
		$this->languages_cleared    = false;
		$this->skip_reasons         = [];
		$this->strings_carried      = false;
		$this->link_anchor          = [];
		$this->languages_changed    = false;
		$this->replaced_link_tables = false;
		$this->groups_keep_strings  = false;
		$this->links_keep_strings   = false;
		$this->moved_groups         = [];
		$this->language_id_set      = null;
		$this->changed_matches      = [];

		// One import at a time per site, whatever started it (the form,
		// WP-CLI, a background job, a network-import slice): held from
		// before the file is read until the last option and meta row is
		// written, so two imports never mix their rows or configuration.
		if ( ! ImportLock::acquire() ) {
			$result['errors'][] = ImportLock::busy_message();
			$result['refused']  = true;

			return $result;
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

		// Read the file's structure: the envelope fields and small sections
		// are decoded, the table rows are only located and counted. The rows
		// are decoded one at a time by the passes below, so neither the file
		// nor its decoded tables are ever held whole.
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
		} finally {
			$reader->close();
		}
	}

	/**
	 * The data-quality and reader limits of an import, as the
	 * perflocale/import/quality_limits filter returns them.
	 *
	 * @return array<array-key, mixed>
	 */
	public static function quality_limits(): array {
		/**
		 * Filter the data-quality limits applied to imports.
		 *
		 * @hook perflocale/import/quality_limits
		 *
		 * @param array{max_languages?: int, max_rows_per_table?: int, max_value_bytes?: int, max_section_bytes?: int, max_config_bytes?: int, max_captured_bytes?: int, max_top_level_members?: int, max_data_members?: int} $limits
		 *        Defaults: 500 / the rows that fit in PHP's memory_limit, never
		 *        fewer than 500000 and never more than the import file ceiling
		 *        divided by 3 (178956970 for the 512 MB
		 *        `perflocale/import/max_file_bytes` default), so a file whose
		 *        import would run out of memory is refused before anything is
		 *        written; with no memory limit (-1), that file ceiling / 1048576 /
		 *        33554432 / 4194304 / 67108864 / 1000 / 100000. `max_section_bytes`
		 *        is the largest single row, and the largest add-on section, the
		 *        importer reads; the file itself is read in pieces.
		 *        `max_config_bytes` is the largest of the file's own top-level
		 *        keys (settings, roles, add-on settings and the other envelope
		 *        keys), which hold settings and lists, never content.
		 *        `max_captured_bytes` is the most the importer keeps in all
		 *        while it reads a file's structure (the sections other than the
		 *        table rows, and the names of the tables); `max_top_level_members`
		 *        the most top-level sections a file may have, and
		 *        `max_data_members` the most tables and row lists in its `data`
		 *        section (for `wp perflocale network-import`, the most sites).
		 *        Only the core sections and the sections of an add-on that hooks
		 *        `perflocale/import/section/<name>` are read; the importer walks
		 *        over any other top-level section without keeping it. The same
		 *        limits apply to `wp perflocale network-import`.
		 *        The memory rule for `max_rows_per_table`: a Merge keeps about
		 *        512 bytes (with margin) for each row of the file's largest table
		 *        until the import ends, and may use 80% of the memory left free
		 *        when the import starts. Return a `max_rows_per_table` to replace
		 *        that default, for example on a host whose PHP processes may
		 *        exceed memory_limit, or to keep large imports out.
		 */
		return (array) apply_filters( 'perflocale/import/quality_limits', [] );
	}

	/**
	 * The limits the reader applies while it reads a file's structure.
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
	 * Whether the importer reads a top-level member of a file: a core
	 * envelope key, or the section of an add-on that restores it through
	 * `perflocale/import/section/<name>`. Any other member is walked over
	 * without being kept and named as not imported.
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
				// The envelope's own keys hold settings and lists, never content:
				// they get the smaller settings cap. Add-on sections keep the section cap.
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
	 * Rows read per piece for one table, and the interval of the importer's
	 * per-piece work (look-ups, cache release).
	 *
	 * @param string $table_name Table short name.
	 * @return int
	 */
	private static function batch_size( string $table_name ): int {
		/**
		 * Rows the data importer reads and checks per piece.
		 *
		 * The importer reads an export file a piece at a time: for each piece
		 * it runs one look-up query per unique key of the table (so rows that
		 * would collide are skipped before any write) and then inserts the
		 * rows one by one. Larger pieces mean fewer look-ups; smaller ones
		 * keep peak memory lower. Clamped to 50-5000. `wp perflocale import
		 * --batch-size` (and `network-import`) sets it for one run.
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
		// The envelope as the checks below read it: every top-level member
		// decoded, and `data` as table => row count (a list's count is empty()
		// exactly when the list is), or the member itself when it is not a list.
		$data = Typed::map( $reader->envelope() );

		if ( $reader->has_table_data() ) {
			$data['data'] = $reader->table_summary();
		}

		// A file that lists its sections is read for those sections only:
		// a table list, row list, add-on part or configuration key that no
		// declared section carries is left unread and named in the report.
		$undeclared = self::undeclared_members( $data, array_keys( $reader->table_summary() ) );

		foreach ( $undeclared['envelope'] as $key ) {
			unset( $data[ $key ] );
		}

		if ( [] !== $undeclared['data'] ) {
			$reader->forget_tables( $undeclared['data'] );
			$data['data'] = $reader->table_summary();
		}

		// Validate structure.
		$validation = $this->validate( $data );

		if ( ! empty( $validation ) ) {
			$result['errors']  = $validation;
			$result['refused'] = true;
			return $result;
		}

		// Site-identity gate. As early as the envelope allows: validate() has
		// guaranteed a `data` array, and everything read below is an address
		// and a set of array keys. Ahead of the data-quality scan on purpose -
		// that scan walks every value in the file, and it never looks at
		// `site_url`, so nothing here depends on it having run. A refusal
		// therefore costs one address comparison rather than a full walk, and
		// it leaves settings, add-on settings, the disabled list, the role grants and
		// the replace transaction all untouched.
		//
		// The rows this protects name posts, terms and strings by numeric ID
		// (see the class docblock). On a site that is not the one the bundle
		// came from, those IDs address unrelated content, and importing them
		// groups that content as translations of each other. There is nothing
		// to remap them with, so the only honest outcomes are "refuse" and
		// "the operator says this site is a copy".
		$allow_foreign_ids = ! empty( $options['allow_foreign_ids'] );
		$recorded_url      = $data['site_url'] ?? null;
		$addon             = self::addon_plan( $reader, $data, $options['addon_choices'] ?? null );

		// Three shapes, two verdicts. No address at all - the key absent, or an
		// empty string - is the legacy envelope shape and is NOT checked here
		// (a file from IDENTITY_SINCE on is, by the rule just below). An
		// address that is present but yields no host (a number, an array, a
		// bare scheme, a JSON `false`) identifies nothing and so cannot be this
		// site: it counts as a mismatch. `wp perflocale network-import` has
		// always judged its slices that way, and both paths read the same key
		// out of the same file.
		$has_recorded_url = isset( $data['site_url'] ) && '' !== $data['site_url'];
		$identity         = self::bundle_matches_this_site( $recorded_url );

		// A file whose `version` is IDENTITY_SINCE or later always records
		// the address of the site that wrote it. Without one it is treated as
		// a copy from another site: refused, without the copy-confirmation
		// box, for whatever would refuse a file from another site. Older
		// files keep the legacy rule above.
		$identity_rules = self::records_identity( $data['version'] ?? null );

		if ( ! $allow_foreign_ids && $identity_rules && ! $has_recorded_url
			&& ( ( $replace && [] !== self::tables_to_wipe( $data ) )
				|| self::carries_object_ids( $data, array_keys( $addon['members'] ) )
				|| ( self::carries_language_refs( $data ) && ! self::has_rows( $data, 'languages' ) ) ) ) {
			$result['errors'][] = __( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. This file records no site address, so it cannot be matched to this site. Tick the box only when this site is a copy of the site the file was exported from and its post and term IDs still match.', 'perflocale' );
			$result['refused']  = true;

			return $result;
		}

		if ( ! $allow_foreign_ids
			&& ( false === $identity || ( $has_recorded_url && null === $identity ) ) ) {
			// The normalized identity, never the envelope's raw string: the
			// message is carried through an admin redirect and a CLI warning,
			// and the file chooses its own bytes.
			//
			// Each message leads with the refusal and the remedy: on the job
			// path it is the failed job's reason, and the Jobs screen renders
			// only the first ~137 characters beside the row and hides the rest
			// behind a tooltip, so a diagnosis-first sentence would bury the fix.
			$theirs = self::site_identity( $recorded_url );

			if ( null === $theirs ) {
				/* translators: stands in for the address recorded in the export file when the recorded value is not a readable address. */
				$theirs = __( 'an unreadable address', 'perflocale' );
			}

			$ours = (string) ( self::site_identity( get_option( 'home' ) ) ?? self::site_identity( home_url() ) );

			// Replace mode's destructive half is keyed on the row lists the
			// file carries for its declared sections, not on the rows in them:
			// tables_to_wipe() plans a DELETE for every unscoped table of a
			// declared section that the file carries as a list, empty or not.
			// A foreign bundle that declares its sections and carries only
			// empty lists therefore passes both row tests below and still
			// empties this site's tables. The plan is
			// the thing to test, and it is a pure static over the envelope, so
			// testing it costs nothing and adds no query. Named apart from the
			// `$wipe_plan` the replace branch builds further down: this one is
			// reached only on a refusal, and the accept path still resolves the
			// plan exactly once.
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

			if ( self::carries_object_ids( $data, array_keys( $addon['members'] ) ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: site address recorded in the export file, 2: this site's address. The quoted phrase is the import form's checkbox label and must match it word for word. */
					__( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. This file was exported from %1$s, not this site (%2$s). Its translation links, translated slugs and content hashes name posts and terms by numeric ID, and on a site that was set up separately the same IDs belong to different content. Tick the box only when this site is a copy of %1$s and its post and term IDs still match - a staging clone, a domain migration, or a backup of this site that records a language-specific address. The box is labelled “This site is a copy of the site the file was exported from”.', 'perflocale' ),
					$theirs,
					$ours
				);
				$result['refused'] = true;

				return $result;
			}

			// No object IDs, but rows that name a language by its numeric id
			// and no `languages` section to resolve them against. Nothing maps
			// them, so a German translation lands under whatever language holds
			// that id here.
			if ( self::carries_language_refs( $data ) && ! self::has_rows( $data, 'languages' ) ) {
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

		// Data-quality gate: invalid UTF-8, null bytes, cardinality bombs,
		// oversize values, malformed rows. Fail closed BEFORE any write -
		// a partially-imported corrupt file is worse than a rejected one.
		// It is also the pass that decodes every row once, so a row that is
		// not valid JSON is refused here, before anything is written.
		//
		// The same pass notes whether the ID-keyed rows that can carry a
		// fingerprint carry any, for the rule below.
		$presence = [
			'rows' => false,
			'fp'   => false,
		];

		try {
			$quality = self::stream_quality_issues( $reader, $data, $limits, ( ! $allow_foreign_ids && $identity_rules ) ? self::fingerprint_sources( $addon['members'] ) : [], $presence );
		} catch ( \UnexpectedValueException $e ) {
			$quality = [ self::read_error_message( $e, $limits ) ];
		}

		if ( ! empty( $quality ) ) {
			$result['errors']  = $quality;
			$result['refused'] = true;
			return $result;
		}

		// A file from IDENTITY_SINCE on fingerprints every ID-keyed row whose
		// post, term or order existed when it was written. One whose such
		// rows carry no fingerprint at all cannot be checked against this
		// site's content, so it is treated as a copy from another site.
		if ( $presence['rows'] && ! $presence['fp'] ) {
			$result['errors'][] = __( 'Import refused. Tick the copy-confirmation box on the import form (WP-CLI: --force) to import it anyway. The rows of this file that name posts, terms or orders by ID carry no fingerprints, so they cannot be checked against this site\'s content. Tick the box only when this site is a copy of the site the file was exported from and its IDs still match.', 'perflocale' );
			$result['refused']  = true;

			return $result;
		}

		ImportLock::heartbeat();

		$max_row               = self::max_section_bytes( $limits );
		$this->strings_carried = $reader->count( 'strings' ) > 0;

		// A replace whose ID-keyed rows mostly name other content here is
		// the wrong site's file (or a file from before this site's content
		// was rebuilt). Replace would first clear this site's own rows for
		// those sections, so the whole file is refused before any write when
		// more than half of the rows that carry a fingerprint name a
		// different post, term or order. A merge only adds rows: it skips
		// those rows and names them (check_fingerprints()).
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

		// Merge never changes this site's configuration. Each configuration
		// part the file carries is counted as skipped and named, and the four
		// blocks below run in Replace mode only.
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
		}

		// Import tables.
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			// Replace mode wipes the tables that BELONG to the sections in
			// the export and that the file carries as row lists, whether or
			// not each list holds rows. Otherwise a table that was empty at
			// export time would leak post-export rows through the replace. A
			// table the file does not carry at all is left as it is: the
			// exporter writes a list for every table of every section it
			// declares, so only a damaged or hand-made file leaves one out.
			//
			// CRITICAL: we restrict the wipe to tables for the EXPORTED
			// sections only. A user importing a strings-only export with
			// `replace` expects "replace the strings"; truncating every
			// PerfLocale table would silently destroy languages, links,
			// etc., across the entire plugin.
			$wpdb = Typed::wpdb();

			// The replace-mode wipe plan: table short-name => type scope
			// (null means the whole table). Resolved once because it is
			// needed twice - the loop below applies it, and the abort gate
			// inside the import loop has to know which tables were only
			// PARTIALLY cleared.
			$wipe_plan = $replace ? self::tables_to_wipe( $data ) : [];

			$this->languages_cleared   = array_key_exists( 'languages', $wipe_plan );
			$this->groups_keep_strings = '!string' === ( $wipe_plan['translation_groups'] ?? null );
			$this->links_keep_strings  = '!string' === ( $wipe_plan['translation_links'] ?? null );

			// The wipe shares the import's try, so a statement that fails
			// there reaches the same catch and its ROLLBACK. The file's
			// configuration is applied only after the COMMIT below.
			ImportLock::heartbeat();

			try {
				if ( $replace ) {
					// Atomic replace: the wipe AND the re-import run inside ONE
					// transaction so a mid-restore failure rolls back instead of
					// leaving the tables emptied or half-populated. DELETE (not
					// TRUNCATE) because TRUNCATE is DDL and implicitly commits - it
					// would silently end the transaction and re-open the data-loss
					// window. IDs are preserved either way (replace keeps ids), so
					// not resetting AUTO_INCREMENT is correct.
					//
					// Each statement is checked against false only: wpdb returns 0
					// for a transaction statement that succeeded and for a DELETE
					// that found the table already empty. Without the transaction,
					// every DELETE below would commit on its own.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
						self::log_db_error( 'START TRANSACTION', (string) $wpdb->last_error );

						throw new \RuntimeException( __( 'Replace import aborted: the database could not open a transaction; rolling back so no data is lost.', 'perflocale' ) );
					}

					$was_suppressing = $wpdb->suppress_errors( true );

					// finally, not a trailing call: whatever leaves the loop, the
					// connection's error reporting goes back to how it was found.
					try {
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
								// - see reap_orphan_string_links().
								$wipe = str_starts_with( $type_scope, '!' )
									? $wpdb->prepare( 'DELETE FROM %i WHERE type <> %s', $full_table, substr( $type_scope, 1 ) )
									: $wpdb->prepare( 'DELETE FROM %i WHERE type = %s', $full_table, $type_scope );
							} else {
								$wipe = $wpdb->prepare( 'DELETE FROM %i', $full_table );
							}

							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The replace wipe, prepared above (table bound with %i, the type as a value).
							$wiped = null === $wipe ? false : $wpdb->query( $wipe );

							// A table left uncleared turns the restore into a merge:
							// the file's rows that collide skip as duplicates and the
							// stale rows stay. A DELETE that ended the whole
							// transaction (a deadlock) would also let the next one
							// commit on its own, so stop at the first failure.
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
					} finally {
						$wpdb->suppress_errors( $was_suppressing );
					}
				}

				foreach ( DataRegistry::import_tables() as $table_name ) {
					if ( ! $reader->is_row_list( $table_name ) ) {
						continue;
					}

					$row_count = $reader->count( $table_name );

					if ( 0 === $row_count ) {
						continue;
					}

					// $replace semantics:
					// wipe → handled above (covers empty-in-export tables)
					// keep ids → needed so translation_links FK-ish pointers
					// into translation_groups stay valid
					// Signal both via the second arg; import_table still treats
					// $replace==true as "preserve IDs" but now NO-OPs the wipe
					// (we already did it).
					if ( 'translation_links' === $table_name && ! $replace ) {
						$this->plan_link_anchors( $reader->rows( $table_name, $max_row ) );
					}

					// A `!type` wipe left that slice in place: the file's rows
					// of that type are not this section's to restore.
					$scope        = $wipe_plan[ $table_name ] ?? null;
					$exclude_type = ( is_string( $scope ) && str_starts_with( $scope, '!' ) ) ? substr( $scope, 1 ) : '';

					$table_result = $this->import_table( $table_name, $reader->rows( $table_name, $max_row ), $replace, $sanitize_strings, $exclude_type );
					if ( 'languages' === $table_name && $table_result['imported'] > 0 ) {
						$this->languages_changed = true;
					}

					$result['imported']  += $table_result['imported'];
					$result['skipped']   += $table_result['skipped'];
					$result['sanitized'] += $table_result['sanitized'];

					if ( ! empty( $table_result['errors'] ) ) {
						foreach ( $table_result['errors'] as $err ) {
							$result['errors'][] = $table_name . ': ' . $err;
						}
					}

					// Replace mode already DELETEd this table inside the
					// transaction. If a non-empty export inserted zero rows,
					// something systematic failed (unknown column, charset,
					// strict-mode) - committing now would leave the table
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
					// insert failure - import_table() counts those
					// separately from duplicate-entry skips. Unscoped tables
					// keep the original guard: every one of their rows is
					// gone, so zero inserts is always fatal.
					$scoped_wipe = ( ( $wipe_plan[ $table_name ] ?? null ) !== null );

					// A replace is a RESTORE, and a restore is all-or-nothing.
					// The pre-import rows for this table were deleted a few
					// lines above, inside this same transaction, so a row the
					// database refused is not a row that "failed to merge" -
					// it is a row the operator no longer has anywhere. Before
					// this gate, 6,808 of 6,809 links committed and the import
					// reported success with the error buried in a list.
					// `failed` counts only REAL errors: duplicate-key skips
					// (the legitimate collision on a scoped wipe, where rows
					// of another type survive and share the id space) are
					// counted separately and never reach here.
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

					// Systematic failure with no per-row error to point at:
					// a non-empty section inserted nothing. Unscoped tables
					// were emptied outright, so zero inserts is always fatal.
					// A scoped wipe reaching here has failed === 0: every row
					// duplicate-skipped against surviving rows of another
					// type, which is an ordinary partial import. Rows left out
					// on purpose (a different object, a missing language) are
					// not a systematic failure either.
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

				// A Replace that cleared or wrote the languages table must leave
				// exactly one default language: no default leaves the site
				// without its unprefixed URLs and fallbacks, and a second one
				// moves them to whichever row is read last. Checked inside the
				// transaction, so a refusal rolls everything back.
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
					// A refused COMMIT usually means the server has already
					// discarded the transaction. The catch's ROLLBACK covers one
					// still open; no configuration has been written yet.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( false === $wpdb->query( 'COMMIT' ) ) {
						self::log_db_error( 'COMMIT', (string) $wpdb->last_error );

						throw new \RuntimeException( __( 'Replace import not confirmed: the database reported an error while saving it. Run the import again.', 'perflocale' ) );
					}
				}
			} catch ( \Throwable $e ) {
				if ( $replace ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'ROLLBACK' );
				}

				// The tables are back where they started, and the file's
				// configuration was never applied, so the site is as it was.
				throw $e;
			}

			ImportLock::heartbeat();

			// A replace that cleared the languages table changed the set of
			// language codes even when it wrote no row.
			if ( array_key_exists( 'languages', $wipe_plan ) ) {
				$this->languages_changed = true;
			}

			if ( array_key_exists( 'translation_groups', $wipe_plan ) || array_key_exists( 'translation_links', $wipe_plan ) ) {
				$this->replaced_link_tables = true;
			}

			// A migration source-map row never outlives its group (the sweep
			// and the group delete cascade to it). A replace that cleared
			// groups removes the rows whose group is gone, so a re-run of a
			// WPML, Polylang or TranslatePress migration cannot join a
			// group by a recorded id.
			if ( array_key_exists( 'translation_groups', $wipe_plan ) ) {
				$this->drop_stale_source_map();
			}

			// Merge mode strands groups: translation_groups have no natural
			// key, so each re-inserts as a NEW row while its links skip on
			// the object_lang (type, object_id, language_id) unique key -
			// leaving the fresh group with nothing pointing at it. (Replace
			// mode truncates first, so it never strands.) Run ONCE here, after
			// the whole loop - especially after translation_links - so we
			// never sweep freshly-imported groups before their links have
			// landed.
			if ( ! $replace ) {
				$this->sweep_orphan_groups();
			}
		}

		if ( '' !== $this->default_kept ) {
			// Nothing has written a note yet: this is the report's first.
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

		// Language rows written with $wpdb->insert() fire no language hook, so
		// the rewrite rules, whose pattern lists the active language codes,
		// are marked for the rebuild every language change asks for
		// (CacheInvalidator::on_language_changed() does the same).
		if ( $this->languages_changed ) {
			update_option( 'perflocale_flush_rules', 1, false );
			delete_transient( 'perflocale_rewrites_verified' );
		}

		// Replace applies the file's configuration once its tables are in
		// (see apply_configuration() for why not before).
		if ( $replace ) {
			$this->apply_configuration( $data, $result );
		}

		ImportLock::heartbeat();

		// The options and meta of the declared sections (ExportItems), each
		// written on its own once the tables and the configuration are in.
		// The shared row lists carry the add-on rows of a file from before
		// add-on parts; those belong to the shared Add-on data choice.
		$declared = array_values( array_filter( Typed::strs( $data['sections'] ?? [] ) ) );
		$lists    = [];

		if ( ! $addon['shared'] ) {
			$declared = array_values( array_diff( $declared, [ DataRegistry::ADDON_SECTION ] ) );
		}

		foreach ( DataRegistry::ROW_LISTS as $kind => $list ) {
			$lists[ $list ] = DataExporter::row_list_items( $kind, $declared );
		}

		/**
		 * Whether the import's own meta writes start Sync Fields mirroring.
		 *
		 * By default they do not, in Merge and in Replace: each post gets the
		 * value the file holds for it, so the import reproduces the file. With
		 * mirroring on, a value the import writes on one member of a
		 * translation group is copied to its translations as a save would copy
		 * it (when Sync Fields lists the key), so the result can differ from
		 * the file: translations get values the file does not give them, a
		 * Merge can change rows this site already had, and a translation the
		 * source site held back (stale) is overwritten.
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

		try {
			$items = ExportItems::import( $reader, $lists + $addon['members'], $replace, $max_row, self::batch_size( 'options' ) );
		} finally {
			if ( ! $mirror ) {
				\PerfLocale\Translation\ContentSync::resume_meta_write_sync();
			}
		}

		$result['imported'] += $items['imported'];
		$result['skipped']  += $items['skipped'];

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

		// A section nothing on this site reads (a newer version's core
		// section, or an add-on that is not active here) is named in the
		// report rather than dropped silently.
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

		// Top-level sections the reader walked over: neither core nor hooked
		// by an add-on active here.
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

		// Per-section import hook for addon-shipped data. Fires once for
		// every top-level key in the envelope that is NOT a core section,
		// so an addon that registered a `perflocale/export/sections` filter
		// can hook the matching action to restore its own rows. Uses the same
		// reserved-key list the exporter enforces so the two can't drift
		// (addon_settings + disabled_addons included - they are handled above
		// and must never be dispatched as an addon section).
		foreach ( $data as $section_name => $section_data ) {
			if ( '' === $section_name || in_array( $section_name, DataExporter::RESERVED_SECTION_KEYS, true ) ) {
				continue;
			}

			if ( ! has_action( 'perflocale/import/section/' . $section_name ) ) {
				$unread[] = $section_name;
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
			 * The action name is dynamic - `<name>` is the section key the
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

		// Reap the links left pointing at groups that no longer exist. Two
		// producers, and the gate has to cover BOTH.
		//
		// REPLACE: a strings bundle wipes the type='string' slice of
		// translation_groups without owning translation_links; and a replace
		// that cleared the link tables (Translation Links) runs both passes
		// too, so a string whose group or link it touched is served again.
		//
		// MERGE: sweep_orphan_groups() above deletes every type='string'
		// group that owns no `strings` row, and it runs on EVERY merge
		// import whatever sections the envelope carried, because the sweep
		// itself is unconditional. A merge of the `translations` section
		// alone therefore strands string links while carrying no strings at
		// all - the shape the old `$carries_strings`-only gate never looked
		// at, leaving those links to block the UNIQUE object_lang slot the
		// next repair needs.
		//
		// Runs BEFORE the repair below, which cannot re-link a string while a
		// dangling row still holds its slot in that key. A replace-mode
		// import that landed no strings ran no sweep and can have stranded
		// nothing, so it still pays nothing.
		if ( $carries_strings || ! $replace || $this->replaced_link_tables ) {
			$this->reap_orphan_string_links();
		}

		// String-graph repair. Two callers, one idempotent pass:
		//
		// MERGE: when the target site ALREADY had a string row (same
		// original_hash), the imported string maps onto the EXISTING row -
		// which keeps its own group_id - while the imported translation_links
		// rows were rewritten onto the freshly-inserted (now unowned) group.
		// The translation value lands but its 'translated' link points at the
		// wrong group, so it is never served.
		//
		// REPLACE: the strings section carries `translation_groups` but NOT
		// the per-language `translation_links` (see DataExporter::sections()),
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
		// only - a replace of other sections skips the pass entirely, while
		// merge mode still runs it unconditionally exactly as it did before
		// the strings section started carrying groups.
		if ( ! $replace || $carries_strings || $this->replaced_link_tables ) {
			$cache = \PerfLocale\Plugin::get_instance()->has( 'cache' )
				? \PerfLocale\Plugin::get_instance()->get( 'cache' )
				: null;

			if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
				( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->repair_orphaned_translations();
			}
		}

		// Flush all caches. Routed through the migration helper (which itself
		// calls CacheManager::flush_all) so the autoloaded eager-link maps for
		// ALL object types are purged, not just L2 - a plain flush_all() leaves
		// the pre-import eager maps in wp_options and long-lived CLI/cron
		// workers keep serving stale group memos. Runs here (inside import(),
		// while switch_to_blog() is active for network-import) so the
		// delete_option() calls hit each TARGET blog's options table. After
		// the reap and the repair, so in files mode the translation files are
		// generated once, from the repaired links.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		// Menu and pattern references in translated templates follow the links
		// this restore wrote: once, for the posts it linked.
		if ( [] !== $this->linked_posts ) {
			\PerfLocale\Translation\TemplateBlockRefs::after_restore( array_keys( $this->linked_posts ) );
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
		 *     imported:     int,
		 *     skipped:      int,
		 *     errors:       array<int,string>,
		 *     sanitized:    int,
		 *     not_applied?: array<int,string>,
		 *     notice?:      string,
		 * } $result The DataImporter result. `sanitized` counts stored
		 *           string translation, language and translated-slug rows
		 *           the sanitizers changed because the import ran at the
		 *           trust level of a user without `unfiltered_html` (always
		 *           0 for WP-CLI). `not_applied` and `notice` name the
		 *           configuration a merge import left unapplied.
		 * @param string $file_path Absolute path to the imported file.
		 * @param bool   $replace   Whether the import ran in replace mode.
		 */
		do_action( 'perflocale/import/completed', $result, $file_path, $replace );

		return $result;
	}

	/**
	 * Apply the file's configuration: settings, add-on settings, the
	 * disabled-add-on list and the role grants. Replace mode only.
	 *
	 * Runs AFTER the table transaction has committed (or, for a file that
	 * carries no tables, straight away). A worker that dies inside the table
	 * transaction therefore leaves configuration and tables both as they
	 * were: the database rolls the tables back when the connection closes,
	 * and nothing of the configuration was written yet. The job's retry runs
	 * the whole import again. Applied before the tables, the file's settings
	 * and roles would stay over the old tables whenever the process died
	 * mid-table: no PHP code runs after a killed worker to put them back.
	 *
	 * @param array<array-key, mixed> $data   Envelope.
	 * @param array<string, mixed>    $result Import result (updated).
	 * @phpstan-param ImportResult $result
	 * @phpstan-param-out ImportResult $result
	 * @return void
	 */
	private function apply_configuration( array $data, array &$result ): void {
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
			// The values are dropped either way - this only stops a supported
			// user flow from being reported as developer error. The secret
			// keys go too: this site keeps its own API keys.
			$incoming = self::applicable_settings( $data['settings'], array_keys( $settings->get_defaults() ) );

			// Settings that describe THIS site rather than the data - its
			// domains, its provider endpoints, its uninstall choice, its
			// paused queue - keep this site's values. A staging export
			// restored on production must not route production's languages
			// to staging's hosts. Each key the file carried with a different
			// value is named in the report.
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

			if ( $incoming !== [] ) {
				$settings->update( $incoming );

				// A provider address the update refused (multisite, its key
				// supplied from outside the database, an importer who is not a
				// super admin) is named in the report, never dropped silently.
				$refused = \PerfLocale\Settings::last_update_refused_addresses();

				if ( [] !== $refused ) {
					$result['refused_addresses'] = $refused;
					$result['address_notice']    = self::refused_address_notice( $refused );
				}
			}

			++$result['imported'];
		}

		// Per-addon settings. Restore as-is - the destination site may
		// not have every addon installed, but that's fine: an absent
		// addon's entry sits dormant in the option until the addon is
		// installed (at which point its own AddonSettings reads pick the
		// value up). Whole-option write so a stale prior import doesn't
		// bleed through. Cache is cleared right after so AddonSettings::all()
		// picks up the fresh option on next read.
		if ( isset( $data['addon_settings'] ) && is_array( $data['addon_settings'] ) ) {
			// The file's entries pass through the exporter's own redaction, so
			// a credential a file carries (one the exporter never writes) is
			// dropped rather than written over this site's. The entry of an
			// add-on not registered on this site is kept on purpose, for the
			// day the add-on is installed; its password-type fields are not
			// known here, so its credential-shaped keys are stripped by name
			// (DataExporter::CREDENTIAL_KEY_PATTERN) and its other keys kept.
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
			/** @var array<string, mixed> $file_addons */
			$file_addons = $data['addon_settings'];
			$incoming    = DataExporter::redact_addon_credentials( $file_addons );
			$current  = (array) get_option( 'perflocale_addon_settings', [] );

			// The exporter REDACTS credential-shaped keys (see
			// DataExporter::CREDENTIAL_KEY_PATTERN) and password-type fields
			// (DataExporter::password_setting_keys()), so an imported addon
			// entry never carries its *_api_key / *_token / passwords. Writing
			// it verbatim would blank the target's live credentials - turning a
			// settings restore into a "now re-enter every API key" incident.
			// Preserve any such key the target still has.
			$merged = $current;

			foreach ( $incoming as $addon => $entry ) {
				if ( ! is_array( $entry ) ) {
					$merged[ $addon ] = $entry;
					continue;
				}

				$existing_entry = ( isset( $current[ $addon ] ) && is_array( $current[ $addon ] ) ) ? $current[ $addon ] : [];
				$password_keys  = $existing_entry !== [] ? DataExporter::password_setting_keys( (string) $addon ) : [];

				// The imported entry is authoritative except for the redacted
				// credential keys restored below.
				$base = $entry;

				foreach ( $existing_entry as $key => $value ) {
					if ( ! array_key_exists( $key, $entry ) && ( preg_match( DataExporter::CREDENTIAL_KEY_PATTERN, (string) $key ) || in_array( (string) $key, $password_keys, true ) ) ) {
						$base[ $key ] = $value;
					}
				}

				$merged[ $addon ] = $base;
			}

			// Authoritative about WHICH addons exist too: drop any the export
			// didn't mention.
			$merged = array_intersect_key( $merged, $incoming );

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

		// Import roles + capabilities snapshot.
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
	 * The settings of a file a Replace import may apply: the keys this build
	 * knows, without the secret ones (API keys). The exporter never writes a
	 * secret, so this site's own value always stays.
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
	 * The settings a Replace import leaves as this site has them.
	 *
	 * @return array<int, string>
	 */
	public static function kept_setting_keys(): array {
		$defaults = [ 'language_domains', 'mt_libre_url', 'mt_agency_url', 'delete_data_on_uninstall', 'background_paused' ];

		/**
		 * Filters the settings a Replace import keeps as this site has them.
		 *
		 * These settings describe the site rather than its translation data:
		 * its per-language domains, its machine-translation endpoints, its
		 * uninstall choice and its paused background queue. A Replace import
		 * applies the file's other settings and leaves these as they are; the
		 * import report names each one whose value in the file was different.
		 * Return an empty array to apply every setting from the file. API
		 * keys are never in an export file, so they are always kept.
		 *
		 * @hook  perflocale/import/keep_target_settings
		 * @since 1.0.7.3
		 *
		 * @param array<int, string> $keys Setting keys. Default: language_domains,
		 *                                 mt_libre_url, mt_agency_url,
		 *                                 delete_data_on_uninstall, background_paused.
		 * @return array<int, string>
		 */
		$keys = apply_filters( 'perflocale/import/keep_target_settings', $defaults );

		return self::setting_keys_from( $keys, $defaults );
	}

	/**
	 * The setting keys a perflocale/import/keep_target_settings callback
	 * returned: its non-empty strings, or the defaults when it did not
	 * return an array.
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
	 * The sentence an import report shows for the settings a Replace import
	 * kept as this site has them.
	 *
	 * @param array<int, string> $kept Setting keys.
	 * @return string
	 */
	public static function kept_settings_notice( array $kept ): string {
		$labels = [
			'language_domains'         => __( 'Per-language domain', 'perflocale' ),
			'mt_libre_url'             => __( 'LibreTranslate URL', 'perflocale' ),
			'mt_agency_url'            => __( 'Agency Endpoint URL', 'perflocale' ),
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
	 * The sentence an import report shows for the provider address settings
	 * the settings update refused.
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
	 * The configuration keys and members of `data` that no section the file
	 * declares carries, by DataRegistry::section_transports(). A file without
	 * `sections` keeps everything, as before section lists existed; one whose
	 * `sections` is not a list declares nothing.
	 *
	 * An add-on part's row lists belong to the Add-on data section; a part
	 * with its own checkbox also counts as declared when the file names it in
	 * `addon_groups`, which the exporter writes for every group part it
	 * exports. A member this version does not know (a newer version's table)
	 * is left to the unread report.
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
	 * How many failed statements wpdb holds in the global $EZSQL_ERROR.
	 *
	 * @return int
	 */
	private static function db_error_count(): int {
		return isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] ) ? count( $GLOBALS['EZSQL_ERROR'] ) : 0;
	}

	/**
	 * Drop the duplicate-key failures added to $EZSQL_ERROR after the first
	 * $from entries: the skips a Merge expects, each about 1 KB, which would
	 * otherwise grow with the file until the request ends. Any other failure
	 * is kept, in order.
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
	 * Build the table wipe plan a replace-mode import should apply.
	 *
	 * Reads the envelope's `sections` field (an array of section keys
	 * present in the export) and resolves it through DataExporter::sections()
	 * to a `table short-name => type scope` map. A null scope means the whole
	 * table; a string means only the rows whose polymorphic `type` column
	 * holds that value (see DataExporter::sections()' `type_scope`). An absent
	 * or empty `sections` list wipes nothing - that's the safer default for a
	 * malformed envelope than truncating every PerfLocale table.
	 *
	 * Two rules keep a partial bundle from over-reaching:
	 *
	 * - The WIDEST claim wins. A full bundle selects both `translations`
	 *   (which owns all of `translation_groups`) and `strings` (which owns
	 *   only the type='string' slice), so the table is wiped whole - exactly
	 *   what happened before scoping existed.
	 * - A scoped slice is wiped only when the envelope actually CARRIES rows
	 *   for that table. Unscoped tables are wiped when the envelope carries
	 *   them as a row list, even an empty one, otherwise post-export rows
	 *   leak through the replace; a table the envelope does not carry at all
	 *   is kept, as the exporter writes a list for every table of every
	 *   section it declares. A scope exists precisely because the table is
	 *   SHARED, and a bundle written before the scope was introduced names
	 *   the section without carrying the slice. Wiping on its behalf would
	 *   delete the target's groups with nothing left to restore them.
	 * - The string slice belongs to String Translations. A file that declares
	 *   Translation Links without String Translations owns the post and term
	 *   rows of `translation_groups` and `translation_links` only: those two
	 *   tables get the scope `!string` (every row whose type is not
	 *   `string`), and the file's own string rows in them are not imported.
	 *   The strings, their groups and their links made after the export
	 *   therefore stay as they are.
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

				// A row list is a count (the reader's summary) or the decoded
				// rows; an absent table is not carried.
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

		// Walking the import order keeps dependency order
		// (parents before children) is preserved even if `sections` arrived
		// in a weird order.
		$plan = [];
		foreach ( DataRegistry::import_tables() as $table_name ) {
			if ( array_key_exists( $table_name, $scopes ) ) {
				$plan[ $table_name ] = $scopes[ $table_name ];
			}
		}

		return $plan;
	}

	/**
	 * What the import does with the add-on data a file carries.
	 *
	 * Each add-on part travels as its own row lists
	 * (`addon_data/<part>/<row list>`, DataRegistry::ADDON_MEMBER_PREFIX). A
	 * part is read when it is chosen (the shared `addon_data` choice for a
	 * part without a group, its own part key for a group) and this site
	 * declares the same part: its rows are matched against that part's
	 * entries only, so Merge and Replace touch nothing outside it. A part
	 * this site does not declare is read against the chosen parts of the
	 * same add-on when it declares any (its groups changed since the
	 * export); otherwise (its add-on is not active, or declares no such
	 * data) it is named in `inactive`, chosen or not. A chosen-out part
	 * that holds rows is named in `unchosen`.
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

			// Not declared here, while its add-on declares other parts (it
			// added, removed or renamed a group since the export): the rows are
			// read against the entries of that add-on's parts chosen on this
			// site. Each row is matched by name, so nothing outside the add-on's
			// own entries is written.
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

				// Its add-on's parts are all chosen out: named as not chosen (the
				// shared part by the "Add-on data" line above).
				if ( $declared ) {
					if ( $rows ) {
						$out['unchosen'] = array_merge( $out['unchosen'], $unticked );
					}

					continue;
				}

				// Its add-on declares nothing here: the import screen has no
				// checkbox for it, so it is named as not active whatever was
				// chosen.
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
	 * Does this envelope carry `strings` rows?
	 *
	 * Gates the REPLACE half of both post-import string passes - the
	 * orphan-link reap and the string-graph repair - so a replace bundle
	 * that landed no strings doesn't pay for work that has nothing to fix.
	 * Merge mode runs both unconditionally: its sweep_orphan_groups() call
	 * is itself unconditional, so any merge import can strand string links
	 * whether or not the envelope carried strings.
	 *
	 * @param array<string,mixed> $data Decoded envelope.
	 * @return bool True when the envelope's `data` holds at least one strings row.
	 */
	private static function carries_strings( array $data ): bool {
		return self::has_rows( $data, 'strings' );
	}

	/**
	 * Does this envelope's `data` carry at least one row for a table?
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
	 * Does this envelope carry rows keyed by a post, term or string ID?
	 *
	 * Every `translation_links` row counts, `type='string'` included: a string
	 * link's `object_id` is the exporting site's `strings.id`. A merge that
	 * carries the strings maps it through their `original_hash`, but a file
	 * with links and no strings can only be read with the exporting site's
	 * ids. `slug_translations` and `content_hashes` carry the same raw
	 * `object_id`, as do the post, term and order meta lists and the add-on
	 * options (form ids) of ExportItems.
	 *
	 * The `strings` and `string_translations` sections are not listed: strings
	 * match on `original_hash`, so they stay portable between sites.
	 *
	 * Pure over its argument - no DB access, no memo (the network importer
	 * reuses one instance across switch_to_blog()).
	 *
	 * An add-on part's row lists count when the import reads them: every
	 * part travels in the Add-on data section, which holds ids.
	 *
	 * @param array<array-key, mixed> $data    Decoded envelope.
	 * @param array<int, string>      $members The add-on part row lists the import reads.
	 * @return bool
	 */
	private static function carries_object_ids( array $data, array $members = [] ): bool {
		$sections = Typed::strs( $data['sections'] ?? [] );

		foreach ( $members as $member ) {
			if ( self::has_rows( $data, $member ) ) {
				return true;
			}
		}

		foreach ( DataRegistry::object_id_tables() as $table ) {
			if ( self::has_rows( $data, $table ) ) {
				return true;
			}
		}

		// The meta row lists name posts, terms and orders; the option rows of
		// an id-keyed section (Add-on data) name forms.
		foreach ( DataRegistry::ROW_LISTS as $kind => $list ) {
			if ( 'option' !== $kind && self::has_rows( $data, $list ) ) {
				return true;
			}
		}

		$id_keyed = array_values( array_intersect( $sections, DataRegistry::id_keyed_sections() ) );

		return [] !== DataExporter::row_list_items( 'option', $id_keyed ) && self::has_rows( $data, DataRegistry::ROW_LISTS['option'] );
	}

	/**
	 * Does this envelope carry rows that name a language by its numeric id?
	 *
	 * These are the tables whose `language_id` the registry's FK map points at `languages`. The map is
	 * consulted only for ids captured while the bundle's own `languages`
	 * section was imported, and only in merge mode, so without that section
	 * the exporting site's ids are written verbatim.
	 *
	 * @param array<array-key, mixed> $data Decoded envelope.
	 * @return bool
	 */
	private static function carries_language_refs( array $data ): bool {
		foreach ( DataRegistry::language_ref_tables() as $table ) {
			if ( self::has_rows( $data, $table ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does the address recorded in a bundle name THIS site?
	 *
	 * The COMPARISON both import paths share - the admin/job gate in
	 * {@see self::import()} and the per-slice guard in
	 * `wp perflocale network-import` - so neither can grow its own idea of
	 * what "the same site" means. The null POLICY is the caller's, and each
	 * one spells it out: null covers two different shapes, and only one of
	 * them ("no address recorded at all") is the legacy envelope both paths
	 * wave through.
	 *
	 * Scheme-agnostic, because an http → https move does not change which
	 * site the IDs belong to. Port-sensitive, because two local installs on
	 * the same host and different ports are different sites.
	 *
	 * Compared against the raw `home` option, which is what the exporter
	 * records, AND against `home_url()`: bundles written before the exporter
	 * stopped passing the value through the `home_url` filter carry the
	 * filtered form, which in domain or subdomain mode is a different host.
	 *
	 * A matching address is not proof the IDs match - a site rebuilt at the
	 * same address keeps the address and loses the IDs - and the value comes
	 * from the file itself. This catches an honest mistake.
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
	 * Normalize a site address down to the identity the gate compares.
	 *
	 * Lowercase host, the port unless it is the scheme's default, and the
	 * path without a trailing slash. The scheme, query and fragment are
	 * dropped.
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

		// A bundle that spells out the scheme's default port and a site that
		// leaves it implicit are the same address.
		if ( 80 === $port || 443 === $port ) {
			$port = 0;
		}

		return strtolower( (string) $parts['host'] )
			. ( $port > 0 ? ':' . $port : '' )
			. untrailingslashit( (string) ( $parts['path'] ?? '' ) );
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
	 * The default `max_rows_per_table`, for this process.
	 *
	 * The file is read and written in pieces, but a Merge keeps a little
	 * state for each row until the import ends (RETAINED_BYTES_PER_ROW). So
	 * the default is the smaller of two ceilings, and never below the 500,000
	 * rows of earlier versions (LEGACY_MAX_ROWS):
	 * - size: the most rows the largest file the import screen accepts
	 *   (`perflocale/import/max_file_bytes`) can hold at MIN_ROW_BYTES a row;
	 * - memory: the rows whose retained state fits in RETAINED_MEMORY_SHARE
	 *   percent of what PHP's memory_limit leaves free when the check runs.
	 *   An unlimited memory_limit (-1) leaves the size ceiling alone.
	 *
	 * A file over it is refused before anything is written. A
	 * `max_rows_per_table` from perflocale/import/quality_limits replaces it.
	 *
	 * @return int
	 */
	private static function default_max_rows(): int {
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;

		return self::max_rows_for( intdiv( max( 0, AdminController::import_max_bytes() ), self::MIN_ROW_BYTES ), $limit, memory_get_usage() );
	}

	/**
	 * The default row ceiling for a size ceiling, a memory limit and the
	 * memory in use (see default_max_rows()).
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

		// The share of the free bytes from quotient and remainder, so no step
		// exceeds the free bytes (a product of them and the share would pass
		// PHP_INT_MAX on 32-bit PHP and become a float).
		$free        = max( 0, $limit - $used );
		$memory_rows = intdiv( intdiv( $free, 100 ) * self::RETAINED_MEMORY_SHARE + intdiv( $free % 100 * self::RETAINED_MEMORY_SHARE, 100 ), self::RETAINED_BYTES_PER_ROW );

		return min( $size_rows, max( self::LEGACY_MAX_ROWS, $memory_rows ) );
	}

	/**
	 * Data-quality validation: reject corrupt or hostile payloads BEFORE
	 * any DB write.
	 *
	 * Catches what json_decode() happily accepts but the importer would
	 * persist verbatim: invalid UTF-8 byte sequences (MySQL truncates the
	 * value at the bad byte - silent data loss), null bytes (classic
	 * injection / downstream-corruption vector), cardinality bombs
	 * (500k-language files exhausting memory or wedging the languages
	 * UI), oversized single values, and malformed row shapes.
	 *
	 * Pure function - no DB access, no WP state beyond __() - so it's
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
	 * The data-quality gate over a file read in pieces: the same checks and
	 * messages as {@see self::find_data_quality_issues()}, with every row
	 * decoded once and dropped again.
	 *
	 * While the rows go by, the ID-keyed rows of $sources are watched:
	 * $presence['rows'] is set when one names a post, term or order, and
	 * $presence['fp'] when one carries a fingerprint.
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
	 * Pass rows through, noting whether the ID-keyed ones carry a fingerprint
	 * (see stream_quality_issues()).
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
	 * Whether a file's `version` is IDENTITY_SINCE or later (its leading
	 * numbers; a version that does not start with a number is older).
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
	 * The row lists whose rows can carry an object fingerprint: list =>
	 * [ the column naming the object's kind, or '' ; the fixed kind, or '' ].
	 *
	 * @param array<string, mixed> $members The add-on part row lists the import reads (addon_plan()).
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function fingerprint_sources( array $members ): array {
		$sources = [];

		foreach ( DataExporter::fingerprint_columns() as $table => $column ) {
			$sources[ $table ] = [ $column, '' ];
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

		// Cardinality gates first - cheap, and they catch the bomb case
		// before the per-value scan walks half a million rows.
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

		// Per-value scan: every string in every table row, then in the
		// settings / addon_settings / roles sections, is checked for invalid
		// UTF-8, null bytes and oversize. Every row is read here, so a row
		// that is not valid JSON stops the import before its first write.
		// Row shape is collected on the way and reported after the values.
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

					// A replace keeps each row's id, and an id near the top of
					// the column's range moves the table's next id there: every
					// later insert would then fail. Real ids stay far below 2^53.
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
	 * Check one value, and every string inside it, for a null byte, invalid
	 * UTF-8 and the size limit.
	 *
	 * Budget-passing recursion: each call returns the errors it found,
	 * capped at $budget, so the cap is enforced at every level.
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

			// preg_match with the `u` modifier fails (returns false) on
			// invalid UTF-8 - the fastest validity probe PHP offers
			// without mbstring.
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
	 * Restore the role + capability snapshot.
	 *
	 * Recreates the Translator role with its imported capability map, then
	 * re-applies the PerfLocale-prefixed caps the file grants to the roles
	 * an export carries (DataExporter::grant_roles(): administrator, editor,
	 * the `perflocale/roles/cap_roles` roles and the Shop Manager); grants to
	 * any other role slug are ignored. Sets `perflocale_caps_version` to the imported value, at most
	 * TranslatorRole::CAPS_VERSION, so `TranslatorRole::install_caps()` sees
	 * a matching version on the next admin_init and does not immediately
	 * rewrite what was just restored, and a file can never mark a capability
	 * install as done that this version of the plugin has not run.
	 *
	 * Foreign caps (anything not prefixed with `perflocale_`) are filtered
	 * out so an export crafted maliciously can't grant arbitrary WP caps.
	 *
	 * @param array<string,mixed> $roles Snapshot from DataExporter::snapshot_roles().
	 * @return array<int, string> Translator capabilities in the file that were not restored (outside the allowlist).
	 */
	private function restore_roles( array $roles ): array {
		$dropped = [];

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
			update_option( 'perflocale_caps_version', min( (int) $roles['caps_version'], TranslatorRole::CAPS_VERSION ), false );
		}

		return array_values( array_filter( $dropped ) );
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
	 * Log a database error's own text to the PHP error log, when WP_DEBUG_LOG is on.
	 *
	 * That text can carry table names with their prefix and the values of the
	 * failed row. Import results and failed-job rows are shown to every user
	 * with Jobs access, so the messages stored there say only that a database
	 * error happened; the detail is here.
	 *
	 * @param string $where What was running, for the log line.
	 * @param string $error The database error.
	 * @return void
	 */
	private static function log_db_error( string $where, string $error ): void {
		if ( $error === '' || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic, only when WP_DEBUG_LOG is on.
		error_log( 'PerfLocale DataImporter: database error while ' . $where . ' - ' . $error );
	}

	/**
	 * The unique keys a table holds now, read in pages of 5,000 rows, as a set
	 * of row_keys() entries.
	 *
	 * Used for the tables {@see self::KEY_LOOKUP} does not list, whose text
	 * keys compare the way the database's indexes compare them; their size
	 * bounds the set.
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
	 * The unique keys that the rows of one piece would take and that the
	 * table already holds, as a set of row_keys() entries.
	 *
	 * One query per unique key, on the column {@see self::KEY_LOOKUP} names,
	 * with the piece's values in the IN list. Rows written earlier in this
	 * import are found too: they are in the table, on the same connection.
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

			// Columns, table and key column are bound with %i; the values one
			// placeholder each: %d for an integer key column, %s otherwise.
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
	 * A row's unique keys as set entries ("key index|normalised values").
	 * Values compare as the indexes compare them on SQLite (NOCASE) and, for
	 * ASCII, on MySQL: ASCII letters case-insensitive, trailing spaces
	 * ignored, slug by its first 191 characters. strtolower(), not
	 * mb_strtolower(): mbstring is optional, and SQLite folds ASCII only. A
	 * key that MySQL's collation folds further (non-ASCII case or accents) is
	 * not found here; its insert fails and the import stops and rolls back.
	 * A key with a missing or NULL column is left out (NULL never collides).
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
	 * Import a single table's data, a piece at a time.
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
	 *         `skipped` counts every row that did not insert; `failed` counts
	 *         the subset that failed for a reason OTHER than a duplicate-key
	 *         collision. The replace-mode abort gate in import() reads
	 *         `failed` to tell a systematic insert failure from the benign
	 *         duplicate-skips a scoped wipe leaves behind.
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

		// Rows this table leaves out on purpose (counted by reason) are not
		// failed inserts: the zero-insert gate in import_from() discounts them.
		$reasons_before = array_sum( $this->skip_reasons );

		// Merge-mode is expected to see duplicate-unique-key collisions (e.g.
		// `languages.slug` already holding "en"). Those are INTENDED skips -
		// merge adds, it doesn't overwrite. Without this suppression, wpdb
		// would echo raw SQL errors into the admin UI redirect page.
		// Truncation is handled by the caller ({@see self::import()}) so
		// replace covers every imported table, including tables that happened
		// to be empty at export time. This method is row-insert only.
		$was_suppressing = $wpdb->suppress_errors( true );

		// wpdb keeps every failed statement in the global $EZSQL_ERROR, the
		// suppressed ones too: about 1 KB for each duplicate a Merge skips,
		// held until the request ends. Those entries are dropped after each
		// piece (see forget_duplicate_errors()).
		$errors_before = self::db_error_count();

		try {
			// Pre-fetch the natural-key map for parent tables so duplicate-skip
			// collisions still populate $id_maps with the existing target id.
			// Without this, a re-import on a target that already has the same
			// languages would leave $id_maps['languages'] empty and child FKs
			// would point at non-existent ids.
			$natural_key_lookup = $this->build_natural_key_lookup( $table_name );

			// One schema fetch for the whole table (identical every row), reused
			// for format binding and for filtering unknown columns.
			$col_formats = $this->column_formats( $full_table );

			// Replace mode writes inside a transaction, where no statement may
			// fail: on the SQLite Database Integration a failed statement ends
			// the transaction (rolling back the wipe and every earlier row) and
			// the statements after it commit one by one. A row whose unique key
			// is already taken - by a surviving row of a scoped wipe or by an
			// earlier row of the file - is therefore skipped before it is
			// written, as the database skipped it on MySQL; any statement that
			// still fails is a genuine error and aborts the import at once.
			// Tables with text keys are read whole once; the others are looked
			// up per piece, so the set never grows with the file.
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

					// A replace writes inside its transaction, where the lock
					// is not touched.
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

		foreach ( $piece as $row ) {
			if ( ! is_array( $row ) ) {
				++$result['skipped'];
				continue;
			}

			$old_id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$file_row = $row;

			// Menu and pattern references in translated templates follow the
			// links this restore wrote, once the import is done.
			if ( 'translation_links' === $table_name && 'post' === ( $row['type'] ?? '' ) && isset( $row['object_id'] ) && is_numeric( $row['object_id'] ) && (int) $row['object_id'] > 0 ) {
				$this->linked_posts[ (int) $row['object_id'] ] = true;
			}

			// Rewrite FK columns from old parent id to the new one captured
			// during the parent table's pass. If a parent id wasn't mapped the
			// row keeps its export value, which on another site means a
			// different parent or none at all. That is reached by an ordinary
			// operator choice: the export form lets any section be unticked,
			// so a bundle can carry translations, strings or slugs without the
			// `languages` section their language_id refers to. Replace mode
			// (the `! $replace` below) never rewrites at all - it restores the
			// export's ids by design. The site-identity gate in import()
			// refuses such a bundle from another site unless the operator
			// confirms this site is a copy of it.
			if ( ! $replace && [] !== $fk_refs ) {
				foreach ( $fk_refs as $col => $parent_table ) {
					if ( isset( $row[ $col ], $this->id_maps[ $parent_table ][ (int) $row[ $col ] ] ) ) {
						$row[ $col ] = $this->id_maps[ $parent_table ][ (int) $row[ $col ] ];
					}
				}
			}

			// A link of a file group that was written under a new id (a kept
			// string group holds the file's id) goes into that group.
			$file_group = $replace && 'translation_links' === $table_name && [] !== $this->moved_groups ? Typed::int( $row['group_id'] ?? null ) : 0;

			if ( $file_group > 0 && isset( $this->moved_groups[ $file_group ] ) ) {
				$row['group_id'] = $this->moved_groups[ $file_group ];
			}

			// A row that names a language this site does not have would be a
			// translation in no language: it is left out and counted.
			if ( $names_language && ! isset( $this->language_ids()[ is_numeric( $row['language_id'] ?? null ) ? (int) $row['language_id'] : 0 ] ) ) {
				++$result['skipped'];
				$this->note_skip( 'unknown_language' );
				continue;
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
			// would COMMIT an emptied table - silent total data loss. Filtering
			// to known columns honors the exporter's forward-compat promise and
			// keeps the insert alive.
			$row = array_intersect_key( $row, $col_formats );

			if ( $row === [] ) {
				++$result['skipped'];
				continue;
			}

			// A language is the default or it is not: any value the file
			// holds is stored as 0 or 1, as the importer's own checks read it.
			if ( 'languages' === $table_name && array_key_exists( 'is_default', $row ) ) {
				$row['is_default'] = empty( $row['is_default'] ) ? 0 : 1;
			}

			// Read before the sanitizer runs: the refusal below is for a code
			// that sanitizing emptied, not for one the file left empty.
			$had_code = $sanitize_strings && 'languages' === $table_name && is_string( $row['slug'] ?? null ) && '' !== $row['slug'];

			// Sanitized after the FK rewrite and the column filter, and before
			// the insert, so the duplicate-skip lookup below matches the bytes
			// this row would have stored.
			//
			// A sanitized value is stored even when nothing is left of it, except
			// a language code (see below). Both serving paths treat an empty
			// translation as untranslated, and UrlConverter treats an empty
			// translated slug as no translation, whereas skipping a string
			// translation would leave the bundle's `translated` link with no
			// value behind it, and a table of nothing but skipped rows would trip
			// the replace-mode zero-insert abort in import().
			$row_sanitized = $sanitize_strings && match ( $table_name ) {
				'string_translations' => self::sanitize_string_translation_row( $row ),
				'languages'           => self::sanitize_language_row( $row ),
				'slug_translations'   => self::sanitize_slug_translation_row( $row ),
				default               => false,
			};

			// A language code that sanitizing emptied is refused. The code is
			// the routing key and this table's natural key; storing '' would
			// turn a language the file names by code into one no URL can reach,
			// which LanguageRepository::insert() refuses too. A code the file
			// itself leaves empty is stored, as on the trusted path: update()
			// lets a language hold '' (the Edit Language form can blank it), so
			// a site's own export can carry one, and refusing it would roll back
			// a replace-mode restore of that site. A code that is not a string
			// is neither sanitized nor refused, so it fares the same on both
			// paths: a missing code, an array or `false` reaches the column as
			// '' (wpdb binds a non-scalar or `false` as '', and under
			// WordPress's SQL modes MySQL fills a missing NOT NULL column with
			// ''); a number or `true` is bound as text ('12', '1.5', '1'),
			// which cannot carry markup; and null is sent as NULL, which the
			// NOT NULL column rejects, so that row counts as failed and a
			// replace-mode restore rolls back.
			//
			// Counted as a failure, so a replace-mode restore rolls back rather
			// than committing without it. In merge mode the row's locale still
			// maps its export id onto a language that already has that locale,
			// as the duplicate-skip path below does; without one, the rows that
			// name this language keep the export's language_id, as for any
			// language row that does not insert.
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
						$this->id_maps[ $table_name ][ $old_id ] = $existing_id;
					}
				}

				continue;
			}

			// An import that did not clear the languages table (a merge, or a
			// Replace whose file does not carry the Languages section) never
			// changes this site's default language. The file's default is
			// added as an ordinary language when this site already has one;
			// the languages table keeps one is_default row per site, and
			// LanguageRepository serves the last one it reads, so a second
			// default row would move the site's default, its unprefixed URLs,
			// its x-default hreflang and its fallbacks.
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

		// Replace mode: the keys this piece would take that the table already
		// holds (see import_table()).
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

				// The row's few keys first: array_intersect_key() walks its
				// first argument, and the taken set grows with the table.
				if ( array_intersect_key( $keys, null !== $whole_keys ? $whole_keys : $piece_keys ) !== [] ) {
					// translation_groups has one key, its id. When only the
					// post and term groups were cleared, a taken id is held by
					// a string group this site kept (or by a group this import
					// moved there): the file's group is written under a new id
					// and its links follow it, rather than landing in the
					// string group.
					if ( 'translation_groups' === $table_name && $this->groups_keep_strings && $old_id > 0 && 'string' !== ( $row['type'] ?? null ) ) {
						unset( $row['id'] );
						$moved  = true;
						$new_id = true;
					} elseif (
						// Likewise a post or term link whose id alone is taken
						// (by a kept string link, or by a link this import wrote
						// under a new id) is written under a new id; its group
						// and object keys are still checked.
						'translation_links' === $table_name && $this->links_keep_strings && $old_id > 0 && 'string' !== ( $row['type'] ?? null )
						&& array_intersect_key( self::row_keys( $table_name, array_diff_key( $row, [ 'id' => true ] ) ), null !== $whole_keys ? $whole_keys : $piece_keys ) === []
					) {
						unset( $row['id'] );
						$new_id = true;
					} else {
						++$result['skipped'];
						continue;
					}
				}
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

			// A merge writes outside a transaction: when a reconnect since the
			// last row ended the connection lock, it is taken again before
			// this row, or the import stops here (ImportLock::heartbeat()).
			if ( ! $replace ) {
				ImportLock::heartbeat();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert( $full_table, $row, $format );

			if ( $new_id && $inserted !== false && $wpdb instanceof \wpdb ) {
				if ( $moved ) {
					$this->moved_groups[ $old_id ] = $wpdb->insert_id;
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
				// Inside the transaction: stop here, so the next statement is
				// the caller's ROLLBACK.
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

				if ( $row_sanitized ) {
					++$result['sanitized'];
				}

				if ( $demoted ) {
					$this->default_kept = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : '';
				} elseif ( 'languages' === $table_name && ! $this->languages_cleared && ! empty( $row['is_default'] ) ) {
					// A site with no default language takes the file's; any
					// further default row in the file is then demoted.
					$this->site_default = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : '';
				}

				// Capture parent's old→new id mapping for child table rewrites.
				if ( ! $replace && $old_id > 0 && $this->is_parent_table( $table_name ) ) {
					$this->id_maps[ $table_name ][ $old_id ] = (int) $wpdb->insert_id;
				}
			} else {
				++$result['skipped'];

				// A duplicate-unique-key collision is an EXPECTED merge skip.
				// Anything else (unknown column, charset rejection, strict-mode
				// zero-date…) is a real failure that must not masquerade as a
				// benign skip - record it so the caller can react.
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

				// Duplicate-skip on a parent: still need the existing id so
				// child FKs resolve. Look it up by natural key (slug/hash).
				// Only a merge import gets here: a replace import stopped above.
				if ( $old_id > 0 && $natural_key_lookup !== null ) {
					$existing_id = $natural_key_lookup( $row );
					if ( $existing_id > 0 ) {
						$this->id_maps[ $table_name ][ $old_id ] = $existing_id;
					}
				}
			}
		}

		// Free memory once per piece.
		//
		// wp_cache_flush_runtime(), NOT wp_cache_flush(). The intent here is
		// only to drop the in-process object-cache array so a large import
		// does not grow without bound. wp_cache_flush() does that too - but
		// on a site with a persistent backend it ALSO empties Redis or
		// Memcached for the whole site, evicting every other plugin's cached
		// data every piece.
		//
		// With no persistent cache the two are identical (core's default
		// implementation simply forwards to wp_cache_flush), so this is
		// strictly a no-op there and a large win where a drop-in is active.
		// WordPress defines wp_cache_flush_runtime() since 6.1, below the
		// plugin's minimum.
		\PerfLocale\Cache\CacheManager::flush_runtime();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Sanitize one imported `string_translations` row in place.
	 *
	 * `translation` goes through Helper::sanitize_untrusted_string_translation().
	 * `extra_forms` is a JSON list of plural forms 2..N that every reader
	 * decodes (StringTranslation::preload_translations(),
	 * StringTranslationRepository::get_extra_forms_map()), so the check runs on
	 * the decoded strings: each string at any depth is sanitized and the value
	 * re-encoded, positions and empty forms intact. A value that does not
	 * decode to an array is ignored by every reader; it is stored as NULL, the
	 * column's "no extra forms" state, only when it contains a `<`, so no
	 * markup is kept that a later change to a reader could expose. Anything
	 * the sanitizer leaves unchanged keeps its original bytes.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return bool True when the row was changed.
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
	 * Sanitize one imported `languages` row in place.
	 *
	 * Runs the row's string cells through LanguageRepository::sanitize_data(),
	 * the rules the Languages screen and the REST route store with, so each
	 * name, format and code is sanitized exactly as those writers sanitize it.
	 * The code's shape check (LanguageRepository::SLUG_PATTERN, which insert()
	 * applies to every code and update() to a changed one) is not applied, so
	 * the import can store a code insert() would refuse: a restore can carry a
	 * code created before insert() enforced the shape, or the '' that update()
	 * accepts. Sanitizing still limits a code to what sanitize_key() keeps.
	 * Only string cells are passed and written back: a JSON number, boolean or
	 * null cannot carry markup, and the numeric columns are bound as integers
	 * anyway.
	 *
	 * The result can be longer than the input: an unclosed `<` becomes `&lt;`,
	 * so a value can outgrow its column. wpdb's insert refuses such a
	 * value before the query, in every SQL mode, and the row counts as failed:
	 * merge mode reports it and replace mode rolls back. The Languages screen
	 * writes through the same wpdb call and refuses the same value.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return bool True when the row was changed.
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
	 * Sanitize one imported `slug_translations` row in place.
	 *
	 * The same sanitizers SlugTranslationRepository::insert() applies:
	 * sanitize_title() on the slug, which every slug writer runs, and
	 * sanitize_key() on the object type. A stored slug becomes part of a
	 * permalink, so it holds only what core's sanitize_title_with_dashes()
	 * keeps: lowercase letters, digits, `%`, `_` and `-`. `object_subtype` is
	 * left as it is, as the repository leaves it, because its readers match
	 * the raw bytes.
	 *
	 * A slug that sanitizes to '' is stored; UrlConverter reads '' as no
	 * translation. A second such row for the same language, object type and
	 * subtype collides on the `slug_lookup` key and is skipped as a duplicate.
	 *
	 * @param array<string, mixed> $row Row, after the unknown-column filter.
	 * @return bool True when the row was changed.
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
	 * Is this a parent table whose ids are referenced elsewhere?
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
	 * Closure that resolves a row to an existing parent id on the target
	 * site via its natural key (e.g. languages.slug, strings.original_hash,
	 * translation_groups.id).
	 *
	 * Returns null when the table has no natural key we can rely on
	 * (translation_groups uses id alone, so duplicate-skip in merge mode
	 * is impossible - every row gets a fresh id).
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
	 * Merge-mode checks on one piece of translation links, before any is
	 * written.
	 *
	 * String links (type `string`) name a `strings.id`, which a merge
	 * renumbers. Each one is pointed at the string's id on this site through
	 * the strings' id map, and into that string's own group, so the link
	 * keeps its status and source. When this import carries strings and a
	 * link names a string the import did not map, the link is left out: on
	 * this site that id is a different string or none. A file without strings
	 * keeps its ids, as on the site that wrote it (the site-identity gate
	 * covers other sites).
	 *
	 * Post and term links: an object has one language. A link that gives an
	 * object a second language is left out. A link whose group already has a
	 * member on this site (the same object in the same language) joins that
	 * member's group, so a translation the file pairs with an existing one is
	 * paired here too (see plan_link_anchors()).
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

		foreach ( $prepared as $i => $entry ) {
			if ( 'string' !== ( $entry['row']['type'] ?? '' ) ) {
				continue;
			}

			$file_string = is_numeric( $entry['file']['object_id'] ?? null ) ? (int) $entry['file']['object_id'] : 0;

			if ( isset( $this->id_maps['strings'][ $file_string ] ) ) {
				$prepared[ $i ]['row']['object_id'] = $this->id_maps['strings'][ $file_string ];
				$string_ids[]                       = $this->id_maps['strings'][ $file_string ];
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

			if ( ( $this->link_anchor[ $file_group ] ?? 0 ) > 0 ) {
				$prepared[ $i ]['row']['group_id'] = $this->link_anchor[ $file_group ];
			}
		}

		return array_values( $prepared );
	}

	/**
	 * Leave out the rows whose post or term is a different object here.
	 *
	 * A row carries the fingerprint of the object its `object_id` named on
	 * the site that wrote the file (ObjectFingerprint). When this site has no
	 * object under that ID, or one that does not match, the row would attach
	 * a translation, slug or hash to the wrong content: it is left out and
	 * counted. Rows without a fingerprint (files written before fingerprints
	 * existed, string links) are not checked. Both modes; one query per kind
	 * and piece.
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
	 * Merge mode: find, for each translation group in the file, the group on
	 * this site that already holds one of its members.
	 *
	 * A file group is re-created under a new id, and its links that already
	 * exist here (same object, same language) are skipped as duplicates. Its
	 * other members would then land in the new group, apart from the
	 * translation the file pairs them with. A file group whose members that
	 * exist here all sit in ONE group of this site joins that group instead;
	 * members spread over several groups here leave it on its new id. Read
	 * before the links are written, one query per type and piece.
	 *
	 * @param iterable $rows The file's translation links.
	 * @phpstan-param iterable<array-key, mixed> $rows
	 * @return void
	 */
	private function plan_link_anchors( iterable $rows ): void {
		$wpdb = Typed::wpdb();

		$this->link_anchor = [];
		$links_table       = Schema::table( 'translation_links' );
		$size              = self::batch_size( 'translation_links' );
		$piece             = [];

		$plan = function ( array $piece ) use ( $wpdb, $links_table ): void {
			$want = [];

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

				$want[ $type ][ $object ][] = [ $file_group, $this->id_maps['languages'][ $language ] ?? $language ];
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

							if ( ! isset( $this->link_anchor[ $file_group ] ) ) {
								$this->link_anchor[ $file_group ] = $here;
							} elseif ( $this->link_anchor[ $file_group ] !== $here ) {
								$this->link_anchor[ $file_group ] = 0;
							}
						}
					}
				}
			}
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
	 * Count a row whose object changed since the export but was recognised,
	 * by each part that still matched.
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
	 * The sentence for rows whose post or term changed since the export and
	 * was recognised by a part that did not change; '' when there are none.
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
	 * Replace mode: how many of the file's ID-keyed rows carry a fingerprint,
	 * and how many of them name a different object here. Read before any
	 * write; one query per kind and piece.
	 *
	 * @param ImportReader                                     $reader  Indexed reader.
	 * @param int                                              $max_row Largest row read.
	 * @param array<string, array<int, array<int, mixed>>>     $members The add-on part row lists the import reads (addon_plan()).
	 * @return array{0: int, 1: int} Rows checked, rows naming a different object.
	 */
	private function fingerprint_mismatch_share( ImportReader $reader, int $max_row, array $members = [] ): array {
		$checked = 0;
		$changed = 0;

		foreach ( self::fingerprint_sources( $members ) as $table => [ $column, $fixed ] ) {
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
				case 'unknown_language':
					$notes[] = sprintf(
						/* translators: %d: number of rows */
						_n( '%d row was not imported because the language it names does not exist on this site. Import the file with its Languages section to bring the language too.', '%d rows were not imported because the language they name does not exist on this site. Import the file with its Languages section to bring the language too.', $count, 'perflocale' ),
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
	 * How many rows of the languages table are marked default (any non-zero
	 * is_default, as LanguageRepository reads it).
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
	 * Delete migration source-map rows whose translation group is gone.
	 *
	 * @return void
	 */
	private function drop_stale_source_map(): void {
		$wpdb = Typed::wpdb();

		$was = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = $wpdb->prepare(
			'DELETE m FROM %i m
			LEFT JOIN %i g ON g.id = m.group_id
			WHERE g.id IS NULL',
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
	 * Delete string links whose owning translation group is gone.
	 *
	 * The mirror image of {@see self::sweep_orphan_groups()}: that reclaims
	 * groups nothing points at, this reclaims links pointing at nothing.
	 * Both shapes are produced by an import - a replace-mode strings bundle
	 * clears the type='string' slice of `translation_groups` without owning
	 * `translation_links`, and the merge-mode sweep deletes string groups
	 * that no longer own a string while leaving their links behind.
	 *
	 * Nothing else reclaims them: the sweep only deletes groups, and
	 * `repair_orphaned_translations()` never deletes a link - its one DELETE
	 * targets `string_translations` rows whose string is gone, and its two
	 * write paths INSERT a group (plus the owning string's group_id UPDATE)
	 * and INSERT IGNORE a link. Left in place they are
	 * not merely dead weight - they still occupy their slot in the UNIQUE
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
