<?php
/**
 * URL bases map publication.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\OptionRead;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles and publishes `perflocale_url_bases_map` under a lease, then retires caches and acknowledges the dirty
 * marker only for the snapshot it compiled (DESIGN-step1 3.10, publication protocol).
 *
 * @phpstan-import-type Report from Compiler
 * @phpstan-import-type Build from Compiler
 * @phpstan-type Result array{status: string, gen: int, report: Report|array{}}
 * @phpstan-type Dirty array{seq: int, reasons: list<string>, retire: array<string, int>}
 *
 * @internal
 */
final class Publication {

	public const MAP = 'perflocale_url_bases_map';

	public const DIRTY = Lifecycle::DIRTY;

	private const LOCK = 'url_bases_compile';

	private const LEASE = 30;

	/**
	 * Retirement attempts per adapter before Site Health warns and retries stop.
	 */
	public const RETIRE_ATTEMPTS = 3;

	/**
	 * Dry-run builds of this request by blog id; dropped by every publish and value write.
	 *
	 * @var array<int, Build>
	 */
	private static array $dry = [];

	/**
	 * This request holds the compile lock (publish_locked() runs).
	 *
	 * @var bool
	 */
	private static bool $locked = false;

	/**
	 * Compile and publish.
	 *
	 * @param string       $reason Why (logged in the dirty marker when the lock is busy).
	 * @param list<string> $forget Keys whose old bases are dropped; `*` for all.
	 * @return Result
	 */
	public static function publish( string $reason, array $forget = [] ): array {
		self::$dry = [];

		// Inside a unit (a hook during a save): a compile would nest and commit it; the marker leaves it to the next one.
		if ( class_exists( \PerfLocale\Database\Tx::class, false ) && \PerfLocale\Database\Tx::open() ) {
			self::mark_dirty( $reason );

			return [
				'status' => 'busy',
				'gen'    => 0,
				'report' => [],
			];
		}

		// Not in use and no cache retirement owed: nothing is compiled or written, and rows a compile left behind go.
		$marker = OptionRead::of( self::DIRTY );

		if ( ! Lifecycle::configured() && ! $marker->failed && [] === self::retire_state( $marker->bytes ?? '' ) ) {
			self::retire_unused();

			return [
				'status' => 'unchanged',
				'gen'    => 0,
				'report' => [],
			];
		}

		// In use from here on: a request that began without URL bases (the first save) hooks the triggers now.
		LifecycleHooks::register();

		$registry = Registry::build();
		$out      = \PerfLocale\Database\Tx::leased(
			self::LOCK,
			self::LEASE,
			static function () use ( $registry, $forget ): array {
				self::$locked = true;

				try {
					return self::publish_locked( $registry, $forget );
				} finally {
					self::$locked = false;
				}
			}
		);

		if ( ! is_array( $out ) ) {
			self::mark_dirty( $reason );

			return [
				'status' => 'busy',
				'gen'    => 0,
				'report' => [],
			];
		}

		self::$dry = [];

		/** @var Result $out */
		return $out;
	}

