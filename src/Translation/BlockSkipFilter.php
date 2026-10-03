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
 * hold its WHOLE serialized subtree - delimiters, attributes, innerHTML and
 * every innerBlock - aside during machine translation and restore it verbatim
 * afterwards. Nothing inside it reaches the provider on this path.
 *
 * "On this path" is the whole-post pipeline. The editor's block-level REST
 * routes do not run through these filters but follow the same rule: the
 * "translate this section / entire post" batches leave a marked block and
 * everything inside it out (block-toolbar.js collectTranslatableBlocks()), and
 * "Fill in from <lang> source" returns a source block that is marked, or sits
 * inside a marked block, verbatim with no provider call
 * ({@see BlockAttributeSource::path_is_skip_marked()}).
 *
 * Works across every MT callsite that flows through `TranslationService::translate_post`
 * because it hooks the existing `perflocale/mt/pre_translate` +
 * `perflocale/mt/post_translate` filters. That call site does two things for
 * this class: it asks {@see self::consume_kept_source()} whether the restore
 * had to keep the source content (so the result can say so), and it calls
 * {@see self::discard_stash()} in its `finally`, so a provider failure between
 * the two filters cannot leave the held-aside content behind.
 */
final class BlockSkipFilter {

	/**
	 * Block attribute that marks a block as "don't translate." Written
	 * by the Gutenberg block-toolbar via a registered boolean attribute;
	 * emitted into saved HTML as `data-perflocale-skip="1"`.
	 */
	public const SKIP_ATTRIBUTE = 'perflocaleSkipTranslation';

	/**
	 * Per-process store: index of the content text (within the $texts
	 * batch) =&gt; array of [ placeholder =&gt; serialized block subtree ] to
	 * restore post-translation.
	 *
	 * Keyed by the md5 of the batch's texts joined with `\x1F`, so one
	 * translate_post run cannot restore another's map. A second run over the
	 * same source content overwrites the same key rather than adding one.
	 *
	 * An entry is removed when {@see self::unmask_after()} consumes it, and
	 * otherwise by {@see self::discard_stash()} from `translate_post`'s
	 * `finally`. That pairing is a property of THAT call site: anything else
	 * that applies `perflocale/mt/pre_translate` directly masks without a
	 * matching discard, and the entry is then its caller's to clear. Inside
	 * `translate_post` the pairing does not hold for a
	 * `perflocale/mt/pre_translate` callback below priority 15 that rewrites
	 * the texts (the key is then computed from text neither the restore nor the
	 * discard re-derives), nor for a same-content `translate_post` nested
	 * inside the mask-to-unmask window (the inner call owns the key) - both
	 * pathological, both noted where they matter.
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	private static array $stash = [];

	/**
	 * Runs whose restore found a placeholder missing and put the source
	 * content back, keyed like {@see self::$stash}. Read and cleared by
	 * {@see self::consume_kept_source()}; also cleared by
	 * {@see self::discard_stash()}.
	 *
	 * @var array<string, true>
	 */
	private static array $kept_source = [];

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
	 * `perflocale/mt/pre_translate`, late enough (priority 15) to mask what
	 * earlier callbacks put into the content. A callback below that priority
	 * which REWRITES the texts breaks the stash key, because the restore side
	 * re-derives it from the raw post fields.
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

		$blocks        = parse_blocks( $content );
		$placeholders  = [];
		$deep_withheld = false;

		$this->mask_blocks( $blocks, $placeholders, $deep_withheld );

		if ( $deep_withheld ) {
			// Withholding a subtree leaves part of the post untranslated, which
			// is a quieter outcome than the operator asked for. Report it once
			// per run, the same way a lost placeholder is reported below.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a deliberate partial-translation point.
			error_log( 'PerfLocale BlockSkipFilter: content nested deeper than ' . self::MAX_DEPTH . ' block levels holds a subtree whose serialization carries the "do not translate" marker string; that whole subtree was kept out of the machine-translation request, so everything inside it stays untranslated.' );
		}

		if ( $placeholders === [] ) {
			return $texts;
		}

		$key = self::stash_key( $texts );

		self::$stash[ $key ][1] = $placeholders;

		// Serialize the mutated block tree back to HTML for the MT pass.
		$texts[1] = serialize_blocks( $blocks );

