<?php
/**
 * Reads a parsed block's text attributes the way the block editor does.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Util\WorkBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads each text attribute of a parsed block from where its block type stores it.
 *
 * Mirrors `textAttrChain()` and `findTextAttrsInSchema()` in assets/js/block-toolbar.js. One regex pass, no ext-dom;
 * a read it cannot complete within its bounds is reported in `error`, never answered with less, so a caller can refuse.
 *
 * @phpstan-type Elements array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>, tag_start: list<int>, tag_end: list<int>, close_end: array<int, int>, values: list<array<string, array{int, int, string}>>, comments: list<array{int, int, int}>, error: string}
 * @phpstan-type Leaf array{path: list<int|string>, format: string, start: int, end: int, quote: string}
 * @phpstan-type Step array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}
 * @phpstan-type Query array{budget: int, error: string}
 * @phpstan-type Memo array{match: array<int, array<int, string>>, above: array<int, array<int, string>>}
 */
final class BlockAttributeSource {

	/**
	 * Value is HTML (rich text): sent to MT in HTML mode.
	 */
	public const FORMAT_HTML = 'html';

	/**
	 * Value is plain text (an HTML attribute such as `alt`): sent in text mode.
	 */
	public const FORMAT_TEXT = 'text';

	/** Error: the fragment could not be tokenized within its bounds (PCRE limit, attributes, scan budget). */
	public const ERROR_TOKENIZER = 'tokenizer';

	/** Error: a selector's evaluation would exceed the work budget ({@see self::SELECTOR_BUDGET}). */
	public const ERROR_SELECTOR_BUDGET = 'selector_budget';

	/**
	 * Most units of selector work one read may spend. Past a direct-recursion allowance each (element, step) state is
	 * decided once through the memo, so a query costs at most elements times steps.
	 */
	public const SELECTOR_BUDGET = 4000000;

	/**
	 * Most distinct attribute names one start tag may carry before the fragment is refused; real tags carry tens.
	 * `perflocale/resync/work_budget` may raise it ({@see WorkBudget::TAG_ATTRIBUTES}).
	 */
	public const MAX_ATTRIBUTES = 4096;

	/**
	 * Attributes that are never text, whatever their schema says. Same list as
	 * `IGNORE_ATTR_NAMES` in block-toolbar.js.
	 */
	public const IGNORE_NAMES = '/^(class|className|anchor|id|url|href|src|target|rel|tagName|level|align|orientation|direction|mode|style|color|backgroundColor|fontSize|fontFamily|borderRadius|borderColor|width|height|gap)$/i';

	/**
	 * Attribute names that are user-visible text although their schema source
	 * is an HTML attribute. Same list as `TRANSLATABLE_ATTR_NAMES` in
	 * block-toolbar.js.
	 */
	private const TEXT_ATTRIBUTE_NAMES = '/^(alt|title|placeholder|ariaLabel|aria-label|description|summary|tooltip)$/i';

	/**
	 * Per-block attribute chains. Same map as `per_block` in `textAttrChain()`.
	 *
	 * A quote's `value` is never read or written: the editor turns a non-empty
	 * `value` into paragraphs that replace the quote's inner blocks.
	 *
	 * @var array<string, string[]>
	 */
	private const PER_BLOCK = [
		'core/quote'        => [ 'citation' ],
		'core/pullquote'    => [ 'value', 'citation' ],
		'core/button'       => [ 'text' ],
		'core/details'      => [ 'summary' ],
		'core/image'        => [ 'caption', 'alt', 'title' ],
		'core/embed'        => [ 'caption' ],
		'core/audio'        => [ 'caption' ],
		'core/video'        => [ 'caption' ],
		'core/post-excerpt' => [ 'excerpt' ],
	];

	/**
	 * Chain for a block with no per-block entry and no discoverable schema.
	 */
	private const FALLBACK_CHAIN = [ 'content', 'text', 'value' ];

	/**
	 * Maximum number of attribute names read from one block.
	 */
	private const MAX_NAMES = 32;

	/**
	 * Elements that never have content or a closing tag, as keys.
	 *
	 * @var array<string, true>
	 */
	private const VOID_ELEMENTS = [
		'area'   => true,
		'base'   => true,
		'br'     => true,
		'col'    => true,
		'embed'  => true,
		'hr'     => true,
		'img'    => true,
		'input'  => true,
		'link'   => true,
		'meta'   => true,
		'source' => true,
		'track'  => true,
		'wbr'    => true,
	];

	/**
	 * Raw-text elements, as keys: a tag or comment inside is content; only its own end tag ends it.
	 *
	 * @var array<string, true>
	 */
	private const RAW_TEXT_ELEMENTS = [
		'script'   => true,
		'style'    => true,
		'textarea' => true,
	];

	/**
	 * Start tags that close an open `p` (HTML's optional end tag rules), as keys.
	 *
	 * @var array<string, true>
	 */
	private const P_CLOSERS = [
		'address'    => true,
		'article'    => true,
		'aside'      => true,
		'blockquote' => true,
		'dd'         => true,
		'details'    => true,
		'div'        => true,
		'dl'         => true,
		'dt'         => true,
		'fieldset'   => true,
		'figcaption' => true,
		'figure'     => true,
		'footer'     => true,
		'form'       => true,
		'h1'         => true,
		'h2'         => true,
		'h3'         => true,
		'h4'         => true,
		'h5'         => true,
		'h6'         => true,
		'header'     => true,
		'hgroup'     => true,
		'hr'         => true,
		'li'         => true,
		'main'       => true,
		'menu'       => true,
		'nav'        => true,
		'ol'         => true,
		'p'          => true,
		'pre'        => true,
		'search'     => true,
		'section'    => true,
		'table'      => true,
		'ul'         => true,
	];

	/**
	 * Elements that end the search for an open `p` (HTML's button scope), as keys.
	 *
	 * @var array<string, true>
	 */
	private const P_SCOPE = [
		'button'   => true,
		'caption'  => true,
		'object'   => true,
		'table'    => true,
		'td'       => true,
		'template' => true,
		'th'       => true,
	];

	/**
	 * Elements that end the search for an open `li`, `dd` or `dt` (HTML's special elements but address, div and p),
	 * as keys; an item is matched before it is tested as scope.
	 *
	 * @var array<string, true>
	 */
	private const ITEM_SCOPE = [
		'article'    => true,
		'aside'      => true,
		'blockquote' => true,
		'body'       => true,
		'button'     => true,
		'caption'    => true,
		'dd'         => true,
		'details'    => true,
		'dialog'     => true,
		'dt'         => true,
		'fieldset'   => true,
		'figcaption' => true,
		'figure'     => true,
		'footer'     => true,
		'form'       => true,
		'h1'         => true,
		'h2'         => true,
		'h3'         => true,
		'h4'         => true,
		'h5'         => true,
		'h6'         => true,
		'header'     => true,
		'hgroup'     => true,
		'html'       => true,
		'iframe'     => true,
		'li'         => true,
		'main'       => true,
		'menu'       => true,
		'nav'        => true,
		'noscript'   => true,
		'object'     => true,
		'ol'         => true,
		'pre'        => true,
		'script'     => true,
		'search'     => true,
		'section'    => true,
		'select'     => true,
		'style'      => true,
		'summary'    => true,
		'table'      => true,
		'tbody'      => true,
		'td'         => true,
		'template'   => true,
		'textarea'   => true,
		'tfoot'      => true,
		'th'         => true,
		'thead'      => true,
		'title'      => true,
		'tr'         => true,
		'ul'         => true,
	];

	/**
	 * Elements that end the search for an open table part (HTML's table scope), as keys.
	 *
	 * @var array<string, true>
	 */
	private const TABLE_SCOPE = [
		'html'     => true,
		'table'    => true,
		'template' => true,
	];

	/**
	 * Elements that end the search for an open `option`, as keys.
	 *
	 * @var array<string, true>
	 */
	private const OPTION_SCOPE = [
		'datalist' => true,
		'optgroup' => true,
		'select'   => true,
	];

	/**
	 * Elements that end the search for an open `optgroup`, as keys.
	 *
	 * @var array<string, true>
	 */
	private const OPTGROUP_SCOPE = [
		'datalist' => true,
		'select'   => true,
	];

