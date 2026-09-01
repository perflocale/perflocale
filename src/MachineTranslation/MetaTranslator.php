<?php
/**
 * Machine translation of post meta fields.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Settings;
use PerfLocale\Translation\PlaceholderMasker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates a curated set of meta fields from a source post onto its
 * translation sibling — SEO titles/descriptions, ACF/MetaBox/Pods text
 * fields, and anything an addon registers on the dedicated registry filter.
 *
 * DESIGN CONTRACTS (each one is load-bearing — see docs/mt-scopes-plan.md §4):
 *
 * - The key registry is `perflocale/mt/translatable_meta_keys` — a SEPARATE,
 *   curated filter. It must NEVER be fed from get_translatable_meta_keys():
 *   that list contains structural keys (ACF repeater counts, flexible-content
 *   layouts), URLs/emails, serialized blobs, and SEO focus keywords, all of
 *   which machine translation would corrupt or mistranslate.
 * - OWNERSHIP RULE: a key is translated only when the sibling's current value
 *   is empty or byte-identical to the source value (the untouched copy from
 *   copy_post_meta). A translator-edited value is never overwritten, which
 *   also makes every re-run idempotent and re-run-safe at ZERO provider cost:
 *   an owned or unchanged value is dropped from the batch before the provider
 *   is reached, so it is never re-billed.
 * - MIRROR keys (builder layout JSON — source-owned, overwritten on every
 *   sync) are excluded at runtime, not just by list curation.
 * - Placeholder tokens (%s, {var}, %%sitename%%-style SEO template tags via
 *   the inline-HTML regex) are masked before MT and must survive; a
 *   translation that drops one is REJECTED and the source value kept.
 * - Writes are wp_slash()ed — update_post_meta unslashes internally and would
 *   otherwise strip backslashes (documented corruption class).
 * - A meta failure NEVER fails the post translation: failures land in the
 *   `_perflocale_meta_mt_errors` breadcrumb + an action, and the loop moves on.
 */
final class MetaTranslator {

	/**
	 * Breadcrumb meta key for per-post meta-MT failures (mirrors
	 * _perflocale_meta_copy_errors from the copy path).
	 */
	public const ERRORS_META_KEY = '_perflocale_meta_mt_errors';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Translation service.
	 *
	 * @var TranslationService
	 */
	private readonly TranslationService $service;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings Plugin settings.
	 * @param TranslationService $service  Translation service (shares provider
	 *                                     selection, the monthly character cap
	 *                                     and usage accounting with post MT).
	 */
	public function __construct( Settings $settings, TranslationService $service ) {
		$this->settings = $settings;
		$this->service  = $service;
	}

	/**
	 * Resolve the MT-able meta keys for a post via the curated registry.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Source post ID (0 = type-level resolution, used
	 *                          by the cost estimator; addons may expand
	 *                          per-post keys like repeater rows when > 0).
	 * @return string[]
	 */
	public function get_mt_meta_keys( string $post_type, int $post_id = 0 ): array {
		/**
		 * The curated machine-translatable meta-key registry.
		 *
		 * Register ONLY leaf text values a human translator would rewrite:
		 * plain text, textarea, and rich-text fields. Never structural keys
		 * (counts, layouts), URLs/emails, serialized arrays, or SEO focus
		 * keywords — those belong on perflocale/translatable_meta_keys (the
		 * seed/copy list), not here.
		 *
		 * @hook perflocale/mt/translatable_meta_keys
		 *
		 * @param string[] $keys      Meta keys to machine-translate.
		 * @param string   $post_type Post type being translated.
		 * @param int      $post_id   Source post ID (0 for type-level queries).
		 */
		$keys = (array) apply_filters( 'perflocale/mt/translatable_meta_keys', [], $post_type, $post_id );

		// Strings only: ints/floats/bools from a sloppy filter would otherwise
		// become junk meta keys ('123', '1'), and an array entry would emit an
		// Array-to-string warning.
		$keys = array_values( array_unique( array_filter( $keys, static fn( $k ): bool => is_string( $k ) && trim( $k ) !== '' ) ) );

		if ( $keys === [] ) {
			return [];
		}

		// Runtime mirror-set exclusion: mirror keys are source-owned (deleted +
		// re-copied on every sync), so translating them would be overwritten
		// AND could hand builder JSON to a text provider. Defense in depth on
		// top of list curation.
		$sync_fields = (array) $this->settings->get( 'sync_fields', [] );
		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', $sync_fields, $post_type );

		return array_values( array_diff( $keys, array_map( 'strval', $mirror ) ) );
	}

