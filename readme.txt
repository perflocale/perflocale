=== PerfLocale ===
Contributors: alexgeorgiev
Tags: multilingual, translation, i18n, language, localization
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance-first multilingual plugin. Translate posts, pages, products, taxonomies, strings, and slugs with 3-layer caching and 20+ integrations.

== Description ==

PerfLocale is a **performance-first multilingual plugin** for WordPress. A 3-layer cache, batch-preloaded queries, and conditional hook registration keep its own code to a small fraction of total page time.

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

Very little — performance is a core design goal. The plugin's own code stays a small fraction of total page time, with three layers of caching so most requests never touch the database. Larger sites — many translated posts across many languages — benefit from running a persistent object cache like Redis. PerfLocale is fully compatible with page-cache plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.); cached pages already include the translated output from the request that filled the cache. Real-world page speed depends mostly on your theme, hosting, and other plugins.

= Does it work with WooCommerce? =

Yes. PerfLocale includes a deep WooCommerce integration: translate products, variations, categories, and attributes. Inventory (stock, SKU, weight, dimensions) syncs automatically across language variants. Multi-currency support, with exchange rates supplied by a provider your site registers via filter. Order emails are sent in the customer's language. The mini-cart, cart, and checkout all display correctly in every language.

= Does it work with page builders? =

Yes. PerfLocale integrates with Elementor, Beaver Builder, Bricks Builder, Oxygen Classic, and Oxygen 6.0. Each builder's content is registered as translatable meta, and dedicated Language Switcher widgets/elements are provided for Elementor, Beaver Builder, and Bricks — in Oxygen (Classic or 6.0) use the `[perflocale_switcher]` shortcode in a Code Block or Shortcode element.

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

= 1.0.1 =

Security and reliability release. Updating is recommended for every site, and required for any site that uses the Translator role or Contact Form 7.

**Security.** A user holding the Translator role could publish, privatise or trash any post or page they were able to edit. Translation status changes were gated only on the edit capability, so the publish and delete capabilities that role deliberately withholds were bypassed. Status changes are now mapped to the target post type's own capabilities, exactly as WordPress core does. Drafting is unchanged.

**Security.** Translated Contact Form 7 forms rendered the form's stored configuration into the public page — recipient addresses, mail templates and headers included — because Contact Form 7 keeps a flattened copy of every property in the post content. Translated forms now read Contact Form 7's own form property instead. Note that a machine-translated form reverts to the source form markup until it is re-saved in Contact Form 7's editor.

**Security.** A new Site Health check reports whether your server actually protects the export directory. The plugin writes a `Deny from all` .htaccess beside every export, but nginx and Caddy ignore .htaccess entirely, and PHP cannot tell which server it is behind. Rather than assume, PerfLocale now places a temporary random file there, requests it over HTTP, and raises a critical result — with the exact nginx and Caddy snippet — if the file comes back. See the installation notes.

**Security.** Data exports are no longer guessable by URL: filenames now carry 32 characters of entropy instead of six. Exports are also published atomically, so a partially written file can never be served, and an export's filesystem path is hidden from REST callers who lack the import/export capability. Nested credentials and URL user-info are now stripped recursively from exports rather than only at the top level.

**Security.** The same translation endpoint also accepted WordPress's internal post statuses. Setting a translation — or, for the site's default language, a source post — to `auto-draft` handed it to the WordPress routine that permanently deletes abandoned drafts, so a role deliberately denied the delete capability could still have content destroyed. Internal statuses are now refused. Draft, pending and workflow statuses added by other plugins are unaffected, and custom post types are judged by their own capabilities rather than by the built-in post ones.

**Security.** Machine translation requested through the plugin's Abilities integration bypassed the hourly per-user and site-wide translation limits, and the contention guard, that the REST endpoints enforce. On a site with Abilities enabled this meant a single account could spend translation credit without limit. All three entry points now share one admission check.

**Security.** The WordPress AI Client provider called the SDK's `generateText()` directly. WordPress applies `wp_supports_ai()` and the site-wide `wp_ai_client_prevent_prompt` policy filter only to the snake_case form, so a site that had globally blocked AI prompts was still being prompted by this plugin. The call now goes through the policy layer.