	/**
	 * Steps 2-6 of the protocol, under the lock.
	 *
	 * @param Registry     $registry Keys.
	 * @param list<string> $forget   Keys whose old bases are dropped; `*` for all.
	 * @return Result
	 */
	private static function publish_locked( Registry $registry, array $forget ): array {
		$durable  = false;
		$caught   = false;
		$restarts = 0;

		while ( true ) {
			$marker   = OptionRead::of( self::DIRTY );
			$snapshot = $marker->bytes;
			$in       = Inputs::read( $registry );

			// A failed read proves nothing absent: publish nothing, write nothing, try again later.
			if ( $marker->failed || $in->read_failed ) {
				return self::read_failed( $in );
			}

			// The published record first, once: until it holds what the stored map serves, the compile classifies nothing unused.
			if ( ! $caught && Values::pub_behind( $in->values, $in->prev_map ) ) {
				$caught  = true;
				$changed = false;
				$stored  = self::store_pub( null !== $in->prev_map ? Values::served( $in->prev_map ) : [], self::slug_ids( $in->languages ), $changed );

				// The language rows changed since this compile read them (a delete and an add under the slug): read again.
				if ( $changed && $restarts++ >= 1 ) {
					Lifecycle::mark_dirty( 'stale' );

					return [
						'status' => 'stale',
						'gen'    => 0,
						'report' => [],
					];
				}

				if ( true === $stored || $changed ) {
					continue;
				}
			}

			$build = ( new Compiler( $in, new Content(), $forget ) )->build();

			// A content lookup failed: its answers prove nothing (a page or shop path may be missing).
			if ( in_array( 'read_failed', $build['report']['errors'], true ) ) {
				return self::read_failed( $in );
			}

			// No stored rules (a flush in progress, a deleted option): keep the published map and the marker.
			if ( in_array( 'rules_missing', $build['report']['errors'], true ) ) {
				Lifecycle::mark_dirty( 'rules_missing' );

				return [
					'status' => 'no_rules',
					'gen'    => \PerfLocale\Admin\Typed::int( $in->prev_map['gen'] ?? null ),
					'report' => $build['report'],
				];
			}

			// Old bases the map alone carried go to the values first, so a lost map cannot lose them; then recompile once.
			if ( ! $durable && [] !== $build['durable'] ) {
				$durable = true;
				$changed = false;

				$stored = self::store_durable( $build['durable'], $in->values, self::slug_ids( $in->languages ), $changed );

				// The values changed since this compile read them (a forget, a save): its durable fields are stale.
				if ( $changed ) {
					if ( $restarts++ >= 1 ) {
						Lifecycle::mark_dirty( 'stale' );

						return [
							'status' => 'stale',
							'gen'    => 0,
							'report' => $build['report'],
						];
					}

					$durable = false;
					continue;
				}

				if ( true === $stored ) {
					continue;
				}

				// Not stored (busy or failed): the next compile tries again. Nothing to store is not a failure.
				if ( null === $stored ) {
					Lifecycle::mark_dirty( 'durable' );
				}
			}

			// Stale re-check: an input that changed while we compiled restarts once, then leaves the marker set.
			$again = Inputs::read( $registry );

			if ( $again->read_failed ) {
				return self::read_failed( $again );
			}

			if ( $again->fingerprint() === $in->fingerprint() && $again->values_raw === $in->values_raw ) {
				break;
			}

			if ( $restarts++ >= 1 ) {
				Lifecycle::mark_dirty( 'stale' );

				return [
					'status' => 'stale',
					'gen'    => 0,
					'report' => $build['report'],
				];
			}
		}

		$report = $build['report'];
		$map    = $build['map'];
		$stored = $in->prev_map;
		$gen    = \PerfLocale\Admin\Typed::int( $stored['gen'] ?? null );

		$over = in_array( 'cap_size', $report['errors'], true );

		if ( $over && null === $stored ) {
			self::ack( $snapshot, [] );

			return [
				'status' => 'too_large',
				'gen'    => $gen,
				'report' => $report,
			];
		}

		// Over the cap the previous map stays, without the values this compile refuses for any reason but a cap (only narrows).
		if ( $over ) {
			$map = self::narrowed( $stored, $report );
		}

		// No map now and none read: a row that appeared since (a publisher whose lease outlived ours) is left to the next compile.
		if ( null === $map && null === $in->map_raw ) {
			$now = OptionRead::of( self::MAP );

			if ( $now->failed ) {
				return self::read_failed( $in );
			}

			if ( null !== $now->bytes ) {
				Lifecycle::mark_dirty( 'lease_lost' );

				return [
					'status' => 'lease_lost',
					'gen'    => $gen,
					'report' => $report,
				];
			}
		}

		$changed = null === $map ? null !== $in->map_raw : ! self::same_map( $stored, $map, false );
		$refresh = ! $changed && null !== $map && ! self::same_map( $stored, $map, true );

		if ( $changed || $refresh ) {
			// Not extended (taken over, or the write failed): nothing written, and the next compile runs again.
			if ( ! Lock::extend( self::LOCK, self::LEASE ) ) {
				Lifecycle::mark_dirty( 'lease_lost' );

				return [
					'status' => 'lease_lost',
					'gen'    => $gen,
					'report' => $report,
				];
			}

			$gen = null === $map ? 0 : ( $changed ? $gen + 1 : max( 1, $gen ) );

			if ( null !== $map ) {
				// Only the fingerprints changed: same generation, no cache retirement.
				$map['gen'] = $gen;
			}

			$write = self::write_map( $in, $map );

			// Before and after the write, as Values::update() does.
			Lifecycle::clear_none();

			// The language rows or the values changed since this compile read them: nothing written; the next compile runs.
			if ( 'stale' === $write ) {
				Lifecycle::mark_dirty( 'stale' );

				return [
					'status' => 'stale',
					'gen'    => \PerfLocale\Admin\Typed::int( $stored['gen'] ?? null ),
					'report' => $report,
				];
			}

			// The row changed under this compile: the newer one stays, and the next compile starts from it.
			if ( 'lost' === $write ) {
				Lifecycle::mark_dirty( 'lease_lost' );

				return [
					'status' => 'lease_lost',
					'gen'    => \PerfLocale\Admin\Typed::int( $stored['gen'] ?? null ),
					'report' => $report,
				];
			}

			if ( 'written' !== $write ) {
				Lifecycle::mark_dirty( 'write_failed' );

				return [
					'status' => 'failed',
					'gen'    => \PerfLocale\Admin\Typed::int( $stored['gen'] ?? null ),
					'report' => $report,
				];
			}
		}

		// What is served now (and what was) is recorded only after the write: never a map this compile did not publish.
		$served = Values::served( $map ?? [] );

		foreach ( Values::unforgotten( null !== $stored ? Values::served( $stored ) : [], $in->values['forget'] ?? [] ) as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $list ) {
				$served[ $key ][ $lang ] = array_merge( $served[ $key ][ $lang ] ?? [], $list );
			}
		}

		if ( Values::pub_behind( $in->values, null ) || Values::pub_lacks( $in->values, $served ) ) {
			$moved = false;
			self::store_pub( $served, self::slug_ids( $in->languages ), $moved );

			// A language deleted and added again under a slug since the read: recorded nothing; the next compile records.
			if ( $moved ) {
				Lifecycle::mark_dirty( 'stale' );
			}
		}