	/**
	 * Start tags that close open elements implicitly: per tag name, steps `[names closed, where the search stops]`
	 * applied in order after the `p` rule.
	 *
	 * @var array<string, list<array{list<string>, array<string, true>}>>
	 */
	private const IMPLIED_CLOSERS = [
		'li'       => [ [ [ 'li' ], self::ITEM_SCOPE ] ],
		'dt'       => [ [ [ 'dd', 'dt' ], self::ITEM_SCOPE ] ],
		'dd'       => [ [ [ 'dd', 'dt' ], self::ITEM_SCOPE ] ],
		'td'       => [ [ [ 'td', 'th' ], self::TABLE_SCOPE ] ],
		'th'       => [ [ [ 'td', 'th' ], self::TABLE_SCOPE ] ],
		'tr'       => [ [ [ 'td', 'th' ], self::TABLE_SCOPE ], [ [ 'tr' ], self::TABLE_SCOPE ] ],
		'tbody'    => [ [ [ 'td', 'th' ], self::TABLE_SCOPE ], [ [ 'tr' ], self::TABLE_SCOPE ], [ [ 'tbody', 'tfoot', 'thead' ], self::TABLE_SCOPE ] ],
		'tfoot'    => [ [ [ 'td', 'th' ], self::TABLE_SCOPE ], [ [ 'tr' ], self::TABLE_SCOPE ], [ [ 'tbody', 'tfoot', 'thead' ], self::TABLE_SCOPE ] ],
		'thead'    => [ [ [ 'td', 'th' ], self::TABLE_SCOPE ], [ [ 'tr' ], self::TABLE_SCOPE ], [ [ 'tbody', 'tfoot', 'thead' ], self::TABLE_SCOPE ] ],
		'option'   => [ [ [ 'option' ], self::OPTION_SCOPE ] ],
		'optgroup' => [ [ [ 'optgroup', 'option' ], self::OPTGROUP_SCOPE ] ],
	];

	/**
	 * A token from its `<`: a comment through its first `-->` (no groups), or a tag up to the `>` that would close it
	 * (group 1 end-tag slash, 2 name, 3 attribute bytes), which the tokenizer checks itself. Every run is possessive so
	 * huge values stay within PCRE limits; an opener with no `-->` commits the search to fail (it and later ones are text).
	 */
	private const TOKEN_PATTERN = '#<(?:!--(?:[^-]++|-++(?!>)|->)*+-++>|!--(*COMMIT)(*FAIL)|(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+))#';

	/**
	 * {@see self::TOKEN_PATTERN} matching a comment by its `<!--` alone (closer: {@see self::closer()}): the fallback
	 * for the rest of a fragment where the token pattern reached a PCRE limit.
	 */
	private const OPENER_PATTERN = '#<(?:!--|(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+))#';

	/**
	 * {@see self::TOKEN_PATTERN} without comments, same groups: for the rest of a fragment after an opener no `-->`
	 * follows, where every `<!--` is text.
	 */
	private const TAG_PATTERN = '#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)#';

	/** A comment closer, searched for when the first `>` after an opener does not close it. */
	private const CLOSER_PATTERN = '/-->/';

	/**
	 * Longest fragment matched in one call: the match list is held at once (about a kilobyte per token), so a longer
	 * one is matched a token at a time.
	 */
	private const BATCH_BYTES = 65536;

	/** Longest attribute bytes of one start tag matched in one call, for the same reason. */
	private const ATTRIBUTE_BATCH_BYTES = 8192;

	/**
	 * Scan budget (per fragment byte, plus a constant): most bytes rematched from `<`s that start no tag before the
	 * fragment is refused. Each may read to the end again; this lets every `<` of 16 KB do so and caps 1 MB near 1 s.
	 * `perflocale/resync/work_budget` may raise the bytes per byte ({@see WorkBudget::TOKENIZER_SCAN}).
	 */
	public const SCAN_BYTES_PER_BYTE = 1024;
	private const SCAN_BYTES         = 134217728;

	/** One attribute of a start tag: the name, then an optional double-quoted, single-quoted or bare value. */
	private const ATTRIBUTE_PATTERN = '#([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#';

	/** Sources whose value is the content of the matched element. */
	private const CONTENT_SOURCES = [ 'rich-text', 'html', 'children' ];

	/** The three states of a memoised selector state: undecided, false, true. */
	private const STATE_UNKNOWN = "\0";
	private const STATE_FALSE   = "\1";
	private const STATE_TRUE    = "\2";

	/** Direct selector work a query may spend per candidate and step before it switches to the memo of states. */
	private const DIRECT_WORK_PER_STATE = 4;

	/** Most selectors kept parsed; past it the kept ones are dropped. Block schemas name a few dozen. */
	private const PARSED_SELECTORS = 256;

	/**
	 * Selector work a read starts with ({@see self::SELECTOR_BUDGET}); a property so tests can refuse a small fragment.
	 *
	 * @var int
	 */
	private static int $selector_budget = self::SELECTOR_BUDGET;

	/**
	 * Parsed alternatives per selector string, at most {@see self::PARSED_SELECTORS}: parsing is pure, and every
	 * read and locate asks for its schema's selectors again.
	 *
	 * @var array<string, list<list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}>>>
	 */
	private static array $parsed = [];

	/**
	 * Attribute names that may carry text for a block type, in priority order.
	 *
	 * Per-block map first, then the text attributes discovered from the
	 * registered schema, then `content` / `text` / `value`.
	 *
	 * @param string $block_name Block name, e.g. `core/image`.
	 * @return string[]
	 */
	public static function chain( string $block_name ): array {
		if ( isset( self::PER_BLOCK[ $block_name ] ) ) {
			return self::PER_BLOCK[ $block_name ];
		}

		$found = [];

		foreach ( self::schema( $block_name ) as $name => $def ) {
			if ( self::is_text_attribute( (string) $name, $def ) ) {
				$found[] = (string) $name;
			}
		}

		return $found !== [] ? $found : self::FALLBACK_CHAIN;
	}

