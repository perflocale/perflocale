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
 * Adds a translation status column to the post list table.
 *
 * Shows color-coded dots for each active language, indicating
 * translation status. Clickable to edit the translation.
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
	 * Preloaded translations for all posts on the current page.
	 * Avoids N+1 queries by batch-loading in one query.
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
		$post_types = $this->settings->get_translatable_post_types();

		foreach ( $post_types as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}

		// Language filter dropdown on post list screens.
		add_action( 'restrict_manage_posts', [ $this, 'render_language_filter' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_language_filter' ] );

		// Preserve language filter in status links (All | Published | Draft | ...).
		foreach ( $post_types as $post_type ) {
			add_filter( "views_edit-{$post_type}", [ $this, 'append_lang_to_views' ] );
		}

		// Batch-preload translations for all posts on the page to avoid N+1 queries.
		add_action( 'pre_get_posts', [ $this, 'schedule_preload' ] );

		// Quick Edit: language selector.
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit_field' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save_quick_edit_language' ], 15, 2 );

		// Inline JS attached during enqueue phase (NOT admin_footer-edit.php
		// — that fires after wp_print_footer_scripts has already emitted
		// the `perflocale-admin` script payload, so wp_add_inline_script()
		// calls at that point are silently dropped).
		add_action( 'admin_enqueue_scripts', [ $this, 'quick_edit_js' ] );
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
	 * Ensures that clicking "All | Published | Draft | ..." preserves
	 * the currently selected language filter.
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
			// Inject perflocale_lang param into each link's href.
			// Decode HTML entities first since WP view links contain &amp; in hrefs.
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

		echo '<select name="perflocale_lang">';
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

		// Find the language ID.
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang      = $lang_repo->find_by_slug( $lang_filter );

		if ( ! $lang ) {
			return;
		}

		// Store language ID for the posts_clauses filter.
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

		// The language/translations columns only register for translatable
		// post types, so the batch preload has zero consumers on other list
		// screens (Media Library, non-translatable CPTs) — skip the
		// translation_links SELECT + cache churn there entirely.
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
	 * Members that are not WP_Post instances are ignored: the_posts fires
	 * before WP_Query normalises its members (wp-includes/class-wp-query.php),
	 * so a sparse result can carry a null member.
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

		// Bulk-prime the L1 (static) cache in ONE SELECT for every post
		// in the list. Without this, the per-post get_translations()
		// calls in the loop below each pay the cold-path cost (~1-2 ms
		// on sites above the eager-link-map cap), turning a 20-row admin
		// list into 30+ ms of avoidable work. Below the cap this is a
		// no-op (eager-link-map already serves these as µs).
		$post_ids = [];

		foreach ( $posts as $p ) {
			if ( $p instanceof \WP_Post ) {
				$post_ids[] = (int) $p->ID;
			}
		}

		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Post, $post_ids );

		// Collect all translation IDs so we can prime the WP post cache in one batch.
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

		// Prime WP post cache for all translation IDs at once - makes
		// the get_post() validation calls in render_column() free.
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
				echo '<span class="perflocale-badge perflocale-badge--none">&mdash;</span>';
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
				$edit_url = get_edit_post_link( $translated_id );

				if ( $edit_url ) {
					/* translators: %s: language name */
					echo '<a href="' . esc_url( $edit_url ) . '" class="perflocale-badge perflocale-badge--green" title="' . esc_attr( sprintf( __( 'Edit %s translation', 'perflocale' ), $lang->name ) ) . '">';
					echo esc_html( $slug_upper );
					echo '</a>';
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
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! isset( $_POST['perflocale_qe_nonce'] ) ) {
			return;
		}

		// Only process the specific post that was quick-edited.
		// WordPress may fire save_post for sibling translations (via ContentSync),
		// but $_POST['post_ID'] is always the one the user actually edited.
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
			// Quick Edit saves over AJAX — no admin_notices surface exists.
			// The row re-render shows the unchanged language (visible
			// feedback); log the cause for the operator.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; quick-edit has no notice surface.
			error_log( sprintf( 'PerfLocale: quick-edit language save failed for post %d (language "%s").', $post_id, $language ) );
		}
	}

	/**
	 * Inline JavaScript to populate the Quick Edit language field from row data.
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

		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleQeI18n=' . wp_json_encode(
				[
					'inUse' => __( 'in use', 'perflocale' ),
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);

		wp_add_inline_script(
			'perflocale-admin',
			'(function($){' .
				'if(typeof inlineEditPost==="undefined")return;' .
				'var origEdit=inlineEditPost.edit;' .
				'inlineEditPost.edit=function(id){' .
					'origEdit.apply(this,arguments);' .
					'if(typeof id==="object"){id=this.getId(id);}' .
					'if(!id)return;' .
					'var row=document.getElementById("post-"+id);' .
					'var langCell=row?row.querySelector(".perflocale-lang-cell"):null;' .
					'if(!langCell)return;' .
					'var lang=langCell.getAttribute("data-perflocale-lang")||"";' .
					'var taken=[];' .
					'try{taken=JSON.parse(langCell.getAttribute("data-perflocale-taken")||"[]");}catch(e){}' .
					'var editRow=document.getElementById("edit-"+id);' .
					'var select=editRow?editRow.querySelector(\'select[name="perflocale_language_qe"]\'):null;' .
					'if(!select)return;' .
					'for(var i=0;i<select.options.length;i++){' .
						'select.options[i].disabled=false;' .
						'select.options[i].textContent=select.options[i].textContent.replace(/ \\(.*\\)$/,"");' .
					'}' .
					'for(var i=0;i<select.options.length;i++){' .
						'var val=select.options[i].value;' .
						'if(val===""||val===lang)continue;' .
						'if(taken.indexOf(val)!==-1){' .
							'select.options[i].disabled=true;' .
							'select.options[i].textContent+=" ("+perflocaleQeI18n.inUse+")";' .
						'}' .
					'}' .
					'select.value=lang||"";' .
				'};' .
			'})(jQuery);'
		);
	}
}
