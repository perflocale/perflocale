<?php
/**
 * Machine translation of post meta fields.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Settings;
use PerfLocale\Translation\ContentSync;
use PerfLocale\Translation\PlaceholderMasker;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine-translates a curated set of meta fields (SEO, ACF / Meta Box / Pods text, add-on keys) from a source post
 * onto its translation, only where the translation still holds the source's value or nothing.
 * Contracts: docs-ai/11-code-rationale.md, "MetaTranslator: design contracts".
 */
final class MetaTranslator {

	/**
	 * Meta key of a post's meta machine-translation failures (as _perflocale_meta_copy_errors on the copy path).
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
	 * @param TranslationService $service  Translation service (provider choice, monthly cap and usage shared with posts).
	 */
	public function __construct( Settings $settings, TranslationService $service ) {
		$this->settings = $settings;
		$this->service  = $service;
	}

	/**
	 * Resolve the MT-able meta keys for a post via the curated registry.
	 *
	 * @param string $post_type      Post type.
	 * @param int    $post_id        Source post ID (0 = type-level resolution,
	 *                               used by the cost estimator; addons may
	 *                               expand per-post keys like repeater rows
	 *                               when > 0).
	 * @param int    $translation_id Translation post ID the values are written
	 *                               to (0 when unknown).
	 * @return string[]
	 */
	public function get_mt_meta_keys( string $post_type, int $post_id = 0, int $translation_id = 0 ): array {
		/**
		 * The machine-translatable meta keys: leaf text a translator would rewrite (text, textarea, rich text). Never
		 * structural keys, URLs, emails, serialized values or SEO focus keywords (those go on
		 * perflocale/translatable_meta_keys).
		 *
		 * @hook perflocale/mt/translatable_meta_keys
		 *
		 * @param string[] $keys           Meta keys to machine-translate.
		 * @param string   $post_type      Post type being translated.
		 * @param int      $post_id        Source post ID (0 for type-level queries).
		 * @param int      $translation_id Translation post ID the values are
		 *                                 written to (0 when unknown).
		 */
		$keys = (array) apply_filters( 'perflocale/mt/translatable_meta_keys', [], $post_type, $post_id, $translation_id );

		// Non-empty strings only: a sloppy filter's ints or bools would become junk keys, an array a warning.
		$keys = array_values( array_unique( array_filter( $keys, static fn( $k ): bool => is_string( $k ) && trim( $k ) !== '' ) ) );

		if ( $keys !== [] ) {
			// A credential-named key is never sent to a provider or overwritten.
			$patterns = PostTranslationManager::mt_sensitive_meta_patterns();
			$keys     = array_values( array_filter( $keys, static fn( string $k ): bool => ! PostTranslationManager::is_sensitive_meta_key( $k, $patterns ) ) );
		}

		if ( $keys === [] ) {
			return [];
		}

		// Mirror keys are source-owned (re-copied on every sync) and can hold builder JSON: never translated.
		$sync_fields = (array) $this->settings->get( 'sync_fields', [] );
		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', $sync_fields, $post_type );

		return array_values( array_diff( $keys, array_map( 'strval', $mirror ) ) );
	}

