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
 * WPForms integration: a translation is a duplicated form whose strings replace the source's per language.
 *
 * Field strings are swapped at render, the confirmation message at submit (WPForms re-reads the form from the database).
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
		add_filter( 'wpforms_frontend_form_data', [ $this, 'translate_form_data' ] );

		// Confirmations come from the form WPForms re-reads at submit, never from the render filter above.
		add_filter( 'wpforms_process_before_form_data', [ $this, 'translate_confirmation' ] );

		// The form definition is post_content JSON, so there is no translatable meta key.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// Never scoped: the form list (get_posts, suppress_filters false) also runs in Beaver Builder's front-end widget form.
		add_filter( 'perflocale/query/never_scoped_post_types', [ $this, 'never_scope_form_type' ] );

		// The wpforms CPT is show_ui = false, so core has no edit link: use the native builder.
		add_filter( 'perflocale/admin/edit_post_link', [ $this, 'edit_link' ], 10, 3 );

		// WPForms sets map_meta_cap false with no filter, so core's edit_post is false for every user.
		add_filter( 'perflocale/object/user_can', [ $this, 'answer_object_permission' ], 10, 4 );

		// A Translations box in the form builder: a custom admin page with no post.php screen or metabox.
		if ( is_admin() ) {
			// This hook fires for every panel with a sidebar: mount on Settings only, or the box renders five times.
			$plugin->get( 'translations_panel' )->mount(
				'wpforms_builder_after_panel_sidebar',
				[
					'accepted_args' => 2,
					'context'       => 'wpforms',
					'resolve'       => static function ( $form = null, $panel = '' ): int {
						if ( $panel !== 'settings' || ! $form instanceof \WP_Post ) {
							return 0;
						}

						return (int) $form->ID;
					},
				]
			);

			// The builder drops every style not on its allowlist, so the panel's handle must be listed.
			add_filter(
				'wpforms_admin_builder_allowed_common_wp_admin_styles',
				static function ( $handles ): array {
					$handles   = is_array( $handles ) ? $handles : [];
					$handles[] = 'perflocale-metabox';

					return $handles;
				}
			);

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_panel_styles' ] );
		}
	}

	/**
	 * Answer whether the current user may act on a WPForms form, only after core said no; fails closed.
	 *
	 * Not wpforms_current_user_can(): it memoises without the user in the key, wrong for CLI and jobs.
	 * See docs-ai/11-code-rationale.md, "PerfLocaleWPForms: object permission".
	 *
	 * @param bool   $can       Whether core's capability check passed.
	 * @param string $action    Semantic action: 'edit', 'delete', 'read' or 'create'.
	 * @param int    $post_id   Object being acted on.
	 * @param string $post_type Its post type.
	 * @return bool
	 */
	public function answer_object_permission( bool $can, string $action, int $post_id, string $post_type ): bool {
		if ( $can || 'wpforms' !== $post_type ) {
			return $can;
		}

		if ( ! function_exists( 'wpforms' ) ) {
			return $can;
		}

		$wpforms = wpforms();

		if ( ! is_object( $wpforms ) || ! is_callable( [ $wpforms, 'obj' ] ) ) {
			return $can;
		}

		$access = $wpforms->obj( 'access' );

		// is_callable(), not method_exists(): a method routed through __call() must count.
		if ( ! is_object( $access ) || ! is_callable( [ $access, 'current_user_can' ] ) ) {
			return $can;
		}

		// 'create' is form-level, so it is asked with no id; 'read' needs edit_form_single like 'edit'.
		$cap = match ( $action ) {
			'delete' => 'delete_form_single',
			'create' => 'create_forms',
			default  => 'edit_form_single',
		};
		$id = 'create' === $action ? 0 : $post_id;

		$user_can = (bool) $access->current_user_can( $cap, $id );

		/**
		 * WPForms' own filter, re-applied because this method bypasses the host wrapper.
		 */
		return (bool) apply_filters( 'wpforms_current_user_can', $user_can, $cap, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPForms' own filter, re-applied because this method bypasses the host wrapper that normally applies it.
	}

	/**
	 * Load the panel's stylesheet on the WPForms form builder.
	 *
	 * Reuses the metabox handle, so Helper's `perflocale*` pass adds the RTL twin.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_panel_styles( string $hook_suffix ): void {
		// The builder's hook suffix, as WPForms itself pins it.
		if ( $hook_suffix !== 'wpforms_page_wpforms-builder' ) {
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
	 * Point the Translations screen at the native WPForms builder.
	 *
	 * @param string $url       URL resolved so far ('' when core could not).
	 * @param int    $post_id   Object being linked.
	 * @param string $post_type Its post type.
	 * @return string
	 */
	public function edit_link( string $url, int $post_id, string $post_type ): string {
		if ( 'wpforms' !== $post_type ) {
			return $url;
		}

		return admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . $post_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Translate form field labels, descriptions, placeholders, choices and submit text.
	 *
	 * Untyped: WPForms' AJAX submit passes `false` for an unknown form, and an array type would fatal.
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

		// The default language renders the original strings.
		$default = $router->get_default_language();
		if ( $default && $slug === $default->slug ) {
			return $form_data;
		}

		$form_id = (int) ( $form_data['id'] ?? 0 );

		if ( $form_id === 0 || ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return $form_data;
		}

		// Merge the duplicate's strings by field id, keeping the source form id so submissions route to it.
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
	 * Swap the confirmation message for the visitor's language at submit time.
	 *
	 * Only `message` confirmations change, so a translated form stays on the source's pipeline.
	 *
	 * @param mixed $form_data Form data as WPForms decoded it; `false` or `[]`
	 *                         when post_content is empty or malformed.
	 * @return mixed The same value, with translated confirmation messages.
	 */
	public function translate_confirmation( $form_data ) {
		if ( ! is_array( $form_data ) || ! isset( $form_data['settings'] ) || ! is_array( $form_data['settings'] ) ) {
			return $form_data;
		}

		if ( empty( $form_data['settings']['confirmations'] ) || ! is_array( $form_data['settings']['confirmations'] ) ) {
			return $form_data;
		}

		$form_id = (int) ( $form_data['id'] ?? 0 );

		if ( $form_id === 0 ) {
			return $form_data;
		}

		$slug = $this->resolve_submit_slug();

		if ( $slug === '' ) {
			return $form_data;
		}

		$translated = $this->get_translated_form( $form_id, $slug );

		if ( $translated === null
			|| empty( $translated['settings']['confirmations'] )
			|| ! is_array( $translated['settings']['confirmations'] ) ) {
			return $form_data;
		}

		$translated_confirmations = $translated['settings']['confirmations'];

		foreach ( $form_data['settings']['confirmations'] as $id => $confirmation ) {
			if ( ! is_array( $confirmation ) || ( $confirmation['type'] ?? '' ) !== 'message' ) {
				continue;
			}

			$message = $translated_confirmations[ $id ]['message'] ?? '';

			if ( is_string( $message ) && $message !== '' ) {
				$form_data['settings']['confirmations'][ $id ]['message'] = $message;
			}
		}

		return $form_data;
	}

	/**
	 * Which language the submission was made in, or '' to keep the source message.
	 *
	 * An AJAX submit URL carries no language, so the posted embedding post id is resolved through the links.
	 *
	 * @return string Language slug, or '' for the source language.
	 */
	private function resolve_submit_slug(): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) || ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return '';
		}

		$router  = $plugin->get( 'router' );
		$default = $router->get_default_language();

		if ( ! wp_doing_ajax() ) {
			$slug = $router->get_current_slug();

			return ( $slug === '' || ( $default && $slug === $default->slug ) ) ? '' : $slug;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only language hint on an unauthenticated endpoint; cast to int and resolved through translation_links, never used as an id and never written.
		$post_id = isset( $_POST['wpforms']['post_id'] ) ? absint( $_POST['wpforms']['post_id'] ) : 0;

		if ( $post_id === 0 ) {
			return '';
		}

		$manager  = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		$language = $manager->detect_post_language( $post_id );

		// detect_post_language() returns the language ROW, or null.
		$slug = is_object( $language ) && isset( $language->slug ) ? (string) $language->slug : '';

		return ( $slug === '' || ( $default && $slug === $default->slug ) ) ? '' : $slug;
	}

	/**
	 * Decode the duplicated form for a language, or null when there is none.
	 *
	 * No publish-status gate: a form's status is not a publication decision.
	 *
	 * @param int    $form_id Source form id.
	 * @param string $slug    Target language slug.
	 * @return array<string, mixed>|null
	 */
	private function get_translated_form( int $form_id, string $slug ): ?array {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return null;
		}

		$manager       = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		$translated_id = $manager->get_translation_id( $form_id, $slug );

		if ( $translated_id === null || $translated_id === $form_id ) {
			return null;
		}

		$translated_post = get_post( $translated_id );

		if ( ! $translated_post || $translated_post->post_content === '' ) {
			return null;
		}

		$translated = function_exists( 'wpforms_decode' )
			? wpforms_decode( $translated_post->post_content )
			: json_decode( $translated_post->post_content, true );

		return is_array( $translated ) ? $translated : null;
	}

	/**
	 * Register the WPForms CPT as a translatable post type.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'wpforms';

		return array_unique( $post_types );
	}

	/**
	 * Keep the WPForms CPT out of the language WHERE clause.
	 *
	 * Forms are resolved by id and translated at render, so scoping protects nothing and empties form pickers.
	 *
	 * @param array<int, string> $post_types Never-scoped post types.
	 * @return array<int, string>
	 */
	public function never_scope_form_type( array $post_types ): array {
		$post_types[] = 'wpforms';

		return array_unique( $post_types );
	}
}
