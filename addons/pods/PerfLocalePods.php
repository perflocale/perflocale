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
 * Pods Framework integration: field sync, machine translation and front-end relationship fields.
 *
 * Meta-based pods only: Advanced Content Types keep their data in custom tables.
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
	 * The front-end field reads, built on the first Pods field read.
	 *
	 * @var \PerfLocalePodsDisplay|null
	 */
	private ?\PerfLocalePodsDisplay $display = null;

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
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_password_fields' ], 10, 2 );

		if ( ! is_admin() ) {
			add_filter( 'pods_pods_field', [ $this, 'translate_field_value' ], 10, 4 );
			add_filter( 'pods_pods_field_related_output_type', [ $this, 'map_display_source' ], PHP_INT_MAX, 4 );
		}

		// The Pod-config cache is keyed by post type only: clear it per blog (outside Bootstrap's switch_blog registry).
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_static_caches' ] );
		}
	}

	/**
	 * Clear the per-post-type Pod-config cache on switch_blog.
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
	 * `perflocale/translation/excluded_meta_keys`: keep the source's Pods password field values out of a new translation.
	 *
	 * A password is known by its field type, not its name, so the credential-name patterns miss it.
	 *
	 * @param array<int, string> $excluded  Meta keys that are not copied.
	 * @param int                $source_id Post the translation is copied from.
	 * @return array<int, string>
	 */
	public function exclude_password_fields( array $excluded, int $source_id ): array {
		$post_type = $source_id > 0 ? get_post_type( $source_id ) : false;

		if ( ! is_string( $post_type ) || ! function_exists( 'pods_api' ) ) {
			return $excluded;
		}

		$pod = $this->get_pod_config( $post_type );

		if ( ! $pod || ! in_array( $pod->get_arg( 'type' ), self::SUPPORTED_POD_TYPES, true ) ) {
			return $excluded;
		}

		foreach ( (array) $pod->get_fields() as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'get_arg' ) ) {
				continue;
			}

			$name = (string) $field->get_arg( 'name' );

			if ( '' !== $name && 'password' === (string) $field->get_arg( 'type' ) ) {
				$excluded[] = $name;
			}
		}

		return $excluded;
	}

	/**
	 * Detect all translatable Pods fields for a post type.
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
	 * Machine-translatable Pods keys: text, paragraph and wysiwyg fields only (the other types are not prose).
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
	 * Route Pods text and paragraph fields to the provider's text mode; only ever turns a key into text.
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
	 * Pods text and paragraph (not wysiwyg) field names of a post type, memoised per blog.
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
	 * Translate Pods relationship field values on the frontend.
	 *
	 * Only the arrays, ids and objects outputs are mapped: a form read saves what it reads back to the item.
	 *
	 * @param mixed  $value Field value.
	 * @param array  $row Current row data.
	 * @param object $params Params object passed to field().
	 * @param \Pods  $obj Current Pods object.
	 * @return mixed
	 */
	public function translate_field_value( mixed $value, mixed $row, mixed $params, mixed $obj ): mixed {
		return $this->display()->translate_field_value( $value, $row, $params, $obj );
	}

	/**
	 * Map the related items a display read formats; the output type is returned unchanged.
	 *
	 * A display read formats the item's cached rows before pods_pods_field runs, so they are mapped here and restored there.
	 *
	 * @param mixed $output How Pods outputs the related items.
	 * @param mixed $row    Current row.
	 * @param mixed $params Params object passed to field().
	 * @param mixed $obj    Current Pods object.
	 * @return mixed
	 */
	public function map_display_source( mixed $output, mixed $row, mixed $params, mixed $obj ): mixed {
		return $this->display()->map_display_source( $output, $row, $params, $obj );
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

		// Pods can be partially loaded, for example during an upgrade.
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
	 * The front-end field reads, loaded and built on first use.
	 *
	 * @return \PerfLocalePodsDisplay
	 */
	private function display(): \PerfLocalePodsDisplay {
		if ( ! class_exists( 'PerfLocalePodsDisplay', false ) ) {
			require_once __DIR__ . '/PerfLocalePodsDisplay.php';
		}

		return $this->display ??= new \PerfLocalePodsDisplay();
	}
}
