<?php
/**
 * Jobs admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Background\JobRunnerFactory;
use PerfLocale\Background\JobState;
use PerfLocale\Migration\MigrationRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders PerfLocale > Jobs: active and recent jobs with status, progress and actions, polled from the Jobs REST
 * routes every 5 s while a row runs. Every change goes through those routes, so browser and CLI users share one
 * permission model.
 */
final class JobsPage {

	/**
	 * Job types whose result counts rows kept in the source language (`kept`, `first_warning`, `redo_notes`).
	 */
	private const KEPT_RESULT_TYPES = [ 'bulk_translate', 'site_translate' ];

	/**
	 * Migration job types whose result lists source items left out (`skipped`), by source.
	 */
	private const ATTENTION_RESULT_TYPES = [ 'polylang_migration' => 'polylang' ];

	/**
	 * Import job types whose result can name root posts a language prefix takes (`slug_clashes`).
	 */
	private const CLASH_RESULT_TYPES = [ 'data_import', 'wpml_migration', 'polylang_migration', 'translatepress_migration' ];

	/**
	 * Job types whose finished row reports what they changed, by count.
	 */
	private const COUNT_RESULT_TYPES = [ 'template_refs_backfill', 'template_refs_fanout', 'sqlite_group_repair' ];

	/**
	 * Job types whose result carries the first failed item's error (`first_error`), shown on a finished or canceled row.
	 */
	private const FIRST_ERROR_RESULT_TYPES = [ 'bulk_translate', 'site_translate', 'bulk_string_translate', 'string_scan', 'auto_translate' ];

	/**
	 * Characters of the first error a row shows; the job's details hold all of it.
	 */
	private const FIRST_ERROR_SHOWN = 140;

