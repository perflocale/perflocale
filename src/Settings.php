<?php
/**
 * Settings manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed wrapper around the perflocale_settings option.
 *
 * Loads the option once per request (static cache) and provides typed getters
 * with defaults for every setting. No DB call is repeated within a request.
 */
final class Settings {

	/**
	 * Option key in wp_options.
	 */
	private const OPTION_KEY = 'perflocale_settings';

	/**
	 * Default values for all settings.
	 */
	private const DEFAULTS = [
		// URL & Routing.
		'url_mode'                       => 'subdirectory',
		'url_prefix_type'                => 'slug',
		'hide_default_prefix'            => true,
		'language_domains'               => [],
		'language_detection_order'       => [ 'url', 'cookie', 'browser', 'default' ],
		'cookie_lifetime'                => 365,
		'disable_language_cookie'        => false,
		'redirect_browser_lang'          => false,
		'redirect_geo_enabled'           => false,
		// Edge-hint redirect: when an edge worker (Cloudflare/Vercel/Netlify)
		// has already mapped country -> language at the edge and forwarded the
		// decision via the X-PerfLocale-Lang header (or perflocale_edge_lang
		// cookie), redirect first-time visitors to that language. Routing at
		// the edge keeps the response cacheable. Requires the global Edge
		// Integration toggle (`edge_integration_enabled`) to also be on.
		'redirect_edge_hint_enabled'     => false,
		// Order in which the redirect handlers run when more than one is
		// enabled. First entry wins (registers earlier on `template_redirect`).
		// Default order: geo first, then browser, edge_hint last. Admins
		// can drag-reorder in the UI.
		'redirect_priority_order'        => [ 'geo', 'browser', 'edge_hint' ],
		'geo_provider'                   => '',
		'geo_country_map'                => [],
		'geo_cache_hours'                => 24,
		'missing_translation_action'     => 'show_default',
		'language_fallbacks'             => [],
		'excluded_paths'                 => [ '/wp-json/', '/wp-admin/', '/wp-login.php' ],

		// Translation.
		'translatable_post_types'        => [ 'post', 'page' ],
		'translatable_taxonomies'        => [ 'category', 'post_tag' ],
		'translatable_meta_keys'         => [],
		'default_translation_status'     => 'empty',
		'auto_create_stubs'              => false,
		'sync_fields'                    => [ 'featured_image', 'menu_order' ],
		'sync_term_hierarchy'            => true,
		'translate_slugs'                => true,

		// Machine Translation.
		'mt_enabled'                     => false,
		'mt_provider'                    => '',
		'mt_deepl_api_key'               => '',
		'mt_deepl_formality'             => 'default',
		'mt_google_api_key'              => '',
		'mt_microsoft_api_key'           => '',
		'mt_microsoft_region'            => 'global',
		'mt_libre_url'                   => '',
		'mt_libre_api_key'               => '',
		'mt_agency_url'                  => '',
		'mt_agency_api_key'              => '',
		'mt_auto_translate_on_publish'   => false,
		// Target scope for BOTH auto-translate flows. Empty = every active
		// language (and languages added later join automatically); an
		// explicit slug list limits the fan-out.
		'mt_auto_translate_languages'    => [],
		'mt_auto_translate_on_create'    => false,
		'mt_monthly_char_limit'          => 500000,
		// Cost kill-switch for the Strings admin bulk-MT toolbar. ON by
		// default (consistent with the rest of MT defaulting to "available
		// once you turn MT on"); flip OFF to hide the toolbar entirely
		// while still permitting per-row MT in the modal editor.
		'mt_bulk_strings_enabled'        => true,
		// Pre-dispatch budget gate: veto bulk MT dispatches whose estimated
		// character volume would exceed the monthly cap, BEFORE any provider
		// spend (see the perflocale/jobs/should_dispatch gate in Bootstrap).
		// OFF restores the historical run-until-cap behaviour.
		'mt_enforce_cap_on_bulk'         => true,
		// Meta-field MT policy. SEO meta (titles/descriptions/og/twitter) is
		// short, cheap, high-value: ON. Custom fields (ACF/MetaBox/Pods text
		// fields) scale with site structure: opt-in. Both feed the curated
		// perflocale/mt/translatable_meta_keys registry — never the seed list.
		'mt_meta_seo'                    => true,
		'mt_meta_custom_fields'          => false,

		// SEO.
		'seo_hreflang_enabled'           => true,
		'seo_hreflang_placement'         => 'head',
		'seo_x_default'                  => true,
		// When true, hreflang includes every active language even without a
		// specific translation (assumes each language root renders 200 via the
		// missing-translation action / fallback chain). Default false is
		// conservative: only languages with an actual translation.
		'seo_hreflang_include_fallbacks' => false,
		'seo_sitemap_enabled'            => true,
		// auto = let the active SEO plugin own its native sitemap if one is
		// detected, otherwise inject into WP core sitemaps. core = always
		// inject into WP core only (even when an SEO plugin is active).
		// plugin = always skip WP core injection (let the SEO plugin
		// addon handle alternates exclusively). Most users want 'auto'.
		'seo_sitemap_source'             => 'auto',
		'seo_plugin'                     => 'none',
		'seo_og_locale'                  => true,

		// Language Switcher.
		'switcher_template'              => 'flags_names',
		// Classic-menu theme locations the switcher is appended to (block
		// themes use the switcher block instead). Empty = never appended.
		'switcher_menu_locations'        => [],
		'switcher_display'               => 'dropdown',
		'switcher_layout'                => 'horizontal',
		'switcher_name_format'           => 'native',
		'switcher_class'                 => '',
		'switcher_flag_style'            => 'rectangular',
		'switcher_show_untranslated'     => false,
		'switcher_hide_current'          => true,
		'switcher_untranslated_link'     => 'homepage',
		// Dropdown chevron icon next to the trigger label. `single` = one
		// down chevron (the classic native-select look, default); `double`
		// = stacked up/down chevrons; `none` = no icon. Themes/addons that
		// want a custom icon (image, icon-font, brand-specific SVG) should
		// hook the `perflocale/switcher/arrow_html` filter — that filter
		// fires for every value including `none`, so it can also INJECT
		// an arrow even when the user picked "no icon" in the UI.
		'switcher_arrow_style'           => 'single',
		// Trigger button label format on dropdown switchers. `inherit`
		// reuses `switcher_name_format` (the options' format), which is
		// the right default for most sites — trigger and options share a
		// vocabulary. Set to `slug` for a compact "EN" / "FR" header
		// pill while keeping full names in the options list, or to
		// `english` / `native` / `both` to override independently.
		'switcher_trigger_format'        => 'inherit',
		'admin_bar_switcher'             => true,
		// Block Hooks auto-insertion of the language switcher in FSE templates.
		// Defaults ON: the block.json declares `blockHooks: core/site-title:after`
		// so themes that use the Site Title block in their header get a working
		// switcher with zero manual placement. Flip OFF to opt out (recommended
		// for users who already have a manually-placed switcher block).
		'switcher_auto_insert'           => true,

		// WooCommerce.
		'wc_email_translation'           => true,
		'wc_sync_stock'                  => true,
		'wc_sync_prices'                 => true,
		'wc_currency_per_lang'           => false,
		'wc_currencies'                  => [],
		'wc_exchange_rate_auto'          => false,
		'wc_exchange_rate_provider'      => '',
		'wc_exchange_rate_interval'      => 'daily',

		// Data.
		'delete_data_on_uninstall'       => false,

		// Performance.
		'cache_object_enabled'           => true,
		'cache_preload_slugs'            => true,
		'string_translation_mode'        => 'files',

		// Background processing. See src/Background/ for the runtime.
		// - background_processing: 'auto' | 'always' | 'never'
		// Auto = async over per-action threshold; Always = always async;
		// Never = always run inline (not recommended on large sites).
		// - background_engine: 'auto' | 'force_wp_cron'
		// Auto picks Action Scheduler when loaded (≥ 3.4), otherwise
		// falls back to WP-Cron. Force = use WP-Cron even when AS is
		// available — escape hatch for sites where AS is misbehaving.
		// - background_thresholds: [type => int]
		// Per-action override of the default item-count threshold for
		// Auto mode. Filter `perflocale/jobs/threshold/<type>` is the
		// programmatic equivalent.
		// - background_paused: when true, dispatched jobs are accepted but
		// workers immediately re-queue them at +5 min instead of running.
		// Operational escape hatch when something is mis-dispatching.
		'background_processing'          => 'auto',
		'background_engine'              => 'auto',
		'background_thresholds'          => [],
		'background_paused'              => false,

		// Integrations (CDN / edge / schema).
		'edge_integration_enabled'       => false,
		'cdn_cache_tags_enabled'         => false,
		'seo_schema_enrichment_enabled'  => true,

		// Modern SEO features.
		//
		// Content-Language HTTP header - W3C standard, safe to send.
		'content_language_header'        => true,

		// data-nosnippet around default-language fallback content so Google
		// doesn't show default-lang snippets under non-default-lang URLs.
		// Only takes effect when missing_translation_action = show_default.
		'fallback_nosnippet'             => true,

		// Speculation Rules API - prerender the visitor's target translation
		// when they hover/focus the language switcher. Chromium-only today,
		// progressive enhancement elsewhere. Experimental → defaults OFF.
		'prerender_on_hover'             => false,

		// View Transitions API - smooth cross-document animation on language
		// switch. Chrome 126+ / Safari 18.2+; progressive enhancement elsewhere.
		// Experimental → defaults OFF; some themes animate their own content
		// on nav and can fight with this.
		'view_transitions_enabled'       => false,

		// Show the translation-overview widget on the WP admin Dashboard.
		// Off by default — opt in under Settings → Advanced. Capability-gated
		// and reads cached counts, so it adds no live queries on dashboard load.
		'dashboard_widget_enabled'       => false,

		// Internal - settings schema version for future migrations.
		'schema_version'                 => 1,
	];

