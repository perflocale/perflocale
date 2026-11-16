<?php
/**
 * Comma-separated keyword lists on the meta machine-translation paths.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A meta value in the `keywords` format: a "kw1,kw2" list sent as one text-mode item per keyword and rejoined with ",".
 * The join never takes a provider's own separator (an Arabic "،" would make the list one keyword).
 */
final class KeywordList {

	/** The perflocale/mt/meta_key_format value of a keyword list. */
	public const FORMAT = 'keywords';

	/** Whitespace and list separators (",", Arabic, CJK, fullwidth) at a keyword's edges. */
	private const EDGES = '/^[\s,\x{060C}\x{3001}\x{FF0C}\x{FF64}]+|[\s,\x{060C}\x{3001}\x{FF0C}\x{FF64}]+$/u';

	/**
	 * The keywords of a list, trimmed, empty ones dropped.
	 *
	 * @param string $value Stored list.
	 * @return list<string>
	 */
	public static function split( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( string $k ): bool => '' !== $k ) );
	}

	/**
	 * The translated keywords as one list; null when one is unusable (empty, or a "," inside), so the caller keeps the
	 * whole existing value.
	 *
	 * @param array<string> $pieces Translated keywords, in order.
	 * @param int           $count  Keywords sent.
	 * @return string|null
	 */
	public static function join( array $pieces, int $count ): ?string {
		if ( count( $pieces ) !== $count || 0 === $count ) {
			return null;
		}

		$out = [];

		foreach ( $pieces as $piece ) {
			$piece = self::trim_edges( $piece );

			if ( '' === $piece || str_contains( $piece, ',' ) ) {
				return null;
			}

			$out[] = $piece;
		}

		return implode( ',', $out );
	}

	/**
	 * A keyword without whitespace or list separators at either end (multibyte-safe).
	 *
	 * @param string $piece Keyword.
	 * @return string
	 */
	private static function trim_edges( string $piece ): string {
		$out = preg_replace( self::EDGES, '', $piece );

		return is_string( $out ) ? $out : '';
	}
}
