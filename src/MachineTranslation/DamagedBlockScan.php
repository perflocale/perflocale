<?php
/**
 * Read-only scan for translations with damaged block markup.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds post translations (linked posts whose language is not the default
 * language) whose post_content holds damaged block markup, as
 * BlockStructureGuard::damage() counts it against the default-language source
 * of their group: escaped or left-over block-delimiter text and unbalanced
 * delimiters when the translation holds more of a kind than the source (an
 * article about block markup holds escaped delimiters in every language), and
 * blocks that lost comment attributes their source block has.
 *
 * Writes nothing. Reads the link table by keyset in batches, and per batch only
 * the ID, type and content of the translations in it and of their sources.
 */
final class DamagedBlockScan {

	/**
	 * Words for each kind of damage.
	 */
	private const KINDS = [
		'escaped'    => 'escaped block delimiter',
		'text'       => 'block delimiter left as text',
		'unbalanced' => 'unbalanced block delimiters',
		'attributes' => 'lost block attributes',
	];

	/**
	 * One page of the scan: the links after a cursor, at most $batch of them.
	 *
	 * Memory is bounded by the page: it holds the page's links, the content of
	 * its translations and of their sources, and the rows found. Pass `next`
	 * back as the cursor for the following page; it is null after the last.
	 *
	 * @param LanguageRepository $langs     Language repository.
	 * @param int                $cursor    Last link ID read (0 = from the start).
	 * @param int                $batch     Links read (1-1000).
	 * @param string             $lang_slug Only this language ('' = every non-default language).
	 * @param string             $post_type Only this post type ('' = every type).
	 * @return array{rows: array<int, array{post_id: int, language: string, source_id: int, issue: string, spot: string}>, next: int|null}
	 *
	 * @throws \InvalidArgumentException When the language is unknown.
	 * @throws \RuntimeException When a read fails (the page is not reported, nothing past it is read).
	 */
	public static function page( LanguageRepository $langs, int $cursor, int $batch, string $lang_slug = '', string $post_type = '' ): array {
		$done = [
			'rows' => [],
			'next' => null,
		];
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return $done;
		}

		$slugs = [];

		// Every language, read past the repository's cached list of all
		// languages: without a persistent object cache that list is stored in
		// a transient, a write this read-only scan must not make.
		foreach ( $langs->find_all( [ 'orderby' => 'id' ] ) as $l ) {
			$vars                                      = get_object_vars( $l );
			$slugs[ self::int( $vars['id'] ?? null ) ] = self::str( $vars['slug'] ?? null );
		}

		$only_lang = 0;

		if ( '' !== $lang_slug ) {
			$only_lang = (int) array_search( $lang_slug, $slugs, true );

			if ( $only_lang <= 0 ) {
				throw new \InvalidArgumentException( esc_html( 'Unknown language: ' . $lang_slug ) );
			}
		}

		$default    = $langs->get_default();
		$default_id = $default ? self::int( get_object_vars( $default )['id'] ?? null ) : 0;
		$batch      = max( 1, min( 1000, $batch ) );
		$links      = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Read-only keyset page; the query is built from literals only and every value is bound through prepare().
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, group_id, object_id, language_id FROM %i WHERE type = 'post' AND id > %d AND language_id <> %d"
					. ( $only_lang > 0 ? ' AND language_id = %d' : ' AND %d = 0' )
					. ' ORDER BY id ASC LIMIT %d',
				$links,
				max( 0, $cursor ),
				$default_id,
				$only_lang,
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable

		self::check_read( $wpdb, $found );

		if ( ! is_array( $found ) || [] === $found ) {
			return $done;
		}

		$page = [];

		foreach ( $found as $r ) {
			$page[] = [
				'id'       => self::int( $r['id'] ?? null ),
				'group'    => self::int( $r['group_id'] ?? null ),
				'object'   => self::int( $r['object_id'] ?? null ),
				'language' => self::int( $r['language_id'] ?? null ),
			];
		}

		unset( $found );

		$out = [
			'rows' => [],
			'next' => count( $page ) < $batch ? null : max( array_column( $page, 'id' ) ),
		];

		$contents = self::contents( $wpdb, array_column( $page, 'object' ), $post_type );

		if ( [] === $contents ) {
			return $out;
		}

		$sources = self::sources( $wpdb, $links, $default_id, array_values( array_unique( array_column( $page, 'group' ) ) ) );
		$schemas = self::schemas();

