<?php
/**
 * The change row and payload of a proposal.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Automation\Policy;
use PerfLocale\Automation\PostPayload;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a proposal into the change row the policy decides and the payload the write path applies. The payload is
 * marked `parts_complete` only when its parts account for everything it adds, so it holds no source text beyond
 * what its parts show (PostPayload::holds_source_text()). Rules: docs-ai (ChangeBuilder parts_complete).
 *
 * @phpstan-type Part array{role: string, block: string, status: string, source: string, current: string, proposed: string}
 * @phpstan-type Structure array{prefixes: list<string>, attributes: array<string, array<string, array<string, true>>>}
 */
final class ChangeBuilder {

	/** Characters kept per part text. */
	public const PART_CHARS = 200;

	/** Bytes kept for the whole parts list, encoded. */
	public const PARTS_BYTES = 16 * 1024;

	/** The statuses of an unpublished translation. */
	private const UNPUBLISHED = [ 'draft', 'pending', 'auto-draft' ];

	/** Class prefixes core derives from a block's name and its block style variations. */
	private const STRUCTURAL_PREFIXES = [ 'wp-block-', 'has-text-align-', 'is-style-' ];

	/** Whole classes core derives from a block's supports and attributes (preset slugs are kebab case). */
	private const STRUCTURAL_CLASS = '/^(?:wp-element-button|align(?:left|right|center|wide|full|none)|has-background|has-fixed-layout|has-drop-cap|has-alpha-channel-opacity|has-[a-z0-9]+(?:-[a-z0-9]+)*-(?:color|background-color|border-color|font-size|font-family|gradient-background))$/D';

	/** A prefix the filter may add: a class-token prefix of at least three characters. */
	private const PREFIX_PATTERN = '/^[A-Za-z0-9_-]{3,}$/D';

	/** A class that may be structure: ASCII letters, digits, hyphens and underscores only. */
	private const CLASS_TOKEN = '/^[A-Za-z0-9_-]+$/D';

	/** Each string literal of JSON, with the colon after it when it is a key. */
	private const JSON_STRING = '/"(?:[^"\\\\]++|\\\\.)*+"(\s*:)?/';

	/**
	 * Top-level block-comment attributes core writes, with the pattern of a value that is structure.
	 *
	 * @var array<string, string>
	 */
	private const STRUCTURAL_ATTRIBUTES = [
		'align'           => '/^(?:left|center|right|wide|full|none|justify)$/D',
		'textAlign'       => '/^(?:left|center|right|justify)$/D',
		'backgroundColor' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
		'textColor'       => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
		'gradient'        => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
		'fontSize'        => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
		'fontFamily'      => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
		'borderColor'     => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
		'tagName'         => '/^(?:div|header|main|section|article|aside|footer|hr|a|button)$/D',
	];

	/**
	 * Keys of the top-level `layout` attribute core's layout support writes, with their keyword values.
	 *
	 * @var array<string, list<string>>
	 */
	private const LAYOUT_KEYWORDS = [
		'type'              => [ 'default', 'constrained', 'flex', 'grid' ],
		'justifyContent'    => [ 'left', 'center', 'right', 'space-between', 'stretch' ],
		'flexWrap'          => [ 'wrap', 'nowrap' ],
		'orientation'       => [ 'horizontal', 'vertical' ],
		'verticalAlignment' => [ 'top', 'center', 'bottom', 'stretch', 'space-between' ],
	];

	/** An HTML attribute value that is only a number. */
	private const NUMBER = '/^-?[0-9]+(?:\.[0-9]+)?$/D';

	/** Core's page markers (`<!--more-->`, `<!--nextpage-->`, `<!--noteaser-->`), exactly. */
	private const MARKERS = [ 'more', 'nextpage', 'noteaser' ];

	/** Core's More tag with a custom text (`<!--more X-->`): X. */
	private const MORE_TEXT = '/^more (.*)$/sD';

	/** A block name the attributes filter may name (as core's block type registry accepts). */
	private const BLOCK_NAME = '/^[a-z0-9-]+\/[a-z0-9-]+$/D';

	/** A block attribute the attributes filter may name. */
	private const ATTRIBUTE_NAME = '/^[A-Za-z_][A-Za-z0-9_-]*$/D';

