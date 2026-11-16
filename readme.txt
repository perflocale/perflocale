=== PerfLocale ===
Contributors: alexgeorgiev
Tags: multilingual, translation, i18n, language, localization
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.8
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate posts, pages, products, categories, strings and URL slugs. Language URLs, hreflang, a language switcher and optional machine translation.

== Description ==

PerfLocale is a multilingual plugin for WordPress. It tries to add as little work to each page as it can: it loads only the code a request needs, reads the links and slugs of a page's posts together instead of one by one, and keeps what it looked up in memory for the rest of the request. Between requests it keeps them in your persistent object cache (such as Redis) or, without one, in transients.

= Features =

* **Content translation** - posts, pages, any custom post type, taxonomies, and URL slugs, with translation-status tracking. Synced patterns, block-theme navigation menus, and block-theme templates and template parts are translatable too (opt-in)
* **URL routing** - subdirectory (`/en/`), subdomain (`en.example.com`), per-language domains, or query-parameter (`?lang=en`) modes; auto-detect from URL, cookie, browser, or an edge/CDN hint
* **String translation** - gettext strings from any plugin or theme, file-based (`.l10n.php`) or database-mode, with CLDR plural rules (6 forms for Arabic, 3 for Russian) and context support. The site title and tagline appear there too
* **Language switcher** - block, shortcode, widget, menu, admin-bar, and template tags; the dropdown style can be used with the keyboard
* **SEO** - hreflang (HTML + HTTP) and sitemap alternates; integrates with Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, and Slim SEO
* **Machine translation** - DeepL, Google, Microsoft, LibreTranslate, a custom agency endpoint, and the WordPress 7.0 AI Client, with a monthly character limit
* **Translator role** - a role for translation staff: it can create and edit translations and use machine translation. Like WordPress's editing roles, it can also edit any post or page and upload media, but it cannot publish, delete or change settings.
* **WooCommerce** - translate products, variations and attributes; show prices in several currencies, with the European Central Bank's daily rates or rates from your own provider; keep stock, price and other product data the same across language copies; send order emails in the customer's language
* **Reliability** - calls to translation providers, webhooks, exchange-rate and country sources pause for a while after repeated failures; locks stop two requests from running the same work at once; background jobs continue where they stopped; Site Health shows what needs attention

= More for visitors and search engines =

A Content-Language HTTP header, a `data-nosnippet` wrapper when a page shows content in another language as a fallback, and optional Speculation Rules prerendering and View Transitions when a visitor switches languages.

= Integrations =

Each integration loads when the plugin or theme it is for is active: WooCommerce, Elementor, Beaver Builder, Bricks, Oxygen (Classic and 6), ACF, Meta Box, Pods, Gravity Forms, WPForms, Contact Form 7, Yoast SEO, Rank Math, AIOSEO, SEOPress, The SEO Framework, Slim SEO, and the Blocksy, Kadence and Neve themes.

= For developers =

