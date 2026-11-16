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
 * MetaBox integration: field map, field sync, machine translation and front-end reference fields.
 */
final class PerfLocaleMetabox implements \PerfLocale\Addon\AddonInterface {

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
	 * The translated-page hooks, once served.
	 *
	 * @var \PerfLocaleMetaboxTranslatedHooks|null
	 */
	private ?\PerfLocaleMetaboxTranslatedHooks $hooks = null;

	/**
	 * The field-map producer, once loaded.
	 *
	 * @var \PerfLocaleMetaboxFields|null
	 */
	private ?\PerfLocaleMetaboxFields $fields = null;

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
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 3 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );
		add_filter( 'perflocale/fields/map', [ $this, 'produce_map' ], 10, 4 );
		// A write with no snapshot taken has nothing to release: do not load the class for it.
		add_action(
			'wp_after_insert_post',
			static function ( mixed $post_id ): void {
				if ( class_exists( \PerfLocale\Translation\Fields\FieldSnapshot::class, false ) ) {
					\PerfLocale\Translation\Fields\FieldSnapshot::release_post( $post_id );
				}
			},
			PHP_INT_MAX,
			1
		);

		// Meta Box deletes the row of a field saved empty, so record a person's clear where Meta Box still knows it.
		// The seed would otherwise refill it; rwmb_before_save_post fires after the form's nonce check.
		add_filter( 'rwmb_after_save_field', [ $this, 'record_clear_on_save' ], 10, 5 );
		add_action( 'rwmb_flush_data', [ $this, 'record_clear_on_set' ], 10, 2 );
		add_action( 'rwmb_before_save_post', [ \PerfLocale\Translation\ContentSync::class, 'open_person_edit' ], 10, 1 );

		// Frontend Submission checks its nonce after core's post_updated, so keep the pre-save row first.
		add_action( 'rwmb_frontend_before_save_post', [ $this, 'note_front_end_row' ], 10, 1 );
		add_filter( 'rwmb_rest_value', [ $this, 'record_clear_on_rest' ], PHP_INT_MAX, 4 );

		// Password fields and group password sub-fields are never copied or seeded into a translation.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_password_fields' ], 10, 2 );
		add_action( 'perflocale/translation/created', [ $this, 'drop_group_password_values' ], 5, 2 );
		add_filter( 'perflocale/sync/seed_meta_value', [ $this, 'seed_without_password_sub_fields' ], 10, 3 );

		if ( ! is_admin() ) {
			add_filter( 'rwmb_get_value', [ $this, 'translate_field_value' ], 10, 4 );

			// Translated-page hooks attach on a detected or imposed language (cron and WP-CLI never detect one).
			if ( $this->get_current_slug() === '' || $this->translated_slug() !== '' ) {
				add_action( 'perflocale/language/detected', [ $this, 'serve_translated_request' ], 20 );
			}

			add_action( 'perflocale/language/overridden', [ $this, 'serve_translated_request' ], 20 );
		}

		// The field cache is keyed by post type only: clear it per blog (outside Bootstrap's switch_blog registry).
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_static_caches' ] );
		}
	}

	/**
	 * `rwmb_frontend_before_save_post`: keep the edited post's pre-save row for ContentSync::note_pre_save_row().
	 *
	 * @param mixed $form The form's post object (MBFS\Post), whose `post_id` is the post edited.
	 * @return void
	 */
	public function note_front_end_row( mixed $form = null ): void {
		$post_id = is_object( $form ) ? ( get_object_vars( $form )['post_id'] ?? 0 ) : 0;

		\PerfLocale\Translation\ContentSync::note_pre_save_row( $post_id );
	}

	/**
	 * Clear the per-post-type field-definition cache on switch_blog.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$field_cache = [];
	}

	/**
	 * Serve the translated-page hooks on a request in a non-default language; otherwise load nothing.
	 *
	 * @return void
	 */
	public function serve_translated_request(): void {
		if ( $this->translated_slug() === '' ) {
			return;
		}

		require_once __DIR__ . '/PerfLocaleMetaboxTranslatedHooks.php';
		( $this->hooks ??= new \PerfLocaleMetaboxTranslatedHooks( $this->translated( ... ), $this->translated_slug( ... ) ) )->serve();
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
	 * `perflocale/translation/excluded_meta_keys`: keep top-level Meta Box password field values out of a new translation.
	 *
	 * A password is known by its type, not its ID; group sub-fields are handled by drop_group_password_values().
	 *
	 * @param array<int, string> $excluded  Meta keys that are not copied.
	 * @param int                $source_id Post the translation is copied from.
	 * @return array<int, string>
	 */
	public function exclude_password_fields( array $excluded, int $source_id ): array {
		return $this->fields()->exclude_password_fields( $excluded, $source_id );
	}

	/**
	 * `perflocale/translation/created`: rewrite the new translation's copied group rows without password sub-fields.
	 *
	 * A group is one meta row holding all its sub-fields, so the key exclusion cannot leave one out.
	 *
	 * @param int|mixed    $new_id New translation's ID.
	 * @param string|mixed $type   Object type, 'post' or 'term'.
	 * @return void
	 */
	public function drop_group_password_values( mixed $new_id = 0, mixed $type = '' ): void {
		if ( 'post' === $type ) {
			$this->passwords()->drop_group_password_values( $new_id, $type );
		}
	}

	/**
	 * `perflocale/sync/seed_meta_value`: seed a Meta Box group row without its password sub-fields.
	 *
	 * @param mixed $value     Row as read from the source.
	 * @param mixed $key       Meta key.
	 * @param mixed $target_id Translation that receives the row.
	 * @return mixed
	 */
	public function seed_without_password_sub_fields( mixed $value, mixed $key = '', mixed $target_id = 0 ): mixed {
		return is_array( $value ) ? $this->passwords()->seed_without_password_sub_fields( $value, $key, $target_id ) : $value;
	}

	/**
	 * The password handling of copied and seeded group rows (writes only), loaded on first use.
	 *
	 * @return \PerfLocaleMetaboxPasswords
	 */
	private function passwords(): \PerfLocaleMetaboxPasswords {
		require_once __DIR__ . '/PerfLocaleMetaboxPasswords.php';

		return new \PerfLocaleMetaboxPasswords( $this->get_fields_for_post_type( ... ), self::is_post_meta_storage( ... ) );
	}

	/**
	 * `perflocale/translatable_meta_keys`: the top-level text and group fields that store post meta.
	 *
	 * @param array<int, string> $keys      Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		return $this->fields()->add_meta_keys( $keys, $post_type );
	}

	/**
	 * `perflocale/mt/translatable_meta_keys`: leaf text, textarea and wysiwyg fields only.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @param int                $post_id   Source post ID (0 = type-level).
	 * @return array<int, string>
	 */
	public function add_mt_meta_keys( array $keys, string $post_type, int $post_id = 0 ): array {
		return $this->fields()->add_mt_meta_keys( $keys, $post_type, $post_id );
	}

	/**
	 * `perflocale/mt/meta_key_format`: 'text' for Meta Box text and textarea fields.
	 *
	 * @param string $format    Inherited format ('html' default).
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @return string
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		return 'html' !== $format ? $format : $this->fields()->mt_meta_key_format( $format, $key, $post_type );
	}

	/**
	 * `perflocale/fields/map`: this host's descriptors (the map is never built on a page view).
	 *
	 * @param mixed $map       The map being built.
	 * @param mixed $object_id Object id.
	 * @param mixed $kind      Object kind.
	 * @param mixed $subtype   Post type.
	 * @return mixed
	 */
	public function produce_map( mixed $map, mixed $object_id = 0, mixed $kind = '', mixed $subtype = '' ): mixed {
		return $this->fields()->produce_map( $map, $object_id, $kind, $subtype );
	}

	/**
	 * The field-map producer and key lists, loaded on first use (never on a page view).
	 *
	 * @return \PerfLocaleMetaboxFields
	 */
	private function fields(): \PerfLocaleMetaboxFields {
		require_once __DIR__ . '/PerfLocaleMetaboxFields.php';

		return $this->fields ??= new \PerfLocaleMetaboxFields( $this->get_fields_for_post_type( ... ), self::is_post_meta_storage( ... ) );
	}

	/**
	 * `rwmb_after_save_field`: snapshot the old value; record an empty save of a field that held a value, and forget it once it holds one again.
	 *
	 * @param mixed $passthru  Filter value, returned unchanged.
	 * @param mixed $field     Field settings.
	 * @param mixed $new_value Value just saved.
	 * @param mixed $old_value Value before the save.
	 * @param mixed $object_id Object ID.
	 * @return mixed
	 */
	public function record_clear_on_save( mixed $passthru = null, mixed $field = null, mixed $new_value = null, mixed $old_value = null, mixed $object_id = 0 ): mixed {
		if ( is_array( $field ) && ! \PerfLocale\Translation\SyncMeta::is_page_view() ) {
			$this->fields()->snapshot_old_value( $field, $old_value, $object_id );
		}

		// Meta Box's emptiness rule: only '' and [] delete the row; '0' is a value.
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
	 * `rwmb_flush_data`, fired by rwmb_set_meta(): no values are passed, so the stored rows decide.
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
	 * The meta key ContentSync would seed for this field on this post, or '' when it would not.
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

		return isset( $this->fields()->seed_key_set( $post_type )[ $key ] ) ? $key : '';
	}

	/**
	 * Translate MetaBox post, taxonomy_advanced and group reference values on the frontend.
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

		$plugin  = \PerfLocale\Plugin::get_instance();
		$default = $plugin->has( 'router' ) ? $plugin->get( 'router' )->get_default_language() : null;

		if ( $default && $lang_slug === $default->slug ) {
			return $value;
		}

		$type = $field['type'];

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
	 * A custom-table field's value is not in wp_postmeta; a postmeta row with its ID is unrelated data.
	 *
	 * @param array<string, mixed> $field Field settings.
	 * @return bool
	 */
	private static function is_post_meta_storage( array $field ): bool {
		return ! isset( $field['storage'] ) || $field['storage'] instanceof \RWMB_Post_Storage;
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
