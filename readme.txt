=== PerfLocale ===
Contributors: alexgeorgiev
Tags: multilingual, translation, i18n, language, localization
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance-first multilingual plugin. Translate posts, pages, products, taxonomies, strings, and slugs with 3-layer caching and 20+ integrations.

== Description ==

PerfLocale is a **performance-first multilingual plugin** for WordPress. A 3-layer cache, batch-preloaded queries, and conditional hook registration keep its own code to a small fraction of total page time — a few milliseconds per page, A/B-measured.

= What you get =

* **Content translation** - posts, pages, any custom post type, taxonomies, and URL slugs, with translation-status tracking
* **URL routing** - subdirectory (`/en/`), subdomain (`en.example.com`), per-domain, or query-parameter (`?lang=en`) modes; auto-detect from URL, cookie, browser, or an edge/CDN hint
* **String translation** - gettext strings from any plugin or theme, file-based (`.l10n.php`) or database-mode, with full CLDR plural rules (Arabic 6 forms, Russian 3) and context support
* **Language switcher** - block, shortcode, widget, menu, admin-bar, and template tags with full ARIA listbox accessibility
* **SEO** - hreflang (HTML + HTTP) and sitemap alternates; integrates with Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, and Slim SEO
* **Machine translation** - DeepL, Google, Microsoft, LibreTranslate, a custom agency endpoint, and the WordPress 7.0 AI Client, with monthly usage caps
* **Translator role** - a dedicated role with translation-only capabilities for your translation staff
* **E-commerce** - WooCommerce product/variation/attribute translation, multi-currency (rates supplied by your own provider hook), inventory sync, localized order emails
* **Reliability** - circuit breakers around every external dependency, token-guarded atomic locks, self-healing background jobs, and Site Health diagnostics

= Modern SEO & UX =

Translation-aware features generic SEO plugins can't provide: Content-Language HTTP header, `data-nosnippet` guard for fallback pages, and opt-in Speculation Rules prerender and View Transitions for switching between languages. Auto-detects and integrates with 20+ plugins and themes, including WooCommerce, Elementor, ACF, Meta Box, Pods, all major SEO plugins, Gravity Forms, and the Blocksy, Kadence, and Neve themes.

= For developers =

200+ action/filter hooks, a full REST API, WP-CLI commands, and a documented addon system. Every internal primitive is `@api` and semver-stable across 1.x. Multisite-ready. Full docs at **https://perflocale.com/docs/**.

== Installation ==

**Permalinks:** pretty permalinks (**Settings → Permalinks** set to anything other than "Plain") are recommended — with them WordPress guarantees that language-prefixed URLs such as `/de/…` reach WordPress on every server. Plain permalinks also work (URLs become `/de/?p=123`) as long as the server routes all paths to WordPress, which nginx configurations and Apache setups with the standard WordPress `.htaccess` block do. Subdomain and per-domain URL modes carry the language in the hostname and work with any permalink setting. For servers that do not route unknown paths to WordPress, query-parameter mode (URLs become `example.com/page?lang=de`, selectable under **PerfLocale → Settings → URL & Routing**) works with any permalink structure — including Plain — on every server, keeping clean URLs for the default language. Site Health reports the exact status for your server.

1. Upload the `perflocale` folder to `/wp-content/plugins/`
2. Activate PerfLocale through the **Plugins** menu in WordPress
3. Go to **PerfLocale → Languages** and add your languages
4. Set one language as the default
5. Go to **PerfLocale → Settings → URL & Routing** and choose your URL structure (subdirectory, subdomain, per-language domain, or query parameter)
6. Start translating - click the language badges next to any post, page, or term to create translations

= Quick Start =

1. **Add languages**: Go to PerfLocale → Languages. Add English as default, then add Bulgarian, German, etc.
2. **Translate a post**: Edit any post. In the PerfLocale meta box, click a language badge to create a translation. Edit the translation in the standard WordPress editor.
3. **Add the language switcher**: Add the "Language Switcher" block to any template or page, drop the Language Switcher widget into a widget area, or use the `[perflocale_switcher]` shortcode. To append it to a classic theme menu, tick the menu location under **PerfLocale → Settings → Language Switcher → Append to classic menus** (developers can also control it with the `perflocale/switcher/add_to_menu` filter).
4. **Configure SEO**: Go to PerfLocale → Settings → SEO. Enable hreflang tags and select your SEO plugin for automatic integration.
5. **Set up machine translation** (optional): Enable Machine Translation on the PerfLocale → Addons screen, then go to PerfLocale → Settings → Addons → Machine Translation. Enter your API key for DeepL, Google, or Microsoft, and enable auto-translate on publish. For production / staging deployments you can also supply API keys via environment variables (e.g. `PERFLOCALE_DEEPL_API_KEY`) or `wp-config.php` constants of the same name — env wins over constant wins over the database value, matching WordPress 7.0's AI Connectors source-priority pattern. See the API Keys documentation page for the full list of supported names.

