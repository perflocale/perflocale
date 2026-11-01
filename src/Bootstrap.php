<?php
/**
 * Plugin bootstrap - conditional loading orchestrator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin initialization: registers only the services the request context needs.
 */
final class Bootstrap {

	/**
	 * Known conflicting multilingual plugins: version constant, plus active_plugins files for
	 * when PerfLocale loads before the competitor defines it.
	 *
	 * @var array<string, array{constant: string, files: string[]}>
	 */
	private const CONFLICTING_PLUGINS = [
		'WPML'           => [
			'constant' => 'ICL_SITEPRESS_VERSION',
			'files'    => [ 'sitepress-multilingual-cms/sitepress.php' ],
		],
		'Polylang'       => [
			'constant' => 'POLYLANG_VERSION',
			'files'    => [ 'polylang/polylang.php', 'polylang-pro/polylang.php' ],
		],
		'TranslatePress' => [
			'constant' => 'TRP_PLUGIN_VERSION',
			'files'    => [ 'translatepress-multilingual/index.php' ],
		],
	];

	/**
	 * Cron hook names copied so wiring them does not autoload WebhookController and Lock on
	 * every request. HookNameDriftTest keeps them equal to the owning classes' constants.
	 */
	private const WEBHOOK_DELIVERY_HOOK = 'perflocale_deliver_webhook';
	private const WEBHOOK_DRAIN_HOOK    = 'perflocale_drain_webhooks';
	private const WEBHOOK_RETRY_HOOK    = 'perflocale_retry_webhook';
	public const LOCK_CLEANUP_HOOK      = 'perflocale_lock_cleanup';

	/**
	 * Key in `perflocale_settings` set while a dormant boot (conflicting plugin active) has not
	 * been followed by a normal boot. Not a setting: Settings::DEFAULTS does not list it.
	 */
	private const DORMANT_KEY = 'was_dormant';

	/**
	 * One-time job state options, copied so a done job's class is not autoloaded on admin
	 * pages. BootstrapOneTimeJobGateTest keeps them equal to the jobs' constants.
	 */
	private const TEMPLATE_REFS_BACKFILL_OPTION = 'perflocale_template_refs_backfill';
	private const SQLITE_GROUP_REPAIR_OPTION    = 'perflocale_sqlite_string_groups_repair_job';

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Abort initialization if a conflicting multilingual plugin is active.
		$conflict = self::detect_conflicting_plugin();

		if ( $conflict !== null ) {
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

			self::note_dormant_boot();

			return;
		}

		// Core builds $wp_locale right after setup_theme (wp-settings.php); FormatLocale needs that locale.
		add_action(
			'setup_theme',
			static function (): void {
				$GLOBALS['perflocale_boot_locale'] = determine_locale();
			},
			PHP_INT_MAX
		);

			// Catalogs in languages/: loaded just in time when no language pack is installed.
		BundledTranslations::register_default();

		$plugin = Plugin::get_instance();

		// ---- Core services (always loaded) ----

		// Settings: loaded on every request, cached in memory after first read.
		$plugin->register( 'settings', fn() => new Settings() );

		// Cache manager: needed everywhere for the 3-layer cache.
		$plugin->register(
			'cache',
			fn( Plugin $p ) => new Cache\CacheManager( $p->get( 'settings' ) ),
			true
		);

		// Cache invalidator: hooks into save_post, etc. to flush stale entries. Its hooks are static forwarders
		// (InvalidatorHooks, booted here), so the invalidator is built at the first change, not on a page view.
		$plugin->register(
			'cache_invalidator',
			fn( Plugin $p ) => new Cache\CacheInvalidator( $p->get( 'cache' ) )
		);
		$plugin->register( 'cache_invalidator_hooks', fn() => new Cache\InvalidatorHooks(), true );

		// Shared repository singletons; registered before the router, which needs lang_repo
		// during detect_locale_early().
		$plugin->register(
			'lang_repo',
			fn( Plugin $p ) => new Database\Repository\LanguageRepository( $p->get( 'cache' ) )
		);
		$plugin->register(
			'group_repo',
			fn( Plugin $p ) => new Database\Repository\TranslationGroupRepository( $p->get( 'cache' ) )
		);