		// The published map holds no forgotten base any more: the pending instruction is done.
		if ( ! $over && [] !== ( $in->values['forget'] ?? [] ) ) {
			self::forgotten( $in->values['forget'], $map );
		}

		$pending = $changed ? self::adapters() : array_keys( self::retire_state( $snapshot ) );
		$failed  = [] !== $pending ? self::retire( $pending ) : [];

		self::ack( $snapshot, self::count_failures( $snapshot, $failed ), array_values( array_diff( $pending, $failed ) ) );

		return [
			'status' => $over ? 'too_large' : ( $changed ? 'published' : 'unchanged' ),
			'gen'    => $gen,
			'report' => $report,
		];
	}

	/**
	 * The map write as a slug-binding write: in one unit, in the global lock order (language rows, values row, map row, other
	 * options), only while the language rows (id and slug), the values and the map hold what this compile read.
	 *
	 * @param Inputs                    $in  The inputs this compile read.
	 * @param array<string, mixed>|null $map The map to store; null deletes the row.
	 * @return string 'written', 'stale' (languages or values changed), 'lost' (the map row changed) or 'failed'.
	 */
	private static function write_map( Inputs $in, ?array $map ): string {
		$why     = 'failed';
		$outcome = \PerfLocale\Database\Tx::run(
			'url_bases',
			// A closure, not an arrow function: `$why` must reach this scope by reference.
			static function ( \PerfLocale\Database\Tx $tx ) use ( $in, $map, &$why ): bool {
				$rows = Inputs::lock_languages() && $tx->alive() ? Inputs::rows( true ) : null;
				$tx->check();

				if ( null === $rows ) {
					return false;
				}

				// A language deleted and another added under its slug since the read has another id: the aliases built for the
				// old one must not be published for it.
				if ( self::slug_ids( $rows ) !== self::slug_ids( $in->languages ) ) {
					$why = 'stale';

					return false;
				}

				$values = OptionRead::of( Values::OPTION, true );
				$tx->check();

				if ( $values->failed ) {
					return false;
				}

				if ( $values->bytes !== $in->values_raw ) {
					$why = 'stale';

					return false;
				}

				$now = OptionRead::of( self::MAP, true );
				$tx->check();

				if ( $now->failed ) {
					return false;
				}

				if ( $now->bytes !== $in->map_raw ) {
					$why = 'lost';

					return false;
				}

				// Same end state whoever wrote it: OptionWriter confirms a resent statement by a successful re-read; its cache
				// update and the option actions (Runtime drops its map) wait for the COMMIT.
				if ( null === $map ) {
					// Nothing is translated or aliased any more: zero cost. Old bases stay in the values (durable fields).
					$written = null !== $in->map_raw && null !== \PerfLocale\Database\OptionWriter::compare_and_delete( self::MAP, $in->map_raw, true, $tx->guard()->condition() );
				} else {
					Lifecycle::clear_none();
					$written = null !== \PerfLocale\Database\OptionWriter::compare_and_set( self::MAP, $map, null !== $in->map_raw ? maybe_unserialize( $in->map_raw ) : false, $in->map_raw, true, true, $tx->guard()->condition() );
				}

				if ( ! $written && 'changed' === ( \PerfLocale\Database\OptionWriter::last_failure( self::MAP )['reason'] ?? '' ) ) {
					$why = 'lost';
				}

				return $written;
			},
			// COMMIT unconfirmed: the row's bytes say whether it landed.
			static function (): ?bool {
				$wrote = \PerfLocale\Database\OptionWriter::wrote( self::MAP );
				$now   = OptionRead::of( self::MAP );

				return $now->failed || null === $wrote ? null : $now->bytes === $wrote[0];
			}
		);

		if ( [] !== $outcome->forgotten ) {
			self::$dry = [];
		}

		return 'done' === $outcome->status ? 'written' : ( 'body' === $outcome->why ? $why : 'failed' );
	}

	/**
	 * Language slugs by id, in id order: what binds slug-keyed data to a language.
	 *
	 * @param list<array{id: int, slug: string, locale: string, is_default: bool, is_active: bool}> $langs Language rows.
	 * @return array<int, string>
	 */
	private static function slug_ids( array $langs ): array {
		$out = array_column( $langs, 'slug', 'id' );
		ksort( $out );

		return $out;
	}

	/**
	 * A compile that stops on a failed read: the dirty marker stays set for the next one.
	 *
	 * @param Inputs $in The inputs whose read failed.
	 * @return Result
	 */
	private static function read_failed( Inputs $in ): array {
		Lifecycle::mark_dirty( 'read_failed' );

		return [
			'status' => 'read_failed',
			'gen'    => \PerfLocale\Admin\Typed::int( $in->prev_map['gen'] ?? null ),
			'report' => [],
		];
	}

	/**
	 * The stored map without the current, history and previous entries a compile over the cap suspends for any reason
	 * but a cap (a cap can come from the growth itself): a later collision still wins.
	 *
	 * @param array<string, mixed> $map    The stored map.
	 * @param Report               $report The new compile's report.
	 * @return array<string, mixed>
	 */
	private static function narrowed( array $map, array $report ): array {
		$cap  = [ 'cap_first_segment', 'cap_keys', 'cap_languages', 'cap_shared', 'too_many_unverified', 'shop_children' ];
		$drop = [];

		foreach ( $report['entries'] as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $cell ) {
				if ( 'suspended' === $cell['status'] && ! in_array( $cell['reason'], $cap, true ) ) {
					$drop[ $key . '|' . $lang . '|' . $cell['value'] ] = true;
				}
			}
		}

		foreach ( $report['history'] as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $rows ) {
				foreach ( $rows as $row ) {
					if ( 'suspended' === $row['status'] && ! in_array( $row['reason'], $cap, true ) ) {
						$drop[ $key . '|' . $lang . '|' . $row['value'] ] = true;
					}
				}
			}
		}

		if ( [] === $drop ) {
			return $map;
		}

		$alias = [];

		foreach ( \PerfLocale\Admin\Typed::arr( $map['alias'] ?? null ) as $first => $list ) {
			$kept = array_values(
				array_filter(
					\PerfLocale\Admin\Typed::arr( $list ),
					static fn( $e ): bool => ! is_array( $e ) || 'registered' === ( $e['kind'] ?? '' ) || ! isset( $drop[ \PerfLocale\Admin\Typed::str( $e['key'] ?? null ) . '|' . \PerfLocale\Admin\Typed::str( $e['lang'] ?? null ) . '|' . implode( '/', \PerfLocale\Admin\Typed::strs( $e['segs'] ?? null ) ) ] )
				)
			);

			if ( [] !== $kept ) {
				$alias[ $first ] = $kept;
			}
		}

		$tr = [];

		foreach ( \PerfLocale\Admin\Typed::arr( $map['tr'] ?? null ) as $key => $by_lang ) {
			foreach ( \PerfLocale\Admin\Typed::arr( $by_lang ) as $lang => $value ) {
				if ( ! isset( $drop[ $key . '|' . $lang . '|' . \PerfLocale\Admin\Typed::str( $value ) ] ) ) {
					$tr[ $key ][ $lang ] = $value;
				}
			}
		}

		$map['alias'] = $alias;
		$map['tr']    = $tr;

		return $map;
	}

	/**
	 * Merge the durable fields a compile computed into the stored values, without queuing a compile for it; nothing when
	 * the stored values differ from the ones the compile read (`$changed` set): a forget confirmed since would come back.
	 *
	 * @param array<string, array{grp: string, reg?: list<string>, hist?: array<string, list<string>>}> $durable Key => fields.
	 * @param array<string, mixed>                                                                       $seen    The values the compile read.
	 * @param array<int, string>                                                                        $langs   The language slugs by id it read.
	 * @param bool                                                                                       $changed Set when they changed since.
	 * @return bool|null True when stored, false when nothing changed or nothing a user set needs a row, null when not saved.
	 */
	private static function store_durable( array $durable, array $seen, array $langs, bool &$changed ): ?bool {
		// Closures, not arrow functions: `$changed` must reach the caller by reference.
		$stored = Lifecycle::quietly(
			static function () use ( $durable, $seen, $langs, &$changed ): ?bool {
				return Values::update(
					static function ( array $v, array $live, array $rows ) use ( $durable, $seen, $langs, &$changed ): array {
						// A language deleted and added again under a slug has another id: its history is not the old one's.
						if ( $v !== $seen || self::slug_ids( $rows ) !== $langs ) {
							$changed = true;

							return $v;
						}

						// History only for languages there now ($live: locked in this transaction).
						foreach ( $durable as $key => $fields ) {
							$entry        = $v['keys'][ $key ] ?? [];
							$entry['grp'] = $entry['grp'] ?? $fields['grp'];

							if ( isset( $fields['reg'] ) ) {
								$entry['reg'] = $fields['reg'];
							}

							foreach ( $fields['hist'] ?? [] as $lang => $list ) {
								if ( in_array( (string) $lang, $live, true ) ) {
									$entry['hist'][ $lang ] = $list;
								}
							}

							$v['keys'][ $key ] = $entry;
						}

						return $v;
					}
				);
			}
		);

		return $stored;
	}

	/**
	 * Record served addresses in the values' `pub` (Values::with_pub()), without queuing a compile; a failure marks the
	 * blog dirty so the next compile repeats it.
	 *
	 * @param array<string, array<string, list<string>>> $served  Addresses by key and language.
	 * @param array<int, string>                         $langs   The language slugs by id the compile read.
	 * @param bool                                       $changed Set when the locked language rows differ (nothing recorded).
	 * @return bool|null As Values::update().
	 */
	private static function store_pub( array $served, array $langs, bool &$changed ): ?bool {
		// The language rows locked in the write's transaction: a language deleted since the map was built gets none, and one
		// added again under its slug (another id) gets nothing the old one served.
		// Closures, not arrow functions: `$changed` must reach the caller by reference.
		$stored = Lifecycle::quietly(
			static function () use ( $served, $langs, &$changed ): ?bool {
				return Values::update(
					static function ( array $v, array $live, array $rows ) use ( $served, $langs, &$changed ): array {
						if ( self::slug_ids( $rows ) !== $langs ) {
							$changed = true;

							return $v;
						}

						return Values::with_pub( $v, $served, $live );
					}
				);
			}
		);

		if ( null === $stored ) {
			Lifecycle::mark_dirty( 'provenance' );
		}

		return is_bool( $stored ) ? $stored : null;
	}

	/**
	 * Drop published forget instructions from the values, and their addresses from `pub` once the map no longer serves
	 * them (a compile that read the values before the forget may have recorded them again); one added meanwhile stays.
	 *
	 * @param list<string>              $done Keys (or `*`) and entries the published map honoured.
	 * @param array<string, mixed>|null $map  The map published now.
	 * @return bool Whether the values were stored (or needed nothing).
	 */
	private static function forgotten( array $done, ?array $map ): bool {
		$served = Values::served( $map ?? [] );

		$stored = Lifecycle::quietly(
			static fn(): ?bool => Values::update(
				static function ( array $v ) use ( $done, $served ): array {
					$v['forget'] = array_values( array_diff( $v['forget'] ?? [], $done ) );

					foreach ( $done as $item ) {
						$e = Values::entry( $item );

						if ( null !== $e && '*' !== $e[1] && ! in_array( $e[2], $served[ $e[0] ][ $e[1] ] ?? [], true ) ) {
							$v['pub'] = Values::without_pub( $v['pub'], $e[0], $e[1], $e[2] );
						}
					}

					return $v;
				}
			)
		);

		// Not stored (busy or failed): the pending forget stays and the next compile clears it.
		if ( null === $stored ) {
			Lifecycle::mark_dirty( 'forget' );
		}

		return null !== $stored;
	}

	/**
	 * A compile without any write, for the screens, Site Health and the CLI.
	 *
	 * @return Build
	 */
	public static function dry_run(): array {
		$blog = get_current_blog_id();

		return self::$dry[ $blog ] ??= ( new Compiler( Inputs::read(), new Content() ) )->build();
	}

	/**
	 * Drop the dry-run builds of this request (the values changed).
	 *
	 * @return void
	 */
	public static function reset_dry_run(): void {
		self::$dry = [];
	}

	/**
	 * In the caller's transaction, after its values release (LanguageWrites::release_slugs()): remove the slugs' entries
	 * from the published map so no compile after COMMIT carries them to a language added under a slug.
	 *
	 * @internal LanguageWrites::release_slugs() only: the caller proved the slugs free and rolls back on a throw.
	 * @param array<int, string>                   $slugs Released language slugs.
	 * @param \PerfLocale\Database\ConnectionGuard $guard The transaction's guard.
	 * @return array{0: array<mixed>, 1: array<mixed>}|null [ old map, new map ] when written; null when nothing to remove.
	 * @throws \RuntimeException When the row cannot be read or the write is not confirmed.
	 */
	public static function release_slugs( array $slugs, \PerfLocale\Database\ConnectionGuard $guard ): ?array {
		$row = OptionRead::of( self::MAP, true );

		if ( $row->failed || ! $guard->holds() ) {
			throw new \RuntimeException( 'release_slugs: the URL bases map could not be read' );
		}

		$map = null !== $row->bytes ? maybe_unserialize( $row->bytes ) : null;

		// No row, or no map v1: a compile reads no entries from it either (Inputs::read()).
		if ( null === $row->bytes || ! is_array( $map ) || 1 !== ( $map['v'] ?? null ) ) {
			return null;
		}

		$next = $map;

		foreach ( $slugs as $slug ) {
			$next = self::without_slug( $next, $slug );
		}

		if ( $next === $map ) {
			return null;
		}

		$wpdb = \PerfLocale\Admin\Typed::wpdb();
		$and  = '' !== $guard->condition() ? ' AND ' . $guard->condition() : '';
		$sql  = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, \PerfLocale\Database\OptionWriter::bytes( $next ), self::MAP, $row->bytes );

		\PerfLocale\Database\OptionWriter::touch( self::MAP );

		// Exactly one row under our lock; 0 is a resent statement or unknown (rule F): the caller rolls back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; $and is the guard condition (a checked marker).
		if ( ! is_string( $sql ) || 1 !== $wpdb->query( $sql . $and ) ) {
			throw new \RuntimeException( 'release_slugs: the URL bases map could not be written' );
		}

		return [ $map, $next ];
	}

	/**
	 * Whether stored map bytes hold entries release_slugs() would remove for a slug (no map v1: none).
	 *
	 * @param string|null $bytes Map row bytes; null when absent.
	 * @param string      $slug  Language slug.
	 * @return bool
	 */
	public static function holds_slug( ?string $bytes, string $slug ): bool {
		$map = null !== $bytes ? maybe_unserialize( $bytes ) : null;

		return is_array( $map ) && 1 === ( $map['v'] ?? null ) && self::without_slug( $map, $slug ) !== $map;
	}

	/**
	 * After a release's COMMIT, before any listener: the map caches, and a retirement owed for caches built from the old
	 * map (the next compile may find nothing else changed).
	 *
	 * @return void
	 */
	public static function map_released(): void {
		self::$dry = [];
		wp_cache_delete( self::MAP, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		Lifecycle::mark_dirty( 'language', self::adapters() );
	}

	/**
	 * After map_released(): the map write's option actions.
	 *
	 * @param array<mixed> $before Map before.
	 * @param array<mixed> $after  Map after.
	 * @return void
	 */
	public static function slug_released( array $before, array $after ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action for this option.
		do_action( 'update_option_' . self::MAP, $before, $after, self::MAP );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'updated_option', self::MAP, $before, $after );
	}

	/**
	 * A map v1 without a language slug's translated values, aliases and shop exceptions; key-level parts stay for the
	 * next compile to rebuild.
	 *
	 * @param array<mixed> $map  Map v1.
	 * @param string       $slug Language slug.
	 * @return array<mixed>
	 */
	private static function without_slug( array $map, string $slug ): array {
		$parts = [];

		foreach ( [ 'tr', 'alias', 'keys' ] as $part ) {
			$parts[ $part ] = is_array( $map[ $part ] ?? null ) ? $map[ $part ] : null;
		}

		foreach ( $parts['tr'] ?? [] as $key => $by_lang ) {
			if ( is_array( $by_lang ) && array_key_exists( $slug, $by_lang ) ) {
				unset( $by_lang[ $slug ] );
				$parts['tr'][ $key ] = $by_lang;

				if ( [] === $by_lang ) {
					unset( $parts['tr'][ $key ] );
				}
			}
		}

		foreach ( $parts['alias'] ?? [] as $first => $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}

			$kept = array_values( array_filter( $list, static fn( $e ): bool => ! is_array( $e ) || ( $e['lang'] ?? null ) !== $slug ) );

			if ( count( $kept ) !== count( $list ) ) {
				$parts['alias'][ $first ] = $kept;

				if ( [] === $kept ) {
					unset( $parts['alias'][ $first ] );
				}
			}
		}

		foreach ( $parts['keys'] ?? [] as $key => $desc ) {
			if ( is_array( $desc ) && is_array( $desc['exc'] ?? null ) && array_key_exists( $slug, $desc['exc'] ) ) {
				$exc = $desc['exc'];
				unset( $exc[ $slug ], $desc['exc'] );

				if ( [] !== $exc ) {
					$desc['exc'] = $exc;
				}

				$parts['keys'][ $key ] = $desc;
			}
		}

		foreach ( $parts as $part => $value ) {
			if ( null !== $value ) {
				$map[ $part ] = $value;
			}
		}

		return $map;
	}

	/**
	 * Whether two maps are equal apart from their generation (and, unless `$exact`, their input fingerprints).
	 *
	 * @param array<string, mixed>|null $a     Stored map.
	 * @param array<string, mixed>      $b     New map.
	 * @param bool                      $exact Compare `fp`, `rw` and `rg` too.
	 * @return bool
	 */
	private static function same_map( ?array $a, array $b, bool $exact ): bool {
		if ( null === $a ) {
			return false;
		}

		unset( $a['gen'], $b['gen'] );

		if ( ! $exact ) {
			unset( $a['fp'], $a['rw'], $a['rg'], $b['fp'], $b['rw'], $b['rg'] );
		}

		return $a === $b;
	}

	/**
	 * The dirty marker, unserialized (a failed read shows as no marker: display only).
	 *
	 * @param string|null $raw Stored bytes; null reads the row.
	 * @return Dirty
	 */
	public static function dirty( ?string $raw = null ): array {
		$raw ??= OptionRead::of( self::DIRTY )->bytes;
		$val   = \PerfLocale\Admin\Typed::arr( null !== $raw ? maybe_unserialize( $raw ) : null );
		$ret   = [];

		foreach ( \PerfLocale\Admin\Typed::map( $val['retire'] ?? null ) as $id => $n ) {
			$ret[ $id ] = \PerfLocale\Admin\Typed::int( $n );
		}

		return [
			'seq'     => \PerfLocale\Admin\Typed::int( $val['seq'] ?? null ),
			'reasons' => \PerfLocale\Admin\Typed::strs( $val['reasons'] ?? null ),
			'retire'  => $ret,
		];
	}

	/**
	 * Set the dirty marker (Lifecycle::mark_dirty()).
	 *
	 * @param string $reason Why the map needs a compile.
	 * @return void
	 */
	public static function mark_dirty( string $reason ): void {
		Lifecycle::mark_dirty( $reason );
	}

	/**
	 * Adapters still owed a retirement, with their attempts (from the marker snapshot).
	 *
	 * @param string|null $snapshot Marker bytes.
	 * @return array<string, int>
	 */
	private static function retire_state( ?string $snapshot ): array {
		return array_filter( self::dirty( $snapshot )['retire'], static fn( int $n ): bool => $n < self::RETIRE_ATTEMPTS );
	}

	/**
	 * Attempts per adapter after this run: failed ones +1, the others dropped.
	 *
	 * @param string|null  $snapshot Marker bytes.
	 * @param list<string> $failed   Adapters that failed now.
	 * @return array<string, int>
	 */
	private static function count_failures( ?string $snapshot, array $failed ): array {
		$before = self::dirty( $snapshot )['retire'];
		$out    = [];

		foreach ( $failed as $id ) {
			$out[ $id ] = ( $before[ $id ] ?? 0 ) + 1;
		}

		// Adapters past their attempts stay listed for Site Health.
		foreach ( $before as $id => $n ) {
			if ( $n >= self::RETIRE_ATTEMPTS && ! isset( $out[ $id ] ) ) {
				$out[ $id ] = $n;
			}
		}

		return $out;
	}

	/**
	 * Step 6: clear the marker only if it still holds the snapshot; failed retirements are kept for a retry.
	 *
	 * A marker rewritten since the snapshot keeps its reasons and gets the owed adapters merged in (CAS, 3 tries). While
	 * URL bases are configured the row stays as a clean marker (autoloaded: Lifecycle::admin_check() reads it with no query).
	 *
	 * @param string|null        $snapshot Marker bytes read before the compile.
	 * @param array<string, int> $retire   Adapters to keep with their attempts.
	 * @param list<string>       $done     Adapters retired now.
	 * @return void
	 */
	private static function ack( ?string $snapshot, array $retire, array $done = [] ): void {
		$wpdb = \PerfLocale\Admin\Typed::wpdb();
		$keep = [] !== $retire || Lifecycle::configured();

		// Not in use any more (nothing published, the switch off): the marker and the other rows go.
		if ( ! $keep && self::retire_unused( $snapshot ) ) {
			return;
		}

		$was = null !== $snapshot ? self::dirty( $snapshot ) : null;

		// A kept marker already clean: no write (a write drops alloptions).
		if ( [] === $retire && null !== $was && [] === $was['reasons'] && [] === $was['retire'] ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Compare-and-set on the marker row; prepared below.
		$raw = $snapshot;

		for ( $try = 0; $try < 3; $try++ ) {
			$cur  = null !== $raw ? self::dirty( $raw ) : self::dirty( '' );
			$own  = $raw === $snapshot;
			$next = maybe_serialize(
				[
					'seq'     => $cur['seq'] + 1,
					'reasons' => $own ? [] : $cur['reasons'],
					'retire'  => $own ? $retire : array_merge( array_diff_key( $cur['retire'], array_flip( $done ) ), $retire ),
					'at'      => microtime( true ) . ':' . wp_rand(),
				]
			);

			$sql = null === $raw
				? $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::DIRTY, $next, Lifecycle::autoload_on() )
				: $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $next, Lifecycle::autoload_on(), self::DIRTY, $raw );

			if ( ! is_string( $sql ) || 1 === (int) $wpdb->query( $sql ) ) {
				break;
			}

			$read = OptionRead::of( self::DIRTY );

			// A failed read acknowledges nothing: the marker stays for the next compile.
			if ( $read->failed ) {
				break;
			}

			$raw = $read->bytes;
		}
		// phpcs:enable

		Lifecycle::marker_written();
	}

	/**
	 * Remove the marker rows (dirty, checked, NONE) only where URL bases are switched off: the values row absent or off and no
	 * map, read past the caches after the marker, under the compile lock. Anything else, a failed read included, keeps them
	 * (Shop page, add-ons and code values only ever mark). The values row stays.
	 *
	 * @param string|null $acked Marker bytes whose retirements this compile has just done (they no longer keep it).
	 * @return bool Whether no marker is left.
	 */
	public static function retire_unused( ?string $acked = null ): bool {
		if ( self::$locked ) {
			return self::retire_locked( $acked );
		}

		// Keep-only shortcuts: a switch cached on (no forget pending), or no marker, needs no lock.
		$cached = get_option( Values::OPTION, false );
		$marker = OptionRead::of( self::DIRTY );

		if ( ( is_array( $cached ) && ! empty( $cached['on'] ) && empty( $cached['forget'] ) ) || $marker->failed ) {
			return false;
		}

		if ( null === $marker->bytes ) {
			return true;
		}

		$out = \PerfLocale\Database\Tx::leased(
			self::LOCK,
			self::LEASE,
			static function () use ( $acked ): bool {
				self::$locked = true;

				try {
					return self::retire_locked( $acked );
				} finally {
					self::$locked = false;
				}
			}
		);

		return true === $out;
	}

	/**
	 * retire_unused() under the lock: the marker read first, then the values and map rows past the caches, so a marker
	 * rewritten after the check no longer matches the compare-and-delete.
	 *
	 * @param string|null $acked Marker bytes whose retirements are done.
	 * @return bool Whether no marker is left.
	 */
	private static function retire_locked( ?string $acked ): bool {
		$read   = OptionRead::of( self::DIRTY );
		$marker = $read->bytes;

		// Retirements still owed keep the marker (and so the triggers) until they are done or out of attempts.
		if ( $read->failed || ( null !== $marker && $marker !== $acked && [] !== self::retire_state( $marker ) ) ) {
			return false;
		}

		$row = Values::read();

		if ( $row->failed || ! OptionRead::of( self::MAP )->absent() ) {
			return false;
		}

		$values = null !== $row->bytes ? maybe_unserialize( $row->bytes ) : null;

		// No map serves anything: a forget a failed write left pending is done (left, it would drop a later live address).
		$pending = Values::shape( $values )['forget'] ?? [];

		if ( [] !== $pending && ! self::forgotten( $pending, null ) ) {
			return false;
		}

		if ( null !== $values && ! ( is_array( $values ) && empty( $values['on'] ) ) ) {
			return false;
		}

		if ( null !== $marker ) {
			$wpdb = \PerfLocale\Admin\Typed::wpdb();
			// Compare-and-delete, byte for byte: a marker rewritten since the read stays, and so do the other rows.
			$sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, self::DIRTY, $marker );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$gone = is_string( $sql ) && 1 === (int) $wpdb->query( $sql );

			Lifecycle::marker_written();

			if ( ! $gone ) {
				return false;
			}
		}

		delete_option( Lifecycle::CHECKED_OPTION );
		delete_option( LifecycleHooks::NONE );

		return true;
	}

	/**
	 * Adapter ids of the active SEO plugins whose URL caches PerfLocale retires, plus PerfLocale's own caches.
	 *
	 * @return list<string>
	 */
	private static function adapters(): array {
		$out = [ 'perflocale' ];

		foreach ( [
			'yoast'    => static fn(): bool => function_exists( 'YoastSEO' ) || class_exists( 'WPSEO_Sitemaps_Cache' ),
			'rankmath' => static fn(): bool => class_exists( 'RankMath\\Sitemap\\Cache' ),
			'aioseo'   => static fn(): bool => function_exists( 'aioseo' ),
			'tsf'      => static fn(): bool => class_exists( 'The_SEO_Framework\\Sitemap\\Cache' ),
		] as $id => $active ) {
			if ( $active() ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Step 5: retire the URL caches built from the old map; returns the adapters that failed.
	 *
	 * Confirmed APIs: Yoast SEO 28.1 src/helpers/indexable-helper.php:169 reset_permalink_indexables(),
	 * inc/sitemaps/class-sitemaps-cache.php:272 WPSEO_Sitemaps_Cache::clear(); Rank Math 1.0.275
	 * includes/modules/sitemap/class-cache.php:209 Cache::invalidate_storage(); AIOSEO 4.9.10
	 * app/Common/Sitemap/Sitemap.php:279 scheduleRegeneration() (static sitemaps; dynamic ones are not stored);
	 * The SEO Framework 5.1.4 inc/classes/sitemap/cache.class.php:56 Cache::clear_sitemap_caches(). SEOPress 10.1 and
	 * Slim SEO 4.9.11 store no sitemap URLs: the page-cache signal below covers them.
	 *
	 * @param list<string> $ids Adapters to run: all after a new map, else the ones still owed.
	 * @return list<string>
	 */
	private static function retire( array $ids ): array {
		$failed = [];

		foreach ( $ids as $id ) {
			try {
				switch ( $id ) {
					case 'perflocale':
						// Hreflang, switcher, slug and sitemap entries hold base-built URLs; flush_all() also fires
						// perflocale/cache/flush_all, the signal page-cache integrations purge on.
						/** @var \PerfLocale\Cache\CacheManager $cache */
						$cache = \PerfLocale\Plugin::get_instance()->get( 'cache' );
						$cache->flush_all();
						break;
					case 'yoast':
						self::yoast_reset();
						self::call( [ 'WPSEO_Sitemaps_Cache', 'clear' ] );
						break;
					case 'rankmath':
						self::call( [ 'RankMath\\Sitemap\\Cache', 'invalidate_storage' ] );
						break;
					case 'aioseo':
						$aioseo  = self::fn( 'aioseo' );
						$sitemap = is_object( $aioseo ) && property_exists( $aioseo, 'sitemap' ) ? $aioseo->sitemap : null;
						self::call( [ $sitemap, 'scheduleRegeneration' ] );
						break;
					case 'tsf':
						self::call( [ 'The_SEO_Framework\\Sitemap\\Cache', 'clear_sitemap_caches' ] );
						break;
				}
			} catch ( \Throwable $e ) {
				$failed[] = $id;
			}
		}

		return $failed;
	}

	/**
	 * Yoast SEO: reset the stored permalinks of post, term and archive indexables.
	 *
	 * @return void
	 */
	private static function yoast_reset(): void {
		$yoast   = self::fn( 'YoastSEO' );
		$helpers = is_object( $yoast ) && isset( $yoast->helpers ) ? $yoast->helpers : null;
		$helper  = is_object( $helpers ) && isset( $helpers->indexable ) ? $helpers->indexable : null;

		foreach ( [ 'post', 'term', 'post-type-archive' ] as $type ) {
			self::call( [ $helper, 'reset_permalink_indexables' ], $type );
		}
	}

	/**
	 * The result of a third-party function, or null when it does not exist.
	 *
	 * @param string $name Function name.
	 * @return mixed
	 */
	private static function fn( string $name ): mixed {
		return is_callable( $name ) ? call_user_func( $name ) : null;
	}

	/**
	 * Call a third-party method when it exists.
	 *
	 * @param array{0: mixed, 1: string} $callable Class or object, method.
	 * @param mixed                      ...$args  Arguments.
	 * @return void
	 */
	private static function call( array $callable, mixed ...$args ): void {
		if ( is_callable( $callable ) ) {
			call_user_func_array( $callable, $args );
		}
	}
}
