<?php
/**
 * Bootstrap's rare paths: the blog-switch reset, the dormant boot and WP-Cron inside a page view.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kept out of BootstrapMaintenance, so a request that switches blogs or a dormant site does not compile its cron bodies.
 *
 * @internal
 */
final class BootstrapRare {

	/**
	 * `pre_get_ready_cron_jobs` on an ALTERNATE_WP_CRON site: bind the job workers once WP-Cron runs in this page view
	 * (wp-cron.php reads its due events here, before it runs any); the page view's own due check binds nothing.
	 *
	 * @param mixed $pre The ready events another filter returned, or null.
	 * @return mixed The value, unchanged.
	 */
	public static function bind_jobs_in_page_cron( mixed $pre ): mixed {
		if ( wp_doing_cron() ) {
			remove_filter( 'pre_get_ready_cron_jobs', [ self::class, 'bind_jobs_in_page_cron' ] );
			Bootstrap::bind_jobs( true );
		}

		return $pre;
	}

	/**
	 * Drop the per-request memos that hold one blog's data: on switch_blog and from refresh_if_changed().
	 *
	 * Every blog-affine static or singleton memo must be reset here or be blog-keyed. LanguageRouter has its own
	 * same-blog-aware switch_blog listener: never reset it here.
	 *
	 * @return void
	 */
	public static function reset_static_state(): void {
		Translation\PostQueryFilter::reset_static_caches();
		Translation\TermQueryFilter::reset_static_caches();
		Translation\PostTranslationManager::reset_static_caches();
		Database\Repository\TranslationGroupRepository::reset_static_caches();
		Database\Repository\SlugTranslationRepository::reset_static_caches();
		Seo\SitemapIntegration::reset_caches();
		Seo\SchemaEnricher::reset();
		Translation\MediaTranslationManager::reset_registered();
		Addon\AddonSettings::reset_static_caches();
		Helper::reset_static_caches();

		try {
			$plugin = Plugin::get_instance();

			// Only one string service exists per mode, so whichever is registered resets.
			foreach ( [ 'string_translation', 'translation_file_loader' ] as $service ) {
				if ( ! $plugin->has( $service ) ) {
					continue;
				}

				$instance = $plugin->get( $service );

				if ( method_exists( $instance, 'reset_for_blog_switch' ) ) {
					$instance->reset_for_blog_switch();
				}
			}

			if ( $plugin->has( 'settings' ) ) {
				$plugin->get( 'settings' )->reset_cache();
			}
		} catch ( \Throwable $e ) {
			// Mid-deactivation or container missing: fail closed.
		}
	}

	/**
	 * Boot while a conflicting multilingual plugin is active: the admin notice, and the dormant record.
	 *
	 * No cache listener runs then, so hreflang may go stale. Only the first dormant boot writes
	 * Bootstrap::DORMANT_KEY, at plugins_loaded, when the option-update listeners can run.
	 *
	 * @param string $conflict Display name of the conflicting plugin.
	 * @return void
	 */
	public static function dormant_boot( string $conflict ): void {
		$notice = static function () use ( $conflict ): void {
			printf(
				'<div class="notice notice-error"><p><strong>PerfLocale</strong>: %1$s %2$s</p></div>',
				sprintf(
				/* translators: %s: conflicting plugin name */
					esc_html__( 'Cannot run while %s is active. Please deactivate one of the two multilingual plugins to avoid conflicts.', 'perflocale' ),
					esc_html( $conflict )
				),
				sprintf(
				/* translators: %s: conflicting plugin name */
					esc_html__( 'Your %s translations stay in the database: after deactivating it, import them from Settings &rarr; Export &amp; Import, or with `wp perflocale migrate`.', 'perflocale' ),
					esc_html( $conflict )
				)
			);
		};

		// Both hooks: core fires admin_notices or network_admin_notices, never both, and a
		// network-activated conflicting plugin is managed from Network Admin.
		add_action( 'admin_notices', $notice );
		add_action( 'network_admin_notices', $notice );

		$stored = get_option( 'perflocale_settings' );

		if ( ! is_array( $stored ) || isset( $stored[ Bootstrap::DORMANT_KEY ] ) ) {
			return;
		}

		add_action(
			'plugins_loaded',
			static function (): void {
				$stored = get_option( 'perflocale_settings' );

				if ( ! is_array( $stored ) || isset( $stored[ Bootstrap::DORMANT_KEY ] ) ) {
					return;
				}

				$stored[ Bootstrap::DORMANT_KEY ] = 1;

				update_option( 'perflocale_settings', $stored );
			},
			0
		);
	}
}
