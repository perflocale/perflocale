<?php
/**
 * Bundled translations of the plugin's front-end strings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets WordPress load the catalogs the plugin ships in `languages/`, and
 * list them without reading the folder.
 *
 * WordPress loads a text domain just in time, when the first string of the
 * domain is translated. It looks for `{domain}-{locale}.l10n.php` or `.mo` in
 * WP_LANG_DIR/plugins (the language packs of translate.wordpress.org), then
 * in WP_LANG_DIR/themes, then in the plugin's own folder when it knows that
 * folder, and passes the folder it found, or false, through the
 * `lang_dir_for_domain` filter (WordPress 6.6+). This class answers only a
 * false for its domain and one of its locales, with its own folder. So an
 * installed language pack is loaded instead of the bundled file, as a whole,
 * and a locale without a bundled file stays in English.
 *
 * WordPress 6.8+ already knows the folder of a plugin activated on the site
 * (its Domain Path header), so there `lang_dir_for_domain` receives that
 * folder and changes nothing. It is what loads the bundled file for a
 * network-activated plugin and on WordPress 6.6 and 6.7. Before 6.6 the
 * filter does not exist, and the strings stay in English.
 *
 * To look in a folder it knows, WordPress lists the folder's translation
 * files, with two glob() calls that run in every request without a
 * persistent object cache. That happens for the plugin's folder on
 * WordPress 6.8+ when the plugin is activated on the site, never for a
 * network activation and never before 6.8. The `pre_get_language_files_from_path`
 * filter (WordPress 6.5+) answers that listing for the plugin's folder with
 * the catalog file names built from LOCALES, in the spelling of the path
 * WordPress passes: the folder as the plugin reads it, or as WordPress
 * builds it from WP_PLUGIN_DIR and the plugin's basename (a symlinked
 * plugin, Windows paths). Any other folder, or a list another callback
 * already returned, is passed through unchanged.
 *
 * Neither filter reads anything: the folder of a listed locale is returned
 * as it is, the file names come from LOCALES, and WordPress opens a file
 * only when a string of the domain is translated in that locale.
 *
 * @internal Not part of the public API.
 */
final class BundledTranslations {

	/**
	 * Locales with a bundled catalog in `languages/`. English locales are
	 * not listed: the source strings are their text.
	 *
	 * @var list<string>
	 */
	public const LOCALES = [ 'ar', 'bg_BG', 'de_DE', 'es_ES', 'fi', 'fr_FR', 'hi_IN', 'it_IT', 'ja', 'nl_NL', 'pt_BR', 'ru_RU', 'sv_SE' ];

	/**
	 * Text domain of the catalogs.
	 *
	 * @var string
	 */
	private string $domain;

	/**
	 * Folder of the catalogs, with a trailing slash.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Every spelling of the catalog folder, with a trailing slash, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $dirs;

	/**
	 * Locales with a catalog in the folder, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $locales;

	/**
	 * Constructor.
	 *
	 * @param string   $domain  Text domain of the catalogs.
	 * @param string   $dir     Folder of the catalogs.
	 * @param string[] $locales Locales with a catalog in the folder.
	 * @param string[] $aliases Other spellings of the same folder.
	 */
	public function __construct( string $domain, string $dir, array $locales, array $aliases = [] ) {
		$this->domain  = $domain;
		$this->dir     = rtrim( $dir, '/' ) . '/';
		$this->locales = array_fill_keys( $locales, true );
		$this->dirs    = [ $this->dir => true ];

		foreach ( $aliases as $alias ) {
			if ( '' !== $alias ) {
				$this->dirs[ rtrim( $alias, '/' ) . '/' ] = true;
			}
		}
	}

	/**
	 * Register the plugin's own catalogs: the `perflocale` domain in
	 * `languages/`, for the locales in LOCALES.
	 *
	 * @return void
	 */
	public static function register_default(): void {
		$plugin_dir = defined( 'PERFLOCALE_DIR' ) ? PERFLOCALE_DIR : '';

		if ( ! is_string( $plugin_dir ) || '' === $plugin_dir ) {
			return;
		}

		// The folder as WordPress spells it when it lists a plugin's Domain
		// Path: WP_PLUGIN_DIR plus the plugin's basename, which follows a
		// symlinked plugin folder (wp_register_plugin_realpath()).
		$aliases = [];

		$plugin_file = defined( 'PERFLOCALE_FILE' ) ? PERFLOCALE_FILE : '';

		if ( defined( 'WP_PLUGIN_DIR' ) && is_string( $plugin_file ) && '' !== $plugin_file && function_exists( 'plugin_basename' ) ) {
			$aliases[] = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( $plugin_file ) ) . '/languages';
		}

		( new self( 'perflocale', $plugin_dir . 'languages', self::LOCALES, $aliases ) )->register();
	}

	/**
	 * Hook the filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'lang_dir_for_domain', [ $this, 'filter_lang_dir' ], 10, 3 );
		add_filter( 'pre_get_language_files_from_path', [ $this, 'filter_language_files' ], 10, 2 );
	}

	/**
	 * The catalog files of the folder, named from LOCALES under the path as
	 * WordPress passed it, when WordPress lists the folder's translation
	 * files (WordPress 6.5+; for a plugin activated on the site, WordPress
	 * 6.8+ lists the Domain Path folder): without a persistent object cache
	 * WordPress otherwise runs two glob() calls on the folder in every
	 * request. The folder matches in any of its spellings (see the
	 * constructor's aliases). Any other folder, or a non-null list from an
	 * earlier callback: unchanged.
	 *
	 * @param mixed $files Files a previous callback returned, or null.
	 * @param mixed $path  Folder WordPress lists, with a trailing slash.
	 * @return mixed
	 */
	public function filter_language_files( mixed $files, mixed $path ): mixed {
		if ( null !== $files || ! is_string( $path ) ) {
			return $files;
		}

		$dir = rtrim( $path, '/' ) . '/';

		if ( ! isset( $this->dirs[ $dir ] ) ) {
			return $files;
		}

		$list = [];

		foreach ( [ '.mo', '.l10n.php' ] as $ext ) {
			foreach ( array_keys( $this->locales ) as $locale ) {
				$list[] = $dir . $this->domain . '-' . $locale . $ext;
			}
		}

		return $list;
	}

	/**
	 * The catalog folder when WordPress found no folder for the domain and a
	 * listed locale; otherwise the path unchanged.
	 *
	 * @param mixed $path   Folder WordPress found for the domain and locale, or false.
	 * @param mixed $domain Text domain.
	 * @param mixed $locale Locale.
	 * @return mixed
	 */
	public function filter_lang_dir( mixed $path, mixed $domain, mixed $locale ): mixed {
		if ( false !== $path || $domain !== $this->domain || ! is_string( $locale ) || ! isset( $this->locales[ $locale ] ) ) {
			return $path;
		}

		return $this->dir;
	}
}
