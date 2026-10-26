<?php
/**
 * Admin assets.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues admin CSS and JavaScript on PerfLocale screens only, each screen with only what it uses.
 */
final class Assets {

	/**
	 * Enqueue one of the plugin's scripts (assets/js/<file>) in the footer.
	 *
	 * @param string             $handle Script handle.
	 * @param string             $file   File name under assets/js/.
	 * @param array<int, string> $deps   Handles it depends on.
	 * @return void
	 */
	public static function enqueue_script( string $handle, string $file, array $deps = [] ): void {
		$base    = \defined( 'PERFLOCALE_URL' ) ? \constant( 'PERFLOCALE_URL' ) : '';
		$version = \defined( 'PERFLOCALE_VERSION' ) ? \constant( 'PERFLOCALE_VERSION' ) : false;

		wp_enqueue_script(
			$handle,
			( is_string( $base ) ? $base : '' ) . 'assets/js/' . $file,
			$deps,
			is_string( $version ) ? $version : false,
			true
		);
	}

	/**
	 * One target language of a bulk machine-translation screen: its id and the Language object fields the screen's
	 * JavaScript hooks pass ({ slug, locale, name, nativeName, isDefault }).
	 *
	 * @param object $lang Language object.
	 * @return array{id: int, slug: string, locale: string, name: string, nativeName: string, isDefault: bool}
	 */
	public static function mt_target( object $lang ): array {
		$str    = static fn( $v ): string => is_scalar( $v ) ? (string) $v : '';
		$slug   = $str( $lang->slug ?? '' );
		$name   = $str( $lang->name ?? '' );
		$name   = '' !== $name ? $name : $slug;
		$native = $str( $lang->native_name ?? '' );

		return [
			'id'         => is_numeric( $lang->id ?? null ) ? (int) $lang->id : 0,
			'slug'       => $slug,
			'locale'     => $str( $lang->locale ?? '' ),
			'name'       => $name,
			'nativeName' => '' !== $native ? $native : $name,
			'isDefault'  => ! empty( $lang->is_default ),
		];
	}

	/**
	 * The counts of an in-request bulk machine translation's result notice (message=bulk_mt_done) for the
	 * perflocale.translations.bulkFinished hook; null otherwise. Read from the notice's query args only: core removes
	 * `message` from the address after the load, so a reload shows neither again.
	 *
	 * @return array{posts: int, target: string, translated: int, skipped: int, failed: int}|null
	 */
	public static function translations_bulk_finished(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: the counts of the notice this load shows.
		$arg = static fn( string $key ): string => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'bulk_mt_done' !== $arg( 'message' ) ) {
			return null;
		}