More than 200 action and filter hooks, a REST API, WP-CLI commands and a documented addon system. A PHP helper API lets you translate a string or run a piece of your own code in a chosen language. Classes and methods marked @api do not change in a breaking way within 1.x. Works on multisite. Full docs at **[perflocale.com/docs](https://perflocale.com/docs/)**.

**Try it without installing anything.** Open a throwaway WordPress site in your browser with PerfLocale already active: [Open PerfLocale in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://perflocale.com/blueprint.json)

= Where to go next =

* **Set-up guide** - a fresh install to a translated post with a working switcher: [perflocale.com/docs/getting-started](https://perflocale.com/docs/getting-started/)
* **Switching from another plugin** - importers for WPML, Polylang and TranslatePress: [perflocale.com/docs/migration](https://perflocale.com/docs/migration/)
* **How it compares** - PerfLocale against WPML, Polylang and TranslatePress: [perflocale.com/compare](https://perflocale.com/compare/)
* **WooCommerce** - products, variations, currencies, stock and order emails: [perflocale.com/docs/woocommerce](https://perflocale.com/docs/woocommerce/)
* **Multisite** - activation, per-site languages and background jobs across a network: [perflocale.com/docs/multisite](https://perflocale.com/docs/multisite/)
* **Something not working?** - symptom-first troubleshooting: [perflocale.com/docs/troubleshooting](https://perflocale.com/docs/troubleshooting/)
* **Hooks reference** - every action and filter: [perflocale.com/docs/hooks](https://perflocale.com/docs/hooks/)
* **REST API and WP-CLI** - [perflocale.com/docs/rest-api](https://perflocale.com/docs/rest-api/) and [perflocale.com/docs/wp-cli](https://perflocale.com/docs/wp-cli/)
* **Source code** - [github.com/perflocale/perflocale](https://github.com/perflocale/perflocale)

== Installation ==

1. Go to **Plugins → Add New Plugin**, search for **PerfLocale**, then click **Install Now** and **Activate**.
2. Go to **PerfLocale → Languages**, add your languages, and click **Set as Default** on your site's main language.
3. Go to **PerfLocale → Settings → URL & Routing** and choose your language URLs: `/de/` (the default), `de.example.com`, per-language domains, or `?lang=de`.
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

It adds some work to each page, as every multilingual plugin does, and it is written to keep that work small. It loads only the code a request needs, reads the data of a page's posts together, and keeps what it looked up in memory for the request. Between requests it uses your persistent object cache (such as Redis) or, without one, transients. Larger sites - many translated posts across many languages - benefit from a persistent object cache. PerfLocale works with page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.); cached pages already include the translated output from the request that filled the cache. Real-world page speed depends mostly on your theme, hosting, and other plugins.

= Does it work with WooCommerce? =

Yes. You can translate products, variations, categories and attributes. Inventory (stock, SKU, GTIN, price, weight, dimensions) stays in sync across language variants whenever WooCommerce saves a product, whether the change comes from an order, the product editor, Quick Edit, the REST API, an import, or another plugin. Prices can be shown in several currencies, with exchange rates from the European Central Bank's daily reference rates or from a provider your site registers through a filter. Order emails are sent in the customer's language, and the mini-cart, cart and checkout use the visitor's language.

= Does it work with page builders? =

Yes. PerfLocale integrates with Elementor, Beaver Builder, Bricks Builder, Oxygen Classic, and Oxygen 6.0. Each builder's content is registered as translatable meta, and dedicated Language Switcher widgets/elements are provided for Elementor, Beaver Builder, and Bricks - in Oxygen (Classic or 6.0) use the `[perflocale_switcher]` shortcode in a Code Block or Shortcode element.

= Does it translate block-theme templates and template parts? =

Yes. It is off by default. Tick **Templates and template parts** under *Site parts* at PerfLocale &rarr; Settings &rarr; Translation and the block templates and template parts you edit in the Site Editor - header, footer, 404, archive layouts - become translatable like any other content, along with the patterns, navigation labels and post content embedded in them.

A translation only takes effect once you publish it. WordPress renders an empty region for a template part it cannot resolve, so a half-finished header would leave a blank strip across the site; until the translation is published, visitors keep seeing the original. The Site Editor's own Translations panel shows which languages exist and labels anything still in draft.

= How do I translate patterns and block-theme menus? =

It depends on which kind of pattern you used, and the two look identical in the editor.

A pattern you insert from the inserter is **copied into the page**, so its text is simply part of that page - translate the page under PerfLocale → Translations and the text comes with it. (This is also why the theme's pattern strings on the Strings screen don't apply to it: those are used when the theme renders the pattern file itself, while the copy in your page is plain HTML.)

A **synced** pattern is stored once and the page only holds a reference to it, so there is nothing in the page to translate. Tick "Synced patterns" under *Site parts* at PerfLocale → Settings → Translation, then translate each pattern like any other post; PerfLocale serves the right one per language, including patterns nested inside a Group.

Block-theme menus work the same way. Menu link addresses are already translated without any setting; enable "Navigation menus" in the same place to translate the visible labels too. In both cases, if a translation is missing or still a draft the original renders - never a blank space.

= How do I translate the site title and tagline? =

Go to PerfLocale → Strings and translate them like any other string - they are listed there as "Site Title" and "Tagline". There is nothing to switch on, and no scan to run: they are registered automatically when the plugin is updated or activated.

Your original title and tagline are never overwritten. PerfLocale serves the translated text on the front end only, and refuses any attempt to write a translated value back over the original.

= Can I migrate from WPML, Polylang, or TranslatePress? =

Yes. PerfLocale includes built-in migration tools for all three plugins. PerfLocale refuses to run while WPML, Polylang or TranslatePress is active, so deactivate the old plugin first - its data stays in the database - then go to PerfLocale → Settings → Export & Import and use the Migration section. Migrations run in batches and leave the old plugin's data untouched. An import that stops part-way can be run again: translations already imported are reused, not duplicated. Back up your database before a large import.

= Does it support RTL languages? =

Yes. PerfLocale detects the text direction from the language configuration and sets the correct `dir="rtl"` attribute on the HTML element. PerfLocale's own stylesheets, for the language switcher and the admin screens, have right-to-left versions for languages such as Arabic and Hebrew.

= Is it compatible with caching plugins? =

Yes. Page-cache plugins such as WP Super Cache, W3 Total Cache, LiteSpeed Cache and WP Rocket work with PerfLocale, because each language version has its own URL and so its own cache entry. Any response whose language was decided by something other than the URL - a GeoIP or browser-language redirect, a returning visitor's language cookie - is sent with WordPress' no-cache headers (`nocache_headers()`), so a cache that respects them does not store one visitor's language and serve it to everyone. On an edge or server cache (Varnish, nginx fastcgi_cache) make sure Cache-Control is honoured or those responses are excluded. Note that enabling GeoIP / browser redirection makes default-language entry URLs uncacheable by design - a page cache serving them from cache would skip the redirect entirely; if you need both, use the bundled edge worker (assets/js/edge-helper.js) to route at the CDN instead. In cookieless mode those redirects do not run, so the default-language entry URLs stay cacheable.

= Can I use it on multisite? =

Yes. Each site in the network has its own languages and translations. PerfLocale's in-memory caches are kept per site and are reset when code switches from one site to another.

= Can I keep API keys out of the database? =

Yes. PerfLocale reads each machine-translation API key from these sources, in this order, and uses the first one that has a value: an environment variable, a `wp-config.php` constant, a key saved under Settings → Connectors (WordPress 7.0 and later; DeepL, Google, Microsoft and LibreTranslate), then the PerfLocale setting. Set `PERFLOCALE_DEEPL_API_KEY`, `PERFLOCALE_GOOGLE_API_KEY`, `PERFLOCALE_MICROSOFT_API_KEY`, `PERFLOCALE_LIBRE_API_KEY`, `PERFLOCALE_LIBRE_URL`, `PERFLOCALE_AGENCY_URL` or `PERFLOCALE_AGENCY_API_KEY` in your container env or `wp-config.php` and the admin-side fields become read-only, showing which environment variable or `wp-config.php` constant supplies the value. With an environment variable or a constant, database backups, exports and SQL dumps do not contain the key.

= What happens to my translations if I uninstall the plugin? =

By default nothing is lost: uninstalling removes the plugin's roles, capabilities, and scheduled tasks, but keeps all translations, languages, and settings in the database so a later re-install picks up exactly where you left off. If you want a complete removal instead, enable "Delete all plugin data when uninstalling" in PerfLocale → Settings → Advanced before uninstalling - then every plugin table and option is deleted. On an object cache that cannot clear groups, PerfLocale's cached copies expire on their own within a day, or within the GeoIP cache time you set. Your posts and pages (including translated ones) are always preserved as normal WordPress content.

On a multisite network each site's own choice is applied to that site, and your users' PerfLocale screen preferences, which every site shares, are removed only if no site chose to keep its data. Deleting the plugin from a very large network can run out of PHP execution time before every site is done: PerfLocale stops cleanly between sites, logs how many were purged and how many remain, and records where it stopped. Re-install the plugin and delete it again without activating it and the next pass carries on from there; from WP-CLI, `wp plugin uninstall perflocale` has no time limit and finishes the network in one pass. If a site fails, the log names it and the same steps retry it once the cause is fixed.

= Does PerfLocale expose anything to edge workers? =

When you enable Edge Worker Integration (PerfLocale → Settings → Advanced), the plugin publishes one public REST endpoint that edge runtimes (Cloudflare Workers, Vercel Edge, Netlify Edge, AWS Lambda@Edge) can read to route visitors before the request reaches WordPress:

* `GET /wp-json/perflocale/v1/config` - returns the routing and language data an edge worker needs: active language slugs and locales, URL mode (subdirectory / subdomain / domain / query), URL prefix type, default language, hide-default-prefix flag, excluded paths, detection and redirect order, home path, plugin version, and the edge-hint header name (`X-PerfLocale-Lang`) and cookie name. Response includes `Cache-Control: public, max-age=300, s-maxage=3600, stale-while-revalidate=86400` plus an `ETag` so edges and browsers can revalidate cheaply with `If-None-Match` (304 on hit). When code hooks the permission filter described below, or the caller is logged in, the response is sent with `Cache-Control: private, no-store` instead.

**The response does not contain** machine-translation API keys, provider tokens, user data, internal post or term IDs, or anything that visitors cannot already see on the site (hreflang tags, language switcher, URL prefixes). That is why the endpoint is public by default. If you add to the response with the `perflocale/api/config` filter, keep it that way.

To restrict access (private staging sites, IP allowlists, Application-Password authentication, mTLS at a reverse proxy), hook the `perflocale/edge_worker/config_permission_callback` filter and return `false` or a `WP_Error` for requests you do not allow. Example:

`add_filter( 'perflocale/edge_worker/config_permission_callback', fn () => current_user_can( 'manage_options' ) );`

PerfLocale has no token or header scheme of its own. Use WordPress' own methods (Application Passwords for server-to-server requests, cookie and nonce for browser sessions, or a JWT/OAuth plugin) and check them in this filter, so the endpoint uses the same authentication as the rest of your site's REST API.

== Screenshots ==

1. Front-end language switcher - block, shortcode, or template tag, plus an optional append to classic theme menus
2. WooCommerce cart in German - translated product titles, attributes, and currency
3. PerfLocale dashboard - per-language translation progress for every post type, with draft and outdated counts
4. Settings - Export & Import: bring existing translations across from WPML, Polylang, or TranslatePress as a background job that is safe to re-run
5. Languages screen - add languages, set the default, and mark text direction
6. Settings - Performance: string-translation mode (files or database), object cache, and slug preloading
7. Strings screen - translate gettext strings from any plugin or theme, with PO export and import
8. Settings - URL & Routing: missing-translation action and per-language fallback chains
9. Jobs - bulk and whole-site translation runs in the background: chunked, resumable, with automatic retries and per-blog status

== Privacy ==

PerfLocale has no tracking, no analytics and no visitor fingerprinting.

* **Cookie:** one cookie, `perflocale_lang`, stores only the active language slug. `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, 365-day default lifetime (filterable via `perflocale/cookie_lifetime`). It can be turned off (see "Cookieless mode" below) - language routing is URL-based and works without it.
* **Visitor IP:** never logged or stored. The optional GeoIP-redirect feature (disabled by default) calls no lookup service and sends the IP nowhere: its built-in sources read the country code that your CDN (Cloudflare, Amazon CloudFront) or your web server's GeoIP module already adds to the request, and that country code is not cached. If you wire a source yourself through the `perflocale/geo/lookup_country` or `perflocale/geo/providers` filter, the IP is passed to that source once per first visit to resolve a country code; the country code is then cached server-side (24 hours by default) under a salted, non-reversible key - an HMAC-SHA256, keyed with the site's auth salt, of the IP after `wp_privacy_anonymize_ip()` has zeroed the host bits - never the raw IP or any value reversible to it.
* **WordPress Privacy API integration:** Tools → Export Personal Data and Tools → Erase Personal Data both work. The eraser zeroes `created_by` on the background jobs the data subject dispatched and deletes their per-user UI-state meta - returning `items_removed`/`items_retained` counts. The same flow runs on the admin `delete_user` path. Full detail in the docs.
* **Consent gating:** the `perflocale/privacy/consent_given` filter lets any consent-management plugin (Cookiebot, Complianz, OneTrust, etc.) hold back PerfLocale until a visitor has consented. When the filter returns false, the `perflocale_lang` cookie is not set, and the GeoIP and browser-language redirects do not run (no outbound request is made).
* **Cookieless mode:** PerfLocale → Settings → URL & Routing → Visitor Redirects → "Language Cookie" turns the `perflocale_lang` cookie off - no consent-management plugin required. Code on your site can still decide per request through the `perflocale/language_cookie/enabled` filter. URL-based language routing keeps working; you lose "remember my language" on non-prefixed URLs. On a WooCommerce store you also lose the cart and checkout language: the block cart/checkout posts to the non-prefixed Store API URLs, and the language stamped on a new order is read from that same cookie, so both fall back to the site's default language. The automatic first-visit redirects (browser language, GeoIP, edge hint) do not run in cookieless mode unless code hooks that filter: without the cookie they would redirect the same visitor again on every default-language page.
* **Suggested privacy-policy text:** auto-registered via `wp_add_privacy_policy_content()`. The sections shown adapt to which features are enabled - GeoIP wording only appears if GeoIP is on, MT wording only appears if MT is on.

Full technical detail: [perflocale.com/docs/privacy](https://perflocale.com/docs/privacy/)

== External Services ==

PerfLocale can optionally connect to external services for machine translation. All external service calls are **disabled by default** and require explicit user configuration (choosing a provider and enabling the feature in settings; most providers also need an API key). No data is sent to any external service without your action. The hosted providers below are contacted over HTTPS; for self-hosted or user-configured endpoints (LibreTranslate, agency, webhooks) use an HTTPS URL.

The GeoIP redirect sends no visitor IP address anywhere on its own: its built-in sources read a country code that your CDN (Cloudflare `CF-IPCountry`, Amazon CloudFront `CloudFront-Viewer-Country`) or your web server's GeoIP module already adds to the request, and make no request. Any other geolocation or exchange-rate source is one you wire yourself through the `perflocale/geo/lookup_country` / `perflocale/geo/providers` and `perflocale/woocommerce/exchange_rates_fetched` / `perflocale/woocommerce/exchange_rate_providers` filters; disclosing that service is your responsibility as the site owner.

API keys for the providers below can be supplied via an environment variable, a `wp-config.php` constant, or the admin Settings field (see the "Can I keep API keys out of the database?" FAQ).

= Machine Translation =

When you enable machine translation and configure an API key in PerfLocale → Settings → Addons → Machine Translation, the plugin sends the text you ask it to translate to the selected provider, together with source/target language codes and your API key. That text is: post titles, content and excerpts; taxonomy term names and descriptions; interface strings listed on the Strings screen (which can include strings registered by other plugins and themes) when you use its machine-translation controls; and, when meta translation is enabled, registered meta values such as SEO titles and descriptions or custom text fields. It is sent when you click "Machine Translate" or use the block editor's translate actions, run a bulk or site-wide translation, translate via WP-CLI or the REST API, or enable auto-translate on publish or on create. Each provider below receives exactly that data, and only for the actions just listed. The provider API additionally defines a connection-test call that sends your API key and, for some providers, a fixed test word - never your content - so an add-on can verify credentials; no screen, WP-CLI command or REST route in PerfLocale itself invokes it.

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

= WooCommerce exchange rates =

When you turn on Auto-Sync Rates and select "European Central Bank" as the Rate Provider in PerfLocale → Settings → Addons → WooCommerce (or click Sync Now), the site downloads the ECB's public euro foreign exchange reference rates file on the sync schedule you choose. The request is a plain download: it sends no visitor data, no order or store data and no key, only a PerfLocale user-agent and the usual connection details (the server's IP address). PerfLocale computes rates against your store currency from that file.

* **European Central Bank** - www.ecb.europa.eu. Euro foreign exchange reference rates (daily file). Receives only the download request described above.
 [Terms of Use (Disclaimer and copyright)](https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html) | [Privacy Statement](https://www.ecb.europa.eu/services/data-protection/privacy-statements/html/index.en.html)

= Webhooks =

When you register webhooks via the PerfLocale REST API, the plugin sends event notifications to your configured webhook URLs when translations are created, updated, or content changes:

(loopback, private-network and credential-bearing destinations, and ports outside WordPress's own allowlist of 80, 443 and 8080, are rejected at validation time, and the refusal names the rule that was broken; on multisite, registering one requires network-administrator permissions) Endpoints you register via the PerfLocale REST API to receive translation-lifecycle notifications (use HTTPS URLs); the destinations are entirely under your control and the plugin calls no hard-coded URL. The plugin POSTs the event type, translation data (post IDs, language codes, status), and a timestamp whenever a translation is created, updated, or otherwise changes, and signs each payload with HMAC-SHA256 when a shared secret is configured. Terms of service and privacy policy are governed by whatever destination you register; review the operator's public policies before registering the URL.

PerfLocale can also publish a read-only public REST endpoint for edge runtimes (`/wp-json/perflocale/v1/config`). It is served by your own site, makes no outbound third-party request and sends no data anywhere - see the "Does PerfLocale expose anything to edge workers?" FAQ for the full description.

== Changelog ==

Full release notes for every version: [perflocale.com/changelog](https://perflocale.com/changelog/)

= 1.0.8 =
* Security hardening.
* Translated URL bases: the base of a post type or taxonomy can use its own word in each language, for example /de/produkte/ instead of /de/product/ (Settings > URL & Routing > URL Bases); terms get a URL slug per language, and links with an old base or slug lead to the current address.
* Content ReSync: after you change an original, "Resync from source" or the bulk "Update translations" brings its translations up to date, with an estimate first and a screen to review the changes. It is off until you turn it on under Settings > Translation > Content ReSync.
* Machine translation: long posts are translated in parts between whole blocks, with optional context from the parts around them; SEO focus keywords can be translated too (off by default), and character limits count only the text a provider receives.
* Visitors and search engines: a noindex option for missing translations, built-in visitor country detection (Cloudflare, Amazon CloudFront, server GeoIP), daily European Central Bank exchange rates without a key, browser language matching by region, and pages that stay cacheable; visitor pages load less code and run fewer database queries.
* Reliability and fixes: WooCommerce stock copies stay correct when many orders arrive at once, imports and uninstall are safer, PerfLocale needs no PHP extension beyond what WordPress needs, and many fixes. After the update, a few translated strings in database mode may need "Scan now" on the Strings screen; a notice tells you. Going back to 1.0.7.5 without restoring your backup: see the full changelog.
* Rank Math: a new translation's Facebook and X images use the image's copy in the translation's language when there is one; the perflocale/rankmath/translate_social_images filter turns this off.
* Developers: new filters, hooks and WP-CLI options for URL bases, fallback, Content ReSync and translations.

= 1.0.7.5 =
* Security hardening.
* Faster pages: visitor pages, Elementor page views, the login page, AJAX requests and admin screens load less PHP code, and the language switcher's script and stylesheet are minified (the full files load with SCRIPT_DEBUG).
* Machine translation: External Agency can send several texts in one request (opt-in); a new agency address must use https://, or http:// for a host you add with the `perflocale/mt/trusted_hosts` filter (a local or Docker endpoint); whitespace-only text is never sent; resuming a stopped bulk run keeps a translation someone edited in the meantime and reports it; a failed call names the item and its length; a short Chinese, Japanese or Korean title or excerpt is saved with a warning.
* Import, export and Strings: an XLIFF import over the REST API runs as a background job in short slices instead of being refused above 60 translation units (`POST /xliff/import` answers 202 with a job id), continues after an interruption, keeps a draft an interrupted import left and lists it in the import's messages instead of deleting it, and keeps a failed import's file so Retry works; the Strings screen saves only the cells you changed, a person's edit replaces a machine translation made since the page opened, and another person's change is reported instead of overwritten; a .po export includes every plural form, so exporting and importing a file keeps all translations.
* Reliability and fixes: database upgrades that cannot finish retry on their own, show what failed with a Retry now button and a Site Health check, and never break the site; the protective .htaccess in PerfLocale's uploads folders works on Apache 2.2 and 2.4, and an upgrade replaces an old one safely and retries until it succeeds; a settings save that stores nothing names the cause; hreflang tags print once per page; a disabled language never appears as a public link; WooCommerce exchange rates refresh after a base currency change, and Store API answers in a visitor's cookie language are never stored by shared caches; Polylang and TranslatePress import failures are reported; `wp perflocale health-check` reports string links without a translation; creating a draft or pending post through the REST API with a slug no longer fails.
* Developers: template tags and helper methods take a language slug, locale or BCP 47 tag, and an unknown or inactive language gets a `_doing_it_wrong` notice; `switcher()` and `[perflocale_switcher]` take include, exclude and untranslated_link; new `flag_emoji()`, `wp perflocale jobs run`, and filters for External Agency batches and XLIFF staging limits; `perflocale/updated` can run again after a failed update, so listeners must be idempotent; a filter that returns the wrong type is ignored and reported.

= 1.0.7.4 =
* Security hardening.
* Background jobs: bulk machine translation continues where a stopped worker left off, bulk string translation runs in short slices, and work one web request cannot finish is refused with the WP-CLI command that runs it.
* Machine translation: "Do not translate" blocks keep their markup on every save path (WordPress 6.7+); a cut-off title, excerpt or classic content is not saved; page builder layouts that machine translation does not send are reported.
* Developers: a PHP API for add-ons (`perflocale()->addon()`) and JavaScript hooks for the language switcher, the block editor and the Jobs, Strings and Translations screens.
* Export & Import and repairs: a Replace import that loses its database connection changes nothing, Merge imports keep their working data in a database table when the database allows it, and `wp perflocale health-check` lists links a string import replaced and gives each string a translation group of its own.
* Visitor pages load less PHP code and admin screens load fewer script files.

= 1.0.7.3.1 =
* Page builders: the builder link of an unpublished translation (for example "Edit with Oxygen") opens the translation, not the original page, so saving no longer changes the original. A builder link that cannot open the translation stops with a message instead.

= 1.0.7.3 =
* Security hardening.
* Machine translation: a result that breaks the source's block structure is never saved, block comments and shortcode tags are held back from the provider, and the editor says why a translation was not saved. A translation someone edits while machine translation runs keeps their edit. New `wp perflocale damaged-blocks` finds translations damaged earlier.
* Export & Import: media texts, classic menu languages, item flags, add-on data, language-code redirects, webhooks and Translator permissions travel in an export; large files upload and import in pieces; an import gives each post the values the file holds.
* Site Editor: translated templates and template parts use their language's menu and synced pattern translations.
* Large sites do less work: sitemaps, ACF, Meta Box and Pods reference fields, block references, XLIFF export, the Polylang import and the strings repair read and write in batches.
* Pods: relationship fields show the translations on translated pages, also in shortcodes, blocks and display().
* Clear Cache clears PerfLocale's caches only, no longer the whole object cache.
* Safer Polylang, WPML and TranslatePress imports, clearer XLIFF import errors and Jobs notices, and accessible labels on four admin controls.
* Editors of a taxonomy with its own permissions can set a term's language.
* SQLite fixes, Meta Box fields on translated pages, and a new `wp perflocale stock-drift` check for WooCommerce stock.

= 1.0.7.2 =
* Security hardening.
* Most visitor pages load less plugin code, and the plugin's own translations load when a page shows PerfLocale text instead of at start-up; sites without an opcode cache gain the most.
* The language switcher ships translated into 24 languages; regional variants such as de_AT, de_CH, nl_BE, fr_CA, es_MX and pt_AO use the closest bundled language.
* Language switcher: a dropdown that a window resize hides closes and stops following the page, and its links work without JavaScript.
* WooCommerce shared stock, Sync Fields and slug redirects stay consistent when two edits happen at the same time; saving a post makes fewer cache reads.

= 1.0.7.1.1 =
* Block editor: saving a post or page no longer ends in an error, and the "View Post" link appears again after publishing.

= 1.0.7.1 =
* Security hardening.
* Faster cart and checkout on stores whose language copies share stock.
* Sync Fields: a change reaches only the translations the person saving may edit, and a translation no longer moves the original's publish date.
* Site Editor: create translations of menus, synced patterns, templates and template parts in the Translations panel; navigation overlays are translated (WordPress 7.0+).
* Site Health lists missing language packs, and the language switcher ships translated into 13 languages.
* Many fixes for WooCommerce, hreflang, webhooks and caching. On WooCommerce older than 10.8, run Status → Tools → Regenerate product lookup tables once.

= 1.0.7 =
* Security hardening.
* A language that still has content can no longer be deleted; the Languages screen shows what it still holds.
* Merge imports no longer change this site's settings, add-ons or roles.
* ACF, Meta Box and SEO fields stay correct however a post is saved, including nested and repeater fields.
* WooCommerce: language copies share one stock in cart and checkout, and order emails keep their language.
* Bulk translation survives host time limits, plus many fixes for imports from WPML, Polylang and TranslatePress.

= 1.0.6 =
* Security hardening and stricter permission checks.
* Importing a file from another site asks you to confirm this site is a copy of it.
* WooCommerce: a visitor's first default-language page sets no cookie, so page caches can store it.
* Multisite: deleting the plugin works through large networks in batches.
* Front-end pages, feeds, sitemaps and REST requests run fewer database queries.
* Many fixes, including previews of draft translations, synced patterns and menus, and the WPForms builder.

== Upgrade Notice ==

= 1.0.8 =
Security hardening. Translated URL bases, Content ReSync, machine translation of long posts in parts, less code per page. The Contact Form 7 integration needs CF7 5.8 or later and pauses with an older CF7. To go back, restore the pre-update database backup and wp-content/uploads/perflocale/.

= 1.0.7.5 =
Security hardening. Less code on every page, large XLIFF imports as background jobs (the REST route answers 202 with a job id), a Strings screen that never overwrites changes made after you opened it, and database upgrades that retry on their own.

= 1.0.7.4 =
Security hardening. Background jobs that continue after a stopped worker, protected blocks that keep their markup, and less code on visitor pages.

= 1.0.7.3.1 =
Opening an unpublished translation in a page builder opens the translation, not the original page.

= 1.0.7.3 =
Security hardening. Machine translations that would break block markup are no longer saved, a more complete and safer Export & Import, Site Editor menus and patterns per language, and less work on large sites.

= 1.0.7.2 =
Security hardening. Less code on visitor pages, the language switcher in 24 languages, and more reliable stock, Sync Fields and slug redirects under concurrent edits.

= 1.0.7.1.1 =
Fixes an error after every save in the block editor.

= 1.0.7.1 =
Security hardening. Faster cart and checkout on stores whose language copies share stock, Sync Fields that keeps the original's publish date, Create in the Site Editor's Translations panel, and a Site Health check for missing language packs.

= 1.0.7 =
Security hardening. A language that still has posts can no longer be deleted (deactivate it instead), and a Merge import no longer changes this site's settings or roles. Custom fields, WooCommerce stock, bulk translation jobs and imports are more reliable.

= 1.0.6 =
Security hardening and stricter permission checks. Importing a JSON file from another site can now ask you to confirm this site is a copy of it. On WooCommerce stores, default-language pages no longer set the language cookie. Multisite uninstall and activation are more reliable.