	/**
	 * Machine-translate the registered meta keys from a source post onto its
	 * translation sibling.
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID (the write target).
	 * @param string $source_lang    Source language slug.
	 * @param string $target_lang    Target language slug.
	 * @param string $provider_id    Provider override ('' = configured default).
	 * @return array{translated:int, skipped:int, failed:int, errors:array<int,string>}
	 */
	public function translate_post_meta( int $source_id, int $translation_id, string $source_lang, string $target_lang, string $provider_id = '' ): array {
		$result = [
			'translated' => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'errors'     => [],
		];

		$post_type = (string) get_post_type( $source_id );

		if ( $post_type === '' || $translation_id <= 0 || $source_id === $translation_id ) {
			return $result;
		}

		$keys = $this->get_mt_meta_keys( $post_type, $source_id );

		if ( $keys === [] ) {
			return $result;
		}

		// Collect the translatable (key, source value) pairs under the
		// ownership rule. Only single-value string meta qualifies — array or
		// serialized values are structural by definition here.
		$to_translate = [];

		foreach ( $keys as $key ) {
			$source_val = get_post_meta( $source_id, $key, true );

			if ( ! is_string( $source_val ) || trim( $source_val ) === '' ) {
				++$result['skipped'];
				continue;
			}

			$target_val = get_post_meta( $translation_id, $key, true );

			if ( is_string( $target_val ) && $target_val !== '' && $target_val !== $source_val ) {
				// Translator-owned value — never overwrite.
				++$result['skipped'];
				continue;
			}

			$to_translate[ $key ] = $source_val;
		}

		if ( $to_translate === [] ) {
			// Nothing to do and nothing failed — clear any stale breadcrumb
			// from a previous failure (every value is now translator-owned
			// or empty; the earlier outage is resolved from this post's view).
			delete_post_meta( $translation_id, self::ERRORS_META_KEY );

			return $result;
		}

		// Monthly-cap check for the whole meta batch (translate_post's own cap
		// check covers only title/content/excerpt). Fail soft: record + skip.
		$estimated = 0;
		foreach ( $to_translate as $v ) {
			$estimated += mb_strlen( $v );
		}

		if ( $this->service->would_exceed_limit( $estimated ) ) {
			$result['failed']   = count( $to_translate );
			$msg                = __( 'Meta fields skipped: monthly machine-translation character limit reached.', 'perflocale' );
			$result['errors'][] = $msg;
			$this->record_errors( $translation_id, [ $msg ] );

			/** This action is documented below. */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $translation_id, array_keys( $to_translate ), $msg );

			return $result;
		}

		// Mask placeholders per value (SEO template tags like %%sitename%%,
		// printf tokens, {brace} vars) so the provider can't mangle them.
		$masked_by_key = [];
		foreach ( $to_translate as $key => $val ) {
			// mask() returns [masked_text, placeholders].
			$masked_by_key[ $key ] = PlaceholderMasker::mask( $val );
		}

		$ordered_keys = array_keys( $masked_by_key );

		// Group the batch by destination format so a plain-text meta key (SEO
		// title/description, an ACF text field) is translated in the provider's
		// TEXT mode and sanitised as text, while HTML-bearing keys (an ACF
		// wysiwyg field) keep HTML mode + the allowlist sanitiser. Default is
		// 'html' so an undeclared / third-party key behaves exactly as before —
		// a key is only routed to text when an addon affirmatively declares it.
		$keys_by_format = [];
		foreach ( $ordered_keys as $key ) {
			/**
			 * Filter the machine-translation destination format for a meta key.
			 *
			 * Return 'text' for plain-text meta (SEO title/description, plain
			 * custom fields) so the provider is called in text mode and the
			 * result is not entity-escaped; 'html' (default) for markup-bearing
			 * meta. Unknown keys stay 'html' — the historical behaviour.
			 *
			 * @hook perflocale/mt/meta_key_format
			 * @param string $format    'html' (default) or 'text'.
			 * @param string $key       Meta key.
			 * @param string $post_type Source post type.
			 */
			$fmt = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, $post_type );
			$fmt = ( 'text' === $fmt ) ? 'text' : 'html';

