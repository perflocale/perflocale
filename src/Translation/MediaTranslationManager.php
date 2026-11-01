<?php
/**
 * Media/attachment translation manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-language alt text, captions, descriptions and featured images, stored in language-suffixed post meta.
 */
final class MediaTranslationManager {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Post meta prefix for per-language featured-image overrides: `_perflocale_thumbnail_{lang_slug}` => attachment ID.
	 */
	public const THUMBNAIL_OVERRIDE_PREFIX = '_perflocale_thumbnail_';

	/**
	 * Recursion guard: the `get_post_metadata` filter reads the override through get_post_meta().
	 *
	 * @var bool
	 */
	private static bool $resolving_thumbnail = false;

	/**
	 * Whether `maybe_register_hooks()` ran for this blog; reset on `switch_blog`, where the language count differs.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Per-request memo of "is the current language the default?"; reset on switch_blog, where it differs.
	 *
	 * @var bool|null
	 */
	private static ?bool $is_default_lang_memo = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param CacheManager   $cache Cache manager.
	 */
	public function __construct( LanguageRouter $router, CacheManager $cache ) {
		$this->router = $router;
		$this->cache  = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// No hooks with fewer than two languages; counted at `init`, when the language tables are ready.
		add_action( 'init', [ $this, 'maybe_register_hooks' ], 20 );
	}

	/**
	 * Reset the per-blog statics on `switch_blog` (Bootstrap's reset registry).
	 *
	 * @return void
	 */
	public static function reset_registered(): void {
		self::$hooks_registered     = false;
		self::$is_default_lang_memo = null;
	}

