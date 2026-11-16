<?php
/**
 * The source's own markup that a machine translation keeps byte for byte.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup units the MT allowlist would change: each raw text, foreign, refused or template element, comment holding
 * markup and void element outside it whole, each other tag alone (an element's text stays translatable). Whole units are held back from the provider;
 * a source's units are kept where the answer has them as text (as a browser reads it), everything else passes the
 * allowlist. Loaded by require_once (no classmap entry).
 */
final class HeldMarkup {

	/** Elements without content. */
	private const VOID = [ 'area', 'base', 'br', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];

	/**
	 * The units of an HTML text the MT allowlist would change, in order.
	 *
	 * @param string $html  HTML.
	 * @param bool   $whole Only whole elements (what is held back from a provider).
	 * @return list<array{0: int, 1: int, 2: bool}> [ start, end, whole ].
	 */
	public static function units( string $html, bool $whole = false ): array {
		$allowed   = str_contains( $html, '<' ) ? TranslationService::mt_allowed_html() : [];
		$protocols = [];
		$out       = [];
		$changed   = [];

		foreach ( self::candidates( $html, $whole, $allowed ) as $unit ) {
			if ( $unit[3] ) {
				$out[] = [ $unit[0], $unit[1], $unit[2] ];
				continue;
			}

			$bytes     = substr( $html, $unit[0], $unit[1] - $unit[0] );
			$protocols = [] === $protocols ? TranslationService::mt_protocols() : $protocols;

			// A lone tag holds no raw text: core's KSES with the same allowlist reads it as sanitize_mt_html() does.
			$changed[ $bytes ] ??= ( $unit[2] ? TranslationService::sanitize_mt_html( $bytes ) : wp_kses( $bytes, $allowed, $protocols ) ) !== $bytes;

			if ( $changed[ $bytes ] ) {
				$out[] = [ $unit[0], $unit[1], $unit[2] ];
			}
		}

		return $out;
	}

	/**
	 * The text with each whole unit replaced by what $hold returns for its bytes.
	 *
	 * @param string                   $text Text.
	 * @param callable(string): string $hold Placeholder for a unit.
	 * @return string
	 */
	public static function hold( string $text, callable $hold ): string {
		$out = '';
		$at  = 0;

		// Without core's KSES loaded (a bare process): script and style elements only.
		$units = function_exists( 'wp_kses_allowed_html' ) ? self::units( $text, true ) : array_map( static fn( int $start, array $span ): array => [ $start, $span[0], true ], array_keys( TranslationService::raw_text_spans( $text ) ), TranslationService::raw_text_spans( $text ) );

		foreach ( $units as [ $start, $end ] ) {
			$out .= substr( $text, $at, $start - $at ) . $hold( substr( $text, $start, $end - $start ) );
			$at   = $end;
		}

		return 0 === $at ? $text : $out . substr( $text, $at );
	}

	/**
	 * Provider HTML through the MT allowlist, the source's own units it holds kept (sanitize_mt_html() without a source).
	 *
	 * @param string $html   Answer with its placeholders back.
	 * @param string $source The text that was translated.
	 * @return string
	 */
	public static function sanitize( string $html, string $source ): string {
		return self::keep( $html, $source )[0];
	}

	/**
	 * A provider-derived value as sanitize_mt_value() treats it, the source's own units kept.
	 *
	 * @param string    $value  Value with its placeholders back.
	 * @param string    $source The text that was translated.
	 * @param bool|null $kept   Set to whether a source unit was kept (the caller then applies the user's rules).
	 * @param-out bool $kept
	 * @return string
	 */
	public static function value( string $value, string $source, ?bool &$kept = null ): string {
		$kept = false;

		if ( ! str_contains( $value, '<' ) ) {
			return $value;
		}

		[ $clean, $count, $same ] = self::keep( $value, $source );
		$kept                     = $count > 0;

		return $same ? $value : $clean;
	}

	/**
	 * Whether an imported translation adds markup outside the MT allowlist: anything but the source's own units, kept
	 * where a browser reads text, and markup the allowlist keeps.
	 *
	 * @param string $value  Translated text.
	 * @param string $source The source's text.
	 * @return bool
	 */
	public static function adds_markup( string $value, string $source ): bool {
		if ( ! str_contains( $value, '<' ) ) {
			return false;
		}

		[ $masked, $map ] = self::masked( $value, $source );

		if ( null === $masked ) {
			return true;
		}

		// What core's KSES keeps of the same text with every tag and attribute in it allowed: the MT allowlist must keep as much.
		$clean = TranslationService::sanitize_mt_html( $masked );

		if ( $clean !== wp_kses( $masked, self::everything( $masked ), array_merge( wp_allowed_protocols(), TranslationService::REFUSED_PROTOCOLS ) ) || self::script_url( $masked ) ) {
			return true;
		}

		return null === self::restored( $clean, $map );
	}

