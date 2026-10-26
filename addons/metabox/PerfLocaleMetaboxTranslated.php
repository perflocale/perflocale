<?php
/**
 * PerfLocale MetaBox addon: the work done on a translated request.
 *
 * Loaded by PerfLocaleMetabox only when a translated request renders a Meta Box block or reads a reference field.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translated-request serving for the MetaBox addon.
 *
 * Per-language block render cache, translated `post` / `taxonomy_advanced` output and the `rwmb_get_value` reference walk.
 */
final class PerfLocaleMetaboxTranslated {

	/**
	 * Key added to a Meta Box block's `data` attribute on a translated request.
	 *
	 * MB Blocks keys its HTML cache on `data` alone, so without it two languages share one entry.
	 *
	 * @var string
	 */
	public const RENDER_LANGUAGE_KEY = '_perflocale_lang';

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
	 * Levels of nested arrays the reference walk enters.
	 *
	 * Meta Box nests one array per clone, multiple and group level; deeper arrays come back unchanged.
	 *
	 * @var int
	 */
	private const MAX_REFERENCE_DEPTH = 16;

	/**
	 * The instance serving the current addon object.
	 *
	 * @var self|null
	 */
	private static ?self $current = null;

	/**
	 * The addon object this instance serves.
	 *
	 * @var \PerfLocaleMetabox
	 */
	private \PerfLocaleMetabox $addon;

	/**
	 * The addon's post translation manager.
	 *
	 * @var \Closure(): \PerfLocale\Translation\PostTranslationManager
	 */
	private \Closure $manager;

	/**
	 * The addon's translation group repository.
	 *
	 * @var \Closure(): \PerfLocale\Database\Repository\TranslationGroupRepository
	 */
	private \Closure $repo;

	/**
	 * References this request already batch-read: "blog|post|lang" or "blog|term|lang" => ( ID => true ).
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $primed = [];

	/**
	 * While prime_references() collects: the IDs the translate_*_ids() methods record instead of mapping.
	 *
	 * @var array{post: array<int, int>, term: array<int, int>}|null
	 */
	private ?array $collecting = null;

	/**
	 * The instance for one addon object.
	 *
	 * @param \PerfLocaleMetabox $addon   The addon.
	 * @param \Closure           $manager Gives the addon's post translation manager.
	 * @param \Closure           $repo    Gives the addon's translation group repository.
	 */
	private function __construct( \PerfLocaleMetabox $addon, \Closure $manager, \Closure $repo ) {
		$this->addon   = $addon;
		$this->manager = $manager;
		$this->repo    = $repo;
	}

	/**
	 * The instance serving an addon object, if it was started.
	 *
	 * @param \PerfLocaleMetabox $addon The addon.
	 * @return self|null
	 */
	public static function of( \PerfLocaleMetabox $addon ): ?self {
		return null !== self::$current && self::$current->addon === $addon ? self::$current : null;
	}

	/**
	 * Start the instance serving an addon object.
	 *
	 * @param \PerfLocaleMetabox $addon   The addon.
	 * @param \Closure           $manager Gives the addon's post translation manager.
	 * @param \Closure           $repo    Gives the addon's translation group repository.
	 * @return self
	 */
	public static function start( \PerfLocaleMetabox $addon, \Closure $manager, \Closure $repo ): self {
		self::$current = new self( $addon, $manager, $repo );

		return self::$current;
	}

	/**
	 * Translate a reference field value read through `rwmb_get_value`.
	 *
	 * @param mixed               $value     Field value.
	 * @param array<string,mixed> $field     Field configuration.
	 * @param string              $lang_slug Target language slug.
	 * @return mixed
	 */
	public function translate_reference_value( mixed $value, array $field, string $lang_slug ): mixed {
		$this->prime_references( $value, $field, $lang_slug );

		return $this->map_references( $value, $field, $lang_slug );
	}

	/**
	 * Map the references of a `post`, `taxonomy_advanced` or `group` field
	 * value to the language's siblings; any other value comes back as it is.
	 *
	 * @param mixed               $value     Field value.
	 * @param array<string,mixed> $field     Field configuration.
	 * @param string              $lang_slug Target language slug.
	 * @return mixed
	 */
	private function map_references( mixed $value, array $field, string $lang_slug ): mixed {
		$type = $field['type'] ?? '';

		if ( $type === 'post' ) {
			return $this->translate_post_ids( $value, $lang_slug );
		}

		if ( $type === 'taxonomy_advanced' ) {
			return $this->translate_term_ids( $value, $lang_slug );
		}

		if ( $type === 'group' && is_array( $value ) && ! empty( $field['fields'] ) ) {
			return $this->translate_group_references( $value, $field, $lang_slug );
		}

		return $value;
	}

