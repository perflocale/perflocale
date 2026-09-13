<?php
/**
 * Shared "Translations" panel renderer and mount registry.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One renderer for the language list, plus a registry of places to mount it.
 *
 * WHY THIS EXISTS
 *
 * The panel had four call sites and no shared renderer, and every one of them
 * only works on a screen WordPress itself owns:
 *
 *   - Admin\MetaBox          — add_meta_box(), so a post.php/post-new.php screen
 *   - assets/js/editor-sidebar.js       — block editor, PluginDocumentSettingPanel
 *   - assets/js/site-editor-sidebar.js  — Site Editor, same React API
 *   - Admin\TermMetaBox      — {$taxonomy}_edit_form_fields
 *
 * A host plugin that builds its OWN editor screen therefore gets nothing.
 * Contact Form 7 is the case that forced this: its form editor is a custom
 * admin page (`admin.php?page=wpcf7&post=<id>&action=edit`), it never calls
 * `add_meta_box()` — the postboxes on that page are hand-built markup — and
 * `wp.plugins.registerPlugin` does not exist there, so neither the metabox nor
 * either React panel can reach it. Its forms are nonetheless real WP posts that
 * PerfLocale translates.
 *
 * ⭐ WHY THIS RENDERS HTML ON THE SERVER RATHER THAN MOUNTING THE REACT PANEL.
 * Not conservatism — the React panels are structurally impossible on the screen
 * this was built for. Contact Form 7 emits its entire editor page through
 * `wp_kses()` (contact-form-7/includes/html-formatter.php:786), and output
 * echoed on its hooks is buffered into that same pass
 * (html-formatter.php:752-762). `<script>` does not survive kses, so a
 * JS-mounted panel cannot be delivered there at all. `ul`, `li`, `span` and `a`
 * DO survive, along with `class`, `id`, `href`, `title` and the global
 * `data-*`/`aria-*`/`role` attributes, because CF7's allowlist starts from
 * `wp_kses_allowed_html( 'post' )` (contact-form-7/includes/formatting.php:384).
 * So server-rendered markup is the only shape that works — and it is also the
 * shape the classic metabox already used, which is why this class is an
 * extraction rather than a new design.
 *
 * WHAT IS DELIBERATELY NOT IN HERE
 *
 * Only the LANGUAGE LIST moved. MetaBox's footer — the language `<select>`, the
 * sync opt-out, the SEO opt-out — stayed behind, because those are form inputs
 * that only mean something on a screen whose submit is handled by
 * `MetaBox::save_meta_box()` on `save_post`. Rendering them on a host's own
 * editor would show controls that silently discard what the operator typed.
 *
 * @since 1.0.5
 */
final class TranslationsPanel {

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Mount the panel on an arbitrary host hook.
	 *
	 * The point of the registry: an addon declares WHERE, and never has to know
	 * how a row is built, escaped, or capability-gated.
	 *
	 *     $plugin->get( 'translations_panel' )->mount(
	 *         'wpcf7_admin_misc_pub_section',
	 *         [ 'context' => 'contact-form-7' ]
	 *     );
	 *
	 * Hosts disagree about what they pass. By default the first hook argument is
	 * taken as the post id, which is what CF7 passes; a host that passes
	 * something else (WPForms hands over a `WP_Post`) supplies a `resolve`
	 * callable instead. Anything that cannot be resolved to a positive id
	 * renders nothing — see the `-1` case in render().
	 *
	 * @param string               $hook    Action hook to render on.
	 * @param array<string, mixed> $options priority, accepted_args, resolve, context, title.
	 * @return void
	 */
	public function mount( string $hook, array $options = [] ): void {
		$priority = isset( $options['priority'] ) ? (int) $options['priority'] : 10;
		$accepted = isset( $options['accepted_args'] ) ? (int) $options['accepted_args'] : 1;
		$resolve  = $options['resolve'] ?? null;

		$args = $options;
		unset( $args['priority'], $args['accepted_args'], $args['resolve'] );

		add_action(
			$hook,
			function ( ...$hook_args ) use ( $resolve, $args ): void {
				// ⚠️ `is_numeric`, not a bare `(int)` cast. Hosts pass all sorts of
				// things as the first hook argument — Elementor's
				// `elementor/documents/register_controls` passes a Document OBJECT,
				// WPForms passes a WP_Post. Casting an object to int in PHP yields
				// 1 (with a warning), so an addon author who forgets `resolve`
				// would silently render POST 1's translations on someone else's
				// screen. Anything that is not a number resolves to 0, and 0
				// renders nothing.
				$first = $hook_args[0] ?? null;

				$post_id = is_callable( $resolve )
					? (int) $resolve( ...$hook_args )
					: ( is_numeric( $first ) ? (int) $first : 0 );

				$this->render( $post_id, $args );
			},
			$priority,
			$accepted
		);
	}

