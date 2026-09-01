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
 * Advanced Custom Fields integration for PerfLocale.
 *
 * Detects ALL ACF field types and registers translatable fields for content
 * sync across translations. Handles repeaters, flexible content, groups,
 * and nested structures recursively.
 *
 * Compatible with ACF 5.x, 6.x, and ACF Pro.
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
		// Register translatable meta keys (used by content sync across translations).
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_acf_meta_keys' ], 10, 2 );

		// Expand repeater / flexible-content sub-field keys to every row of the
		// source post at sync time (the static list above only knows row 0).
		add_filter( 'perflocale/sync_fields/for_post', [ $this, 'expand_repeating_rows' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 3 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// Translate reference fields on the frontend to point to translated posts.
		add_filter( 'acf/format_value/type=relationship', [ $this, 'translate_relationship' ], 20, 3 );
		add_filter( 'acf/format_value/type=post_object', [ $this, 'translate_post_object' ], 20, 3 );

		// Translate taxonomy fields on the frontend to point to translated terms.
		add_filter( 'acf/format_value/type=taxonomy', [ $this, 'translate_taxonomy' ], 20, 3 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		// No user-configurable settings — ACF field auto-detection runs
		// unconditionally on every translatable post type, which is the
		// expected behaviour for an integration addon. Previously this
		// returned an `acf_auto_detect` checkbox that was declared but
		// never actually consumed by add_acf_meta_keys() (dead UI).
		// Empty return means the auto-generated settings subtab won't
		// surface this addon — there's nothing to configure.
		return [];
	}

	/**
	 * Detect all translatable ACF fields for a post type.
	 *
	 * Recursively walks field groups to find text-type fields inside
	 * repeaters, flexible content layouts, and groups.
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
	 * Expand repeater / flexible-content sub-field keys to every row of the
	 * source post.
	 *
	 * collect_translatable_fields() can only register row 0 — the meta-key set
	 * is post-type-scoped and can't know a given post's row count. ContentSync
	 * copies keys verbatim, so without this rows 1+ of every repeater/flexible
	 * field would be dropped from translations. The source post id is known
	 * here, so read its real row structure and add the remaining rows' keys.
	 *
	 * @param array<int, string> $fields    Meta keys queued for sync.
	 * @param int                $source_id Source post being synced from.
	 * @return array<int, string>
	 */
	public function expand_repeating_rows( array $fields, int $source_id ): array {
		if ( $source_id <= 0 || empty( $fields ) ) {
			return $fields;
		}

		// Worklist expansion. A nested template like slides_0_buttons_0_label
		// carries a "_0_" per repeater level; the old single-pass code found
		// only the FIRST occurrence (expanding outer rows while pinning every
		// inner row to 0) and never re-scanned the keys it generated — so
		// inner rows 1..N and outer×inner combinations were silently dropped,
		// leaving translations with untranslated or structurally-missing rows.
		// Here each queue item carries a search OFFSET so the NEXT level's
		// "_0_" is expanded in turn; generated keys re-enter the queue until no
		// unexpanded "_0_" remains. The (key|offset) dedup makes row-0 (whose
		// concretised key equals the template) still advance to the inner level
		// instead of colliding with the original.
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
				continue; // Fully concrete already — the original is re-added below.
			}

			$prefix    = substr( $key, 0, $pos );  // Concrete container path.
			$suffix    = substr( $key, $pos + 3 ); // Sub-key after this "_0_".
			$container = get_post_meta( $source_id, $prefix, true );

			// Repeaters store the row count as an int; flexible content stores
			// an array of layout names (one per row). Row 0 always exists as
			// the source template; rows 1..N-1 exist when the container reports
			// them (int count / array length).
			$count = is_array( $container ) ? count( $container ) : (int) $container;

			for ( $i = 0; $i < max( 1, $count ); $i++ ) {
				$candidate = $prefix . '_' . $i . '_' . $suffix;
				$next_off  = strlen( $prefix ) + 1 + strlen( (string) $i ) + 1;
				$is_leaf   = strpos( $candidate, '_0_', $next_off ) === false;

				if ( $is_leaf ) {
					// Concrete leaf: keep row 0 (template) always; a
					// flexible-content row only has this sub-key when its
					// layout contains the field, so gate real rows on existence.
					if ( $i === 0 || metadata_exists( 'post', $source_id, $candidate ) ) {
						$out[ $candidate ] = true;
					}
				} else {
					// Intermediate level: the row exists (i < count), recurse
					// to expand the next "_0_" past the part just concretised.
					$queue[] = array( $candidate, $next_off );
				}
			}
		}

		// Preserve the original contract: every input template key is returned
		// even when a nested-leaf existence check would otherwise drop it.
		foreach ( $fields as $key ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Machine-translatable ACF meta keys: LEAF text fields only.
	 *
	 * Deliberately narrower than add_acf_meta_keys(): url/email/link/oembed
	 * values must not be machine-translated, and container keys (repeater
	 * counts, flexible-content layout arrays, group parents) are structural.
	 * Row-0 sub-key templates are expanded to the post's actual rows via
	 * expand_repeating_rows() when a concrete post is given. Gated by the
	 * mt_meta_custom_fields setting (opt-in — cost scales with structure).
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

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $keys;
		}

		$acf_keys = [];

		foreach ( acf_get_field_groups( [ 'post_type' => $post_type ] ) as $group ) {
			$fields = acf_get_fields( $group['key'] ?? '' );

			if ( is_array( $fields ) ) {
				$acf_keys = array_merge( $acf_keys, $this->collect_mt_fields( $fields ) );
			}
		}

		if ( $acf_keys === [] ) {
			return $keys;
		}

		if ( $post_id > 0 ) {
			$acf_keys = $this->expand_repeating_rows( $acf_keys, $post_id );
		}

		return array_values( array_unique( array_merge( $keys, $acf_keys ) ) );
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

			// Clone fields: mirror the copy walker's prefix rule so cloned
			// text/textarea/wysiwyg subfields are machine-translated too (they
			// are copied to translations, so they must be MT-collectable).
			if ( $field['type'] === 'clone' && ! empty( $field['sub_fields'] ) ) {
				$clone_prefix = ! empty( $field['prefix_name'] ) ? $meta_key . '_' : $prefix;
				$keys         = array_merge( $keys, $this->collect_mt_fields( $field['sub_fields'], $clone_prefix, $leaf_types ) );
			}
		}

		return $keys;
	}

	/**
	 * Declare the MT destination format for an ACF meta key: 'text' for
	 * text / textarea fields (plain-text destinations that must not carry
	 * entity-escaped output), leaving wysiwyg (and every non-ACF key) on the
	 * inherited 'html' default. Only ever UPGRADES a key to text — never the
	 * reverse — so a wysiwyg field can never be mis-sent as plain text.
	 *
	 * @param string $format    Inherited format ('html' by default).
	 * @param string $key       Meta key (repeater rows carry numeric indices).
	 * @param string $post_type Source post type.
	 * @return string 'text' when the key is an ACF text/textarea field, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		if ( 'text' === $format ) {
			return $format;
		}

		$set = $this->plaintext_mt_key_set( $post_type );

		if ( $set === [] ) {
			return $format;
		}

		// Normalise repeater / flexible-content row indices to the row-0
		// template form collect_mt_fields() emits (slides_3_title →
		// slides_0_title) so every row of a text field resolves.
		$template = (string) preg_replace( '/_\d+_/', '_0_', $key );

		return ( isset( $set[ $key ] ) || isset( $set[ $template ] ) ) ? 'text' : $format;
	}

	/**
	 * The set of ACF text/textarea (NOT wysiwyg) meta-key templates for a post
	 * type, built once per request. Blog-keyed for multisite; bounded so a
	 * request touching many post types stays memory-flat.
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
	 * Recursively collect translatable field meta keys.
	 *
	 * Walks through field definitions and builds the meta key patterns
	 * for all text-type fields, including those nested inside repeaters,
	 * flexible content layouts, and groups.
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
				$clone_prefix = ! empty( $field['prefix_name'] ) ? $meta_key . '_' : $prefix;
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
	 * Replaces related post IDs with their translated counterparts
	 * for the current language.
	 *
	 * @param mixed               $value Field value (array of post IDs or WP_Post objects).
	 * @param mixed               $post_id ACF post id. NOT always an int: acf_get_valid_post_id()
	 *                                     returns 'options', "term_{$id}", "user_{$id}", "block_…"
	 *                                     and null. Typing this `int` made PHP throw at argument
	 *                                     binding — before this method's own early returns could
	 *                                     run — turning a taxonomy archive or an options page into
	 *                                     an HTTP 500. Unused in the body; kept for the signature.
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

		foreach ( $value as $related_post ) {
			$related_id  = is_object( $related_post ) ? $related_post->ID : (int) $related_post;
			$translation = $manager->get_translation_id( $related_id, $lang_slug );

			// Only swap in a translation the visitor is allowed to see. A
			// translation still in draft/pending/private would otherwise be
			// rendered on a public page in place of the published source —
			// leaking unfinished content to anonymous visitors and linking to
			// a URL they cannot open. Falling back to the source keeps the
			// field populated with something public.
			if ( $translation && ! $this->is_publicly_viewable_translation( (int) $translation ) ) {
				$translation = null;
			}

			if ( $translation ) {
				if ( is_object( $related_post ) ) {
					$translated_post = get_post( $translation );

					// Null-guard: get_post returns null when the ID no longer
					// exists (translator deleted the translated post, etc).
					// Fall back to the original so callers don't dereference null.
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
	 * Translate ACF post object field value on the frontend.
	 *
	 * @param mixed               $value Field value (post ID or WP_Post object).
	 * @param mixed               $post_id ACF post id. NOT always an int: acf_get_valid_post_id()
	 *                                     returns 'options', "term_{$id}", "user_{$id}", "block_…"
	 *                                     and null. Typing this `int` made PHP throw at argument
	 *                                     binding — before this method's own early returns could
	 *                                     run — turning a taxonomy archive or an options page into
	 *                                     an HTTP 500. Unused in the body; kept for the signature.
	 * @param array<string,mixed> $field Field configuration.
	 * @return mixed
	 */
	public function translate_post_object( mixed $value, mixed $post_id, array $field ): mixed {
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
	 * Replaces term IDs with their translated counterparts.
	 *
	 * @param mixed               $value Field value (array of term IDs or WP_Term objects).
	 * @param mixed               $post_id ACF post id. NOT always an int: acf_get_valid_post_id()
	 *                                     returns 'options', "term_{$id}", "user_{$id}", "block_…"
	 *                                     and null. Typing this `int` made PHP throw at argument
	 *                                     binding — before this method's own early returns could
	 *                                     run — turning a taxonomy archive or an options page into
	 *                                     an HTTP 500. Unused in the body; kept for the signature.
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
	 * Whether a translated post may be shown to the current visitor.
	 *
	 * Reference fields resolve on the front end, where swapping in a draft,
	 * pending or private translation would publish unfinished content and
	 * point at a URL the visitor cannot open. Uses core's own visibility
	 * rule (WP 5.7+) with a status fallback for older cores.
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