	/**
	 * `rwmb_raw_meta`: batch-read the links and targets of a reference or group field's IDs.
	 *
	 * Every Meta Box field read passes here first, so the per-reference lookups after it hit the cache.
	 *
	 * @param mixed $value The stored value.
	 * @param mixed $field Field settings.
	 * @return mixed
	 */
	public function prime_raw_meta( mixed $value, mixed $field = null ): mixed {
		if ( ! is_array( $field ) || empty( $value ) || ! in_array( $field['type'] ?? '', [ 'post', 'taxonomy_advanced', 'group' ], true ) ) {
			return $value;
		}

		$slug = $this->translated_slug();

		if ( $slug !== '' ) {
			$this->prime_references( $value, $field, $slug );
		}

		return $value;
	}

	/**
	 * Batch-read a field value's reference links and the language's sibling posts or terms.
	 *
	 * Collects the IDs the mapping walk would look up, then primes each ID once per request; maps nothing.
	 *
	 * @param mixed               $value     Field value.
	 * @param array<string,mixed> $field     Field configuration.
	 * @param string              $lang_slug Target language slug.
	 * @return void
	 */
	private function prime_references( mixed $value, array $field, string $lang_slug ): void {
		if ( empty( $value ) || null !== $this->collecting ) {
			return;
		}

		$this->collecting = [
			'post' => [],
			'term' => [],
		];

		try {
			$this->map_references( $value, $field, $lang_slug );
			$found = $this->collecting;
		} finally {
			$this->collecting = null;
		}

		$this->prime_posts( $found['post'], $lang_slug );
		$this->prime_terms( $found['term'], $lang_slug );
	}

	/**
	 * The IDs of one kind not read in a batch yet in this language, now
	 * marked as read.
	 *
	 * @param array<int, int> $ids       IDs.
	 * @param string          $kind      post or term.
	 * @param string          $lang_slug Target language slug.
	 * @return array<int, int>
	 */
	private function take_unprimed( array $ids, string $kind, string $lang_slug ): array {
		$key  = get_current_blog_id() . '|' . $kind . '|' . $lang_slug;
		$todo = [];

		foreach ( $ids as $id ) {
			if ( $id > 0 && ! isset( $this->primed[ $key ][ $id ] ) ) {
				$this->primed[ $key ][ $id ] = true;
				$todo[]                      = $id;
			}
		}

		return $todo;
	}

	/**
	 * Batch-read the links of some posts and their siblings in a language.
	 *
	 * @param array<int, int> $ids       Post IDs.
	 * @param string          $lang_slug Target language slug.
	 * @return void
	 */
	private function prime_posts( array $ids, string $lang_slug ): void {
		$ids = $this->take_unprimed( $ids, 'post', $lang_slug );

		if ( [] === $ids ) {
			return;
		}

		( $this->repo )()->prime_translations( \PerfLocale\Enum\ObjectType::Post, $ids );

		$siblings = [];

		foreach ( $ids as $id ) {
			$sibling = ( $this->manager )()->get_translation_id( $id, $lang_slug );

			if ( $sibling && $sibling !== $id ) {
				$siblings[ $sibling ] = $sibling;
			}
		}

		if ( [] !== $siblings ) {
			_prime_post_caches( array_values( $siblings ), false, false );
		}
	}

	/**
	 * Batch-read the links of some terms and their siblings in a language.
	 *
	 * @param array<int, int> $ids       Term IDs.
	 * @param string          $lang_slug Target language slug.
	 * @return void
	 */
	private function prime_terms( array $ids, string $lang_slug ): void {
		$ids = $this->take_unprimed( $ids, 'term', $lang_slug );

		if ( [] === $ids ) {
			return;
		}

		$repo = ( $this->repo )();
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Term, $ids );

		$siblings = [];

		foreach ( $ids as $id ) {
			foreach ( $repo->get_translations( $id, \PerfLocale\Enum\ObjectType::Term ) as $link ) {
				$sibling = (int) ( $link->object_id ?? 0 );

				if ( ( $link->language_slug ?? '' ) === $lang_slug && $sibling > 0 && $sibling !== $id ) {
					$siblings[ $sibling ] = $sibling;
				}
			}
		}

