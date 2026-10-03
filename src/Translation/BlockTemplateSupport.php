<?php
/**
 * Data shape for translated block templates and template parts.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\TranslationStatus;
use PerfLocale\Helper;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives a `wp_template` / `wp_template_part` translation the shape core needs
 * in order to find it, and keeps the plugin's automatic paths away from types
 * that are infrastructure rather than content.
 *
 * ⭐ WHY A TRANSLATION NEEDS A SPECIAL SHAPE
 *
 * A template part is not resolved by id. `render_block_core_template_part()`
 * runs its OWN `WP_Query` (wp-includes/blocks/template-part.php:29):
 *
 *     post_type      => 'wp_template_part'
 *     post_status    => 'publish'
 *     post_name__in  => array( $attributes['slug'] )
 *     tax_query      => wp_theme = get_stylesheet()
 *
 * So a translation is findable only if it is PUBLISHED, carries the `wp_theme`
 * term for the active stylesheet, and has a post_name DISTINCT from the source
 * — distinct because that slug is the only handle
 * {@see \PerfLocale\Frontend\BlockTemplateTranslator} has to point the block at.
 *
 * Two plugin behaviours actively work against that, which is why this class
 * exists rather than a couple of inline calls:
 *
 *   1. {@see PostTranslationManager::do_create_translation()} copies the
 *      source's `post_name` verbatim, and inserts as `draft`/`pending`.
 *      `wp_unique_post_slug()` RETURNS EARLY for those statuses
 *      (wp-includes/post.php, the draft|pending|auto-draft short-circuit), so
 *      nothing would ever make the slug unique on its own.
 *   2. `Bootstrap::allow_translation_duplicate_slugs()` deliberately collapses
 *      WordPress's `-2` suffix back to the source slug for translations. That
 *      is correct for content — a German page should keep its own clean slug —
 *      and fatal here: two rows would answer the same `post_name__in`.
 *
 * `copy_taxonomy_terms()` is no help either: it copies only TRANSLATABLE
 * taxonomies, and `wp_theme` can never be one.
 *
 * ⚠️ THE FALLBACK GUARANTEE. A translation that is missing, draft, trashed or
 * wrongly shaped must render the ORIGINAL, never an empty area. That is not
 * enforced by a checklist here — it falls out of the data shape: core's query
 * demands `post_status = publish` AND the `wp_theme` term, so anything this
 * class failed to finish simply is not found, and the resolver's own existence
 * check (see BlockTemplateTranslator) declines to rewrite. Both layers fail
 * closed, toward the source.
 */
final class BlockTemplateSupport {

	/**
	 * Post types this class shapes. These are FSE infrastructure: resolved by
	 * slug, never browsed by language.
	 */
	public const TYPES = [ 'wp_template', 'wp_template_part' ];

	/**
	 * Slug marker separating a source slug from its language suffix.
	 *
	 * Chosen to be something a human would not type and core would not
	 * generate, so {@see self::is_translation_slug()} can recognise our own
	 * rows without a database round trip.
	 */
	public const SLUG_MARKER = '-pfl-';

	/**
	 * Longest post_name we will produce. `wp_posts.post_name` is
	 * VARCHAR(200); leave room for the marker and the language slug.
	 */
	private const SLUG_MAX = 180;