== Frequently Asked Questions ==

= Does PerfLocale slow down my site? =

Very little — performance is a core design goal. The plugin's own code stays a small fraction of total page time, typically a few milliseconds per page, with three layers of caching so most requests never touch the database. Larger sites — many translated posts across many languages — benefit from running a persistent object cache like Redis. PerfLocale is fully compatible with page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.); cached pages already include the translated output from the request that filled the cache. Real-world page speed depends mostly on your theme, hosting, and other plugins.

= Does it work with WooCommerce? =

Yes. PerfLocale includes a deep WooCommerce integration: translate products, variations, categories, and attributes. Inventory (stock, SKU, weight, dimensions) syncs automatically across language variants. Multi-currency support, with exchange rates supplied by a provider your site registers via filter. Order emails are sent in the customer's language. The mini-cart, cart, and checkout all display correctly in every language.

= Does it work with page builders? =

Yes. PerfLocale integrates with Elementor, Beaver Builder, Bricks Builder, Oxygen Classic, and Oxygen 6.0. Each builder's content is registered as translatable meta, and dedicated Language Switcher widgets/elements are provided for Elementor, Beaver Builder, and Bricks — in Oxygen (Classic or 6.0) use the `[perflocale_switcher]` shortcode in a Code Block or Shortcode element.

= Can I migrate from WPML, Polylang, or TranslatePress? =

Yes. PerfLocale includes built-in migration tools for all three plugins. Go to PerfLocale → Settings → Export & Import and use the Migration section. All migrations run in batches with transaction safety - if anything fails, your data is rolled back.

= Does it support RTL languages? =

Yes. PerfLocale detects the text direction from the language configuration and sets the correct `dir="rtl"` attribute on the HTML element. The language switcher and admin UI work correctly with RTL languages like Arabic and Hebrew.

= Is it compatible with caching plugins? =

Yes. PerfLocale works with all major caching plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket). Each language version has its own URL, so page caches naturally separate content by language. Any response whose language was decided by something other than the URL — a GeoIP or browser-language redirect, a returning visitor's language cookie — is automatically marked uncacheable (via WordPress' `nocache_headers()`), so one visitor's language can never be cached and served to everyone. On an edge or server cache (Varnish, nginx fastcgi_cache) make sure Cache-Control is honoured or those responses are excluded. Note that enabling GeoIP / browser redirection makes default-language entry URLs uncacheable by design — a page cache serving them from cache would skip the redirect entirely; if you need both, use the bundled edge worker (assets/js/edge-helper.js) to route at the CDN instead.

= Can I use it on multisite? =

Yes. PerfLocale is multisite-compatible. Each site in the network has its own languages and translations. Static caches are properly scoped and reset on blog switches.

= Can I keep API keys out of the database? =

Yes. PerfLocale resolves every machine-translation API key from three sources in priority order: environment variable → `wp-config.php` constant → database setting (whichever has a non-empty value first wins). Set `PERFLOCALE_DEEPL_API_KEY`, `PERFLOCALE_GOOGLE_API_KEY`, `PERFLOCALE_MICROSOFT_API_KEY`, `PERFLOCALE_LIBRE_API_KEY`, `PERFLOCALE_LIBRE_URL`, `PERFLOCALE_AGENCY_URL` or `PERFLOCALE_AGENCY_API_KEY` in your container env or `wp-config.php` and the admin-side fields become read-only, showing which environment variable or `wp-config.php` constant supplies the value. Database backups, exports, and SQL dumps then never contain the secret.

= What happens to my translations if I uninstall the plugin? =

By default nothing is lost: uninstalling removes the plugin's roles, capabilities, scheduled tasks, and caches, but keeps all translations, languages, and settings in the database so a later re-install picks up exactly where you left off. If you want a complete removal instead, enable "Delete all plugin data when uninstalling" in PerfLocale → Settings → Advanced before uninstalling — then every plugin table and option is deleted. Your posts and pages (including translated ones) are always preserved as normal WordPress content.

