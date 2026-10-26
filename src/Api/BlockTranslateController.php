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
use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Translation\MtRateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /perflocale/v1/block-translate`: machine-translate text or HTML snippets for the block editor; writes nothing.
 *
 * It still spends provider quota: the monthly character cap and the shared per-user hourly rate limit apply.
 */
final class BlockTranslateController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'block-translate';

	/**
	 * Maximum characters per text: protects the MT budget and bounds the request.
	 */
	private const MAX_INPUT_LENGTH = 50000;

	/**
	 * Maximum entries per batch: the character caps alone allow huge numbers of tiny strings, each a provider round-trip.
	 */
	private const MAX_BATCH_ENTRIES = 500;

	/**
	 * Maximum block-path depth on /from-source: far above real nesting, and bounds walk_block_path() for hostile input.
	 */
	private const MAX_BLOCK_PATH_DEPTH = 32;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// "Fill in from source": translates the same block of the source-language sibling.
		// Registered before `/block-translate` so the more specific route matches first.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/from-source',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'translate_from_source' ],
				'permission_callback' => [ $this, 'from_source_permissions_check' ],
				'args'                => [
					// Sibling post being edited; edit_post on it is checked before the source is read.
					'target_post_id' => [
						'required' => true,
						'type'     => 'integer',
					],
					// Index path of the block, e.g. [3, 1, 2]; the same indices are walked in the source post's tree.
					'block_path'     => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
					],
					// Target language slug; the source language comes from the source post.
					'target_lang'    => [
						'required' => true,
						'type'     => 'string',
					],
					// Optional attribute names to read (the editor's chain); omitted means every text attribute.
					'source_attrs'   => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => [],
					],
					// Optional single-name form of `source_attrs`.
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
					// Single-text path.
					'text'        => [
						'type'    => 'string',
						'default' => '',
					],
					// Batch path, one provider round-trip; a non-empty `texts` wins over `text`.
					'texts'       => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => [],
					],
					// Format per `texts` entry: `text` for plain attributes (alt, title), `html` (default) for rich text.
					'formats'     => [
						'type'    => 'array',
						'items'   => [
							'type' => 'string',
							'enum' => [ 'html', 'text' ],
						],
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
					// Optional: the post being edited, whose text this is.
					'post_id'     => [
						'type'    => 'integer',
						'default' => 0,
					],
				],
			]
		);
	}

	/**
	 * Permission gate for `/from-source`: the MT cap, then edit_post on `target_post_id`.
	 *
	 * The read check on the source sibling is in the handler, which resolves the sibling.
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
			// The handler returns the descriptive 400.
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

		// Batch mode for a non-empty `texts` array; anything else falls back to the single-text path.
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

		$formats = [];

		if ( $is_batch ) {
			$formats_param = $request->get_param( 'formats' );

			if ( is_array( $formats_param ) && $formats_param !== [] ) {
				$formats = array_values( array_filter( $formats_param, 'is_string' ) );

				if ( count( $formats ) !== count( $texts ) || array_diff( $formats, [ BlockAttributeSource::FORMAT_HTML, BlockAttributeSource::FORMAT_TEXT ] ) !== [] ) {
					return $this->error( 'invalid_formats', __( 'Every entry in `formats` must be "html" or "text", one per entry in `texts`.', 'perflocale' ), 400 );
				}
			}
		}

		if ( $source_lang === '' || $target_lang === '' ) {
			return $this->error( 'missing_params', __( 'source_lang and target_lang are required.', 'perflocale' ), 400 );
		}

		// The edited post: password-protected is sent unless the perflocale/mt/send_password_protected filter refuses it.
		$post_id_param = $request->get_param( 'post_id' );
		$post_id       = is_numeric( $post_id_param ) ? absint( $post_id_param ) : 0;

		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			$edited = get_post( $post_id );

			if ( $edited instanceof \WP_Post && ! TranslationService::may_send_post( $edited, 'editor' ) ) {
				return $this->error( 'password_protected', TranslationService::password_protected_skip_message(), 403 );
			}
		}

		// Unknown language codes are a 400 here, not a provider 500 the UI cannot tell from a crash.
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
			// Same language: return the input unchanged rather than an error.
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

		// Each batch entry and the batch sum are capped, so splitting cannot pass the cap.
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

			// Characters, not bytes (mb_strlen, polyfilled by core), as every other cap in the plugin.
			$total_chars = 0;

			foreach ( $texts as $i => $t ) {
				if ( mb_strlen( $t ) > self::MAX_INPUT_LENGTH ) {
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
				$total_chars += mb_strlen( $t );
			}

			// Batch sum cap is 4x the single cap: a whole post fits, a runaway client cannot drain the monthly quota.
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

			if ( mb_strlen( $text ) > self::MAX_INPUT_LENGTH ) {
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

		// Single text only: a pre_translate_lookup hit answers before TranslationService and its providers are built.
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
				// Each entry is sanitized for its format, so the response is safe to write to block attributes.
				$batch_result = $this->translate_by_format(
					$service,
					array_values( $texts ),
					$formats,
					$source_lang,
					$target_lang,
					$provider_id
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
	 * Lookup filter that lets an integration answer an MT call from its own cache.
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
	 * Walk a parsed-block tree to the position given by `$path`.
	 *
	 * [3, 1, 2] is top-level block 3, inner block 1, inner block 2; null when a step is out of range.
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
	 * Drop the whitespace entries parse_blocks() puts between blocks.
	 *
	 * The editor's block paths count real blocks only, so the server walk must too.
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
	 * Translate the same block of the source-language sibling into the target post's language.
	 *
	 * Each text attribute comes back under its own name in `attributes`. A block under "Do not translate" is
	 * returned verbatim (`source` = `kept`) with no provider call or rate-limit slot, as whole-post MT keeps it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function translate_from_source( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$target_post_id = (int) $request->get_param( 'target_post_id' );
		$block_path     = (array) $request->get_param( 'block_path' );
		$target_lang    = sanitize_key( (string) $request->get_param( 'target_lang' ) );
		$provider_id    = sanitize_key( (string) $request->get_param( 'provider' ) );

		// Attribute names are camelCase: BlockAttributeSource validates them, sanitize_key() would lower-case them.
		$source_attrs = array_values( array_filter( (array) $request->get_param( 'source_attrs' ), 'is_string' ) );
		$source_attr  = (string) $request->get_param( 'source_attr' );
		$legacy_hint  = $source_attrs === [] && $source_attr !== '';

		if ( $legacy_hint ) {
			$source_attrs = [ $source_attr ];
		}

		if ( $target_post_id <= 0 || $target_lang === '' ) {
			return $this->error( 'missing_params', __( 'target_post_id and target_lang are required.', 'perflocale' ), 400 );
		}

		// Depth cap before any other work, against hostile input.
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

		// Edit rights on this target post, not any post.
		if ( ! current_user_can( 'edit_post', $target_post_id ) ) {
			return $this->error( 'cannot_edit_post', __( 'You cannot edit this post.', 'perflocale' ), 403 );
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->get( 'settings' )->mt_enabled() ) {
			return $this->error( 'mt_disabled', __( 'Machine translation is disabled.', 'perflocale' ), 403 );
		}

		// The source post is the default-language sibling.
		$source_post = $this->find_source_sibling_post( $target_post_id );

		if ( ! $source_post instanceof \WP_Post ) {
			return $this->error( 'no_source_sibling', __( 'This post has no source-language sibling to translate from.', 'perflocale' ), 404 );
		}

		// The source's text is sent, so the caller must also be able to read the source post.
		if ( ! current_user_can( 'read_post', (int) $source_post->ID ) ) {
			return $this->error( 'cannot_read_source', __( 'You do not have permission to read the source post.', 'perflocale' ), 403 );
		}

		// The source itself is a caller error: it belongs on /block-translate.
		if ( (int) $source_post->ID === $target_post_id ) {
			return $this->error( 'is_source', __( 'Target post IS the source post; use /block-translate to translate in place.', 'perflocale' ), 400 );
		}

		// A password-protected source is sent unless the perflocale/mt/send_password_protected filter refuses it.
		if ( ! TranslationService::may_send_post( $source_post, 'editor' ) ) {
			return $this->error( 'password_protected', TranslationService::password_protected_skip_message(), 403 );
		}

		// Resolve the source post's language slug for the MT call.
		$lang_repo    = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$default_lang = $lang_repo->get_default();

		if ( ! $default_lang || empty( $default_lang->slug ) ) {
			return $this->error( 'no_default_language', __( 'No default language configured.', 'perflocale' ), 500 );
		}

		$source_lang = (string) $default_lang->slug;

		if ( $source_lang === $target_lang ) {
			// Defensive: the source post case is refused above.
			return $this->error( 'same_lang', __( 'Source and target languages match; nothing to translate.', 'perflocale' ), 400 );
		}

		// Walk the source post's block tree to the same position.
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

		$read = BlockAttributeSource::read( $matched_block, $source_attrs );

		// The single-name hint is a preference: an empty hinted attribute falls back to the block type's chain.
		if ( $legacy_hint && $read['values'] === [] ) {
			$read = BlockAttributeSource::read( $matched_block );
		}

		$values   = $read['values'];
		$provider = $provider_id !== '' ? $provider_id : (string) $plugin->get( 'settings' )->get_mt_provider();

		if ( $values === [] ) {
			if ( $read['skipped'] !== [] ) {
				return $this->error( 'unreadable_source_block', __( 'The source block\'s text could not be read for this block type.', 'perflocale' ), 422 );
			}

			return $this->error( 'empty_source_text', __( 'The corresponding source block has no text to translate.', 'perflocale' ), 422 );
		}

		$base = [
			'source_attr'    => (string) array_key_first( $values ),
			'formats'        => $read['formats'],
			'skipped_attrs'  => $read['skipped'],
			'provider'       => $provider,
			'source_post_id' => (int) $source_post->ID,
		];

		// "Do not translate" on the block or an ancestor: the source text verbatim, as whole-post MT keeps it.
		if ( BlockAttributeSource::path_is_skip_marked( $source_blocks, $block_path ) ) {
			return $this->success(
				$base + [
					'translated'  => (string) reset( $values ),
					'attributes'  => $values,
					'source'      => 'kept',
					'kept_reason' => 'do_not_translate',
				]
			);
		}

		$total_chars = 0;

		foreach ( $values as $value ) {
			$chars = mb_strlen( $value );

			if ( $chars > self::MAX_INPUT_LENGTH ) {
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

			$total_chars += $chars;
		}

		if ( $total_chars > self::MAX_INPUT_LENGTH * 4 ) {
			return $this->error(
				'batch_too_long',
				sprintf(
					/* translators: %d: total max characters across the whole batch */
					__( 'Batch total exceeds the aggregate length cap (%d characters). Split into smaller batches.', 'perflocale' ),
					self::MAX_INPUT_LENGTH * 4
				),
				413
			);
		}

		$limited = $this->enforce_rate_limit( get_current_user_id() );

		if ( $limited instanceof \WP_Error ) {
			return $limited;
		}

		// Lookup filter per value, as on the single-text path.
		$translated = [];
		$pending    = [];

		foreach ( $values as $name => $value ) {
			$tm_hit = $this->maybe_tm_lookup( $value, $source_lang, $target_lang );

			if ( $tm_hit !== null ) {
				$translated[ $name ] = $tm_hit;
			} else {
				$pending[ $name ] = $value;
			}
		}

		if ( $pending !== [] ) {
			$service = new TranslationService(
				$plugin->get( 'settings' ),
				$plugin->get( 'cache' )
			);

			$names   = array_keys( $pending );
			$formats = array_map( static fn( string $name ): string => (string) ( $read['formats'][ $name ] ?? BlockAttributeSource::FORMAT_HTML ), $names );

			try {
				$results = $this->translate_by_format( $service, array_values( $pending ), $formats, $source_lang, $target_lang, $provider_id );
			} catch ( \Throwable $e ) {
				return $this->error( 'translation_failed', $e->getMessage(), 500 );
			}

			foreach ( $names as $i => $name ) {
				// An empty result is a provider failure: never hand the editor an empty string to write over its text.
				if ( isset( $results[ $i ] ) && $results[ $i ] !== '' ) {
					$translated[ $name ] = $results[ $i ];
				}
			}
		}

		// Chain order, whichever path produced each value.
		$attributes = array_intersect_key( array_replace( $values, $translated ), $translated );

		if ( $attributes === [] ) {
			return $this->error( 'translation_failed', __( 'The translation provider returned an empty result; aborting so existing content is not overwritten.', 'perflocale' ), 500 );
		}

		$base['source_attr'] = (string) array_key_first( $attributes );

		return $this->success(
			$base + [
				'translated' => (string) reset( $attributes ),
				'attributes' => $attributes,
				'source'     => $pending === [] ? 'tm' : 'mt',
			]
		);
	}

	/**
	 * Translate a batch whose entries have different destination formats: one
	 * TranslationService call per format, results back in input order.
	 *
	 * @param TranslationService $service     Service.
	 * @param string[]           $texts       Entries, 0..n-1.
	 * @param string[]           $formats     Parallel formats (`html` | `text`); missing = `html`.
	 * @param string             $source_lang Source language.
	 * @param string             $target_lang Target language.
	 * @param string             $provider_id Provider id or '' for the configured one.
	 * @return string[] Translations, 0..n-1.
	 */
	private function translate_by_format( TranslationService $service, array $texts, array $formats, string $source_lang, string $target_lang, string $provider_id ): array {
		$groups = [];

		foreach ( $texts as $i => $text ) {
			$format                  = ( $formats[ $i ] ?? '' ) === BlockAttributeSource::FORMAT_TEXT ? BlockAttributeSource::FORMAT_TEXT : BlockAttributeSource::FORMAT_HTML;
			$groups[ $format ][ $i ] = (string) $text;
		}

		$out = array_fill( 0, count( $texts ), '' );

		foreach ( $groups as $format => $entries ) {
			$result = $service->translate_batch_texts( array_values( $entries ), $source_lang, $target_lang, $provider_id, true, $format );
			$j      = 0;

			foreach ( array_keys( $entries ) as $i ) {
				$out[ $i ] = (string) ( $result[ $j ] ?? '' );
				++$j;
			}
		}

		return $out;
	}

	/**
	 * The default-language sibling of a post, or null when there is none.
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
	 * Per-user hourly rate limit, one budget shared with MachineTranslateController.
	 *
	 * @param int $user_id Current user ID.
	 * @return \WP_Error|null
	 */
	private function enforce_rate_limit( int $user_id ): ?\WP_Error {
		// The shared MtRateLimiter policy.
		return MtRateLimiter::admit( $user_id );
	}
}
