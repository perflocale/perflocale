<?php
/**
 * Front-end resolution of translated block templates and template parts.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;
use PerfLocale\Translation\BlockTemplateSupport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the translated CONTENT of a customised block template or template
 * part, without changing WHICH template the hierarchy chose.
 *
 * ⭐ TWO TYPES, TWO COMPLETELY DIFFERENT SEAMS. This is not symmetry for its
 * own sake — core resolves them through unrelated code paths:
 *
 *   wp_template      locate_block_template() -> resolve_block_template()
 *                    -> get_block_templates() (PLURAL), block-template.php:171.
 *                    ⚠️ get_block_template() SINGULAR is the REST / Site Editor
 *                    path and NEVER fires on a front-end page. A class hooked
 *                    there passes every structural test and does nothing.
 *
 *   wp_template_part resolves through NEITHER. render_block_core_template_part()
 *                    runs its OWN WP_Query (blocks/template-part.php:29) keyed on
 *                    $attributes['slug'] + a wp_theme tax query, and the only hook
 *                    on that path is the NON-mutating action
 *                    `render_block_core_template_part_post`. So the only way in
 *                    is to change the slug the block asks for, BEFORE core looks
 *                    it up — hence `render_block_data`.
 *
 * ⚠️ NEVER MUTATE WHAT CORE HANDS YOU. The failure mode this guards against is
 * the only unrecoverable one in the feature: translated content reaching the
 * SITE EDITOR, which then saves it over the operator's original with no undo.
 *
 * What is actually true, verified against core rather than assumed — because
 * the received wisdom here ("the returned objects live in the persistent object
 * cache") is WRONG for this class, and a deep clone bought on that reasoning
 * would have been pure overhead:
 *
 *   - `WP_Block_Template` has NO object properties. Every one is a string,
 *     bool, int, null or string[] (class-wp-block-template.php). PHP copies
 *     arrays by value, so a shallow `clone` shares nothing that can be mutated
 *     back into the original.
 *   - `wp-includes/block-template-utils.php` contains ZERO `wp_cache_*` calls,
 *     and `_build_block_template_result_from_post()` CONSTRUCTS A NEW object on
 *     every call. The objects handed to this filter are per-call, not cached.
 *
 * So the clone below is provably sufficient today, and a deep clone would cost
 * a full recursive copy for no added safety. It is kept rather than dropped
 * because it is nearly free and it stays correct if core ever starts caching
 * these, or if a lower-priority filter holds a reference to the same object.
 *
 * ⚠️ That sufficiency depends on a property of CORE, so it is pinned by a test
 * rather than by this comment: the block-template-translation suite asserts
 * that no `WP_Block_Template` property is an object. If core ever adds one, the
 * suite fails loudly instead of this silently becoming a data-loss bug.
 *
 * ⚠️ FRONT END ONLY, for the same reason `BlockRefTranslator` is: a rewritten
 * `attrs['slug']` that reaches the editor is saved back into the SOURCE
 * template on the next save, silently replacing a reference to "header" with a
 * reference to "header-pfl-de". `is_admin()` plus a `rest_api_init` detach is
 * the proven guard in this codebase.
 *
 * The template `id` (`theme//slug`) is never touched. That id is what drives
 * the template hierarchy, so changing it would change WHICH template renders
 * rather than what it says.
 */
final class BlockTemplateTranslator {

	/**
	 * Language router.
	 *
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Language repository.
	 *
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $languages;

	/**
	 * Whether the filters are currently attached.
	 *
	 * @var bool
	 */
	private bool $attached = false;

	/**
	 * Primed slug => translated post ID maps, per post type, for the CURRENT
	 * language. Null until primed.
	 *
	 * One query per post type per request replaces one query per block. The
	 * precedent is BlockRefTranslator::prime_references().
	 *
	 * @var array<string, array<string, array{id: int, slug: string}>|null>
	 */
	private array $primed = [
		'wp_template'      => null,
		'wp_template_part' => null,
	];

	/**
	 * Language slug the primed maps belong to.
	 *
	 * @var string
	 */
	private string $primed_for = '';