	/**
	 * Cached settings array - loaded once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Resolved override map, built once at load() time. Keys are setting
	 * names whose CONSTANT_MAP entry resolves to either an environment
	 * variable or a PHP constant; values are the resolved string. Read-path
	 * uses this instead of re-running getenv()/defined()/constant() on
	 * every get() call.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $constant_overrides = null;

	/**
	 * Per-language URL prefix memo. Keyed by language ID → computed prefix.
	 *
	 * `get_url_prefix()` is called ~115 times per archive render (every
	 * permalink filter calls it via UrlConverter::add_language_prefix_to_url).
	 * In locale-prefix mode each call runs `strtolower(str_replace())` over
	 * the locale string — sub-µs but at 115×/request it adds up. The set of
	 * inputs is tiny (one per active language, 2-5 typically), so a per-ID
	 * cache collapses all calls to a single hash lookup after the first.
	 *
	 * Reset on `update_option_perflocale_settings` so url_prefix_type
	 * changes take effect immediately.
	 *
	 * @var array<int, string>
	 */
	private array $url_prefix_cache = [];

	/**
	 * Per-request memo for get_translatable_post_types() / _taxonomies().
	 *
	 * Was previously function-level `static`, but that scope can't be cleared
	 * by reset_cache(), so settings saves within the same request kept seeing
	 * the old list. Holding them as instance state lets reset_cache() drop
	 * them in lockstep with $this->settings.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $translatable_post_types_cache = null;

	/**
	 * @var array<int, string>|null
	 */
	private ?array $translatable_taxonomies_cache = null;

