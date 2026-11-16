<?php
/**
 * The outcome of one pair.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What PairRunner::run() did for one (source, language) pair, with the figures a run result sums up.
 */
final class Outcome {

	/**
	 * Constructor.
	 *
	 * @param string             $status         applied, held, unchanged, outdated (nothing to write; the sync stays incomplete), confirmed, held_same, skipped_decided, missing, empty, target_gone, no_source, out_of_scope, skipped_permission, forbidden, no_base, unsupported, compose_error, needs_estimate, mt_unavailable (nothing written), limit, failed, stale, busy or unavailable.
	 * @param string             $reason         Why: the policy's reason for a held change, the engine's reason for an error, '' otherwise.
	 * @param int                $translation_id The translation, 0 when none was resolved.
	 * @param int                $change_id      The applied or held row, 0 when none.
	 * @param string             $decision       The policy's decision, '' when none was taken.
	 * @param array<string, int> $counts         The proposal's counts.
	 * @param array<int, string> $flags          The proposal's flags.
	 * @param int                $mt_chars       Characters sent to machine translation.
	 * @param int                $mt_requests    Provider requests made.
	 * @param string             $mt_unavailable Why MT was unavailable to a pair that needed it (mt_disabled, no_capability, no_provider); '' otherwise.
	 * @phpstan-param list<string> $flags
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $reason = '',
		public readonly int $translation_id = 0,
		public readonly int $change_id = 0,
		public readonly string $decision = '',
		public readonly array $counts = [],
		public readonly array $flags = [],
		public readonly int $mt_chars = 0,
		public readonly int $mt_requests = 0,
		public readonly string $mt_unavailable = ''
	) {}

	/**
	 * Whether the translation was written.
	 *
	 * @return bool
	 */
	public function wrote(): bool {
		return 'applied' === $this->status;
	}

	/**
	 * Whether a change row was recorded (applied or held).
	 *
	 * @return bool
	 */
	public function recorded(): bool {
		return 'applied' === $this->status || 'held' === $this->status;
	}
}
