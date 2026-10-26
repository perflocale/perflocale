<?php
/**
 * Holds shortcode tags back from a machine-translation provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Swaps every registered shortcode tag for an opaque placeholder before the provider call and restores it byte for byte.
 *
 * An HTML comment, or an empty `<pfl-s>` element for a provider that returns comments as text. Attributes are settings
 * far more often than text, and a translated one breaks the shortcode; enclosed content is translated. Details:
 * docs-ai/11-code-rationale.md, "ShortcodeMask: what is held back".
 */
final class ShortcodeMask {

	/**
	 * Shortcodes that keep visible text in their attributes.
	 */
	private const TEXT_SHORTCODES = [ 'caption' ];

	/**
	 * Per-call nonce in every placeholder.
	 *
	 * @var string
	 */
	private string $nonce;

	/**
	 * Whether placeholders are `<pfl-s>` elements instead of comments.
	 *
	 * @var bool
	 */
	private bool $elements = false;

	/**
	 * The text with its shortcode tags replaced.
	 *
	 * @var string
	 */
	private string $masked = '';

	/**
	 * Original tag text by index.
	 *
	 * @var array<int, string>
	 */
	private array $tags = [];

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->nonce = bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Mask the shortcode tags of a text.
	 *
	 * @param string $text     Text as it is sent.
	 * @param bool   $elements Placeholders as empty `<pfl-s>` elements ({@see AbstractProvider::placeholders_as_elements()}).
	 * @return self|null Null when the text holds no shortcode tag to hold back.
	 */
	public static function mask( string $text, bool $elements = false ): ?self {
		if ( ! str_contains( $text, '[' ) || ! function_exists( 'get_shortcode_regex' ) ) {
			return null;
		}

		// Such a provider changes a tag sent as text (name, attributes, closer): every tag is held back.
		$names = array_diff( array_keys( (array) ( $GLOBALS['shortcode_tags'] ?? [] ) ), $elements ? [] : self::text_shortcodes() );
		$names = array_values( array_filter( array_map( 'strval', $names ), static fn( string $n ): bool => '' !== $n ) );

		if ( [] === $names ) {
			return null;
		}

		$mask           = new self();
		$mask->elements = $elements;
		$mask->masked   = $mask->replace( $text, '/' . get_shortcode_regex( $names ) . '/s', 0 );

		return [] === $mask->tags ? null : $mask;
	}

	/**
	 * The text to send.
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->masked;
	}

	/**
	 * Put the shortcode tags back into a translated text.
	 *
	 * @param string $translated Provider answer.
	 * @return string
	 *
	 * @throws NotSavedException When a placeholder is missing, repeated or unknown.
	 */
	public function restore( string $translated ): string {
		$translated = PlaceholderCheck::respaced( $translated );
		$map        = [];

		foreach ( $this->tags as $i => $tag ) {
			$map[ $this->placeholder( $i ) ] = $tag;
		}

		// Either form and any pfl-s tag or bare nonce token counts, so a marker moved between forms is damage.
		$damaged = PlaceholderCheck::damaged( $translated, array_keys( $map ), '/<!--\s*pfl-s-' . $this->nonce . '-\d+\s*-->|<pfl-s\b[^>]*+>(?:\s*+<\/pfl-s>)?|<\/pfl-s>|(?:pfl-s-)?' . $this->nonce . '-\d+/' );

		if ( $damaged > 0 ) {
			throw new NotSavedException(
				esc_html(
					sprintf(
						/* translators: 1: number of shortcode markers that came back damaged, 2: number sent. */
						__( 'The machine translation was not saved because %1$d of the %2$d shortcode markers sent to the provider came back missing, changed or more than once.', 'perflocale' ),
						$damaged,
						count( $map )
					)
				)
			);
		}

		return strtr( $translated, $map );
	}

	/**
	 * Shortcodes sent with their attributes.
	 *
	 * @return array<int, string>
	 */
	private static function text_shortcodes(): array {
		/**
		 * Filter the shortcodes whose tags go to machine translation with their attributes (they hold visible text).
		 *
		 * Every other registered shortcode's tags are held back and restored. Not read for a provider that returns HTML
		 * comments as text (LibreTranslate): it changes tags sent as text, so every tag is held back.
		 *
		 * @hook perflocale/mt/text_shortcodes
		 * @param string[] $tags Shortcode names. Default: [ 'caption' ].
		 */
		$tags = apply_filters( 'perflocale/mt/text_shortcodes', self::TEXT_SHORTCODES );

		return array_values( array_filter( (array) $tags, 'is_string' ) );
	}

	/**
	 * Replace the shortcode tags of a text, recursing into enclosed content.
	 *
	 * @param string $text  Text.
	 * @param string $regex Shortcode expression.
	 * @param int    $depth Recursion depth.
	 * @return string
	 */
	private function replace( string $text, string $regex, int $depth ): string {
		if ( $depth > 20 ) {
			return $text;
		}

		$held = count( $this->tags );
		$out  = preg_replace_callback(
			$regex,
			function ( array $m ) use ( $regex, $depth ): string {
				// An escaped [[tag]] is shown as text.
				if ( '[' === ( $m[1] ?? '' ) && ']' === ( $m[6] ?? '' ) ) {
					return $m[0];
				}

				$name   = (string) $m[2];
				$closer = '[/' . $name . ']';
				$open   = '[' . $name . (string) ( $m[3] ?? '' ) . ( '/' === ( $m[4] ?? '' ) ? '/' : '' ) . ']';
				$result = $this->hold( $open );

				// The match without the bracket core captured after it: a closing tag followed by a literal `]` still ends it.
				$tag = substr( $m[0], 0, strlen( $m[0] ) - strlen( (string) ( $m[6] ?? '' ) ) );

				// Enclosing only when the match is opener, content and closer: an attribute may end in the closer text.
				if ( $tag === (string) ( $m[1] ?? '' ) . $open . (string) ( $m[5] ?? '' ) . $closer && '/' !== ( $m[4] ?? '' ) ) {
					$result .= $this->replace( (string) ( $m[5] ?? '' ), $regex, $depth + 1 ) . $this->hold( $closer );
				}

				// A lone bracket core captured beside the tag is text, as do_shortcode_tag() keeps it.
				return (string) ( $m[1] ?? '' ) . $result . (string) ( $m[6] ?? '' );
			},
			$text
		);

		if ( null === $out ) {
			// A failed match returns the text as it was: the tags held meanwhile have no placeholder in it.
			$this->tags = array_slice( $this->tags, 0, $held, true );

			return $text;
		}

		return $out;
	}

	/**
	 * Hold a tag back and return its placeholder.
	 *
	 * @param string $tag Tag text.
	 * @return string
	 */
	private function hold( string $tag ): string {
		$i                = count( $this->tags );
		$this->tags[ $i ] = $tag;

		return $this->placeholder( $i );
	}

	/**
	 * Placeholder for a tag.
	 *
	 * @param int $index Tag index.
	 * @return string
	 */
	private function placeholder( int $index ): string {
		return $this->elements
			? '<pfl-s data-i="' . $this->nonce . '-' . $index . '"></pfl-s>'
			: '<!-- pfl-s-' . $this->nonce . '-' . $index . ' -->';
	}
}
