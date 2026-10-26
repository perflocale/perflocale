<?php
/**
 * Per-language synced patterns and block-theme navigation menus.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points a block's `ref` at its translation while the page renders.
 *
 * `core/block` (synced pattern) and `core/navigation` hold only a reference, so translating the page cannot
 * reach them. Front end only: a rewritten ref that reaches the editor is saved into the source page. Core
 * quirks: docs-ai/11-code-rationale.md, "BlockRefTranslator: what core does with a ref".
 */
final class BlockRefTranslator {

	/**
	 * Blocks that reference another post by `ref`, mapped to the post type that reference must be.
	 *
	 * Checked here: core/navigation renders a post of any type, core/block renders a wrong one empty.
	 *
	 * @var array<string, string>
	 */
	private const BLOCKS = [
		'core/block'      => 'wp_block',
		'core/navigation' => 'wp_navigation',
	];

	/**
	 * The render_callback that stands in for each block type of BLOCKS while a translated page renders.
	 *
	 * @var array<string, string>
	 */
	private const WRAPPERS = [
		'core/block'      => 'render_pattern',
		'core/navigation' => 'render_navigation',
	];

	/**
	 * Router.
	 */
	private LanguageRouter $router;

	/**
	 * Settings.
	 */
	private Settings $settings;

	/**
	 * Resolved once the language is known; '' means "do not rewrite".
	 */
	private string $slug = '';

	/**
	 * Whether this request ever passed the front-end guards.
	 *
	 * Overrides re-resolve only while true: reattaching after the REST detach could save a ref over the source.
	 */
	private bool $attached = false;

	/**
	 * Whether the batched warm-up has run for this request.
	 */
	private bool $primed = false;

	/**
	 * Process-wide, blog-keyed memo of source ref => translated ref (0 = keep the source).
	 *
	 * Positive hits are re-checked against the post; 0 entries and moved links are not, by design;
	 * see docs-ai/11-code-rationale.md, "BlockRefTranslator: ref memo".
	 *
	 * @var array<string, int>
	 */
	private static array $memo = [];

	/**
	 * Every translated id the memo has handed out, keyed like the memo.
	 *
	 * Lets a wrapped render_callback recognise an already swapped ref without resolving it again.
	 *
	 * @var array<string, true>
	 */
	private static array $targets = [];

	/**
	 * The render_callback each block type had before the wrapper replaced it, keyed by block name.
	 *
	 * @var array<string, callable>
	 */
	private static array $core_render = [];

	/**
	 * The block names whose render chain holds the wrapper.
	 *
	 * A name stays set while another callback wraps the wrapper: saving that one as the callback to
	 * call would make the two call each other.
	 *
	 * @var array<string, true>
	 */
	private static array $wrapped = [];

	/**
	 * The instance whose language the wrappers swap in, or null to pass every
	 * block through unchanged.
	 *
	 * @var self|null
	 */
	private static ?self $render_owner = null;

	/**
	 * Translation manager, built on first use only.
	 */
	private ?PostTranslationManager $manager = null;

	/**
	 * @param LanguageRouter $router   Router.
	 * @param Settings       $settings Settings.
	 */
	public function __construct( LanguageRouter $router, Settings $settings ) {
		$this->router   = $router;
		$this->settings = $settings;
	}

	/**
	 * Wire the hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Front end only: a rewritten ref that reaches the editor is saved into the source page.
		if ( is_admin() ) {
			return;
		}

		// REST_REQUEST is not defined yet here, so REST detaches later.
		add_action( 'rest_api_init', [ $this, 'detach' ], 0 );

		add_action( 'perflocale/language/detected', [ $this, 'attach' ], 20 );

		// Re-resolve in language-override windows (WooCommerce emails); $attached keeps REST detached.
		$this->attached = true;

		add_action( 'perflocale/language/overridden', [ $this, 'relanguage' ], 20 );
		add_action( 'perflocale/language/restored', [ $this, 'relanguage' ], 20 );

		add_action( 'switch_blog', [ self::class, 'reset_memo' ] );
	}

	/**
	 * Re-resolve for a language imposed mid-request.
	 *
	 * The memo is kept: its keys carry the blog, language and post type.
	 *
	 * @return void
	 */
	public function relanguage(): void {
		if ( ! $this->attached ) {
			return;
		}

		remove_filter( 'render_block_data', [ $this, 'rewrite_ref' ], 10 );

		$this->slug   = '';
		$this->primed = false;

		$this->attach();
	}

