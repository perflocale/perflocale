<?php
/**
 * Holds block delimiters back from a machine-translation provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Translation\BlockAttributeSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Swaps every block delimiter for an opaque placeholder before the provider call and restores it byte for byte.
 *
 * An HTML comment, or an empty `<pfl-b>` element for a provider that returns comments as text: see
 * docs-ai/11-code-rationale.md, "BlockDelimiterMask: placeholder form and attribute segments".
 */
final class BlockDelimiterMask {

	/**
	 * Per-call nonce in every placeholder.
	 *
	 * @var string
	 */
	private string $nonce;

	/**
	 * Whether placeholders are `<pfl-b>` elements instead of comments.
	 *
	 * @var bool
	 */
	private bool $elements = false;

	/**
	 * The content with its delimiters replaced.
	 *
	 * @var string
	 */
	private string $masked = '';

	/**
	 * Original delimiter text by index.
	 *
	 * @var array<int, string>
	 */
	private array $delimiters = [];

	/**
	 * Delimiters that carry comment text: index => [ block name, void, decoded attributes ].
	 *
	 * @var array<int, array{0: string, 1: bool, 2: array<mixed>}>
	 */
	private array $openers = [];

	/**
	 * Segments sent besides the content: [ delimiter index, attribute name, value ].
	 *
	 * @var array<int, array{0: int, 1: string, 2: string}>
	 */
	private array $segments = [];

	/**
	 * Delimiter texts restore() wrote: the originals and the rebuilt starts.
	 *
	 * @var array<string, true>
	 */
	private array $trusted = [];

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->nonce = bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Mask the block delimiters of a content.
	 *
	 * @param string $content  Content as it is sent.
	 * @param bool   $elements Placeholders as empty `<pfl-b>` elements ({@see AbstractProvider::placeholders_as_elements()}).
	 * @return self|null Null when the content has no readable block delimiter: it is sent as it is.
	 */
	public static function mask( string $content, bool $elements = false ): ?self {
		if ( ! str_contains( $content, '<!-- wp:' ) && ! str_contains( $content, '<!-- /wp:' ) ) {
			return null;
		}

		$tokens = BlockStructureGuard::delimiters( $content );

		if ( null === $tokens || [] === $tokens ) {
			return null;
		}

		$mask           = new self();
		$mask->elements = $elements;
		$offset         = 0;

		foreach ( $tokens as $i => $t ) {
			$text = substr( $content, $t['start'], $t['end'] - $t['start'] );

			$mask->masked          .= substr( $content, $offset, $t['start'] - $offset ) . $mask->placeholder( $i );
			$mask->delimiters[ $i ] = $text;
			$mask->trusted[ $text ] = true;
			$offset                 = $t['end'];

			// An opener holding a shortcode placeholder stays whole: re-serializing escapes the placeholder.
			if ( 'end' === $t['kind'] || ! $t['attrs'] || str_contains( $text, '<!-- pfl-s-' ) ) {
				continue;
			}

			$open  = (int) strpos( $text, '{' );
			$attrs = json_decode( substr( $text, $open, (int) strrpos( $text, '}' ) - $open + 1 ), true );

			if ( ! is_array( $attrs ) ) {
				continue;
			}

			$names = BlockAttributeSource::comment_text_names( $t['name'], $attrs );

			if ( [] === $names ) {
				continue;
			}

			$mask->openers[ $i ] = [ $t['name'], 'void' === $t['kind'], $attrs ];

			foreach ( $names as $name ) {
				$mask->segments[] = [ $i, $name, is_string( $attrs[ $name ] ) ? $attrs[ $name ] : '' ];
			}
		}

		$mask->masked .= substr( $content, $offset );

		return $mask;
	}

	/**
	 * The content to send.
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->masked;
	}

	/**
	 * The comment-attribute texts to send, in order, after the content.
	 *
	 * @return array<int, string>
	 */
	public function segment_texts(): array {
		return array_map( static fn( array $s ): string => $s[2], $this->segments );
	}

