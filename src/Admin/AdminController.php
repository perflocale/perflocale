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
 * Registers the PerfLocale admin menu, delegates rendering to the page classes and handles the admin forms.
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

		// Page-specific inline CSS and JS go through the enqueue API on the `perflocale-admin` handle, never echoed from
		// render() (WordPress.org review rule).
		add_action( 'admin_enqueue_scripts', [ Pages\SettingsPage::class, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ Pages\JobsPage::class, 'enqueue_assets' ] );

		// Plugins list action links.
		add_filter(
			'plugin_action_links_' . plugin_basename( PERFLOCALE_FILE ),
			[ $this, 'filter_plugin_action_links' ]
		);

		// Plugins list row meta links.
		add_filter( 'plugin_row_meta', [ $this, 'filter_plugin_row_meta' ], 10, 2 );
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
		// Route guard ("is this our page?"), not a security check: capability and nonce come before any side effect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'perflocale-settings' ) {
			return;
		}

		// Regenerate translation files (a nonce'd GET link): nonce first, then capability.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_regenerate'] ) ) {
			check_admin_referer( 'perflocale_regenerate_translations' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$plugin    = \PerfLocale\Plugin::get_instance();
			$cache     = $plugin->get( 'cache' );
			$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
			$count     = $generator->generate_all();
			// generate_all() repairs orphaned rows first; the notice says how many were reconnected.
			$repaired = $generator->get_repaired_count();
			// Languages whose translations could not be read kept their files; the notice names them.
			$read_failed = count( $generator->get_read_failed_locales() );

			wp_safe_redirect(
				add_query_arg(
					[
						'page'              => 'perflocale-settings',
						'tab'               => 'performance',
						'files_generated'   => $count,
						'repaired'          => $repaired,
						'files_read_failed' => $read_failed,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Settings save: nonce first, then capability.
		if ( empty( $_POST['perflocale_settings_tab'] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$tab    = sanitize_key( wp_unslash( $_POST['perflocale_settings_tab'] ) );
		$page   = new Pages\SettingsPage( $this->settings );
		$values = $page->extract_tab_values( $tab );

		// wpdb refuses a too-long or invalid-UTF-8 value instead of storing it, so the notice carries the real result;
		// update() also returns false for a save that changed nothing, hence the no-op signal.
		$settings_saved = $this->settings->update( $values ) || \PerfLocale\Settings::last_update_was_noop();

		// The performance tab may change the string translation mode.
		if ( $tab === 'performance' ) {
			$plugin = \PerfLocale\Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );

			if ( ( $values['string_translation_mode'] ?? '' ) === 'files' ) {
				$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
				$generator->generate_all();
			} else {
				// Database mode: remove the generated files.
				$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
				$generator->clean_all();
			}
		}

		// Add-on sub-tabs (built-in and filtered) redirect back to the Addons tab.
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

		if ( [] !== \PerfLocale\Settings::last_update_refused_addresses() ) {
			$redirect_args['mt-address-refused'] = '1';
		}

		if ( [] !== Pages\SettingsPage::last_refused_sync_fields() ) {
			$redirect_args['sync-refused'] = rawurlencode( implode( ',', Pages\SettingsPage::last_refused_sync_fields() ) );
		}

		if ( in_array( $tab, $addon_subtabs, true ) ) {
			$redirect_args['tab']    = 'addons';
			$redirect_args['subtab'] = $tab;
		} else {
			$redirect_args['tab'] = $tab;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
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
	 * The WPML import notice's sentence (with a leading space) when the untrusted-user filters changed stored string
	 * translations; '' for 0. As the JSON import's.
	 *
	 * @param int $count Rows the filters changed.
	 * @return string
	 */
	private static function sanitized_import_note( int $count ): string {
		if ( $count <= 0 ) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: %d: number of imported rows (string translations, languages, translated slugs) the filters changed */
			_n(
				'%d imported item was changed by the filters that apply to accounts without the unfiltered_html capability (disallowed markup such as scripts is removed; other values may be reformatted the way the PerfLocale screens save them).',
				'%d imported items were changed by the filters that apply to accounts without the unfiltered_html capability (disallowed markup such as scripts is removed; other values may be reformatted the way the PerfLocale screens save them).',
				$count,
				'perflocale'
			),
			$count
		);
	}

	/**
	 * Import an uploaded PO file at the uploading user's trust level: without `unfiltered_html` the Strings screen's
	 * markup rules apply (Helper::sanitize_untrusted_string_translation()). Decided here, so WP-CLI is unaffected.
	 *
	 * @internal
	 *
	 * @param string $path    Readable PO file.
	 * @param string $lang    Target language slug.
	 * @param bool   $replace Replace mode, as PoSync::import_from_file().
	 * @return array{
	 *     imported:int,
	 *     skipped:int,
	 *     errors:array<int,string>,
	 *     inserted:int,
	 *     updated:int,
	 *     unchanged:int,
	 *     no_translation:int,
	 *     fuzzy_skipped:int,
	 *     total_entries:int,
	 *     sanitized:int,
	 *     sanitized_empty:int,
	 * } PoSync::import_from_file() result.
	 */
	public static function import_po_upload( string $path, string $lang, bool $replace ): array {
		$sanitize = current_user_can( 'unfiltered_html' )
			? null
			: [ \PerfLocale\Helper::class, 'sanitize_untrusted_string_translation' ];

		return \PerfLocale\Admin\PoSync::import_from_file( $path, $lang, $replace, $sanitize );
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
	 * The add-on data choices a form posted that this site declares (DataExporter::addon_groups() keys, plus the
	 * shared `addon_data` with `$with_shared`). The form also posts `<field>_shown`; without it the answer is null
	 * (every choice).
	 *
	 * @param string $field       POST field (a checkbox list).
	 * @param bool   $with_shared Whether the shared Add-on data choice is one of them.
	 * @return list<string>|null
	 */
	private static function posted_addon_choices( string $field, bool $with_shared ): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called by the export and import handlers after their nonce checks.
		if ( empty( $_POST[ $field . '_shown' ] ) ) {
			return null;
		}

		$posted = isset( $_POST[ $field ] ) ? Typed::strs( map_deep( wp_unslash( (array) $_POST[ $field ] ), 'sanitize_text_field' ) ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$allowed = array_keys( \PerfLocale\Admin\DataExporter::addon_groups() );

		if ( $with_shared ) {
			$allowed[] = 'addon_data';
		}

		// Exact allowed keys only: nothing posted reaches the exporter or importer unchecked.
		return array_values( array_intersect( $posted, $allowed ) );
	}

	/**
	 * Why an admin-started migration did not run ('' when it ran): `error` for an exception (another import holds the
	 * lock), `blocked` with reasons first in `errors`. Nothing was written either way.
	 *
	 * @param array<string, mixed> $dispatched Dispatcher::dispatch() result.
	 * @return string
	 */
	private static function migration_refusal( array $dispatched ): string {
		if ( ! empty( $dispatched['error'] ) ) {
			return wp_specialchars_decode( (string) $dispatched['error'], ENT_QUOTES );
		}

		$r = (array) ( $dispatched['result'] ?? [] );

		if ( empty( $r['blocked'] ) ) {
			return '';
		}

		return implode( ' ', array_slice( array_map( 'strval', (array) ( $r['errors'] ?? [] ) ), 0, 2 ) );
	}

	/**
	 * Handle the export, import and migration actions.
	 *
	 * @return void
	 */
	public function process_export_import(): void {
		// Route guard; capability and nonce per action below, nonce first.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['perflocale_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action       = sanitize_key( wp_unslash( $_POST['perflocale_action'] ) );
		$redirect_url = admin_url( 'admin.php?page=perflocale-settings&tab=export-import' );

		// Export.
		if ( $action === 'export' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_export_data' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			// The two capabilities the background export, the download and every import require.
			if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'perflocale_import_export' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$sections = isset( $_POST['export_sections'] )
				? array_map( 'sanitize_key', wp_unslash( (array) $_POST['export_sections'] ) )
				: [];

			// The ticked add-on groups this site declares; null (every group) for a post not from such a form.
			$groups = self::posted_addon_choices( 'export_addon_groups', false );

			// Sync or async decided here, not by Dispatcher::dispatch(): the sync path streams the download, where Dispatcher
			// would write to disk first. The async path goes through Dispatcher so JobState records it.
			$plugin   = \PerfLocale\Plugin::get_instance();
			$settings = $plugin->get( 'settings' );
			$job      = new \PerfLocale\Background\Jobs\DataExportJob();
			$job_args = [
				'sections'     => $sections,
				'addon_groups' => $groups,
				'path'         => '',
			];

			$refused = \PerfLocale\Background\WebLimit::refusal( $job, $job_args, 'wp perflocale export <file>' );

			if ( '' !== $refused ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			if ( $job->should_run_async( $job_args, $settings ) ) {
				$result = \PerfLocale\Background\Dispatcher::dispatch( $job, $job_args );

				if ( ( $result['mode'] ?? '' ) === 'async' ) {
					wp_safe_redirect( add_query_arg( 'perflocale_notice', 'export_queued', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
					exit;
				}

				// Dispatcher ran inline anyway (denied, never): stream below.
			}

			$exporter = new \PerfLocale\Admin\DataExporter();
			$exporter->download( $sections, $groups ); // Dies after sending the file.
		}

		// Import.
		if ( $action === 'import' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_import_data' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			// A file sent in pieces (ImportUpload): the form carries the token, the assembled copy is staged; taking it ends the
			// upload and frees its quota place.
			$upload_token   = isset( $_POST['perflocale_import_upload'] ) && is_string( $_POST['perflocale_import_upload'] ) ? sanitize_text_field( wp_unslash( $_POST['perflocale_import_upload'] ) ) : '';
			$persisted_path = '' !== $upload_token ? \PerfLocale\Admin\ImportUpload::claim( $upload_token ) : '';

			if ( null === $persisted_path ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', \PerfLocale\Admin\ImportUpload::busy_message() ) );
				exit;
			}

			if ( '' !== $upload_token && '' === $persisted_path ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'The file was not uploaded completely, or the upload has expired. Choose the file and import it again.', 'perflocale' ) ) );
				exit;
			}

			if ( '' === $persisted_path ) {
				// PHP's `error` slot is the only record of why there is no tmp_name: read it before the emptiness check, or a file
				// refused as too big would be "No file uploaded".
				$upload_error = isset( $_FILES['perflocale_import_file']['error'] ) && is_scalar( $_FILES['perflocale_import_file']['error'] )
					? (int) $_FILES['perflocale_import_file']['error']
					: UPLOAD_ERR_NO_FILE;

				if ( UPLOAD_ERR_OK !== $upload_error ) {
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', self::upload_error_message( $upload_error ) ) );
					exit;
				}

				if ( empty( $_FILES['perflocale_import_file']['tmp_name'] ) ) {
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'No file uploaded.', 'perflocale' ) ) );
					exit;
				}

				// tmp_name is a server path: is_uploaded_file() is the check, sanitize_text_field() could break it.
				$tmp_path = $_FILES['perflocale_import_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				if ( ! is_uploaded_file( $tmp_path ) ) {
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'Invalid file upload.', 'perflocale' ) ) );
					exit;
				}

				// Size check before staging (PHP's own measurement), against import_max_bytes() as the form shows it.
				$max_import_bytes = self::import_max_bytes();
				$upload_size      = isset( $_FILES['perflocale_import_file']['size'] )
					? (int) $_FILES['perflocale_import_file']['size']
					: 0;

				if ( $upload_size <= 0 || $upload_size > max( 1024 * 1024, $max_import_bytes ) ) {
					wp_safe_redirect(
						ImportNotice::url(
							$redirect_url,
							'error',
							sprintf(
								/* translators: %s: maximum allowed import file size, e.g. "50 MB" */
								__( 'Import file exceeds the maximum allowed size (%s).', 'perflocale' ),
								size_format( $max_import_bytes )
							)
						)
					);
					exit;
				}

				// Copy PHP's temp upload into uploads/perflocale/temp/ in chunks (Plugin Check refuses move_uploaded_file(), rename()
				// and copy()), so a later background run still finds it.
				$temp_dir = \PerfLocale\Helper::uploads_temp_dir();

				if ( $temp_dir === '' || ! wp_mkdir_p( $temp_dir ) ) {
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'Could not create temporary upload directory.', 'perflocale' ) ) );
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
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'Could not stage uploaded file.', 'perflocale' ) ) );
					exit;
				}

				// Every read and write checked: a full disk would leave a truncated copy reported as invalid JSON.
				$staged = true;

				while ( ! feof( $source ) ) {
					$chunk = fread( $source, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Chunked copy of fresh PHP upload tmp file.
					if ( $chunk === false ) {
						$staged = false;
						break;
					}
					if ( strlen( $chunk ) !== fwrite( $dest, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked write into wp-content/uploads scratch dir.
						$staged = false;
						break;
					}
				}
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle.
				$staged = fclose( $dest ) && $staged; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle.
				wp_delete_file( $tmp_path );

				if ( ! $staged ) {
					wp_delete_file( $persisted_path );
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'Could not stage the uploaded file: the server could not write a full copy of it (the disk may be full). Nothing was imported.', 'perflocale' ) ) );
					exit;
				}

				if ( ! file_exists( $persisted_path ) || filesize( $persisted_path ) === 0 ) {
					wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', __( 'Staged upload is empty.', 'perflocale' ) ) );
					exit;
				}
			}

			// Safety-net cleanup through the importer's own hook, in case the background run never comes.
			\PerfLocale\Background\BackgroundEvents::enqueue(
				'perflocale_cleanup_temp_import',
				[ $persisted_path ],
				DAY_IN_SECONDS
			);

			$replace = ( sanitize_key( $_POST['perflocale_import_mode'] ?? 'merge' ) === 'replace' );

			// The "this site is a copy of the source" checkbox (same post and term ids); in the job args so an async run keeps it.
			$same_ids = ! empty( $_POST['perflocale_import_same_ids'] );

			// The add-on data to import (shared and per group); null (all the file has) for a post not from such a form.
			$addon_choices = self::posted_addon_choices( 'perflocale_import_addon', true );

			// Through Dispatcher: inline below the threshold (counts on the redirect), else a job (estimated from file size).
			$import_job  = new \PerfLocale\Background\Jobs\DataImportJob();
			$import_args = [
				'file_path'         => $persisted_path,
				'replace'           => $replace,
				'allow_foreign_ids' => $same_ids,
				'addon_choices'     => $addon_choices,
			];
			$refused     = \PerfLocale\Background\WebLimit::refusal( $import_job, $import_args, 'wp perflocale import <file>' );

			if ( '' !== $refused ) {
				wp_delete_file( $persisted_path );
				\PerfLocale\Background\BackgroundEvents::unschedule( 'perflocale_cleanup_temp_import', [ $persisted_path ] );
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			$result = \PerfLocale\Background\Dispatcher::dispatch( $import_job, $import_args );

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect( add_query_arg( 'perflocale_notice', 'import_queued', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
				exit;
			}

			$r = is_array( $result['result'] ?? null ) ? $result['result'] : [];

			// Inline run done: delete the file and its safety-net event.
			if ( file_exists( $persisted_path ) ) {
				wp_delete_file( $persisted_path );
			}
			\PerfLocale\Background\BackgroundEvents::unschedule(
				'perflocale_cleanup_temp_import',
				[ $persisted_path ]
			);

			// Every outcome but async lands here; a thrown inline run, `denied` and `error` report only `error`, with no result,
			// which must not end at the success notice. After the file cleanup, as on success.
			if ( ! empty( $result['error'] ) ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', (string) $result['error'] ) );
				exit;
			}

			// Said whether or not errors were reported: the rows were stored.
			$sanitized_note = '';

			if ( (int) ( $r['sanitized'] ?? 0 ) > 0 ) {
				$sanitized_note = ' ' . sprintf(
					/* translators: %d: number of imported rows (string translations, languages, translated slugs) the filters changed */
					_n(
						'%d imported item was changed by the filters that apply to accounts without the unfiltered_html capability (disallowed markup such as scripts is removed; other values may be reformatted the way the PerfLocale screens save them).',
						'%d imported items were changed by the filters that apply to accounts without the unfiltered_html capability (disallowed markup such as scripts is removed; other values may be reformatted the way the PerfLocale screens save them).',
						(int) $r['sanitized'],
						'perflocale'
					),
					(int) $r['sanitized']
				);
			}

			// A merge import leaves the file's configuration unapplied; the importer's notice says so and points to Replace.
			$not_applied_note = isset( $r['notice'] ) && is_string( $r['notice'] ) && '' !== $r['notice'] ? ' ' . $r['notice'] : '';

			// A provider address the settings update refused is named too.
			if ( isset( $r['address_notice'] ) && is_string( $r['address_notice'] ) && '' !== $r['address_notice'] ) {
				$not_applied_note .= ' ' . $r['address_notice'];
			}

			// What the import left out, kept or changed on purpose, one sentence each.
			foreach ( (array) ( $r['notes'] ?? [] ) as $note ) {
				if ( is_string( $note ) && '' !== $note ) {
					$not_applied_note .= ' ' . $note;
				}
			}

			// rawurlencode(): translated text that may carry the file's own error text; a raw `&`, `#` or `+` would cut it.
			if ( ! empty( $r['errors'] ) ) {
				// A merge that wrote rows and hit errors says how far it got (the notice shows three errors); a refused file only
				// says why.
				$errors   = Typed::strs( $r['errors'] );
				$imported = Typed::int( $r['imported'] ?? 0 );
				$skipped  = Typed::int( $r['skipped'] ?? 0 );
				$counts   = $imported + $skipped > 0
					? sprintf(
						/* translators: 1: number of errors, 2: imported count, 3: skipped count */
						_n( 'Import finished with %1$d error (%2$d items imported, %3$d skipped):', 'Import finished with %1$d errors (%2$d items imported, %3$d skipped):', count( $errors ), 'perflocale' ),
						count( $errors ),
						$imported,
						$skipped
					) . ' '
					: '';
				$msg = $counts . implode( '; ', array_slice( $errors, 0, 3 ) ) . $sanitized_note . $not_applied_note;
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $msg ) );
			} else {
				/* translators: %1$d: imported count, %2$d: skipped count */
				$msg = sprintf( __( 'Import complete. %1$d items imported, %2$d skipped.', 'perflocale' ), (int) ( $r['imported'] ?? 0 ), (int) ( $r['skipped'] ?? 0 ) ) . $sanitized_note . $not_applied_note;
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'success', $msg ) );
			}

			exit;
		}

		// Migrate from WPML.
		if ( $action === 'migrate_wpml' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_migrate_wpml' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			// Through Dispatcher: small sites inline (results at once), large ones as a job on the Jobs screen.
			$migration = new \PerfLocale\Background\Jobs\WpmlMigrationJob();
			$refused   = \PerfLocale\Background\WebLimit::refusal( $migration, [], 'wp perflocale migrate wpml' );

			if ( '' !== $refused ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			$result = \PerfLocale\Background\Dispatcher::dispatch( $migration, [] );

			if ( $result['mode'] === 'async' ) {
				$last_progress = (int) ( $result['updated_at'] ?? 0 );

				if ( ! empty( $result['duplicate'] ) && ( $result['status'] ?? '' ) === 'running' ) {
					$queued_msg = sprintf(
						/* translators: %s: time since the import last reported progress, e.g. "3 mins" */
						__( 'A WPML import is already running (last progress %s ago); no second import was started. Track its progress under PerfLocale → Jobs.', 'perflocale' ),
						human_time_diff( $last_progress > 0 ? $last_progress : time() )
					);
				} elseif ( ! empty( $result['duplicate'] ) ) {
					$queued_msg = __( 'A WPML import is already queued; no second import was started. Track its progress under PerfLocale → Jobs.', 'perflocale' );
				} elseif ( ! empty( $result['replaced'] ) ) {
					$queued_msg = __( 'The previous WPML import stopped without finishing and was marked failed. A new WPML import was queued. Track its progress under PerfLocale → Jobs.', 'perflocale' );
				} else {
					$queued_msg = __( 'WPML migration queued. Track progress under PerfLocale → Jobs.', 'perflocale' );
				}

				// The Jobs page shows this one-shot notice (same transient as the string scan's).
				set_transient( 'perflocale_scan_queued_notice_' . get_current_user_id(), (string) $queued_msg, 60 );
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-jobs' ) );
				exit;
			}

			$refused = self::migration_refusal( $result );

			if ( $refused !== '' ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
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

			$skipped_lines = \PerfLocale\Migration\WpmlImporter::skipped_lines( $r );

			if ( $skipped_lines !== [] ) {
				$msg .= ' ' . implode( ' ', $skipped_lines );
			}

			$strings_sanitized = $r['strings_sanitized'] ?? 0;
			$msg              .= self::sanitized_import_note( is_int( $strings_sanitized ) ? $strings_sanitized : 0 );
			$strings_rejected  = $r['strings_rejected'] ?? 0;
			$rejected_note     = \PerfLocale\Migration\WpmlImporter::rejected_strings_note( is_int( $strings_rejected ) ? $strings_rejected : 0 );

			if ( $rejected_note !== '' ) {
				$msg .= ' ' . $rejected_note;
			}

			wp_safe_redirect( ImportNotice::url( $redirect_url, 'success', $msg ) );
			exit;
		}

		// Migrate from Polylang.
		if ( $action === 'migrate_polylang' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_migrate_polylang' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$migration = new \PerfLocale\Background\Jobs\PolylangMigrationJob();
			$refused   = \PerfLocale\Background\WebLimit::refusal( $migration, [], 'wp perflocale migrate polylang' );

			if ( '' !== $refused ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			$result = \PerfLocale\Background\Dispatcher::dispatch( $migration, [] );

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect( add_query_arg( 'perflocale_notice', 'polylang_queued', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
				exit;
			}

			$refused = self::migration_refusal( $result );

			if ( $refused !== '' ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			$r = $result['result'] ?? [];

			// An unfinished import is reported as the job and CLI report it, with the notice to run it again.
			if ( ! empty( $r['run_failed'] ) ) {
				$reason = $r['first_error'] ?? '';

				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', is_string( $reason ) ? $reason : '' ) );
				exit;
			}

			$msg = sprintf(
				/* translators: %1$d: number of posts imported, %2$d: number of terms imported */
				__( 'Polylang import complete. %1$d posts, %2$d terms imported.', 'perflocale' ),
				(int) ( $r['posts'] ?? 0 ),
				(int) ( $r['terms'] ?? 0 )
			);

			// Failures are counted; the run's notices are shown as they are.
			$errors = (array) ( $r['errors'] ?? [] );

			if ( $errors !== [] ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: number of errors */
					_n( '%d error occurred.', '%d errors occurred.', count( $errors ), 'perflocale' ),
					count( $errors )
				);
			}

			$notices = array_filter( (array) ( $r['notices'] ?? [] ), 'is_string' );

			if ( $notices !== [] ) {
				$msg .= ' ' . implode( ' ', $notices );
			}

			// Items read and not imported: an attention notice instead of a plain success.
			$skipped_lines = \PerfLocale\Migration\MigrationRunner::skipped_lines( 'polylang', $r );

			if ( $skipped_lines !== [] ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'warning', $msg . ' ' . implode( ' ', $skipped_lines ) ) );
				exit;
			}

			// Encoded as the attention notice is: wp_sanitize_redirect() drops an apostrophe.
			wp_safe_redirect( ImportNotice::url( $redirect_url, 'success', $msg ) );
			exit;
		}

		// Migrate from TranslatePress.
		if ( $action === 'migrate_translatepress' ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_migrate_translatepress' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$migration = new \PerfLocale\Background\Jobs\TranslatePressMigrationJob();
			$refused   = \PerfLocale\Background\WebLimit::refusal( $migration, [], 'wp perflocale migrate translatepress' );

			if ( '' !== $refused ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			$result = \PerfLocale\Background\Dispatcher::dispatch( $migration, [] );

			if ( $result['mode'] === 'async' ) {
				wp_safe_redirect( add_query_arg( 'perflocale_notice', 'translatepress_queued', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
				exit;
			}

			$refused = self::migration_refusal( $result );

			if ( $refused !== '' ) {
				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', $refused ) );
				exit;
			}

			$r = $result['result'] ?? [];

			// An unfinished import is reported as the job and CLI report it, with the notice to run it again.
			if ( ! empty( $r['run_failed'] ) ) {
				$reason = $r['first_error'] ?? '';

				wp_safe_redirect( ImportNotice::url( $redirect_url, 'error', is_string( $reason ) ? $reason : '' ) );
				exit;
			}

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

			wp_safe_redirect( ImportNotice::url( $redirect_url, 'success', $msg ) );
			exit;
		}
	}

	/**
	 * Process the language forms early, before output.
	 *
	 * @return void
	 */
	public function process_language_forms(): void {
		// Route guard; capability and nonce per branch below, nonce first.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'perflocale-languages' ) {
			return;
		}

		// set_default via GET.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'set_default' && isset( $_GET['language_id'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'perflocale_set_default_language' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'perflocale_manage_languages' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$plugin  = \PerfLocale\Plugin::get_instance();
			$cache   = $plugin->get( 'cache' );
			$repo    = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang_id = absint( $_GET['language_id'] );

			$new_lang = $repo->find( $lang_id );

			// set_default() refuses a missing or inactive target (an inactive default would resolve to null everywhere):
			// everything below runs only when the promotion landed.
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

			// The front page follows to the new default's version.
			$this->swap_front_page_to_language( $new_lang );

			// URL prefixes change with the default: flush rewrite rules.
			update_option( 'perflocale_flush_rules', 1, false );

			// Clear the language cookie so visitors detect the new default.
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

		// Delete via GET is 'confirm-delete'; 'delete' renders the read-only preview (LanguagesPage::render_delete_preview()),
		// so an old bookmark shows the preview.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'confirm-delete' && isset( $_GET['language_id'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'perflocale_delete_language' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'perflocale_manage_languages' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$plugin   = \PerfLocale\Plugin::get_instance();
			$cache    = $plugin->get( 'cache' );
			$repo     = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang_id  = absint( $_GET['language_id'] );
			$language = $repo->find( $lang_id );

			// delete() returns false after its ROLLBACK and that is reported; a missing or default language is refused with a
			// message too, not a silent re-render.
			$deletable = $language && ! $language->is_default;

			// A language with posts is refused (LanguageRepository::delete_blockers()), checked again here since content can be
			// added after the preview; a failed count refuses too.
			$blockers = $deletable ? $repo->delete_blockers( $lang_id ) : null;

			if ( $deletable && is_array( $blockers ) && $blockers['posts'] !== [] ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'        => 'perflocale-languages',
							'message'     => 'delete_blocked',
							'language_id' => $lang_id,
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$deleted = ( $deletable && is_array( $blockers ) )
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

		// Add or edit via POST.
		if ( empty( $_POST['perflocale_language_action'] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'perflocale_save_language' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'perflocale_manage_languages' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$data = [
			'slug'           => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
			'locale'         => isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '',
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'native_name'    => isset( $_POST['native_name'] ) ? sanitize_text_field( wp_unslash( $_POST['native_name'] ) ) : '',
			'flag'           => isset( $_POST['flag'] ) ? sanitize_text_field( wp_unslash( $_POST['flag'] ) ) : '',
			// The form's rtl/ltr when given, else derived from the locale (as REST and CLI), so RTL works without the preset JS.
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
			// The slug must survive sanitize_key() (no /u: a non-ASCII slug becomes ''), checked against the repository's own
			// pattern before the rename loop below commits renames and 301s; the notice names what was typed.
			if ( $data['slug'] === '' || ! preg_match( \PerfLocale\Database\Repository\LanguageRepository::SLUG_PATTERN, $data['slug'] ) ) {
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

			// Slug and locale are UNIQUE: a friendly notice instead of the raw "Duplicate entry" SQL error.
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

			// Ticked rename boxes, `perflocale_rename[old_slug] = new_slug`, unslashed and sanitize_key()'d; empty and no-op
			// pairs dropped (nonce verified above).
			$rename_payload = [];

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified earlier in this branch.
			if ( isset( $_POST['perflocale_rename'] ) && is_array( $_POST['perflocale_rename'] ) ) {
				// wp_unslash() recurses, so one call covers every key and value; each pair is sanitize_key()'d below.
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

			// New languages go to the end of the list: the column default 0 would sort them first.
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

			// The add branch's duplicate check, excluding this row: otherwise update() fails with a raw SQL error and the
			// default side effects would still run.
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
				// "Set as default" only after a confirmed save, so a failed rename cannot swap the default and front page.
				if ( ! empty( $_POST['is_default'] ) ) {
					$current_default = $repo->get_default();

					if ( ! $current_default || (int) $current_default->id !== $lang_id ) {
						// set_default() refuses an inactive target, which one submit can create (Active unticked, default ticked): the
						// promotion is reported as failed like the GET branch, and its side effects skipped; the row edit stays.
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

						// Clear the language cookie so visitors get the new default.
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

			// update() refuses a slug routing cannot express before writing anything (the other changes go with it): name it,
			// as the duplicate checks do.
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
		if ( empty( $_POST['perflocale_save_string_translations'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_string_translations' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// wp_kses_post, not sanitize_textarea_field(): originals carry markup (`<a href="%s">`, entities), which that
		// would strip with the placeholder inside, and %xx; kses keeps post-safe markup and drops <script> and on*.
		$translations = isset( $_POST['perflocale_str_trans'] ) ? map_deep( wp_unslash( $_POST['perflocale_str_trans'] ), 'wp_kses_post' ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $translations ) ) {
			return;
		}

		// Extra plural forms 2..N, [string_id][lang_id] = [form2, ...], with the same markup handling.
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
		// Cells whose write could not be attempted (group INSERT or link upsert failed), reported on the redirect.
		$link_failures = 0;

		// Every string's group_id in one query (also dedups repeated ids).
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

		// Stored translations in one query, [string_id][language_id]: the grid posts every cell, so unchanged cells are
		// skipped (no write, no needs_update reset, no kses normalisation of untouched text).
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

		// Languages written or deleted, to scope the cache flush and file regeneration.
		$touched_lang_ids = [];

		foreach ( $translations as $string_id => $lang_translations ) {
			$string_id = absint( $string_id );

			if ( ! is_array( $lang_translations ) ) {
				continue;
			}

			// No `strings` row (stale form, deleted since render): nothing to write or repair.
			if ( ! array_key_exists( $string_id, $group_map ) ) {
				continue;
			}

			$group_id = $group_map[ $string_id ];

			// group_id can be 0, gone, or a post or term group's; links under any of them would orphan or repoint that group,
			// and skipping would lose the typed text without a message. So the group is healed at the first write below.
			$needs_group = ! $group_repo->is_string_group( $group_id );

			foreach ( $lang_translations as $lang_id => $value ) {
				$lang_id = absint( $lang_id );
				// Already unslashed and sanitized by map_deep() above: another wp_unslash() would eat real backslashes.
				$value = (string) $value;

				if ( $value === '' ) {
					// Empty: remove the link, unless the string still needs a group (its unusable group_id may be a live post or term
					// group whose link this would delete). Clearing a blank cell mints no group.
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

					// Drop the stored text only when there is a row: most posted cells are blank, and an unconditional DELETE would
					// cost a write each and trigger the cache flush and regeneration below for nothing.
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

				// Touched when the text or the stored plural forms differ (compared in canonical form), so unchanged forms are no edit.
				$extra_change = false;

				if ( is_array( $row_extra ) ) {
					$posted_extra_json = \PerfLocale\Database\Repository\StringTranslationRepository::normalize_extra_forms(
						array_map( 'strval', $row_extra )
					);
					$stored_extra_json = $current_extra_map[ $string_id ][ $lang_id ] ?? null;
					$extra_change      = $posted_extra_json !== $stored_extra_json;
				}

				// Untouched cell: left alone, or the write would reset a needs_update the source tracker set and re-run wp_kses.
				if ( ! $text_change && ! $extra_change ) {
					continue;
				}

				// About to write: mint a string group and point the string at it, with repair_orphaned_translations() step 2's
				// statements; only the first written cell pays, and a failed INSERT leaves the string for the next save.
				if ( $needs_group ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$inserted = $wpdb->insert(
						$groups_table,
						[ 'type' => \PerfLocale\Enum\ObjectType::String->value ],
						[ '%s' ]
					);

					if ( ! $inserted ) {
						// No group to write under: every cell of this string is skipped, and counted.
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

				// Link the string group to the language (object_id = string id). translation_links has two unique keys; a row of
				// this string under another (dead) group is resolved inside upsert_link(), which drops it and retries.
				$link_result = $group_repo->upsert_link( $group_id, $string_id, $lang_id, 'translated', \PerfLocale\Enum\SourceType::Manual );

				if ( $link_result === false ) {
					// A hard database error: counted, so the redirect says something happened instead of losing the typed text.
					++$link_failures;
					continue;
				}

				// The text in its table (upsert on the primary key; set() keeps extra_forms), skipped when only plural forms changed.
				if ( $text_change ) {
					$st_repo->set( $string_id, $lang_id, (string) $value );
				}

				// Extra forms 2..N after set() (set_extra_forms() is an UPDATE); 3+ form languages always post them, so clearing
				// every field reaches it (normalised to NULL). Only when they changed.
				if ( $extra_change && is_array( $row_extra ) ) {
					$st_repo->set_extra_forms( $string_id, $lang_id, array_map( 'strval', $row_extra ) );
				}

				++$saved;
				$touched_lang_ids[ $lang_id ] = true;
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Flush the string cache and regenerate files, also for a delete-only save, or removed translations stay served.
		if ( ( $saved > 0 || $deleted > 0 ) && $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$all_langs = $lang_repo->get_active();

			foreach ( $all_langs as $lang ) {
				$cache->delete( "all_string_translations_{$lang->id}", 'perflocale_strings' );
			}

			// Files mode: regenerate only the touched languages. The all-language cache delete above stays broad: an add-on's
			// gettext bridge depends on it.
			if ( $this->settings->get( 'string_translation_mode' ) === 'files' ) {
				$generator = new \PerfLocale\Strings\TranslationFileGenerator( $cache );
				$generator->generate_all( $touched_lang_ids === [] ? null : array_keys( $touched_lang_ids ) );
			}

			/**
			 * Fires after the Strings screen saved string translations, so add-ons deriving state from the string tables can
			 * refresh it.
			 *
			 * @hook perflocale/strings/changed
			 *
			 * @param string $origin What changed the strings ('admin_save').
			 */
			do_action( 'perflocale/strings/changed', 'admin_save' );
		}

		// Back with the count.
		$redirect_args = [
			'page'  => 'perflocale-strings',
			'saved' => $saved,
		];

		if ( $link_failures > 0 ) {
			$redirect_args['save_failed'] = $link_failures;
		}

		// Keep the filters.
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
	 * Handle the "Translate the entire site" submit (nonce and capability checked by process_translations_bulk()).
	 *
	 * @return void
	 */
	private function process_site_translate(): void {
		if ( ! self::bulk_mt_translate_available() ) {
			wp_safe_redirect( add_query_arg( 'message', 'bulk_mt_unavailable', admin_url( 'admin.php?page=perflocale-translations' ) ) );
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

		// Only types this site translates may be targeted; anything else posted is dropped.
		$settings_for_types = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$allowed_types      = $settings_for_types->get_site_translate_post_types();
		$requested_types    = $site_post_types;
		$requested_count    = count( $site_post_types );
		$site_post_types    = array_values( array_intersect( $site_post_types, $allowed_types ) );

		if ( $site_lang_ids === [] || $site_post_types === [] ) {
			// Say why the selection was empty: a configured type whose plugin is off looks like a run with no content.
			$message = 'site_translate_empty';

			// Only the types this request asked for, so the reason points at the right type.
			$dropped = $requested_count > 0
				? array_values( array_intersect( $requested_types, $settings_for_types->get_unregistered_translatable_post_types() ) )
				: [];

			if ( $site_lang_ids !== [] && $dropped !== [] ) {
				$message = 'site_translate_unregistered';
			}

			wp_safe_redirect(
				add_query_arg(
					array_filter(
						[
							'message' => $message,
							'types'           => $message === 'site_translate_unregistered' ? implode( ',', $dropped ) : false,
						]
					),
					admin_url( 'admin.php?page=perflocale-translations' )
				)
			);
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
			wp_safe_redirect( add_query_arg( 'message', 'site_translate_denied', admin_url( 'admin.php?page=perflocale-translations' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'perflocale_notice', 'bulk_mt_queued', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
		exit;
	}

	/**
	 * Process the Translations screen's bulk actions: mt_translate (selected posts into a language or all, existing
	 * pairs skipped; inline below the Settings > Performance threshold, else a job) and mark_needs_update (the selected
	 * editable posts' links in the target languages).
	 *
	 * @return void
	 */
	public function process_translations_bulk(): void {
		if ( empty( $_POST['perflocale_translations_action'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_translations_bulk' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// Completeness before branching, for site_translate too: the form ends with `perflocale_form_end`, and PHP drops
		// input past max_input_vars in order. Refused only when the marker is missing and the request reached the limit
		// (a caller may simply not send it).
		$max_input_vars = (int) ini_get( 'max_input_vars' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$form_complete  = isset( $_POST['perflocale_form_end'] );

		if ( ! $form_complete && $max_input_vars > 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$posted_vars = count( $_POST, COUNT_RECURSIVE );

			if ( $posted_vars >= $max_input_vars ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'    => 'perflocale-translations',
							'message' => 'bulk_truncated',
							'limit'   => $max_input_vars,
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		// "Translate the entire site" posts the same nonce with action=site_translate: the SiteTranslateJob chain, never inline.
		if ( sanitize_key( wp_unslash( $_POST['perflocale_translations_action'] ) ) === 'site_translate' ) {
			$this->process_site_translate();
			return;
		}

		$bulk_action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$bulk_value  = sanitize_text_field( wp_unslash( $_POST['bulk_value'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() applied per-element on the next line.
		$ids_raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : [];
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids_raw ), fn( $i ) => $i > 0 ) );

		// Keep the filters so the redirect returns to the same view.
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

		// Target languages: "all" is every active language but the default.
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

				// Through Dispatcher: BulkTranslateJob inline below the threshold, else a job on the Jobs screen. The meta opt-in
				// is also gated by the settings, so passing it is safe.
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
					wp_safe_redirect( add_query_arg( 'perflocale_notice', 'bulk_mt_queued', admin_url( 'admin.php?page=perflocale-jobs' ) ) );
					exit;
				}

				// Refused (permission, budget, a veto) or stopped before any row: say why instead of "0 created".
				if ( in_array( $result['mode'], [ 'denied', 'error' ], true ) || isset( $result['error'] ) ) {
					set_transient( 'perflocale_bulk_mt_error_' . get_current_user_id(), (string) ( $result['error'] ?? __( 'Dispatch failed.', 'perflocale' ) ), 5 * MINUTE_IN_SECONDS );
					$redirect( 'bulk_mt_denied' );
				}

				$r           = is_array( $result['result'] ?? null ) ? $result['result'] : [];
				$created     = (int) ( $r['created'] ?? 0 );
				$skipped     = (int) ( $r['skipped'] ?? 0 );
				$failed      = (int) ( $r['failed'] ?? 0 );
				$first_error = (string) ( $r['first_error'] ?? '' );
				$no_access   = min( $skipped, max( 0, (int) ( $r['skipped_permission'] ?? 0 ) ) );
				$kept        = min( $created, max( 0, (int) ( $r['kept'] ?? 0 ) ) );

				// The first error goes in a per-user transient, not the URL (odd characters, reflection).
				if ( $first_error !== '' ) {
					set_transient( 'perflocale_bulk_mt_error_' . get_current_user_id(), $first_error, 5 * MINUTE_IN_SECONDS );
				}

				// Same for the first "Do not translate" kept-source warning.
				$first_warning = (string) ( $r['first_warning'] ?? '' );

				if ( $kept > 0 && $first_warning !== '' ) {
					set_transient( 'perflocale_bulk_mt_warning_' . get_current_user_id(), $first_warning, 5 * MINUTE_IN_SECONDS );
				}

				$redirect(
					'bulk_mt_done',
					[
						// Selection and target ('all' or a slug), reported by the perflocale.translations.bulkFinished hook.
						'posts'     => count( $ids ),
						'target'    => sanitize_key( $bulk_value ),
						'created'   => $created,
						'skipped'   => $skipped - $no_access,
						'no_access' => $no_access,
						'failed'    => $failed,
						'kept'      => $kept,
					]
				);
				break;

			case 'mark_needs_update':
				global $wpdb;

				// Only the groups of selected posts this user may edit (a selected post can be of a type they cannot edit).
				_prime_post_caches( $ids, false, false );
				$ids = array_values( array_filter( $ids, static fn( int $id ): bool => \PerfLocale\Helper::user_can_edit_object( $id ) ) );

				if ( $ids === [] ) {
					/**
					 * No selected post is editable by this user: nothing flagged, the action still fires with a count of zero.
					 *
					 * @hook perflocale/translations/bulk_marked_needs_update
					 */
					do_action( 'perflocale/translations/bulk_marked_needs_update', $ids, $target_lang_ids, 0 );

					// The redirect closure exits.
					$redirect( 'bulk_marked_needs_update', [ 'count' => 0 ] );
				}

				$tl                = \PerfLocale\Database\Schema::table( 'translation_links' );
				$tg                = \PerfLocale\Database\Schema::table( 'translation_groups' );
				$id_placeholders   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$lang_placeholders = implode( ',', array_fill( 0, count( $target_lang_ids ), '%d' ) );
				$post_type         = \PerfLocale\Enum\ObjectType::Post->value;

				// Join through group_id (the checkboxes carry source ids, whose own row is the default language the targets
				// exclude), posts only through translation_groups.type; the scope is read first for the cache invalidation and a
				// re-run-proof count. Docs: docs-ai/11-code-rationale.md, "AdminController: bulk needs_update scope".
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
					// The SELECT's scope in the same order, so rows counted and rows written match; each t row is assigned once.
					$update_args = array_merge(
						[ $tl, $tg, $tl, \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value, $post_type ],
						$ids,
						$target_lang_ids
					);

					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk status flip: no cache to read. The two interpolated variables are generated "%d,%d,..." placeholder lists, never data. Replacements arrive as one array ($update_args), which WPCS cannot count; it holds exactly the three %i, the two %s and the two %d id lists.
					if ( ! $wpdb instanceof \wpdb ) {
						$updated = false;
					} elseif ( '' === \PerfLocale\Database\Schema::touch_write_time( [ [ 't.status', "'needs_update'", 'text' ] ], 't.' ) ) {
						$updated = $wpdb->query(
							$wpdb->prepare(
								"UPDATE %i AS t
							INNER JOIN %i AS g ON g.id = t.group_id
							INNER JOIN %i AS s ON s.group_id = t.group_id
							SET t.status = %s
							WHERE g.type = %s AND s.object_id IN ($id_placeholders)
							AND t.language_id IN ($lang_placeholders)",
								$update_args
							) ?? ''
						);
					} else {
						// SQLite: with the write time MySQL's ON UPDATE CURRENT_TIMESTAMP gives (Schema::touch_write_time() written out).
						$updated = $wpdb->query(
							$wpdb->prepare(
								"UPDATE %i AS t
							INNER JOIN %i AS g ON g.id = t.group_id
							INNER JOIN %i AS s ON s.group_id = t.group_id
							SET t.status = %s, t.updated_at = CASE WHEN CAST(t.status AS BINARY) = CAST('needs_update' AS BINARY) THEN t.updated_at ELSE NOW() END
							WHERE g.type = %s AND s.object_id IN ($id_placeholders)
							AND t.language_id IN ($lang_placeholders)",
								$update_args
							) ?? ''
						);
					}
					// phpcs:enable

					if ( $updated === false ) {
						// The scope matched but the write failed: no cache invalidation, and its own error message instead of the green
						// "0 flagged" an empty selection gets.
						/**
						 * The bulk flip wrote no rows; the action still fires with a count of zero.
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
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'perflocale_import_export' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

			if ( $lang === '' ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=no_lang' ) );
				exit;
			}

			// Buffer the build so a stray notice cannot land before the Content-Type header and corrupt the .po file.
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
				wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			if ( ! current_user_can( 'perflocale_import_export' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

			if ( $lang === '' ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=missing_input' ) );
				exit;
			}

			// As for the JSON import: only the `error` slot says an over-limit file was refused. Only the numeric code travels in
			// the redirect; the page builds the sentence, so nothing from the request is echoed.
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
			// tmp_name is a server path: is_uploaded_file() is the check, sanitize_text_field() could break it.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guarded by is_uploaded_file() below.
			$tmp_path = wp_unslash( $_FILES['po_file']['tmp_name'] );

			if ( ! is_uploaded_file( $tmp_path ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings&po_message=invalid_upload' ) );
				exit;
			}

			// Size check before parsing: core's \PO holds every entry in memory (as the JSON import's gate).
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

			$result = self::import_po_upload( $tmp_path, $lang, $replace );

			// The full breakdown in a per-user transient (deleted on first read), not URL params, as the string scanner does.
			set_transient(
				'perflocale_po_import_result_' . get_current_user_id(),
				[
					'imported'        => (int) $result['imported'],
					'skipped'         => (int) $result['skipped'],
					'errors'          => count( $result['errors'] ),
					'inserted'        => (int) $result['inserted'],
					'updated'         => (int) $result['updated'],
					'unchanged'       => (int) $result['unchanged'],
					'no_translation'  => (int) $result['no_translation'],
					'fuzzy_skipped'   => (int) $result['fuzzy_skipped'],
					'total_entries'   => (int) $result['total_entries'],
					'sanitized'       => (int) $result['sanitized'],
					'sanitized_empty' => (int) $result['sanitized_empty'],
					// The first messages, so the notice says which entries failed or were partly imported.
					'error_messages'  => array_slice( array_map( 'strval', $result['errors'] ), 0, 3 ),
					'lang'            => $lang,
				],
				MINUTE_IN_SECONDS * 5
			);

			wp_safe_redirect( admin_url( 'admin.php?page=perflocale-strings' ) );
			exit;
		}
	}

	/**
	 * Stream a finished `data_export` job's file as a download (admin_init, `perflocale_export_download=<JOB_ID>`).
	 *
	 * Gates: the per-job nonce, `perflocale_import_export` (as DataExportJob requires; Jobs screen users may lack it),
	 * a complete data_export job with a `path`, and its realpath inside uploads/perflocale/exports/. Deleted after.
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

		// DataExportJob's own capability, so dispatch and download use the same one (also when delegated through
		// perflocale/roles/cap_roles).
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

		// The file must be inside uploads/perflocale/exports/.
		$exports_dir = \PerfLocale\Helper::uploads_exports_dir();
		$real_dir    = $exports_dir !== '' ? realpath( $exports_dir ) : false;
		$real_path   = realpath( $file_path );

		if ( $real_dir === false || $real_path === false
			|| ! str_starts_with( $real_path, rtrim( $real_dir, '/' ) . DIRECTORY_SEPARATOR )
			|| ! is_readable( $real_path ) ) {
			wp_die( esc_html__( 'Export file is no longer available.', 'perflocale' ), '', [ 'response' => 410 ] );
		}

		/**
		 * Fires after every gate passed and before any header is sent, as the export file is about to be streamed (audit
		 * logs, monitoring). Do not echo: it would land in the download.
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
		// sanitize_file_name() keeps the name from injecting Content-Disposition fields (a custom `path` could carry one).
		$safe_filename = sanitize_file_name( basename( $real_path ) );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );

		$size = filesize( $real_path );
		if ( $size !== false && $size > 0 ) {
			header( 'Content-Length: ' . (int) $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a known file inside uploads/perflocale/exports/; WP_Filesystem reads the whole payload into memory which defeats streaming.
		readfile( $real_path );

		/**
		 * Fires after the export file was streamed and before it is deleted: the last chance to read it (an offsite copy,
		 * a follow-up check). Do not echo: the body is being sent.
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

		// One use: the file goes, the job row stays; the link then answers 410 Gone through the gates above.
		wp_delete_file( $real_path );

		exit;
	}

	public function process_string_scan(): void {
		if ( empty( $_POST['perflocale_scan'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_scan_strings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// mode='all' always runs as a background job (StringScanJob::should_run_async()).
		$scan    = new \PerfLocale\Background\Jobs\StringScanJob();
		$refused = \PerfLocale\Background\WebLimit::refusal( $scan, [ 'mode' => 'all' ], 'wp perflocale strings scan --dir=<theme or plugin directory>' );

		if ( '' !== $refused ) {
			wp_die( esc_html( $refused ) );
		}

		$result = \PerfLocale\Background\Dispatcher::dispatch( $scan, [ 'mode' => 'all' ] );

		if ( in_array( $result['mode'], [ 'denied', 'error' ], true ) ) {
			wp_die( esc_html( (string) ( $result['error'] ?? __( 'Dispatch failed.', 'perflocale' ) ) ) );
		}

		if ( $result['mode'] === 'async' ) {
			// The confirmation in a user transient, shown on the Jobs page's next load (clean URL).
			set_transient(
				'perflocale_scan_queued_notice_' . get_current_user_id(),
				(string) __( 'String scan queued. Track progress below.', 'perflocale' ),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=perflocale-jobs' ) );
			exit;
		}

		$r = $result['result'] ?? [];

		// Scan results in a transient, not URL params.
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
	 * Handle "Create translation" from the Translations panel (the classic metabox and host plugins' panels).
	 * Docs: docs-ai/11-code-rationale.md, "AdminController: Create translation".
	 *
	 * @return void
	 */
	public function handle_create_translation(): void {
		$source_id   = absint( $_GET['source_id'] ?? 0 );
		$target_lang = sanitize_key( $_GET['target_lang'] ?? '' );

		if ( $source_id === 0 || $target_lang === '' ) {
			wp_die( esc_html__( 'Invalid request.', 'perflocale' ), '', [ 'response' => 400 ] );
		}

		// Nonce.
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'perflocale_create_' . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// As the REST create route: the capability first, then edit rights through the Helper (a `map_meta_cap => false`
		// host type answers a bare edit_post false); a host may widen edit rights, never translation rights.
		if ( ! current_user_can( 'perflocale_translate' ) || ! \PerfLocale\Helper::user_can_edit_object( $source_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$cache    = $plugin->get( 'cache' );
		$settings = $plugin->get( 'settings' );
		$manager  = new \PerfLocale\Translation\PostTranslationManager( $cache, $settings );
		$new_id   = false;

		// A live translation is opened, never created again or machine-translated.
		$existing = $manager->get_translation_id( $source_id, $target_lang );

		if ( $existing !== null && get_post( $existing ) ) {
			$new_id = (int) $existing;
		}

		// create_translation() copies from get_copy_source_id()'s post: checked too, before any provider call (0: nothing
		// would be created).
		$copy_from = $manager->get_copy_source_id( $source_id, $target_lang );

		if ( $copy_from > 0 && $copy_from !== $source_id && ! \PerfLocale\Helper::user_can_copy_translation_source( $copy_from ) ) {
			wp_die( esc_html__( 'You cannot edit the original this translation is copied from.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// The new post takes the copy source's type: that type's create capability.
		if ( $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_like( $copy_from ) ) {
			wp_die( esc_html__( 'You do not have permission to create translations of this content.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// Machine translation only for languages in mt_auto_translate_languages (empty: all); others get a clean stub.
		$auto_scope  = (array) $settings->get( 'mt_auto_translate_languages', [] );
		$mt_in_scope = ( $auto_scope === [] || in_array( $target_lang, $auto_scope, true ) );

		// Only when a translation is about to be created and no link row exists; needs the MT capability, the REST rate
		// limit, core edit_post on the source and the password filter. admit() last (it counts). A refusal gives the stub.
		$source_post   = get_post( $source_id );
		$mt_reason     = '';
		$mt_reason_own = false;
		$mt_warning    = '';

		if (
			$copy_from > 0
			&& $existing === null
			&& $settings->mt_enabled()
			&& (bool) $settings->get( 'mt_auto_translate_on_create' )
			&& $mt_in_scope
			&& current_user_can( 'perflocale_use_mt' )
			&& current_user_can( 'edit_post', $source_id )
			&& $source_post instanceof \WP_Post
		) {
			$throttled = null;

			if ( ! \PerfLocale\MachineTranslation\TranslationService::may_send_post( $source_post, 'auto_create' ) ) {
				$mt_reason = __( 'The original is password-protected, and machine translation does not send password-protected posts when a translation is created.', 'perflocale' );
			} else {
				$throttled = \PerfLocale\Translation\MtRateLimiter::admit( get_current_user_id() );
			}

			if ( $throttled instanceof \WP_Error ) {
				$mt_reason = $throttled->get_error_message();
			} elseif ( '' === $mt_reason ) {
				try {
					$mt_service = new \PerfLocale\MachineTranslation\TranslationService( $settings, $cache );
					$result     = $mt_service->translate_post( $source_id, $target_lang );
					$new_id     = $result['post_id'] ?? false;
					$mt_warning = is_string( $result['builder_warning'] ?? null ) ? $result['builder_warning'] : '';

					if ( ! $new_id ) {
						$mt_reason = __( 'The machine translation returned no translation.', 'perflocale' );
					}
				} catch ( \Throwable $e ) {
					// MT failed (save guard refusal, provider error): the stub below, with the reason shown.
					$new_id        = false;
					$mt_reason     = $e->getMessage();
					$mt_reason_own = $e instanceof \PerfLocale\MachineTranslation\NotSavedException;
				}
			}
		}

		// The stub: out-of-scope languages get it empty (auto-MT can fill it later), other fallbacks copy the source.
		if ( ! $new_id ) {
			$new_id = $manager->create_translation( $source_id, $target_lang, $mt_in_scope );
		}

		if ( $new_id === false ) {
			wp_die( esc_html__( 'Failed to create translation.', 'perflocale' ) );
		}

		if ( '' !== $mt_reason ) {
			MtCreateNotice::remember( is_numeric( $new_id ) ? (int) $new_id : 0, $mt_reason, $mt_reason_own );
		} elseif ( '' !== $mt_warning ) {
			MtCreateNotice::remember_warning( is_numeric( $new_id ) ? (int) $new_id : 0, $mt_warning );
		}

		// A translation the user may not view is never named: back to the source, whose panel shows "Translated".
		$named = is_numeric( $new_id ) ? (int) $new_id : 0;

		if ( $named !== $source_id && ! \PerfLocale\Helper::user_can_view_object( $named ) ) {
			$new_id = $source_id;
		}

		// The translation is already written: a type without its own editor has no edit URL, so fall back to the
		// Translations screen (also for a relative '?classic-editor' URL) instead of reporting a failure.
		$edit_url = ObjectLinks::edit_url( (int) $new_id );

		if ( $edit_url === '' ) {
			$edit_url = add_query_arg(
				[
					'page'                => 'perflocale-translations',
					'perflocale_created'  => (int) $new_id,
				],
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Handle "Create term translation".
	 *
	 * @return void
	 */
	public function handle_create_term_translation(): void {
		$source_id   = absint( $_GET['source_id'] ?? 0 );
		$taxonomy    = sanitize_key( $_GET['taxonomy'] ?? '' );
		$target_lang = sanitize_key( $_GET['target_lang'] ?? '' );

		if ( $source_id === 0 || $taxonomy === '' || $target_lang === '' ) {
			wp_die( esc_html__( 'Invalid request.', 'perflocale' ), '', [ 'response' => 400 ] );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'perflocale_create_term_' . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// perflocale_translate, as for posts: edit rights on the term are not translation rights.
		if ( ! current_user_can( 'perflocale_translate' ) || ! current_user_can( 'edit_term', $source_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$plugin  = \PerfLocale\Plugin::get_instance();
		$cache   = $plugin->get( 'cache' );
		$manager = new \PerfLocale\Translation\TermTranslationManager( $cache );

		// The new term is seeded from get_copy_source_id()'s term: checked too.
		$copy_from = $manager->get_copy_source_id( $source_id, $taxonomy, $target_lang );

		if ( $copy_from > 0 && $copy_from !== $source_id && ! current_user_can( 'edit_term', $copy_from ) ) {
			wp_die( esc_html__( 'You cannot edit the original this translation is copied from.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// wp_insert_term() checks no capability: the copy source taxonomy's own create rule.
		if ( $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_term_like( $copy_from ) ) {
			wp_die( esc_html__( 'You do not have permission to create translations of this content.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$new_id = $manager->create_translation( $source_id, $taxonomy, $target_lang, true );

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
	 * Handle Site Health's "Reset breaker" link: force-close one breaker after the operator checked the service, instead
	 * of waiting for the cooldown. manage_options, as Site Health; a nonce per key.
	 *
	 * @return void
	 */
	public function handle_reset_breaker_action(): void {
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// Breaker::sanitize_key()'s rule, so nonce and reset target the same key.
		$key = strtolower( $key );
		$key = (string) preg_replace( '/[^a-z0-9_-]+/', '_', $key );

		if ( $key === '' ) {
			wp_die( esc_html__( 'Invalid breaker key.', 'perflocale' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset circuit breakers.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'perflocale_reset_breaker_' . $key ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		\PerfLocale\Concurrency\Breaker::reset( $key );

		// Back to Site Health to show the new state; the dashboard when the referrer is not an admin page.
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
	 * Save an add-on card's inline settings form (action=perflocale_save_addon_settings, one addon_id,
	 * `settings[$key]` per editable field of get_settings_fields()); hidden and add-on-managed fields are left alone.
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
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'perflocale_manage_addons' ) ) {
			wp_die( esc_html__( 'You do not have permission to update addon settings.', 'perflocale' ), '', [ 'response' => 403 ] );
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
		// A third-party get_settings_fields() may throw: treated as no fields, as on render.
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

		// Keep add-on-managed state (hidden and undeclared fields) the form does not carry.
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

			// A 'custom' field without sanitize_callback means "leave the stored value".
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
					// addon_save_failed covers AddonSettings::set_addon()'s size cap and lock contention.
					'perflocale_msg'   => $saved ? 'addon_saved' : 'addon_save_failed',
					'perflocale_addon' => rawurlencode( $addon_id ),
				],
				$back
			)
		);
		exit;
	}

	/**
	 * Built-in features shown as Addons cards but not registered through AddonRegistry => their Settings flag.
	 *
	 * @var array<string, string>
	 */
	private const BUILTIN_FEATURE_TOGGLES = [
		'machine-translation' => 'mt_enabled',
	];

	/**
	 * Toggle an add-on or built-in feature: a built-in flips its Settings flag (mt_enabled), anything else goes through
	 * AddonRegistry::set_disabled(), bundled add-ons included.
	 *
	 * @return void
	 */
	public function handle_toggle_addon(): void {
		// Only the addon id first: it scopes the nonce action. Nothing is acted on before nonce and capability pass.
		$addon_id = isset( $_POST['addon_id'] ) ? sanitize_key( wp_unslash( $_POST['addon_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- id only scopes the nonce action verified immediately below; not acted upon.

		if ( $addon_id === '' ) {
			wp_die( esc_html__( 'Invalid addon.', 'perflocale' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'perflocale_toggle_addon_' . $addon_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'perflocale_manage_addons' ) ) {
			wp_die( esc_html__( 'You do not have permission to toggle addons.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// Nonce and capability passed: read the rest.
		$desired  = isset( $_POST['disable'] ) ? (string) wp_unslash( $_POST['disable'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as string === '1' only; never output, stored raw, or used in SQL.
		$want_off = ( $desired === '1' );

		if ( isset( self::BUILTIN_FEATURE_TOGGLES[ $addon_id ] ) ) {
			// A built-in: flip its flag (Settings::update() writes under its own lock and says whether it committed).
			$flag    = self::BUILTIN_FEATURE_TOGGLES[ $addon_id ];
			$new_val = ! $want_off; // want_off => flag false.
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
	 * Point the front page and the posts page at the new default language's versions.
	 *
	 * @param object $new_lang New default language object.
	 * @return void
	 */
	private function swap_front_page_to_language( object $new_lang ): void {
		$plugin  = \PerfLocale\Plugin::get_instance();
		$cache   = $plugin->get( 'cache' );
		$manager = new \PerfLocale\Translation\PostTranslationManager( $cache, $this->settings );

		// page_on_front.
		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $front_page_id > 0 ) {
			$translated = $manager->get_translation_id( $front_page_id, $new_lang->slug );

			// Only a published translation may become the front page: Generate Missing Translations creates drafts, and a draft
			// front page breaks the site. Otherwise the option stays on the old page.
			if ( $translated && $translated !== $front_page_id && get_post_status( $translated ) === 'publish' ) {
				update_option( 'page_on_front', $translated );
			}
		}

		// page_for_posts.
		$blog_page_id = (int) get_option( 'page_for_posts' );

		if ( $blog_page_id > 0 ) {
			$translated = $manager->get_translation_id( $blog_page_id, $new_lang->slug );

			// Same rule: a draft posts page is no posts page.
			if ( $translated && $translated !== $blog_page_id && get_post_status( $translated ) === 'publish' ) {
				update_option( 'page_for_posts', $translated );
			}
		}
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
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$limit = 100;

		// `network_id` is required: WP_Site_Query's default 0 adds no site_id predicate, so a multi-network install would
		// list other networks' sites.
		$sites = get_sites(
			[
				'network_id' => get_current_network_id(),
				'number'     => $limit,
				'orderby'    => 'id',
				'order'      => 'ASC',
			]
		);

		// Only when the cap was hit: one COUNT, so the notice can say "100 of N".
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
				$last_runs    = (array) get_option( \PerfLocale\Background\BackgroundEvents::LAST_RUN_OPTION, [] );
				$engine_label = (string) get_option( 'perflocale_active_engine', '' );

				// No stamped schema version: the plugin never ran there, "unknown" without a catalog query. A stamp does not prove the
				// tables exist, so tables_exist() still asks.
				$db_version = get_option( 'perflocale_db_version', 0 );
				$job_counts = is_numeric( $db_version ) && (int) $db_version > 0 && \PerfLocale\Database\Schema::tables_exist()
					? \PerfLocale\Background\JobState::count_by_status( [ 'queued', 'running', 'failed' ] )
					: null;

				// Null when the table is missing or the read failed: "unknown".
				$pending_count = null === $job_counts ? null : (int) ( $job_counts['queued'] ?? 0 ) + (int) ( $job_counts['running'] ?? 0 );
				$failed_count  = null === $job_counts ? null : (int) ( $job_counts['failed'] ?? 0 );

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
								<?php if ( null === $row['pending_count'] ) : ?>
									<em title="<?php esc_attr_e( 'The jobs table of this site could not be read.', 'perflocale' ); ?>"><?php esc_html_e( 'unknown', 'perflocale' ); ?></em>
								<?php elseif ( $row['pending_count'] > 0 ) : ?>
									<strong><?php echo (int) $row['pending_count']; ?></strong>
								<?php else : ?>
									<span style="color:#646970;">0</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null === $row['failed_count'] ) : ?>
									<em title="<?php esc_attr_e( 'The jobs table of this site could not be read.', 'perflocale' ); ?>"><?php esc_html_e( 'unknown', 'perflocale' ); ?></em>
								<?php elseif ( $row['failed_count'] > 0 ) : ?>
									<strong style="color:#a35a00;"><?php echo (int) $row['failed_count']; ?></strong>
								<?php else : ?>
									<span style="color:#646970;">0</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['engine'] !== '' ) : ?>
									<code style="font-size:11px;"><?php echo esc_html( $row['engine'] ); ?></code>
								<?php else : ?>
									<span style="color:#646970;">-</span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo $row['last_lock_run'] > 0
									? esc_html( human_time_diff( $row['last_lock_run'], time() ) . ' ' . __( 'ago', 'perflocale' ) )
									: '<span style="color:#646970;">-</span>';
								?>
							</td>
							<td>
								<?php
								echo $row['last_gc_run'] > 0
									? esc_html( human_time_diff( $row['last_gc_run'], time() ) . ' ' . __( 'ago', 'perflocale' ) )
									: '<span style="color:#646970;">-</span>';
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
	 * Process the dashboard's quick actions (clear cache, refresh permalinks).
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

		// Nonce first (check_admin_referer() dies on failure), then capability.
		check_admin_referer( 'perflocale_dashboard_action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$redirect = admin_url( 'admin.php?page=perflocale' );

		if ( $action === 'clear_cache' ) {
			$plugin = \PerfLocale\Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );

			// PerfLocale's caches only: other plugins' and WordPress's data and flush_all()'s kept state records stay.
			if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
				$cache->flush_all();
			}

			$redirect = add_query_arg( 'perflocale_notice', 'cache_cleared', $redirect );
		} elseif ( $action === 'flush_permalinks' ) {
			flush_rewrite_rules();

			$redirect = add_query_arg( 'perflocale_notice', 'permalinks_flushed', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
