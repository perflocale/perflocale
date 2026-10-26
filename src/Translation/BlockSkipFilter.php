<?php
/**
 * Honours the `perflocaleSkipTranslation` block attribute during MT.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\MachineTranslation\PlaceholderCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds "Do not translate" blocks, whole subtree included, aside during whole-post MT and restores them verbatim.
 *
 * TranslationService::translate_post() calls discard_stash() in its `finally`: a
 * provider failure must not keep the subtrees in memory. Call-site contract: see
 * docs-ai/11-code-rationale.md, "BlockSkipFilter: the translate_post pairing".
 */
final class BlockSkipFilter {

	/**
	 * Block attribute set by the block-toolbar "Do not translate" toggle; the
	 * saved HTML also carries `data-perflocale-skip="1"`.
	 */
	public const SKIP_ATTRIBUTE = 'perflocaleSkipTranslation';

	/**
	 * A placeholder replace_with_token() makes; group 1 is its uuid.
	 */
	private const TOKEN_RE = '/<!-- perflocale-skip-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}) -->/';

	/**
	 * Held-aside subtrees per run, keyed by stash_key(): [1] placeholder => subtree,
	 * [2] placeholder => parent block chain. unmask_after() or discard_stash()
	 * removes an entry; a caller of `perflocale/mt/pre_translate` outside
	 * translate_post() must clear its own.
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	private static array $stash = [];

	/**
	 * Runs whose restore found a placeholder missing and put the source content
	 * back, keyed like self::$stash.
	 *
	 * @var array<string, true>
	 */
	private static array $kept_source = [];

	/**
	 * Protected units the restore put back (subtree and parent block chain), keyed
	 * like self::$stash; also set when it kept the source, which holds the same blocks.
	 *
	 * @var array<string, array<int, array{bytes: string, chain: string}>>
	 */
	private static array $restored = [];

	/**
	 * Whether a content may hold a block marked "Do not translate"; false only
	 * when it cannot. The name may be written with JSON \u escapes, which the
	 * block parser decodes, so any letter escape (\u0041 to \u007a) also counts.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function may_have_marker( string $content ): bool {
		return str_contains( $content, self::SKIP_ATTRIBUTE ) || 1 === preg_match( '/\\\\u00[4-7][0-9a-f]/i', $content );
	}

	/**
	 * `wp_insert_post_data` / `wp_insert_attachment_data` for every save: ProtectedBlockSave::keep_on_save(), unless
	 * that class is not loaded yet (no state of its own) and the submitted content cannot carry a marker.
	 *
	 * @param mixed $data        Slashed post data about to be written.
	 * @param mixed $postarr     Sanitized arguments of the save.
	 * @param mixed $unsanitized Arguments of the save before the content filters.
	 * @return mixed
	 */
	public static function keep_on_save( mixed $data, mixed $postarr = [], mixed $unsanitized = [] ): mixed {
		$raw = is_array( $unsanitized ) ? ( $unsanitized['post_content'] ?? null ) : null;

		if ( ! class_exists( \PerfLocale\MachineTranslation\ProtectedBlockSave::class, false ) && ( ! is_string( $raw ) || ! self::may_have_marker( $raw ) ) ) {
			return $data;
		}

		return \PerfLocale\MachineTranslation\ProtectedBlockSave::keep_on_save( $data, $postarr, $unsanitized );
	}

	/**
	 * `_wp_post_revision_fields`: ProtectedBlockSave::note_restore() for a revision (a restore) or once that class is loaded.
	 *
	 * @param mixed $fields Revision fields.
	 * @param mixed $post   Post or revision the fields are read for.
	 * @return mixed
	 */
	public static function note_restore( mixed $fields, mixed $post = null ): mixed {
		$row = is_object( $post ) ? get_object_vars( $post ) : $post;

		if ( ! ( is_array( $row ) && 'revision' === ( $row['post_type'] ?? '' ) ) && ! class_exists( \PerfLocale\MachineTranslation\ProtectedBlockSave::class, false ) ) {
			return $fields;
		}

		return \PerfLocale\MachineTranslation\ProtectedBlockSave::note_restore( $fields, $post );
	}

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
	 * Mask skip-marked blocks before MT runs, at priority 15 to mask what earlier
	 * callbacks added. A callback below 15 that rewrites the texts breaks the
	 * stash key, which the restore re-derives from the raw post fields.
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

