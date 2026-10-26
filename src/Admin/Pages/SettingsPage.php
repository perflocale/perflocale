<?php
/**
 * Settings admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Database\DataRegistry;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the PerfLocale settings page with a tabbed interface.
 */
final class SettingsPage {

	/**
	 * Machine-translation key settings rendered as password inputs: never
	 * printed into the form, and kept when the input is submitted empty.
	 */
	private const MT_SECRET_KEYS = [ 'mt_deepl_api_key', 'mt_google_api_key', 'mt_microsoft_api_key', 'mt_libre_api_key', 'mt_agency_api_key' ];

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
	 * Sync Fields entries the last {@see merge_sync_fields()} left out
	 * because they are the plugin's own meta keys.
	 *
	 * @var array<int, string>
	 */
	private static array $refused_sync_fields = [];

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
	 * Registers and attaches the fallback-chain editor CSS and JS.
	 *
	 * Enqueued on every settings tab: cheaper than tracking the tab at admin_enqueue_scripts time.
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
					// Touch has no drag-and-drop or arrow keys: these label the move buttons for phones.
					'moveUp'       => __( 'Move earlier', 'perflocale' ),
					'moveDown'     => __( 'Move later', 'perflocale' ),
				],
			]
		);

		// The redirect-priority editor reuses the .pl-fb-chip CSS; its JS is a separate, sortable-only file.
		wp_enqueue_script(
			'perflocale-redirect-priority-editor',
			PERFLOCALE_URL . 'assets/js/redirect-priority-editor.js',
			[],
			PERFLOCALE_VERSION,
			true
		);

		// Conditional fields of the auto-generated addon subtab; a no-op without an addon form.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only message display.
		$address_refused = isset( $_GET['mt-address-refused'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only message display.
		$sync_refused = isset( $_GET['sync-refused'] ) && is_string( $_GET['sync-refused'] ) ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_GET['sync-refused'] ) ) ) ) ) : [];

		?>
		<div class="wrap perflocale-settings">
			<h1><?php echo esc_html__( 'PerfLocale Settings', 'perflocale' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( $message === 'true' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved successfully.', 'perflocale' ); ?></p>
				</div>
			<?php elseif ( $message === 'false' ) : ?>
				<?php // A save that wpdb refused. ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'Settings could not be saved. The database refused the write, which usually means one value is too long for its column or contains characters it cannot store. Your previous settings are unchanged.', 'perflocale' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( [] !== $sync_refused ) : ?>
				<?php // Sync Fields entries merge_sync_fields() left out: the plugin's own meta keys. ?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %s: comma-separated list of meta keys. */
							esc_html__( 'Not added to Sync Fields: %s. PerfLocale keeps these fields per post and never syncs them between translations.', 'perflocale' ),
							wp_kses( implode( ', ', array_map( static fn( string $key ): string => '<code>' . esc_html( $key ) . '</code>', $sync_refused ) ), [ 'code' => [] ] )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $address_refused ) : ?>
				<?php // A provider address change Settings::update() refused (multisite, key supplied from outside the database). ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( "This provider's key is set by the network administrator; ask them to change the address.", 'perflocale' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// On multisite, ms-settings.php resolves the host against the site list before any plugin loads,
			// so unregistered language hostnames get "site does not exist". Checked on the tab that sets the
			// mode; SiteHealth::language_host_report() costs no query outside multisite host modes.
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
			// Addon-settings saves redirect back here with perflocale_msg; the Addons list page's flash
			// renderer does not run on this screen.
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$files_read_failed = isset( $_GET['files_read_failed'] ) ? absint( $_GET['files_read_failed'] ) : 0;

			if ( $files_generated >= 0 ) :
				?>
				<div class="notice <?php echo esc_attr( $files_read_failed > 0 ? 'notice-error' : 'notice-success' ); ?> is-dismissible">
					<p>
						<?php
						if ( $files_read_failed > 0 ) {
							printf(
								/* translators: 1: number of files generated, 2: number of languages whose translations could not be read */
								esc_html__( 'Translation files were not fully regenerated. %1$d file(s) generated. The translations of %2$d language(s) could not be read from the database, so their existing translation files were kept. Try again, and check the database if this repeats.', 'perflocale' ),
								absint( $files_generated ),
								absint( $files_read_failed )
							);
						} else {
							printf(
								/* translators: %d: number of files generated */
								esc_html__( 'Translation files regenerated successfully. %d file(s) generated.', 'perflocale' ),
								absint( $files_generated )
							);
						}

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

			<?php
			if ( $active_tab === 'export-import' ) {
				$this->render_import_notices();
			}
			?>

			<nav class="nav-tab-wrapper" style="position: sticky; top: 32px; background: #f0f0f1; z-index: 10; display: flex; align-items: flex-end;">
				<div class="perflocale-settings-tabs__items" style="flex: 1 1 auto; display: flex; align-items: flex-end;">
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
	 * Sanitizes the GeoIP country-mapping input into `[ slug => "AA,BB" ]`.
	 *
	 * The default language (a mapping for it over-redirects), inactive slugs and non-`[A-Z]{2}` codes
	 * are dropped; stored rows of inactive languages are kept (merge_language_map()).
	 *
	 * @param mixed $input Raw POSTed value (expected: assoc array).
	 * @return array<string, string>
	 */
	private function sanitize_geo_country_map( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = [];
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

			// Never store the default language's mapping, whatever was posted: the UI only hides its row.
			if ( $default_slug !== '' && $slug === $default_slug ) {
				continue;
			}

			// Only active languages can have a mapping.
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

			if ( $codes !== [] ) {
				$out[ $slug ] = implode( ',', array_keys( $codes ) );
			}
		}

		return self::merge_language_map( array_filter( (array) $this->settings->get( 'geo_country_map', [] ), 'is_string' ), $out, array_keys( $active_slugs ) );
	}

	/**
	 * Merge a posted per-language map onto the stored one.
	 *
	 * The tab renders a row per active language: rendered entries come from the post (blank removes),
	 * others stay as stored, and the stored key order is kept so an unchanged save writes the same array.
	 *
	 * @template T
	 * @param array<int|string, T> $stored   Stored map.
	 * @param array<int|string, T> $posted   Sanitized posted map without blank rows.
	 * @param array<int, string>   $rendered Slugs that have a row on the form.
	 * @return array<string, T>
	 */
	private static function merge_language_map( array $stored, array $posted, array $rendered ): array {
		$out = [];

		foreach ( $stored as $slug => $value ) {
			$slug = (string) $slug;

			if ( isset( $posted[ $slug ] ) ) {
				$out[ $slug ] = $posted[ $slug ];
			} elseif ( ! in_array( $slug, $rendered, true ) ) {
				$out[ $slug ] = $value;
			}
		}

		foreach ( $posted as $slug => $value ) {
			if ( ! array_key_exists( (string) $slug, $out ) ) {
				$out[ (string) $slug ] = $value;
			}
		}

		return $out;
	}

	/**
	 * A stored string setting, for a field the tab does not always render.
	 *
	 * @param string $key      Settings key.
	 * @param string $fallback Value when nothing usable is stored.
	 * @return string
	 */
	private function stored_string( string $key, string $fallback ): string {
		$value = $this->settings->get( $key, $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * The redirect priority order to store from a URL & Routing save.
	 *
	 * The form renders chips only for enabled methods; a reordered post is written into the positions
	 * its methods hold, so a method without a chip keeps its place.
	 *
	 * @param array<int, string>|null $posted Sanitized posted order, or null when none was posted.
	 * @return array<int, string>
	 */
	private function merge_redirect_priority_order( ?array $posted ): array {
		$stored = array_values( array_filter( (array) $this->settings->get( 'redirect_priority_order' ), 'is_string' ) );

		if ( $posted === null ) {
			return $stored;
		}

		$effective = array_values( array_filter( (array) $this->settings->get_redirect_priority_order(), 'is_string' ) );
		$posted    = array_values( array_intersect( array_unique( $posted ), $effective ) );
		$rendered  = array_values( array_intersect( $effective, $posted ) );

		if ( $posted === $rendered ) {
			return $stored;
		}

		$queue = $posted;
		$out   = [];

		foreach ( $effective as $method ) {
			$out[] = in_array( $method, $posted, true ) ? (string) array_shift( $queue ) : $method;
		}

		return $out;
	}

	/**
	 * A checkbox list to store from a save.
	 *
	 * Checked entries come from the post; a stored entry without a checkbox is kept, in stored order.
	 * A ticked entry only an addon filter made translatable is not stored, so it ends with the addon.
	 *
	 * @param array<int|string, mixed> $posted    Sanitized posted list.
	 * @param array<int|string, mixed> $stored    Stored list.
	 * @param array<int|string, mixed> $effective Filtered list the form was rendered from.
	 * @param array<int, string>       $rendered  Entries that have a checkbox on the form.
	 * @return array<int, string>
	 */
	private static function merge_checkbox_list( array $posted, array $stored, array $effective, array $rendered ): array {
		$stored = array_values( array_filter( $stored, static fn( $name ): bool => is_string( $name ) && $name !== '' ) );
		$added  = array_diff( array_filter( $effective, 'is_string' ), $stored );
		$kept   = array_values( array_diff( array_filter( $posted, 'is_string' ), $added ) );
		$out    = [];

		foreach ( $stored as $name ) {
			if ( in_array( $name, $kept, true ) || ! in_array( $name, $rendered, true ) ) {
				$out[] = $name;
			}
		}

		foreach ( $kept as $name ) {
			if ( ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Keep a fallback chain's targets that have no chip on the form.
	 *
	 * The editor shows chips for active targets only; a stored inactive target returns to its position.
	 *
	 * @param array<string, array<int, string>> $posted   Posted rows without empty ones.
	 * @param array<int|string, mixed>          $stored   Stored fallback map.
	 * @param array<int, string>                $rendered Slugs that have a row on the form.
	 * @return array<string, array<int, string>>
	 */
	private static function keep_unrendered_fallback_targets( array $posted, array $stored, array $rendered ): array {
		foreach ( $rendered as $slug ) {
			$row = $posted[ $slug ] ?? [];

			foreach ( array_values( (array) ( $stored[ $slug ] ?? [] ) ) as $position => $target ) {
				if ( ! is_string( $target ) || $target === '' || in_array( $target, $rendered, true ) || in_array( $target, $row, true ) ) {
					continue;
				}

				array_splice( $row, min( $position, count( $row ) ), 0, [ $target ] );
			}

			if ( $row !== [] ) {
				$posted[ $slug ] = $row;
			}
		}

		return $posted;
	}

	/**
	 * Render a right-aligned "Help" nav-tab inside the settings tab bar.
	 *
	 * Opens the docs page of the active tab in a new tab; unknown tabs get the docs index.
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
		// AdminController::on_admin_init() verifies the nonce first; this repeat puts a nonce check next
		// to the $_POST reads for Plugin Check.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_settings' ) ) {
			return [];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$values = [];

		// Languages that have a row in the URL & Routing tab's per-language
		// tables; stored entries of any other language are kept.
		$rendered_slugs = [];

		if ( $tab === 'url-routing' ) {
			$rendered_slugs = array_values( array_filter( wp_list_pluck( \PerfLocale\Plugin::get_instance()->lang_repo()->get_active(), 'slug' ), 'is_string' ) );
		}

		// The fallback chain editor renders only with two or more active
		// languages; with fewer, nothing is posted and the stored map is kept.
		$fallback_rows = count( $rendered_slugs ) > 1 ? $rendered_slugs : [];

		match ( $tab ) {
			'url-routing' => $values = [
				'url_mode'                   => isset( $_POST['url_mode'] ) && in_array( $_POST['url_mode'], [ 'subdirectory', 'subdomain', 'domain', 'query' ], true ) ? sanitize_key( wp_unslash( $_POST['url_mode'] ) ) : 'subdirectory',
				'url_prefix_type'            => isset( $_POST['url_prefix_type'] ) && in_array( $_POST['url_prefix_type'], [ 'slug', 'locale' ], true ) ? sanitize_key( wp_unslash( $_POST['url_prefix_type'] ) ) : 'slug',
				'hide_default_prefix'        => isset( $_POST['hide_default_prefix'] ),
				'redirect_browser_lang'      => isset( $_POST['redirect_browser_lang'] ),
				'redirect_geo_enabled'       => isset( $_POST['redirect_geo_enabled'] ),
				// The provider select renders only when a provider is registered;
				// without one, keep the stored id.
				'geo_provider'               => isset( $_POST['geo_provider'] ) ? sanitize_key( wp_unslash( $_POST['geo_provider'] ) ) : $this->stored_string( 'geo_provider', '' ),
				'geo_cache_hours'            => isset( $_POST['geo_cache_hours'] ) ? absint( $_POST['geo_cache_hours'] ) : 24,
				'geo_country_map'            => $this->sanitize_geo_country_map( $_POST['geo_country_map'] ?? [] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_geo_country_map handles unslash + per-key sanitisation.
				'missing_translation_action' => isset( $_POST['missing_translation_action'] ) && in_array( $_POST['missing_translation_action'], [ 'show_default', 'show_404', 'redirect_default' ], true ) ? sanitize_key( wp_unslash( $_POST['missing_translation_action'] ) ) : 'show_default',
				'language_fallbacks'         => self::merge_language_map(
					array_filter( (array) $this->settings->get( 'language_fallbacks', [] ), 'is_array' ),
					self::keep_unrendered_fallback_targets(
						isset( $_POST['language_fallbacks'] ) && is_array( $_POST['language_fallbacks'] )
						? ( static function ( array $raw ): array {
							$out = [];

							foreach ( $raw as $slug => $row ) {
								// Keys are language slugs from the POST body: sanitized here as well as on write.
								$slug = sanitize_key( (string) $slug );

								if ( $slug === '' ) {
									continue;
								}

								$row = array_values( array_filter( array_map( 'sanitize_key', (array) $row ) ) );

								if ( $row !== [] ) {
									$out[ $slug ] = $row;
								}
							}

							return $out;
						// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys and every row entry are sanitize_key'd inside the closure above.
						} )( wp_unslash( (array) $_POST['language_fallbacks'] ) )
						: [],
						(array) $this->settings->get( 'language_fallbacks', [] ),
						$fallback_rows
					),
					$fallback_rows
				),
				'cookie_lifetime'            => isset( $_POST['cookie_lifetime'] ) ? absint( $_POST['cookie_lifetime'] ) : 365,
				'disable_language_cookie'    => isset( $_POST['disable_language_cookie'] ),
				'excluded_paths'             => isset( $_POST['excluded_paths'] ) ? array_map( 'sanitize_text_field', array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['excluded_paths'] ) ) ) ) ) ) : [],
				// Stored as a bare lowercase host: apply_domain() compares hosts and replaces ://host. A blank
				// row is not stored.
				'language_domains'           => self::merge_language_map(
					array_filter( (array) $this->settings->get( 'language_domains', [] ), 'is_string' ),
					isset( $_POST['language_domains'] )
					? ( static function ( array $raw ): array {
						$out = [];

						foreach ( $raw as $slug => $v ) {
							// Keys are language slugs and arrive from the POST
							// body too - sanitize them, not just the values.
							$slug = sanitize_key( (string) $slug );

							if ( $slug === '' ) {
								continue;
							}

							$v    = is_string( $v ) ? sanitize_text_field( wp_unslash( $v ) ) : '';
							$host = wp_parse_url( str_contains( $v, '//' ) ? $v : '//' . $v, PHP_URL_HOST );
							$host = strtolower( (string) ( $host ?: $v ) );

							if ( $host !== '' ) {
								$out[ $slug ] = $host;
							}
						}

						return $out;
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- keys sanitize_key'd and each value sanitized + host-normalized inside the closure above.
					} )( (array) $_POST['language_domains'] )
					: [],
					$rendered_slugs
				),
				// Rendered only while edge integration is on; otherwise keep the stored value, since an
				// unprinted checkbox would read as unticked.
				'redirect_edge_hint_enabled' => $this->settings->edge_integration_enabled()
					? isset( $_POST['redirect_edge_hint_enabled'] )
					: (bool) $this->settings->get( 'redirect_edge_hint_enabled' ),
				// Chip order; Settings::get_redirect_priority_order() also drops unknowns, dedupes and backfills.
				'redirect_priority_order'    => $this->merge_redirect_priority_order(
					isset( $_POST['redirect_priority_order'] )
						? array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['redirect_priority_order'] ) ) ) )
						: null
				),
			],
			'translation' => $values         = [
				// The tab never offers `attachment` (translation_tab_post_types()),
				// so it counts as rendered and unticked: a stored one is not kept.
				'translatable_post_types'    => self::pair_block_template_types(
					self::merge_checkbox_list(
						isset( $_POST['translatable_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['translatable_post_types'] ) : [],
						(array) $this->settings->get( 'translatable_post_types', [] ),
						$this->settings->get_translatable_post_types(),
						array_merge( [ 'attachment' ], ...array_map( 'array_keys', $this->translation_tab_post_types() ) )
					)
				),
				'translatable_taxonomies'    => self::merge_checkbox_list(
					isset( $_POST['translatable_taxonomies'] ) ? array_map( 'sanitize_key', (array) $_POST['translatable_taxonomies'] ) : [],
					(array) $this->settings->get( 'translatable_taxonomies', [] ),
					$this->settings->get_translatable_taxonomies(),
					array_map( 'strval', array_keys( get_taxonomies( [ 'public' => true ] ) ) )
				),
				'default_translation_status' => isset( $_POST['default_translation_status'] ) ? sanitize_text_field( wp_unslash( $_POST['default_translation_status'] ) ) : 'empty',
				'auto_create_stubs'          => isset( $_POST['auto_create_stubs'] ),
				'sync_fields'                => $this->merge_sync_fields(),
				'translate_slugs'            => isset( $_POST['translate_slugs'] ),
				'sync_term_hierarchy'        => isset( $_POST['sync_term_hierarchy'] ),
			],
			// With MT off the tab prints only mt_enabled: the other fields are read only while the stored flag
			// is on (extraction runs before Settings::update()), or unprinted fields would save as cleared and
			// mt_auto_translate_languages would become the no-language scope.
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
				// Active slugs only; with every target checked store [] ("all"), so later languages are included.
				'mt_auto_translate_languages'  => ( function (): array {
					$posted  = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['mt_auto_translate_languages'] ?? [] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each entry sanitize_key'd then whitelisted against active slugs.
					$router  = \PerfLocale\Plugin::get_instance()->get( 'router' );
					$default = $router->get_default_language();
					$active  = [];

					// The default language is the MT source, not a target: it has no checkbox and is not counted.
					foreach ( $router->get_active_languages() as $l ) {
						if ( isset( $l->slug ) && ( ! $default || $l->slug !== $default->slug ) ) {
							$active[] = (string) $l->slug;
						}
					}

					$subset = array_values( array_intersect( $posted, $active ) );

					// None checked: [] means "all", so store the reserved no-language sentinel.
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
				// No control on this tab: keep the stored value unless one is posted.
				'switcher_flag_style'        => isset( $_POST['switcher_flag_style'] ) ? sanitize_text_field( wp_unslash( $_POST['switcher_flag_style'] ) ) : $this->stored_string( 'switcher_flag_style', 'rectangular' ),
				'switcher_show_untranslated' => isset( $_POST['switcher_show_untranslated'] ),
				'switcher_hide_current'      => isset( $_POST['switcher_hide_current'] ),
				'switcher_untranslated_link' => isset( $_POST['switcher_untranslated_link'] ) ? sanitize_text_field( wp_unslash( $_POST['switcher_untranslated_link'] ) ) : 'homepage',
				'switcher_arrow_style'       => isset( $_POST['switcher_arrow_style'] ) && in_array( $_POST['switcher_arrow_style'], [ 'single', 'double', 'none' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_arrow_style'] ) ) : 'single',
				'switcher_trigger_format'    => isset( $_POST['switcher_trigger_format'] ) && in_array( $_POST['switcher_trigger_format'], [ 'inherit', 'native', 'english', 'both', 'slug' ], true ) ? sanitize_key( wp_unslash( $_POST['switcher_trigger_format'] ) ) : 'inherit',
				'switcher_auto_insert'       => isset( $_POST['switcher_auto_insert'] ),
				// Whitelisted verbatim against the theme's registered locations: core slugs are case-sensitive
				// (`headerMenu`), so no sanitize_key(); the intersect is the security check. Stored locations
				// of another theme have no checkbox and are kept.
				'switcher_menu_locations'    => self::merge_checkbox_list(
					array_values(
						array_intersect(
							array_map( 'strval', (array) wp_unslash( $_POST['switcher_menu_locations'] ?? [] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted verbatim against registered keys below.
							array_keys( get_registered_nav_menus() )
						)
					),
					(array) $this->settings->get( 'switcher_menu_locations', [] ),
					[],
					array_map( 'strval', array_keys( get_registered_nav_menus() ) )
				),
				'admin_bar_switcher'         => isset( $_POST['admin_bar_switcher'] ),
			],
			'performance' => $values       = [
				'cache_object_enabled'    => isset( $_POST['cache_object_enabled'] ),
				'cache_preload_slugs'     => isset( $_POST['cache_preload_slugs'] ),
				'string_translation_mode' => isset( $_POST['string_translation_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['string_translation_mode'] ) ) : 'files',
				// Whitelisted values: the string default alone would let any posted string through sanitising.
				'background_processing'   => isset( $_POST['background_processing'] ) && in_array( $_POST['background_processing'], [ 'auto', 'always', 'never' ], true )
					? sanitize_key( wp_unslash( $_POST['background_processing'] ) )
					: 'auto',
				// The radio renders only while Action Scheduler is loaded; without it, keep the stored choice.
				'background_engine'       => ! \PerfLocale\Background\JobRunnerFactory::action_scheduler_available()
					? (string) $this->settings->get( 'background_engine', 'auto' )
					: ( isset( $_POST['background_engine'] ) && in_array( $_POST['background_engine'], [ 'auto', 'force_wp_cron' ], true )
						? sanitize_key( wp_unslash( $_POST['background_engine'] ) )
						: 'auto' ),
				// Per-job overrides, keys whitelisted against background_job_types(); zero or missing uses the
				// job default (a stored zero would force every dispatch async).
				'background_thresholds'   => $this->extract_background_thresholds(),
				'background_paused'       => isset( $_POST['background_paused'] ),
			],
			'advanced' => $values    = [
				'edge_integration_enabled' => isset( $_POST['edge_integration_enabled'] ),
				'cdn_cache_tags_enabled'   => isset( $_POST['cdn_cache_tags_enabled'] ),
				'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ),
				'dashboard_widget_enabled' => isset( $_POST['dashboard_widget_enabled'] ),
				'abilities_enabled'        => isset( $_POST['abilities_enabled'] ),
				'abilities_write_enabled'  => isset( $_POST['abilities_write_enabled'] ),
			],
			'woocommerce' => $values = [
				'wc_email_translation'      => isset( $_POST['wc_email_translation'] ),
				'wc_sync_stock'             => isset( $_POST['wc_sync_stock'] ),
				'wc_sync_prices'            => isset( $_POST['wc_sync_prices'] ),
				'wc_currency_per_lang'      => isset( $_POST['wc_currency_per_lang'] ),
				// The currency table is hidden while per-language currency is
				// off, so its rendered defaults are not stored then.
				'wc_currencies'             => ! isset( $_POST['wc_currency_per_lang'] )
					? (array) $this->settings->get( 'wc_currencies', [] )
					: ( class_exists( 'PerfLocaleWooCommerce' ) ? \PerfLocaleWooCommerce::sanitize_currencies_post() : [] ),
				'wc_exchange_rate_auto'     => isset( $_POST['wc_exchange_rate_auto'] ),
				// The provider select renders only when a provider is registered;
				// without one, keep the stored id.
				'wc_exchange_rate_provider' => isset( $_POST['wc_exchange_rate_provider'] ) ? sanitize_key( wp_unslash( $_POST['wc_exchange_rate_provider'] ) ) : $this->stored_string( 'wc_exchange_rate_provider', '' ),
				'wc_exchange_rate_interval' => isset( $_POST['wc_exchange_rate_interval'] ) ? sanitize_key( wp_unslash( $_POST['wc_exchange_rate_interval'] ) ) : 'daily',
			],
			'export-import' => $values = [], // Export/import handled separately via admin_init.
			default => $values         = [],
		};
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// The key inputs render empty, so an empty one keeps the stored key.
		// A changed provider address clears its stored key unless a new key is
		// typed: Settings::update() applies that rule for every writer.
		if ( $tab === 'machine-translation' ) {
			foreach ( self::MT_SECRET_KEYS as $secret ) {
				if ( isset( $values[ $secret ] ) && $values[ $secret ] === '' ) {
					unset( $values[ $secret ] );
				}
			}
		}

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

		// Machine Translation is always listed: its enable checkbox is inside this subtab.
		$subtabs['machine-translation'] = __( 'Machine Translation', 'perflocale' );

		// WooCommerce subtab: needs WooCommerce and the add-on not disabled.
		if ( class_exists( 'WooCommerce' ) && ! \PerfLocale\Addon\AddonRegistry::is_disabled( 'woocommerce' ) ) {
			$subtabs['woocommerce'] = __( 'WooCommerce', 'perflocale' );
		}

		// One subtab per registered addon with editable fields, except those with a built-in subtab.
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
	 * Auto-generated subtab IDs: one per registered addon with user-editable fields.
	 *
	 * Used by get_addon_subtabs() and to route render_addons_tab() to the generated form.
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

		// Operator-disabled addons get no subtab; it returns when re-enabled.
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

		// Reachable only when the public addon_subtabs filter empties the list (admin-render.php drives
		// this path): explain instead of rendering an empty form.
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

		// A subtab was named in the URL but is not available - say so instead of
		// silently showing a different one, which reads as "my settings vanished".
		if ( $requested !== '' && $requested !== $subtab ) {
			?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: requested addon subtab slug, 2: label of the subtab shown instead */
						esc_html__( 'There are no settings for %1$s on this site - it is turned off or not installed. Showing %2$s instead.', 'perflocale' ),
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
					// Auto-generated addon subtab: its own form to handle_save_addon_settings, since addon
					// settings live in their own option.
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
	 * Renders the WooCommerce subtab through the WC addon class, which owns that UI.
	 *
	 * It uses the main settings form: its data lives in the `perflocale_settings.wc_*` keys.
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

		// WC addon isn't registered (WooCommerce plugin not active) -
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
	 * True if an auto-addon subtab's addon is booted, for the subtab nav's Active pill.
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
	 * Posts to handle_save_addon_settings (AddonSettings::set_addon(): size cap, lock, save hooks),
	 * outside the perflocale_save_settings form, since addon settings live in their own option.
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

		// Editable fields only; the storage layer keeps addon-managed state on save.
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
		// The edge-hint redirect needs its own toggle and edge integration on.
		$redirect_edge_hint    = (bool) $this->settings->get( 'redirect_edge_hint_enabled' )
			&& $this->settings->edge_integration_enabled();
		$geo_provider          = (string) $this->settings->get( 'geo_provider', '' );
		$geo_cache_hours       = (int) $this->settings->get( 'geo_cache_hours', 24 );
		$geo_country_map       = (array) $this->settings->get( 'geo_country_map', [] );
		$missing_action        = $this->settings->get_missing_translation_action();
		$cookie_lifetime       = (int) $this->settings->get( 'cookie_lifetime' );
		$excluded_paths        = $this->settings->get_excluded_paths();

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
						<?php echo esc_html__( 'Query parameter mode works with every permalink structure - including Plain - and every server. The default language always uses clean URLs without the parameter.', 'perflocale' ); ?>
					</p>
					<p class="description" style="margin-top:6px;">
						<?php echo esc_html__( 'Subdirectory needs no DNS work and concentrates SEO value on one hostname; subdomain and domain-per-language require DNS, a TLS certificate, and - on multisite - every language hostname registered as a site.', 'perflocale' ); ?>
						<a href="https://perflocale.com/docs/url-routing/#url-structure" target="_blank" rel="noopener"><?php echo esc_html__( 'Compare the four URL modes', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
					</p>
				</fieldset>

				<?php
				// Per-language domain table: used in domain mode only, but rendered hidden in every mode so a
				// save keeps the stored domains; admin-settings.js toggles it with the URL mode.
				$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
				$all_langs = $lang_repo->get_active();
				$domains   = $this->settings->get_language_domains();
				$base_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';
				?>
				<div id="perflocale-domain-config" style="margin-top:12px;<?php echo esc_attr( $url_mode !== 'domain' ? 'display:none;' : '' ); ?>">
					<p class="description" style="margin-bottom:8px;">
						<?php echo esc_html__( 'Configure the domain for each language. DNS must be pointed to this server.', 'perflocale' ); ?>
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
								?>
								<tr>
									<td style="padding-left:8px;"><?php echo esc_html( $flag . ' ' . ( $lang->native_name ?: $lang->name ) ); ?></td>
									<td>
										<input type="text" name="language_domains[<?php echo esc_attr( $lang->slug ); ?>]"
											value="<?php echo esc_attr( $default ); ?>"
											placeholder="<?php echo esc_attr( $base_host ); ?>"
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
		// The reorder UI shows with 2+ redirect methods; with one, the order still round-trips in
		// hidden inputs so it survives.
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
							<span class="pl-fb-chip__move" aria-hidden="false"><button type="button" class="pl-fb-chip__up" aria-label="<?php echo esc_attr__( 'Move earlier', 'perflocale' ); ?>" title="<?php echo esc_attr__( 'Move earlier', 'perflocale' ); ?>">&#9650;</button><button type="button" class="pl-fb-chip__down" aria-label="<?php echo esc_attr__( 'Move later', 'perflocale' ); ?>" title="<?php echo esc_attr__( 'Move later', 'perflocale' ); ?>">&#9660;</button></span>
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
						<?php
						// A stored id no provider registers (its plugin is off, or the id changed) gets its own option,
						// so saving the tab unchanged keeps it instead of posting the first registered one.
						if ( '' !== $geo_provider && ! isset( $geo_providers[ $geo_provider ] ) ) :
							?>
							<option value="<?php echo esc_attr( $geo_provider ); ?>" selected="selected">
								<?php
								/* translators: %s: provider id stored in the settings. */
								echo esc_html( sprintf( __( '%s (not registered)', 'perflocale' ), $geo_provider ) );
								?>
							</option>
						<?php endif; ?>
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

		// The table renders only with a non-default active language to map.
		$geo_mappable = array_values( array_filter( $geo_langs, static fn( $l ) => ! empty( $l->slug ) && (string) $l->slug !== $geo_default_slug ) );

		if ( count( $geo_mappable ) >= 1 ) :
			?>
		<tr class="perflocale-geo-field">
			<th scope="row"><?php echo esc_html__( 'Country Mapping', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:8px;"><?php echo esc_html__( 'Map country codes to languages. Comma-separated ISO 3166-1 alpha-2 codes (e.g., US,GB,AU).', 'perflocale' ); ?></p>
				<?php
				// The note depends on whether the default locale has a country: GeoIP redirects only away from
				// the default, so a generic default (ar) needs no mapping.
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
							__( 'The default language (<strong>%s</strong>) is intentionally not mappable here - visitors whose country doesn&rsquo;t match any other mapping already stay on the default, so an explicit mapping for it would over-redirect.', 'perflocale' ),
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
							__( 'Your default language (<strong>%1$s</strong>, locale <code>%2$s</code>) doesn&rsquo;t carry a country in its locale - that&rsquo;s fine. Visitors from any country that isn&rsquo;t mapped to a different language above will stay on the default automatically. <strong>You don&rsquo;t need a country code for the default</strong>; the default IS the catch-all.', 'perflocale' ),
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

						// The locale's country (`en_US` gives `US`) is only the placeholder: the runtime derives it for a
						// blank row, so it is never stored.
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
		// Fallback chains: each list is the exact order tried, with no walk through other chains.
		$all_langs = ( new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) ) )->get_active();
		$fallbacks = $this->settings->get_language_fallbacks();

		// At most 4 fallbacks, and never more than the other active languages.
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
						// Chips are for active targets only, so only those count
						// toward the cap. A stored target that is not active stays
						// stored (keep_unrendered_fallback_targets()).
						$flag        = \PerfLocale\Helper::get_flag_emoji( $lang );
						$current_row = array_values( array_filter( (array) ( $fallbacks[ $lang->slug ] ?? [] ), static fn( $s ) => $s !== '' && $s !== $lang->slug && isset( $lang_by_slug[ $s ] ) ) );
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
										<span class="pl-fb-chip__move" aria-hidden="false"><button type="button" class="pl-fb-chip__up" aria-label="<?php echo esc_attr__( 'Move earlier', 'perflocale' ); ?>" title="<?php echo esc_attr__( 'Move earlier', 'perflocale' ); ?>">&#9650;</button><button type="button" class="pl-fb-chip__down" aria-label="<?php echo esc_attr__( 'Move later', 'perflocale' ); ?>" title="<?php echo esc_attr__( 'Move later', 'perflocale' ); ?>">&#9660;</button></span>
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
					<?php echo esc_html__( 'Cookieless mode - never set the language-preference cookie (perflocale_lang).', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'For strict GDPR / cookie-consent setups. Language routing keeps working (it is URL-based); you lose "remember my language" on non-prefixed URLs, and the automatic first-visit redirects (browser language, GeoIP, edge hint) do not run, because without the cookie they would redirect the visitor again on every page. A consent plugin can also gate the cookie via the perflocale/privacy/consent_given filter.', 'perflocale' ); ?></p>
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
		// Repeated nonce check next to the $_POST reads for Plugin Check; AdminController::on_admin_init()
		// is the real gate.
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

		$fields = array_values( array_unique( array_merge( $checkbox_fields, $custom_meta ) ) );

		// The plugin's own meta keys (registry entries with sync `never`, and `_perflocale_` keys) describe
		// one post each: mirrored, a sibling would take over another post's data. Left out and named.
		self::$refused_sync_fields = array_values( array_filter( $fields, [ DataRegistry::class, 'is_never_sync_meta' ] ) );

		return array_values( array_diff( $fields, self::$refused_sync_fields ) );
	}

	/**
	 * Sync Fields entries the last settings save left out because they are
	 * the plugin's own meta keys.
	 *
	 * @return array<int, string>
	 */
	public static function last_refused_sync_fields(): array {
		return self::$refused_sync_fields;
	}

	/**
	 * Keep `wp_template` and `wp_template_part` switched on together.
	 *
	 * One feature behind two types: a template without its parts renders a half-translated page.
	 * `perflocale/translatable_post_types` can still enable one alone.
	 *
	 * @param array<int, string> $types Submitted post types.
	 * @return array<int, string>
	 */
	private static function pair_block_template_types( array $types ): array {
		$has_any = array_intersect( [ 'wp_template', 'wp_template_part' ], $types ) !== [];

		// Add the missing one of the pair after the rest, and leave every other
		// entry where it is, so an unchanged list is written back unchanged.
		if ( $has_any ) {
			$types[] = 'wp_template';
			$types[] = 'wp_template_part';
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * The post types the Translation tab shows a checkbox for: the public ones
	 * except `attachment`, and the curated non-public ones.
	 *
	 * @return array{0: array<string, \WP_Post_Type>, 1: array<string, \WP_Post_Type>} Public types, then non-public types.
	 */
	private function translation_tab_post_types(): array {
		$all_post_types = get_post_types( [ 'public' => true ], 'objects' );

		// `attachment` is never offered: media is translated in place (MediaTranslationManager), and
		// scoping it would language-filter every front-end attachment query.
		unset( $all_post_types['attachment'] );

		// Curated, not get_post_types( [ 'public' => false ] ): listed only when something serves the
		// translation. Template types rely on PostQueryFilter::never_scoped_post_types(): see
		// docs-ai/11-code-rationale.md, "SettingsPage: non-public post types".
		/**
		 * Non-public post types offered on the settings screen.
		 *
		 * @hook perflocale/settings/non_public_post_types
		 * @param string[] $types Post type names.
		 */
		$non_public_names = (array) apply_filters(
			'perflocale/settings/non_public_post_types',
			[ 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ]
		);

		$non_public_types = [];

		foreach ( $non_public_names as $non_public_name ) {
			$non_public_object = get_post_type_object( (string) $non_public_name );

			// Registered and not public only: a public type is already listed above.
			if ( $non_public_object && empty( $non_public_object->public ) ) {
				$non_public_types[ $non_public_object->name ] = $non_public_object;
			}
		}

		return [ $all_post_types, $non_public_types ];
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

		[ $all_post_types, $non_public_types ] = $this->translation_tab_post_types();
		$all_taxonomies                        = get_taxonomies( [ 'public' => true ], 'objects' );

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

		// The bulk tools below run from admin-settings.js on perflocaleBulkFetch (admin-bulk-fetch.js);
		// this tab loads the helper and gives them their data.
		\PerfLocale\Admin\Assets::enqueue_script( 'perflocale-admin-bulk-fetch', 'admin-bulk-fetch.js' );
		\PerfLocale\Admin\Assets::add_dependency( 'perflocale-admin-settings', 'perflocale-admin-bulk-fetch' );

		wp_localize_script(
			'perflocale-admin-settings',
			'perflocaleTranslationData',
			[
				'taxNonce'         => wp_create_nonce( 'perflocale_create_taxonomy_translations' ),
				'postLangNonce'    => wp_create_nonce( 'perflocale_assign_post_languages' ),
				'genTrNonce'       => wp_create_nonce( 'perflocale_generate_missing_translations' ),
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
			]
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
				<?php if ( $non_public_types ) : ?>
					<?php
					// A separate collapsed group: patterns and posts are not comparable choices.
					?>
					<details class="perflocale-settings__advanced-types">
						<summary><?php echo esc_html__( 'Advanced content types', 'perflocale' ); ?></summary>
						<p class="description">
							<?php echo esc_html__( 'Content types that are not part of your site\'s public pages, but can still hold text your visitors read.', 'perflocale' ); ?>
						</p>
						<fieldset>
							<?php
							foreach ( $non_public_types as $npt ) :
								// The two block-template types share ONE control below -
								// see pair_block_template_types().
								if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $npt->name ) ) {
									continue;
								}
								?>
								<label>
									<input type="checkbox" name="translatable_post_types[]" value="<?php echo esc_attr( $npt->name ); ?>" <?php checked( in_array( $npt->name, $translatable_pts, true ) ); ?>>
									<?php echo esc_html( $npt->labels->name ); ?> <code>(<?php echo esc_html( $npt->name ); ?>)</code>
								</label>
								<?php if ( 'wp_block' === $npt->name ) : ?>
									<span class="description">
										- <?php echo esc_html__( 'synced patterns; each language gets its own copy, and pages using the pattern show the right one automatically.', 'perflocale' ); ?>
									</span>
								<?php elseif ( 'wp_navigation' === $npt->name ) : ?>
									<span class="description">
										- <?php echo esc_html__( 'menus in block themes. Link addresses are already translated without this; switch it on to translate the menu labels too.', 'perflocale' ); ?>
									</span>
								<?php endif; ?>
								<br>
							<?php endforeach; ?>
						</fieldset>
					</details>
				<?php endif; ?>
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
		<?php
		// Full site editing gets its own row: on a block theme it holds the header, footer and layouts.
		$fse_types   = array_filter(
			$non_public_types,
			static fn( $t ) => \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $t->name )
		);
		$fse_enabled = array_intersect( [ 'wp_template', 'wp_template_part' ], $translatable_pts ) !== [];

		if ( $fse_types ) :
			?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Full Site Editing', 'perflocale' ); ?></th>
			<td>
				<fieldset>
					<label>
						<input type="checkbox" name="translatable_post_types[]" value="wp_template" <?php checked( $fse_enabled ); ?>>
						<?php echo esc_html__( 'Translate block theme templates and template parts', 'perflocale' ); ?>
					</label>
					<p class="description">
						<?php echo esc_html__( 'Text you typed directly into a block theme\'s templates and template parts - a header, footer, or 404 page. Both are switched on together: translating a page layout without its header leaves a half-translated page.', 'perflocale' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'Templates you have not customised are unaffected - their text already follows the theme\'s own translations. The Site Editor always shows and saves the original.', 'perflocale' ); ?>
					</p>
				</fieldset>
			</td>
		</tr>
		<?php endif; ?>
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
				<p class="description"><?php echo esc_html__( 'Uncheck to allow deliberately different category trees per language - e.g. a smaller, flatter catalog in one market. Term names, slugs, and descriptions are always translator-owned.', 'perflocale' ); ?></p>
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
					<?php echo esc_html__( 'Term archives use the clean per-language slug PerfLocale records - German stays at /de/tutorials/ rather than the unique tutorials-de the database needed - but the plugin does not translate slugs for you.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/url-routing/#translated-slugs" target="_blank" rel="noopener"><?php echo esc_html__( 'How display slugs work', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Bulk Translation', 'perflocale' ); ?></h3></td>
		</tr>
		<?php $unimported = \PerfLocale\Migration\MigrationState::unimported_sources(); ?>
		<?php if ( $unimported !== [] ) : ?>
			<tr>
				<td colspan="2">
					<div class="notice notice-warning inline">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: source plugin name(s), e.g. "WPML" or "WPML and Polylang" */
									__( '%s translations were found that have not been imported yet. Import them first (Settings → Export & Import). The tools below, run before the import, give those translations the default language and create duplicate drafts.', 'perflocale' ),
									\PerfLocale\Migration\MigrationState::source_names( $unimported )
								)
							);
							?>
						</p>
					</div>
				</td>
			</tr>
		<?php endif; ?>
		<?php $incomplete = $unimported === [] ? \PerfLocale\Migration\MigrationState::incomplete_sources() : []; ?>
		<?php if ( $incomplete !== [] ) : ?>
			<tr>
				<td colspan="2">
					<div class="notice notice-warning inline">
						<p><?php echo esc_html( \PerfLocale\Migration\MigrationState::incomplete_message( $incomplete ) ); ?></p>
					</div>
				</td>
			</tr>
		<?php endif; ?>
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
					<?php echo esc_html__( 'Give every item that has no language yet the site\'s default language. This covers everything ticked above - posts, pages, products, and the types your addons add, such as forms and patterns. Anything that already has a language is left alone. Run it once after installing PerfLocale on an existing site, so language-aware pickers (nav menus, category checklists) stop offering unmanaged content in every language. Large sites are processed in batches and the button keeps going until it is finished.', 'perflocale' ); ?>
				</p>
				<p class="description" style="margin:0 0 8px;">
					<strong><?php echo esc_html__( 'This is step 1 of 2.', 'perflocale' ); ?></strong>
					<?php echo esc_html__( 'Assigning a language is what makes "Generate Missing Translations" below able to see your existing content. Run this one first, then that one.', 'perflocale' ); ?>
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
		<tr>
			<th scope="row"><?php echo esc_html__( 'Generate Missing Translations', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin:0 0 8px;">
					<?php echo esc_html__( 'Create the missing translation drafts for content you already have, in every active language. Use this when you enable a new language, or turn on a content type whose existing items have no translations yet - forms and patterns are the usual cases, because nothing creates those in bulk otherwise.', 'perflocale' ); ?>
				</p>
				<p class="description" style="margin:0 0 8px;">
					<strong><?php echo esc_html__( 'Run "Assign Default Language" first.', 'perflocale' ); ?></strong>
					<?php echo esc_html__( 'This button only finds items that already have a language, so on a site that had content before PerfLocale it reports nothing to do until the button above has run.', 'perflocale' ); ?>
				</p>
				<p class="description" style="margin:0 0 8px;">
					<?php echo esc_html__( 'Drafts only, with no content copied and no machine translation - nothing becomes visible to visitors, and nothing is sent to a provider. Existing translations are never touched, so running it twice creates nothing the second time. It tells you how many it will create before it starts.', 'perflocale' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:8px;">
					<button type="button" class="button" id="perflocale-generate-translations">
						<?php echo esc_html__( 'Generate Missing Translations', 'perflocale' ); ?>
					</button>
				</div>
				<div id="perflocale-gentr-progress" style="display:none;margin-top:10px;max-width:420px;">
					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
						<span id="perflocale-gentr-status" style="font-size:13px;color:#50575e;"></span>
						<span id="perflocale-gentr-percent" style="font-size:12px;color:#50575e;font-weight:500;"></span>
					</div>
					<div style="width:100%;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
						<div id="perflocale-gentr-bar" style="width:0;height:100%;background:#2271b1;border-radius:4px;transition:width 0.3s ease;"></div>
					</div>
				</div>
				<div id="perflocale-gentr-result" style="margin-top:8px;"></div>
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
					// WP AI Client: selectable when the API is loaded or a resolver is plugged in, else listed
					// disabled. Detection mirrors WpAiClientProvider::resolve_client_callback().
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
				<?php
				// The WordPress AI Client takes its provider and key from core's
				// Connectors screen, which exists only on WordPress versions that
				// ship it.
				if ( 'wp_ai_client' === $mt_provider && file_exists( ABSPATH . 'wp-admin/options-connectors.php' ) ) :
					?>
					<p class="description">
						<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php echo esc_html__( 'Connect an AI provider under Settings → Connectors.', 'perflocale' ); ?></a>
					</p>
				<?php endif; ?>
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
					<?php echo esc_html__( 'Lets translators MT-translate selected, filtered, or every string in a single dispatch (up to 5,000 strings per run). Disable to keep MT available for per-row edits only - useful when controlling provider costs.', 'perflocale' ); ?>
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
					<?php echo esc_html__( 'This is the only ceiling on what your translation provider can bill you in a month - 0 means unlimited, which with auto-translate on publish leaves the spend uncapped.', 'perflocale' ); ?>
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
				<p class="description">
					<?php
					printf(
						/* translators: %s: filter name */
						esc_html__( 'For a server on this machine (localhost) or on a private network, add its host to the %s filter.', 'perflocale' ),
						'<code>perflocale/mt/trusted_hosts</code>'
					);
					?>
				</p>
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
		// Stored verbatim (case-sensitive) - nav-menu location slugs can be
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
						<?php echo esc_html__( 'Keep Files unless your filesystem is read-only or the translations directory is not writable - that is the only reason to choose Database.', 'perflocale' ); ?>
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
	 * Its four fields are read back by extract_tab_values() under the `performance` tab.
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
						<?php esc_html_e( 'Always run inline. Not recommended on large sites - long operations can time out.', 'perflocale' ); ?>
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
						<?php esc_html_e( 'Auto - use Action Scheduler', 'perflocale' ); ?>
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
			'wc_stock_check'           => static fn() => new \PerfLocale\WooCommerce\StockCheckJob(),
			'wc_stock_align'           => static fn() => new \PerfLocale\WooCommerce\StockAlignJob(),
		];
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background Thresholds', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin: 0 0 10px;">
					<?php esc_html_e( 'In Auto mode, operations below their threshold run inline; above, they queue. Leave a row blank to use the default. Filter perflocale/jobs/threshold/<type> is the programmatic equivalent.', 'perflocale' ); ?>
				</p>
				<?php
				// The data-label attributes make the phone layout: below 782px core's `.form-table td` rule
				// stacks these cells too, and a CSS `content:` string cannot be translated.
				$bg_label_default  = __( 'Default', 'perflocale' );
				$bg_label_override = __( 'Override', 'perflocale' );
				?>
				<table class="widefat striped perflocale-bgthresh" style="max-width:560px;">
					<caption class="screen-reader-text"><?php esc_html_e( 'Background job thresholds, with each job\'s default and the operator\'s override.', 'perflocale' ); ?></caption>
					<thead>
						<tr>
							<th scope="col" style="padding-left:14px;"><?php esc_html_e( 'Operation', 'perflocale' ); ?></th>
							<th scope="col" style="width:120px;"><?php echo esc_html( $bg_label_default ); ?></th>
							<th scope="col" style="width:160px;"><?php echo esc_html( $bg_label_override ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( self::shown_background_job_types() as $type => $label ) :
							$default  = (int) $factories[ $type ]()->get_default_threshold();
							$override = isset( $thresholds[ $type ] ) ? (int) $thresholds[ $type ] : 0;
							$field_id = 'perflocale-bgthresh-' . $type;
							?>
							<tr>
								<td class="perflocale-bgthresh__op" style="padding-left:14px;">
									<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
								</td>
								<td class="perflocale-bgthresh__default" data-label="<?php echo esc_attr( $bg_label_default ); ?>"><code><?php echo esc_html( (string) $default ); ?></code></td>
								<td class="perflocale-bgthresh__override" data-label="<?php echo esc_attr( $bg_label_override ); ?>">
									<input
										type="number"
										min="1"
										step="1"
										id="<?php echo esc_attr( $field_id ); ?>"
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
	 * Opt-in except schema; an off feature registers no hooks and no REST routes.
	 *
	 * @return void
	 */
	private function render_advanced_tab(): void {
		$edge_enabled  = (bool) $this->settings->get( 'edge_integration_enabled' );
		$cache_tags_on = (bool) $this->settings->get( 'cdn_cache_tags_enabled' );
		$config_url    = rest_url( 'perflocale/v1/config' );
		$abilities_on  = (bool) $this->settings->get( 'abilities_enabled', true );
		$ability_write = (bool) $this->settings->get( 'abilities_write_enabled', false );
		$abilities_api = function_exists( 'wp_register_ability' );
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'AI Agent Abilities', 'perflocale' ); ?></th>
			<td>
				<?php if ( ! $abilities_api ) : ?>
					<p class="description">
						<?php echo esc_html__( 'Requires WordPress 6.9 or newer. This site is running an older version, so nothing is registered either way.', 'perflocale' ); ?>
					</p>
				<?php endif; ?>
				<label>
					<input type="checkbox" name="abilities_enabled" value="1" <?php checked( $abilities_on ); ?>>
					<?php echo esc_html__( 'Let AI tools and agents look up translation information', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Registers four read-only abilities through WordPress\'s Abilities API: list languages, get a post\'s translations, detect a post\'s language, and convert a URL to another language. They respect the calling user\'s permissions and cost nothing when no agent is installed.', 'perflocale' ); ?>
				</p>
				<label style="display:block;margin-top:10px;">
					<input type="checkbox" name="abilities_write_enabled" value="1" <?php checked( $ability_write ); ?>>
					<?php echo esc_html__( 'Also let them create and machine-translate content', 'perflocale' ); ?>
				</label>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Adds two abilities that WRITE: create a translation, and machine-translate a post. Machine translation spends your provider quota, so an agent could use it without you seeing the request. Off by default for that reason - turn it on if you are deliberately automating translation through an agent.', 'perflocale' ); ?>
				</p>
			</td>
		</tr>
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
				<p class="description"><?php echo wp_kses( __( 'When enabled, deleting the plugin will remove all translation data, languages, settings, and database tables. When disabled, your data is preserved for reinstallation. This is a one-shot teardown setting - toggle it before deleting the plugin from <em>Plugins → Installed Plugins</em>.', 'perflocale' ), [ 'em' => [] ] ); ?></p>
			</td>
		</tr>
		<?php
	}


	/**
	 * Extract and sanitize per-job background-threshold overrides from POST.
	 *
	 * Keys are whitelisted against background_job_types(); empty, zero or negative inputs are
	 * dropped so the job's default applies.
	 *
	 * @return array<string, int>
	 */
	private function extract_background_thresholds(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by AdminController.
		if ( empty( $_POST['background_thresholds'] ) || ! is_array( $_POST['background_thresholds'] ) ) {
			return [];
		}

		$out   = [];
		$shown = self::shown_background_job_types();
		$kept  = (array) $this->settings->get( 'background_thresholds', [] );

		foreach ( self::background_job_types() as $type => $_label ) {
			// A row this screen did not show (the WooCommerce stock jobs
			// while the add-on or Inventory Sync is off) keeps its override.
			if ( ! isset( $shown[ $type ] ) ) {
				$value = $kept[ $type ] ?? null;

				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$out[ $type ] = (int) $value;
				}

				continue;
			}

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
	 * Registered job types and their labels, for the Performance tab threshold rows.
	 *
	 * Mirrors the WorkerRegistry registrations in Bootstrap that dispatch by threshold; auto_translate always queues.
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
			'wc_stock_check'           => __( 'WooCommerce stock check', 'perflocale' ),
			'wc_stock_align'           => __( 'WooCommerce stock alignment', 'perflocale' ),
		];
	}

	/**
	 * The job types whose threshold row the Performance tab shows: every
	 * type, except the WooCommerce stock jobs while the WooCommerce add-on
	 * did not boot or Inventory Sync is off.
	 *
	 * @return array<string, string>
	 */
	private static function shown_background_job_types(): array {
		$types    = self::background_job_types();
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;
		$stock    = \PerfLocale\Helper::get_instance()->is_addon_active( 'woocommerce' )
			&& $settings instanceof \PerfLocale\Settings
			&& (bool) $settings->get( 'wc_sync_stock', true );

		// Aligning stock is off in this version (Api::align_off()).
		unset( $types['wc_stock_align'] );

		if ( ! $stock ) {
			unset( $types['wc_stock_check'] );
		}

		return $types;
	}

	/**
	 * Renders the import outcome the Export & Import handlers redirect back with (ImportNotice).
	 *
	 * Printed above the tab strip, where core's admin script would otherwise move it.
	 *
	 * @return void
	 */
	private function render_import_notices(): void {
		$notice  = \PerfLocale\Admin\ImportNotice::take();
		$type    = $notice['type'] ?? '';
		$message = $notice['message'] ?? '';
		?>

		<?php if ( 'success' === $type ) : ?>
			<div class="notice notice-success" style="margin:16px 0 8px;"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'warning' === $type ) : ?>
			<div class="notice notice-warning" style="margin:16px 0 8px;"><p><strong><?php esc_html_e( 'Needs attention:', 'perflocale' ); ?></strong> <?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'error' === $type ) : ?>
			<div class="notice notice-error" style="margin:16px 0 8px;"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * WPML's default language and languages, against PerfLocale's.
	 *
	 * Reads WPML's settings option and its locale tables only (no content
	 * scan), so the tab stays cheap; the import runs the full check.
	 *
	 * @param \PerfLocale\Cache\CacheManager $cache Cache manager.
	 * @return void
	 */
	private function render_wpml_language_check( \PerfLocale\Cache\CacheManager $cache ): void {
		$reader   = new \PerfLocale\Migration\WpmlSettingsReader();
		$settings = $reader->settings();

		if ( ! $settings['found'] || $settings['default'] === '' ) {
			return;
		}

		$importer  = new \PerfLocale\Migration\WpmlImporter( $cache );
		$locales   = $reader->locales();
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$pl_def    = $lang_repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $lang_repo->get_default() : null;
		$pl_slug   = $pl_def !== null ? (string) $pl_def->slug : '';
		$label     = static fn( string $code ): string => isset( $locales[ $code ] ) ? $code . ' (' . $locales[ $code ] . ')' : $code;
		$missing   = [];

		foreach ( array_unique( array_merge( [ $settings['default'] ], $settings['active'] ) ) as $code ) {
			if ( $importer->language_for_code( (string) $code ) === null ) {
				$missing[] = $label( (string) $code );
			}
		}

		$default_ok = $importer->language_for_code( $settings['default'] ) === $pl_slug;
		?>
		<ul class="perflocale-migration-card__checks">
			<li>
				<span class="dashicons <?php echo esc_attr( $default_ok ? 'dashicons-yes' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: WPML default language, e.g. "fr (fr_FR)", 2: PerfLocale default language slug */
						__( 'WPML\'s default language: %1$s. PerfLocale\'s default language: %2$s.', 'perflocale' ),
						$label( $settings['default'] ),
						$pl_slug
					)
				);
				echo ' ';
				echo esc_html(
					$default_ok
						? __( 'They match.', 'perflocale' )
						: sprintf(
							/* translators: %s: WPML default language, e.g. "fr (fr_FR)" */
							__( 'They differ: make %s the default language under PerfLocale → Languages before the import.', 'perflocale' ),
							$label( $settings['default'] )
						)
				);
				?>
			</li>
			<li>
				<span class="dashicons <?php echo esc_attr( $missing === [] ? 'dashicons-yes' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>
				<?php
				echo esc_html(
					$missing === []
						? __( 'Every WPML language has a matching PerfLocale language.', 'perflocale' )
						: sprintf(
							/* translators: %s: comma-separated WPML languages, e.g. "bg (bg_BG)" */
							__( 'Missing in PerfLocale: %s. Add these languages under PerfLocale → Languages before the import.', 'perflocale' ),
							implode( ', ', $missing )
						)
				);
				?>
			</li>
		</ul>
		<?php
	}

	/**
	 * The export section's label as the screen shows it, translated.
	 *
	 * The registry holds the English label (it loads without WordPress); unknown sections show it.
	 *
	 * @param string $key   Section key.
	 * @param string $label The registry's label.
	 * @return string
	 */
	public static function export_section_label( string $key, string $label ): string {
		switch ( $key ) {
			case 'settings':
				return __( 'Settings', 'perflocale' );
			case 'roles':
				return __( 'Roles & Capabilities', 'perflocale' );
			case 'languages':
				return __( 'Languages', 'perflocale' );
			case 'translations':
				return __( 'Translation Links', 'perflocale' );
			case 'strings':
				return __( 'String Translations', 'perflocale' );
			case 'slugs':
				return __( 'Slug Translations', 'perflocale' );
			case 'hashes':
				return __( 'Content Hashes', 'perflocale' );
			case 'media':
				/* translators: export section: per-language alt text, captions, descriptions and featured-image overrides. */
				return __( 'Media', 'perflocale' );
			case 'menus':
				/* translators: export section: classic menus' languages and their pairing. */
				return __( 'Menus', 'perflocale' );
			case 'item_flags':
				/* translators: export section: per-post sync opt-out and SEO-exclude flags, the order language. */
				return __( 'Item flags', 'perflocale' );
			case 'addon_data':
				/* translators: export section: data add-ons store, for example Gravity Forms translations. */
				return __( 'Add-on data', 'perflocale' );
			default:
				return $label;
		}
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
		$sections                 = \PerfLocale\Admin\DataExporter::sections();
		$addon_groups             = \PerfLocale\Admin\DataExporter::addon_groups();

		// The import form names the importer's ceiling: a file larger than one request is sent in
		// pieces (admin-settings.js, ImportUpload), so PHP's limit only decides whether it is split.
		$single_request_bytes = \PerfLocale\Admin\AdminController::max_upload_bytes();
		$import_ceiling_bytes = \PerfLocale\Admin\AdminController::import_max_bytes();
		$import_ceiling       = \PerfLocale\Admin\AdminController::format_bytes( $import_ceiling_bytes );

		/* translators: %d: percentage of the file uploaded so far. */
		$upload_progress_msg = __( 'Uploading the file in pieces: %d%%', 'perflocale' );
		/* translators: %s: the reason the upload stopped. */
		$upload_failed_msg  = __( 'The upload stopped and nothing was imported: %s', 'perflocale' );
		$upload_network_msg = __( 'the connection to the server was lost.', 'perflocale' );
		$upload_piece_msg   = sprintf(
			/* translators: %s: the smallest piece the screen sends, e.g. "256 KB". "wp perflocale import" is a command and stays in English. */
			__( 'the server refused a piece of %s as too large. Ask your host to accept larger uploads, or import the file with WP-CLI: wp perflocale import <file>.', 'perflocale' ),
			\PerfLocale\Admin\AdminController::format_bytes( \PerfLocale\Admin\ImportUpload::MIN_PIECE )
		);

		// Warn when a full export exceeds the import ceiling; two queries per table, on this tab only.
		$export_estimate = ( new \PerfLocale\Admin\DataExporter() )->estimate_bytes();

		?>

		<table class="form-table" role="presentation">

		<!-- Export -->
		<tr>
			<th scope="row"><?php echo esc_html__( 'Export', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-top:0;"><?php echo esc_html__( 'Select which data to include in the export.', 'perflocale' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>" style="margin-top:8px;">
					<?php wp_nonce_field( 'perflocale_export_data' ); ?>
					<input type="hidden" name="perflocale_action" value="export">
					<input type="hidden" name="export_addon_groups_shown" value="1">
					<fieldset style="margin-bottom:12px;">
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" id="perflocale-export-all" checked>
							<strong><?php echo esc_html__( 'Select All', 'perflocale' ); ?></strong>
						</label>
						<div style="margin-left:24px;display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;">
							<?php foreach ( $sections as $key => $section ) : ?>
								<label>
									<input type="checkbox" name="export_sections[]" value="<?php echo esc_attr( $key ); ?>" class="perflocale-export-section" checked>
									<?php echo esc_html( self::export_section_label( (string) $key, (string) $section['label'] ) ); ?>
								</label>
							<?php endforeach; ?>
							<?php foreach ( $addon_groups as $group_key => $group_label ) : ?>
								<label>
									<input type="checkbox" name="export_addon_groups[]" value="<?php echo esc_attr( $group_key ); ?>" class="perflocale-export-section" checked>
									<?php echo esc_html( $group_label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description" style="margin:10px 0 0 24px;">
							<?php echo esc_html__( 'Keep "Languages" selected whenever the export includes Translation Links, String Translations or Slug Translations. Those rows name their language by its numeric ID, and the same ID belongs to a different language on a different site - imported without the Languages section, a German translation can silently become a French one.', 'perflocale' ); ?>
						</p>
					</fieldset>
					<?php if ( $import_ceiling_bytes > 0 && $export_estimate > $import_ceiling_bytes ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<?php
								printf(
									/* translators: 1: estimated size of a full export, e.g. "80 MB", 2: largest file this site accepts for an import through this screen, e.g. "64 MB". */
									esc_html__( 'A full export of this site will be about %1$s, more than this site accepts for an import through this screen (%2$s). To restore it, import it with WP-CLI (wp perflocale import), raise the import limit, or export the sections in smaller files.', 'perflocale' ),
									esc_html( \PerfLocale\Admin\AdminController::format_bytes( $export_estimate ) ),
									esc_html( $import_ceiling )
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					<?php submit_button( __( 'Export Data', 'perflocale' ), 'secondary', 'submit', false ); ?>
				</form>
			</td>
		</tr>

		<!-- Import -->
		<tr>
			<th scope="row"><?php echo esc_html__( 'Import', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin-top:0;"><?php echo esc_html__( 'Restore data from a PerfLocale JSON export file. Large imports run in the background - track progress under PerfLocale → Jobs.', 'perflocale' ); ?></p>

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=export-import' ) ); ?>" style="margin-top:8px;"
					data-perflocale-import-form
					data-single-limit="<?php echo esc_attr( (string) $single_request_bytes ); ?>"
					data-import-limit="<?php echo esc_attr( (string) $import_ceiling_bytes ); ?>"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-msg-too-large="<?php echo esc_attr( \PerfLocale\Admin\ImportUpload::too_large_message() ); ?>"
					data-msg-progress="<?php echo esc_attr( $upload_progress_msg ); ?>"
					data-msg-failed="<?php echo esc_attr( $upload_failed_msg ); ?>"
					data-msg-network="<?php echo esc_attr( $upload_network_msg ); ?>"
					data-min-piece="<?php echo esc_attr( (string) \PerfLocale\Admin\ImportUpload::MIN_PIECE ); ?>"
					data-msg-piece-refused="<?php echo esc_attr( $upload_piece_msg ); ?>">
					<?php wp_nonce_field( 'perflocale_import_data' ); ?>
					<input type="hidden" name="perflocale_action" value="import">
					<input type="hidden" name="perflocale_import_upload" value="">

					<div data-perflocale-dropzone class="perflocale-dropzone" style="border:2px dashed #c3c4c7;border-radius:4px;padding:24px;text-align:center;margin-bottom:12px;">
						<span class="dashicons dashicons-upload" style="font-size:32px;width:32px;height:32px;color:#8c8f94;"></span>
						<p style="margin:8px 0 0;color:#646970;">
							<?php echo esc_html__( 'Drag and drop your JSON export here, or choose a file below.', 'perflocale' ); ?>
						</p>
						<p data-perflocale-dropzone-name style="margin:8px 0 0;font-weight:600;color:#1e1e1e;"></p>
						<p style="margin:12px 0 0;">
							<label for="perflocale-import-file" class="screen-reader-text"><?php echo esc_html__( 'File to import', 'perflocale' ); ?></label>
							<input type="file" name="perflocale_import_file" id="perflocale-import-file" accept=".json,application/json" required>
						</p>
						<p style="margin:8px 0 0;font-size:11px;color:#646970;">
							<?php
							printf(
								/* translators: %s: largest file this site accepts for upload, e.g. "2 MB". */
								esc_html__( 'Maximum file size: %s.', 'perflocale' ),
								esc_html( $import_ceiling )
							);

							if ( $single_request_bytes > 0 && $single_request_bytes < $import_ceiling_bytes ) {
								echo ' ';
								printf(
									/* translators: %s: largest file the server accepts in one request, e.g. "8 MB". */
									esc_html__( 'A file larger than %s is sent in pieces.', 'perflocale' ),
									esc_html( \PerfLocale\Admin\AdminController::format_bytes( $single_request_bytes ) )
								);
							}
							?>
						</p>
					</div>
					<p class="description" data-perflocale-import-status role="status" hidden></p>

					<fieldset style="margin-bottom:12px;">
						<legend class="screen-reader-text"><?php echo esc_html__( 'Import Mode', 'perflocale' ); ?></legend>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="perflocale_import_mode" value="merge" checked>
							<strong><?php echo esc_html__( 'Merge', 'perflocale' ); ?></strong> - <?php echo esc_html__( 'add imported rows alongside existing data; this site\'s settings, add-on settings, add-on list and roles stay as they are', 'perflocale' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="perflocale_import_mode" value="replace">
							<strong><?php echo esc_html__( 'Replace', 'perflocale' ); ?></strong> - <?php echo esc_html__( 'clear this site\'s rows for the sections in the file, then load the file', 'perflocale' ); ?>
						</label>
					</fieldset>

					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'Replace clears this site\'s PerfLocale table rows for each section the file carries before loading it, so rows the file does not hold are gone; other data (flags on posts, terms and orders, menu and media languages, redirects, add-on data) is only overwritten where the file has a value. It also applies the file\'s settings, add-on settings, add-on list and roles; this site keeps its own domains, provider addresses, uninstall choice and paused queue. Merge only adds rows and never changes this site\'s configuration.', 'perflocale' ); ?>
						<a href="https://perflocale.com/docs/export-import/#merge-vs-replace" target="_blank" rel="noopener"><?php echo esc_html__( 'Merge vs Replace - what Replace clears', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
					</p>
					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'Tip: re-importing the same backup in Merge mode creates fresh translation_groups rows (the table has no natural key) - the matching links de-duplicate via their object_lang (type, object_id, language_id) UNIQUE key, so the duplicate groups are linked to nothing and are swept at the end of every Merge import. For deterministic re-imports use Replace mode.', 'perflocale' ); ?>
					</p>

					<fieldset class="perflocale-import-addon" data-perflocale-import-addon>
						<legend><?php echo esc_html__( 'Add-on data to import', 'perflocale' ); ?></legend>
						<input type="hidden" name="perflocale_import_addon_shown" value="1">
						<label class="perflocale-import-addon__choice" data-perflocale-addon-choice="addon_data">
							<input type="checkbox" name="perflocale_import_addon[]" value="addon_data" checked>
							<?php echo esc_html( self::export_section_label( 'addon_data', 'Add-on data' ) ); ?>
						</label>
						<?php foreach ( $addon_groups as $group_key => $group_label ) : ?>
							<label class="perflocale-import-addon__choice" data-perflocale-addon-choice="<?php echo esc_attr( $group_key ); ?>">
								<input type="checkbox" name="perflocale_import_addon[]" value="<?php echo esc_attr( $group_key ); ?>" checked>
								<?php echo esc_html( $group_label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<fieldset style="margin:12px 0;">
						<legend class="screen-reader-text"><?php echo esc_html__( 'Cross-site import', 'perflocale' ); ?></legend>
						<label style="display:block;">
							<input type="checkbox" name="perflocale_import_same_ids" value="1">
							<?php
							/* translators: the import refusal messages in DataImporter quote this label back to the operator, minus the parenthetical. Translate the two together or the message will name a control that is not on screen. */
							echo esc_html__( 'This site is a copy of the site the file was exported from (post and term IDs match)', 'perflocale' );
							?>
						</label>
						<p class="description" style="margin:4px 0 0 24px;">
							<?php echo esc_html__( 'Translation links, translated slugs and content hashes name posts and terms by numeric ID. A file whose recorded site address is not this one is refused unless you tick this, because the same IDs belong to different content on a site that was set up separately. Tick it for a staging clone, a domain migration, or a backup of this site that records a language-specific address - not for a site that was rebuilt from a content export, which keeps the address and changes the IDs.', 'perflocale' ); ?>
						</p>
					</fieldset>

					<?php submit_button( __( 'Import Data', 'perflocale' ), 'secondary', 'submit', false ); ?>
				</form>

			</td>
		</tr>

		<?php
		// Each `covers` line names only what that importer imports (src/Migration/); none of them
		// touches nav menus.
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
								<?php $this->render_wpml_language_check( $cache ); ?>
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
								<?php $pll_policy_line = \PerfLocale\Migration\PolylangImporter::policy_line( \PerfLocale\Migration\PolylangImporter::conflict_policies() ); ?>
								<?php if ( $pll_policy_line !== '' ) : ?>
									<p class="perflocale-migration-card__policy"><?php echo esc_html( $pll_policy_line ); ?></p>
								<?php endif; ?>
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
	 * Render an API key field that respects external overrides (env var or PHP constant).
	 *
	 * An override disables the input and names its source; a stored key is never printed, and an
	 * empty submission keeps it ({@see self::MT_SECRET_KEYS}).
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
			$is_url      = $type === 'url';
			$input_type  = $is_url ? 'url' : 'password';
			$escaped     = $is_url ? esc_url( $value ) : '';
			$placeholder = ! $is_url && $value !== '' ? str_repeat( '*', 20 ) : '';
			?>
			<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $escaped ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" class="regular-text" autocomplete="off">
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
