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
 * One server-rendered language list, plus a registry of host hooks to mount it on.
 *
 * Server HTML because hosts such as Contact Form 7 pass their editor through kses, which strips scripts; no form
 * inputs, as only MetaBox's save handles them: docs-ai/11-code-rationale.md, "TranslationsPanel: host editors".
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
	 * The first hook argument is the post id unless `resolve` maps the hook arguments to one.
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
				// Not a bare (int) cast: an object argument casts to 1 and would show post 1's translations.
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
	 * Output passes wp_kses_post(); get_html() returns the same markup without it.
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

		// The only sanitizing of the `perflocale/panel/render` output: keep it here, not in get_html(), and add
		// no opt-out. See docs-ai/11-code-rationale.md, "TranslationsPanel: escaping at the echo".
		echo wp_kses_post( $html );
	}

	/**
	 * Build the panel markup for one object.
	 *
	 * Empty for a missing or unsaved object, a user without edit rights, or no active languages.
	 *
	 * Not sanitized (a filter listener may return anything): echo through render(), or escape at your own sink.
	 *
	 * @param int                  $post_id Post id to describe.
	 * @param array<string, mixed> $args    Render arguments.
	 * @return string Unsanitized panel markup, or '' when nothing must render.
	 */
	public function get_html( int $post_id, array $args = [] ): string {
		// Not absint(): Contact Form 7 passes -1 for an unsaved form, which absint() makes post 1.
		if ( $post_id <= 0 ) {
			return '';
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		// The mount hook is no authorization, so the panel checks edit rights itself. The helper, not a bare
		// edit_post check, because a type with `map_meta_cap => false` makes core refuse even administrators.
		if ( ! \PerfLocale\Helper::user_can_edit_object( $post_id ) ) {
			return '';
		}

		$rows = $this->get_rows( $post_id, $args );

		if ( $rows === [] ) {
			return '';
		}

		// Hide Create when the action would refuse it (only the action checks the seed original). Passed to the
		// renderer, not put in the rows, which a `perflocale/panel/rows` listener can rewrite.
		$can_create = current_user_can( 'perflocale_translate' ) && \PerfLocale\Helper::user_can_create_like( $post_id );

		$html = '<ul class="perflocale-mb-list">';

		foreach ( $rows as $row ) {
			$html .= $this->render_row( $row, $can_create );
		}

		$html .= '</ul>';

		/**
		 * Filter the rendered Translations panel markup.
		 *
		 * The echo in render() (every mount()) passes the result through wp_kses_post(); get_html() returns it unsanitized.
		 * Listeners still escape their own values: kses keeps the text of a tag it removes.
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
	 * Data apart from markup, so an add-on can change rows without redoing the escaping.
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

		// One query for every sibling the loop's get_post() checks, instead of one each on a cold cache.
		$prime_ids = array_values( array_filter( array_map( 'intval', array_values( $translations ) ) ) );

		if ( $prime_ids !== [] ) {
			_prime_post_caches( $prime_ids, true, true );
		}

		$rows = [];

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $translations[ $lang->slug ] ?? null;

			// A link row can outlive its post: no Edit link to nothing.
			if ( $has_translation && $translated_id && ! get_post( $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			// A sibling the user may not view: its language only, no ID or link (rows filter included).
			$hidden = $has_translation && $translated_id && (int) $translated_id !== $post_id && ! \PerfLocale\Helper::user_can_view_object( (int) $translated_id );

			if ( $hidden ) {
				$translated_id = null;
			}

			$rows[] = [
				'slug'            => (string) $lang->slug,
				'badge'           => \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->slug ),
				'native_name'     => (string) ( $lang->native_name ?: $lang->name ),
				'is_current'      => ( $post_lang && $post_lang->slug === $lang->slug ),
				'has_translation' => $has_translation,
				'hidden'          => $hidden,
				'translated_id'   => $translated_id !== null ? (int) $translated_id : null,
				'source_id'       => $post_id,
				'source_status'   => (string) $post->post_status,
				// In the row so `perflocale/panel/rows` can retarget it per mount context; the edit link filter
				// knows only the post type.
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
	 * The classic metabox's markup: site stylesheets target these classes.
	 *
	 * @param array<string, mixed> $row        Row data from get_rows().
	 * @param bool                 $can_create Whether the user may create translations here.
	 * @return string
	 */
	private function render_row( array $row, bool $can_create ): string {
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
		} elseif ( $has_translation && ! empty( $row['hidden'] ) ) {
			$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--disabled">' . esc_html__( 'Translated', 'perflocale' ) . '</span>';
		} elseif ( $has_translation && $translated_id > 0 ) {
			// From the row, so a `perflocale/panel/rows` listener can replace it.
			$edit_url = (string) ( $row['edit_url'] ?? '' );

			if ( $edit_url !== '' ) {
				$html .= '<a href="' . esc_url( $edit_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--edit">' . esc_html__( 'Edit', 'perflocale' ) . '</a>';
			} else {
				// Translated but no editor to open: a pill, so the row does not look untranslated and offer Create.
				$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--disabled" title="' . esc_attr__( 'Translated. This content type has no editor that can be opened from here.', 'perflocale' ) . '">' . esc_html__( 'Translated', 'perflocale' ) . '</span>';
			}
		} elseif ( 'auto-draft' === ( $row['source_status'] ?? '' ) ) {
			$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--disabled" title="' . esc_attr__( 'Save the post before creating translations.', 'perflocale' ) . '">+ ' . esc_html__( 'Create', 'perflocale' ) . '</span>';
		} elseif ( ! $can_create ) {
			// A disabled pill: the action would refuse this user, but the language is still missing.
			$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--disabled" title="' . esc_attr__( 'You do not have permission to create translations of this content.', 'perflocale' ) . '">+ ' . esc_html__( 'Create', 'perflocale' ) . '</span>';
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