	/**
	 * Enqueue the page's stylesheet and refresh script, on the `perflocale_page_perflocale-jobs` screen only.
	 *
	 * @param string $hook
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'perflocale_page_perflocale-jobs' ) {
			return;
		}

		wp_enqueue_style(
			'perflocale-jobs',
			PERFLOCALE_URL . 'assets/css/jobs.css',
			[],
			PERFLOCALE_VERSION
		);

		// wp-hooks: the poller fires perflocale.jobs.statusChanged.
		wp_enqueue_script(
			'perflocale-jobs',
			PERFLOCALE_URL . 'assets/js/jobs.js',
			[ 'wp-hooks' ],
			PERFLOCALE_VERSION,
			true
		);

		wp_localize_script(
			'perflocale-jobs',
			'perflocaleJobs',
			[
				'pollUrl'     => rest_url( 'perflocale/v1/jobs' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'ago'         => __( 'ago', 'perflocale' ),
				'isMultisite' => is_multisite(),
				// SCRIPT_DEBUG: a hook callback that throws is reported once per hook in the console.
				'debug'       => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				'i18n'        => [
					'pollPaused'    => __( 'Live updates paused after repeated errors - reload the page to resume.', 'perflocale' ),
					'id'            => __( 'ID', 'perflocale' ),
					'type'          => __( 'Type', 'perflocale' ),
					'engine'        => __( 'Engine', 'perflocale' ),
					'created_by'    => __( 'Created by', 'perflocale' ),
					'blog_id'       => __( 'Blog ID', 'perflocale' ),
					'attempts'      => __( 'Attempts', 'perflocale' ),
					'error'         => __( 'Error', 'perflocale' ),
					'args'          => __( 'Args', 'perflocale' ),
					'result'        => __( 'Result', 'perflocale' ),
					'log'           => __( 'Log', 'perflocale' ),
					/* translators: %1$s: failure detail (HTTP status code or error message) */
					'requestFailed' => __( 'Failed: %1$s', 'perflocale' ),
					'stageProgress' => self::stage_progress_format(),
					/* translators: 1: overall progress in percent, 2: the current stage and its progress, e.g. "Posts: 120 of 500" */
					'stageValue'    => __( '%1$s%% (%2$s)', 'perflocale' ),
					'stages'        => MigrationRunner::stage_labels(),
				],
				'labels'      => [
					'queued'   => __( 'Queued', 'perflocale' ),
					'running'  => __( 'Running', 'perflocale' ),
					'complete' => __( 'Complete', 'perflocale' ),
					'failed'   => __( 'Failed', 'perflocale' ),
					'canceled' => __( 'Canceled', 'perflocale' ),
				],
			]
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Summary rows have no args, result or log: the kept-rows warning reads results in one batch below, and a row's
		// Details fetches GET /jobs/{id}.
		$jobs = \PerfLocale\Background\JobAccess::visible( JobState::list_active_summary() );

		// One batched result read for the displayed rows that show one (kept rows, counts, first error, ReSync notes).
		$kept_ids = [];
		foreach ( $jobs as $kept_id => $summary ) {
			if ( self::shows_result( $summary['status'] ?? '', $summary['type'] ?? '' ) ) {
				$kept_ids[] = (string) $kept_id;
			}
		}
		$results = JobState::results( $kept_ids );

		$engine = JobRunnerFactory::pick()->get_engine_name();
		$as_url = '';

		if ( $engine === 'action_scheduler' && function_exists( 'admin_url' ) ) {
			// Action Scheduler's screen ignores a `group` URL filter: its `s` search matches hook, group and args, and
			// "perflocale" is both the group and the hook prefix.
			$as_url = admin_url( 'tools.php?page=action-scheduler&s=perflocale' );
		}

		// The "scan queued" notice from AdminController's async dispatch; consumed on first read, so a refresh does not
		// replay it.
		$scan_queued_notice = get_transient( 'perflocale_scan_queued_notice_' . get_current_user_id() );
		if ( is_string( $scan_queued_notice ) && $scan_queued_notice !== '' ) {
			delete_transient( 'perflocale_scan_queued_notice_' . get_current_user_id() );
		} else {
			$scan_queued_notice = '';
		}

		// A queued job's confirmation: the redirect carries a fixed code, and only a known code shows a message.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queued_code    = isset( $_GET['perflocale_notice'] ) && is_string( $_GET['perflocale_notice'] ) ? sanitize_key( wp_unslash( $_GET['perflocale_notice'] ) ) : '';
		$queued_message = '' !== $queued_code ? self::queued_message( $queued_code ) : '';

		?>
		<div class="wrap perflocale-jobs">
			<h1><?php esc_html_e( 'Background Jobs', 'perflocale' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( $scan_queued_notice !== '' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $scan_queued_notice ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $queued_message !== '' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $queued_message ); ?></p>
				</div>
			<?php endif; ?>

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s is the engine name. */
					esc_html__( 'Long-running operations (imports, migrations, bulk translation) run in the background. Engine: %s', 'perflocale' ),
					'<code>' . esc_html( $engine ) . '</code>'
				);
				?>
			</p>

			<?php if ( \PerfLocale\Background\WorkerRegistry::queue_is_paused() ) : ?>
				<div class="notice notice-warning inline" style="margin-top:12px;">
					<p>
						<strong><?php esc_html_e( 'Queue paused.', 'perflocale' ); ?></strong>
						<?php
						printf(
							/* translators: %s is the URL of the Performance settings tab. */
							wp_kses_post( __( 'Workers are re-queueing jobs every 5 minutes instead of running them. <a href="%s">Unpause in Settings → Performance</a> to resume.', 'perflocale' ) ),
							esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=performance' ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No background jobs queued or running right now.', 'perflocale' ); ?></p>
				</div>
			<?php else : ?>
				<div class="perflocale-table-responsive">
				<table class="wp-list-table widefat fixed striped perflocale-jobs-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Background jobs and their status.', 'perflocale' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Type', 'perflocale' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'perflocale' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Progress', 'perflocale' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Updated', 'perflocale' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'perflocale' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jobs as $job_id => $row ) : ?>
							<?php $this->render_row( (string) $job_id, $row, $results[ (string) $job_id ] ?? [] ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>

			<?php if ( $as_url !== '' ) : ?>
				<p style="margin-top:1em;">
					<?php
					printf(
						/* translators: %s is the URL of the Action Scheduler admin page. */
						wp_kses_post( __( 'Full action history is available at <a href="%s">Tools → Scheduled Actions</a> (filtered to the <code>perflocale</code> group).', 'perflocale' ) ),
						esc_url( $as_url )
					);
					?>
				</p>
			<?php endif; ?>

			<?php KeptDraftsPanel::render(); ?>

			<?php $this->render_scheduled_tasks_panel(); ?>
		</div>

		<?php
		// The page's styles and refresh script come from enqueue_assets() on `admin_enqueue_scripts`.
	}

	/**
	 * Whether a task row shows: its feature is on, or an event for it is still scheduled or running (a leftover shows).
	 *
	 * @param bool                                 $feature_on Feature state; core tasks pass true.
	 * @param array{next: int|null, untimed: bool} $detail     BackgroundEvents::next_run_detail().
	 * @return bool
	 */
	private static function row_shown( bool $feature_on, array $detail ): bool {
		return $feature_on || null !== $detail['next'] || $detail['untimed'];
	}

	/**
	 * Whether automatic exchange-rate sync is on: WooCommerce loaded, its add-on active and the setting enabled.
	 *
	 * @return bool
	 */
	private static function fx_sync_on(): bool {
		if ( ! class_exists( 'WooCommerce' ) || ! \PerfLocale\Helper::get_instance()->is_addon_active( 'woocommerce' ) ) {
			return false;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		return $plugin->has( 'settings' ) && (bool) $plugin->settings()->get( 'wc_exchange_rate_auto', false );
	}

	/**
	 * Render the read-only "Scheduled tasks" panel: the recurring work outside the Jobs queue (rate sync, lock cleanup,
	 * jobs GC) with next run, engine and status.
	 *
	 * @return void
	 */
	private function render_scheduled_tasks_panel(): void {
		// Each task has its interval: a next run over 1.5 x that in the past is overdue (WP-Cron drift on quiet sites).
		// The per-blog tasks pass their [blog_id] args, which Action Scheduler matches exactly.
		$blog_arg = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];

		$tasks = [
			[
				'hook'             => \PerfLocale\WooCommerce\ExchangeRateSync::CRON_HOOK,
				'args'             => [],
				'label'            => __( 'Exchange-rate sync', 'perflocale' ),
				'description'      => __( 'WooCommerce multi-currency rate refresh.', 'perflocale' ),
				'interval_seconds' => DAY_IN_SECONDS,
				'feature_on'       => self::fx_sync_on(),
			],
			[
				'hook'             => \PerfLocale\Concurrency\Lock::CLEANUP_HOOK,
				'args'             => [],
				'label'            => __( 'Concurrency-lock cleanup', 'perflocale' ),
				'description'      => __( 'Reaps expired lock rows from wp_options.', 'perflocale' ),
				'interval_seconds' => DAY_IN_SECONDS,
			],
			[
				'hook'             => 'perflocale_jobs_gc',
				'args'             => $blog_arg,
				'label'            => __( 'Background-jobs GC', 'perflocale' ),
				'description'      => __( 'Removes per-job options for jobs older than 24h.', 'perflocale' ),
				'interval_seconds' => DAY_IN_SECONDS,
			],
			[
				'hook'             => 'perflocale_jobs_watchdog',
				'args'             => $blog_arg,
				'label'            => __( 'Background-jobs watchdog', 'perflocale' ),
				'description'      => __( 'Hourly stuck-job sweep - force-fails workers whose status hasn\'t moved for hours.', 'perflocale' ),
				'interval_seconds' => HOUR_IN_SECONDS,
			],
		];

		$rows         = [];
		$overdue_seen = false;

		foreach ( $tasks as $task ) {
			// A running recurring Action Scheduler task has no next timestamp; `untimed` from the same probe marks it running.
			// These hooks are only ever scheduled through enqueue_recurring(), never async, so `untimed` means running here.
			$detail = BackgroundEvents::next_run_detail( $task['hook'], $task['args'] );

			if ( ! self::row_shown( $task['feature_on'] ?? true, $detail ) ) {
				continue;
			}

			$next     = $detail['next'];
			$overdue  = false;
			$drift_by = 0;

			// Overdue: the scheduled time passed by more than half the interval (WP-Cron skipped a tick).
			if ( $next !== null
				&& $next < time()
				&& ( time() - $next ) > (int) ( $task['interval_seconds'] / 2 ) ) {
				$overdue      = true;
				$drift_by     = time() - $next;
				$overdue_seen = true;
			}

			$rows[] = [
				'label'       => $task['label'],
				'description' => $task['description'],
				'hook'        => $task['hook'],
				'next_run'    => $next,
				'in_progress' => null === $next && $detail['untimed'],
				'overdue'     => $overdue,
				'drift_by'    => $drift_by,
				'last_run'    => BackgroundEvents::last_run( $task['hook'] ),
			];
		}

		// Drift warning on WP-Cron only: Action Scheduler persists and catches up, so it would be a false alarm there.
		$current_engine = JobRunnerFactory::pick()->get_engine_name();
		?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Scheduled tasks', 'perflocale' ); ?></h2>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Recurring schedulers that run outside the Jobs queue. Routed through Action Scheduler when loaded, WP-Cron otherwise.', 'perflocale' ); ?>
		</p>

		<?php
		// DISABLE_WP_CRON on the WP-Cron engine: events wait until something calls wp-cron.php externally (Action
		// Scheduler runs its own loop), so warn only in that case.
		$disable_wp_cron_active = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' );
		?>

		<?php if ( $disable_wp_cron_active && $current_engine === 'wp_cron' ) : ?>
			<div class="notice notice-error inline" style="margin:8px 0 12px;">
				<p>
					<strong><?php esc_html_e( 'WP-Cron is disabled on this site.', 'perflocale' ); ?></strong>
					<?php esc_html_e( 'The `DISABLE_WP_CRON` constant is set, so scheduled events will not fire unless an external trigger hits wp-cron.php (typically a server cron job that pings the URL every few minutes).', 'perflocale' ); ?>
					<br>
					<?php
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						printf(
							/* translators: %s is the URL of the Settings → Performance tab. */
							wp_kses_post( __( 'Action Scheduler is loaded - switch the engine to "Action Scheduler (auto)" in <a href="%s">Settings → Performance</a> to bypass WP-Cron entirely.', 'perflocale' ) ),
							esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=performance' ) )
						);
					} else {
						esc_html_e( 'Without an external cron trigger, the recurring tasks below will stop running.', 'perflocale' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $overdue_seen && $current_engine === 'wp_cron' ) : ?>
			<div class="notice notice-warning inline" style="margin:8px 0 12px;">
				<p>
					<strong><?php esc_html_e( 'WP-Cron drift detected.', 'perflocale' ); ?></strong>
					<?php
					echo esc_html__(
						'One or more scheduled tasks are overdue. WP-Cron only fires when traffic hits the site - on low-traffic stores, daily events can be delayed by hours or days.',
						'perflocale'
					);
					?>
					<br>
					<?php
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						printf(
							/* translators: %s is the URL of the Settings → Performance tab. */
							wp_kses_post( __( 'Action Scheduler is loaded - switch the engine in <a href="%s">Settings → Performance</a> for catch-up behaviour.', 'perflocale' ) ),
							esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=performance' ) )
						);
					} else {
						echo wp_kses_post( __( 'Consider installing Action Scheduler (or a plugin that bundles it, like WooCommerce) - it persists schedules to the database and catches up on missed runs.', 'perflocale' ) );
					}
					?>
				</p>
			</div>
		<?php endif; ?>
		<div class="perflocale-table-responsive">
		<table class="wp-list-table widefat fixed striped perflocale-scheduled-tasks-table">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Scheduled Tasks', 'perflocale' ); ?></caption>
			<thead>
				<tr>
					<th style="width:22%;"><?php esc_html_e( 'Task', 'perflocale' ); ?></th>
					<th><?php esc_html_e( 'Description', 'perflocale' ); ?></th>
					<th style="width:16%;"><?php esc_html_e( 'Last run', 'perflocale' ); ?></th>
					<th style="width:16%;"><?php esc_html_e( 'Next run', 'perflocale' ); ?></th>
					<th style="width:22%;"><?php esc_html_e( 'Hook', 'perflocale' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr <?php echo $row['overdue'] ? 'style="background:#fff7e6;"' : ''; ?>>
						<td>
							<strong><?php echo esc_html( $row['label'] ); ?></strong>
							<?php if ( $row['overdue'] ) : ?>
								<br>
								<span style="display:inline-block;background:#f6b800;color:#3c434a;font-size:10px;font-weight:600;padding:1px 6px;border-radius:9px;margin-top:2px;letter-spacing:0.3px;">
									<?php esc_html_e( 'OVERDUE', 'perflocale' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['description'] ); ?></td>
						<td>
							<?php if ( $row['last_run'] === null || $row['last_run']['ran_at'] <= 0 ) : ?>
								<span style="color:#646970;"><?php esc_html_e( 'Never', 'perflocale' ); ?></span>
							<?php else : ?>
								<?php
								$ago = max( 0, time() - $row['last_run']['ran_at'] );
								if ( $ago < 60 ) {
									$ago_human = sprintf(
										/* translators: %d: seconds ago */
										_n( '%d second ago', '%d seconds ago', $ago, 'perflocale' ),
										$ago
									);
								} elseif ( $ago < HOUR_IN_SECONDS ) {
									$ago_human = sprintf(
										/* translators: %d: minutes ago */
										_n( '%d minute ago', '%d minutes ago', (int) round( $ago / MINUTE_IN_SECONDS ), 'perflocale' ),
										(int) round( $ago / MINUTE_IN_SECONDS )
									);
								} elseif ( $ago < DAY_IN_SECONDS ) {
									$ago_human = sprintf(
										/* translators: %d: hours ago */
										_n( '%d hour ago', '%d hours ago', (int) round( $ago / HOUR_IN_SECONDS ), 'perflocale' ),
										(int) round( $ago / HOUR_IN_SECONDS )
									);
								} else {
									$ago_human = sprintf(
										/* translators: %d: days ago */
										_n( '%d day ago', '%d days ago', (int) round( $ago / DAY_IN_SECONDS ), 'perflocale' ),
										(int) round( $ago / DAY_IN_SECONDS )
									);
								}

								$duration_ms = (int) $row['last_run']['duration_ms'];
								?>
								<?php echo esc_html( $ago_human ); ?>
								<?php if ( $duration_ms > 0 ) : ?>
									<br>
									<small style="color:#646970;">
										<?php
										// Milliseconds below one second, else seconds.
										if ( $duration_ms < 1000 ) {
											printf(
												/* translators: %d: number of milliseconds */
												esc_html__( 'took %d ms', 'perflocale' ),
												(int) $duration_ms
											);
										} else {
											printf(
												/* translators: %s: number of seconds with one decimal, e.g. "1.5" */
												esc_html__( 'took %s s', 'perflocale' ),
												esc_html( number_format_i18n( $duration_ms / 1000, 1 ) )
											);
										}
										?>
									</small>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $row['next_run'] === null && $row['in_progress'] ) : ?>
								<span style="color:#646970;"><?php esc_html_e( 'Running', 'perflocale' ); ?></span>
							<?php elseif ( $row['next_run'] === null ) : ?>
								<span style="color:#646970;"><?php esc_html_e( 'Not scheduled', 'perflocale' ); ?></span>
							<?php elseif ( $row['overdue'] ) : ?>
								<?php
								$drift = $row['drift_by'];
								if ( $drift < HOUR_IN_SECONDS ) {
									$drift_human = sprintf(
										/* translators: %d: minutes overdue */
										_n( '%d minute overdue', '%d minutes overdue', (int) round( $drift / MINUTE_IN_SECONDS ), 'perflocale' ),
										(int) round( $drift / MINUTE_IN_SECONDS )
									);
								} elseif ( $drift < DAY_IN_SECONDS ) {
									$drift_human = sprintf(
										/* translators: %d: hours overdue */
										_n( '%d hour overdue', '%d hours overdue', (int) round( $drift / HOUR_IN_SECONDS ), 'perflocale' ),
										(int) round( $drift / HOUR_IN_SECONDS )
									);
								} else {
									$drift_human = sprintf(
										/* translators: %d: days overdue */
										_n( '%d day overdue', '%d days overdue', (int) round( $drift / DAY_IN_SECONDS ), 'perflocale' ),
										(int) round( $drift / DAY_IN_SECONDS )
									);
								}
								?>
								<span style="color:#a35a00;font-weight:600;"><?php echo esc_html( $drift_human ); ?></span>
								<br>
								<small style="color:#a35a00;"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['next_run'] ) ); ?></small>
							<?php else : ?>
								<?php
								$delta = max( 0, $row['next_run'] - time() );
								if ( $delta < 60 ) {
									$human = sprintf(
										/* translators: %d: number of seconds until next run */
										_n( 'in %d second', 'in %d seconds', $delta, 'perflocale' ),
										$delta
									);
								} elseif ( $delta < HOUR_IN_SECONDS ) {
									$human = sprintf(
										/* translators: %d: number of minutes until next run */
										_n( 'in %d minute', 'in %d minutes', (int) round( $delta / MINUTE_IN_SECONDS ), 'perflocale' ),
										(int) round( $delta / MINUTE_IN_SECONDS )
									);
								} elseif ( $delta < DAY_IN_SECONDS ) {
									$human = sprintf(
										/* translators: %d: number of hours until next run */
										_n( 'in %d hour', 'in %d hours', (int) round( $delta / HOUR_IN_SECONDS ), 'perflocale' ),
										(int) round( $delta / HOUR_IN_SECONDS )
									);
								} else {
									$human = sprintf(
										/* translators: %d: number of days until next run */
										_n( 'in %d day', 'in %d days', (int) round( $delta / DAY_IN_SECONDS ), 'perflocale' ),
										(int) round( $delta / DAY_IN_SECONDS )
									);
								}
								?>
								<?php echo esc_html( $human ); ?>
								<br>
								<small style="color:#646970;"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['next_run'] ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><code style="font-size:11px;"><?php echo esc_html( $row['hook'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * The lines from a finished row's result under its status: a Polylang job's policy line and left-out items, and the
	 * first error. Prints nothing without any.
	 *
	 * @param string        $policy_line Language conflict policy line, or ''.
	 * @param array<string> $attention   Lines of items left out.
	 * @param string        $first_error The result's first error, or ''.
	 * @return void
	 */
	private static function render_result_notes( string $policy_line, array $attention, string $first_error ): void {
		self::render_migration_notes( $policy_line, $attention );
		self::render_first_error( $first_error );
	}

	/**
	 * A Polylang job's policy line and left-out items, and an import's slug clashes; nothing otherwise.
	 *
	 * @param string        $policy_line Language conflict policy line, or ''.
	 * @param array<string> $attention   Lines of items left out.
	 * @return void
	 */
	private static function render_migration_notes( string $policy_line, array $attention ): void {
		if ( $policy_line !== '' ) {
			echo '<div class="perflocale-jobs-warning">' . esc_html( $policy_line ) . '</div>';
		}

		if ( $attention !== [] ) {
			echo '<div class="perflocale-jobs-warning"><strong>' . esc_html__( 'Needs attention:', 'perflocale' ) . '</strong> ' . esc_html( implode( ' ', $attention ) ) . '</div>';
		}
	}

	/**
	 * The message of a queued-job code a redirect carries; '' for any other code.
	 *
	 * @param string $code The perflocale_notice code.
	 * @return string
	 */
	private static function queued_message( string $code ): string {
		switch ( $code ) {
			case 'export_queued':
				return __( 'Data export queued. The download link will appear under PerfLocale → Jobs when ready.', 'perflocale' );
			case 'import_queued':
				return __( 'Data import queued. Track progress under PerfLocale → Jobs.', 'perflocale' );
			case 'polylang_queued':
				return __( 'Polylang migration queued. Track progress under PerfLocale → Jobs.', 'perflocale' );
			case 'translatepress_queued':
				return __( 'TranslatePress migration queued. Track progress under PerfLocale → Jobs.', 'perflocale' );
			case 'bulk_mt_queued':
				return __( 'Machine translation queued. Track progress below.', 'perflocale' );
			default:
				return '';
		}
	}

	/**
	 * Whether a row needs its job's result: the kept count, the first error, or a finished Content ReSync run's note.
	 *
	 * @param mixed $status Job status.
	 * @param mixed $type   Job type slug.
	 * @return bool
	 */
	private static function shows_result( $status, $type ): bool {
		return self::shows_kept( $status, $type ) || self::shows_first_error( $status, $type ) || ( 'complete' === $status && ( 'content_resync' === $type || in_array( $type, self::CLASH_RESULT_TYPES, true ) ) );
	}

	/**
	 * Whether a row shows its result's first error: a finished or canceled job of a FIRST_ERROR_RESULT_TYPES type (a
	 * failed row shows its own error).
	 *
	 * @param mixed $status Job status.
	 * @param mixed $type   Job type slug.
	 * @return bool
	 */
	private static function shows_first_error( $status, $type ): bool {
		return in_array( $status, [ 'complete', 'canceled' ], true ) && in_array( $type, self::FIRST_ERROR_RESULT_TYPES, true );
	}

	/**
	 * The first error under the status, shortened (title and details hold all of it); nothing when there is none.
	 *
	 * @param string $first_error The result's first error, or ''.
	 * @return void
	 */
	private static function render_first_error( string $first_error ): void {
		if ( $first_error === '' ) {
			return;
		}

		$shown = mb_strlen( $first_error ) > self::FIRST_ERROR_SHOWN
			? mb_substr( $first_error, 0, self::FIRST_ERROR_SHOWN - 3 ) . '…'
			: $first_error;

		echo '<div class="perflocale-jobs-first-error" title="' . esc_attr( $first_error ) . '"><strong>' . esc_html__( 'First error:', 'perflocale' ) . '</strong> ' . esc_html( $shown ) . '</div>';
	}

	/**
	 * Whether a row shows how many created translations kept the source language (finished, KEPT_RESULT_TYPES).
	 *
	 * @param mixed $status Job status.
	 * @param mixed $type   Job type slug.
	 * @return bool
	 */
	private static function shows_kept( $status, $type ): bool {
		return 'complete' === $status && ( in_array( $type, self::KEPT_RESULT_TYPES, true ) || in_array( $type, self::COUNT_RESULT_TYPES, true ) || ( is_string( $type ) && isset( self::ATTENTION_RESULT_TYPES[ $type ] ) ) );
	}

	/**
	 * Render a single job row.
	 *
	 * @param string                   $job_id
	 * @param array<string, mixed>     $row    Active-index summary row.
	 * @param array<int|string, mixed> $result The job's decoded result when shows_result() holds for the row, else [].
	 * @return void
	 */
	private function render_row( string $job_id, array $row, array $result = [] ): void {
		$status     = (string) ( $row['status'] ?? 'unknown' );
		$type       = (string) ( $row['type'] ?? '' );
		$progress   = (int) ( $row['progress'] ?? 0 );
		$progress   = max( 0, min( 100, $progress ) );
		$updated_at = (int) ( $row['updated_at'] ?? 0 );
		$stage_text = $status === 'running'
			? self::stage_text( (string) ( $row['stage'] ?? '' ), (int) ( $row['processed'] ?? 0 ), (int) ( $row['total'] ?? 0 ) )
			: '';

		$status_labels = [
			'queued'   => __( 'Queued', 'perflocale' ),
			'running'  => __( 'Running', 'perflocale' ),
			'complete' => __( 'Complete', 'perflocale' ),
			'failed'   => __( 'Failed', 'perflocale' ),
			'canceled' => __( 'Canceled', 'perflocale' ),
		];
		$status_label  = $status_labels[ $status ] ?? $status;

		// A failed row shows the summary's error inline, for triage without REST or WP-CLI.
		$error_full  = '';
		$error_short = '';
		if ( $status === 'failed' ) {
			$err = (string) ( $row['error'] ?? '' );
			if ( $err !== '' ) {
				$error_full  = $err;
				$error_short = mb_strlen( $err ) > 140
					? mb_substr( $err, 0, 137 ) . '…'
					: $err;
			}
		}

		// Finished machine-translation jobs report rows kept in the source language; only those types read the result.
		$kept          = 0;
		$first_warning = '';
		$redo_notes    = [];
		$redo_more     = 0;
		if ( self::shows_kept( $status, $type ) ) {
			$kept          = max( 0, (int) ( $result['kept'] ?? 0 ) );
			$first_warning = trim( (string) ( $result['first_warning'] ?? '' ) );

			// Every pair an earlier stopped run left gets its own line; the first warning is not repeated.
			foreach ( is_array( $result['redo_notes'] ?? null ) ? $result['redo_notes'] : [] as $note ) {
				if ( is_string( $note ) && '' !== trim( $note ) && trim( $note ) !== $first_warning ) {
					$redo_notes[] = trim( $note );
				}
			}

			$redo_more = is_numeric( $result['redo_notes_more'] ?? null ) ? max( 0, (int) $result['redo_notes_more'] ) : 0;
		}

		// A finished or canceled job whose items failed names the first error.
		$first_error = self::shows_first_error( $status, $type ) ? ( $result['first_error'] ?? '' ) : '';
		$first_error = is_string( $first_error ) ? trim( $first_error ) : '';

		// A finished migration that left source items out lists them.
		$attention = 'complete' === $status && isset( self::ATTENTION_RESULT_TYPES[ $type ] )
			? \PerfLocale\Migration\MigrationRunner::skipped_lines( self::ATTENTION_RESULT_TYPES[ $type ], array_filter( $result, 'is_string', ARRAY_FILTER_USE_KEY ) )
			: [];

		// An import's root posts a language prefix now takes.
		if ( 'complete' === $status && in_array( $type, self::CLASH_RESULT_TYPES, true ) ) {
			$attention = array_merge( $attention, \PerfLocale\Migration\MigrationRunner::clash_lines( $result ) );
		}

		// A Polylang job under a non-default conflict policy says so: the policy it ran under, or the one it will read.
		$policy_line = '';

		if ( 'polylang_migration' === $type ) {
			$ran         = is_array( $result['conflict_policy'] ?? null ) ? $result['conflict_policy'] : null;
			$policy_line = \PerfLocale\Migration\PolylangImporter::policy_line( null !== $ran && JobState::is_terminal( (string) $status ) ? $ran : \PerfLocale\Migration\PolylangImporter::conflict_policies() );
		}

		// A finished Content ReSync run that stopped early or left pairs says why (a fixed stop in the viewer's language);
		// pairs refused for the PHP memory limit are listed.
		$resync_note   = '';
		$resync_memory = [];
		if ( $status === 'complete' && $type === 'content_resync' && \PerfLocale\Util\ResyncSwitch::on() ) {
			$resync        = $result;
			$stop_text     = \PerfLocale\Admin\ResyncNotices::stop_text( \PerfLocale\Util\Scalar::str( $resync['stopped'] ?? '' ) );
			$resync_note   = ( '' !== \PerfLocale\Util\Scalar::str( $resync['stopped'] ?? '' ) || \PerfLocale\Util\Scalar::int( $resync['not_processed'] ?? 0 ) > 0 )
				? trim( '' !== $stop_text ? $stop_text : \PerfLocale\Util\Scalar::str( $resync['first_error'] ?? '' ) )
				: '';
			$resync_memory = \PerfLocale\Admin\ResyncNotices::memory_refusals( is_array( $resync['items'] ?? null ) ? $resync['items'] : [] );
		}

		?>
		<tr data-perflocale-job-row="<?php echo esc_attr( $job_id ); ?>">
			<td><code><?php echo esc_html( $type ); ?></code></td>
			<td data-perflocale-cell="status">
				<span class="perflocale-jobs-status <?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
				<?php if ( $error_short !== '' ) : ?>
					<div class="perflocale-jobs-error"
						title="<?php echo esc_attr( $error_full ); ?>"
						style="margin-top:4px; font-size:11px; color:#b32d2e; max-width:380px; white-space:normal; line-height:1.4;">
						<?php echo esc_html( $error_short ); ?>
					</div>
				<?php endif; if ( 'complete' === $status && in_array( $type, [ 'template_refs_backfill', 'template_refs_fanout' ], true ) ) : ?>
					<div class="perflocale-jobs-stage">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: templates and template parts read, 2: of those, the ones updated, 3: menu and pattern references pointed at a translation. */
								__( 'Templates and template parts read: %1$d; updated: %2$d; menu and pattern references pointed at their translation: %3$d.', 'perflocale' ),
								is_numeric( $result['read'] ?? null ) ? max( 0, (int) $result['read'] ) : 0,
								is_numeric( $result['updated'] ?? null ) ? max( 0, (int) $result['updated'] ) : 0,
								is_numeric( $result['references'] ?? null ) ? max( 0, (int) $result['references'] ) : 0
							)
						);
						?>
					</div>
				<?php endif; if ( 'complete' === $status && 'sqlite_group_repair' === $type ) : ?>
					<div class="perflocale-jobs-stage">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: string groups created, 2: string links moved into their string's group, 3: links written for translated strings that had none, 4: unused string groups deleted, 5: links left where they were. */
								__( 'String groups created: %1$d; links moved: %2$d; links written: %3$d; empty groups deleted: %4$d; links left where they were: %5$d.', 'perflocale' ),
								is_numeric( $result['groups_created'] ?? null ) ? max( 0, (int) $result['groups_created'] ) : 0,
								is_numeric( $result['links_moved'] ?? null ) ? max( 0, (int) $result['links_moved'] ) : 0,
								is_numeric( $result['links_written'] ?? null ) ? max( 0, (int) $result['links_written'] ) : 0,
								is_numeric( $result['groups_deleted'] ?? null ) ? max( 0, (int) $result['groups_deleted'] ) : 0,
								is_numeric( $result['links_left'] ?? null ) ? max( 0, (int) $result['links_left'] ) : 0
							)
						);
						?>
					</div>
				<?php endif; ?>
				<?php self::render_result_notes( $policy_line, $attention, $first_error ); if ( $resync_note !== '' ) : ?>
					<div class="perflocale-jobs-warning"><?php echo esc_html( $resync_note ); ?></div>
				<?php endif; if ( [] !== $resync_memory ) : ?>
					<div class="perflocale-jobs-warning">
						<?php echo esc_html( \PerfLocale\Admin\ResyncNotices::memory_note( $resync_memory ) ); ?>
						<ul>
							<?php foreach ( $resync_memory as $refusal ) : ?>
								<li><?php echo wp_kses( \PerfLocale\Admin\ResyncNotices::memory_line( $refusal, true ), [ 'a' => [ 'href' => [] ] ] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; if ( $kept > 0 || $first_warning !== '' || [] !== $redo_notes || $redo_more > 0 ) : ?>
					<div class="perflocale-jobs-warning">
						<?php
						if ( $kept > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: %d: number of created translations whose content stayed in the source language. */
									_n( '%d created translation kept the source-language content.', '%d created translations kept the source-language content.', $kept, 'perflocale' ),
									$kept
								)
							);
						}
						?>
						<?php if ( $first_warning !== '' ) : ?>
							<strong><?php esc_html_e( 'First warning:', 'perflocale' ); ?></strong>
							<?php echo esc_html( $first_warning ); ?>
						<?php endif; if ( [] !== $redo_notes ) : ?>
							<ul>
								<?php foreach ( $redo_notes as $note ) : ?>
									<li><?php echo esc_html( $note ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; if ( $redo_more > 0 ) : ?>
							<?php
							/* translators: %d: number of further warnings of the same kind that are not listed. */
							echo esc_html( sprintf( _n( '%d more is not listed.', '%d more are not listed.', $redo_more, 'perflocale' ), $redo_more ) );
							?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</td>
			<td data-perflocale-cell="progress">
				<?php echo esc_html( (string) $progress ); ?>%
				<div class="perflocale-jobs-progress" role="progressbar"
					aria-valuenow="<?php echo esc_attr( (string) $progress ); ?>"
					aria-valuemin="0" aria-valuemax="100"
					<?php if ( $stage_text !== '' ) : ?>
						aria-valuetext="<?php echo esc_attr( sprintf( /* translators: 1: overall progress in percent, 2: the current stage and its progress, e.g. "Posts: 120 of 500" */ __( '%1$s%% (%2$s)', 'perflocale' ), number_format_i18n( $progress ), $stage_text ) ); ?>"
					<?php endif; ?>
					aria-label="<?php esc_attr_e( 'Job progress', 'perflocale' ); ?>">
					<span style="width: <?php echo esc_attr( (string) $progress ); ?>%"></span>
				</div>
				<div class="perflocale-jobs-stage" data-perflocale-cell="stage"><?php echo esc_html( $stage_text ); ?></div>
			</td>
			<td data-perflocale-cell="updated">
				<?php
				echo esc_html(
					$updated_at > 0
						? sprintf(
							/* translators: %s: human-readable time span e.g. "3 days". */
							__( '%s ago', 'perflocale' ),
							human_time_diff( $updated_at )
						)
						: '-'
				);
				?>
			</td>
			<td>
				<?php $this->render_actions( $job_id, $status, $type ); ?>
			</td>
		</tr>
		<tr class="perflocale-jobs-detail-row" data-perflocale-job-detail="<?php echo esc_attr( $job_id ); ?>" hidden>
			<td colspan="5" class="perflocale-jobs-detail-cell">
				<div class="perflocale-jobs-detail-loading">
					<?php esc_html_e( 'Loading details…', 'perflocale' ); ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * The text of a running job's stage, e.g. "Posts: 120 of 500".
	 *
	 * @param string $stage     Stage key ('' when the job reports none).
	 * @param int    $processed Items of the stage done.
	 * @param int    $total     Items in the stage; 0 while not known.
	 * @return string '' without a stage.
	 */
	private static function stage_text( string $stage, int $processed, int $total ): string {
		if ( $stage === '' ) {
			return '';
		}

		$label = MigrationRunner::stage_label( $stage );

		if ( $total <= 0 ) {
			return $label;
		}

		return sprintf(
			self::stage_progress_format(),
			$label,
			number_format_i18n( max( 0, $processed ) ),
			number_format_i18n( $total )
		);
	}

	/**
	 * The format of a stage's progress: stage label, items done, items in total.
	 *
	 * @return string
	 */
	private static function stage_progress_format(): string {
		/* translators: 1: stage of a background job, e.g. "Posts", 2: items of the stage done, 3: items in the stage */
		return __( '%1$s: %2$s of %3$s', 'perflocale' );
	}

	/**
	 * Render a row's action buttons by status.
	 *
	 * @param string $job_id
	 * @param string $status
	 * @param string $type   Job type slug (e.g. 'data_export').
	 * @return void
	 */
	private function render_actions( string $job_id, string $status, string $type ): void {
		// Escaped at the echo below, inline for the static analysers.
		$rest_url_base = rest_url( 'perflocale/v1/jobs/' . $job_id );
		$nonce         = wp_create_nonce( 'wp_rest' );

		?>
		<button type="button" class="button button-small" data-perflocale-jobs-action="details" data-job="<?php echo esc_attr( $job_id ); ?>" data-base="<?php echo esc_url( $rest_url_base ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" aria-expanded="false"><?php esc_html_e( 'Details', 'perflocale' ); ?></button>
		<?php

		if ( in_array( $status, [ 'queued', 'running' ], true ) ) {
			?>
			<button type="button" class="button button-small" data-perflocale-jobs-action="cancel" data-job="<?php echo esc_attr( $job_id ); ?>" data-base="<?php echo esc_url( $rest_url_base ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Cancel', 'perflocale' ); ?></button>
			<?php
		}

		if ( in_array( $status, [ 'failed', 'canceled' ], true ) ) {
			?>
			<button type="button" class="button button-small" data-perflocale-jobs-action="retry" data-job="<?php echo esc_attr( $job_id ); ?>" data-base="<?php echo esc_url( $rest_url_base ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Retry', 'perflocale' ); ?></button>
			<?php
		}

		// A finished data_export's one-use download link; AdminController::process_export_download() streams and checks it.
		if ( $status === 'complete' && $type === 'data_export' ) {
			$download_url = wp_nonce_url(
				admin_url( 'admin.php?page=perflocale-jobs&perflocale_export_download=' . rawurlencode( $job_id ) ),
				'perflocale_export_download_' . $job_id
			);
			?>
			<a class="button button-small button-primary" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'perflocale' ); ?></a>
			<?php
		}

		if ( in_array( $status, [ 'complete', 'failed', 'canceled' ], true ) ) {
			?>
			<button type="button" class="button button-small button-link-delete" data-perflocale-jobs-action="delete" data-job="<?php echo esc_attr( $job_id ); ?>" data-base="<?php echo esc_url( $rest_url_base ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Delete', 'perflocale' ); ?></button>
			<?php
		}
		?>
		<?php
		// The buttons' click handler is in assets/js/jobs.js (enqueue_assets()).
	}
}
