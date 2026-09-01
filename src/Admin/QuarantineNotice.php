<?php
/**
 * Surface addon quarantine state in the WordPress admin.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows an admin notice and a Site Health test when one or more addons
 * have been auto-quarantined by the AddonRegistry after repeated boot
 * failures.
 *
 * The notice appears on every admin page for admins so broken
 * integrations are impossible to miss. A per-addon "Retry" link calls
 * `AddonRegistry::reset_quarantine()` which clears the failure counter
 * so the addon boots again on the next request - if the underlying
 * issue was fixed, the counter stays cleared; if not, the addon is
 * re-quarantined after another 3 failures.
 *
 * Kept intentionally dependency-light: the quarantine API is public on
 * AddonRegistry, so this class only needs the service container to
 * reach it.
 */
final class QuarantineNotice {

	/**
	 * GET query arg that triggers a retry.
	 */
	private const RETRY_ARG = 'perflocale_retry_addon';

	/**
	 * Nonce action for the retry link.
	 */
	private const RETRY_NONCE = 'perflocale_retry_addon';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_init', [ $this, 'process_retry_action' ] );

		// The Site Health test for quarantined addons lives in
		// PerfLocale\Admin\SiteHealth alongside the rest of the plugin's
		// diagnostics - kept out of this class to avoid two entries in
		// the Status list.
	}

	/**
	 * Render the admin notice listing quarantined addons with per-addon
	 * Retry links.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = $this->get_quarantined_ids();

		if ( empty( $ids ) ) {
			return;
		}

		$labels = $this->resolve_labels( $ids );

		?>
		<div class="notice notice-error perflocale-quarantine-notice">
			<p>
				<strong><?php esc_html_e( 'PerfLocale: Broken addon(s) disabled', 'perflocale' ); ?></strong>
				-
				<?php
				esc_html_e(
					'the addon(s) listed below threw errors three times in a row and have been auto-disabled to protect your site. Fix the underlying issue (deactivate the third-party plugin, update it, or check the debug log) then click Retry.',
					'perflocale'
				);
				?>
			</p>
			<ul style="list-style:disc; margin-left:20px;">
				<?php foreach ( $ids as $id ) : ?>
					<?php
					$retry_url = wp_nonce_url(
						add_query_arg( self::RETRY_ARG, $id ),
						self::RETRY_NONCE
					);
					$label     = $labels[ $id ] ?? $id;
					?>
					<li>
						<code><?php echo esc_html( $label ); ?></code>
						&nbsp;
						<a href="<?php echo esc_url( $retry_url ); ?>" class="button button-small">
							<?php esc_html_e( 'Retry', 'perflocale' ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Handle the ?perflocale_retry_addon=<id> link: verify capability
	 * and nonce, clear the addon's failure counter, redirect clean.
	 *
	 * @return void
	 */
	public function process_retry_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::RETRY_ARG ] ) ) {
			return;
		}

		// Canonical WP order: nonce (CSRF) first, then capability.
		// `check_admin_referer()` itself dies on failure, so we explicitly
		// re-check the cap immediately after to keep the gate symmetric.
		check_admin_referer( self::RETRY_NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		$id = sanitize_key( wp_unslash( $_GET[ self::RETRY_ARG ] ) );

		if ( $id === '' ) {
			return;
		}

		$registry = $this->registry();

		if ( $registry !== null ) {
			$registry->reset_quarantine( $id );
		}

		// Strip our query args from the current URL and redirect.
		$redirect = remove_query_arg( [ self::RETRY_ARG, '_wpnonce' ] );

		if ( $redirect === '' ) {
			$redirect = admin_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Fetch quarantined addon IDs from the registry.
	 *
	 * @return array<int, string>
	 */
	private function get_quarantined_ids(): array {
		$registry = $this->registry();

		if ( $registry === null ) {
			return [];
		}

		return $registry->get_quarantined_ids();
	}

	/**
	 * Map addon IDs to their display names (falling back to the ID).
	 *
	 * @param array<int, string> $ids Addon IDs.
	 * @return array<string, string>
	 */
	private function resolve_labels( array $ids ): array {
		$labels   = [];
		$registry = $this->registry();

		if ( $registry === null ) {
			foreach ( $ids as $id ) {
				$labels[ $id ] = $id;
			}

			return $labels;
		}

		$addons = $registry->get_addons();

		foreach ( $ids as $id ) {
			if ( isset( $addons[ $id ] ) && method_exists( $addons[ $id ], 'get_name' ) ) {
				$labels[ $id ] = (string) $addons[ $id ]->get_name();
			} else {
				$labels[ $id ] = $id;
			}
		}

		return $labels;
	}

	/**
	 * Lazily resolve the AddonRegistry from the service container.
	 *
	 * @return \PerfLocale\Addon\AddonRegistry|null
	 */
	private function registry(): ?\PerfLocale\Addon\AddonRegistry {
		try {
			$plugin = Plugin::get_instance();

			if ( ! $plugin->has( 'addon_registry' ) ) {
				return null;
			}

			$registry = $plugin->get( 'addon_registry' );

			return $registry instanceof \PerfLocale\Addon\AddonRegistry ? $registry : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
