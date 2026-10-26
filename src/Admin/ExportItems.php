<?php
/**
 * Options and meta an export carries next to the tables.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports and imports the options and meta outside PerfLocale's tables, as row lists inside `data` (webhooks without secrets).
 *
 * Every imported row is checked (declared section, object type and fingerprint, stored shape); meta is never unserialized.
 * See docs-ai/11-code-rationale.md, "ExportItems: row lists and import rules".
 */
final class ExportItems {

	/**
	 * Regular expression per name placeholder.
	 */
	private const PLACEHOLDERS = [
		'{lang}' => '[a-z]{2,3}(?:-[a-z]{2,3})?',
		'{id}'   => '[0-9]+',
	];

	/**
	 * Meta rows read per query on export.
	 */
	private const EXPORT_BATCH = 1000;

	/**
	 * A machine translation's pending mark (TranslationService::PENDING_META_KEY): "<time>", or "<time>:c" while created.
	 */
	private const PENDING_KEY = '_perflocale_mt_pending';

	/**
	 * The shape of a pending mark.
	 */
	private const PENDING_SHAPE = '/^[1-9][0-9]{0,18}(?::c)?$/D';

	/**
	 * The rows of one row list, for the exporter.
	 *
	 * @param string                                                                                                $pseudo Row list (`options`, `post_meta`, `term_meta`, `order_meta`).
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}> $items  The items the exported sections carry in it (DataExporter::row_list_items()).
	 * @return \Generator<int, array<string, mixed>>
	 * @throws \RuntimeException When a read fails (a partial export must never be written).
	 */
	public static function export_rows( string $pseudo, array $items ): \Generator {
		if ( [] === $items ) {
			return;
		}

		if ( 'options' === $pseudo ) {
			yield from self::export_options( $items );

			return;
		}

		yield from self::export_meta( $pseudo, $items );
	}

	/**
	 * Option rows.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}> $items Option items.
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function export_options( array $items ): \Generator {
		$wpdb = Typed::wpdb();

		foreach ( $items as $item ) {
			$names = [ $item[1] ];

			if ( self::has_placeholder( $item[1] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export read of option names by prefix; one query per pattern.
				$found = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name ASC', $wpdb->options, $wpdb->esc_like( self::like_prefix( $item[1] ) ) . '%' ) );

				self::fail_on_db_error( 'options' );

				$names = array_values( array_filter( Typed::strs( $found ), static fn( string $n ): bool => 1 === preg_match( self::item_regex( $item ), $n ) ) );
			}

			foreach ( $names as $name ) {
				$missing = new \stdClass();
				$value   = get_option( $name, $missing );

				if ( $value === $missing ) {
					continue;
				}

				if ( 'perflocale_webhooks' === $name ) {
					// Only a user who may register webhooks exports them; an import without the row keeps the target's own.
					if ( ! self::may_carry_webhooks() ) {
						continue;
					}

					$value = self::without_webhook_secrets( $value );
				}

				yield [
					'name'  => $name,
					'value' => $value,
				];
			}
		}
	}

	/**
	 * Whether the current user may export or import the webhooks: one who may register them, or WP-CLI without a user.
	 *
	 * @return bool
	 */
	private static function may_carry_webhooks(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI && 0 === get_current_user_id() ) {
			return true;
		}

