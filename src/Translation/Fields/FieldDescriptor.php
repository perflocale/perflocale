<?php
/**
 * One classified host field key.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One key the host stores: where it is, its host names, its class and the rows that enclose it.
 */
final class FieldDescriptor {

	/**
	 * Constructor.
	 *
	 * @param string                                               $key               Concrete meta key (`slides_2_title`); for a Meta Box blob leaf the blob's key.
	 * @param string                                               $template          Template key, row indices as 0 (`slides_0_title`).
	 * @param string                                               $host              `acf` or `mb`.
	 * @param string                                               $field_key         ACF field key as loaded (composite inside a seamless clone), or the Meta Box id path.
	 * @param string                                               $original_key      ACF `__key` when the field is cloned, else the field key; Meta Box: the id path.
	 * @param string                                               $type              Host field type.
	 * @param string                                               $class             {@see FieldClass} class.
	 * @param string                                               $format            `text` or `html` (text class).
	 * @param string                                               $rule              `copy` or `keep` (value class).
	 * @param string                                               $kind              Reference kind (reference class).
	 * @param bool                                                 $multiple          Whether the field holds a list of values.
	 * @param array<int, string>                                   $taxonomies        Allowed taxonomies of a term field.
	 * @param array<int, string>                                   $post_types        Allowed post types of a post field.
	 * @param array<int, array{0: string, 1: int, 2: string|null}> $chain             Enclosing rows outermost first: [container key, index, layout|null].
	 * @param string                                               $container         The outermost container key, or ''.
	 * @param array<int, int|string>                               $mb_path           Meta Box: the path inside the blob, e.g. ['cards', 2, 'link'].
	 * @param string                                               $storage           {@see FieldClass} storage.
	 * @param array<int, string>                                   $label_path        Labels of the enclosing containers and the field (`__label` for a clone leaf).
	 * @param bool                                                 $clone             Meta Box: cloneable.
	 * @param bool                                                 $clone_as_multiple Meta Box: one row per clone.
	 * @param bool                                                 $sync_terms        ACF taxonomy with `save_terms`.
	 * @param bool                                                 $sensitive         Whether the key's name marks a credential.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $template,
		public readonly string $host,
		public readonly string $field_key,
		public readonly string $original_key,
		public readonly string $type,
		public readonly string $class,
		public readonly string $format = FieldClass::FORMAT_TEXT,
		public readonly string $rule = FieldClass::RULE_KEEP,
		public readonly string $kind = '',
		public readonly bool $multiple = false,
		public readonly array $taxonomies = [],
		public readonly array $post_types = [],
		public readonly array $chain = [],
		public readonly string $container = '',
		public readonly array $mb_path = [],
		public readonly string $storage = FieldClass::STORAGE_POSTMETA,
		public readonly array $label_path = [],
		public readonly bool $clone = false,
		public readonly bool $clone_as_multiple = false,
		public readonly bool $sync_terms = false,
		public readonly bool $sensitive = false
	) {
	}
}
