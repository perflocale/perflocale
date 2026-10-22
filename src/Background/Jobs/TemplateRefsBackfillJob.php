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
 * Points the menu and synced-pattern references of the translated templates
 * and template parts a site already has at their language's translations,
 * once per blog after an update to a version with
 * {@see TemplateBlockRefs}: its rules run when a translation is created or
 * linked, so containers written before carry the source-language references
 * until such an event reaches them, and a container the translator could
 * not edit was skipped.
 *
 * Dispatched from wp-admin (admin_init, by a user who may edit the theme's
 * templates) and run by the job runner (WP-Cron or Action Scheduler), never
 * on a visitor's request; `wp perflocale template-refs-backfill` runs it
 * again inline. The work is TemplateBlockRefs::backfill_page(): keyset pages
 * of container translations, the same rules and compare-and-set write as a
 * create, in time slices that resume from the last container read.
 *
 * The blog's state is the option `perflocale_template_refs_backfill`, in
 * the forms of {@see OneTimeDispatch}: absent until the first dispatch,
 * `claim:<unix time>` while a request dispatches, `queued:<job id>:<unix
 * time>` while the job is queued or running, `done:<unix time>` once it
 * finished (or when the blog had nothing to read), `failed:<unix time>` or
 * `canceled:<unix time>` once its job failed or was canceled. A job that
 * could not read the containers, or could not write one, fails with that
 * reason on its Jobs row and is never recorded as done; once the job
 * runner's automatic retries are used up the state records the failure, and
 * the next dispatch waits until it failed at least an hour ago.
 */
final class TemplateRefsBackfillJob extends AbstractJob {

	/**
	 * The blog's backfill state.
	 */
	public const OPTION = 'perflocale_template_refs_backfill';

	/**
	 * Capability of a user who may run the backfill.
	 */
	private const CAPABILITY = 'edit_theme_options';

	/**
	 * Seconds a failed backfill waits before an admin page view dispatches it
	 * again.
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
	 * Templates and template parts are edited by users who may edit the
	 * theme's options.
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
	 * Result: `read` containers read, `updated` containers written,
	 * `references` references pointed at a translation, `after` the last
	 * container read (the resume point). No count is read up front: the
	 * containers are found page by page, and a run that finds none records
	 * done.
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

			// Progress as the worker finds it: what was read, of that and one
			// more page while there is one.
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
	 * `admin_init`: the first wp-admin request of a user who may run the
	 * backfill dispatches it, once per blog, and dispatches it again when the
	 * job it recorded was canceled or is gone, or failed at least an hour
	 * ago. Decided from the autoloaded state option alone; a queued job's
	 * row is read at most once per {@see OneTimeDispatch::CHECK_EVERY}
	 * seconds. The request claims the state first, in one statement, and
	 * reads no container: of simultaneous admin requests only the one that
	 * wins the claim dispatches, and the job finds the containers page by
	 * page. Nothing on any other request.
	 *
	 * @return void
	 */
	public static function maybe_dispatch(): void {
		$state = get_option( self::OPTION, '' );

		// Done, the state of every admin request once the backfill ran, stops
		// here without loading the dispatch class.
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
	 * Queue the backfill job and record it as queued over the claim of the
	 * request that won it ({@see OneTimeDispatch::publish()}).
	 *
	 * @param string $claim The claim this request holds.
	 * @return void
	 */
	private static function dispatch( string $claim ): void {
		$queued = Dispatcher::enqueue( new self(), [] );

		// Not queued (a veto filter, a refused dispatch): no job id, and the
		// next admin request tries again.
		OneTimeDispatch::publish( self::OPTION, $claim, isset( $queued['job_id'] ) && is_string( $queued['job_id'] ) ? $queued['job_id'] : '' );
	}
}