**Security.** Machine-translation rate limiting now validates the shape of its stored counter rather than only its presence. A corrupted counter — from an object-cache collision or another plugin writing the same key — could previously slip past the hourly cap entirely, or turn a translation request into a fatal error.

**Reliability.** When the scheduler refuses a webhook retry, that refusal is now recorded as a delivery failure instead of being discarded. Previously the retry vanished with no second attempt and no entry in the failure log. Failure-log lock contention is also reported rather than silently skipped.

**Reliability.** A post list containing an entry that is not a post object — WordPress can hand one to its `the_posts` filters when a post is permanently deleted during a page load — caused a fatal error in the front-end translated-slug preload and in the translations column of the admin post list. Both now skip such entries and behave exactly as before for every real post.

**Performance.** Saving a post no longer rewrites its translations when nothing that is actually synced has changed. Every save used to issue one full update per translation regardless, which bumped each translation's modified date, fired the save hooks other plugins listen to, and cost a full set of database writes per translation. Translations are now written only when a synced field really differs. Nothing about what gets synced has changed.

**WooCommerce.** Block Cart and Block Checkout work again on subdirectory multisite children. A shopper browsing in a non-default language made every Store API request return a 404 there, which broke the cart. Store API messages on those sites now come back in the site's default language; single sites, subdomain networks and per-domain networks are unaffected.

**Background jobs.** Deleting a job created before 1.0.1 no longer removes whatever file happens to sit at that job's old export path. Those records predate the file-identity check, so they can no longer prove they own the file; the artifact is left to the age sweep instead. The failure log also no longer records the export path, filename or download token — only the job ID and a short hash.

**REST API.** A status that WordPress refuses for a post type now returns 400 `translation_status_rejected` instead of 500. Nothing is written either way; the previous response told clients the server had broken when the request was at fault.

**Caching.** New `perflocale/cache/purge_urls` action fires when a post's public visibility changes, carrying every affected front-end URL including each translation's. A translated page is a separate URL that a full-page cache usually does not know about, so one that had been public could stay readable in the cache after being made private. PerfLocale now names the URLs; wiring them to your cache is a three-line filter. See the hooks reference.

**Security.** Draft, pending, private and trashed translations no longer leak through Yoast breadcrumb structured data or the WooCommerce cart. Custom machine-translation endpoints are checked against IPv6 addresses as well as IPv4, and translation responses are size-capped. Credential masking in provider errors no longer depends on the credential containing a digit.

**WooCommerce.** Simultaneous purchases across language versions of the same product no longer lose a stock decrement. Each sale is now applied to the sibling languages as a relative change that the database performs atomically, so three shoppers buying three language versions of a stock-10 product at the same instant leave every version at 7. Previously two of those three sales could be lost, and the loss grew with the number of simultaneous orders. Stock status and the product lookup table WooCommerce uses for shop queries are now derived in the same operation that reads the quantity, so a translated product can no longer display a figure that a concurrent sale has already superseded. Exchange-rate syncing now merges the provider's response instead of replacing the whole stored set, so a partial response no longer wipes the currencies it omitted, and a response that fails validation now counts as a failure for the circuit breaker rather than resetting it. Bulgaria's suggested currency is now EUR.

**Content.** Protected nested blocks keep their inner blocks. XLIFF export no longer strips emoji, rare CJK and other supplementary-plane characters. A failed table read during export now fails loudly instead of producing a valid-looking, silently incomplete backup.

**Reliability.** A failed string-translation move no longer deletes the source rows. A transient database error while building the link map is no longer cached as an authoritative empty result. Job locks can no longer be released or garbage-collected by a previous owner. Data import no longer flushes the entire object cache every 500 rows, which on sites with Redis or Memcached evicted every other plugin's cached data. The Action Scheduler integration — including the bridge that marks a job failed when its worker is killed — now actually registers; it previously never did.

**Background jobs.** Deleting a job now also removes the export file it produced, instead of leaving it in the uploads folder with no record identifying it. That cleanup no longer follows a symbolic link, so it cannot remove a file belonging to a different job, and it now verifies that the file at the recorded location is still the one the job created. If the file cannot be removed, the job is still deleted and the leftover is logged rather than disappearing silently.

