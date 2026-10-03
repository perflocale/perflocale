<?php
/**
 * WPML migration importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\MigrationSourceMapRepository;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports translation data from WPML.
 *
 * Reads WPML's icl_translations and icl_strings tables, maps language
 * codes to PerfLocale language IDs, and creates translation groups
 * and links for posts, terms, and strings.
 *
 * @phpstan-type ImportResult array{posts: int, terms: int, strings: int, errors: array<int, string>, blocked: bool, preflight: array<string, mixed>, menus: int, orders: int, term_languages: array{found: int, fixed: int, left: int, examples: list<string>}, skipped_kinds: array<string, int>, skipped: array<string, int>, skipped_examples: array<string, list<string>>, protected_domains: list<string>}
 */
final class WpmlImporter {

	use ImportHeartbeat;

	/**
	 * The import's stages, in the order it runs them ({@see ImportHeartbeat}).
	 */
	private const PROGRESS_STAGES = [ 'posts', 'terms', 'term_check', 'strings', 'orders' ];

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $languages;

	/**
	 * @var TranslationGroupRepository
	 */
	private readonly TranslationGroupRepository $groups;

	/**
	 * @var StringRepository
	 */
	private readonly StringRepository $strings;

	/**
	 * Source-map repository for idempotency across DB restores +
	 * partial-failure crashes. The map pins WPML trids to the
	 * translation_groups row PerfLocale created for them — so a re-run
	 * after a restore finds the prior mapping and reuses the group_id
	 * instead of allocating a duplicate.
	 *
	 * @var MigrationSourceMapRepository
	 */
	private readonly MigrationSourceMapRepository $source_map;

	/**
	 * Cache manager kept on the instance so we can construct additional
	 * repositories (e.g. StringTranslationRepository) lazily during string
	 * import without passing the cache through every call chain.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Language code mapping: WPML code => PerfLocale language ID.
	 *
	 * @var array<string, int>
	 */
	private array $language_map = [];

	/**
	 * Source reads that failed during this run; import() adds them to the errors.
	 *
	 * @var list<string>
	 */
	private array $read_errors = [];

	/**
	 * Reader for WPML's settings and locale tables.
	 *
	 * @var WpmlSettingsReader|null
	 */
	private ?WpmlSettingsReader $reader = null;

	/**
	 * WPML code => locale, once read.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $wpml_locales = null;

	/**
	 * Element types this run skips, once resolved.
	 *
	 * @var list<string>|null
	 */
	private ?array $skipped_types = null;

	/**
	 * Active PerfLocale language slugs by id, once read.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $slugs_by_id = null;

	/**
	 * Examples kept per skip reason.
	 */
	private const SKIPPED_EXAMPLES = 5;

	/**
	 * Skipped icl_translations rows per reason, for this run.
	 *
	 * @var array<string, int>
	 */
	private array $skipped = [];

	/**
	 * Up to SKIPPED_EXAMPLES "trid N (post|term ID)" examples per reason.
	 *
	 * @var array<string, list<string>>
	 */
	private array $skipped_examples = [];

