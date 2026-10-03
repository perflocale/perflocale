<?php
/**
 * PerfLocale MetaBox addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MetaBox integration for PerfLocale.
 *
 * Registers the translatable top-level fields that store post meta
 * (text types, cloneable fields and whole groups) for content sync across
 * translations. Translates reference field IDs (post, taxonomy_advanced),
 * including those nested in groups, on the frontend.
 *
 * Compatible with MetaBox standalone and MetaBox AIO.
 */
final class PerfLocaleMetabox implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Field types that contain translatable text content.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_TYPES = [
		'text',
		'textarea',
		'wysiwyg',
		'url',
		'email',
		'oembed',
	];

	/**
	 * Field types that store translatable text inside serialized arrays.
	 *
	 * @var array<int, string>
	 */
	private const SERIALIZED_TEXT_TYPES = [
		'fieldset_text',
		'text_list',
		'key_value',
		'input_list',
	];

	/**
	 * Field types that store reference IDs needing translation.
	 *
	 * @var array<int, string>
	 */
	private const REFERENCE_TYPES = [
		'post',
		'taxonomy_advanced',
	];

	/**
	 * Cached field definitions per post type.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $field_cache = [];

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
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'metabox';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'MetaBox';
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
		return [ 'meta-box-aio/meta-box-aio.php', 'meta-box/meta-box.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'RWMB_VER' ) || class_exists( 'RWMB_Loader' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register translatable meta keys.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// Meta Box DELETES the row when a field is saved empty, so a cleared
		// field and a never-set one look the same to ContentSync's seed-only
		// rule, which would refill it on the next save of the source. Record
		// the clear where the host still knows it: the editor and Frontend
		// Submission save, rwmb_set_meta(), and the MB REST API. Only a
		// person's clear is recorded: rwmb_before_save_post fires after a
		// Meta Box form passed its nonce check and marks its post as edited.
		add_filter( 'rwmb_after_save_field', [ $this, 'record_clear_on_save' ], 10, 5 );
		add_action( 'rwmb_flush_data', [ $this, 'record_clear_on_set' ], 10, 2 );
		add_action( 'rwmb_before_save_post', [ \PerfLocale\Translation\ContentSync::class, 'open_person_edit' ], 10, 1 );
		add_filter( 'rwmb_rest_value', [ $this, 'record_clear_on_rest' ], PHP_INT_MAX, 4 );

		// The value of a password field is not copied into a new translation,
		// nor is a password sub-field of a group, also when a source save
		// seeds the group into an existing translation.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_password_fields' ], 10, 2 );
		add_action( 'perflocale/translation/created', [ $this, 'drop_group_password_values' ], 5, 2 );
		add_filter( 'perflocale/sync/seed_meta_value', [ $this, 'seed_without_password_sub_fields' ], 10, 3 );

		// Translate reference fields on the frontend only.
		if ( ! is_admin() ) {
			add_filter( 'rwmb_get_value', [ $this, 'translate_field_value' ], 10, 4 );
		}

		// The field-definition cache is keyed by post type only; on multisite
		// a shared slug can carry different Meta Box fields per blog, so clear
		// it when the active blog switches (mirrors the core repositories).
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_static_caches' ] );
		}
	}

	/**
	 * Clear the per-post-type field-definition cache. Hooked to switch_blog
	 * on multisite so one blog's Meta Box fields never serve another's.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$field_cache = [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * `perflocale/translation/excluded_meta_keys`: the values of the source
	 * post type's Meta Box password fields stay out of a new translation.
	 *
	 * A password field is a password by its type, whatever its ID, so the
	 * credential-name patterns of the meta copy do not catch it. Covers the
	 * top-level fields that store their value as a post meta row (the field
	 * ID); a field inside a group is part of the group's one row, which
	 * drop_group_password_values() rewrites without it. Runs only when a
	 * translation is created.
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

		foreach ( $this->get_fields_for_post_type( $post_type ) as $field ) {
			if ( is_array( $field ) && 'password' === ( $field['type'] ?? '' ) && ! empty( $field['id'] ) && self::is_post_meta_storage( $field ) ) {
				$excluded[] = (string) $field['id'];
			}
		}

		return $excluded;
	}

	/**
	 * `perflocale/translation/created`: the password sub-fields of the new
	 * translation's Meta Box groups are taken out of the copied values.
	 *
	 * A group is one post meta row holding all its sub-fields (one row per
	 * clone when the clones are stored as separate rows), so
	 * exclude_password_fields() cannot leave a password sub-field out of the
	 * copy. Each copied row of a group that has a password sub-field, in the
	 * group itself, a nested group or a clone, is written again without it;
	 * every other value stays as copied. Rows without one are not written.
	 *
	 * @param int|mixed    $new_id New translation's ID.
	 * @param string|mixed $type   Object type, 'post' or 'term'.
	 * @return void
	 */
	public function drop_group_password_values( mixed $new_id = 0, mixed $type = '' ): void {
		if ( 'post' !== $type || ! is_numeric( $new_id ) || (int) $new_id <= 0 || ! function_exists( 'rwmb_get_object_fields' ) ) {
			return;
		}

		$post_id   = (int) $new_id;
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) ) {
			return;
		}

		foreach ( $this->get_fields_for_post_type( $post_type ) as $field ) {
			if ( ! is_array( $field ) || 'group' !== ( $field['type'] ?? '' ) || empty( $field['id'] ) || ! self::is_post_meta_storage( $field ) || ! self::has_password_sub_field( $field ) ) {
				continue;
			}

			$key = (string) $field['id'];

			foreach ( (array) get_post_meta( $post_id, $key, false ) as $row ) {
				$kept = self::without_password_sub_fields( $row, $field );

				if ( $kept !== $row ) {
					// The previous value picks the row; the new one is slashed
					// because update_post_meta() unslashes it.
					update_post_meta( $post_id, $key, \PerfLocale\Helper::deep_slash( $kept ), $row );
				}
			}
		}
	}

	/**
	 * `perflocale/sync/seed_meta_value`: a seeded row of a Meta Box group is
	 * copied without its password sub-fields.
	 *
	 * A translation that has no row for a group (created before the source's
	 * group was filled, or before the group was added) gets the source's
	 * rows on the next save of the source. Each row of a post-meta group
	 * with a password sub-field is seeded as drop_group_password_values()
	 * leaves a new translation's copy; every other row is returned as it is.
	 *
	 * @param mixed $value     Row as read from the source.
	 * @param mixed $key       Meta key.
	 * @param mixed $target_id Translation that receives the row.
	 * @return mixed
	 */
	public function seed_without_password_sub_fields( mixed $value, mixed $key = '', mixed $target_id = 0 ): mixed {
		if ( ! is_array( $value ) || ! is_string( $key ) || '' === $key || ! is_numeric( $target_id ) || ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $value;
		}

		$post_type = get_post_type( (int) $target_id );

		if ( ! is_string( $post_type ) ) {
			return $value;
		}

		foreach ( $this->get_fields_for_post_type( $post_type ) as $field ) {
			if ( is_array( $field ) && 'group' === ( $field['type'] ?? '' ) && $key === (string) ( $field['id'] ?? '' ) && self::is_post_meta_storage( $field ) && self::has_password_sub_field( $field ) ) {
				return self::without_password_sub_fields( $value, $field );
			}
		}

		return $value;
	}

	/**
	 * Whether a group field has a password sub-field, at any depth.
	 *
	 * @param array<string, mixed> $group Group field settings.
	 * @return bool
	 */
	private static function has_password_sub_field( array $group ): bool {
		foreach ( (array) ( $group['fields'] ?? [] ) as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}

			if ( 'password' === ( $sub['type'] ?? '' ) || ( 'group' === ( $sub['type'] ?? '' ) && self::has_password_sub_field( $sub ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A group's stored value without its password sub-fields.
	 *
	 * A list is the group's clones, each a value of its own; a map holds one
	 * value per sub-field ID. Nested groups are walked the same way.
	 *
	 * @param mixed                $value Stored group value.
	 * @param array<string, mixed> $group Group field settings.
	 * @return mixed
	 */
	private static function without_password_sub_fields( mixed $value, array $group ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		// A list of clones. ($value === array_values($value) is the list check
		// equivalent to array_is_list(), which Plugin Check maps to WP 6.5.)
		if ( $value === array_values( $value ) ) {
			foreach ( $value as $index => $clone ) {
				$value[ $index ] = self::without_password_sub_fields( $clone, $group );
			}

			return $value;
		}

		foreach ( (array) ( $group['fields'] ?? [] ) as $sub ) {
			$sub_id = is_array( $sub ) ? (string) ( $sub['id'] ?? '' ) : '';

			if ( $sub_id === '' || ! array_key_exists( $sub_id, $value ) ) {
				continue;
			}

			if ( 'password' === ( $sub['type'] ?? '' ) ) {
				unset( $value[ $sub_id ] );
			} elseif ( 'group' === ( $sub['type'] ?? '' ) ) {
				$value[ $sub_id ] = self::without_password_sub_fields( $value[ $sub_id ], $sub );
			}
		}

		return $value;
	}

	/**
	 * Detect all translatable MetaBox fields for a post type.
	 *
	 * Registers the top-level text-type and group fields that store post
	 * meta; see collect_translatable_fields().
	 *
	 * @param array<int, string> $keys Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $keys;
		}

		$fields = $this->get_fields_for_post_type( $post_type );

		if ( empty( $fields ) ) {
			return $keys;
		}

		$new_keys = $this->collect_translatable_fields( $fields );

		if ( ! empty( $new_keys ) ) {
			$keys = array_unique( array_merge( $keys, $new_keys ) );
		}

		return $keys;
	}

	/**
	 * Machine-translatable MetaBox keys: LEAF text/textarea/wysiwyg fields
	 * only. Serialized text types (fieldset_text, text_list, key_value,
	 * input_list) are whole-array blobs, url/email/oembed must not be
	 * translated, and group fields store serialized sub-structures — all
	 * excluded. Gated by the mt_meta_custom_fields setting.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_custom_fields', false ) ) {
			return $keys;
		}

		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $keys;
		}

		$fields   = $this->get_fields_for_post_type( $post_type );
		$mt_types = [ 'text', 'textarea', 'wysiwyg' ];
		$new_keys = [];

		foreach ( (array) $fields as $field ) {
			$type = is_array( $field ) ? ( $field['type'] ?? '' ) : '';
			$name = is_array( $field ) ? ( $field['id'] ?? ( $field['name'] ?? '' ) ) : '';

			// Cloneable fields store either a serialized blob (skipped by the
			// single-value MT read) or one meta row per clone value when
			// clone_as_multiple is set; the multi-row variant would have the
			// translated row-0 value collapse onto every row, so keep clones
			// out of MT entirely.
			if ( ! is_array( $field ) || ! empty( $field['clone'] ) || ! self::is_post_meta_storage( $field ) ) {
				continue;
			}

			if ( $name !== '' && in_array( $type, $mt_types, true ) ) {
				$new_keys[] = (string) $name;
			}
		}

		return $new_keys === [] ? $keys : array_values( array_unique( array_merge( $keys, $new_keys ) ) );
	}

	/**
	 * Route Meta Box text / textarea fields to the provider's text mode so the
	 * stored translation isn't entity-escaped; wysiwyg fields keep the inherited
	 * 'html' default. Only ever UPGRADES a key to text.
	 *
	 * @param string $format    Inherited format ('html' default).
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @return string 'text' for a text/textarea field, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		if ( 'text' === $format ) {
			return $format;
		}

		$set = $this->plaintext_mt_key_set( $post_type );

		return isset( $set[ $key ] ) ? 'text' : $format;
	}

	/**
	 * The set of Meta Box text/textarea (NOT wysiwyg) field names for a post
	 * type, built once per request. Blog-keyed for multisite; bounded.
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
			foreach ( (array) $this->get_fields_for_post_type( $post_type ) as $field ) {
				if ( ! is_array( $field ) || ! empty( $field['clone'] ) || ! self::is_post_meta_storage( $field ) ) {
					continue;
				}

				$type = $field['type'] ?? '';
				$name = $field['id'] ?? ( $field['name'] ?? '' );

				if ( $name !== '' && in_array( $type, [ 'text', 'textarea' ], true ) ) {
					$set[ (string) $name ] = true;
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
	 * `rwmb_after_save_field`: record an empty save of a field that held a
	 * value, and forget it once the field holds a value again. Any other save
	 * (unchanged, edited, still empty) returns after two comparisons.
	 *
	 * @param mixed $passthru  Filter value, returned unchanged.
	 * @param mixed $field     Field settings.
	 * @param mixed $new_value Value just saved.
	 * @param mixed $old_value Value before the save.
	 * @param mixed $object_id Object ID.
	 * @return mixed
	 */
	public function record_clear_on_save( mixed $passthru = null, mixed $field = null, mixed $new_value = null, mixed $old_value = null, mixed $object_id = 0 ): mixed {
		// Meta Box's own emptiness rule (RWMB_Helpers_Value::is_valid_for_field):
		// only '' and [] delete the row; '0' is a value.
		$had = '' !== $old_value && [] !== $old_value && null !== $old_value;
		$has = '' !== $new_value && [] !== $new_value && null !== $new_value;

		if ( $had !== $has && is_array( $field ) && ! empty( $field['save_field'] ) && is_numeric( $object_id ) ) {
			$post_id = (int) $object_id;
			$key     = $this->tracked_key( $post_id, $field );

			if ( $key !== '' ) {
				\PerfLocale\Translation\ContentSync::record_seed_clear( $post_id, $key, ! $has );
			}
		}

		return $passthru;
	}

	/**
	 * `rwmb_flush_data`, fired by rwmb_set_meta(): no old or new value is
	 * passed, so the stored rows decide. An empty set is recorded only while
	 * a person edits the post (ContentSync::record_seed_clear()).
	 *
	 * @param mixed $object_id Object ID.
	 * @param mixed $field     Field settings.
	 * @return void
	 */
	public function record_clear_on_set( mixed $object_id = 0, mixed $field = null ): void {
		if ( ! is_numeric( $object_id ) || ! is_array( $field ) || empty( $field['save_field'] ) ) {
			return;
		}

		$post_id = (int) $object_id;
		$key     = $this->tracked_key( $post_id, $field );

		if ( $key !== '' ) {
			\PerfLocale\Translation\ContentSync::record_seed_clear( $post_id, $key, get_post_meta( $post_id, $key, false ) === [] );
		}
	}

	/**
	 * `rwmb_rest_value` (MB REST API): the last filter before that extension
	 * saves a field, and it fires neither of the hooks above.
	 *
	 * @param mixed $new_value Value about to be saved, returned unchanged.
	 * @param mixed $field     Field settings.
	 * @param mixed $old_value Value before the save.
	 * @param mixed $object_id Object ID.
	 * @return mixed
	 */
	public function record_clear_on_rest( mixed $new_value = null, mixed $field = null, mixed $old_value = null, mixed $object_id = 0 ): mixed {
		$this->record_clear_on_save( null, $field, $new_value, $old_value, $object_id );

		return $new_value;
	}

	/**
	 * The meta key ContentSync would seed for this field on this post, or ''
	 * when it would not: post-meta storage only, a translatable post type,
	 * and a key this addon registers for it.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $field   Field settings.
	 * @return string
	 */
	private function tracked_key( int $post_id, array $field ): string {
		if ( $post_id <= 0 || empty( $field['id'] ) || ! self::is_post_meta_storage( $field ) ) {
			return '';
		}

		$plugin    = \PerfLocale\Plugin::get_instance();
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) || ! $plugin->has( 'settings' )
			|| ! in_array( $post_type, $plugin->get( 'settings' )->get_translatable_post_types(), true ) ) {
			return '';
		}

		$key = (string) $field['id'];

		return isset( $this->seed_key_set( $post_type )[ $key ] ) ? $key : '';
	}

	/**
	 * The keys add_meta_keys() registers for a post type, as a set. Built once
	 * per request; blog-keyed for multisite; bounded like plaintext_mt_key_set().
	 *
	 * @param string $post_type Post type.
	 * @return array<string, bool>
	 */
	private function seed_key_set( string $post_type ): array {
		static $cache = [];

		$blog = get_current_blog_id();

		if ( isset( $cache[ $blog ][ $post_type ] ) ) {
			return $cache[ $blog ][ $post_type ];
		}

		$set = function_exists( 'rwmb_get_object_fields' )
			? array_fill_keys( array_map( 'strval', $this->collect_translatable_fields( $this->get_fields_for_post_type( $post_type ) ) ), true )
			: [];

		if ( count( $cache[ $blog ] ?? [] ) >= 32 ) {
			unset( $cache[ $blog ] );
		}

		$cache[ $blog ][ $post_type ] = $set;

		return $set;
	}

	/**
	 * Translate MetaBox reference field values on the frontend.
	 *
	 * Replaces related post/term IDs with their translated counterparts
	 * for the current language. Handles post, taxonomy_advanced, and
	 * group fields containing reference sub-fields.
	 *
	 * @param mixed               $value Field value.
	 * @param array<string,mixed> $field Field configuration.
	 * @param array<string,mixed> $args Additional arguments.
	 * @param int|null            $post_id Post ID.
	 * @return mixed
	 */
	public function translate_field_value( mixed $value, mixed $field, mixed $args, mixed $post_id ): mixed {
		if ( ! is_array( $field ) || empty( $field['type'] ) ) {
			return $value;
		}

		$lang_slug = $this->get_current_slug();

		if ( $lang_slug === '' ) {
			return $value;
		}

		// Skip translation lookups on the default language - no translations exist.
		$plugin  = \PerfLocale\Plugin::get_instance();
		$default = $plugin->has( 'router' ) ? $plugin->get( 'router' )->get_default_language() : null;

		if ( $default && $lang_slug === $default->slug ) {
			return $value;
		}

		$type = $field['type'];

		// Translate post reference fields.
		if ( $type === 'post' ) {
			return $this->translate_post_ids( $value, $lang_slug );
		}

		// Translate taxonomy_advanced reference fields.
		if ( $type === 'taxonomy_advanced' ) {
			return $this->translate_term_ids( $value, $lang_slug );
		}

		// Translate reference sub-fields inside groups.
		if ( $type === 'group' && is_array( $value ) && ! empty( $field['fields'] ) ) {
			return $this->translate_group_references( $value, $field, $lang_slug );
		}

		return $value;
	}

	/**
	 * Get cached field definitions for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_fields_for_post_type( string $post_type ): array {
		if ( isset( self::$field_cache[ $post_type ] ) ) {
			return self::$field_cache[ $post_type ];
		}

		$fields = rwmb_get_object_fields( $post_type, 'post' );

		self::$field_cache[ $post_type ] = is_array( $fields ) ? $fields : [];

		return self::$field_cache[ $post_type ];
	}

	/**
	 * Whether a field stores its value as the post's own meta row.
	 *
	 * Meta Box can route a box to a custom table (MB Custom Table) or another
	 * storage; those values never reach wp_postmeta, so post-meta copy, seed and
	 * machine translation cannot see them, and a postmeta row that happens to
	 * share the field ID is unrelated data. A field without a storage object
	 * uses Meta Box's post-meta default.
	 *
	 * @param array<string, mixed> $field Field settings.
	 * @return bool
	 */
	private static function is_post_meta_storage( array $field ): bool {
		return ! isset( $field['storage'] ) || $field['storage'] instanceof \RWMB_Post_Storage;
	}

	/**
	 * Collect the top-level translatable field meta keys.
	 *
	 * Registers text-type fields and group fields that store post meta. A
	 * group is one meta row holding every sub-field, so its child IDs are not
	 * meta keys of their own and are never registered.
	 *
	 * @param array<string, array<string, mixed>> $fields MetaBox field definitions.
	 * @return array<int, string>
	 */
	private function collect_translatable_fields( array $fields ): array {
		$keys = [];

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) || empty( $field['type'] ) || ! self::is_post_meta_storage( $field ) ) {
				continue;
			}

			$type     = $field['type'];
			$field_id = $field['id'];

			if ( in_array( $type, self::TEXT_TYPES, true ) || in_array( $type, self::SERIALIZED_TEXT_TYPES, true ) ) {
				$keys[] = $field_id;
				continue;
			}

			if ( $type === 'group' && ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$keys[] = $field_id;
			}
		}

		return $keys;
	}

	/**
	 * Resolve a post's current-language sibling, but only when the visitor is
	 * allowed to see it.
	 *
	 * `rwmb_get_value` is filtered on the FRONT END, so a reference field whose
	 * sibling is still a draft/pending/private translation would otherwise put
	 * that unpublished post's title on a public page and link to a URL an
	 * anonymous visitor cannot open. PerfLocale creates translations as drafts,
	 * so that is the normal state of a half-finished translation, not an edge
	 * case. Returning null keeps the published source in place — the same rule
	 * the ACF addon applies to its relationship / post-object fields and the
	 * WooCommerce addon to cross-sell and upsell IDs.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $lang_slug Target language slug.
	 * @return int|null Sibling post ID, or null when there is none to show.
	 */
	private function public_translation_id( int $post_id, string $lang_slug ): ?int {
		if ( $post_id <= 0 ) {
			return null;
		}

		$translation = $this->get_manager()->get_translation_id( $post_id, $lang_slug );

		if ( ! $translation || $translation === $post_id ) {
			return null;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) ) {
			return is_post_publicly_viewable( $translation ) ? $translation : null;
		}

		$status = get_post_status( $translation );

		return ( is_string( $status ) && in_array( $status, get_post_stati( [ 'public' => true ] ), true ) )
			? $translation
			: null;
	}

	/**
	 * Translate post IDs to their current-language equivalents.
	 *
	 * Handles single IDs, arrays, comma-separated strings, and WP_Post objects.
	 * A sibling the visitor may not see is skipped — see
	 * {@see self::public_translation_id()}.
	 *
	 * @param mixed  $value Post ID(s) in various formats.
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_post_ids( mixed $value, string $lang_slug ): mixed {
		if ( empty( $value ) ) {
			return $value;
		}

		// Comma-separated string of IDs.
		if ( is_string( $value ) && str_contains( $value, ',' ) ) {
			$ids        = array_map( 'intval', explode( ',', $value ) );
			$translated = [];

			foreach ( $ids as $id ) {
				if ( $id <= 0 ) {
					continue;
				}

				$translation  = $this->public_translation_id( $id, $lang_slug );
				$translated[] = $translation ?? $id;
			}

			return implode( ',', $translated );
		}

		// Array of IDs or WP_Post objects.
		if ( is_array( $value ) ) {
			$translated = [];

			foreach ( $value as $item ) {
				$item_id = is_object( $item ) && isset( $item->ID ) ? (int) $item->ID : (int) $item;

				if ( $item_id <= 0 ) {
					$translated[] = $item;
					continue;
				}

				$translation = $this->public_translation_id( $item_id, $lang_slug );

				if ( $translation ) {
					if ( is_object( $item ) ) {
						$translated_post = get_post( $translation );
						$translated[]    = $translated_post instanceof \WP_Post ? $translated_post : $item;
					} else {
						$translated[] = $translation;
					}
				} else {
					$translated[] = $item;
				}
			}

			return $translated;
		}

		// Single ID.
		$item_id = is_object( $value ) && isset( $value->ID ) ? (int) $value->ID : (int) $value;

		if ( $item_id <= 0 ) {
			return $value;
		}

		$translation = $this->public_translation_id( $item_id, $lang_slug );

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
	 * Translate term IDs to their current-language equivalents.
	 *
	 * Handles single IDs, arrays, and comma-separated strings.
	 *
	 * @param mixed  $value Term ID(s) in various formats.
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_term_ids( mixed $value, string $lang_slug ): mixed {
		if ( empty( $value ) ) {
			return $value;
		}

		// Comma-separated string of IDs.
		if ( is_string( $value ) && str_contains( $value, ',' ) ) {
			$ids        = array_map( 'intval', explode( ',', $value ) );
			$translated = [];

			foreach ( $ids as $id ) {
				if ( $id <= 0 ) {
					continue;
				}

				$translated[] = $this->get_translated_term_id( $id, $lang_slug );
			}

			return implode( ',', $translated );
		}

		// Array of IDs or WP_Term objects.
		if ( is_array( $value ) ) {
			$translated = [];

			foreach ( $value as $item ) {
				$term_id = is_object( $item ) && isset( $item->term_id ) ? (int) $item->term_id : (int) $item;

				if ( $term_id <= 0 ) {
					$translated[] = $item;
					continue;
				}

				$new_id = $this->get_translated_term_id( $term_id, $lang_slug );

				if ( $new_id !== $term_id && is_object( $item ) ) {
					$translated_term = get_term( $new_id );
					$translated[]    = $translated_term instanceof \WP_Term ? $translated_term : $item;
				} else {
					$translated[] = is_object( $item ) ? $item : $new_id;
				}
			}

			return $translated;
		}

		// Single ID.
		$term_id = is_object( $value ) && isset( $value->term_id ) ? (int) $value->term_id : (int) $value;

		if ( $term_id <= 0 ) {
			return $value;
		}

		$new_id = $this->get_translated_term_id( $term_id, $lang_slug );

		if ( $new_id !== $term_id && is_object( $value ) ) {
			$translated_term = get_term( $new_id );
			return $translated_term instanceof \WP_Term ? $translated_term : $value;
		}

		return is_object( $value ) ? $value : $new_id;
	}

	/**
	 * Translate reference fields inside a group value.
	 *
	 * Walks the deserialized group value and translates post/term IDs
	 * for sub-fields that are reference types.
	 *
	 * @param array<string, mixed>|array<int, array<string, mixed>> $value Group value (single or cloneable array of groups).
	 * @param array<string, mixed>                                  $field Group field config.
	 * @param string                                                $lang_slug Target language slug.
	 * @return array<string, mixed>|array<int, array<string, mixed>>
	 */
	private function translate_group_references( array $value, array $field, string $lang_slug ): array {
		// Build a map of reference sub-fields.
		$ref_sub_fields = [];

		foreach ( $field['fields'] as $sub_field ) {
			if ( ! is_array( $sub_field ) || empty( $sub_field['id'] ) || empty( $sub_field['type'] ) ) {
				continue;
			}

			if ( in_array( $sub_field['type'], self::REFERENCE_TYPES, true ) ) {
				$ref_sub_fields[ $sub_field['id'] ] = $sub_field['type'];
			}

			// Recurse into nested groups.
			if ( $sub_field['type'] === 'group' && ! empty( $sub_field['fields'] ) ) {
				$ref_sub_fields[ $sub_field['id'] ] = 'group';
			}
		}

		// No reference sub-fields - return as-is.
		if ( empty( $ref_sub_fields ) ) {
			return $value;
		}

		// Cloneable group: array of arrays.
		$is_cloneable = ! empty( $field['clone'] ) && isset( $value[0] ) && is_array( $value[0] );

		if ( $is_cloneable ) {
			foreach ( $value as $index => $clone_entry ) {
				if ( ! is_array( $clone_entry ) ) {
					continue;
				}

				$value[ $index ] = $this->translate_group_entry( $clone_entry, $ref_sub_fields, $field, $lang_slug );
			}
		} else {
			$value = $this->translate_group_entry( $value, $ref_sub_fields, $field, $lang_slug );
		}

		return $value;
	}

	/**
	 * Translate reference fields in a single group entry.
	 *
	 * @param array<string, mixed>  $entry Group entry data.
	 * @param array<string, string> $ref_sub_fields Map of field_id => type for reference fields.
	 * @param array<string, mixed>  $field Parent group field config.
	 * @param string                $lang_slug Target language slug.
	 * @return array<string, mixed>
	 */
	private function translate_group_entry( array $entry, array $ref_sub_fields, array $field, string $lang_slug ): array {
		foreach ( $ref_sub_fields as $sub_id => $sub_type ) {
			if ( ! isset( $entry[ $sub_id ] ) ) {
				continue;
			}

			if ( $sub_type === 'post' ) {
				$entry[ $sub_id ] = $this->translate_post_ids( $entry[ $sub_id ], $lang_slug );
			} elseif ( $sub_type === 'taxonomy_advanced' ) {
				$entry[ $sub_id ] = $this->translate_term_ids( $entry[ $sub_id ], $lang_slug );
			} elseif ( $sub_type === 'group' ) {
				// Find the nested group's field config for recursive translation.
				foreach ( $field['fields'] as $sub_field ) {
					if ( is_array( $sub_field ) && ( $sub_field['id'] ?? '' ) === $sub_id && is_array( $entry[ $sub_id ] ) ) {
						$entry[ $sub_id ] = $this->translate_group_references( $entry[ $sub_id ], $sub_field, $lang_slug );
						break;
					}
				}
			}
		}

		return $entry;
	}

	/**
	 * Get the translated term ID for a given term.
	 *
	 * @param int    $term_id Original term ID.
	 * @param string $lang_slug Target language slug.
	 * @return int Translated term ID, or original if no translation found.
	 */
	private function get_translated_term_id( int $term_id, string $lang_slug ): int {
		$repo  = $this->get_repo();
		$links = $repo->get_translations( $term_id, \PerfLocale\Enum\ObjectType::Term );

		foreach ( $links as $link ) {
			if ( ! empty( $link->language_slug ) && $link->language_slug === $lang_slug && (int) $link->object_id !== $term_id ) {
				$translated_term = get_term( (int) $link->object_id );

				if ( $translated_term instanceof \WP_Term ) {
					return (int) $link->object_id;
				}
			}
		}

		return $term_id;
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