	/**
	 * Per-post-type cache of get_translatable_meta_keys(). An instance
	 * property (not a function-static) so reset_cache() — invoked on
	 * switch_blog and after a settings save — can actually clear it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $translatable_meta_keys_cache = [];

	/**
	 * Per-key origin of the resolved override ('env' or 'constant').
	 * Populated alongside $constant_overrides; surfaces to the UI via
	 * get_override_source() so admins see which source supplied the value.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $override_sources = null;

	/**
	 * Load settings from the database (once per request).
	 *
	 * @return array<string, mixed>
	 */
	private function load(): array {
		if ( $this->settings !== null ) {
			return $this->settings;
		}

		$stored = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$this->settings = wp_parse_args( $stored, self::DEFAULTS );

		// Pre-resolve every CONSTANT_MAP entry once so get() (called 80-150×
		// per request) stays a pure array lookup instead of paying
		// getenv()/defined()/constant() each call. Source priority: env >
		// constant (wp-config.php) > DB, so containerised hosts keep secrets
		// out of wp_options without losing the admin-saved fallback. Env /
		// constant values are trimmed (trailing-newline copy-paste).
		$this->constant_overrides = [];
		$this->override_sources   = [];

		foreach ( self::CONSTANT_MAP as $setting_key => $constant_name ) {
			$env = getenv( $constant_name );

			if ( is_string( $env ) && trim( $env ) !== '' ) {
				$this->constant_overrides[ $setting_key ] = trim( $env );
				$this->override_sources[ $setting_key ]   = 'env';
				continue;
			}

			if ( defined( $constant_name ) ) {
				$value = constant( $constant_name );

				// Empty constants are treated as "not set" so an explicit
				// `define('…', '')` in wp-config doesn't override a real
				// admin-saved value with nothing.
				if ( ! is_string( $value ) || trim( $value ) !== '' ) {
					$this->constant_overrides[ $setting_key ] = is_string( $value ) ? trim( $value ) : $value;
					$this->override_sources[ $setting_key ]   = 'constant';
				}
			}
		}

		// WP 7.0+ Connectors API: if the host site has a key registered
		// against a known connector slug, treat that as the highest-priority
		// source after env+constant. Feature-detected; on WP versions
		// without the Connectors API this branch is a no-op.
		$this->maybe_apply_connectors_overrides();

		return $this->settings;
	}

	/**
	 * Map of setting keys to WordPress Connectors API slugs (WP 7.0+).
	 *
	 * When the Connectors API exposes a key for any of these slugs and the
	 * matching setting hasn't already been resolved from env / constant, the
	 * Connectors value wins over the database value. Each slug corresponds to
	 * the canonical connector name expected by core (`deepl`, `google-cloud-
	 * translation`, etc.). When the Connectors API is not available (every WP
	 * release before 7.0, and 7.0 itself until the API ships), this map is
	 * inert.
	 */
	private const CONNECTOR_MAP = [
		'mt_deepl_api_key'     => 'deepl',
		'mt_google_api_key'    => 'google-cloud-translation',
		'mt_microsoft_api_key' => 'microsoft-translator',
		'mt_libre_api_key'     => 'libretranslate',
	];

	/**
	 * Try to populate constant_overrides from the WP 7.0 Connectors API.
	 *
	 * Connectors sit between env/constant and the database in the source
	 * priority chain — env still wins (operators may want to force a value
	 * for a specific host) and constants still win (defined in wp-config),
	 * but a key registered in Connectors is preferred over the value an
	 * admin typed into the PerfLocale settings UI. This gives multi-plugin
	 * sites a single rotation point.
	 *
	 * Inert on stock WP 7.0 (the function isn't shipped yet) and earlier;
	 * activates automatically once core ships `wp_get_connector` (or the
	 * equivalent retrieval API), without a plugin update.
	 *
	 * @return void
	 */
	private function maybe_apply_connectors_overrides(): void {
		$resolver = $this->resolve_connectors_callback();

		if ( $resolver === null ) {
			return;
		}

		/**
		 * Filter the setting-key → connector-slug map used to resolve
		 * Connectors-API values. Defaults to the 4 MT-provider keys.
		 *
		 * Use this to route additional API keys through the WP Connectors
		 * API — for example, geo provider tokens
		 * (a provider key setting registered by the site)
		 * or per-currency keys registered by the site.
		 * Unknown connector slugs simply return null from the resolver and
		 * fall through to env / constant / DB as usual.
		 *
		 * @hook perflocale/connectors/slug_map
		 *
		 * @param array<string, string> $map Default map (MT providers only).
		 */
		$map = (array) apply_filters( 'perflocale/connectors/slug_map', self::CONNECTOR_MAP );

		foreach ( $map as $setting_key => $connector_slug ) {
			$setting_key    = (string) $setting_key;
			$connector_slug = (string) $connector_slug;

			if ( $setting_key === '' || $connector_slug === '' ) {
				continue;
			}
			// Env / constant already supplied a value — keep their priority.
			if ( isset( $this->constant_overrides[ $setting_key ] ) ) {
				continue;
			}

			try {
				$value = $resolver( $connector_slug );
			} catch ( \Throwable $e ) {
				// Connector lookup failed — fall through to the DB value.
				continue;
			}

			if ( ! is_string( $value ) || trim( $value ) === '' ) {
				continue;
			}

			$this->constant_overrides[ $setting_key ] = trim( $value );
			$this->override_sources[ $setting_key ]   = 'connector';
		}
	}

