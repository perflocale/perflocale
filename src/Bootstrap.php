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
 * Handles plugin initialization and conditional service registration.
 *
 * This class ensures that only the services needed for the current request
 * context (admin, frontend, REST, CLI) are registered and loaded.
 */
final class Bootstrap {

	/**
	 * Known conflicting multilingual plugins.
	 *
	 * Structure: name => [ 'constant' => ..., 'files' => [ ... ] ]
	 * - `constant` is the plugin's version define. Present once the plugin's
	 * main file has loaded.
	 * - `files` are active_plugins entries to match against. Used as a
	 * fallback when the constant isn't defined yet (PerfLocale's main file
	 * loaded before the competitor's in active_plugins order).
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
	 * Cron hook names duplicated from their owning classes so wiring these
	 * add_action()/schedule calls doesn't autoload WebhookController (45KB,
	 * plus its RestController parent) and Lock (17KB) on every request just
	 * to read a class constant — the callbacks construct the classes lazily
	 * when the hooks actually fire. HookNameDriftTest asserts these stay
	 * byte-equal to Api\WebhookController::DELIVERY_HOOK / DRAIN_HOOK /
	 * RETRY_HOOK and Concurrency\Lock::CLEANUP_HOOK.
	 */
	private const WEBHOOK_DELIVERY_HOOK = 'perflocale_deliver_webhook';
	private const WEBHOOK_DRAIN_HOOK    = 'perflocale_drain_webhooks';
	private const WEBHOOK_RETRY_HOOK    = 'perflocale_retry_webhook';
	private const LOCK_CLEANUP_HOOK     = 'perflocale_lock_cleanup';

