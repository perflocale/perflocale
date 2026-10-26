<?php
/**
 * Languages admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Admin\LanguagePacks;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Helper;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the Languages admin page.
 *
 * @phpstan-import-type Item from LanguagePacks
 * @phpstan-type Packs array{locales: array<string, array{core: string, offered: list<Item>}>}
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
		// AdminController::process_language_forms() handles the forms on admin_init, before output.

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
	 * Renders the delete preview: what the cascade would remove.
	 *
	 * Read-only: the delete is action=confirm-delete in AdminController, so this URL never deletes.
	 *
	 * @param object|null $language Language row, or null when not found.
	 * @return void
	 */
	private function render_delete_preview( ?object $language ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview; the nonce gate below is belt-and-braces, the destructive step re-verifies its own nonce in AdminController.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $language || ! wp_verify_nonce( $nonce, 'perflocale_delete_language' ) || ! current_user_can( 'perflocale_manage_languages' ) ) {
			$this->render_list();
			return;
		}

		if ( ! empty( $language->is_default ) ) {
			$this->render_list();
			return;
		}

		$counts   = $this->repo->count_cascade( (int) $language->id );
		$total    = array_sum( $counts );
		$blockers = $this->repo->delete_blockers( (int) $language->id );
		$blocked  = ! is_array( $blockers ) || $blockers['posts'] !== [];

		$labels = [
			'translation_links'   => __( 'Translation links (posts/terms unlinked from their translation groups - the posts themselves are NOT deleted)', 'perflocale' ),
			'translation_groups'  => __( 'Translation groups left empty (garbage-collected)', 'perflocale' ),
			'string_translations' => __( 'String translations', 'perflocale' ),
			'slug_translations'   => __( 'Slug translations', 'perflocale' ),
		];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Delete Language', 'perflocale' ); ?></h1>
			<hr class="wp-header-end">

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
					<?php echo esc_html__( 'This cannot be undone. The rows below are removed in a single transaction - if any step fails, nothing is deleted.', 'perflocale' ); ?>
				</p>
				<p><?php echo esc_html( $this->routing_loss_warning( $language, true ) ); ?></p>
			</div>

			<?php $this->render_delete_blockers( $language, $blockers ); ?>

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
				<?php if ( ! $blocked ) : ?>
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
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>" class="button" style="margin-left: 8px;">
					<?php echo esc_html__( 'Cancel', 'perflocale' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Explains what stops a delete, and what the delete would unlink anyway.
	 *
	 * Posts block it (delete_blockers()); terms, media and block templates are information only.
	 *
	 * @param object                                                                              $language Language row.
	 * @param array{posts: array<string, int>, attachments: int, terms: int, templates: int}|null $blockers delete_blockers() result.
	 * @return void
	 */
	private function render_delete_blockers( object $language, ?array $blockers ): void {
		if ( null === $blockers ) {
			echo '<div class="notice notice-error"><p>' . esc_html__(
				'The content of this language could not be checked, so it cannot be deleted right now. Reload this page to try again.',
				'perflocale'
			) . '</p></div>';
			return;
		}

		if ( $blockers['posts'] !== [] ) {
			$edit_url     = admin_url( 'admin.php?page=perflocale-languages&action=edit&language_id=' . absint( $language->id ) );
			$translatable = Plugin::get_instance()->get( 'settings' )->get_translatable_post_types();
			?>
			<div class="notice notice-error">
				<p><strong><?php echo esc_html__( 'This language cannot be deleted while it still has content:', 'perflocale' ); ?></strong></p>
				<ul class="ul-disc">
					<?php foreach ( $blockers['posts'] as $type => $count ) : ?>
						<li>
							<?php
							$summary  = Helper::post_type_counts_summary( [ $type => $count ] );
							$list_url = $this->blocker_list_url( (string) $type, (string) $language->slug, $translatable );
							?>
							<?php if ( $list_url !== '' ) : ?>
								<a href="<?php echo esc_url( $list_url ); ?>">
									<?php echo esc_html( $summary ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $summary ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p>
					<?php echo esc_html__( 'Move these to the Trash first, or deactivate the language instead: an inactive language keeps its translations linked, so shared stock and SKUs keep working.', 'perflocale' ); ?>
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Edit language', 'perflocale' ); ?></a>
				</p>
			</div>
			<?php
		}

		if ( $blockers['terms'] > 0 || $blockers['attachments'] > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of terms, 2: number of media items. */
					__( 'The delete also unlinks %1$s terms and %2$s media items from their translations. They are kept, and they do not block the delete.', 'perflocale' ),
					number_format_i18n( $blockers['terms'] ),
					number_format_i18n( $blockers['attachments'] )
				)
			) . '</p>';
		}

		if ( $blockers['templates'] > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of block theme templates and template parts. */
					_n(
						'The delete also unlinks %s block theme template or template part from its translations. It is kept, and it does not block the delete.',
						'The delete also unlinks %s block theme templates and template parts from their translations. They are kept, and they do not block the delete.',
						$blockers['templates'],
						'perflocale'
					),
					number_format_i18n( $blockers['templates'] )
				)
			) . '</p>';
		}
	}

	/**
	 * The post list a blocking post type links to, filtered to the language.
	 *
	 * '' when edit.php refuses the type (unregistered or show_ui false) or PostListColumns
	 * would not filter it (not translatable), which would list every language.
	 *
	 * @param string             $type         Post type.
	 * @param string             $lang_slug    Language slug.
	 * @param array<int, string> $translatable Translatable post types.
	 * @return string
	 */
	private function blocker_list_url( string $type, string $lang_slug, array $translatable ): string {
		$type_object = get_post_type_object( $type );

		if ( ! $type_object instanceof \WP_Post_Type || ! $type_object->show_ui || ! in_array( $type, $translatable, true ) ) {
			return '';
		}

		return add_query_arg(
			[
				'post_type'       => $type,
				'perflocale_lang' => $lang_slug,
			],
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Warning shown where a language's URLs are about to stop being routed.
	 *
	 * The wording follows the URL mode and the row's state, and routing is left alone on purpose:
	 * see docs-ai/11-code-rationale.md, "LanguagesPage: routing loss warning".
	 *
	 * @param object $language  Language row being deleted or deactivated.
	 * @param bool   $permanent True on the delete screen, false for the active toggle.
	 * @return string Plain text - escape at the point of output.
	 */
	private function routing_loss_warning( object $language, bool $permanent ): string {
		$settings     = Plugin::get_instance()->get( 'settings' );
		$is_path_mode = $settings->get_url_mode() === 'subdirectory';
		$prefix       = '';

		if ( $is_path_mode ) {
			// The segment sits under home_url()'s path (subfolder install or multisite child), which is
			// what the router strips; PerfLocale's home_url filter skips wp-admin, so it is unprefixed.
			$home_path = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '/' );

			if ( '' === $home_path ) {
				$home_path = '/';
			}

			$prefix = trailingslashit( $home_path ) . $settings->get_url_prefix( $language ) . '/';
		}

		if ( empty( $language->is_active ) ) {
			$lead = $is_path_mode
				/* translators: %s: URL path prefix, e.g. /de/. */
				? sprintf( __( 'This language is inactive, so URLs under %s are not recognised.', 'perflocale' ), $prefix )
				: __( 'This language is inactive, so its URLs are not recognised.', 'perflocale' );
		} elseif ( $permanent ) {
			$lead = $is_path_mode
				/* translators: %s: URL path prefix, e.g. /de/. */
				? sprintf( __( 'Existing URLs under %s will no longer be recognised.', 'perflocale' ), $prefix )
				: __( 'Existing URLs for this language will no longer be recognised.', 'perflocale' );
		} else {
			$lead = $is_path_mode
				/* translators: %s: URL path prefix, e.g. /de/. */
				? sprintf( __( 'If you deactivate this language, URLs under %s will no longer be recognised.', 'perflocale' ), $prefix )
				: __( 'If you deactivate this language, its URLs will no longer be recognised.', 'perflocale' );
		}

		$tail = $is_path_mode
			? __( 'WordPress answers an unrecognised URL with its own guess, which is often an unrelated post, so add redirects for any you still need.', 'perflocale' )
			: __( 'Those URLs still answer, but in the site default language rather than this one.', 'perflocale' );

		return $lead . ' ' . $tail;
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
		$packs       = $this->language_packs( $languages );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
		?>
		<div class="wrap perflocale-languages">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Languages', 'perflocale' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages&action=add' ) ); ?>" class="page-title-action">
				<?php echo esc_html__( 'Add New', 'perflocale' ); ?>
			</a>
			<hr class="wp-header-end" style="margin-bottom: 16px;">

			<?php $this->render_admin_notice( $message ); ?>
			<?php $this->render_bare_default_notice( $all_languages ); ?>

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

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
						$pack_hint  = $this->language_pack_hint( $language, $packs );
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
							<?php
							/*
							 * Touch has no HTML5 drag-and-drop and no hover to reveal the handle, so small
							 * screens get these buttons, which use the arrow keys' move-and-save path.
							 */
							?>
							<div class="perflocale-lang-item__move">
								<button type="button"
									class="perflocale-lang-item__move-btn"
									data-perflocale-move="up"
									aria-label="
									<?php
									echo esc_attr(
										sprintf(
											/* translators: %s: language name */
											__( 'Move %s up', 'perflocale' ),
											$language->name
										)
									);
									?>
									">
									<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
										<path d="M2.5 7.5L6 4L9.5 7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</button>
								<button type="button"
									class="perflocale-lang-item__move-btn"
									data-perflocale-move="down"
									aria-label="
									<?php
									echo esc_attr(
										sprintf(
											/* translators: %s: language name */
											__( 'Move %s down', 'perflocale' ),
											$language->name
										)
									);
									?>
									">
									<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
										<path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</button>
							</div>
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
									// bdi keeps an RTL native name from garbling the LTR punctuation; lang
									// lets screen readers switch pronunciation.
									?>
									<bdi lang="<?php echo esc_attr( str_replace( '_', '-', (string) $language->locale ) ); ?>"><?php echo esc_html( $language->native_name ); ?></bdi>
									&middot; <code><?php echo esc_html( $language->slug ); ?></code>
									&middot; <code><?php echo esc_html( $language->locale ); ?></code>
									<?php if ( $language->text_direction === 'rtl' ) : ?>
										&middot; RTL
									<?php endif; ?>
								</div>
								<?php
								echo wp_kses(
									$pack_hint,
									[
										'div' => [
											'class' => [],
										],
										'a'   => [
											'href' => [],
										],
									]
								);
								?>
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
										// Shown disabled, not hidden: set_default() refuses an inactive
										// language, because get_default() reads the active-only bootstrap.
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
	 * The installable language packs of this page's active languages, by locale.
	 *
	 * Read like Site Health's test: a missing WordPress pack and the packs core's update checks
	 * offered; packs WordPress.org does not offer stay on Site Health only.
	 *
	 * @param array<int, object> $languages Language rows of this page.
	 * @return array
	 * @phpstan-return Packs
	 */
	private function language_packs( array $languages ): array {
		$packs = [
			'locales' => [],
		];

		if ( ! current_user_can( 'perflocale_manage_languages' ) ) {
			return $packs;
		}

		$locales = [];

		foreach ( $languages as $language ) {
			if ( ! empty( $language->is_active ) && isset( $language->locale ) && is_string( $language->locale ) ) {
				$locales[] = $language->locale;
			}
		}

		if ( [] === $locales ) {
			return $packs;
		}

		$report = LanguagePacks::report( $locales, false );

		foreach ( $report['locales'] as $locale => $row ) {
			$findings = LanguagePacks::findings( $row );

			if ( LanguagePacks::MISSING === $findings['core'] || LanguagePacks::OFFERED === $findings['core'] || [] !== $findings['offered'] ) {
				$packs['locales'][ (string) $locale ] = [
					'core'    => $findings['core'],
					'offered' => $findings['offered'],
				];
			}
		}

		return $packs;
	}

	/**
	 * The language-pack hint of an active language's row, empty when no pack is installable.
	 *
	 * Users who can install languages also get the way to install them.
	 *
	 * @param object $language Language row.
	 * @param array  $packs    Result of language_packs().
	 * @return string The hint's markup (escaped), or an empty string.
	 * @phpstan-param Packs $packs
	 */
	private function language_pack_hint( object $language, array $packs ): string {
		$locale = isset( $language->locale ) && is_string( $language->locale ) ? $language->locale : '';

		if ( empty( $language->is_active ) || ! isset( $packs['locales'][ $locale ] ) ) {
			return '';
		}

		$findings    = $packs['locales'][ $locale ];
		$can_install = current_user_can( 'install_languages' );
		$name        = isset( $language->name ) && is_string( $language->name ) && '' !== $language->name ? $language->name : $locale;
		$label       = esc_html( $name ) . ' (' . esc_html( $locale ) . ')';
		$parts       = [];

		if ( LanguagePacks::MISSING === $findings['core'] ) {
			$text = esc_html__( 'The WordPress language pack is not installed for:', 'perflocale' ) . ' ' . $label . '.';

			if ( $can_install ) {
				$text .= ' ' . sprintf(
					/* translators: %s: "Settings → General" link */
					esc_html__( 'To install it, choose it as Site Language in %s and save, then set the Site Language back.', 'perflocale' ),
					'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings → General', 'perflocale' ) . '</a>'
				);
			}

			$parts[] = $text;
		}

		$ready = $this->pack_kinds( LanguagePacks::OFFERED === $findings['core'], $findings['offered'] );

		if ( '' !== $ready ) {
			$parts[] = esc_html__( 'Ready to install on Dashboard → Updates:', 'perflocale' ) . ' ' . $ready . '.';
		}

		if ( $can_install && '' !== $ready ) {
			$parts[] = '<a href="' . esc_url( LanguagePacks::updates_url() ) . '">' . esc_html__( 'Open Dashboard → Updates', 'perflocale' ) . '</a>';
		}

		return '<div class="perflocale-lang-item__packs">' . implode( ' ', $parts ) . '</div>';
	}

	/**
	 * The kinds of packs in a list, short: "WordPress, 1 theme, 2 plugins".
	 *
	 * @param bool  $core  Whether WordPress itself is in the list.
	 * @param array $items Theme and plugin packs.
	 * @return string Escaped text, empty for an empty list.
	 * @phpstan-param list<Item> $items
	 */
	private function pack_kinds( bool $core, array $items ): string {
		$themes  = 0;
		$plugins = 0;

		foreach ( $items as $item ) {
			if ( 'theme' === $item['type'] ) {
				++$themes;
			} else {
				++$plugins;
			}
		}

		$kinds = $core ? [ esc_html__( 'WordPress', 'perflocale' ) ] : [];

		if ( $themes > 0 ) {
			/* translators: %s: number of themes */
			$kinds[] = esc_html( sprintf( _n( '%s theme', '%s themes', $themes, 'perflocale' ), number_format_i18n( $themes ) ) );
		}

		if ( $plugins > 0 ) {
			/* translators: %s: number of plugins */
			$kinds[] = esc_html( sprintf( _n( '%s plugin', '%s plugins', $plugins, 'perflocale' ), number_format_i18n( $plugins ) ) );
		}

		return implode( ', ', $kinds );
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

		// Clamped on read too: a stored value may exceed the save-time ceiling. The stored
		// preference is not rewritten.
		return \PerfLocale\Helper::normalize_per_page( (int) $val, 20, (string) $option );
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
			 * Filters the predefined languages of the "Add Language" quick-select.
			 *
			 * Entry keys (strings): slug (max 10, unique), locale (max 20, unique), name, native_name,
			 * flag (ISO 3166-1 alpha-2), text_direction ('ltr' or 'rtl'), date_format, time_format.
			 *
			 * @hook perflocale/predefined_languages
			 *
			 * @param array<int, array<string, string>> $predefined Bundled languages from `data/languages.php`.
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

			<?php $this->render_admin_notice( $message ); ?>

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

			<div class="perflocale-lang-form-wrap">
				<?php if ( ! $is_edit && ! empty( $predefined ) ) : ?>
					<?php
					// Popular languages first in their declared order, then the rest by English name;
					// the combobox draws its group divider from `data-popular="1"`.
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

						if ( $flag !== '' && strlen( $flag ) === 2 && Helper::is_ascii_alpha( $flag ) ) {
							// Regional indicators from raw UTF-8 bytes ('A' is U+1F1E6): mb_chr() has no
							// core polyfill and fatals on a host without mbstring.
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
						<?php if ( $is_edit && ! $is_default ) : ?>
							<p class="description" style="margin-left: 24px;"><?php echo esc_html( $this->routing_loss_warning( $language, false ) ); ?></p>
						<?php endif; ?>
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
					// Rename nudge: one checkbox per active bare-language slug with a region-qualified
					// target; JS shows the one whose slug prefixes the typed slug (`ar-ma` shows `ar`).
					$rename_candidates = [];

					if ( ! $is_edit ) {
						// The form detector needs no region-qualified sibling yet: the user is about to add it.
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
						\PerfLocale\Admin\Assets::enqueue_script( 'perflocale-language-rename-hint', 'language-rename-hint.js' );
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

		// Duplicate slug or locale, caught by AdminController before the insert.
		if ( $message === 'duplicate_slug' || $message === 'duplicate_locale' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dup     = isset( $_GET['dup'] ) ? sanitize_text_field( wp_unslash( $_GET['dup'] ) ) : '';
			$is_slug = $message === 'duplicate_slug';

			$text = $is_slug
				? sprintf(
					/* translators: %s: slug that already exists */
					__( 'A language with the slug %s already exists. Pick a different slug - each language must have a unique URL identifier.', 'perflocale' ),
					'<code>' . esc_html( $dup ) . '</code>'
				)
				: sprintf(
					/* translators: %s: locale that already exists */
					__( 'A language with the locale %s already exists. Pick a different locale - each language must have a unique WordPress locale.', 'perflocale' ),
					'<code>' . esc_html( $dup ) . '</code>'
				);

			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses(
				$text,
				[ 'code' => [] ]
			) . '</p></div>';
			return;
		}

		// A slug routing cannot express: update() refused it before writing, so nothing was saved.
		if ( $message === 'invalid_slug' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bad = isset( $_GET['dup'] ) ? sanitize_text_field( wp_unslash( $_GET['dup'] ) ) : '';

			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses(
				sprintf(
					/* translators: %1$s: the rejected language slug, %2$s and %3$s: example slugs */
					__( 'The slug %1$s cannot be used in URLs, so nothing was saved. Use two or three lowercase letters, optionally followed by a hyphen and two or three more - for example %2$s or %3$s.', 'perflocale' ),
					'<code>' . esc_html( $bad ) . '</code>',
					'<code>de</code>',
					'<code>pt-br</code>'
				),
				[ 'code' => [] ]
			) . '</p></div>';
			return;
		}

		// A delete refused because the language still has posts
		// (LanguageRepository::delete_blockers()). The preview screen lists them.
		if ( $message === 'delete_blocked' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice; the preview link carries its own nonce.
			$language_id = isset( $_GET['language_id'] ) ? absint( $_GET['language_id'] ) : 0;
			$preview_url = $language_id > 0
				? wp_nonce_url( admin_url( 'admin.php?page=perflocale-languages&action=delete&language_id=' . $language_id ), 'perflocale_delete_language' )
				: '';

			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__(
				'The language was not deleted because it still has content. Move its posts to the Trash first, or deactivate the language instead: an inactive language keeps its translations linked.',
				'perflocale'
			);

			if ( $preview_url !== '' ) {
				echo ' <a href="' . esc_url( $preview_url ) . '">' . esc_html__( 'See what it still has', 'perflocale' ) . '</a>';
			}

			echo '</p></div>';
			return;
		}

		// A delete that did not happen: delete() rolled back, or it was the default language or a
		// removed id. The site is unchanged.
		if ( $message === 'delete_failed' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__(
				'The language was not deleted and nothing on the site was changed. The default language cannot be removed, and the language may already be gone - reload this list, and check the error log if it is still here.',
				'perflocale'
			) . '</p></div>';
			return;
		}

		// A refused "Set as Default": LanguageRepository::set_default() rejects a missing or inactive target.
		if ( $message === 'default_change_failed' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__(
				'The default language was not changed. Only an active language can be the default - activate it first, then set it as default.',
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
	 * Shows a notice when a bare-language slug (`en`) sits next to a region-qualified one (`en-gb`).
	 *
	 * A UX nudge only: the output is valid BCP 47; the Add Language form offers the rename.
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