	/**
	 * Machine-translate the registered meta keys from a source post onto its translation.
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID (the write target).
	 * @param string $source_lang    Source language slug.
	 * @param string $target_lang    Target language slug.
	 * @param string $provider_id    Provider override ('' = configured default).
	 * @return array{translated:int, skipped:int, failed:int, errors:array<int,string>}
	 * @throws DeadlineReachedException When a call does not fit the slice's deadline (nothing written).
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

		$keys = $this->get_mt_meta_keys( $post_type, $source_id, $translation_id );

		// A key listed for the source but not for this translation counts as skipped.
		$result['skipped'] += count( array_diff( $this->get_mt_meta_keys( $post_type, $source_id ), $keys ) );

		if ( $keys === [] ) {
			return $result;
		}

		// Keys a person emptied on this translation (its seed-cleared marker).
		$cleared = ContentSync::seed_cleared_keys( $translation_id );

		// The (key, source value) pairs the ownership rule allows; single string values only.
		$to_translate = [];
		$target_rows  = [];

		foreach ( $keys as $key ) {
			if ( isset( $cleared[ $key ] ) ) {
				// Emptied by a person on this translation: stays empty.
				++$result['skipped'];
				continue;
			}

			$source_val = get_post_meta( $source_id, $key, true );

			if ( ! is_string( $source_val ) || trim( $source_val ) === '' ) {
				++$result['skipped'];
				continue;
			}

			$target_val = get_post_meta( $translation_id, $key, true );

			// The key's rows on the target from the primed cache: the snapshot the write may replace ([] when absent).
			$rows = array_values( (array) get_metadata_raw( 'post', $translation_id, $key, false ) );

			if ( count( $rows ) > 1 || ( is_string( $target_val ) && $target_val !== '' && $target_val !== $source_val ) ) {
				// Translator-owned, or several rows: never overwritten.
				++$result['skipped'];
				continue;
			}

			$to_translate[ $key ] = $source_val;
			$target_rows[ $key ]  = $rows;
		}

		if ( $to_translate === [] ) {
			// Nothing to do and nothing failed: clear an old failure breadcrumb.
			delete_post_meta( $translation_id, self::ERRORS_META_KEY );

			return $result;
		}

		// Mask placeholders (SEO tags, printf tokens, {vars}); a value with no letter outside them is copied, never sent.
		$masked_by_key = [];
		$verbatim      = [];
		foreach ( $to_translate as $key => $val ) {
			// mask() returns [masked_text, placeholders].
			$masked_by_key[ $key ] = PlaceholderMasker::mask( $val );

			if ( ! self::has_translatable_letter( $masked_by_key[ $key ][0], count( $masked_by_key[ $key ][1] ) ) ) {
				$verbatim[ $key ] = true;
			}
		}

		// Monthly cap for the meta values (translate_post() checks title, content and excerpt only); over it: record and skip.
		$estimated = 0;
		foreach ( $to_translate as $key => $v ) {
			if ( ! isset( $verbatim[ $key ] ) ) {
				$estimated += mb_strlen( $v );
			}
		}

		if ( $estimated > 0 && $this->service->would_exceed_limit( $estimated ) ) {
			$result['failed']   = count( $to_translate ) - count( $verbatim );
			$result['skipped'] += count( $verbatim );
			$msg                = __( 'Meta fields skipped: monthly machine-translation character limit reached.', 'perflocale' );
			$result['errors'][] = $msg;
			$this->record_errors( $translation_id, [ $msg ] );

			/** This action is documented below. */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $translation_id, array_keys( $to_translate ), $msg );

			return $result;
		}

		$ordered_keys = array_keys( $masked_by_key );

		// Batch per destination format: a key an add-on declares 'text' uses the provider's text mode and text
		// sanitising; every other key stays 'html' with the allowlist sanitiser.
		$keys_by_format = [];
		foreach ( $ordered_keys as $key ) {
			if ( isset( $verbatim[ $key ] ) ) {
				continue;
			}

			/**
			 * Filter a meta key's machine-translation format: 'text' for plain-text meta (provider text mode, no entity
			 * escaping), 'html' (default) for markup.
			 *
			 * @hook perflocale/mt/meta_key_format
			 * @param string $format    'html' (default) or 'text'.
			 * @param string $key       Meta key.
			 * @param string $post_type Source post type.
			 * @param int    $source_id Source post ID.
			 */
			$fmt = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, $post_type, $source_id );
			$fmt = ( 'text' === $fmt ) ? 'text' : 'html';

			$keys_by_format[ $fmt ][] = $key;
		}

		// One provider call per format; results go back by index into $ordered_keys.
		$translated = array_fill( 0, count( $ordered_keys ), '' );
		$key_index  = array_flip( $ordered_keys );

		try {
			foreach ( $keys_by_format as $fmt => $group_keys ) {
				$group_batch = [];
				foreach ( $group_keys as $key ) {
					$group_batch[] = $masked_by_key[ $key ][0];
				}

				// Brings the monthly cap, the count-mismatch failure and the format's sanitising.
				$group_out = $this->service->translate_batch_texts( $group_batch, $source_lang, $target_lang, $provider_id, false, $fmt );

				foreach ( $group_keys as $gi => $key ) {
					$translated[ $key_index[ $key ] ] = $group_out[ $gi ] ?? '';
				}
			}
		} catch ( DeadlineReachedException $e ) {
			// The bulk slice stops and redoes the post, still marked pending, in its next run.
			throw $e;
		} catch ( \Throwable $e ) {
			$result['failed']   = count( $to_translate ) - count( $verbatim );
			$result['skipped'] += count( $verbatim );
			$result['errors'][] = $e->getMessage();
			$this->record_errors( $translation_id, [ $e->getMessage() ] );

			/** This action is documented below (fires per failed batch too). */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $translation_id, array_keys( $to_translate ), $e->getMessage() );

			return $result;
		}

		$errors  = [];
		$written = [];

		// The provider wait can take minutes and another request may have saved a field meanwhile: re-read the rows past
		// the cache, once for the batch.
		$current = $this->read_rows( $translation_id, $ordered_keys );

		foreach ( $ordered_keys as $i => $ordered_key ) {
			// A numeric key such as "2024" is an int array key here.
			$key = (string) $ordered_key;

			if ( null === $current ) {
				++$result['failed'];
				$errors[] = sprintf( 'Meta key "%s" could not be re-checked before saving; existing value kept.', $key );
				continue;
			}

			$snapshot = array_map( 'maybe_serialize', $target_rows[ $key ] );

			if ( ( $current[ $key ] ?? [] ) !== $snapshot ) {
				// Changed during the provider wait: that edit owns the field.
				++$result['skipped'];
				continue;
			}

			if ( isset( $verbatim[ $ordered_key ] ) ) {
				// Placeholders only: the source value as it is.
				$state = $this->write( $translation_id, $key, $to_translate[ $ordered_key ], $target_rows[ $ordered_key ], $snapshot );

				if ( 'failed' === $state ) {
					++$result['failed'];
					$errors[] = sprintf( 'Meta key "%s" could not be saved; existing value kept.', $key );
					continue;
				}

				if ( 'written' === $state ) {
					$written[] = $key;
				}

				++$result['skipped'];
				continue;
			}

			$out = isset( $translated[ $i ] ) ? (string) $translated[ $i ] : '';

			if ( trim( $out ) === '' ) {
				++$result['failed'];
				$errors[] = sprintf( 'Empty translation for meta key "%s"; source value kept.', $key );
				continue;
			}

			$restored = PlaceholderMasker::restore( $out, $masked_by_key[ $key ][1] );

			// A result that dropped a placeholder would break an SEO template or format string: keep the source.
			if ( ! PlaceholderMasker::preserves_placeholders( $to_translate[ $key ], $restored ) ) {
				++$result['failed'];
				$errors[] = sprintf( 'Placeholder lost in meta key "%s"; source value kept.', $key );
				continue;
			}

			$state = $this->write( $translation_id, $key, $restored, $target_rows[ $key ], $snapshot );

			if ( 'conflict' === $state ) {
				++$result['skipped'];
				continue;
			}

			if ( 'failed' === $state ) {
				++$result['failed'];
				$errors[] = sprintf( 'Meta key "%s" could not be saved; existing value kept.', $key );
				continue;
			}

			if ( 'written' === $state ) {
				$written[] = $key;
			}

			++$result['translated'];
		}

		if ( $written !== [] ) {
			/**
			 * Fires after machine translation changed meta values on a translation, before any failure of the same run is
			 * reported; keys that already held the translation are not listed.
			 *
			 * @hook perflocale/mt/meta_translated
			 *
			 * @param int      $translation_id Translation post ID (the post written to).
			 * @param string[] $written_keys   Meta keys whose value was written.
			 * @param int      $source_id      Source post ID.
			 */
			do_action( 'perflocale/mt/meta_translated', $translation_id, $written, $source_id );
		}

		if ( $errors === [] && $result['failed'] === 0 ) {
			// A clean run clears an old failure breadcrumb.
			delete_post_meta( $translation_id, self::ERRORS_META_KEY );
		}

		if ( $errors !== [] ) {
			$result['errors'] = $errors;
			$this->record_errors( $translation_id, $errors );

			/**
			 * Fires when meta values failed to machine-translate; the post translation is unaffected.
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
	 * Whether masked text keeps a letter without its placeholder sentinels (anything to translate).
	 *
	 * @param string $masked Text returned by PlaceholderMasker::mask().
	 * @param int    $count  Number of placeholders mask() replaced.
	 * @return bool
	 */
	private static function has_translatable_letter( string $masked, int $count ): bool {
		for ( $n = 0; $n < $count; $n++ ) {
			$masked = str_replace( '[[PFL_PH_' . $n . ']]', '', $masked );
		}

		// Invalid UTF-8 (preg_match() false) is sent.
		return 0 !== preg_match( '/\p{L}/u', $masked );
	}

	/**
	 * Append lines to a translation's failure breadcrumb (for writers outside this class, such as WooCommerce variation
	 * texts).
	 *
	 * @param int      $post_id Translation post ID.
	 * @param string[] $errors  Error strings.
	 * @return void
	 */
	public static function record_meta_errors( int $post_id, array $errors ): void {
		if ( $post_id <= 0 || $errors === [] ) {
			return;
		}

		$existing = get_post_meta( $post_id, self::ERRORS_META_KEY, true );
		$lines    = array_merge( is_array( $existing ) ? array_filter( $existing, 'is_string' ) : [], array_map( 'strval', $errors ) );

		update_post_meta( $post_id, self::ERRORS_META_KEY, wp_slash( array_values( array_unique( array_map( 'sanitize_text_field', $lines ) ) ) ) );
	}

	/**
	 * Store the failure breadcrumb on the translation.
	 *
	 * @param int      $translation_id Translation post ID.
	 * @param string[] $errors         Error strings.
	 * @return void
	 */
	private function record_errors( int $translation_id, array $errors ): void {
		update_post_meta( $translation_id, self::ERRORS_META_KEY, wp_slash( array_map( 'sanitize_text_field', $errors ) ) );
	}

	/**
	 * Write one translated value over exactly the rows the snapshot saw.
	 *
	 * @param int               $post_id  Translation post ID.
	 * @param string            $key      Meta key.
	 * @param string            $value    Translated value (unslashed).
	 * @param array<int, mixed> $rows     The key's rows at the snapshot ([] = absent).
	 * @param array<int, mixed> $snapshot The same rows in stored (serialized) form.
	 * @return string 'written', 'unchanged' (the key already holds the value),
	 *                'conflict' (someone else wrote the key first) or 'failed'.
	 */
	private function write( int $post_id, string $key, string $value, array $rows, array $snapshot ): string {
		// wp_slash: the meta API unslashes.
		if ( $rows === [] ) {
			// Absent at the snapshot: add only while it is still absent.
			$done = false !== add_post_meta( $post_id, $key, wp_slash( $value ), true );
		} elseif ( $rows[0] === $value ) {
			return 'unchanged';
		} else {
			// A non-empty $prev_value makes core's UPDATE conditional; an empty() one is ignored, so a '' or '0' snapshot relies
			// on the re-read. The raw snapshot value: core never unslashes $prev_value.
			$done = false !== update_post_meta( $post_id, $key, wp_slash( $value ), empty( $rows[0] ) ? '' : $rows[0] );
		}

		return $done ? 'written' : $this->settle_refused( $post_id, $key, $value, $snapshot );
	}

	/**
	 * Why a write did not land (core's false is also "already holds the value"): decided from the cached rows, the table
	 * read only when the cache disagrees. Docs: docs-ai/11-code-rationale.md, "MetaTranslator: a write that did not land".
	 *
	 * @param int               $post_id  Translation post ID.
	 * @param string            $key      Meta key.
	 * @param string            $value    Translated value (unslashed).
	 * @param array<int, mixed> $snapshot The key's rows at the snapshot, stored form.
	 * @return string 'written', 'unchanged', 'conflict' or 'failed'.
	 */
	private function settle_refused( int $post_id, string $key, string $value, array $snapshot ): string {
		// The value core compared against and would have stored.
		$expected = maybe_serialize( sanitize_meta( $key, $value, 'post', (string) get_object_subtype( 'post', $post_id ) ) );
		$cached   = array_map( 'maybe_serialize', array_values( (array) get_metadata_raw( 'post', $post_id, $key, false ) ) );

		if ( $cached === [ $expected ] ) {
			return 'unchanged';
		}

		$now = $this->read_rows( $post_id, [ $key ] );

		if ( null === $now ) {
			return 'failed';
		}

		$now = $now[ $key ] ?? [];

		if ( $now === [ $expected ] ) {
			// The row holds the value, the cache does not: the invalidation core does after an UPDATE that changed a row.
			wp_cache_delete( $post_id, 'post_meta' );

			return 'written';
		}

		return $now === $snapshot ? 'failed' : 'conflict';
	}

	/**
	 * The keys' current rows on a post, read from the table, never the cache.
	 *
	 * @param int                $post_id Post ID.
	 * @param array<int, string> $keys    Meta keys (non-empty; int entries are numeric keys).
	 * @return array<string, array<int, string|null>>|null Raw meta_value rows per key in
	 *                                                      meta_id order; null when the read failed.
	 */
	private function read_rows( int $post_id, array $keys ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$keys = array_map( 'strval', array_values( $keys ) );
		$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Must see current rows, not the post_meta cache; $in is one %s per key.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key IN ({$in}) ORDER BY meta_id ASC", array_merge( [ $wpdb->postmeta, $post_id ], $keys ) ), ARRAY_A );

		// get_results() returns an empty array for a failed query too.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_string( $row['meta_key'] ?? null ) ) {
				$out[ $row['meta_key'] ][] = is_string( $row['meta_value'] ?? null ) ? $row['meta_value'] : null;
			}
		}

		return $out;
	}
}