	/**
	 * Read the text attributes of a parsed block (sourced ones from the first element the schema selector matches, comment
	 * attributes from the block comment). A selector this reader cannot evaluate is reported in `skipped`, a tokenizer
	 * failure or spent budget in `error`.
	 *
	 * @param array<mixed> $block Parsed block (parse_blocks() shape).
	 * @param array<mixed> $names Attribute names to read (invalid ones ignored); empty = the block type's chain.
	 * @return array{values: array<string, string>, formats: array<string, string>, skipped: string[], error: string}
	 */
	public static function read( array $block, array $names = [] ): array {
		$block_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$html       = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
		$schema     = self::schema( $block_name );

		$wanted = [];

		foreach ( ( $names !== [] ? $names : self::chain( $block_name ) ) as $name ) {
			if ( is_string( $name ) && self::is_valid_name( $name ) && ! in_array( $name, $wanted, true ) ) {
				$wanted[] = $name;
			}

			if ( count( $wanted ) >= self::MAX_NAMES ) {
				break;
			}
		}

		$out = [
			'values'  => [],
			'formats' => [],
			'skipped' => [],
			'error'   => '',
		];

		$doc   = null;
		$query = self::query_state();

		foreach ( $wanted as $name ) {
			if ( preg_match( self::IGNORE_NAMES, $name ) ) {
				continue;
			}

			$def    = is_array( $schema[ $name ] ?? null ) ? $schema[ $name ] : [];
			$source = is_string( $def['source'] ?? null ) ? $def['source'] : '';
			$value  = null;
			$format = self::FORMAT_HTML;

			if ( $source !== '' ) {
				if ( in_array( $source, [ 'rich-text', 'html', 'children' ], true ) ) {
					$selector = is_string( $def['selector'] ?? null ) ? $def['selector'] : '';

					if ( $selector === '' ) {
						$value = $html;
					} else {
						$doc ??= self::tokenize( $html );

						if ( $doc['error'] !== '' ) {
							$out['error']     = $doc['error'];
							$out['skipped'][] = $name;
							continue;
						}

						$match = self::query( $doc, $selector, -1, $query );

						if ( $query['error'] !== '' ) {
							$out['error']     = $query['error'];
							$out['skipped'][] = $name;
							continue;
						}

						if ( $match === false ) {
							$out['skipped'][] = $name;
							continue;
						}

						if ( $match !== null ) {
							$start = $doc['start'][ $match ];
							$value = substr( $html, $start, ( $doc['end'][ $match ] ?? $start ) - $start );
						}
					}
				} elseif ( $source === 'attribute' && preg_match( self::TEXT_ATTRIBUTE_NAMES, $name ) ) {
					$format    = self::FORMAT_TEXT;
					$selector  = is_string( $def['selector'] ?? null ) ? $def['selector'] : '';
					$html_attr = strtolower( is_string( $def['attribute'] ?? null ) ? $def['attribute'] : '' );

					if ( $selector === '' || $html_attr === '' ) {
						$out['skipped'][] = $name;
						continue;
					}

					$doc ??= self::tokenize( $html );

					if ( $doc['error'] !== '' ) {
						$out['error']     = $doc['error'];
						$out['skipped'][] = $name;
						continue;
					}

					$match = self::query( $doc, $selector, -1, $query );

					if ( $query['error'] !== '' ) {
						$out['error']     = $query['error'];
						$out['skipped'][] = $name;
						continue;
					}

					if ( $match === false ) {
						$out['skipped'][] = $name;
						continue;
					}

					if ( $match !== null && isset( $doc['attrs'][ $match ][ $html_attr ] ) ) {
						$value = $doc['attrs'][ $match ][ $html_attr ];
					}
				} else {
					// URL, boolean, query, raw and meta sources are not text.
					$out['skipped'][] = $name;
					continue;
				}
			}

			// Comment attribute, or a sourced attribute also present in the
			// block comment (older saved shapes) when the HTML held nothing.
			if ( ( $value === null || trim( $value ) === '' ) && isset( $attrs[ $name ] ) && is_string( $attrs[ $name ] ) ) {
				$value = $attrs[ $name ];
			}

			if ( $value === null || trim( $value ) === '' ) {
				continue;
			}

			$out['values'][ $name ]  = trim( $value );
			$out['formats'][ $name ] = $format;
		}

		return $out;
	}

	/**
	 * Text attributes of a block that live only in its block comment.
	 *
	 * Chain names with no schema `source` whose value has a letter. Whole-post
	 * machine translation holds block comments back, so these would otherwise
	 * stay untranslated. None for a "Do not translate" block: its text is not sent.
	 *
	 * @param string       $block_name Block name, e.g. `acme/card`.
	 * @param array<mixed> $attrs      The block's decoded comment attributes.
	 * @return string[]
	 */
	public static function comment_text_names( string $block_name, array $attrs ): array {
		if ( ! empty( $attrs[ BlockSkipFilter::SKIP_ATTRIBUTE ] ) ) {
			return [];
		}

		$schema = self::schema( $block_name );
		$names  = [];

		foreach ( self::chain( $block_name ) as $name ) {
			$def = is_array( $schema[ $name ] ?? null ) ? $schema[ $name ] : [];

			if (
				preg_match( self::IGNORE_NAMES, $name )
				|| ( is_string( $def['source'] ?? null ) && '' !== $def['source'] )
				|| ! is_string( $attrs[ $name ] ?? null )
				|| ! preg_match( '/\p{L}/u', $attrs[ $name ] )
			) {
				continue;
			}

			$names[] = $name;
		}

		return $names;
	}

	/**
	 * Locate a parsed block's text attributes as untrimmed byte ranges of its inner HTML, by read()'s rules.
	 *
	 * Also an `attribute` source whose HTML attribute is a text name (media-text `mediaAlt` reads `alt`), and each
	 * `query` item (path: name, index, sub-name). On `error` the stopped names are in `skipped`: text unknown, not absent.
	 *
	 * @param array<mixed> $block      Parsed block (parse_blocks() shape).
	 * @param string       $inner_html The block's own HTML the ranges index.
	 * @param array<mixed> $names      Attribute names to locate; empty = the block type's chain.
	 * @return array{leaves: list<array{path: list<int|string>, format: string, start: int, end: int, quote: string}>, skipped: list<string>, error: string}
	 */
	public static function locate( array $block, string $inner_html, array $names = [] ): array {
		$block_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$schema     = self::schema( $block_name );
		$wanted     = [];

		foreach ( ( $names !== [] ? $names : self::chain( $block_name ) ) as $name ) {
			if ( is_string( $name ) && self::is_valid_name( $name ) && ! in_array( $name, $wanted, true ) ) {
				$wanted[] = $name;
			}

			if ( count( $wanted ) >= self::MAX_NAMES ) {
				break;
			}
		}

		$out = [
			'leaves'  => [],
			'skipped' => [],
			'error'   => '',
		];

		$doc   = null;
		$query = self::query_state();

		foreach ( $wanted as $name ) {
			if ( preg_match( self::IGNORE_NAMES, $name ) ) {
				continue;
			}

			$def = is_array( $schema[ $name ] ?? null ) ? $schema[ $name ] : [];

			if ( ! is_string( $def['source'] ?? null ) || $def['source'] === '' ) {
				continue;
			}

			$doc ??= self::tokenize( $inner_html );

			if ( $doc['error'] !== '' ) {
				$out['error']     = $doc['error'];
				$out['skipped'][] = $name;
				continue;
			}

			$located = self::locate_source( $doc, strlen( $inner_html ), $def, $name, [ $name ], -1, $out['leaves'], $query );

			if ( $query['error'] !== '' ) {
				$out['error']     = $query['error'];
				$out['skipped'][] = $name;
				continue;
			}

			if ( ! $located ) {
				$out['skipped'][] = $name;
			}
		}

		return $out;
	}

	/**
	 * The elements of an HTML fragment as read() and locate() see them: parallel lists in document order (`close_end`
	 * after `</x>`, or where an implied close starts). With `error` set every list is empty.
	 *
	 * @param string $html Fragment.
	 * @return array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>, tag_start: list<int>, tag_end: list<int>, close_end: array<int, int>, values: list<array<string, array{int, int, string}>>, comments: list<array{int, int, int}>, error: string}
	 */
	public static function elements( string $html ): array {
		return self::tokenize( $html );
	}

