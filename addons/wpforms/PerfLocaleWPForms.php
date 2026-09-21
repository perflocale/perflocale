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
 * Translates form field labels, descriptions, placeholders, choices, the
 * submit button text and the confirmation message per language by reading them
 * from the duplicated (translated) form post — the WPForms CPT is registered as
 * translatable, so a translation is a copy of the form the user edits in the
 * native builder.
 *
 * The swap happens at two different times. Field strings and submit text are
 * replaced at RENDER. The confirmation message is replaced at SUBMIT, because
 * WPForms re-reads the form from the database when it processes an entry and
 * never looks at the rendered form data.
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

		// Confirmations are decided at SUBMIT time, from form data WPForms
		// re-reads out of the database: WPForms_Process::process() filters
		// wpforms_decode( $form->post_content ) through this hook and never
		// consults `wpforms_frontend_form_data`, which is a RENDER filter.
		// Extending translate_form_data() to cover confirmations would look
		// like a fix in the diff and change nothing at runtime.
		add_filter( 'wpforms_process_before_form_data', [ $this, 'translate_confirmation' ] );

		// Register the WPForms CPT as translatable: a translation is a duplicated
		// form the user edits in the native builder. The form definition lives in
		// post_content (JSON), so there is no separate translatable meta key.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// ...but never language-scope it. The visitor-facing paths are safe on
		// their own — `[wpforms id="123"]` and the block both resolve through
		// `WPFormsForm_Handler::get_single()`, which ends in
		// `get_post( absint( $id ) )` (includes/class-form.php:294), a direct
		// row read the query filter never sees. The LIST path is not:
		// `get( '' )` falls to `get_multiple()` →
		// `get_posts( [ 'post_type' => 'wpforms', 'suppress_filters' => false ] )`
		// (:243/:347/:369), and that explicit `suppress_filters => false` means
		// PerfLocale's WHERE is applied.
		//
		// That list runs on the FRONT END, not only in wp-admin: Beaver
		// Builder renders `WP_Widget::form()` through its own front-end AJAX
		// handler (`add_action( 'wp', … )`, chosen precisely because wp_ajax
		// "only works in the admin"), so `includes/class-widget.php:140` runs
		// with `is_admin() === false` on the edited page's own translated
		// permalink. Every default-language-linked form is then dropped and the
		// widget's form picker reads "No forms".
		//
		// Editor-facing rather than visitor-facing, so this is a low-severity
		// member of the class — but the registration costs nothing and scoping
		// this type protects nothing, since forms are resolved by id and the
		// translation is swapped in at render time by translate_form_data()
		// via a link-table lookup, never a WP_Query.
		add_filter( 'perflocale/query/never_scoped_post_types', [ $this, 'never_scope_form_type' ] );

		// The wpforms CPT is show_ui = false, so WordPress has no `_edit_link`
		// and the Translations screen would render a dead link. Send it to the
		// native builder the comment above already points users at.
		add_filter( 'perflocale/admin/edit_post_link', [ $this, 'edit_link' ], 10, 3 );

		// ⭐ ANSWER THE PERMISSION QUESTION CORE CANNOT.
		//
		// WPForms registers its type with `'capability_type' => 'wpforms_form'` and
		// `'map_meta_cap' => false` (wpforms-lite/includes/class-form.php:102-103) and
		// then adds no `map_meta_cap` filter anywhere — `src/Access/Capabilities.php`
		// in Lite is a stub returning `manage_options`. Core therefore maps
		// `current_user_can( 'edit_post', $form_id )` to the PRIMITIVE capability
		// `edit_wpforms_form`, which no role holds, so the answer was `false` for
		// EVERY user. Measured as user 1 (administrator, `is_super_admin()` true):
		//
		//     current_user_can( 'edit_post', 287348 )                === false
		//     wpforms_current_user_can( 'edit_form_single', 287348 )  === true
		//
		// Every per-object translation route gates on that check, so translating a
		// WPForms form was refused for everyone even though the plugin advertises the
		// type as translatable. Contact Form 7 has the identical registration but DOES
		// add the filter, which is why CF7 worked and this did not.
		//
		// The authority deferred to is the host's OWN access object, not a capability
		// invented on WPForms' behalf, so a site running the Pro Access addon keeps its
		// granular per-form rules. See answer_object_permission() for why the object is
		// called directly rather than through `wpforms_current_user_can()`.
		add_filter( 'perflocale/object/user_can', [ $this, 'answer_object_permission' ], 10, 4 );

		// A Translations box in WPForms' own form builder — another screen none of the
		// existing panels reach: the builder is a custom admin page, `wpforms` is
		// registered `show_ui => false` so there is no post.php screen and no metabox,
		// and `wp.plugins.registerPlugin` does not exist there.
		if ( is_admin() ) {
			// `wpforms_builder_after_panel_sidebar` fires inside `.wpforms-panel-sidebar`
			// and receives ( WP_Post $form, string $panel_slug )
			// (includes/admin/builder/panels/class-base.php:291).
			//
			// ⚠️ It fires for EVERY panel that has a sidebar — fields, settings,
			// revisions, payments, providers — so an unguarded mount would render the
			// box five times in one page. The resolver returns 0 for every panel but
			// Settings, and the renderer draws nothing for an id <= 0. Settings is the
			// right home: it is the form-level configuration panel, the analogue of the
			// post editor's document sidebar.
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

			// ⚠️ WPForms DELETES the style registry on its builder screen, keeping only
			// an allowlist: `wp_styles()->registered = array_intersect_key( ... )`
			// (includes/admin/builder/class-builder.php:282). A stylesheet enqueued the
			// ordinary way is silently dropped and the box renders unstyled, with no
			// error. Adding the handle to the host's own documented allowlist filter is
			// the supported way in — and is why the enqueue below can stay ordinary.
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
	 * Answer whether the current user may act on a WPForms form.
	 *
	 * Only ever consulted when core has already said NO, and only for this addon's own
	 * post type — so it can widen access for `wpforms` and can never revoke access core
	 * granted for anything else. Callers apply their own `perflocale_translate` or
	 * per-object checks as well, so it never hands translation rights to a user who
	 * has none.
	 *
	 * ⚠️ Keep this on the host's access object, not `wpforms_current_user_can()`. That
	 * wrapper memoises per capability and form id without the user in the key
	 * (wpforms-lite/includes/functions/access.php), which suits WPForms' one-user web
	 * requests but not a permission check that may run for several users in one
	 * process (WP-CLI, a background job).
	 *
	 * So this calls the host's access object directly and then applies the host's own
	 * documented `wpforms_current_user_can` filter so a site that customises WPForms
	 * permissions still has that honoured. That is what the wrapper does, minus the
	 * cache.
	 *
	 * Fails CLOSED: with no access object, core's original answer stands.
	 *
	 * 'read' (asked, together with 'edit', about the form a new translation is
	 * copied from) is answered with edit_form_single like 'edit': stricter than
	 * WPForms' view capability, so it never grants more than the 'edit' half.
	 *
	 * 'create' means "may create a form" and is asked about an existing form: the
	 * one a new translation would be copied from, when one is about to be created,
	 * and the one the Translations panel lists, to decide whether to offer Create.
	 * It is answered with create_forms, WPForms' own form-creation capability,
	 * which WPForms always checks without a form id.
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

		// is_callable(), not method_exists(): the latter is FALSE for a method routed
		// through __call(), which would silently disable this on any version that uses
		// magic dispatch.
		if ( ! is_object( $access ) || ! is_callable( [ $access, 'current_user_can' ] ) ) {
			return $can;
		}

		// WPForms' own capability names. 'delete' covers both the DELETE route and a
		// `status=trash` update, which PerfLocale gates identically; 'create' is the
		// form-level create_forms, which is not about an existing form and so is asked
		// with no id; every other action, 'read' included, needs edit_form_single.
		$cap = match ( $action ) {
			'delete' => 'delete_form_single',
			'create' => 'create_forms',
			default  => 'edit_form_single',
		};
		$id = 'create' === $action ? 0 : $post_id;

		$user_can = (bool) $access->current_user_can( $cap, $id );

		/**
		 * This is the HOST's filter, re-applied here because this method deliberately
		 * bypasses the host wrapper that would normally apply it. Documented by WPForms
		 * with exactly this signature (access.php:253-261).
		 */
		return (bool) apply_filters( 'wpforms_current_user_can', $user_can, $cap, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPForms' own filter, re-applied because this method bypasses the host wrapper that normally applies it.
	}

	/**
	 * Load the panel's stylesheet on the WPForms form builder.
	 *
	 * Reuses the classic metabox's sheet and handle, so the box matches every other
	 * editor and Helper's late `perflocale*` pass supplies the RTL twin for free.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_panel_styles( string $hook_suffix ): void {
		// WPForms pins this string itself:
		// `add_action( 'load-wpforms_page_wpforms-builder', ... )`
		// (includes/admin/builder/class-builder.php:131).
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
	 * Translate form field labels, descriptions, and placeholders.
	 *
	 * The parameter cannot be type-declared. WPForms' AJAX submit handler
	 * feeds this filter the raw return of WPForms_Form_Handler::get()
	 * (includes/class-process.php:2096), and that method returns the boolean
	 * `false` whenever the posted form id resolves to no readable form
	 * (includes/class-form.php:245) — a trashed, deleted or unknown form. An
	 * `array` declaration would make that a TypeError raised during argument
	 * binding, before the is_admin() early return below, instead of WPForms'
	 * own error response.
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
	 * Swap the confirmation message for the visitor's language at submit time.
	 *
	 * Only `message`-type confirmations are touched. Everything else in the
	 * form data — notifications, spam settings, the form id submissions route
	 * to — is returned untouched, so a translated form stays on one pipeline.
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
	 * On a normal submit the router already knows. On AJAX — which every form
	 * built from a WPForms template uses — the request is to admin-ajax.php, so
	 * the URL carries no language and the language cookie is not guaranteed to
	 * be set. WPForms posts the embedding post id and trusts it itself to set
	 * the global $post; here it is only cast to an int and resolved through the
	 * translation table, never used to load anything.
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
	 * Deliberately duplicates the resolution translate_form_data() does inline
	 * rather than sharing it, so the shipped render path stays byte-identical.
	 * No publish-status gate: a translation is a duplicated form the operator
	 * edits in the native builder and its status is not a publication decision.
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

	/**
	 * Keep the WPForms CPT out of the language WHERE clause.
	 *
	 * Translatable (it gets translation records) but never scoped (forms are
	 * resolved by id and translated at render). See the rationale in boot().
	 *
	 * @param array<int, string> $post_types Never-scoped post types.
	 * @return array<int, string>
	 */
	public function never_scope_form_type( array $post_types ): array {
		$post_types[] = 'wpforms';

		return array_unique( $post_types );
	}
}
