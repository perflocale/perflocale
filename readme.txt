=== PerfLocale ===
Contributors: alexgeorgiev
Tags: multilingual, translation, i18n, language, localization
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.6
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
* **Translator role** - a dedicated role with translation-only capabilities for your translation staff
* **E-commerce** - WooCommerce product/variation/attribute translation, multi-currency (rates supplied by your own provider hook), inventory sync, localized order emails
* **Reliability** - circuit breakers around every external dependency, token-guarded atomic locks, self-healing background jobs, and Site Health diagnostics

= Modern SEO & UX =

Translation-aware features generic SEO plugins can't provide: Content-Language HTTP header, `data-nosnippet` guard for fallback pages, and opt-in Speculation Rules prerender and View Transitions for switching between languages. Auto-detects and integrates with 20+ plugins and themes, including WooCommerce, Elementor, ACF, Meta Box, Pods, all major SEO plugins, Gravity Forms, and the Blocksy, Kadence, and Neve themes.

= For developers =

200+ action/filter hooks, a full REST API, WP-CLI commands, and a documented addon system. A PHP helper API lets you translate a string or render a block of markup in any language from your own code. Every internal primitive is `@api` and semver-stable across 1.x. Multisite-ready. Full docs at **https://perflocale.com/docs/**.

**Try it without installing anything.** Open a throwaway WordPress site in your browser with PerfLocale already active: https://playground.wordpress.net/?blueprint-url=https://perflocale.com/blueprint.json

= Where to go next =

* **Set-up guide** — a fresh install to a translated post with a working switcher: https://perflocale.com/docs/getting-started/
* **Switching from another plugin** — importers for WPML, Polylang and TranslatePress: https://perflocale.com/docs/migration/
* **How it compares** — PerfLocale against WPML, Polylang and TranslatePress: https://perflocale.com/compare/
* **WooCommerce** — products, variations, currencies, stock and order emails: https://perflocale.com/docs/woocommerce/
* **Multisite** — activation, per-site languages and background jobs across a network: https://perflocale.com/docs/multisite/
* **Something not working?** — symptom-first troubleshooting: https://perflocale.com/docs/troubleshooting/
* **Hooks reference** — every action and filter: https://perflocale.com/docs/hooks/
* **REST API and WP-CLI** — https://perflocale.com/docs/rest-api/ and https://perflocale.com/docs/wp-cli/
* **Source code** — https://github.com/perflocale/perflocale

== Installation ==

**Permalinks:** pretty permalinks (**Settings → Permalinks** set to anything other than "Plain") are recommended — with them WordPress guarantees that language-prefixed URLs such as `/de/…` reach WordPress on every server. Plain permalinks also work (URLs become `/de/?p=123`) as long as the server routes all paths to WordPress, which nginx configurations and Apache setups with the standard WordPress `.htaccess` block do. Subdomain and per-domain URL modes carry the language in the hostname and work with any permalink setting. For servers that do not route unknown paths to WordPress, query-parameter mode (URLs become `example.com/page?lang=de`, selectable under **PerfLocale → Settings → URL & Routing**) works with any permalink structure — including Plain — on every server, keeping clean URLs for the default language. Site Health reports the exact status for your server.

**Data exports and your web server (nginx and Caddy users, please read).** Exports are written to `wp-content/uploads/perflocale/exports/` and are downloaded through an authenticated, nonce-checked admin link that deletes the file as soon as it is served. The directory also gets a `Deny from all` .htaccess — but **only Apache and LiteSpeed honour .htaccess. nginx and Caddy ignore it.** On those servers an export stays fetchable by its exact URL until it is downloaded or swept, so add an explicit rule:

nginx:

`location ~* /wp-content/uploads/perflocale/exports/ { deny all; return 404; }`

Caddy:

`@perflocale_exports path /wp-content/uploads/perflocale/exports/*`
`respond @perflocale_exports 404`

*Tools → Site Health* tells you which situation you are in: PerfLocale writes a temporary random file into that directory, requests it over HTTP, and reports a **critical** result if the server hands it back. Nothing to configure — just look at the check after your first export.

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

Very little — performance is a core design goal. The plugin keeps the work it does on each page small, with three layers of caching to keep its own database work low. Larger sites — many translated posts across many languages — benefit from running a persistent object cache like Redis. PerfLocale is fully compatible with page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.); cached pages already include the translated output from the request that filled the cache. Real-world page speed depends mostly on your theme, hosting, and other plugins.

= Does it work with WooCommerce? =

