=== PerfLocale ===
Contributors: alexgeorgiev
Tags: multilingual, translation, i18n, language, localization
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.7
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance-first multilingual plugin. Translate posts, pages, products, taxonomies, strings, and slugs with 3-layer caching and 20+ integrations.

== Description ==

PerfLocale is a **performance-first multilingual plugin** for WordPress. A 3-layer cache, batch-preloaded queries, and conditional hook registration keep its own work per page small.

= What you get =

* **Content translation** - posts, pages, any custom post type, taxonomies, and URL slugs, with translation-status tracking. Synced patterns, block-theme navigation menus, and block-theme templates and template parts are translatable too (opt-in)
* **URL routing** - subdirectory (`/en/`), subdomain (`en.example.com`), per-domain, or query-parameter (`?lang=en`) modes; auto-detect from URL, cookie, browser, or an edge/CDN hint
* **String translation** - gettext strings from any plugin or theme, file-based (`.l10n.php`) or database-mode, with full CLDR plural rules (Arabic 6 forms, Russian 3) and context support. The site title and tagline appear there too
* **Language switcher** - block, shortcode, widget, menu, admin-bar, and template tags with full ARIA listbox accessibility
* **SEO** - hreflang (HTML + HTTP) and sitemap alternates; integrates with Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, and Slim SEO
* **Machine translation** - DeepL, Google, Microsoft, LibreTranslate, a custom agency endpoint, and the WordPress 7.0 AI Client, with monthly usage caps
* **Translator role** - a role for translation staff: it can create and edit translations and use machine translation. Like WordPress's editing roles, it can also edit any post or page and upload media, but it cannot publish, delete or change settings.
* **E-commerce** - WooCommerce product/variation/attribute translation, multi-currency (rates supplied by your own provider hook), inventory sync, localized order emails
* **Reliability** - circuit breakers around every external dependency, token-guarded atomic locks, self-healing background jobs, and Site Health diagnostics

= Modern SEO & UX =

Translation-aware features generic SEO plugins can't provide: Content-Language HTTP header, `data-nosnippet` guard for fallback pages, and opt-in Speculation Rules prerender and View Transitions for switching between languages. Auto-detects and integrates with 20+ plugins and themes, including WooCommerce, Elementor, ACF, Meta Box, Pods, all major SEO plugins, Gravity Forms, and the Blocksy, Kadence, and Neve themes.

= For developers =

