<?php
/**
 * The resync engine for one pair.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Util\WorkBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the pure part of a resync on values the caller read: parse, segment, align, match, compose, verify, and report
 * the counts, flags and completeness. No database, no post, no provider: machine outputs come in as texts.
 *
 * @phpstan-type Document array{content?: string, title?: string, excerpt?: string, fields?: array<string, mixed>}
 * @phpstan-type Options array{keep_exceptions?: bool, threshold?: int, block_map?: array<mixed>, post_type?: string, segment?: array<string, mixed>, fields_owned?: array<string, string>, fields_unknown?: list<string>, fields_absent?: list<string>, seed_cleared?: array<string, mixed>, base_known?: bool, texts?: array<int, string>, field_texts?: array<string, string>, reference?: (callable(string): array<mixed>)|null, person_edited?: bool, person_automatic?: array<string, true>|null}
 */
final class Engine {

	/** The flag of an alignment the policy must not apply unseen. */
	public const FLAG_AMBIGUOUS = Aligner::FLAG_AMBIGUOUS;

	/** The cause of an `unsupported` pair whose run would not fit the request's memory. */
	public const ERROR_MEMORY = 'memory';

	/**
	 * Units or block delimiters the documents must hold before the memory headroom is checked, so a smaller run never
	 * reads the limit (a run peaks near 12-19 MB at about that size, measured).
	 */
	private const MEMORY_CHECK_UNITS = 2000;

	/** Memory the trees and segmentations must hold before the headroom is checked whatever the counts. */
	private const MEMORY_CHECK_BYTES = 4194304;

	/** The least memory a scanned tree holds per block delimiter (605 to 1,018 bytes measured). */
	private const TREE_BYTES_PER_DELIMITER = 600;

	/**
	 * The run's peak beyond its three trees for blocks without text, as a share of the trees' memory plus PEAK_PER_BLOCK
	 * per block of each side: covers the measured 1.11 to 2.41 times the trees, within 30 %.
	 */
	private const PEAK_PER_TREE_BYTE = 0.8;

	/** See {@see self::PEAK_PER_TREE_BYTE}: bytes per block (`<!-- wp:`) of each side. */
	private const PEAK_PER_BLOCK = 1150;

	/** The passing peak of a side's segmentation per tag (`<`) of its document: 235 to 966 bytes measured. */
	private const PEAK_PER_TAG = 1000;

	/**
	 * The passing peak of a side's scan per byte of its document, core's parser as parity guard included: 2.0 to 3.9
	 * measured; it also covers the later segmentation (at most 3.7 per byte).
	 */
	private const PEAK_PER_SCANNED_BYTE = 4;

	/** The passing peak of a side's scan per block delimiter: 1,441 to 2,460 bytes measured. */
	private const PEAK_PER_SCANNED_DELIMITER = 2500;

	/**
	 * The second half's memory per composed byte beyond the verifier's scan (content, unit texts, verifier segmentation);
	 * with the scan it covers the measured 0.26 to 0.93 of the projection.
	 */
	private const PEAK_PER_COMPOSED_BYTE = 3;

	/**
	 * The passing peak of one unit's work per byte of its text (1.6 to 22.2 measured); counted once, for the largest unit,
	 * an html unit without its tags.
	 */
	private const PEAK_PER_UNIT_BYTE = 24;

	/**
	 * The run's peak over its three segmentations' memory, rounded up from the largest measured (1.5 to 2.2); alignment,
	 * matching, composition and the verifier's segmentation take the rest.
	 */
	private const PEAK_PER_SEGMENTED_BYTE = 2.25;

	/** Memory a run leaves unused under the limit, for the request that runs it. */
	public const MEMORY_RESERVE = 16777216;

