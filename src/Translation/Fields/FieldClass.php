<?php
/**
 * The classification of host field types.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One class per host field type, naming what a source save does: text seeded once, reference copied and mapped,
 * terms set as object terms, value copied or kept, structure copied with row alignment, password never travels,
 * ignore has no row.
 */
final class FieldClass {

	public const TEXT      = 'text';
	public const REFERENCE = 'reference';
	public const TERMS     = 'terms';
	public const VALUE     = 'value';
	public const STRUCTURE = 'structure';
	public const PASSWORD  = 'password';
	public const IGNORE    = 'ignore';

	public const CLASSES = [ self::TEXT, self::REFERENCE, self::TERMS, self::VALUE, self::STRUCTURE, self::PASSWORD, self::IGNORE ];

	public const FORMAT_TEXT = 'text';
	public const FORMAT_HTML = 'html';

	public const RULE_COPY = 'copy';
	public const RULE_KEEP = 'keep';

	public const KIND_POST       = 'post';
	public const KIND_TERM       = 'term';
	public const KIND_USER       = 'user';
	public const KIND_ATTACHMENT = 'attachment';
	public const KIND_ARCHIVE    = 'archive';

	public const STORAGE_POSTMETA            = 'postmeta';
	public const STORAGE_TERMMETA            = 'termmeta';
	public const STORAGE_USERMETA            = 'usermeta';
	public const STORAGE_COMMENTMETA         = 'commentmeta';
	public const STORAGE_CUSTOM_TABLE        = 'custom_table';
	public const STORAGE_OPTION              = 'option';
	public const STORAGE_SETTING             = 'setting';
	public const STORAGE_BLOCK_ATTR          = 'block_attr';
	public const STORAGE_RELATIONSHIPS_TABLE = 'relationships_table';

	public const STORAGES = [
		self::STORAGE_POSTMETA,
		self::STORAGE_TERMMETA,
		self::STORAGE_USERMETA,
		self::STORAGE_COMMENTMETA,
		self::STORAGE_CUSTOM_TABLE,
		self::STORAGE_OPTION,
		self::STORAGE_SETTING,
		self::STORAGE_BLOCK_ATTR,
		self::STORAGE_RELATIONSHIPS_TABLE,
	];

	/** The text types the "Global field text" panel offers, per host. */
	public const ACF_PANEL_TYPES = [ 'text', 'textarea', 'wysiwyg' ];
	public const MB_PANEL_TYPES  = [ 'text', 'textarea', 'wysiwyg', 'text_list', 'fieldset_text', 'key_value' ];