	/**
	 * Put the delimiters back into a translated content.
	 *
	 * @param string             $translated Provider answer for the content.
	 * @param array<int, string> $segments   Provider answers for segment_texts(), in order.
	 * @return string The content with its delimiters.
	 *
	 * @throws NotSavedException When a placeholder is missing, repeated or unknown.
	 */
	public function restore( string $translated, array $segments ): string {
		$translated = PlaceholderCheck::respaced( $translated );
		$sent       = [];

		foreach ( array_keys( $this->delimiters ) as $i ) {
			$sent[] = $this->placeholder( $i );
		}

		// Either form and any pfl-b tag or bare nonce token counts, so a marker moved between forms is damage.
		$damaged = PlaceholderCheck::damaged( $translated, $sent, '/<!--\s*pfl-b-' . $this->nonce . '-\d+\s*-->|<pfl-b\b[^>]*+>(?:\s*+<\/pfl-b>)?|<\/pfl-b>|(?:pfl-b-)?' . $this->nonce . '-\d+/' );

		if ( $damaged > 0 ) {
			throw new NotSavedException(
				esc_html(
					BlockStructureGuard::reason(
						sprintf(
							/* translators: 1: number of block markers that came back damaged, 2: number sent. */
							__( '%1$d of the %2$d block markers sent to the provider came back missing, changed or more than once', 'perflocale' ),
							$damaged,
							count( $sent )
						)
					)
				)
			);
		}

		$texts = $this->delimiters;

		// Each opener's segments, in the order they were sent.
		$by_opener = [];

		foreach ( $this->segments as $k => [ $index ] ) {
			$by_opener[ $index ][] = $k;
		}

		foreach ( $this->openers as $i => [ $name, $void, $attrs ] ) {
			foreach ( $by_opener[ $i ] ?? [] as $k ) {
				[ , $attr, $value ] = $this->segments[ $k ];

				$out            = TranslationService::sanitize_mt_html( (string) ( $segments[ $k ] ?? '' ) );
				$attrs[ $attr ] = '' === trim( $out ) ? $value : $out;
			}

			$texts[ $i ]                   = '<!-- wp:' . strip_core_block_namespace( $name ) . ' ' . serialize_block_attributes( $attrs ) . ' ' . ( $void ? '/' : '' ) . '-->';
			$this->trusted[ $texts[ $i ] ] = true;
		}

		$map = [];

		foreach ( $texts as $i => $text ) {
			$map[ $this->placeholder( $i ) ] = $text;
		}

		return strtr( $translated, $map );
	}

	/**
	 * Delimiter texts this mask wrote back.
	 *
	 * @return array<string, true>
	 */
	public function trusted(): array {
		return $this->trusted;
	}

	/**
	 * Every delimiter text of a content.
	 *
	 * @param string $html Content.
	 * @return array<string, true>
	 */
	public static function delimiter_texts( string $html ): array {
		$out = [];

		foreach ( BlockStructureGuard::delimiters( $html ) ?? [] as $t ) {
			$out[ substr( $html, $t['start'], $t['end'] - $t['start'] ) ] = true;
		}

		return $out;
	}

	/**
	 * Put trusted delimiter bytes back after sanitizing (wp_kses() turns a bare `&` in an attribute into `&amp;`).
	 *
	 * Only when kind and name of every delimiter match in order; any other delimiter keeps its sanitized text.
	 *
	 * @param string              $sanitized Content after sanitize_mt_html().
	 * @param string              $before    The same content before it.
	 * @param array<string, true> $trusted   Trusted delimiter texts.
	 * @return string
	 */
	public static function keep_delimiters( string $sanitized, string $before, array $trusted ): string {
		$after  = BlockStructureGuard::delimiters( $sanitized );
		$source = BlockStructureGuard::delimiters( $before );

		if ( null === $after || null === $source || count( $after ) !== count( $source ) ) {
			return $sanitized;
		}

		$out    = '';
		$offset = 0;

		foreach ( $after as $i => $t ) {
			if ( $t['kind'] !== $source[ $i ]['kind'] || $t['name'] !== $source[ $i ]['name'] ) {
				return $sanitized;
			}

			$was  = substr( $before, $source[ $i ]['start'], $source[ $i ]['end'] - $source[ $i ]['start'] );
			$out .= substr( $sanitized, $offset, $t['start'] - $offset )
				. ( isset( $trusted[ $was ] ) ? $was : substr( $sanitized, $t['start'], $t['end'] - $t['start'] ) );

			$offset = $t['end'];
		}

		return $out . substr( $sanitized, $offset );
	}

	/**
	 * Placeholder for a delimiter.
	 *
	 * @param int $index Delimiter index.
	 * @return string
	 */
	private function placeholder( int $index ): string {
		return $this->elements
			? '<pfl-b data-i="' . $this->nonce . '-' . $index . '"></pfl-b>'
			: '<!-- pfl-b-' . $this->nonce . '-' . $index . ' -->';
	}
}
