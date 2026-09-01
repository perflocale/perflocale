<?php
/**
 * Shared admin navigation strip.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the underline tab strip shared by every PerfLocale admin page
 * (the Settings page keeps its own, tab-per-settings-section strip).
 *
 * Tabs are derived from the actually-registered PerfLocale submenu, so
 * conditional pages (addon-registered ones) appear and disappear with
 * their features, and capability filtering matches the sidebar menu
 * exactly. Known slugs render in a curated order;
 * unknown ones (future addons) slot in before Jobs; Settings is always
 * last.
 */
final class PluginNav {

	/**
	 * Curated tab order. Slugs missing from the registered submenu are
	 * skipped; registered slugs missing from this list are appended
	 * before Jobs (with Settings pinned last).
	 */
	private const ORDER = [
		'perflocale',
		'perflocale-languages',
		'perflocale-translations',
		'perflocale-strings',
		'perflocale-addons',
		'perflocale-jobs',
		'perflocale-settings',
	];

	/**
	 * Per-page documentation URLs for the right-aligned Help tab —
	 * the same targets the pages' individual "Documentation" header
	 * buttons pointed at before the shared strip replaced them.
	 */
	private const HELP = [
		'perflocale'              => 'https://perflocale.com/docs/',
		'perflocale-languages'    => 'https://perflocale.com/docs/content-translation/',
		'perflocale-translations' => 'https://perflocale.com/docs/translations/',
		'perflocale-strings'      => 'https://perflocale.com/docs/string-translation/',
		'perflocale-addons'       => 'https://perflocale.com/docs/addons/',
		'perflocale-jobs'         => 'https://perflocale.com/docs/background-jobs/',
	];

	/**
	 * Render the strip. Call directly under the page's header row
	 * (after the `wp-header-end` marker where the page has one).
	 *
	 * @return void
	 */
	public static function render(): void {
		global $submenu;

		$registered = [];

		foreach ( (array) ( $submenu['perflocale'] ?? [] ) as $item ) {
			// Submenu entry shape: [0] menu title, [1] capability, [2] slug.
			$slug = (string) ( $item[2] ?? '' );
			$cap  = (string) ( $item[1] ?? 'manage_options' );

			if ( $slug === '' || ! str_starts_with( $slug, 'perflocale' ) || ! current_user_can( $cap ) ) {
				continue;
			}

			$registered[ $slug ] = wp_strip_all_tags( (string) ( $item[0] ?? $slug ) );
		}

		if ( $registered === [] ) {
			return;
		}

		$ordered = [];

		foreach ( self::ORDER as $slug ) {
			if ( isset( $registered[ $slug ] ) ) {
				$ordered[ $slug ] = $registered[ $slug ];
				unset( $registered[ $slug ] );
			}
		}

		// Unknown (future addon) slugs: keep them visible, ahead of the
		// trailing Jobs/Settings pair when those exist.
		if ( $registered !== [] ) {
			$tail = [];

			foreach ( [ 'perflocale-jobs', 'perflocale-settings' ] as $tail_slug ) {
				if ( isset( $ordered[ $tail_slug ] ) ) {
					$tail[ $tail_slug ] = $ordered[ $tail_slug ];
					unset( $ordered[ $tail_slug ] );
				}
			}

			$ordered = array_merge( $ordered, $registered, $tail );
		}

		// Route guard only — highlighting, not a security decision.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		?>
		<nav class="nav-tab-wrapper perflocale-nav-tabs">
			<div class="perflocale-nav-tabs__items">
				<?php foreach ( $ordered as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
						class="nav-tab <?php echo esc_attr( $current === $slug ? 'nav-tab-active' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>
			<a href="<?php echo esc_url( self::HELP[ $current ] ?? 'https://perflocale.com/docs/' ); ?>"
				target="_blank"
				rel="noopener"
				class="nav-tab perflocale-help-tab perflocale-btn-icon perflocale-btn-icon--md perflocale-nav-tabs__help"
				title="<?php echo esc_attr__( 'Open documentation for this page in a new window', 'perflocale' ); ?>">
				<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
				<?php echo esc_html__( 'Help', 'perflocale' ); ?>
			</a>
		</nav>
		<?php
	}
}
