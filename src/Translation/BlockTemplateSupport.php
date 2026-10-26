<?php
/**
 * Data shape for translated block templates and template parts.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Helper;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives a `wp_template` / `wp_template_part` translation the slug and `wp_theme` term core needs to find it.
 *
 * Core finds a part by published slug + theme term, so a half-built translation falls back to the source.
 * See docs-ai/11-code-rationale.md, "BlockTemplateSupport: findable translation shape".
 */
final class BlockTemplateSupport {

	/**
	 * Post types this class shapes: FSE infrastructure, resolved by slug, never browsed by language.
	 */
	public const TYPES = [ 'wp_template', 'wp_template_part' ];

	/**
	 * Slug marker separating a source slug from its language suffix; one neither humans nor core produce.
	 */
	public const SLUG_MARKER = '-pfl-';

	/**
	 * Longest base post_name: VARCHAR(200) minus room for the marker and the language slug.
	 */
	private const SLUG_MAX = 180;

	/**
	 * Is this post type an FSE template type? Static so automatic save paths can ask without a service.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_template_type( string $post_type ): bool {
		return in_array( $post_type, self::TYPES, true );
	}

	/**
	 * Does this slug belong to one of our translations (marker + active language suffix, optional `-<digits>`)?
	 *
	 * Must stay anchored and language-aware: a bare marker match hides operator templates such as `page-pfl-x`.
	 * See docs-ai/11-code-rationale.md, "BlockTemplateSupport: anchored translation slug".
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

		// Truncated bases collide and core does not dedupe template slugs: add a digest of the full source slug.
		if ( $base !== $source_slug ) {
			$base .= '-' . substr( md5( $source_slug ), 0, 8 );
		}

		return $base . self::SLUG_MARKER . sanitize_key( $lang_slug );
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'perflocale/translation/post_data', [ $this, 'shape_post_data' ], 10, 3 );
		add_action( 'perflocale/translation/created', [ $this, 'attach_terms' ], 10, 4 );

		// Priority 40: after the default-language assignment (5), ContentSync (20) and ContentChangeDetector (30),
		// so the new template has its language and neither sees a half-built group.
		add_action( 'save_post', [ $this, 'ensure_translations_exist' ], 40, 2 );

		// Menu and pattern references; by class name so TemplateBlockRefs loads only when one fires.
		// `before_delete_post` at 5: before the link cleanup (10) removes the deleted post's language.
		add_action( 'perflocale/translation/created', [ TemplateBlockRefs::class, 'on_created' ], 20, 3 );
		add_action( 'before_delete_post', [ TemplateBlockRefs::class, 'on_removed' ], 5, 1 );
		add_action( 'wp_trash_post', [ TemplateBlockRefs::class, 'on_removed' ], 10, 1 );
		add_action( 'untrashed_post', [ TemplateBlockRefs::class, 'on_restored' ], 10, 1 );
	}

	/**
	 * Give every active language a draft copy of this source template as soon as it is saved.
	 *
	 * Only the source fans out (a translation would add a second original); drafts never render.
	 * See docs-ai/11-code-rationale.md, "BlockTemplateSupport: templates auto-create".
	 *
	 * @param int           $post_id Post being saved.
	 * @param \WP_Post|null $post    The post, as WordPress passes it.
	 * @return void
	 */
	public function ensure_translations_exist( int $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && self::is_template_type( $post->post_type ) ) {
			BlockTemplateWrites::ensure_translations_exist( $post_id, $post );
		}
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
		return is_string( $data['post_type'] ?? null ) && self::is_template_type( $data['post_type'] ) ? BlockTemplateWrites::shape_post_data( $data, $source_id, $target_slug ) : $data;
	}

	/**
	 * Give the new translation the terms core's own lookup requires.
	 *
	 * Not via `tax_input`: wp_insert_post() capability-gates it, and CLI, cron and MT runs have no user.
	 *
	 * @param int    $new_post_id New translation post ID.
	 * @param string $object_type 'post' or 'term'.
	 * @param string $target_slug Target language slug.
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public function attach_terms( int $new_post_id, string $object_type, string $target_slug, int $source_id ): void {
		if ( 'post' === $object_type ) {
			BlockTemplateWrites::attach_terms( $new_post_id, $object_type, $target_slug, $source_id );
		}
	}
}