	/**
	 * Run the engine on a pair: prepare() then complete() with the options' texts.
	 *
	 * @param array<string, mixed> $base        The base: `content`, `title`, `excerpt`, `fields` (values per key).
	 * @param array<string, mixed> $translation The translation, same shape.
	 * @param array<string, mixed> $source      The new source, same shape.
	 * @param array<string, mixed> $options     `keep_exceptions` (default true), `threshold` (default 100), `block_map`, `post_type` (default `post`), `segment` (segmenter options), `fields_owned`, `fields_unknown`, `fields_absent` (keys proven to have no row on both posts), `seed_cleared`, `base_known`, `texts` (machine outputs per unit index of the matching, content units first, then title and excerpt), `field_texts` (machine outputs per key), `reference` (a reference parse for the scanner's parity guard), `person_edited` (rule 1: a person edited the translation since the last automatic write, {@see PersonBlocks}), `person_automatic` (for a translation without a block map, the own parts of the last automatic write's blocks, {@see PersonBlocks::owns()}).
	 * @return Proposal
	 * @phpstan-param Document $base
	 * @phpstan-param Document $translation
	 * @phpstan-param Document $source
	 * @phpstan-param Options $options
	 */
	public static function run( array $base, array $translation, array $source, array $options = [] ): Proposal {
		$prepared = self::prepare( $base, $translation, $source, $options );

		if ( $prepared instanceof Proposal ) {
			return $prepared;
		}

		return self::complete( $prepared, $options['texts'] ?? [], $options['field_texts'] ?? [], (bool) ( $options['person_edited'] ?? false ), $options['person_automatic'] ?? null );
	}

