<?php
/**
 * Default-language and missing-language checks for Polylang and TranslatePress imports.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Database\Repository\LanguageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares the source plugin's languages with PerfLocale's before an import.
 *
 * Read-only. The first import from a source is refused (`blocking`) when
 * the source's default language is not PerfLocale's default language, or
 * when the source has content in a language PerfLocale does not have:
 * importing then would move every page to another address, or leave that
 * content without a language in the default-language listings. Once an
 * import from the source has run on the blog, the same findings are only
 * warnings, so a catch-up import is never refused.
 *
 * Reads only the source's settings option and its language rows; runs when
 * an import or a dry run starts, never on a page view.
 */
final class SourcePreflight {

	/**
	 * Check a Polylang import.
	 *
	 * @param LanguageRepository $languages PerfLocale languages.
	 * @return array{blocking: bool, first_import: bool, default: string, map: array<string, string|null>, content: array<string, int>, locales: array<string, string>, problems: list<array{level: string, code: string, message: string}>}
	 */
	public static function polylang( LanguageRepository $languages ): array {
		global $wpdb;

		$settings = get_option( 'polylang', null );
		$found    = is_array( $settings );
		$default  = $found && is_string( $settings['default_lang'] ?? null ) ? (string) $settings['default_lang'] : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off read when an import starts; nothing to cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.slug, tt.taxonomy, tt.description, COUNT( tr.object_id ) AS items
				FROM %i t
				INNER JOIN %i tt ON tt.term_id = t.term_id
				LEFT JOIN %i tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy IN ( %s, %s )
				GROUP BY tt.term_taxonomy_id, t.slug, tt.taxonomy, tt.description',
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$wpdb->term_relationships,
				'language',
				'term_language'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$locales = [];
		$content = [];

		foreach ( (array) $rows as $row ) {
			$slug = (string) $row->slug;

			if ( $row->taxonomy === 'term_language' ) {
				$slug = str_starts_with( $slug, 'pll_' ) ? substr( $slug, 4 ) : $slug;
			} else {
				$locales[ $slug ] = self::polylang_locale( (string) $row->description );
			}

			$content[ $slug ] = ( $content[ $slug ] ?? 0 ) + (int) $row->items;
		}

		$active = $languages->get_active();
		$map    = [];

		foreach ( array_keys( $content + $locales ) as $slug ) {
			$slug  = (string) $slug;
			$match = null;

			if ( ( $locales[ $slug ] ?? '' ) !== '' ) {
				foreach ( $active as $lang ) {
					if ( self::normalize_locale( (string) $lang->locale ) === self::normalize_locale( $locales[ $slug ] ) ) {
						$match = (string) $lang->slug;
						break;
					}
				}
			}

			if ( $match === null ) {
				foreach ( $active as $lang ) {
					if ( (string) $lang->slug === $slug ) {
						$match = (string) $lang->slug;
						break;
					}
				}
			}

			$map[ $slug ] = $match;
		}

		return self::result( 'Polylang', 'polylang', $languages, $found, $default, $map, $content, $locales );
	}