Yes. PerfLocale includes a deep WooCommerce integration: translate products, variations, categories, and attributes. Inventory (stock, SKU, weight, dimensions) syncs automatically across language variants. Multi-currency support, with exchange rates supplied by a provider your site registers via filter. Order emails are sent in the customer's language. The mini-cart, cart, and checkout all display correctly in every language.

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

Yes. PerfLocale includes built-in migration tools for all three plugins. PerfLocale refuses to run while WPML, Polylang or TranslatePress is active, so deactivate the old plugin first — its data stays in the database — then go to PerfLocale → Settings → Export & Import and use the Migration section. All migrations run in batches with transaction safety - if anything fails, your data is rolled back.

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

On a multisite network each site's own choice is applied to that site. Deleting the plugin from a very large network can run out of PHP execution time before every site is done: PerfLocale stops cleanly between sites, logs how many were purged and how many remain, and records where it stopped. Re-install the plugin and delete it again without activating it and the next pass carries on from there; from WP-CLI, `wp plugin uninstall perflocale` has no time limit and finishes the network in one pass.

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
* **Cookieless mode:** PerfLocale → Settings → URL & Routing → "Language Cookie" turns the `perflocale_lang` cookie off entirely — no consent-management plugin required. URL-based language routing keeps working; you lose "remember my language" on non-prefixed URLs. On a WooCommerce store you also lose the cart and checkout language: the block cart/checkout posts to the non-prefixed Store API URLs, and the language stamped on a new order is read from that same cookie, so both fall back to the site's default language.
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

(loopback, private-network and credential-bearing destinations, and ports outside WordPress's own allowlist of 80, 443 and 8080, are rejected at validation time, and the refusal names the rule that was broken; on multisite, registering one requires network-administrator permissions) Endpoints you register via the PerfLocale REST API to receive translation-lifecycle notifications (use HTTPS URLs); the destinations are entirely under your control and the plugin calls no hard-coded URL. The plugin POSTs the event type, translation data (post IDs, language codes, status), and a timestamp whenever a translation is created, updated, or otherwise changes, and signs each payload with HMAC-SHA256 when a shared secret is configured. Terms of service and privacy policy are governed by whatever destination you register; review the operator's public policies before registering the URL.

PerfLocale can also publish a read-only public REST endpoint for edge runtimes (`/wp-json/perflocale/v1/config`). It is served by your own site, makes no outbound third-party request and sends no data anywhere - see the "Does PerfLocale expose anything to edge workers?" FAQ for the full description.

== Changelog ==

Each release below is a short summary. The complete notes for every
version, with the reasoning behind each change, live at
https://perflocale.com/changelog/

= 1.0.6 =

Security hardening and stricter permission checks.

Importing a JSON file from another site, or a backup of this site that records a language-specific address, can now ask you to confirm that this site is a copy of it: tick the checkbox under Settings → Export & Import, or pass --force in WP-CLI.

On WooCommerce stores, a visitor's first page in the site's default language no longer sets the language cookie, so full-page caches can store those pages. Cart and checkout keep working in every language.

Also fixes previews of draft translations that share their slug with a published page, synced patterns and navigation menus rendering blank when their translation was published empty, the WPForms builder's "+ Create" failing even for administrators, an error or a duplicate when two requests created the same translation at once, page titles (WooCommerce's Create Page Translations) and term names (Create Taxonomy Translations) saved in the admin's language when the target language had no WordPress language pack, Strings-screen hints shown in the wrong language, and the Jobs screen showing "Not scheduled" for a task that was running. Front-end pages, feeds, sitemaps and REST requests also run fewer database queries - 2 to 8 fewer on each one we measured on a WooCommerce test site with Redis - with the same output.

On multisite, deleting the plugin now works through a large network in batches and records where it stopped if a request runs out of time: re-install the plugin and delete it again, without activating it, and the next pass carries on from there. Each site's own "Delete data on uninstall" choice survives the interruption. Network activation no longer stops at the first site that fails, and rendering content from another site no longer leaves the original site without its detected language.

Full notes: https://perflocale.com/changelog/

= 1.0.5 =

Translates block-theme templates and template parts, so the header, footer and page layouts you customise in the Site Editor can be translated like any other content, and shows a Translations panel in the Site Editor and in the Contact Form 7 and WPForms form editors — screens the existing panels could not reach. Fixes a class of defect where content that a plugin looks up by identity rather than by language disappeared on every non-default language: Contact Form 7 embeds rendered "Error: Contact form not found", Oxygen pages rendered with no layout at all, block-theme navigation fell back to an auto-generated menu, and on WooCommerce block themes the entire product body vanished. Also fixes Contact Form 7 forms missing from the Translations screen, translated grouped products losing their product type and their child products, the shop page showing every language's products when it is the front page, cart and checkout links pointing at unpublished pages, a "Needs update" badge that could never be cleared, an Oxygen template that could render in the wrong language once its translation group was reduced to a single translation, a dashboard that counted trashed translations as translated, a multisite case where one subsite's active add-ons could switch off language filtering on another, and WPForms forms that could not be translated by anyone at all — every translation request was refused, administrators included. Elementor translations also no longer inherit the original page's generated CSS and cached HTML, which could make a newly created translation render unstyled or show the original language.

