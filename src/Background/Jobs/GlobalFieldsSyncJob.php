<?php
/**
 * Background job finishing the work of a string-scope sync.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\Dispatcher;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Plugin;
use PerfLocale\Strings\Domains;
use PerfLocale\Strings\StringScopeResult;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job `global_fields_sync`: runs the removals a sync deferred (chunk by chunk under the scope's lock) and the
 * orphan-scope sweep after a complete enumeration; a sweep removing a whole domain or over half of it is refused. Args:
 * 'scopes', 'sweep'.
 */
final class GlobalFieldsSyncJob extends AbstractJob {

	/** The job type. */
	public const TYPE = 'global_fields_sync';

	/**
	 * Seconds a sweep's live list stays usable: long enough for any queue latency.
	 * Scopes created meanwhile are protected by their rows' creation time, not by this window.
	 */
	public const SWEEP_MAX_AGE = 86400;

	/** {@inheritDoc} */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The capability of every entry point that changes string rows.
	 */
	public function get_required_capability(): string {
		return 'perflocale_manage_translations';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Always async in auto mode: the synchronous part already ran in the dispatching request.
	 */
	public function get_default_threshold(): int {
		return 0;
	}

	/**
	 * Hand the deferred removals of a scope to the job.
	 *
	 * The dispatcher refuses a request without a capable user (cron), so report the removals as deferred only on
	 * true; otherwise continue them with {@see StringRepository::continue_scope_removals()}.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return bool Whether the job accepted the removals (queued, or run inline without an error or a failed run).
	 */
	public static function dispatch_removals( string $domain, string $context_prefix ): bool {
		$dispatched = Dispatcher::dispatch(
			new self(),
			[
				'scopes' => [
					[
						'domain' => $domain,
						'prefix' => $context_prefix,
					],
				],
			]
		);

		return 'async' === $dispatched['mode'] || ( 'sync' === $dispatched['mode'] && ! isset( $dispatched['error'] ) && empty( $dispatched['result']['run_failed'] ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args     Dispatch args (scopes, sweep).
	 * @param callable             $progress Progress callback.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, callable $progress ): array {
		$scopes = $this->scopes( $args );
		$sweeps = $this->sweeps( $args );
		$repo   = new StringRepository( Plugin::get_instance()->cache() );
		$total  = count( $scopes ) + count( $sweeps );
		$done   = 0;
		$result = [
			'scopes'  => [],
			'sweep'   => [],
			'deleted' => 0,
		];

		$progress( 0, max( 1, $total ) );

		foreach ( $scopes as $scope ) {
			$key                 = $scope['domain'] . '|' . $scope['prefix'];
			[ $chunk, $deleted ] = $this->continue_removals( $repo, $scope['domain'], $scope['prefix'], $progress, $done, $total );

			$result['deleted']       += $deleted;
			$result['scopes'][ $key ] = $chunk->to_array();

			if ( ! $chunk->ok() ) {
				$result['run_failed']  = true;
				$result['first_error'] = sprintf(
					/* translators: 1: a string scope (domain and prefix), 2: the sync status */
					__( 'The removals of %1$s stopped: %2$s.', 'perflocale' ),
					$key,
					$chunk->status
				);

				return $result;
			}

			++$done;
			$progress( $done, $total );
		}

		// A sweep's deferred removals continue here, not in a second job; a failed orphan fails the job afterwards.
		$continue    = [];
		$sweep_error = '';

		foreach ( $sweeps as $sweep ) {
			if ( ! $sweep['complete'] ) {
				$result['sweep'][ $sweep['domain'] ] = 'skipped';
				++$done;
				$progress( $done, $total );
				continue;
			}

			if ( $sweep['enumerated_at'] <= 0 || $sweep['enumerated_at'] < time() - self::SWEEP_MAX_AGE ) {
				$result['sweep'][ $sweep['domain'] ] = 'refused_stale';

				if ( '' === $sweep_error ) {
					$sweep_error = sprintf(
						/* translators: 1: a text domain, 2: a number of seconds */
						__( 'The sweep of %1$s was refused: its list of live scopes is older than %2$d seconds, or carries no time. Run a string scan again.', 'perflocale' ),
						$sweep['domain'],
						self::SWEEP_MAX_AGE
					);
				}

				++$done;
				$progress( $done, $total );
				continue;
			}

			$ids     = in_array( $sweep['domain'], Domains::SWEEPABLE, true ) ? $repo->scope_ids( $sweep['domain'] ) : [];
			$refusal = $this->sweep_refusal( $sweep['domain'], $sweep['live_ids'], $ids );
			$results = null === $refusal && null !== $ids ? $repo->sweep_orphan_scopes( $sweep['domain'], $sweep['live_ids'], $sweep['enumerated_at'], $ids ) : null;

			if ( null === $results ) {
				$result['sweep'][ $sweep['domain'] ] = $refusal ?? 'refused';

				if ( 'db_error' === $refusal && '' === $sweep_error ) {
					$sweep_error = sprintf(
						/* translators: %s: a text domain */
						__( 'The sweep of %s was refused: its string scopes could not be read. The next string scan runs it again.', 'perflocale' ),
						$sweep['domain']
					);
				}

				++$done;
				$progress( $done, $total );
				continue;
			}

			foreach ( $results as $prefix => $removal ) {
				$result['sweep'][ $sweep['domain'] . '|' . $prefix ] = $removal->to_array();
				$result['deleted']                                  += $removal->deleted;

				if ( StringScopeResult::DEFERRED === $removal->status ) {
					$continue[] = [
						'domain' => $sweep['domain'],
						'prefix' => $prefix,
					];
				} elseif ( StringScopeResult::SKIPPED !== $removal->status && ! $removal->ok() && '' === $sweep_error ) {
					$sweep_error = sprintf(
						/* translators: 1: a string scope (domain and prefix), 2: the sync status */
						__( 'The sweep of %1$s stopped: %2$s.', 'perflocale' ),
						$sweep['domain'] . '|' . $prefix,
						$removal->status
					);
				}
			}

			++$done;
			$progress( $done, $total );
		}

		foreach ( $continue as $scope ) {
			$key                 = $scope['domain'] . '|' . $scope['prefix'];
			[ $chunk, $deleted ] = $this->continue_removals( $repo, $scope['domain'], $scope['prefix'], $progress, $done, $total );

			$result['deleted']       += $deleted;
			$result['scopes'][ $key ] = $chunk->to_array();

			if ( ! $chunk->ok() ) {
				$result['run_failed']  = true;
				$result['first_error'] = sprintf(
					/* translators: 1: a string scope (domain and prefix), 2: the sync status */
					__( 'The removals of %1$s stopped: %2$s.', 'perflocale' ),
					$key,
					$chunk->status
				);

				return $result;
			}
		}

		if ( '' !== $sweep_error ) {
			$result['run_failed']  = true;
			$result['first_error'] = $sweep_error;
		}

		return $result;
	}

	/**
	 * Why a sweep must not run, null when it may. A failed scope-id read is `db_error`, never an empty domain.
	 *
	 * @param string                  $domain   Text domain.
	 * @param array<int, string>      $live_ids The live scope ids.
	 * @param array<int, string>|null $ids      The domain's scope ids; null when the read failed.
	 * @return string|null
	 */
	private function sweep_refusal( string $domain, array $live_ids, ?array $ids ): ?string {
		if ( ! in_array( $domain, Domains::SWEEPABLE, true ) ) {
			return 'refused';
		}

		if ( null === $ids ) {
			return 'db_error';
		}

		if ( $ids === [] ) {
			return null;
		}

		if ( $live_ids === [] ) {
			return 'refused_empty_live_list';
		}

		$orphans = count( array_diff( $ids, $live_ids ) );

		return $orphans * 2 > count( $ids ) ? 'refused_over_half' : null;
	}

	/**
	 * Delete one scope's pending removals chunk by chunk, one progress tick per chunk (refreshes the job lock,
	 * sees a cancel).
	 *
	 * @param StringRepository $repo     Repository.
	 * @param string           $domain   Text domain.
	 * @param string           $prefix   The scope.
	 * @param callable         $progress Progress callback.
	 * @param int              $done     Items done.
	 * @param int              $total    Items in total.
	 * @return array{0: StringScopeResult, 1: int} The last chunk's result and the rows deleted.
	 */
	private function continue_removals( StringRepository $repo, string $domain, string $prefix, callable $progress, int $done, int $total ): array {
		$deleted = 0;

		do {
			$chunk    = $repo->continue_scope_removals( $domain, $prefix );
			$deleted += $chunk->deleted;
			$progress( $done, max( 1, $total ) );
		} while ( StringScopeResult::DEFERRED === $chunk->status );

		return [ $chunk, $deleted ];
	}

	/**
	 * The scopes of a dispatch.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return array<int, array{domain: string, prefix: string}>
	 */
	private function scopes( array $args ): array {
		$out = [];

		foreach ( (array) ( $args['scopes'] ?? [] ) as $scope ) {
			if ( ! is_array( $scope ) ) {
				continue;
			}

			$domain = Scalar::str( $scope['domain'] ?? '' );
			$prefix = Scalar::str( $scope['prefix'] ?? '' );

			if ( '' !== $domain && '' !== $prefix ) {
				$out[] = [
					'domain' => $domain,
					'prefix' => $prefix,
				];
			}
		}

		return $out;
	}

	/**
	 * The sweeps of a dispatch.
	 *
	 * @param array<string, mixed> $args Dispatch args.
	 * @return array<int, array{domain: string, live_ids: array<int, string>, complete: bool, enumerated_at: int}>
	 */
	private function sweeps( array $args ): array {
		$out = [];

		foreach ( (array) ( $args['sweep'] ?? [] ) as $sweep ) {
			if ( ! is_array( $sweep ) ) {
				continue;
			}

			$domain = Scalar::str( $sweep['domain'] ?? '' );

			if ( '' === $domain ) {
				continue;
			}

			$out[] = [
				'domain'        => $domain,
				'live_ids'      => array_values( array_filter( Scalar::strings( $sweep['live_ids'] ?? [] ), static fn( string $id ): bool => '' !== $id ) ),
				'complete'      => true === ( $sweep['complete'] ?? false ),
				'enumerated_at' => Scalar::int( $sweep['enumerated_at'] ?? 0 ),
			];
		}

		return $out;
	}
}