	/**
	 * Active language slugs memo.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $lang_slugs = null;

	/**
	 * Whether the feature is on. Memoised: the gate is consulted once per
	 * core/template-part block, and it walks the translatable-types array.
	 *
	 * @var bool|null
	 */
	private ?bool $enabled_memo = null;

	/**
	 * Whether the mapped translations' term rows have been primed this
	 * request. Only used by the opt-in theme re-check.
	 *
	 * @var bool
	 */
	private bool $part_terms_primed = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter     $router    Language router.
	 * @param Settings           $settings  Settings.
	 * @param LanguageRepository $languages Language repository.
	 */
	public function __construct( LanguageRouter $router, Settings $settings, LanguageRepository $languages ) {
		$this->router    = $router;
		$this->settings  = $settings;
		$this->languages = $languages;
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// `get_block_templates` runs in the Site Editor too, and there we want
		// the OPPOSITE behaviour: no content swap, and our translation rows
		// hidden from the list. filter_block_templates() handles both, so it
		// attaches everywhere; only the slug rewrite is front-end only.
		add_filter( 'get_block_templates', [ $this, 'filter_block_templates' ], 99, 3 );

		// ⚠️ THE BLOG-SWITCH RESET MUST BE REGISTERED BEFORE THE ADMIN RETURN.
		//
		// It used to sit below it, which was harmless only for as long as this
		// service did not exist in admin at all (it was registered inside
		// Bootstrap's frontend-only branch). The moment that was corrected, the
		// listing filter above started running in admin with memos that are
		// every one of them BLOG-AFFINE:
		//
		//   enabled_memo — translatable_post_types is a per-blog setting
		//   lang_slugs   — the active language list is per-blog
		//
		// In network admin, priming on an FSE-enabled blog A and then switching
		// to blog B carried A's answers across: on B the filter believed the
		// feature was on and used A's language slugs, so a legitimate template
		// of B's whose slug happened to end in one of A's language suffixes was
		// dropped from B's Site Editor listing. Reproduced: the entry survived
		// with this reset in place, vanished without it, and came back on a
		// manual reset.
		//
		// `get_block_templates` really does fire on ordinary admin screens —
		// measured on wp-admin/edit.php, where WooCommerce's template
		// controller calls it — so this is not a theoretical path.
		add_action( 'switch_blog', [ $this, 'reset_memo' ] );

		if ( is_admin() ) {
			return;
		}

		// REST_REQUEST is not defined yet at registration time, so detach
		// rather than refuse to attach — same reasoning as BlockRefTranslator.
		add_action( 'rest_api_init', [ $this, 'detach' ], 0 );

		add_action( 'perflocale/language/detected', [ $this, 'attach' ], 20 );
		add_action( 'perflocale/language/overridden', [ $this, 'relanguage' ], 20 );
	}

	/**
	 * Attach the template-part slug rewrite.
	 *
	 * @return void
	 */
	public function attach(): void {
		if ( $this->attached ) {
			return;
		}

		$this->attached = true;
		add_filter( 'render_block_data', [ $this, 'rewrite_template_part_slug' ], 10, 1 );
	}

	/**
	 * Detach everything (REST).
	 *
	 * @return void
	 */
	public function detach(): void {
		$this->attached = false;
		remove_filter( 'render_block_data', [ $this, 'rewrite_template_part_slug' ], 10 );
	}

	/**
	 * A rendering window imposed a different language — drop the primed maps.
	 *
	 * @return void
	 */
	public function relanguage(): void {
		$this->reset_memo();
	}

	/**
	 * Forget primed lookups (language override, blog switch).
	 *
	 * @return void
	 */
	public function reset_memo(): void {
		$this->primed     = [
			'wp_template'      => null,
			'wp_template_part' => null,
		];
		$this->primed_for        = '';
		$this->lang_slugs        = null;
		$this->enabled_memo      = null;
		$this->part_terms_primed = false;
	}

