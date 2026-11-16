<?php
/**
 * Reference values mapped through the translation groups.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The IDs a `reference` field holds, read through the translation groups. map() keeps the value's shape and takes
 * only a live member of the referenced kind; canonical() names each group by its source-language member (else the
 * smallest id), so every map() result is equivalent to its input. Per blog; never loaded on a page view.
 */
final class ReferenceMapper {

	/** Array levels walked (the value and the lists it holds); deeper arrays stay as they are. */
	public const MAX_DEPTH = 2;

	/**
	 * Constructor.
	 *
	 * @param TranslationGroupRepository $groups      The translation groups.
	 * @param \Closure|null              $source_lang Returns the current blog's source language slug; null reads it from the plugin.
	 */
	public function __construct(
		private readonly TranslationGroupRepository $groups,
		private readonly ?\Closure $source_lang = null
	) {
	}

	/**
	 * Read the groups and objects of many values at once, so the map() and canonical() calls that follow
	 * need no query.
	 *
	 * @param iterable<int|string, array{0: mixed, 1: FieldDescriptor}> $values Pairs of a stored value and its field.
	 * @return void
	 */
	public function prime( iterable $values ): void {
		$ids = [];

		foreach ( $values as $pair ) {
			$type = self::object_type( $pair[1] );

			if ( null !== $type ) {
				$ids[ $type->value ] ??= [];
				self::collect( $pair[0], self::is_csv_field( $pair[1] ), 0, $ids[ $type->value ] );
			}
		}

		foreach ( [ ObjectType::Post, ObjectType::Term ] as $type ) {
			$found = $ids[ $type->value ] ?? [];

			if ( [] === $found ) {
				continue;
			}

			$this->groups->prime_translations( $type, array_keys( $found ) );

			$objects = $found;

			foreach ( array_keys( $found ) as $id ) {
				foreach ( $this->groups->get_translations( $id, $type ) as $link ) {
					$member = self::member_id( $link );

					if ( $member > 0 ) {
						$objects[ $member ] = $member;
					}
				}
			}

			self::prime_objects( $type, array_values( $objects ) );
		}
	}

	/**
	 * The value with each ID replaced by its group's member in a language.
	 *
	 * @param mixed           $value Stored value; anything but an array, int or string comes back as it is.
	 * @param FieldDescriptor $d     The field.
	 * @param string          $lang  Language slug.
	 * @return mixed The value in the same shape.
	 */
	public function map( mixed $value, FieldDescriptor $d, string $lang ): mixed {
		$type = self::object_type( $d );

		if ( null === $type || '' === $lang ) {
			return $value;
		}

		$csv = self::is_csv_field( $d );
		$ids = [];

		self::collect( $value, $csv, 0, $ids );

		if ( [] === $ids ) {
			return $value;
		}

		return self::replace( $value, $csv, $this->members( $type, array_keys( $ids ), $d, $lang ), 0 );
	}

	/**
	 * The value as a list of group representatives in its order; an empty value is the empty list.
	 *
	 * @param mixed           $value Stored value.
	 * @param FieldDescriptor $d     The field.
	 * @return array<int, mixed>
	 */
	public function canonical( mixed $value, FieldDescriptor $d ): array {
		return $this->canonicals( [ $value ], $d )[0];
	}

	/**
	 * Whether two values of a field name the same objects in the same order,
	 * whichever language's members they hold.
	 *
	 * @param mixed           $a One value.
	 * @param mixed           $b The other value.
	 * @param FieldDescriptor $d The field.
	 * @return bool
	 */
	public function equivalent( mixed $a, mixed $b, FieldDescriptor $d ): bool {
		$canonical = $this->canonicals( [ $a, $b ], $d );

		return $canonical[0] === $canonical[1];
	}

