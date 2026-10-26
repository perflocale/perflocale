<?php
/**
 * Block templates: creating and shaping their translations (writes only).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\TranslationStatus;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The save and create paths of BlockTemplateSupport, which forwards here after its cheap template-type checks.
 *
 * @internal
 */
final class BlockTemplateWrites {

	/**
	 * Language slug a translation belongs to, or '' when unknown.
	 *
	 * @param int $post_id Translation post ID.
	 * @return string
	 */
	private static function language_of( int $post_id ): string {
		try {
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( ! $plugin->has( 'group_repo' ) || ! $plugin->has( 'lang_repo' ) ) {
				return '';
			}

			global $wpdb;

			$links = $wpdb->prefix . 'perflocale_translation_links';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lang_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT language_id FROM %i WHERE object_id = %d AND type = 'post' LIMIT 1", $links, $post_id )
			);

			if ( $lang_id <= 0 ) {
				return '';
			}

			$lang = $plugin->get( 'lang_repo' )->find( $lang_id );

			return is_object( $lang ) && ! empty( $lang->slug ) ? (string) $lang->slug : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Give every active language a copy of this template as soon as it is saved.
	 *
	 * Templates auto-create, unlike posts: each copy is a draft (core ignores a draft
	 * template) seeded with the source's blocks. Only the source fans out, or the
	 * group would grow a second original.
	 *
	 * @param int           $post_id Post being saved.
	 * @param \WP_Post|null $post    The post, as WordPress passes it.
	 * @return void
	 */
	public static function ensure_translations_exist( int $post_id, $post = null ): void {
		static $running = false;

		if ( $running || ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! BlockTemplateSupport::is_template_type( $post->post_type ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// An auto-draft is the Site Editor's scratch state and a trashed template is
		// on its way out; neither should seed anything.
		if ( in_array( $post->post_status, [ 'auto-draft', 'trash', 'inherit' ], true ) ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) || ! $plugin->has( 'lang_repo' ) ) {
			return;
		}

		$settings = $plugin->get( 'settings' );

		// Full Site Editing is opt-in; this type is translatable only while it is on.
		if ( ! in_array( $post->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

		/**
		 * Filter whether saving a block template fans out copies to every language.
		 *
		 * Return false to keep template translations entirely manual.
		 *
		 * @hook  perflocale/fse/auto_create_translations
		 * @since 1.0.5
		 *
		 * @param bool     $enabled Whether to create the missing translations.
		 * @param \WP_Post $post    The template being saved.
		 * @return bool
		 */
		if ( ! apply_filters( 'perflocale/fse/auto_create_translations', true, $post ) ) {
			return;
		}

		// No `wp_theme` term yet: core cannot see the row, and the create path's
		// active-theme fallback would file every copy under that theme for good.
		// See docs-ai/11-code-rationale.md, "BlockTemplateWrites: theme term on fan-out".
		$themes = get_the_terms( $post_id, 'wp_theme' );

		if ( ! is_array( $themes ) ) {
			return;
		}

		$theme     = reset( $themes );
		$lang_repo = $plugin->get( 'lang_repo' );
		$default   = $lang_repo->get_default();

		if ( ! is_object( $default ) || empty( $default->slug ) ) {
			return;
		}

		$manager   = new PostTranslationManager( $plugin->get( 'cache' ), $settings );
		$post_lang = $manager->detect_post_language( $post_id );

		// No language yet (auto_assign_default_language could not place it), or this
		// is itself a translation. Either way, not the post that fans out.
		if ( ! is_object( $post_lang ) || empty( $post_lang->slug ) || $post_lang->slug !== $default->slug ) {
			return;
		}

		$existing = $manager->get_translations( $post_id );
		$running  = true;

		try {
			foreach ( $lang_repo->get_active() as $lang ) {
				$slug = (string) ( $lang->slug ?? '' );

				if ( $slug === '' || $slug === $default->slug ) {
					continue;
				}

				// A link row can outlive the post it names, so confirm the target is
				// really there before deciding this language is covered.
				if ( isset( $existing[ $slug ] ) && get_post( (int) $existing[ $slug ] ) instanceof WP_Post ) {
					continue;
				}

				if ( $theme instanceof \WP_Term && self::relink_orphan( $post, $theme, $slug, (int) $lang->id, (int) $default->id ) ) {
					continue;
				}

				$manager->create_translation( $post_id, $slug, true );
			}
		} catch ( \Throwable $e ) {
			// One language failing must not break the save the operator asked for. Silent:
			// this runs on every template save, including anonymous front-end ones.
			unset( $e );
		} finally {
			$running = false;
		}
	}

	/**
	 * Relink this language's orphaned translation into the source's group instead of seeding a copy.
	 *
	 * "Reset to theme default" deletes the source and leaves its translations; a
	 * seeded copy would strand the finished one and take its slug. Match rules: see
	 * docs-ai/11-code-rationale.md, "BlockTemplateWrites: orphan relink".
	 *
	 * @param WP_Post  $source     The template being saved (default language).
	 * @param \WP_Term $theme      The source's wp_theme term.
	 * @param string   $lang_slug  Target language slug.
	 * @param int      $lang_id    Target language ID.
	 * @param int      $default_id Default language ID.
	 * @return bool True when an orphan was found; the caller then seeds no copy.
	 */
	private static function relink_orphan( WP_Post $source, \WP_Term $theme, string $lang_slug, int $lang_id, int $default_id ): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $source->post_name === '' || ! $plugin->has( 'group_repo' ) ) {
			return false;
		}

		global $wpdb;

		$links = $wpdb->prefix . 'perflocale_translation_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphan_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM %i p
				 INNER JOIN %i tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				 INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d
				 WHERE p.post_type = %s
				   AND p.post_name = %s
				   AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
				   AND NOT EXISTS ( SELECT 1 FROM %i d WHERE d.group_id = l.group_id AND d.language_id = %d )
				 ORDER BY ( p.post_status = 'publish' ) DESC, p.ID DESC
				 LIMIT 1",
				$wpdb->posts,
				$wpdb->term_relationships,
				(int) $theme->term_taxonomy_id,
				$links,
				$lang_id,
				$source->post_type,
				BlockTemplateSupport::translation_slug( $source->post_name, $lang_slug ),
				$links,
				$default_id
			)
		);

		if ( $orphan_id <= 0 ) {
			return false;
		}

		$groups = $plugin->get( 'group_repo' );

		// A found orphan is never shadowed by a seeded copy, which would take its
		// slug. When the move cannot happen the language stays missing, and the
		// next save of the template tries again.
		if ( $groups instanceof TranslationGroupRepository ) {
			$group = $groups->find_for_object( $source->ID, ObjectType::Post );

			if ( $group !== null ) {
				$groups->link_object( (int) $group->id, $orphan_id, $lang_id, TranslationStatus::NeedsUpdate->value );
			}
		}

		return true;
	}

