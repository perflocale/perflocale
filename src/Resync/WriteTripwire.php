<?php
/**
 * Content ReSync: the write tripwire.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\MachineTranslation\TranslationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Script constructs a write would add: a refused element (TranslationService::REFUSED_ELEMENTS), an on* or srcdoc
 * attribute, or a javascript:, vbscript: or data: URL that no known text (the source, the stored translation) holds.
 * Read as a browser reads tags (WP_HTML_Tag_Processor). Not filterable: a hit refuses the write.
 */
final class WriteTripwire {

	/** URL attributes: core's wp_kses_uri_attributes() plus xlink:href. */
	public const URL_ATTRIBUTES = [ 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns', 'xlink:href' ];

	/**
	 * The constructs of the written texts that none of the known texts holds.
	 *
	 * @param array<int|string, mixed> $written Texts to write (non-strings ignored).
	 * @param array<int|string, mixed> $known   Texts already trusted (non-strings ignored).
	 * @return list<string> Construct keys; empty when the write may go ahead.
	 */
	public static function added( array $written, array $known ): array {
		$found = [];

		foreach ( $written as $text ) {
			if ( is_string( $text ) && str_contains( $text, '<' ) ) {
				$found += self::constructs( $text );
			}
		}

		if ( [] === $found ) {
			return [];
		}

		$have = [];

		foreach ( $known as $text ) {
			if ( is_string( $text ) && str_contains( $text, '<' ) ) {
				$have += self::constructs( $text );
			}
		}

		return array_map( 'strval', array_keys( array_diff_key( $found, $have ) ) );
	}

	/**
	 * The script constructs of a text as keys; a tag cut off at the end counts as one with its bytes. A text with an svg
	 * or math element is read a second time in that namespace, where a style holds markup (WordPress 6.7+; before, each
	 * such element must match a known one byte for byte).
	 *
	 * @param string $html HTML.
	 * @return array<string, true>
	 */
	private static function constructs( string $html ): array {
		if ( ! class_exists( \WP_HTML_Tag_Processor::class ) ) {
			return [ 'unreadable:' . $html => true ];
		}

		$out = self::read( new \WP_HTML_Tag_Processor( $html ), $html );

		if ( 1 === preg_match( '/<(?:svg|math)[\s\/>]/i', $html ) ) {
			$foreign = new \WP_HTML_Tag_Processor( $html );

			// Not method_exists(): the static analysis stubs are a 6.7+ core. Before 6.7 an svg or math element must be
			// byte-equal to a known one (fail closed).
			if ( in_array( 'change_parsing_namespace', get_class_methods( $foreign ), true ) && $foreign->change_parsing_namespace( 'svg' ) ) {
				$out += self::read( $foreign, $html );
			} else {
				$out += self::foreign_elements( $html );
			}
		}

		return $out;
	}

	/**
	 * Each svg and math element of a text by its bytes, and the rest of a text the scanner could not read past one.
	 *
	 * @param string $html HTML.
	 * @return array<string, true>
	 */
	private static function foreign_elements( string $html ): array {
		[ $tokens, $stop ] = TranslationService::markup_tokens( $html );
		$out               = [];

		foreach ( $tokens as [ $start, $end, $name, $kind ] ) {
			if ( 'whole' === $kind && ( 'svg' === $name || 'math' === $name ) ) {
				$out[ 'foreign:' . substr( $html, $start, $end - $start ) ] = true;
			}
		}

		if ( $stop < strlen( $html ) && 1 === preg_match( '/<(?:svg|math)[\s\/>]/i', substr( $html, $stop ) ) ) {
			$out[ 'foreign-unread:' . substr( $html, $stop ) ] = true;
		}

		return $out;
	}

	/**
	 * The constructs one reading of a text finds.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor at the start.
	 * @param string                 $html      The text.
	 * @return array<string, true>
	 */
	private static function read( \WP_HTML_Tag_Processor $processor, string $html ): array {
		$out   = [];
		$spans = null;
		$whole = in_array( 'next_token', get_class_methods( $processor ), true );

		while ( $processor->next_tag() ) {
			$tag   = strtolower( (string) $processor->get_tag() );
			$attrs = [];

			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$name  = strtolower( is_string( $name ) ? $name : '' );
				$value = $processor->get_attribute( $name );
				$value = is_string( $value ) ? $value : '';

				$attrs[ $name ] = $value;

				if ( str_starts_with( $name, 'on' ) || 'srcdoc' === $name || ( in_array( $name, self::URL_ATTRIBUTES, true ) && self::script_url( $value ) ) ) {
					$out[ 'attr:' . $tag . ':' . $name . '=' . $value ] = true;
				}
			}

			if ( ! in_array( $tag, TranslationService::REFUSED_ELEMENTS, true ) ) {
				continue;
			}

			ksort( $attrs );
			$key = 'element:' . $tag . ':' . (string) wp_json_encode( $attrs );

			// A script or style counts with its bytes: the bookmark spans the element from WordPress 6.5 (next_token());
			// before, raw_text_spans() finds its end; unread, with everything after it.
			if ( 'script' === $tag || 'style' === $tag ) {
				$span = self::tag_span( $processor );

				if ( null !== $span && ! $whole ) {
					$spans ??= TranslationService::raw_text_spans( $html );
					$span    = isset( $spans[ $span[0] ] ) ? [ $span[0], $spans[ $span[0] ][0] ] : null;
				}

				$key .= ':' . ( null === $span ? 'unread:' . $html : substr( $html, $span[0], $span[1] - $span[0] ) );
			}

			$out[ $key ] = true;
		}

		// A token left open at the end (a page's next bytes would close it), with its bytes from where a browser's reading
		// stops; 6.4 has no paused_at_incomplete_token(), and a "<" that opens no tag ("5 < 10") is text.
		$paused = in_array( 'paused_at_incomplete_token', get_class_methods( $processor ), true ) ? $processor->paused_at_incomplete_token() : null;
		$cut    = false === $paused ? strlen( $html ) : TranslationService::markup_end( $html );
		$cut    = true === $paused ? min( $cut, (int) strrpos( $html, '<' ) ) : $cut;

		if ( $cut < strlen( $html ) ) {
			$out[ 'cut:' . substr( $html, $cut ) ] = true;
		}

		return $out;
	}

