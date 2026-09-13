<?php
/**
 * PerfLocale Oxygen Builder Classic addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oxygen Builder Classic integration for PerfLocale.
 *
 * Registers Oxygen's builder meta keys (JSON content, shortcodes,
 * page settings) as translatable so translated posts preserve
 * their Oxygen layouts. Also registers the ct_template custom
 * post type as translatable for reusable components.
 */
final class PerfLocaleOxygen implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'oxygen';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Oxygen Builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'oxygen/functions.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'CT_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register Oxygen meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// ⚠️ Only the TEXT-FREE keys mirror — see add_mirror_keys(). The builder
		// documents (ct_builder_json / ct_builder_shortcodes) are NOT on that
		// list: a mirror is bidirectional, so mirroring a document holding the
		// user's words destroys translated text in both directions.
		// ct_other_template is excluded for a separate reason — it stores a
		// per-page template POST ID, so mirroring would pin every sibling to the
		// source-language template.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A raw meta mirror of the layout leaves the sibling's GENERATED
		// CSS-cache file (uploads/oxygen/css/{id}.css) built from the
		// pre-sync layout; drop it so the sibling serves live CSS instead.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_css_cache' ], 10, 3 );

		// Register Oxygen custom post types as translatable.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// ⭐ ...but NEVER language-scope them, and resolve the sibling ourselves.
		//
		// Oxygen picks the template for a request by RULE MATCHING: it loads
		// every published ct_template with `new WP_Query( [ 'post_type' =>
		// 'ct_template', 'post_status' => 'publish' ] )` and walks them
		// (component-framework/includes/templates.php — the inner-content,
		// posts and archives resolvers), from the front-end dispatcher in
		// component-init.php, which returns early on `is_admin()`.
		//
		// Language-scoping that query is catastrophic, because Oxygen has no
		// "no template matched" fallback: `$is_template` stays false and the
		// page renders with NO header, footer or layout at all. Measured on
		// test.local with two published templates (one linked `en`, one `de`):
		//   /      => [1102638]      (en)
		//   /de/   => [1102639]      (de)
		//   /es/   => []   -> ct_get_archives_template() === false -> NO LAYOUT
		//   /fr/   => []   -> ditto
		// Every site that runs "Assign Default Language" — which Settings tells
		// operators to do first — lands in exactly that state on every language
		// it has not hand-translated templates for.
		//
		// Never-scoping alone would be wrong in the other direction: unlike
		// Contact Form 7 (resolved by a unique hash) and wp_navigation (whose
		// ref BlockRefTranslator swaps at render), Oxygen SELECTS BY QUERY, and
		// `copy_post_meta()` gives a sibling the same `_ct_template_order` and
		// matching rules as its source. Two published siblings would then match
		// identically and `orderby => meta_value_num` would return whichever
		// row MySQL happened to yield — making the rendered language undefined.
		// So the exemption is paired with resolve_template_language() below,
		// which collapses each translation group back to exactly one row.
		add_filter( 'perflocale/query/never_scoped_post_types', [ $this, 'never_scope_template_types' ] );
		add_filter( 'the_posts', [ $this, 'resolve_template_language' ], 20, 2 );

		// ⭐ The oxygen-gutenberg companion plugin registers one block type per
		// "full page block" PAGE, from a front-end `init` (priority 11) query:
		//
		//   $args2 = [ 'post_type' => 'page', 'post_status' => 'publish',
		//              'meta_key' => '_ct_oxygenberg_full_page_block',
		//              'meta_value' => '1' ];
		//   $page_blocks = new WP_Query( $args2 );
		//   … register_block_type( 'oxygen-vsb/ovsb-' . $post->post_name, … )
		//
		// (oxygen-gutenberg/oxygen-gutenberg.php:63-104.) `page` is scoped —
		// correctly, it is browsed by language — so on a non-default language
		// that query returned only that language's pages and the source's block
		// type was never registered. An unregistered dynamic block renders as
		// NOTHING, so the page body silently emptied. Measured on test.local
		// with the plugin activated: `/` registered
		// `oxygen-vsb/ovsb-zzfullpageblock`, `/de/` registered ZERO blocks.
		//
		// The remedy cannot be the never-scoped list — putting `page` on it
		// would leak every language's pages into every page listing. Instead
		// this one query shape is marked all-languages, which is what block
		// REGISTRATION needs: block names are slug-keyed and global, so
		// registering every language's blocks is both harmless and required for
		// a page in one language to embed a block defined in another.
		add_action( 'pre_get_posts', [ $this, 'unscope_full_page_block_query' ], 1 );

		// NOTE: Oxygen Classic doesn't expose a plugin-facing element
		// registration API - the `oxygen_custom_elements_list` filter that
		// previous versions of this addon targeted doesn't actually exist
		// in Oxygen source. Users wanting a language switcher in Oxygen
		// should use the `[perflocale_switcher]` shortcode inside an Oxygen
		// Code Block or Shortcode element.
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add Oxygen meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = 'ct_builder_json';
		$keys[] = 'ct_builder_shortcodes';
		$keys[] = 'ct_page_settings';
		$keys[] = 'ct_other_template';
		// 'ct_options' is not post meta — it's a JSON attribute inside ct_*
		// shortcodes — so it never had anything to sync.

		return $keys;
	}

	/**
	 * Add Oxygen keys that keep full-mirror semantics.
	 *
	 * A strict subset of {@see self::add_meta_keys()}, and now a much smaller one.
	 *
	 * ⚠️ `ct_builder_json` and `ct_builder_shortcodes` USED TO BE HERE AND MUST NOT
	 * COME BACK. They are not a layout skeleton — they are the documents holding
	 * every heading, paragraph and button label on the page. ContentSync's mirror
	 * is bidirectional ("any group member's save propagates group-wide"), so
	 * mirroring them destroys translated text in both directions: the translator
	 * saves and overwrites the source, then the next source save overwrites the
	 * translation. Reproduced end to end for Elementor on 4.2.1 and 4.1.3, and
	 * observed for Beaver Builder on a replica; Oxygen stores text the same way,
	 * so it was moved with them rather than waiting for its own incident.
	 *
	 * Both keys stay on the TRANSLATABLE list, so a new translation is still born
	 * with the source's full layout — it simply owns it afterwards. The accepted
	 * cost is that later structural edits no longer propagate; keeping a
	 * translator's words is worth more than automatic layout parity.
	 *
	 * What remains is page-level presentation with no user-visible text in it.
	 * `ct_other_template` is still excluded for its own separate reason: it is a
	 * per-page template ID that must not be pinned to the source language.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		$keys[] = 'ct_page_settings';

		return $keys;
	}

	/**
	 * Drop the sibling's generated Oxygen CSS cache after a layout mirror.
	 *
	 * Oxygen's universal CSS cache stores per-post state in the
	 * `oxygen_vsb_css_files_state` option and only rewrites a post's cached
	 * file on ITS OWN builder save, so a mirrored sibling keeps enqueueing
	 * CSS built from the old layout. With the state entry gone the frontend
	 * falls back to live `xlink=css` output. Done inline rather than via
	 * oxygen_vsb_delete_css_file(): that function trips undefined-index
	 * warnings on entries flagged 'empty' — and an 'empty' entry must ALSO
	 * be cleared, or a layout that gained CSS stays skipped by
	 * oxygen_vsb_load_cached_css_files().
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_css_cache( int $source_id, int $target_id, array $mirror_keys ): void {
		$oxygen_keys = [ 'ct_builder_json', 'ct_builder_shortcodes', 'ct_page_settings' ];

		if ( array_intersect( $oxygen_keys, $mirror_keys ) === [] ) {
			return;
		}

		$files_meta = get_option( 'oxygen_vsb_css_files_state', [] );

		if ( ! is_array( $files_meta ) || ! isset( $files_meta[ $target_id ] ) ) {
			return;
		}

		if ( ! empty( $files_meta[ $target_id ]['path'] ) && file_exists( $files_meta[ $target_id ]['path'] ) ) {
			wp_delete_file( $files_meta[ $target_id ]['path'] );
		}

		unset( $files_meta[ $target_id ] );

		// Oxygen's OWN option name, deliberately unprefixed: this is Oxygen's
		// generated-CSS manifest and we are invalidating the stale entry for a
		// translation we just mirrored. A perflocale_-prefixed key would write
		// to an option Oxygen never reads, leaving its cache stale.
		update_option( 'oxygen_vsb_css_files_state', $files_meta );
	}

	/**
	 * Add Oxygen post types as translatable.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'ct_template';

		return array_unique( $post_types );
	}

	/**
	 * Oxygen post types that resolve by QUERY and must never be scoped.
	 *
	 * `ct_template` is the layout selector (see boot()).
	 *
	 * `oxy_user_library` is Oxygen's reusable-block store. The Gutenberg
	 * bridge registers one block type per library post, keyed on the POST
	 * SLUG, from a front-end `init` query — so a scoped query means the block
	 * is never registered and every embedded reusable block renders empty.
	 * It is listed here rather than in add_post_types() on purpose: nothing
	 * marks it translatable today, and exempting a type that is not scoped is
	 * harmless, so this is the cheap guard against someone ticking it later.
	 *
	 * @param array<int, string> $post_types Never-scoped post types.
	 * @return array<int, string>
	 */
	public function never_scope_template_types( array $post_types ): array {
		$post_types[] = 'ct_template';
		$post_types[] = 'oxy_user_library';

		return array_unique( $post_types );
	}

	/**
	 * Exempt oxygen-gutenberg's full-page-block registration query from scoping.
	 *
	 * Matched on the exact shape the companion plugin uses — post type `page`
	 * plus the `_ct_oxygenberg_full_page_block` meta key — so no ordinary page
	 * query is affected. See the rationale in boot().
	 *
	 * Registered at priority 1, ahead of PerfLocale's own `pre_get_posts`
	 * stamping at priority 5.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public function unscope_full_page_block_query( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		if ( (string) $query->get( 'meta_key' ) !== '_ct_oxygenberg_full_page_block' ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = is_string( $post_type ) ? [ $post_type ] : (array) $post_type;

		if ( array_diff( $post_type, [ 'page' ] ) !== [] ) {
			return;
		}

		$query->set( 'perflocale_all_languages', true );
	}

	/**
	 * Collapse each translation group to the row that belongs to this request.
	 *
	 * Runs only on queries whose post types are entirely Oxygen's own, so the
	 * ordinary front-end loop never reaches it. Within such a query it keeps,
	 * per translation group and in the query's original order:
	 *
	 *   1. the row in the CURRENT language, when the query returned one;
	 *   2. otherwise the row in the DEFAULT language (the source);
	 *   3. otherwise the first row of that group the query returned.
	 *
	 * Only rows the query ALREADY returned are considered — a sibling is never
	 * substituted in. That matters: `PostTranslationManager` creates siblings
	 * as drafts, and Oxygen's resolvers all filter `post_status => 'publish'`,
	 * so an unpublished translation must stay invisible exactly as it is today.
	 * Posts with no translation link are passed through untouched.
	 *
	 * @param array<int, mixed> $posts Posts the query returned.
	 * @param \WP_Query         $query The query itself.
	 * @return array<int, mixed>
	 */
	public function resolve_template_language( $posts, $query ): array {
		// NOT `count( $posts ) < 2`: a meta-filtered lookup can return exactly
		// ONE row that belongs to the wrong language, and that single row is
		// precisely the case the drop-arm below exists for.
		if ( ! is_array( $posts ) || $posts === [] || ! $query instanceof \WP_Query ) {
			return is_array( $posts ) ? $posts : [];
		}

		$requested = $query->get( 'post_type' );
		$requested = is_string( $requested ) ? [ $requested ] : (array) $requested;

		// Only OUR types, and at least one of them: a mixed query (Oxygen's
		// editor does run `post_type => [ post, page, ct_template, ... ]`)
		// must pass through untouched rather than have its pages collapsed.
		if ( $requested === [] || array_diff( $requested, [ 'ct_template', 'oxy_user_library' ] ) !== [] ) {
			return $posts;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) || ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return $posts;
		}

		$current = $plugin->get( 'router' )->get_current_language();

		// No current language means wp-admin or a pre-routing request. Leave
		// the builder's own listings alone — collapsing them would hide a
		// translated template from the operator's template list.
		if ( ! is_object( $current ) || ! isset( $current->slug ) ) {
			return $posts;
		}

		$manager = new \PerfLocale\Translation\PostTranslationManager(
			$plugin->get( 'cache' ),
			$plugin->get( 'settings' )
		);

		$default = ( new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) ) )->get_default();
		$present = [];

		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$present[ (int) $post->ID ] = true;
			}
		}

		$out   = [];
		$taken = [];

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
				$out[] = $post;
				continue;
			}

			$id           = (int) $post->ID;
			$translations = $manager->get_translations( $id );

			// UNLINKED ONLY. A template with no link row has no language, so
			// there is no language rule to apply to it and it must render for
			// everyone — that is the untranslated Oxygen site, and dropping its
			// templates would blank the layout.
			//
			// ⚠️ DO NOT restore the `count( $translations ) < 2` form this
			// replaced ("a group of one: nothing to collapse"). Collapsing is
			// not the only job here — the never-sideways rule below is the
			// other one, and a group of ONE is where it matters most. Delete
			// the source and the German sibling out of a three-language group
			// and the lone FRENCH template is the entire group, so `count < 2`
			// short-circuited and handed it to a German visitor. Worse, it was
			// a regression: 1.0.4 returned nothing for that query because
			// ct_template was still language-scoped in SQL. Exempting the type
			// from scoping moved the decision out of the query and into this
			// method, so this is the arm that now has to make it.
			//
			// Measured on the frozen 1.0.5 candidate, group {fr: 1107006} on a
			// /de/ request: returned [1107006] with the old guard, [] with this
			// one — matching 1.0.4 exactly.
			if ( ! is_array( $translations ) || $translations === [] ) {
				$out[] = $post;
				continue;
			}

			// Group identity = the set of ids in the group. Stable regardless
			// of which member we happen to be standing on.
			$ids = array_map( 'intval', array_values( $translations ) );
			sort( $ids );
			$group_key = implode( ',', $ids );

			if ( isset( $taken[ $group_key ] ) ) {
				continue;
			}

			$winner = null;

			if ( isset( $translations[ $current->slug ] ) && isset( $present[ (int) $translations[ $current->slug ] ] ) ) {
				$winner = (int) $translations[ $current->slug ];
			} elseif ( $default && isset( $translations[ $default->slug ] ) && isset( $present[ (int) $translations[ $default->slug ] ] ) ) {
				$winner = (int) $translations[ $default->slug ];
			}

			$taken[ $group_key ] = true;

			// Neither the current language nor the source came back. Whatever
			// IS here belongs to some third language, so drop it: a template
			// may fall back to its SOURCE, never sideways to another
			// translation. This is reachable whenever the rule meta has
			// diverged between siblings — Oxygen's index-template lookup, for
			// instance, filters on `_ct_template_index`, so a sibling that was
			// flagged as the index while its source was not is the only row
			// the query returns. Without this arm an /es/ visitor would get
			// the GERMAN layout.
			if ( $winner === null ) {
				continue;
			}

			if ( $winner === $id ) {
				$out[] = $post;
				continue;
			}

			$winner_post = get_post( $winner );
			$out[]       = $winner_post instanceof \WP_Post ? $winner_post : $post;
		}

		return $out;
	}
}
