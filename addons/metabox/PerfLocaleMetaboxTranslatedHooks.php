<?php
/**
 * PerfLocale Meta Box addon: the translated-page hooks.
 *
 * Loaded only by PerfLocaleMetabox::serve_translated_request() on a request in a language other than the default one.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The translated-page hooks of the Meta Box addon.
 *
 * Each hands work to PerfLocaleMetaboxTranslated only when there is some, so a page without Meta Box output never loads it.
 */
final class PerfLocaleMetaboxTranslatedHooks {

	/**
	 * Whether the translated-page hooks are attached.
	 *
	 * @var bool
	 */
	private bool $language_hooks_attached = false;

	/**
	 * Build the hooks.
	 *
	 * @param \Closure(): \PerfLocaleMetaboxTranslated $translated      The addon's translated-request object, loaded on first use.
	 * @param \Closure(): string                       $translated_slug The current language slug when it is not the default one.
	 */
	public function __construct( private \Closure $translated, private \Closure $translated_slug ) {}

	/**
	 * Attach the translated-page hooks and follow the rendering windows that impose or restore a language.
	 *
	 * @return void
	 */
	public function serve(): void {
		add_action( 'perflocale/language/overridden', [ $this, 'attach_language_hooks' ], 20 );
		add_action( 'perflocale/language/restored', [ $this, 'attach_language_hooks' ], 20 );
		$this->attach_language_hooks();
	}

	/**
	 * Attach the translated-page hooks for the current language.
	 *
	 * Runs again when a rendering window imposes or restores a language: a default-language window detaches them.
	 *
	 * @return void
	 */
	public function attach_language_hooks(): void {
		if ( $this->language_hooks_attached ) {
			remove_filter( 'render_block_data', [ $this, 'add_block_render_language' ], 10 );
			remove_filter( '_rwmb_post_format_single_value', [ $this, 'translate_post_format_value' ], 10 );
			remove_filter( 'rwmb_field_class', [ $this, 'term_display_field_class' ], 10 );
			remove_filter( 'rwmb_raw_meta', [ $this, 'prime_raw_meta' ], 10 );
			$this->language_hooks_attached = false;
		}

		if ( ( $this->translated_slug )() === '' ) {
			return;
		}

		add_filter( 'render_block_data', [ $this, 'add_block_render_language' ], 10, 1 );
		add_filter( '_rwmb_post_format_single_value', [ $this, 'translate_post_format_value' ], 10, 2 );
		add_filter( 'rwmb_field_class', [ $this, 'term_display_field_class' ], 10, 2 );
		add_filter( 'rwmb_raw_meta', [ $this, 'prime_raw_meta' ], 10, 2 );
		$this->language_hooks_attached = true;
	}

	/**
	 * `render_block_data`: a Meta Box block is handed to
	 * PerfLocaleMetaboxTranslated::add_block_render_language(); every other
	 * block comes back as it is, without loading that class.
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

		return ( $this->translated )()->add_block_render_language( $parsed_block );
	}

	/**
	 * `_rwmb_post_format_single_value`: Meta Box prints a `post` field;
	 * PerfLocaleMetaboxTranslated::translate_post_format_value() maps it.
	 *
	 * @param mixed $value The stored post ID.
	 * @param mixed $field Field settings.
	 * @return mixed
	 */
	public function translate_post_format_value( mixed $value, mixed $field = null ): mixed {
		return ( $this->translated )()->translate_post_format_value( $value, $field );
	}

	/**
	 * `rwmb_field_class`: the `taxonomy_advanced` field class is handed to
	 * PerfLocaleMetaboxTranslated::term_display_field_class(); every other
	 * class comes back as it is, without loading that class.
	 *
	 * @param mixed $class_name Field class name.
	 * @param mixed $type       Field type.
	 * @return mixed
	 */
	public function term_display_field_class( mixed $class_name, mixed $type = '' ): mixed {
		if ( 'RWMB_Taxonomy_Advanced_Field' !== $class_name ) {
			return $class_name;
		}

		return ( $this->translated )()->term_display_field_class( $class_name, $type );
	}

	/**
	 * `rwmb_raw_meta`: a non-empty value of a `post`, `taxonomy_advanced` or
	 * `group` field is handed to PerfLocaleMetaboxTranslated::prime_raw_meta();
	 * every other value comes back as it is, without loading that class.
	 *
	 * @param mixed $value The stored value.
	 * @param mixed $field Field settings.
	 * @return mixed
	 */
	public function prime_raw_meta( mixed $value, mixed $field = null ): mixed {
		if ( ! is_array( $field ) || empty( $value ) || ! in_array( $field['type'] ?? '', [ 'post', 'taxonomy_advanced', 'group' ], true ) ) {
			return $value;
		}

		return ( $this->translated )()->prime_raw_meta( $value, $field );
	}
}