200+ action/filter hooks, a full REST API, WP-CLI commands, and a documented addon system. A PHP helper API lets you translate a string or render a block of markup in any language from your own code. Classes and methods marked @api are semver-stable across 1.x. Multisite-ready. Full docs at **[perflocale.com/docs](https://perflocale.com/docs/)**.

**Try it without installing anything.** Open a throwaway WordPress site in your browser with PerfLocale already active: [Open PerfLocale in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://perflocale.com/blueprint.json)

= Where to go next =

* **Set-up guide** — a fresh install to a translated post with a working switcher: [perflocale.com/docs/getting-started](https://perflocale.com/docs/getting-started/)
* **Switching from another plugin** — importers for WPML, Polylang and TranslatePress: [perflocale.com/docs/migration](https://perflocale.com/docs/migration/)
* **How it compares** — PerfLocale against WPML, Polylang and TranslatePress: [perflocale.com/compare](https://perflocale.com/compare/)
* **WooCommerce** — products, variations, currencies, stock and order emails: [perflocale.com/docs/woocommerce](https://perflocale.com/docs/woocommerce/)
* **Multisite** — activation, per-site languages and background jobs across a network: [perflocale.com/docs/multisite](https://perflocale.com/docs/multisite/)
* **Something not working?** — symptom-first troubleshooting: [perflocale.com/docs/troubleshooting](https://perflocale.com/docs/troubleshooting/)
* **Hooks reference** — every action and filter: [perflocale.com/docs/hooks](https://perflocale.com/docs/hooks/)
* **REST API and WP-CLI** — [perflocale.com/docs/rest-api](https://perflocale.com/docs/rest-api/) and [perflocale.com/docs/wp-cli](https://perflocale.com/docs/wp-cli/)
* **Source code** — [github.com/perflocale/perflocale](https://github.com/perflocale/perflocale)

== Installation ==

1. Go to **Plugins → Add New Plugin**, search for **PerfLocale**, then click **Install Now** and **Activate**.
2. Go to **PerfLocale → Languages**, add your languages, and click **Set as Default** on your site's main language.
3. Go to **PerfLocale → Settings → URL & Routing** and choose your language URLs: `/de/` (the default), `de.example.com`, one domain per language, or `?lang=de`.
4. Translate: open a post, page or category, and in its **Translations** panel click **+ Create** beside a language. Then edit the new translation in the normal editor.
5. Add a language switcher: the **Language Switcher** block or widget, the `[perflocale_switcher]` shortcode, or a menu under **PerfLocale → Settings → Language Switcher**.
6. Optional: under **PerfLocale → Settings → SEO**, select your SEO plugin. Hreflang tags are on by default.
7. Optional: for machine translation, enable **Machine Translation** under **PerfLocale → Addons**, then choose a provider under **PerfLocale → Settings → Addons → Machine Translation** and add its API key (DeepL, Google or Microsoft). On WordPress 7.0 or newer you can choose the **WordPress AI Client** instead and connect an AI provider under **Settings → Connectors**.
8. Open **Tools → Site Health**. It tells you if your server needs anything.

**Good to know**

* Permalinks: any setting except "Plain" is recommended. Plain also works (URLs become `/de/?p=123`). If your server does not pass unknown paths to WordPress, use the query parameter URL mode (`?lang=de`).
* nginx and Caddy ignore `.htaccess`, so add one rule that blocks direct access to exports: see [Production tuning](https://perflocale.com/docs/production-tuning/#export-directory). Site Health shows whether you need it.
* API keys can also come from environment variables or `wp-config.php` constants, such as `PERFLOCALE_DEEPL_API_KEY`. See [API keys](https://perflocale.com/docs/api-key-constants/).

== Frequently Asked Questions ==

= Does PerfLocale slow down my site? =

Very little — performance is a core design goal. The plugin keeps the work it does on each page small, with three layers of caching to keep its own database work low. Larger sites — many translated posts across many languages — benefit from running a persistent object cache like Redis. PerfLocale is fully compatible with page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.); cached pages already include the translated output from the request that filled the cache. Real-world page speed depends mostly on your theme, hosting, and other plugins.

= Does it work with WooCommerce? =

Yes. PerfLocale includes a deep WooCommerce integration: translate products, variations, categories, and attributes. Inventory (stock, SKU, GTIN, price, weight, dimensions) stays in sync across language variants whenever WooCommerce saves a product, whether the change comes from an order, the product editor, Quick Edit, the REST API, an import, or another plugin. Multi-currency support, with exchange rates supplied by a provider your site registers via filter. Order emails are sent in the customer's language. The mini-cart, cart, and checkout all display correctly in every language.

= Does it work with page builders? =

Yes. PerfLocale integrates with Elementor, Beaver Builder, Bricks Builder, Oxygen Classic, and Oxygen 6.0. Each builder's content is registered as translatable meta, and dedicated Language Switcher widgets/elements are provided for Elementor, Beaver Builder, and Bricks — in Oxygen (Classic or 6.0) use the `[perflocale_switcher]` shortcode in a Code Block or Shortcode element.

= Does it translate block-theme templates and template parts? =

Yes. It is off by default. Switch on **Full Site Editing** under PerfLocale &rarr; Settings &rarr; Translation and the block templates and template parts you edit in the Site Editor - header, footer, 404, archive layouts - become translatable like any other content, along with the patterns, navigation labels and post content embedded in them.

A translation only takes effect once you publish it. WordPress renders an empty region for a template part it cannot resolve, so a half-finished header would leave a blank strip across the site; until the translation is published, visitors keep seeing the original. The Site Editor's own Translations panel shows which languages exist and labels anything still in draft.

= How do I translate patterns and block-theme menus? =

It depends on which kind of pattern you used, and the two look identical in the editor.

A pattern you insert from the inserter is **copied into the page**, so its text is simply part of that page — translate the page under PerfLocale → Translations and the text comes with it. (This is also why the theme's pattern strings on the Strings screen don't apply to it: those are used when the theme renders the pattern file itself, while the copy in your page is plain HTML.)

A **synced** pattern is stored once and the page only holds a reference to it, so there is nothing in the page to translate. Enable "Patterns" under *Advanced content types* at PerfLocale → Settings → Translation, then translate each pattern like any other post; PerfLocale serves the right one per language, including patterns nested inside a Group.

Block-theme menus work the same way. Menu link addresses are already translated without any setting; enable "Navigation Menus" in the same place to translate the visible labels too. In both cases, if a translation is missing or still a draft the original renders — never a blank space.

= How do I translate the site title and tagline? =

Go to PerfLocale → Strings and translate them like any other string — they are listed there as "Site Title" and "Tagline". There is nothing to switch on, and no scan to run: they are registered automatically when the plugin is updated or activated.

Your original title and tagline are never overwritten. PerfLocale serves the translated text on the front end only, and refuses any attempt to write a translated value back over the original.

= Can I migrate from WPML, Polylang, or TranslatePress? =

Yes. PerfLocale includes built-in migration tools for all three plugins. PerfLocale refuses to run while WPML, Polylang or TranslatePress is active, so deactivate the old plugin first — its data stays in the database — then go to PerfLocale → Settings → Export & Import and use the Migration section. Migrations run in batches and leave the old plugin's data untouched. An import that stops part-way can be run again: translations already imported are reused, not duplicated. Back up your database before a large import.

= Does it support RTL languages? =

Yes. PerfLocale detects the text direction from the language configuration and sets the correct `dir="rtl"` attribute on the HTML element. The language switcher and admin UI work correctly with RTL languages like Arabic and Hebrew.

= Is it compatible with caching plugins? =

Yes. PerfLocale works with all major caching plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket). Each language version has its own URL, so page caches naturally separate content by language. Any response whose language was decided by something other than the URL — a GeoIP or browser-language redirect, a returning visitor's language cookie — is automatically marked uncacheable (via WordPress' `nocache_headers()`), so one visitor's language can never be cached and served to everyone. On an edge or server cache (Varnish, nginx fastcgi_cache) make sure Cache-Control is honoured or those responses are excluded. Note that enabling GeoIP / browser redirection makes default-language entry URLs uncacheable by design — a page cache serving them from cache would skip the redirect entirely; if you need both, use the bundled edge worker (assets/js/edge-helper.js) to route at the CDN instead. In cookieless mode those redirects do not run, so the default-language entry URLs stay cacheable.

= Can I use it on multisite? =

Yes. PerfLocale is multisite-compatible. Each site in the network has its own languages and translations. Static caches are properly scoped and reset on blog switches.

= Can I keep API keys out of the database? =

Yes. PerfLocale resolves every machine-translation API key from three sources in priority order: environment variable → `wp-config.php` constant → database setting (whichever has a non-empty value first wins). Set `PERFLOCALE_DEEPL_API_KEY`, `PERFLOCALE_GOOGLE_API_KEY`, `PERFLOCALE_MICROSOFT_API_KEY`, `PERFLOCALE_LIBRE_API_KEY`, `PERFLOCALE_LIBRE_URL`, `PERFLOCALE_AGENCY_URL` or `PERFLOCALE_AGENCY_API_KEY` in your container env or `wp-config.php` and the admin-side fields become read-only, showing which environment variable or `wp-config.php` constant supplies the value. Database backups, exports, and SQL dumps then never contain the secret.

= What happens to my translations if I uninstall the plugin? =

By default nothing is lost: uninstalling removes the plugin's roles, capabilities, and scheduled tasks, but keeps all translations, languages, and settings in the database so a later re-install picks up exactly where you left off. If you want a complete removal instead, enable "Delete all plugin data when uninstalling" in PerfLocale → Settings → Advanced before uninstalling — then every plugin table and option is deleted. Your posts and pages (including translated ones) are always preserved as normal WordPress content.

On a multisite network each site's own choice is applied to that site, and your users' PerfLocale screen preferences, which every site shares, are removed only if no site chose to keep its data. Deleting the plugin from a very large network can run out of PHP execution time before every site is done: PerfLocale stops cleanly between sites, logs how many were purged and how many remain, and records where it stopped. Re-install the plugin and delete it again without activating it and the next pass carries on from there; from WP-CLI, `wp plugin uninstall perflocale` has no time limit and finishes the network in one pass. If a site fails, the log names it and the same steps retry it once the cause is fixed.

= Does PerfLocale expose anything to edge workers? =

When you enable Edge Worker Integration (PerfLocale → Settings → Advanced), the plugin publishes a single public REST endpoint that edge runtimes (Cloudflare Workers, Vercel Edge, Netlify Edge, AWS Lambda@Edge) can read to pre-route visitors before the request ever hits PHP:

* `GET /wp-json/perflocale/v1/config` - returns the minimum routing + language metadata an edge worker needs: active language slugs and locales, URL mode (subdirectory / subdomain / domain / query), URL prefix type, default language, hide-default-prefix flag, excluded paths, detection order, the edge-hint header name (`X-PerfLocale-Lang`) and cookie name. Response includes `Cache-Control: public, max-age=300, s-maxage=3600, stale-while-revalidate=86400` plus an `ETag` so edges and browsers can revalidate cheaply with `If-None-Match` (304 on hit). When code hooks the permission filter described below, or the caller is logged in, the response is sent with `Cache-Control: private, no-store` instead.

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

* **Cookie:** one cookie, `perflocale_lang`, stores only the active language slug. `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, 365-day default lifetime (filterable via `perflocale/cookie_lifetime`). It can be turned off (see "Cookieless mode" below) — language routing is URL-based and works without it.
* **Visitor IP:** never logged or stored. The optional GeoIP-redirect feature (disabled by default) ships with no lookup provider and no endpoint, so out of the box it sends the IP nowhere. If you wire a source yourself through the `perflocale/geo/lookup_country` or `perflocale/geo/providers` filter, the IP is passed to that source once per first visit to resolve a country code; the country code is then cached server-side (24 hours by default) under a salted, non-reversible key - an HMAC-SHA256, keyed with the site's auth salt, of the IP after `wp_privacy_anonymize_ip()` has zeroed the host bits - never the raw IP or any value reversible to it.
* **WordPress Privacy API integration:** Tools → Export Personal Data and Tools → Erase Personal Data both work. The eraser zeroes `created_by` on the background jobs the data subject dispatched and deletes their per-user UI-state meta — returning `items_removed`/`items_retained` counts. The same flow runs on the admin `delete_user` path. Full detail in the docs.
* **Consent gating:** the `perflocale/privacy/consent_given` filter lets any consent-management plugin (Cookiebot, Complianz, OneTrust, etc.) hold back PerfLocale until a visitor has consented. When the filter returns false, the `perflocale_lang` cookie is not set, and the GeoIP and browser-language redirects do not run (no outbound request is made).
* **Cookieless mode:** PerfLocale → Settings → URL & Routing → "Language Cookie" turns the `perflocale_lang` cookie off — no consent-management plugin required. Code on your site can still decide per request through the `perflocale/language_cookie/enabled` filter. URL-based language routing keeps working; you lose "remember my language" on non-prefixed URLs. On a WooCommerce store you also lose the cart and checkout language: the block cart/checkout posts to the non-prefixed Store API URLs, and the language stamped on a new order is read from that same cookie, so both fall back to the site's default language. The automatic first-visit redirects (browser language, GeoIP, edge hint) do not run in cookieless mode unless code hooks that filter: without the cookie they would redirect the same visitor again on every default-language page.
* **Suggested privacy-policy text:** auto-registered via `wp_add_privacy_policy_content()`. The sections shown adapt to which features are enabled — GeoIP wording only appears if GeoIP is on, MT wording only appears if MT is on.

Full technical detail: [perflocale.com/docs/privacy](https://perflocale.com/docs/privacy/)

== External Services ==

PerfLocale can optionally connect to external services for machine translation. All external service calls are **disabled by default** and require explicit user configuration (entering an API key and enabling the feature in settings). No data is sent to any external service without your action. The hosted providers below are contacted over HTTPS; for self-hosted or user-configured endpoints (LibreTranslate, agency, webhooks) use an HTTPS URL.

The GeoIP-redirect and WooCommerce exchange-rate features ship with **no** provider and contact **no** service on their own: they call nothing unless you wire a source yourself through the `perflocale/geo/lookup_country` / `perflocale/geo/providers` and `perflocale/woocommerce/exchange_rates_fetched` / `perflocale/woocommerce/exchange_rate_providers` filters. If you connect one, disclosing that service is your responsibility as the site owner.

API keys for the providers below can be supplied via an environment variable, a `wp-config.php` constant, or the admin Settings field (see the "Can I keep API keys out of the database?" FAQ).

= Machine Translation =

When you enable machine translation and configure an API key in PerfLocale → Settings → Addons → Machine Translation, the plugin sends the text you ask it to translate to the selected provider, together with source/target language codes and your API key. That text is: post titles, content and excerpts; taxonomy term names and descriptions; interface strings listed on the Strings screen (which can include strings registered by other plugins and themes) when you use its machine-translation controls; and, when meta translation is enabled, registered meta values such as SEO titles and descriptions or custom text fields. It is sent when you click "Machine Translate" or use the block editor's translate actions, run a bulk or site-wide translation, translate via WP-CLI or the REST API, or enable auto-translate on publish or on create. Each provider below receives exactly that data, and only for the actions just listed. The provider API additionally defines a connection-test call that sends your API key and, for some providers, a fixed test word — never your content — so an add-on can verify credentials; no screen, WP-CLI command or REST route in PerfLocale itself invokes it.

* **DeepL** - api.deepl.com / api-free.deepl.com. Commercial neural-translation API (free and paid tiers). Receives the text, language codes and API key described above.
 [Terms of Service](https://www.deepl.com/en/pro-license) | [Privacy Policy](https://www.deepl.com/en/privacy)
* **Google Cloud Translation** - translation.googleapis.com. Google's paid cloud translation API. Receives the text, language codes and API key described above.
 [Terms of Service](https://cloud.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)
* **Microsoft Azure Translator** - api.cognitive.microsofttranslator.com. Microsoft's paid cloud translation API. Receives the text, language codes and API key described above.
 [Terms of Service](https://azure.microsoft.com/en-us/support/legal/) | [Privacy Policy](https://www.microsoft.com/en-us/privacy/privacystatement)
* **LibreTranslate** - self-hosted or user-configured URL. Open-source translation server you host yourself or point at an instance you trust; the plugin calls no hard-coded LibreTranslate endpoint. Receives the text and language codes described above at the URL you configure. Terms of service and privacy policy are governed by the LibreTranslate instance you configure; the AGPL-3.0 linked below is the governing license of the software itself.
 [Terms (AGPL-3.0 License)](https://github.com/LibreTranslate/LibreTranslate/blob/main/LICENSE) | [Source Code](https://github.com/LibreTranslate/LibreTranslate)
* **WordPress AI Client** - no hard-coded endpoint; delegated to WordPress core. When you select the "WordPress AI Client" provider on WordPress 7.0+ (or a host that ships the AI Client feature plugin), PerfLocale builds a short translation prompt (the text described above plus source/target language codes) and hands it to WordPress core's `wp_ai_client_prompt()` function. PerfLocale itself makes no outbound HTTP request for this provider: WordPress core (and whichever AI provider you connected under Settings → Connectors) performs the network call. The data sent, the destination, and the governing Terms of Service / Privacy Policy are therefore those of the AI provider you configured in WordPress core, only for the actions listed above.
 [WordPress AI Building Blocks](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks/)

= External Translation Agency =

When you configure an external agency URL in PerfLocale → Settings → Addons → Machine Translation, the plugin sends post content to the configured endpoint for human or agency translation:

* **Custom Agency Endpoint** - user-configured URL. A webhook endpoint you configure (use an HTTPS URL) to send post content to a human translator or translation agency for offline processing; the destination is entirely under your control and the plugin calls no hard-coded endpoint. The plugin sends post text, source/target language codes, and a unique request ID, only when you submit a translation request; the agency must return the translated text in the immediate response. Terms of service and privacy policy are governed by the agency whose endpoint you choose to configure; review their public policies before sending post content.

= Webhooks =

When you register webhooks via the PerfLocale REST API, the plugin sends event notifications to your configured webhook URLs when translations are created, updated, or content changes:

(loopback, private-network and credential-bearing destinations, and ports outside WordPress's own allowlist of 80, 443 and 8080, are rejected at validation time, and the refusal names the rule that was broken; on multisite, registering one requires network-administrator permissions) Endpoints you register via the PerfLocale REST API to receive translation-lifecycle notifications (use HTTPS URLs); the destinations are entirely under your control and the plugin calls no hard-coded URL. The plugin POSTs the event type, translation data (post IDs, language codes, status), and a timestamp whenever a translation is created, updated, or otherwise changes, and signs each payload with HMAC-SHA256 when a shared secret is configured. Terms of service and privacy policy are governed by whatever destination you register; review the operator's public policies before registering the URL.

PerfLocale can also publish a read-only public REST endpoint for edge runtimes (`/wp-json/perflocale/v1/config`). It is served by your own site, makes no outbound third-party request and sends no data anywhere - see the "Does PerfLocale expose anything to edge workers?" FAQ for the full description.

== Changelog ==

Each release below is a short summary. The complete notes for every
version, with the reasoning behind each change, live at
[perflocale.com/changelog](https://perflocale.com/changelog/)

= 1.0.7 =

Security hardening.

Languages: a language that still has posts, pages or products outside the Trash can no longer be deleted; the Languages screen, the REST API (409 language_has_content) and WP-CLI say what it still has. Move that content to the Trash first, or deactivate the language instead. Terms, media and block theme template copies do not block a delete.

Export and import: a Merge import no longer changes this site's settings, add-on settings, add-on list or roles; it reports them as not applied. Replace applies them, and a file with only the Settings and Roles sections deletes no rows.

Sync Fields: copying a field to the other language versions changes only that field; their content, title, excerpt, publish date and status stay exactly as they are, whoever saves.

Custom fields: ACF, Meta Box and SEO values stay correct however a post is saved (editor, REST API, WP-CLI, WooCommerce saves from code), including nested groups, repeaters and flexible content. A field a translator empties stays empty. Field changes made during a front-end page view are copied to translations on the next save, and on multisite each site follows its own Sync Fields setting.

WooCommerce: language copies of a product share one stock at checkout, in the cart and when adding to the cart, including stock held by pending orders. Saving a product no longer overwrites the stock of a copy that manages its own stock, so a sale made during the save is never lost. New product translations get the source's catalog visibility, featured flag, shipping class and brand, and on WooCommerce 10.8 or newer their product lookup row, so they sort by price and are found by SKU; on older versions, and for translations created before this update, WooCommerce → Status → Tools → Product lookup tables → Regenerate builds the missing rows. Shop managers can create product translations. The block Cart and Checkout show product names, short descriptions and images in the page language from the first view, order line names no longer start with "Protected:", and a sold-individually product cannot be bought twice as two language copies. Every order is tagged with its language whenever the WooCommerce add-on is active, and an order without a language that is paid on a translated order-pay page takes that page's language, so its emails use it. Each order email keeps its own language and exchange rate, and an order's personal-data export includes its language.

Machine translation: the WordPress AI Client prefers small models (filterable), and its error message, Site Health and the editor's setup link point to Settings → Connectors when no provider is ready. A password-protected post is sent to a machine translation service only when someone picks that post (in the editor, with a bulk action on selected rows, or by its ID); automatic runs and wp perflocale translate --all skip it. The new perflocale/mt/send_password_protected filter can change this for any run. In the block editor, "Fill in from source" fills each block's own text, and blocks marked "Do not translate" are kept; a block inside one shows why it cannot be translated. Bulk and site-wide translation say how many new translations kept the source content because of "Do not translate", on the Translations screen and the Jobs page. Bulk string translation selects only strings still missing a translation, fills every plural form, and says how many remain. Product translation also covers variation descriptions and local attribute options. A machine translation server on a private network (for example a self-hosted LibreTranslate) must be listed with the perflocale/mt/trusted_hosts filter.

Background jobs: bulk translation jobs run in slices of up to 20 seconds or 500 pairs and continue where they stopped, so hosts that stop long requests no longer kill them. A job that stops on a PHP fatal error is marked failed at once, and a killed worker no longer holds up the Action Scheduler queue (WooCommerce included) once its lock expires.

Importers: WPML, Polylang and TranslatePress imports run one at a time, refuse a first import when the default language differs or a language is missing (a later catch-up import shows a warning instead), and bring menus, strings and order languages. The TranslatePress import brings full post data and WooCommerce order languages, replaces only the whole texts, attributes, block settings and translation blocks that TranslatePress translated, and reports translations it skipped. The Jobs page shows each import's current stage and how many of its items are done, and a background import whose worker stopped can be started again after 15 minutes. The WPML importer reports conflicting translation sets instead of relinking them, and large imports and interrupted background jobs are handled reliably; an import that loses its lock stops with a message instead of continuing. Bulk tools ask before running on data that has not been imported yet.

URLs and redirects: with the language cookie turned off, the browser-language, GeoIP and edge-hint redirects no longer run, so the default language stays reachable; otherwise they redirect only to a page that shows content in that language. Domain mode serves a default language without a domain of its own on the site's own host. Fallback chains work in subdomain and domain mode.

Translations screen: the status filters, the Dashboard and wp perflocale status take each translation's status from its own post, so a translation in the Trash counts as missing.

Block themes: customising a template part again after "Reset to theme default" brings its translations back, and a Navigation block without a chosen menu shows the default-language menu or its translation.

Strings: in files mode, translation files are rebuilt when languages change, and Site Health names each language without files.

Roles: changes a site owner makes to the Translator role are kept across updates and reactivation.

Multisite: network activation is refused, listing the IDs of the sites that failed, when any site cannot be set up.

New filters: perflocale/language_cookie/enabled decides per request whether the language cookie is written (consent still comes first), and perflocale/addon/enabled turns an add-on off or on per request; perflocale()->is_addon_active() tells whether it runs. Also perflocale/mt/send_password_protected, perflocale/jobs/bulk_translate/slice_seconds and perflocale/jobs/bulk_translate/slice_max_pairs, perflocale/roles/translator_caps, perflocale/roles/shop_manager_caps and perflocale/sync/seed_meta_value.

Changing an existing post's language (on its edit screen, in Quick Edit or with WP-CLI) swaps its categories, tags and other translatable terms for their translations in the new language; a term without one is kept.

Also fixes hreflang tags on page 2 and later of a listing pointing to pages that do not exist in another language, force-deleting a translation leaving its siblings' hreflang tags pointing to it, page 2 and later and the feeds of translated term archives redirecting to page 1, redirects to another language's domain in domain and subdomain URL mode, posts without a translation link (shown on their own URL, with hreflang tags), unknown two-segment URLs loading the front page, a settings tab saved without changes resetting settings it does not show, the per-language domain table showing in subdomain mode, wp perflocale languages delete reporting success for a delete that was rolled back, excluded paths matching part of a path segment or missing non-Latin paths, custom fields such as book_author, whose names hold a credential word inside a longer word, not being copied into a new translation, uninstall with "Delete all plugin data" leaving WooCommerce order data (including High-Performance Order Storage) and scheduled actions behind, a server error from an unusual post_type query parameter, deleting a language with an empty code removing the language tag from every menu, links showing untranslated slugs after a failed database query, an expired cached translation list being rebuilt on every request on sites without a persistent object cache, and a converted price that rounded to zero in a store currency without decimals. Admin notices appear above the PerfLocale tab strip, and the perflocale() helper can be called from a perflocale/loaded listener.

Full notes: [perflocale.com/changelog](https://perflocale.com/changelog/)

= 1.0.6 =

Security hardening and stricter permission checks.

Importing a JSON file from another site, or a backup of this site that records a language-specific address, can now ask you to confirm that this site is a copy of it: tick the checkbox under Settings → Export & Import, or pass `--force` in WP-CLI.

On WooCommerce stores, a visitor's first page in the site's default language no longer sets the language cookie, so full-page caches can store those pages. Cart and checkout keep working in every language.

Also fixes previews of draft translations that share their slug with a published page, synced patterns and navigation menus rendering blank when their translation was published empty, the WPForms builder's "+ Create" failing even for administrators, an error or a duplicate when two requests created the same translation at once, page titles (WooCommerce's Create Page Translations) and term names (Create Taxonomy Translations) saved in the admin's language when the target language had no WordPress language pack, Strings-screen hints shown in the wrong language, and the Jobs screen showing "Not scheduled" for a task that was running. Front-end pages, feeds, sitemaps and REST requests also run fewer database queries - 2 to 8 fewer on each one we measured on a WooCommerce test site with Redis - with the same output.

On multisite, deleting the plugin now works through a large network in batches and records where it stopped if a request runs out of time: re-install the plugin and delete it again, without activating it, and the next pass carries on from there. Each site's own "Delete data on uninstall" choice survives the interruption. Network activation no longer stops at the first site that fails, and rendering content from another site no longer leaves the original site without its detected language.

Full notes: [perflocale.com/changelog](https://perflocale.com/changelog/)

= 1.0.5 =

Translates block-theme templates and template parts, so the header, footer and page layouts you customise in the Site Editor can be translated like any other content, and shows a Translations panel in the Site Editor and in the Contact Form 7 and WPForms form editors — screens the existing panels could not reach. Fixes a class of defect where content that a plugin looks up by identity rather than by language disappeared on every non-default language: Contact Form 7 embeds rendered "Error: Contact form not found", Oxygen pages rendered with no layout at all, block-theme navigation fell back to an auto-generated menu, and on WooCommerce block themes the entire product body vanished. Also fixes Contact Form 7 forms missing from the Translations screen, translated grouped products losing their product type and their child products, the shop page showing every language's products when it is the front page, cart and checkout links pointing at unpublished pages, a "Needs update" badge that could never be cleared, an Oxygen template that could render in the wrong language once its translation group was reduced to a single translation, a dashboard that counted trashed translations as translated, a multisite case where one subsite's active add-ons could switch off language filtering on another, and WPForms forms that could not be translated by anyone at all — every translation request was refused, administrators included. Elementor translations also no longer inherit the original page's generated CSS and cached HTML, which could make a newly created translation render unstyled or show the original language.

Saving a block template now creates it in every language, each seeded with the original's blocks and created as a draft, so nothing changes for visitors until you publish one. Add a language later and the next save picks it up.

Page-builder layouts are no longer overwritten between languages. Elementor, Beaver Builder, Bricks, Oxygen and Oxygen 6 store a page's text inside their layout data, and PerfLocale had been keeping that data identical across a translation group in both directions - so saving a translated page could replace the original with the translated language, and saving the original could replace a finished translation. A new translation is still created as a full copy of the original layout; from then on each language owns its own. The trade-off is that later structural edits no longer propagate automatically to existing translations.

Numbers and currency now follow the requested language's own conventions rather than the locale the page happens to be running in, so a German page shows 1.234,56 where an English one shows 1,234.56. Media is no longer offered as a translatable content type: PerfLocale never translated media by duplicating attachments - it stores per-language alt text, captions and descriptions on the same attachment - so the checkbox enabled nothing while language-scoping every front-end attachment query as a side effect. Media translation itself is unchanged.

On WordPress 6.9 and newer, four read-only AI-agent abilities (list languages, get a post's translations, detect a post's language, convert a URL between languages) now register by default; the two write abilities stay off. Both switches live under PerfLocale → Settings → Advanced → AI Agent Abilities.

Full notes: [perflocale.com/changelog](https://perflocale.com/changelog/)

== Upgrade Notice ==

= 1.0.7 =
Security hardening. A language that still has posts can no longer be deleted (deactivate it instead), and a Merge import no longer changes this site's settings or roles. Custom fields, WooCommerce stock, bulk translation jobs and imports are more reliable.

= 1.0.6 =
Security hardening and stricter permission checks. Importing a JSON file from another site can now ask you to confirm this site is a copy of it. On WooCommerce stores, default-language pages no longer set the language cookie. Multisite uninstall and activation are more reliable.

= 1.0.5 =
Translates block-theme templates and template parts, and adds a Translations panel to the Site Editor and the Contact Form 7 and WPForms editors. Fixes forms, Oxygen layouts, menus and WooCommerce product bodies vanishing on non-default languages, and WPForms forms nobody could translate.
