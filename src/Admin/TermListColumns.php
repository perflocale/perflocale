<?php
/**
 * Translation status columns in taxonomy term list tables.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Settings;
use PerfLocale\Translation\TermTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a translation status column to taxonomy term list tables.
 *
 * Shows language badges for each active language with links to
 * edit existing translations.
 */
final class TermListColumns {

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
	 * Cached term translation manager.
	 *
	 * @var TermTranslationManager|null
	 */
	private ?TermTranslationManager $manager = null;

	/**
	 * Batch-preloaded term language data: term_id → language_slug.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $preloaded_languages = null;

	/**
	 * Batch-preloaded term translations: term_id → [ language_slug → translated_term_id ].
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
		// Defer taxonomy column hooks to admin_init so all addons (including WooCommerce)
		// are fully booted and their pa_* taxonomies appear in the translatable list.
		add_action( 'admin_init', [ $this, 'register_taxonomy_column_hooks' ] );

		// Language filter for term list screens.
		add_action( 'admin_head-edit-tags.php', [ $this, 'inject_term_language_filter' ] );
		add_filter( 'get_terms_args', [ $this, 'apply_term_language_filter' ], 10, 2 );
		add_filter( 'terms_clauses', [ $this, 'filter_terms_by_language_admin' ], 10, 3 );

		// Quick Edit: language selector for taxonomy term lists.
		// Mirrors PostListColumns Quick Edit so categories, tags, product_cat,
		// and any registered translatable taxonomy get an inline language
		// dropdown. Render fires from `quick_edit_custom_box` (which the
		// terms list table also dispatches), save fires from `edited_term`
		// after `wp_ajax_inline_save_tax` runs `wp_update_term`.
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit_field' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'save_quick_edit_language' ], 15, 3 );

		// Inline JS attached during enqueue phase (NOT admin_footer-* — that
		// fires after wp_print_footer_scripts has already emitted the
		// `perflocale-admin` script payload, so wp_add_inline_script() calls
		// at that point are silently dropped).
		add_action( 'admin_enqueue_scripts', [ $this, 'quick_edit_js' ] );
	}

	/**
	 * Register per-taxonomy column hooks.
	 *
	 * Runs at admin_init, after all addons have been booted at init:0, so
	 * WooCommerce pa_* attribute taxonomies are included in the translatable list.
	 *
	 * @return void
	 */
	public function register_taxonomy_column_hooks(): void {
		$taxonomies = $this->settings->get_translatable_taxonomies();

		foreach ( $taxonomies as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", [ $this, 'add_column' ] );
			add_filter( "manage_{$taxonomy}_custom_column", [ $this, 'render_column' ], 10, 3 );
		}
	}