		return $texts;
	}

	/**
	 * Put the held-aside subtrees back after MT.
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
		$key               = self::stash_key( $pre_mask_texts );

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

				// Reported to the caller through consume_kept_source(), so the
				// run is not presented as a plain success.
				self::$kept_source[ $key ] = true;

				$translated[1] = (string) $original[1];

				return $translated;
			}

			$content = str_replace( $placeholder, $original_html, $content );
		}

		$translated[1] = $content;

		return $translated;
	}

	/**
	 * Drop the stash entry a run left behind.
	 *
	 * {@see self::unmask_after()} consumes the entry on the way out, but it
	 * only runs once `perflocale/mt/post_translate` is reached: a provider that
	 * throws in between never gets there, and the held-aside subtrees would
	 * then sit in this static for the rest of the process - one entry per
	 * distinct failing source post, which a long CLI or worker run through a
	 * provider outage repeats. {@see TranslationService::translate_post} calls
	 * this from its `finally`, so the failure path costs what the success path
	 * already did.
	 *
	 * Keyed exactly as `unmask_after` keys its lookup, from the raw
	 * [title, content, excerpt] triple, so it removes an entry in precisely the
	 * cases the restore side would have found one.
	 *
	 * @param array<int, string> $texts Raw [title, content, excerpt] triple the run started from.
	 * @return void
	 */
	public static function discard_stash( array $texts ): void {
		if ( self::$stash === [] && self::$kept_source === [] ) {
			return;
		}

		$key = self::stash_key( $texts );

		unset( self::$stash[ $key ], self::$kept_source[ $key ] );
	}

	/**
	 * Whether the restore for this run had to keep the source content because
	 * the provider lost a "do not translate" placeholder. Reading clears it.
	 *
	 * Keyed like {@see self::discard_stash()}, from the raw
	 * [title, content, excerpt] triple the run started from.
	 *
	 * @param array<int, string> $texts Raw [title, content, excerpt] triple the run started from.
	 * @return bool
	 */
	public static function consume_kept_source( array $texts ): bool {
		if ( self::$kept_source === [] ) {
			return false;
		}

		$key  = self::stash_key( $texts );
		$kept = isset( self::$kept_source[ $key ] );

		unset( self::$kept_source[ $key ] );

		return $kept;
	}

	/**
	 * Depth at which the traversal below stops reading block attributes and
	 * starts withholding whole subtrees instead. Real WP content nests < 10
	 * levels. It bounds this class's own recursion only.
	 */
	private const MAX_DEPTH = 50;

	/**
	 * Walk a parsed-blocks tree, replacing each skip-marked block with a unique
	 * placeholder token and stashing its whole serialized subtree. A marked
	 * block is not descended into - everything inside it is preserved with it.
	 *
	 * @param array<int, array<string, mixed>> $blocks By reference.
	 * @param array<string, string>            $placeholders By reference - filled with token =&gt; serialized subtree.
	 * @param bool                             $deep_withheld By reference - set when the past-depth branch withheld a subtree.
	 * @param int                              $depth Current recursion depth (internal).
	 * @return void
	 */
	private function mask_blocks( array &$blocks, array &$placeholders, bool &$deep_withheld, int $depth = 0 ): void {
		// Past this depth the walk no longer reads attributes, and whatever it
		// leaves in the tree is what the provider is sent. Withhold rather than
		// hand a marked block over: every subtree whose SERIALIZATION contains
		// the marker string is replaced whole, unmarked descendants included.
		// Over-withholding at a depth real content never reaches is the safe
		// side of that trade; translating a block the author marked "do not
		// translate" is not.
		$past_depth = $depth > self::MAX_DEPTH;

		foreach ( $blocks as &$block ) {
			if ( $past_depth ) {
				$serialized = serialize_block( $block );

				if ( strpos( $serialized, self::SKIP_ATTRIBUTE ) === false ) {
					continue;
				}

				$token                  = $this->replace_with_token( $block );
				$placeholders[ $token ] = $serialized;
				$deep_withheld          = true;

				continue;
			}

			$is_skip = ! empty( $block['attrs'][ self::SKIP_ATTRIBUTE ] );

			if ( $is_skip ) {
				$original = (string) ( $block['innerHTML'] ?? '' );
				$has_kids = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] );

				if ( $original !== '' || $has_kids ) {
					// Stash the ENTIRE serialized subtree - delimiters, attrs,
					// innerHTML AND every innerBlock. Stashing only innerHTML
					// while overwriting innerContent with a single string chunk
					// DELETED the children: serialize_blocks() rebuilds a block
					// by walking innerContent and emitting the next innerBlock
					// for each NULL entry, so a chunk list containing no NULLs
					// drops every child. A protected Quote kept its cite and
					// lost its paragraph; a protected Group lost everything
					// inside it.
					$serialized = serialize_block( $block );

					$token                  = $this->replace_with_token( $block );
					$placeholders[ $token ] = $serialized;
				}

				// Don't recurse - the whole block subtree is preserved.
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->mask_blocks( $block['innerBlocks'], $placeholders, $deep_withheld, $depth + 1 );
			}
		}

		// The loop variable still points at the last element; a later write to
		// it would rewrite that element rather than the variable.
		unset( $block );
	}

	/**
	 * Swap one block node for a freeform placeholder node and return the token.
	 *
	 * Both withholding paths go through here so they cannot drift apart. The
	 * replacement is 1:1 - one node in, one node out - because the parent's
	 * innerContent carries one NULL per inner block and serialize_block()
	 * emits the next innerBlock for each of them: collapsing several nodes into
	 * one would walk past the end of that list.
	 *
	 * @param array<string, mixed> $block By reference - replaced with the token node.
	 * @return string The placeholder token now standing in for $block.
	 */
	private function replace_with_token( array &$block ): string {
		// Plain HTML-comment placeholder so it round-trips through providers
		// unchanged (they treat comments as untranslatable boilerplate in HTML
		// mode).
		$token = '<!-- perflocale-skip-' . wp_generate_uuid4() . ' -->';

		// A freeform block (blockName === null) serializes as its innerHTML
		// verbatim - i.e. the bare token. The original's own delimiters and
		// attribute JSON therefore never reach the provider either, which is
		// what the "whole block subtree is preserved" contract always said.
		$block = [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $token,
			'innerContent' => [ $token ],
		];

		return $token;
	}

	/**
	 * Deterministic key for the current batch so `mask_before`, `unmask_after`
	 * and `discard_stash` find the same stash entry.
	 *
	 * @param array<int, string> $texts
	 * @return string
	 */
	private static function stash_key( array $texts ): string {
		return md5( implode( "\x1F", array_map( 'strval', $texts ) ) );
	}
}