**Background jobs.** The "is this event already scheduled?" check was a no-op under Action Scheduler, because an async action has no next-run timestamp for it to find — so scheduling guards that relied on it could queue duplicate work. It now asks Action Scheduler directly. Recurring maintenance events now report whether the scheduler actually accepted them, and a refusal is logged with its reason instead of being indistinguishable from success. Site Health also now lists the machine-translation usage cleanup among the recurring events it checks, so all four are covered. A long-running command or worker no longer reuses a stale answer about whether one of the plugin's jobs is in flight; on multisite that stale answer could come from a different site and delay Action Scheduler's recovery of *other* plugins' stuck tasks. An export whose post-write hook throws is no longer orphaned: the export succeeded, so the job keeps its result and reports the hook failure separately. When a scheduler refuses an event — a filter veto, a duplicate, or a queue error — that refusal is now reported and logged rather than silently producing no work. On multisite, a job lookup can no longer return another site's job when one process handles more than one site.

**Routing.** Query-parameter URL mode now honours the URL Prefix Format setting: a site set to locale form serves `?lang=en-us` rather than `?lang=en`, everywhere the plugin writes a URL — including the search form's hidden field and the WooCommerce AJAX endpoint. Requests using the other form are permanently redirected to the canonical one, so a language keeps exactly one indexable URL instead of two that both answer 200.

**Routing.** A language could be made unreachable by its own slug. Language locales are also matched in their URL form, and a single-pass lookup let a later language's locale form overwrite an earlier language's real slug — give one language the slug `de-de` and another the locale `de_DE`, and `/de-de/` served the second one. Real slugs now always win, whatever order the languages were created in.

**REST API.** Creating a language with a slug or locale that already exists returned HTTP 500 with a raw database error. It now returns 409 with `slug_exists` or `locale_exists`, so a client can tell "already there" from "something broke".

**New filter.** `perflocale/url/query_var` renames the query variable used by query-parameter URL mode, for sites where another plugin already owns `lang`. Register it from an mu-plugin and flush permalinks afterwards. See the hooks reference for details.

**New filters.** `perflocale/jobs/deduplicate_admission` controls whether an identical in-flight job blocks a new dispatch (default on). `perflocale/breaker/probe_lease_seconds` sets how long a single circuit-breaker probe holds its turn before another request may try. See the hooks reference for both.

**Backup and restore.** A replace-mode import is now all-or-nothing. If the database refuses even one row, the whole import rolls back and your existing data is left exactly as it was; previously the import deleted the old rows, committed everything that did land, and reported success with the failure listed among the notices. The same is true of a PO import run with replace: a refused translation now rolls the language back instead of leaving it part-replaced with an entry marked translated but empty. Settings, add-on settings, the disabled-add-on list and role grants carried in an import bundle are also undone when the tables roll back — they used to stay applied over data that had reverted.

**Backup and restore.** Every write to an export file is now checked. A disk that fills up, a quota that runs out, or a network share that drops a write partway through no longer produces a file that ends correctly and is corrupt in the middle — the export is abandoned and your previous backup is left in place. A row that cannot be encoded also aborts the export instead of being written as nothing.

**Background jobs.** A worker that cannot record itself as running no longer runs the job anyway. Previously a failed status write left the job listed as queued while its work went ahead — translations created, provider credit spent, the completion hook fired — and the queued row stayed eligible to be picked up and run a second time. A job cancelled at the moment its worker starts is now caught too.

**Background jobs.** Dispatching the same job twice — a double-clicked button, a retried request, the same `--async` command run again — now returns the job already in flight instead of queueing a second one. Only an identical operation is folded in: different arguments, a different job type and the chunked site-translation chain all keep running in parallel as before.

**Machine translation.** The circuit breaker now counts a provider that answers with something unreadable. A proxy or captive portal returning HTTP 200 with an error page used to *clear* the failure count on every call, so the breaker could never trip no matter how long the outage lasted. When the breaker does open and its cooldown expires, exactly one request is now let through to test the provider rather than every request that arrives at once. A late failure can no longer republish a stale "closed" state over a breaker that just tripped, and corrupted breaker state cached by another plugin no longer causes a fatal error. The DeepL and Google providers also refuse a batch reply that does not have one translation per text sent, which otherwise shifted every later translation onto the wrong source string.

