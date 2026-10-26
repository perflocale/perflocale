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
	private const LOCK_CLEANUP_HOOK     = 'perflocale_lock_cleanup';

	/**
	 * Post meta on a WooCommerce page translation: md5 of the source title MT last answered
	 * for, so an unchanged title is not sent again.
	 */
	private const WC_PAGE_MT_ATTEMPT_META = '_perflocale_wc_page_mt_attempt';

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

		// Cache invalidator: hooks into save_post, etc. to flush stale entries.
		$plugin->register(
			'cache_invalidator',
			fn( Plugin $p ) => new Cache\CacheInvalidator( $p->get( 'cache' ) ),
			true
		);

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

		// Add language labels to page dropdowns (Reading Settings, etc.).
		add_filter( 'wp_dropdown_pages', [ self::class, 'add_language_labels_to_dropdown' ] );

		// Clear the `_perflocale_meta_copy_errors` warning on real saves. Always loaded, not in
		// admin-only MetaBox, so REST and CLI saves clear it too; on save_post, not in a GET
		// notice hook, so the state change sits behind core's save nonce and capability checks.
		add_action(
			'save_post',
			static function ( int $post_id, ?\WP_Post $post = null ): void {
				// Core passes null when the row was deleted after the write; see
				// auto_assign_default_language().
				if ( ! $post instanceof \WP_Post ) {
					return;
				}

				// Skip autosaves and revisions so a heartbeat autosave does not dismiss the
				// warning. Autosaves are revisions, so wp_is_post_revision() covers them.
				if (
					( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
					|| wp_is_post_revision( $post_id )
				) {
					return;
				}

				// Most posts never had a copy-errors warning.
				$errors = get_post_meta( $post_id, '_perflocale_meta_copy_errors', true );

				if ( empty( $errors ) || ! is_array( $errors ) ) {
					return;
				}

				// Self-clearing: a failed step's entry is dropped only when its content looks
				// populated, so saving without filling the fields keeps the warning.
				$remaining = [];
				foreach ( $errors as $err ) {
					$step = is_array( $err ) && isset( $err['step'] ) ? (string) $err['step'] : '';
					switch ( $step ) {
						case 'featured_image':
							if ( has_post_thumbnail( $post_id ) ) {
								continue 2; // drop this error entry
							}
							break;
						case 'taxonomy_terms':
							// Terms beyond the taxonomy default count: core gives every new post
							// "Uncategorized", which does not mean the copy worked.
							$has_term = false;
							foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
								$terms = wp_get_object_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
								if ( is_wp_error( $terms ) || count( $terms ) === 0 ) {
									continue;
								}
								$default_term = (int) get_option( 'default_' . $tax, 0 );
								$non_default  = array_filter(
									array_map( 'intval', $terms ),
									static fn( int $tid ): bool => $tid !== $default_term
								);
								if ( count( $non_default ) > 0 ) {
									$has_term = true;
									break;
								}
							}
							if ( $has_term ) {
								continue 2;
							}
							break;
						case 'post_meta':
							// Proxy: any non-internal meta key counts as addressed (the failed key is
							// not stored; _thumbnail_id belongs to featured_image).
							$meta_keys     = get_post_meta( $post_id );
							$has_user_meta = false;
							if ( is_array( $meta_keys ) ) {
								foreach ( array_keys( $meta_keys ) as $key ) {
									if ( ! is_string( $key ) ) {
										continue;
									}
									if ( $key === '_perflocale_meta_copy_errors' ) {
										continue;
									}
									if ( str_starts_with( $key, '_' ) ) {
										continue;
									}
									$has_user_meta = true;
									break;
								}
							}
							if ( $has_user_meta ) {
								continue 2;
							}
							break;
						default:
							// Unknown step: keep it rather than drop a signal.
							break;
					}
					$remaining[] = $err;
				}

				if ( count( $remaining ) === count( $errors ) ) {
					// Nothing addressed: keep the warning.
					return;
				}

				if ( empty( $remaining ) ) {
					delete_post_meta( $post_id, '_perflocale_meta_copy_errors' );
					return;
				}

				update_post_meta( $post_id, '_perflocale_meta_copy_errors', wp_slash( $remaining ) );
			},
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

			require_once $addon_path;
			$class_name = 'PerfLocale' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $theme_slug ) ) );

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$theme_addon = new $class_name();

			if ( ! $theme_addon instanceof \PerfLocale\Addon\AddonInterface ) {
				continue;
			}

			$addon_id = $theme_addon->get_id();

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
					static function () use ( $plugin, $theme_addon, $addon_id, $default_on ): void {
						if ( $plugin->has( 'addon_registry' ) ) {
							$registry = $plugin->addon_registry();
							$registry->register( $theme_addon );
							$registry->mark_filter_off( $addon_id, $default_on );
						}
					},
					15
				);
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

		// Admin bar language switcher - works on both frontend and admin.
		$plugin->register(
			'admin_bar_switcher',
			fn( Plugin $p ) => new Admin\AdminBarSwitcher(
				$p->get( 'router' ),
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

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
			self::register_admin_services( $plugin );
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
				true
			);
		}

		// AJAX handlers needed while wp_doing_ajax(): AdminController and TermMetaBox do not load
		// during AJAX.
		if ( wp_doing_ajax() ) {
			// Quick Edit language save (`inline-save` AJAX): PostListColumns' save_post handler
			// must be wired during AJAX, so register the service here too.
			$plugin->register(
				'post_list_columns',
				fn( Plugin $p ) => new Admin\PostListColumns(
					$p->get( 'settings' ),
					$p->get( 'cache' ),
				),
				true
			);

			// The same for terms: `inline-save-tax` fires edited_term, and TermListColumns' hooks
			// otherwise register only outside AJAX.
			$plugin->register(
				'term_list_columns',
				fn( Plugin $p ) => new Admin\TermListColumns(
					$p->get( 'settings' ),
					$p->get( 'cache' ),
				),
				true
			);

			// Term language save for AJAX term creation (add-tag); TermMetaBox does not load here.
			add_action(
				'admin_init',
				static function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
					if ( empty( $_POST['perflocale_term_nonce'] ) || empty( $_POST['perflocale_term_language'] ) ) {
						return;
					}

					$plugin   = Plugin::get_instance();
					$settings = $plugin->get( 'settings' );

					foreach ( $settings->get_translatable_taxonomies() as $taxonomy ) {
						add_action(
							"created_{$taxonomy}",
							static function ( int $term_id ) use ( $plugin ): void {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
								if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_term_nonce'] ?? '' ), 'perflocale_term_lang' ) ) {
									return;
								}

								// The new term's own edit right (follows the taxonomy's caps).
								if ( ! current_user_can( 'edit_term', $term_id ) ) {
									return;
								}

						// phpcs:ignore WordPress.Security.NonceVerification.Missing
								$language = sanitize_key( $_POST['perflocale_term_language'] ?? '' );

								if ( $language !== '' ) {
									$manager = new Translation\TermTranslationManager( $plugin->get( 'cache' ) );

									if ( ! $manager->set_term_language( $term_id, $language ) ) {
										// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point.
										error_log( sprintf( 'PerfLocale: language save failed for new term %d (language "%s").', $term_id, $language ) );
									}
								}
							}
						);
					}
				}
			);

			// The import form's chunked upload (Settings > Export & Import). The class loads only
			// when the action runs.
			add_action( 'wp_ajax_perflocale_import_upload', [ Admin\ImportUpload::class, 'handle_ajax' ] );

			add_action(
				'wp_ajax_perflocale_save_hidden_columns',
				static function (): void {
					// Default $die=true: an invalid nonce ends the request before any input is read.
					check_ajax_referer( 'perflocale_hidden_columns', '_wpnonce' );

					// Per-user UI state: `read` is the minimum authenticated capability.
					if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
						wp_send_json_error( 'Insufficient permissions.', 403 );
					}

					// Cap the payload so user meta cannot be flooded. sanitize_text_field() keeps the
					// JSON punctuation; per-element sanitize_key() runs after decode.
					$raw = isset( $_POST['hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['hidden'] ) ) : '[]';

					if ( strlen( $raw ) > 4096 ) {
						wp_send_json_error( 'Payload too large.' );
					}

					$hidden = json_decode( $raw, true );

					if ( ! is_array( $hidden ) ) {
						$hidden = [];
					}

					$hidden = array_map( 'sanitize_key', $hidden );

					update_user_meta( get_current_user_id(), 'perflocale_strings_hidden_langs', $hidden );

					wp_send_json_success();
				}
			);

			// Generic hidden-columns saver; `meta_key` is allow-listed so no arbitrary user meta
			// key can be written.
			add_action(
				'wp_ajax_perflocale_save_hidden_columns_generic',
				static function (): void {
					$meta_key_raw = isset( $_POST['meta_key'] ) ? sanitize_key( wp_unslash( $_POST['meta_key'] ) ) : '';

					$allowed = [
						'perflocale_translations_hidden_langs' => 'perflocale_translations_hidden_cols',
					];

					if ( ! isset( $allowed[ $meta_key_raw ] ) ) {
						wp_send_json_error( 'Invalid meta key.' );
					}

					// Default $die=true: an invalid nonce ends the request before any input is read.
					check_ajax_referer( $allowed[ $meta_key_raw ], '_wpnonce' );

					if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
						wp_send_json_error( 'Insufficient permissions.', 403 );
					}

					$raw = isset( $_POST['hidden'] ) ? (string) wp_unslash( $_POST['hidden'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field applied below on the serialized-JSON string before decode.

					if ( strlen( $raw ) > 4096 ) {
						wp_send_json_error( 'Payload too large.' );
					}

					$hidden = json_decode( sanitize_text_field( $raw ), true );

					if ( ! is_array( $hidden ) ) {
						$hidden = [];
					}

					$hidden = array_map( 'sanitize_key', $hidden );

					update_user_meta( get_current_user_id(), $meta_key_raw, $hidden );

					wp_send_json_success();
				}
			);
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
			self::register_cli_services( $plugin );
		}

		// Exchange rate sync AJAX: registered here so it works even when the WooCommerce add-on
		// does not boot; the handler checks WooCommerce itself.
		if ( wp_doing_ajax() ) {
			add_action(
				'wp_ajax_perflocale_sync_exchange_rates',
				static function (): void {
					$p        = Plugin::get_instance();
					$settings = $p->get( 'settings' );
					$sync     = new WooCommerce\ExchangeRateSync( $settings );
					$sync->ajax_sync();
				}
			);

			add_action(
				'wp_ajax_perflocale_create_wc_pages',
				static function (): void {
					self::ajax_create_wc_page_translations();
				}
			);

			add_action(
				'wp_ajax_perflocale_create_taxonomy_translations',
				static function (): void {
					self::ajax_create_taxonomy_translations();
				}
			);

			add_action(
				'wp_ajax_perflocale_assign_post_languages',
				static function (): void {
					self::ajax_assign_post_languages();
				}
			);

			add_action(
				'wp_ajax_perflocale_generate_missing_translations',
				static function (): void {
					self::ajax_generate_missing_translations();
				}
			);
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
			static function ( string $file ): void {
				if ( $file === '' ) {
					return;
				}

				// An unimported chunked upload frees its quota slot, whether or not its file remains.
				Admin\ImportUpload::release_path( $file );

				// Only files inside `uploads/perflocale/temp/`.
				$temp_dir = realpath( Helper::uploads_temp_dir() );
				$real     = realpath( $file );

				if ( $temp_dir === false || $real === false ) {
					return;
				}

				if ( ! str_starts_with( $real, rtrim( $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
					return;
				}

				wp_delete_file( $real );
			}
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
			static function ( $blog_id = 0 ): void {
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						MachineTranslation\AbstractProvider::gc_old_usage_counters();
					}
				);
			},
			10,
			1
		);

		// Daily background-jobs GC, scheduled per blog: the blog id is the event argument and
		// run_recurring_for_blog() switches to it.
		add_action(
			'perflocale_jobs_gc',
			static function ( $blog_id = 0 ): void {
				Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'gc' ] );

				// Also sweep empty translation_groups rows (orphans, and groups unlink_by_object_id()
				// kept when it could not count the links left), capped per tick.
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						if ( class_exists( '\\PerfLocale\\Database\\Repository\\TranslationGroupRepository' )
						&& \PerfLocale\Database\Schema::tables_exist()
						) {
							\PerfLocale\Database\Repository\TranslationGroupRepository::gc_empty_groups();
						}
					}
				);

				// Safety-net sweep for abandoned temp/export files (7+ days), for lost cleanup events.
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						if ( method_exists( '\\PerfLocale\\Helper', 'gc_stale_upload_files' ) ) {
							\PerfLocale\Helper::gc_stale_upload_files();
						}
					}
				);

				// Expired perflocale_* transients: core's sweeps miss them when nothing reads them or
				// WP-Cron is off. One bounded DELETE joining value and timeout rows.
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						global $wpdb;
						$now = time();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->query(
							$wpdb->prepare(
								"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
					 WHERE a.option_name LIKE %s
					   AND a.option_name NOT LIKE %s
					   AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
					   AND b.option_value < %s",
								$wpdb->esc_like( '_transient_perflocale_' ) . '%',
								$wpdb->esc_like( '_transient_timeout_' ) . '%',
								(string) $now
							)
						);
					}
				);

				// Strings mark-and-sweep: delete rows unseen past the retention window (filter
				// perflocale/strings/stale_retention_days), cascading to string_translations.
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						if ( ! class_exists( '\\PerfLocale\\Database\\Repository\\StringRepository' )
							|| ! \PerfLocale\Database\Schema::tables_exist() ) {
							return;
						}
						$cache = Plugin::get_instance()->has( 'cache' )
							? Plugin::get_instance()->get( 'cache' )
							: null;
						if ( $cache === null ) {
							return;
						}
						( new \PerfLocale\Database\Repository\StringRepository( $cache ) )->gc_stale_strings();
					}
				);

				// Orphan sweep for string_translations whose parent string was deleted elsewhere.
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						if ( ! class_exists( '\\PerfLocale\\Database\\Repository\\StringTranslationRepository' )
							|| ! \PerfLocale\Database\Schema::tables_exist() ) {
							return;
						}
						$cache = Plugin::get_instance()->has( 'cache' )
							? Plugin::get_instance()->get( 'cache' )
							: null;
						if ( $cache === null ) {
							return;
						}
						( new \PerfLocale\Database\Repository\StringTranslationRepository( $cache ) )->gc_orphans();
					}
				);
			}
		);

		// Hourly stuck-job watchdog - same multisite consideration as GC.
		add_action(
			'perflocale_jobs_watchdog',
			static function ( $blog_id = 0 ): void {
				Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'watchdog' ] );
			}
		);

		// Schedule both recurring events under the current engine: admin_init only (the drift
		// probe queries the schedule store), which runs after AS's init@1 hook.
		if ( is_admin() ) {
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

			if ( is_admin() ) {
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

				if ( is_admin() ) {
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
			static function ( $proceed, Background\AbstractJob $job, array $args ) {
				if ( $proceed !== true ) {
					return $proceed;
				}

				$type = $job->get_type();
				if ( ! in_array( $type, [ 'bulk_translate', 'bulk_string_translate' ], true ) ) {
					return $proceed;
				}

				$settings = Plugin::get_instance()->get( 'settings' );
				if ( ! (bool) $settings->get( 'mt_enforce_cap_on_bulk', true ) ) {
					return $proceed;
				}

				$estimator = new MachineTranslation\CostEstimator( $settings );

				if ( $type === 'bulk_translate' ) {
					$estimate = $estimator->estimate_posts(
						(array) ( $args['source_ids'] ?? [] ),
						(array) ( $args['target_lang_ids'] ?? [] ),
						! empty( $args['include_meta'] )
					);
				} else {
					// With Overwrite on (skip_existing false), translated rows are sent again and counted.
					$string_job = $job instanceof Background\Jobs\BulkStringTranslateJob
						? $job
						: new Background\Jobs\BulkStringTranslateJob();
					$estimate   = $estimator->estimate_strings(
						$string_job->resolve_ids_for_estimate( $args ),
						(array) ( $args['target_lang_ids'] ?? [] ),
						! (bool) ( $args['skip_existing'] ?? true )
					);
				}

				// Deny only when there is work to send: an all-translated selection (chars 0) is a no-op.
				if ( ! empty( $estimate['would_exceed'] ) && (int) $estimate['chars'] > 0 ) {
					return sprintf(
						/* translators: 1: estimated characters, 2: remaining monthly characters, 3: monthly limit. */
						__( 'This translation needs about %1$s characters but only %2$s of the monthly limit (%3$s) remain. Raise the limit under Settings → Addons → Machine Translation, or narrow the selection.', 'perflocale' ),
						number_format_i18n( (int) $estimate['chars'] ),
						number_format_i18n( (int) $estimate['monthly_remaining'] ),
						number_format_i18n( (int) $estimate['monthly_limit'] )
					);
				}

				return $proceed;
			},
			10,
			3
		);

		// A bulk MT dispatch counts as one request of the user to the hourly limiter. Priority
		// 1000: after the budget gate and other vetoes, so a refused dispatch is not counted. A
		// site-wide chain's next chunk (after_id > 0) was already counted.
		add_filter(
			'perflocale/jobs/should_dispatch',
			static function ( $proceed, Background\AbstractJob $job, array $args ) {
				if ( $proceed !== true ) {
					return $proceed;
				}

				$type = $job->get_type();

				if ( ! in_array( $type, [ 'bulk_translate', 'bulk_string_translate', 'site_translate' ], true ) ) {
					return $proceed;
				}

				if ( $type === 'site_translate' && (int) ( $args['after_id'] ?? 0 ) > 0 ) {
					return $proceed;
				}

				$limited = Translation\MtRateLimiter::admit( get_current_user_id() );

				return $limited instanceof \WP_Error ? $limited->get_error_message() : $proceed;
			},
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
			$registrar = new AbilitiesRegistrar( $plugin );
			add_action( 'wp_abilities_api_categories_init', [ $registrar, 'register_category' ] );
			add_action( 'wp_abilities_api_init', [ $registrar, 'register_abilities' ] );
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
	 * Option holding the last active engine, to detect Action Scheduler appearing or disappearing,
	 * which `maybe_remigrate_engine()` cannot see. Autoloaded.
	 *
	 * @var string
	 */
	private const ACTIVE_ENGINE_OPTION = 'perflocale_active_engine';

	/**
	 * Timestamp of the last full recurring-schedule check (autoloaded; written at most every 6 hours).
	 */
	private const SCHEDULES_VERIFIED_OPTION = 'perflocale_schedules_verified_at';

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
	 * Settings saves (update_option_perflocale_settings:20), WP-CLI, network activation and
	 * wp_initialize_site call the unthrottled method.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules_throttled(): void {
		// The full check costs several ms under AS (one table query per is_scheduled()), so it
		// runs at most every 6 hours; the cheap drift check stays per request.
		$current_engine = Background\JobRunnerFactory::pick()->get_engine_name();
		$stored_engine  = get_option( self::ACTIVE_ENGINE_OPTION, $current_engine );
		$drifted        = ! is_string( $stored_engine ) || $stored_engine !== $current_engine;

		if ( ! $drifted ) {
			$last = get_option( self::SCHEDULES_VERIFIED_OPTION, 0 );

			if ( is_numeric( $last ) && time() - (int) $last < 6 * HOUR_IN_SECONDS ) {
				return;
			}
		}

		self::ensure_recurring_schedules();
		update_option( self::SCHEDULES_VERIFIED_OPTION, time(), true );
	}

	/**
	 * Ensure the recurring background events are scheduled under the active engine.
	 *
	 * On engine drift (Action Scheduler gone or back) it first unschedules them from both
	 * engines, or stranded AS actions would fire alongside the new WP-Cron ones. Unthrottled.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules(): void {
		$current_engine = Background\JobRunnerFactory::pick()->get_engine_name();
		$stored_engine  = (string) get_option( self::ACTIVE_ENGINE_OPTION, $current_engine );

		if ( $stored_engine !== $current_engine ) {
			// Drift (also catches a `background_engine` change written without our hook): wipe
			// schedules in both engines so old ones do not fire alongside the new.
			$blog_arg_for_drift = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];
			Background\BackgroundEvents::unschedule_recurring( self::LOCK_CLEANUP_HOOK );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc', $blog_arg_for_drift );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog', $blog_arg_for_drift );
			// Also the legacy no-arg form, so it does not fire alongside the per-blog one.
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc' );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog' );
			// MT usage GC is engine-bound too: clear the per-blog and no-arg forms.
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc', $blog_arg_for_drift );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc' );
			// A pending one-shot resume under the old engine would be stranded: clear it.
			Background\BackgroundEvents::unschedule_all( Background\Resumer::HOOK );

			update_option( self::ACTIVE_ENGINE_OPTION, $current_engine, true );
		}

		if ( ! Background\BackgroundEvents::is_scheduled( self::LOCK_CLEANUP_HOOK ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				self::LOCK_CLEANUP_HOOK,
				time() + HOUR_IN_SECONDS,
				DAY_IN_SECONDS,
				'daily'
			);
		}

		// The blog id as args: the handler switches into that blog before it reads site state.
		// Action Scheduler's tables are per blog too.
		$blog_arg = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];

		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_jobs_gc', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_jobs_gc',
				time() + HOUR_IN_SECONDS,
				DAY_IN_SECONDS,
				'daily',
				$blog_arg
			);
		}

		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_jobs_watchdog', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_jobs_watchdog',
				time() + ( 10 * MINUTE_IN_SECONDS ),
				HOUR_IN_SECONDS,
				'hourly',
				$blog_arg
			);
		}

		// Weekly drop of perflocale_mt_usage_YYYY_MM options older than 13 months.
		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_mt_usage_gc', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_mt_usage_gc',
				time() + ( 30 * MINUTE_IN_SECONDS ),
				WEEK_IN_SECONDS,
				'weekly',
				$blog_arg
			);
		}

		// First call after install or after the option was deleted: seed the baseline.
		if ( get_option( self::ACTIVE_ENGINE_OPTION ) === false ) {
			update_option( self::ACTIVE_ENGINE_OPTION, $current_engine, true );
		}
	}

	/**
	 * On a `background_engine` change, move every recurring event to the new engine.
	 *
	 * Also reschedules the exchange-rate sync via `ExchangeRateSync::maybe_reschedule()`.
	 *
	 * @param mixed $old_value Previous option payload (array or scalar).
	 * @param mixed $new_value New option payload (array or scalar).
	 * @return void
	 */
	public static function maybe_remigrate_engine( $old_value, $new_value ): void {
		$old_engine = is_array( $old_value ) && isset( $old_value['background_engine'] )
			? (string) $old_value['background_engine']
			: 'auto';
		$new_engine = is_array( $new_value ) && isset( $new_value['background_engine'] )
			? (string) $new_value['background_engine']
			: 'auto';

		if ( $old_engine === $new_engine ) {
			return;
		}

		// Forget the memoized runner and reset Settings' memo first: pick() may already have run in
		// this request, and either would re-stamp the events under the old engine.
		Background\JobRunnerFactory::reset_memo();

		$plugin = Plugin::get_instance();
		if ( $plugin->has( 'settings' ) ) {
			try {
				$settings = $plugin->get( 'settings' );
				if ( method_exists( $settings, 'reset_cache' ) ) {
					$settings->reset_cache();
				}
			} catch ( \Throwable $e ) {
				// Non-fatal: at worst the schedule lands under the old engine until the next request.
				unset( $e );
			}
		}

		// Cancel the recurring events on both back-ends and clear the active-engine option; the
		// re-enqueue below reads the saved setting (this hook runs after the write).
		delete_option( self::ACTIVE_ENGINE_OPTION );
		$blog_arg_for_remig = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];
		Background\BackgroundEvents::unschedule_recurring( self::LOCK_CLEANUP_HOOK );
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc', $blog_arg_for_remig );
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog', $blog_arg_for_remig );
		// MT usage GC is engine-bound too (re-created below).
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc', $blog_arg_for_remig );
		self::ensure_recurring_schedules();

		// Exchange-rate sync reschedules through the same helper, so it follows the new engine.
		if ( $plugin->has( 'exchange_rate_sync' ) ) {
			try {
				$ers = $plugin->get( 'exchange_rate_sync' );
				if ( method_exists( $ers, 'maybe_reschedule' ) ) {
					$ers->maybe_reschedule();
				}
			} catch ( \Throwable $e ) {
				// Best-effort: the next init reconciles state.
				unset( $e );
			}
		}
	}

	/**
	 * Register admin-only services.
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	private static function register_admin_services( Plugin $plugin ): void {
		$plugin->register(
			'admin_controller',
			fn( Plugin $p ) => new Admin\AdminController( $p->get( 'settings' ) ),
			true
		);

		$plugin->register(
			'admin_assets',
			fn() => new Admin\Assets(),
			true
		);

		// Dashboard widget: eager so its wp_dashboard_setup hook registers.
		$plugin->register(
			'dashboard_widget',
			fn( Plugin $p ) => new Admin\DashboardWidget( $p->get( 'settings' ) ),
			true
		);

		// Quarantined add-on notice: eager so its admin_notices hooks register in time.
		$plugin->register(
			'quarantine_notice',
			fn() => new Admin\QuarantineNotice(),
			true
		);

		$plugin->register(
			'meta_box',
			fn( Plugin $p ) => new Admin\MetaBox(
				$p->get( 'router' ),
				$p->get( 'cache' ),
			),
			true
		);

		$plugin->register(
			'post_list_columns',
			fn( Plugin $p ) => new Admin\PostListColumns(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

		$plugin->register(
			'term_meta_box',
			fn( Plugin $p ) => new Admin\TermMetaBox(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

		$plugin->register(
			'term_list_columns',
			fn( Plugin $p ) => new Admin\TermListColumns(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			true
		);

		$plugin->register(
			'editor_sidebar',
			fn() => new Admin\EditorSidebar(),
			true
		);
	}

	/**
	 * Register frontend-only services (never in a non-AJAX admin request).
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	private static function register_frontend_services( Plugin $plugin ): void {
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

		$plugin->register(
			'frontend_assets',
			fn() => new Frontend\Assets(),
			true
		);

		// 301 redirector for renamed language slugs; no-op when the redirect map is empty.
		$plugin->register(
			'slug_redirector',
			fn() => new Frontend\SlugRedirector(),
			true
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
			true
		);

		// View Transitions emitter: opt-in; emit() returns at once when the setting is off.
		$plugin->register(
			'view_transitions',
			fn( Plugin $p ) => new Frontend\ViewTransitionsEmitter(
				$p->get( 'settings' ),
			),
			true
		);

		// Speculation Rules emitter: prerenders translation targets on switcher hover. Opt-in.
		$plugin->register(
			'speculation_rules',
			fn( Plugin $p ) => new Frontend\SpeculationRulesEmitter(
				$p->get( 'settings' ),
				$p->get( 'router' ),
				$p->get( 'url_converter' ),
			),
			true
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
			true
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
	 * Register WP-CLI commands.
	 *
	 * @param Plugin $plugin Service container.
	 * @return void
	 */
	private static function register_cli_services( Plugin $plugin ): void {
		$plugin->register(
			'cli',
			fn( Plugin $p ) => new Cli\PerfLocaleCommand( $p ),
			true
		);

		// Add-on lifecycle subcommands (wp perflocale addon ...), a separate service.
		$plugin->register(
			'cli_addon',
			fn() => new Cli\AddonCommand(),
			true
		);

		// Per-add-on settings subcommands (wp perflocale addon settings ...), their own namespace
		// so WP-CLI groups get/set/list under `settings`.
		$plugin->register(
			'cli_addon_settings',
			fn() => new Cli\AddonSettingsCommand(),
			true
		);
	}

	/**
	 * Register REST API routes on rest_api_init.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		// Prevent double registration if rest_api_init fires multiple times.
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;

		$controllers = [
			new Api\LanguagesController(),
			new Api\TranslationsController(),
			new Api\StringsController(),
			new Api\XliffController(),
			new Api\WebhookController(),
			new Api\JobsController(),
		];

		// MT routes register unconditionally: the handlers check mt_enabled() per request and
		// answer `mt_disabled`, not `rest_no_route`, to an editor that cached the route.
		$controllers[] = new Api\MachineTranslateController();
		$controllers[] = new Api\BlockTranslateController();

		// The public edge-config endpoint exists only when edge integration is enabled.
		if ( Plugin::get_instance()->get( 'settings' )->edge_integration_enabled() ) {
			$controllers[] = new Api\ConfigController();
		}

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}

		// The language of each navigation menu in the Site Editor's edit-context data.
		( new Api\NavigationLanguageField() )->register();
	}

	/**
	 * Hold back a bulk language tool while imported data is missing or an import runs.
	 *
	 * Run before a WPML, Polylang or TranslatePress import, these tools would give that data the
	 * default language. The screen confirms `perflocale_unimported_source` and resends with
	 * `continue_unimported=1`. Call after the handler's nonce and capability checks.
	 *
	 * @return bool True when the handler may run; false after a JSON error was sent.
	 */
	private static function guard_bulk_tool(): bool {
		if ( Migration\MigrationLock::seconds_remaining() > 0 ) {
			wp_send_json_error(
				[
					'code'    => 'perflocale_import_running',
					'message' => __( 'An import is running. Try again when it has finished.', 'perflocale' ),
				]
			);

			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler verified the nonce.
		if ( isset( $_POST['continue_unimported'] ) && '1' === sanitize_key( wp_unslash( $_POST['continue_unimported'] ) ) ) {
			return true;
		}

		$sources    = Migration\MigrationState::unimported_sources();
		$incomplete = $sources === [] ? Migration\MigrationState::incomplete_sources() : [];

		if ( $sources === [] && $incomplete === [] ) {
			return true;
		}

		wp_send_json_error(
			[
				'code'    => 'perflocale_unimported_source',
				'message' => $sources !== []
					? sprintf(
						/* translators: %s: source plugin name(s), e.g. "WPML" or "WPML and Polylang" */
						__( '%s translations have not been imported yet. Running this now gives them the default language.', 'perflocale' ),
						Migration\MigrationState::source_names( $sources )
					)
					: Migration\MigrationState::incomplete_message( $incomplete ),
				'confirm' => __( 'Continue anyway?', 'perflocale' ),
			]
		);

		return false;
	}

	/**
	 * AJAX handler: create Cart, Checkout, My Account and Shop translations in every language.
	 *
	 * Titles are machine-translated when MT is enabled and configured.
	 *
	 * @return void
	 */
	private static function ajax_create_wc_page_translations(): void {
		check_ajax_referer( 'perflocale_create_wc_pages', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ] );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'perflocale' ) ] );
		}

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$languages = $lang_repo->get_active();
		$default   = $lang_repo->get_default();

		if ( ! $default || count( $languages ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		// Collect WooCommerce page IDs.
		$wc_page_keys = [ 'cart', 'checkout', 'myaccount', 'shop' ];
		$page_ids     = [];

		foreach ( $wc_page_keys as $key ) {
			$page_id = wc_get_page_id( $key );

			if ( $page_id > 0 && get_post( $page_id ) ) {
				$page_ids[ $key ] = $page_id;
			}
		}

		if ( empty( $page_ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No WooCommerce pages found.', 'perflocale' ) ] );
		}

		// MT through TranslationService, so the monthly limit and plain-text sanitiser apply and
		// languages travel as slugs (pt-br, not pt).
		$mt_provider = null;
		$mt_service  = null;

		if ( $settings->mt_enabled() ) {
			try {
				$mt_service  = new MachineTranslation\TranslationService( $settings, $cache );
				$mt_provider = $mt_service->get_provider();
			} catch ( \Throwable $e ) {
				// MT not configured: continue without translation.
				$mt_service  = null;
				$mt_provider = null;
			}
		}

		$manager = new Translation\PostTranslationManager( $cache, $settings );
		$created = 0;
		$skipped = 0;
		$details = [];
		// Whether MT produced any title: a configured provider may be skipped or fail.
		$mt_used = false;

		global $wpdb;

		foreach ( $page_ids as $key => $page_id ) {
			// Ensure the source page has a language assigned.
			if ( ! $manager->detect_post_language( $page_id ) && ! $manager->set_post_language( $page_id, $default->slug ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the page-creation batch continues with remaining pages.
				error_log( sprintf( 'PerfLocale: default-language assignment failed for page %d during page-creation batch.', $page_id ) );
			}

			$source_post = get_post( $page_id );

			if ( ! $source_post ) {
				continue;
			}

			// A created translation is published only when its source is and the user may publish.
			$source_type = get_post_type_object( $source_post->post_type );
			$may_publish = 'publish' === $source_post->post_status
				&& $source_type instanceof \WP_Post_Type
				&& is_string( $source_type->cap->publish_posts ?? null )
				&& current_user_can( $source_type->cap->publish_posts );

			foreach ( $languages as $lang ) {
				if ( $lang->slug === $default->slug ) {
					continue;
				}

				$label = ucfirst( $key ) . ' → ' . $lang->slug;

				// MT-tagged when a provider was resolved above; otherwise Manual.
				$link_source = $mt_provider !== null
					? \PerfLocale\Enum\SourceType::MachineTranslation
					: \PerfLocale\Enum\SourceType::Manual;
				$inserted    = false;
				$new_id      = $manager->create_translation( $page_id, $lang->slug, true, $link_source, $inserted );

				if ( ! $new_id || $new_id === $page_id ) {
					$details[] = $label . ': failed';
					continue;
				}

				$new_post = get_post( $new_id );

				if ( ! $new_post ) {
					$details[] = $label . ': post missing (ID ' . $new_id . ')';
					continue;
				}

				// Title: local translations first, then MT; defaults to the source title so the UPDATE
				// below never writes an empty string.
				$original_title   = $source_post->post_title;
				$translated_title = $original_title;

				// Resolve a title (maybe a paid MT call) only when the row still needs one:
				// create_translation() is idempotent, so a re-run reaches finished pages too.
				$needs_title = (
					$new_post->post_status !== 'publish'
					|| $new_post->post_name !== $source_post->post_name
					|| $new_post->post_title === $original_title
					|| trim( $new_post->post_title ) === ''
				);

				$mt_translated_this = false;

				if ( $needs_title ) {
					$local_translation = self::find_local_translation( $original_title, $lang->locale );
					$title_hash        = md5( $original_title );

					if ( $local_translation !== '' ) {
						$translated_title = $local_translation;
					} elseif ( $mt_service !== null && get_post_meta( $new_id, self::WC_PAGE_MT_ATTEMPT_META, true ) !== $title_hash ) {
						// A title the provider already answered for is not sent again (an unchanged
						// "Checkout" would be billed on every run).
						try {
							$mt_titles = $mt_service->translate_batch_texts( [ $original_title ], (string) $default->slug, (string) $lang->slug, '', false, 'text' );
							$mt_title  = sanitize_text_field( (string) ( $mt_titles[0] ?? '' ) );

							if ( $mt_title !== '' ) {
								update_post_meta( $new_id, self::WC_PAGE_MT_ATTEMPT_META, $title_hash );
								$translated_title   = $mt_title;
								$mt_translated_this = true;
							}
						} catch ( \Throwable $e ) {
							// Keep the original title on failure, including a monthly-limit refusal.
							$translated_title = $original_title;
						}
					}
				} else {
					// An existing translated title wins on re-runs.
					$translated_title = $new_post->post_title;
				}

				// Matching slug and translated title; a translation that existed before keeps its status.
				$target_status = $inserted && $may_publish ? 'publish' : $new_post->post_status;
				$needs_update  = (
					$new_post->post_status !== $target_status
					|| $new_post->post_name !== $source_post->post_name
					|| $new_post->post_title !== $translated_title
				);

				// The MT note reflects titles actually written, not discarded provider answers.
				if ( $needs_update && $mt_translated_this ) {
					$mt_used = true;
				}

				if ( $needs_update ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->posts,
						[
							'post_title'  => $translated_title,
							'post_name'   => $source_post->post_name,
							'post_status' => $target_status,
						],
						[ 'ID' => $new_id ],
						[ '%s', '%s', '%s' ],
						[ '%d' ]
					);
					clean_post_cache( $new_id );
					++$created;
					$details[] = $label . ': created (ID ' . $new_id . ')';
				} else {
					++$skipped;
					$details[] = $label . ': exists (ID ' . $new_id . ')';
				}
			}
		}

		if ( $created > 0 ) {
			update_option( 'perflocale_flush_rules', '1', false );
		}

		$message = sprintf(
			/* translators: %1$d: number of pages created, %2$d: number already existing */
			__( 'Done - %1$d page(s) created, %2$d already existed.', 'perflocale' ),
			$created,
			$skipped
		);

		if ( $mt_used ) {
			$message .= ' ' . __( 'Titles were machine-translated.', 'perflocale' );
		}

		wp_send_json_success(
			[
				'message' => $message,
				'created' => $created,
				'skipped' => $skipped,
				'details' => $details,
			]
		);
	}

	/**
	 * Release the debug query log and report whether a bulk admin run nears PHP's memory limit.
	 *
	 * Under SAVEQUERIES `$wpdb->queries` keeps SQL plus a backtrace per query, enough to fatal a
	 * bulk run, so it is dropped (`num_queries` stays). The caller yields its cursor past the
	 * ceiling. See docs-ai/11-code-rationale.md, "Bootstrap: bulk memory pressure".
	 *
	 * @param bool $release_query_log Whether to drop `$wpdb->queries` first.
	 * @return bool True when the caller should stop and hand back its cursor.
	 */
	private static function bulk_memory_pressure( bool $release_query_log = true ): bool {
		if ( $release_query_log && defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			global $wpdb;

			if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) && count( $wpdb->queries ) > 200 ) {
				$wpdb->queries = [];
			}
		}

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		// -1 / 0 means no limit (typical under WP-CLI): nothing to yield for.
		if ( $limit <= 0 ) {
			return false;
		}

		/**
		 * Fraction of PHP's memory limit at which a bulk admin run stops and
		 * hands its cursor back instead of risking a fatal.
		 *
		 * @hook perflocale/admin/bulk_memory_ceiling
		 *
		 * @param float $fraction Default 0.8.
		 */
		$ceiling = (float) apply_filters( 'perflocale/admin/bulk_memory_ceiling', 0.8 );
		$ceiling = min( 0.95, max( 0.5, $ceiling ) );

		return memory_get_usage( true ) > (int) ( $limit * $ceiling );
	}

	/**
	 * AJAX handler: create translations for every term of the translatable taxonomies.
	 *
	 * Uses local WordPress translations first, then MT if available. Resumable: the client sends
	 * back the cursor.
	 *
	 * @return void
	 */
	private static function ajax_create_taxonomy_translations(): void {
		// Verify the caller before any side effect.
		check_ajax_referer( 'perflocale_create_taxonomy_translations', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ] );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		// Caller verified: lift the time limit (filterable; default 0) and keep running on abort.
		// Inside this handler only, never on init.
		if ( function_exists( 'set_time_limit' ) ) {
			/**
			 * Time limit for the WC-pages / taxonomy-translation bulk admin handlers.
			 *
			 * @hook  perflocale/admin/bulk_time_limit
			 * @since 1.0.0
			 * @param int $seconds Default 0 (no limit). Set to a positive int (seconds) to cap.
			 * @return int
			 */
			$bulk_time_limit = (int) apply_filters( 'perflocale/admin/bulk_time_limit', 0 );
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
			set_time_limit( $bulk_time_limit );
		}
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$languages = $lang_repo->get_active();
		$default   = $lang_repo->get_default();

		// Resumable cursor, not a re-scan: one request for every term would hit the gateway
		// timeout, and restarting each run at the first taxonomy would never finish.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$tax_index = isset( $_POST['tax_index'] ) ? max( 0, (int) $_POST['tax_index'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$term_offset = isset( $_POST['term_offset'] ) ? max( 0, (int) $_POST['term_offset'] ) : 0;

		// Concurrency guard: two runs would both create the same missing term (a WP term, which
		// the unique link key cannot stop). acquire()/release(), not Lock::with(): every exit is
		// wp_send_json_*(), whose die() skips `finally`. Release on each exit path.
		$lock_name = 'create_term_translations';

		if ( ! Concurrency\Lock::acquire( $lock_name, 120 ) ) {
			// A held lock may belong to a run that died (release() works only in its process):
			// say how long until it clears.
			$held_for = Concurrency\Lock::seconds_remaining( $lock_name );

			wp_send_json_error(
				[
					'message' => $held_for > 0
						? sprintf(
							/* translators: %d: seconds remaining. */
							__( 'Another translation run is in progress, or a previous one ended unexpectedly. This clears automatically in %d seconds - then try again.', 'perflocale' ),
							$held_for
						)
						: __( 'Another translation run is already in progress. Wait for it to finish, then try again.', 'perflocale' ),
				]
			);
		}

		$started_at = microtime( true );
		/** @hook perflocale/create_term_translations/budget_seconds Wall-clock budget for one run. */
		$budget_seconds = (float) apply_filters( 'perflocale/create_term_translations/budget_seconds', 15.0 );
		/** @hook perflocale/create_term_translations/batch_size Terms fetched per run. */
		$term_batch = max( 1, (int) apply_filters( 'perflocale/create_term_translations/batch_size', 200 ) );
		$hit_budget  = false;
		$more        = false;
		$next_index  = 0;
		$next_offset = 0;

		if ( ! $default || count( $languages ) < 2 ) {
			// Release first: wp_send_json_*() dies, and the lock would block retries until its TTL.
			Concurrency\Lock::release( $lock_name );
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		// MT through TranslationService, so the monthly limit and plain-text sanitiser apply and
		// languages travel as slugs (pt-br, not pt).
		$mt_provider = null;
		$mt_service  = null;

		if ( $settings->mt_enabled() ) {
			try {
				$mt_service  = new MachineTranslation\TranslationService( $settings, $cache );
				$mt_provider = $mt_service->get_provider();
			} catch ( \Throwable $e ) {
				$mt_service  = null;
				$mt_provider = null;
			}
		}

		// flush_all, not flush_group: a stale L3 transient would rehydrate L2 and disagree with
		// the direct-DB prefetch below, miscounting existing terms as new.
		$cache->flush_all();

		// One bulk term-to-language map query instead of N+1.
		global $wpdb;

		$links_table  = Database\Schema::table( 'translation_links' );
		$groups_table = Database\Schema::table( 'translation_groups' );
		$langs_table  = Database\Schema::table( 'languages' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() are safe.
		$all_term_links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.group_id, l.object_id AS term_id, lang.slug AS lang_slug
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id AND g.type = 'term'
				INNER JOIN %i lang ON l.language_id = lang.id",
				$links_table,
				$groups_table,
				$langs_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// term_lang_map: term_id to language slug; term_group_map: term_id to group_id;
		// group_lang_map: group_id to slug to term_id. The idempotency check uses the last,
		// since create_translation() returns the same int for an existing translation.
		$term_lang_map  = [];
		$term_group_map = [];
		$group_lang_map = [];

		foreach ( $all_term_links as $row ) {
			$tid                                       = (int) $row->term_id;
			$gid                                       = (int) $row->group_id;
			$term_lang_map[ $tid ]                     = $row->lang_slug;
			$term_group_map[ $tid ]                    = $gid;
			$group_lang_map[ $gid ][ $row->lang_slug ] = $tid;
		}

		// Free memory.
		unset( $all_term_links );

		$taxonomies       = $settings->get_translatable_taxonomies();
		$term_manager     = new Translation\TermTranslationManager( $cache );
		$created          = 0;
		$skipped          = 0;
		$taxonomy_details = [];
		$mt_used          = false;

		// Local translations per locale (locale to original to translated), to avoid repeated
		// switch_to_locale() calls.
		$local_cache = [];

		// MT rename jobs (lang slug to taxonomy to new term_id => source name), translated in a
		// batched second pass of 50 per call instead of one blocking call per term.
		$mt_pending = [];

		// Defer term counting until all inserts are done.
		wp_defer_term_counting( true );

		$taxonomies = array_values( $taxonomies );

		foreach ( $taxonomies as $tax_i => $taxonomy ) {
			// Resume: skip taxonomies the previous run finished.
			if ( $tax_i < $tax_index ) {
				continue;
			}

			$tax_obj = get_taxonomy( $taxonomy );

			if ( ! $tax_obj ) {
				continue;
			}

			$tax_label   = $tax_obj->labels->name;
			$tax_created = 0;
			$tax_skipped = 0;
			$in_tax      = 0;

			// Fetch only term IDs and names to minimize memory usage.
			$terms = get_terms(
				[
					'taxonomy'                 => $taxonomy,
					'hide_empty'               => false,
					'orderby'                  => 'parent',
					'order'                    => 'ASC',
					'number'                   => $term_batch,
					'offset'                   => ( $tax_i === $tax_index ) ? $term_offset : 0,
					'perflocale_all_languages' => true,
				]
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$tid = $term->term_id;

				// Use the bulk map instead of per-term DB queries.
				$lang_slug = $term_lang_map[ $tid ] ?? null;

				// Only create translations for default-language terms or unassigned terms.
				if ( $lang_slug !== null && $lang_slug !== $default->slug ) {
					continue;
				}

				// Assign the default language if unset; this creates a group, so re-read its id for
				// the idempotency check below.
				if ( $lang_slug === null ) {
					if ( ! $term_manager->set_term_language( $tid, $default->slug ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the term batch continues with remaining terms.
						error_log( sprintf( 'PerfLocale: default-language assignment failed for term %d during term-translation batch.', $tid ) );
					}
					$term_lang_map[ $tid ] = $default->slug;

					$new_group = $term_manager->get_group_id_for_term( $tid );
					if ( $new_group !== null ) {
						$term_group_map[ $tid ]                         = $new_group;
						$group_lang_map[ $new_group ][ $default->slug ] = $tid;
					}
				}

				$group_id = $term_group_map[ $tid ] ?? null;

				foreach ( $languages as $lang ) {
					if ( $lang->slug === $default->slug ) {
						continue;
					}

					// Idempotency: the group already has this language, so it "existed" (repeated
					// clicks report 0 created).
					if ( $group_id !== null && isset( $group_lang_map[ $group_id ][ $lang->slug ] ) ) {
						++$tax_skipped;
						continue;
					}

					// MT-tagged when a provider was resolved, as on the post path.
					$term_source = $mt_provider !== null
						? \PerfLocale\Enum\SourceType::MachineTranslation
						: \PerfLocale\Enum\SourceType::Manual;
					$inserted    = false;
					$new_id      = $term_manager->create_translation( $tid, $taxonomy, $lang->slug, true, $term_source, $inserted );

					if ( ! $new_id || $new_id === $tid ) {
						++$tax_skipped;
						continue;
					}

					// Safety net: a term create_translation() did not insert, or one already in the
					// start-of-run map, already existed (another request, stale cache, or a source in
					// several groups). Its name stays.
					if ( ! $inserted || isset( $term_lang_map[ $new_id ] ) ) {
						++$tax_skipped;
						continue;
					}

					// Track the new term in our map.
					$term_lang_map[ $new_id ] = $lang->slug;

					// Keep the group map in sync for sibling terms later in this run.
					if ( $group_id === null ) {
						$group_id = $term_manager->get_group_id_for_term( $tid );
						if ( $group_id !== null ) {
							$term_group_map[ $tid ] = $group_id;
						}
					}

					if ( $group_id !== null ) {
						$term_group_map[ $new_id ]                  = $group_id;
						$group_lang_map[ $group_id ][ $lang->slug ] = $new_id;
					}

					// Local translations first (no network): core's .mo files carry curated names such
					// as "Uncategorized", which MT must not replace.
					if ( ! isset( $local_cache[ $lang->locale ][ $term->name ] ) ) {
						$local_cache[ $lang->locale ][ $term->name ] = self::find_local_translation( $term->name, $lang->locale );
					}

					$translated_name = $local_cache[ $lang->locale ][ $term->name ];

					if ( $translated_name !== '' ) {
						$name_result = wp_update_term( $new_id, $taxonomy, [ 'name' => wp_slash( $translated_name ) ] );
						if ( is_wp_error( $name_result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; term keeps its source name when localization fails.
							error_log( sprintf( 'PerfLocale migration: wp_update_term name localization failed for term %d: %s', (int) $new_id, $name_result->get_error_message() ) );
						}
					} elseif ( $mt_service !== null ) {
						// Deferred to the batched MT pass (see $mt_pending).
						$mt_pending[ $lang->slug ][ $taxonomy ][ (int) $new_id ] = $term->name;
					}

					++$tax_created;
				}

				++$in_tax;

				// The cursor passes a term only after every language was attempted for it, or a
				// resumed run would skip languages. Time or memory (bulk_memory_pressure()) both
				// stop by handing the cursor back.
				if ( ( microtime( true ) - $started_at ) >= $budget_seconds || self::bulk_memory_pressure() ) {
					$hit_budget = true;
					break;
				}
			}

			$base_offset = ( $tax_i === $tax_index ) ? $term_offset : 0;
			$page_count  = is_array( $terms ) ? count( $terms ) : 0;

			if ( $hit_budget ) {
				$more        = true;
				$next_index  = $tax_i;
				$next_offset = $base_offset + $in_tax;
			} elseif ( $page_count >= $term_batch ) {
				// A full page came back, so this taxonomy probably has more.
				$more        = true;
				$next_index  = $tax_i;
				$next_offset = $base_offset + $page_count;
			} else {
				// Taxonomy exhausted: the next run starts at the next one.
				$next_index  = $tax_i + 1;
				$next_offset = 0;
			}

			// Free term objects after each taxonomy.
			unset( $terms );

			$created += $tax_created;
			$skipped += $tax_skipped;

			$taxonomy_details[] = [
				'taxonomy' => $tax_label,
				'created'  => $tax_created,
				'skipped'  => $tax_skipped,
			];
	
			if ( $more ) {
				break;
			}
		}

		// Flush the deferred term counts.
		wp_defer_term_counting( false );

		// Second pass: batched MT rename, one call per (language, taxonomy, 50-term chunk).
		if ( $mt_service !== null && ! empty( $mt_pending ) ) {
			foreach ( $mt_pending as $lang_slug => $by_taxonomy ) {
				foreach ( $by_taxonomy as $taxonomy => $pending_terms ) {
					$source_names = array_values( array_unique( array_map( 'strval', $pending_terms ) ) );
					$name_map     = [];

					foreach ( array_chunk( $source_names, 50 ) as $chunk ) {
						// Each chunk is checked against the monthly limit; a refused one keeps its source names.
						try {
							$translated_chunk = $mt_service->translate_batch_texts(
								$chunk,
								(string) $default->slug,
								(string) $lang_slug,
								'',
								false,
								'text'
							);
						} catch ( \Throwable $e ) {
							continue;
						}

						$n = min( count( $chunk ), count( $translated_chunk ) );

						for ( $i = 0; $i < $n; $i++ ) {
							$translated = trim( (string) ( $translated_chunk[ $i ] ?? '' ) );

							if ( $translated !== '' && $translated !== $chunk[ $i ] ) {
								$name_map[ $chunk[ $i ] ] = $translated;
							}
						}
					}

					if ( empty( $name_map ) ) {
						continue;
					}

					foreach ( $pending_terms as $new_id => $source_name ) {
						if ( isset( $name_map[ $source_name ] ) ) {
							$mt_result = wp_update_term( $new_id, $taxonomy, [ 'name' => wp_slash( $name_map[ $source_name ] ) ] );
							if ( is_wp_error( $mt_result ) ) {
								if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
									// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; MT name not applied, term keeps its source name.
									error_log( sprintf( 'PerfLocale migration: MT term-name update failed for term %d: %s', (int) $new_id, $mt_result->get_error_message() ) );
								}
							} else {
								$mt_used = true;
							}
						}
					}
				}
			}
		}

		$message = $more
			? sprintf(
				/* translators: %1$d: created so far, %2$d: already existed */
				__( '%1$d created, %2$d already existed - still working...', 'perflocale' ),
				$created,
				$skipped
			)
			: sprintf(
				/* translators: %1$d: number of translations created, %2$d: number already existing */
				__( 'Done - %1$d translation(s) created, %2$d already existed.', 'perflocale' ),
				$created,
				$skipped
			);

		if ( $mt_used ) {
			$message .= ' ' . __( 'Term names were machine-translated.', 'perflocale' );
		}

		// Release before the response: wp_send_json_success() dies.
		Concurrency\Lock::release( $lock_name );

		wp_send_json_success(
			[
				'message'          => $message,
				'created'          => $created,
				'skipped'          => $skipped,
				'taxonomy_details' => $taxonomy_details,
				// The caller sends these back, so the next run resumes here.
				'more'             => $more,
				'tax_index'        => $next_index,
				'term_offset'      => $next_offset,
			]
		);
	}

	/**
	 * AJAX handler: create the missing translation drafts for existing content.
	 *
	 * The bulk, non-MT way to make translations exist. A write path that creates posts, so:
	 * drafts only (the Default Translation Status), content not copied (an empty draft reads as
	 * unfinished), never MT, idempotent, authorized per source (refusals in `skipped_permission`),
	 * and bounded and resumable on the bulk cursor contract.
	 *
	 * @return void
	 */
	private static function ajax_generate_missing_translations(): void {
		check_ajax_referer( 'perflocale_generate_missing_translations', '_nonce' );

		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'perflocale' ) ], 403 );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$default   = $lang_repo->get_default();
		$languages = $lang_repo->get_active();

		if ( ! $default || count( $languages ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		global $wpdb;

		$targets = [];

		foreach ( $languages as $lang ) {
			if ( (int) $lang->id !== (int) $default->id ) {
				$targets[] = $lang;
			}
		}

		$lock_name = 'generate_missing_translations';

		// Manual acquire and release, not a with() wrapper: see the taxonomy translations handler.
		if ( ! Concurrency\Lock::acquire( $lock_name, 120 ) ) {
			// A lock left by a crashed run looks live: report the wait.
			$held_for = Concurrency\Lock::seconds_remaining( $lock_name );

			wp_send_json_error(
				[
					'message' => $held_for > 0
						? sprintf(
							/* translators: %d: seconds remaining. */
							__( 'Another run is in progress, or a previous one ended unexpectedly. This clears automatically in %d seconds - then try again.', 'perflocale' ),
							$held_for
						)
						: __( 'Another run is already in progress. Wait for it to finish, then try again.', 'perflocale' ),
				]
			);
		}

		$types = array_values( $settings->get_translatable_post_types() );

		// Optional scoping to some post types (also lets a test confine it to a fixture type).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$requested = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) ) )
			: [];

		if ( $requested !== [] ) {
			$types = array_values( array_intersect( $types, $requested ) );
		}

		// Count-first mode: report the scale before any work, so the confirmation can state a
		// number. Creates nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( isset( $_POST['mode'] ) && 'count' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ) {
			$counts = [];
			$total  = 0;

			foreach ( $types as $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					continue;
				}

				// The same "source" definition as the run below.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$n = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p
						 INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d
						 WHERE p.post_type = %s
						   AND p.post_status IN ('publish','draft','pending','private','future')",
						Database\Schema::table( 'translation_links' ),
						(int) $default->id,
						$post_type
					)
				);
				// phpcs:enable

				if ( $n > 0 ) {
					$counts[] = [
						'post_type' => $post_type,
						'sources'   => $n,
					];
					$total    += $n * count( $targets );
				}
			}

			Concurrency\Lock::release( $lock_name );

			wp_send_json_success(
				[
					'mode'    => 'count',
					// A ceiling, not a prediction: existing pairs are skipped.
					'max'     => $total,
					'types'   => $counts,
					'message' => sprintf(
						/* translators: %d: maximum number of drafts. */
						__( 'This will create up to %d draft translation(s). Existing translations are skipped.', 'perflocale' ),
						$total
					),
				]
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$type_index = isset( $_POST['type_index'] ) ? max( 0, (int) $_POST['type_index'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$post_offset = isset( $_POST['post_offset'] ) ? max( 0, (int) $_POST['post_offset'] ) : 0;

		$started_at = microtime( true );
		/** @hook perflocale/generate_translations/budget_seconds Wall-clock budget for one run. */
		$budget = (float) apply_filters( 'perflocale/generate_translations/budget_seconds', 15.0 );
		/** @hook perflocale/generate_translations/batch_size Source posts fetched per run. */
		$batch = max( 1, (int) apply_filters( 'perflocale/generate_translations/batch_size', 100 ) );

		$manager = new Translation\PostTranslationManager( $cache, $settings );

		$created      = 0;
		$skipped      = 0;
		$failed       = 0;
		$more         = false;
		$next_index   = 0;
		$next_offset  = 0;
		$per_type     = [];
		$hit_budget   = false;

		// Pairs refused by the per-source checks; $skipped counts existing translations.
		$skipped_permission = 0;

		foreach ( $types as $ti => $post_type ) {
			if ( $ti < $type_index ) {
				continue;
			}

			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			$base   = ( $ti === $type_index ) ? $post_offset : 0;
			$in_type = 0;
			$made    = 0;

			// Source-language posts only, via the link table: `perflocale_lang` is not a query arg
			// that filters here, so it would also return earlier runs' translations. ID order keeps
			// the offset stable across runs.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$source_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d
					 WHERE p.post_type = %s
					   AND p.post_status IN ('publish','draft','pending','private','future')
					 ORDER BY p.ID ASC
					 LIMIT %d OFFSET %d",
					Database\Schema::table( 'translation_links' ),
					(int) $default->id,
					$post_type,
					$batch,
					$base
				)
			);
			// phpcs:enable

			foreach ( (array) $source_ids as $source_id ) {
				$source_id = (int) $source_id;

				foreach ( $targets as $lang ) {
					if ( $manager->get_translation_id( $source_id, $lang->slug ) !== null ) {
						++$skipped;
						continue;
					}

					// The checks of every other create path: edit on the source, edit and read on the
					// copy-from post, and the create capability of its type.
					$copy_from = $manager->get_copy_source_id( $source_id, (string) $lang->slug );

					if ( ! Helper::user_can_edit_object( $source_id )
						|| ( $copy_from > 0 && ( ! Helper::user_can_copy_translation_source( $copy_from ) || ! Helper::user_can_create_like( $copy_from ) ) )
					) {
						++$skipped_permission;
						continue;
					}

					// copy_content = false: an empty draft reads as "not done".
					$inserted = false;
					$new_id   = $manager->create_translation( $source_id, $lang->slug, false, \PerfLocale\Enum\SourceType::Manual, $inserted );

					if ( ! is_int( $new_id ) || $new_id <= 0 ) {
						++$failed;
					} elseif ( ! $inserted ) {
						// Not inserted by this call: another request created it in between.
						++$skipped;
					} else {
						++$created;
						++$made;
					}
				}

				++$in_type;

				// The cursor passes a source only after every language was attempted for it. Time
				// or memory (bulk_memory_pressure()) both stop by handing the cursor back.
				if ( ( microtime( true ) - $started_at ) >= $budget || self::bulk_memory_pressure() ) {
					$hit_budget = true;
					break;
				}
			}

			$per_type[] = [
				'post_type' => $post_type,
				'created'   => $made,
			];

			$page = is_array( $source_ids ) ? count( $source_ids ) : 0;

			if ( $hit_budget ) {
				$more        = true;
				$next_index  = $ti;
				$next_offset = $base + $in_type;
			} elseif ( $page >= $batch ) {
				$more        = true;
				$next_index  = $ti;
				$next_offset = $base + $page;
			} else {
				$next_index  = $ti + 1;
				$next_offset = 0;
			}

			if ( $more ) {
				break;
			}
		}

		$message = $more
			? sprintf(
				/* translators: %1$d: created so far, %2$d: already existed */
				__( '%1$d created, %2$d already existed - still working...', 'perflocale' ),
				$created,
				$skipped
			)
			: sprintf(
				/* translators: 1: created, 2: already existing, 3: failed */
				__( 'Done - %1$d draft translation(s) created, %2$d already existed, %3$d failed.', 'perflocale' ),
				$created,
				$skipped,
				$failed
			);

		if ( $skipped_permission > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of translations not created. */
				_n(
					'%d translation was not created because you cannot edit the original or create content of its type.',
					'%d translations were not created because you cannot edit the original or create content of its type.',
					$skipped_permission,
					'perflocale'
				),
				$skipped_permission
			);
		}

		Concurrency\Lock::release( $lock_name );

		wp_send_json_success(
			[
				'message'            => $message,
				'created'            => $created,
				'skipped'            => $skipped,
				'skipped_permission' => $skipped_permission,
				'failed'             => $failed,
				'details'            => $per_type,
				'more'               => $more,
				'type_index'         => $next_index,
				'post_offset'        => $next_offset,
			]
		);
	}

	/**
	 * AJAX handler: assign the default language to every translatable post without a link row.
	 *
	 * Unlinked posts show in every language (PostQueryFilter's `OR object_id IS NULL`), so
	 * language-scoped pickers look leaky. Posts with a language stay untouched; nothing infers a
	 * language from content. Idempotent.
	 *
	 * @return void
	 */
	private static function ajax_assign_post_languages(): void {
		// Verify the caller before any side effect.
		check_ajax_referer( 'perflocale_assign_post_languages', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ] );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		// Caller verified: lift the time limit (filterable; default 0) and keep running on abort.
		// Inside this handler only, never on init.
		if ( function_exists( 'set_time_limit' ) ) {
			/**
			 * Time limit for the WC-pages / taxonomy-translation bulk admin handlers.
			 *
			 * @hook  perflocale/admin/bulk_time_limit
			 * @since 1.0.0
			 * @param int $seconds Default 0 (no limit). Set to a positive int (seconds) to cap.
			 * @return int
			 */
			$bulk_time_limit = (int) apply_filters( 'perflocale/admin/bulk_time_limit', 0 );
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
			set_time_limit( $bulk_time_limit );
		}
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$default   = $lang_repo->get_default();

		if ( ! $default ) {
			wp_send_json_error( [ 'message' => __( 'No default language configured.', 'perflocale' ) ] );
		}

		$types = array_values( $settings->get_translatable_post_types() );

		// Optional scoping, as in ajax_generate_missing_translations() (lets a test confine it to
		// a fixture type).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$requested = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) ) )
			: [];

		if ( $requested !== [] ) {
			$types = array_values( array_intersect( $types, $requested ) );
		}

		if ( empty( $types ) ) {
			wp_send_json_error( [ 'message' => __( 'No translatable post types configured.', 'perflocale' ) ] );
		}

		global $wpdb;
		$links_table  = Database\Schema::table( 'translation_links' );
		$groups_table = Database\Schema::table( 'translation_groups' );

		// Unlinked posts, one query per post type: LEFT JOIN to the 'post' group; NULL means never linked.
		$manager        = new Translation\PostTranslationManager( $cache, $settings );
		$per_type       = [];
		$total_assigned = 0;
		$hit_budget     = false;
		$started_at     = microtime( true );

		/** @hook perflocale/assign_languages/budget_seconds Wall-clock budget for one assign run. */
		$budget_seconds = (float) apply_filters( 'perflocale/assign_languages/budget_seconds', 15.0 );
		/** @hook perflocale/assign_languages/batch_size Rows fetched per post type per run. */
		$batch_size     = max( 1, (int) apply_filters( 'perflocale/assign_languages/batch_size', 500 ) );

		foreach ( $types as $post_type ) {
			// Posts with no valid post-group link. NOT EXISTS, not a LEFT JOIN: a post with one good
			// and one orphan link would otherwise count as unlinked and never settle.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table(), post_type sanitized.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.post_status IN ('publish','draft','pending','private','future')
					  AND p.post_type = %s
					  AND NOT EXISTS (
					    SELECT 1 FROM %i l
					    INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
					    WHERE l.object_id = p.ID
					  )
					ORDER BY p.ID ASC
					LIMIT %d",
					$post_type,
					$links_table,
					$groups_table,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$assigned = 0;

			foreach ( $ids as $post_id ) {
				if ( $manager->set_post_language( (int) $post_id, $default->slug ) ) {
					++$assigned;
				}

				// Bounded: stop on a clock or memory pressure (bulk_memory_pressure()) and report what
				// is left, or a large site gets a gateway 504 while PHP keeps writing. The caller resumes.
				if ( ( microtime( true ) - $started_at ) >= $budget_seconds || self::bulk_memory_pressure() ) {
					$hit_budget = true;
					break;
				}
			}

			$per_type[] = [
				'post_type' => $post_type,
				'assigned'  => $assigned,
			];

			$total_assigned += $assigned;

			if ( $hit_budget ) {
				break;
			}
		}

		// What is still unassigned across every type: this makes the run resumable.
		$remaining = 0;

		foreach ( $types as $post_type ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$remaining += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					WHERE p.post_status IN ('publish','draft','pending','private','future')
					  AND p.post_type = %s
					  AND NOT EXISTS (
					    SELECT 1 FROM %i l
					    INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
					    WHERE l.object_id = p.ID
					  )",
					$post_type,
					$links_table,
					$groups_table
				)
			);
			// phpcs:enable
		}

		$message = $remaining > 0
			? sprintf(
				/* translators: 1: number assigned so far, 2: language, 3: number still to do */
				__( '%1$d assigned to %2$s - %3$d still to go, continuing...', 'perflocale' ),
				$total_assigned,
				Helper::format_locale_as_bcp47( (string) $default->slug ),
				$remaining
			)
			: sprintf(
				/* translators: 1: number of items assigned, 2: default language slug */
				__( 'Done - %1$d item(s) assigned to %2$s.', 'perflocale' ),
				$total_assigned,
				Helper::format_locale_as_bcp47( (string) $default->slug )
			);

		wp_send_json_success(
			[
				'message'           => $message,
				'assigned'          => $total_assigned,
				'post_type_details' => $per_type,
				'remaining'         => $remaining,
			]
		);
	}

	/**
	 * Find a local WordPress translation for a string across all loaded text domains.
	 *
	 * A locale WordPress cannot switch to counts as a miss.
	 *
	 * @param string $text Original text to translate.
	 * @param string $locale Target WordPress locale (e.g., 'de_DE', 'ar').
	 * @return string Translated text, or empty string if no local translation found.
	 */
	private static function find_local_translation( string $text, string $locale ): string {
		// switch_to_locale() returns false without pushing a frame both when the locale is already
		// current and when it has no language pack; in the second case $l10n answers in the current
		// locale, and callers would save that as the target's title or name.
		$switched = switch_to_locale( $locale );

		if ( ! $switched && determine_locale() !== $locale ) {
			return '';
		}

		$result = '';

		global $l10n;

		if ( is_array( $l10n ) ) {
			foreach ( $l10n as $translations ) {
				$translated = $translations->translate( $text );

				if ( $translated !== $text && $translated !== '' ) {
					$result = $translated;
					break;
				}
			}
		}

		// Pop only a frame this call pushed.
		if ( $switched ) {
			restore_previous_locale();
		}

		return $result;
	}

	/**
	 * Add language labels ("[EN]") to wp_dropdown_pages options.
	 *
	 * @param string $output Dropdown HTML.
	 * @return string Modified HTML.
	 */
	public static function add_language_labels_to_dropdown( string $output ): string {
		if ( ! is_admin() || empty( $output ) ) {
			return $output;
		}

		// Per-request memo of page id to language code (the dropdown renders several times).
		// Keyed by blog id: a network-admin render may switch blogs between dropdowns.
		static $page_lang_map = [];

		$blog_id = get_current_blog_id();

		if ( ! isset( $page_lang_map[ $blog_id ] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			global $wpdb;

			$links_table  = Database\Schema::table( 'translation_links' );
			$groups_table = Database\Schema::table( 'translation_groups' );
			$langs_table  = Database\Schema::table( 'languages' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.object_id, UPPER(lang.slug) AS lang_code
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id AND g.type = 'post'
					INNER JOIN %i lang ON l.language_id = lang.id
					INNER JOIN %i p ON l.object_id = p.ID AND p.post_type = 'page'
					LIMIT 500",
					$links_table,
					$groups_table,
					$langs_table,
					$wpdb->posts
				)
			);

			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$blog_map = [];

			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					$blog_map[ (int) $row->object_id ] = $row->lang_code;
				}
			}

			$page_lang_map[ $blog_id ] = $blog_map;
		}

		$map = $page_lang_map[ $blog_id ];

		if ( $map === [] ) {
			return $output;
		}

		// Append language code to each <option> label.
		return preg_replace_callback(
			'/(<option[^>]*value=["\'](\d+)["\'][^>]*>)([^<]+)(<\/option>)/i',
			static function ( array $m ) use ( $map ): string {
				$page_id = (int) $m[2];

				if ( isset( $map[ $page_id ] ) ) {
					// Escape late: the slug goes straight into the dropdown's HTML.
					return $m[1] . $m[3] . ' [' . esc_html( $map[ $page_id ] ) . ']' . $m[4];
				}

				return $m[0];
			},
			$output
		) ?? $output;
	}

	/**
	 * Allow duplicate post slugs across languages; the URL's language prefix keeps them apart.
	 *
	 * @param string $slug The slug WP wants to assign (possibly with -2, -3, etc.).
	 * @param int    $post_id Post ID.
	 * @param string $post_status Post status.
	 * @param string $post_type Post type.
	 * @param int    $post_parent Post parent ID.
	 * @param string $original_slug The original desired slug before deduplication.
	 * @return string The slug to use.
	 */
	public static function allow_translation_duplicate_slugs(
		string $slug,
		int $post_id,
		string $post_status,
		string $post_type,
		int $post_parent,
		string $original_slug
	): string {
		// Never collapse a template translation's slug: its distinct post_name is core's only
		// lookup handle (`post_name__in`), and a shared one makes two rows answer one query.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post_type ) ) {
			return $slug;
		}

		// Only intervene if WP changed the slug (appended -2, -3, etc.).
		if ( $slug === $original_slug ) {
			return $slug;
		}

		// Only for translatable post types.
		$plugin   = Plugin::get_instance();
		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $post_type, $settings->get_translatable_post_types(), true ) ) {
			return $slug;
		}

		// Collapse only when the conflicting posts are in a different language; a same-language
		// collision keeps WP's -N suffix, or the URLs would be identical.
		$cache     = $plugin->get( 'cache' );
		$manager   = new Translation\PostTranslationManager( $cache, $settings );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );

		// create_translation() sets this hint before wp_insert_post(): the link row does not exist yet.
		$current_lang = null;
		if ( Translation\PostTranslationManager::$creating_translation_lang_slug !== null ) {
			$current_lang = $lang_repo->find_by_slug(
				Translation\PostTranslationManager::$creating_translation_lang_slug
			);
		}

		if ( ! $current_lang ) {
			$current_lang = $manager->detect_post_language( $post_id );
		}

		if ( ! $current_lang ) {
			// Unknown language: be conservative.
			return $slug;
		}

		global $wpdb;

		// All same-slug, same-type, non-trash siblings; one same-language conflict keeps the suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conflict_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_name = %s
				AND post_type = %s
				AND ID != %d
				AND post_status NOT IN ( 'trash', 'auto-draft' )",
				$original_slug,
				$post_type,
				$post_id
			)
		);

		if ( empty( $conflict_ids ) ) {
			return $slug;
		}

		foreach ( (array) $conflict_ids as $conflict_id ) {
			$conflict_lang = $manager->detect_post_language( (int) $conflict_id );

			// Same-language or unassigned (treated as default) conflict: keep WP's slug.
			if ( ! $conflict_lang || (int) $conflict_lang->id === (int) $current_lang->id ) {
				return $slug;
			}
		}

		// Every conflict is in another language: the language router prefixes the URL.
		return $original_slug;
	}

	/**
	 * Remove a permanently deleted post's translation link and slug/content-hash rows.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function cleanup_translation_link_on_delete( int $post_id ): void {
		// Revisions never carry translation links.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		// Clear the post's and its siblings' hreflang while the link still names the siblings.
		if ( $plugin->has( 'cache_invalidator' ) ) {
			$plugin->get( 'cache_invalidator' )->clear_hreflang_before_unlink( $post_id );
		}

		// Translation link plus group GC.
		$groups = new Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
		$groups->unlink_by_object_id( $post_id, 'post' );

		// Drop this post's language memo so same-request callers do not keep the stale value.
		Translation\PostTranslationManager::forget_post_language( $post_id );

		// Tables may not exist yet (activation) or any more (uninstall).
		if ( ! Database\Schema::tables_exist() ) {
			return;
		}

		global $wpdb;

		// The post's slug_translations rows (one per language).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Database\Schema::table( 'slug_translations' ),
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			],
			[ '%d', '%s' ]
		);

		// The post's content_hashes row: ContentChangeDetector's own delete hook is not booted on
		// the front end, and this handler always is.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Database\Schema::table( 'content_hashes' ),
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Store a sync opt-out flag sent in a REST update before the post is saved.
	 *
	 * REST writes `meta` after save_post, so ContentSync (save_post:20) would read the old flag
	 * for that Update. Same permission check and sanitising as core's later write; creates and
	 * autosaves are left to core.
	 *
	 * @param \stdClass|\WP_Error   $prepared_post Post prepared for the database.
	 * @param \WP_REST_Request|null $request       Request being handled.
	 * @return \stdClass|\WP_Error The prepared post, unchanged.
	 */
	public static function apply_rest_sync_optout( $prepared_post, $request = null ) {
		if ( ! $prepared_post instanceof \stdClass || ! $request instanceof \WP_REST_Request ) {
			return $prepared_post;
		}

		$post_id = isset( $prepared_post->ID ) && is_numeric( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;

		if ( $post_id <= 0 || str_ends_with( untrailingslashit( $request->get_route() ), '/autosaves' ) ) {
			return $prepared_post;
		}

		$meta = $request->get_param( 'meta' );
		$key  = Translation\SyncMeta::SYNC_OPTOUT_META;

		if ( ! is_array( $meta ) || ! array_key_exists( $key, $meta ) ) {
			return $prepared_post;
		}

		if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
			return $prepared_post;
		}

		$value = null === $meta[ $key ] ? '' : sanitize_meta( $key, $meta[ $key ], 'post', (string) get_post_type( $post_id ) );

		if ( 'yes' === $value ) {
			update_post_meta( $post_id, $key, 'yes' );
		} else {
			delete_post_meta( $post_id, $key );
		}

		return $prepared_post;
	}

	/**
	 * Boot the write-hook services on the first write of a request that was not a write context.
	 *
	 * Each signal fires before the hooks those services register, so the triggering write is
	 * handled; a scheduled publish has no data filter, so `future_to_publish` boots them.
	 * Deletions need no signal. See docs-ai/11-code-rationale.md, "Bootstrap: write services on
	 * first write".
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

		add_filter( 'wp_insert_post_data', $boot, PHP_INT_MIN );
		add_filter( 'add_post_metadata', $boot, PHP_INT_MIN );
		add_filter( 'update_post_metadata', $boot, PHP_INT_MIN );
		add_filter( 'delete_post_metadata', $boot, PHP_INT_MIN );
		$boot_action = static function () use ( $boot ): void {
			$boot();
		};

		add_action( 'edit_terms', $boot_action, PHP_INT_MIN );
		add_action( 'set_object_terms', $boot_action, PHP_INT_MIN );
		add_action( 'future_to_publish', $boot_action, PHP_INT_MIN );
	}

	/**
	 * Anonymise a user's background-job rows on the site they are removed from.
	 *
	 * Hooked in every context: wpmu_delete_user() reaches the user's other sites only through
	 * this action, and account plugins run it from a front-end POST.
	 *
	 * @param int|string $user_id User being removed.
	 * @param int|string $blog_id Site the user is removed from.
	 * @return void
	 */
	public static function anonymize_jobs_on_blog( $user_id, $blog_id = 0 ): void {
		$user_id = (int) $user_id;
		$blog_id = (int) $blog_id;

		if ( $user_id <= 0 ) {
			return;
		}

		// Site teardown removes members after SiteCleanup dropped the tables: nothing to anonymise.
		if ( $blog_id > 0 && Database\SiteCleanup::was_site_purged( $blog_id ) ) {
			return;
		}

		$switched = false;

		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			// A site where the plugin was never activated has no jobs table.
			if ( Database\Schema::tables_exist() ) {
				Background\JobState::anonymize_for_user( $user_id );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Auto-assign the default language to new translatable posts (save_post:5, every context).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_assign_default_language( int $post_id, ?\WP_Post $post = null ): void {
		// Core passes null when the post was deleted after the write, and some plugins fire
		// save_post with one argument.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Child types inherit the parent's language (product_variation).
		if ( $post->post_type === 'product_variation' ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $post->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$cache   = $plugin->get( 'cache' );
		$manager = new Translation\PostTranslationManager( $cache, $settings );

		// Already has a language.
		if ( $manager->detect_post_language( $post_id ) !== null ) {
			return;
		}

		$lang_repo    = new Database\Repository\LanguageRepository( $cache );
		$default_lang = $lang_repo->get_default();

		if ( ! $default_lang ) {
			return;
		}

		// The template reference rules at request end learn the post's type from here.
		Translation\TemplateBlockRefs::note_known_type( $post_id, $post->post_type );

		if ( ! $manager->set_post_language( $post_id, $default_lang->slug ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the post stays unassigned and is treated as default-language by the fallback convention.
			error_log( sprintf( 'PerfLocale: auto default-language assignment failed for new post %d.', $post_id ) );

			return;
		}

		// Terms passed to wp_insert_post() were attached before the post had a language: run the
		// wrong-language term swap once, with the language known.
		$term_filter = $plugin->has( 'term_assignment_filter' ) ? $plugin->get( 'term_assignment_filter' ) : null;

		if ( $term_filter instanceof Translation\TermAssignmentFilter ) {
			$term_filter->normalize_post_terms( $post_id );
		}
	}

	/**
	 * Posts whose first publish asks for translation stubs, per blog, until
	 * their save has finished writing terms, meta and the featured image.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $pending_stubs = [];

	/**
	 * Products whose WooCommerce data was saved inside their own save_post, per
	 * blog; their stubs wait for that save to finish.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $stub_products_saved = [];

	/**
	 * Pending stub posts per blog before the oldest is created at once.
	 */
	private const PENDING_STUBS_CAP = 100;

	/**
	 * Auto-create empty translation stubs when a post is first published.
	 *
	 * The transition fires before REST and WooCommerce write terms, meta and product data, so
	 * this only records the post; stubs are made once its save completes
	 * ({@see self::create_stubs_after_insert()}, the WooCommerce hooks, a shutdown fallback).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_create_translation_stubs( string $new_status, string $old_status, \WP_Post $post ): void {
		// No empty stubs for templates: a published empty translation renders a blank header.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post->post_type ) ) {
			return;
		}

		// Only trigger when a post transitions to "publish" for the first time.
		if ( $new_status !== 'publish' || $old_status === 'publish' ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $plugin->get( 'settings' )->get_translatable_post_types(), true ) ) {
			return;
		}

		$blog_id = get_current_blog_id();

		if ( isset( self::$pending_stubs[ $blog_id ] ) && count( self::$pending_stubs[ $blog_id ] ) >= self::PENDING_STUBS_CAP ) {
			self::create_stubs_now( (int) array_key_first( self::$pending_stubs[ $blog_id ] ) );
		}

		self::$pending_stubs[ $blog_id ][ (int) $post->ID ] = true;

		if ( false === has_action( 'shutdown', [ self::class, 'create_pending_stubs' ] ) ) {
			add_action( 'wp_after_insert_post', [ self::class, 'create_stubs_after_insert' ], 100, 1 );
			add_action( 'woocommerce_new_product', [ self::class, 'create_stubs_after_product_save' ], 100, 1 );
			add_action( 'woocommerce_update_product', [ self::class, 'create_stubs_after_product_save' ], 100, 1 );
			add_action( 'shutdown', [ self::class, 'create_pending_stubs' ], 0 );
		}
	}

	/**
	 * Create the stubs of a post whose save has just completed.
	 *
	 * A WooCommerce product waits for its data store's hook, unless that save already ran inside
	 * this post save.
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function create_stubs_after_insert( $post_id ): void {
		$post_id = (int) $post_id;
		$blog_id = get_current_blog_id();

		if ( ! isset( self::$pending_stubs[ $blog_id ][ $post_id ] ) ) {
			return;
		}

		if ( 'product' === get_post_type( $post_id ) && did_action( 'woocommerce_loaded' ) && ! isset( self::$stub_products_saved[ $blog_id ][ $post_id ] ) ) {
			return;
		}

		self::create_stubs_now( $post_id );
	}

	/**
	 * Create the stubs of a product once WooCommerce has saved its data.
	 *
	 * @param int|string $product_id Product ID.
	 * @return void
	 */
	public static function create_stubs_after_product_save( $product_id ): void {
		$product_id = (int) $product_id;
		$blog_id    = get_current_blog_id();

		if ( ! isset( self::$pending_stubs[ $blog_id ][ $product_id ] ) ) {
			return;
		}

		// Saved inside the product's own save_post: the language select and other save_post
		// writers have not run yet, so wait for wp_after_insert_post.
		if ( doing_action( 'save_post' ) ) {
			self::$stub_products_saved[ $blog_id ][ $product_id ] = true;

			return;
		}

		self::create_stubs_now( $product_id );
	}

	/**
	 * Create the stubs still pending at request end (no wp_after_insert_post or WooCommerce hook).
	 *
	 * @return void
	 */
	public static function create_pending_stubs(): void {
		foreach ( array_keys( self::$pending_stubs ) as $blog_id ) {
			$switched = is_multisite() && get_current_blog_id() !== (int) $blog_id;

			if ( $switched ) {
				switch_to_blog( (int) $blog_id );
			}

			try {
				foreach ( array_keys( self::$pending_stubs[ $blog_id ] ) as $post_id ) {
					self::create_stubs_now( (int) $post_id );
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		self::$pending_stubs       = [];
		self::$stub_products_saved = [];
	}

	/**
	 * Create the translation stubs of one recorded post on the current blog.
	 *
	 * The language is read after the save, so the editor's language select is respected.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function create_stubs_now( int $post_id ): void {
		$blog_id = get_current_blog_id();

		unset( self::$pending_stubs[ $blog_id ][ $post_id ], self::$stub_products_saved[ $blog_id ][ $post_id ] );

		$post   = get_post( $post_id );
		$plugin = Plugin::get_instance();

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		$settings  = $plugin->get( 'settings' );
		$cache     = $plugin->get( 'cache' );
		$manager   = new Translation\PostTranslationManager( $cache, $settings );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$default   = $lang_repo->get_default();

		if ( ! $default ) {
			return;
		}

		// Only create stubs for default-language posts.
		$post_lang = $manager->detect_post_language( $post->ID );

		if ( $post_lang && $post_lang->slug !== $default->slug ) {
			return;
		}

		// Lock the existence check and create loop: two near-simultaneous publish transitions would
		// both pass the `count > 1` guard. 60s TTL, below WP-Cron's 15-minute timeout.
		Concurrency\Lock::with(
			'auto_stubs_' . $post->ID,
			60,
			function () use ( $manager, $lang_repo, $default, $post ) {
				$existing = $manager->get_translations( $post->ID );

				if ( count( $existing ) > 1 ) {
					return;
				}

				foreach ( $lang_repo->get_active() as $lang ) {
					if ( $lang->slug === $default->slug ) {
						continue;
					}

					$manager->create_translation( $post->ID, $lang->slug, false );
				}
			}
		);
	}

	/**
	 * Blogs whose compiled translation files are fully regenerated at request end (files mode).
	 *
	 * @var array<int, true>
	 */
	private static array $pending_files_regeneration = [];

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
	 * That is a non-default language activated or its locale changed; $language is re-read after
	 * the write, so an update that wrote nothing queues nothing.
	 *
	 * @param mixed $language Language row after the update (null when it could not be read).
	 * @param mixed $before   Language row before the update, as an array.
	 * @return void
	 */
	public static function regenerate_files_after_language_update( $language, $before = [] ): void {
		if ( ! is_object( $language ) || ! is_array( $before ) || ! empty( $language->is_default ) || empty( $language->is_active ) ) {
			return;
		}

		$activated      = empty( $before['is_active'] );
		$locale_changed = (string) ( $language->locale ?? '' ) !== (string) ( $before['locale'] ?? '' );

		if ( $activated || $locale_changed ) {
			self::queue_files_regeneration();
		}
	}

	/**
	 * Queue one full regeneration of this blog's compiled files for request end (files mode).
	 *
	 * Debounced per request and blog, so an import's many edits share one run on the final state.
	 *
	 * @return void
	 */
	public static function queue_files_regeneration(): void {
		if ( ! self::files_mode() ) {
			return;
		}

		self::$pending_files_regeneration[ get_current_blog_id() ] = true;

		if ( false === has_action( 'shutdown', [ self::class, 'run_pending_files_regeneration' ] ) ) {
			add_action( 'shutdown', [ self::class, 'run_pending_files_regeneration' ], 0 );
		}
	}

	/**
	 * Run the queued regenerations: one generate_all() per blog still in files mode.
	 *
	 * @return void
	 */
	public static function run_pending_files_regeneration(): void {
		$pending                          = self::$pending_files_regeneration;
		self::$pending_files_regeneration = [];

		foreach ( array_keys( $pending ) as $blog_id ) {
			$switched = is_multisite() && get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			try {
				if ( self::files_mode() ) {
					self::regenerate_files_now();
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Queue the background regeneration after a plugin update (files mode; one pending event).
	 *
	 * @return void
	 */
	public static function queue_files_regeneration_after_update(): void {
		if ( ! self::files_mode() ) {
			return;
		}

		if ( ! Background\BackgroundEvents::is_scheduled( self::FILES_REGENERATE_HOOK, [] ) ) {
			Background\BackgroundEvents::enqueue( self::FILES_REGENERATE_HOOK );
		}
	}

	/**
	 * Handler of FILES_REGENERATE_HOOK: one full regeneration while still in files mode.
	 *
	 * @return void
	 */
	public static function regenerate_files_in_background(): void {
		if ( self::files_mode() ) {
			self::regenerate_files_now();
		}
	}

	/**
	 * Whether strings are served from compiled translation files.
	 *
	 * @return bool
	 */
	private static function files_mode(): bool {
		$plugin = Plugin::get_instance();

		return $plugin->has( 'settings' ) && 'files' === (string) $plugin->get( 'settings' )->get( 'string_translation_mode', 'files' );
	}

	/**
	 * Full regeneration of the current blog's compiled files; a failure is logged, never thrown.
	 *
	 * @return void
	 */
	private static function regenerate_files_now(): void {
		try {
			( new Strings\TranslationFileGenerator( Plugin::get_instance()->get( 'cache' ) ) )->generate_all();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a failure path that must not break the request.
			error_log( 'PerfLocale: regenerating the compiled translation files failed: ' . $e->getMessage() );
		}
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
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			Background\Jobs\AutoTranslateJob::on_publish( $post );
		}
	}

	/**
	 * AUTO_TRANSLATE_CRON: run an event an earlier version scheduled as a job.
	 *
	 * @param int $post_id Post ID.
	 * @param int $attempt Handoffs tried before.
	 * @return void
	 */
	public static function process_auto_translate( int $post_id, int $attempt = 0 ): void {
		Background\Jobs\AutoTranslateJob::from_legacy_event( $post_id, $attempt );
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
		// Skip WP-internal taxonomies.
		if ( in_array( $taxonomy, [ 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' ], true ) ) {
			return;
		}

		// A language chosen in the term form is saved by the created_{taxonomy} handler.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['perflocale_term_language'] ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $taxonomy, $settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		$cache   = $plugin->get( 'cache' );
		$manager = new Translation\TermTranslationManager( $cache );

		// Already has a language.
		if ( $manager->detect_term_language( $term_id ) !== null ) {
			return;
		}

		$lang_repo    = new Database\Repository\LanguageRepository( $cache );
		$default_lang = $lang_repo->get_default();

		if ( $default_lang && ! $manager->set_term_language( $term_id, $default_lang->slug ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the term stays unassigned and is treated as default-language by the fallback convention.
			error_log( sprintf( 'PerfLocale: auto default-language assignment failed for new term %d.', $term_id ) );
		}
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
