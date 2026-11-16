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
use PerfLocale\Util\Scalar;

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

		$html = '';

		// This post's own waiting update: the line and link above the list.
		foreach ( $rows as $row ) {
			$review_url = self::row_text( $row, 'review_url' );

			if ( ! empty( $row['is_current'] ) && '' !== $review_url ) {
				$html .= '<p class="perflocale-mb-update">' . esc_html__( 'An update from the source is waiting for review.', 'perflocale' )
					. ' <a href="' . esc_url( $review_url ) . '">' . esc_html__( 'Review update', 'perflocale' ) . '</a></p>';
				break;
			}
		}

		$html .= '<ul class="perflocale-mb-list">';

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

		// The "update waiting" read: one indexed query for this post and its translations; none while ReSync is off.
		$resync  = \PerfLocale\Util\ResyncSwitch::on();
		$pending = array_merge( [ $post_id ], $prime_ids );
		$waiting = $resync ? \PerfLocale\Util\ResyncSwitch::waiting_updates( $pending ) : [];
		$updates = [] !== $waiting ? ReviewUpdate::links( $waiting ) : [];
		$rows    = [];

		// "Resync from source": on the source, each row with a translation the user can open; on a translation, its own
		// row. Only for a user and type the handler accepts and a readable source (the handler checks every pair again).
		// A lone post has no row to resync: its classes stay unloaded.
		$source     = $resync && count( $translations ) > 1 && ResyncLinks::allowed_for_type( $post->post_type ) ? ResyncLinks::source_of( $post, $translations ) : null;
		$can_resync = null !== $source;
		$is_source  = null !== $source && $source->ID === $post_id;

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $translations[ $lang->slug ] ?? null;

			// A link row can outlive its post: no Edit link to nothing.
			if ( $has_translation && $translated_id && ! get_post( $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			// A sibling the user may not view: the row of a missing translation, also for the rows filter.
			if ( $has_translation && $translated_id && (int) $translated_id !== $post_id && ! \PerfLocale\Helper::user_can_view_object( (int) $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			$is_current = ( $post_lang && $post_lang->slug === $lang->slug );
			$update_of  = $is_current ? $post_id : ( $has_translation && $translated_id ? (int) $translated_id : 0 );
			$edit_url   = ( $has_translation && $translated_id ) ? ObjectLinks::edit_url( (int) $translated_id ) : '';
			$resync_url = '';

			if ( $can_resync && ( $is_source ? ( ! $is_current && '' !== $edit_url ) : $is_current ) ) {
				$resync_url = ResyncLinks::resync_url( $post_id, Scalar::str( Scalar::prop( $lang, 'slug' ) ), 'referer' );
			}

			$rows[] = [
				'review_url'      => $update_of > 0 && isset( $updates[ $update_of ] ) ? $updates[ $update_of ]['url'] : '',
				'slug'            => (string) $lang->slug,
				'badge'           => \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->slug ),
				'native_name'     => (string) ( $lang->native_name ?: $lang->name ),
				'is_current'      => $is_current,
				'has_translation' => $has_translation,
				// Kept for `perflocale/panel/rows` listeners; always false: a hidden sibling's row is a missing one.
				'hidden'          => false,
				'translated_id'   => $translated_id !== null ? (int) $translated_id : null,
				'source_id'       => $post_id,
				'source_status'   => (string) $post->post_status,
				// In the row so `perflocale/panel/rows` can retarget it per mount context; the edit link filter
				// knows only the post type.
				'edit_url'        => $edit_url,
				// The row's "Resync from source" link, '' when none; in the row for the same reason.
				'resync_url'      => $resync_url,
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
	 * A row field as text ('' when not scalar): as Scalar::str(), without loading it on an edit screen with no ReSync control.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Field.
	 * @return string
	 */
	private static function row_text( array $row, string $key ): string {
		$value = $row[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
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

		$resync_url = self::row_text( $row, 'resync_url' );

		if ( $is_current ) {
			$html .= '<span class="perflocale-mb-pill perflocale-mb-pill--current">' . esc_html__( 'Current', 'perflocale' ) . '</span>';

			if ( '' !== $resync_url ) {
				$html .= '<a href="' . esc_url( $resync_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--resync"' . ResyncLinks::post_link_attributes( $resync_url ) . '>' . esc_html__( 'Resync from source', 'perflocale' ) . '</a>';
			}
		} elseif ( $has_translation && $translated_id > 0 ) {
			// From the row, so a `perflocale/panel/rows` listener can replace it.
			$edit_url = (string) ( $row['edit_url'] ?? '' );

			if ( $edit_url !== '' ) {
				$html .= '<a href="' . esc_url( $edit_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--edit">' . esc_html__( 'Edit', 'perflocale' ) . '</a>';

				// The translation's waiting update, for a user who may decide it.
				$review_url = self::row_text( $row, 'review_url' );

				if ( '' !== $review_url ) {
					$html .= '<a href="' . esc_url( $review_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--update">' . esc_html__( 'Update waiting', 'perflocale' ) . '</a>';
				}

				if ( '' !== $resync_url ) {
					$html .= '<a href="' . esc_url( $resync_url ) . '" class="perflocale-mb-pill perflocale-mb-pill--resync"' . ResyncLinks::post_link_attributes( $resync_url ) . '>' . esc_html__( 'Resync', 'perflocale' ) . '</a>';
				}
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
