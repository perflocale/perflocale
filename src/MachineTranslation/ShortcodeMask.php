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
 * Swaps every registered shortcode tag, each element outside the MT allowlist (HeldMarkup) and, on request, each
 * template token for an opaque placeholder before the provider call and restores it byte for byte.
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
	 * Template tokens held back in post content, as PlaceholderMasker masks them in strings and meta: `{{var}}`,
	 * `{{ var }}` and `{UPPER_SNAKE}`.
	 */
	private const BRACE_TOKEN = '/\{\{\s*+[A-Za-z0-9_.-]++\s*+\}\}|\{[A-Z][A-Z0-9]*+(?:_[A-Z0-9]++)*+\}/';

	/**
	 * Nonce in every placeholder: a hash of the text, so the same text gives the same pieces (PieceAnswers); a text
	 * cannot hold the hash of itself.
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
	 * The elements held back whole (HeldMarkup::units()).
	 *
	 * @var list<string>
	 */
	private array $units = [];

	/**
	 * Constructor.
	 *
	 * @param string $text Text whose hash is the nonce.
	 */
	private function __construct( string $text ) {
		$this->nonce = substr( hash( 'sha256', $text ), 0, 12 );
	}

	/**
	 * Mask the shortcode tags of a text.
	 *
	 * @param string $text     Text as it is sent.
	 * @param bool   $elements Placeholders as empty `<pfl-s>` elements ({@see AbstractProvider::placeholders_as_elements()}).
	 * @param bool   $raw      Post content: also hold back each template token in its text (BRACE_TOKEN), as strings
	 *                         and meta are masked.
	 * @return self|null Null when the text holds nothing to hold back.
	 */
	public static function mask( string $text, bool $elements = false, bool $raw = false ): ?self {
		$braces = $raw && str_contains( $text, '{' ) && 1 === preg_match( self::BRACE_TOKEN, $text );
		$held   = 1 === preg_match( '/<[a-zA-Z]/', $text );
		$names  = [];

		if ( str_contains( $text, '[' ) && function_exists( 'get_shortcode_regex' ) ) {
			// Such a provider changes a tag sent as text (name, attributes, closer): every tag is held back.
			$names = array_diff( array_keys( (array) ( $GLOBALS['shortcode_tags'] ?? [] ) ), $elements ? [] : self::text_shortcodes() );
			$names = array_values( array_filter( array_map( 'strval', $names ), static fn( string $n ): bool => '' !== $n ) );
		}

		if ( [] === $names && ! $held && ! $braces ) {
			return null;
		}

		$mask           = new self( $text );
		$mask->elements = $elements;
		$mask->masked   = $held ? $mask->hold_units( $text ) : $text;
		$mask->masked   = $braces ? $mask->hold_braces( $mask->masked ) : $mask->masked;

		if ( [] !== $names ) {
			$mask->masked = $mask->replace( $mask->masked, '/' . get_shortcode_regex( $names ) . '/s', 0 );
		}

		return [] === $mask->tags ? null : $mask;
	}

	/**
	 * Hold back each template token in the text of an HTML text, never inside a tag or comment (a block delimiter's
	 * JSON or an attribute keeps its bytes). One linear pass.
	 *
	 * @param string $text HTML.
	 * @return string
	 */
	private function hold_braces( string $text ): string {
		$held = count( $this->tags );
		$out  = preg_replace_callback(
			'/<!--.*?-->|<[a-zA-Z\/!?](?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>|' . substr( self::BRACE_TOKEN, 1, -1 ) . '/s',
			fn( array $m ): string => '<' === $m[0][0] ? $m[0] : $this->hold( $m[0] ),
			$text
		);

		if ( null === $out ) {
			$this->tags = array_slice( $this->tags, 0, $held, true );

			return $text;
		}

		return $out;
	}

	/**
	 * Hold back each element of an HTML text outside the MT allowlist, whole (code and embeds are never sent).
	 *
	 * @param string $text HTML.
	 * @return string
	 */
	private function hold_units( string $text ): string {
		require_once __DIR__ . '/HeldMarkup.php';

		return HeldMarkup::hold(
			$text,
			function ( string $unit ): string {
				$this->units[] = $unit;

				return $this->hold( $unit );
			}
		);
	}

	/**
	 * The elements held back whole, each as often as the text holds it.
	 *
	 * @return list<string>
	 */
	public function units(): array {
		return $this->units;
	}

	/**
	 * The text without the tags mask() holds back for a comment-form provider; enclosed content stays.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function bare( string $text ): string {
		$mask = self::mask( $text );

		if ( null === $mask ) {
			return $text;
		}

		$out = $mask->masked;

		foreach ( array_keys( $mask->tags ) as $i ) {
			$out = str_replace( $mask->placeholder( $i ), '', $out );
		}

		return $out;
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

		$damaged = PlaceholderCheck::damaged( $translated, array_keys( $map ), $this->any_pattern() );

		if ( $damaged > 0 ) {
			throw new NotSavedException(
				esc_html(
					sprintf(
						/* translators: 1: number of markers that came back damaged, 2: number sent. */
						__( 'The machine translation was not saved because %1$d of the %2$d markers sent to the provider for shortcodes, template tokens and held-back code or embeds came back missing, changed or more than once.', 'perflocale' ),
						$damaged,
						count( $map )
					)
				)
			);
		}

		return strtr( $translated, $map );
	}

	/**
	 * Any token of this mask's placeholders: either form, any pfl-s tag or bare nonce token, so a marker moved between
	 * forms is damage.
	 *
	 * @return string
	 */
	public function any_pattern(): string {
		return '/<!--\s*pfl-s-' . $this->nonce . '-\d+\s*-->|<pfl-s\b[^>]*+>(?:\s*+<\/pfl-s>)?|<\/pfl-s>|(?:pfl-s-)?' . $this->nonce . '-\d+/';
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
