<?php
/**
 * The context sent with each unit a resync machine translates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Translation\PlaceholderMasker;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the machine-translation context of each unit a pair sends: neighbours, section, or previous translation.
 * Protected blocks are never context, and every piece is masked as its request is, so context leaks nothing the
 * request itself would not send. Rendered as plain text cut to `max_chars`, highest priority first.
 *
 * @phpstan-type Request array{index: int|string, text: string, format: string, context?: string}
 * @phpstan-type Neighbour array{source: string, translation: string|null}
 * @phpstan-type Context array{before: list<Neighbour>, after: list<Neighbour>, section: string|null, previous_translation: string|null, instructions: string, max_chars: int}
 * @phpstan-type Config array{mode: string, neighbours: int, previous: bool, max_chars: int}
 */
final class ContextBuilder {

	/** Context modes (the `mt_context_mode` setting). */
	public const MODE_OFF        = 'off';
	public const MODE_NEIGHBOURS = 'neighbours';
	public const MODE_SECTION    = 'section';

	/** The longest context a filter may ask for, in characters. */
	public const MAX_CHARS_CEILING = 5000;

	/** What a mask token becomes in context. */
	private const MASK_SHOWN = '[...]';

	/** The fewest characters worth keeping of a piece cut to fit. */
	private const MIN_CUT = 20;

	/**
	 * The rendered context of each request, parallel to `$requests`; '' for none.
	 *
	 * @param Prepared                         $prepared The prepared pair.
	 * @param array<int, array<string, mixed>> $requests The pair's requests: `index` (unit index or `field:<key>`), `text`, `format`.
	 * @param array<string, mixed>             $config   `mode`, `neighbours`, `previous`, `max_chars`.
	 * @param array<string, mixed>|null        $args     The filter's third argument; null runs no filter (for request counting).
	 * @phpstan-param list<Request> $requests
	 * @phpstan-param Config $config
	 * @return list<string>
	 */
	public static function build( Prepared $prepared, array $requests, array $config, ?array $args ): array {
		$mode = $config['mode'];
		$out  = array_fill( 0, count( $requests ), '' );

		if ( self::MODE_NEIGHBOURS !== $mode && self::MODE_SECTION !== $mode ) {
			return $out;
		}

		$units    = $prepared->matching->units;
		$content  = [];
		$position = [];

		foreach ( $units as $index => $match ) {
			if ( Unit::KIND_FIELD !== $match->unit->kind && ! $prepared->protected_elsewhere( $match->unit ) ) {
				$position[ $index ] = count( $content );
				$content[]          = $index;
			}
		}

		$members = self::MODE_SECTION === $mode ? self::section_members( $units, $content ) : [];
		$fields  = [];

		foreach ( $prepared->fields_needing_text() as $field ) {
			$fields[ $field['key'] ] = $field;
		}

		$plain = [];

		foreach ( $requests as $r => $request ) {
			$index   = $request['index'];
			$context = self::empty_context( $config['max_chars'] );
			$segment = null;

			if ( is_string( $index ) && str_starts_with( $index, 'field:' ) ) {
				$key   = substr( $index, 6 );
				$field = $fields[ $key ] ?? null;

				if ( $config['previous'] && null !== $field && null !== $field['previous'] ) {
					$context['previous_translation'] = self::plain( $field['previous'], $request['format'], $plain, true );
				}

				$segment = [
					'text'       => $request['text'],
					'kind'       => 'meta',
					'block_name' => '',
					'block_path' => '',
					'attribute'  => '',
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A key of the filter's segment, not a query.
					'status'     => null === $field ? Matcher::STATUS_CHANGED : $field['status'],
				];
			} else {
				$match = $units[ (int) $index ] ?? null;

				if ( null === $match ) {
					continue;
				}

				$unit = $match->unit;

				if ( $config['previous'] && Matcher::STATUS_CHANGED === $match->status && null !== $match->translation ) {
					$context['previous_translation'] = self::plain( $match->translation->text, $match->translation->format, $plain );
				}

				if ( isset( $position[ (int) $index ] ) ) {
					$p = $position[ (int) $index ];

					if ( self::MODE_NEIGHBOURS === $mode ) {
						$n = max( 1, $config['neighbours'] );

						for ( $k = max( 0, $p - $n ); $k < $p; $k++ ) {
							$context['before'][] = self::neighbour( $units[ $content[ $k ] ], $plain );
						}

						for ( $k = $p + 1, $last = min( count( $content ) - 1, $p + $n ); $k <= $last; $k++ ) {
							$context['after'][] = self::neighbour( $units[ $content[ $k ] ], $plain );
						}
					} else {
						$context['section'] = self::section( $unit, $units, $members, $plain );
					}
				}

				$segment = [
					'text'       => $request['text'],
					'kind'       => self::kind_of( $unit ),
					'block_name' => $unit->node instanceof BlockNode ? (string) $unit->node->name : '',
					'block_path' => $unit->node instanceof BlockNode ? Segmentation::path_key( $unit->node ) : '',
					'attribute'  => str_starts_with( $unit->role, 'text:' ) ? substr( $unit->role, 5 ) : '',
					'meta_key'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A key of the filter's segment, not a query.
					'status'     => $match->status,
				];
			}

			if ( null !== $args ) {
				$context = self::filtered( $context, $segment, $args );
			}

			$out[ $r ] = null === $context ? '' : self::render( $context );
		}

		return array_values( $out );
	}