	/**
	 * ACF types by class.
	 *
	 * @var array<string, array{class: string, format?: string, kind?: string}>
	 */
	private const ACF = [
		'text'             => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'textarea'         => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'wysiwyg'          => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_HTML,
		],
		'url'              => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'email'            => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'link'             => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'oembed'           => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'post_object'      => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_POST,
		],
		'relationship'     => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_POST,
		],
		'page_link'        => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_POST,
		],
		'taxonomy'         => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_TERM,
		],
		'user'             => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_USER,
		],
		'image'            => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'file'             => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'gallery'          => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'number'           => [ 'class' => self::VALUE ],
		'range'            => [ 'class' => self::VALUE ],
		'true_false'       => [ 'class' => self::VALUE ],
		'date_picker'      => [ 'class' => self::VALUE ],
		'date_time_picker' => [ 'class' => self::VALUE ],
		'time_picker'      => [ 'class' => self::VALUE ],
		'color_picker'     => [ 'class' => self::VALUE ],
		'select'           => [ 'class' => self::VALUE ],
		'checkbox'         => [ 'class' => self::VALUE ],
		'radio'            => [ 'class' => self::VALUE ],
		'button_group'     => [ 'class' => self::VALUE ],
		'google_map'       => [ 'class' => self::VALUE ],
		'icon_picker'      => [ 'class' => self::VALUE ],
		'repeater'         => [ 'class' => self::STRUCTURE ],
		'flexible_content' => [ 'class' => self::STRUCTURE ],
		'password'         => [ 'class' => self::PASSWORD ],
		'message'          => [ 'class' => self::IGNORE ],
		'tab'              => [ 'class' => self::IGNORE ],
		'accordion'        => [ 'class' => self::IGNORE ],
		'separator'        => [ 'class' => self::IGNORE ],
		'group'            => [ 'class' => self::IGNORE ],
		'clone'            => [ 'class' => self::IGNORE ],
	];

	/**
	 * Meta Box types by class. Cloneable non-group fields and `post` with `parent` are classified by the producer.
	 *
	 * @var array<string, array{class: string, format?: string, kind?: string}>
	 */
	private const METABOX = [
		'text'              => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'textarea'          => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'wysiwyg'           => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_HTML,
		],
		'url'               => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'email'             => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'oembed'            => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'fieldset_text'     => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'text_list'         => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'key_value'         => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'input_list'        => [
			'class'  => self::TEXT,
			'format' => self::FORMAT_TEXT,
		],
		'post'              => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_POST,
		],
		'taxonomy_advanced' => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_TERM,
		],
		'user'              => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_USER,
		],
		'file'              => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'file_advanced'     => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'file_upload'       => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'image'             => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'image_advanced'    => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'image_upload'      => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'single_image'      => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'video'             => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'media'             => [
			'class' => self::REFERENCE,
			'kind'  => self::KIND_ATTACHMENT,
		],
		'taxonomy'          => [ 'class' => self::TERMS ],
		'number'            => [ 'class' => self::VALUE ],
		'range'             => [ 'class' => self::VALUE ],
		'switch'            => [ 'class' => self::VALUE ],
		'checkbox'          => [ 'class' => self::VALUE ],
		'checkbox_list'     => [ 'class' => self::VALUE ],
		'radio'             => [ 'class' => self::VALUE ],
		'select'            => [ 'class' => self::VALUE ],
		'select_advanced'   => [ 'class' => self::VALUE ],
		'select_tree'       => [ 'class' => self::VALUE ],
		'button_group'      => [ 'class' => self::VALUE ],
		'image_select'      => [ 'class' => self::VALUE ],
		'date'              => [ 'class' => self::VALUE ],
		'datetime'          => [ 'class' => self::VALUE ],
		'time'              => [ 'class' => self::VALUE ],
		'color'             => [ 'class' => self::VALUE ],
		'slider'            => [ 'class' => self::VALUE ],
		'map'               => [ 'class' => self::VALUE ],
		'osm'               => [ 'class' => self::VALUE ],
		'icon'              => [ 'class' => self::VALUE ],
		'autocomplete'      => [ 'class' => self::VALUE ],
		'background'        => [ 'class' => self::VALUE ],
		'sidebar'           => [ 'class' => self::VALUE ],
		'group'             => [ 'class' => self::STRUCTURE ],
		'password'          => [ 'class' => self::PASSWORD ],
		'heading'           => [ 'class' => self::IGNORE ],
		'divider'           => [ 'class' => self::IGNORE ],
		'custom_html'       => [ 'class' => self::IGNORE ],
		'button'            => [ 'class' => self::IGNORE ],
	];

	/**
	 * The class of an ACF field type; a third-party type is a value (keep rule).
	 *
	 * @param string $type ACF field type.
	 * @return array{class: string, format?: string, kind?: string}
	 */
	public static function acf( string $type ): array {
		return self::ACF[ $type ] ?? [ 'class' => self::VALUE ];
	}

	/**
	 * The class of a Meta Box field type.
	 *
	 * @param string $type Meta Box field type.
	 * @return array{class: string, format?: string, kind?: string}
	 */
	public static function metabox( string $type ): array {
		return self::METABOX[ $type ] ?? [ 'class' => self::VALUE ];
	}
}
