<?php
/**
 * PerfLocale Meta Box addon: the field map producer and the key lists read from it.
 *
 * Loaded only by PerfLocaleMetabox on a save, a sync, machine translation, a job or an admin screen.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PerfLocale\Translation\Fields\FieldClass;
use PerfLocale\Translation\Fields\FieldDescriptor;
use PerfLocale\Translation\Fields\FieldMap;
use PerfLocale\Translation\Fields\FieldSnapshot;

/**
 * The Meta Box fields of a post as field-map descriptors, and the seed, password and MT key lists built from that map.
 */
final class PerfLocaleMetaboxFields {

	/**
	 * The host attribute of this addon's descriptors.
	 */
	private const HOST = 'mb';

	/**
	 * Build it with the addon's field lookups.
	 *
	 * @param \Closure(string): array<int|string, mixed> $fields_for        The Meta Box fields of a post type.
	 * @param \Closure(array<string, mixed>): bool        $post_meta_storage Whether a field stores post meta.
	 */
	public function __construct( private \Closure $fields_for, private \Closure $post_meta_storage ) {}

	/**
	 * Whether produce_map() uses this request's definitions on a switched blog: only for the password exclusion,
	 * where the boot blog's list can only exclude more.
	 *
	 * @var bool
	 */
	private bool $boot_definitions = false;

	/**
	 * `perflocale/fields/map`: each top-level Meta Box field that stores a row, with the leaves of its groups and clones.
	 *
	 * The shape comes from the definition (`multiple`, `clone`, `clone_as_multiple`), never from the row count: one
	 * `multiple` item is a row holding a scalar. The type-level map (post id 0) has the top-level descriptors only.
	 *
	 * @param mixed $map       The map being built.
	 * @param mixed $object_id Object id.
	 * @param mixed $kind      Object kind.
	 * @param mixed $subtype   Post type.
	 * @return mixed
	 */
	public function produce_map( mixed $map, mixed $object_id = 0, mixed $kind = '', mixed $subtype = '' ): mixed {
		if ( ! $map instanceof FieldMap || FieldMap::KIND_POST !== $kind || ! is_string( $subtype ) || '' === $subtype ) {
			return $map;
		}

		if ( ! function_exists( 'rwmb_get_object_fields' ) || ( ! $this->boot_definitions && FieldMap::definitions_unavailable() ) ) {
			return $map;
		}

		$post_id  = is_numeric( $object_id ) ? (int) $object_id : 0;
		$patterns = \PerfLocale\Translation\PostTranslationManager::sensitive_meta_patterns();
		$storage  = new \PerfLocale\Translation\Fields\PostMetaStorage();

		foreach ( ( $this->fields_for )( $subtype ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) || ! is_string( $field['id'] ) || empty( $field['type'] ) || ! is_string( $field['type'] ) ) {
				continue;
			}

			$where = $this->storage_of( $field );
			$id    = $field['id'];
			$rows  = ( $post_id > 0 && FieldClass::STORAGE_POSTMETA === $where ) ? $storage->read( $post_id, $id ) : null;

			self::describe( $field, $id, [], [ $id ], [], $rows, $where, $map, $patterns );
		}