	/**
	 * Locate one sourced attribute (or one sub-attribute of a query item).
	 *
	 * @param array<string, mixed> $doc    Tokenized fragment.
	 * @param int                  $length Length of the fragment.
	 * @param array<mixed>         $def    Attribute schema.
	 * @param string               $name   Attribute name.
	 * @param array<int|string>    $path   Path of the attribute (name, or name, item index, sub-name, …).
	 * @param int                  $scope  Element index the search is limited to the descendants of; -1 for the whole fragment.
	 * @param array<int, array>    $leaves Leaves found so far, appended to.
	 * @param array<string, mixed> $query  The read's query state ({@see self::query_state()}).
	 * @return bool False when read() would skip the attribute (a source that is not text, an unsupported selector), or when the query state holds an error.
	 * @phpstan-param Elements $doc
	 * @phpstan-param list<int|string> $path
	 * @phpstan-param list<Leaf> $leaves
	 * @phpstan-param Query $query
	 */
	private static function locate_source( array $doc, int $length, array $def, string $name, array $path, int $scope, array &$leaves, array &$query ): bool {
		$source   = is_string( $def['source'] ?? null ) ? $def['source'] : '';
		$selector = is_string( $def['selector'] ?? null ) ? $def['selector'] : '';

		if ( in_array( $source, self::CONTENT_SOURCES, true ) ) {
			if ( $selector === '' ) {
				$leaves[] = self::leaf( $path, self::FORMAT_HTML, $scope < 0 ? 0 : $doc['start'][ $scope ], $scope < 0 ? $length : ( $doc['end'][ $scope ] ?? $doc['start'][ $scope ] ), '' );

				return true;
			}

			$match = self::query( $doc, $selector, $scope, $query );

			if ( $match === false ) {
				return false;
			}

			if ( $match !== null ) {
				$leaves[] = self::leaf( $path, self::FORMAT_HTML, $doc['start'][ $match ], $doc['end'][ $match ] ?? $doc['start'][ $match ], '' );
			}

			return true;
		}

		if ( $source === 'attribute' ) {
			$html_attr = strtolower( is_string( $def['attribute'] ?? null ) ? $def['attribute'] : '' );

			if ( $html_attr === '' || ( ! preg_match( self::TEXT_ATTRIBUTE_NAMES, $name ) && ! preg_match( self::TEXT_ATTRIBUTE_NAMES, $html_attr ) ) ) {
				return false;
			}

			if ( $selector === '' ) {
				// In a query item the item element holds the attribute; at the top level there is none, as in read().
				if ( $scope < 0 ) {
					return false;
				}

				$match = $scope;
			} else {
				$match = self::query( $doc, $selector, $scope, $query );

				if ( $match === false ) {
					return false;
				}
			}

			if ( $match !== null && isset( $doc['values'][ $match ][ $html_attr ] ) ) {
				[ $start, $end, $quote ] = $doc['values'][ $match ][ $html_attr ];

				$leaves[] = self::leaf( $path, self::FORMAT_TEXT, $start, $end, $quote );
			}

			return true;
		}

		if ( $source === 'query' ) {
			$sub_query = is_array( $def['query'] ?? null ) ? $def['query'] : [];

			if ( $selector === '' || $sub_query === [] ) {
				return false;
			}

			$items = self::query_all( $doc, $selector, $scope, $query );

			if ( $items === false ) {
				return false;
			}

			foreach ( $items as $i => $element ) {
				foreach ( $sub_query as $sub_name => $sub_def ) {
					if ( ! is_string( $sub_name ) || ! is_array( $sub_def ) || preg_match( self::IGNORE_NAMES, $sub_name ) ) {
						continue;
					}

					// A sub-attribute that is not text is left out of the item; the item itself stays.
					self::locate_source( $doc, $length, $sub_def, $sub_name, array_merge( $path, [ $i, $sub_name ] ), $element, $leaves, $query );

					if ( $query['error'] !== '' ) {
						return false;
					}
				}
			}

			return true;
		}

		// URL, boolean, raw, tag, text, node and meta sources are not text.
		return false;
	}

	/**
	 * One located leaf.
	 *
	 * @param array<int|string> $path   Attribute path.
	 * @param string            $format html or text.
	 * @param int               $start  Range start.
	 * @param int               $end    Range end (exclusive).
	 * @param string            $quote  Quote style of an attribute value; '' otherwise.
	 * @return array{path: list<int|string>, format: string, start: int, end: int, quote: string}
	 * @phpstan-param list<int|string> $path
	 */
	private static function leaf( array $path, string $format, int $start, int $end, string $quote ): array {
		return [
			'path'   => $path,
			'format' => $format,
			'start'  => $start,
			'end'    => $end,
			'quote'  => $quote,
		];
	}

	/**
	 * Whether a block or any block above it on a path carries the "Do not
	 * translate" marker.
	 *
	 * @param array<mixed> $blocks Parsed blocks (parse_blocks() shape).
	 * @param array<mixed> $path   Index path over real blocks (placeholders skipped).
	 * @return bool
	 */
	public static function path_is_skip_marked( array $blocks, array $path ): bool {
		$current = $blocks;

		foreach ( $path as $idx ) {
			$real = [];

			foreach ( $current as $b ) {
				if ( is_array( $b ) && is_string( $b['blockName'] ?? null ) && $b['blockName'] !== '' ) {
					$real[] = $b;
				}
			}

			$idx = is_numeric( $idx ) ? (int) $idx : -1;

			if ( $idx < 0 || ! isset( $real[ $idx ] ) ) {
				return false;
			}

			$node  = $real[ $idx ];
			$attrs = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];

			if ( ! empty( $attrs[ BlockSkipFilter::SKIP_ATTRIBUTE ] ) ) {
				return true;
			}

			$current = is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : [];
		}

