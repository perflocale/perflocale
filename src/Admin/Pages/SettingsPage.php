<?php
/**
 * Settings admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the PerfLocale settings page with a tabbed interface.
 *
 * Tabs: URL & Routing, Translation, SEO, Performance, Language
 * Switcher, Addons (feature subtabs such as Machine Translation),
 * Export & Import, Advanced. Each tab renders its own set of
 * settings fields with full sanitization and nonce verification.
 */
final class SettingsPage {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Available tabs and their labels.
	 *
	 * @var array<string, string>
	 */
	private array $tabs = [];

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;

		$this->tabs = [
			'url-routing'       => __( 'URL & Routing', 'perflocale' ),
			'translation'       => __( 'Translation', 'perflocale' ),
			'seo'               => __( 'SEO', 'perflocale' ),
			'performance'       => __( 'Performance', 'perflocale' ),
			'language-switcher' => __( 'Language Switcher', 'perflocale' ),
			'addons'            => __( 'Addons', 'perflocale' ),
			'export-import'     => __( 'Export & Import', 'perflocale' ),
			'advanced'          => __( 'Advanced', 'perflocale' ),
		];
	}

	/**
	 * Register and attach the fallback-chain editor CSS and JS through
	 * the standard WordPress style/script pipeline.
	 *
	 * The fallback editor markup only appears on the URL-routing tab of the
	 * settings page, but enqueuing the (small) inline CSS + JS on every
	 * settings-page load is cheaper than tracking tab state from
	 * admin_enqueue_scripts time - and means we never echo raw style/script
	 * tags into the page body.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'perflocale_page_perflocale-settings' ) {
			return;
		}

		wp_enqueue_style(
			'perflocale-fallback-editor',
			PERFLOCALE_URL . 'assets/css/fallback-editor.css',
			[],
			PERFLOCALE_VERSION
		);

		wp_enqueue_script(
			'perflocale-fallback-editor',
			PERFLOCALE_URL . 'assets/js/fallback-editor.js',
			[],
			PERFLOCALE_VERSION,
			true
		);
		wp_localize_script(
			'perflocale-fallback-editor',
			'perflocaleFallbackEditor',
			[
				'labels' => [
					'removePrefix' => __( 'Remove', 'perflocale' ),
					'removeSuffix' => __( 'fallback', 'perflocale' ),
					'dragHint'     => __( 'Draggable fallback', 'perflocale' ),
					/* translators: 1: ordinal position, 2: language name or redirect method name */
					'positionTpl'  => __( 'Position %1$d: %2$s', 'perflocale' ),
				],
			]
		);

		// Redirect-priority editor reuses the .pl-fb-chip CSS for chip styling.
		// Its own JS lives in a small separate file because the behaviour is
		// stripped-down (sortable only, no add/remove, no max).
		wp_enqueue_script(
			'perflocale-redirect-priority-editor',
			PERFLOCALE_URL . 'assets/js/redirect-priority-editor.js',
			[],
			PERFLOCALE_VERSION,
			true
		);

		// Conditional-field show/hide for the auto-generated addon
		// settings subtab. Enqueued unconditionally on the Settings page
		// because the user can navigate between subtabs without a page
		// reload (well — currently they do reload, but enqueuing here
		// keeps the asset available wherever the form might render). The
		// script no-ops gracefully when no addon form is present.
		wp_enqueue_script(
			'perflocale-addon-settings-conditional',
			PERFLOCALE_URL . 'assets/js/addon-settings-conditional.js',
			[],
			PERFLOCALE_VERSION,
			true
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Form processing is handled by AdminController on admin_init.

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab routing only.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'url-routing';

		if ( ! array_key_exists( $active_tab, $this->tabs ) ) {
			$active_tab = 'url-routing';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only message display.
		$message = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';

		?>
		<div class="wrap perflocale-settings">
			<h1><?php echo esc_html__( 'PerfLocale Settings', 'perflocale' ); ?></h1>

			<?php if ( $message === 'true' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved successfully.', 'perflocale' ); ?></p>
				</div>
			<?php elseif ( $message === 'false' ) : ?>
				<?php // A save that wpdb refused. It reported success before this existed. ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'Settings could not be saved. The database refused the write, which usually means one value is too long for its column or contains characters it cannot store. Your previous settings are unchanged.', 'perflocale' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// A multisite network resolves the requested hostname against its
			// own site list in ms-settings.php, BEFORE any plugin loads - so
			// picking subdomain or per-language-domain mode with hostnames that
			// are not registered as sites takes every translated link to
			// WordPress's "site does not exist" screen, and no plugin code can
			// intercept it. SiteHealth has always said so, but only to whoever
			// opens Tools -> Site Health; the operator breaks the network HERE,
			// at save time, so the same check runs on the tab that owns the
			// setting. Same code, not a copy: SiteHealth::language_host_report()
			// costs zero queries on a single site and in subdirectory / query
			// mode, both of which return before the first lookup.
			if ( $active_tab === 'url-routing' ) :
				$perflocale_host_report = \PerfLocale\Admin\SiteHealth::language_host_report();

				if ( $perflocale_host_report['applies'] && $perflocale_host_report['unresolved'] !== [] ) :
					$perflocale_unresolved = [];

					foreach ( $perflocale_host_report['unresolved'] as $perflocale_bad_host ) {
						$perflocale_unresolved[] = '<code>' . esc_html( $perflocale_bad_host ) . '</code>';
					}
					?>
					<div class="notice notice-error">
						<p><strong>
							<?php
							printf(
								/* translators: %s: URL mode label, e.g. "subdomain". */
								esc_html__( '%s URL mode generates hostnames this network cannot resolve.', 'perflocale' ),
								'<code>' . esc_html( (string) $perflocale_host_report['mode'] ) . '</code>'
							);
							?>
						</strong></p>
						<p>
							<?php
							printf(
								/* translators: %s: comma-separated list of hostnames, each wrapped in a code element. */
								wp_kses_post( __( 'These hostnames are not registered as sites in this network: %s. On multisite, WordPress matches the requested hostname against the network\'s site list before any plugin loads, so a visitor following a translated link gets the "site does not exist" screen instead of a translation.', 'perflocale' ) ),
								wp_kses_post( implode( ', ', $perflocale_unresolved ) )
							);
							?>
						</p>
						<p>
							<?php echo esc_html__( 'Add each hostname as a site in the network (and point its DNS or server alias at this install), or switch back to subdirectory or query URL mode, which keep this site\'s hostname.', 'perflocale' ); ?>
						</p>
						<p>
							<a href="<?php echo esc_url( network_admin_url( 'site-new.php' ) ); ?>"><?php echo esc_html__( 'Add a site to the network', 'perflocale' ); ?></a>
							<span style="color:#c3c4c7;"> &middot; </span>
							<a href="https://perflocale.com/docs/multisite/#url-modes" target="_blank" rel="noopener"><?php echo esc_html__( 'URL modes on a network', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
						</p>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php
			// Addon-settings saves (Settings → Addons → <addon> subtab) redirect
			// back HERE with perflocale_msg — the flash renderer on the Addons
			// LIST page never runs for this screen, so without this block a
			// successful save showed no feedback at all.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash params on a redirect.
			$perflocale_flash_msg  = isset( $_GET['perflocale_msg'] ) ? sanitize_key( $_GET['perflocale_msg'] ) : '';
			$perflocale_flash_slug = isset( $_GET['perflocale_addon'] ) ? sanitize_key( $_GET['perflocale_addon'] ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( $perflocale_flash_slug !== '' && in_array( $perflocale_flash_msg, [ 'addon_saved', 'addon_save_failed' ], true ) ) {
				$perflocale_subtab_labels = $this->get_addon_subtabs();
				$perflocale_flash_name    = $perflocale_subtab_labels[ $perflocale_flash_slug ] ?? $perflocale_flash_slug;
				if ( $perflocale_flash_msg === 'addon_saved' ) {
					?>
					<div class="notice notice-success is-dismissible">
						<p>
						<?php
						/* translators: %s: addon display name */
						echo esc_html( sprintf( __( 'Settings for %s saved.', 'perflocale' ), $perflocale_flash_name ) );
						?>
						</p>
					</div>
					<?php
				} else {
					?>
					<div class="notice notice-error is-dismissible">
						<p>
						<?php
						/* translators: %s: addon display name */
						echo esc_html( sprintf( __( 'Could not save settings for %s. The values may exceed the per-addon size limit, or another save was in progress. Check the PHP error log under WP_DEBUG for the exact reason.', 'perflocale' ), $perflocale_flash_name ) );
						?>
						</p>
					</div>
					<?php
				}
			}
			?>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$files_generated = isset( $_GET['files_generated'] ) ? absint( $_GET['files_generated'] ) : -1;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$repaired = isset( $_GET['repaired'] ) ? absint( $_GET['repaired'] ) : 0;

			if ( $files_generated >= 0 ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of files generated */
							esc_html__( 'Translation files regenerated successfully. %d file(s) generated.', 'perflocale' ),
							absint( $files_generated )
						);

						if ( $repaired > 0 ) {
							echo ' ';
							printf(
								/* translators: %d: number of stranded translations reconnected */
								esc_html__( '%d previously-stranded translation(s) were reconnected during this run.', 'perflocale' ),
								absint( $repaired )
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="position: sticky; top: 32px; background: #f0f0f1; z-index: 10; display: flex; align-items: flex-end;">
				<div style="flex: 1 1 auto; display: flex; align-items: flex-end;">
					<?php foreach ( $this->tabs as $tab_key => $tab_label ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=' . $tab_key ) ); ?>"
							class="nav-tab <?php echo esc_attr( $active_tab === $tab_key ? 'nav-tab-active' : '' ); ?>">
							<?php echo esc_html( $tab_label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php $this->render_tab_help_link( $active_tab ); ?>
			</nav>

			<form class="perflocale-settings-tabs-mobile" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="perflocale-settings" />
				<label class="perflocale-settings-tabs-mobile__label" for="perflocale-settings-tab-select">
					<?php echo esc_html__( 'Settings section:', 'perflocale' ); ?>
				</label>
				<select
					id="perflocale-settings-tab-select"
					name="tab"
					class="perflocale-settings-tabs-mobile__select"
				>
					<?php foreach ( $this->tabs as $tab_key => $tab_label ) : ?>
						<option
							value="<?php echo esc_attr( $tab_key ); ?>"
							<?php selected( $active_tab, $tab_key ); ?>
						>
							<?php echo esc_html( $tab_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button perflocale-settings-tabs-mobile__go"><?php echo esc_html__( 'Go', 'perflocale' ); ?></button>
			</form>

			<?php if ( $active_tab === 'export-import' ) : ?>
				<?php $this->render_export_import_tab(); ?>
			<?php elseif ( $active_tab === 'addons' ) : ?>
				<?php $this->render_addons_tab(); ?>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=' . $active_tab ) ); ?>">
				<?php wp_nonce_field( 'perflocale_save_settings' ); ?>
				<input type="hidden" name="perflocale_settings_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<table class="form-table" role="presentation">
					<?php
					match ( $active_tab ) {
						'url-routing' => $this->render_url_routing_tab(),
						'translation' => $this->render_translation_tab(),
						'seo' => $this->render_seo_tab(),
						'language-switcher' => $this->render_language_switcher_tab(),
						'performance' => $this->render_performance_tab(),
						'advanced' => $this->render_advanced_tab(),
						default => $this->render_url_routing_tab(),
					};
	?>
				</table>

				<?php submit_button(); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sanitize the GeoIP country-mapping input.
	 *
	 * Hard contract:
	 *   - The DEFAULT language is unconditionally dropped (any visitor
	 *     whose IP doesn't match a mapped country falls through to the
	 *     default automatically; mapping the default actively causes
	 *     over-redirects, and a malicious / mistaken save must NOT be
	 *     able to introduce one).
	 *   - Each language's value is split on commas, uppercased, and only
	 *     `[A-Z]{2}` codes are kept. Anything else (emoji, HTML, IDN,
	 *     longer strings) is dropped silently.
	 *   - Slugs not in the active-language list are dropped.
	 *   - Empty rows produce no entry (caller's `?? ''` keeps the UI
	 *     consistent without bloating the saved option).
	 *
	 * Returned shape: `[ slug => "AA,BB,CC" ]` — same shape the existing
	 * runtime expects.
	 *
	 * @param mixed $input Raw POSTed value (expected: assoc array).
	 * @return array<string, string>
	 */
	private function sanitize_geo_country_map( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$cache        = \PerfLocale\Plugin::get_instance()->get( 'cache' );
		$repo         = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		$default      = $repo->get_default();
		$default_slug = is_object( $default ) && ! empty( $default->slug ) ? (string) $default->slug : '';

		$active_slugs = [];
		foreach ( $repo->get_active() as $l ) {
			if ( ! empty( $l->slug ) ) {
				$active_slugs[ (string) $l->slug ] = true;
			}
		}

		$out = [];

		foreach ( wp_unslash( $input ) as $slug => $raw ) {
			$slug = (string) $slug;

			// Server-side guard: never store a mapping for the default
			// language, regardless of what was submitted. The UI hides
			// this row but a hand-crafted POST or HTML injection must NOT
			// be able to bypass it.
			if ( $default_slug !== '' && $slug === $default_slug ) {
				continue;
			}

			// Drop slugs the user can't actually have configured (active
			// list is the truth — no mapping for inactive / removed
			// languages is meaningful).
			if ( ! isset( $active_slugs[ $slug ] ) ) {
				continue;
			}

			$raw   = is_scalar( $raw ) ? (string) $raw : '';
			$codes = [];

			foreach ( explode( ',', $raw ) as $token ) {
				$token = strtoupper( trim( $token ) );

				if ( preg_match( '/^[A-Z]{2}$/', $token ) ) {
					$codes[ $token ] = true; // de-dupe
				}
			}

			$out[ $slug ] = implode( ',', array_keys( $codes ) );
		}

		return $out;
	}

	/**
	 * Render a right-aligned "Help" nav-tab inside the settings tab bar.
	 *
	 * Styled as a regular nav-tab (native WP admin look) but floated right
	 * with a book icon. Clicking opens the most relevant /docs/ page for
	 * the active settings tab in a new browser tab. Unknown tabs fall
	 * through to the docs index.
	 *
	 * @param string $active_tab Active settings tab key.
	 * @return void
	 */
	private function render_tab_help_link( string $active_tab ): void {
		$map = [
			'url-routing'       => 'https://perflocale.com/docs/url-routing/',
			'translation'       => 'https://perflocale.com/docs/content-translation/',
			'seo'               => 'https://perflocale.com/docs/seo/',
			'performance'       => 'https://perflocale.com/docs/production-tuning/',
			'language-switcher' => 'https://perflocale.com/docs/language-switcher/',
			'addons'            => 'https://perflocale.com/docs/addons/',
			'export-import'     => 'https://perflocale.com/docs/export-import/',
			'advanced'          => 'https://perflocale.com/docs/hooks/',
		];

		$url = $map[ $active_tab ] ?? 'https://perflocale.com/docs/';

		?>
		<a href="<?php echo esc_url( $url ); ?>"
			target="_blank"
			rel="noopener"
			class="nav-tab perflocale-help-tab perflocale-btn-icon perflocale-btn-icon--md"
			style="margin-left:auto;margin-right:0;color:#50575e;"
			title="<?php echo esc_attr__( 'Open documentation for this tab in a new window', 'perflocale' ); ?>">
			<span class="dashicons dashicons-editor-help"></span>
			<?php echo esc_html__( 'Help', 'perflocale' ); ?>
		</a>
		<?php
	}

	/**
	 * Extract and sanitize settings values from POST data for a specific tab.
	 *
	 * @param string $tab Active tab key.
	 * @return array<string, mixed> Sanitized values.
	 */
	public function extract_tab_values( string $tab ): array {
		// AdminController::on_admin_init() verifies `perflocale_save_settings`
		// before reaching this method, but we re-check here so static
		// analysers (Plugin Check, wporg-pluginchecker) see a nonce gate
		// adjacent to the $_POST reads instead of relying on the caller.
		// Removing this would still be safe — defense in depth, not the
		// authoritative gate.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_settings' ) ) {
			return [];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$values = [];

		match ( $tab ) {
			'url-routing' => $values = [
				'url_mode'                   => isset( $_POST['url_mode'] ) && in_array( $_POST['url_mode'], [ 'subdirectory', 'subdomain', 'domain', 'query' ], true ) ? sanitize_key( wp_unslash( $_POST['url_mode'] ) ) : 'subdirectory',
				'url_prefix_type'            => isset( $_POST['url_prefix_type'] ) && in_array( $_POST['url_prefix_type'], [ 'slug', 'locale' ], true ) ? sanitize_key( wp_unslash( $_POST['url_prefix_type'] ) ) : 'slug',
				'hide_default_prefix'        => isset( $_POST['hide_default_prefix'] ),
				'redirect_browser_lang'      => isset( $_POST['redirect_browser_lang'] ),
				'redirect_geo_enabled'       => isset( $_POST['redirect_geo_enabled'] ),
				'geo_provider'               => isset( $_POST['geo_provider'] ) ? sanitize_key( wp_unslash( $_POST['geo_provider'] ) ) : '',
				'geo_cache_hours'            => isset( $_POST['geo_cache_hours'] ) ? absint( $_POST['geo_cache_hours'] ) : 24,
				'geo_country_map'            => $this->sanitize_geo_country_map( $_POST['geo_country_map'] ?? [] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_geo_country_map handles unslash + per-key sanitisation.
				'missing_translation_action' => isset( $_POST['missing_translation_action'] ) && in_array( $_POST['missing_translation_action'], [ 'show_default', 'show_404', 'redirect_default' ], true ) ? sanitize_key( wp_unslash( $_POST['missing_translation_action'] ) ) : 'show_default',
				'language_fallbacks'         => isset( $_POST['language_fallbacks'] ) && is_array( $_POST['language_fallbacks'] )
					? ( static function ( array $raw ): array {
						$out = [];

						foreach ( $raw as $slug => $row ) {
							// Keys are language slugs and arrive from the POST
							// body too — sanitize them, not just the row values.
							// Settings::sanitize_language_fallbacks() re-checks
							// on write; this keeps intake clean as well.
							$slug = sanitize_key( (string) $slug );

							if ( $slug === '' ) {
								continue;
							}

							$out[ $slug ] = array_values( array_filter( array_map( 'sanitize_key', (array) $row ) ) );
						}

						return $out;
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys and every row entry are sanitize_key'd inside the closure above.
					} )( wp_unslash( (array) $_POST['language_fallbacks'] ) )
					: [],
				'cookie_lifetime'            => isset( $_POST['cookie_lifetime'] ) ? absint( $_POST['cookie_lifetime'] ) : 365,
				'disable_language_cookie'    => isset( $_POST['disable_language_cookie'] ),
				'excluded_paths'             => isset( $_POST['excluded_paths'] ) ? array_map( 'sanitize_text_field', array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['excluded_paths'] ) ) ) ) ) ) : [],
				// Normalize each entry to a bare lowercase host: strip any scheme,
				// path/trailing slash, and case so apply_domain()'s host-equality
				// check and ://host string-replace work (a stored "https://De.X/"
				// would otherwise never match the request host "de.x").
				'language_domains'           => isset( $_POST['language_domains'] )
					? ( static function ( array $raw ): array {
						$out = [];

						foreach ( $raw as $slug => $v ) {
							// Keys are language slugs and arrive from the POST
							// body too — sanitize them, not just the values.
							$slug = sanitize_key( (string) $slug );

							if ( $slug === '' ) {
								continue;
							}

							$v    = is_string( $v ) ? sanitize_text_field( wp_unslash( $v ) ) : '';
							$host = wp_parse_url( str_contains( $v, '//' ) ? $v : '//' . $v, PHP_URL_HOST );

							$out[ $slug ] = strtolower( (string) ( $host ?: $v ) );
						}

						return $out;
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- keys sanitize_key'd and each value sanitized + host-normalized inside the closure above.
					} )( (array) $_POST['language_domains'] )
					: [],
				// Rendered only inside `if ( edge_integration_enabled() )`, so on a
				// save with the edge worker off the checkbox was never printed and
				// isset() reads false — clearing a setting the operator could not
				// see. Mirror the renderer's gate and keep the stored value.
				'redirect_edge_hint_enabled' => $this->settings->edge_integration_enabled()
					? isset( $_POST['redirect_edge_hint_enabled'] )
					: (bool) $this->settings->get( 'redirect_edge_hint_enabled' ),
				// Redirect-priority chip order. Sanitisation also lives in
				// Settings::get_redirect_priority_order() (drops unknowns,
				// dedupes, backfills missing methods) so this can stay simple.
				'redirect_priority_order'    => isset( $_POST['redirect_priority_order'] )
					? array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['redirect_priority_order'] ) ) ) )
					: [],
			],
			'translation' => $values         = [
				'translatable_post_types'    => isset( $_POST['translatable_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['translatable_post_types'] ) : [],
				'translatable_taxonomies'    => isset( $_POST['translatable_taxonomies'] ) ? array_map( 'sanitize_key', (array) $_POST['translatable_taxonomies'] ) : [],
				'default_translation_status' => isset( $_POST['default_translation_status'] ) ? sanitize_text_field( wp_unslash( $_POST['default_translation_status'] ) ) : 'empty',
				'auto_create_stubs'          => isset( $_POST['auto_create_stubs'] ),
				'sync_fields'                => $this->merge_sync_fields(),
				'translate_slugs'            => isset( $_POST['translate_slugs'] ),
				'sync_term_hierarchy'        => isset( $_POST['sync_term_hierarchy'] ),
			],
			// The renderer returns early when machine translation is OFF
			// (render_machine_translation_tab), so the enabling save posts
			// ONLY mt_enabled — every other control below was never printed.
			// Extracting them anyway turned "not rendered" into "operator
			// cleared it": mt_auto_translate_languages in particular became
			// the explicit no-language scope, so auto-translate silently did
			// nothing after being switched on. Mirror the renderer's own gate
			// and read the flag as it is STORED, before Settings::update()
			// runs (AdminController::on_admin_init extracts at :242 and
			// updates at :245).
			'machine-translation' => $values = $this->settings->mt_enabled() ? [
				'mt_enabled'                   => isset( $_POST['mt_enabled'] ),
				'mt_provider'                  => isset( $_POST['mt_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_provider'] ) ) : '',
				'mt_deepl_api_key'             => isset( $_POST['mt_deepl_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_deepl_api_key'] ) ) : '',
				'mt_deepl_formality'           => isset( $_POST['mt_deepl_formality'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_deepl_formality'] ) ) : 'default',
				'mt_google_api_key'            => isset( $_POST['mt_google_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_google_api_key'] ) ) : '',
				'mt_microsoft_api_key'         => isset( $_POST['mt_microsoft_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_microsoft_api_key'] ) ) : '',
				'mt_microsoft_region'          => isset( $_POST['mt_microsoft_region'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_microsoft_region'] ) ) : 'global',
				'mt_libre_url'                 => isset( $_POST['mt_libre_url'] ) ? esc_url_raw( wp_unslash( $_POST['mt_libre_url'] ) ) : '',
				'mt_libre_api_key'             => isset( $_POST['mt_libre_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_libre_api_key'] ) ) : '',
				'mt_agency_url'                => isset( $_POST['mt_agency_url'] ) ? esc_url_raw( wp_unslash( $_POST['mt_agency_url'] ) ) : '',
				'mt_agency_api_key'            => isset( $_POST['mt_agency_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_agency_api_key'] ) ) : '',
				'mt_auto_translate_on_publish' => isset( $_POST['mt_auto_translate_on_publish'] ),
				// Sanitize against ACTIVE language slugs; when every active
				// language is checked, store [] ("all") so languages added
				// later are included automatically instead of silently
				// excluded by a stale explicit list.
				'mt_auto_translate_languages'  => ( function (): array {
					$posted  = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['mt_auto_translate_languages'] ?? [] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each entry sanitize_key'd then whitelisted against active slugs.
					$router  = \PerfLocale\Plugin::get_instance()->get( 'router' );
					$default = $router->get_default_language();
					$active  = [];

					// The default language is the MT source, never a target —
					// the UI renders no checkbox for it, so it must not count
					// toward the "every target checked" comparison below.
					foreach ( $router->get_active_languages() as $l ) {
						if ( isset( $l->slug ) && ( ! $default || $l->slug !== $default->slug ) ) {
							$active[] = (string) $l->slug;
						}
					}

					$subset = array_values( array_intersect( $posted, $active ) );

					// All targets unchecked = auto-MT nowhere. [] is taken
					// (it means "all"), so store the reserved sentinel — a
					// scope that matches no language — instead of silently
					// flipping the admin's "none" back into "all".
					if ( $subset === [] && $active !== [] ) {
						return [ \PerfLocale\Settings::MT_SCOPE_NONE ];
					}

					return count( $subset ) >= count( $active ) ? [] : $subset;
				} )(),
				'mt_auto_translate_on_create'  => isset( $_POST['mt_auto_translate_on_create'] ),
				'mt_monthly_char_limit'        => isset( $_POST['mt_monthly_char_limit'] ) ? absint( $_POST['mt_monthly_char_limit'] ) : 500000,
				'mt_bulk_strings_enabled'      => isset( $_POST['mt_bulk_strings_enabled'] ),
				'mt_meta_custom_fields'        => isset( $_POST['mt_meta_custom_fields'] ),
			] : [
				'mt_enabled' => isset( $_POST['mt_enabled'] ),
			],
			'seo' => $values                 = [
				'seo_hreflang_enabled'           => isset( $_POST['seo_hreflang_enabled'] ),
				'seo_hreflang_placement'         => isset( $_POST['seo_hreflang_placement'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_hreflang_placement'] ) ) : 'head',
				'seo_x_default'                  => isset( $_POST['seo_x_default'] ),
				'seo_hreflang_include_fallbacks' => isset( $_POST['seo_hreflang_include_fallbacks'] ),
				'seo_sitemap_enabled'            => isset( $_POST['seo_sitemap_enabled'] ),
				'seo_plugin'                     => isset( $_POST['seo_plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_plugin'] ) ) : 'none',
				'seo_og_locale'                  => isset( $_POST['seo_og_locale'] ),
				'seo_schema_enrichment_enabled'  => isset( $_POST['seo_schema_enrichment_enabled'] ),

				// Modern SEO & UX.
				'content_language_header'        => isset( $_POST['content_language_header'] ),
				'fallback_nosnippet'             => isset( $_POST['fallback_nosnippet'] ),
				'prerender_on_hover'             => isset( $_POST['prerender_on_hover'] ),
				'view_transitions_enabled'       => isset( $_POST['view_transitions_enabled'] ),
			],
			'language-switcher' => $values = [
				'switcher_template'          => isset( $_POST['switcher_template'] ) ? sanitize_text_field( wp_unslash( $_POST['switcher_template'] ) ) : 'flags_names',
				'switcher_display'           => isset( $_POST['switcher_display'] ) && in_array( $_POST['switcher_display'], [ 'inline', 'simple', 'dropdown' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_display'] ) ) : 'dropdown',
				'switcher_layout'            => isset( $_POST['switcher_layout'] ) && in_array( $_POST['switcher_layout'], [ 'horizontal', 'vertical' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_layout'] ) ) : 'horizontal',
				'switcher_name_format'       => isset( $_POST['switcher_name_format'] ) && in_array( $_POST['switcher_name_format'], [ 'native', 'english', 'both', 'slug' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_name_format'] ) ) : 'native',
				'switcher_class'             => isset( $_POST['switcher_class'] ) ? sanitize_text_field( wp_unslash( $_POST['switcher_class'] ) ) : '',
				'switcher_flag_style'        => isset( $_POST['switcher_flag_style'] ) ? sanitize_text_field( wp_unslash( $_POST['switcher_flag_style'] ) ) : 'rectangular',
				'switcher_show_untranslated' => isset( $_POST['switcher_show_untranslated'] ),
				'switcher_hide_current'      => isset( $_POST['switcher_hide_current'] ),
				'switcher_untranslated_link' => isset( $_POST['switcher_untranslated_link'] ) ? sanitize_text_field( wp_unslash( $_POST['switcher_untranslated_link'] ) ) : 'homepage',
				'switcher_arrow_style'       => isset( $_POST['switcher_arrow_style'] ) && in_array( $_POST['switcher_arrow_style'], [ 'single', 'double', 'none' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_arrow_style'] ) ) : 'single',
				'switcher_trigger_format'    => isset( $_POST['switcher_trigger_format'] ) && in_array( $_POST['switcher_trigger_format'], [ 'inherit', 'native', 'english', 'both', 'slug' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_trigger_format'] ) ) : 'inherit',
				'switcher_auto_insert'       => isset( $_POST['switcher_auto_insert'] ),
				// Validate against the theme's REGISTERED locations so a
				// crafted POST can't persist arbitrary strings.
				// Whitelist against the theme's REGISTERED location keys
				// VERBATIM — core stores nav-menu slugs case-sensitively
				// (a theme may register `headerMenu`), and sanitize_key()
				// would lowercase them so the array_intersect never matched,
				// silently dropping the operator's choice. The intersect is
				// the whole security check; no extra sanitizer is needed.
				'switcher_menu_locations'    => array_values(
					array_intersect(
						array_map( 'strval', (array) wp_unslash( $_POST['switcher_menu_locations'] ?? [] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted verbatim against registered keys below.
						array_keys( get_registered_nav_menus() )
					)
				),
				'admin_bar_switcher'         => isset( $_POST['admin_bar_switcher'] ),
			],
			'performance' => $values       = [
				'cache_object_enabled'    => isset( $_POST['cache_object_enabled'] ),
				'cache_preload_slugs'     => isset( $_POST['cache_preload_slugs'] ),
				'string_translation_mode' => isset( $_POST['string_translation_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['string_translation_mode'] ) ) : 'files',
				// Background-processing settings — whitelist values explicitly
				// so an attacker can't post arbitrary keys that would survive
				// sanitisation only because Settings::DEFAULTS[$key] is a string.
				'background_processing'   => isset( $_POST['background_processing'] ) && in_array( $_POST['background_processing'], [ 'auto', 'always', 'never' ], true )
					? sanitize_key( wp_unslash( $_POST['background_processing'] ) )
					: 'auto',
				// The radio renders only when Action Scheduler is loaded, so a
				// Performance save on a site without it never posts the field and
				// the fallback below would silently reset the operator's choice.
				'background_engine'       => ! \PerfLocale\Background\JobRunnerFactory::action_scheduler_available()
					? (string) $this->settings->get( 'background_engine', 'auto' )
					: ( isset( $_POST['background_engine'] ) && in_array( $_POST['background_engine'], [ 'auto', 'force_wp_cron' ], true )
						? sanitize_key( wp_unslash( $_POST['background_engine'] ) )
						: 'auto' ),
				// Per-job threshold overrides. Whitelist keys against the
				// registered job types (self::background_job_types()) so an
				// attacker can't POST arbitrary keys that would persist via
				// the array setting. Zero or missing values drop back to the
				// job's default (we don't store zero — that would force every
				// dispatch async).
				'background_thresholds'   => $this->extract_background_thresholds(),
				'background_paused'       => isset( $_POST['background_paused'] ),
			],
			'advanced' => $values      = [
				'edge_integration_enabled' => isset( $_POST['edge_integration_enabled'] ),
				'cdn_cache_tags_enabled'   => isset( $_POST['cdn_cache_tags_enabled'] ),
				'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ),
				'dashboard_widget_enabled' => isset( $_POST['dashboard_widget_enabled'] ),
			],
			'woocommerce' => $values   = [
				'wc_email_translation'      => isset( $_POST['wc_email_translation'] ),
				'wc_sync_stock'             => isset( $_POST['wc_sync_stock'] ),
				'wc_sync_prices'            => isset( $_POST['wc_sync_prices'] ),
				'wc_currency_per_lang'      => isset( $_POST['wc_currency_per_lang'] ),
				'wc_currencies'             => class_exists( 'PerfLocaleWooCommerce' ) ? \PerfLocaleWooCommerce::sanitize_currencies_post() : [],
				'wc_exchange_rate_auto'     => isset( $_POST['wc_exchange_rate_auto'] ),
				'wc_exchange_rate_provider' => isset( $_POST['wc_exchange_rate_provider'] ) ? sanitize_key( wp_unslash( $_POST['wc_exchange_rate_provider'] ) ) : '',
				'wc_exchange_rate_interval' => isset( $_POST['wc_exchange_rate_interval'] ) ? sanitize_key( wp_unslash( $_POST['wc_exchange_rate_interval'] ) ) : 'daily',
			],
			'export-import' => $values = [], // Export/import handled separately via admin_init.
			default => $values         = [],
		};
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Allow external addons to provide their sanitized values.
		if ( empty( $values ) ) {
			/**
			 * Filter settings values for external addon subtabs.
			 *
			 * Return an associative array of sanitized key => value pairs.
			 * Keys must be registered in Settings::DEFAULTS or they will be ignored.
			 *
			 * @param array<string, mixed> $values Empty array.
			 * @param string $tab The subtab slug.
			 */
			$values = (array) apply_filters( 'perflocale/settings/extract_addon_values', $values, $tab );
		}

		return $values;
	}

	/**
	 * Get the addon subtab definitions.
	 *
	 * @return array<string, string> Subtab slug => label.
	 */
	private function get_addon_subtabs(): array {
		$subtabs = [];

		// Machine Translation is ALWAYS listed, on or off. Its own
		// enable checkbox lives inside this subtab, so gating the subtab
		// on mt_enabled made the feature unreachable: the tab was hidden
		// until the flag was set, and the only control that sets the flag
		// was inside the hidden tab. The nav still shows the on/off state
		// beside the label (see $statuses below), which is what the
		// listing-page sync was actually for.
		$subtabs['machine-translation'] = __( 'Machine Translation', 'perflocale' );

		// WooCommerce subtab — needs the WC plugin active AND the
		// WooCommerce addon not in the operator-controlled disabled
		// list. Disabling the addon hides the subtab so the operator
		// isn't configuring features that won't run.
		if ( class_exists( 'WooCommerce' ) && ! \PerfLocale\Addon\AddonRegistry::is_disabled( 'woocommerce' ) ) {
			$subtabs['woocommerce'] = __( 'WooCommerce', 'perflocale' );
		}

		// Auto-add subtabs for any registered addon that exposes
		// user-editable fields via get_settings_fields(). Skips addons
		// already covered by the built-in subtabs (e.g. WooCommerce)
		// to avoid duplication.
		foreach ( $this->get_auto_addon_subtab_ids() as $id => $label ) {
			if ( ! isset( $subtabs[ $id ] ) ) {
				$subtabs[ $id ] = $label;
			}
		}

		/**
		 * Filter addon subtabs shown in the Addons settings tab.
		 *
		 * @param array<string, string> $subtabs Subtab slug => label.
		 */
		return (array) apply_filters( 'perflocale/settings/addon_subtabs', $subtabs );
	}

	/**
	 * Auto-generated subtab IDs — one per registered addon that exposes
	 * user-editable fields. Used by {@see get_addon_subtabs()} to merge
	 * registry-derived subtabs into the static list, AND by
	 * {@see render_addons_tab()} to route those subtabs to the auto-
	 * generated form renderer instead of the static built-in flow.
	 *
	 * @return array<string, string> addon_id => display name
	 */
	private function get_auto_addon_subtab_ids(): array {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'addon_registry' ) ) {
			return [];
		}

		try {
			$registry = $plugin->get( 'addon_registry' );
			$addons   = (array) $registry->get_addons();
		} catch ( \Throwable $e ) {
			return [];
		}

		// Skip addons the operator has explicitly disabled — their
		// settings subtab would just confuse: "why am I configuring an
		// addon that isn't running?" When the user re-enables, the
		// subtab comes back automatically.
		$disabled = \PerfLocale\Addon\AddonRegistry::get_disabled();

		$out = [];

		foreach ( $addons as $id => $addon ) {
			// Skip addons covered by an explicit built-in subtab.
			if ( in_array( $id, [ 'machine-translation', 'woocommerce' ], true ) ) {
				continue;
			}

			if ( in_array( $id, $disabled, true ) ) {
				continue;
			}

			try {
				$fields = (array) $addon->get_settings_fields();
			} catch ( \Throwable $e ) {
				continue;
			}

			$has_editable = false;
			foreach ( $fields as $f ) {
				if ( is_array( $f ) && \PerfLocale\Addon\AddonSettings::is_user_editable_field( $f ) ) {
					$has_editable = true;
					break;
				}
			}

			if ( ! $has_editable ) {
				continue;
			}

			$out[ (string) $id ] = method_exists( $addon, 'get_name' ) ? (string) $addon->get_name() : (string) $id;
		}

		return $out;
	}

	/**
	 * Render the Addons tab with sidebar sub-navigation.
	 *
	 * @return void
	 */
	private function render_addons_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subtab    = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : '';
		$subtabs   = $this->get_addon_subtabs();
		$requested = $subtab;

		if ( $subtab === '' || ! isset( $subtabs[ $subtab ] ) ) {
			$subtab = array_key_first( $subtabs );
		}

		// Nothing to configure. This is NO LONGER the default state: since
		// Machine Translation is listed unconditionally, get_addon_subtabs()
		// always returns at least one entry, so this branch is now reachable
		// only when a filter empties the list. It stays because that filter is
		// public, and because admin-render.php drives exactly this path.
		// It used to fall through to the form below with $subtab = null, which
		// rendered an empty nav, an empty table and a lone "Save Changes"
		// button that saved nothing: a blank screen with no explanation.
		if ( $subtab === null ) {
			$addons_url = admin_url( 'admin.php?page=perflocale-addons' );
			?>
			<div class="perflocale-addons-wrap perflocale-addons-wrap--empty">
				<p><strong><?php echo esc_html__( 'No addon settings to show yet.', 'perflocale' ); ?></strong></p>
				<p>
					<?php
					if ( $requested !== '' ) {
						printf(
							/* translators: %s: the addon subtab slug the URL asked for, e.g. machine-translation */
							esc_html__( 'This page has no settings for %s. That addon is either turned off or not installed on this site.', 'perflocale' ),
							'<code>' . esc_html( $requested ) . '</code>'
						);
					} else {
						echo esc_html__( 'Settings appear here once an addon that has options is switched on.', 'perflocale' );
					}
					?>
				</p>
				<p>
					<?php echo esc_html__( 'Machine Translation, WooCommerce and any third-party addons are switched on from the Addons screen. Come back here afterwards to configure them.', 'perflocale' ); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $addons_url ); ?>">
						<?php echo esc_html__( 'Go to Addons', 'perflocale' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		// A subtab was named in the URL but is not available — say so instead of
		// silently showing a different one, which reads as "my settings vanished".
		if ( $requested !== '' && $requested !== $subtab ) {
			?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: requested addon subtab slug, 2: label of the subtab shown instead */
						esc_html__( 'There are no settings for %1$s on this site — it is turned off or not installed. Showing %2$s instead.', 'perflocale' ),
						'<code>' . esc_html( $requested ) . '</code>',
						'<strong>' . esc_html( (string) ( $subtabs[ $subtab ] ?? $subtab ) ) . '</strong>'
					);
					?>
				</p>
			</div>
			<?php
		}

		// Status indicators for built-in features.
		$statuses = [
			'machine-translation' => (bool) $this->settings->get( 'mt_enabled', false ),
			'woocommerce'         => class_exists( 'WooCommerce' ),
		];

		$auto_addon_subtabs = $this->get_auto_addon_subtab_ids();
		$is_auto_addon      = isset( $auto_addon_subtabs[ $subtab ] );
		?>
		<div class="perflocale-addons-wrap">
			<nav class="perflocale-addons-nav">
				<ul>
					<?php
					foreach ( $subtabs as $key => $label ) :
						$is_active = ( $key === $subtab );
						$url       = admin_url( 'admin.php?page=perflocale-settings&tab=addons&subtab=' . $key );
						// Auto-addon subtabs reflect registry-booted state; built-ins use the $statuses map.
						$enabled = isset( $auto_addon_subtabs[ $key ] )
							? $this->is_auto_addon_active( $key )
							: ( $statuses[ $key ] ?? false );
						?>
						<li class="perflocale-addons-nav__item<?php echo esc_attr( $is_active ? ' perflocale-addons-nav__item--active' : '' ); ?>">
							<a href="<?php echo esc_url( $url ); ?>">
								<?php echo esc_html( $label ); ?>
								<?php if ( $enabled ) : ?>
									<span class="perflocale-addons-nav__status perflocale-addons-nav__status--on"><?php echo esc_html__( 'Active', 'perflocale' ); ?></span>
								<?php else : ?>
									<span class="perflocale-addons-nav__status"><?php echo esc_html__( 'Inactive', 'perflocale' ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<div class="perflocale-addons-content">
				<?php
				if ( $is_auto_addon ) :
					// Auto-generated addon settings subtab. Renders its own
					// form (admin-post.php → handle_save_addon_settings).
					// Bypasses the perflocale_save_settings wrapper because
					// addon settings live in their own option keyed by
					// addon ID, not in the main settings option.
					$this->render_auto_addon_subtab( $subtab );
				else :
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=addons&subtab=' . $subtab ) ); ?>">
						<?php wp_nonce_field( 'perflocale_save_settings' ); ?>
						<input type="hidden" name="perflocale_settings_tab" value="<?php echo esc_attr( $subtab ); ?>">

						<table class="form-table" role="presentation">
							<?php
							$builtin_subtabs = [ 'machine-translation', 'woocommerce' ];

							match ( $subtab ) {
								'machine-translation' => $this->render_machine_translation_tab(),
								'woocommerce' => $this->delegate_to_wc_addon(),
								default => null,
							};

							// Allow external addons to render their settings UI.
					if ( ! in_array( $subtab, $builtin_subtabs, true ) ) {
						/** @hook perflocale/settings/render_addon_subtab Fires for non-built-in addon subtabs. */
						do_action( 'perflocale/settings/render_addon_subtab', $subtab, $this->settings );
					}
					?>
						</table>

						<?php
						/** @hook perflocale/settings/addon_subtab_after Fires after the form-table, before submit. */
						do_action( 'perflocale/settings/addon_subtab_after', $subtab, $this->settings );
						submit_button();
						?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the WooCommerce settings subtab by delegating to the WC
	 * addon class. The addon owns the entire bespoke UI (pages section,
	 * products section, emails section, per-language exchange-rate
	 * matrix, auto-sync controls, provider-conditional API keys,
	 * sync-now button) — SettingsPage just routes the subtab to it.
	 *
	 * The match() in render_addons_tab() calls this for the
	 * 'woocommerce' subtab. WC isn't part of the auto-addon-subtab
	 * pipeline because its data still lives in `perflocale_settings.wc_*`
	 * keys (read by ExchangeRateSync, EmailTranslation, MultiCurrency,
	 * the REST APIs, etc.) — so it shares the main settings form's
	 * save handler instead of admin-post.php → handle_save_addon_settings.
	 *
	 * Falls back to a "WooCommerce not active" notice when the WC plugin
	 * isn't loaded (the addon class is only available via the bundled
	 * manifest's compat check, which gates on class_exists('WooCommerce')).
	 *
	 * @return void
	 */
	private function delegate_to_wc_addon(): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'addon_registry' ) ) {
			$registry = $plugin->get( 'addon_registry' );
			$addons   = $registry->get_addons();
			$wc       = $addons['woocommerce'] ?? null;

			if ( $wc && method_exists( $wc, 'render_settings_subtab' ) ) {
				$wc->render_settings_subtab( $this->settings );
				return;
			}
		}

		// WC addon isn't registered (WooCommerce plugin not active) —
		// render a "not active" notice in the subtab body.
		?>
		<tr>
			<td colspan="2">
				<div class="notice notice-warning inline" style="margin:8px 0;">
					<p><?php echo esc_html__( 'WooCommerce is not active. Install and activate WooCommerce to use these settings.', 'perflocale' ); ?></p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * True if an auto-addon subtab corresponds to an addon that is
	 * currently booted. Used for the "Active / Inactive" pill in the
	 * subtab nav so disabled / quarantined / unbooted addons surface as
	 * inactive without us having to mirror the registry's state machine.
	 *
	 * @param string $addon_id
	 * @return bool
	 */
	private function is_auto_addon_active( string $addon_id ): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'addon_registry' ) ) {
			return false;
		}

		try {
			return (bool) $plugin->get( 'addon_registry' )->is_booted( $addon_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Render the auto-generated settings form for one addon.
	 *
	 * Form posts to admin-post.php → handle_save_addon_settings, which
	 * routes through {@see \PerfLocale\Addon\AddonSettings::set_addon()}
	 * (size cap, lock, before/after_save hooks). The form is rendered
	 * independently of the parent Settings page's perflocale_save_settings
	 * wrapper because addon settings live in their own option.
	 *
	 * @param string $addon_id
	 * @return void
	 */
	private function render_auto_addon_subtab( string $addon_id ): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'addon_registry' ) ) {
			echo '<p>' . esc_html__( 'Addon registry unavailable.', 'perflocale' ) . '</p>';
			return;
		}

		$registry = $plugin->get( 'addon_registry' );
		$addons   = $registry->get_addons();

		if ( ! isset( $addons[ $addon_id ] ) ) {
			echo '<p>' . esc_html__( 'Unknown addon.', 'perflocale' ) . '</p>';
			return;
		}

		$addon = $addons[ $addon_id ];

		try {
			$fields = (array) $addon->get_settings_fields();
		} catch ( \Throwable $e ) {
			$fields = [];
		}

		// Filter to user-editable fields only; hidden / addon-managed
		// state stays out of the form (the storage layer preserves it
		// untouched on save).
		$editable = [];
		foreach ( $fields as $key => $field ) {
			if ( is_string( $key ) && $key !== '' && is_array( $field )
				&& \PerfLocale\Addon\AddonSettings::is_user_editable_field( $field )
			) {
				$editable[ $key ] = $field;
			}
		}

		$values = \PerfLocale\Addon\AddonSettings::get_addon( $addon_id );

		if ( empty( $editable ) ) {
			echo '<p>' . esc_html__( 'This addon has no user-configurable settings.', 'perflocale' ) . '</p>';
			return;
		}

		\PerfLocale\Addon\AddonSettings::render_form(
			$addon_id,
			$editable,
			$values,
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Render the URL & Routing tab fields.
	 *
	 * @return void
	 */
	private function render_url_routing_tab(): void {
		$url_mode         = $this->settings->get_url_mode();
		$hide_default     = $this->settings->hide_default_prefix();
		$redirect_browser = (bool) $this->settings->get( 'redirect_browser_lang' );
		$redirect_geo     = (bool) $this->settings->get( 'redirect_geo_enabled' );
		// Edge-hint redirect is gated by both its own toggle AND the global
		// edge integration setting (the underlying detect_from_edge_hint()
		// bails when edge integration is off).
		$redirect_edge_hint    = (bool) $this->settings->get( 'redirect_edge_hint_enabled' )
			&& $this->settings->edge_integration_enabled();
		$geo_provider          = (string) $this->settings->get( 'geo_provider', '' );
		$geo_cache_hours       = (int) $this->settings->get( 'geo_cache_hours', 24 );
		$geo_country_map       = (array) $this->settings->get( 'geo_country_map', [] );
		$missing_action        = $this->settings->get_missing_translation_action();
		$cookie_lifetime       = (int) $this->settings->get( 'cookie_lifetime' );
		$excluded_paths        = $this->settings->get_excluded_paths();

		// Inline script: URL mode toggle (domain config show/hide).
		wp_add_inline_script(
			'perflocale-admin',
			'( function() {' .
				'var radios = document.querySelectorAll( \'input[name="url_mode"]\' );' .
				'var config = document.getElementById( \'perflocale-domain-config\' );' .
				'if ( ! radios.length || ! config ) return;' .
				'radios.forEach( function( radio ) {' .
					'radio.addEventListener( \'change\', function() {' .
						'config.style.display = ( this.value === \'subdomain\' || this.value === \'domain\' ) ? \'\' : \'none\';' .
					'} );' .
				'} );' .
			'} )();'
		);

		// Inline script: GeoIP provider toggle.
		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var geo = document.getElementById(\'perflocale-geo-enabled\');' .
				'if(!geo) return;' .
				'function toggle(){' .
					'var on = geo.checked;' .
					'document.querySelectorAll(\'.perflocale-geo-field\').forEach(function(tr){ tr.style.display = on ? \'\' : \'none\'; });' .
				'}' .
				'geo.addEventListener(\'change\', toggle);' .
				'toggle();' .
			'})();'
		);

		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'URL Mode', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<label>
						<input type="radio" name="url_mode" value="subdirectory" <?php checked( $url_mode, 'subdirectory' ); ?>>
						<?php echo esc_html__( 'Subdirectory', 'perflocale' ); ?>
						<span class="description">(example.com/en/page)</span>
					</label><br>
					<label>
						<input type="radio" name="url_mode" value="subdomain" <?php checked( $url_mode, 'subdomain' ); ?>>
						<?php echo esc_html__( 'Subdomain', 'perflocale' ); ?>
						<span class="description">(en.example.com/page)</span>
					</label><br>
					<label>
						<input type="radio" name="url_mode" value="domain" <?php checked( $url_mode, 'domain' ); ?>>
						<?php echo esc_html__( 'Per-language domain', 'perflocale' ); ?>
						<span class="description">(example.fr/page)</span>
					</label><br>
					<label>
						<input type="radio" name="url_mode" value="query" <?php checked( $url_mode, 'query' ); ?>>
						<?php echo esc_html__( 'Query parameter', 'perflocale' ); ?>
						<span class="description">(example.com/page?lang=en)</span>
					</label>
					<p class="description">
						<?php echo esc_html__( 'Query parameter mode works with every permalink structure — including Plain — and every server. The default language always uses clean URLs without the parameter.', 'perflocale' ); ?>
					</p>
					<p class="description" style="margin-top:6px;">
						<?php echo esc_html__( 'Subdirectory needs no DNS work and concentrates SEO value on one hostname; subdomain and domain-per-language require DNS, a TLS certificate, and — on multisite — every language hostname registered as a site.', 'perflocale' ); ?>
						<a href="https://perflocale.com/docs/url-routing/#url-structure" target="_blank" rel="noopener"><?php echo esc_html__( 'Compare the four URL modes', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
					</p>
				</fieldset>

				<?php
				// Domain configuration table - shown for subdomain and domain modes.
				$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
				$all_langs = $lang_repo->get_active();
				$domains   = $this->settings->get_language_domains();
				$base_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';
				?>
				<div id="perflocale-domain-config" style="margin-top:12px;<?php echo esc_attr( $url_mode !== 'subdomain' && $url_mode !== 'domain' ? 'display:none;' : '' ); ?>">
					<p class="description" style="margin-bottom:8px;">
						<?php echo esc_html__( 'Configure the domain or subdomain for each language. DNS must be pointed to this server.', 'perflocale' ); ?>
					</p>
					<table class="widefat fixed" style="max-width:500px;">
						<caption class="screen-reader-text"><?php echo esc_html__( 'Per-language domain mapping.', 'perflocale' ); ?></caption>
						<thead>
							<tr>
								<th scope="col" style="padding-left:8px;"><?php echo esc_html__( 'Language', 'perflocale' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Domain', 'perflocale' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $all_langs as $lang ) :
								$flag    = \PerfLocale\Helper::get_flag_emoji( $lang );
								$default = $domains[ $lang->slug ] ?? '';

								// For subdomain mode, suggest slug.basehost as placeholder.
								$placeholder = $url_mode === 'subdomain'
									? $lang->slug . '.' . $base_host
									: $base_host;
								?>
								<tr>
									<td style="padding-left:8px;"><?php echo esc_html( $flag . ' ' . ( $lang->native_name ?: $lang->name ) ); ?></td>
									<td>
										<input type="text" name="language_domains[<?php echo esc_attr( $lang->slug ); ?>]"
											value="<?php echo esc_attr( $default ); ?>"
											placeholder="<?php echo esc_attr( $placeholder ); ?>"
											class="regular-text" style="width:100%;">
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'URL Prefix Format', 'perflocale' ); ?></th>
			<td>
				<?php $prefix_type = (string) $this->settings->get( 'url_prefix_type', 'slug' ); ?>
				<fieldset>
					<label>
						<input type="radio" name="url_prefix_type" value="slug" <?php checked( $prefix_type, 'slug' ); ?>>
						<?php echo esc_html__( 'Language slug', 'perflocale' ); ?>
						<span class="description">(example.com/<strong>en</strong>/page, example.com/<strong>de</strong>/page)</span>
					</label><br>
					<label>
						<input type="radio" name="url_prefix_type" value="locale" <?php checked( $prefix_type, 'locale' ); ?>>
						<?php echo esc_html__( 'Full locale', 'perflocale' ); ?>
						<span class="description">(example.com/<strong>en-us</strong>/page, example.com/<strong>de-at</strong>/page)</span>
					</label>
				</fieldset>
				<p class="description"><?php echo esc_html__( 'Choose what appears in the URL as the language identifier. "Slug" uses the short code (en, bg), "Locale" uses the full locale (en-us, de-de).', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Default Language URL', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="hide_default_prefix" value="1" <?php checked( $hide_default ); ?>>
					<?php echo esc_html__( 'Hide the language prefix for the default language.', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'When enabled, the default language uses clean URLs without a language prefix.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Browser Language Redirect', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="redirect_browser_lang" value="1" <?php checked( $redirect_browser ); ?>>
					<?php echo esc_html__( 'Redirect first-time visitors to their browser language.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'GeoIP Redirect', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="redirect_geo_enabled" value="1" <?php checked( $redirect_geo ); ?> id="perflocale-geo-enabled">
					<?php echo esc_html__( 'Redirect first-time visitors based on their IP geolocation.', 'perflocale' ); ?>
				</label>
				<p class="description">
				<?php echo esc_html__( 'Uses a GeoIP API to detect the visitor\'s country. When more than one redirect mechanism is enabled, the order set in "Redirect Priority" below decides which runs first.', 'perflocale' ); ?>
				<a href="https://perflocale.com/docs/geo-redirect/#settings" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'Provider comparison & setup', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
			</p>
			</td>
		</tr>

		<?php if ( $this->settings->edge_integration_enabled() ) : ?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Edge Hint Redirect', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="redirect_edge_hint_enabled" value="1" <?php checked( (bool) $this->settings->get( 'redirect_edge_hint_enabled' ) ); ?>>
					<?php echo esc_html__( 'Redirect first-time visitors using a language already chosen at the edge.', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Trusts the X-PerfLocale-Lang header (or perflocale_edge_lang cookie) set by your edge worker. Free, fast alternative to the GeoIP API redirect - the country->language lookup happens at the edge instead of via a third-party API.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<?php endif; ?>

		<?php
		// Redirect-priority editor. The drag-to-reorder UI is shown only when
		// 2+ redirect mechanisms are enabled — with a single mechanism there is
		// nothing to order. When exactly one is on, the saved order is still
		// round-tripped via hidden inputs so re-enabling a second mechanism
		// later restores the admin's chosen order instead of reverting it.
		$enabled_count  = (int) $redirect_browser + (int) $redirect_geo + (int) $redirect_edge_hint;
		$priority_order = $enabled_count > 0 ? $this->settings->get_redirect_priority_order() : [];

		if ( $enabled_count >= 2 ) :
			$priority_methods = [
				'geo'       => [
					'enabled' => $redirect_geo,
					'icon'    => '🌍',
					'label'   => __( 'GeoIP location', 'perflocale' ),
				],
				'browser'   => [
					'enabled' => $redirect_browser,
					'icon'    => '🗣️',
					'label'   => __( 'Browser language', 'perflocale' ),
				],
				'edge_hint' => [
					'enabled' => $redirect_edge_hint,
					'icon'    => '⚡',
					'label'   => __( 'Edge hint', 'perflocale' ),
				],
			];

			// Render chips in saved order, but only those whose mechanism is on.
			$visible_methods = array_values(
				array_filter(
					$priority_order,
					static fn( $m ) => isset( $priority_methods[ $m ] ) && $priority_methods[ $m ]['enabled']
				)
			);
			?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Redirect Priority', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:10px;">
					<?php echo esc_html__( 'Drag to reorder the redirect mechanisms. The first one that triggers a redirect wins (subsequent handlers do not run). Only the mechanisms enabled above appear here.', 'perflocale' ); ?>
				</p>

				<div class="pl-prio-editor"
					role="group"
					aria-label="<?php echo esc_attr__( 'Redirect priority order', 'perflocale' ); ?>">
					<?php
					foreach ( $visible_methods as $idx => $method_key ) :
						$method = $priority_methods[ $method_key ];
						?>
						<div class="pl-prio-chip pl-fb-chip"
							data-method="<?php echo esc_attr( $method_key ); ?>"
							draggable="true"
							tabindex="0"
							aria-roledescription="<?php echo esc_attr__( 'Draggable priority item', 'perflocale' ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: 1: ordinal position, 2: language name or redirect method name */ __( 'Position %1$d: %2$s', 'perflocale' ), $idx + 1, $method['label'] ) ); ?>">
							<span class="pl-fb-chip__grip" aria-hidden="true">⋮⋮</span>
							<span class="pl-fb-chip__pos"><?php echo esc_html( (string) ( $idx + 1 ) ); ?></span>
							<span class="pl-fb-chip__name"><?php echo esc_html( $method['icon'] . ' ' . $method['label'] ); ?></span>
							<input type="hidden" name="redirect_priority_order[]" value="<?php echo esc_attr( $method_key ); ?>">
						</div>
					<?php endforeach; ?>
				</div>
			</td>
		</tr>
		<?php elseif ( $enabled_count === 1 ) : ?>
		<tr class="hidden" aria-hidden="true">
			<td>
				<?php foreach ( $priority_order as $method_key ) : ?>
					<input type="hidden" name="redirect_priority_order[]" value="<?php echo esc_attr( $method_key ); ?>">
				<?php endforeach; ?>
			</td>
		</tr>
		<?php endif; ?>

		<tr class="perflocale-geo-field">
			<th scope="row"><?php echo esc_html__( 'GeoIP Provider', 'perflocale' ); ?></th>
			<td>
				<?php $geo_providers = \PerfLocale\Router\GeoRedirect::get_providers(); ?>
				<?php if ( empty( $geo_providers ) ) : ?>
					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'No geolocation provider is registered. PerfLocale does not bundle one, so no visitor IP is ever sent to a third party unless your site arranges it.', 'perflocale' ); ?>
					</p>
					<p class="description" style="margin-top:6px;">
						<?php echo esc_html__( 'Register a provider with the perflocale/geo/providers filter, or return a country code directly from perflocale/geo/lookup_country - a one-liner when your CDN already sends one, such as Cloudflare CF-IPCountry.', 'perflocale' ); ?>
						<a href="https://perflocale.com/docs/geo-redirect/#geoip-provider" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'Provider examples', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
					</p>
				<?php else : ?>
					<select id="perflocale-geo-provider" name="geo_provider">
						<?php foreach ( $geo_providers as $pid => $pdef ) : ?>
							<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $geo_provider, $pid ); ?>><?php echo esc_html( (string) ( $pdef['name'] ?? $pid ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html__( 'Registered by your site via the perflocale/geo/providers filter.', 'perflocale' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>

		<tr class="perflocale-geo-field">
			<th scope="row">
				<label for="perflocale-geo-cache"><?php echo esc_html__( 'Cache Duration', 'perflocale' ); ?></label>
			</th>
			<td>
				<input type="number" id="perflocale-geo-cache" name="geo_cache_hours" value="<?php echo absint( $geo_cache_hours ); ?>" class="small-text" min="0" max="720"> <?php echo esc_html__( 'hours', 'perflocale' ); ?>
				<p class="description"><?php echo esc_html__( 'How long to cache GeoIP lookups per IP address. Set to 0 to disable caching (every request will query the GeoIP provider).', 'perflocale' ); ?></p>
			</td>
		</tr>
		<?php
		$geo_lang_repo    = new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
		$geo_langs        = $geo_lang_repo->get_active();
		$geo_default      = $geo_lang_repo->get_default();
		$geo_default_slug = is_object( $geo_default ) && ! empty( $geo_default->slug ) ? (string) $geo_default->slug : '';

		// Only render the table when there's at least one non-default
		// active language to map. Single-language sites + the default-only
		// case have nothing to configure here.
		$geo_mappable = array_values( array_filter( $geo_langs, static fn( $l ) => ! empty( $l->slug ) && (string) $l->slug !== $geo_default_slug ) );

		if ( count( $geo_mappable ) >= 1 ) :
			?>
		<tr class="perflocale-geo-field">
			<th scope="row"><?php echo esc_html__( 'Country Mapping', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:8px;"><?php echo esc_html__( 'Map country codes to languages. Comma-separated ISO 3166-1 alpha-2 codes (e.g., US,GB,AU).', 'perflocale' ); ?></p>
				<?php
				// Tailor the note to whether the default language's locale
				// carries a country code. GeoIP only redirects AWAY from the
				// default, so a generic-locale default (ar/zh/es) shouldn't
				// trigger a false "you must map something" alarm.
				$default_label        = is_object( $geo_default ) ? ( $geo_default->native_name ?? $geo_default->name ?? $geo_default_slug ) : $geo_default_slug;
				$default_locale       = is_object( $geo_default ) ? (string) ( $geo_default->locale ?? '' ) : '';
				$default_locale_parts = explode( '_', $default_locale );
				$default_has_country  = isset( $default_locale_parts[1] ) && preg_match( '/^[A-Za-z]{2}$/', $default_locale_parts[1] );

				if ( $default_has_country ) :
					?>
				<p class="description" style="margin-bottom:8px;color:#50575e;">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: default language name (e.g. "English (US)"). */
							__( 'The default language (<strong>%s</strong>) is intentionally not mappable here &mdash; visitors whose country doesn&rsquo;t match any other mapping already stay on the default, so an explicit mapping for it would over-redirect.', 'perflocale' ),
							esc_html( $default_label )
						)
					);
					?>
				</p>
				<?php else : ?>
				<p class="description" style="margin-bottom:8px;color:#50575e;">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: default language name (e.g. "Arabic"), 2: default language locale (e.g. "ar"). */
							__( 'Your default language (<strong>%1$s</strong>, locale <code>%2$s</code>) doesn&rsquo;t carry a country in its locale &mdash; that&rsquo;s fine. Visitors from any country that isn&rsquo;t mapped to a different language above will stay on the default automatically. <strong>You don&rsquo;t need a country code for the default</strong>; the default IS the catch-all.', 'perflocale' ),
							esc_html( $default_label ),
							esc_html( $default_locale !== '' ? $default_locale : $geo_default_slug )
						)
					);
					?>
				</p>
				<?php endif; ?>
				<p class="description" style="margin-bottom:8px;color:#50575e;"><?php echo esc_html__( 'Rows you leave blank are auto-derived from each language\'s locale (e.g. de_DE → DE). Languages without a country in their locale must be filled in explicitly.', 'perflocale' ); ?></p>
				<table class="widefat fixed" style="max-width:500px;">
					<caption class="screen-reader-text"><?php echo esc_html__( 'Accept-Language country codes per language.', 'perflocale' ); ?></caption>
					<thead><tr><th scope="col" style="width:40%;padding-left:8px;"><?php echo esc_html__( 'Language', 'perflocale' ); ?></th><th scope="col"><?php echo esc_html__( 'Country Codes', 'perflocale' ); ?></th></tr></thead>
					<tbody>
					<?php
					foreach ( $geo_mappable as $gl ) :
						$flag      = \PerfLocale\Helper::get_flag_emoji( $gl );
						$saved_val = $geo_country_map[ $gl->slug ] ?? '';

						// Locale-derived suggestion: `en_US` → `US`. Used only
						// as the input PLACEHOLDER (so the user sees what
						// would be auto-derived at runtime if they leave the
						// row blank) — never pre-filled into the value, so
						// the saved option stays small and the auto-derive
						// path is the single source of truth.
						$locale_parts     = explode( '_', (string) ( $gl->locale ?? '' ) );
						$placeholder_code = isset( $locale_parts[1] ) && preg_match( '/^[A-Za-z]{2}$/', $locale_parts[1] )
							? strtoupper( substr( $locale_parts[1], 0, 2 ) )
							: '';
						?>
						<tr>
							<td style="padding-left:8px;"><?php echo esc_html( $flag . ' ' . ( $gl->native_name ?: $gl->name ) ); ?></td>
							<td>
								<input type="text" name="geo_country_map[<?php echo esc_attr( $gl->slug ); ?>]" value="<?php echo esc_attr( $saved_val ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $placeholder_code !== '' ? $placeholder_code : __( 'e.g. SA, AE, EG', 'perflocale' ) ); ?>" pattern="^([A-Za-z]{2})(\s*,\s*[A-Za-z]{2})*$" style="width:100%;">
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php endif; ?>
		<tr>
			<th scope="row">
				<label for="perflocale-missing-action"><?php echo esc_html__( 'Missing Translation', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-missing-action" name="missing_translation_action">
					<option value="show_default" <?php selected( $missing_action, 'show_default' ); ?>><?php echo esc_html__( 'Show content in default language', 'perflocale' ); ?></option>
					<option value="show_404" <?php selected( $missing_action, 'show_404' ); ?>><?php echo esc_html__( 'Show 404 error', 'perflocale' ); ?></option>
					<option value="redirect_default" <?php selected( $missing_action, 'redirect_default' ); ?>><?php echo esc_html__( 'Redirect to default language version', 'perflocale' ); ?></option>
				</select>
				<p class="description" style="margin-top:4px;">
					<a href="https://perflocale.com/docs/language-fallbacks/#seo" target="_blank" rel="noopener"><?php echo esc_html__( 'Compare the three modes (SEO + UX tradeoffs)', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>
		<?php
		// Language Fallback Chain - per-language ordered list of fallback
		// languages. Each list is self-contained (no transitive walking
		// through other languages' own fallback configurations), so the
		// order here is the exact order the walker tries.
		$all_langs = ( new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) ) )->get_active();
		$fallbacks = $this->settings->get_language_fallbacks();

		// Maximum fallbacks per language. Capped at 4 (sensible UI limit),
		// and never more than (total active languages − 1) since the
		// current language itself can't fall back to itself.
		$max_fb = min( 4, max( 1, count( $all_langs ) - 1 ) );

		// Build a quick slug→lang map for pretty labels on prefilled chips.
		$lang_by_slug = [];
		foreach ( $all_langs as $l ) {
			$lang_by_slug[ $l->slug ] = $l;
		}

		if ( count( $all_langs ) > 1 ) :
			?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Language Fallbacks', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:10px;">
					<?php echo esc_html__( 'When a translation is missing, fall back to these languages in order. The first one that has a published translation wins - a single redirect is issued, never a chain of hops. If every slot is empty or unresolved, the "Missing Translation Action" above applies.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/language-fallbacks/#seo" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'Fallback chain docs', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>

<div class="pl-fb-editor" data-max="<?php echo esc_attr( (string) $max_fb ); ?>">
					<?php
					foreach ( $all_langs as $lang ) :
						$flag        = \PerfLocale\Helper::get_flag_emoji( $lang );
						$current_row = array_values( array_filter( (array) ( $fallbacks[ $lang->slug ] ?? [] ), static fn( $s ) => $s !== '' && $s !== $lang->slug ) );
						$current_row = array_slice( $current_row, 0, $max_fb );
						?>
						<div class="pl-fb-row" data-slug="<?php echo esc_attr( $lang->slug ); ?>">
							<div class="pl-fb-row__label">
								<span class="pl-fb-flag"><?php echo esc_html( $flag ); ?></span>
								<span class="pl-fb-row__name"><?php echo esc_html( $lang->native_name ?: $lang->name ); ?></span>
							</div>

							<div class="pl-fb-row__body <?php echo count( $current_row ) >= $max_fb ? 'pl-fb-row__body--full' : ''; ?>"
								role="group"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language name */ __( 'Fallback chain for %s', 'perflocale' ), $lang->native_name ?: $lang->name ) ); ?>">

								<span class="pl-fb-empty"><?php echo esc_html__( 'No fallbacks - uses Missing Translation Action.', 'perflocale' ); ?></span>

								<?php
								foreach ( $current_row as $idx => $fb_slug ) :
									$fb_lang = $lang_by_slug[ $fb_slug ] ?? null;
									if ( ! $fb_lang ) {
										continue; }
									$fb_flag = \PerfLocale\Helper::get_flag_emoji( $fb_lang );
									$fb_name = $fb_lang->native_name ?: $fb_lang->name;
									?>
									<div class="pl-fb-chip" data-slug="<?php echo esc_attr( $fb_slug ); ?>"
										draggable="true"
										tabindex="0"
										aria-roledescription="<?php echo esc_attr__( 'Draggable fallback', 'perflocale' ); ?>"
										aria-label="<?php echo esc_attr( sprintf( /* translators: 1: ordinal position, 2: language name or redirect method name */ __( 'Position %1$d: %2$s', 'perflocale' ), $idx + 1, $fb_name ) ); ?>">
										<span class="pl-fb-chip__grip" aria-hidden="true">⋮⋮</span>
										<span class="pl-fb-chip__pos"><?php echo esc_html( (string) ( $idx + 1 ) ); ?></span>
										<span class="pl-fb-chip__name"><?php echo esc_html( $fb_flag . ' ' . $fb_name ); ?></span>
										<button type="button" class="pl-fb-chip__remove" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language name */ __( 'Remove %s fallback', 'perflocale' ), $fb_name ) ); ?>" title="<?php echo esc_attr__( 'Remove fallback', 'perflocale' ); ?>">&times;</button>
										<input type="hidden" name="language_fallbacks[<?php echo esc_attr( $lang->slug ); ?>][]" value="<?php echo esc_attr( $fb_slug ); ?>">
									</div>
								<?php endforeach; ?>

								<select class="pl-fb-row__picker" aria-label="<?php echo esc_attr__( 'Add fallback language', 'perflocale' ); ?>">
									<option value="">&#43; <?php echo esc_html__( 'Add fallback', 'perflocale' ); ?></option>
									<?php
									foreach ( $all_langs as $opt_lang ) :
										if ( $opt_lang->slug === $lang->slug ) {
											continue; }
										$opt_flag = \PerfLocale\Helper::get_flag_emoji( $opt_lang );
										$opt_name = $opt_lang->native_name ?: $opt_lang->name;
										$already  = in_array( $opt_lang->slug, $current_row, true );
										?>
										<option
											value="<?php echo esc_attr( $opt_lang->slug ); ?>"
											data-flag="<?php echo esc_attr( $opt_flag ); ?>"
											data-name="<?php echo esc_attr( $opt_name ); ?>"
											<?php echo $already ? 'disabled hidden' : ''; ?>
										>
											<?php echo esc_html( $opt_flag . ' ' . $opt_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

			</td>
		</tr>
		<?php endif; ?>
		<tr>
			<th scope="row">
				<label for="perflocale-cookie-lifetime"><?php echo esc_html__( 'Cookie Lifetime', 'perflocale' ); ?></label>
			</th>
			<td>
				<input type="number" id="perflocale-cookie-lifetime" name="cookie_lifetime" value="<?php echo absint( $cookie_lifetime ); ?>" class="small-text" min="1" max="3650"> <?php echo esc_html__( 'days', 'perflocale' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Language Cookie', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="disable_language_cookie" value="1" <?php checked( (bool) $this->settings->get( 'disable_language_cookie' ) ); ?>>
					<?php echo esc_html__( 'Cookieless mode — never set the language-preference cookie (perflocale_lang).', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'For strict GDPR / cookie-consent setups. Language routing keeps working (it is URL-based); you only lose "remember my language" on non-prefixed URLs and the redirect "don\'t ask again" memory. A consent plugin can also gate the cookie via the perflocale/privacy/consent_given filter.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-excluded-paths"><?php echo esc_html__( 'Excluded Paths', 'perflocale' ); ?></label>
			</th>
			<td>
				<textarea id="perflocale-excluded-paths" name="excluded_paths" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", $excluded_paths ) ); ?></textarea>
				<p class="description"><?php echo esc_html__( 'One path per line. These URL paths will bypass language routing.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Merge checkbox sync fields with custom meta keys from textarea.
	 *
	 * @return array<int, string>
	 */
	private function merge_sync_fields(): array {
		// Re-verify the settings-save nonce here so static analysers see a
		// nonce check adjacent to the $_POST reads (Plugin Check /
		// wporg-pluginchecker don't follow block-scope phpcs:disable across
		// long methods or to a caller). The authoritative gate is the
		// nonce + capability check in AdminController::on_admin_init().
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_settings' ) ) {
			return [];
		}

		$checkbox_fields = isset( $_POST['sync_fields'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['sync_fields'] ) )
			: [];

		$custom_meta = isset( $_POST['custom_sync_meta'] )
			? array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['custom_sync_meta'] ) ) ) ) ) )
			: [];

		return array_values( array_unique( array_merge( $checkbox_fields, $custom_meta ) ) );
	}

	/**
	 * Render the Translation tab fields.
	 *
	 * @return void
	 */
	private function render_translation_tab(): void {
		$translatable_pts  = $this->settings->get_translatable_post_types();
		$translatable_taxs = $this->settings->get_translatable_taxonomies();
		$default_status    = (string) $this->settings->get( 'default_translation_status' );
		$auto_stubs        = (bool) $this->settings->get( 'auto_create_stubs' );
		$sync_fields       = (array) $this->settings->get( 'sync_fields' );
		$translate_slugs   = $this->settings->translate_slugs_enabled();

		$all_post_types = get_post_types( [ 'public' => true ], 'objects' );
		$all_taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

		$available_sync_fields = [
			'featured_image' => __( 'Featured Image', 'perflocale' ),
			'menu_order'     => __( 'Menu Order', 'perflocale' ),
			'post_parent'    => __( 'Page Parent', 'perflocale' ),
			'post_date'      => __( 'Publish Date', 'perflocale' ),
			'post_author'    => __( 'Author', 'perflocale' ),
			'comment_status' => __( 'Comment Status', 'perflocale' ),
			'ping_status'    => __( 'Ping Status', 'perflocale' ),
		];

		// Separate custom meta keys from the built-in sync fields.
		$builtin_keys     = array_keys( $available_sync_fields );
		$custom_sync_meta = array_filter( $sync_fields, fn( $f ) => ! in_array( $f, $builtin_keys, true ) );

		// Inline script: Create taxonomy translations AJAX.
		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleTranslationData = ' . wp_json_encode(
				[
					'taxNonce'         => wp_create_nonce( 'perflocale_create_taxonomy_translations' ),
					'postLangNonce'    => wp_create_nonce( 'perflocale_assign_post_languages' ),
					'i18nCreating'     => __( 'Creating taxonomy translations...', 'perflocale' ),
					'i18nAssigning'    => __( 'Assigning default language to unlinked posts...', 'perflocale' ),
					'i18nFailed'       => __( 'Failed', 'perflocale' ),
					'i18nFailedDot'    => __( 'Failed.', 'perflocale' ),
					'i18nDone'         => __( 'Done', 'perflocale' ),
					'i18nTaxonomy'     => __( 'Taxonomy', 'perflocale' ),
					'i18nPostType'     => __( 'Post type', 'perflocale' ),
					'i18nCreated'      => __( 'Created', 'perflocale' ),
					'i18nExisted'      => __( 'Existed', 'perflocale' ),
					'i18nAssigned'     => __( 'Assigned', 'perflocale' ),
					'i18nNetworkError' => __( 'Network error', 'perflocale' ),
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);

		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var btn = document.getElementById(\'perflocale-create-tax-translations\');' .
				'if ( ! btn ) return;' .
				'var d = perflocaleTranslationData;' .
				'btn.addEventListener(\'click\', function() {' .
					'var progress = document.getElementById(\'perflocale-tax-progress\');' .
					'var bar = document.getElementById(\'perflocale-tax-bar\');' .
					'var status = document.getElementById(\'perflocale-tax-status\');' .
					'var percent = document.getElementById(\'perflocale-tax-percent\');' .
					'var result = document.getElementById(\'perflocale-tax-result\');' .
					'btn.disabled = true;' .
					'if ( progress ) progress.style.display = \'block\';' .
					'if ( bar ) { bar.style.width = \'0\'; bar.style.background = \'#2271b1\'; }' .
					'if ( status ) status.textContent = d.i18nCreating;' .
					'if ( percent ) percent.textContent = \'\';' .
					'if ( result ) result.innerHTML = \'\';' .
					'var pct = 0;' .
					'var pInterval = setInterval(function() {' .
						'pct = Math.min(pct + Math.random() * 8, 90);' .
						'if ( bar ) bar.style.width = pct + \'%\';' .
						'if ( percent ) percent.textContent = Math.round(pct) + \'%\';' .
					'}, 400);' .
					'var data = new FormData();' .
					'data.append(\'action\', \'perflocale_create_taxonomy_translations\');' .
					'data.append(\'_nonce\', d.taxNonce);' .
					'fetch(ajaxurl, { method: \'POST\', body: data, credentials: \'same-origin\' })' .
						'.then(function(r) { return r.json(); })' .
						'.then(function(resp) {' .
							'clearInterval(pInterval);' .
							'if ( bar ) bar.style.width = \'100%\';' .
							'if ( percent ) percent.textContent = \'100%\';' .
							'btn.disabled = false;' .
							'if ( ! resp.success ) {' .
								'if ( bar ) bar.style.background = \'#d63638\';' .
								'if ( status ) status.textContent = d.i18nFailed;' .
								'if ( result ) result.innerHTML = \'<p style="color:#d63638;margin:0;">\' + (resp.data && resp.data.message ? resp.data.message : d.i18nFailedDot) + \'</p>\';' .
								'return;' .
							'}' .
							'if ( bar ) bar.style.background = \'#00a32a\';' .
							'if ( status ) status.textContent = resp.data.message || d.i18nDone;' .
							'var details = resp.data.taxonomy_details;' .
							'if ( details && details.length ) {' .
								'var html = \'<table class="widefat striped" style="max-width:420px;margin-top:6px;">\';' .
								'html += \'<thead><tr><th style="padding:6px 8px;">\' + d.i18nTaxonomy + \'</th>\';' .
								'html += \'<th style="padding:6px 8px;text-align:center;">\' + d.i18nCreated + \'</th>\';' .
								'html += \'<th style="padding:6px 8px;text-align:center;">\' + d.i18nExisted + \'</th></tr></thead><tbody>\';' .
								'details.forEach(function(row) {' .
									'html += \'<tr><td style="padding:4px 8px;">\' + row.taxonomy + \'</td>\';' .
									'html += \'<td style="padding:4px 8px;text-align:center;color:#00a32a;font-weight:500;">\' + row.created + \'</td>\';' .
									'html += \'<td style="padding:4px 8px;text-align:center;color:#6b7280;">\' + row.skipped + \'</td></tr>\';' .
								'});' .
								'html += \'</tbody></table>\';' .
								'if ( result ) result.innerHTML = html;' .
							'}' .
						'})' .
						'.catch(function() {' .
							'clearInterval(pInterval);' .
							'if ( bar ) { bar.style.width = \'100%\'; bar.style.background = \'#d63638\'; }' .
							'if ( status ) status.textContent = d.i18nNetworkError;' .
							'if ( percent ) percent.textContent = \'\';' .
							'btn.disabled = false;' .
						'});' .
				'});' .
			'})();'
		);

		// Inline script: Assign default language to unlinked posts/pages.
		// Same progress-bar pattern as taxonomy button but wires a different
		// AJAX action + UI nodes.
		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var btn = document.getElementById(\'perflocale-assign-post-langs\');' .
				'if ( ! btn ) return;' .
				'var d = perflocaleTranslationData;' .
				'btn.addEventListener(\'click\', function() {' .
					'var progress = document.getElementById(\'perflocale-postlang-progress\');' .
					'var bar = document.getElementById(\'perflocale-postlang-bar\');' .
					'var status = document.getElementById(\'perflocale-postlang-status\');' .
					'var percent = document.getElementById(\'perflocale-postlang-percent\');' .
					'var result = document.getElementById(\'perflocale-postlang-result\');' .
					'btn.disabled = true;' .
					'if ( progress ) progress.style.display = \'block\';' .
					'if ( bar ) { bar.style.width = \'0\'; bar.style.background = \'#2271b1\'; }' .
					'if ( status ) status.textContent = d.i18nAssigning;' .
					'if ( percent ) percent.textContent = \'\';' .
					'if ( result ) result.innerHTML = \'\';' .
					'var pct = 0;' .
					'var pInterval = setInterval(function() {' .
						'pct = Math.min(pct + Math.random() * 10, 90);' .
						'if ( bar ) bar.style.width = pct + \'%\';' .
						'if ( percent ) percent.textContent = Math.round(pct) + \'%\';' .
					'}, 400);' .
					'var data = new FormData();' .
					'data.append(\'action\', \'perflocale_assign_post_languages\');' .
					'data.append(\'_nonce\', d.postLangNonce);' .
					'fetch(ajaxurl, { method: \'POST\', body: data, credentials: \'same-origin\' })' .
						'.then(function(r) { return r.json(); })' .
						'.then(function(resp) {' .
							'clearInterval(pInterval);' .
							'if ( bar ) bar.style.width = \'100%\';' .
							'if ( percent ) percent.textContent = \'100%\';' .
							'btn.disabled = false;' .
							'if ( ! resp.success ) {' .
								'if ( bar ) bar.style.background = \'#d63638\';' .
								'if ( status ) status.textContent = d.i18nFailed;' .
								'if ( result ) result.innerHTML = \'<p style="color:#d63638;margin:0;">\' + (resp.data && resp.data.message ? resp.data.message : d.i18nFailedDot) + \'</p>\';' .
								'return;' .
							'}' .
							'if ( bar ) bar.style.background = \'#00a32a\';' .
							'if ( status ) status.textContent = resp.data.message || d.i18nDone;' .
							'var details = resp.data.post_type_details;' .
							'if ( details && details.length ) {' .
								'var html = \'<table class="widefat striped" style="max-width:420px;margin-top:6px;">\';' .
								'html += \'<thead><tr><th style="padding:6px 8px;">\' + d.i18nPostType + \'</th>\';' .
								'html += \'<th style="padding:6px 8px;text-align:center;">\' + d.i18nAssigned + \'</th></tr></thead><tbody>\';' .
								'details.forEach(function(row) {' .
									'html += \'<tr><td style="padding:4px 8px;">\' + row.post_type + \'</td>\';' .
									'html += \'<td style="padding:4px 8px;text-align:center;color:#00a32a;font-weight:500;">\' + row.assigned + \'</td></tr>\';' .
								'});' .
								'html += \'</tbody></table>\';' .
								'if ( result ) result.innerHTML = html;' .
							'}' .
						'})' .
						'.catch(function() {' .
							'clearInterval(pInterval);' .
							'if ( bar ) { bar.style.width = \'100%\'; bar.style.background = \'#d63638\'; }' .
							'if ( status ) status.textContent = d.i18nNetworkError;' .
							'if ( percent ) percent.textContent = \'\';' .
							'btn.disabled = false;' .
						'});' .
				'});' .
			'})();'
		);

		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Translatable Post Types', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $all_post_types as $pt ) : ?>
						<label>
							<input type="checkbox" name="translatable_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $translatable_pts, true ) ); ?>>
							<?php echo esc_html( $pt->labels->name ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Translatable Taxonomies', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $all_taxonomies as $tax ) : ?>
						<label>
							<input type="checkbox" name="translatable_taxonomies[]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( in_array( $tax->name, $translatable_taxs, true ) ); ?>>
							<?php echo esc_html( $tax->labels->name ); ?> <code>(<?php echo esc_html( $tax->name ); ?>)</code>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-default-status"><?php echo esc_html__( 'Default Translation Status', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-default-status" name="default_translation_status">
					<option value="empty" <?php selected( $default_status, 'empty' ); ?>><?php echo esc_html__( 'Empty', 'perflocale' ); ?></option>
					<option value="draft" <?php selected( $default_status, 'draft' ); ?>><?php echo esc_html__( 'Draft', 'perflocale' ); ?></option>
					<option value="pending" <?php selected( $default_status, 'pending' ); ?>><?php echo esc_html__( 'Pending Review', 'perflocale' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Auto-Create Stubs', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="auto_create_stubs" value="1" <?php checked( $auto_stubs ); ?>>
					<?php echo esc_html__( 'Automatically create empty translation stubs when content is published.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Sync Fields', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $available_sync_fields as $field_key => $field_label ) : ?>
						<label>
							<input type="checkbox" name="sync_fields[]" value="<?php echo esc_attr( $field_key ); ?>" <?php checked( in_array( $field_key, $sync_fields, true ) ); ?>>
							<?php echo esc_html( $field_label ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php echo esc_html__( 'These fields will be kept in sync across all translations.', 'perflocale' ); ?></p>
				<br>
				<label for="perflocale-custom-sync-meta"><strong><?php echo esc_html__( 'Custom Meta Keys', 'perflocale' ); ?></strong></label>
				<textarea id="perflocale-custom-sync-meta" name="custom_sync_meta" rows="3" class="large-text code" placeholder="<?php echo esc_attr__( 'my_custom_meta_key', 'perflocale' ); ?>"><?php echo esc_textarea( implode( "\n", $custom_sync_meta ) ); ?></textarea>
				<p class="description"><?php echo esc_html__( 'One meta key per line. These custom fields will be synced across translations (e.g., ACF fields, WooCommerce fields).', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Sync Term Hierarchy', 'perflocale' ); ?></th>
			<td>
				<?php $sync_term_hierarchy = (bool) $this->settings->get( 'sync_term_hierarchy', true ); ?>
				<label>
					<input type="checkbox" name="sync_term_hierarchy" value="1" <?php checked( $sync_term_hierarchy ); ?>>
					<?php echo esc_html__( 'Keep category/term parent structure identical across languages (parents mapped to each language\'s own translated terms).', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Uncheck to allow deliberately different category trees per language — e.g. a smaller, flatter catalog in one market. Term names, slugs, and descriptions are always translator-owned.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Translate Slugs', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="translate_slugs" value="1" <?php checked( $translate_slugs ); ?>>
					<?php echo esc_html__( 'Allow post and term slugs to be translated per language.', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Term archives use the clean per-language slug PerfLocale records — German stays at /de/tutorials/ rather than the unique tutorials-de the database needed — but the plugin does not translate slugs for you.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/url-routing/#translated-slugs" target="_blank" rel="noopener"><?php echo esc_html__( 'How display slugs work', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Bulk Translation', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Taxonomy Translations', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin:0 0 8px;">
					<?php echo esc_html__( 'Create translation stubs for all terms in your translatable taxonomies (categories, tags, etc.) across all active languages. Term names will be translated using local WordPress translations first, then machine translation if enabled. Existing translations will not be affected.', 'perflocale' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:8px;">
					<button type="button" class="button" id="perflocale-create-tax-translations">
						<?php echo esc_html__( 'Create Taxonomy Translations', 'perflocale' ); ?>
					</button>
				</div>
				<div id="perflocale-tax-progress" style="display:none;margin-top:10px;max-width:420px;">
					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
						<span id="perflocale-tax-status" style="font-size:13px;color:#50575e;"></span>
						<span id="perflocale-tax-percent" style="font-size:12px;color:#50575e;font-weight:500;"></span>
					</div>
					<div style="width:100%;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
						<div id="perflocale-tax-bar" style="width:0;height:100%;background:#2271b1;border-radius:4px;transition:width 0.3s ease;"></div>
					</div>
				</div>
				<div id="perflocale-tax-result" style="margin-top:8px;"></div>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Assign Default Language', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin:0 0 8px;">
					<?php echo esc_html__( 'Link every translatable post/page/product that currently has no language assigned to the site\'s default language. Posts that already have a language assigned will not be touched. Use this once after installing PerfLocale on a pre-existing site so language-aware filters (nav-menu pickers, category checklists) stop showing unmanaged content in every language.', 'perflocale' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:8px;">
					<button type="button" class="button" id="perflocale-assign-post-langs">
						<?php echo esc_html__( 'Assign Default Language to Unlinked Posts', 'perflocale' ); ?>
					</button>
				</div>
				<div id="perflocale-postlang-progress" style="display:none;margin-top:10px;max-width:420px;">
					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
						<span id="perflocale-postlang-status" style="font-size:13px;color:#50575e;"></span>
						<span id="perflocale-postlang-percent" style="font-size:12px;color:#50575e;font-weight:500;"></span>
					</div>
					<div style="width:100%;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
						<div id="perflocale-postlang-bar" style="width:0;height:100%;background:#2271b1;border-radius:4px;transition:width 0.3s ease;"></div>
					</div>
				</div>
				<div id="perflocale-postlang-result" style="margin-top:8px;"></div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the Machine Translation tab fields.
	 *
	 * @return void
	 */
	private function render_machine_translation_tab(): void {
		$mt_enabled = $this->settings->mt_enabled();
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Machine Translation', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="mt_enabled" value="1" <?php checked( $mt_enabled ); ?>>
					<?php echo esc_html__( 'Enable machine translation features', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Translate content using DeepL, Google Translate, Microsoft Translator, or LibreTranslate. Includes auto-translate on publish/create, bulk translation and monthly usage limits. Disabled by default to keep the plugin lightweight.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<?php

		if ( ! $mt_enabled ) {
			return;
		}

		$mt_provider  = $this->settings->get_mt_provider();
		$auto_publish = (bool) $this->settings->get( 'mt_auto_translate_on_publish' );
		$char_limit   = (int) $this->settings->get( 'mt_monthly_char_limit' );

		$deepl_key       = (string) $this->settings->get( 'mt_deepl_api_key' );
		$deepl_formality = (string) $this->settings->get( 'mt_deepl_formality' );
		$google_key      = (string) $this->settings->get( 'mt_google_api_key' );
		$ms_key          = (string) $this->settings->get( 'mt_microsoft_api_key' );
		$ms_region       = (string) $this->settings->get( 'mt_microsoft_region' );
		$libre_url       = (string) $this->settings->get( 'mt_libre_url' );
		$libre_key       = (string) $this->settings->get( 'mt_libre_api_key' );
		$agency_url      = (string) $this->settings->get( 'mt_agency_url' );
		$agency_key      = (string) $this->settings->get( 'mt_agency_api_key' );
		?>
		<tr>
			<th scope="row">
				<label for="perflocale-mt-provider"><?php echo esc_html__( 'Provider', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-mt-provider" name="mt_provider">
					<option value="" <?php selected( $mt_provider, '' ); ?>><?php echo esc_html__( '-- Select --', 'perflocale' ); ?></option>
					<option value="deepl" <?php selected( $mt_provider, 'deepl' ); ?>><?php echo esc_html__( 'DeepL', 'perflocale' ); ?></option>
					<option value="google" <?php selected( $mt_provider, 'google' ); ?>><?php echo esc_html__( 'Google Translate', 'perflocale' ); ?></option>
					<option value="microsoft" <?php selected( $mt_provider, 'microsoft' ); ?>><?php echo esc_html__( 'Microsoft Translator', 'perflocale' ); ?></option>
					<option value="libretranslate" <?php selected( $mt_provider, 'libretranslate' ); ?>><?php echo esc_html__( 'LibreTranslate', 'perflocale' ); ?></option>
					<option value="external_agency" <?php selected( $mt_provider, 'external_agency' ); ?>><?php echo esc_html__( 'External Agency', 'perflocale' ); ?></option>
					<?php
					// WP 7.0 AI Client option — show only when the host has the API
					// loaded (or has plugged in a custom resolver via
					// perflocale/mt/wp_ai_client_resolver). Falls back to listing it
					// disabled so users on older WP versions can see the option but
					// not select it (avoids "where did it go?" support churn).
					// Detection mirrors WpAiClientProvider::resolve_client_callback().
					$supports_fn         = 'wp_supports_ai'; // Called by name: optional WP 7.0 fn, guarded.
					$ai_client_available = (
						function_exists( 'wp_ai_client_prompt' )
						&& ( ! function_exists( $supports_fn ) || $supports_fn() )
					) || is_callable( apply_filters( 'perflocale/mt/wp_ai_client_resolver', null ) );
					if ( $ai_client_available || $mt_provider === 'wp_ai_client' ) :
						?>
						<option value="wp_ai_client" <?php selected( $mt_provider, 'wp_ai_client' ); ?>><?php echo esc_html__( 'WordPress AI Client (WP 7.0+)', 'perflocale' ); ?></option>
					<?php else : ?>
						<option value="wp_ai_client" disabled><?php echo esc_html__( 'WordPress AI Client (requires WP 7.0+ with AI Client API)', 'perflocale' ); ?></option>
					<?php endif; ?>
				</select>
				<p class="description" style="margin-top:6px;">
					<a href="https://perflocale.com/docs/machine-translation/#providers" target="_blank" rel="noopener"><?php echo esc_html__( 'Compare providers', 'perflocale' ); ?></a>
					<span style="color:#c3c4c7;"> · </span>
					<a href="https://perflocale.com/docs/api-key-constants/" target="_blank" rel="noopener"><?php echo esc_html__( 'Keep API keys out of the database instead', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Auto-Translate on Publish', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="mt_auto_translate_on_publish" value="1" <?php checked( $auto_publish ); ?>>
					<?php echo esc_html__( 'Automatically machine-translate content when it is published.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Auto-Translate on Create', 'perflocale' ); ?></th>
			<td>
				<?php $auto_create = (bool) $this->settings->get( 'mt_auto_translate_on_create' ); ?>
				<label>
					<input type="checkbox" name="mt_auto_translate_on_create" value="1" <?php checked( $auto_create ); ?>>
					<?php echo esc_html__( 'Automatically translate title, content, and excerpt when creating a new translation.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Auto-Translate Target Languages', 'perflocale' ); ?></th>
			<td>
				<?php
				$auto_langs = (array) $this->settings->get( 'mt_auto_translate_languages', [] );
				$router     = \PerfLocale\Plugin::get_instance()->get( 'router' );
				$default_l  = $router->get_default_language();
				?>
				<fieldset>
					<?php foreach ( $router->get_active_languages() as $al ) : ?>
						<?php
						if ( $default_l && $al->slug === $default_l->slug ) {
							continue; // The default language is the MT source, never a target.
						}
						$checked = ( $auto_langs === [] || in_array( (string) $al->slug, $auto_langs, true ) );
						?>
						<label style="display:inline-block;margin:2px 14px 2px 0;">
							<input type="checkbox" name="mt_auto_translate_languages[]" value="<?php echo esc_attr( (string) $al->slug ); ?>" <?php checked( $checked ); ?>>
							<?php echo esc_html( (string) ( $al->native_name ?: $al->name ?: $al->slug ) ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">
					<?php echo esc_html__( 'Both auto-translate flows only machine-translate into the checked languages; unchecked languages get clean, empty stubs for translators working from scratch. Checking every language keeps new languages included automatically; unchecking every language turns auto-translate off for all of them.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Bulk-Translate Strings', 'perflocale' ); ?></th>
			<td>
				<?php $bulk_strings_enabled = (bool) $this->settings->get( 'mt_bulk_strings_enabled', true ); ?>
				<label>
					<input type="checkbox" name="mt_bulk_strings_enabled" value="1" <?php checked( $bulk_strings_enabled ); ?>>
					<?php echo esc_html__( 'Show the bulk MT-translate toolbar on the Strings admin page.', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Lets translators MT-translate selected, filtered, or every string in a single dispatch (up to 5,000 string × target pairs per run). Disable to keep MT available for per-row edits only — useful when controlling provider costs.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Custom Fields', 'perflocale' ); ?></th>
			<td>
				<?php $mt_meta_custom_fields = (bool) $this->settings->get( 'mt_meta_custom_fields', false ); ?>
				<label>
					<input type="checkbox" name="mt_meta_custom_fields" value="1" <?php checked( $mt_meta_custom_fields ); ?>>
					<?php echo esc_html__( 'Machine-translate custom field values from ACF, Meta Box and Pods.', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Off by default: field values are often names, SKUs, URLs or numbers that must not be translated. Turn it on only when your custom fields hold prose. Fields the addons exclude are still skipped.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-mt-char-limit"><?php echo esc_html__( 'Monthly Character Limit', 'perflocale' ); ?></label>
			</th>
			<td>
				<input type="number" id="perflocale-mt-char-limit" name="mt_monthly_char_limit" value="<?php echo absint( $char_limit ); ?>" class="regular-text" min="0" step="1000">
				<p class="description"><?php echo esc_html__( 'Maximum characters to send to the translation API per month. Set to 0 for unlimited.', 'perflocale' ); ?></p>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'This is the only ceiling on what your translation provider can bill you in a month — 0 means unlimited, which with auto-translate on publish leaves the spend uncapped.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/machine-translation/#char-limit" target="_blank" rel="noopener"><?php echo esc_html__( 'What happens when the cap is reached', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3><?php echo esc_html__( 'DeepL Settings', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-deepl-key"><?php echo esc_html__( 'DeepL API Key', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_deepl_api_key', 'perflocale-deepl-key', $deepl_key ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-deepl-formality"><?php echo esc_html__( 'DeepL Formality', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-deepl-formality" name="mt_deepl_formality">
					<option value="default" <?php selected( $deepl_formality, 'default' ); ?>><?php echo esc_html__( 'Default', 'perflocale' ); ?></option>
					<option value="more" <?php selected( $deepl_formality, 'more' ); ?>><?php echo esc_html__( 'More formal', 'perflocale' ); ?></option>
					<option value="less" <?php selected( $deepl_formality, 'less' ); ?>><?php echo esc_html__( 'Less formal', 'perflocale' ); ?></option>
					<option value="prefer_more" <?php selected( $deepl_formality, 'prefer_more' ); ?>><?php echo esc_html__( 'Prefer more formal', 'perflocale' ); ?></option>
					<option value="prefer_less" <?php selected( $deepl_formality, 'prefer_less' ); ?>><?php echo esc_html__( 'Prefer less formal', 'perflocale' ); ?></option>
				</select>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3><?php echo esc_html__( 'Google Translate Settings', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-google-key"><?php echo esc_html__( 'Google API Key', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_google_api_key', 'perflocale-google-key', $google_key ); ?>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3><?php echo esc_html__( 'Microsoft Translator Settings', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-ms-key"><?php echo esc_html__( 'Microsoft API Key', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_microsoft_api_key', 'perflocale-ms-key', $ms_key ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-ms-region"><?php echo esc_html__( 'Microsoft Region', 'perflocale' ); ?></label>
			</th>
			<td>
				<input type="text" id="perflocale-ms-region" name="mt_microsoft_region" value="<?php echo esc_attr( $ms_region ); ?>" class="regular-text">
				<p class="description"><?php echo esc_html__( 'Azure region for the Translator resource (e.g., "global", "eastus").', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3><?php echo esc_html__( 'LibreTranslate Settings', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-libre-url"><?php echo esc_html__( 'LibreTranslate URL', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_libre_url', 'perflocale-libre-url', $libre_url, 'url' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-libre-key"><?php echo esc_html__( 'LibreTranslate API Key', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_libre_api_key', 'perflocale-libre-key', $libre_key ); ?>
			</td>
		</tr>
		<tr>
			<td colspan="2"><h3><?php echo esc_html__( 'External Agency Settings', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-agency-url"><?php echo esc_html__( 'Agency Endpoint URL', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_agency_url', 'perflocale-agency-url', $agency_url, 'url' ); ?>
				<p class="description"><?php echo esc_html__( 'HTTPS URL of the external translation agency endpoint.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-agency-key"><?php echo esc_html__( 'Agency API Key', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $this->render_api_key_field( 'mt_agency_api_key', 'perflocale-agency-key', $agency_key ); ?>
				<p class="description"><?php echo esc_html__( 'Optional. Sent as a Bearer token in the Authorization header.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the SEO tab fields.
	 *
	 * @return void
	 */
	private function render_seo_tab(): void {
		$hreflang_enabled   = $this->settings->hreflang_enabled();
		$hreflang_placement = (string) $this->settings->get( 'seo_hreflang_placement' );
		$x_default          = (bool) $this->settings->get( 'seo_x_default' );
		$include_fallbacks  = (bool) $this->settings->get( 'seo_hreflang_include_fallbacks' );
		$sitemap_enabled    = (bool) $this->settings->get( 'seo_sitemap_enabled' );
		$seo_plugin         = (string) $this->settings->get( 'seo_plugin' );
		$og_locale          = (bool) $this->settings->get( 'seo_og_locale' );
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Hreflang Tags', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="seo_hreflang_enabled" value="1" <?php checked( $hreflang_enabled ); ?>>
					<?php echo esc_html__( 'Output hreflang tags for multilingual pages.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-hreflang-placement"><?php echo esc_html__( 'Hreflang Placement', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-hreflang-placement" name="seo_hreflang_placement">
					<option value="head" <?php selected( $hreflang_placement, 'head' ); ?>><?php echo esc_html__( 'HTML Head', 'perflocale' ); ?></option>
					<option value="http_header" <?php selected( $hreflang_placement, 'http_header' ); ?>><?php echo esc_html__( 'HTTP Header', 'perflocale' ); ?></option>
					<option value="both" <?php selected( $hreflang_placement, 'both' ); ?>><?php echo esc_html__( 'Both', 'perflocale' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'x-default Tag', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="seo_x_default" value="1" <?php checked( $x_default ); ?>>
					<?php echo esc_html__( 'Include an x-default hreflang tag pointing to the default language.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Include Fallback Languages', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="seo_hreflang_include_fallbacks" value="1" <?php checked( $include_fallbacks ); ?>>
					<?php echo esc_html__( 'List every active language in hreflang - even ones where the post has no translation yet.', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Off (default): only languages that have a real translation are listed. Turn on if you show the original content when a translation is missing - search engines will then also see those URLs as available.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Multilingual Sitemap', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="seo_sitemap_enabled" value="1" <?php checked( $sitemap_enabled ); ?>>
					<?php echo esc_html__( 'Generate language-aware XML sitemaps.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-seo-plugin"><?php echo esc_html__( 'SEO Plugin Integration', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-seo-plugin" name="seo_plugin">
					<option value="none" <?php selected( $seo_plugin, 'none' ); ?>><?php echo esc_html__( 'None', 'perflocale' ); ?></option>
					<option value="yoast" <?php selected( $seo_plugin, 'yoast' ); ?>><?php echo esc_html__( 'Yoast SEO', 'perflocale' ); ?></option>
					<option value="rankmath" <?php selected( $seo_plugin, 'rankmath' ); ?>><?php echo esc_html__( 'Rank Math', 'perflocale' ); ?></option>
					<option value="aioseo" <?php selected( $seo_plugin, 'aioseo' ); ?>><?php echo esc_html__( 'All in One SEO', 'perflocale' ); ?></option>
					<option value="seopress" <?php selected( $seo_plugin, 'seopress' ); ?>><?php echo esc_html__( 'SEOPress', 'perflocale' ); ?></option>
					<option value="theseoframework" <?php selected( $seo_plugin, 'theseoframework' ); ?>><?php echo esc_html__( 'The SEO Framework', 'perflocale' ); ?></option>
					<option value="slimseo" <?php selected( $seo_plugin, 'slimseo' ); ?>><?php echo esc_html__( 'Slim SEO', 'perflocale' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Open Graph Locale', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="seo_og_locale" value="1" <?php checked( $og_locale ); ?>>
					<?php echo esc_html__( 'Localize the <html lang> attribute and add og:locale:alternate tags for translated versions when an SEO plugin is active. (Your SEO plugin already outputs the page\'s own og:locale in the right language automatically.)', 'perflocale' ); ?>
				</label>
			</td>
		</tr>

		<?php
		$schema_on           = (bool) $this->settings->get( 'seo_schema_enrichment_enabled' );
		$content_language_on = (bool) $this->settings->get( 'content_language_header', true );
		$fallback_nosnip_on  = (bool) $this->settings->get( 'fallback_nosnippet', true );
		$prerender_on        = (bool) $this->settings->get( 'prerender_on_hover', false );
		$vt_on               = (bool) $this->settings->get( 'view_transitions_enabled', false );
		?>

		<tr>
			<th scope="row"><?php echo esc_html__( 'Schema (JSON-LD) Enrichment', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="seo_schema_enrichment_enabled" value="1" <?php checked( $schema_on ); ?>>
					<?php echo esc_html__( 'Tell your SEO plugin which language the page is in and link translated versions together.', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Adds two small pieces of structured data to the SEO plugin you already use (Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, The SEO Framework): the current page\'s language, and pointers to its translations. Helps Google show the right language in search, and helps AI crawlers understand your site. Recommended.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/seo-schema/#what-gets-added" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'Schema enrichment docs', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row" colspan="2" style="padding-top:24px; border-top:1px solid #dcdcde;">
				<h3 style="margin:0 0 4px;"><?php echo esc_html__( 'Modern SEO & UX', 'perflocale' ); ?></h3>
				<p class="description" style="font-weight:normal;">
					<?php echo esc_html__( 'Optional extras for faster indexing and smoother switching. Safe to use alongside your existing SEO plugin.', 'perflocale' ); ?>
				</p>
			</th>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Content-Language Header', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="content_language_header" value="1" <?php checked( $content_language_on ); ?>>
					<?php echo esc_html__( 'Tell search engines the page\'s language in the HTTP response (on top of the usual <html lang> attribute).', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Adds a standard "Content-Language" header (e.g. de-DE) that Google, Yandex, and caches read. Harmless to leave on - recommended.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php echo esc_html__( 'Clean Snippets on Fallback Pages', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="fallback_nosnippet" value="1" <?php checked( $fallback_nosnip_on ); ?>>
					<?php echo esc_html__( 'When a translation is missing and the original is shown instead, hide that text from Google\'s search snippets.', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Only relevant if "Missing translation action" is set to "Show default language". Without this, the German /de/ page could show English text in the Google snippet - confusing for visitors. The page stays indexed; only the snippet is suppressed.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php echo esc_html__( 'Instant Language Switching', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="prerender_on_hover" value="1" <?php checked( $prerender_on ); ?>>
					<?php echo esc_html__( 'Pre-load the target translation while the user is hovering the language switcher (experimental).', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'The switch feels instant because the other language is already fetched. Works in Chrome, Edge, and other Chromium-based browsers; ignored safely elsewhere. Only kicks in on hover/focus, so it won\'t waste bandwidth for visitors who don\'t switch.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php echo esc_html__( 'Smooth Switch Animation', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="view_transitions_enabled" value="1" <?php checked( $vt_on ); ?>>
					<?php echo esc_html__( 'Crossfade between the old and new page when switching languages - no white flash (experimental).', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Works in Chrome 126+ and Safari 18.2+; other browsers just switch normally. If your theme already does its own page-transition animations (sliders, intro effects), test before turning this on - they can occasionally clash.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>

		<?php
	}

	/**
	 * Render the Language Switcher tab fields.
	 *
	 * @return void
	 */
	private function render_language_switcher_tab(): void {
		$template          = (string) $this->settings->get( 'switcher_template' );
		$flag_style        = (string) $this->settings->get( 'switcher_flag_style' );
		$show_untranslated = (bool) $this->settings->get( 'switcher_show_untranslated' );
		$hide_current      = (bool) $this->settings->get( 'switcher_hide_current' );
		$untranslated_link = (string) $this->settings->get( 'switcher_untranslated_link' );
		$arrow_style       = (string) $this->settings->get( 'switcher_arrow_style' );
		$trigger_format    = (string) $this->settings->get( 'switcher_trigger_format' );
		$auto_insert       = (bool) $this->settings->get( 'switcher_auto_insert', true );
		// Stored verbatim (case-sensitive) — nav-menu location slugs can be
		// mixed-case; lowercasing here would break the checked() match.
		$menu_locations    = array_map( 'strval', (array) $this->settings->get( 'switcher_menu_locations', [] ) );
		?>
		<tr>
			<td colspan="2" style="padding: 0;">
				<div class="perflocale-switcher-info">
					<div class="perflocale-switcher-info__header">
						<span class="dashicons dashicons-info-outline"></span>
						<strong><?php echo esc_html__( 'These are global defaults', 'perflocale' ); ?></strong>
					</div>
					<p><?php echo esc_html__( 'The settings below define the default appearance when no per-instance options are set. You can place the language switcher using any of these methods:', 'perflocale' ); ?></p>
					<div class="perflocale-switcher-info__methods">
						<div class="perflocale-switcher-info__method">
							<span class="dashicons dashicons-block-default"></span>
							<div>
								<strong><?php echo esc_html__( 'Gutenberg Block', 'perflocale' ); ?></strong>
								<span><?php echo esc_html__( 'Search "Language Switcher" in the block inserter. Full settings in the sidebar.', 'perflocale' ); ?></span>
							</div>
						</div>
						<div class="perflocale-switcher-info__method">
							<span class="dashicons dashicons-admin-customizer"></span>
							<div>
								<strong><?php echo esc_html__( 'Customizer', 'perflocale' ); ?></strong>
								<span><?php echo esc_html__( 'Add the Language Switcher widget to any sidebar, header, or footer area.', 'perflocale' ); ?></span>
							</div>
						</div>
						<div class="perflocale-switcher-info__method">
							<span class="dashicons dashicons-shortcode"></span>
							<div>
								<strong><?php echo esc_html__( 'Shortcode', 'perflocale' ); ?></strong>
								<span><?php echo esc_html__( 'Paste this shortcode into any post, page, or widget:', 'perflocale' ); ?></span>
								<code class="perflocale-switcher-info__code" role="button" tabindex="0" data-perflocale-copy="[perflocale_switcher]" aria-label="<?php echo esc_attr__( 'Copy shortcode to clipboard', 'perflocale' ); ?>" title="<?php echo esc_attr__( 'Click to copy', 'perflocale' ); ?>">[perflocale_switcher]</code>
							</div>
						</div>
						<div class="perflocale-switcher-info__method">
							<span class="dashicons dashicons-editor-code"></span>
							<div>
								<strong><?php echo esc_html__( 'PHP Template Tag', 'perflocale' ); ?></strong>
								<span><?php echo esc_html__( 'Use in your theme templates:', 'perflocale' ); ?></span>
								<code class="perflocale-switcher-info__code" role="button" tabindex="0" data-perflocale-copy="&lt;?php perflocale_language_switcher(); ?&gt;" aria-label="<?php echo esc_attr__( 'Copy PHP template tag to clipboard', 'perflocale' ); ?>" title="<?php echo esc_attr__( 'Click to copy', 'perflocale' ); ?>">&lt;?php perflocale_language_switcher(); ?&gt;</code>
							</div>
						</div>
					</div>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-switcher-template"><?php echo esc_html__( 'Switcher Style', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-switcher-template" name="switcher_template">
					<option value="flags_names" <?php selected( $template, 'flags_names' ); ?>><?php echo esc_html__( 'Flags + Names', 'perflocale' ); ?></option>
					<option value="flags_only" <?php selected( $template, 'flags_only' ); ?>><?php echo esc_html__( 'Flags Only', 'perflocale' ); ?></option>
					<option value="names_only" <?php selected( $template, 'names_only' ); ?>><?php echo esc_html__( 'Names Only', 'perflocale' ); ?></option>
				</select>
				<p class="description"><?php echo esc_html__( 'What to show for each language in the switcher.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-switcher-display"><?php echo esc_html__( 'Display Mode', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $switcher_display = (string) $this->settings->get( 'switcher_display', 'inline' ); ?>
				<select id="perflocale-switcher-display" name="switcher_display">
					<option value="inline" <?php selected( $switcher_display, 'inline' ); ?>><?php echo esc_html__( 'Inline', 'perflocale' ); ?></option>
					<option value="simple" <?php selected( $switcher_display, 'simple' ); ?>><?php echo esc_html__( 'Simple', 'perflocale' ); ?></option>
					<option value="dropdown" <?php selected( $switcher_display, 'dropdown' ); ?>><?php echo esc_html__( 'Dropdown', 'perflocale' ); ?></option>
				</select>
				<p class="description">
					<?php echo esc_html__( 'Inline shows all languages with hover effects. Simple shows plain text without backgrounds. Dropdown shows a click-to-expand menu.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/shortcodes/#perflocale-switcher" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'Shortcode attributes reference', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>
		<tr class="perflocale-row-dropdown-only" data-perflocale-show-when-display="dropdown"<?php echo $switcher_display !== 'dropdown' ? ' style="display:none;"' : ''; ?>>
			<th scope="row">
				<label for="perflocale-arrow-style"><?php echo esc_html__( 'Dropdown Arrow', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-arrow-style" name="switcher_arrow_style">
					<option value="single" <?php selected( $arrow_style, 'single' ); ?>><?php echo esc_html__( 'Single arrow (down chevron)', 'perflocale' ); ?></option>
					<option value="double" <?php selected( $arrow_style, 'double' ); ?>><?php echo esc_html__( 'Double arrow (up + down chevrons)', 'perflocale' ); ?></option>
					<option value="none" <?php selected( $arrow_style, 'none' ); ?>><?php echo esc_html__( 'No arrow', 'perflocale' ); ?></option>
				</select>
				<p class="description"><?php echo wp_kses( __( 'Icon shown next to the language label on the dropdown trigger. Themes can override the markup entirely via the <code>perflocale/switcher/arrow_html</code> filter.', 'perflocale' ), [ 'code' => [] ] ); ?></p>
			</td>
		</tr>
		<tr class="perflocale-row-dropdown-only" data-perflocale-show-when-display="dropdown"<?php echo $switcher_display !== 'dropdown' ? ' style="display:none;"' : ''; ?>>
			<th scope="row">
				<label for="perflocale-trigger-format"><?php echo esc_html__( 'Trigger Label Format', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-trigger-format" name="switcher_trigger_format">
					<option value="inherit" <?php selected( $trigger_format, 'inherit' ); ?>><?php echo esc_html__( 'Match Name Format (default)', 'perflocale' ); ?></option>
					<option value="native"  <?php selected( $trigger_format, 'native' ); ?>><?php echo esc_html__( 'Native (e.g. Deutsch)', 'perflocale' ); ?></option>
					<option value="english" <?php selected( $trigger_format, 'english' ); ?>><?php echo esc_html__( 'English (e.g. German)', 'perflocale' ); ?></option>
					<option value="both"    <?php selected( $trigger_format, 'both' ); ?>><?php echo esc_html__( 'Both (Deutsch / German)', 'perflocale' ); ?></option>
					<option value="slug"    <?php selected( $trigger_format, 'slug' ); ?>><?php echo esc_html__( 'Code only (DE)', 'perflocale' ); ?></option>
				</select>
				<p class="description"><?php echo esc_html__( 'Label shown on the dropdown trigger button, independent of the options format. "Match Name Format" keeps trigger and options in sync; pick a different format for compact headers (e.g. "EN" on the button, "English" inside the dropdown).', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-switcher-layout"><?php echo esc_html__( 'Layout', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $switcher_layout = (string) $this->settings->get( 'switcher_layout', 'horizontal' ); ?>
				<select id="perflocale-switcher-layout" name="switcher_layout">
					<option value="horizontal" <?php selected( $switcher_layout, 'horizontal' ); ?>><?php echo esc_html__( 'Horizontal', 'perflocale' ); ?></option>
					<option value="vertical" <?php selected( $switcher_layout, 'vertical' ); ?>><?php echo esc_html__( 'Vertical', 'perflocale' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-switcher-name-format"><?php echo esc_html__( 'Name Format', 'perflocale' ); ?></label>
			</th>
			<td>
				<?php $name_format = (string) $this->settings->get( 'switcher_name_format', 'native' ); ?>
				<select id="perflocale-switcher-name-format" name="switcher_name_format">
					<option value="native" <?php selected( $name_format, 'native' ); ?>><?php echo esc_html__( 'Native (e.g. Deutsch)', 'perflocale' ); ?></option>
					<option value="english" <?php selected( $name_format, 'english' ); ?>><?php echo esc_html__( 'English (e.g. German)', 'perflocale' ); ?></option>
					<option value="both" <?php selected( $name_format, 'both' ); ?>><?php echo esc_html__( 'Both (Deutsch / German)', 'perflocale' ); ?></option>
					<option value="slug" <?php selected( $name_format, 'slug' ); ?>><?php echo esc_html__( 'Code (e.g. DE)', 'perflocale' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Auto-insert in FSE headers', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="switcher_auto_insert" value="1" <?php checked( $auto_insert ); ?>>
					<?php echo esc_html__( 'Automatically place the language switcher block after the Site Title in block themes.', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo wp_kses( __( 'Uses the WordPress Block Hooks API (FSE / block themes only). Themes can redirect the insertion point via the <code>perflocale/switcher/auto_insert_anchor</code> filter.', 'perflocale' ), [ 'code' => [] ] ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Append to classic menus', 'perflocale' ); ?></th>
			<td>
				<?php $registered_locations = get_registered_nav_menus(); ?>
				<?php if ( $registered_locations === [] ) : ?>
					<p class="description"><?php echo esc_html__( 'Your active theme registers no classic menu locations. Block themes use the Language Switcher block instead.', 'perflocale' ); ?></p>
				<?php else : ?>
					<fieldset>
						<legend class="screen-reader-text"><?php echo esc_html__( 'Menu locations the switcher is appended to', 'perflocale' ); ?></legend>
						<?php foreach ( $registered_locations as $location_slug => $location_label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="switcher_menu_locations[]" value="<?php echo esc_attr( (string) $location_slug ); ?>" <?php checked( in_array( (string) $location_slug, $menu_locations, true ) ); ?>>
								<?php echo esc_html( $location_label ); ?> <code><?php echo esc_html( $location_slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php echo esc_html__( 'Appends the language switcher to the end of the selected classic menu locations (block themes: use the Language Switcher block).', 'perflocale' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Untranslated Languages', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="switcher_show_untranslated" value="1" <?php checked( $show_untranslated ); ?>>
					<?php echo esc_html__( 'Show languages even when a translation is not available.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Hide Current Language', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="switcher_hide_current" value="1" <?php checked( $hide_current ); ?>>
					<?php echo esc_html__( 'Hide the currently active language from the switcher.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-untranslated-link"><?php echo esc_html__( 'Untranslated Link Target', 'perflocale' ); ?></label>
			</th>
			<td>
				<select id="perflocale-untranslated-link" name="switcher_untranslated_link">
					<option value="homepage" <?php selected( $untranslated_link, 'homepage' ); ?>><?php echo esc_html__( 'Language Homepage', 'perflocale' ); ?></option>
					<option value="no_link" <?php selected( $untranslated_link, 'no_link' ); ?>><?php echo esc_html__( 'Show Without Link', 'perflocale' ); ?></option>
					<option value="hide" <?php selected( $untranslated_link, 'hide' ); ?>><?php echo esc_html__( 'Hide Link', 'perflocale' ); ?></option>
				</select>
				<p class="description"><?php echo esc_html__( 'Where to link when a translation does not exist for a given language.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="perflocale-switcher-class"><?php echo esc_html__( 'Custom CSS Class', 'perflocale' ); ?></label>
			</th>
			<td>
				<input type="text" id="perflocale-switcher-class" name="switcher_class" value="<?php echo esc_attr( (string) $this->settings->get( 'switcher_class' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. my-switcher custom-class', 'perflocale' ); ?>">
				<p class="description"><?php echo esc_html__( 'Add custom CSS classes to the language switcher. Separate multiple classes with spaces.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Admin Toolbar', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="admin_bar_switcher" value="1" <?php checked( (bool) $this->settings->get( 'admin_bar_switcher' ) ); ?>>
					<?php echo esc_html__( 'Show language switcher in the WordPress admin toolbar', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Displays the current language with a dropdown to switch languages. On the frontend it links to the translated page; in the admin it filters content by language.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the Performance tab fields.
	 *
	 * @return void
	 */
	private function render_performance_tab(): void {
		$cache_object  = (bool) $this->settings->get( 'cache_object_enabled' );
		$preload_slugs = (bool) $this->settings->get( 'cache_preload_slugs' );
		$str_mode      = (string) $this->settings->get( 'string_translation_mode' );
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'String Translation Mode', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="string_translation_mode" value="files" <?php checked( $str_mode, 'files' ); ?>>
						<?php echo esc_html__( 'Translation Files (Recommended)', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 12px 24px;">
						<?php echo esc_html__( 'Generates .l10n.php files for each domain and locale. Fastest performance - translations are served natively by WordPress with zero filter overhead.', 'perflocale' ); ?>
					</p>
					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="string_translation_mode" value="database" <?php checked( $str_mode, 'database' ); ?>>
						<?php echo esc_html__( 'Database (Lazy Load)', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 0 24px;">
						<?php echo esc_html__( 'Loads translations from the database on demand via gettext filter. No file generation needed - useful for development or read-only file systems.', 'perflocale' ); ?>
					</p>
					<p class="description" style="margin-top:6px;">
						<?php echo esc_html__( 'Keep Files unless your filesystem is read-only or the translations directory is not writable — that is the only reason to choose Database.', 'perflocale' ); ?>
						<a href="https://perflocale.com/docs/production-tuning/#string-mode" target="_blank" rel="noopener"><?php echo esc_html__( 'Which storage mode to use', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
					</p>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Object Cache', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="cache_object_enabled" value="1" <?php checked( $cache_object ); ?>>
					<?php echo esc_html__( 'Use the WordPress object cache (Redis, Memcached, etc.) for persistent caching.', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Leave this on. When a persistent object cache drop-in is installed, PerfLocale skips its database transient fallback entirely, which makes this setting its only persistent layer - so unchecking it leaves no persistent caching at all, and every language, translation and slug look-up is recomputed on each request. Uncheck it only to isolate PerfLocale from a misbehaving cache backend.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Preload Slugs', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="cache_preload_slugs" value="1" <?php checked( $preload_slugs ); ?>>
					<?php echo esc_html__( 'Preload all translated slugs on each request for faster URL generation.', 'perflocale' ); ?>
				</label>
			</td>
		</tr>
		<?php if ( $str_mode === 'files' ) : ?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Translation Files', 'perflocale' ); ?></th>
			<td>
				<?php
				$dir        = ( new \PerfLocale\Strings\TranslationFileGenerator( \PerfLocale\Plugin::get_instance()->get( 'cache' ) ) )->get_translations_dir();
				$file_count = is_dir( $dir ) ? count( glob( $dir . '/*.l10n.php' ) ?: [] ) : 0;
				$regen_url  = wp_nonce_url(
					admin_url( 'admin.php?page=perflocale-settings&tab=performance&perflocale_regenerate=1' ),
					'perflocale_regenerate_translations'
				);
				?>
				<p class="description" style="margin-bottom: 8px;">
					<?php
					printf(
						/* translators: %1$d: file count, %2$s: directory path */
						esc_html__( '%1$d translation file(s) in %2$s', 'perflocale' ),
						absint( $file_count ),
						'<code>' . esc_html( str_replace( ABSPATH, '', $dir ) ) . '</code>'
					);
					?>
				</p>
				<a href="<?php echo esc_url( $regen_url ); ?>" class="button perflocale-btn-icon perflocale-btn-icon--md">
					<span class="dashicons dashicons-update"></span>
					<?php echo esc_html__( 'Regenerate Translation Files', 'perflocale' ); ?>
				</a>
			</td>
		</tr>
		<?php endif; ?>

		<?php $this->render_background_processing_rows(); ?>
		<?php
	}

	/**
	 * Render the Background-processing rows on the Performance tab.
	 *
	 * Four fields, all of them read back by this page's own
	 * {@see self::extract_tab_values()} under the `performance` tab (there is
	 * no separate per-tab save handler):
	 *   - `background_processing`: Auto / Always / Never radio.
	 *   - `background_engine`: shown only when Action Scheduler is loaded;
	 *     "Auto (Action Scheduler)" vs "Force WP-Cron".
	 *   - `background_paused`: the emergency-brake checkbox.
	 *   - `background_thresholds[<type>]`: one optional per-job override per
	 *     registered job type, via {@see self::extract_background_thresholds()}.
	 *
	 * @return void
	 */
	private function render_background_processing_rows(): void {
		$mode           = (string) $this->settings->get( 'background_processing', 'auto' );
		$engine         = (string) $this->settings->get( 'background_engine', 'auto' );
		$as_available   = \PerfLocale\Background\JobRunnerFactory::action_scheduler_available();
		$current_engine = \PerfLocale\Background\JobRunnerFactory::pick()->get_engine_name();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background Processing', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="background_processing" value="auto" <?php checked( $mode, 'auto' ); ?>>
						<?php esc_html_e( 'Auto (recommended)', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 10px 24px;">
						<?php esc_html_e( 'Run large operations (imports, migrations, bulk translation) in the background. Small ones still run inline so you see the result immediately.', 'perflocale' ); ?>
					</p>

					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="background_processing" value="always" <?php checked( $mode, 'always' ); ?>>
						<?php esc_html_e( 'Always', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 10px 24px;">
						<?php esc_html_e( 'Always run heavy operations in the background. Best on large sites or shared hosting with strict PHP timeouts.', 'perflocale' ); ?>
					</p>

					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="background_processing" value="never" <?php checked( $mode, 'never' ); ?>>
						<?php esc_html_e( 'Never', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 0 24px;">
						<?php esc_html_e( 'Always run inline. Not recommended on large sites — long operations can time out.', 'perflocale' ); ?>
					</p>
				</fieldset>
				<p class="description" style="margin-top: 10px;">
					<?php
					printf(
						/* translators: 1: URL of the background jobs admin page, 2: URL of the background-jobs documentation page. */
						wp_kses_post( __( 'Background jobs appear under <a href="%1$s">PerfLocale → Jobs</a> while they run. <a href="%2$s" target="_blank" rel="noopener">Learn more →</a>', 'perflocale' ) ),
						esc_url( admin_url( 'admin.php?page=perflocale-jobs' ) ),
						esc_url( 'https://perflocale.com/docs/background-jobs/#settings' )
					);
					?>
				</p>
			</td>
		</tr>

		<?php if ( $as_available ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background Engine', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="background_engine" value="auto" <?php checked( $engine, 'auto' ); ?>>
						<?php esc_html_e( 'Auto — use Action Scheduler', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 10px 24px;">
						<?php
						printf(
							/* translators: %s is a code element with the current engine name. */
							esc_html__( 'Action Scheduler is loaded on this site. Currently in use: %s.', 'perflocale' ),
							'<code>' . esc_html( $current_engine ) . '</code>'
						);
						?>
					</p>

					<label style="display:block; margin-bottom:6px;">
						<input type="radio" name="background_engine" value="force_wp_cron" <?php checked( $engine, 'force_wp_cron' ); ?>>
						<?php esc_html_e( 'Force WP-Cron', 'perflocale' ); ?>
					</label>
					<p class="description" style="margin: 0 0 0 24px;">
						<?php esc_html_e( 'Skip Action Scheduler and use WordPress’s built-in cron instead. Escape hatch for sites where Action Scheduler is misbehaving.', 'perflocale' ); ?>
					</p>
				</fieldset>
			</td>
		</tr>
		<?php endif; ?>

		<tr>
			<th scope="row"><?php esc_html_e( 'Pause queue', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="background_paused" value="1" <?php checked( (bool) $this->settings->get( 'background_paused', false ) ); ?>>
					<?php esc_html_e( 'Pause all background workers', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php esc_html_e( 'New dispatches are accepted but workers immediately re-queue them every 5 minutes instead of running. Use this as an emergency brake when something is mis-dispatching; uncheck to resume.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>

		<?php
		// Per-job threshold overrides. Defaults come from each job's
		// get_default_threshold(); leaving a row blank means "use default".
		$thresholds = (array) $this->settings->get( 'background_thresholds', [] );
		$factories  = [
			'data_import'              => static fn() => new \PerfLocale\Background\Jobs\DataImportJob(),
			'data_export'              => static fn() => new \PerfLocale\Background\Jobs\DataExportJob(),
			'wpml_migration'           => static fn() => new \PerfLocale\Background\Jobs\WpmlMigrationJob(),
			'polylang_migration'       => static fn() => new \PerfLocale\Background\Jobs\PolylangMigrationJob(),
			'translatepress_migration' => static fn() => new \PerfLocale\Background\Jobs\TranslatePressMigrationJob(),
			'string_scan'              => static fn() => new \PerfLocale\Background\Jobs\StringScanJob(),
			'bulk_translate'           => static fn() => new \PerfLocale\Background\Jobs\BulkTranslateJob(),
			'bulk_string_translate'    => static fn() => new \PerfLocale\Background\Jobs\BulkStringTranslateJob(),
			'site_translate'           => static fn() => new \PerfLocale\Background\Jobs\SiteTranslateJob(),
		];
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background Thresholds', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin: 0 0 10px;">
					<?php esc_html_e( 'In Auto mode, operations below their threshold run inline; above, they queue. Leave a row blank to use the default. Filter perflocale/jobs/threshold/<type> is the programmatic equivalent.', 'perflocale' ); ?>
				</p>
				<table class="widefat striped" style="max-width:560px;">
					<caption class="screen-reader-text"><?php esc_html_e( 'Background job thresholds, with each job\'s default and the operator\'s override.', 'perflocale' ); ?></caption>
					<thead>
						<tr>
							<th scope="col" style="padding-left:14px;"><?php esc_html_e( 'Operation', 'perflocale' ); ?></th>
							<th scope="col" style="width:120px;"><?php esc_html_e( 'Default', 'perflocale' ); ?></th>
							<th scope="col" style="width:160px;"><?php esc_html_e( 'Override', 'perflocale' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( self::background_job_types() as $type => $label ) :
							$default  = (int) $factories[ $type ]()->get_default_threshold();
							$override = isset( $thresholds[ $type ] ) ? (int) $thresholds[ $type ] : 0;
							?>
							<tr>
								<td style="padding-left:14px;"><?php echo esc_html( $label ); ?></td>
								<td><code><?php echo esc_html( (string) $default ); ?></code></td>
								<td>
									<input
										type="number"
										min="1"
										step="1"
										name="background_thresholds[<?php echo esc_attr( $type ); ?>]"
										value="<?php echo $override > 0 ? esc_attr( (string) $override ) : ''; ?>"
										placeholder="<?php echo esc_attr( (string) $default ); ?>"
										style="width:100%;"
									>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the "Integrations" tab - CDN / edge worker / SEO schema toggles.
	 *
	 * All three toggles are opt-in (except schema which defaults on - it's
	 * pure-SEO upside with zero runtime cost). When a feature is off, no
	 * related hooks are registered and no REST routes are exposed.
	 *
	 * @return void
	 */
	private function render_advanced_tab(): void {
		$edge_enabled  = (bool) $this->settings->get( 'edge_integration_enabled' );
		$cache_tags_on = (bool) $this->settings->get( 'cdn_cache_tags_enabled' );
		$config_url    = rest_url( 'perflocale/v1/config' );
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Dashboard Widget', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="dashboard_widget_enabled" value="1" <?php checked( (bool) $this->settings->get( 'dashboard_widget_enabled' ) ); ?>>
					<?php echo esc_html__( 'Show a translation-overview widget on the WordPress Dashboard', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Displays active languages and content-translation coverage at a glance. Only shown to users who can manage languages, loads only on the Dashboard screen, and reads cached counts (no extra queries on dashboard load).', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Edge Worker Integration', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="edge_integration_enabled" value="1" <?php checked( $edge_enabled ); ?>>
					<?php echo esc_html__( 'Publish /perflocale/v1/config endpoint and honour X-PerfLocale-Lang hints', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Exposes a cache-friendly public REST endpoint that a Cloudflare Worker, Vercel Edge function, or Netlify Edge function can read to pre-route visitors to the correct language before the request reaches PHP. Also adds an Edge Hint Redirect method, which you switch on under URL &amp; Routing; with two or more redirect methods enabled you can set the order they run in.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/edge-integration/#restricting-access" target="_blank" rel="noopener"><?php echo esc_html__( 'Restricting access to the endpoint', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
				<?php if ( $edge_enabled ) : ?>
					<p class="description" style="margin-top:6px;">
						<strong><?php echo esc_html__( 'Endpoint:', 'perflocale' ); ?></strong>
						<code><?php echo esc_html( $config_url ); ?></code>
					</p>
					<p class="description">
						<?php echo esc_html__( 'A reference Worker implementation is included at:', 'perflocale' ); ?>
						<code>assets/js/edge-helper.js</code>
					</p>
					<p class="description" style="margin-top:6px; color:#a65f05;">
						<?php echo esc_html__( 'Note: the endpoint is public (required so edge workers can read it without credentials). Any paths you add to "Excluded paths" in URL & Routing will be visible in the JSON payload. If a path is sensitive, route around it instead of relying on it being hidden.', 'perflocale' ); ?>
					</p>
				<?php else : ?>
					<p class="description" style="margin-top:6px; color:#646970;">
						<em><?php echo esc_html__( 'Leave disabled if you are not running a Cloudflare Worker / Vercel Edge / Netlify Edge function. When off, no REST route is registered and no hint headers are consumed.', 'perflocale' ); ?></em>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'CDN Cache-Tag Headers', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="cdn_cache_tags_enabled" value="1" <?php checked( $cache_tags_on ); ?>>
					<?php echo esc_html__( 'Emit Cache-Tag response headers so CDNs can purge by language or by specific content', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Adds a Cache-Tag header to every frontend page with tags like lang:bg_BG, post:123, home. Compatible with Cloudflare (Cache-Tag), Bunny (Cache-Tag), and Fastly (rename to Surrogate-Key via filter). Enables surgical purges instead of flushing the entire edge cache on every save.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/cache-tags/#cdn-support" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'CDN setup examples', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
				<?php if ( ! $cache_tags_on ) : ?>
					<p class="description" style="margin-top:6px; color:#646970;">
						<em><?php echo esc_html__( 'Leave disabled if your hosting does not sit behind a CDN that reads these tags. When off, no send_headers hook is registered.', 'perflocale' ); ?></em>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Uninstall', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( (bool) $this->settings->get( 'delete_data_on_uninstall' ) ); ?>>
					<?php echo esc_html__( 'Delete all plugin data when uninstalling', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo wp_kses( __( 'When enabled, deleting the plugin will remove all translation data, languages, settings, and database tables. When disabled, your data is preserved for reinstallation. This is a one-shot teardown setting — toggle it before deleting the plugin from <em>Plugins → Installed Plugins</em>.', 'perflocale' ), [ 'em' => [] ] ); ?></p>
			</td>
		</tr>
		<?php
	}


	/**
	 * Extract and sanitize per-job background-threshold overrides from POST.
	 *
	 * Whitelists keys against the registered job types listed by
	 * {@see self::background_job_types()} so an attacker can't POST arbitrary
	 * keys that would persist in the array setting.
	 * Empty / zero / negative inputs are dropped so {@see AbstractJob::effective_threshold()}
	 * falls back to the job's default.
	 *
	 * @return array<string, int>
	 */
	private function extract_background_thresholds(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by AdminController.
		if ( empty( $_POST['background_thresholds'] ) || ! is_array( $_POST['background_thresholds'] ) ) {
			return [];
		}

		$out = [];

		foreach ( self::background_job_types() as $type => $_label ) {
			$raw = isset( $_POST['background_thresholds'][ $type ] )
				? sanitize_text_field( wp_unslash( (string) $_POST['background_thresholds'][ $type ] ) )
				: '';
			$raw = trim( $raw );

			if ( $raw === '' ) {
				continue;
			}

			$n = (int) $raw;

			if ( $n > 0 ) {
				$out[ $type ] = $n;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $out;
	}

	/**
	 * Map of registered Tier-2 job types → human label. Mirrors the
	 * WorkerRegistry registrations in Bootstrap. The labels are used by
	 * the Performance-tab threshold rows; adding a new job type means
	 * adding it here too so operators can override its threshold.
	 *
	 * @return array<string, string>
	 */
	private static function background_job_types(): array {
		return [
			'data_import'              => __( 'Data import (JSON)', 'perflocale' ),
			'data_export'              => __( 'Data export (JSON)', 'perflocale' ),
			'wpml_migration'           => __( 'WPML migration', 'perflocale' ),
			'polylang_migration'       => __( 'Polylang migration', 'perflocale' ),
			'translatepress_migration' => __( 'TranslatePress migration', 'perflocale' ),
			'string_scan'              => __( 'String scan', 'perflocale' ),
			'bulk_translate'           => __( 'Bulk machine translation', 'perflocale' ),
			'bulk_string_translate'    => __( 'Bulk MT translation of strings', 'perflocale' ),
			'site_translate'           => __( 'Site-wide MT translation (chunked)', 'perflocale' ),
		];
	}

	/**
	 * Render the Export & Import tab.
	 *
	 * @return void
	 */
	private function render_export_import_tab(): void {
		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );

		$wpml_available           = ( new \PerfLocale\Migration\WpmlImporter( $cache ) )->can_import();
		$polylang_available       = ( new \PerfLocale\Migration\PolylangImporter( $cache ) )->can_import();
		$translatepress_available = ( new \PerfLocale\Migration\TranslatePressImporter( $cache ) )->can_import();
		$sections                 = \PerfLocale\Admin\DataExporter::SECTIONS;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$import_result = isset( $_GET['import_result'] ) ? sanitize_text_field( wp_unslash( $_GET['import_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : '';

		// The ceiling an operator will actually hit, named on the import form
		// so the limit is visible BEFORE a doomed upload rather than only after
		// it - the same pair of ceilings StringsPage names on the PO form, and
		// the smaller one binds: what PHP will accept
		// (`AdminController::max_upload_bytes()`, the one that binds on every
		// stock host) and what the importer will read back whole
		// (`AdminController::import_max_bytes()`, the ceiling
		// AdminController's own gate applies). One admin-page-only call to
		// each; nothing on the front end.
		$import_ceiling = \PerfLocale\Admin\AdminController::format_bytes(
			min(
				\PerfLocale\Admin\AdminController::max_upload_bytes(),
				\PerfLocale\Admin\AdminController::import_max_bytes()
			)
		);

		// Inline script: Export checkboxes select-all.
		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var allCb = document.getElementById(\'perflocale-export-all\');' .
				'var boxes = document.querySelectorAll(\'.perflocale-export-section\');' .
				'if(!allCb)return;' .
				'allCb.addEventListener(\'change\',function(){' .
					'boxes.forEach(function(cb){cb.checked=allCb.checked;});' .
				'});' .
				'boxes.forEach(function(cb){' .
					'cb.addEventListener(\'change\',function(){' .
						'allCb.checked=[].every.call(boxes,function(c){return c.checked;});' .
					'});' .
				'});' .
			'})();'
		);

		?>

		<?php if ( $import_result !== '' ) : ?>
			<div class="notice notice-success" style="margin:16px 0 8px;"><p><?php echo esc_html( $import_result ); ?></p></div>
		<?php endif; ?>

		<?php if ( $import_error !== '' ) : ?>
			<div class="notice notice-error" style="margin:16px 0 8px;"><p><?php echo esc_html( $import_error ); ?></p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">

		<!-- Export -->
		<tr>
			<th scope="row"><?php echo esc_html__( 'Export', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-top:0;"><?php echo esc_html__( 'Select which data to include in the export.', 'perflocale' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>" style="margin-top:8px;">
					<?php wp_nonce_field( 'perflocale_export_data' ); ?>
					<input type="hidden" name="perflocale_action" value="export">
					<fieldset style="margin-bottom:12px;">
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" id="perflocale-export-all" checked>
							<strong><?php echo esc_html__( 'Select All', 'perflocale' ); ?></strong>
						</label>
						<div style="margin-left:24px;display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;">
							<?php foreach ( $sections as $key => $section ) : ?>
								<label>
									<input type="checkbox" name="export_sections[]" value="<?php echo esc_attr( $key ); ?>" class="perflocale-export-section" checked>
									<?php echo esc_html( $section['label'] ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description" style="margin:10px 0 0 24px;">
							<?php echo esc_html__( 'Keep "Languages" selected whenever the export includes Translation Links, String Translations or Slug Translations. Those rows name their language by its numeric ID, and the same ID belongs to a different language on a different site - imported without the Languages section, a German translation can silently become a French one.', 'perflocale' ); ?>
						</p>
					</fieldset>
					<?php submit_button( __( 'Export Data', 'perflocale' ), 'secondary', 'submit', false ); ?>
				</form>
			</td>
		</tr>

		<!-- Import -->
		<tr>
			<th scope="row"><?php echo esc_html__( 'Import', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-top:0;"><?php echo esc_html__( 'Restore data from a PerfLocale JSON export file. Large imports run in the background — track progress under PerfLocale → Jobs.', 'perflocale' ); ?></p>

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>" style="margin-top:8px;">
					<?php wp_nonce_field( 'perflocale_import_data' ); ?>
					<input type="hidden" name="perflocale_action" value="import">

					<div data-perflocale-dropzone class="perflocale-dropzone" style="border:2px dashed #c3c4c7;border-radius:4px;padding:24px;text-align:center;margin-bottom:12px;">
						<span class="dashicons dashicons-upload" style="font-size:32px;width:32px;height:32px;color:#8c8f94;"></span>
						<p style="margin:8px 0 0;color:#646970;">
							<?php echo esc_html__( 'Drag and drop your JSON export here, or choose a file below.', 'perflocale' ); ?>
						</p>
						<p data-perflocale-dropzone-name style="margin:8px 0 0;font-weight:600;color:#1e1e1e;"></p>
						<p style="margin:12px 0 0;">
							<input type="file" name="perflocale_import_file" accept=".json,application/json" required>
						</p>
						<p style="margin:8px 0 0;font-size:11px;color:#646970;">
							<?php
							printf(
								/* translators: %s: largest import file this site accepts, e.g. "2 MB". */
								esc_html__( 'Maximum file size: %s.', 'perflocale' ),
								esc_html( $import_ceiling )
							);
							?>
						</p>
					</div>

					<fieldset style="margin-bottom:12px;">
						<legend class="screen-reader-text"><?php echo esc_html__( 'Import Mode', 'perflocale' ); ?></legend>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="perflocale_import_mode" value="merge" checked>
							<strong><?php echo esc_html__( 'Merge', 'perflocale' ); ?></strong> - <?php echo esc_html__( 'add imported data alongside existing data', 'perflocale' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="perflocale_import_mode" value="replace">
							<strong><?php echo esc_html__( 'Replace', 'perflocale' ); ?></strong> - <?php echo esc_html__( 'delete all existing data first', 'perflocale' ); ?>
						</label>
					</fieldset>
					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'Replace clears this site\'s PerfLocale tables before loading, so anything the uploaded bundle does not carry is gone; Merge only adds.', 'perflocale' ); ?>
						<a href="https://perflocale.com/docs/export-import/#merge-vs-replace" target="_blank" rel="noopener"><?php echo esc_html__( 'Merge vs Replace — what Replace clears', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
					</p>
					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'Tip: re-importing the same backup in Merge mode creates fresh translation_groups rows (the table has no natural key) — the matching links de-duplicate via their (object_id, language_id) UNIQUE constraint, so the duplicate groups are linked to nothing and are swept by the daily orphan-group GC. For deterministic re-imports use Replace mode.', 'perflocale' ); ?>
					</p>

					<?php submit_button( __( 'Import Data', 'perflocale' ), 'secondary', 'submit', false ); ?>
				</form>

			</td>
		</tr>

		<?php
		// Migration cards. Each `covers` line names ONLY what that importer really
		// imports, verified against src/Migration/: WpmlImporter does posts, terms and
		// strings; PolylangImporter does posts, terms and single-language objects and NO
		// strings; TranslatePressImporter does posts, slugs and strings and NO terms.
		// NONE of the three touches nav menus. This is the only place a user is told
		// what a migration will and will not bring across - keep it honest.
		?>
		<!-- Migration -->
		<tr>
			<th scope="row"><?php echo esc_html__( 'Migration', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-top:0;"><?php echo esc_html__( 'Import translations from WPML, Polylang, or TranslatePress. Your existing plugin&rsquo;s data is read, never modified. Imports run in the background and are safe to re-run.', 'perflocale' ); ?></p>

				<?php if ( ! $wpml_available && ! $polylang_available && ! $translatepress_available ) : ?>
					<p style="color:#646970;margin-top:8px;">
						<?php echo esc_html__( 'No WPML, Polylang, or TranslatePress data detected.', 'perflocale' ); ?>
					</p>
				<?php else : ?>
					<div class="perflocale-migration-cards">
						<?php if ( $wpml_available ) : ?>
							<div class="perflocale-migration-card">
								<h3 class="perflocale-migration-card__title">WPML</h3>
								<p class="perflocale-migration-card__covers"><?php echo esc_html__( 'Posts, pages, terms, and string translations.', 'perflocale' ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>">
									<?php wp_nonce_field( 'perflocale_migrate_wpml' ); ?>
									<input type="hidden" name="perflocale_action" value="migrate_wpml">
									<?php
									submit_button(
										__( 'Import from WPML', 'perflocale' ),
										'secondary',
										'submit',
										false,
										[ 'onclick' => "return confirm('" . esc_js( __( 'Import all translations from WPML?', 'perflocale' ) ) . "');" ]
									);
									?>
								</form>
								<p class="perflocale-migration-card__found"><?php echo esc_html__( 'WPML data found', 'perflocale' ); ?></p>
							</div>
						<?php endif; ?>
						<?php if ( $polylang_available ) : ?>
							<div class="perflocale-migration-card">
								<h3 class="perflocale-migration-card__title">Polylang</h3>
								<p class="perflocale-migration-card__covers"><?php echo esc_html__( 'Posts, pages, and terms, with their language links.', 'perflocale' ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>">
									<?php wp_nonce_field( 'perflocale_migrate_polylang' ); ?>
									<input type="hidden" name="perflocale_action" value="migrate_polylang">
									<?php
									submit_button(
										__( 'Import from Polylang', 'perflocale' ),
										'secondary',
										'submit',
										false,
										[ 'onclick' => "return confirm('" . esc_js( __( 'Import all translations from Polylang?', 'perflocale' ) ) . "');" ]
									);
									?>
								</form>
								<p class="perflocale-migration-card__found"><?php echo esc_html__( 'Polylang data found', 'perflocale' ); ?></p>
							</div>
						<?php endif; ?>
						<?php if ( $translatepress_available ) : ?>
							<div class="perflocale-migration-card">
								<h3 class="perflocale-migration-card__title">TranslatePress</h3>
								<p class="perflocale-migration-card__covers"><?php echo esc_html__( 'Posts, pages, URL slugs, and string translations.', 'perflocale' ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>">
									<?php wp_nonce_field( 'perflocale_migrate_translatepress' ); ?>
									<input type="hidden" name="perflocale_action" value="migrate_translatepress">
									<?php
									submit_button(
										__( 'Import from TranslatePress', 'perflocale' ),
										'secondary',
										'submit',
										false,
										[ 'onclick' => "return confirm('" . esc_js( __( 'Import all translations from TranslatePress? This may take a few minutes for large sites.', 'perflocale' ) ) . "');" ]
									);
									?>
								</form>
								<p class="perflocale-migration-card__found"><?php echo esc_html__( 'TranslatePress data found', 'perflocale' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		</table>
		<?php
	}

	/**
	 * Render an API key field that respects external overrides (env var or
	 * PHP constant).
	 *
	 * When the value comes from getenv() or a defined PHP constant, the
	 * input is disabled and shows the source so the admin knows their edit
	 * here would be ignored anyway. Both sources share one canonical name
	 * (see Settings::CONSTANT_MAP) — the env var and the constant use the
	 * same uppercase string (e.g. PERFLOCALE_DEEPL_API_KEY).
	 *
	 * @param string $key Setting key (e.g., 'mt_deepl_api_key').
	 * @param string $id HTML input ID.
	 * @param string $value Current value.
	 * @param string $type Input type ('password' or 'url').
	 * @return void
	 */
	private function render_api_key_field( string $key, string $id, string $value, string $type = 'password' ): void {
		$source     = $this->settings->get_override_source( $key );
		$const_name = $this->settings->get_constant_name( $key );

		if ( $source !== null ) {
			$masked = str_repeat( '*', 20 );
			?>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $masked ); ?>" class="regular-text" disabled>
			<p class="description">
				<?php
				if ( $source === 'env' ) {
					printf(
						/* translators: %s: environment variable name */
						esc_html__( 'Defined in the %s environment variable', 'perflocale' ),
						'<code>' . esc_html( $const_name ) . '</code>'
					);
				} elseif ( $source === 'connector' ) {
					esc_html_e( 'Provided by the WordPress Connectors API', 'perflocale' );
				} else {
					printf(
						/* translators: %s: PHP constant name */
						esc_html__( 'Defined in wp-config.php as %s', 'perflocale' ),
						'<code>' . esc_html( $const_name ) . '</code>'
					);
				}
				?>
			</p>
			<?php
		} else {
			$input_type = $type === 'url' ? 'url' : 'password';
			$escaped    = $type === 'url' ? esc_url( $value ) : esc_attr( $value );
			?>
			<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $escaped ); ?>" class="regular-text" autocomplete="off">
			<?php if ( $const_name !== '' ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: external override name (used for both env var and PHP constant) */
						esc_html__( 'For better security set this as the %s environment variable or define it in wp-config.php (same name).', 'perflocale' ),
						'<code>' . esc_html( $const_name ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>
			<?php
		}
	}
}
