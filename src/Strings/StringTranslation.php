<?php
/**
 * String translation via gettext filter (database mode).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves gettext calls from the plugin's string tables (database mode).
 *
 * Loads on language detection (parse_request), not wp_loaded, which fires before the language is known.
 */
final class StringTranslation {

	/** ACF options pages, widgets and custom storage ids. */
	public const ACF_OPTIONS = 'perflocale-acf-options';

	/** Meta Box settings pages. */
	public const MB_SETTINGS = 'perflocale-mb-settings';

	/** ACF field settings (labels, instructions, choices); ACF calls __() on it. */
	public const ACF_FIELDS = 'perflocale-acf-fields';

	/** Meta Box choice and form labels. */
	public const MB_FIELDS = 'perflocale-mb-fields';

	/** Post type and taxonomy labels. */
	public const LABELS = 'perflocale-labels';

	/**
	 * Domains read only through get_translation() by a bridge; a map of only these attaches no gettext filter.
	 * Literals on purpose (no constant-expression copy per request); StringDomainsTest pins them to the constants
	 * and TranslationFileLoader holds the same set.
	 */
	public const LOOKUP_ONLY = [
		'perflocale-options',
		'perflocale-acf-options',
		'perflocale-mb-settings',
		'perflocale-mb-fields',
		'perflocale-labels',
	];

	/** Lookup-only domains whose rows carry a context prefix; only their rows read context and position. */
	public const POSITIONED = [
		'perflocale-acf-options',
		'perflocale-mb-settings',
		'perflocale-mb-fields',
		'perflocale-labels',
	];

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Reserved $translations key of the extra-plural-forms sub-map; the NUL prefix never collides with a hash.
	 */
	public const EXTRA_FORMS_KEY = "\0pfl_extra_forms";

	/**
	 * Reserved key: '1' when the map has a row the gettext filters serve, '0' when only OptionStrings rows.
	 *
	 * A map without the key (an older cache entry) counts as having gettext rows.
	 */
	public const GETTEXT_ROWS_KEY = "\0pfl_gettext_rows";

	/** Reserved key: lookup-only domains in the map, `[domain => '1']`; absent counts as every domain. */
	public const DOMAINS_KEY = "\0pfl_domains";

	/** Reserved key: per lookup-only domain, its context prefixes `[domain => [prefix => '1']]`; absent = all. */
	public const CONTEXT_PREFIXES_KEY = "\0pfl_context_prefixes";

	/** Reserved key: per-language option-page reference overrides, folded in at build (no front-end option read). */
	public const FIELD_LINKS_KEY = "\0pfl_field_links";

	/** Reserved key: serialized map size in bytes; the build writes none (it never serializes the map). */
	public const MAP_BYTES_KEY = "\0pfl_map_bytes";

	/** Map size above which the Strings page suggests files mode; below Memcached's 1 MB item limit. */
	public const MAP_NOTICE_BYTES = 524288;

	/** Longest composite key the hash memo keeps, so it never holds a second copy of large values. */
	private const MEMO_MAX_KEY_BYTES = 1024;

	/** Most entries of the hash memo; at the bound it starts over. */
	private const MEMO_MAX_ENTRIES = 5000;

	/**
	 * Preloaded translations: [hash => translated_text_string].
	 *
	 * @var array<string, string>
	 */
	private array $translations = [];

	/**
	 * Whether translations have been preloaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Per-request hash cache to avoid recomputing SHA-256 for repeated strings.
	 *
	 * @var array<string, string>
	 */
	private array $hash_cache = [];

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param CacheManager   $cache Cache manager.
	 */
	public function __construct( LanguageRouter $router, CacheManager $cache ) {
		$this->router = $router;
		$this->cache  = $cache;
	}

	/**
	 * A string's identity pre-image: 1.0.7.5's `domain|context|text`, or `domain|context\x04text` when the context holds a bar.
	 * Injective: a domain never holds a bar and a context or text never "\x04" (refused on write), so "\x04" marks the bar
	 * form and the first two bars split the other. $other: the form the tuple does not use (rows still to be moved).
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @param bool   $other   The other form.
	 * @return string
	 */
	public static function identity( string $domain, string $context, string $text, bool $other = false ): string {
		return $domain . '|' . $context . ( str_contains( $context, '|' ) !== $other ? "\x04" : '|' ) . $text;
	}