		return false;
	}

	/**
	 * Registered attribute schema of a block type, or [] when unregistered.
	 *
	 * @param string $block_name Block name.
	 * @return array<mixed>
	 */
	public static function schema( string $block_name ): array {
		if ( $block_name === '' || ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return [];
		}

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );

		return ( $type instanceof \WP_Block_Type && is_array( $type->attributes ) ) ? $type->attributes : [];
	}

	/**
	 * Same inclusion rules as `findTextAttrsInSchema()` in block-toolbar.js.
	 *
	 * @param string $name Attribute name.
	 * @param mixed  $def  Attribute schema.
	 * @return bool
	 */
	private static function is_text_attribute( string $name, $def ): bool {
		if ( ! is_array( $def ) || preg_match( self::IGNORE_NAMES, $name ) ) {
			return false;
		}

		$type   = $def['type'] ?? '';
		$source = $def['source'] ?? '';

		if ( $type === 'rich-text' ) {
			return true;
		}

		if ( $type !== 'string' ) {
			return false;
		}

		if ( in_array( $source, [ 'html', 'rich-text', 'children' ], true ) ) {
			return true;
		}

		return $source === 'attribute' && (bool) preg_match( self::TEXT_ATTRIBUTE_NAMES, $name );
	}

	/**
	 * Attribute names accepted from a caller.
	 *
	 * @param string $name Candidate.
	 * @return bool
	 */
	private static function is_valid_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name );
	}

	/**
	 * One pass over an HTML fragment (lists and ranges as elements() documents): up to BATCH_BYTES at once, then a token
	 * at a time. A `<` no `>` closes is text; raw-text content is skipped; a regex failure, too many attributes or a spent
	 * budget give ERROR_TOKENIZER.
	 *
	 * @param string $html Fragment.
	 * @return array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>, tag_start: list<int>, tag_end: list<int>, close_end: array<int, int>, values: list<array<string, array{int, int, string}>>, comments: list<array{int, int, int}>, error: string}
	 */
	private static function tokenize( string $html ): array {
		if ( $html === '' ) {
			return self::no_elements( '' );
		}

		$tag_names  = [];
		$attrs      = [];
		$parents    = [];
		$starts     = [];
		$ends       = [];
		$tag_starts = [];
		$tag_ends   = [];
		$close_ends = [];
		$values     = [];
		$comments   = [];
		$stack      = [];
		$open       = [];
		$length     = strlen( $html );
		$offset     = 0;
		$pattern    = self::TOKEN_PATTERN;
		$closers    = true;
		$closer     = -1;
		$opener     = -1;
		$stop       = 0;
		$last       = null;
		$scanned    = 0;
		$budget     = self::SCAN_BYTES_PER_BYTE * $length + self::SCAN_BYTES;
		$batch      = null;
		$count      = 0;
		$next       = 0;
		$tag        = [];
		$decoded    = [];
		$ranges     = [];

		if ( $length <= self::BATCH_BYTES ) {
			$count = preg_match_all( $pattern, $html, $batch, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

			if ( $count === false ) {
				$pattern = self::OPENER_PATTERN;
				$batch   = null;
			}
		}

		while ( true ) {
			if ( $batch !== null ) {
				if ( $next < $count ) {
					$tag = $batch[ $next++ ];
					$at  = $tag[0][1];

					if ( $at < $offset ) {
						// Inside raw text; a match that runs past its end hides the tokens there.
						if ( $at + strlen( $tag[0][0] ) > $offset ) {
							$batch = null;
						}

						continue;
					}
				} else {
					// A batch of the token pattern is the first, matched from the fragment's start.
					$at   = -1;
					$stop = $count > 0 ? $batch[ $count - 1 ][0][1] + strlen( $batch[ $count - 1 ][0][0] ) : 0;
				}
			} else {
				$found = $offset < $length ? preg_match( $pattern, $html, $tag, PREG_OFFSET_CAPTURE, $offset ) : 0;

				if ( $found === false ) {
					if ( $pattern !== self::TOKEN_PATTERN ) {
						return self::no_elements( self::ERROR_TOKENIZER );
					}

					$pattern = self::OPENER_PATTERN;
					continue;
				}

				$at   = $found === 1 ? $tag[0][1] : -1;
				$stop = $offset;
			}

			if ( $at < 0 ) {
				// No token left: past the first opener with no `-->` every `<!--` is text; match the rest without comments.
				$unclosed = $pattern === self::TOKEN_PATTERN && $stop + 4 <= $length ? strpos( $html, '<!--', $stop ) : false;

				if ( $unclosed === false ) {
					break;
				}

				$closers = false;
				$pattern = self::TAG_PATTERN;
				$offset  = max( $offset, $unclosed + 4 );

				if ( $batch !== null ) {
					$count = preg_match_all( $pattern, $html, $batch, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $offset );
					$next  = 0;

					if ( $count === false ) {
						return self::no_elements( self::ERROR_TOKENIZER );
					}
				}

				continue;
			}

			$end  = $at + strlen( $tag[0][0] );
			$name = $tag[2][0] ?? '';

			if ( $name === '' ) {
				if ( $end - $at === 4 ) {
					// An opener matched alone ({@see self::OPENER_PATTERN}): its closer is searched for.
					$close = self::closer( $html, $end );

					if ( $close < -1 ) {
						return self::no_elements( self::ERROR_TOKENIZER );
					}

					if ( $close === -1 ) {
						// No `-->` follows: this opener and every later one are text, matched no more.
						$closers = false;
						$pattern = self::TAG_PATTERN;
						$offset  = $end;
						continue;
					}

					$end = $close + 3;
				}

				$parent     = $stack !== [] ? $stack[ count( $stack ) - 1 ] : -1;
				$comments[] = [ $at, $end, $parent ];
				$offset     = $end;

				// Following comments share its parent: in a batch each is its own match, else each is found by its closer.
				if ( $batch !== null ) {
					while ( $next < $count && ! isset( $batch[ $next ][1] ) ) {
						$at         = $batch[ $next ][0][1];
						$offset     = $at + strlen( $batch[ $next++ ][0][0] );
						$comments[] = [ $at, $offset, $parent ];
					}
				} else {
					while ( substr_compare( $html, '<!--', $offset, 4 ) === 0 ) {
						$close = self::closer( $html, $offset + 4 );

						// An opener without a closer, or a failed search, is left to the token search.
						if ( $close < 0 ) {
							break;
						}

						$comments[] = [ $offset, $close + 3, $parent ];
						$offset     = $close + 3;
					}
				}

				continue;
			}

			if ( ( $html[ $end ] ?? '' ) !== '>' ) {
				if ( $last === null ) {
					$last = strrpos( $html, '>' );
					$last = $last === false ? -1 : $last;
				}

				// No `>` after this `<`: nothing from here on closes, neither a tag nor a comment.
				if ( $at > $last ) {
					break;
				}

				$span = $end - $at - 1;

				// Each `<` the match ran over fails the same way, unless a quote or a closed `<!--` lies between.
				$past = strcspn( $html, '<', $at + 1, $span ) === $span;

				if ( ! $past && strcspn( $html, '"\'', $at + 1, $span ) === $span ) {
					if ( $closers ) {
						if ( $opener <= $at ) {
							$opener = strpos( $html, '<!--', $at + 1 );
							$opener = $opener === false ? $length : $opener;
						}

						// A closer found for an earlier opener is reused when it lies past this one; $length means none.
						if ( $opener < $end && $closer < $opener + 4 ) {
							$closer = self::closer( $html, $opener + 4 );

							if ( $closer < -1 ) {
								return self::no_elements( self::ERROR_TOKENIZER );
							}

							$closer = $closer === -1 ? $length : $closer;
						}

						if ( $opener < $end && $closer === $length ) {
							// No `-->` follows: this opener and every later one are text.
							$closers = false;
							$pattern = self::TAG_PATTERN;

							if ( $batch !== null ) {
								$count = preg_match_all( $pattern, $html, $batch, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $end );
								$next  = 0;

								if ( $count === false ) {
									return self::no_elements( self::ERROR_TOKENIZER );
								}
							}
						}
					}

					$past = ! $closers || $opener >= $end;
				}

				if ( $past ) {
					$offset = $end;
					continue;
				}

				$scanned += $end - $at;

				if ( $scanned > $budget ) {
					// Over the budget the filter may raise it; past the budget it sets the fragment is refused.
					$budget = WorkBudget::get( WorkBudget::TOKENIZER_SCAN ) * $length + self::SCAN_BYTES;

					if ( $scanned > $budget ) {
						return self::no_elements( self::ERROR_TOKENIZER );
					}
				}

				$offset = $at + 1;
				$batch  = null;
				continue;
			}

			$after  = $end + 1;
			$offset = $after;
			$name   = strtolower( $name );

			if ( ( $tag[1][0] ?? '' ) === '/' ) {
				if ( ( $open[ $name ] ?? 0 ) === 0 ) {
					continue;
				}

				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $tag_names[ $stack[ $i ] ] === $name ) {
						$closed = $stack[ $i ];

						foreach ( array_splice( $stack, $i ) as $element ) {
							$ends[ $element ]       = $at;
							$close_ends[ $element ] = $at;
							--$open[ $tag_names[ $element ] ];
						}

						$close_ends[ $closed ] = $after;
						break;
					}
				}
				continue;
			}

			if ( isset( self::P_CLOSERS[ $name ] ) && ( $open['p'] ?? 0 ) > 0 ) {
				self::close_implied( [ 'p' ], self::P_SCOPE, $at, $stack, $tag_names, $ends, $close_ends, $open );
			}

			foreach ( self::IMPLIED_CLOSERS[ $name ] ?? [] as [ $names, $scope ] ) {
				self::close_implied( $names, $scope, $at, $stack, $tag_names, $ends, $close_ends, $open );
			}

			$raw_attrs = $tag[3][0] ?? '';
			$index     = count( $tag_names );

			if ( $raw_attrs === '' ) {
				$self    = isset( self::VOID_ELEMENTS[ $name ] );
				$decoded = [];
				$ranges  = [];
			} else {
				$self = str_ends_with( rtrim( $raw_attrs ), '/' ) || isset( self::VOID_ELEMENTS[ $name ] );

				if ( ! self::attributes( $raw_attrs, $tag[3][1] ?? $end, $decoded, $ranges ) ) {
					return self::no_elements( self::ERROR_TOKENIZER );
				}
			}

			$tag_names[]          = $name;
			$attrs[]              = $decoded;
			$values[]             = $ranges;
			$parents[]            = $stack !== [] ? $stack[ count( $stack ) - 1 ] : -1;
			$starts[]             = $after;
			$tag_starts[]         = $at;
			$tag_ends[]           = $after;
			$ends[ $index ]       = $self ? $after : $length;
			$close_ends[ $index ] = $self ? $after : $length;

			if ( $self ) {
				continue;
			}

			if ( isset( self::RAW_TEXT_ELEMENTS[ $name ] ) ) {
				$close = self::raw_end( $html, $name, $after );

				if ( $close === null ) {
					return self::no_elements( self::ERROR_TOKENIZER );
				}

				if ( $close !== [] ) {
					$ends[ $index ]       = $close[0];
					$close_ends[ $index ] = $close[1];
				}

				$offset = $close_ends[ $index ];
				continue;
			}

			$stack[]       = $index;
			$open[ $name ] = ( $open[ $name ] ?? 0 ) + 1;
		}

		return [
			'tag'       => $tag_names,
			'attrs'     => $attrs,
			'parent'    => $parents,
			'start'     => $starts,
			'end'       => $ends,
			'tag_start' => $tag_starts,
			'tag_end'   => $tag_ends,
			'close_end' => $close_ends,
			'values'    => $values,
			'comments'  => $comments,
			'error'     => '',
		];
	}

	/**
	 * The element document of an empty fragment (error '') or of one that could not be tokenized (error named).
	 *
	 * @param string $error The error; '' for an empty fragment.
	 * @return array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>, tag_start: list<int>, tag_end: list<int>, close_end: array<int, int>, values: list<array<string, array{int, int, string}>>, comments: list<array{int, int, int}>, error: string}
	 */
	private static function no_elements( string $error ): array {
		return [
			'tag'       => [],
			'attrs'     => [],
			'parent'    => [],
			'start'     => [],
			'end'       => [],
			'tag_start' => [],
			'tag_end'   => [],
			'close_end' => [],
			'values'    => [],
			'comments'  => [],
			'error'     => $error,
		];
	}

	/**
	 * Close the nearest open element of the given names and every element above it where a start tag begins (HTML's
	 * optional end tag rules); nothing when a scope element sits above it or none of the names is open.
	 *
	 * @param array<int, string>  $names      Tag names the start tag closes.
	 * @param array<string, true> $scope      Tag names that end the search, as keys.
	 * @param int                 $at         Where the start tag begins.
	 * @param array<int, int>     $stack      Open element indexes, innermost last.
	 * @param array<int, string>  $tag_names  Tag name per element.
	 * @param array<int, int>     $ends       Content end per element.
	 * @param array<int, int>     $close_ends Outer end per element.
	 * @param array<string, int>  $open       Open element count per tag name.
	 * @return void
	 * @phpstan-param list<string> $names
	 * @phpstan-param list<int> $stack
	 * @phpstan-param list<string> $tag_names
	 */
	private static function close_implied( array $names, array $scope, int $at, array &$stack, array $tag_names, array &$ends, array &$close_ends, array &$open ): void {
		$any = false;

		foreach ( $names as $name ) {
			$any = $any || ( $open[ $name ] ?? 0 ) > 0;
		}

		if ( ! $any ) {
			return;
		}

		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			$tag = $tag_names[ $stack[ $i ] ];

			if ( in_array( $tag, $names, true ) ) {
				foreach ( array_splice( $stack, $i ) as $element ) {
					$ends[ $element ]       = $at;
					$close_ends[ $element ] = $at;
					--$open[ $tag_names[ $element ] ];
				}

				return;
			}

			if ( isset( $scope[ $tag ] ) ) {
				return;
			}
		}
	}

	/**
	 * Where the first `-->` at or after an offset starts: at the first `>` when two dashes at or after the offset
	 * precede it (a byte search dash runs cannot slow), else the first `-->` after that `>`.
	 *
	 * @param string $html Fragment.
	 * @param int    $from Where the search starts (after a comment opener).
	 * @return int The closer's offset; -1 when none follows; -2 when the regex failed.
	 */
	private static function closer( string $html, int $from ): int {
		$gt = strpos( $html, '>', $from );

		if ( $gt === false ) {
			return -1;
		}

		if ( $gt >= $from + 2 && $html[ $gt - 1 ] === '-' && $html[ $gt - 2 ] === '-' ) {
			return $gt - 2;
		}

		$match = [];
		$found = preg_match( self::CLOSER_PATTERN, $html, $match, PREG_OFFSET_CAPTURE, $gt + 1 );

		if ( $found === false ) {
			return -2;
		}

		return $found === 1 ? $match[0][1] : -1;
	}

	/**
	 * Where a raw-text element ends: the first `</name` followed by whitespace, `/` or `>`, through the next `>`
	 * (case-insensitive). No `>` after it means none after a later one either, so the element is unclosed.
	 *
	 * @param string $html Fragment.
	 * @param string $name The element's tag name, lowercase.
	 * @param int    $from Where its content starts.
	 * @return array{}|array{int, int}|null The end tag's start and end, [] when the element is unclosed, null when the regex failed.
	 */
	private static function raw_end( string $html, string $name, int $from ): ?array {
		$end   = [];
		$found = preg_match( '#</' . $name . '(?=[\s/>])#i', $html, $end, PREG_OFFSET_CAPTURE, $from );

		if ( $found === false ) {
			return null;
		}

		if ( $found === 0 ) {
			return [];
		}

		$gt = strpos( $html, '>', $end[0][1] );

		return $gt === false ? [] : [ $end[0][1], $gt + 1 ];
	}

	/**
	 * The decoded attributes of one start tag with each raw value range and quote, in fragment coordinates; the first
	 * of equal names wins, a valueless one has an empty range after its name. Longer than ATTRIBUTE_BATCH_BYTES: one
	 * attribute at a time, at most {@see self::MAX_ATTRIBUTES} names (or the `perflocale/resync/work_budget` bound).
	 *
	 * @param string                                      $raw     Everything between the tag name and `>`.
	 * @param int                                         $base    Offset of $raw in the fragment.
	 * @param array<string, string>|null                  $decoded Set to the decoded value per name.
	 * @param array<string, array{int, int, string}>|null $ranges  Set to the raw value range and quote per name.
	 * @return bool False when the attribute regex failed or the names are over the bound.
	 * @phpstan-param-out array<string, string> $decoded
	 * @phpstan-param-out array<string, array{int, int, string}> $ranges
	 */
	private static function attributes( string $raw, int $base, ?array &$decoded, ?array &$ranges ): bool {
		$decoded = [];
		$ranges  = [];
		$batch   = null;
		$count   = 0;
		$next    = 0;
		$offset  = 0;
		$a       = [];
		$bound   = self::MAX_ATTRIBUTES;

		if ( strlen( $raw ) <= self::ATTRIBUTE_BATCH_BYTES ) {
			$count = preg_match_all( self::ATTRIBUTE_PATTERN, $raw, $batch, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

			if ( $count === false ) {
				return false;
			}
		}

		while ( true ) {
			if ( $batch !== null ) {
				if ( $next >= $count ) {
					break;
				}

				$a = $batch[ $next++ ];
			} else {
				$found = preg_match( self::ATTRIBUTE_PATTERN, $raw, $a, PREG_OFFSET_CAPTURE, $offset );

				if ( $found === false ) {
					return false;
				}

				if ( $found === 0 ) {
					break;
				}

				$offset = $a[0][1] + strlen( $a[0][0] );
			}

			$name = strtolower( $a[1][0] );

			if ( isset( $decoded[ $name ] ) ) {
				continue;
			}

			if ( isset( $a[2] ) && $a[2][1] >= 0 ) {
				$group = $a[2];
				$quote = '"';
			} elseif ( isset( $a[3] ) && $a[3][1] >= 0 ) {
				$group = $a[3];
				$quote = "'";
			} elseif ( isset( $a[4] ) && $a[4][1] >= 0 ) {
				$group = $a[4];
				$quote = '';
			} else {
				$group = [ '', $a[1][1] + strlen( $a[1][0] ) ];
				$quote = '';
			}

			$at               = $base + $group[1];
			$decoded[ $name ] = $group[0] === '' ? '' : html_entity_decode( $group[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$ranges[ $name ]  = [ $at, $at + strlen( $group[0] ), $quote ];

			if ( $batch === null && count( $decoded ) > $bound ) {
				// Over the default the filter may raise the bound, read once; past the bound the tag is refused.
				if ( $bound === self::MAX_ATTRIBUTES ) {
					$bound = WorkBudget::get( WorkBudget::TAG_ATTRIBUTES );
				}

				if ( count( $decoded ) > $bound ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The query state of one read: selector work left ({@see self::$selector_budget}) and the error that stopped it.
	 *
	 * @return array{budget: int, error: string}
	 */
	private static function query_state(): array {
		return [
			'budget' => self::$selector_budget,
			'error'  => '',
		];
	}

	/**
	 * First element, in document order, matching a selector.
	 *
	 * Supports comma alternatives; compounds made of an optional tag, `.class`,
	 * `[attr]` and `:not([attr])`; descendant (space) and child (`>`)
	 * combinators. That covers every text selector in core's block.json files.
	 *
	 * @param array<string, mixed> $doc      Tokenized fragment.
	 * @param string               $selector CSS selector.
	 * @param int                  $scope    Element index whose descendants are searched; -1 for every element.
	 * @param array<string, mixed> $query    The read's query state; false is answered with its error set when the query is over budget or a regex failed.
	 * @return int|false|null Element index, null when none matches, false when the selector is unsupported or the query was refused.
	 * @phpstan-param Elements $doc
	 * @phpstan-param Query $query
	 */
	private static function query( array $doc, string $selector, int $scope, array &$query ) {
		$alternatives = self::alternatives( $selector );

		if ( $alternatives === null ) {
			return false;
		}

		$found = self::select( $doc, $alternatives, self::candidates( $doc, $scope ), true, $query );

		return $found === false ? false : ( $found[0] ?? null );
	}

	/**
	 * Every element, in document order, matching a selector.
	 *
	 * @param array<string, mixed> $doc      Tokenized fragment.
	 * @param string               $selector CSS selector.
	 * @param int                  $scope    Element index whose descendants are searched; -1 for every element.
	 * @param array<string, mixed> $query    The read's query state; false is answered with its error set when the query is over budget or a regex failed.
	 * @return list<int>|false Element indexes, false when the selector is unsupported or the query was refused.
	 * @phpstan-param Elements $doc
	 * @phpstan-param Query $query
	 */
	private static function query_all( array $doc, string $selector, int $scope, array &$query ) {
		$alternatives = self::alternatives( $selector );

		if ( $alternatives === null ) {
			return false;
		}

		return self::select( $doc, $alternatives, self::candidates( $doc, $scope ), false, $query );
	}

	/**
	 * The candidates matching any alternative, in document order: the first only, or all. Direct recursion runs within
	 * {@see self::DIRECT_WORK_PER_STATE} units per candidate and step, then the memo takes over with the same answers:
	 * repeated descendant steps that miss are combinatorial by recursion, linear with the memo.
	 *
	 * @param array<string, mixed> $doc          Tokenized fragment.
	 * @param array<int, array>    $alternatives Parsed alternatives.
	 * @param array<int, int>      $candidates   Element indexes, in document order.
	 * @param bool                 $first        Whether the first match ends the search.
	 * @param array<string, mixed> $query        The read's query state; the work spent is taken from its budget.
	 * @return list<int>|false Matching element indexes, false with the query's error set when it was refused.
	 * @phpstan-param Elements $doc
	 * @phpstan-param list<list<Step>> $alternatives
	 * @phpstan-param list<int> $candidates
	 * @phpstan-param Query $query
	 */
	private static function select( array $doc, array $alternatives, array $candidates, bool $first, array &$query ) {
		$steps = 0;

		foreach ( $alternatives as $alternative ) {
			$steps += count( $alternative );
		}

		$allowance = min( $query['budget'], self::DIRECT_WORK_PER_STATE * ( count( $candidates ) + 1 ) * $steps );
		$work      = $allowance;
		$memo      = null;
		$out       = [];

		foreach ( $candidates as $i ) {
			$match = false;

			if ( $memo === null ) {
				foreach ( $alternatives as $alternative ) {
					$match = self::matches_directly( $doc, $i, $alternative, count( $alternative ) - 1, $work );

					if ( $match || $work < 0 ) {
						break;
					}
				}

				if ( $work < 0 ) {
					$query['budget'] -= $allowance;
					$memo             = self::memo();
				}
			}

			if ( $memo !== null ) {
				$match = self::matches_any( $doc, $i, $alternatives, $memo, $query );

				if ( $query['error'] !== '' ) {
					return false;
				}
			}

			if ( $match ) {
				$out[] = $i;

				if ( $first ) {
					break;
				}
			}
		}

		if ( $memo === null ) {
			$query['budget'] -= $allowance - $work;
		}

		return $out;
	}

	/**
	 * Whether element $i matches steps[0..$k] (steps[$k] on $i itself) by direct recursion, one unit of $work per
	 * compound tried; when none is left, or a regex fails, the answer is abandoned with $work below zero.
	 *
	 * @param array<string, mixed>                                                                                      $doc   Tokenized fragment.
	 * @phpstan-param Elements $doc
	 * @param int                                                                                                       $i     Element index.
	 * @param list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}> $steps Parsed steps.
	 * @param int                                                                                                       $k     Step index.
	 * @param int                                                                                                       $work  Units left, decreased.
	 * @return bool
	 */
	private static function matches_directly( array $doc, int $i, array $steps, int $k, int &$work ): bool {
		if ( ! isset( $steps[ $k ] ) || --$work < 0 ) {
			return false;
		}

		$matches = self::matches_compound( $doc['tag'][ $i ] ?? '', $doc['attrs'][ $i ] ?? [], $steps[ $k ] );

		if ( $matches !== true ) {
			if ( $matches === null ) {
				$work = -1;
			}

			return false;
		}

		if ( $k === 0 ) {
			return true;
		}

		$parent = $doc['parent'][ $i ] ?? -1;

		if ( $steps[ $k ]['combinator'] === '>' ) {
			return $parent >= 0 && self::matches_directly( $doc, $parent, $steps, $k - 1, $work );
		}

		while ( $parent >= 0 && $work >= 0 ) {
			if ( self::matches_directly( $doc, $parent, $steps, $k - 1, $work ) ) {
				return true;
			}

			$parent = $doc['parent'][ $parent ] ?? -1;
		}

		return false;
	}

	/**
	 * The parsed alternatives of a comma-separated selector, kept once parsed ({@see self::$parsed}).
	 *
	 * @param string $selector CSS selector.
	 * @return list<list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}>>|null Null when unsupported.
	 * @phpstan-return list<list<Step>>|null
	 */
	private static function alternatives( string $selector ): ?array {
		if ( isset( self::$parsed[ $selector ] ) ) {
			return self::$parsed[ $selector ];
		}

		$alternatives = [];

		foreach ( explode( ',', $selector ) as $alt ) {
			$parsed = self::parse_selector( $alt );

			if ( $parsed === null ) {
				return null;
			}

			$alternatives[] = $parsed;
		}

		if ( count( self::$parsed ) >= self::PARSED_SELECTORS ) {
			self::$parsed = [];
		}

		self::$parsed[ $selector ] = $alternatives;

		return $alternatives;
	}

	/**
	 * The elements a query looks at: every element, or the descendants of one (in document order, the elements after
	 * it that start before its content ends).
	 *
	 * @param array<string, mixed> $doc   Tokenized fragment.
	 * @param int                  $scope Element index; -1 for every element.
	 * @return list<int>
	 * @phpstan-param Elements $doc
	 */
	private static function candidates( array $doc, int $scope ): array {
		if ( $scope < 0 ) {
			return array_keys( $doc['tag'] );
		}

		$out   = [];
		$end   = $doc['end'][ $scope ] ?? 0;
		$count = count( $doc['tag'] );

		for ( $i = $scope + 1; $i < $count && $doc['tag_start'][ $i ] < $end; $i++ ) {
			$out[] = $i;
		}

		return $out;
	}

	/**
	 * An empty memo of selector states for one query: per alternative and step, one byte per element for whether it
	 * matches the steps up to that step (`match`) and whether it or an ancestor does (`above`), each decided once.
	 *
	 * @return array{match: array<int, array<int, string>>, above: array<int, array<int, string>>}
	 */
	private static function memo(): array {
		return [
			'match' => [],
			'above' => [],
		];
	}

	/**
	 * Whether an element matches any parsed alternative, with the query's memo of states.
	 *
	 * @param array<string, mixed> $doc          Tokenized fragment.
	 * @param int                  $i            Element index.
	 * @param array<int, array>    $alternatives Parsed alternatives.
	 * @param array<string, mixed> $memo         The query's memo.
	 * @param array<string, mixed> $query        The read's query state.
	 * @return bool
	 * @phpstan-param Elements $doc
	 * @phpstan-param list<list<Step>> $alternatives
	 * @phpstan-param Memo $memo
	 * @phpstan-param Query $query
	 */
	private static function matches_any( array $doc, int $i, array $alternatives, array &$memo, array &$query ): bool {
		foreach ( $alternatives as $a => $steps ) {
			if ( self::matches_steps( $doc, $i, $steps, count( $steps ) - 1, $a, $memo, $query ) ) {
				return true;
			}

			if ( $query['error'] !== '' ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Parse one comma-free selector into compound steps.
	 *
	 * @param string $selector Selector without commas.
	 * @return list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}>|null Null when unsupported.
	 */
	private static function parse_selector( string $selector ): ?array {
		$normalised = preg_replace( '/\s*>\s*/', ' > ', trim( $selector ) );

		if ( $normalised === null ) {
			return null;
		}

		$selector = trim( $normalised );

		if ( $selector === '' ) {
			return null;
		}

		$tokens = preg_split( '/\s+/', $selector );

		if ( $tokens === false ) {
			return null;
		}

		$steps      = [];
		$combinator = ' ';

		foreach ( $tokens as $token ) {
			if ( $token === '>' ) {
				if ( $steps === [] || $combinator === '>' ) {
					return null;
				}
				$combinator = '>';
				continue;
			}

			if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*|\*)?((?:\.[A-Za-z_-][A-Za-z0-9_-]*|\[[A-Za-z_:][-A-Za-z0-9_:.]*\]|:not\(\[[A-Za-z_:][-A-Za-z0-9_:.]*\]\))*)$/', $token, $m ) ) {
				return null;
			}

			if ( preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)|:not\(\[([^\]]+)\]\)|\[([^\]]+)\]/', $m[2], $parts, PREG_SET_ORDER ) === false ) {
				return null;
			}

			$tag  = strtolower( $m[1] );
			$step = [
				'combinator' => $steps === [] ? '' : $combinator,
				'tag'        => $tag === '*' ? '' : $tag,
				'classes'    => [],
				'has'        => [],
				'not'        => [],
			];

			foreach ( $parts as $p ) {
				if ( ( $p[1] ?? '' ) !== '' ) {
					$step['classes'][] = $p[1];
				} elseif ( ( $p[2] ?? '' ) !== '' ) {
					$step['not'][] = strtolower( $p[2] );
				} elseif ( ( $p[3] ?? '' ) !== '' ) {
					$step['has'][] = strtolower( $p[3] );
				}
			}

			$steps[]    = $step;
			$combinator = ' ';
		}

		return $combinator === '>' ? null : $steps;
	}

	/**
	 * Whether element $i matches steps[0..$k] as {@see self::matches_directly()} answers it, decided once per element
	 * and step (the memo) at one budget unit each; false with the query's error set when spent or a regex failed.
	 *
	 * @param array<string, mixed>                                                                                      $doc   Tokenized fragment.
	 * @phpstan-param Elements $doc
	 * @param int                                                                                                       $i     Element index.
	 * @param list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}> $steps Parsed steps.
	 * @param int                                                                                                       $k     Step index.
	 * @param int                                                                                                       $a     Index of the alternative the steps belong to.
	 * @param array<string, mixed>                                                                                      $memo  The query's memo.
	 * @param array<string, mixed>                                                                                      $query The read's query state.
	 * @phpstan-param Memo $memo
	 * @phpstan-param Query $query
	 * @return bool
	 */
	private static function matches_steps( array $doc, int $i, array $steps, int $k, int $a, array &$memo, array &$query ): bool {
		if ( ! isset( $steps[ $k ] ) ) {
			return false;
		}

		$known = self::state( $memo['match'], $a, $k, $i, count( $doc['tag'] ) );

		if ( $known !== self::STATE_UNKNOWN ) {
			return $known === self::STATE_TRUE;
		}

		if ( --$query['budget'] < 0 ) {
			$query['error'] = self::ERROR_SELECTOR_BUDGET;

			return false;
		}

		$matches = self::matches_compound( $doc['tag'][ $i ] ?? '', $doc['attrs'][ $i ] ?? [], $steps[ $k ] );

		if ( $matches === null ) {
			$query['error'] = self::ERROR_TOKENIZER;

			return false;
		}

		if ( $matches && $k > 0 ) {
			$parent = $doc['parent'][ $i ] ?? -1;

			if ( $parent < 0 ) {
				$matches = false;
			} elseif ( $steps[ $k ]['combinator'] === '>' ) {
				$matches = self::matches_steps( $doc, $parent, $steps, $k - 1, $a, $memo, $query );
			} else {
				$matches = self::matches_above( $doc, $parent, $steps, $k - 1, $a, $memo, $query );
			}

			if ( $query['error'] !== '' ) {
				return false;
			}
		}

		if ( isset( $memo['match'][ $a ][ $k ] ) ) {
			$memo['match'][ $a ][ $k ][ $i ] = $matches ? self::STATE_TRUE : self::STATE_FALSE;
		}

		return $matches;
	}

	/**
	 * Whether element $i or an ancestor matches steps[0..$k]. Every element walked is recorded with the answer, so
	 * ancestors are walked once per step over the query, not once per candidate.
	 *
	 * @param array<string, mixed>                                                                                      $doc   Tokenized fragment.
	 * @phpstan-param Elements $doc
	 * @param int                                                                                                       $i     Element index.
	 * @param list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}> $steps Parsed steps.
	 * @param int                                                                                                       $k     Step index.
	 * @param int                                                                                                       $a     Index of the alternative the steps belong to.
	 * @param array<string, mixed>                                                                                      $memo  The query's memo.
	 * @param array<string, mixed>                                                                                      $query The read's query state.
	 * @phpstan-param Memo $memo
	 * @phpstan-param Query $query
	 * @return bool
	 */
	private static function matches_above( array $doc, int $i, array $steps, int $k, int $a, array &$memo, array &$query ): bool {
		$count  = count( $doc['tag'] );
		$walked = [];
		$answer = false;

		for ( $node = $i; $node >= 0; $node = $doc['parent'][ $node ] ?? -1 ) {
			$known = self::state( $memo['above'], $a, $k, $node, $count );

			if ( $known !== self::STATE_UNKNOWN ) {
				$answer = $known === self::STATE_TRUE;
				break;
			}

			if ( self::matches_steps( $doc, $node, $steps, $k, $a, $memo, $query ) ) {
				$answer = true;
				break;
			}

			if ( $query['error'] !== '' ) {
				return false;
			}

			if ( --$query['budget'] < 0 ) {
				$query['error'] = self::ERROR_SELECTOR_BUDGET;

				return false;
			}

			$walked[] = $node;
		}

		if ( ! isset( $memo['above'][ $a ][ $k ] ) ) {
			return $answer;
		}

		$state = $answer ? self::STATE_TRUE : self::STATE_FALSE;

		if ( $answer && $node >= 0 ) {
			$memo['above'][ $a ][ $k ][ $node ] = $state;
		}

		foreach ( $walked as $node ) {
			$memo['above'][ $a ][ $k ][ $node ] = $state;
		}

		return $answer;
	}

	/**
	 * The recorded state of one element for one alternative and step; the step's byte list is created on first use.
	 *
	 * @param array<int, array<int, string>> $states The memo's `match` or `above` lists.
	 * @param int                            $a      Alternative index.
	 * @param int                            $k      Step index.
	 * @param int                            $i      Element index.
	 * @param int                            $count  Element count of the fragment.
	 * @return string {@see self::STATE_UNKNOWN}, {@see self::STATE_FALSE} or {@see self::STATE_TRUE}.
	 */
	private static function state( array &$states, int $a, int $k, int $i, int $count ): string {
		if ( ! isset( $states[ $a ][ $k ] ) ) {
			$states[ $a ][ $k ] = str_repeat( self::STATE_UNKNOWN, max( 1, $count ) );
		}

		return $states[ $a ][ $k ][ $i ] ?? self::STATE_UNKNOWN;
	}

	/**
	 * Whether one element matches one compound selector; null when the class list could not be split.
	 *
	 * @param string                                                                                              $tag   Element tag.
	 * @param array<string, string>                                                                               $attrs Element attributes.
	 * @param array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>} $step Compound.
	 * @return bool|null
	 */
	private static function matches_compound( string $tag, array $attrs, array $step ): ?bool {
		if ( $step['tag'] !== '' && $tag !== $step['tag'] ) {
			return false;
		}

		if ( $step['classes'] !== [] ) {
			$classes = preg_split( '/\s+/', trim( $attrs['class'] ?? '' ) );

			if ( $classes === false ) {
				return null;
			}

			foreach ( $step['classes'] as $class ) {
				if ( ! in_array( $class, $classes, true ) ) {
					return false;
				}
			}
		}

		foreach ( $step['has'] as $attr ) {
			if ( ! array_key_exists( $attr, $attrs ) ) {
				return false;
			}
		}

		foreach ( $step['not'] as $attr ) {
			if ( array_key_exists( $attr, $attrs ) ) {
				return false;
			}
		}

		return true;
	}
}