	/**
	 * The context of one outdated string: its previous translation, plain and masked; '' without one.
	 *
	 * @param string                    $text      The string's source text, as sent.
	 * @param string                    $previous  Its live translation.
	 * @param string                    $format    `html` or `text` (the string's format; the context is plain text either way).
	 * @param int                       $max_chars The character limit.
	 * @param array<string, mixed>|null $args      The filter's third argument; null runs no filter.
	 * @return string
	 */
	public static function for_string( string $text, string $previous, string $format, int $max_chars, ?array $args ): string {
		$previous = self::string_plain( $previous );

		if ( '' === $previous ) {
			return '';
		}

		$context                         = self::empty_context( $max_chars );
		$context['previous_translation'] = $previous;

		if ( null !== $args ) {
			$context = self::filtered(
				$context,
				[
					'text'       => $text,
					'kind'       => 'string',
					'block_name' => '',
					'block_path' => '',
					'attribute'  => '',
					'meta_key'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A key of the filter's segment, not a query.
					'status'     => Matcher::STATUS_CHANGED,
				],
				$args
			);
		}

		return null === $context ? '' : self::render( $context );
	}

	/**
	 * A string's translation as plain context: markup removed, masked as the job masks strings, space collapsed.
	 *
	 * @param string $text The translation.
	 * @return string
	 */
	private static function string_plain( string $text ): string {
		$masked = PlaceholderMasker::mask( wp_strip_all_tags( $text ) )[0];
		$masked = (string) preg_replace( '/\[\[PFL_PH_\d+\]\]/', self::MASK_SHOWN, $masked );
		$masked = html_entity_decode( $masked, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $masked ) );
	}

	/**
	 * A context through `perflocale/mt/context`, normalised.
	 *
	 * @param array<string, mixed> $context The built context.
	 * @param array<string, mixed> $segment The piece.
	 * @param array<string, mixed> $args    The run's facts.
	 * @phpstan-param Context $context
	 * @return array<string, mixed>|null Null to send none.
	 * @phpstan-return Context|null
	 */
	private static function filtered( array $context, array $segment, array $args ): ?array {
		/**
		 * Filters the context sent with one piece Content ReSync machine translates, or one outdated string.
		 * Runs only while the context setting is not Off and the provider accepts context; entries are plain, masked text.
		 *
		 * @hook perflocale/mt/context
		 * @since 1.0.8
		 *
		 * @param array<string, mixed> $context before[] and after[] (each {source, translation|null}), section (string|null), previous_translation (string|null), instructions (string; an "Instructions:" line of the context that every provider receiving context gets, DeepL included), max_chars (int; the setting; a missing or non-numeric value keeps it, 0 sends none). Return [] to send none.
		 * @param array<string, mixed> $segment text, kind (block_text|block_attribute|title|excerpt|meta|string), block_name, block_path, attribute, meta_key, status (new|changed).
		 * @param array<string, mixed> $args    object_type (post|template|template_part|pattern|navigation|string), object_id, source_object_id, post_type, source_language, target_language, trigger, provider, provider_supports_context, job_id, user_id.
		 */
		$filtered = apply_filters( 'perflocale/mt/context', $context, $segment, $args );

		return self::normalize( $filtered, $context['max_chars'] );
	}

	/**
	 * A unit's plain text for context: masked as its request is sent, markup removed, space collapsed; memoised.
	 *
	 * @param string                $text   The text.
	 * @param string                $format `html` or `text`.
	 * @param array<string, string> $memo   Memo, by mask, format and text.
	 * @param bool                  $field  Whether the text is a custom field's value.
	 * @return string
	 */
	private static function plain( string $text, string $format, array &$memo, bool $field = false ): string {
		$format = BlockAttributeSource::FORMAT_TEXT === $format ? BlockAttributeSource::FORMAT_TEXT : BlockAttributeSource::FORMAT_HTML;
		$key    = ( $field ? 'field' : 'unit' ) . "\0" . $format . "\0" . $text;

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$masked = UnitTranslator::held( $field ? UnitTranslator::mask_field( $text )[0] : UnitTranslator::mask( $text, $format )[0], $format );
		$masked = (string) preg_replace( '/\[\[PFL_PH_\d+\]\]|<!-- pfl-s-[0-9a-f]{12}-\d+ -->/', self::MASK_SHOWN, $masked );
		$masked = wp_strip_all_tags( $masked );

		if ( BlockAttributeSource::FORMAT_HTML === $format ) {
			$masked = html_entity_decode( $masked, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$memo[ $key ] = trim( (string) preg_replace( '/\s+/u', ' ', $masked ) );

		return $memo[ $key ];
	}

	/**
	 * A neighbour: its source text and, when reused, its translation.
	 *
	 * @param UnitMatch             $neighbour The neighbour's match.
	 * @param array<string, string> $memo      Plain-text memo.
	 * @return array{source: string, translation: string|null}
	 */
	private static function neighbour( UnitMatch $neighbour, array &$memo ): array {
		return [
			'source'      => self::plain( $neighbour->unit->text, $neighbour->unit->format, $memo ),
			'translation' => Matcher::STATUS_REUSED === $neighbour->status && null !== $neighbour->translation ? self::plain( $neighbour->translation->text, $neighbour->translation->format, $memo ) : null,
		];
	}

	/**
	 * Content units per enclosing block path key.
	 *
	 * @param array<int, UnitMatch> $units   The matching's units.
	 * @param array<int, int>       $content Unit indexes of the content units, in document order.
	 * @return array<int|string, list<int>> Unit indexes by ancestor path key.
	 */
	private static function section_members( array $units, array $content ): array {
		$members = [];

		foreach ( $content as $index ) {
			$node = $units[ $index ]->unit->node;

			if ( ! $node instanceof BlockNode ) {
				continue;
			}

			$prefix = '';

			foreach ( array_slice( $node->path->indexes(), 0, -1 ) as $i ) {
				$prefix               = '' === $prefix ? (string) $i : $prefix . '.' . $i;
				$members[ $prefix ][] = $index;
			}
		}

		return $members;
	}

	/**
	 * A unit's section: the texts of its nearest enclosing block, its own included (so they share one request).
	 *
	 * @param Unit                         $unit    The unit.
	 * @param array<int, UnitMatch>        $units   The matching's units.
	 * @param array<int|string, list<int>> $members {@see section_members()}.
	 * @param array<string, string>        $memo    Plain-text memo.
	 * @return string|null
	 */
	private static function section( Unit $unit, array $units, array $members, array &$memo ): ?string {
		if ( ! $unit->node instanceof BlockNode ) {
			return null;
		}

		$indexes = $unit->node->path->indexes();

		if ( count( $indexes ) < 2 ) {
			return null;
		}

		$texts = [];

		foreach ( $members[ implode( '.', array_slice( $indexes, 0, -1 ) ) ] ?? [] as $other ) {
			$text = self::plain( $units[ $other ]->unit->text, $units[ $other ]->unit->format, $memo );

			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return [] === $texts ? null : implode( ' / ', $texts );
	}

	/**
	 * The kind of a unit as the filter names it.
	 *
	 * @param Unit $unit The unit.
	 * @return string
	 */
	private static function kind_of( Unit $unit ): string {
		if ( Keys::ROLE_TITLE === $unit->role ) {
			return 'title';
		}

		if ( Keys::ROLE_EXCERPT === $unit->role ) {
			return 'excerpt';
		}

		if ( str_starts_with( $unit->role, 'meta:' ) ) {
			return 'meta';
		}

		return Keys::ROLE_HTML === $unit->role ? 'block_text' : 'block_attribute';
	}

	/**
	 * An empty context.
	 *
	 * @param int $max_chars The character limit.
	 * @return array<string, mixed>
	 * @phpstan-return Context
	 */
	private static function empty_context( int $max_chars ): array {
		return [
			'before'               => [],
			'after'                => [],
			'section'              => null,
			'previous_translation' => null,
			'instructions'         => '',
			'max_chars'            => $max_chars,
		];
	}

	/**
	 * A filter's answer as a context: [] or non-array is none; `max_chars` defaults to the setting, clamped.
	 *
	 * @param mixed $value     The filter's answer.
	 * @param int   $max_chars The limit the filter was given.
	 * @return array<string, mixed>|null
	 * @phpstan-return Context|null
	 */
	public static function normalize( mixed $value, int $max_chars = 0 ): ?array {
		if ( ! is_array( $value ) || [] === $value ) {
			return null;
		}

		$limit = is_numeric( $value['max_chars'] ?? null ) ? Scalar::int( $value['max_chars'] ) : $max_chars;
		$out   = self::empty_context( max( 0, min( self::MAX_CHARS_CEILING, $limit ) ) );

		foreach ( [ 'before', 'after' ] as $side ) {
			foreach ( is_array( $value[ $side ] ?? null ) ? $value[ $side ] : [] as $entry ) {
				if ( is_array( $entry ) && is_string( $entry['source'] ?? null ) ) {
					$out[ $side ][] = [
						'source'      => $entry['source'],
						'translation' => is_string( $entry['translation'] ?? null ) ? $entry['translation'] : null,
					];
				}
			}
		}

		$out['section']              = is_string( $value['section'] ?? null ) ? $value['section'] : null;
		$out['previous_translation'] = is_string( $value['previous_translation'] ?? null ) ? $value['previous_translation'] : null;
		$out['instructions']         = is_string( $value['instructions'] ?? null ) ? $value['instructions'] : '';

		return $out;
	}

	/**
	 * The context as plain text within `max_chars`: lines chosen by priority, the last one cut, then reading order.
	 *
	 * @param array<string, mixed> $context A normalised context.
	 * @phpstan-param Context $context
	 * @return string
	 */
	public static function render( array $context ): string {
		$max = $context['max_chars'];

		if ( $max <= 0 ) {
			return '';
		}

		// Priority order, each with its reading position.
		$lines = [];

		if ( '' !== trim( $context['instructions'] ) ) {
			$lines[] = [ 0, 'Instructions: ' . $context['instructions'] ];
		}

		$before = array_reverse( $context['before'] );
		$after  = $context['after'];
		$count  = max( count( $before ), count( $after ) );

		if ( null !== $context['previous_translation'] && '' !== trim( $context['previous_translation'] ) ) {
			$lines[] = [ 5, 'Previous translation of this text: ' . $context['previous_translation'] ];
		}

		for ( $k = 0; $k < $count; $k++ ) {
			if ( isset( $before[ $k ] ) ) {
				$lines[] = [ 2000 - $k, 'Before: ' . self::neighbour_line( $before[ $k ] ) ];
			}

			if ( isset( $after[ $k ] ) ) {
				$lines[] = [ 3000 + $k, 'After: ' . self::neighbour_line( $after[ $k ] ) ];
			}
		}

		if ( null !== $context['section'] && '' !== trim( $context['section'] ) ) {
			$lines[] = [ 4000, 'Section: ' . $context['section'] ];
		}

		$chosen = [];
		$left   = $max;

		foreach ( $lines as [ $order, $line ] ) {
			$need = mb_strlen( $line ) + ( [] === $chosen ? 0 : 1 );

			if ( $need <= $left ) {
				$chosen[ $order ] = $line;
				$left            -= $need;
				continue;
			}

			$room = $left - ( [] === $chosen ? 0 : 1 );

			if ( $room >= self::MIN_CUT ) {
				$chosen[ $order ] = mb_substr( $line, 0, $room );
			}

			break;
		}

		ksort( $chosen );

		return implode( "\n", $chosen );
	}

	/**
	 * A neighbour as one line.
	 *
	 * @param array{source: string, translation: string|null} $neighbour The neighbour.
	 * @return string
	 */
	private static function neighbour_line( array $neighbour ): string {
		return null === $neighbour['translation'] || '' === $neighbour['translation']
			? $neighbour['source']
			: $neighbour['source'] . ' (translated: ' . $neighbour['translation'] . ')';
	}
}