	/**
	 * Initialize the plugin.
	 *
	 * Called once from perflocale.php after the autoloader is loaded.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Abort initialization if a conflicting multilingual plugin is active.
		$conflict = self::detect_conflicting_plugin();

		if ( $conflict !== null ) {
			add_action(
				'admin_notices',
				static function () use ( $conflict ): void {
					printf(
						'<div class="notice notice-error"><p><strong>PerfLocale</strong>: %s</p></div>',
						sprintf(
						/* translators: %s: conflicting plugin name */
							esc_html__( 'Cannot run while %s is active. Please deactivate one of the two multilingual plugins to avoid conflicts.', 'perflocale' ),
							esc_html( $conflict )
						)
					);
				}
			);

			return;
		}

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

		// Shared repository singletons — one instance per request, with a
		// single seam to mock for tests.
		//
		// Must be registered BEFORE the LanguageRouter (which calls
		// $plugin->get( 'lang_repo' ) from its own constructor lifecycle
		// during detect_locale_early()).
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

		// Early language detection - resolve the current language from the
		// URL immediately (during plugin file load) so the locale filter
		// returns the correct value BEFORE plugins load their text domains
		// at init. Without this, WooCommerce and other plugins would always
		// load English strings because text domain loading runs before
		// parse_request where the full detection happens.
		$router = $plugin->get( 'router' );
		add_filter( 'locale', [ $router, 'filter_locale' ] );
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

		// Database migrator: checks schema version on admin, REST, and CLI requests.
		$plugin->register(
			'migrator',
			fn() => new Database\Migrator(),
			is_admin() || Helper::is_rest_request() || ( defined( 'WP_CLI' ) && WP_CLI )
		);

		// Content sync: synchronize configured fields across translations on save.
		$sync_fields = (array) $plugin->get( 'settings' )->get( 'sync_fields', [] );

		if ( ! empty( $sync_fields ) ) {
			// Both hooks (save_post + edited_term) only fire in write
			// contexts. Skip eager boot on frontend GETs.
			$plugin->register(
				'content_sync',
				fn( Plugin $p ) => new Translation\ContentSync(
					$p->get( 'settings' ),
					$p->get( 'cache' ),
				),
				Helper::is_write_context()
			);
		}

		// Allow duplicate post slugs for posts in different languages.
		// Must be global (admin + frontend) since WP checks uniqueness on save.
		add_filter( 'wp_unique_post_slug', [ self::class, 'allow_translation_duplicate_slugs' ], 10, 6 );

		// Auto-assign default language to new posts on save (all contexts).
		add_action( 'save_post', [ self::class, 'auto_assign_default_language' ], 5, 2 );

		// Clean up translation_links when a post is permanently deleted.
		// Without this the group row keeps pointing at a post ID that no
		// longer exists, so `get_translations()` returns orphan IDs and the
		// switcher / hreflang / fallback chain see ghost siblings.
		add_action( 'before_delete_post', [ self::class, 'cleanup_translation_link_on_delete' ] );

		// Auto-create translation stubs when a post is published.
		if ( (bool) $plugin->get( 'settings' )->get( 'auto_create_stubs' ) ) {
			add_action( 'transition_post_status', [ self::class, 'auto_create_translation_stubs' ], 20, 3 );
		}

		// Auto-translate on publish - deferred to WP-Cron so publishing is instant.
		if ( $plugin->get( 'settings' )->mt_enabled() && (bool) $plugin->get( 'settings' )->get( 'mt_auto_translate_on_publish' ) ) {
			add_action( 'transition_post_status', [ self::class, 'auto_translate_on_publish' ], 25, 3 );
		}

		// Cron handler for deferred auto-translation (always registered so
		// scheduled events fire even if the setting is later toggled off).
		add_action( self::AUTO_TRANSLATE_CRON, [ self::class, 'process_auto_translate' ] );

		// Add language labels to page dropdowns (Reading Settings, etc.).
		add_filter( 'wp_dropdown_pages', [ self::class, 'add_language_labels_to_dropdown' ] );

		// Clear the `_perflocale_meta_copy_errors` warning meta on real
		// post saves. Registered at always-loaded scope (not inside
		// MetaBox) because MetaBox is admin-only behind
		// `is_admin() && ! wp_doing_ajax()` — leaving the cleanup there
		// would skip the block-editor REST save path AND WP-CLI saves,
		// and the warning notice would persist forever in those contexts.
		//
		// Rationale: the wp.org review's 31 May pass flagged
		// `delete_post_meta` happening inside `admin_notices` (a GET-driven
		// render hook) as a state change without nonce verification.
		// Moving the state change to `save_post` means it inherits the
		// nonce + capability gates WP-core has already verified upstream
		// in each save path:
		//
		// - Classic form save: `check_admin_referer('update-post_'.$id)`
		// in wp-admin/post.php BEFORE wp_update_post fires save_post.
		// - Block-editor / REST save: WP_REST_Posts_Controller's
		// permission_callback enforces current_user_can('edit_post',$id)
		// and the request carries X-WP-Nonce.
		// - WP-CLI / cron / programmatic: no web-attacker CSRF surface.
		//
		// Cost on the every-post-save hot path: one get_post_meta cache
		// hit (~sub-µs on a warmed cache) + early return for the 99%+ of
		// saves on posts that never had a copy-errors warning.
		add_action(
			'save_post',
			static function ( int $post_id, ?\WP_Post $post = null ): void {
				// Core hands this hook whatever its post-write re-read
				// returned, which is null when the row was deleted in
				// between; a non-nullable hint made that an uncaught
				// TypeError. See auto_assign_default_language().
				if ( ! $post instanceof \WP_Post ) {
					return;
				}

				// Bail on autosave + revision contexts so a heartbeat
				// autosave doesn't silently dismiss the warning before
				// the user has explicitly saved.
				//
				// Modern WP autosaves create a revision post and fire
				// save_post for the revision (not the parent) —
				// wp_is_post_revision() catches them. DOING_AUTOSAVE
				// covers legacy / third-party paths where the constant
				// is set but save_post fires for the parent post.
				//
				// No separate wp_is_post_autosave() check: per WP-core
				// source, autosaves are a proper subset of revisions
				// (wp_is_post_autosave internally requires
				// wp_is_post_revision to be true and ADDS a post_name
				// check), so the revision branch catches them already.
				if (
					( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
					|| wp_is_post_revision( $post_id )
				) {
					return;
				}

				// Read-first short-circuit so we skip the meta updates
				// for the dominant case (posts that never had a copy-
				// errors warning).
				$errors = get_post_meta( $post_id, '_perflocale_meta_copy_errors', true );

				if ( empty( $errors ) || ! is_array( $errors ) ) {
					return;
				}

				// Self-clearing dismissal: each failed step is only
				// removed from the errors array if the underlying
				// content "looks" populated. The warning persists for
				// steps that still appear to lack content, so a user
				// who clicks Update without actually filling in the
				// failed fields keeps seeing the warning until they do.
				//
				// Without this gate (the prior implementation), any
				// save unconditionally cleared the warning — silently
				// dismissing a real problem and shipping an incomplete
				// translation.
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
							// User has populated terms if any taxonomy
							// has at least one term BEYOND the WP-core
							// default (e.g. WordPress assigns
							// "Uncategorized" to every new post — that
							// alone shouldn't dismiss the warning, since
							// it means the copy didn't actually
							// transfer the source post's categories).
							// Same shape for default link_category /
							// default_email_category on other types.
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
							// post_meta is vague — the user could have
							// addressed any of N meta keys. We can't
							// know which one was supposed to be copied
							// without storing it at copy time. As a
							// conservative proxy: if the post has ANY
							// non-internal meta keys set, treat the
							// failure as addressed. Internal keys (_
							// prefix excluding _thumbnail_id which is
							// covered by featured_image) are excluded.
							$meta_keys = get_post_meta( $post_id );
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
							// Unknown step from a future code path —
							// preserve it so we don't silently swallow
							// signals we don't yet understand.
							break;
					}
					$remaining[] = $err;
				}

				if ( count( $remaining ) === count( $errors ) ) {
					// Nothing changed — the user saved without addressing
					// any of the failed steps. Leave the meta alone so
					// the warning persists.
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

		// Language-conditional content - block + shortcode + PHP helper.
		// Registered eagerly so the shortcode tag resolves in feeds, REST
		// preview, and admin-ajax contexts too. Cost when unused is a
		// single in-memory class instance + two `init` hook callbacks.
		$plugin->register(
			'conditional_content',
			fn() => new Frontend\ConditionalContent(),
			true
		);

		// Block-toolbar "Do not translate" toggle: hooks the
		// perflocale/mt/pre_translate + /post_translate pipeline so marked
		// blocks survive MT verbatim. Those filters only fire during admin /
		// REST / CLI MT ops, never on frontend GETs — hence the context gate.
		$plugin->register(
			'block_skip_filter',
			fn() => new Translation\BlockSkipFilter(),
			Helper::is_write_context()
		);

		// Block-toolbar editor assets - adds a "Translate" dropdown
		// to every supported text block in the Gutenberg editor for
		// users with the perflocale_translate capability. Loads only
		// when (a) capability check passes and (b) the feature filter
		// returns true; everyone else never downloads the script.
		//
		// `enqueue_block_editor_assets` only fires in the block editor
		// (post editor + site editor + full-site editing) which is
		// always an admin context. Gating the `add_action` saves the
		// closure allocation + capture cost on every non-admin request.
		if ( is_admin() ) {
			add_action(
				'enqueue_block_editor_assets',
				static function (): void {
					if ( ! current_user_can( 'perflocale_translate' ) ) {
						return;
					}

					// Gate by screen so we don't load 53 KB JS + 2 KB CSS + the
					// language-list query for editor contexts that can't possibly
					// use them: the Site Editor (screen->base !== 'post'), the
					// Widgets / Pattern editors, and post.php for post types the
					// admin didn't mark translatable. Mirrors EditorSidebar's gate.
					$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

					if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base ) {
						return;
					}

					$plugin       = Plugin::get_instance();
					$translatable = $plugin->has( 'settings' )
					? (array) $plugin->get( 'settings' )->get_translatable_post_types()
					: [];

					if ( ! in_array( $screen->post_type, $translatable, true ) ) {
						return;
					}

					/**
					 * Filter whether the PerfLocale block-toolbar extension loads.
					 *
					 * @hook perflocale/block_toolbar/enabled
					 * @param bool $enabled Default true.
					 */
					if ( ! apply_filters( 'perflocale/block_toolbar/enabled', true ) ) {
						return;
					}

					wp_enqueue_script(
						'perflocale-block-toolbar',
						PERFLOCALE_URL . 'assets/js/block-toolbar.js',
						[ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-api-fetch' ],
						PERFLOCALE_VERSION,
						true
					);

					// Load the JS translations for this handle. Without it the wp.i18n.__()
					// strings in the script file stay untranslated no matter what a
					// translator supplies — WordPress only wires a script's JSON language
					// pack when the handle is registered here. No-ops until a language pack
					// exists, so it is safe on a fresh install.
					wp_set_script_translations( 'perflocale-block-toolbar', 'perflocale' );

					wp_enqueue_style(
						'perflocale-block-toolbar-editor',
						PERFLOCALE_URL . 'assets/css/block-toolbar-editor.css',
						[],
						PERFLOCALE_VERSION
					);

					// Hoist the cache + repository instantiations out of the per-
					// branch checks below. Previously this closure created TWO
					// LanguageRepository instances (one for the languages map, one
					// for sibling detection a few branches down), plus separate
					// PostTranslationManager and TranslationGroupRepository
					// instances — all sharing the same CacheManager. Now each
					// service is created at most once per editor load.
					$has_cache = $plugin->has( 'cache' );
					$cache_svc = $has_cache ? $plugin->get( 'cache' ) : null;
					$lang_repo = ( $cache_svc !== null ) ? new Database\Repository\LanguageRepository( $cache_svc ) : null;

					$languages = [];
					$current   = '';

					if ( $lang_repo !== null ) {
						$languages = array_map(
							static fn( $lang ): array => [
								'slug'   => (string) $lang->slug,
								// Canonical BCP 47 form for visible labels in the JS UI.
								// `slug` stays lowercase because it's used as an
								// identifier (REST keys, URL segments, comparison
								// against router state); `bcp47` is render-only.
								'bcp47'  => Helper::format_locale_as_bcp47( (string) $lang->slug ),
								'locale' => (string) $lang->locale,
								'name'   => (string) ( $lang->name ?? $lang->native_name ?? $lang->slug ),
							],
							$lang_repo->get_active()
						);
					}

					if ( $plugin->has( 'router' ) ) {
						$cur = $plugin->get( 'router' )->get_current_language();
						if ( is_object( $cur ) ) {
							$current = (string) ( $cur->slug ?? '' );
						}
					}

					// Source-language detection for block-level MT: use the *edited
					// post's* language, not the admin router language. Editing the
					// FR sibling of an EN post in a FR admin session must still
					// translate FROM FR (what's in the editor) TO the picked target.
					$post_source_lang = '';
					$edited_post      = get_post();

					if ( $edited_post instanceof \WP_Post && $cache_svc !== null ) {
						$manager   = new Translation\PostTranslationManager(
							$cache_svc,
							$plugin->get( 'settings' )
						);
						$post_lang = $manager->detect_post_language( (int) $edited_post->ID );

						if ( $post_lang && isset( $post_lang->slug ) ) {
							$post_source_lang = (string) $post_lang->slug;
						}
					}

					// "MT ready" = setting ON and the *selected* provider has its
					// API key. Anything less and we can't actually translate, so
					// the UI hides the submenu in favour of a setup-prompt link.
					// Using the active-provider check (not "any provider") means a
					// user with a wp-config DeepL key but the dropdown still on
					// "-- Select --" sees the setup prompt instead of buttons that
					// would fail at request time with an empty-provider error.
					$mt_ready = false;

					if ( $plugin->get( 'settings' )->mt_enabled() && $cache_svc !== null ) {
						$mt_service = new MachineTranslation\TranslationService(
							$plugin->get( 'settings' ),
							$cache_svc
						);
						$mt_ready   = $mt_service->is_active_provider_ready();
					}

					// Deep-link to the MT settings subtab so the actionable
					// "Set up machine translation" menu item lands the user in
					// the right place instead of the generic settings page.
					$mt_settings_url = admin_url( 'admin.php?page=perflocale-settings&tab=addons&subtab=machine-translation' );

					// Sibling-aware toolbar. When the open post is a
					// translation of a source-language sibling, the per-block menu
					// flips from "Translate to <X>" (multi-target replace-in-place)
					// to a single "Fill in from <source-lang> source" action.
					//
					// Resolve the source post + default language slug once, server-
					// side, so the JS doesn't need to make extra REST calls just to
					// detect sibling status. `is_sibling` is true iff the post's
					// language ≠ default language AND a translation group with a
					// source sibling exists.
					$default_language           = $lang_repo !== null ? $lang_repo->get_default() : null;
					$source_post_id_for_sibling = 0;
					$is_sibling                 = false;

					$default_lang_slug = ( $default_language && ! empty( $default_language->slug ) )
					? (string) $default_language->slug
					: '';

					if (
					$default_lang_slug !== ''
					&& $post_source_lang !== ''
					&& $post_source_lang !== $default_lang_slug
					&& $edited_post instanceof \WP_Post
					&& $cache_svc !== null
					) {
						try {
							$group_repo   = new Database\Repository\TranslationGroupRepository( $cache_svc );
							$translations = $group_repo->get_translations( (int) $edited_post->ID, \PerfLocale\Enum\ObjectType::Post );

							foreach ( (array) $translations as $link ) {
								if ( (int) ( $link->language_id ?? 0 ) !== (int) $default_language->id ) {
									continue;
								}

								$candidate_id = (int) ( $link->object_id ?? 0 );

								if ( $candidate_id > 0 && get_post( $candidate_id ) instanceof \WP_Post ) {
									$source_post_id_for_sibling = $candidate_id;
									$is_sibling                 = true;
									break;
								}
							}
						} catch ( \Throwable $e ) {
							// Sibling detection is a non-critical UX hint; never
							// fail the editor load over a translation-group lookup.
							$is_sibling = false;
						}
					}

					wp_localize_script(
						'perflocale-block-toolbar',
						'perflocaleBlockToolbar',
						[
							'languages'       => $languages,
							'currentLang'     => $current,
							'postSourceLang'  => $post_source_lang,
							'mtReady'         => $mt_ready,
							'mtSettingsUrl'   => $mt_settings_url,
							// Active provider id (e.g. "deepl", "google"). Pre-resolved
							// to the display name client-side; null when MT is off so
							// the toolbar can fall back to a generic label without
							// pretending a provider is configured.
							'mtProvider'      => $mt_ready ? (string) $plugin->get( 'settings' )->get_mt_provider() : '',
							// Sibling-detection payload.
							'isSibling'       => $is_sibling,
							'sourceLang'      => $default_lang_slug,
							'sourcePostId'    => $source_post_id_for_sibling,
							'targetPostId'    => ( $edited_post instanceof \WP_Post ) ? (int) $edited_post->ID : 0,
							'supportedBlocks' => [
								'core/paragraph',
								'core/heading',
								'core/list-item',
								'core/quote',
								'core/pullquote',
								'core/button',
								'core/preformatted',
								'core/verse',
								'core/code',
								// Disclosure widget (WP 6.5+): translates the
								// `summary` attribute. The collapsible body lives
								// in inner blocks which are independently
								// translatable per their own block type.
								'core/details',
							],
							'i18n'            => [
								'translating'  => __( 'Translating…', 'perflocale' ),
								/* translators: %s: target language name */
								'translatedTo' => __( 'Translated to %s', 'perflocale' ),
								'wrapTitle'    => __( 'Show only for certain languages', 'perflocale' ),
								'setUpMt'      => __( 'Set up machine translation…', 'perflocale' ),
							],
						]
					);
				}
			);
		}

		// CDN Cache-Tag emitter — only instantiate when enabled so sites
		// without a CDN pay zero cost (no send_headers hook registered).
		if ( $plugin->get( 'settings' )->cdn_cache_tags_enabled() ) {
			$plugin->register(
				'cache_tag_emitter',
				fn() => new Frontend\CacheTagEmitter(),
				true
			);
		}

		// Content change detector — flags translations when source changes.
		// Hooks fire only on save_post/edited_term, so it's gated to write
		// contexts (no work on a frontend GET).
		$plugin->register(
			'content_change_detector',
			fn( Plugin $p ) => new Translation\ContentChangeDetector(
				$p->get( 'settings' ),
				$p->get( 'cache' ),
			),
			Helper::is_write_context()
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

		// Translator role and caps. The role/caps hooks only fire in
		// admin/CLI context, so skip the eager boot on frontend GETs.
		$plugin->register(
			'translator_role',
			fn() => new Admin\TranslatorRole(),
			Helper::is_write_context()
		);

		// User deletion, however, is NOT admin-only: membership and
		// account-management plugins delete users straight from a front-end
		// POST, where the service above is never constructed and the
		// created_by anonymisation would silently never run — leaving the
		// deleted user's ID on background-job rows. Wire the three deletion
		// hooks directly instead: they cost three add_action() calls and
		// instantiate nothing until a user is actually deleted.
		// (wpmu_delete_user is separate because the network-admin path does
		// not fire delete_user.)
		$anonymize_jobs = static function ( $user_id ): void {
			Background\JobState::anonymize_for_user( (int) $user_id );
		};

		add_action( 'delete_user', $anonymize_jobs );
		add_action( 'wpmu_delete_user', $anonymize_jobs );

		// WordPress Privacy API integration: exporter + eraser + Policy Guide
		// text. All three hooks fire inside the WP privacy admin flows only;
		// the service has no frontend work, so we don't need to instantiate
		// or wire it on visitor pageviews.
		$plugin->register(
			'privacy_integration',
			fn( Plugin $p ) => new Admin\PrivacyIntegration( $p->get( 'settings' ) ),
			Helper::is_write_context()
		);

		// Generic Customizer integration - floating language switcher for any theme.
		$plugin->register(
			'customizer_integration',
			fn() => new Frontend\CustomizerIntegration(),
			true
		);

		// Theme builder integrations must register early (before Customizer reads items).
		// The addon registry boots at plugins_loaded:20 which is too late for builder APIs
		// that read their component lists during after_setup_theme.
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

			// This early path boots ahead of AddonRegistry and then marks the
			// addon booted, so the registry's boot loop skips it — including
			// its disabled-list and quarantine gates. Honour both here so the
			// operator toggle and the auto-quarantine counter apply to theme
			// addons exactly as they do to every other addon. Both options are
			// autoloaded, so these reads add no queries.
			if ( in_array( $addon_id, Addon\AddonRegistry::get_disabled(), true ) ) {
				continue;
			}

			$failures  = (array) get_option( 'perflocale_addon_failures', [] );
			$threshold = (int) apply_filters( 'perflocale/addons/quarantine_threshold', 3 );

			if ( $threshold > 0 && (int) ( $failures[ $addon_id ] ?? 0 ) >= $threshold ) {
				continue;
			}

			// is_compatible() and boot() are third-party code; a throw here
			// would otherwise be an uncaught sitewide fatal that never feeds
			// the quarantine counter. Mirror AddonRegistry::boot_addons().
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
					$e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
				);
				continue;
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
		//
		// PostQueryFilter + TermQueryFilter hook WP_Query / get_terms to
		// restrict results to the current language. They MUST run in admin
		// too - otherwise the Categories metabox, Posts nav-menu picker,
		// etc. all show terms/posts from every language mixed together.
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

		// Swap post→term assignments that end up with a wrong-language
		// term_id (classic-editor tag box submits tag NAMES, not IDs, so
		// term_exists() can resolve to a same-named sibling in another
		// language and silently attach the wrong term).
		// The lone hook (set_object_terms) only fires when terms are
		// assigned to objects, which always happens in admin / REST / CLI
		// write contexts — never on a visitor frontend GET. Demote to a
		// context guard so the service isn't instantiated on every public
		// pageview.
		$plugin->register(
			'term_assignment_filter',
			fn( Plugin $p ) => new Translation\TermAssignmentFilter(
				$p->get( 'cache' ),
				$p->get( 'settings' ),
			),
			Helper::is_write_context()
		);

		// ---- Context-specific services ----

		if ( is_admin() && ! wp_doing_ajax() ) {
			self::register_admin_services( $plugin );
			// Surface addon migration + uninstall errors in admin notices.
			\PerfLocale\Addon\AddonMigrationErrors::register_hooks();
		}

		// Admin AJAX handlers that need to work during wp_doing_ajax().
		// AdminController and TermMetaBox are not loaded during AJAX (by
		// design), so these lightweight handlers are registered directly.
		if ( wp_doing_ajax() ) {
			// Quick Edit language save — fires from `inline-save` AJAX in
			// edit.php. The full PostListColumns service is admin-only
			// (gated above), but its `save_quick_edit_language` save_post
			// handler MUST be wired during AJAX or every Quick Edit
			// language change is silently dropped. Reuse the eager service
			// registration so a single class owns the hook + nonce check.
			$plugin->register(
				'post_list_columns',
				fn( Plugin $p ) => new Admin\PostListColumns(
					$p->get( 'settings' ),
					$p->get( 'cache' ),
				),
				true
			);

			// Same problem, term flavour: `inline-save-tax` AJAX fires
			// `edited_term` on save, but the term Quick Edit hooks (in
			// TermListColumns) only register inside register_admin_services()
			// which is gated on `! wp_doing_ajax()`. Register the term list
			// columns service eagerly during AJAX too so categories, tags,
			// product_cat, and any registered translatable taxonomy persist
			// language changes from inline editing.
			$plugin->register(
				'term_list_columns',
				fn( Plugin $p ) => new Admin\TermListColumns(
					$p->get( 'settings' ),
					$p->get( 'cache' ),
				),
				true
			);

			// Term language save - fires when adding terms via AJAX (add-tag).
			// TermMetaBox is not instantiated during AJAX, so we handle it here.
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

								if ( ! current_user_can( 'manage_categories' ) ) {
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

			add_action(
				'wp_ajax_perflocale_save_hidden_columns',
				static function (): void {
					// Use the default $die=true behaviour so an invalid nonce
					// terminates the request before any input is read. This is
					// the canonical wp.org-reviewer-friendly pattern.
					check_ajax_referer( 'perflocale_hidden_columns', '_wpnonce' );

					// Per-user UI state - any authenticated user that reaches
					// the Strings screen needs to be able to hide columns.
					// `read` is the minimum authenticated capability.
					if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
						wp_send_json_error( 'Insufficient permissions.' );
					}

					// Cap the payload so an attacker can't flood user_meta with a
					// multi-megabyte JSON string. sanitize_text_field() is safe
					// to apply to the JSON envelope here: it strips tags/control
					// chars but leaves `[`, `]`, `{`, `}`, `"`, `,`, `:` intact.
					// Per-element sanitization (`sanitize_key`) runs after decode.
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

			// Generic hidden-columns saver used by the Translations + Assignments
			// pages. Client sends `meta_key` along with the nonce/payload so the
			// same handler can route to the right per-page user-meta bucket. The
			// meta_key is strictly allow-listed below to prevent writing into
			// arbitrary user-meta keys.
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

					// Default $die=true so an invalid nonce terminates the request
					// before any input is read. Canonical wp.org-reviewer pattern.
					check_ajax_referer( $allowed[ $meta_key_raw ], '_wpnonce' );

					if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
						wp_send_json_error( 'Insufficient permissions.' );
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

		// RTL stylesheet twins: one late pass over every registered
		// perflocale* style instead of add_data calls at a dozen
		// registration sites. 999 so every page/addon/VE registration has
		// happened; no-op on LTR locales.
		//
		// Registered OUTSIDE the frontend/admin split below: these used to
		// live in register_frontend_services(), which only runs when
		// `! is_admin() || wp_doing_ajax()`, so the admin_enqueue_scripts
		// hook was never attached on a normal wp-admin request and every
		// shipped -rtl.css twin was dead in the admin. Each hook is inert in
		// the context where its action never fires, so registering both
		// unconditionally is free.
		add_action( 'wp_enqueue_scripts', [ Helper::class, 'register_rtl_styles' ], 999 );
		add_action( 'admin_enqueue_scripts', [ Helper::class, 'register_rtl_styles' ], 999 );

		if ( ! is_admin() || wp_doing_ajax() ) {
			self::register_frontend_services( $plugin );
		} else {
			// Admin (non-AJAX) still needs the switcher WIDGET + shortcode
			// registered: Appearance → Widgets, the block-widgets Legacy
			// Widget picker, and the customizer all enumerate widget types
			// in plain admin requests — without this the switcher widget
			// doesn't exist there and can never be added or managed.
			// Registration is cheap (one widgets_init + one shortcode); the
			// heavy frontend services stay frontend-only.
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

		// Exchange rate sync AJAX - registered directly in Bootstrap so it
		// works regardless of whether the WooCommerce addon boots successfully.
		// The handler itself checks WooCommerce availability at execution time.
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
		}

		// REST API controllers - registered on rest_api_init.
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

		// Invalidate the edge-config cache whenever languages or settings
		// change. Cheap no-op when the feature is off - just removes a
		// cache entry that was never written.
		add_action( 'perflocale/language/added', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/language/updated', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/language/deleted', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/languages/reordered', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/default_language/changed', [ Api\ConfigController::class, 'invalidate' ] );
		add_action( 'perflocale/settings/updated', [ Api\ConfigController::class, 'invalidate' ] );

		// When string translation mode is switched to files, regenerate all
		// .l10n.php files immediately so they exist before the next frontend
		// request. Runs synchronously in the same admin/REST request that
		// saved the settings - acceptable for the typically small number of
		// strings. Clears the regenerating transient on completion so
		// TranslationFileLoader stops retrying after files are ready.
		add_action(
			'perflocale/strings/regenerate_files',
			static function ( Cache\CacheManager $cache ): void {
				$generator = new Strings\TranslationFileGenerator( $cache );
				$generator->generate_all();
				delete_transient( 'perflocale_strings_regenerating' );
			}
		);

		// Webhook delivery runs on WP-Cron so the originating request
		// isn't blocked on slow endpoints or bad DNS.
		add_action(
			self::WEBHOOK_DELIVERY_HOOK,
			static function ( string $webhook_id, string $event, array $data, string $timestamp ): void {
				( new Api\WebhookController() )->deliver_webhook( $webhook_id, $event, $data, $timestamp, 1 );
			},
			10,
			4
		);

		// Drains the coalesced delivery queue on the WP-Cron engine — one
		// tick dispatches a bounded batch of the deliveries a bulk fan-out
		// buffered, so the autoloaded `cron` option holds one event instead
		// of thousands.
		add_action(
			self::WEBHOOK_DRAIN_HOOK,
			static function (): void {
				( new Api\WebhookController() )->drain_webhooks();
			}
		);

		// Retry cron hook for failed webhook deliveries (attempts 2+) and
		// breaker-deferred re-deliveries (same attempt, deferral count 7th).
		add_action(
			self::WEBHOOK_RETRY_HOOK,
			static function ( string $webhook_id, string $event, array $data, string $timestamp, int $attempt, string $delivery_id = '', int $breaker_deferrals = 0 ): void {
				( new Api\WebhookController() )->deliver_webhook( $webhook_id, $event, $data, $timestamp, $attempt, $delivery_id, $breaker_deferrals );
			},
			10,
			7
		);

		// Fan out plugin translation/content actions to subscribed webhooks.
		// Lazy: registers the forwarding hooks only after plugins_loaded so
		// that get_option() is safe to call and no-op for sites without any
		// subscribers. The WebhookController class is only autoloaded
		// (reflection + file parse, ~50–100µs first time) when actually
		// needed - do the empty-option check here so the hook and the class
		// never touch the runtime on single-site installs that have no
		// webhooks configured.
		//
		// Gated to write-contexts because every event the dispatchers listen
		// to (perflocale/translation/created|linked|status_changed) fires
		// exclusively from admin / REST / CLI / CRON translation operations. A
		// frontend GET will never invoke them, so paying the ~140 µs
		// get_option roundtrip on every visitor pageview is pure waste.
		//
		// wp_doing_cron() is load-bearing: background jobs (bulk translate,
		// scheduled publishes) execute inside the WP-Cron worker, where NONE
		// of the other conditions hold — without it, every translation created
		// by a background job silently fired no webhooks at all.
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
					// Single-site only: on multisite the boot blog's option
					// says nothing about the blogs this request may
					// switch_to_blog() into — an event fired under a switched
					// blog must still reach THAT blog's subscribers, so the
					// dispatchers always register and fire_webhook() no-ops
					// per blog via its own webhook lookup instead.
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

		// Multisite: clear per-request static caches when switching blog
		// context so cross-site data doesn't leak into the new blog's
		// queries. UrlConverter already resets its own cache; add the
		// post/term query filter caches too.
		//
		// Gated behind is_multisite() because (a) `switch_to_blog()` is a
		// no-op on single-site WP yet still fires the `switch_blog` action,
		// so without the gate any third-party plugin that calls
		// switch_to_blog(get_current_blog_id()) (WooCommerce / AIOWPSecurity
		// / etc. do this) would needlessly wipe our per-request caches
		// mid-request — costing both work and correctness; (b) skipping
		// these add_action calls on single-site shaves ~20 µs from boot.
		//
		// NOTE: LanguageRouter hooks its OWN switch_blog listener
		// (maybe_reset_on_switch) in register_hooks() — do NOT add a
		// raw ::reset here or same-blog switch_to_blog() calls (common in
		// WooCommerce, AIOWPSecurity, etc.) will wipe the detected-language
		// state mid-request and break the switcher/hreflang/PostQueryFilter.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ Translation\PostQueryFilter::class, 'reset_static_caches' ] );
			add_action( 'switch_blog', [ Translation\TermQueryFilter::class, 'reset_static_caches' ] );
			add_action( 'switch_blog', [ Translation\PostTranslationManager::class, 'reset_static_caches' ] );
			add_action( 'switch_blog', [ Database\Repository\TranslationGroupRepository::class, 'reset_static_caches' ] );
			add_action( 'switch_blog', [ Database\Repository\SlugTranslationRepository::class, 'reset_static_caches' ] );
			add_action( 'switch_blog', [ Seo\SitemapIntegration::class, 'reset_caches' ] );
			add_action( 'switch_blog', [ Seo\SchemaEnricher::class, 'reset' ] );
			add_action( 'switch_blog', [ Translation\MediaTranslationManager::class, 'reset_registered' ] );
			// Addon settings are keyed per-blog (one autoloaded option per
			// site), so the in-memory cache MUST drop on switch_blog or
			// addons would read the wrong site's values after a switch.
			add_action( 'switch_blog', [ Addon\AddonSettings::class, 'reset_static_caches' ] );

			// Helper memoises current_language() per request. Templates on
			// the new blog must see the new blog's current language —
			// without this, a theme calling perflocale()->locale() after
			// switch_to_blog() reads the previous blog's value.
			add_action( 'switch_blog', [ Helper::class, 'reset_static_caches' ] );

			// String translation is a shared singleton holding a per-BLOG memo.
			// Only one of these two services is registered (database vs files
			// mode), so resolve whichever exists and drop its memo on a blog
			// switch — otherwise blog 2 serves blog 1's strings.
			add_action(
				'switch_blog',
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
				}
			);

			// Settings is a DI singleton that loads its per-blog options
			// blob ONCE on first access and memoises it for the rest of the
			// request. Without an explicit reset, switch_to_blog() leaves
			// the memo pointing at blog A's url_mode / translatable_post_types
			// / hide_default_prefix / mt_provider, which then drive blog B's
			// routing and translation decisions for any code that reads
			// settings after the switch. Resolve from the container lazily
			// so we don't instantiate Settings on blogs that never read it.
			add_action(
				'switch_blog',
				static function (): void {
					try {
						$plugin = Plugin::get_instance();
						if ( $plugin->has( 'settings' ) ) {
							$plugin->get( 'settings' )->reset_cache();
						}
					} catch ( \Throwable $e ) {
						// Mid-deactivation / container missing — fail closed.
					}
				}
			);
		}

		// Mirror the same data-derived invalidation pattern used by
		// LanguageRouter / UrlConverter / SlugManager: when a language is
		// added / updated / renamed / deleted within the same request,
		// the slug-repo's per-request `has_any_slugs_memo` can outlive
		// its underlying truth (e.g. deleting the last language with
		// translated slugs empties the table but keeps the memo TRUE).
		// Two lines here keep all four routing-layer classes on one
		// consistent invalidation pattern.
		$reset_slug_repo = [ Database\Repository\SlugTranslationRepository::class, 'reset_static_caches' ];

		add_action( 'perflocale/language/added', $reset_slug_repo );
		add_action( 'perflocale/language/updated', $reset_slug_repo );
		add_action( 'perflocale/language/slug_renamed', $reset_slug_repo );

		// Helper's per-request current-language memo (added so themes
		// calling perflocale()->locale() in a template loop don't re-hit
		// the DI container per call). Same data-derived invalidation
		// triggers as the slug repo above — a language slug rename mid-
		// request would otherwise leave Helper returning the old slug.
		$reset_helper = [ Helper::class, 'reset_static_caches' ];

		add_action( 'perflocale/language/added', $reset_helper );
		add_action( 'perflocale/language/updated', $reset_helper );
		add_action( 'perflocale/language/slug_renamed', $reset_helper );
		add_action( 'perflocale/language/deleted', $reset_helper );
		add_action( 'perflocale/language/deleted', $reset_slug_repo );

		// Glossary entries reference (source_language_id, target_language_id).
		// When a language is renamed/deleted, the cached per-(src,tgt) entry
		// lists need to be invalidated so the next read sees the fresh DB
		// state (including any cascade-deleted rows from
		// LanguageRepository::delete). Mirrors the slug-repo pattern above.


		// Translation Memory cache key includes language IDs. When a
		// language is renamed/deleted, the cascade in
		// LanguageRepository::delete drops the TM rows but the per-


		// Scheduled cleanup for abandoned import temp files.
		//
		// Path-traversal guard: the hook is publicly callable via
		// do_action(), so without a realpath + prefix check any caller
		// could pass an arbitrary path and trigger wp_delete_file() on it.
		// Mirrors the same canonical pattern used by DataImportJob's
		// is_safe_upload_path() and the export-download endpoint.
		add_action(
			'perflocale_cleanup_temp_import',
			static function ( string $file ): void {
				if ( $file === '' ) {
					return;
				}

				// Path-traversal guard: only files inside `uploads/perflocale/temp/`
				// may be deleted via this publicly-callable hook.
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

		// Daily reaper for expired lock rows - crashed requests leak a
		// row in wp_options, which accumulates over time. One DELETE per
		// day keeps the options table lean. Wrap so the action hook
		// discards reap_expired()'s int return (number of rows cleaned).
		add_action(
			self::LOCK_CLEANUP_HOOK,
			static function (): void {
				Concurrency\Lock::reap_expired();
			}
		);

		// Action Scheduler integration — only register when AS is loaded
		// (woocommerce/jetpack/many others ship it; standalone too). On a
		// vanilla site without AS, every `add_action( 'action_scheduler_*' )`
		// is wasted: WP stores the callback in $wp_filter, AS never fires,
		// callback never runs.
		if ( class_exists( 'ActionScheduler' ) || class_exists( 'ActionScheduler_Store' ) ) {
			// Action Scheduler's default "action failure" timeout is 5
			// minutes — actions that haven't returned in that window are
			// re-claimed and re-fired. Our long-running migration / import /
			// scan jobs can legitimately run for many minutes, so without
			// raising this filter AS would silently retry while the original
			// worker is still alive (the per-job lock blocks the second
			// instance from running, but the operator sees ghost "failed
			// (timed out)" actions in AS admin). Match `STUCK_TIMEOUT` so AS
			// gives up on the same cadence as our own watchdog.
			add_filter(
				'action_scheduler_failure_period',
				static function (): int {
					return Background\JobState::STUCK_TIMEOUT;
				}
			);

			// Bridge Action Scheduler failure states to JobState. When a worker
			// triggers a PHP fatal, hits AS's timeout, or fires the shutdown
			// monitor, AS marks its own action failed but our JobState row stays
			// `running` until our watchdog catches it ~6h later. These three
			// hooks close that gap so the Jobs admin UI matches *Tools →
			// Scheduled Actions* within one cron tick.
			//
			// Multisite note: AS uses `$wpdb->prefix . 'actionscheduler_*'`
			// tables (per-blog, NOT $base_prefix), and AS's queue runner is
			// registered per blog. The failure hook therefore fires in the
			// SAME blog context that owns the action — no switch_to_blog is
			// needed here. JobState options also live on that blog. Verified
			// 2026-05-18: action_id is per-blog, so a stray cross-blog handler
			// would resolve action_id to a DIFFERENT action in the wrong blog.
			$bridge_as_failure = static function ( $action_id, $exception_or_timeout = null ) {
				if ( ! class_exists( '\\ActionScheduler' ) ) {
					return;
				}
				try {
					$action = \ActionScheduler::store()->fetch_action( (int) $action_id );
				} catch ( \Throwable $e ) {
					return;
				}
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_group' )
				|| $action->get_group() !== Background\ActionSchedulerRunner::GROUP ) {
					return;
				}
				$as_args = method_exists( $action, 'get_args' ) ? $action->get_args() : [];
				$job_id  = isset( $as_args[0] ) ? (string) $as_args[0] : '';
				if ( $job_id === '' || ! Background\JobState::is_safe_id( $job_id ) ) {
					return;
				}
				$state = Background\JobState::get( $job_id );
				if ( ! $state ) {
					return;
				}
				$status = (string) ( $state['status'] ?? '' );
				if ( in_array( $status, [ 'complete', 'failed', 'canceled' ], true ) ) {
					return;
				}
				$detail = '';
				if ( $exception_or_timeout instanceof \Throwable ) {
					$detail = ': ' . $exception_or_timeout->getMessage();
				} elseif ( is_array( $exception_or_timeout ) && isset( $exception_or_timeout['message'] ) ) {
					$detail = ': ' . (string) $exception_or_timeout['message'];
				}
				Background\JobState::mark_failed(
					$job_id,
					/* translators: %s is an optional AS-supplied error message starting with ": ". */
					sprintf( __( '[AS marked failed] Worker terminated before completion%s', 'perflocale' ), $detail )
				);
			};
			add_action( 'action_scheduler_failed_execution', $bridge_as_failure, 10, 2 );
			add_action( 'action_scheduler_failed_action', $bridge_as_failure, 10, 2 );
			add_action( 'action_scheduler_unexpected_shutdown', $bridge_as_failure, 10, 2 );
		}

		// Weekly GC for `perflocale_mt_usage_YYYY_MM` options. The MT usage
		// admin UI only ever reads the current month + the last 12 months;
		// older rows accumulate forever otherwise. AbstractProvider's
		// static helper does the LIKE-scan + per-row delete with a 13-month
		// retention window. Scheduled per-blog (MT usage is per-blog).
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

		// Daily GC for the background-jobs system: removes per-job options
		// for completed/failed/canceled jobs older than 24h. On multisite,
		// the AS table is network-shared so we pass the dispatching blog id
		// as an arg and `switch_to_blog` inside the handler — without this,
		// only one blog's GC ever runs.
		add_action(
			'perflocale_jobs_gc',
			static function ( $blog_id = 0 ): void {
				Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'gc' ] );

				// Same daily window also sweeps empty translation_groups rows
				// (orphan rows whose links are all gone — safety net for any
				// write path that bypasses unlink_by_object_id). 1000-row cap
				// per tick keeps the query bounded.
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

				// And the daily safety-net sweep for abandoned temp / export
				// files. The per-import 24h scheduled-cleanup event handles
				// the happy path, but if cron is disabled or a scheduled
				// event is lost, files would linger forever otherwise.
				// Default: anything 7+ days old.
				Background\JobState::run_recurring_for_blog(
					(int) $blog_id,
					static function (): void {
						if ( method_exists( '\\PerfLocale\\Helper', 'gc_stale_upload_files' ) ) {
							\PerfLocale\Helper::gc_stale_upload_files();
						}
					}
				);

				// Expired perflocale_* transients sweep. WP core's
				// delete_expired_transients() runs lazily on get_transient()
				// and via the twice-daily wp_scheduled_delete cron; neither
				// catches our transients reliably if nothing reads them or
				// if WP-Cron is disabled. Bounded single-statement DELETE
				// joining _transient_timeout_<key> rows (which carry the
				// expiry) against the corresponding _transient_<key> rows.
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

				// Strings mark-and-sweep: delete strings rows whose
				// last_seen_at is older than the retention window
				// (default 90 days, filter perflocale/strings/stale_retention_days).
				// Covers the disabled-plugin / removed-theme cleanup case.
				// Cascades to string_translations in the same call.
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

				// Defensive orphan-sweep for string_translations: catches
				// any row whose parent strings.id has been deleted by a
				// path that bypassed the gc_stale_strings() cascade.
				// Single-statement LEFT JOIN; cheap when nothing to do.
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


		// Hourly stuck-job watchdog — same multisite consideration as GC.
		add_action(
			'perflocale_jobs_watchdog',
			static function ( $blog_id = 0 ): void {
				Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'watchdog' ] );
			}
		);


		// Schedule both recurring events under the current engine.
		//
		// Drift-detection probe — runs JobRunnerFactory::pick() (which
		// probes Action Scheduler availability) and queries the schedule
		// store on every request it fires on. Costs ~1 ms. Hooked to
		// `admin_init` rather than plain `init` so visitors don't pay for
		// it on every frontend pageview; drift is only actionable from
		// an admin context. Direct setting changes still trigger
		// re-migration through `maybe_remigrate_engine` below.
		//
		// Timing: `admin_init` fires inside `init` priority 10, which is
		// still after `action_scheduler_init` (AS hooks init@1), so the
		// engine selector returns the correct value.
		//
		// Skip the registration entirely on non-admin requests — the
		// `admin_init` hook never fires outside wp-admin so the callback
		// is unreachable, but the add_action call itself is still ~5µs
		// of $wp_filter bookkeeping we can avoid on visitor pageviews.
		if ( is_admin() ) {
			add_action( 'admin_init', [ self::class, 'ensure_recurring_schedules_throttled' ] );
		}

		// Engine-flip migration: when the operator changes
		// `background_engine` (auto <-> force_wp_cron), unschedule the
		// recurring events from both engines and re-schedule them under
		// the new engine. Without this, in-flight schedules stay on the
		// old engine until they naturally expire - mixed-state until the
		// next interval.
		add_action( 'update_option_perflocale_settings', [ self::class, 'maybe_remigrate_engine' ], 10, 2 );

		// Bust the Settings::$settings in-memory cache when the option is
		// updated. Without this, REST/admin code that reads a setting
		// AFTER persisting it in the same request still sees the prior
		// value (Settings::load() memoises on first access). The hook is
		// cheap — reset_cache is an array assignment — and fires only on
		// actual settings saves, which are infrequent.
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

		// Settings-flip → immediate (un)schedule. ensure_recurring_schedules()
		// is idempotent and figures out the current desired state from the
		// freshly-saved settings (relies on the cache-reset above at priority 1
		// and the engine remigrate at priority 10 having already run). Without
		// this, flipping a recurring feature off via REST / CLI leaves its
		// cron firing until the next admin pageview triggers the existing
		// `admin_init` ensure call. Cheap (one option read + one
		// scheduled-event lookup per recurring hook); only runs on actual
		// settings saves.
		add_action( 'update_option_perflocale_settings', [ self::class, 'ensure_recurring_schedules' ], 20 );

		// One-shot resume after activation. The deactivation hook removes
		// every queued worker event, but it intentionally leaves the
		// JobState rows in place so the operator's history survives
		// reactivation. On reactivate, Activator::activate() schedules a
		// `perflocale_resume_jobs` event for time() — this handler scans
		// the index and re-enqueues any `queued` or `running` rows via
		// the now-fully-initialised runner factory. Wrap so the action
		// hook discards resume()'s int return (number resumed).
		add_action(
			'perflocale_resume_jobs',
			static function (): void {
				Background\Resumer::resume();
			}
		);

		// Register Tier-2 background workers. Each call binds the worker
		// hook (`perflocale_job_run_<type>`) to WorkerRegistry::run(), so
		// dispatched jobs land in the same lock + cap-check + retry pipeline.
		//
		// Factory closures (not instances) so workers get a fresh object per
		// invocation — stateless across jobs.
		Background\WorkerRegistry::register(
			'data_import',
			static fn(): Background\AbstractJob => new Background\Jobs\DataImportJob()
		);
		Background\WorkerRegistry::register(
			'data_export',
			static fn(): Background\AbstractJob => new Background\Jobs\DataExportJob()
		);
		// One-off migration importers (WPML / Polylang / TranslatePress).
		// A migration is dispatched from wp-admin and executed by cron or
		// Action Scheduler — never by a visitor pageview — so the worker
		// hooks are bound only in job-capable contexts. On the hot frontend
		// path this skips three add_action() calls and, more importantly,
		// keeps the importer classes out of any code path a visitor can
		// reach. The factories are closures either way, so the importer
		// classes themselves are still only autoloaded when a job runs.
		if ( Helper::is_write_context() ) {
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
		}
		Background\WorkerRegistry::register(
			'string_scan',
			static fn(): Background\AbstractJob => new Background\Jobs\StringScanJob()
		);
		Background\WorkerRegistry::register(
			'bulk_translate',
			static fn(): Background\AbstractJob => new Background\Jobs\BulkTranslateJob()
		);

		// Pre-dispatch MT budget gate: veto a bulk MT dispatch whose estimated
		// character volume would blow past the monthly cap BEFORE any provider
		// spend, instead of letting the job burn quota until the per-post cap
		// check throws mid-run (posts) or never throws at all (strings — that
		// path is tracked but historically not cap-blocked). The estimate uses
		// the jobs' own skip-existing semantics via CostEstimator, so already-
		// translated pairs don't inflate the number. Opt-out setting for sites
		// that prefer the old run-until-cap behaviour.
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
					$string_job = $job instanceof Background\Jobs\BulkStringTranslateJob
						? $job
						: new Background\Jobs\BulkStringTranslateJob();
					$estimate   = $estimator->estimate_strings(
						$string_job->resolve_ids_for_estimate( $args ),
						(array) ( $args['target_lang_ids'] ?? [] )
					);
				}

				// Only deny when there is ACTUAL work to send: a dispatch whose
				// entire selection is already translated (chars=0) would be a
				// no-op job, and blocking it with an over-budget error is
				// confusing. The real over-cap case (chars>0) still denies.
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

		// Invalidate the AddonRegistry bootable-set transient whenever the
		// environment changes in a way that could flip a compat closure
		// (plugin activation, theme switch, plugin update). Registered
		// once at plugin load — the hooks live until the request ends and
		// are idempotent, so safe in every context.
		Addon\AddonRegistry::register_cache_invalidation();

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

		// Boot all eager services - this calls register_hooks() on each.
		// Priority 0 on init to run before most other plugins.
		add_action( 'init', [ $plugin, 'boot' ], 0 );

		// Abilities API (WP 6.9+) - disabled by default, enabled via filter.
		// Registers PerfLocale translation operations as discoverable abilities
		// for AI tools and external consumers. Zero overhead when disabled.
		/** @hook perflocale/abilities/enabled Enable the WordPress Abilities API integration. Default: false. */
		if ( apply_filters( 'perflocale/abilities/enabled', false ) ) {
			$registrar = new AbilitiesRegistrar( $plugin );
			add_action( 'wp_abilities_api_categories_init', [ $registrar, 'register_category' ] );
			add_action( 'wp_abilities_api_init', [ $registrar, 'register_abilities' ] );
		}

		// Register the [perflocale_language] utility shortcode.
		add_action( 'init', [ self::class, 'register_language_shortcode' ], 20 );

		// Expose the per-post opt-out flags to the block editor: the
		// Gutenberg document panel reads/writes them through the REST meta
		// channel (the classic Translations metabox has its own fields).
		// Priority 20 so custom post types registered at default priority
		// exist before the per-subtype registration.
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

				foreach ( $plugin->get( 'settings' )->get_translatable_post_types() as $post_type ) {
					register_post_meta( $post_type, Translation\ContentSync::SYNC_OPTOUT_META, $flag_args );
					register_post_meta( $post_type, Helper::SEO_EXCLUDE_META, $flag_args );
				}
			},
			20
		);


		/** @hook perflocale/loaded Fires after all PerfLocale services are registered. */
		do_action( 'perflocale/loaded', $plugin );
	}

	/**
	 * Option that stores the last-known ACTIVE engine (the value
	 * `JobRunnerFactory::pick()->get_engine_name()` returned the last
	 * time `ensure_recurring_schedules()` ran). Used to detect drift
	 * caused by Action Scheduler disappearing (WooCommerce / standalone
	 * AS plugin deactivated) or reappearing - cases that don't trigger
	 * the `update_option_perflocale_settings` hook, so
	 * `maybe_remigrate_engine()` doesn't catch them on its own.
	 *
	 * Autoloaded so the read is free (already cached in `alloptions`).
	 *
	 * @var string
	 */
	private const ACTIVE_ENGINE_OPTION = 'perflocale_active_engine';

	/**
	 * Timestamp of the last full recurring-schedule verification
	 * (autoloaded; written at most every 6 hours by the throttled path).
	 */
	private const SCHEDULES_VERIFIED_OPTION = 'perflocale_schedules_verified_at';

	/**
	 * Ensure the plugin's two recurring background events
	 * (`Concurrency\Lock::CLEANUP_HOOK` and `perflocale_jobs_gc`) are
	 * scheduled under the currently-active engine.
	 *
	 * Performs proactive engine-drift detection: if the engine has
	 * changed since this method last ran (e.g. Action Scheduler was
	 * disabled by deactivating WooCommerce, or re-enabled by installing
	 * it back), unschedule the recurring events from BOTH engines
	 * before re-enqueueing. Without this, a downgrade leaves stranded
	 * actions in the AS tables (harmless until AS comes back, then they
	 * fire alongside the new WP-Cron schedules and double-trigger our
	 * handlers).
	 *
	 * Called from init@20 on every plugin boot and from
	 * `maybe_remigrate_engine()` after the operator flips the
	 * `background_engine` setting.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules_throttled(): void {
		// The full verification below costs 4–8 ms per call under Action
		// Scheduler (each is_scheduled() is an AS table query) — too much
		// for EVERY admin_init. The engine-DRIFT check is cheap (one
		// autoloaded option read + factory pick), so it stays per-request:
		// drift forces the full run immediately. Otherwise the schedule
		// verification is a self-heal for externally-lost events and a
		// 6-hour cadence is plenty (settings saves still trigger the full
		// run synchronously via update_option_perflocale_settings@20).
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
	 * Full recurring-schedule verification + engine-drift migration. See the
	 * docblock above ensure_recurring_schedules_throttled() for the cadence;
	 * this method itself is unthrottled (settings saves and CLI call it
	 * directly for immediate effect).
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules(): void {
		$current_engine = Background\JobRunnerFactory::pick()->get_engine_name();
		$stored_engine  = (string) get_option( self::ACTIVE_ENGINE_OPTION, $current_engine );

		if ( $stored_engine !== $current_engine ) {
			// Drift detected. Most common cause is AS disappearing or
			// reappearing without a settings change. Wipe schedules in
			// BOTH engines so the stranded ones from the old engine
			// don't fire alongside the new ones we're about to create.
			//
			// Bonus: this also catches the case where the operator
			// changed `background_engine` while plugin code wasn't
			// running (CLI option update bypassing the
			// update_option_perflocale_settings hook).
			$blog_arg_for_drift = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];
			Background\BackgroundEvents::unschedule_recurring( self::LOCK_CLEANUP_HOOK );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc', $blog_arg_for_drift );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog', $blog_arg_for_drift );
			// Also clear any pre-fix (no-arg) schedules from older versions so
			// they don't fire alongside the new per-blog ones.
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc' );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog' );
			// The three newer recurring hooks are engine-bound as well — clear
			// both the per-blog and legacy no-arg forms so an engine flip
			// doesn't leave them running on the old back-end.
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc', $blog_arg_for_drift );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc' );
			// Resumer is one-shot, not recurring, but if a queued resume
			// event is pending under the old engine, an engine flip leaves
			// it stranded. Clear all of its scheduled instances in both
			// back-ends.
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

		// Multisite-safe: schedule with the current blog id as args so each
		// blog has its own scheduled action under AS (which uses a network-
		// shared table). The handler switches into the right blog before
		// reading per-blog state. WP-Cron is per-blog natively so this is
		// a no-op there.
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

		// Drop perflocale_mt_usage_YYYY_MM options older than 13 months.
		// One row per month per blog accumulates forever otherwise. Weekly
		// is plenty — the row never grows mid-month and the GC is cheap.
		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_mt_usage_gc', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_mt_usage_gc',
				time() + ( 30 * MINUTE_IN_SECONDS ),
				WEEK_IN_SECONDS,
				'weekly',
				$blog_arg
			);
		}


		// First call after install / after a `delete_option` (e.g. via
		// uninstall preserve-purge that wiped settings) - seed the
		// option so subsequent calls have a baseline to compare against.
		if ( get_option( self::ACTIVE_ENGINE_OPTION ) === false ) {
			update_option( self::ACTIVE_ENGINE_OPTION, $current_engine, true );
		}
	}

	/**
	 * Engine-flip migration hook. Compares the old and new
	 * `perflocale_settings` arrays; if `background_engine` changed
	 * (auto <-> force_wp_cron), cancels every recurring event on BOTH
	 * engines and re-schedules them. Without this, schedules created
	 * before the flip stay on the old engine until they naturally
	 * expire - mixed-state for one cycle.
	 *
	 * Also triggers a re-schedule of the exchange-rate-sync cron via
	 * `ExchangeRateSync::maybe_reschedule()` so WooCommerce stores
	 * with auto-sync enabled migrate immediately too.
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

		// Bust the Settings cache before scheduling. Settings::load()
		// memoises the option into `$this->settings` on first access -
		// without this reset, `JobRunnerFactory::pick()` would still see
		// the OLD engine when called from `BackgroundEvents::use_action_scheduler()`
		// during the re-schedule below, and our migration would re-stamp
		// the old engine's events.
		// Also forget the memoized runner: pick() may have resolved earlier in
		// this same request (admin_init schedule throttle), and the Settings
		// reset below can't reach an already-constructed runner instance —
		// the re-schedule would silently re-stamp events under the OLD engine.
		Background\JobRunnerFactory::reset_memo();

		$plugin = Plugin::get_instance();
		if ( $plugin->has( 'settings' ) ) {
			try {
				$settings = $plugin->get( 'settings' );
				if ( method_exists( $settings, 'reset_cache' ) ) {
					$settings->reset_cache();
				}
			} catch ( \Throwable $e ) {
				// Non-fatal — fall through to the re-schedule; worst case
				// the schedule lands under the old engine and gets fixed
				// on the next request.
				unset( $e );
			}
		}

		// Cancel both recurring events on both back-ends, then re-enqueue
		// under the new engine via the common scheduler helper. The
		// `enqueue_recurring()` call inside `ensure_recurring_schedules()`
		// reads the updated setting (this hook runs AFTER the option is
		// already written) and routes to AS or WP-Cron accordingly.
		// Also clear `perflocale_active_engine` so the drift-detector
		// inside `ensure_recurring_schedules()` re-seeds it under the
		// new engine.
		delete_option( self::ACTIVE_ENGINE_OPTION );
		$blog_arg_for_remig = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];
		Background\BackgroundEvents::unschedule_recurring( self::LOCK_CLEANUP_HOOK );
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc', $blog_arg_for_remig );
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog', $blog_arg_for_remig );
		// The MT-usage/TM GC + MT-quality-score recurring hooks are engine-bound
		// too; without unscheduling them here an engine flip strands them on the
		// old back-end (ensure_recurring_schedules re-creates them below).
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc', $blog_arg_for_remig );
		self::ensure_recurring_schedules();

		// Re-schedule the exchange-rate-sync cron too if WooCommerce
		// is loaded and the service is registered. `maybe_reschedule()`
		// unschedules then re-schedules via the same BackgroundEvents
		// helper, so it picks up the new engine automatically.
		if ( $plugin->has( 'exchange_rate_sync' ) ) {
			try {
				$ers = $plugin->get( 'exchange_rate_sync' );
				if ( method_exists( $ers, 'maybe_reschedule' ) ) {
					$ers->maybe_reschedule();
				}
			} catch ( \Throwable $e ) {
				// Best-effort - failure here is non-fatal; the next init
				// will reconcile state.
				unset( $e );
			}
		}
	}

	/**
	 * Register admin-only services.
	 *
	 * These are never instantiated on frontend requests.
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

		// Dashboard widget (translation overview). Eager so its
		// wp_dashboard_setup hook registers; the widget itself renders
		// only on the Dashboard screen, gated on a setting + capability.
		$plugin->register(
			'dashboard_widget',
			fn( Plugin $p ) => new Admin\DashboardWidget( $p->get( 'settings' ) ),
			true
		);

		// Surface quarantined addons in an admin notice.
		// Eager so admin_notices hooks register in time.
		$plugin->register(
			'quarantine_notice',
			fn() => new Admin\QuarantineNotice(),
			true
		);

		// WP Site Health integration - adds an info section + status tests
		// covering config, DB tables, rewrite rules, conflicts, MT usage,
		// FX staleness, translation files, and addon quarantine.
		$plugin->register(
			'site_health',
			fn() => new Admin\SiteHealth(),
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
	 * Register frontend-only services.
	 *
	 * These are never instantiated in admin (non-AJAX) context.
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

		// 301 redirector for renamed language slugs (`/en/...` → `/en-us/...`
		// after the admin renames `en` → `en-us`). No-op when the redirect
		// map is empty — single get_option() check on the frontend hot path.
		$plugin->register(
			'slug_redirector',
			fn() => new Frontend\SlugRedirector(),
			true
		);

		// Per-language date/time formats: hook into option_date_format /
		// option_time_format so every WP date render on the frontend
		// respects the active language's override automatically.
		$plugin->register(
			'locale_date_format',
			fn( Plugin $p ) => new Frontend\LocaleDateFormat( $p->get( 'router' ) ),
			true
		);

		// Content-Language HTTP header - W3C standard, one header() call per
		// frontend request. Gated by the `content_language_header` setting.
		$plugin->register(
			'content_language_header',
			fn( Plugin $p ) => new Frontend\ContentLanguageHeader(
				$p->get( 'router' ),
				$p->get( 'settings' ),
			),
			true
		);

		// View Transitions API emitter - experimental, opt-in. No cost when
		// disabled (service registers hooks but the emit() method bails
		// immediately on the setting check).
		$plugin->register(
			'view_transitions',
			fn( Plugin $p ) => new Frontend\ViewTransitionsEmitter(
				$p->get( 'settings' ),
			),
			true
		);

		// Speculation Rules API emitter - prerender translation targets on
		// switcher hover. Experimental, opt-in. Only emits when the current
		// page has translation alternates.
		$plugin->register(
			'speculation_rules',
			fn( Plugin $p ) => new Frontend\SpeculationRulesEmitter(
				$p->get( 'settings' ),
				$p->get( 'router' ),
				$p->get( 'url_converter' ),
			),
			true
		);

		// data-nosnippet wrapper around default-language fallback content.
		// Prevents Google from showing default-lang snippets under
		// non-default-lang URLs when missing_translation_action=show_default.
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

		// NOTE: Do NOT register PostQueryFilter / TermQueryFilter here —
		// they're in the always-registered section of boot() because admin
		// screens (Categories metabox, nav-menus Pages picker, etc.) need
		// them too. Re-adding would fatal from Plugin::register().
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * Only loaded when WP_CLI is defined and true.
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

		// Addon lifecycle subcommands (wp perflocale addon …). Registered
		// as a separate service so it eagerly calls WP_CLI::add_command
		// without being intertwined with the main PerfLocaleCommand.
		$plugin->register(
			'cli_addon',
			fn() => new Cli\AddonCommand(),
			true
		);

		// Per-addon settings subcommands (wp perflocale addon settings …).
		// Registered under its own command namespace so WP-CLI groups
		// `get` / `set` / `list` under the `settings` help layer.
		$plugin->register(
			'cli_addon_settings',
			fn() => new Cli\AddonSettingsCommand(),
			true
		);
	}

	/**
	 * Register REST API routes.
	 *
	 * Called on rest_api_init hook.
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

		// MT REST routes register UNCONDITIONALLY. Both handlers enforce
		// `mt_enabled()` at request time and return a graceful `mt_disabled`
		// 403 when off, so disabling MT mid-session no longer surfaces a
		// confusing `rest_no_route` 404 to clients (e.g. the Block Editor
		// toolbar caches the route at page load; if mt_enabled was flipped
		// off in another tab between page-load and click, the request 404'd).
		// Memory cost of always-loading two controllers + their permission /
		// route arrays: <1 KB. Worth the better failure UX.
		$controllers[] = new Api\MachineTranslateController();
		$controllers[] = new Api\BlockTranslateController();

		// Only register the public edge-config endpoint when edge
		// integration is explicitly enabled. Keeps the REST surface
		// minimal on sites that don't use a Worker / Edge runtime.
		if ( Plugin::get_instance()->get( 'settings' )->edge_integration_enabled() ) {
			$controllers[] = new Api\ConfigController();
		}

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * AJAX handler: create WooCommerce page translations for all languages.
	 *
	 * Creates translation stubs for Cart, Checkout, My Account, and Shop
	 * pages in every active non-default language. If machine translation is
	 * enabled and configured, page titles are auto-translated.
	 *
	 * @return void
	 */
	private static function ajax_create_wc_page_translations(): void {
		check_ajax_referer( 'perflocale_create_wc_pages', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ] );
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

		// Set up MT provider if available.
		$mt_provider  = null;
		$default_lang = strtolower( substr( $default->locale, 0, 2 ) );

		if ( $settings->mt_enabled() ) {
			try {
				$mt_service  = new MachineTranslation\TranslationService( $settings, $cache );
				$mt_provider = $mt_service->get_provider();
			} catch ( \Throwable $e ) {
				// MT not configured - continue without translation.
				$mt_provider = null;
			}
		}

		$manager = new Translation\PostTranslationManager( $cache, $settings );
		$created = 0;
		$skipped = 0;
		$details = [];
		// Track whether machine translation actually produced a title, so the
		// "Titles were machine-translated" note only shows when it's true — a
		// configured provider may still be skipped (local .mo hit, target ==
		// default language) or fail, in which case the source title is kept.
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

			foreach ( $languages as $lang ) {
				if ( $lang->slug === $default->slug ) {
					continue;
				}

				$label = ucfirst( $key ) . ' → ' . $lang->slug;

				// MT-tagged when an MT provider was resolved above; otherwise
				// the page shell was created without translation, so Manual
				// reflects what landed in the row.
				$link_source = $mt_provider !== null
					? \PerfLocale\Enum\SourceType::MachineTranslation
					: \PerfLocale\Enum\SourceType::Manual;
				$new_id      = $manager->create_translation( $page_id, $lang->slug, true, $link_source );

				if ( ! $new_id || $new_id === $page_id ) {
					$details[] = $label . ': failed';
					continue;
				}

				$new_post = get_post( $new_id );

				if ( ! $new_post ) {
					$details[] = $label . ': post missing (ID ' . $new_id . ')';
					continue;
				}

				// Translate the title: try local WP/plugin translations first, then MT.
				// Initialize to the source title so the UPDATE below never writes
				// an empty string when (a) no local translation matched AND (b) no
				// MT provider is configured OR (c) target_lang === default_lang
				// (the same-language fast path skips the elseif branch entirely).
				// Without this initializer PHP would warn on the undefined variable
				// and UPDATE would write NULL/empty into post_title.
				$original_title   = $source_post->post_title;
				$translated_title = $original_title;

				// Only resolve a title (local lookup, then a PAID provider call)
				// when this row still needs one: a fresh shell, an unpublished or
				// slug-mismatched row, or a title still equal to the source.
				// create_translation() is idempotent, so a re-run of the bulk
				// action lands here with the already-translated published page —
				// re-resolving would spend an MT call per page per click for a
				// guaranteed no-op.
				$needs_title = (
					$new_post->post_status !== 'publish'
					|| $new_post->post_name !== $source_post->post_name
					|| $new_post->post_title === $original_title
					|| trim( $new_post->post_title ) === ''
				);

				$mt_translated_this = false;

				if ( $needs_title ) {
					$local_translation = self::find_local_translation( $original_title, $lang->locale );

					if ( $local_translation !== '' ) {
						$translated_title = $local_translation;
					} elseif ( $mt_provider ) {
						$target_lang = strtolower( substr( $lang->locale, 0, 2 ) );

						if ( $target_lang !== $default_lang ) {
							try {
								$translated_title = $mt_provider->translate(
									$original_title,
									$default_lang,
									$target_lang
								);
								$mt_translated_this = true;
							} catch ( \Throwable $e ) {
								// Keep original title on failure.
								$translated_title = $original_title;
							}
						}
					}
				} else {
					// Existing translated title is authoritative on re-runs.
					$translated_title = $new_post->post_title;
				}

				// Publish with matching slug and translated title.
				$needs_update = (
					$new_post->post_status !== 'publish'
					|| $new_post->post_name !== $source_post->post_name
					|| $new_post->post_title !== $translated_title
				);

				// The "Titles were machine-translated" note must reflect writes
				// that actually happened, not provider calls that were discarded.
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
							'post_status' => 'publish',
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
	 * AJAX handler: create translations for all terms in translatable taxonomies.
	 *
	 * Iterates through each translatable taxonomy, finds terms in the default
	 * language (or unassigned terms), and creates translation stubs for every
	 * non-default active language. Uses local WordPress translations first,
	 * then falls back to MT if available.
	 *
	 * @return void
	 */
	private static function ajax_create_taxonomy_translations(): void {
		// Verify the caller before any side effects (resource-limit
		// raises, DB scans, etc.) — nonce + capability checks first.
		check_ajax_referer( 'perflocale_create_taxonomy_translations', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ] );
		}

		// Caller verified — now raise limits for the heavy lift. Large
		// sites with many terms + languages can run up against php.ini
		// max_execution_time (default 30-60s on shared hosts) even though
		// the work is cheap per-term. A single blocking MT call per term
		// is slow enough to blow the timeout before the handler gets to
		// batch-translate below. `function_exists` skips cleanly when
		// the host lists it in disable_functions.
		// Filterable so hosts that DO want a hard cap can set one. Default
		// 0 (no limit) only applies inside this caller-verified handler;
		// it does NOT run on init/__construct.
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

		if ( ! $default || count( $languages ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		// Set up MT provider if available.
		$mt_provider  = null;
		$default_lang = strtolower( substr( $default->locale, 0, 2 ) );

		if ( $settings->mt_enabled() ) {
			try {
				$mt_service  = new MachineTranslation\TranslationService( $settings, $cache );
				$mt_provider = $mt_service->get_provider();
			} catch ( \Throwable $e ) {
				$mt_provider = null;
			}
		}

		// flush_all (not flush_group): clear all three cache layers before the
		// bulk-read map. flush_group clears only L2, so a stale L3 transient
		// would rehydrate L2 and disagree with the direct-DB prefetch below,
		// making the idempotency check miscount existing terms as new.
		$cache->flush_all();

		// Build a bulk term → language map in one query to avoid N+1 queries.
		// This is critical for performance with thousands of terms.
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

		// $term_lang_map      : term_id → language slug (what language is this term in?)
		// $term_group_map     : term_id → group_id    (which group does this term belong to?)
		// $group_lang_map     : group_id → lang_slug → term_id
		// (for a given group, which term represents each language?)
		// Idempotency check in the inner loop is:
		// isset( $group_lang_map[ $group_of_source ][ $target_lang ] )
		// Without this, create_translation()'s own "already exists" short-circuit
		// returns the same int either way and the caller counted existing
		// translations as newly-created on every click.
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

		// Pre-cache local translations per locale to avoid repeated switch_to_locale() calls.
		// Cache: locale → original_name → translated_name.
		$local_cache = [];

		// Collected MT rename-jobs (lang_slug → taxonomy → new_term_id =>
		// source_name). A batched second pass runs one MT call per (lang,
		// taxonomy) chunk-of-50 instead of one blocking call per term, which
		// keeps large taxonomies under PHP's max_execution_time.
		$mt_pending = [];

		// Defer term counting until all inserts are done - avoids expensive
		// wp_update_term_count() calls per wp_insert_term().
		wp_defer_term_counting( true );

		foreach ( $taxonomies as $taxonomy ) {
			$tax_obj = get_taxonomy( $taxonomy );

			if ( ! $tax_obj ) {
				continue;
			}

			$tax_label   = $tax_obj->labels->name;
			$tax_created = 0;
			$tax_skipped = 0;

			// Fetch only term IDs and names to minimize memory usage.
			$terms = get_terms(
				[
					'taxonomy'                 => $taxonomy,
					'hide_empty'               => false,
					'orderby'                  => 'parent',
					'order'                    => 'ASC',
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

				// Assign default language if not set. set_term_language also
				// creates a group for this term, so re-query the group id so
				// the idempotency check below can see it.
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

					// Idempotency: the source term's group already has a
					// sibling for this language → that's "existed", not a
					// fresh create. This is what makes repeated clicks of
					// the button report "0 created, N existed" instead of
					// re-reporting the same N as newly-created each time.
					if ( $group_id !== null && isset( $group_lang_map[ $group_id ][ $lang->slug ] ) ) {
						++$tax_skipped;
						continue;
					}

					// Same MT-vs-Manual decision as the post path above —
					// the term-translation row reflects whether MT actually
					// ran during this batch.
					$term_source = $mt_provider !== null
						? \PerfLocale\Enum\SourceType::MachineTranslation
						: \PerfLocale\Enum\SourceType::Manual;
					$new_id      = $term_manager->create_translation( $tid, $taxonomy, $lang->slug, true, $term_source );

					if ( ! $new_id || $new_id === $tid ) {
						++$tax_skipped;
						continue;
					}

					// Safety net: if create_translation returned a term_id
					// that was already present in our bulk-read map at the
					// start of this run, the translation already existed
					// (covers edge cases where our group-aware pre-check
					// couldn't see it - e.g. a stale-cache rehydrate or a
					// prior corruption where the source term had multiple
					// group memberships).
					if ( isset( $term_lang_map[ $new_id ] ) ) {
						++$tax_skipped;
						continue;
					}

					// Track the new term in our map.
					$term_lang_map[ $new_id ] = $lang->slug;

					// Keep the group-aware map in sync so sibling terms
					// processed later in the same run see this translation
					// already exists.
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

					// Local-translation lookup (fast, no network). WP core's
					// .mo files ship translations for common strings like
					// "Uncategorized", so this short-circuits the MT path
					// for free and prevents "Uncategorized" from becoming
					// "Безкатегория" via MT when the WP-core Bulgarian .mo
					// already has a curated translation.
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
					} elseif ( $mt_provider ) {
						// Defer MT to a batched second pass. See $mt_pending
						// docblock - avoids per-term serial HTTP calls that
						// previously blew PHP max_execution_time on large sites.
						$target_lang_code = strtolower( substr( $lang->locale, 0, 2 ) );

						if ( $target_lang_code !== $default_lang ) {
							$mt_pending[ $lang->slug ][ $taxonomy ][ (int) $new_id ] = [
								'source_name' => $term->name,
								'target_code' => $target_lang_code,
							];
						}
					}

					++$tax_created;
				}
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
		}

		// Flush deferred term counts now that all inserts are done.
		wp_defer_term_counting( false );

		// ─── Second pass: batched MT rename of the pending term translations.
		// One MT HTTP call per (target-lang, taxonomy, 50-term chunk) instead
		// of one call per term per language. DeepL and Google both accept
		// arrays of up to 50 strings per request; this collapses hundreds of
		// serial API calls into single-digit batch calls.
		if ( $mt_provider && ! empty( $mt_pending ) ) {
			foreach ( $mt_pending as $lang_slug => $by_taxonomy ) {
				foreach ( $by_taxonomy as $taxonomy => $pending_terms ) {
					$unique_names = [];
					foreach ( $pending_terms as $entry ) {
						$unique_names[ $entry['source_name'] ] = $entry['target_code'];
					}

					if ( empty( $unique_names ) ) {
						continue;
					}

					$target_code  = reset( $unique_names );
					$source_names = array_keys( $unique_names );

					$name_map = [];

					foreach ( array_chunk( $source_names, 50 ) as $chunk ) {
						try {
							$translated_chunk = $mt_provider->translate_batch(
								$chunk,
								$default_lang,
								$target_code
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

					foreach ( $pending_terms as $new_id => $entry ) {
						if ( isset( $name_map[ $entry['source_name'] ] ) ) {
							$mt_result = wp_update_term( $new_id, $taxonomy, [ 'name' => wp_slash( $name_map[ $entry['source_name'] ] ) ] );
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

		$message = sprintf(
			/* translators: %1$d: number of translations created, %2$d: number already existing */
			__( 'Done - %1$d translation(s) created, %2$d already existed.', 'perflocale' ),
			$created,
			$skipped
		);

		if ( $mt_used ) {
			$message .= ' ' . __( 'Term names were machine-translated.', 'perflocale' );
		}

		wp_send_json_success(
			[
				'message'          => $message,
				'created'          => $created,
				'skipped'          => $skipped,
				'taxonomy_details' => $taxonomy_details,
			]
		);
	}

	/**
	 * AJAX handler: assign the site's default language to every translatable
	 * post/page/CPT that currently has no translation-link row.
	 *
	 * Pre-existing sites (installed before PerfLocale) commonly have posts
	 * that were never linked to any language. PostQueryFilter's fallback
	 * clause (`language = X OR object_id IS NULL`) correctly shows those
	 * unmanaged posts in every locale, which makes language-scoped pickers
	 * (nav-menu Pages accordion, category checklists on post.php, etc.)
	 * appear to "leak" other-language content.
	 *
	 * This one-click cleanup links every unlinked post to the default
	 * language. Posts that already have a language stay untouched - the
	 * assumption is the user meant them. The operation is idempotent:
	 * running it twice does nothing on the second run.
	 *
	 * Nothing here infers a post's actual language from its title/slug
	 * (that kind of heuristic is noisy and unreliable). Sites with mixed-
	 * language content need to explicitly assign other languages post by
	 * post via the editor sidebar, or use a translation import.
	 *
	 * @return void
	 */
	private static function ajax_assign_post_languages(): void {
		// Verify the caller before any side effects (resource-limit
		// raises, DB scans, etc.) — nonce + capability checks first.
		check_ajax_referer( 'perflocale_assign_post_languages', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ] );
		}

		// Caller verified — raise limits so very large sites don't blow
		// PHP max_execution_time on the first run. set_post_language()
		// does a single small DB write per post, so this is fast — but
		// 10 000+ posts still needs room. `function_exists` skips cleanly
		// when the host lists it in disable_functions.
		// Filterable so hosts that DO want a hard cap can set one. Default
		// 0 (no limit) only applies inside this caller-verified handler;
		// it does NOT run on init/__construct.
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

		$types = $settings->get_translatable_post_types();

		if ( empty( $types ) ) {
			wp_send_json_error( [ 'message' => __( 'No translatable post types configured.', 'perflocale' ) ] );
		}

		global $wpdb;
		$links_table  = Database\Schema::table( 'translation_links' );
		$groups_table = Database\Schema::table( 'translation_groups' );

		// Look up unlinked posts in a single query per post type - explicit
		// LEFT JOIN to the 'post'-type translation_group. Posts whose group
		// row is NULL have never been linked.
		$manager        = new Translation\PostTranslationManager( $cache, $settings );
		$per_type       = [];
		$total_assigned = 0;

		foreach ( $types as $post_type ) {
			// Find posts with NO valid post-type translation link. The
			// previous LEFT JOIN approach expanded to one row per link, so
			// a post with one good link AND one orphan link (link whose
			// group_id references a deleted/non-existent group) was
			// returned as "unlinked" — false positive that re-assigns the
			// already-linked post and never settles. NOT EXISTS evaluates
			// once per post and short-circuits cleanly.
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
					  )",
					$post_type,
					$links_table,
					$groups_table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$assigned = 0;

			foreach ( $ids as $post_id ) {
				if ( $manager->set_post_language( (int) $post_id, $default->slug ) ) {
					++$assigned;
				}
			}

			$per_type[] = [
				'post_type' => $post_type,
				'assigned'  => $assigned,
			];

			$total_assigned += $assigned;
		}

		$message = sprintf(
			/* translators: 1: number of posts assigned, 2: default language slug */
			__( 'Done - %1$d post(s) assigned to %2$s.', 'perflocale' ),
			$total_assigned,
			Helper::format_locale_as_bcp47( (string) $default->slug )
		);

		wp_send_json_success(
			[
				'message'           => $message,
				'assigned'          => $total_assigned,
				'post_type_details' => $per_type,
			]
		);
	}

	/**
	 * Find a local WordPress translation for a string across all loaded textdomains.
	 *
	 * Switches to the target locale and checks every loaded Translations object
	 * for a match. Useful for strings that ship with plugin/theme translations
	 * (e.g., WooCommerce page titles, default category names).
	 *
	 * @param string $text Original text to translate.
	 * @param string $locale Target WordPress locale (e.g., 'de_DE', 'ar').
	 * @return string Translated text, or empty string if no local translation found.
	 */
	private static function find_local_translation( string $text, string $locale ): string {
		switch_to_locale( $locale );

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

		restore_previous_locale();

		return $result;
	}

	/**
	 * Add language labels to wp_dropdown_pages output.
	 *
	 * Appends a language code (e.g., "[EN]", "[FR]") to each page option
	 * in dropdown selects used by Reading Settings, etc.
	 *
	 * @param string $output Dropdown HTML.
	 * @return string Modified HTML.
	 */
	public static function add_language_labels_to_dropdown( string $output ): string {
		if ( ! is_admin() || empty( $output ) ) {
			return $output;
		}

		// Per-request memo of the page-id → language-code map. wp_dropdown_pages
		// fires multiple times on a single Customizer / Reading-Settings
		// render (one per dropdown, sometimes one per partial). Without the
		// memo each call re-runs the 4-table JOIN below; with it, every call
		// after the first is a single array lookup. Keyed by blog id because
		// page ids are per-blog auto-increments — a network-admin render that
		// switch_to_blog()s between dropdowns would otherwise mislabel pages
		// with the first blog's map. Self-correcting, so no reset hook needed.
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
					// Escape late: $map holds language slugs read from the
					// languages table. They are sanitize_key()'d on write, so
					// nothing hostile can be stored today — but this string is
					// returned straight into the dropdown's HTML, so it is
					// escaped at the point of output regardless of what wrote it.
					return $m[1] . $m[3] . ' [' . esc_html( $map[ $page_id ] ) . ']' . $m[4];
				}

				return $m[0];
			},
			$output
		) ?? $output;
	}

	/**
	 * Allow duplicate post slugs for posts that are translations of each other.
	 *
	 * WordPress enforces unique slugs per post type. For multilingual sites,
	 * posts in different languages should share the same slug - uniqueness
	 * is provided by the language prefix in the URL (/de/about/ vs /about/).
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

		// We only collapse the slug back to the original when the conflicting
		// post(s) live in a DIFFERENT language. Same-language slug collisions
		// must keep WP's deduplication (-2, -3) because the URLs would
		// otherwise be identical and unroutable.
		$cache     = $plugin->get( 'cache' );
		$manager   = new Translation\PostTranslationManager( $cache, $settings );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );

		// PostTranslationManager::create_translation() sets a class-level hint
		// before its wp_insert_post() call so this filter can know the new
		// post's language - the translation_links row hasn't been written yet.
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
			// Can't tell what language we're in - be conservative.
			return $slug;
		}

		global $wpdb;

		// All same-slug, same-type, non-trash siblings in the system. We need
		// every one of them to live in a different language than ours; even
		// one same-language conflict means WP's -N suffix has to stay.
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

			// Same-language conflict (or unassigned conflict treated as
			// default): keep WP's deduplicated slug.
			if ( ! $conflict_lang || (int) $conflict_lang->id === (int) $current_lang->id ) {
				return $slug;
			}
		}

		// Every conflict is in a different language - safe to use the
		// original slug; the language router prefixes the URL.
		return $original_slug;
	}

	/**
	 * Remove a post's translation_links + slug/content-hash rows when the
	 * post is permanently deleted. Fires on `before_delete_post` so the
	 * rows are gone by the time related caches get invalidated.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function cleanup_translation_link_on_delete( int $post_id ): void {
		// Revisions never carry translation links; skip the cache + DB lookup
		// noise on every autosave-revision purge.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		// translation_links + group GC. Existing helper handles both.
		$groups = new Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
		$groups->unlink_by_object_id( $post_id, 'post' );

		// Drop the L1 lang memo for this post so any same-request caller
		// that detected its language before the delete doesn't keep
		// returning the now-stale value.
		Translation\PostTranslationManager::forget_post_language( $post_id );

		// Tables-exist guard so the cleanup is safe during the brief
		// activation window where Bootstrap fires before Schema finishes
		// provisioning, and during uninstall ordering where
		// Schema::drop_tables may have already fired.
		if ( ! Database\Schema::tables_exist() ) {
			return;
		}

		global $wpdb;

		// slug_translations rows for this post. Without this, every deleted
		// translated post leaves N orphan rows (one per language) pointing at
		// an ID that no longer exists. Index seek on the (object_type,
		// object_id, language_id) UNIQUE key + small N per post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Database\Schema::table( 'slug_translations' ),
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			],
			[ '%d', '%s' ]
		);

		// content_hashes row for this post. ContentChangeDetector cleans this
		// on its own delete hook, but it is only booted in admin/AJAX/REST/CLI
		// contexts — a permanent delete from a front-end context would otherwise
		// orphan the hash row. This handler is always booted, so mirror the
		// slug_translations cleanup here. Index seek on the (object_type,
		// object_id) UNIQUE key; perf-neutral.
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
	 * Auto-assign the default language to new translatable posts.
	 *
	 * Runs on save_post at priority 5 across ALL contexts (admin, REST, CLI)
	 * so that every new post gets a language assigned immediately.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_assign_default_language( int $post_id, ?\WP_Post $post = null ): void {
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Skip child post types that inherit language from their parent.
		// e.g. product_variation inherits from its parent product.
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

		// Already has a language - nothing to do.
		if ( $manager->detect_post_language( $post_id ) !== null ) {
			return;
		}

		$lang_repo    = new Database\Repository\LanguageRepository( $cache );
		$default_lang = $lang_repo->get_default();

		if ( $default_lang && ! $manager->set_post_language( $post_id, $default_lang->slug ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the post stays unassigned and is treated as default-language by the fallback convention.
			error_log( sprintf( 'PerfLocale: auto default-language assignment failed for new post %d.', $post_id ) );
		}
	}

	/**
	 * Auto-create empty translation stubs when a post is first published.
	 *
	 * Creates draft posts for every active non-default language and links
	 * them in the translation group. Only runs once per post (skips if
	 * translations already exist).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_create_translation_stubs( string $new_status, string $old_status, \WP_Post $post ): void {
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

		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $post->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

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

		// Lock around the existence check + per-language create_translation
		// loop. Without this, two near-simultaneous publish transitions (e.g.
		// quick-edit + autosave colliding, double-clicks, cron race) both
		// pass the `count($existing) > 1` guard and both call create_translation
		// per language. The translation_links unique key catches the link
		// rows but the duplicate WP posts are still inserted, leaving orphans.
		// 60s TTL is well above any realistic per-post stub-creation time and
		// well below WP-Cron's 15-min hard timeout.
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
	 * Cron hook for deferred auto-translation.
	 *
	 * Public so Deactivator can clear it on deactivation.
	 */
	public const AUTO_TRANSLATE_CRON = 'perflocale_auto_translate_post';

	/**
	 * Schedule auto-translation for a newly published post via WP-Cron.
	 *
	 * Deferred to a background job so publishing is never blocked by
	 * slow or failing translation API calls. Without this, a down API
	 * with 3 retries × N languages could block the publish for 20+ seconds.
	 * Runs at priority 25 (after auto_create_translation_stubs at 20) so the
	 * stubs already exist when translation starts.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_translate_on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
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

		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $post->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$cache     = $plugin->get( 'cache' );
		$manager   = new Translation\PostTranslationManager( $cache, $settings );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$default   = $lang_repo->get_default();

		if ( ! $default ) {
			return;
		}

		// Only auto-translate default-language posts.
		$post_lang = $manager->detect_post_language( $post->ID );

		if ( $post_lang && $post_lang->slug !== $default->slug ) {
			return;
		}

		// Defer to async runner — Action Scheduler when available
		// (immediate claim, no per-minute cron tick wait), WP-Cron otherwise.
		if ( ! Background\BackgroundEvents::is_scheduled( self::AUTO_TRANSLATE_CRON, [ $post->ID ] ) ) {
			Background\BackgroundEvents::enqueue( self::AUTO_TRANSLATE_CRON, [ $post->ID ] );
		}
	}

	/**
	 * Process deferred auto-translation for a single post.
	 *
	 * @param int $post_id Post ID to translate.
	 * @return void
	 */
	public static function process_auto_translate( int $post_id ): void {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		// Early-exit if the source post was deleted between cron-schedule and
		// cron-run; without this the loop logs a "Source post not found" error
		// per language for one benign race.
		if ( ! get_post( $post_id ) ) {
			return;
		}

		// Prevent two WP-Cron workers from double-translating the same post
		// (e.g. a quick-edit toggle firing two near-simultaneous publish hooks,
		// or a cron runner with overlapping tick windows). TTL of 120 s covers
		// the maximum expected wall-clock time for one post across all languages.
		if ( ! Concurrency\Lock::acquire( 'auto_translate_' . $post_id, 120 ) ) {
			return;
		}

		try {
			$settings  = $plugin->get( 'settings' );
			$cache     = $plugin->get( 'cache' );
			$lang_repo = new Database\Repository\LanguageRepository( $cache );
			$default   = $lang_repo->get_default();

			if ( ! $default || ! $settings->mt_enabled() ) {
				return;
			}

			$service   = new MachineTranslation\TranslationService( $settings, $cache );
			$languages = $lang_repo->get_active();

			// Explicit target subset stored in mt_auto_translate_languages
			// limits the fan-out; the empty default means "every active
			// language" (and automatically includes languages added later).
			$scope = (array) $settings->get( 'mt_auto_translate_languages', [] );

			$manager      = new Translation\PostTranslationManager( $cache, $settings );
			$translations = $manager->get_translations( $post_id );

			foreach ( $languages as $lang ) {
				if ( $lang->slug === $default->slug ) {
					continue;
				}

				if ( $scope !== [] && ! in_array( $lang->slug, $scope, true ) ) {
					continue;
				}

				// Never overwrite a human-touched translation: a republish of
				// the source fires this whole flow again, and translate_post's
				// update path would put raw MT over a live, human-refined
				// sibling. Auto-MT may only fill languages with no translation
				// yet or an untouched stub — stubs are created with EMPTY
				// content, so non-empty content means a person (or a prior MT
				// run the translator may since have edited) owns the text.
				// The source_type link column cannot discriminate here: auto
				// stubs are recorded as Manual and translate_post never
				// upgrades provenance.
				$existing_id = (int) ( $translations[ $lang->slug ] ?? 0 );
				$should      = true;

				if ( $existing_id > 0 && $existing_id !== $post_id ) {
					$existing = get_post( $existing_id );
					$should   = ! $existing || (string) $existing->post_content === '';
				}

				/**
				 * Whether the auto-translate flow may (re)translate this
				 * language for this post. Return true to force re-MT of an
				 * already-filled translation, false to skip a language.
				 *
				 * @hook perflocale/mt/should_auto_translate
				 * @param bool   $should      Computed decision (skip non-empty translations).
				 * @param int    $post_id     Source post ID.
				 * @param string $lang_slug   Target language slug.
				 * @param int    $existing_id Existing translation post ID (0 = none).
				 */
				if ( ! (bool) apply_filters( 'perflocale/mt/should_auto_translate', $should, $post_id, (string) $lang->slug, $existing_id ) ) {
					continue;
				}

				try {
					$service->translate_post( $post_id, $lang->slug );
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						// Silence the common benign cases (post deleted
						// mid-cron, stub reused) so debug.log stays
						// usable for real failures.
						$msg    = $e->getMessage();
						$benign = str_contains( $msg, 'Source post not found' )
							|| str_contains( $msg, 'Invalid post ID' )
							|| str_contains( $msg, 'Невалидно ID' );

						if ( ! $benign ) {
							error_log( 'PerfLocale auto-translate failed for ' . $lang->slug . ': ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}
				}
			}
		} finally {
			Concurrency\Lock::release( 'auto_translate_' . $post_id );
		}
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
		// Skip WP-internal taxonomies that should never be translated.
		if ( in_array( $taxonomy, [ 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' ], true ) ) {
			return;
		}

		// If the user explicitly selected a language via the term form,
		// defer to the AJAX save handler which runs on created_{taxonomy}
		// - don't override with the default language.
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

		// Already has a language - nothing to do.
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
	 * Detect if a known conflicting multilingual plugin is active.
	 *
	 * @return string|null Display name of the conflicting plugin, or null.
	 */
	private static function detect_conflicting_plugin(): ?string {
		// Primary check: does the competitor's version constant exist? This is
		// defined inside the competitor's main file once it has loaded.
		foreach ( self::CONFLICTING_PLUGINS as $name => $meta ) {
			if ( defined( $meta['constant'] ) ) {
				return $name;
			}
		}

		// Fallback: check active_plugins directly. PerfLocale's main file may
		// load BEFORE the competitor's main file (alphabetical / active_plugins
		// order), in which case the competitor's constant isn't defined yet at
		// the moment Bootstrap::init() runs. Reading the option covers that
		// case - and keeps conflict detection order-independent on ALL sites.
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
	 * Register the [perflocale_language] utility shortcode.
	 *
	 * Outputs current language info in various formats.
	 * Usage: [perflocale_language format="slug|locale|name|native_name|display_name|flag"]
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
