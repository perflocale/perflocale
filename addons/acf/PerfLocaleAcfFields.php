<?php
/**
 * PerfLocale ACF addon: the field map producer and the key lists read from it.
 *
 * Loaded only by PerfLocaleAcf on a save, a sync, machine translation, a job or an admin screen.
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
 * The ACF fields of a post as field-map descriptors, and the seed, sync and MT key lists built from that map.
 */
final class PerfLocaleAcfFields {

	/**
	 * The host attribute of this addon's descriptors.
	 */
	private const HOST = 'acf';

	/**
	 * Field types whose leaves are machine-translated.
	 *
	 * @var array<int, string>
	 */
	private const MT_TYPES = [ 'text', 'textarea', 'wysiwyg' ];

	/**
	 * The concrete MT map of the source post resolved last: [ "{blog}:{post}", [ meta key => 'text' | 'html' ] ].
	 *
	 * One entry only, so a bulk job stays memory-flat.
	 *
	 * @var array{0: string, 1: array<string, string>}|null
	 */
	private ?array $concrete_mt_memo = null;

	/**
	 * The value keys exclude_password_fields() held back last, for note_unresolved().
	 *
	 * @var array<int, string>
	 */
	private array $unresolved = [];

	/**
	 * `perflocale/fields/map`: the ACF fields of one post as descriptors.
	 *
	 * A post gets the groups its edit screen shows; a type-level map (id 0) the type's groups with row indices as 0.
	 * Row loops are clamped to the post's distinct meta-key count, so a corrupt count cannot spin.
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

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) || FieldMap::definitions_unavailable() ) {
			return $map;
		}

		$post_id = is_numeric( $object_id ) ? (int) $object_id : 0;
		$query   = [ 'post_type' => $subtype ];

		if ( $post_id > 0 ) {
			$query = [
				'post_id'   => $post_id,
				'post_type' => $subtype,
			];
			$terms = self::primed_post_terms( $post_id, $subtype );

			if ( null !== $terms ) {
				$query['post_terms'] = $terms;
			}
		}

		$read     = $post_id > 0 ? static fn( string $key ): mixed => get_post_meta( $post_id, $key, true ) : null;
		$budget   = $post_id > 0 ? count( (array) get_post_meta( $post_id ) ) : 0;
		$patterns = \PerfLocale\Translation\PostTranslationManager::sensitive_meta_patterns();

		foreach ( acf_get_field_groups( $query ) as $group ) {
			$fields = acf_get_fields( $group['key'] ?? '' );

			if ( is_array( $fields ) ) {
				self::walk( $fields, '', '', $read, $budget, $map, $patterns );
			}
		}

		return $map;
	}

	/**
	 * A post's term ids per taxonomy when every relationship cache holds them (FieldDrift primes a chunk's), else null.
	 * ACF's taxonomy location rules read `post_terms` instead of querying each post.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type.
	 * @return array<string, array<int, int>>|null
	 */
	private static function primed_post_terms( int $post_id, string $post_type ): ?array {
		$out = [];

		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$ids = wp_cache_get( $post_id, (string) $taxonomy . '_relationships' );

			if ( ! is_array( $ids ) ) {
				return null;
			}

			$out[ (string) $taxonomy ] = array_map( 'intval', $ids );
		}