		return [
			'posts'      => absint( $arg( 'posts' ) ),
			'target'     => $arg( 'target' ),
			'translated' => absint( $arg( 'created' ) ),
			'skipped'    => absint( $arg( 'skipped' ) ),
			'failed'     => absint( $arg( 'failed' ) ),
		];
	}

	/**
	 * Make an enqueued script load after one a screen adds later (the Settings Translation tab adds the bulk request
	 * helper to admin-settings.js); footer scripts print after the screen, so it holds for this request.
	 *
	 * @param string $handle Script that needs $dep.
	 * @param string $dep    Script it needs.
	 * @return void
	 */
	public static function add_dependency( string $handle, string $dep ): void {
		$script = wp_scripts()->query( $handle );

		if ( $script instanceof \_WP_Dependency && ! in_array( $dep, $script->deps, true ) ) {
			$script->deps[] = $dep;
		}
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the admin assets of the current screen.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		// Only load on PerfLocale admin pages.
		if ( ! $this->is_perflocale_page( $hook ) ) {
			$screen = get_current_screen();
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( ! $screen || ! $plugin->has( 'settings' ) ) {
				return;
			}

			$settings  = $plugin->get( 'settings' );
			$needs_css = false;

			// Post list screens.
			if ( $hook === 'edit.php' ) {
				$needs_css = in_array( $screen->post_type, $settings->get_translatable_post_types(), true );
			}

			// Taxonomy term list screens.
			if ( $hook === 'edit-tags.php' || $hook === 'term.php' ) {
				$needs_css = in_array( $screen->taxonomy, $settings->get_translatable_taxonomies(), true );
			}

			if ( $needs_css ) {
				// List tables need only the badge rules: the small dedicated file, kept in sync with admin.css's "Translation
				// Status Badges" section.
				wp_enqueue_style(
					'perflocale-list-badges',
					PERFLOCALE_URL . 'assets/css/list-badges.css',
					[],
					PERFLOCALE_VERSION
				);
			}

			// Dashboard widget styles on the Dashboard only, under the widget's own gate (enabled, can manage translations).
			if (
				$hook === 'index.php'
				&& (bool) $settings->get( 'dashboard_widget_enabled', false )
				&& current_user_can( 'perflocale_manage_languages' )
			) {
				wp_enqueue_style(
					'perflocale-dashboard-widget',
					PERFLOCALE_URL . 'assets/css/dashboard-widget.css',
					[],
					PERFLOCALE_VERSION
				);
			}

			// The `perflocale-admin` carrier handle lets nearby screens get inline CSS and JS without downloading a file.
			$needs_carrier = (
				$hook === 'post.php'
				|| $hook === 'post-new.php'
				|| $hook === 'edit.php'
				|| $hook === 'nav-menus.php'
				|| $hook === 'edit-tags.php'
				|| $hook === 'term.php'
			);

			if ( $needs_carrier ) {
				wp_register_style( 'perflocale-admin', false, [], PERFLOCALE_VERSION );
				wp_enqueue_style( 'perflocale-admin' );
				wp_register_script( 'perflocale-admin', false, [], PERFLOCALE_VERSION, true );
				wp_enqueue_script( 'perflocale-admin' );
			}

			// The JS Abilities shim registers the PHP abilities with the WordPress JS Abilities API (7.0+) for editor tools;
			// feature-detected in the browser, enqueued only on editor screens.
			$default_screens = [ 'post.php', 'post-new.php', 'site-editor.php' ];

			/**
			 * Filter the admin screens that get the JS Abilities shim (for custom editor screens such as Greenshift or Bricks);
			 * without `wp.abilities.register` the script does nothing.
			 *
			 * @hook perflocale/abilities/js_screens
			 *
			 * @param string[] $screens Default screen hooks.
			 */
			$abilities_screens = (array) apply_filters( 'perflocale/abilities/js_screens', $default_screens );

			if ( in_array( $hook, $abilities_screens, true ) ) {
				$this->maybe_enqueue_abilities_shim();
			}

			return;
		}

		// Shared admin CSS; the handle stays 'perflocale-admin' so page classes' wp_add_inline_style() land on a loaded handle.
		wp_enqueue_style(
			'perflocale-admin',
			PERFLOCALE_URL . 'assets/css/admin-core.css',
			[],
			PERFLOCALE_VERSION
		);

		// Each screen loads its own rule chunk on top of the shared core: a rule whose classes only one Pages/*.php emits
		// lives in that page's chunk, everything else in admin-core.css. Jobs uses shared rules only.
		$page_css = [
			'toplevel_page_perflocale'                 => 'dashboard',
			'perflocale_page_perflocale-languages'     => 'languages',
			'perflocale_page_perflocale-settings'      => 'settings',
			'perflocale_page_perflocale-addons'        => 'addons',
			'perflocale_page_perflocale-strings'       => 'strings',
			'perflocale_page_perflocale-translations'  => 'translations',
		];

		if ( isset( $page_css[ $hook ] ) ) {
			$chunk = $page_css[ $hook ];

			wp_enqueue_style(
				'perflocale-admin-' . $chunk,
				PERFLOCALE_URL . 'assets/css/admin-' . $chunk . '.css',
				[ 'perflocale-admin' ],
				PERFLOCALE_VERSION
			);
		}

		// Addons screen: the tab strip scrolls sideways when narrow and this draws its scroll indicator (iOS and Android
		// show scrollbars only as overlays and iOS ignores ::-webkit-scrollbar).
		if ( 'perflocale_page_perflocale-addons' === $hook ) {
			wp_enqueue_script(
				'perflocale-addons-tabs-scroll',
				PERFLOCALE_URL . 'assets/js/addons-tabs-scroll.js',
				[],
				PERFLOCALE_VERSION,
				true
			);
		}

		// A carrier handle with no file: holds the `perflocaleAdmin` payload and is a dependency of nearby admin scripts.
		wp_register_script( 'perflocale-admin', false, [], PERFLOCALE_VERSION, true );
		wp_enqueue_script( 'perflocale-admin' );

		// Delegated handlers for data-perflocale-{confirm,copy,submit-busy} and <details data-perflocale-popover>, so PHP
		// templates need no inline onclick.
		wp_enqueue_script(
			'perflocale-admin-actions',
			PERFLOCALE_URL . 'assets/js/admin-actions.js',
			[ 'perflocale-admin' ],
			PERFLOCALE_VERSION,
			true
		);

		// `nonce` (action `perflocale_nonce`) is admin-ajax's `_ajax_nonce`; `restNonce` (`wp_rest`) is the REST cookie
		// auth's `X-WP-Nonce`.
		wp_localize_script(
			'perflocale-admin',
			'perflocaleAdmin',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'restUrl'   => rest_url( 'perflocale/v1/' ),
				'nonce'     => wp_create_nonce( 'perflocale_nonce' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => [
					'saved'               => __( 'Settings saved.', 'perflocale' ),
					'error'               => __( 'An error occurred.', 'perflocale' ),
					'confirmDelete'       => __( 'Are you sure you want to delete this?', 'perflocale' ),
					'translating'         => __( 'Translating...', 'perflocale' ),
					'translated'          => __( 'Translation complete.', 'perflocale' ),
					'savingOrder'         => __( 'Saving order…', 'perflocale' ),
					'orderSaved'          => __( 'Order saved.', 'perflocale' ),
					'reorderFailed'       => __( 'Reorder failed.', 'perflocale' ),
					/* translators: %1$s: HTTP status code */
					'reorderFailedStatus' => __( 'Reorder failed (%1$s).', 'perflocale' ),
				],
			]
		);

		// The bulk-action bar exists only on the Translations screen.
		$bulk_screens = [
			'perflocale_page_perflocale-translations',
		];

		if ( in_array( $hook, $bulk_screens, true ) ) {
			wp_enqueue_script(
				'perflocale-bulk-actions',
				PERFLOCALE_URL . 'assets/js/bulk-actions.js',
				[ 'perflocale-admin' ],
				PERFLOCALE_VERSION,
				true
			);

			wp_localize_script(
				'perflocale-bulk-actions',
				'perflocaleBulk',
				[
					'pickAction'    => __( 'Pick a bulk action first.', 'perflocale' ),
					'pickRows'      => __( 'Pick at least one item first.', 'perflocale' ),
					'confirmDelete' => __( 'Delete the selected items? This cannot be undone.', 'perflocale' ),
				]
			);
		}

		// Translations screen estimates (bulk confirm, the whole-site panel), gated like the Strings bulk toolbar so no JS
		// ships with machine translation off.
		if ( $hook === 'perflocale_page_perflocale-translations' ) {
			$plugin   = \PerfLocale\Plugin::get_instance();
			$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;

			if ( $settings && $settings->mt_enabled() && current_user_can( 'perflocale_manage_translations' ) ) {
				// wp-hooks: the script fires perflocale.translations.bulkSubmitted and perflocale.translations.bulkFinished.
				wp_enqueue_script(
					'perflocale-translations-bulk-mt',
					PERFLOCALE_URL . 'assets/js/translations-bulk-mt.js',
					[ 'wp-hooks' ],
					PERFLOCALE_VERSION,
					true
				);

				$lang_repo  = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
				$mt_targets = [];

				foreach ( $lang_repo->get_active() as $lang ) {
					if ( ! empty( $lang->is_default ) ) {
						continue;
					}
					$mt_targets[] = self::mt_target( $lang );
				}

				wp_localize_script(
					'perflocale-translations-bulk-mt',
					'perflocaleTrMt',
					[
						'estimateUrl' => rest_url( 'perflocale/v1/machine-translate/estimate' ),
						'bulkUrl'     => rest_url( 'perflocale/v1/translations/bulk-translate' ),
						'nonce'       => wp_create_nonce( 'wp_rest' ),
						'targets'     => $mt_targets,
						'bulkFinished' => self::translations_bulk_finished(),
						// SCRIPT_DEBUG: a hook callback that throws is reported once per hook in the console.
						'debug'       => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
						'i18n'        => [
							/* translators: 1: item count, 2: character count, 3: skipped count */
							'confirmEstimate'    => __( 'Machine-translate %1$s items (~%2$s characters; %3$s already translated will be skipped)?', 'perflocale' ),
							'confirmPlain'       => __( 'Machine-translate the selected items? Existing translations are skipped.', 'perflocale' ),
							/* translators: 1: estimated characters, 2: remaining monthly characters */
							'overBudget'         => __( 'Needs ~%1$s characters but only %2$s remain in the monthly limit. Raise it under Settings → Addons → Machine Translation.', 'perflocale' ),
							/* translators: 1: item count, 2: character count, 3: skipped count */
							'estimateResult'     => __( '%1$s items, ~%2$s characters (%3$s already translated will be skipped).', 'perflocale' ),
							'estimating'         => __( 'Estimating…', 'perflocale' ),
							/* translators: %s: number of posts checked so far. */
							'estimatingProgress' => __( 'Estimating… %s posts checked.', 'perflocale' ),
							'pickSelection'      => __( 'Pick at least one language and one post type.', 'perflocale' ),
							'genericError'       => __( 'Could not fetch the estimate.', 'perflocale' ),
						],
					]
				);
			}
		}

		// The dropzone is used on the Strings (PO import) and Settings (data import) screens only.
		$dropzone_screens = [
			'perflocale_page_perflocale-strings',
			'perflocale_page_perflocale-settings',
		];

		if ( in_array( $hook, $dropzone_screens, true ) ) {
			wp_enqueue_style(
				'perflocale-dropzone',
				PERFLOCALE_URL . 'assets/css/dropzone.css',
				[],
				PERFLOCALE_VERSION
			);
			wp_enqueue_script(
				'perflocale-dropzone',
				PERFLOCALE_URL . 'assets/js/dropzone.js',
				[],
				PERFLOCALE_VERSION,
				true
			);
		}

		// Settings screen enhancements (switcher rows by display mode) and the import form's upload in pieces, in one file
		// so a per-script cost other plugins add to each script tag is paid once.
		if ( $hook === 'perflocale_page_perflocale-settings' ) {
			wp_enqueue_script(
				'perflocale-admin-settings',
				PERFLOCALE_URL . 'assets/js/admin-settings.js',
				[ 'perflocale-admin' ],
				PERFLOCALE_VERSION,
				true
			);
		}

		// Placeholder validator, the Edit Translation modal and the per-row check, on the Strings screen only (StringsPage
		// localizes the modal's strings onto this handle).
		if ( $hook === 'perflocale_page_perflocale-strings' ) {
			wp_enqueue_script(
				'perflocale-strings-validate',
				PERFLOCALE_URL . 'assets/js/strings-validate.js',
				[ 'perflocale-admin' ],
				PERFLOCALE_VERSION,
				true
			);

			// Bulk string translation: only with machine translation on and its Strings bulk switch on.
			$plugin   = \PerfLocale\Plugin::get_instance();
			$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;

			if ( $settings && $settings->mt_enabled() && $settings->mt_bulk_strings_enabled() && current_user_can( 'perflocale_use_mt' ) ) {
				// wp-hooks: the script fires perflocale.strings.bulkQueued and perflocale.strings.bulkFinished.
				wp_enqueue_script(
					'perflocale-strings-bulk-mt',
					PERFLOCALE_URL . 'assets/js/strings-bulk-mt.js',
					[ 'perflocale-admin', 'wp-hooks' ],
					PERFLOCALE_VERSION,
					true
				);

				$lang_repo  = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
				$active     = $lang_repo->get_active();
				$mt_targets = [];

				foreach ( $active as $lang ) {
					if ( ! empty( $lang->is_default ) ) {
						continue; // The source language is never a target.
					}

					$mt_targets[] = self::mt_target( $lang );
				}

				wp_localize_script(
					'perflocale-strings-bulk-mt',
					'perflocaleStrMt',
					[
						'restUrl'  => rest_url( 'perflocale/v1/strings/machine-translate' ),
						'jobsUrl'  => admin_url( 'admin.php?page=perflocale-jobs' ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'targets'  => $mt_targets,
						'provider' => (string) $settings->get_mt_provider(),
						// Most strings one dispatch translates; the confirm dialogs cut the selection there, as the job does.
						'runCap'   => \PerfLocale\Background\Jobs\BulkStringCap::max_per_dispatch(),
						// SCRIPT_DEBUG: a hook callback that throws is reported once per hook in the console.
						'debug'    => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
						'i18n'     => [
							'pickTargets'     => __( 'Pick at least one target language.', 'perflocale' ),
							'pickStrings'     => __( 'Pick at least one string row first.', 'perflocale' ),
							/* translators: 1: number of strings, 2: comma-separated language names */
							'confirmSelected' => __( 'MT-translate %1$d selected strings into %2$s? Existing translations are preserved unless Overwrite is on.', 'perflocale' ),
							/* translators: 1: number of filtered strings, 2: comma-separated language names */
							'confirmFiltered' => __( 'MT-translate %1$d filtered strings into %2$s? This may take a while.', 'perflocale' ),
							/* translators: 1: total string count, 2: comma-separated language names */
							'confirmAll'      => __( 'MT-translate every string in the table (%1$d total) into %2$s? This will dispatch a background job and may incur provider costs.', 'perflocale' ),
							/* translators: 1: number of strings one run translates, 2: number of filtered strings, 3: comma-separated language names */
							'filteredCapped'  => __( 'MT-translate %1$d of the %2$d filtered strings into %3$s? One run translates at most %1$d strings; run it again to continue. This may take a while.', 'perflocale' ),
							/* translators: 1: number of strings one run translates, 2: total string count, 3: comma-separated language names */
							'allCapped'       => __( 'MT-translate %1$d of the %2$d strings in the table into %3$s? One run translates at most %1$d strings; run it again to continue. This will dispatch a background job and may incur provider costs.', 'perflocale' ),
							'maxExceeded'     => sprintf(
								/* translators: %s: the most strings one run translates, for example 5,000. */
								__( 'Selection exceeds the per-dispatch cap (%s strings per run). Narrow the filter or split the run.', 'perflocale' ),
								number_format_i18n( \PerfLocale\Background\Jobs\BulkStringCap::max_per_dispatch() )
							),
							'queued'          => __( 'Bulk MT job queued. Redirecting to Jobs page…', 'perflocale' ),
							/* translators: 1: translated count, 2: skipped count, 3: failed count */
							'syncDone'        => __( 'Done. %1$d translated, %2$d skipped, %3$d failed.', 'perflocale' ),
							'syncDoneReload'  => __( 'Reload to see the new translations in the table.', 'perflocale' ),
							'firstError'      => __( 'First error:', 'perflocale' ),
							/* translators: %1$d: number of strings the run did not reach because of the per-run cap. */
							'cappedLeft'      => __( 'Still missing a translation: %1$d. Run it again to continue.', 'perflocale' ),
							'dirtyStay'       => __( 'Job started - your unsaved edits were kept; save them, then check the Jobs page.', 'perflocale' ),
							'genericError'    => __( 'Something went wrong while dispatching the MT job.', 'perflocale' ),
							'dispatching'     => __( 'Dispatching…', 'perflocale' ),
						],
					]
				);
			}
		}

		// The language preset combobox (250-language data) on the Languages add/edit form only.
		if ( $hook === 'perflocale_page_perflocale-languages' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

			if ( $action === 'add' || $action === 'edit' ) {
				wp_enqueue_style(
					'perflocale-language-preset-combo',
					PERFLOCALE_URL . 'assets/css/language-preset-combo.css',
					[],
					PERFLOCALE_VERSION
				);
				wp_enqueue_script(
					'perflocale-language-preset-combo',
					PERFLOCALE_URL . 'assets/js/language-preset-combo.js',
					[],
					PERFLOCALE_VERSION,
					true
				);
				wp_localize_script(
					'perflocale-language-preset-combo',
					'perflocaleCombo',
					[
						'i18n' => [
							'noMatches' => __( 'No languages match - try a different search.', 'perflocale' ),
						],
					]
				);
			}

			// Drag-and-drop reorder on the list view only (no `action`); its CSS is in the shared admin CSS.
			if ( $action === '' ) {
				wp_enqueue_script(
					'perflocale-languages-reorder',
					PERFLOCALE_URL . 'assets/js/languages-reorder.js',
					[ 'perflocale-admin' ],
					PERFLOCALE_VERSION,
					true
				);
			}
		}
	}

	/**
	 * Enqueue the JS Abilities shim with the registered abilities and their schemas; it feature-detects the JS
	 * Abilities API, so without it the cost is one small script.
	 *
	 * @return void
	 */
	private function maybe_enqueue_abilities_shim(): void {
		// No PHP abilities were registered without `wp_register_ability`: no shim.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$abilities = $this->collect_registered_abilities();

		if ( $abilities === [] ) {
			return;
		}

		$payload = [ 'abilities' => $abilities ];

		/**
		 * Filter the payload of the JS Abilities shim, `[ 'abilities' => [ { name, label, ... } ] ]`, for example to keep
		 * `translate-post` server-side only; an empty `abilities` list skips JS registration.
		 *
		 * @hook perflocale/abilities/js_payload
		 *
		 * @param array $payload  Default payload (matches `perflocaleAbilities` JS global).
		 */
		$payload = (array) apply_filters( 'perflocale/abilities/js_payload', $payload );

		if ( empty( $payload['abilities'] ) ) {
			return;
		}

		wp_enqueue_script(
			'perflocale-abilities-shim',
			PERFLOCALE_URL . 'assets/js/abilities-shim.js',
			[ 'wp-api-fetch' ],
			PERFLOCALE_VERSION,
			true
		);

		wp_localize_script(
			'perflocale-abilities-shim',
			'perflocaleAbilities',
			$payload
		);
	}

	/**
	 * Every `perflocale/*` ability in the WP Abilities registry, shaped for the JS shim.
	 *
	 * @return array<int, array{name:string,label:string,description:string,inputSchema:?array,outputSchema:?array,readonly:bool,destructive:bool,idempotent:bool}>
	 */
	private function collect_registered_abilities(): array {
		// Called by name: the Abilities API (WP 6.9+) is optional; function_exists() guards it.
		$get_abilities = 'wp_get_abilities';

		if ( ! function_exists( $get_abilities ) ) {
			return [];
		}

		$registered = $get_abilities();

		if ( ! is_array( $registered ) ) {
			return [];
		}

		$out = [];

		foreach ( $registered as $name => $ability ) {
			$name = is_string( $name ) ? $name : ( is_object( $ability ) && isset( $ability->name ) ? (string) $ability->name : '' );

			if ( $name === '' || strpos( $name, 'perflocale/' ) !== 0 ) {
				continue;
			}

			$get = static function ( $obj, string $key, $fallback = null ) {
				if ( is_array( $obj ) ) {
					return $obj[ $key ] ?? $fallback;
				}

				if ( is_object( $obj ) ) {
					if ( isset( $obj->$key ) ) {
						return $obj->$key;
					}

					$method = 'get_' . $key;

					if ( method_exists( $obj, $method ) ) {
						return $obj->$method();
					}
				}

				return $fallback;
			};

			$meta        = $get( $ability, 'meta', [] );
			$annotations = is_array( $meta ) && isset( $meta['annotations'] ) && is_array( $meta['annotations'] )
				? $meta['annotations']
				: [];

			$out[] = [
				'name'         => $name,
				'label'        => (string) $get( $ability, 'label', $name ),
				'description'  => (string) $get( $ability, 'description', '' ),
				'inputSchema'  => $get( $ability, 'input_schema', null ),
				'outputSchema' => $get( $ability, 'output_schema', null ),
				// The annotations choose the shim's REST method as core's run controller does: readonly GET, destructive and
				// idempotent DELETE, else POST.
				'readonly'     => ! empty( $annotations['readonly'] ),
				'destructive'  => ! empty( $annotations['destructive'] ),
				'idempotent'   => ! empty( $annotations['idempotent'] ),
			];
		}

		return $out;
	}

	/**
	 * Whether this is a PerfLocale admin screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private function is_perflocale_page( string $hook ): bool {
		$perflocale_hooks = [
			'toplevel_page_perflocale',
			'perflocale_page_perflocale-languages',
			'perflocale_page_perflocale-translations',
			'perflocale_page_perflocale-strings',
			'perflocale_page_perflocale-addons',
			'perflocale_page_perflocale-settings',
			'perflocale_page_perflocale-jobs',
		];

		return in_array( $hook, $perflocale_hooks, true );
	}
}