	/**
	 * Whether a URL attribute holds a refused scheme as a browser reads it (KSES misses `&Tab;`, `&colon;`, `&#106a`).
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	private static function script_url( string $html ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$value = is_string( $name ) && in_array( strtolower( $name ), \PerfLocale\Resync\WriteTripwire::URL_ATTRIBUTES, true ) ? $processor->get_attribute( $name ) : null;

				if ( is_string( $value ) && \PerfLocale\Resync\WriteTripwire::script_url( $value ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sanitized HTML, how many source units it kept, and whether it equals the input but for lone `&` written as `&amp;`.
	 *
	 * @param string $html   HTML.
	 * @param string $source Source text.
	 * @return array{0: string, 1: int, 2: bool}
	 */
	private static function keep( string $html, string $source ): array {
		[ $masked, $map ] = str_contains( $html, '<' ) ? self::masked( $html, $source ) : [ $html, [] ];

		if ( null === $masked || [] === $map ) {
			$clean = TranslationService::sanitize_mt_html( $html );

			return [ $clean, 0, self::lone_amp( $html ) === $clean ];
		}

		$clean    = TranslationService::sanitize_mt_html( $masked );
		$restored = self::restored( $clean, $map );

		// A unit the answer put inside a tag, comment or raw text: nothing is kept.
		if ( null === $restored ) {
			$clean = TranslationService::sanitize_mt_html( $html );

			return [ $clean, 0, self::lone_amp( $html ) === $clean ];
		}

		return [ $restored, count( $map ), self::lone_amp( $masked ) === $clean ];
	}

	/**
	 * The HTML with each unit byte-equal to one of the source's (each used once) replaced by a text token.
	 *
	 * @param string $html   HTML.
	 * @param string $source Source text.
	 * @return array{0: string|null, 1: array<string, string>} [ masked (null when it cannot be masked), token => bytes ].
	 */
	private static function masked( string $html, string $source ): array {
		$left = [];

		foreach ( '' === $source ? [] : self::units( $source ) as [ $start, $end ] ) {
			$bytes          = substr( $source, $start, $end - $start );
			$left[ $bytes ] = ( $left[ $bytes ] ?? 0 ) + 1;
		}

		if ( [] === $left ) {
			return [ $html, [] ];
		}

		$nonce = 'pflkeep' . bin2hex( random_bytes( 8 ) );

		if ( false !== stripos( $html, $nonce ) ) {
			return [ null, [] ];
		}

		$out = '';
		$at  = 0;
		$map = [];

		foreach ( self::candidates( $html, false, TranslationService::mt_allowed_html() ) as [ $start, $end ] ) {
			$bytes = substr( $html, $start, $end - $start );

			if ( empty( $left[ $bytes ] ) ) {
				continue;
			}

			--$left[ $bytes ];
			$token         = $nonce . 'n' . count( $map ) . 'x';
			$map[ $token ] = $bytes;
			$out          .= substr( $html, $at, $start - $at ) . $token;
			$at            = $end;
		}

		return [ [] === $map ? $html : $out . substr( $html, $at ), $map ];
	}

	/**
	 * Sanitized HTML with its tokens back, or null when one is missing, repeated, or not where a browser reads text.
	 *
	 * @param string                $clean Sanitized masked HTML.
	 * @param array<string, string> $map   Token => bytes.
	 * @return string|null
	 */
	private static function restored( string $clean, array $map ): ?string {
		if ( [] === $map ) {
			return $clean;
		}

		[ $tokens, $stop ] = TranslationService::markup_tokens( $clean );
		$at                = [];

		foreach ( array_keys( $map ) as $token ) {
			$pos = strpos( $clean, $token );

			if ( false === $pos || 1 !== substr_count( $clean, $token ) || $pos + strlen( $token ) > $stop ) {
				return null;
			}

			$at[ $pos ] = strlen( $token );
		}

		ksort( $at );
		$i     = 0;
		$n     = count( $tokens );
		$inert = 0;

		foreach ( $at as $pos => $length ) {
			while ( $i < $n && $tokens[ $i ][1] <= $pos ) {
				$inert = self::template_depth( $inert, $tokens[ $i ] );
				++$i;
			}

			// Inside a tag, comment or raw text, or inside a template (inert content a script may later clone).
			if ( $inert > 0 || ( $i < $n && $tokens[ $i ][0] < $pos + $length ) ) {
				return null;
			}
		}

		return strtr( $clean, $map );
	}