	/**
	 * The canonical lists of several values, with one lookup for all.
	 *
	 * @param array<int, mixed> $values Stored values.
	 * @param FieldDescriptor   $d      The field.
	 * @return array<int, array<int, mixed>>
	 */
	private function canonicals( array $values, FieldDescriptor $d ): array {
		if ( FieldClass::REFERENCE !== $d->class ) {
			return array_map( static fn( mixed $value ): array => [ $value ], $values );
		}

		$csv     = self::is_csv_field( $d );
		$type    = self::object_type( $d );
		$members = [];

		if ( null !== $type ) {
			$ids = [];

			foreach ( $values as $value ) {
				self::collect( $value, $csv, 0, $ids );
			}

			if ( [] !== $ids ) {
				$members = $this->representatives( $type, array_keys( $ids ) );
			}
		}

		return array_map( static fn( mixed $value ): array => self::canon( $value, $csv, $members ), $values );
	}

	/**
	 * Each ID's usable member in a language, for the IDs that have one other than themselves.
	 *
	 * @param ObjectType      $type Group type.
	 * @param array<int, int> $ids  IDs.
	 * @param FieldDescriptor $d    The field.
	 * @param string          $lang Language slug.
	 * @return array<int, int> ID => member.
	 */
	private function members( ObjectType $type, array $ids, FieldDescriptor $d, string $lang ): array {
		$this->groups->prime_translations( $type, $ids );

		$found = [];

		foreach ( $ids as $id ) {
			foreach ( $this->groups->get_translations( $id, $type ) as $link ) {
				if ( ! is_object( $link ) || ! isset( $link->language_slug ) || $lang !== $link->language_slug ) {
					continue;
				}

				$member = self::member_id( $link );

				if ( $member > 0 && $member !== $id ) {
					$found[ $id ] = $member;
				}

				break;
			}
		}

		if ( [] === $found ) {
			return $found;
		}

		$objects = [];

		foreach ( $found as $id => $member ) {
			$objects[ $id ]     = $id;
			$objects[ $member ] = $member;
		}

		self::prime_objects( $type, array_values( $objects ) );

		foreach ( $found as $id => $member ) {
			$usable = ObjectType::Term === $type ? self::usable_term( $id, $member, $d ) : self::usable_post( $id, $member, $d );

			if ( ! $usable ) {
				unset( $found[ $id ] );
			}
		}

		return $found;
	}

	/**
	 * Each ID's group representative: the source-language member, else the smallest member ID.
	 *
	 * @param ObjectType      $type Group type.
	 * @param array<int, int> $ids  IDs.
	 * @return array<int, int> ID => representative.
	 */
	private function representatives( ObjectType $type, array $ids ): array {
		$this->groups->prime_translations( $type, $ids );

		$source = $this->source_lang();
		$found  = [];

		foreach ( $ids as $id ) {
			$default  = 0;
			$smallest = 0;

			foreach ( $this->groups->get_translations( $id, $type ) as $link ) {
				$member = self::member_id( $link );

				if ( $member <= 0 ) {
					continue;
				}

				if ( '' !== $source && is_object( $link ) && isset( $link->language_slug ) && $source === $link->language_slug ) {
					$default = $member;
				}

				if ( 0 === $smallest || $member < $smallest ) {
					$smallest = $member;
				}
			}

			$representative = $default > 0 ? $default : $smallest;

			if ( $representative > 0 ) {
				$found[ $id ] = $representative;
			}
		}

		return $found;
	}

	/**
	 * The object ID of a link row, 0 when it has none.
	 *
	 * @param mixed $link Link row.
	 * @return int
	 */
	private static function member_id( mixed $link ): int {
		return ( is_object( $link ) && isset( $link->object_id ) && is_numeric( $link->object_id ) ) ? (int) $link->object_id : 0;
	}

