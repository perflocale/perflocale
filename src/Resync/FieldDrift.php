<?php
/**
 * Field-only source changes of recorded pairs, found by a run's scan.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Automation\PostPayload;
use PerfLocale\Automation\PostTarget;
use PerfLocale\Database\Repository\ResyncRepository;
use PerfLocale\Translation\Fields\FieldMap;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A field save does not mark a translation Outdated: the scan compares the recorded field digest and key list instead.
 */
final class FieldDrift {

	/** Bases per read: a base row holds its field values too. */
	private const BASES_PER_READ = 50;

	/** Sources whose meta is held at once, as the pair runs hold it. */
	private const SOURCES_PER_CHUNK = 50;

	/** Most keys a base's `seen` map holds: twice the largest real post measured (2,002 keys); past it a base stores none. */
	public const SEEN_CAP = 4000;

	/**
	 * The pairs whose source's live values for the base's keys no longer give the state's field digest, or that gained
	 * an eligible key the base lacks whose value changed since the base (keys_added()).
	 *
	 * A state without a field digest (a revision bootstrap) or without a base is never drift: run() decides those.
	 *
	 * @param array<int, array{source_id: int, base_id: int, fields_hash: string}> $pairs By translation id.
	 * @param callable|null                                                        $stop  Asked between source chunks; true stops.
	 * @phpstan-param (callable(): bool)|null $stop
	 * @return array<int, true>|false|null By translation id; false when $stop stopped it; null when a read failed.
	 */
	public static function changed( array $pairs, ?callable $stop = null ): array|false|null {
		$pairs = array_filter( $pairs, static fn ( array $p ): bool => $p['base_id'] > 0 && '' !== $p['fields_hash'] );

		if ( [] === $pairs ) {
			return [];
		}

		$repo = new ResyncRepository();
		$keys = [];

		foreach ( array_chunk( array_values( array_unique( array_column( $pairs, 'base_id' ) ) ), self::BASES_PER_READ ) as $ids ) {
			$read = $repo->base_keys_of( $ids );

			if ( null === $read ) {
				return null;
			}

			$keys += $read;
		}

		$by_source = [];

		foreach ( $pairs as $translation_id => $p ) {
			$by_source[ $p['source_id'] ][] = $translation_id;
		}

		$out      = [];
		$key_sets = ! FieldMap::definitions_unavailable();
		$seen     = [];

		foreach ( array_chunk( array_keys( $by_source ), self::SOURCES_PER_CHUNK ) as $c => $sources ) {
			if ( $c > 0 && null !== $stop && $stop() ) {
				return false;
			}

			// Primed here, dropped after the chunk: a selected pair reads its source's meta again.
			$primed = self::prime_meta( $sources );

			if ( null === $primed ) {
				return null;
			}

			$posts = [];
			$terms = [];

			try {
				$check = [];

				foreach ( $sources as $source_id ) {
					foreach ( $by_source[ $source_id ] as $translation_id ) {
						$base = $keys[ $pairs[ $translation_id ]['base_id'] ] ?? null;

						if ( null === $base ) {
							continue;
						}

						$list                    = $base['keys'];
						$seen[ $translation_id ] = $base['seen'];

						if ( Store::digest_for_keys( $list, PostTarget::field_values( $source_id, $list ) ) !== $pairs[ $translation_id ]['fields_hash'] ) {
							$out[ $translation_id ] = true;
						} elseif ( $key_sets && null !== $base['seen'] ) {
							$check[ $translation_id ] = $list;
						}
					}
				}

				// The key lists: one map per source. Only a pair whose base is not the source's list reads its translation
				// (keys it holds as more than one unit leave the list), 50 translations' meta at a time.
				$posts = [] === $check ? [] : array_values( array_map( 'intval', _get_non_cached_ids( $sources, 'posts' ) ) );

				if ( [] !== $posts ) {
					_prime_post_caches( $posts, false, false );
				}

				$terms       = [] === $check ? [] : self::prime_terms( $sources );
				$source_keys = [];
				$exact       = [];

				foreach ( $check as $translation_id => $list ) {
					$source_id = $pairs[ $translation_id ]['source_id'];
					$source    = get_post( $source_id );

					if ( ! $source instanceof \WP_Post ) {
						continue;
					}

					$source_keys[ $source_id ] ??= self::source_keys( $source );
					$added                       = self::keys_added( $list, $source_keys[ $source_id ], $source_id, $seen[ $translation_id ] );

					if ( null === $added ) {
						return null;
					}

					if ( $added ) {
						$exact[ $translation_id ] = $source;
					}
				}

				foreach ( array_chunk( $exact, self::SOURCES_PER_CHUNK, true ) as $group ) {
					$held = self::prime_meta( array_keys( $group ) );

					if ( null === $held ) {
						return null;
					}

					try {
						foreach ( $group as $translation_id => $source ) {
							$added = self::keys_added( $check[ $translation_id ], self::pair_keys( $source, $translation_id, $source_keys[ $source->ID ] ), $source->ID, $seen[ $translation_id ] );

							if ( null === $added ) {
								return null;
							}

							if ( $added ) {
								$out[ $translation_id ] = true;
							}
						}
					} finally {
						foreach ( $held as $id ) {
							wp_cache_delete( $id, 'post_meta' );
						}
					}
				}
			} finally {
				foreach ( $primed as $id ) {
					wp_cache_delete( $id, 'post_meta' );
				}

				foreach ( $posts as $id ) {
					wp_cache_delete( $id, 'posts' );
				}

				foreach ( $terms as $id => $taxonomies ) {
					foreach ( $taxonomies as $taxonomy ) {
						wp_cache_delete( $id, $taxonomy . '_relationships' );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Prime the meta of the posts not cached yet, in one read; null when it failed (core caches that as no meta).
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return list<int>|null The ids primed (to drop after use).
	 */
	private static function prime_meta( array $ids ): ?array {
		global $wpdb;

		$primed = array_values( array_filter( $ids, static fn ( int $id ): bool => false === wp_cache_get( $id, 'post_meta' ) ) );

		if ( [] === $primed ) {
			return [];
		}

		update_meta_cache( 'post', $primed );

		if ( ! $wpdb instanceof \wpdb || '' !== $wpdb->last_error ) {
			foreach ( $primed as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}

			return null;
		}

		return $primed;
	}

	/**
	 * Prime the term relationships of a chunk's sources, one read per post type: a host's location rules (ACF) read them.
	 *
	 * @param array<int, int> $sources Source ids.
	 * @return array<int, list<string>> The taxonomies primed, by post id (to drop after the chunk).
	 */
	private static function prime_terms( array $sources ): array {
		$by_type = [];

		foreach ( $sources as $id ) {
			$post = get_post( $id );

			if ( $post instanceof \WP_Post ) {
				$by_type[ $post->post_type ][] = $id;
			}
		}

		$out = [];

		foreach ( $by_type as $type => $ids ) {
			$taxonomies = array_values( get_object_taxonomies( (string) $type ) );

			if ( [] === $taxonomies ) {
				continue;
			}

			$cold = array_values( array_filter( $ids, static fn ( int $id ): bool => false === wp_cache_get( $id, $taxonomies[0] . '_relationships' ) ) );

			if ( [] !== $cold ) {
				update_object_term_cache( $cold, (string) $type );
			}

			foreach ( $cold as $id ) {
				$out[ $id ] = $taxonomies;
			}
		}

		return $out;
	}

	/**
	 * Whether a pair now has an eligible key its base lacks, holding a non-empty unit as field_values() reads it (a run
	 * writes nothing else) whose rows are not the ones the base's `seen` map recorded (a new ACF row, a field edited
	 * since). Design C7: a key that only entered the scope (a setting, an add-on) or left it is no work; a base stored
	 * without the map counts its own keys only.
	 *
	 * @param array<int, string>         $base_keys The base's keys.
	 * @param array<int, string>         $current   pair_keys() now.
	 * @param int                        $source_id The source post.
	 * @param array<string, string>|null $seen      The base's `seen` map.
	 * @return bool|null Null when the source's meta could not be read.
	 */
	public static function keys_added( array $base_keys, array $current, int $source_id, ?array $seen ): ?bool {
		$added = array_values( array_diff( array_map( 'strval', $current ), array_map( 'strval', $base_keys ) ) );

		if ( [] === $added || null === $seen || FieldMap::definitions_unavailable() ) {
			return false;
		}

		if ( self::unit_moved( $source_id, $added, $seen ) ) {
			return true;
		}

		// No meta at all is also what a failed load leaves cached: only a checked read tells them apart.
		$all = get_post_meta( $source_id );

		if ( ! is_array( $all ) || [] !== $all ) {
			return false;
		}

		return false === PostTarget::rowless_keys( $source_id, $added ) ? null : self::unit_moved( $source_id, $added, $seen );
	}

	/**
	 * Whether one of the keys holds a non-empty unit whose rows differ from the `seen` digest (or have none).
	 *
	 * @param int                   $post_id Post id.
	 * @param list<string>          $keys    Meta keys.
	 * @param array<string, string> $seen    The base's `seen` map.
	 * @return bool
	 */
	private static function unit_moved( int $post_id, array $keys, array $seen ): bool {
		$all = null;

		foreach ( PostTarget::field_values( $post_id, $keys ) as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			// seen() digests the raw rows: the same read here, or every key would look moved.
			$all ??= get_post_meta( $post_id );
			$rows  = is_array( $all ) && is_array( $all[ $key ] ?? null ) ? $all[ $key ] : [];

			if ( ( $seen[ $key ] ?? '' ) !== Store::rows_digest( $rows ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The `seen` map a base stores: the row digest of each source key outside its list that may become eligible and
	 * holds a non-empty unit (keys_added()); a key either sensitive list names is never eligible and never stored.
	 *
	 * @param int                $source_id The source post.
	 * @param array<int, string> $listed    The base's keys.
	 * @return array<string, string>|false|null False past SEEN_CAP keys (the base stores no map: a toggle starts nothing,
	 *                                          its own keys still count); null when the source's meta could not be read.
	 */
	public static function seen( int $source_id, array $listed ): array|false|null {
		$all = get_post_meta( $source_id );

		if ( ! is_array( $all ) ) {
			return null;
		}

		// No meta at all is also what a failed load leaves cached; meta found only now means the caller's values came from one.
		if ( [] === $all ) {
			return false === PostTarget::rowless_keys( $source_id, [] ) || [] !== get_post_meta( $source_id ) ? null : [];
		}

		$skip  = array_fill_keys( array_map( 'strval', $listed ), true );
		$never = array_merge( PostTranslationManager::sensitive_meta_patterns(), PostTranslationManager::mt_sensitive_meta_patterns() );
		$rows  = [];
		$out   = [];

		foreach ( PostPayload::writable_keys( array_map( 'strval', array_keys( $all ) ), $never ) as $key ) {
			if ( ! isset( $skip[ $key ] ) && is_array( $all[ $key ] ) ) {
				$rows[ $key ] = $all[ $key ];
			}
		}

		// A key with no unit is left out: keys_added() counts none, and once it holds one its absence counts as a change.
		foreach ( PostTarget::field_values( $source_id, array_map( 'strval', array_keys( $rows ) ) ) as $key => $unit ) {
			if ( null === $unit || '' === $unit ) {
				continue;
			}

			if ( count( $out ) >= self::SEEN_CAP ) {
				return false;
			}

			$out[ $key ] = Store::rows_digest( $rows[ $key ] );
		}

		return $out;
	}

	/**
	 * Whether a base key off the list has a value other than the base's: a removed ACF row, or a field that left and was edited.
	 *
	 * @param array<int, string>    $base_keys   The base's keys.
	 * @param array<string, string> $base_values The base's values.
	 * @param array<int, string>    $keys        The keys the run covers.
	 * @param int                   $source_id   The source post.
	 * @return bool
	 */
	public static function left_moved( array $base_keys, array $base_values, array $keys, int $source_id ): bool {
		$left = array_values( array_diff( $base_keys, $keys ) );

		foreach ( PostTarget::field_values( $source_id, $left ) as $key => $value ) {
			if ( ( $base_values[ $key ] ?? null ) !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a pair gained a key. The translation is read only when the source's list did: pair_keys() is a subset of it.
	 *
	 * @param array<int, string>         $base_keys      The base's keys.
	 * @param array<string, string>|null $seen           The base's `seen` map.
	 * @param \WP_Post                   $source         Source.
	 * @param int                        $translation_id Translation id.
	 * @param list<string>|null          $every          Set to pair_keys() of every row when it was built.
	 * @return bool|null Null when the source's meta could not be read.
	 */
	public static function pair_added( array $base_keys, ?array $seen, \WP_Post $source, int $translation_id, ?array &$every = null ): ?bool {
		if ( null === $seen ) {
			return false;
		}

		$all   = self::source_keys( $source );
		$added = self::keys_added( $base_keys, $all, $source->ID, $seen );

		if ( true !== $added ) {
			return $added;
		}

		$every = self::pair_keys( $source, $translation_id, $all );

		return self::keys_added( $base_keys, $every, $source->ID, $seen );
	}

	/**
	 * The keys a resync may write for a source: the registry minus sensitive keys, storable by the field layer.
	 *
	 * @param \WP_Post $source         Source.
	 * @param int      $translation_id Translation the registry is asked for (0: the source's keys, every row).
	 * @return list<string>
	 */
	public static function source_keys( \WP_Post $source, int $translation_id = 0 ): array {
		// The key pass builds and memoises this post's map; the memo is emptied first so only that build is reused.
		FieldMap::reset_memo();

		$keys = PostPayload::writable_keys( SyncRecorder::field_keys( $source, $translation_id ), PostTranslationManager::sensitive_meta_patterns() );
		$map  = FieldMap::memo( FieldMap::KIND_POST, $source->ID, $source->post_type ) ?? FieldLayer::map_for( $source );

		return FieldLayer::storable_keys( $map, $keys );
	}

	/**
	 * The keys a pair's state records: source_keys() minus those the translation holds as more than one unit.
	 *
	 * @param \WP_Post          $source         Source.
	 * @param int               $translation_id Translation id.
	 * @param list<string>|null $source_keys    source_keys() of the source's every row, when already built.
	 * @return list<string>
	 */
	public static function pair_keys( \WP_Post $source, int $translation_id, ?array $source_keys = null ): array {
		$keys = $source_keys ?? self::source_keys( $source );

		return array_values( array_diff( $keys, PostTarget::keys_without_unit( $translation_id, $keys ) ) );
	}
}
