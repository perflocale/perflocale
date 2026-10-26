<?php
/**
 * Loads string translations from generated .l10n.php files via gettext filter.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves string translations from generated .l10n.php files through the gettext filters (files mode).
 */
final class TranslationFileLoader {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * Translations loaded from file: [hash => translated_text].
	 *
	 * @var array<string, string>
	 */
	private array $translations = [];

	/**
	 * Whether translations have been loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Whether a loaded domain needs the gettext filters; option strings are read through get_translation().
	 *
	 * @var bool
	 */
	private bool $gettext_domains = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 */
	public function __construct( LanguageRouter $router ) {
		$this->router = $router;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Deferred until the language is detected.
		add_action( 'perflocale/language/detected', [ $this, 'activate' ] );
	}

	/**
	 * Activate gettext filters and eagerly load translation files.
	 *
	 * @return void
	 */
	public function activate(): void {
		// The default language serves source strings: skip loading entirely.
		$current = $this->router->get_current_language();
		$default = $this->router->get_default_language();

		if (
			$current !== null
			&& $default !== null
			&& isset( $current->slug, $default->slug )
			&& $current->slug === $default->slug
		) {
			return;
		}

		// Load first: gettext fires on every __() call, so register the filters only when they can serve something.
		$this->load_translations();

		if ( empty( $this->translations ) || ! $this->gettext_domains ) {
			return;
		}

		add_filter( 'gettext', [ $this, 'translate_string' ], 10, 3 );
		add_filter( 'gettext_with_context', [ $this, 'translate_string_with_context' ], 10, 4 );
		add_filter( 'ngettext', [ $this, 'translate_plural_string' ], 10, 5 );
		add_filter( 'ngettext_with_context', [ $this, 'translate_plural_string_with_context' ], 10, 6 );
	}

	/**
	 * Filter gettext to serve translated strings from file cache; mixed because plugins may call __( null ).
	 *
	 * @param mixed $translation Translated text (from .mo files).
	 * @param mixed $text Original text.
	 * @param mixed $domain Text domain.
	 * @return string Translated text.
	 */
	public function translate_string( mixed $translation, mixed $text, mixed $domain ): string {
		if ( ! is_string( $text ) || $text === '' ) {
			return (string) ( $translation ?? '' );
		}

		return $this->get_translation( $text, (string) $domain, '' ) ?? (string) $translation;
	}

	/**
	 * Filter gettext_with_context.
	 *
	 * @param mixed $translation Translated text.
	 * @param mixed $text Original text.
	 * @param mixed $context Translation context.
	 * @param mixed $domain Text domain.
	 * @return string Translated text.
	 */
	public function translate_string_with_context( mixed $translation, mixed $text, mixed $context, mixed $domain ): string {
		if ( ! is_string( $text ) || $text === '' ) {
			return (string) ( $translation ?? '' );
		}

		$hit = $this->get_translation( $text, (string) $domain, (string) ( $context ?? '' ) );

		if ( $hit === null ) {
			return (string) $translation;
		}

		// A context equal to a synthetic plural context hits a NUL-joined plural row: return its first form.
		$nul = strpos( $hit, "\0" );

		return $nul === false ? $hit : substr( $hit, 0, $nul );
	}

	/**
	 * Filter ngettext to serve translated plural strings from file cache.
	 *
	 * @param mixed $translation Translated text.
	 * @param mixed $single Singular original text.
	 * @param mixed $plural Plural original text.
	 * @param mixed $number Count.
	 * @param mixed $domain Text domain.
	 * @return string Translated text.
	 */
	public function translate_plural_string( mixed $translation, mixed $single, mixed $plural, mixed $number, mixed $domain ): string {
		$form_index = $this->plural_form_index( (int) $number );

		if ( $form_index === 0 ) {
			$original = $single;
			if ( ! is_string( $original ) || $original === '' ) {
				return (string) ( $translation ?? '' );
			}
			return $this->get_translation( $original, (string) $domain, 'singular' ) ?? (string) $translation;
		}

		$original = $plural;
		if ( ! is_string( $original ) || $original === '' ) {
			return (string) ( $translation ?? '' );
		}

		return $this->pick_plural_form( $original, (string) $domain, 'plural', $form_index ) ?? (string) $translation;
	}

