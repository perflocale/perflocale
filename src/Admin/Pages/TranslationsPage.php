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
 * Renders the filterable table of translatable content with per-language status badges (a simplified list table,
 * not a WP_List_Table subclass).
 */
final class TranslationsPage {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Most rows a list longer than one page is checked over to page and count it by the rows the user may view.
	 */
	private const VIEW_CHECK_MAX = 2000;

	/**
	 * Whether the list's count includes rows the host refuses the user (past VIEW_CHECK_MAX rows): not shown.
	 */
	private bool $count_hidden = false;

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

		// The user's hidden language columns (as on the Strings screen).
		$hidden_langs = (array) get_user_meta( get_current_user_id(), 'perflocale_translations_hidden_langs', true );

		// Read-only filters: no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter_post_type = isset( $_GET['post_type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		// The language filter's slug form (`?perflocale_lang=de`), as the admin bar switcher and every list screen use it.
		$lang_slug_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';
		$filter_language  = 0;

		if ( $lang_slug_filter !== '' ) {
			$resolved        = $lang_repo->find_by_slug( $lang_slug_filter );
			$filter_language = $resolved ? (int) $resolved->id : 0;
		}
		// A status the filter offers, or none; the all-language selector caches one result per status.
		$filter_status_raw = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : '';
		$filter_status     = $filter_status_raw !== ''
			? ( TranslationStatus::tryFrom( $filter_status_raw )?->value ?? '' )
			: '';
		// Source filter (translation_links.source), validated with SourceType::tryFrom().
		$filter_source_raw = isset( $_GET['source_filter'] ) ? sanitize_key( wp_unslash( $_GET['source_filter'] ) ) : '';
		$filter_source     = $filter_source_raw !== ''
			? ( \PerfLocale\Enum\SourceType::tryFrom( $filter_source_raw )?->value ?? '' )
			: '';
		$search            = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged             = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// Allow-listed orderby => WP_Query orderby; anything else sorts by modified DESC.
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