	/**
	 * Locate a callable for retrieving an API key from the WP Connectors API.
	 *
	 * The Connectors API hasn't shipped at the time of writing — the function
	 * name is whatever core finally settles on. Probe a small set of plausible
	 * names so a future name change doesn't strand this integration. Custom
	 * integrators can also short-circuit detection by filtering with their
	 * own callable.
	 *
	 * Returns null when no resolver is available; caller treats that as
	 * "Connectors not present, skip."
	 *
	 * @return null|callable(string): ?string
	 */
	private function resolve_connectors_callback(): ?callable {
		/**
		 * Filter the callable used to look up a key in the WordPress
		 * Connectors API. Return any callable that accepts a connector slug
		 * (e.g. 'deepl') and returns a string key, or null/empty when the
		 * connector isn't registered.
		 *
		 * Useful for unit tests, for custom in-house key vaults, and as a
		 * forward-compatibility shim when core renames the function.
		 *
		 * @hook perflocale/connectors/resolver
		 *
		 * @param null|callable $resolver Default null (auto-detect).
		 */
		$custom = apply_filters( 'perflocale/connectors/resolver', null );

		if ( is_callable( $custom ) ) {
			return $custom;
		}

		// Core's WP 7.0 wp_get_connector() fires _doing_it_wrong for ids that
		// were never registered — and most sites register none of our MT
		// providers. Probe for a registration checker (same name-probing as
		// the getter below, since this plugin supports WP < 7.0) so every
		// settings load doesn't spray "Connector not found" notices into
		// debug.log.
		$exists_fn = null;

		foreach ( [ 'wp_is_connector_registered', 'wp_connector_is_registered' ] as $probe ) {
			if ( function_exists( $probe ) ) {
				$exists_fn = $probe;
				break;
			}
		}

		foreach ( [ 'wp_get_connector_key', 'wp_get_connector', 'wp_connector_get_key' ] as $fn ) {
			if ( function_exists( $fn ) ) {
				return static function ( string $slug ) use ( $fn, $exists_fn ): ?string {
					if ( $exists_fn !== null && ! $exists_fn( $slug ) ) {
						return null;
					}

					$value = $fn( $slug );

					if ( is_string( $value ) ) {
						return $value;
					}

					if ( is_array( $value ) && isset( $value['api_key'] ) && is_string( $value['api_key'] ) ) {
						return $value['api_key'];
					}

					if ( is_object( $value ) && method_exists( $value, 'get_key' ) ) {
						$key = $value->get_key();

						return is_string( $key ) ? $key : null;
					}

					return null;
				};
			}
		}

		return null;
	}

	/**
	 * Map of setting keys to wp-config.php constant names.
	 *
	 * When a constant is defined, it takes priority over the database value.
	 */
	private const CONSTANT_MAP = [
		'mt_deepl_api_key'      => 'PERFLOCALE_DEEPL_API_KEY',
		'mt_google_api_key'     => 'PERFLOCALE_GOOGLE_API_KEY',
		'mt_microsoft_api_key'  => 'PERFLOCALE_MICROSOFT_API_KEY',
		'mt_libre_api_key'      => 'PERFLOCALE_LIBRE_API_KEY',
		'mt_libre_url'          => 'PERFLOCALE_LIBRE_URL',
		'mt_agency_url'         => 'PERFLOCALE_AGENCY_URL',
		'mt_agency_api_key'     => 'PERFLOCALE_AGENCY_API_KEY',
	];

	/**
	 * Get a setting value by key.
	 *
	 * @param string $key Setting key.
	 * @param mixed $default Default value if key not found.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->load();

		// wp-config.php constant override wins. Resolved-once map built in
		// load() — saves re-running isset(CONSTANT_MAP) + defined() per call.
		if ( isset( $this->constant_overrides[ $key ] ) ) {
			return $this->constant_overrides[ $key ];
		}

		if ( array_key_exists( $key, $settings ) && $settings[ $key ] !== null ) {
			return $settings[ $key ];
		}

		if ( $default !== null ) {
			return $default;
		}

		return self::DEFAULTS[ $key ] ?? null;
	}

	/**
	 * Check if a setting is currently overridden by either an environment
	 * variable or a PHP constant (i.e. its DB value is being ignored).
	 *
	 * Saving the SettingsPage form should skip writing to the DB for any
	 * key returning true here — otherwise the admin's edit appears to do
	 * nothing because the override wins on the next read.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_overridden( string $key ): bool {
		$this->load();
		return isset( $this->constant_overrides[ $key ] );
	}

	/**
	 * Identify which external source supplied an overridden value.
	 *
	 * @param string $key Setting key.
	 * @return string|null 'env' if from getenv(), 'constant' if from a PHP
	 *                     constant, 'connector' if from the WP 7.0+
	 *                     Connectors API, null when the DB value is in effect.
	 */
	public function get_override_source( string $key ): ?string {
		$this->load();
		return $this->override_sources[ $key ] ?? null;
	}

	/**
	 * Get the constant name for a setting key.
	 *
	 * @param string $key Setting key.
	 * @return string Constant name or empty string.
	 */
	public function get_constant_name( string $key ): string {
		return self::CONSTANT_MAP[ $key ] ?? '';
	}

