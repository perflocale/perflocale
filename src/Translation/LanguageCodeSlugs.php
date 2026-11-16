<?php
/**
 * Post slugs that equal a language's URL prefix at the site root, where the prefix takes the path.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: required by the slug filter (only when a slug is a language code), the language screens and Site Health.
 *
 * @since 1.0.8
 */
final class LanguageCodeSlugs {

	/**
	 * Whether language codes are path prefixes (subdirectory URLs): a root path equal to one reads as that language.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public static function applies( Settings $settings ): bool {
		return 'subdirectory' === $settings->get_url_mode();
	}

	/**
	 * Whether a post of this type and parent has the address /<slug>/: a top-level page, or a post under /%postname%/.
	 *
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent ID.
	 * @return bool
	 */
	public static function at_root( string $post_type, int $parent ): bool {
		if ( 0 !== $parent ) {
			return false;
		}

		$structure = get_option( 'permalink_structure' );

		return 'page' === $post_type || ( 'post' === $post_type && is_string( $structure ) && str_starts_with( $structure, '/%postname%' ) );
	}

	/**
	 * The root paths a language takes: its slug and, in locale form, its URL prefix (the slug form 301s to it).
	 *
	 * @param string   $slug     Language slug.
	 * @param string   $locale   Locale.
	 * @param Settings $settings Settings.
	 * @return array<int, string>
	 */
	public static function paths( string $slug, string $locale, Settings $settings ): array {
		$prefix = $settings->get_url_prefix(
			(object) [
				'slug'   => $slug,
				'locale' => $locale,
			]
		);

		return array_values( array_unique( array_filter( [ $slug, $prefix ], static fn( string $p ): bool => '' !== $p ) ) );
	}

	/**
	 * Every language's root paths, inactive ones too (activating one must not hide a page).
	 *
	 * @param LanguageRepository $repo     Languages.
	 * @param Settings           $settings Settings.
	 * @return array<int, string>
	 */
	public static function root_paths( LanguageRepository $repo, Settings $settings ): array {
		$out = [];

		foreach ( $repo->find_all() as $lang ) {
			if ( is_string( $lang->slug ?? null ) && is_string( $lang->locale ?? null ) ) {
				$out = array_merge( $out, self::paths( $lang->slug, $lang->locale, $settings ) );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The first of $paths a published root post holds and why it is refused, or [ '', '' ].
	 *
	 * @param array<int, string> $paths    Root paths.
	 * @param Settings           $settings Settings.
	 * @return array{0: string, 1: string}
	 */
	public static function clash( array $paths, Settings $settings ): array {
		foreach ( $paths as $path ) {
			$text = self::refusal( $path, $settings );

			if ( '' !== $text ) {
				return [ $path, $text ];
			}
		}

		return [ '', '' ];
	}

	/**
	 * Why root path $code cannot become an active language's: the root posts it would hide, or '' when none.
	 *
	 * @param string   $code     Root path (a slug or a URL prefix).
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function refusal( string $code, Settings $settings ): string {
		$posts = self::holders( $code, $settings, 3 );

		if ( [] === $posts ) {
			return '';
		}

		/* translators: 1: post title, 2: post ID. */
		$names = implode( ', ', array_map( static fn( \WP_Post $p ): string => sprintf( __( '"%1$s" (#%2$d)', 'perflocale' ), get_the_title( $p ), $p->ID ), $posts ) );

		/* translators: 1: root path (a language code or URL prefix), 2: post titles. */
		return sprintf( __( 'The address /%1$s/ belongs to %2$s. A language at "/%1$s/" would take it over: change that slug first, or use another code or locale.', 'perflocale' ), $code, $names );
	}

	/**
	 * Import report lines: each published root post an active language's prefix takes, in one query (nothing refused).
	 *
	 * @param array<string, string> $names    Root path => language name.
	 * @param Settings              $settings Settings.
	 * @return list<string>
	 */
	public static function clash_notes( array $names, Settings $settings ): array {
		$notes = [];

		foreach ( self::rows( array_map( 'strval', array_keys( $names ) ), $settings, 20 ) as $post ) {
			$label = get_post_type_object( $post->post_type )?->labels->singular_name ?? null;

			/* translators: 1: post type name, 2: the post's slug (a language code), 3: post ID, 4: language name. */
			$notes[] = sprintf( __( '%1$s \'%2$s\' (ID %3$d) now clashes with the %4$s URL prefix; rename it.', 'perflocale' ), is_string( $label ) ? $label : $post->post_type, $post->post_name, $post->ID, $names[ $post->post_name ] ?? $post->post_name );
		}

		return $notes;
	}

	/**
	 * Published root posts (any language) whose slug is $code: the language prefix takes their URL.
	 *
	 * @param string   $code     Root path.
	 * @param Settings $settings Settings.
	 * @param int      $limit    Rows at most.
	 * @return array<int, \WP_Post>
	 */
	public static function holders( string $code, Settings $settings, int $limit = 5 ): array {
		return self::rows( [ $code ], $settings, $limit );
	}

	/**
	 * Published root posts whose slug is one of $codes (the post_name index); a type not translated counts too.
	 *
	 * @param array<int, string> $codes    Root paths.
	 * @param Settings           $settings Settings.
	 * @param int                $limit    Rows at most.
	 * @return array<int, \WP_Post>
	 */
	private static function rows( array $codes, Settings $settings, int $limit ): array {
		global $wpdb;

		$codes = array_values( array_filter( $codes, static fn( string $c ): bool => '' !== $c ) );

		// No Schema::tables_exist(): only the posts table is read, and a new site's setup request may hold an early "no".
		if ( [] === $codes || ! self::applies( $settings ) || ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$types = array_values( array_filter( [ 'page', 'post' ], static fn( string $t ): bool => self::at_root( $t, 0 ) ) );

		if ( [] === $types ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID FROM %i p WHERE p.post_name IN (' . implode( ',', array_fill( 0, count( $codes ), '%s' ) ) . ") AND p.post_parent = 0 AND p.post_status IN ( 'publish', 'future', 'private' ) AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ORDER BY p.ID LIMIT %d',
				array_merge( [ $wpdb->posts ], $codes, $types, [ $limit ] )
			)
		);
		// phpcs:enable

		$posts = [];

		foreach ( (array) $ids as $id ) {
			$post = is_numeric( $id ) ? get_post( (int) $id ) : null;

			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}
}
