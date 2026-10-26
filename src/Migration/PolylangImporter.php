<?php
/**
 * Polylang migration importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\MigrationSourceMapRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports translation data from Polylang.
 *
 * Rebuilds PerfLocale groups from Polylang's `language`, `post_translations` and `term_translations` terms.
 *
 * @phpstan-type ImportResult array{posts: int, terms: int, menus: int, strings: int, errors: array<int, string>, notices: array<int, string>, skipped: array<string, int>, skipped_examples: array<string, list<string>>, unmapped_languages: array<string, int>, overridden: array<string, int>, overridden_examples: array<string, list<string>>, conflict_policy: array<string, string>, incomplete: bool}
 */
final class PolylangImporter {

	use ImportHeartbeat;

	/**
	 * The import's stages, in the order it runs them.
	 */
	private const PROGRESS_STAGES = [ 'posts', 'terms', 'single_posts', 'single_terms', 'site' ];

	/**
	 * The stage whose items the translation-set loops count: key, items done, items in total.
	 *
	 * @var array{0: string, 1: int, 2: int}
	 */
	private array $item_progress = [ '', 0, 0 ];

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
	 * Source map: Polylang set term_id => PerfLocale group, so a re-run after a DB restore or a failure reuses it.
	 *
	 * @var MigrationSourceMapRepository
	 */
	private readonly MigrationSourceMapRepository $source_map;

	/**
	 * Language slug mapping: Polylang slug => PerfLocale language ID.
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
	 * Translation-link writes this run asked for and did not get.
	 *
	 * Reported once by import(), so a partial migration never shows a clean count.
	 *
	 * @var int
	 */
	private int $link_failures = 0;

	/**
	 * Examples kept per skip reason.
	 */
	private const SKIPPED_EXAMPLES = 5;

	/**
	 * Longest Polylang term description decoded (bytes); a set or language row is a few KiB.
	 */
	public const MAX_DESCRIPTION_BYTES = 65536;

	/**
	 * Object ids per page of the counted-object bitmap (4 KiB per page).
	 */
	private const SEEN_PAGE_IDS = 32768;

	/**
	 * Source items read and deliberately not linked, per reason (WPML's reason
	 * names where the meaning is the same).
	 *
	 * @var array<string, int>
	 */
	private array $skipped = [];

	/**
	 * A few examples per skip reason, ids only.
	 *
	 * @var array<string, list<string>>
	 */
	private array $skipped_examples = [];

	/**
	 * Counted objects per kind and type ('missing|post'), so an object listed in a set and in its
	 * language counts once: one bit per id, in pages of SEEN_PAGE_IDS ids.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $seen = [];

	/**
	 * Whether a read or write the import needed failed; a rerun imports what this run left out.
	 *
	 * @var bool
	 */
	private bool $incomplete = false;

	/**
	 * Source-map rows that could not be written on the reuse path.
	 *
	 * @var int
	 */
	private int $map_failures = 0;

	/**
	 * Polylang languages with no PerfLocale language of their own (no match, or one already taken), as slug keys.
	 *
	 * @var array<string, true>
	 */
	private array $unmapped = [];

	/**
	 * Objects not imported per unmapped Polylang language.
	 *
	 * @var array<string, int>
	 */
	private array $unmapped_by_language = [];

	/**
	 * Language conflict policy per object type, read once when the import starts.
	 *
	 * @var array<string, string>
	 */
	private array $policies = [];

	/**
	 * Objects whose language the `source` policy changed, per reason.
	 *
	 * @var array<string, int>
	 */
	private array $overridden = [];

	/**
	 * A few examples per override reason, ids and language slugs only.
	 *
	 * @var array<string, list<string>>
	 */
	private array $overridden_examples = [];

	/**
	 * PerfLocale's default language id, read once.
	 *
	 * @var int|null
	 */
	private ?int $default_id = null;

	/**
	 * Group id => language id => object id, for the groups read for the current page.
	 *
	 * Null until the page's first slot check reads them.
	 *
	 * @var array<int, array<int, int>>|null
	 */
	private ?array $slots = null;

	/**
	 * The groups the current page's objects sit in, read at its first slot check.
	 *
	 * @var array<int, int>
	 */
	private array $slot_groups = [];