**Privacy.** Error text from the HTTP layer is now credential-masked before it is stored or shown, in both the provider error path and the webhook failure log. Only a site-local HTTP filter, custom transport or proxy could put a secret there, but the code no longer assumes none does.

**WP-CLI.** `wp perflocale import`, `po-import` and `network-import` now exit non-zero when the import reports errors. A malformed file previously printed a warning and then `Success:` with exit code 0, so a restore script could record a restore that never happened. `wp perflocale translate` no longer prints ten "invalid synopsis part" warnings on every successful run.

**REST API.** Malformed XLIFF — an empty body, XML that does not parse, a document declaring entities, or a target language the site does not have — now returns 400 `invalid_xliff` instead of 500. Genuine server faults still return 500. An XLIFF file that repeats the same unit identifier is also applied once instead of once per repeat; the result is identical and the import is far quicker.

**Routing.** Translated permalinks on non-Latin sites could be generated broken. WordPress percent-encodes non-ASCII slugs, so one Japanese character costs nine slug characters and an ordinary 26-character title becomes a 198-character slug. When two such slugs collided, PerfLocale trimmed one to make room for a `-2` suffix and the cut landed in the middle of a percent-escape — leaving a slug ending in something like `%e3%81%8`. Apache and nginx both reject a malformed escape in a URL path with a 400 error before WordPress runs, so that translation's permalink, and its entry in the language switcher, hreflang tags and sitemap, could not be opened at all. The trim is now character-aware, exactly as WordPress does it for post slugs. Existing slugs are untouched; re-save the affected translation to regenerate one.

**Multisite.** The notice explaining that PerfLocale cannot run alongside WPML, Polylang or TranslatePress now also appears in Network Admin. When the other plugin was network-activated, the person who activated it worked in Network Admin — where WordPress does not fire the hook the notice used — so they saw nothing at all, and the `wp perflocale migrate` command does not exist in that state either. The notice also now says that their existing translations stay in the database and can be imported after deactivating.

**Add-ons.** A failed add-on boot no longer leaves a permanent warning. WordPress replaces plugin files in place during an update, so a page request landing mid-write can see a half-written file and report a parse error — a problem that fixes itself on the very next request. PerfLocale recorded that permanently and showed it to every administrator until someone ran a WP-CLI command. The record is now retired as soon as the add-on boots successfully, and clearing an add-on's quarantine from the Add-ons screen clears its boot errors too. A failed migration or uninstall is still kept, because a successful boot says nothing about those. The recorded message also no longer contains the server's absolute file paths.

**Behaviour changes worth knowing.** On a site using query-parameter URLs with locale-form prefixes, the query value changes on upgrade and the old form now issues a permanent redirect — expect search engines and browsers to cache that redirect. If two of your languages collide on a slug-versus-locale form, the URL that was serving the wrong language starts serving the right one. Exports now fail rather than silently omit a table that cannot be read. Per-status translation counts may read higher on sites whose stored status had drifted. The `perflocale/mt/pre_translate` and `perflocale/machine_translation/after` hooks now carry the source object's own language rather than the site default. A save that changes none of the synced fields no longer writes to the translations, so `save_post`, `post_updated` and `transition_post_status` no longer fire for them on such a save and their modified dates stay put; the `perflocale/cache/flush_object` purge signal still fires for each translation, so CDN integrations are unaffected. A replace-mode import that previously finished with errors will now roll back instead — check the reported error, fix the bundle, and re-run. Scripts that call `wp perflocale import` or `po-import` and only check the exit code will start seeing failures they were previously told were successes. A second dispatch of an identical job now returns the first job's ID; if you relied on getting a new ID each time, the `perflocale/jobs/deduplicate_admission` filter restores the old behaviour.

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

Full release notes: https://perflocale.com/changelog/

== Upgrade Notice ==

= 1.0.1 =
Security release. Fixes capability bypasses that let the Translator role publish, trash or destroy content, closes a machine-translation quota bypass, stops translated Contact Form 7 forms exposing mail settings, and fixes a WooCommerce race that lost stock when several languages sold at once.

= 1.0.0 =
Initial release of PerfLocale.
