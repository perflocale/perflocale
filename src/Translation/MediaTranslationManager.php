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
	private const THUMBNAIL_OVERRIDE_PREFIX = '_perflocale_thumbnail_';

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
	 * Mirror the per-language caption/description into REST view payloads; edit keeps the source it writes back.
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

		// Escape late: the_post_thumbnail_caption() echoes this unescaped.
		return $translated !== '' ? esc_html( (string) $translated ) : $caption;
	}

	/**
	 * Filter attachment data for JS (media library modal).
	 *
	 * `$meta` stays untyped: core passes false when there is no metadata, and a type error empties the library.
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
		$lang_repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages  = $lang_repo->get_active();
		$default    = $lang_repo->get_default();
		$field_keys = self::field_keys( $languages );

		foreach ( $languages as $lang ) {
			if ( $default && $lang->slug === $default->slug ) {
				continue;
			}

			$slug = (string) $lang->slug;

			if ( ! isset( $field_keys[ $slug ] ) ) {
				continue;
			}

			$key   = $field_keys[ $slug ];
			$label = strtoupper( $slug );

			// Core prints `label` as HTML and the key unescaped in attributes: escape the label, key from field_keys().

			// Alt text per language.
			$form_fields[ 'perflocale_alt_' . $key ] = [
				/* translators: %s: Language code */
				'label' => esc_html( sprintf( __( 'Alt Text (%s)', 'perflocale' ), $label ) ),
				'input' => 'text',
				'value' => get_post_meta( $post->ID, '_perflocale_alt_' . $slug, true ),
				// Core prints `helps` as HTML, so it is escaped here.
				/* translators: %s: Language name */
				'helps' => esc_html( sprintf( __( 'Alternative text for %s', 'perflocale' ), $lang->name ) ),
			];

			// Caption per language.
			$form_fields[ 'perflocale_caption_' . $key ] = [
				/* translators: %s: Language code */
				'label' => esc_html( sprintf( __( 'Caption (%s)', 'perflocale' ), $label ) ),
				'input' => 'text',
				'value' => get_post_meta( $post->ID, '_perflocale_caption_' . $slug, true ),
			];

			// Description per language; core prints a textarea `value` as-is, so it is escaped here.
			$description = get_post_meta( $post->ID, '_perflocale_description_' . $slug, true );

			$form_fields[ 'perflocale_desc_' . $key ] = [
				/* translators: %s: Language code */
				'label' => esc_html( sprintf( __( 'Description (%s)', 'perflocale' ), $label ) ),
				'input' => 'textarea',
				'value' => esc_textarea( is_scalar( $description ) ? (string) $description : '' ),
			];
		}

		return $form_fields;
	}

	/**
	 * Save translation fields when attachment is saved.
	 *
	 * `$attachment` is a raw request slice core never type-checks; `$post` is returned unchanged, as core writes it.
	 *
	 * @param array<string, mixed> $post Post data.
	 * @param mixed                $attachment Attachment field data: an array, or any scalar a malformed request sent.
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

		foreach ( self::field_keys( $languages ) as $slug => $key ) {
			if ( isset( $attachment[ 'perflocale_alt_' . $key ] ) ) {
				update_post_meta( $post_id, '_perflocale_alt_' . $slug, sanitize_text_field( $attachment[ 'perflocale_alt_' . $key ] ) );
			}

			if ( isset( $attachment[ 'perflocale_caption_' . $key ] ) ) {
				update_post_meta( $post_id, '_perflocale_caption_' . $slug, sanitize_text_field( $attachment[ 'perflocale_caption_' . $key ] ) );
			}

			if ( isset( $attachment[ 'perflocale_desc_' . $key ] ) ) {
				update_post_meta( $post_id, '_perflocale_description_' . $slug, sanitize_textarea_field( $attachment[ 'perflocale_desc_' . $key ] ) );
			}
		}

		return $post;
	}

	/**
	 * Media-form field key suffix per language slug: sanitize_key() of the slug, safe in core's attributes.
	 *
	 * On a suffix collision the first language keeps it, so one posted value never reaches two languages.
	 *
	 * @param array<int, object> $languages Language rows, in display order.
	 * @return array<string, string> Slug => field key suffix.
	 */
	private static function field_keys( array $languages ): array {
		$keys = [];
		$used = [];

		foreach ( $languages as $lang ) {
			$slug = (string) ( $lang->slug ?? '' );
			$key  = sanitize_key( $slug );

			if ( $key === '' || isset( $keys[ $slug ] ) || isset( $used[ $key ] ) ) {
				continue;
			}

			$keys[ $slug ] = $key;
			$used[ $key ]  = true;
		}

		return $keys;
	}

	// -------------------------------------------------------------------------
	// Per-language featured image overrides.
	// -------------------------------------------------------------------------

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

	/**
	 * Register the per-language featured-image metabox on translatable types with thumbnail support.
	 *
	 * @return void
	 */
	public function add_thumbnail_translation_metabox( string $active_post_type = '', $post = null ): void {
		unset( $active_post_type ); // Unused: add_meta_box() scopes each box to its screen.

		$plugin    = \PerfLocale\Plugin::get_instance();
		$settings  = $plugin->get( 'settings' );
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();

		if ( count( $languages ) < 2 ) {
			return;
		}

		// The post this call is for, or the global when the caller passed none.
		$current_post = $post instanceof \WP_Post ? $post : null;

		if ( ! $current_post && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$current_post = $GLOBALS['post'];
		}

		foreach ( $settings->get_translatable_post_types() as $type ) {
			if ( ! post_type_supports( $type, 'thumbnail' ) ) {
				continue;
			}

			// Like core's Featured Image box: the theme may support thumbnails for a subset of types.
			if ( ! current_theme_supports( 'post-thumbnails', $type ) ) {
				continue;
			}

			/**
			 * Filter whether the per-language Featured Image panel is registered; false keeps the front-end swap.
			 *
			 * @hook perflocale/media/show_featured_image_panel
			 * @param bool   $show      Default: true.
			 * @param string $post_type Post type slug being evaluated.
			 */
			if ( ! apply_filters( 'perflocale/media/show_featured_image_panel', true, $type ) ) {
				continue;
			}

			// No box when no language row applies; the render placeholder is only a fallback.
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
				<?php echo esc_html__( 'Every language already has its own translation of this post - each translation manages its own featured image. No per-language override needed here.', 'perflocale' ); ?>
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
	 * Languages whose override row has a visible effect in the Featured Image panel for this post.
	 *
	 * Hides languages held by a sibling or by the post itself; an unlinked post keeps every language.
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
		 * Filter the language list shown in the per-language Featured Image panel for one post.
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
	 * Language IDs held in this post's group (by a sibling or by the post itself); [] when unlinked.
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
	 * @param \WP_Post|null $post    Post object; null when the row is gone or save_post fired with one argument.
	 * @return void
	 */
	public function save_thumbnail_translations( int $post_id, ?\WP_Post $post = null ): void {
		// Nullable on purpose: see the @param above.
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

		// The form belongs to the edited post; other posts saved in this request keep their overrides.
		$form_post = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( $form_post !== $post_id ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Keys and values sanitised at the boundary.
		$thumb_overrides = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( isset( $_POST['perflocale_thumb_override'] ) && is_array( $_POST['perflocale_thumb_override'] ) ) {
			// For static analysers; the per-element sanitize_key()/absint() below is the real validation.
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

			// The attachment must exist and be renderable as a featured image.
			$attachment = get_post( $attachment_id );

			if ( ! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment' ) {
				continue;
			}

			// Reject only a declared non-image MIME type: an empty one (odd import, offloaded) may be a real image.
			$mime = (string) $attachment->post_mime_type;

			if ( $mime !== '' && ! str_starts_with( $mime, 'image/' ) ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, $attachment_id );
		}
	}

	/**
	 * Retract every per-language featured-image override that pointed at an attachment being deleted.
	 *
	 * Like core for `_thumbnail_id`: a leftover override would remove that language's featured image.
	 * Per language through the metadata API (cache and hooks stay correct); find_all(), as an inactive
	 * language's overrides return when it is reactivated.
	 *
	 * @param mixed $attachment_id Attachment ID as core passed it; may be a numeric string.
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

			// Core's call shape for `_thumbnail_id`; $object_id is inert under $delete_all (0 for the int hint).
			delete_metadata( 'post', 0, self::THUMBNAIL_OVERRIDE_PREFIX . $slug, $attachment_id, true );
		}
	}

	/**
	 * Enqueue the media picker on post-edit screens for the per-language thumbnail metabox.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_media_picker_on_post_edit( string $hook ): void {
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		wp_enqueue_media();

		// No wp-i18n: every label the script writes travels in the localised object below.
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
				// Localised, not wp.i18n: the extractor cannot follow a variable into __() in the script.
				'button_set'     => __( 'Set', 'perflocale' ),
				'button_change'  => __( 'Change', 'perflocale' ),
				'button_remove'  => __( 'Remove', 'perflocale' ),
			]
		);
	}
}
