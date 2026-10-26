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
 * Serves the translated content of a customised block template or template part.
 *
 * Front end only, and never mutates what core hands over: translated content or a rewritten slug that
 * reaches the Site Editor is saved over the original. The template `id` is never touched (it picks the
 * template). Seams: docs-ai/11-code-rationale.md, "BlockTemplateTranslator: seams and safety".
 */
final class BlockTemplateTranslator {

	/**
	 * Parsed-block key marking a template part already looked up, so render_template_part() skips it.
	 *
	 * Never written to a post: the filter runs only while a front-end page renders.
	 */
	private const RESOLVED = 'perflocale_part_resolved';

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
	 * Primed slug => translation maps per post type for the current language; null until primed.
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
	 * Whether the feature is on; memoised because every template-part block asks.
	 *
	 * @var bool|null
	 */
	private ?bool $enabled_memo = null;

	/**
	 * Whether the mapped translations' term rows are primed (opt-in theme re-check only).
	 *
	 * @var bool
	 */
	private bool $part_terms_primed = false;

	/**
	 * Whether core/navigation declares the `overlay` attribute (WordPress 7.0+); null until seen.
	 *
	 * @var bool|null
	 */
	private ?bool $overlay_memo = null;

	/**
	 * The render_callback core/template-part had before the wrapper replaced it.
	 *
	 * @var callable|null
	 */
	private static $core_render = null;

	/**
	 * Whether core/template-part's render chain holds the wrapper.
	 *
	 * Stays set while another callback wraps the wrapper: saving that one as the callback to call
	 * would make the two call each other.
	 *
	 * @var bool
	 */
	private static bool $wrapped = false;

	/**
	 * The instance whose language the wrapper swaps in, or null to pass every
	 * block through unchanged.
	 *
	 * @var self|null
	 */
	private static ?self $render_owner = null;

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
		// Everywhere: in the Site Editor this hides translation rows and swaps no content.
		add_filter( 'get_block_templates', [ $this, 'filter_block_templates' ], 99, 3 );

		// Before the admin return: the listing filter runs in admin too (WooCommerce calls it on
		// edit.php), and its memos are per blog.
		add_action( 'switch_blog', [ $this, 'reset_memo' ] );

		if ( is_admin() ) {
			return;
		}

		// REST_REQUEST is not defined yet here, so REST detaches later.
		add_action( 'rest_api_init', [ $this, 'detach' ], 0 );