		// Fast path, a deliberate cost decision: no marker in the raw content, no parse.
		if ( ! self::may_have_marker( $content ) ) {
			return $texts;
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return $texts;
		}

		$deep_withheld = false;

		[ $blocks, $placeholders, $chains ] = $this->mask_content( $content, $deep_withheld );

		if ( $deep_withheld ) {
			// Withholding leaves part of the post untranslated: report it once per run.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a deliberate partial-translation point.
			error_log( 'PerfLocale BlockSkipFilter: content nested deeper than ' . self::MAX_DEPTH . ' block levels holds a subtree whose serialization carries the "do not translate" marker string; that whole subtree was kept out of the machine-translation request, so everything inside it stays untranslated.' );
		}

		if ( $placeholders === [] ) {
			return $texts;
		}

		$key = self::stash_key( $texts );

		self::$stash[ $key ][1] = $placeholders;
		self::$stash[ $key ][2] = $chains;

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

		// Same key as mask_before(): $original is the pre-mask [title, content, excerpt].
		$pre_mask_texts    = $original;
		$pre_mask_texts[1] = $original[1];
		$key               = self::stash_key( $pre_mask_texts );

		if ( empty( self::$stash[ $key ][1] ) ) {
			return $translated;
		}

		$map    = self::$stash[ $key ][1];
		$chains = self::$stash[ $key ][2] ?? [];
		unset( self::$stash[ $key ] );

		self::$restored[ $key ] = self::units( $map, $chains );

		$content = self::put_back( (string) $translated[1], $map );

		// A lost placeholder means its subtree exists only in the stash: keep the
		// source rather than persist the post with that content deleted.
		if ( null === $content ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent content-loss point.
			error_log( 'PerfLocale BlockSkipFilter: the machine-translation provider did not return every "do not translate" placeholder; keeping the source content for this post.' );

			// Reported through consume_kept_source(), so the run is not a plain success.
			self::$kept_source[ $key ] = true;

			$translated[1] = (string) $original[1];

			return $translated;
		}

		$translated[1] = $content;