		if ( [] !== $siblings ) {
			_prime_term_caches( array_values( $siblings ), false );
		}
	}

	/**
	 * `rwmb_field_class`: on a translated request, print `taxonomy_advanced` through PerfLocaleMetaboxTermField.
	 *
	 * Meta Box prints it via format_single_value() with no filter on the term; the subclass changes only
	 * that method. Another plugin's class is left as it is.
	 *
	 * @param mixed $class_name Field class name.
	 * @param mixed $type       Field type.
	 * @return mixed
	 */
	public function term_display_field_class( mixed $class_name, mixed $type = '' ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Meta Box's rwmb_field_class signature (class name, field type).
		if ( 'RWMB_Taxonomy_Advanced_Field' !== $class_name || $this->translated_slug() === '' ) {
			return $class_name;
		}

		if ( ! class_exists( 'PerfLocaleMetaboxTermField', false ) ) {
			if ( ! class_exists( 'RWMB_Taxonomy_Advanced_Field' ) ) {
				return $class_name;
			}

			require_once __DIR__ . '/PerfLocaleMetaboxTermField.php';
		}

		\PerfLocaleMetaboxTermField::$map ??= $this->translate_term_format_value( ... );

		return \PerfLocaleMetaboxTermField::class;
	}

	/**
	 * The term a `taxonomy_advanced` field prints through the_value() is the
	 * current language's sibling, the same term `rwmb_get_value` gives.
	 *
	 * @param mixed $value The term object; any other value comes back as it is.
	 * @return mixed
	 */
	public function translate_term_format_value( mixed $value ): mixed {
		if ( ! is_object( $value ) || ! isset( $value->term_id ) ) {
			return $value;
		}

		$slug = $this->translated_slug();

		if ( $slug === '' ) {
			return $value;
		}

		return $this->translate_term_ids( $value, $slug );
	}

	/**
	 * `_rwmb_post_format_single_value`: the post a `post` field prints through
	 * Meta Box's the_value() path is the current language's sibling.
	 *
	 * The print path reads the stored ID without `rwmb_get_value`; this is the seam Meta Box's own WPML
	 * integration uses. A sibling the visitor may not see is skipped.
	 *
	 * @param mixed $value The stored post ID.
	 * @param mixed $field Field settings.
	 * @return mixed
	 */
	public function translate_post_format_value( mixed $value, mixed $field = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Meta Box's _rwmb_post_format_single_value signature (value, field).
		$slug = $this->translated_slug();

		if ( $slug === '' ) {
			return $value;
		}

		return $this->translate_post_ids( $value, $slug );
	}

	/**
	 * `render_block_data`: add the current language to a Meta Box block's `data`, one cache entry per language.
	 *
	 * The key is not a field ID, so Meta Box reads no value for it; a block without `data` gets its registered default.
	 *
	 * @param mixed $parsed_block The parsed block.
	 * @return mixed
	 */
	public function add_block_render_language( mixed $parsed_block ): mixed {
		if ( ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		$name = $parsed_block['blockName'] ?? null;

		if ( ! is_string( $name ) || ! str_starts_with( $name, 'meta-box/' ) ) {
			return $parsed_block;
		}

		$slug = $this->translated_slug();

		if ( $slug === '' ) {
			return $parsed_block;
		}

		$attrs = $parsed_block['attrs'] ?? [];

		if ( ! is_array( $attrs ) ) {
			return $parsed_block;
		}

		$data = array_key_exists( 'data', $attrs ) ? $attrs['data'] : self::registered_block_data( $name );

		if ( ! is_array( $data ) ) {
			return $parsed_block;
		}

		$data[ self::RENDER_LANGUAGE_KEY ] = $slug;
		$attrs['data']                     = $data;
		$parsed_block['attrs']             = $attrs;

		return $parsed_block;
	}

	/**
	 * The default of a registered block type's `data` attribute.
	 *
	 * @param string $name Block name.
	 * @return mixed The default, or an empty array when the type declares none.
	 */
	private static function registered_block_data( string $name ): mixed {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return [];
		}

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

		if ( ! is_object( $type ) || ! isset( $type->attributes['data'] ) || ! is_array( $type->attributes['data'] ) ) {
			return [];
		}

		return $type->attributes['data']['default'] ?? [];
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
		$router  = $plugin->get( 'router' );
		$slug    = $router->get_current_slug();
		$default = $router->get_default_language();

		if ( $slug === '' || ( null !== $default && isset( $default->slug ) && $slug === $default->slug ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Resolve a post's current-language sibling, only when the visitor may see it.
	 *
	 * Translations start as drafts; null keeps the published source instead of a draft's title and URL.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $lang_slug Target language slug.
	 * @return int|null Sibling post ID, or null when there is none to show.
	 */
	private function public_translation_id( int $post_id, string $lang_slug ): ?int {
		if ( $post_id <= 0 ) {
			return null;
		}

		$translation = ( $this->manager )()->get_translation_id( $post_id, $lang_slug );

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
	 * Keeps the value's shape; a sibling the visitor may not see is skipped.
	 *
	 * @param mixed  $value Post ID(s) in various formats.
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_post_ids( mixed $value, string $lang_slug ): mixed {
		if ( empty( $value ) ) {
			return $value;
		}

		if ( null !== $this->collecting ) {
			$this->collect( $value, 'post', 'ID' );

			return $value;
		}

		return self::map_reference_tree(
			$value,
			fn( int $id ): ?int => $this->public_translation_id( $id, $lang_slug ),
			'ID',
			static function ( int $id ): ?object {
				$post = get_post( $id );

				return $post instanceof \WP_Post ? $post : null;
			}
		);
	}

	/**
	 * Translate term IDs to their current-language equivalents.
	 *
	 * Keeps the value's shape.
	 *
	 * @param mixed  $value Term ID(s) in various formats.
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_term_ids( mixed $value, string $lang_slug ): mixed {
		if ( empty( $value ) ) {
			return $value;
		}

		if ( null !== $this->collecting ) {
			$this->collect( $value, 'term', 'term_id' );

			return $value;
		}

		return self::map_reference_tree(
			$value,
			function ( int $id ) use ( $lang_slug ): ?int {
				$new_id = $this->get_translated_term_id( $id, $lang_slug );

				return $new_id !== $id ? $new_id : null;
			},
			'term_id',
			static function ( int $id ): ?object {
				$term = get_term( $id );

				return $term instanceof \WP_Term ? $term : null;
			}
		);
	}

	/**
	 * Record the IDs the mapping walk would look up in a value, each once.
	 *
	 * @param mixed  $value   Reference value.
	 * @param string $kind    post or term.
	 * @param string $id_prop ID property of the objects.
	 * @return void
	 */
	private function collect( mixed $value, string $kind, string $id_prop ): void {
		self::map_reference_tree(
			$value,
			function ( int $id ) use ( $kind ): ?int {
				if ( null !== $this->collecting ) {
					$this->collecting[ $kind ][ $id ] = $id;
				}

				return null;
			},
			$id_prop,
			static fn( int $id ): ?object => null // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the walk's loader signature; collecting loads nothing.
		);
	}

	/**
	 * Map every reference in a stored or formatted value, keeping its shape.
	 *
	 * Recurses into arrays (clones and groups nest: `[[11, 12], [13]]`) up to MAX_REFERENCE_DEPTH. Leaves that map:
	 * an object with the ID property, an int or digit string, a comma-separated ID list (non-positive IDs dropped);
	 * anything else comes back as it is.
	 *
	 * @param mixed                     $value      The value.
	 * @param callable(int): (int|null) $sibling    The sibling ID of an ID, or null to keep it.
	 * @param string                    $id_prop    ID property of the objects (`ID` or `term_id`).
	 * @param callable(int): ?object    $load       The object of a sibling ID, or null.
	 * @param int                       $depth      Number of arrays around `$value`.
	 * @return mixed
	 */
	private static function map_reference_tree( mixed $value, callable $sibling, string $id_prop, callable $load, int $depth = 0 ): mixed {
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_REFERENCE_DEPTH ) {
				return $value;
			}

			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::map_reference_tree( $item, $sibling, $id_prop, $load, $depth + 1 );
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			$id = isset( $value->{$id_prop} ) && is_numeric( $value->{$id_prop} ) ? (int) $value->{$id_prop} : 0;

			if ( $id <= 0 ) {
				return $value;
			}

			$new_id = $sibling( $id );

			return $new_id !== null ? ( $load( $new_id ) ?? $value ) : $value;
		}

		if ( is_string( $value ) && str_contains( $value, ',' ) ) {
			$mapped = [];

			foreach ( array_map( 'intval', explode( ',', $value ) ) as $id ) {
				if ( $id > 0 ) {
					$mapped[] = $sibling( $id ) ?? $id;
				}
			}

			return implode( ',', $mapped );
		}

		if ( is_int( $value ) ) {
			$id = $value;
		} elseif ( is_string( $value ) && \PerfLocale\Helper::is_ascii_digits( trim( $value ) ) ) {
			$id = (int) $value;
		} else {
			return $value;
		}

		if ( $id <= 0 ) {
			return $value;
		}

		return $sibling( $id ) ?? $value;
	}

	/**
	 * Translate reference fields inside a group value.
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
		$repo  = ( $this->repo )();
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
}