	/**
	 * Echo the panel for one object.
	 *
	 * @param int                  $post_id Post id to describe.
	 * @param array<string, mixed> $args    Render arguments (context, title, heading).
	 * @return void
	 */
	public function render( int $post_id, array $args = [] ): void {
		$html = $this->get_html( $post_id, $args );

		if ( $html === '' ) {
			return;
		}

		// ESCAPED AT THE SINK, like every other echo in this plugin that emits
		// built markup. Each dynamic value was already escaped as it was written
		// (see render_row(), where every one passes through esc_html/esc_attr/
		// esc_url), so wp_kses_post() changes nothing we produce — `ul`, `li`,
		// `span` and `a` with class/href/title all survive it untouched.
		//
		// What it buys is the `perflocale/panel/render` filter: that runs inside
		// get_html() and a listener can return anything at all. Without an
		// escaper here, a third party's markup would reach the page verbatim on
		// an admin screen. Escaping at the sink is the invariant this codebase
		// holds everywhere else, and it should not have an exception.
		//
		// ⚠️ Consequence for listeners, documented on the hooks page: the panel's
		// markup is limited to what wp_kses_post() permits.
		echo wp_kses_post( $html );
	}

	/**
	 * Build the panel markup for one object.
	 *
	 * Returns an empty string for anything that must not render: a missing or
	 * unsaved object, a user without edit rights on it, or a site with no active
	 * languages.
	 *
	 * @param int                  $post_id Post id to describe.
	 * @param array<string, mixed> $args    Render arguments.
	 * @return string
	 */
	public function get_html( int $post_id, array $args = [] ): string {
		// ⚠️ `> 0` IS LOAD-BEARING, not defensive habit. Contact Form 7 passes
		// literally -1 for a form that has not been saved yet
		// (contact-form-7/admin/admin.php:407, `$post->initial() ? -1 : ...`),
		// and a host that mounts this on a "new object" screen has nothing else
		// to hand over. absint() would turn that into 1 and describe an
		// unrelated post.
		if ( $post_id <= 0 ) {
			return '';
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		// The mount site is NOT the authorization. An addon can register this on
		// any hook it likes, including one that fires on a screen whose own gate
		// is weaker or absent, so the panel gates itself on the same check the
		// REST route uses. It enumerates an object's translations and links to
		// them, which is exactly what edit rights govern.
		//
		// Helper, not a bare `current_user_can( 'edit_post', … )`: a host that
		// registers its type with `map_meta_cap => false` and never grants the
		// resulting primitive makes core answer `false` for everyone, including
		// administrators — so the panel would silently never render on precisely
		// the custom editor screens this registry exists to serve. See
		// Helper::user_can_edit_object().
		if ( ! \PerfLocale\Helper::user_can_edit_object( $post_id ) ) {
			return '';
		}

		$rows = $this->get_rows( $post_id, $args );

		if ( $rows === [] ) {
			return '';
		}

		$html = '<ul class="perflocale-mb-list">';

		foreach ( $rows as $row ) {
			$html .= $this->render_row( $row );
		}

		$html .= '</ul>';

		/**
		 * Filter the rendered Translations panel markup.
		 *
		 * Runs on the finished string, so a listener can wrap, replace or
		 * suppress the panel wholesale. ⚠️ What is returned is passed through
		 * `wp_kses_post()` before it is echoed, so a listener still escapes its
		 * own dynamic values and cannot emit tags outside that allowlist. A host
		 * that filters its own page through kses — Contact Form 7 does — narrows
		 * it further still.
		 *
		 * @hook  perflocale/panel/render
		 * @since 1.0.5
		 *
		 * @param string               $html    Panel markup.
		 * @param int                  $post_id Post being described.
		 * @param array<string, mixed> $args    Render arguments, including `context`.
		 * @return string
		 */
		return (string) apply_filters( 'perflocale/panel/render', $html, $post_id, $args );
	}

	/**
	 * Build the per-language row data for one object.
	 *
	 * Separated from the markup so an addon can reorder, drop or annotate rows
	 * without reimplementing the escaping.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $args    Render arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_rows( int $post_id, array $args = [] ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return [];
		}

		$plugin       = \PerfLocale\Plugin::get_instance();
		$lang_repo    = $plugin->get( 'lang_repo' );
		$manager      = new PostTranslationManager( $this->cache, $plugin->get( 'settings' ) );
		$languages    = $lang_repo->get_active();
		$translations = $manager->get_translations( $post_id );
		$post_lang    = $manager->detect_post_language( $post_id );

		// One query for every sibling instead of one query PER sibling. The loop
		// below calls get_post() on each translation to check the link row is not
		// pointing at a deleted post, and on a cold cache each of those was its own
		// SELECT — measured as one extra query per existing sibling, so the panel
		// got slower for exactly the sites that have the most translations.
		//
		// Same idiom the REST route already uses for this list
		// (Api/TranslationsController::get_translations()). Terms and meta are
		// primed too: render_row() reads post_status, and ObjectLinks::edit_url()
		// reads the post type.
		$prime_ids = array_values( array_filter( array_map( 'intval', array_values( $translations ) ) ) );

		if ( $prime_ids !== [] ) {
			_prime_post_caches( $prime_ids, true, true );
		}

		$rows = [];

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $translations[ $lang->slug ] ?? null;

			// A link row can outlive the post it points at. Treating that as
			// "translated" would render an Edit link to nothing.
			if ( $has_translation && $translated_id && ! get_post( $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			$rows[] = [
				'slug'            => (string) $lang->slug,
				'badge'           => \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->slug ),
				'native_name'     => (string) ( $lang->native_name ?: $lang->name ),
				'is_current'      => ( $post_lang && $post_lang->slug === $lang->slug ),
				'has_translation' => $has_translation,
				'translated_id'   => $translated_id !== null ? (int) $translated_id : null,
				'source_id'       => $post_id,
				'source_status'   => (string) $post->post_status,
				// Resolved HERE rather than while rendering so the rows filter can
				// retarget it. ObjectLinks applies `perflocale/admin/edit_post_link`,
				// but that filter is keyed on POST TYPE alone — it cannot send the
				// same type somewhere different depending on which screen the panel
				// is on. A builder that mounts this inside its own canvas wants
				// "Edit" to stay in that canvas while the Translations screen keeps
				// linking to the WP editor, and `perflocale/panel/rows` receives the
				// context, so putting the URL in the row is what makes that possible.
				'edit_url'        => ( $has_translation && $translated_id )
					? ObjectLinks::edit_url( (int) $translated_id )
					: '',
			];
		}

		/**
		 * Filter the Translations panel rows before they are rendered.
		 *
		 * @hook  perflocale/panel/rows
		 * @since 1.0.5
		 *
		 * @param array<int, array<string, mixed>> $rows    One entry per active language.
		 * @param int                              $post_id Post being described.
		 * @param array<string, mixed>             $args    Render arguments, including `context`.
		 * @return array<int, array<string, mixed>>
		 */
		return (array) apply_filters( 'perflocale/panel/rows', $rows, $post_id, $args );
	}

