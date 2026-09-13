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

		// ...but never LANGUAGE-SCOPE it. A form is resolved by hash or by title
		// (`wpcf7_get_contact_form_by_hash()` / `..._by_title()`, both of which run
		// a real front-end WP_Query since CF7 5.8), never browsed by language. Once
		// the source carries a default-language link row, the language WHERE excludes
		// it on every other language and the embed falls through to CF7's
		// "Error: Contact form not found." — proven at runtime: an EN-linked form with
		// no DE translation returns 0 objects from WPCF7_ContactForm::find() on /de/.
		// Scoping this type does not filter the lookup, it breaks it; the same reason
		// wp_template_part is on the core list. Translation still happens — at render
		// time, in translate_form_properties() below, which is where it belongs.
		add_filter( 'perflocale/query/never_scoped_post_types', [ $this, 'never_scope_form_type' ] );

		// Don't copy CF7's per-form identity meta into a translation — duplicating
		// `_hash` makes two forms share one hash and breaks CF7's by-hash form
		// resolution. Rendering keys on post ID (translate_form_properties'
		// get_translation_id round-trip), so dropping it is safe.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_identity_meta' ], 10, 2 );

		// wpcf7_contact_form is registered with show_ui = false, so WordPress
		// has no `_edit_link` for it and PerfLocale's Translations screen would
		// render a dead link for every form. CF7's own editor keys on the post
		// ID and opens any form record, translations included.
		add_filter( 'perflocale/admin/edit_post_link', [ $this, 'edit_link' ], 10, 3 );

		// ⭐ A "Translations" box on CF7's OWN form editor — a screen none of the
		// existing panels can reach.
		//
		// CF7's editor is a custom admin page (admin.php?page=wpcf7&post=<id>
		// &action=edit), so `add_meta_boxes` on post.php never fires for it; CF7
		// does not call add_meta_box() anywhere, its postboxes being hand-built
		// markup. `wp.plugins.registerPlugin` does not exist there either, so
		// neither the block-editor nor the Site Editor sidebar can mount. The net
		// effect was that the addon whose objects most often need translating was
		// the one with no in-editor translations UI at all — you had to leave for
		// the Translations screen to find out whether a form had a German twin.
		//
		// `wpcf7_admin_misc_pub_section` fires inside the genuine `.postbox` in
		// CF7's right-hand column (contact-form-7/admin/edit-contact-form.php:293,
		// within #misc-publishing-actions in section#submitdiv.postbox) and is
		// handed the numeric post id.
		//
		// ⚠️ That id is literally -1 on the Add New screen
		// (contact-form-7/admin/admin.php:407), which is why the panel guards on
		// `> 0` rather than absint() — see TranslationsPanel::get_html().
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
	 * Reuses the classic metabox's sheet and its handle, so the box looks
	 * identical to the one on every other editor and the RTL twin is picked up
	 * automatically by Helper's late `perflocale*` stylesheet pass.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_panel_styles( string $hook_suffix ): void {
		// Gate on the SCREEN ID, which CF7 pins itself: it compares against the
		// literal 'toplevel_page_wpcf7' in admin/includes/welcome-panel.php.
		//
		// ⚠️ Do NOT extend this to CF7's "Add New" page by guessing its hook
		// suffix. That one is derived from `sanitize_title()` of the LOCALISED
		// menu title — plus whatever markup wpcf7_admin_menu_change_notice()
		// appends to it — so it is not a stable string on any site, let alone a
		// translated one. There is nothing to show there anyway: an unsaved form
		// has no id and no translations.
		if ( $hook_suffix !== 'toplevel_page_wpcf7' ) {
			return;
		}

		// The same screen id also serves the form LIST table, where no panel
		// renders. Requiring an open form keeps the CSS off that page.
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

		// CF7 keeps the authoritative form grammar in `_form` post meta and
		// reads it back from there itself (retrieve_property(),
		// includes/contact-form.php:332-346). post_content is NOT the form:
		// save() (:1263) stores an implode() over wpcf7_array_flatten( $props ),
		// a flattened dump of EVERY property - mail recipients, CC/BCC,
		// additional_headers, the mail body, attachment paths, messages,
		// additional_settings. CF7 renders prop( 'form' ) verbatim (:886), so
		// copying post_content in here published the whole mail configuration
		// into the public HTML of any page embedding the translated form. Read
		// the property, never the dump; when the translation carries no `_form`,
		// fall through to the source form CF7 already resolved - fail closed,
		// the direction CF7 itself takes.
		$translated_form = get_post_meta( $translated_id, '_form', true );

		if ( is_string( $translated_form ) && $translated_form !== '' ) {
			$properties['form'] = $translated_form;
		}

		// Replace translatable message properties from post meta.
		$message_keys = [ 'mail', 'mail_2', 'messages' ];

		foreach ( $message_keys as $key ) {
			$translated_value = get_post_meta( $translated_id, '_' . $key, true );

			if ( ! empty( $translated_value ) ) {
				$properties[ $key ] = $translated_value;
			}
		}

		// ⚠️ A translation owns its whole `mail` array, ROUTING INCLUDED. The
		// recipient, sender and additional_headers were copied from the source
		// when the translation was created and are never re-synced, so changing
		// the recipient on the English form does NOT change where German
		// submissions go — verified at runtime: source recipient edited to
		// NEW@example.com, the translation kept OLD@example.com.
		//
		// That is left as the DEFAULT on purpose. A different recipient per
		// language is a legitimate, common setup (German enquiries to the
		// German team), and silently overriding it would break those sites. The
		// operator is not left in the dark either: the same edit flips the
		// translation's link status to `needs_update`, which surfaces as a red
		// badge on the Translations screen — and since
		// ContentChangeDetector::clear_needs_update() landed, editing the
		// translation clears it again, so the signal is now a round trip rather
		// than a one-way door.
		//
		// Sites that would rather pin mail ROUTING to the source while keeping
		// the wording translated can opt in per key:
		//
		//   add_filter( 'perflocale/cf7/source_mail_keys', function () {
		//       return [ 'recipient', 'additional_headers' ];
		//   } );
		//
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
	 * Keep CF7's post type out of the language WHERE clause.
	 *
	 * Translatable (it gets translation records) but never scoped (its lookups
	 * are by hash/title, not by language). See the rationale in boot().
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
