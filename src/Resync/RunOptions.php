<?php
/**
 * The options of a resync run.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a run passes to PairRunner::run() for every pair. Only machine, threshold, keep_exceptions and fields shape a
 * proposal (options_hash()); the rest are facts of the run.
 */
final class RunOptions {

	/** The near-match thresholds the setting offers. */
	public const THRESHOLDS = [ 100, 95, 90, 85, 80 ];

	/**
	 * The match threshold: 100 (exact only) or one of the offered values.
	 *
	 * @var int
	 */
	public readonly int $threshold;

	/**
	 * Constructor.
	 *
	 * @param bool          $machine         Machine mode asked for; availability is still checked at run time.
	 * @param int           $threshold       The near-match threshold; a value not offered is exact only.
	 * @param bool          $keep_exceptions Whether per-language blocks and media are kept.
	 * @param bool          $fields          Whether custom fields are in scope for the run.
	 * @param bool          $per_post        An explicit per-post "Resync from source" (any source status but trash and auto-draft).
	 * @param bool          $query_mode      The Update translations dialog: a rejected or undone proposal is not proposed again.
	 * @param int           $ignore_own_lock The one post whose fresh edit lock by the acting user does not count.
	 * @param string        $trigger         The trigger name recorded on the change.
	 * @param string        $job_id          The job id recorded on the change.
	 * @param int|null      $mt_budget       Characters the run may send; null = no cap, 0 = a per-post run started at once.
	 * @param bool          $dry_run         An estimate: nothing is written or sent.
	 * @param \Closure|null $before_request Asked before each provider request with the count so far; false stops the pair. Null: no clock.
	 * @param int           $post_bound      Most text bytes a post the pair reads may hold (base included); PHP_INT_MAX: no bound.
	 * @phpstan-param (\Closure(int): bool)|null $before_request
	 */
	public function __construct(
		public readonly bool $machine = false,
		int $threshold = 100,
		public readonly bool $keep_exceptions = true,
		public readonly bool $fields = true,
		public readonly bool $per_post = false,
		public readonly bool $query_mode = false,
		public readonly int $ignore_own_lock = 0,
		public readonly string $trigger = 'resync',
		public readonly string $job_id = '',
		public readonly ?int $mt_budget = null,
		public readonly bool $dry_run = false,
		public readonly ?\Closure $before_request = null,
		public readonly int $post_bound = PHP_INT_MAX
	) {
		$this->threshold = in_array( $threshold, self::THRESHOLDS, true ) ? $threshold : 100;
	}

	/**
	 * The hash of the options that shape a proposal (a held proposal is the same only when it matches).
	 * Context joins it only in machine mode with context on, so hashes without context stay stable.
	 *
	 * @param array<string, mixed> $context The context settings: `mode`, `neighbours`, `previous`, `max_chars`; [] for none.
	 * @return string
	 */
	public function options_hash( array $context = [] ): string {
		$shape = [
			'machine'         => $this->machine,
			'threshold'       => $this->threshold,
			'keep_exceptions' => $this->keep_exceptions,
			'fields'          => $this->fields,
		];

		$mode = $context['mode'] ?? 'off';

		if ( $this->machine && is_string( $mode ) && 'off' !== $mode ) {
			$shape['context'] = [
				$mode,
				\PerfLocale\Util\Scalar::int( $context['neighbours'] ?? 0 ),
				! empty( $context['previous'] ),
				\PerfLocale\Util\Scalar::int( $context['max_chars'] ?? 0 ),
			];
		}

		return hash( 'sha256', (string) wp_json_encode( $shape ) );
	}
}