		// Language router: must run on every request to detect current language.
		$plugin->register(
			'router',
			fn( Plugin $p ) => new Router\LanguageRouter(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

		// Detect the language from the URL now, during plugin load, so the locale filter is right
		// before plugins load their text domains at init (parse_request is too late).
		$router = $plugin->get( 'router' );
		add_filter( 'locale', [ $router, 'filter_locale' ] );

		// Resolve the language query-var name here, at the same load point for admin and front
		// end: it memoises, and a later first call would see more filters registered.
		Router\UrlConverter::query_var();

		$router->detect_locale_early();

		// Rewrite manager: registers language-prefixed rewrite rules.
		$plugin->register(
			'rewrite_manager',
			fn( Plugin $p ) => new Router\RewriteManager( $p->get( 'settings' ) ),
			true
		);

		// Slug manager: preloads translated slugs for the current request.
		$plugin->register(
			'slug_manager',
			fn( Plugin $p ) => new Router\SlugManager( $p->get( 'cache' ) ),
			true
		);

		// URL converter: filters permalinks to include language prefix.
		$plugin->register(
			'url_converter',
			fn( Plugin $p ) => new Router\UrlConverter(
				$p->get( 'router' ),
				$p->get( 'settings' ),
				$p->get( 'slug_manager' ),
				$p->get( 'cache' ),
			),
			true
		);

		// GeoIP redirect: redirect visitors based on IP country.
		if ( (bool) $plugin->get( 'settings' )->get( 'redirect_geo_enabled' ) ) {
			$plugin->register(
				'geo_redirect',
				fn( Plugin $p ) => new Router\GeoRedirect(
					$p->get( 'settings' ),
					$p->get( 'router' ),
				),
				true
			);
		}

		// The first normal boot after a dormant one retires the hreflang
		// cache: it may list alternates changed while PerfLocale did not run.
		self::resume_after_dormant_boot( $plugin );

		// Database migrator: checks schema version on admin, REST, and CLI requests.
		$plugin->register(
			'migrator',
			fn() => new Database\Migrator(),
			is_admin() || Helper::is_rest_request() || ( defined( 'WP_CLI' ) && WP_CLI )
		);

		// Decided once for the service registrations below that depend on it.
		$write_context = Helper::is_write_context();

		// Content sync: registered whatever this blog's Sync Fields list holds, since a request
		// can switch blogs and write there. Booted up front in write contexts, else on the first
		// write ({@see self::boot_write_services_on_first_write()}).
		$plugin->register(
			'content_sync',
			fn( Plugin $p ) => new Translation\ContentSync(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			$write_context
		);

		// Allow duplicate post slugs across languages (global: WP checks uniqueness on save).
		add_filter( 'wp_unique_post_slug', [ self::class, 'allow_translation_duplicate_slugs' ], 10, 6 );

		// Auto-assign default language to new posts on save (all contexts).
		add_action( 'save_post', [ self::class, 'auto_assign_default_language' ], 5, 2 );

		// Remove a permanently deleted post's translation link, or its group keeps an orphan ID.
		add_action( 'before_delete_post', [ self::class, 'cleanup_translation_link_on_delete' ] );

		// Auto-create translation stubs when a post is published.
		if ( (bool) $plugin->get( 'settings' )->get( 'auto_create_stubs' ) ) {
			add_action( 'transition_post_status', [ self::class, 'auto_create_translation_stubs' ], 20, 3 );
		}

		// Auto-translate on publish: a background job per post, so publishing is instant.
		if ( $plugin->get( 'settings' )->mt_enabled() && (bool) $plugin->get( 'settings' )->get( 'mt_auto_translate_on_publish' ) ) {
			add_action( 'transition_post_status', [ self::class, 'auto_translate_on_publish' ], 25, 3 );
		}

		// Always registered, so events scheduled before the upgrade still run.
		add_action( self::AUTO_TRANSLATE_CRON, [ self::class, 'process_auto_translate' ], 10, 2 );

		// Add language labels to page dropdowns (Reading Settings, etc.). Admin only: a theme's or widget's dropdown
		// would load the admin code for an unchanged result.
		if ( is_admin() ) {
			add_filter( 'wp_dropdown_pages', [ self::class, 'add_language_labels_to_dropdown' ] );
		}

		// Clear the `_perflocale_meta_copy_errors` warning on real saves. Always loaded, not in
		// admin-only MetaBox, so REST and CLI saves clear it too; on save_post, not in a GET
		// notice hook, so the state change sits behind core's save nonce and capability checks.
		add_action(
			'save_post',
			[ BootstrapWrites::class, 'clear_meta_copy_errors' ],
			20,
			2
		);

		// Auto-assign default language to new terms (all contexts).
		add_action( 'created_term', [ self::class, 'auto_assign_default_term_language' ], 5, 4 );

		// Language Switcher Gutenberg block - must register on all requests.
		$plugin->register(
			'switcher_block',
			fn() => new Frontend\LanguageSwitcherBlock(),
			true
		);

		// Language-conditional content (block, shortcode, PHP helper): eager so the shortcode
		// resolves in feeds, REST previews and admin-ajax too.
		$plugin->register(
			'conditional_content',
			fn() => new Frontend\ConditionalContent(),
			true
		);

		// Block-toolbar "Do not translate": its MT filters only fire in write contexts.
		$plugin->register(
			'block_skip_filter',
			fn() => new Translation\BlockSkipFilter(),
			$write_context
		);

		// Every translation save keeps "Do not translate" blocks that match its source byte for
		// byte. Class-string callbacks: the class loads on the first post save, never on a view.
		add_filter( 'wp_insert_post_data', [ Translation\BlockSkipFilter::class, 'keep_on_save' ], PHP_INT_MAX, 3 );
		add_filter( 'wp_insert_attachment_data', [ Translation\BlockSkipFilter::class, 'keep_on_save' ], PHP_INT_MAX, 3 );
		add_filter( '_wp_post_revision_fields', [ Translation\BlockSkipFilter::class, 'note_restore' ], PHP_INT_MAX, 2 );
		add_action( 'wp_restore_post_revision', [ MachineTranslation\ProtectedBlockSave::class, 'restore_done' ] );

		// Block-toolbar "Translate" dropdown (the block editor is always an admin request).
		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', [ Admin\BlockToolbarAssets::class, 'enqueue' ] );
		}

		// CDN Cache-Tag emitter: only registered when enabled.
		if ( $plugin->get( 'settings' )->cdn_cache_tags_enabled() ) {
			$plugin->register(
				'cache_tag_emitter',
				fn() => new Frontend\CacheTagEmitter(),
				true
			);
		}

		// Content change detector: flags translations when the source changes. Booted up front in
		// write contexts; see boot_write_services_on_first_write() otherwise.
		$plugin->register(
			'content_change_detector',
			fn( Plugin $p ) => new Translation\ContentChangeDetector(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			$write_context
		);

		// Menu translation manager - per-language nav menus.
		$plugin->register(
			'menu_manager',
			fn( Plugin $p ) => new Translation\MenuManager(
				$p->get( 'router' ),
				$p->get( 'cache' ),
			),
			true
		);

		// Media translation - per-language alt text, captions, descriptions.
		$plugin->register(
			'media_translation',
			fn( Plugin $p ) => new Translation\MediaTranslationManager(
				$p->get( 'router' ),
				$p->get( 'cache' ),
			),
			true
		);

		// Translator role and caps: their hooks fire in admin and CLI only.
		$plugin->register(
			'translator_role',
			fn() => new Admin\TranslatorRole(),
			$write_context
		);

		// User deletion is not admin-only (front-end account plugins delete users), so wire the
		// job anonymisation directly. wpmu_delete_user does not fire delete_user; other sites are
		// reached through remove_user_from_blog.
		$anonymize_jobs = static function ( $user_id ): void {
			Background\JobState::anonymize_for_user( (int) $user_id );
		};

		add_action( 'delete_user', $anonymize_jobs );
		add_action( 'wpmu_delete_user', $anonymize_jobs );
		add_action( 'remove_user_from_blog', [ self::class, 'anonymize_jobs_on_blog' ], 10, 2 );

		// Privacy API exporter, eraser and policy text: privacy admin flows only.
		$plugin->register(
			'privacy_integration',
			fn( Plugin $p ) => new Admin\PrivacyIntegration( $p->get( 'settings' ) ),
			$write_context
		);

		// Generic Customizer integration - floating language switcher for any theme.
		$plugin->register(
			'customizer_integration',
			fn() => new Frontend\CustomizerIntegration(),
			true
		);

		// Theme builder add-ons boot here: the add-on registry boots at init:0, too late for
		// builder APIs that read their component lists during after_setup_theme.
		$early_theme_addons = [
			'blocksy' => 'addons/blocksy/PerfLocaleBlocksy.php',
			'kadence' => 'addons/kadence/PerfLocaleKadence.php',
			'neve'    => 'addons/neve/PerfLocaleNeve.php',
		];

		$current_template = get_template();

		foreach ( $early_theme_addons as $theme_slug => $addon_file ) {
			if ( $current_template !== $theme_slug ) {
				continue;
			}

			$addon_path = PERFLOCALE_DIR . $addon_file;

			if ( ! file_exists( $addon_path ) ) {
				continue;
			}

			// The add-on's get_id(): its file loads only once the checks below let it boot.
			$addon_id = $theme_slug;

			// This path boots ahead of AddonRegistry and marks the add-on booted, so apply the
			// registry's quarantine gate and perflocale/addon/enabled filter here, in its order.
			// Both options are autoloaded.
			$failures  = (array) get_option( 'perflocale_addon_failures', [] );
			$threshold = (int) apply_filters( 'perflocale/addons/quarantine_threshold', 3 );

			if ( $threshold > 0 && (int) ( $failures[ $addon_id ] ?? 0 ) >= $threshold ) {
				continue;
			}

			$default_on = ! in_array( $addon_id, Addon\AddonRegistry::get_disabled(), true );

			/** This filter is documented in src/Addon/AddonRegistry.php */
			if ( ! (bool) apply_filters( 'perflocale/addon/enabled', $default_on, $addon_id ) ) {
				// Hand the decision to the registry so it neither boots the add-on nor asks again.
				add_action(
					'plugins_loaded',
					static function () use ( $plugin, $addon_id, $default_on ): void {
						if ( $plugin->has( 'addon_registry' ) ) {
							$registry = $plugin->addon_registry();
							$registry->defer( $addon_id );
							$registry->mark_filter_off( $addon_id, $default_on );
						}
					},
					15
				);
				continue;
			}

			require_once $addon_path;
			$class_name = 'PerfLocale' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $theme_slug ) ) );

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$theme_addon = new $class_name();

			if ( ! $theme_addon instanceof \PerfLocale\Addon\AddonInterface ) {
				continue;
			}

			// Third-party code: catch so a throw feeds the quarantine counter instead of a
			// sitewide fatal. Mirrors AddonRegistry::boot_addons().
			try {
				if ( ! $theme_addon->is_compatible() ) {
					continue;
				}

				$theme_addon->boot( $plugin );
			} catch ( \Throwable $e ) {
				$failures[ $addon_id ] = (int) ( $failures[ $addon_id ] ?? 0 ) + 1;
				update_option( 'perflocale_addon_failures', $failures, true );
				Addon\AddonMigrationErrors::record(
					$addon_id,
					'boot',
					(int) $failures[ $addon_id ],
					\PerfLocale\Util\PathRedactor::redact( $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() )
				);
				continue;
			}

			// Booted cleanly: clear the boot failure counter and its admin record (boot stage only,
			// like AddonRegistry::boot_addons()).
			if ( isset( $failures[ $addon_id ] ) ) {
				unset( $failures[ $addon_id ] );
				update_option( 'perflocale_addon_failures', $failures, true );
				Addon\AddonMigrationErrors::clear_stage( $addon_id, 'boot' );
			}

			// Mark as already booted so AddonRegistry doesn't double-boot it.
			add_action(
				'plugins_loaded',
				static function () use ( $plugin, $theme_addon, $addon_id ): void {
					if ( $plugin->has( 'addon_registry' ) ) {
						$registry = $plugin->get( 'addon_registry' );
						$registry->register( $theme_addon );
						$registry->mark_booted( $addon_id );
					}
				},
				15
			);
		}

