<?php
/**
 * Background jobs admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Background\JobRunnerFactory;
use PerfLocale\Background\JobState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders *PerfLocale → Jobs*. Lists active + recent jobs from
 * {@see JobState::list_active()} with status, progress, and action
 * buttons (cancel / retry / delete). The page polls the JobsController
 * REST endpoint every 5 seconds while any row is in `running` state so
 * the operator sees live progress without a manual refresh.
 *
 * All mutations go through the REST endpoints (not direct POST to this
 * page) so the permission story is the same for browser users and CLI
 * users (`curl -u admin /wp-json/perflocale/v1/jobs`).
 */
final class JobsPage {

	/**
	 * Enqueue stylesheet + auto-refresh script on the Jobs admin page only.
	 *
	 * Wired from {@see AdminController} on `admin_enqueue_scripts`. The
	 * `$hook` arg is WordPress's per-page hook suffix; we gate on the
	 * canonical `perflocale_page_perflocale-jobs` suffix so the asset
	 * payload doesn't load on every wp-admin screen.
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

		wp_enqueue_script(
			'perflocale-jobs',
			PERFLOCALE_URL . 'assets/js/jobs.js',
			[],
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
				'i18n'        => [
					'pollPaused'    => __( 'Live updates paused after repeated errors — reload the page to resume.', 'perflocale' ),
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
		// list_active_summary() omits args/result/log — the admin table
		// renders inline only the small columns; the inspect-on-click
		// modal calls GET /jobs/{id} which uses JobState::get() for full
		// detail.
		$jobs   = JobState::list_active_summary();
		$engine = JobRunnerFactory::pick()->get_engine_name();
		$as_url = '';

		if ( $engine === 'action_scheduler' && function_exists( 'admin_url' ) ) {
			// Action Scheduler's Tools → Scheduled Actions screen has no
			// `group` URL filter — passing one is silently ignored. Use the
			// `s` (search) param instead, which AS exposes as a real form
			// field and matches against hook + group + args. "perflocale"
			// matches our group name AND our hook prefix, so it narrows the
			// list to plugin-owned actions either way.
			$as_url = admin_url( 'tools.php?page=action-scheduler&s=perflocale' );
		}

		// Surface the "scan queued" confirmation set by AdminController's
		// async-dispatch path. The transient is consumed on first read so
		// a manual page-refresh doesn't replay it.
		$scan_queued_notice = get_transient( 'perflocale_scan_queued_notice_' . get_current_user_id() );
		if ( is_string( $scan_queued_notice ) && $scan_queued_notice !== '' ) {
			delete_transient( 'perflocale_scan_queued_notice_' . get_current_user_id() );
		} else {
			$scan_queued_notice = '';
		}

		?>
		<div class="wrap perflocale-jobs">
			<?php if ( $scan_queued_notice !== '' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $scan_queued_notice ); ?></p>
				</div>
			<?php endif; ?>

			<h1><?php esc_html_e( 'Background Jobs', 'perflocale' ); ?></h1>

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
							<?php $this->render_row( (string) $job_id, $row ); ?>
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

			<?php $this->render_scheduled_tasks_panel(); ?>
		</div>

		<?php
		// Styles + auto-refresh JS for this page are enqueued by
		// JobsPage::enqueue_assets() on `admin_enqueue_scripts`. See the
		// top of this class for the registration.
	}

	/**
	 * Render the read-only "Scheduled tasks" observability panel.
	 *
	 * Lists the recurring schedulers that live outside the Jobs queue
	 * (exchange-rate sync, lock cleanup, jobs GC) so the operator can see
	 * all of the plugin's background work in one place — next run, engine,
	 * status — without needing to dig into Tools → Scheduled Actions or
	 * wp_options['cron'].
	 *
	 * @return void
	 */
	private function render_scheduled_tasks_panel(): void {
		// Each task carries its expected interval so we can detect drift:
		// `interval_seconds` is how long between runs the engine is
		// supposed to fire. When `next_run` is more than 1.5x that into
		// the past, we flag the task as overdue. This is the canonical
		// symptom of WP-Cron drift on low-traffic sites (cron only fires
		// on traffic; daily events can run weekly).
		// jobs_gc + jobs_watchdog are scheduled per-blog with a [blog_id]
		// args tuple under AS so each blog has its own recurring action
		// (the AS table is network-shared). The next_run probe needs to
		// pass the same args or it would miss the schedule entirely on
		// multisite.
		$blog_arg = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];

		$tasks = [
			[
				'hook'             => \PerfLocale\WooCommerce\ExchangeRateSync::CRON_HOOK,
				'args'             => [],
				'label'            => __( 'Exchange-rate sync', 'perflocale' ),
				'description'      => __( 'WooCommerce multi-currency rate refresh.', 'perflocale' ),
				'interval_seconds' => DAY_IN_SECONDS,
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
				'description'      => __( 'Hourly stuck-job sweep — force-fails workers whose status hasn\'t moved for hours.', 'perflocale' ),
				'interval_seconds' => HOUR_IN_SECONDS,
			],
		];

