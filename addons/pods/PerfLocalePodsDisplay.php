<?php
/**
 * PerfLocale Pods addon: the front-end field reads.
 *
 * Loaded only by PerfLocalePods on the first Pods field read of a front-end
 * request (pods_pods_field or pods_pods_field_related_output_type). A
 * request that reads no Pods field never loads this file.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end field reads for the Pods addon: relationship (pick) field values
 * mapped to the current language for the IDs, arrays and objects outputs,
 * and the rows a display read formats.
 */
final class PerfLocalePodsDisplay {

	/**
	 * Translated posts or terms loaded per statement by prime_post_targets()
	 * and prime_term_targets().
	 *
	 * @var int
	 */
	private const TARGET_PRIME_CHUNK = 100;

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
	 * True while map_display_source() reads a field for a display read; the
	 * rows Pods keeps from that read are mapped there, not in
	 * translate_field_value().
	 *
	 * @var bool
	 */
	private bool $reading_display_source = false;

	/**
	 * The rows map_display_source() mapped, per display read (its params
	 * object): the Pods item, the field name and the rows as Pods kept them.
	 * translate_field_value(), at the end of that read, puts them back, so a
	 * later read of the item in another language starts from the item's own
	 * rows. Null until a display read is mapped.
	 *
	 * @var \WeakMap<object, array<int, mixed>>|null
	 */
	private ?\WeakMap $display_rows = null;

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
		// Only translate pick (relationship) fields.
		if ( ! is_object( $params ) || ! isset( $params->name ) ) {
			return $value;
		}

		// The end of a display read whose rows map_display_source() mapped:
		// the item keeps its own rows again.
		if ( null !== $this->display_rows && isset( $this->display_rows[ $params ] ) ) {
			[ $item, $name, $rows ] = $this->display_rows[ $params ];
			unset( $this->display_rows[ $params ] );

			if ( isset( $item->data ) && is_object( $item->data ) && is_array( $item->data->row ) ) {
				$item->data->row[ $name ] = $rows;
			}
		}

		if ( empty( $value ) || $this->reading_display_source || ! self::is_id_output( $params ) ) {
			return $value;
		}

		// Resolve the language first: nothing is translated on the default
		// language, so bail BEFORE the (more expensive) Pods field-config
		// lookup below - matching the ACF and Meta Box addons' ordering.
		$lang_slug = $this->get_target_slug();

		if ( $lang_slug === '' ) {
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

		if ( self::field_arg( $field_data, 'type' ) !== 'pick' ) {
			return $value;
		}

		$kind = self::reference_kind( self::field_arg( $field_data, 'pick_object' ) );

		if ( 'post' === $kind ) {
			return $this->translate_post_references( $value, $lang_slug );
		}

		if ( 'term' === $kind ) {
			return $this->translate_term_references( $value, $lang_slug );
		}

		// user, role, site, network, table, comment, pod - not translatable.
		return $value;
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
		if ( 'arrays' !== $output || ! is_object( $params ) || empty( $params->display ) || ! empty( $params->in_form ) || $this->reading_display_source ) {
			return $output;
		}

		$name = isset( $params->name ) && is_string( $params->name ) ? trim( $params->name ) : '';

		if ( '' === $name || str_contains( $name, '.' ) || ! self::is_pods_item( $obj ) || isset( $obj->row_override[ $name ] ) ) {
			return $output;
		}

		$lang_slug = $this->get_target_slug();

		if ( '' === $lang_slug ) {
			return $output;
		}

		$field_data = $obj->fields( $name );

		if ( ( ! is_object( $field_data ) && ! is_array( $field_data ) )
			|| self::field_arg( $field_data, 'type' ) !== 'pick'
			|| self::field_arg( $field_data, 'name' ) !== $name ) {
			return $output;
		}

		$kind        = self::reference_kind( self::field_arg( $field_data, 'pick_object' ) );
		$pick_output = self::field_arg( $field_data, 'pick_output' );

		if ( '' === $kind || ( is_string( $pick_output ) && '' !== $pick_output && 'arrays' !== $pick_output ) ) {
			return $output;
		}

		if ( ! isset( $obj->data->row[ $name ] ) ) {
			$this->reading_display_source = true;

			try {
				$obj->field(
					[
						'name'    => $name,
						'output'  => 'arrays',
						'single'  => false,
						'orderby' => $params->orderby ?? null,
						'params'  => $params->params ?? null,
						'keyed'   => $params->keyed ?? false,
					]
				);
			} finally {
				$this->reading_display_source = false;
			}
		}

		$rows = is_array( $obj->data->row ) ? ( $obj->data->row[ $name ] ?? null ) : null;

		if ( is_array( $rows ) && [] !== $rows ) {
			$this->display_rows          ??= new \WeakMap();
			$this->display_rows[ $params ] = [ $obj, $name, $rows ];
			$obj->data->row[ $name ]       = 'post' === $kind
				? $this->translate_post_references( $rows, $lang_slug )
				: $this->translate_term_references( $rows, $lang_slug );
		}

		return $output;
	}