	/**
	 * Register the media-translation hooks when the site has at least two active languages.
	 *
	 * @return void
	 */
	public function maybe_register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		// The languages list is cached.
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return;
		}

		// Frontend: filter attachment data for current language.
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_image_alt' ], 10, 2 );
		add_filter( 'wp_get_attachment_caption', [ $this, 'filter_caption' ], 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'filter_attachment_js' ], 10, 3 );

		// Per-language description on attachment pages (priority 8, before wpautop) and in REST view contexts.
		add_filter( 'the_content', [ $this, 'filter_attachment_page_description' ], 8 );
		add_filter( 'rest_prepare_attachment', [ $this, 'filter_rest_attachment' ], 10, 3 );

		// Admin: add language fields to attachment edit screen.
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_translation_fields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ $this, 'save_translation_fields' ], 10, 2 );

		// Per-language featured image at `get_post_metadata`, so every caller sees the swapped ID.
		add_filter( 'get_post_metadata', [ $this, 'filter_thumbnail_id' ], 10, 4 );

		// Admin metabox on the post edit screen for translatable types.
		add_action( 'add_meta_boxes', [ $this, 'add_thumbnail_translation_metabox' ] );
		add_action( 'save_post', [ $this, 'save_thumbnail_translations' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_picker_on_post_edit' ] );

		// Deleting an image retracts our overrides, as core does for `_thumbnail_id`.
		add_action( 'delete_attachment', [ $this, 'prune_thumbnail_overrides' ] );
	}

	/**
	 * Filter image alt text for current language.
	 *
	 * @param array<string, string> $attr       Image attributes.
	 * @param mixed                 $attachment Attachment post; may be null (unchecked get_post() in core), so not typed.
	 * @return array<string, string>
	 */
	public function filter_image_alt( array $attr, mixed $attachment ): array {
		// Return $attr unchanged, never a rebuilt copy: core prints it on the <img>.
		if ( ! $attachment instanceof \WP_Post ) {
			return $attr;
		}

		if ( is_admin() ) {
			return $attr;
		}

		$lang_slug = $this->router->get_current_slug();

		if ( $lang_slug === '' ) {
			return $attr;
		}

		$translated_alt = get_post_meta( $attachment->ID, '_perflocale_alt_' . $lang_slug, true );

		if ( $translated_alt !== '' ) {
			$attr['alt'] = $translated_alt;
		}

		return $attr;
	}

	/**
	 * Serve the per-language Description on the attachment page itself.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public function filter_attachment_page_description( $content ) {
		if ( is_admin() || ! is_string( $content ) || ! is_attachment() || ! in_the_loop() ) {
			return $content;
		}

		$lang_slug = $this->router->get_current_slug();

		if ( $lang_slug === '' ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
			return $content;
		}

		// Escape late, whatever wrote the row; esc_html() keeps newlines, so wpautop still builds paragraphs.
		$translated = (string) get_post_meta( $post->ID, '_perflocale_description_' . $lang_slug, true );

		return $translated !== '' ? esc_html( $translated ) : $content;
	}

	/**
	 * Filter attachment caption for current language.
	 *
	 * @param string $caption Original caption.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_caption( string $caption, int $attachment_id ): string {
		if ( is_admin() ) {
			return $caption;
		}

		$lang_slug = $this->router->get_current_slug();

		if ( $lang_slug === '' ) {
			return $caption;
		}

		$translated = get_post_meta( $attachment_id, '_perflocale_caption_' . $lang_slug, true );

		// Escape late: the_post_thumbnail_caption() echoes this unescaped.
		return $translated !== '' ? esc_html( (string) $translated ) : $caption;
	}

	/**
	 * Swap front-end `_thumbnail_id` reads for the current language's override when one exists.
	 *
	 * @param mixed  $value Default null (allow WP to fetch normally).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key being fetched.
	 * @param bool   $single Whether a single value is requested.
	 * @return mixed
	 */
	public function filter_thumbnail_id( mixed $value, int $object_id, string $meta_key, bool $single ): mixed {
		if ( $meta_key !== '_thumbnail_id' || is_admin() || self::$resolving_thumbnail ) {
			return $value;
		}

		// Hot path: the default language has no override, memoised per request.
		if ( self::$is_default_lang_memo === null ) {
			$current = $this->router->get_current_language();
			$default = $this->router->get_default_language();

			self::$is_default_lang_memo = (
				$current !== null
				&& $default !== null
				&& isset( $current->slug, $default->slug )
				&& $current->slug === $default->slug
			);
		}

		if ( self::$is_default_lang_memo ) {
			return $value;
		}

		$lang_slug = $this->router->get_current_slug();

		if ( $lang_slug === '' ) {
			return $value;
		}

		// get_post_meta() re-enters this filter; finally so a throwing callback cannot strand the guard.
		self::$resolving_thumbnail = true;
		try {
			$override_id = (int) get_post_meta( $object_id, self::THUMBNAIL_OVERRIDE_PREFIX . $lang_slug, true );
		} finally {
			self::$resolving_thumbnail = false;
		}

		if ( $override_id <= 0 ) {
			return $value;
		}

		// The short-circuit shape depends on $single.
		return $single ? (string) $override_id : [ (string) $override_id ];
	}

	// Cold paths: one-line forwarders keep each hook's [ $this, method ] callback; the bodies load on first call.

	/**
	 * Per-language caption/description in REST view payloads.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $post     Attachment post.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function filter_rest_attachment( $response, $post, $request = null ) {
		return MediaTranslationAdmin::filter_rest_attachment( $this->router, $response, $post, $request );
	}

	/**
	 * Per-language alt/caption/description in the front-end media modal.
	 *
	 * `$meta` stays untyped: core passes false when there is no metadata, and a type error empties the library.
	 *
	 * @param array<string, mixed> $response   Attachment data.
	 * @param \WP_Post             $attachment Attachment post.
	 * @param mixed                $meta       Attachment metadata, or false.
	 * @return array<string, mixed>
	 */
	public function filter_attachment_js( array $response, \WP_Post $attachment, mixed $meta = null ): array {
		return MediaTranslationAdmin::filter_attachment_js( $this->router, $response, $attachment );
	}

	/**
	 * Per-language fields on the attachment edit form.
	 *
	 * @param array<string, mixed> $form_fields Form fields.
	 * @param \WP_Post             $post        Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_translation_fields( array $form_fields, \WP_Post $post ): array {
		return MediaTranslationAdmin::add_translation_fields( $form_fields, $post );
	}

	/**
	 * Save the attachment form's per-language fields.
	 *
	 * @param array<string, mixed> $post       Post data.
	 * @param mixed                $attachment Attachment field data.
	 * @return array<string, mixed>
	 */
	public function save_translation_fields( array $post, mixed $attachment ): array {
		return MediaTranslationAdmin::save_translation_fields( $post, $attachment );
	}

	/**
	 * Register the per-language featured-image metabox.
	 *
	 * @param string $active_post_type Screen post type.
	 * @param mixed  $post             The post, or null.
	 * @return void
	 */
	public function add_thumbnail_translation_metabox( string $active_post_type = '', $post = null ): void {
		MediaTranslationAdmin::add_thumbnail_translation_metabox( $this, $active_post_type, $post );
	}

	/**
	 * Render the per-language featured-image metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_thumbnail_translation_metabox( \WP_Post $post ): void {
		MediaTranslationAdmin::render_thumbnail_translation_metabox( $post );
	}

	/**
	 * Save the metabox's per-language featured images.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object, or null.
	 * @return void
	 */
	public function save_thumbnail_translations( int $post_id, ?\WP_Post $post = null ): void {
		// Only the metabox form posts the nonce; other saves (an oEmbed cache post in a page view) skip the class.
		if ( isset( $_POST['perflocale_thumbnail_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the body.
			MediaTranslationAdmin::save_thumbnail_translations( $post_id, $post );
		}
	}

	/**
	 * Retract overrides that point at a deleted attachment.
	 *
	 * @param mixed $attachment_id Attachment ID as core passed it.
	 * @return void
	 */
	public function prune_thumbnail_overrides( mixed $attachment_id = 0 ): void {
		MediaTranslationAdmin::prune_thumbnail_overrides( $attachment_id );
	}

	/**
	 * Media picker for the metabox on post-edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_media_picker_on_post_edit( string $hook ): void {
		MediaTranslationAdmin::enqueue_media_picker_on_post_edit( $hook );
	}
}