		$rows         = [];
		$overdue_seen = false;

		foreach ( $tasks as $task ) {
			$next     = BackgroundEvents::next_run( $task['hook'], (array) ( $task['args'] ?? [] ) );
			$overdue  = false;
			$drift_by = 0;

			// "Overdue" = scheduled time is in the past by more than half
			// the interval. For a daily event that means >12h past due.
			// Daily events are normally still upcoming (next_run is in
			// the future), so this only fires if WP-Cron skipped a tick.
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
				'overdue'     => $overdue,
				'drift_by'    => $drift_by,
				'last_run'    => BackgroundEvents::last_run( $task['hook'] ),
			];
		}

		// WP-Cron drift detection — only show the warning when the
		// engine is actually WP-Cron. Action Scheduler doesn't drift the
		// same way (it persists and catches up via WP-Heartbeat-fired
		// runner), so the banner would be a false alarm on AS-backed
		// sites.
		$current_engine = JobRunnerFactory::pick()->get_engine_name();
		?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Scheduled tasks', 'perflocale' ); ?></h2>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Recurring schedulers that run outside the Jobs queue. Routed through Action Scheduler when loaded, WP-Cron otherwise.', 'perflocale' ); ?>
		</p>

		<?php
		// DISABLE_WP_CRON detection. When the host (or `wp-config.php`)
		// defines `DISABLE_WP_CRON` and we're scheduling onto WP-Cron,
		// the events sit in `wp_options['cron']` forever until something
		// fires `wp-cron.php` externally (server cron, uptime monitor,
		// SaaS pinger). Action Scheduler isn't affected — it runs on
		// its own claim loop. So only show this banner when:
		// 1. The constant is truthy.
		// 2. The plugin is on the wp_cron engine right now.
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
										// Display duration in ms for sub-second
										// runs, seconds otherwise.
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
							<?php if ( $row['next_run'] === null ) : ?>
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
	 * Render a single job row.
	 *
	 * @param string               $job_id
	 * @param array<string, mixed> $row Active-index summary row.
	 * @return void
	 */
	private function render_row( string $job_id, array $row ): void {
		$status     = (string) ( $row['status'] ?? 'unknown' );
		$type       = (string) ( $row['type'] ?? '' );
		$progress   = (int) ( $row['progress'] ?? 0 );
		$updated_at = (int) ( $row['updated_at'] ?? 0 );

		$status_labels = [
			'queued'   => __( 'Queued', 'perflocale' ),
			'running'  => __( 'Running', 'perflocale' ),
			'complete' => __( 'Complete', 'perflocale' ),
			'failed'   => __( 'Failed', 'perflocale' ),
			'canceled' => __( 'Canceled', 'perflocale' ),
		];
		$status_label  = $status_labels[ $status ] ?? $status;

		// Surface the error message inline on failed rows so the operator can
		// triage without hitting the REST endpoint or wp-cli. The active-index
		// row doesn't carry the error (it'd bloat the autoloaded option), so
		// pull from the per-job option — but only for failed rows so we don't
		// add a query per row on the typical case.
		$error_full  = '';
		$error_short = '';
		if ( $status === 'failed' ) {
			$detail = JobState::get( $job_id );
			$err    = (string) ( $detail['error'] ?? '' );
			if ( $err !== '' ) {
				$error_full  = $err;
				$error_short = mb_strlen( $err ) > 140
					? mb_substr( $err, 0, 137 ) . '…'
					: $err;
			}
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
				<?php endif; ?>
			</td>
			<td data-perflocale-cell="progress">
				<?php echo esc_html( (string) $progress ); ?>%
				<div class="perflocale-jobs-progress" role="progressbar"
					aria-valuenow="<?php echo esc_attr( (string) max( 0, min( 100, $progress ) ) ); ?>"
					aria-valuemin="0" aria-valuemax="100"
					aria-label="<?php esc_attr_e( 'Job progress', 'perflocale' ); ?>">
					<span style="width: <?php echo esc_attr( (string) max( 0, min( 100, $progress ) ) ); ?>%"></span>
				</div>
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
						: '—'
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
	 * Render the action buttons for one row, gated by status.
	 *
	 * @param string $job_id
	 * @param string $status
	 * @param string $type   Job type slug (e.g. 'data_export').
	 * @return void
	 */
	private function render_actions( string $job_id, string $status, string $type ): void {
		// Build once; escape AT the echo point below so static analysers
		// see the escape call inline.
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

		// Single-use download anchor for async data_export results. The actual
		// streaming + auth happens in AdminController::process_export_download
		// on admin_init; this is just a nonce'd link to that endpoint.
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
		// Click handler for these buttons lives in assets/js/jobs.js,
		// enqueued by JobsPage::enqueue_assets().
	}
}
