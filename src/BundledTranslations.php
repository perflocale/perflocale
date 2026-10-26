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
 * Lets WordPress load and list the catalogs shipped in `languages/` without reading the folder.
 *
 * An installed language pack always wins: see docs-ai/11-code-rationale.md, "BundledTranslations: lookup order".
 *
 * @internal Not part of the public API.
 */
final class BundledTranslations {

	/**
	 * Locales with a bundled catalog in `languages/` (English is the source text).
	 *
	 * @var list<string>
	 */
	public const LOCALES = [ 'ar', 'bg_BG', 'cs_CZ', 'de_DE', 'es_ES', 'fa_IR', 'fi', 'fr_FR', 'hi_IN', 'id_ID', 'it_IT', 'ja', 'ko_KR', 'nl_NL', 'pl_PL', 'pt_BR', 'pt_PT', 'ru_RU', 'sv_SE', 'tr_TR', 'uk', 'vi', 'zh_CN', 'zh_TW' ];

	/**
	 * Bundled locale for a locale with no catalog or language pack; a key ending in `_` covers the language.
	 *
	 * No `pt_` key on purpose: no locale reads pt_BR's catalog in place of its own.
	 *
	 * @var array<string, string>
	 */
	public const FALLBACKS = [
		'nl_BE'          => 'nl_NL',
		'nl_NL_formal'   => 'nl_NL',
		'de_DE_formal'   => 'de_DE',
		'de_AT'          => 'de_DE',
		'de_CH'          => 'de_DE',
		'de_CH_informal' => 'de_DE',
		'fr_'            => 'fr_FR',
		'es_'            => 'es_ES',
		'pt_PT_ao90'     => 'pt_PT',
		'pt_AO'          => 'pt_PT',
	];

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
	 * Base locale for a locale with no catalog in the folder, by locale or language prefix.
	 *
	 * @var array<string, string>
	 */
	private array $fallbacks;

	/**
	 * Catalog to load in place of a variant's file name, by the .mo path WordPress builds.
	 *
	 * @var array<string, string>
	 */
	private array $mofiles = [];

	/**
	 * Constructor.
	 *
	 * @param string                $domain    Text domain of the catalogs.
	 * @param string                $dir       Folder of the catalogs.
	 * @param string[]              $locales   Locales with a catalog in the folder.
	 * @param string[]              $aliases   Other spellings of the same folder.
	 * @param array<string, string> $fallbacks Base locale by locale or language prefix (see FALLBACKS).
	 */
	public function __construct( string $domain, string $dir, array $locales, array $aliases = [], array $fallbacks = [] ) {
		$this->domain    = $domain;
		$this->dir       = rtrim( $dir, '/' ) . '/';
		$this->locales   = array_fill_keys( $locales, true );
		$this->dirs      = [ $this->dir => true ];
		$this->fallbacks = $fallbacks;

		foreach ( $aliases as $alias ) {
			if ( '' !== $alias ) {
				$this->dirs[ rtrim( $alias, '/' ) . '/' ] = true;
			}
		}
	}

	/**
	 * Register the plugin's own catalogs: the `perflocale` domain in `languages/`.
	 *
	 * @return void
	 */
	public static function register_default(): void {
		$plugin_dir = defined( 'PERFLOCALE_DIR' ) ? PERFLOCALE_DIR : '';

		if ( ! is_string( $plugin_dir ) || '' === $plugin_dir ) {
			return;
		}

		// WordPress spells the Domain Path folder from WP_PLUGIN_DIR and the basename (symlinks differ).
		$aliases = [];

		$plugin_file = defined( 'PERFLOCALE_FILE' ) ? PERFLOCALE_FILE : '';

		if ( defined( 'WP_PLUGIN_DIR' ) && is_string( $plugin_file ) && '' !== $plugin_file && function_exists( 'plugin_basename' ) ) {
			$aliases[] = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( $plugin_file ) ) . '/languages';
		}

		( new self( 'perflocale', $plugin_dir . 'languages', self::LOCALES, $aliases, self::FALLBACKS ) )->register();
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
	 * The folder's catalog file names from LOCALES, in place of WordPress's listing of it.
	 *
	 * Spares two glob() calls per request without a persistent object cache; other folders pass through.
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
	 * The catalog folder when WordPress found none for a listed locale or a FALLBACKS variant.
	 *
	 * A variant's base catalog is queued for filter_mofile(); a folder found elsewhere (a language pack) is kept.
	 *
	 * @param mixed $path   Folder WordPress found for the domain and locale, or false.
	 * @param mixed $domain Text domain.
	 * @param mixed $locale Locale.
	 * @return mixed
	 */
	public function filter_lang_dir( mixed $path, mixed $domain, mixed $locale ): mixed {
		if ( $domain !== $this->domain || ! is_string( $locale ) ) {
			return $path;
		}

		if ( isset( $this->locales[ $locale ] ) ) {
			return false === $path ? $this->dir : $path;
		}

		$base = $this->base_locale( $locale );

		if ( null === $base || ( false !== $path && ( ! is_string( $path ) || ! isset( $this->dirs[ rtrim( $path, '/' ) . '/' ] ) ) ) ) {
			return $path;
		}

		if ( [] === $this->mofiles ) {
			add_filter( 'load_textdomain_mofile', [ $this, 'filter_mofile' ], 10, 2 );
		}

		$this->mofiles[ $this->dir . $this->domain . '-' . $locale . '.mo' ] = $this->dir . $this->domain . '-' . $base . '.mo';

		return $this->dir;
	}

	/**
	 * The base locale's catalog in place of a queued variant's file name (also yields the .l10n.php name).
	 *
	 * @param mixed $mofile Path of the .mo file WordPress is about to load.
	 * @param mixed $domain Text domain.
	 * @return mixed
	 */
	public function filter_mofile( mixed $mofile, mixed $domain ): mixed {
		if ( $domain !== $this->domain || ! is_string( $mofile ) ) {
			return $mofile;
		}

		return $this->mofiles[ $mofile ] ?? $mofile;
	}

	/**
	 * The bundled locale FALLBACKS names for a locale, or null.
	 *
	 * @param string $locale Locale with no catalog in the folder.
	 * @return string|null
	 */
	private function base_locale( string $locale ): ?string {
		$base = $this->fallbacks[ $locale ] ?? null;

		if ( null === $base ) {
			$cut = strpos( $locale, '_' );

			if ( false === $cut ) {
				return null;
			}

			$base = $this->fallbacks[ substr( $locale, 0, $cut + 1 ) ] ?? null;
		}

		return null !== $base && isset( $this->locales[ $base ] ) ? $base : null;
	}
}
