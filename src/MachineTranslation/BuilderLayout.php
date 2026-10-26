<?php
/**
 * Page builder layouts that machine translation does not send.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builder add-ons declare their document keys on perflocale/mt/builder_documents; read only when machine
 * translation or its estimate runs.
 */
final class BuilderLayout {

	/**
	 * Stored values that hold no layout.
	 */
	private const EMPTY_VALUES = [ '', '[]', '{}', 'a:0:{}', 'null' ];

	/**
	 * Longest document checked for an empty root; longer ones count as a layout.
	 */
	private const ROOT_MAX = 8192;

	/**
	 * Declared builders, malformed entries dropped.
	 *
	 * @return array<int, array{name: string, keys: array<int, string>, mode?: array{0: string, 1: string}, always?: true}>
	 */
	public static function declared(): array {
		/**
		 * Page builders whose layout lives in their own post meta, which machine translation does not send.
		 *
		 * @hook perflocale/mt/builder_documents
		 * @since 1.0.7.4
		 * @param array $builders Entries: name (shown in the warning), keys (document meta keys), optional mode
		 *                        [meta key, value] when the builder renders the post only while that meta holds that value,
		 *                        optional always (true) when it renders any document that holds a layout.
		 */
		$raw = apply_filters( 'perflocale/mt/builder_documents', [] );
		$out = [];

		foreach ( (array) $raw as $entry ) {
			$name = is_array( $entry ) && is_string( $entry['name'] ?? null ) ? $entry['name'] : '';
			$keys = is_array( $entry ) && is_array( $entry['keys'] ?? null ) ? array_values( array_filter( $entry['keys'], 'is_string' ) ) : [];

			if ( '' === $name || [] === $keys ) {
				continue;
			}

			$builder = [
				'name' => $name,
				'keys' => $keys,
			];
			$mode    = $entry['mode'] ?? null;

			if ( is_array( $mode ) && is_string( $mode[0] ?? null ) && is_scalar( $mode[1] ?? null ) ) {
				$builder['mode'] = [ $mode[0], (string) $mode[1] ];
			}

			if ( true === ( $entry['always'] ?? null ) ) {
				$builder['always'] = true;
			}

			$out[] = $builder;
		}

		return $out;
	}

	/**
	 * The builder whose layout a post's machine translation leaves out, or ''.
	 *
	 * @param int    $post_id Source post.
	 * @param string $content Its post_content.
	 * @return string
	 */
	public static function pending( int $post_id, string $content ): string {
		$builders = self::declared();
		$meta     = [];

		foreach ( self::meta_keys( $builders ) as $key ) {
			$meta[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return [] === $builders ? '' : self::name_for( $content, $meta, $builders );
	}

	/**
	 * The first builder whose document holds a layout that renders the post: the builder renders any layout (always),
	 * its content holds no text (a builder's placeholder at most), or the builder's mode meta says it renders the post.
	 *
	 * @param string                                                                                                       $content  The post's content.
	 * @param array<string, mixed>                                                                                         $meta     Meta values by key (raw or unserialized).
	 * @param array<int, array{name: string, keys: array<int, string>, mode?: array{0: string, 1: string}, always?: true}> $builders From declared().
	 * @return string
	 */
	public static function name_for( string $content, array $meta, array $builders ): string {
		$no_text = '' === (string) preg_replace( '/[\s\x{00A0}]+|&nbsp;/u', '', strip_tags( $content ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A text check, not output; same result as before for every content.

		foreach ( $builders as $builder ) {
			$mode    = $builder['mode'] ?? null;
			$flag    = null === $mode ? null : ( $meta[ $mode[0] ] ?? null );
			$renders = $no_text || isset( $builder['always'] ) || ( null !== $mode && is_scalar( $flag ) && (string) $flag === $mode[1] );

			if ( ! $renders ) {
				continue;
			}

			foreach ( $builder['keys'] as $key ) {
				$value = $meta[ $key ] ?? '';

				if ( is_array( $value ) ? [] !== $value : ( is_scalar( $value ) && ! in_array( trim( (string) $value ), self::EMPTY_VALUES, true ) && ! self::empty_root( (string) $value ) ) ) {
					return $builder['name'];
				}
			}
		}

		return '';
	}

	/**
	 * Add the warning to a translate_post() result; `kept` gets 'builder'.
	 *
	 * @param array<string, mixed> $result translate_post() result.
	 * @param string               $name   Builder name.
	 * @return array<string, mixed>
	 */
	public static function report( array $result, string $name ): array {
		$warning = sprintf(
			/* translators: 1: page builder name, 2: page builder name. */
			__( 'The %1$s layout was not machine-translated. Translate it in %2$s.', 'perflocale' ),
			$name,
			$name
		);

		$result['warnings']        = array_merge( (array) ( $result['warnings'] ?? [] ), [ $warning ] );
		$result['kept']            = array_values( array_unique( array_merge( array_filter( (array) ( $result['kept'] ?? [] ), 'is_string' ), [ 'builder' ] ) ) );
		$result['builder_warning'] = $warning;

		return $result;
	}

	/**
	 * Posts whose builder layout machine translation leaves out: post id => builder name. Content is read only where
	 * no mode meta decides.
	 *
	 * @param array<int, int> $post_ids Post IDs.
	 * @return array<int, string>
	 */
	public static function names_by_post( array $post_ids ): array {
		global $wpdb;

		$builders = self::declared();
		$keys     = self::meta_keys( $builders );
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ), static fn( int $i ): bool => $i > 0 ) );

		if ( [] === $keys || [] === $post_ids || ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$idph  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$keyph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// A short document is read whole (empty root check), a longer one by its head; the first row per key counts.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, CASE WHEN LENGTH( meta_value ) <= %d THEN meta_value ELSE LEFT( meta_value, 64 ) END AS head FROM %i WHERE post_id IN ({$idph}) AND meta_key IN ({$keyph}) ORDER BY meta_id ASC",
				array_merge( [ self::ROOT_MAX, $wpdb->postmeta ], $post_ids, $keys )
			),
			ARRAY_A
		);
		// phpcs:enable

		$meta = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id   = $row['post_id'] ?? null;
			$key  = $row['meta_key'] ?? null;
			$head = $row['head'] ?? '';

			if ( is_numeric( $id ) && is_string( $key ) ) {
				$meta[ (int) $id ][ $key ] ??= is_string( $head ) ? $head : '';
			}
		}

