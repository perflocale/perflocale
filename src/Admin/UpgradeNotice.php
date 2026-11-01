<?php
/**
 * Upgrade failure notice, Retry now and the Site Health upgrade test.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\UpgradeRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a recorded upgrade failure to managers until the step succeeds, and runs Retry now.
 *
 * Loaded only while a failure is recorded, on the Retry request and on Site Health.
 *
 * @since 1.0.7.5
 * @internal
 */
final class UpgradeNotice {

	/** admin-post action and nonce action of Retry now. */
	public const ACTION = 'perflocale_upgrade_retry';

	/**
	 * Who sees the notice and may retry: the managers who can fix the database.
	 *
	 * @return string
	 */
	private static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Render the notice (admin_notices).
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by handle_retry()'s redirect.
		$busy     = isset( $_GET['perflocale_upgrade'] ) && 'busy' === sanitize_key( wp_unslash( (string) $_GET['perflocale_upgrade'] ) );
		$failures = UpgradeRunner::failures();

		if ( [] === $failures && ! $busy ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>PerfLocale</strong>: ' . esc_html__( 'The database upgrade did not finish.', 'perflocale' ) . '</p><ul>';

		foreach ( $failures as $failure ) {
			echo '<li>' . esc_html( self::line( $failure ) ) . '</li>';
		}

		echo '</ul>';

		if ( $busy ) {
			$wait = max( 60, \PerfLocale\Concurrency\Lock::seconds_remaining( UpgradeRunner::LOCK ) );
			/* translators: %s: time, e.g. "5 mins". */
			echo '<p>' . esc_html( sprintf( __( 'Another request is running the upgrade. Reload this page in %s at most.', 'perflocale' ), human_time_diff( time(), time() + $wait ) ) ) . '</p>';
		}

		echo '<p><a class="button button-primary" href="' . esc_url( self::retry_url() ) . '">' . esc_html__( 'Retry now', 'perflocale' ) . '</a> '
			. esc_html__( 'If it keeps failing, check that the database user may create and alter tables, then retry.', 'perflocale' ) . '</p></div>';
	}

	/**
	 * Add-on error records minus the schema and migrate ones of add-on steps this notice reports, rendering it when no
	 * runner hooked it on this request, so each failure shows once.
	 *
	 * @param array<string, array<string, mixed>> $records AddonMigrationErrors::get_all().
	 * @return array<string, array<string, mixed>>
	 */
	public static function without_addon_steps( array $records ): array {
		if ( ! current_user_can( self::capability() ) ) {
			return $records;
		}

		$failures = UpgradeRunner::failures();
		$taken    = false;

		foreach ( $records as $key => $record ) {
			$addon_id = $record['addon_id'] ?? '';

			if ( is_string( $addon_id ) && in_array( $record['stage'] ?? '', [ 'schema', 'migrate' ], true ) && isset( $failures[ 'addon:' . $addon_id ] ) ) {
				unset( $records[ $key ] );
				$taken = true;
			}
		}

		if ( $taken && false === has_action( 'admin_notices', [ self::class, 'render' ] ) ) {
			self::render();
		}

		return $records;
	}

	/**
	 * One failure as text: step, cause, attempts, next automatic try.
	 *
	 * @param array<string, mixed> $failure A failures() entry.
	 * @return string
	 */
	private static function line( array $failure ): string {
		$wait = max( 0, (int) ( $failure['next'] ?? 0 ) - time() );

		return sprintf(
			/* translators: 1: step that failed, 2: cause, 3: number of attempts, 4: time until the next automatic try. */
			__( '%1$s failed: %2$s Attempts: %3$d. Next automatic try: %4$s.', 'perflocale' ),
			(string) ( $failure['step'] ?? '' ),
			// Redacted again here: a stored entry may predate the redaction or come from another copy of the code.
			rtrim( \PerfLocale\Util\PathRedactor::redact( (string) ( $failure['error'] ?? '' ) ), '.' ) . '.',
			(int) ( $failure['attempts'] ?? 0 ),
			0 === $wait ? __( 'on the next page load', 'perflocale' ) : human_time_diff( time(), time() + $wait )
		);
	}

	/**
	 * Retry now (admin-post): run every pending step under the lock, then go back to the screen.
	 *
	 * @return void
	 */
	public static function handle_retry(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to run the PerfLocale database upgrade.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		$result = UpgradeRunner::retry();
		$back   = wp_get_referer();

		wp_safe_redirect( add_query_arg( 'perflocale_upgrade', $result, $back ? $back : admin_url() ) );
		exit;
	}

	/**
	 * The nonce-protected Retry now link.
	 *
	 * @return string
	 */
	private static function retry_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * Site Health test `perflocale_upgrade`: recorded failures and every table, column and index.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string} Status, label, description HTML, actions HTML.
	 */
	public static function site_health(): array {
		$failures = UpgradeRunner::failures();
		$missing  = UpgradeRunner::missing_parts();

		if ( [] === $failures && [] === $missing ) {
			return [
				'good',
				__( 'PerfLocale database upgrade is complete', 'perflocale' ),
				esc_html__( 'Every upgrade step finished and every table, column and index is in the database.', 'perflocale' ),
				'',
			];
		}

		$items = '';

		foreach ( $failures as $failure ) {
			$items .= '<li>' . esc_html( self::line( $failure ) ) . '</li>';
		}

		if ( null === $missing ) {
			$items .= '<li>' . esc_html__( 'The database did not return the table structure, so it could not be checked.', 'perflocale' ) . '</li>';
		} elseif ( [] !== $missing ) {
			/* translators: %s: comma-separated table, column or index names. */
			$items .= '<li>' . esc_html( sprintf( __( 'Missing in the database: %s.', 'perflocale' ), implode( ', ', $missing ) ) ) . '</li>';
		}

		$retry = '<a class="button" href="' . esc_url( self::retry_url() ) . '">' . esc_html__( 'Retry now', 'perflocale' ) . '</a>';

		// Missing parts alone are a recommendation, and nothing retries them on its own: Retry now re-applies the schema.
		if ( [] === $failures ) {
			return [
				'recommended',
				null === $missing ? __( 'PerfLocale could not check its database tables', 'perflocale' ) : __( 'PerfLocale database tables are incomplete', 'perflocale' ),
				'<ul>' . $items . '</ul><p>' . ( null === $missing
					? esc_html__( 'No upgrade step failed. Use Retry now to check the tables again; if the check keeps failing, ask your host to look at the database server\'s error log.', 'perflocale' )
					: esc_html__( 'No upgrade step failed. Use Retry now to apply the table structure again; if parts stay missing, check the database user\'s right to create and alter tables.', 'perflocale' ) ) . '</p>',
				$retry,
			];
		}

		return [
			'critical',
			__( 'PerfLocale database upgrade did not finish', 'perflocale' ),
			'<ul>' . $items . '</ul><p>' . esc_html__( 'PerfLocale retries on its own. After fixing the cause, for example the database user\'s right to create and alter tables, use Retry now.', 'perflocale' ) . '</p>',
			$retry,
		];
	}
}
