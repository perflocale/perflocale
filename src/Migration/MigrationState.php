<?php
/**
 * Which migration sources have been imported on this blog.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records, per blog, that an import from a source plugin has run.
 *
 * The stamp lives in one option that is not autoloaded. A site that
 * imported with an older PerfLocale has no stamp; is_imported() then looks
 * for the rows that import left (source-map rows, or links it wrote) and
 * writes the stamp when it finds them, so that lookup runs once.
 *
 * The stamp means "an import ran". Whether the last run imported
 * everything is kept apart, under {@see INCOMPLETE}: a run that left part
 * of the source data not imported (a failed read or write) sets it, a run
 * that finished clears it.
 */
final class MigrationState {

	/**
	 * Option holding `[ source => [ 'imported_at' => int, 'version' => string ] ]`,
	 * plus the import flags under {@see FLAGS} and the unfinished imports
	 * under {@see INCOMPLETE}.
	 */
	public const OPTION = 'perflocale_migration_state';

	/**
	 * State key holding `[ source => [ flag => time ] ]`: what an import did
	 * once and must not repeat on a later run.
	 */
	private const FLAGS = 'flags';

	/**
	 * State key holding `[ source => time ]`: sources whose last import did
	 * not finish.
	 */
	private const INCOMPLETE = 'incomplete';

	/**
	 * Source type => [ migration_source_map type, translation_links source ].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const SOURCES = [
		'wpml'           => [ 'wpml', 'imported_wpml' ],
		'polylang'       => [ 'polylang', 'imported_polylang' ],
		'translatepress' => [ 'trp', 'imported_trp' ],
	];

	/**
	 * Blog ID => unimported_sources() result, for the request.
	 *
	 * @var array<int, list<string>>
	 */
	private static array $unimported = [];

	/**
	 * Blog ID => incomplete_sources() result, for the request.
	 *
	 * @var array<int, list<string>>
	 */
	private static array $incomplete = [];

	/**
	 * Record that an import from the source ran on this blog.
	 *
	 * @param string $source Source type: wpml, polylang or translatepress.
	 * @return void
	 */
	public static function mark_imported( string $source ): void {
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			return;
		}

		$state = self::read();

		$state[ $source ] = [
			'imported_at' => time(),
			'version'     => defined( 'PERFLOCALE_VERSION' ) ? (string) PERFLOCALE_VERSION : '',
		];

		update_option( self::OPTION, $state, false );