	/**
	 * Whether a URL attribute's value runs script or loads an inline document, read as a browser reads it.
	 * Before 6.6 get_attribute() leaves some references encoded; decoding a decoded value again only adds hits.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function script_url( string $value ): bool {
		return in_array( MarkupRemoval::scheme( $value ), TranslationService::REFUSED_PROTOCOLS, true );
	}

	/**
	 * [ start, end ] of the token the processor is on, from core's bookmark: 6.5+ spans a whole script or style element,
	 * 6.4 ends at the opener's ">".
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor on a tag.
	 * @return array{0: int, 1: int}|null
	 */
	private static function tag_span( \WP_HTML_Tag_Processor $processor ): ?array {
		if ( ! $processor->set_bookmark( 'pfl_tw' ) ) {
			return null;
		}

		$bookmarks = ( new \ReflectionProperty( \WP_HTML_Tag_Processor::class, 'bookmarks' ) )->getValue( $processor );
		$processor->release_bookmark( 'pfl_tw' );
		$mark = is_array( $bookmarks ) && isset( $bookmarks['pfl_tw'] ) && is_object( $bookmarks['pfl_tw'] ) ? get_object_vars( $bookmarks['pfl_tw'] ) : [];

		if ( ! is_int( $mark['start'] ?? null ) ) {
			return null;
		}

		if ( is_int( $mark['length'] ?? null ) ) {
			return [ $mark['start'], $mark['start'] + $mark['length'] ];
		}

		return is_int( $mark['end'] ?? null ) ? [ $mark['start'], $mark['end'] + 1 ] : null;
	}
}
