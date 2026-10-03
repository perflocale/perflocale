<?php
/**
 * Translations admin page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Admin\ObjectLinks;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\TranslationStatus;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a filterable table of all translatable content with
 * per-language translation status badges.
 *
 * Uses an inline simplified WP_List_Table pattern without extending
 * the core class directly.
 */
final class TranslationsPage {

	/**
	 * Plugin settings.
	 *
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
	 * Render the translations page.
	 *
	 * @return void
	 */
	public function render(): void {
		$plugin     = Plugin::get_instance();
		$cache      = $plugin->get( 'cache' );
		$lang_repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$group_repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		$languages  = $lang_repo->get_active();
		$post_types = $this->settings->get_translatable_post_types();

		// User column-visibility preference (matches Strings page pattern).
		$hidden_langs = (array) get_user_meta( get_current_user_id(), 'perflocale_translations_hidden_langs', true );

		// Read filters (no nonce needed for read-only filtering).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter_post_type = isset( $_GET['post_type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		// Canonical language filter - the slug form (`?perflocale_lang=de`)
		// used by the WP admin-bar switcher and every other list page in
		// the plugin. Resolved to an internal language ID for the repo.
		$lang_slug_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';
		$filter_language  = 0;

		if ( $lang_slug_filter !== '' ) {
			$resolved        = $lang_repo->find_by_slug( $lang_slug_filter );
			$filter_language = $resolved ? (int) $resolved->id : 0;
		}
		// One of the statuses the filter offers, or none. The all-language
		// selector caches one result per status, and a status change of a
		// translatable post deletes exactly those entries.
		$filter_status_raw = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : '';
		$filter_status     = $filter_status_raw !== ''
			? ( TranslationStatus::tryFrom( $filter_status_raw )?->value ?? '' )
			: '';
		// Source / provenance filter (translation_links.source). Validated against
		// SourceType::tryFrom so URL injection cannot smuggle arbitrary values
		// into matches_filters().
		$filter_source_raw = isset( $_GET['source_filter'] ) ? sanitize_key( wp_unslash( $_GET['source_filter'] ) ) : '';
		$filter_source     = $filter_source_raw !== ''
			? ( \PerfLocale\Enum\SourceType::tryFrom( $filter_source_raw )?->value ?? '' )
			: '';
		$search            = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged             = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// Sorting - allow-listed orderby → WP_Query orderby mapping. Anything
		// outside the list falls back to the default (modified DESC) so the
		// URL can't inject arbitrary SQL column refs.
		$sort_map    = [
			'id'    => 'ID',
			'title' => 'title',
			'type'  => 'type',
		];
		$orderby_key = isset( $_GET['orderby'] ) ? strtolower( sanitize_key( $_GET['orderby'] ) ) : '';
		$order       = isset( $_GET['order'] ) && strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) === 'ASC' ? 'ASC' : 'DESC';
		$orderby_wp  = $sort_map[ $orderby_key ] ?? 'modified';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_per_page();

		// Build query args.
		$query_post_types = $filter_post_type !== '' && in_array( $filter_post_type, $post_types, true )
			? [ $filter_post_type ]
			: $post_types;

		$query_args = [
			'post_type'              => $query_post_types,
			'post_status'            => 'any',
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'orderby'                => $orderby_wp,
			'order'                  => $order,
			// Custom flag picked up by the posts_where filter below - hides
			// non-default-language translations so each group shows once
			// (as its default-language source row). Orphan posts (no links
			// at all) remain visible.
			'perflocale_only_source' => true,
		];

		// Title-only search via the s parameter - scoped to post_title via
		// the 's' query WP_Query handles natively. Cheaper than meta search
		// and matches what the user typed in the title field.
		if ( $search !== '' ) {
			$query_args['s'] = $search;
		}

		// Register the one-shot filter that appends a NOT IN subquery
		// excluding every post_id that exists in translation_links with a
		// non-default language. Scoped by the `perflocale_only_source`
		// query var so it never leaks to unrelated queries.
		$this->register_source_only_filter( $lang_repo );

		// Rows the current user may not read stay off the list and out of its
		// count (run_list_query()). '' for a user who may read every row.
		$unreadable_where = $this->unreadable_rows_where( $query_post_types );

		// Push language + status filters down to SQL so WP_Query's found_posts
		// reflects the filtered result size (prevents paginator advertising
		// pages that are all filtered out post-query). When either filter is
		// active we resolve the matching source object IDs up front and pass
		// them via `post__in`, which causes WP to compute pagination against
		// the filtered ID set instead of the entire post_type row count.
		// The source filter is applied client-side via matches_filters();
		// the count drift is mild and the join cost wasn't worth the repo
		// surface area at the time.
		$filter_active = ( $filter_language > 0 || $filter_status !== '' );

		// The source-only filter registered above discards every object whose
		// link is not the default language, so the ID resolution can be bound
		// to that language up front instead of loading every language's rows
		// into PHP and then throwing most of them away. 0 when the site has no
		// default language — in which case that filter is not registered
		// either, and the unbounded behaviour is still correct. The
		// all-language status filter in matches_filters() skips this
		// language's link the same way the SQL selector does.
		$default_lang       = $lang_repo->get_default();
		$source_language_id = ( $default_lang && ! empty( $default_lang->id ) ) ? (int) $default_lang->id : 0;

		if ( $filter_active ) {
			$active_language_ids = array_map( static fn( $l ) => (int) $l->id, $languages );

			$matching_ids = $this->resolve_filtered_object_ids(
				$group_repo,
				$filter_language,
				$filter_status,
				$active_language_ids,
				$filter_source,
				$source_language_id
			);

			if ( empty( $matching_ids ) ) {
				// No matches. Empty-state path without triggering a real WP_Query.
				$posts       = [];
				$total       = 0;
				$total_pages = 0;
				$query       = null;
			} else {
				$query_args['post__in'] = $matching_ids;
				// Preserve ordering requested (modified DESC) instead of
				// ordering by post__in sequence - that's the user intent.
				$query       = $this->run_list_query( $query_args, $unreadable_where );
				$posts       = $query->posts;
				$total       = $query->found_posts;
				$total_pages = (int) ceil( $total / $per_page );
			}
		} else {
			$query       = $this->run_list_query( $query_args, $unreadable_where );
			$posts       = $query->posts;
			$total       = $query->found_posts;
			$total_pages = (int) ceil( $total / $per_page );
		}

		$visible_ids = array_map( static fn( $p ) => (int) $p->ID, $posts );
		$batch_map   = ! empty( $visible_ids )
			? $group_repo->get_translations_for_objects( $visible_ids, \PerfLocale\Enum\ObjectType::Post )
			: [];

		// Resolve each link's effective status (TranslationStatus::effective(),
		// the PHP twin of the SQL the status filter and the counts use) so the
		// table cells and the status filter agree. Done once here at
		// collection time, not per-row, to keep matches_filters()
		// pure (no DB calls inside the filter loop). _prime_post_caches()
		// pulls every linked post into the WP cache in a single query, so
		// the get_post() calls inside the loop are O(1).
		if ( ! empty( $batch_map ) ) {
			$linked_ids = [];
			foreach ( $batch_map as $links ) {
				foreach ( $links as $link ) {
					$lid = (int) ( $link->object_id ?? 0 );
					if ( $lid > 0 ) {
						$linked_ids[ $lid ] = true;
					}
				}
			}

			if ( ! empty( $linked_ids ) ) {
				_prime_post_caches( array_keys( $linked_ids ), false, false );
			}

			// Reconcile the stored status against the post's real status with the
			// same definition TranslationLinkRepository::effective_status_sql()
			// gives the Dashboard, `wp perflocale status` and the status filter,
			// so they all report the same status for the same link.
			foreach ( $batch_map as $links ) {
				foreach ( $links as $link ) {
					$linked_post  = get_post( (int) ( $link->object_id ?? 0 ) );
					$link->status = TranslationStatus::effective(
						(string) ( $link->status ?? '' ),
						$linked_post instanceof \WP_Post ? $linked_post->post_status : null
					);
				}
			}
		}

