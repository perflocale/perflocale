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
 * Manages per-language alt text, captions, descriptions, and alternate images.
 *
 * Stores translated media metadata in post meta with language-suffixed keys.
 * On the frontend, filters attachment data to return the current language version.
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
	 * Post meta prefix for per-language featured-image overrides.
	 *
	 * A post may have an override per active language; when set the
	 * frontend swaps `_thumbnail_id` transparently. Absence of the
	 * override meta means the default `_thumbnail_id` applies.
	 *
	 * Key format: `_perflocale_thumbnail_{lang_slug}`, value: attachment ID.
	 */
	private const THUMBNAIL_OVERRIDE_PREFIX = '_perflocale_thumbnail_';

	/**
	 * Recursion guard for the `get_post_metadata` filter - prevents a
	 * stack overflow when our filter calls get_post_meta() internally
	 * to look up the override.
	 *
	 * @var bool
	 */
	private static bool $resolving_thumbnail = false;

	/**
	 * Whether `maybe_register_hooks()` has already run for the current
	 * blog context. Promoted from a function-local static so it can be
	 * reset on multisite `switch_blog`, since the language count may
	 * differ between blogs and we need to re-evaluate registration.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Per-request memo of "is the current language the default?", used by the
	 * hot filter_thumbnail_id() path. A class static (not a function-static)
	 * so reset_registered() can clear it on switch_blog — the current language
	 * differs per blog, so a stale value would mis-route thumbnail IDs.
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
		// Fast short-circuit: media translation only makes sense when at
		// least two languages are active. On a single-language install
		// we skip every filter + action registration so there's literally
		// zero overhead on any request type.
		//
		// Defer the count check to `init` - at plugin-load time, language
		// tables may not be ready yet (fresh install, migrations running).
		add_action( 'init', [ $this, 'maybe_register_hooks' ], 20 );
	}

	/**
	 * Reset the hooks-registered flag on multisite blog switches.
	 *
	 * Wired to `switch_blog` from Bootstrap alongside the other static
	 * resets (SchemaEnricher, PostQueryFilter, etc.). Without this, the
	 * static flag from Blog A would suppress re-evaluation for Blog B
	 * whose language count may differ.
	 *
	 * @return void
	 */
	public static function reset_registered(): void {
		self::$hooks_registered     = false;
		self::$is_default_lang_memo = null;
	}

	/**
	 * Register the actual media-translation hooks only when the site has
	 * ≥2 active languages. Called once on `init` priority 20.
	 *
	 * @return void
	 */
	public function maybe_register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		// Cheap check: the languages list is 3-layer cached so this is
		// one cache hit on most requests.
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return;
		}

		// Frontend: filter attachment data for current language.
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_image_alt' ], 10, 2 );
		add_filter( 'wp_get_attachment_caption', [ $this, 'filter_caption' ], 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'filter_attachment_js' ], 10, 3 );

		// The per-language Description the media edit form solicits was
		// write-without-read: no visitor surface ever served it. Attachment
		// pages render the attachment's post_content — swap it for the
		// current-language description there (priority 8, before wpautop),
		// and mirror it into REST media payloads for view contexts.
		add_filter( 'the_content', [ $this, 'filter_attachment_page_description' ], 8 );
		add_filter( 'rest_prepare_attachment', [ $this, 'filter_rest_attachment' ], 10, 3 );

		// Admin: add language fields to attachment edit screen.
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_translation_fields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ $this, 'save_translation_fields' ], 10, 2 );

		// Per-language featured image: swap the thumbnail ID transparently
		// when an override exists for the current language. Done at the
		// lowest level (`get_post_metadata`) so every caller - core,
		// themes, plugins, REST, blocks - sees the correct ID without any
		// per-integration work.
		add_filter( 'get_post_metadata', [ $this, 'filter_thumbnail_id' ], 10, 4 );

		// Admin metabox on the post edit screen for translatable types.
		add_action( 'add_meta_boxes', [ $this, 'add_thumbnail_translation_metabox' ] );
		add_action( 'save_post', [ $this, 'save_thumbnail_translations' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_picker_on_post_edit' ] );

		// Deleting an image must retract every featured-image assignment that
		// pointed at it. Core does exactly that for its own key two lines after
		// this action fires, with a delete_metadata() call on `_thumbnail_id`.
		// Our overrides are a parallel set of the same assignment and need the
		// same retraction — see prune_thumbnail_overrides() for what a leftover
		// row does to the front end.
		add_action( 'delete_attachment', [ $this, 'prune_thumbnail_overrides' ] );
	}

	/**
	 * Filter image alt text for current language.
	 *
	 * @param array<string, string> $attr       Image attributes.
	 * @param mixed                 $attachment Attachment post — NOT guaranteed to be a
	 *                                          WP_Post. Core builds it with an unchecked
	 *                                          `get_post( $attachment_id )` at
	 *                                          wp-includes/media.php:1176 and never
	 *                                          dereferences it before handing it to this
	 *                                          filter at :1282, so a null reaches us
	 *                                          whenever the documented `image_downsize`
	 *                                          preempt filter satisfies the entry gate for
	 *                                          an id with no post row. Typing this
	 *                                          `\WP_Post` threw at ARGUMENT BINDING — a
	 *                                          white screen on any page rendering that
	 *                                          image, before the guards below could run.
	 * @return array<string, string>
	 */
	public function filter_image_alt( array $attr, mixed $attachment ): array {
		// Return $attr UNCHANGED, never a rebuilt copy: this value becomes the
		// attributes core prints on the <img>.
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
	 * Attachment templates print the attachment's post_content through
	 * the_content; when a translation exists for the current language it
	 * replaces the source description (priority 8 keeps wpautop and the
	 * rest of the default filter chain applying afterwards).
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

		// Written only by save_translation_fields(), which runs the value
		// through sanitize_textarea_field(). Escaped again on the way out
		// (escape late) so this filter can never emit markup regardless of what
		// wrote the row — the same value is esc_html()'d on the REST branch.
		// esc_html() does not double-encode existing entities and leaves
		// newlines intact, so wpautop at priority 10 still builds paragraphs.
		$translated = (string) get_post_meta( $post->ID, '_perflocale_description_' . $lang_slug, true );

		return $translated !== '' ? esc_html( $translated ) : $content;
	}

	/**
	 * Mirror the per-language caption/description into REST media payloads.
	 *
	 * View contexts only — the editor (context=edit) must keep the raw
	 * source values it writes back.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $post     Attachment post.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function filter_rest_attachment( $response, $post, $request = null ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}

		if ( $request instanceof \WP_REST_Request && $request->get_param( 'context' ) === 'edit' ) {
			return $response;
		}

		$lang_slug = $this->router->get_current_slug();

		if ( $lang_slug === '' ) {
			return $response;
		}

		$data = $response->get_data();

		$caption = (string) get_post_meta( $post->ID, '_perflocale_caption_' . $lang_slug, true );

		if ( $caption !== '' && isset( $data['caption']['rendered'] ) ) {
			$data['caption']['rendered'] = wpautop( esc_html( $caption ) );
		}

		$description = (string) get_post_meta( $post->ID, '_perflocale_description_' . $lang_slug, true );

		if ( $description !== '' && isset( $data['description']['rendered'] ) ) {
			$data['description']['rendered'] = wpautop( esc_html( $description ) );
		}

		$response->set_data( $data );

		return $response;
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

		// Escape late — core echoes this filter's return value unescaped in
		// the_post_thumbnail_caption(), and the same meta is esc_html()'d on
		// the REST branch below.
		return $translated !== '' ? esc_html( (string) $translated ) : $caption;
	}

	/**
	 * Filter attachment data for JS (media library modal).
	 *
	 * `$meta` is deliberately untyped. `wp_prepare_attachment_for_js()` passes
	 * the return of `wp_get_attachment_metadata()` straight through, and that
	 * is `false` whenever the attachment has no usable `_wp_attachment_metadata`
	 * row — an attachment created by `wp_insert_attachment()` alone (what most
	 * importers and sideloaders do), or a document whose file was already gone
	 * when metadata generation ran, which stores an empty array that
	 * `wp_get_attachment_metadata()` reports as `false`. An `array` hint made
	 * PHP throw a TypeError at ARGUMENT BINDING, before this method's own body
	 * could guard anything, so `admin-ajax.php?action=query-attachments`
	 * returned a 500 and the media library — the Media page, the block editor's
	 * image block, and this plugin's own per-language featured-image picker —
	 * rendered zero attachments for the whole site. The value is never read
	 * here; it is accepted and ignored so the filter is transparent whatever
	 * core hands it.
	 *
	 * @param array<string, mixed> $response Attachment data.
	 * @param \WP_Post             $attachment Attachment post.
	 * @param mixed                $meta Attachment metadata as core resolved it: an array, or false when there is none.
	 * @return array<string, mixed>
	 */
	public function filter_attachment_js( array $response, \WP_Post $attachment, mixed $meta = null ): array {
		if ( ! is_admin() ) {
			$lang_slug = $this->router->get_current_slug();

			if ( $lang_slug !== '' ) {
				$alt = get_post_meta( $attachment->ID, '_perflocale_alt_' . $lang_slug, true );

				if ( $alt !== '' ) {
					$response['alt'] = $alt;
				}

				$caption = get_post_meta( $attachment->ID, '_perflocale_caption_' . $lang_slug, true );

				if ( $caption !== '' ) {
					$response['caption'] = $caption;
				}

				$desc = get_post_meta( $attachment->ID, '_perflocale_description_' . $lang_slug, true );

				if ( $desc !== '' ) {
					$response['description'] = $desc;
				}
			}
		}

		return $response;
	}

	/**
	 * Add translation fields to the attachment edit form.
	 *
	 * @param array<string, mixed> $form_fields Form fields.
	 * @param \WP_Post             $post Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_translation_fields( array $form_fields, \WP_Post $post ): array {
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();
		$default   = $lang_repo->get_default();

		foreach ( $languages as $lang ) {
			if ( $default && $lang->slug === $default->slug ) {
				continue;
			}

			$slug  = $lang->slug;
			$label = strtoupper( $slug );

			// Alt text per language.
			$form_fields[ 'perflocale_alt_' . $slug ] = [
				/* translators: %s: Language code */
				'label' => sprintf( __( 'Alt Text (%s)', 'perflocale' ), $label ),
				'input' => 'text',
				'value' => get_post_meta( $post->ID, '_perflocale_alt_' . $slug, true ),
				/* translators: %s: Language name */
				'helps' => sprintf( __( 'Alternative text for %s', 'perflocale' ), $lang->name ),
			];

			// Caption per language.
			$form_fields[ 'perflocale_caption_' . $slug ] = [
				/* translators: %s: Language code */
				'label' => sprintf( __( 'Caption (%s)', 'perflocale' ), $label ),
				'input' => 'text',
				'value' => get_post_meta( $post->ID, '_perflocale_caption_' . $slug, true ),
			];

			// Description per language.
			$form_fields[ 'perflocale_desc_' . $slug ] = [
				/* translators: %s: Language code */
				'label' => sprintf( __( 'Description (%s)', 'perflocale' ), $label ),
				'input' => 'textarea',
				'value' => get_post_meta( $post->ID, '_perflocale_description_' . $slug, true ),
			];
		}

		return $form_fields;
	}

	/**
	 * Save translation fields when attachment is saved.
	 *
	 * `$attachment` is NOT guaranteed to be an array. All three core call
	 * sites hand this filter a raw, unvalidated slice of the request:
	 *
	 *   - wp-admin/includes/media.php     `$_POST['attachments'][ $id ]`
	 *   - wp-admin/includes/ajax-actions.php `$_REQUEST['attachments'][ $id ]`
	 *   - wp-admin/includes/post.php      `$post_data['attachments'][ $id ] ?? array()`
	 *
	 * and none of them checks the element's type — `empty()` is the only
	 * gate. A request that posts `attachments[123]=foo` therefore arrives
	 * here as a string, which an `array` type declaration rejects at
	 * argument binding, before any guard in the body can run: an uncaught
	 * TypeError out of wp_ajax_save_attachment_compat() / edit_post(), i.e.
	 * a 500 on saving media. Accept whatever the caller has and return
	 * `$post` UNCHANGED when there are no fields to read — never a coerced
	 * copy, since this filter's return value IS the post data core then
	 * writes.
	 *
	 * @param array<string, mixed> $post Post data.
	 * @param mixed                $attachment Attachment field data as core passed it;
	 *                                         an array in the UI flow, but any scalar
	 *                                         a malformed request supplied.
	 * @return array<string, mixed>
	 */
	public function save_translation_fields( array $post, mixed $attachment ): array {
		if ( ! is_array( $attachment ) || ! isset( $post['ID'] ) ) {
			return $post;
		}

		$post_id = (int) $post['ID'];

		if ( $post_id <= 0 ) {
			return $post;
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		foreach ( $languages as $lang ) {
			$slug = $lang->slug;

			if ( isset( $attachment[ 'perflocale_alt_' . $slug ] ) ) {
				update_post_meta( $post_id, '_perflocale_alt_' . $slug, sanitize_text_field( $attachment[ 'perflocale_alt_' . $slug ] ) );
			}

			if ( isset( $attachment[ 'perflocale_caption_' . $slug ] ) ) {
				update_post_meta( $post_id, '_perflocale_caption_' . $slug, sanitize_text_field( $attachment[ 'perflocale_caption_' . $slug ] ) );
			}

			if ( isset( $attachment[ 'perflocale_desc_' . $slug ] ) ) {
				update_post_meta( $post_id, '_perflocale_description_' . $slug, sanitize_textarea_field( $attachment[ 'perflocale_desc_' . $slug ] ) );
			}
		}

		return $post;
	}

	// -------------------------------------------------------------------------
	// Per-language featured image overrides.
	// -------------------------------------------------------------------------

	/**
	 * Intercept `_thumbnail_id` reads on the frontend and swap in the
	 * language-specific override when one exists.
	 *
	 * Runs at the `get_post_metadata` level so every consumer of
	 * get_post_thumbnail_id() - core, themes, blocks, REST, schema
	 * addons - gets the translated ID without additional integration.
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

		// Per-request gate: on the default language there's no override to
		// swap in (the stored `_thumbnail_id` IS the default-language one).
		// Cached once per request so the hot path short-circuits in ~1µs
		// instead of running the get_post_meta round-trip below. get_post_meta
		// fires 50+ times per archive page, so even with the meta_key check
		// filtering most out, skipping the rest here saves another ~100µs.
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

		// Recursion guard - get_post_meta() below will re-enter this filter.
		// try/finally so a throwing get_post_metadata filter callback (any
		// other plugin on that hook) can't strand the guard at true and break
		// thumbnail overrides for the rest of the request.
		self::$resolving_thumbnail = true;
		try {
			$override_id = (int) get_post_meta( $object_id, self::THUMBNAIL_OVERRIDE_PREFIX . $lang_slug, true );
		} finally {
			self::$resolving_thumbnail = false;
		}

		if ( $override_id <= 0 ) {
			return $value;
		}

		// WordPress expects either a scalar or an array of values for the
		// `get_post_metadata` short-circuit - shape depends on $single.
		return $single ? (string) $override_id : [ (string) $override_id ];
	}

	/**
	 * Register the per-language featured-image metabox on every
	 * translatable post type.
	 *
	 * Only shown when at least 2 active languages exist - a single-language
	 * site has no use for overrides.
	 *
	 * Both the post type AND the active theme must support post-thumbnails.
	 * WP core hides its own Featured Image box when either is missing, and
	 * we mirror the same guard so we don't end up exposing an "override the
	 * featured image" panel on a theme/post-type combo where no featured
	 * image will ever render.
	 *
	 * @return void
	 */
	public function add_thumbnail_translation_metabox( string $active_post_type = '', $post = null ): void {
		unset( $active_post_type ); // WP-supplied hint about which screen this fires for; we iterate every translatable type so the registration is screen-scoped by add_meta_box itself.

		$plugin    = \PerfLocale\Plugin::get_instance();
		$settings  = $plugin->get( 'settings' );
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return;
		}

		// Resolve the WP_Post that this add_meta_boxes call is for. WP calls
		// the hook once per request with the current edit-screen post; if
		// the caller didn't pass one (older WP or programmatic invocation),
		// fall back to the global.
		$current_post = $post instanceof \WP_Post ? $post : null;

		if ( ! $current_post && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$current_post = $GLOBALS['post'];
		}

		foreach ( $settings->get_translatable_post_types() as $type ) {
			if ( ! post_type_supports( $type, 'thumbnail' ) ) {
				continue;
			}

			// `current_theme_supports( 'post-thumbnails', $post_type )` accepts
			// the post type so themes that opt-in to a subset (e.g.
			// `add_theme_support( 'post-thumbnails', [ 'post', 'page' ] )`)
			// still get a correct answer per type.
			if ( ! current_theme_supports( 'post-thumbnails', $type ) ) {
				continue;
			}

			/**
			 * Filter whether the per-language Featured Image panel should
			 * be registered for a given post type.
			 *
			 * Returning false suppresses the metabox without disabling the
			 * frontend swap (so existing overrides keep working). Useful for
			 * themes or addons that render their own featured-image surface
			 * and want to host the language overrides inside that UI instead.
			 *
			 * @hook perflocale/media/show_featured_image_panel
			 * @param bool   $show      Default: true.
			 * @param string $post_type Post type slug being evaluated.
			 */
			if ( ! apply_filters( 'perflocale/media/show_featured_image_panel', true, $type ) ) {
				continue;
			}

			// Suppress the metabox entirely when there's nothing meaningful to
			// render for this post — a registered box with an "every language
			// already translated" placeholder just steals visual real estate.
			// The placeholder stays a fallback (resolve_panel_languages()
			// returning [] mid-render).
			if ( $current_post instanceof \WP_Post && $current_post->post_type === $type ) {
				$panel_langs = $this->resolve_panel_languages( (int) $current_post->ID, $languages );

				if ( $panel_langs === [] ) {
					continue;
				}
			}

			add_meta_box(
				'perflocale-featured-image-translations',
				__( 'Featured Image per Language', 'perflocale' ),
				[ $this, 'render_thumbnail_translation_metabox' ],
				$type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the per-language featured-image overrides metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_thumbnail_translation_metabox( \WP_Post $post ): void {
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $this->resolve_panel_languages( $post->ID, $lang_repo->get_active() );

		wp_nonce_field( 'perflocale_thumbnail_translations', 'perflocale_thumbnail_nonce' );

		if ( $languages === [] ) {
			?>
			<p class="description" style="margin-top:0;">
				<?php echo esc_html__( 'Every language already has its own translation of this post — each translation manages its own featured image. No per-language override needed here.', 'perflocale' ); ?>
			</p>
			<?php
			return;
		}

		$default_thumbnail_id = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		?>
		<p class="description" style="margin-top:0;">
			<?php echo esc_html__( 'Override the featured image for specific languages. Leave empty to use the post\'s default featured image.', 'perflocale' ); ?>
		</p>

		<div class="perflocale-thumb-translations" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<?php
			foreach ( $languages as $lang ) :
				$slug         = (string) $lang->slug;
				$field_name   = 'perflocale_thumb_override[' . $slug . ']';
				$override_id  = (int) get_post_meta( $post->ID, self::THUMBNAIL_OVERRIDE_PREFIX . $slug, true );
				$preview_id   = $override_id > 0 ? $override_id : $default_thumbnail_id;
				$preview_src  = $preview_id > 0 ? wp_get_attachment_image_src( $preview_id, 'thumbnail' ) : false;
				$preview_url  = is_array( $preview_src ) ? (string) $preview_src[0] : '';
				$has_override = $override_id > 0;
				?>
				<div class="perflocale-thumb-translations__row" data-lang="<?php echo esc_attr( $slug ); ?>" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #f0f0f1;">
					<div class="perflocale-thumb-translations__preview" style="width:48px;height:48px;flex:0 0 48px;border:1px solid #dcdcde;background:#f6f7f7 center/cover no-repeat;<?php echo $preview_url ? "background-image:url('" . esc_url( $preview_url ) . "');" : ''; ?>"></div>

					<div style="flex:1;min-width:0;">
						<strong style="display:block;font-size:12px;"><?php echo esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) . ' ' . ( $lang->native_name ?: $lang->name ) ); ?></strong>
						<span class="perflocale-thumb-translations__state" style="font-size:11px;color:#50575e;">
							<?php echo $has_override ? esc_html__( 'Using override', 'perflocale' ) : esc_html__( 'Using default', 'perflocale' ); ?>
						</span>
					</div>

					<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( (string) $override_id ); ?>">

					<div style="display:flex;flex-direction:column;gap:4px;">
						<button type="button" class="button button-small perflocale-thumb-translations__pick" data-lang="<?php echo esc_attr( $slug ); ?>">
							<?php echo esc_html( $has_override ? __( 'Change', 'perflocale' ) : __( 'Set', 'perflocale' ) ); ?>
						</button>
						<?php if ( $has_override ) : ?>
							<button type="button" class="button-link-delete perflocale-thumb-translations__clear" data-lang="<?php echo esc_attr( $slug ); ?>" style="text-align:right;font-size:11px;">
								<?php echo esc_html__( 'Remove', 'perflocale' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Decide which languages to surface as rows in the per-language Featured
	 * Image panel for a given post.
	 *
	 * Policy — hide a language row iff at least one of:
	 *
	 * 1) A SIBLING translation post in that language exists (different
	 *    object_id, same group). The sibling has its own _thumbnail_id, so
	 *    an override on this post is unreachable - the frontend loads the
	 *    sibling for that language, never this post.
	 * 2) THIS POST is itself registered for that language (same object_id
	 *    row). A "DE override on a DE-language post" would just shadow the
	 *    post's regular Featured Image box - the editor should edit the
	 *    default _thumbnail_id directly instead.
	 *
	 * What's LEFT after both filters is the set of languages where this
	 * post will be served (no sibling) AND for which the override is the
	 * only way to get a language-specific image (because the post's
	 * default _thumbnail_id is bound to a different language). That's the
	 * exact set where the override has visible effect.
	 *
	 * Posts with no translation group at all (untranslated single-language
	 * sites, brand-new drafts) keep every language - PerfLocale doesn't
	 * know which language they're "in" yet so every override could be the
	 * one the visitor's language hits.
	 *
	 * @param int                $post_id        Current post being edited.
	 * @param array<int, object> $all_languages  All active language rows.
	 * @return array<int, object>                  Filtered language rows.
	 */
	private function resolve_panel_languages( int $post_id, array $all_languages ): array {
		$exclude = $this->collect_excluded_language_ids( $post_id );

		if ( $exclude === [] ) {
			$languages = $all_languages;
		} else {
			$languages = array_values(
				array_filter(
					$all_languages,
					static function ( object $lang ) use ( $exclude ): bool {
						return ! in_array( (int) $lang->id, $exclude, true );
					}
				)
			);
		}

		/**
		 * Filter the language list shown in the per-language Featured Image
		 * panel for one specific post.
		 *
		 * Lets themes / addons drop additional languages (e.g. hide RTL
		 * languages when the theme has no RTL stylesheet) or - by returning
		 * the full $all_languages list - show every language regardless of
		 * the default filtering.
		 *
		 * @hook perflocale/media/featured_image_panel_languages
		 * @param array<int, object> $languages     Filtered languages PerfLocale would render.
		 * @param int                $post_id       Post being edited.
		 * @param array<int, object> $all_languages Unfiltered list of every active language.
		 */
		$filtered = apply_filters(
			'perflocale/media/featured_image_panel_languages',
			$languages,
			$post_id,
			$all_languages
		);

		return is_array( $filtered ) ? array_values( $filtered ) : $languages;
	}

	/**
	 * Collect every language_id whose row in the per-language Featured
	 * Image panel would be dead UI for the given post.
	 *
	 * Combines two sets in one pass over the post's translation group:
	 *   - SIBLING languages: a different object_id occupies the (group,
	 *     language) slot, so the frontend never loads $post_id for that
	 *     language.
	 *   - SELF languages: the post itself occupies the (group, language)
	 *     slot, so an override would just shadow the regular _thumbnail_id.
	 *
	 * Returns an empty array when the post has no translation group at
	 * all - the caller treats that as "show every language" because we
	 * have no evidence the post is bound to any single language.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, int> Distinct language_ids to exclude.
	 */
	private function collect_excluded_language_ids( int $post_id ): array {
		$groups = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$links  = $groups->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post );

		$ids = [];

		foreach ( $links as $link ) {
			$lang_id = (int) ( $link->language_id ?? 0 );

			if ( $lang_id > 0 ) {
				$ids[ $lang_id ] = $lang_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Persist per-language featured-image overrides from the metabox.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object; null when core re-read the row
	 *                               after the write and found it gone, or when a
	 *                               third party re-fires save_post with one argument.
	 * @return void
	 */
	public function save_thumbnail_translations( int $post_id, ?\WP_Post $post = null ): void {
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['perflocale_thumbnail_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['perflocale_thumbnail_nonce'] ) ), 'perflocale_thumbnail_translations' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Sanitise every key + value at the boundary so $thumb_overrides is
		// guaranteed-clean at every later read site: keys via sanitize_key()
		// (lowercase alnum/dash/underscore only), values via absint(). Empty
		// or zero-id entries are dropped here so the consumer loop below
		// only sees valid pairs. Nonce was verified earlier.
		$thumb_overrides = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( isset( $_POST['perflocale_thumb_override'] ) && is_array( $_POST['perflocale_thumb_override'] ) ) {
			// Pre-sanitize the whole array so static analyzers see a sanitize
			// call adjacent to wp_unslash. Per-element sanitize_key()/absint()
			// in the foreach below is the authoritative validation step.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$raw = map_deep( wp_unslash( (array) $_POST['perflocale_thumb_override'] ), 'sanitize_text_field' );

			foreach ( $raw as $slug => $attachment_id ) {
				$slug_clean = sanitize_key( (string) $slug );
				$id_clean   = absint( $attachment_id );

				if ( $slug_clean === '' ) {
					continue;
				}

				$thumb_overrides[ $slug_clean ] = $id_clean;
			}
		}

		foreach ( $thumb_overrides as $slug => $attachment_id ) {
			$meta_key = self::THUMBNAIL_OVERRIDE_PREFIX . $slug;

			if ( $attachment_id <= 0 ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			// Basic validation: the attachment must actually exist, and it
			// must not be something a featured image can never render.
			$attachment = get_post( $attachment_id );

			if ( ! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment' ) {
				continue;
			}

			// Reject an attachment that DECLARES a non-image MIME type. The
			// picker only ever offers `library: { type: 'image' }`, and core's
			// own set_post_thumbnail() refuses anything wp_get_attachment_image()
			// can't render, so a PDF or a .zip arriving here came from a forged
			// POST, not the UI — and storing it would give that language a
			// featured image that silently renders nothing while every other
			// language keeps its image. The test is positive-evidence-only: an
			// attachment with no declared MIME type (an odd import, an offloaded
			// object) is still accepted, so this can never silently swallow a
			// real image.
			$mime = (string) $attachment->post_mime_type;

			if ( $mime !== '' && ! str_starts_with( $mime, 'image/' ) ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, $attachment_id );
		}
	}

	/**
	 * Retract every per-language featured-image override that pointed at an
	 * attachment being deleted.
	 *
	 * Core guarantees this for its own key — `wp_delete_attachment()` runs
	 * `delete_metadata( 'post', null, '_thumbnail_id', $post_id, true )`
	 * immediately after firing `delete_attachment`, so deleting an image
	 * un-assigns it as a featured image everywhere. `_perflocale_thumbnail_*`
	 * is the same assignment held under a per-language key, and without the
	 * same retraction the row survives the attachment: filter_thumbnail_id()
	 * then keeps returning the dead ID for that language, get_the_post_thumbnail()
	 * resolves it to nothing, and the page loses its featured image ENTIRELY —
	 * while every other language still renders the post's perfectly good
	 * default. Measured on a real site: `/de/<post>/` went from one
	 * `wp-post-image` to zero, `/` was unaffected, and nothing in the editor
	 * hinted at why. Deleting media is an everyday action.
	 *
	 * Iterating language rows rather than pattern-matching the meta key keeps
	 * this to core's metadata API — the object cache and the delete_post_meta
	 * hooks stay correct for free, and no new raw query joins the plugin. One
	 * indexed lookup per configured language is spent against a
	 * wp_delete_attachment() call that already runs dozens of queries on the
	 * same attachment, and the language list itself comes from find_all()'s
	 * day-long cache. find_all() (not get_active()) is the right set: a
	 * deactivated language's override becomes live again the moment it is
	 * switched back on, so its rows must be retracted too.
	 *
	 * @param mixed $attachment_id Attachment ID as core passed it — `wp_delete_attachment()`
	 *                             forwards its own argument untouched, so a caller that
	 *                             handed it a numeric string arrives here as one.
	 * @return void
	 */
	public function prune_thumbnail_overrides( mixed $attachment_id = 0 ): void {
		$attachment_id = is_numeric( $attachment_id ) ? (int) $attachment_id : 0;

		if ( $attachment_id <= 0 ) {
			return;
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );

		foreach ( $lang_repo->find_all() as $language ) {
			$slug = sanitize_key( (string) ( $language->slug ?? '' ) );

			if ( $slug === '' ) {
				continue;
			}

			// Same call shape core uses for `_thumbnail_id`: delete every
			// post's row for this key whose value is the departing attachment.
			// The `$object_id` argument is inert under `$delete_all` — core
			// absint()s it (null and 0 both become 0) before the row lookup,
			// which reads meta_key + meta_value only — so 0 is passed here
			// rather than core's null purely to satisfy the int type hint.
			delete_metadata( 'post', 0, self::THUMBNAIL_OVERRIDE_PREFIX . $slug, $attachment_id, true );
		}
	}

	/**
	 * Enqueue the WordPress media picker on post-edit screens where our
	 * per-language thumbnail metabox will appear. Gated so the assets
	 * don't load on screens that don't need them.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_media_picker_on_post_edit( string $hook ): void {
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		wp_enqueue_media();

		// No 'wp-i18n' dependency and no wp_set_script_translations(): the
		// script carries no __() calls of its own, so a JS language pack for
		// this handle would never be consulted. Every label it can write
		// travels in the localised object below instead, which also saves
		// load_script_textdomain()'s per-request file probe on every
		// post-edit screen.
		wp_enqueue_script(
			'perflocale-thumbnail-translations',
			PERFLOCALE_URL . 'assets/js/thumbnail-translations.js',
			[],
			PERFLOCALE_VERSION,
			true
		);

		wp_localize_script(
			'perflocale-thumbnail-translations',
			'perflocaleThumbTrans',
			[
				'pick_title'     => __( 'Select featured image for language', 'perflocale' ),
				'pick_button'    => __( 'Use this image', 'perflocale' ),
				'using_override' => __( 'Using override', 'perflocale' ),
				'using_default'  => __( 'Using default', 'perflocale' ),
				// render_thumbnail_translation_metabox() renders these
				// three server-side; the script rewrites the same buttons
				// after a pick or a clear. They travel through the localised
				// object rather than wp.i18n.__() because the string
				// extractor cannot follow a variable into __(): read that
				// way they never reach this handle's JS pack, so the two
				// buttons would snap back to English beside PHP-rendered
				// rows that stayed translated.
				'button_set'     => __( 'Set', 'perflocale' ),
				'button_change'  => __( 'Change', 'perflocale' ),
				'button_remove'  => __( 'Remove', 'perflocale' ),
			]
		);
	}
}
