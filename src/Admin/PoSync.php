<?php
/**
 * Gettext PO export + import for the strings + string_translations tables.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Schema;
use PerfLocale\Database\Repository\StringRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates between PerfLocale's `strings` + `string_translations` rows and
 * standard gettext PO files. One PO carries every string for ONE language at
 * a time — that's the format Poedit, Loco Translate, Crowdin, GlotPress and
 * every CAT tool natively understand.
 *
 * The plugin's `domain` field is preserved in the PO via an extracted
 * comment (`#. domain: my-plugin`) so a round-trip back through this
 * importer correctly re-associates strings with their source domain. WP-core
 * gettext doesn't model "all domains in one file" so we synthesize this
 * with a sentinel comment rather than abusing msgctxt.
 *
 * Line-ending caveat: PO is a Unix-line-ending format by convention. WP's
 * core PO writer drops carriage returns from msgid/msgstr; on import this
 * class normalizes the source-string lookup to match either form, so a
 * source row stored with \r\n still matches an import with \n. For lossless
 * \r preservation, use the JSON DataExporter / DataImporter instead.
 */
final class PoSync {

	/**
	 * Comment prefix used to round-trip the `domain` column through PO files.
	 */
	private const DOMAIN_PREFIX = 'domain: ';

	/**
	 * Separator that folds the text domain into msgctxt on export so entries
	 * that share (context, msgid) across different domains keep distinct PO
	 * keys. Text domains are slugs (never contain this char), so a single-split
	 * on it cleanly recovers the domain; the domain comment stays authoritative
	 * and gates the reverse so foreign PO contexts are never mis-parsed.
	 */
	private const CTX_DOMAIN_SEP = '|';

	/**
	 * Fold a non-default text domain into the PO entry's msgctxt.
	 *
	 * PO entries are keyed by (context, msgid) only — the domain is otherwise
	 * carried in a comment, so two rows sharing (context, msgid) in DIFFERENT
	 * domains would collapse onto one entry and lose a translation. Prefixing
	 * the domain keeps them distinct. Default-domain strings keep their bare
	 * context (or null) so the common case stays clean.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Gettext context.
	 * @return string|null
	 */
	private static function compose_context( string $domain, string $context ): ?string {
		if ( $domain !== '' && $domain !== 'default' ) {
			return $domain . self::CTX_DOMAIN_SEP . $context;
		}

		return $context !== '' ? $context : null;
	}

	/**
	 * Reverse {@see compose_context}. Only strips the prefix when it EXACTLY
	 * matches the domain recovered from the entry's comment, so a foreign PO
	 * whose context legitimately contains the separator is left untouched.
	 *
	 * @param string $raw_context The msgctxt as read from the PO file.
	 * @param string $domain      Domain from the entry's `domain:` comment.
	 * @return string
	 */
	private static function decompose_context( string $raw_context, string $domain ): string {
		if ( $domain === '' || $domain === 'default' ) {
			return $raw_context;
		}

		$prefix = $domain . self::CTX_DOMAIN_SEP;

		if ( str_starts_with( $raw_context, $prefix ) ) {
			return substr( $raw_context, strlen( $prefix ) );
		}

		return $raw_context;
	}

	/**
	 * Export every translation for one language as a PO file.
	 *
	 * @param string $path      Output path.
	 * @param string $lang_slug Target language slug (matches `languages.slug`).
	 * @param string $domain    Optional domain filter; empty = all domains.
	 * @return int|false Bytes written, or false on failure.
	 */
	public static function export_to_file( string $path, string $lang_slug, string $domain = '' ) {
		self::load_pomo();

		global $wpdb;
		$lang = self::resolve_language( $lang_slug );

		if ( ! $lang ) {
			return false;
		}

		$strings      = Schema::table( 'strings' );
		$translations = Schema::table( 'string_translations' );

		$where = '1=1';
		$args  = [ (int) $lang->id ];

		if ( $domain !== '' ) {
			$where .= ' AND s.domain = %s';
			$args[] = $domain;
		}

		// Header block via core's PO class so the output matches what every
		// other gettext consumer produces.
		$po = new \PO();

		$po->set_header( 'Project-Id-Version', 'PerfLocale ' . ( defined( 'PERFLOCALE_VERSION' ) ? PERFLOCALE_VERSION : '0.0' ) );
		$po->set_header( 'Language', (string) $lang->locale );
		$po->set_header( 'MIME-Version', '1.0' );
		$po->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$po->set_header( 'Content-Transfer-Encoding', '8bit' );
		// Per-language CLDR plural header so a translator's editor knows the
		// language's form count. The flat export emits singular/plural as
		// separate entries (universal 2-form interchange); the lossless
		// full-fidelity backup of 3+ plural forms is the JSON DataExporter,
		// which copies the extra_forms column verbatim.
		$po->set_header( 'Plural-Forms', \PerfLocale\Strings\PluralRules::header( (string) $lang->locale ) );
		$po->set_header( 'X-Generator', 'PerfLocale' );

		// Serialise the entries in keyset-paginated batches instead of
		// materialising the whole corpus as objects (all rows as stdClass +
		// every Translation_Entry + core \PO's internal entries array) —
		// the ~1KB-per-row object overhead peaked at 3-4x data size and
		// OOM'd around 100k strings. Each 1000-row batch is discarded after
		// serialising, so peak memory is O(final file size), not O(rows ×
		// object overhead). WHERE s.id > cursor ORDER BY s.id rides the PK
		// (no filesort per batch); entry order is by id instead of the old
		// domain-grouped order, which no consumer depends on (each entry
		// carries its own domain comment).
		$out    = $po->export_headers();
		$cursor = 0;

		/**
		 * Rows fetched per keyset batch during PO export. Trade-off knob:
		 * higher = fewer queries, more peak wpdb result memory per batch;
		 * lower = the reverse. Clamped to a sane floor/ceiling so a filter
		 * bug can't turn the export into per-row queries or one giant fetch.
		 *
		 * @hook perflocale/strings/po_export_batch_size
		 * @param int $batch_size Default 1000.
		 */
		$batch_size = (int) apply_filters( 'perflocale/strings/po_export_batch_size', 1000 );
		$batch_size = max( 100, min( 10000, $batch_size ) );

		do {
			// $cursor / $batch_size are internally-generated integers (the
			// keyset cursor comes from a previous row's id; the batch size is
			// clamped to 100..10000 above), hard int-cast into the query body.
			// Every user-influenceable value — the language id and the domain —
			// stays on a %d/%s placeholder bound through $args, so no
			// request-derived value is ever interpolated into SQL.
			$cursor_sql = (int) $cursor;
			$limit_sql  = (int) $batch_size;

			// Placeholder order: the two %i table names, then the %d language id
			// and whatever %s the $where fragment added (both already in $args),
			// then the keyset cursor and batch size. $where is a structural
			// fragment built from literals above — it never carries a value.
			$q_args = array_merge( [ $strings, $translations ], $args, [ $cursor_sql, $limit_sql ] );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.original, s.context, s.domain, COALESCE(t.translation, '') AS translation
				 FROM %i s
				 LEFT JOIN %i t ON t.string_id = s.id AND t.language_id = %d
				 WHERE {$where} AND s.id > %d
				 ORDER BY s.id
				 LIMIT %d",
					...$q_args
				)
			);
			// phpcs:enable