	/**
	 * Whether an object has what map_display_source() uses of a Pods object:
	 * fields(), field() and the data object holding the current row.
	 *
	 * @param mixed $obj Object passed with a Pods filter.
	 * @return bool
	 */
	private static function is_pods_item( mixed $obj ): bool {
		return is_object( $obj )
			&& method_exists( $obj, 'fields' )
			&& method_exists( $obj, 'field' )
			&& isset( $obj->data )
			&& is_object( $obj->data )
			&& property_exists( $obj->data, 'row' );
	}

	/**
	 * Whether a Pods field() read returns related items as IDs, rows or
	 * objects, the outputs this addon maps.
	 *
	 * @param object $params Params object passed to field().
	 * @return bool
	 */
	private static function is_id_output( object $params ): bool {
		if ( ! empty( $params->in_form ) || ! empty( $params->display ) ) {
			return false;
		}

		$output = $params->output ?? null;

		return null === $output || in_array( $output, [ 'arrays', 'ids', 'objects' ], true );
	}

	/**
	 * One setting of a Pods field configuration: a Field object (Pods 2.8
	 * and later) or an array (earlier versions).
	 *
	 * @param object|array<string, mixed> $field_data Field configuration.
	 * @param string                      $arg        Setting name.
	 * @return mixed
	 */
	private static function field_arg( object|array $field_data, string $arg ): mixed {
		if ( is_array( $field_data ) ) {
			return $field_data[ $arg ] ?? null;
		}

		return method_exists( $field_data, 'get_arg' ) ? $field_data->get_arg( $arg ) : null;
	}

