<?php
/**
 * Quality rules for machine outputs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\MachineTranslation\PlaceholderCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checks a machine output must pass before it lands in a unit: not empty for a non-empty input, no comment that
 * could inject block structure, each mask token back exactly once, no tag lost (by name and href/src) and the tag
 * balance kept, a length ratio of 0.3-3.0 above 20 characters. A rejected unit keeps the source, flags `mt_quality`.
 */
final class MtQuality {

	/** The length band, for texts over this many characters. */
	private const LENGTH_FROM = 20;
	private const RATIO_MIN   = 0.3;
	private const RATIO_MAX   = 3.0;

	/** A mask token in any spelling PlaceholderMasker::restore() reads; the index captured. */
	private const TOKEN_ANY = '/\[\[\s*PFL[_ ]PH[_ ](\d+)\s*\]\]/i';

	/** Elements without a closing tag. */
	private const VOID = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ];

	/** An opening or closing tag: closer slash, name, attributes (a quoted `>` stays inside). */
	private const TAG = '/<(\/?)([a-zA-Z][a-zA-Z0-9-]*+)(?=[\s\/>])((?:"[^"]*+"|\'[^\']*+\'|[^"\'>]++)*+)>/';

	/**
	 * Why an output is rejected, or '' when it is accepted.
	 *
	 * @param string      $source The unit's source text.
	 * @param string      $output The machine output, placeholders restored.
	 * @param string      $format `html` or `text`.
	 * @param string|null $answer The answer before restore; null: the output.
	 * @param int         $sent   Mask tokens sent `[[PFL_PH_0]]` to `[[PFL_PH_<n-1>]]`.
	 * @return string '' | empty | comment | placeholder | tag | length
	 */
	public static function reject_reason( string $source, string $output, string $format, ?string $answer = null, int $sent = 0 ): string {
		if ( '' !== trim( $source ) && '' === trim( $output ) ) {
			return 'empty';
		}

		if ( ! Verifier::accepts_text( $output ) ) {
			return 'comment';
		}

		// restore() gives the answer back unchanged when it refuses a token.
		if ( self::tokens_damaged( $answer ?? $output, $sent ) || ( $sent > 0 && $output === $answer ) ) {
			return 'placeholder';
		}

		// Both formats: a text unit's tags travel as tokens, unless the masker hit a PCRE limit and sent them as text.
		if ( ! self::keeps_tags( $source, $output ) ) {
			return 'tag';
		}

		$length = mb_strlen( $source );

		if ( $length > self::LENGTH_FROM ) {
			$ratio = mb_strlen( $output ) / $length;

			if ( $ratio < self::RATIO_MIN || $ratio > self::RATIO_MAX ) {
				return 'length';
			}
		}

		return '';
	}

	/**
	 * Whether an answer lost, repeated or invented a mask token.
	 *
	 * @param string $answer The answer before restore.
	 * @param int    $sent   Tokens sent.
	 * @return bool
	 */
	private static function tokens_damaged( string $answer, int $sent ): bool {
		if ( 0 === $sent && ! str_contains( $answer, '[[' ) ) {
			return false;
		}

		$canonical = preg_replace_callback( self::TOKEN_ANY, static fn ( array $m ): string => '[[PFL_PH_' . (int) $m[1] . ']]', $answer );

		if ( null === $canonical ) {
			return true;
		}

		$tokens = [];

		for ( $i = 0; $i < $sent; $i++ ) {
			$tokens[] = '[[PFL_PH_' . $i . ']]';
		}

		return PlaceholderCheck::damaged( $canonical, $tokens, '/\[\[PFL_PH_\d+\]\]/' ) > 0;
	}

	/**
	 * Whether an output keeps the source's tags: at least as many of each (name with href/src), the same openers minus
	 * closers per name, and no more stray or crossed closers. A repeated pair passes; a cut or crossed one does not.
	 *
	 * @param string $source Source text.
	 * @param string $output Output text.
	 * @return bool
	 */
	private static function keeps_tags( string $source, string $output ): bool {
		if ( ! str_contains( $source, '<' ) && ! str_contains( $output, '<' ) ) {
			return true;
		}

		$want = self::tags( $source );
		$got  = self::tags( $output );

		if ( null === $want || null === $got ) {
			return false;
		}

		foreach ( $want['counts'] as $tag => $count ) {
			if ( ( $got['counts'][ $tag ] ?? 0 ) < $count ) {
				return false;
			}
		}

		return $want['net'] === $got['net'] && $got['faults'] <= $want['faults'];
	}

	/**
	 * The tags of a text: opening tags keyed by lowercase name plus the decoded href or src value with their counts,
	 * openers minus closers per non-void name, and the closers a stack walk finds stray or crossed.
	 *
	 * @param string $text Text.
	 * @return array{counts: array<string, int>, net: array<string, int>, faults: int}|null Null on a PCRE error.
	 */
	private static function tags( string $text ): ?array {
		$out   = [
			'counts' => [],
			'net'    => [],
			'faults' => 0,
		];
		$found = preg_match_all( self::TAG, $text, $m, PREG_SET_ORDER );

		if ( false === $found ) {
			return null;
		}

		$stack = [];

		foreach ( $m as $tag ) {
			$name = strtolower( $tag[2] );
			$void = in_array( $name, self::VOID, true ) || str_ends_with( rtrim( $tag[3] ), '/' );

			if ( '/' === $tag[1] ) {
				if ( in_array( $name, self::VOID, true ) ) {
					continue;
				}

				$out['net'][ $name ] = ( $out['net'][ $name ] ?? 0 ) - 1;
				$at                  = array_search( $name, $stack, true );

				if ( false === $at ) {
					++$out['faults'];
				} else {
					$out['faults'] += count( $stack ) - 1 - (int) $at;
					$stack          = array_slice( $stack, 0, (int) $at );
				}

				continue;
			}

			$key = $name;

			if ( preg_match( '/\s(href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag[3], $a ) ) {
				$value = isset( $a[4] ) ? rtrim( $a[4], '/' ) : implode( '', array_slice( $a, 2 ) );
				$key  .= ' ' . strtolower( $a[1] ) . '=' . html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}

			$out['counts'][ $key ] = ( $out['counts'][ $key ] ?? 0 ) + 1;

			if ( ! $void ) {
				$out['net'][ $name ] = ( $out['net'][ $name ] ?? 0 ) + 1;
				$stack[]             = $name;
			}
		}

		$out['net'] = array_filter( $out['net'] );
		ksort( $out['net'] );

		return $out;
	}
}