			foreach ( $rows as $row ) {
				$cursor = (int) $row->id;

				// Carriage returns are normalised out first. Core's PO::poify
				// escapes backslash, double quote and tab but NOT \r, so a raw
				// 0x0D inside a quoted PO string is emitted verbatim and makes
				// the ENTIRE exported file unparseable — a re-import returns
				// zero entries, not one bad entry. Measured: one CR anywhere in
				// one string costs the whole export. Windows line endings in a
				// translation are the ordinary way to acquire one.
				$entry = new \Translation_Entry(
					[
						'singular'           => self::normalise_newlines( (string) $row->original ),
						'context'            => self::compose_context( (string) $row->domain, (string) $row->context ),
						'translations'       => [ self::normalise_newlines( (string) $row->translation ) ],
						'extracted_comments' => self::DOMAIN_PREFIX . (string) $row->domain,
					]
				);

				$serialized = \PO::export_entry( $entry );

				if ( ! is_string( $serialized ) || $serialized === '' ) {
					continue; // Core returns false for an entry it can't serialise.
				}

				// Mirror core PO::export(): headers and entries joined by
				// exactly one blank line.
				$out .= "\n\n" . $serialized;
			}
		} while ( count( $rows ) === $batch_size );

		$wp_filesystem = \PerfLocale\Helper::filesystem();

		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $path, $out, FS_CHMOD_FILE ) ) {
			return false;
		}

		return strlen( $out );
	}

	/**
	 * Make a language's string translations visible on both serving paths.
	 *
	 * Drops the per-language gettext map so database mode rebuilds it, and
	 * regenerates the `.l10n` bundles in files mode. Without it an import only
	 * takes effect after an unrelated admin save or the caches' natural TTL.
	 *
	 * Also run after a rolled-back replace: that pass deleted and re-read rows
	 * before aborting, so anything it cached describes a state the database has
	 * since discarded.
	 *
	 * @param object $lang Language row (needs `id`).
	 * @return void
	 */
	private static function flush_language_caches( object $lang ): void {
		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );

		if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
			return;
		}

		$cache->delete( "all_string_translations_{$lang->id}", 'perflocale_strings' );
		$cache->invalidate_group( 'perflocale_strings' );
		$cache->invalidate_group( 'perflocale_trans' );

		if ( $plugin->get( 'settings' )->get( 'string_translation_mode' ) === 'files' ) {
			( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->generate_all( [ (int) $lang->id ] );
		}
	}

	/**
	 * Import a PO file into the strings + string_translations tables.
	 *
	 * Each PO entry becomes (or matches) a row in `strings`; the row is linked
	 * to the target language in `translation_links` (the join every serving
	 * path drives through — see {@see self::link_string_translation()}), and
	 * only then is the entry's msgstr upserted into `string_translations`. An
	 * entry that cannot be linked is counted in `skipped` with a message in
	 * `errors` naming the row, and writes NOTHING — a stored value with no link
	 * is invisible to every reader and is never retried.
	 *
	 * The originating `domain` is read from the extracted comment that this
	 * exporter emits; entries without that comment fall back to 'default'.
	 *
	 * @param string $path      Input PO path.
	 * @param string $lang_slug Target language slug.
	 * @param bool   $replace   When true, blow away every existing translation
	 *                          for this language before importing (sources are
	 *                          kept; only the per-language `string_translations`
	 *                          rows for $lang_slug are wiped).
	 * @return array{
	 *     imported:int,
	 *     skipped:int,
	 *     errors:array<int,string>,
	 *     inserted:int,
	 *     updated:int,
	 *     unchanged:int,
	 *     no_translation:int,
	 *     fuzzy_skipped:int,
	 *     total_entries:int,
	 * } The first three keys match the standard importer shape; the rest
	 * break the count down so callers can render an accurate notice
	 * instead of an "8 imported, 22001 skipped" lump that hides the fact
	 * most of those 22001 are msgid-only entries (no translation to upsert).
	 *
	 * @throws \Throwable Re-thrown after the replace-mode transaction is rolled
	 *                     back, so an unexpected failure can never leave the
	 *                     wipe committed without its re-import.
	 */
	/**
	 * True when `$bytes` is well-formed UTF-8.
	 *
	 * preg_match with the /u modifier and an empty pattern is the standard test
	 * and needs no extension: PCRE refuses to run a /u pattern against a subject
	 * that is not valid UTF-8, so a false return IS the answer.
	 * mb_check_encoding() would be the obvious choice and is not usable here —
	 * WordPress core does not polyfill it, so it would fatal on a host built
	 * without ext-mbstring.
	 *
	 * @param string $bytes Candidate.
	 */
	/**
	 * Convert CRLF and bare CR to LF.
	 *
	 * @see the export loop for why: core's PO serialiser does not escape \r.
	 *
	 * @param string $text Text to normalise.
	 */
	private static function normalise_newlines( string $text ): string {
		return str_replace( [ "\r\n", "\r" ], "\n", $text );
	}

	private static function is_valid_utf8( string $bytes ): bool {
		return $bytes === '' || preg_match( '//u', $bytes ) === 1;
	}

	public static function import_from_file( string $path, string $lang_slug, bool $replace = false ): array {
		$result = [
			'imported'       => 0,
			'skipped'        => 0,
			'errors'         => [],
			'inserted'       => 0,
			'updated'        => 0,
			'unchanged'      => 0,
			'no_translation' => 0,
			'fuzzy_skipped'  => 0,
			'total_entries'  => 0,
		];

		if ( ! is_readable( $path ) ) {
			$result['errors'][] = __( 'Cannot read PO file.', 'perflocale' );
			return $result;
		}

		self::load_pomo();

		$lang = self::resolve_language( $lang_slug );

		if ( ! $lang ) {
			$result['errors'][] = sprintf(
				/* translators: %s: language slug */
				__( 'Language "%s" is not active on this site.', 'perflocale' ),
				$lang_slug
			);
			return $result;
		}

		$po = new \PO();

		if ( ! $po->import_from_file( $path ) ) {
			$result['errors'][] = __( 'Failed to parse PO file.', 'perflocale' );
			return $result;
		}

		// import_from_file() returns TRUE for a file it could not read a single
		// usable entry out of, and in replace mode the code below then deletes
		// every string translation and every translated link for the target
		// language, commits, and imports nothing: total data loss from a file the
		// operator was told was fine.
		//
		// Three distinct triggers, and an earlier version of this guard caught
		// only the first:
		//
		//  1. A file that parses to zero entries. A header-only PO — what Poedit
		//     writes before anything is translated — does this.
		//  2. A file that is not valid UTF-8. Core's PO::unpoify runs a /u regex,
		//     so a Latin-1 or Windows-1252 save loses the entries that contain a
		//     high byte while the method still reports success.
		//  3. A file whose entries all carry an EMPTY translation, which is what
		//     an ASCII msgid with a Latin-1 msgstr produces: one entry, nothing
		//     in it. Counting entries was not enough.
		//
		// So: reject the payload outright unless it is valid UTF-8, and then
		// require at least one entry that would actually write something.
		if ( ! self::is_valid_utf8( (string) file_get_contents( $path ) ) ) {
			$result['errors'][] = __( 'The PO file is not valid UTF-8. Re-save it as UTF-8 and try again — importing it as-is would discard the entries it cannot read.', 'perflocale' );
			return $result;
		}

		$has_entries = false;

		foreach ( (array) $po->entries as $po_entry ) {
			if ( ! $po_entry instanceof \Translation_Entry || (string) $po_entry->singular === '' ) {
				continue;
			}

			foreach ( (array) $po_entry->translations as $po_translation ) {
				if ( (string) $po_translation !== '' ) {
					$has_entries = true;
					break 2;
				}
			}
		}

		if ( ! $has_entries ) {
			$result['errors'][] = __( 'The PO file contains no translatable entries. If it was exported from another tool, check that it is saved as UTF-8.', 'perflocale' );
			return $result;
		}

		global $wpdb;
		$strings_table = Schema::table( 'strings' );
		$st_table      = Schema::table( 'string_translations' );

		// Resolved ONCE, before anything is written. Every imported value needs
		// a `translation_links` row to be servable at all and that write goes
		// through the repository, whose constructor is typed — so an import
		// that cannot build one has to say so up front rather than fatal on the
		// first entry, or (worse) store thousands of rows nothing can serve.
		$plugin        = \PerfLocale\Plugin::get_instance();
		$cache_service = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		if ( ! $cache_service instanceof \PerfLocale\Cache\CacheManager ) {
			$result['errors'][] = __( 'The PerfLocale cache service is not available, so imported translations could not be linked to a language. Nothing was imported.', 'perflocale' );

			return $result;
		}

		$group_repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache_service );

		if ( $replace ) {
			// Wrap the destructive DELETE + the re-insert loop below in ONE
			// transaction so a mid-import fatal (timeout/OOM) rolls back to the
			// pre-import state instead of leaving a language's translations
			// deleted-but-only-partially-restored. InnoDB auto-rolls-back an
			// open transaction when the connection tears down on a fatal.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
			$wpdb->query( 'START TRANSACTION' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE language_id = %d',
					$st_table,
					(int) $lang->id
				)
			);

			// Drop the 'translated' link markers the wiped rows carried too —
			// leaving them orphans the exact way a stale link points at a
			// now-deleted row (grid shows translated with an empty value).
			// Scoped to type='string' groups: the links table is polymorphic
			// and this language's POST/TERM links must survive a strings-only
			// replace.
			$groups_table_r = Schema::sanitize_table( Schema::table( 'translation_groups' ) );
			$links_table_r  = Schema::sanitize_table( Schema::table( 'translation_links' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE l FROM %i l
					 INNER JOIN %i g ON g.id = l.group_id AND g.type = 'string'
					 WHERE l.language_id = %d",
					$links_table_r,
					$groups_table_r,
					(int) $lang->id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// A replace has already DELETEd every translation for this language a
		// few lines above, inside the open transaction. From here on, ANY exit
		// that is not a clean COMMIT has to ROLLBACK explicitly: relying on the
		// connection tearing down only covers a fatal, and a Throwable caught
		// by an outer handler (REST, CLI, a job worker) would otherwise leave
		// the transaction open for whatever runs next on that connection to
		// commit - publishing the wipe without the re-import.
		try {

			// Iterate entries. PO::$entries is keyed by Translation_Entry::key().
			foreach ( (array) $po->entries as $entry ) {
				if ( ! $entry instanceof \Translation_Entry ) {
					continue;
				}

				$singular = (string) ( $entry->singular ?? '' );

				if ( $singular === '' ) {
					continue; // Empty msgid is the PO header — skip.
				}

				++$result['total_entries'];

				$raw_context = $entry->context !== null ? (string) $entry->context : '';
				$domain      = self::extract_domain( (string) ( $entry->extracted_comments ?? '' ) );

				// A single PO entry is normally one source row, but a standard
				// gettext PLURAL entry (msgid + msgid_plural, msgstr[0..N] in one
				// entry) carries two source forms. PerfLocale's own exporter emits
				// singular/plural as two separate context-tagged entries, so its
				// round-trip never enters the plural branch; but an externally
				// authored PO (e.g. a translate.wordpress.org language pack) packs
				// both forms into one entry. Split it into the two rows the scanner
				// and loader expect so the plural form isn't dropped and the
				// singular lands where _n() looks it up instead of context ''.
				$forms = self::entry_to_forms( $entry, $singular, $raw_context, $domain );

				$has_translation = false;
				foreach ( $forms as $form ) {
					if ( $form['translation'] !== '' ) {
						$has_translation = true;
						break;
					}
				}

				if ( ! $has_translation ) {
					// No translation on any form; nothing to upsert. Dominant case
					// for a fresh PO the translator hasn't filled in, or an export
					// that carried every source string regardless of status. Track
					// it separately so the UI can say "msgid-only" instead of
					// lumping it under generic "skipped".
					++$result['no_translation'];
					++$result['skipped'];
					continue;
				}

				// gettext semantics: a fuzzy translation is msgmerge's guess
				// copied from a DIFFERENT source string — explicitly marked
				// unreliable. Every standard consumer (msgfmt, Poedit, Loco)
				// excludes fuzzy by default; importing it as authoritative would
				// display wrong text. PerfLocale's own exports never carry flags,
				// so this only affects externally-edited PO files.
				if ( in_array( 'fuzzy', (array) $entry->flags, true ) ) {
					++$result['fuzzy_skipped'];
					++$result['skipped'];
					continue;
				}

				foreach ( $forms as $form ) {
					$singular = $form['original'];
					$context  = $form['context'];
					$msgstr   = $form['translation'];

					if ( $msgstr === '' ) {
						continue; // This form has no translation yet; skip it.
					}

					// Find or create the source string row. Primary lookup by the
					// canonical sha256 original_hash — a UNIQUE indexed O(1) probe
					// on the exact (domain, context, original) tuple; the plain
					// (original, context, domain) SELECT scanned the TEXT column.
					// group_id rides along: the serving layer's map query INNER
					// JOINs translation_links, so the upsert below must mark the
					// group translated for this language or the imported text is
					// never served.
					// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$string_row      = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT id, group_id FROM %i WHERE original_hash = %s LIMIT 1',
							$strings_table,
							StringRepository::compute_hash( $domain, $context, $singular )
						)
					);
					$string_id       = $string_row ? (int) $string_row->id : 0;
					$string_group_id = $string_row ? (int) $string_row->group_id : 0;

					// Fallback: gettext PO format normalizes line endings to \n.
					// WP-core's PO::unpoify reads back any \r in the source as \n,
					// so a row stored with \r\n or lone \r won't byte-match the
					// import's $singular without normalization.
					//
					// Two-step normalize on the stored side so the comparison
					// matches gettext semantics exactly:
					// \r\n → \n   (CRLF collapses to LF)
					// lone \r → \n  (any remaining lone CR becomes LF)
					//
					// CHAR(13) / CHAR(10) avoid $wpdb->prepare's backslash
					// escaping of the query body — '\r' / '\n' literals get
					// double-escaped and stop matching.
					//
					// GATED on the msgid containing \n: a stored row that differs
					// only by CR/CRLF normalizes to a form that HAS \n, so its PO
					// msgid must too. Without the gate, this non-sargable
					// REPLACE() scan (it computes REPLACE over every candidate
					// TEXT row) ran once per NEW entry — quadratic on first-time
					// imports of large external PO files.
					if ( $string_id === 0 && $singular !== '' && str_contains( $singular, "\n" ) ) {
						$string_row      = $wpdb->get_row(
							$wpdb->prepare(
								'SELECT id, group_id FROM %i
							 WHERE REPLACE(REPLACE(original, CONCAT(CHAR(13), CHAR(10)), CHAR(10)), CHAR(13), CHAR(10)) = %s
							   AND context = %s
							   AND domain = %s
							 LIMIT 1',
								$strings_table,
								$singular,
								$context,
								$domain
							)
						);
						$string_id       = $string_row ? (int) $string_row->id : 0;
						$string_group_id = $string_row ? (int) $string_row->group_id : 0;
					}

					// True only when THIS iteration minted the group it is about to
					// link under, so it is known to be `type = 'string'` without
					// re-reading it. Every other path — an existing row, or the
					// row recovered after losing the UNIQUE-hash race — carries a
					// group_id from the table that has to be verified.
					$group_verified = false;

					if ( $string_id === 0 ) {
						// Create the source row exactly as the scanner does: a canonical
							// sha256 original_hash + a dedicated type='string' group. The
							// previous hand-rolled md5 hash with group_id 0 produced an
							// orphan that never rendered in DB mode and collided into a
							// DUPLICATE on the next scan (which keys by the sha256 hash).
							$groups_table = Schema::table( 'translation_groups' );

							// insert_id is read only after a CONFIRMED insert: on a
							// failed one it can still hold a PRIOR row's id rather
							// than 0, depending on the mysqli driver — the same
							// reason BulkStringTranslateJob::mint_string_group()
							// refuses to trust it. Trusting it here would file the
							// new string under a group that belongs to something
							// else, which is the exact shape upsert_link() then
							// rewrites through the group_lang unique key.
							$group_inserted = $wpdb->insert( $groups_table, [ 'type' => 'string' ], [ '%s' ] );
							$group_id       = false !== $group_inserted ? (int) $wpdb->insert_id : 0;

						if ( $group_id > 0 ) {
							$inserted = $wpdb->insert(
								$strings_table,
								[
									'original'      => $singular,
									'context'       => $context,
									'domain'        => $domain,
									'original_hash' => StringRepository::compute_hash( $domain, $context, $singular ),
									'group_id'      => $group_id,
									'created_at'    => current_time( 'mysql', true ),
								],
								[ '%s', '%s', '%s', '%s', '%d', '%s' ]
							);

							if ( false !== $inserted && (int) $wpdb->insert_id > 0 ) {
								$string_id       = (int) $wpdb->insert_id;
								$string_group_id = $group_id;
								$group_verified  = true;
							} else {
								// Lost the UNIQUE-hash race: drop the orphan group and
								// recover the existing row by its canonical hash.
								$wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
								$string_row      = $wpdb->get_row(
									$wpdb->prepare(
										'SELECT id, group_id FROM %i WHERE original_hash = %s LIMIT 1',
										$strings_table,
										StringRepository::compute_hash( $domain, $context, $singular )
									)
								);
								$string_id       = $string_row ? (int) $string_row->id : 0;
								$string_group_id = $string_row ? (int) $string_row->group_id : 0;
							}
						}
					}

					if ( $string_id <= 0 ) {
						$result['errors'][] = sprintf(
							/* translators: %s: PO msgid */
							__( 'Could not create source row for: %s', 'perflocale' ),
							mb_substr( $singular, 0, 60 )
						);
						++$result['skipped'];
						continue;
					}

					// The link is written BEFORE the value, and the string's group
					// is healed first when it cannot legally own one. Same order
					// and same contract as
					// BulkStringTranslateJob::save_translation(): a value with no
					// link is never served AND never retried (the skip-existing
					// branches key off the value), whereas a link with no value
					// serves nothing in the meantime and is simply re-written by
					// the next import.
					//
					// `strings.group_id` is an unenforced FK and three shapes fail
					// it — 0 (never grouped), an id whose group row is gone, and an
					// id that collides with a live post/term group. The last is why
					// this can never be the bare `> 0` test it used to be:
					// upsert_link()'s ON DUPLICATE KEY UPDATE would match that post
					// group's own row through the group_lang unique key and rewrite
					// its object_id to a string id, pointing a real post
					// translation at nothing.
					$link_error = self::link_string_translation(
						$group_repo,
						$string_id,
						$string_group_id,
						(int) $lang->id,
						$group_verified
					);

					if ( $link_error !== '' ) {
						++$result['skipped'];
						$result['errors'][] = $link_error;
						continue;
					}

					// Upsert the translation.
					//
					// MySQL's INSERT…ON DUPLICATE KEY UPDATE returns affected_rows:
					// 0 — duplicate matched, the UPDATE clause's columns ended
					// up with their existing values (no actual write)
					// 1 — fresh insert
					// 2 — duplicate matched and values changed (real update)
					//
					// IMPORTANT: we deliberately do NOT include `updated_at` in
					// the UPDATE clause. The schema declares
					// `updated_at … ON UPDATE CURRENT_TIMESTAMP`, which fires
					// only when at least one OTHER column actually changes. If
					// we wrote `updated_at = VALUES(updated_at)` here, MySQL
					// would always count the row as updated (because the
					// timestamp differs every call), and we couldn't distinguish
					// "really updated" from "no-op upsert" — every re-import of
					// the same PO would report "N updated" instead of "N already
					// up to date".
					// Plural forms 2..N (Polish/Russian/Arabic) ride on this row as
					// JSON extra_forms; NULL for every other row. ONLY a genuine
					// msgid_plural entry is authoritative about those forms —
					// entry_to_forms tags its plural row with the 'extra_forms' key
					// (even as []), and NEVER sets it on a flat/singular entry. So a
					// plural entry may write (form present) OR clear (empty ->
					// NULL) the column; a flat entry must leave it untouched. That
					// distinction is load-bearing: our own PO export flattens each
					// plural row to a singular msgctxt="plural" entry, which on
					// re-import hash-matches the existing plural row. If a flat
					// entry were allowed to write extra_forms it would NULL every
					// 3+ form language's stored forms on the export -> edit ->
					// re-import round-trip (silent data loss).
					$authoritative = array_key_exists( 'extra_forms', $form );
					$extra_json    = ( $authoritative && is_array( $form['extra_forms'] ) && $form['extra_forms'] !== [] )
						? wp_json_encode( array_map( 'strval', $form['extra_forms'] ) )
						: null;

					// IF(%d, VALUES(extra_forms), extra_forms): when authoritative,
					// take the incoming value (possibly NULL to clear); otherwise
					// keep whatever is already stored. NULLIF(%s, '') restores the
					// SQL NULL that wpdb::prepare() flattens away — a null php
					// value binds as '' under %s, which would store a junk
					// empty-string on every non-plural row (and on authoritative
					// clears) instead of the column's real "no extra forms" state.
					// A genuine $extra_json is a non-empty JSON array literal, so
					// the '' → NULL mapping can never hit a real value.
					$now = current_time( 'mysql', true );
					// `string_translations` has exactly ONE unique key — PRIMARY
					// (string_id, language_id) — so this upsert can only ever
					// collide on the pair it is addressing, and affected_rows is a
					// trustworthy inserted/updated/no-op signal. (The polymorphic
					// `translation_links` table, written above, carries two, which
					// is why its write goes through link_string_translation()
					// rather than a bare upsert.)
					$upsert = $wpdb->query(
						$wpdb->prepare(
							"INSERT INTO %i (string_id, language_id, translation, extra_forms, updated_at)
						 VALUES (%d, %d, %s, NULLIF(%s, ''), %s)
						 ON DUPLICATE KEY UPDATE
							extra_forms = IF(%d, VALUES(extra_forms), extra_forms),
							translation = VALUES(translation)",
							$st_table,
							$string_id,
							(int) $lang->id,
							$msgstr,
							$extra_json,
							$now,
							$authoritative ? 1 : 0
						)
					);
					// phpcs:enable

					if ( $upsert !== false ) {
						++$result['imported'];

						switch ( (int) $upsert ) {
							case 1:
								++$result['inserted'];
								break;
							case 2:
								++$result['updated'];
								break;
							case 0:
							default:
								++$result['unchanged'];
								break;
						}
					} else {
						++$result['skipped'];
						$result['errors'][] = sprintf(
							/* translators: 1: msgid, 2: DB error */
							__( '%1$s: %2$s', 'perflocale' ),
							mb_substr( $singular, 0, 60 ),
							(string) $wpdb->last_error
						);

						// In replace mode the old value for this string is already
						// deleted, so a refused upsert is not a skip - it is the
						// string's translation, gone. Committing the rest would
						// hand back 19 of 20 replacements plus a link still marked
						// "translated" pointing at nothing. Roll the whole import
						// back and give the operator their pre-import data.
						//
						// Returned, not thrown, so every caller keeps the
						// documented array contract and reports the failure the
						// same way it reports a malformed file.
						if ( $replace ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
							$wpdb->query( 'ROLLBACK' );

							$result['errors'][]  = __( 'Replace import rolled back: the database refused a translation, so nothing was changed.', 'perflocale' );
							$result['imported']  = 0;
							$result['inserted']  = 0;
							$result['updated']   = 0;
							$result['unchanged'] = 0;

							// Flush before returning. The aborted pass deleted
							// and re-read rows, so caches populated during it
							// describe a state the database has just discarded.
							self::flush_language_caches( $lang );

							return $result;
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			if ( $replace ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
				$wpdb->query( 'ROLLBACK' );
			}

			throw $e;
		}

		if ( $replace ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
			$wpdb->query( 'COMMIT' );
		}

		// Make the import visible immediately on BOTH serving paths (same
		// post-write flush the strings-admin save performs): drop the
		// per-language gettext map so DB mode rebuilds it with the new rows,
		// and regenerate this language's .l10n bundles in files mode.
		// Without this, an import only takes effect after an unrelated
		// admin save or the caches' natural TTL.
		if ( $result['imported'] > 0 || $replace ) {
			self::flush_language_caches( $lang );
		}

		/**
		 * Fires after a PO import writes string translations. Lets addons
		 * deriving state from the strings tables invalidate it (PO import
		 * can touch any domain, including addon-owned ones, and --replace
		 * mode bulk-deletes a language's translations).
		 *
		 * @hook perflocale/strings/changed
		 *
		 * @param string $origin What changed the strings ('po_import').
		 */
		do_action( 'perflocale/strings/changed', 'po_import' );

		return $result;
	}

	/**
	 * Make one imported translation SERVABLE: heal the string's group when it
	 * cannot legally own a link, then write the `translation_links` row.
	 *
	 * `strings.group_id` is an unenforced foreign key and three shapes fail it
	 * — 0 (never grouped), an id whose group row is gone, and an id that
	 * collides with a live post/term group. A string link cannot legally hang
	 * off any of them, so the group is healed onto a fresh `type = 'string'`
	 * one ({@see self::mint_string_group()}) at the moment of the first write
	 * for the row — the same deferral
	 * {@see \PerfLocale\Admin\AdminController::process_string_translations()}
	 * and {@see \PerfLocale\Background\Jobs\BulkStringTranslateJob::save_translation()}
	 * make, because minting for every entry of a PO file would create a group
	 * per untranslated msgid.
	 *
	 * `translation_links` carries TWO unique keys — `group_lang`
	 * (group_id, language_id) AND `object_lang` (type, object_id, language_id)
	 * — and an INSERT … ON DUPLICATE KEY UPDATE can collide on either. When a
	 * heal has just moved this string onto a new group but an orphaned
	 * `type = 'string'` link for the same (object_id, language_id) still points
	 * at the OLD group, the INSERT collides on `object_lang` instead: the
	 * update rewrites that stale row while leaving its group_id on the dead
	 * group, so nothing links the string to the NEW group and upsert_link()
	 * reports false. `upsert_link()` reaps that debris itself and retries once
	 * before it gives up; the same reap is repeated here — type-scoped, because
	 * object_id is polymorphic and a post/term id collides with a string id
	 * freely — so this path behaves identically whichever layer is doing the
	 * reaping, exactly as
	 * {@see \PerfLocale\Background\Jobs\BulkStringTranslateJob::save_translation()}
	 * does. A false that survives both is a real write failure.
	 *
	 * A group minted by this call is reclaimed when the link still cannot be
	 * written, so a failure is a true no-op rather than a fresh orphan.
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo  Group/link repository.
	 * @param int                                                        $string_id   Row in the `strings` table being linked.
	 * @param int                                                        $group_id    The string's group id; updated in place when healed, so the caller's later forms reuse it.
	 * @param int                                                        $language_id Target language id.
	 * @param bool                                                       $verified    True when the caller minted this exact group as `type = 'string'` moments ago, so the type probe can be skipped.
	 * @return string Empty string on success; otherwise the operator-facing reason nothing was linked.
	 */
	private static function link_string_translation(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		int $string_id,
		int &$group_id,
		int $language_id,
		bool $verified = false
	): string {
		global $wpdb;

		$original_group_id = $group_id;
		$minted_group_id   = 0;

		if ( ! $verified && ! $group_repo->is_string_group( $group_id ) ) {
			$group_id        = self::mint_string_group( $string_id );
			$minted_group_id = $group_id;

			if ( $group_id === 0 ) {
				$group_id = $original_group_id;

				return sprintf(
					/* translators: %d: id of the row in the plugin's strings table. */
					__( 'Could not create a translation group for string #%d; its translation was not imported. Re-run the import to retry.', 'perflocale' ),
					$string_id
				);
			}
		}

		// For string groups object_id is the string id. upsert_link() is a
		// single INSERT … ON DUPLICATE KEY UPDATE, so this string's
		// sibling-language links survive untouched.
		$linked = $group_repo->upsert_link(
			$group_id,
			$string_id,
			$language_id,
			'translated',
			\PerfLocale\Enum\SourceType::Manual
		);

		if ( $linked === false ) {
			// Second line of defence behind upsert_link()'s own object_lang
			// reap: drop the stale type-scoped slot and try once more. No row
			// for this group exists at this point — that is what false means —
			// so this can only remove debris that belongs to a dead group.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Debris removal on a self-heal path; no cache to consult.
			$wpdb->delete(
				Schema::table( 'translation_links' ),
				[
					'type'        => \PerfLocale\Enum\ObjectType::String->value,
					'object_id'   => $string_id,
					'language_id' => $language_id,
				],
				[ '%s', '%d', '%d' ]
			);

			$linked = $group_repo->upsert_link(
				$group_id,
				$string_id,
				$language_id,
				'translated',
				\PerfLocale\Enum\SourceType::Manual
			);
		}

		if ( $linked === false ) {
			// Leave nothing widowed: if this call minted the group moments ago,
			// reclaim it by primary key and put the row back where it was.
			if ( $minted_group_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback of a write this method just made.
				$wpdb->delete(
					Schema::table( 'translation_groups' ),
					[ 'id' => $minted_group_id ],
					[ '%d' ]
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback of a write this method just made.
				$wpdb->update(
					Schema::table( 'strings' ),
					[ 'group_id' => $original_group_id ],
					[ 'id' => $string_id ],
					[ '%d' ],
					[ '%d' ]
				);

				$group_id = $original_group_id;
			}

			return sprintf(
				/* translators: %d: id of the row in the plugin's strings table. */
				__( 'Could not link string #%d to the target language; its translation was not imported. Re-run the import to retry.', 'perflocale' ),
				$string_id
			);
		}

		return '';
	}

	/**
	 * Mint a fresh string-type translation group and repoint one string at it.
	 *
	 * The same INSERT-then-UPDATE pair, in the same order and with the same
	 * checks, that
	 * {@see \PerfLocale\Background\Jobs\BulkStringTranslateJob::mint_string_group()},
	 * {@see \PerfLocale\Admin\AdminController::process_string_translations()}
	 * and {@see \PerfLocale\Strings\TranslationFileGenerator::repair_orphaned_translations()}
	 * shape (2) run — the four self-heal paths must not drift.
	 *
	 * Both statements are checked. A failed INSERT leaves the string exactly
	 * as it was. A failed UPDATE — or a zero-row one, meaning the `strings` row
	 * went away between the lookup and now — would leave the string still
	 * pointing at the unusable id while a usable group sat under a different
	 * one, so the group is reclaimed rather than widowed. Either way the caller
	 * gets 0 and reports the entry skipped.
	 *
	 * @param int $string_id Row in the `strings` table to repair.
	 * @return int New group id, or 0 when the repair could not be completed.
	 */
	private static function mint_string_group( int $string_id ): int {
		if ( $string_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write; there is no cache to consult and no repository accessor that mints a bare string group.
		$inserted = $wpdb->insert(
			Schema::table( 'translation_groups' ),
			[ 'type' => \PerfLocale\Enum\ObjectType::String->value ],
			[ '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		// insert_id is only read after a confirmed INSERT: on a failed one it
		// can still hold a PRIOR row's id rather than 0, depending on the
		// mysqli driver.
		$group_id = (int) $wpdb->insert_id;

		if ( $group_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write, keyed on the primary key.
		$updated = $wpdb->update(
			Schema::table( 'strings' ),
			[ 'group_id' => $group_id ],
			[ 'id' => $string_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( ! is_int( $updated ) || $updated < 1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reclaiming the group this method just created, by primary key.
			$wpdb->delete( Schema::table( 'translation_groups' ), [ 'id' => $group_id ], [ '%d' ] );

			return 0;
		}

		return $group_id;
	}

	/**
	 * Lazy-load WP's bundled gettext classes. They don't autoload like the
	 * rest of WP since `wp-includes/pomo/*.php` is plain require_once-style.
	 *
	 * @return void
	 */
	private static function load_pomo(): void {
		if ( ! class_exists( 'PO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/po.php';
		}
		if ( ! class_exists( 'Translation_Entry' ) ) {
			require_once ABSPATH . WPINC . '/pomo/entry.php';
		}
	}

	/**
	 * Resolve a language slug to its row.
	 *
	 * @param string $slug Language slug.
	 * @return object|null Language row or null.
	 */
	private static function resolve_language( string $slug ): ?object {
		global $wpdb;
		$ltable = Schema::table( 'languages' );

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, slug, locale FROM %i WHERE slug = %s LIMIT 1',
				$ltable,
				$slug
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ?: null;
	}

	/**
	 * Pull the `domain: <name>` token out of an extracted-comments block.
	 *
	 * @param string $extracted PO `#.` comments for one entry.
	 * @return string Domain string, or 'default' when absent.
	 */
	private static function extract_domain( string $extracted ): string {
		foreach ( preg_split( '/\r?\n/', $extracted ) as $line ) {
			$line = trim( $line );

			if ( str_starts_with( $line, self::DOMAIN_PREFIX ) ) {
				return trim( substr( $line, strlen( self::DOMAIN_PREFIX ) ) );
			}
		}

		return 'default';
	}

	/**
	 * Expand a PO entry into the (original, context, translation) source rows
	 * it maps to.
	 *
	 * A normal entry is a single row. A standard gettext PLURAL entry
	 * (msgid + msgid_plural, msgstr[0..N] in one entry) expands to two rows,
	 * one per source form, matching how the scanner stores _n()/_nx(): the
	 * singular under context 'singular' (or the entry's own msgctxt) and the
	 * plural under 'plural' (or "<msgctxt> (plural)"). Forms 0 and 1 fill the
	 * two rows' translation columns; forms 2..N (Polish/Russian/Arabic) ride
	 * on the plural row's 'extra_forms' key, which the CLDR runtime indexes.
	 *
	 * @param \Translation_Entry $entry    Parsed PO entry.
	 * @param string             $singular The entry's msgid (already stringified).
	 * @param string             $raw_ctx  The entry's raw msgctxt.
	 * @param string             $domain   Domain resolved from the entry comment.
	 * @return array<int,array{original:string,context:string,translation:string}>
	 */
	private static function entry_to_forms( \Translation_Entry $entry, string $singular, string $raw_ctx, string $domain ): array {
		$plural = (string) ( $entry->plural ?? '' );

		if ( ! $entry->is_plural || $plural === '' ) {
			return [
				[
					'original'    => $singular,
					'context'     => self::decompose_context( $raw_ctx, $domain ),
					'translation' => isset( $entry->translations[0] ) ? (string) $entry->translations[0] : '',
				],
			];
		}

		$base = self::decompose_context( $raw_ctx, $domain );

		// Forms 2..N (msgstr[2], msgstr[3], … for Polish/Russian/Arabic)
		// ride on the plural row as extra_forms; the DB and files runtimes
		// pick the right one by the locale's CLDR form index.
		$extra = [];
		foreach ( array_slice( (array) $entry->translations, 2 ) as $form ) {
			$extra[] = (string) $form;
		}

		return [
			[
				'original'    => $singular,
				'context'     => $base !== '' ? $base : 'singular',
				'translation' => isset( $entry->translations[0] ) ? (string) $entry->translations[0] : '',
			],
			[
				'original'    => $plural,
				'context'     => $base !== '' ? $base . ' (plural)' : 'plural',
				'translation' => isset( $entry->translations[1] ) ? (string) $entry->translations[1] : '',
				'extra_forms' => $extra,
			],
		];
	}
}
