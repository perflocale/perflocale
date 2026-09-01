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
 * Serves string translations from generated .l10n.php cache files
 * via the gettext filter.
 *
 * Unlike the database mode (StringTranslation), this reads from a single
 * PHP file per locale - zero database queries, OPcache friendly.
 * The gettext filter ensures translations work regardless of whether
 * the theme/plugin uses .mo files, .l10n.php files, or JIT loading.
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
		// Defer until language is detected - avoids wasted filter calls during early WP init.
		add_action( 'perflocale/language/detected', [ $this, 'activate' ] );
	}

	/**
	 * Activate gettext filters and eagerly load translation files.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Fast-path: on the default language WordPress is already serving
		// source strings in that language - no `__()` replacement needed.
		// Skip the entire load pipeline (filesystem scan + file I/O) because
		// the gettext filter has nothing to do. Saves ~200–300µs on every
		// default-language frontend request.
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

		// Load translations FIRST - if there's nothing to serve, skip the
		// gettext filter registration entirely. gettext fires on every
		// `__()` call (easily thousands per page), so avoiding the 4
		// callbacks when the user has no translation files is a real win.
		$this->load_translations();

		if ( empty( $this->translations ) ) {
			return;
		}

		add_filter( 'gettext', [ $this, 'translate_string' ], 10, 3 );
		add_filter( 'gettext_with_context', [ $this, 'translate_string_with_context' ], 10, 4 );
		add_filter( 'ngettext', [ $this, 'translate_plural_string' ], 10, 5 );
		add_filter( 'ngettext_with_context', [ $this, 'translate_plural_string_with_context' ], 10, 6 );
	}

	/**
	 * Filter gettext to serve translated strings from file cache.
	 *
	 * Parameters are typed as mixed because WordPress may pass null
	 * when plugins call __( null ), which triggers PHP 8.1+ deprecation
	 * warnings for null-to-string coercion.
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

		// A user context that happens to collide with a synthetic plural
		// context ('plural' or '<ctx> (plural)') would resolve to the plural
		// row's NUL-joined multi-form value. Return the first form (the primary
		// plural translation) rather than leaking embedded NUL bytes. Costs one
		// strpos only on a found _x()-with-context value; the far hotter
		// no-context translate_string() path can never hit a plural entry.
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
	 * Resolve a plural translation for form index >=1 from the file cache.
	 *
	 * The generator NUL-joins a plural row's forms (form1\0form2\0…); split
	 * and pick the requested index, falling back to form 1 when a specific
	 * form is absent.
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
			// 2-form language / no extra forms — the value IS form 1.
			return $value;
		}

		$forms = explode( "\0", $value );
		$pick  = $forms[ $form_index - 1 ] ?? '';

		// A missing OR empty form falls back to form 1 (gettext behaviour for
		// a partially-translated plural), never an empty string.
		return ( is_string( $pick ) && $pick !== '' ) ? $pick : $forms[0];
	}

	/**
	 * Look up a translation from the file-based cache.
	 *
	 * Lazy-loads translations on the first call from .l10n.php files.
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
	 * Build the translations-map composite key.
	 *
	 * Keying by the raw `domain|context|text` composite (the exact pre-image
	 * StringRepository::compute_hash() would feed to SHA-256, same '|'
	 * separator) keeps identical identity semantics while removing a full
	 * SHA-256 from every __()/_e()/_x()/_n() call — the hottest per-request
	 * path (500–1500 gettext fires per translated page). The in-memory writer
	 * (load_translations), the on-disk writer (TranslationFileGenerator's
	 * combined bundle), and the reader (get_translation) all share this one
	 * helper so the key expressions can never drift. Combined bundles PERSIST
	 * these keys, so any format change must bump
	 * TranslationFileGenerator::COMBINED_VERSION — a stale-format bundle is
	 * then rejected and rebuilt instead of silently missing every lookup.
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
	 * Reads all domain files, builds a hash map for O(1) lookups.
	 * PHP files are cached by OPcache for maximum performance.
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
			// Don't set $loaded - allow retry if the directory is
			// created later (e.g., after translation file generation).
			return;
		}

		// The generator names bundles with sanitize_file_name( $locale ), so
		// the suffix must be built the same way or free-text locales (e.g.
		// containing a space) would never match the files on disk.
		$suffix = '-' . sanitize_file_name( $locale ) . '.l10n.php';

		// Prefer the generator's manifest (an autoloaded, per-blog option
		// listing exactly the .l10n.php basenames it wrote) over a per-request
		// directory glob (~13µs of syscall). The generator is the sole writer
		// of this directory, so the manifest reflects disk by construction; the
		// per-file realpath guard below still skips anything removed
		// out-of-band, so this is glob-equivalent for reads. Fall back to glob
		// when the manifest is absent (first request before the first
		// generation, or a legacy install).
		$manifest = get_option( TranslationFileGenerator::MANIFEST_OPTION );

		// Equals TranslationFileGenerator::combined_basename( $locale ) —
		// derived from $suffix to avoid a second sanitize_file_name() call.
		$combined_name = TranslationFileGenerator::COMBINED_PREFIX . $suffix;

		// Combined-bundle fast path: the generator additionally emits one
		// per-locale file already keyed in map_key() format, so a single
		// include (an OPcache-shared array) replaces the per-domain walk
		// below. That walk pays is_string + explode + concat + hash-insert
		// per message on every non-default-language request and is O(total
		// strings) — the dominant files-mode cost at the scale files mode is
		// chosen for. The bundle is trusted only when the manifest lists it:
		// the generator deletes a stale bundle before touching any per-domain
		// file and writes the manifest last, so listed + present ⟹ current.
		// Any failure (bundle missing mid-regeneration, format-version
		// mismatch after an upgrade) falls through to the per-domain path.
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
			// If file generation is in progress (mode was just switched to
			// "files"), don't lock $loaded=true - allow the next request to
			// retry once files are written.
			if ( ! get_transient( 'perflocale_strings_regenerating' ) ) {
				$this->loaded = true;
			}

			return;
		}

		$this->loaded = true;

		// Resolve the canonical translations directory once. Files whose
		// realpath doesn't live inside this directory are rejected below
		// (defence in depth against symlink escapes - the glob can't
		// otherwise reach outside, but a rogue symlink inside the directory
		// could redirect an `include` at an attacker-controlled PHP file).
		$dir_real = realpath( $dir );

		foreach ( $files as $file ) {
			$basename = basename( $file );

			// The combined bundle shares the locale suffix (so locale-wide
			// cleanup globs catch it) but is not a per-domain file — reaching
			// here means it wasn't trusted above, so don't parse it at all.
			if ( $basename === $combined_name ) {
				continue;
			}

			$domain = substr( $basename, 0, -strlen( $suffix ) );

			if ( $domain === '' ) {
				continue;
			}

			$file_real = realpath( $file );

			if ( $file_real === false || $dir_real === false ) {
				continue; // Broken symlink or missing parent &mdash; skip silently.
			}

			// Strict prefix check: the resolved file must sit inside the
			// resolved translations directory. Trailing separator prevents
			// `/foo` matching `/foobar`.
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

			foreach ( $data['messages'] as $original => $translated ) {
				if ( ! is_string( $translated ) || $translated === '' ) {
					continue;
				}

				// Parse context from key format: context\x04original. A purely
				// numeric source key (e.g. '42') is stored by PHP as an int
				// array key, so guard str_contains() on is_string() and stringify
				// the key below — the read side (get_translation) always passes a
				// string $text, so the write key must stringify to match, never skip.
				$context = '';

				if ( is_string( $original ) && str_contains( $original, "\x04" ) ) {
					[ $context, $original ] = explode( "\x04", $original, 2 );
				}

				$this->translations[ self::map_key( $domain, $context, strval( $original ) ) ] = $translated;
			}
		}
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
	 * Drop the per-blog memo.
	 *
	 * The container registers this class as a shared singleton, but its
	 * translation memo is per-BLOG data. Without a reset on `switch_blog` a
	 * multisite request that switches blogs keeps serving the previous blog's
	 * strings. Hooked in Bootstrap alongside the other switch_blog resets.
	 *
	 * @return void
	 */
	public function reset_for_blog_switch(): void {
		$this->translations = [];
		$this->loaded       = false;
	}

}