	/**
	 * Filter ngettext_with_context to serve translated plural strings.
	 *
	 * @param mixed $translation Translated text.
	 * @param mixed $single Singular original text.
	 * @param mixed $plural Plural original text.
	 * @param mixed $number Count.
	 * @param mixed $context Translation context.
	 * @param mixed $domain Text domain.
	 * @return string Translated text.
	 */
	public function translate_plural_string_with_context( mixed $translation, mixed $single, mixed $plural, mixed $number, mixed $context, mixed $domain ): string {
		$form_index = $this->plural_form_index( (int) $number );
		$base_ctx   = (string) ( $context ?? '' );

		if ( $form_index === 0 ) {
			$original = $single;
			if ( ! is_string( $original ) || $original === '' ) {
				return (string) ( $translation ?? '' );
			}
			$form_context = $base_ctx !== '' ? $base_ctx : 'singular';
			return $this->get_translation( $original, (string) $domain, $form_context ) ?? (string) $translation;
		}

		$original = $plural;
		if ( ! is_string( $original ) || $original === '' ) {
			return (string) ( $translation ?? '' );
		}

		$form_context = $base_ctx !== '' ? $base_ctx . ' (plural)' : 'plural';

		return $this->pick_plural_form( $original, (string) $domain, $form_context, $form_index ) ?? (string) $translation;
	}

	/**
	 * CLDR plural form index for a count in the current language.
	 *
	 * @param int $number The count.
	 * @return int
	 */
	private function plural_form_index( int $number ): int {
		$current = $this->router->get_current_language();
		$locale  = is_object( $current ) && ! empty( $current->locale )
			? (string) $current->locale
			: ( is_object( $current ) && ! empty( $current->slug ) ? (string) $current->slug : 'en' );

		return PluralRules::form_index( $locale, $number );
	}

	/**
	 * Resolve a plural translation for form index >=1 from the NUL-joined forms the generator writes.
	 *
	 * @param string $original     Plural original text.
	 * @param string $domain       Text domain.
	 * @param string $form_context 'plural' or '<ctx> (plural)'.
	 * @param int    $form_index   Requested index (>=1).
	 * @return string|null
	 */
	private function pick_plural_form( string $original, string $domain, string $form_context, int $form_index ): ?string {
		$value = $this->get_translation( $original, $domain, $form_context );

		if ( $value === null ) {
			return null;
		}

		if ( strpos( $value, "\0" ) === false ) {
			// Two-form language: the value is form 1.
			return $value;
		}

		$forms = explode( "\0", $value );
		$pick  = $forms[ $form_index - 1 ] ?? '';

		// A missing or empty form falls back to form 1, as gettext does.
		return ( is_string( $pick ) && $pick !== '' ) ? $pick : $forms[0];
	}

	/**
	 * Look up a translation from the file-based cache, loading it on first use.
	 *
	 * @param string $text Original text.
	 * @param string $domain Text domain.
	 * @param string $context Context.
	 * @return string|null Translated text or null if not found.
	 */
	public function get_translation( string $text, string $domain, string $context ): ?string {
		if ( ! $this->loaded ) {
			$this->load_translations();
		}

		if ( empty( $this->translations ) ) {
			return null;
		}

		return $this->translations[ self::map_key( $domain, $context, $text ) ] ?? null;
	}

	/**
	 * Build the translations-map key: the raw pre-image of StringRepository::compute_hash(), unhashed.
	 *
	 * Combined bundles persist these keys: a format change must bump TranslationFileGenerator::COMBINED_VERSION.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Gettext context ('' when none).
	 * @param string $text    Original string.
	 * @return string
	 */
	public static function map_key( string $domain, string $context, string $text ): string {
		return $domain . '|' . $context . '|' . $text;
	}

