<?php
/**
 * Gettext PO export and import of the strings and string_translations tables.
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
 * Converts between the string tables and PO files, one language per file. The text domain travels as a
 * `#. domain:` comment and, when not default, in msgctxt; carriage returns become `\n` (lossless: JSON export).
 * Docs: docs-ai/11-code-rationale.md, "PoSync: PO round trip".
 */
final class PoSync {

	/**
	 * Comment prefix carrying the `domain` column through PO files.
	 */
	private const DOMAIN_PREFIX = 'domain: ';

	/**
	 * Separator of the text domain folded into msgctxt, so entries sharing (context, msgid) across domains stay
	 * distinct; domains are slugs and never contain it.
	 */
	private const CTX_DOMAIN_SEP = '|';

	/**
	 * PO entries per batch whose held translations are read together.
	 */
	private const HELD_BATCH = 250;

	/**
	 * Fold a non-default text domain into msgctxt (PO keys entries by (context, msgid) only); the default domain keeps
	 * its bare context or null.
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
	 * Reverse compose_context(), only when the prefix is exactly the entry's comment domain, so a foreign context that
	 * contains the separator is left alone.
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
	public static function export_to_file( string $path, string $lang_slug, string $domain = '', array &$report = [] ) {
		self::load_pomo();

		// Set on every exit path, so a caller can read it without checking the return value.
		$report = [
			'emitted'        => 0,
			'unhashable'     => 0,
			'unhashable_ids' => [],
			'dup_groups'     => 0,
			'dup_rows'       => 0,
		];

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

		// The header through core's PO class, as every gettext consumer writes it.
		$po = new \PO();

		$po->set_header( 'Project-Id-Version', 'PerfLocale ' . ( defined( 'PERFLOCALE_VERSION' ) ? PERFLOCALE_VERSION : '0.0' ) );
		$po->set_header( 'Language', (string) $lang->locale );
		$po->set_header( 'MIME-Version', '1.0' );
		$po->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$po->set_header( 'Content-Transfer-Encoding', '8bit' );
		// The language's CLDR plural header; singular and plural are exported as separate entries (the JSON export keeps
		// 3+ forms).
		$po->set_header( 'Plural-Forms', \PerfLocale\Strings\PluralRules::header( (string) $lang->locale ) );
		$po->set_header( 'X-Generator', 'PerfLocale' );

		// Keyset batches written as produced (memory: one batch), into a sibling temp renamed over $path on success.
		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write; WP_Filesystem has no streaming API.
		$fh = fopen( $tmp, 'xb' );

		if ( ! $fh ) {
			return false;
		}

		$bytes = 0;
		$write = static function ( string $data ) use ( $fh, &$bytes ): bool {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write.
			$ok     = fwrite( $fh, $data ) === strlen( $data );
			$bytes += strlen( $data );

			return $ok;
		};
		$abort = static function () use ( $fh, $tmp ): bool {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.
			fclose( $fh );
			wp_delete_file( $tmp );

			return false;
		};

		if ( ! $write( $po->export_headers() ) ) {
			return $abort();
		}

		$cursor = 0;

		/**
		 * Filter the rows per keyset batch of the PO export (default 1000, clamped): more rows, fewer queries and more memory.
		 *
		 * @hook perflocale/strings/po_export_batch_size
		 * @param int $batch_size Default 1000.
		 */
		$batch_size = (int) apply_filters( 'perflocale/strings/po_export_batch_size', 1000 );
		$batch_size = max( 100, min( 10000, $batch_size ) );

