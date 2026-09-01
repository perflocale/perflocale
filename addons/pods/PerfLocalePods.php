<?php
/**
 * PerfLocale Pods addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pods Framework integration for PerfLocale.
 *
 * Detects all Pods field types and registers translatable text fields for
 * content sync across translations. Translates pick (relationship) field
 * IDs on the frontend to point to translated posts/terms.
 *
 * Supports Extended post types (meta-based storage). Advanced Content
 * Types with custom table storage are not supported since their data
 * lives outside wp_postmeta.
 *
 * Compatible with Pods 2.x and 3.x.
 */
final class PerfLocalePods implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Field types that contain translatable text content.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_TYPES = [
		'text',
		'paragraph',
		'wysiwyg',
		'code',
		'email',
		'phone',
		'website',
		'oembed',
	];

	/**
	 * Pod object types that use WordPress meta for storage.
	 * Advanced Content Types use custom tables - not supported.
	 *
	 * @var array<int, string>
	 */
	private const SUPPORTED_POD_TYPES = [
		'post_type',
		'taxonomy',
		'user',
		'comment',
		'media',
	];

	/**
	 * Cached pod configurations per post type.
	 *
	 * @var array<string, \Pods\Whatsit\Pod|null>
	 */
	private static array $pod_cache = [];

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
		return 'pods';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Pods';
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
		return [ 'pods/init.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'PODS_VERSION' ) || function_exists( 'pods' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register translatable meta keys.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// Translate pick (relationship) fields on the frontend only.
		if ( ! is_admin() ) {
			add_filter( 'pods_pods_field', [ $this, 'translate_field_value' ], 10, 4 );
		}

		// The Pod-config cache is keyed by post type only; on multisite a
		// shared slug can map to a different Pod per blog, so clear it when
		// the active blog switches (mirrors the core repositories' resets).
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_static_caches' ] );
		}
	}

	/**
	 * Clear the per-post-type Pod-config cache. Hooked to switch_blog on
	 * multisite so one blog's Pod definitions never serve another's.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$pod_cache = [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Detect all translatable Pods fields for a post type.
	 *
	 * Loads the pod definition and iterates fields to find text-type
	 * fields whose values should be translatable.
	 *
	 * @param array<int, string> $keys Existing translatable meta keys.
	 * @param string             $post_type Post type being queried.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		if ( ! function_exists( 'pods_api' ) ) {
			return $keys;
		}

		$pod = $this->get_pod_config( $post_type );

		if ( ! $pod ) {
			return $keys;
		}

		// Only support Extended post types (meta-based storage).
		$pod_type = $pod->get_arg( 'type' );

		if ( ! in_array( $pod_type, self::SUPPORTED_POD_TYPES, true ) ) {
			return $keys;
		}

		$fields = $pod->get_fields();

		if ( empty( $fields ) ) {
			return $keys;
		}

		$new_keys = [];

		foreach ( $fields as $field ) {
			$type       = $field->get_arg( 'type' );
			$field_name = $field->get_arg( 'name' );

			if ( empty( $type ) || empty( $field_name ) ) {
				continue;
			}

			// Text-type fields: register meta key directly.
			if ( in_array( $type, self::TEXT_TYPES, true ) ) {
				$new_keys[] = $field_name;
				continue;
			}

			// Link fields contain translatable 'text' in serialized array.
			if ( $type === 'link' ) {
				$new_keys[] = $field_name;
			}
		}

		if ( ! empty( $new_keys ) ) {
			$keys = array_unique( array_merge( $keys, $new_keys ) );
		}

		return $keys;
	}

	/**
	 * Machine-translatable Pods keys: LEAF text/paragraph/wysiwyg fields
	 * only (code/email/phone/website/oembed/link excluded — not human prose).
	 * Gated by the mt_meta_custom_fields setting.
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

		if ( ! function_exists( 'pods_api' ) ) {
			return $keys;
		}

		$pod = pods_api()->load_pod( [ 'name' => $post_type ], false );

		if ( empty( $pod ) || ! is_object( $pod ) || ! method_exists( $pod, 'get_fields' ) ) {
			return $keys;
		}

		$mt_types = [ 'text', 'paragraph', 'wysiwyg' ];
		$new_keys = [];

		foreach ( (array) $pod->get_fields() as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'get_type' ) ) {
				continue;
			}

			$type = (string) $field->get_type();
			$name = (string) $field->get_arg( 'name' );

			if ( $name !== '' && in_array( $type, $mt_types, true ) ) {
				$new_keys[] = $name;
			}
		}

		return $new_keys === [] ? $keys : array_values( array_unique( array_merge( $keys, $new_keys ) ) );
	}

	/**
	 * Route Pods plain / paragraph text fields to the provider's text mode so
	 * the stored translation isn't entity-escaped; wysiwyg fields keep the
	 * inherited 'html' default. Only ever UPGRADES a key to text.
	 *
	 * @param string $format    Inherited format ('html' default).
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @return string 'text' for a plain/paragraph field, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		if ( 'text' === $format ) {
			return $format;
		}

		$set = $this->plaintext_mt_key_set( $post_type );

		return isset( $set[ $key ] ) ? 'text' : $format;
	}

	/**
	 * The set of Pods plain-text (text / paragraph, NOT wysiwyg) field names for
	 * a post type, built once per request. Blog-keyed for multisite; bounded.
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

		if ( function_exists( 'pods_api' ) ) {
			$pod = pods_api()->load_pod( [ 'name' => $post_type ], false );

			if ( is_object( $pod ) && method_exists( $pod, 'get_fields' ) ) {
				foreach ( (array) $pod->get_fields() as $field ) {
					if ( ! is_object( $field ) || ! method_exists( $field, 'get_type' ) ) {
						continue;
					}

					$type = (string) $field->get_type();
					$name = (string) $field->get_arg( 'name' );

					if ( $name !== '' && in_array( $type, [ 'text', 'paragraph' ], true ) ) {
						$set[ $name ] = true;
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
	 * Translate Pods field values on the frontend.
	 *
	 * Intercepts relationship (pick) field values and replaces
	 * post/term IDs with their translated counterparts for the
	 * current language.
	 *
	 * @param mixed  $value Field value.
	 * @param array  $row Current row data.
	 * @param object $params Params object passed to field().
	 * @param \Pods  $obj Current Pods object.
	 * @return mixed
	 */
	public function translate_field_value( mixed $value, mixed $row, mixed $params, mixed $obj ): mixed {
		// Only translate pick (relationship) fields.
		if ( ! is_object( $params ) || ! isset( $params->name ) ) {
			return $value;
		}

		if ( empty( $value ) ) {
			return $value;
		}

		// Resolve the language first: nothing is translated on the default
		// language, so bail BEFORE the (more expensive) Pods field-config
		// lookup below — matching the ACF and Meta Box addons' ordering.
		$lang_slug = $this->get_current_slug();

		if ( $lang_slug === '' ) {
			return $value;
		}

		$plugin  = \PerfLocale\Plugin::get_instance();
		$default = $plugin->has( 'router' ) ? $plugin->get( 'router' )->get_default_language() : null;

		if ( $default && $lang_slug === $default->slug ) {
			return $value;
		}

		// Get the field configuration from the Pods object.
		$field_data = null;

		if ( is_object( $obj ) && method_exists( $obj, 'fields' ) ) {
			$field_data = $obj->fields( $params->name );
		}

		// Pods' fields() can return a Field object, an array, null, or
		// false depending on field state. Only proceed when we have
		// something usable to avoid downstream method-on-null errors.
		if ( ! is_object( $field_data ) && ! is_array( $field_data ) ) {
			return $value;
		}

		// Get field type - Pods Field objects use get_arg(), arrays use direct access.
		$field_type = null;

		if ( is_object( $field_data ) && method_exists( $field_data, 'get_arg' ) ) {
			$field_type = $field_data->get_arg( 'type' );
		} elseif ( is_array( $field_data ) ) {
			$field_type = $field_data['type'] ?? null;
		}

		if ( $field_type !== 'pick' ) {
			return $value;
		}

		// Get the pick_object to determine what the field relates to.
		$pick_object = null;

		if ( is_object( $field_data ) && method_exists( $field_data, 'get_arg' ) ) {
			$pick_object = $field_data->get_arg( 'pick_object' );
		} elseif ( is_array( $field_data ) ) {
			$pick_object = $field_data['pick_object'] ?? null;
		}

		if ( empty( $pick_object ) ) {
			return $value;
		}

		// Determine translation strategy based on pick_object.
		if ( str_starts_with( $pick_object, 'post_type-' ) || $pick_object === 'media' ) {
			return $this->translate_post_references( $value, $lang_slug );
		}

		if ( str_starts_with( $pick_object, 'taxonomy-' ) ) {
			return $this->translate_term_references( $value, $lang_slug );
		}

		// user, role, site, network, table, comment - not translatable.
		return $value;
	}

	/**
	 * Get cached pod configuration for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return \Pods\Whatsit\Pod|null Pod object or null if not found.
	 */
	private function get_pod_config( string $post_type ): ?object {
		if ( array_key_exists( $post_type, self::$pod_cache ) ) {
			return self::$pod_cache[ $post_type ];
		}

		// Pods can be partially loaded (e.g. during upgrade). Guard the
		// function_exists check so a missing pods_api() doesn't fatal here.
		if ( ! function_exists( 'pods_api' ) ) {
			self::$pod_cache[ $post_type ] = null;
			return null;
		}

		$pod = pods_api()->load_pod(
			[
				'name' => $post_type,
			]
		);

		self::$pod_cache[ $post_type ] = ( $pod && ! is_wp_error( $pod ) ) ? $pod : null;

		return self::$pod_cache[ $post_type ];
	}

	/**
	 * Resolve a post's current-language sibling, but only when the visitor is
	 * allowed to see it.
	 *
	 * `pods_pods_field` is filtered on the FRONT END, so a pick field whose
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
	 * Translate post reference IDs to their current-language equivalents.
	 *
	 * Handles arrays of IDs, arrays of associative arrays (output=arrays),
	 * WP_Post objects, and single IDs. A sibling the visitor may not see is
	 * skipped — see {@see self::public_translation_id()}.
	 *
	 * @param mixed  $value Post reference value(s).
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_post_references( mixed $value, string $lang_slug ): mixed {
		// Array of values (most common for pick fields).
		if ( is_array( $value ) ) {
			$translated = [];

			foreach ( $value as $key => $item ) {
				// Array of associative arrays — Pods returns each entry as a row map keyed by ID, post_title, … when its output is set to 'arrays'.
				if ( is_array( $item ) && isset( $item['ID'] ) ) {
					$item_id     = (int) $item['ID'];
					$translation = $this->public_translation_id( $item_id, $lang_slug );

					if ( $translation ) {
						$translated_post = get_post( $translation, ARRAY_A );

						if ( is_array( $translated_post ) ) {
							$translated[] = $translated_post;
							continue;
						}
					}

					$translated[] = $item;
					continue;
				}

				// WP_Post objects.
				if ( is_object( $item ) && isset( $item->ID ) ) {
					$item_id     = (int) $item->ID;
					$translation = $this->public_translation_id( $item_id, $lang_slug );

					if ( $translation ) {
						$translated_post = get_post( $translation );

						if ( $translated_post instanceof \WP_Post ) {
							$translated[] = $translated_post;
							continue;
						}
					}

					$translated[] = $item;
					continue;
				}

				// Single ID in array.
				$item_id      = (int) $item;
				$translation  = $this->public_translation_id( $item_id, $lang_slug );
				$translated[] = $translation ?? $item_id;
			}

			return $translated;
		}

		// Single WP_Post object.
		if ( is_object( $value ) && isset( $value->ID ) ) {
			$item_id     = (int) $value->ID;
			$translation = $this->public_translation_id( $item_id, $lang_slug );

			if ( $translation ) {
				$translated_post = get_post( $translation );

				if ( $translated_post instanceof \WP_Post ) {
					return $translated_post;
				}
			}

			return $value;
		}

		// Single ID.
		$item_id = (int) $value;

		if ( $item_id <= 0 ) {
			return $value;
		}

		$translation = $this->public_translation_id( $item_id, $lang_slug );

		return $translation ?? $value;
	}

	/**
	 * Translate term reference IDs to their current-language equivalents.
	 *
	 * Handles arrays of IDs, arrays of associative arrays, WP_Term objects,
	 * and single IDs.
	 *
	 * @param mixed  $value Term reference value(s).
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_term_references( mixed $value, string $lang_slug ): mixed {
		// Array of values.
		if ( is_array( $value ) ) {
			$translated = [];

			foreach ( $value as $item ) {
				// Array of associative arrays: [{term_id => 1, name => '...', ...}]
				if ( is_array( $item ) && isset( $item['term_id'] ) ) {
					$term_id = (int) $item['term_id'];
					$new_id  = $term_id > 0 ? $this->get_translated_term_id( $term_id, $lang_slug ) : $term_id;

					if ( $new_id !== $term_id ) {
						$translated_term = get_term( $new_id, '', ARRAY_A );

						if ( is_array( $translated_term ) ) {
							$translated[] = $translated_term;
							continue;
						}
					}

					$translated[] = $item;
					continue;
				}

				// WP_Term objects.
				if ( is_object( $item ) && isset( $item->term_id ) ) {
					$term_id = (int) $item->term_id;
					$new_id  = $term_id > 0 ? $this->get_translated_term_id( $term_id, $lang_slug ) : $term_id;

					if ( $new_id !== $term_id ) {
						$translated_term = get_term( $new_id );

						if ( $translated_term instanceof \WP_Term ) {
							$translated[] = $translated_term;
							continue;
						}
					}

					$translated[] = $item;
					continue;
				}

				// Single ID in array.
				$term_id      = (int) $item;
				$new_id       = $term_id > 0 ? $this->get_translated_term_id( $term_id, $lang_slug ) : $term_id;
				$translated[] = $new_id;
			}

			return $translated;
		}

		// Single WP_Term object.
		if ( is_object( $value ) && isset( $value->term_id ) ) {
			$term_id = (int) $value->term_id;
			$new_id  = $term_id > 0 ? $this->get_translated_term_id( $term_id, $lang_slug ) : $term_id;

			if ( $new_id !== $term_id ) {
				$translated_term = get_term( $new_id );

				if ( $translated_term instanceof \WP_Term ) {
					return $translated_term;
				}
			}

			return $value;
		}

		// Single ID.
		$term_id = (int) $value;

		if ( $term_id <= 0 ) {
			return $value;
		}

		return $this->get_translated_term_id( $term_id, $lang_slug );
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
