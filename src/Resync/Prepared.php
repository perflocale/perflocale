<?php
/**
 * A pair parsed, segmented, aligned and matched, before composition.
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
 * What {@see Engine::prepare()} computed for a pair: segmentations, alignment, matching, field units and the field
 * decisions without machine texts. The runner sends exactly the units and fields needing text, then hands the
 * outputs to {@see Engine::complete()}.
 *
 * @phpstan-import-type Document from Engine
 * @phpstan-import-type Options from Engine
 * @phpstan-import-type Result from FieldMerge
 * @phpstan-type Request array{index: int, text: string, format: string, role: string, kind: string, block: string, status: string, previous: string|null}
 * @phpstan-type FieldRequest array{key: string, text: string, status: string, previous: string|null}
 * @phpstan-type Part array{role: string, block: string, status: string, source: string, current: string, proposed: string, person?: true}
 */
final class Prepared {

	/**
	 * {@see self::protected_elsewhere()} per source block, by object id.
	 *
	 * @var array<int, bool>
	 */
	private array $protected_memo = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $base        The base document.
	 * @param array<string, mixed> $translation The translation document.
	 * @param array<string, mixed> $source      The new source document.
	 * @param array<string, mixed> $options     The engine options.
	 * @param Segmentation         $seg_b       The base, segmented.
	 * @param Segmentation         $seg_t       The translation, segmented.
	 * @param Segmentation         $seg_n       The new source, segmented.
	 * @param array<int, Unit>     $fields_b    The base's title and excerpt units.
	 * @param array<int, Unit>     $fields_t    The translation's.
	 * @param array<int, Unit>     $fields_n    The new source's.
	 * @param Alignment            $alignment   Base with translation.
	 * @param Matching             $matching    New source with base.
	 * @param array<string, mixed> $field_plan  {@see FieldMerge::merge()} without machine texts.
	 * @phpstan-param Document $base
	 * @phpstan-param Document $translation
	 * @phpstan-param Document $source
	 * @phpstan-param Options $options
	 * @phpstan-param list<Unit> $fields_b
	 * @phpstan-param list<Unit> $fields_t
	 * @phpstan-param list<Unit> $fields_n
	 * @phpstan-param Result $field_plan
	 */
	public function __construct(
		public readonly array $base,
		public readonly array $translation,
		public readonly array $source,
		public readonly array $options,
		public readonly Segmentation $seg_b,
		public readonly Segmentation $seg_t,
		public readonly Segmentation $seg_n,
		public readonly array $fields_b,
		public readonly array $fields_t,
		public readonly array $fields_n,
		public readonly Alignment $alignment,
		public readonly Matching $matching,
		public readonly array $field_plan
	) {}

	/**
	 * The units that need a new text: every `changed` or `new` unit with its {@see Matching::$units} index (the `texts`
	 * key), its data and, when changed, the aligned translation as previous. Units of a block protected in the base or
	 * translation are left out: the composition keeps its bytes, so its text is never sent.
	 *
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<Request>
	 */
	public function units_needing_text(): array {
		$out = [];

		foreach ( $this->matching->units as $index => $match ) {
			if ( $match->status !== Matcher::STATUS_CHANGED && $match->status !== Matcher::STATUS_NEW ) {
				continue;
			}

			if ( $this->protected_elsewhere( $match->unit ) ) {
				continue;
			}

			$unit  = $match->unit;
			$out[] = [
				'index'    => $index,
				'text'     => $unit->text,
				'format'   => $unit->format === BlockAttributeSource::FORMAT_TEXT ? BlockAttributeSource::FORMAT_TEXT : BlockAttributeSource::FORMAT_HTML,
				'role'     => $unit->role,
				'kind'     => $unit->kind,
				'block'    => self::block_name( $unit ),
				'status'   => $match->status,
				'previous' => null === $match->translation ? null : $match->translation->text,
			];
		}

		return $out;
	}

	/**
	 * Whether a source unit's block, or an ancestor in the new source, is protected through the base or the translation;
	 * {@see Composer::block()} keeps such a block's bytes, so its units are neither sent nor used as context. Memoised.
	 *
	 * @param Unit $unit A unit of the new source.
	 * @return bool
	 */
	public function protected_elsewhere( Unit $unit ): bool {
		if ( ! $unit->node instanceof BlockNode ) {
			return false;
		}

		$id = spl_object_id( $unit->node );

		if ( ! isset( $this->protected_memo[ $id ] ) ) {
			$this->protected_memo[ $id ] = $this->chain_protected( $unit->node );
		}

		return $this->protected_memo[ $id ];
	}

	/**
	 * Whether a source block or an ancestor is protected through the base or the translation.
	 *
	 * @param BlockNode $node A block of the new source.
	 * @return bool
	 */
	private function chain_protected( BlockNode $node ): bool {
		$level = null;

		foreach ( array_slice( $node->path->indexes(), 0, -1 ) as $index ) {
			$level = null === $level ? ( $this->seg_n->tree->nodes[ $index ] ?? null ) : ( $level->children[ $index ] ?? null );

			if ( null === $level ) {
				break;
			}

			if ( $this->level_protected( $level ) ) {
				return true;
			}
		}

		return $this->level_protected( $node );
	}