	/**
	 * Update settings.
	 *
	 * Merges provided values with existing settings, sanitizes, and saves.
	 *
	 * @param array<string, mixed> $values Key-value pairs to update.
	 * @return bool True on success.
	 */
	public function update( array $values ): bool {
		$sanitized = $this->sanitize( $values );

		// Lock the read-modify-write so two concurrent saves (admin + admin, or
		// admin + REST) don't both read the pre-write blob and have the second
		// writer clobber keys the first changed. 10s TTL covers the
		// read+sanitize+write; on lock-acquire failure (another save mid-flight)
		// we retry once before giving up.
		$result = \PerfLocale\Concurrency\Lock::with(
			'settings_update',
			10,
			function () use ( $sanitized ): array {
				// Inside the critical section, force a fresh DB read. Without
				// this, an earlier get() in the same request will have populated
				// $this->settings, and load() returns the in-memory copy that
				// pre-dates any concurrent writer that committed while we were
				// acquiring the lock — exactly the lost-update race the lock
				// is meant to prevent. wp_cache_delete on the options group
				// also busts WP's alloptions/options cache so get_option below
				// hits the DB.
				$this->reset_cache();
				wp_cache_delete( self::OPTION_KEY, 'options' );

				$current = $this->load();
				$merged  = array_merge( $current, $sanitized );

				// autoload='yes' - the ~4 KB blob is read on nearly every request
				// (frontend language routing, hreflang, URL rewriting) so paying for
				// it inside the bundled alloptions fetch is strictly cheaper than a
				// second DB round-trip. Previous iterations opted out, citing memory;
				// at this payload size that concern is not measurable.
				$saved = update_option( self::OPTION_KEY, $merged, true );

				// Update the in-memory cache only on a successful write — else
				// an update_option failure would leave Settings::$settings
				// ahead of the DB and get() would return unpersisted values.
				// update_option also returns false for a no-op (value already
				// current); that case is harmless, so if the merged blob equals
				// $current we sync the memo anyway.
				if ( (bool) $saved ) {
					$this->settings = $merged;
				} elseif ( $merged === $current ) {
					// Idempotent no-op save; keep the in-memory cache
					// aligned so an early get() before the next load()
					// can't observe a stale entry.
					$this->settings = $merged;
				}

				// Only flag rewrite-rule flush when a URL-affecting setting changed.
				// Non-URL changes (MT keys, switcher UI options, etc.) don't warrant
				// a flush - this avoids the rewrite-regeneration cost after trivial
				// settings saves.
				if ( (bool) $saved && $this->url_affecting_change( $current, $merged ) ) {
					update_option( 'perflocale_flush_rules', 1, false );
				}

				return [
					'ok'      => (bool) $saved,
					'merged'  => $merged,
					'current' => $current,
				];
			}
		);

		if ( $result === null ) {
			// Lock unavailable — another save is in flight. Don't silently
			// drop the user's input; surface as a soft failure and let the
			// caller retry. The settings page treats `false` as "save
			// failed" and shows a notice.
			return false;
		}

		/** @hook perflocale/settings/updated Fires after settings are saved. */
		do_action( 'perflocale/settings/updated', $result['merged'], $result['current'] );

		return (bool) $result['ok'];
	}

	/**
	 * Keys that, when changed, require a rewrite-rule flush.
	 *
	 * @var array<int, string>
	 */
	private const URL_AFFECTING_KEYS = [
		'url_mode',
		'url_prefix_type',
		'hide_default_prefix',
		'translatable_post_types',
		'translatable_taxonomies',
		'translate_slugs',
		'language_domains',
		'excluded_paths',
	];

	/**
	 * Did any URL-affecting setting change between two settings snapshots?
	 *
	 * @param array<string, mixed> $before Previous settings.
	 * @param array<string, mixed> $after New settings (merged).
	 * @return bool
	 */
	private function url_affecting_change( array $before, array $after ): bool {
		foreach ( self::URL_AFFECTING_KEYS as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize setting values.
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, mixed> Sanitized values.
	 */
	private function sanitize( array $values ): array {
		$sanitized = [];

		foreach ( $values as $key => $value ) {
			// Only allow known keys.
			if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s is the unknown settings key being silently dropped. */
							__( 'Settings::update() received unknown key "%s" — silently dropped. Verify the key against Settings::DEFAULTS, or extend the schema.', 'perflocale' ),
							(string) $key
						)
					),
					'1.0.0'
				);
				continue;
			}

			// Skip keys overridden by env var or PHP constant — don't store
			// in database, otherwise the admin's edit appears to do nothing
			// because the override wins on the next read.
			if ( $this->is_overridden( $key ) ) {
				continue;
			}

			// HTML-safe keys that use wp_kses_post instead of sanitize_text_field.
			$is_html_key = false;

			// Keys that store nested associative arrays (not flat string arrays).
			// Without this dispatch the `is_array( DEFAULTS[$key] )` branch
			// would flatten them via sanitize_text_field, corrupting the
			// structure (each row becomes the string "Array").
			$is_nested_array = in_array( $key, [ 'wc_currencies', 'language_fallbacks' ], true );