	/**
	 * Render one language row.
	 *
	 * Byte-for-byte the markup MetaBox::render() emitted before this class
	 * existed, so the classic metabox keeps its exact appearance and any CSS or
	 * user stylesheet targeting it keeps working.
	 *
	 * @param array<string, mixed> $row Row data from get_rows().
	 * @return string
	 */
	private function render_row( array $row ): string {
		$is_current      = ! empty( $row['is_current'] );
		$has_translation = ! empty( $row['has_translation'] );
		$translated_id   = isset( $row['translated_id'] ) ? (int) $row['translated_id'] : 0;
		$source_id       = isset( $row['source_id'] ) ? (int) $row['source_id'] : 0;
		$row_class       = 'perflocale-mb-item' . ( $is_current ? ' perflocale-mb-item--current' : '' );

		$html  = '<li class="' . esc_attr( $row_class ) . '">';
		$html .= '<span class="perflocale-mb-left">';
		$html .= '<span class="perflocale-mb-badge">' . esc_html( (string) ( $row['badge'] ?? '' ) ) . '</span>';
		$html .= '<span class="perflocale-mb-native">' . esc_html( (string) ( $row['native_name'] ?? '' ) ) . '</span>';
		$html .= '</span>';

		if ( $is_current ) {
			$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--current">' . esc_html__( 'Current', 'perflocale' ) . '</span>';
		} elseif ( $has_translation && $translated_id > 0 ) {
			// From the row, so a `perflocale/panel/rows` listener can replace it.
			$edit_url = (string) ( $row['edit_url'] ?? '' );

			if ( $edit_url !== '' ) {
				$html .= '<a href="' . esc_url( $edit_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--edit">' . esc_html__( 'Edit', 'perflocale' ) . '</a>';
			} else {
				// ⚠️ Translated, but with no editor to open (non-public post
				// type, or no edit_post capability). Rendering nothing made
				// the row look untranslated and offered a "+ Create" that
				// would have duplicated it.
				$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--disabled" title="' . esc_attr__( 'Translated. This content type has no editor that can be opened from here.', 'perflocale' ) . '">' . esc_html__( 'Translated', 'perflocale' ) . '</span>';
			}
		} elseif ( 'auto-draft' === ( $row['source_status'] ?? '' ) ) {
			$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--disabled" title="' . esc_attr__( 'Save the post before creating translations.', 'perflocale' ) . '">+ ' . esc_html__( 'Create', 'perflocale' ) . '</span>';
		} else {
			$create_url = add_query_arg(
				[
					'action'      => 'perflocale_create_translation',
					'source_id'   => $source_id,
					'target_lang' => (string) ( $row['slug'] ?? '' ),
					'_wpnonce'    => wp_create_nonce( 'perflocale_create_' . $source_id ),
				],
				admin_url( 'admin-post.php' )
			);

			$html .= '<a href="' . esc_url( $create_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--create">+ ' . esc_html__( 'Create', 'perflocale' ) . '</a>';
		}

		$html .= '</li>';

		return $html;
	}
}
