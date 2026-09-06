<?php
/**
 * Addons management page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Addons management page with categories and status detection.
 */
final class AddonsPage {

	/**
	 * Get the full addon registry with compatibility checks.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_addon_registry(): array {
		$addons = [
			'machine-translation' => [
				'name'         => 'Machine Translation',
				'description'  => __( 'Translate content using DeepL, Google Translate, Microsoft Translator, or LibreTranslate. Includes auto-translate on publish/create, bulk translation and monthly usage limits. Disabled by default.', 'perflocale' ),
				'category'     => 'feature',
				'icon'         => 'dashicons-translation',
				'requires'     => __( 'Enable and configure in Settings > Addons > Machine Translation', 'perflocale' ),
				'settings_tab' => 'machine-translation',
				'check'        => fn() => (bool) \PerfLocale\Plugin::get_instance()->get( 'settings' )->mt_enabled(),
			],
			'blocksy'             => [
				'name'        => 'Blocksy Theme',
				'description' => __( 'Adds a Language Switcher element to Blocksy\'s header and footer builder.', 'perflocale' ),
				'category'    => 'theme',
				'icon'        => 'dashicons-layout',
				'requires'    => __( 'Blocksy theme', 'perflocale' ),
				'theme_slug'  => 'blocksy',
				'check'       => fn() => 'blocksy' === get_template(),
			],
			'kadence'             => [
				'name'        => 'Kadence Theme',
				'description' => __( 'Injects the Language Switcher into Kadence\'s header and primary navigation.', 'perflocale' ),
				'category'    => 'theme',
				'icon'        => 'dashicons-layout',
				'requires'    => __( 'Kadence theme', 'perflocale' ),
				'theme_slug'  => 'kadence',
				'check'       => fn() => 'kadence' === get_template(),
			],
			'neve'                => [
				'name'        => 'Neve Theme',
				'description' => __( 'Adds a Language Switcher element to Neve\'s header builder. Drag it into any header row via the Customizer.', 'perflocale' ),
				'category'    => 'theme',
				'icon'        => 'dashicons-layout',
				'requires'    => __( 'Neve theme', 'perflocale' ),
				'theme_slug'  => 'neve',
				'check'       => fn() => 'neve' === get_template(),
			],
			'yoast'               => [
				'name'        => 'Yoast SEO',
				'description' => __( 'Translate Yoast SEO titles, meta descriptions, and Open Graph tags.', 'perflocale' ),
				'category'    => 'seo',
				'icon'        => 'dashicons-chart-area',
				'requires'    => __( 'Yoast SEO plugin', 'perflocale' ),
				'plugin_file' => 'wordpress-seo/wp-seo.php',
				'check'       => fn() => defined( 'WPSEO_VERSION' ),
			],
			'rankmath'            => [
				'name'        => 'Rank Math',
				'description' => __( 'Translate Rank Math titles, meta descriptions, and focus keywords.', 'perflocale' ),
				'category'    => 'seo',
				'icon'        => 'dashicons-chart-line',
				'requires'    => __( 'Rank Math plugin', 'perflocale' ),
				'plugin_file' => 'seo-by-rank-math/rank-math.php',
				'check'       => fn() => class_exists( 'RankMath' ),
			],
			'seopress'            => [
				'name'        => 'SEOPress',
				'description' => __( 'Translate SEOPress titles, meta descriptions, and social tags.', 'perflocale' ),
				'category'    => 'seo',
				'icon'        => 'dashicons-chart-area',
				'requires'    => __( 'SEOPress plugin', 'perflocale' ),
				'plugin_file' => 'wp-seopress/seopress.php',
				'check'       => fn() => function_exists( 'seopress_get_service' ) || class_exists( 'SEOPRESS_Functions' ),
			],
			'aioseo'              => [
				'name'        => 'All in One SEO',
				'description' => __( 'Translate AIOSEO titles, descriptions, OG tags, and sitemap integration.', 'perflocale' ),
				'category'    => 'seo',
				'icon'        => 'dashicons-chart-area',
				'requires'    => __( 'All in One SEO plugin', 'perflocale' ),
				'plugin_file' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'check'       => fn() => defined( 'AIOSEO_FILE' ),
			],
			'theseoframework'     => [
				'name'        => 'The SEO Framework',
				'description' => __( 'Translate SEO titles, descriptions, and schema language properties.', 'perflocale' ),
				'category'    => 'seo',
				'icon'        => 'dashicons-chart-area',
				'requires'    => __( 'The SEO Framework plugin', 'perflocale' ),
				'plugin_file' => 'autodescription/autodescription.php',
				'check'       => fn() => function_exists( 'the_seo_framework' ) || function_exists( 'tsf' ),
			],
			'slimseo'             => [
				'name'        => 'Slim SEO',
				'description' => __( 'Set correct OG locale and schema language for Slim SEO.', 'perflocale' ),
				'category'    => 'seo',
				'icon'        => 'dashicons-chart-area',
				'requires'    => __( 'Slim SEO plugin', 'perflocale' ),
				'plugin_file' => 'slim-seo/slim-seo.php',
				'check'       => fn() => class_exists( 'SlimSEO\\Plugin' ),
			],
			'woocommerce'         => [
				'name'         => 'WooCommerce',
				'description'  => __( 'Translate products, categories, attributes. Multi-currency and email translation.', 'perflocale' ),
				'category'     => 'ecommerce',
				'icon'         => 'dashicons-cart',
				'requires'     => __( 'WooCommerce plugin', 'perflocale' ),
				'settings_tab' => 'woocommerce',
				'plugin_file'  => 'woocommerce/woocommerce.php',
				'check'        => fn() => class_exists( 'WooCommerce' ),
			],
			'elementor'           => [
				'name'        => 'Elementor',
				'description' => __( 'Language switcher Elementor widget and translatable widget content.', 'perflocale' ),
				'category'    => 'builder',
				'icon'        => 'dashicons-editor-expand',
				'requires'    => __( 'Elementor plugin', 'perflocale' ),
				'plugin_file' => 'elementor/elementor.php',
				'check'       => fn() => defined( 'ELEMENTOR_VERSION' ),
			],
			'beaver-builder'      => [
				'name'        => 'Beaver Builder',
				'description' => __( 'Language switcher Beaver Builder module.', 'perflocale' ),
				'category'    => 'builder',
				'icon'        => 'dashicons-admin-multisite',
				'requires'    => __( 'Beaver Builder plugin', 'perflocale' ),
				'plugin_file' => 'bb-plugin/fl-builder.php',
				'check'       => fn() => class_exists( 'FLBuilder' ),
			],
			'oxygen'              => [
				'name'        => 'Oxygen Builder',
				'description' => __( 'Translate Oxygen Classic builder content, shortcodes, and page settings. Registers ct_template post type as translatable.', 'perflocale' ),
				'category'    => 'builder',
				'icon'        => 'dashicons-editor-code',
				'requires'    => __( 'Oxygen Builder plugin', 'perflocale' ),
				'plugin_file' => 'oxygen/ct-oxygen-plugin.php',
				'check'       => fn() => defined( 'CT_VERSION' ),
			],
			'oxygen6'             => [
				'name'        => 'Oxygen 6.0',
				'description' => __( 'Translate Oxygen 6.0 (Breakdance in Oxygen mode) content tree and template settings with dynamic meta prefix support.', 'perflocale' ),
				'category'    => 'builder',
				'icon'        => 'dashicons-editor-code',
				'requires'    => __( 'Oxygen 6.0 plugin', 'perflocale' ),
				'plugin_file' => 'oxygen-6.0/plugin.php',
				'check'       => fn() => defined( '__BREAKDANCE_VERSION' ),
			],
			'bricks'              => [
				'name'        => 'Bricks Builder',
				'description' => __( 'Translate Bricks Builder page content and settings. Includes a Language Switcher element.', 'perflocale' ),
				'category'    => 'builder',
				'icon'        => 'dashicons-screenoptions',
				'requires'    => __( 'Bricks Builder theme', 'perflocale' ),
				'theme_slug'  => 'bricks',
				'check'       => fn() => defined( 'BRICKS_VERSION' ),
			],
			'acf'                 => [
				'name'        => 'Advanced Custom Fields',
				'description' => __( 'Translate ACF text, textarea, WYSIWYG, URL, and email fields.', 'perflocale' ),
				'category'    => 'fields',
				'icon'        => 'dashicons-admin-settings',
				'requires'    => __( 'ACF or ACF Pro', 'perflocale' ),
				'plugin_file' => 'advanced-custom-fields/acf.php',
				'check'       => fn() => class_exists( 'ACF' ),
			],
			'metabox'             => [
				'name'        => 'MetaBox',
				'description' => __( 'Translate MetaBox text, textarea, WYSIWYG, URL, email fields. Supports groups, cloneables, and reference field translation.', 'perflocale' ),
				'category'    => 'fields',
				'icon'        => 'dashicons-admin-settings',
				'requires'    => __( 'MetaBox or MetaBox AIO plugin', 'perflocale' ),
				'plugin_file' => 'meta-box-aio/meta-box-aio.php',
				'check'       => fn() => defined( 'RWMB_VER' ) || class_exists( 'RWMB_Loader' ),
			],
			'pods'                => [
				'name'        => 'Pods',
				'description' => __( 'Translate Pods text, paragraph, WYSIWYG, email, phone, URL fields. Translates pick field relationships on the frontend.', 'perflocale' ),
				'category'    => 'fields',
				'icon'        => 'dashicons-admin-settings',
				'requires'    => __( 'Pods plugin', 'perflocale' ),
				'plugin_file' => 'pods/init.php',
				'check'       => fn() => defined( 'PODS_VERSION' ),
			],
			'gravity-forms'       => [
				'name'        => 'Gravity Forms',
				'description' => __( 'Translate form labels, descriptions, choices, and confirmations.', 'perflocale' ),
				'category'    => 'forms',
				'icon'        => 'dashicons-feedback',
				'requires'    => __( 'Gravity Forms plugin', 'perflocale' ),
				'plugin_file' => 'gravityforms/gravityforms.php',
				'check'       => fn() => class_exists( 'GFAPI' ),
			],
			'contact-form-7'      => [
				'name'        => 'Contact Form 7',
				'description' => __( 'Serve translated form content and email templates per language.', 'perflocale' ),
				'category'    => 'forms',
				'icon'        => 'dashicons-email',
				'requires'    => __( 'Contact Form 7 plugin', 'perflocale' ),
				'plugin_file' => 'contact-form-7/wp-contact-form-7.php',
				'check'       => fn() => defined( 'WPCF7_VERSION' ),
			],
			'wpforms'             => [
				'name'        => 'WPForms',
				'description' => __( 'Translate form fields, descriptions, placeholders, and submit buttons.', 'perflocale' ),
				'category'    => 'forms',
				'icon'        => 'dashicons-forms',
				'requires'    => __( 'WPForms plugin', 'perflocale' ),
				'plugin_file' => 'wpforms-lite/wpforms.php',
				'check'       => fn() => function_exists( 'wpforms' ),
			],
		];

		/**
		 * Filter the addon registry so external plugins can add their card.
		 *
		 * Each entry must have: name, description, category, icon, requires, check (callable returning bool).
		 * Optional: settings_tab (slug for Settings > Addons subtab), plugin_file, theme_slug.
		 *
		 * @param array<string, array<string, mixed>> $addons Addon definitions.
		 */
		return apply_filters( 'perflocale/addons/registry', $addons );
	}

	/**
	 * Category labels.
	 *
	 * @return array<string, string>
	 */
	private function get_categories(): array {
		return [
			'all'       => __( 'All', 'perflocale' ),
			'feature'   => __( 'Features', 'perflocale' ),
			'theme'     => __( 'Themes', 'perflocale' ),
			'seo'       => __( 'SEO', 'perflocale' ),
			'ecommerce' => __( 'E-Commerce', 'perflocale' ),
			'builder'   => __( 'Page Builders', 'perflocale' ),
			'fields'    => __( 'Custom Fields', 'perflocale' ),
			'forms'     => __( 'Forms', 'perflocale' ),
		];
	}

	/**
	 * Render the addons page.
	 *
	 * @return void
	 */
	public function render(): void {
		$addons     = $this->get_addon_registry();
		$categories = $this->get_categories();

		// Auto-show addons registered via perflocale/addons/register that
		// didn't add a card via the perflocale/addons/registry filter.
		// Reads optional get_card_info() for category, icon, description, etc.
		$plugin   = \PerfLocale\Plugin::get_instance();
		$registry = $plugin->has( 'addon_registry' ) ? $plugin->get( 'addon_registry' ) : null;

		// Index of registered addon instances so the per-card section can
		// pull get_settings_fields() / version requirement from the same
		// source. Keyed by addon ID.
		$addon_instances = [];

		if ( $registry !== null ) {
			$addon_instances = $registry->get_addons();

			foreach ( $addon_instances as $id => $addon ) {
				if ( isset( $addons[ $id ] ) ) {
					continue;
				}

				$card = $addon instanceof \PerfLocale\Addon\HasCardInfo
					? (array) $addon->get_card_info()
					: [];

				$addons[ $id ] = [
					'name'         => $card['name'] ?? $addon->get_name(),
					'description'  => $card['description'] ?? '',
					'category'     => $card['category'] ?? 'feature',
					'icon'         => $card['icon'] ?? 'dashicons-admin-plugins',
					'requires'     => $card['requires'] ?? $addon->get_name() . ' v' . $addon->get_version(),
					'settings_tab' => sanitize_key( $card['settings_tab'] ?? '' ),
					'check'        => fn() => $registry->is_booted( $id ),
				];
			}
		}

		$version_mismatches = $registry !== null ? $registry->get_version_mismatches() : [];
		$disabled_ids       = \PerfLocale\Addon\AddonRegistry::get_disabled();
		$quarantined_ids    = $registry !== null ? $registry->get_quarantined_ids() : [];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$active_cat    = isset( $_GET['category'] ) ? sanitize_key( $_GET['category'] ) : 'all';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $categories[ $active_cat ] ) ) {
			$active_cat = 'all';
		}

		if ( ! in_array( $status_filter, [ 'all', 'active', 'inactive', 'installed', 'not_installed' ], true ) ) {
			$status_filter = 'all';
		}

		// IDs that the Addons page treats as built-in features (cards
		// exist but they aren't registry-registered addons — their
		// on/off state is the underlying Settings flag, not the
		// disabled-addons option).
		$builtin_feature_ids = [ 'machine-translation' ];

		// Precompute status for each addon. Status is one of:
		// 'active'        — addon is doing work right now
		// 'disabled'      — operator turned it off (registry or feature flag)
		// 'installed'     — host plugin/theme detected but addon not booted
		// (typically blocked by addon's own compat check)
		// 'not_installed' — host plugin/theme absent on disk
		//
		// Order of checks matters: 'disabled' wins over 'active' so a
		// disabled WC addon doesn't show up under the Active tab just
		// because WooCommerce is loaded.
		$addon_statuses  = [];
		$active_count    = 0;
		$disabled_count  = 0;
		$installed_count = 0;

		foreach ( $addons as $addon_id => $addon ) {
			// Check disabled FIRST, before active. A disabled addon
			// shouldn't read as "active" just because its host plugin
			// is loaded — the user's intent (clicked Disable) overrides
			// the auto-detection.
			$is_disabled = in_array( $addon_id, $disabled_ids, true );

			// Built-in features have their own toggle (the Settings flag
			// the toggle handler writes to). When the flag is off, the
			// check() callback already returns false. So we don't need
			// a second source of truth — but we DO want the card to read
			// "Disabled" instead of "Not installed" for these because
			// they're always installed (they're part of the plugin).
			$is_builtin = in_array( $addon_id, $builtin_feature_ids, true );

			if ( $is_disabled ) {
				$addon_statuses[ $addon_id ] = 'disabled';
				++$disabled_count;
				continue;
			}

			$check     = $addon['check'] ?? null;
			$is_active = is_callable( $check ) ? (bool) $check() : false;

			if ( $is_active ) {
				$addon_statuses[ $addon_id ] = 'active';
				++$active_count;
				continue;
			}

			// Built-in features whose flag is off → 'disabled'
			// (semantically: turned off by the operator), NOT
			// 'not_installed' which would imply the plugin is missing.
			if ( $is_builtin ) {
				$addon_statuses[ $addon_id ] = 'disabled';
				++$disabled_count;
				continue;
			}

			$is_installed = false;

			if ( ! empty( $addon['theme_slug'] ) ) {
				$is_installed = is_dir( get_theme_root() . '/' . $addon['theme_slug'] );
			} elseif ( ! empty( $addon['theme_slugs'] ) ) {
				foreach ( $addon['theme_slugs'] as $slug ) {
					if ( is_dir( get_theme_root() . '/' . $slug ) ) {
						$is_installed = true;
						break;
					}
				}
			} elseif ( ! empty( $addon['plugin_file'] ) ) {
				$is_installed = file_exists( trailingslashit( WP_PLUGIN_DIR ) . $addon['plugin_file'] );
			}

			if ( $is_installed ) {
				$addon_statuses[ $addon_id ] = 'installed';
				++$installed_count;
			} else {
				$addon_statuses[ $addon_id ] = 'not_installed';
			}
		}

		$not_installed_count = count( $addons ) - $active_count - $disabled_count - $installed_count;

		?>
		<div class="wrap perflocale-dashboard">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Addons', 'perflocale' ); ?></h1>
			<hr class="wp-header-end">

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$flash_msg   = isset( $_GET['perflocale_msg'] ) ? sanitize_key( $_GET['perflocale_msg'] ) : '';
			$flash_addon = isset( $_GET['perflocale_addon'] ) ? sanitize_key( $_GET['perflocale_addon'] ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$flash_text = '';
			$flash_kind = 'updated';

			if ( $flash_msg === 'addon_saved' && $flash_addon !== '' ) {
				$flash_name = $addons[ $flash_addon ]['name'] ?? $flash_addon;
				/* translators: %s: addon display name */
				$flash_text = sprintf( __( 'Settings for %s saved.', 'perflocale' ), $flash_name );
			} elseif ( $flash_msg === 'addon_save_failed' && $flash_addon !== '' ) {
				$flash_name = $addons[ $flash_addon ]['name'] ?? $flash_addon;
				/* translators: %s: addon display name */
				$flash_text = sprintf( __( 'Could not save settings for %s. The values may exceed the per-addon size limit, or another save was in progress. Check the PHP error log under WP_DEBUG for the exact reason.', 'perflocale' ), $flash_name );
				$flash_kind = 'notice-error';
			} elseif ( $flash_msg === 'addon_disabled' && $flash_addon !== '' ) {
				$flash_name = $addons[ $flash_addon ]['name'] ?? $flash_addon;
				/* translators: %s: addon display name */
				$flash_text = sprintf( __( '%s disabled. Refresh the page to confirm the addon no longer boots.', 'perflocale' ), $flash_name );
				$flash_kind = 'notice-warning';
			} elseif ( $flash_msg === 'addon_enabled' && $flash_addon !== '' ) {
				$flash_name = $addons[ $flash_addon ]['name'] ?? $flash_addon;
				/* translators: %s: addon display name */
				$flash_text = sprintf( __( '%s re-enabled.', 'perflocale' ), $flash_name );
			} elseif ( $flash_msg === 'addon_toggle_failed' && $flash_addon !== '' ) {
				$flash_name = $addons[ $flash_addon ]['name'] ?? $flash_addon;
				/* translators: %s: addon display name */
				$flash_text = sprintf( __( 'Could not toggle %s. The disabled-addons list may be at its size cap, or the addon id is malformed. Check the PHP error log under WP_DEBUG.', 'perflocale' ), $flash_name );
				$flash_kind = 'notice-error';
			}

			if ( $flash_text !== '' ) :
				?>
				<div class="notice <?php echo esc_attr( $flash_kind ); ?> is-dismissible" style="margin-top:20px;">
					<p><?php echo esc_html( $flash_text ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $version_mismatches ) ) : ?>
				<div class="notice notice-error" style="margin-top:20px;">
					<p>
						<strong><?php echo esc_html__( 'Some addons require a newer version of PerfLocale and were not booted:', 'perflocale' ); ?></strong>
					</p>
					<ul style="list-style:disc;margin-left:20px;">
						<?php
						foreach ( $version_mismatches as $vm_id => $vm_required ) :
							$vm_label = $addons[ $vm_id ]['name'] ?? $vm_id;
							?>
							<li>
								<?php
								printf(
									/* translators: 1: addon name, 2: required PerfLocale version, 3: running PerfLocale version */
									esc_html__( '%1$s requires PerfLocale %2$s (running %3$s).', 'perflocale' ),
									'<strong>' . esc_html( $vm_label ) . '</strong>',
									esc_html( $vm_required ),
									esc_html( (string) ( defined( 'PERFLOCALE_VERSION' ) ? PERFLOCALE_VERSION : '?' ) )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $quarantined_ids ) ) : ?>
				<div class="notice notice-warning" style="margin-top:20px;">
					<p>
						<strong><?php echo esc_html__( 'Quarantined addons', 'perflocale' ); ?></strong>
						&mdash;
						<?php echo esc_html__( 'these addons threw a fatal exception during boot 3 times in a row, so PerfLocale has stopped trying to load them. The rest of the plugin keeps working normally. After fixing the underlying issue (check the PHP error log under WP_DEBUG), clear the failure counter with:', 'perflocale' ); ?>
					</p>
					<ul style="list-style:disc;margin-left:20px;">
						<?php
						foreach ( $quarantined_ids as $qid ) :
							$qlabel = $addons[ $qid ]['name'] ?? $qid;
							?>
							<li>
								<strong><?php echo esc_html( $qlabel ); ?></strong>
								&mdash;
								<code>wp perflocale addon reset-quarantine <?php echo esc_html( $qid ); ?></code>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<!-- Summary -->
			<div class="perflocale-dash-stats" style="grid-template-columns: repeat(3, 1fr); margin-top: 20px;">
				<div class="perflocale-dash-stat">
					<span class="perflocale-dash-stat__number"><?php echo esc_html( (string) count( $addons ) ); ?></span>
					<span class="perflocale-dash-stat__label"><?php echo esc_html__( 'Available', 'perflocale' ); ?></span>
				</div>
				<div class="perflocale-dash-stat">
					<span class="perflocale-dash-stat__number" style="color: var(--perflocale-green-text);"><?php echo esc_html( (string) $active_count ); ?></span>
					<span class="perflocale-dash-stat__label"><?php echo esc_html__( 'Active', 'perflocale' ); ?></span>
				</div>
				<div class="perflocale-dash-stat">
					<span class="perflocale-dash-stat__number" style="color: var(--perflocale-gray-text);"><?php echo esc_html( (string) ( count( $addons ) - $active_count ) ); ?></span>
					<span class="perflocale-dash-stat__label"><?php echo esc_html__( 'Inactive', 'perflocale' ); ?></span>
				</div>
			</div>

			<p class="description" style="margin-bottom: 6px;">
				<?php echo esc_html__( 'Addons activate automatically when their required plugin or theme is detected. No manual installation needed.', 'perflocale' ); ?>
			</p>

			<!-- Category & Status Tabs -->
			<div class="perflocale-addons-tabs">
				<?php
				// Category tabs - clicking a category clears the active filter.
				foreach ( $categories as $cat_key => $cat_label ) :
					$url        = add_query_arg(
						[
							'page'     => 'perflocale-addons',
							'category' => $cat_key,
						],
						admin_url( 'admin.php' )
					);
					$is_current = ( $cat_key === $active_cat && ! in_array( $status_filter, [ 'active', 'inactive' ], true ) );

					$count = 0;

					if ( $cat_key === 'all' ) {
						$count = count( $addons );
					} else {
						foreach ( $addons as $addon ) {
							if ( $addon['category'] === $cat_key ) {
								++$count;
							}
						}
					}
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="perflocale-addons-tab<?php echo $is_current ? ' perflocale-addons-tab--active' : ''; ?>">
						<?php echo esc_html( $cat_label ); ?>
						<span class="perflocale-addons-tab__count"><?php echo esc_html( (string) $count ); ?></span>
					</a>
				<?php endforeach; ?>

				<?php
				// "Active" tab - shows only active addons across all categories.
				$active_url = add_query_arg(
					[
						'page'   => 'perflocale-addons',
						'status' => 'active',
					],
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $active_url ); ?>" class="perflocale-addons-tab<?php echo $status_filter === 'active' ? ' perflocale-addons-tab--active' : ''; ?>">
					<?php echo esc_html__( 'Active', 'perflocale' ); ?>
					<span class="perflocale-addons-tab__count"><?php echo esc_html( (string) $active_count ); ?></span>
				</a>

				<?php
				// "Inactive" tab — everything that's not currently
				// booted, regardless of why (operator-disabled, host
				// plugin not installed, or host detected but addon
				// didn't pass compat). The per-card badge still shows
				// the specific reason so operators can see what's going
				// on at a glance.
				$inactive_count = count( $addons ) - $active_count;
				$inactive_url   = add_query_arg(
					[
						'page'   => 'perflocale-addons',
						'status' => 'inactive',
					],
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $inactive_url ); ?>" class="perflocale-addons-tab<?php echo $status_filter === 'inactive' ? ' perflocale-addons-tab--active' : ''; ?>">
					<?php echo esc_html__( 'Inactive', 'perflocale' ); ?>
					<span class="perflocale-addons-tab__count"><?php echo esc_html( (string) $inactive_count ); ?></span>
				</a>
			</div>

			<!-- Addon Cards -->
			<div class="perflocale-addons-grid">
				<?php
				foreach ( $addons as $addon_id => $addon ) :
					$addon_status = $addon_statuses[ $addon_id ] ?? 'not_installed';

					// Status filter: Active matches only running addons;
					// Inactive matches everything that's NOT active
					// regardless of why (disabled by operator, host
					// plugin not installed, or addon failed its own
					// compat check). The per-card badge below still
					// shows the specific reason. Anything else falls
					// through to the per-category filter.
					if ( $status_filter === 'active' ) {
						if ( $addon_status !== 'active' ) {
							continue;
						}
					} elseif ( $status_filter === 'inactive' ) {
						if ( $addon_status === 'active' ) {
							continue;
						}
					} else {
						// Category filter.
						if ( $active_cat !== 'all' && $addon['category'] !== $active_cat ) {
							continue;
						}
					}

					// Card-render flags derived from the precomputed status.
					// $addon_status was already resolved in the precompute
					// loop above with 'disabled' winning over 'active', so
					// a disabled addon's WC plugin being loaded doesn't
					// make this card read as "Active" any more.
					$is_active    = ( $addon_status === 'active' );
					$is_disabled  = ( $addon_status === 'disabled' );
					$is_installed = ( $addon_status === 'installed' );

					if ( $is_disabled ) {
						$status_class = 'perflocale-addon-card__status--inactive';
						$status_label = __( 'Disabled', 'perflocale' );
					} elseif ( $is_active ) {
						$status_class = 'perflocale-addon-card__status--active';
						$status_label = __( 'Active', 'perflocale' );
					} elseif ( $is_installed ) {
						$status_class = 'perflocale-addon-card__status--installed';
						$status_label = __( 'Not active', 'perflocale' );
					} else {
						$status_class = 'perflocale-addon-card__status--inactive';
						$status_label = __( 'Not installed', 'perflocale' );
					}

					// Look up the registered addon instance — gives us the
					// canonical settings fields + version requirement.
					$addon_instance  = $addon_instances[ $addon_id ] ?? null;
					$settings_fields = [];

					if ( $addon_instance !== null ) {
						try {
							$settings_fields = (array) $addon_instance->get_settings_fields();
						} catch ( \Throwable $e ) {
							$settings_fields = [];
						}
					}

					// Drop non-editable types (e.g. 'hidden') from the inline
					// form — they're addon-managed and the generic save path
					// preserves them automatically.
					$editable_fields = [];
					foreach ( $settings_fields as $field_key => $field_def ) {
						if ( is_string( $field_key ) && is_array( $field_def ) && \PerfLocale\Addon\AddonSettings::is_user_editable_field( $field_def ) ) {
							$editable_fields[ $field_key ] = $field_def;
						}
					}

					$current_values = ! empty( $editable_fields ) ? \PerfLocale\Addon\AddonSettings::get_addon( $addon_id ) : [];
					?>
					<?php
					// Surface the most recent boot / migration / uninstall
					// error inline so operators don't need to SSH + tail
					// error_log to find out why an addon is quarantined or
					// in an unexpected state. AddonMigrationErrors reads a
					// single autoload=no option; per-card loop is fine
					// since a typical install has fewer than ~30 cards.
					$last_error     = \PerfLocale\Addon\AddonMigrationErrors::last_for_addon( $addon_id );
					$is_quarantined = in_array( $addon_id, $quarantined_ids, true );
					?>
					<div class="perflocale-addon-card<?php echo $is_active ? ' perflocale-addon-card--active' : ''; ?>">
						<div class="perflocale-addon-card__header">
							<span class="dashicons <?php echo esc_attr( $addon['icon'] ); ?> perflocale-addon-card__icon"></span>
							<div class="perflocale-addon-card__title">
								<h3><?php echo esc_html( $addon['name'] ); ?></h3>
								<span class="perflocale-addon-card__cat"><?php echo esc_html( $categories[ $addon['category'] ] ?? '' ); ?></span>
							</div>
							<span class="perflocale-addon-card__status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
						</div>
						<div class="perflocale-addon-card__body">
							<p><?php echo esc_html( $addon['description'] ); ?></p>
							<?php
							if ( $last_error !== null ) :
								// Truncate stack-trace-like messages so the card
								// stays readable; full text is in
								// `wp perflocale addon errors <id>`.
								$msg     = (string) ( $last_error['message'] ?? '' );
								$display = mb_strlen( $msg ) > 200 ? mb_substr( $msg, 0, 200 ) . '…' : $msg;
								$stage   = (string) ( $last_error['stage'] ?? '' );
								$at      = (string) ( $last_error['recorded'] ?? '' );
								$icon    = $is_quarantined ? '⚠' : 'ℹ';
								?>
								<div class="perflocale-addon-card__error" style="margin-top:8px;padding:8px 10px;border:1px solid #d63638;background:#fcf0f1;border-radius:3px;font-size:12px;line-height:1.4;">
									<strong style="color:#d63638;">
										<?php
										printf(
											/* translators: 1: warning icon, 2: error stage (boot, migrate, etc.) */
											esc_html__( '%1$s Last error (%2$s):', 'perflocale' ),
											esc_html( $icon ),
											esc_html( $stage )
										);
										?>
									</strong>
									<code style="display:block;margin-top:4px;background:transparent;color:#1d2327;font-size:11px;word-break:break-word;">
										<?php echo esc_html( $display ); ?>
									</code>
									<?php if ( $at !== '' ) : ?>
										<span class="description" style="display:block;margin-top:2px;font-size:10px;color:#646970;">
											<?php
											printf(
												/* translators: 1: ISO 8601 datetime, 2: addon id */
												esc_html__( 'Recorded %1$s · full text: wp perflocale addon errors %2$s', 'perflocale' ),
												esc_html( $at ),
												esc_html( $addon_id )
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
						<?php
						// Built-in features (Machine Translation) have no registry
						// instance, but they DO have a toggle: AdminController's
						// BUILTIN_FEATURE_TOGGLES maps the card to its Settings flag.
						// Gating this form on $addon_instance alone meant the MT card
						// rendered "Enable here" with no Enable button on it.
						$perflocale_card_toggleable = ( $addon_instance !== null || in_array( $addon_id, $builtin_feature_ids, true ) );
						?>
						<?php if ( $perflocale_card_toggleable && current_user_can( 'perflocale_manage_addons' ) ) : ?>
							<div class="perflocale-addon-card__toggle" style="padding:6px 12px;border-top:1px solid #f0f0f1;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;margin:0;">
									<input type="hidden" name="action" value="perflocale_toggle_addon">
									<input type="hidden" name="addon_id" value="<?php echo esc_attr( $addon_id ); ?>">
									<input type="hidden" name="disable" value="<?php echo $is_disabled ? '0' : '1'; ?>">
									<?php wp_nonce_field( 'perflocale_toggle_addon_' . $addon_id ); ?>
									<button type="submit" class="button button-small">
										<?php echo $is_disabled ? esc_html__( 'Enable', 'perflocale' ) : esc_html__( 'Disable', 'perflocale' ); ?>
									</button>
									<?php if ( $is_disabled && $addon_instance !== null ) : ?>
										<span class="description" style="font-size:11px;color:var(--perflocale-gray-text);">
											<?php echo esc_html__( 'Skipped at boot until re-enabled.', 'perflocale' ); ?>
										</span>
									<?php endif; ?>
								</form>
							</div>
						<?php endif; ?>

						<div class="perflocale-addon-card__footer">
							<?php
							// Manage URL: first preference is an explicitly-declared
							// settings_tab (e.g. machine-translation, woocommerce
							// — the built-in tabs that ship with the plugin). Second preference is the auto-generated
							// per-addon subtab — any registered addon with user-
							// editable get_settings_fields() gets one for free
							// (see SettingsPage::get_addon_subtabs).
							$settings_tab = sanitize_key( $addon['settings_tab'] ?? '' );

							if ( $settings_tab === '' && ! empty( $editable_fields ) ) {
								$settings_tab = $addon_id;
							}

							$settings_url = $settings_tab !== '' ? admin_url( 'admin.php?page=perflocale-settings&tab=addons&subtab=' . $settings_tab ) : '';

							if ( $is_active && $settings_url !== '' ) :
								?>
								<a href="<?php echo esc_url( $settings_url ); ?>" class="perflocale-addon-card__link">
									<?php echo esc_html__( 'Manage', 'perflocale' ); ?> &rarr;
								</a>
								<span class="perflocale-addon-card__auto perflocale-btn-icon perflocale-btn-icon--sm" style="color: var(--perflocale-green-text);">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php echo esc_html__( 'Detected', 'perflocale' ); ?>
								</span>
							<?php elseif ( ! $is_active && $settings_url !== '' ) : ?>
								<a href="<?php echo esc_url( $settings_url ); ?>" class="perflocale-addon-card__link">
									<?php echo esc_html( $addon['requires'] ); ?>
								</a>
								<span class="perflocale-addon-card__auto" style="color: var(--perflocale-gray-text);">
									<?php
									// Reuse the status already resolved above. Hard-coding
									// "Not installed" made a built-in feature that is merely
									// switched off — Machine Translation on a default install —
									// read "Disabled" in its header and "Not installed" in its
									// footer at the same time.
									echo esc_html( $status_label );
									?>
								</span>
							<?php else : ?>
								<span class="perflocale-addon-card__requires">
									<?php echo esc_html( $addon['requires'] ); ?>
								</span>
								<?php if ( $is_active ) : ?>
									<span class="perflocale-addon-card__auto" style="color: var(--perflocale-green-text);">
										<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
										<?php echo esc_html__( 'Detected', 'perflocale' ); ?>
									</span>
								<?php elseif ( $is_installed ) : ?>
									<span class="perflocale-addon-card__auto perflocale-btn-icon perflocale-btn-icon--sm" style="color: var(--perflocale-amber-text, #b45309);">
										<span class="dashicons dashicons-marker"></span>
										<?php echo esc_html__( 'Installed', 'perflocale' ); ?>
									</span>
								<?php else : ?>
									<span class="perflocale-addon-card__auto" style="color: var(--perflocale-gray-text);">
										<?php echo esc_html__( 'Not installed', 'perflocale' ); ?>
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