		return \PerfLocale\Api\WebhookController::user_can_register();
	}

	/**
	 * The webhook list as exported: every entry without its secret.
	 *
	 * @param mixed $value Stored webhooks.
	 * @return array<array-key, mixed>
	 */
	private static function without_webhook_secrets( $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $id => $hook ) {
			if ( is_array( $hook ) ) {
				unset( $hook['secret'] );
				$out[ $id ] = $hook;
			}
		}

		return $out;
	}

	/**
	 * Meta rows of one meta kind, read by meta id in batches.
	 *
	 * @param string                                                                                                $pseudo `post_meta`, `term_meta` or `order_meta`.
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}> $items  Items of that kind.
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function export_meta( string $pseudo, array $items ): \Generator {
		$wpdb = Typed::wpdb();

		[ $table, $id_col, $object_col, $join ] = self::meta_storage( $pseudo );

		foreach ( $items as $item ) {
			$placeholder = self::has_placeholder( $item[1] );
			$last        = 0;

			do {
				// LIKE on the literal prefix uses the meta_key index; the pattern below matches exactly.
				$key_arg = $placeholder ? $wpdb->esc_like( self::like_prefix( $item[1] ) ) . '%' : $item[1];
				$rows    = self::meta_page( $wpdb, $table, $id_col, $object_col, $join, $placeholder, $key_arg, $last );

				self::fail_on_db_error( $pseudo );

				$rows  = Typed::rows( $rows );
				$count = count( $rows );

				if ( 0 === $count ) {
					break;
				}

				$last = Typed::int( $rows[ $count - 1 ]['mid'] ?? 0 );
				$keep = [];

				foreach ( $rows as $row ) {
					$key = Typed::str( $row['meta_key'] ?? '' );

					if ( 1 === preg_match( self::item_regex( $item ), $key ) && ( self::PENDING_KEY !== $key || self::stopped_mark( Typed::str( $row['meta_value'] ?? '' ) ) ) ) {
						$keep[] = $row;
					}
				}

				$fps = ObjectFingerprint::for_ids( self::object_kind( $pseudo ), array_map( static fn( array $r ): int => Typed::int( $r['object_id'] ?? 0 ), $keep ) );

				foreach ( $keep as $row ) {
					$object_id = Typed::int( $row['object_id'] ?? 0 );
					$out       = [
						'object_id'  => $object_id,
						'meta_key'   => Typed::str( $row['meta_key'] ?? '' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key in an export row, not a query argument.
						'meta_value' => Typed::str( $row['meta_value'] ?? '' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Array key in an export row, not a query argument.
					];

					if ( isset( $fps[ $object_id ] ) ) {
						$out[ ObjectFingerprint::FIELD ] = $fps[ $object_id ];
					}

					yield $out;
				}
			} while ( self::EXPORT_BATCH === $count );
		}
	}

	/**
	 * Whether a pending mark is a stopped worker's: older than a lease (younger: a live call writes the translation).
	 *
	 * @param string $value Mark.
	 * @return bool
	 */
	private static function stopped_mark( string $value ): bool {
		return 1 === preg_match( self::PENDING_SHAPE, $value ) && time() - (int) $value >= \PerfLocale\Background\JobResume::LEASE;
	}

	/**
	 * One page of meta rows after a meta id, by exact key or LIKE prefix, joined to the order table for orders.
	 *
	 * Four literal statements, so every identifier is bound with %i.
	 *
	 * @param \wpdb                                       $wpdb       Database.
	 * @param string                                      $table      Meta table.
	 * @param string                                      $id_col     Meta row id column.
	 * @param string                                      $object_col Object id column.
	 * @param array{0: string, 1: string, 2: string}|null $join       Order table, its id and type columns (orders only).
	 * @param bool                                        $prefix     Match the key by LIKE prefix (true) or exactly.
	 * @param string                                      $key_arg    The LIKE pattern or the exact key.
	 * @param int                                         $last       Last meta id read.
	 * @return mixed What get_results() returned (ARRAY_A).
	 */
	private static function meta_page( \wpdb $wpdb, string $table, string $id_col, string $object_col, ?array $join, bool $prefix, string $key_arg, int $last ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export read in meta id batches.
		if ( null === $join ) {
			$rows = $prefix
				? $wpdb->get_results( $wpdb->prepare( 'SELECT m.%i AS mid, m.%i AS object_id, m.meta_key, m.meta_value FROM %i m WHERE m.meta_key LIKE %s AND m.%i > %d ORDER BY m.%i ASC LIMIT %d', $id_col, $object_col, $table, $key_arg, $id_col, $last, $id_col, self::EXPORT_BATCH ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT m.%i AS mid, m.%i AS object_id, m.meta_key, m.meta_value FROM %i m WHERE m.meta_key = %s AND m.%i > %d ORDER BY m.%i ASC LIMIT %d', $id_col, $object_col, $table, $key_arg, $id_col, $last, $id_col, self::EXPORT_BATCH ), ARRAY_A );
		} else {
			[ $orders, $order_id, $order_type ] = $join;

			$rows = $prefix
				? $wpdb->get_results( $wpdb->prepare( 'SELECT m.%i AS mid, m.%i AS object_id, m.meta_key, m.meta_value FROM %i m INNER JOIN %i o ON o.%i = m.%i AND o.%i = %s WHERE m.meta_key LIKE %s AND m.%i > %d ORDER BY m.%i ASC LIMIT %d', $id_col, $object_col, $table, $orders, $order_id, $object_col, $order_type, 'shop_order', $key_arg, $id_col, $last, $id_col, self::EXPORT_BATCH ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT m.%i AS mid, m.%i AS object_id, m.meta_key, m.meta_value FROM %i m INNER JOIN %i o ON o.%i = m.%i AND o.%i = %s WHERE m.meta_key = %s AND m.%i > %d ORDER BY m.%i ASC LIMIT %d', $id_col, $object_col, $table, $orders, $order_id, $object_col, $order_type, 'shop_order', $key_arg, $id_col, $last, $id_col, self::EXPORT_BATCH ), ARRAY_A );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows;
	}

	/**
	 * Where one meta kind is stored: [ meta table, meta id column, object id column, order table join or null ].
	 *
	 * @param string $pseudo `post_meta`, `term_meta` or `order_meta`.
	 * @return array{0: string, 1: string, 2: string, 3: array{0: string, 1: string, 2: string}|null}
	 */
	private static function meta_storage( string $pseudo ): array {
		$wpdb = Typed::wpdb();

		if ( 'term_meta' === $pseudo ) {
			return [ $wpdb->termmeta, 'meta_id', 'term_id', null ];
		}

		if ( 'order_meta' === $pseudo ) {
			if ( self::hpos() ) {
				return [ $wpdb->prefix . 'wc_orders_meta', 'id', 'order_id', [ $wpdb->prefix . 'wc_orders', 'id', 'type' ] ];
			}

			return [ $wpdb->postmeta, 'meta_id', 'post_id', [ $wpdb->posts, 'ID', 'post_type' ] ];
		}

		return [ $wpdb->postmeta, 'meta_id', 'post_id', null ];
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS).
	 *
	 * @return bool
	 */
	public static function hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The fingerprint kind of a meta kind's objects.
	 *
	 * @param string $pseudo `post_meta`, `term_meta` or `order_meta`.
	 * @return string
	 */
	private static function object_kind( string $pseudo ): string {
		return 'term_meta' === $pseudo ? 'term' : ( 'order_meta' === $pseudo ? 'order' : 'post' );
	}

	/**
	 * Throw when the last query failed.
	 *
	 * @param string $what What was being read.
	 * @return void
	 * @throws \RuntimeException When the last query failed.
	 */
	private static function fail_on_db_error( string $what ): void {
		$error = Typed::wpdb()->last_error;

		if ( '' !== $error ) {
			throw new \RuntimeException( esc_html( sprintf( 'PerfLocale export: reading %1$s failed (%2$s) - refusing to emit a partial export.', $what, $error ) ) );
		}
	}

	/* ------------------------------------------------------------------ import */

	/**
	 * Import the items of the declared sections from their row lists.
	 *
	 * Runs after the table import commits; each item is written on its own, so a failure here is safe to run again.
	 *
	 * @param ImportReader                                                                                                         $reader  Indexed reader.
	 * @param array<string, array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}>> $lists   `data` member => the items it may carry: a row list and the items of the declared sections (DataExporter::row_list_items()), or an add-on part's row list and that part's items (DataExporter::part_items()).
	 * @param bool                                                                                                                 $replace Replace mode.
	 * @param int                                                                                                                  $max_row Largest row read, in bytes.
	 * @param int                                                                                                                  $size    Rows per piece.
	 * @return array{imported: int, skipped: int, reasons: array<string, int>, notes: array<int, string>, matched: array<string, int>}
	 */
	public static function import( ImportReader $reader, array $lists, bool $replace, int $max_row, int $size ): array {
		$out = [
			'imported' => 0,
			'skipped'  => 0,
			'reasons'  => [],
			'notes'    => [],
			'matched'  => [],
		];

		foreach ( $lists as $member => $items ) {
			$member = (string) $member;

			if ( ! $reader->is_row_list( $member ) || 0 === $reader->count( $member ) ) {
				continue;
			}

			// An add-on part's member (`addon_data/<part>/<row list>`) holds rows of the list its name ends with.
			$slash  = strrpos( $member, '/' );
			$pseudo = false === $slash ? $member : substr( $member, $slash + 1 );
			$piece  = [];

			foreach ( $reader->rows( $member, $max_row ) as $row ) {
				$piece[] = $row;

				if ( count( $piece ) >= $size ) {
					ImportLock::heartbeat();
					self::import_piece( $pseudo, $items, $piece, $replace, $out );
					$piece = [];
				}
			}

			if ( [] !== $piece ) {
				self::import_piece( $pseudo, $items, $piece, $replace, $out );
			}
		}

		if ( ( $out['reasons']['webhook_secret_new'] ?? 0 ) > 0 ) {
			$count          = $out['reasons']['webhook_secret_new'];
			$out['notes'][] = sprintf(
				/* translators: %d: number of webhooks */
				_n(
					'%d webhook was imported with a new secret: an export never carries webhook secrets. Register it again with the secret its receiver expects.',
					'%d webhooks were imported with a new secret: an export never carries webhook secrets. Register them again with the secret their receivers expect.',
					$count,
					'perflocale'
				),
				$count
			);
			unset( $out['reasons']['webhook_secret_new'] );
		}

		return $out;
	}

	/**
	 * Import one piece of a row list.
	 *
	 * @param string                                                                                                                  $pseudo Row list.
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}>                   $items  Items the declared sections carry in it.
	 * @param array<int, mixed>                                                                                                       $piece  Decoded rows.
	 * @param bool                                                                                                                    $replace Replace mode.
	 * @param array{imported: int, skipped: int, reasons: array<string, int>, notes: array<int, string>, matched: array<string, int>} $out Result (updated).
	 * @return void
	 */
	private static function import_piece( string $pseudo, array $items, array $piece, bool $replace, array &$out ): void {
		if ( 'options' === $pseudo ) {
			foreach ( $piece as $row ) {
				self::import_option( $row, $items, $replace, $out );
			}

			return;
		}

		// Rows whose key belongs to a declared section: [ object id, meta key, row, item ].
		$rows = [];

		foreach ( $piece as $row ) {
			$row  = Typed::map( $row );
			$key  = $row['meta_key'] ?? null;
			$item = is_string( $key ) ? self::item_for( $items, $key ) : null;
			$id   = Typed::int( $row['object_id'] ?? null );

			if ( null === $item || $id <= 0 ) {
				self::skip( $out, 'not_restorable' );
				continue;
			}

			$rows[] = [ $id, $key, $row, $item ];
		}

		if ( [] === $rows ) {
			return;
		}

		$kind    = self::object_kind( $pseudo );
		$ids     = array_column( $rows, 0 );
		$objects = self::object_types( $kind, $ids );
		$fps     = ObjectFingerprint::for_ids( $kind, $ids );

		foreach ( $rows as [ $id, $key, $row, $item ] ) {

			if ( ! self::object_fits( $item[2], $objects[ $id ] ?? null ) ) {
				self::skip( $out, 'object_changed' );
				continue;
			}

			$cmp = isset( $row[ ObjectFingerprint::FIELD ] ) ? ObjectFingerprint::compare( $row[ ObjectFingerprint::FIELD ], $fps[ $id ] ?? null ) : null;

			if ( null !== $cmp && ! $cmp['match'] ) {
				self::skip( $out, 'object_changed' );
				continue;
			}

			if ( null !== $cmp && $cmp['changed'] ) {
				foreach ( $cmp['parts'] as $part ) {
					$out['matched'][ $part ] = ( $out['matched'][ $part ] ?? 0 ) + 1;
				}

				$out['matched']['_rows'] = ( $out['matched']['_rows'] ?? 0 ) + 1;
			}

			$value = self::meta_value( $key, $row['meta_value'] ?? null, $item[4] ?? null );

			if ( null === $value ) {
				self::skip( $out, 'bad_value' );
				continue;
			}

			if ( self::write_meta( $pseudo, $id, $key, $value, $replace ) ) {
				++$out['imported'];
			} else {
				++$out['skipped'];
			}
		}

		\PerfLocale\Cache\CacheManager::flush_runtime();
	}

	/**
	 * Import one option row.
	 *
	 * @param mixed                                                                                                                   $row     Decoded row.
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}>                   $items   Option items of the declared sections.
	 * @param bool                                                                                                                    $replace Replace mode.
	 * @param array{imported: int, skipped: int, reasons: array<string, int>, notes: array<int, string>, matched: array<string, int>} $out Result (updated).
	 * @return void
	 */
	private static function import_option( $row, array $items, bool $replace, array &$out ): void {
		$row  = Typed::map( $row );
		$name = Typed::str( $row['name'] ?? null );
		$item = '' !== $name ? self::item_for( $items, $name ) : null;

		if ( null === $item || ! array_key_exists( 'value', $row ) ) {
			self::skip( $out, 'not_restorable' );
			return;
		}

		// Merge rule `never` (configuration): a merge never changes it; the merge notice covers its sections.
		if ( ! $replace && 'never' === ( $item[3] ?? null ) ) {
			++$out['skipped'];
			return;
		}

		$value = $row['value'];

		switch ( true ) {
			case 'perflocale_slug_redirects' === $name:
				$written = self::import_slug_redirects( $value, $replace );
				break;
			case 'perflocale_webhooks' === $name:
				$written = self::import_webhooks( $value, $out );
				break;
			case 'perflocale_translator_caps' === $name:
				$written = self::import_translator_ledger( $value );
				break;
			case 'perflocale_shop_manager_caps' === $name:
				$written = self::import_shop_manager_record( $value );
				break;
			case 'gf_form' === $item[2]:
				$written = self::import_gf_translations( (int) substr( $name, strlen( self::like_prefix( $item[1] ) ) ), $value, $replace, $out );
				break;
			case is_callable( $item[4] ?? null ):
				$written = self::import_extension_option( $name, $value, $item[4], $item, $replace );
				break;
			default:
				$written = null;
		}

		if ( true === $written ) {
			++$out['imported'];
		} elseif ( false === $written ) {
			++$out['skipped'];
		} else {
			self::skip( $out, 'bad_value' );
		}
	}

	/**
	 * An add-on's registry option, stored only after the entry's own import sanitizer passed the value.
	 *
	 * Merge: insert_if_absent writes only when this site has none, union_keys adds the keys it lacks; replace writes.
	 *
	 * @param string                                                                                    $name      Option name.
	 * @param mixed                                                                                     $value     File value (decoded JSON: arrays and scalars only).
	 * @param callable                                                                                  $sanitizer The entry's import sanitizer (the item's fifth member).
	 * @param array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string} $item      Registry item.
	 * @param bool                                                                                      $replace   Replace mode.
	 * @return bool|null True when written, false when nothing changed, null when the sanitizer refused the value.
	 */
	private static function import_extension_option( string $name, $value, callable $sanitizer, array $item, bool $replace ): ?bool {
		$clean = call_user_func( $sanitizer, $value, $name );

		if ( null === $clean || is_object( $clean ) ) {
			return null;
		}

		$missing = new \stdClass();
		$current = get_option( $name, $missing );

		if ( ! $replace ) {
			if ( 'union_keys' === $item[3] && is_array( $clean ) ) {
				$clean = ( is_array( $current ) ? $current : [] ) + $clean;
			} elseif ( $current !== $missing ) {
				return false;
			}
		}

		if ( $current === $clean ) {
			return false;
		}

		return update_option( $name, $clean, false );
	}

	/**
	 * The language-code redirect map (old code => new code): a merge adds the codes this site lacks, a replace writes the file's.
	 *
	 * @param mixed $value   File value.
	 * @param bool  $replace Replace mode.
	 * @return bool|null True when written, false when nothing changed, null when the value is not a map.
	 */
	private static function import_slug_redirects( $value, bool $replace ): ?bool {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$pattern = \PerfLocale\Database\Repository\LanguageRepository::SLUG_PATTERN;
		$clean   = [];

		foreach ( $value as $old => $new ) {
			$old = sanitize_key( (string) $old );
			$new = is_string( $new ) ? sanitize_key( $new ) : '';

			if ( 1 === preg_match( $pattern, $old ) && 1 === preg_match( $pattern, $new ) && $old !== $new ) {
				$clean[ $old ] = $new;
			}
		}

		$current = (array) get_option( \PerfLocale\Database\Repository\LanguageRepository::REDIRECTS_OPTION, [] );
		$next    = $replace ? $clean : $current + $clean;

		// Read on every front-end request: at most 50 codes, newest last, as LanguageRepository keeps it.
		$next = array_slice( $next, -50, null, true );

		if ( $next === $current ) {
			return false;
		}

		return update_option( \PerfLocale\Database\Repository\LanguageRepository::REDIRECTS_OPTION, $next );
	}

	/**
	 * The webhooks (replace only), for a user who may register them.
	 *
	 * The file carries no secrets: a known address keeps this site's secret, any other gets a new random one.
	 *
	 * @param mixed                                                                                                                   $value File value.
	 * @param array{imported: int, skipped: int, reasons: array<string, int>, notes: array<int, string>, matched: array<string, int>} $out   Result (updated).
	 * @return bool|null
	 */
	private static function import_webhooks( $value, array &$out ): ?bool {
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( ! self::may_carry_webhooks() ) {
			$out['notes'][] = \PerfLocale\Api\WebhookController::register_denied_message();

			return false;
		}

		$current = get_option( 'perflocale_webhooks', [] );
		$secrets = [];

		foreach ( is_array( $current ) ? $current : [] as $hook ) {
			if ( is_array( $hook ) && is_string( $hook['url'] ?? null ) && is_string( $hook['secret'] ?? null ) && '' !== $hook['secret'] ) {
				$secrets[ $hook['url'] ] = $hook['secret'];
			}
		}

		$events = \PerfLocale\Api\WebhookController::valid_events();
		$next   = [];

		foreach ( $value as $hook ) {
			if ( ! is_array( $hook ) || ! is_string( $hook['url'] ?? null ) ) {
				continue;
			}

			$url   = esc_url_raw( $hook['url'] );
			$parts = wp_parse_url( $url );

			if ( '' === $url || ! \PerfLocale\Helper::is_valid_url( $url ) || ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				continue;
			}

			$hook_events = array_values( array_intersect( Typed::strs( $hook['events'] ?? [] ), $events ) );

			if ( [] === $hook_events ) {
				continue;
			}

			$id = is_string( $hook['id'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9-]{1,64}$/', $hook['id'] ) ? $hook['id'] : wp_generate_uuid4();

			if ( isset( $secrets[ $url ] ) ) {
				$secret = $secrets[ $url ];
			} else {
				$secret = wp_generate_password( 48, true, true );
				self::skip( $out, 'webhook_secret_new', false );
			}

			$next[ $id ] = [
				'id'         => $id,
				'url'        => $url,
				'events'     => $hook_events,
				'secret'     => $secret,
				'created_at' => is_string( $hook['created_at'] ?? null ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $hook['created_at'] ) ? $hook['created_at'] : gmdate( 'Y-m-d H:i:s' ),
			];
		}

		if ( $next === $current ) {
			return false;
		}

		// Not autoloaded, as WebhookController stores it: the secrets stay out of alloptions.
		return update_option( 'perflocale_webhooks', $next, false );
	}

	/**
	 * The Translator capability ledger (replace only), limited to the capabilities a roles restore may grant.
	 *
	 * @param mixed $value File value.
	 * @return bool|null
	 */
	private static function import_translator_ledger( $value ): ?bool {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$next = [
			'granted' => self::cap_map( $value['granted'] ?? [] ),
			'removed' => self::cap_list( $value['removed'] ?? [] ),
			'denied'  => self::cap_list( $value['denied'] ?? [] ),
		];

		if ( get_option( 'perflocale_translator_caps' ) === $next ) {
			return false;
		}

		return update_option( 'perflocale_translator_caps', $next, false );
	}

	/**
	 * The Shop Manager grant record (replace only).
	 *
	 * @param mixed $value File value.
	 * @return bool|null
	 */
	private static function import_shop_manager_record( $value ): ?bool {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$next = [ 'granted' => self::cap_map( $value['granted'] ?? [] ) ];

		if ( isset( $value['removed'] ) ) {
			$next['removed'] = self::cap_list( $value['removed'] );
		}

		if ( get_option( 'perflocale_shop_manager_caps' ) === $next ) {
			return false;
		}

		// Autoloaded, as TranslatorRole stores it: read on every admin_init of a WooCommerce site.
		return update_option( 'perflocale_shop_manager_caps', $next, true );
	}

	/**
	 * Capability => grant, only PerfLocale capabilities and the editing capabilities the Translator role needs.
	 *
	 * @param mixed $map Raw map.
	 * @return array<string, bool>
	 */
	private static function cap_map( $map ): array {
		$out = [];

		foreach ( is_array( $map ) ? $map : [] as $cap => $grant ) {
			$cap = sanitize_key( (string) $cap );

			if ( self::restorable_cap( $cap ) ) {
				$out[ $cap ] = (bool) $grant;
			}
		}

		return $out;
	}

	/**
	 * A list of capability names, restricted as cap_map().
	 *
	 * @param mixed $caps Raw list.
	 * @return array<int, string>
	 */
	private static function cap_list( $caps ): array {
		$out = [];

		foreach ( is_array( $caps ) ? $caps : [] as $cap ) {
			$cap = is_string( $cap ) ? sanitize_key( $cap ) : '';

			if ( self::restorable_cap( $cap ) ) {
				$out[] = $cap;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether an import may record this capability.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	private static function restorable_cap( string $cap ): bool {
		return str_starts_with( $cap, 'perflocale_' )
			|| in_array( $cap, [ 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'upload_files' ], true );
	}

	/**
	 * One form's Gravity Forms translations, through the add-on's own save, which sanitizes every value.
	 *
	 * @param int                                                                                                                     $form_id Form ID.
	 * @param mixed                                                                                                                   $value   Language code => translations.
	 * @param bool                                                                                                                    $replace Replace mode.
	 * @param array{imported: int, skipped: int, reasons: array<string, int>, notes: array<int, string>, matched: array<string, int>} $out     Result (updated).
	 * @return bool|null
	 */
	private static function import_gf_translations( int $form_id, $value, bool $replace, array &$out ): ?bool {
		if ( ! is_array( $value ) || $form_id <= 0 ) {
			return null;
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$registry = $plugin->has( 'addon_registry' ) ? $plugin->get( 'addon_registry' ) : null;
		$addon    = $registry instanceof \PerfLocale\Addon\AddonRegistry ? ( $registry->get_addons()['gravity-forms'] ?? null ) : null;

		if ( ! is_object( $addon ) || ! method_exists( $addon, 'save_translations' ) || ! method_exists( $addon, 'get_translations' ) || ! method_exists( $addon, 'delete_translations' ) || ! class_exists( 'GFAPI' ) ) {
			self::skip( $out, 'addon_inactive', false );
			return false;
		}

		if ( ! \GFAPI::form_id_exists( $form_id ) ) {
			self::skip( $out, 'object_changed', false );
			return false;
		}

		if ( $replace ) {
			$addon->delete_translations( $form_id );
		}

		$written = false;

		foreach ( $value as $slug => $translations ) {
			if ( ! is_array( $translations ) || ! is_string( $slug ) ) {
				continue;
			}

			if ( ! $replace && [] !== $addon->get_translations( $form_id, $slug ) ) {
				continue;
			}

			$written = (bool) $addon->save_translations( $form_id, $slug, $translations ) || $written;
		}

		return $written;
	}

	/**
	 * The value to store for a meta row, or null when the plugin stores no such value under that key.
	 *
	 * Never unserialized: a serialized value is refused.
	 *
	 * @param string        $key       Meta key.
	 * @param mixed         $value     File value.
	 * @param callable|null $sanitizer The registry entry's import sanitizer (add-on entries only).
	 * @return string|null
	 */
	private static function meta_value( string $key, $value, ?callable $sanitizer = null ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;

		if ( is_serialized( $value ) ) {
			return null;
		}

		// An add-on's registry meta: its import sanitizer decides, and only a scalar result is stored.
		if ( null !== $sanitizer ) {
			$clean = call_user_func( $sanitizer, $value, $key );

			return is_scalar( $clean ) && ! is_serialized( (string) $clean ) ? (string) $clean : null;
		}

		if ( str_starts_with( $key, '_perflocale_alt_' ) || str_starts_with( $key, '_perflocale_caption_' ) ) {
			return sanitize_text_field( $value );
		}

		if ( str_starts_with( $key, '_perflocale_description_' ) ) {
			return sanitize_textarea_field( $value );
		}

		if ( str_starts_with( $key, '_perflocale_thumbnail_' ) ) {
			$id = absint( $value );

			return $id > 0 && 'attachment' === get_post_type( $id ) ? (string) $id : null;
		}

		if ( '_perflocale_sync_optout' === $key || '_perflocale_seo_exclude' === $key ) {
			return 'yes' === $value ? 'yes' : null;
		}

		if ( self::PENDING_KEY === $key ) {
			return 1 === preg_match( self::PENDING_SHAPE, $value ) ? $value : null;
		}

		if ( str_starts_with( $key, '_perflocale_menu_' ) ) {
			$id   = absint( $value );
			$term = $id > 0 ? get_term( $id, 'nav_menu' ) : null;

			return $term instanceof \WP_Term ? (string) $id : null;
		}

		if ( '_perflocale_language' === $key ) {
			$slug = sanitize_key( $value );

			return 1 === preg_match( \PerfLocale\Database\Repository\LanguageRepository::SLUG_PATTERN, $slug ) ? $slug : null;
		}

		return null;
	}

	/**
	 * Write one meta value: a merge adds it when the object has none; a replace writes the file's value over this site's.
	 *
	 * @param string $pseudo  Meta kind.
	 * @param int    $id      Object ID.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 * @param bool   $replace Replace mode.
	 * @return bool True when written.
	 */
	private static function write_meta( string $pseudo, int $id, string $key, string $value, bool $replace ): bool {
		if ( 'order_meta' === $pseudo ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;

			// WC_Order's meta API, checked by method, so the check needs no WooCommerce class here.
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save_meta_data' ) ) {
				return false;
			}

			$current = Typed::str( $order->get_meta( $key, true ) );

			if ( $current === $value || ( ! $replace && '' !== $current ) ) {
				return false;
			}

			// save_meta_data(), not save(): one meta row, no order resave (as EmailTranslation does).
			$order->update_meta_data( $key, $value );
			$order->save_meta_data();

			return true;
		}

		$type = 'term_meta' === $pseudo ? 'term' : 'post';

		if ( metadata_exists( $type, $id, $key ) ) {
			if ( ! $replace || Typed::str( get_metadata( $type, $id, $key, true ) ) === $value ) {
				return false;
			}

			return false !== update_metadata( $type, $id, $key, wp_slash( $value ) );
		}

		return false !== add_metadata( $type, $id, $key, wp_slash( $value ), true );
	}

	/**
	 * The type of each object that exists: post type, taxonomy, or order type.
	 *
	 * @param string          $kind `post`, `term` or `order`.
	 * @param array<int, int> $ids  Object IDs.
	 * @return array<int, string>
	 */
	private static function object_types( string $kind, array $ids ): array {
		$wpdb = Typed::wpdb();

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = [];

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			if ( 'term' === $kind ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Core table bound with %i; ids bound one %d each.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS id, taxonomy AS type FROM %i WHERE term_id IN ({$placeholders})", $wpdb->term_taxonomy, ...$chunk ), ARRAY_A );
			} elseif ( 'order' === $kind && self::hpos() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WooCommerce table bound with %i; ids bound one %d each.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type FROM %i WHERE id IN ({$placeholders})", $wpdb->prefix . 'wc_orders', ...$chunk ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Core table bound with %i; ids bound one %d each.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID AS id, post_type AS type FROM %i WHERE ID IN ({$placeholders})", $wpdb->posts, ...$chunk ), ARRAY_A );
			}

			foreach ( Typed::rows( $rows ) as $row ) {
				$out[ Typed::int( $row['id'] ?? 0 ) ] ??= Typed::str( $row['type'] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * Whether an object of this type may carry an item for that object kind; no kind (add-on meta) fits any existing object.
	 *
	 * @param string|null $kind Item object kind (attachment, nav_menu, ...), or null.
	 * @param string|null $type The object's type here, null when it does not exist.
	 * @return bool
	 */
	private static function object_fits( ?string $kind, ?string $type ): bool {
		if ( null === $type ) {
			return false;
		}

		if ( null === $kind ) {
			return true;
		}

		switch ( $kind ) {
			case 'attachment':
				return 'attachment' === $type;
			case 'nav_menu':
				return 'nav_menu' === $type;
			case 'shop_order':
				return 'shop_order' === $type;
			case 'translatable_post':
				return ! in_array( $type, [ 'attachment', 'revision', 'nav_menu_item', 'shop_order' ], true );
			case 'product':
				return 'product' === $type;
			case 'gf_form':
				return true;
			default:
				return false;
		}
	}

	/**
	 * The item a stored name belongs to, among the given items.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}> $items Items.
	 * @param string                                                                                                $name  Option name or meta key.
	 * @return array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string}|null
	 */
	private static function item_for( array $items, string $name ): ?array {
		foreach ( $items as $item ) {
			if ( 1 === preg_match( self::item_regex( $item ), $name ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Count a row left out, by reason.
	 *
	 * @param array{imported: int, skipped: int, reasons: array<string, int>, notes: array<int, string>, matched: array<string, int>} $out     Result (updated).
	 * @param string                                                                                                                  $reason  Reason key.
	 * @param bool                                                                                                                    $skipped Also count it as skipped.
	 * @return void
	 */
	private static function skip( array &$out, string $reason, bool $skipped = true ): void {
		if ( $skipped ) {
			++$out['skipped'];
		}

		$out['reasons'][ $reason ] = ( $out['reasons'][ $reason ] ?? 0 ) + 1;
	}

	/**
	 * Whether a name has a placeholder.
	 *
	 * @param string $pattern Name.
	 * @return bool
	 */
	private static function has_placeholder( string $pattern ): bool {
		return str_contains( $pattern, '{' );
	}

	/**
	 * The literal part of a name before its first placeholder.
	 *
	 * @param string $pattern Name.
	 * @return string
	 */
	private static function like_prefix( string $pattern ): string {
		$at = strpos( $pattern, '{' );

		return false === $at ? $pattern : substr( $pattern, 0, $at );
	}

	/**
	 * The regular expression an item's name stands for: the registry's own, or this class's for {lang} and {id}.
	 *
	 * @param array{0: string, 1: string, 2: string|null, 3: string|null, 4: callable|null, 5?: string} $item Item.
	 * @return string
	 */
	private static function item_regex( array $item ): string {
		return isset( $item[5] ) && '' !== $item[5] ? $item[5] : self::to_regex( $item[1] );
	}

	/**
	 * Anchored regular expression for a name.
	 *
	 * @param string $pattern Name.
	 * @return string
	 */
	private static function to_regex( string $pattern ): string {
		$parts = preg_split( '/(\{[a-z0-9]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE );
		$re    = '';

		foreach ( (array) $parts as $part ) {
			$re .= self::PLACEHOLDERS[ $part ] ?? preg_quote( (string) $part, '/' );
		}

		return '/^' . $re . '$/';
	}
}
