<?php
/**
 * String translations admin page with inline editing and modal editor.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Admin\AdminController;
use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\TranslationLinkRepository;
use PerfLocale\Helper;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the string translations admin page with inline editing.
 */
final class StringsPage {

	/**
	 * Controls the save form posts that are NOT one of the per-cell inputs:
	 * `_wpnonce`, `_wp_http_referer`, the `perflocale_save_string_translations`
	 * flag and the three filter carriers (`domain_filter`, `search`, `paged`)
	 * — seven with the submit button, which posts into the form through its
	 * `form` attribute. The remainder is slack: PHP's own cut-off is not exact
	 * (a 1000-var limit was measured delivering 1001 names on PHP 8.2), and a
	 * reserve costing 1.6% of a stock host's budget is cheaper than being one
	 * variable over.
	 *
	 * @var int
	 */
	private const SAVE_FORM_RESERVED_INPUTS = 16;

	/**
	 * Preloaded plural extra_forms per "string_id_language_id" — populated
	 * by preload_translation_texts() so the grid renders the extra plural
	 * inputs without a per-cell query.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $extra_map = [];

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		$plugin       = Plugin::get_instance();
		$cache        = $plugin->get( 'cache' );
		$settings     = $plugin->get( 'settings' );
		$string_repo  = new StringRepository( $cache );
		$lang_repo    = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$hidden_langs = (array) get_user_meta( get_current_user_id(), 'perflocale_strings_hidden_langs', true );
		$languages    = $lang_repo->get_active();

		// Bulk MT-translate toolbar opt-in: master MT switch on + bulk
		// kill-switch on (Settings → Addons → Machine Translation → Bulk MT for
		// Strings) + user has the `perflocale_use_mt` cap + there's at
		// least one non-default language to translate INTO.
		$mt_default_lang = $lang_repo->get_default();
		$mt_non_default  = array_values( array_filter( $languages, static fn( $l ) => empty( $l->is_default ) ) );
		$mt_show_toolbar = $settings->mt_enabled()
			&& $settings->mt_bulk_strings_enabled()
			&& current_user_can( 'perflocale_use_mt' )
			&& $mt_default_lang !== null
			&& $mt_non_default !== [];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$domain      = isset( $_GET['domain_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['domain_filter'] ) ) : '';
		$context     = isset( $_GET['context_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['context_filter'] ) ) : '';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$search_mode = isset( $_GET['search_mode'] ) ? sanitize_key( $_GET['search_mode'] ) : 'contains';
		$status      = isset( $_GET['status_filter'] ) ? sanitize_key( $_GET['status_filter'] ) : '';
		// Canonical language filter - the slug form (`?perflocale_lang=de`)
		// used by the WP admin-bar switcher and every other list page in
		// the plugin. Resolved to an internal language ID for the repo.
		$lang_slug_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';
		$lang_filter      = 0;

		if ( $lang_slug_filter !== '' ) {
			$resolved    = $lang_repo->find_by_slug( $lang_slug_filter );
			$lang_filter = $resolved ? (int) $resolved->id : 0;
		}
		$page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// Scan results are passed via a transient (not URL params) to keep pagination URLs clean.
		$scan_result = get_transient( 'perflocale_scan_result_' . get_current_user_id() );
		$scan_new    = is_array( $scan_result ) ? absint( $scan_result['scan_new'] ?? 0 ) : -1;
		$scan_total  = is_array( $scan_result ) ? absint( $scan_result['scan_total'] ?? 0 ) : 0;

		if ( $scan_result ) {
			delete_transient( 'perflocale_scan_result_' . get_current_user_id() );
		}
		$saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0;
		// Cells the save handler could not write (group INSERT / link upsert
		// failed). Without this the grid re-renders showing the stored value
		// and the typed translation is simply gone, with no message at all.
		$save_failed = isset( $_GET['save_failed'] ) ? absint( $_GET['save_failed'] ) : 0;

		$allowed_modes = [ 'contains', 'not_contains', 'exact', 'starts_with', 'not_starts_with', 'ends_with', 'not_ends_with' ];
		if ( ! in_array( $search_mode, $allowed_modes, true ) ) {
			$search_mode = 'contains';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Screen Options accepts up to 999 rows, and the save form below posts
		// one input per (row × language): 999 rows over five languages is 5,016
		// controls in ONE POST. PHP stops parsing at `max_input_vars` — 1000 on
		// a stock host — and silently drops everything past the cut-off. The
		// nonce, the action flag and the filter carriers are first in the form
		// so they always survive; the save therefore runs, reports success and
		// discards every edit that landed in the truncated tail. Nothing
		// downstream can recover it: by the time admin_init sees the POST the
		// dropped inputs no longer exist. Bounding the page size here is the
		// only point at which that is preventable.
		$per_page = $this->cap_per_page_to_input_vars( $this->get_per_page(), $languages );
		$offset   = ( $page_num - 1 ) * $per_page;

		$filter_args = [
			'domain'           => $domain,
			'context'          => $context,
			'search'           => $search,
			'search_mode'      => $search_mode,
			'status'           => $status,
			'language_id'      => $lang_filter,
			// for every other status (ignored by the repo unless status
			// matches).
		];

		$total_items = $string_repo->count( $filter_args );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

		$strings = $string_repo->find_all(
			array_merge(
				$filter_args,
				[
					'limit'  => $per_page,
					'offset' => $offset,
				]
			)
		);

		$link_repo       = new TranslationLinkRepository( $cache );
		$translation_map = $this->preload_translations( $strings, $languages, $link_repo );
		$text_map        = $this->preload_translation_texts( $strings, $languages );
		$mo_hints        = $this->get_mo_translation_hints( $strings, $languages );
		$domains         = $this->get_available_domains( $cache );
		$contexts        = $this->get_available_contexts( $cache );
		$base_url        = admin_url( 'admin.php?page=perflocale-strings' );

		// Build sort URLs - preserve all active filters.
		$sort_args = [ 'page' => 'perflocale-strings' ];

		if ( $domain !== '' ) {
			$sort_args['domain_filter'] = $domain;
		}

		if ( $search !== '' ) {
			$sort_args['s'] = $search;

			if ( $search_mode !== 'contains' ) {
				$sort_args['search_mode'] = $search_mode;
			}
		}

		if ( $status !== '' ) {
			$sort_args['status_filter'] = $status;
		}

		if ( $lang_slug_filter !== '' ) {
			$sort_args['perflocale_lang'] = $lang_slug_filter;
		}

		// PO export/import live next to the page heading (alongside the
		// Documentation link) instead of in the per-row toolbar — they're
		// page-level actions, not per-result actions, and this matches
		// WP-admin convention for top-of-page actions. Same non-default
		// list the MT toolbar uses above.
		$non_default_langs = $mt_non_default;

		// The ceiling an operator will actually hit, named on the import form
		// so the limit is visible BEFORE a doomed upload rather than only
		// after it. Two ceilings apply and the smaller one binds: what PHP
		// will accept (`wp_max_upload_size()`, the one that binds on every
		// stock host) and what the importer will parse
		// (AdminController::po_max_bytes()). One admin-page-only call to each;
		// nothing on the front end.
		$po_upload_ceiling = AdminController::format_bytes(
			min( AdminController::max_upload_bytes(), AdminController::po_max_bytes() )
		);

		?>
		<div class="wrap perflocale-strings-page">
			<div class="perflocale-page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding-top:12px;">
				<h1 class="wp-heading-inline" style="margin:0;padding:0;line-height:1;"><?php echo esc_html__( 'String Translations', 'perflocale' ); ?></h1>

				<?php if ( ! empty( $non_default_langs ) ) : ?>
					<details class="perflocale-page-action" data-perflocale-popover style="position:relative;margin:0;">
						<summary class="button perflocale-btn-icon perflocale-btn-icon--md" style="cursor:pointer;list-style:none;">
							<span class="dashicons dashicons-download"></span>
							<?php echo esc_html__( 'Export PO', 'perflocale' ); ?>
						</summary>
						<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="position:absolute;left:0;top:calc(100% + 6px);background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.08);padding:14px;min-width:240px;z-index:10;">
							<?php wp_nonce_field( 'perflocale_strings_po_export' ); ?>
							<input type="hidden" name="perflocale_strings_action" value="po_export">
							<p style="margin:0 0 6px;"><strong><?php echo esc_html__( 'Export translations as PO', 'perflocale' ); ?></strong></p>
							<label style="display:block;margin-bottom:8px;">
								<span style="display:block;font-size:12px;color:#646970;margin-bottom:2px;"><?php echo esc_html__( 'Language', 'perflocale' ); ?></span>
								<select name="lang" required style="width:100%;">
									<?php foreach ( $non_default_langs as $lang ) : ?>
										<option value="<?php echo esc_attr( $lang->slug ); ?>"><?php echo esc_html( ( $lang->native_name ?: $lang->name ) . ' (' . Helper::format_locale_as_bcp47( $lang->slug ) . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<button type="submit" class="button button-primary"><?php echo esc_html__( 'Download .po', 'perflocale' ); ?></button>
						</form>
					</details>

					<details class="perflocale-page-action" data-perflocale-popover style="position:relative;margin:0;">
						<summary class="button perflocale-btn-icon perflocale-btn-icon--md" style="cursor:pointer;list-style:none;">
							<span class="dashicons dashicons-upload"></span>
							<?php echo esc_html__( 'Import PO', 'perflocale' ); ?>
						</summary>
						<form method="post" action="<?php echo esc_url( $base_url ); ?>" enctype="multipart/form-data" style="position:absolute;left:0;top:calc(100% + 6px);background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.08);padding:14px;min-width:280px;z-index:10;">
							<?php wp_nonce_field( 'perflocale_strings_po_import' ); ?>
							<input type="hidden" name="perflocale_strings_action" value="po_import">
							<p style="margin:0 0 6px;"><strong><?php echo esc_html__( 'Import PO file', 'perflocale' ); ?></strong></p>
							<label style="display:block;margin-bottom:8px;">
								<span style="display:block;font-size:12px;color:#646970;margin-bottom:2px;"><?php echo esc_html__( 'Language', 'perflocale' ); ?></span>
								<select name="lang" required style="width:100%;">
									<?php foreach ( $non_default_langs as $lang ) : ?>
										<option value="<?php echo esc_attr( $lang->slug ); ?>"><?php echo esc_html( ( $lang->native_name ?: $lang->name ) . ' (' . Helper::format_locale_as_bcp47( $lang->slug ) . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label class="perflocale-dropzone" data-perflocale-dropzone style="margin-bottom:8px;">
								<input type="file" name="po_file" accept=".po" required class="perflocale-dropzone__input">
								<div class="perflocale-dropzone__hint">
									<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
									<strong><?php echo esc_html__( 'Drop a .po file here', 'perflocale' ); ?></strong>
									<span>
									<?php
										printf(
											/* translators: %s: 'click to browse' rendered as an inline link */
											esc_html__( 'or %s to choose a file', 'perflocale' ),
											'<em>' . esc_html__( 'click to browse', 'perflocale' ) . '</em>'
										);
									?>
									</span>
								</div>
								<small class="perflocale-dropzone__filename" data-perflocale-dropzone-name></small>
							</label>
							<p style="margin:-2px 0 8px;font-size:11px;color:#646970;">
								<?php
								printf(
									/* translators: %s: largest PO file this site accepts, e.g. "2 MB". */
									esc_html__( 'Maximum file size: %s.', 'perflocale' ),
									esc_html( $po_upload_ceiling )
								);
								?>
							</p>
							<label style="display:block;margin-bottom:10px;">
								<input type="checkbox" name="replace_mode" value="1">
								<?php echo esc_html__( 'Replace existing translations for this language', 'perflocale' ); ?>
							</label>
							<button type="submit" class="button button-primary"><?php echo esc_html__( 'Import', 'perflocale' ); ?></button>
						</form>
					</details>
				<?php endif; ?>

			</div>

			<?php \PerfLocale\Admin\PluginNav::render(); ?>
			<hr class="wp-header-end">

			<?php if ( $scan_new >= 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %1$s: number of new strings, %2$s: total strings */
							esc_html__( 'Scan complete. %1$s new strings added. Total: %2$s strings in database.', 'perflocale' ),
							'<strong>' . esc_html( number_format_i18n( $scan_new ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $scan_total ) ) . '</strong>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $saved > 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php /* translators: %d: number of saved translations */ printf( esc_html( _n( '%d translation saved.', '%d translations saved.', $saved, 'perflocale' ) ), esc_html( $saved ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $save_failed > 0 ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php /* translators: %d: number of translations that could not be saved */ printf( esc_html( _n( '%d translation could not be saved and was discarded — a database error stopped the write. Copy your text before reloading, then try again.', '%d translations could not be saved and were discarded — a database error stopped the write. Copy your text before reloading, then try again.', $save_failed, 'perflocale' ) ), esc_html( $save_failed ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// PO import flash notice — pulled from a per-user transient so
			// the redirect back from the import handler doesn't leave
			// `po_message=…&imported=…&errors=…` query strings in the URL
			// (mirrors the scan-result transient pattern above).
			$po_import_result = get_transient( 'perflocale_po_import_result_' . get_current_user_id() );

			if ( is_array( $po_import_result ) ) {
				delete_transient( 'perflocale_po_import_result_' . get_current_user_id() );
			}

			// Error-only messages (no_lang / missing_input / export_fail) still
			// come through URL params for now — they're set on a redirect from
			// validation gates BEFORE the importer runs. Read once and let the
			// browser strip them via history.replaceState below. Pure display
			// state with sanitize_key — no nonce needed because nothing
			// mutates based on the value.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$po_message = isset( $_GET['po_message'] ) ? sanitize_key( wp_unslash( (string) $_GET['po_message'] ) ) : '';
			?>

			<?php
			if ( is_array( $po_import_result ) ) :
				$inserted       = (int) ( $po_import_result['inserted'] ?? 0 );
				$updated        = (int) ( $po_import_result['updated'] ?? 0 );
				$unchanged      = (int) ( $po_import_result['unchanged'] ?? 0 );
				$no_translation = (int) ( $po_import_result['no_translation'] ?? 0 );
				$fuzzy_skipped  = (int) ( $po_import_result['fuzzy_skipped'] ?? 0 );
				$err            = (int) ( $po_import_result['errors'] ?? 0 );
				$total_entries  = (int) ( $po_import_result['total_entries'] ?? 0 );
				// Headline: "added/updated/unchanged" + a separate hint for
				// msgid-only rows so the user understands the breakdown.
				$primary_changes = $inserted + $updated;
				?>
				<div class="notice <?php echo $err > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
					<p><strong>
					<?php
					if ( $primary_changes === 0 && $unchanged > 0 ) {
						printf(
							/* translators: %d: number of entries already up to date */
							esc_html__( 'PO import: nothing changed (%d translations were already up to date).', 'perflocale' ),
							absint( $unchanged )
						);
					} else {
						printf(
							/* translators: 1: inserted count, 2: updated count, 3: unchanged count */
							esc_html__( 'PO import: %1$d added, %2$d updated, %3$d already up to date.', 'perflocale' ),
							absint( $inserted ),
							absint( $updated ),
							absint( $unchanged )
						);
					}
					?>
					</strong></p>
					<?php if ( $no_translation > 0 || $fuzzy_skipped > 0 || $err > 0 ) : ?>
						<p style="margin:6px 0 0;color:#646970;font-size:13px;">
						<?php
							$bits = [];
						if ( $no_translation > 0 ) {
							$bits[] = sprintf(
								/* translators: %d: number of msgid-only entries */
								esc_html( _n( '%d entry had no translation (msgid only).', '%d entries had no translation (msgid only).', $no_translation, 'perflocale' ) ),
								$no_translation
							);
						}
						if ( $fuzzy_skipped > 0 ) {
							$bits[] = sprintf(
								/* translators: %d: number of fuzzy entries skipped */
								esc_html( _n( '%d fuzzy entry skipped (marked unreliable in the PO file).', '%d fuzzy entries skipped (marked unreliable in the PO file).', $fuzzy_skipped, 'perflocale' ) ),
								$fuzzy_skipped
							);
						}
						if ( $err > 0 ) {
							$bits[] = sprintf(
								/* translators: %d: number of errors */
								esc_html( _n( '%d error.', '%d errors.', $err, 'perflocale' ) ),
								$err
							);
						}
							// Each $bits entry was already passed through esc_html() / esc_html(_n())
							// when it was added to the array, so the implode() output is safe.
							echo wp_kses_post( implode( ' ', $bits ) );
						if ( $total_entries > 0 ) {
							echo ' <em style="color:#8c8f94;">(' . sprintf(
								/* translators: %d: total entries in the PO file */
								esc_html( _n( '%d entry in file', '%d entries in file', $total_entries, 'perflocale' ) ),
								absint( $total_entries )
							) . ')</em>';
						}
						?>
						</p>
					<?php endif; ?>
				</div>
			<?php elseif ( $po_message === 'no_lang' || $po_message === 'missing_input' ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'PO import requires a language and a file.', 'perflocale' ); ?></p>
				</div>
			<?php elseif ( $po_message === 'export_fail' ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'PO export failed. Check the language slug and try again.', 'perflocale' ); ?></p>
				</div>
			<?php elseif ( $po_message === 'too_large' ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
					<?php
					// The importer's own ceiling, not PHP's — PHP refuses its
					// own limit long before the file reaches the gate that
					// sets this message, so naming a number is what makes the
					// difference between the two visible.
					printf(
						/* translators: %s: largest PO file the importer will parse, e.g. "50 MB". */
						esc_html__( 'The uploaded PO file is larger than the importer accepts (limit: %s). Split it into smaller files, or raise the ceiling with the perflocale/po/max_bytes filter.', 'perflocale' ),
						esc_html( AdminController::format_bytes( AdminController::po_max_bytes() ) )
					);
					?>
					</p>
				</div>
			<?php elseif ( $po_message === 'upload_error' ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
					<?php
					// PHP refused the upload. The handler forwards only the
					// numeric UPLOAD_ERR_* code, so nothing user-supplied
					// travels in the URL and both import forms share one set
					// of sentences. absint() bounds it; an unmapped code falls
					// through to upload_error_message()'s default branch.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$po_upload_err = isset( $_GET['po_upload_err'] ) ? absint( wp_unslash( $_GET['po_upload_err'] ) ) : UPLOAD_ERR_NO_FILE;
					echo esc_html( AdminController::upload_error_message( $po_upload_err ) );
					?>
					</p>
				</div>
			<?php elseif ( $po_message === 'invalid_upload' ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'The uploaded file could not be read. Please try again.', 'perflocale' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// AI quality "Mark reviewed" / "Re-score" flash notice. Sourced
			// from the admin-post redirect (?message=mt_review_done&op=clear|rescore
			// or message=mt_review_nochange). No nonce read — pure display
			// state that just confirms a write the handler already validated.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_review_msg = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( (string) $_GET['message'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_review_op = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( (string) $_GET['op'] ) ) : '';
			?>
			<?php if ( $_review_msg === 'mt_review_done' ) : ?>
				<div class="notice notice-success is-dismissible"><p>
				<?php
					echo esc_html(
						$_review_op === 'rescore'
							? __( 'Translation queued for re-scoring on the next cron run.', 'perflocale' )
							: __( 'Translation marked as reviewed. The badge will disappear after the page reload.', 'perflocale' )
					);
				?>
				</p></div>
			<?php elseif ( $_review_msg === 'mt_review_nochange' ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'No score row matched the request — it may have already been cleared.', 'perflocale' ); ?></p></div>
			<?php endif; ?>

			<?php
			// Strip stale PO error params after the notice has rendered so
			// reloads don't replay the message and pagination URLs stay clean.
			// Attached as inline JS to the registered `perflocale-admin` handle
			// instead of a raw <script> tag so wp.org's "use wp_enqueue" rule
			// is satisfied. Conditional add — only runs when there's a message
			// to clean up.
			if ( $po_message !== '' ) {
				wp_add_inline_script(
					'perflocale-admin',
					'(function () {' .
					' if ( ! window.history || ! window.history.replaceState ) return;' .
					' var url = new URL( window.location.href );' .
					' [ "po_message", "po_upload_err", "imported", "skipped", "errors" ].forEach( function ( key ) {' .
					'  url.searchParams.delete( key );' .
					' } );' .
					' window.history.replaceState( {}, document.title, url.toString() );' .
					'})();'
				);
			}
			?>

			<!-- Toolbar -->
			<div class="perflocale-str-toolbar">
				<div class="perflocale-str-toolbar__left">
					<form method="get" class="perflocale-str-search">
						<input type="hidden" name="page" value="perflocale-strings">
						<?php if ( $domain !== '' ) : ?>
							<input type="hidden" name="domain_filter" value="<?php echo esc_attr( $domain ); ?>">
						<?php endif; ?>
						<?php if ( $status !== '' ) : ?>
							<input type="hidden" name="status_filter" value="<?php echo esc_attr( $status ); ?>">
						<?php endif; ?>
						<?php if ( $lang_slug_filter !== '' ) : ?>
							<input type="hidden" name="perflocale_lang" value="<?php echo esc_attr( $lang_slug_filter ); ?>">
						<?php endif; ?>
						<select name="search_mode" style="min-width:120px;" aria-label="<?php echo esc_attr__( 'Search mode', 'perflocale' ); ?>">
							<option value="contains" <?php selected( $search_mode, 'contains' ); ?>><?php echo esc_html__( 'Contains', 'perflocale' ); ?></option>
							<option value="not_contains" <?php selected( $search_mode, 'not_contains' ); ?>><?php echo esc_html__( 'Does not contain', 'perflocale' ); ?></option>
							<option value="exact" <?php selected( $search_mode, 'exact' ); ?>><?php echo esc_html__( 'Exact match', 'perflocale' ); ?></option>
							<option value="starts_with" <?php selected( $search_mode, 'starts_with' ); ?>><?php echo esc_html__( 'Starts with', 'perflocale' ); ?></option>
							<option value="not_starts_with" <?php selected( $search_mode, 'not_starts_with' ); ?>><?php echo esc_html__( 'Does not start with', 'perflocale' ); ?></option>
							<option value="ends_with" <?php selected( $search_mode, 'ends_with' ); ?>><?php echo esc_html__( 'Ends with', 'perflocale' ); ?></option>
							<option value="not_ends_with" <?php selected( $search_mode, 'not_ends_with' ); ?>><?php echo esc_html__( 'Does not end with', 'perflocale' ); ?></option>
						</select>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search strings...', 'perflocale' ); ?>" class="perflocale-str-search__input">
						<button type="submit" class="button"><?php echo esc_html__( 'Search', 'perflocale' ); ?></button>
						<?php if ( $search !== '' ) : ?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array_diff_key(
										$sort_args,
										[
											's'           => '',
											'search_mode' => '',
										]
									),
									admin_url( 'admin.php' )
								)
							);
							?>
										" class="button"><?php echo esc_html__( 'Clear', 'perflocale' ); ?></a>
						<?php endif; ?>
					</form>
				</div>
				<div class="perflocale-str-toolbar__right">
					<span class="perflocale-str-toolbar__count"><?php /* translators: %s: total number of strings */ printf( esc_html__( '%s strings', 'perflocale' ), '<strong>' . esc_html( number_format_i18n( $total_items ) ) . '</strong>' ); ?></span>

					<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'perflocale_scan_strings' ); ?>
						<input type="hidden" name="perflocale_scan" value="1">
						<button type="submit" class="button button-primary perflocale-btn-icon perflocale-btn-icon--md" data-perflocale-submit-busy="<?php echo esc_attr__( 'Scanning…', 'perflocale' ); ?>">
							<span class="dashicons dashicons-search"></span>
							<?php echo esc_html__( 'Scan for Strings', 'perflocale' ); ?>
						</button>
					</form>
				</div>
			</div>

			<!-- Filters - always visible so users can clear criteria when results are empty -->
			<div class="tablenav top">
				<div class="alignleft actions">
					<form method="get" class="perflocale-str-filter" style="display:flex;align-items:center;margin:0;">
						<input type="hidden" name="page" value="perflocale-strings">
						<?php if ( $search !== '' ) : ?>
							<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
						<?php endif; ?>
						<?php if ( $search_mode !== 'contains' ) : ?>
							<input type="hidden" name="search_mode" value="<?php echo esc_attr( $search_mode ); ?>">
						<?php endif; ?>

						<select name="domain_filter" aria-label="<?php echo esc_attr__( 'Filter by domain', 'perflocale' ); ?>">
							<option value=""><?php echo esc_html__( 'All Domains', 'perflocale' ); ?></option>
							<?php foreach ( $domains as $d ) : ?>
								<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $domain, $d ); ?>><?php echo esc_html( $d ); ?></option>
							<?php endforeach; ?>
						</select>

						<?php if ( ! empty( $contexts ) ) : ?>
						<select name="context_filter" aria-label="<?php echo esc_attr__( 'Filter by context', 'perflocale' ); ?>">
							<option value=""><?php echo esc_html__( 'All Contexts', 'perflocale' ); ?></option>
							<?php foreach ( $contexts as $c ) : ?>
								<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $context, $c ); ?>><?php echo esc_html( $c ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php endif; ?>

						<select name="status_filter" aria-label="<?php echo esc_attr__( 'Filter by translation status', 'perflocale' ); ?>">
							<option value=""><?php echo esc_html__( 'All Strings', 'perflocale' ); ?></option>
							<option value="translated" <?php selected( $status, 'translated' ); ?>><?php echo esc_html__( 'Translated', 'perflocale' ); ?></option>
							<option value="untranslated" <?php selected( $status, 'untranslated' ); ?>><?php echo esc_html__( 'Untranslated', 'perflocale' ); ?></option>
							<option value="needs_update" <?php selected( $status, 'needs_update' ); ?>><?php echo esc_html__( 'Needs Update', 'perflocale' ); ?></option>
						</select>

						<select name="perflocale_lang" aria-label="<?php echo esc_attr__( 'Filter by language', 'perflocale' ); ?>">
							<option value=""><?php echo esc_html__( 'All Languages', 'perflocale' ); ?></option>
							<?php foreach ( $languages as $lang ) : ?>
								<option value="<?php echo esc_attr( $lang->slug ); ?>" <?php selected( $lang_slug_filter, $lang->slug ); ?>>
									<?php echo esc_html( Helper::get_flag_emoji( $lang ) . ' ' . ( $lang->native_name ?: $lang->name ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<button type="submit" class="button" style="white-space:nowrap;"><?php echo esc_html__( 'Filter', 'perflocale' ); ?></button>

						<?php if ( $domain !== '' || $context !== '' || $status !== '' || $lang_slug_filter !== '' ) : ?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									[
										'page'        => 'perflocale-strings',
										's'           => $search !== '' ? $search : false,
										'search_mode' => $search_mode !== 'contains' ? $search_mode : false,
									],
									admin_url( 'admin.php' )
								)
							);
							?>
										" class="button" style="white-space:nowrap;margin-left:0.5em;"><?php echo esc_html__( 'Reset', 'perflocale' ); ?></a>
						<?php endif; ?>
					</form>
				</div>
				<?php $this->render_tablenav( $page_num, $total_pages, $total_items, $sort_args ); ?>
			</div>

				<?php
				$has_active_filter = ( $domain !== '' || $context !== '' || $search !== '' || $status !== '' || $lang_slug_filter !== '' );
				// "Translate all" should confirm against the entire strings
				// table, not the currently-filtered subset. When filters are
				// clear this is exactly $total_items, so skip the extra COUNT
				// query in that case.
				$grand_total = $has_active_filter ? $string_repo->count() : $total_items;
				?>

				<?php if ( $mt_show_toolbar ) : ?>
				<div class="perflocale-str-mt-toolbar"
					data-perflocale-mt-toolbar
					data-perflocale-total="<?php echo esc_attr( (string) $total_items ); ?>"
					data-perflocale-grand-total="<?php echo esc_attr( (string) $grand_total ); ?>"
					data-perflocale-has-filter="<?php echo $has_active_filter ? '1' : '0'; ?>"
					data-perflocale-filter="
					<?php
					echo esc_attr(
						(string) wp_json_encode(
							[
								'domain'      => $domain,
								'context'     => $context,
								'search'      => $search,
								'search_mode' => $search_mode,
								'status'      => $status,
								'language_id' => $lang_filter,
							]
						)
					);
					?>
					">
					<div class="perflocale-str-mt-toolbar__inner">
						<div class="perflocale-str-mt-toolbar__group perflocale-str-mt-toolbar__group--targets">
							<span class="perflocale-str-mt-toolbar__label"><?php echo esc_html__( 'MT translate to:', 'perflocale' ); ?></span>
							<div class="perflocale-str-mt-toolbar__chips" role="group" aria-label="<?php echo esc_attr__( 'Target languages for MT', 'perflocale' ); ?>">
								<?php
								foreach ( $mt_non_default as $lang ) :
									$label = Helper::get_flag_emoji( $lang ) . ' ' . ( $lang->native_name ?: ( $lang->name ?: $lang->slug ) );
									?>
									<label class="perflocale-str-mt-toolbar__chip">
										<input type="checkbox"
											class="perflocale-str-mt-toolbar__chip-input"
											data-perflocale-mt-target="<?php echo esc_attr( (string) (int) $lang->id ); ?>"
											value="<?php echo esc_attr( (string) (int) $lang->id ); ?>">
										<span class="perflocale-str-mt-toolbar__chip-label"><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="perflocale-str-mt-toolbar__group perflocale-str-mt-toolbar__group--options">
							<label class="perflocale-str-mt-toolbar__overwrite">
								<input type="checkbox" data-perflocale-mt-overwrite value="1">
								<?php echo esc_html__( 'Overwrite existing translations', 'perflocale' ); ?>
							</label>
						</div>
					</div>

					<div class="perflocale-str-mt-toolbar__action-row">
						<p class="perflocale-str-mt-toolbar__status" data-perflocale-mt-status aria-live="polite"></p>
						<div class="perflocale-str-mt-toolbar__group perflocale-str-mt-toolbar__group--actions">
							<button type="button"
								class="button button-primary perflocale-str-mt-toolbar__btn perflocale-str-mt-toolbar__btn--ids"
								data-perflocale-mt-action="ids"
								hidden>
								<?php echo esc_html__( 'Translate selected', 'perflocale' ); ?>
								<span class="perflocale-str-mt-toolbar__count" data-perflocale-mt-count>(0)</span>
							</button>
							<?php if ( $has_active_filter ) : ?>
								<button type="button"
									class="button perflocale-str-mt-toolbar__btn perflocale-str-mt-toolbar__btn--filter"
									data-perflocale-mt-action="filter">
									<?php
									printf(
										/* translators: %s: count of filtered strings */
										esc_html__( 'Translate filtered (%s)', 'perflocale' ),
										'<strong>' . esc_html( number_format_i18n( $total_items ) ) . '</strong>'
									);
									?>
								</button>
							<?php endif; ?>
							<button type="button"
								class="button perflocale-str-mt-toolbar__btn perflocale-str-mt-toolbar__btn--all"
								data-perflocale-mt-action="all">
								<?php echo esc_html__( 'Translate all', 'perflocale' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php $save_form_id = 'perflocale-str-save-form'; ?>
				<form id="<?php echo esc_attr( $save_form_id ); ?>" method="post" action="<?php echo esc_url( $base_url ); ?>">
					<?php wp_nonce_field( 'perflocale_string_translations' ); ?>
					<input type="hidden" name="perflocale_save_string_translations" value="1">
					<input type="hidden" name="domain_filter" value="<?php echo esc_attr( $domain ); ?>">
					<input type="hidden" name="search" value="<?php echo esc_attr( $search ); ?>">
					<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $page_num ); ?>">

					<div class="perflocale-table-responsive">
					<table class="wp-list-table widefat fixed striped perflocale-str-edit-table">
						<caption class="screen-reader-text"><?php echo esc_html__( 'Strings and their translations, one column per language.', 'perflocale' ); ?></caption>
						<?php if ( ! empty( $strings ) ) : ?>
						<thead>
							<tr>
								<?php if ( $mt_show_toolbar ) : ?>
									<td class="manage-column column-cb check-column">
										<label class="screen-reader-text" for="perflocale-str-cb-select-all"><?php echo esc_html__( 'Select All', 'perflocale' ); ?></label>
										<input id="perflocale-str-cb-select-all" type="checkbox" data-perflocale-mt-select-all>
									</td>
								<?php endif; ?>
								<th scope="col" class="manage-column"><?php echo esc_html__( 'Original Text', 'perflocale' ); ?></th>
								<th scope="col" class="manage-column"><?php echo esc_html__( 'Domain', 'perflocale' ); ?></th>
								<?php
								foreach ( $languages as $lang ) :
									$is_hidden  = in_array( $lang->slug, $hidden_langs, true );
									$lang_label = $lang->native_name ?: ( $lang->name ?: $lang->slug );
									?>
									<th scope="col" class="perflocale-str-edit-table__th-lang" data-perflocale-lang-col="<?php echo esc_attr( $lang->slug ); ?>"<?php echo $is_hidden ? ' style="display:none;"' : ''; ?>>
										<span class="perflocale-th-lang">
											<span class="perflocale-th-lang__flag" aria-hidden="true"><?php echo esc_html( Helper::get_flag_emoji( $lang ) ); ?></span>
											<span class="perflocale-th-lang__name"><?php echo esc_html( $lang_label ); ?></span>
										</span>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<?php endif; ?>
						<tbody>
							<?php if ( empty( $strings ) ) : ?>
								<tr class="no-items">
									<td class="perflocale-empty-row" colspan="<?php echo absint( 2 + count( $languages ) + ( $mt_show_toolbar ? 1 : 0 ) ); ?>">
										<?php
										echo $search !== '' || $domain !== '' || $status !== '' || $lang_filter > 0
											? esc_html__( 'No strings match your criteria.', 'perflocale' )
											: esc_html__( 'No strings yet. Click "Scan for Strings".', 'perflocale' );
										?>
									</td>
								</tr>
							<?php endif; ?>
							<?php
							foreach ( $strings as $string ) :
								// Comprehensive sprintf token detection (positional, width,
								// precision, all WP/PHP specifiers). Mirrored client-side
								// by the strings-validate.js extractor.
								$placeholders     = \PerfLocale\Util\SprintfTokens::extract( (string) $string->original );
								$has_placeholders = ! empty( $placeholders );
								?>
								<tr>
									<?php if ( $mt_show_toolbar ) : ?>
										<th scope="row" class="check-column">
											<label class="screen-reader-text" for="perflocale-str-cb-<?php echo absint( $string->id ); ?>">
												<?php
												printf(
													/* translators: %s: original (untranslated) string preview */
													esc_html__( 'Select string: %s', 'perflocale' ),
													esc_html( mb_strimwidth( $string->original, 0, 60, '...' ) )
												);
												?>
											</label>
											<input id="perflocale-str-cb-<?php echo absint( $string->id ); ?>"
												type="checkbox"
												class="perflocale-str-mt-cb"
												data-perflocale-string-id="<?php echo absint( $string->id ); ?>">
										</th>
									<?php endif; ?>
									<td class="column-primary">
										<span class="perflocale-str-original<?php echo $has_placeholders ? ' perflocale-str-original--has-ph' : ''; ?>">
											<?php echo esc_html( mb_strimwidth( $string->original, 0, 100, '...' ) ); ?>
										</span>
										<?php if ( ! empty( $string->context ) ) : ?>
											<span class="perflocale-str-context"><?php echo esc_html( $string->context ); ?></span>
										<?php endif; ?>
										<?php if ( $string->file_path !== '' ) : ?>
										<div class="perflocale-str-meta" title="<?php echo esc_attr( $this->full_path( $string->file_path ) . ':' . $string->line_number ); ?>">
											<?php echo esc_html( $this->truncate_path( $string->file_path ) . ':' . $string->line_number ); ?>
										</div>
										<?php endif; ?>
									</td>
									<td>
										<code class="perflocale-str-domain"><?php echo esc_html( $string->domain ); ?></code>
									</td>
									<?php
									foreach ( $languages as $lang ) :
										$text_key     = $string->id . '_' . $lang->id;
										$trans_text   = $text_map[ $text_key ] ?? '';
										$trans_status = $translation_map[ $text_key ] ?? '';
										$has_trans    = $trans_status !== '';
										$needs_update = $trans_status === 'needs_update';
										$field_name   = 'perflocale_str_trans[' . absint( $string->id ) . '][' . absint( $lang->id ) . ']';
										$ph_json      = esc_attr( wp_json_encode( $placeholders ) );
										$mo_hint      = $mo_hints[ $text_key ] ?? '';
										$ph_text      = $mo_hint !== '' ? $mo_hint : __( 'Translation...', 'perflocale' );
										$col_hidden   = in_array( $lang->slug, $hidden_langs, true );
										?>
										<td class="perflocale-str-edit-table__td-lang" data-perflocale-lang-col="<?php echo esc_attr( $lang->slug ); ?>"<?php echo $col_hidden ? ' style="display:none;"' : ''; ?>>
											<div class="perflocale-str-input-wrap">
												<?php if ( $needs_update ) : ?>
													<span class="perflocale-str-needs-update" title="<?php echo esc_attr__( 'Source text changed - review this translation', 'perflocale' ); ?>">&#x26A0;</span>
												<?php endif; ?>
												<input type="text"
													name="<?php echo esc_attr( $field_name ); ?>"
													value="<?php echo esc_attr( $trans_text ); ?>"
													dir="auto"
													class="perflocale-str-input<?php echo $has_trans ? ' perflocale-str-input--filled' : ''; ?><?php echo $needs_update ? ' perflocale-str-input--needs-update' : ''; ?><?php echo ( ! $has_trans && $mo_hint !== '' ) ? ' perflocale-str-input--mo-hint' : ''; ?>"
													placeholder="<?php echo esc_attr( $ph_text ); ?>">
												<button type="button"
													class="perflocale-str-expand-btn"
													title="<?php echo esc_attr__( 'Expand editor', 'perflocale' ); ?>"
													data-original="<?php echo esc_attr( $string->original ); ?>"
													data-context="<?php echo esc_attr( $string->context ); ?>"
													data-field="<?php echo esc_attr( $field_name ); ?>"
													data-placeholders="<?php echo esc_attr( $ph_json ); ?>"
													data-lang="<?php echo esc_attr( $lang->name ); ?>"
													data-mo-hint="<?php echo esc_attr( $mo_hint ); ?>">
													<span class="dashicons dashicons-editor-expand"></span>
												</button>
											</div>
											<?php
											// Extra plural forms (2..N) for a plural-context row in a
											// language with more than two CLDR plural forms (Polish,
											// Russian, Arabic …). Form 0 is this string's singular-context
											// sibling row; form 1 is the input above; these are forms 2..N.
											$is_plural_row = $string->context === 'plural'
												|| ( is_string( $string->context ) && str_ends_with( $string->context, ' (plural)' ) );
											$nplurals      = \PerfLocale\Strings\PluralRules::nplurals( (string) $lang->locale );

											if ( $is_plural_row && $nplurals > 2 ) :
												$existing_extra = $this->extra_map[ $text_key ] ?? [];
												?>
												<div class="perflocale-str-extra-forms">
													<span class="perflocale-str-extra-forms__label"><?php echo esc_html__( 'Plural forms', 'perflocale' ); ?></span>
													<?php for ( $form_i = 2; $form_i < $nplurals; $form_i++ ) : ?>
														<input type="text"
															name="perflocale_str_extra[<?php echo absint( $string->id ); ?>][<?php echo absint( $lang->id ); ?>][]"
															value="<?php echo esc_attr( $existing_extra[ $form_i - 2 ] ?? '' ); ?>"
															dir="auto"
															class="perflocale-str-input perflocale-str-input--plural-extra"
															placeholder="<?php echo esc_attr( sprintf( /* translators: %d: plural form number */ __( 'Form %d', 'perflocale' ), $form_i + 1 ) ); ?>">
													<?php endfor; ?>
												</div>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>

				</form>

				<div class="tablenav bottom perflocale-str-save-row">
					<?php if ( ! empty( $strings ) ) : ?>
						<div class="perflocale-str-save-row__save">
							<?php submit_button( __( 'Save Translations', 'perflocale' ), 'primary', 'submit', false, [ 'form' => $save_form_id ] ); ?>
						</div>
					<?php endif; ?>
					<?php $this->render_tablenav( $page_num, $total_pages, $total_items, $sort_args ); ?>
				</div>
		</div>

		<!-- Modal Editor -->
		<div id="perflocale-str-modal" class="perflocale-str-modal" style="display:none;">
			<div class="perflocale-str-modal__backdrop"></div>
			<div class="perflocale-str-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="perflocale-str-modal-title">
				<div class="perflocale-str-modal__header">
					<h2 id="perflocale-str-modal-title"><?php echo esc_html__( 'Edit Translation', 'perflocale' ); ?></h2>
					<button type="button" class="perflocale-str-modal__close" title="<?php echo esc_attr__( 'Close', 'perflocale' ); ?>">&times;</button>
				</div>
				<div class="perflocale-str-modal__body">
					<div class="perflocale-str-modal__original">
						<label><?php echo esc_html__( 'Original', 'perflocale' ); ?></label>
						<div id="perflocale-str-modal-original" class="perflocale-str-modal__text"></div>
					</div>
					<div id="perflocale-str-modal-context-wrap" class="perflocale-str-modal__context" style="display:none;">
						<label><?php echo esc_html__( 'Context', 'perflocale' ); ?></label>
						<div id="perflocale-str-modal-context"></div>
					</div>
					<div id="perflocale-str-modal-ph-wrap" class="perflocale-str-modal__placeholders" style="display:none;">
						<label>
							<?php echo esc_html__( 'Placeholders', 'perflocale' ); ?>
							<small>(<?php echo esc_html__( 'click to insert · turns green when present', 'perflocale' ); ?>)</small>
						</label>
						<div id="perflocale-str-modal-ph" class="perflocale-str-modal__ph-list" role="list"></div>
						<p id="perflocale-str-modal-ph-status" class="perflocale-str-modal__ph-status" aria-live="polite"></p>
					</div>
					<div class="perflocale-str-modal__translation">
						<label id="perflocale-str-modal-lang-label" for="perflocale-str-modal-textarea"><?php echo esc_html__( 'Translation', 'perflocale' ); ?></label>
						<textarea id="perflocale-str-modal-textarea" rows="5" dir="auto"></textarea>
					</div>
				</div>
				<div class="perflocale-str-modal__footer">
					<button type="button" class="button" id="perflocale-str-modal-cancel"><?php echo esc_html__( 'Cancel', 'perflocale' ); ?></button>
					<button type="button" class="button button-primary" id="perflocale-str-modal-apply"><?php echo esc_html__( 'Apply', 'perflocale' ); ?></button>
				</div>
			</div>
		</div>

		<?php
		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleStrI18n=' . wp_json_encode(
				[
					'allPresent'   => __( 'All placeholders present.', 'perflocale' ),
					/* translators: %s: comma-separated list of placeholder tokens (e.g. "%1$s, %2$d") missing from the translation. */
					'someMissing'  => __( 'Missing: %s', 'perflocale' ),
					/* translators: %s: comma-separated list of placeholder tokens present in the translation but not in the source string. */
					'someExtra'    => __( 'Extra (more than source): %s', 'perflocale' ),
					'applyConfirm' => __( 'Translation has placeholder issues. Apply anyway?', 'perflocale' ),
					/* translators: %s: comma-separated list of placeholder tokens missing from the input field's value. */
					'inputMissing' => __( 'Missing placeholder(s): %s', 'perflocale' ),
				]
			) . ';',
			'before'
		);

		// Modal + page-save validator. Both consume window.perflocaleStrValidate
		// (loaded by strings-validate.js). The modal does live highlighting; the
		// page-save fallback re-runs the same extractor on every dirty row.
		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				// ─── Modal ────────────────────────────────────────────────
				'var modal=document.getElementById("perflocale-str-modal");' .
				'if(!modal)return;' .
				'var textarea=document.getElementById("perflocale-str-modal-textarea");' .
				'var originalEl=document.getElementById("perflocale-str-modal-original");' .
				'var contextEl=document.getElementById("perflocale-str-modal-context");' .
				'var contextWrap=document.getElementById("perflocale-str-modal-context-wrap");' .
				'var phWrap=document.getElementById("perflocale-str-modal-ph-wrap");' .
				'var phList=document.getElementById("perflocale-str-modal-ph");' .
				'var phStatus=document.getElementById("perflocale-str-modal-ph-status");' .
				'var applyBtn=document.getElementById("perflocale-str-modal-apply");' .
				'var langLabel=document.getElementById("perflocale-str-modal-lang-label");' .
				'var targetField=null;' .
				'var modalOriginal="";' .
				'var validateTimer=null;' .

				// Render chips for `tokens`, attach click-to-insert handlers.
				'function renderChips(tokens){' .
					'phList.innerHTML="";' .
					'tokens.forEach(function(ph){' .
						'var chip=document.createElement("button");' .
						'chip.type="button";' .
						'chip.className="perflocale-str-modal__ph-chip";' .
						'chip.setAttribute("data-ph",ph);' .
						'chip.setAttribute("role","listitem");' .
						'chip.innerHTML=\'<span class="perflocale-str-modal__ph-icon" aria-hidden="true"></span>\'+' .
							'\'<code>\'+ph.replace(/&/g,"&amp;").replace(/</g,"&lt;")+\'</code>\';' .
						'chip.addEventListener("click",function(){' .
							'var start=textarea.selectionStart;' .
							'var end=textarea.selectionEnd;' .
							'var val=textarea.value;' .
							'textarea.value=val.substring(0,start)+ph+val.substring(end);' .
							'textarea.focus();' .
							'textarea.setSelectionRange(start+ph.length,start+ph.length);' .
							'runValidate();' .
						'});' .
						'phList.appendChild(chip);' .
					'});' .
				'}' .

				// Recompute chip states + status + Apply button warning.
				'function runValidate(){' .
					'if(!window.perflocaleStrValidate||!modalOriginal){' .
						'return;' .
					'}' .
					'var result=window.perflocaleStrValidate.compare(modalOriginal,textarea.value);' .
					'var missing=[],extra=[];' .
					'phList.querySelectorAll(".perflocale-str-modal__ph-chip").forEach(function(chip){' .
						'var ph=chip.getAttribute("data-ph");' .
						'var state=result.states[ph]||"missing";' .
						'chip.classList.remove("is-present","is-missing","is-extra");' .
						'chip.classList.add("is-"+state);' .
						'if(state==="missing")missing.push(ph);' .
						'else if(state==="extra")extra.push(ph);' .
					'});' .
					'var hasIssue=missing.length>0||extra.length>0;' .
					'applyBtn.classList.toggle("has-warning",hasIssue);' .
					'if(missing.length===0&&extra.length===0){' .
						'phStatus.textContent=perflocaleStrI18n.allPresent;' .
						'phStatus.className="perflocale-str-modal__ph-status is-clean";' .
					'}else{' .
						'var parts=[];' .
						'if(missing.length){parts.push(perflocaleStrI18n.someMissing.replace("%s",missing.join(", ")));}' .
						'if(extra.length){parts.push(perflocaleStrI18n.someExtra.replace("%s",extra.join(", ")));}' .
						'phStatus.textContent=parts.join(" · ");' .
						'phStatus.className="perflocale-str-modal__ph-status is-dirty";' .
					'}' .
				'}' .

				'function scheduleValidate(){' .
					'if(validateTimer)clearTimeout(validateTimer);' .
					'validateTimer=setTimeout(runValidate,80);' .
				'}' .

				'var lastOpener=null;' .
				'function openModal(btn){' .
					'lastOpener=btn;' .
					'modalOriginal=btn.dataset.original||"";' .
					'var context=btn.dataset.context||"";' .
					'var placeholders=JSON.parse(btn.dataset.placeholders||"[]");' .
					'var lang=btn.dataset.lang||"";' .
					'var fieldName=btn.dataset.field||"";' .
					'var moHint=btn.dataset.moHint||"";' .
					'targetField=document.querySelector(\'input[name="\'+fieldName+\'"]\');' .
					'originalEl.textContent=modalOriginal;' .
					'textarea.value=targetField?targetField.value:"";' .
					'textarea.placeholder=moHint||"";' .
					'langLabel.textContent=lang?lang+" Translation":"Translation";' .
					'if(context){contextEl.textContent=context;contextWrap.style.display="";}' .
					'else{contextWrap.style.display="none";}' .
					'if(placeholders.length>0){' .
						'phWrap.style.display="";' .
						'renderChips(placeholders);' .
						'runValidate();' .
					'}else{' .
						'phWrap.style.display="none";' .
						'applyBtn.classList.remove("has-warning");' .
					'}' .
					'modal.style.display="";' .
					'textarea.focus();' .
				'}' .
				'function closeModal(){' .
					'modal.style.display="none";' .
					'targetField=null;' .
					'modalOriginal="";' .
					'applyBtn.classList.remove("has-warning");' .
					// Return keyboard focus to the row button that opened the
					// dialog — closing used to drop focus to <body>, stranding
					// keyboard users at the top of the page.
					'if(lastOpener){lastOpener.focus();lastOpener=null;}' .
				'}' .
				'function applyModal(){' .
					'if(applyBtn.classList.contains("has-warning")){' .
						'if(!confirm(perflocaleStrI18n.applyConfirm))return;' .
					'}' .
					'if(targetField){' .
						'targetField.value=textarea.value;' .
						'if(textarea.value){targetField.classList.add("perflocale-str-input--filled");}' .
						'else{targetField.classList.remove("perflocale-str-input--filled");}' .
					'}' .
					'closeModal();' .
				'}' .

				'textarea.addEventListener("input",scheduleValidate);' .
				'document.querySelectorAll(".perflocale-str-expand-btn").forEach(function(btn){' .
					'btn.addEventListener("click",function(){openModal(this);});' .
				'});' .
				'document.querySelector(".perflocale-str-modal__close").addEventListener("click",closeModal);' .
				'document.querySelector(".perflocale-str-modal__backdrop").addEventListener("click",closeModal);' .
				'document.getElementById("perflocale-str-modal-cancel").addEventListener("click",closeModal);' .
				'applyBtn.addEventListener("click",applyModal);' .
				'document.addEventListener("keydown",function(e){' .
					'if(modal.style.display==="none"){return;}' .
					'if(e.key==="Escape"){closeModal();return;}' .
					// Focus trap: Tab cycles inside the dialog while it is
					// open (aria-modal promises assistive tech exactly that).
					'if(e.key==="Tab"){' .
						'var f=modal.querySelectorAll("button,textarea,input,select,a[href]");' .
						'if(!f.length){return;}' .
						'var first=f[0],last=f[f.length-1];' .
						'if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}' .
						'else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}' .
					'}' .
				'});' .
			'})();' .

			// Per-row inline validation: red border + warning icon on rows
			// whose value is missing source placeholders, live on keystroke.
			'(function(){' .
				// `strings-validate.js` declares perflocale-admin as a
				// dependency, so it loads AFTER this inline script runs.
				// Don\'t early-return on missing window.perflocaleStrValidate
				// — attach listeners now and lazy-check inside validate()
				// when events actually fire (by which time the file is
				// loaded).
				'var inputs=document.querySelectorAll(".perflocale-str-input");' .
				'if(!inputs.length)return;' .

				// Cache extracted source tokens per input so we don\'t re-run
				// the regex on every keystroke. Source is read from the row\'s
				// expand-button data-original (one button per language column,
				// any will do — same source).
				'function getSource(input){' .
					'var wrap=input.closest(".perflocale-str-input-wrap");' .
					'if(!wrap)return"";' .
					'var btn=wrap.querySelector(".perflocale-str-expand-btn");' .
					'return btn?(btn.dataset.original||""):"";' .
				'}' .

				'function ensureWarn(wrap){' .
					'var icon=wrap.querySelector(".perflocale-str-input-warn");' .
					'if(!icon){' .
						'icon=document.createElement("span");' .
						'icon.className="perflocale-str-input-warn";' .
						'icon.textContent="\\u26A0";' .
						'icon.setAttribute("aria-hidden","true");' .
						'wrap.appendChild(icon);' .
					'}' .
					'return icon;' .
				'}' .

				'function validate(input){' .
					// Lazy-check the validator API — strings-validate.js may
					// not be loaded yet at IIFE-execution time but will be
					// by the time any event fires.
					'if(!window.perflocaleStrValidate)return;' .
					'var src=getSource(input);' .
					'var val=input.value;' .
					'var wrap=input.closest(".perflocale-str-input-wrap");' .
					// Empty translation → never invalid (translator hasn\'t
					// started yet). Source with no placeholders → no check.
					'if(!src||!val.trim()){' .
						'input.classList.remove("perflocale-str-input--invalid");' .
						'var icon=wrap&&wrap.querySelector(".perflocale-str-input-warn");' .
						'if(icon)icon.style.display="none";' .
						'return;' .
					'}' .
					'var srcTokens=window.perflocaleStrValidate.extract(src);' .
					'if(srcTokens.length===0){' .
						'input.classList.remove("perflocale-str-input--invalid");' .
						'var icon2=wrap&&wrap.querySelector(".perflocale-str-input-warn");' .
						'if(icon2)icon2.style.display="none";' .
						'return;' .
					'}' .
					'var result=window.perflocaleStrValidate.compare(src,val);' .
					'var missing=[];' .
					'srcTokens.forEach(function(t){' .
						'if(result.states[t]==="missing")missing.push(t);' .
					'});' .
					'if(missing.length>0){' .
						'input.classList.add("perflocale-str-input--invalid");' .
						'var icon3=ensureWarn(wrap);' .
						'icon3.style.display="";' .
						'icon3.title=perflocaleStrI18n.inputMissing.replace("%s",missing.join(", "));' .
					'}else{' .
						'input.classList.remove("perflocale-str-input--invalid");' .
						'var icon4=wrap&&wrap.querySelector(".perflocale-str-input-warn");' .
						'if(icon4)icon4.style.display="none";' .
					'}' .
				'}' .

				'inputs.forEach(function(input){' .
					'input.addEventListener("input",function(){validate(input);});' .
				'});' .

				// Initial sweep — flag any pre-filled bad rows on page
				// load. Deferred to the next tick because strings-validate.js
				// hasn\'t loaded yet at IIFE-execution time (it declares
				// perflocale-admin as a dependency, so it ships AFTER this
				// inline blob).
				'setTimeout(function(){inputs.forEach(validate);},0);' .

				// The modal\'s Apply path writes back to the hidden input via
				// .value=... which doesn\'t fire \'input\'. Re-validate after
				// modal apply by listening on the form for any value change.
				'document.addEventListener("change",function(ev){' .
					'if(ev.target.matches&&ev.target.matches(".perflocale-str-input"))validate(ev.target);' .
				'});' .

				// Also re-validate after modal apply syncs the hidden input.
				// The Apply handler doesn\'t fire change, so observe via
				// MutationObserver on the input\'s value attribute. Cheaper
				// alternative: listen for the modal close + sweep.
				'var modalApplyBtn=document.getElementById("perflocale-str-modal-apply");' .
				'if(modalApplyBtn){' .
					'modalApplyBtn.addEventListener("click",function(){' .
						'setTimeout(function(){inputs.forEach(validate);},50);' .
					'});' .
				'}' .
			'})();'
		);
		?>
		<?php
	}

	/**
	 * Render standard WP tablenav pagination.
	 *
	 * @param int                  $current Current page.
	 * @param int                  $total_pages Total pages.
	 * @param int                  $total_items Total items.
	 * @param array<string,string> $base_args URL args.
	 * @return void
	 */
	private function render_tablenav( int $current, int $total_pages, int $total_items, array $base_args ): void {
		// Ensure transient scan params never leak into pagination links.
		unset( $base_args['scan_new'], $base_args['scan_total'], $base_args['saved'], $base_args['save_failed'] );

		$page_links = paginate_links(
			[
				'base'      => add_query_arg( array_merge( $base_args, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => $current,
			]
		);

		echo '<div class="tablenav-pages">';

		if ( $page_links ) {
			echo wp_kses_post( '<span class="pagination-links">' . $page_links . '</span>' );
		}

		echo '</div>';
	}

	/**
	 * Get per-page from Screen Options.
	 *
	 * @return int
	 */
	private function get_per_page(): int {
		$user   = get_current_user_id();
		$screen = get_current_screen();
		$option = $screen ? $screen->get_option( 'per_page', 'option' ) : '';
		$val    = $option ? (int) get_user_meta( $user, $option, true ) : 0;

		return $val > 0 ? $val : 20;
	}

	/**
	 * Bound the grid page size so the save form can never post more inputs
	 * than PHP will accept.
	 *
	 * The form posts one control per (row × language), plus — on a plural row
	 * in a language with more than two CLDR plural forms — one more per extra
	 * form. The bound assumes the WORST case, every row a plural row, because
	 * the page size has to be fixed before the rows are queried and the real
	 * plural count for the page is not knowable yet. On en/de/pl (Polish has
	 * three forms) that costs a quarter of the rows and buys a guarantee worth
	 * more than they are: whatever the browser posts, PHP parses all of it.
	 *
	 * `max_input_vars` is applied to $_GET, $_POST and $_COOKIE separately
	 * (measured on PHP 8.2: 400 query vars and 400 cookies alongside the POST
	 * left the POST budget untouched), so the reserve only has to cover this
	 * form's own scalars — see SAVE_FORM_RESERVED_INPUTS.
	 *
	 * Default per-page is 20, so on any language set this is a no-op until an
	 * operator raises the Screen Options value far enough to be in danger.
	 *
	 * @param int                $per_page       Rows per page the operator asked for.
	 * @param array<int, object> $languages      Active languages; each carries a `locale`.
	 * @param int|null           $max_input_vars Limit override for tests; null reads the live ini.
	 * @return int Rows per page, never below 1.
	 */
	private function cap_per_page_to_input_vars( int $per_page, array $languages, ?int $max_input_vars = null ): int {
		$limit = $max_input_vars ?? (int) ini_get( 'max_input_vars' );

		// There is no "unlimited" value to detect: PHP rejects a negative
		// max_input_vars and silently falls back to 1000 (measured — `-d
		// max_input_vars=-1` makes ini_get() report "1000"), and 0 is a real,
		// pathological setting meaning "accept no input variables at all",
		// which no row count can rescue. So a non-positive reading only ever
		// means the ini is unreadable: leave the operator's page size alone
		// rather than clamp on a value we do not trust.
		if ( $limit <= 0 || [] === $languages ) {
			return $per_page;
		}

		$per_row = 0;

		foreach ( $languages as $lang ) {
			$extra    = \PerfLocale\Strings\PluralRules::nplurals( (string) ( $lang->locale ?? '' ) ) - 2;
			$per_row += 1 + max( 0, $extra );
		}

		if ( $per_row < 1 ) {
			return $per_page;
		}

		$budget = max( 0, $limit - self::SAVE_FORM_RESERVED_INPUTS );

		return max( 1, min( $per_page, intdiv( $budget, $per_row ) ) );
	}

	/**
	 * Preload translation statuses.
	 *
	 * Returns "<string_id>:<lang_id>" → status-string map. The value is the
	 * link's status column (e.g. 'translated', 'needs_update')
	 * or the empty string when no link exists. Callers compare against
	 * specific status values to render flag styling on the strings table.
	 *
	 * @param array<int,object>         $strings Strings.
	 * @param array<int,object>         $languages Languages.
	 * @param TranslationLinkRepository $link_repo Link repo.
	 * @return array<string,string>
	 */
	private function preload_translations( array $strings, array $languages, TranslationLinkRepository $link_repo ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$map       = [];
		$group_ids = [];

		foreach ( $strings as $s ) {
			if ( ! empty( $s->group_id ) ) {
				$group_ids[] = (int) $s->group_id;
			}
		}

		if ( empty( $group_ids ) || empty( $languages ) ) {
			return $map;
		}

		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT group_id, language_id, status FROM %i WHERE group_id IN ({$placeholders})",
				$links_table,
				...$group_ids
			)
		);

		$glm = [];

		foreach ( $links as $l ) {
			$glm[ (int) $l->group_id ][ (int) $l->language_id ] = $l->status ?? 'translated';
		}

		foreach ( $strings as $s ) {
			if ( empty( $s->group_id ) ) {
				continue;
			}

			foreach ( $languages as $lang ) {
				if ( isset( $glm[ (int) $s->group_id ][ (int) $lang->id ] ) ) {
					$map[ $s->id . '_' . $lang->id ] = $glm[ (int) $s->group_id ][ (int) $lang->id ];
				}
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $map;
	}

	/**
	 * Preload translation texts.
	 *
	 * @param array<int,object> $strings Strings.
	 * @param array<int,object> $languages Languages.
	 * @return array<string,string>
	 */
	private function preload_translation_texts( array $strings, array $languages ): array {
		if ( empty( $strings ) || empty( $languages ) ) {
			return [];
		}

		global $wpdb;

		$string_ids   = array_values( array_unique( array_map( static fn( $s ): int => (int) $s->id, $strings ) ) );
		$language_ids = array_values( array_unique( array_map( static fn( $l ): int => (int) $l->id, $languages ) ) );

		$string_ids   = array_filter( $string_ids, static fn( int $id ): bool => $id > 0 );
		$language_ids = array_filter( $language_ids, static fn( int $id ): bool => $id > 0 );

		if ( $string_ids === [] || $language_ids === [] ) {
			return [];
		}

		$st_table = \PerfLocale\Database\Schema::table( 'string_translations' );

		// Single cross-product query against the dedicated table - one index
		// seek on the (string_id, language_id) PRIMARY KEY per matching row.
		$s_placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );
		$l_placeholders = implode( ',', array_fill( 0, count( $language_ids ), '%d' ) );

		$args = array_merge( $string_ids, $language_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT string_id, language_id, translation, extra_forms FROM %i WHERE string_id IN ({$s_placeholders}) AND language_id IN ({$l_placeholders}) AND translation != ''",
				$st_table,
				...$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$map             = [];
		$this->extra_map = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key         = (int) $row->string_id . '_' . (int) $row->language_id;
				$map[ $key ] = (string) $row->translation;

				if ( isset( $row->extra_forms ) && $row->extra_forms !== null && $row->extra_forms !== '' ) {
					$decoded = json_decode( (string) $row->extra_forms, true );

					if ( is_array( $decoded ) && $decoded !== [] ) {
						$this->extra_map[ $key ] = array_map( 'strval', $decoded );
					}
				}
			}
		}

		return $map;
	}

	/**

	/**
	 * Get available domains.
	 *
	 * @param CacheManager $cache Cache — accepted for signature parity with
	 *                           sibling getters; the result is short-lived
	 *                           page-render data, not memoized here.
	 * @return array<int,string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature parity with sibling page-data getters.
	private function get_available_domains( CacheManager $cache ): array {
		// Cache like the sibling get_available_contexts(): this runs on every
		// Strings admin page load, and the domain set changes rarely. 5-minute
		// object-cache TTL (same eventual-consistency contract as contexts).
		// Generation-folded key so a perflocale_strings bump (strings save /
		// import / GC / MT) actually invalidates the filter dropdown, not just
		// the 5-min TTL.
		$domains_key = \PerfLocale\Cache\CacheManager::l2_key( 'available_domains', 'perflocale_strings' );
		$cached      = wp_cache_get( $domains_key, 'perflocale_strings' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$table = \PerfLocale\Database\Schema::table( 'strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT domain FROM %i ORDER BY domain ASC',
				$table
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $result ?: [];

		wp_cache_set( $domains_key, $result, 'perflocale_strings', 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Get distinct context values from the strings table.
	 *
	 * Cached in the 'perflocale_strings' object-cache group under a
	 * generation-folded key (invalidated by any perflocale_strings generation
	 * bump) with a 5-minute TTL fallback for new contexts arriving
	 * via the scanner — the filter dropdown tolerating a ≤5-min-stale
	 * context list is a fine trade for skipping the DISTINCT scan on
	 * every page load.
	 *
	 * @param CacheManager $cache Cache — accepted for signature parity with
	 *                           sibling getters; not memoized here.
	 * @return array<int,string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature parity with sibling page-data getters.
	private function get_available_contexts( CacheManager $cache ): array {
		$contexts_key = \PerfLocale\Cache\CacheManager::l2_key( 'available_contexts', 'perflocale_strings' );
		$cached       = wp_cache_get( $contexts_key, 'perflocale_strings' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$table = \PerfLocale\Database\Schema::table( 'strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT context FROM %i WHERE context != '' ORDER BY context ASC",
				$table
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $result ?: [];

		wp_cache_set( $contexts_key, $result, 'perflocale_strings', 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Truncate path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function truncate_path( string $path ): string {
		$parts = explode( '/', $path );

		return count( $parts ) <= 2 ? $path : '.../' . implode( '/', array_slice( $parts, -2 ) );
	}

	/**
	 * Get the path from wp-content for hover tooltip.
	 *
	 * @param string $path Full relative path from ABSPATH.
	 * @return string Path from wp-content/.
	 */
	private function full_path( string $path ): string {
		// Derive the marker from WP_CONTENT_DIR rather than assuming the
		// directory is named `wp-content` — it is renameable, and on the
		// "WordPress in its own directory" layout the stored path is not
		// ABSPATH-relative at all.
		$marker = basename( rtrim( (string) WP_CONTENT_DIR, '/\\' ) ) . '/';
		$pos    = strpos( $path, $marker );

		return $pos !== false ? substr( $path, $pos ) : $path;
	}

	/**
	 * Look up existing .mo file translations as placeholder hints.
	 *
	 * For each string × language, check if the .mo / .l10n.php files
	 * already have a translation. Returns a map of "{string_id}_{lang_id}" → translated text.
	 * These are shown as input placeholders so the admin can see what
	 * translation already ships with the plugin/theme.
	 *
	 * @param array<int, object> $strings Strings on the current page.
	 * @param array<int, object> $languages Active languages.
	 * @return array<string, string> Key: "{string_id}_{lang_id}", Value: .mo translation.
	 */
	private function get_mo_translation_hints( array $strings, array $languages ): array {
		$hints = [];

		if ( empty( $strings ) || empty( $languages ) ) {
			return $hints;
		}

		$domains_used = [];

		foreach ( $strings as $s ) {
			$domains_used[ $s->domain ] = true;
		}

		foreach ( $languages as $lang ) {
			if ( empty( $lang->locale ) ) {
				continue;
			}

			// switch_to_locale() returns false when already in that locale.
			// Translations for the current locale are already loaded, so
			// we can still look them up - just skip restore afterward.
			$switched = switch_to_locale( $lang->locale );

			foreach ( $strings as $s ) {
				$original = $s->original;
				$context  = $s->context ?? '';

				// For _n() strings, context is 'singular' or 'plural' - these
				// are PerfLocale markers, not real gettext contexts.
				if ( $context === '' || $context === 'singular' || $context === 'plural' ) {
					$translated = translate( $original, $s->domain ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Translation plugin must call translate() with dynamic text/domain to show MO hints.
				} elseif ( ! str_ends_with( $context, '(plural)' ) ) {
					$translated = translate_with_gettext_context( $original, $context, $s->domain ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralContext, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Translation plugin must call translate_with_gettext_context() with dynamic text/domain/context to show MO hints.
				} else {
					$translated = translate( $original, $s->domain ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Translation plugin must call translate() with dynamic text/domain to show MO hints.
				}

				if ( $translated !== $original ) {
					$hints[ $s->id . '_' . $lang->id ] = $translated;
				}
			}

			if ( $switched ) {
				restore_previous_locale();
			}
		}

		// For the source language (e.g. EN), the original text IS the translation.
		// Show it as a hint so admins don't think a translation is missing.
		$admin_locale = get_locale();

		foreach ( $languages as $lang ) {
			if ( ! empty( $lang->locale ) && $lang->locale === $admin_locale ) {
				foreach ( $strings as $s ) {
					$key = $s->id . '_' . $lang->id;

					if ( ! isset( $hints[ $key ] ) ) {
						$hints[ $key ] = $s->original;
					}
				}
			}
		}

		return $hints;
	}
}
