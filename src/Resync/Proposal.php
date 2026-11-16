<?php
/**
 * The engine's outcome for one pair.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What {@see Engine::run()} proposes for a (source, language) pair: composed content, fields and their decisions,
 * counts, flags, completeness, block map, alignment facts and person-changed blocks. A status other than `proposal`
 * carries its reason and proposes nothing.
 */
final class Proposal {

	/** A proposal was composed and verified. */
	public const STATUS_PROPOSAL = 'proposal';
	/** A document the scanner does not support; nothing proposed. */
	public const STATUS_UNSUPPORTED = 'unsupported';
	/** The composition failed verification; nothing proposed. */
	public const STATUS_COMPOSE_ERROR = 'compose_error';

	/**
	 * Constructor.
	 *
	 * @param string                         $status        {@see self::STATUS_PROPOSAL}, {@see self::STATUS_UNSUPPORTED} or {@see self::STATUS_COMPOSE_ERROR}.
	 * @param string                         $reason        Why the status is not `proposal`; '' otherwise.
	 * @param string                         $content       The composed content.
	 * @param string                         $title         The proposed title.
	 * @param string                         $excerpt       The proposed excerpt.
	 * @param array<string, string>          $fields        Custom field values to write, per key; keys kept or left alone are absent.
	 * @param array<string, string>          $field_status  The decision per key ({@see FieldMerge} statuses).
	 * @param array<int, string>             $edited_fields Keys whose translation value was changed outside ReSync (rule 1).
	 * @param array<string, int>             $counts        reused, same_as_source, fuzzy, changed, new, removed, removed_in_translation, left_source, machine_translated, unknown_base.
	 * @param array<int, string>             $flags         Every flag, sorted, each once.
	 * @param array<string, list<string>>    $block_flags   Flags per source block path key; '' is the document.
	 * @param bool                           $complete      No part left in the source language, no near match, no unknown field base, no flag.
	 * @param array<int, array<int, string>> $block_map Translation block path, canon hash prefix, source block path, in document order; then the person mark and the own part's hash where {@see PersonBlocks::entry()} adds them.
	 * @param bool                           $one_to_one    Whether the base aligned with the translation one to one.
	 * @param bool                           $reordered     Whether the translation's blocks stand in another order than their base blocks.
	 * @param int                            $person_changed How many blocks a person wrote or changed in the translation the proposal changes or removes.
	 * @param array<int, int>                $person_units  The changed units whose part replaces a person's text, by index of the matching.
	 * @phpstan-param list<string> $edited_fields
	 * @phpstan-param list<string> $flags
	 * @phpstan-param list<list<string>> $block_map
	 * @phpstan-param list<int> $person_units
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $reason,
		public readonly string $content = '',
		public readonly string $title = '',
		public readonly string $excerpt = '',
		public readonly array $fields = [],
		public readonly array $field_status = [],
		public readonly array $edited_fields = [],
		public readonly array $counts = [],
		public readonly array $flags = [],
		public readonly array $block_flags = [],
		public readonly bool $complete = false,
		public readonly array $block_map = [],
		public readonly bool $one_to_one = false,
		public readonly bool $reordered = false,
		public readonly int $person_changed = 0,
		public readonly array $person_units = []
	) {}
}
