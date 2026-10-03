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
 *   is reached, so it is never re-billed. A key with several rows is not one
 *   value and is skipped, and so is a key listed in the translation's
 *   seed-cleared marker (a field a person emptied there stays empty). A key
 *   the registry lists for the source but not for this translation (an
 *   addon found its row laid out differently there) is counted as skipped.
 * - The rule is applied to a snapshot taken before the provider call. After
 *   it, the rows are re-read past the object cache in one query, and a key
 *   whose rows changed during the wait is skipped: that edit owns the field.
 *   The write replaces only the snapshot (a unique add when the key was
 *   absent, a prev_value compare-and-swap otherwise), and a write that did
 *   not land counts as failed, never as translated.
 * - Keys matching the sensitive-meta patterns (the same list that keeps them
 *   out of a new translation's copied meta) are dropped from the registry,
 *   whichever addon registered them.
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
		 * @param string[] $keys           Meta keys to machine-translate.
		 * @param string   $post_type      Post type being translated.
		 * @param int      $post_id        Source post ID (0 for type-level queries).
		 * @param int      $translation_id Translation post ID the values are
		 *                                 written to (0 when unknown).
		 */
		$keys = (array) apply_filters( 'perflocale/mt/translatable_meta_keys', [], $post_type, $post_id, $translation_id );

		// Strings only: ints/floats/bools from a sloppy filter would otherwise
		// become junk meta keys ('123', '1'), and an array entry would emit an
		// Array-to-string warning.
		$keys = array_values( array_unique( array_filter( $keys, static fn( $k ): bool => is_string( $k ) && trim( $k ) !== '' ) ) );

		if ( $keys !== [] ) {
			// A credential-named key is never sent to a provider or overwritten.
			$patterns = PostTranslationManager::sensitive_meta_patterns();
			$keys     = array_values( array_filter( $keys, static fn( string $k ): bool => ! PostTranslationManager::is_sensitive_meta_key( $k, $patterns ) ) );
		}

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

		$keys = $this->get_mt_meta_keys( $post_type, $source_id, $translation_id );

		// A key listed for the source but not for this translation (an addon
		// found its row laid out differently there) is skipped, not dropped
		// unseen.
		$result['skipped'] += count( array_diff( $this->get_mt_meta_keys( $post_type, $source_id ), $keys ) );

		if ( $keys === [] ) {
			return $result;
		}

		// Keys a person emptied on this translation (its seed-cleared marker).
		$cleared = ContentSync::seed_cleared_keys( $translation_id );

		// Collect the translatable (key, source value) pairs under the
		// ownership rule. Only single-value string meta qualifies — array or
		// serialized values are structural by definition here.
		$to_translate = [];
		$target_rows  = [];

		foreach ( $keys as $key ) {
			if ( isset( $cleared[ $key ] ) ) {
				// Emptied by a person on this translation: stays empty until
				// someone types a value there.
				++$result['skipped'];
				continue;
			}

			$source_val = get_post_meta( $source_id, $key, true );

			if ( ! is_string( $source_val ) || trim( $source_val ) === '' ) {
				++$result['skipped'];
				continue;
			}

			$target_val = get_post_meta( $translation_id, $key, true );

			// Every row the key has on the target, from the same primed cache:
			// the snapshot the write below may replace ([] when absent).
			$rows = array_values( (array) get_metadata_raw( 'post', $translation_id, $key, false ) );

			if ( count( $rows ) > 1 || ( is_string( $target_val ) && $target_val !== '' && $target_val !== $source_val ) ) {
				// Translator-owned value, or several rows rather than one
				// value — never overwrite.
				++$result['skipped'];
				continue;
			}

			$to_translate[ $key ] = $source_val;
			$target_rows[ $key ]  = $rows;
		}

		if ( $to_translate === [] ) {
			// Nothing to do and nothing failed — clear any stale breadcrumb
			// from a previous failure (every value is now translator-owned
			// or empty; the earlier outage is resolved from this post's view).
			delete_post_meta( $translation_id, self::ERRORS_META_KEY );

			return $result;
		}

		// Mask placeholders per value (SEO template tags like %%sitename%%,
		// printf tokens, {brace} vars) so the provider can't mangle them.
		// A value with no letter outside its placeholders ("%%title%% %%sep%%
		// %%sitename%%") has nothing to translate: it is copied as it is and
		// never sent.
		$masked_by_key = [];
		$verbatim      = [];
		foreach ( $to_translate as $key => $val ) {
			// mask() returns [masked_text, placeholders].
			$masked_by_key[ $key ] = PlaceholderMasker::mask( $val );

			if ( ! self::has_translatable_letter( $masked_by_key[ $key ][0], count( $masked_by_key[ $key ][1] ) ) ) {
				$verbatim[ $key ] = true;
			}
		}

		// Monthly-cap check for the values that will be sent (translate_post's
		// own cap check covers only title/content/excerpt). Fail soft: record
		// + skip.
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

		// Group the batch by destination format so a plain-text meta key (SEO
		// title/description, an ACF text field) is translated in the provider's
		// TEXT mode and sanitised as text, while HTML-bearing keys (an ACF
		// wysiwyg field) keep HTML mode + the allowlist sanitiser. Default is
		// 'html' so an undeclared / third-party key behaves exactly as before —
		// a key is only routed to text when an addon affirmatively declares it.
		$keys_by_format = [];
		foreach ( $ordered_keys as $key ) {
			if ( isset( $verbatim[ $key ] ) ) {
				continue;
			}

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
			 * @param int    $source_id Source post ID.
			 */
			$fmt = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, $post_type, $source_id );
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

		// The provider wait can run to minutes (retries, Retry-After). Someone
		// may have saved one of these fields meanwhile, from a request whose
		// write this process's object cache never saw, so the rows are read
		// past the cache, once for the whole batch.
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

			// Integrity gate: a translation that dropped a placeholder would
			// ship a broken SEO template / format string. Keep the source.
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
			 * Fires after machine translation changed meta values on a
			 * translation, before any failure for the same run is reported.
			 * Keys whose value was already the translation are not listed.
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
	 * Whether masked text keeps a letter once its placeholder sentinels are
	 * removed, i.e. whether a provider has anything to translate in it.
	 *
	 * @param string $masked Text returned by PlaceholderMasker::mask().
	 * @param int    $count  Number of placeholders mask() replaced.
	 * @return bool
	 */
	private static function has_translatable_letter( string $masked, int $count ): bool {
		for ( $n = 0; $n < $count; $n++ ) {
			$masked = str_replace( '[[PFL_PH_' . $n . ']]', '', $masked );
		}

		// Text that is not valid UTF-8 (preg_match() false) is sent as before.
		return 0 !== preg_match( '/\p{L}/u', $masked );
	}

	/**
	 * Add lines to a translation's machine-translation failure breadcrumb,
	 * after the ones already there (for MT writers outside this class, such
	 * as the WooCommerce variation texts).
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
	 * Persist the failure breadcrumb on the translation post.
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
		// wp_slash: the meta API unslashes internally (documented
		// backslash-corruption class without it).
		if ( $rows === [] ) {
			// Absent at the snapshot: add only while it is still absent.
			$done = false !== add_post_meta( $post_id, $key, wp_slash( $value ), true );
		} elseif ( $rows[0] === $value ) {
			return 'unchanged';
		} else {
			// A non-empty $prev_value makes core's UPDATE conditional on it; core
			// ignores an empty() one, so a '' or '0' snapshot relies on the
			// re-read alone. The raw snapshot value: core never unslashes
			// $prev_value.
			$done = false !== update_post_meta( $post_id, $key, wp_slash( $value ), empty( $rows[0] ) ? '' : $rows[0] );
		}

		return $done ? 'written' : $this->settle_refused( $post_id, $key, $value, $snapshot );
	}

	/**
	 * Why a write did not land. Core returns false both for a refused write
	 * (a short-circuit filter, a failed INSERT/UPDATE, a lost compare-and-swap)
	 * and for a value the key already holds.
	 *
	 * The cached rows settle the already-holds case without a query: core
	 * compared against that same cache. The table is read only when the cache
	 * disagrees, because core leaves the cache as it was when its write changed
	 * no row. Not wp_cache_delete() + get_post_meta(): when the database is
	 * refusing reads too, that reload caches an EMPTY meta set for the post.
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
			// The row holds the value and the cache does not: the invalidation
			// core performs after an UPDATE that changed a row.
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