		return $map;
	}

	/**
	 * Whether a descriptor is a key the copy/seed list carries: a top-level post-meta text field, group or cloneable text.
	 *
	 * @param FieldDescriptor $d Descriptor.
	 * @return bool
	 */
	public static function is_seed_descriptor( FieldDescriptor $d ): bool {
		if ( self::HOST !== $d->host || $d->mb_path !== [] || FieldClass::STORAGE_POSTMETA !== $d->storage ) {
			return false;
		}

		if ( FieldClass::TEXT === $d->class ) {
			return true;
		}

		return FieldClass::STRUCTURE === $d->class
			&& ( 'group' === $d->type || FieldClass::TEXT === FieldClass::metabox( $d->type )['class'] );
	}

	/**
	 * Add the descriptor of one field and, for a group or cloneable field with stored rows, those of its leaves.
	 *
	 * @param array<string, mixed>   $field    Normalised field.
	 * @param string                 $key      The top-level meta key.
	 * @param array<int, int|string> $path     Path inside the blob so far.
	 * @param array<int, string>     $id_path  Field ids so far, the top-level id first.
	 * @param array<int, string>     $labels   Labels of the enclosing groups.
	 * @param array<int, mixed>|null $rows     The stored rows of the top-level key; null when not read.
	 * @param string                 $storage  Storage attribute.
	 * @param FieldMap               $map      Out: the descriptors.
	 * @param array<int, string>     $patterns Sensitive key patterns.
	 * @return void
	 */
	private static function describe( array $field, string $key, array $path, array $id_path, array $labels, ?array $rows, string $storage, FieldMap $map, array $patterns ): void {
		$type  = (string) $field['type'];
		$clone = ! empty( $field['clone'] );

		// clone_as_multiple is top-level storage only (RWMB_Field::save()); inside a blob a clone is always a list.
		$cam    = $clone && $path === [] && ! empty( $field['clone_as_multiple'] );
		$label  = is_string( $field['name'] ?? null ) && '' !== $field['name'] ? $field['name'] : (string) $field['id'];
		$labels = array_merge( $labels, [ $label ] );
		$class  = self::classify( $field );

		$map->add( self::descriptor( $field, $key, $path, $id_path, $labels, $storage, $class, $clone, $cam, $patterns ) );

		if ( null === $rows || FieldClass::STRUCTURE !== $class['class'] ) {
			return;
		}

		// The stored items with their path index: one row per clone, one blob holding the clones, or one blob.
		$items = [];

		if ( $cam ) {
			foreach ( $rows as $i => $row ) {
				$items[] = [ $i, $row ];
			}
		} elseif ( $clone ) {
			foreach ( is_array( $rows[0] ?? null ) ? $rows[0] : [] as $i => $item ) {
				$items[] = [ $i, $item ];
			}
		} else {
			$items[] = [ null, $rows[0] ?? null ];
		}

		foreach ( $items as [ $index, $item ] ) {
			$item_path = null === $index ? $path : array_merge( $path, [ $index ] );

			if ( 'group' === $type ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				foreach ( (array) ( $field['fields'] ?? [] ) as $sub ) {
					if ( ! is_array( $sub ) || empty( $sub['id'] ) || ! is_string( $sub['id'] ) || empty( $sub['type'] ) || ! is_string( $sub['type'] ) ) {
						continue;
					}

					$value = $item[ $sub['id'] ] ?? null;
					self::describe( $sub, $key, array_merge( $item_path, [ $sub['id'] ] ), array_merge( $id_path, [ $sub['id'] ] ), $labels, [ $value ], $storage, $map, $patterns );
				}
			} else {
				$leaf = $field;

				$leaf['clone']             = false;
				$leaf['clone_as_multiple'] = false;

				$map->add( self::descriptor( $leaf, $key, $item_path, $id_path, $labels, $storage, FieldClass::metabox( $type ), false, false, $patterns ) );
			}
		}
	}

	/**
	 * The class of a normalised field: by type, then the shape rules.
	 *
	 * A `post` field writing `post_parent` or a group without sub-fields is `ignore`; a cloneable field is a `structure`
	 * of leaves, except a password, which stays a password whatever its shape so it is never copied.
	 *
	 * @param array<string, mixed> $field Normalised field.
	 * @return array{class: string, format?: string, kind?: string}
	 */
	private static function classify( array $field ): array {
		$type  = is_string( $field['type'] ?? null ) ? $field['type'] : '';
		$class = FieldClass::metabox( $type );

		if ( ( 'post' === $type && ! empty( $field['parent'] ) ) || ( 'group' === $type && empty( $field['fields'] ) ) ) {
			return [ 'class' => FieldClass::IGNORE ];
		}

		if ( ! empty( $field['clone'] ) && 'group' !== $type
			&& ! in_array( $class['class'], [ FieldClass::IGNORE, FieldClass::PASSWORD ], true ) ) {
			return [ 'class' => FieldClass::STRUCTURE ];
		}

		return $class;
	}

	/**
	 * The descriptor of one field at one path.
	 *
	 * @param array<string, mixed>                                 $field      Normalised field.
	 * @param string                                               $key        The top-level meta key.
	 * @param array<int, int|string>                               $path       Path inside the blob ([] for the top-level field).
	 * @param array<int, string>                                   $id_path    Field ids, the top-level id first.
	 * @param array<int, string>                                   $labels     Label path.
	 * @param string                                               $storage    Storage attribute.
	 * @param array{class: string, format?: string, kind?: string} $classified Classification.
	 * @param bool                                                 $cloneable  Cloneable.
	 * @param bool                                                 $cam        One row per clone.
	 * @param array<int, string>                                   $patterns   Sensitive key patterns.
	 * @return FieldDescriptor
	 */
	private static function descriptor( array $field, string $key, array $path, array $id_path, array $labels, string $storage, array $classified, bool $cloneable, bool $cam, array $patterns ): FieldDescriptor {
		$type       = (string) $field['type'];
		$taxonomies = [];
		$post_types = [];

		foreach ( (array) ( $field['taxonomy'] ?? [] ) as $taxonomy ) {
			if ( is_string( $taxonomy ) && '' !== $taxonomy ) {
				$taxonomies[] = $taxonomy;
			}
		}

		foreach ( (array) ( $field['post_type'] ?? [] ) as $post_type ) {
			if ( is_string( $post_type ) && '' !== $post_type ) {
				$post_types[] = $post_type;
			}
		}

		$field_key = implode( '.', $id_path );

		return new FieldDescriptor(
			key: $key,
			template: $key,
			host: self::HOST,
			field_key: $field_key,
			original_key: $field_key,
			type: $type,
			class: $classified['class'],
			format: $classified['format'] ?? FieldClass::FORMAT_TEXT,
			kind: $classified['kind'] ?? '',
			multiple: ! empty( $field['multiple'] ),
			taxonomies: $taxonomies,
			post_types: $post_types,
			container: $path === [] ? '' : $key,
			mb_path: $path,
			storage: $storage,
			label_path: $labels,
			clone: $cloneable,
			clone_as_multiple: $cam,
			sensitive: \PerfLocale\Translation\PostTranslationManager::is_sensitive_meta_key( $key, $patterns )
		);
	}

	/**
	 * Where a field keeps its value: post meta, another core meta storage, or a custom table (any other storage object).
	 *
	 * @param array<string, mixed> $field Field settings.
	 * @return string
	 */
	private function storage_of( array $field ): string {
		if ( ( $this->post_meta_storage )( $field ) ) {
			return FieldClass::STORAGE_POSTMETA;
		}

		$storage = $field['storage'];

		if ( $storage instanceof \RWMB_Term_Storage ) {
			return FieldClass::STORAGE_TERMMETA;
		}

		if ( $storage instanceof \RWMB_User_Storage ) {
			return FieldClass::STORAGE_USERMETA;
		}

		if ( $storage instanceof \RWMB_Comment_Storage ) {
			return FieldClass::STORAGE_COMMENTMETA;
		}

		return FieldClass::STORAGE_CUSTOM_TABLE;
	}

	/**
	 * The translatable Meta Box keys of a post type: top-level post-meta text and group fields (is_seed_descriptor()).
	 *
	 * @param array<int, string> $keys      Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $keys;
		}

		$new_keys = self::type_map( $post_type )->keys( [ self::class, 'is_seed_descriptor' ] );

		if ( $new_keys === [] ) {
			return $keys;
		}

		return array_values( array_unique( array_merge( $keys, $new_keys ) ) );
	}

	/**
	 * The keys add_meta_keys() registers for a post type, as a set memoised per blog.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, bool>
	 */
	public function seed_key_set( string $post_type ): array {
		static $cache = [];

		$blog = get_current_blog_id();

		if ( isset( $cache[ $blog ][ $post_type ] ) ) {
			return $cache[ $blog ][ $post_type ];
		}

		$set = function_exists( 'rwmb_get_object_fields' )
			? array_fill_keys( self::type_map( $post_type )->keys( [ self::class, 'is_seed_descriptor' ] ), true )
			: [];

		if ( count( $cache[ $blog ] ?? [] ) >= 32 ) {
			unset( $cache[ $blog ] );
		}

		$cache[ $blog ][ $post_type ] = $set;

		return $set;
	}

	/**
	 * Top-level Meta Box password fields that store post meta, cloneable or not (a password is known by its type).
	 *
	 * @param array<int, string> $excluded  Meta keys that are not copied.
	 * @param int                $source_id Post the translation is copied from.
	 * @return array<int, string>
	 */
	public function exclude_password_fields( array $excluded, int $source_id ): array {
		$post_type = $source_id > 0 ? get_post_type( $source_id ) : false;

		if ( ! is_string( $post_type ) || ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $excluded;
		}

		$this->boot_definitions = true;

		try {
			$map = self::type_map( $post_type );
		} finally {
			$this->boot_definitions = false;
		}

		foreach ( $map->keys( static fn( FieldDescriptor $d ): bool => self::HOST === $d->host && FieldClass::PASSWORD === $d->class && $d->mb_path === [] && FieldClass::STORAGE_POSTMETA === $d->storage ) as $key ) {
			$excluded[] = $key;
		}

		return $excluded;
	}

	/**
	 * Machine-translatable Meta Box keys (mt_keys()), gated by the mt_meta_custom_fields setting.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @param int                $post_id   Source post ID (0 = type-level).
	 * @return array<int, string>
	 */
	public function add_mt_meta_keys( array $keys, string $post_type, int $post_id = 0 ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_custom_fields', false ) ) {
			return $keys;
		}

		$new_keys = $this->mt_keys( $post_type, $post_id );

		return $new_keys === [] ? $keys : array_values( array_unique( array_merge( $keys, $new_keys ) ) );
	}

	/**
	 * The machine-translatable keys of a post type: top-level, non-cloneable post-meta text, textarea and wysiwyg.
	 *
	 * Serialized types and groups are blobs; with clone_as_multiple the translated row-0 value would collapse onto every row.
	 * For a post, the key pass's map (another host's list builds it too); its top-level descriptors are the type's.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Source post ID (0 = type-level).
	 * @return array<int, string>
	 */
	public function mt_keys( string $post_type, int $post_id = 0 ): array {
		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return [];
		}

		return ( $post_id > 0 ? FieldMap::for_key_pass( $post_id, $post_type ) : self::type_map( $post_type ) )->keys(
			static fn( FieldDescriptor $d ): bool => self::is_mt_descriptor( $d, [ 'text', 'textarea', 'wysiwyg' ] )
		);
	}

	/**
	 * The type-level map of a post type, shared with the other addons in one filter apply; never the memo slot (it holds
	 * the save's or MT run's map).
	 *
	 * @param string $post_type Post type.
	 * @return FieldMap
	 */
	private static function type_map( string $post_type ): FieldMap {
		return FieldMap::for_type_pass( $post_type );
	}

	/**
	 * Whether a descriptor is a top-level, non-cloneable Meta Box post-meta text field of one of the types.
	 *
	 * @param FieldDescriptor    $d     Descriptor.
	 * @param array<int, string> $types Field types.
	 * @return bool
	 */
	private static function is_mt_descriptor( FieldDescriptor $d, array $types ): bool {
		return self::HOST === $d->host
			&& FieldClass::TEXT === $d->class
			&& $d->mb_path === []
			&& ! $d->clone
			&& FieldClass::STORAGE_POSTMETA === $d->storage
			&& in_array( $d->type, $types, true );
	}

	/**
	 * Route Meta Box text and textarea fields to the provider's text mode; only ever turns a key into text.
	 *
	 * @param string $format    Inherited format ('html' default).
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @return string 'text' for a text/textarea field, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		if ( 'html' !== $format ) {
			return $format;
		}

		$set = $this->plaintext_mt_key_set( $post_type );

		return isset( $set[ $key ] ) ? 'text' : $format;
	}

	/**
	 * Meta Box text and textarea (not wysiwyg) field names of a post type, from the type-level map, memoised per blog.
	 *
	 * @param string $post_type Source post type.
	 * @return array<string, bool> field name => true.
	 */
	private function plaintext_mt_key_set( string $post_type ): array {
		static $cache = [];

		$blog = get_current_blog_id();

		if ( isset( $cache[ $blog ][ $post_type ] ) ) {
			return $cache[ $blog ][ $post_type ];
		}

		$set = [];

		if ( function_exists( 'rwmb_get_object_fields' ) ) {
			foreach ( self::type_map( $post_type )->keys( static fn( FieldDescriptor $d ): bool => self::is_mt_descriptor( $d, [ 'text', 'textarea' ] ) ) as $name ) {
				$set[ $name ] = true;
			}
		}

		if ( count( $cache[ $blog ] ?? [] ) >= 32 ) {
			unset( $cache[ $blog ] );
		}

		$cache[ $blog ][ $post_type ] = $set;

		return $set;
	}

	/**
	 * `rwmb_after_save_field`: keep a field's old value as the request's snapshot, by its class.
	 *
	 * Only a post-meta structure, reference or value field on a translatable post records, so a post with hundreds of
	 * text fields spends none of the bound; the scope is released when the post's save ends.
	 *
	 * @param mixed $field     Field settings.
	 * @param mixed $old_value Value before the save.
	 * @param mixed $object_id Object ID.
	 * @return void
	 */
	public function snapshot_old_value( mixed $field, mixed $old_value, mixed $object_id ): void {
		$field = self::field_settings( $field );

		if ( null === $field || empty( $field['id'] ) || ! is_string( $field['id'] ) || ! is_numeric( $object_id ) || (int) $object_id <= 0
			|| ! ( $this->post_meta_storage )( $field ) || \PerfLocale\Translation\SyncMeta::is_page_view()
			|| ! in_array( self::classify( $field )['class'], [ FieldClass::STRUCTURE, FieldClass::REFERENCE, FieldClass::VALUE ], true )
			|| ! self::is_translatable_post( (int) $object_id ) ) {
			return;
		}

		FieldSnapshot::take( FieldSnapshot::scope_post( (int) $object_id ), $field['id'], $old_value );
	}

	/**
	 * Whether a post's type is one the settings translate.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function is_translatable_post( int $post_id ): bool {
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return false;
		}

		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		return method_exists( $settings, 'get_translatable_post_types' ) && in_array( $post_type, (array) $settings->get_translatable_post_types(), true );
	}

	/**
	 * A field's settings as the host passes them: the array keyed by setting name, or null for anything else.
	 *
	 * @param mixed $field Hook argument.
	 * @return array<string, mixed>|null
	 */
	private static function field_settings( mixed $field ): ?array {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$settings = [];

		foreach ( $field as $name => $value ) {
			if ( is_string( $name ) ) {
				$settings[ $name ] = $value;
			}
		}

		return $settings;
	}
}