			$sanitized[ $key ] = match ( true ) {
				$is_nested_array => $this->sanitize_nested_array( $key, (array) $value ),
				is_bool( self::DEFAULTS[ $key ] ) => (bool) $value,
				is_int( self::DEFAULTS[ $key ] ) => absint( $value ),
				is_array( self::DEFAULTS[ $key ] ) => $this->sanitize_flat_array( (array) $value ),
				$is_html_key => wp_kses_post( (string) $value ),
				is_string( self::DEFAULTS[ $key ] ) => sanitize_text_field( (string) $value ),
				default => $value,
			};
		}

		return $sanitized;
	}

	/**
	 * Sanitize a flat settings array — string KEYS included, not just values.
	 *
	 * Flat arrays here are either lists (numeric keys: excluded_paths,
	 * detection_order, translatable post types/taxonomies) or slug-keyed maps
	 * (language_domains, geo_country_map). List keys pass through; string
	 * keys are slugs and get sanitize_key'd so a crafted POST can't smuggle
	 * arbitrary bytes into stored option keys. Non-scalar values are dropped
	 * (nested arrays belong to sanitize_nested_array()).
	 *
	 * @param array<int|string, mixed> $value Raw array.
	 * @return array<int|string, string> Sanitized array.
	 */
	private function sanitize_flat_array( array $value ): array {
		$out = [];

		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) ) {
				$k = sanitize_key( $k );

				if ( $k === '' ) {
					continue;
				}
			}

			if ( ! is_scalar( $v ) ) {
				continue;
			}

			$out[ $k ] = sanitize_text_field( (string) $v );
		}

		return $out;
	}

	/**
	 * Sanitize a nested settings array.
	 *
	 * Dispatches to the correct sanitizer for each known nested key.
	 *
	 * @param string               $key Setting key.
	 * @param array<string, mixed> $value Raw value array.
	 * @return array<string, mixed> Sanitized array.
	 */
	private function sanitize_nested_array( string $key, array $value ): array {
		if ( $key === 'wc_currencies' ) {
			return $this->sanitize_wc_currencies( $value );
		}

		if ( $key === 'language_fallbacks' ) {
			return $this->sanitize_language_fallbacks( $value );
		}

		return [];
	}

	/**
	 * Sanitize the per-language fallback-chain map.
	 *
	 * Expected input: `[ 'en-us' => [ 'en-gb', 'en-ca', 'de-de' ], ... ]`.
	 *
	 * Drops self-references, empty entries, duplicates (preserving order),
	 * and clips each list to {@see self::MAX_FALLBACK_DEPTH} as a final
	 * safety net against runaway storage.
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, array<int, string>>
	 */
	private function sanitize_language_fallbacks( array $raw ): array {
		$out = [];

		foreach ( $raw as $slug => $list ) {
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' ) {
				continue;
			}

			if ( ! is_array( $list ) ) {
				continue;
			}

			$clean = [];

			foreach ( $list as $entry ) {
				$entry = sanitize_key( (string) $entry );

				if ( $entry === '' || $entry === $slug ) {
					continue;
				}

				if ( in_array( $entry, $clean, true ) ) {
					continue;
				}

				$clean[] = $entry;

				if ( count( $clean ) >= self::MAX_FALLBACK_DEPTH ) {
					break;
				}
			}

			if ( $clean !== [] ) {
				$out[ $slug ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * Sanitize the per-language currency configuration array.
	 *
	 * Expected input: `[ 'de' => [ 'currency_code' => 'EUR', 'exchange_rate' => '1.2' ], ... ]`
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, array{currency_code: string, exchange_rate: float}> Sanitized currencies.
	 */
	private function sanitize_wc_currencies( array $raw ): array {
		$out = [];

		foreach ( $raw as $slug => $data ) {
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' || ! is_array( $data ) ) {
				continue;
			}

			$code = strtoupper( substr( sanitize_text_field( (string) ( $data['currency_code'] ?? '' ) ), 0, 3 ) );

			if ( $code === '' ) {
				continue;
			}

			$display  = sanitize_key( (string) ( $data['display'] ?? 'symbol' ) );
			$position = sanitize_key( (string) ( $data['position'] ?? 'default' ) );

			$out[ $slug ] = [
				'currency_code' => $code,
				'exchange_rate' => max( 0.0001, (float) ( $data['exchange_rate'] ?? 1.0 ) ),
				// Pin this currency's rate against auto-sync. Without persisting
				// this flag the manual-rate override in MultiCurrency /
				// ExchangeRateSync was dead — auto-sync always won.
				'manual_rate'   => ! empty( $data['manual_rate'] ),
				'display'       => in_array( $display, [ 'symbol', 'code' ], true ) ? $display : 'symbol',
				'position'      => in_array( $position, [ 'default', 'left', 'left_space', 'right', 'right_space' ], true ) ? $position : 'default',
			];
		}

		return $out;
	}

	/**
	 * Get all default values.
	 *
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Reset the internal cache - forces reload on next access.
	 *
	 * @return void
	 */
	public function reset_cache(): void {
		$this->settings                      = null;
		$this->constant_overrides            = null;
		$this->url_prefix_cache              = [];
		$this->translatable_post_types_cache = null;
		$this->translatable_taxonomies_cache = null;
		$this->translatable_meta_keys_cache  = [];
	}

	// -------------------------------------------------------------------------
	// Typed convenience getters.
	// -------------------------------------------------------------------------

	/**
	 * Get the URL mode (subdirectory, subdomain, domain, or query).
	 *
	 * @return string
	 */
	public function get_url_mode(): string {
		$mode = (string) $this->get( 'url_mode' );

		/**
		 * Override the URL mode in code (e.g. per environment via wp-config).
		 *
		 * MUST return the same value for every call within a site — every
		 * generated link, rewrite rule, and cached URL (hreflang alternates
		 * live 12h) assumes one mode per site. Vary it per environment or
		 * per blog, never per request. Unknown values fall back to the
		 * stored setting.
		 *
		 * @hook perflocale/url_mode
		 * @param string $mode One of 'subdirectory', 'subdomain', 'domain', 'query'.
		 */
		$filtered = (string) apply_filters( 'perflocale/url_mode', $mode );

		return in_array( $filtered, [ 'subdirectory', 'subdomain', 'domain', 'query' ], true ) ? $filtered : $mode;
	}

	/**
	 * Get the per-language domain map.
	 *
	 * Returns an array of language_slug => domain (e.g., ['en' => 'example.com', 'fr' => 'example.fr']).
	 * Used for subdomain and per-language domain URL modes.
	 *
	 * @return array<string, string>
	 */
	public function get_language_domains(): array {
		$domains = $this->get( 'language_domains' );

		return is_array( $domains ) ? $domains : [];
	}

	/**
	 * Get the domain for a specific language.
	 *
	 * @param string $slug Language slug.
	 * @return string Domain or empty string.
	 */
	public function get_language_domain( string $slug ): string {
		$domains = $this->get_language_domains();

		return $domains[ $slug ] ?? '';
	}

	/**
	 * Get the URL prefix type (slug or locale).
	 *
	 * @return string 'slug' or 'locale'.
	 */
	public function get_url_prefix_type(): string {
		$type = (string) $this->get( 'url_prefix_type', 'slug' );

		return in_array( $type, [ 'slug', 'locale' ], true ) ? $type : 'slug';
	}

	/**
	 * Get the URL prefix for a language object.
	 *
	 * Returns the slug (e.g. "fr") or the locale in URL format (e.g. "fr-fr")
	 * depending on the url_prefix_type setting.
	 *
	 * @param object $language Language object with slug and locale properties.
	 * @return string URL prefix.
	 */
	public function get_url_prefix( object $language ): string {
		$id = (int) ( $language->id ?? 0 );

		if ( $id > 0 && isset( $this->url_prefix_cache[ $id ] ) ) {
			return $this->url_prefix_cache[ $id ];
		}

		if ( $this->get_url_prefix_type() === 'locale' ) {
			$prefix = strtolower( str_replace( '_', '-', $language->locale ) );
		} else {
			$prefix = $language->slug;
		}

		if ( $id > 0 ) {
			$this->url_prefix_cache[ $id ] = $prefix;
		}

		return $prefix;
	}

	/**
	 * Whether to hide the language prefix for the default language.
	 *
	 * @return bool
	 */
	public function hide_default_prefix(): bool {
		return (bool) $this->get( 'hide_default_prefix' );
	}

	/**
	 * Get the language detection order.
	 *
	 * @return array<int, string>
	 */
	public function get_detection_order(): array {
		return (array) $this->get( 'language_detection_order' );
	}

	/**
	 * Get the priority order for the redirect-on-first-visit handlers.
	 *
	 * When both `redirect_browser_lang` and `redirect_geo_enabled` are on,
	 * the position of each method in this array determines which `template_
	 * redirect` priority their hooks register at. Lower index = earlier hook
	 * = wins when both want to redirect.
	 *
	 * Wraps the persisted setting with the
	 * `perflocale/redirect/priority_order` filter so integrators can override
	 * the order per request without writing to the option.
	 *
	 * @return array<int, string> Ordered list of method keys ('geo', 'browser').
	 */
	public function get_redirect_priority_order(): array {
		$order = (array) $this->get( 'redirect_priority_order' );

		// Sanity: drop unknown values, dedupe, preserve order.
		$known   = [ 'geo', 'browser', 'edge_hint' ];
		$cleaned = [];

		foreach ( $order as $method ) {
			if ( is_string( $method ) && in_array( $method, $known, true ) && ! in_array( $method, $cleaned, true ) ) {
				$cleaned[] = $method;
			}
		}

		// Backfill any known method missing from the saved order so the array
		// is always exhaustive - downstream code can `array_search()` without
		// a "method not in order" edge case.
		foreach ( $known as $method ) {
			if ( ! in_array( $method, $cleaned, true ) ) {
				$cleaned[] = $method;
			}
		}

		/**
		 * Filter the redirect priority order.
		 *
		 * Lets integrators override the persisted setting per request -
		 * e.g. force `geo` first for staff cookie holders, or fall back to
		 * `browser` while a third-party geo provider is rate-limited.
		 *
		 * @param array<int, string> $order Sanitised priority order.
		 */
		return apply_filters( 'perflocale/redirect/priority_order', $cleaned );
	}

	/**
	 * Get translatable post types.
	 *
	 * @return array<int, string>
	 */
	public function get_translatable_post_types(): array {
		if ( $this->translatable_post_types_cache !== null ) {
			return $this->translatable_post_types_cache;
		}

		/** @hook perflocale/translatable_post_types Filter the translatable post types. */
		$result = (array) apply_filters( 'perflocale/translatable_post_types', (array) $this->get( 'translatable_post_types' ) );

		if ( did_action( 'plugins_loaded' ) ) {
			$this->translatable_post_types_cache = $result;
		}

		return $result;
	}

	/**
	 * Get translatable taxonomies.
	 *
	 * @return array<int, string>
	 */
	public function get_translatable_taxonomies(): array {
		// Cache per-request, but only after all plugins have loaded (so addon
		// filters like WooCommerce's pa_* taxonomies are registered). Before
		// plugins_loaded completes, return without caching to avoid locking
		// in an incomplete list.
		if ( $this->translatable_taxonomies_cache !== null ) {
			return $this->translatable_taxonomies_cache;
		}

		/** @hook perflocale/translatable_taxonomies Filter the translatable taxonomies. */
		$result = (array) apply_filters( 'perflocale/translatable_taxonomies', (array) $this->get( 'translatable_taxonomies' ) );

		// Only lock the cache after plugins_loaded has fired.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->translatable_taxonomies_cache = $result;
		}

		return $result;
	}

	/**
	 * Get meta keys that should sync across translations.
	 *
	 * Combines the stored `translatable_meta_keys` setting with whatever
	 * addons contribute through the `perflocale/translatable_meta_keys`
	 * filter. Used by ContentSync to auto-sync builder/plugin meta keys
	 * without requiring the user to add each one manually in settings.
	 *
	 * @param string $post_type Post type context for the filter (empty for any).
	 * @return array<int, string>
	 */
	public function get_translatable_meta_keys( string $post_type = '' ): array {
		// Per-post-type cache. Populated only after plugins_loaded so addon
		// filters have had a chance to register - mirrors the same pattern
		// used by get_translatable_post_types()/_taxonomies(). Stored on the
		// instance (not a function-static) so reset_cache() can clear it on
		// switch_blog / settings save.
		$key = $post_type === '' ? '__any__' : $post_type;

		if ( isset( $this->translatable_meta_keys_cache[ $key ] ) ) {
			return $this->translatable_meta_keys_cache[ $key ];
		}

		/**
		 * Filter the list of meta keys that should sync across translations.
		 *
		 * @hook perflocale/translatable_meta_keys
		 * @param array<int, string> $keys Meta keys.
		 * @param string $post_type Post type (may be empty).
		 */
		$result = (array) apply_filters(
			'perflocale/translatable_meta_keys',
			(array) $this->get( 'translatable_meta_keys' ),
			$post_type
		);

		// Deduplicate + drop empties so callers can safely array_merge.
		$result = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $k ): string => (string) $k, $result ),
					static fn( string $k ): bool => $k !== ''
				)
			)
		);

		if ( did_action( 'plugins_loaded' ) ) {
			$this->translatable_meta_keys_cache[ $key ] = $result;
		}

		return $result;
	}

	/**
	 * Whether slug translation is enabled.
	 *
	 * @return bool
	 */
	public function translate_slugs_enabled(): bool {
		return (bool) $this->get( 'translate_slugs' );
	}

	/**
	 * Whether machine translation is enabled.
	 *
	 * @return bool
	 */
	public function mt_enabled(): bool {
		return (bool) $this->get( 'mt_enabled' );
	}

	/**
	 * Get the active machine translation provider slug.
	 *
	 * @return string
	 */
	public function get_mt_provider(): string {
		return (string) $this->get( 'mt_provider' );
	}

	/**
	 * Whether the Strings page bulk-MT toolbar is enabled. Independent of
	 * mt_enabled (which is the master switch) — defaults ON so existing
	 * installs that enable MT also get the bulk toolbar without an extra
	 * settings dance, but admins worried about provider costs can flip
	 * this OFF to keep per-row MT only.
	 *
	 * @return bool
	 */
	public function mt_bulk_strings_enabled(): bool {
		return (bool) $this->get( 'mt_bulk_strings_enabled', true );
	}

	/**
	 * Whether SEO hreflang output is enabled.
	 *
	 * @return bool
	 */
	public function hreflang_enabled(): bool {
		return (bool) $this->get( 'seo_hreflang_enabled' );
	}

	/**
	 * Get the action when a translation is missing.
	 *
	 * @return string 'show_default', 'show_404', or 'redirect_default'.
	 */
	public function get_missing_translation_action(): string {
		return (string) $this->get( 'missing_translation_action' );
	}

	/**
	 * Max fallback-list length. Admin UI only exposes 4 slots; this cap is
	 * a safety net for values written directly to the option via API.
	 */
	public const MAX_FALLBACK_DEPTH = 10;

	/**
	 * Sentinel stored as the single element of mt_auto_translate_languages
	 * when the admin unchecks EVERY target language. The empty array already
	 * means "all active languages" (so languages added later are included
	 * automatically), which left "none" unrepresentable — saving all-unchecked
	 * used to silently re-enable every language. Consumers need no special
	 * handling: no real language uses this slug, so a scope containing only
	 * this token simply matches nothing.
	 */
	public const MT_SCOPE_NONE = '__none';

	/**
	 * Get per-language fallback chains as an ordered list of slugs.
	 *
	 * Storage shape: `[ 'en-us' => [ 'en-gb', 'en-ca', 'de-de' ], ... ]`.
	 *
	 * Guardrails applied on every read: slug-sanitise every entry, drop
	 * self-references, drop empties, dedupe preserving order, clip to
	 * {@see self::MAX_FALLBACK_DEPTH} to prevent runaway-length lists.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function get_language_fallbacks(): array {
		static $cache     = null;
		static $cache_key = null;

		$raw = (array) $this->get( 'language_fallbacks' );
		$key = md5( (string) wp_json_encode( $raw ) );

		if ( $cache !== null && $cache_key === $key ) {
			return $cache;
		}

		$normalised = [];

		foreach ( $raw as $slug => $value ) {
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' ) {
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$list = [];

			foreach ( $value as $entry ) {
				$entry = sanitize_key( (string) $entry );

				if ( $entry === '' || $entry === $slug ) {
					continue; // drop empties and self-references
				}

				if ( in_array( $entry, $list, true ) ) {
					continue; // dedupe
				}

				$list[] = $entry;

				if ( count( $list ) >= self::MAX_FALLBACK_DEPTH ) {
					break; // safety cap
				}
			}

			if ( $list !== [] ) {
				$normalised[ $slug ] = $list;
			}
		}

		$cache     = $normalised;
		$cache_key = $key;

		return $normalised;
	}

	/**
	 * Get excluded URL paths that bypass language routing.
	 *
	 * @return array<int, string>
	 */
	public function get_excluded_paths(): array {
		/** @hook perflocale/excluded_paths Filter URL paths excluded from language routing. */
		return (array) apply_filters( 'perflocale/excluded_paths', (array) $this->get( 'excluded_paths' ) );
	}

	/**
	 * Whether edge-worker integration is enabled.
	 *
	 * Controls the `/wp-json/perflocale/v1/config` public endpoint AND
	 * whether the `edge_hint` detection method (header/cookie hint from
	 * a Cloudflare Worker / Vercel Edge / Netlify edge) is honoured.
	 *
	 * @return bool
	 */
	public function edge_integration_enabled(): bool {
		/**
		 * Programmatic override - forced state wins over the stored setting.
		 *
		 * @hook perflocale/edge/enabled
		 * @param bool $enabled Current effective state.
		 */
		return (bool) apply_filters( 'perflocale/edge/enabled', (bool) $this->get( 'edge_integration_enabled' ) );
	}

	/**
	 * Whether CDN `Cache-Tag` response header emission is enabled.
	 *
	 * @return bool
	 */
	public function cdn_cache_tags_enabled(): bool {
		/**
		 * Programmatic override for CDN cache-tag emission.
		 *
		 * @hook perflocale/cache_tags/enabled
		 * @param bool $enabled Current effective state.
		 */
		return (bool) apply_filters( 'perflocale/cache_tags/enabled', (bool) $this->get( 'cdn_cache_tags_enabled' ) );
	}

	/**
	 * Whether per-language JSON-LD schema enrichment is enabled.
	 *
	 * @return bool
	 */
	public function seo_schema_enrichment_enabled(): bool {
		/**
		 * Programmatic override for schema enrichment in SEO addons.
		 *
		 * @hook perflocale/seo/schema_enrichment_enabled
		 * @param bool $enabled Current effective state.
		 */
		return (bool) apply_filters(
			'perflocale/seo/schema_enrichment_enabled',
			(bool) $this->get( 'seo_schema_enrichment_enabled' )
		);
	}
}
