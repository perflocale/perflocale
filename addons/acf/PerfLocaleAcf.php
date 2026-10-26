<?php
/**
 * PerfLocale ACF addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Custom Fields integration: field sync, machine translation and reference fields.
 */
final class PerfLocaleAcf implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Field types that contain translatable text.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_TYPES = [
		'text',
		'textarea',
		'wysiwyg',
		'url',
		'email',
		'link',
		'oembed',
	];

	/**
	 * Translated posts or terms loaded per statement when priming reference targets.
	 *
	 * @var int
	 */
	private const TARGET_PRIME_CHUNK = 100;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'acf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Advanced Custom Fields';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'advanced-custom-fields/acf.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return class_exists( 'ACF' ) || function_exists( 'acf_get_field_groups' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_acf_meta_keys' ], 10, 2 );

		// The post-type key list knows only row 0: expand rows per source post at sync time.
		add_filter( 'perflocale/sync_fields/for_post', [ $this, 'expand_repeating_rows' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 4 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 4 );

		// Front-end reference fields point to translated posts and terms.
		add_filter( 'acf/format_value/type=relationship', [ $this, 'translate_relationship' ], 20, 3 );
		add_filter( 'acf/format_value/type=post_object', [ $this, 'translate_post_object' ], 20, 3 );

		add_filter( 'acf/format_value/type=taxonomy', [ $this, 'translate_taxonomy' ], 20, 3 );

		// ACF memoises values per request: MT writes meta behind its back and an imposed language changes formatted references.
		add_action( 'perflocale/mt/meta_translated', [ $this, 'forget_post_values' ], 10, 1 );
		add_action( 'perflocale/language/overridden', [ $this, 'forget_formatted_values' ], 10, 0 );

		// Last priority, so the value judged is the one ACF stores.
		add_filter( 'acf/update_value', [ $this, 'record_clear_on_update' ], PHP_INT_MAX, 3 );

		// Front-end ACF form after its nonce check; the edit screen is marked by ContentSync.
		add_filter( 'acf/pre_save_post', [ $this, 'open_form_edit' ], PHP_INT_MAX, 1 );

		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_password_fields' ], 10, 2 );
	}

	/**
	 * `perflocale/translation/excluded_meta_keys`: keep the source's ACF password field values out of a new translation.
	 *
	 * A password is known by its field type, not its name: each value row `{name}` has a `_{name}` row holding the field key.
	 *
	 * @param array<int, string> $excluded  Meta keys that are not copied.
	 * @param int                $source_id Post the translation is copied from.
	 * @return array<int, string>
	 */
	public function exclude_password_fields( array $excluded, int $source_id ): array {
		if ( $source_id <= 0 || ! function_exists( 'acf_get_field' ) ) {
			return $excluded;
		}

		$meta = get_post_meta( $source_id );

		if ( ! is_array( $meta ) ) {
			return $excluded;
		}

		$types = [];

		foreach ( array_keys( $meta ) as $key ) {
			$reference = $meta[ '_' . $key ][0] ?? null;

			if ( ! is_string( $reference ) || ! str_starts_with( $reference, 'field_' ) ) {
				continue;
			}

			if ( ! isset( $types[ $reference ] ) ) {
				$field               = acf_get_field( $reference );
				$types[ $reference ] = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
			}

			if ( 'password' === $types[ $reference ] ) {
				$excluded[] = (string) $key;
			}
		}

		return $excluded;
	}

	/**
	 * `acf/pre_save_post`: fired by ACF's front-end form after its nonce
	 * check, with the post the form is about to save.
	 *
	 * @param mixed $post_id ACF post ID, returned unchanged.
	 * @return mixed
	 */
	public function open_form_edit( mixed $post_id = 0 ): mixed {
		$decoded = function_exists( 'acf_decode_post_id' ) ? acf_decode_post_id( $post_id ) : [
			'type' => is_numeric( $post_id ) ? 'post' : '',
			'id'   => $post_id,
		];

		if ( is_array( $decoded ) && 'post' === ( $decoded['type'] ?? '' ) ) {
			\PerfLocale\Translation\ContentSync::open_person_edit( $decoded['id'] ?? 0 );
		}

		return $post_id;
	}

	/**
	 * `acf/update_value`: record a text field a person empties in the post's seed-cleared marker.
	 *
	 * The marker is forgotten once the field holds a value again; nested sub-fields arrive with their full meta key as the name.
	 *
	 * @param mixed $value   Value about to be saved, returned unchanged.
	 * @param mixed $post_id ACF post ID (123, "post_123", "term_5", "option").
	 * @param mixed $field   Field settings.
	 * @return mixed
	 */
	public function record_clear_on_update( mixed $value = null, mixed $post_id = 0, mixed $field = null ): mixed {
		if ( ! is_array( $field ) || ! in_array( $field['type'] ?? '', self::TEXT_TYPES, true )
			|| ! is_string( $field['name'] ?? null ) || '' === $field['name'] ) {
			return $value;
		}

		$decoded = function_exists( 'acf_decode_post_id' ) ? acf_decode_post_id( $post_id ) : [
			'type' => is_numeric( $post_id ) ? 'post' : '',
			'id'   => $post_id,
		];

		$id = ( 'post' === ( $decoded['type'] ?? '' ) && is_numeric( $decoded['id'] ?? null ) ) ? (int) $decoded['id'] : 0;

		if ( $id <= 0 || \PerfLocale\Translation\PostTranslationManager::is_creating() || \PerfLocale\Translation\SyncMeta::is_page_view() ) {
			return $value;
		}

		$has = ! self::is_empty_value( $value );
		$had = false;

		foreach ( (array) get_post_meta( $id, $field['name'], false ) as $row ) {
			if ( ! self::is_empty_value( $row ) ) {
				$had = true;
				break;
			}
		}

		if ( $had === $has ) {
			return $value;
		}

		$post_type = get_post_type( $id );
		$settings  = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( $has || in_array( $post_type, $settings->get_translatable_post_types(), true ) ) {
			\PerfLocale\Translation\ContentSync::record_seed_clear( $id, $field['name'], ! $has );
		}

		return $value;
	}

	/**
	 * Whether an ACF value counts as empty: no value, '' or [].
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty_value( mixed $value ): bool {
		return null === $value || false === $value || '' === $value || [] === $value;
	}

	/**
	 * Drop every entry ACF's value store holds for one post.
	 *
	 * Keys are "{id}:{name}[:formatted|:escaped]" or "post_{id}:...". A parent field caches its
	 * children's values too, so evicting only the written leaf keys would leave stale values.
	 *
	 * @param mixed $post_id Post whose values changed.
	 * @return void
	 */
	public function forget_post_values( mixed $post_id = 0 ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;
		$store   = $this->acf_values_store();

		if ( $post_id <= 0 || null === $store ) {
			return;
		}

		$plain  = $post_id . ':';
		$prefix = 'post_' . $post_id . ':';

		foreach ( array_keys( (array) $store->get_data() ) as $name ) {
			$name = (string) $name;

			if ( str_starts_with( $name, $plain ) || str_starts_with( $name, $prefix ) ) {
				$store->remove( $name );
			}
		}
	}

	/**
	 * Drop ACF's formatted and escaped values when the language changes mid-request.
	 *
	 * Raw loaded values do not depend on the language and stay.
	 *
	 * @return void
	 */
	public function forget_formatted_values(): void {
		$store = $this->acf_values_store();

		if ( null === $store ) {
			return;
		}

		foreach ( array_keys( (array) $store->get_data() ) as $name ) {
			$name = (string) $name;

			if ( str_ends_with( $name, ':formatted' ) || str_ends_with( $name, ':escaped' ) ) {
				$store->remove( $name );
			}
		}
	}

	/**
	 * ACF's in-request value store, when this ACF build has one.
	 *
	 * @return \ACF_Data|null
	 */
	private function acf_values_store(): ?\ACF_Data {
		if ( ! function_exists( 'acf_get_store' ) || ! class_exists( 'ACF_Data' ) ) {
			return null;
		}

		$store = acf_get_store( 'values' );

		return $store instanceof \ACF_Data ? $store : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		// No settings: field detection runs on every translatable post type.
		return [];
	}

	/**
	 * Detect all translatable ACF fields for a post type, nested ones included.
	 *
	 * @param array<int, string> $keys Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_acf_meta_keys( array $keys, string $post_type ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $keys;
		}

		$groups = acf_get_field_groups( [ 'post_type' => $post_type ] );

		if ( empty( $groups ) ) {
			return $keys;
		}

		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );

			if ( ! is_array( $fields ) ) {
				continue;
			}

			$keys = array_merge( $keys, $this->collect_translatable_fields( $fields ) );
		}

		return array_unique( $keys );
	}

	/**
	 * Expand repeater and flexible-content sub-field keys to every row of the source post.
	 *
	 * The post-type key set knows only row 0 and ContentSync copies keys verbatim, so rows 1+ need their own keys.
	 *
	 * @param array<int, string> $fields    Meta keys queued for sync.
	 * @param int                $source_id Source post being synced from.
	 * @return array<int, string>
	 */
	public function expand_repeating_rows( array $fields, int $source_id ): array {
		if ( $source_id <= 0 || empty( $fields ) ) {
			return $fields;
		}

		// Worklist: an item carries a search offset so each nesting level's "_0_" is expanded in turn.
		// The (key|offset) dedup lets row 0, whose key equals the template, still advance to the inner level.
		$out   = [];
		$seen  = [];
		$queue = [];

		foreach ( $fields as $key ) {
			if ( is_string( $key ) ) {
				$queue[] = array( $key, 0 );
			}
		}

		while ( $queue !== array() ) {
			list( $key, $off ) = array_shift( $queue );

			$sig = $key . '|' . $off;
			if ( isset( $seen[ $sig ] ) ) {
				continue;
			}
			$seen[ $sig ] = true;

			$pos = strpos( $key, '_0_', $off );
			if ( $pos === false ) {
				continue; // Fully concrete already - the original is re-added below.
			}

			$prefix    = substr( $key, 0, $pos );  // Concrete container path.
			$suffix    = substr( $key, $pos + 3 ); // Sub-key after this "_0_".
			$container = get_post_meta( $source_id, $prefix, true );

			// A repeater stores a row count, flexible content an array of layout names; row 0 is the template.
			$count = is_array( $container ) ? count( $container ) : (int) $container;

			for ( $i = 0; $i < max( 1, $count ); $i++ ) {
				$candidate = $prefix . '_' . $i . '_' . $suffix;
				$next_off  = strlen( $prefix ) + 1 + strlen( (string) $i ) + 1;
				$is_leaf   = strpos( $candidate, '_0_', $next_off ) === false;

				if ( $is_leaf ) {
					// A flexible-content row has this sub-key only when its layout contains the field.
					if ( $i === 0 || metadata_exists( 'post', $source_id, $candidate ) ) {
						$out[ $candidate ] = true;
					}
				} else {
					// Intermediate level: expand the next "_0_" past the part just concretised.
					$queue[] = array( $candidate, $next_off );
				}
			}
		}

		// Every input template key is returned, even one a leaf existence check skipped.
		foreach ( $fields as $key ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Machine-translatable ACF meta keys: leaf text, textarea and wysiwyg fields only.
	 *
	 * Narrower than add_acf_meta_keys(): url/email/link/oembed values are never machine translated, container keys are structural.
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

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $keys;
		}

		if ( $post_id > 0 ) {
			$acf_keys = array_map( 'strval', array_keys( $this->concrete_mt_map( $post_type, $post_id, $translation_id ) ) );
		} else {
			$acf_keys = [];

			foreach ( acf_get_field_groups( [ 'post_type' => $post_type ] ) as $group ) {
				$fields = acf_get_fields( $group['key'] ?? '' );

				if ( is_array( $fields ) ) {
					$acf_keys = array_merge( $acf_keys, $this->collect_mt_fields( $fields ) );
				}
			}
		}

		if ( $acf_keys === [] ) {
			return $keys;
		}

		return array_values( array_unique( array_merge( $keys, $acf_keys ) ) );
	}

	/**
	 * The concrete MT map of the source post resolved last: [ "{blog}:{post}", [ meta key => 'text' | 'html' ] ].
	 *
	 * One entry only, so a bulk job stays memory-flat.
	 *
	 * @var array{0: string, 1: array<string, string>}|null
	 */
	private ?array $concrete_mt_memo = null;

	/**
	 * Machine-translatable ACF leaves of one source post, with their format.
	 *
	 * A layout sub-field key has no layout in it, so each flexible row is typed from its stored layout.
	 * See docs-ai/11-code-rationale.md, "PerfLocaleAcf: concrete MT map".
	 *
	 * @param string $post_type Source post type.
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Translation post ID; 0 skips the row-alignment check.
	 * @return array<string, string> meta key => 'text' | 'html'.
	 */
	private function concrete_mt_map( string $post_type, int $source_id, int $target_id = 0 ): array {
		$classes = [];
		$chains  = [];

		// Row loops are clamped to the post's distinct meta-key count: every
		// stored row writes at least one key, so a corrupt count cannot spin.
		$budget = count( (array) get_post_meta( $source_id ) );

		// The groups ACF shows on this post's edit screen: post-dependent location rules resolve against it.
		foreach ( acf_get_field_groups(
			[
				'post_id'   => $source_id,
				'post_type' => $post_type,
			]
		) as $group ) {
			$fields = acf_get_fields( $group['key'] ?? '' );

			if ( is_array( $fields ) ) {
				$this->walk_concrete_mt( $fields, '', $source_id, $budget, $classes, $chains );
			}
		}

		$map = array_filter( $classes, static fn( string $c ): bool => 'deny' !== $c );

		$this->concrete_mt_memo = [ get_current_blog_id() . ':' . $source_id, $map ];

		if ( $target_id > 0 && $target_id !== $source_id ) {
			$map = $this->aligned_rows_only( $map, $chains, $source_id, $target_id );
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

		// A key is evidence for every row that encloses it, so a moved outer
		// row is recognised by the leaves of the rows nested in it.
		foreach ( $chains as $key => $chain ) {
			foreach ( $chain as $row ) {
				$row_leaves[ $row[0] . '|' . $row[1] ][] = (string) $key;
			}
		}

		$aligned = [];
		$by_leaf = [];

		foreach ( $map as $key => $class ) {
			foreach ( $chains[ (string) $key ] ?? [] as $row ) {
				list( $container, $index, $layout ) = $row;

				$row_id = $container . '|' . $index;

				if ( ! isset( $aligned[ $row_id ] ) ) {
					$aligned[ $row_id ] = $this->row_aligned( $container, $index, $layout, $row_leaves[ $row_id ] ?? [], $source_id, $target_id, $by_leaf );
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
	 * @return bool
	 */
	private function row_aligned( string $container, int $index, ?string $layout, array $leaves, int $source_id, int $target_id, array &$by_leaf ): bool {
		$stored = get_post_meta( $target_id, $container, true );

		if ( null !== $layout ) {
			return is_array( $stored ) && isset( $stored[ $index ] ) && $stored[ $index ] === $layout;
		}

		if ( ! is_numeric( $stored ) || $index >= (int) $stored ) {
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
				$rows             = is_numeric( $rows ) ? (int) $rows : 0;

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
	 * Classify every stored ACF key under $fields for one source post.
	 *
	 * @param array<int|string, mixed>                                            $fields    ACF field definitions.
	 * @param string                                                              $prefix    Concrete meta-key prefix.
	 * @param int                                                                 $source_id Source post ID.
	 * @param int                                                                 $budget    Row-loop ceiling.
	 * @param array<string, string>                                               $classes   Out: key => 'text' | 'html' | 'deny'.
	 * @param array<string, array<int, array{0: string, 1: int, 2: string|null}>> $chains    Out: key => its enclosing rows.
	 * @param array<int, array{0: string, 1: int, 2: string|null}>                $chain     Rows enclosing $fields, outermost first.
	 * @return void
	 */
	private function walk_concrete_mt( array $fields, string $prefix, int $source_id, int $budget, array &$classes, array &$chains, array $chain = [] ): void {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || ! is_string( $field['name'] ) ) {
				continue;
			}

			$key  = $prefix . $field['name'];
			$type = (string) ( $field['type'] ?? '' );

			if ( 'group' === $type || 'clone' === $type ) {
				if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
					$child = 'group' === $type ? $key . '_' : self::clone_child_prefix( $field, $key, $prefix );
					$this->walk_concrete_mt( $field['sub_fields'], $child, $source_id, $budget, $classes, $chains, $chain );
				}
				continue;
			}

			$class = match ( $type ) {
				'text', 'textarea' => 'text',
				'wysiwyg'          => 'html',
				default            => 'deny',
			};

			$classes[ $key ] = ( isset( $classes[ $key ] ) && $classes[ $key ] !== $class ) ? 'deny' : $class;
			$chains[ $key ]  = $chain;

			if ( 'repeater' === $type && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$count = get_post_meta( $source_id, $key, true );
				$count = is_numeric( $count ) ? min( (int) $count, $budget ) : 0;

				for ( $i = 0; $i < $count; $i++ ) {
					$this->walk_concrete_mt( $field['sub_fields'], $key . '_' . $i . '_', $source_id, $budget, $classes, $chains, array_merge( $chain, [ [ $key, $i, null ] ] ) );
				}
			} elseif ( 'flexible_content' === $type && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$rows = get_post_meta( $source_id, $key, true );

				if ( ! is_array( $rows ) ) {
					continue;
				}

				$by_name = [];

				foreach ( $field['layouts'] as $layout ) {
					if ( is_array( $layout ) && isset( $layout['name'] ) && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$by_name[ (string) $layout['name'] ][] = $layout['sub_fields'];
					}
				}

				foreach ( $rows as $i => $layout_name ) {
					if ( ! is_int( $i ) || ! is_string( $layout_name ) || ! isset( $by_name[ $layout_name ] ) ) {
						continue;
					}

					foreach ( $by_name[ $layout_name ] as $sub_fields ) {
						$this->walk_concrete_mt( $sub_fields, $key . '_' . $i . '_', $source_id, $budget, $classes, $chains, array_merge( $chain, [ [ $key, $i, $layout_name ] ] ) );
					}
				}
			}
		}
	}

	/**
	 * Walk field definitions collecting ONLY leaf text/textarea/wysiwyg keys.
	 * Containers contribute their sub-field templates, never their own key.
	 *
	 * @param array<int, array<string, mixed>> $fields ACF field definitions.
	 * @param string                           $prefix Meta key prefix.
	 * @return array<int, string>
	 */
	private function collect_mt_fields( array $fields, string $prefix = '', ?array $leaf_types = null ): array {
		$keys     = [];
		$mt_types = $leaf_types ?? [ 'text', 'textarea', 'wysiwyg' ];

		foreach ( $fields as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}

			$meta_key = $prefix . $field['name'];

			if ( in_array( $field['type'], $mt_types, true ) ) {
				$keys[] = $meta_key;
				continue;
			}

			if ( $field['type'] === 'group' && ! empty( $field['sub_fields'] ) ) {
				$keys = array_merge( $keys, $this->collect_mt_fields( $field['sub_fields'], $meta_key . '_', $leaf_types ) );
				continue;
			}

			if ( $field['type'] === 'repeater' && ! empty( $field['sub_fields'] ) ) {
				$keys = array_merge( $keys, $this->collect_mt_fields( $field['sub_fields'], $meta_key . '_0_', $leaf_types ) );
				continue;
			}

			if ( $field['type'] === 'flexible_content' && ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) ) {
						$keys = array_merge( $keys, $this->collect_mt_fields( $layout['sub_fields'], $meta_key . '_0_', $leaf_types ) );
					}
				}
				continue;
			}

			// Cloned leaves are copied to translations, so they are collected under the copy walker's prefix.
			if ( $field['type'] === 'clone' && ! empty( $field['sub_fields'] ) ) {
				$clone_prefix = self::clone_child_prefix( $field, $meta_key, $prefix );
				$keys         = array_merge( $keys, $this->collect_mt_fields( $field['sub_fields'], $clone_prefix, $leaf_types ) );
			}
		}

		return $keys;
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
		if ( 'text' === $format ) {
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
	 * ACF text and textarea (not wysiwyg) meta-key templates of a post type, memoised per blog.
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
			foreach ( acf_get_field_groups( [ 'post_type' => $post_type ] ) as $group ) {
				$fields = acf_get_fields( $group['key'] ?? '' );

				if ( is_array( $fields ) ) {
					foreach ( $this->collect_mt_fields( $fields, '', [ 'text', 'textarea' ] ) as $k ) {
						$set[ $k ] = true;
					}
				}
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

	/**
	 * Recursively collect translatable field meta keys.
	 *
	 * @param array<int, array<string, mixed>> $fields ACF field definitions.
	 * @param string                           $prefix Meta key prefix for nested fields.
	 * @return array<int, string>
	 */
	private function collect_translatable_fields( array $fields, string $prefix = '' ): array {
		$keys = [];

		foreach ( $fields as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}

			$meta_key = $prefix . $field['name'];

			// Text-type fields: register the meta key directly.
			if ( in_array( $field['type'], self::TEXT_TYPES, true ) ) {
				$keys[] = $meta_key;
				continue;
			}

			// Group fields: sub-fields stored as {group}_{sub_field} (no row index).
			if ( $field['type'] === 'group' && ! empty( $field['sub_fields'] ) ) {
				$keys = array_merge(
					$keys,
					$this->collect_translatable_fields( $field['sub_fields'], $meta_key . '_' )
				);
				continue;
			}

			// Repeater fields: sub-fields stored as {repeater}_{N}_{sub_field}.
			// Register with index 0 as representative - copy_post_meta copies all indices.
			if ( $field['type'] === 'repeater' && ! empty( $field['sub_fields'] ) ) {
				$keys[] = $meta_key; // The row count meta key itself.
				$keys   = array_merge(
					$keys,
					$this->collect_translatable_fields( $field['sub_fields'], $meta_key . '_0_' )
				);
				continue;
			}

			// Flexible content: sub-fields inside each layout.
			// Register with index 0 as representative for all rows.
			if ( $field['type'] === 'flexible_content' && ! empty( $field['layouts'] ) ) {
				$keys[] = $meta_key; // The layout array meta key.

				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) ) {
						$keys = array_merge(
							$keys,
							$this->collect_translatable_fields( $layout['sub_fields'], $meta_key . '_0_' )
						);
					}
				}

				continue;
			}

			// Clone fields: if the cloned field is a text type, register it.
			if ( $field['type'] === 'clone' && ! empty( $field['sub_fields'] ) ) {
				$clone_prefix = self::clone_child_prefix( $field, $meta_key, $prefix );
				$keys         = array_merge(
					$keys,
					$this->collect_translatable_fields( $field['sub_fields'], $clone_prefix )
				);
			}
		}

		return $keys;
	}

	/**
	 * Translate ACF relationship field values on the frontend.
	 *
	 * @param mixed               $value Field value (array of post IDs or WP_Post objects).
	 * @param mixed               $post_id ACF post id, unused; mixed because ACF passes 'options', "term_{$id}" or null (an int type fatals).
	 * @param array<string,mixed> $field Field configuration.
	 * @return mixed
	 */
	public function translate_relationship( mixed $value, mixed $post_id, array $field ): mixed {
		if ( ! is_array( $value ) || is_admin() || $this->is_default_language() ) {
			return $value;
		}

		$lang_slug = $this->get_current_slug();

		if ( $lang_slug === '' ) {
			return $value;
		}

		$manager    = $this->get_manager();
		$translated = [];

		$this->prime_reference_targets( $value, $lang_slug );

		foreach ( $value as $related_post ) {
			$related_id = self::reference_id( $related_post );

			// Not a post reference: hand it back untouched, type included.
			if ( $related_id <= 0 ) {
				$translated[] = $related_post;
				continue;
			}

			$translation = $manager->get_translation_id( $related_id, $lang_slug );

			// Only a translation the visitor may see: a draft or private one would leak unfinished content.
			if ( $translation && ! $this->is_publicly_viewable_translation( (int) $translation ) ) {
				$translation = null;
			}

			if ( $translation ) {
				if ( is_object( $related_post ) ) {
					$translated_post = get_post( $translation );

					// The translated post may be deleted: fall back to the original.
					$translated[] = $translated_post instanceof \WP_Post ? $translated_post : $related_post;
				} else {
					$translated[] = $translation;
				}
			} else {
				$translated[] = $related_post;
			}
		}

		return $translated;
	}

	/**
	 * The post ID a relationship item refers to: a WP_Post's ID, an integer
	 * or a numeric string; 0 for anything else.
	 *
	 * @param mixed $item Relationship field item.
	 * @return int
	 */
	private static function reference_id( mixed $item ): int {
		if ( $item instanceof \WP_Post ) {
			return (int) $item->ID;
		}

		if ( is_int( $item ) || ( is_string( $item ) && is_numeric( $item ) ) ) {
			return (int) $item;
		}

		return 0;
	}

	/**
	 * Prime in batches the links and translated posts translate_relationship() reads.
	 *
	 * Cache fill only: visibility, fallback and order stay with the mapping loop.
	 *
	 * @param array<int|string, mixed> $value     Relationship field value.
	 * @param string                   $lang_slug Current language slug.
	 * @return void
	 */
	private function prime_reference_targets( array $value, string $lang_slug ): void {
		$ids = [];

		foreach ( $value as $item ) {
			$id = self::reference_id( $item );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( count( $ids ) < 2 || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}

		$this->get_repo()->prime_translations( \PerfLocale\Enum\ObjectType::Post, array_values( $ids ) );

		$manager = $this->get_manager();
		$targets = [];

		foreach ( $ids as $id ) {
			$target = $manager->get_translation_id( $id, $lang_slug );

			if ( $target !== null && $target > 0 ) {
				$targets[ $target ] = $target;
			}
		}

		foreach ( array_chunk( array_values( $targets ), self::TARGET_PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
		}
	}

	/**
	 * Translate ACF post object field value on the frontend.
	 *
	 * @param mixed               $value Field value: a post ID or WP_Post object, or a
	 *                                   list of them when "Select multiple" is on.
	 * @param mixed               $post_id ACF post id, unused; mixed because ACF passes 'options', "term_{$id}" or null (an int type fatals).
	 * @param array<string,mixed> $field Field configuration.
	 * @return mixed
	 */
	public function translate_post_object( mixed $value, mixed $post_id, array $field ): mixed {
		// A "Select multiple" Post Object arrives as a list: map it per item (an (int) cast of an array is 1, a real post ID).
		if ( is_array( $value ) ) {
			return $this->translate_relationship( $value, $post_id, $field );
		}

		if ( ! $value || is_admin() || $this->is_default_language() ) {
			return $value;
		}

		$lang_slug = $this->get_current_slug();

		if ( $lang_slug === '' ) {
			return $value;
		}

		$manager     = $this->get_manager();
		$related_id  = is_object( $value ) ? $value->ID : (int) $value;
		$translation = $manager->get_translation_id( $related_id, $lang_slug );

		// See translate_relationship(): never surface a non-public translation
		// in place of a published source on the front end.
		if ( $translation && ! $this->is_publicly_viewable_translation( (int) $translation ) ) {
			$translation = null;
		}

		if ( $translation ) {
			if ( is_object( $value ) ) {
				$translated_post = get_post( $translation );

				return $translated_post instanceof \WP_Post ? $translated_post : $value;
			}

			return $translation;
		}

		return $value;
	}

	/**
	 * Translate ACF taxonomy field values on the frontend.
	 *
	 * @param mixed               $value Field value (array of term IDs or WP_Term objects).
	 * @param mixed               $post_id ACF post id, unused; mixed because ACF passes 'options', "term_{$id}" or null (an int type fatals).
	 * @param array<string,mixed> $field Field configuration.
	 * @return mixed
	 */
	public function translate_taxonomy( mixed $value, mixed $post_id, array $field ): mixed {
		if ( ! $value || is_admin() || $this->is_default_language() ) {
			return $value;
		}

		$lang_slug = $this->get_current_slug();

		if ( $lang_slug === '' ) {
			return $value;
		}

		$repo       = $this->get_repo();
		$was_single = ! is_array( $value );

		if ( $was_single ) {
			$value = [ $value ];
		}

		$translated = [];

		$this->prime_term_targets( $value, $lang_slug );

		foreach ( $value as $term ) {
			$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;

			if ( $term_id <= 0 ) {
				$translated[] = $term;
				continue;
			}

			$links = $repo->get_translations( $term_id, \PerfLocale\Enum\ObjectType::Term );
			$found = false;

			foreach ( $links as $link ) {
				if ( ! empty( $link->language_slug ) && $link->language_slug === $lang_slug && (int) $link->object_id !== $term_id ) {
					$translated_term = get_term( (int) $link->object_id );

					if ( $translated_term instanceof \WP_Term ) {
						$translated[] = is_object( $term ) ? $translated_term : $translated_term->term_id;
						$found        = true;
						break;
					}
				}
			}

			if ( ! $found ) {
				$translated[] = $term;
			}
		}

		// Return single value if the original was not an array.
		if ( $was_single && count( $translated ) === 1 ) {
			return $translated[0];
		}

		return $translated;
	}

	/**
	 * Prime in batches the links and translated terms translate_taxonomy() reads.
	 *
	 * Cache fill only: the get_term() check, fallback and order stay with the mapping loop.
	 *
	 * @param array<int|string, mixed> $value     Taxonomy field value.
	 * @param string                   $lang_slug Current language slug.
	 * @return void
	 */
	private function prime_term_targets( array $value, string $lang_slug ): void {
		$ids = [];

		foreach ( $value as $term ) {
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$term_id = (int) $term->term_id;
			} elseif ( is_int( $term ) || ( is_string( $term ) && is_numeric( $term ) ) ) {
				$term_id = (int) $term;
			} else {
				continue;
			}

			if ( $term_id > 0 ) {
				$ids[ $term_id ] = $term_id;
			}
		}

		if ( count( $ids ) < 2 || ! function_exists( '_prime_term_caches' ) ) {
			return;
		}

		$repo = $this->get_repo();
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Term, array_values( $ids ) );

		$targets = [];

		foreach ( $ids as $term_id ) {
			foreach ( $repo->get_translations( $term_id, \PerfLocale\Enum\ObjectType::Term ) as $link ) {
				if ( ! empty( $link->language_slug ) && $link->language_slug === $lang_slug && (int) $link->object_id !== $term_id ) {
					$target = (int) $link->object_id;

					if ( $target > 0 ) {
						$targets[ $target ] = $target;
					}

					break;
				}
			}
		}

		foreach ( array_chunk( array_values( $targets ), self::TARGET_PRIME_CHUNK ) as $chunk ) {
			_prime_term_caches( $chunk, false );
		}
	}

	/**
	 * Whether a translated post may be shown to the current visitor.
	 *
	 * Core's visibility rule (WP 5.7+) with a status fallback for older cores.
	 *
	 * @param int $post_id Translated post ID.
	 * @return bool
	 */
	private function is_publicly_viewable_translation( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) ) {
			return (bool) is_post_publicly_viewable( $post_id );
		}

		$status = get_post_status( $post_id );

		return is_string( $status ) && in_array( $status, get_post_stati( [ 'public' => true ] ), true );
	}

	/**
	 * Check if the current language is the default (no translation needed).
	 *
	 * @return bool
	 */
	private function is_default_language(): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return true;
		}

		$router  = $plugin->get( 'router' );
		$default = $router->get_default_language();

		return $default && $router->get_current_slug() === $default->slug;
	}

	/**
	 * Get the current language slug.
	 *
	 * @return string
	 */
	private function get_current_slug(): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return '';
		}

		return $plugin->get( 'router' )->get_current_slug();
	}

	/**
	 * Lazily instantiated post translation manager.
	 *
	 * @var \PerfLocale\Translation\PostTranslationManager|null
	 */
	private ?\PerfLocale\Translation\PostTranslationManager $manager = null;

	/**
	 * Lazily instantiated translation group repository.
	 *
	 * @var \PerfLocale\Database\Repository\TranslationGroupRepository|null
	 */
	private ?\PerfLocale\Database\Repository\TranslationGroupRepository $repo = null;

	/**
	 * Get the post translation manager (lazy).
	 *
	 * @return \PerfLocale\Translation\PostTranslationManager
	 */
	private function get_manager(): \PerfLocale\Translation\PostTranslationManager {
		if ( $this->manager === null ) {
			$plugin        = \PerfLocale\Plugin::get_instance();
			$this->manager = new \PerfLocale\Translation\PostTranslationManager(
				$plugin->get( 'cache' ),
				$plugin->get( 'settings' )
			);
		}

		return $this->manager;
	}

	/**
	 * Get the translation group repository (lazy).
	 *
	 * @return \PerfLocale\Database\Repository\TranslationGroupRepository
	 */
	private function get_repo(): \PerfLocale\Database\Repository\TranslationGroupRepository {
		if ( $this->repo === null ) {
			$plugin     = \PerfLocale\Plugin::get_instance();
			$this->repo = new \PerfLocale\Database\Repository\TranslationGroupRepository(
				$plugin->get( 'cache' )
			);
		}

		return $this->repo;
	}
}
