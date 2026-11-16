<?php
/**
 * One unit of text of a resync.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The smallest text that is reused or translated: a document byte range, a string leaf of a block comment's JSON, or
 * a field. `$text` is the unit's bytes as they stand (entities and markup as written); the key is the normalised form.
 *
 * @phpstan-type Mirror array{range: array{int, int}, kind: string, quote: string}
 */
final class Unit {

	/** A byte range of the document. */
	public const KIND_RANGE = 'range';

	/** A string leaf of a block comment's JSON. */
	public const KIND_JSON = 'json';

	/** A field outside the content: title, excerpt, custom field. */
	public const KIND_FIELD = 'field';

	/** Mirror kinds: an HTML attribute value, the text of an HTML comment. */
	public const MIRROR_ATTRIBUTE = 'attribute';
	public const MIRROR_COMMENT   = 'comment';

	/**
	 * The sha256 of the role class and the normalised text ({@see Keys::key()}).
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * Constructor.
	 *
	 * @param string         $kind   {@see self::KIND_RANGE}, {@see self::KIND_JSON} or {@see self::KIND_FIELD}.
	 * @param string         $role   Role class: html, text:<attribute>, title, excerpt, meta:<key>.
	 * @param string         $format {@see BlockAttributeSource::FORMAT_HTML} or {@see BlockAttributeSource::FORMAT_TEXT}.
	 * @param string         $text   The unit's text.
	 * @param BlockNode|null $node   The block or freeform node the unit belongs to; null for a field.
	 * @param array|null     $range  Document byte range (end exclusive) of a range unit; null otherwise.
	 * @param array          $path   Attribute path: the schema path of a located attribute (`caption`; `images`, 0, `alt`), the JSON path of a comment leaf; [] for a freeform unit or a field.
	 * @param string         $quote  Quote style of an HTML attribute value (`"`, `'`, or '' when bare); '' otherwise.
	 * @param array|null     $mirror A second place that holds the same text and is written with it; null when none.
	 * @param array|null     $cell   Row and column of a table cell; null otherwise.
	 * @param string         $tag    For a unit of a freeform region: the element the unit is the content of (`p`, `li`, …), `#text` for a depth-0 run, `img` for an image attribute; '' otherwise.
	 * @phpstan-param array{int, int}|null $range
	 * @phpstan-param list<int|string> $path
	 * @phpstan-param Mirror|null $mirror
	 * @phpstan-param array{int, int}|null $cell
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $role,
		public readonly string $format,
		public readonly string $text,
		public readonly ?BlockNode $node,
		public readonly ?array $range,
		public readonly array $path,
		public readonly string $quote,
		public readonly ?array $mirror,
		public readonly ?array $cell,
		public readonly string $tag = ''
	) {
		$this->key = Keys::key( $role, $text, $format );
	}
}