Saving a block template now creates it in every language, each seeded with the original's blocks and created as a draft, so nothing changes for visitors until you publish one. Add a language later and the next save picks it up.

Page-builder layouts are no longer overwritten between languages. Elementor, Beaver Builder, Bricks, Oxygen and Oxygen 6 store a page's text inside their layout data, and PerfLocale had been keeping that data identical across a translation group in both directions - so saving a translated page could replace the original with the translated language, and saving the original could replace a finished translation. A new translation is still created as a full copy of the original layout; from then on each language owns its own. The trade-off is that later structural edits no longer propagate automatically to existing translations.

Numbers and currency now follow the requested language's own conventions rather than the locale the page happens to be running in, so a German page shows 1.234,56 where an English one shows 1,234.56. Media is no longer offered as a translatable content type: PerfLocale never translated media by duplicating attachments - it stores per-language alt text, captions and descriptions on the same attachment - so the checkbox enabled nothing while language-scoping every front-end attachment query as a side effect. Media translation itself is unchanged.

On WordPress 6.9 and newer, four read-only AI-agent abilities (list languages, get a post's translations, detect a post's language, convert a URL between languages) now register by default; the two write abilities stay off. Both switches live under PerfLocale → Settings → Advanced → AI Agent Abilities.

Full notes: https://perflocale.com/changelog/

= 1.0.4 =

Adds translation for the site title and tagline, synced patterns and block-theme navigation menus, and a PHP API for translating from your own code. Fixes a case where a translated site title could overwrite the original, a multisite call returning the wrong blog's name, and scheduled rendering windows resolving the wrong language.

Full notes: https://perflocale.com/changelog/

= 1.0.3 =

Fixes WooCommerce order emails going out in the wrong language, percent signs deleted from translated titles, exports publishing unreadable bytes, Site Health checks that never reached the dashboard, and the admin on phones and tablets.

Full notes: https://perflocale.com/changelog/

= 1.0.2 =

Fixes translated content served in the wrong language on sites whose slugs are not Latin script, permanently corrupted slugs for long non-Latin names, fatal errors on servers built without an optional PHP extension, a PO import that could delete a language's translations, and a group of settings that could not be reached or were silently cleared.

Full notes: https://perflocale.com/changelog/

= 1.0.1 =

Security and reliability release: security hardening and stricter permission checks. Also fixes WooCommerce stock lost when several language versions of a product sold at once, and makes Replace-mode imports all-or-nothing.

Full notes: https://perflocale.com/changelog/

= 1.0.0 =

Initial public release. Translation for posts, pages, custom post types, taxonomies and URL slugs; four routing modes with self-healing rewrite rules; gettext string translation with full CLDR plural rules; SEO output including hreflang and sitemaps; WooCommerce support; machine translation; importers for WPML, Polylang and TranslatePress; a REST API and WP-CLI commands.

Full notes: https://perflocale.com/changelog/

== Upgrade Notice ==

= 1.0.6 =
Security hardening and stricter permission checks. Importing a JSON file from another site can now ask you to confirm this site is a copy of it. On WooCommerce stores, default-language pages no longer set the language cookie. Multisite uninstall and activation are more reliable.

= 1.0.5 =
Translates block-theme templates and template parts, and adds a Translations panel to the Site Editor and the Contact Form 7 and WPForms editors. Fixes forms, Oxygen layouts, menus and WooCommerce product bodies vanishing on non-default languages, and WPForms forms nobody could translate.

= 1.0.4 =
Translates the site title, tagline, synced patterns and block-theme menus. Fixes a translated site title overwriting the original, a multisite call returning the wrong blog's name, and order emails resolving the wrong language.

= 1.0.3 =
Fixes WooCommerce order emails in the wrong language, percent signs deleted from titles, and the admin on phones and tablets.

= 1.0.2 =
Machine Translation could not be switched on from the admin at all, and three settings were silently cleared by unrelated saves. Also fixes a fatal on servers without mbstring, translates WPForms confirmations, and makes XLIFF imports about three times cheaper.

= 1.0.1 =
Security and reliability release: security hardening and stricter permission checks. Also fixes a WooCommerce race that lost stock when several languages sold at once.

= 1.0.0 =
Initial release of PerfLocale.