	/**
	 * Inject a language filter dropdown into the term list screen via JS.
	 *
	 * @return void
	 */
	public function inject_term_language_filter(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		$languages = $this->get_languages();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';

		$options = '<option value="">' . esc_html__( 'All Languages', 'perflocale' ) . '</option>';

		foreach ( $languages as $lang ) {
			$flag     = \PerfLocale\Helper::get_flag_emoji( $lang );
			$selected = ( $current === $lang->slug ) ? ' selected' : '';
			$options .= '<option value="' . esc_attr( $lang->slug ) . '"' . $selected . '>'
				. esc_html( $flag . ' ' . $lang->name )
				. '</option>';
		}

		$filter_url = admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $screen->taxonomy ) );

		if ( ! empty( $screen->post_type ) ) {
			$filter_url = add_query_arg( 'post_type', $screen->post_type, $filter_url );
		}

		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleTermFilter=' . wp_json_encode(
				[
					'options'   => $options,
					'filterBtn' => __( 'Filter', 'perflocale' ),
					// Raw URL, NOT esc_url(): this value is consumed as a
					// JavaScript string, not emitted into an HTML attribute.
					// esc_url() encodes '&' as '&#038;', which JS does not
					// decode, so the query string the browser navigated to
					// became '...category&#038;post_type=post' and the
					// language parameter was parsed into the fragment and
					// dropped — the filter silently did nothing. The
					// wp_json_encode() call below (JSON_HEX_* flags) is the
					// context-correct escaper for a JS string literal.
					'filterUrl' => $filter_url,
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);

		wp_add_inline_script(
			'perflocale-admin',
			'(function(){' .
				'var tablenav=document.querySelector("#posts-filter .tablenav.top .bulkactions");' .
				'if(!tablenav)return;' .
				'var select=document.createElement("select");' .
				'select.name="perflocale_lang";' .
				'select.style.marginLeft="6px";' .
				'select.innerHTML=perflocaleTermFilter.options;' .
				'var btn=document.createElement("input");' .
				'btn.type="button";' .
				'btn.className="button";' .
				'btn.value=perflocaleTermFilter.filterBtn;' .
				'btn.style.marginLeft="4px";' .
				'btn.addEventListener("click",function(){' .
					'var val=select.value;' .
					'var url=perflocaleTermFilter.filterUrl;' .
					'if(val){url+="&perflocale_lang="+encodeURIComponent(val);}' .
					'window.location.href=url;' .
				'});' .
				'tablenav.parentNode.insertBefore(select,tablenav.nextSibling);' .
				'select.parentNode.insertBefore(btn,select.nextSibling);' .
			'})();'
		);
	}

	/**
	 * Store the language filter in a static property for the terms_clauses filter.
	 *
	 * @param array<string, mixed> $args Term query args.
	 * @param array<string>        $taxonomies Taxonomies.
	 * @return array<string, mixed>
	 */
	public function apply_term_language_filter( array $args, array $taxonomies ): array {
		if ( ! is_admin() ) {
			return $args;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang_filter = isset( $_GET['perflocale_lang'] ) ? sanitize_key( $_GET['perflocale_lang'] ) : '';

		if ( $lang_filter !== '' ) {
			$args['perflocale_admin_lang_filter'] = $lang_filter;
		}

		return $args;
	}

	/**
	 * Filter terms by language in admin when filter is active.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param array<int, string>    $taxonomies Taxonomies.
	 * @param array<string, mixed>  $args Query args.
	 * @return array<string, string>
	 */
	public function filter_terms_by_language_admin( array $clauses, array $taxonomies, array $args ): array {
		if ( ! is_admin() || empty( $args['perflocale_admin_lang_filter'] ) ) {
			return $clauses;
		}

		$lang_slug = sanitize_key( $args['perflocale_admin_lang_filter'] );
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang      = $lang_repo->find_by_slug( $lang_slug );

		if ( ! $lang ) {
			return $clauses;
		}

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		$clauses['join'] = ( $clauses['join'] ?? '' ) .
			" INNER JOIN {$links_table} AS pl_tfl ON (t.term_id = pl_tfl.object_id)" .
			" INNER JOIN {$groups_table} AS pl_tfg ON (pl_tfl.group_id = pl_tfg.id AND pl_tfg.type = 'term')";

		global $wpdb;

		$clauses['where'] = ( $clauses['where'] ?? '' ) . $wpdb->prepare(
			' AND pl_tfl.language_id = %d',
			(int) $lang->id
		);

		return $clauses;
	}

	/**
	 * Add the translations column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$new_columns = [];

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( $key === 'name' ) {
				$new_columns['perflocale_language']     = __( 'Language', 'perflocale' );
				$new_columns['perflocale_translations'] = __( 'Translations', 'perflocale' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render the translations column content for a term.
	 *
	 * Note: taxonomy custom_column filter expects a RETURN value, not echo.
	 *
	 * @param string $content Existing column content.
	 * @param string $column Column name.
	 * @param int    $term_id Term ID.
	 * @return string Column HTML.
	 */
	public function render_column( string $content, string $column, int $term_id ): string {
		if ( $column !== 'perflocale_language' && $column !== 'perflocale_translations' ) {
			return $content;
		}

		// Lazy batch preload: on the first column render call, batch-load
		// all translation data for terms visible on this page.
		if ( $this->preloaded_languages === null ) {
			$this->batch_preload_for_current_page();
		}

		if ( $column === 'perflocale_language' ) {
			return $this->render_language_column( $term_id );
		}

		return $this->render_translations_column( $term_id );
	}

	/**
	 * Render the Language column - shows which language this term belongs to.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private function render_language_column( int $term_id ): string {
		$current_slug = '';
		$badge_html   = '';

		// Use preloaded data if available (batch query ran).
		if ( $this->preloaded_languages !== null ) {
			$lang_slug = $this->preloaded_languages[ $term_id ] ?? null;

			if ( $lang_slug === null ) {
				$badge_html = '<span class="perflocale-badge perflocale-badge--none" title="' . esc_attr__( 'No language set', 'perflocale' ) . '">&mdash;</span>';
			} else {
				$found = false;
				foreach ( $this->get_languages() as $lang ) {
					if ( $lang->slug === $lang_slug ) {
						$flag         = \PerfLocale\Helper::get_flag_emoji( $lang );
						$flag         = $flag !== '' ? $flag . ' ' : '';
						$badge_html   = '<span class="perflocale-badge perflocale-badge--green">' . esc_html( $flag . \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ) ) . '</span>';
						$current_slug = (string) $lang->slug;
						$found        = true;
						break;
					}
				}
				if ( ! $found ) {
					$badge_html = '<span class="perflocale-badge perflocale-badge--none">&mdash;</span>';
				}
			}
		} else {
			// Fallback: per-term query (shouldn't happen on list pages).
			$term_lang = $this->get_manager()->detect_term_language( $term_id );

			if ( ! $term_lang ) {
				$badge_html = '<span class="perflocale-badge perflocale-badge--none" title="' . esc_attr__( 'No language set', 'perflocale' ) . '">&mdash;</span>';
			} else {
				$flag         = \PerfLocale\Helper::get_flag_emoji( $term_lang );
				$flag         = $flag !== '' ? $flag . ' ' : '';
				$badge_html   = '<span class="perflocale-badge perflocale-badge--green">' . esc_html( $flag . \PerfLocale\Helper::format_locale_as_bcp47( $term_lang->slug ) ) . '</span>';
				$current_slug = (string) $term_lang->slug;
			}
		}

		// Wrap in a cell carrying the data-attrs the Quick Edit JS reads to
		// pre-populate the inline `<select>` and disable already-taken
		// languages (mirrors PostListColumns' `.perflocale-lang-cell`).
		$taken = $this->get_taken_languages_for_term( $term_id, $current_slug );

		return '<div class="perflocale-lang-cell"'
			. ' data-perflocale-lang="' . esc_attr( $current_slug ) . '"'
			. ' data-perflocale-taken="' . esc_attr( wp_json_encode( array_values( $taken ) ) ) . '">'
			. $badge_html
			. '</div>';
	}

	/**
	 * Resolve the language slugs already taken by sibling translations of
	 * this term (excluding the term's own current language).
	 *
	 * Used by the Quick Edit JS to disable conflicting `<option>`s in the
	 * inline language picker — picking a language that another sibling
	 * already owns would produce a 409 in `set_term_language()`.
	 *
	 * @param int    $term_id      Current term ID.
	 * @param string $current_slug Current term's language slug (excluded from the result).
	 * @return array<int, string>
	 */
	private function get_taken_languages_for_term( int $term_id, string $current_slug ): array {
		// Reuse the preloaded translations payload when available — the
		// translations array is keyed by lang_slug → translated_term_id, so
		// every key is a language slug already used by a sibling.
		if ( $this->preloaded_translations !== null ) {
			$translations = $this->preloaded_translations[ $term_id ] ?? [];
		} else {
			$translations = $this->get_manager()->get_translations( $term_id );
		}

		$taken = [];

		foreach ( $translations as $slug => $sibling_id ) {
			$slug = (string) $slug;

			if ( $slug === '' || $slug === $current_slug ) {
				continue;
			}

			$taken[] = $slug;
		}

		return $taken;
	}

	/**
	 * Render the Translations column - shows badges for each language.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private function render_translations_column( int $term_id ): string {
		$languages = $this->get_languages();

		$screen   = get_current_screen();
		$taxonomy = $screen->taxonomy ?? 'category';

		// Use preloaded translations if available (batch query ran).
		if ( $this->preloaded_translations !== null ) {
			$translations = $this->preloaded_translations[ $term_id ] ?? [];
		} else {
			// Fallback: per-term query.
			$translations = $this->get_manager()->get_translations( $term_id );
		}

		$html = '<div class="perflocale-lang-badges">';

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $translations[ $lang->slug ] ?? null;
			$slug_upper      = \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug );

			// Skip deleted terms.
			if ( $has_translation && $translated_id && ! get_term( $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			if ( $has_translation && $translated_id ) {
				$is_current = ( (int) $translated_id === $term_id );
				$edit_url   = get_edit_term_link( $translated_id, $taxonomy );

				if ( ! $is_current && $edit_url ) {
					$html .= '<a href="' . esc_url( $edit_url ) . '" class="perflocale-badge perflocale-badge--green" title="' . esc_attr( $lang->name ) . '">';
					$html .= esc_html( $slug_upper );
					$html .= '</a>';
				} else {
					$html .= '<span class="perflocale-badge perflocale-badge--green perflocale-badge--current" title="' . esc_attr( $lang->name ) . '">';
					$html .= esc_html( $slug_upper );
					$html .= '</span>';
				}
			} else {
				/* translators: %s: Language name */
				$html .= '<span class="perflocale-badge perflocale-badge--none" title="' . esc_attr( sprintf( __( '%s: Not translated', 'perflocale' ), $lang->name ) ) . '">';
				$html .= esc_html( $slug_upper );
				$html .= '</span>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Batch-preload translation data for all terms visible on this page.
	 *
	 * Called lazily on the first render_column() call. Collects term IDs
	 * that will be rendered, then runs ONE batch query to get all their
	 * translation group data. This replaces 80+ per-term queries.
	 *
	 * @return void
	 */
	private function batch_preload_for_current_page(): void {
		$this->preloaded_languages    = [];
		$this->preloaded_translations = [];

		// Collect term IDs from the WP list table's items.
		global $wp_list_table;

		$term_ids = [];

		if ( $wp_list_table && isset( $wp_list_table->items ) && is_array( $wp_list_table->items ) ) {
			foreach ( $wp_list_table->items as $term ) {
				if ( $term instanceof \WP_Term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
		}

		if ( empty( $term_ids ) ) {
			return;
		}

		// Single batch query: find all translation group siblings for these terms.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$lang_table   = \PerfLocale\Database\Schema::table( 'languages' );

		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l2.object_id AS term_id, lang.slug AS language_slug, l2.group_id
				FROM %i l1
				INNER JOIN %i g ON g.id = l1.group_id AND g.type = 'term'
				INNER JOIN %i l2 ON l2.group_id = l1.group_id
				INNER JOIN %i lang ON lang.id = l2.language_id
				WHERE l1.object_id IN ({$placeholders})",
				$links_table,
				$groups_table,
				$links_table,
				$lang_table,
				...$term_ids
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$group_members = []; // group_id → [ language_slug → term_id ].
		$group_of_term = []; // term_id → group_id (the row already carries it).

		foreach ( $rows as $row ) {
			$tid  = (int) $row->term_id;
			$gid  = (int) $row->group_id;
			$slug = $row->language_slug;

			$this->preloaded_languages[ $tid ] = $slug;
			$group_members[ $gid ][ $slug ]    = $tid;
			$group_of_term[ $tid ]             = $gid;
		}

		// Map each page term to its siblings via its OWN group id (already in
		// the rows) — O(terms) instead of the previous O(terms × groups ×
		// members) in_array() scan. Only page terms that appeared in the rows
		// have a group; the rest simply have no translations to preload.
		foreach ( $term_ids as $page_tid ) {
			$page_tid = (int) $page_tid;
			if ( isset( $group_of_term[ $page_tid ] ) ) {
				$this->preloaded_translations[ $page_tid ] = $group_members[ $group_of_term[ $page_tid ] ];
			}
		}

		// Warm the 'terms' cache for every sibling badge in one IN() query.
		// render_translations_column() calls get_term()/get_edit_term_link()
		// per (row × language); translated siblings sort apart alphabetically,
		// so on a multi-page taxonomy most are off the current list page and
		// each would otherwise cost a single-row SELECT. Mirrors
		// PostListColumns' _prime_post_caches().
		$sibling_ids = [];

		foreach ( $this->preloaded_translations as $members ) {
			foreach ( $members as $sibling_id ) {
				$sibling_ids[] = (int) $sibling_id;
			}
		}

		if ( ! empty( $sibling_ids ) ) {
			_prime_term_caches( array_unique( $sibling_ids ), false );
		}
	}

	/**
	 * Get active languages (cached).
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
	 * Get the term translation manager (cached).
	 *
	 * @return TermTranslationManager
	 */
	private function get_manager(): TermTranslationManager {
		if ( $this->manager === null ) {
			$this->manager = new TermTranslationManager( $this->cache );
		}

		return $this->manager;
	}

	/**
	 * Render the inline Quick Edit language `<select>` for term list tables.
	 *
	 * `quick_edit_custom_box` fires for both posts (`$arg2 = post_type`) and
	 * terms (`$arg2 = 'edit-tags'`, `$arg3 = taxonomy`). This handler bails
	 * unless the dispatch is for a term, which lets a single hook subscription
	 * coexist with PostListColumns' parallel handler without conflict.
	 *
	 * @param string $column_name Column being rendered.
	 * @param string $screen_id  'edit-tags' for term list tables, post-type slug for post list tables.
	 * @param string $taxonomy   Taxonomy slug (passed by core for the term variant).
	 * @return void
	 */
	public function render_quick_edit_field( string $column_name, string $screen_id = '', string $taxonomy = '' ): void {
		if ( $column_name !== 'perflocale_language' ) {
			return;
		}

		// Disambiguate term-flavour calls from post-flavour calls — both fire
		// the same action, but only the term variant carries 'edit-tags'.
		if ( $screen_id !== 'edit-tags' ) {
			return;
		}

		if ( $taxonomy === '' || ! in_array( $taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		$languages = $this->get_languages();

		?>
		<fieldset class="inline-edit-col">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php echo esc_html__( 'Language', 'perflocale' ); ?></span>
					<?php wp_nonce_field( 'perflocale_term_quick_edit', 'perflocale_term_qe_nonce' ); ?>
					<select name="perflocale_term_language_qe">
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
	 * Save the language picked in Quick Edit.
	 *
	 * `edited_term` fires after `wp_update_term()`, which `wp_ajax_inline_save_tax`
	 * calls. The language change is decoupled from the term name/slug update —
	 * we only mutate translation_links rows, never the term itself.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused; kept for hook signature).
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function save_quick_edit_language( int $term_id, int $tt_id, string $taxonomy ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['perflocale_term_qe_nonce'] ) ) {
			return;
		}

		// Only act on the specific term being inline-edited; `edited_term`
		// fires for any update (including programmatic ones from other
		// addons) so we double-check this is the user's Quick Edit target.
		$edited_id = isset( $_POST['tax_ID'] ) ? absint( $_POST['tax_ID'] ) : 0;

		if ( $edited_id !== $term_id ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_term_qe_nonce'] ), 'perflocale_term_quick_edit' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		if ( ! in_array( $taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		$language = isset( $_POST['perflocale_term_language_qe'] ) ? sanitize_key( $_POST['perflocale_term_language_qe'] ) : '';

		if ( $language === '' ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $this->get_manager()->set_term_language( $term_id, $language ) ) {
			// Quick Edit saves over AJAX — no admin_notices surface exists.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; quick-edit has no notice surface.
			error_log( sprintf( 'PerfLocale: quick-edit language save failed for term %d (language "%s").', $term_id, $language ) );
		}
	}

	/**
	 * Inline JS that pre-populates the term Quick Edit language `<select>`
	 * from row data each time a row's Quick Edit is opened.
	 *
	 * Mirrors {@see PostListColumns::quick_edit_js()} — same state-shape,
	 * same `inUse` decoration, but reads from the term row IDs (`tag-{id}`
	 * / `edit-{id}`) and hooks core's `inlineEditTax.edit` instead of
	 * `inlineEditPost.edit`.
	 *
	 * @return void
	 */
	public function quick_edit_js(): void {
		$screen = get_current_screen();

		if ( ! $screen || $screen->base !== 'edit-tags' ) {
			return;
		}

		if ( ! in_array( $screen->taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		wp_add_inline_script(
			'perflocale-admin',
			'var perflocaleTermQeI18n=' . wp_json_encode(
				[
					'inUse' => __( 'in use', 'perflocale' ),
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);

		// Defer the inlineEditTax.edit override. `inline-edit-tax.js` is
		// enqueued lazily by core (sometimes after our inline script runs),
		// so a synchronous `if (typeof inlineEditTax === "undefined") return`
		// would silently no-op. Poll briefly + override once it's available.
		wp_add_inline_script(
			'perflocale-admin',
			'(function($){' .
				'function install(){' .
					'if(typeof inlineEditTax==="undefined"){return false;}' .
					'if(inlineEditTax.__perflocaleHooked){return true;}' .
					'inlineEditTax.__perflocaleHooked=true;' .
					'var origEdit=inlineEditTax.edit;' .
					'inlineEditTax.edit=function(id){' .
						'origEdit.apply(this,arguments);' .
						'if(typeof id==="object"){id=this.getId(id);}' .
						'if(!id){return;}' .
						// Term rows are `tag-{id}` regardless of taxonomy.
						'var row=document.getElementById("tag-"+id);' .
						'var langCell=row?row.querySelector(".perflocale-lang-cell"):null;' .
						'if(!langCell){return;}' .
						'var lang=langCell.getAttribute("data-perflocale-lang")||"";' .
						'var taken=[];' .
						'try{taken=JSON.parse(langCell.getAttribute("data-perflocale-taken")||"[]");}catch(e){}' .
						'var editRow=document.getElementById("edit-"+id);' .
						'var select=editRow?editRow.querySelector(\'select[name="perflocale_term_language_qe"]\'):null;' .
						'if(!select){return;}' .
						'for(var i=0;i<select.options.length;i++){' .
							'select.options[i].disabled=false;' .
							'select.options[i].textContent=select.options[i].textContent.replace(/ \\(.*\\)$/,"");' .
						'}' .
						'for(var i=0;i<select.options.length;i++){' .
							'var val=select.options[i].value;' .
							'if(val===""||val===lang){continue;}' .
							'if(taken.indexOf(val)!==-1){' .
								'select.options[i].disabled=true;' .
								'select.options[i].textContent+=" ("+perflocaleTermQeI18n.inUse+")";' .
							'}' .
						'}' .
						'select.value=lang||"";' .
					'};' .
					'return true;' .
				'}' .
				'if(install()){return;}' .
				// Poll up to ~3s for inline-edit-tax.js to load. Inexpensive
				// (50 ms × 60 = 60 short JSON-less DOM ops) and hands control
				// back to the browser between checks. Stops the moment the
				// hook installs successfully.
				'var tries=0;' .
				'var iv=setInterval(function(){' .
					'if(install()||++tries>60){clearInterval(iv);}' .
				'},50);' .
			'})(jQuery);'
		);
	}
}