		return $translated;
	}

	/**
	 * Put every placeholder occurrence back in one pass (other maps: put_back_each()).
	 * Valid as all placeholders start with `<!-- perflocale-skip-` (one `<`, so no overlap) and share one length.
	 *
	 * @param string                $content Provider output with placeholders.
	 * @param array<string, string> $map     Placeholder => serialized subtree.
	 * @return string|null Null when a placeholder of the map is not in the content.
	 */
	private static function put_back( string $content, array $map ): ?string {
		$prefix = '<!-- perflocale-skip-';
		$size   = null;

		foreach ( array_keys( $map ) as $placeholder ) {
			$placeholder = (string) $placeholder;

			if ( ! str_starts_with( $placeholder, $prefix ) || ( null !== $size && strlen( $placeholder ) !== $size ) ) {
				return self::put_back_each( $content, $map );
			}

			$size = strlen( $placeholder );
		}

		if ( null === $size ) {
			return $content;
		}

		$parts = [];
		$found = [];
		$at    = 0;
		$skip  = strlen( $prefix );
		$pos   = strpos( $content, $prefix );

		while ( false !== $pos ) {
			$candidate = substr( $content, $pos, $size );

			if ( isset( $map[ $candidate ] ) ) {
				$parts[]             = substr( $content, $at, $pos - $at );
				$parts[]             = $map[ $candidate ];
				$found[ $candidate ] = true;
				$at                  = $pos + $size;
				$pos                 = strpos( $content, $prefix, $at );

				continue;
			}

			$pos = strpos( $content, $prefix, $pos + $skip );
		}

		if ( count( $found ) !== count( $map ) ) {
			return null;
		}

		$parts[] = substr( $content, $at );

		return implode( '', $parts );
	}

	/**
	 * Put the subtrees back one placeholder at a time (placeholders not of the token form).
	 *
	 * @param string                $content Provider output with placeholders.
	 * @param array<string, string> $map     Placeholder => serialized subtree.
	 * @return string|null Null when a placeholder of the map is not in the content.
	 */
	private static function put_back_each( string $content, array $map ): ?string {
		foreach ( $map as $placeholder => $original_html ) {
			if ( strpos( $content, (string) $placeholder ) === false ) {
				return null;
			}

			$content = str_replace( (string) $placeholder, $original_html, $content );
		}

		return $content;
	}

	/**
	 * The content with each placeholder as an empty `<pfl-skip>` element, for a provider that returns HTML comments as text.
	 *
	 * @param string $content Content as it is sent.
	 * @return string
	 */
	public static function placeholders_as_elements( string $content ): string {
		if ( ! str_contains( $content, '<!-- perflocale-skip-' ) ) {
			return $content;
		}

		return preg_replace( self::TOKEN_RE, '<pfl-skip data-i="$1"></pfl-skip>', $content ) ?? $content;
	}

	/**
	 * The placeholders back from placeholders_as_elements() when each element came back exactly once.
	 *
	 * @param string $answer Provider answer.
	 * @param string $sent   The content before placeholders_as_elements().
	 * @return string Else the answer as it is: unmask_after() then keeps the source.
	 */
	public static function placeholders_from_elements( string $answer, string $sent ): string {
		if ( ! preg_match_all( self::TOKEN_RE, $sent, $m ) ) {
			return $answer;
		}

		$map = [];

		foreach ( $m[1] as $i => $uuid ) {
			$map[ '<pfl-skip data-i="' . $uuid . '"></pfl-skip>' ] = $m[0][ $i ];
		}

		if ( 0 !== PlaceholderCheck::damaged( $answer, array_keys( $map ), '/<pfl-skip\b[^>]*+>(?:\s*+<\/pfl-skip>)?|<\/pfl-skip>/' ) ) {
			return $answer;
		}

		return strtr( $answer, $map );
	}

	/**
	 * Drop the stash entry a run left behind.
	 *
	 * A provider that throws never reaches unmask_after(), so translate_post()
	 * calls this from its `finally`. Keyed from the raw triple exactly as
	 * unmask_after() keys its lookup.
	 *
	 * @param array<int, string> $texts Raw [title, content, excerpt] triple the run started from.
	 * @return void
	 */
	public static function discard_stash( array $texts ): void {
		if ( self::$stash === [] && self::$kept_source === [] && self::$restored === [] ) {
			return;
		}

		$key = self::stash_key( $texts );

		unset( self::$stash[ $key ], self::$kept_source[ $key ], self::$restored[ $key ] );
	}

	/**
	 * Whether the restore for this run had to keep the source content because
	 * the provider lost a "do not translate" placeholder. Reading clears it.
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
	 * The protected units the restore of this run put back: each serialized
	 * subtree with its parent block chain. Reading clears them.
	 *
	 * @param array<int, string> $texts Raw [title, content, excerpt] triple the run started from.
	 * @return array<int, array{bytes: string, chain: string}>
	 */
	public static function consume_restored( array $texts ): array {
		if ( self::$restored === [] ) {
			return [];
		}

		$key      = self::stash_key( $texts );
		$restored = self::$restored[ $key ] ?? [];

		unset( self::$restored[ $key ] );

		return $restored;
	}

	/**
	 * Every subtree of a content the mask holds aside, serialized exactly as
	 * mask_before() stashes it, in document order (past self::MAX_DEPTH: each
	 * whole subtree whose serialization carries the marker).
	 *
	 * @param string $content Post content.
	 * @return array<int, string>
	 */
	public static function protected_subtrees( string $content ): array {
		if ( '' === $content || ! self::may_have_marker( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$deep_withheld = false;

		return array_values( ( new self() )->mask_content( $content, $deep_withheld )[1] );
	}

	/**
	 * Every protected unit of a content: the protected subtrees whose own block is
	 * marked, each with its parent block chain ('' at the top), in document order.
	 * A subtree withheld past self::MAX_DEPTH only for the marker string is no unit.
	 *
	 * @param string $content Post content.
	 * @return array<int, array{bytes: string, chain: string}>
	 */
	public static function protected_units( string $content ): array {
		if ( '' === $content || ! self::may_have_marker( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$deep_withheld = false;

		[ , $placeholders, $chains, $unmarked ] = ( new self() )->mask_content( $content, $deep_withheld );

		return self::units( array_diff_key( $placeholders, $unmarked ), $chains );
	}

	/**
	 * Pair each held-aside subtree with its parent block chain.
	 *
	 * @param array<string, string> $placeholders Token => serialized subtree.
	 * @param array<string, string> $chains       Token => parent block chain.
	 * @return array<int, array{bytes: string, chain: string}>
	 */
	private static function units( array $placeholders, array $chains ): array {
		$units = [];

		foreach ( $placeholders as $token => $bytes ) {
			$units[] = [
				'bytes' => $bytes,
				'chain' => $chains[ $token ] ?? '',
			];
		}

		return $units;
	}

	/**
	 * Parse a content and hold its protected subtrees aside: the one walk
	 * both {@see self::mask_before()} and {@see self::protected_subtrees()}
	 * use, so the stash and the save-time check serialize alike.
	 *
	 * @param string $content       Post content.
	 * @param bool   $deep_withheld By reference - set when the past-depth branch withheld a subtree.
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>, 2: array<string, string>, 3: array<string, true>} [ masked blocks, token => serialized subtree, token => parent block chain, token => true for a subtree whose own block is not marked ].
	 */
	private function mask_content( string $content, bool &$deep_withheld ): array {
		$blocks       = parse_blocks( $content );
		$placeholders = [];
		$chains       = [];
		$unmarked     = [];

		$this->mask_blocks( $blocks, $placeholders, $deep_withheld, 0, [], $chains, $unmarked );

		return [ $blocks, $placeholders, $chains, $unmarked ];
	}

	/**
	 * Depth past which the walk stops reading block attributes and withholds
	 * whole subtrees instead; it bounds this class's own recursion only.
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
	 * @param array<int, string>               $ancestors Names of the blocks above this level (internal).
	 * @param array<string, string>            $chains By reference - filled with token =&gt; parent block chain.
	 * @param array<string, true>              $unmarked By reference - filled with token =&gt; true for a subtree withheld past the depth whose own block is not marked.
	 * @return void
	 */
	private function mask_blocks( array &$blocks, array &$placeholders, bool &$deep_withheld, int $depth = 0, array $ancestors = [], array &$chains = [], array &$unmarked = [] ): void {
		$chain = implode( ' > ', $ancestors );

		// Past this depth the walk stops descending and fails closed: every subtree
		// whose serialization holds the marker string is withheld whole, never sent.
		// Only one whose own block is marked counts as a unit (protected_units()).
		$past_depth = $depth > self::MAX_DEPTH;

		foreach ( $blocks as &$block ) {
			if ( $past_depth ) {
				$serialized = serialize_block( $block );

				if ( strpos( $serialized, self::SKIP_ATTRIBUTE ) === false ) {
					continue;
				}

				$attrs                  = $block['attrs'] ?? null;
				$marked                 = is_array( $attrs ) && ! empty( $attrs[ self::SKIP_ATTRIBUTE ] );
				$token                  = $this->replace_with_token( $block );
				$placeholders[ $token ] = $serialized;
				$chains[ $token ]       = $chain;
				$deep_withheld          = true;

				if ( ! $marked ) {
					$unmarked[ $token ] = true;
				}

				continue;
			}

			$is_skip = ! empty( $block['attrs'][ self::SKIP_ATTRIBUTE ] );

			if ( $is_skip ) {
				$original = (string) ( $block['innerHTML'] ?? '' );
				$has_kids = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] );

				if ( $original !== '' || $has_kids ) {
					// Stash the whole serialized subtree: serialize_blocks() emits an inner
					// block per null in innerContent, so innerHTML alone drops every child.
					// An empty marked block is left in place (past the cap it is withheld).
					$serialized = serialize_block( $block );

					$token                  = $this->replace_with_token( $block );
					$placeholders[ $token ] = $serialized;
					$chains[ $token ]       = $chain;
				}

				// Don't recurse - the whole block subtree is preserved.
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->mask_blocks( $block['innerBlocks'], $placeholders, $deep_withheld, $depth + 1, array_merge( $ancestors, [ is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '' ] ), $chains, $unmarked );
			}
		}

		// The loop variable still points at the last element; a later write to
		// it would rewrite that element rather than the variable.
		unset( $block );
	}

	/**
	 * Swap one block node for a freeform placeholder node and return the token.
	 *
	 * One node in, one node out: the parent's innerContent holds one null per
	 * inner block, so collapsing nodes would walk past the end of that list.
	 *
	 * @param array<string, mixed> $block By reference - replaced with the token node.
	 * @return string The placeholder token that replaced $block.
	 */
	private function replace_with_token( array &$block ): string {
		// An HTML comment passes through providers unchanged in HTML mode.
		$token = '<!-- perflocale-skip-' . wp_generate_uuid4() . ' -->';

		// A freeform block serializes as its innerHTML alone, so the original
		// delimiters and attribute JSON never reach the provider either.
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