		$names      = [];
		$candidates = [];

		foreach ( $meta as $id => $m ) {
			$names[ $id ] = self::name_for( 'text', $m, $builders );

			if ( '' === $names[ $id ] && '' !== self::name_for( '', $m, $builders ) ) {
				$candidates[] = $id;
			}
		}

		$names    = array_filter( $names );
		$contents = [];

		if ( [] !== $candidates ) {
			$cph = implode( ',', array_fill( 0, count( $candidates ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$contents = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM %i WHERE ID IN ({$cph})", array_merge( [ $wpdb->posts ], $candidates ) ), ARRAY_A );
			// phpcs:enable
		}

		foreach ( is_array( $contents ) ? $contents : [] as $row ) {
			$id      = $row['ID'] ?? null;
			$content = $row['post_content'] ?? '';

			if ( ! is_numeric( $id ) ) {
				continue;
			}

			$id   = (int) $id;
			$name = self::name_for( is_string( $content ) ? $content : '', $meta[ $id ] ?? [], $builders );

			if ( '' !== $name ) {
				$names[ $id ] = $name;
			}
		}

		ksort( $names );

		return $names;
	}

	/**
	 * Whether a document is a builder root without elements: Oxygen's root node, or the root of Oxygen 6 / Breakdance's
	 * tree_json_string. The builder then renders post_content or nothing, so there is no layout to translate.
	 *
	 * @param string $value Stored document.
	 * @return bool
	 */
	private static function empty_root( string $value ): bool {
		if ( strlen( $value ) > self::ROOT_MAX || ! str_starts_with( ltrim( $value ), '{' ) ) {
			return false;
		}

		$doc = json_decode( $value, true, 32 );

		if ( is_array( $doc ) && is_string( $doc['tree_json_string'] ?? null ) ) {
			$doc = json_decode( $doc['tree_json_string'], true, 32 );
		}

		$root = is_array( $doc ) && is_array( $doc['root'] ?? null ) ? $doc['root'] : $doc;

		return is_array( $root ) && ( 'root' === ( $root['name'] ?? null ) || ( is_array( $root['data'] ?? null ) && 'root' === ( $root['data']['type'] ?? null ) ) ) && empty( $root['children'] );
	}

	/**
	 * Every declared document and mode key.
	 *
	 * @param array<int, array{name: string, keys: array<int, string>, mode?: array{0: string, 1: string}, always?: true}> $builders From declared().
	 * @return array<int, string>
	 */
	private static function meta_keys( array $builders ): array {
		$keys = [];

		foreach ( $builders as $builder ) {
			$keys = array_merge( $keys, $builder['keys'], isset( $builder['mode'] ) ? [ $builder['mode'][0] ] : [] );
		}

		return array_values( array_unique( $keys ) );
	}
}
