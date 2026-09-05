<?php
/**
 * Honours the `perflocaleSkipTranslation` block attribute during MT.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When a block in the post's content carries the `perflocaleSkipTranslation`
 * attribute (set by the Gutenberg block-toolbar "Do not translate" toggle),
 * hold its innerHTML aside during machine translation and restore it
 * verbatim afterwards.
 *
 * Works across every MT callsite that flows through `TranslationService::translate_post`
 * because it hooks the existing `perflocale/mt/pre_translate` +
 * `perflocale/mt/post_translate` filters - nothing else changes.
 */
final class BlockSkipFilter {

	/**
	 * Block attribute that marks a block as "don't translate." Written
	 * by the Gutenberg block-toolbar via a registered boolean attribute;
	 * emitted into saved HTML as `data-perflocale-skip="1"`.
	 */
	public const SKIP_ATTRIBUTE = 'perflocaleSkipTranslation';

	/**
	 * Per-request store: index of the content text (within the $texts
	 * batch) =&gt; array of [ placeholder =&gt; original_innerHTML ] to
	 * restore post-translation.
	 *
	 * Keyed by `md5(serialize($texts))` so concurrent MT runs (rare but
	 * possible under load) can't cross-contaminate each other's
	 * restoration maps.
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	private static array $stash = [];

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'perflocale/mt/pre_translate', [ $this, 'mask_before' ], 15, 4 );
		add_filter( 'perflocale/mt/post_translate', [ $this, 'unmask_after' ], 15, 4 );
	}

	/**
	 * Mask skip-marked blocks before MT runs. Called from
	 * {@see TranslationService::translate_post} via
	 * `perflocale/mt/pre_translate` (priority 15 so the glossary
	 * pre-pass at priority 10 has already run).
	 *
	 * @param array<int, string> $texts [title, content, excerpt].
	 * @param string             $source_lang
	 * @param string             $target_lang
	 * @param string             $provider_id
	 * @return array<int, string>
	 */
	public function mask_before( $texts, $source_lang, $target_lang, $provider_id ) {
		if ( ! is_array( $texts ) || ! isset( $texts[1] ) ) {
			return $texts;
		}

		$content = (string) $texts[1];

		if ( $content === '' ) {
			return $texts;
		}

		// Fast path: no skip-attribute marker in the raw content (it's
		// persisted into the block-comment delimiter). Skip the parse.
		if ( strpos( $content, self::SKIP_ATTRIBUTE ) === false ) {
			return $texts;
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return $texts;
		}

		$blocks       = parse_blocks( $content );
		$placeholders = [];

		$this->mask_blocks( $blocks, $placeholders );

		if ( $placeholders === [] ) {
			return $texts;
		}

		$key = $this->stash_key( $texts );

		self::$stash[ $key ][1] = $placeholders;

		// Serialize the mutated block tree back to HTML for the MT pass.
		$texts[1] = serialize_blocks( $blocks );

		return $texts;
	}

	/**
	 * Restore masked innerHTML after MT.
	 *
	 * @param array<int, string> $translated
	 * @param array<int, string> $original [title, content, excerpt] pre-mask.
	 * @param string             $target_lang
	 * @param string             $provider_id
	 * @return array<int, string>
	 */
	public function unmask_after( $translated, $original, $target_lang, $provider_id ) {
		if ( ! is_array( $translated ) || ! isset( $translated[1] ) || ! is_array( $original ) ) {
			return $translated;
		}

		// The key is computed from the post-mask $texts, but we stashed
		// against that key in mask_before(). Here $original is the raw
		// title/content/excerpt triple from translate_post - same shape
		// we saw pre-mask. Re-derive the key the same way.
		$pre_mask_texts    = $original;
		$pre_mask_texts[1] = $original[1];
		$key               = $this->stash_key( $pre_mask_texts );

		if ( empty( self::$stash[ $key ][1] ) ) {
			return $translated;
		}

		$map = self::$stash[ $key ][1];
		unset( self::$stash[ $key ] );

		$content = (string) $translated[1];

		foreach ( $map as $placeholder => $original_html ) {
			// The protected subtree exists ONLY in the stash at this point, so
			// a provider that dropped or rewrote the placeholder comment would
			// have us persist a post with that content deleted. Keep the
			// untranslated source instead: losing a translation is recoverable,
			// losing the content is not. Same contract as the string path,
			// which rejects a translation that lost a mask token outright.
			if ( strpos( $content, $placeholder ) === false ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent content-loss point.
				error_log( 'PerfLocale BlockSkipFilter: the machine-translation provider did not return every "do not translate" placeholder; keeping the source content for this post.' );

				$translated[1] = (string) $original[1];

				return $translated;
			}

			$content = str_replace( $placeholder, $original_html, $content );
		}

		$translated[1] = $content;

		return $translated;
	}

	/**
	 * Maximum innerBlocks recursion depth. Real WP content nests < 10 levels;
	 * this is a safety valve against pathological / malicious input that
	 * could otherwise blow the PHP stack during MT.
	 */
	private const MAX_DEPTH = 50;

	/**
	 * Walk a parsed-blocks tree, replacing innerHTML of skip-marked
	 * blocks with a unique placeholder token. Recurses into innerBlocks
	 * so nested blocks inherit the skip.
	 *
	 * @param array<int, array<string, mixed>> $blocks By reference.
	 * @param array<string, string>            $placeholders By reference - filled with token =&gt; original HTML.
	 * @param int                              $depth Current recursion depth (internal).
	 * @return void
	 */
	private function mask_blocks( array &$blocks, array &$placeholders, int $depth = 0 ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}

		foreach ( $blocks as &$block ) {
			$is_skip = ! empty( $block['attrs'][ self::SKIP_ATTRIBUTE ] );

			if ( $is_skip ) {
				$original = (string) ( $block['innerHTML'] ?? '' );
				$has_kids = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] );

				if ( $original !== '' || $has_kids ) {
					// Plain HTML-comment placeholder so it round-trips through
					// providers unchanged (they treat comments as
					// untranslatable boilerplate in HTML mode).
					$token = '<!-- perflocale-skip-' . wp_generate_uuid4() . ' -->';

					// Stash the ENTIRE serialized subtree - delimiters, attrs,
					// innerHTML AND every innerBlock. Stashing only innerHTML
					// while overwriting innerContent with a single string chunk
					// DELETED the children: serialize_blocks() rebuilds a block
					// by walking innerContent and emitting the next innerBlock
					// for each NULL entry, so a chunk list containing no NULLs
					// drops every child. A protected Quote kept its cite and
					// lost its paragraph; a protected Group lost everything
					// inside it.
					$placeholders[ $token ] = serialize_block( $block );

					// Replace the whole node with a freeform block
					// (blockName === null), which serialize_block() renders as
					// its innerHTML verbatim - i.e. the bare token. The
					// wrapper's own delimiters and attribute JSON therefore
					// never reach the provider either, which is what the
					// "whole block subtree is preserved" contract always said.
					$block = [
						'blockName'    => null,
						'attrs'        => [],
						'innerBlocks'  => [],
						'innerHTML'    => $token,
						'innerContent' => [ $token ],
					];
				}

				// Don't recurse - the whole block subtree is preserved.
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->mask_blocks( $block['innerBlocks'], $placeholders, $depth + 1 );
			}
		}
	}

	/**
	 * Deterministic key for the current batch so `mask_before` and
	 * `unmask_after` find the same stash entry.
	 *
	 * @param array<int, string> $texts
	 * @return string
	 */
	private function stash_key( array $texts ): string {
		return md5( implode( "\x1F", array_map( 'strval', $texts ) ) );
	}
}
