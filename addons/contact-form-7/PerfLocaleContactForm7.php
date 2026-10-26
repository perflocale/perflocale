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
 * Contact Form 7 integration: a translation is a duplicated form whose properties replace the source's per language.
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
		// Also swaps mail, mail_2 and messages, so the composed mail needs no filter of its own.
		add_filter( 'wpcf7_contact_form_properties', [ $this, 'translate_form_properties' ], 10, 2 );

		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// Never scoped: CF7 finds a form by hash or title with a front-end WP_Query, so scoping hides the form.
		add_filter( 'perflocale/query/never_scoped_post_types', [ $this, 'never_scope_form_type' ] );

		// A copied `_hash` would make two forms share one hash and break CF7's by-hash lookup.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_identity_meta' ], 10, 2 );

		// wpcf7_contact_form is show_ui = false, so core has no edit link: use CF7's own editor.
		add_filter( 'perflocale/admin/edit_post_link', [ $this, 'edit_link' ], 10, 3 );

		// A Translations box in CF7's own editor, a custom admin page with no metaboxes.
		// The hook passes -1 on Add New, so the panel guards on `> 0`, not absint().
		if ( is_admin() ) {
			$plugin->get( 'translations_panel' )->mount(
				'wpcf7_admin_misc_pub_section',
				[ 'context' => 'contact-form-7' ]
			);

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_panel_styles' ] );
		}
	}

	/**
	 * Load the panel's stylesheet on Contact Form 7's form editor.
	 *
	 * Reuses the metabox handle, so Helper's `perflocale*` pass adds the RTL twin.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_panel_styles( string $hook_suffix ): void {
		// CF7 pins this screen id itself; the Add New suffix comes from the localised menu title, so it is not matched.
		if ( $hook_suffix !== 'toplevel_page_wpcf7' ) {
			return;
		}

		// The same screen id serves the form list, where no panel renders.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads which screen is open; changes nothing.
		if ( absint( $_GET['post'] ?? 0 ) <= 0 ) {
			return;
		}

		wp_enqueue_style(
			'perflocale-metabox',
			PERFLOCALE_URL . 'assets/css/metabox.css',
			[],
			PERFLOCALE_VERSION
		);
	}

	/**
	 * Point the Translations screen at Contact Form 7's own form editor.
	 *
	 * @param string $url       URL resolved so far ('' when core could not).
	 * @param int    $post_id   Object being linked.
	 * @param string $post_type Its post type.
	 * @return string
	 */
	public function edit_link( string $url, int $post_id, string $post_type ): string {
		if ( 'wpcf7_contact_form' !== $post_type ) {
			return $url;
		}

		return admin_url( 'admin.php?page=wpcf7&post=' . $post_id . '&action=edit' );
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
	 * @param array<string, mixed> $properties Form properties.
	 * @param \WPCF7_ContactForm   $form Contact Form 7 form instance.
	 * @return array<string, mixed> Filtered properties.
	 */
	public function translate_form_properties( array $properties, $form ): array {
		// CF7 runs this filter in its constructor for unsaved forms, whose id() is null.
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

		// The form is `_form` meta: post_content is a flattened dump of every property, mail settings included.
		$translated_form = get_post_meta( $translated_id, '_form', true );

		if ( is_string( $translated_form ) && $translated_form !== '' ) {
			$properties['form'] = $translated_form;
		}

		$message_keys = [ 'mail', 'mail_2', 'messages' ];

		foreach ( $message_keys as $key ) {
			$translated_value = get_post_meta( $translated_id, '_' . $key, true );

			if ( ! empty( $translated_value ) ) {
				$properties[ $key ] = $translated_value;
			}
		}

		// A translation owns its whole mail array, routing included: a recipient per language is a common setup.
		/**
		 * Mail sub-keys taken from the SOURCE form instead of the translation.
		 *
		 * @hook  perflocale/cf7/source_mail_keys
		 * @since 1.0.5
		 *
		 * @param array<int, string> $keys      Sub-keys of CF7's mail/mail_2 arrays.
		 * @param int                $form_id   Source form post ID.
		 * @param int                $translated_id Translation post ID.
		 * @return array<int, string>
		 */
		$source_mail_keys = (array) apply_filters( 'perflocale/cf7/source_mail_keys', [], $form_id, $translated_id );

		if ( $source_mail_keys !== [] ) {
			foreach ( [ 'mail', 'mail_2' ] as $mail_key ) {
				if ( ! isset( $properties[ $mail_key ] ) || ! is_array( $properties[ $mail_key ] ) ) {
					continue;
				}

				$source_mail = get_post_meta( $form_id, '_' . $mail_key, true );

				if ( ! is_array( $source_mail ) ) {
					continue;
				}

				foreach ( $source_mail_keys as $sub_key ) {
					$sub_key = (string) $sub_key;

					if ( array_key_exists( $sub_key, $source_mail ) ) {
						$properties[ $mail_key ][ $sub_key ] = $source_mail[ $sub_key ];
					}
				}
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
	 * Keep CF7's post type out of the language WHERE clause: forms are found by hash or title.
	 *
	 * @param array<int, string> $post_types Never-scoped post types.
	 * @return array<int, string>
	 */
	public function never_scope_form_type( array $post_types ): array {
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

		$default = $router->get_default_language();
		if ( $default && $current_slug === $default->slug ) {
			return null;
		}

		// PostTranslationManager is not a container service, so it is constructed here.
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
