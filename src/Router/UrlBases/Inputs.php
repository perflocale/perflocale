<?php
/**
 * URL bases compile inputs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Admin\Typed;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every input of one compile, read uncached (options and the stored `rewrite_rules` by `$wpdb`), and its fingerprint.
 *
 * @phpstan-import-type ValueSet from Values
 * @phpstan-type Lang array{id: int, slug: string, locale: string, is_default: bool, is_active: bool}
 *
 * @internal
 */
final class Inputs {

	/**
	 * Options whose stored value is a compile input.
	 */
	public const OPTIONS = [ 'rewrite_rules', 'permalink_structure', 'category_base', 'tag_base', 'woocommerce_permalinks', 'woocommerce_shop_page_id', 'wp_attachment_pages_enabled' ];

	/**
	 * Inputs.
	 *
	 * @param ValueSet                  $values       Values.
	 * @param string|null               $values_raw   Stored values bytes.
	 * @param array<string, string>     $options      Option name => stored bytes (missing options absent).
	 * @param list<Lang>                $languages    Languages.
	 * @param array<string, string>     $prefixes     Language slug => URL prefix.
	 * @param list<string>              $old_codes    Old language codes.
	 * @param array<string, mixed>|null $prev_map     The stored map (format v1), or null.
	 * @param Registry                  $registry     Keys.
	 * @param bool                      $hide_default Hidden default prefix.
	 * @param bool                      $map_unread   A map row is stored but is not a map v1 (unsure what was live).
	 * @param bool                      $read_failed  The options or languages read failed (or no languages table): nothing read proves absence.
	 * @param string|null               $map_raw      The stored map's bytes as read, null without a row.
	 */
	public function __construct(
		public readonly array $values,
		public readonly ?string $values_raw,
		public readonly array $options,
		public readonly array $languages,
		public readonly array $prefixes,
		public readonly array $old_codes,
		public readonly ?array $prev_map,
		public readonly Registry $registry,
		public readonly bool $hide_default,
		public readonly bool $map_unread = false,
		public readonly bool $read_failed = false,
		public readonly ?string $map_raw = null
	) {
	}

	/**
	 * Read the inputs of the current blog.
	 *
	 * @param Registry|null $registry Keys, or null to build them.
	 * @return self
	 */
	public static function read( ?Registry $registry = null ): self {
		$registry ??= Registry::build();
		$names      = array_values( array_unique( array_merge( self::OPTIONS, $registry->inputs(), [ Values::OPTION, Publication::MAP, 'perflocale_slug_redirects' ] ) ) );
		$failed     = false;
		$raw        = self::raw_options( $names, $failed );
		$languages  = self::languages( $failed );
		/** @var \PerfLocale\Settings $settings */
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$prefixes = [];

		foreach ( $languages as $lang ) {
			$prefixes[ $lang['slug'] ] = $settings->get_url_prefix( (object) $lang );
		}

		$values_raw = $raw[ Values::OPTION ] ?? null;
		$map_raw    = $raw[ Publication::MAP ] ?? null;
		$stored     = isset( $raw[ Publication::MAP ] );
		$prev       = $stored ? maybe_unserialize( $raw[ Publication::MAP ] ) : null;
		$old        = isset( $raw['perflocale_slug_redirects'] ) ? maybe_unserialize( $raw['perflocale_slug_redirects'] ) : [];
		$prev       = is_array( $prev ) && 1 === ( $prev['v'] ?? null ) ? Typed::map( $prev ) : null;

		unset( $raw[ Values::OPTION ], $raw[ Publication::MAP ], $raw['perflocale_slug_redirects'] );

		return new self(
			Values::shape( null !== $values_raw ? maybe_unserialize( $values_raw ) : null ),
			$values_raw,
			$raw,
			$languages,
			$prefixes,
			array_map( 'strval', array_keys( Typed::arr( $old ) ) ),
			$prev,
			$registry,
			(bool) $settings->get( 'hide_default_prefix' ),
			$stored && null === $prev,
			$failed,
			$map_raw
		);
	}

	/**
	 * Stored option bytes by name, in one query; a missing option is absent.
	 *
	 * @param list<string> $names  Option names.
	 * @param bool         $failed Set when the query failed.
	 * @return array<string, string>
	 */
	private static function raw_options( array $names, bool &$failed ): array {
		$wpdb = Typed::wpdb();

		$in = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Compile inputs past every cache; $in holds placeholders only.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds one %s per name in ...$names.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM %i WHERE option_name IN ({$in})", $wpdb->options, ...$names ), ARRAY_N );
		// phpcs:enable

		$failed = $failed || '' !== $wpdb->last_error;
		$out    = [];

		foreach ( Typed::arr( $rows ) as $row ) {
			$row                                  = Typed::arr( $row );
			$out[ Typed::str( $row[0] ?? null ) ] = Typed::str( $row[1] ?? null );
		}

		return $out;
	}