	/**
	 * Whether one source block is protected through the base (its partner) or the translation (the partner's translation).
	 *
	 * @param BlockNode $node A block of the new source.
	 * @return bool
	 */
	private function level_protected( BlockNode $node ): bool {
		$base = $this->matching->base_of( $node );

		if ( null === $base ) {
			return false;
		}

		if ( $this->seg_b->is_protected( $base ) ) {
			return true;
		}

		$translation = $this->alignment->translation_of( $base );

		return null !== $translation && $this->seg_t->is_protected( $translation );
	}

	/**
	 * The custom fields that need a new text: every `changed` or `new` key, with the source's value and, for a changed key
	 * whose value automation wrote, that value as previous. A new key has none: a seeded one holds the source's text.
	 *
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<FieldRequest>
	 */
	public function fields_needing_text(): array {
		$out   = [];
		$owned = $this->options['fields_owned'] ?? [];

		foreach ( $this->field_plan['status'] as $key => $decision ) {
			if ( $decision !== FieldMerge::STATUS_CHANGED && $decision !== FieldMerge::STATUS_NEW ) {
				continue;
			}

			$key      = (string) $key;
			$source   = $this->source['fields'][ $key ] ?? null;
			$current  = $this->translation['fields'][ $key ] ?? null;
			$previous = null;

			if ( $decision === FieldMerge::STATUS_CHANGED && is_string( $current ) && '' !== $current && isset( $owned[ $key ] ) && $owned[ $key ] === hash( 'sha256', $current ) ) {
				$previous = $current;
			}

			$out[] = [
				'key'      => $key,
				'text'     => is_scalar( $source ) ? (string) $source : '',
				'status'   => $decision,
				'previous' => $previous,
			];
		}

		return $out;
	}

	/**
	 * The parts of a change: every changed, new and near-matched unit and field, with source, current and proposed text
	 * (the machine text, else the translation's for a near match, else the source's). Protected-block units are no parts;
	 * a part replacing a person's text carries `person` = true.
	 *
	 * @param array<int, string>    $texts        Machine outputs per unit index.
	 * @param array<string, string> $field_texts  Machine outputs per key.
	 * @param array<int, int>       $person_units Unit indexes whose part replaces a person's text.
	 * @return array<int, array<string, string|true>>
	 * @phpstan-return list<Part>
	 */
	public function parts( array $texts, array $field_texts, array $person_units = [] ): array {
		$out    = [];
		$person = array_fill_keys( $person_units, true );

		foreach ( $this->matching->units as $index => $match ) {
			if ( $match->status !== Matcher::STATUS_CHANGED && $match->status !== Matcher::STATUS_NEW && $match->status !== Matcher::STATUS_FUZZY ) {
				continue;
			}

			// The composition keeps such a block's bytes: no part changes there.
			if ( $this->protected_elsewhere( $match->unit ) ) {
				continue;
			}

			$current  = null === $match->translation ? '' : $match->translation->text;
			$proposed = $match->unit->text;

			if ( $match->status === Matcher::STATUS_FUZZY ) {
				$proposed = $current;
			} elseif ( isset( $texts[ $index ] ) && Verifier::accepts_for( $match->unit, $texts[ $index ] ) ) {
				$proposed = $texts[ $index ];
			}

			$part = [
				'role'     => $match->unit->role,
				'block'    => self::block_name( $match->unit ),
				'status'   => $match->status,
				'source'   => $match->unit->text,
				'current'  => $current,
				'proposed' => $proposed,
			];

			if ( isset( $person[ $index ] ) ) {
				$part['person'] = true;
			}

			$out[] = $part;
		}

		foreach ( $this->field_plan['status'] as $key => $decision ) {
			if ( $decision !== FieldMerge::STATUS_CHANGED && $decision !== FieldMerge::STATUS_NEW && $decision !== FieldMerge::STATUS_FUZZY ) {
				continue;
			}

			$key     = (string) $key;
			$source  = $this->source['fields'][ $key ] ?? null;
			$current = $this->translation['fields'][ $key ] ?? null;
			$source  = is_scalar( $source ) ? (string) $source : '';
			$current = is_scalar( $current ) ? (string) $current : '';

			$proposed = $source;

			if ( $decision === FieldMerge::STATUS_FUZZY ) {
				$proposed = $current;
			} elseif ( isset( $field_texts[ $key ] ) && Verifier::accepts_text( $field_texts[ $key ] ) ) {
				$proposed = $field_texts[ $key ];
			}

			$out[] = [
				'role'     => Keys::role_meta( $key ),
				'block'    => '',
				'status'   => $decision,
				'source'   => $source,
				'current'  => $current,
				'proposed' => $proposed,
			];
		}

		return $out;
	}

	/**
	 * The block name of a unit's block, '' for a field or a freeform unit.
	 *
	 * @param Unit $unit Unit.
	 * @return string
	 */
	private static function block_name( Unit $unit ): string {
		return $unit->node instanceof BlockNode ? (string) $unit->node->name : '';
	}
}