	/**
	 * The group each object of the read groups sits in: object id => group id.
	 *
	 * @var array<int, int>
	 */
	private array $slot_of = [];

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager (unused: same signature as the other importers).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- DI signature parity with sibling importers.
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->languages  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$this->groups     = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$this->source_map = new MigrationSourceMapRepository();
	}

	/**
	 * Check if Polylang taxonomy exists and import is possible.
	 *
	 * @return bool
	 */
	public function can_import(): bool {
		return taxonomy_exists( 'language' ) || $this->polylang_terms_exist();
	}

	/**
	 * Run the full Polylang import.
	 *
	 * `errors` lists failures, `notices` findings that are not failures, `skipped` items read and not
	 * linked (id-only examples); `incomplete` means a needed read or write failed and a rerun imports the rest.
	 *
	 * @return ImportResult
	 */
	public function import(): array {
		$result = [
			'posts'               => 0,
			'terms'               => 0,
			'menus'               => 0,
			'strings'             => 0,
			'errors'              => [],
			'notices'             => [],
			'skipped'             => [],
			'skipped_examples'    => [],
			'unmapped_languages'  => [],
			'overridden'          => [],
			'overridden_examples' => [],
			'conflict_policy'     => [],
			'incomplete'          => false,
		];

		if ( ! $this->can_import() ) {
			$result['errors'][] = __( 'Polylang language taxonomy not found.', 'perflocale' );
			return $this->finish( $result );
		}

		$this->build_language_map( $result );

		if ( empty( $this->language_map ) ) {
			if ( $this->incomplete ) {
				return $this->finish( $result );
			}

			$result['errors'][] = __( 'No matching languages found between Polylang and PerfLocale.', 'perflocale' );
			return $this->finish( $result );
		}

		$this->policies = self::conflict_policies();

		$result['posts'] = $this->import_post_translations( $result );
		$result['terms'] = $this->import_term_translations( $result );

		// Polylang makes set terms only for linked objects; a lone non-default object still needs a
		// one-member group or it is served as the default language (as WPML's single-row trids).
		$result['posts'] += $this->import_single_language_objects( 'language', ObjectType::Post, $result );
		$result['terms'] += $this->import_single_language_objects( 'term_language', ObjectType::Term, $result );

		// What Polylang keeps beside its groups: menus per language, string
		// translations, which types it translated, and per-language prices.
		$this->beat( 'site', 0, 4 );
		$site_data       = new PolylangSiteData( $this->wpdb, $this->languages, $this->language_map, \Closure::fromCallable( [ $this, 'beat' ] ) );
		$settings        = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$result['menus'] = $site_data->import_menus( $result['notices'] );
		$this->beat( 'site', 1, 4 );
		$result['strings'] = $site_data->import_strings( $result['notices'] );
		$this->beat( 'site', 2, 4 );

		$site_data->import_translatable_types( $settings, $result['errors'], $result['notices'] );
		$this->beat( 'site', 3, 4 );
		$site_data->keep_prices_per_language( $settings, $result['notices'] );
		$this->beat( 'site', 4, 4 );

		if ( $this->link_failures > 0 ) {
			$this->incomplete   = true;
			$result['errors'][] = sprintf(
				/* translators: %d: number of failed translation-link writes */
				_n( 'Polylang import: %d translation link could not be written - that translation is not connected to its group; re-run the import to retry it.', 'Polylang import: %d translation links could not be written - those translations are not connected to their group; re-run the import to retry them.', $this->link_failures, 'perflocale' ),
				$this->link_failures
			);
		}

		if ( $this->map_failures > 0 ) {
			$this->incomplete   = true;
			$result['errors'][] = sprintf(
				/* translators: %d: number of failed source-map writes */
				_n( 'Polylang import: %d translation set could not be recorded as imported; re-run the import.', 'Polylang import: %d translation sets could not be recorded as imported; re-run the import.', $this->map_failures, 'perflocale' ),
				$this->map_failures
			);
		}

		return $this->finish( $result );
	}

	/**
	 * Add the read errors, the skips and the completion flag to a result.
	 *
	 * @param array<string, mixed> $result Import result.
	 * @phpstan-param ImportResult $result
	 * @return ImportResult
	 */
	private function finish( array $result ): array {
		foreach ( $this->read_errors as $read_error ) {
			$result['errors'][] = $read_error;
		}

		$result['skipped']             = $this->skipped;
		$result['skipped_examples']    = $this->skipped_examples;
		$result['unmapped_languages']  = $this->unmapped_by_language;
		$result['overridden']          = $this->overridden;
		$result['overridden_examples'] = $this->overridden_examples;

		// Information, not a problem: a run with only this line is a success.
		$count = $this->overridden['set_before_import'] ?? 0;

		if ( $count > 0 ) {
			$line = sprintf(
				/* translators: %d: number of Polylang items */
				_n( '%d Polylang item had a language set in PerfLocale before the import and now has Polylang\'s.', '%d Polylang items had a language set in PerfLocale before the import and now have Polylang\'s.', $count, 'perflocale' ),
				$count
			);

			if ( ! empty( $this->overridden_examples['set_before_import'] ) ) {
				/* translators: %s: comma-separated examples */
				$line .= ' ' . sprintf( __( 'For example: %s.', 'perflocale' ), implode( ', ', $this->overridden_examples['set_before_import'] ) );
			}

			$result['notices'][] = $line;
		}

		$result['conflict_policy'] = $this->policies;
		$result['incomplete']      = $this->incomplete;

		return $result;
	}

	/**
	 * Count a source item that was read and not linked.
	 *
	 * @param string $reason  Reason key.
	 * @param string $example Ids only, e.g. "post 24 (fi)".
	 * @return void
	 */
	private function skip( string $reason, string $example ): void {
		$this->skipped[ $reason ] = ( $this->skipped[ $reason ] ?? 0 ) + 1;

		if ( count( $this->skipped_examples[ $reason ] ?? [] ) < self::SKIPPED_EXAMPLES ) {
			$this->skipped_examples[ $reason ][] = $example;
		}
	}

	/**
	 * Count an object a Polylang set or language lists that does not exist, once per object.
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $object_id Object id.
	 * @param string     $lang_slug Polylang language slug it is listed under.
	 * @return void
	 */
	private function skip_missing( ObjectType $type, int $object_id, string $lang_slug ): void {
		if ( ! $this->first_count( 'missing', $type, $object_id ) ) {
			return;
		}

		$this->skip( 'missing_object', sprintf( '%s %d (%s)', ObjectType::Post === $type ? 'post' : 'term', $object_id, $lang_slug ) );
	}

	/**
	 * Count an object in a Polylang language with no PerfLocale language, once per object.
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $object_id Object id.
	 * @param string     $lang_slug Polylang language slug.
	 * @return void
	 */
	private function skip_unmapped( ObjectType $type, int $object_id, string $lang_slug ): void {
		if ( $this->counted( 'missing', $type, $object_id ) || ! $this->first_count( 'unmapped', $type, $object_id ) ) {
			return;
		}

		$this->unmapped_by_language[ $lang_slug ] = ( $this->unmapped_by_language[ $lang_slug ] ?? 0 ) + 1;
		$this->skip( 'unmapped_language', sprintf( '%s %d (%s)', ObjectType::Post === $type ? 'post' : 'term', $object_id, $lang_slug ) );
	}

	/**
	 * Count a set member under a language with no PerfLocale language, as missing or unmapped.
	 *
	 * Keys that are no Polylang language are left to the set checks.
	 *
	 * @param ObjectType       $type      Object type.
	 * @param int              $object_id Object id.
	 * @param string           $lang_slug Key in the set.
	 * @param array<int, true> $exists    Existing ids of the batch.
	 * @return void
	 */
	private function skip_unmapped_member( ObjectType $type, int $object_id, string $lang_slug, array $exists ): void {
		if ( $object_id <= 0 || ! isset( $this->unmapped[ $lang_slug ] ) ) {
			return;
		}

		if ( isset( $exists[ $object_id ] ) ) {
			$this->skip_unmapped( $type, $object_id, $lang_slug );
		} else {
			$this->skip_missing( $type, $object_id, $lang_slug );
		}
	}

	/**
	 * Record that an object was counted for one kind of count; true the first time.
	 *
	 * @param string     $kind      missing, unmapped or conflict.
	 * @param ObjectType $type      Object type.
	 * @param int        $object_id Object id.
	 * @return bool
	 */
	private function first_count( string $kind, ObjectType $type, int $object_id ): bool {
		$key                    = $kind . '|' . $type->value;
		[ $page, $byte, $mask ] = self::seen_bit( $object_id );

		if ( ! isset( $this->seen[ $key ][ $page ] ) ) {
			$this->seen[ $key ][ $page ] = str_repeat( "\0", intdiv( self::SEEN_PAGE_IDS, 8 ) );
		}

		$bits = ord( $this->seen[ $key ][ $page ][ $byte ] );

		if ( 0 !== ( $bits & $mask ) ) {
			return false;
		}

		$this->seen[ $key ][ $page ][ $byte ] = chr( $bits | $mask );

		return true;
	}

	/**
	 * Whether an object was counted for one kind of count.
	 *
	 * @param string     $kind      missing, unmapped or conflict.
	 * @param ObjectType $type      Object type.
	 * @param int        $object_id Object id.
	 * @return bool
	 */
	private function counted( string $kind, ObjectType $type, int $object_id ): bool {
		$key                    = $kind . '|' . $type->value;
		[ $page, $byte, $mask ] = self::seen_bit( $object_id );

		return isset( $this->seen[ $key ][ $page ] ) && 0 !== ( ord( $this->seen[ $key ][ $page ][ $byte ] ) & $mask );
	}

	/**
	 * Where an object's bit is: its page, the byte in the page and the bit's mask.
	 *
	 * @param int $object_id Object id.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function seen_bit( int $object_id ): array {
		$offset = ( $object_id % self::SEEN_PAGE_IDS + self::SEEN_PAGE_IDS ) % self::SEEN_PAGE_IDS;

		return [ intdiv( $object_id - $offset, self::SEEN_PAGE_IDS ), intdiv( $offset, 8 ), 1 << ( $offset % 8 ) ];
	}

	/**
	 * Which of these objects exist: one read for the whole list.
	 *
	 * @param ObjectType $type Object type.
	 * @param array<int> $ids  Object ids.
	 * @return array<int, true>|null Existing ids as keys; null when the read failed.
	 */
	private function existing_ids( ObjectType $type, array $ids ): ?array {
		if ( $ids === [] ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One existence read per batch; the IN() list is generated placeholders, every value bound.
		$ids   = array_values( $ids );
		$found = ObjectType::Post === $type
			? $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT ID FROM %i WHERE ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $this->wpdb->posts ], $ids ) ) )
			: $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT term_id FROM %i WHERE term_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $this->wpdb->terms ], $ids ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$error = $this->read_error( $found );

		if ( $error !== '' ) {
			$this->record_read_error( $type, $error );
			return null;
		}

		$exists = [];

		foreach ( (array) $found as $id ) {
			if ( is_numeric( $id ) ) {
				$exists[ (int) $id ] = true;
			}
		}

		return $exists;
	}

	/**
	 * An integer column of a database row object (0 when absent or not numeric).
	 *
	 * @param object $row Row.
	 * @param string $key Column.
	 * @return int
	 */
	private static function row_int( object $row, string $key ): int {
		$value = get_object_vars( $row )[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * The language keys of a decoded Polylang set: the set without Polylang's own `sync` key.
	 *
	 * @param array<mixed> $set Decoded description.
	 * @return array<mixed>
	 */
	private static function set_members( array $set ): array {
		unset( $set['sync'] );

		return $set;
	}

	/**
	 * A Polylang term description decoded; false when longer than MAX_DESCRIPTION_BYTES or nested deeper than 16.
	 *
	 * @param string $description Term-taxonomy description; returned as it is when not serialized.
	 * @return mixed
	 */
	public static function decode_description( string $description ): mixed {
		if ( strlen( $description ) > self::MAX_DESCRIPTION_BYTES ) {
			return false;
		}

		if ( ! is_serialized( $description ) ) {
			return $description;
		}

		// User-written data: allowed_classes=false creates no object from it.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false is the exact mitigation the rule warns about; @ suppresses the notice for a refused class or depth.
		return @unserialize(
			$description,
			[
				'allowed_classes' => false,
				'max_depth'       => 16,
			]
		);
	}

	/**
	 * Polylang import lines for the skipped items, per reason, with examples.
	 *
	 * @param array<string, mixed> $result Import result with `skipped` and `skipped_examples`.
	 * @return list<string>
	 */
	public static function skipped_lines( array $result ): array {
		$lines    = [];
		$examples = (array) ( $result['skipped_examples'] ?? [] );

		foreach ( (array) ( $result['skipped'] ?? [] ) as $reason => $count ) {
			$count = is_numeric( $count ) ? (int) $count : 0;

			if ( $count <= 0 ) {
				continue;
			}

			// One line per language, with that language's examples.
			if ( 'unmapped_language' === $reason ) {
				foreach ( (array) ( $result['unmapped_languages'] ?? [] ) as $slug => $in_language ) {
					$in_language = is_numeric( $in_language ) ? (int) $in_language : 0;

					if ( $in_language <= 0 ) {
						continue;
					}

					$slug = (string) $slug;
					$line = sprintf(
						/* translators: 1: number of Polylang items, 2: Polylang language code */
						_n( '%1$d Polylang item in %2$s was not imported: %2$s has no PerfLocale language of its own (see above). Add it under PerfLocale → Languages, then run the import again.', '%1$d Polylang items in %2$s were not imported: %2$s has no PerfLocale language of its own (see above). Add it under PerfLocale → Languages, then run the import again.', $in_language, 'perflocale' ),
						$in_language,
						$slug
					);
					$found = array_values( array_filter( array_map( static fn( $example ): string => is_scalar( $example ) ? (string) $example : '', (array) ( $examples[ $reason ] ?? [] ) ), static fn( string $example ): bool => str_ends_with( $example, ' (' . $slug . ')' ) ) );

					if ( $found !== [] ) {
						/* translators: %s: comma-separated examples */
						$line .= ' ' . sprintf( __( 'For example: %s.', 'perflocale' ), implode( ', ', $found ) );
					}

					$lines[] = $line;
				}

				continue;
			}

			$line = match ( (string) $reason ) {
				'missing_object' =>
					/* translators: %d: number of Polylang items */
					_n( '%d Polylang item was not imported: its post or term no longer exists.', '%d Polylang items were not imported: their posts or terms no longer exist.', $count, 'perflocale' ),
				'empty_set'        =>
					/* translators: %d: number of Polylang translation sets */
					_n( '%d Polylang translation set was not imported: it lists no item.', '%d Polylang translation sets were not imported: they list no item.', $count, 'perflocale' ),
				'language_taken'   =>
					/* translators: %d: number of Polylang items */
					_n( '%d Polylang item was not linked, and its translation set was left as it is: in the PerfLocale translation group it would join, another post or term already has its language.', '%d Polylang items were not linked, and their translation sets were left as they are: in the PerfLocale translation group each would join, another post or term already has its language.', $count, 'perflocale' ),
				'split_groups'     =>
					/* translators: %d: number of Polylang items */
					_n( '%d Polylang item was not linked, and its translation set was left as it is: it is in a PerfLocale translation group with other posts or terms, and linking it would move it away from them.', '%d Polylang items were not linked, and their translation sets were left as they are: each is in a PerfLocale translation group with other posts or terms, and linking it would move it away from them.', $count, 'perflocale' ),
				'stale_source_map' =>
					/* translators: %d: number of Polylang sets or items */
					_n( '%d record of an earlier Polylang import pointed to a translation group that no longer matches it (a group of another kind, or one holding other posts or terms in its languages); it was not used.', '%d records of an earlier Polylang import pointed to translation groups that no longer match them (groups of another kind, or holding other posts or terms in their languages); they were not used.', $count, 'perflocale' ),
				'language_differs' =>
					/* translators: %d: number of Polylang items */
					_n( '%d Polylang item kept its PerfLocale language, which differs from Polylang\'s. Change it in PerfLocale if Polylang is right, then run the import again.', '%d Polylang items kept their PerfLocale language, which differs from Polylang\'s. Change them in PerfLocale if Polylang is right, then run the import again.', $count, 'perflocale' ),
				default            => '',
			};

			if ( $line === '' ) {
				continue;
			}

			$line = sprintf( $line, $count );

			if ( ! empty( $examples[ $reason ] ) ) {
				/* translators: %s: comma-separated examples */
				$line .= ' ' . sprintf( __( 'For example: %s.', 'perflocale' ), implode( ', ', array_map( static fn( $example ): string => is_scalar( $example ) ? (string) $example : '', (array) $examples[ $reason ] ) ) );
			}

			$lines[] = $line;
		}

		$changed = (array) ( $result['overridden'] ?? [] );
		$count   = is_numeric( $changed['language_differs'] ?? null ) ? (int) $changed['language_differs'] : 0;

		if ( $count > 0 ) {
			$line = sprintf(
				/* translators: 1: number of Polylang items, 2: filter name */
				_n( '%1$d Polylang item was changed from its PerfLocale language to Polylang\'s (%2$s).', '%1$d Polylang items were changed from their PerfLocale language to Polylang\'s (%2$s).', $count, 'perflocale' ),
				$count,
				'perflocale/migration/language_conflict_policy'
			);
			$shown = (array) ( ( (array) ( $result['overridden_examples'] ?? [] ) )['language_differs'] ?? [] );

			if ( $shown !== [] ) {
				/* translators: %s: comma-separated examples */
				$line .= ' ' . sprintf( __( 'For example: %s.', 'perflocale' ), implode( ', ', array_map( static fn( $example ): string => is_scalar( $example ) ? (string) $example : '', $shown ) ) );
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Why the source read that just ran failed, or '' when it worked.
	 *
	 * A read failed when it returned no array or left last_error set (get_col() then gives [] like an
	 * empty result); the next query resets last_error, so call this straight after the read.
	 *
	 * @phpstan-impure
	 *
	 * @param mixed $rows What the read returned.
	 * @return string
	 */
	private function read_error( $rows ): string {
		if ( $this->wpdb->last_error !== '' ) {
			return $this->wpdb->last_error;
		}

		return is_array( $rows ) ? '' : 'the query returned no result';
	}

	/**
	 * Log a database error's own text to the PHP error log, when WP_DEBUG_LOG is on.
	 *
	 * The text can hold prefixed table names and row values, and results are shown to every user
	 * with Jobs access, so stored messages only say that a database error happened.
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
		error_log( '[PerfLocale] Polylang import: database error while ' . $where . ' - ' . $error );
	}

	/**
	 * Record a failed source read; import() adds it to the errors.
	 *
	 * @param ObjectType $type  What the read was loading.
	 * @param string     $error The database error.
	 * @return void
	 */
	private function record_read_error( ObjectType $type, string $error ): void {
		$this->incomplete = true;

		self::log_db_error( ObjectType::Post === $type ? 'reading post data' : 'reading term data', $error );

		$this->read_errors[] = ObjectType::Post === $type
			? sprintf(
				/* translators: %s: source plugin name */
				__( 'Some %s post data could not be read because of a database error and was not imported. Re-run the import.', 'perflocale' ),
				'Polylang'
			)
			: sprintf(
				/* translators: %s: source plugin name */
				__( 'Some %s term data could not be read because of a database error and was not imported. Re-run the import.', 'perflocale' ),
				'Polylang'
			);
	}

	/**
	 * Check if Polylang language terms exist in the database.
	 *
	 * Used when the Polylang plugin is deactivated but its data remains.
	 *
	 * @return bool
	 */
	private function polylang_terms_exist(): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->term_taxonomy} WHERE taxonomy = %s",
				'language'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $count > 0;
	}

	/**
	 * Build a mapping from Polylang language slugs to PerfLocale language IDs.
	 *
	 * @param array<string, mixed> $result Import result (passed by reference for errors).
	 * @phpstan-param ImportResult $result
	 * @return void
	 */
	private function build_language_map( array &$result ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// The term description holds Polylang's exact locale (serialized): matching on it is the only
		// way to tell pt_BR from pt_PT and to match hyphenated slugs ('pt-br') to locales.
		$pll_langs = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT t.slug, t.name, tt.description
				FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				'language'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$read_error = $this->read_error( $pll_langs );

		if ( $read_error !== '' || ! is_array( $pll_langs ) ) {
			$this->incomplete = true;
			self::log_db_error( 'reading the languages', $read_error );
			$this->read_errors[] = sprintf(
				/* translators: %s: source plugin name */
				__( '%s languages could not be read because of a database error, so nothing was imported. Re-run the import.', 'perflocale' ),
				'Polylang'
			);
			return;
		}

		$active_languages = $this->languages->get_active();

		// Two Polylang languages may resolve to one PerfLocale language: one object per (group,
		// language) is a hard invariant, so the first claimant wins and the rest are reported.
		$claimed_by = [];

		foreach ( $pll_langs as $pll_lang ) {
			$pll_slug   = sanitize_text_field( $pll_lang->slug );
			$pll_locale = self::extract_pll_locale( (string) ( $pll_lang->description ?? '' ) );

			$matched_id = null;

			// Pass 1: Polylang's locale, exact after normalising, as slugs collide (pt_BR and pt_PT
			// may both be 'pt').
			if ( $pll_locale !== '' ) {
				foreach ( $active_languages as $lang ) {
					if ( self::normalize_locale( $lang->locale ) === self::normalize_locale( $pll_locale ) ) {
						$matched_id = (int) $lang->id;
						break;
					}
				}
			}

			// Pass 2 - fall back to an exact slug match (no description locale, or
			// a locale PerfLocale doesn't carry). Never a str_starts_with prefix.
			if ( $matched_id === null ) {
				foreach ( $active_languages as $lang ) {
					if ( $lang->slug === $pll_slug ) {
						$matched_id = (int) $lang->id;
						break;
					}
				}
			}

			if ( $matched_id !== null ) {
				if ( isset( $claimed_by[ $matched_id ] ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: first Polylang slug, 2: colliding Polylang slug, 3: colliding slug again */
						__( 'Polylang languages "%1$s" and "%2$s" both map to the same PerfLocale language - "%3$s" was skipped. Add a distinct PerfLocale language for it and re-run.', 'perflocale' ),
						$claimed_by[ $matched_id ],
						$pll_slug,
						$pll_slug
					);
					$this->unmapped[ $pll_slug ] = true;
					continue;
				}

				$claimed_by[ $matched_id ]       = $pll_slug;
				$this->language_map[ $pll_slug ] = $matched_id;
			} else {
				$result['errors'][] = sprintf(
					/* translators: 1: Polylang language slug, 2: its locale */
					__( 'No PerfLocale language match for Polylang slug "%1$s" (locale "%2$s").', 'perflocale' ),
					$pll_slug,
					$pll_locale
				);
				$this->unmapped[ $pll_slug ] = true;
			}
		}
	}

	/**
	 * The locale in a Polylang `language`-term description, or '' when absent or malformed.
	 *
	 * @param string $description Serialized term-taxonomy description.
	 * @return string
	 */
	private static function extract_pll_locale( string $description ): string {
		if ( $description === '' ) {
			return '';
		}

		$meta = self::decode_description( $description );

		if ( is_array( $meta ) && isset( $meta['locale'] ) && is_string( $meta['locale'] ) ) {
			return sanitize_text_field( $meta['locale'] );
		}

		return '';
	}

	/**
	 * Normalise a locale for comparison: lowercase, hyphens => underscores.
	 *
	 * @param string $locale Locale string.
	 * @return string
	 */
	private static function normalize_locale( string $locale ): string {
		return strtolower( str_replace( '-', '_', $locale ) );
	}

	/**
	 * Import post translations from Polylang's post_translations taxonomy.
	 *
	 * Polylang stores each set as a serialized array in the term description.
	 *
	 * @param array<string, mixed> $result Import result (passed by reference for errors).
	 * @phpstan-param ImportResult $result
	 * @return int Number of posts imported.
	 */
	private function import_post_translations( array &$result ): int {
		$imported = 0;

		// Bounded memory: the set term_ids first, then each batch's serialized descriptions.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$term_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT t.term_id FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				'post_translations'
			)
		);

		$read_error = $this->read_error( $term_ids );

		if ( $read_error !== '' ) {
			$this->record_read_error( ObjectType::Post, $read_error );
			return 0;
		}

		if ( empty( $term_ids ) ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $term_ids ), $batch_size );

		$this->item_progress = [ 'posts', 0, count( $term_ids ) ];
		$this->beat( 'posts', 0, count( $term_ids ) );

		foreach ( $batches as $batch ) {
			// Generated %d placeholders sized to the batch, safe to interpolate.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// $this->wpdb->terms / ->term_taxonomy are wpdb-provided table names.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$translation_terms = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.description
					FROM {$this->wpdb->terms} t
					INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND t.term_id IN ($placeholders)",
					array_merge( [ 'post_translations' ], $batch )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Skip only this batch: the ones after it still import.
			$read_error = $this->read_error( $translation_terms );

			if ( $read_error !== '' || ! is_array( $translation_terms ) ) {
				$this->record_read_error( ObjectType::Post, $read_error );
				continue;
			}

			$imported += $this->process_post_translation_terms( $translation_terms, $result );
			// Drop this batch's runtime cache and SAVEQUERIES log; the persistent cache is untouched.
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		$this->beat( 'posts', count( $term_ids ), count( $term_ids ) );
		return $imported;
	}

	/**
	 * Per-batch processor for the post-translations taxonomy terms.
	 *
	 * @param array<int, object>   $translation_terms Term rows for this batch.
	 * @param array<string, mixed> $result            Accumulator (errors[]).
	 * @phpstan-param ImportResult $result
	 * @return int
	 */
	private function process_post_translation_terms( array $translation_terms, array &$result ): int {
		$imported = 0;

		// Prime the post and link caches for every post of the batch in one pass, so the per-set
		// get_post() and link lookups below are cache hits.
		$all_post_ids = [];

		foreach ( $translation_terms as $term ) {
			// The same decode as below, to collect the ids to prime.
			$translations_for_prime = self::decode_description( (string) $term->description );

			if ( ! is_array( $translations_for_prime ) ) {
				continue;
			}

			foreach ( $translations_for_prime as $post_id ) {
				$post_id = (int) $post_id;
				if ( $post_id > 0 ) {
					$all_post_ids[ $post_id ] = true; // dedupe via map keys
				}
			}
		}

		if ( $all_post_ids !== [] ) {
			$post_id_list = array_keys( $all_post_ids );

			if ( function_exists( '_prime_post_caches' ) ) {
				// Post rows only: no term or meta priming.
				_prime_post_caches( $post_id_list, false, false );
			}

			$this->groups->prime_translations( ObjectType::Post, $post_id_list );
		}

		// Which listed posts exist: one read for the batch. When it fails the
		// batch's sets are left for a rerun (their members still get their
		// language in the single-language pass).
		$exists = $this->existing_ids( ObjectType::Post, array_keys( $all_post_ids ) );

		if ( null === $exists ) {
			return 0;
		}

		$this->start_slot_page( ObjectType::Post, $exists );

		foreach ( $translation_terms as $term ) {
			// Before each item: the report counts the items finished before it.
			$this->beat( $this->item_progress[0], $this->item_progress[1]++, $this->item_progress[2] );

			$translations = self::decode_description( (string) $term->description );

			// Report a non-empty description that does not unserialize (corrupt row), never drop it silently.
			if ( ! is_array( $translations ) && '' !== trim( (string) $term->description ) ) {
				$result['errors'][] = sprintf(
					/* translators: %d: term_taxonomy term id */
					__( 'Unreadable Polylang post_translations data for term %d - group skipped.', 'perflocale' ),
					(int) $term->term_id
				);
				continue;
			}

			if ( is_array( $translations ) && [] === self::set_members( $translations ) ) {
				$this->skip( 'empty_set', 'term ' . self::row_int( $term, 'term_id' ) );
				continue;
			}

			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue;
			}

			$imported += $this->apply_set( ObjectType::Post, self::row_int( $term, 'term_id' ), $translations, $exists, $result );
		}

		return $imported;
	}

	/**
	 * Link one Polylang set, or leave it whole and count why.
	 *
	 * Every member is checked before any write, so a set links whole or not at all: see
	 * docs-ai/11-code-rationale.md, "PolylangImporter: linking a set".
	 *
	 * @param ObjectType           $type         Post or Term.
	 * @param int                  $set_id       Term id of the Polylang set.
	 * @param array<mixed>         $translations Decoded set: language slug => object id.
	 * @param array<int, true>     $exists       Existing ids of the batch.
	 * @param array<string, mixed> $result       Import result (errors).
	 * @phpstan-param ImportResult $result
	 * @return int Group and link writes that succeeded.
	 */
	private function apply_set( ObjectType $type, int $set_id, array $translations, array $exists, array &$result ): int {
		$is_post    = ObjectType::Post === $type;
		$source_key = $set_id . '|' . $type->value;
		$members    = [];

		foreach ( self::set_members( $translations ) as $lang_slug => $object_id ) {
			$object_id = is_numeric( $object_id ) ? (int) $object_id : 0;
			$lang_slug = (string) $lang_slug;
			$lang_id   = $this->language_map[ $lang_slug ] ?? null;

			if ( $lang_id === null ) {
				$this->skip_unmapped_member( $type, $object_id, $lang_slug, $exists );
				continue;
			}

			if ( ! isset( $exists[ $object_id ] ) ) {
				$this->skip_missing( $type, $object_id, $lang_slug );
				continue;
			}

			$members[] = [
				'slug'   => $lang_slug,
				'object' => $object_id,
				'lang'   => $lang_id,
			];
		}

		if ( $members === [] ) {
			return 0;
		}

		$in_set     = array_column( $members, 'object' );
		$default_id = $this->default_language_id();
		$state      = [];

		// Each member's own link and whether its group holds objects outside the set.
		foreach ( $members as $i => $member ) {
			$own     = null;
			$outside = [];
			$links   = $this->groups->get_translations( $member['object'], $type );

			foreach ( $links as $link ) {
				if ( ! is_object( $link ) ) {
					continue;
				}

				$linked = self::row_int( $link, 'object_id' );

				if ( $linked === $member['object'] ) {
					$own = $link;
				} elseif ( ! in_array( $linked, $in_set, true ) ) {
					$outside[] = $linked;
				}
			}

			$state[ $i ] = [
				'group'      => null !== $own ? self::row_int( $own, 'group_id' ) : 0,
				'linked'     => null !== $own ? self::row_int( $own, 'language_id' ) : 0,
				'outside'    => $outside,
				'unassigned' => null !== $own && 1 === count( $links ) && ! self::imported_link( $own ),
			];
		}

		// The group the set joins: its recorded group when that still fits the
		// set (a record that does not is counted and replaced by the writes below).
		$mapped_id = $this->source_map->get_group_id( 'polylang', $source_key );
		$target    = 0;

		if ( $mapped_id !== null ) {
			$langs = array_flip( array_column( $members, 'lang' ) );

			if ( SourceMapCheck::group_fits( $type, (int) $mapped_id, $in_set, $langs ) ) {
				$target = (int) $mapped_id;
			} elseif ( null !== $this->groups->find( (int) $mapped_id ) ) {
				$this->skip( 'stale_source_map', sprintf( '%s set %d', $type->value, $set_id ) );
			}
		}

		if ( 0 === $target ) {
			$holding = [];

			foreach ( $state as $i => $st ) {
				if ( $st['group'] > 0 && $st['outside'] !== [] ) {
					$holding[ $st['group'] ][] = $i;
				}
			}

			if ( count( $holding ) > 1 ) {
				foreach ( $holding as $indexes ) {
					foreach ( $indexes as $i ) {
						$this->skip_conflict( 'split_groups', $type, $members[ $i ]['object'], sprintf( '%s %d (%s, its group holds %s %d)', $type->value, $members[ $i ]['object'], $members[ $i ]['slug'], $type->value, $state[ $i ]['outside'][0] ) );
					}
				}

				return 0;
			}

			if ( $holding !== [] ) {
				$target = (int) array_key_first( $holding );
			} else {
				foreach ( $state as $st ) {
					if ( $st['group'] > 0 ) {
						$target = $st['group'];
						break;
					}
				}
			}
		}

		if ( 0 === $target ) {
			return $this->create_set_group( $type, $set_id, $members, $source_key, $result );
		}

		// Checks before any write: the whole set or nothing.
		$conflicts = [];
		$relabel   = [];

		foreach ( $members as $i => $member ) {
			$st = $state[ $i ];

			if ( $st['group'] === $target && $st['linked'] === $member['lang'] ) {
				continue;
			}

			if ( $st['group'] > 0 && $st['linked'] !== $member['lang'] && ! $st['unassigned'] ) {
				if ( 'source' !== $this->policy( $type ) || $member['lang'] === $default_id ) {
					$conflicts[] = [ 'language_differs', $i, sprintf( '%s %d (%s in PerfLocale, %s in Polylang)', $type->value, $member['object'], $this->language_slug( $st['linked'] ), $member['slug'] ) ];
					continue;
				}

				$relabel[ $i ] = true;
			}

			if ( $st['group'] > 0 && $st['group'] !== $target && $st['outside'] !== [] ) {
				$conflicts[] = [ 'split_groups', $i, sprintf( '%s %d (%s, its group holds %s %d)', $type->value, $member['object'], $member['slug'], $type->value, $st['outside'][0] ) ];
				continue;
			}

			$holder = $this->slot_holder( $target, $member['lang'] );

			if ( $holder > 0 && ! in_array( $holder, $in_set, true ) ) {
				$conflicts[] = [ 'language_taken', $i, sprintf( '%s %d (%s, held by %s %d)', $type->value, $member['object'], $member['slug'], $type->value, $holder ) ];
			}
		}

		if ( $conflicts !== [] ) {
			foreach ( $conflicts as [ $reason, $i, $example ] ) {
				$this->skip_conflict( $reason, $type, $members[ $i ]['object'], $example );
			}

			return 0;
		}

		if ( $mapped_id !== $target && ! $this->source_map->set_group_id( 'polylang', $source_key, $target ) ) {
			++$this->map_failures;
		}

		// Members already in the group first: a relabel frees or takes a slot
		// another member's link needs.
		$order = array_keys( $members );
		usort(
			$order,
			static function ( int $a, int $b ) use ( $state, $target ): int {
				$in_group = (int) ( $state[ $b ]['group'] === $target ) <=> (int) ( $state[ $a ]['group'] === $target );

				return 0 !== $in_group ? $in_group : $a <=> $b;
			}
		);
		$imported = 0;

		foreach ( $order as $i ) {
			$member = $members[ $i ];

			if ( $state[ $i ]['group'] === $target && $state[ $i ]['linked'] === $member['lang'] ) {
				continue;
			}

			$linked = $this->groups->link_object( $target, $member['object'], $member['lang'], $this->link_status( $type, $member['object'] ), SourceType::ImportedPolylang );

			$this->forget_slots( $member['object'], $target, $state[ $i ]['group'] );

			if ( false === $linked ) {
				++$this->link_failures;
				continue;
			}

			++$imported;

			if ( isset( $relabel[ $i ] ) ) {
				$this->count_overridden( 'language_differs', $type, $member['object'], sprintf( '%s %d (%s in PerfLocale, now %s)', $type->value, $member['object'], $this->language_slug( $state[ $i ]['linked'] ), $member['slug'] ) );
			} elseif ( $state[ $i ]['unassigned'] && $state[ $i ]['linked'] !== $member['lang'] && $state[ $i ]['linked'] !== $default_id ) {
				$this->count_overridden( 'set_before_import', $type, $member['object'], sprintf( '%s %d (%s in PerfLocale, now %s)', $type->value, $member['object'], $this->language_slug( $state[ $i ]['linked'] ), $member['slug'] ) );
			}
		}

		return $imported;
	}

	/**
	 * Create the group of a set none of whose members has one, then link the others.
	 *
	 * @param ObjectType                                        $type       Post or Term.
	 * @param int                                               $set_id     Term id of the Polylang set.
	 * @param list<array{slug: string, object: int, lang: int}> $members    Valid members.
	 * @param string                                            $source_key Source-map key.
	 * @param array<string, mixed>                              $result     Import result (errors).
	 * @phpstan-param ImportResult $result
	 * @return int Group and link writes that succeeded.
	 */
	private function create_set_group( ObjectType $type, int $set_id, array $members, string $source_key, array &$result ): int {
		$seed     = $members[0];
		$group_id = $this->groups->create_group(
			$type,
			$seed['object'],
			$seed['lang'],
			$this->link_status( $type, $seed['object'] ),
			SourceType::ImportedPolylang,
			[
				'type' => 'polylang',
				'key'  => $source_key,
			]
		);

		$this->forget_slots( $seed['object'], is_numeric( $group_id ) ? (int) $group_id : 0 );

		if ( false === $group_id ) {
			$this->incomplete   = true;
			$result['errors'][] = sprintf(
				ObjectType::Post === $type
					/* translators: %d: term ID of Polylang's post translation set */
					? __( 'Failed to create group for Polylang post translation term %d.', 'perflocale' )
					/* translators: %d: term ID of Polylang's term translation set */
					: __( 'Failed to create group for Polylang term translation term %d.', 'perflocale' ),
				$set_id
			);

			return 0;
		}

		$imported = 1;

		foreach ( array_slice( $members, 1 ) as $member ) {
			$linked = $this->groups->link_object( (int) $group_id, $member['object'], $member['lang'], $this->link_status( $type, $member['object'] ), SourceType::ImportedPolylang );

			$this->forget_slots( $member['object'], $group_id );

			if ( false === $linked ) {
				++$this->link_failures;
			} else {
				++$imported;
			}
		}

		return $imported;
	}

	/**
	 * Start a page of sets or objects: its first slot check reads its objects' groups in one query.
	 *
	 * @param ObjectType       $type   Object type.
	 * @param array<int, true> $exists The page's existing objects, as keys.
	 * @return void
	 */
	private function start_slot_page( ObjectType $type, array $exists ): void {
		$groups = [];

		foreach ( array_keys( $exists ) as $object_id ) {
			$link = $this->groups->find_link_for_object( (int) $object_id, $type );

			if ( null !== $link ) {
				$groups[ self::row_int( $link, 'group_id' ) ] = true;
			}
		}

		unset( $groups[0] );

		$this->slots       = null;
		$this->slot_groups = array_keys( $groups );
		$this->slot_of     = [];
	}

	/**
	 * The object that holds a language in a group, read from the database.
	 *
	 * The page's groups load in one query, others on first check, a group this import wrote to is
	 * read again; after a failed read, object_in_group_language() reads the slot on its own.
	 *
	 * @param int $group_id    Group ID.
	 * @param int $language_id Language ID.
	 * @return int Object ID, or 0 when no object holds that language.
	 */
	private function slot_holder( int $group_id, int $language_id ): int {
		if ( null === $this->slots ) {
			$this->slots = [];
			$this->read_slots( array_unique( array_merge( $this->slot_groups, [ $group_id ] ) ) );
		}

		if ( ! isset( $this->slots[ $group_id ] ) ) {
			$this->read_slots( [ $group_id ] );
		}

		if ( isset( $this->slots[ $group_id ] ) ) {
			return $this->slots[ $group_id ][ $language_id ] ?? 0;
		}

		return $this->groups->object_in_group_language( $group_id, $language_id );
	}

	/**
	 * Read which object holds each language of these groups, uncached (the import reads its own writes).
	 *
	 * A group whose read failed stays unread.
	 *
	 * @param array<int, int> $group_ids Group IDs.
	 * @return void
	 */
	private function read_slots( array $group_ids ): void {
		foreach ( array_chunk( array_values( $group_ids ), 500 ) as $chunk ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Uncached on purpose (the import reads its own writes); the IN() list is generated placeholders, every value bound.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT group_id, language_id, object_id FROM %i WHERE group_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					array_merge( [ \PerfLocale\Database\Schema::table( 'translation_links' ) ], $chunk )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

			if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $chunk as $group_id ) {
				$this->slots[ $group_id ] = [];
			}

			foreach ( $rows as $row ) {
				$group_id  = self::row_int( $row, 'group_id' );
				$object_id = self::row_int( $row, 'object_id' );

				$this->slots[ $group_id ][ self::row_int( $row, 'language_id' ) ] = $object_id;
				$this->slot_of[ $object_id ]                                     = $group_id;
			}
		}
	}

	/**
	 * Mark the groups a link write may have changed (destination, old group given and as read) for rereading.
	 *
	 * @param int $object_id The linked object.
	 * @param int ...$group_ids Groups the write touched.
	 * @return void
	 */
	private function forget_slots( int $object_id, int ...$group_ids ): void {
		if ( null === $this->slots ) {
			return;
		}

		if ( isset( $this->slot_of[ $object_id ] ) ) {
			$group_ids[] = $this->slot_of[ $object_id ];
		}

		foreach ( $group_ids as $group_id ) {
			unset( $this->slots[ $group_id ] );
		}
	}

	/**
	 * The status an imported link gets: a post's follows its status (a draft never imports as published).
	 *
	 * @param ObjectType $type      Post or Term.
	 * @param int        $object_id Object id.
	 * @return string
	 */
	private function link_status( ObjectType $type, int $object_id ): string {
		return ( ObjectType::Post === $type && get_post_status( $object_id ) !== 'publish' )
			? TranslationStatus::Draft->value
			: TranslationStatus::Published->value;
	}

	/**
	 * Whether an import wrote this link (its source is an import).
	 *
	 * @param object $link Link row.
	 * @return bool
	 */
	private static function imported_link( object $link ): bool {
		$source = get_object_vars( $link )['source'] ?? '';

		return in_array( $source, [ SourceType::ImportedPolylang->value, SourceType::ImportedWpml->value, SourceType::ImportedTrp->value ], true );
	}

	/**
	 * PerfLocale's default language id (0 when none).
	 *
	 * @return int
	 */
	private function default_language_id(): int {
		if ( null === $this->default_id ) {
			$default          = $this->languages->get_default();
			$this->default_id = null !== $default ? (int) $default->id : 0;
		}

		return $this->default_id;
	}

	/**
	 * A PerfLocale language's slug, for examples.
	 *
	 * @param int $language_id Language id.
	 * @return string
	 */
	private function language_slug( int $language_id ): string {
		$language = $this->languages->find( $language_id );
		$slug     = null !== $language ? ( get_object_vars( $language )['slug'] ?? '' ) : '';

		return is_string( $slug ) && $slug !== '' ? $slug : (string) $language_id;
	}


	/**
	 * The language conflict policy of an object type for this run.
	 *
	 * @param ObjectType $type Post or Term.
	 * @return string 'keep' or 'source'.
	 */
	private function policy( ObjectType $type ): string {
		return $this->policies[ $type->value ] ?? 'keep';
	}

	/**
	 * The language conflict policy per object type, read from the filter.
	 *
	 * @return array{post: string, term: string}
	 */
	public static function conflict_policies(): array {
		$policies = [];

		foreach ( [ ObjectType::Post, ObjectType::Term ] as $type ) {
			/**
			 * What a Polylang import does when PerfLocale has another language for an imported post or term.
			 *
			 * 'keep' (default) leaves PerfLocale's language; 'source' sets Polylang's, never moving another item
			 * or setting the default language over one PerfLocale holds. Both list each item. Read once per import.
			 *
			 * @hook perflocale/migration/language_conflict_policy
			 * @since 1.0.7.3
			 *
			 * @param string $policy      'keep'.
			 * @param string $source      'polylang'.
			 * @param string $object_type 'post' or 'term'.
			 * @return string 'keep' or 'source'.
			 */
			$value                    = apply_filters( 'perflocale/migration/language_conflict_policy', 'keep', 'polylang', $type->value );
			$policies[ $type->value ] = 'source' === $value ? 'source' : 'keep';
		}

		return [
			'post' => $policies['post'],
			'term' => $policies['term'],
		];
	}

	/**
	 * The line that announces a policy other than `keep`, or ''.
	 *
	 * @param array<mixed> $policies Policy per object type.
	 * @return string
	 */
	public static function policy_line( array $policies ): string {
		$post = 'source' === ( $policies['post'] ?? '' );
		$term = 'source' === ( $policies['term'] ?? '' );

		if ( ! $post && ! $term ) {
			return '';
		}

		$what = $post && $term
			? __( 'posts and terms', 'perflocale' )
			: ( $post ? __( 'posts', 'perflocale' ) : __( 'terms', 'perflocale' ) );

		return sprintf(
			/* translators: 1: which objects (posts and terms, posts, or terms), 2: filter name */
			__( 'Language conflicts: the import sets Polylang\'s language for %1$s that PerfLocale has in another language, and lists each change (%2$s).', 'perflocale' ),
			$what,
			'perflocale/migration/language_conflict_policy'
		);
	}

	/**
	 * Count a set member or object left as it is because of a conflict, once per object.
	 *
	 * @param string     $reason    language_taken, split_groups or language_differs.
	 * @param ObjectType $type      Object type.
	 * @param int        $object_id Object id.
	 * @param string     $example   Ids and language slugs only.
	 * @return void
	 */
	private function skip_conflict( string $reason, ObjectType $type, int $object_id, string $example ): void {
		if ( ! $this->first_count( 'conflict', $type, $object_id ) ) {
			return;
		}

		$this->skip( $reason, $example );
	}

	/**
	 * Count an object whose language the `source` policy changed, once per object.
	 *
	 * @param string     $reason    language_differs.
	 * @param ObjectType $type      Object type.
	 * @param int        $object_id Object id.
	 * @param string     $example   Ids and language slugs only.
	 * @return void
	 */
	private function count_overridden( string $reason, ObjectType $type, int $object_id, string $example ): void {
		if ( ! $this->first_count( 'conflict', $type, $object_id ) ) {
			return;
		}

		$this->overridden[ $reason ]                       = ( $this->overridden[ $reason ] ?? 0 ) + 1;

		if ( count( $this->overridden_examples[ $reason ] ?? [] ) < self::SKIPPED_EXAMPLES ) {
			$this->overridden_examples[ $reason ][] = $example;
		}
	}

	/**
	 * Import term translations from Polylang's term_translations taxonomy.
	 *
	 * @param array<string, mixed> $result Import result (passed by reference for errors).
	 * @phpstan-param ImportResult $result
	 * @return int Number of terms imported.
	 */
	private function import_term_translations( array &$result ): int {
		$imported = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$term_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT t.term_id FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				'term_translations'
			)
		);

		$read_error = $this->read_error( $term_ids );

		if ( $read_error !== '' ) {
			$this->record_read_error( ObjectType::Term, $read_error );
			return 0;
		}

		if ( empty( $term_ids ) ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $term_ids ), $batch_size );

		$this->item_progress = [ 'terms', 0, count( $term_ids ) ];
		$this->beat( 'terms', 0, count( $term_ids ) );

		foreach ( $batches as $batch ) {
			// Generated %d placeholders sized to the batch, safe to interpolate.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// $this->wpdb->terms / ->term_taxonomy are wpdb-provided table names.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$translation_terms = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.description
					FROM {$this->wpdb->terms} t
					INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND t.term_id IN ($placeholders)",
					array_merge( [ 'term_translations' ], $batch )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$read_error = $this->read_error( $translation_terms );

			if ( $read_error !== '' || ! is_array( $translation_terms ) ) {
				$this->record_read_error( ObjectType::Term, $read_error );
				continue;
			}

			$imported += $this->process_term_translation_terms( $translation_terms, $result );
			// Drop this batch's runtime cache and SAVEQUERIES log; the persistent cache is untouched.
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		$this->beat( 'terms', count( $term_ids ), count( $term_ids ) );
		return $imported;
	}

	/**
	 * Per-batch processor for the term-translations taxonomy terms.
	 *
	 * @param array<int, object>   $translation_terms Term rows for this batch.
	 * @param array<string, mixed> $result            Accumulator (errors[]).
	 * @phpstan-param ImportResult $result
	 * @return int
	 */
	private function process_term_translation_terms( array $translation_terms, array &$result ): int {
		$imported = 0;

		// Which listed terms exist: one read for the batch (as for posts).
		$listed = [];

		foreach ( $translation_terms as $term ) {
			$vars        = get_object_vars( $term );
			$description = is_string( $vars['description'] ?? null ) ? $vars['description'] : '';
			$decoded     = self::decode_description( $description );

			foreach ( is_array( $decoded ) ? self::set_members( $decoded ) : [] as $term_id ) {
				if ( is_int( $term_id ) || is_string( $term_id ) ) {
					$term_id = (int) $term_id;

					if ( $term_id > 0 ) {
						$listed[ $term_id ] = true;
					}
				}
			}
		}

		if ( $listed !== [] ) {
			$this->groups->prime_translations( ObjectType::Term, array_keys( $listed ) );
		}

		$exists = $this->existing_ids( ObjectType::Term, array_keys( $listed ) );

		if ( null === $exists ) {
			return 0;
		}

		$this->start_slot_page( ObjectType::Term, $exists );

		foreach ( $translation_terms as $term ) {
			// Before each item: the report counts the items finished before it.
			$this->beat( $this->item_progress[0], $this->item_progress[1]++, $this->item_progress[2] );

			$translations = self::decode_description( (string) $term->description );

			// Surface an unreadable (truncated/corrupt) serialized description
			// instead of dropping it silently.
			if ( ! is_array( $translations ) && '' !== trim( (string) $term->description ) ) {
				$result['errors'][] = sprintf(
					/* translators: %d: term_taxonomy term id */
					__( 'Unreadable Polylang term_translations data for term %d - group skipped.', 'perflocale' ),
					(int) $term->term_id
				);
				continue;
			}

			if ( is_array( $translations ) && [] === self::set_members( $translations ) ) {
				$this->skip( 'empty_set', 'term ' . self::row_int( $term, 'term_id' ) );
				continue;
			}

			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue;
			}

			$imported += $this->apply_set( ObjectType::Term, self::row_int( $term, 'term_id' ), $translations, $exists, $result );
		}

		return $imported;
	}

	/**
	 * Create one-member groups for objects with a Polylang language but no linked translations.
	 *
	 * Such objects sit only in the `language` / `term_language` taxonomies, unseen by the set passes;
	 * a non-default one needs a link or it is served as the default language.
	 *
	 * @param string               $taxonomy Polylang language taxonomy name.
	 * @param ObjectType           $type     Object type the taxonomy classifies.
	 * @param array<string, mixed> $result   Accumulator (errors[]).
	 * @phpstan-param ImportResult $result
	 * @return int Number of groups created.
	 */
	private function import_single_language_objects( string $taxonomy, ObjectType $type, array &$result ): int {
		$imported = 0;

		$default_lang    = $this->languages->get_default();
		$default_lang_id = $default_lang !== null ? (int) $default_lang->id : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$lang_terms = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, t.slug
				FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				$taxonomy
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$read_error = $this->read_error( $lang_terms );

		if ( $read_error !== '' || ! is_array( $lang_terms ) ) {
			$this->record_read_error( $type, $read_error );
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$stage      = $type === ObjectType::Post ? 'single_posts' : 'single_terms';
		$done       = 0;
		$total      = 0;

		$this->beat( $stage, 0, 0 );

		foreach ( $lang_terms as $lang_term ) {
			// The term_language slugs carry a 'pll_' prefix; stripped to match the language_map keys.
			$slug    = sanitize_text_field( (string) $lang_term->slug );
			$slug    = str_starts_with( $slug, 'pll_' ) ? substr( $slug, 4 ) : $slug;
			$lang_id = $this->language_map[ $slug ] ?? null;

			// Objects in a language with no PerfLocale language are counted, not imported.
			if ( $lang_id === null ) {
				if ( isset( $this->unmapped[ $slug ] ) ) {
					$this->count_unmapped_objects( $lang_term, $type, $slug, $batch_size );
				}

				continue;
			}

			// A default-language object needs no row (the fallback serves it); one PerfLocale has in
			// another language goes through import_default_language_objects().
			if ( $lang_id === $default_lang_id ) {
				$imported += $this->import_default_language_objects( $lang_term, $type, $slug, $default_lang_id, $batch_size );
				continue;
			}

			// Keyset-paginate this language's object ids so a huge
			// single-language content set never lands in memory at once.
			$last_object_id = 0;

			do {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$object_ids = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT object_id FROM {$this->wpdb->term_relationships}
						WHERE term_taxonomy_id = %d AND object_id > %d
						ORDER BY object_id ASC
						LIMIT %d",
						(int) $lang_term->term_taxonomy_id,
						$last_object_id,
						$batch_size
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

				// The keyset cursor cannot move past a page it never read, so
				// this language ends here; the other languages still import.
				$read_error = $this->read_error( $object_ids );

				if ( $read_error !== '' ) {
					$this->record_read_error( $type, $read_error );
					break;
				}

				if ( empty( $object_ids ) ) {
					break;
				}

				$object_ids     = array_map( 'intval', $object_ids );
				$last_object_id = (int) end( $object_ids );

				// Prime existence + group lookups for the whole batch so the
				// per-object checks below are cache hits, not per-row SELECTs.
				if ( ObjectType::Post === $type && function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( $object_ids, false, false );
				}

				$this->groups->prime_translations( $type, $object_ids );

				// Which of the page's objects exist: one read for the page. When
				// it fails this language ends here, as for a failed page read.
				$exists = $this->existing_ids( $type, $object_ids );

				if ( null === $exists ) {
					break;
				}

				$this->start_slot_page( $type, $exists );

				foreach ( $object_ids as $object_id ) {
					if ( ! isset( $exists[ $object_id ] ) ) {
						$this->skip_missing( $type, $object_id, $slug );
						continue;
					}

					// A draft never imports as published; terms have no draft state.
					$link_status = ( ObjectType::Post === $type && get_post_status( $object_id ) !== 'publish' )
						? TranslationStatus::Draft->value
						: TranslationStatus::Published->value;

					// Done only when already filed under the right language: every saved post sits in an
					// auto-created default-language group, so a group-only check would leave a lone Polylang
					// `de` post served as the default language. It is relabelled inside its group.
					$existing_link = $this->groups->find_link_for_object( $object_id, $type );

					if ( $existing_link ) {
						if ( (int) $existing_link->language_id === (int) $lang_id ) {
							continue;
						}

						// A lone link no import wrote has no language yet. Any other is PerfLocale's: kept under
						// `keep`, relabelled under `source` only when no other object holds Polylang's language.
						$alone    = 1 === count( $this->groups->get_translations( $object_id, $type ) );
						$override = false;

						if ( ! $alone || self::imported_link( $existing_link ) ) {
							$example = sprintf( '%s %d (%s in PerfLocale, %s in Polylang)', $type->value, $object_id, $this->language_slug( (int) $existing_link->language_id ), $slug );

							if ( 'source' !== $this->policy( $type ) ) {
								$this->skip_conflict( 'language_differs', $type, $object_id, $example );
								continue;
							}

							$holder = $this->slot_holder( (int) $existing_link->group_id, (int) $lang_id );

							if ( $holder > 0 && $holder !== $object_id ) {
								$this->skip_conflict( 'language_taken', $type, $object_id, sprintf( '%s %d (%s, held by %s %d)', $type->value, $object_id, $slug, $type->value, $holder ) );
								continue;
							}

							$override = true;
						}

						$relinked = $this->groups->link_object(
							(int) $existing_link->group_id,
							$object_id,
							$lang_id,
							$link_status,
							SourceType::ImportedPolylang
						);

						$this->forget_slots( $object_id, self::row_int( $existing_link, 'group_id' ) );

						if ( $relinked !== false ) {
							++$imported;

							if ( $override ) {
								$this->count_overridden( 'language_differs', $type, $object_id, sprintf( '%s %d (%s in PerfLocale, now %s)', $type->value, $object_id, $this->language_slug( (int) $existing_link->language_id ), $slug ) );
							} elseif ( self::row_int( $existing_link, 'language_id' ) !== $default_lang_id ) {
								$this->count_overridden( 'set_before_import', $type, $object_id, sprintf( '%s %d (%s in PerfLocale, now %s)', $type->value, $object_id, $this->language_slug( self::row_int( $existing_link, 'language_id' ) ), $slug ) );
							}
						} else {
							++$this->link_failures;
						}

						continue;
					}

					$source_key = $lang_term->term_id . ':' . $object_id . '|' . $type->value;

					// A recorded group that is empty and of this kind takes
					// the object back; any other record is counted and
					// replaced by the new group's.
					$mapped_id = $this->source_map->get_group_id( 'polylang', (string) $source_key );

					if ( $mapped_id !== null ) {
						if ( SourceMapCheck::group_fits( $type, (int) $mapped_id, [ $object_id ], [ (int) $lang_id => 0 ], true ) ) {
							$relinked = $this->groups->link_object( (int) $mapped_id, $object_id, (int) $lang_id, $link_status, SourceType::ImportedPolylang );

							$this->forget_slots( $object_id, (int) $mapped_id );

							if ( false !== $relinked ) {
								++$imported;
							} else {
								++$this->link_failures;
							}

							continue;
						}

						if ( null !== $this->groups->find( (int) $mapped_id ) ) {
							$this->skip( 'stale_source_map', sprintf( '%s %d (%s)', $type->value, $object_id, $slug ) );
						}
					}

					$new_group_id = $this->groups->create_group(
						$type,
						$object_id,
						$lang_id,
						$link_status,
						SourceType::ImportedPolylang,
						[
							'type' => 'polylang',
							'key'  => (string) $source_key,
						]
					);

					$this->forget_slots( $object_id, is_numeric( $new_group_id ) ? (int) $new_group_id : 0 );

					if ( $new_group_id === false ) {
						$this->incomplete   = true;
						$result['errors'][] = sprintf(
							$type === ObjectType::Post
								/* translators: %d: post ID */
								? __( 'Failed to create single-language group for Polylang post %d.', 'perflocale' )
								/* translators: %d: term ID */
								: __( 'Failed to create single-language group for Polylang term %d.', 'perflocale' ),
							$object_id
						);
						continue;
					}

					++$imported;
				}

				// Drop this batch's runtime cache and SAVEQUERIES log; the persistent cache is untouched.
				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

				$done += count( $object_ids );

				if ( $total === 0 && count( $object_ids ) === $batch_size && $this->reports_progress() ) {
					$total = $this->count_single_language_objects( $lang_terms, $default_lang_id );
				}

				$this->beat( $stage, $done, $total );
			} while ( count( $object_ids ) === $batch_size );
		}

		$this->beat( $stage, $done, $done );

		return $imported;
	}

	/**
	 * Count the objects of one unmapped Polylang language, page by page (gone ones as missing).
	 *
	 * @param object     $lang_term  Row of the language taxonomy (term_taxonomy_id).
	 * @param ObjectType $type       Object type.
	 * @param string     $slug       Polylang language slug.
	 * @param int        $batch_size Page size.
	 * @return void
	 */
	private function count_unmapped_objects( object $lang_term, ObjectType $type, string $slug, int $batch_size ): void {
		$tt_id = self::row_int( $lang_term, 'term_taxonomy_id' );
		$last  = 0;
		$full  = true;

		while ( $full ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Keyset page of one language's objects, counted only; the table is bound with %i.
			$object_ids = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT object_id FROM %i WHERE term_taxonomy_id = %d AND object_id > %d ORDER BY object_id ASC LIMIT %d',
					$this->wpdb->term_relationships,
					$tt_id,
					$last,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			$read_error = $this->read_error( $object_ids );

			if ( $read_error !== '' ) {
				$this->record_read_error( $type, $read_error );
				return;
			}

			if ( empty( $object_ids ) ) {
				return;
			}

			$ids = [];

			foreach ( $object_ids as $object_id ) {
				$ids[] = is_numeric( $object_id ) ? (int) $object_id : 0;
			}

			$last   = (int) end( $ids );
			$full   = count( $ids ) === $batch_size;
			$exists = $this->existing_ids( $type, $ids );

			if ( null === $exists ) {
				return;
			}

			foreach ( $ids as $object_id ) {
				if ( isset( $exists[ $object_id ] ) ) {
					$this->skip_unmapped( $type, $object_id, $slug );
				} else {
					$this->skip_missing( $type, $object_id, $slug );
				}
			}

			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			$this->beat();
		}
	}

	/**
	 * Objects Polylang has in PerfLocale's default language that PerfLocale has in another language.
	 *
	 * A lone link no import wrote takes the default language (listed); any other is listed as
	 * language_differs and kept under both policies. One keyset query per page.
	 *
	 * @param object     $lang_term       Row of the language taxonomy (term_taxonomy_id).
	 * @param ObjectType $type            Post or Term.
	 * @param string     $slug            Polylang language slug.
	 * @param int        $default_lang_id PerfLocale default language ID.
	 * @param int        $batch_size      Page size.
	 * @return int Number of objects given the default language.
	 */
	private function import_default_language_objects( object $lang_term, ObjectType $type, string $slug, int $default_lang_id, int $batch_size ): int {
		$tt_id    = self::row_int( $lang_term, 'term_taxonomy_id' );
		$last     = 0;
		$full     = true;
		$imported = 0;

		while ( $full ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Keyset page of one language's objects that hold a link in another language; tables bound with %i.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT r.object_id, l.group_id, l.language_id, l.source FROM %i r INNER JOIN %i l ON l.object_id = r.object_id AND l.type = %s WHERE r.term_taxonomy_id = %d AND r.object_id > %d AND l.language_id <> %d ORDER BY r.object_id ASC LIMIT %d',
					$this->wpdb->term_relationships,
					\PerfLocale\Database\Schema::table( 'translation_links' ),
					$type->value,
					$tt_id,
					$last,
					$default_lang_id,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			$read_error = $this->read_error( $rows );

			if ( $read_error !== '' || ! is_array( $rows ) ) {
				$this->record_read_error( $type, $read_error );
				return $imported;
			}

			$full = count( $rows ) === $batch_size;

			// The page's memberships in one read, for the group check below.
			if ( [] !== $rows ) {
				$this->groups->prime_translations( $type, array_map( static fn( object $row ): int => self::row_int( $row, 'object_id' ), $rows ) );
			}

			foreach ( $rows as $row ) {
				$object_id = self::row_int( $row, 'object_id' );
				$old_lang  = self::row_int( $row, 'language_id' );
				$last      = max( $last, $object_id );

				if ( self::imported_link( $row ) || 1 !== count( $this->groups->get_translations( $object_id, $type ) ) ) {
					$this->skip_conflict( 'language_differs', $type, $object_id, sprintf( '%s %d (%s in PerfLocale, %s in Polylang)', $type->value, $object_id, $this->language_slug( $old_lang ), $slug ) );
					continue;
				}

				if ( false === $this->groups->link_object( self::row_int( $row, 'group_id' ), $object_id, $default_lang_id, $this->link_status( $type, $object_id ), SourceType::ImportedPolylang ) ) {
					++$this->link_failures;
					continue;
				}

				++$imported;
				$this->count_overridden( 'set_before_import', $type, $object_id, sprintf( '%s %d (%s in PerfLocale, now %s)', $type->value, $object_id, $this->language_slug( $old_lang ), $slug ) );
			}

			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			$this->beat();
		}

		return $imported;
	}

	/**
	 * Objects in the non-default languages, for the single-language stage's progress.
	 *
	 * @param array<int, object> $lang_terms      Rows of the language taxonomy (term_taxonomy_id, slug).
	 * @param int                $default_lang_id PerfLocale default language ID.
	 * @return int
	 */
	private function count_single_language_objects( array $lang_terms, int $default_lang_id ): int {
		$tt_ids = [];

		foreach ( $lang_terms as $lang_term ) {
			$slug    = sanitize_text_field( (string) $lang_term->slug );
			$slug    = str_starts_with( $slug, 'pll_' ) ? substr( $slug, 4 ) : $slug;
			$lang_id = $this->language_map[ $slug ] ?? null;

			if ( $lang_id !== null && $lang_id !== $default_lang_id ) {
				$tt_ids[] = (int) $lang_term->term_taxonomy_id;
			}
		}

		if ( $tt_ids === [] ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One count for the import's progress; the IN() list is generated placeholders, every value bound.
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE term_taxonomy_id IN (' . implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) ) . ')',
				array_merge( [ $this->wpdb->term_relationships ], $tt_ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return $count;
	}

	/**
	 * Resolve the per-batch fetch size for Polylang term reads.
	 *
	 * Smaller batches of serialized set descriptions use less memory and more SQL round trips.
	 *
	 * @return int Clamped to [10, 1000].
	 */
	private static function resolve_batch_size(): int {
		/**
		 * Filter the per-batch SELECT size used by the Polylang importer.
		 *
		 * Lower reduces peak memory on very large migrations, higher means fewer SQL round trips.
		 * Clamped to [10, 1000].
		 *
		 * @hook perflocale/migration/polylang/batch_size
		 * @since 1.0.0
		 *
		 * @param int $size Default 100.
		 * @return int
		 *
		 * @example
		 * add_filter( 'perflocale/migration/polylang/batch_size', fn() => 250 );
		 */
		$size = (int) apply_filters( 'perflocale/migration/polylang/batch_size', 100 );

		return max( 10, min( 1000, $size ) );
	}
}
