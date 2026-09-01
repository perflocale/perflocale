<?php
/**
 * PerfLocale Gravity Forms addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gravity Forms integration for PerfLocale.
 *
 * Translates form field labels, descriptions, placeholders, choices, and
 * confirmations at render time. Gravity Forms keeps forms in its own
 * gf_form* tables — a GF form id is NOT a wp_posts id — so per-form
 * translations live in a per-form option keyed by the GF form id, with one
 * entry per language slug. Translations are written programmatically via
 * save_translations() or supplied at render time through the
 * `perflocale/gravity_forms/form_translations` filter.
 */
final class PerfLocaleGravityForms implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Option-name prefix for per-form translation storage. The GF form id is
	 * appended; the option value is an array keyed by language slug.
	 */
	private const OPTION_PREFIX = 'perflocale_gf_translations_';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'gravity-forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Gravity Forms';
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
		return [ 'gravityforms/gravityforms.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return class_exists( 'GFAPI' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		add_filter( 'gform_pre_render', [ $this, 'translate_form' ] );
		add_filter( 'gform_pre_validation', [ $this, 'translate_form' ] );
		add_filter( 'gform_pre_submission_filter', [ $this, 'translate_form' ] );

		// GF deletes forms from its own tables (no WP post lifecycle fires),
		// so drop the per-form translations option on GF's own delete hook.
		add_action( 'gform_after_delete_form', [ $this, 'on_form_deleted' ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Translate form field labels, descriptions, and confirmations.
	 *
	 * Reads stored translations for the current language from the per-form
	 * option and applies them to form fields before rendering.
	 *
	 * @param array<string, mixed> $form Gravity Forms form array.
	 * @return array<string, mixed> Translated form array.
	 */
	public function translate_form( array $form ): array {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $form;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return $form;
		}

		// Default language renders the original strings — skip the lookup.
		$default = $router->get_default_language();
		if ( $default && $current_slug === $default->slug ) {
			return $form;
		}

		$form_id = absint( $form['id'] ?? 0 );

		if ( $form_id === 0 ) {
			return $form;
		}

		$translations = $this->get_translations( $form_id, $current_slug );

		/** @hook perflocale/gravity_forms/form_translations Filter the translations applied to a Gravity Forms form for the current language. */
		$translations = apply_filters( 'perflocale/gravity_forms/form_translations', $translations, $form_id, $current_slug );

		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return $form;
		}

		$form = $this->translate_form_fields( $form, $translations );
		$form = $this->translate_confirmations( $form, $translations );

		return $form;
	}

	/**
	 * Get the stored translations for a form in one language.
	 *
	 * @param int    $form_id       Gravity Forms form id.
	 * @param string $language_slug PerfLocale language slug.
	 * @return array<string, mixed> Translations array, empty when none stored.
	 */
	public function get_translations( int $form_id, string $language_slug ): array {
		$slug = sanitize_key( $language_slug );

		if ( $form_id <= 0 || $slug === '' ) {
			return [];
		}

		$all = get_option( self::OPTION_PREFIX . $form_id, [] );

		if ( ! is_array( $all ) ) {
			return [];
		}

		$stored = $all[ $slug ] ?? [];

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Store translations for a form in one language.
	 *
	 * Expected shape (every branch optional):
	 *
	 *   [
	 *     'fields'        => [ (string) field_id => [
	 *       'label'       => string,
	 *       'description' => string,
	 *       'placeholder' => string,
	 *       'choices'     => [ (int) choice_index => string ],
	 *     ] ],
	 *     'confirmations' => [ (string) confirmation_id => [
	 *       'message' => string,
	 *       'url'     => string,
	 *     ] ],
	 *   ]
	 *
	 * Unknown keys are discarded. An empty (post-sanitization) payload clears
	 * the language instead of storing an empty entry.
	 *
	 * @param int                  $form_id       Gravity Forms form id.
	 * @param string               $language_slug PerfLocale language slug.
	 * @param array<string, mixed> $translations  Translations to store.
	 * @return bool True if the stored value changed.
	 */
	public function save_translations( int $form_id, string $language_slug, array $translations ): bool {
		$slug = sanitize_key( $language_slug );

		if ( $form_id <= 0 || $slug === '' ) {
			return false;
		}

		$clean = $this->sanitize_translations( $translations );

		if ( empty( $clean ) ) {
			return $this->delete_translations( $form_id, $slug );
		}

		$all = get_option( self::OPTION_PREFIX . $form_id, [] );

		if ( ! is_array( $all ) ) {
			$all = [];
		}

		$all[ $slug ] = $clean;

		return update_option( self::OPTION_PREFIX . $form_id, $all, false );
	}

	/**
	 * Delete stored translations for a form.
	 *
	 * @param int         $form_id       Gravity Forms form id.
	 * @param string|null $language_slug Language slug, or null for all languages.
	 * @return bool True if anything was deleted.
	 */
	public function delete_translations( int $form_id, ?string $language_slug = null ): bool {
		if ( $form_id <= 0 ) {
			return false;
		}

		if ( $language_slug === null ) {
			return delete_option( self::OPTION_PREFIX . $form_id );
		}

		$slug = sanitize_key( $language_slug );
		$all  = get_option( self::OPTION_PREFIX . $form_id, [] );

		if ( ! is_array( $all ) || ! isset( $all[ $slug ] ) ) {
			return false;
		}

		unset( $all[ $slug ] );

		if ( empty( $all ) ) {
			return delete_option( self::OPTION_PREFIX . $form_id );
		}

		return update_option( self::OPTION_PREFIX . $form_id, $all, false );
	}

	/**
	 * Drop the per-form translations option when GF deletes the form.
	 *
	 * Untyped parameter: invoked by Gravity Forms' `gform_after_delete_form`
	 * action, so the id shape is outside this plugin's control.
	 *
	 * @param int|string $form_id Deleted Gravity Forms form id.
	 * @return void
	 */
	public function on_form_deleted( $form_id ): void {
		$this->delete_translations( absint( $form_id ) );
	}

	/**
	 * Whitelist a translations payload down to the applied shape.
	 *
	 * Mirrors the sanitization the apply path performs, so stored data is
	 * bounded and safe even if the apply-side filters change.
	 *
	 * @param array<string, mixed> $translations Raw translations payload.
	 * @return array<string, mixed> Sanitized payload.
	 */
	private function sanitize_translations( array $translations ): array {
		$clean = [];

		if ( ! empty( $translations['fields'] ) && is_array( $translations['fields'] ) ) {
			foreach ( $translations['fields'] as $field_id => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$entry = [];

				foreach ( [ 'label', 'description' ] as $key ) {
					if ( ! empty( $field[ $key ] ) && is_string( $field[ $key ] ) ) {
						$entry[ $key ] = wp_kses_post( $field[ $key ] );
					}
				}

				if ( ! empty( $field['placeholder'] ) && is_string( $field['placeholder'] ) ) {
					$entry['placeholder'] = sanitize_text_field( $field['placeholder'] );
				}

				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = [];

					foreach ( $field['choices'] as $index => $text ) {
						if ( is_string( $text ) && $text !== '' ) {
							$choices[ (int) $index ] = wp_kses_post( $text );
						}
					}

					if ( ! empty( $choices ) ) {
						$entry['choices'] = $choices;
					}
				}

				if ( ! empty( $entry ) ) {
					$clean['fields'][ (string) $field_id ] = $entry;
				}
			}
		}

		if ( ! empty( $translations['confirmations'] ) && is_array( $translations['confirmations'] ) ) {
			foreach ( $translations['confirmations'] as $id => $confirmation ) {
				if ( ! is_array( $confirmation ) ) {
					continue;
				}

				$entry = [];

				if ( ! empty( $confirmation['message'] ) && is_string( $confirmation['message'] ) ) {
					$entry['message'] = wp_kses_post( $confirmation['message'] );
				}

				if ( ! empty( $confirmation['url'] ) && is_string( $confirmation['url'] ) ) {
					$url = esc_url_raw( $confirmation['url'] );

					if ( $url !== '' ) {
						$entry['url'] = $url;
					}
				}

				if ( ! empty( $entry ) ) {
					$clean['confirmations'][ (string) $id ] = $entry;
				}
			}
		}

		return $clean;
	}

	/**
	 * Translate individual form field labels and descriptions.
	 *
	 * @param array<string, mixed> $form Form array.
	 * @param array<string, mixed> $translations Translation data keyed by field ID.
	 * @return array<string, mixed>
	 */
	private function translate_form_fields( array $form, array $translations ): array {
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $form;
		}

		foreach ( $form['fields'] as &$field ) {
			// GF fields are GF_Field objects; skip anything malformed so a
			// bad field definition (e.g. from a broken add-on) can't fatal.
			if ( ! is_object( $field ) || ! isset( $field->id ) ) {
				continue;
			}

			$field_id           = (string) $field->id;
			$field_translations = $translations['fields'][ $field_id ] ?? [];

			if ( empty( $field_translations ) ) {
				continue;
			}

			if ( ! empty( $field_translations['label'] ) ) {
				$field->label = wp_kses_post( $field_translations['label'] );
			}

			if ( ! empty( $field_translations['description'] ) ) {
				$field->description = wp_kses_post( $field_translations['description'] );
			}

			if ( ! empty( $field_translations['placeholder'] ) ) {
				$field->placeholder = sanitize_text_field( $field_translations['placeholder'] );
			}

			// Translate choices (dropdowns, radio buttons, checkboxes).
			// GF_Field->choices can be null, false, or a non-array for
			// field types without choices - guard before iterating.
			if ( ! empty( $field_translations['choices'] ) && is_array( $field->choices ?? null ) ) {
				foreach ( $field->choices as $index => &$choice ) {
					if ( ! is_array( $choice ) || ! isset( $field_translations['choices'][ $index ] ) ) {
						continue;
					}

					$choice['text'] = wp_kses_post( $field_translations['choices'][ $index ] );
				}
				unset( $choice );
			}
		}
		unset( $field );

		return $form;
	}

	/**
	 * Translate form confirmations.
	 *
	 * @param array<string, mixed> $form Form array.
	 * @param array<string, mixed> $translations Translation data.
	 * @return array<string, mixed>
	 */
	private function translate_confirmations( array $form, array $translations ): array {
		if ( empty( $form['confirmations'] ) || empty( $translations['confirmations'] ) ) {
			return $form;
		}

		foreach ( $form['confirmations'] as $id => &$confirmation ) {
			$conf_translations = $translations['confirmations'][ $id ] ?? [];

			if ( ! empty( $conf_translations['message'] ) && ( $confirmation['type'] ?? '' ) === 'message' ) {
				$confirmation['message'] = wp_kses_post( $conf_translations['message'] );
			}

			if ( ! empty( $conf_translations['url'] ) && ( $confirmation['type'] ?? '' ) === 'redirect' ) {
				$confirmation['url'] = esc_url_raw( $conf_translations['url'] );
			}
		}
		unset( $confirmation );

		return $form;
	}
}
