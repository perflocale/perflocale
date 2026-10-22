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
 * Replaces every block delimiter of a post's content (starts with their
 * attribute JSON, ends, self-closing blocks) with an opaque placeholder before
 * the content goes to the provider, and puts the original delimiters back
 * afterwards, byte for byte.
 *
 * A placeholder is an HTML comment holding a per-call nonce and an index and
 * no text to translate, the form the "Do not translate" placeholders of
 * BlockSkipFilter already rely on with every bundled provider; wp_kses() keeps
 * comments. A placeholder the answer lost, repeats or invents means the answer
 * is damaged: restore() refuses it with the reason, and translate_post() fails
 * the item without writing anything.
 *
 * Text a block keeps only in its comment attributes (the BlockAttributeSource
 * rules the block editor's Translate button uses) is sent as separate
 * segments; restore() writes each sanitized translation back into the
 * attribute JSON with core's serializer.
 *
 * keep_delimiters() puts trusted delimiter bytes back after sanitize_mt_html():
 * wp_kses() re-serializes block attributes and turns a bare `&` in an
 * attribute value into `&amp;`.
 */
final class BlockDelimiterMask {

	/**
	 * Per-call nonce in every placeholder.
	 *
	 * @var string
	 */
	private string $nonce;

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
	 * @param string $content Content as it is sent.
	 * @return self|null Null when the content has no block delimiter (or they
	 *                   cannot be read): it is sent as it is.
	 */
	public static function mask( string $content ): ?self {
		if ( ! str_contains( $content, '<!-- wp:' ) && ! str_contains( $content, '<!-- /wp:' ) ) {
			return null;
		}

		$tokens = BlockStructureGuard::delimiters( $content );

		if ( null === $tokens || [] === $tokens ) {
			return null;
		}

		$mask   = new self();
		$offset = 0;

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
		$sent = [];

		foreach ( array_keys( $this->delimiters ) as $i ) {
			$sent[] = $this->placeholder( $i );
		}

		$damaged = PlaceholderCheck::damaged( $translated, $sent, '/<!--\s*pfl-b-' . $this->nonce . '-\d+\s*-->|pfl-b-' . $this->nonce . '-\d+/' );

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
	 * Put trusted delimiter bytes back after sanitizing.
	 *
	 * When the sanitized content has the same delimiters (kind and name, in
	 * order) as the content before sanitizing, each delimiter whose text
	 * before sanitizing is trusted is written back as it was. Any other
	 * delimiter keeps its sanitized text.
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
		return '<!-- pfl-b-' . $this->nonce . '-' . $index . ' -->';
	}
}