			$keys_by_format[ $fmt ][] = $key;
		}

		// One provider round-trip PER FORMAT (typically 1-2). Results are
		// scattered back to a $translated array indexed to $ordered_keys, so the
		// restore/placeholder loop below is unchanged.
		$translated = array_fill( 0, count( $ordered_keys ), '' );
		$key_index  = array_flip( $ordered_keys );

		try {
			foreach ( $keys_by_format as $fmt => $group_keys ) {
				$group_batch = [];
				foreach ( $group_keys as $key ) {
					$group_batch[] = $masked_by_key[ $key ][0];
				}

				// translate_batch_texts brings the monthly-cap gate, the
				// count-mismatch hard-fail, and format-appropriate sanitising.
				$group_out = $this->service->translate_batch_texts( $group_batch, $source_lang, $target_lang, $provider_id, false, $fmt );

				foreach ( $group_keys as $gi => $key ) {
					$translated[ $key_index[ $key ] ] = $group_out[ $gi ] ?? '';
				}
			}
		} catch ( \Throwable $e ) {
			$result['failed']   = count( $to_translate );
			$result['errors'][] = $e->getMessage();
			$this->record_errors( $translation_id, [ $e->getMessage() ] );

			/** This action is documented below (fires per failed batch too). */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $translation_id, array_keys( $to_translate ), $e->getMessage() );

			return $result;
		}

		$errors = [];

		foreach ( $ordered_keys as $i => $key ) {
			$out = isset( $translated[ $i ] ) ? (string) $translated[ $i ] : '';

			if ( trim( $out ) === '' ) {
				++$result['failed'];
				$errors[] = sprintf( 'Empty translation for meta key "%s"; source value kept.', $key );
				continue;
			}

			$restored = PlaceholderMasker::restore( $out, $masked_by_key[ $key ][1] );

			// Integrity gate: a translation that dropped a placeholder would
			// ship a broken SEO template / format string. Keep the source.
			if ( ! PlaceholderMasker::preserves_placeholders( $to_translate[ $key ], $restored ) ) {
				++$result['failed'];
				$errors[] = sprintf( 'Placeholder lost in meta key "%s"; source value kept.', $key );
				continue;
			}

			// wp_slash: update_post_meta unslashes internally (documented
			// backslash-corruption class without it).
			update_post_meta( $translation_id, $key, wp_slash( $restored ) );
			++$result['translated'];

		}

		if ( $errors === [] && $result['failed'] === 0 ) {
			// A fully clean run clears any stale breadcrumb from a previous
			// failure, so operators don't keep seeing a resolved outage.
			delete_post_meta( $translation_id, self::ERRORS_META_KEY );
		}

		if ( $errors !== [] ) {
			$result['errors'] = $errors;
			$this->record_errors( $translation_id, $errors );

			/**
			 * Fires when one or more meta values failed to machine-translate.
			 * The post translation itself is unaffected.
			 *
			 * @hook perflocale/mt/meta_translate_failed
			 *
			 * @param int      $source_id      Source post ID.
			 * @param int      $translation_id Translation post ID.
			 * @param string[] $keys           Keys involved in the failure.
			 * @param string   $message        Human-readable summary.
			 */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $translation_id, $ordered_keys, implode( ' | ', $errors ) );
		}

		return $result;
	}

	/**
	 * Persist the failure breadcrumb on the translation post.
	 *
	 * @param int      $translation_id Translation post ID.
	 * @param string[] $errors         Error strings.
	 * @return void
	 */
	private function record_errors( int $translation_id, array $errors ): void {
		update_post_meta( $translation_id, self::ERRORS_META_KEY, wp_slash( array_map( 'sanitize_text_field', $errors ) ) );
	}
}