		add_action( 'perflocale/language/detected', [ $this, 'attach' ], 20 );
		add_action( 'perflocale/language/overridden', [ $this, 'relanguage' ], 20 );
		add_action( 'perflocale/language/restored', [ $this, 'relanguage' ], 20 );
	}

	/**
	 * Attach the template-part slug and navigation overlay rewrite.
	 *
	 * @return void
	 */
	public function attach(): void {
		if ( $this->attached ) {
			return;
		}

		$this->attached = true;
		add_filter( 'render_block_data', [ $this, 'rewrite_template_part_slug' ], 10, 1 );

		$this->wrap_render_callback();
	}

	/**
	 * Detach everything (REST).
	 *
	 * @return void
	 */
	public function detach(): void {
		$this->attached = false;
		remove_filter( 'render_block_data', [ $this, 'rewrite_template_part_slug' ], 10 );

		$this->unwrap_render_callback();
	}

	/**
	 * Route core/template-part's render_callback through render_template_part().
	 *
	 * Top-level blocks of a Navigation overlay part skip `render_block_data`. The registered type is
	 * changed, not `register_block_type_args` filtered, which would run for every block type.
	 *
	 * @return void
	 */
	private function wrap_render_callback(): void {
		if ( ! self::$wrapped ) {
			$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/template-part' );

			if ( ! $type instanceof \WP_Block_Type || ! $type->is_dynamic() ) {
				return;
			}

			self::$core_render     = $type->render_callback;
			$type->render_callback = [ self::class, 'render_template_part' ];
			self::$wrapped         = true;
		}

		self::$render_owner = $this;
	}

	/**
	 * Put core/template-part's own render_callback back.
	 *
	 * A callback installed over the wrapper stays; the ownerless wrapper beneath it passes blocks through.
	 *
	 * @return void
	 */
	private function unwrap_render_callback(): void {
		if ( self::$render_owner !== $this ) {
			return;
		}

		self::$render_owner = null;

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/template-part' );

		if ( $type instanceof \WP_Block_Type && $type->render_callback === [ self::class, 'render_template_part' ] && is_callable( self::$core_render ) ) {
			$type->render_callback = self::$core_render;
			self::$wrapped         = false;
		}
	}

	/**
	 * The render_callback of core/template-part while a front-end page renders.
	 *
	 * Only the attributes passed on change; blocks marked RESOLVED pass through without a second lookup.
	 *
	 * @param mixed  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @param mixed  $block      The WP_Block being rendered.
	 * @return mixed What the saved callback returns.
	 */
	public static function render_template_part( $attributes, $content = '', $block = null ) {
		$render = self::$core_render;

		if ( ! is_callable( $render ) ) {
			return '';
		}

		$owner    = self::$render_owner;
		$resolved = $block instanceof \WP_Block && ! empty( $block->parsed_block[ self::RESOLVED ] );

		if ( null !== $owner && is_array( $attributes ) && ! $resolved && $owner->current_language_slug() !== '' ) {
			$translated = $owner->part_slug_for( $attributes );

			if ( $translated !== '' ) {
				$attributes['slug'] = $translated;
			}
		}

		return call_user_func( $render, $attributes, $content, $block );
	}

	/**
	 * A rendering window imposed a different language - drop the primed maps.
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
		$this->overlay_memo      = null;
	}

	/**
	 * Swap template content for the current language, and keep translation rows out of every listing.
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

		// Inert until a template type is translatable: no listing changes, no queries.
		if ( ! $this->is_enabled() ) {
			return $templates;
		}

		$lang_slugs = $this->language_slugs();

		// A translation is never a resolvable template; it is edited from PerfLocale's Translations screen.
		$visible = [];

		foreach ( $templates as $template ) {
			$slug = is_object( $template ) && isset( $template->slug ) ? (string) $template->slug : '';

			if ( BlockTemplateSupport::is_translation_slug( $slug, $lang_slugs ) ) {
				continue;
			}

			$visible[] = $template;
		}

		// Never a swap in admin or REST: the Site Editor must show and save the source.
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

			// trim(): whitespace-only content renders a blank page. Type and status are re-checked on the
			// live row (the map is a memo); keep the literals in step with the prime SQL (FSE suite D2).
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

			// Shallow clone suffices: WP_Block_Template has no object properties (suite-pinned).
			$clone          = clone $template;
			$clone->content = $content;

			$swapped[] = $clone;
		}

		return $swapped;
	}

	/**
	 * Point a core/template-part block, or a core/navigation block's overlay, at the translated part.
	 *
	 * Core looks the part up by `attrs['slug']` in its own WP_Query, with no mutating hook on that path.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public function rewrite_template_part_slug( $parsed_block ) {
		if ( ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		$name = $parsed_block['blockName'] ?? '';

		if ( $name === 'core/navigation' ) {
			return $this->rewrite_navigation_overlay( $parsed_block );
		}

		if ( $name !== 'core/template-part' ) {
			return $parsed_block;
		}

		$parsed_block[ self::RESOLVED ] = true;

		if ( ! is_array( $parsed_block['attrs'] ?? null ) ) {
			return $parsed_block;
		}

		$translated = $this->part_slug_for( $parsed_block['attrs'] );

		if ( $translated !== '' ) {
			$parsed_block['attrs']['slug'] = $translated;
		}

		return $parsed_block;
	}

	/**
	 * The slug a core/template-part block should ask for in the current language, or '' to keep its own.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return string
	 */
	private function part_slug_for( array $attrs ): string {
		$slug = $attrs['slug'] ?? '';
		$slug = is_int( $slug ) ? (string) $slug : $slug;

		if ( ! is_string( $slug ) || $slug === '' || ! $this->is_enabled() ) {
			return '';
		}

		// Another theme's part is not ours to redirect (core gates on the same comparison).
		$theme = $attrs['theme'] ?? '';

		if ( ! is_string( $theme ) || ( $theme !== '' && $theme !== get_stylesheet() ) ) {
			return '';
		}

		return $this->translated_part_slug( $slug );
	}

	/**
	 * Point a core/navigation block's `overlay` at the translated part.
	 *
	 * Core (7.0+) queries the overlay part itself from `attrs['overlay']` (`slug` or `theme//slug`).
	 * Another theme's overlay is left alone: core renders nothing for it.
	 *
	 * @param array<string, mixed> $parsed_block Parsed core/navigation block.
	 * @return array<string, mixed>
	 */
	private function rewrite_navigation_overlay( array $parsed_block ): array {
		$attrs   = $parsed_block['attrs'] ?? null;
		$overlay = is_array( $attrs ) ? ( $attrs['overlay'] ?? '' ) : '';

		if ( ! is_string( $overlay ) || $overlay === '' || ! $this->is_enabled() || ! $this->overlay_supported() ) {
			return $parsed_block;
		}

		$prefix = '';
		$slug   = $overlay;
		$parts  = explode( '//', $overlay, 2 );

		if ( count( $parts ) === 2 ) {
			if ( $parts[0] !== get_stylesheet() ) {
				return $parsed_block;
			}

			$prefix = $parts[0] . '//';
			$slug   = $parts[1];
		}

		if ( $slug === '' ) {
			return $parsed_block;
		}

		$translated = $this->translated_part_slug( $slug );

		if ( $translated !== '' ) {
			$attrs['overlay']      = $prefix . $translated;
			$parsed_block['attrs'] = $attrs;
		}

		return $parsed_block;
	}

	/**
	 * Whether core/navigation declares the `overlay` attribute here.
	 *
	 * @return bool
	 */
	private function overlay_supported(): bool {
		if ( $this->overlay_memo === null ) {
			$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/navigation' );

			$this->overlay_memo = $type instanceof \WP_Block_Type
				&& is_array( $type->attributes )
				&& isset( $type->attributes['overlay'] );
		}

		return $this->overlay_memo;
	}

	/**
	 * The post_name of the usable current-language translation of part $slug, or '' to keep the source.
	 *
	 * @param string $slug Source template-part slug.
	 * @return string
	 */
	private function translated_part_slug( string $slug ): string {
		// Never rewrite a translation slug, or it compounds into "header-pfl-de-pfl-de".
		if ( BlockTemplateSupport::is_translation_slug( $slug, $this->language_slugs() ) ) {
			return '';
		}

		$lang = $this->current_language_slug();

		if ( $lang === '' ) {
			return '';
		}

		$map = $this->primed_map( 'wp_template_part', $lang );

		// Fail closed: an unresolvable part renders an empty area, so never rewrite speculatively.
		if ( ! isset( $map[ $slug ] ) ) {
			return '';
		}

		// Type, status, content and slug come from the live row, never the map: an empty or blank-slug
		// translation would render an empty or missing header (the prime SQL never checks tr.post_name).
		$translation = get_post( $map[ $slug ]['id'] );

		if ( ! $translation instanceof \WP_Post
			|| $translation->post_type !== 'wp_template_part'
			|| $translation->post_status !== 'publish'
			|| (string) $translation->post_name === ''
			|| trim( (string) $translation->post_content ) === ''
		) {
			return '';
		}

		// Off by default: only a theme term stripped after priming turns the memo into a blank, and the
		// check costs a fresh query.
		// See docs-ai/11-code-rationale.md, "BlockTemplateTranslator: template-part memo".
		/**
		 * Re-verify a template part translation's `wp_theme` membership at render time.
		 *
		 * For sites that change terms while rendering (importers, migration tooling); one query per request.
		 *
		 * @hook perflocale/fse/verify_part_theme
		 *
		 * @param bool $verify Default false.
		 */
		if ( apply_filters( 'perflocale/fse/verify_part_theme', false ) ) {
			if ( ! $this->part_still_in_theme( $map, (int) $map[ $slug ]['id'] ) ) {
				return '';
			}
		}

		// The live post_name, not the primed one: after a rename core would find no row and render ''.
		return (string) $translation->post_name;
	}

	/**
	 * Whether a template-part translation still carries the active theme's `wp_theme` term.
	 *
	 * Primes the whole map's terms on first use: one query per request, not one per part.
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
	 * Build a translation's content the way core builds a template's content.
	 *
	 * Not the raw column: that skips the Block Hooks API and drops hooked blocks (the language switcher
	 * among them). Null means the caller falls back to the source.
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
	 * Called from the filter callbacks, never from register_hooks(): that runs before add-ons add types.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( $this->enabled_memo !== null ) {
			return $this->enabled_memo;
		}

		// Not Settings::get_translatable_post_types(): it memoises for the request and may run here
		// before add-ons bind their types. The template types come from the saved setting anyway.
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

		// The default language is the source: nothing to swap, at zero cost.
		if ( ! empty( $current->is_default ) ) {
			return '';
		}

		return (string) $current->slug;
	}

	/**
	 * Source slug => published translation of $type in $lang, one query per type per request.
	 *
	 * A per-request memo; callers read type, status, content and slug from the live row.
	 *
	 * @param string $type Post type.
	 * @param string $lang Language slug.
	 * @return array<string, array{id: int, slug: string}> Source slug => the translation's id and post_name.
	 */
	private function primed_map( string $type, string $lang ): array {
		// Keyed by stylesheet too: the query filters on it, and it can change mid-request.
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

		// Identity from the translation link, never the slug; source and translation both in the active
		// theme (src_trel, a primary-key join, not EXISTS).
		// See docs-ai/11-code-rationale.md, "BlockTemplateTranslator: prime query".
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