	/**
	 * Swap template CONTENT for the current language, and keep our translation
	 * rows out of every listing.
	 *
	 * @param array<int, mixed> $templates     Templates core resolved.
	 * @param array<string, mixed> $query      The query args.
	 * @param string            $template_type 'wp_template' or 'wp_template_part'.
	 * @return array<int, mixed>
	 */
	public function filter_block_templates( $templates, $query, $template_type ) {
		unset( $query );

		if ( ! is_array( $templates ) || $templates === [] ) {
			return $templates;
		}

		// Opt-in. Until an operator marks a template type translatable this
		// filter must be completely inert — no listing changes, no queries.
		// Sites that never enabled the feature were previously paying for it,
		// AND losing any legitimate template whose slug contained the marker.
		if ( ! $this->is_enabled() ) {
			return $templates;
		}

		$lang_slugs = $this->language_slugs();

		// A translation is never itself a template the site can resolve to —
		// it is the German text for one. Drop it from EVERY listing, which is
		// also what keeps it out of the Site Editor (the decision taken for
		// 1.0.5: translations are edited from PerfLocale's Translations screen,
		// not by finding a second "Header" in the Site Editor).
		$visible = [];

		foreach ( $templates as $template ) {
			$slug = is_object( $template ) && isset( $template->slug ) ? (string) $template->slug : '';

			if ( BlockTemplateSupport::is_translation_slug( $slug, $lang_slugs ) ) {
				continue;
			}

			$visible[] = $template;
		}

		// Admin/REST gets the filtered list but never a swap: the Site Editor
		// must always show and save the SOURCE.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $visible;
		}

		$lang = $this->current_language_slug();

		if ( $lang === '' ) {
			return $visible;
		}

		$type = in_array( (string) $template_type, BlockTemplateSupport::TYPES, true )
			? (string) $template_type
			: 'wp_template';

		$map = $this->primed_map( $type, $lang );

		if ( $map === [] ) {
			return $visible;
		}

		$swapped = [];

		foreach ( $visible as $template ) {
			$slug = is_object( $template ) && isset( $template->slug ) ? (string) $template->slug : '';

			if ( $slug === '' || ! isset( $map[ $slug ] ) ) {
				$swapped[] = $template;
				continue;
			}

			$translation = get_post( $map[ $slug ]['id'] );

			// ⚠️ `=== ''` IS NOT AN EMPTINESS TEST. A translation containing
			// only "\n  \n" passed it and rendered a BLANK PAGE — no <main>,
			// no header, no footer. This sits on the happy path: translations
			// are created with empty content by default and the operator must
			// publish the row for the feature to resolve at all.
			//
			// ⚠️ THE STATUS AND TYPE RE-CHECKS ARE NOT REDUNDANT WITH THE MAP.
			// The map is a per-request memo primed by one SELECT that filters on
			// `tr.post_status = 'publish'` and `tr.post_type = %s`. Anything that
			// changes those AFTER priming — an import, a bulk edit, an extension
			// unpublishing a row mid-render, a long render window — would
			// otherwise leave this branch serving a translation that is no
			// longer published. The part branch below re-checks both as well.
			// Both properties are already on the WP_Post that `get_post()` just
			// returned, so this costs two string comparisons and no query.
			// Keep the literals in step with the prime SQL: widening one without
			// the other makes every translated template silently fall back to
			// source, and section D2 of the FSE suite pins that agreement.
			if ( ! $translation instanceof \WP_Post
				|| $translation->post_type !== $type
				|| $translation->post_status !== 'publish'
				|| trim( (string) $translation->post_content ) === ''
			) {
				$swapped[] = $template;
				continue;
			}

			$content = $this->build_template_content( $translation );

			if ( $content === null ) {
				$swapped[] = $template;
				continue;
			}

			// ⭐ CLONE — see the class docblock. Cheap, and it stays correct if
			// core ever caches these or a lower-priority filter holds a
			// reference to the same object.
			$clone          = clone $template;
			$clone->content = $content;

			$swapped[] = $clone;
		}

