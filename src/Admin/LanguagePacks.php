<?php
/**
 * Language-pack status of the site's languages.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which WordPress.org language packs are installed for a set of locales.
 *
 * WHAT IT READS (no network request, no write)
 *   - The translation files in WP_LANG_DIR, WP_LANG_DIR/themes and
 *     WP_LANG_DIR/plugins, through the listing core's own translation loader
 *     uses: get_available_languages() for WordPress itself, and
 *     WP_Textdomain_Registry::get_language_files_from_path() (WordPress 6.5+,
 *     cached by core for an hour) or a glob for themes and plugins. The theme
 *     and plugin listings are read only when an item needs them.
 *   - The data of core's last update checks: the site transients
 *     `update_core`, `update_themes` and `update_plugins`. They say which
 *     themes and plugins WordPress.org serves (their `response` and
 *     `no_update` entries) and which language packs it offered (their
 *     `translations` entries, the list Dashboard > Updates installs).
 *   - The site transient `available_translations`, the list of WordPress
 *     translations core caches for three hours when its language selector is
 *     opened. When it is not cached, nothing here asks WordPress.org for it.
 *   - The headers of the themes and plugins a report lists as offered or not
 *     offered, for their names (not read when the caller needs no names).
 *   - The active theme and its parent, and the `active_plugins` option (with
 *     `active_sitewide_plugins` on multisite).
 *
 * STATES, per locale (en_US is the language WordPress is written in and is
 * never checked):
 *   - WordPress itself: `installed` (the locale is in
 *     get_available_languages(), so {locale}.mo or {locale}.l10n.php is in
 *     WP_LANG_DIR), `offered` (core's update data offers the pack),
 *     `not_available` (`available_translations` is cached and has no such
 *     locale) or `missing`.
 *   - The active theme, its parent theme and every plugin active on this
 *     site (network-activated ones included) that core's update data lists:
 *     `installed` ({slug}-{locale}.mo or .l10n.php in WP_LANG_DIR/themes or
 *     WP_LANG_DIR/plugins; {slug} is the theme's directory, or the plugin's
 *     slug in the update data, else its folder), `offered`, `not_offered`
 *     (the last update check offered no pack) or `unchecked` (the WordPress
 *     pack for the locale is not installed, and core's update checks ask only
 *     for installed locales). Themes and plugins the update data does not
 *     list get no language packs from WordPress.org and are left out. Without
 *     update data for themes or plugins (no check of that kind has
 *     completed: the transient has no `no_update` list), none of that kind
 *     is checked.
 *
 * Whether WordPress.org has a theme or plugin pack for a locale is not
 * knowable without asking it: `not_offered` means the last update check
 * offered none, which is also the answer when the locale was installed after
 * that check.
 *
 * Site Health's language-pack test and the hint in a language's row on the
 * Languages screen both read a locale through report() and findings(): Site
 * Health lists every finding, the hint the packs among them that can be
 * installed.
 *
 * @phpstan-type UpdateData array{last_checked: int, translations: array<mixed>, listed: array<string, mixed>}
 * @phpstan-type Item array{type: string, id: string, slug: string, name: string, state: string}
 * @phpstan-type Report array{locales: array<string, array{core: string, items: list<Item>}>, checked: array{theme: bool, plugin: bool}, last_checked: int}
 */
final class LanguagePacks {

	public const INSTALLED     = 'installed';
	public const OFFERED       = 'offered';
	public const MISSING       = 'missing';
	public const NOT_AVAILABLE = 'not_available';
	public const NOT_OFFERED   = 'not_offered';
	public const UNCHECKED     = 'unchecked';

