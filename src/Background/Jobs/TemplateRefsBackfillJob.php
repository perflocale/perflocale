<?php
/**
 * One-time backfill of menu and pattern references in translated templates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\Dispatcher;
use PerfLocale\Background\OneTimeDispatch;
use PerfLocale\Translation\TemplateBlockRefs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points the menu and synced-pattern references of a site's existing translated templates and parts at their
 * language's translations, once per blog: TemplateBlockRefs runs on a create or link, so older containers keep source
 * references. Queued from wp-admin (maybe_dispatch()); `wp perflocale template-refs-backfill` runs it inline.
 */
final class TemplateRefsBackfillJob extends AbstractJob {

	/**
	 * The blog's backfill state (OneTimeDispatch forms): absent, `claim:`, `queued:<job id>:`, `done:` (also when
	 * there was nothing to read), `failed:` or `canceled:` plus a unix time. A failed read or write is never done.
	 */
	public const OPTION = 'perflocale_template_refs_backfill';

	/**
	 * Capability of a user who may run the backfill.
	 */
	private const CAPABILITY = 'edit_theme_options';

	/**
	 * Seconds a failed backfill (retries used up) waits before an admin page view dispatches it again.
	 */
	private const RETRY_AFTER_FAILED = HOUR_IN_SECONDS;

	/**
	 * Containers read per page.
	 */
	private const PAGE = 50;

	/**
	 * Seconds of work per slice.
	 */
	private const SLICE_SECONDS = 10;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'template_refs_backfill';
	}

	/**
	 * {@inheritDoc}
	 *
	 * `edit_theme_options`: who edits templates and template parts.
	 */
	public function get_required_capability(): string {
		return self::CAPABILITY;
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return 0;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Result: `read`, `updated` and `references` counts, and `after` (the resume point). No count up front: containers
	 * are found page by page, and a run that finds none records done.
	 *
	 * @param array<string, mixed> $args     Worker args (`__perflocale_slice` from the worker).
	 * @param callable             $progress Progress reporter.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the containers could not be read.
	 */
	public function execute( array $args, callable $progress ): array {
		$slice  = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : [];
		$resume = is_array( $slice['resume'] ?? null ) ? $slice['resume'] : [];
		$count  = static fn( $value ): int => is_numeric( $value ) ? (int) $value : 0;
		$result = [
			'read'       => $count( $resume['read'] ?? 0 ),
			'updated'    => $count( $resume['updated'] ?? 0 ),
			'references' => $count( $resume['references'] ?? 0 ),
			'after'      => $count( $resume['after'] ?? 0 ),
		];
		$sliced = [] !== $slice;
		$until  = microtime( true ) + self::SLICE_SECONDS;

		do {
			// A sliced run checks its time budget after each container.
			$page = TemplateBlockRefs::backfill_page( $result['after'], self::PAGE, $sliced ? $until : null );

			if ( $page['error'] && $page['failed'] > 0 ) {
				throw new \RuntimeException(
					'conflict' === $page['cause']
						? esc_html__( 'A template or template part was being edited at the same time, so its menu or pattern reference was not updated yet. It is tried again later.', 'perflocale' )
						: esc_html__( 'A template or template part could not be saved because the database did not accept the change. It is tried again later.', 'perflocale' )
				);
			}

			if ( $page['error'] ) {
				throw new \RuntimeException( esc_html__( 'The templates and template parts to update could not be read (database error, or no default language). The update runs again from the next admin page view.', 'perflocale' ) );
			}

			$result['read']       += $page['read'];
			$result['updated']    += $page['updated'];
			$result['references'] += $page['references'];
			$result['after']       = $page['next'];

			// Progress as found: read so far, of that plus one page while another page exists.
			$progress( $result['read'], 0 === $page['next'] ? max( 1, $result['read'] ) : $result['read'] + self::PAGE );

			if ( 0 === $page['next'] ) {
				update_option( self::OPTION, 'done:' . time(), true );

				return $result;
			}
		} while ( ! $sliced || microtime( true ) < $until );

		$result['__perflocale_continue'] = true;

		return $result;
	}

	/**
	 * `admin_init`: dispatches the backfill once per blog for a user who may run it, and again when its job was
	 * canceled, is gone or failed at least RETRY_AFTER_FAILED ago. Decided from the autoloaded state (a queued job's
	 * row is read at most once per OneTimeDispatch::CHECK_EVERY); only the request that wins the claim dispatches.
	 *
	 * @return void
	 */
	public static function maybe_dispatch(): void {
		$state = get_option( self::OPTION, '' );

		// Done (every admin request once the backfill ran) stops here without loading the dispatch class.
		if ( wp_doing_ajax() || ! is_string( $state ) || str_starts_with( $state, 'done:' ) ) {
			return;
		}

		OneTimeDispatch::maybe_dispatch(
			self::OPTION,
			$state,
			self::CAPABILITY,
			self::RETRY_AFTER_FAILED,
			false,
			static fn( string $claim ) => self::dispatch( $claim )
		);
	}

	/**
	 * Queue the backfill job and record it as queued over this request's claim (OneTimeDispatch::publish()).
	 *
	 * @param string $claim The claim this request holds.
	 * @return void
	 */
	private static function dispatch( string $claim ): void {
		$queued = Dispatcher::enqueue( new self(), [] );

		// Not queued (a veto filter, a refused dispatch): no job id, and the next admin request tries again.
		OneTimeDispatch::publish( self::OPTION, $claim, isset( $queued['job_id'] ) && is_string( $queued['job_id'] ) ? $queued['job_id'] : '' );
	}
}