	/** A keyword value the attributes filter may add: up to 32 class-token characters. */
	private const KEYWORD = '/^[A-Za-z0-9_-]{1,32}$/D';

	/**
	 * The policy area of a change to a translation.
	 *
	 * @param string $post_status The translation's status.
	 * @return string
	 */
	public static function area( string $post_status ): string {
		return in_array( $post_status, self::UNPUBLISHED, true ) ? Policy::AREA_DRAFT_UPDATE : Policy::AREA_PUBLISHED_UPDATE;
	}

	/**
	 * The origin of a change: `machine`, `synced` (a source-language part or near match), else `reused`.
	 *
	 * @param array<string, mixed> $counts The counts.
	 * @return string
	 */
	public static function origin( array $counts ): string {
		if ( Scalar::int( $counts['machine_translated'] ?? 0 ) > 0 ) {
			return 'machine';
		}

		if ( Scalar::int( $counts['left_source'] ?? 0 ) > 0 || Scalar::int( $counts['fuzzy'] ?? 0 ) > 0 ) {
			return 'synced';
		}

		return 'reused';
	}

	/**
	 * The parts cut to PART_CHARS per text and PARTS_BYTES for the encoded list.
	 *
	 * @param array<int, array<string, string|true>> $parts Parts.
	 * @phpstan-param list<Part> $parts
	 * @return list<Part>
	 */
	public static function parts( array $parts ): array {
		$out   = [];
		$bytes = 2;

		foreach ( $parts as $part ) {
			foreach ( [ 'source', 'current', 'proposed' ] as $field ) {
				$part[ $field ] = mb_substr( $part[ $field ], 0, self::PART_CHARS );
			}

			$size = strlen( (string) wp_json_encode( $part ) ) + ( [] === $out ? 0 : 1 );

			if ( $bytes + $size > self::PARTS_BYTES ) {
				break;
			}

			$bytes += $size;
			$out[]  = $part;
		}

		return $out;
	}