	/**
	 * The SHA-256 of identity(): the strings table's original_hash.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @param bool   $other   The other form.
	 * @return non-falsy-string 64-character hex hash.
	 */
	public static function identity_hash( string $domain, string $context, string $text, bool $other = false ): string {
		return hash( 'sha256', self::identity( $domain, $context, $text, $other ) );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Register the gettext filters only once the language is known.
		add_action( 'perflocale/language/detected', [ $this, 'activate' ] );
	}

	/**
	 * Activate gettext filters and eagerly preload translations.
	 *
	 * Runs on perflocale/language/detected, so the language is known.
	 *
	 * @return void
	 */
	public function activate(): void {
		// The default language serves source strings: no preload and no filters (as TranslationFileLoader::activate).
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

		// Preload first: without gettext rows the filters are not added, sparing every __() call.
		$this->preload_translations();

		if ( self::should_attach_gettext( $this->has_gettext_rows(), $this->translations !== [] ) ) {
			add_filter( 'gettext', [ $this, 'translate_string' ], 10, 3 );
			add_filter( 'gettext_with_context', [ $this, 'translate_string_with_context' ], 10, 4 );
			add_filter( 'ngettext', [ $this, 'translate_plural_string' ], 10, 5 );
			add_filter( 'ngettext_with_context', [ $this, 'translate_plural_string_with_context' ], 10, 6 );
		}
	}

	/**
	 * Whether a domain is served by lookup only.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	public static function is_lookup_only( string $domain ): bool {
		return in_array( $domain, self::LOOKUP_ONLY, true );
	}

	/**
	 * Whether the gettext filters attach for a loaded map.
	 *
	 * Never on admin-ajax with an admin referer (served by lookup); admin-ajax without one and `?wc-ajax=`
	 * decide as a page view does.
	 *
	 * @param bool $gettext_rows Whether the map holds a row of a gettext domain.
	 * @param bool $any_rows     Whether the map holds any row.
	 * @return bool
	 */
	public static function should_attach_gettext( bool $gettext_rows, bool $any_rows ): bool {
		if ( is_admin() && wp_doing_ajax() && self::referer_is_admin() ) {
			return false;
		}

		/**
		 * Whether the gettext filters attach on a translated request.
		 *
		 * @hook perflocale/strings/attach_gettext
		 * @since 1.0.8
		 * @param string|bool $mode 'auto' (attach when a gettext domain has translations), true (attach whenever the map is non-empty), false (never).
		 */
		$mode = apply_filters( 'perflocale/strings/attach_gettext', 'auto' );

		if ( true === $mode ) {
			return $any_rows;
		}

		if ( false === $mode ) {
			return false;
		}

		return $gettext_rows;
	}