	/**
	 * Language-pack status of the given locales on the current site.
	 *
	 * @param array<int|string, mixed> $locales WordPress locales.
	 * @param bool                     $names   Read the display names of the themes and plugins listed as offered or not offered.
	 * @return Report
	 */
	public static function report( array $locales, bool $names = true ): array {
		$wanted = [];

		foreach ( $locales as $locale ) {
			if ( is_string( $locale ) && '' !== $locale && 'en_US' !== $locale ) {
				$wanted[ $locale ] = true;
			}
		}

		$report = [
			'locales'      => [],
			'checked'      => [
				'theme'  => false,
				'plugin' => false,
			],
			'last_checked' => 0,
		];

		if ( [] === $wanted ) {
			return $report;
		}

		$theme_data  = self::update_data( 'update_themes', true );
		$plugin_data = self::update_data( 'update_plugins', true );
		$offered     = self::offered( [ self::update_data( 'update_core', false ), $theme_data, $plugin_data ] );
		$available   = get_site_transient( 'available_translations' );
		$installed   = array_fill_keys( get_available_languages(), true );
		$items       = self::items( $theme_data, $plugin_data );
		$files       = [
			'theme'  => null,
			'plugin' => null,
		];

		$report['checked'] = [
			'theme'  => null !== $theme_data,
			'plugin' => null !== $plugin_data,
		];

		foreach ( [ $theme_data, $plugin_data ] as $data ) {
			if ( null !== $data ) {
				$report['last_checked'] = 0 === $report['last_checked'] ? $data['last_checked'] : min( $report['last_checked'], $data['last_checked'] );
			}
		}

		foreach ( array_keys( $wanted ) as $locale ) {
			$locale = (string) $locale;

			if ( isset( $installed[ $locale ] ) ) {
				$core = self::INSTALLED;
			} elseif ( isset( $offered[ 'core||' . $locale ] ) ) {
				$core = self::OFFERED;
			} elseif ( is_array( $available ) && ! isset( $available[ $locale ] ) ) {
				$core = self::NOT_AVAILABLE;
			} else {
				$core = self::MISSING;
			}

			$states = [];

			foreach ( $items as $item ) {
				$type = $item['type'];

				if ( null === $files[ $type ] ) {
					$files[ $type ] = self::files( WP_LANG_DIR . ( 'theme' === $type ? '/themes' : '/plugins' ) );
				}

				$base = $item['slug'] . '-' . $locale;

				if ( isset( $files[ $type ][ $base . '.mo' ] ) || isset( $files[ $type ][ $base . '.l10n.php' ] ) ) {
					$state = self::INSTALLED;
				} elseif ( isset( $offered[ $type . '|' . $item['slug'] . '|' . $locale ] ) ) {
					$state = self::OFFERED;
				} elseif ( self::INSTALLED === $core ) {
					$state = self::NOT_OFFERED;
				} else {
					$state = self::UNCHECKED;
				}

				$item['state'] = $state;
				$states[]      = $item;
			}

			$report['locales'][ $locale ] = [
				'core'  => $core,
				'items' => $states,
			];
		}

		return $names ? self::with_names( $report ) : $report;
	}

	/**
	 * What a report lists for one locale. Site Health's language-pack test
	 * and the Languages screen both state a locale from this: the state of
	 * its WordPress pack, and its theme and plugin packs that are offered or
	 * not offered. Installed theme and plugin packs are not listed, and
	 * neither are unchecked ones (they wait on the WordPress pack, which is
	 * then listed as not installed). `listed` is true when anything is.
	 *
	 * @param array $row One locale of a report.
	 * @return array{core: string, offered: list<Item>, not_offered: list<Item>, listed: bool}
	 * @phpstan-param array{core: string, items: list<Item>} $row
	 */
	public static function findings( array $row ): array {
		$offered     = [];
		$not_offered = [];

		foreach ( $row['items'] as $item ) {
			if ( self::OFFERED === $item['state'] ) {
				$offered[] = $item;
			} elseif ( self::NOT_OFFERED === $item['state'] ) {
				$not_offered[] = $item;
			}
		}

		return [
			'core'        => $row['core'],
			'offered'     => $offered,
			'not_offered' => $not_offered,
			'listed'      => self::INSTALLED !== $row['core'] || [] !== $offered || [] !== $not_offered,
		];
	}