	/**
	 * The keys whose base is unknown for this translation (the state row's `fields_unknown`).
	 *
	 * @param Proposal $proposal The proposal.
	 * @return list<string>
	 */
	public static function unknown_keys( Proposal $proposal ): array {
		$out = [];

		foreach ( $proposal->field_status as $key => $status ) {
			if ( FieldMerge::STATUS_UNKNOWN_BASE === $status ) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * The change row and payload of a proposal.
	 *
	 * @param Proposal             $proposal The proposal.
	 * @param array<string, mixed> $facts    translation_id, source_id, language_id, post_status, live_title, live_excerpt, live_content, live_hash, live_digest, source_hash, source_fields, base_id, base_quality, base_fields, keys, options_hash, trigger, job_id, user_id, actor, flags, parts, mt_chars, open_before.
	 * @return array{change: array<string, mixed>, payload: array<string, mixed>, summary: array<string, mixed>}
	 */
	public static function build( Proposal $proposal, array $facts ): array {
		$counts  = $proposal->counts;
		$flags   = Scalar::strings( $facts['flags'] ?? $proposal->flags );
		$keys    = Scalar::strings( $facts['keys'] ?? [] );
		$all     = self::part_list( is_array( $facts['parts'] ?? null ) ? $facts['parts'] : [] );
		$parts   = self::parts( $all );
		$count   = static fn ( string $key ): int => Scalar::int( $counts[ $key ] ?? 0 );
		$summary = [
			'resync'                => 1,
			'reused'                => $count( Matcher::STATUS_REUSED ),
			'changed'               => $count( Matcher::STATUS_CHANGED ),
			'new'                   => $count( Matcher::STATUS_NEW ),
			'removed'               => $count( Matcher::STATUS_REMOVED ),
			'machine_translated'    => $count( 'machine_translated' ),
			'left_source'           => $count( 'left_source' ),
			'fuzzy'                 => $count( Matcher::STATUS_FUZZY ),
			'same_as_source'        => $count( Matcher::SAME_AS_SOURCE ),
			'kept_translation_only' => 0,
			'kept_removed'          => 0,
			'fields_changed'        => count( $proposal->fields ),
			'flags'                 => $flags,
			'complete'              => $proposal->complete && [] === Policy::counted_flags( $flags ),
			'open_before'           => true === ( $facts['open_before'] ?? false ),
			'source_hash'           => Scalar::str( $facts['source_hash'] ?? '' ),
			'fields_hash'           => Scalar::str( $facts['source_fields'] ?? '' ),
			'base_id'               => Scalar::int( $facts['base_id'] ?? 0 ),
			'base_quality'          => Scalar::str( $facts['base_quality'] ?? '' ),
			'options_hash'          => Scalar::str( $facts['options_hash'] ?? '' ),
			'fields'                => $keys,
			'base_fields_hash'      => Scalar::str( $facts['base_fields'] ?? '' ),
			'fields_unknown'        => self::unknown_keys( $proposal ),
			'edited_fields'         => $proposal->edited_fields,
			'mt_chars'              => Scalar::int( $facts['mt_chars'] ?? 0 ),
			'source_echo'           => count( array_filter( $all, [ PostPayload::class, 'echoes_source' ] ) ),
		];

		$payload = [ 'post_content' => $proposal->content ];

		if ( $proposal->title !== Scalar::str( $facts['live_title'] ?? '' ) ) {
			$payload['post_title'] = $proposal->title;
		}

		if ( $proposal->excerpt !== Scalar::str( $facts['live_excerpt'] ?? '' ) ) {
			$payload['post_excerpt'] = $proposal->excerpt;
		}

		$payload['meta']             = $proposal->fields;
		$payload['base_id']          = $summary['base_id'];
		$payload['source_hash']      = $summary['source_hash'];
		$payload['fields_hash']      = $summary['fields_hash'];
		$payload['base_fields_hash'] = $summary['base_fields_hash'];
		$payload['parts']            = $parts;

		$live = $facts['live_content'] ?? null;

		if ( is_string( $live ) && self::accounted( $all, $payload, $proposal->fields, $live ) ) {
			$payload['parts_complete'] = true;
		}

		$payload['block_map'] = $proposal->block_map;

		$change = [
			'area'         => self::area( Scalar::str( $facts['post_status'] ?? '' ) ),
			'object_type'  => 'post',
			'object_id'    => Scalar::int( $facts['translation_id'] ?? 0 ),
			'source_id'    => Scalar::int( $facts['source_id'] ?? 0 ),
			'language_id'  => Scalar::int( $facts['language_id'] ?? 0 ),
			'origin'       => self::origin( $counts ),
			'trigger_name' => Scalar::str( $facts['trigger'] ?? 'resync' ),
			'job_id'       => Scalar::str( $facts['job_id'] ?? '' ),
			'user_id'      => Scalar::int( $facts['user_id'] ?? 0 ),
			'actor'        => Scalar::str( $facts['actor'] ?? Policy::ACTOR_USER ),
			'summary'      => $summary,
			'base_hash'    => Scalar::str( $facts['live_hash'] ?? '' ),
		];

		return [
			'change'  => $change,
			'payload' => $payload,
			'summary' => $summary,
		];
	}

	/**
	 * Whether the parts account for everything the payload adds to the live translation (see the class docblock).
	 *
	 * @param array<int, array<string, string>> $all     The uncut parts.
	 * @phpstan-param list<Part> $all
	 * @param array<string, mixed>              $payload The payload so far.
	 * @param array<string, mixed>              $fields  The fields the proposal writes.
	 * @param string                            $live    The live translation's content.
	 * @return bool
	 */
	private static function accounted( array $all, array $payload, array $fields, string $live ): bool {
		$added = self::added_lines( Scalar::str( $payload['post_content'] ?? '' ), $live );

		if ( [] === $all ) {
			return [] === $added && ! array_key_exists( 'post_title', $payload ) && ! array_key_exists( 'post_excerpt', $payload ) && [] === $fields;
		}

		$structure = [
			'prefixes'   => self::structural_prefixes(),
			'attributes' => self::structural_attribute_values(),
		];
		$roles     = [];
		$known     = [];

		foreach ( $all as $part ) {
			$roles[ $part['role'] ] = true;
			$known                 += self::tokens( $part['proposed'], $structure );
			$whole                  = self::token( $part['proposed'] );

			if ( '' !== $whole ) {
				$known[ $whole ] = true;
			}
		}

		$written = [];

		foreach ( [
			'post_title'   => Keys::ROLE_TITLE,
			'post_excerpt' => Keys::ROLE_EXCERPT,
		] as $field => $role ) {
			if ( array_key_exists( $field, $payload ) ) {
				$written[] = [ $role, Scalar::str( $payload[ $field ] ) ];
			}
		}

		foreach ( $fields as $key => $value ) {
			$written[] = [ Keys::role_meta( (string) $key ), is_string( $value ) ? $value : '' ];
		}

		foreach ( $written as [ $role, $text ] ) {
			if ( ! isset( $roles[ $role ] ) || ! self::covered( $text, $known, $structure ) ) {
				return false;
			}
		}

		if ( [] === $added ) {
			return true;
		}

		$known += self::tokens( $live, $structure );

		foreach ( $added as $line ) {
			if ( ! self::covered( $line, $known, $structure ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The non-blank lines of the proposed content the live one lacks, counted with repeats (a line diff's adds).
	 *
	 * @param string $proposed Proposed content.
	 * @param string $live     Live content.
	 * @return list<string>
	 */
	private static function added_lines( string $proposed, string $live ): array {
		$lines = static fn ( string $text ): array => array_values(
			array_filter(
				explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $text ) ),
				static fn ( string $line ): bool => '' !== trim( $line )
			)
		);
		$have  = array_count_values( $lines( $live ) );
		$out   = [];

		foreach ( $lines( $proposed ) as $line ) {
			if ( ( $have[ $line ] ?? 0 ) < 1 ) {
				$out[] = $line;
				continue;
			}

			--$have[ $line ];
		}

		return $out;
	}

	/**
	 * Whether every token of a text is a known one.
	 *
	 * @param string               $text      Text.
	 * @param array<string, true>  $known     Known tokens.
	 * @param array<string, mixed> $structure What the filters add to core's structure.
	 * @phpstan-param Structure $structure
	 * @return bool
	 */
	private static function covered( string $text, array $known, array $structure ): bool {
		foreach ( self::tokens( $text, $structure ) as $token => $yes ) {
			if ( ! isset( $known[ $token ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The text tokens of a text, line by line (runs between tags, attribute values, block-comment string values,
	 * other comments), entities decoded and trimmed, less core's structure. Full rules: docs-ai (ChangeBuilder).
	 *
	 * @param string               $text      Text.
	 * @param array<string, mixed> $structure What the filters add to core's structure.
	 * @phpstan-param Structure $structure
	 * @return array<string, true>
	 */
	private static function tokens( string $text, array $structure ): array {
		$out = [];

		foreach ( explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $text ) ) as $line ) {
			if ( 0 === preg_match_all( '/<!--(.*?)(?:-->|$)|<(\/?[A-Za-z][^>]*)(?:>|$)|([^<]+)|</s', $line, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
				continue;
			}

			foreach ( $matches as $m ) {
				$values = [];

				if ( isset( $m[3] ) ) {
					$values[] = self::token( $m[3] );
				} elseif ( isset( $m[2] ) ) {
					if ( preg_match_all( '/([^\s"\'=<>\/]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/', $m[2], $attrs, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
						foreach ( $attrs as $a ) {
							$value = self::token( $a[2] ?? $a[3] ?? $a[4] ?? '' );

							if ( 1 === preg_match( self::NUMBER, $value ) ) {
								continue;
							}

							$values[] = 'class' === strtolower( (string) $a[1] ) ? self::user_classes( $value, $structure['prefixes'] ) : $value;
						}
					}
				} elseif ( isset( $m[1] ) ) {
					$values = self::comment_values( $m[1], $structure );
				}

				foreach ( $values as $token ) {
					if ( '' !== $token ) {
						$out[ $token ] = true;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * The text tokens of a comment: a block delimiter's attribute strings less structure, a More tag's text,
	 * nothing for page markers, else the whole comment.
	 *
	 * @param string               $comment   The text between `<!--` and `-->`.
	 * @param array<string, mixed> $structure What the filters add to core's structure.
	 * @phpstan-param Structure $structure
	 * @return list<string>
	 */
	private static function comment_values( string $comment, array $structure ): array {
		if ( in_array( $comment, self::MARKERS, true ) ) {
			return [];
		}

		if ( 1 === preg_match( self::MORE_TEXT, $comment, $more ) ) {
			return [ self::token( $more[1] ) ];
		}

		if ( 1 !== preg_match( '/^\s*\/?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*(\{.*\})?\s*\/?\s*$/s', $comment, $m ) ) {
			return [ self::token( $comment ) ];
		}

		// A delimiter without attributes holds no text.
		if ( ! isset( $m[2] ) ) {
			return [];
		}

		$attrs = json_decode( $m[2], true );

		if ( ! is_array( $attrs ) || ! self::keys_unique( $m[2] ) ) {
			return [ self::token( $m[2] ) ];
		}

		$out   = [];
		$block = str_contains( $m[1], '/' ) ? $m[1] : 'core/' . $m[1];
		$added = $structure['attributes'][ $block ] ?? [];

		foreach ( $attrs as $name => $value ) {
			if ( 'layout' === $name && is_array( $value ) ) {
				$attrs[ $name ] = self::layout_rest( $value );
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$token = self::token( $value );

			if ( 'className' === $name ) {
				$out[] = self::user_classes( $token, $structure['prefixes'] );
				unset( $attrs[ $name ] );
			} elseif ( ( isset( self::STRUCTURAL_ATTRIBUTES[ $name ] ) && 1 === preg_match( self::STRUCTURAL_ATTRIBUTES[ $name ], $token ) ) || isset( $added[ $name ][ $token ] ) ) {
				unset( $attrs[ $name ] );
			}
		}

		array_walk_recursive(
			$attrs,
			static function ( $value ) use ( &$out ): void {
				if ( is_string( $value ) ) {
					$out[] = self::token( $value );
				}
			}
		);

		return $out;
	}

	/**
	 * A `layout` attribute less its LAYOUT_KEYWORDS keys holding a keyword; the rest is text.
	 *
	 * @param array<mixed> $layout The decoded `layout` value.
	 * @return array<mixed>
	 */
	private static function layout_rest( array $layout ): array {
		foreach ( self::LAYOUT_KEYWORDS as $key => $keywords ) {
			if ( isset( $layout[ $key ] ) && is_string( $layout[ $key ] ) && in_array( self::token( $layout[ $key ] ), $keywords, true ) ) {
				unset( $layout[ $key ] );
			}
		}

		return $layout;
	}

	/**
	 * Whether no object in a block comment's attributes JSON repeats a key. A repeated key keeps only its last
	 * value when decoded while the line keeps both, so such JSON must stay one token of text.
	 *
	 * @param string $json Attributes JSON.
	 * @return bool
	 */
	private static function keys_unique( string $json ): bool {
		$objects = json_decode( $json );

		if ( ! is_object( $objects ) || false === preg_match_all( self::JSON_STRING, $json, $strings, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
			return false;
		}

		$raw = 0;

		foreach ( $strings as $string ) {
			if ( isset( $string[1] ) ) {
				++$raw;
			}
		}

		return self::key_count( $objects ) === $raw;
	}

	/**
	 * The number of keys of the objects in a decoded JSON value.
	 *
	 * @param mixed $value Decoded JSON (objects as stdClass).
	 * @return int
	 */
	private static function key_count( mixed $value ): int {
		$count = 0;

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
			$count = count( $value );
		}

		if ( ! is_array( $value ) ) {
			return 0;
		}

		foreach ( $value as $item ) {
			$count += self::key_count( $item );
		}

		return $count;
	}

	/**
	 * A decoded class list without its structural classes, joined by a space.
	 *
	 * @param string   $classes  Class list, entities decoded.
	 * @param string[] $prefixes Class prefixes that are structure.
	 * @phpstan-param list<string> $prefixes
	 * @return string
	 */
	private static function user_classes( string $classes, array $prefixes ): string {
		$rest = [];

		$names = preg_split( '/[ \t\n\f\r]+/', $classes, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( false === $names ? [ $classes ] : $names as $name ) {
			if ( ! self::structural_class( $name, $prefixes ) ) {
				$rest[] = $name;
			}
		}

		return implode( ' ', $rest );
	}

	/**
	 * Whether a class is structure: a structural prefix or whole class, and an ASCII class token (so a non-ASCII
	 * space cannot join words into one class).
	 *
	 * @param string   $name     One class.
	 * @param string[] $prefixes Class prefixes that are structure.
	 * @phpstan-param list<string> $prefixes
	 * @return bool
	 */
	private static function structural_class( string $name, array $prefixes ): bool {
		if ( 1 !== preg_match( self::CLASS_TOKEN, $name ) ) {
			return false;
		}

		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return 1 === preg_match( self::STRUCTURAL_CLASS, $name );
	}

	/**
	 * The class prefixes that are structure: core's and the filter's.
	 *
	 * @return list<string>
	 */
	private static function structural_prefixes(): array {
		/**
		 * Filters the class prefixes Content ReSync treats as block structure, not text, besides core's own.
		 * A prefix also hides a user class that starts with it; entries need 3+ class-token characters.
		 *
		 * @hook  perflocale/resync/structural_class_prefixes
		 * @since 1.0.8
		 *
		 * @param string[] $prefixes Class prefixes to add. Default empty.
		 */
		/**
		 * A filter may return anything; only valid prefixes are taken.
		 *
		 * @var mixed $given
		 */
		$given = apply_filters( 'perflocale/resync/structural_class_prefixes', [] );
		$out   = self::STRUCTURAL_PREFIXES;

		if ( ! is_array( $given ) ) {
			return $out;
		}

		foreach ( $given as $prefix ) {
			if ( is_string( $prefix ) && 1 === preg_match( self::PREFIX_PATTERN, $prefix ) && ! in_array( $prefix, $out, true ) ) {
				$out[] = $prefix;
			}
		}

		return $out;
	}

	/**
	 * The block attribute values the filter adds to core's structure, per block and top-level attribute.
	 *
	 * @return array<string, array<string, array<string, true>>>
	 */
	private static function structural_attribute_values(): array {
		/**
		 * Filters the top-level block attribute values Content ReSync treats as structure, not text, besides core's.
		 * Values are exact keywords of 1-32 class-token characters; `className` entries have no effect.
		 *
		 * @hook  perflocale/resync/structural_attributes
		 * @since 1.0.8
		 *
		 * @param array<string, array<string, string[]>> $attributes Block name => attribute => allowed values. Default empty.
		 */
		/**
		 * A filter may return anything; only valid entries are taken.
		 *
		 * @var mixed $given
		 */
		$given = apply_filters( 'perflocale/resync/structural_attributes', [] );
		$out   = [];

		if ( ! is_array( $given ) ) {
			return $out;
		}

		foreach ( $given as $block => $attributes ) {
			if ( ! is_string( $block ) || 1 !== preg_match( self::BLOCK_NAME, $block ) || ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( $attributes as $name => $values ) {
				if ( ! is_string( $name ) || 1 !== preg_match( self::ATTRIBUTE_NAME, $name ) || ! is_array( $values ) ) {
					continue;
				}

				foreach ( $values as $value ) {
					if ( is_string( $value ) && 1 === preg_match( self::KEYWORD, $value ) ) {
						$out[ $block ][ $name ][ $value ] = true;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * One token: entities decoded, trimmed.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function token( string $value ): string {
		return trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Parts as the typed list; `person` only when true (the part replaces a person's text).
	 *
	 * @param array<mixed> $parts Parts.
	 * @return list<Part>
	 */
	private static function part_list( array $parts ): array {
		$out = [];

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$clean = [
				'role'     => Scalar::str( $part['role'] ?? '' ),
				'block'    => Scalar::str( $part['block'] ?? '' ),
				'status'   => Scalar::str( $part['status'] ?? '' ),
				'source'   => Scalar::str( $part['source'] ?? '' ),
				'current'  => Scalar::str( $part['current'] ?? '' ),
				'proposed' => Scalar::str( $part['proposed'] ?? '' ),
			];

			if ( true === ( $part['person'] ?? null ) ) {
				$clean['person'] = true;
			}

			$out[] = $clean;
		}

		return $out;
	}
}