	/**
	 * Whether the raw referer is under admin_url(); an absolute one must be on the admin host (any scheme or case).
	 *
	 * @return bool
	 */
	public static function referer_is_admin(): bool {
		$referer = wp_get_raw_referer();

		if ( ! is_string( $referer ) || '' === $referer ) {
			return false;
		}

		$parts = wp_parse_url( $referer );
		$admin = wp_parse_url( admin_url() );

		if ( ! is_array( $parts ) || ! is_array( $admin ) ) {
			return false;
		}

		if ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) ( $admin['host'] ?? '' ) ) ) {
			return false;
		}

		$admin_path = (string) ( $admin['path'] ?? '' );

		return '' !== $admin_path && str_starts_with( (string) ( $parts['path'] ?? '' ), $admin_path );
	}

	/**
	 * Preload all string translations for the current language.
	 *
	 * @return void
	 */
	public function preload_translations(): void {
		if ( $this->loaded ) {
			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		$this->loaded = true;

		// Every translation of the language, hash => text, read on a miss (Strings\MapBuilder, loaded only then).
		$preloaded = $this->cache->get(
			"all_str2_translations_{$language_id}",
			static fn (): array => MapBuilder::build( $language_id, false ),
			6 * HOUR_IN_SECONDS,
			'perflocale_strings'
		);

		// An object cache can return a non-array (truncated payload, failed unserialize): assigning it to the
		// typed property would fatal every request, so it counts as "no translations".
		$this->translations = is_array( $preloaded ) ? $preloaded : [];

		// Fill gaps from fallback languages, when configured.
		$settings  = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$fallbacks = (array) $settings->get( 'language_fallbacks' );

		if ( ! empty( $fallbacks ) ) {
			$this->load_fallback_translations( $language_id );
		}
	}

	/**
	 * Load fallback language translations for strings missing in the current language.
	 *
	 * The current language, then earlier chain entries, win over later ones.
	 *
	 * @param int $language_id Current language ID.
	 * @return void
	 */
	private function load_fallback_translations( int $language_id ): void {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$slug     = $this->router->get_current_slug();
		// 'strings' context: a string may borrow from a sibling locale, unlike post content.
		$chain    = $settings->get_fallback_chain( (string) $slug, 'strings' );

		if ( empty( $chain ) ) {
			return;
		}

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $this->cache );

		// Each later language only fills the gaps that remain.
		foreach ( (array) $chain as $fallback_slug ) {
			$fallback_lang = $lang_repo->find_by_slug( sanitize_key( (string) $fallback_slug ) );

			if ( ! $fallback_lang || (int) $fallback_lang->id === $language_id ) {
				continue;
			}

			$fallback_id = (int) $fallback_lang->id;

			$fallback_translations = $this->cache->get(
				"all_str2_translations_{$fallback_id}",
				static fn (): array => MapBuilder::build( $fallback_id, true ),
				6 * HOUR_IN_SECONDS,
				'perflocale_strings'
			);

			// Same non-array guard as the primary map: splicing and `+=` fatal on a non-array.
			if ( is_array( $fallback_translations ) && $fallback_translations !== [] ) {
				// Extra forms merge per hash, not by the whole-key `+=`, which would drop one side's plurals.
				$fallback_extra = $fallback_translations[ self::EXTRA_FORMS_KEY ] ?? [];
				unset( $fallback_translations[ self::EXTRA_FORMS_KEY ] );

				// Either map having gettext rows means the merged one has.
				$gettext_rows = $this->has_gettext_rows() || '0' !== ( $fallback_translations[ self::GETTEXT_ROWS_KEY ] ?? '1' );
				unset( $fallback_translations[ self::GETTEXT_ROWS_KEY ] );

				// The domain and prefix sets are unions carried in by the merge below.
				$fallback_translations = $this->merge_reserved_sets( $fallback_translations );
				unset( $fallback_translations[ self::MAP_BYTES_KEY ], $fallback_translations[ self::FIELD_LINKS_KEY ] );

				// Fallback extra forms only when the whole row comes from the fallback (checked before the merge):
				// else forms 2..N would be in another language than form 1.
				$adoptable_extra = [];

				if ( is_array( $fallback_extra ) && $fallback_extra !== [] ) {
					foreach ( $fallback_extra as $hash => $forms ) {
						if ( ! isset( $this->translations[ $hash ] ) ) {
							$adoptable_extra[ $hash ] = $forms;
						}
					}
				}

				$this->translations += $fallback_translations;

				$this->translations[ self::GETTEXT_ROWS_KEY ] = $gettext_rows ? '1' : '0';

				if ( $adoptable_extra !== [] ) {
					$primary_extra = $this->translations[ self::EXTRA_FORMS_KEY ] ?? [];
					// `+` keeps the primary's forms and fills only the hashes it lacks.
					$this->translations[ self::EXTRA_FORMS_KEY ] = ( is_array( $primary_extra ) ? $primary_extra : [] ) + $adoptable_extra;
				}
			}
		}
	}

	/**
	 * A numeric value as an int, anything else 0.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function as_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Union the domain and prefix sets into the fallback map; the loaded map's copies are unset so `+=` takes
	 * the unions. A map lacking a key leaves the merged map without it (pessimistic).
	 *
	 * @param array<mixed> $fallback The fallback map as the cache returned it.
	 * @return array<mixed> The fallback map with the merged sets.
	 */
	private function merge_reserved_sets( array $fallback ): array {
		foreach ( [ self::DOMAINS_KEY, self::CONTEXT_PREFIXES_KEY ] as $key ) {
			$own   = [] === $this->translations ? [] : self::entry( $this->translations, $key );
			$other = $fallback[ $key ] ?? null;

			unset( $this->translations[ $key ], $fallback[ $key ] );

			if ( ! is_array( $own ) || ! is_array( $other ) ) {
				continue;
			}

			if ( self::DOMAINS_KEY === $key ) {
				$fallback[ $key ] = $own + $other;
				continue;
			}

			foreach ( $other as $domain => $set ) {
				$own[ $domain ] = ( is_array( $own[ $domain ] ?? null ) ? $own[ $domain ] : [] ) + ( is_array( $set ) ? $set : [] );
			}

			$fallback[ $key ] = $own;
		}

		return $fallback;
	}

	/**
	 * One map entry as mixed (a reserved entry holds an array).
	 *
	 * @param array<mixed> $map A map.
	 * @param string       $key Key.
	 * @return mixed
	 */
	private static function entry( array $map, string $key ): mixed {
		return $map[ $key ] ?? null;
	}

	/**
	 * Whether the loaded map holds a row of a lookup-only domain; true for a map without the domain list.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	public function map_has_domain( string $domain ): bool {
		if ( ! $this->loaded ) {
			$this->preload_translations();
		}

		if ( $this->translations === [] ) {
			return false;
		}

		$domains = self::entry( $this->translations, self::DOMAINS_KEY );

		return ! is_array( $domains ) || isset( $domains[ $domain ] );
	}

	/**
	 * Whether the loaded map holds a lookup-only row under a context prefix; true for a map without the list.
	 *
	 * @param string $domain Text domain.
	 * @param string $prefix Context prefix (`post_type|book|`, `mb:box_id`).
	 * @return bool
	 */
	public function map_has_context_prefix( string $domain, string $prefix ): bool {
		if ( ! $this->map_has_domain( $domain ) ) {
			return false;
		}

		$prefixes = self::entry( $this->translations, self::CONTEXT_PREFIXES_KEY );

		if ( ! is_array( $prefixes ) ) {
			return true;
		}

		return is_array( $prefixes[ $domain ] ?? null ) && isset( $prefixes[ $domain ][ $prefix ] );
	}

	/**
	 * The serialized size a cached map carries in MAP_BYTES_KEY.
	 *
	 * @param mixed $map A map read from the cache.
	 * @return int 0 when the map carries no size.
	 */
	public static function map_bytes( mixed $map ): int {
		return is_array( $map ) ? self::as_int( $map[ self::MAP_BYTES_KEY ] ?? 0 ) : 0;
	}

	/**
	 * Whether the loaded map holds a row the gettext filters can serve.
	 *
	 * @return bool
	 */
	private function has_gettext_rows(): bool {
		if ( $this->translations === [] ) {
			return false;
		}

		return '0' !== ( $this->translations[ self::GETTEXT_ROWS_KEY ] ?? '1' );
	}

	/**
	 * Filter gettext to serve translated strings.
	 *
	 * Mixed parameters: WordPress may pass null (a plugin calling __( null )), which a string type rejects.
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
	 * Filter gettext_with_context to serve translated strings.
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

		return $this->get_translation( $text, (string) $domain, (string) ( $context ?? '' ) ) ?? (string) $translation;
	}

	/**
	 * Filter ngettext to serve translated plural strings.
	 *
	 * The form comes from the current language's CLDR rule for $number.
	 *
	 * @param mixed $translation Translated text (from .mo files).
	 * @param mixed $single Singular original text.
	 * @param mixed $plural Plural original text.
	 * @param mixed $number Count determining singular/plural.
	 * @param mixed $domain Text domain.
	 * @return string Translated text.
	 */
	public function translate_plural_string( mixed $translation, mixed $single, mixed $plural, mixed $number, mixed $domain ): string {
		$form_index = $this->plural_form_index( (int) $number );

		// Form 0 is the 'singular' row; forms 1..N the 'plural' row (the scanner's _n() contexts).
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

		return $this->get_plural_form( $original, (string) $domain, 'plural', $form_index ) ?? (string) $translation;
	}

	/**
	 * Filter ngettext_with_context to serve translated plural strings.
	 *
	 * @param mixed $translation Translated text.
	 * @param mixed $single Singular original text.
	 * @param mixed $plural Plural original text.
	 * @param mixed $number Count determining singular/plural.
	 * @param mixed $context Translation context.
	 * @param mixed $domain Text domain.
	 * @return string Translated text.
	 */
	public function translate_plural_string_with_context( mixed $translation, mixed $single, mixed $plural, mixed $number, mixed $context, mixed $domain ): string {
		$form_index = $this->plural_form_index( (int) $number );
		$base_ctx   = (string) ( $context ?? '' );

		// _nx(): singular under the context, plural under "context (plural)", or 'singular' / 'plural'.
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

		return $this->get_plural_form( $original, (string) $domain, $form_context, $form_index ) ?? (string) $translation;
	}

	/**
	 * CLDR plural form index for a count in the current language.
	 *
	 * @param int $number The count.
	 * @return int Form index (0 = singular; 1..N = plural forms).
	 */
	private function plural_form_index( int $number ): int {
		$current = $this->router->get_current_language();
		$locale  = is_object( $current ) && ! empty( $current->locale )
			? (string) $current->locale
			: ( is_object( $current ) && ! empty( $current->slug ) ? (string) $current->slug : 'en' );

		return PluralRules::form_index( $locale, $number );
	}

	/**
	 * Resolve a plural translation for a given form index (>=1).
	 *
	 * Form 1 is the plural row's translation, forms 2..N its extra_forms; a missing form falls back to form 1.
	 *
	 * @param string $original     The plural original text.
	 * @param string $domain       Text domain.
	 * @param string $form_context 'plural' or '<ctx> (plural)'.
	 * @param int    $form_index   Requested form index (>=1).
	 * @return string|null
	 */
	private function get_plural_form( string $original, string $domain, string $form_context, int $form_index ): ?string {
		$form_one = $this->get_translation( $original, $domain, $form_context );

		if ( $form_index <= 1 ) {
			return $form_one;
		}

		if ( ! $this->loaded ) {
			$this->preload_translations();
		}

		$extra_map = $this->translations[ self::EXTRA_FORMS_KEY ] ?? null;

		if ( is_array( $extra_map ) ) {
			$hash = self::identity_hash( $domain, $form_context, $original );

			if ( isset( $extra_map[ $hash ] ) && is_array( $extra_map[ $hash ] ) ) {
				// extra_forms holds forms 2..N; index 2 → offset 0.
				$candidate = $extra_map[ $hash ][ $form_index - 2 ] ?? '';

				if ( is_string( $candidate ) && $candidate !== '' ) {
					// The same filter as forms 0 and 1 in get_translation(), so listeners see every form.
					return has_filter( 'perflocale/string/translate' ) ? \PerfLocale\Util\StringLookup::filtered( $candidate, $original, $domain, $this->router->get_current_slug() ) : $candidate;
				}
			}
		}

		return $form_one;
	}

	/**
	 * Look up a string translation from the preloaded cache.
	 *
	 * Public for strings that bypass WordPress i18n (WooCommerce attribute labels, option strings).
	 *
	 * @param string $text Original text.
	 * @param string $domain Text domain.
	 * @param string $context Context.
	 * @return string|null Translated text or null if not found.
	 */
	public function get_translation( string $text, string $domain, string $context ): ?string {
		// Lazy load on first call.
		if ( ! $this->loaded ) {
			$this->preload_translations();
		}

		// Fast path: no translations at all - skip hash computation entirely.
		if ( empty( $this->translations ) ) {
			return null;
		}

		// Memo key only ("\x04" ends the context); the stored hash is identity_hash()'s.
		$cache_key = $domain . '|' . $context . "\x04" . $text;

		if ( isset( $this->hash_cache[ $cache_key ] ) ) {
			$hash = $this->hash_cache[ $cache_key ];
		} else {
			$hash = self::identity_hash( $domain, $context, $text );

			if ( strlen( $cache_key ) <= self::MEMO_MAX_KEY_BYTES ) {
				if ( count( $this->hash_cache ) >= self::MEMO_MAX_ENTRIES ) {
					$this->hash_cache = [];
				}

				$this->hash_cache[ $cache_key ] = $hash;
			}
		}

		if ( ! isset( $this->translations[ $hash ] ) ) {
			return null;
		}

		$translated = $this->translations[ $hash ];

		if ( ! is_string( $translated ) || $translated === '' ) {
			return null;
		}

		// Hot path (hundreds of calls per page): the filter only when it has listeners.
		return has_filter( 'perflocale/string/translate' ) ? \PerfLocale\Util\StringLookup::filtered( $translated, $text, $domain, $this->router->get_current_slug() ) : $translated;
	}

	/**
	 * Drop the per-blog memo.
	 *
	 * A shared singleton with per-blog data: without this reset on `switch_blog` blog 2 serves blog 1's strings.
	 *
	 * @return void
	 */
	public function reset_for_blog_switch(): void {
		$this->translations = [];
		$this->loaded       = false;
		$this->hash_cache   = [];
	}

}