	/**
	 * Every language slug of this blog, read directly; null when the read failed.
	 *
	 * @param bool $locked A locking read (MySQL/MariaDB), held to COMMIT: only inside a transaction (Values::write_in_transaction()).
	 * @return list<string>|null
	 */
	public static function slugs( bool $locked = false ): ?array {
		$rows = self::rows( $locked );

		return null === $rows ? null : array_column( $rows, 'slug' );
	}

	/**
	 * Every language row of this blog (id, slug, locale, flags), read directly; null when the read failed.
	 *
	 * @param bool $locked As slugs().
	 * @return list<Lang>|null
	 */
	public static function rows( bool $locked = false ): ?array {
		$failed = false;
		$rows   = self::languages( $failed, $locked );

		return $failed ? null : $rows;
	}

	/**
	 * Lock every language row in id order, first in each transaction that binds slug-keyed data or writes language rows: one
	 * global lock order (language rows by id, values row, map row, the delete's tables, other options) leaves no cycle.
	 *
	 * @return bool False when the read failed; true without a query on SQLite (one writer per database).
	 */
	public static function lock_languages(): bool {
		if ( '' === \PerfLocale\Database\ConnectionGuard::lock_suffix() ) {
			return true;
		}

		$wpdb = Typed::wpdb();
		$sql  = $wpdb->prepare( 'SELECT id FROM %i ORDER BY id ASC FOR UPDATE', Schema::table( 'languages' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A row lock, past any cache; prepared above.
		$rows = is_string( $sql ) ? $wpdb->query( $sql ) : false;

		return false !== $rows && '' === $wpdb->last_error;
	}

	/**
	 * Every language row of this blog, read directly.
	 *
	 * @param bool $failed Set when the query failed.
	 * @param bool $locked A locking read (ConnectionGuard::lock_suffix()).
	 * @return list<Lang>
	 */
	private static function languages( bool &$failed, bool $locked = false ): array {
		$wpdb = Typed::wpdb();

		// A failed probe, or no table (a site move before db-migrate), proves no language absent.
		if ( ! Schema::tables_exist() ) {
			$failed = true;

			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compile input, past any cache; the lock suffix is a literal.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, slug, locale, is_default, is_active FROM %i ORDER BY sort_order ASC, id ASC' . ( $locked ? \PerfLocale\Database\ConnectionGuard::lock_suffix() : '' ), Schema::table( 'languages' ) ), ARRAY_A );
		$failed = $failed || '' !== $wpdb->last_error;
		$out    = [];

		foreach ( Typed::rows( $rows ) as $row ) {
			$out[] = [
				'id'         => Typed::int( $row['id'] ?? null ),
				'slug'       => Typed::str( $row['slug'] ?? null ),
				'locale'     => Typed::str( $row['locale'] ?? null ),
				'is_default' => 1 === Typed::int( $row['is_default'] ?? null ),
				'is_active'  => 1 === Typed::int( $row['is_active'] ?? null ),
			];
		}

		return $out;
	}

	/**
	 * An option's stored value, unserialized; null when absent.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function option( string $name ): mixed {
		return isset( $this->options[ $name ] ) ? maybe_unserialize( $this->options[ $name ] ) : null;
	}

	/**
	 * The default language row, or null.
	 *
	 * @return Lang|null
	 */
	public function default_language(): ?array {
		foreach ( $this->languages as $lang ) {
			if ( $lang['is_default'] ) {
				return $lang;
			}
		}

		return null;
	}

	/**
	 * This site's path on a multisite network, '' elsewhere.
	 *
	 * @return string
	 */
	public static function site_path(): string {
		$site = is_multisite() ? get_site() : null;

		return $site instanceof \WP_Site ? (string) $site->path : '';
	}

	/**
	 * Fingerprint of every input (map `fp`).
	 *
	 * @param array<string, mixed> $extra Further inputs the compile read (shop data).
	 * @return string 40 hex.
	 */
	public function fingerprint( array $extra = [] ): string {
		$keys = [];

		foreach ( $this->registry->keys() as $key => $desc ) {
			$keys[ $key ] = [ $desc['grp'], $desc['slug'], $desc['with_front'], $desc['qv'] ];
		}

		$options = $this->options;
		ksort( $options );

		// A pending forget and the published record are no map input: writing them must not make the map look stale.
		$values = null !== $this->values_raw ? serialize( array_diff_key( $this->values, [ 'forget' => 0, 'pub' => 0 ] ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A stable form of the inputs.

		if ( isset( $options['rewrite_rules'] ) ) {
			$options['rewrite_rules'] = md5( (string) $options['rewrite_rules'] );
		}

		return sha1(
			(string) wp_json_encode(
				[
					$values,
					$options,
					$this->languages,
					$this->prefixes,
					$this->old_codes,
					$this->hide_default,
					$keys,
					$this->registry->shared(),
					self::site_path(),
					$extra,
				]
			)
		);
	}
}
