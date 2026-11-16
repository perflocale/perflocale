<?php
/**
 * Jobs page: drafts kept from stopped runs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Translation\CreationMark;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The unacknowledged CreationMark reports, with Move to trash (re-checked) and Dismiss; loaded on the Jobs page only.
 */
final class KeptDraftsPanel {

	private const ACTION = 'perflocale_kept_draft';

	/** Reports per read, and the listed reports one view gathers before it offers "Show more". */
	private const PER_PAGE = 50;

	/** Most reads per view, so reports the user cannot read never hide later ones and a view stays bounded. */
	private const PAGES = 10;

	/**
	 * Render the panel; nothing when no report is kept.
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list position; read only.
		$start   = isset( $_GET['perflocale_kept_after'] ) && is_string( $_GET['perflocale_kept_after'] ) ? absint( $_GET['perflocale_kept_after'] ) : 0;
		$next    = $start;
		$reports = [];

		// Only the drafts this user may read (a draft's title and editor link are its own).
		for ( $read = 0; $read < self::PAGES && count( $reports ) < self::PER_PAGE && ( 0 === $read || $next > 0 ); $read++ ) {
			$after = $next;

			foreach ( CreationMark::kept( self::PER_PAGE, $after, $next ) as $report ) {
				$report['drafts'] = array_filter( $report['drafts'], static fn( int $draft ): bool => current_user_can( 'read_post', $draft > 0 ? $draft : $report['source'] ), ARRAY_FILTER_USE_KEY );

				if ( [] !== $report['drafts'] ) {
					$reports[] = $report;
				}
			}
		}

		if ( [] === $reports && 0 === $next && 0 === $start ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A result code the handler put in the redirect; only compared to fixed values.
		$result = isset( $_GET['perflocale_kept'] ) && is_string( $_GET['perflocale_kept'] ) ? sanitize_key( wp_unslash( $_GET['perflocale_kept'] ) ) : '';
		$notice = self::result_text( $result );
		?>
		<h2><?php esc_html_e( 'Kept drafts', 'perflocale' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Translation runs that stopped left these unlinked drafts. They are never deleted automatically: move a draft to the trash if you do not need it, or dismiss the entry.', 'perflocale' ); ?></p>
		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Draft', 'perflocale' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Language', 'perflocale' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'perflocale' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'perflocale' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $reports as $report ) : ?>
					<?php foreach ( $report['drafts'] as $draft => $outcome ) : ?>
						<tr>
							<td><?php self::render_draft( $draft ); ?></td>
							<td><?php echo esc_html( $report['lang'] ); ?></td>
							<td><?php echo esc_html( CreationMark::message( $draft, $report['source'], $outcome ) ); ?></td>
							<td>
								<?php
								if ( $draft > 0 && 'trash' !== get_post_status( $draft ) && false !== get_post_status( $draft ) ) {
									self::render_form( 'trash', $report['name'], $draft, __( 'Move to trash', 'perflocale' ) );
								}

								self::render_form( 'dismiss', $report['name'], 0, __( 'Dismiss', 'perflocale' ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $next > 0 || $start > 0 ) : ?>
			<p>
				<?php if ( $start > 0 ) : ?>
					<a class="button button-small" href="<?php echo esc_url( remove_query_arg( [ 'perflocale_kept_after', 'perflocale_kept' ] ) ); ?>"><?php esc_html_e( 'Back to the start', 'perflocale' ); ?></a>
				<?php endif; ?>
				<?php if ( $next > 0 ) : ?>
					<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'perflocale_kept_after', $next, remove_query_arg( 'perflocale_kept' ) ) ); ?>"><?php esc_html_e( 'Show more', 'perflocale' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The draft as a link to its editor where the user may edit it.
	 *
	 * @param int $draft Draft.
	 * @return void
	 */
	private static function render_draft( int $draft ): void {
		$title = $draft > 0 ? get_the_title( $draft ) : '';
		/* translators: %d: post ID. */
		$label = sprintf( __( 'Post %d', 'perflocale' ), $draft ) . ( '' !== $title ? ': ' . $title : '' );
		$edit  = $draft > 0 && current_user_can( 'edit_post', $draft ) ? get_edit_post_link( $draft, 'raw' ) : '';

		if ( is_string( $edit ) && '' !== $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a>';
		} else {
			echo esc_html( 0 === $draft ? __( 'Not checked yet', 'perflocale' ) : $label );
		}
	}

