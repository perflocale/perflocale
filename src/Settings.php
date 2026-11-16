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
 * Typed wrapper around the perflocale_settings option, loaded once per request.
 */
final class Settings {

	/**
	 * Option key in wp_options.
	 */
	public const OPTION_KEY = 'perflocale_settings';

	/**
	 * Default values for all settings.
	 *
	 * The geo proxy-trust keys are left out on purpose (docs-ai/04-do-not-fix.md 2.21).
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
		// Redirect to the edge worker's language hint; needs `edge_integration_enabled` too.
		'redirect_edge_hint_enabled'     => false,
		// First entry wins: it registers earlier on `template_redirect`.
		'redirect_priority_order'        => [ 'browser', 'geo', 'edge_hint' ],
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

		// Abilities API: capability-gated reads on; writes off because translate-post spends MT budget.
		'abilities_enabled'              => true,
		'abilities_write_enabled'        => false,
		'sync_fields'                    => [ 'featured_image', 'menu_order' ],
		'sync_term_hierarchy'            => true,
		'translate_slugs'                => true,
		// Content ReSync (ResyncSettings): on/off (Util\ResyncSwitch), types left out (empty = all), who fills changed
		// parts, near-match threshold, keep a translation's own blocks, hold a change to a person's block always or once.
		'resync_enabled'                 => false,
		'resync_excluded'                => [],
		'resync_changed_parts'           => 'source',
		'resync_match_threshold'         => 100,
		'resync_keep_exceptions'         => true,
		'resync_person_blocks'           => 'always',

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
		'mt_agency_batch'                => false,
		'mt_agency_max_chars'            => 0,
		'mt_auto_translate_on_publish'   => false,
		// Both auto-translate flows; empty = every active language, including later ones.
		'mt_auto_translate_languages'    => [],
		'mt_auto_translate_on_create'    => false,
		'mt_monthly_char_limit'          => 500000,
		// Cost switch for the Strings bulk-MT toolbar; per-row MT stays available.
		'mt_bulk_strings_enabled'        => true,
		// Veto a bulk MT dispatch whose estimate exceeds the monthly cap, before any provider spend.
		'mt_enforce_cap_on_bulk'         => true,
		// MT of SEO meta is cheap (on); custom fields scale with the site (opt-in).
		'mt_meta_seo'                    => true,
		// SEO focus keywords (Rank Math, Yoast): opt-in, and only with mt_meta_seo on.
		'mt_meta_seo_keywords'           => false,
		'mt_meta_custom_fields'          => false,
		// Context sent with each piece (MachineTranslation\ContextSettings): off, neighbours or section; its size bounds.
		'mt_context_mode'                => 'off',
		'mt_context_neighbours'          => 2,
		'mt_context_previous'            => true,
		'mt_context_max_chars'           => 1500,

		// SEO.
		'seo_hreflang_enabled'           => true,
		'seo_hreflang_placement'         => 'head',
		'seo_x_default'                  => true,
		// True lists every active language in hreflang, assuming each renders 200 via a fallback.
		'seo_hreflang_include_fallbacks' => false,
		'seo_sitemap_enabled'            => true,
		// Sitemap tree for alternates: auto (SEO plugin's if detected, else core), core or plugin.
		'seo_sitemap_source'             => 'auto',
		'seo_plugin'                     => 'none',
		'seo_og_locale'                  => true,

		// Language Switcher.
		'switcher_template'              => 'flags_names',
		// Classic-menu locations the switcher is appended to; block themes use the block.
		'switcher_menu_locations'        => [],
		'switcher_display'               => 'dropdown',
		'switcher_layout'                => 'horizontal',
		'switcher_name_format'           => 'native',
		'switcher_class'                 => '',
		'switcher_flag_style'            => 'rectangular',
		'switcher_show_untranslated'     => false,
		'switcher_hide_current'          => true,
		'switcher_untranslated_link'     => 'homepage',
		// Dropdown chevron: single, double or none; `perflocale/switcher/arrow_html` fires for all three.
		'switcher_arrow_style'           => 'single',
		// Dropdown trigger label format; `inherit` reuses `switcher_name_format`.
		'switcher_trigger_format'        => 'inherit',
		'admin_bar_switcher'             => true,
		// Block Hooks insertion of the switcher after core/site-title in block templates.
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

		// Background processing: see docs-ai/11-code-rationale.md, "Settings: background processing keys".
		'background_processing'          => 'auto',
		'background_engine'              => 'auto',
		'background_thresholds'          => [],
		'background_paused'              => false,

		// Integrations (CDN / edge / schema).
		'edge_integration_enabled'       => false,
		'cdn_cache_tags_enabled'         => false,
		'seo_schema_enrichment_enabled'  => true,

		// Modern SEO features.
		'content_language_header'        => true,

		// Wraps content in data-nosnippet when the post's language differs from the URL's.
		'fallback_nosnippet'             => true,

		// Speculation Rules prerender on switcher hover; experimental, so off.
		'prerender_on_hover'             => false,

		// View Transitions on language switch; off because some themes animate navigation themselves.
		'view_transitions_enabled'       => false,

		// Dashboard translation-overview widget (cached counts).
		'dashboard_widget_enabled'       => false,

		// Settings schema version for migrations.
		'schema_version'                 => 1,
	];

	/**
	 * Cached settings array - loaded once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Values from the environment, constants or Connectors, resolved once in load().
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $constant_overrides = null;

	/**
	 * URL prefix by language ID; get_url_prefix() runs for every permalink.
	 *
	 * @var array<int, string>
	 */
	private array $url_prefix_cache = [];