	/**
	 * Is this post type an FSE template type?
	 *
	 * Static because the plugin's automatic paths (Bootstrap's save_post and
	 * transition_post_status handlers, ContentSync) need to ask the question
	 * without resolving a service.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_template_type( string $post_type ): bool {
		return in_array( $post_type, self::TYPES, true );
	}

	/**
	 * Does this slug belong to one of OUR translations?
	 *
	 * ⚠️ THIS MUST BE ANCHORED AND LANGUAGE-AWARE. It was originally a bare
	 * `str_contains( $slug, '-pfl-' )`, and that deleted legitimate operator
	 * templates from resolution — in EVERY language, including the default,
	 * on sites that had never enabled the feature. Reproduced: two identical
	 * pages differing only in template slug,
	 *
	 *     wp-custom-template-plf-demo -> renders
	 *     wp-custom-template-pfl-demo -> template GONE, hierarchy falls through
	 *
	 * and because core builds `slug__not_in` from the rows it already found,
	 * dropping the database row suppressed the same-named THEME FILE too — the
	 * customisation and its fallback both vanished with no visible cause.
	 * `page-pfl-x`, `category-pfl-x` and `header-pfl-department` all tripped it.
	 *
	 * A slug is ours only when it ends with the marker followed by a slug of a
	 * language this site actually has. Passing the language list is therefore
	 * strongly preferred; the anchored fallback exists only for callers that
	 * cannot resolve it, and still requires the marker to be a SUFFIX.
	 *
	 * The marker and language may be followed by `-<digits>` and nothing else
	 * (`header-pfl-de-2`). Core gives a template translation that suffix when
	 * it is published while another row of the same theme holds
	 * `header-pfl-de`: drafts skip `wp_unique_post_slug()`, publishing does
	 * not. Such a row is still a translation, and listed it would show a
	 * second "Header (de)" beside the source.
	 *
	 * @param string            $slug        Post name.
	 * @param array<int,string> $lang_slugs  Active language slugs. Empty = fall
	 *                                       back to the anchored pattern.
	 * @return bool
	 */
	public static function is_translation_slug( string $slug, array $lang_slugs = [] ): bool {
		if ( $slug === '' || ! str_contains( $slug, self::SLUG_MARKER ) ) {
			return false;
		}

		if ( $lang_slugs !== [] ) {
			// The slug without its trailing `-<digits>`, or '' when it has none.
			$trimmed    = rtrim( $slug, '0123456789' );
			$unnumbered = ( $trimmed !== $slug && str_ends_with( $trimmed, '-' ) ) ? substr( $trimmed, 0, -1 ) : '';

			foreach ( $lang_slugs as $lang ) {
				$lang   = sanitize_key( (string) $lang );
				$suffix = self::SLUG_MARKER . $lang;

				if ( $lang !== '' && ( str_ends_with( $slug, $suffix ) || ( $unnumbered !== '' && str_ends_with( $unnumbered, $suffix ) ) ) ) {
					return true;
				}
			}

			return false;
		}

		return (bool) preg_match( '~' . preg_quote( self::SLUG_MARKER, '~' ) . '[a-z0-9_-]+$~', $slug );
	}