	/**
	 * One action button as a form to admin-post.php.
	 *
	 * @param string $op    'trash' or 'dismiss'.
	 * @param string $name  Report row.
	 * @param int    $draft Draft (trash only).
	 * @param string $label Button text.
	 * @return void
	 */
	private static function render_form( string $op, string $name, int $draft, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="perflocale-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
			<input type="hidden" name="report" value="<?php echo esc_attr( $name ); ?>">
			<input type="hidden" name="draft" value="<?php echo esc_attr( (string) $draft ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<button type="submit" class="button button-small<?php echo 'trash' === $op ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Admin-post handler: Move to trash or Dismiss, then back to the Jobs page with a result code.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'perflocale_translate' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$op     = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$name   = isset( $_POST['report'] ) && is_string( $_POST['report'] ) ? sanitize_text_field( wp_unslash( $_POST['report'] ) ) : '';
		$draft  = isset( $_POST['draft'] ) && is_string( $_POST['draft'] ) ? absint( $_POST['draft'] ) : 0;
		$report = CreationMark::report( $name );

		if ( null === $report ) {
			$result = 'gone';
		} elseif ( 'trash' === $op ) {
			$result = CreationMark::trash_kept( $name, $draft );
		} elseif ( 'dismiss' === $op ) {
			$result = self::dismiss_result( $name, $report );
		} else {
			$result = 'failed';
		}

		wp_safe_redirect( add_query_arg( 'perflocale_kept', $result, admin_url( 'admin.php?page=perflocale-jobs' ) ) );
		exit;
	}

	/**
	 * Dismiss a report after its rights check; the result code.
	 *
	 * @param string                                                        $name   Report row.
	 * @param array{value: string, source: int, drafts: array<int, string>} $report The report.
	 * @return string
	 */
	private static function dismiss_result( string $name, array $report ): string {
		$may = self::may_dismiss( $report );

		if ( null === $may ) {
			return 'unread_dismiss';
		}

		if ( ! $may ) {
			return 'denied_dismiss';
		}

		return CreationMark::dismiss( $name, $report['value'] ) ? 'dismissed' : 'gone';
	}

	/**
	 * Whether the user may dismiss a report: edit rights on each draft it lists (its source while unchecked), managers too.
	 *
	 * A post confirmed deleted is skipped: edit_post on it is refused to everyone, so its report could never go.
	 *
	 * @param array{source: int, drafts: array<int, string>} $report The report.
	 * @return bool|null Null when a post could not be read.
	 */
	private static function may_dismiss( array $report ): ?bool {
		foreach ( array_keys( $report['drafts'] ) as $draft ) {
			$post = $draft > 0 ? $draft : $report['source'];

			// get_post() answers the same for a deleted post and a failed read.
			if ( ! get_post( $post ) instanceof \WP_Post ) {
				$gone = self::post_gone( $post );

				if ( null === $gone ) {
					return null;
				}

				if ( $gone ) {
					continue;
				}
			}

			if ( ! current_user_can( 'edit_post', $post ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the post row is confirmed absent; null when the read failed.
	 *
	 * @param int $post Post.
	 * @return bool|null
	 */
	private static function post_gone( int $post ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cache cannot prove a row absent; COUNT always answers, so "no row" means a failed read.
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID = %d', $wpdb->posts, $post ) );

		return is_numeric( $found ) ? 0 === (int) $found : null;
	}

	/**
	 * The notice for a handler result code; '' for none.
	 *
	 * @param string $result Result code.
	 * @return string
	 */
	private static function result_text( string $result ): string {
		return match ( $result ) {
			'trashed'   => __( 'The draft was moved to the trash.', 'perflocale' ),
			'dismissed' => __( 'The entry was dismissed. The draft itself was not changed.', 'perflocale' ),
			'linked'    => __( 'The draft was not moved to the trash: it is linked as a translation now.', 'perflocale' ),
			'denied'    => __( 'The draft was not moved to the trash: you do not have permission to delete it.', 'perflocale' ),
			'denied_dismiss' => __( 'The entry was not dismissed: you do not have permission to edit its drafts.', 'perflocale' ),
			'unread_dismiss' => __( 'The entry was not dismissed: its drafts could not be checked. Try again in a moment.', 'perflocale' ),
			'gone'      => __( 'That entry is no longer listed.', 'perflocale' ),
			'failed'    => __( 'The draft could not be moved to the trash. Try again, or move it from the Posts screen.', 'perflocale' ),
			default     => '',
		};
	}
}