	/**
	 * Check a TranslatePress import.
	 *
	 * @param LanguageRepository $languages PerfLocale languages.
	 * @return array{blocking: bool, first_import: bool, default: string, map: array<string, string|null>, content: array<string, int>, locales: array<string, string>, problems: list<array{level: string, code: string, message: string}>}
	 */
	public static function translatepress( LanguageRepository $languages ): array {
		global $wpdb;

		$settings = get_option( 'trp_settings', null );
		$found    = is_array( $settings );
		$default  = $found && is_string( $settings['default-language'] ?? null ) ? (string) $settings['default-language'] : '';
		$targets  = $found && is_array( $settings['translation-languages'] ?? null ) ? $settings['translation-languages'] : [];
		// A language TranslatePress had not published is imported only into a
		// switched-off PerfLocale language, or skipped with a notice, by the
		// importer; it is never a missing language here.
		$published = $found && is_array( $settings['publish-languages'] ?? null ) ? array_map( 'strval', $settings['publish-languages'] ) : null;
		$active    = $languages->get_active();
		$map       = [];
		$content   = [];
		$locales   = [];

		foreach ( array_unique( array_merge( $default !== '' ? [ $default ] : [], array_map( 'strval', $targets ) ) ) as $locale ) {
			$locale = (string) $locale;

			if ( $locale === '' ) {
				continue;
			}

			$locales[ $locale ] = $locale;
			$map[ $locale ]     = self::translatepress_match( $locale, $active );

			if ( $locale === $default || $default === '' || ( $published !== null && ! in_array( $locale, $published, true ) ) ) {
				continue;
			}

			$table = $wpdb->prefix . 'trp_dictionary_' . strtolower( str_replace( '-', '_', $default ) ) . '_' . strtolower( str_replace( '-', '_', $locale ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off read when an import starts; nothing to cache.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}

			$has = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE translated <> '' LIMIT 1", $table ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$content[ $locale ] = $has !== null ? 1 : 0;
		}

		return self::result( 'TranslatePress', 'translatepress', $languages, $found, $default, $map, $content, $locales );
	}

	/**
	 * The refused import's result, in the shape the importers return.
	 *
	 * @param array{problems: list<array{level: string, code: string, message: string}>} $preflight Preflight.
	 * @return array{posts: int, terms: int, strings: int, slugs: int, errors: list<string>, blocked: bool, preflight: array<string, mixed>}
	 */
	public static function blocked_result( array $preflight ): array {
		return [
			'posts'     => 0,
			'terms'     => 0,
			'strings'   => 0,
			'slugs'     => 0,
			'errors'    => self::messages( $preflight ),
			'blocked'   => true,
			'preflight' => $preflight,
		];
	}

	/**
	 * Problem messages, errors first.
	 *
	 * @param array{problems: list<array{level: string, code: string, message: string}>} $preflight Preflight.
	 * @return list<string>
	 */
	public static function messages( array $preflight ): array {
		$out = [];

		foreach ( [ 'error', 'warning' ] as $level ) {
			foreach ( $preflight['problems'] as $problem ) {
				if ( $problem['level'] === $level ) {
					$out[] = $problem['message'];
				}
			}
		}

		return $out;
	}

	/**
	 * Build the problems list and the blocking flag.
	 *
	 * @param string                     $name           Source plugin name for messages.
	 * @param string                     $source         MigrationState source key.
	 * @param LanguageRepository         $languages      PerfLocale languages.
	 * @param bool                       $found          Whether the source's settings were read.
	 * @param string                     $source_default Source default language code.
	 * @param array<string, string|null> $map            Source code => PerfLocale slug, or null.
	 * @param array<string, int>         $content        Source code => items in that language.
	 * @param array<string, string>      $locales        Source code => locale.
	 * @return array{blocking: bool, first_import: bool, default: string, map: array<string, string|null>, content: array<string, int>, locales: array<string, string>, problems: list<array{level: string, code: string, message: string}>}
	 */
	private static function result( string $name, string $source, LanguageRepository $languages, bool $found, string $source_default, array $map, array $content, array $locales ): array {
		$first    = ! MigrationState::is_imported( $source );
		$pl_def   = $languages->get_default();
		$pl_slug  = $pl_def !== null ? (string) $pl_def->slug : '';
		$problems = [];

		$label = static function ( string $code ) use ( $locales ): string {
			return ( $locales[ $code ] ?? '' ) !== '' && $locales[ $code ] !== $code ? $code . ' (' . $locales[ $code ] . ')' : $code;
		};

		if ( ! $found || $source_default === '' ) {
			$problems[] = [
				'level'   => 'warning',
				'code'    => 'settings_missing',
				'message' => sprintf(
					/* translators: %s: source plugin name, e.g. "Polylang" */
					__( '%1$s\'s settings could not be read, so its default language could not be checked. Make sure PerfLocale\'s default language is the one %1$s used.', 'perflocale' ),
					$name
				),
			];
		} elseif ( ( $map[ $source_default ] ?? null ) !== $pl_slug ) {
			$problems[] = [
				'level'   => 'error',
				'code'    => 'default_mismatch',
				'message' => sprintf(
					/* translators: 1: source plugin name, 2: its default language, e.g. "fr (fr_FR)", 3: PerfLocale default language slug */
					__( '%1$s\'s default language is %2$s, but PerfLocale\'s default language is %3$s. Make %2$s the default language under PerfLocale → Languages, then run the import again. Importing now would change the address of every page.', 'perflocale' ),
					$name,
					$label( $source_default ),
					$pl_slug
				),
			];
		}

		foreach ( $content as $code => $items ) {
			$code = (string) $code;

			if ( $items <= 0 || ( $map[ $code ] ?? null ) !== null ) {
				continue;
			}

			$problems[] = [
				'level'   => 'error',
				'code'    => 'missing_language',
				'message' => sprintf(
					/* translators: 1: source plugin name, 2: language code and locale, e.g. "ar (ar)" */
					__( '%1$s has content in %2$s, which has no matching PerfLocale language. Add it under PerfLocale → Languages, then run the import again.', 'perflocale' ),
					$name,
					$label( $code )
				),
			];
		}

		$blocking = false;

		foreach ( $problems as $problem ) {
			if ( $problem['level'] === 'error' ) {
				$blocking = $first;
				break;
			}
		}

		return [
			'blocking'     => $blocking,
			'first_import' => $first,
			'default'      => $source_default,
			'map'          => $map,
			'content'      => $content,
			'locales'      => $locales,
			'problems'     => $problems,
		];
	}

	/**
	 * TranslatePress locale => PerfLocale slug: exact locale, then slug prefix (the importer's order).
	 *
	 * @param string        $locale TranslatePress locale.
	 * @param array<object> $active Active PerfLocale languages.
	 * @return string|null
	 */
	private static function translatepress_match( string $locale, array $active ): ?string {
		foreach ( $active as $lang ) {
			if ( (string) $lang->locale === $locale ) {
				return (string) $lang->slug;
			}
		}

		foreach ( $active as $lang ) {
			if ( (string) $lang->slug !== '' && str_starts_with( $locale, (string) $lang->slug ) ) {
				return (string) $lang->slug;
			}
		}

		return null;
	}

	/**
	 * The locale in a Polylang `language` term description (a serialized array).
	 *
	 * @param string $description Term description.
	 * @return string
	 */
	private static function polylang_locale( string $description ): string {
		if ( $description === '' || ! is_serialized( $description ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false blocks object injection, as in PolylangImporter.
		$meta = @unserialize( $description, [ 'allowed_classes' => false ] );

		return is_array( $meta ) && is_string( $meta['locale'] ?? null ) ? sanitize_text_field( $meta['locale'] ) : '';
	}

	/**
	 * Lowercase, hyphens to underscores.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private static function normalize_locale( string $locale ): string {
		return strtolower( str_replace( '-', '_', $locale ) );
	}
}