		// Admin bar language switcher - works on both frontend and admin. Core fires admin_bar_init
		// only when the toolbar shows, so an anonymous visitor never loads the class.
		$plugin->register(
			'admin_bar_switcher',
			fn( Plugin $p ) => new Admin\AdminBarSwitcher(
				$p->get( 'router' ),
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			)
		);
		add_action( 'admin_bar_init', [ Admin\AdminBarSwitcher::class, 'on_admin_bar_init' ] );

		// ---- Query filters (always registered, both admin AND frontend) ----
		// Admin too: otherwise metaboxes and menu pickers mix every language.
		$plugin->register(
			'post_query_filter',
			fn( Plugin $p ) => new Translation\PostQueryFilter(
				$p->get( 'router' ),
				$p->get( 'settings' ),
			),
			true
		);

		$plugin->register(
			'term_query_filter',
			fn( Plugin $p ) => new Translation\TermQueryFilter(
				$p->get( 'router' ),
				$p->get( 'settings' ),
			),
			true
		);

		// Swap a wrong-language term on post assignment: the classic tag box submits names, which
		// term_exists() can resolve to a same-named term in another language. Write contexts only.
		$plugin->register(
			'term_assignment_filter',
			fn( Plugin $p ) => new Translation\TermAssignmentFilter(
				$p->get( 'cache' ),
				$p->get( 'settings' ),
			),
			$write_context
		);

		// Writes also happen outside write contexts (front-end POST forms, XML-RPC, WP-Cron inside
		// a page view), known only later: there the three services above boot on the first write.
		if ( ! $write_context ) {
			self::boot_write_services_on_first_write( $plugin );
		}

		// The shared "Translations" panel renderer and its mount registry. Always registered (and
		// lazy): add-ons mount it from boot(), before the admin gate below decides the context.
		$plugin->register(
			'translations_panel',
			fn( Plugin $p ) => new Admin\TranslationsPanel( $p->get( 'cache' ) ),
			false
		);

		// ---- Context-specific services ----

		if ( is_admin() && ! wp_doing_ajax() ) {
			BootstrapAdmin::register_admin_services( $plugin );
			// Surface addon migration + uninstall errors in admin notices.
			\PerfLocale\Addon\AddonMigrationErrors::register_hooks();
		}

		// WP Site Health info section and status tests. Not inside the admin gate: core's weekly
		// scheduled check runs from cron (is_admin() false) and writes the result behind the
		// Tools count bubble. Tests that make a loopback or DNS request set `skip_cron`.
		if ( is_admin() || wp_doing_cron() ) {
			$plugin->register(
				'site_health',
				fn() => new Admin\SiteHealth(),
				false
			);
			Admin\SiteHealthHooks::register();
		}

		// AJAX handlers needed while wp_doing_ajax(): AdminController and TermMetaBox do not load
		// during AJAX.
		if ( wp_doing_ajax() ) {
			BootstrapAjax::register( $plugin );
		}

		// RTL stylesheet twins: one late pass (999) over every registered perflocale* style; no-op
		// on LTR. Outside the frontend/admin split below, or admin_enqueue_scripts never attaches.
		add_action( 'wp_enqueue_scripts', [ Helper::class, 'register_rtl_styles' ], 999 );
		add_action( 'admin_enqueue_scripts', [ Helper::class, 'register_rtl_styles' ], 999 );

		// An imposed rendering language must reach the string layer too: both string services load
		// their per-language map once, on language/detected. Not gated on multisite; the action
		// fires only on a real change and the reload is lazy.
		add_action(
			'perflocale/language/overridden',
			static function (): void {
				$plugin = Plugin::get_instance();

				foreach ( [ 'string_translation', 'translation_file_loader' ] as $service ) {
					if ( ! $plugin->has( $service ) ) {
						continue;
					}

					$instance = $plugin->get( $service );

					if ( method_exists( $instance, 'reset_for_blog_switch' ) ) {
						$instance->reset_for_blog_switch();
					}
				}
			},
			5
		);

		// Per-language site title and tagline. Outside the frontend/admin split below on purpose:
		// in wp-admin it registers option strings and catches `perflocale/updated`, which
		// Migrator fires on admin_init. See docs-ai/11-code-rationale.md, "Bootstrap: services
		// outside the frontend/admin split".
		$plugin->register(
			'option_strings',
			fn( Plugin $p ) => new Frontend\OptionStrings( $p->get( 'router' ) ),
			true
		);

		// ---- Block-theme template translation (FSE) ----
		// Outside the frontend/admin split below: an admin-post create must shape template
		// translations too. Kept before `addon_registry`, so neither constructor nor
		// register_hooks() may read get_translatable_post_types(): no eager read here.
		$plugin->register(
			'block_template_translator',
			fn( Plugin $p ) => new Frontend\BlockTemplateTranslator( $p->get( 'router' ), $p->get( 'settings' ), $p->get( 'lang_repo' ) ),
			true
		);

		$plugin->register(
			'block_template_support',
			fn( Plugin $p ) => new Translation\BlockTemplateSupport(),
			true
		);

		if ( ! is_admin() || wp_doing_ajax() ) {
			self::register_frontend_services( $plugin );
		} else {
			// Admin still needs the switcher widget and shortcode: the widget screens and the
			// customizer enumerate widget types in plain admin requests.
			$plugin->register(
				'language_switcher',
				fn( Plugin $p ) => new Frontend\LanguageSwitcher(
					$p->get( 'router' ),
					$p->get( 'url_converter' ),
					$p->get( 'settings' ),
				),
				true
			);
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			BootstrapAdmin::register_cli_services( $plugin );
		}

