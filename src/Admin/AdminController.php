<?php
/**
 * Admin controller: menus, pages and admin form handlers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Admin\ObjectLinks;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the PerfLocale admin menu and hooks, and delegates rendering to the page classes and the forms to AdminForms.
 */
final class AdminController {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Admin page hooks, for conditional script loading; false where the user lacks the menu capability, so consumers
	 * check is_string() first.
	 *
	 * @var array<string, string|false>
	 */
	private array $page_hooks = [];

	/**
	 * Form and admin-post handlers, built on first use.
	 *
	 * @var AdminForms|null
	 */
	private ?AdminForms $forms = null;

	/**
	 * Route field of each admin_init form handler: [ 'GET' or 'POST', field, value; '' means any non-empty value ].
	 * The same test as the handler's first check, so other requests never load AdminForms.
	 */
	private const FORM_ROUTES = [
		'process_settings_form'       => [ 'GET', 'page', 'perflocale-settings' ],
		'process_export_import'       => [ 'POST', 'perflocale_action', '' ],
		'process_export_download'     => [ 'GET', 'perflocale_export_download', '' ],
		'process_language_forms'      => [ 'GET', 'page', 'perflocale-languages' ],
		'process_string_scan'         => [ 'POST', 'perflocale_scan', '' ],
		'process_string_translations' => [ 'POST', 'perflocale_save_string_translations', '' ],
		'process_strings_po_forms'    => [ 'POST', 'perflocale_strings_action', '' ],
		'process_translations_bulk'   => [ 'POST', 'perflocale_translations_action', '' ],
		'process_dashboard_actions'   => [ 'GET', 'page', 'perflocale' ],
	];

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
		// Multisite: the network admin Jobs overview (active jobs and recurring task runs of every site).
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'register_network_menu' ] );
		}

		// The column visibility AJAX handler is registered in Bootstrap: this class is not loaded on AJAX.

		// Screen option save filters are in perflocale.php (earliest).

		// Forms are processed early, before output.
		add_action( 'admin_init', [ $this, 'process_settings_form' ] );
		add_action( 'admin_init', [ $this, 'process_export_import' ] );
		add_action( 'admin_init', [ $this, 'process_export_download' ] );
		add_action( 'admin_init', [ $this, 'process_language_forms' ] );
		add_action( 'admin_init', [ $this, 'process_string_scan' ] );
		add_action( 'admin_init', [ $this, 'process_string_translations' ] );
		add_action( 'admin_init', [ $this, 'process_strings_po_forms' ] );
		add_action( 'admin_init', [ $this, 'process_translations_bulk' ] );
		add_action( 'admin_init', [ $this, 'process_dashboard_actions' ] );

		// A POST PHP discarded for exceeding post_max_size reaches every handler as an empty $_POST; only this notice can
		// say what happened.
		add_action( 'admin_notices', [ $this, 'render_discarded_post_notice' ] );

		// Why a translation the Create action meant to machine-translate is an untranslated copy.
		add_action( 'admin_notices', [ $this, 'render_mt_create_notice' ] );


		// admin-post.php actions.
		add_action( 'admin_post_perflocale_create_translation', [ $this, 'handle_create_translation' ] );
		add_action( 'admin_post_perflocale_create_term_translation', [ $this, 'handle_create_term_translation' ] );
		add_action( 'admin_post_perflocale_reset_breaker', [ $this, 'handle_reset_breaker_action' ] );
		add_action( 'admin_post_perflocale_save_addon_settings', [ $this, 'handle_save_addon_settings' ] );
		add_action( 'admin_post_perflocale_toggle_addon', [ $this, 'handle_toggle_addon' ] );

		// A new WooCommerce base currency: the rate sync runs at the next tick, not at the old pair's slot.
		add_action( 'update_option_woocommerce_currency', [ $this, 'reschedule_rate_sync' ] );

		// Page-specific inline CSS and JS go through the enqueue API on the `perflocale-admin` handle, never echoed from
		// render() (WordPress.org review rule).
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_settings_assets' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_jobs_assets' ] );

		// Plugins list action links.
		add_filter(
			'plugin_action_links_' . plugin_basename( PERFLOCALE_FILE ),
			[ $this, 'filter_plugin_action_links' ]
		);

		// Plugins list row meta links.
		add_filter( 'plugin_row_meta', [ $this, 'filter_plugin_row_meta' ], 10, 2 );
	}

	/**
	 * The form and admin-post handlers.
	 *
	 * @return AdminForms
	 */
	private function forms(): AdminForms {
		return $this->forms ??= new AdminForms( $this->settings );
	}

	/**
	 * Whether this request carries the route field of an admin_init form handler (FORM_ROUTES).
	 *
	 * @param string $handler Handler method name.
	 * @return bool
	 */
	private static function routed( string $handler ): bool {
		[ $source, $field, $value ] = self::FORM_ROUTES[ $handler ];
		// phpcs:ignore WordPress.Security.NonceVerification -- Route check only; the handler verifies the nonce.
		$input = 'GET' === $source ? $_GET : $_POST;

		return '' === $value ? ! empty( $input[ $field ] ) : isset( $input[ $field ] ) && $input[ $field ] === $value;
	}

	/**
	 * Settings screen assets: the hook is checked here, so other screens never load SettingsPage.
	 *
	 * @param mixed $hook Admin page hook suffix (untyped: another plugin can fire the action with anything).
	 * @return void
	 */
	public static function enqueue_settings_assets( mixed $hook ): void {
		if ( 'perflocale_page_perflocale-settings' === $hook ) {
			Pages\SettingsPage::enqueue_assets( $hook );
		}
	}

	/**
	 * Jobs screen assets: the hook is checked here, so other screens never load JobsPage.
	 *
	 * @param mixed $hook Admin page hook suffix (untyped: another plugin can fire the action with anything).
	 * @return void
	 */
	public static function enqueue_jobs_assets( mixed $hook ): void {
		if ( 'perflocale_page_perflocale-jobs' === $hook ) {
			Pages\JobsPage::enqueue_assets( $hook );
		}
	}

	/**
	 * Reschedule the exchange-rate sync with its first run now and queue a new-base refresh, when the WooCommerce
	 * add-on is booted. The refresh is separate: a running Action Scheduler sync counts as scheduled.
	 *
	 * @return void
	 */
	public function reschedule_rate_sync(): void {
		$this->forms()->reschedule_rate_sync();
	}

	/**
	 * Add Settings and Languages links to the plugins list row (only filters on plugins.php).
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

		// Before Deactivate.
		return array_merge( $prefixed, $links );
	}

	/**
	 * Add Documentation and Support links to the plugins list row meta.
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
	 * Process the settings forms early, before output.
	 *
	 * @return void
	 */
	public function process_settings_form(): void {
		if ( self::routed( 'process_settings_form' ) ) {
			$this->forms()->process_settings_form();
		}
	}

	// Upload limits: both import forms gate uploads in code above PHP's own limits; the helpers below turn PHP's two
	// kinds of refusal into something the operator can act on.

	/**
	 * The PO importer's own ceiling above PHP's upload limits: core's \PO parser holds the whole file in memory, so it
	 * guards generous hosts. Not wp_max_upload_size(), which any plugin can move. StringsPage names the same number.
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
	 * The JSON importer's ceiling above PHP's upload limits (it reads in pieces, so this bounds staged disk and import
	 * length, not memory); one place, so the gate, the form and the export warning agree.
	 *
	 * @return int Maximum accepted import size, in bytes.
	 */
	public static function import_max_bytes(): int {
		/**
		 * Filters the largest data export JSON the Export & Import screen imports, in bytes; a file over PHP's upload
		 * limits is sent in pieces, so this is the screen's only size cap. WP-CLI does not apply it.
		 *
		 * @hook perflocale/import/max_file_bytes
		 *
		 * @param int $bytes Default 512 MB.
		 */
		return (int) apply_filters( 'perflocale/import/max_file_bytes', 512 * MB_IN_BYTES );
	}

	/**
	 * Import an uploaded PO file at the uploading user's trust level: without `unfiltered_html` the Strings screen's
	 * markup rules apply (Helper::sanitize_untrusted_string_translation()). Decided here, so WP-CLI is unaffected.
	 *
	 * @internal
	 * @param string $path    Readable PO file.
	 * @param string $lang    Target language slug.
	 * @param bool   $replace Replace mode, as PoSync::import_from_file().
	 * @return array<mixed> AdminForms::import_po_upload()'s result.
	 */
	public static function import_po_upload( string $path, string $lang, bool $replace ): array {
		return AdminForms::import_po_upload( $path, $lang, $replace );
	}

	/**
	 * A size_format() that always renders: a negative value (only a third-party `upload_size_limit` filter makes one)
	 * is clamped to zero instead of false.
	 *
	 * @param mixed $bytes Byte count, or anything the `upload_size_limit` filter returned
	 *                      (float, numeric string, or an ini string like '64M').
	 * @return string Formatted size, e.g. "2 MB".
	 */
	public static function format_bytes( mixed $bytes ): string {
		// Not typed int (04-do-not-fix): wp_max_upload_size() passes through the `upload_size_limit` filter, which can
		// return a float, a numeric string or '64M'; under strict_types a narrow type would white-screen the Strings page.
		// An ini-shaped value goes through wp_convert_hr_to_bytes().
		$normalised = is_numeric( $bytes )
			? (int) $bytes
			: (int) wp_convert_hr_to_bytes( (string) $bytes );

		return (string) size_format( max( 0, $normalised ) );
	}

	/**
	 * The upload ceiling in bytes, normalised as format_bytes() does (min() on a raw '64M' compares a string with an int).
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
	 * The reason for a PHP upload error code, one sentence per code: an over-limit file needs a smaller file or a higher
	 * limit, a missing or unwritable temp directory is a host fault. Shared by both import forms.
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
				return __( 'Only part of the file reached the server, so nothing was imported. The upload was interrupted - try again.', 'perflocale' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was received. Choose a file and submit the form again.', 'perflocale' );

			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'This server has no temporary folder for uploads, so no file can be uploaded to it at all. Ask your host to configure the PHP upload_tmp_dir setting - a smaller file will not help.', 'perflocale' );

			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded file to disk. Check the free disk space and the permissions on the PHP temporary folder - a smaller file will not help.', 'perflocale' );

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
	 * Whether PHP discarded this request's POST body for exceeding post_max_size: a POST that declared more than the
	 * limit and arrived with $_POST and $_FILES empty (nonce included). post_max_size 0 never discards; without
	 * CONTENT_LENGTH the answer is false.
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

		// Emptiness only: nothing is read from either.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- No nonce can exist: this is the branch where the body carrying it was discarded.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return false;
		}

		$declared = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$limit    = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		return $declared > 0 && $limit > 0 && $declared > $limit;
	}

	/**
	 * `admin_notices`: the Create action's machine translation notice (MtCreateNotice::render_admin_notice()), only on
	 * a classic-editor post.php or a URL with `perflocale_created`, and only when one waits for the user; other
	 * screens return before MtCreateNotice loads.
	 *
	 * @return void
	 */
	public function render_mt_create_notice(): void {
		$post_id = 0;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only: the id selects the current user's own record, which MtCreateNotice checks against edit_post.
		if ( 'post.php' === ( $GLOBALS['pagenow'] ?? '' ) && isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( $screen instanceof \WP_Screen && $screen->is_block_editor() ) {
				return;
			}

			$post_id = absint( $_GET['post'] );
		} elseif ( isset( $_GET['perflocale_created'] ) && is_numeric( $_GET['perflocale_created'] ) ) {
			$post_id = absint( $_GET['perflocale_created'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! self::mt_create_notice_waiting( $post_id ) ) {
			return;
		}

		MtCreateNotice::render_admin_notice();
	}

	/**
	 * Whether a machine translation notice waits for the current user on this post: the user meta
	 * `perflocale_mt_notice_<blog>_<post>` (MtCreateNotice::WAITING_META_PREFIX, written out so that class stays
	 * unloaded), from the user's meta cache, so a screen without one runs no query.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function mt_create_notice_waiting( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		$until = get_user_meta( $user_id, 'perflocale_mt_notice_' . get_current_blog_id() . '_' . $post_id, true );

		return is_numeric( $until ) && (int) $until >= time();
	}

	/**
	 * Tell the operator when PHP discarded their upload before WordPress saw it, on this plugin's screens only.
	 *
	 * Runs where no nonce can be checked (it was in the discarded body), so it is inert: it reads only emptiness,
	 * echoes a fixed sentence, writes nothing; `$_GET['page']` is compared, never printed. One comparison per load.
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
					__( 'That submission was too large for this server, so PHP discarded it before WordPress saw it - nothing was uploaded, imported or changed. This server accepts at most %1$s per file and %2$s per submission. Use a smaller file, or ask your host to raise the PHP upload_max_filesize and post_max_size settings.', 'perflocale' ),
					self::format_bytes( self::max_upload_bytes() ),
					self::format_bytes( wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) )
				)
			)
		);
	}

	/**
	 * Handle the export, import and migration actions.
	 *
	 * @return void
	 */
	public function process_export_import(): void {
		if ( self::routed( 'process_export_import' ) ) {
			$this->forms()->process_export_import();
		}
	}

	/**
	 * Process the language forms early, before output.
	 *
	 * @return void
	 */
	public function process_language_forms(): void {
		if ( self::routed( 'process_language_forms' ) ) {
			$this->forms()->process_language_forms();
		}
	}

	/**
	 * Register the Screen Options of the Strings and Translations screens.
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

		// Language column checkboxes on the Strings screen.
		if ( isset( $this->page_hooks['strings'] ) ) {
			add_filter( 'screen_settings', [ $this, 'render_strings_column_options' ], 10, 2 );
		}

		// The same on the Translations screen.
		if ( isset( $this->page_hooks['translations'] ) ) {
			add_filter( 'screen_settings', [ $this, 'render_translations_column_options' ], 10, 2 );
		}

		// The save filters are registered in register_hooks(), before set_screen_options().
	}

	/**
	 * The Strings screen's language column checkboxes in Screen Options.
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

		$settings .= '<fieldset class="metabox-prefs" data-perflocale-col-action="perflocale_save_hidden_columns" data-perflocale-col-nonce="' . esc_attr( wp_create_nonce( 'perflocale_hidden_columns' ) ) . '">';
		$settings .= '<legend>' . esc_html__( 'Language Columns', 'perflocale' ) . '</legend>';

		foreach ( $languages as $lang ) {
			$checked   = ! in_array( $lang->slug, $hidden, true ) ? ' checked="checked"' : '';
			$settings .= '<label>';
			$settings .= '<input class="perflocale-col-toggle" type="checkbox" value="' . esc_attr( $lang->slug ) . '"' . $checked . '>';
			$settings .= esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . $lang->name );
			$settings .= '</label>';
		}

		// Wired by admin-actions.js (on every PerfLocale screen).
		$settings .= '</fieldset>';

		return $settings;
	}

	/**
	 * The Translations screen's language column checkboxes, stored under their own user meta key.
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
	 * The language column fieldset of both screens, each with its own nonce action so they cannot cross-submit.
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

		$settings .= '<fieldset class="metabox-prefs" data-perflocale-col-action="perflocale_save_hidden_columns_generic" data-perflocale-col-nonce="' . esc_attr( wp_create_nonce( $nonce_slug ) ) . '">';
		$settings .= '<legend>' . esc_html__( 'Language Columns', 'perflocale' ) . '</legend>';

		foreach ( $languages as $lang ) {
			$checked   = ! in_array( $lang->slug, $hidden, true ) ? ' checked="checked"' : '';
			$settings .= '<label>';
			$settings .= '<input class="perflocale-col-toggle" data-meta-key="' . esc_attr( $meta_key ) . '" type="checkbox" value="' . esc_attr( $lang->slug ) . '"' . $checked . '>';
			$settings .= esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . $lang->name );
			$settings .= '</label>';
		}

		// admin-actions.js sends each box's data-meta-key, so ajax_save_hidden_columns_generic() stores into this screen's key.
		$settings .= '</fieldset>';

		return $settings;
	}

	/**
	 * Process the Strings grid's inline translation saves.
	 *
	 * @return void
	 */
	public function process_string_translations(): void {
		if ( self::routed( 'process_string_translations' ) ) {
			$this->forms()->process_string_translations();
		}
	}


	/**
	 * Whether the current user may run bulk machine translation now: they manage
	 * translations (as the bulk handlers require) and the active provider is configured.
	 *
	 * @return bool
	 */
	public static function bulk_mt_translate_available(): bool {
		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			return false;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return false;
		}

		$settings = $plugin->get( 'settings' );

		// The master switch first: with machine translation off the REST routes refuse, so the admin must not offer it.
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
	 * Process the Translations screen's bulk actions: mt_translate (selected posts into a language or all, existing
	 * pairs skipped; inline below the Settings > Performance threshold, else a job) and mark_needs_update (the selected
	 * editable posts' links in the target languages).
	 *
	 * @return void
	 */
	public function process_translations_bulk(): void {
		if ( self::routed( 'process_translations_bulk' ) ) {
			$this->forms()->process_translations_bulk();
		}
	}

	public function process_strings_po_forms(): void {
		if ( self::routed( 'process_strings_po_forms' ) ) {
			$this->forms()->process_strings_po_forms();
		}
	}

	/**
	 * Stream a finished `data_export` job's file as a download (admin_init, `perflocale_export_download=<JOB_ID>`).
	 *
	 * @return void
	 */
	public function process_export_download(): void {
		if ( self::routed( 'process_export_download' ) ) {
			$this->forms()->process_export_download();
		}
	}

	public function process_string_scan(): void {
		if ( self::routed( 'process_string_scan' ) ) {
			$this->forms()->process_string_scan();
		}
	}

	/**
	 * Handle "Create translation" from the Translations panel (the classic metabox and host plugins' panels).
	 * Docs: docs-ai/11-code-rationale.md, "AdminController: Create translation".
	 *
	 * @return void
	 */
	public function handle_create_translation(): void {
		$this->forms()->handle_create_translation();
	}

	/**
	 * Handle "Create term translation".
	 *
	 * @return void
	 */
	public function handle_create_term_translation(): void {
		$this->forms()->handle_create_term_translation();
	}


	/**
	 * Handle Site Health's "Reset breaker" link: force-close one breaker after the operator checked the service, instead
	 * of waiting for the cooldown. manage_options, as Site Health; a nonce per key.
	 *
	 * @return void
	 */
	public function handle_reset_breaker_action(): void {
		$this->forms()->handle_reset_breaker_action();
	}

	/**
	 * Save an add-on card's inline settings form (action=perflocale_save_addon_settings, one addon_id,
	 * `settings[$key]` per editable field of get_settings_fields()); hidden and add-on-managed fields are left alone.
	 *
	 * @return void
	 */
	public function handle_save_addon_settings(): void {
		$this->forms()->handle_save_addon_settings();
	}

	/**
	 * Toggle an add-on or built-in feature: a built-in flips its Settings flag (mt_enabled), anything else goes through
	 * AddonRegistry::set_disabled(), bundled add-ons included.
	 *
	 * @return void
	 */
	public function handle_toggle_addon(): void {
		$this->forms()->handle_toggle_addon();
	}

	/**
	 * The top-level menu icon as a base64 data URI: only then does core's admin CSS recolour it per colour scheme (the
	 * SVG is solid white). Read once per request; a Dashicon when the file cannot be read.
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
				// A standard image data: URI for a bundled icon, not obfuscation.
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
		// Top level: anyone who can translate.
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

		// Languages: admins.
		$this->page_hooks['languages'] = add_submenu_page(
			'perflocale',
			__( 'Languages', 'perflocale' ),
			__( 'Languages', 'perflocale' ),
			'perflocale_manage_languages',
			'perflocale-languages',
			[ $this, 'render_languages' ]
		);

		// String Translation: editors and above.
		$this->page_hooks['strings'] = add_submenu_page(
			'perflocale',
			__( 'Strings', 'perflocale' ),
			__( 'Strings', 'perflocale' ),
			'perflocale_manage_translations',
			'perflocale-strings',
			[ $this, 'render_strings' ]
		);

		// Translations: the content list with per-language status.
		$this->page_hooks['translations'] = add_submenu_page(
			'perflocale',
			__( 'Translations', 'perflocale' ),
			__( 'Translations', 'perflocale' ),
			'perflocale_translate',
			'perflocale-translations',
			[ $this, 'render_translations' ]
		);

		// Addons: admins.
		$this->page_hooks['addons'] = add_submenu_page(
			'perflocale',
			__( 'Addons', 'perflocale' ),
			__( 'Addons', 'perflocale' ),
			'perflocale_manage_addons',
			'perflocale-addons',
			[ $this, 'render_addons' ]
		);

		// Jobs: translators see their own, supervisors everyone's; JobsController checks each row's actions.
		$this->page_hooks['jobs'] = add_submenu_page(
			'perflocale',
			__( 'Background Jobs', 'perflocale' ),
			__( 'Jobs', 'perflocale' ),
			'perflocale_translate',
			'perflocale-jobs',
			[ $this, 'render_jobs' ]
		);

		// Settings: admins, last.
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
	 * Render the Addons page.
	 *
	 * @return void
	 */
	public function render_addons(): void {
		$page = new Pages\AddonsPage();
		$page->render();
	}

	/**
	 * Render the Jobs page.
	 *
	 * @return void
	 */
	public function render_jobs(): void {
		$page = new Pages\JobsPage();
		$page->render();
	}

	/**
	 * Register the network admin PerfLocale menu (multisite): the Jobs overview only, the rest is per site.
	 * `manage_network_options`, so super admins see it.
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
	 * Render the network Jobs overview: per site the queued, running and failed counts and the recurring runs
	 * (BackgroundEvents::record_run()), linked to its Jobs page; "unknown", never 0, without a readable table. This
	 * network's first 100 sites.
	 *
	 * @return void
	 */
	public function render_network_jobs(): void {
		( new NetworkJobsPage() )->render_network_jobs();
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
	 * Process the dashboard's quick actions (clear cache, refresh permalinks).
	 *
	 * @return void
	 */
	public function process_dashboard_actions(): void {
		if ( self::routed( 'process_dashboard_actions' ) ) {
			$this->forms()->process_dashboard_actions();
		}
	}
}
