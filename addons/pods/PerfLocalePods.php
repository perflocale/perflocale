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
		// Register translatable meta keys.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// The value of a password field is not copied into a new translation.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_password_fields' ], 10, 2 );

		// Translate pick (relationship) fields on the frontend only.
		if ( ! is_admin() ) {
			add_filter( 'pods_pods_field', [ $this, 'translate_field_value' ], 10, 4 );
			add_filter( 'pods_pods_field_related_output_type', [ $this, 'map_display_source' ], PHP_INT_MAX, 4 );
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
	 * `perflocale/translation/excluded_meta_keys`: the values of the source
	 * post type's Pods password fields stay out of a new translation.
	 *
	 * A password field is a password by its type, whatever its name, so the
	 * credential-name patterns of the meta copy do not catch it. Pods stores
	 * a field of a meta-based pod as the post meta row of the field's name.
	 * Runs only when a translation is created.
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
	 * only (code/email/phone/website/oembed/link excluded - not human prose).
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
	 * The value is mapped for the outputs that carry IDs: arrays (Pods'
	 * default), ids and objects. A form read (in_form), a display read
	 * (the formatted text the Pods shortcode and blocks print) and the
	 * names, pods and find outputs are returned as they are: a form saves
	 * what it reads back to the item, and the others carry no ID to map.
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
	 * Map the related items a display read formats.
	 *
	 * A display read ($pod->display(), a magic tag, the [pods] shortcode and
	 * the Pods blocks) formats a relationship field before pods_pods_field
	 * runs. It formats the rows Pods keeps in the item's row under the field
	 * name, the arrays output. This filter runs at the start of that read:
	 * it maps those rows to the current language, after reading the field
	 * once with the arrays output when Pods has not read it yet (the read the
	 * display would make). Pods then formats and escapes the mapped rows as
	 * it does any rows, and translate_field_value() puts the item's own rows
	 * back at the end of the read. The output type is returned unchanged.
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