	/**
	 * Prime the post or term cache with one query for the uncached ones.
	 *
	 * @param ObjectType      $type Group type.
	 * @param array<int, int> $ids  Object IDs.
	 * @return void
	 */
	private static function prime_objects( ObjectType $type, array $ids ): void {
		if ( ObjectType::Term === $type ) {
			if ( function_exists( '_prime_term_caches' ) ) {
				_prime_term_caches( $ids, false );
			}

			return;
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	/**
	 * Whether a post member can stand for the input post: live (not trashed or auto-draft), of an allowed type
	 * and of the input post's type; an attachment for an attachment field.
	 *
	 * @param int             $id     Input ID.
	 * @param int             $member Member ID.
	 * @param FieldDescriptor $d      The field.
	 * @return bool
	 */
	private static function usable_post( int $id, int $member, FieldDescriptor $d ): bool {
		$post = get_post( $member );

		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || 'auto-draft' === $post->post_status ) {
			return false;
		}

		if ( FieldClass::KIND_ATTACHMENT === $d->kind ) {
			return 'attachment' === $post->post_type;
		}

		if ( [] !== $d->post_types && ! in_array( $post->post_type, $d->post_types, true ) ) {
			return false;
		}

		$input = get_post( $id );

		return ! $input instanceof \WP_Post || $input->post_type === $post->post_type;
	}

	/**
	 * Whether a term member can stand for the input term: in an allowed taxonomy and the input term's taxonomy.
	 *
	 * @param int             $id     Input ID.
	 * @param int             $member Member ID.
	 * @param FieldDescriptor $d      The field.
	 * @return bool
	 */
	private static function usable_term( int $id, int $member, FieldDescriptor $d ): bool {
		$term = get_term( $member );

		if ( ! $term instanceof \WP_Term ) {
			return false;
		}

		if ( [] !== $d->taxonomies && ! in_array( $term->taxonomy, $d->taxonomies, true ) ) {
			return false;
		}

		$input = get_term( $id );

		return ! $input instanceof \WP_Term || $input->taxonomy === $term->taxonomy;
	}

	/**
	 * The current blog's source language slug, '' when there is none.
	 *
	 * @return string
	 */
	private function source_lang(): string {
		if ( null !== $this->source_lang ) {
			$slug = ( $this->source_lang )();

			return is_string( $slug ) ? $slug : '';
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'lang_repo' ) ) {
			return '';
		}

		$default = $plugin->lang_repo()->get_default();

		return ( null !== $default && isset( $default->slug ) && is_string( $default->slug ) ) ? $default->slug : '';
	}

	/**
	 * The group type of a reference descriptor's IDs; null when IDs are their own members (users, archives).
	 *
	 * @param FieldDescriptor $d The field.
	 * @return ObjectType|null
	 */
	private static function object_type( FieldDescriptor $d ): ?ObjectType {
		if ( FieldClass::REFERENCE !== $d->class ) {
			return null;
		}

		return match ( $d->kind ) {
			FieldClass::KIND_POST, FieldClass::KIND_ATTACHMENT => ObjectType::Post,
			FieldClass::KIND_TERM => ObjectType::Term,
			default => null,
		};
	}

	/**
	 * Whether a field stores its IDs as one comma-separated string.
	 *
	 * @param FieldDescriptor $d The field.
	 * @return bool
	 */
	private static function is_csv_field( FieldDescriptor $d ): bool {
		return 'mb' === $d->host && 'taxonomy_advanced' === $d->type;
	}

