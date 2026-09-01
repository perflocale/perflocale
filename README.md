# PerfLocale

**Multilingual WordPress without the overhead.** Posts, pages, products, taxonomies, strings and slugs — translated on WordPress core APIs alone, with zero third-party PHP libraries.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/perflocale?label=wordpress.org)](https://wordpress.org/plugins/perflocale/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/perflocale)](https://wordpress.org/plugins/perflocale/)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](LICENSE)

- **Website** — <https://perflocale.com>
- **Documentation** — <https://perflocale.com/docs/>
- **Plugin directory** — <https://wordpress.org/plugins/perflocale/>
- **Support** — <https://wordpress.org/support/plugin/perflocale/>

---

## Try it without installing anything

Opens a throwaway WordPress in your browser, already set up with English, German, French and Italian:

**[▶ Launch the live demo](https://playground.wordpress.net/?blueprint-url=https://perflocale.com/blueprint.json)**

Italian is deliberately missing on one page, so you can see how the dashboard reports gaps and how the missing-translation fallback behaves.

## What it does

- **Content translation** for posts, pages, any custom post type, categories, tags and custom taxonomies, with per-language URL slugs.
- **URL routing** in subdirectory (`/de/`), subdomain, per-language domain, or query-parameter form.
- **String translation** for gettext strings from any plugin or theme, with PO import/export.
- **SEO** — hreflang tags, sitemap alternates, canonical handling, and integration with the major SEO plugins.
- **WooCommerce** — products, attributes, cart and checkout, localized order emails, and opt-in per-language currencies.
- **Machine translation** via DeepL, Google, Microsoft, LibreTranslate, or your own endpoint. Bring your own key; nothing is bundled.
- **Migration** from WPML, Polylang or TranslatePress. Your existing plugin's data is read, never modified, and every import is safe to re-run.
- **194 bundled language definitions**, including regional variants and 15 right-to-left locales.

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer |
| PHP | 8.1 or newer |
| Database | MySQL 5.6+ / MariaDB 10.1+ |
| Multisite | Supported, per-blog |

## Installation

Most people should install from the plugin directory:

```
WP Admin → Plugins → Add New → search "PerfLocale"
```

Or with WP-CLI:

```bash
wp plugin install perflocale --activate
```

To run this repository directly, clone it into your plugins directory — the plugin lives at the repository root, so there is no build step:

```bash
cd wp-content/plugins
git clone https://github.com/perflocale/perflocale.git perflocale
wp plugin activate perflocale
```

## Quick start

```bash
wp perflocale languages add en_US --default
wp perflocale languages add de_DE
wp perflocale languages list
```

Then open **PerfLocale → Dashboard** for per-language progress across every post type.

## For developers

- **200+ action and filter hooks**, documented at <https://perflocale.com/docs/hooks/>.
- **REST API** under `perflocale/v1`, and a full **WP-CLI** command set under `wp perflocale`.
- **Addon system** with a documented toolkit — see <https://perflocale.com/docs/addon-system/>.
- Every internal primitive is marked `@api` and is semver-stable across `1.x`.

## Relationship to the WordPress.org release

This repository mirrors what is published to the plugin directory. Each tagged release here
corresponds to the same version in the WordPress.org SVN repository, and the plugin files are
byte-identical, so either can be diffed against the other.

Directory-only extras — banners, screenshots and the Playground blueprint — are **not** in this
repository. They live in the SVN `assets/` folder, which is never part of what users download.

## Contributing

Bug reports and feature requests are welcome in [Issues](https://github.com/perflocale/perflocale/issues).
Support questions are better placed in the
[WordPress.org support forum](https://wordpress.org/support/plugin/perflocale/), where the answer
also helps the next person who searches for it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