	/**
	 * Rows left out because their item is listed in another set, as
	 * icl_translations.translation_id => true.
	 *
	 * @var array<int, true>
	 */
	private array $rows_in_other_sets = [];

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->cache      = $cache;
		$this->languages  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$this->groups     = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$this->strings    = new StringRepository( $cache );
		$this->source_map = new MigrationSourceMapRepository();
	}

	/**
	 * The PerfLocale language slug a WPML code maps to, or null.
	 *
	 * Same matching as the import: exact slug, then WPML's locale for the
	 * code, then a locale prefix.
	 *
	 * @param string $code WPML language code.
	 * @return string|null
	 */
	public function language_for_code( string $code ): ?string {
		$lang = $this->match_language( $code, $this->languages->get_active() );

		return $lang !== null ? (string) $lang->slug : null;
	}

	/**
	 * Check if WPML tables exist and import is possible.
	 *
	 * @return bool
	 */
	public function can_import(): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$translations_table = $this->wpdb->prefix . 'icl_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$translations_table
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $result > 0;
	}

	/**
	 * Run the full WPML import.
	 *
	 * The first import on a blog refuses to start (`blocked`, nothing
	 * written) when WPML's default language is not PerfLocale's default, or
	 * when WPML has content in a language PerfLocale does not have. Once an
	 * import has run, the same findings are reported and the import runs.
	 *
	 * @return ImportResult
	 */
	public function import(): array {
		$result = [
			'posts'             => 0,
			'terms'             => 0,
			'strings'           => 0,
			'errors'            => [],
			'blocked'           => false,
			'preflight'         => [],
			'menus'             => 0,
			'orders'            => 0,
			'term_languages'    => [
				'found'    => 0,
				'fixed'    => 0,
				'left'     => 0,
				'examples' => [],
			],
			'skipped_kinds'     => [],
			'skipped'           => [],
			'skipped_examples'  => [],
			'protected_domains' => [],
		];

		$this->skipped            = [];
		$this->skipped_examples   = [];
		$this->rows_in_other_sets = [];

		if ( ! $this->can_import() ) {
			$result['errors'][] = __( 'WPML tables not found.', 'perflocale' );
			return $result;
		}

		$preflight           = $this->preflight();
		$result['preflight'] = $preflight;

		foreach ( [ 'error', 'warning' ] as $level ) {
			foreach ( $preflight['problems'] as $problem ) {
				if ( $problem['level'] === $level && $problem['code'] !== 'string_only_language' ) {
					$result['errors'][] = $problem['message'];
				}
			}
		}

		if ( $preflight['blocking'] ) {
			$result['blocked'] = true;
			return $result;
		}

		$this->build_language_map( $result );

		if ( empty( $this->language_map ) ) {
			$result['errors'][] = __( 'No matching languages found between WPML and PerfLocale.', 'perflocale' );
			return $result;
		}

		$result['skipped_kinds'] = $this->count_skipped_kinds();

		$this->find_rows_in_several_sets( ObjectType::Post );
		$result['posts'] = $this->import_post_translations( $result );
		$this->find_rows_in_several_sets( ObjectType::Term );
		$result['terms'] = $this->import_term_translations( $result );

		ksort( $this->skipped );
		$result['skipped']          = $this->skipped;
		$result['skipped_examples'] = $this->skipped_examples;

		MigrationState::mark_imported( 'wpml' );

		$this->check_term_languages( $result );

		$result['strings'] = $this->import_string_translations( $result );
		$result['orders']  = $this->import_order_languages( $result );
		$result['errors']  = array_merge( $result['errors'], $this->read_errors );

		return $result;
	}

	/**
	 * Compare WPML's settings and content with PerfLocale's languages.
	 *
	 * Read-only. `blocking` is true when import() would refuse: only while
	 * no WPML import has run on this blog, and only for an `error` problem.
	 *
	 * @return array{blocking: bool, first_import: bool, wpml: array<string, mixed>, map: array<string, string|null>, problems: list<array{level: string, code: string, message: string}>}
	 */
	public function preflight(): array {
		$reader   = $this->reader();
		$settings = $reader->settings();
		$locales  = $this->wpml_locales();
		$content  = $reader->content_languages( $this->skipped_element_types() );
		$strings  = $reader->string_languages();
		$active   = $this->languages->get_active();
		$pl_def   = $this->languages->get_default();
		$first    = ! MigrationState::is_imported( 'wpml' );

		$codes = array_values(
			array_unique(
				array_merge(
					array_keys( $content ),
					$settings['active'],
					$strings,
					$settings['default'] !== '' ? [ $settings['default'] ] : []
				)
			)
		);

		$map = [];

		foreach ( $codes as $code ) {
			$lang         = $this->match_language( $code, $active );
			$map[ $code ] = $lang !== null ? (string) $lang->slug : null;
		}

		$label = static function ( string $code ) use ( $locales ): string {
			return isset( $locales[ $code ] ) ? $code . ' (' . $locales[ $code ] . ')' : $code;
		};

		$problems = [];
		$pl_slug  = $pl_def !== null ? (string) $pl_def->slug : '';

		if ( ! $settings['found'] || $settings['default'] === '' ) {
			$problems[] = [
				'level'   => 'warning',
				'code'    => 'settings_missing',
				'message' => __( 'WPML\'s settings could not be read, so its default language could not be checked. Make sure PerfLocale\'s default language is the one WPML used.', 'perflocale' ),
			];
		} elseif ( ( $map[ $settings['default'] ] ?? null ) !== $pl_slug ) {
			$problems[] = [
				'level'   => 'error',
				'code'    => 'default_mismatch',
				'message' => sprintf(
					/* translators: 1: WPML default language code and locale, e.g. "fr (fr_FR)", 2: PerfLocale default language slug */
					__( 'WPML\'s default language is %1$s, but PerfLocale\'s default language is %2$s. Make %1$s the default language under PerfLocale → Languages, then run the import again. Importing now would change the address of every page.', 'perflocale' ),
					$label( $settings['default'] ),
					$pl_slug
				),
			];
		}

		$active_codes = $settings['found'] && $settings['active'] !== [] ? $settings['active'] : null;

		foreach ( $content as $code => $rows ) {
			if ( $rows <= 0 || ( $map[ $code ] ?? null ) !== null ) {
				continue;
			}

			if ( $active_codes === null || in_array( $code, $active_codes, true ) ) {
				$problems[] = [
					'level'   => 'error',
					'code'    => 'missing_language',
					'message' => sprintf(
						/* translators: %s: WPML language code and locale, e.g. "bg (bg_BG)" */
						__( 'WPML has content in %s, which has no matching PerfLocale language. Add it under PerfLocale → Languages, then run the import again.', 'perflocale' ),
						$label( (string) $code )
					),
				];
			} else {
				$problems[] = [
					'level'   => 'warning',
					'code'    => 'inactive_rows',
					'message' => sprintf(
						/* translators: 1: number of WPML items, 2: WPML language code and locale */
						_n( '%1$d WPML item is in %2$s, a language WPML had switched off. It was not imported.', '%1$d WPML items are in %2$s, a language WPML had switched off. They were not imported.', (int) $rows, 'perflocale' ),
						$rows,
						$label( (string) $code )
					),
				];
			}
		}

		foreach ( $strings as $code ) {
			if ( ! isset( $content[ $code ] ) && ( $map[ $code ] ?? null ) === null ) {
				$problems[] = [
					'level'   => 'warning',
					'code'    => 'string_only_language',
					'message' => sprintf(
						/* translators: %s: WPML language code */
						__( 'No PerfLocale language match for WPML code "%s".', 'perflocale' ),
						$code
					),
				];
			}
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
		/** @var \PerfLocale\Settings $pl_settings */
		$pl_settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$pl_mode     = $pl_settings->get_url_mode();

		if ( $settings['found'] && $settings['url_mode'] !== '' ) {
			$same_mode = $settings['url_mode'] === 'domain'
				? in_array( $pl_mode, [ 'domain', 'subdomain' ], true )
				: $settings['url_mode'] === $pl_mode;

			if ( ! $same_mode ) {
				$problems[] = [
					'level'   => 'warning',
					'code'    => 'url_mode',
					'message' => sprintf(
						/* translators: 1: WPML's URL format, e.g. "Subdirectory", 2: PerfLocale's URL format */
						__( 'WPML\'s URL format was "%1$s"; PerfLocale\'s is "%2$s". Change it under PerfLocale → Settings → URL & Routing if the addresses should stay the same.', 'perflocale' ),
						self::url_mode_label( $settings['url_mode'] ),
						self::url_mode_label( $pl_mode )
					),
				];
			} elseif ( $pl_mode === 'subdirectory' && $settings['default_in_directory'] !== null && $pl_slug !== '' ) {
				$pl_hides = $pl_settings->hide_default_prefix();

				if ( $settings['default_in_directory'] && $pl_hides ) {
					$problems[] = [
						'level'   => 'warning',
						'code'    => 'hide_default',
						'message' => sprintf(
							/* translators: %s: PerfLocale default language slug */
							__( 'WPML put the default language in its own directory (/%s/); PerfLocale hides the default language\'s prefix. Check Default Language URL under PerfLocale → Settings → URL & Routing.', 'perflocale' ),
							$pl_slug
						),
					];
				} elseif ( ! $settings['default_in_directory'] && ! $pl_hides ) {
					$problems[] = [
						'level'   => 'warning',
						'code'    => 'hide_default',
						'message' => sprintf(
							/* translators: %s: PerfLocale default language slug */
							__( 'WPML showed the default language without a directory; PerfLocale adds the /%s/ prefix to it. Check Default Language URL under PerfLocale → Settings → URL & Routing.', 'perflocale' ),
							$pl_slug
						),
					];
				}
			}
		}

		$blocking = false;

		foreach ( $problems as $problem ) {
			if ( $problem['level'] === 'error' ) {
				$blocking = $first;
				break;
			}
		}

		return [
			'blocking'     => $blocking,
			'first_import' => $first,
			'wpml'         => [
				'settings_found'       => $settings['found'],
				'default'              => $settings['default'],
				'active'               => $settings['active'],
				'url_mode'             => $settings['url_mode'],
				'default_in_directory' => $settings['default_in_directory'],
				'locales'              => array_intersect_key( $locales, array_flip( $codes ) ),
				'content'              => $content,
			],
			'map'          => $map,
			'problems'     => $problems,
		];
	}

	/**
	 * The label PerfLocale's URL & Routing screen shows for a URL mode.
	 *
	 * @param string $mode subdirectory, subdomain, domain or query.
	 * @return string
	 */
	private static function url_mode_label( string $mode ): string {
		return match ( $mode ) {
			'subdirectory' => __( 'Subdirectory', 'perflocale' ),
			'subdomain' => __( 'Subdomain', 'perflocale' ),
			'domain' => __( 'Per-language domain', 'perflocale' ),
			'query' => __( 'Query parameter', 'perflocale' ),
			default => $mode,
		};
	}

	/**
	 * The settings reader, created on first use.
	 *
	 * @return WpmlSettingsReader
	 */
	private function reader(): WpmlSettingsReader {
		if ( $this->reader === null ) {
			$this->reader = new WpmlSettingsReader();
		}

		return $this->reader;
	}

	/**
	 * WPML's code => locale table, read once per importer.
	 *
	 * @return array<string, string>
	 */
	private function wpml_locales(): array {
		if ( $this->wpml_locales === null ) {
			$this->wpml_locales = $this->reader()->locales();
		}

		return $this->wpml_locales;
	}

	/**
	 * The active PerfLocale language a WPML code maps to, or null.
	 *
	 * An exact slug match first. Then WPML's own locale for the code equal to a
	 * language's locale, which maps codes such as zh-hans (zh_CN) that are
	 * neither a slug nor a locale prefix. Then a locale that starts with the
	 * code ("pt-br" → pt_BR).
	 *
	 * @param string        $code   WPML language code.
	 * @param array<object> $active Active PerfLocale languages.
	 * @return object|null
	 */
	private function match_language( string $code, array $active ): ?object {
		if ( $code === '' ) {
			return null;
		}

		foreach ( $active as $lang ) {
			if ( $lang->slug === $code ) {
				return $lang;
			}
		}

		$wpml_locale = self::normalize_locale( $this->wpml_locales()[ $code ] ?? '' );

		if ( $wpml_locale !== '' ) {
			foreach ( $active as $lang ) {
				if ( self::normalize_locale( (string) $lang->locale ) === $wpml_locale ) {
					return $lang;
				}
			}
		}

		$needle = str_replace( '-', '_', strtolower( $code ) );

		foreach ( $active as $lang ) {
			if ( str_starts_with( strtolower( (string) $lang->locale ), $needle ) ) {
				return $lang;
			}
		}

		return null;
	}

	/**
	 * A locale in one comparable form: lowercase, underscores.
	 *
	 * @param string $locale Locale such as "zh_CN" or "zh-cn".
	 * @return string
	 */
	private static function normalize_locale( string $locale ): string {
		return str_replace( '-', '_', strtolower( trim( $locale ) ) );
	}

	/**
	 * WPML element types the import leaves out.
	 *
	 * Menu items and attachments (WPML Media's per-language copies) are not
	 * grouped by PerfLocale, and translation_priority and product_visibility
	 * are WPML's and WooCommerce's internal taxonomies.
	 *
	 * @return list<string>
	 */
	private function skipped_element_types(): array {
		if ( $this->skipped_types !== null ) {
			return $this->skipped_types;
		}

		/**
		 * WPML element types (`icl_translations.element_type`) the WPML import skips.
		 *
		 * Each entry is a `post_<post type>` or `tax_<taxonomy>` value. Return an
		 * empty array to import every type.
		 *
		 * @hook perflocale/migration/wpml/skipped_element_types
		 * @since 1.0.7
		 * @param string[] $types Default: post_nav_menu_item, post_attachment, tax_translation_priority, tax_product_visibility.
		 */
		$types = apply_filters(
			'perflocale/migration/wpml/skipped_element_types',
			[ 'post_nav_menu_item', 'post_attachment', 'tax_translation_priority', 'tax_product_visibility' ]
		);

		$clean = [];

		foreach ( is_array( $types ) ? $types : [] as $type ) {
			if ( is_string( $type ) && preg_match( '/^(post|tax)_[a-z0-9_-]+$/', $type ) === 1 ) {
				$clean[] = $type;
			}
		}

		$this->skipped_types = array_values( array_unique( $clean ) );

		return $this->skipped_types;
	}

	/**
	 * `AND element_type NOT IN (…)` for the skipped types, with its values.
	 *
	 * @param string $column Column reference, e.g. "element_type" or "icl.element_type".
	 * @return array{0: string, 1: list<string>} The SQL fragment (placeholders only) and its values.
	 */
	private function skipped_types_clause( string $column ): array {
		$types = $this->skipped_element_types();

		if ( $types === [] ) {
			return [ '', [] ];
		}

		return [ ' AND ' . $column . ' NOT IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ];
	}

	/**
	 * Rows per skipped element type, for the import result.
	 *
	 * @return array<string, int>
	 */
	private function count_skipped_kinds(): array {
		$types = $this->skipped_element_types();

		if ( $types === [] ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a generated list of %s placeholders; the values are bound through prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT element_type, COUNT(*) AS c FROM %i WHERE element_id IS NOT NULL AND element_type IN ($placeholders) GROUP BY element_type",
				array_merge( [ $this->wpdb->prefix . 'icl_translations' ], $types )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.NotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->element_type ] = (int) $row->c;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Active PerfLocale language slugs by id, read once per importer.
	 *
	 * @return array<int, string>
	 */
	private function slugs_by_id(): array {
		if ( $this->slugs_by_id === null ) {
			$this->slugs_by_id = [];

			foreach ( $this->languages->get_active() as $lang ) {
				$this->slugs_by_id[ (int) $lang->id ] = (string) $lang->slug;
			}
		}

		return $this->slugs_by_id;
	}

	/**
	 * The error left by the source read that just ran, or '' when it worked.
	 *
	 * A failed get_col()/get_results() returns [] exactly as an empty result
	 * does; only last_error tells them apart, and the next query resets it,
	 * so call this straight after the read.
	 *
	 * @phpstan-impure
	 *
	 * @return string
	 */
	private function read_error(): string {
		return $this->wpdb->last_error;
	}

	/**
	 * Log a database error's own text to the PHP error log, when WP_DEBUG_LOG is on.
	 *
	 * That text can carry table names with their prefix and row values.
	 * Migration results and job rows are shown to every user with Jobs
	 * access, so the messages stored there say only that a database error
	 * happened; the detail is here.
	 *
	 * @param string $where What was running, for the log line.
	 * @param string $error The database error.
	 * @return void
	 */
	private static function log_db_error( string $where, string $error ): void {
		if ( $error === '' || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic, only when WP_DEBUG_LOG is on.
		error_log( '[PerfLocale] WPML import: database error while ' . $where . ' - ' . $error );
	}

	/**
	 * Record a failed source read; import() adds it to the errors.
	 *
	 * @param ObjectType $type  What the read was loading.
	 * @param string     $error The database error.
	 * @return void
	 */
	private function record_read_error( ObjectType $type, string $error ): void {
		self::log_db_error( ObjectType::Post === $type ? 'reading post data' : 'reading term data', $error );

		$this->read_errors[] = ObjectType::Post === $type
			? sprintf(
				/* translators: %s: source plugin name */
				__( 'Some %s post data could not be read because of a database error and was not imported. Re-run the import.', 'perflocale' ),
				'WPML'
			)
			: sprintf(
				/* translators: %s: source plugin name */
				__( 'Some %s term data could not be read because of a database error and was not imported. Re-run the import.', 'perflocale' ),
				'WPML'
			);
	}

	/**
	 * Build a mapping from WPML language codes to PerfLocale language IDs.
	 *
	 * @param array<string, mixed> $result Import result (passed by reference for errors).
	 * @phpstan-param ImportResult $result
	 * @return void
	 */
	private function build_language_map( array &$result ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$icl_table = $this->wpdb->prefix . 'icl_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpml_langs = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT language_code FROM %i',
				$icl_table
			)
		);

		// A partial map would silently skip every row in the unread languages;
		// an empty one makes import() stop with nothing imported.
		$read_error = $this->read_error();

		if ( $read_error !== '' ) {
			self::log_db_error( 'reading the language list', $read_error );

			$result['errors'][] = __( 'The WPML language list could not be read because of a database error.', 'perflocale' );
			return;
		}

		if ( ! is_array( $wpml_langs ) ) {
			$wpml_langs = [];
		}

		// Union in languages that appear ONLY in icl_string_translations. A
		// language activated for admin/theme string translation but with no
		// translated posts yet has NO icl_translations rows — building the map
		// from icl_translations alone would leave those string translations
		// unmapped and silently dropped at the $lang_id === null skip.
		$str_trans_table = $this->wpdb->prefix . 'icl_string_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$str_table_exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$str_trans_table
			)
		);

		if ( $str_table_exists > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$string_langs = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT language FROM %i',
					$str_trans_table
				)
			);

			if ( is_array( $string_langs ) ) {
				$wpml_langs = array_values( array_unique( array_merge( $wpml_langs, $string_langs ) ) );
			}
		}

		if ( $wpml_langs === [] ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return;
		}

		$active_languages = $this->languages->get_active();

		// Guard against two WPML codes resolving to the SAME PerfLocale id (an
		// exact slug match on one plus a locale-prefix match on another, e.g.
		// 'pt' + 'pt-br' against a single Portuguese language). One object per
		// (group, language) is a hard invariant — a second code sharing the id
		// would make link_object() evict the first sibling's link from every
		// shared trid. Keep the first claimant, skip and report the rest.
		// Mirrors PolylangImporter::build_language_map().
		$claimed_by = [];

		foreach ( $wpml_langs as $wpml_code ) {
			$wpml_code = sanitize_text_field( $wpml_code );

			// Skip blank codes. WPML's icl_translations carries rows with an
			// empty language_code (orphaned auto-draft / comment / package
			// element rows). A blank code would prefix-match every locale and
			// import every blank/NULL-code row as the site default. Leaving ''
			// unmapped makes those rows skip cleanly.
			if ( $wpml_code === '' ) {
				continue;
			}

			// Slug, then WPML's own locale for the code, then a locale prefix;
			// see match_language(). The slug pass comes first so a language
			// whose locale merely starts with the code (en_GB for "en") never
			// shadows the language whose slug is the code.
			$matched = $this->match_language( $wpml_code, $active_languages );

			if ( $matched !== null ) {
				if ( isset( $claimed_by[ (int) $matched->id ] ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: first WPML language code, 2: colliding WPML language code, 3: colliding code again */
						__( 'WPML languages "%1$s" and "%2$s" both map to the same PerfLocale language — "%3$s" was skipped. Add a distinct PerfLocale language for it and re-run.', 'perflocale' ),
						$claimed_by[ (int) $matched->id ],
						$wpml_code,
						$wpml_code
					);
					continue;
				}

				$claimed_by[ (int) $matched->id ] = $wpml_code;
				$this->language_map[ $wpml_code ] = (int) $matched->id;
			} else {
				$result['errors'][] = sprintf(
					/* translators: %s: WPML language code */
					__( 'No PerfLocale language match for WPML code "%s".', 'perflocale' ),
					$wpml_code
				);
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Import post translations from WPML.
	 *
	 * Groups WPML translations by trid and creates PerfLocale
	 * translation groups with links for each language version.
	 *
	 * @param array<string, mixed> $result Import result (passed by reference for errors).
	 * @phpstan-param ImportResult $result
	 * @return int Number of posts imported.
	 */
	private function import_post_translations( array &$result ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$icl_table = $this->wpdb->prefix . 'icl_translations';
		$imported  = 0;

		// Bounded memory: fetch DISTINCT trids first (one BIGINT per row),
		// chunk them, then per chunk SELECT only those trids' rows and process
		// — rather than one big SELECT holding every row in memory before
		// grouping (~15 MB of PHP arrays on a 50k-post bilingual install).

		[ $skip_sql, $skip_values ] = $this->skipped_types_clause( 'element_type' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$trids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT trid FROM %i
				WHERE element_type LIKE %s AND element_id IS NOT NULL{$skip_sql}
				ORDER BY trid ASC",
				array_merge( [ $icl_table, 'post_%' ], $skip_values )
			)
		);

		$read_error = $this->read_error();

		if ( $read_error !== '' ) {
			$this->record_read_error( ObjectType::Post, $read_error );
			return 0;
		}

		if ( empty( $trids ) ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $trids ), $batch_size );

		$this->beat( 'posts', 0, count( $trids ) );

		foreach ( $batches as $batch_index => $batch ) {
			// $placeholders is a generated '%d,%d,...' string sized to the
			// current batch — safe to interpolate into the IN() clause.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// The icl_translations table identifier is class-controlled and bound to the current blog's wpdb prefix.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT translation_id, trid, element_id, language_code, element_type
					FROM %i
					WHERE element_type LIKE %s
					AND element_id IS NOT NULL
					AND trid IN ($placeholders){$skip_sql}
					ORDER BY trid ASC, translation_id ASC",
					array_merge( [ $icl_table ], [ 'post_%' ], $batch, $skip_values )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Skip only this batch: the ones after it still import.
			$read_error = $this->read_error();

			if ( $read_error !== '' ) {
				$this->record_read_error( ObjectType::Post, $read_error );
				continue;
			}

			if ( ! is_array( $rows ) ) {
				continue;
			}

			// Group this batch's rows by trid.
			$groups_by_trid = [];
			foreach ( $rows as $row ) {
				$groups_by_trid[ $row->trid ][] = $row;
			}

			$imported += $this->process_trid_groups( ObjectType::Post, $groups_by_trid, $result );

			// Bound worker memory on huge legacy sites: drop runtime object-cache
			// copies + SAVEQUERIES log accumulated by this batch (re-fetched on
			// demand; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			$this->beat( 'posts', min( count( $trids ), ( $batch_index + 1 ) * $batch_size ), count( $trids ) );
		}

		$this->beat( 'posts', count( $trids ), count( $trids ) );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $imported;
	}

	/**
	 * Group and link one batch of WPML translation sets (trids) of posts or terms.
	 *
	 * WPML writes an icl_translations row (with authoritative language) for
	 * every element, including untranslated ones that form a single-row
	 * trid. A lone default-language item needs no group: PerfLocale's
	 * default-language fallback resolves it. A lone item in any other
	 * language gets a group, or it would be served as the default language.
	 *
	 * A row is never linked in a way that silently moves or unlinks another
	 * item. These rows are skipped and counted in the result's `skipped`
	 * map ({@see skip_row()}):
	 * - `missing_object`: the post or term no longer exists;
	 * - `unmapped_language`: the language has no PerfLocale language;
	 * - `duplicate_language` / `duplicate_item`: a second row of a language,
	 *   or of an item, the set already has (the row with the lowest WPML
	 *   translation_id is kept);
	 * - `several_sets`: the item is listed in another set too
	 *   ({@see find_rows_in_several_sets()});
	 * - `language_taken`: the group the set reuses holds one of the set's
	 *   languages for an item that is not in the set, so linking would unlink
	 *   that item; the whole set is left as it is;
	 * - `other_set_group`: the group the set would join through its items is
	 *   recorded for another WPML set that WPML still lists; the whole set is
	 *   left as it is and is not recorded onto that group;
	 * - `split_groups`: the set's items sit in two or more PerfLocale groups
	 *   that also hold items outside the set, so linking them together would
	 *   move an item away from the others; the whole set is left as it is.
	 *
	 * @param ObjectType                     $type           Post or Term.
	 * @param array<array<int, object>>      $groups_by_trid trid => rows, in translation_id order.
	 * @param array<string, mixed>           $result         Import result (errors).
	 * @phpstan-param ImportResult $result
	 * @return int Number of group + link writes that succeeded.
	 */
	private function process_trid_groups( ObjectType $type, array $groups_by_trid, array &$result ): int {
		$imported = 0;
		$is_post  = ObjectType::Post === $type;
		$kind     = $is_post ? 'post' : 'term';

		$default_lang    = $this->languages->get_default();
		$default_lang_id = $default_lang !== null ? (int) $default_lang->id : 0;

		foreach ( $groups_by_trid as $trid => $trid_rows ) {
			$trid = (int) $trid;

			// Rows find_rows_in_several_sets() gave to another set are already counted.
			$trid_rows = array_values(
				array_filter(
					$trid_rows,
					fn( $row ): bool => ! isset( $this->rows_in_other_sets[ (int) ( $row->translation_id ?? 0 ) ] )
				)
			);

			if ( $trid_rows === [] ) {
				continue;
			}

			$members = [];
			$langs   = [];
			$objects = [];

			foreach ( $trid_rows as $row ) {
				$lang_id   = $this->language_map[ $row->language_code ] ?? null;
				$object_id = $is_post ? (int) $row->element_id : (int) ( $row->resolved_term_id ?? 0 );
				$shown_id  = $object_id > 0 ? $object_id : (int) $row->element_id;

				if ( $lang_id === null ) {
					$this->skip_row( 'unmapped_language', $trid, $kind, $shown_id );
					continue;
				}

				// resolved_term_id is the real term_id (from the wp_term_taxonomy
				// JOIN); element_id is WPML's term_taxonomy_id, never a term_id.
				if ( $object_id <= 0 || ! ( $is_post ? get_post( $object_id ) : term_exists( $object_id ) ) ) {
					$this->skip_row( 'missing_object', $trid, $kind, $shown_id );
					continue;
				}

				if ( isset( $langs[ (int) $lang_id ] ) ) {
					$this->skip_row( 'duplicate_language', $trid, $kind, $object_id );
					continue;
				}

				if ( isset( $objects[ $object_id ] ) ) {
					$this->skip_row( 'duplicate_item', $trid, $kind, $object_id );
					continue;
				}

				$langs[ (int) $lang_id ] = true;
				$objects[ $object_id ]   = true;
				$members[]               = [
					'row'    => $row,
					'object' => $object_id,
					'lang'   => (int) $lang_id,
				];
			}

			if ( $members === [] ) {
				continue;
			}

			$first = $members[0];

			// A single-row trid is only worth a group when its language is
			// non-default.
			if ( count( $trid_rows ) < 2 && $first['lang'] === $default_lang_id ) {
				continue;
			}

			// Reuse path #1: the source map (committed in the same transaction
			// as the group). Confirm the group still exists: a row left by a
			// pre-cascade delete or a manual removal must not link items to a
			// dead group. Reuse path #2: a group the set's items already
			// belong to (a group PerfLocale gave them on save, or an import
			// from before the source map existed), see member_group().
			$source_key = $trid . '|' . $kind;
			$mapped_id  = $this->source_map->get_group_id( 'wpml', $source_key );
			$existing   = $mapped_id !== null ? $this->groups->find( $mapped_id ) : null;

			if ( ! $existing ) {
				$member_group = $this->member_group( $members, $type );

				if ( $member_group < 0 ) {
					foreach ( $members as $member ) {
						$this->skip_row( 'split_groups', $trid, $kind, $member['object'] );
					}

					continue;
				}

				$existing = $member_group > 0 ? $this->groups->find( $member_group ) : null;
			}

			if ( $existing ) {
				$group_id   = (int) $existing->id;
				$seed_index = 0;

				// A group recorded for another WPML set is that set's: joining it
				// would merge two WPML sets into one group. A recorded set whose
				// trid WPML no longer lists (its tables were rebuilt) owns
				// nothing any more.
				$other_keys = $mapped_id !== $group_id
					? array_values( array_diff( $this->source_map->keys_for_group( 'wpml', $group_id ), [ $source_key ] ) )
					: [];

				if ( $other_keys !== [] && $this->any_trid_listed( $other_keys ) ) {
					foreach ( $members as $member ) {
						$this->skip_row( 'other_set_group', $trid, $kind, $member['object'] );
					}

					continue;
				}

				if ( $this->set_would_unlink_others( $group_id, $members, $type ) ) {
					foreach ( $members as $member ) {
						$this->skip_row( 'language_taken', $trid, $kind, $member['object'] );
					}

					continue;
				}

				// Converge the source map on the reuse path too (INSERT .. ON
				// DUPLICATE KEY UPDATE: idempotent, also repairs a stale row),
				// keeping one key per group.
				if ( $mapped_id !== $group_id ) {
					foreach ( $other_keys as $stale_key ) {
						$this->source_map->delete_key( 'wpml', $stale_key );
					}

					$this->source_map->set_group_id( 'wpml', $source_key, $group_id );
				}
			} else {
				$new_group_id = $this->groups->create_group(
					$type,
					$first['object'],
					$first['lang'],
					$this->link_status( $type, $first['object'] ),
					SourceType::ImportedWpml,
					[
						'type' => 'wpml',
						'key'  => $source_key,
					]
				);

				if ( $new_group_id === false ) {
					$result['errors'][] = $is_post
						? sprintf(
							/* translators: %d: WPML translation set ID (trid) */
							__( 'Failed to create group for WPML trid %d.', 'perflocale' ),
							$trid
						)
						: sprintf(
							/* translators: %s: WPML source key of a term translation set */
							__( 'Failed to create group for WPML term translation (source key %s).', 'perflocale' ),
							$source_key
						);
					continue;
				}

				$group_id   = (int) $new_group_id;
				$seed_index = 1;
				++$imported;
			}

			// Link every member - including the first when an existing group is
			// reused, since it may sit in another group or under another
			// language, and link_object() moves it.
			for ( $i = $seed_index, $count = count( $members ); $i < $count; $i++ ) {
				$member = $members[ $i ];

				// Skip a member already in this group with this language: BOTH
				// halves matter. PerfLocale files every new post under the default
				// language on save, so an item can sit in this group under the
				// wrong language.
				$already = $this->groups->find_link_for_object( $member['object'], $type );

				if ( $already
					&& (int) $already->group_id === $group_id
					&& (int) $already->language_id === $member['lang'] ) {
					continue;
				}

				$link_result = $this->groups->link_object(
					$group_id,
					$member['object'],
					$member['lang'],
					$this->link_status( $type, $member['object'] ),
					SourceType::ImportedWpml
				);

				if ( $link_result !== false ) {
					++$imported;
				}
			}

			if ( ! $is_post && (string) $first['row']->element_type === 'tax_nav_menu' ) {
				$this->link_menu_set( array_column( $members, 'row' ), $result );
			}
		}

		return $imported;
	}

	/**
	 * The PerfLocale group a set with no recorded group joins, from its items' groups.
	 *
	 * With one group among the items, that group. With several, the one that
	 * also holds items outside the set (the others hold set items only, so
	 * moving those leaves nothing behind); when none does, the group of the
	 * first item that has one. When two or more hold items outside the set,
	 * every choice would move a set item away from items that stay behind.
	 * The result does not depend on the order of the set's rows, except for
	 * which of several set-only groups is kept.
	 *
	 * @param list<array{row: object, object: int, lang: int}> $members The set's items.
	 * @param ObjectType                                       $type    Post or Term.
	 * @return int Group ID; 0 when no item has a group; -1 to refuse the set.
	 */
	private function member_group( array $members, ObjectType $type ): int {
		$in_set = array_column( $members, 'object' );
		$groups = [];

		foreach ( $members as $member ) {
			$links = $this->groups->get_translations( $member['object'], $type );
			$own   = null;

			foreach ( $links as $link ) {
				if ( (int) ( $link->object_id ?? 0 ) === $member['object'] ) {
					$own = (int) $link->group_id;
					break;
				}
			}

			if ( $own === null || isset( $groups[ $own ] ) ) {
				continue;
			}

			$groups[ $own ] = false;

			foreach ( $links as $link ) {
				if ( ! in_array( (int) ( $link->object_id ?? 0 ), $in_set, true ) ) {
					$groups[ $own ] = true;
					break;
				}
			}
		}

		if ( $groups === [] ) {
			return 0;
		}

		$holding = array_keys( array_filter( $groups ) );

		if ( count( $holding ) > 1 ) {
			return -1;
		}

		return (int) ( $holding[0] ?? array_key_first( $groups ) );
	}

	/**
	 * Whether WPML still lists any of these source-map keys' trids.
	 *
	 * A key that is not of the "<trid>|<kind>" form, or a read error, counts
	 * as listed.
	 *
	 * @param string[] $keys Source-map keys.
	 * @return bool
	 */
	private function any_trid_listed( array $keys ): bool {
		$trids = [];

		foreach ( $keys as $key ) {
			$trid = (int) strstr( $key, '|', true );

			if ( $trid <= 0 ) {
				return true;
			}

			$trids[] = $trid;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $trids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One indexed lookup on a refusal path; the placeholders are %d only.
		$listed = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM %i WHERE trid IN ( {$placeholders} ) LIMIT 1",
				$this->wpdb->prefix . 'icl_translations',
				...$trids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $listed !== null || $this->wpdb->last_error !== '';
	}

	/**
	 * Whether linking a set into an existing group would unlink an item outside the set.
	 *
	 * The link_object() call replaces the group's link for a language. When that link
	 * belongs to an item the WPML set does not list, the replacement unlinks
	 * it without a word (served as the default language from then on).
	 *
	 * @param int                                              $group_id Group the set would join.
	 * @param list<array{row: object, object: int, lang: int}> $members  The set's items.
	 * @param ObjectType                                       $type     Post or Term.
	 * @return bool
	 */
	private function set_would_unlink_others( int $group_id, array $members, ObjectType $type ): bool {
		$in_set = array_column( $members, 'object' );

		foreach ( $members as $member ) {
			$already = $this->groups->find_link_for_object( $member['object'], $type );

			if ( $already && (int) $already->group_id === $group_id && (int) $already->language_id === $member['lang'] ) {
				continue;
			}

			$holder = $this->groups->object_in_group_language( $group_id, $member['lang'] );

			if ( $holder > 0 && ! in_array( $holder, $in_set, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The link status an imported item gets.
	 *
	 * A post's comes from its real status: a WPML draft translation under a
	 * published source must not be imported as published, or hreflang and
	 * the switcher would offer a public URL for a non-public post. Every
	 * non-publish state is Draft. Terms are Published.
	 *
	 * @param ObjectType $type      Post or Term.
	 * @param int        $object_id Post or term ID.
	 * @return string
	 */
	private function link_status( ObjectType $type, int $object_id ): string {
		if ( ObjectType::Post !== $type || get_post_status( $object_id ) === 'publish' ) {
			return TranslationStatus::Published->value;
		}

		return TranslationStatus::Draft->value;
	}

	/**
	 * Find the rows of items WPML lists in more than one translation set.
	 *
	 * A post or term belongs to one PerfLocale group, so only one of its rows
	 * can be imported. WPML leaves such rows after a post-type change or when
	 * a plugin re-registers an item. The row kept is:
	 * - the only row whose element type matches the item's real post type or
	 *   taxonomy, when exactly one does;
	 * - otherwise, when the remaining rows all give the item one language,
	 *   the row in the largest set (then the lowest trid);
	 * - otherwise none: all of the item's rows are skipped and its links stay
	 *   as they are.
	 * Every other row is counted as `several_sets` and left out by
	 * {@see process_trid_groups()}. One read per import and kind.
	 *
	 * @param ObjectType $type Post or Term.
	 * @return void
	 */
	private function find_rows_in_several_sets( ObjectType $type ): void {
		$is_post = ObjectType::Post === $type;
		$icl     = $this->wpdb->prefix . 'icl_translations';
		$like    = $is_post ? 'post_%' : 'tax_%';
		$kind    = $is_post ? 'post' : 'term';

		[ $skip_c, $skip_values ] = $this->skipped_types_clause( 'c.element_type' );
		[ $skip_d ]               = $this->skipped_types_clause( 'element_type' );
		[ $skip_icl ]             = $this->skipped_types_clause( 'icl.element_type' );

		// Fixed fragments per kind: the item's own table, its id column and its type column.
		$object_sql = $is_post
			? 'icl.element_id AS object_id, obj.post_type AS real_type'
			: 'obj.term_id AS object_id, obj.taxonomy AS real_type';
		$join_sql   = $is_post
			? 'LEFT JOIN %i obj ON obj.ID = icl.element_id'
			: 'LEFT JOIN %i obj ON obj.term_taxonomy_id = icl.element_id';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $object_sql/$join_sql are fixed fragments and the skip clauses generated placeholders; every value is bound through prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT icl.translation_id, icl.element_id, icl.trid, icl.language_code, icl.element_type, {$object_sql},
					( SELECT COUNT(*) FROM %i c WHERE c.trid = icl.trid AND c.element_id IS NOT NULL AND c.element_type LIKE %s{$skip_c} ) AS set_size
				FROM %i icl
				INNER JOIN (
					SELECT element_id FROM %i
					WHERE element_type LIKE %s AND element_id IS NOT NULL{$skip_d}
					GROUP BY element_id
					HAVING COUNT(DISTINCT trid) > 1
				) several ON several.element_id = icl.element_id
				{$join_sql}
				WHERE icl.element_type LIKE %s{$skip_icl}
				ORDER BY icl.element_id ASC, icl.trid ASC, icl.translation_id ASC",
				array_merge(
					[ $icl, $like ],
					$skip_values,
					[ $icl, $icl, $like ],
					$skip_values,
					[ $is_post ? $this->wpdb->posts : $this->wpdb->term_taxonomy, $like ],
					$skip_values
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$error = $this->read_error();

		if ( $error !== '' ) {
			// The import still runs: set_would_unlink_others() keeps every
			// existing link, only the choice of row is lost.
			self::log_db_error( 'looking for items listed in several WPML sets', $error );
			return;
		}

		$by_element = [];

		foreach ( (array) $rows as $row ) {
			$by_element[ (int) $row->element_id ][] = $row;
		}

		foreach ( $by_element as $item_rows ) {
			// A missing item: process_trid_groups() skips each of its rows as missing_object.
			if ( $item_rows[0]->real_type === null ) {
				continue;
			}

			$object_id = (int) $item_rows[0]->object_id;
			$real      = ( $is_post ? 'post_' : 'tax_' ) . (string) $item_rows[0]->real_type;
			$match     = array_values( array_filter( $item_rows, static fn( $r ): bool => (string) $r->element_type === $real ) );
			$cands     = $match !== [] ? $match : $item_rows;
			$keep      = null;

			if ( count( $cands ) === 1 ) {
				$keep = $cands[0];
			} elseif ( count( array_unique( array_map( static fn( $r ): string => (string) $r->language_code, $cands ) ) ) === 1 ) {
				usort( $cands, static fn( $a, $b ): int => [ (int) $b->set_size, (int) $a->trid ] <=> [ (int) $a->set_size, (int) $b->trid ] );
				$keep = $cands[0];
			}

			foreach ( $item_rows as $row ) {
				if ( $keep !== null && (int) $row->translation_id === (int) $keep->translation_id ) {
					continue;
				}

				$this->rows_in_other_sets[ (int) $row->translation_id ] = true;
				$this->skip_row( 'several_sets', (int) $row->trid, $kind, $object_id );
			}
		}
	}

	/**
	 * Count one skipped icl_translations row under its reason, with a few examples.
	 *
	 * @param string $reason    Reason key (see {@see process_trid_groups()}).
	 * @param int    $trid      WPML trid.
	 * @param string $kind      post or term.
	 * @param int    $object_id Post or term ID (term_taxonomy_id when the term is missing).
	 * @return void
	 */
	private function skip_row( string $reason, int $trid, string $kind, int $object_id ): void {
		$this->skipped[ $reason ] = ( $this->skipped[ $reason ] ?? 0 ) + 1;

		if ( count( $this->skipped_examples[ $reason ] ?? [] ) < self::SKIPPED_EXAMPLES ) {
			$this->skipped_examples[ $reason ][] = sprintf( 'trid %d (%s %d)', $trid, $kind, $object_id );
		}
	}

	/**
	 * One line per reason for the rows an import skipped, for WP-CLI and the admin notice.
	 *
	 * @param array<string, mixed> $result Import result with `skipped` and `skipped_examples`.
	 * @return list<string>
	 */
	public static function skipped_lines( array $result ): array {
		$lines    = [];
		$examples = (array) ( $result['skipped_examples'] ?? [] );

		foreach ( (array) ( $result['skipped'] ?? [] ) as $reason => $count ) {
			$count = (int) $count;

			if ( $count <= 0 ) {
				continue;
			}

			$line = match ( (string) $reason ) {
				'missing_object' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was skipped: its post or term no longer exists.', '%d WPML rows were skipped: their posts or terms no longer exist.', $count, 'perflocale' ),
				'unmapped_language' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was skipped: its language has no PerfLocale language.', '%d WPML rows were skipped: their languages have no PerfLocale language.', $count, 'perflocale' ),
				'duplicate_language' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was skipped: its translation set already has an item in that language (the first one was kept).', '%d WPML rows were skipped: their translation sets already have an item in that language (the first one was kept).', $count, 'perflocale' ),
				'duplicate_item' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was skipped: its item is already in the translation set in another language (the first row was kept).', '%d WPML rows were skipped: their items are already in the translation set in another language (the first row was kept).', $count, 'perflocale' ),
				'several_sets' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was skipped: its item is listed in more than one translation set.', '%d WPML rows were skipped: their items are listed in more than one translation set.', $count, 'perflocale' ),
				'language_taken' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was not linked: its translation set would unlink another item that holds one of its languages in PerfLocale.', '%d WPML rows were not linked: their translation sets would unlink other items that hold one of their languages in PerfLocale.', $count, 'perflocale' ),
				'other_set_group' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was not linked: its item already belongs to the translation group of another WPML set.', '%d WPML rows were not linked: their items already belong to the translation group of another WPML set.', $count, 'perflocale' ),
				'split_groups' =>
					/* translators: %d: number of WPML rows */
					_n( '%d WPML row was not linked: the items of its translation set belong to different PerfLocale translation groups that also hold other items.', '%d WPML rows were not linked: the items of their translation sets belong to different PerfLocale translation groups that also hold other items.', $count, 'perflocale' ),
				default => '',
			};

			if ( $line === '' ) {
				continue;
			}

			$line = sprintf( $line, $count );

			if ( ! empty( $examples[ $reason ] ) ) {
				/* translators: %s: comma-separated examples such as "trid 42 (post 7)" */
				$line .= ' ' . sprintf( __( 'For example: %s.', 'perflocale' ), implode( ', ', array_map( static fn( $example ): string => is_scalar( $example ) ? (string) $example : '', (array) $examples[ $reason ] ) ) );
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Resolve the per-batch chunk size for both post + term import paths.
	 * Filterable via `perflocale/migration/wpml/batch_size`. Default 100
	 * trids per batch — at ~2 langs each that's ~200 wp_icl_translations
	 * rows per SELECT. Clamped to 10–1000.
	 *
	 * @return int
	 */
	private static function resolve_batch_size(): int {
		/**
		 * Per-batch chunk size during WPML migration. Default 100 trids.
		 *
		 * Lower = smaller memory peak per batch, more SELECT round-trips.
		 * Higher = bigger memory peak, fewer round-trips. The bottleneck on
		 * large migrations is typically the per-row `get_post` /
		 * `find_for_object` chain, not the SELECT, so the default sits in
		 * the middle of the safe range.
		 *
		 * @hook perflocale/migration/wpml/batch_size
		 * @param int $size Default 100.
		 */
		$size = (int) apply_filters( 'perflocale/migration/wpml/batch_size', 100 );
		return max( 10, min( 1000, $size ) );
	}

	/**
	 * Import term translations from WPML.
	 *
	 * @param array<string, mixed> $result Import result (passed by reference for errors).
	 * @phpstan-param ImportResult $result
	 * @return int Number of terms imported.
	 */
	private function import_term_translations( array &$result ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$icl_table = $this->wpdb->prefix . 'icl_translations';
		$imported  = 0;

		[ $skip_sql, $skip_values ] = $this->skipped_types_clause( 'element_type' );
		[ $skip_sql_icl ]           = $this->skipped_types_clause( 'icl.element_type' );

		// Same batched-fetch refactor as import_post_translations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$trids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT trid FROM %i
				WHERE element_type LIKE %s AND element_id IS NOT NULL{$skip_sql}
				ORDER BY trid ASC",
				array_merge( [ $icl_table, 'tax_%' ], $skip_values )
			)
		);

		$read_error = $this->read_error();

		if ( $read_error !== '' ) {
			$this->record_read_error( ObjectType::Term, $read_error );
			return 0;
		}

		if ( empty( $trids ) ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $trids ), $batch_size );

		$this->beat( 'terms', 0, count( $trids ) );

		foreach ( $batches as $batch_index => $batch ) {
			// $placeholders is a generated '%d,%d,...' string sized to the
			// current batch — safe to interpolate into the IN() clause.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// The icl_translations table identifier is class-controlled and bound to the current blog's wpdb prefix.
			// WPML stores the TERM TAXONOMY id (not the term_id) in
			// icl_translations.element_id for taxonomy rows. Resolve the real
			// term_id via wp_term_taxonomy so the group links to a term WP can
			// actually find — using element_id directly links the wrong term
			// (term_id != term_taxonomy_id on most sites) or silently no-ops.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT icl.translation_id, icl.trid, icl.element_id, icl.language_code, icl.element_type, tt.term_id AS resolved_term_id
					FROM %i icl
					LEFT JOIN {$this->wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = icl.element_id
					WHERE icl.element_type LIKE %s
					AND icl.element_id IS NOT NULL
					AND icl.trid IN ($placeholders){$skip_sql_icl}
					ORDER BY icl.trid ASC, icl.translation_id ASC",
					array_merge( [ $icl_table ], [ 'tax_%' ], $batch, $skip_values )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$read_error = $this->read_error();

			if ( $read_error !== '' ) {
				$this->record_read_error( ObjectType::Term, $read_error );
				continue;
			}

			if ( ! is_array( $rows ) ) {
				continue;
			}

			$groups_by_trid = [];
			foreach ( $rows as $row ) {
				$groups_by_trid[ $row->trid ][] = $row;
			}

			$imported += $this->process_trid_groups( ObjectType::Term, $groups_by_trid, $result );

			// Bound worker memory on huge legacy sites: drop runtime object-cache
			// copies + SAVEQUERIES log accumulated by this batch (re-fetched on
			// demand; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			$this->beat( 'terms', min( count( $trids ), ( $batch_index + 1 ) * $batch_size ), count( $trids ) );
		}

		$this->beat( 'terms', count( $trids ), count( $trids ) );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $imported;
	}

	/**
	 * Give the menus of one WPML nav_menu translation set their PerfLocale languages and links.
	 *
	 * MenuManager picks the menu for the visitor's language from term meta
	 * only, so the translation group alone changes nothing on the front end.
	 * A menu WPML had in one language only gets no language: its items keep
	 * being pointed at each language's pages, as for any shared menu.
	 *
	 * @param array<int, object>   $trid_rows The trid's icl_translations rows.
	 * @param array<string, mixed> $result    Import result (menus, errors).
	 * @phpstan-param ImportResult $result
	 * @return void
	 */
	private function link_menu_set( array $trid_rows, array &$result ): void {
		$slugs = $this->slugs_by_id();
		$set   = [];

		foreach ( $trid_rows as $row ) {
			$lang_id = $this->language_map[ $row->language_code ] ?? null;
			$term_id = (int) ( $row->resolved_term_id ?? 0 );

			if ( $lang_id === null || $term_id <= 0 || ! isset( $slugs[ $lang_id ] ) ) {
				continue;
			}

			$set[ $slugs[ $lang_id ] ] = $term_id;
		}

		if ( count( $set ) < 2 ) {
			return;
		}

		$linked = \PerfLocale\Translation\MenuManager::link_imported_menus( $set );

		if ( $linked['conflict'] !== null ) {
			$result['errors'][] = sprintf(
				/* translators: %s: comma-separated menu term IDs */
				__( 'WPML menu set (menus %s) was not linked: one of its menus already has a different language or link in PerfLocale.', 'perflocale' ),
				implode( ', ', array_map( 'intval', array_values( $set ) ) )
			);
			return;
		}

		if ( $linked['written'] > 0 ) {
			++$result['menus'];
		}
	}

	/**
	 * Import string translations from WPML's icl_strings and icl_string_translations tables.
	 *
	 * @param array<string, mixed> $result Import result; this method appends to `errors` (skipped rows, failed link writes).
	 * @phpstan-param ImportResult $result
	 * @return int Number of strings imported.
	 */
	private function import_string_translations( array &$result ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$strings_table   = $this->wpdb->prefix . 'icl_strings';
		$str_trans_table = $this->wpdb->prefix . 'icl_string_translations';
		$imported        = 0;
		$link_failures   = 0;

		// Check if strings table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$strings_table
			)
		);

		if ( (int) $table_exists === 0 ) {
			return 0;
		}

		// Hoist the StringTranslationRepository OUT of the per-row loop. The
		// repo is stateless beyond its CacheManager dependency, so a single
		// instance services every iteration. Previously a `new` ran on every
		// row — measurable on a 50k-string WPML site (50k allocations of
		// objects + their wp_cache_get bootstraps) and pure waste.
		$str_trans = new \PerfLocale\Database\Repository\StringTranslationRepository( $this->cache );

		// Keyset-paginated batch loop. Replaces a single SELECT that pulled
		// every WPML string translation into PHP memory at once — which on
		// a 50k-string WPML export was ~25 MB of PHP arrays before the loop
		// even started running and reliably OOM'd the default 256M PHP
		// memory limit. WHERE s.id > $last_id + ORDER BY s.id ASC + LIMIT N
		// is also faster than OFFSET-based pagination because the index seek
		// is constant per batch instead of linear in the offset.
		//
		// Batch size is filterable so very-large-string sites can tune it
		// down (longer per-string values) or hosted environments with
		// generous memory can crank it up to reduce DB round-trips.
		$batch_size = (int) apply_filters( 'perflocale/migration/wpml_string_batch_size', 500 );

		if ( $batch_size < 1 ) {
			$batch_size = 500;
		}

		$last_id          = 0;
		$skipped_unmapped = 0;
		$skipped_insert   = 0;
		$domains          = [];
		$done             = 0;
		$total            = 0;

		$this->beat( 'strings', 0, 0 );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					// WPML's icl_strings has NO `domain_name` column: the text
					// domain lives in `context` and the gettext msgctxt in
					// `gettext_context`. Selecting the nonexistent column made
					// the whole query error → zero WPML string translations
					// imported. Alias to the names the consumer already expects.
					"SELECT s.id, s.value, s.context AS domain_name, s.gettext_context AS string_context,
							st.id AS st_id, st.language, st.value AS translated_value
					FROM %i s
					INNER JOIN %i st ON st.string_id = s.id
					WHERE st.status IN (%d, %d)
					AND st.value IS NOT NULL
					AND st.value != ''
					AND st.id > %d
					ORDER BY st.id ASC
					LIMIT %d",
					$strings_table,
					$str_trans_table,
					10, // ICL_TM_COMPLETE
					3,  // ICL_TM_NEEDS_UPDATE — WPML still SERVES these (existing
					// translation shown, flagged for review); status=10 alone
						// silently dropped every needs-update translation.
					$last_id,
					$batch_size
				)
			);

			// A non-empty last_error means the batch SELECT itself failed (a
			// WPML schema the aliases don't match, or a missing
			// icl_string_translations table). Surface it instead of breaking
			// out as if the import cleanly finished with zero rows.
			if ( $this->wpdb->last_error !== '' ) {
				self::log_db_error( 'reading string translations', $this->wpdb->last_error );

				$result['errors'][] = sprintf(
					/* translators: %s: source plugin name */
					__( 'Some %s string translations could not be read because of a database error and were not imported. Re-run the import.', 'perflocale' ),
					'WPML'
				);
				break;
			}

			if ( ! is_array( $rows ) || $rows === [] ) {
				break;
			}

			$imported += $this->import_string_batch( $rows, $str_trans, $domains, $skipped_unmapped, $skipped_insert, $link_failures );

			// Advance keyset cursor. Even if the last batch was short, we
			// still capture the highest id so a partial-fail+restart can
			// resume cleanly (file/data import jobs use the same idiom).
			$last_id   = (int) end( $rows )->st_id;
			$row_count = count( $rows );

			// Release the batch array reference so PHP can reclaim the
			// memory before the next iteration. Critical on big sites:
			// without this, gc_collect_cycles() can leave the previous
			// batch in scope until the do-while condition is evaluated.
			unset( $rows );

			// Bound worker memory between streamed batches (runtime cache +
			// SAVEQUERIES log only; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

			$done += $row_count;

			if ( $total === 0 && $row_count >= $batch_size && $this->reports_progress() ) {
				$total = $this->count_string_translations( $strings_table, $str_trans_table );
			}

			$this->beat( 'strings', $done, $total );

			// Partial batch → end of data. Avoids one wasted round-trip
			// that would just return zero rows under the cursor we just
			// advanced past.
			if ( $row_count < $batch_size ) {
				break;
			}
		} while ( true );

		$this->beat( 'strings', $done, $done );

		if ( $link_failures > 0 ) {
			$result['errors'][] = sprintf(
				/* translators: %d: number of failed translation-link writes */
				_n( 'WPML string import: %d translation link write failed — the affected strings are stored but not served; re-run the import to repair them.', 'WPML string import: %d translation link writes failed — the affected strings are stored but not served; re-run the import to repair them.', $link_failures, 'perflocale' ),
				$link_failures
			);
		}

		if ( $skipped_unmapped > 0 || $skipped_insert > 0 ) {
			$result['errors'][] = sprintf(
				/* translators: 1: total skipped, 2: unmapped-language count, 3: insert-failure count */
				_n( 'WPML string import skipped %1$d translation: %2$d with no matching PerfLocale language, %3$d that failed to insert.', 'WPML string import skipped %1$d translations: %2$d with no matching PerfLocale language, %3$d that failed to insert.', $skipped_unmapped + $skipped_insert, 'perflocale' ),
				$skipped_unmapped + $skipped_insert,
				$skipped_unmapped,
				$skipped_insert
			);
		}

		$result['protected_domains'] = $this->protect_imported_domains( array_keys( $domains ) );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $imported;
	}

	/**
	 * Import one batch of WPML string translations.
	 *
	 * Reads the batch's strings, translations and links with one query each
	 * and writes inside one transaction, so a batch costs a few round trips
	 * and one commit instead of several commits per row. Every row ends as a
	 * row-by-row import leaves it: a missing string is created, an existing
	 * translation is never replaced, and the link is written unless it
	 * already links this string as an imported translation.
	 *
	 * @param object[]                    $rows             Batch rows: id, value, domain_name, string_context, st_id, language, translated_value.
	 * @param StringTranslationRepository $str_trans        Translations.
	 * @param array<string, true>         $domains          Domains holding an imported translation; added to.
	 * @param int                         $skipped_unmapped Rows in a language with no match; added to.
	 * @param int                         $skipped_insert   Rows whose string could not be created; added to.
	 * @param int                         $link_failures    Link writes that failed; added to.
	 * @return int Translations written.
	 */
	private function import_string_batch( array $rows, StringTranslationRepository $str_trans, array &$domains, int &$skipped_unmapped, int &$skipped_insert, int &$link_failures ): int {
		$items = [];

		foreach ( $rows as $row ) {
			$lang_id = $this->language_map[ $row->language ] ?? null;

			if ( $lang_id === null ) {
				++$skipped_unmapped;
				continue;
			}

			$domain   = sanitize_text_field( $row->domain_name ?? 'default' );
			$context  = sanitize_text_field( $row->string_context ?? '' );
			$original = (string) $row->value;

			$items[] = [
				'domain'   => $domain,
				'context'  => $context,
				'original' => $original,
				'lang'     => (int) $lang_id,
				'value'    => (string) $row->translated_value,
				'hash'     => StringRepository::compute_hash( $domain, $context, $original ),
			];
		}

		if ( $items === [] ) {
			return 0;
		}

		$imported = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One transaction per batch.
		$in_transaction = $this->wpdb->query( 'START TRANSACTION' ) !== false;

		try {
			$strings = $this->strings_by_hash( array_values( array_unique( array_column( $items, 'hash' ) ) ) );
			$failed  = [];
			$created = [];

			foreach ( $items as $item ) {
				if ( isset( $strings[ $item['hash'] ] ) || isset( $failed[ $item['hash'] ] ) || isset( $created[ $item['hash'] ] ) ) {
					continue;
				}

				$string_id = $this->strings->insert(
					[
						'domain'   => $item['domain'],
						'context'  => $item['context'],
						'original' => $item['original'],
					]
				);

				if ( $string_id === false ) {
					$failed[ $item['hash'] ] = true;
				} else {
					$created[ $item['hash'] ] = true;
				}
			}

			foreach ( $this->strings_by_hash( array_map( 'strval', array_keys( $created ) ) ) as $hash => $string ) {
				$strings[ $hash ] = $string;
			}

			$ids_by_lang = [];
			$group_ids   = [];

			foreach ( $items as $item ) {
				$string = $strings[ $item['hash'] ] ?? null;

				if ( $string !== null && (int) $string->group_id > 0 ) {
					$ids_by_lang[ $item['lang'] ][] = (int) $string->id;
					$group_ids[]                    = (int) $string->group_id;
				}
			}

			$have = [];

			foreach ( $ids_by_lang as $lang_id => $ids ) {
				$have[ $lang_id ] = $str_trans->get_many( array_values( array_unique( $ids ) ), (int) $lang_id );
			}

			$links  = $this->string_links_for_groups( array_values( array_unique( $group_ids ) ) );
			$to_set = [];
			$pairs  = [];

			foreach ( $items as $item ) {
				if ( isset( $failed[ $item['hash'] ] ) ) {
					++$skipped_insert;
					continue;
				}

				$string = $strings[ $item['hash'] ] ?? null;

				if ( $string === null || (int) $string->group_id <= 0 ) {
					continue;
				}

				$string_id = (int) $string->id;
				$lang_id   = $item['lang'];
				$is_new    = false;

				// Idempotency: an existing translation (e.g. a correction made
				// after an earlier import) is kept; only the link is checked.
				// The first row of the batch for a string + language wins.
				if ( ! isset( $have[ $lang_id ][ $string_id ] ) ) {
					$to_set[]                       = [ $string_id, $lang_id, $item['value'] ];
					$have[ $lang_id ][ $string_id ] = $item['value'];
					$is_new                         = true;
				}

				$pairs[] = [ $string_id, (int) $string->group_id, $lang_id, $item['domain'], $is_new ];
			}

			$saved = [];

			if ( $to_set !== [] ) {
				if ( $str_trans->set_many( $to_set ) ) {
					foreach ( $to_set as [ $string_id, $lang_id ] ) {
						$saved[ $string_id . ':' . $lang_id ] = true;
					}
				} else {
					foreach ( $to_set as [ $string_id, $lang_id, $value ] ) {
						if ( $str_trans->set( $string_id, $lang_id, $value ) ) {
							$saved[ $string_id . ':' . $lang_id ] = true;
						}
					}
				}

				$imported += count( $saved );
			}

			// The value row alone is never served: the gettext map, the
			// files-mode generator and the strings screen all join
			// translation_links. A link is written unless it already links
			// this string as an imported translation; a failed write is
			// counted, because that translation is stored but shown nowhere.
			$to_link = [];

			foreach ( $pairs as [ $string_id, $group_id, $lang_id, $domain, $is_new ] ) {
				if ( $is_new && ! isset( $saved[ $string_id . ':' . $lang_id ] ) ) {
					continue;
				}

				$domains[ $domain ] = true;
				$link               = $links[ $group_id ][ $lang_id ] ?? null;

				if ( isset( $to_link[ $group_id . ':' . $lang_id ] ) || (
					$link !== null
					&& (int) $link->object_id === $string_id
					&& (string) $link->status === 'translated'
					&& (string) $link->source === SourceType::ImportedWpml->value
				) ) {
					continue;
				}

				$to_link[ $group_id . ':' . $lang_id ] = [ $group_id, $string_id, $lang_id ];
			}

			$link_failures += $this->groups->upsert_string_links( array_values( $to_link ), SourceType::ImportedWpml );
		} finally {
			if ( $in_transaction ) {
				$this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}

		return $imported;
	}

	/**
	 * PerfLocale strings by hash, for a batch.
	 *
	 * @param string[] $hashes original_hash values.
	 * @return array<string, object> hash => row with id and group_id.
	 */
	private function strings_by_hash( array $hashes ): array {
		$out = [];

		foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $placeholders is a generated %s list bound to $chunk.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT id, group_id, original_hash FROM %i WHERE original_hash IN ({$placeholders})",
					array_merge( [ Schema::table( 'strings' ) ], $chunk )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

			foreach ( (array) $rows as $row ) {
				$out[ (string) $row->original_hash ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Existing links of a batch's string groups.
	 *
	 * @param int[] $group_ids Group ids.
	 * @return array<int, array<int, object>> group_id => language_id => row with object_id, status and source.
	 */
	private function string_links_for_groups( array $group_ids ): array {
		$out = [];

		foreach ( array_chunk( $group_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $placeholders is a generated %d list bound to $chunk.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT group_id, language_id, object_id, status, source FROM %i WHERE group_id IN ({$placeholders})",
					array_merge( [ Schema::table( 'translation_links' ) ], $chunk )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

			foreach ( (array) $rows as $row ) {
				$out[ (int) $row->group_id ][ (int) $row->language_id ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Keep the string GC away from the domains this import filled.
	 *
	 * The GC deletes strings the scanner has not seen for 90 days once a full
	 * scan ran; WPML's domains (admin texts, widgets, theme and plugin option
	 * strings) are never scanned, so their imported translations would all be
	 * deleted. Imported domains are added to `perflocale_gc_protected_domains`,
	 * which the GC honours, except domains the scanner owns (a row with a real
	 * file path): those follow the scan, which keeps a string while it is
	 * still in the code. Entries are only ever added.
	 *
	 * @param string[] $domains Domains holding an imported translation.
	 * @return list<string> Domains added to the option.
	 */
	private function protect_imported_domains( array $domains ): array {
		$domains = array_values( array_filter( array_map( 'strval', $domains ), static fn( string $d ): bool => $d !== '' ) );

		if ( $domains === [] ) {
			return [];
		}

		$scanned = [];

		foreach ( array_chunk( $domains, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $placeholders is a generated %s list bound to $chunk.
			$found = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT DISTINCT domain FROM %i WHERE domain IN ({$placeholders}) AND file_path <> '' AND file_path <> %s",
					array_merge( [ Schema::table( 'strings' ) ], $chunk, [ 'translatepress-import' ] )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

			// A failed read protects the whole chunk: keeping strings is the safe side.
			if ( $this->wpdb->last_error !== '' ) {
				continue;
			}

			foreach ( (array) $found as $domain ) {
				$scanned[ (string) $domain ] = true;
			}
		}

		$current = array_values( array_filter( array_map( 'strval', (array) get_option( 'perflocale_gc_protected_domains', [] ) ) ) );
		$added   = [];

		foreach ( $domains as $domain ) {
			if ( ! isset( $scanned[ $domain ] ) && ! in_array( $domain, $current, true ) ) {
				$added[] = $domain;
			}
		}

		if ( $added !== [] ) {
			update_option( 'perflocale_gc_protected_domains', array_values( array_merge( $current, $added ) ), false );
		}

		return $added;
	}

	/**
	 * Re-attach imported posts that sit on a category or tag of another language.
	 *
	 * WPML hid such assignments; PerfLocale lists a post under every term it is
	 * attached to, whatever the term's language. Each affected post goes
	 * through TermAssignmentFilter::normalize_post_terms(), which swaps a
	 * wrong-language term for its translation in the post's language and
	 * leaves it in place when there is none. A term without a language link
	 * counts as the default language.
	 *
	 * @param array<string, mixed> $result Import result (term_languages, errors).
	 * @phpstan-param ImportResult $result
	 * @return void
	 */
	private function check_term_languages( array &$result ): void {
		$plugin = \PerfLocale\Plugin::get_instance();
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
		/** @var \PerfLocale\Settings $settings */
		$settings   = $plugin->get( 'settings' );
		$taxonomies = array_values( array_filter( array_map( 'strval', $settings->get_translatable_taxonomies() ) ) );
		$default    = $this->languages->get_default();

		if ( $taxonomies === [] || $default === null ) {
			return;
		}

		$links     = Schema::table( 'translation_links' );
		$source    = SourceType::ImportedWpml->value;
		$tax_marks = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$filter    = new \PerfLocale\Translation\TermAssignmentFilter( $this->cache, $settings );
		$report    = &$result['term_languages'];
		$last_id   = 0;
		$done      = 0;
		$total     = 0;

		$this->beat( 'term_check', 0, 0 );

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared; the sniff does not recognise the injected $this->wpdb.
			$post_ids = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT object_id FROM %i WHERE type = %s AND source = %s AND object_id > %d ORDER BY object_id ASC LIMIT 500',
					$links,
					ObjectType::Post->value,
					$source,
					$last_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			if ( ! is_array( $post_ids ) || $post_ids === [] ) {
				break;
			}

			$post_ids = array_map( 'intval', $post_ids );
			$fetched  = count( $post_ids );
			$last_id  = (int) end( $post_ids );
			$id_marks = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.NotPrepared -- $tax_marks and $id_marks are generated placeholder lists; every value is bound through prepare().
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT pl.object_id, tt.taxonomy, tt.term_id, tt.term_taxonomy_id
					FROM %i pl
					INNER JOIN %i tr ON tr.object_id = pl.object_id
					INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ($tax_marks)
					LEFT JOIN %i tl ON tl.type = %s AND tl.object_id = tt.term_id
					WHERE pl.type = %s AND pl.source = %s AND pl.object_id IN ($id_marks)
					AND pl.language_id <> COALESCE( tl.language_id, %d )
					ORDER BY pl.object_id ASC, tt.term_taxonomy_id ASC",
					array_merge(
						[ $links, $this->wpdb->term_relationships, $this->wpdb->term_taxonomy ],
						$taxonomies,
						[ $links, ObjectType::Term->value, ObjectType::Post->value, $source ],
						$post_ids,
						[ (int) $default->id ]
					)
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.NotPrepared

			$by_post = [];

			foreach ( (array) $rows as $row ) {
				$by_post[ (int) $row->object_id ][] = $row;
			}

			foreach ( $by_post as $post_id => $post_rows ) {
				// The per-request language memo may still hold the language
				// the post had before this import linked it.
				\PerfLocale\Translation\PostTranslationManager::forget_post_language( $post_id );
				$filter->normalize_post_terms( $post_id );

				foreach ( $post_rows as $row ) {
					++$report['found'];

					if ( count( $report['examples'] ) < 5 ) {
						$report['examples'][] = $post_id . '/' . $row->taxonomy . '/' . (int) $row->term_id;
					}

					if ( is_object_in_term( $post_id, (string) $row->taxonomy, (int) $row->term_id ) ) {
						++$report['left'];
					} else {
						++$report['fixed'];
					}
				}
			}

			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

			$done += $fetched;

			if ( $total === 0 && $fetched === 500 && $this->reports_progress() ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One count for the import's progress, on an import that reads more than one batch.
				$total = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND source = %s',
						$links,
						ObjectType::Post->value,
						$source
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			}

			$this->beat( 'term_check', $done, $total );
		} while ( $fetched === 500 );

		$this->beat( 'term_check', $done, $done );

		unset( $report );

		if ( $result['term_languages']['found'] > 0 ) {
			$result['errors'][] = sprintf(
				/* translators: 1: category or tag assignments that pointed to a term in another language, 2: how many were moved to the translated term, 3: how many were left as they are */
				_n( '%1$d category or tag assignment pointed to a term in another language. Moved to the matching translation: %2$d. Left as they are, with no translation of that term: %3$d.', '%1$d category or tag assignments pointed to a term in another language. Moved to the matching translation: %2$d. Left as they are, with no translation of that term: %3$d.', (int) $result['term_languages']['found'], 'perflocale' ),
				$result['term_languages']['found'],
				$result['term_languages']['fixed'],
				$result['term_languages']['left']
			);
		}
	}

	/**
	 * The string translations the string import reads, for its progress.
	 *
	 * The same rows as the import's batch query: status complete or needs
	 * update, with a value.
	 *
	 * @param string $strings_table   WPML's icl_strings table.
	 * @param string $str_trans_table WPML's icl_string_translations table.
	 * @return int
	 */
	private function count_string_translations( string $strings_table, string $str_trans_table ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One count for the import's progress, on an import that reads more than one batch.
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM %i s INNER JOIN %i st ON st.string_id = s.id
				WHERE st.status IN (%d, %d) AND st.value IS NOT NULL AND st.value != ''",
				$strings_table,
				$str_trans_table,
				10,
				3
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Copy WPML's order language (`wpml_language`) to PerfLocale's order meta.
	 *
	 * Orders only, and only those without a PerfLocale language yet
	 * ({@see OrderLanguageCopy}).
	 *
	 * @param array<string, mixed> $result Import result (errors).
	 * @phpstan-param ImportResult $result
	 * @return int Orders given a language.
	 */
	private function import_order_languages( array &$result ): int {
		$slugs = $this->slugs_by_id();
		$copy  = OrderLanguageCopy::copy(
			$this->wpdb,
			'wpml_language',
			function ( string $code ) use ( $slugs ): string {
				$lang_id = $this->language_map[ WpmlSettingsReader::clean_code( $code ) ] ?? null;

				return $lang_id !== null ? (string) ( $slugs[ $lang_id ] ?? '' ) : '';
			},
			function ( int $done, int $total ): void {
				$this->beat( 'orders', $done, $total );
			},
			$this->reports_progress()
		);

		if ( $copy['error'] !== '' ) {
			self::log_db_error( 'reading order languages', $copy['error'] );
			return 0;
		}

		foreach ( $copy['missing'] as $code => $missing ) {
			$result['errors'][] = sprintf(
				/* translators: 1: number of orders, 2: WPML language code */
				_n(
					'%1$d order has the WPML language "%2$s", which has no PerfLocale language; its emails use the default language.',
					'%1$d orders have the WPML language "%2$s", which has no PerfLocale language; their emails use the default language.',
					$missing,
					'perflocale'
				),
				$missing,
				(string) $code
			);
		}

		return $copy['written'];
	}
}
