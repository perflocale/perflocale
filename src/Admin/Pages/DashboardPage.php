<?php
/**
 * Dashboard admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Admin\PluginNav;
use PerfLocale\Database\Repository\TranslationLinkRepository;
use PerfLocale\Helper;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the PerfLocale dashboard with translation completeness overview.
 *
 * Visual language mirrors the Settings page: native form-table rows for
 * label/value content and widefat tables for the per-language progress
 * breakdown, under the shared PluginNav strip. Maintenance/shortcut/
 * resource panels sit in a right-hand rail on wide screens.
 */
final class DashboardPage {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render(): void {
		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$link_repo = new TranslationLinkRepository( $cache );

		$languages  = $lang_repo->get_active();
		$post_types = $this->settings->get_translatable_post_types();
		$default    = $lang_repo->get_default();

		// Compute totals.
		// Count only source (default-language) published posts - not all published
		// posts - so that translations don't inflate the denominator.
		$total_source     = 0;
		$total_translated = 0;
		$default_id       = $default ? (int) $default->id : 0;

		$non_default_langs = array_filter( $languages, fn( $l ) => $default && $l->slug !== $default->slug );
		$target_count      = count( $non_default_langs );

		// Per-post-type source counts (cached for reuse in per-type tables below).
		$source_counts = [];

		foreach ( $post_types as $pt ) {
			$source_counts[ $pt ] = $default_id > 0
				? $link_repo->count_source_published( $default_id, $pt )
				: 0;
			$total_source        += $source_counts[ $pt ];
		}

		$status_matrix = $default_id > 0 && ! empty( $non_default_langs ) && ! empty( $post_types )
			? $link_repo->count_status_matrix(
				array_map( static fn( $l ) => (int) $l->id, $non_default_langs ),
				$post_types
			)
			: [];

		foreach ( $non_default_langs as $lang ) {
			foreach ( $post_types as $pt ) {
				$total_translated += (int) ( $status_matrix[ (int) $lang->id ][ $pt ]['published'] ?? 0 );
			}
		}

