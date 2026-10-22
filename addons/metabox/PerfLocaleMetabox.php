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

		// That nonce check comes after core's post_updated on the Frontend
		// Submission form, so the post's row as it was before the save is
		// kept just before the form updates the post.
		add_action( 'rwmb_frontend_before_save_post', [ $this, 'note_front_end_row' ], 10, 1 );
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

			// A translated request also gets the translated-page hooks
			// (PerfLocaleMetaboxTranslated) once its language is detected. A
			// request already known here to be in the default language (the
			// language comes from the URL) attaches nothing more.
			if ( $this->get_current_slug() === '' || $this->translated_slug() !== '' ) {
				add_action( 'perflocale/language/detected', [ $this, 'serve_translated_request' ], 20 );
			}
		}

		// The field-definition cache is keyed by post type only; on multisite
		// a shared slug can carry different Meta Box fields per blog, so clear
		// it when the active blog switches (mirrors the core repositories).
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_static_caches' ] );
		}
	}

	/**
	 * `rwmb_frontend_before_save_post`: the Frontend Submission form is about
	 * to update the post it edits. Its row as it is now is the row before the
	 * save, which a save that clears the post's stale mark compares the post
	 * columns with (ContentSync::note_pre_save_row()). A form that creates a
	 * post has no post yet and keeps nothing.
	 *
	 * @param mixed $form The form's post object (MBFS\Post), whose `post_id` is the post edited.
	 * @return void
	 */
	public function note_front_end_row( mixed $form = null ): void {
		$post_id = is_object( $form ) ? ( get_object_vars( $form )['post_id'] ?? 0 ) : 0;

		\PerfLocale\Translation\ContentSync::note_pre_save_row( $post_id );
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
	 * Hand a request in a language other than the default one to
	 * PerfLocaleMetaboxTranslated, which attaches the translated-page hooks.
	 * A request in the default language, or without a language, loads and
	 * attaches nothing.
	 *
	 * @return void
	 */
	public function serve_translated_request(): void {
		if ( $this->translated_slug() === '' ) {
			return;
		}

		$this->translated()->serve();
	}

	/**
	 * The current language slug when it is not the default language.
	 *
	 * @return string The slug, or an empty string for the default language or no language.
	 */
	private function translated_slug(): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return '';
		}

		/**
		 * The language router service.
		 *
		 * @var \PerfLocale\Router\LanguageRouter $router
		 */
		$router    = $plugin->get( 'router' );
		$lang_slug = $router->get_current_slug();

		if ( $lang_slug === '' ) {
			return '';
		}

		$default = $router->get_default_language();

		if ( null !== $default && isset( $default->slug ) && $lang_slug === $default->slug ) {
			return '';
		}

		return $lang_slug;
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
	 * translated, and group fields store serialized sub-structures - all
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

		// Translate post and taxonomy_advanced reference fields, and the
		// reference sub-fields inside groups.
		if ( $type !== 'post' && $type !== 'taxonomy_advanced' && $type !== 'group' ) {
			return $value;
		}

		return $this->translated()->translate_reference_value( $value, $field, $lang_slug );
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
	 * The translated-request class serving this addon, loaded on first use.
	 *
	 * @return \PerfLocaleMetaboxTranslated
	 */
	private function translated(): \PerfLocaleMetaboxTranslated {
		if ( ! class_exists( 'PerfLocaleMetaboxTranslated', false ) ) {
			require_once __DIR__ . '/PerfLocaleMetaboxTranslated.php';
		}

		return \PerfLocaleMetaboxTranslated::of( $this )
			?? \PerfLocaleMetaboxTranslated::start( $this, $this->get_manager( ... ), $this->get_repo( ... ) );
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