	/**
	 * Build the translation slug for a source slug + language.
	 *
	 * @param string $source_slug Source post_name.
	 * @param string $lang_slug   Target language slug.
	 * @return string
	 */
	public static function translation_slug( string $source_slug, string $lang_slug ): string {
		$base = Helper::truncate_slug( $source_slug, self::SLUG_MAX );

		// ⚠️ TRUNCATION COLLIDES. Two different sources of 185 and 184
		// characters produced the SAME translation slug, and
		// `wp_unique_post_slug()` does not dedupe `wp_template_part` — both rows
		// coexisted under one post_name and core's `posts_per_page => 1` picked
		// one arbitrarily, so one template rendered another's translation.
		// `Helper::truncate_slug()` also rtrim()s trailing hyphens, which adds a
		// second collision path at the boundary.
		//
		// When (and only when) the base was shortened, append a short stable
		// digest of the FULL source slug. Untruncated slugs keep their readable
		// form, so this costs nothing in the normal case.
		if ( $base !== $source_slug ) {
			$base .= '-' . substr( md5( $source_slug ), 0, 8 );
		}

		return $base . self::SLUG_MARKER . sanitize_key( $lang_slug );
	}

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
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'perflocale/translation/post_data', [ $this, 'shape_post_data' ], 10, 3 );
		add_action( 'perflocale/translation/created', [ $this, 'attach_terms' ], 10, 4 );

		// Priority 40: after auto_assign_default_language (5), which is what gives a
		// brand-new template its language, and after ContentSync (20) and
		// ContentChangeDetector (30), so neither sees a half-built group.
		add_action( 'save_post', [ $this, 'ensure_translations_exist' ], 40, 2 );
	}

	/**
	 * Give every active language a copy of this template as soon as it is saved.
	 *
	 * ⭐ WHY TEMPLATES AUTO-CREATE WHEN NOTHING ELSE DOES.
	 *
	 * For posts and pages, minting a translation on every save would scatter drafts
	 * nobody asked for — you translate the handful of pages you care about, not all
	 * of them. Templates are the opposite case on all three counts:
	 *
	 *   - The set is small and structural. A theme has a header, a footer, a 404 and
	 *     a dozen or so layouts, and a site almost always wants all of them in all
	 *     of its languages. Left to hand-creation, the one you forget is the one that
	 *     renders the wrong language.
	 *   - The Site Editor offers nowhere to create one. Its Translations panel lists
	 *     the languages and, for a template with no siblings, was a dead end: it named
	 *     the problem and gave you nothing to click.
	 *   - ⭐ It cannot change what visitors see. A translation is created as a DRAFT,
	 *     and WordPress ignores a draft template and renders the original instead. So
	 *     this fills in the missing pieces without touching the front end until
	 *     somebody publishes one deliberately.
	 *
	 * It also answers what happens when the language set changes: add a language and
	 * save the template, and the missing copy appears; the panel reads the active
	 * languages live, so it reflects the new set immediately.
	 *
	 * Each copy is seeded with the source's blocks (`$copy_content = true`). An empty
	 * template is worse than none — publish one and the language loses its header and
	 * footer.
	 *
	 * A language whose translation of this template was left behind by "Reset to
	 * theme default" gets that translation back instead of a new copy
	 * ({@see self::relink_orphan()}).
	 *
	 * ⚠️ ONLY THE SOURCE FANS OUT. A saved German template must not mint an English
	 * one, or the group would grow a second "original". The recursion guard is belt
	 * and braces on top of that: creating a translation fires `save_post` for the new
	 * post, which returns early anyway because its language is not the default.
	 *
	 * @param int           $post_id Post being saved.
	 * @param \WP_Post|null $post    The post, as WordPress passes it.
	 * @return void
	 */
	public function ensure_translations_exist( int $post_id, $post = null ): void {
		static $running = false;

		if ( $running || ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! self::is_template_type( $post->post_type ) ) {
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

		// Full Site Editing is opt-in, and this type is only translatable while it is
		// on. Off means off: no fan-out.
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

		// ⚠️ A template with no `wp_theme` term is not a template core can SEE:
		// `get_block_templates()` joins that taxonomy (see the class docblock),
		// so nothing resolves to this row yet and there is nothing to translate.
		//
		// Fanning out from it is worse than useless. `create_block_template_translation()`
		// falls back to the ACTIVE theme for a source with no term of its own —
		// deliberately, so an operator's explicit request cannot produce a term-less
		// row core rejects outright. Here nobody asked, so that fallback would file
		// every sibling under whichever theme happened to be active, taking identity
		// from the request instead of from the thing being translated. And because
		// the loop below skips any language it has already covered, the borrowed
		// theme would be permanent.
		//
		// The Site Editor is unaffected, and this is checked rather than assumed:
		// WP_REST_Templates_Controller::prepare_item_for_database() puts the theme in
		// `tax_input`, and wp_insert_post() writes those terms (post.php, the
		// `tax_input` block) well before it fires `save_post`. A programmatic insert
		// that sets the term afterwards simply fans out on its NEXT save, by which
		// time the term is there and correct.
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

				if ( $theme instanceof \WP_Term && $this->relink_orphan( $post, $theme, $slug, (int) $lang->id, (int) $default->id ) ) {
					continue;
				}

				$manager->create_translation( $post_id, $slug, true );
			}
		} catch ( \Throwable $e ) {
			// A failure to seed one language must not take down the save the operator
			// actually asked for — the template itself is already written by now.
			// Swallowed deliberately and silently: this runs on every template save,
			// including anonymous front-end ones that can reach save_post, so it is
			// not a place to write to the PHP error log on a loop.
			unset( $e );
		} finally {
			$running = false;
		}
	}

	/**
	 * Move this language's orphaned translation of the template into the
	 * source's group, instead of seeding a second copy.
	 *
	 * ⭐ WHY. "Reset to theme default" in the Site Editor deletes the source row
	 * for good (the template controller's `source=theme` path calls
	 * `wp_delete_post( $id, true )`), and only the source's own link goes with
	 * it. Its translations stay behind with their slugs, terms and content, in a
	 * group with no default-language member, which
	 * {@see \PerfLocale\Frontend\BlockTemplateTranslator} never serves. When the
	 * template is customised again, core inserts a NEW source row. A seeded copy
	 * would leave the finished translation unreachable and give two rows the
	 * same `…-pfl-de` slug: core renames the copy to `…-pfl-de-2` when it is
	 * published, and the Site Editor's next save of "Header (de)" goes into the
	 * orphan.
	 *
	 * The orphan must match on everything that makes it this template's
	 * translation:
	 *   - the same post type, and `post_name` = translation_slug( source, lang );
	 *   - the source's `wp_theme` term: block themes share default slugs (Twenty
	 *     Twenty-Four and Twenty Twenty-Five collide on eight), so the slug alone
	 *     would take another theme's translation;
	 *   - not trashed, an auto-draft or a revision;
	 *   - linked in this language, in a group with NO default-language member,
	 *     so the translation of a source that still exists is never taken.
	 * When several rows match, a published one wins, then the newest.
	 *
	 * The orphan keeps its post status and is flagged needs_update: it
	 * translates the previous customisation, and a published one is served
	 * again at once. link_object() moves it out of its old group and removes
	 * that group once it is empty.
	 *
	 * One indexed SELECT, run only for a language the source's group lacks.
	 *
	 * @param WP_Post  $source     The template being saved (default language).
	 * @param \WP_Term $theme      The source's wp_theme term.
	 * @param string   $lang_slug  Target language slug.
	 * @param int      $lang_id    Target language ID.
	 * @param int      $default_id Default language ID.
	 * @return bool True when an orphan was found; the caller then seeds no copy.
	 */
	private function relink_orphan( WP_Post $source, \WP_Term $theme, string $lang_slug, int $lang_id, int $default_id ): bool {
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
				self::translation_slug( $source->post_name, $lang_slug ),
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
	public function shape_post_data( array $data, int $source_id, string $target_slug ): array {
		if ( ! self::is_template_type( (string) ( $data['post_type'] ?? '' ) ) ) {
			return $data;
		}

		$source_name = (string) ( $data['post_name'] ?? '' );

		if ( $source_name === '' ) {
			$source = get_post( $source_id );
			$source_name = $source instanceof WP_Post ? $source->post_name : '';
		}

		if ( $source_name === '' ) {
			// Nothing to derive a distinct slug from. Leave the data alone: a
			// translation with no slug is simply never found by core's query,
			// which is the safe direction.
			return $data;
		}

		$data['post_name'] = self::translation_slug( $source_name, $target_slug );

		// A template's title is what the Site Editor and the Translations list
		// show. Two rows called "Header" are indistinguishable, so say which is
		// which even though translations are hidden from the Site Editor by
		// default — an operator who opts back in should not see a duplicate.
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
	 * ⚠️ Set here rather than through `tax_input` on the insert:
	 * `wp_insert_post()` capability-gates `tax_input`, which is wrong in CLI,
	 * cron and machine-translation contexts where there is no user.
	 *
	 * @param int    $new_post_id New translation post ID.
	 * @param string $object_type 'post' or 'term'.
	 * @param string $target_slug Target language slug.
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public function attach_terms( int $new_post_id, string $object_type, string $target_slug, int $source_id ): void {
		unset( $target_slug );

		if ( $object_type !== 'post' ) {
			return;
		}

		$new_post = get_post( $new_post_id );

		if ( ! $new_post instanceof WP_Post || ! self::is_template_type( $new_post->post_type ) ) {
			return;
		}

		// Without this term core's query cannot see the row at all, whatever
		// else is right about it.
		//
		// ⚠️ MIRROR THE SOURCE'S THEME, NOT THE ACTIVE ONE.
		//
		// This used to stamp `get_stylesheet()` unconditionally, which is only
		// the same thing while the source happens to belong to the theme that
		// is active right now. Translate a template belonging to a DIFFERENT
		// retained theme and the translation was filed under the active theme
		// instead — inheriting identity from the request rather than from the
		// thing being translated. The area term twenty lines below has always
		// been mirrored from the source; this is the same rule, and the
		// asymmetry was the bug.
		//
		// There is no parent/child dimension to handle: core writes
		// `get_stylesheet()` for every DB-stored template and matches it by
		// name, so a source can only legitimately carry one theme term. A
		// fallback that also accepted `get_template()` would reopen this bug in
		// child-theme form.
		//
		// The fallback is the ACTIVE theme, for a source with no term of its
		// own — the old behaviour, kept so a malformed source cannot leave the
		// translation with no term at all, which core rejects outright.
		$source_theme = get_the_terms( $source_id, 'wp_theme' );
		$theme_name   = get_stylesheet();

		if ( is_array( $source_theme ) && isset( $source_theme[0]->name ) && (string) $source_theme[0]->name !== '' ) {
			$theme_name = (string) $source_theme[0]->name;
		}

		wp_set_object_terms( $new_post_id, $theme_name, 'wp_theme', false );

		// ⚠️ RE-ASSERT THE SLUG. With `default_translation_status = 'pending'`
		// — a shipped option — core BLANKS `post_name` on insert in a context
		// with no user (CLI, cron, the MT queue, a webhook: exactly the
		// contexts this method's own docblock cites), and publishing later
		// regenerates it from the title. Because the title was also rewritten
		// to "X (de)" the regenerated slug LOOKS right while never matching,
		// so the feature silently never resolved and the row was invisible.
		// Measured: inserted 'zz-slugtest-pfl-de' -> stored '' -> published as
		// 'zz-slugtest-de'.
		$source = get_post( $source_id );

		if ( $source instanceof WP_Post && $source->post_name !== '' ) {
			$lang_slug = self::language_of( $new_post_id );

			if ( $lang_slug !== '' ) {
				$expected = self::translation_slug( $source->post_name, $lang_slug );

				if ( $new_post->post_name !== $expected ) {
					wp_update_post(
						[
							'ID'        => $new_post_id,
							'post_name' => $expected,
						]
					);
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