	/**
	 * Give the new translation a distinct slug and a self-describing title.
	 *
	 * @param array<string, mixed> $data        Post data about to be inserted.
	 * @param int                  $source_id   Source post ID.
	 * @param string               $target_slug Target language slug.
	 * @return array<string, mixed>
	 */
	public static function shape_post_data( array $data, int $source_id, string $target_slug ): array {
		if ( ! BlockTemplateSupport::is_template_type( (string) ( $data['post_type'] ?? '' ) ) ) {
			return $data;
		}

		$source_name = (string) ( $data['post_name'] ?? '' );

		if ( $source_name === '' ) {
			$source = get_post( $source_id );
			$source_name = $source instanceof WP_Post ? $source->post_name : '';
		}

		if ( $source_name === '' ) {
			// No slug to derive from: a translation with no slug is never found by
			// core's query, the safe direction.
			return $data;
		}

		$data['post_name'] = BlockTemplateSupport::translation_slug( $source_name, $target_slug );

		// Two rows called "Header" look alike in the Site Editor and the Translations
		// list, so the title names the language.
		$title = (string) ( $data['post_title'] ?? '' );

		if ( $title !== '' ) {
			$data['post_title'] = sprintf(
				/* translators: 1: source template title, 2: language slug. */
				_x( '%1$s (%2$s)', 'translated block template title', 'perflocale' ),
				$title,
				$target_slug
			);
		}

		return $data;
	}

	/**
	 * Give the new translation the terms core's own lookup requires.
	 *
	 * Set here rather than through `tax_input` on the insert:
	 * `wp_insert_post()` capability-gates `tax_input`, which is wrong in CLI,
	 * cron and machine-translation contexts where there is no user.
	 *
	 * @param int    $new_post_id New translation post ID.
	 * @param string $object_type 'post' or 'term'.
	 * @param string $target_slug Target language slug.
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public static function attach_terms( int $new_post_id, string $object_type, string $target_slug, int $source_id ): void {
		unset( $target_slug );

		if ( $object_type !== 'post' ) {
			return;
		}

		$new_post = get_post( $new_post_id );

		if ( ! $new_post instanceof WP_Post || ! BlockTemplateSupport::is_template_type( $new_post->post_type ) ) {
			return;
		}

		// Core's query needs this term. Mirror the source's theme, not the active one
		// (core stores one `get_stylesheet()` term per template, so no `get_template()`
		// fallback); the active theme only for a source with none, which core rejects.
		$source_theme = get_the_terms( $source_id, 'wp_theme' );
		$theme_name   = get_stylesheet();

		if ( is_array( $source_theme ) && isset( $source_theme[0]->name ) && (string) $source_theme[0]->name !== '' ) {
			$theme_name = (string) $source_theme[0]->name;
		}

		wp_set_object_terms( $new_post_id, $theme_name, 'wp_theme', false );

		// Re-assert the slug: with `default_translation_status = 'pending'` core blanks
		// `post_name` on an insert with no user (CLI, cron, MT queue), and publishing
		// then derives one from the "X (de)" title that never matches.
		$source = get_post( $source_id );

		if ( $source instanceof WP_Post && $source->post_name !== '' ) {
			$lang_slug = self::language_of( $new_post_id );

			if ( $lang_slug !== '' ) {
				$expected = BlockTemplateSupport::translation_slug( $source->post_name, $lang_slug );

				if ( $new_post->post_name !== $expected ) {
					// The update saves the copied content again; it keeps the
					// source's markup as the insert did.
					$copied = new CopiedContentSave( $source_id, $source_id, $new_post_id );

					try {
						$copied->arm();

						wp_update_post(
							[
								'ID'        => $new_post_id,
								'post_name' => $expected,
							]
						);
					} finally {
						$copied->disarm();
					}
				}
			}
		}

		// The area term drives which slot the Site Editor offers the part in
		// (header/footer/uncategorized). Mirror the source so a translation is
		// never orphaned into the wrong area.
		if ( $new_post->post_type === 'wp_template_part' ) {
			$areas = wp_get_object_terms( $source_id, 'wp_template_part_area', [ 'fields' => 'slugs' ] );

			if ( ! is_wp_error( $areas ) && $areas !== [] ) {
				wp_set_object_terms( $new_post_id, $areas, 'wp_template_part_area', false );
			}
		}
	}
}