	/**
	 * Attach the render filter, if this request can possibly need it.
	 *
	 * @return void
	 */
	public function attach(): void {
		$default = $this->router->get_default_language();
		$current = (string) $this->router->get_current_slug();

		// The default language pays nothing, not even the per-block name compare.
		if ( '' === $current || ( $default && $current === $default->slug ) ) {
			return;
		}

		$this->slug = $current;

		add_filter( 'render_block_data', [ $this, 'rewrite_ref' ], 10, 1 );

		$this->wrap_render_callbacks();
	}

	/**
	 * Remove the render filter before REST dispatch.
	 *
	 * @return void
	 */
	public function detach(): void {
		remove_filter( 'render_block_data', [ $this, 'rewrite_ref' ], 10 );

		$this->slug   = '';
		$this->primed = false;

		// Also closes the door on relanguage().
		$this->attached = false;

		$this->unwrap_render_callbacks();
	}

	/**
	 * Route the render_callback of each block type in WRAPPERS through its wrapper.
	 *
	 * Top-level blocks of a Navigation overlay part skip `render_block_data`. The registered types are
	 * changed, not `register_block_type_args` filtered, which would run for every block type.
	 *
	 * @return void
	 */
	private function wrap_render_callbacks(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( self::WRAPPERS as $name => $wrapper ) {
			if ( isset( self::$wrapped[ $name ] ) ) {
				continue;
			}

			$type = $registry->get_registered( $name );

			if ( ! $type instanceof \WP_Block_Type || ! $type->is_dynamic() ) {
				continue;
			}

			self::$core_render[ $name ] = $type->render_callback;
			$type->render_callback      = [ self::class, $wrapper ];
			self::$wrapped[ $name ]     = true;
		}

		self::$render_owner = $this;
	}

	/**
	 * Put each wrapped block type's own render_callback back.
	 *
	 * A callback installed over a wrapper stays; the ownerless wrapper beneath it passes blocks through.
	 *
	 * @return void
	 */
	private function unwrap_render_callbacks(): void {
		if ( self::$render_owner !== $this ) {
			return;
		}

		self::$render_owner = null;

		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( self::WRAPPERS as $name => $wrapper ) {
			$type = $registry->get_registered( $name );
			$core = self::$core_render[ $name ] ?? null;

			if ( $type instanceof \WP_Block_Type && $type->render_callback === [ self::class, $wrapper ] && is_callable( $core ) ) {
				$type->render_callback = $core;
				unset( self::$wrapped[ $name ] );
			}
		}
	}

	/**
	 * The render_callback of core/navigation while a translated page renders.
	 *
	 * @see render_wrapped()
	 *
	 * @param mixed  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @param mixed  $block      The WP_Block being rendered.
	 * @return mixed What core's callback returns.
	 */
	public static function render_navigation( $attributes, $content = '', $block = null ) {
		return self::render_wrapped( 'core/navigation', $attributes, $content, $block );
	}

	/**
	 * The render_callback of core/block (a synced pattern) while a translated page renders.
	 *
	 * @see render_wrapped()
	 *
	 * @param mixed  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @param mixed  $block      The WP_Block being rendered.
	 * @return mixed What core's callback returns.
	 */
	public static function render_pattern( $attributes, $content = '', $block = null ) {
		return self::render_wrapped( 'core/block', $attributes, $content, $block );
	}

