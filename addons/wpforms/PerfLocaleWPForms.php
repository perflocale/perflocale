<?php
/**
 * PerfLocale WPForms integration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms integration for PerfLocale.
 *
 * Translates form field labels, descriptions, placeholders, choices, and the
 * submit button text per language by reading them from the duplicated
 * (translated) form post — the WPForms CPT is registered as translatable, so a
 * translation is a copy of the form the user edits in the native builder.
 */
final class PerfLocaleWPForms implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'WPForms';
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
		return [ 'wpforms-lite/wpforms.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return function_exists( 'wpforms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Translate form fields on frontend render from the duplicated form.
		add_filter( 'wpforms_frontend_form_data', [ $this, 'translate_form_data' ] );

		// Register the WPForms CPT as translatable: a translation is a duplicated
		// form the user edits in the native builder. The form definition lives in
		// post_content (JSON), so there is no separate translatable meta key.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Translate form field labels, descriptions, and placeholders.
	 *
	 * The parameter cannot be type-declared. WPForms' AJAX submit handler
	 * feeds this filter the raw return of WPForms_Form_Handler::get()
	 * (includes/class-process.php:2096), and that method returns the boolean
	 * `false` whenever the posted form id resolves to no readable form
	 * (includes/class-form.php:245) — a trashed or deleted form, or any id a
	 * bot invents on the unauthenticated `wpforms_submit` endpoint. An `array`
	 * declaration would make that a TypeError raised during argument binding,
	 * before the is_admin() early return below, so a stray POST would fatal
	 * admin-ajax instead of getting WPForms' own error response.
	 *
	 * @param array<string, mixed>|mixed $form_data Form data array, or `false`
	 *                                              when WPForms could not load
	 *                                              the form.
	 * @return array<string, mixed>|mixed Translated form data; anything that is
	 *                                    not a form-data array is returned
	 *                                    unchanged.
	 */
	public function translate_form_data( $form_data ) {
		if ( ! is_array( $form_data ) ) {
			return $form_data;
		}

		if ( is_admin() ) {
			return $form_data;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $form_data;
		}

		$router = $plugin->get( 'router' );
		$slug   = $router->get_current_slug();

		if ( $slug === '' || ! isset( $form_data['fields'] ) ) {
			return $form_data;
		}

		// Default language renders the original strings — skip the per-field
		// meta lookups entirely.
		$default = $router->get_default_language();
		if ( $default && $slug === $default->slug ) {
			return $form_data;
		}

		$form_id = (int) ( $form_data['id'] ?? 0 );

		if ( $form_id === 0 || ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return $form_data;
		}

		// Resolve the translated (duplicated) form and read its translated field
		// strings — rather than never-written per-field meta. The duplicate has
		// the same field IDs, so merge by id and keep the original form id +
		// structure so submissions still route to the source form. (Construct the
		// manager directly — it is not a registered container service.)
		$manager       = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		$translated_id = $manager->get_translation_id( $form_id, $slug );

		if ( $translated_id === null || $translated_id === $form_id ) {
			return $form_data;
		}

		$translated_post = get_post( $translated_id );

		if ( ! $translated_post || $translated_post->post_content === '' ) {
			return $form_data;
		}

		$translated = function_exists( 'wpforms_decode' )
			? wpforms_decode( $translated_post->post_content )
			: json_decode( $translated_post->post_content, true );

		if ( ! is_array( $translated ) || empty( $translated['fields'] ) || ! is_array( $translated['fields'] ) ) {
			return $form_data;
		}

		$translated_fields = $translated['fields'];

		foreach ( $form_data['fields'] as $field_id => &$field ) {
			if ( ! isset( $translated_fields[ $field_id ] ) || ! is_array( $translated_fields[ $field_id ] ) ) {
				continue;
			}

			$tf = $translated_fields[ $field_id ];

			foreach ( [ 'label', 'description', 'placeholder' ] as $key ) {
				if ( ! empty( $field[ $key ] ) && ! empty( $tf[ $key ] ) ) {
					$field[ $key ] = $tf[ $key ];
				}
			}

			// Choices (select / radio / checkbox), matched by choice id.
			if ( ! empty( $field['choices'] ) && is_array( $field['choices'] )
				&& ! empty( $tf['choices'] ) && is_array( $tf['choices'] ) ) {
				foreach ( $field['choices'] as $choice_id => &$choice ) {
					if ( is_array( $choice ) && ! empty( $choice['label'] )
						&& ! empty( $tf['choices'][ $choice_id ]['label'] ) ) {
						$choice['label'] = $tf['choices'][ $choice_id ]['label'];
					}
				}

				unset( $choice );
			}
		}

		unset( $field );

		// Submit button text from the translated form.
		if ( ! empty( $form_data['settings']['submit_text'] ) && ! empty( $translated['settings']['submit_text'] ) ) {
			$form_data['settings']['submit_text'] = $translated['settings']['submit_text'];
		}

		return $form_data;
	}

	/**
	 * Register the WPForms CPT as a translatable post type, so a translation is
	 * a duplicated form whose post_content (the form JSON) carries the
	 * translated field strings.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'wpforms';

		return array_unique( $post_types );
	}
}