	/**
	 * Unit candidates in order, read as a browser reads the text: [ start, end, whole, changed for sure ].
	 *
	 * @param string               $html    HTML.
	 * @param bool                 $whole   Only whole elements.
	 * @param array<string, mixed> $allowed The MT allowlist.
	 * @return list<array{0: int, 1: int, 2: bool, 3: bool}>
	 */
	private static function candidates( string $html, bool $whole, array $allowed ): array {
		if ( ! str_contains( $html, '<' ) ) {
			return [];
		}

		[ $tokens, $stop ] = TranslationService::markup_tokens( $html );
		$closers           = self::closers( $tokens, $allowed );
		$out               = [];
		$n                 = count( $tokens );
		$inert             = 0;

		for ( $i = 0; $i < $n; $i++ ) {
			[ $start, $end, $name, $kind ] = $tokens[ $i ];
			$foreign                       = ! isset( $allowed[ $name ] );
			$before                        = $inert;
			$inert                         = self::template_depth( $inert, $tokens[ $i ] );

			// A template element (inert content a script may clone) and a comment holding markup: whole, never sent.
			if ( 0 === $before && 'open' === $kind && 'template' === $name ) {
				$close = self::template_closer( $tokens, $i );

				if ( null === $close ) {
					$out[] = [ $start, strlen( $html ), true, true ];

					return $out;
				}

				$out[] = [ $start, $tokens[ $close ][1], true, true ];
				$i     = $close;
				$inert = 0;
				continue;
			}

			if ( 0 === $before && 'other' === $kind && str_starts_with( substr( $html, $start, 4 ), '<!--' ) && false !== strpos( substr( $html, $start + 4, $end - $start - 4 ), '<' ) ) {
				$out[] = [ $start, $end, true, true ];
				continue;
			}

			if ( 'other' === $kind || 'template' === $name || $before > 0 ) {
				continue;
			}

			if ( 'whole' === $kind || ( 'open' === $kind && $foreign && in_array( $name, self::VOID, true ) ) ) {
				$out[] = [ $start, $end, true, $foreign ];
				continue;
			}

			// A refused element (object, applet, an SVG animation ...) whole to its closer.
			if ( 'open' === $kind && $foreign && isset( $closers[ $i ] ) && in_array( $name, TranslationService::REFUSED_ELEMENTS, true ) ) {
				$out[] = [ $start, $tokens[ $closers[ $i ] ][1], true, true ];
				$i     = $closers[ $i ];
				continue;
			}

			if ( ! $whole ) {
				$out[] = [ $start, $end, false, $foreign ];
			}
		}

		// The rest past where the scan stopped (svg, select ... it cannot model) is one whole unit: never sent.
		if ( $stop < strlen( $html ) ) {
			$out[] = [ $stop, strlen( $html ), true, true ];
		}

		return $out;
	}

	/**
	 * Index of the closer of the template element opened at token $i (nested templates counted), or null when unclosed.
	 *
	 * @param list<array{0: int, 1: int, 2: string, 3: string}> $tokens Tokens.
	 * @param int                                               $i      Index of the opener.
	 * @return int|null
	 */
	private static function template_closer( array $tokens, int $i ): ?int {
		$depth = 0;
		$n     = count( $tokens );

		for ( $j = $i; $j < $n; $j++ ) {
			$depth = self::template_depth( $depth, $tokens[ $j ] );

			if ( 0 === $depth ) {
				return $j;
			}
		}

		return null;
	}

	/**
	 * Open template elements after a token.
	 *
	 * @param int                                         $depth Open templates before it.
	 * @param array{0: int, 1: int, 2: string, 3: string} $token Token.
	 * @return int
	 */
	private static function template_depth( int $depth, array $token ): int {
		if ( 'template' !== $token[2] ) {
			return $depth;
		}

		return 'open' === $token[3] ? $depth + 1 : ( 'close' === $token[3] ? max( 0, $depth - 1 ) : $depth );
	}

	/**
	 * For each opener of an element outside the allowlist, the index of the closer that ends it (same-name nesting).
	 *
	 * @param list<array{0: int, 1: int, 2: string, 3: string}> $tokens  Tokens.
	 * @param array<string, mixed>                              $allowed Allowlist.
	 * @return array<int, int>
	 */
	private static function closers( array $tokens, array $allowed ): array {
		$open = [];
		$out  = [];

		foreach ( $tokens as $i => [ , , $name, $kind ] ) {
			if ( isset( $allowed[ $name ] ) || ( 'open' !== $kind && 'close' !== $kind ) ) {
				continue;
			}

			if ( 'open' === $kind ) {
				$open[ $name ][] = $i;
			} elseif ( ! empty( $open[ $name ] ) ) {
				$out[ (int) array_pop( $open[ $name ] ) ] = $i;
			}
		}

		return $out;
	}

	/**
	 * Core's post allowlist plus every tag and attribute a browser finds in the text.
	 *
	 * @param string $html HTML.
	 * @return array<string, array<string, bool>>
	 */
	private static function everything( string $html ): array {
		$all = [];

		foreach ( wp_kses_allowed_html( 'post' ) as $tag => $attrs ) {
			$all[ (string) $tag ] = is_array( $attrs ) ? array_fill_keys( array_map( 'strval', array_keys( $attrs ) ), true ) : [];
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			$tag = strtolower( (string) $processor->get_tag() );

			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $attr ) {
				if ( is_string( $attr ) ) {
					$all[ $tag ][ $attr ] = true;
				}
			}

			$all[ $tag ] ??= [];
		}

		return $all;
	}

	/**
	 * A text with each `&` that cannot begin a character reference written as `&amp;`.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function lone_amp( string $value ): string {
		return (string) preg_replace( '/&(?![A-Za-z0-9#])/', '&amp;', $value );
	}
}