		return [] === $out ? null : $out;
	}

	/**
	 * Whether a descriptor is a key the copy/seed list carries: an ACF text leaf or a repeater / flexible container.
	 *
	 * @param FieldDescriptor $d Descriptor.
	 * @return bool
	 */
	public static function is_seed_descriptor( FieldDescriptor $d ): bool {
		return self::HOST === $d->host && ( FieldClass::TEXT === $d->class || FieldClass::STRUCTURE === $d->class );
	}

	/**
	 * Classify every stored ACF key under $fields into the map, walking rows as ACF's load_value() does.
	 *
	 * A flexible row walks only its stored layout's sub-fields (the key holds no layout); without a reader every
	 * container gives row 0 and every layout. Rationale: docs-ai/11-code-rationale.md, "PerfLocaleAcf: concrete MT map".
	 *
	 * @param array<int|string, mixed>                             $fields   ACF field definitions as loaded.
	 * @param string                                               $prefix   Concrete meta-key prefix.
	 * @param string                                               $tprefix  Template meta-key prefix (row indices as 0).
	 * @param callable|null                                        $read     `callable(string $key): mixed`; null for a type-level map.
	 * @param int                                                  $budget   Row-loop ceiling.
	 * @param FieldMap                                             $map      Out: the descriptors.
	 * @param array<int, string>                                   $patterns Sensitive key patterns.
	 * @param array<int, array{0: string, 1: int, 2: string|null}> $chain    Rows enclosing $fields, outermost first.
	 * @param array<int, string>                                   $labels   Labels of the enclosing containers.
	 * @param array<int, string>                                   $clones   Keys of the enclosing clone containers.
	 * @return void
	 */
	private static function walk( array $fields, string $prefix, string $tprefix, ?callable $read, int $budget, FieldMap $map, array $patterns, array $chain = [], array $labels = [], array $clones = [] ): void {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = (string) ( $field['type'] ?? '' );
			$name = self::field_name( $field, $type );

			if ( '' === $name ) {
				continue;
			}

			$key      = $prefix . $name;
			$template = $tprefix . $name;
			$label    = is_string( $field['__label'] ?? null ) ? $field['__label'] : (string) ( $field['label'] ?? $name );
			$subs     = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : [];
			$path     = $labels;
			$spliced  = self::spliced_clone_label( $field, $clones );

			if ( null !== $spliced ) {
				$path[] = $spliced;
			}

			$own = array_merge( $path, [ $label ] );

			if ( 'group' === $type || 'clone' === $type ) {
				if ( $subs !== [] ) {
					$child  = 'group' === $type ? $key . '_' : self::clone_child_prefix( $field, $key, $prefix );
					$tchild = 'group' === $type ? $template . '_' : self::clone_child_prefix( $field, $template, $tprefix );
					$inner  = 'clone' === $type && is_string( $field['key'] ?? null ) ? array_merge( $clones, [ $field['key'] ] ) : $clones;
					self::walk( $subs, $child, $tchild, $read, $budget, $map, $patterns, $chain, $own, $inner );
				}
				continue;
			}

			$map->add( self::descriptor( $field, $key, $template, $type, $chain, $own, $patterns ) );

			if ( 'repeater' === $type && $subs !== [] ) {
				if ( null === $read ) {
					self::walk( $subs, $key . '_0_', $template . '_0_', null, $budget, $map, $patterns, array_merge( $chain, [ [ $key, 0, null ] ] ), $own, $clones );
					continue;
				}

				$count = $read( $key );
				$count = is_numeric( $count ) ? min( (int) $count, $budget ) : 0;

				for ( $i = 0; $i < $count; $i++ ) {
					self::walk( $subs, $key . '_' . $i . '_', $template . '_0_', $read, $budget, $map, $patterns, array_merge( $chain, [ [ $key, $i, null ] ] ), $own, $clones );
				}
			} elseif ( 'flexible_content' === $type && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$by_name = [];

				foreach ( $field['layouts'] as $layout ) {
					if ( is_array( $layout ) && isset( $layout['name'] ) && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$by_name[ (string) $layout['name'] ][] = $layout['sub_fields'];
					}
				}

				if ( null === $read ) {
					foreach ( $by_name as $layout_name => $lists ) {
						foreach ( $lists as $sub_fields ) {
							self::walk( $sub_fields, $key . '_0_', $template . '_0_', null, $budget, $map, $patterns, array_merge( $chain, [ [ $key, 0, (string) $layout_name ] ] ), $own, $clones );
						}
					}
					continue;
				}

				$rows = $read( $key );

				if ( ! is_array( $rows ) ) {
					continue;
				}

				foreach ( $rows as $i => $layout_name ) {
					if ( ! is_int( $i ) || ! is_string( $layout_name ) || ! isset( $by_name[ $layout_name ] ) ) {
						continue;
					}

					foreach ( $by_name[ $layout_name ] as $sub_fields ) {
						self::walk( $sub_fields, $key . '_' . $i . '_', $template . '_0_', $read, $budget, $map, $patterns, array_merge( $chain, [ [ $key, $i, $layout_name ] ] ), $own, $clones );
					}
				}
			}
		}
	}

	/**
	 * The name a loaded field is keyed by: `name`, else for an `ignore`-class field the `_name` backup.
	 *
	 * Message, tab, accordion and separator arrive with `name` emptied by their load_field().
	 *
	 * @param array<mixed> $field Loaded field.
	 * @param string       $type  Field type.
	 * @return string
	 */
	private static function field_name( array $field, string $type ): string {
		$name = $field['name'] ?? null;

		if ( is_string( $name ) && '' !== $name ) {
			return $name;
		}

		if ( FieldClass::IGNORE !== FieldClass::acf( $type )['class'] ) {
			return '';
		}

		$backup = $field['_name'] ?? null;

		return is_string( $backup ) ? $backup : '';
	}

	/**
	 * The label of the seamless clone a spliced field came from, read from ACF's field store; null when none.
	 *
	 * ACF splices a seamless clone's sub-fields (key `{clone key}_{original key}`) into the parent list, so the clone
	 * itself never reaches the walk.
	 *
	 * @param array<mixed>       $field  Loaded field.
	 * @param array<int, string> $clones Keys of the enclosing clone containers.
	 * @return string|null
	 */
	private static function spliced_clone_label( array $field, array $clones ): ?string {
		$key  = $field['key'] ?? null;
		$orig = $field['__key'] ?? null;

		if ( ! is_string( $key ) || ! is_string( $orig ) || '' === $orig || $key === $orig || ! str_ends_with( $key, '_' . $orig ) ) {
			return null;
		}

		$clone_key = substr( $key, 0, -strlen( $orig ) - 1 );

		if ( '' === $clone_key || in_array( $clone_key, $clones, true ) || ! function_exists( 'acf_get_store' ) ) {
			return null;
		}

		$store = acf_get_store( 'fields' );

		if ( ! is_object( $store ) || ! method_exists( $store, 'has' ) || ! method_exists( $store, 'get' ) || ! $store->has( $clone_key ) ) {
			return null;
		}

		$clone = $store->get( $clone_key );
		$label = is_array( $clone ) ? ( $clone['label'] ?? null ) : null;

		return is_string( $label ) && '' !== $label ? $label : null;
	}

	/**
	 * The descriptor of one loaded ACF field at one concrete key.
	 *
	 * @param array<string, mixed>                                 $field    Loaded field.
	 * @param string                                               $key      Concrete key.
	 * @param string                                               $template Template key.
	 * @param string                                               $type     Field type.
	 * @param array<int, array{0: string, 1: int, 2: string|null}> $chain    Enclosing rows.
	 * @param array<int, string>                                   $labels   Label path.
	 * @param array<int, string>                                   $patterns Sensitive key patterns.
	 * @return FieldDescriptor
	 */
	private static function descriptor( array $field, string $key, string $template, string $type, array $chain, array $labels, array $patterns ): FieldDescriptor {
		$class      = FieldClass::acf( $type );
		$field_key  = is_string( $field['key'] ?? null ) ? $field['key'] : '';
		$original   = is_string( $field['__key'] ?? null ) ? $field['__key'] : $field_key;
		$taxonomies = [];
		$post_types = [];

		if ( 'taxonomy' === $type && is_string( $field['taxonomy'] ?? null ) && '' !== $field['taxonomy'] ) {
			$taxonomies[] = $field['taxonomy'];
		}

		foreach ( (array) ( $field['post_type'] ?? [] ) as $post_type ) {
			if ( is_string( $post_type ) && '' !== $post_type ) {
				$post_types[] = $post_type;
			}
		}

		$multiple = match ( $type ) {
			'relationship', 'gallery', 'checkbox' => true,
			'taxonomy'                            => in_array( $field['field_type'] ?? '', [ 'checkbox', 'multi_select' ], true ),
			default                               => ! empty( $field['multiple'] ),
		};

		return new FieldDescriptor(
			key: $key,
			template: $template,
			host: self::HOST,
			field_key: $field_key,
			original_key: $original,
			type: $type,
			class: $class['class'],
			format: $class['format'] ?? FieldClass::FORMAT_TEXT,
			kind: $class['kind'] ?? '',
			multiple: $multiple,
			taxonomies: $taxonomies,
			post_types: $post_types,
			chain: $chain,
			container: $chain === [] ? '' : $chain[0][0],
			label_path: $labels,
			sync_terms: 'taxonomy' === $type && ! empty( $field['save_terms'] ),
			sensitive: \PerfLocale\Translation\PostTranslationManager::is_sensitive_meta_key( $key, $patterns )
		);
	}

	/**
	 * `acf/pre_update_value` at priority 1: record the value a field holds before this save writes it. Never short-circuits.
	 *
	 * By type, never by the map: an outermost container records its whole subtree (its hook fires before its rows
	 * are written), a scalar reference or value records itself, anything else nothing; nothing on a page view.
	 *
	 * @param mixed $check   Short-circuit value, returned unchanged.
	 * @param mixed $value   Value about to be saved.
	 * @param mixed $post_id ACF post ID (123, "post_123", "options").
	 * @param mixed $field   Field settings.
	 * @return mixed
	 */
	public function snapshot_before_update( mixed $check = null, mixed $value = null, mixed $post_id = 0, mixed $field = null ): mixed {
		if ( ! is_array( $field ) || ! is_string( $field['name'] ?? null ) || '' === $field['name'] || \PerfLocale\Translation\SyncMeta::is_page_view() ) {
			return $check;
		}

		$decoded = function_exists( 'acf_decode_post_id' ) ? acf_decode_post_id( $post_id ) : [
			'type' => is_numeric( $post_id ) ? 'post' : '',
			'id'   => $post_id,
		];

		$id = ( is_array( $decoded ) && 'post' === ( $decoded['type'] ?? '' ) && is_numeric( $decoded['id'] ?? null ) ) ? (int) $decoded['id'] : 0;

		// No consumer runs for a type that is not translatable, so its entries would only spend the bound.
		if ( $id <= 0 || ! self::is_translatable_post( $id ) ) {
			return $check;
		}

		$type  = (string) ( $field['type'] ?? '' );
		$name  = $field['name'];
		$scope = FieldSnapshot::scope_post( $id );

		if ( in_array( $type, [ 'repeater', 'flexible_content', 'group', 'clone' ], true ) ) {
			if ( FieldSnapshot::covers( $scope, $name ) ) {
				return $check;
			}

			// The field's name is already concrete at every depth, so the walk starts with no prefix.
			$collector = new FieldMap( FieldMap::KIND_POST, $id );
			$read      = static fn( string $key ): mixed => get_post_meta( $id, $key, true );

			self::walk( [ $field ], '', '', $read, count( (array) get_post_meta( $id ) ), $collector, [] );

			$subtree = [];

			foreach ( $collector->keys() as $key ) {
				$subtree[ $key ] = $read( $key );
			}

			// covers() answers for these keys, so a child that fires next is not taken a second time.
			FieldSnapshot::take( $scope, $name, $subtree, array_keys( $subtree ) );

			return $check;
		}

		$class = FieldClass::acf( $type )['class'];

		if ( ( FieldClass::REFERENCE === $class || FieldClass::VALUE === $class ) && ! FieldSnapshot::covers( $scope, $name ) ) {
			FieldSnapshot::take( $scope, $name, get_post_meta( $id, $name, true ) );
		}

		return $check;
	}

	/**
	 * Keep ACF values out of a new translation unless their field resolves to a type other than password.
	 *
	 * Fail closed: a deleted or unloaded group may have held a password. Each value row `{name}` has a `_{name}` row
	 * with the field key; an unresolved value leaves both out and is noted on the new translation (note_unresolved()).
	 *
	 * @param array<int, string> $excluded  Meta keys that are not copied.
	 * @param int                $source_id Post the translation is copied from.
	 * @return array<int, string>
	 */
	public function exclude_password_fields( array $excluded, int $source_id ): array {
		$meta = get_post_meta( $source_id );

		if ( ! is_array( $meta ) ) {
			return $excluded;
		}

		$types            = [];
		$this->unresolved = [];

		foreach ( array_keys( $meta ) as $key ) {
			$key       = (string) $key;
			$reference = $meta[ '_' . $key ][0] ?? null;

			if ( ! is_string( $reference ) || ! str_starts_with( $reference, 'field_' ) ) {
				continue;
			}

			$types[ $reference ] ??= self::reference_type( $reference );

			if ( 'password' === $types[ $reference ] ) {
				$excluded[] = $key;
			} elseif ( '' === $types[ $reference ] ) {
				$excluded[]         = $key;
				$excluded[]         = '_' . $key;
				$this->unresolved[] = $key;
			}
		}

		if ( [] !== $this->unresolved ) {
			add_action( 'perflocale/translation/created', [ $this, 'note_unresolved' ], 10, 2 );
		}

		return $excluded;
	}

	/**
	 * The type of an ACF field key, '' when it does not resolve here; a clone's `{clone}_{field}` key by its field.
	 *
	 * On a switched blog a local (PHP or JSON) definition is the boot blog's, so it does not resolve.
	 *
	 * @param string $reference Field key.
	 * @return string
	 */
	private static function reference_type( string $reference ): string {
		if ( function_exists( 'acf_is_local_field' ) && acf_is_local_field( $reference ) && FieldMap::definitions_unavailable() ) {
			return '';
		}

		$field = acf_get_field( $reference );

		if ( is_array( $field ) && is_string( $field['type'] ?? null ) && '' !== $field['type'] ) {
			return $field['type'];
		}

		$at = strrpos( $reference, '_field_' );

		return false !== $at && 'clone' === self::reference_type( substr( $reference, 0, $at ) ) ? self::reference_type( substr( $reference, $at + 1 ) ) : '';
	}

	/**
	 * `perflocale/translation/created`: record on the new translation the ACF values exclude_password_fields() left out.
	 *
	 * @param mixed $new_id New translation's ID.
	 * @param mixed $type   Object type.
	 * @return void
	 */
	public function note_unresolved( mixed $new_id = 0, mixed $type = '' ): void {
		// The copy creates the post's term translations first: those fire this hook too.
		if ( 'post' !== $type ) {
			return;
		}

		remove_action( 'perflocale/translation/created', [ $this, 'note_unresolved' ], 10 );

		$keys             = $this->unresolved;
		$this->unresolved = [];

		if ( ! is_numeric( $new_id ) || (int) $new_id <= 0 || [] === $keys ) {
			return;
		}

		\PerfLocale\Translation\PostTranslationWrites::note_copy_step(
			(int) $new_id,
			[
				'step'    => 'unresolved_fields',
				'keys'    => $keys,
				/* translators: %s: comma-separated meta keys. */
				'message' => sprintf( __( 'Custom fields not copied: their ACF field definition is not loaded (%s).', 'perflocale' ), implode( ', ', $keys ) ),
			]
		);
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
	 * The translatable ACF keys of a post type: text leaves and repeater / flexible containers, row indices as 0.
	 *
	 * @param array<int, string> $keys      Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_acf_meta_keys( array $keys, string $post_type ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $keys;
		}

		$acf_keys = self::type_map( $post_type )->keys( [ self::class, 'is_seed_descriptor' ] );

		if ( $acf_keys === [] ) {
			return $keys;
		}

		return array_values( array_unique( array_merge( $keys, $acf_keys ) ) );
	}

	/**
	 * Expand repeater and flexible-content sub-field keys to every row the source post holds.
	 *
	 * An ACF password row leaves the list unless the site chose to sync it (site_chose_key()), so the seed never copies
	 * a credential; every other input key is returned.
	 *
	 * @param array<int, string> $fields    Meta keys queued for sync.
	 * @param int                $source_id Source post being synced from.
	 * @return array<int, string>
	 */
	public function expand_repeating_rows( array $fields, int $source_id ): array {
		if ( $source_id <= 0 || empty( $fields ) ) {
			return $fields;
		}

		$out = [];

		foreach ( $fields as $key ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = true;
			}
		}

		$post_type = get_post_type( $source_id );
		$templates = [];
		$secrets   = [];
		$input     = $out;
		$chosen    = null;

		if ( is_string( $post_type ) && function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			$map = FieldMap::for_post( $source_id, $post_type );

			foreach ( $map->descriptors() as $d ) {
				if ( self::HOST !== $d->host ) {
					continue;
				}

				$templates[ $d->template ] = true;

				if ( FieldClass::PASSWORD === $d->class ) {
					$chosen ??= self::site_sync_choice( $post_type );

					if ( ! self::site_chose_key( isset( $input[ $d->key ] ) ? $d->key : $d->template, $chosen ) ) {
						$secrets[ $d->key ] = true;
						continue;
					}
				}

				// A row past 0 only when the post holds it: a sub-field added after the rows were saved is row 0 alone.
				if ( isset( $out[ $d->template ] ) && ( $d->key === $d->template || metadata_exists( 'post', $source_id, $d->key ) ) ) {
					$out[ $d->key ] = true;
				}
			}
		}

		foreach ( self::expand_foreign_rows( array_keys( $out ), $templates, $source_id ) as $key ) {
			$out[ $key ] = true;
		}

		return array_keys( array_diff_key( $out, $secrets ) );
	}

	/**
	 * The two lists ContentSync::run_sync() splits synced keys by: the mirror keys and the keys add-ons contribute.
	 *
	 * @param string $post_type Source post type.
	 * @return array{mirror: array<string, true>, addon: array<string, true>}
	 */
	private static function site_sync_choice( string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', (array) $settings->get( 'sync_fields', [] ), $post_type );

		return [
			'mirror' => array_fill_keys( array_filter( $mirror, 'is_string' ), true ),
			'addon'  => array_fill_keys( $settings->get_translatable_meta_keys( $post_type ), true ),
		];
	}

	/**
	 * Whether the site chose to sync $key, as ContentSync::run_sync() decides: a mirror key or a key no add-on contributes.
	 *
	 * @param string                                                         $key    Meta key.
	 * @param array{mirror: array<string, true>, addon: array<string, true>} $chosen site_sync_choice().
	 * @return bool
	 */
	private static function site_chose_key( string $key, array $chosen ): bool {
		return isset( $chosen['mirror'][ $key ] ) || ! isset( $chosen['addon'][ $key ] );
	}

	/**
	 * Expand another producer's row-0 templates against the post's container meta, one "_0_" level at a time.
	 *
	 * The (key|offset) dedup lets row 0, whose key equals the template, still advance to the inner level.
	 *
	 * @param array<int, string>  $keys      Meta keys queued for sync.
	 * @param array<string, true> $templates Templates the ACF map owns (left to it).
	 * @param int                 $source_id Source post.
	 * @return array<int, string>
	 */
	private static function expand_foreign_rows( array $keys, array $templates, int $source_id ): array {
		$out   = [];
		$seen  = [];
		$queue = [];

		foreach ( $keys as $key ) {
			if ( ! isset( $templates[ $key ] ) && str_contains( $key, '_0_' ) ) {
				$queue[] = [ $key, 0 ];
			}
		}

		while ( $queue !== [] ) {
			[ $key, $off ] = array_shift( $queue );

			$sig = $key . '|' . $off;

			if ( isset( $seen[ $sig ] ) ) {
				continue;
			}

			$seen[ $sig ] = true;
			$pos          = strpos( $key, '_0_', $off );

			if ( false === $pos ) {
				continue;
			}

			$prefix    = substr( $key, 0, $pos );
			$suffix    = substr( $key, $pos + 3 );
			$container = get_post_meta( $source_id, $prefix, true );
			$count     = is_array( $container ) ? count( $container ) : (int) $container;

			for ( $i = 0; $i < max( 1, $count ); $i++ ) {
				$candidate = $prefix . '_' . $i . '_' . $suffix;
				$next_off  = strlen( $prefix ) + 1 + strlen( (string) $i ) + 1;

				if ( false === strpos( $candidate, '_0_', $next_off ) ) {
					if ( 0 === $i || metadata_exists( 'post', $source_id, $candidate ) ) {
						$out[ $candidate ] = true;
					}
				} else {
					$queue[] = [ $candidate, $next_off ];
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * Machine-translatable ACF meta keys: leaf text, textarea and wysiwyg fields only (mt_keys()).
	 *
	 * @param array<int, string> $keys           Meta keys.
	 * @param string             $post_type      Post type.
	 * @param int                $post_id        Source post ID (0 = type-level).
	 * @param int                $translation_id Translation post ID (0 = unknown).
	 * @return array<int, string>
	 */
	public function add_mt_meta_keys( array $keys, string $post_type, int $post_id = 0, int $translation_id = 0 ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_custom_fields', false ) ) {
			return $keys;
		}

		$acf_keys = $this->mt_keys( $post_type, $post_id, $translation_id );

		if ( $acf_keys === [] ) {
			return $keys;
		}

		return array_values( array_unique( array_merge( $keys, $acf_keys ) ) );
	}

	/**
	 * The machine-translatable keys of a post type or of one source post.
	 *
	 * Narrower than add_acf_meta_keys(): url/email/link/oembed values are never machine translated, containers are structural.
	 *
	 * @param string $post_type      Post type.
	 * @param int    $post_id        Source post ID (0 = type-level).
	 * @param int    $translation_id Translation post ID (0 = unknown).
	 * @return array<int, string>
	 */
	public function mt_keys( string $post_type, int $post_id = 0, int $translation_id = 0 ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}

		if ( $post_id > 0 ) {
			return array_map( 'strval', array_keys( $this->concrete_mt_map( $post_type, $post_id, $translation_id ) ) );
		}

		return self::type_map( $post_type )->keys( static fn( FieldDescriptor $d ): bool => self::is_mt_descriptor( $d, self::MT_TYPES ) );
	}

	/**
	 * Whether a descriptor is an ACF text leaf of one of the types.
	 *
	 * @param FieldDescriptor    $d     Descriptor.
	 * @param array<int, string> $types Field types.
	 * @return bool
	 */
	private static function is_mt_descriptor( FieldDescriptor $d, array $types ): bool {
		return self::HOST === $d->host && FieldClass::TEXT === $d->class && in_array( $d->type, $types, true );
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
	 * Machine-translatable ACF leaves of one source post, with their format, from the post's map.
	 *
	 * A key the map classifies twice differently is left out.
	 *
	 * @param string $post_type Source post type.
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Translation post ID; 0 skips the row-alignment check.
	 * @return array<string, string> meta key => 'text' | 'html'.
	 */
	private function concrete_mt_map( string $post_type, int $source_id, int $target_id = 0 ): array {
		$fields = FieldMap::for_key_pass( $source_id, $post_type );
		$map    = $fields->text_leaves( self::MT_TYPES, self::HOST );

		$this->concrete_mt_memo = [ get_current_blog_id() . ':' . $source_id, $map ];

		if ( $target_id > 0 && $target_id !== $source_id ) {
			$map = $this->aligned_rows_only( $map, $fields->chains(), $source_id, $target_id );
		}

		return $map;
	}

	/**
	 * Drop every key whose row does not line up with the translation.
	 *
	 * ACF rows have no stable identity, so a reordered target row must not receive another row's text.
	 * See docs-ai/11-code-rationale.md, "PerfLocaleAcf: row alignment".
	 *
	 * @param array<string, string>                                               $map       key => 'text' | 'html'.
	 * @param array<string, array<int, array{0: string, 1: int, 2: string|null}>> $chains    key => enclosing rows [container key, index, layout|null], outermost first; every classified key, not only those in $map.
	 * @param int                                                                 $source_id Source post ID.
	 * @param int                                                                 $target_id Translation post ID.
	 * @return array<string, string>
	 */
	private function aligned_rows_only( array $map, array $chains, int $source_id, int $target_id ): array {
		$row_leaves = [];
		$enumerated = [];

		// A key is evidence for every row that encloses it, so a moved outer
		// row is recognised by the leaves of the rows nested in it.
		foreach ( $chains as $key => $chain ) {
			foreach ( $chain as $row ) {
				$row_leaves[ $row[0] . '|' . $row[1] ][] = (string) $key;
				$enumerated[ $row[0] ]                   = max( $enumerated[ $row[0] ] ?? 0, $row[1] + 1 );
			}
		}

		$aligned = [];
		$by_leaf = [];

		foreach ( $map as $key => $class ) {
			foreach ( $chains[ (string) $key ] ?? [] as $row ) {
				list( $container, $index, $layout ) = $row;

				$row_id = $container . '|' . $index;

				if ( ! isset( $aligned[ $row_id ] ) ) {
					$aligned[ $row_id ] = $this->row_aligned( $container, $index, $layout, $row_leaves[ $row_id ] ?? [], $source_id, $target_id, $by_leaf, $enumerated[ $container ] ?? 0 );
				}

				if ( ! $aligned[ $row_id ] ) {
					unset( $map[ $key ] );
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Whether one row of the translation is still the source's row at that
	 * index; see aligned_rows_only().
	 *
	 * @param string                             $container Container meta key.
	 * @param int                                $index     Row index.
	 * @param string|null                        $layout    Source layout (flexible content), or null (repeater).
	 * @param array<int, string>                 $leaves    Keys stored in this row or in rows nested in it.
	 * @param int                                $source_id Source post ID.
	 * @param int                                $target_id Translation post ID.
	 * @param array<string, array<string, true>> $by_leaf   Memo: "container|sub-key" => serialized source values of that sub-field.
	 * @param int                                $bound     Rows of the container the field map enumerated: caps a corrupt stored count.
	 * @return bool
	 */
	private function row_aligned( string $container, int $index, ?string $layout, array $leaves, int $source_id, int $target_id, array &$by_leaf, int $bound ): bool {
		$stored = get_post_meta( $target_id, $container, true );

		// A flexible row of the same layout may still be another row (a reorder): it takes the leaf evidence too.
		if ( null !== $layout ) {
			if ( ! is_array( $stored ) || ! isset( $stored[ $index ] ) || $stored[ $index ] !== $layout ) {
				return false;
			}
		} elseif ( ! is_numeric( $stored ) || $index >= (int) $stored ) {
			return false;
		}

		$head = $container . '_' . $index . '_';

		foreach ( $leaves as $leaf ) {
			$target_value = get_post_meta( $target_id, $leaf, true );

			if ( '' === $target_value || ! str_starts_with( $leaf, $head ) || get_post_meta( $source_id, $leaf, true ) === $target_value ) {
				continue;
			}

			$sub  = substr( $leaf, strlen( $head ) );
			$memo = $container . '|' . $sub;

			if ( ! isset( $by_leaf[ $memo ] ) ) {
				$by_leaf[ $memo ] = [];
				$rows             = get_post_meta( $source_id, $container, true );
				$rows             = min( $bound, is_array( $rows ) ? count( $rows ) : ( is_numeric( $rows ) ? (int) $rows : 0 ) );

				for ( $j = 0; $j < $rows; $j++ ) {
					$value = get_post_meta( $source_id, $container . '_' . $j . '_' . $sub, true );

					if ( '' !== $value ) {
						$by_leaf[ $memo ][ maybe_serialize( $value ) ] = true;
					}
				}
			}

			// The source row at this index holds a different value, so any
			// source row holding the translation's value is another row.
			if ( isset( $by_leaf[ $memo ][ maybe_serialize( $target_value ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Declare the MT destination format of an ACF meta key: 'text' for text and textarea fields.
	 *
	 * Only ever turns a key into text, never back, so a wysiwyg field is never sent as plain text.
	 *
	 * @param string $format    Inherited format ('html' by default).
	 * @param string $key       Meta key (repeater rows carry numeric indices).
	 * @param string $post_type Source post type.
	 * @param mixed  $source_id Source post ID (0 from 3-argument callers).
	 * @return string 'text' when the key is an ACF text/textarea field, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type, mixed $source_id = 0 ): string {
		if ( 'html' !== $format ) {
			return $format;
		}

		$source_id = is_numeric( $source_id ) ? (int) $source_id : 0;

		if ( $source_id > 0
			&& null !== $this->concrete_mt_memo
			&& get_current_blog_id() . ':' . $source_id === $this->concrete_mt_memo[0]
			&& isset( $this->concrete_mt_memo[1][ $key ] )
		) {
			return 'text' === $this->concrete_mt_memo[1][ $key ] ? 'text' : $format;
		}

		$set = $this->plaintext_mt_key_set( $post_type );

		if ( $set === [] ) {
			return $format;
		}

		// Normalise row indices to the row-0 template form (slides_3_title → slides_0_title).
		$template = (string) preg_replace( '/_\d+_/', '_0_', $key );

		return ( isset( $set[ $key ] ) || isset( $set[ $template ] ) ) ? 'text' : $format;
	}

	/**
	 * ACF text and textarea (not wysiwyg) meta-key templates of a post type, from the type-level map, memoised per blog.
	 *
	 * @param string $post_type Source post type.
	 * @return array<string, bool> template meta key => true.
	 */
	private function plaintext_mt_key_set( string $post_type ): array {
		static $cache = [];

		$blog = get_current_blog_id();

		if ( isset( $cache[ $blog ][ $post_type ] ) ) {
			return $cache[ $blog ][ $post_type ];
		}

		$set = [];

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( self::type_map( $post_type )->keys( static fn( FieldDescriptor $d ): bool => self::is_mt_descriptor( $d, [ 'text', 'textarea' ] ) ) as $k ) {
				$set[ $k ] = true;
			}
		}

		if ( count( $cache[ $blog ] ?? [] ) >= 32 ) {
			unset( $cache[ $blog ] );
		}

		$cache[ $blog ][ $post_type ] = $set;

		return $set;
	}

	/**
	 * Meta-key prefix of a Clone field's children: the clone's own path minus its `_name`, as ACF Pro stores them.
	 *
	 * ACF already writes "Prefix Field Names" into each loaded child's name, so that setting is not applied again here.
	 *
	 * @param array<string, mixed> $field    Loaded ACF clone field.
	 * @param string               $meta_key The clone's own meta path ($prefix . name).
	 * @param string               $prefix   The clone's container path.
	 * @return string
	 */
	private static function clone_child_prefix( array $field, string $meta_key, string $prefix ): string {
		$own = isset( $field['_name'] ) && is_string( $field['_name'] ) ? $field['_name'] : '';

		if ( '' === $own || ! str_ends_with( $meta_key, $own ) ) {
			return $prefix;
		}

		return substr( $meta_key, 0, -strlen( $own ) );
	}
}