	/**
	 * Date and time of a report's last update check, in the site's formats.
	 *
	 * @param int $timestamp Unix time of the check.
	 * @return string
	 */
	public static function checked_at( int $timestamp ): string {
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		$text        = wp_date( ( is_string( $date_format ) ? $date_format : 'Y-m-d' ) . ' ' . ( is_string( $time_format ) ? $time_format : 'H:i' ), $timestamp );

		return false === $text ? '' : $text;
	}

	/**
	 * Dashboard > Updates, where WordPress installs the language packs its
	 * update checks offer (the network admin's screen on multisite).
	 *
	 * @return string
	 */
	public static function updates_url(): string {
		return is_multisite() ? network_admin_url( 'update-core.php' ) : admin_url( 'update-core.php' );
	}

	/**
	 * Core's update data of one kind, or null when there is none: the time of
	 * the check, the language packs it offered, and its `response` and
	 * `no_update` entries by key.
	 *
	 * Core stores `last_checked` before it asks WordPress.org, and the
	 * `response`, `no_update` and `translations` lists of a theme or plugin
	 * check only when the answer arrives. A site whose check of that kind
	 * never completed (WordPress.org blocked or unreachable, or the request
	 * refused) has a transient without `no_update`, which is no update data
	 * here. A failed check after a completed one keeps the earlier lists.
	 * `update_core` has no `no_update` list and is read only for the
	 * WordPress packs it offers.
	 *
	 * @param string $name      Site transient name.
	 * @param bool   $completed Require the `no_update` list of a completed theme or plugin check.
	 * @return UpdateData|null
	 */
	private static function update_data( string $name, bool $completed ): ?array {
		$data = get_site_transient( $name );

		if ( ! is_object( $data ) ) {
			return null;
		}

		$vars    = get_object_vars( $data );
		$checked = $vars['last_checked'] ?? null;

		if ( ! is_numeric( $checked ) || (int) $checked < 1 ) {
			return null;
		}

		if ( $completed && ! ( isset( $vars['no_update'] ) && is_array( $vars['no_update'] ) ) ) {
			return null;
		}

		$listed = [];

		foreach ( [ 'no_update', 'response' ] as $part ) {
			if ( isset( $vars[ $part ] ) && is_array( $vars[ $part ] ) ) {
				foreach ( $vars[ $part ] as $key => $entry ) {
					$listed[ (string) $key ] = $entry;
				}
			}
		}

		return [
			'last_checked' => (int) $checked,
			'translations' => isset( $vars['translations'] ) && is_array( $vars['translations'] ) ? $vars['translations'] : [],
			'listed'       => $listed,
		];
	}

	/**
	 * The language packs core's update data offers, keyed "type|slug|locale"
	 * (WordPress itself as "core||locale").
	 *
	 * @param array<int, UpdateData|null> $sources Update data.
	 * @return array<string, true>
	 */
	private static function offered( array $sources ): array {
		$offered = [];

		foreach ( $sources as $data ) {
			if ( null === $data ) {
				continue;
			}

			foreach ( $data['translations'] as $entry ) {
				$entry = (array) $entry;
				$type  = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';
				$lang  = isset( $entry['language'] ) && is_string( $entry['language'] ) ? $entry['language'] : '';

				if ( '' === $lang ) {
					continue;
				}

				if ( 'core' === $type ) {
					$offered[ 'core||' . $lang ] = true;
				} elseif ( ( 'theme' === $type || 'plugin' === $type ) && isset( $entry['slug'] ) && is_string( $entry['slug'] ) ) {
					$offered[ $type . '|' . $entry['slug'] . '|' . $lang ] = true;
				}
			}
		}

		return $offered;
	}

