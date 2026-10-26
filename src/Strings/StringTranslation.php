<?php
/**
 * String translation via gettext filter (database mode).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;
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
	private const EXTRA_FORMS_KEY = "\0pfl_extra_forms";

	/**
	 * Reserved key: '1' when the map has a row the gettext filters serve, '0' when only OptionStrings rows.
	 *
	 * A map without the key (an older cache entry) counts as having gettext rows.
	 */
	private const GETTEXT_ROWS_KEY = "\0pfl_gettext_rows";

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

		if ( $this->has_gettext_rows() ) {
			add_filter( 'gettext', [ $this, 'translate_string' ], 10, 3 );
			add_filter( 'gettext_with_context', [ $this, 'translate_string_with_context' ], 10, 4 );
			add_filter( 'ngettext', [ $this, 'translate_plural_string' ], 10, 5 );
			add_filter( 'ngettext_with_context', [ $this, 'translate_plural_string_with_context' ], 10, 6 );
		}
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

		// Every translation of the language, hash => text, in one query.
		$preloaded = $this->cache->get(
			"all_string_translations_{$language_id}",
			function () use ( $language_id ): array {
				// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
				global $wpdb;

				$strings_table = Schema::table( 'strings' );
				$links_table   = Schema::table( 'translation_links' );
				$groups_table  = Schema::table( 'translation_groups' );
				$st_table      = Schema::table( 'string_translations' );

				// Driven from string_translations, so its key narrows to this language before the other joins.
				// See docs-ai/11-code-rationale.md, "StringTranslation: preload query plan".
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT s.original_hash, st.translation AS translated_text, st.extra_forms, s.domain
						FROM %i st
						INNER JOIN %i s
							ON s.id = st.string_id
						INNER JOIN %i g
							ON g.id = s.group_id AND g.type = 'string'
						INNER JOIN %i l
							ON l.group_id = s.group_id AND l.language_id = st.language_id
						WHERE st.language_id = %d
						AND st.translation != ''",
						$st_table,
						$strings_table,
						$groups_table,
						$links_table,
						$language_id
					)
				);

				$map     = [];
				$extra   = [];
				$gettext = false;

				if ( is_array( $results ) ) {
					foreach ( $results as $row ) {
						// Internal "_" domains (`_pfl_dyn`) are served by the output buffer, not gettext:
						// they must not make the map non-empty and register the filters.
						if ( isset( $row->domain[0] ) && $row->domain[0] === '_' ) {
							continue;
						}

						$map[ $row->original_hash ] = $row->translated_text;
						$gettext                    = $gettext || self::is_gettext_domain( (string) $row->domain );

						// Plural forms 2..N live in the plural row's extra_forms JSON (NULL for 2-form languages).
						if ( isset( $row->extra_forms ) && $row->extra_forms !== null && $row->extra_forms !== '' ) {
							$decoded = json_decode( (string) $row->extra_forms, true );

							if ( is_array( $decoded ) && $decoded !== [] ) {
								$extra[ $row->original_hash ] = array_map( 'strval', $decoded );
							}
						}
					}
				}

				// Extra forms go under a reserved key; a cached map without it has no extra forms.
				if ( $extra !== [] ) {
					$map[ self::EXTRA_FORMS_KEY ] = $extra;
				}

				if ( $map !== [] ) {
					$map[ self::GETTEXT_ROWS_KEY ] = $gettext ? '1' : '0';
				}

				// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
				return $map;
			},
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
				"all_string_translations_{$fallback_id}",
				function () use ( $fallback_id ): array {
					// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
					global $wpdb;

					$strings_table = Schema::table( 'strings' );
					$links_table   = Schema::table( 'translation_links' );
					$groups_table  = Schema::table( 'translation_groups' );
					$st_table      = Schema::table( 'string_translations' );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$results = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT s.original_hash, st.translation AS translated_text, st.extra_forms, s.domain
							FROM %i s
							INNER JOIN %i g ON g.id = s.group_id AND g.type = 'string'
							INNER JOIN %i l ON l.group_id = s.group_id AND l.language_id = %d
							INNER JOIN %i st ON st.string_id = s.id AND st.language_id = %d
							WHERE st.translation != ''",
							$strings_table,
							$groups_table,
							$links_table,
							$fallback_id,
							$st_table,
							$fallback_id
						)
					);

					$map     = [];
					$extra   = [];
					$gettext = false;

					if ( is_array( $results ) ) {
						foreach ( $results as $row ) {
							// Internal "_" domains are not gettext-served: they must not register the filters.
							if ( isset( $row->domain[0] ) && $row->domain[0] === '_' ) {
								continue;
							}

							$gettext = $gettext || self::is_gettext_domain( (string) $row->domain );

							if ( isset( $row->extra_forms ) && $row->extra_forms !== null && $row->extra_forms !== '' ) {
								$decoded = json_decode( (string) $row->extra_forms, true );

								if ( is_array( $decoded ) && $decoded !== [] ) {
									$extra[ $row->original_hash ] = array_map( 'strval', $decoded );
								}
							}

							$map[ $row->original_hash ] = $row->translated_text;
						}
					}

					if ( $extra !== [] ) {
						$map[ self::EXTRA_FORMS_KEY ] = $extra;
					}

					if ( $map !== [] ) {
						$map[ self::GETTEXT_ROWS_KEY ] = $gettext ? '1' : '0';
					}

					// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
					return $map;
				},
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
	 * Whether rows of this text domain are served through the gettext filters.
	 *
	 * @param string $domain Text domain.
	 * @return bool
	 */
	private static function is_gettext_domain( string $domain ): bool {
		return $domain !== \PerfLocale\Frontend\OptionStrings::DOMAIN;
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
			$hash = hash( 'sha256', $domain . '|' . $form_context . '|' . $original );

			if ( isset( $extra_map[ $hash ] ) && is_array( $extra_map[ $hash ] ) ) {
				// extra_forms holds forms 2..N; index 2 → offset 0.
				$candidate = $extra_map[ $hash ][ $form_index - 2 ] ?? '';

				if ( is_string( $candidate ) && $candidate !== '' ) {
					// The same filter as forms 0 and 1 in get_translation(), so listeners see every form.
					if ( has_filter( 'perflocale/string/translate' ) ) {
						$candidate = (string) apply_filters(
							'perflocale/string/translate',
							$candidate,
							$original,
							$domain,
							$this->router->get_current_slug()
						);
					}

					return $candidate;
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

		// Per-request memo of the SHA-256 of repeated strings.
		$cache_key = $domain . '|' . $context . '|' . $text;

		if ( isset( $this->hash_cache[ $cache_key ] ) ) {
			$hash = $this->hash_cache[ $cache_key ];
		} else {
			$hash                           = hash( 'sha256', $cache_key );
			$this->hash_cache[ $cache_key ] = $hash;
		}

		if ( ! isset( $this->translations[ $hash ] ) ) {
			return null;
		}

		$translated = $this->translations[ $hash ];

		if ( ! is_string( $translated ) || $translated === '' ) {
			return null;
		}

		// Hot path (hundreds of calls per page): apply the filter only when it has listeners.
		if ( has_filter( 'perflocale/string/translate' ) ) {
			/**
			 * Filter a translated string before returning it.
			 *
			 * @param string $translated The translated text.
			 * @param string $text The original text.
			 * @param string $domain Text domain.
			 * @param string $lang Current language slug.
			 */
			$translated = apply_filters(
				'perflocale/string/translate',
				$translated,
				$text,
				$domain,
				$this->router->get_current_slug()
			);
		}

		return $translated;
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
