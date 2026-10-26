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
 * Reads local files and core's last update checks only (no request, no write): `not_offered` means the last
 * check offered none. See docs-ai/11-code-rationale.md, "LanguagePacks: sources and states".
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
		$offered     = self::offered( [ $theme_data, $plugin_data ] );
		$installed   = array_fill_keys( get_available_languages(), true );
		$items       = self::items( $theme_data, $plugin_data );

		// Read only for a locale whose WordPress pack is not installed.
		$available = null;

		if ( [] !== array_diff_key( $wanted, $installed ) ) {
			$offered  += self::offered( [ self::update_data( 'update_core', false ) ] );
			$available = get_site_transient( 'available_translations' );
		}

		$files = [
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
	 * What Site Health and the Languages screen list for one locale: its WordPress pack state and the theme and
	 * plugin packs offered or not offered (unchecked ones wait on the WordPress pack). `listed`: anything is.
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
	 * Dashboard > Updates, where WordPress installs offered packs (the network admin's on multisite).
	 *
	 * @return string
	 */
	public static function updates_url(): string {
		return is_multisite() ? network_admin_url( 'update-core.php' ) : admin_url( 'update-core.php' );
	}

	/**
	 * Core's update data of one kind (check time, offered packs, listed entries), or null when there is none.
	 *
	 * Core writes `last_checked` before the request: only a `no_update` list proves a completed check.
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
	 * Display names, read only for the items a report lists (offered or not offered).
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
