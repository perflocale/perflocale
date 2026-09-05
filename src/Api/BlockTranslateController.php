<?php
/**
 * REST endpoint for block-level / inline machine translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Concurrency\Lock;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Translation\MtRateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /perflocale/v1/block-translate` - translate a single text / HTML
 * snippet with the configured MT provider.
 *
 * Used by the Gutenberg block-toolbar "Translate with MT" action, but
 * otherwise generic: any caller can POST a text + source + target and
 * get a translation back without side effects on any post.
 *
 * Unlike {@see MachineTranslateController::translate} this does NOT touch
 * the translation-group tables or persist anything about a post. It is a
 * provider wrapper, but still a provider-bound spender: the monthly
 * character cap is enforced inside the service before the provider call,
 * and the same per-user hourly rate-limit cap applies for budget safety.
 */
final class BlockTranslateController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'block-translate';

	/**
	 * Maximum input length - protects MT budget + caps request body
	 * size. Block content shouldn't realistically exceed this.
	 */
	private const MAX_INPUT_LENGTH = 50000;

	/**
	 * Maximum number of entries accepted in one batch. The per-entry and
	 * aggregate-character caps alone let a request carry hundreds of
	 * thousands of tiny strings, and each batch miss becomes one sequential
	 * provider round-trip inside a single PHP worker. A full post's
	 * translatable leaves stay well under this bound.
	 */
	private const MAX_BATCH_ENTRIES = 500;

	/**
	 * Maximum block-path depth accepted on /from-source. Real Gutenberg
	 * trees rarely nest beyond 6-8 levels (group → columns → column → block);
	 * a cap of 32 is well above any realistic post and well below anything
	 * that would let a malicious client force pathological recursion in
	 * walk_block_path().
	 */
	private const MAX_BLOCK_PATH_DEPTH = 32;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Sibling-aware "Fill in from source" endpoint. Resolves
		// the corresponding block in the post's source-language sibling and
		// translates it into the current sibling's language. Registered
		// BEFORE the catch-all `/block-translate` so the more-specific
		// suffix wins the route match.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/from-source',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'translate_from_source' ],
				'permission_callback' => [ $this, 'from_source_permissions_check' ],
				'args'                => [
					// Sibling post being edited. The handler verifies the
					// caller can edit it (`edit_post` cap) before fetching
					// source content from the corresponding source post.
					'target_post_id' => [
						'required' => true,
						'type'     => 'integer',
					],
					// Position path of the block in the sibling's tree:
					// [3, 1, 2] = top-level index 3, then inner index 1,
					// then inner index 2. Server walks the SAME indices in
					// the source post's tree to find the corresponding block.
					'block_path'     => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
					],
					// Sibling's language slug (target). Source language is
					// derived server-side from the source post.
					'target_lang'    => [
						'required' => true,
						'type'     => 'string',
					],
					// Optional: which attribute to extract from the source
					// block. Server will fall through a chain (content / text /
					// value / caption / summary / alt / title / placeholder)
					// when omitted, picking the longest-text candidate.
					'source_attr'    => [
						'type'    => 'string',
						'default' => '',
					],
					'provider'       => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'translate' ],
				'permission_callback' => [ $this, 'mt_permissions_check' ],
				'args'                => [
					// Single-text path (legacy + most JS callers).
					'text'        => [
						'type'    => 'string',
						'default' => '',
					],
					// Batch path: array of strings, all translated
					// in one provider round-trip. When `texts` is non-empty
					// it takes precedence over `text`.
					'texts'       => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => [],
					],
					'source_lang' => [
						'required' => true,
						'type'     => 'string',
					],
					'target_lang' => [
						'required' => true,
						'type'     => 'string',
					],
					'provider'    => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);
	}

	/**
	 * Per-post permission gate for `/from-source`. Runs the MT cap check
	 * first, then `edit_post` on the body's `target_post_id`. The full
	 * read-cap check on the SOURCE sibling stays in the handler — the
	 * sibling resolution requires DB work that this gate intentionally
	 * doesn't replicate.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function from_source_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->mt_permissions_check( $request );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$target_post_id = (int) $request->get_param( 'target_post_id' );
		if ( $target_post_id <= 0 ) {
			// Defer to the handler's 400 missing_params for descriptive errors.
			return true;
		}

		if ( ! current_user_can( 'edit_post', $target_post_id ) ) {
			return new \WP_Error(
				'cannot_edit_post',
				__( 'You cannot edit this post.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function translate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$text        = (string) $request->get_param( 'text' );
		$texts_param = $request->get_param( 'texts' );
		$source_lang = sanitize_key( (string) $request->get_param( 'source_lang' ) );
		$target_lang = sanitize_key( (string) $request->get_param( 'target_lang' ) );
		$provider_id = sanitize_key( (string) $request->get_param( 'provider' ) );

		// Detect batch mode: client supplied an array of texts. Each input
		// must be a string; a non-array `texts` is treated as missing so
		// the controller can fall back to the single-text path below.
		$is_batch = is_array( $texts_param ) && count( $texts_param ) > 0;
		$texts    = [];

		if ( $is_batch ) {
			foreach ( $texts_param as $i => $t ) {
				if ( ! is_string( $t ) ) {
					return $this->error( 'invalid_texts', __( 'Every entry in `texts` must be a string.', 'perflocale' ), 400 );
				}
				$texts[ $i ] = $t;
			}
		}

		if ( $source_lang === '' || $target_lang === '' ) {
			return $this->error( 'missing_params', __( 'source_lang and target_lang are required.', 'perflocale' ), 400 );
		}

		// Validate both lang codes against active languages up front so an
		// invalid code returns HTTP 400 (client error) instead of a
		// provider-side 500 that surfaces as a generic "translation failed"
		// the admin UI can't distinguish from a real server crash.
		$router       = Plugin::get_instance()->get( 'router' );
		$active       = $router->get_active_languages();
		$active_codes = [];

		foreach ( (array) $active as $lang ) {
			$slug = (string) ( $lang->slug ?? '' );

			if ( $slug !== '' ) {
				$active_codes[ $slug ] = true;
			}

			$locale = strtolower( str_replace( '_', '-', (string) ( $lang->locale ?? '' ) ) );

			if ( $locale !== '' ) {
				$active_codes[ $locale ] = true;
			}
		}

		if ( $active_codes !== [] ) {
			if ( ! isset( $active_codes[ $source_lang ] ) ) {
				return $this->error( 'invalid_source_lang', __( 'Unknown source language.', 'perflocale' ), 400 );
			}

			if ( ! isset( $active_codes[ $target_lang ] ) ) {
				return $this->error( 'invalid_target_lang', __( 'Unknown target language.', 'perflocale' ), 400 );
			}
		}

		if ( $source_lang === $target_lang ) {
			// No-op but return gracefully - the UI might pre-select the
			// current language by mistake; save the caller from handling
			// an error here.
			if ( $is_batch ) {
				return $this->success(
					[
						'translated' => array_values( $texts ),
						'provider'   => $provider_id,
					]
				);
			}

			return $this->success(
				[
					'translated' => $text,
					'provider'   => $provider_id,
				]
			);
		}

		// Validate input lengths up-front. Single-text path: simple cap. Batch
		// path: each entry must individually fit AND the SUM must too, so a
		// single request can't slip past the cap by splitting.
		if ( $is_batch ) {
			if ( count( $texts ) > self::MAX_BATCH_ENTRIES ) {
				return $this->error(
					'batch_too_many',
					sprintf(
						/* translators: %d: max entries per batch */
						__( 'Batch exceeds the maximum number of entries (%d). Split into smaller batches.', 'perflocale' ),
						self::MAX_BATCH_ENTRIES
					),
					413
				);
			}

			$total_chars = 0;

			foreach ( $texts as $i => $t ) {
				if ( strlen( $t ) > self::MAX_INPUT_LENGTH ) {
					return $this->error(
						'text_too_long',
						sprintf(
							/* translators: 1: index in batch, 2: max characters */
							__( 'Batch entry #%1$d exceeds the per-text length cap (%2$d characters).', 'perflocale' ),
							$i,
							self::MAX_INPUT_LENGTH
						),
						413
					);
				}
				$total_chars += strlen( $t );
			}

			// Batch sum cap = 4× single cap. Conservative enough that one
			// "translate entire post" still fits, but a runaway client can't
			// drain the whole monthly MT quota in a single request.
			$batch_total_cap = self::MAX_INPUT_LENGTH * 4;

			if ( $total_chars > $batch_total_cap ) {
				return $this->error(
					'batch_too_long',
					sprintf(
						/* translators: %d: total max characters across the whole batch */
						__( 'Batch total exceeds the aggregate length cap (%d characters). Split into smaller batches.', 'perflocale' ),
						$batch_total_cap
					),
					413
				);
			}
		} else {
			$text_trimmed = trim( $text );

			if ( $text_trimmed === '' ) {
				return $this->error( 'empty_text', __( 'Text to translate is empty.', 'perflocale' ) );
			}

			if ( strlen( $text ) > self::MAX_INPUT_LENGTH ) {
				return $this->error(
					'text_too_long',
					sprintf(
						/* translators: %d: max characters */
						__( 'Text exceeds the block-translate length cap (%d characters).', 'perflocale' ),
						self::MAX_INPUT_LENGTH
					),
					413
				);
			}
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->get( 'settings' )->mt_enabled() ) {
			return $this->error( 'mt_disabled', __( 'Machine translation is disabled.', 'perflocale' ), 403 );
		}

		$limited = $this->enforce_rate_limit( get_current_user_id() );

		if ( $limited instanceof \WP_Error ) {
			return $limited;
		}

		// 2.3 Translation Memory pre-flight (single-text path only): an exact
		// match skips the MT call. Batch stays on MT (a multi-leaf walk rarely
		// has every leaf hit TM). TranslationService is instantiated BELOW, so
		// a TM hit short-circuits without constructing five providers + running
		// the providers filter on the common cached path.
		if ( ! $is_batch ) {
			$tm_hit = $this->maybe_tm_lookup( $text, $source_lang, $target_lang );

			if ( $tm_hit !== null ) {
				return $this->success(
					[
						'translated' => $tm_hit,
						'provider'   => $provider_id !== '' ? $provider_id : $plugin->get( 'settings' )->get_mt_provider(),
						'source'     => 'tm',
					]
				);
			}
		}

		$service = new TranslationService(
			$plugin->get( 'settings' ),
			$plugin->get( 'cache' )
		);

		try {
			if ( $is_batch ) {
				// translate_batch_texts wraps Provider::translate_batch with
				// per-entry glossary + sanitize_mt_html so the response is
				// safe to write to block attributes without further escaping.
				$batch_result = $service->translate_batch_texts(
					array_values( $texts ),
					$source_lang,
					$target_lang,
					$provider_id,
					true
				);
			} else {
				$translated = $service->translate_text( $text, $source_lang, $target_lang, $provider_id, true );
			}
		} catch ( \Throwable $e ) {
			return $this->error( 'translation_failed', $e->getMessage(), 500 );
		}

		if ( $is_batch ) {
			return $this->success(
				[
					'translated' => $batch_result,
					'provider'   => $provider_id !== '' ? $provider_id : $plugin->get( 'settings' )->get_mt_provider(),
					'source'     => 'mt',
				]
			);
		}

		return $this->success(
			[
				'translated' => $translated,
				'provider'   => $provider_id !== '' ? $provider_id : $plugin->get( 'settings' )->get_mt_provider(),
				'source'     => 'mt',
			]
		);
	}

	/**
	/**
	 * Translation-memory pre-flight hook point.
	 *
	 * The bundled translation memory was removed; this remains as the single
	 * place an integration can short-circuit an MT call with its own cached
	 * translation. Return a non-empty string from the filter to use it
	 * instead of calling the provider.
	 *
	 * @return string|null Translation to use, or null to call the provider.
	 */
	private function maybe_tm_lookup( string $text, string $source_lang, string $target_lang ): ?string {
		/**
		 * Short-circuit an MT provider call with a locally-cached translation.
		 *
		 * @hook perflocale/mt/pre_translate_lookup
		 *
		 * @param string|null $translation Null by default (call the provider).
		 * @param string      $text        Source text.
		 * @param string      $source_lang Source language slug.
		 * @param string      $target_lang Target language slug.
		 */
		$hit = apply_filters( 'perflocale/mt/pre_translate_lookup', null, $text, $source_lang, $target_lang );

		return is_string( $hit ) && $hit !== '' ? $hit : null;
	}

	/**
	 * Server-side text-attribute chain. Mirrors the JS `textAttrChain`
	 * priority order so server-side block walks pick the same attribute
	 * the client would have picked. Used by `translate_from_source` to
	 * extract text from the source post's corresponding block.
	 *
	 * MUST stay in sync with `textAttrChain()` in
	 * assets/js/block-toolbar.js — both sides walk the same per-block
	 * chain server- and client-side. Drift = sibling fill-from-source
	 * picking different attributes between client hint and server
	 * resolution. tests/test-attr-chain-parity.php asserts the two match.
	 *
	 * @return string[] Attribute names in priority order.
	 */
	private function text_attr_chain( string $block_name ): array {
		$per_block = [
			'core/quote'        => [ 'value' ],
			'core/pullquote'    => [ 'value', 'citation' ],
			'core/button'       => [ 'text' ],
			'core/details'      => [ 'summary' ],
			'core/image'        => [ 'caption', 'alt', 'title' ],
			'core/embed'        => [ 'caption' ],
			'core/audio'        => [ 'caption' ],
			'core/video'        => [ 'caption' ],
			'core/post-excerpt' => [ 'excerpt' ],
		];

		return $per_block[ $block_name ] ?? [ 'content', 'text', 'value' ];
	}

	/**
	 * Walk a parsed-block tree to the position given by `$path`.
	 *
	 * `$path` is an array of integers: [3, 1, 2] means top-level block 3,
	 * then inner block 1, then inner block 2. Returns null if any step in
	 * the path is out of range (the source post has been restructured).
	 *
	 * @param array<int, array<string, mixed>> $blocks Result of parse_blocks().
	 * @param int[]                            $path Index path from root to target.
	 * @return array<string, mixed>|null
	 */
	private function walk_block_path( array $blocks, array $path ): ?array {
		$node    = null;
		$current = $this->filter_real_blocks( $blocks );

		foreach ( $path as $idx ) {
			$idx = (int) $idx;

			if ( $idx < 0 || ! isset( $current[ $idx ] ) ) {
				return null;
			}

			$node    = $current[ $idx ];
			$current = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] )
				? $this->filter_real_blocks( $node['innerBlocks'] )
				: [];
		}

		return $node;
	}

	/**
	 * Strip the empty whitespace-only entries `parse_blocks()` interleaves
	 * between real blocks (an empty-string `blockName` entry between every
	 * pair of adjacent blocks).
	 *
	 * The editor's `wp.data.select('core/block-editor').getBlocks()` returns
	 * ONLY real blocks - no whitespace placeholders. The JS-computed
	 * position path therefore counts only real blocks, and the server-side
	 * walk must do the same or every nested-block path will be off by N
	 * (where N = number of preceding whitespace gaps).
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>> Re-indexed (0..n-1) array of real blocks.
	 */
	private function filter_real_blocks( array $blocks ): array {
		$out = [];

		foreach ( $blocks as $b ) {
			$name = $b['blockName'] ?? null;

			if ( $name === null || $name === '' ) {
				continue;
			}

			$out[] = $b;
		}

		return $out;
	}

	/**
	 * Extract the longest text candidate from a parsed block.
	 *
	 * Tries the per-block attribute chain first; falls back to the block's
	 * inner HTML for blocks (like core/paragraph) where the persisted text
	 * lives in the rendered HTML rather than an attribute.
	 *
	 * Returns [ $text, $attr ] where $attr is the attribute name the value
	 * was read from, or 'innerHTML' for the HTML fallback. Empty string
	 * for both when the block has no text.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function extract_block_text( array $block, string $hint_attr = '' ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

		// Caller hint wins when it points to a non-empty value.
		if ( $hint_attr !== '' && isset( $attrs[ $hint_attr ] ) && is_string( $attrs[ $hint_attr ] ) && $attrs[ $hint_attr ] !== '' ) {
			return [ $attrs[ $hint_attr ], $hint_attr ];
		}

		$chain     = $this->text_attr_chain( (string) ( $block['blockName'] ?? '' ) );
		$best      = '';
		$best_attr = '';

		foreach ( $chain as $attr ) {
			$v = $attrs[ $attr ] ?? null;

			if ( is_string( $v ) && strlen( $v ) > strlen( $best ) ) {
				$best      = $v;
				$best_attr = $attr;
			}
		}

		// Fallback to innerHTML when no attribute carried text. core/paragraph
		// and core/heading store their content in innerHTML on the server side
		// (Gutenberg's serializer puts the text between block delimiters).
		if ( $best === '' ) {
			$inner         = (string) ( $block['innerHTML'] ?? '' );
			$inner_trimmed = trim( $inner );

			if ( $inner_trimmed !== '' ) {
				return [ $inner_trimmed, 'innerHTML' ];
			}
		}

		return [ $best, $best_attr ];
	}

	/**
	 * Translate the corresponding block in this post's SOURCE sibling and
	 * return the translation in the target post's language.
	 *
	 * Use case: editing a French sibling that's still mostly English, click
	 * "Fill in from source" on a paragraph → endpoint fetches the EN source
	 * post's same-position paragraph → translates EN to FR → returns the FR
	 * text for the JS to write back.
	 *
	 * Validation:
	 *  - Caller has `edit_post` for `target_post_id`
	 *  - target_post_id resolves to a post with a translation group
	 *  - The translation group has a sibling in the default (source) language
	 *  - The source-sibling's block tree has a node at `block_path`
	 *  - The matched source block has translatable text
	 *
	 * Errors:
	 *  - 403 `cannot_edit_post` (auth), 404 `no_source_sibling` /
	 *    `block_not_found_in_source`, 422 `empty_source_text` for the
	 *    "block has no text" case (rare in practice).
	 */
	public function translate_from_source( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$target_post_id = (int) $request->get_param( 'target_post_id' );
		$block_path     = (array) $request->get_param( 'block_path' );
		$target_lang    = sanitize_key( (string) $request->get_param( 'target_lang' ) );
		$source_attr    = sanitize_key( (string) $request->get_param( 'source_attr' ) );
		$provider_id    = sanitize_key( (string) $request->get_param( 'provider' ) );

		if ( $target_post_id <= 0 || $target_lang === '' ) {
			return $this->error( 'missing_params', __( 'target_post_id and target_lang are required.', 'perflocale' ), 400 );
		}

		// Cap block_path depth before any other work — protects walk_block_path()
		// from pathological recursion and bounds the JSON body size we'll
		// process for malicious / malformed clients.
		if ( count( $block_path ) > self::MAX_BLOCK_PATH_DEPTH ) {
			return $this->error(
				'block_path_too_deep',
				sprintf(
					/* translators: %d: max nesting depth */
					__( 'Block path exceeds the maximum nesting depth (%d).', 'perflocale' ),
					self::MAX_BLOCK_PATH_DEPTH
				),
				400
			);
		}

		// Per-block-attribute auth - more granular than `edit_posts`.
		// Confirms the caller has rights for THIS specific target post.
		if ( ! current_user_can( 'edit_post', $target_post_id ) ) {
			return $this->error( 'cannot_edit_post', __( 'You cannot edit this post.', 'perflocale' ), 403 );
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->get( 'settings' )->mt_enabled() ) {
			return $this->error( 'mt_disabled', __( 'Machine translation is disabled.', 'perflocale' ), 403 );
		}

		// Find the source post (the sibling in the default language).
		$source_post = $this->find_source_sibling_post( $target_post_id );

		if ( ! $source_post instanceof \WP_Post ) {
			return $this->error( 'no_source_sibling', __( 'This post has no source-language sibling to translate from.', 'perflocale' ), 404 );
		}

		// Read-cap on the SOURCE post. `edit_post` on the target alone is not
		// enough — a user with edit rights on a translation but not on its
		// private/draft source could otherwise exfiltrate source content
		// through this endpoint. This denies that even though it's a sibling
		// the caller is "logically related to".
		if ( ! current_user_can( 'read_post', (int) $source_post->ID ) ) {
			return $this->error( 'cannot_read_source', __( 'You do not have permission to read the source post.', 'perflocale' ), 403 );
		}

		// If editing the source itself was somehow routed here, that's a
		// caller error - the JS should have used /block-translate instead.
		if ( (int) $source_post->ID === $target_post_id ) {
			return $this->error( 'is_source', __( 'Target post IS the source post; use /block-translate to translate in place.', 'perflocale' ), 400 );
		}

		// Resolve the source post's language slug for the MT call.
		$lang_repo    = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$default_lang = $lang_repo->get_default();

		if ( ! $default_lang || empty( $default_lang->slug ) ) {
			return $this->error( 'no_default_language', __( 'No default language configured.', 'perflocale' ), 500 );
		}

		$source_lang = (string) $default_lang->slug;

		if ( $source_lang === $target_lang ) {
			// Editing the source post (already handled above), but defensive.
			return $this->error( 'same_lang', __( 'Source and target languages match; nothing to translate.', 'perflocale' ), 400 );
		}

		// Parse the source post's content into a block tree, then walk the
		// position path to find the corresponding block.
		if ( ! function_exists( 'parse_blocks' ) ) {
			return $this->error( 'parse_blocks_missing', __( 'Block parser unavailable.', 'perflocale' ), 500 );
		}

		$source_blocks = parse_blocks( $source_post->post_content );
		$matched_block = $this->walk_block_path( $source_blocks, $block_path );

		if ( $matched_block === null ) {
			return $this->error(
				'block_not_found_in_source',
				__( 'No matching block at this position in the source post. The source post structure may have diverged from this sibling.', 'perflocale' ),
				404
			);
		}

		// Extract the source block's text. Caller may pass `source_attr` to
		// pin to a specific attribute (when the JS already knows which one
		// it needs); otherwise the chain heuristic picks.
		[ $source_text, $picked_attr ] = $this->extract_block_text( $matched_block, $source_attr );

		if ( $source_text === '' ) {
			return $this->error( 'empty_source_text', __( 'The corresponding source block has no text to translate.', 'perflocale' ), 422 );
		}

		if ( strlen( $source_text ) > self::MAX_INPUT_LENGTH ) {
			return $this->error(
				'text_too_long',
				sprintf(
					/* translators: %d: max characters */
					__( 'Source block exceeds the per-translate length cap (%d characters).', 'perflocale' ),
					self::MAX_INPUT_LENGTH
				),
				413
			);
		}

		$limited = $this->enforce_rate_limit( get_current_user_id() );

		if ( $limited instanceof \WP_Error ) {
			return $limited;
		}

		// TM pre-flight — same toggle/filter as the regular
		// translate path. This is a logical extension: when the user clicks
		// "Fill from source" on a phrase that's been translated before,
		// don't waste MT quota.
		$tm_hit = $this->maybe_tm_lookup( $source_text, $source_lang, $target_lang );

		if ( $tm_hit !== null ) {
			return $this->success(
				[
					'translated'     => $tm_hit,
					'provider'       => $provider_id !== '' ? $provider_id : $plugin->get( 'settings' )->get_mt_provider(),
					'source'         => 'tm',
					'source_attr'    => $picked_attr,
					'source_post_id' => (int) $source_post->ID,
				]
			);
		}

		$service = new TranslationService(
			$plugin->get( 'settings' ),
			$plugin->get( 'cache' )
		);

		try {
			$translated = $service->translate_text( $source_text, $source_lang, $target_lang, $provider_id, true );
		} catch ( \Throwable $e ) {
			return $this->error( 'translation_failed', $e->getMessage(), 500 );
		}

		return $this->success(
			[
				'translated'     => $translated,
				'provider'       => $provider_id !== '' ? $provider_id : $plugin->get( 'settings' )->get_mt_provider(),
				'source'         => 'mt',
				'source_attr'    => $picked_attr,
				'source_post_id' => (int) $source_post->ID,
			]
		);
	}

	/**
	 * Resolve the source-language sibling of a target post.
	 *
	 * Looks up the post's translation group, iterates linked posts, returns
	 * the one whose language is the default (source) language. Returns null
	 * when the post has no group, no source sibling, or the source post no
	 * longer exists in WP.
	 */
	private function find_source_sibling_post( int $target_post_id ): ?\WP_Post {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return null;
		}

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$default   = $lang_repo->get_default();

		if ( ! $default || empty( $default->id ) ) {
			return null;
		}

		$default_id = (int) $default->id;

		try {
			$group_repo   = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
			$translations = $group_repo->get_translations( $target_post_id, \PerfLocale\Enum\ObjectType::Post );

			if ( ! is_array( $translations ) || empty( $translations ) ) {
				return null;
			}

			foreach ( $translations as $link ) {
				if ( (int) ( $link->language_id ?? 0 ) !== $default_id ) {
					continue;
				}

				$source_id = (int) ( $link->object_id ?? 0 );

				if ( $source_id <= 0 ) {
					continue;
				}

				$post = get_post( $source_id );

				if ( $post instanceof \WP_Post ) {
					return $post;
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Per-user hourly rate limit - mirrors
	 * MachineTranslateController::enforce_rate_limit so the two
	 * endpoints share one budget under the `perflocale/mt/rate_limit`
	 * filter.
	 *
	 * @param int $user_id Current user ID.
	 * @return \WP_Error|null
	 */
	private function enforce_rate_limit( int $user_id ): ?\WP_Error {
		// Delegates to the shared policy — see MachineTranslateController. The
		// two copies had to agree on transient keys and lock name to share a
		// budget at all; now they cannot disagree.
		return MtRateLimiter::admit( $user_id );
	}
}
