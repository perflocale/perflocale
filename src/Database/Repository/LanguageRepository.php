<?php
/**
 * Language repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Contract\RepositoryInterface;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for perflocale_languages: reads through the 3-layer cache, writes invalidate it and fire hooks.
 */
final class LanguageRepository implements RepositoryInterface {

	/**
	 * The slug shape the routing layer can express (the REST item routes, create_item() and SlugRedirector hard-code
	 * it); every entry of data/languages.php matches. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: the slug
	 * shape and the bootstrap".
	 */
	public const SLUG_PATTERN = '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/';

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb  = $wpdb;
		$this->cache = $cache;
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'languages' );
	}

	/**
	 * A language by id.
	 *
	 * @param int $id Language ID.
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		// An active language comes from the bootstrap already in memory, not a per-id transient (two queries): the front
		// end asks for the current language by id on every request.
		$bootstrap = $this->get_bootstrap();

		foreach ( $bootstrap['active'] as $row ) {
			if ( (int) $row->id === $id ) {
				return $row;
			}
		}

		// Inactive languages or a bootstrap miss: the per-id cache (admin dropdowns list every language).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cached = $this->cache->get(
			"language_{$id}",
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d',
					$this->table(),
					$id
				)
			),
			DAY_IN_SECONDS,
			'perflocale_langs'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A non-object payload from a corrupted persistent cache is a miss (see find_by_slug()).
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * Batch-fetch languages by IDs: one query for the uncached ones, which it caches for find().
	 *
	 * @param array<int, int> $ids Language IDs.
	 * @return array<int, object> Map of id => language object. Missing IDs are absent.
	 */
	public function find_many( array $ids ): array {
		return LanguageFindMany::find( $ids, fn(): array => $this->get_bootstrap()['active'], $this->cache, $this->wpdb );
	}

	/**
	 * A language by URL slug.
	 *
	 * @param string $slug Language slug (e.g. 'en', 'fr').
	 * @return object|null
	 */
	public function find_by_slug( string $slug ): ?object {
		$slug = sanitize_key( $slug );

		// The bootstrap's slug map first (every active language).
		$map = $this->get_bootstrap()['slug_map'];

		if ( isset( $map[ $slug ] ) ) {
			$bootstrap_hit = $map[ $slug ];
			// A persistent cache can return a malformed payload ('' from an older serialiser): a miss, re-queried, so the
			// ?object return type never fatals.
			if ( is_object( $bootstrap_hit ) ) {
				return $bootstrap_hit;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$cached = $this->cache->get(
			"language_slug_{$slug}",
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE slug = %s',
					$this->table(),
					$slug
				)
			),
			DAY_IN_SECONDS,
			'perflocale_langs'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A non-object cached payload is corruption: null now, replaced on the next rename or language change.
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * A language by WordPress locale.
	 *
	 * @param string $locale Locale string (e.g. 'en_US').
	 * @return object|null
	 */
	public function find_by_locale( string $locale ): ?object {
		$locale = sanitize_text_field( $locale );

		// The bootstrap first: active languages, what the public filters need.
		foreach ( $this->get_bootstrap()['active'] as $row ) {
			if ( isset( $row->locale ) && (string) $row->locale === $locale ) {
				return $row;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$cached = $this->cache->get(
			"language_locale_{$locale}",
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE locale = %s',
					$this->table(),
					$locale
				)
			),
			DAY_IN_SECONDS,
			'perflocale_langs'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A non-object cached payload is a miss (see find_by_slug()).
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * The bootstrap bundle: active list, default and slug map in one transient (2 queries instead of 6 without a
	 * persistent cache) and one coherent snapshot.
	 *
	 * @return array{active: array<int, object>, default: ?object, slug_map: array<string, object>}
	 */
	private function get_bootstrap(): array {
		$had_db_error = false;

		$result = $this->cache->get(
			'bootstrap',
			function () use ( &$had_db_error ): array {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// Probe the table first: wp_initialize_site fires switch_blog before activation runs on a new blog.
				$table_escaped = $this->wpdb->esc_like( $this->table() );
				$exists        = (string) $this->wpdb->get_var(
					$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_escaped )
				);

				// A failed probe (deadlock, "server gone away") also answers '': flag it so the empty bootstrap is not cached.
				if ( $this->wpdb->last_error !== '' ) {
					$had_db_error = true;
				}

				if ( $exists === '' ) {
					return [
						'active'   => [],
						'default'  => null,
						'slug_map' => [],
					];
				}

				// One SELECT of the active languages for all three parts.
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT * FROM %i WHERE is_active = 1 ORDER BY sort_order ASC, id ASC',
						$this->table()
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// A query error is never cached, only a successful empty table; last_error decides (get_results() returns null or
				// [] on error by driver).
				if ( $this->wpdb->last_error !== '' ) {
					$had_db_error = true;
				}

				$rows = is_array( $rows ) ? $rows : [];

				$default  = null;
				$slug_map = [];

				foreach ( $rows as $row ) {
					if ( ! empty( $row->is_default ) ) {
						$default = $row;
					}

					if ( isset( $row->slug ) && $row->slug !== '' ) {
						$slug_map[ (string) $row->slug ] = $row;
					}
				}

				return [
					'active'   => $rows,
					'default'  => $default,
					'slug_map' => $slug_map,
				];
			},
			DAY_IN_SECONDS,
			'perflocale_langs'
		);

		// A cold-load error would persist an empty bootstrap for a day and make the site monolingual: drop it so the next
		// request retries. A real empty table stays cached; nothing is announced (no data changed).
		if ( $had_db_error ) {
			$this->cache->evict( 'bootstrap', 'perflocale_langs' );
		}

		return $result;
	}

	/**
	 * Every active language by sort_order, from the bootstrap (the most called method).
	 *
	 * @return array<int, object>
	 */
	public function get_active(): array {
		return $this->get_bootstrap()['active'];
	}

	/**
	 * The default language.
	 *
	 * @return object|null
	 */
	public function get_default(): ?object {
		return $this->get_bootstrap()['default'];
	}

	/**
	 * Slug => language map.
	 *
	 * @return array<string, object>
	 */
	public function get_slug_map(): array {
		return $this->get_bootstrap()['slug_map'];
	}

	/**
	 * Languages matching the arguments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, object>
	 */
	public function find_all( array $args = [] ): array {
		// The no-argument call (every language by sort_order: admin screens, the REST list) is cached in the bootstrap's
		// group, so flush_languages() clears both.
		$is_canonical_default = empty( $args ) || (
			array_key_exists( 'is_active', $args ) && $args['is_active'] === null
			&& ( ( $args['orderby'] ?? 'sort_order' ) === 'sort_order' )
			&& ( strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) === 'ASC' )
		);

		if ( $is_canonical_default ) {
			return $this->cache->get(
				'all_sorted',
				function (): array {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $this->wpdb->get_results(
						$this->wpdb->prepare(
							'SELECT * FROM %i ORDER BY sort_order ASC, id ASC',
							$this->table()
						)
					);
					// phpcs:enable
					return is_array( $rows ) ? $rows : [];
				},
				DAY_IN_SECONDS,
				'perflocale_langs'
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$defaults = [
			'is_active' => null,
			'orderby'   => 'sort_order',
			'order'     => 'ASC',
		];

		$args       = wp_parse_args( $args, $defaults );
		$where      = '1=1';
		$query_args = [];

		if ( $args['is_active'] !== null ) {
			$where       .= ' AND is_active = %d';
			$query_args[] = (int) $args['is_active'];
		}

		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );

		if ( ! $orderby ) {
			$orderby = 'sort_order ASC';
		}

		$sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY {$orderby}";

		if ( ! empty( $query_args ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$query_args ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Insert a language.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @return int|false Inserted ID or false.
	 */
	public function insert( array $data ): int|false {
		$sanitized = self::sanitize_data( $data );

		// The full slug shape, as update() and rename_slug() check it: an empty or odd slug matches no rewrite rule and could
		// never be edited (sanitize_key() empties a non-ASCII slug).
		if ( ! isset( $sanitized['slug'] ) || ! is_string( $sanitized['slug'] ) || ! preg_match( self::SLUG_PATTERN, $sanitized['slug'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->table(), $sanitized );

		if ( $result === false ) {
			return false;
		}

		$id = (int) $this->wpdb->insert_id;

		$this->cache->flush_languages();

		// A slug made live must not stay a redirect source (as apply_slug_rename()): the Add Language flow renames a language
		// out of the way and inserts the new one under the freed slug.
		$this->drop_stale_slug_redirect(
			isset( $sanitized['slug'] ) && is_string( $sanitized['slug'] ) ? $sanitized['slug'] : ''
		);

		$language = $this->find( $id );

		/** @hook perflocale/language/added Fires after a new language is added. */
		do_action( 'perflocale/language/added', $language );

		return $id;
	}

	/**
	 * Remove a now-live slug from the 301 redirect map, for insert() and update() (apply_slug_rename() prunes in its own
	 * write); a compare-and-set write, and none when the map has no entry for the slug.
	 *
	 * @param string $slug Slug that just became live.
	 * @return void
	 */
	private function drop_stale_slug_redirect( string $slug ): void {
		if ( $slug === '' ) {
			return;
		}

		$wrote = false;

		$this->write_slug_redirects(
			static function ( array $redirects ) use ( $slug ): array {
				unset( $redirects[ $slug ] );

				return $redirects;
			},
			false,
			$wrote
		);

		if ( $wrote ) {
			$this->forget_cached_slug_redirects();
		}
	}

	/**
	 * Rename a language's slug and record an old => new 301 so `/old-slug/...` URLs keep working. Data-cheap (tables use
	 * the id); the cost is the rewrite flush and the redirect the front end reads.
	 *
	 * @param int    $id       Language ID.
	 * @param string $new_slug Proposed new slug (lowercase, alpha + hyphens).
	 * @return bool True on success.
	 */
	public function rename_slug( int $id, string $new_slug ): bool {
		$new_slug = strtolower( trim( $new_slug ) );

		// The shared SLUG_PATTERN, as update().
		if ( ! preg_match( self::SLUG_PATTERN, $new_slug ) ) {
			return false;
		}

		$old = $this->find( $id );

		if ( ! $old || (string) $old->slug === $new_slug ) {
			return false;
		}

		// Another language owns the slug: a clean false instead of a constraint error.
		if ( $this->find_by_slug( $new_slug ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table(),
			[ 'slug' => $new_slug ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $result === false ) {
			return false;
		}

		$old_slug = (string) $old->slug;

		// Everything beyond the row write lives in apply_slug_rename(), shared with update().
		$this->apply_slug_rename( $id, $old_slug, $new_slug );

		return true;
	}

	/**
	 * Everything a slug change implies beyond the row UPDATE: the 301 record, the reference migration, cache
	 * invalidation and the rewrite flush flag; shared by rename_slug() and update() (the Edit form's slug field).
	 * Docs: docs-ai/11-code-rationale.md, "LanguageRepository: slug renames and deletes".
	 *
	 * @param int    $id       Language ID whose slug just changed.
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return void
	 */
	private function apply_slug_rename( int $id, string $old_slug, string $new_slug ): void {
		// The redirect record, in one option (a site renames a handful of slugs).
		$recorded = $this->record_slug_redirect( $old_slug, $new_slug );

		// Every slug held as a key or value outside the id graph (attachments, menus, settings, user preferences).
		$this->migrate_slug_references( $old_slug, $new_slug );

		// Read the row after these writes: a delete that read before them would leave redirects and options for a dead
		// prefix. Still there: a later delete's clean-up sees the writes; gone: that clean-up runs here.
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $this->table(), $id ) );
		// A failed read is not a gone row (as in delete()): the settings stay.
		$gone = '' === $wpdb->last_error && 0 === $wpdb->num_rows;

		if ( $gone ) {
			$this->drop_slug_options( $new_slug );
		}

		// The cached map copies go once the row holds it, after the migration so its option reads reuse this request's list.
		if ( $recorded ) {
			$this->forget_cached_slug_redirects();
		}

		// The group flush plus explicit key deletes: some persistent backends do not fully honour flush_group().
		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );
		$this->cache->delete( "language_slug_{$old_slug}", 'perflocale_langs' );
		$this->cache->delete( "language_slug_{$new_slug}", 'perflocale_langs' );

		// The eager link map has the old slug in every link row of this language and outlives any TTL.
		$this->flush_translation_link_caches();

		// Rewrite rules are built from the active slugs: flush them on the next request.
		update_option( 'perflocale_flush_rules', 1, false );

		/** @hook perflocale/language/slug_renamed Fires after a language slug rename succeeds. */
		do_action( 'perflocale/language/slug_renamed', $id, $old_slug, $new_slug );
	}

	/**
	 * Write old => new into the redirect map with a compare-and-set (write_slug_redirects()).
	 *
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return bool True once the row holds the map; the caller then drops
	 *              the cached copies with forget_cached_slug_redirects().
	 */
	private function record_slug_redirect( string $old_slug, string $new_slug ): bool {
		return $this->write_slug_redirects(
			static fn( array $redirects ): array => self::redirect_map_after_rename( $redirects, $old_slug, $new_slug ),
			true
		);
	}

	/**
	 * Change the autoloaded redirect map with a compare-and-set on its row (a cached copy can be older): the UPDATE or
	 * DELETE matches only the value it was derived from, and a lost comparison re-reads the row and retries, bounded. An
	 * unchanged map writes nothing, confirmed against the row with $confirm_unchanged.
	 *
	 * @param callable $transform         Returns the map after the change, given the map as read.
	 * @param bool     $confirm_unchanged Whether an unchanged map read from the cached copy is confirmed
	 *                                    against the row.
	 * @param bool     $wrote             Set to true when a write of the row matched.
	 * @phpstan-param callable(array<mixed>): array<mixed> $transform
	 * @return bool True once the row holds the changed map, or nothing had to
	 *              change; false after the bounded attempts.
	 */
	private function write_slug_redirects( callable $transform, bool $confirm_unchanged, bool &$wrote = false ): bool {
		$wpdb     = $this->wpdb;
		$cached   = get_option( self::REDIRECTS_OPTION, null );
		$current  = null === $cached ? null : maybe_serialize( $cached );
		$expected = is_string( $current ) ? $current : null;
		$from_row = false;
		$settled  = false;
		$wrote    = false;

		for ( $attempt = 0; $attempt < self::REDIRECT_WRITE_ATTEMPTS; $attempt++ ) {
			$map   = null === $expected ? [] : (array) maybe_unserialize( $expected );
			$after = $transform( $map );
			$value = [] === $after ? null : maybe_serialize( $after );

			if ( $value === $expected ) {
				// Nothing to write: proven by a row read, or by the cached copy when the caller accepts it.
				if ( $from_row || ! $confirm_unchanged ) {
					$settled = true;
					break;
				}
			} elseif ( null === $value ) {
				// The change empties the map: the row goes, while it holds the value the change came from.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set delete of an autoloaded option; the caller purges the options cache.
				$affected = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, self::REDIRECTS_OPTION, $expected ) ?? '' );

				if ( 1 === $affected ) {
					$settled = true;
					$wrote   = true;
					break;
				}
			} elseif ( null === $expected ) {
				// Autoloaded: SlugRedirector reads it on every front-end request; the 50-entry cap keeps it small.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of an autoloaded option; the caller purges the options cache.
				$affected = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::REDIRECTS_OPTION, $value, function_exists( 'wp_determine_option_autoload_value' ) ? 'on' : 'yes' ) ?? '' );

				if ( 1 === $affected ) {
					$settled = true;
					$wrote   = true;
					break;
				}
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of an autoloaded option; the caller purges the options cache.
				$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $value, self::REDIRECTS_OPTION, $expected ) ?? '' );

				if ( 1 === $affected ) {
					$settled = true;
					$wrote   = true;
					break;
				}
			}

			if ( $attempt + 1 >= self::REDIRECT_WRITE_ATTEMPTS ) {
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row itself, past any cached copy.
			$row      = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::REDIRECTS_OPTION ) );
			$expected = null === $row ? null : (string) $row;
			$from_row = true;
		}

		return $settled;
	}

	/**
	 * The settings with a renamed slug moved from old to new wherever it is a key or a value.
	 *
	 * @param array<mixed> $settings Settings as stored.
	 * @param string       $old_slug Pre-rename slug.
	 * @param string       $new_slug Post-rename slug.
	 * @return array<mixed>
	 */
	private static function settings_after_rename( array $settings, string $old_slug, string $new_slug ): array {
		// geo_country_map: [slug => "CC,CC"], the slug as key (form field geo_country_map[<slug>]).
		if ( ! empty( $settings['geo_country_map'] )
			&& is_array( $settings['geo_country_map'] )
			&& isset( $settings['geo_country_map'][ $old_slug ] )
		) {
			$settings['geo_country_map'][ $new_slug ] = $settings['geo_country_map'][ $old_slug ];
			unset( $settings['geo_country_map'][ $old_slug ] );
		}

		// language_fallbacks: [slug => [fallback slugs]], the slug as key and as value.
		if ( ! empty( $settings['language_fallbacks'] ) && is_array( $settings['language_fallbacks'] ) ) {
			$rekeyed = [];

			foreach ( $settings['language_fallbacks'] as $key => $fallbacks ) {
				$new_key = $key === $old_slug ? $new_slug : $key;

				if ( is_array( $fallbacks ) ) {
					$fallbacks = array_map(
						static fn( $s ) => $s === $old_slug ? $new_slug : $s,
						$fallbacks
					);
				}

				$rekeyed[ $new_key ] = $fallbacks;
			}

			$settings['language_fallbacks'] = $rekeyed;
		}

		// language_domains [slug => domain] and wc_currencies [slug => rates], the slug as key; without the move the
		// currency override stops applying.
		foreach ( [ 'language_domains', 'wc_currencies' ] as $map ) {
			if ( ! empty( $settings[ $map ] ) && is_array( $settings[ $map ] ) && array_key_exists( $old_slug, $settings[ $map ] ) ) {
				$settings[ $map ][ $new_slug ] = $settings[ $map ][ $old_slug ];
				unset( $settings[ $map ][ $old_slug ] );
			}
		}

		return $settings;
	}

	/**
	 * The settings without a deleted slug's keys and values, the inverse of migrate_slug_references() (a stale
	 * wc_currencies or language_domains entry silently stops applying or routes nowhere).
	 *
	 * @param array<mixed> $settings Settings as stored.
	 * @param string       $slug     Deleted language's slug.
	 * @return array<mixed>
	 */
	private static function settings_without_language( array $settings, string $slug ): array {
		// geo_country_map: the slug as key.
		if ( ! empty( $settings['geo_country_map'] ) && is_array( $settings['geo_country_map'] ) ) {
			unset( $settings['geo_country_map'][ $slug ] );
		}

		// language_fallbacks: the slug as key and as value.
		if ( ! empty( $settings['language_fallbacks'] ) && is_array( $settings['language_fallbacks'] ) ) {
			unset( $settings['language_fallbacks'][ $slug ] );

			foreach ( $settings['language_fallbacks'] as $key => $fallbacks ) {
				if ( ! is_array( $fallbacks ) ) {
					continue;
				}
				$pruned = array_values(
					array_filter(
						$fallbacks,
						static fn( $s ): bool => $s !== $slug
					)
				);
				if ( $pruned !== $fallbacks ) {
					$settings['language_fallbacks'][ $key ] = $pruned;
				}
			}
		}

		// language_domains and wc_currencies: the slug as key.
		foreach ( [ 'language_domains', 'wc_currencies' ] as $map ) {
			if ( ! empty( $settings[ $map ] ) && is_array( $settings[ $map ] ) ) {
				unset( $settings[ $map ][ $slug ] );
			}
		}

		return $settings;
	}

	/**
	 * Change an array option with a compare-and-set on its row, as write_slug_redirects() does; through
	 * OptionWriter::compare_and_set(), so listeners see update_option()'s filters and actions. A missing row or a
	 * non-array value is left alone.
	 *
	 * @param string    $option    Option name.
	 * @param callable  $transform Returns the array after the change, given the array as read.
	 * @param bool|null $autoload  As update_option()'s $autoload: null keeps the row's.
	 * @phpstan-param callable(array<mixed>): array<mixed> $transform
	 * @return bool True when a write of the row matched.
	 */
	private function change_option( string $option, callable $transform, ?bool $autoload = null ): bool {
		$wpdb     = $this->wpdb;
		$cached   = get_option( $option, null );
		$expected = is_array( $cached ) ? maybe_serialize( $cached ) : null;
		$current  = $cached;

		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			if ( null === $expected ) {
				return false;
			}

			$before = maybe_unserialize( $expected );

			if ( ! is_array( $before ) ) {
				return false;
			}

			$after = $transform( $before );

			if ( $after === $before ) {
				return false;
			}

			// As update_option() writes it (filters may change or veto), only while the row holds what was read.
			$written = \PerfLocale\Database\OptionWriter::compare_and_set( $option, $after, $current, $expected, $autoload );

			if ( null !== $written ) {
				return $written;
			}

			if ( $attempt + 1 >= self::OPTION_CAS_ATTEMPTS ) {
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row itself, past any cached copy.
			$row      = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $option ) );
			$expected = null === $row ? null : (string) $row;

			// The hooks get the old value through `option_{$option}`, as update_option() passes it; the change is on the row.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter for this option.
			$current = null === $row ? null : apply_filters( "option_{$option}", maybe_unserialize( (string) $row ), $option );
		}

		return false;
	}

	/**
	 * Drop a gone language's slug from options: perflocale_settings, perflocale_exchange_rates and every redirect naming
	 * it. Run by delete(), and by apply_slug_rename() when the language was deleted meanwhile.
	 *
	 * @param string $slug The slug.
	 * @return void
	 */
	private function drop_slug_options( string $slug ): void {
		// Every settings reference holding the slug as key or value, written with change_option()'s compare-and-set: the
		// copy read can be older than the row, and a plain write would undo another save.
		$settings_written = $this->change_option(
			'perflocale_settings',
			static fn( array $settings ): array => self::settings_without_language( $settings, $slug )
		);

		if ( $settings_written ) {
			// Reload the Settings singleton before anything later in this request reads it.
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$plugin->get( 'settings' )->reset_cache();
			}
		}

		// WooCommerce's fetched exchange rates, keyed by slug (ExchangeRateSync would drop it only at its next sync).
		$this->change_option(
			'perflocale_exchange_rates',
			static function ( array $rates ) use ( $slug ): array {
				unset( $rates[ $slug ] );

				return $rates;
			},
			false
		);

		// Drop every redirect whose key or value is the slug: one into a dead prefix is a permanent 301 to 404. With the
		// compare-and-set of write_slug_redirects(), confirmed against the row (a redirect recorded since is only there).
		$wrote = false;

		$this->write_slug_redirects(
			static function ( array $redirects ) use ( $slug ): array {
				$pruned = [];

				foreach ( $redirects as $from => $to ) {
					if ( (string) $from === $slug || (string) $to === $slug ) {
						continue;
					}
					$pruned[ $from ] = $to;
				}

				return $pruned;
			},
			true,
			$wrote
		);

		if ( $wrote ) {
			$this->forget_cached_slug_redirects();
		}
	}

	/**
	 * Drop every cached copy of the redirect map (alloptions, its key, its `notoptions` entry) so the next read loads
	 * the row.
	 *
	 * @return void
	 */
	private function forget_cached_slug_redirects(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::REDIRECTS_OPTION, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ self::REDIRECTS_OPTION ] ) ) {
			unset( $notoptions[ self::REDIRECTS_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * The redirect map once a slug is renamed.
	 *
	 * @param array<mixed> $redirects Map as stored, old slug => new slug.
	 * @param string       $old_slug  Pre-rename slug.
	 * @param string       $new_slug  Post-rename slug.
	 * @return array<mixed>
	 */
	private static function redirect_map_after_rename( array $redirects, string $old_slug, string $new_slug ): array {
		// Compress chains: entries pointing at the old slug point at the new one, so one 301 lands at the end.
		foreach ( $redirects as $existing_old => $existing_new ) {
			if ( $existing_new === $old_slug ) {
				$redirects[ $existing_old ] = $new_slug;
			}
		}

		$redirects[ $old_slug ] = $new_slug;

		// The new slug is live: never a redirect source (left by an earlier rename or a slug swap).
		unset( $redirects[ $new_slug ] );

		// Drop self-mappings compression can leave.
		$redirects = array_filter(
			$redirects,
			static fn( $v, $k ) => $v !== $k,
			ARRAY_FILTER_USE_BOTH
		);

		// The last 50 only, to bound the option.
		if ( count( $redirects ) > 50 ) {
			$redirects = array_slice( $redirects, -50, null, true );
		}

		return $redirects;
	}

	/**
	 * Drop every cache that copies language columns into link rows: the autoloaded eager map has no TTL and is read
	 * first, so a stale slug there drops the language from hreflang, sitemap alternates and the switcher until
	 * invalidated at the write site.
	 *
	 * @return void
	 */
	private function flush_translation_link_caches(): void {
		$group_repo = new TranslationGroupRepository( $this->cache );
		$group_repo->invalidate_eager_link_map();
		TranslationGroupRepository::reset_static_caches();
		$this->cache->flush_translations();
	}

	/**
	 * Move a renamed slug wherever it is stored outside the languages table: per-language meta key suffixes, the
	 * `_perflocale_language` values (menus, orders incl. HPOS), the settings arrays, exchange rates and user preferences,
	 * with the caches the direct writes skip. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: what a slug rename migrates".
	 *
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return void
	 */
	private function migrate_slug_references( string $old_slug, string $new_slug ): void {
		global $wpdb;

		// 1. Attachment meta keys with the slug: a direct UPDATE of meta_key (indexed range, few rows, admin only), so the
		// slow-query sniff is a false positive.
		$post_meta_prefixes = [
			'_perflocale_alt_',
			'_perflocale_caption_',
			'_perflocale_description_',
		];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		foreach ( $post_meta_prefixes as $prefix ) {
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_key' => $prefix . $new_slug ],
				[ 'meta_key' => $prefix . $old_slug ],
				[ '%s' ],
				[ '%s' ]
			);
		}

		// 2. Linked-menu term meta, the same way.
		$wpdb->update(
			$wpdb->termmeta,
			[ 'meta_key' => '_perflocale_menu_' . $new_slug ],
			[ 'meta_key' => '_perflocale_menu_' . $old_slug ],
			[ '%s' ],
			[ '%s' ]
		);

		// 2b. Term meta `_perflocale_language` (a nav menu's language) holds the slug as value; SELECT first for the cache.
		$affected_term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
				'_perflocale_language',
				$old_slug
			)
		);

		if ( ! empty( $affected_term_ids ) ) {
			// A one-off admin migration: the meta_value warning does not justify a dedicated table.
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$wpdb->termmeta,
				[ 'meta_value' => $new_slug ],
				[
					'meta_key'   => '_perflocale_language',
					'meta_value' => $old_slug,
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			// Direct updates fire no meta hooks: delete the term meta caches (wp_cache_delete_multiple() on WP 6.0+).
			$ids_int = array_map( 'intval', (array) $affected_term_ids );

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $ids_int, 'term_meta' );
			} else {
				foreach ( $ids_int as $tid ) {
					wp_cache_delete( $tid, 'term_meta' );
				}
			}
		}

		// 2c. Post meta `_perflocale_language` (WooCommerce orders, for the order email language); generic for any consumer.
		$affected_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_perflocale_language',
				$old_slug
			)
		);

		if ( ! empty( $affected_post_ids ) ) {
			// A one-off admin migration (as the term meta above).
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => $new_slug ],
				[
					'meta_key'   => '_perflocale_language',
					'meta_value' => $old_slug,
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			$ids_int = array_map( 'intval', (array) $affected_post_ids );

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $ids_int, 'post_meta' );
			} else {
				foreach ( $ids_int as $pid ) {
					wp_cache_delete( $pid, 'post_meta' );
				}
			}
		}

		// 2d. The same tag in `{prefix}wc_orders_meta`, where HPOS stores it; whenever the table exists, as compatibility
		// mode keeps both copies. Missed, later status emails go out in the default language.
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$has_orders_meta = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) ) === $orders_meta;

		if ( $has_orders_meta ) {
			$affected_order_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %s',
					$orders_meta,
					'_perflocale_language',
					$old_slug
				)
			);

			if ( ! empty( $affected_order_ids ) ) {
				$wpdb->update(
					$orders_meta,
					[ 'meta_value' => $new_slug ],
					[
						'meta_key'   => '_perflocale_language',
						'meta_value' => $old_slug,
					],
					[ '%s' ],
					[ '%s', '%s' ]
				);

				// As WooCommerce invalidates a direct meta write, plus the cached order object through its own OrderCache (the keys
				// are namespaced: deleting the bare id deletes nothing and the old slug stays served for an hour).
				$order_cache = null;

				if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
					try {
						$order_cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );
					} catch ( \Throwable $e ) {
						$order_cache = null;   // Container not booted, or the class not registered.
					}
				}

				foreach ( array_map( 'intval', (array) $affected_order_ids ) as $oid ) {
					if ( class_exists( '\WC_Order' ) && method_exists( '\WC_Order', 'generate_meta_cache_key' ) ) {
						wp_cache_delete( \WC_Order::generate_meta_cache_key( $oid, 'orders' ), 'orders' );
					}

					if ( null !== $order_cache ) {
						$order_cache->remove( $oid );
					}

					// For a WooCommerce without OrderCache; harmless otherwise.
					wp_cache_delete( $oid, 'orders' );
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		// 3. Settings and 4b. WooCommerce's fetched exchange rates (else 1:1 until the next sync), both with the
		// compare-and-set of change_option(), so a save committed since the read is not undone.
		$settings_written = $this->change_option(
			'perflocale_settings',
			static fn( array $settings ): array => self::settings_after_rename( $settings, $old_slug, $new_slug )
		);

		if ( $settings_written ) {
			// Reload the Settings singleton before anything reads it again in this request.
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$plugin->get( 'settings' )->reset_cache();
			}
		}

		$this->change_option(
			'perflocale_exchange_rates',
			static function ( array $rates ) use ( $old_slug, $new_slug ): array {
				if ( array_key_exists( $old_slug, $rates ) ) {
					$rates[ $new_slug ] = $rates[ $old_slug ];
					unset( $rates[ $old_slug ] );
				}

				return $rates;
			},
			false
		);

		// 4. Per-user hidden-language arrays (Strings and Translations columns): only the rows holding the slug; network-wide
		// per user, as delete()'s sweep.
		$user_meta_keys = [
			'perflocale_strings_hidden_langs',
			'perflocale_translations_hidden_langs',
		];

		foreach ( $user_meta_keys as $meta_key ) {
			// One-off per-key scan.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta}
					WHERE meta_key = %s AND meta_value LIKE %s",
					$meta_key,
					'%' . $wpdb->esc_like( $old_slug ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$arr = maybe_unserialize( $row->meta_value );

				if ( ! is_array( $arr ) ) {
					continue;
				}

				$mutated = false;
				$next    = [];

				foreach ( $arr as $entry ) {
					if ( $entry === $old_slug ) {
						$next[]  = $new_slug;
						$mutated = true;
					} else {
						$next[] = $entry;
					}
				}

				if ( $mutated ) {
					update_user_meta( (int) $row->user_id, $meta_key, $next );
				}
			}
		}
	}

	/**
	 * The slug redirect map, read by the front-end redirect on every request (one autoloaded option).
	 *
	 * @return array<string, string> [old_slug => new_slug]
	 */
	public static function get_slug_redirects(): array {
		return (array) get_option( self::REDIRECTS_OPTION, [] );
	}

	/**
	 * Option of the slug redirect map, public so uninstall can delete it without loading this class.
	 */
	public const REDIRECTS_OPTION = 'perflocale_slug_redirects';

	/**
	 * Redirect map writes a rename tries, each after a lost compare-and-set re-reads the row.
	 */
	private const REDIRECT_WRITE_ATTEMPTS = 5;

	/**
	 * Option writes change_option() tries, each after a lost compare-and-set re-reads the row.
	 */
	private const OPTION_CAS_ATTEMPTS = 5;

	/**
	 * Update a language.
	 *
	 * @param int $id Language ID.
	 * @param array<string, mixed> $data Data to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$old = $this->find( $id );

		if ( ! $old ) {
			return false;
		}

		$sanitized = self::sanitize_data( $data );

		// The Edit form's slug field is editable, so a rename can arrive here: read both sides before the write.
		$before   = (array) $old;
		$old_slug = isset( $before['slug'] ) && is_string( $before['slug'] ) ? $before['slug'] : '';
		$new_slug = isset( $sanitized['slug'] ) && is_string( $sanitized['slug'] ) ? $sanitized['slug'] : '';

		// Any slug change invalidates the eager map (it holds language_slug in every link row of this language).
		$slug_differs = isset( $sanitized['slug'] ) && $new_slug !== $old_slug;

		// Only a move between two real slugs is a rename: an empty side would record a 301 keyed by '' that never fires
		// and run the migration with a LIKE '%%'.
		$slug_changed = $slug_differs && $old_slug !== '' && $new_slug !== '';

		// A changed slug must match SLUG_PATTERN, as rename_slug() demands; an unchanged legacy slug keeps saving, so no
		// site is locked out of its Edit screen.
		if ( $slug_differs && $new_slug !== '' && ! preg_match( self::SLUG_PATTERN, $new_slug ) ) {
			return false;
		}

		// Name and native name ride on every cached link row too.
		$labels_changed = ( isset( $sanitized['name'] ) && $sanitized['name'] !== ( $before['name'] ?? null ) )
			|| ( isset( $sanitized['native_name'] ) && $sanitized['native_name'] !== ( $before['native_name'] ?? null ) );

		// An is_active toggle (the REST PATCH sends it alone) changes routing: drop the eager map too, which has no TTL.
		$active_changed = isset( $sanitized['is_active'] )
			&& (int) $sanitized['is_active'] !== (int) ( $before['is_active'] ?? 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table(),
			$sanitized,
			[ 'id' => $id ],
			null,
			[ '%d' ]
		);

		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		// Only after a successful update, so a UNIQUE collision records no 301 for a rename that did not land.
		if ( $result !== false ) {
			if ( $slug_changed ) {
				$this->apply_slug_rename( $id, $old_slug, $new_slug );
			} elseif ( $labels_changed || $active_changed || $slug_differs ) {
				// An empty slug on one side: no 301 or migration, but the eager map goes; a slug filled in from empty is newly live
				// and pruned as a redirect source (a no-op otherwise).
				$this->drop_stale_slug_redirect( $new_slug );
				$this->flush_translation_link_caches();
			}
		}

		$language = $this->find( $id );

		/** @hook perflocale/language/updated Fires after a language is updated. */
		do_action( 'perflocale/language/updated', $language, $before );

		return $result !== false;
	}

	/**
	 * Rewrite sort_order for these ids in their order, starting at `$offset + 1` (a paginated slice keeps other pages'
	 * range). Every id is validated before any write, so a bogus one cannot half-apply.
	 *
	 * @param array<int, int> $ordered_ids Language IDs in their new visual order.
	 * @param int             $offset      Starting position (0-based). Sort orders begin at `offset + 1`.
	 * @return int Number of rows updated.
	 */
	public function reorder( array $ordered_ids, int $offset = 0 ): int {
		$ordered_ids = array_values( array_map( 'intval', $ordered_ids ) );

		if ( empty( $ordered_ids ) ) {
			return 0;
		}

		// Every id must exist; duplicates dropped.
		$ordered_ids  = array_values( array_unique( $ordered_ids ) );
		$placeholders = implode( ',', array_fill( 0, count( $ordered_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Class-owned table, placeholders from validated int ids.
		$existing = (array) $this->wpdb->get_col(
			$this->wpdb->prepare( "SELECT id FROM %i WHERE id IN ($placeholders)", array_merge( [ $this->table() ], $ordered_ids ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( count( $existing ) !== count( $ordered_ids ) ) {
			return 0;
		}

		$offset = max( 0, $offset );

		// One UPDATE ... CASE id WHEN ... END instead of one query per language.
		$cases = [];
		$args  = [];

		foreach ( $ordered_ids as $position => $id ) {
			$cases[] = 'WHEN %d THEN %d';
			$args[]  = $id;
			$args[]  = $offset + $position + 1;
		}

		$args = array_merge( $args, $ordered_ids );

		// Every part is plugin-built ('WHEN %d THEN %d', array_fill('%d')); $args carries the ints.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET sort_order = CASE id ' . implode( ' ', $cases ) . " END WHERE id IN ({$placeholders})",
				array_merge( [ $this->table() ], $args )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// flush_languages() clears the whole group, per-id entries included.
		$updated = $result === false ? 0 : count( $ordered_ids );

		$this->cache->flush_languages();

		/** @hook perflocale/languages/reordered Fires after the language list is reordered. */
		do_action( 'perflocale/languages/reordered', $ordered_ids, $offset );

		return $updated;
	}

	/**
	 * Count what delete() would remove for a language, for the delete preview: each key mirrors one cascade step of
	 * delete(); keep the two in step.
	 *
	 * @param int $id Language ID.
	 * @return array<string, int> Table-ish label => row count.
	 */
	public function count_cascade( int $id ): array {
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$counts = [
			'translation_links'   => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', $links_table, $id )
			),
			// Groups left empty once this language's links go, as delete()'s group GC removes them (string groups excluded).
			'translation_groups'  => (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i g
					 WHERE g.type != 'string'
					   AND EXISTS ( SELECT 1 FROM %i l1 WHERE l1.group_id = g.id AND l1.language_id = %d )
					   AND NOT EXISTS ( SELECT 1 FROM %i l2 WHERE l2.group_id = g.id AND l2.language_id != %d )",
					$groups_table,
					$links_table,
					$id,
					$links_table,
					$id
				)
			),
			'string_translations' => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', Schema::table( 'string_translations' ), $id )
			),
			'slug_translations'   => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', Schema::table( 'slug_translations' ), $id )
			),
		];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $counts;
	}

	/**
	 * Count the content that blocks deleting a language: its posts, which would become default-language duplicates with
	 * split WooCommerce stock (trash them or deactivate the language). Attachments, terms and templates are reported
	 * only. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: deleting a language".
	 *
	 * @param int $id Language ID.
	 * @return array{posts: array<string, int>, attachments: int, terms: int, templates: int}|null
	 *         Blocking post counts by post type, plus the informational counts;
	 *         null when the count query failed (callers must refuse).
	 */
	public function delete_blockers( int $id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uncached on purpose: a delete decision reads the live rows; tables bound through %i.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT g.type AS group_type, p.post_type AS post_type, COUNT(*) AS n
				 FROM %i l
				 INNER JOIN %i g ON g.id = l.group_id
				 LEFT JOIN %i p ON g.type = 'post' AND p.ID = l.object_id
				 WHERE l.language_id = %d
				   AND (
				     g.type = 'term'
				     OR ( g.type = 'post' AND p.ID IS NOT NULL AND p.post_status NOT IN ( 'trash', 'auto-draft' ) )
				   )
				 GROUP BY g.type, p.post_type",
				Schema::table( 'translation_links' ),
				Schema::table( 'translation_groups' ),
				$this->wpdb->posts,
				$id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) || $this->wpdb->last_error !== '' ) {
			return null;
		}

		$blockers = [
			'posts'       => [],
			'attachments' => 0,
			'terms'       => 0,
			'templates'   => 0,
		];

		foreach ( $rows as $row ) {
			$count = (int) $row->n;

			if ( (string) $row->group_type === 'term' ) {
				$blockers['terms'] += $count;
			} elseif ( (string) $row->post_type === 'attachment' ) {
				$blockers['attachments'] += $count;
			} elseif ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) $row->post_type ) ) {
				$blockers['templates'] += $count;
			} else {
				$blockers['posts'][ (string) $row->post_type ] = $count;
			}
		}

		return $blockers;
	}

	/**
	 * Delete a language and everything that references it.
	 *
	 * @param int $id Language ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$language = $this->find( $id );

		if ( ! $language ) {
			return false;
		}

		// Every cascade DELETE in one transaction, so a timeout or lost connection cannot leave links pointing at a removed
		// language; any failure rolls back and returns false (as set_default()).
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$slug_table   = Schema::table( 'slug_translations' );
		$slug_rows    = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// The slug as the row holds it, under a row lock: a concurrent rename is seen here or waits and cleans up after itself.
			$wpdb = $this->wpdb;
			// Row lock on MySQL only (see ConnectionGuard::lock_suffix()).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table bound with %i; the lock suffix is a literal.
			$slug_now = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE id = %d' . \PerfLocale\Database\ConnectionGuard::lock_suffix(), $this->table(), $id ) );

			// Nothing deleted after a failed read (SQLite ended the transaction); a gone row was deleted by another request.
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'delete_language: failed to read the languages row' );
			}

			if ( 0 === $wpdb->num_rows ) {
				throw new \RuntimeException( 'delete_language: the languages row is gone' );
			}

			if ( is_string( $slug_now ) && $slug_now !== $language->slug ) {
				$language       = clone $language;
				$language->slug = $slug_now;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
			if ( $result === false ) {
				throw new \RuntimeException( 'delete_language: failed to delete languages row' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->delete( $links_table, [ 'language_id' => $id ], [ '%d' ] ) ) {
				throw new \RuntimeException( 'delete_language: failed to delete translation_links' );
			}

			// Remove the non-string groups left empty, in one pass. One table per DELETE: the SQLite integration's 2.2.x legacy
			// driver rejects `DELETE g FROM` and mishandles a JOIN.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM %i WHERE type != 'string' AND id NOT IN ( SELECT group_id FROM %i )",
					$groups_table,
					$links_table
				)
			) ) {
				throw new \RuntimeException( 'delete_language: failed to garbage-collect orphan translation_groups' );
			}

			// Same connection, so part of the transaction.
			( new StringTranslationRepository( $this->cache ) )->delete_for_language( $id );

			// The slug rows' identifiers before their DELETE, for the cache invalidation after the commit.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$slug_rows_raw = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT object_type, object_id FROM %i WHERE language_id = %d',
					$slug_table,
					$id
				)
			);
			// A failed read answers []: last_error decides.
			if ( $slug_rows_raw === false || '' !== $this->wpdb->last_error ) {
				throw new \RuntimeException( 'delete_language: failed to capture slug rows for cache invalidation' );
			}
			$slug_rows = (array) $slug_rows_raw;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->delete( $slug_table, [ 'language_id' => $id ], [ '%d' ] ) ) {
				throw new \RuntimeException( 'delete_language: failed to delete slug_translations' );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				// A failed COMMIT means the server rolled back: report failure.
				return false;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		// Source-map rows of the removed groups, best effort and after the commit (inside, a failure ends the transaction on
		// SQLite); the importer's dead-group guard covers any left.
		$source_map_table = Schema::table( 'migration_source_map' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE group_id NOT IN ( SELECT id FROM %i )',
				$source_map_table,
				$groups_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Per-row L2 keys as SlugTranslationRepository::get_slug() builds them: "slug_{type}_{id}_{lang}" in 'perflocale_slugs'.
		foreach ( (array) $slug_rows as $slug_row ) {
			$cache_key = sprintf( 'slug_%s_%d_%d', (string) $slug_row->object_type, (int) $slug_row->object_id, $id );
			$this->cache->delete( $cache_key, 'perflocale_slugs' );
		}

		// L1, L3 and the zero-state flag (L2 was done per row).
		$this->cache->flush_slugs();
		SlugTranslationRepository::reset_static_caches();

		// The eager map and translations_* caches still hold the removed links (hreflang, the switcher).
		$this->flush_translation_link_caches();

		// Everything here is keyed by the slug; with '' the meta sweeps would match every row: skipped.
		if ( '' !== (string) $language->slug ) {
			// Slug-keyed attachment and menu meta, through delete_metadata( $type, 0, $key, '', true ) so the meta caches go too.
			$post_meta_prefixes = [
				'_perflocale_alt_',
				'_perflocale_caption_',
				'_perflocale_description_',
			];

			foreach ( $post_meta_prefixes as $prefix ) {
				delete_metadata( 'post', 0, $prefix . $language->slug, '', true );
			}

			delete_metadata( 'term', 0, '_perflocale_menu_' . $language->slug, '', true );

			// `_perflocale_language` term meta holds the slug as value: remove every row with it.
			delete_metadata( 'term', 0, '_perflocale_language', $language->slug, true );

			// Hidden-language user meta, as the rename does but removing the slug, only rows containing it. Unprefixed, so on a
			// network one array per user serves every site (as core's column preferences).
			$user_meta_keys = [
				'perflocale_strings_hidden_langs',
				'perflocale_translations_hidden_langs',
			];

			foreach ( $user_meta_keys as $meta_key ) {
				// usermeta is core's own table; the key and slug go through %s.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT umeta_id, user_id, meta_value FROM {$this->wpdb->usermeta}
						WHERE meta_key = %s AND meta_value LIKE %s",
						$meta_key,
						'%' . $this->wpdb->esc_like( $language->slug ) . '%'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( ! is_array( $rows ) ) {
					continue;
				}

				foreach ( $rows as $row ) {
					$arr = maybe_unserialize( $row->meta_value );

					if ( ! is_array( $arr ) ) {
						continue;
					}

					$next = array_values(
						array_filter(
							$arr,
							static fn( $entry ): bool => $entry !== $language->slug
						)
					);

					if ( $next !== $arr ) {
						update_user_meta( (int) $row->user_id, $meta_key, $next );
					}
				}
			}
		}

		// The settings, exchange rates and slug redirects that name the slug.
		$this->drop_slug_options( is_string( $language->slug ) ? $language->slug : '' );

		// The generated `<domain>-<locale>.l10n.php` files of this locale.
		$locale = isset( $language->locale ) ? (string) $language->locale : '';

		if ( $locale !== '' ) {
			$upload_dir = wp_upload_dir();
			$base       = $upload_dir['basedir'] . '/perflocale/translations';
			$pattern    = $base . '/*-' . sanitize_file_name( $locale ) . '.l10n.php';
			$files      = glob( $pattern );

			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					wp_delete_file( $file );
				}
			}
		}

		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		/** @hook perflocale/language/deleted Fires after a language is deleted. */
		do_action( 'perflocale/language/deleted', $id, $language->slug );

		return true;
	}

	/**
	 * Make a language the default (unsetting the current one).
	 *
	 * @param int $id Language ID to make default.
	 * @return bool
	 * @throws \RuntimeException When the unset-default or set-default UPDATE fails inside the transaction.
	 */
	public function set_default( int $id ): bool {
		// Refuse a missing or inactive target first: update() answers 0 for no match, which would leave no default, and an
		// inactive default reads as null.
		$target = $this->find( $id );

		if ( $target === null || empty( $target->is_active ) ) {
			return false;
		}

		$old_default    = $this->get_default();
		$old_default_id = $old_default ? (int) $old_default->id : 0;

		// Both UPDATEs in one transaction, so a failure between them cannot leave the site without a default.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Unset every default (any non-zero value, as readers count it).
			$unset_sql = $this->wpdb->prepare( 'UPDATE %i SET is_default = 0 WHERE is_default <> 0', $this->table() );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared on the line above.
			$unset_ok = is_string( $unset_sql ) ? $this->wpdb->query( $unset_sql ) : false;

			if ( $unset_ok === false ) {
				throw new \RuntimeException( 'set_default: failed to unset current default' );
			}

			// The new default.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$set_ok = $this->wpdb->update(
				$this->table(),
				[ 'is_default' => 1 ],
				[ 'id' => $id ],
				[ '%d' ],
				[ '%d' ]
			);

			// False or 0 rows: the target went meanwhile (a concurrent delete), and committing would leave no default.
			if ( $set_ok === false || (int) $set_ok < 1 ) {
				throw new \RuntimeException( 'set_default: failed to set new default' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				// A failed COMMIT means the server rolled back: report failure.
				return false;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		$this->cache->flush_languages();

		// Flush the per-language caches so is_default is fresh.
		if ( $old_default_id > 0 ) {
			$this->cache->delete( "language_{$old_default_id}", 'perflocale_langs' );
		}

		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		$new_default = $this->find( $id );

		/** @hook perflocale/default_language/changed Fires when the default language changes. */
		do_action( 'perflocale/default_language/changed', $new_default, $old_default );

		return true;
	}

	/**
	 * Sanitize language data for insert and update; public so the data import stores an untrusted user's language row
	 * by these rules. Sanitizes only: the slug shape is checked by insert() and update().
	 *
	 * @internal
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed> Sanitized data.
	 */
	public static function sanitize_data( array $data ): array {
		$sanitized = [];

		$string_fields = [ 'slug', 'locale', 'name', 'native_name', 'flag', 'date_format', 'time_format', 'text_direction' ];
		$int_fields    = [ 'is_default', 'is_active', 'sort_order' ];

		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		foreach ( $int_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = absint( $data[ $field ] );
			}
		}

		// Lowercase, trimmed to the VARCHAR(10) column, so callers see the stored value, not a silent truncation.
		if ( isset( $sanitized['slug'] ) ) {
			$sanitized['slug'] = mb_substr( sanitize_key( $sanitized['slug'] ), 0, 10 );
		}

		if ( isset( $sanitized['locale'] ) ) {
			$sanitized['locale'] = mb_substr( $sanitized['locale'], 0, 20 );
		}

		if ( isset( $sanitized['flag'] ) ) {
			$sanitized['flag'] = mb_substr( $sanitized['flag'], 0, 10 );
		}

		// Text direction.
		if ( isset( $sanitized['text_direction'] ) && ! in_array( $sanitized['text_direction'], [ 'ltr', 'rtl' ], true ) ) {
			$sanitized['text_direction'] = 'ltr';
		}

		return $sanitized;
	}
}