		self::$unimported = [];
	}

	/**
	 * Record whether the last import from the source finished.
	 *
	 * Writes only when the recorded outcome changes.
	 *
	 * @param string $source   Source type: wpml, polylang or translatepress.
	 * @param bool   $finished False when the run left part of the source data not imported.
	 * @return void
	 */
	public static function record_outcome( string $source, bool $finished ): void {
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			return;
		}

		$state      = self::read();
		$incomplete = is_array( $state[ self::INCOMPLETE ] ?? null ) ? $state[ self::INCOMPLETE ] : [];

		if ( $finished === ! isset( $incomplete[ $source ] ) ) {
			return;
		}

		if ( $finished ) {
			unset( $incomplete[ $source ] );
		} else {
			$incomplete[ $source ] = time();
		}

		if ( $incomplete === [] ) {
			unset( $state[ self::INCOMPLETE ] );
		} else {
			$state[ self::INCOMPLETE ] = $incomplete;
		}

		update_option( self::OPTION, $state, false );

		self::$incomplete = [];
	}

	/**
	 * True when the last import from the source did not finish.
	 *
	 * @param string $source Source type: wpml, polylang or translatepress.
	 * @return bool
	 */
	public static function is_incomplete( string $source ): bool {
		$state      = self::read();
		$incomplete = $state[ self::INCOMPLETE ] ?? null;

		return is_array( $incomplete ) && isset( $incomplete[ $source ] );
	}

	/**
	 * True when an import from the source has run on this blog.
	 *
	 * @param string $source Source type: wpml, polylang or translatepress.
	 * @return bool
	 */
	public static function is_imported( string $source ): bool {
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			return false;
		}

		$state = self::read();

		if ( isset( $state[ $source ] ) ) {
			return true;
		}

		if ( ! self::has_import_rows( self::SOURCES[ $source ][0], self::SOURCES[ $source ][1] ) ) {
			return false;
		}

		self::mark_imported( $source );

		return true;
	}

	/**
	 * Record that an import from the source did something it does once.
	 *
	 * @param string $source Source type: wpml, polylang or translatepress.
	 * @param string $flag   Flag name.
	 * @return void
	 */
	public static function set_flag( string $source, string $flag ): void {
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			return;
		}

		$state = self::read();
		$flags = is_array( $state[ self::FLAGS ] ?? null ) ? $state[ self::FLAGS ] : [];

		$flags[ $source ]          = is_array( $flags[ $source ] ?? null ) ? $flags[ $source ] : [];
		$flags[ $source ][ $flag ] = time();
		$state[ self::FLAGS ]      = $flags;

		update_option( self::OPTION, $state, false );
	}

	/**
	 * True when set_flag() recorded the flag for the source on this blog.
	 *
	 * @param string $source Source type: wpml, polylang or translatepress.
	 * @param string $flag   Flag name.
	 * @return bool
	 */
	public static function has_flag( string $source, string $flag ): bool {
		$state = self::read();
		$flags = $state[ self::FLAGS ] ?? null;

		return is_array( $flags ) && is_array( $flags[ $source ] ?? null ) && isset( $flags[ $source ][ $flag ] );
	}

	/**
	 * Sources whose translation data is on this blog but has not been imported.
	 *
	 * A source counts only when its data holds at least one translation, so
	 * a site whose leftover tables hold a single language is never reported:
	 * WPML a row with a source language in `icl_translations`, Polylang a
	 * `post_translations` or `term_translations` group with two or more
	 * members, TranslatePress a translated row in a dictionary table of one
	 * of its languages.
	 * Memoised per blog for the request; recording an import clears it.
	 *
	 * @return list<string>
	 */
	public static function unimported_sources(): array {
		$blog_id = get_current_blog_id();

		if ( isset( self::$unimported[ $blog_id ] ) ) {
			return self::$unimported[ $blog_id ];
		}

		$out = [];

		// The source data is checked first: those reads are indexed or end
		// at a missing table, so a site that never ran the plugin never
		// reaches is_imported()'s lookup on translation_links.source.
		if ( self::wpml_has_translations() && ! self::is_imported( 'wpml' ) ) {
			$out[] = 'wpml';
		}

		if ( self::polylang_has_translations() && ! self::is_imported( 'polylang' ) ) {
			$out[] = 'polylang';
		}

		if ( self::translatepress_has_translations() && ! self::is_imported( 'translatepress' ) ) {
			$out[] = 'translatepress';
		}

		self::$unimported[ $blog_id ] = $out;

		return $out;
	}

	/**
	 * Sources whose last import did not finish and whose data is still on this blog.
	 *
	 * Memoised per blog for the request; recording an outcome clears it.
	 *
	 * @return list<string>
	 */
	public static function incomplete_sources(): array {
		$blog_id = get_current_blog_id();

		if ( isset( self::$incomplete[ $blog_id ] ) ) {
			return self::$incomplete[ $blog_id ];
		}

		$out = [];

		// The stamp first: the source-data reads run only for a source that
		// has an unfinished import.
		foreach ( array_keys( self::SOURCES ) as $source ) {
			if ( ! self::is_incomplete( $source ) ) {
				continue;
			}

			$has_data = match ( $source ) {
				'wpml'     => self::wpml_has_translations(),
				'polylang' => self::polylang_has_translations(),
				default    => self::translatepress_has_translations(),
			};

			if ( $has_data ) {
				$out[] = $source;
			}
		}

		self::$incomplete[ $blog_id ] = $out;

		return $out;
	}

	/**
	 * The notice for sources whose last import did not finish.
	 *
	 * @param string[] $sources Source keys.
	 * @return string
	 */
	public static function incomplete_message( array $sources ): string {
		return sprintf(
			/* translators: %s: source plugin name(s), e.g. "TranslatePress" or "WPML and Polylang" */
			__( 'The last %s import did not finish, so some of its translations are not imported yet. Run the import again (Settings → Export & Import) before you use the bulk translation tools: they give translations that are not imported the default language.', 'perflocale' ),
			self::source_names( $sources )
		);
	}

	/**
	 * Display names of sources, as a list in the site's language (e.g. "WPML and Polylang").
	 *
	 * @param string[] $sources Source keys.
	 * @return string
	 */
	public static function source_names( array $sources ): string {
		$names = [
			'wpml'           => 'WPML',
			'polylang'       => 'Polylang',
			'translatepress' => 'TranslatePress',
		];

		return wp_sprintf( '%l', array_map( static fn( string $s ): string => $names[ $s ] ?? $s, $sources ) );
	}

	/**
	 * True when WPML's icl_translations table exists and holds a translation.
	 *
	 * @return bool
	 */
	private static function wpml_has_translations(): bool {
		global $wpdb;

		$reader = new WpmlSettingsReader();
		$table  = $wpdb->prefix . 'icl_translations';

		if ( ! $reader->table_exists( $table ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE source_language_code IS NOT NULL AND element_id IS NOT NULL LIMIT 1',
				$table
			)
		);

		return $found !== null;
	}

	/**
	 * True when Polylang left a translation group with two or more members.
	 *
	 * The group is a `post_translations` or `term_translations` term whose
	 * description is the serialized language => object map. Polylang gives
	 * every term a group, also an untranslated one, so a one-member map
	 * (`a:1:{…}`) is not a translation.
	 *
	 * @return bool
	 */
	private static function polylang_has_translations(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check behind the per-request memo.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE taxonomy IN ( %s, %s ) AND description LIKE %s AND description NOT LIKE %s AND description NOT LIKE %s LIMIT 1',
				$wpdb->term_taxonomy,
				'post_translations',
				'term_translations',
				'a:%',
				'a:0:%',
				'a:1:%'
			)
		);

		return $found !== null;
	}

	/**
	 * True when a TranslatePress dictionary table of one of its translation
	 * languages holds a translated row.
	 *
	 * @return bool
	 */
	private static function translatepress_has_translations(): bool {
		global $wpdb;

		$settings = get_option( 'trp_settings', null );

		if ( ! is_array( $settings ) || ! is_string( $settings['default-language'] ?? null ) || ! is_array( $settings['translation-languages'] ?? null ) ) {
			return false;
		}

		$default = strtolower( str_replace( '-', '_', $settings['default-language'] ) );
		$reader  = new WpmlSettingsReader();

		foreach ( $settings['translation-languages'] as $locale ) {
			$locale = strtolower( str_replace( '-', '_', (string) $locale ) );

			if ( $locale === '' || $locale === $default ) {
				continue;
			}

			$table = $wpdb->prefix . 'trp_dictionary_' . $default . '_' . $locale;

			if ( ! $reader->table_exists( $table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check behind the per-request memo.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE translated <> '' LIMIT 1", $table ) );

			if ( $found !== null ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The stored state, or an empty array.
	 *
	 * @return array<string, mixed>
	 */
	private static function read(): array {
		$state = get_option( self::OPTION, [] );

		if ( ! is_array( $state ) ) {
			return [];
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
		/** @var array<string, mixed> $state */
		return $state;
	}

	/**
	 * True when an earlier import left a source-map row or a link it wrote.
	 *
	 * @param string $map_type    migration_source_map.migration_type.
	 * @param string $link_source translation_links.source.
	 * @return bool
	 */
	private static function has_import_rows( string $map_type, string $link_source ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A once-per-site lookup behind a stamp; nothing to cache.
		$mapped = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE migration_type = %s LIMIT 1',
				Schema::table( 'migration_source_map' ),
				$map_type
			)
		);

		if ( $mapped !== null ) {
			return true;
		}

		$linked = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE source = %s LIMIT 1',
				Schema::table( 'translation_links' ),
				$link_source
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $linked !== null;
	}
}
