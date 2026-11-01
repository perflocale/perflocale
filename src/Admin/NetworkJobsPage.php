<?php
/**
 * Network admin Jobs overview.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The network admin PerfLocale page (multisite), loaded only when it renders.
 *
 * @internal
 */
final class NetworkJobsPage {

	/**
	 * Render the network Jobs overview: per site the queued, running and failed counts and the recurring runs
	 * (BackgroundEvents::record_run()), linked to its Jobs page; "unknown", never 0, without a readable table. This
	 * network's first 100 sites.
	 *
	 * @return void
	 */
	public function render_network_jobs(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$limit = 100;

		// `network_id` is required: WP_Site_Query's default 0 adds no site_id predicate, so a multi-network install would
		// list other networks' sites.
		$sites = get_sites(
			[
				'network_id' => get_current_network_id(),
				'number'     => $limit,
				'orderby'    => 'id',
				'order'      => 'ASC',
			]
		);

		// Only when the cap was hit: one COUNT, so the notice can say "100 of N".
		$total_sites = count( $sites ) >= $limit
			? (int) get_sites(
				[
					'network_id' => get_current_network_id(),
					'count'      => true,
				]
			)
			: count( $sites );

		$rows = [];

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;

			switch_to_blog( $blog_id );

			try {
				$last_runs    = (array) get_option( \PerfLocale\Background\BackgroundEvents::LAST_RUN_OPTION, [] );
				$engine_label = (string) get_option( 'perflocale_active_engine', '' );

				// No stamped schema version: the plugin never ran there, "unknown" without a catalog query. A stamp does not prove the
				// tables exist, so tables_exist() still asks.
				$db_version = get_option( 'perflocale_db_version', 0 );
				$job_counts = is_numeric( $db_version ) && (int) $db_version > 0 && \PerfLocale\Database\Schema::tables_exist()
					? \PerfLocale\Background\JobState::count_by_status( [ 'queued', 'running', 'failed' ] )
					: null;

				// Null when the table is missing or the read failed: "unknown".
				$pending_count = null === $job_counts ? null : (int) ( $job_counts['queued'] ?? 0 ) + (int) ( $job_counts['running'] ?? 0 );
				$failed_count  = null === $job_counts ? null : (int) ( $job_counts['failed'] ?? 0 );

				$rows[] = [
					'blog_id'       => $blog_id,
					'domain'        => (string) $site->domain . (string) $site->path,
					'admin_url'     => get_admin_url( $blog_id, 'admin.php?page=perflocale-jobs' ),
					'pending_count' => $pending_count,
					'failed_count'  => $failed_count,
					'engine'        => $engine_label,
					'last_lock_run' => (int) ( $last_runs['perflocale_lock_cleanup']['ran_at'] ?? 0 ),
					'last_gc_run'   => (int) ( $last_runs['perflocale_jobs_gc']['ran_at'] ?? 0 ),
				];
			} finally {
				restore_current_blog();
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PerfLocale - Network Jobs Overview', 'perflocale' ); ?></h1>
			<p class="description" style="margin-top:8px;">
				<?php
				printf(
					/* translators: %d is the number of sites listed. */
					esc_html( _n( 'Showing job state across %d site on this network.', 'Showing job state across %d sites on this network.', count( $rows ), 'perflocale' ) ),
					(int) count( $rows )
				);
				?>
				<?php if ( count( $sites ) >= $limit ) : ?>
					<br>
					<em>
						<?php
						printf(
							/* translators: 1: number of sites listed (the cap), 2: total number of sites on this network. */
							esc_html__( 'Capped at the first %1$d of %2$d sites on this network. Use the per-site Jobs page for the rest.', 'perflocale' ),
							(int) $limit,
							(int) $total_sites
						);
						?>
					</em>
				<?php endif; ?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Per-site background-job engine and health status.', 'perflocale' ); ?></caption>
				<thead>
					<tr>
						<th scope="col" style="width:6%;"><?php esc_html_e( 'ID', 'perflocale' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Site', 'perflocale' ); ?></th>
						<th scope="col" style="width:11%;"><?php esc_html_e( 'Active', 'perflocale' ); ?></th>
						<th scope="col" style="width:11%;"><?php esc_html_e( 'Failed', 'perflocale' ); ?></th>
						<th scope="col" style="width:14%;"><?php esc_html_e( 'Engine', 'perflocale' ); ?></th>
						<th scope="col" style="width:14%;"><?php esc_html_e( 'Lock cleanup', 'perflocale' ); ?></th>
						<th scope="col" style="width:14%;"><?php esc_html_e( 'Jobs GC', 'perflocale' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="7" style="text-align:center;color:#646970;padding:1.2em;">
							<?php esc_html_e( 'No sites found.', 'perflocale' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo (int) $row['blog_id']; ?></td>
							<td>
								<a href="<?php echo esc_url( $row['admin_url'] ); ?>">
									<?php echo esc_html( $row['domain'] ); ?>
								</a>
							</td>
							<td>
								<?php if ( null === $row['pending_count'] ) : ?>
									<em title="<?php esc_attr_e( 'The jobs table of this site could not be read.', 'perflocale' ); ?>"><?php esc_html_e( 'unknown', 'perflocale' ); ?></em>
								<?php elseif ( $row['pending_count'] > 0 ) : ?>
									<strong><?php echo (int) $row['pending_count']; ?></strong>
								<?php else : ?>
									<span style="color:#646970;">0</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null === $row['failed_count'] ) : ?>
									<em title="<?php esc_attr_e( 'The jobs table of this site could not be read.', 'perflocale' ); ?>"><?php esc_html_e( 'unknown', 'perflocale' ); ?></em>
								<?php elseif ( $row['failed_count'] > 0 ) : ?>
									<strong style="color:#a35a00;"><?php echo (int) $row['failed_count']; ?></strong>
								<?php else : ?>
									<span style="color:#646970;">0</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['engine'] !== '' ) : ?>
									<code style="font-size:11px;"><?php echo esc_html( $row['engine'] ); ?></code>
								<?php else : ?>
									<span style="color:#646970;">-</span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo $row['last_lock_run'] > 0
									? esc_html( human_time_diff( $row['last_lock_run'], time() ) . ' ' . __( 'ago', 'perflocale' ) )
									: '<span style="color:#646970;">-</span>';
								?>
							</td>
							<td>
								<?php
								echo $row['last_gc_run'] > 0
									? esc_html( human_time_diff( $row['last_gc_run'], time() ) . ' ' . __( 'ago', 'perflocale' ) )
									: '<span style="color:#646970;">-</span>';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