	/**
	 * What a pick field relates to, from its pick_object: 'post' for posts
	 * of any post type and media, 'term' for terms, '' for anything else.
	 *
	 * Pods saves the related type as pick_object "post_type" or "taxonomy"
	 * with the post type or taxonomy name in pick_val. The combined form of
	 * the field editor's Related Type list, "post_type-NAME" or
	 * "taxonomy-NAME", is read the same way.
	 *
	 * @param mixed $pick_object The field's pick_object.
	 * @return string
	 */
	private static function reference_kind( mixed $pick_object ): string {
		if ( ! is_string( $pick_object ) || '' === $pick_object ) {
			return '';
		}

		if ( 'post_type' === $pick_object || 'media' === $pick_object || str_starts_with( $pick_object, 'post_type-' ) ) {
			return 'post';
		}

		if ( 'taxonomy' === $pick_object || str_starts_with( $pick_object, 'taxonomy-' ) ) {
			return 'term';
		}

		return '';
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
	 * case. Returning null keeps the published source in place - the same rule
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
	 * Handles a list (Pods' multi-select value; its keys are kept) of IDs,
	 * row arrays (output=arrays) or WP_Post objects, and one of each (a
	 * single-select value). A sibling the visitor may not see is skipped -
	 * see {@see self::public_translation_id()}. Anything that is not a post
	 * reference is returned as it is.
	 *
	 * @param mixed  $value Post reference value(s).
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_post_references( mixed $value, string $lang_slug ): mixed {
		if ( ! is_array( $value ) || isset( $value['ID'] ) ) {
			return $this->translate_post_item( $value, $lang_slug );
		}

		$this->prime_post_targets( $value, $lang_slug );

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->translate_post_item( $item, $lang_slug );
		}

		return $value;
	}

	/**
	 * One related post in the current language, in the shape it came in: an
	 * ID, a row array (see {@see self::row_for()}) or a WP_Post. The item is
	 * returned as it is when it is not a post reference or has no sibling
	 * the visitor may see.
	 *
	 * @param mixed  $item      Related post.
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_post_item( mixed $item, string $lang_slug ): mixed {
		$translation = $this->public_translation_id( self::post_reference_id( $item ), $lang_slug );

		if ( null === $translation ) {
			return $item;
		}

		if ( is_object( $item ) || is_array( $item ) ) {
			$translated_post = get_post( $translation );

			if ( ! $translated_post instanceof \WP_Post ) {
				return $item;
			}

			return is_array( $item ) ? self::row_for( $item, $translated_post, $translation ) : $translated_post;
		}

		return $translation;
	}

	/**
	 * A related item's row array (Pods' arrays output: the row's columns and
	 * pod_item_id) for another post or term, with the same keys: a key the
	 * object has takes the object's value, pod_item_id takes the object's
	 * ID, any other key keeps the row's value. A number goes in as a string
	 * where the row held a string, as the database row Pods read does. The
	 * row then goes through Pods' pods_access_bleep_data(), as every row Pods
	 * reads does, so a password the object holds is not in it. It reads the
	 * loaded object only, so it costs no query.
	 *
	 * @param array<int|string, mixed> $row    Source row.
	 * @param object                   $object Translated WP_Post or WP_Term.
	 * @param int                      $id     The object's ID.
	 * @return array<int|string, mixed>
	 */
	private static function row_for( array $row, object $object, int $id ): array {
		$vars = get_object_vars( $object );

		foreach ( $row as $key => $old ) {
			if ( 'pod_item_id' === $key ) {
				$new = $id;
			} elseif ( array_key_exists( $key, $vars ) ) {
				$new = $vars[ $key ];
			} else {
				continue;
			}

			$row[ $key ] = ( is_string( $old ) && ( is_int( $new ) || is_float( $new ) ) ) ? (string) $new : $new;
		}

		return function_exists( 'pods_access_bleep_data' ) ? (array) pods_access_bleep_data( $row ) : $row;
	}

	/**
	 * The post ID a relationship item refers to: a row array's or an
	 * object's ID, an integer or a numeric string; 0 for anything else.
	 *
	 * @param mixed $item Relationship field item.
	 * @return int
	 */
	private static function post_reference_id( mixed $item ): int {
		if ( is_array( $item ) ) {
			$item = $item['ID'] ?? null;
		} elseif ( is_object( $item ) ) {
			$item = $item->ID ?? null;
		}

		if ( is_int( $item ) || ( is_string( $item ) && is_numeric( $item ) ) ) {
			return (int) $item;
		}

		return 0;
	}

	/**
	 * Load what the mapping of a list of related posts reads, in batches:
	 * the links of every referenced post in one prime, then their
	 * current-language posts in chunks of TARGET_PRIME_CHUNK. The mapping
	 * then finds them in the cache instead of paying one link lookup (above
	 * the eager-map cap) and one post query per item. It only fills caches:
	 * the per-item visibility check, the fallback to the source and the
	 * order are the mapping's. Terms and meta are not read by the mapping,
	 * so they are not loaded. A list with fewer than two distinct posts is
	 * left to the mapping.
	 *
	 * @param array<int|string, mixed> $value     Relationship field value.
	 * @param string                   $lang_slug Target language slug.
	 * @return void
	 */
	private function prime_post_targets( array $value, string $lang_slug ): void {
		$ids = [];

		foreach ( $value as $item ) {
			$id = self::post_reference_id( $item );

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

			if ( $target !== null && $target > 0 && $target !== $id ) {
				$targets[ $target ] = $target;
			}
		}

		foreach ( array_chunk( array_values( $targets ), self::TARGET_PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
		}
	}

	/**
	 * Translate term reference IDs to their current-language equivalents.
	 *
	 * Handles a list (its keys are kept) of IDs, row arrays or WP_Term
	 * objects, and one of each. Anything that is not a term reference is
	 * returned as it is.
	 *
	 * @param mixed  $value Term reference value(s).
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_term_references( mixed $value, string $lang_slug ): mixed {
		if ( ! is_array( $value ) || isset( $value['term_id'] ) ) {
			return $this->translate_term_item( $value, $lang_slug );
		}

		$this->prime_term_targets( $value, $lang_slug );

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->translate_term_item( $item, $lang_slug );
		}

		return $value;
	}

	/**
	 * One related term in the current language, in the shape it came in: an
	 * ID, a row array (see {@see self::row_for()}) or a WP_Term. The item is
	 * returned as it is when it is not a term reference or has no sibling.
	 *
	 * @param mixed  $item      Related term.
	 * @param string $lang_slug Target language slug.
	 * @return mixed
	 */
	private function translate_term_item( mixed $item, string $lang_slug ): mixed {
		$term_id = self::term_reference_id( $item );

		if ( $term_id <= 0 ) {
			return $item;
		}

		$new_id = $this->get_translated_term_id( $term_id, $lang_slug );

		if ( $new_id === $term_id ) {
			return $item;
		}

		if ( is_object( $item ) || is_array( $item ) ) {
			$translated_term = get_term( $new_id );

			if ( ! $translated_term instanceof \WP_Term ) {
				return $item;
			}

			return is_array( $item ) ? self::row_for( $item, $translated_term, $new_id ) : $translated_term;
		}

		return $new_id;
	}

	/**
	 * The term ID a relationship item refers to: a row array's or an
	 * object's term_id, an integer or a numeric string; 0 for anything else.
	 *
	 * @param mixed $item Relationship field item.
	 * @return int
	 */
	private static function term_reference_id( mixed $item ): int {
		if ( is_array( $item ) ) {
			$item = $item['term_id'] ?? null;
		} elseif ( is_object( $item ) ) {
			$item = $item->term_id ?? null;
		}

		if ( is_int( $item ) || ( is_string( $item ) && is_numeric( $item ) ) ) {
			return (int) $item;
		}

		return 0;
	}

	/**
	 * Load what the mapping of a list of related terms reads, in batches:
	 * the links of every referenced term in one prime, then their
	 * current-language terms in chunks of TARGET_PRIME_CHUNK, without term
	 * meta. Like prime_post_targets() it only fills caches, and a list with
	 * fewer than two distinct terms is left to the mapping.
	 *
	 * @param array<int|string, mixed> $value     Relationship field value.
	 * @param string                   $lang_slug Target language slug.
	 * @return void
	 */
	private function prime_term_targets( array $value, string $lang_slug ): void {
		$ids = [];

		foreach ( $value as $item ) {
			$id = self::term_reference_id( $item );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( count( $ids ) < 2 || ! function_exists( '_prime_term_caches' ) ) {
			return;
		}

		$repo = $this->get_repo();
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Term, array_values( $ids ) );

		$targets = [];

		foreach ( $ids as $id ) {
			foreach ( $repo->get_translations( $id, \PerfLocale\Enum\ObjectType::Term ) as $link ) {
				$target = (int) ( $link->object_id ?? 0 );

				if ( ( $link->language_slug ?? '' ) === $lang_slug && $target > 0 && $target !== $id ) {
					$targets[ $target ] = $target;
				}
			}
		}

		foreach ( array_chunk( array_values( $targets ), self::TARGET_PRIME_CHUNK ) as $chunk ) {
			_prime_term_caches( $chunk, false );
		}
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
	 * The current language slug when it is not the default language, the
	 * language whose pages are mapped; '' otherwise.
	 *
	 * @return string
	 */
	private function get_target_slug(): string {
		$lang_slug = $this->get_current_slug();

		if ( '' === $lang_slug ) {
			return '';
		}

		$default = \PerfLocale\Plugin::get_instance()->get( 'router' )->get_default_language();

		return ( $default && $lang_slug === $default->slug ) ? '' : $lang_slug;
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