	/**
	 * The first half of a run: parse, segment, align, match, and decide the fields without machine texts. An unsupported
	 * or unreadable document, or a run whose projected memory peak would leave less than the reserve under the limit
	 * ({@see self::ERROR_MEMORY}, checked before each allocation step), gives an `unsupported` proposal: side and cause.
	 *
	 * @param array<string, mixed> $base        The base document.
	 * @param array<string, mixed> $translation The translation document.
	 * @param array<string, mixed> $source      The new source document.
	 * @param array<string, mixed> $options     See run(); `texts` and `field_texts` are ignored here.
	 * @return Prepared|Proposal
	 * @phpstan-param Document $base
	 * @phpstan-param Document $translation
	 * @phpstan-param Document $source
	 * @phpstan-param Options $options
	 */
	public static function prepare( array $base, array $translation, array $source, array $options = [] ): Prepared|Proposal {
		$threshold = (int) ( $options['threshold'] ?? 100 );
		$post_type = (string) ( $options['post_type'] ?? 'post' );
		$segment   = $options['segment'] ?? [];
		$reference = $options['reference'] ?? null;

		$trees = [];

		$documents = [
			'base'        => $base,
			'translation' => $translation,
			'source'      => $source,
		];

		$delimiters = [];
		$blocks     = 0;

		foreach ( $documents as $side => $doc ) {
			$content             = (string) ( $doc['content'] ?? '' );
			$opened              = substr_count( $content, '<!-- wp:' );
			$blocks             += $opened;
			$delimiters[ $side ] = $opened + substr_count( $content, '<!-- /wp:' );
		}

		$counted = array_sum( $delimiters );

		if ( $counted >= self::MEMORY_CHECK_UNITS && ! self::fits_trees( 0, $counted * self::TREE_BYTES_PER_DELIMITER, $blocks ) ) {
			return new Proposal( Proposal::STATUS_UNSUPPORTED, (string) array_search( max( $delimiters ), $delimiters, true ) . ': ' . self::ERROR_MEMORY );
		}

		$start = memory_get_usage();

		foreach ( $documents as $side => $doc ) {
			$content  = (string) ( $doc['content'] ?? '' );
			$scanning = strlen( $content ) * self::PEAK_PER_SCANNED_BYTE + $delimiters[ $side ] * self::PEAK_PER_SCANNED_DELIMITER;

			if ( $scanning >= self::MEMORY_CHECK_BYTES && ! self::fits_passing( $scanning ) ) {
				return new Proposal( Proposal::STATUS_UNSUPPORTED, $side . ': ' . self::ERROR_MEMORY );
			}

			$tree = BlockScanner::scan( $content, $reference );

			if ( ! $tree->supported ) {
				return new Proposal( Proposal::STATUS_UNSUPPORTED, $side . ': ' . $tree->reason );
			}

			$trees[ $side ] = $tree;
			$held           = max( 0, memory_get_usage() - $start );

			if ( ( $counted >= self::MEMORY_CHECK_UNITS || $held >= self::MEMORY_CHECK_BYTES ) && ! self::fits_trees( $held, intdiv( $held * 3, count( $trees ) ), $blocks ) ) {
				return new Proposal( Proposal::STATUS_UNSUPPORTED, $side . ': ' . self::ERROR_MEMORY );
			}
		}

		$trees_held = max( 0, memory_get_usage() - $start );
		$before     = memory_get_usage();
		$units      = 0;
		$largest    = 0;
		$segs       = [];

		foreach ( [ 'base', 'translation', 'source' ] as $side ) {
			$segmenting = substr_count( (string) ( $documents[ $side ]['content'] ?? '' ), '<' ) * self::PEAK_PER_TAG;

			if ( $segmenting >= self::MEMORY_CHECK_BYTES && ! self::fits_passing( $segmenting ) ) {
				return new Proposal( Proposal::STATUS_UNSUPPORTED, $side . ': ' . self::ERROR_MEMORY );
			}

			$seg = Segmenter::segment( $trees[ $side ], $segment );

			if ( $seg->error !== '' ) {
				return new Proposal( Proposal::STATUS_UNSUPPORTED, $side . ': ' . $seg->error );
			}

			$segs[ $side ] = $seg;
			$units        += count( $seg->units );

			foreach ( $seg->units as $unit ) {
				$bytes = strlen( $unit->text );

				// A large unit counts its text without its tags: its tokens are what its work holds.
				if ( $bytes * self::PEAK_PER_UNIT_BYTE >= self::MEMORY_CHECK_BYTES && $bytes > $largest && BlockAttributeSource::FORMAT_HTML === $unit->format ) {
					$bytes = Keys::text_bytes( $unit->text );
				}

				$largest = max( $largest, $bytes );
			}

			$held = max( 0, memory_get_usage() - $before );

			if ( ( $units >= self::MEMORY_CHECK_UNITS || $trees_held + $held >= self::MEMORY_CHECK_BYTES ) && ! self::fits( $held, count( $segs ), $largest ) ) {
				return new Proposal( Proposal::STATUS_UNSUPPORTED, $side . ': ' . self::ERROR_MEMORY );
			}
		}

		[ 'base' => $seg_b, 'translation' => $seg_t, 'source' => $seg_n ] = $segs;

		$fields_b = self::field_units( $base, $post_type );
		$fields_t = self::field_units( $translation, $post_type );
		$fields_n = self::field_units( $source, $post_type );

		$alignment = Aligner::align( $seg_b, $seg_t, $options['block_map'] ?? [], $fields_b, $fields_t );
		$matching  = Matcher::match( $seg_n, $seg_b, $alignment, $threshold, $fields_n, $fields_b );

		$source_bytes      = strlen( (string) ( $source['content'] ?? '' ) );
		$translation_bytes = strlen( (string) ( $translation['content'] ?? '' ) );

		// The matcher stopped where its similarity tokens would not fit.
		if ( $matching->memory ) {
			return new Proposal( Proposal::STATUS_UNSUPPORTED, ( $source_bytes >= strlen( (string) ( $base['content'] ?? '' ) ) ? 'source' : 'base' ) . ': ' . self::ERROR_MEMORY );
		}

		// The composition is about the source's bytes times the translation/base ratio, at most both together; it is held
		// while the verifier scans it, then segments it.
		$composed  = min( $source_bytes + $translation_bytes, (int) ( $source_bytes * max( 1.0, $translation_bytes / max( 1, strlen( (string) ( $base['content'] ?? '' ) ) ) ) ) );
		$composing = $composed * ( self::PEAK_PER_COMPOSED_BYTE + self::PEAK_PER_SCANNED_BYTE ) + $delimiters['source'] * self::PEAK_PER_SCANNED_DELIMITER;

		// Counted on the real memory: the matcher's freed tokens leave pages
		// that the second half's allocations do not reuse.
		if ( $composing >= self::MEMORY_CHECK_BYTES && ! self::fits_passing( $composing, true ) ) {
			return new Proposal( Proposal::STATUS_UNSUPPORTED, ( $source_bytes >= $translation_bytes ? 'source' : 'translation' ) . ': ' . self::ERROR_MEMORY );
		}

		return new Prepared(
			$base,
			$translation,
			$source,
			$options,
			$seg_b,
			$seg_t,
			$seg_n,
			$fields_b,
			$fields_t,
			$fields_n,
			$alignment,
			$matching,
			self::merge_fields( $base, $translation, $source, $options, [] )
		);
	}