= Does PerfLocale expose anything to edge workers? =

When you enable Edge Worker Integration (PerfLocale → Settings → Advanced), the plugin publishes a single public REST endpoint that edge runtimes (Cloudflare Workers, Vercel Edge, Netlify Edge, AWS Lambda@Edge) can read to pre-route visitors before the request ever hits PHP:

* `GET /wp-json/perflocale/v1/config` - returns the minimum routing + language metadata an edge worker needs: active language slugs and locales, URL mode (subdirectory / subdomain / domain / query), URL prefix type, default language, hide-default-prefix flag, excluded paths, detection order, the edge-hint header name (`X-PerfLocale-Lang`) and cookie name. Response includes `Cache-Control: public, max-age=300, s-maxage=3600, stale-while-revalidate=86400` plus an `ETag` so edges and browsers can revalidate cheaply with `If-None-Match` (304 on hit).

**The response NEVER contains:** machine-translation API keys, provider tokens, user data, internal post or term IDs, or any data that is not already observable from the rendered site (hreflang tags, language switcher, URL prefixes). That non-sensitive invariant is what justifies the public default. If you extend the payload via the `perflocale/api/config` filter, your additions must preserve this invariant.

Public by default + filter-gated for restriction: site owners who want to restrict access (private staging sites, IP allowlists, Application-Password authentication, mTLS at a reverse proxy) hook the `perflocale/edge_worker/config_permission_callback` filter to return `false` or a `WP_Error` for unauthorised requests. Example:

`add_filter( 'perflocale/edge_worker/config_permission_callback', fn () => current_user_can( 'manage_options' ) );`

The plugin does not invent its own bearer-token or custom-header authentication scheme. Use WordPress' built-in primitives (Application Passwords for machine-to-machine auth, cookie + nonce for browser sessions, or a third-party JWT/OAuth plugin) and gate via this filter — that way your edge-worker auth composes with the rest of your site's WP-REST authentication setup.

== Screenshots ==

1. Front-end language switcher - block, shortcode, or template tag rendered as an accessible ARIA listbox, plus an optional append to classic theme menus
2. WooCommerce cart in German - translated product titles, attributes, and currency
3. PerfLocale dashboard - per-language translation progress for every post type, with draft and outdated counts
4. Settings - Export & Import: bring existing translations across from WPML, Polylang, or TranslatePress as a background job that is safe to re-run
5. Languages screen - add languages, set the default, and mark text direction
6. Settings - Performance: string-translation mode (files or database), object cache, and slug preloading
7. Strings screen - translate gettext strings from any plugin or theme, with PO export and import
8. Settings - URL & Routing: missing-translation action and per-language fallback chains
9. Jobs - bulk and whole-site translation runs in the background: chunked, resumable, with automatic retries and per-blog status

== Privacy ==

PerfLocale is privacy-first by default. No tracking, no analytics, no visitor fingerprinting.