	/**
	 * The active theme, its parent and the plugins active on this site that
	 * core's update data lists.
	 *
	 * @param UpdateData|null $theme_data  `update_themes` data.
	 * @param UpdateData|null $plugin_data `update_plugins` data.
	 * @return list<array{type: string, id: string, slug: string, name: string}>
	 */
	private static function items( ?array $theme_data, ?array $plugin_data ): array {
		$items = [];

		if ( null !== $theme_data ) {
			$known = $theme_data['listed'];

			foreach ( array_unique( [ get_stylesheet(), get_template() ] ) as $stylesheet ) {
				if ( '' !== $stylesheet && isset( $known[ $stylesheet ] ) ) {
					$items[] = [
						'type' => 'theme',
						'id'   => $stylesheet,
						'slug' => $stylesheet,
						'name' => '',
					];
				}
			}
		}

		if ( null !== $plugin_data ) {
			$known  = $plugin_data['listed'];
			$active = array_values( array_filter( (array) get_option( 'active_plugins', [] ), 'is_string' ) );

			if ( is_multisite() ) {
				$active = array_merge( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) );
			}

			foreach ( array_unique( $active ) as $file ) {
				if ( ! isset( $known[ $file ] ) ) {
					continue;
				}

				$entry = (array) $known[ $file ];
				$slug  = isset( $entry['slug'] ) && is_string( $entry['slug'] ) && '' !== $entry['slug'] ? $entry['slug'] : '';

				if ( '' === $slug ) {
					$dir  = dirname( $file );
					$slug = '.' === $dir ? basename( $file, '.php' ) : $dir;
				}

				$items[] = [
					'type' => 'plugin',
					'id'   => $file,
					'slug' => $slug,
					'name' => '',
				];
			}
		}

		return $items;
	}

	/**
	 * Base names of the .mo and .l10n.php files in a language directory.
	 *
	 * @param string $dir Directory.
	 * @return array<string, true>
	 */
	private static function files( string $dir ): array {
		global $wp_textdomain_registry;

		if ( is_object( $wp_textdomain_registry ) && is_callable( [ $wp_textdomain_registry, 'get_language_files_from_path' ] ) ) {
			$list = $wp_textdomain_registry->get_language_files_from_path( $dir );
		} else {
			$list = array_merge( (array) glob( $dir . '/*.mo' ), (array) glob( $dir . '/*.l10n.php' ) );
		}

		$set = [];

		foreach ( (array) $list as $path ) {
			if ( is_string( $path ) ) {
				$set[ basename( $path ) ] = true;
			}
		}

		return $set;
	}

	/**
	 * Display names for the items a report lists (offered or not offered),
	 * read only for those.
	 *
	 * @param array $report Report.
	 * @return array
	 * @phpstan-param Report $report
	 * @phpstan-return Report
	 */
	private static function with_names( array $report ): array {
		$names = [];

		foreach ( $report['locales'] as $locale => $row ) {
			foreach ( $row['items'] as $i => $item ) {
				if ( self::OFFERED !== $item['state'] && self::NOT_OFFERED !== $item['state'] ) {
					continue;
				}

				$key = $item['type'] . '|' . $item['id'];

				if ( ! isset( $names[ $key ] ) ) {
					$names[ $key ] = self::name( $item['type'], $item['id'], $item['slug'] );
				}

				$report['locales'][ $locale ]['items'][ $i ]['name'] = $names[ $key ];
			}
		}

		return $report;
	}

	/**
	 * Display name of a theme or plugin, its slug when the header has none.
	 *
	 * @param string $type 'theme' or 'plugin'.
	 * @param string $id   Stylesheet or plugin file.
	 * @param string $slug WordPress.org slug.
	 * @return string
	 */
	private static function name( string $type, string $id, string $slug ): string {
		if ( 'theme' === $type ) {
			$name = wp_get_theme( $id )->get( 'Name' );

			return '' !== $name ? $name : $slug;
		}

		$path = WP_PLUGIN_DIR . '/' . $id;

		if ( ! is_file( $path ) ) {
			return $slug;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$name = get_plugin_data( $path, false, false )['Name'];

		return '' !== $name ? $name : $slug;
	}
}
