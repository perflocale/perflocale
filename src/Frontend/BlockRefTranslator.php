<?php
/**
 * Per-language synced patterns and block-theme navigation menus.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

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
 * Two core blocks reference another post instead of carrying their own content:
 * `core/block` (a synced pattern) and `core/navigation` (a block-theme menu).
 * Neither is reachable by translating the page that uses them, because the page
 * holds only the reference. This class swaps that reference at render time.
 *
 * A synced pattern is a `wp_block` post. A page that uses one does NOT contain
 * its text — it contains `<!-- wp:block {"ref":123} /-->`, and core loads post
 * 123 at render time. Translating the page therefore cannot reach it: there is
 * nothing in the page to translate. (An *inserted* theme pattern is the
 * opposite case — the inserter copies the markup into `post_content`, so it is
 * ordinary page content and the page translation covers it. The two look
 * identical in the editor, which is why this needs saying twice.)
 *
 * WHERE THE REWRITE HAPPENS, AND WHY THERE
 * `render_block_data` hands us the PARSED block just before render, so changing
 * `attrs['ref']` lets `render_block_core_block()` run its own five checks
 * against the translated post. Filtering the rendered output instead would mean
 * re-implementing all of them. The cost on a page with no synced patterns is one
 * string comparison per block — see rewrite_ref().
 *
 * WHAT CORE DOES WITH A `ref` (wp-includes/blocks/block.php), all of it load-bearing:
 *
 *   - a post whose `post_type` is not `wp_block` renders EMPTY, not fallback;
 *   - a post that is not `publish`, or has a password, renders EMPTY;
 *   - `$seen_refs` is a static recursion guard keyed BY REF, so a rewritten ref
 *     is still guarded and nesting keeps working;
 *   - pattern overrides live in the INSTANCE attributes in the host post, not in
 *     the `wp_block`, so per-instance overrides survive the swap untouched.
 *
 * The first two are why resolve() verifies post type and status itself and
 * returns 0 rather than a bad id: the failure mode we must never ship is a
 * pattern that silently VANISHES from a translated page. Falling back to the
 * source text is always better than rendering nothing.
 *
 * ⚠️ NEVER rewrite in the editor. The block editor must load the original `ref`,
 * or the next save writes the translated id into the source page and the two
 * languages collapse onto one pattern. That is why register_hooks() refuses to
 * attach anything under is_admin(), and why the filters are detached before REST
 * dispatch — the same write-back hazard {@see OptionStrings} exists to contain.
 */
final class BlockRefTranslator {

	/**
	 * Blocks that reference another post by `ref`, mapped to the post type that
	 * reference is required to be.
	 *
	 * Both render EMPTY in core when the ref does not resolve, which is why
	 * translated_ref() verifies the type itself rather than trusting the swap:
	 *
	 *   - `core/block`      → blocks/block.php:26 rejects a non-`wp_block` post
	 *                        outright.
	 *   - `core/navigation` → navigation.php:323 does NOT check the post type at
	 *                        all; it parses whatever post it finds as navigation
	 *                        blocks. A wrong-type ref there yields an empty menu,
	 *                        so the check has to live HERE.
	 *
	 * @var array<string, string>
	 */
	private const BLOCKS = [
		'core/block'      => 'wp_block',
		'core/navigation' => 'wp_navigation',
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
	 * A language-override window may only re-resolve while this is true; after
	 * the REST detach it must not reattach, or a rewritten `ref` could reach the
	 * editor and be saved over the source page.
	 */
	private bool $attached = false;

	/**
	 * Whether the batched warm-up has run for this request.
	 */
	private bool $primed = false;

	/**
	 * Per-request memo of source ref => translated ref (0 = keep the source).
	 *
	 * Blog-keyed: a switch_to_blog() in the middle of a render must not serve
	 * blog A's pattern ids to blog B.
	 *
	 * @var array<string, int>
	 */
	private static array $memo = [];

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
		// ⚠️ Front end only. See the class docblock: a rewritten ref that reaches
		// the editor is written back to the source page on the next save.
		if ( is_admin() ) {
			return;
		}

		// Same reasoning as OptionStrings: REST_REQUEST is not defined yet at
		// registration time, so detach rather than refuse to attach.
		add_action( 'rest_api_init', [ $this, 'detach' ], 0 );

		add_action( 'perflocale/language/detected', [ $this, 'attach' ], 20 );

		// ⚠️ And again when a rendering window imposes a different language.
		// `override_current_language()` (LanguageRouter.php:2373) is the supported
		// way to render part of a request in another language — WooCommerce order
		// emails are the live example. Subscribing only to `language/detected`
		// left $slug frozen at the language the REQUEST arrived in, so a synced
		// pattern inside such a window rendered in the wrong language while the
		// router correctly reported the other one.
		//
		// $attached gates it, so an override can never resurrect the filter after
		// the REST detach or in a context the guards excluded.
		$this->attached = true;

		add_action( 'perflocale/language/overridden', [ $this, 'relanguage' ], 20 );

		add_action( 'switch_blog', [ self::class, 'reset_memo' ] );
	}