		// Base URL args used for pagination + filter + sort persistence.
		$base_args = array_filter(
			[
				'page'             => 'perflocale-translations',
				'post_type_filter' => $filter_post_type,
				'perflocale_lang'  => $lang_slug_filter,
				'status_filter'    => $filter_status,
				'source_filter'    => $filter_source,
				's'                => $search,
				'orderby'          => $orderby_key !== '' && isset( $sort_map[ $orderby_key ] ) ? $orderby_key : '',
				'order'            => ( $orderby_key !== '' && isset( $sort_map[ $orderby_key ] ) ) ? $order : '',
			],
			static fn( $v ) => $v !== '' && $v !== 0
		);

			$visible_langs = array_filter( $languages, static fn( $l ) => ! in_array( $l->slug, $hidden_langs, true ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash routing
			$bulk_message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_no_access = isset( $_GET['no_access'] ) ? absint( $_GET['no_access'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_kept = isset( $_GET['kept'] ) ? absint( $_GET['kept'] ) : 0;

			$mt_available = \PerfLocale\Admin\AdminController::bulk_mt_translate_available();

			// Resolve the active provider's display name so the bulk-action
			// label reads "Translate via DeepL into…" instead of a generic
			// "MT-translate" — admins can see at a glance which API will run.
			$mt_provider_name = '';
			if ( $mt_available ) {
				try {
					$mt_service       = new \PerfLocale\MachineTranslation\TranslationService( Plugin::get_instance()->get( 'settings' ), Plugin::get_instance()->get( 'cache' ) );
					$mt_provider_name = $mt_service->get_provider()->get_name();
				} catch ( \Throwable $e ) {
					$mt_provider_name = '';
				}
			}

			?>
		<div class="wrap perflocale-strings-page perflocale-translations">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Translations', 'perflocale' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			if ( $bulk_message === 'bulk_mt_done' ) :
				$mt_first_error = get_transient( 'perflocale_bulk_mt_error_' . get_current_user_id() );
				if ( $mt_first_error ) {
					delete_transient( 'perflocale_bulk_mt_error_' . get_current_user_id() );
				}
				$mt_first_warning = get_transient( 'perflocale_bulk_mt_warning_' . get_current_user_id() );
				if ( $mt_first_warning ) {
					delete_transient( 'perflocale_bulk_mt_warning_' . get_current_user_id() );
				}
				$notice_class = ( $bulk_failed > 0 || $bulk_no_access > 0 || $bulk_kept > 0 ) ? 'notice-warning' : 'notice-success';
				?>
				<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible"><p>
				<?php
					printf(
						/* translators: 1: count translated, 2: count skipped, 3: count failed */
						esc_html__( 'Bulk machine translation: %1$d created, %2$d skipped (already translated), %3$d failed.', 'perflocale' ),
						absint( $bulk_created ),
						absint( $bulk_skipped ),
						absint( $bulk_failed )
					);
				if ( $bulk_no_access > 0 ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %d: number of post × language pairs skipped because the user may not translate them. */
							_n( '%d skipped because you are not allowed to translate it.', '%d skipped because you are not allowed to translate them.', $bulk_no_access, 'perflocale' ),
							absint( $bulk_no_access )
						)
					);
				}
				if ( ( $bulk_failed > 0 || $bulk_no_access > 0 ) && $mt_first_error ) {
					echo '<br><strong>' . esc_html__( 'First error:', 'perflocale' ) . '</strong> ' . esc_html( (string) $mt_first_error );
				}
				if ( $bulk_kept > 0 ) {
					echo '<br>' . esc_html(
						sprintf(
							/* translators: %d: number of created translations whose content stayed in the source language. */
							_n( '%d created translation kept the source-language content.', '%d created translations kept the source-language content.', $bulk_kept, 'perflocale' ),
							absint( $bulk_kept )
						)
					);
					if ( $mt_first_warning ) {
						echo ' <strong>' . esc_html__( 'First warning:', 'perflocale' ) . '</strong> ' . esc_html( (string) $mt_first_warning );
					}
				}
				?>
				</p></div>
			<?php elseif ( $bulk_message === 'bulk_mt_denied' ) : ?>
				<?php
				$_mt_denied = get_transient( 'perflocale_bulk_mt_error_' . get_current_user_id() );
				if ( $_mt_denied ) {
					delete_transient( 'perflocale_bulk_mt_error_' . get_current_user_id() );
				}
				?>
				<div class="notice notice-warning is-dismissible"><p>
				<?php
				echo esc_html__( 'Bulk machine translation did not run.', 'perflocale' );
				if ( $_mt_denied ) {
					echo ' ' . esc_html( (string) $_mt_denied );
				}
				?>
				</p></div>
			<?php elseif ( $bulk_message === 'bulk_marked_needs_update' ) : ?>
				<div class="notice notice-success is-dismissible"><p>
				<?php
					printf(
						/* translators: %d: number of translation rows flagged */
						esc_html( _n( '%d translation flagged as needing update.', '%d translations flagged as needing update.', $bulk_count, 'perflocale' ) ),
						absint( $bulk_count )
					);
				?>
				</p></div>
			<?php elseif ( $bulk_message === 'bulk_mark_failed' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Nothing was flagged: the database rejected the update. The translations are unchanged — check the error log and try again.', 'perflocale' ); ?></p></div>
			<?php elseif ( $bulk_message === 'bulk_mt_unavailable' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Machine translation is unavailable. Configure a provider with a valid API key under Settings → Addons → Machine Translation.', 'perflocale' ); ?></p></div>
			<?php
			/*
			 * ⚠️ These three used to redirect with `perflocale_bulk=<key>`, which
			 * NOTHING reads — this block switches on `$_GET['message']`. Every
			 * failed "Translate the entire site" therefore bounced the operator
			 * back to a silent page. The handler now uses the `message` key, and
			 * these arms give each outcome words.
			 */
			?>
			<?php elseif ( $bulk_message === 'site_translate_empty' ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Nothing to translate: pick at least one target language and one post type.', 'perflocale' ); ?></p></div>
			<?php
			elseif ( $bulk_message === 'site_translate_unregistered' ) :
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash routing
				$_missing = isset( $_GET['types'] ) ? sanitize_text_field( wp_unslash( $_GET['types'] ) ) : '';
				?>
				<div class="notice notice-error is-dismissible"><p>
				<?php
				printf(
					/* translators: %s: comma-separated list of post type slugs. */
					esc_html__( 'Nothing to translate. These post types are marked translatable but are not registered right now — the plugin that provides them is probably inactive: %s', 'perflocale' ),
					esc_html( $_missing )
				);
				?>
				</p></div>
			<?php
			elseif ( $bulk_message === 'site_translate_denied' ) :
				$_st_error = get_transient( 'perflocale_bulk_mt_error_' . get_current_user_id() );
				if ( $_st_error ) {
					delete_transient( 'perflocale_bulk_mt_error_' . get_current_user_id() );
				}
				?>
				<div class="notice notice-error is-dismissible"><p>
				<?php
				echo esc_html__( 'The site-wide translation run could not be queued.', 'perflocale' );
				if ( $_st_error ) {
					echo ' ' . esc_html( (string) $_st_error );
				}
				?>
				</p></div>
			<?php elseif ( $bulk_message === 'bulk_no_target_language' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Pick a target language for the bulk action.', 'perflocale' ); ?></p></div>
			<?php
			elseif ( $bulk_message === 'bulk_truncated' ) :
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash routing
				$_limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 0;
				?>
				<div class="notice notice-error is-dismissible"><p>
				<?php
				printf(
					/* translators: %d: the server's max_input_vars limit. */
					esc_html__( 'Nothing was changed. The form did not arrive complete — this server accepts at most %d form fields (max_input_vars), and the request reached that limit. Lower "Rows per page" in Screen Options, or select fewer rows, and try again.', 'perflocale' ),
					absint( $_limit )
				);
				?>
				</p></div>
			<?php elseif ( $bulk_message === 'bulk_no_selection' ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Pick a bulk action and at least one post first.', 'perflocale' ); ?></p></div>
			<?php elseif ( $bulk_message === 'bulk_unknown' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Unknown bulk action.', 'perflocale' ); ?></p></div>
			<?php endif; ?>

			<?php
			/*
			 * Discoverability hint for the non-public content types.
			 *
			 * These ship OFF, and the support thread that produced the feature was
			 * someone who could not find HOW to translate a synced pattern. A hint is
			 * the cheap half of that problem: tell the people who actually have such
			 * content, and nobody else.
			 *
			 * ⚠️ COST. This runs ONLY while rendering this screen — never on the front
			 * end, never on another admin page, never on cron. It asks nothing at all
			 * when both types are already enabled, and otherwise uses
			 * wp_count_posts(), whose per-status counts core keeps in the object
			 * cache and invalidates on save — so on a warm cache it costs no query,
			 * and on a cold one far less than this screen's own listing query.
			 */
			$hint_candidates = [];

			foreach ( [ 'wp_block' => __( 'Patterns', 'perflocale' ), 'wp_navigation' => __( 'Navigation Menus', 'perflocale' ) ] as $hint_type => $hint_label ) {
				if ( ! in_array( $hint_type, $post_types, true ) && post_type_exists( $hint_type ) ) {
					$hint_candidates[ $hint_type ] = $hint_label;
				}
			}

			if ( $hint_candidates ) {
				$hint_counts = [];

				foreach ( $hint_candidates as $hint_type => $hint_label ) {
					// wp_count_posts() rather than a hand-written COUNT: core caches
					// the per-status counts in the object cache and invalidates them
					// on save, so on a warm cache this costs no query at all.
					$hint_counts_obj = wp_count_posts( $hint_type );
					$hint_found      = (int) ( $hint_counts_obj->publish ?? 0 );

					if ( $hint_found > 0 ) {
						$hint_counts[] = sprintf(
							/* translators: 1: number of items, 2: the content type label shown on the settings screen, e.g. "Patterns". */
							_n( '%1$d %2$s', '%1$d %2$s', $hint_found, 'perflocale' ),
							$hint_found,
							$hint_label
						);
					}
				}

				if ( $hint_counts ) :
					?>
					<div class="notice notice-info is-dismissible">
						<p>
							<?php
							printf(
								/* translators: %s: a list such as "4 Patterns and 1 Navigation Menus". */
								esc_html__( 'This site has %s that are not translatable yet.', 'perflocale' ),
								esc_html( implode( __( ' and ', 'perflocale' ), $hint_counts ) )
							);
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=translation' ) ); ?>">
								<?php echo esc_html__( 'Switch them on under Advanced content types', 'perflocale' ); ?></a>.
						</p>
					</div>
					<?php
				endif;
			}
			?>

			<?php \PerfLocale\Admin\PluginNav::render(); ?>

			<!-- Toolbar: search + count (matches Strings page style) -->
			<div class="perflocale-str-toolbar">
				<div class="perflocale-str-toolbar__left">
					<form method="get" class="perflocale-str-search">
						<input type="hidden" name="page" value="perflocale-translations">
						<?php if ( $filter_post_type !== '' ) : ?>
							<input type="hidden" name="post_type_filter" value="<?php echo esc_attr( $filter_post_type ); ?>">
						<?php endif; ?>
						<?php if ( $lang_slug_filter !== '' ) : ?>
							<input type="hidden" name="perflocale_lang" value="<?php echo esc_attr( $lang_slug_filter ); ?>">
						<?php endif; ?>
						<?php if ( $filter_status !== '' ) : ?>
							<input type="hidden" name="status_filter" value="<?php echo esc_attr( $filter_status ); ?>">
						<?php endif; ?>
						<?php if ( $filter_source !== '' ) : ?>
							<input type="hidden" name="source_filter" value="<?php echo esc_attr( $filter_source ); ?>">
						<?php endif; ?>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search posts by title…', 'perflocale' ); ?>" class="perflocale-str-search__input">
						<button type="submit" class="button"><?php echo esc_html__( 'Search', 'perflocale' ); ?></button>
						<?php if ( $search !== '' ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array_diff_key( $base_args, [ 's' => '' ] ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php echo esc_html__( 'Clear', 'perflocale' ); ?></a>
						<?php endif; ?>
					</form>
				</div>
				<div class="perflocale-str-toolbar__right">
					<!-- Count moved to the bulk-actions tablenav row below. -->
				</div>
			</div>

			<!-- Filters + pagination on one row (matches Strings page) -->
			<div class="tablenav top">
				<div class="alignleft actions">
					<?php $this->render_filters( $post_types, $languages, $filter_post_type, $lang_slug_filter, $filter_status, $search, $filter_source ); ?>
				</div>
				<?php $this->render_pagination_links( $paged, $total_pages, $total, $base_args ); ?>
				<br class="clear">
			</div>

			<?php
			// Bulk-actions form + bar. The form is declared OUTSIDE the
			// table; checkboxes inside the table bind to it via the HTML5
			// `form="..."` attribute. Same pattern as Glossary/Assignments.
			$bulk_form_id = 'perflocale-translations-bulk';
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-translations' ) ); ?>" id="<?php echo esc_attr( $bulk_form_id ); ?>" class="perflocale-bulk-form" data-perflocale-bulk-form>
				<?php wp_nonce_field( 'perflocale_translations_bulk' ); ?>
				<input type="hidden" name="perflocale_translations_action" value="bulk">
				<?php
				// Replay current filters so the redirect after the bulk action
				// returns to the same view.
				foreach ( [
					'post_type_filter' => $filter_post_type,
					'status_filter'    => $filter_status,
					'source_filter'    => $filter_source,
					's'                => $search,
					'perflocale_lang'  => $lang_slug_filter,
					'paged'            => $paged,
					'orderby'          => $orderby_key,
					'order'            => $order,
				] as $arg => $val ) {
					if ( $val !== '' && $val !== 0 ) {
						printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $arg ), esc_attr( (string) $val ) );
					}
				}
				?>
				<?php
				// ⭐ COMPLETENESS MARKER — must be the LAST field in the form.
				// PHP silently discards input past `max_input_vars`, so if this
				// arrives the browser sent everything before it too. A recursive
				// element COUNT cannot prove the same thing: it also counts array
				// containers, so an intact 63-field submission was being refused
				// as "truncated" at a limit of 64.
				?>
				<input type="hidden" name="perflocale_form_end" value="1">
			</form>

			<div class="tablenav top perflocale-bulk-tablenav-top">
				<div class="alignleft actions bulkactions perflocale-bulkactions">
					<label for="perflocale-bulk-action-top" class="screen-reader-text"><?php echo esc_html__( 'Select bulk action', 'perflocale' ); ?></label>
					<select id="perflocale-bulk-action-top" name="bulk_action" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-select>
						<option value=""><?php echo esc_html__( 'Bulk actions', 'perflocale' ); ?></option>
						<?php if ( $mt_available ) : ?>
							<option value="mt_translate">
							<?php
							if ( $mt_provider_name !== '' ) {
								printf(
									/* translators: %s: machine-translation provider display name (e.g. "DeepL", "Google Translate") */
									esc_html__( 'Translate via %s', 'perflocale' ),
									esc_html( $mt_provider_name )
								);
							} else {
								esc_html_e( 'Machine-translate', 'perflocale' );
							}
							?>
							</option>
						<?php endif; ?>
						<option value="mark_needs_update"><?php echo esc_html__( 'Mark as Needs Update', 'perflocale' ); ?></option>
					</select>

					<select name="bulk_value" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-value-target-lang hidden aria-label="<?php echo esc_attr__( 'Target language', 'perflocale' ); ?>">
						<option value="all"><?php echo esc_html__( 'All target languages', 'perflocale' ); ?></option>
						<?php
						foreach ( $languages as $l ) :
							if ( ! empty( $l->is_default ) ) {
								continue; // can't translate INTO the source language
							}
							?>
							<option value="<?php echo esc_attr( $l->slug ); ?>">
								<?php echo esc_html( \PerfLocale\Helper::get_flag_emoji( $l ) . ' ' . ( $l->native_name ?: $l->name ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="button action" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-apply>
						<?php echo esc_html__( 'Apply', 'perflocale' ); ?>
					</button>

					<?php if ( $mt_available ) : ?>
						<label class="perflocale-bulk-meta-toggle" data-perflocale-bulk-meta hidden>
							<input type="checkbox" name="include_meta" value="1" form="<?php echo esc_attr( $bulk_form_id ); ?>" checked>
							<?php echo esc_html__( 'Include SEO/custom fields', 'perflocale' ); ?>
						</label>
					<?php endif; ?>
				</div>
				<div class="alignright perflocale-str-toolbar__count perflocale-str-toolbar__count--inline">
				<?php
					/* translators: %s: total item count (assignments, posts, or terms — i18n-formatted, may be wrapped in <strong>). */
					printf( esc_html__( '%s items', 'perflocale' ), '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>' );
				?>
				</div>
			</div>

			<div class="perflocale-table-responsive">
			<table class="wp-list-table widefat fixed striped perflocale-translations-table">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Translation Status by Language', 'perflocale' ); ?></caption>
				<colgroup>
					<col class="check-column">
					<col class="perflocale-col-title">
					<col class="perflocale-col-type">
					<?php foreach ( $visible_langs as $language ) : ?>
						<col class="perflocale-col-lang">
					<?php endforeach; ?>
				</colgroup>
				<thead>
					<tr>
						<th class="manage-column column-cb check-column" scope="col">
							<label class="perflocale-cb-label" for="perflocale-tr-cb-select-all-1">
								<input id="perflocale-tr-cb-select-all-1" type="checkbox" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-select-all>
								<span class="screen-reader-text"><?php echo esc_html__( 'Select All', 'perflocale' ); ?></span>
							</label>
						</th>
						<?php
						// ID column dropped — the source post ID is always
						// available via `View`/`Edit` links and the page-source
						// of the linked URL, and the visual real-estate is
						// better used by the title row-actions (Edit | View
						// hover affordances) and the wider language matrix.
						echo wp_kses_post( $this->sort_th( 'title', __( 'Title', 'perflocale' ), $orderby_key, $order, $base_args, 'perflocale-col-title' ) );
						echo wp_kses_post( $this->sort_th( 'type', __( 'Type', 'perflocale' ), $orderby_key, $order, $base_args, 'perflocale-col-type' ) );
						?>
						<?php
						foreach ( $visible_langs as $language ) :
							$lang_label = $language->native_name ?: ( $language->name ?: $language->slug );
							$lang_title = $language->name && $language->native_name && $language->name !== $language->native_name
								? $language->name . ' — ' . $language->native_name
								: ( $language->name ?: $language->native_name ?: $language->slug );
							?>
							<th scope="col" class="perflocale-col-lang" data-perflocale-lang-col="<?php echo esc_attr( $language->slug ); ?>" title="<?php echo esc_attr( $lang_title ); ?>">
								<span class="perflocale-th-lang">
									<span class="perflocale-th-lang__flag" aria-hidden="true"><?php echo esc_html( \PerfLocale\Helper::get_flag_emoji( $language ) ); ?></span>
									<span class="perflocale-th-lang__name"><?php echo esc_html( $lang_label ); ?></span>
								</span>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<?php
				// Pre-compute the visible row set so the empty-state check, the
				// rendered rows, and the colspan all agree. The source filter is
				// applied here at PHP level (language + status are SQL-pushed
				// up-stream via resolve_filtered_object_ids); without this
				// pre-pass the foreach would silently `continue` past every row
				// and the operator would see "X items" + zero rendered rows.
				$visible_rows                   = [];
				$active_language_ids_for_filter = array_map( static fn( $l ) => (int) $l->id, $languages );

				foreach ( $posts as $post ) {
					$translations    = $batch_map[ (int) $post->ID ] ?? [];
					$translation_map = [];
					foreach ( $translations as $link ) {
						$translation_map[ (int) $link->language_id ] = $link;
					}

					if ( $filter_language > 0 || $filter_status !== '' || $filter_source !== '' ) {
						if ( ! $this->matches_filters(
							$translation_map,
							$filter_language,
							$filter_status,
							$active_language_ids_for_filter,
							$filter_source,
							$source_language_id
						) ) {
							continue;
						}
					}

					$visible_rows[] = [
						'post'            => $post,
						'translation_map' => $translation_map,
					];
				}

				$any_filter_active = ( $filter_post_type !== '' || $lang_slug_filter !== '' || $filter_status !== '' || $filter_source !== '' || $search !== '' );
				?>
				<tbody>
					<?php if ( empty( $visible_rows ) ) : ?>
						<tr>
							<td class="perflocale-empty-row" colspan="<?php echo absint( 3 + count( $visible_langs ) ); ?>">
								<?php if ( $search !== '' && ! $any_filter_active ) : ?>
									<?php
									/* translators: %s: search query */
									printf( esc_html__( 'No posts found matching "%s".', 'perflocale' ), '<strong>' . esc_html( $search ) . '</strong>' );
									?>
								<?php elseif ( $any_filter_active ) : ?>
									<?php echo esc_html__( 'No translations match the current filters. Try clearing them with the Reset button above.', 'perflocale' ); ?>
								<?php else : ?>
									<?php echo esc_html__( 'No translatable content found.', 'perflocale' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php else : ?>
						<?php
						foreach ( $visible_rows as $row ) :
							$post            = $row['post'];
							$translation_map = $row['translation_map'];

							$post_type_obj = get_post_type_object( $post->post_type );
							$type_label    = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
							?>
							<?php
							$edit_url   = ObjectLinks::edit_url( $post->ID, $post->post_type );
							$view_url   = ObjectLinks::view_url( $post );
							$post_title = get_the_title( $post );

							if ( $post_title === '' ) {
								$post_title = __( '(no title)', 'perflocale' );
							}
							?>
							<tr>
								<th scope="row" class="check-column">
									<label class="perflocale-cb-label" for="perflocale-tr-cb-<?php echo absint( $post->ID ); ?>">
										<input id="perflocale-tr-cb-<?php echo absint( $post->ID ); ?>" type="checkbox" name="ids[]" value="<?php echo absint( $post->ID ); ?>" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-row>
										<span class="screen-reader-text">
											<?php
											printf(
												/* translators: %s: row label being selected (post title or source term). */
												esc_html__( 'Select %s', 'perflocale' ),
												esc_html( $post_title )
											);
											?>
										</span>
									</label>
								</th>
								<td class="perflocale-col-title has-row-actions column-primary">
									<strong>
										<?php if ( $edit_url !== '' ) : ?>
											<a class="row-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $post_title ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $post_title ); ?>
										<?php endif; ?>
									</strong>
									<div class="row-actions">
										<?php if ( $edit_url !== '' ) : ?>
											<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post title */ __( 'Edit &#8220;%s&#8221;', 'perflocale' ), $post_title ) ); ?>"><?php echo esc_html__( 'Edit', 'perflocale' ); ?></a></span>
										<?php endif; ?>
										<?php if ( $view_url !== '' && get_post_status( $post ) === 'publish' ) : ?>
											<?php
											if ( $edit_url !== '' ) :
												?>
												| <?php endif; ?>
											<span class="view"><a href="<?php echo esc_url( $view_url ); ?>" rel="bookmark" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post title */ __( 'View &#8220;%s&#8221;', 'perflocale' ), $post_title ) ); ?>"><?php echo esc_html__( 'View', 'perflocale' ); ?></a></span>
										<?php endif; ?>
									</div>
								</td>
								<td class="perflocale-col-type"><?php echo esc_html( $type_label ); ?></td>
								<?php foreach ( $visible_langs as $language ) : ?>
									<td class="perflocale-col-lang" data-perflocale-lang-col="<?php echo esc_attr( $language->slug ); ?>">
										<?php
										$lang_id = (int) $language->id;
										$this->render_translation_cell( $translation_map[ $lang_id ] ?? null, $post );
										?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			</div>

			<?php $this->render_tablenav_bottom( $paged, $total_pages, $total, $base_args ); ?>

			<?php $this->render_site_translate_panel( $mt_available, $mt_provider_name, $languages ); ?>
		</div>
		<?php
	}

	/**
	 * Render a single language-column cell for a source post's row.
	 *
	 * The badge shows `link.status` as render() resolved it: the link's
	 * effective status (TranslationStatus::effective() — empty / draft /
	 * pending / published / needs_update, from the linked post's status with
	 * a stored needs_update kept).
	 *
	 * A linked post that was deleted / trashed shows Empty.
	 *
	 * @param object|null $link translation_links row for this language (or null).
	 * @param \WP_Post    $post The source post being rendered.
	 * @return void
	 */
	private function render_translation_cell( ?object $link, \WP_Post $post ): void {
		$badge_label   = __( 'Empty', 'perflocale' );
		$badge_tone    = 'empty';
		$cross_link_id = 0;

		if ( $link && ! empty( $link->object_id ) ) {
			// Verify the linked post exists + isn't trashed. If it's gone,
			// the link row is orphaned and we should just show "Empty".
			$linked_post = get_post( (int) $link->object_id );

			if ( $linked_post instanceof \WP_Post && $linked_post->post_status !== 'trash' ) {
				// $link->status is the effective status resolved during batch
				// collection in render().
				$link_status = (string) ( $link->status ?? '' );
				$status_enum = TranslationStatus::tryFrom( $link_status );

				if ( $status_enum ) {
					$color       = $status_enum->color();
					$badge_label = $status_enum->label();
					$badge_tone  = match ( $color ) {
						'green' => 'published',
						'blue' => 'draft',
						'amber' => 'pending',
						'red' => 'needs-update',
						default => 'empty',
					};
				}

				if ( (int) $link->object_id !== (int) $post->ID ) {
					$cross_link_id = (int) $link->object_id;
				}
			}
		}

		// AI quality score → small badge alongside the status badge when
		// scoring is enabled and the persisted score is at-or-below the
		// configured threshold. Orthogonal to the status (a Published
		$review_badge = '';

		// Resolve the edit URL once. Linkable when this column points at a
		// DIFFERENT post (a sibling translation) — clicking the source-row's
		// own column would just re-open the title link, so leave that as a
		// plain span.
		$edit_url = '';

		if ( $cross_link_id > 0 ) {
			$resolved = ObjectLinks::edit_url( $cross_link_id );

			if ( $resolved !== '' ) {
				$edit_url = $resolved;
			}
		}

		// External-link icon (Dashicons), kept INSIDE the badge as the user
		// requested. Aria-hidden because the wrapping `<a>` already has its
		// own descriptive aria-label.
		$icon = '<span class="dashicons dashicons-external perflocale-status-badge__icon" aria-hidden="true"></span>';

		if ( $edit_url !== '' ) {
			// $icon is a hard-coded `<span class="dashicons …">` string defined
			// a few lines above — no user input is ever interpolated into it,
			// so feeding it through esc_html() would mangle the markup, and
			// wp_kses() would strip the dashicons class. Print verbatim.
			printf(
				'<a class="perflocale-status-badge perflocale-status-badge--%1$s perflocale-status-badge--linked" href="%2$s" aria-label="%3$s"><span class="perflocale-status-badge__label">%4$s</span>%5$s</a>%6$s',
				esc_attr( $badge_tone ),
				esc_url( $edit_url ),
				esc_attr(
					sprintf(
					/* translators: %s: status label e.g. "Published". */
						__( 'Edit translation (%s)', 'perflocale' ),
						$badge_label
					)
				),
				esc_html( $badge_label ),
				wp_kses(
					$icon,
					[
						'span' => [
							'class'       => [],
							'aria-hidden' => [],
						],
					]
				),
				wp_kses(
					$review_badge,
					[
						'a'    => [
							'class'      => [],
							'href'       => [],
							'title'      => [],
							'aria-label' => [],
						],
						'span' => [
							'class'      => [],
							'title'      => [],
							'aria-label' => [],
						],
					]
				)
			);
			return;
		}

		printf(
			'<span class="perflocale-status-badge perflocale-status-badge--%s"><span class="perflocale-status-badge__label">%s</span></span>%s',
			esc_attr( $badge_tone ),
			esc_html( $badge_label ),
			wp_kses(
				$review_badge,
				[
					'a'    => [
						'class'      => [],
						'href'       => [],
						'title'      => [],
						'aria-label' => [],
					],
					'span' => [
						'class'      => [],
						'title'      => [],
						'aria-label' => [],
					],
				]
			)
		);
	}

	/**
	 * Build a sortable `<th>` for the posts table. Follows the same markup
	 * WP core uses in WP_List_Table so the active-sort triangle + hover
	 * styling inherits the admin stylesheet automatically.
	 *
	 * @param string               $column Allow-listed column key (id/title/type).
	 * @param string               $label Translated header label.
	 * @param string               $orderby_key Currently-active orderby key.
	 * @param string               $order 'ASC' or 'DESC'.
	 * @param array<string, mixed> $base_args Query args to preserve in the sort URL.
	 * @param string               $extra_class Extra class (e.g. 'perflocale-col-id').
	 * @return string HTML for the <th>.
	 */
	private function sort_th( string $column, string $label, string $orderby_key, string $order, array $base_args, string $extra_class = '' ): string {
		$is_sorted  = ( $orderby_key === $column );
		$next_order = $is_sorted && $order === 'ASC' ? 'DESC' : 'ASC';

		$url_args = $base_args;
		unset( $url_args['paged'] );
		$url_args['orderby'] = $column;
		$url_args['order']   = $next_order;
		$url                 = add_query_arg( $url_args, admin_url( 'admin.php' ) );

		$class = 'manage-column sortable ' . ( $is_sorted ? ( strtolower( $order ) === 'asc' ? 'asc' : 'desc' ) : 'desc' );

		if ( $is_sorted ) {
			$class .= ' sorted';
		}

		if ( $extra_class !== '' ) {
			$class .= ' ' . $extra_class;
		}

		return sprintf(
			'<th scope="col" class="%1$s"><a href="%2$s"><span>%3$s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Run the screen's list query, with the condition from
	 * unreadable_rows_where() appended to its WHERE clause, so the rows the
	 * user may not read are neither listed nor counted in `found_posts`.
	 *
	 * The posts_where callback is attached for this one query only and acts
	 * only on a query carrying `perflocale_only_source` (this screen's).
	 *
	 * @param array<string, mixed> $query_args       WP_Query arguments.
	 * @param string               $unreadable_where Condition from unreadable_rows_where().
	 * @return \WP_Query
	 */
	private function run_list_query( array $query_args, string $unreadable_where ): \WP_Query {
		if ( $unreadable_where === '' ) {
			return new \WP_Query( $query_args );
		}

		$append = static function ( string $where, \WP_Query $q ) use ( $unreadable_where ): string {
			return $q->get( 'perflocale_only_source' ) ? $where . $unreadable_where : $where;
		};

		add_filter( 'posts_where', $append, 10, 2 );

		try {
			return new \WP_Query( $query_args );
		} finally {
			remove_filter( 'posts_where', $append, 10 );
		}
	}

	/**
	 * WHERE condition that leaves out the posts the current user may not
	 * read, or '' when they may read every post of these types.
	 *
	 * Follows core's read_post mapping (map_meta_cap()) for a post another
	 * user wrote in a status that is not public, per post type: a private
	 * status needs read_private_posts; any other status needs
	 * edit_others_posts, and `future` also edit_published_posts. Posts in a
	 * public status and the user's own posts stay listed. Each capability is
	 * checked once per post type, so this costs no query and no per-row
	 * check, and a user who holds them all (an administrator; an editor for
	 * posts and pages) gets ''. A post type registered without map_meta_cap
	 * maps read_post to one capability for all its posts and is not filtered
	 * here.
	 *
	 * @param array<int, string> $post_types Post types the list queries.
	 * @return string `' AND NOT ( … )'` or ''.
	 */
	private function unreadable_rows_where( array $post_types ): string {
		global $wpdb;

		$private_statuses = [];
		$other_statuses   = [];

		foreach ( get_post_stati( [], 'objects' ) as $name => $status_object ) {
			if ( ! empty( $status_object->public ) ) {
				continue;
			}

			if ( ! empty( $status_object->private ) ) {
				$private_statuses[] = (string) $name;
			} else {
				$other_statuses[] = (string) $name;
			}
		}

		$user_id = get_current_user_id();
		$clauses = [];

		foreach ( $post_types as $post_type ) {
			$type_object = get_post_type_object( (string) $post_type );

			if ( ! $type_object || ! $type_object->map_meta_cap ) {
				continue;
			}

			$hidden = current_user_can( $type_object->cap->read_private_posts ) ? [] : $private_statuses;

			if ( ! current_user_can( $type_object->cap->edit_others_posts ) ) {
				$hidden = array_merge( $hidden, $other_statuses );
			} elseif ( in_array( 'future', $other_statuses, true ) && ! current_user_can( $type_object->cap->edit_published_posts ) ) {
				$hidden[] = 'future';
			}

			if ( $hidden === [] ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $hidden ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $placeholders is a generated %s list bound to $hidden.
			$clause = (string) $wpdb->prepare(
				"( {$wpdb->posts}.post_type = %s AND {$wpdb->posts}.post_status IN ({$placeholders})",
				(string) $post_type,
				...$hidden
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

			if ( $user_id > 0 ) {
				$clause .= (string) $wpdb->prepare( " AND {$wpdb->posts}.post_author <> %d", $user_id );
			}

			$clauses[] = $clause . ' )';
		}

		return $clauses === [] ? '' : ' AND NOT ( ' . implode( ' OR ', $clauses ) . ' )';
	}

	/**
	 * Register a scoped posts_where filter that hides non-default-language
	 * translation rows when WP_Query is invoked with the
	 * `perflocale_only_source` query var set.
	 *
	 * Uses a prepared subquery - no user input reaches the SQL string.
	 * Registering once per render() is idempotent: WordPress de-dupes
	 * identical closure references, but we also guard via a static flag.
	 *
	 * @param LanguageRepository $lang_repo Language repository (for default lang).
	 * @return void
	 */
	private function register_source_only_filter( LanguageRepository $lang_repo ): void {
		// Keyed by blog, NOT a bare bool. The closure below captures this blog's
		// $wpdb->posts, its two plugin table names and its default-language id,
		// so every blog visited in a process registers its own closure. With a
		// single flag, a second blog would keep the first blog's closure and the
		// Translations screen would filter against another blog's tables (too
		// few rows, and an "IN/ALL/ANY subquery" error in $wpdb->last_error).
		// Same shape as
		// {@see \PerfLocale\Database\Repository\TranslationGroupRepository::eager_memo_key()}.
		static $registered = [];

		$blog_key = get_current_blog_id();

		if ( isset( $registered[ $blog_key ] ) ) {
			return;
		}

		$default_lang = $lang_repo->get_default();
		if ( ! $default_lang || empty( $default_lang->id ) ) {
			return;
		}

		global $wpdb;

		$links_table  = $wpdb->prefix . 'perflocale_translation_links';
		$groups_table = $wpdb->prefix . 'perflocale_translation_groups';
		$default_id   = (int) $default_lang->id;
		$type         = \PerfLocale\Enum\ObjectType::Post->value;

		// Pre-build the prepared subquery once. $type and $default_id are
		// the only dynamic values and both are placeholder-bound.
		// wpdb->prepare() can return null on placeholder mismatch — cast to
		// empty string so the .= concat below stays a string operation.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subquery = (string) $wpdb->prepare(
			" AND {$wpdb->posts}.ID NOT IN (
				SELECT l.object_id
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id
				WHERE g.type = %s AND l.language_id <> %d
			)",
			$links_table,
			$groups_table,
			$type,
			$default_id
		);
		// phpcs:enable

		add_filter(
			'posts_where',
			static function ( string $where, \WP_Query $q ) use ( $subquery, $blog_key ): string {
				// Belt and braces: even keyed per blog, a closure registered on
				// one blog stays on the `posts_where` hook after a
				// switch_to_blog(). Refuse to contribute its baked-in table
				// names anywhere but the blog it was built for.
				if ( get_current_blog_id() !== $blog_key ) {
					return $where;
				}

				if ( $q->get( 'perflocale_only_source' ) ) {
					$where .= $subquery;
				}

				return $where;
			},
			10,
			2
		);

		$registered[ $blog_key ] = true;
	}

	/**
	 * Read per-page from screen options (WP user-meta), fall back to 20.
	 *
	 * @return int
	 */
	private function get_per_page(): int {
		$user   = get_current_user_id();
		$screen = get_current_screen();
		$option = $screen ? $screen->get_option( 'per_page', 'option' ) : '';
		$val    = $option ? (int) get_user_meta( $user, $option, true ) : 0;

		// ⚠️ Clamp on READ. The save-time ceiling cannot touch values stored
		// BEFORE it existed: measured with max_input_vars=64 (ceiling 25), a
		// stored 777 still drove posts_per_page=777 and rendered every row.
		// The protection has to sit where the value is USED. The stored
		// preference is deliberately not rewritten - only the effective value.
		return \PerfLocale\Helper::normalize_per_page( (int) $val, 20, (string) $option );
	}

	/**
	 * "Translate the entire site" panel: language + post-type selection, a
	 * MANDATORY estimate step (the Start button unlocks only after a
	 * non-over-budget estimate), then a nonce'd POST that dispatches the
	 * chunked SiteTranslateJob background chain.
	 *
	 * @param bool                      $mt_available     Whether bulk MT can run.
	 * @param string                    $mt_provider_name Active provider display name.
	 * @param array<int|string, object> $languages        Active language rows.
	 * @return void
	 */
	private function render_site_translate_panel( bool $mt_available, string $mt_provider_name, array $languages ): void {
		if ( ! $mt_available || ! current_user_can( 'perflocale_manage_translations' ) ) {
			return;
		}

		// ⭐ Offer exactly what the handler will accept — see
		// Settings::get_site_translate_post_types(). Offering `public + show_ui`
		// here meant the panel listed types the plugin refuses to translate and
		// hid every addon type it would happily translate.
		$public_types = [];

		foreach ( $this->settings->get_site_translate_post_types() as $pt_name ) {
			$pt_object = get_post_type_object( $pt_name );

			if ( $pt_object instanceof \WP_Post_Type ) {
				$public_types[ $pt_name ] = $pt_object;
			}
		}
		?>
		<details class="perflocale-site-translate" data-perflocale-site-translate>
			<summary>
				<?php
				if ( $mt_provider_name !== '' ) {
					/* translators: %s: machine-translation provider display name */
					printf( esc_html__( 'Translate the entire site via %s', 'perflocale' ), esc_html( $mt_provider_name ) );
				} else {
					esc_html_e( 'Translate the entire site', 'perflocale' );
				}
				?>
			</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-translations' ) ); ?>" class="perflocale-site-translate__form">
				<?php wp_nonce_field( 'perflocale_translations_bulk' ); ?>
				<input type="hidden" name="perflocale_translations_action" value="site_translate">

				<fieldset class="perflocale-site-translate__group">
					<legend><?php echo esc_html__( 'Target languages', 'perflocale' ); ?></legend>
					<?php foreach ( $languages as $l ) : ?>
						<?php
						if ( ! empty( $l->is_default ) ) {
							continue;
						}
						?>
						<label>
							<input type="checkbox" name="site_lang_ids[]" value="<?php echo esc_attr( (string) (int) $l->id ); ?>" data-perflocale-site-lang>
							<?php echo esc_html( \PerfLocale\Helper::get_flag_emoji( $l ) . ' ' . ( $l->native_name ?: $l->name ) ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<fieldset class="perflocale-site-translate__group">
					<legend><?php echo esc_html__( 'Post types', 'perflocale' ); ?></legend>
					<?php foreach ( $public_types as $ptype ) : ?>
						<label>
							<input type="checkbox" name="site_post_types[]" value="<?php echo esc_attr( $ptype->name ); ?>" data-perflocale-site-type <?php checked( in_array( $ptype->name, [ 'post', 'page' ], true ) ); ?>>
							<?php echo esc_html( $ptype->labels->name ?? $ptype->name ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<label class="perflocale-site-translate__meta">
					<input type="checkbox" name="include_meta" value="1" checked>
					<?php echo esc_html__( 'Also translate SEO/custom fields (per Machine Translation settings)', 'perflocale' ); ?>
				</label>

				<p class="perflocale-site-translate__actions">
					<button type="button" class="button" data-perflocale-site-estimate><?php echo esc_html__( 'Estimate cost', 'perflocale' ); ?></button>
					<button type="submit" class="button button-primary" data-perflocale-site-start disabled><?php echo esc_html__( 'Start background translation', 'perflocale' ); ?></button>
					<span class="perflocale-site-translate__result" data-perflocale-site-result aria-live="polite"></span>
				</p>
				<p class="description">
					<?php echo esc_html__( 'Runs as a resumable background job in bounded chunks; existing translations are never overwritten. Track progress under PerfLocale → Jobs.', 'perflocale' ); ?>
				</p>
				<?php
				// ⭐ COMPLETENESS MARKER — must be the LAST field in the form.
				// PHP silently discards input past `max_input_vars`, so if this
				// arrives the browser sent everything before it too. A recursive
				// element COUNT cannot prove the same thing: it also counts array
				// containers, so an intact 63-field submission was being refused
				// as "truncated" at a limit of 64.
				?>
				<input type="hidden" name="perflocale_form_end" value="1">
			</form>
		</details>
		<?php
	}

	/**
	 * Render the WordPress-standard tablenav (bottom) with paginate_links().
	 *
	 * @param int                  $current Current page.
	 * @param int                  $total_pages Total pages.
	 * @param int                  $total_items Total items (across pages).
	 * @param array<string, mixed> $base_args URL args to preserve on nav.
	 * @return void
	 */
	private function render_tablenav_bottom( int $current, int $total_pages, int $total_items, array $base_args ): void {
		?>
		<div class="tablenav bottom">
			<?php $this->render_pagination_links( $current, $total_pages, $total_items, $base_args ); ?>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * Core pagination block reused by top + bottom tablenav. Uses
	 * paginate_links() for the numbered prev/1/2/…/N/next style that
	 * matches StringsPage.
	 *
	 * @param int                  $current Current page.
	 * @param int                  $total_pages Total pages.
	 * @param int                  $total_items Total items.
	 * @param array<string, mixed> $base_args URL args.
	 * @return void
	 */
	private function render_pagination_links( int $current, int $total_pages, int $total_items, array $base_args ): void {
		$page_links = paginate_links(
			[
				'base'      => add_query_arg( array_merge( $base_args, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => max( 1, $total_pages ),
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
	 * Render the filter bar above the table.
	 *
	 * @param array<int, string> $post_types Available post types.
	 * @param array<int, object> $languages Active languages.
	 * @param string             $filter_post_type Current post type filter.
	 * @param string             $lang_slug_filter Current language filter (slug, '' = all).
	 * @param string             $filter_status Current status filter.
	 * @return void
	 */
	private function render_filters(
		array $post_types,
		array $languages,
		string $filter_post_type,
		string $lang_slug_filter,
		string $filter_status,
		string $search = '',
		string $filter_source = ''
	): void {
		?>
		<form method="get" class="perflocale-str-filter" style="display:flex;align-items:center;margin:0;flex-wrap:wrap;">
			<input type="hidden" name="page" value="perflocale-translations">
			<?php if ( $search !== '' ) : ?>
				<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
			<?php endif; ?>

			<select name="post_type_filter" aria-label="<?php echo esc_attr__( 'Filter by post type', 'perflocale' ); ?>">
				<option value=""><?php echo esc_html__( 'All Post Types', 'perflocale' ); ?></option>
				<?php foreach ( $post_types as $pt ) : ?>
					<?php
					$pt_object = get_post_type_object( $pt );
					$pt_label  = $pt_object ? $pt_object->labels->name : $pt;
					?>
					<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $filter_post_type, $pt ); ?>>
						<?php echo esc_html( $pt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="perflocale_lang" aria-label="<?php echo esc_attr__( 'Filter by language', 'perflocale' ); ?>">
				<option value=""><?php echo esc_html__( 'All Languages', 'perflocale' ); ?></option>
				<?php foreach ( $languages as $lang ) : ?>
					<option value="<?php echo esc_attr( $lang->slug ); ?>" <?php selected( $lang_slug_filter, $lang->slug ); ?>>
						<?php echo esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . $lang->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="status_filter" aria-label="<?php echo esc_attr__( 'Filter by translation status', 'perflocale' ); ?>">
				<option value=""><?php echo esc_html__( 'All Statuses', 'perflocale' ); ?></option>
				<?php foreach ( TranslationStatus::cases() as $status ) : ?>
					<option value="<?php echo esc_attr( $status->value ); ?>" <?php selected( $filter_status, $status->value ); ?>>
						<?php echo esc_html( $status->label() ); ?>
					</option>
				<?php endforeach; ?>
				
			</select>

			<select name="source_filter" aria-label="<?php echo esc_attr__( 'Filter by translation source', 'perflocale' ); ?>">
				<option value=""><?php echo esc_html__( 'Any Source', 'perflocale' ); ?></option>
				<?php foreach ( \PerfLocale\Enum\SourceType::cases() as $src ) : ?>
					<option value="<?php echo esc_attr( $src->value ); ?>" <?php selected( $filter_source, $src->value ); ?>>
						<?php echo esc_html( $src->label() ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="button" style="white-space:nowrap;"><?php echo esc_html__( 'Filter', 'perflocale' ); ?></button>

			<?php if ( $filter_post_type !== '' || $lang_slug_filter !== '' || $filter_status !== '' || $filter_source !== '' ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						[
							'page' => 'perflocale-translations',
							's'    => $search !== '' ? $search : false,
						],
						admin_url( 'admin.php' )
					)
				);
				?>
				" class="button" style="white-space:nowrap;margin-left:0.5em;"><?php echo esc_html__( 'Reset', 'perflocale' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}


	/**
	 * Check if a post's translations match the current language/status filters.
	 *
	 * @param array<int, object> $translation_map Language ID => link map.
	 * @param int $filter_language Language ID filter (0 = all).
	 * @param string $filter_status Status filter (empty = all).
	 * @param array<int, int> $active_language_ids All active language IDs (for the 'empty'-across-all check).
	 * @return bool True if the post matches all active filters.
	 */
	/**
	 * Resolve the post IDs that match the active (language, status) filter
	 * combination at the SQL level. The returned set is used as `post__in`
	 * in WP_Query so pagination counts reflect filtered reality.
	 *
	 * Branches (mirror the matches_filters() semantics):
	 * - language > 0, status='empty' : posts MISSING the language
	 * - language > 0, status=other : posts with a translation in that language at that status
	 * - language > 0, status='' : posts WITH the language (any status)
	 * - language=0, status='empty' : posts missing at least one active language
	 * - language=0, status=other : posts with ANY translation at that status
	 *
	 * @param TranslationGroupRepository $group_repo Repository.
	 * @param int                        $filter_language Language filter.
	 * @param string                     $filter_status Status filter.
	 * @param array<int, int>            $active_language_ids All active lang IDs.
	 * @return array<int, int> Matching post IDs. Empty = no matches.
	 */
	private function resolve_filtered_object_ids(
		TranslationGroupRepository $group_repo,
		int $filter_language,
		string $filter_status,
		array $active_language_ids,
		string $filter_source = '',
		int $source_language_id = 0
	): array {
		$type = \PerfLocale\Enum\ObjectType::Post;

		if ( $filter_language > 0 ) {
			return $group_repo->find_source_object_ids_by_language_status(
				$type,
				$filter_language,
				$filter_status,
				$source_language_id
			);
		}

		if ( $filter_status === 'empty' ) {
			return $group_repo->find_source_object_ids_missing_any_language(
				$type,
				$active_language_ids,
				$source_language_id
			);
		}

		if ( $filter_status !== '' ) {
			return $group_repo->find_source_object_ids_by_status_any_language(
				$type,
				$filter_status,
				$source_language_id
			);
		}

		// No filter - caller shouldn't have invoked us.
		return [];
	}

	/**
	 * Check if a post's translations match the current language/status filters.
	 *
	 * @param array<int, object> $translation_map Language ID => link map.
	 * @param int                $filter_language Language ID filter (0 = all).
	 * @param string             $filter_status Status filter (empty = all).
	 * @param array<int, int>    $active_language_ids All active language IDs.
	 * @param string             $filter_source Source filter (empty = all).
	 * @param int                $source_language_id Default language ID (0 = none):
	 *                                               its link is the row itself, so
	 *                                               the all-language status filter
	 *                                               skips it.
	 * @return bool True if the post matches all active filters.
	 */

	private function matches_filters(
		array $translation_map,
		int $filter_language,
		string $filter_status,
		array $active_language_ids = [],
		string $filter_source = '',
		int $source_language_id = 0
	): bool {
		// Source filter intersects with the other filters: a row passes iff
		// at least one of its links matches both the source and the other
		// active filters. Pre-filter the map by source so the existing
		// language/status logic naturally agrees.
		if ( $filter_source !== '' ) {
			$translation_map = array_filter(
				$translation_map,
				static fn( $link ) => ( $link->source ?? '' ) === $filter_source
			);

			if ( empty( $translation_map ) ) {
				return false;
			}
		}

		// No filters active - always match.
		if ( $filter_language === 0 && $filter_status === '' ) {
			return true;
		}

		// Filter by specific language.
		if ( $filter_language > 0 ) {
			if ( ! isset( $translation_map[ $filter_language ] ) ) {
				// No translation for this language — only matches the 'empty'
				// status filter. Don't also match when status_filter is empty,
				// or the language dropdown alone becomes a no-op: picking
				// "German" alone should narrow to posts that have German.
				return $filter_status === 'empty';
			}

			if ( $filter_status !== '' ) {
				return $translation_map[ $filter_language ]->status === $filter_status;
			}

			return true;
		}

		// Filter by status across all languages.
		if ( $filter_status === 'empty' ) {
			// Match if any active language is missing a translation — not
			// unconditionally true, or status='empty' + "All Languages" would
			// be a no-op.
			foreach ( $active_language_ids as $lid ) {
				if ( ! isset( $translation_map[ $lid ] ) ) {
					return true;
				}
			}
			return false;
		}

		foreach ( $translation_map as $link ) {
			if ( $source_language_id > 0 && (int) ( $link->language_id ?? 0 ) === $source_language_id ) {
				continue;
			}

			if ( $link->status === $filter_status ) {
				return true;
			}
		}

		return false;
	}
}
