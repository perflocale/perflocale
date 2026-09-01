<?php
/**
 * Languages admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Helper;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the Languages admin page.
 */
final class LanguagesPage {

	/**
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$plugin     = Plugin::get_instance();
		$cache      = $plugin->get( 'cache' );
		$this->repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
	}

	/**
	 * Render the languages page.
	 *
	 * @return void
	 */
	public function render(): void {
		// Form processing is handled by AdminController::process_language_forms()
		// on admin_init (before output), so we only render here.

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';

		match ( $action ) {
			'add' => $this->render_form(),
			'edit' => $this->render_form( $this->get_edit_language() ),
			'delete' => $this->render_delete_preview( $this->get_edit_language() ),
			default => $this->render_list(),
		};
	}

	/**
	 * Render the delete-preview screen: exactly what the cascade will
	 * remove, BEFORE anything is touched. The destructive step moved to
	 * action=confirm-delete (AdminController), so landing here — even
	 * from an old bookmark of the previous action=delete URL — never
	 * deletes anything.
	 *
	 * @param object|null $language Language row, or null when not found.
	 * @return void
	 */
	private function render_delete_preview( ?object $language ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview; the nonce gate below is belt-and-braces, the destructive step re-verifies its own nonce in AdminController.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $language || ! wp_verify_nonce( $nonce, 'perflocale_delete_language' ) || ! current_user_can( 'manage_options' ) ) {
			$this->render_list();
			return;
		}

		if ( ! empty( $language->is_default ) ) {
			$this->render_list();
			return;
		}

		$counts = $this->repo->count_cascade( (int) $language->id );
		$total  = array_sum( $counts );

		$labels = [
			'translation_links'   => __( 'Translation links (posts/terms unlinked from their translation groups — the posts themselves are NOT deleted)', 'perflocale' ),
			'translation_groups'  => __( 'Translation groups left empty (garbage-collected)', 'perflocale' ),
			'string_translations' => __( 'String translations', 'perflocale' ),
			'slug_translations'   => __( 'Slug translations', 'perflocale' ),
		];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Delete Language', 'perflocale' ); ?></h1>

			<div class="notice notice-warning">
				<p>
					<strong>
						<?php
						printf(
							/* translators: 1: language name, 2: language slug. */
							esc_html__( 'You are about to permanently delete %1$s (%2$s).', 'perflocale' ),
							esc_html( (string) $language->name ),
							esc_html( (string) $language->slug )
						);
						?>
					</strong>
					<?php echo esc_html__( 'This cannot be undone. The rows below are removed in a single transaction — if any step fails, nothing is deleted.', 'perflocale' ); ?>
				</p>
			</div>