* **Cookie:** one cookie, `perflocale_lang`, stores only the active language slug. `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, 365-day default lifetime (filterable via `perflocale/cookie_lifetime`). It can be turned off entirely (see "Cookieless mode" below) — language routing is URL-based and works without it.
* **Visitor IP:** never logged or stored. The optional GeoIP-redirect feature (disabled by default) ships with no lookup provider and no endpoint, so out of the box it sends the IP nowhere. If you wire a source yourself through the `perflocale/geo/lookup_country` or `perflocale/geo/providers` filter, the IP is passed to that source once per first visit to resolve a country code; the country code is then cached server-side (24 hours by default) under a salted, non-reversible key - an HMAC-SHA256, keyed with the site's auth salt, of the IP after `wp_privacy_anonymize_ip()` has zeroed the host bits - never the raw IP or any value reversible to it.
* **WordPress Privacy API integration:** Tools → Export Personal Data and Tools → Erase Personal Data both work. The eraser zeroes `created_by` on the background jobs the data subject dispatched and deletes their per-user UI-state meta — returning `items_removed`/`items_retained` counts. The same flow runs on the admin `delete_user` path. Full detail in the docs.
* **Consent gating:** the `perflocale/privacy/consent_given` filter lets any consent-management plugin (Cookiebot, Complianz, OneTrust, etc.) hold back PerfLocale until a visitor has consented. When the filter returns false, the `perflocale_lang` cookie is not set, and the GeoIP and browser-language redirects do not run (no outbound request is made).
* **Cookieless mode:** PerfLocale → Settings → URL & Routing → "Language Cookie" turns the `perflocale_lang` cookie off entirely — no consent-management plugin required. URL-based language routing keeps working; you only lose "remember my language" on non-prefixed URLs.
* **Suggested privacy-policy text:** auto-registered via `wp_add_privacy_policy_content()`. The sections shown adapt to which features are enabled — GeoIP wording only appears if GeoIP is on, MT wording only appears if MT is on.

Full technical detail: https://perflocale.com/docs/privacy/

== External Services ==

PerfLocale can optionally connect to external services for machine translation. All external service calls are **disabled by default** and require explicit user configuration (entering an API key and enabling the feature in settings). No data is sent to any external service without your action. The hosted providers below are contacted over HTTPS; for self-hosted or user-configured endpoints (LibreTranslate, agency, webhooks) use an HTTPS URL.

The GeoIP-redirect and WooCommerce exchange-rate features ship with **no** provider and contact **no** service on their own: they call nothing unless you wire a source yourself through the `perflocale/geo/lookup_country` / `perflocale/geo/providers` and `perflocale/woocommerce/exchange_rates_fetched` / `perflocale/woocommerce/exchange_rate_providers` filters. If you connect one, disclosing that service is your responsibility as the site owner.

API keys for the providers below can be supplied via an environment variable, a `wp-config.php` constant, or the admin Settings field (see the "Can I keep API keys out of the database?" FAQ).

= Machine Translation =

When you enable machine translation and configure an API key in PerfLocale → Settings → Addons → Machine Translation, the plugin sends the text you ask it to translate to the selected provider, together with source/target language codes and your API key. That text is: post titles, content and excerpts; taxonomy term names and descriptions; interface strings listed on the Strings screen (which can include strings registered by other plugins and themes) when you use its machine-translation controls; and, when meta translation is enabled, registered meta values such as SEO titles and descriptions or custom text fields. It is sent when you click "Machine Translate", run a bulk or site-wide translation, translate via WP-CLI or the REST API, or enable auto-translate on publish. Each provider below receives exactly that data, and only for the actions just listed. The provider API additionally defines a connection-test call that sends only your API key (no post content) so an add-on can verify credentials; no screen, WP-CLI command or REST route in PerfLocale itself invokes it.

* **DeepL** - api.deepl.com / api-free.deepl.com. Commercial neural-translation API (free and paid tiers). Receives the text, language codes and API key described above.
 [Terms of Service](https://www.deepl.com/en/pro-license) | [Privacy Policy](https://www.deepl.com/en/privacy)
* **Google Cloud Translation** - translation.googleapis.com. Google's paid cloud translation API. Receives the text, language codes and API key described above.
 [Terms of Service](https://cloud.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)
* **Microsoft Azure Translator** - api.cognitive.microsofttranslator.com. Microsoft's paid cloud translation API. Receives the text, language codes and API key described above.
 [Terms of Service](https://azure.microsoft.com/en-us/support/legal/) | [Privacy Policy](https://www.microsoft.com/en-us/privacy/privacystatement)
* **LibreTranslate** - self-hosted or user-configured URL. Open-source translation server you host yourself or point at an instance you trust; the plugin calls no hard-coded LibreTranslate endpoint. Receives the text and language codes described above at the URL you configure. Terms of service and privacy policy are governed by the LibreTranslate instance you configure; the AGPL-3.0 linked below is the governing license of the software itself.
 [Terms (AGPL-3.0 License)](https://github.com/LibreTranslate/LibreTranslate/blob/main/LICENSE) | [Source Code](https://github.com/LibreTranslate/LibreTranslate)
* **WordPress AI Client** - no hard-coded endpoint; delegated to WordPress core. When you select the "WP AI Client" provider on WordPress 7.0+ (or a host that ships the AI Client feature plugin), PerfLocale builds a short translation prompt (the text described above plus source/target language codes) and hands it to WordPress core's `wp_ai_client_prompt()` function. PerfLocale itself makes no outbound HTTP request for this provider: WordPress core (and whichever AI provider you configured under core's AI Connectors settings) performs the network call. The data sent, the destination, and the governing Terms of Service / Privacy Policy are therefore those of the AI provider you configured in WordPress core, only for the actions listed above.
 [WordPress AI Building Blocks](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks/)

= External Translation Agency =

When you configure an external agency URL in PerfLocale → Settings → Addons → Machine Translation, the plugin sends post content to the configured endpoint for human or agency translation:

* **Custom Agency Endpoint** - user-configured URL. A webhook endpoint you configure (use an HTTPS URL) to send post content to a human translator or translation agency for offline processing; the destination is entirely under your control and the plugin calls no hard-coded endpoint. The plugin sends post text, source/target language codes, and a unique request ID, only when you submit a translation request; the agency must return the translated text in the immediate response. Terms of service and privacy policy are governed by the agency whose endpoint you choose to configure; review their public policies before sending post content.

= Webhooks =

When you register webhooks via the PerfLocale REST API, the plugin sends event notifications to your configured webhook URLs when translations are created, updated, or content changes:

* **User-configured webhook URLs** - registered via REST API (loopback and private-network destinations are rejected). Endpoints you register via the PerfLocale REST API to receive translation-lifecycle notifications (use HTTPS URLs); the destinations are entirely under your control and the plugin calls no hard-coded URL. The plugin POSTs the event type, translation data (post IDs, language codes, status), and a timestamp whenever a translation is created, updated, or otherwise changes, and signs each payload with HMAC-SHA256 when a shared secret is configured. Terms of service and privacy policy are governed by whatever destination you register; review the operator's public policies before registering the URL.

PerfLocale can also publish a read-only public REST endpoint for edge runtimes (`/wp-json/perflocale/v1/config`). It is served by your own site, makes no outbound third-party request and sends no data anywhere - see the "Does PerfLocale expose anything to edge workers?" FAQ for the full description.

== Changelog ==

= 1.0.0 =

Initial public release.

**Content & routing** — Translate posts, pages, any custom post type, taxonomies, and URL slugs with per-language status tracking and configurable fallback chains. Routing in subdirectory (`/en/`), subdomain, per-language domain, or query-parameter (`?lang=en`) modes; language detection from URL, cookie, browser, or GeoIP; self-healing rewrite rules.

**Strings** — gettext translation for any plugin or theme, file-based (`.l10n.php`) or database mode, with a built-in scanner, MO/PO hint lookup, and full CLDR plural rules (up to six forms — e.g. Arabic 6, Russian/Polish 3), imported from multi-form PO files, editable per-form in the Strings screen, and preserved across a PO export / re-import.

**Language switcher** — Block, shortcode, nav-menu, admin-bar, widget, and template-tag switchers with full ARIA listbox accessibility, keyboard navigation + type-ahead, RTL-aware labels, configurable arrow/label format, live repositioning, an opt-in setting to auto-append the switcher to selected classic-menu locations, and 40+ filters / CSS custom properties for theming.

**SEO & UX** — hreflang (HTML `<link>` + HTTP header) with x-default and sitemap alternates. No hreflang-suppression filters are registered against Yoast / Rank Math / AIOSEO / SEOPress / The SEO Framework / Slim SEO, because none of them emit head- or header-stage hreflang of their own for PerfLocale's tags to collide with; instead the per-plugin addons add JSON-LD schema enrichment (`inLanguage` + `workTranslation`) and make each plugin's own sitemap language-aware. Plus Content-Language header, `data-nosnippet` fallback guard, and opt-in Speculation Rules prerender (registered through core's API on WP 6.8+, self-emitted on 6.4-6.7) and View Transitions.

**Machine translation** — DeepL, Google, Microsoft, LibreTranslate (and the WP 7.0 AI Client where available), with monthly usage caps and circuit breakers.

**E-commerce** — WooCommerce product / variation / attribute translation, multi-currency with scheduled exchange-rate sync from a rate provider your site registers via filter, inventory sync, and localized order emails.

**Platform & reliability** — Multisite-ready (per-subsite languages and translations; chunked network activation), 3-layer caching, a background-job system with self-healing rescheduling, token-guarded atomic locks, circuit breakers around every external dependency, and Site Health diagnostics. Built-in batched, transaction-safe migration from WPML, Polylang, and TranslatePress.

**Developers** — 200+ action/filter hooks, a full REST API, WP-CLI commands, a documented addon system, API keys resolvable from environment variable / `wp-config.php` constant / database, and `@api` semver-stable internal primitives.

Full release notes: https://perflocale.com/docs/

== Upgrade Notice ==

= 1.0.0 =
Initial release of PerfLocale.
