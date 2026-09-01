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
 * Intercepts gettext calls (__(), _e(), etc.) and serves translations
 * from the database via wp_options.
 *
 * Uses lazy loading: translations are loaded on the first gettext call
 * (after language detection has completed on parse_request), not on
 * wp_loaded (which fires before parse_request).
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
	 * Reserved key under which the extra-plural-forms sub-map lives inside
	 * $translations. A NUL prefix guarantees it can never collide with a
	 * 64-hex sha256 original_hash.
	 */
	private const EXTRA_FORMS_KEY = "\0pfl_extra_forms";

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
		// Defer gettext filter registration until language is detected.
		// This avoids hundreds of wasted filter calls during early WP init
		// (before parse_request fires and language is known).
		add_action( 'perflocale/language/detected', [ $this, 'activate' ] );
	}

	/**
	 * Activate gettext filters and eagerly preload translations.
	 *
	 * Called when language detection completes (on parse_request).
	 * By this point the language is guaranteed to be available.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Fast-path: on the default language WordPress already serves source
		// strings in that language - no gettext swap needed. Skip the preload
		// query + filter registration entirely to save ~200µs on every
		// default-language frontend request (database mode's preload query is
		// a 4-table JOIN even on warm cache). Same principle as the analogous
		// skip in TranslationFileLoader::activate.
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

		// Preload first - if there are no DB translations for this language,
		// skip registering the gettext filters entirely. This avoids adding
		// overhead to every __() call (300-800 per page) when no translations exist.
		$this->preload_translations();

		if ( ! empty( $this->translations ) ) {
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

		// Load ALL translated text into memory in a SINGLE query.
		// JOINs strings → groups → links → wp_options to get hash → translation in one pass.
		$preloaded = $this->cache->get(
			"all_string_translations_{$language_id}",
			function () use ( $language_id ): array {
				// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
				global $wpdb;

				$strings_table = Schema::table( 'strings' );
				$links_table   = Schema::table( 'translation_links' );
				$groups_table  = Schema::table( 'translation_groups' );
				$st_table      = Schema::table( 'string_translations' );

				// Single query: drive the plan from string_translations FIRST
				// so the (string_id, language_id) PRIMARY KEY narrows the
				// result set to "translated entries for this language" before
				// we touch strings / groups / translation_links. The earlier
				// version started from `strings` and applied the language
				// filter via INNER JOIN + post-filter `WHERE st.translation != ''`,
				// which left the planner unable to use the language_id index
				// efficiently and meant a full strings-table scan on cold
				// caches with 5k+ entries (50–150ms per fresh PHP worker).
				//
				// Driving from `st` flips the plan to: index range scan on
				// (string_id, language_id) for `language_id = ?`, then key-
				// lookup on each `s.id` and `l.group_id`. Same result, ~10×
				// faster on the cold path; warm path is unaffected (cache hit).
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

				$map   = [];
				$extra = [];

				if ( is_array( $results ) ) {
					foreach ( $results as $row ) {
						// Skip internal, output-buffer-served domains (a leading
						// "_", e.g. the Visual Editor's "_pfl_dyn") — not gettext-
						// resolved, so they must not populate the map (which would
						// register the gettext filters on otherwise-untranslated
						// languages).
						if ( isset( $row->domain[0] ) && $row->domain[0] === '_' ) {
							continue;
						}

						$map[ $row->original_hash ] = $row->translated_text;

						// Plural forms 2..N (Polish/Russian/Arabic) live in the
						// plural-context row's extra_forms JSON — NULL for every
						// 2-form language, so this branch is skipped for almost
						// all rows.
						if ( isset( $row->extra_forms ) && $row->extra_forms !== null && $row->extra_forms !== '' ) {
							$decoded = json_decode( (string) $row->extra_forms, true );

							if ( is_array( $decoded ) && $decoded !== [] ) {
								$extra[ $row->original_hash ] = array_map( 'strval', $decoded );
							}
						}
					}
				}

				// Fold the (usually empty) extra-forms map into the cached
				// structure under a reserved key that can NEVER collide with a
				// 64-hex sha256 hash, so every existing hash lookup is
				// untouched and old cached blobs (which lack the key) simply
				// resolve to "no extra forms".
				if ( $extra !== [] ) {
					$map[ self::EXTRA_FORMS_KEY ] = $extra;
				}

				// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
				return $map;
			},
			6 * HOUR_IN_SECONDS,
			'perflocale_strings'
		);

		// A persistent object cache can hand back something that is no longer
		// the array we stored — a truncated Redis payload, or a drop-in that
		// reports a hit but returns false for a value it could not unserialize.
		// $translations is a TYPED property, so assigning that straight through
		// throws at the ASSIGNMENT: a fatal on every front-end request for as
		// long as the poisoned entry lives, not a degraded page. Treat a
		// non-array payload as "no translations" — the same defensive shape
		// LanguageRepository::find() uses for its non-object payloads —
		// so the request finishes serving source strings instead of white-
		// screening. A well-formed array is passed through UNCHANGED.
		$this->translations = is_array( $preloaded ) ? $preloaded : [];

		// Load fallback language translations to fill gaps (only if fallbacks are configured).
		$settings  = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$fallbacks = (array) $settings->get( 'language_fallbacks' );

		if ( ! empty( $fallbacks ) ) {
			$this->load_fallback_translations( $language_id );
		}
	}

	/**
	 * Load fallback language translations for strings missing in the current language.
	 *
	 * If the current language has a fallback configured, loads that language's
	 * translations and merges them (current language takes priority).
	 *
	 * @param int $language_id Current language ID.
	 * @return void
	 */
	private function load_fallback_translations( int $language_id ): void {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$slug     = $this->router->get_current_slug();
		$chain    = $settings->get_language_fallbacks()[ $slug ] ?? [];

		if ( empty( $chain ) ) {
			return;
		}

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $this->cache );

		// Walk the configured fallback chain. The current language (already
		// loaded) and earlier chain entries win; each subsequent language only
		// fills the gaps that remain.
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

					$map   = [];
					$extra = [];

					if ( is_array( $results ) ) {
						foreach ( $results as $row ) {
							// Skip internal, output-buffer-served domains (a
							// leading "_", e.g. the Visual Editor's "_pfl_dyn") —
							// they're not gettext-resolved, so they must not
							// populate the map (which would register the gettext
							// filters on otherwise-untranslated languages).
							if ( isset( $row->domain[0] ) && $row->domain[0] === '_' ) {
								continue;
							}

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

					// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
					return $map;
				},
				6 * HOUR_IN_SECONDS,
				'perflocale_strings'
			);

			// Same corrupted-payload guard as the primary map above: this map
			// is spliced and then `+=`d into a typed array property, and both
			// operations fatal on a non-array. `! empty()` alone would let the
			// string "GARBAGE" through.
			if ( is_array( $fallback_translations ) && $fallback_translations !== [] ) {
				// The extra-forms sub-map needs a PER-HASH merge (primary
				// hash wins), not the whole-key `+=` — otherwise the primary
				// language's EXTRA_FORMS_KEY entry would block every fallback
				// plural, or vice-versa. Splice it out, merge separately.
				$fallback_extra = $fallback_translations[ self::EXTRA_FORMS_KEY ] ?? [];
				unset( $fallback_translations[ self::EXTRA_FORMS_KEY ] );

				// A fallback's extra forms may only ride along when the WHOLE
				// row comes from the fallback (the primary map lacks the hash,
				// evaluated BEFORE the merge below fills it). If the primary
				// has its own form-1 translation but no extra forms, attaching
				// the fallback's forms 2..N would serve a MIXED-language plural
				// set (form 1 in the primary language, "many" in the fallback
				// language) — same-language form-1 fallback is the lesser evil.
				$adoptable_extra = [];

				if ( is_array( $fallback_extra ) && $fallback_extra !== [] ) {
					foreach ( $fallback_extra as $hash => $forms ) {
						if ( ! isset( $this->translations[ $hash ] ) ) {
							$adoptable_extra[ $hash ] = $forms;
						}
					}
				}

				$this->translations += $fallback_translations;

				if ( $adoptable_extra !== [] ) {
					$primary_extra = $this->translations[ self::EXTRA_FORMS_KEY ] ?? [];
					// `+` keeps the primary hash's forms and fills only the
					// hashes the primary lacks.
					$this->translations[ self::EXTRA_FORMS_KEY ] = ( is_array( $primary_extra ) ? $primary_extra : [] ) + $adoptable_extra;
				}
			}
		}
	}

	/**
	 * Filter gettext to serve translated strings.
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
	 * WordPress has already selected the correct form (singular/plural)
	 * based on $number. We look up the translation for that form.
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

		// Form 0 = singular row; forms 1..N = plural row (translation column
		// for form 1, extra_forms for 2..N). The scanner stores _n() strings
		// under context 'singular' / 'plural'.
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

		// _nx() strings: singular under the actual context, plural forms
		// under "context (plural)" (or plain "plural" when no context).
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
	 * Form 1 is the plural row's own `translation`; forms 2..N come from
	 * that row's `extra_forms` JSON (loaded into the reserved sub-map).
	 * Falls back to form 1 when a specific extra form is absent — the
	 * partially-translated-plural behaviour, matching gettext.
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
					// Forms 0 and 1 pass through get_translation()'s
					// perflocale/string/translate filter; run forms 2..N through
					// the same filter so a listener (e.g. a glossary marker)
					// sees every plural form consistently, not just the first
					// two. Cold path (plural, form >= 2 only) — no hot-path cost.
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
	 * Lazy-loads translations on the first call. By this point,
	 * parse_request has already fired and the language is detected.
	 *
	 * Uses a per-request hash cache to avoid re-computing SHA-256
	 * for strings that appear multiple times (common in loops).
	 *
	 * Public so external code (e.g., WooCommerce attribute label filter)
	 * can look up translations for strings that bypass WordPress i18n.
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

		// Per-request hash cache - avoids recomputing SHA-256 for repeated strings.
		// Key is a cheap concatenation; value is the expensive SHA-256 hash.
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

		// Only apply the filter if there are actual listeners - this fires
		// 300-800 times per page, so skip the overhead when nobody is listening.
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
		$this->hash_cache   = [];
	}

}