		do {
			// Cursor and batch size are internal integers cast into the query; the language id and domain stay placeholders.
			$cursor_sql = (int) $cursor;
			$limit_sql  = (int) $batch_size;

			// Placeholder order: the two %i tables, the %d language and any %s of $where (in $args), then cursor and batch size.
			// $where is built from literals only.
			$q_args = array_merge( [ $strings, $translations ], $args, [ $cursor_sql, $limit_sql ] );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.original, s.original_hash, s.context, s.domain, COALESCE(t.translation, '') AS translation
				 FROM %i s
				 LEFT JOIN %i t ON t.string_id = s.id AND t.language_id = %d
				 WHERE {$where} AND s.id > %d
				 ORDER BY s.id
				 LIMIT %d",
					...$q_args
				)
			);
			// phpcs:enable

			// A failed page and the last page both return []: without this a truncated file would be a success. Read before
			// another query (the duplicate count) clears last_error.
			if ( '' !== (string) $wpdb->last_error ) {
				return $abort();
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row->id;

				// Carriage returns out: core's PO::poify() does not escape \r, and one raw CR makes the whole file unparseable.
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
					continue; // False for an entry core cannot serialise.
				}

				// One blank line between entries, as core's PO::export().
				if ( ! $write( "\n\n" . $serialized ) ) {
					return $abort();
				}
				++$report['emitted'];

				/*
				 * A row whose stored original_hash is not the hash of its `original` would get a phantom twin on re-import (the
				 * importer hashes the msgid it reads); counted per row, retaining nothing. The raw original is hashed.
				 */
				if ( StringRepository::compute_hash( (string) $row->domain, (string) $row->context, (string) $row->original ) !== (string) $row->original_hash ) {
					++$report['unhashable'];

					if ( count( $report['unhashable_ids'] ) < 20 ) {
						$report['unhashable_ids'][] = (int) $row->id;
					}
				}
			}
		} while ( count( $rows ) === $batch_size );

		/*
		 * Rows composing to the same (msgctxt, msgid) collapse into one PO entry: counted in SQL over the loop's WHERE, as an
		 * in-PHP set would materialise the corpus the keyset loop avoids.
		 */
		// ReplacementsWrongNumber is a false positive here: six placeholders, six values, passed through a spread the
		// sniff counts as one argument.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$dupes = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS grps, COALESCE(SUM(c), 0) AS rows_in_grps FROM (
					SELECT COUNT(*) AS c
					FROM %i s
					WHERE {$where} AND s.original <> ''
					GROUP BY BINARY ( CASE WHEN s.domain <> '' AND s.domain <> 'default' THEN CONCAT( s.domain, %s, s.context ) ELSE s.context END ),
						BINARY REPLACE( REPLACE( s.original, %s, %s ), %s, %s )
					HAVING COUNT(*) > 1
				) d",
				// $args[0] is the row query's language id; this aggregate has no such join, so only the domain filter is bound.
				...array_merge( [ $strings ], array_slice( $args, 1 ), [ self::CTX_DOMAIN_SEP, "\r\n", "\n", "\r", "\n" ] )
			)
		);
		// phpcs:enable

		if ( $dupes ) {
			$report['dup_groups'] = (int) $dupes->grps;
			$report['dup_rows']   = (int) $dupes->rows_in_grps;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Streaming write.
		$ok = fflush( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.
		$ok = fclose( $fh ) && $ok;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The mode put_contents() gave the file.
		$ok = $ok && chmod( $tmp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic publish of the file written above.
		if ( ! $ok || ! rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );

			return false;
		}

		return $bytes;
	}

	/**
	 * Log a database error when WP_DEBUG_LOG is on; the import result (notice, REST) gets a short message, as the
	 * server's text can name tables and values.
	 *
	 * @param string $where What the import was doing.
	 * @param string $error The database error.
	 * @return void
	 */
	private static function log_db_error( string $where, string $error ): void {
		if ( $error === '' || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic, only when WP_DEBUG_LOG is on.
		error_log( '[PerfLocale] PO import: database error while ' . $where . ' - ' . $error );
	}

	/**
	 * Make a language's string translations visible on both serving paths: drop its gettext map (database mode),
	 * regenerate its `.l10n` bundles (files mode). Also after a rolled-back replace, whose reads cached discarded state.
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
	 * Convert CRLF and bare CR to LF.
	 *
	 * @see the export loop for why: core's PO serialiser does not escape \r.
	 *
	 * @param string $text Text to normalise.
	 */
	private static function normalise_newlines( string $text ): string {
		return str_replace( [ "\r\n", "\r" ], "\n", $text );
	}

	/**
	 * Whether `$bytes` is well-formed UTF-8: an empty /u pattern fails on invalid UTF-8. Not mb_check_encoding(), which
	 * core does not polyfill (a fatal without ext-mbstring).
	 *
	 * @param string $bytes Candidate.
	 */
	private static function is_valid_utf8( string $bytes ): bool {
		return $bytes === '' || preg_match( '//u', $bytes ) === 1;
	}

	/**
	 * Import a PO file into the string tables: each entry finds or creates its `strings` row, links it to the language
	 * in `translation_links` and only then upserts its msgstr; an entry that cannot be linked is skipped with an error
	 * and writes nothing. Entries without a `domain:` comment use 'default'.
	 *
	 * @param string        $path      Input PO path.
	 * @param string        $lang_slug Target language slug.
	 * @param bool          $replace   Delete this language's string translations first (sources and other languages kept).
	 * @param callable|null $sanitize  Applied to every non-empty value of an importable entry before it writes; returns
	 *                                 what to store. Null stores the file's bytes (WP-CLI); the admin upload passes one
	 *                                 for users without `unfiltered_html`.
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
	 *     sanitized:int,
	 *     sanitized_empty:int,
	 * } The first three keys are the standard importer shape; the rest break it down for the notice. `sanitized`:
	 * entries $sanitize changed; `sanitized_empty`: those left with no translation (always with an error line).
	 *
	 * @throws \Throwable Re-thrown after the replace-mode transaction is rolled
	 *                     back, so an unexpected failure can never leave the
	 *                     wipe committed without its re-import.
	 */
	public static function import_from_file( string $path, string $lang_slug, bool $replace = false, ?callable $sanitize = null ): array {
		$result = [
			'imported'        => 0,
			'skipped'         => 0,
			'errors'          => [],
			'inserted'        => 0,
			'updated'         => 0,
			'unchanged'       => 0,
			'no_translation'  => 0,
			'fuzzy_skipped'   => 0,
			'total_entries'   => 0,
			'sanitized'       => 0,
			'sanitized_empty' => 0,
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

		/*
		 * A replace import deletes the language before it writes, so a file that would write nothing is refused first:
		 * not valid UTF-8, no entries, only empty, fuzzy or extra-plural translations. Docs: docs-ai/11-code-rationale.md,
		 * "PoSync: what an import may write".
		 */
		if ( ! self::is_valid_utf8( (string) file_get_contents( $path ) ) ) {
			$result['errors'][] = __( 'The PO file is not valid UTF-8. Re-save it as UTF-8 and try again - importing it as-is would discard the entries it cannot read.', 'perflocale' );
			return $result;
		}

		// The entry loop's own test, in its order: a translation on row 0 or 1, then no fuzzy flag.
		$has_entries = false;
		$saw_fuzzy   = false;

		foreach ( (array) $po->entries as $po_entry ) {
			if ( ! $po_entry instanceof \Translation_Entry || (string) $po_entry->singular === '' ) {
				continue;
			}

			$po_forms = self::entry_to_forms(
				$po_entry,
				(string) $po_entry->singular,
				$po_entry->context !== null ? (string) $po_entry->context : '',
				self::extract_domain( (string) ( $po_entry->extracted_comments ?? '' ) )
			);

			if ( ! self::forms_have_translation( $po_forms ) ) {
				continue;
			}

			if ( in_array( 'fuzzy', (array) $po_entry->flags, true ) ) {
				$saw_fuzzy = true;
				continue;
			}

			$has_entries = true;
			break;
		}

		// Such a file can also hold translations only in extra plural forms, which never write alone: the message names both.
		if ( ! $has_entries ) {
			$result['errors'][] = $saw_fuzzy
				? __( 'Nothing was imported: every translation in the PO file is either marked fuzzy (needs review) or an extra plural form that is not imported on its own, and fuzzy translations are never imported. Review the fuzzy translations in your translation tool, then import again.', 'perflocale' )
				: __( 'The PO file contains no translatable entries. If it was exported from another tool, check that it is saved as UTF-8.', 'perflocale' );
			return $result;
		}

		// Importable values the sanitizer empties entirely: returned here before any write, with the loop's counters.
		if ( $sanitize !== null ) {
			$emptied_file = self::counts_if_sanitizer_empties_file( $po, $sanitize );

			if ( $emptied_file !== null ) {
				$result             = array_merge( $result, $emptied_file );
				$result['errors'][] = __( 'Nothing was imported: removing HTML that is not allowed left every importable translation in the PO file empty.', 'perflocale' );

				return $result;
			}
		}

		global $wpdb;
		$strings_table = Schema::table( 'strings' );
		$st_table      = Schema::table( 'string_translations' );

		// Resolved once before any write: every value needs a link through the repository, so a missing one is reported
		// up front instead of storing rows nothing can serve.
		$plugin        = \PerfLocale\Plugin::get_instance();
		$cache_service = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		if ( ! $cache_service instanceof \PerfLocale\Cache\CacheManager ) {
			$result['errors'][] = __( 'The PerfLocale cache service is not available, so imported translations could not be linked to a language. Nothing was imported.', 'perflocale' );

			return $result;
		}

		$group_repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache_service );

		// Failed queries before the replace transaction (Schema::transaction_lost()).
		$tx_errors = Schema::query_error_count();

		if ( $replace ) {
			// The delete and the re-import in one transaction, so a fatal rolls back to the state before the import.
			// Checked against false only: success returns 0.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				$result['errors'][] = __( 'Replace import did not start: the database could not open a transaction, so nothing was changed.', 'perflocale' );

				return $result;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wiped = false !== $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE language_id = %d',
					$st_table,
					(int) $lang->id
				)
			);

			// Also drop the wiped rows' 'translated' string links, only in string groups (post and term links stay); only
			// after the first delete succeeded.
			$wiped = $wiped && self::delete_string_links( (int) $lang->id );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// 0 deleted rows is a valid wipe; false stops here, since after a deadlock every later statement would commit
			// alone. Nothing was read, so no cache to flush.
			if ( ! $wiped ) {
				// Read before the ROLLBACK clears it.
				self::log_db_error( 'removing the existing translations', (string) $wpdb->last_error );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
				$wpdb->query( 'ROLLBACK' );

				$result['errors'][] = __( 'Replace import rolled back: the database could not remove the existing translations, so nothing was changed.', 'perflocale' );

				return $result;
			}
		}

		// From here every exit that is not a clean COMMIT rolls back explicitly: an outer handler catching a Throwable
		// would leave the wipe for the next statement to commit. $held is what each string held before its write, for the
		// inserted / updated / unchanged counts (SQLite's upsert reports 1 for all three).
		$held    = [];
		$entries = array_values( (array) $po->entries );

		// In a replace transaction no statement may fail (on SQLite one failure ends it): a failed write stops the import.
		$abort_replace = static function () use ( &$result, $wpdb, $lang ): void {
			if ( $wpdb instanceof \wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
				$wpdb->query( 'ROLLBACK' );
			}

			$result['errors'][]  = __( 'Replace import rolled back: the database refused a write, so nothing was changed.', 'perflocale' );
			$result['imported']  = 0;
			$result['inserted']  = 0;
			$result['updated']   = 0;
			$result['unchanged'] = 0;

			self::flush_language_caches( $lang );
		};

		try {

			// PO::$entries is keyed by Translation_Entry::key().
			foreach ( $entries as $index => $entry ) {
				if ( ! $replace && 0 === $index % self::HELD_BATCH ) {
					$held = self::held_translations( $strings_table, $st_table, $lang, array_slice( $entries, $index, self::HELD_BATCH ) );
				}

				if ( ! $entry instanceof \Translation_Entry ) {
					continue;
				}

				$singular = (string) ( $entry->singular ?? '' );

				if ( $singular === '' ) {
					continue; // The PO header.
				}

				++$result['total_entries'];

				$raw_context = $entry->context !== null ? (string) $entry->context : '';
				$domain      = self::extract_domain( (string) ( $entry->extracted_comments ?? '' ) );

				// A gettext plural entry (msgid + msgid_plural, as in a translate.wordpress.org pack) becomes the two rows the
				// scanner and loader expect; PerfLocale's own export writes them as two entries.
				$forms = self::entry_to_forms( $entry, $singular, $raw_context, $domain );

				if ( ! self::forms_have_translation( $forms ) ) {
					// No translation on any form (a fresh PO): counted apart, so the notice can say msgid-only.
					++$result['no_translation'];
					++$result['skipped'];
					continue;
				}

				// Fuzzy is msgmerge's guess from another string; every standard consumer skips it.
				if ( in_array( 'fuzzy', (array) $entry->flags, true ) ) {
					++$result['fuzzy_skipped'];
					++$result['skipped'];
					continue;
				}

				// Sanitized only once importable, so msgid-only and fuzzy entries count as without a sanitizer; an emptied value
				// is reported.
				if ( $sanitize !== null ) {
					[ $clean_forms, $altered ] = self::sanitize_forms( $forms, $sanitize );

					if ( $altered ) {
						++$result['sanitized'];
					}

					if ( ! self::forms_have_translation( $clean_forms ) ) {
						++$result['sanitized_empty'];
						++$result['skipped'];
						$result['errors'][] = sprintf(
							/* translators: %s: PO msgid */
							__( 'Not imported: removing HTML that is not allowed left the translation of "%s" empty.', 'perflocale' ),
							mb_substr( $singular, 0, 60 )
						);
						continue;
					}

					[ $rows_not_imported, $partial_errors ] = self::report_partly_sanitized( $forms, $clean_forms, $singular, $replace );

					$result['skipped'] += $rows_not_imported;

					foreach ( $partial_errors as $partial_error ) {
						$result['errors'][] = $partial_error;
					}

					$forms = $clean_forms;
				}

				foreach ( $forms as $form ) {
					$singular = $form['original'];
					$context  = $form['context'];
					$msgstr   = $form['translation'];

					if ( $msgstr === '' ) {
						continue; // No translation for this form.
					}

					// Find the source row by its sha256 original_hash (unique, indexed); group_id comes along for the link below.
					// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$lookup_errors   = Schema::query_error_count();
					$string_row      = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT id, group_id FROM %i WHERE original_hash = %s LIMIT 1',
							$strings_table,
							StringRepository::compute_hash( $domain, $context, $singular )
						)
					);
					$string_id       = $string_row ? (int) $string_row->id : 0;
					$string_group_id = $string_row ? (int) $string_row->group_id : 0;

					// Line-ending fallback: PO reads \r back as \n, so a row stored with CRLF or CR is matched normalised (CHAR(13) and
					// CHAR(10), as prepare() would escape '\r'). Only when the msgid has a newline: the scan is not sargable.
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

					// A failed lookup ends a replace import before any write (lookups run no hook, so the failure is theirs).
					if ( $replace && Schema::transaction_lost( $tx_errors, Schema::query_error_count() > $lookup_errors ) ) {
						$abort_replace();
						return $result;
					}

					// True only when this iteration created the group (then known to be a string group); any other group_id is verified.
					$group_verified = false;

					if ( $string_id === 0 ) {
						// Create the source row as the scanner does: the sha256 original_hash and its own type='string' group.
							$groups_table = Schema::table( 'translation_groups' );

							// insert_id only after a confirmed insert: after a failed one it can hold an earlier row's id (as
							// BulkStringTranslateJob::mint_string_group()).
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
								],
								[ '%s', '%s', '%s', '%s', '%d' ]
							);

							if ( false !== $inserted && (int) $wpdb->insert_id > 0 ) {
								$string_id       = (int) $wpdb->insert_id;
								$string_group_id = $group_id;
								$group_verified  = true;
							} elseif ( $replace ) {
								$abort_replace();
								return $result;
							} else {
								// Lost the unique-hash race: drop the orphan group and use the existing row.
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

					if ( $string_id <= 0 && $replace ) {
						$abort_replace();
						return $result;
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

					// The link before the value, its group healed first (as BulkStringTranslateJob::save_translation()): a value
					// without a link is never served or retried. group_id can be 0, gone, or a post or term group's.
					$link_error = self::link_string_translation(
						$group_repo,
						$string_id,
						$string_group_id,
						(int) $lang->id,
						$group_verified,
						$replace
					);

					// The link write runs perflocale/translation/linked listeners, whose failed query can also end the transaction.
					if ( $replace && Schema::transaction_lost( $tx_errors, $link_error !== '' ) ) {
						$abort_replace();
						return $result;
					}

					if ( $link_error !== '' ) {
						++$result['skipped'];
						$result['errors'][] = $link_error;
						continue;
					}

					// Upsert the value; updated_at moves by its ON UPDATE clause (SQLite: Schema::touch_write_time()). Only a genuine
					// msgid_plural entry may write or clear extra_forms: a flat re-imported entry would erase stored forms.
					$authoritative = array_key_exists( 'extra_forms', $form );
					$extra_json    = ( $authoritative && is_array( $form['extra_forms'] ) && $form['extra_forms'] !== [] )
						? wp_json_encode( array_map( 'strval', $form['extra_forms'] ) )
						: null;

					// IF(%d, VALUES(extra_forms), extra_forms) takes the value only when authoritative; NULLIF(%s, '') restores the NULL
					// prepare() flattens. One unique key (string_id, language_id), unlike translation_links.
					$touch = \PerfLocale\Database\Schema::touch_write_time(
						$authoritative
							? [ [ 'extra_forms', 'VALUES(extra_forms)', 'text_null' ], [ 'translation', 'VALUES(translation)', 'text' ] ]
							: [ [ 'translation', 'VALUES(translation)', 'text' ] ]
					);
					// A string the batch read did not cover (created here, or found by the fallback or after the race) is read now.
					if ( ! $replace && ! array_key_exists( $string_id, $held ) ) {
						$held[ $string_id ] = $group_verified ? null : self::held_translation( $st_table, $lang, $string_id );
					}

					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $touch is Schema::touch_write_time(): column names and VALUES() only, '' on MySQL.
					$upsert = $wpdb->query(
						$wpdb->prepare(
							"INSERT INTO %i (string_id, language_id, translation, extra_forms)
						 VALUES (%d, %d, %s, NULLIF(%s, ''))
						 ON DUPLICATE KEY UPDATE
							extra_forms = IF(%d, VALUES(extra_forms), extra_forms),
							translation = VALUES(translation)" . $touch,
							$st_table,
							$string_id,
							(int) $lang->id,
							$msgstr,
							$extra_json,
							$authoritative ? 1 : 0
						)
					);
					// phpcs:enable

					if ( $upsert !== false ) {
						++$result['imported'];

						$was      = $held[ $string_id ] ?? null;
						$now_held = [
							sha1( $msgstr ),
							$authoritative ? ( null === $extra_json ? null : sha1( (string) $extra_json ) ) : ( $was[1] ?? null ),
						];

						if ( null === $was ) {
							++$result['inserted'];
						} elseif ( $was === $now_held ) {
							++$result['unchanged'];
						} else {
							++$result['updated'];
						}

						$held[ $string_id ] = $now_held;
					} else {
						++$result['skipped'];
						self::log_db_error( 'saving a translation', (string) $wpdb->last_error );
						$result['errors'][] = sprintf(
							/* translators: %s: the source string (msgid), shortened to 60 characters */
							__( '%s: the database refused this translation.', 'perflocale' ),
							mb_substr( $singular, 0, 60 )
						);

						// In replace mode the old value is already deleted, so a refused upsert loses the translation: roll the whole
						// import back. Returned, not thrown, to keep the array contract.
						if ( $replace ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
							$wpdb->query( 'ROLLBACK' );

							$result['errors'][]  = __( 'Replace import rolled back: the database refused a translation, so nothing was changed.', 'perflocale' );
							$result['imported']  = 0;
							$result['inserted']  = 0;
							$result['updated']   = 0;
							$result['unchanged'] = 0;

							// The aborted pass deleted and re-read rows: its caches describe discarded state.
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

		// A refused COMMIT usually means the server discarded the transaction; the ROLLBACK covers an open one (false only:
		// success is 0). A failed query that ended the transaction rolled the wipe back too: reported, never committed.
		if ( $replace && Schema::transaction_lost( $tx_errors ) ) {
			$abort_replace();

			return $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
		if ( $replace && false === $wpdb->query( 'COMMIT' ) ) {
			// Read before the ROLLBACK clears it.
			self::log_db_error( 'committing a replace import', (string) $wpdb->last_error );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control statement; constant SQL, no value/identifier, caching N/A.
			$wpdb->query( 'ROLLBACK' );

			// First, so the three-message admin notice cannot hide it behind per-entry ones.
			array_unshift(
				$result['errors'],
				__( 'Replace import not confirmed: the database reported an error while saving it. Check this language\'s translations and run the import again.', 'perflocale' )
			);
			$result['imported']  = 0;
			$result['inserted']  = 0;
			$result['updated']   = 0;
			$result['unchanged'] = 0;

			// As after the rolled-back upsert: the aborted pass deleted and re-read rows.
			self::flush_language_caches( $lang );

			return $result;
		}

		// Make the import visible on both serving paths at once, as a Strings save does (flush_language_caches()).
		if ( $result['imported'] > 0 || $replace ) {
			self::flush_language_caches( $lang );
		}

		/**
		 * Fires after a PO import writes string translations, so add-ons deriving state from the string tables can refresh
		 * it (an import can touch any domain, and --replace deletes a language's translations).
		 *
		 * @hook perflocale/strings/changed
		 *
		 * @param string $origin What changed the strings ('po_import').
		 */
		do_action( 'perflocale/strings/changed', 'po_import' );

		return $result;
	}

	/**
	 * Whether a row of the entry carries a translation to store (extra plural forms ride on a row and do not count).
	 *
	 * @param array<int, array{original: string, context: string, translation: string, extra_forms?: array<int, string>}> $forms Output of {@see self::entry_to_forms()}.
	 */
	private static function forms_have_translation( array $forms ): bool {
		foreach ( $forms as $form ) {
			if ( $form['translation'] !== '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The counters for a file whose importable translations the sanitizer empties entirely, else null (also when nothing
	 * is importable before sanitizing). Importable follows the entry loop's order; stops at the first surviving value.
	 *
	 * @param \PO      $po       Parsed file.
	 * @param callable $sanitize Import sanitizer.
	 * @return array{total_entries: int, no_translation: int, fuzzy_skipped: int, skipped: int, sanitized: int, sanitized_empty: int}|null
	 */
	private static function counts_if_sanitizer_empties_file( \PO $po, callable $sanitize ): ?array {
		$counts = [
			'total_entries'   => 0,
			'no_translation'  => 0,
			'fuzzy_skipped'   => 0,
			'skipped'         => 0,
			'sanitized'       => 0,
			'sanitized_empty' => 0,
		];

		foreach ( (array) $po->entries as $entry ) {
			if ( ! $entry instanceof \Translation_Entry || (string) $entry->singular === '' ) {
				continue;
			}

			++$counts['total_entries'];
			++$counts['skipped'];

			$raw_context = $entry->context !== null ? (string) $entry->context : '';
			$domain      = self::extract_domain( (string) ( $entry->extracted_comments ?? '' ) );
			$forms       = self::entry_to_forms( $entry, (string) $entry->singular, $raw_context, $domain );

			if ( ! self::forms_have_translation( $forms ) ) {
				++$counts['no_translation'];
				continue;
			}

			if ( in_array( 'fuzzy', (array) $entry->flags, true ) ) {
				++$counts['fuzzy_skipped'];
				continue;
			}

			[ $clean_forms ] = self::sanitize_forms( $forms, $sanitize );

			if ( self::forms_have_translation( $clean_forms ) ) {
				return null;
			}

			++$counts['sanitized'];
			++$counts['sanitized_empty'];
		}

		return $counts['sanitized_empty'] > 0 ? $counts : null;
	}

	/**
	 * Describe the rows and plural forms of a partly surviving entry that the sanitizer emptied, so the import never
	 * reads as complete: an emptied row is not written (merge mode keeps what is stored), an emptied msgstr[2..N] is
	 * stored as an empty form.
	 *
	 * @param array<int, array{original: string, context: string, translation: string, extra_forms?: array<int, string>}> $forms       Forms as read from the file.
	 * @param array<int, array{original: string, context: string, translation: string, extra_forms?: array<int, string>}> $clean_forms The same forms after the sanitizer.
	 * @param string                                                                                                      $msgid       The entry's msgid, for the messages.
	 * @param bool                                                                                                        $replace     Replace mode (nothing stored is kept).
	 * @return array{0: int, 1: array<int, string>} Rows left unwritten (each a skip), and one message per emptied value.
	 */
	private static function report_partly_sanitized( array $forms, array $clean_forms, string $msgid, bool $replace ): array {
		$name     = mb_substr( $msgid, 0, 60 );
		$rows     = 0;
		$messages = [];

		foreach ( $forms as $i => $form ) {
			if ( $form['translation'] === '' ) {
				continue;
			}

			if ( $clean_forms[ $i ]['translation'] === '' ) {
				$later_forms = isset( $form['extra_forms'] ) && [] !== array_filter( $form['extra_forms'], static fn( string $extra ): bool => $extra !== '' );

				$message = $later_forms
					? sprintf(
						/* translators: %s: PO msgid */
						__( 'Partly imported: removing HTML that is not allowed left msgstr[1] of "%s" empty, so msgstr[1] and the plural forms after it were not imported.', 'perflocale' ),
						$name
					)
					: sprintf(
						/* translators: 1: plural form index (0 or 1), 2: PO msgid */
						__( 'Partly imported: removing HTML that is not allowed left msgstr[%1$d] of "%2$s" empty, so that form was not imported.', 'perflocale' ),
						(int) $i,
						$name
					);

				if ( ! $replace ) {
					$message .= ' ' . __( 'Any translation already stored for it was kept.', 'perflocale' );
				}

				++$rows;
				$messages[] = $message;
				continue;
			}

			foreach ( $form['extra_forms'] ?? [] as $k => $extra ) {
				if ( $extra !== '' && ( $clean_forms[ $i ]['extra_forms'][ $k ] ?? '' ) === '' ) {
					$messages[] = sprintf(
						/* translators: 1: plural form index (2 or higher), 2: PO msgid */
						__( 'Partly imported: removing HTML that is not allowed left msgstr[%1$d] of "%2$s" empty, and that plural form was stored without a translation.', 'perflocale' ),
						(int) $k + 2,
						$name
					);
				}
			}
		}

		return [ $rows, $messages ];
	}

	/**
	 * Run the sanitizer over every value of one entry; an emptied extra form stays '' in its slot (forms are indexed by
	 * CLDR category).
	 *
	 * @param array<int, array{original: string, context: string, translation: string, extra_forms?: array<int, string>}> $forms    Output of {@see self::entry_to_forms()}.
	 * @param callable                                                                                                    $sanitize Maps a non-empty value to the string to store.
	 * @return array{0: array<int, array{original: string, context: string, translation: string, extra_forms?: array<int, string>}>, 1: bool}
	 *         The sanitized forms, and whether any value changed.
	 */
	private static function sanitize_forms( array $forms, callable $sanitize ): array {
		$altered = false;

		$apply = static function ( string $value ) use ( $sanitize, &$altered ): string {
			if ( $value === '' ) {
				return '';
			}

			$clean = self::apply_sanitizer( $sanitize, $value );

			if ( $clean !== $value ) {
				$altered = true;
			}

			return $clean;
		};

		foreach ( $forms as $i => $form ) {
			$forms[ $i ]['translation'] = $apply( $form['translation'] );

			if ( isset( $form['extra_forms'] ) ) {
				$forms[ $i ]['extra_forms'] = array_map( $apply, $form['extra_forms'] );
			}
		}

		return [ $forms, $altered ];
	}

	/**
	 * Call the sanitizer; a non-scalar answer (null, an array) counts as nothing left.
	 *
	 * @param callable $sanitize Import sanitizer.
	 * @param string   $value    Non-empty translated value.
	 * @return string
	 */
	private static function apply_sanitizer( callable $sanitize, string $value ): string {
		$clean = $sanitize( $value );

		return is_scalar( $clean ) ? (string) $clean : '';
	}

	/**
	 * Make one imported translation servable: heal the string's group when it cannot own a link, then write the link,
	 * reaping a stale link under another group once (translation_links has two unique keys). A group minted here is
	 * reclaimed on failure. Docs: docs-ai/11-code-rationale.md, "PoSync: string links and their two keys".
	 *
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository $group_repo  Group/link repository.
	 * @param int                                                        $string_id   Row in the `strings` table being linked.
	 * @param int                                                        $group_id    The string's group id; updated in place when healed, so the caller's later forms reuse it.
	 * @param int                                                        $language_id Target language id.
	 * @param bool                                                       $verified    True when the caller minted this exact group as `type = 'string'` moments ago, so the type probe can be skipped.
	 * @param bool                                                       $in_transaction True inside a replace import's transaction: a failed link write is returned at once, with no retry (no statement may run after a failure there).
	 * @return string Empty string on success; otherwise the operator-facing reason nothing was linked.
	 */
	private static function link_string_translation(
		\PerfLocale\Database\Repository\TranslationGroupRepository $group_repo,
		int $string_id,
		int &$group_id,
		int $language_id,
		bool $verified = false,
		bool $in_transaction = false
	): string {
		global $wpdb;

		$original_group_id = $group_id;
		$minted_group_id   = 0;

		if ( $verified ) {
			\PerfLocale\Database\Repository\TranslationGroupRepository::remember_string_group( $group_id );
		}

		if ( ! $verified && ! $group_repo->is_string_group( $group_id ) ) {
			$group_id        = self::mint_string_group( $string_id, $in_transaction );
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

		// object_id is the string id; one upsert, so the string's other language links stay.
		$linked = $group_repo->upsert_link(
			$group_id,
			$string_id,
			$language_id,
			'translated',
			\PerfLocale\Enum\SourceType::Manual,
			$in_transaction
		);

		if ( $linked === false && $in_transaction ) {
			return sprintf(
				/* translators: %d: id of the row in the plugin's strings table. */
				__( 'Could not link string #%d to the target language; its translation was not imported. Re-run the import to retry.', 'perflocale' ),
				$string_id
			);
		}

		if ( $linked === false ) {
			// Behind upsert_link()'s own reap: drop a stale link of this string in another group and try once more.
			// Never this group's link: after a failed write it still serves the stored translation.
			if ( $wpdb instanceof \wpdb ) {
				$sql = $wpdb->prepare( 'DELETE FROM %i WHERE type = %s AND object_id = %d AND language_id = %d AND group_id != %d', Schema::table( 'translation_links' ), \PerfLocale\Enum\ObjectType::String->value, $string_id, $language_id, $group_id );

				if ( is_string( $sql ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Debris removal on a self-heal path (prepared above); no cache to consult.
					$wpdb->query( $sql );
				}
			}

			$linked = $group_repo->upsert_link(
				$group_id,
				$string_id,
				$language_id,
				'translated',
				\PerfLocale\Enum\SourceType::Manual
			);
		}

		if ( $linked === false ) {
			// A group this call minted is reclaimed by primary key and the row put back.
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
	 * Mint a string group and point one string at it, exactly as the other self-heal paths do (same statements, order
	 * and checks). A failed INSERT changes nothing; a failed or zero-row UPDATE reclaims the group. Then 0.
	 *
	 * @param int  $string_id      Row in the `strings` table to repair.
	 * @param bool $in_transaction True inside a replace import's transaction: a failed write is not
	 *                             followed by the clean-up DELETE (the caller rolls back).
	 * @return int New group id, or 0 when the repair could not be completed.
	 */
	private static function mint_string_group( int $string_id, bool $in_transaction = false ): int {
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

		// insert_id only after a confirmed INSERT: after a failed one it can hold an earlier row's id.
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
			// In a transaction nothing follows a failed update: the caller's ROLLBACK removes the group.
			if ( false === $updated && $in_transaction ) {
				return 0;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reclaiming the group this method just created, by primary key.
			$wpdb->delete( Schema::table( 'translation_groups' ), [ 'id' => $group_id ], [ '%d' ] );

			return 0;
		}

		return $group_id;
	}

	/**
	 * Delete one language's links of string groups (a replace import's wipe).
	 *
	 * @param int $language_id Language ID.
	 * @return bool False when the DELETE failed.
	 */
	private static function delete_string_links( int $language_id ): bool {
		global $wpdb;

		// One table per DELETE: the SQLite integration's 2.2.x legacy driver mishandles a DELETE holding JOIN.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return false !== $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE language_id = %d AND group_id IN ( SELECT id FROM %i WHERE type = 'string' )",
				Schema::sanitize_table( Schema::table( 'translation_links' ) ),
				$language_id,
				Schema::sanitize_table( Schema::table( 'translation_groups' ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Load WordPress's bundled gettext classes (wp-includes/pomo/ is not autoloaded).
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
	 * The `domain: <name>` token of an entry's extracted comments.
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
	 * The source rows a PO entry maps to: one, or for a gettext plural entry two, as the scanner stores _n()/_nx()
	 * ('singular' / 'plural' or "<msgctxt> (plural)"); forms 2..N ride on the plural row's 'extra_forms'.
	 *
	 * @param \Translation_Entry $entry    Parsed PO entry.
	 * @param string             $singular The entry's msgid (already stringified).
	 * @param string             $raw_ctx  The entry's raw msgctxt.
	 * @param string             $domain   Domain resolved from the entry comment.
	 * @return array<int,array{original:string,context:string,translation:string,extra_forms?:array<int,string>}>
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

		// Forms 2..N ride on the plural row; both runtimes pick one by the locale's CLDR form index.
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

	/**
	 * What a batch's strings hold in a language, per string id: SHA-1s of the translation and of the extra forms (null
	 * without), or null without a translation; one read by source hash, missing strings absent.
	 *
	 * @param string                   $strings_table The strings table.
	 * @param string                   $st_table      The string_translations table.
	 * @param object                   $lang          The language row.
	 * @param array<int|string, mixed> $entries       The batch's PO entries.
	 * @return array<int, array{0: string, 1: string|null}|null>
	 */
	private static function held_translations( string $strings_table, string $st_table, object $lang, array $entries ): array {
		global $wpdb;

		$language_id = self::language_id( $lang );

		if ( ! $wpdb instanceof \wpdb || $language_id <= 0 ) {
			return [];
		}

		$hashes = [];

		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof \Translation_Entry || ! is_string( $entry->singular ) || $entry->singular === '' ) {
				continue;
			}

			$domain = self::extract_domain( is_string( $entry->extracted_comments ) ? $entry->extracted_comments : '' );
			$forms  = self::entry_to_forms( $entry, $entry->singular, is_string( $entry->context ) ? $entry->context : '', $domain );

			foreach ( $forms as $form ) {
				if ( $form['translation'] !== '' ) {
					$hashes[ StringRepository::compute_hash( $domain, $form['context'], $form['original'] ) ] = true;
				}
			}
		}

		if ( $hashes === [] ) {
			return [];
		}

		$hashes = array_keys( $hashes );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per batch of an import; tables bound with %i, one %s placeholder per hash.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.id AS string_id, st.string_id AS held_id, st.translation, st.extra_forms
				 FROM %i s
				 LEFT JOIN %i st ON st.string_id = s.id AND st.language_id = %d
				 WHERE s.original_hash IN (' . implode( ', ', array_fill( 0, count( $hashes ), '%s' ) ) . ')',
				$strings_table,
				$st_table,
				$language_id,
				...$hashes
			),
			ARRAY_A
		);
		// phpcs:enable

		$held = [];

		foreach ( $rows as $row ) {
			$string_id = $row['string_id'] ?? null;

			if ( ! is_numeric( $string_id ) ) {
				continue;
			}

			$held[ (int) $string_id ] = null === ( $row['held_id'] ?? null ) ? null : self::held_pair( $row );
		}

		return $held;
	}

	/**
	 * What one string holds in a language, as held_translations() reports it.
	 *
	 * @param string $st_table  The string_translations table.
	 * @param object $lang      The language row.
	 * @param int    $string_id The string id.
	 * @return array{0: string, 1: string|null}|null
	 */
	private static function held_translation( string $st_table, object $lang, int $string_id ): ?array {
		global $wpdb;

		$language_id = self::language_id( $lang );

		if ( ! $wpdb instanceof \wpdb || $language_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A primary-key read before one import write; the table is bound with %i.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT translation, extra_forms FROM %i WHERE string_id = %d AND language_id = %d',
				$st_table,
				$string_id,
				$language_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::held_pair( $row ) : null;
	}

	/**
	 * A language row's id, 0 when it has none.
	 *
	 * @param object $lang The language row.
	 * @return int
	 */
	private static function language_id( object $lang ): int {
		$id = get_object_vars( $lang )['id'] ?? null;

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * The SHA-1 pair of a string_translations row.
	 *
	 * @param array<mixed> $row The row, with translation and extra_forms.
	 * @return array{0: string, 1: string|null}
	 */
	private static function held_pair( array $row ): array {
		$translation = $row['translation'] ?? '';
		$extra       = $row['extra_forms'] ?? null;

		return [ sha1( is_string( $translation ) ? $translation : '' ), is_string( $extra ) ? sha1( $extra ) : null ];
	}
}