	/**
	 * Memo of get_translatable_post_types(); a property so reset_cache() can clear it.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $translatable_post_types_cache = null;

	/**
	 * @var array<int, string>|null
	 */
	private ?array $translatable_taxonomies_cache = null;

	/**
	 * Per-post-type memo of get_translatable_meta_keys(); a property so reset_cache() can clear it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $translatable_meta_keys_cache = [];

	/**
	 * Per-key origin of the resolved override ('env', 'constant' or 'connector').
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

		// Resolved once so get() stays an array lookup. Priority: env, constant, Connectors, database.
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

				// An empty constant is "not set": it must not blank a saved value.
				if ( ! is_string( $value ) || trim( $value ) !== '' ) {
					$this->constant_overrides[ $setting_key ] = is_string( $value ) ? trim( $value ) : $value;
					$this->override_sources[ $setting_key ]   = 'constant';
				}
			}
		}

		$this->maybe_apply_connectors_overrides();

		return $this->settings;
	}

	/**
	 * Setting keys mapped to WordPress Connectors API slugs; inert without that API.
	 */
	private const CONNECTOR_MAP = [
		'mt_deepl_api_key'     => 'deepl',
		'mt_google_api_key'    => 'google-cloud-translation',
		'mt_microsoft_api_key' => 'microsoft-translator',
		'mt_libre_api_key'     => 'libretranslate',
	];