	/**
	 * Render a wrapped block with its `ref` pointed at this language's translation.
	 *
	 * Only the attributes passed on change; a ref rewrite_ref() already swapped is not swapped twice.
	 *
	 * @param string $name       Block name, a key of WRAPPERS.
	 * @param mixed  $attributes Block attributes.
	 * @param mixed  $content    Block content.
	 * @param mixed  $block      The WP_Block being rendered.
	 * @return mixed What the saved callback returns.
	 */
	private static function render_wrapped( string $name, $attributes, $content, $block ) {
		$render = self::$core_render[ $name ] ?? null;

		if ( ! is_callable( $render ) ) {
			return '';
		}

		if ( null !== self::$render_owner && is_array( $attributes ) ) {
			$attributes = self::$render_owner->swap_ref( $attributes, self::BLOCKS[ $name ] );
		}

		return call_user_func( $render, $attributes, $content, $block );
	}

	/**
	 * The attributes with `ref` pointed at this language's translation.
	 *
	 * @param array<mixed> $attributes Block attributes.
	 * @param string       $post_type  Post type the reference is required to be.
	 * @return array<mixed>
	 */
	private function swap_ref( array $attributes, string $post_type ): array {
		if ( '' === $this->slug ) {
			return $attributes;
		}

		$ref = $attributes['ref'] ?? null;
		$ref = is_int( $ref ) || ( is_string( $ref ) && is_numeric( $ref ) ) ? (int) $ref : 0;

		if ( $ref <= 0 || isset( self::$targets[ $this->memo_key( $post_type, $ref ) ] ) ) {
			return $attributes;
		}

		$translated = $this->translated_ref( $ref, $post_type );

		if ( $translated > 0 ) {
			$attributes['ref'] = $translated;
		}

		return $attributes;
	}

	/**
	 * Memo key for a post id of a post type in this blog and language.
	 *
	 * @param string $post_type Post type.
	 * @param int    $id        Post id.
	 * @return string
	 */
	private function memo_key( string $post_type, int $id ): string {
		return get_current_blog_id() . ':' . $this->slug . ':' . $post_type . ':' . $id;
	}

	/**
	 * Swap a synced pattern's ref for its translation.
	 *
	 * Runs for every block on the page, so the blockName check comes first.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public function rewrite_ref( $parsed_block ) {
		$name = is_array( $parsed_block ) ? ( $parsed_block['blockName'] ?? '' ) : '';

		if ( ! is_string( $name ) || ! isset( self::BLOCKS[ $name ] ) ) {
			return $parsed_block;
		}

		$ref = isset( $parsed_block['attrs']['ref'] ) ? (int) $parsed_block['attrs']['ref'] : 0;

		if ( $ref <= 0 ) {
			return $parsed_block;
		}

		// One batched warm-up when the first reference is seen.
		if ( ! $this->primed ) {
			$this->prime_references();
		}

		$translated = $this->translated_ref( $ref, self::BLOCKS[ $name ] );

		if ( $translated > 0 ) {
			$parsed_block['attrs']['ref'] = $translated;
		}

		return $parsed_block;
	}

	/**
	 * The translated id for a referenced post, or 0 to keep the source.
	 *
	 * Public: UrlConverter primes navigation items on `pre_render_block`, before the swap, and needs the same answer.
	 * A ref naming this language's own unusable member resolves to the default-language one.
	 *
	 * @param int    $ref       Referenced post id.
	 * @param string $post_type Post type the translation is required to be.
	 * @return int Post id to render instead, or 0 to keep the reference.
	 */
	public function translated_ref( int $ref, string $post_type ): int {
		if ( $ref <= 0 || '' === $post_type ) {
			return 0;
		}

		$key = $this->memo_key( $post_type, $ref );

		if ( isset( self::$memo[ $key ] ) ) {
			$memoised = self::$memo[ $key ];

			if ( $memoised <= 0 || self::is_usable_target( get_post( $memoised ), $post_type ) ) {
				return $memoised;
			}

			// The remembered target cannot render: resolve again.
		}

		self::$memo[ $key ] = 0;

		if ( '' === $this->slug ) {
			return 0;
		}

		if ( null === $this->manager ) {
			// Constructed directly: this manager has no container id.
			$this->manager = new PostTranslationManager(
				Plugin::get_instance()->get( 'cache' ),
				$this->settings
			);
		}

		$translations = $this->manager->get_translations( $ref );
		$id           = (int) ( $translations[ $this->slug ] ?? 0 );

		// The ref already names this language's member (TemplateBlockRefs pointed it there); if it
		// cannot render, the default-language member does.
		if ( $id === $ref ) {
			if ( self::is_usable_target( get_post( $ref ), $post_type ) ) {
				return 0;
			}

			$default      = $this->router->get_default_language();
			$default_slug = is_object( $default ) && isset( $default->slug ) && is_string( $default->slug ) ? $default->slug : '';
			$id           = '' !== $default_slug ? (int) ( $translations[ $default_slug ] ?? 0 ) : 0;
		}

		if ( $id <= 0 || $id === $ref ) {
			return 0;
		}

		if ( ! self::is_usable_target( get_post( $id ), $post_type ) ) {
			return 0;
		}

		self::$memo[ $key ] = $id;

		self::$targets[ $this->memo_key( $post_type, $id ) ] = true;

		return $id;
	}