		// REST API controllers - registered on rest_api_init.
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

		// Invalidate the edge-config cache when languages or settings change.
		add_action( 'perflocale/language/added', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/language/updated', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/language/deleted', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/languages/reordered', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/default_language/changed', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/settings/updated', [ Api\ConfigController::class, 'invalidate' ] );

		// Switching string mode to files: regenerate all .l10n.php files in the saving request,
		// then clear the transient so TranslationFileLoader stops retrying.
		add_action(
			'perflocale/strings/regenerate_files',
			static function ( Cache\CacheManager $cache ): void {
				$generator = new Strings\TranslationFileGenerator( $cache );
				$generator->generate_all();
				delete_transient( 'perflocale_strings_regenerating' );
			}
		);

		// Files mode compiles active non-default languages only, so activating one, changing its
		// locale or changing the default queues one full regeneration at request end.
		// See queue_files_regeneration().
		add_action( 'perflocale/language/updated', [ self::class, 'regenerate_files_after_language_update' ], 20, 2 );
		add_action( 'perflocale/default_language/changed', [ self::class, 'queue_files_regeneration' ], 20, 0 );

		// After a plugin update in files mode: one full regeneration in the background, never in
		// the request that noticed the update.
		add_action( 'perflocale/updated', [ self::class, 'queue_files_regeneration_after_update' ], 10, 0 );
		add_action( self::FILES_REGENERATE_HOOK, [ self::class, 'regenerate_files_in_background' ], 10, 0 );

		// Webhook delivery runs on WP-Cron so the originating request is not blocked.
		add_action(
			self::WEBHOOK_DELIVERY_HOOK,
			static function ( string $webhook_id, string $event, array $data, string $timestamp ): void {
				( new Api\WebhookController() )->deliver_webhook( $webhook_id, $event, $data, $timestamp, 1 );
			},
			10,
			4
		);

		// Drains the coalesced delivery queue in bounded batches, so the autoloaded `cron` option
		// holds one event instead of thousands.
		add_action(
			self::WEBHOOK_DRAIN_HOOK,
			static function (): void {
				( new Api\WebhookController() )->drain_webhooks();
			}
		);

		// Retries failed deliveries (attempt 2+) and breaker-deferred re-deliveries.
		add_action(
			self::WEBHOOK_RETRY_HOOK,
			static function ( string $webhook_id, string $event, array $data, string $timestamp, int $attempt, string $delivery_id = '', int $breaker_deferrals = 0 ): void {
				( new Api\WebhookController() )->deliver_webhook( $webhook_id, $event, $data, $timestamp, $attempt, $delivery_id, $breaker_deferrals );
			},
			10,
			7
		);

		// Fan out translation events to webhooks, in write contexts only (the events never fire
		// on a front-end GET). wp_doing_cron() is required: background jobs create translations
		// in the cron worker.
		if (
			is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| Helper::is_rest_request()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			add_action(
				'plugins_loaded',
				static function (): void {
					// Single-site only: on multisite an event under a switched blog must reach that
					// blog's subscribers, so fire_webhook() checks per blog.
					if ( ! is_multisite() ) {
						$webhooks = get_option( 'perflocale_webhooks', [] );

						if ( empty( $webhooks ) || ! is_array( $webhooks ) ) {
							return;
						}
					}

					Api\WebhookController::register_event_dispatchers();
				},
				20
			);
		}

		// Multisite: clear per-request static caches on blog switch. Gated on is_multisite():
		// single-site switch_to_blog() still fires switch_blog, and plugins call it with the
		// current blog. LanguageRouter has its own same-blog-aware listener: never add a raw
		// reset of it here.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_static_state' ] );
		}

		// Language changes within a request invalidate the slug repo's has_any_slugs memo, as
		// for the other routing classes.
		$reset_slug_repo = [ Database\Repository\SlugTranslationRepository::class, 'reset_static_caches' ];

		add_action( 'perflocale/language/added', $reset_slug_repo );
		add_action( 'perflocale/language/updated', $reset_slug_repo );
		add_action( 'perflocale/language/slug_renamed', $reset_slug_repo );

		// Helper's per-request current-language memo: same invalidation triggers.
		$reset_helper = [ Helper::class, 'reset_static_caches' ];

		add_action( 'perflocale/language/added', $reset_helper );
		add_action( 'perflocale/language/updated', $reset_helper );
		add_action( 'perflocale/language/slug_renamed', $reset_helper );
		add_action( 'perflocale/language/deleted', $reset_helper );

		// And when a rendering window imposes a language, or Helper::with_language() and
		// perflocale()->slug()/is_rtl() describe the request's language inside the window.
		add_action( 'perflocale/language/overridden', $reset_helper );
		add_action( 'perflocale/language/deleted', $reset_slug_repo );

		// Scheduled cleanup for abandoned import temp files. The hook is publicly callable, so
		// only files inside the temp dir are deleted (realpath + prefix check).
		add_action(
			'perflocale_cleanup_temp_import',
			[ BootstrapMaintenance::class, 'cleanup_temp_import' ]
		);

		// Daily reaper for expired lock rows (crashed requests leak them); the wrapper discards
		// reap_expired()'s return.
		add_action(
			self::LOCK_CLEANUP_HOOK,
			static function (): void {
				Concurrency\Lock::reap_expired();
			}
		);

		// Action Scheduler integration, deferred to `init`: AS is not loaded yet while this file
		// runs, nor at plugins_loaded on a stock WooCommerce site. Priority 1 precedes any queue run.
		add_action(
			'init',
			static function (): void {
				if ( class_exists( 'ActionScheduler' ) || class_exists( 'ActionScheduler_Store' ) ) {
					// Raise AS's 5-minute failure period to STUCK_TIMEOUT so long jobs are not
					// re-claimed while alive. The filter is store-wide (no action context), so raise
					// it only while one of our jobs has a live worker; otherwise pass it through.
					add_filter(
						'action_scheduler_failure_period',
						static function ( $time_limit ) {
							if ( ! Background\JobState::has_live_job_in_flight() ) {
								return $time_limit;
							}

							return max( (int) $time_limit, Background\JobState::STUCK_TIMEOUT );
						}
					);

					// AS failure states reach the jobs table; the bridge loads only when one fires.
					add_action( 'action_scheduler_failed_execution', [ Background\ActionSchedulerFailureBridge::class, 'on_failed_execution' ], 10, 2 );
					add_action( 'action_scheduler_failed_action', [ Background\ActionSchedulerFailureBridge::class, 'on_timed_out' ], 10, 1 );
					add_action( 'action_scheduler_unexpected_shutdown', [ Background\ActionSchedulerFailureBridge::class, 'on_unexpected_shutdown' ], 10, 2 );
				}
			},
			1
		);

		// Weekly GC for `perflocale_mt_usage_YYYY_MM` options (13-month retention), per blog.
		add_action(
			'perflocale_mt_usage_gc',
			[ BootstrapMaintenance::class, 'mt_usage_gc' ],
			10,
			1
		);

		// Daily background-jobs GC, scheduled per blog: the blog id is the event argument and
		// run_recurring_for_blog() switches to it.
		add_action(
			'perflocale_jobs_gc',
			[ BootstrapMaintenance::class, 'jobs_gc' ]
		);