	/**
	 * Load all translations from .l10n.php files for the current locale.
	 *
	 * @return void
	 */
	private function load_translations(): void {
		$language = $this->router->get_current_language();

		// Don't mark as loaded until language is actually detected.
		if ( ! $language || empty( $language->locale ) ) {
			return;
		}

		$locale = $language->locale;
		$dir    = $this->get_translations_dir();

		if ( ! is_dir( $dir ) ) {
			// Not marked loaded: the directory may be created later.
			return;
		}

		// Built like the generator's file names, or free-text locales never match.
		$suffix = '-' . sanitize_file_name( $locale ) . '.l10n.php';

		// The generator's manifest (sole writer of the directory) replaces a glob; glob only when it is absent.
		$manifest = get_option( TranslationFileGenerator::MANIFEST_OPTION );

		// Equals TranslationFileGenerator::combined_basename( $locale ).
		$combined_name = TranslationFileGenerator::COMBINED_PREFIX . $suffix;

		// Combined bundle: trusted only when the manifest lists it (written last, after a stale bundle is deleted).
		// See docs-ai/11-code-rationale.md, "TranslationFileLoader: combined bundle trust".
		if ( is_array( $manifest ) && in_array( $combined_name, $manifest, true ) ) {
			$combined  = $dir . '/' . $combined_name;
			$dir_real  = realpath( $dir );
			$file_real = realpath( $combined );

			if (
				$file_real !== false
				&& $dir_real !== false
				&& strncmp( $file_real, $dir_real . DIRECTORY_SEPARATOR, strlen( $dir_real ) + 1 ) === 0
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
				&& is_readable( $combined )
			) {
				$data = include $combined; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

				if (
					is_array( $data )
					&& TranslationFileGenerator::COMBINED_VERSION === (int) ( $data['v'] ?? 0 )
					&& isset( $data['map'] )
					&& is_array( $data['map'] )
					&& $data['map'] !== []
				) {
					$this->loaded       = true;
					$this->translations = $data['map'];

					// The manifest names the bundle's domains, so no walk over the map.
					foreach ( $manifest as $name ) {
						if ( is_string( $name ) && $name !== $combined_name && str_ends_with( $name, $suffix )
							&& self::is_gettext_domain( substr( $name, 0, -strlen( $suffix ) ) )
						) {
							$this->gettext_domains = true;
							break;
						}
					}

					return;
				}
			}
		}

		if ( is_array( $manifest ) && $manifest !== [] ) {
			$files = [];

			foreach ( $manifest as $name ) {
				if ( is_string( $name ) && str_ends_with( $name, $suffix ) ) {
					$files[] = $dir . '/' . $name;
				}
			}
		} else {
			$files = glob( $dir . '/*' . $suffix );
		}

		// Safety limit: prevent excessive file I/O from misconfigured directories.
		if ( is_array( $files ) && count( $files ) > 200 ) {
			$files = array_slice( $files, 0, 200 );
		}

		if ( ! is_array( $files ) || empty( $files ) ) {
			// While generation runs, stay unloaded so the next request retries.
			if ( ! get_transient( 'perflocale_strings_regenerating' ) ) {
				$this->loaded = true;
			}

			return;
		}

		$this->loaded = true;

		// An include must never follow a symlink out of this directory.
		$dir_real = realpath( $dir );

		foreach ( $files as $file ) {
			$basename = basename( $file );

			// The untrusted combined bundle shares the locale suffix but is not a per-domain file.
			if ( $basename === $combined_name ) {
				continue;
			}

			$domain = substr( $basename, 0, -strlen( $suffix ) );

			if ( $domain === '' ) {
				continue;
			}

			$file_real = realpath( $file );

			if ( $file_real === false || $dir_real === false ) {
				continue; // Broken symlink or missing parent - skip silently.
			}

			// The trailing separator stops `/foo` matching `/foobar`.
			if ( strncmp( $file_real, $dir_real . DIRECTORY_SEPARATOR, strlen( $dir_real ) + 1 ) !== 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$data = include $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

			if ( ! is_array( $data ) || empty( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
				continue;
			}

			$gettext_domain = self::is_gettext_domain( $domain );

			foreach ( $data['messages'] as $original => $translated ) {
				if ( ! is_string( $translated ) || $translated === '' ) {
					continue;
				}

				// Key format context\x04original; a numeric key arrives as int and is stringified, never skipped.
				$context = '';

				if ( is_string( $original ) && str_contains( $original, "\x04" ) ) {
					[ $context, $original ] = explode( "\x04", $original, 2 );
				}

				$this->translations[ self::map_key( $domain, $context, strval( $original ) ) ] = $translated;

				if ( $gettext_domain ) {
					$this->gettext_domains = true;
				}
			}
		}
	}

	/**
	 * Whether a domain's strings are served through the gettext filters.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	private static function is_gettext_domain( string $domain ): bool {
		return $domain !== \PerfLocale\Frontend\OptionStrings::DOMAIN;
	}

	/**
	 * Get the translations directory path.
	 *
	 * @return string
	 */
	private function get_translations_dir(): string {
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . '/perflocale/translations';
	}

	/**
	 * Drop the per-blog memo on `switch_blog` (Bootstrap's reset registry): the service is a shared singleton.
	 *
	 * @return void
	 */
	public function reset_for_blog_switch(): void {
		$this->translations    = [];
		$this->loaded          = false;
		$this->gettext_domains = false;
	}

}