			<table class="widefat striped" style="max-width: 760px;">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Rows that will be permanently deleted', 'perflocale' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Data', 'perflocale' ); ?></th>
						<th scope="col" style="width: 120px; text-align: right;"><?php echo esc_html__( 'Rows', 'perflocale' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $counts as $key => $count ) : ?>
						<tr>
							<td><?php echo esc_html( $labels[ $key ] ?? $key ); ?></td>
							<td style="text-align: right;"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Total rows', 'perflocale' ); ?></th>
						<th style="text-align: right;"><?php echo esc_html( number_format_i18n( $total ) ); ?></th>
					</tr>
				</tfoot>
			</table>

			<p style="margin-top: 16px;">
				<a href="
				<?php
				echo esc_url(
					wp_nonce_url(
						admin_url( 'admin.php?page=perflocale-languages&action=confirm-delete&language_id=' . absint( $language->id ) ),
						'perflocale_delete_language'
					)
				);
				?>
				"
				class="button button-primary button-link-delete"
				data-perflocale-confirm="<?php echo esc_attr__( 'Permanently delete this language and every row listed? This cannot be undone.', 'perflocale' ); ?>">
					<?php
					printf(
						/* translators: %s: formatted row count. */
						esc_html__( 'Delete language and %s rows', 'perflocale' ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>" class="button" style="margin-left: 8px;">
					<?php echo esc_html__( 'Cancel', 'perflocale' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Get language being edited.
	 *
	 * @return object|null
	 */
	private function get_edit_language(): ?object {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$language_id = isset( $_GET['language_id'] ) ? absint( $_GET['language_id'] ) : 0;

		return $language_id > 0 ? $this->repo->find( $language_id ) : null;
	}

	/**
	 * Render the language list.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$all_languages = $this->repo->find_all();
		$total_items   = count( $all_languages );

		// Pagination.
		$per_page = $this->get_per_page();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_num    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset      = ( $page_num - 1 ) * $per_page;
		$languages   = array_slice( $all_languages, $offset, $per_page );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
		?>
		<div class="wrap perflocale-languages">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Languages', 'perflocale' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages&action=add' ) ); ?>" class="page-title-action">
				<?php echo esc_html__( 'Add New', 'perflocale' ); ?>
			</a>
			<hr class="wp-header-end" style="margin-bottom: 16px;">

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

			<?php $this->render_admin_notice( $message ); ?>
			<?php $this->render_bare_default_notice( $all_languages ); ?>

			<?php if ( empty( $all_languages ) ) : ?>
				<div class="perflocale-lang-empty">
					<p><?php echo esc_html__( 'No languages configured yet. Add your first language to get started.', 'perflocale' ); ?></p>
				</div>
			<?php else : ?>

				<?php $this->render_pagination( $page_num, $total_pages, $total_items ); ?>

				<div class="perflocale-lang-list"
					data-perflocale-reorderable
					data-offset="<?php echo esc_attr( (string) $offset ); ?>">
					<?php
					foreach ( $languages as $language ) :
						$flag       = Helper::get_flag_emoji( $language );
						$edit_url   = admin_url( 'admin.php?page=perflocale-languages&action=edit&language_id=' . absint( $language->id ) );
						$is_default = (bool) $language->is_default;
						?>
						<div class="perflocale-lang-item<?php echo $is_default ? ' perflocale-lang-item--default' : ''; ?><?php echo ! $language->is_active ? ' perflocale-lang-item--inactive' : ''; ?>"
							data-language-id="<?php echo absint( $language->id ); ?>">
							<button type="button" class="perflocale-lang-item__handle"
								aria-label="<?php echo esc_attr__( 'Drag, or press arrow up/down, to reorder', 'perflocale' ); ?>"
								title="<?php echo esc_attr__( 'Drag, or press arrow up/down, to reorder', 'perflocale' ); ?>">
								<svg width="10" height="16" viewBox="0 0 10 16" fill="none" aria-hidden="true">
									<circle cx="2" cy="3"  r="1.2" fill="currentColor"/>
									<circle cx="8" cy="3"  r="1.2" fill="currentColor"/>
									<circle cx="2" cy="8"  r="1.2" fill="currentColor"/>
									<circle cx="8" cy="8"  r="1.2" fill="currentColor"/>
									<circle cx="2" cy="13" r="1.2" fill="currentColor"/>
									<circle cx="8" cy="13" r="1.2" fill="currentColor"/>
								</svg>
							</button>
							<div class="perflocale-lang-item__flag"><?php echo esc_html( $flag ); ?></div>
							<div class="perflocale-lang-item__info">
								<div class="perflocale-lang-item__title">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $language->name ); ?></a>
									<?php if ( $is_default ) : ?>
										<span class="perflocale-lang-item__badge"><?php echo esc_html__( 'Default', 'perflocale' ); ?></span>
									<?php endif; ?>
									<?php if ( ! $language->is_active ) : ?>
										<span class="perflocale-lang-item__badge perflocale-lang-item__badge--gray"><?php echo esc_html__( 'Inactive', 'perflocale' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="perflocale-lang-item__meta">
									<?php
									// bdi + lang: an RTL native name ("العربية")
									// printed bare inside this LTR admin line
									// garbles the adjacent "·"/code punctuation;
									// bdi isolates its directionality and lang
									// lets screen readers switch pronunciation.
									?>
									<bdi lang="<?php echo esc_attr( str_replace( '_', '-', (string) $language->locale ) ); ?>"><?php echo esc_html( $language->native_name ); ?></bdi>
									&middot; <code><?php echo esc_html( $language->slug ); ?></code>
									&middot; <code><?php echo esc_html( $language->locale ); ?></code>
									<?php if ( $language->text_direction === 'rtl' ) : ?>
										&middot; RTL
									<?php endif; ?>
								</div>
							</div>
							<div class="perflocale-lang-item__actions">
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php echo esc_html__( 'Edit', 'perflocale' ); ?></a>
								<?php if ( ! $is_default ) : ?>
									<?php if ( $language->is_active ) : ?>
										<a href="
										<?php
										echo esc_url(
											wp_nonce_url(
												admin_url( 'admin.php?page=perflocale-languages&action=set_default&language_id=' . absint( $language->id ) ),
												'perflocale_set_default_language'
											)
										);
										?>
										"
										class="button button-small"
										data-perflocale-confirm="<?php echo esc_attr__( 'Set this as the default language? All new content will default to this language.', 'perflocale' ); ?>">
											<?php echo esc_html__( 'Set as Default', 'perflocale' ); ?>
										</a>
									<?php else : ?>
										<?php
										// An inactive language cannot be promoted:
										// get_default() reads the active-only
										// bootstrap, so LanguageRepository::
										// set_default() refuses it outright. Offer
										// the action as visibly unavailable rather
										// than hiding it — the operator can see the
										// button exists and what unlocks it.
										?>
										<button type="button" class="button button-small" disabled
											title="<?php echo esc_attr__( 'Activate this language before making it the default.', 'perflocale' ); ?>">
											<?php echo esc_html__( 'Set as Default', 'perflocale' ); ?>
										</button>
									<?php endif; ?>
									<a href="
									<?php
									echo esc_url(
										wp_nonce_url(
											admin_url( 'admin.php?page=perflocale-languages&action=delete&language_id=' . absint( $language->id ) ),
											'perflocale_delete_language'
										)
									);
									?>
									"
									class="button button-small button-link-delete">
										<?php echo esc_html__( 'Delete', 'perflocale' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="perflocale-lang-list__status" aria-live="polite" data-perflocale-reorder-status></div>

				<?php $this->render_pagination( $page_num, $total_pages, $total_items ); ?>

			<?php endif; ?>
		</div>
		<?php
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
	 * Render pagination.
	 *
	 * @param int $current Current page.
	 * @param int $total_pages Total pages.
	 * @param int $total_items Total items.
	 * @return void
	 */
	private function render_pagination( int $current, int $total_pages, int $total_items ): void {
		if ( $total_pages < 2 ) {
			return;
		}

		$page_links = paginate_links(
			[
				'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php' ) ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => $current,
				'add_args'  => [ 'page' => 'perflocale-languages' ],
			]
		);

		echo '<div class="perflocale-pagination">';
		/* translators: %s: Number of languages */
		echo '<span class="perflocale-pagination__count">' . esc_html( sprintf( _n( '%s language', '%s languages', $total_items, 'perflocale' ), number_format_i18n( $total_items ) ) ) . '</span>';

		if ( $page_links ) {
			echo wp_kses_post( '<span class="perflocale-pagination__links">' . $page_links . '</span>' );
		}

		echo '</div>';
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param object|null $language Existing language or null.
	 * @return void
	 */
	private function render_form( ?object $language = null ): void {
		$is_edit        = $language !== null;
		$title          = $is_edit ? __( 'Edit Language', 'perflocale' ) : __( 'Add Language', 'perflocale' );
		$slug           = $is_edit ? $language->slug : '';
		$locale         = $is_edit ? $language->locale : '';
		$name           = $is_edit ? $language->name : '';
		$native_name    = $is_edit ? $language->native_name : '';
		$flag           = $is_edit ? $language->flag : '';
		$text_direction = $is_edit ? $language->text_direction : 'ltr';
		$date_format    = $is_edit ? (string) ( $language->date_format ?? '' ) : '';
		$time_format    = $is_edit ? (string) ( $language->time_format ?? '' ) : '';
		$is_active      = $is_edit ? (bool) $language->is_active : true;
		$is_default     = $is_edit && ! empty( $language->is_default );

		// Load predefined languages for the quick-select.
		$predefined = [];

		if ( ! $is_edit ) {
			$predefined = require PERFLOCALE_DIR . 'data/languages.php';

			/**
			 * Filter the bundled list of predefined languages shown in the
			 * "Add Language" quick-select. Use this to add custom languages
			 * (e.g. constructed languages, internal locale variants) or to
			 * replace / prune the bundled set entirely.
			 *
			 * Each entry is an associative array with the following keys:
			 *   - `slug`           string, max 10 chars, must be unique
			 *   - `locale`         string, max 20 chars, must be unique
			 *   - `name`           string, English display name
			 *   - `native_name`    string, native-script display name
			 *   - `flag`           string, ISO 3166-1 alpha-2 country code
			 *   - `text_direction` string, 'ltr' or 'rtl'
			 *   - `date_format`    string, PHP date() format
			 *   - `time_format`    string, PHP date() format
			 *
			 * @hook perflocale/predefined_languages
			 *
			 * @param array<int, array<string, string>> $predefined Bundled
			 *   languages as loaded from `data/languages.php` (194 entries
			 *   in 1.0.0).
			 */
			$predefined = (array) apply_filters( 'perflocale/predefined_languages', $predefined );
		}

		$flag_preview = '';

		if ( $is_edit ) {
			$flag_preview = Helper::get_flag_emoji( $language );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
		?>
		<div class="wrap perflocale-languages">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<hr class="wp-header-end">

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

			<?php $this->render_admin_notice( $message ); ?>

			<div class="perflocale-lang-form-wrap">
				<?php if ( ! $is_edit && ! empty( $predefined ) ) : ?>
					<?php
					// Sort: popular languages first (curated by real-world
					// usage frequency on WP installs), then alphabetical
					// by english name. Slugs in `$popular_slugs` keep
					// their declared order. The combobox JS reads each
					// row's `data-popular="1"` marker to render a section
					// divider between the two groups.
					$popular_slugs = [
						'en',
						'en-gb',
						'fr',
						'de',
						'es',
						'it',
						'pt-br',
						'pt',
						'zh-cn',
						'zh-tw',
						'ja',
						'ko',
						'ar',
						'ru',
						'hi',
						'nl',
						'pl',
						'tr',
						'sv',
					];
					$popular_order = array_flip( $popular_slugs );

					$popular = [];
					$rest    = [];

					foreach ( $predefined as $pl ) {
						$slug = (string) ( $pl['slug'] ?? '' );
						if ( isset( $popular_order[ $slug ] ) ) {
							$popular[ $popular_order[ $slug ] ] = $pl;
						} else {
							$rest[] = $pl;
						}
					}

					ksort( $popular );
					$popular = array_values( $popular );

					usort( $rest, static fn( $a, $b ) => strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ) );

					$render_option = static function ( array $pl, bool $is_popular, int $index ): string {
						$flag       = (string) ( $pl['flag'] ?? '' );
						$flag_emoji = '';

						if ( $flag !== '' && strlen( $flag ) === 2 && ctype_alpha( $flag ) ) {
							// Regional-indicator codepoints U+1F1E6–U+1F1FF are a
							// contiguous 4-byte UTF-8 block (leading bytes F0 9F 87);
							// 'A' (0x41) maps to U+1F1E6, so 'us' becomes 🇺🇸. Encoded
							// directly to avoid depending on mb_chr(), which has no
							// WP core polyfill and would fatal on a no-mbstring host.
							$flag_emoji = "\xF0\x9F\x87" . chr( 0xA6 + ord( strtoupper( $flag[0] ) ) - 0x41 )
								. "\xF0\x9F\x87" . chr( 0xA6 + ord( strtoupper( $flag[1] ) ) - 0x41 );
						}

						return sprintf(
							'<li class="perflocale-combo__option" role="option" aria-selected="false"' .
							' id="perflocale-combo-opt-%d"' .
							' data-slug="%s" data-locale="%s" data-name="%s" data-native="%s"' .
							' data-flag="%s" data-dir="%s" data-date-format="%s" data-time-format="%s"%s>' .
							'<span class="perflocale-combo__flag" aria-hidden="true">%s</span>' .
							'<span class="perflocale-combo__label"><strong>%s</strong> <span class="perflocale-combo__native">%s</span></span>' .
							'<span class="perflocale-combo__locale">%s</span>' .
							'</li>',
							$index,
							esc_attr( (string) $pl['slug'] ),
							esc_attr( (string) $pl['locale'] ),
							esc_attr( (string) $pl['name'] ),
							esc_attr( (string) $pl['native_name'] ),
							esc_attr( $flag ),
							esc_attr( (string) $pl['text_direction'] ),
							esc_attr( (string) ( $pl['date_format'] ?? '' ) ),
							esc_attr( (string) ( $pl['time_format'] ?? '' ) ),
							$is_popular ? ' data-popular="1"' : '',
							$flag_emoji !== '' ? esc_html( $flag_emoji ) : '🌐',
							esc_html( (string) $pl['name'] ),
							esc_html( (string) $pl['native_name'] ),
							esc_html( (string) $pl['locale'] )
						);
					};
	?>
					<div class="perflocale-lang-picker">
						<label for="perflocale-combo-input"><?php echo esc_html__( 'Quick select a language', 'perflocale' ); ?></label>
						<div class="perflocale-combo" data-perflocale-combo>
							<input
								id="perflocale-combo-input"
								type="text"
								class="perflocale-combo__input"
								placeholder="<?php echo esc_attr__( 'Search by name, locale, or country code…', 'perflocale' ); ?>"
								autocomplete="off"
								role="combobox"
								aria-controls="perflocale-combo-list"
								aria-autocomplete="list"
								aria-haspopup="listbox"
								aria-expanded="false">
							<ul
								id="perflocale-combo-list"
								class="perflocale-combo__list"
								role="listbox"
								hidden>
								<?php $opt_index = 0; ?>
								<?php if ( ! empty( $popular ) ) : ?>
									<li class="perflocale-combo__group-label" role="presentation"><?php echo esc_html__( 'Popular', 'perflocale' ); ?></li>
									<?php foreach ( $popular as $pl ) : ?>
										<?php echo wp_kses_post( $render_option( $pl, true, $opt_index++ ) ); ?>
									<?php endforeach; ?>
								<?php endif; ?>

								<?php if ( ! empty( $rest ) ) : ?>
									<li class="perflocale-combo__group-label" role="presentation"><?php echo esc_html__( 'All languages (alphabetical)', 'perflocale' ); ?></li>
									<?php foreach ( $rest as $pl ) : ?>
										<?php echo wp_kses_post( $render_option( $pl, false, $opt_index++ ) ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</ul>
							<div class="perflocale-combo__status" role="status" aria-live="polite"></div>
						</div>

						<noscript>
							<select id="perflocale-preset" class="perflocale-lang-picker__select" style="margin-top:8px;">
								<option value=""><?php echo esc_html__( 'Choose a language...', 'perflocale' ); ?></option>
								<?php foreach ( array_merge( $popular, $rest ) as $pl ) : ?>
									<option
										value="<?php echo esc_attr( $pl['slug'] ); ?>"
										data-locale="<?php echo esc_attr( $pl['locale'] ); ?>"
										data-name="<?php echo esc_attr( $pl['name'] ); ?>"
										data-native="<?php echo esc_attr( $pl['native_name'] ); ?>"
										data-flag="<?php echo esc_attr( $pl['flag'] ); ?>"
										data-dir="<?php echo esc_attr( $pl['text_direction'] ); ?>"
										data-date-format="<?php echo esc_attr( $pl['date_format'] ?? '' ); ?>"
										data-time-format="<?php echo esc_attr( $pl['time_format'] ?? '' ); ?>">
										<?php echo esc_html( $pl['name'] . ' - ' . $pl['native_name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</noscript>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>" class="perflocale-lang-form" autocomplete="off">
					<?php wp_nonce_field( 'perflocale_save_language' ); ?>
					<input type="hidden" name="perflocale_language_action" value="<?php echo esc_attr( $is_edit ? 'edit' : 'add' ); ?>">
					<?php if ( $is_edit ) : ?>
						<input type="hidden" name="language_id" value="<?php echo absint( $language->id ); ?>">
					<?php endif; ?>

					<div class="perflocale-lang-form__grid">
						<div class="perflocale-lang-form__field">
							<label for="perflocale-slug"><?php echo esc_html__( 'Slug', 'perflocale' ); ?> <span class="required">*</span></label>
							<input type="text" id="perflocale-slug" name="slug" value="<?php echo esc_attr( $slug ); ?>" required aria-required="true" maxlength="10" pattern="[a-z0-9\-]+" placeholder="en" autocomplete="off">
							<span class="perflocale-lang-form__hint"><?php echo esc_html__( 'URL identifier (e.g. "en", "fr", "de")', 'perflocale' ); ?></span>
						</div>

						<div class="perflocale-lang-form__field">
							<label for="perflocale-locale"><?php echo esc_html__( 'Locale', 'perflocale' ); ?> <span class="required">*</span></label>
							<input type="text" id="perflocale-locale" name="locale" value="<?php echo esc_attr( $locale ); ?>" required aria-required="true" maxlength="20" placeholder="en_US" autocomplete="off">
							<span class="perflocale-lang-form__hint"><?php echo esc_html__( 'WordPress locale (e.g. "en_US", "fr_FR")', 'perflocale' ); ?></span>
						</div>

						<div class="perflocale-lang-form__field">
							<label for="perflocale-name"><?php echo esc_html__( 'English Name', 'perflocale' ); ?> <span class="required">*</span></label>
							<input type="text" id="perflocale-name" name="name" value="<?php echo esc_attr( $name ); ?>" required aria-required="true" maxlength="100" placeholder="English" autocomplete="off">
						</div>

						<div class="perflocale-lang-form__field">
							<label for="perflocale-native-name"><?php echo esc_html__( 'Native Name', 'perflocale' ); ?> <span class="required">*</span></label>
							<input type="text" id="perflocale-native-name" name="native_name" value="<?php echo esc_attr( $native_name ); ?>" required aria-required="true" maxlength="100" placeholder="English" autocomplete="off">
						</div>

						<div class="perflocale-lang-form__field perflocale-lang-form__field--half">
							<label for="perflocale-flag">
								<?php echo esc_html__( 'Flag Override', 'perflocale' ); ?>
								<span id="perflocale-flag-preview" style="font-size: 20px; vertical-align: middle; margin-left: 4px;"><?php echo esc_html( $flag_preview ); ?></span>
							</label>
							<input type="text" id="perflocale-flag" name="flag" value="<?php echo esc_attr( $flag ); ?>" maxlength="10" placeholder="<?php echo esc_attr__( 'Auto from locale', 'perflocale' ); ?>" autocomplete="off">
							<span class="perflocale-lang-form__hint"><?php echo esc_html__( 'Optional. Country code (e.g. "us"). Auto-detected from locale if empty.', 'perflocale' ); ?></span>
						</div>

						<div class="perflocale-lang-form__field perflocale-lang-form__field--half">
							<label for="perflocale-text-direction"><?php echo esc_html__( 'Text Direction', 'perflocale' ); ?></label>
							<select id="perflocale-text-direction" name="text_direction">
								<option value="ltr" <?php selected( $text_direction, 'ltr' ); ?>><?php echo esc_html__( 'LTR (Left to Right)', 'perflocale' ); ?></option>
								<option value="rtl" <?php selected( $text_direction, 'rtl' ); ?>><?php echo esc_html__( 'RTL (Right to Left)', 'perflocale' ); ?></option>
							</select>
						</div>

						<div class="perflocale-lang-form__field perflocale-lang-form__field--half">
							<label for="perflocale-date-format"><?php echo esc_html__( 'Date Format', 'perflocale' ); ?></label>
							<input type="text" id="perflocale-date-format" name="date_format" value="<?php echo esc_attr( $date_format ); ?>" maxlength="50" placeholder="<?php echo esc_attr( get_option( 'date_format', 'F j, Y' ) ); ?>">
							<span class="perflocale-lang-form__hint"><?php echo esc_html__( 'Optional. PHP date format used when rendering dates in this language. Falls back to the site default.', 'perflocale' ); ?></span>
						</div>

						<div class="perflocale-lang-form__field perflocale-lang-form__field--half">
							<label for="perflocale-time-format"><?php echo esc_html__( 'Time Format', 'perflocale' ); ?></label>
							<input type="text" id="perflocale-time-format" name="time_format" value="<?php echo esc_attr( $time_format ); ?>" maxlength="50" placeholder="<?php echo esc_attr( get_option( 'time_format', 'g:i a' ) ); ?>">
							<span class="perflocale-lang-form__hint"><?php echo esc_html__( 'Optional. PHP time format used when rendering times in this language. Falls back to the site default.', 'perflocale' ); ?></span>
						</div>
					</div>

					<div class="perflocale-lang-form__toggle">
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $is_active ); ?><?php echo $is_default ? ' checked disabled' : ''; ?>>
							<?php echo esc_html__( 'Active - enable this language on the site', 'perflocale' ); ?>
							<?php if ( $is_default ) : ?>
								<input type="hidden" name="is_active" value="1">
							<?php endif; ?>
						</label>
					</div>
					<?php if ( $is_edit ) : ?>
					<div class="perflocale-lang-form__toggle">
						<label>
							<input type="checkbox" name="is_default" value="1" <?php checked( $is_default ); ?><?php echo $is_default ? ' disabled' : ''; ?>>
							<?php echo esc_html__( 'Default language - the primary language of the site', 'perflocale' ); ?>
							<?php if ( $is_default ) : ?>
								<input type="hidden" name="is_default" value="1">
							<?php endif; ?>
						</label>
						<?php if ( $is_default ) : ?>
							<p class="description" style="margin-left: 24px;"><?php echo esc_html__( 'This is the current default language. To change the default, set another language as default.', 'perflocale' ); ?></p>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<?php
					// Pre-flight rename nudge — extended in v… to cover ALL
					// active bare-language slugs whose locale exposes a
					// region-qualified upgrade target, not just the default.
					// A non-default `ar` next to `ar-MA` benefits from the
					// rename nudge identically to a default `en` next to
					// `en-GB`, so we render one checkbox per candidate and
					// show whichever one matches the slug the user is
					// typing into the form (prefix match: `ar-ma` → `ar`).
					$rename_candidates = [];

					if ( ! $is_edit ) {
						// Use the form-side detector: render checkboxes for
						// every active bare language whose locale exposes a
						// rename target. No "existing region-qualified
						// sibling" requirement — at form-render time the
						// user is precisely about to ADD that sibling.
						$rename_candidates = Helper::bare_language_rename_candidates_for_form(
							$this->repo->get_active()
						);
					}
					?>
					<?php if ( ! empty( $rename_candidates ) ) : ?>
						<?php
						foreach ( $rename_candidates as $cand ) :
							$cand_lang   = $cand['language'];
							$cand_slug   = (string) $cand_lang->slug;
							$cand_target = (string) $cand['suggested'];
							?>
							<div class="perflocale-lang-form__field perflocale-lang-form__rename"
								style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:10px 12px;margin:8px 0;display:none;"
								data-perflocale-rename-prefix="<?php echo esc_attr( $cand_slug ); ?>">
								<label style="display:block;cursor:pointer;font-weight:400;">
									<input type="checkbox" name="perflocale_rename[<?php echo esc_attr( $cand_slug ); ?>]" value="<?php echo esc_attr( $cand_target ); ?>" style="margin-right:6px;">
									<?php
									printf(
										/* translators: 1: current bare slug, 2: suggested rename target */
										esc_html__( 'Rename %1$s to %2$s for visual symmetry. Existing %1$s URLs will 301-redirect to %2$s.', 'perflocale' ),
										'<code>' . esc_html( $cand_slug ) . '</code>',
										'<code>' . esc_html( $cand_target ) . '</code>'
									);
									?>
								</label>
								<p class="description" style="margin:6px 0 0 24px;font-size:11px;">
									<?php
									if ( ! empty( $cand_lang->is_default ) ) {
										echo esc_html__( 'Optional. Skip if your default language is intentionally region-unspecified.', 'perflocale' );
									} else {
										echo esc_html__( 'Optional. Skip if this language is intentionally region-unspecified.', 'perflocale' );
									}
									?>
								</p>
							</div>
						<?php endforeach; ?>
						<?php
						// JS: when the user types a region-qualified slug
						// (`ar-ma`), reveal the checkbox whose
						// data-perflocale-rename-prefix matches the
						// language part (everything before the first `-`).
						// Other bare-language candidates stay hidden so
						// the form only nudges about the directly relevant
						// rename — surfacing all candidates at once would
						// feel pushy.
						wp_add_inline_script(
							'perflocale-admin',
							'(function(){' .
								'var slug=document.getElementById("perflocale-slug");' .
								'var boxes=document.querySelectorAll("[data-perflocale-rename-prefix]");' .
								'if(!slug||!boxes.length)return;' .
								'function update(){' .
									'var v=(slug.value||"").toLowerCase();' .
									'var dash=v.indexOf("-");' .
									'var prefix=dash>0?v.slice(0,dash):"";' .
									'boxes.forEach(function(b){' .
										'b.style.display=prefix && b.getAttribute("data-perflocale-rename-prefix")===prefix?"block":"none";' .
										// Reset checkbox when hidden so an
										// unticked-but-stale state can\'t
										// be submitted by accident.
										'if(b.style.display==="none"){' .
											'var cb=b.querySelector(\'input[type="checkbox"]\');' .
											'if(cb)cb.checked=false;' .
										'}' .
									'});' .
								'}' .
								'slug.addEventListener("input",update);' .
								'slug.addEventListener("change",update);' .
								'update();' .
							'})();'
						);
						?>
					<?php endif; ?>

					<div class="perflocale-lang-form__actions">
						<?php submit_button( $is_edit ? __( 'Save Changes', 'perflocale' ) : __( 'Add Language', 'perflocale' ), 'primary', 'submit', false ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>" class="button">
							<?php echo esc_html__( 'Cancel', 'perflocale' ); ?>
						</a>
					</div>
				</form>
			</div>
		</div>


		<?php
	}

	/**
	 * Render admin notice.
	 *
	 * @param string $message Message key.
	 * @return void
	 */
	private function render_admin_notice( string $message ): void {
		if ( $message === '' ) {
			return;
		}

		// Error-class messages (red, sticky) — duplicate-key collisions
		// caught by the AdminController before the wpdb->insert fires.
		if ( $message === 'duplicate_slug' || $message === 'duplicate_locale' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dup     = isset( $_GET['dup'] ) ? sanitize_text_field( wp_unslash( $_GET['dup'] ) ) : '';
			$is_slug = $message === 'duplicate_slug';

			$text = $is_slug
				? sprintf(
					/* translators: %s: slug that already exists */
					__( 'A language with the slug %s already exists. Pick a different slug — each language must have a unique URL identifier.', 'perflocale' ),
					'<code>' . esc_html( $dup ) . '</code>'
				)
				: sprintf(
					/* translators: %s: locale that already exists */
					__( 'A language with the locale %s already exists. Pick a different locale — each language must have a unique WordPress locale.', 'perflocale' ),
					'<code>' . esc_html( $dup ) . '</code>'
				);

			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses(
				$text,
				[ 'code' => [] ]
			) . '</p></div>';
			return;
		}

		// A slug the routing layer cannot express. update() refuses it before
		// writing the row, so the whole form was discarded — say so, and name
		// the value, rather than re-rendering the screen with nothing saved.
		if ( $message === 'invalid_slug' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bad = isset( $_GET['dup'] ) ? sanitize_text_field( wp_unslash( $_GET['dup'] ) ) : '';

			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses(
				sprintf(
					/* translators: %1$s: the rejected language slug, %2$s and %3$s: example slugs */
					__( 'The slug %1$s cannot be used in URLs, so nothing was saved. Use two to five lowercase letters, optionally with one region suffix — for example %2$s or %3$s.', 'perflocale' ),
					'<code>' . esc_html( $bad ) . '</code>',
					'<code>de</code>',
					'<code>pt-br</code>'
				),
				[ 'code' => [] ]
			) . '</p></div>';
			return;
		}

		// A delete that did not happen: LanguageRepository::delete() rolls its
		// cascade back and returns false, and the default language / a stale
		// delete URL for an already-removed id never reach it at all. All three
		// leave the site unchanged, so say so instead of reporting success.
		if ( $message === 'delete_failed' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__(
				'The language was not deleted and nothing on the site was changed. The default language cannot be removed, and the language may already be gone — reload this list, and check the error log if it is still here.',
				'perflocale'
			) . '</p></div>';
			return;
		}

		// A refused "Set as Default": LanguageRepository::set_default() rejects
		// a missing or inactive target, and the controller now honours that
		// return value instead of reporting success over a no-op.
		if ( $message === 'default_change_failed' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__(
				'The default language was not changed. Only an active language can be the default — activate it first, then set it as default.',
				'perflocale'
			) . '</p></div>';
			return;
		}

		$text = match ( $message ) {
			'added' => __( 'Language added successfully.', 'perflocale' ),
			'added_renamed' => __( 'Language added. Default language slug was renamed for visual consistency; old URLs now 301 to the new slug.', 'perflocale' ),
			'updated' => __( 'Language updated successfully.', 'perflocale' ),
			'deleted' => __( 'Language deleted successfully.', 'perflocale' ),
			'default_changed' => __( 'Default language changed successfully.', 'perflocale' ),
			default => '',
		};

		if ( $text === '' ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Surface an inline notice when the site default uses a bare-language
	 * slug (`en`) AND another active language is region-qualified (`en-gb`).
	 *
	 * Pure UX nudge: the renderer is correct per BCP 47, but the visual
	 * mismatch confuses users who expect symmetry. The notice points at
	 * the suggested rename and links to the Add Language form (where the
	 * pre-flight checkbox can also kick off the rename in one click).
	 *
	 * @param array<int, object> $languages All active languages.
	 * @return void
	 */
	private function render_bare_default_notice( array $languages ): void {
		$candidates = Helper::detect_bare_languages_with_region_siblings( $languages );

		if ( empty( $candidates ) ) {
			return;
		}

		$add_url = admin_url( 'admin.php?page=perflocale-languages&action=add' );
		?>
		<div class="notice notice-info is-dismissible perflocale-bare-default-notice">
			<p>
				<strong><?php echo esc_html__( 'Heads up:', 'perflocale' ); ?></strong>
				<?php echo esc_html__( 'Some active languages use bare slugs while others are region-qualified, which produces uneven badges and weaker hreflang. Consider renaming for visual symmetry:', 'perflocale' ); ?>
			</p>
			<ul style="margin:0 0 6px 22px;list-style:disc;">
				<?php
				foreach ( $candidates as $row ) :
					$slug      = (string) $row['language']->slug;
					$suggested = (string) $row['suggested'];
					$is_def    = ! empty( $row['language']->is_default );
					?>
					<li>
						<?php
						printf(
							/* translators: 1: current slug, 2: suggested slug */
							esc_html__( '%1$s → %2$s', 'perflocale' ),
							'<code>' . esc_html( $slug ) . '</code>',
							'<code>' . esc_html( $suggested ) . '</code>'
						);
						if ( $is_def ) {
							echo ' <em style="color:#646970;font-size:11px;">' . esc_html__( '(default)', 'perflocale' ) . '</em>';
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( $add_url ); ?>"><?php echo esc_html__( 'Renaming is offered when you next add a region-qualified language.', 'perflocale' ); ?></a>
			</p>
		</div>
		<?php
	}
}