		// Hourly stuck-job watchdog - same multisite consideration as GC.
		add_action(
			'perflocale_jobs_watchdog',
			static function ( $blog_id = 0 ): void {
				Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'watchdog' ] );
			}
		);

		// Schedule both recurring events under the current engine: admin_init only (the drift
		// probe queries the schedule store), which runs after AS's init@1 hook. Not on AJAX (heartbeat).
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'admin_init', [ self::class, 'ensure_recurring_schedules_throttled' ] );
		}

		// Engine flip: move the recurring events to the new engine at once.
		add_action( 'update_option_perflocale_settings', [ self::class, 'maybe_remigrate_engine' ], 10, 2 );

		// Reset Settings' memo on save, so code reading a setting after saving it sees the new value.
		add_action(
			'update_option_perflocale_settings',
			static function (): void {
				$plugin = Plugin::get_instance();

				if ( ! $plugin->has( 'settings' ) ) {
					return;
				}

				$settings = $plugin->get( 'settings' );

				if ( method_exists( $settings, 'reset_cache' ) ) {
					$settings->reset_cache();
				}
			},
			1
		);

		// Settings change: (un)schedule recurring features at once. Priority 20, after the memo
		// reset (1) and engine remigration (10).
		add_action( 'update_option_perflocale_settings', [ self::class, 'ensure_recurring_schedules' ], 20 );

		// One-shot resume after activation: deactivation clears worker events but keeps JobState
		// rows, and Activator schedules `perflocale_resume_jobs` to re-enqueue queued or running ones.
		add_action(
			'perflocale_resume_jobs',
			static function (): void {
				Background\Resumer::resume();
			}
		);

		// Background workers: each binds `perflocale_job_run_<type>` to WorkerRegistry::run().
		// Factories give each run a fresh object.
		Background\WorkerRegistry::register(
			'data_import',
			static fn(): Background\AbstractJob => new Background\Jobs\DataImportJob()
		);
		Background\WorkerRegistry::register(
			'data_export',
			static fn(): Background\AbstractJob => new Background\Jobs\DataExportJob()
		);
		// Migration importers and template jobs run from cron or Action Scheduler, never a page
		// view: bind them in job-capable contexts only.
		if ( $write_context ) {
			Background\WorkerRegistry::register(
				'wpml_migration',
				static fn(): Background\AbstractJob => new Background\Jobs\WpmlMigrationJob()
			);
			Background\WorkerRegistry::register(
				'polylang_migration',
				static fn(): Background\AbstractJob => new Background\Jobs\PolylangMigrationJob()
			);
			Background\WorkerRegistry::register(
				'translatepress_migration',
				static fn(): Background\AbstractJob => new Background\Jobs\TranslatePressMigrationJob()
			);
			// One-time backfill of menu and pattern references in existing translated templates.
			Background\WorkerRegistry::register(
				'template_refs_backfill',
				static fn(): Background\AbstractJob => new Background\Jobs\TemplateRefsBackfillJob()
			);
			// Containers a menu or pattern event reaches beyond the changing request
			// (TemplateBlockRefs::fan_out()).
			Background\WorkerRegistry::register(
				'template_refs_fanout',
				static fn(): Background\AbstractJob => new Background\Jobs\TemplateRefsFanoutJob()
			);
			// XLIFF imports queued by POST /xliff/import.
			Background\WorkerRegistry::register(
				'xliff_import',
				static fn(): Background\AbstractJob => new Background\Jobs\XliffImportJob()
			);

			// Not on AJAX: the job refuses to dispatch there, after its class loaded.
			if ( is_admin() && ! wp_doing_ajax() ) {
				add_action( 'admin_init', [ self::class, 'maybe_dispatch_template_refs_backfill' ], 20 );
			}

			// One-time jobs record a cancel in their dispatch state, so an admin page decides
			// from that option alone.
			add_action( 'perflocale/jobs/canceled', [ Background\OneTimeDispatch::class, 'on_canceled' ], 10, 2 );

			// SQLite only: repair of string groups an older scan shared. The engine is checked first,
			// so MySQL loads nothing; wp-admin only queues the job.
			if ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ) {
				Background\WorkerRegistry::register(
					'sqlite_group_repair',
					static fn(): Background\AbstractJob => new Background\Jobs\StringGroupRepairJob()
				);

				if ( is_admin() && ! wp_doing_ajax() ) {
					add_action( 'admin_init', [ self::class, 'maybe_dispatch_sqlite_group_repair' ] );
				}
			}
		}
		Background\WorkerRegistry::register(
			'string_scan',
			static fn(): Background\AbstractJob => new Background\Jobs\StringScanJob()
		);
		Background\WorkerRegistry::register(
			'bulk_translate',
			static fn(): Background\AbstractJob => new Background\Jobs\BulkTranslateJob()
		);
		Background\WorkerRegistry::register(
			'auto_translate',
			static fn(): Background\AbstractJob => new Background\Jobs\AutoTranslateJob()
		);

		// Pre-dispatch MT budget gate: veto a bulk MT dispatch whose estimate (skip-existing aware,
		// via CostEstimator) exceeds the monthly cap, before any provider spend. Opt-out setting.
		add_filter(
			'perflocale/jobs/should_dispatch',
			[ BootstrapMaintenance::class, 'mt_budget_gate' ],
			10,
			3
		);

		// A bulk MT dispatch counts as one request of the user to the hourly limiter. Priority
		// 1000: after the budget gate and other vetoes, so a refused dispatch is not counted. A
		// site-wide chain's next chunk (after_id > 0) was already counted.
		add_filter(
			'perflocale/jobs/should_dispatch',
			[ BootstrapMaintenance::class, 'mt_rate_gate' ],
			1000,
			3
		);

		Background\WorkerRegistry::register(
			'bulk_string_translate',
			static fn(): Background\AbstractJob => new Background\Jobs\BulkStringTranslateJob()
		);
		Background\WorkerRegistry::register(
			'site_translate',
			static fn(): Background\AbstractJob => new Background\Jobs\SiteTranslateJob()
		);

		// Addon registry - discovers and boots addons after all plugins load.
		$plugin->register(
			'addon_registry',
			fn() => new Addon\AddonRegistry(),
			true
		);

		// Invalidate the add-on bootable-set transient when plugins or the theme change.
		Addon\AddonRegistry::register_cache_invalidation();

		// Retire stored WooCommerce stock maps when stock sync, the add-on list or WooCommerce is
		// switched, in every context. Class-name callbacks: InventorySync loads only when one fires.
		add_action( 'perflocale/settings/updated', [ WooCommerce\InventorySync::class, 'retire_after_settings_change' ], 10, 2 );
		add_action( 'update_option_perflocale_disabled_addons', [ WooCommerce\InventorySync::class, 'retire_stored_maps' ], 10, 0 );
		add_action( 'activated_plugin', [ WooCommerce\InventorySync::class, 'retire_after_plugin_toggle' ], 10, 1 );
		add_action( 'deactivated_plugin', [ WooCommerce\InventorySync::class, 'retire_after_plugin_toggle' ], 10, 1 );

		// SEO integrations.
		$plugin->register(
			'seo_hreflang_manager',
			fn( Plugin $p ) => new Seo\HreflangManager( $p->get( 'settings' ) ),
			true
		);

		$plugin->register(
			'seo_sitemap',
			fn( Plugin $p ) => new Seo\SitemapIntegration( $p->get( 'settings' ) ),
			true
		);

		// Boot all eager services (register_hooks() on each) at init priority 0.
		add_action( 'init', [ $plugin, 'boot' ], 0 );

		// Abilities API (WP 6.9+): the abilities_enabled setting, overridable by the filter. Write
		// abilities are a separate setting, off by default.
		/** @hook perflocale/abilities/enabled Enable the WordPress Abilities API integration. Default: the `abilities_enabled` setting. */
		if ( apply_filters( 'perflocale/abilities/enabled', (bool) $plugin->get( 'settings' )->get( 'abilities_enabled', true ) ) ) {
			add_action( 'wp_abilities_api_categories_init', [ AbilitiesRegistrar::class, 'on_categories_init' ] );
			add_action( 'wp_abilities_api_init', [ AbilitiesRegistrar::class, 'on_abilities_init' ] );
		}

		// Register the [perflocale_language] utility shortcode.
		add_action( 'init', [ self::class, 'register_language_shortcode' ], 20 );

		// Expose the per-post opt-out flags to the block editor via REST meta. Priority 20 so CPTs
		// registered at the default priority exist.
		add_action(
			'init',
			static function (): void {
				$plugin = Plugin::get_instance();

				if ( ! $plugin->has( 'settings' ) ) {
					return;
				}

				$flag_args = [
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $value ): string => 'yes' === $value ? 'yes' : '',
					'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				];

				// REST writes `meta` after save_post has fired, so the opt-out flag is stored early
				// (see apply_rest_sync_optout()).
				$early_optout = Helper::is_write_context();

				foreach ( $plugin->get( 'settings' )->get_translatable_post_types() as $post_type ) {
					register_post_meta( $post_type, Translation\SyncMeta::SYNC_OPTOUT_META, $flag_args );
					register_post_meta( $post_type, Helper::SEO_EXCLUDE_META, $flag_args );

					if ( $early_optout && is_string( $post_type ) ) {
						add_filter( 'rest_pre_insert_' . $post_type, [ self::class, 'apply_rest_sync_optout' ], 10, 2 );
					}
				}
			},
			20
		);

		/** @hook perflocale/loaded Fires after all PerfLocale services are registered. */
		do_action( 'perflocale/loaded', $plugin );
	}

	/**
	 * Drop the per-request memos that hold one blog's data: on switch_blog and from refresh_if_changed().
	 *
	 * Every blog-affine static or singleton memo must be reset here or be blog-keyed. Only one
	 * string service exists per mode, so whichever is registered resets. LanguageRouter has its
	 * own same-blog-aware switch_blog listener: never reset it here.
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
	 * `admin_init`: TemplateRefsBackfillJob::maybe_dispatch(), unless the state option says done.
	 *
	 * The done check reads the autoloaded option before any job class loads.
	 *
	 * @return void
	 */
	public static function maybe_dispatch_template_refs_backfill(): void {
		if ( self::one_time_job_done( self::TEMPLATE_REFS_BACKFILL_OPTION ) ) {
			return;
		}

		Background\Jobs\TemplateRefsBackfillJob::maybe_dispatch();
	}

	/**
	 * `admin_init` on SQLite: Background\Jobs\StringGroupRepairJob::maybe_dispatch(),
	 * after the same done check as maybe_dispatch_template_refs_backfill().
	 *
	 * @return void
	 */
	public static function maybe_dispatch_sqlite_group_repair(): void {
		if ( self::one_time_job_done( self::SQLITE_GROUP_REPAIR_OPTION ) ) {
			return;
		}

		Background\Jobs\StringGroupRepairJob::maybe_dispatch();
	}

	/**
	 * Whether a one-time job's state option says done (`done:<unix time>`).
	 *
	 * @param string $option State option.
	 * @return bool
	 */
	private static function one_time_job_done( string $option ): bool {
		$state = get_option( $option, '' );

		return is_string( $state ) && str_starts_with( $state, 'done:' );
	}

	/**
	 * Throttled ensure_recurring_schedules() for admin_init: full run on engine drift, else every 6 hours.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules_throttled(): void {
		BootstrapMaintenance::ensure_recurring_schedules_throttled();
	}

	/**
	 * Ensure the recurring background events are scheduled under the active engine.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules(): void {
		BootstrapMaintenance::ensure_recurring_schedules();
	}

	/**
	 * On a `background_engine` change, move every recurring event to the new engine.
	 *
	 * @param mixed $old_value Previous option payload (array or scalar).
	 * @param mixed $new_value New option payload (array or scalar).
	 * @return void
	 */
	public static function maybe_remigrate_engine( $old_value, $new_value ): void {
		BootstrapMaintenance::maybe_remigrate_engine( $old_value, $new_value );
	}

	/**
	 * Register frontend-only services (never in a non-AJAX admin request).
	 *
	 * Page-response services (slug redirect, Content-Language, opt-in emitters, snippet guard) boot only where a page is served;
	 * AJAX, WP-Cron, WP-CLI, XML-RPC and wp-login.php keep them registered for get(). REST cannot be told apart at init.
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	private static function register_frontend_services( Plugin $plugin ): void {
		// wp-login.php by core's own $pagenow (set before plugins load); unset, it fails open.
		$page = ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			&& 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' );

		$plugin->register(
			'language_switcher',
			fn( Plugin $p ) => new Frontend\LanguageSwitcher(
				$p->get( 'router' ),
				$p->get( 'url_converter' ),
				$p->get( 'settings' ),
			),
			true
		);

		$plugin->register(
			'hreflang',
			fn( Plugin $p ) => new Frontend\HreflangTags(
				$p->get( 'router' ),
				$p->get( 'url_converter' ),
				$p->get( 'settings' ),
			),
			true
		);

		// AJAX keeps it: a handler may print the styles a switcher it rendered enqueued.
		$plugin->register(
			'frontend_assets',
			fn() => new Frontend\Assets(),
			$page || wp_doing_ajax()
		);

		// 301 redirector for renamed language slugs; no-op when the redirect map is empty.
		$plugin->register(
			'slug_redirector',
			fn() => new Frontend\SlugRedirector(),
			$page
		);

		// Per-language synced patterns and navigation menus (they hold only a `ref`). Front end
		// only: a `ref` rewritten in the editor would be saved into the source page, so
		// BlockRefTranslator refuses to attach under is_admin() and detaches before REST dispatch.
		$plugin->register(
			'block_ref_translator',
			fn( Plugin $p ) => new Frontend\BlockRefTranslator( $p->get( 'router' ), $p->get( 'settings' ) ),
			true
		);

		// Per-language date and time formats (option_date_format / option_time_format filters).
		$plugin->register(
			'locale_date_format',
			fn( Plugin $p ) => new Frontend\LocaleDateFormat( $p->get( 'router' ) ),
			true
		);

		// Content-Language header, gated by the `content_language_header` setting.
		$plugin->register(
			'content_language_header',
			fn( Plugin $p ) => new Frontend\ContentLanguageHeader(
				$p->get( 'router' ),
				$p->get( 'settings' ),
			),
			$page
		);

		// View Transitions emitter: opt-in; booted only when the setting is on.
		$plugin->register(
			'view_transitions',
			fn( Plugin $p ) => new Frontend\ViewTransitionsEmitter(
				$p->get( 'settings' ),
			),
			$page && (bool) $plugin->settings()->get( 'view_transitions_enabled', false )
		);

		// Speculation Rules emitter: prerenders translation targets on switcher hover. Opt-in; booted only when on.
		$plugin->register(
			'speculation_rules',
			fn( Plugin $p ) => new Frontend\SpeculationRulesEmitter(
				$p->get( 'settings' ),
				$p->get( 'router' ),
				$p->get( 'url_converter' ),
			),
			$page && (bool) $plugin->settings()->get( 'prerender_on_hover', false )
		);

		// data-nosnippet wrapper when the singular post's language differs from the URL's (the
		// show_default missing-translation case).
		$plugin->register(
			'fallback_snippet_guard',
			fn( Plugin $p ) => new Translation\FallbackSnippetGuard(
				$p->get( 'router' ),
				$p->get( 'settings' ),
				new Translation\PostTranslationManager( $p->get( 'cache' ), $p->get( 'settings' ) ),
			),
			$page
		);

		// String translation - mode-dependent (files or database).
		$str_mode = ( $plugin->get( 'settings' ) )->get( 'string_translation_mode' );

		if ( $str_mode === 'database' ) {
			// Database mode: gettext filter with lazy loading.
			$plugin->register(
				'string_translation',
				fn( Plugin $p ) => new Strings\StringTranslation(
					$p->get( 'router' ),
					$p->get( 'cache' ),
				),
				true
			);
		} else {
			// Files mode: serve translations from .l10n.php files via gettext filter.
			$plugin->register(
				'translation_file_loader',
				fn( Plugin $p ) => new Strings\TranslationFileLoader(
					$p->get( 'router' ),
				),
				true
			);
		}

		// Not PostQueryFilter / TermQueryFilter: they are always registered above, and
		// registering them again here would fatal.
	}

	/**
	 * Register REST API routes on rest_api_init.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		BootstrapAdmin::register_rest_routes();
	}

	/**
	 * Add language labels ("[EN]") to wp_dropdown_pages options.
	 *
	 * @param string $output Dropdown HTML.
	 * @return string Modified HTML.
	 */
	public static function add_language_labels_to_dropdown( string $output ): string {
		return BootstrapAdmin::add_language_labels_to_dropdown( $output );
	}

	/**
	 * Allow duplicate post slugs across languages; the URL's language prefix keeps them apart.
	 *
	 * @param string   $slug The slug WP wants to assign (possibly with -2, -3, etc.).
	 * @param int|null $post_id Post ID; null on a REST create, read as 0 like wp_insert_post()'s new post.
	 * @param string   $post_status Post status.
	 * @param string   $post_type Post type.
	 * @param int|null $post_parent Post parent ID; null on a REST create.
	 * @param string   $original_slug The original desired slug before deduplication.
	 * @return string The slug to use.
	 */
	public static function allow_translation_duplicate_slugs(
		string $slug,
		$post_id,
		string $post_status,
		string $post_type,
		$post_parent,
		string $original_slug
	): string {
		return BootstrapWrites::allow_translation_duplicate_slugs( $slug, (int) $post_id, $post_status, $post_type, (int) $post_parent, $original_slug );
	}

	/**
	 * Remove a permanently deleted post's translation link and slug/content-hash rows.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function cleanup_translation_link_on_delete( int $post_id ): void {
		BootstrapWrites::cleanup_translation_link_on_delete( $post_id );
	}

	/**
	 * Store a sync opt-out flag sent in a REST update before the post is saved.
	 *
	 * @param \stdClass|\WP_Error   $prepared_post Post prepared for the database.
	 * @param \WP_REST_Request|null $request       Request being handled.
	 * @return \stdClass|\WP_Error The prepared post, unchanged.
	 */
	public static function apply_rest_sync_optout( $prepared_post, $request = null ) {
		return BootstrapWrites::apply_rest_sync_optout( $prepared_post, $request );
	}

	/**
	 * Boot the write-hook services on the first write they act on, in a request that was not a write context.
	 *
	 * A meta write boots them only for a key they act on, or once ContentSync is loaded (its static state may be
	 * in use). See docs-ai/11-code-rationale.md, "Bootstrap: write services on first write".
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	private static function boot_write_services_on_first_write( Plugin $plugin ): void {
		$booted = false;

		$boot = static function ( mixed $value = null ) use ( $plugin, &$booted ): mixed {
			if ( $booted ) {
				return $value;
			}

			$booted = true;

			foreach ( [ 'content_sync', 'content_change_detector', 'term_assignment_filter' ] as $id ) {
				if ( ! $plugin->has( $id ) ) {
					continue;
				}

				$service = $plugin->get( $id );

				if ( method_exists( $service, 'register_hooks' ) ) {
					$service->register_hooks();
				}
			}

			return $value;
		};

		$boot_meta = static function ( mixed $check = null, mixed $object_id = 0, mixed $meta_key = '' ) use ( $plugin, $boot, &$booted ): mixed {
			if ( ! $booted && ( class_exists( Translation\ContentSync::class, false ) || ( is_string( $meta_key )
				&& ( Translation\SyncMeta::is_seed_clear_tracked( $meta_key )
					|| Translation\SyncMeta::may_sync_meta_key( $meta_key, (array) $plugin->settings()->get( 'sync_fields', [] ) ) ) ) ) ) {
				$boot();
			}

			return $check;
		};

		add_filter( 'wp_insert_post_data', $boot, PHP_INT_MIN );
		add_filter( 'add_post_metadata', $boot_meta, PHP_INT_MIN, 3 );
		add_filter( 'update_post_metadata', $boot_meta, PHP_INT_MIN, 3 );
		add_filter( 'delete_post_metadata', $boot_meta, PHP_INT_MIN, 3 );
		$boot_action = static function () use ( $boot ): void {
			$boot();
		};

		add_action( 'edit_terms', $boot_action, PHP_INT_MIN );
		add_action( 'set_object_terms', $boot_action, PHP_INT_MIN );
		// wp_publish_post() passes no data filter and fires this before save_post.
		add_action( 'transition_post_status', $boot_action, PHP_INT_MIN );
		add_action( 'future_to_publish', $boot_action, PHP_INT_MIN );
	}

	/**
	 * Anonymise a user's background-job rows on the site they are removed from.
	 *
	 * @param int|string $user_id User being removed.
	 * @param int|string $blog_id Site the user is removed from.
	 * @return void
	 */
	public static function anonymize_jobs_on_blog( $user_id, $blog_id = 0 ): void {
		BootstrapWrites::anonymize_jobs_on_blog( $user_id, $blog_id );
	}

	/**
	 * Auto-assign the default language to new translatable posts (save_post:5, every context).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_assign_default_language( int $post_id, ?\WP_Post $post = null ): void {
		BootstrapWrites::auto_assign_default_language( $post_id, $post );
	}

	/**
	 * Auto-create empty translation stubs when a post is first published.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_create_translation_stubs( string $new_status, string $old_status, \WP_Post $post ): void {
		BootstrapWrites::auto_create_translation_stubs( $new_status, $old_status, $post );
	}

	/**
	 * Create the stubs of a post whose save has just completed.
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function create_stubs_after_insert( $post_id ): void {
		BootstrapWrites::create_stubs_after_insert( $post_id );
	}

	/**
	 * Create the stubs of a product once WooCommerce has saved its data.
	 *
	 * @param int|string $product_id Product ID.
	 * @return void
	 */
	public static function create_stubs_after_product_save( $product_id ): void {
		BootstrapWrites::create_stubs_after_product_save( $product_id );
	}

	/**
	 * Create the stubs still pending at request end (no wp_after_insert_post or WooCommerce hook).
	 *
	 * @return void
	 */
	public static function create_pending_stubs(): void {
		BootstrapWrites::create_pending_stubs();
	}

	/**
	 * Background event: one full regeneration of the compiled translation
	 * files after a plugin update (files mode).
	 *
	 * Public so Deactivator can clear it on deactivation.
	 */
	public const FILES_REGENERATE_HOOK = 'perflocale_regenerate_translation_files';

	/**
	 * Queue a full file regeneration when a language update leaves a language without files.
	 *
	 * @param mixed $language Language row after the update (null when it could not be read).
	 * @param mixed $before   Language row before the update, as an array.
	 * @return void
	 */
	public static function regenerate_files_after_language_update( $language, $before = [] ): void {
		BootstrapWrites::regenerate_files_after_language_update( $language, $before );
	}

	/**
	 * Queue one full regeneration of this blog's compiled files for request end (files mode).
	 *
	 * @return void
	 */
	public static function queue_files_regeneration(): void {
		BootstrapWrites::queue_files_regeneration();
	}

	/**
	 * Run the queued regenerations: one generate_all() per blog still in files mode.
	 *
	 * @return void
	 */
	public static function run_pending_files_regeneration(): void {
		BootstrapWrites::run_pending_files_regeneration();
	}

	/**
	 * Queue the background regeneration after a plugin update (files mode; one pending event).
	 *
	 * @return void
	 */
	public static function queue_files_regeneration_after_update(): void {
		BootstrapWrites::queue_files_regeneration_after_update();
	}

	/**
	 * Handler of FILES_REGENERATE_HOOK: one full regeneration while still in files mode.
	 *
	 * @return void
	 */
	public static function regenerate_files_in_background(): void {
		BootstrapWrites::regenerate_files_in_background();
	}

	/**
	 * Hook of the auto-translate events 1.0.7.3 and earlier scheduled; each now queues a job.
	 */
	public const AUTO_TRANSLATE_CRON = 'perflocale_auto_translate_post';

	/**
	 * transition_post_status: queue auto-translation of a newly published post (Background\Jobs\AutoTranslateJob).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public static function auto_translate_on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		BootstrapWrites::auto_translate_on_publish( $new_status, $old_status, $post );
	}

	/**
	 * AUTO_TRANSLATE_CRON: run an event an earlier version scheduled as a job.
	 *
	 * @param int $post_id Post ID.
	 * @param int $attempt Handoffs tried before.
	 * @return void
	 */
	public static function process_auto_translate( int $post_id, int $attempt = 0 ): void {
		BootstrapWrites::process_auto_translate( $post_id, $attempt );
	}

	/**
	 * Auto-assign the default language to new translatable terms.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function auto_assign_default_term_language( int $term_id, int $tt_id, string $taxonomy, array $args = [] ): void {
		BootstrapWrites::auto_assign_default_term_language( $term_id, $tt_id, $taxonomy, $args );
	}

	/**
	 * Record a boot where a conflicting multilingual plugin keeps PerfLocale from running.
	 *
	 * No cache listener runs then, so hreflang may go stale. Only the first dormant boot writes
	 * DORMANT_KEY, at plugins_loaded, when the option-update listeners can run.
	 *
	 * @return void
	 */
	private static function note_dormant_boot(): void {
		$stored = get_option( 'perflocale_settings' );

		if ( ! is_array( $stored ) || isset( $stored[ self::DORMANT_KEY ] ) ) {
			return;
		}

		add_action(
			'plugins_loaded',
			static function (): void {
				$stored = get_option( 'perflocale_settings' );

				if ( ! is_array( $stored ) || isset( $stored[ self::DORMANT_KEY ] ) ) {
					return;
				}

				$stored[ self::DORMANT_KEY ] = 1;

				update_option( 'perflocale_settings', $stored );
			},
			0
		);
	}

	/**
	 * On the first normal boot after a dormant one, retire the hreflang cache and drop the record.
	 *
	 * The group is retired at plugins_loaded:0. The record goes at the end of init, after Action
	 * Scheduler init (the settings listeners pick the engine), under the settings lock on a fresh
	 * read. See docs-ai/11-code-rationale.md, "Bootstrap: dormant boot".
	 *
	 * @param Plugin $plugin Plugin container.
	 * @return void
	 */
	private static function resume_after_dormant_boot( Plugin $plugin ): void {
		$settings = $plugin->get( 'settings' );

		if ( ! $settings instanceof Settings || empty( $settings->get( self::DORMANT_KEY ) ) ) {
			return;
		}

		add_action(
			'plugins_loaded',
			static function () use ( $plugin, $settings ): void {
				$cache = $plugin->get( 'cache' );

				if ( $cache instanceof Cache\CacheManager ) {
					$cache->invalidate_group( 'perflocale_hreflang' );
				}

				add_action(
					'init',
					static function () use ( $settings ): void {
						Concurrency\Lock::with(
							'settings_update',
							10,
							static function () use ( $settings ): void {
								wp_cache_delete( 'perflocale_settings', 'options' );
								wp_cache_delete( 'alloptions', 'options' );

								$stored = get_option( 'perflocale_settings' );

								if ( is_array( $stored ) && array_key_exists( self::DORMANT_KEY, $stored ) ) {
									unset( $stored[ self::DORMANT_KEY ] );
									update_option( 'perflocale_settings', $stored );
								}

								$settings->reset_cache();
							}
						);
					},
					PHP_INT_MAX
				);
			},
			0
		);
	}

	/**
	 * Detect if a known conflicting multilingual plugin is active.
	 *
	 * @return string|null Display name of the conflicting plugin, or null.
	 */
	private static function detect_conflicting_plugin(): ?string {
		// Primary check: the competitor's version constant.
		foreach ( self::CONFLICTING_PLUGINS as $name => $meta ) {
			if ( defined( $meta['constant'] ) ) {
				return $name;
			}
		}

		// Fallback: active_plugins, for when PerfLocale loads before the competitor.
		$active = (array) get_option( 'active_plugins', [] );

		// Multisite: a competitor may be network-activated; merge that list in.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
			$active         = array_merge( $active, array_keys( $network_active ) );
		}

		foreach ( self::CONFLICTING_PLUGINS as $name => $meta ) {
			foreach ( $meta['files'] as $file ) {
				if ( in_array( $file, $active, true ) ) {
					return $name;
				}
			}
		}

		return null;
	}

	/**
	 * Register the [perflocale_language] shortcode: format="slug|locale|name|native_name|display_name|flag".
	 *
	 * @return void
	 */
	public static function register_language_shortcode(): void {
		add_shortcode(
			'perflocale_language',
			static function ( $atts ): string {
				$atts = shortcode_atts( [ 'format' => 'name' ], (array) $atts, 'perflocale_language' );
				$h    = perflocale();

				return match ( $atts['format'] ) {
					'slug' => esc_html( $h->slug() ),
					'locale' => esc_html( $h->locale() ),
					'name' => esc_html( $h->name() ),
					'native_name', 'native' => esc_html( $h->native_name() ),
					'display_name', 'display' => esc_html( $h->display_name() ),
					'flag' => esc_html( $h->flag() ),
					default => esc_html( $h->name() ),
				};
			}
		);
	}
}