		return $swapped;
	}

	/**
	 * Point a core/template-part block at the translated part.
	 *
	 * Core looks the part up by `attrs['slug']` in its own WP_Query, so the
	 * only way to serve a translation is to change what it asks for. Fires for
	 * nested blocks too (class-wp-block.php:614), so parts inside parts and
	 * parts inside templates are all covered.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public function rewrite_template_part_slug( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || ( $parsed_block['blockName'] ?? '' ) !== 'core/template-part' ) {
			return $parsed_block;
		}

		$slug = (string) ( $parsed_block['attrs']['slug'] ?? '' );

		if ( $slug === '' || ! $this->is_enabled() ) {
			return $parsed_block;
		}

		// Never rewrite a slug that is already a translation, or the chain
		// compounds into "header-pfl-de-pfl-de".
		if ( BlockTemplateSupport::is_translation_slug( $slug, $this->language_slugs() ) ) {
			return $parsed_block;
		}

		// A part carrying an explicit theme that is not the active one is not
		// ours to redirect; core's own render gates on the same comparison
		// (blocks/template-part.php:27).
		$theme = (string) ( $parsed_block['attrs']['theme'] ?? '' );

		if ( $theme !== '' && $theme !== get_stylesheet() ) {
			return $parsed_block;
		}

		$lang = $this->current_language_slug();

		if ( $lang === '' ) {
			return $parsed_block;
		}

		$map = $this->primed_map( 'wp_template_part', $lang );

		// Fail closed: no PUBLISHED, correctly-termed translation means the
		// block keeps asking for the source. An unresolvable part renders an
		// EMPTY AREA, so a speculative rewrite would produce a blank header.
		if ( ! isset( $map[ $slug ] ) ) {
			return $parsed_block;
		}

		// ⚠️ The map says the row EXISTS; it says nothing about whether the row
		// has anything in it, and core's own bail is `is_null( $content )`
		// (blocks/template-part.php), which '' does not trip. A published but
		// empty translation therefore rendered
		// `<header class="wp-block-template-part"></header>` — the entire
		// header gone — and that is the DEFAULT state of a fresh translation.
		// Re-verify against the post itself, exactly as the template branch
		// does, which also closes any staleness between priming and use.
		$translation = get_post( $map[ $slug ]['id'] );

		// The empty-slug arm closes a latent hole independent of staleness: the
		// prime SQL constrains `src.post_name <> ''` but never `tr.post_name`,
		// so a translation with an empty slug would be written into attrs and
		// resolve to nothing — and core renders '' for an unresolvable part,
		// removing the whole block INCLUDING its wrapper, not merely emptying
		// it (wp-includes/blocks/template-part.php). A blank header is the one
		// failure mode this feature must never produce.
		if ( ! $translation instanceof \WP_Post
			|| $translation->post_type !== 'wp_template_part'
			|| $translation->post_status !== 'publish'
			|| (string) $map[ $slug ]['slug'] === ''
			|| trim( (string) $translation->post_content ) === ''
		) {
			return $parsed_block;
		}

		// ⚠️ THE ONE STALENESS CASE LEFT, AND WHY IT IS OFF BY DEFAULT.
		//
		// The map is primed by a query that requires the translation to carry
		// the active theme's `wp_theme` term. Strip that term AFTER priming —
		// an import, a theme tool, an extension editing terms during a long
		// render — and this branch still rewrites the slug, core's own part
		// query then finds nothing, and the block renders '' (whole wrapper
		// gone). Reproduced; it is a same-request window only.
		//
		// Re-checking it correctly costs a FRESH read: a value carried in the
		// map cannot answer "is the term still there NOW", and the map's own
		// theme column would be useless anyway — the query binds
		// `t.name = get_stylesheet()`, so every row's theme is a constant equal
		// to the active stylesheet by construction.
		//
		// So it is opt-in. Off, this costs one `apply_filters` on a branch that
		// only runs for parts that actually matched the map. On, the whole
		// map's term rows are primed in ONE query and each check is then a
		// cache hit — one extra query per request, not one per part.
		//
		/**
		 * Re-verify a template part translation's `wp_theme` membership at
		 * render time, instead of trusting the primed map.
		 *
		 * Worth enabling where posts and terms are mutated during rendering
		 * (importers, migration tooling, long-running previews). Costs one
		 * extra query per request; leave it off otherwise.
		 *
		 * @hook perflocale/fse/verify_part_theme
		 *
		 * @param bool $verify Default false.
		 */
		if ( apply_filters( 'perflocale/fse/verify_part_theme', false ) ) {
			if ( ! $this->part_still_in_theme( $map, (int) $map[ $slug ]['id'] ) ) {
				return $parsed_block;
			}
		}

		// The translation's OWN post_name, read from the database — never a
		// slug derived here, which could disagree with the row core will find.
		$parsed_block['attrs']['slug'] = $map[ $slug ]['slug'];

		return $parsed_block;
	}

	/**
	 * Whether a template-part translation still carries the active theme's
	 * `wp_theme` term. Only consulted when `perflocale/fse/verify_part_theme`
	 * is enabled — see the call site for why it is not the default.
	 *
	 * Primes the term cache for EVERY translation in the map on first use, so
	 * a page with several translated parts pays one query rather than one per
	 * part. `has_term()` then answers from the object cache.
	 *
	 * @param array<string, array{id: int, slug: string}> $map  The primed map.
	 * @param int                                         $id   Translation post ID.
	 * @return bool
	 */
	private function part_still_in_theme( array $map, int $id ): bool {
		if ( ! $this->part_terms_primed ) {
			$this->part_terms_primed = true;

			$ids = [];

			foreach ( $map as $row ) {
				if ( isset( $row['id'] ) ) {
					$ids[] = (int) $row['id'];
				}
			}

			if ( $ids !== [] ) {
				update_object_term_cache( $ids, 'wp_template_part' );
			}
		}

		return (bool) has_term( get_stylesheet(), 'wp_theme', $id );
	}

	/**
	 * Build a translation's content the way CORE builds a template's content.
	 *
	 * ⭐ NOT the raw `post_content` column. `_build_block_template_result_from_post()`
	 * finishes by running the content through `apply_block_hooks_to_content(…,
	 * 'insert_hooked_blocks_and_set_ignored_hooked_blocks_metadata' )`
	 * (wp-includes/block-template-utils.php). Assigning the raw column skips
	 * the Block Hooks API entirely, so every hooked block silently disappears
	 * on translated templates only.
	 *
	 * That is not hypothetical and it is not someone else's problem: THIS
	 * plugin's own language switcher is auto-inserted after `core/site-title`
	 * through exactly that mechanism, and `switcher_auto_insert` defaults to
	 * true. The raw-column version rendered the switcher on the default
	 * language and dropped it on every other one — the same "works in English,
	 * vanishes in German" shape as the bug that killed the previous
	 * implementation. It generalises to any hooked block from any plugin.
	 *
	 * Returns null when core cannot build the template, in which case the
	 * caller must fall back to the SOURCE rather than serve partial content.
	 *
	 * @param \WP_Post $translation Translation post.
	 * @return string|null
	 */
	private function build_template_content( \WP_Post $translation ): ?string {
		if ( ! function_exists( '_build_block_template_result_from_post' ) ) {
			return null;
		}

		$built = _build_block_template_result_from_post( $translation );

		if ( ! $built instanceof \WP_Block_Template ) {
			return null;
		}

		$content = (string) $built->content;

		return trim( $content ) === '' ? null : $content;
	}

	/**
	 * Is this feature switched on for this site?
	 *
	 * ⚠️ CALLED FROM THE FILTER CALLBACKS, NEVER FROM register_hooks().
	 * `Settings::get_translatable_post_types()` memoises behind
	 * `did_action('plugins_loaded')`, and `register_hooks()` runs during the
	 * eager boot at `init:0` — BEFORE the addon registry (before the addon registry boots inside the same Plugin::boot() loop) has added
	 * its types. Reading the list there would lock in an incomplete answer for
	 * the whole request, which is precisely the bug that once made Contact
	 * Form 7 and WooCommerce products silently untranslatable. Both callers
	 * below run during template resolution, long after init.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( $this->enabled_memo !== null ) {
			return $this->enabled_memo;
		}

		// ⚠️ DELIBERATELY NOT `Settings::get_translatable_post_types()`.
		//
		// That accessor MEMOISES PERMANENTLY for the request the moment
		// `plugins_loaded` has fired (Settings.php:1187-1189). Since this
		// service became registered in plain wp-admin, this gate runs on
		// ordinary admin screens — `WP_Theme::get_post_templates()` and
		// `wp_get_post_content_block_attributes()` both call
		// `get_block_templates()` on a post-edit screen under a block theme —
		// so calling the memoising accessor here would risk freezing a
		// TRUNCATED type list for the whole request if it ever ran before the
		// addon registry binds `perflocale/translatable_post_types`. That is
		// the exact failure that once made Contact Form 7 forms and WooCommerce
		// products silently untranslatable (see Admin\PostListColumns).
		//
		// Measured today the first `get_block_templates()` of a request always
		// lands after the addon filters are bound — but this gate must not
		// DEPEND on that ordering, because nothing enforces it and the cost of
		// not depending on it is zero.
		//
		// The saved setting plus a local filter pass gives the same answer for
		// the only two types this method cares about: `wp_template` and
		// `wp_template_part` are CORE post types written into
		// `translatable_post_types` by the FSE checkbox
		// (SettingsPage::pair_block_template_types()), never contributed by an
		// addon. Applying the filter keeps filter-only enablement working.
		$types = (array) apply_filters(
			'perflocale/translatable_post_types',
			(array) $this->settings->get( 'translatable_post_types', [] )
		);

		$enabled = false;

		foreach ( $types as $type ) {
			if ( BlockTemplateSupport::is_template_type( (string) $type ) ) {
				$enabled = true;
				break;
			}
		}

		$this->enabled_memo = $enabled;

		return $enabled;
	}

	/**
	 * Active language slugs, for the anchored translation-slug test.
	 *
	 * @return array<int, string>
	 */
	private function language_slugs(): array {
		if ( $this->lang_slugs !== null ) {
			return $this->lang_slugs;
		}

		$slugs = [];

		foreach ( $this->languages->get_active() as $lang ) {
			if ( ! empty( $lang->slug ) ) {
				$slugs[] = (string) $lang->slug;
			}
		}

		$this->lang_slugs = $slugs;

		return $slugs;
	}

	/**
	 * Current language slug, or '' when no swap should happen.
	 *
	 * @return string
	 */
	private function current_language_slug(): string {
		$current = $this->router->get_current_language();

		if ( ! is_object( $current ) || empty( $current->slug ) ) {
			return '';
		}

		// The default language IS the source. Nothing to swap, and returning
		// early keeps the whole feature at zero cost on a monolingual request.
		if ( ! empty( $current->is_default ) ) {
			return '';
		}

		return (string) $current->slug;
	}

	/**
	 * slug => post ID for every PUBLISHED translation of $type in $lang.
	 *
	 * One query per post type per request. The `wp_theme` join mirrors core's
	 * own requirement, so a row this map contains is a row core can find.
	 *
	 * @param string $type Post type.
	 * @param string $lang Language slug.
	 * @return array<string, array{id: int, slug: string}> Source slug => the
	 *         translation's own id and post_name. Both are needed: the id reads
	 *         the content, and the post_name is what a template-part block must
	 *         be pointed at — never a slug derived at call time, which could
	 *         disagree with the row core will actually find.
	 */
	private function primed_map( string $type, string $lang ): array {
		// ⚠️ Keyed by STYLESHEET as well as language. The query filters on
		// get_stylesheet(), so a theme switch mid-request (or a `stylesheet`
		// filter) would otherwise keep serving the previous theme's rows.
		$memo_key = get_stylesheet() . '|' . $lang;

		if ( $this->primed_for !== $memo_key ) {
			$this->reset_memo();
			$this->primed_for = $memo_key;
		}

		if ( isset( $this->primed[ $type ] ) && $this->primed[ $type ] !== null ) {
			return $this->primed[ $type ];
		}

		$current = $this->router->get_current_language();
		$default = $this->languages->get_default();

		if ( ! is_object( $current ) || ! is_object( $default ) ) {
			$this->primed[ $type ] = [];

			return [];
		}

		global $wpdb;

		$links = $wpdb->prefix . 'perflocale_translation_links';

		// ⭐ IDENTITY COMES FROM THE TRANSLATION LINK, NOT FROM THE SLUG.
		//
		// This used to find translations with `post_name LIKE '%-pfl-de'`, which
		// meant a row's SLUG was the only thing that made it "the German version
		// of X". Three ways that went wrong, all reproduced:
		//   - delete the source template (Site Editor → "Reset to theme default")
		//     and the translation stayed published with its wp_theme term, so
		//     every non-default language kept serving the old customised markup
		//     FOREVER — and since translations are hidden from the Site Editor
		//     there was no UI to find or remove it;
		//   - a row with NO link rows at all was served as a translation;
		//   - a row linked to language_id 3 (pl) was served on de.
		// Renaming a language slug also silently killed every template
		// translation, and re-using a retired slug served the old language's rows.
		//
		// Joining the group to BOTH the default-language row (the source) and the
		// current-language row makes the relationship the source of truth: an
		// orphan has no source row to join to and simply disappears from the map,
		// which falls back to the source — the safe direction.
		//
		// ⭐ THE SOURCE MUST BELONG TO THE ACTIVE THEME TOO (`src_trel`).
		//
		// Without that join this matched on the TRANSLATION's theme term alone
		// and then keyed the map by the SOURCE's slug — so a translation whose
		// source belongs to a DIFFERENT retained theme was served for whichever
		// template of the ACTIVE theme happened to share that slug. Not exotic:
		// core scopes template-slug uniqueness per theme, and the default slugs
		// (404, archive, home, index, page, search, single, plus header/footer
		// parts) are shared by every block theme — Twenty Twenty-Four and
		// Twenty Twenty-Five collide on all eight. Any site that customised
		// templates under two themes could get the wrong theme's German text.
		//
		// The join, not EXISTS: `(object_id, term_taxonomy_id)` is the PRIMARY
		// KEY of term_relationships, so this is a const-time eq_ref and cannot
		// duplicate rows, whereas an EXISTS semi-join leaves MySQL free to
		// materialise one of the largest tables on a mature install. Same
		// correctness, nothing left to the planner. `trel.term_taxonomy_id` is
		// already equated to `tt.term_taxonomy_id`, which is already pinned to
		// taxonomy `wp_theme` and to the term NAMED get_stylesheet(), so this
		// says exactly "the source is a member of the active theme" — the same
		// predicate core applies in get_block_templates().
		//
		// ⚠️ It also repairs old rows without a migration. A translation
		// created before the writer fix carries the ACTIVE theme while its
		// source carries the foreign one, so the join fails and the entry
		// leaves the map — falling back to the SOURCE, which is the safe
		// direction. Rows whose source has NO wp_theme term drop out too; core
		// itself refuses those (`template_missing_theme`), so matching core is
		// the point rather than a regression.
		//
		// Keyed by the SOURCE's slug, valued with the translation's own id and
		// post_name, so nothing downstream has to DERIVE a slug any more.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT src.post_name AS source_slug, tr.ID AS translation_id, tr.post_name AS translation_slug
				 FROM %i l_tr
				 INNER JOIN %i l_src
				         ON l_src.group_id = l_tr.group_id
				        AND l_src.language_id = %d
				        AND l_src.type = 'post'
				 INNER JOIN {$wpdb->posts} tr  ON tr.ID  = l_tr.object_id
				 INNER JOIN {$wpdb->posts} src ON src.ID = l_src.object_id
				 INNER JOIN {$wpdb->term_relationships} trel ON trel.object_id = tr.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = trel.term_taxonomy_id AND tt.taxonomy = 'wp_theme'
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.name = %s
				 INNER JOIN {$wpdb->term_relationships} src_trel
				         ON src_trel.object_id = src.ID
				        AND src_trel.term_taxonomy_id = trel.term_taxonomy_id
				 WHERE l_tr.language_id = %d
				   AND l_tr.type = 'post'
				   AND tr.post_type = %s
				   AND tr.post_status = 'publish'
				   AND src.post_type = %s
				   AND src.post_name <> ''",
				$links,
				$links,
				(int) $default->id,
				get_stylesheet(),
				(int) $current->id,
				$type,
				$type
			)
		);
		// phpcs:enable

		$map = [];

		foreach ( (array) $rows as $row ) {
			$source_slug = (string) $row->source_slug;

			if ( $source_slug === '' ) {
				continue;
			}

			$map[ $source_slug ] = [
				'id'   => (int) $row->translation_id,
				'slug' => (string) $row->translation_slug,
			];
		}

		$this->primed[ $type ] = $map;

		return $map;
	}
}