	/**
	 * Whether a reference of this type may be pointed at this post.
	 *
	 * Core never falls back to the source for a post rejected here; it renders a blank or no menu.
	 * Shared by first resolution and memo hits. `trim()`, not `empty()`: whitespace renders blank, "0" does not.
	 *
	 * @param mixed  $post      get_post() result.
	 * @param string $post_type Post type the reference requires.
	 * @return bool
	 */
	private static function is_usable_target( $post, string $post_type ): bool {
		return $post instanceof \WP_Post
			&& $post_type === $post->post_type
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& '' !== trim( (string) $post->post_content );
	}

	/**
	 * Warm the caches every reference on this page will need, in one batch.
	 *
	 * Only warms caches and decides nothing, so a missed ref costs a lookup, never a wrong answer.
	 * Term and author archives are skipped (their queried id is not a post id), but not via is_singular():
	 * queried id 0 needs get_post( 0 )'s global-post fallback.
	 *
	 * @return void
	 */
	private function prime_references(): void {
		$this->primed = true;

		$queried_id = get_queried_object_id();

		if ( $queried_id > 0 && ! get_queried_object() instanceof \WP_Post ) {
			return;
		}

		$post = get_post( $queried_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$content = (string) $post->post_content;

		// Cheap bail before any regex: most pages have no references at all.
		if ( ! str_contains( $content, '"ref":' ) ) {
			return;
		}

		if ( ! preg_match_all( '/"ref"\s*:\s*(\d+)/', $content, $matches ) ) {
			return;
		}

		$refs = array_values( array_unique( array_map( 'intval', $matches[1] ) ) );
		$refs = array_filter( $refs, static fn( int $id ): bool => $id > 0 );

		// One reference costs one query anyway; batching it would add a round trip.
		if ( count( $refs ) < 2 ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'group_repo' ) ) {
			return;
		}

		$repo = $plugin->get( 'group_repo' );

		if ( ! $repo instanceof TranslationGroupRepository ) {
			return;
		}

		// Into the same link caches translated_ref() reads; cached links cost nothing.
		$repo->prime_translations( ObjectType::Post, $refs );

		$targets = [];

		foreach ( $refs as $ref ) {
			foreach ( $repo->get_translations( $ref, ObjectType::Post ) as $link ) {
				if ( isset( $link->language_slug ) && $link->language_slug === $this->slug ) {
					$targets[] = (int) $link->object_id;
				}
			}
		}

		if ( [] === $targets ) {
			return;
		}

		// Meta too (core's block hooks read it); terms are not read.
		_prime_post_caches( array_values( array_unique( $targets ) ), false, true );
	}

	/**
	 * Drop the memo across a blog switch.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memo    = [];
		self::$targets = [];
	}
}
