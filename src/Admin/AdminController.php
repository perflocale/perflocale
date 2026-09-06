<?php
/**
 * Admin controller - registers menus and pages.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the PerfLocale admin menu and delegates page rendering
 * to specific page controller classes.
 */
final class AdminController {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Admin page hooks (used for conditional script loading).
	 *
	 * add_menu_page / add_submenu_page return string|false — the value can
	 * be `false` when the current user lacks the menu capability. We store
	 * both shapes here and gate consumers (load-XXX action, screen-id
	 * comparisons) with is_string() before use.
	 *
	 * @var array<string, string|false>
	 */
	private array $page_hooks = [];

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_menu', [ $this, 'register_screen_options' ], 99 );
		// Network-admin Jobs overview on multisite: shows active job
		// counts + recurring-task last-run timestamps across every
		// subsite in a single table, with click-through to per-site
		// Jobs page. Only registered on multisite installs.
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'register_network_menu' ] );
		}

		// Note: The AJAX handler for column visibility (perflocale_save_hidden_columns)
		// is registered in Bootstrap.php since AdminController is not loaded during AJAX.

		// Screen option save filters are in perflocale.php (earliest possible).

		// Process forms early, before output.
		add_action( 'admin_init', [ $this, 'process_settings_form' ] );
		add_action( 'admin_init', [ $this, 'process_export_import' ] );
		add_action( 'admin_init', [ $this, 'process_export_download' ] );
		add_action( 'admin_init', [ $this, 'process_language_forms' ] );
		add_action( 'admin_init', [ $this, 'process_string_scan' ] );
		add_action( 'admin_init', [ $this, 'process_string_translations' ] );
		add_action( 'admin_init', [ $this, 'process_strings_po_forms' ] );
		add_action( 'admin_init', [ $this, 'process_translations_bulk' ] );
		add_action( 'admin_init', [ $this, 'process_dashboard_actions' ] );

		// A POST body PHP discarded for exceeding post_max_size reaches every
		// handler above as an empty $_POST, so none of them can report it —
		// their route guards and nonce checks are the first things it breaks.
		// This notice is the only surface left that can say what happened.
		add_action( 'admin_notices', [ $this, 'render_discarded_post_notice' ] );


		// Handle admin-post.php actions.
		add_action( 'admin_post_perflocale_create_translation', [ $this, 'handle_create_translation' ] );
		add_action( 'admin_post_perflocale_create_term_translation', [ $this, 'handle_create_term_translation' ] );
		add_action( 'admin_post_perflocale_reset_breaker', [ $this, 'handle_reset_breaker_action' ] );
		add_action( 'admin_post_perflocale_save_addon_settings', [ $this, 'handle_save_addon_settings' ] );
		add_action( 'admin_post_perflocale_toggle_addon', [ $this, 'handle_toggle_addon' ] );

		// Attach page-specific inline CSS/JS through WordPress' standard
		// enqueue pipeline (wp_add_inline_style / wp_add_inline_script on
		// the already-enqueued `perflocale-admin` handle). Keeping the
		// hookup here means page classes never echo raw markup from
		// render() - the WordPress.org plugin reviewers require all
		// stylesheet and script output to go through Core's enqueue API.
		add_action( 'admin_enqueue_scripts', [ Pages\SettingsPage::class, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ Pages\JobsPage::class, 'enqueue_assets' ] );

		// Plugins-list action links (only fires on wp-admin/plugins.php).
		add_filter(
			'plugin_action_links_' . plugin_basename( PERFLOCALE_FILE ),
			[ $this, 'filter_plugin_action_links' ]
		);

		// Right-column meta links on wp-admin/plugins.php.
		add_filter( 'plugin_row_meta', [ $this, 'filter_plugin_row_meta' ], 10, 2 );
	}

	/**
	 * Add Settings + Languages links to the plugins-list row (left column,
	 * next to "Deactivate"). Cost: one filter call on wp-admin/plugins.php
	 * only; no effect on any other admin page or on the frontend.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string>
	 */
	public function filter_plugin_action_links( array $links ): array {
		$prefixed = [];

		if ( current_user_can( 'manage_options' ) ) {
			$prefixed['settings'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=perflocale-settings' ) ),
				esc_html__( 'Settings', 'perflocale' )
			);
		}

		if ( current_user_can( 'perflocale_manage_languages' ) || current_user_can( 'manage_options' ) ) {
			$prefixed['languages'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ),
				esc_html__( 'Languages', 'perflocale' )
			);
		}

		// Prepend our links so they appear before Deactivate.
		return array_merge( $prefixed, $links );
	}

	/**
	 * Add Documentation + Support links to the plugins-list row (right column).
	 *
	 * @param array<int, string> $links Existing row meta links.
	 * @param string             $file Plugin file being filtered.
	 * @return array<int, string>
	 */
	public function filter_plugin_row_meta( array $links, string $file ): array {
		if ( $file !== plugin_basename( PERFLOCALE_FILE ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://perflocale.com/docs/' ),
			esc_html__( 'Documentation', 'perflocale' )
		);

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://wordpress.org/support/plugin/perflocale/' ),
			esc_html__( 'Support', 'perflocale' )
		);

		return $links;
	}

	/**
	 * Process settings form submission early, before output.
	 *
	 * @return void
	 */
	public function process_settings_form(): void {
		// Route guard - this is not a security check, it's "is this our page?".
		// Capability and nonce checks happen below before any side effect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'perflocale-settings' ) {
			return;
		}

		// Handle regenerate translation files action (GET link with nonce).
		// Canonical WP order: nonce first (CSRF), then capability.
		// `check_admin_referer()` itself dies on failure, so we explicitly
		// re-check the cap immediately after to keep the gate symmetric.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_regenerate'] ) ) {
			check_admin_referer( 'perflocale_regenerate_translations' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$plugin    = \PerfLocale\Plugin::get_instance();
			$cache     = $plugin->get( 'cache' );
			$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
			// generate_all() self-heals first, but we still want the repair
			// counts surfaced in the redirect notice so the user sees that
			// stranded translations were reconnected during the run.
			$repaired_before = $generator->count_repairable_orphans();
			$count           = $generator->generate_all();
			// Anything that started orphaned and is no longer orphaned was
			// repaired by generate_all(). count_repairable_orphans() after
			// the run should be ≤ before.
			$repaired_after = $generator->count_repairable_orphans();
			$repaired       = max( 0, $repaired_before - $repaired_after );

			wp_safe_redirect(
				add_query_arg(
					[
						'page'            => 'perflocale-settings',
						'tab'             => 'performance',
						'files_generated' => $count,
						'repaired'        => $repaired,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Settings POST save path - same nonce-first-then-capability ordering.
		if ( empty( $_POST['perflocale_settings_tab'] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		$tab    = sanitize_key( wp_unslash( $_POST['perflocale_settings_tab'] ) );
		$page   = new Pages\SettingsPage( $this->settings );
		$values = $page->extract_tab_values( $tab );

		// The boolean matters. wpdb refuses a write whose value is too long for
		// its column or carries invalid UTF-8 — it does not truncate or repair —
		// so update() can fail with nothing stored while the redirect below
		// reports success. Carry the real result through to the notice.
		// update() returns false for a refused write AND for a save that changed
		// nothing, so the no-op signal is consulted alongside it. Without that,
		// re-saving a tab unchanged — the common case — would show an error.
		$settings_saved = $this->settings->update( $values ) || \PerfLocale\Settings::last_update_was_noop();

		// When performance tab is saved, handle string translation mode changes.
		if ( $tab === 'performance' ) {
			$plugin = \PerfLocale\Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );

			if ( ( $values['string_translation_mode'] ?? '' ) === 'files' ) {
				$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
				$generator->generate_all();
			} else {
				// Clean up files when switching to database mode.
				$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
				$generator->clean_all();
			}
		}

		// Addon subtab keys redirect back to the Addons parent tab.
		// Check both built-in subtabs and any external ones registered via filter.
		$addon_subtabs = array_keys(
			(array) apply_filters(
				'perflocale/settings/addon_subtabs',
				[
					'machine-translation' => '',
					'woocommerce'         => '',
				]
			)
		);

		$redirect_args = [
			'page'             => 'perflocale-settings',
			'settings-updated' => $settings_saved ? 'true' : 'false',
		];

		if ( in_array( $tab, $addon_subtabs, true ) ) {
			$redirect_args['tab']    = 'addons';
			$redirect_args['subtab'] = $tab;
		} else {
			$redirect_args['tab'] = $tab;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Upload limits
	//
	// Both import forms on this plugin's screens gate uploads in application
	// code at 50 MB — 25x a stock host's `upload_max_filesize`. On a stock
	// host PHP therefore refuses the file first and the application gate is
	// never what the operator hits; only a generously configured host reaches
	// it. The helpers below turn the two ways PHP can refuse an upload into
	// something the operator can act on.
	// ------------------------------------------------------------------

	/**
	 * Ceiling the PO importer applies on top of PHP's own upload limits.
	 *
	 * Deliberately kept as a SECOND ceiling rather than lowered to
	 * `wp_max_upload_size()`. PHP refuses anything above its own limit before
	 * this code runs, so the only uploads this gate ever sees are ones a
	 * generous host already accepted — which is exactly the case it exists
	 * for, because core's \PO parser holds every entry of the file in memory
	 * at once. Lowering it to `wp_max_upload_size()` would make it dead code
	 * on a stock host and weaker on the hosts that need it, and would tie an
	 * out-of-memory guard to a number any other plugin can move through the
	 * `upload_size_limit` filter.
	 *
	 * Exposed so StringsPage can name the same number the gate used.
	 *
	 * @return int Maximum accepted PO upload size, in bytes.
	 */
	public static function po_max_bytes(): int {
		/**
		 * Filters the largest PO file the importer will accept, in bytes.
		 *
		 * @hook perflocale/po/max_bytes
		 */
		return (int) apply_filters( 'perflocale/po/max_bytes', 50 * MB_IN_BYTES );
	}

	/**
	 * Ceiling the JSON data importer applies on top of PHP's own upload limits.
	 *
	 * The twin of po_max_bytes(), and kept a second ceiling for the same
	 * reason: the staged file is read back and decoded whole. Applying the
	 * filter in one place is what keeps the gate and the number shown on the
	 * import form from drifting apart.
	 *
	 * @return int Maximum accepted import size, in bytes.
	 */
	public static function import_max_bytes(): int {
		/**
		 * Filters the largest data-export JSON the importer will accept, in bytes.
		 *
		 * @hook perflocale/import/max_file_bytes
		 */
		return (int) apply_filters( 'perflocale/import/max_file_bytes', 50 * MB_IN_BYTES );
	}

	/**
	 * `size_format()` for a byte count that has to render no matter what.
	 *
	 * Core's size_format() returns false for a negative input — which only a
	 * third-party `upload_size_limit` filter could produce — and a message
	 * reading "limit: " helps nobody. Clamping at zero keeps the sentence
	 * whole.
	 *
	 * @param mixed $bytes Byte count, or anything the `upload_size_limit` filter returned
	 *                      (float, numeric string, or an ini string like '64M').
	 * @return string Formatted size, e.g. "2 MB".
	 */
	public static function format_bytes( mixed $bytes ): string {
		// Deliberately NOT typed `int`. Every caller feeds this
		// `wp_max_upload_size()`, whose return passes through the third-party
		// `upload_size_limit` filter — and a plugin may return a float, a
		// numeric string, or an ini-shaped string like '64M'. This file declares
		// strict_types, so a narrow parameter would throw at ARGUMENT BINDING,
		// before any guard in the body could run: a white screen on the Strings
		// admin page on every load, caused by another plugin's filter. WordPress
		// core tolerates exactly this — `size_format( wp_max_upload_size() )` in
		// wp-includes/media.php is untyped and survives. Normalise here instead,
		// and route the ini-shaped case through wp_convert_hr_to_bytes() so a
		// '64M' answer renders as 64 MB rather than 64 bytes.
		$normalised = is_numeric( $bytes )
			? (int) $bytes
			: (int) wp_convert_hr_to_bytes( (string) $bytes );

		return (string) size_format( max( 0, $normalised ) );
	}

	/**
	 * The upload ceiling in bytes, normalised the same way {@see format_bytes()} does.
	 *
	 * `min()` on a raw `wp_max_upload_size()` has the same exposure as the
	 * signature above did — a filter returning '64M' makes `min()` compare a
	 * string against an int and pick the wrong one, silently. Callers that need
	 * the NUMBER rather than the label go through here.
	 *
	 * @return int Bytes, never negative.
	 */
	public static function max_upload_bytes(): int {
		$limit = wp_max_upload_size();

		$normalised = is_numeric( $limit )
			? (int) $limit
			: (int) wp_convert_hr_to_bytes( (string) $limit );

		return max( 0, $normalised );
	}

	/**
	 * Human-readable reason for a PHP upload error code.
	 *
	 * `$_FILES[…]['error']` is the only record of WHY an upload arrived with
	 * an empty `tmp_name`. Ignore it and a file the server refused looks
	 * exactly like no file at all — and the two need opposite responses, so
	 * each code gets its own sentence. An over-limit file is the operator's
	 * to shrink or the host's limit to raise; a missing or unwritable
	 * temporary directory is a host fault that no smaller file will fix.
	 *
	 * Shared by both import forms so the wording cannot drift apart.
	 *
	 * @param int $code One of PHP's UPLOAD_ERR_* constants.
	 * @return string Translated sentence; empty string when the upload succeeded.
	 */
	public static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_OK:
				return '';

			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: largest single upload this server accepts, e.g. "2 MB". */
					__( 'The file is larger than this server accepts (limit: %s). Upload a smaller file, or ask your host to raise the PHP upload_max_filesize and post_max_size settings.', 'perflocale' ),
					self::format_bytes( self::max_upload_bytes() )
				);

			case UPLOAD_ERR_PARTIAL:
				return __( 'Only part of the file reached the server, so nothing was imported. The upload was interrupted — try again.', 'perflocale' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was received. Choose a file and submit the form again.', 'perflocale' );

			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'This server has no temporary folder for uploads, so no file can be uploaded to it at all. Ask your host to configure the PHP upload_tmp_dir setting — a smaller file will not help.', 'perflocale' );

			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded file to disk. Check the free disk space and the permissions on the PHP temporary folder — a smaller file will not help.', 'perflocale' );

			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension on this server stopped the upload. Ask your host which extension blocked it.', 'perflocale' );

			default:
				return sprintf(
					/* translators: %d: numeric PHP upload error code. */
					__( 'The upload failed for an unrecognized reason (PHP upload error %d). Try again, and check the server error log if it keeps failing.', 'perflocale' ),
					$code
				);
		}
	}

	/**
	 * Whether PHP threw this request's POST body away for exceeding post_max_size.
	 *
	 * Above that limit PHP discards the whole body before any script runs:
	 * `$_POST`, `$_FILES` and therefore `_wpnonce` all arrive empty. Every
	 * handler in this class then bails at its own route guard without a word;
	 * a handler ordered nonce-first instead — as several of WordPress core's
	 * admin forms are — reports a security failure for a request nobody
	 * forged. The signature is a POST that declared more bytes than
	 * post_max_size allows and arrived with both superglobals empty.
	 *
	 * `post_max_size = 0` disables the limit and PHP then never discards, so
	 * an empty `$_POST` there is a genuinely empty body, not a truncated one.
	 * A SAPI that does not publish CONTENT_LENGTH falls back to false — no
	 * notice, i.e. the behaviour that shipped before this — rather than to a
	 * false alarm.
	 *
	 * @return bool True when the body was discarded.
	 */
	public static function post_body_was_discarded(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'POST' !== $method ) {
			return false;
		}

		// Emptiness only — no value is read out of either superglobal. A body
		// PHP parsed leaves at least one of them populated.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- No nonce can exist: this is the branch where the body carrying it was discarded.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return false;
		}

		$declared = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$limit    = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		return $declared > 0 && $limit > 0 && $declared > $limit;
	}

	/**
	 * Tell the operator when PHP discarded their upload before WordPress saw it.
	 *
	 * Renders on this plugin's own admin screens only, and only for the
	 * signature above. It exists because nothing else can report this: the
	 * body is gone, so no form handler runs.
	 *
	 * SECURITY. This is the one branch in the plugin that runs where a nonce
	 * cannot be verified — the nonce was inside the discarded body — so it is
	 * inert by construction. It reads no value out of `$_POST` or `$_FILES`
	 * (only whether they are empty), echoes nothing from the request, writes
	 * no option, transient, post or row, and performs no action. The whole of
	 * what an attacker gains by forging the signature (an over-long POST to
	 * one of these screens, in their own browser) is a fixed sentence that
	 * already ships in this plugin's translation files. The screen gate reads
	 * `$_GET['page']`, which survives the discard because it travels in the
	 * URL rather than the body, and is compared, never printed.
	 *
	 * Cost: one `REQUEST_METHOD` comparison per admin page load, which is
	 * where it returns on every GET. Nothing on the front end.
	 *
	 * @return void
	 */
	public function render_discarded_post_notice(): void {
		if ( ! self::post_body_was_discarded() ) {
			return;
		}

		// Our screens only.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display gate: the value is compared, never printed, and nothing mutates.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		if ( ! str_starts_with( $page, 'perflocale' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'PerfLocale:', 'perflocale' ),
			esc_html(
				sprintf(
					/* translators: 1: largest single file this server accepts, e.g. "2 MB". 2: largest whole submission this server accepts, e.g. "8 MB". */
					__( 'That submission was too large for this server, so PHP discarded it before WordPress saw it — nothing was uploaded, imported or changed. This server accepts at most %1$s per file and %2$s per submission. Use a smaller file, or ask your host to raise the PHP upload_max_filesize and post_max_size settings.', 'perflocale' ),
					self::format_bytes( self::max_upload_bytes() ),
					self::format_bytes( wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) )
				)
			)
		);
	}

	/**
	 * Handle export, import, and migration actions.
	 *
	 * @return void
	 */
	public function process_export_import(): void {
		// Route guard - is this our action submission? Cap and nonce checks
		// happen per-action below in canonical nonce-first-then-cap order.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['perflocale_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action       = sanitize_key( wp_unslash( $_POST['perflocale_action'] ) );
		$redirect_url = admin_url( 'admin.php?page=perflocale-settings&tab=export-import' );

		// ---- Export ----
		if ( $action === 'export' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_export_data' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$sections = isset( $_POST['export_sections'] )
				? array_map( 'sanitize_key', wp_unslash( (array) $_POST['export_sections'] ) )
				: [];

			// Decide sync vs async manually here rather than via
			// Dispatcher::dispatch — the sync path needs to STREAM the
			// export to the browser (admin clicked "Export", expects a
			// download), and Dispatcher's inline path would write to disk
			// first, costing double I/O on small sites. Async path goes
			// through Dispatcher normally so JobState gets recorded.
			$plugin   = \PerfLocale\Plugin::get_instance();
			$settings = $plugin->get( 'settings' );
			$job      = new \PerfLocale\Background\Jobs\DataExportJob();
			$job_args = [
				'sections' => $sections,
				'path'     => '',
			];

			if ( $job->should_run_async( $job_args, $settings ) ) {
				$result = \PerfLocale\Background\Dispatcher::dispatch( $job, $job_args );

				if ( ( $result['mode'] ?? '' ) === 'async' ) {
					wp_safe_redirect(
						add_query_arg(
							'import_result',
							rawurlencode( __( 'Data export queued. The download link will appear under PerfLocale → Jobs when ready.', 'perflocale' ) ),
							admin_url( 'admin.php?page=perflocale-jobs' )
						)
					);
					exit;
				}

				// Dispatcher returned inline anyway (e.g. denied/never) —
				// fall through to the streaming path below.
			}

			$exporter = new \PerfLocale\Admin\DataExporter();
			$exporter->download( $sections ); // Dies after sending file.
		}

		// ---- Import ----
		if ( $action === 'import' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_import_data' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			// PHP records why an upload produced no tmp_name in the entry's
			// `error` slot and nowhere else. Read it BEFORE the emptiness
			// gate below: a file the server refused for being too big arrives
			// here as a populated $_FILES entry with an empty tmp_name, which
			// that gate would otherwise report as "No file uploaded".
			$upload_error = isset( $_FILES['perflocale_import_file']['error'] ) && is_scalar( $_FILES['perflocale_import_file']['error'] )
				? (int) $_FILES['perflocale_import_file']['error']
				: UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_OK !== $upload_error ) {
				wp_safe_redirect(
					add_query_arg(
						'import_error',
						rawurlencode( self::upload_error_message( $upload_error ) ),
						$redirect_url
					)
				);
				exit;
			}

			if ( empty( $_FILES['perflocale_import_file']['tmp_name'] ) ) {
				wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'No file uploaded.', 'perflocale' ) ), $redirect_url ) );
				exit;
			}

			// tmp_name is a server-controlled path - do NOT sanitize_text_field()
			// which can strip valid path characters. is_uploaded_file() is the
			// correct security check for uploaded file paths.
			$tmp_path = $_FILES['perflocale_import_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( ! is_uploaded_file( $tmp_path ) ) {
				wp_safe_redirect( add_query_arg( 'import_error', __( 'Invalid file upload.', 'perflocale' ), $redirect_url ) );
				exit;
			}

			// Fail-fast size check before the chunked copy stages a multi-GB
			// file (wasted I/O + disk-fill risk). $_FILES size is PHP's own
			// measurement, not client-forgeable. The ceiling comes from
			// import_max_bytes() so this gate and the number the import form
			// advertises can never disagree.
			$max_import_bytes = self::import_max_bytes();
			$upload_size      = isset( $_FILES['perflocale_import_file']['size'] )
				? (int) $_FILES['perflocale_import_file']['size']
				: 0;

			if ( $upload_size <= 0 || $upload_size > max( 1024 * 1024, $max_import_bytes ) ) {
				wp_safe_redirect(
					add_query_arg(
						'import_error',
						rawurlencode(
							sprintf(
							/* translators: %s: maximum allowed import file size, e.g. "50 MB" */
								__( 'Import file exceeds the maximum allowed size (%s).', 'perflocale' ),
								size_format( $max_import_bytes )
							)
						),
						$redirect_url
					)
				);
				exit;
			}

			// Move PHP's tmp upload into wp-content/uploads/perflocale/temp/
			// so the async worker can still see the file when WP-Cron /
			// Action Scheduler runs on a later request. Mirrors the
			// chunked-stream pattern used by the CSV importer
			// below — Plugin Check rejects move_uploaded_file/rename/copy.
			$temp_dir = \PerfLocale\Helper::uploads_temp_dir();

			if ( $temp_dir === '' || ! wp_mkdir_p( $temp_dir ) ) {
				wp_safe_redirect( add_query_arg( 'import_error', __( 'Could not create temporary upload directory.', 'perflocale' ), $redirect_url ) );
				exit;
			}

			\PerfLocale\Helper::harden_directory( $temp_dir );

			$token          = wp_generate_password( 16, false );
			$persisted_path = $temp_dir . '/import-' . $token . '.json';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a fresh PHP upload tmp file; WP_Filesystem cannot reach php_uploaded_file area; @ suppresses open-failure warning handled by the truthiness check below.
			$source = @fopen( $tmp_path, 'rb' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Writing into our wp-content/uploads/perflocale/temp/ scratch dir created via wp_mkdir_p above; @ suppresses open-failure warning handled below.
			$dest = @fopen( $persisted_path, 'wb' );

			if ( ! $source || ! $dest ) {
				if ( $source ) {
					fclose( $source ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle.
				if ( $dest ) {
					fclose( $dest ); }   // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle.
				wp_safe_redirect( add_query_arg( 'import_error', __( 'Could not stage uploaded file.', 'perflocale' ), $redirect_url ) );
				exit;
			}

			while ( ! feof( $source ) ) {
				$chunk = fread( $source, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Chunked copy of fresh PHP upload tmp file.
				if ( $chunk === false ) {
					break;
				}
				fwrite( $dest, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked write into wp-content/uploads scratch dir.
			}
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle.
			fclose( $dest );   // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle.
			wp_delete_file( $tmp_path );

			if ( ! file_exists( $persisted_path ) || filesize( $persisted_path ) === 0 ) {
				wp_safe_redirect( add_query_arg( 'import_error', __( 'Staged upload is empty.', 'perflocale' ), $redirect_url ) );
				exit;
			}

			// Safety-net cleanup: if async never runs (cron stalled) or
			// the worker crashes, the file is reaped by the same hook
			// the importer uses.
			\PerfLocale\Background\BackgroundEvents::enqueue(
				'perflocale_cleanup_temp_import',
				[ $persisted_path ],
				DAY_IN_SECONDS
			);

			$replace = ( sanitize_key( $_POST['perflocale_import_mode'] ?? 'merge' ) === 'replace' );

			// Route through Dispatcher. Sync below the threshold (admin
			// sees counts on the redirect); async above (admin redirects
			// to PerfLocale → Jobs to watch progress). DataImportJob
			// estimates row count from filesize.
			$result = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\DataImportJob(),
				[
					'file_path' => $persisted_path,
					'replace'   => $replace,
				]
			);

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect(
					add_query_arg(
						'import_result',
						rawurlencode( __( 'Data import queued. Track progress under PerfLocale → Jobs.', 'perflocale' ) ),
						admin_url( 'admin.php?page=perflocale-jobs' )
					)
				);
				exit;
			}

			$r = is_array( $result['result'] ?? null ) ? $result['result'] : [];

			// Sync path completed — delete now and drop the scheduled
			// safety-net cleanup so it doesn't fire against a stale path.
			if ( file_exists( $persisted_path ) ) {
				wp_delete_file( $persisted_path );
			}
			\PerfLocale\Background\BackgroundEvents::unschedule(
				'perflocale_cleanup_temp_import',
				[ $persisted_path ]
			);

			// Only `async` was handled above, so every other Dispatcher
			// outcome lands here — and three of them report the failure in
			// `error` while carrying no `result` at all: a sync run that
			// threw, `denied` (the user lacks the cap), and `error` (job-id
			// allocation and the like). With $r empty the `errors` branch
			// below cannot fire, so all three used to end at the success
			// notice: "Import complete. 0 items imported, 0 skipped." over a
			// transaction that was rolled back, or never started.
			//
			// Checked here rather than earlier so a failed import still
			// releases the uploaded file and its scheduled safety-net
			// cleanup, exactly as a successful one does.
			if ( ! empty( $result['error'] ) ) {
				wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( (string) $result['error'] ), $redirect_url ) );
				exit;
			}

			if ( ! empty( $r['errors'] ) ) {
				$msg = implode( '; ', array_slice( $r['errors'], 0, 3 ) );
				wp_safe_redirect( add_query_arg( 'import_error', $msg, $redirect_url ) );
			} else {
				/* translators: %1$d: imported count, %2$d: skipped count */
				$msg = sprintf( __( 'Import complete. %1$d items imported, %2$d skipped.', 'perflocale' ), (int) ( $r['imported'] ?? 0 ), (int) ( $r['skipped'] ?? 0 ) );
				wp_safe_redirect( add_query_arg( 'import_result', $msg, $redirect_url ) );
			}

			exit;
		}

		// ---- Migrate from WPML ----
		if ( $action === 'migrate_wpml' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_migrate_wpml' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			// Route through Dispatcher: small WPML installs run inline
			// (admin sees results immediately); large installs go async
			// to dodge PHP-FPM timeouts, redirect to *PerfLocale → Jobs*
			// where the operator watches progress.
			$result = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\WpmlMigrationJob(),
				[]
			);

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect(
					add_query_arg(
						'import_result',
						__( 'WPML migration queued. Track progress under PerfLocale → Jobs.', 'perflocale' ),
						admin_url( 'admin.php?page=perflocale-jobs' )
					)
				);
				exit;
			}

			$r   = $result['result'] ?? [];
			$msg = sprintf(
				/* translators: %1$d: number of posts imported, %2$d: number of terms imported, %3$d: number of strings imported */
				__( 'WPML import complete. %1$d posts, %2$d terms, %3$d strings imported.', 'perflocale' ),
				(int) ( $r['posts'] ?? 0 ),
				(int) ( $r['terms'] ?? 0 ),
				(int) ( $r['strings'] ?? 0 )
			);

			if ( ! empty( $r['errors'] ) ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: number of errors */
					__( '%d errors occurred.', 'perflocale' ),
					count( $r['errors'] )
				);
			}

			wp_safe_redirect( add_query_arg( 'import_result', $msg, $redirect_url ) );
			exit;
		}

		// ---- Migrate from Polylang ----
		if ( $action === 'migrate_polylang' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_migrate_polylang' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$result = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\PolylangMigrationJob(),
				[]
			);

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect(
					add_query_arg(
						'import_result',
						__( 'Polylang migration queued. Track progress under PerfLocale → Jobs.', 'perflocale' ),
						admin_url( 'admin.php?page=perflocale-jobs' )
					)
				);
				exit;
			}

			$r   = $result['result'] ?? [];
			$msg = sprintf(
				/* translators: %1$d: number of posts imported, %2$d: number of terms imported */
				__( 'Polylang import complete. %1$d posts, %2$d terms imported.', 'perflocale' ),
				(int) ( $r['posts'] ?? 0 ),
				(int) ( $r['terms'] ?? 0 )
			);

			if ( ! empty( $r['errors'] ) ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: number of errors */
					__( '%d errors occurred.', 'perflocale' ),
					count( $r['errors'] )
				);
			}

			wp_safe_redirect( add_query_arg( 'import_result', $msg, $redirect_url ) );
			exit;
		}

		// ---- Migrate from TranslatePress ----
		if ( $action === 'migrate_translatepress' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_migrate_translatepress' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$result = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\TranslatePressMigrationJob(),
				[]
			);

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect(
					add_query_arg(
						'import_result',
						__( 'TranslatePress migration queued. Track progress under PerfLocale → Jobs.', 'perflocale' ),
						admin_url( 'admin.php?page=perflocale-jobs' )
					)
				);
				exit;
			}

			$r   = $result['result'] ?? [];
			$msg = sprintf(
				/* translators: %1$d: number of posts imported, %2$d: number of strings imported, %3$d: number of slugs imported */
				__( 'TranslatePress import complete. %1$d posts, %2$d strings, %3$d slugs imported.', 'perflocale' ),
				(int) ( $r['posts'] ?? 0 ),
				(int) ( $r['strings'] ?? 0 ),
				(int) ( $r['slugs'] ?? 0 )
			);

			if ( ! empty( $r['errors'] ) ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: number of errors */
					__( '%d errors occurred.', 'perflocale' ),
					count( $r['errors'] )
				);
			}

			wp_safe_redirect( add_query_arg( 'import_result', $msg, $redirect_url ) );
			exit;
		}
	}

	/**
	 * Process language form submissions early, before any output.
	 *
	 * @return void
	 */
	public function process_language_forms(): void {
		// Route guard - is this our page? Cap and nonce checks happen
		// per-branch below in canonical nonce-first-then-cap order.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'perflocale-languages' ) {
			return;
		}

		// Handle set_default via GET.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'set_default' && isset( $_GET['language_id'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'perflocale_set_default_language' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$plugin  = \PerfLocale\Plugin::get_instance();
			$cache   = $plugin->get( 'cache' );
			$repo    = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang_id = absint( $_GET['language_id'] );

			$new_lang = $repo->find( $lang_id );

			// set_default() refuses a target that is missing or inactive:
			// get_default() reads the active-only bootstrap, so an inactive
			// default resolves to NULL and takes filter_locale(),
			// is_default_language() and the switcher down with it. Ignoring
			// that refusal repointed core's `page_on_front` at a translation,
			// dropped the visitor cookie and reported success — a destructive
			// write for a default change that never happened. Everything below
			// is conditional on the promotion actually landing.
			if ( ! $new_lang || ! $repo->set_default( $lang_id ) ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-languages',
							'message' => 'default_change_failed',
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Swap the WordPress front page to the new default language version.
			$this->swap_front_page_to_language( $new_lang );

			// Flush rewrite rules since URL prefixes change with default language.
			update_option( 'perflocale_flush_rules', 1, false );

			// Clear the language cookie so visitors re-detect the new default.
			if ( ! headers_sent() ) {
				setcookie(
					'perflocale_lang',
					'',
					[
						'expires'  => time() - 3600,
						'path'     => COOKIEPATH,
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					]
				);
			}

			wp_safe_redirect(
				add_query_arg(
					[
						'page'    => 'perflocale-languages',
						'message' => 'default_changed',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Handle delete via GET. The destructive verb is 'confirm-delete' —
		// 'delete' now renders the read-only cascade-preview screen
		// (LanguagesPage::render_delete_preview), so a stale bookmark of
		// the old delete URL shows the preview instead of deleting.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'confirm-delete' && isset( $_GET['language_id'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'perflocale_delete_language' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$plugin   = \PerfLocale\Plugin::get_instance();
			$cache    = $plugin->get( 'cache' );
			$repo     = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang_id  = absint( $_GET['language_id'] );
			$language = $repo->find( $lang_id );

			// delete() wraps every cascade DELETE in one transaction and returns
			// false after a ROLLBACK, precisely so the operator sees the failed
			// action and can retry — discarding that return reported "Language
			// deleted successfully" over a language that is still there. The
			// missing-row / is-default cases used to fall through to the POST
			// handler below and re-render the screen with nothing removed and
			// nothing said; a stale bookmark of a delete URL reaches both.
			$deleted = ( $language && ! $language->is_default )
				? $repo->delete( $lang_id )
				: false;

			wp_safe_redirect(
				add_query_arg(
					[
						'page'    => 'perflocale-languages',
						'message' => $deleted ? 'deleted' : 'delete_failed',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Handle add/edit via POST.
		if ( empty( $_POST['perflocale_language_action'] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_language' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		$data = [
			'slug'           => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
			'locale'         => isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '',
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'native_name'    => isset( $_POST['native_name'] ) ? sanitize_text_field( wp_unslash( $_POST['native_name'] ) ) : '',
			'flag'           => isset( $_POST['flag'] ) ? sanitize_text_field( wp_unslash( $_POST['flag'] ) ) : '',
			// Use an explicit rtl/ltr from the form when present; otherwise
			// auto-derive from the locale (matching the REST/CLI paths) so an
			// RTL language added without the JS preset still gets dir=rtl.
			'text_direction' => ( isset( $_POST['text_direction'] ) && in_array( $_POST['text_direction'], [ 'rtl', 'ltr' ], true ) )
				? sanitize_key( wp_unslash( $_POST['text_direction'] ) )
				: \PerfLocale\Helper::default_text_direction( isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '' ),
			'date_format'    => isset( $_POST['date_format'] ) ? sanitize_text_field( wp_unslash( $_POST['date_format'] ) ) : '',
			'time_format'    => isset( $_POST['time_format'] ) ? sanitize_text_field( wp_unslash( $_POST['time_format'] ) ) : '',
			'is_active'      => isset( $_POST['is_active'] ) ? 1 : 0,
		];

		$plugin      = \PerfLocale\Plugin::get_instance();
		$cache       = $plugin->get( 'cache' );
		$repo        = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		$action_type = sanitize_text_field( wp_unslash( $_POST['perflocale_language_action'] ) );

		if ( $action_type === 'add' ) {
			// Pre-flight: the slug survived sanitisation. sanitize_key() carries
			// no /u modifier, so it deletes every byte of a non-ASCII slug and
			// hands back ''. That row inserts once (the UNIQUE key allows a
			// single empty slug), the screen says "Language added", and the
			// language can never match a rewrite rule. The repository refuses it
			// now; this turns that refusal into a message that names the value
			// the operator actually typed, rather than a silently re-rendered
			// form. Reuses the invalid_slug notice the edit branch already has.
			if ( $data['slug'] === '' ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-languages',
							'action'  => 'add',
							'message' => 'invalid_slug',
							'dup'     => rawurlencode( isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '' ),
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Pre-flight: friendly duplicate check. Both `slug` and
			// `locale` columns carry UNIQUE indexes. Without this, the
			// admin sees a raw `Duplicate entry 'de' for key
			// 'wp_perflocale_languages.slug'` SQL error printed at the
			// top of the page when they retype an existing slug. Detect
			// + redirect to a humane admin notice instead.
			if ( $data['slug'] !== '' && $repo->find_by_slug( $data['slug'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-languages',
							'action'  => 'add',
							'message' => 'duplicate_slug',
							'dup'     => rawurlencode( $data['slug'] ),
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			if ( $data['locale'] !== '' && $repo->find_by_locale( $data['locale'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-languages',
							'action'  => 'add',
							'message' => 'duplicate_locale',
							'dup'     => rawurlencode( $data['locale'] ),
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Optional pre-flight: handle ticked rename checkboxes. POST shape
			// is `perflocale_rename[old_slug] = new_slug`. Keys and values are
			// sanitised at the boundary (wp_unslash + sanitize_key) so no
			// SQL/XSS/path-traversal payload survives; empty/no-op entries are
			// dropped. Nonce was verified earlier in this branch.
			$rename_payload = [];

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified earlier in this branch.
			if ( isset( $_POST['perflocale_rename'] ) && is_array( $_POST['perflocale_rename'] ) ) {
				// wp_unslash recurses into arrays, so a single call un-slashes
				// every key + value below; the foreach then sanitises each
				// pair to lowercase alnum/dash/underscore via sanitize_key.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-element via sanitize_key() in the foreach below.
				$rename_raw = wp_unslash( (array) $_POST['perflocale_rename'] );

				foreach ( $rename_raw as $old => $new ) {
					$old_clean = sanitize_key( (string) $old );
					$new_clean = sanitize_key( (string) $new );

					if ( $old_clean === '' || $new_clean === '' || $old_clean === $new_clean ) {
						continue;
					}

					$rename_payload[ $old_clean ] = $new_clean;
				}
			}

			$renamed_count = 0;

			foreach ( $rename_payload as $old_slug => $new_slug ) {
				$candidate = $repo->find_by_slug( $old_slug );

				if ( ! $candidate ) {
					continue;
				}

				if ( $repo->rename_slug( (int) $candidate->id, $new_slug ) ) {
					++$renamed_count;
				}
			}

			// Default new languages to the bottom of the list. The schema's
			// `sort_order DEFAULT 0` would land every new row above all
			// existing rows because the list query is `ORDER BY sort_order
			// ASC, id ASC` — visually jarring when admins add a language
			// expecting it to append, not jump to the top.
			global $wpdb;
			$lt = $wpdb->prefix . 'perflocale_languages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $lt is `$wpdb->prefix . 'perflocale_languages'`, plugin-controlled, never user input.
			$max_sort           = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(MAX(sort_order), 0) FROM %i',
					$lt
				)
			);
			$data['sort_order'] = $max_sort + 1;

			$result = $repo->insert( $data );

			if ( $result !== false ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-languages',
							'message' => $renamed_count > 0 ? 'added_renamed' : 'added',
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		if ( $action_type === 'edit' && isset( $_POST['language_id'] ) ) {
			$lang_id = absint( $_POST['language_id'] );

			// Pre-flight duplicate check mirroring the add branch, excluding the
			// row being edited. Both `slug` and `locale` carry UNIQUE indexes;
			// without this the update() below fails with a raw SQL error, returns
			// false, and the is_default side effects would still fire on a save
			// that never happened.
			if ( $data['slug'] !== '' ) {
				$slug_match = $repo->find_by_slug( $data['slug'] );

				if ( $slug_match && (int) $slug_match->id !== $lang_id ) {
					wp_safe_redirect(
						add_query_arg(
							[
								'page'        => 'perflocale-languages',
								'action'      => 'edit',
								'language_id' => $lang_id,
								'message'     => 'duplicate_slug',
								'dup'         => rawurlencode( $data['slug'] ),
							],
							admin_url( 'admin.php' )
						)
					);
					exit;
				}
			}

			if ( $data['locale'] !== '' ) {
				$locale_match = $repo->find_by_locale( $data['locale'] );

				if ( $locale_match && (int) $locale_match->id !== $lang_id ) {
					wp_safe_redirect(
						add_query_arg(
							[
								'page'        => 'perflocale-languages',
								'action'      => 'edit',
								'language_id' => $lang_id,
								'message'     => 'duplicate_locale',
								'dup'         => rawurlencode( $data['locale'] ),
							],
							admin_url( 'admin.php' )
						)
					);
					exit;
				}
			}

			$result = $repo->update( $lang_id, $data );

			if ( $result !== false ) {
				// Handle "set as default" checkbox. Only after a confirmed save
				// so a failed rename can't swap the site default / front page.
				if ( ! empty( $_POST['is_default'] ) ) {
					$current_default = $repo->get_default();

					if ( ! $current_default || (int) $current_default->id !== $lang_id ) {
						// set_default() refuses a missing or INACTIVE target, and
						// this form can produce one in a single submit: unticking
						// "Active" while ticking "Set as default" saves is_active=0
						// first, so the promotion is then rejected. Ignoring that
						// return repointed core's `page_on_front` at the language's
						// translation, dropped the visitor cookie and scheduled a
						// rewrite flush — a destructive write for a default change
						// that never happened, reported as "Language updated
						// successfully". Honour the refusal exactly as the GET
						// set_default branch above does; the row edit itself has
						// already been written, so only the promotion is reported
						// as failed.
						if ( ! $repo->set_default( $lang_id ) ) {
							wp_safe_redirect(
								add_query_arg(
									[
										'page'    => 'perflocale-languages',
										'message' => 'default_change_failed',
									],
									admin_url( 'admin.php' )
								)
							);
							exit;
						}

						$new_default = $repo->find( $lang_id );

						if ( $new_default ) {
							$this->swap_front_page_to_language( $new_default );
						}

						// Clear language cookie so visitors get the new default.
						if ( ! headers_sent() ) {
							setcookie(
								'perflocale_lang',
								'',
								[
									'expires'  => time() - 3600,
									'path'     => COOKIEPATH,
									'domain'   => COOKIE_DOMAIN,
									'secure'   => is_ssl(),
									'httponly' => true,
									'samesite' => 'Lax',
								]
							);
						}

						// Flush rewrite rules for the new default prefix.
						update_option( 'perflocale_flush_rules', 1, false );
					}
				}

				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-languages',
							'message' => 'updated',
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// update() refuses a slug the routing layer cannot express, and the
			// refusal happens BEFORE the row is written — so a name, flag or
			// is_active change posted alongside a bad slug is discarded with it.
			// Without this branch the screen simply re-renders with nothing
			// saved and nothing said, which is the silent-failure shape the two
			// duplicate checks above already avoid. Mirror them: send the
			// offending value back so the notice can name it.
			wp_safe_redirect(
				add_query_arg(
					[
						'page'        => 'perflocale-languages',
						'action'      => 'edit',
						'language_id' => $lang_id,
						'message'     => 'invalid_slug',
						'dup'         => rawurlencode( $data['slug'] ),
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Register Screen Options for the Strings page.
	 *
	 * @return void
	 */
	public function register_screen_options(): void {
		$screens = [
			'strings'      => [
				'label'  => __( 'Strings per page', 'perflocale' ),
				'option' => 'perflocale_strings_per_page',
			],
			'languages'    => [
				'label'  => __( 'Languages per page', 'perflocale' ),
				'option' => 'perflocale_languages_per_page',
			],
			'translations' => [
				'label'  => __( 'Translations per page', 'perflocale' ),
				'option' => 'perflocale_translations_per_page',
			],
		];

		foreach ( $screens as $key => $config ) {
			if ( ! isset( $this->page_hooks[ $key ] ) || ! is_string( $this->page_hooks[ $key ] ) ) {
				continue;
			}

			$option_name = $config['option'];
			$label       = $config['label'];

			add_action(
				'load-' . $this->page_hooks[ $key ],
				function () use ( $label, $option_name ): void {
					add_screen_option(
						'per_page',
						[
							'label'   => $label,
							'default' => 20,
							'option'  => $option_name,
						]
					);
				}
			);
		}

		// Add column visibility checkboxes to the Strings page Screen Options.
		if ( isset( $this->page_hooks['strings'] ) ) {
			add_filter( 'screen_settings', [ $this, 'render_strings_column_options' ], 10, 2 );
		}

		// Same column-visibility helper for the Translations page.
		if ( isset( $this->page_hooks['translations'] ) ) {
			add_filter( 'screen_settings', [ $this, 'render_translations_column_options' ], 10, 2 );
		}

		// Save filters are registered in register_hooks() to run before set_screen_options().
	}

	/**
	 * Render language column visibility checkboxes in Screen Options.
	 *
	 * @param string     $settings Screen settings HTML.
	 * @param \WP_Screen $screen Current screen.
	 * @return string
	 */
	public function render_strings_column_options( string $settings, \WP_Screen $screen ): string {
		if ( ! isset( $this->page_hooks['strings'] ) || $screen->id !== $this->page_hooks['strings'] ) {
			return $settings;
		}

		$plugin    = \PerfLocale\Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return $settings;
		}

		$hidden = (array) get_user_meta( get_current_user_id(), 'perflocale_strings_hidden_langs', true );

		$settings .= '<fieldset class="metabox-prefs">';
		$settings .= '<legend>' . esc_html__( 'Language Columns', 'perflocale' ) . '</legend>';

		foreach ( $languages as $lang ) {
			$checked   = ! in_array( $lang->slug, $hidden, true ) ? ' checked="checked"' : '';
			$settings .= '<label>';
			$settings .= '<input class="perflocale-col-toggle" type="checkbox" value="' . esc_attr( $lang->slug ) . '"' . $checked . '>';
			$settings .= esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . $lang->name );
			$settings .= '</label>';
		}

		$settings .= '</fieldset>';

		// Inline JS to save column visibility via AJAX.
		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleColNonce=' . wp_json_encode( wp_create_nonce( 'perflocale_hidden_columns' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);

		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var checkboxes=document.querySelectorAll(".perflocale-col-toggle");' .
				'checkboxes.forEach(function(cb){' .
					'cb.addEventListener("change",function(){' .
						'var hidden=[];' .
						'checkboxes.forEach(function(c){if(!c.checked)hidden.push(c.value);});' .
						'var fd=new FormData();' .
						'fd.append("action","perflocale_save_hidden_columns");' .
						'fd.append("_wpnonce",perflocaleColNonce);' .
						'fd.append("hidden",JSON.stringify(hidden));' .
						'fetch(ajaxurl,{method:"POST",body:fd});' .
						'var slug=this.value;' .
						'var cols=document.querySelectorAll("[data-perflocale-lang-col=\'"+slug+"\']");' .
						'cols.forEach(function(el){el.style.display=cb.checked?"":"none";});' .
					'});' .
				'});' .
			'})();'
		);

		return $settings;
	}

	/**
	 * Render Language Columns checkboxes for the Translations page.
	 * Mirrors render_strings_column_options - same UI, different user-meta
	 * key so the two pages can have independent column preferences.
	 *
	 * @param string     $settings Screen settings HTML.
	 * @param \WP_Screen $screen Current screen.
	 * @return string
	 */
	public function render_translations_column_options( string $settings, \WP_Screen $screen ): string {
		if ( ! isset( $this->page_hooks['translations'] ) || $screen->id !== $this->page_hooks['translations'] ) {
			return $settings;
		}

		return $this->render_language_column_options(
			$settings,
			'perflocale_translations_hidden_langs',
			'perflocale_translations_hidden_cols'
		);
	}

	/**
	 * Shared implementation for the two new pages' Language Columns
	 * checkbox fieldset. Uses a distinct nonce-slug per page so sessions
	 * can't cross-submit.
	 *
	 * @param string $settings Existing screen settings HTML to append to.
	 * @param string $meta_key User-meta key that stores hidden slugs.
	 * @param string $nonce_slug Unique nonce action slug for the AJAX save.
	 * @return string
	 */
	private function render_language_column_options( string $settings, string $meta_key, string $nonce_slug ): string {
		$plugin    = \PerfLocale\Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return $settings;
		}

		$hidden = (array) get_user_meta( get_current_user_id(), $meta_key, true );

		$settings .= '<fieldset class="metabox-prefs">';
		$settings .= '<legend>' . esc_html__( 'Language Columns', 'perflocale' ) . '</legend>';

		foreach ( $languages as $lang ) {
			$checked   = ! in_array( $lang->slug, $hidden, true ) ? ' checked="checked"' : '';
			$settings .= '<label>';
			$settings .= '<input class="perflocale-col-toggle" data-meta-key="' . esc_attr( $meta_key ) . '" type="checkbox" value="' . esc_attr( $lang->slug ) . '"' . $checked . '>';
			$settings .= esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . $lang->name );
			$settings .= '</label>';
		}

		$settings .= '</fieldset>';

		// Inline JS: save column visibility via admin-ajax. Uses the SAME
		// AJAX action as the Strings page (perflocale_save_hidden_columns)
		// but submits its own meta_key so the handler stores into the right
		// bucket. See ajax_save_hidden_columns_generic below.
		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleColNonce_' . esc_js( $nonce_slug ) . '=' . wp_json_encode( wp_create_nonce( $nonce_slug ) ) . ';',
			'before'
		);

		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var boxes=document.querySelectorAll(".perflocale-col-toggle[data-meta-key=\"' . esc_js( $meta_key ) . '\"]");' .
				'boxes.forEach(function(cb){' .
					'cb.addEventListener("change",function(){' .
						'var hidden=[];' .
						'boxes.forEach(function(c){if(!c.checked)hidden.push(c.value);});' .
						'var fd=new FormData();' .
						'fd.append("action","perflocale_save_hidden_columns_generic");' .
						'fd.append("_wpnonce",perflocaleColNonce_' . esc_js( $nonce_slug ) . ');' .
						'fd.append("meta_key",' . wp_json_encode( $meta_key ) . ');' .
						'fd.append("hidden",JSON.stringify(hidden));' .
						'fetch(ajaxurl,{method:"POST",body:fd});' .
						'var slug=this.value;' .
						'document.querySelectorAll("[data-perflocale-lang-col=\'"+slug+"\']").forEach(function(el){el.style.display=cb.checked?"":"none";});' .
					'});' .
				'});' .
			'})();'
		);

		return $settings;
	}

	/**
	 * Process inline string translation saves.
	 *
	 * @return void
	 */
	public function process_string_translations(): void {
		if ( empty( $_POST['perflocale_save_string_translations'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_string_translations' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'perflocale_translate' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		// Sanitize with wp_kses_post, NOT sanitize_textarea_field. Gettext
		// originals are stored verbatim and routinely carry markup — a link
		// `<a href="%s">`, `<strong>`, an entity — and sanitize_textarea_field
		// strips EVERY tag (taking the %s placeholder inside the attribute with
		// it) and deletes %xx sequences, silently corrupting any HTML-bearing
		// translation on save. wp_kses_post preserves post-safe markup and the
		// placeholders while still stripping <script>/on* — the right trust
		// level for the low-privilege Translator role that holds this cap.
		$translations = isset( $_POST['perflocale_str_trans'] ) ? map_deep( wp_unslash( $_POST['perflocale_str_trans'] ), 'wp_kses_post' ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $translations ) ) {
			return;
		}

		// Extra plural forms 2..N for 3+ form languages: [string_id][lang_id] = [form2, form3, …]. Same markup fidelity as the base translation above.
		$extra_forms_post = isset( $_POST['perflocale_str_extra'] ) ? map_deep( wp_unslash( $_POST['perflocale_str_extra'] ), 'wp_kses_post' ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$extra_forms_post = is_array( $extra_forms_post ) ? $extra_forms_post : [];

		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$links_table   = \PerfLocale\Database\Schema::table( 'translation_links' );
		$strings_table = \PerfLocale\Database\Schema::table( 'strings' );
		$groups_table  = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$plugin        = \PerfLocale\Plugin::get_instance();
		$cache         = $plugin->get( 'cache' );
		$st_repo       = new \PerfLocale\Database\Repository\StringTranslationRepository( $cache );
		$group_repo    = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );

		$saved   = 0;
		$deleted = 0;
		// Cells whose write could not be attempted at all (group INSERT or link
		// upsert failed). Reported on the redirect so a save that silently
		// dropped a translator's text is never indistinguishable from a save
		// where nothing needed writing.
		$link_failures = 0;

		// Pre-fetch every string's group_id in ONE query. The old loop
		// issued a SELECT per string — fine for the typical admin-form
		// save of 20–50 entries, but linear waste on 200+ bulk imports
		// (CLI, REST). Building the {string_id => group_id} map upfront
		// also dedups repeated string_ids in the input for free.
		$string_ids = array_values( array_filter( array_map( 'absint', array_keys( $translations ) ) ) );
		$group_map  = [];

		if ( ! empty( $string_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, group_id FROM %i WHERE id IN ({$placeholders})",
					array_merge( [ $strings_table ], $string_ids )
				)
			);

			foreach ( (array) $rows as $row ) {
				$group_map[ (int) $row->id ] = (int) $row->group_id;
			}
		}

		// Current stored translations for every posted string, in ONE query,
		// keyed [string_id][language_id] => translation. The grid re-posts
		// EVERY cell on the page, so without this an edit to one cell would
		// re-write (and re-link, clearing needs_update on) every other cell.
		// Diffing against the stored value lets the loop skip untouched cells
		// entirely: no needless write, no status reset, and the rare
		// wp_kses '<'-in-prose normalisation only ever touches a cell the
		// translator actually edited.
		$current_map       = [];
		$current_extra_map = [];

		if ( ! empty( $string_ids ) ) {
			$st_table     = \PerfLocale\Database\Schema::table( 'string_translations' );
			$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$current_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT string_id, language_id, translation, extra_forms FROM %i WHERE string_id IN ({$placeholders})",
					array_merge( [ $st_table ], $string_ids )
				)
			);

			foreach ( (array) $current_rows as $row ) {
				$current_map[ (int) $row->string_id ][ (int) $row->language_id ]       = (string) $row->translation;
				$current_extra_map[ (int) $row->string_id ][ (int) $row->language_id ] = null === $row->extra_forms ? null : (string) $row->extra_forms;
			}
		}

		// Languages actually written/deleted this save — scopes the cache
		// flush + file regeneration below to what changed.
		$touched_lang_ids = [];

		foreach ( $translations as $string_id => $lang_translations ) {
			$string_id = absint( $string_id );

			if ( ! is_array( $lang_translations ) ) {
				continue;
			}

			// A string_id absent from the map has no `strings` row at all (a
			// stale form, or the row was deleted between render and save):
			// there is nothing to write against and nothing to repair.
			if ( ! array_key_exists( $string_id, $group_map ) ) {
				continue;
			}

			$group_id = $group_map[ $string_id ];

			// strings.group_id is an unenforced FK, and three shapes fail it:
			// 0 (never grouped), an id whose group row is gone, and an id that
			// collides with a live post/term group. Writing links under any of
			// them either orphans the row or repoints the colliding group's
			// link via upsert_link()'s ON DUPLICATE KEY UPDATE, so none of them
			// may reach the writes below as-is.
			//
			// Skipping the string outright is not an option either: the
			// redirect carries saved=0 and StringsPage gates its notice on
			// saved > 0, so the operator's typed translation would vanish with
			// no message at all — and stay untranslatable forever, since
			// nothing else repairs a string that has no translation row yet.
			// Self-heal instead, onto a fresh string-type group, with the same
			// two statements TranslationFileGenerator::
			// repair_orphaned_translations() shape (2) uses.
			//
			// Deferred to the first cell that actually writes (below): the grid
			// re-posts EVERY cell on the page, so healing here would mint a
			// group for every untouched string in view.
			$needs_group = ! $group_repo->is_string_group( $group_id );

			foreach ( $lang_translations as $lang_id => $value ) {
				$lang_id = absint( $lang_id );
				// Value was already wp_unslash'd + sanitized by map_deep()
				// at the top of this method. Re-applying wp_unslash here
				// would eat legitimate backslashes in the translation.
				$value = (string) $value;

				if ( $value === '' ) {
					// Remove the translation link if empty. Not while the
					// string still needs a group: it owns no link of its own,
					// and its unusable group_id may belong to a live post/term
					// group whose link this would delete. Clearing an already
					// blank cell has nothing to heal for, so no group is minted
					// here — the string_translations delete below is keyed on
					// string_id and stays correct either way.
					if ( ! $needs_group ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->delete(
							$links_table,
							[
								'group_id'    => $group_id,
								'language_id' => $lang_id,
							],
							[ '%d', '%d' ]
						);
					}

					// Drop the stored translation text too so subsequent
					// reads correctly return "no translation" — but only when
					// there is a row to drop. The grid re-posts EVERY cell on
					// the page and most of them are blank, so an unconditional
					// DELETE here costs one write per empty cell on a save
					// that changed nothing, and — worse — leaves $deleted
					// non-zero, firing the whole cache-flush + .l10n.php
					// regeneration + `perflocale/strings/changed` block below
					// over a no-op. $current_map already holds every stored
					// (string_id, language_id) pair from the single SELECT at
					// the top of this method, so the guard is free.
					//
					// Gating the flush on it is also exactly right: the
					// all_string_translations_{lang} map is driven FROM
					// string_translations (StringTranslation::preload_
					// translations), so a language with no row here had
					// nothing cached to invalidate.
					if ( isset( $current_map[ $string_id ][ $lang_id ] ) ) {
						$st_repo->delete( $string_id, $lang_id );

						++$deleted;
						$touched_lang_ids[ $lang_id ] = true;
					}

					continue;
				}

				$row_extra   = $extra_forms_post[ $string_id ][ $lang_id ] ?? null;
				$text_change = ! isset( $current_map[ $string_id ][ $lang_id ] )
					|| $current_map[ $string_id ][ $lang_id ] !== $value;

				// A cell is "touched" iff its base text OR its stored plural
				// forms differ from what's on disk. Compare the posted forms
				// against the stored column in canonical form so a 3+ form
				// cell that re-posts its unchanged forms is NOT treated as an
				// edit (which would reset needs_update and re-write markup).
				$extra_change = false;

				if ( is_array( $row_extra ) ) {
					$posted_extra_json = \PerfLocale\Database\Repository\StringTranslationRepository::normalize_extra_forms(
						array_map( 'strval', $row_extra )
					);
					$stored_extra_json = $current_extra_map[ $string_id ][ $lang_id ] ?? null;
					$extra_change      = $posted_extra_json !== $stored_extra_json;
				}

				// Untouched cell (base text unchanged AND plural forms
				// unchanged): leave it entirely alone. The grid re-posts every
				// cell, so writing here would reset a needs_update flag the
				// source-change tracker set on a translation the operator never
				// edited — and re-run wp_kses over already-stored markup.
				if ( ! $text_change && ! $extra_change ) {
					continue;
				}

				// The cell is about to be written, so the string needs a group
				// it can legally own. Mint one and repoint `strings.group_id`
				// at it — the same INSERT-then-UPDATE pair, in the same order,
				// that repair_orphaned_translations() shape (2) runs, so the
				// two self-heal paths cannot drift. Only the first written cell
				// pays for this; the remaining languages reuse the new group.
				// A failed INSERT leaves the string exactly as it was, so the
				// next save retries rather than writing under a bad id.
				if ( $needs_group ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$inserted = $wpdb->insert(
						$groups_table,
						[ 'type' => \PerfLocale\Enum\ObjectType::String->value ],
						[ '%s' ]
					);

					if ( ! $inserted ) {
						// No group id to write under, so every cell of this
						// string is skipped. Counted, not silent — see the
						// upsert failure branch below.
						++$link_failures;
						continue 2;
					}

					$group_id = (int) $wpdb->insert_id;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$strings_table,
						[ 'group_id' => $group_id ],
						[ 'id' => $string_id ],
						[ '%d' ],
						[ '%d' ]
					);

					$needs_group = false;
				}

				// Link this string-group to the target language. For string
				// translations object_id = string_id (each language-link points
				// back to the string row).
				//
				// translation_links carries TWO unique keys — group_lang
				// (group_id, language_id) and object_lang (type, object_id,
				// language_id) — and upsert_link()'s INSERT … ON DUPLICATE KEY
				// UPDATE can land on either. Sibling-language links for this
				// string are preserved because they differ in language_id on
				// both keys; the object_lang case (this string already holding a
				// row for this language under a DIFFERENT, usually dead, group —
				// the shape the $needs_group heal above mints a new group for)
				// is resolved inside upsert_link(), which drops the stale row and
				// retries rather than leaving the link on the dead group.
				$link_result = $group_repo->upsert_link( $group_id, $string_id, $lang_id, 'translated', \PerfLocale\Enum\SourceType::Manual );

				if ( $link_result === false ) {
					// Only a hard DB error reaches here. Dropping the cell
					// silently is the very failure the $needs_group comment
					// above refuses to accept: `saved` would stay 0, StringsPage
					// gates its notice on saved > 0, and the operator's typed
					// translation would disappear with no message. Count it so
					// the redirect can say something happened.
					++$link_failures;
					continue;
				}

				// Store the translation text in the dedicated table (upsert
				// on the (string_id, language_id) PRIMARY KEY; extra_forms
				// is preserved by set() itself). Skip the write when only the
				// plural forms changed — set() would be a no-op but still
				// costs a query.
				if ( $text_change ) {
					$st_repo->set( $string_id, $lang_id, (string) $value );
				}

				// Extra plural forms 2..N: runs after set() because the row
				// must exist (set_extra_forms is an UPDATE). Only 3+ form
				// languages post these inputs — they always submit (empty
				// strings included), so an operator clearing every field
				// still reaches set_extra_forms, which normalises to NULL.
				// Only write when the forms actually changed.
				if ( $extra_change && is_array( $row_extra ) ) {
					$st_repo->set_extra_forms( $string_id, $lang_id, array_map( 'strval', $row_extra ) );
				}

				++$saved;
				$touched_lang_ids[ $lang_id ] = true;
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Flush string translation cache and regenerate files. A delete-only
		// save ($saved === 0 but $deleted > 0) still removed rows, so the
		// cached all_string_translations_{lang} map + .l10n.php files would
		// otherwise keep serving the just-removed translation until TTL.
		if ( ( $saved > 0 || $deleted > 0 ) && $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$all_langs = $lang_repo->get_active();

			foreach ( $all_langs as $lang ) {
				$cache->delete( "all_string_translations_{$lang->id}", 'perflocale_strings' );
			}

			// Regenerate .l10n.php files if using file mode — scoped to the
			// languages this save actually touched (a one-string save was
			// re-fetching EVERY language's full translation set). The
			// all-language cache-delete loop above deliberately stays broad:
			// the VE gettext bridge depends on it (do not narrow).
			if ( $this->settings->get( 'string_translation_mode' ) === 'files' ) {
				$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
				$generator->generate_all( $touched_lang_ids === [] ? null : array_keys( $touched_lang_ids ) );
			}

			/**
			 * Fires after string translations are saved from the admin
			 * Strings screen. Lets addons that derive state from the
			 * `strings`/`string_translations` tables (e.g. the Visual
			 * Editor's per-language bundles) invalidate it — core itself
			 * only knows about its own gettext caches.
			 *
			 * @hook perflocale/strings/changed
			 *
			 * @param string $origin What changed the strings ('admin_save').
			 */
			do_action( 'perflocale/strings/changed', 'admin_save' );
		}

		// Redirect back with count.
		$redirect_args = [
			'page'  => 'perflocale-strings',
			'saved' => $saved,
		];

		if ( $link_failures > 0 ) {
			$redirect_args['save_failed'] = $link_failures;
		}

		// Preserve filters.
		if ( ! empty( $_POST['domain_filter'] ) ) {
			$redirect_args['domain_filter'] = sanitize_text_field( wp_unslash( $_POST['domain_filter'] ) );
		}

		if ( ! empty( $_POST['search'] ) ) {
			$redirect_args['s'] = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		}

		if ( ! empty( $_POST['paged'] ) ) {
			$redirect_args['paged'] = absint( $_POST['paged'] );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}


	/**
	 * Whether the Translations page should expose the "MT-translate" bulk
	 * action. True only when the active provider is fully configured
	 * (API key set, etc.). When false, the option is hidden — better than
	 * a greyed-out "why doesn't this work?" entry.
	 *
	 * @return bool
	 */
	public static function bulk_mt_translate_available(): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return false;
		}

		$settings = $plugin->get( 'settings' );

		// Master switch first: with MT disabled the REST endpoints already
		// refuse (mt_disabled 403), so admin bulk surfaces must not offer the
		// action either — a configured provider alone is not enough.
		if ( ! $settings->mt_enabled() ) {
			return false;
		}

		$provider_id = (string) $settings->get( 'mt_provider' );

		if ( $provider_id === '' ) {
			return false;
		}

		try {
			$service  = new \PerfLocale\MachineTranslation\TranslationService( $settings, $plugin->get( 'cache' ) );
			$provider = $service->get_provider( $provider_id );
			return $provider->is_configured();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Handle the "Translate the entire site" panel submit (nonce + cap
	 * already verified by process_translations_bulk before delegating).
	 *
	 * @return void
	 */
	private function process_site_translate(): void {
		if ( ! self::bulk_mt_translate_available() ) {
			wp_safe_redirect( add_query_arg( 'perflocale_bulk', 'bulk_mt_unavailable', admin_url( 'admin.php?page=perflocale-translations' ) ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$site_lang_ids = isset( $_POST['site_lang_ids'] ) && is_array( $_POST['site_lang_ids'] )
			? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['site_lang_ids'] ) ) ) )
			: [];

		$site_post_types = isset( $_POST['site_post_types'] ) && is_array( $_POST['site_post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['site_post_types'] ) ) ) )
			: [];

		$include_meta = ! empty( $_POST['include_meta'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Only public, UI-visible post types may be targeted — anything else
		// in the POST is dropped (defends against crafted type names).
		$allowed_types   = array_keys(
			get_post_types(
				[
					'public'  => true,
					'show_ui' => true,
				],
				'names'
			)
		);
		// Attachments are additionally excluded to mirror the panel: they are
		// post_status=inherit so the job would select nothing anyway, but the
		// whitelist should match what the UI offers.
		$allowed_types = array_diff( $allowed_types, [ 'attachment' ] );

		$site_post_types = array_values( array_intersect( $site_post_types, $allowed_types ) );

		if ( $site_lang_ids === [] || $site_post_types === [] ) {
			wp_safe_redirect( add_query_arg( 'perflocale_bulk', 'site_translate_empty', admin_url( 'admin.php?page=perflocale-translations' ) ) );
			exit;
		}

		$outcome = \PerfLocale\Background\Dispatcher::dispatch(
			new \PerfLocale\Background\Jobs\SiteTranslateJob(),
			[
				'post_types'      => $site_post_types,
				'target_lang_ids' => $site_lang_ids,
				'include_meta'    => $include_meta,
				'after_id'        => 0,
			]
		);

		if ( ( $outcome['mode'] ?? '' ) === 'denied' || ( $outcome['mode'] ?? '' ) === 'error' ) {
			set_transient( 'perflocale_bulk_mt_error_' . get_current_user_id(), (string) ( $outcome['error'] ?? __( 'Dispatch failed.', 'perflocale' ) ), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'perflocale_bulk', 'site_translate_denied', admin_url( 'admin.php?page=perflocale-translations' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'bulk_mt_queued', '1', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
		exit;
	}

	/**
	 * Process the Translations-page bulk-actions POST. Two actions:
	 *
	 *   - mt_translate     — MT-translate selected source posts into the
	 *                        chosen target language (or all). Skips pairs
	 *                        that already have a translation. Routed
	 *                        through the Dispatcher: small selections run
	 *                        inline (admin sees counts on the redirect);
	 *                        larger selections go async (admin redirects
	 *                        to PerfLocale → Jobs to watch progress).
	 *                        The sync/async cutoff is configurable per
	 *                        site under Settings → Performance → Background
	 *                        Thresholds → "Bulk machine translation".
	 *   - mark_needs_update — Flip status='needs_update' on existing
	 *                        translation_links rows, scoped to the chosen
	 *                        target language (or all).
	 *
	 * @return void
	 */
	public function process_translations_bulk(): void {
		if ( empty( $_POST['perflocale_translations_action'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_translations_bulk' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		// Site-wide MT: the "Translate the entire site" panel posts the same
		// nonce with action=site_translate. Dispatches the chunked background
		// chain (SiteTranslateJob) — never an inline loop.
		if ( sanitize_key( wp_unslash( $_POST['perflocale_translations_action'] ) ) === 'site_translate' ) {
			$this->process_site_translate();
			return;
		}

		$bulk_action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$bulk_value  = sanitize_text_field( wp_unslash( $_POST['bulk_value'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() applied per-element on the next line.
		$ids_raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : [];
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids_raw ), fn( $i ) => $i > 0 ) );

		// Preserve filters so the redirect lands back on the same view.
		$preserve = [];
		foreach ( [ 'post_type_filter', 'status_filter', 'source_filter', 's', 'perflocale_lang', 'paged', 'orderby', 'order' ] as $arg ) {
			if ( isset( $_POST[ $arg ] ) && $_POST[ $arg ] !== '' ) {
				$preserve[ $arg ] = sanitize_text_field( wp_unslash( (string) $_POST[ $arg ] ) );
			}
		}

		$redirect = function ( string $message, array $extra = [] ) use ( $preserve ): void {
			wp_safe_redirect(
				add_query_arg(
					array_merge(
						$preserve,
						[
							'page'    => 'perflocale-translations',
							'message' => $message,
						],
						$extra
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		};

		if ( empty( $ids ) || $bulk_action === '' ) {
			$redirect( 'bulk_no_selection' );
		}

		$plugin    = \PerfLocale\Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );

		// Resolve target languages from the secondary value.
		// "all" means every active language EXCEPT the default (you can't
		// translate a post into its source language).
		$default    = $lang_repo->get_default();
		$default_id = $default ? (int) $default->id : 0;
		$active     = $lang_repo->get_active();

		$target_lang_ids = [];

		if ( $bulk_value === 'all' ) {
			foreach ( $active as $l ) {
				if ( (int) $l->id !== $default_id ) {
					$target_lang_ids[] = (int) $l->id;
				}
			}
		} else {
			$resolved = $lang_repo->find_by_slug( sanitize_key( $bulk_value ) );
			if ( $resolved && (int) $resolved->id !== $default_id ) {
				$target_lang_ids[] = (int) $resolved->id;
			}
		}

		if ( empty( $target_lang_ids ) ) {
			$redirect( 'bulk_no_target_language' );
		}

		$lang_by_id = [];
		foreach ( $active as $l ) {
			$lang_by_id[ (int) $l->id ] = $l;
		}

		switch ( $bulk_action ) {
			case 'mt_translate':
				if ( ! self::bulk_mt_translate_available() ) {
					$redirect( 'bulk_mt_unavailable' );
				}

				// Route through the Dispatcher. BulkTranslateJob runs the
				// same inline TranslationService loop when the request is
				// below the configured threshold; bigger dispatches return
				// immediately and the worker grinds through the matrix in
				// the background, visible under PerfLocale → Jobs.
				//
				// Batch size driven by the threshold setting (default 25
				// pairs, configurable per site).
				// Meta-field MT opt-in from the confirm dialog. The curated key
				// registry is additionally setting-gated, so this is safe to
				// pass even when both meta settings are off (no-op).
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of process_translations_bulk().
				$bulk_include_meta = ! empty( $_POST['include_meta'] );

				$result = \PerfLocale\Background\Dispatcher::dispatch(
					new \PerfLocale\Background\Jobs\BulkTranslateJob(),
					[
						'source_ids'      => $ids,
						'target_lang_ids' => $target_lang_ids,
						'include_meta'    => $bulk_include_meta,
					]
				);

				if ( $result['mode'] === 'async' ) {
					wp_safe_redirect(
						add_query_arg(
							'bulk_mt_queued',
							'1',
							admin_url( 'admin.php?page=perflocale-jobs' )
						)
					);
					exit;
				}

				$r           = is_array( $result['result'] ?? null ) ? $result['result'] : [];
				$created     = (int) ( $r['created'] ?? 0 );
				$skipped     = (int) ( $r['skipped'] ?? 0 );
				$failed      = (int) ( $r['failed'] ?? 0 );
				$first_error = (string) ( $r['first_error'] ?? '' );

				// Stash the first error message in a per-user transient so
				// the redirect URL stays clean (error text can contain odd
				// characters, and embedding it in a query string creates
				// invalid URLs and CSRF-prone reflections).
				if ( $first_error !== '' ) {
					set_transient( 'perflocale_bulk_mt_error_' . get_current_user_id(), $first_error, 5 * MINUTE_IN_SECONDS );
				}

				$redirect(
					'bulk_mt_done',
					[
						'created' => $created,
						'skipped' => $skipped,
						'failed'  => $failed,
					]
				);
				break;

			case 'mark_needs_update':
				global $wpdb;
				$tl                = \PerfLocale\Database\Schema::table( 'translation_links' );
				$tg                = \PerfLocale\Database\Schema::table( 'translation_groups' );
				$id_placeholders   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$lang_placeholders = implode( ',', array_fill( 0, count( $target_lang_ids ), '%d' ) );
				$post_type         = \PerfLocale\Enum\ObjectType::Post->value;

				// Join through group_id, do NOT match object_id against the
				// target languages. The checkboxes on the Translations screen
				// carry SOURCE post ids, and a source post's own link row is
				// its DEFAULT-language row — the one language $target_lang_ids
				// deliberately excludes (see the "all" branch above). Filtering
				// `object_id IN (selection) AND language_id IN (targets)` on a
				// single row therefore asks for two disjoint sets and matches
				// nothing. The sibling rows we actually want share the group:
				// resolve each selected post's group from its own row (s) and
				// flip that group's rows in the target languages (t).
				//
				// object_id is polymorphic — a term with the same numeric id
				// owns its own row here — so the scope has to say "posts".
				// It says so the way the rest of the plugin says it: through
				// translation_groups.type, exactly like TranslationsPage::
				// register_source_only_filter(), which decides the very same
				// question for the checkboxes feeding this handler. The group
				// row is the authority on what a group holds;
				// translation_links.type is a denormalised copy that rows
				// written before that column existed carry EMPTY (mutest's
				// three subsites still hold 15 such rows today, every one of
				// them inside a post/term group), and a `links.type` predicate
				// silently matches none of them.
				//
				// COUNT(DISTINCT t.id), not COUNT(*): the s-side join yields
				// one row per (source row, target row) pair, so a group with
				// more than one matching s row would multiply its own targets
				// in the count. The UPDATE is unaffected either way — it flips
				// each t row at most once — but the operator-facing number and
				// the hook payload must be target rows, not join pairs.
				//
				// Resolve the scope before writing, in one indexed query, for
				// two reasons. It yields the group ids whose 3-layer
				// get_translations cache the UPDATE below invalidates (the
				// UPDATE bypasses the repository, so reads would keep serving
				// the pre-update status until the TTL). And it yields a row
				// count that survives a re-run: $wpdb->query() reports MySQL's
				// *changed* rows, so flagging an already-flagged translation
				// would report "0 translations flagged" — the same green
				// message over a no-op that this branch exists to fix. The
				// notice says "%d translations flagged as needing update", so
				// the honest number is the rows the scope matched, all of which
				// carry needs_update by the time the redirect lands.
				//
				// Every table is index-driven (verified with EXPLAIN on
				// test.local's 6,815 links): s resolves through the
				// object_lookup (object_id, language_id) KEY, g is a PRIMARY
				// KEY lookup, t rides the group_lang (group_id, language_id)
				// UNIQUE key.
				//
				// The table names lead each arg list: the %i identifier
				// placeholders come first, then the %s values and the %d id
				// lists, in the order they appear in the statement. prepare()
				// consumes args strictly in order.
				$scope_args = array_merge(
					[ $tl, $tg, $tl, $post_type ],
					$ids,
					$target_lang_ids
				);

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope resolution for the bulk flip: no cache to read. The two interpolated variables are generated "%d,%d,..." placeholder lists, never data. Replacements arrive as one array ($scope_args), which WPCS cannot count; it holds exactly the three %i, the one %s and the two %d id lists.
				$scope_rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT t.group_id AS group_id, COUNT(DISTINCT t.id) AS matched
						FROM %i AS t
						INNER JOIN %i AS g ON g.id = t.group_id
						INNER JOIN %i AS s ON s.group_id = t.group_id
						WHERE g.type = %s AND s.object_id IN ($id_placeholders)
						AND t.language_id IN ($lang_placeholders)
						GROUP BY t.group_id",
						$scope_args
					)
				);
				// phpcs:enable

				$group_ids = [];
				$count     = 0;

				foreach ( $scope_rows as $scope_row ) {
					$group_ids[] = (int) $scope_row->group_id;
					$count      += (int) $scope_row->matched;
				}

				if ( $count > 0 ) {
					// Same three-table scope as the SELECT above, in the same
					// order, so the rows counted and the rows written cannot
					// diverge. A multi-table UPDATE assigns each matched t row
					// once however many s rows join to it, so the duplicate
					// pairs that made COUNT(*) wrong never mattered here.
					$update_args = array_merge(
						[ $tl, $tg, $tl, \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value, $post_type ],
						$ids,
						$target_lang_ids
					);

					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk status flip: no cache to read. The two interpolated variables are generated "%d,%d,..." placeholder lists, never data. Replacements arrive as one array ($update_args), which WPCS cannot count; it holds exactly the three %i, the two %s and the two %d id lists.
					$updated = $wpdb->query(
						$wpdb->prepare(
							"UPDATE %i AS t
							INNER JOIN %i AS g ON g.id = t.group_id
							INNER JOIN %i AS s ON s.group_id = t.group_id
							SET t.status = %s
							WHERE g.type = %s AND s.object_id IN ($id_placeholders)
							AND t.language_id IN ($lang_placeholders)",
							$update_args
						)
					);
					// phpcs:enable

					if ( $updated === false ) {
						// The scope matched but the write did not land. Skip the
						// cache invalidation — the cached statuses are still the
						// stored ones — and redirect to a DIFFERENT message.
						// Reporting count=0 through `bulk_marked_needs_update`
						// rendered a GREEN "0 translations flagged as needing
						// update": the identical success notice an empty
						// selection gets, over a database write that failed.
						/**
						 * Bulk flip ran but wrote no rows; listeners still see
						 * the action, with a count of zero.
						 *
						 * @hook perflocale/translations/bulk_marked_needs_update
						 */
						do_action( 'perflocale/translations/bulk_marked_needs_update', $ids, $target_lang_ids, 0 );

						$redirect( 'bulk_mark_failed' );
						break;
					}

					$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );
					foreach ( $group_ids as $gid ) {
						$repo->invalidate_group_cache( $gid );
					}
				}

				/** @hook perflocale/translations/bulk_marked_needs_update */
				do_action( 'perflocale/translations/bulk_marked_needs_update', $ids, $target_lang_ids, $count );

				$redirect( 'bulk_marked_needs_update', [ 'count' => $count ] );
				break;

			default:
				$redirect( 'bulk_unknown' );
		}
	}

	public function process_strings_po_forms(): void {
		if ( empty( $_POST['perflocale_strings_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['perflocale_strings_action'] ) );

		if ( $action === 'po_export' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_strings_po_export' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'perflocale_import_export' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

			if ( $lang === '' ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=no_lang' ) );
				exit;
			}

			// Capture the build phase in an output buffer so any stray PHP
			// notice/deprecation/warning emitted during PO serialisation
			// can't leak into the response stream BEFORE our Content-Type
			// header — which would otherwise produce a corrupt .po file
			// with HTML noise at the top.
			ob_start();
			$tmp   = wp_tempnam( 'perflocale-po-' );
			$bytes = \PerfLocale\Admin\PoSync::export_to_file( $tmp, $lang );
			ob_end_clean();

			if ( $bytes === false ) {
				wp_delete_file( $tmp );
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=export_fail' ) );
				exit;
			}

			$filename = 'perflocale-' . $lang . '-' . gmdate( 'Y-m-d-His' ) . '.po';
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . (string) (int) $bytes );
			nocache_headers();
			readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			wp_delete_file( $tmp );
			exit;
		}

		if ( $action === 'po_import' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_strings_po_import' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
			}

			if ( ! current_user_can( 'perflocale_import_export' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
			}

			$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

			if ( $lang === '' ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=missing_input' ) );
				exit;
			}

			// Same reason as the JSON importer above: an over-limit PO arrives
			// as a populated $_FILES entry with an empty tmp_name, and only
			// the `error` slot says so. Only the numeric code travels in the
			// redirect — the sentence is built on the page from
			// upload_error_message(), so nothing from the request is echoed.
			$upload_error = isset( $_FILES['po_file']['error'] ) && is_scalar( $_FILES['po_file']['error'] )
				? (int) $_FILES['po_file']['error']
				: UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_OK !== $upload_error ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'          => 'perflocale-strings',
							'po_message'    => 'upload_error',
							'po_upload_err' => $upload_error,
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			if ( empty( $_FILES['po_file']['tmp_name'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=missing_input' ) );
				exit;
			}

			$replace = ! empty( $_POST['replace_mode'] );
			// $_FILES['x']['tmp_name'] is a server-PHP-controlled path; do
			// NOT sanitize_text_field() it (could strip valid path chars).
			// is_uploaded_file() is the canonical guard for upload paths.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guarded by is_uploaded_file() below.
			$tmp_path = wp_unslash( $_FILES['po_file']['tmp_name'] );

			if ( ! is_uploaded_file( $tmp_path ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=invalid_upload' ) );
				exit;
			}

			// Reject oversized uploads BEFORE parsing — core \PO accumulates every
			// entry in memory, so a huge PO would OOM/timeout the request. Mirrors
			// the JSON-import (perflocale/import/max_file_bytes) size gate.
			$max_po_bytes = self::po_max_bytes();
			$po_size      = isset( $_FILES['po_file']['size'] )
				? (int) $_FILES['po_file']['size']
				: 0;

			if ( $po_size <= 0 ) {
				$po_size = (int) ( @filesize( $tmp_path ) ?: 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( $po_size > $max_po_bytes ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=too_large' ) );
				exit;
			}

			$result = \PerfLocale\Admin\PoSync::import_from_file( $tmp_path, $lang, $replace );

			// Pass the full breakdown via a per-user transient (not URL
			// params) so the post-import page is clean — same flash
			// pattern as the string scanner. Transient is deleted on
			// first read. Inserted/updated/unchanged/no_translation give
			// the user an honest per-row breakdown.
			set_transient(
				'perflocale_po_import_result_' . get_current_user_id(),
				[
					'imported'       => (int) $result['imported'],
					'skipped'        => (int) $result['skipped'],
					'errors'         => count( $result['errors'] ),
					'inserted'       => (int) ( $result['inserted'] ?? 0 ),
					'updated'        => (int) ( $result['updated'] ?? 0 ),
					'unchanged'      => (int) ( $result['unchanged'] ?? 0 ),
					'no_translation' => (int) ( $result['no_translation'] ?? 0 ),
					'fuzzy_skipped'  => (int) ( $result['fuzzy_skipped'] ?? 0 ),
					'total_entries'  => (int) ( $result['total_entries'] ?? 0 ),
					'lang'           => $lang,
				],
				MINUTE_IN_SECONDS * 5
			);

			wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings' ) );
			exit;
		}
	}

	/**
	 * Stream a finished `data_export` job's file as a download attachment.
	 *
	 * Bound to `admin_init`. Listens for the canonical URL
	 *   /wp-admin/admin.php?page=perflocale-jobs&perflocale_export_download=<JOB_ID>&_wpnonce=<NONCE>
	 *
	 * All five gates have to pass before a byte goes out:
	 *
	 *   1. Query arg present (otherwise no-op so this handler doesn't
	 *      touch any other admin pageload).
	 *   2. Nonce verifies the per-job download intent (`perflocale_export_download_<JOB_ID>`).
	 *   3. Caller has the same capability the export-data form required
	 *      (`manage_options`) — handlers off the Jobs page are reachable
	 *      by lower-tier translator roles, who must not pull a full
	 *      database export.
	 *   4. Job is real, type `data_export`, status `complete`, and
	 *      stores a `path` in its result.
	 *   5. Realpath of that file is INSIDE wp-content/uploads/perflocale/exports/.
	 *      Anything pointing outside is treated as malicious and
	 *      hard-rejected. After streaming, the file is unlinked so
	 *      the same URL can't replay.
	 *
	 * @return void
	 */
	public function process_export_download(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified explicitly below.
		if ( empty( $_GET['perflocale_export_download'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See nonce check below.
		$job_id = sanitize_key( wp_unslash( (string) $_GET['perflocale_export_download'] ) );

		if ( $job_id === '' ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( (string) ( $_GET['_wpnonce'] ?? '' ) ) ), 'perflocale_export_download_' . $job_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// Match the cap required by DataExportJob::get_required_capability()
		// so the dispatch + download lifecycle uses the same capability.
		// A site owner who delegates perflocale_import_export via the
		// `perflocale/roles/cap_roles` filter therefore gets a complete
		// dispatch-to-retrieval flow instead of a UX dead-end.
		if ( ! current_user_can( 'perflocale_import_export' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$job = \PerfLocale\Background\JobState::get( $job_id );

		if ( ! is_array( $job )
			|| ( $job['type'] ?? '' ) !== 'data_export'
			|| ( $job['status'] ?? '' ) !== 'complete' ) {
			wp_die( esc_html__( 'Export not ready.', 'perflocale' ), '', [ 'response' => 404 ] );
		}

		$result    = is_array( $job['result'] ?? null ) ? $job['result'] : [];
		$file_path = isset( $result['path'] ) ? (string) $result['path'] : '';

		if ( $file_path === '' || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Export file is no longer available.', 'perflocale' ), '', [ 'response' => 410 ] );
		}

		// Hard requirement: the file must live inside
		// `uploads/perflocale/exports/`. Anything outside is rejected.
		$exports_dir = \PerfLocale\Helper::uploads_exports_dir();
		$real_dir    = $exports_dir !== '' ? realpath( $exports_dir ) : false;
		$real_path   = realpath( $file_path );

		if ( $real_dir === false || $real_path === false
			|| ! str_starts_with( $real_path, rtrim( $real_dir, '/' ) . DIRECTORY_SEPARATOR )
			|| ! is_readable( $real_path ) ) {
			wp_die( esc_html__( 'Export file is no longer available.', 'perflocale' ), '', [ 'response' => 410 ] );
		}

		/**
		 * Fires after every gate (nonce, cap, job-status, realpath) has
		 * passed and the export file is about to be streamed to the
		 * operator's browser, BEFORE any HTTP header is sent.
		 *
		 * Use for audit-log entries ("user X downloaded export Y at Z"),
		 * monitoring-pipeline events, or compliance-driven hooks that
		 * need to record every export-egress event. Anything `do_action`
		 * callbacks `echo` from here would land in the response body
		 * before the JSON download — don't.
		 *
		 * @hook  perflocale/export/download/before_serve
		 * @since 1.0.0
		 *
		 * @param string             $job_id    UUID of the data_export job.
		 * @param string             $real_path Absolute path of the file
		 *                                      about to be streamed (already
		 *                                      validated inside uploads/).
		 * @param array<string,mixed> $job      The JobState row.
		 */
		do_action( 'perflocale/export/download/before_serve', $job_id, $real_path, $job );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		// sanitize_file_name() strips quotes, control chars, slashes etc. so
		// the filename cannot inject additional Content-Disposition fields.
		// The current happy-path filename (perflocale-export-{date}.json) is
		// already safe — this is defense-in-depth for any future caller that
		// dispatches DataExportJob with a custom `path` argument.
		$safe_filename = sanitize_file_name( basename( $real_path ) );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );

		$size = filesize( $real_path );
		if ( $size !== false && $size > 0 ) {
			header( 'Content-Length: ' . (int) $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a known file inside uploads/perflocale/exports/; WP_Filesystem reads the whole payload into memory which defeats streaming.
		readfile( $real_path );

		/**
		 * Fires AFTER the export file has been streamed to the browser and
		 * BEFORE the single-use deletion takes effect. The file is still
		 * readable at $real_path at this point — your callback is the last
		 * chance to act on it before it disappears.
		 *
		 * Useful for offsite-backup logic ("ship a copy to S3 every time
		 * an admin downloads"), or for chaining a post-download step
		 * (e.g. queue an Integrity-check job that re-reads the file).
		 *
		 * Don't `echo` from here — the HTTP body is already being sent.
		 *
		 * @hook  perflocale/export/download/after_serve
		 * @since 1.0.0
		 *
		 * @param string              $job_id    Job UUID.
		 * @param string              $real_path Path of the file just served
		 *                                       (still on disk until the
		 *                                       wp_delete_file() call below).
		 * @param array<string,mixed> $job       The JobState row.
		 * @param int                 $size      Size in bytes that was served
		 *                                       (0 when filesize() failed).
		 */
		do_action(
			'perflocale/export/download/after_serve',
			$job_id,
			$real_path,
			$job,
			(int) ( $size !== false ? $size : 0 )
		);

		// Single-use: drop the file so the same URL can't replay. The
		// JobState row stays so the operator can still see the job in
		// the Jobs admin page; a subsequent click on the download link
		// just gets a 410-Gone via the realpath / is_readable gate above.
		wp_delete_file( $real_path );

		exit;
	}

	public function process_string_scan(): void {
		if ( empty( $_POST['perflocale_scan'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_scan_strings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		// Route through Dispatcher: small sites (few plugins / small theme)
		// run inline so the operator gets immediate feedback. Anything
		// matching StringScanJob's `args_size` threshold (mode='all' is
		// always Big) goes async to dodge PHP-FPM timeouts on bloated
		// installs. Sync path is small by definition; async path runs
		// inside its own WP-Cron / Action Scheduler context.
		$result = \PerfLocale\Background\Dispatcher::dispatch(
			new \PerfLocale\Background\Jobs\StringScanJob(),
			[ 'mode' => 'all' ]
		);

		if ( $result['mode'] === 'async' ) {
			// Stash the confirmation message in a user-scoped transient so
			// the Jobs page can render it via admin_notices on the next
			// pageload. Keeps the URL clean; same pattern as the sync path
			// below uses for its result message.
			set_transient(
				'perflocale_scan_queued_notice_' . get_current_user_id(),
				(string) __( 'String scan queued. Track progress below.', 'perflocale' ),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=perflocale-jobs' ) );
			exit;
		}

		$r = $result['result'] ?? [];

		// Store scan results in a transient (not URL params) to keep URLs clean.
		set_transient(
			'perflocale_scan_result_' . get_current_user_id(),
			[
				'scan_new'   => (int) ( $r['scan_new'] ?? $r['inserted'] ?? 0 ),
				'scan_total' => (int) ( $r['scan_total'] ?? 0 ),
			],
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings' ) );
		exit;
	}

	/**
	 * Handle the "Create translation" action from the MetaBox.
	 *
	 * @return void
	 */
	public function handle_create_translation(): void {
		$source_id   = absint( $_GET['source_id'] ?? 0 );
		$target_lang = sanitize_key( $_GET['target_lang'] ?? '' );

		if ( $source_id === 0 || $target_lang === '' ) {
			wp_die( esc_html__( 'Invalid request.', 'perflocale' ) );
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'perflocale_create_' . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		// Check capability.
		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ) );
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$cache    = $plugin->get( 'cache' );
		$settings = $plugin->get( 'settings' );
		$manager  = new \PerfLocale\Translation\PostTranslationManager( $cache, $settings );
		$new_id   = false;

		// Auto-translate via MT if enabled — and only for languages inside the
		// mt_auto_translate_languages scope (empty = all). An out-of-scope
		// language falls through to the clean-stub branch below, which is the
		// whole point: a translator working from scratch gets an empty stub
		// instead of MT pre-fill they would have to discard.
		$auto_scope  = (array) $settings->get( 'mt_auto_translate_languages', [] );
		$mt_in_scope = ( $auto_scope === [] || in_array( $target_lang, $auto_scope, true ) );

		if ( $settings->mt_enabled() && (bool) $settings->get( 'mt_auto_translate_on_create' ) && $mt_in_scope ) {
			try {
				$mt_service = new \PerfLocale\MachineTranslation\TranslationService( $settings, $cache );
				$result     = $mt_service->translate_post( $source_id, $target_lang );
				$new_id     = $result['post_id'] ?? false;
			} catch ( \Throwable $e ) {
				// MT failed - fall back to stub creation below.
				$new_id = false;
			}
		}

		// Fall back to creating a stub. An OUT-OF-SCOPE language gets a clean
		// stub with no source copy: the translator works from scratch, and an
		// empty stub stays fillable by auto-MT if the language is added to
		// the scope later (the skip-existing guard treats non-empty content
		// as human-owned). MT-disabled / MT-failure fallbacks keep copying
		// the source — long-standing behavior that gives editors a working
		// starting point.
		if ( ! $new_id ) {
			$new_id = $manager->create_translation( $source_id, $target_lang, $mt_in_scope );
		}

		if ( $new_id === false ) {
			wp_die( esc_html__( 'Failed to create translation.', 'perflocale' ) );
		}

		// Redirect to the new post's edit screen.
		$edit_url = get_edit_post_link( $new_id, 'raw' );

		if ( ! $edit_url ) {
			wp_die( esc_html__( 'Failed to create translation.', 'perflocale' ) );
		}

		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Handle the "Create term translation" action.
	 *
	 * @return void
	 */
	public function handle_create_term_translation(): void {
		$source_id   = absint( $_GET['source_id'] ?? 0 );
		$taxonomy    = sanitize_key( $_GET['taxonomy'] ?? '' );
		$target_lang = sanitize_key( $_GET['target_lang'] ?? '' );

		if ( $source_id === 0 || $taxonomy === '' || $target_lang === '' ) {
			wp_die( esc_html__( 'Invalid request.', 'perflocale' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'perflocale_create_term_' . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'edit_term', $source_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ) );
		}

		$plugin  = \PerfLocale\Plugin::get_instance();
		$cache   = $plugin->get( 'cache' );
		$manager = new \PerfLocale\Translation\TermTranslationManager( $cache );
		$new_id  = $manager->create_translation( $source_id, $taxonomy, $target_lang, true );

		if ( $new_id === false ) {
			wp_die( esc_html__( 'Failed to create term translation.', 'perflocale' ) );
		}

		$edit_url = get_edit_term_link( $new_id, $taxonomy );

		if ( ! $edit_url ) {
			wp_die( esc_html__( 'Failed to create term translation.', 'perflocale' ) );
		}

		wp_safe_redirect( $edit_url );
		exit;
	}


	/**
	 * Handle the "Reset breaker" admin link from the Site Health
	 * circuit-breakers card. Force-closes one breaker so an operator
	 * who has manually verified the downstream is healthy can resume
	 * traffic immediately rather than waiting for the cooldown to
	 * elapse and a probe to land.
	 *
	 * Capability: `manage_options` — same gate Site Health itself uses.
	 * CSRF: per-key nonce so a stolen nonce for one breaker can't reset
	 * a different one.
	 *
	 * @return void
	 */
	public function handle_reset_breaker_action(): void {
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// Match the sanitisation Breaker::sanitize_key() applies internally
		// so the nonce + the underlying reset both target the same key.
		$key = strtolower( $key );
		$key = (string) preg_replace( '/[^a-z0-9_-]+/', '_', $key );

		if ( $key === '' ) {
			wp_die( esc_html__( 'Invalid breaker key.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset circuit breakers.', 'perflocale' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'perflocale_reset_breaker_' . $key ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		\PerfLocale\Concurrency\Breaker::reset( $key );

		// Bounce back to Site Health so the operator sees the updated
		// breaker state immediately. Fall back to dashboard if the
		// referrer isn't an admin page (e.g. direct URL hit).
		$back = wp_get_referer();
		if ( ! is_string( $back ) || $back === '' ) {
			$back = admin_url( 'site-health.php' );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'message' => 'breaker_reset',
					'key'     => rawurlencode( $key ),
				],
				$back
			)
		);
		exit;
	}

	/**
	 * Persist the inline addon-settings form from the Addons admin page.
	 *
	 * Form layout: each addon card POSTs its own form to admin-post.php
	 * with action=perflocale_save_addon_settings and a single hidden
	 * addon_id. The submitted `settings[$key]` array carries one entry
	 * per user-editable field declared in `get_settings_fields()`. Hidden
	 * + addon-managed fields are intentionally skipped so we don't clobber
	 * state the addon writes itself (e.g. WooCommerce wc_currencies).
	 *
	 * @return void
	 */
	public function handle_save_addon_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$addon_id = isset( $_POST['addon_id'] ) ? sanitize_key( wp_unslash( $_POST['addon_id'] ) ) : '';

		if ( $addon_id === '' ) {
			wp_die( esc_html__( 'Invalid addon.', 'perflocale' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_save_addon_settings_' . $addon_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'perflocale_manage_addons' ) ) {
			wp_die( esc_html__( 'You do not have permission to update addon settings.', 'perflocale' ) );
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'addon_registry' ) ) {
			wp_die( esc_html__( 'Addon registry unavailable.', 'perflocale' ) );
		}

		$registry = $plugin->get( 'addon_registry' );
		$addons   = $registry->get_addons();

		if ( ! isset( $addons[ $addon_id ] ) ) {
			wp_die( esc_html__( 'Unknown addon.', 'perflocale' ) );
		}

		$addon = $addons[ $addon_id ];
		// Third-party addons may throw from get_settings_fields() (constructor
		// errors, lazy-loaded data sources). Catch + treat as "no fields"
		// so the save handler degrades gracefully instead of fataling.
		// The render path applies the same guard for consistency.
		try {
			$fields = (array) $addon->get_settings_fields();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'PerfLocale handle_save_addon_settings: get_settings_fields() threw for "%s": %s', $addon_id, $e->getMessage() ) );
			}
			$fields = [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; each value is sanitised per-field by AddonSettings::sanitize_field() below
		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

		// Preserve existing addon-managed state (hidden fields and anything
		// not declared as user-editable) so a generic save pass doesn't wipe
		// values the addon wrote outside the form.
		$values = \PerfLocale\Addon\AddonSettings::get_addon( $addon_id );

		foreach ( $fields as $key => $field ) {
			if ( ! is_string( $key ) || $key === '' || ! is_array( $field ) ) {
				continue;
			}

			if ( ! \PerfLocale\Addon\AddonSettings::is_user_editable_field( $field ) ) {
				continue;
			}

			$raw       = $raw_settings[ $key ] ?? null;
			$sanitized = \PerfLocale\Addon\AddonSettings::sanitize_field( $field, $raw, $addon_id, $key );

			// 'custom' type without a sanitize_callback signals "leave the
			// stored value alone" so addon-managed state under the same
			// key isn't wiped by a generic save.
			if ( $sanitized !== \PerfLocale\Addon\AddonSettings::CUSTOM_NO_SANITIZE ) {
				$values[ $key ] = $sanitized;
			}
		}

		$saved = \PerfLocale\Addon\AddonSettings::set_addon( $addon_id, $values );

		$back = wp_get_referer();
		if ( ! is_string( $back ) || $back === '' ) {
			$back = admin_url( 'admin.php?page=perflocale-addons' );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					// `addon_save_failed` covers the size-cap reject + lock-contention
					// paths from AddonSettings::set_addon(); the page renders an
					// error notice instead of the success one.
					'perflocale_msg'   => $saved ? 'addon_saved' : 'addon_save_failed',
					'perflocale_addon' => rawurlencode( $addon_id ),
				],
				$back
			)
		);
		exit;
	}

	/**
	 * Map of built-in feature ids (shown as cards on the Addons admin
	 * page but NOT registered through AddonRegistry) to their underlying
	 * Settings flag. Toggling the card sets the flag, which is the
	 * canonical on/off for these features.
	 *
	 * @var array<string, string>
	 */
	private const BUILTIN_FEATURE_TOGGLES = [
		'machine-translation' => 'mt_enabled',
	];

	/**
	 * Toggle an addon's (or built-in feature's) enabled/disabled state.
	 *
	 * Two paths:
	 *   1. Built-in feature (machine-translation) → flip the underlying
	 *      Settings flag (mt_enabled). The Addons listing card's "Active"
	 *      status is computed from these flags, so the visible state follows.
	 *   2. Anything else → write to perflocale_disabled_addons via
	 *      AddonRegistry::set_disabled(), the canonical path for
	 *      registry-registered addons (including bundled ones like
	 *      WooCommerce — disabling means disabling, no safeguard).
	 *
	 * @return void
	 */
	public function handle_toggle_addon(): void {
		// Read ONLY the addon id first: it scopes the nonce action, so it must
		// be known before the nonce can be verified. Nothing is acted upon
		// until BOTH the nonce and the capability check below pass.
		$addon_id = isset( $_POST['addon_id'] ) ? sanitize_key( wp_unslash( $_POST['addon_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- id only scopes the nonce action verified immediately below; not acted upon.

		if ( $addon_id === '' ) {
			wp_die( esc_html__( 'Invalid addon.', 'perflocale' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_toggle_addon_' . $addon_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'perflocale_manage_addons' ) ) {
			wp_die( esc_html__( 'You do not have permission to toggle addons.', 'perflocale' ) );
		}

		// CSRF + capability confirmed — only now read the rest of the request.
		$desired  = isset( $_POST['disable'] ) ? (string) wp_unslash( $_POST['disable'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as string === '1' only; never output, stored raw, or used in SQL.
		$want_off = ( $desired === '1' );

		if ( isset( self::BUILTIN_FEATURE_TOGGLES[ $addon_id ] ) ) {
			// Built-in feature — flip the underlying settings flag.
			// Settings::update() takes a key-value map and runs the
			// write under its own lock; the bool return reflects
			// whether the option was committed.
			$flag    = self::BUILTIN_FEATURE_TOGGLES[ $addon_id ];
			$new_val = ! $want_off; // want_off=true → flag false (disabled)
			$toggled = $this->settings->update( [ $flag => $new_val ] );
		} else {
			$toggled = \PerfLocale\Addon\AddonRegistry::set_disabled( $addon_id, $want_off );
		}

		$back = wp_get_referer();
		if ( ! is_string( $back ) || $back === '' ) {
			$back = admin_url( 'admin.php?page=perflocale-addons' );
		}

		if ( ! $toggled ) {
			$msg = 'addon_toggle_failed';
		} else {
			$msg = $want_off ? 'addon_disabled' : 'addon_enabled';
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'perflocale_msg'   => $msg,
					'perflocale_addon' => rawurlencode( $addon_id ),
				],
				$back
			)
		);
		exit;
	}

	/**
	 * Swap WordPress front page and blog page to the new default language version.
	 *
	 * @param object $new_lang New default language object.
	 * @return void
	 */
	private function swap_front_page_to_language( object $new_lang ): void {
		$plugin  = \PerfLocale\Plugin::get_instance();
		$cache   = $plugin->get( 'cache' );
		$manager = new \PerfLocale\Translation\PostTranslationManager( $cache, $this->settings );

		// Swap page_on_front.
		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $front_page_id > 0 ) {
			$translated = $manager->get_translation_id( $front_page_id, $new_lang->slug );

			if ( $translated && $translated !== $front_page_id ) {
				update_option( 'page_on_front', $translated );
			}
		}

		// Swap page_for_posts.
		$blog_page_id = (int) get_option( 'page_for_posts' );

		if ( $blog_page_id > 0 ) {
			$translated = $manager->get_translation_id( $blog_page_id, $new_lang->slug );

			if ( $translated && $translated !== $blog_page_id ) {
				update_option( 'page_for_posts', $translated );
			}
		}
	}

	/**
	 * Build a base64 data URI for the top-level menu icon.
	 *
	 * Why a data URI: WordPress core's admin CSS recolors menu icons via
	 * the `filter` property to match the user's selected admin colour
	 * scheme (and applies hover / current-page variants automatically),
	 * but only when the icon is delivered as an inline data URI from
	 * `add_menu_page()`. A plain plugin URL (e.g. via plugins_url())
	 * skips that pipeline and renders unstyled, so hover / active states
	 * are flat. The source SVG must be solid white (#fff) for the
	 * filter to produce the correct contrast across schemes — see
	 * assets/images/icon.svg.
	 *
	 * Caching: the file is only ~7 KB but `register_menus()` runs on
	 * every admin pageload. A static memo keeps the read + base64 encode
	 * to once per request without leaking across requests (which would
	 * mask edits to icon.svg during development).
	 *
	 * Fallback: if the file can't be read for any reason (permissions,
	 * partial install) we fall back to a Dashicon so the menu is never
	 * broken — just visually generic.
	 *
	 * @return string Data URI, or 'dashicons-translation' fallback.
	 */
	private function get_menu_icon_uri(): string {
		static $memo = null;

		if ( $memo !== null ) {
			return $memo;
		}

		$path = PERFLOCALE_DIR . 'assets/images/icon.svg';

		if ( is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a small bundled plugin asset; the path is hardcoded under PERFLOCALE_DIR and never user-supplied.
			$svg = file_get_contents( $path );

			if ( is_string( $svg ) && $svg !== '' ) {
				// base64 here builds a standard image data: URI for a bundled
				// SVG icon — not code obfuscation.
				$memo = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return $memo;
			}
		}

		$memo = 'dashicons-translation';
		return $memo;
	}

	/**
	 * Register the PerfLocale admin menu and submenus.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		// Top-level menu - visible to anyone who can translate.
		$this->page_hooks['dashboard'] = add_menu_page(
			__( 'PerfLocale', 'perflocale' ),
			__( 'PerfLocale', 'perflocale' ),
			'perflocale_translate',
			'perflocale',
			[ $this, 'render_dashboard' ],
			$this->get_menu_icon_uri(),
			30
		);

		// Dashboard.
		$this->page_hooks['dashboard_sub'] = add_submenu_page(
			'perflocale',
			__( 'Dashboard', 'perflocale' ),
			__( 'Dashboard', 'perflocale' ),
			'perflocale_translate',
			'perflocale',
			[ $this, 'render_dashboard' ]
		);

		// Languages - admin only.
		$this->page_hooks['languages'] = add_submenu_page(
			'perflocale',
			__( 'Languages', 'perflocale' ),
			__( 'Languages', 'perflocale' ),
			'perflocale_manage_languages',
			'perflocale-languages',
			[ $this, 'render_languages' ]
		);

		// String Translation - editors and above.
		$this->page_hooks['strings'] = add_submenu_page(
			'perflocale',
			__( 'Strings', 'perflocale' ),
			__( 'Strings', 'perflocale' ),
			'perflocale_manage_translations',
			'perflocale-strings',
			[ $this, 'render_strings' ]
		);

		// Translations - filterable content list with per-language status.
		$this->page_hooks['translations'] = add_submenu_page(
			'perflocale',
			__( 'Translations', 'perflocale' ),
			__( 'Translations', 'perflocale' ),
			'perflocale_translate',
			'perflocale-translations',
			[ $this, 'render_translations' ]
		);

		// Addons - admin only.
		$this->page_hooks['addons'] = add_submenu_page(
			'perflocale',
			__( 'Addons', 'perflocale' ),
			__( 'Addons', 'perflocale' ),
			'perflocale_manage_addons',
			'perflocale-addons',
			[ $this, 'render_addons' ]
		);

		// Background Jobs — anyone with translate cap can view their own
		// jobs; supervisors can manage everyone's. JobsController enforces
		// per-row permissions on cancel/retry/delete.
		$this->page_hooks['jobs'] = add_submenu_page(
			'perflocale',
			__( 'Background Jobs', 'perflocale' ),
			__( 'Jobs', 'perflocale' ),
			'perflocale_translate',
			'perflocale-jobs',
			[ $this, 'render_jobs' ]
		);

		// Settings - admin only (last item).
		$this->page_hooks['settings'] = add_submenu_page(
			'perflocale',
			__( 'Settings', 'perflocale' ),
			__( 'Settings', 'perflocale' ),
			'manage_options',
			'perflocale-settings',
			[ $this, 'render_settings' ]
		);
	}

	/**
	 * Render the Dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		$page = new Pages\DashboardPage( $this->settings );
		$page->render();
	}

	/**
	 * Render the Languages page.
	 *
	 * @return void
	 */
	public function render_languages(): void {
		$page = new Pages\LanguagesPage();
		$page->render();
	}

	/**
	 * Render the Strings page.
	 *
	 * @return void
	 */
	public function render_strings(): void {
		$page = new Pages\StringsPage();
		$page->render();
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		$page = new Pages\SettingsPage( $this->settings );
		$page->render();
	}

	/**
	 * Render an admin page.
	 *
	 * @return void
	 */
	public function render_addons(): void {
		$page = new Pages\AddonsPage();
		$page->render();
	}

	/**
	 * Render the Background Jobs page.
	 *
	 * @return void
	 */
	public function render_jobs(): void {
		$page = new Pages\JobsPage();
		$page->render();
	}

	/**
	 * Register the network-admin "PerfLocale" menu on multisite.
	 *
	 * Only the Jobs overview is exposed here — the rest of PerfLocale
	 * (settings, languages, translations) is per-site by design and
	 * has nothing to aggregate at the network level. Capability is
	 * `manage_network_options` so super-admins (and anything granted
	 * that cap explicitly) see it; regular Site Admins do not.
	 *
	 * @return void
	 */
	public function register_network_menu(): void {
		add_menu_page(
			__( 'PerfLocale', 'perflocale' ),
			__( 'PerfLocale', 'perflocale' ),
			'manage_network_options',
			'perflocale-network-jobs',
			[ $this, 'render_network_jobs' ],
			'dashicons-translation',
			50
		);
	}

	/**
	 * Render the network-admin Jobs overview.
	 *
	 * Aggregates per-site state in one pass: switches to each blog,
	 * reads the active-jobs index + the last-run timestamps recorded
	 * by `BackgroundEvents::record_run()`, and tabulates them with
	 * direct links to the per-site Jobs page.
	 *
	 * Scoped to the network being administered, and capped at the
	 * first 100 of its sites to keep the load manageable on very large
	 * networks - super-admins on bigger networks should use the
	 * per-site Jobs page directly.
	 *
	 * @return void
	 */
	public function render_network_jobs(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perflocale' ) );
		}

		$limit = 100;

		// `network_id` is not optional here. WP_Site_Query defaults it to 0,
		// which emits NO `site_id` predicate at all — on a multi-network
		// install this page would list, switch_to_blog() into, and link to
		// sites belonging to networks the current Network Admin screen has
		// nothing to do with. Every other lookup on this screen (options,
		// jobs, admin URLs) is per-blog, so the only thing that scopes the
		// page is the query itself.
		$sites = get_sites(
			[
				'network_id' => get_current_network_id(),
				'number'     => $limit,
				'orderby'    => 'id',
				'order'      => 'ASC',
			]
		);

		// Only when the cap was actually hit: one extra COUNT so the notice
		// below can say "100 of N" instead of leaving the super-admin to
		// guess how much of their network is missing from the table.
		$total_sites = count( $sites ) >= $limit
			? (int) get_sites(
				[
					'network_id' => get_current_network_id(),
					'count'      => true,
				]
			)
			: count( $sites );

		$rows = [];

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;

			switch_to_blog( $blog_id );

			try {
				$active_jobs  = (array) get_option( 'perflocale_active_jobs', [] );
				$last_runs    = (array) get_option( \PerfLocale\Background\BackgroundEvents::LAST_RUN_OPTION, [] );
				$engine_label = (string) get_option( 'perflocale_active_engine', '' );

				$pending_count = 0;
				$failed_count  = 0;

				foreach ( $active_jobs as $job ) {
					if ( ! is_array( $job ) ) {
						continue;
					}
					$status = (string) ( $job['status'] ?? '' );
					if ( in_array( $status, [ 'queued', 'running' ], true ) ) {
						++$pending_count;
					} elseif ( $status === 'failed' ) {
						++$failed_count;
					}
				}

				$rows[] = [
					'blog_id'       => $blog_id,
					'domain'        => (string) $site->domain . (string) $site->path,
					'admin_url'     => get_admin_url( $blog_id, 'admin.php?page=perflocale-jobs' ),
					'pending_count' => $pending_count,
					'failed_count'  => $failed_count,
					'engine'        => $engine_label,
					'last_lock_run' => (int) ( $last_runs['perflocale_lock_cleanup']['ran_at'] ?? 0 ),
					'last_gc_run'   => (int) ( $last_runs['perflocale_jobs_gc']['ran_at'] ?? 0 ),
				];
			} finally {
				restore_current_blog();
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PerfLocale - Network Jobs Overview', 'perflocale' ); ?></h1>
			<p class="description" style="margin-top:8px;">
				<?php
				printf(
					/* translators: %d is the number of sites listed. */
					esc_html( _n( 'Showing job state across %d site on this network.', 'Showing job state across %d sites on this network.', count( $rows ), 'perflocale' ) ),
					(int) count( $rows )
				);
				?>
				<?php if ( count( $sites ) >= $limit ) : ?>
					<br>
					<em>
						<?php
						printf(
							/* translators: 1: number of sites listed (the cap), 2: total number of sites on this network. */
							esc_html__( 'Capped at the first %1$d of %2$d sites on this network. Use the per-site Jobs page for the rest.', 'perflocale' ),
							(int) $limit,
							(int) $total_sites
						);
						?>
					</em>
				<?php endif; ?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Per-site background-job engine and health status.', 'perflocale' ); ?></caption>
				<thead>
					<tr>
						<th scope="col" style="width:6%;"><?php esc_html_e( 'ID', 'perflocale' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Site', 'perflocale' ); ?></th>
						<th scope="col" style="width:11%;"><?php esc_html_e( 'Active', 'perflocale' ); ?></th>
						<th scope="col" style="width:11%;"><?php esc_html_e( 'Failed', 'perflocale' ); ?></th>
						<th scope="col" style="width:14%;"><?php esc_html_e( 'Engine', 'perflocale' ); ?></th>
						<th scope="col" style="width:14%;"><?php esc_html_e( 'Lock cleanup', 'perflocale' ); ?></th>
						<th scope="col" style="width:14%;"><?php esc_html_e( 'Jobs GC', 'perflocale' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="7" style="text-align:center;color:#646970;padding:1.2em;">
							<?php esc_html_e( 'No sites found.', 'perflocale' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo (int) $row['blog_id']; ?></td>
							<td>
								<a href="<?php echo esc_url( $row['admin_url'] ); ?>">
									<?php echo esc_html( $row['domain'] ); ?>
								</a>
							</td>
							<td>
								<?php if ( $row['pending_count'] > 0 ) : ?>
									<strong><?php echo (int) $row['pending_count']; ?></strong>
								<?php else : ?>
									<span style="color:#646970;">0</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['failed_count'] > 0 ) : ?>
									<strong style="color:#a35a00;"><?php echo (int) $row['failed_count']; ?></strong>
								<?php else : ?>
									<span style="color:#646970;">0</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['engine'] !== '' ) : ?>
									<code style="font-size:11px;"><?php echo esc_html( $row['engine'] ); ?></code>
								<?php else : ?>
									<span style="color:#646970;">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo $row['last_lock_run'] > 0
									? esc_html( human_time_diff( $row['last_lock_run'], time() ) . ' ' . __( 'ago', 'perflocale' ) )
									: '<span style="color:#646970;">—</span>';
								?>
							</td>
							<td>
								<?php
								echo $row['last_gc_run'] > 0
									? esc_html( human_time_diff( $row['last_gc_run'], time() ) . ' ' . __( 'ago', 'perflocale' ) )
									: '<span style="color:#646970;">—</span>';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}



	/**
	 * Render the Translations page.
	 *
	 * @return void
	 */
	public function render_translations(): void {
		$page = new Pages\TranslationsPage( $this->settings );
		$page->render();
	}

	/**
	 * Process dashboard quick actions (clear cache, refresh permalinks).
	 *
	 * @return void
	 */
	public function process_dashboard_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'perflocale' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['perflocale_action'] ) ? sanitize_text_field( wp_unslash( $_GET['perflocale_action'] ) ) : '';

		if ( empty( $action ) ) {
			return;
		}

		// Canonical WP order: nonce (CSRF) first, then capability.
		// `check_admin_referer()` itself dies on failure, so we explicitly
		// re-check the cap immediately after to keep the gate symmetric.
		check_admin_referer( 'perflocale_dashboard_action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ) );
		}

		$redirect = admin_url( 'admin.php?page=perflocale' );

		if ( $action === 'clear_cache' ) {
			$plugin = \PerfLocale\Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );

			if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
				$cache->flush_all();
			}

			// Also flush WordPress object cache group.
			wp_cache_flush();

			$redirect = add_query_arg( 'perflocale_notice', 'cache_cleared', $redirect );
		} elseif ( $action === 'flush_permalinks' ) {
			flush_rewrite_rules();

			$redirect = add_query_arg( 'perflocale_notice', 'permalinks_flushed', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