		foreach ( $page as $link ) {
			$content = $contents[ $link['object'] ] ?? null;

			if ( null === $content ) {
				continue;
			}

			[ $source_id, $source_content ] = $sources[ $link['group'] ] ?? [ 0, null ];
			$damage                         = BlockStructureGuard::damage( $content, $source_content, $schemas );

			if ( null === $damage['first'] ) {
				continue;
			}

			$source_damage = null === $source_content ? null : BlockStructureGuard::damage( $source_content );
			$spot          = null;

			foreach ( array_keys( self::KINDS ) as $kind ) {
				if ( $damage[ $kind ] > ( null === $source_damage ? 0 : $source_damage[ $kind ] ) && isset( $damage['at'][ $kind ] ) && ( null === $spot || $damage['at'][ $kind ]['offset'] < $spot['offset'] ) ) {
					$spot = $damage['at'][ $kind ];
				}
			}

			if ( null === $spot ) {
				continue;
			}

			$offset        = $spot['offset'];
			$lost          = isset( $spot['keys'] ) ? 'lost ' . implode( ', ', $spot['keys'] ) . ' at ' : '';
			$out['rows'][] = [
				'post_id'   => $link['object'],
				'language'  => $slugs[ $link['language'] ] ?? (string) $link['language'],
				'source_id' => $source_id,
				'issue'     => self::KINDS[ $spot['kind'] ] ?? $spot['kind'],
				'spot'      => $lost . 'byte ' . $offset . ': ' . str_replace( [ "\r", "\n" ], [ '\r', '\n' ], substr( $content, $offset, 60 ) ),
			];
		}

		return $out;
	}

	/**
	 * Content of the given posts, of one type or any.
	 *
	 * @param \wpdb      $wpdb      Database.
	 * @param array<int> $ids       Post IDs.
	 * @param string     $post_type Type filter ('' = any).
	 * @return array<int, string> ID => content.
	 */
	private static function contents( \wpdb $wpdb, array $ids, string $post_type ): array {
		$out = [];
		$in  = implode( ',', array_map( 'intval', $ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The IN list holds integers only; the table name is core's.
		$found = $wpdb->get_results( "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE ID IN ($in)", ARRAY_A );

		self::check_read( $wpdb, $found );

		foreach ( is_array( $found ) ? $found : [] as $p ) {
			if ( '' === $post_type || $post_type === self::str( $p['post_type'] ?? null ) ) {
				$out[ self::int( $p['ID'] ?? null ) ] = self::str( $p['post_content'] ?? null );
			}
		}

		return $out;
	}

	/**
	 * The default-language member of each group and its content.
	 *
	 * @param \wpdb      $wpdb       Database.
	 * @param string     $links      Link table.
	 * @param int        $default_id Default language ID.
	 * @param array<int> $groups     Group IDs.
	 * @return array<int, array{0: int, 1: string}> Group ID => [ source post ID, source content ].
	 */
	private static function sources( \wpdb $wpdb, string $links, int $default_id, array $groups ): array {
		$out = [];
		$in  = implode( ',', array_map( 'intval', $groups ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table names are the plugin's and core's, the language id and the IN list are integers.
		$found = $wpdb->get_results( 'SELECT l.group_id, l.object_id, p.post_content FROM `' . esc_sql( $links ) . "` l INNER JOIN {$wpdb->posts} p ON p.ID = l.object_id WHERE l.type = 'post' AND l.language_id = " . (int) $default_id . " AND l.group_id IN ($in)", ARRAY_A );

		self::check_read( $wpdb, $found );

		foreach ( is_array( $found ) ? $found : [] as $s ) {
			$out[ self::int( $s['group_id'] ?? null ) ] = [ self::int( $s['object_id'] ?? null ), self::str( $s['post_content'] ?? null ) ];
		}

		return $out;
	}

	/**
	 * Attribute schema of every registered block type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$out = [];

		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return $out;
		}

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( ! is_array( $type->attributes ) ) {
				continue;
			}

			$attrs = [];

			foreach ( $type->attributes as $key => $def ) {
				$attrs[ (string) $key ] = $def;
			}

			$out[ (string) $name ] = $attrs;
		}

		return $out;
	}

	/**
	 * Stop on a read that failed. A failed read is an error, never an empty
	 * result: wpdb answers it with no rows and sets last_error (cleared at
	 * the start of every query).
	 *
	 * @param \wpdb $wpdb  Database.
	 * @param mixed $found What the read returned.
	 * @return void
	 * @throws \RuntimeException When the read failed.
	 */
	private static function check_read( \wpdb $wpdb, $found ): void {
		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html( 'The scan stopped because a database read failed. Run the command again.' ) );
		}
	}

	/**
	 * A database or object value as an integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A database or object value as a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