		$overall_target     = $total_source * $target_count;
		$overall_percentage = $overall_target > 0 ? (int) round( ( $total_translated / $overall_target ) * 100 ) : 0;
		$overall_percentage = min( 100, $overall_percentage );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['perflocale_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['perflocale_notice'] ) ) : '';

		?>
		<div class="wrap perflocale-dashboard">
			<h1><?php echo esc_html__( 'PerfLocale Dashboard', 'perflocale' ); ?></h1>

			<?php PluginNav::render(); ?>

			<?php if ( $notice === 'cache_cleared' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Plugin cache cleared successfully.', 'perflocale' ); ?></p>
				</div>
			<?php elseif ( $notice === 'permalinks_flushed' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Permalinks refreshed successfully.', 'perflocale' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $languages ) ) : ?>
				<div class="notice notice-warning" style="margin-top: 20px;">
					<p>
						<?php
						printf(
							/* translators: %s: URL to languages page */
							wp_kses_post( __( 'No active languages found. <a href="%s">Add a language</a> to get started.', 'perflocale' ) ),
							esc_url( admin_url( 'admin.php?page=perflocale-languages' ) )
						);
						?>
					</p>
				</div>
				<?php
				return;
			endif;
			?>

			<div class="perflocale-dash-cols">
				<div class="perflocale-dash-cols__main">
					<?php
					$this->render_overview_rows(
						$languages,
						$default,
						$post_types,
						$source_counts,
						$total_translated,
						$overall_target,
						$overall_percentage
					);
					$this->render_progress_section( $post_types, $non_default_langs, $source_counts, $status_matrix );
					?>
				</div>
				<aside class="perflocale-dash-cols__rail">
					<?php $this->render_tools_rail(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Overview — languages, content counts, and overall progress as
	 * native form-table rows (the Settings page's label/value layout).
	 *
	 * @param array<int, object> $languages          Active languages.
	 * @param object|null        $default_lang       Default language.
	 * @param array<int, string> $post_types         Translatable post types.
	 * @param array<string, int> $source_counts      Published source items per post type.
	 * @param int                $total_translated   Published translations across all languages.
	 * @param int                $overall_target     Source items × target languages.
	 * @param int                $overall_percentage Overall completion percentage.
	 * @return void
	 */
	private function render_overview_rows(
		array $languages,
		?object $default_lang,
		array $post_types,
		array $source_counts,
		int $total_translated,
		int $overall_target,
		int $overall_percentage
	): void {
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Languages', 'perflocale' ); ?></th>
					<td>
						<div class="perflocale-dash-langs">
							<?php
							foreach ( $languages as $language ) :
								$flag       = Helper::get_flag_emoji( $language );
								$is_default = ( $default_lang && $language->slug === $default_lang->slug );
								$edit_url   = admin_url( 'admin.php?page=perflocale-languages&action=edit&language_id=' . (int) $language->id );

								// Prefer the full locale (de-DE) over the bare
								// slug; a slug-only language still shows its slug.
								$code = Helper::format_locale_as_bcp47( (string) ( $language->locale ?: $language->slug ) );
								?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="perflocale-dash-langs__row">
									<span class="perflocale-dash-langs__flag" aria-hidden="true"><?php echo esc_html( $flag ); ?></span>
									<span class="perflocale-dash-langs__name"><?php echo esc_html( $language->native_name ?: $language->name ); ?></span>
									<span class="perflocale-dash-langs__code"><?php echo esc_html( $code ); ?></span>
									<?php if ( $is_default ) : ?>
										<span class="perflocale-dash-langs__badge"><?php echo esc_html__( 'Default', 'perflocale' ); ?></span>
									<?php endif; ?>
								</a>
							<?php endforeach; ?>
						</div>
						<p class="description">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>">
								<?php echo esc_html__( 'Manage languages', 'perflocale' ); ?>
							</a>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Content', 'perflocale' ); ?></th>
					<td>
						<?php
						$content_parts = [];

						foreach ( $post_types as $pt ) {
							$pto = get_post_type_object( $pt );

							if ( ! $pto ) {
								continue;
							}

							$pt_count = (int) ( $source_counts[ $pt ] ?? 0 );

							$content_parts[] = sprintf(
								/* translators: 1: item count, 2: post type name (e.g. "Pages") */
								_x( '%1$s %2$s', 'content count, e.g. "4 Pages"', 'perflocale' ),
								number_format_i18n( $pt_count ),
								$pt_count === 1 ? $pto->labels->singular_name : $pto->labels->name
							);
						}

						echo esc_html( implode( ', ', $content_parts ) );
						?>
						<p class="description">
							<?php
							echo esc_html(
								$default_lang
									? sprintf(
										/* translators: %s: default language name */
										__( 'Published content in %s, the default language. Translations do not count toward these totals.', 'perflocale' ),
										$default_lang->native_name ?: $default_lang->name
									)
									: __( 'Published content in the default language.', 'perflocale' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Overall Progress', 'perflocale' ); ?></th>
					<td>
						<div class="perflocale-dash-bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percentage */ __( 'Overall translation progress: %d%%', 'perflocale' ), $overall_percentage ) ); ?>">
							<div class="perflocale-dash-bar__fill" style="width: <?php echo esc_attr( (string) $overall_percentage ); ?>%;"></div>
						</div>
						<p class="perflocale-dash-bar__caption">
							<?php
							printf(
								/* translators: 1: published translation count, 2: total expected translations, 3: percentage */
								esc_html__( '%1$s of %2$s translations published (%3$d%%).', 'perflocale' ),
								esc_html( number_format_i18n( $total_translated ) ),
								esc_html( number_format_i18n( $overall_target ) ),
								absint( $overall_percentage )
							);
							?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Translation Progress — one native table per post type with a
	 * per-language completion row.
	 *
	 * @param array<int, string>                                 $post_types        Translatable post types.
	 * @param array<int, object>                                 $non_default_langs Target languages.
	 * @param array<string, int>                                 $source_counts     Published source items per post type.
	 * @param array<int, array<string, array<string, int|null>>> $status_matrix     lang_id → post_type → status counts.
	 * @return void
	 */
	private function render_progress_section(
		array $post_types,
		array $non_default_langs,
		array $source_counts,
		array $status_matrix
	): void {
		$has_translatable_content = false;

		foreach ( $post_types as $check_pt ) {
			if ( ( $source_counts[ $check_pt ] ?? 0 ) > 0 ) {
				$has_translatable_content = true;
				break;
			}
		}

		?>
		<h2 class="perflocale-dash-section-title"><?php echo esc_html__( 'Translation Progress', 'perflocale' ); ?></h2>
		<?php

		if ( ! $has_translatable_content || $non_default_langs === [] ) {
			?>
			<p class="perflocale-dash-empty">
				<?php echo esc_html__( 'No published content to translate yet. Once you publish posts or pages in the default language, per-language progress appears here.', 'perflocale' ); ?>
			</p>
			<?php
			return;
		}

		foreach ( $post_types as $post_type ) :
			$pto = get_post_type_object( $post_type );

			if ( ! $pto ) {
				continue;
			}

			$published = $source_counts[ $post_type ] ?? 0;

			// Skip post types with zero source content.
			if ( $published === 0 ) {
				continue;
			}
			?>
			<h3 class="perflocale-dash-h3">
				<?php echo esc_html( $pto->labels->name ); ?>
				<span class="perflocale-dash-h3__count">
					<?php
					printf(
						/* translators: %s: number of items */
						esc_html( _n( '%s item', '%s items', $published, 'perflocale' ) ),
						esc_html( number_format_i18n( $published ) )
					);
					?>
				</span>
			</h3>
			<div class="perflocale-table-responsive">
				<table class="widefat striped perflocale-dash-table">
					<thead>
						<tr>
							<th scope="col" class="perflocale-dash-table__lang-col"><?php echo esc_html__( 'Language', 'perflocale' ); ?></th>
							<th scope="col" class="perflocale-dash-table__bar-col"><?php echo esc_html__( 'Progress', 'perflocale' ); ?></th>
							<th scope="col" class="perflocale-dash-table__num"><?php echo esc_html__( 'Published', 'perflocale' ); ?></th>
							<th scope="col" class="perflocale-dash-table__num"><?php echo esc_html__( 'Drafts', 'perflocale' ); ?></th>
							<th scope="col" class="perflocale-dash-table__num"><?php echo esc_html__( 'Pending', 'perflocale' ); ?></th>
							<th scope="col" class="perflocale-dash-table__num"><?php echo esc_html__( 'Outdated', 'perflocale' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $non_default_langs as $language ) :
							$sc         = $status_matrix[ (int) $language->id ][ $post_type ] ?? [];
							$translated = (int) ( $sc['published'] ?? 0 );
							$drafts     = (int) ( $sc['draft'] ?? 0 );
							$pending    = (int) ( $sc['pending'] ?? 0 );
							$outdated   = (int) ( $sc['needs_update'] ?? 0 );
							$pct        = $published > 0 ? min( 100, (int) round( ( $translated / $published ) * 100 ) ) : 0;

							$flag = Helper::get_flag_emoji( $language );
							?>
							<tr>
								<td class="perflocale-dash-table__lang-col">
									<span class="perflocale-dash-langs__flag" aria-hidden="true"><?php echo esc_html( $flag ); ?></span>
									<span class="perflocale-dash-langs__name"><?php echo esc_html( $language->native_name ?: $language->name ); ?></span>
								</td>
								<td class="perflocale-dash-table__bar-col">
									<div class="perflocale-dash-table__bar-wrap">
										<div class="perflocale-dash-bar perflocale-dash-bar--slim">
											<div class="perflocale-dash-bar__fill" style="width: <?php echo esc_attr( (string) $pct ); ?>%;"></div>
										</div>
										<span class="perflocale-dash-table__pct"><?php echo esc_html( $pct . '%' ); ?></span>
									</div>
								</td>
								<td class="perflocale-dash-table__num"><?php echo $translated > 0 ? esc_html( number_format_i18n( $translated ) ) : '<span class="perflocale-dash-table__zero">&#8212;</span>'; ?></td>
								<td class="perflocale-dash-table__num"><?php echo $drafts > 0 ? esc_html( number_format_i18n( $drafts ) ) : '<span class="perflocale-dash-table__zero">&#8212;</span>'; ?></td>
								<td class="perflocale-dash-table__num"><?php echo $pending > 0 ? '<span class="perflocale-dash-table__attn">' . esc_html( number_format_i18n( $pending ) ) . '</span>' : '<span class="perflocale-dash-table__zero">&#8212;</span>'; ?></td>
								<td class="perflocale-dash-table__num"><?php echo $outdated > 0 ? '<span class="perflocale-dash-table__warn">' . esc_html( number_format_i18n( $outdated ) ) . '</span>' : '<span class="perflocale-dash-table__zero">&#8212;</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
		<p class="description perflocale-dash-legend">
			<?php echo esc_html__( 'Progress counts published translations against published default-language content. Drafts and pending translations are underway; outdated ones need review after their source changed.', 'perflocale' ); ?>
		</p>
		<?php
	}

	/**
	 * Right-hand rail — maintenance actions, shortcuts, and resources as
	 * compact panels.
	 *
	 * @return void
	 */
	private function render_tools_rail(): void {
		?>
		<div class="perflocale-dash-panel">
			<h3 class="perflocale-dash-panel__title"><?php echo esc_html__( 'Cache', 'perflocale' ); ?></h3>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=perflocale&perflocale_action=clear_cache' ), 'perflocale_dashboard_action' ) ); ?>"
				class="button button-secondary">
				<?php echo esc_html__( 'Clear Cache', 'perflocale' ); ?>
			</a>
			<p class="description">
				<?php echo esc_html__( 'Empties all PerfLocale caches. They are rebuilt automatically on the next page view.', 'perflocale' ); ?>
			</p>
		</div>
		<div class="perflocale-dash-panel">
			<h3 class="perflocale-dash-panel__title"><?php echo esc_html__( 'Permalinks', 'perflocale' ); ?></h3>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=perflocale&perflocale_action=flush_permalinks' ), 'perflocale_dashboard_action' ) ); ?>"
				class="button button-secondary">
				<?php echo esc_html__( 'Refresh Permalinks', 'perflocale' ); ?>
			</a>
			<p class="description">
				<?php echo esc_html__( 'Rebuilds the rewrite rules. Use this if language URLs stop resolving after changing the URL mode.', 'perflocale' ); ?>
			</p>
		</div>
		<div class="perflocale-dash-panel">
			<h3 class="perflocale-dash-panel__title"><?php echo esc_html__( 'Shortcuts', 'perflocale' ); ?></h3>
			<div class="perflocale-dash-links">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>">
					<span class="dashicons dashicons-translation" aria-hidden="true"></span>
					<?php echo esc_html__( 'Manage Languages', 'perflocale' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-strings' ) ); ?>">
					<span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span>
					<?php echo esc_html__( 'String Translations', 'perflocale' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings' ) ); ?>">
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					<?php echo esc_html__( 'Settings', 'perflocale' ); ?>
				</a>
			</div>
		</div>
		<div class="perflocale-dash-panel">
			<h3 class="perflocale-dash-panel__title"><?php echo esc_html__( 'Resources', 'perflocale' ); ?></h3>
			<div class="perflocale-dash-links">
				<a href="https://perflocale.com/docs/" target="_blank" rel="noopener">
					<span class="dashicons dashicons-book-alt" aria-hidden="true"></span>
					<?php echo esc_html__( 'Documentation', 'perflocale' ); ?>
					<span class="dashicons dashicons-external perflocale-dash-links__external" aria-hidden="true"></span>
				</a>
				<a href="https://perflocale.com/features/" target="_blank" rel="noopener">
					<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
					<?php echo esc_html__( 'All Features', 'perflocale' ); ?>
					<span class="dashicons dashicons-external perflocale-dash-links__external" aria-hidden="true"></span>
				</a>
				<a href="https://wordpress.org/support/plugin/perflocale/" target="_blank" rel="noopener">
					<span class="dashicons dashicons-sos" aria-hidden="true"></span>
					<?php echo esc_html__( 'Support', 'perflocale' ); ?>
					<span class="dashicons dashicons-external perflocale-dash-links__external" aria-hidden="true"></span>
				</a>
				<?php
				// A quiet, opt-in ask that sits as a peer of the other resource
				// rows - deliberately not a notice, a widget or a dismissible
				// nag, so it needs no stored state and never interrupts a task.
				// Administrators only: Translators reach this screen too but
				// generally did not install the plugin.
				if ( current_user_can( 'manage_options' ) ) :
					?>
				<a href="https://wordpress.org/support/plugin/perflocale/reviews/" target="_blank" rel="noopener">
					<span class="dashicons dashicons-star-filled perflocale-dash-links__star" aria-hidden="true"></span>
					<?php echo esc_html__( 'Rate PerfLocale', 'perflocale' ); ?>
					<span class="dashicons dashicons-external perflocale-dash-links__external" aria-hidden="true"></span>
				</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