	/**
	 * Add Connectors API keys to the overrides: below env and constants, above the database.
	 *
	 * @return void
	 */
	private function maybe_apply_connectors_overrides(): void {
		$resolver = $this->resolve_connectors_callback();

		if ( $resolver === null ) {
			return;
		}

		/**
		 * Filter the setting-key to connector-slug map; an unknown slug falls through to the database.
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
			// Env / constant already supplied a value - keep their priority.
			if ( isset( $this->constant_overrides[ $setting_key ] ) ) {
				continue;
			}

			try {
				$value = $resolver( $connector_slug );
			} catch ( \Throwable $e ) {
				// Connector lookup failed - fall through to the DB value.
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
	 * Callable that reads a key from the Connectors API, or null when none exists.
	 *
	 * Probes several function names because core has not settled on one.
	 *
	 * @return null|callable(string): ?string
	 */
	private function resolve_connectors_callback(): ?callable {
		/**
		 * Filter the callable that maps a connector slug to its key (null when not registered).
		 *
		 * @hook perflocale/connectors/resolver
		 *
		 * @param null|callable $resolver Default null (auto-detect).
		 */
		$custom = apply_filters( 'perflocale/connectors/resolver', null );

		if ( is_callable( $custom ) ) {
			return $custom;
		}

		// wp_get_connector() raises _doing_it_wrong for unregistered ids, so check registration first.
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

		// An external override wins over the stored value.
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
	 * Whether an environment variable, constant or connector overrides the stored value.
	 *
	 * Saves skip such keys: the override would win on the next read anyway.
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
	 * @return string|null 'env', 'constant' or 'connector'; null when the stored value is in effect.
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
	 * Update settings: sanitize, merge onto the stored row and save.
	 *
	 * @param array<string, mixed> $values Key-value pairs to update.
	 * @return bool True on success.
	 */
	/**
	 * Set by {@see update()}; read by {@see last_update_was_noop()}.
	 *
	 * @var bool
	 */
	private static bool $last_update_was_noop = false;

	/**
	 * Set by {@see update()}: another save held the settings lock.
	 *
	 * @var bool
	 */
	private static bool $last_update_was_busy = false;

	/**
	 * Set by {@see update()}; read by {@see last_update_refused_addresses()}.
	 *
	 * @var array<int, string>
	 */
	private static array $last_update_refused = [];

	public function update( array $values ): bool {
		self::$last_update_refused  = [];
		self::$last_update_was_noop = false;
		\PerfLocale\Util\OutboundUrl::forget_refusal();

		$result = $this->writer()->update( $this->sanitize( $values ) );

		self::$last_update_was_busy = null === $result;

		if ( $result === null ) {
			// Another save holds the lock: report a failure so the caller can retry.
			return false;
		}

		self::$last_update_refused  = $result['refused'];
		self::$last_update_was_noop = $result['noop'];

		/** @hook perflocale/settings/updated Fires after settings are saved. */
		do_action( 'perflocale/settings/updated', $result['merged'], $result['current'] );

		return $result['ok'];
	}

	/**
	 * The writer behind update() and sanitize(), loaded on first use.
	 *
	 * @return Util\SettingsWriter
	 */
	private function writer(): Util\SettingsWriter {
		return new Util\SettingsWriter(
			$this,
			fn(): array => $this->load(),
			function ( array $merged ): void {
				$this->settings = $merged;
			}
		);
	}

	/**
	 * Provider address settings whose change the last update() refused (see OutboundUrl::provider_address_rules()).
	 *
	 * @return array<int, string>
	 */
	public static function last_update_refused_addresses(): array {
		return self::$last_update_refused;
	}

	/**
	 * Whether the last update() wrote nothing because the stored value already matched.
	 *
	 * update() returns false for both a refused write and a no-op; callers that treat false as a failure rely on that.
	 */
	public static function last_update_was_noop(): bool {
		return self::$last_update_was_noop;
	}

	/**
	 * Whether the last update() saved nothing because another save held the settings lock.
	 */
	public static function last_update_was_busy(): bool {
		return self::$last_update_was_busy;
	}

	/**
	 * Sanitize setting values (Util\SettingsWriter::sanitize()).
	 *
	 * @param array<string, mixed> $values Raw values.
	 * @return array<string, mixed> Sanitized values.
	 */
	private function sanitize( array $values ): array {
		return $this->writer()->sanitize( $values );
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
	 * Watch the row from the first instance on, so no read can precede the watcher.
	 */
	public function __construct() {
		self::watch_row();
	}

	/**
	 * Reset the shared memos (Settings, ResyncSwitch) after the row is added, saved or deleted.
	 *
	 * @return void
	 */
	public static function watch_row(): void {
		foreach ( [ 'add', 'update', 'delete' ] as $op ) {
			add_action( $op . '_option_' . self::OPTION_KEY, [ BootstrapWrites::class, 'reset_settings_memo' ], 1 );
		}
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
		 * Override the URL mode in code; unknown values fall back to the stored setting.
		 *
		 * Vary it per environment or blog, never per request: links, rewrite rules and cached URLs assume one mode.
		 *
		 * @hook perflocale/url_mode
		 * @param string $mode One of 'subdirectory', 'subdomain', 'domain', 'query'.
		 */
		$filtered = (string) apply_filters( 'perflocale/url_mode', $mode );

		return in_array( $filtered, [ 'subdirectory', 'subdomain', 'domain', 'query' ], true ) ? $filtered : $mode;
	}

	/**
	 * Get the language slug to domain map used by the subdomain and domain URL modes.
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
	 * Get a language's URL prefix: its slug or its locale in URL form (fr-fr), by url_prefix_type.
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
	 * Get the priority order of the first-visit redirect handlers; a lower index wins.
	 *
	 * @return array<int, string> Ordered list of method keys ('geo', 'browser', 'edge_hint').
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

		// Always exhaustive, so callers can array_search() any known method.
		foreach ( $known as $method ) {
			if ( ! in_array( $method, $cleaned, true ) ) {
				$cleaned[] = $method;
			}
		}

		/**
		 * Filter the redirect priority order per request.
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

		// Never `attachment`, even if stored or filtered in: media is translated in place.
		$result = array_values(
			array_filter(
				$result,
				static fn( $type ): bool => (string) $type !== 'attachment'
			)
		);

		if ( did_action( 'plugins_loaded' ) ) {
			$this->translatable_post_types_cache = $result;
		}

		return $result;
	}

	/**
	 * Registered translatable post types the "translate the entire site" surfaces offer and accept.
	 *
	 * Not the public + show_ui list: see docs-ai/11-code-rationale.md, "Settings: site translate post types".
	 *
	 * @return array<int, string>
	 */
	public function get_site_translate_post_types(): array {
		$types = [];

		foreach ( $this->get_translatable_post_types() as $type ) {
			$type = (string) $type;

			if ( $type === '' || $type === 'attachment' || ! post_type_exists( $type ) ) {
				continue;
			}

			$types[] = $type;
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Translatable post types that are not registered, so a dropped selection can say why.
	 *
	 * @return array<int, string>
	 */
	public function get_unregistered_translatable_post_types(): array {
		$missing = [];

		foreach ( $this->get_translatable_post_types() as $type ) {
			$type = (string) $type;

			if ( $type !== '' && $type !== 'attachment' && ! post_type_exists( $type ) ) {
				$missing[] = $type;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Get translatable taxonomies.
	 *
	 * @return array<int, string>
	 */
	public function get_translatable_taxonomies(): array {
		// Memoised only after plugins_loaded: earlier, add-on filters are not registered yet.
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
	 * Get meta keys that sync across translations: the setting plus add-on filter entries.
	 *
	 * @param string $post_type Post type context for the filter (empty for any).
	 * @return array<int, string>
	 */
	public function get_translatable_meta_keys( string $post_type = '' ): array {
		// Memoised only after plugins_loaded, as for post types.
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
	 * Whether the Strings page bulk-MT toolbar is enabled (mt_enabled is the master switch).
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
	 * Values of missing_translation_action.
	 */
	public const MISSING_ACTIONS = [ 'show_default', 'show_default_noindex', 'show_404', 'redirect_default' ];

	/**
	 * Get the action when a translation is missing; an unknown stored value reads as show_default.
	 *
	 * @return string One of MISSING_ACTIONS.
	 */
	public function get_missing_translation_action(): string {
		$action = $this->get( 'missing_translation_action' );

		return is_string( $action ) && in_array( $action, self::MISSING_ACTIONS, true ) ? $action : 'show_default';
	}

	/**
	 * Max fallback-list length; a cap for values written past the 4-slot admin UI.
	 */
	public const MAX_FALLBACK_DEPTH = 10;

	/**
	 * Sole element of mt_auto_translate_languages when no target is ticked; the empty array means "all".
	 */
	public const MT_SCOPE_NONE = '__none';

	/**
	 * Get per-language fallback chains (`[ 'en-us' => [ 'en-gb', 'de-de' ] ]`), normalised on every read.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function get_language_fallbacks( string $context = '' ): array {
		static $cache = [];

		$raw = (array) $this->get( 'language_fallbacks' );

		/**
		 * Filter the whole fallback map before it is normalised, so a filter cannot add a cycle or an unbounded chain.
		 *
		 * `$context` names the consumer: a string may borrow from a sibling locale where a post should not.
		 *
		 * @hook perflocale/language/fallbacks
		 *
		 * @param array<string, array<int, string>> $raw     Stored map.
		 * @param string                            $context Consumer: 'strings',
		 *                                                   'post_query', 'admin',
		 *                                                   or '' when unstated.
		 */
		$raw = (array) apply_filters( 'perflocale/language/fallbacks', $raw, $context );

		// Keyed by context too: a context-aware filter must not leak one consumer's chain to another.
		$key = $context . '|' . md5( (string) wp_json_encode( $raw ) );

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
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

		$cache[ $key ] = $normalised;

		return $normalised;
	}

	/**
	 * The normalised fallback chain for one language; the filter's result is normalised again.
	 *
	 * @param string $slug    Language slug whose chain is wanted (a variation
	 *                        such as `en-gb` is just another slug).
	 * @param string $context Consumer: 'strings', 'post_query', 'admin', or ''.
	 * @return array<int, string> Ordered fallback slugs, nearest first.
	 */
	public function get_fallback_chain( string $slug, string $context = '' ): array {
		$slug = sanitize_key( $slug );

		if ( $slug === '' ) {
			return [];
		}

		$chain = (array) ( $this->get_language_fallbacks( $context )[ $slug ] ?? [] );

		/**
		 * Filter one language's fallback chain.
		 *
		 * @hook perflocale/language/fallback_chain
		 *
		 * @param array<int, string> $chain   Ordered fallback slugs, nearest first.
		 * @param string             $slug    The language being resolved.
		 * @param string             $context Consumer: 'strings', 'post_query',
		 *                                    'admin', or '' when unstated.
		 */
		$chain = (array) apply_filters( 'perflocale/language/fallback_chain', $chain, $slug, $context );

		// Re-apply the same guardrails to whatever the filter returned.
		$clean = [];

		foreach ( $chain as $entry ) {
			$entry = sanitize_key( (string) $entry );

			if ( $entry === '' || $entry === $slug || in_array( $entry, $clean, true ) ) {
				continue;
			}

			$clean[] = $entry;

			if ( count( $clean ) >= self::MAX_FALLBACK_DEPTH ) {
				break;
			}
		}

		return $clean;
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
	 * Whether edge-worker integration (the public config endpoint and the edge_hint method) is enabled.
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