	/**
	 * The second half of a run: compose with the given texts, verify (the plan, then no added block damage), title and
	 * excerpt by the unit rule, fields three ways, then counts, flags, completeness, block map and the person-written
	 * blocks it changes ({@see PersonBlocks}).
	 *
	 * @param Prepared                 $prepared      A prepared pair.
	 * @param array<int, string>       $texts         Machine outputs per unit index of the matching.
	 * @param array<string, string>    $field_texts   Machine outputs per key.
	 * @param bool                     $person_edited Rule 1: a person edited the translation since the last automatic write.
	 * @param array<string, true>|null $person_automatic For a translation without a block map, the own parts of the last automatic write's blocks ({@see PersonBlocks::owns()}); null when unknown.
	 * @param array<int, string>       $verbatim      Machine mode: the keys of the custom fields with nothing to translate ({@see UnitTranslator::verbatim()}), which take the source's value as the same as the source.
	 * @return Proposal
	 * @phpstan-param list<string> $verbatim
	 */
	public static function complete( Prepared $prepared, array $texts = [], array $field_texts = [], bool $person_edited = false, ?array $person_automatic = null, array $verbatim = [] ): Proposal {
		$options   = $prepared->options;
		$keep      = (bool) ( $options['keep_exceptions'] ?? true );
		$segment   = $options['segment'] ?? [];
		$reference = $options['reference'] ?? null;
		$matching  = $prepared->matching;
		$alignment = $prepared->alignment;

		try {
			$composition = Composer::compose( $prepared->seg_n, $prepared->seg_b, $prepared->seg_t, $alignment, $matching, $texts, $keep );
		} catch ( ComposeError $e ) {
			return new Proposal( Proposal::STATUS_COMPOSE_ERROR, $e->getMessage() );
		}

		$verified = Verifier::verify( $composition, $segment, $reference );

		if ( $verified['errors'] !== [] || $verified['segmentation'] === null ) {
			return new Proposal( Proposal::STATUS_COMPOSE_ERROR, implode( ',', $verified['errors'] ) );
		}

		if ( Verifier::adds_damage( $composition->content, (string) ( $prepared->source['content'] ?? '' ), (string) ( $prepared->translation['content'] ?? '' ) ) ) {
			return new Proposal( Proposal::STATUS_COMPOSE_ERROR, Verifier::ERROR_STRUCTURE );
		}

		$counts = $composition->counts;
		$flags  = $composition->flags;

		$counts[ Matcher::STATUS_REMOVED ] = count( $matching->removed_units );
		$counts['unknown_base']            = 0;

		$title   = self::field( $prepared->fields_n, Keys::ROLE_TITLE, (string) ( $prepared->translation['title'] ?? '' ), $matching, $texts, $counts, $flags );
		$excerpt = self::field( $prepared->fields_n, Keys::ROLE_EXCERPT, (string) ( $prepared->translation['excerpt'] ?? '' ), $matching, $texts, $counts, $flags );

		$merged = $field_texts === [] && $verbatim === [] ? $prepared->field_plan : self::merge_fields( $prepared->base, $prepared->translation, $prepared->source, $options, $field_texts, $verbatim );

		foreach ( $merged['counts'] as $key => $count ) {
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + $count;
		}

		foreach ( $merged['flags'] as $flag ) {
			$flags = self::add_flag( $flags, Composer::DOCUMENT_KEY, $flag );
		}

		if ( $alignment->ambiguous() || $matching->over_budget ) {
			$flags = self::add_flag( $flags, Composer::DOCUMENT_KEY, self::FLAG_AMBIGUOUS );
		}

		$flag_list = ( new Composition( '', $flags, [], [], [], [] ) )->flag_list();
		$complete  = $counts['left_source'] === 0 && $counts[ Matcher::STATUS_FUZZY ] === 0 && $counts['unknown_base'] === 0 && $flag_list === [];
		$person    = PersonBlocks::of( $prepared, $person_edited, $person_automatic );

		[ $block_map, $own_by_origin ] = self::block_map( $verified['segmentation'], $composition, $person );

		return new Proposal(
			Proposal::STATUS_PROPOSAL,
			'',
			$composition->content,
			$title,
			$excerpt,
			$merged['values'],
			$merged['status'],
			$merged['edited'],
			$counts,
			$flag_list,
			$flags,
			$complete,
			$block_map,
			$alignment->one_to_one(),
			self::reordered( $alignment ),
			$person->changed( $own_by_origin ),
			$person->units()
		);
	}