	/**
	 * The ID a scalar holds, 0 otherwise. A string must read back as itself ('012' is not an ID).
	 *
	 * @param mixed $value Scalar.
	 * @return int
	 */
	private static function id_of( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $value ) && (string) (int) $value === $value ) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Whether a string is a comma-separated list of IDs.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function is_csv( string $value ): bool {
		if ( 1 !== preg_match( '/^[0-9]+(,[0-9]+)*$/', $value ) ) {
			return false;
		}

		foreach ( explode( ',', $value ) as $piece ) {
			if ( 0 === self::id_of( $piece ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Gather the IDs of a value, in the order they appear.
	 *
	 * @param mixed           $value Value.
	 * @param bool            $csv   Whether strings are comma-separated lists.
	 * @param int             $depth Array level of the value.
	 * @param array<int, int> $ids   ID => ID, filled.
	 * @return void
	 */
	private static function collect( mixed $value, bool $csv, int $depth, array &$ids ): void {
		if ( is_array( $value ) ) {
			if ( $depth < self::MAX_DEPTH ) {
				foreach ( $value as $item ) {
					self::collect( $item, $csv, $depth + 1, $ids );
				}
			}

			return;
		}

		$id = self::id_of( $value );

		if ( $id > 0 ) {
			$ids[ $id ] = $id;

			return;
		}

		if ( $csv && is_string( $value ) && self::is_csv( $value ) ) {
			foreach ( explode( ',', $value ) as $piece ) {
				$ids[ (int) $piece ] = (int) $piece;
			}
		}
	}

	/**
	 * The value with each ID that has a member replaced by it, in the ID's own form.
	 *
	 * @param mixed           $value   Value.
	 * @param bool            $csv     Whether strings are comma-separated lists.
	 * @param array<int, int> $members ID => member.
	 * @param int             $depth   Array level of the value.
	 * @return mixed
	 */
	private static function replace( mixed $value, bool $csv, array $members, int $depth ): mixed {
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_DEPTH ) {
				return $value;
			}

			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace( $item, $csv, $members, $depth + 1 );
			}

			return $value;
		}

		$id = self::id_of( $value );

		if ( $id > 0 ) {
			if ( ! isset( $members[ $id ] ) ) {
				return $value;
			}

			return is_int( $value ) ? $members[ $id ] : (string) $members[ $id ];
		}

		if ( $csv && is_string( $value ) && self::is_csv( $value ) ) {
			$pieces = [];

			foreach ( explode( ',', $value ) as $piece ) {
				$pieces[] = (string) ( $members[ (int) $piece ] ?? $piece );
			}

			return implode( ',', $pieces );
		}

		return $value;
	}

	/**
	 * The canonical list of one value.
	 *
	 * @param mixed           $value   Value.
	 * @param bool            $csv     Whether strings are comma-separated lists.
	 * @param array<int, int> $members ID => representative.
	 * @return array<int, mixed>
	 */
	private static function canon( mixed $value, bool $csv, array $members ): array {
		if ( null === $value || false === $value || '' === $value ) {
			return [];
		}

		if ( is_array( $value ) ) {
			return self::canon_list( $value, $csv, $members, 0 );
		}

		if ( $csv && is_string( $value ) && self::is_csv( $value ) ) {
			return self::canon_csv( $value, $members );
		}

		return [ self::canon_scalar( $value, $members ) ];
	}

	/**
	 * The canonical list of an array: its items in order, keys dropped.
	 *
	 * @param array<int|string, mixed> $items   Array.
	 * @param bool                     $csv     Whether strings are comma-separated lists.
	 * @param array<int, int>          $members ID => representative.
	 * @param int                      $depth   Array level of the array.
	 * @return array<int, mixed>
	 */
	private static function canon_list( array $items, bool $csv, array $members, int $depth ): array {
		$out = [];

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $depth + 1 < self::MAX_DEPTH ? self::canon_list( $item, $csv, $members, $depth + 1 ) : self::opaque( $item );
			} elseif ( $csv && is_string( $item ) && self::is_csv( $item ) ) {
				$out[] = self::canon_csv( $item, $members );
			} else {
				$out[] = self::canon_scalar( $item, $members );
			}
		}

		return $out;
	}

	/**
	 * The canonical list of a comma-separated string.
	 *
	 * @param string          $value   String.
	 * @param array<int, int> $members ID => representative.
	 * @return array<int, int>
	 */
	private static function canon_csv( string $value, array $members ): array {
		$out = [];

		foreach ( explode( ',', $value ) as $piece ) {
			$out[] = $members[ (int) $piece ] ?? (int) $piece;
		}

		return $out;
	}

	/**
	 * The canonical form of a non-list item: an ID's representative, any other scalar as its string, null as ''.
	 *
	 * @param mixed           $value   Item.
	 * @param array<int, int> $members ID => representative.
	 * @return int|string
	 */
	private static function canon_scalar( mixed $value, array $members ): int|string {
		$id = self::id_of( $value );

		if ( $id > 0 ) {
			return $members[ $id ] ?? $id;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return null === $value ? '' : self::opaque( $value );
	}

	/**
	 * A comparable string of an object or unwalked array; never stored.
	 *
	 * @param mixed $value Object or array.
	 * @return string
	 */
	private static function opaque( mixed $value ): string {
		try {
			return serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A comparable form, never stored.
		} catch ( \Throwable $e ) {
			return is_object( $value ) ? spl_object_hash( $value ) : gettype( $value );
		}
	}
}
