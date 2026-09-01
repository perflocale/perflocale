<?php
/**
 * PerfLocale Contact Form 7 addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact Form 7 integration for PerfLocale.
 *
 * Translates form content and mail components by hooking into
 * CF7's property and mail filters.
 */
final class PerfLocaleContactForm7 implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'contact-form-7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Contact Form 7';
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
		return [ 'contact-form-7/wp-contact-form-7.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'WPCF7_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// translate_form_properties() already replaces the mail / mail_2 /
		// messages properties from the duplicated form's native CF7 meta, so the
		// composed mail is translated at the source. A separate mail-components
		// filter (which read never-written _perflocale_mail_* meta) is redundant
		// and was a dead no-op, so it is intentionally not registered.
		add_filter( 'wpcf7_contact_form_properties', [ $this, 'translate_form_properties' ], 10, 2 );

		// Register CF7 post type as translatable.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// Don't copy CF7's per-form identity meta into a translation — duplicating
		// `_hash` makes two forms share one hash and breaks CF7's by-hash form
		// resolution. Rendering keys on post ID (translate_form_properties'
		// get_translation_id round-trip), so dropping it is safe.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_identity_meta' ], 10, 2 );
	}

	/**
	 * Exclude CF7's per-form identity meta from translation copying.
	 *
	 * @param array<int, string> $excluded Meta keys excluded from copying.
	 * @param int                $source_id Source post being translated.
	 * @return array<int, string>
	 */
	public function exclude_identity_meta( array $excluded, int $source_id ): array {
		if ( get_post_type( $source_id ) === 'wpcf7_contact_form' ) {
			$excluded[] = '_hash';
			$excluded[] = '_old_cf7_unit_id';
		}

		return $excluded;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Serve translated form properties (form markup, messages) for the current language.
	 *
	 * Looks up the translated CF7 form post via the translation group
	 * system and replaces the form and message properties with translated content.
	 *
	 * @param array<string, mixed> $properties Form properties.
	 * @param \WPCF7_ContactForm   $form Contact Form 7 form instance.
	 * @return array<string, mixed> Filtered properties.
	 */
	public function translate_form_properties( array $properties, $form ): array {
		// CF7 invokes the properties filter during WPCF7_ContactForm::__construct()
		// for template/unsaved forms where id() is null. Nothing to translate
		// yet - bail before passing null to the int-typed lookup.
		$form_id = ( is_object( $form ) && method_exists( $form, 'id' ) ) ? $form->id() : null;

		if ( ! is_int( $form_id ) || $form_id <= 0 ) {
			return $properties;
		}

		$translated_id = $this->get_translated_form_id( $form_id );

		if ( $translated_id === null ) {
			return $properties;
		}

		$translated_post = get_post( $translated_id );

		if ( ! $translated_post ) {
			return $properties;
		}

		// Replace the form markup with the translated version.
		if ( ! empty( $translated_post->post_content ) ) {
			$properties['form'] = $translated_post->post_content;
		}

		// Replace translatable message properties from post meta.
		$message_keys = [ 'mail', 'mail_2', 'messages' ];

		foreach ( $message_keys as $key ) {
			$translated_value = get_post_meta( $translated_id, '_' . $key, true );

			if ( ! empty( $translated_value ) ) {
				$properties[ $key ] = $translated_value;
			}
		}

		return $properties;
	}

	/**
	 * Add Contact Form 7 post type to translatable list.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'wpcf7_contact_form';

		return array_unique( $post_types );
	}

	/**
	 * Get the translated CF7 form post ID for the current language.
	 *
	 * @param int $form_id Original form post ID.
	 * @return int|null Translated post ID or null.
	 */
	private function get_translated_form_id( int $form_id ): ?int {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return null;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return null;
		}

		// Default language serves the original form — skip the lookup.
		$default = $router->get_default_language();
		if ( $default && $current_slug === $default->slug ) {
			return null;
		}

		// PostTranslationManager is NOT a registered container service — it is
		// constructed on demand everywhere else in the plugin. The previous
		// $plugin->get('post_translation_manager') silently failed the has()
		// guard, so CF7 form translation never resolved. Construct it directly.
		if ( ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return null;
		}

		$manager       = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		$translated_id = $manager->get_translation_id( $form_id, $current_slug );

		if ( $translated_id === null || $translated_id === $form_id ) {
			return null;
		}

		return $translated_id;
	}
}
