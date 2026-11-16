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
	 * The field-map producer, once loaded.
	 *
	 * @var \PerfLocaleAcfFields|null
	 */
	private ?\PerfLocaleAcfFields $fields = null;

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
		add_filter( 'perflocale/fields/map', [ $this, 'produce_map' ], 10, 4 );

		// First on the hook, so the value recorded is the one stored before this save; released when the save ends.
		add_filter( 'acf/pre_update_value', [ $this, 'snapshot_before_update' ], 1, 4 );
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
	 * `perflocale/translation/excluded_meta_keys`: ACF password values, and values whose field does not resolve, stay
	 * out of a new translation.
	 *
	 * @param array<int, string> $excluded  Meta keys that are not copied.
	 * @param int                $source_id Post the translation is copied from.
	 * @return array<int, string>
	 */
	public function exclude_password_fields( array $excluded, int $source_id ): array {
		return $source_id > 0 && function_exists( 'acf_get_field' ) ? $this->fields()->exclude_password_fields( $excluded, $source_id ) : $excluded;
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
	 * `perflocale/translatable_meta_keys`: the ACF text leaves and row containers of a post type.
	 *
	 * @param array<int, string> $keys      Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_acf_meta_keys( array $keys, string $post_type ): array {
		return $this->fields()->add_acf_meta_keys( $keys, $post_type );
	}

	/**
	 * `perflocale/sync_fields/for_post`: expand row-0 keys to every row of the source post.
	 *
	 * @param array<int, string> $fields    Meta keys queued for sync.
	 * @param int                $source_id Source post being synced from.
	 * @return array<int, string>
	 */
	public function expand_repeating_rows( array $fields, int $source_id ): array {
		return $this->fields()->expand_repeating_rows( $fields, $source_id );
	}

	/**
	 * `perflocale/mt/translatable_meta_keys`: the machine-translatable ACF leaves.
	 *
	 * @param array<int, string> $keys           Meta keys.
	 * @param string             $post_type      Post type.
	 * @param int                $post_id        Source post ID (0 = type-level).
	 * @param int                $translation_id Translation post ID (0 = unknown).
	 * @return array<int, string>
	 */
	public function add_mt_meta_keys( array $keys, string $post_type, int $post_id = 0, int $translation_id = 0 ): array {
		return $this->fields()->add_mt_meta_keys( $keys, $post_type, $post_id, $translation_id );
	}

	/**
	 * `perflocale/mt/meta_key_format`: 'text' for ACF text and textarea keys.
	 *
	 * @param string $format    Inherited format.
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @param mixed  $source_id Source post ID (0 from 3-argument callers).
	 * @return string
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type, mixed $source_id = 0 ): string {
		return 'html' !== $format ? $format : $this->fields()->mt_meta_key_format( $format, $key, $post_type, $source_id );
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
	 * `acf/pre_update_value`: snapshot a field before a save writes it; a page view loads nothing (theme update_field()).
	 *
	 * @param mixed $check   Short-circuit value, returned unchanged.
	 * @param mixed $value   Value about to be saved.
	 * @param mixed $post_id ACF post ID.
	 * @param mixed $field   Field settings.
	 * @return mixed
	 */
	public function snapshot_before_update( mixed $check = null, mixed $value = null, mixed $post_id = 0, mixed $field = null ): mixed {
		return \PerfLocale\Translation\SyncMeta::is_page_view() ? $check : $this->fields()->snapshot_before_update( $check, $value, $post_id, $field );
	}

	/**
	 * The field-map producer and key lists, loaded on first use (never on a page view).
	 *
	 * @return \PerfLocaleAcfFields
	 */
	private function fields(): \PerfLocaleAcfFields {
		require_once __DIR__ . '/PerfLocaleAcfFields.php';

		return $this->fields ??= new \PerfLocaleAcfFields();
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