	/**
	 * Memory a run leaves unused under the limit: MEMORY_RESERVE, or what an option write takes when that is more
	 * ({@see WorkBudget::options_reserve()}).
	 *
	 * @return int Bytes.
	 */
	public static function reserve(): int {
		return max( self::MEMORY_RESERVE, WorkBudget::options_reserve() );
	}

	/**
	 * Whether the run fits memory after its segmentations so far: their memory projected to three sides times
	 * PEAK_PER_SEGMENTED_BYTE, plus the largest unit's work, on top of what was held before leaves the reserve.
	 *
	 * @param int $held    Memory the segmentations so far hold.
	 * @param int $sides   Sides segmented so far, 1 to 3.
	 * @param int $largest Bytes of the largest unit's text so far.
	 * @return bool
	 */
	private static function fits( int $held, int $sides, int $largest ): bool {
		$limit = WorkBudget::memory_limit();

		if ( $limit === null ) {
			return true;
		}

		return memory_get_usage( true ) - $held + (int) ( $held * 3 / $sides * self::PEAK_PER_SEGMENTED_BYTE ) + $largest * self::PEAK_PER_UNIT_BYTE + self::reserve() <= $limit;
	}

	/**
	 * Whether a step's passing peak (a side's scan or segmentation, the verifier's) on top of the memory in use leaves the
	 * reserve under the limit. Always when unbounded.
	 *
	 * @param int  $peak The step's projected passing peak, in bytes.
	 * @param bool $real Whether the memory in use is the real memory (the pages PHP holds) rather than the memory allocated.
	 * @return bool
	 */
	private static function fits_passing( int $peak, bool $real = false ): bool {
		$limit = WorkBudget::memory_limit();

		return $limit === null || memory_get_usage( $real ) + $peak + self::reserve() <= $limit;
	}

	/**
	 * Whether the run fits memory before or between scans: the trees still to come and the rest of the run on them, on top
	 * of the memory in use (not the real memory: a scan's passing parser arrays are free for the next), leave the reserve.
	 *
	 * @param int $held      Memory the trees scanned so far hold.
	 * @param int $projected Memory the three trees would hold.
	 * @param int $blocks    Blocks of the three documents.
	 * @return bool
	 */
	private static function fits_trees( int $held, int $projected, int $blocks ): bool {
		$limit = WorkBudget::memory_limit();

		if ( $limit === null ) {
			return true;
		}

		return memory_get_usage() + max( 0, $projected - $held ) + (int) ( $projected * self::PEAK_PER_TREE_BYTE ) + $blocks * self::PEAK_PER_BLOCK + self::reserve() <= $limit;
	}

