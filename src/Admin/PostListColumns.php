<?php
/**
 * Translation status columns in post list tables.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Settings;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Language and Translations columns, the language filter and the Quick Edit language field.
 */
final class PostListColumns {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Cached languages.
	 *
	 * @var array<int, object>|null
	 */
	private ?array $languages = null;

	/**
	 * Cached translation manager.
	 *
	 * @var PostTranslationManager|null
	 */
	private ?PostTranslationManager $manager = null;

	/**
	 * Translations of every post on the current page, loaded in one batch.
	 *
	 * @var array<int, array<string, int>>|null
	 */
	private ?array $preloaded_translations = null;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Never read the translatable types here: add-ons have not booted yet, and Settings would memoise the
		// short list for the request. See docs-ai/11-code-rationale.md, "PostListColumns: deferred type hooks".
		add_action( 'admin_init', [ $this, 'register_post_type_column_hooks' ] );

		// Language filter dropdown on post list screens.
		add_action( 'restrict_manage_posts', [ $this, 'render_language_filter' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_language_filter' ] );

		// One batch of translations for every post on the page.
		add_action( 'pre_get_posts', [ $this, 'schedule_preload' ] );

		// Quick Edit: language selector.
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit_field' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save_quick_edit_language' ], 15, 2 );

		add_action( 'admin_enqueue_scripts', [ $this, 'quick_edit_js' ] );
	}

	/**
	 * Bind the per-post-type column and status-link hooks.
	 *
	 * Public for callers where admin_init never fires (`wp eval-file`), as in TermListColumns.
	 *
	 * @return void
	 */
	public function register_post_type_column_hooks(): void {
		$post_types = $this->settings->get_translatable_post_types();

		foreach ( $post_types as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );

			// Keeps the language filter in the status links; this hook exists only per screen id.
			add_filter( "views_edit-{$post_type}", [ $this, 'append_lang_to_views' ] );
		}
	}

	/**
	 * Render the language filter dropdown on post list screens.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */

	/**
	 * Append the active language filter parameter to status view links.
	 *
	 * @param array<string, string> $views Status view links.
	 * @return array<string, string>
	 */
	public function append_lang_to_views( array $views ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';

		if ( $lang_filter === '' ) {
			return $views;
		}

		foreach ( $views as $key => $link ) {
			// Decode first: core's view links hold &amp; in their hrefs.
			$views[ $key ] = preg_replace_callback(
				'/href=["\']([^"\']+)["\']/',
				static function ( array $m ) use ( $lang_filter ): string {
					$raw_url = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
					$url     = add_query_arg( 'perflocale_lang', $lang_filter, $raw_url );
					return 'href="' . esc_url( $url ) . '"';
				},
				$link
			) ?? $link;
		}

		return $views;
	}

	public function render_language_filter( string $post_type ): void {
		if ( ! in_array( $post_type, $this->settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$languages = $this->get_languages();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';

		echo '<label for="perflocale-filter-by-language" class="screen-reader-text">' . esc_html__( 'Filter by language', 'perflocale' ) . '</label>';
		echo '<select name="perflocale_lang" id="perflocale-filter-by-language">';
		echo '<option value="">' . esc_html__( 'All Languages', 'perflocale' ) . '</option>';

		$option_allowed = [
			'option' => [
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			],
		];
		foreach ( $languages as $lang ) {
			$flag = \PerfLocale\Helper::get_flag_emoji( $lang );
			echo wp_kses(
				'<option value="' . esc_attr( $lang->slug ) . '"' . selected( $current, $lang->slug, false ) . '>',
				$option_allowed
			);
			echo esc_html( $flag . ' ' . $lang->name );
			echo '</option>';
		}

		echo '</select>';
	}

	/**
	 * Apply the language filter to the admin post query.
	 *
	 * @param \WP_Query $query The query.
	 * @return void
	 */
	public function apply_language_filter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}

		if ( ! in_array( $query->get( 'post_type' ), $this->settings->get_translatable_post_types(), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';

		if ( $lang_filter === '' ) {
			return;
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang      = $lang_repo->find_by_slug( $lang_filter );

		if ( ! $lang ) {
			return;
		}

		// Read by filter_posts_by_language().
		$query->set( 'perflocale_admin_lang_filter', (int) $lang->id );

		if ( ! has_filter( 'posts_clauses', [ $this, 'filter_posts_by_language' ] ) ) {
			add_filter( 'posts_clauses', [ $this, 'filter_posts_by_language' ], 10, 2 );
		}
	}

	/**
	 * Modify query clauses to filter posts by a specific language.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query The query.
	 * @return array<string, string>
	 */
	public function filter_posts_by_language( array $clauses, \WP_Query $query ): array {
		$language_id = $query->get( 'perflocale_admin_lang_filter' );

		if ( ! $language_id ) {
			return $clauses;
		}

		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		// Plugin table names from Schema::table(), not input: a fragment returned to core cannot be prepared whole.
		$clauses['join'] = ( $clauses['join'] ?? '' ) .
			" INNER JOIN {$links_table} AS pl_flink ON ({$wpdb->posts}.ID = pl_flink.object_id)" .
			" INNER JOIN {$groups_table} AS pl_fgroup ON (pl_flink.group_id = pl_fgroup.id AND pl_fgroup.type = 'post')";

		$clauses['where'] = ( $clauses['where'] ?? '' ) . $wpdb->prepare(
			' AND pl_flink.language_id = %d',
			$language_id
		);

		return $clauses;
	}

	/**
	 * Schedule batch preloading of translations after the main admin query runs.
	 *
	 * @param \WP_Query $query Query instance.
	 * @return void
	 */
	public function schedule_preload( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Only translatable types have the columns; skip the query on every other list screen.
		$queried_type = $query->get( 'post_type' );
		$types        = (array) ( ( $queried_type === '' || $queried_type === null || $queried_type === [] ) ? 'post' : $queried_type );

		if ( array_intersect( $types, $this->settings->get_translatable_post_types() ) === [] ) {
			return;
		}

		add_filter( 'the_posts', [ $this, 'preload_translations_batch' ], 10, 2 );
	}

	/**
	 * Batch-preload translations for all posts in the admin list.
	 *
	 * Skips non-WP_Post members: the_posts fires before WP_Query normalises them.
	 *
	 * @param array<int, mixed> $posts Posts from the query; non-WP_Post members are skipped.
	 * @param \WP_Query         $query The query.
	 * @return array<int, mixed> The same array, unmodified.
	 */
	public function preload_translations_batch( array $posts, \WP_Query $query ): array {
		if ( empty( $posts ) || ! is_admin() ) {
			return $posts;
		}

		$manager                      = $this->get_manager();
		$this->preloaded_translations = [];

		// One SELECT for the whole list above the eager link map's cap; a no-op below it.
		$post_ids = [];

		foreach ( $posts as $p ) {
			if ( $p instanceof \WP_Post ) {
				$post_ids[] = (int) $p->ID;
			}
		}

		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Post, $post_ids );

		// Every translation id, to prime the post cache in one batch.
		$all_translation_ids = [];

		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}

			$pid                                  = (int) $post->ID;
			$translations                         = $manager->get_translations( $pid );
			$this->preloaded_translations[ $pid ] = $translations;

			foreach ( $translations as $translated_id ) {
				if ( is_int( $translated_id ) && $translated_id > 0 ) {
					$all_translation_ids[] = $translated_id;
				}
			}
		}

		// Makes render_column()'s get_post() checks free.
		if ( ! empty( $all_translation_ids ) ) {
			_prime_post_caches( array_unique( $all_translation_ids ), false, false );
		}

		// Remove filter after use.
		remove_filter( 'the_posts', [ $this, 'preload_translations_batch' ], 10 );

		return $posts;
	}

	/**
	 * Add the translations column to the post list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Modified columns.
	 */
	public function add_column( array $columns ): array {
		$new_columns = [];

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Insert after the title column.
			if ( $key === 'title' ) {
				$new_columns['perflocale_language']     = __( 'Language', 'perflocale' );
				$new_columns['perflocale_translations'] = __( 'Translations', 'perflocale' );
			}
		}

		/**
		 * Filter the post list columns.
		 *
		 * @param array $new_columns Column definitions.
		 * @param string $post_type Current post type.
		 */
		return apply_filters( 'perflocale/admin/post_list_columns', $new_columns, get_current_screen()->post_type ?? 'post' );
	}

	/**
	 * Render the translations column content for a post.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( $column === 'perflocale_language' ) {
			$manager   = $this->get_manager();
			$post_lang = $manager->detect_post_language( $post_id );
			$lang_slug = $post_lang ? $post_lang->slug : '';

			// Find which languages are already taken by siblings in this translation group.
			$translations = $this->preloaded_translations[ $post_id ] ?? $manager->get_translations( $post_id );
			$taken        = array_keys( $translations );

			// data-perflocale-lang: current language slug.
			// data-perflocale-taken: JSON array of language slugs used by siblings.
			echo '<div class="perflocale-lang-cell"'
				. ' data-perflocale-lang="' . esc_attr( $lang_slug ) . '"'
				. ' data-perflocale-taken="' . esc_attr( wp_json_encode( array_values( $taken ) ) ) . '"'
				. '>';

			if ( $post_lang ) {
				$flag = \PerfLocale\Helper::get_flag_emoji( $post_lang );
				$flag = $flag !== '' ? $flag . ' ' : '';
				echo '<span class="perflocale-badge perflocale-badge--green">' . esc_html( $flag . \PerfLocale\Helper::format_locale_as_bcp47( $post_lang->slug ) ) . '</span>';
			} else {
				echo '<span class="perflocale-badge perflocale-badge--none">-</span>';
			}

			echo '</div>';
			return;
		}

		if ( $column !== 'perflocale_translations' ) {
			return;
		}

		$languages = $this->get_languages();
		$manager   = $this->get_manager();

		// Use preloaded data if available, otherwise query individually (fallback).
		$translations = $this->preloaded_translations[ $post_id ] ?? $manager->get_translations( $post_id );

		$post_lang = $manager->detect_post_language( $post_id );

		echo '<div class="perflocale-lang-badges">';

		foreach ( $languages as $lang ) {
			$slug_upper = \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug );

			// Skip the current post's own language - that's shown in the Language column.
			if ( $post_lang && $post_lang->slug === $lang->slug ) {
				continue;
			}

			$translated_id = $translations[ $lang->slug ] ?? null;

			// Skip deleted posts.
			if ( $translated_id && ! get_post( $translated_id ) ) {
				$translated_id = null;
			}

			if ( $translated_id ) {
				// A sibling the user may not view gets the badge without a link (its ID stays off the page).
				$edit_url = \PerfLocale\Helper::user_can_view_object( (int) $translated_id ) ? \PerfLocale\Admin\ObjectLinks::edit_url( (int) $translated_id ) : '';

				if ( $edit_url !== '' ) {
					/* translators: %s: language name */
					echo '<a href="' . esc_url( $edit_url ) . '" class="perflocale-badge perflocale-badge--green" title="' . esc_attr( sprintf( __( 'Edit %s translation', 'perflocale' ), $lang->name ) ) . '">';
					echo esc_html( $slug_upper );
					echo '</a>';
				} else {
					// Translated but no editor to link to: the badge without a link, so it does not look untranslated.
					/* translators: %s: language name */
					echo '<span class="perflocale-badge perflocale-badge--green" title="' . esc_attr( sprintf( __( '%s: translated', 'perflocale' ), $lang->name ) ) . '">';
					echo esc_html( $slug_upper );
					echo '</span>';
				}
			} else {
				/* translators: %s: Language name */
				echo '<span class="perflocale-badge perflocale-badge--none" title="' . esc_attr( sprintf( __( '%s: Not translated', 'perflocale' ), $lang->name ) ) . '">';
				echo esc_html( $slug_upper );
				echo '</span>';
			}
		}

		echo '</div>';
	}

	/**
	 * Get active languages (cached for the column render loop).
	 *
	 * @return array<int, object>
	 */
	private function get_languages(): array {
		if ( $this->languages === null ) {
			$repo            = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
			$this->languages = $repo->get_active();
		}

		return $this->languages;
	}

	/**
	 * Get the post translation manager (cached).
	 *
	 * @return PostTranslationManager
	 */
	private function get_manager(): PostTranslationManager {
		if ( $this->manager === null ) {
			$this->manager = new PostTranslationManager( $this->cache, $this->settings );
		}

		return $this->manager;
	}

	/**
	 * Render language select field in the Quick Edit form.
	 *
	 * @param string $column_name Column name.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function render_quick_edit_field( string $column_name, string $post_type ): void {
		if ( $column_name !== 'perflocale_language' ) {
			return;
		}

		if ( ! in_array( $post_type, $this->settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$languages = $this->get_languages();

		?>
		<fieldset class="inline-edit-col-right" style="clear:both;">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<span class="title"><?php echo esc_html__( 'Language', 'perflocale' ); ?></span>
					<?php wp_nonce_field( 'perflocale_quick_edit', 'perflocale_qe_nonce' ); ?>
					<select name="perflocale_language_qe" style="width:auto;">
						<option value=""><?php echo esc_html__( '- No Change -', 'perflocale' ); ?></option>
						<?php foreach ( $languages as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang->slug ); ?>">
								<?php echo esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . $lang->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Save language from Quick Edit.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function save_quick_edit_language( int $post_id, ?\WP_Post $post = null ): void {
		// Nullable: core passes null for a post deleted after the write, and some plugins pass one argument.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! isset( $_POST['perflocale_qe_nonce'] ) ) {
			return;
		}

		// Only the quick-edited post (post_ID), not a sibling ContentSync saves in this request.
		$edited_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( $edited_id !== $post_id ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_qe_nonce'] ), 'perflocale_quick_edit' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$language = isset( $_POST['perflocale_language_qe'] ) ? sanitize_key( $_POST['perflocale_language_qe'] ) : '';

		if ( $language === '' ) {
			return;
		}

		$manager = $this->get_manager();

		if ( ! $manager->set_post_language( $post_id, $language ) ) {
			// Quick Edit has no notice: the row shows the unchanged language, and the cause is logged.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; quick-edit has no notice surface.
			error_log( sprintf( 'PerfLocale: quick-edit language save failed for post %d (language "%s").', $post_id, $language ) );
		}
	}

	/**
	 * Enqueue the script that fills the Quick Edit language field from row data.
	 *
	 * @return void
	 */
	public function quick_edit_js(): void {
		$screen = get_current_screen();

		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}

		if ( ! in_array( $screen->post_type, $this->settings->get_translatable_post_types(), true ) ) {
			return;
		}

		Assets::enqueue_script( 'perflocale-post-quick-edit', 'post-quick-edit.js', [ 'jquery', 'inline-edit-post' ] );

		wp_localize_script(
			'perflocale-post-quick-edit',
			'perflocaleQeI18n',
			[
				'inUse' => __( 'in use', 'perflocale' ),
			]
		);
	}
}