		// Query args.
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
			// Read by the posts_where filter below: only each group's default-language source row is listed (posts without
			// links stay visible).
			'perflocale_only_source' => true,
		];

		// Title search through WP_Query's `s`.
		if ( $search !== '' ) {
			$query_args['s'] = $search;
		}

		// One-shot posts_where filter excluding non-default-language translations, scoped by `perflocale_only_source`.
		$this->register_source_only_filter( $lang_repo );

		// Rows the user may not read are neither listed nor counted (run_list_query()); '' when every row is readable.
		$unreadable_where = $this->unreadable_rows_where( $query_post_types ) . $this->non_mapped_unreadable_where( $query_post_types );

		// Language and status filters resolve matching ids up front and go in as `post__in`, so found_posts and the
		// paginator count the filtered set. The source filter runs in PHP (matches_filters()); its count drift is mild.
		$filter_active = ( $filter_language > 0 || $filter_status !== '' );

		// The source-only filter keeps default-language links only, so id resolution is bound to that language (0 without
		// one, and then that filter is not registered). The all-language status filter skips this language's link too.
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
				// No matches: the empty state without a WP_Query.
				$posts       = [];
				$total       = 0;
				$total_pages = 0;
				$query       = null;
			} else {
				$query_args['post__in'] = $matching_ids;
				// Keep the requested order (modified DESC), not the post__in order.
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

		// Each link's effective status (TranslationStatus::effective(), the PHP twin of the filters' SQL), once here so
		// matches_filters() stays pure; _prime_post_caches() makes the get_post() calls cache hits.
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

			// The same status definition as TranslationLinkRepository::effective_status_sql(), so the Dashboard, `wp
			// perflocale status` and the filter agree.
			foreach ( $batch_map as $links ) {
				foreach ( $links as $link ) {
					$linked_post  = get_post( (int) ( $link->object_id ?? 0 ) );
					$link->status = TranslationStatus::effective(
						(string) ( $link->status ?? '' ),
						$linked_post instanceof \WP_Post ? $linked_post->post_status : null
					);
				}
			}

			if ( is_array( $batch_map ) ) {
				$batch_map = self::mask_unviewable_siblings( $batch_map );
			}
		}

		// URL args kept for pagination, filters and sorting.
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

			// The provider's name, so the bulk label says "Translate via DeepL into...".
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
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Nothing was flagged: the database rejected the update. The translations are unchanged - check the error log and try again.', 'perflocale' ); ?></p></div>
			<?php elseif ( $bulk_message === 'bulk_mt_unavailable' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Machine translation is unavailable. Configure a provider with a valid API key under Settings → Addons → Machine Translation.', 'perflocale' ); ?></p></div>
			<?php
			/*
			 * Outcomes of "Translate the entire site", redirected with the `message` key this block reads.
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
					esc_html__( 'Nothing to translate. These post types are marked translatable but are not registered right now - the plugin that provides them is probably inactive: %s', 'perflocale' ),
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
					esc_html__( 'Nothing was changed. The form did not arrive complete - this server accepts at most %d form fields (max_input_vars), and the request reached that limit. Lower "Rows per page" in Screen Options, or select fewer rows, and try again.', 'perflocale' ),
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
			 * Hint for the non-public content types (off by default), shown only to sites that have such content. Runs only on
			 * this screen; with both types enabled it asks nothing, else wp_count_posts(), which core caches.
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
					// wp_count_posts(), not a COUNT: core caches the per-status counts and invalidates them on save.
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
			// The bulk form is outside the table; the row checkboxes bind to it with the HTML5 `form` attribute.
			$bulk_form_id = 'perflocale-translations-bulk';
			$check_column = current_user_can( 'perflocale_manage_translations' );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-translations' ) ); ?>" id="<?php echo esc_attr( $bulk_form_id ); ?>" class="perflocale-bulk-form" data-perflocale-bulk-form>
				<?php wp_nonce_field( 'perflocale_translations_bulk' ); ?>
				<input type="hidden" name="perflocale_translations_action" value="bulk">
				<?php
				// Replay the filters so the redirect returns to the same view.
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
				// Completeness marker, the last field: PHP drops input past max_input_vars, so its arrival proves the rest arrived
				// (an element count would also count array containers).
				?>
				<input type="hidden" name="perflocale_form_end" value="1">
			</form>

			<div class="tablenav top perflocale-bulk-tablenav-top">
				<?php $this->render_bulk_actions( $bulk_form_id, $mt_available, $mt_provider_name, $languages ); ?>
				<div class="alignright perflocale-str-toolbar__count perflocale-str-toolbar__count--inline">
				<?php
				if ( ! $this->count_hidden ) {
					/* translators: %s: total item count (assignments, posts, or terms - i18n-formatted, may be wrapped in <strong>). */
					printf( esc_html__( '%s items', 'perflocale' ), '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>' );
				}
				?>
				</div>
			</div>

			<div class="perflocale-table-responsive">
			<table class="wp-list-table widefat fixed striped perflocale-translations-table">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Translation Status by Language', 'perflocale' ); ?></caption>
				<colgroup>
					<?php if ( $check_column ) : ?>
						<col class="check-column">
					<?php endif; ?>
					<col class="perflocale-col-title">
					<col class="perflocale-col-type">
					<?php foreach ( $visible_langs as $language ) : ?>
						<col class="perflocale-col-lang">
					<?php endforeach; ?>
				</colgroup>
				<thead>
					<tr>
						<?php $this->render_select_all_cell( $bulk_form_id ); ?>
						<?php
						// No ID column: the source id is on the View and Edit links, and the room goes to the language matrix.
						echo wp_kses_post( $this->sort_th( 'title', __( 'Title', 'perflocale' ), $orderby_key, $order, $base_args, 'perflocale-col-title' ) );
						echo wp_kses_post( $this->sort_th( 'type', __( 'Type', 'perflocale' ), $orderby_key, $order, $base_args, 'perflocale-col-type' ) );
						?>
						<?php
						foreach ( $visible_langs as $language ) :
							$lang_label = $language->native_name ?: ( $language->name ?: $language->slug );
							$lang_title = $language->name && $language->native_name && $language->name !== $language->native_name
								? $language->name . ' - ' . $language->native_name
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
				// The visible rows, computed once so the empty state, the rows and the colspan agree (the source filter applies
				// here; language and status were applied in SQL).
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
							<td class="perflocale-empty-row" colspan="<?php echo absint( ( $check_column ? 3 : 2 ) + count( $visible_langs ) ); ?>">
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
								<?php $this->render_row_check_cell( $bulk_form_id, (int) $post->ID, $post_title ); ?>
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
	 * Render one language cell of a source row: a badge of the link's effective status as render() resolved it
	 * (TranslationStatus::effective()); a deleted or trashed linked post shows Empty.
	 *
	 * @param object|null $link translation_links row for this language (or null).
	 * @param \WP_Post    $post The source post being rendered.
	 * @return void
	 */
	private function render_translation_cell( ?object $link, \WP_Post $post ): void {
		$badge_label   = __( 'Empty', 'perflocale' );
		$badge_tone    = 'empty';
		$cross_link_id = 0;

		if ( $link && ! empty( $link->perflocale_hidden ) ) {
			$badge_label = __( 'Translated', 'perflocale' );
		} elseif ( $link && ! empty( $link->object_id ) ) {
			// A linked post that is gone or trashed: the link is orphaned, show Empty.
			$linked_post = get_post( (int) $link->object_id );

			if ( $linked_post instanceof \WP_Post && $linked_post->post_status !== 'trash' ) {
				// The effective status resolved in render().
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

		// A link only when the column names another post (a sibling); the row's own column would repeat the title link.
		$edit_url = '';

		if ( $cross_link_id > 0 ) {
			$resolved = ObjectLinks::edit_url( $cross_link_id );

			if ( $resolved !== '' ) {
				$edit_url = $resolved;
			}
		}

		// External-link icon inside the badge; aria-hidden, as the <a> has its own label.
		$icon = '<span class="dashicons dashicons-external perflocale-status-badge__icon" aria-hidden="true"></span>';

		if ( $edit_url !== '' ) {
			// $icon is a fixed string from above: esc_html() would break it and wp_kses() strip the dashicons class.
			printf(
				'<a class="perflocale-status-badge perflocale-status-badge--%1$s perflocale-status-badge--linked" href="%2$s" aria-label="%3$s"><span class="perflocale-status-badge__label">%4$s</span>%5$s</a>',
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
				)
			);
			return;
		}

		printf(
			'<span class="perflocale-status-badge perflocale-status-badge--%s"><span class="perflocale-status-badge__label">%s</span></span>',
			esc_attr( $badge_tone ),
			esc_html( $badge_label )
		);
	}

	/**
	 * Whether the user may see a sibling; an unregistered type (an inactive builder's) gets core's own read_post
	 * fallback, edit_others_posts, without core's doing-it-wrong notice.
	 *
	 * @param int $object_id Sibling post.
	 * @return bool
	 */
	private static function sibling_viewable( int $object_id ): bool {
		$type = get_post_type( $object_id );

		if ( false === $type ) {
			return false;
		}

		return post_type_exists( $type ) ? \PerfLocale\Helper::user_can_view_object( $object_id ) : current_user_can( 'edit_others_posts' );
	}

	/**
	 * Replace each linked sibling the user may not view with a link that names only its language.
	 *
	 * The cell then shows "Translated" with no ID, status, source or link, and no status or source filter matches it.
	 *
	 * @param array<mixed> $batch_map Source post ID => its translation links.
	 * @return array<mixed>
	 */
	private static function mask_unviewable_siblings( array $batch_map ): array {
		foreach ( $batch_map as $source_id => $links ) {
			if ( ! is_array( $links ) ) {
				continue;
			}

			foreach ( $links as $i => $link ) {
				$vars      = is_object( $link ) ? get_object_vars( $link ) : [];
				$object_id = is_numeric( $vars['object_id'] ?? null ) ? (int) $vars['object_id'] : 0;

				if ( $object_id > 0 && $object_id !== (int) $source_id && ! self::sibling_viewable( $object_id ) ) {
					$links[ $i ] = (object) [
						'language_id'       => is_numeric( $vars['language_id'] ?? null ) ? (int) $vars['language_id'] : 0,
						'object_id'         => 0,
						'status'            => '',
						'source'            => '',
						'perflocale_hidden' => true,
					];
				}
			}

			$batch_map[ $source_id ] = $links;
		}

		return $batch_map;
	}

	/**
	 * A sortable `<th>` with WP_List_Table's markup, so the admin styles apply.
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
	 * Run the list query with unreadable_rows_where() appended (this query only, `perflocale_only_source`), so rows the
	 * user may not read are neither listed nor counted. A listed post its host refuses drops the type's view set and
	 * the list runs again; a list longer than one page is paged and counted by viewable rows (viewable_ids()).
	 *
	 * @param array<string, mixed> $query_args       WP_Query arguments.
	 * @param string               $unreadable_where Condition from unreadable_rows_where().
	 * @return \WP_Query
	 */
	private function run_list_query( array $query_args, string $unreadable_where ): \WP_Query {
		$query = $this->list_query_where( $query_args, $unreadable_where );
		$stale = $this->revoked_view_types( $query->posts );

		if ( [] !== $stale ) {
			array_map( [ $this, 'forget_view_set' ], $stale );
			$types            = array_values( array_filter( (array) ( $query_args['post_type'] ?? [] ), 'is_string' ) );
			$unreadable_where = $this->unreadable_rows_where( $types ) . $this->non_mapped_unreadable_where( $types );
			$query            = $this->list_query_where( $query_args, $unreadable_where );
		}

		// A refused row on any page shifts every later page and the count: both come from the viewable rows.
		$one_page = $query->found_posts <= count( $query->posts );
		$ids      = $one_page ? null : $this->viewable_ids( $query_args, $unreadable_where );

		if ( null !== $ids ) {
			$per  = is_int( $query_args['posts_per_page'] ?? null ) ? max( 1, $query_args['posts_per_page'] ) : 20;
			$at   = is_int( $query_args['paged'] ?? null ) ? max( 1, $query_args['paged'] ) : 1;
			$page = array_slice( $ids, ( $at - 1 ) * $per, $per );

			_prime_post_caches( $page, false, false );
			$query->posts         = array_values( array_filter( array_map( 'get_post', $page ) ) );
			$query->post_count    = count( $query->posts );
			$query->found_posts   = count( $ids );
			$query->max_num_pages = (int) ceil( count( $ids ) / $per );

			return $query;
		}

		$denied = $this->refused_mapped_ids( $query->posts );

		if ( [] === $denied ) {
			return $query;
		}

		// No refill from the next page: its first row would then show on both pages.
		$query->posts       = array_values( array_filter( $query->posts, static fn( $p ): bool => ! ( $p instanceof \WP_Post && in_array( $p->ID, $denied, true ) ) ) );
		$query->post_count  = count( $query->posts );
		$this->count_hidden = ! $one_page;

		if ( $one_page ) {
			$query->found_posts = $query->post_count;
		}

		return $query;
	}

	/**
	 * IDs of the whole list in its order without the mapped-type posts the host refuses, null past VIEW_CHECK_MAX rows.
	 *
	 * @param array<string, mixed> $query_args       WP_Query arguments.
	 * @param string               $unreadable_where Condition from unreadable_rows_where().
	 * @return array<int, int>|null
	 */
	private function viewable_ids( array $query_args, string $unreadable_where ): ?array {
		$all = $this->list_query_where( [ 'fields' => 'ids', 'posts_per_page' => self::VIEW_CHECK_MAX + 1, 'paged' => 1, 'no_found_rows' => true ] + $query_args, $unreadable_where )->posts;

		if ( count( $all ) > self::VIEW_CHECK_MAX ) {
			return null;
		}

		$out = [];

		foreach ( array_chunk( array_values( array_filter( $all, 'is_int' ) ), 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			$refused = $this->refused_mapped_ids( array_map( 'get_post', $chunk ) );
			$out     = array_merge( $out, array_values( array_diff( $chunk, $refused ) ) );
			\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
		}

		return $out;
	}

	/**
	 * Listed posts of types with map_meta_cap the user may not view: a host refusal core's caps cannot show.
	 *
	 * @param array<mixed> $posts Posts the list query returned.
	 * @return array<int, int>
	 */
	private function refused_mapped_ids( array $posts ): array {
		$ids = [];

		foreach ( $posts as $post ) {
			$type_object = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : null;

			if ( $post instanceof \WP_Post && $type_object && $type_object->map_meta_cap && ! \PerfLocale\Helper::user_can_view_object( $post->ID ) ) {
				$ids[] = $post->ID;
			}
		}

		return $ids;
	}

	/**
	 * The list query with the condition appended for this one query.
	 *
	 * @param array<string, mixed> $query_args       WP_Query arguments.
	 * @param string               $unreadable_where Condition to append.
	 * @return \WP_Query
	 */
	private function list_query_where( array $query_args, string $unreadable_where ): \WP_Query {
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
	 * WHERE condition leaving out posts the user may not read ('' when they may read all of these types).
	 *
	 * Core's read_post mapping for other users' non-public posts, once per type: private needs read_private_posts,
	 * others edit_others_posts (`future` also edit_published_posts); no map_meta_cap: non_mapped_unreadable_where().
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
	 * WHERE condition that keeps, of each post type without map_meta_cap, only the posts the user may view.
	 *
	 * Each post is checked by its host (Helper::user_can_view_object()), cached per user. The shorter id list
	 * is bound; the NOT IN form admits only live posts up to the highest id the check read.
	 *
	 * @param array<int, string> $post_types Post types the list queries.
	 * @return string One `' AND ( … )'` per such type, or ''.
	 */
	private function non_mapped_unreadable_where( array $post_types ): string {
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$where = '';

		foreach ( $post_types as $post_type ) {
			$type_object = get_post_type_object( (string) $post_type );

			if ( ! $type_object || $type_object->map_meta_cap ) {
				continue;
			}

			[ $viewable, $max, $ids ] = $this->non_mapped_view_set( (string) $post_type );

			if ( $viewable && [] === $ids ) {
				$where .= (string) $wpdb->prepare( ' AND %i.post_type <> %s', $wpdb->posts, (string) $post_type );

				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a generated %d list bound to $ids.
			if ( $viewable ) {
				$where .= (string) $wpdb->prepare(
					' AND ( %i.post_type <> %s OR %i.ID IN (' . $placeholders . ') )',
					$wpdb->posts,
					(string) $post_type,
					$wpdb->posts,
					...$ids
				);

				continue;
			}

			$where .= (string) $wpdb->prepare( " AND ( %i.post_type <> %s OR ( %i.post_status NOT IN ('trash', 'auto-draft') AND %i.ID <= %d", $wpdb->posts, (string) $post_type, $wpdb->posts, $wpdb->posts, $max );

			if ( [] !== $ids ) {
				$where .= (string) $wpdb->prepare( ' AND %i.ID NOT IN (' . $placeholders . ')', $wpdb->posts, ...$ids );
			}
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.NotPrepared

			$where .= ' ) )';
		}

		return $where;
	}

	/**
	 * The user's view of one type, kept 600 s: [ true, max, viewable ] or [ false, max, others ], stamped with the
	 * user's caps and the type's rows; with a persistent cache prefixed `<last_changed>:<expiry>:` to skip the rows stamp.
	 *
	 * @param string $post_type Post type registered without map_meta_cap.
	 * @return array{0: bool, 1: int, 2: array<int, int>} Ids newest first; max is the highest id the check read.
	 */
	private function non_mapped_view_set( string $post_type ): array {
		$user   = wp_get_current_user();
		$ext    = wp_using_ext_object_cache();
		$cache  = Plugin::get_instance()->cache();
		$name   = $cache->derive_transient_key( 'translations_readable_' . (int) $user->ID . '_' . $post_type, 'perflocale' );
		$sign   = [ get_current_blog_id(), (int) $user->ID, $user->allcaps, $post_type ];
		$stored = $ext ? wp_cache_get( $name, 'perflocale' ) : get_transient( $name );
		$stored = is_string( $stored ) ? $stored : '';
		$fast   = '';
		$expiry = 0;

		if ( $ext ) {
			$fast                      = md5( (string) wp_json_encode( [ ...$sign, wp_cache_get_last_changed( 'posts' ) ] ) );
			[ $was, $expiry, $stored ] = array_pad( explode( ':', $stored, 3 ), 3, '' );
			$expiry                    = (int) $expiry > time() ? (int) $expiry : 0;
			$stored                    = $expiry > 0 ? $stored : '';
			$set                       = $was === $fast && '' !== $stored ? self::unpack_view_set( substr( $stored, 33 ) ) : null;

			if ( null !== $set ) {
				return $set;
			}
		}

		$rows = $this->non_mapped_rows_stamp( $post_type );

		if ( null === $rows ) {
			return $this->check_non_mapped_ids( $post_type );
		}

		$stamp = md5( (string) wp_json_encode( [ ...$sign, $rows ] ) ) . ':';
		$set   = str_starts_with( $stored, $stamp ) ? self::unpack_view_set( substr( $stored, 33 ) ) : null;

		if ( null === $set ) {
			$set    = $this->check_non_mapped_ids( $post_type );
			$stored = $stamp . self::pack_view_set( $set );
			$expiry = time() + 10 * MINUTE_IN_SECONDS;

			if ( ! $ext ) {
				set_transient( $name, $stored, 10 * MINUTE_IN_SECONDS );
			}
		}

		// Stamped again under this last_changed, never past the set's own expiry.
		if ( $ext ) {
			wp_cache_set( $name, $fast . ':' . $expiry . ':' . $stored, 'perflocale', max( 1, $expiry - time() ) );
		}

		return $set;
	}

	/**
	 * Drop the current user's stored view set of one type, on the path in use.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function forget_view_set( string $post_type ): void {
		$cache = Plugin::get_instance()->cache();
		$name  = $cache->derive_transient_key( 'translations_readable_' . (int) wp_get_current_user()->ID . '_' . $post_type, 'perflocale' );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $name, 'perflocale' );

			return;
		}

		delete_transient( $name );
	}

	/**
	 * Types without map_meta_cap with a listed post the user may not view any more (one host check per listed post).
	 *
	 * @param array<mixed> $posts Posts the list query returned.
	 * @return array<int, string>
	 */
	private function revoked_view_types( array $posts ): array {
		$stale = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || isset( $stale[ $post->post_type ] ) ) {
				continue;
			}

			$type        = $post->post_type;
			$type_object = get_post_type_object( $type );

			if ( $type_object && ! $type_object->map_meta_cap && ! \PerfLocale\Helper::user_can_view_object( $post->ID ) ) {
				$stale[ $type ] = $type;
			}
		}

		return array_values( $stale );
	}

	/**
	 * A view set as stored: `<i|n>:<max>:<ids>` (i: the viewable ids, n: the others).
	 *
	 * @param array{0: bool, 1: int, 2: array<int, int>} $set View set.
	 * @return string
	 */
	private static function pack_view_set( array $set ): string {
		return ( $set[0] ? 'i' : 'n' ) . ':' . $set[1] . ':' . implode( ',', $set[2] );
	}

	/**
	 * A stored view set, or null unless it reads back the same (positive ids, no stray byte). No regex: a repeated
	 * group exhausts the PCRE JIT stack on thousands of ids.
	 *
	 * @param string $packed Stored value without the stamp.
	 * @return array{0: bool, 1: int, 2: array<int, int>}|null
	 */
	private static function unpack_view_set( string $packed ): ?array {
		$parts = explode( ':', $packed );

		if ( 3 !== count( $parts ) || ! in_array( $parts[0], [ 'i', 'n' ], true ) ) {
			return null;
		}

		$max = (int) $parts[1];
		$ids = '' === $parts[2] ? [] : array_map( 'intval', explode( ',', $parts[2] ) );

		if ( (string) $max !== $parts[1] || $max < 0 || implode( ',', $ids ) !== $parts[2] || ( [] !== $ids && min( $ids ) < 1 ) ) {
			return null;
		}

		return [ 'i' === $parts[0], $max, $ids ];
	}

	/**
	 * Every live post of one type checked for the user (Helper::user_can_view_object()) as a view set; trash and
	 * auto-drafts count as not viewable, and the shorter id list is kept.
	 *
	 * @param string $post_type Post type registered without map_meta_cap.
	 * @return array{0: bool, 1: int, 2: array<int, int>}
	 */
	private function check_non_mapped_ids( string $post_type ): array {
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached per user by the caller.
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_status FROM %i WHERE post_type = %s ORDER BY ID DESC', $wpdb->posts, $post_type ), ARRAY_A );
		$live    = [];
		$others  = [];
		$allowed = [];
		$max     = 0;

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id = isset( $row['ID'] ) && is_numeric( $row['ID'] ) ? (int) $row['ID'] : 0;

			if ( $id < 1 ) {
				continue;
			}

			$max = max( $max, $id );

			if ( in_array( $row['post_status'] ?? '', [ 'trash', 'auto-draft' ], true ) ) {
				$others[] = $id;
			} else {
				$live[] = $id;
			}
		}

		foreach ( array_chunk( $live, 100 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );

			foreach ( $chunk as $id ) {
				if ( \PerfLocale\Helper::user_can_view_object( $id ) ) {
					$allowed[] = $id;
				} else {
					$others[] = $id;
				}
			}

			\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
		}

		rsort( $others );

		if ( [] === $allowed ) {
			return [ true, $max, [] ];
		}

		return count( $others ) < count( $allowed ) ? [ false, $max, $others ] : [ true, $max, $allowed ];
	}

	/**
	 * A stamp of one type's rows: per post status the count, id sum, author sum and newest edit.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, array<mixed>>|null Null when the query fails.
	 */
	private function non_mapped_rows_stamp( string $post_type ): ?array {
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- validates the stored list on each view.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) AS n, SUM(ID) AS ids, SUM(post_author) AS authors, MAX(post_modified_gmt) AS modified FROM %i WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') GROUP BY post_status ORDER BY post_status", $wpdb->posts, $post_type ), ARRAY_A );

		return is_array( $rows ) && '' === $wpdb->last_error ? $rows : null;
	}

	/**
	 * Register the posts_where filter that hides non-default-language translations for a WP_Query with
	 * `perflocale_only_source` (a prepared subquery, once per blog).
	 *
	 * @param LanguageRepository $lang_repo Language repository (for default lang).
	 * @return void
	 */
	private function register_source_only_filter( LanguageRepository $lang_repo ): void {
		// Per blog, not one flag: the closure captures this blog's tables and default language, and a single flag would
		// filter another blog against the first blog's tables (as TranslationGroupRepository::eager_memo_key()).
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

		// Built once, both values bound; prepare() can return null, so cast to string.
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
				// A closure stays on `posts_where` after switch_to_blog(): only its own blog gets its table names.
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
	 * Rows per page from the screen options, else 20.
	 *
	 * @return int
	 */
	private function get_per_page(): int {
		$user   = get_current_user_id();
		$screen = get_current_screen();
		$option = $screen ? $screen->get_option( 'per_page', 'option' ) : '';
		$val    = $option ? (int) get_user_meta( $user, $option, true ) : 0;

		// Clamped on read: a value stored before the save-time ceiling existed would still list every row. The stored
		// preference itself is left as is.
		return \PerfLocale\Helper::normalize_per_page( (int) $val, 20, (string) $option );
	}

	/**
	 * The select-all header cell of the Translations list, for managers only (the checkboxes feed the bulk actions).
	 *
	 * @param string $bulk_form_id Id of the bulk form.
	 * @return void
	 */
	private function render_select_all_cell( string $bulk_form_id ): void {
		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			return;
		}
		?>
						<th class="manage-column column-cb check-column" scope="col">
							<label class="perflocale-cb-label" for="perflocale-tr-cb-select-all-1">
								<input id="perflocale-tr-cb-select-all-1" type="checkbox" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-select-all>
								<span class="screen-reader-text"><?php echo esc_html__( 'Select All', 'perflocale' ); ?></span>
							</label>
						</th>
		<?php
	}

	/**
	 * A row's checkbox cell of the Translations list, for managers only.
	 *
	 * @param string $bulk_form_id Id of the bulk form.
	 * @param int    $post_id      Source post ID.
	 * @param string $post_title   Row label.
	 * @return void
	 */
	private function render_row_check_cell( string $bulk_form_id, int $post_id, string $post_title ): void {
		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			return;
		}
		?>
								<th scope="row" class="check-column">
									<label class="perflocale-cb-label" for="perflocale-tr-cb-<?php echo absint( $post_id ); ?>">
										<input id="perflocale-tr-cb-<?php echo absint( $post_id ); ?>" type="checkbox" name="ids[]" value="<?php echo absint( $post_id ); ?>" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-perflocale-bulk-row>
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
		<?php
	}

	/**
	 * Bulk action select, target language, Apply and the meta toggle of the Translations list, for managers only.
	 *
	 * @param string                    $bulk_form_id     Id of the form the controls submit.
	 * @param bool                      $mt_available     Whether bulk MT can run.
	 * @param string                    $mt_provider_name Active provider display name.
	 * @param array<int|string, object> $languages        Active language rows.
	 * @return void
	 */
	private function render_bulk_actions( string $bulk_form_id, bool $mt_available, string $mt_provider_name, array $languages ): void {
		// The bulk form handler requires this capability for every action.
		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			return;
		}
		?>
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
							continue; // The source language is never a target.
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
		<?php
	}

	/**
	 * The "Translate the entire site" panel: languages and post types, a required estimate (Start unlocks only within
	 * budget), then a nonce'd POST that dispatches the SiteTranslateJob chain.
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

		// Offer exactly what the handler accepts: Settings::get_site_translate_post_types().
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
				// Completeness marker, the last field: PHP drops input past max_input_vars, so its arrival proves the rest arrived
				// (an element count would also count array containers).
				?>
				<input type="hidden" name="perflocale_form_end" value="1">
			</form>
		</details>
		<?php
	}

	/**
	 * The bottom tablenav with paginate_links().
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
	 * The pagination of both tablenavs (paginate_links(), as StringsPage).
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
	 * Post ids matching the language and status filters in SQL, used as `post__in` so pagination counts the filtered
	 * set (the same cases as matches_filters(): a language missing or present at a status, any missing, any at a status).
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

		// No filter: not called then.
		return [];
	}

	/**
	 * Whether a post's translations match the language, status and source filters.
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
		// A row passes when one of its links matches the source and the other filters: pre-filter the map by source.
		if ( $filter_source !== '' ) {
			$translation_map = array_filter(
				$translation_map,
				static fn( $link ) => ( $link->source ?? '' ) === $filter_source
			);

			if ( empty( $translation_map ) ) {
				return false;
			}
		}

		// No filters: match.
		if ( $filter_language === 0 && $filter_status === '' ) {
			return true;
		}

		// One language.
		if ( $filter_language > 0 ) {
			if ( ! isset( $translation_map[ $filter_language ] ) ) {
				// No translation in it: matches only the 'empty' status (the language filter alone narrows to posts that have it).
				return $filter_status === 'empty';
			}

			if ( $filter_status !== '' ) {
				return $translation_map[ $filter_language ]->status === $filter_status;
			}

			return true;
		}

		// Status across all languages.
		if ( $filter_status === 'empty' ) {
			// Any active language missing, or 'empty' with all languages would match everything.
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