	/**
	 * The fields of a pair, three ways.
	 *
	 * @param array<string, mixed>  $base        Base document.
	 * @param array<string, mixed>  $translation Translation document.
	 * @param array<string, mixed>  $source      Source document.
	 * @param array<string, mixed>  $options     Engine options.
	 * @param array<string, string> $field_texts Machine outputs per key.
	 * @param array<int, string>    $verbatim    Keys that take the source's value as the same as the source.
	 * @return array<string, mixed>
	 * @phpstan-param Document $base
	 * @phpstan-param Document $translation
	 * @phpstan-param Document $source
	 * @phpstan-param Options $options
	 * @phpstan-param list<string> $verbatim
	 * @phpstan-return array{values: array<string, string>, status: array<string, string>, edited: list<string>, flags: list<string>, counts: array<string, int>}
	 */
	private static function merge_fields( array $base, array $translation, array $source, array $options, array $field_texts, array $verbatim = [] ): array {
		return FieldMerge::merge(
			(array) ( $base['fields'] ?? [] ),
			(array) ( $source['fields'] ?? [] ),
			(array) ( $translation['fields'] ?? [] ),
			[
				'owned'        => $options['fields_owned'] ?? [],
				'unknown'      => $options['fields_unknown'] ?? [],
				'seed_cleared' => $options['seed_cleared'] ?? [],
				'threshold'    => (int) ( $options['threshold'] ?? 100 ),
				'texts'        => $field_texts,
				'verbatim'     => $verbatim,
				'base_known'   => (bool) ( $options['base_known'] ?? true ),
				'absent'       => $options['fields_absent'] ?? [],
			]
		);
	}

	/**
	 * Whether the translation's blocks stand in another order than their base blocks.
	 *
	 * @param Alignment $alignment Alignment.
	 * @return bool
	 */
	private static function reordered( Alignment $alignment ): bool {
		$last = -1;

		foreach ( $alignment->pairs as [ , $t ] ) {
			if ( $t->start < $last ) {
				return true;
			}

			$last = $t->start;
		}

		return false;
	}

	/**
	 * The title and excerpt of a document as units.
	 *
	 * @param array<string, mixed> $doc       Document.
	 * @param string               $post_type Post type.
	 * @return list<Unit>
	 * @phpstan-param Document $doc
	 */
	private static function field_units( array $doc, string $post_type ): array {
		$out   = [];
		$title = Segmenter::segment_title( (string) ( $doc['title'] ?? '' ), $post_type );

		if ( $title !== null ) {
			$out[] = $title;
		}

		$excerpt = Segmenter::segment_excerpt( (string) ( $doc['excerpt'] ?? '' ) );

		if ( $excerpt !== null ) {
			$out[] = $excerpt;
		}

		return $out;
	}

	/**
	 * A title or excerpt by the unit rule on the whole field: the translation's text for a reused or near match, else an
	 * accepted machine output or the source's text; the translation's value when the source has no unit for it.
	 *
	 * @param array<int, Unit>            $source      The source's field units.
	 * @param string                      $role        {@see Keys::ROLE_TITLE} or {@see Keys::ROLE_EXCERPT}.
	 * @param string                      $current     The translation's current value.
	 * @param Matching                    $matching    Matching.
	 * @param array<int, string>          $texts       Machine outputs per unit index.
	 * @param array<string, int>          $counts      Counts, updated.
	 * @param array<string, list<string>> $flags       Flags, updated.
	 * @return string
	 * @phpstan-param list<Unit> $source
	 */
	private static function field( array $source, string $role, string $current, Matching $matching, array $texts, array &$counts, array &$flags ): string {
		foreach ( $source as $unit ) {
			if ( $unit->role !== $role ) {
				continue;
			}

			$match = $matching->match_of( $unit );

			if ( $match === null ) {
				return $current;
			}

			++$counts[ $match->status ];

			if ( $match->same_as_source ) {
				++$counts[ Matcher::SAME_AS_SOURCE ];
			}

			if ( ( $match->status === Matcher::STATUS_REUSED || $match->status === Matcher::STATUS_FUZZY ) && $match->translation !== null ) {
				return $match->translation->text;
			}

			if ( $match->status === Matcher::STATUS_REMOVED_IN_TRANSLATION ) {
				return $current;
			}

			$index = array_search( $match, $matching->units, true );

			if ( $index !== false && isset( $texts[ $index ] ) ) {
				if ( Verifier::accepts_for( $unit, $texts[ $index ] ) ) {
					++$counts['machine_translated'];

					return $texts[ $index ];
				}

				$flags = self::add_flag( $flags, Composer::DOCUMENT_KEY, Composer::FLAG_MT_QUALITY );
			}

			++$counts['left_source'];

			return $unit->text;
		}

		return $current;
	}