	/**
	 * Re-resolve for a language imposed mid-request.
	 *
	 * The memo is deliberately KEPT: its keys already carry the blog, language
	 * and post type, so entries from the previous window cannot be mistaken for
	 * this one's, and a window that switches back reuses them for free.
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

		// Nothing is ever translated into its own language, so the default
		// language pays nothing at all — not even the per-block name compare.
		if ( '' === $current || ( $default && $current === $default->slug ) ) {
			return;
		}

		$this->slug = $current;

		add_filter( 'render_block_data', [ $this, 'rewrite_ref' ], 10, 1 );
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
	}

	/**
	 * Swap a synced pattern's ref for its translation.
	 *
	 * ⚠️ This runs for EVERY block on the page. The blockName comparison is the
	 * first statement for that reason: a page with no synced patterns pays one
	 * string compare per block and touches neither the database nor get_post().
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

		// One batched warm-up the first time a reference is actually seen. A page
		// with no synced pattern or block menu never reaches this line, so the
		// common case still pays nothing but the array lookup above.
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
	 * Public because `UrlConverter::prime_navigation_block_items()` needs the
	 * SAME answer: it runs on `pre_render_block`, which fires BEFORE
	 * `render_block_data`, so without this it would batch-prime the SOURCE
	 * menu's items and then watch the TRANSLATED menu render — one lookup per
	 * item instead of one batched query, on exactly the pages using the feature.
	 *
	 * @param int    $ref       Source post id.
	 * @param string $post_type Post type the translation is required to be.
	 * @return int Translated post id, or 0 to keep the source.
	 */
	public function translated_ref( int $ref, string $post_type ): int {
		if ( $ref <= 0 || '' === $post_type ) {
			return 0;
		}

		$key = get_current_blog_id() . ':' . $this->slug . ':' . $post_type . ':' . $ref;

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		self::$memo[ $key ] = 0;

		if ( '' === $this->slug ) {
			return 0;
		}

		if ( null === $this->manager ) {
			// Constructed directly: there is no container id for this manager
			// and Bootstrap.php:2481 builds it the same way.
			$this->manager = new PostTranslationManager(
				Plugin::get_instance()->get( 'cache' ),
				$this->settings
			);
		}

		$id = (int) ( $this->manager->get_translation_id( $ref, $this->slug ) ?? 0 );

		if ( $id <= 0 || $id === $ref ) {
			return 0;
		}

		$post = get_post( $id );

		// Every one of these three renders EMPTY in core if we let it through,
		// and an empty pattern is worse than an untranslated one.
		if ( ! $post instanceof \WP_Post
			|| $post_type !== $post->post_type
			|| 'publish' !== $post->post_status
			|| '' !== (string) $post->post_password
		) {
			return 0;
		}

		self::$memo[ $key ] = $id;

		return $id;
	}

	/**
	 * Warm the caches every reference on this page will need, in one batch.
	 *
	 * WHY: resolution is per reference, and each one costs a link query plus a
	 * post read. Measured cold on this machine, 25 distinct patterns cost 30
	 * queries; batching the links and priming the post cache costs 3, after
	 * which the per-reference path costs 0. An audit measured the same shape at
	 * scale — 50 distinct patterns added 49 queries on three sites without an
	 * object cache. A warm persistent cache hides most of it, which is exactly
	 * why the sites that lack one are the ones that need this.
	 *
	 * ⚠️ This ONLY warms caches. It does not populate the memo, decide anything,
	 * or change what translated_ref() returns — every guard still runs per
	 * reference against the real post. So a miss (a pattern coming from a
	 * template rather than the post, a ref this regex does not see) simply falls
	 * back to the previous per-reference cost. It cannot produce a wrong answer,
	 * which is the property worth having in a page-render path.
	 *
	 * @return void
	 */
	private function prime_references(): void {
		$this->primed = true;

		$post = get_post( get_queried_object_id() );

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

		// One reference is the case we already handle in one query; batching it
		// would add a round trip rather than remove one.
		if ( count( $refs ) < 2 ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'group_repo' ) ) {
			return;
		}

		$grouped = $plugin->get( 'group_repo' )->get_translations_for_objects( $refs, ObjectType::Post );

		$targets = [];

		foreach ( (array) $grouped as $links ) {
			foreach ( (array) $links as $link ) {
				if ( isset( $link->language_slug ) && $link->language_slug === $this->slug ) {
					$targets[] = (int) $link->object_id;
				}
			}
		}

		if ( [] === $targets ) {
			return;
		}

		// Terms and meta are not read by the resolver, so do not pay for them.
		_prime_post_caches( array_values( array_unique( $targets ) ), false, false );
	}

	/**
	 * Drop the memo across a blog switch.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memo = [];
	}
}