	/**
	 * Add a flag to a key, once.
	 *
	 * @param array<string, list<string>> $flags Flags.
	 * @param string                      $key   Key.
	 * @param string                      $flag  Flag.
	 * @return array<string, list<string>>
	 */
	private static function add_flag( array $flags, string $key, string $flag ): array {
		if ( ! in_array( $flag, $flags[ $key ] ?? [], true ) ) {
			$flags[ $key ][] = $flag;
		}

		return $flags;
	}

	/**
	 * The block map of the composed translation, in document order: per output block that stands for a source block, its
	 * path, its canon's first 16 hex characters and the source path, plus person data ({@see PersonBlocks::entry()}).
	 * With a person's block in the pair, each output block's own-part hash by source path comes back too.
	 *
	 * @param Segmentation      $output      The composed content, segmented.
	 * @param Composition       $composition The composition.
	 * @param PersonBlocks|null $person The pair's person's blocks; null for none.
	 * @return array{list<list<string>>, array<string, string>}
	 */
	private static function block_map( Segmentation $output, Composition $composition, ?PersonBlocks $person = null ): array {
		$nodes  = [];
		$canons = [];

		foreach ( $output->tree->nodes as $node ) {
			foreach ( self::block_nodes( $node ) as $block ) {
				$nodes[] = $block;
			}

			if ( $node->name !== null ) {
				self::canon_index( $node, $output->tree, $canons );
			}
		}

		$map    = [];
		$owns   = [];
		$tracks = null !== $person && ! $person->none();

		foreach ( $nodes as $i => $node ) {
			$origin = $composition->origins[ $i ] ?? null;

			if ( $origin === null ) {
				continue;
			}

			$hash16 = substr( $canons[ spl_object_id( $node ) ], 0, 16 );
			$own    = $node->children === [] ? '' : PersonBlocks::own( $node, $output->tree );

			if ( $tracks ) {
				$owns[ $origin ] ??= '' === $own ? $hash16 : $own;
			}

			$map[] = PersonBlocks::entry( Segmentation::path_key( $node ), $hash16, $origin, $tracks && $person->keeps( $origin ), $own );
		}

		return [ $map, $owns ];
	}

	/**
	 * The canon of a block and every block below it, by node id, children first ({@see Keys::canon_of()}), so each node
	 * is hashed once whatever its depth.
	 *
	 * @param BlockNode          $node   Node.
	 * @param BlockTree          $tree   The node's tree.
	 * @param array<int, string> $canons Canon per node id, added to.
	 * @return string The node's canon.
	 */
	private static function canon_index( BlockNode $node, BlockTree $tree, array &$canons ): string {
		$children = [];

		foreach ( $node->children as $child ) {
			$children[] = self::canon_index( $child, $tree, $canons );
		}

		$canon = Keys::canon_of( $node, $tree, $children );

		$canons[ spl_object_id( $node ) ] = $canon;

		return $canon;
	}

	/**
	 * The blocks of a subtree in document order.
	 *
	 * @param BlockNode $node Node.
	 * @return list<BlockNode>
	 */
	private static function block_nodes( BlockNode $node ): array {
		$out = $node->name === null ? [] : [ $node ];

		foreach ( $node->children as $child ) {
			foreach ( self::block_nodes( $child ) as $sub ) {
				$out[] = $sub;
			}
		}

		return $out;
	}
}
