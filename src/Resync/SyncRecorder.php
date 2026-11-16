<?php
/**
 * Content ReSync: the segment memory's capture points, bootstrap, validity,
 * re-base, write-ahead intents and housekeeping.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Automation\ChangeService;
use PerfLocale\Automation\PostTarget;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\Repository\PendingChangeRepository;
use PerfLocale\Database\Repository\ResyncRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Translation\ContentChangeDetector;
use PerfLocale\Translation\ContentSync;
use PerfLocale\Translation\SyncMeta;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records per translation the source state it was last synced to (its base) and its own state after that sync,
 * and keeps a write-ahead intent while a resync write is in flight so a crash never leaves a half-written change.
 * Nothing here runs on a save request.
 *
 * @phpstan-import-type BaseRow from ResyncRepository
 * @phpstan-import-type StateRow from ResyncRepository
 * @phpstan-import-type LiveRead from PostTarget
 * @phpstan-type Document array{title: string, content: string, excerpt: string, keys: list<string>, values: array<string, string|null>}
 * @phpstan-type Live array{post_hash: string, fields_digest: string, fields?: array<string, string|null>, status?: string, missed_schedule?: bool}
 * @phpstan-type BaseDraft array{source_id: int, source_hash: string, fields_hash: string, origin: string, revision_id: int, title: string, content: string, excerpt: string, fields: string}
 * @phpstan-type Capture array{blog: int, translation_id: int, source_id: int, language_id: int, base_id: int, row: BaseDraft, created: bool, values: array<string, mixed>}
 */
final class SyncRecorder {

	/** How a state row was recorded. */
	public const HOW_APPLIED   = 'applied';
	public const HOW_CAPTURED  = 'captured';
	public const HOW_FILLED    = 'filled';
	public const HOW_CONFIRMED = 'confirmed';
	public const HOW_UNCHANGED = 'unchanged';
	public const HOW_BOOTSTRAP = 'bootstrap';

	/** A draft's post_date_gmt until it is first saved. */
	private const NO_DATE = '0000-00-00 00:00:00';

	/** Base quality. */
	public const QUALITY_EXACT    = 'exact';
	public const QUALITY_CONFIRM  = 'confirm';
	public const QUALITY_REVISION = 'revision';

	/** Base origin. */
	public const ORIGIN_LIVE     = 'live';
	public const ORIGIN_REVISION = 'revision';

	/** capture_base(): the source's meta could not be read, so no base was found or written. */
	public const SOURCE_UNREAD = -2;

	/** An intent older than this is reconciled by housekeeping. */
	public const INTENT_STALE_SECONDS = 600;

	/** Days after which run options of jobs that no longer run are swept. */
	private const RUN_OPTION_DAYS = 7;

	/** Unfinished base-retention pass over pending post payloads: last id and bases read (per blog, not autoloaded). */
	public const PENDING_PASS_OPTION = 'perflocale_resync_pending_pass';

	/** Resume point of stale-intent repairs, as `{intent time}|{state row id}` (per blog, not autoloaded). */
	public const STALE_AFTER_OPTION = 'perflocale_resync_stale_after';

	/** Seconds of a housekeeping run the stale-intent repairs may use, so the later steps always get the rest. */
	private const REPAIR_SECONDS = 1.0;

	/** Seconds of clock difference between web nodes the base retention cutoff allows for. */
	private const SKEW_ALLOWANCE = 3600;

	/** Housekeeping cursors at the start of a cycle: see housekeep(). */
	public const IDLE_CURSORS = [
		'stage'     => '',
		'states'    => 0,
		'sources'   => 0,
		'retention' => null,
	];

	/**
	 * The intent kinds the reconciler knows.
	 *
	 * @var string[]
	 */
	private const INTENT_KINDS = [ 'apply', 'approve', 'undo' ];

	/**
	 * Machine-translation start time, keyed by blog then source id.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $started = [];

	/**
	 * Translations an engine fill recorded in this request (blog, translation id); the area-1 capture skips them.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $filled = [];

	/**
	 * Custom-field keys a started machine translation resolved, keyed by blog then source id; reused by its capture.
	 *
	 * @var array<int, array<int, array{int, list<string>}>>
	 */
	private static array $mt_keys = [];

	/** Most area-1 captures an open batch holds before it writes them. */
	private const BATCH_MAX = 25;

	/** Longest wait, in seconds, of the oldest capture in an open batch. */
	private const BATCH_SECONDS = 5.0;

	/**
	 * Open capture batches (batches nest).
	 *
	 * @var int
	 */
	private static int $batch_depth = 0;

	/**
	 * Captures an open batch holds, all of one blog, in capture order.
	 *
	 * @var list<Capture>
	 */
	private static array $batch = [];

	/**
	 * When the oldest capture in the batch was taken (microtime).
	 *
	 * @var float
	 */
	private static float $batch_since = 0.0;

	/**
	 * Whether the batch-writing shutdown function is registered.
	 *
	 * @var bool
	 */
	private static bool $batch_shutdown = false;

	// ------------------------------------------------------------------
	// Pure rules.
	// ------------------------------------------------------------------

	/**
	 * The newest revision at or before `$t_ref`; the higher id wins an equal time.
	 *
	 * @param array<int, array{id: int, date_gmt: string}> $revisions Non-autosave revisions, any order.
	 * @param string                                       $t_ref     UTC datetime.
	 * @return array{id: int, date_gmt: string}|null
	 */
	public static function choose_revision( array $revisions, string $t_ref ): ?array {
		$best = null;

		foreach ( $revisions as $revision ) {
			if ( $revision['date_gmt'] > $t_ref ) {
				continue;
			}

			if ( null === $best
				|| $revision['date_gmt'] > $best['date_gmt']
				|| ( $revision['date_gmt'] === $best['date_gmt'] && $revision['id'] > $best['id'] )
			) {
				$best = $revision;
			}
		}

		return $best;
	}

	/**
	 * Whether a state row counts: current source and language, an existing base of the same source, and content.
	 * Base integrity is checked separately (base_intact()).
	 *
	 * @param array<string, mixed>      $state       State row.
	 * @param array<string, mixed>|null $base        The row `state.base_id` names, null when missing.
	 * @param int                       $source_id   The current default-language member of the group.
	 * @param int                       $language_id The translation's language.
	 * @param bool                      $has_content Whether the translation's content is not empty.
	 * @return bool
	 */
	public static function state_valid( array $state, ?array $base, int $source_id, int $language_id, bool $has_content ): bool {
		$base_id = Scalar::int( $state['base_id'] ?? 0 );

		return $has_content
			&& $base_id > 0
			&& null !== $base
			&& Scalar::int( $base['id'] ?? 0 ) === $base_id
			&& Scalar::int( $state['source_id'] ?? 0 ) === $source_id
			&& Scalar::int( $state['language_id'] ?? 0 ) === $language_id
			&& Scalar::int( $base['source_id'] ?? 0 ) === $source_id;
	}

	/**
	 * A base row's title, content and excerpt: its columns, or the revision its pointer names.
	 *
	 * @param array<string, mixed>                                        $base            Base row.
	 * @param array{title: string, content: string, excerpt: string}|null $revision_fields The revision's fields, when the row points at one.
	 * @return array{title: string, content: string, excerpt: string}|null Null when the pointer's revision is missing.
	 */
	public static function base_fields( array $base, ?array $revision_fields ): ?array {
		$revision_id = Scalar::int( $base['revision_id'] ?? 0 );
		$title       = Scalar::str( $base['title'] ?? '' );
		$content     = Scalar::str( $base['content'] ?? '' );
		$excerpt     = Scalar::str( $base['excerpt'] ?? '' );

		if ( $revision_id > 0 && '' === $title && '' === $content && '' === $excerpt ) {
			return $revision_fields;
		}

		return [
			'title'   => $title,
			'content' => $content,
			'excerpt' => $excerpt,
		];
	}

	/**
	 * Whether a base row still holds the state its hash names.
	 *
	 * @param array<string, mixed>                                        $base            Base row.
	 * @param array{title: string, content: string, excerpt: string}|null $revision_fields The revision's fields, when the row points at one.
	 * @return bool
	 */
	public static function base_intact( array $base, ?array $revision_fields ): bool {
		$fields = self::base_fields( $base, $revision_fields );

		return null !== $fields
			&& Store::content_hash( $fields['title'], $fields['content'], $fields['excerpt'] ) === Scalar::str( $base['source_hash'] ?? '' );
	}

	/**
	 * Which candidate a re-base keeps: the one the translation aligns with one to one; on a tie the newer one
	 * for a published translation (its save marked it in sync), else the state's base.
	 *
	 * @param bool $state_aligns    The state's base aligns one to one.
	 * @param bool $revision_aligns The revision candidate aligns one to one.
	 * @param bool $published       The translation is published.
	 * @param bool $revision_newer  The revision candidate is the newer source state.
	 * @return string 'state' or 'revision'.
	 */
	public static function rebase_choice( bool $state_aligns, bool $revision_aligns, bool $published, bool $revision_newer ): string {
		if ( $state_aligns !== $revision_aligns ) {
			return $state_aligns ? 'state' : 'revision';
		}

		return $published && $revision_newer ? 'revision' : 'state';
	}

	/**
	 * Keys whose live value is neither the prior nor the written one: another writer changed them, so a put-back
	 * never touches them. A key the write does not name is expected at its prior value.
	 *
	 * @param array<int, string>         $keys          The pair's keys.
	 * @param array<string, mixed>       $previous_meta `previous.meta`: per key whether a row existed and its value.
	 * @param array<string, string|null> $expected      `expected.fields`: per written key the hash of the value written, null for a delete.
	 * @param array<string, string|null> $live          The live values.
	 * @return list<string>
	 */
	public static function foreign_fields( array $keys, array $previous_meta, array $expected, array $live ): array {
		$out = [];

		foreach ( $keys as $key ) {
			$key          = (string) $key;
			$value        = $live[ $key ] ?? null;
			$spec         = $previous_meta[ $key ] ?? null;
			$prior_exists = is_array( $spec ) && ! empty( $spec['exists'] );
			$prior_value  = $prior_exists ? Scalar::str( $spec['value'] ?? '' ) : null;

			if ( null === $value ? ! $prior_exists : ( $prior_exists && $value === $prior_value ) ) {
				continue;
			}

			if ( array_key_exists( $key, $expected ) ) {
				$hash = $expected[ $key ];

				if ( null === $hash ? null === $value : ( is_string( $value ) && hash( 'sha256', $value ) === $hash ) ) {
					continue;
				}
			}

			$out[] = $key;
		}

		return $out;
	}

	/**
	 * Written keys whose live value is the written one and differs from the prior one: a trace the write landed.
	 *
	 * @param array<string, mixed>       $previous_meta `previous.meta`: per key whether a row existed and its value.
	 * @param array<string, string|null> $expected      `expected.fields`: per written key the hash of the value written, null for a delete.
	 * @param array<string, string|null> $live          The live values.
	 * @return list<string>
	 */
	public static function fields_as_written( array $previous_meta, array $expected, array $live ): array {
		$out = [];

		foreach ( $expected as $key => $hash ) {
			$key          = (string) $key;
			$value        = $live[ $key ] ?? null;
			$spec         = $previous_meta[ $key ] ?? null;
			$prior_exists = is_array( $spec ) && ! empty( $spec['exists'] );
			$prior_value  = $prior_exists ? Scalar::str( $spec['value'] ?? '' ) : null;

			if ( $value === $prior_value ) {
				continue;
			}

			if ( null === $hash ? null === $value : ( is_string( $value ) && hash( 'sha256', $value ) === $hash ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * Keys whose translation value differs from the source at a confirmation (translated or stale: unknowable).
	 * A key proven empty on the translation holds neither, so its base is known.
	 *
	 * @param array<string, string|null> $source      Source values.
	 * @param array<string, string|null> $translation Translation values.
	 * @param array<int, string>         $empty       Keys a checked read found without a value on the translation.
	 * @return list<string>
	 */
	public static function unknown_fields( array $source, array $translation, array $empty = [] ): array {
		$out   = [];
		$empty = array_fill_keys( $empty, true );

		foreach ( $source as $key => $value ) {
			if ( ! isset( $empty[ $key ] ) && ( $translation[ $key ] ?? null ) !== $value ) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * The keys with no row or one empty row on a post, read after a checked meta load; none when the load failed.
	 *
	 * @param int                $post_id Post id.
	 * @param array<int, string> $keys    Meta keys.
	 * @return list<string>
	 */
	private static function valueless_keys( int $post_id, array $keys ): array {
		$out = PostTarget::rowless_keys( $post_id, $keys );

		if ( false === $out ) {
			return [];
		}

		foreach ( PostTarget::field_values( $post_id, $keys ) as $key => $value ) {
			if ( '' === $value ) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * Whether a stored intent has the shape the reconciler reads.
	 *
	 * @param array<mixed> $intent Intent.
	 * @return bool
	 */
	public static function intent_valid( array $intent ): bool {
		$kind = Scalar::str( $intent['kind'] ?? '' );

		if ( ! in_array( $kind, self::INTENT_KINDS, true ) || '' === Scalar::str( $intent['id'] ?? '' ) ) {
			return false;
		}

		foreach ( [ 'prior', 'expected' ] as $key ) {
			$read = $intent[ $key ] ?? null;

			if ( ! is_array( $read ) || ! is_string( $read['post_hash'] ?? null ) || ! is_string( $read['fields_digest'] ?? null ) ) {
				return false;
			}
		}

		if ( 'apply' !== $kind && Scalar::int( $intent['row_id'] ?? 0 ) <= 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * What the reconciler does with an intent: the live hashes say whether the write happened, half or not at all.
	 * A half-done write is put back only when nobody acted since (a person's status or field value stands, and a
	 * resync write never changes a status). Full decision table: docs-ai (SyncRecorder::reconcile_plan).
	 *
	 * @param array<mixed>              $intent   Intent.
	 * @param array<string, mixed>|null $live     The live read, null when the translation is gone.
	 * @phpstan-param Live|null $live
	 * @param bool                      $recorded Whether the change is recorded already.
	 * @return string finish | record | nothing | put_back | finish_restore | record_expected | record_live | restore | trashed | gone
	 */
	public static function reconcile_plan( array $intent, ?array $live, bool $recorded ): string {
		if ( array_key_exists( 'restoring', $intent ) ) {
			if ( null === $live ) {
				return 'gone';
			}

			return 'trash' === Scalar::str( $live['status'] ?? '' ) ? 'trashed' : 'restore';
		}

		if ( $recorded ) {
			return 'finish';
		}

		if ( null === $live ) {
			return 'gone';
		}

		$expected      = is_array( $intent['expected'] ?? null ) ? $intent['expected'] : [];
		$prior         = is_array( $intent['prior'] ?? null ) ? $intent['prior'] : [];
		$marks_post    = array_key_exists( 'post_written', $intent );
		$post_written  = $marks_post && is_string( $intent['post_written'] ) ? $intent['post_written'] : null;
		$post_writing  = $marks_post && null === $post_written && is_string( $intent['post_writing'] ?? null ) ? $intent['post_writing'] : null;
		$post_expected = $live['post_hash'] === ( $post_written ?? $expected['post_hash'] ?? null ) || ( null !== $post_writing && $live['post_hash'] === $post_writing );

		// Legacy-formula post hashes prove nothing about the live post: record nothing, offer the update again.
		foreach ( [ $post_written, $post_writing, $expected['post_hash'] ?? null, $prior['post_hash'] ?? null ] as $named ) {
			if ( is_string( $named ) && StateHash::is_legacy( $named ) ) {
				return 'nothing';
			}
		}

		if ( $post_expected && $live['fields_digest'] === ( $expected['fields_digest'] ?? null ) ) {
			return 'record';
		}

		if ( $live['post_hash'] === ( $prior['post_hash'] ?? null ) && $live['fields_digest'] === ( $prior['fields_digest'] ?? null ) ) {
			return 'nothing';
		}

		$previous = is_array( $intent['previous'] ?? null ) ? $intent['previous'] : [];

		if ( ! $post_expected && $marks_post ) {
			return null === $post_written ? 'nothing' : 'record_expected';
		}

		if ( ! $post_expected ) {
			// The post is written before any meta, so a post at prior with no field as written proves nothing landed.
			// An undo's `previous.meta` is the version it restores, so it is never proven here.
			if ( 'undo' !== Scalar::str( $intent['kind'] ?? '' ) && $live['post_hash'] === ( $prior['post_hash'] ?? null )
				&& is_array( $live['fields'] ?? null ) && is_array( $expected['fields'] ?? null ) && is_array( $previous['meta'] ?? null )
				&& [] === self::fields_as_written( self::string_keyed( $previous['meta'] ), self::hash_map( $expected['fields'] ), $live['fields'] )
			) {
				return 'nothing';
			}

			return 'record_expected';
		}

		$status = isset( $live['status'] ) ? Scalar::str( $live['status'] ) : null;

		if ( 'trash' === $status ) {
			return 'trashed';
		}

		if ( ! empty( $live['missed_schedule'] ) || ( null !== $status && is_string( $previous['post_status'] ?? null ) && $status !== $previous['post_status'] ) ) {
			return 'record_live';
		}

		// Fields still at their pre-write value (stopped after the post) are no person's change; this matters for
		// an undo, whose `previous` is the version it restores, not the one it replaces.
		if ( $live['fields_digest'] !== ( $prior['fields_digest'] ?? null )
			&& is_array( $live['fields'] ?? null ) && is_array( $expected['fields'] ?? null ) && is_array( $previous['meta'] ?? null )
			&& [] !== self::foreign_fields( Scalar::strings( $intent['keys'] ?? [] ), self::string_keyed( $previous['meta'] ), self::hash_map( $expected['fields'] ), $live['fields'] )
			&& [] === self::undo_unwritten( $intent, $live['fields'] )
		) {
			return 'record_expected';
		}

		return 'undo' === Scalar::str( $intent['kind'] ?? '' ) ? 'finish_restore' : 'put_back';
	}

	/**
	 * Keys the write changes that are still at their pre-write value: the write never reached them.
	 *
	 * @param array<string, string|null> $prior    Per key the hash of the value before the write, null for no row.
	 * @param array<string, string|null> $expected `expected.fields`.
	 * @param array<string, string|null> $live     The live values.
	 * @return list<string>
	 */
	public static function unwritten_fields( array $prior, array $expected, array $live ): array {
		$out = [];

		foreach ( $expected as $key => $hash ) {
			if ( ! array_key_exists( $key, $prior ) || $hash === $prior[ $key ] ) {
				continue;
			}

			$value = $live[ $key ] ?? null;

			if ( ( null === $value ? null : hash( 'sha256', $value ) ) === $prior[ $key ] ) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * Keys an undo never reached (still at `prior.fields`); none for other kinds or intents without them.
	 *
	 * @param array<mixed>               $intent Intent.
	 * @param array<string, string|null> $live   The live values.
	 * @return list<string>
	 */
	private static function undo_unwritten( array $intent, array $live ): array {
		$prior    = is_array( $intent['prior'] ?? null ) ? $intent['prior'] : [];
		$expected = is_array( $intent['expected'] ?? null ) ? $intent['expected'] : [];

		if ( 'undo' !== Scalar::str( $intent['kind'] ?? '' ) || ! is_array( $prior['fields'] ?? null ) ) {
			return [];
		}

		return self::unwritten_fields( self::hash_map( $prior['fields'] ), self::hash_map( $expected['fields'] ?? [] ), $live );
	}

	/**
	 * Keys finishing an undo keeps: those a person changed since, when some key never got its restore.
	 *
	 * @param array<mixed>         $intent Intent.
	 * @param array<string, mixed> $live   The live read.
	 * @phpstan-param Live $live
	 * @return list<string>
	 */
	private static function undo_keep( array $intent, array $live ): array {
		$fields    = $live['fields'] ?? [];
		$unwritten = self::undo_unwritten( $intent, $fields );
		$previous  = is_array( $intent['previous'] ?? null ) ? $intent['previous'] : [];
		$expected  = is_array( $intent['expected'] ?? null ) ? $intent['expected'] : [];

		if ( [] === $unwritten ) {
			return [];
		}

		return array_values( array_diff( self::foreign_fields( Scalar::strings( $intent['keys'] ?? [] ), self::string_keyed( $previous['meta'] ?? [] ), self::hash_map( $expected['fields'] ?? [] ), $fields ), $unwritten ) );
	}

	/**
	 * `previous.meta` as per-key hashes: the value's hash, null for no row.
	 *
	 * @param mixed $meta `previous.meta`.
	 * @return array<string, string|null>
	 */
	private static function meta_hashes( mixed $meta ): array {
		$out = [];

		foreach ( self::string_keyed( $meta ) as $key => $spec ) {
			$out[ $key ] = is_array( $spec ) && ! empty( $spec['exists'] ) ? hash( 'sha256', Scalar::str( $spec['value'] ?? '' ) ) : null;
		}

		return $out;
	}

	/**
	 * What finishing a put-back writes: the version to restore, its text only while the post holds bytes of this
	 * write or its prior (a text a person saved since stands), and the fields to keep as they are.
	 *
	 * @param array<mixed>         $intent Intent.
	 * @param array<string, mixed> $live   The live read.
	 * @phpstan-param Live $live
	 * @return array{to: array<mixed>, post: bool, keep: list<string>}
	 */
	public static function restore_plan( array $intent, array $live ): array {
		$previous = is_array( $intent['previous'] ?? null ) ? $intent['previous'] : [];
		$to       = is_array( $intent['restoring'] ?? null ) ? $intent['restoring'] : $previous;
		$expected = is_array( $intent['expected'] ?? null ) ? $intent['expected'] : [];
		$prior    = is_array( $intent['prior'] ?? null ) ? $intent['prior'] : [];
		$own      = array_filter(
			[ $intent['post_written'] ?? null, $intent['post_writing'] ?? null, $expected['post_hash'] ?? null, $prior['post_hash'] ?? null ],
			'is_string'
		);
		$keep     = is_array( $live['fields'] ?? null ) && is_array( $expected['fields'] ?? null )
			? self::foreign_fields( Scalar::strings( $intent['keys'] ?? [] ), self::string_keyed( $to['meta'] ?? [] ), self::hash_map( $expected['fields'] ), $live['fields'] )
			: [];

		return [
			'to'   => $to,
			'post' => in_array( $live['post_hash'], $own, true ),
			'keep' => $keep,
		];
	}

	/**
	 * A decoded field map by key: a digits-only key decodes as an int and is kept by its string spelling.
	 *
	 * @param mixed $value Value.
	 * @return array<string, mixed>
	 */
	private static function string_keyed( mixed $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			$out[ (string) $key ] = $item;
		}

		return $out;
	}

	/**
	 * A decoded `expected.fields`: per key a hash or null (other entries dropped).
	 *
	 * @param mixed $value Value.
	 * @return array<string, string|null>
	 */
	private static function hash_map( mixed $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			if ( null === $item || is_string( $item ) ) {
				$out[ (string) $key ] = $item;
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// Reading a translation's state.
	// ------------------------------------------------------------------

	/**
	 * A base row's integrity-checked document (text and field snapshot); null when the base is unusable.
	 *
	 * @param array<string, int|string> $base Base row.
	 * @phpstan-param BaseRow $base
	 * @return array<string, mixed>|null
	 * @phpstan-return Document|null
	 */
	public static function base_document( array $base ): ?array {
		$revision_fields = null;

		if ( $base['revision_id'] > 0 && '' === $base['title'] && '' === $base['content'] && '' === $base['excerpt'] ) {
			$revision = get_post( $base['revision_id'] );

			if ( $revision instanceof \WP_Post && 'revision' === $revision->post_type ) {
				$revision_fields = [
					'title'   => $revision->post_title,
					'content' => $revision->post_content,
					'excerpt' => $revision->post_excerpt,
				];
			}
		}

		if ( ! self::base_intact( $base, $revision_fields ) ) {
			return null;
		}

		$fields = self::base_fields( $base, $revision_fields );

		if ( null === $fields ) {
			return null;
		}

		$snapshot = Store::decode_fields( $base['fields'] );

		return [
			'title'   => $fields['title'],
			'content' => $fields['content'],
			'excerpt' => $fields['excerpt'],
			'keys'    => $snapshot['keys'],
			'values'  => $snapshot['values'],
		];
	}

	/**
	 * A translation's valid state with its base document, or null (no row, invalid row, failed integrity).
	 * With a bound, a base over it is not read: null with $too_large set.
	 *
	 * @param int                            $translation_id Translation post id.
	 * @param int                            $source_id      The current default-language member of the group.
	 * @param int                            $language_id    The translation's language.
	 * @param \WP_Post                       $translation    The translation.
	 * @param array<string, int|string>|null $row            Receives the state row as stored, valid or not; null when there is none.
	 * @param int                            $bound          Most text bytes the base's document may hold; PHP_INT_MAX: no bound.
	 * @param bool                           $too_large      Receives whether the base was not read for its size.
	 * @param-out StateRow|null $row
	 * @param-out bool $too_large
	 * @return array{state: array<string, int|string>, base: array<string, int|string>, document: array<string, mixed>}|null
	 * @phpstan-return array{state: StateRow, base: BaseRow, document: Document}|null
	 */
	public static function valid_state( int $translation_id, int $source_id, int $language_id, \WP_Post $translation, ?array &$row = null, int $bound = PHP_INT_MAX, bool &$too_large = false ): ?array {
		$row       = null;
		$too_large = false;

		if ( ! Store::available() ) {
			return null;
		}

		$repo  = new ResyncRepository();
		$state = $repo->state( $translation_id );
		$row   = $state;

		if ( null === $state ) {
			return null;
		}

		// A state synced before ReSync was last off may miss changes made meanwhile: the run reads the source again.
		$after = \PerfLocale\Util\ResyncOff::trusted_after();

		if ( $after > 0 && $state['synced_at'] <= gmdate( 'Y-m-d H:i:s', $after ) ) {
			return null;
		}

		if ( PHP_INT_MAX !== $bound && $state['base_id'] > 0 && PostReads::too_large( [ $state['base_id'] => $repo->base_bytes( $state['base_id'] ) ], $state['base_id'], $bound ) ) {
			$too_large = true;

			return null;
		}

		$base = $state['base_id'] > 0 ? $repo->base( $state['base_id'] ) : null;

		if ( ! self::state_valid( $state, $base, $source_id, $language_id, '' !== $translation->post_content ) || null === $base ) {
			return null;
		}

		$document = self::base_document( $base );

		if ( null === $document ) {
			return null;
		}

		return [
			'state'    => $state,
			'base'     => $base,
			'document' => $document,
		];
	}

	// ------------------------------------------------------------------
	// Fields.
	// ------------------------------------------------------------------

	/**
	 * The custom-field keys in scope for a pair, from the machine-translation registry.
	 *
	 * @param \WP_Post $source         Source.
	 * @param int      $translation_id Translation id (0 when unknown).
	 * @return list<string>
	 */
	public static function field_keys( \WP_Post $source, int $translation_id = 0 ): array {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->settings();
		$service  = new \PerfLocale\MachineTranslation\TranslationService( $settings, $plugin->cache() );

		$keys = ( new \PerfLocale\MachineTranslation\MetaTranslator( $settings, $service ) )->get_mt_meta_keys( $source->post_type, $source->ID, $translation_id );

		return array_values( array_map( 'strval', $keys ) );
	}

	// ------------------------------------------------------------------
	// Capture points.
	// ------------------------------------------------------------------

	/**
	 * The base row of a live source state, found by its unique key or inserted (as a revision pointer when the
	 * newest non-autosave revision holds the same bytes).
	 *
	 * @param \WP_Post                   $source Source, as it is now.
	 * @param array<string, string|null> $values Field values of the source for the keys in scope.
	 * @return int Base id, 0 on failure, SOURCE_UNREAD when the source's meta could not be read (nothing written).
	 */
	public static function capture_base( \WP_Post $source, array $values ): int {
		$repo = new ResyncRepository();
		$base = self::find_or_prepare_base( $repo, $source, $values );

		if ( $base['id'] < 0 ) {
			return self::SOURCE_UNREAD === $base['id'] ? self::SOURCE_UNREAD : 0;
		}

		return $base['id'] > 0 ? $base['id'] : self::insert_base_row( $repo, $base['row'] );
	}

	/**
	 * The reads of capture_base(): the found base id, or 0 and the row to insert. A state the open batch
	 * holds is answered from the batch with no read.
	 *
	 * @param ResyncRepository           $repo   Repository.
	 * @param \WP_Post                   $source Source, as it is now.
	 * @param array<string, string|null> $values Field values of the source for the keys in scope.
	 * @param array<string, int>         $queued The batch's bases by base_key(): the id found, 0 for a row the batch inserts.
	 * @return array{id: int, row: BaseDraft, queued: bool} Id -1 when the claim of a found base failed, SOURCE_UNREAD when the source's meta could not be read.
	 */
	private static function find_or_prepare_base( ResyncRepository $repo, \WP_Post $source, array $values, array $queued = [] ): array {
		$seen        = FieldDrift::seen( $source->ID, array_keys( $values ) );
		$source_hash = Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt );
		$fields_json = Store::fields_json( $values, is_array( $seen ) ? $seen : null );
		$fields_hash = Store::fields_digest( $values );
		$row         = [
			'source_id'   => $source->ID,
			'source_hash' => $source_hash,
			'fields_hash' => $fields_hash,
			'origin'      => self::ORIGIN_LIVE,
			'revision_id' => 0,
			'title'       => $source->post_title,
			'content'     => $source->post_content,
			'excerpt'     => $source->post_excerpt,
			'fields'      => $fields_json,
		];
		$key         = self::base_key( $row );

		// Rule A: the values came from the same meta cache, so neither a reused nor a new base is recorded from it.
		if ( null === $seen ) {
			return [
				'id'     => self::SOURCE_UNREAD,
				'row'    => $row,
				'queued' => false,
			];
		}

		if ( isset( $queued[ $key ] ) ) {
			return [
				'id'     => $queued[ $key ],
				'row'    => $row,
				'queued' => true,
			];
		}

		$id    = $repo->find_base( $source->ID, $source_hash, $fields_hash );
		$claim = $id > 0 ? $repo->claim_base( $id ) : false;

		// A reused base is claimed, so the retention prune cannot delete it under the state or payload that names it.
		// A failed claim names nothing: the insert would answer the unclaimed row by its unique key.
		if ( false !== $claim ) {
			return [
				'id'     => true === $claim ? $id : -1,
				'row'    => $row,
				'queued' => false,
			];
		}

		$revision = self::newest_revision( $source->ID );

		if ( null !== $revision ) {
			$post = get_post( $revision['id'] );

			if ( $post instanceof \WP_Post && Store::content_hash( $post->post_title, $post->post_content, $post->post_excerpt ) === $source_hash ) {
				$row['revision_id'] = $revision['id'];
				$row['title']       = '';
				$row['content']     = '';
				$row['excerpt']     = '';
			}
		}

		return [
			'id'     => 0,
			'row'    => $row,
			'queued' => false,
		];
	}

	/**
	 * The unique key of a base row: source, content hash, field digest.
	 *
	 * @param array<string, mixed> $row Base row.
	 * @return string
	 */
	private static function base_key( array $row ): string {
		return Scalar::int( $row['source_id'] ?? 0 ) . '|' . Scalar::str( $row['source_hash'] ?? '' ) . '|' . Scalar::str( $row['fields_hash'] ?? '' );
	}

	/**
	 * Insert a prepared base row; a concurrent insert of the same state wins and its id is answered.
	 * insert_base() never fails on the unique key, so a batch transaction never holds a failed statement.
	 *
	 * @param ResyncRepository     $repo Repository.
	 * @param array<string, mixed> $row  The prepared row.
	 * @return int Base id, 0 on failure.
	 */
	private static function insert_base_row( ResyncRepository $repo, array $row ): int {
		return $repo->insert_base( $row );
	}

	/**
	 * Record a translation's state row, replacing any, and touch its old and new bases.
	 * An intent on the row stays unless this write is its finish: only the finish or the reconciler clears it.
	 *
	 * @param int             $translation_id Translation id.
	 * @param int             $source_id      Source id.
	 * @param int             $language_id    Language id.
	 * @param int             $base_id        The base.
	 * @param array<mixed>    $values         Other state columns (how, base_quality, hashes, complete, fields_owned, fields_unknown, block_map, prev_base_id).
	 * @param bool            $finish         Whether this write finishes the row's intent (cleared with it).
	 * @param array<int>|null $touch          Bases to touch when the caller knows them; null: the new and the row's current ones.
	 * @return int State row id, 0 on failure.
	 */
	private static function record_state( int $translation_id, int $source_id, int $language_id, int $base_id, array $values, bool $finish = false, ?array $touch = null ): int {
		$repo = new ResyncRepository();
		$row  = array_merge(
			[
				'prev_base_id'            => 0,
				'translation_hash'        => '',
				'translation_fields_hash' => '',
				'complete'                => 0,
				'fields_owned'            => '',
				'fields_unknown'          => '',
				'block_map'               => '',
			],
			$values,
			[
				'source_id'   => $source_id,
				'language_id' => $language_id,
				'base_id'     => $base_id,
				'synced_at'   => ResyncRepository::now(),
			]
		);

		if ( $finish ) {
			$row['intent']    = '';
			$row['intent_at'] = null;
		}

		// ASCII-only JSON (\uXXXX): a utf8mb3 table refuses 4-byte characters and wpdb would drop the whole write.
		foreach ( [ 'fields_owned', 'fields_unknown', 'block_map' ] as $json ) {
			if ( is_array( $row[ $json ] ) ) {
				$row[ $json ] = [] === $row[ $json ] ? '' : Scalar::str( wp_json_encode( $row[ $json ], JSON_UNESCAPED_SLASHES ) );
			}
		}

		// Before the upsert, so the touch still reads the row's current bases; a failed touch writes no state.
		$touched = null === $touch
			? $repo->touch_bases( [ $base_id, Scalar::int( $row['prev_base_id'] ) ], $translation_id )
			: $repo->touch_bases( $touch );

		if ( $touched < 0 ) {
			return 0;
		}

		return $repo->upsert_state( $translation_id, $row );
	}

	/**
	 * Confirmation: a pair with no state row whose link is not `needs_update` is in sync, so record the current
	 * source as its base (quality `confirm`). No post write, so earlier person marks stay.
	 *
	 * @param \WP_Post                       $translation Translation (must have content).
	 * @param \WP_Post                       $source      Source, as it is now.
	 * @param int                            $language_id The translation's language.
	 * @param array<int, array<int, string>> $marks       The person marks the state keeps ({@see PersonBlocks::carry()}).
	 * @phpstan-param list<list<string>> $marks
	 * @return int State row id, 0 when nothing was recorded.
	 */
	public static function confirm( \WP_Post $translation, \WP_Post $source, int $language_id, array $marks = [] ): int {
		if ( ! Store::available() || '' === $translation->post_content ) {
			return 0;
		}

		$keys          = self::field_keys( $source, $translation->ID );
		$source_values = PostTarget::field_values( $source->ID, $keys );
		$empty         = self::valueless_keys( $translation->ID, $keys );
		$target_values = PostTarget::field_values( $translation->ID, $keys );
		$base_id       = self::capture_base( $source, $source_values );

		if ( $base_id <= 0 ) {
			return 0;
		}

		return self::record_state(
			$translation->ID,
			$source->ID,
			$language_id,
			$base_id,
			[
				'how'                     => self::HOW_CONFIRMED,
				'base_quality'            => self::QUALITY_CONFIRM,
				'source_hash'             => Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt ),
				'fields_hash'             => Store::fields_digest( $source_values ),
				'translation_hash'        => Store::content_hash( $translation->post_title, $translation->post_content, $translation->post_excerpt ),
				'translation_fields_hash' => Store::digest_for_keys( $keys, $target_values ),
				'fields_unknown'          => self::unknown_fields( $source_values, $target_values, $empty ),
				'block_map'               => $marks,
			]
		);
	}

	/**
	 * Engine fill: base = the source at fill time, the written keys owned; area-1 capture then skips it this request.
	 *
	 * @param \WP_Post                   $translation Translation, read back after the fill.
	 * @param \WP_Post                   $source      Source at fill time.
	 * @param int                        $language_id Language.
	 * @param array<string, string|null> $written     Field values the fill wrote.
	 * @param bool                       $complete    Whether every part was translated.
	 * @param array<mixed>               $block_map   The block map of the composition.
	 * @return int State row id, 0 when nothing was recorded.
	 */
	public static function after_fill( \WP_Post $translation, \WP_Post $source, int $language_id, array $written, bool $complete, array $block_map ): int {
		if ( ! Store::available() ) {
			return 0;
		}

		self::$filled[ get_current_blog_id() ][ $translation->ID ] = true;

		$keys          = self::field_keys( $source, $translation->ID );
		$source_values = PostTarget::field_values( $source->ID, $keys );
		$target_values = PostTarget::field_values( $translation->ID, $keys );
		$base_id       = self::capture_base( $source, $source_values );

		if ( $base_id <= 0 ) {
			return 0;
		}

		return self::record_state(
			$translation->ID,
			$source->ID,
			$language_id,
			$base_id,
			[
				'how'                     => self::HOW_FILLED,
				'base_quality'            => self::QUALITY_EXACT,
				'source_hash'             => Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt ),
				'fields_hash'             => Store::fields_digest( $source_values ),
				'translation_hash'        => Store::content_hash( $translation->post_title, $translation->post_content, $translation->post_excerpt ),
				'translation_fields_hash' => Store::digest_for_keys( $keys, $target_values ),
				'complete'                => $complete ? 1 : 0,
				'fields_owned'            => Store::owned_hashes( $written ),
				'block_map'               => $block_map,
			]
		);
	}

	/**
	 * Record a resync write or a proven no-op. A no-op is recorded outside the target lock, so it keeps any intent
	 * another writer began. The caller has read the translation back.
	 *
	 * @param int                  $translation_id Translation id.
	 * @param int                  $source_id      Source id.
	 * @param int                  $language_id    Language.
	 * @param int                  $base_id        The base the write establishes.
	 * @param array<string, mixed> $values         source_hash, fields_hash, translation_hash, translation_fields_hash, complete, fields_owned (hashes), fields_unknown, block_map, how (applied | unchanged), base_quality, prev_base_id.
	 * @param bool                 $finish         Whether this write finishes the row's intent (a write: yes; a no-op: no).
	 * @return int State row id, 0 when nothing was recorded.
	 */
	public static function after_write( int $translation_id, int $source_id, int $language_id, int $base_id, array $values, bool $finish = true ): int {
		if ( ! Store::available() || $base_id <= 0 ) {
			return 0;
		}

		$values = array_merge(
			[
				'how'          => self::HOW_APPLIED,
				'base_quality' => self::QUALITY_EXACT,
			],
			$values
		);

		return self::record_state( $translation_id, $source_id, $language_id, $base_id, $values, $finish );
	}

	/**
	 * Bootstrap: the source revision current at the translation's last text save; null when none is usable.
	 * With a bound, a revision over it is not read: null with $too_large set.
	 *
	 * @param \WP_Post $translation Translation.
	 * @param \WP_Post $source      Source, as it is now.
	 * @param int      $bound       Most text bytes the revision may hold; PHP_INT_MAX: no bound.
	 * @param bool     $too_large   Receives whether the revision was not read for its size.
	 * @param bool     $read_failed Receives whether its size could not be read: no base, and not a missing one.
	 * @param-out bool $too_large
	 * @param-out bool $read_failed
	 * @return array{revision_id: int, date_gmt: string, hash: string, equals_live: bool, title: string, content: string, excerpt: string}|null
	 */
	public static function bootstrap_base( \WP_Post $translation, \WP_Post $source, int $bound = PHP_INT_MAX, bool &$too_large = false, bool &$read_failed = false ): ?array {
		$too_large   = false;
		$read_failed = false;
		$t_ref       = self::last_text_save( $translation );
		$revision    = self::source_revision_at( $source->ID, $t_ref );

		if ( null === $revision ) {
			return null;
		}

		if ( PHP_INT_MAX !== $bound ) {
			$bytes = self::post_bytes( $revision['id'] );

			if ( null === $bytes ) {
				$read_failed = true;

				return null;
			}

			if ( PostReads::too_large( [ $revision['id'] => $bytes ], $revision['id'], $bound ) ) {
				$too_large = true;

				return null;
			}
		}

		$post = get_post( $revision['id'] );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$hash = Store::content_hash( $post->post_title, $post->post_content, $post->post_excerpt );

		return [
			'revision_id' => $revision['id'],
			'date_gmt'    => $revision['date_gmt'],
			'hash'        => $hash,
			'equals_live' => Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt ) === $hash,
			'title'       => $post->post_title,
			'content'     => $post->post_content,
			'excerpt'     => $post->post_excerpt,
		];
	}

	/**
	 * The base row of a revision state (fields not read), found or inserted.
	 *
	 * @param int                                                                                    $source_id Source id.
	 * @param array{revision_id: int, hash: string, title: string, content: string, excerpt: string} $revision  A bootstrap_base() result.
	 * @return int Base id, 0 on failure.
	 */
	public static function revision_base( int $source_id, array $revision ): int {
		$repo  = new ResyncRepository();
		$id    = $repo->find_base( $source_id, $revision['hash'], '' );
		$claim = $id > 0 ? $repo->claim_base( $id ) : false;

		// A failed claim answers 0: the insert would answer the unclaimed row by its unique key.
		if ( false !== $claim ) {
			return true === $claim ? $id : 0;
		}

		$id = $repo->insert_base(
			[
				'source_id'   => $source_id,
				'source_hash' => $revision['hash'],
				'fields_hash' => '',
				'origin'      => self::ORIGIN_REVISION,
				'revision_id' => $revision['revision_id'],
				'title'       => $revision['title'],
				'content'     => $revision['content'],
				'excerpt'     => $revision['excerpt'],
				'fields'      => '',
			]
		);

		return $id > 0 ? $id : $repo->find_base( $source_id, $revision['hash'], '' );
	}

	/**
	 * Bootstrap equal to the live source: capture the base only; nothing is written, the flag and person marks stay.
	 *
	 * @param \WP_Post                                                                               $translation Translation.
	 * @param \WP_Post                                                                               $source      Source.
	 * @param int                                                                                    $language_id Language.
	 * @param array{revision_id: int, hash: string, title: string, content: string, excerpt: string} $revision    A bootstrap_base() result whose hash equals the live source.
	 * @param array<int, array<int, string>>                                                         $marks       The person marks the state keeps ({@see PersonBlocks::carry()}).
	 * @phpstan-param list<list<string>> $marks
	 * @return int State row id, 0 when nothing was recorded.
	 */
	public static function bootstrap( \WP_Post $translation, \WP_Post $source, int $language_id, array $revision, array $marks = [] ): int {
		if ( ! Store::available() || '' === $translation->post_content ) {
			return 0;
		}

		$base_id = self::revision_base( $source->ID, $revision );

		if ( $base_id <= 0 ) {
			return 0;
		}

		return self::record_state(
			$translation->ID,
			$source->ID,
			$language_id,
			$base_id,
			[
				'how'              => self::HOW_BOOTSTRAP,
				'base_quality'     => self::QUALITY_REVISION,
				'source_hash'      => $revision['hash'],
				'fields_hash'      => '',
				'translation_hash' => Store::content_hash( $translation->post_title, $translation->post_content, $translation->post_excerpt ),
				'block_map'        => $marks,
			]
		);
	}

	/**
	 * Re-base a translation changed since its last sync: the state's base and the revision at its last text save
	 * compete (rebase_choice()). A revision winner is heuristic; a dry run stores no base row (base id 0).
	 *
	 * @param array<string, int|string> $state       The valid state row.
	 * @param array<string, mixed>      $document    Its base document.
	 * @param \WP_Post                  $translation Translation.
	 * @param \WP_Post                  $source      Source.
	 * @param bool                      $dry         Whether nothing may be written.
	 * @phpstan-param StateRow $state
	 * @phpstan-param Document $document
	 * @return array{winner: string, base_id: int, quality: string, heuristic: bool, document: array<string, mixed>}
	 * @phpstan-return array{winner: string, base_id: int, quality: string, heuristic: bool, document: Document}
	 */
	public static function rebase( array $state, array $document, \WP_Post $translation, \WP_Post $source, bool $dry = false ): array {
		$keep = [
			'winner'    => 'state',
			'base_id'   => $state['base_id'],
			'quality'   => $state['base_quality'],
			'heuristic' => false,
			'document'  => $document,
		];

		$revision = self::bootstrap_base( $translation, $source );

		if ( null === $revision || $revision['hash'] === $state['source_hash'] ) {
			return $keep;
		}

		$block_map       = PendingChangeRepository::decode( $state['block_map'] );
		$state_aligns    = self::aligns( $document['content'], $translation->post_content, $block_map, $source->post_type );
		$revision_aligns = self::aligns( $revision['content'], $translation->post_content, [], $source->post_type );
		$published       = ! in_array( $translation->post_status, [ 'draft', 'pending', 'auto-draft' ], true );
		$newer           = $revision['date_gmt'] > $state['synced_at'];

		if ( 'state' === self::rebase_choice( $state_aligns, $revision_aligns, $published, $newer ) ) {
			return $keep;
		}

		$base_id = $dry ? 0 : self::revision_base( $source->ID, $revision );

		if ( $base_id <= 0 && ! $dry ) {
			return $keep;
		}

		return [
			'winner'    => 'revision',
			'base_id'   => $base_id,
			'quality'   => self::QUALITY_REVISION,
			'heuristic' => true,
			'document'  => [
				'title'   => $revision['title'],
				'content' => $revision['content'],
				'excerpt' => $revision['excerpt'],
				'keys'    => [],
				'values'  => [],
			],
		];
	}

	/**
	 * Whether a base content aligns one to one with a translation content. False when either is unsupported or
	 * unreadable: an alignment of documents without units is no evidence.
	 *
	 * @param string       $base        Base content.
	 * @param string       $translation Translation content.
	 * @param array<mixed> $block_map   The block map of the last write.
	 * @param string       $post_type   Post type.
	 * @return bool
	 */
	public static function aligns( string $base, string $translation, array $block_map, string $post_type ): bool {
		$base_tree = BlockScanner::scan( $base );
		$tran_tree = BlockScanner::scan( $translation );

		if ( ! $base_tree->supported || ! $tran_tree->supported ) {
			return false;
		}

		$options = [ 'post_type' => $post_type ];
		$seg_b   = Segmenter::segment( $base_tree, $options );
		$seg_t   = Segmenter::segment( $tran_tree, $options );

		if ( $seg_b->error !== '' || $seg_t->error !== '' ) {
			return false;
		}

		return Aligner::align( $seg_b, $seg_t, $block_map )->one_to_one();
	}

	// ------------------------------------------------------------------
	// Area-1 capture.
	// ------------------------------------------------------------------

	/**
	 * `perflocale/machine_translation/before`: record the start.
	 *
	 * @param int $source_id Source post id.
	 * @return void
	 */
	public static function before_mt( int $source_id ): void {
		if ( $source_id > 0 ) {
			self::$started[ get_current_blog_id() ][ $source_id ] = time();
		}
	}

	/**
	 * `perflocale/machine_translation/failed`: forget the start, so a later `after` needs a new `before`.
	 *
	 * @param int $source_id Source post id.
	 * @return void
	 */
	public static function failed_mt( int $source_id ): void {
		unset( self::$started[ get_current_blog_id() ][ $source_id ], self::$mt_keys[ get_current_blog_id() ][ $source_id ] );
	}

	/**
	 * Keep the keys a started machine translation's meta step resolved, for the same call's capture.
	 *
	 * @param int           $source_id      Source post id.
	 * @param int           $translation_id Translation post id.
	 * @param array<string> $keys           The keys, as get_mt_meta_keys() returned them.
	 * @return void
	 */
	public static function note_mt_keys( int $source_id, int $translation_id, array $keys ): void {
		$blog = get_current_blog_id();

		if ( isset( self::$started[ $blog ][ $source_id ] ) && $translation_id > 0 ) {
			self::$mt_keys[ $blog ][ $source_id ] = [ $translation_id, array_values( array_map( 'strval', $keys ) ) ];
		}
	}

	/**
	 * `perflocale/machine_translation/after`: capture the translated source as the base, when the call started
	 * through `before` and the source still has the bytes it read. Owned fields only for a translation the call
	 * created: a filled stub may hold a person's values, and core moves a draft's floating date on every update.
	 *
	 * @param int           $source_id   Source post id.
	 * @param array<mixed>  $result      The call's result (`post_id` = the translation, `created` whether the call inserted it).
	 * @param string        $target_lang Target language slug.
	 * @param \WP_Post|null $source      The source as the call read it.
	 * @return int State row id; 0 when nothing was captured, it waits in an open batch, or its commit failed.
	 */
	public static function after_mt( int $source_id, array $result, string $target_lang, ?\WP_Post $source ): int {
		$capture = self::mt_capture( $source_id, $result, $target_lang, $source );

		if ( null === $capture ) {
			return 0;
		}

		if ( self::$batch_depth > 0 ) {
			self::queue_capture( $capture );

			return 0;
		}

		return self::write_captures( [ $capture ] )[0] ?? 0;
	}

	/**
	 * The reads of after_mt(): the capture to write, or null.
	 *
	 * @param int           $source_id   Source post id.
	 * @param array<mixed>  $result      The call's result.
	 * @param string        $target_lang Target language slug.
	 * @param \WP_Post|null $source      The source as the call read it.
	 * @return array<string, mixed>|null
	 * @phpstan-return Capture|null
	 */
	private static function mt_capture( int $source_id, array $result, string $target_lang, ?\WP_Post $source ): ?array {
		$blog  = get_current_blog_id();
		$start = self::$started[ $blog ][ $source_id ] ?? 0;
		$noted = self::$mt_keys[ $blog ][ $source_id ] ?? null;
		unset( self::$started[ $blog ][ $source_id ], self::$mt_keys[ $blog ][ $source_id ] );

		if ( 0 === $start || ! Store::available() ) {
			return null;
		}

		$translation_id = Scalar::int( $result['post_id'] ?? 0 );

		if ( $translation_id <= 0 || isset( self::$filled[ $blog ][ $translation_id ] ) ) {
			return null;
		}

		$translation = get_post( $translation_id );

		if ( ! $translation instanceof \WP_Post || '' === $translation->post_content ) {
			return null;
		}

		wp_cache_delete( $source_id, 'posts' );
		$live = get_post( $source_id );

		if ( ! $live instanceof \WP_Post ) {
			return null;
		}

		$snapshot = $source instanceof \WP_Post && $source->ID === $source_id ? $source : $live;

		if ( Store::content_hash( $live->post_title, $live->post_content, $live->post_excerpt ) !== Store::content_hash( $snapshot->post_title, $snapshot->post_content, $snapshot->post_excerpt ) ) {
			return null;
		}

		$language = \PerfLocale\Plugin::get_instance()->lang_repo()->find_by_slug( $target_lang );

		$language_id = null === $language ? 0 : Scalar::int( Scalar::prop( $language, 'id' ) );

		if ( $language_id <= 0 ) {
			return null;
		}

		$keys         = null !== $noted && $noted[0] === $translation_id ? $noted[1] : self::field_keys( $snapshot, $translation_id );
		$source_values = PostTarget::field_values( $source_id, $keys );
		$target_values = PostTarget::field_values( $translation_id, $keys );
		$created       = ! empty( $result['created'] );
		$queued        = [];

		// A call that kept the content wrote none: record a confirmation and keep the person marks.
		$kept  = in_array( 'content', Scalar::strings( (array) ( $result['kept'] ?? [] ) ), true );
		$marks = $kept ? PersonBlocks::carry( PendingChangeRepository::decode( Scalar::str( ( new ResyncRepository() )->state( $translation_id )['block_map'] ?? '' ) ) ) : [];

		foreach ( self::$batch as $held ) {
			if ( $held['blog'] === $blog ) {
				$queued[ self::base_key( $held['row'] ) ] = $held['base_id'];
			}
		}

		$base = self::find_or_prepare_base( new ResyncRepository(), $snapshot, $source_values, $queued );

		return [
			'blog'           => $blog,
			'translation_id' => $translation_id,
			'source_id'      => $source_id,
			'language_id'    => $language_id,
			'base_id'        => $base['id'],
			'row'            => $base['row'],
			'created'        => $created,
			'values'         => [
				'how'                     => $kept ? self::HOW_CONFIRMED : self::HOW_CAPTURED,
				'base_quality'            => self::QUALITY_EXACT,
				'source_hash'             => $base['row']['source_hash'],
				'fields_hash'             => Store::fields_digest( $source_values ),
				'translation_hash'        => Store::content_hash( $translation->post_title, $translation->post_content, $translation->post_excerpt ),
				'translation_fields_hash' => Store::digest_for_keys( $keys, $target_values ),
				'fields_owned'            => $created ? Store::owned_hashes( $target_values ) : [],
				'block_map'               => $marks,
			],
		];
	}

	/**
	 * Write area-1 captures of the current blog in one transaction (opened after the reads, so a base a concurrent
	 * capture inserted is seen). Stops at the first failed statement and rolls back: on SQLite a failed statement
	 * ends the transaction and later writes would commit alone. A lost connection fails the batch (end_writes()).
	 *
	 * @param list<array<string, mixed>> $captures Captures, in order.
	 * @phpstan-param list<Capture> $captures
	 * @return list<int> Per capture its state row id or 0; all 0 when a statement or the commit failed.
	 */
	private static function write_captures( array $captures ): array {
		global $wpdb;

		$repo   = new ResyncRepository();
		$ids    = array_fill( 0, count( $captures ), 0 );
		$begun  = $repo->begin_writes();
		$own    = ResyncRepository::WRITES_STARTED === $begun;
		$failed = static fn (): bool => $wpdb instanceof \wpdb && '' !== $wpdb->last_error;
		$abort  = static function () use ( $repo, $own, $captures ): array {
			if ( $own ) {
				$repo->abort_writes();
			}

			return array_fill( 0, count( $captures ), 0 );
		};

		// A failed START TRANSACTION is no transaction to join: write nothing.
		if ( ResyncRepository::WRITES_FAILED === $begun ) {
			return $ids;
		}

		// One lock order for concurrent batches (crossing orders deadlocked): new bases by key, base touches by id
		// in one statement, states by translation id.
		$new = [];

		foreach ( $captures as $capture ) {
			if ( 0 === $capture['base_id'] ) {
				$new[ self::base_key( $capture['row'] ) ] = $capture['row'];
			}
		}

		uasort( $new, static fn ( array $a, array $b ): int => [ $a['source_id'], $a['source_hash'], $a['fields_hash'] ] <=> [ $b['source_id'], $b['source_hash'], $b['fields_hash'] ] );

		$inserted = [];

		foreach ( $new as $key => $row ) {
			$inserted[ $key ] = self::insert_base_row( $repo, $row );

			if ( 0 === $inserted[ $key ] && $failed() ) {
				return $abort();
			}
		}

		$bases = [];
		$touch = [];
		$reads = [];

		foreach ( $captures as $i => $capture ) {
			// A base whose claim failed is not recorded.
			if ( $capture['base_id'] < 0 ) {
				$bases[ $i ] = 0;
				continue;
			}

			$bases[ $i ] = $capture['base_id'] > 0 ? $capture['base_id'] : $inserted[ self::base_key( $capture['row'] ) ];

			// A capture of a new translation touches only a base it reuses; any other its row's old and new bases.
			if ( ! $capture['created'] ) {
				$touch[] = $bases[ $i ];
				$touch[] = Scalar::int( $capture['values']['prev_base_id'] ?? 0 );
				$reads[] = $capture['translation_id'];
			} elseif ( $capture['base_id'] > 0 ) {
				$touch[] = $capture['base_id'];
			}
		}

		$current = $repo->state_bases( $reads );

		if ( null === $current || $repo->touch_bases( array_merge( $touch, $current ) ) < 0 ) {
			return $abort();
		}

		$order = array_keys( $captures );
		usort( $order, static fn ( int $a, int $b ): int => [ $captures[ $a ]['translation_id'], $a ] <=> [ $captures[ $b ]['translation_id'], $b ] );

		foreach ( $order as $i ) {
			$capture = $captures[ $i ];

			if ( $bases[ $i ] <= 0 ) {
				continue;
			}

			$ids[ $i ] = self::record_state( $capture['translation_id'], $capture['source_id'], $capture['language_id'], $bases[ $i ], $capture['values'], false, [] );

			if ( 0 === $ids[ $i ] && $failed() ) {
				return $abort();
			}
		}

		if ( $own && ! $repo->end_writes() ) {
			return array_fill( 0, count( $captures ), 0 );
		}

		return array_values( $ids );
	}

	/**
	 * Open a (nestable) capture batch: captures wait and commit together, once per batch instead of once per post.
	 * Pairs a dying process never wrote are confirmed or bootstrapped later, like any pair never captured.
	 *
	 * @return void
	 */
	public static function begin_capture_batch(): void {
		++self::$batch_depth;

		if ( ! self::$batch_shutdown ) {
			self::$batch_shutdown = true;
			register_shutdown_function( [ self::class, 'end_all_capture_batches' ] );
		}
	}

	/**
	 * Close a capture batch; the outermost one writes what the batch holds.
	 *
	 * @return void
	 */
	public static function end_capture_batch(): void {
		if ( self::$batch_depth <= 0 ) {
			return;
		}

		--self::$batch_depth;

		if ( 0 === self::$batch_depth ) {
			self::flush_captures();
		}
	}

	/**
	 * Shutdown function: close every open capture batch and write what it holds.
	 *
	 * @return void
	 */
	public static function end_all_capture_batches(): void {
		self::$batch_depth = 0;
		self::flush_captures();
	}

	/**
	 * Add a capture to the open batch; flush first for another blog, after when full or too old.
	 *
	 * @param array<string, mixed> $capture Capture.
	 * @phpstan-param Capture $capture
	 * @return void
	 */
	private static function queue_capture( array $capture ): void {
		if ( [] !== self::$batch && self::$batch[0]['blog'] !== $capture['blog'] ) {
			self::flush_captures();
		}

		if ( [] === self::$batch ) {
			self::$batch_since = microtime( true );
		}

		self::$batch[] = $capture;

		if ( count( self::$batch ) >= self::BATCH_MAX || microtime( true ) - self::$batch_since >= self::BATCH_SECONDS ) {
			self::flush_captures();
		}
	}

	/**
	 * Write the captures the batch holds, on their blog.
	 *
	 * @return int The state rows recorded.
	 */
	public static function flush_captures(): int {
		$captures          = self::$batch;
		self::$batch       = [];
		self::$batch_since = 0.0;

		if ( [] === $captures ) {
			return 0;
		}

		$blog   = $captures[0]['blog'];
		$switch = is_multisite() && get_current_blog_id() !== $blog;

		if ( $switch ) {
			switch_to_blog( $blog );
		}

		try {
			return count( array_filter( self::write_captures( $captures ) ) );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	// ------------------------------------------------------------------
	// Write-ahead intents and the reconciler.
	// ------------------------------------------------------------------

	/**
	 * Store the intent of a write about to happen (a new row has base 0 and is no state).
	 *
	 * @param int                  $translation_id Translation id.
	 * @param int                  $source_id      Source id.
	 * @param int                  $language_id    Language.
	 * @param array<string, mixed> $intent         Intent (keys: docs-ai SyncRecorder::begin_intent); its `id` is replaced. No user id: a reconciled change is decided by 0.
	 * @return string The intent id, '' on failure or while the row holds another intent.
	 */
	public static function begin_intent( int $translation_id, int $source_id, int $language_id, array $intent ): string {
		if ( ! Store::available() ) {
			return '';
		}

		// A fresh id per call, so the row read back holds this call's intent only when this call wrote it.
		$intent['id'] = wp_generate_uuid4();

		if ( ! self::intent_valid( $intent ) ) {
			return '';
		}

		$json = self::encode_intent( $intent );

		if ( null === $json ) {
			return '';
		}

		$id = ( new ResyncRepository() )->upsert_state(
			$translation_id,
			[
				'source_id'   => $source_id,
				'language_id' => $language_id,
				'intent'      => $json,
				'intent_at'   => ResyncRepository::now(),
			],
			true
		);

		return $id > 0 ? $intent['id'] : '';
	}

	/**
	 * The stored form of an intent. `post_written` and `post_writing` lead, unset, so the writer can set each hash
	 * by its position (ResyncRepository::INTENT_OPEN_WRITING). ASCII-only JSON, so a utf8mb3 table stores it.
	 *
	 * @param array<string, mixed> $intent Intent.
	 * @return string|null Null when it cannot be encoded.
	 */
	public static function encode_intent( array $intent ): ?string {
		if ( array_key_exists( 'post_written', $intent ) ) {
			$intent = [
				'post_written' => null,
				'post_writing' => null,
			] + $intent;
		}

		$json = wp_json_encode( $intent, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Store the stored post's hash in the writer's open intent, after the post write and before any meta write.
	 *
	 * @param int    $translation_id Translation id.
	 * @param string $intent_id      The writer's intent id.
	 * @param string $post_hash      Hash of the stored post.
	 * @return bool Whether the intent now holds it.
	 */
	public static function mark_post_written( int $translation_id, string $intent_id, string $post_hash ): bool {
		return ( new ResyncRepository() )->mark_post_written( $translation_id, $intent_id, $post_hash );
	}

	/**
	 * Store the hash of the post core's UPDATE carries in the writer's open intent, right before that UPDATE.
	 *
	 * @param int    $translation_id Translation id.
	 * @param string $intent_id      The writer's intent id.
	 * @param string $post_hash      Hash of the post being written.
	 * @return bool Whether the intent now holds it.
	 */
	public static function mark_post_writing( int $translation_id, string $intent_id, string $post_hash ): bool {
		return ( new ResyncRepository() )->mark_post_writing( $translation_id, $intent_id, $post_hash );
	}

	/**
	 * Mark an open intent as being put back, before anything is written, so a half-done put-back is finished by
	 * the reconciler, never recorded. An intent without `post_written` is not marked.
	 *
	 * @param int               $translation_id Translation id.
	 * @param string            $intent_id      The intent id.
	 * @param array<mixed>|null $to             The version to write back; null for the intent's own `previous`.
	 * @param string            $own_slug       The slug the write's own save left ('' none): its slug is put back over it.
	 * @return bool Whether the intent is now marked.
	 */
	public static function mark_restoring( int $translation_id, string $intent_id, ?array $to = null, string $own_slug = '' ): bool {
		$json = null === $to ? 'true' : wp_json_encode( $to, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) && ( new ResyncRepository() )->mark_restoring( $translation_id, $intent_id, $json, $own_slug );
	}

	/**
	 * Clear a translation's intent (recorded, or never happened); with `$intent_id` only that intent.
	 *
	 * @param int    $translation_id Translation id.
	 * @param string $intent_id      The intent to clear, '' for any.
	 * @return void
	 */
	public static function clear_intent( int $translation_id, string $intent_id = '' ): void {
		( new ResyncRepository() )->clear_intent( $translation_id, $intent_id );
	}

	/**
	 * Put a translation back from a stored `previous` while holding its Outdated flag (the write moves the post's
	 * hash and the detector's clear must not run). A missed-schedule text is not written: core would publish it.
	 * Post and fields are compare-and-set writes; a row changed since answers PostTarget::ROW_MOVED.
	 *
	 * @param int                             $translation_id Translation id.
	 * @param array<mixed>                    $previous       The stored previous version.
	 * @param array<int, string>              $keep           Keys not written back.
	 * @param callable|null                   $after_post     See PostTarget::write().
	 * @param callable|null                   $at_update      See PostTarget::write(): lock renewal right before the post UPDATE.
	 * @param string|null                     $row_digest     Row digest read before the caller's plan read ('' unreadable); null: read now.
	 * @param array<string, string|null>|null $expected       Per field the value read under the lock, null for no row; null: read at the write.
	 * @return string '' on success, else the reason.
	 */
	public static function put_back( int $translation_id, array $previous, array $keep = [], ?callable $after_post = null, ?callable $at_update = null, ?string $row_digest = null, ?array $expected = null ): string {
		if ( [] !== $keep && is_array( $previous['meta'] ?? null ) ) {
			foreach ( $keep as $key ) {
				unset( $previous['meta'][ $key ] );
			}
		}

		unset( $previous['expected'] );

		if ( null !== $expected ) {
			$previous['expected'] = $expected;
		}

		$post = get_post( $translation_id );
		$text = is_string( $previous['post_title'] ?? null ) || is_string( $previous['post_content'] ?? null ) || is_string( $previous['post_excerpt'] ?? null );

		if ( $text && $post instanceof \WP_Post && PostTarget::missed_schedule( $post ) ) {
			return __( 'The translation is scheduled and its date has passed or is less than two minutes away; it is not written until it is published.', 'perflocale' );
		}

		$previous['row_digest'] = $row_digest ?? PostTarget::row_digest( $translation_id ) ?? '';

		ContentChangeDetector::hold_flag( $translation_id );

		try {
			$result = ChangeService::writing( static fn (): string => PostTarget::write( $translation_id, $previous, true, $after_post, null, $at_update ), $translation_id );
		} finally {
			ContentChangeDetector::release_flag( $translation_id );
		}

		return is_string( $result ) ? $result : '';
	}

	/**
	 * Reconcile stale intents (crashed writes), oldest first, each under its target lock. With `$rotate` the batch
	 * resumes after the last one tried: an intent whose repair keeps failing would otherwise starve all others.
	 *
	 * @param callable $in_time Whether another reconcile may run.
	 * @param int      $batch   Intents read.
	 * @param bool     $rotate  Continue after the last intent an earlier run tried.
	 * @return bool False when the time ran out or a read failed (the resume point stays).
	 */
	public static function reconcile_stale( callable $in_time, int $batch, bool $rotate = false ): bool {
		$repo  = new ResyncRepository();
		$ttl   = 0;
		$after = $rotate ? Scalar::str( get_option( self::STALE_AFTER_OPTION, '' ) ) : '';
		$at    = '';
		$id    = 0;

		if ( '' !== $after && 2 === count( explode( '|', $after ) ) ) {
			[ $at, $raw ] = explode( '|', $after );
			$id           = Scalar::int( $raw );
		}

		$rows = $repo->stale_intents( self::stale_cutoff(), $batch, $at, $id );

		if ( [] === $rows && '' !== $at ) {
			// Past the last stale intent: start over.
			$rows = $repo->stale_intents( self::stale_cutoff(), $batch );
		}

		if ( null === $rows ) {
			return false;
		}

		$last = null;

		foreach ( $rows as $row ) {
			if ( ! $in_time() ) {
				if ( $rotate && null !== $last ) {
					update_option( self::STALE_AFTER_OPTION, $last[1] . '|' . $last[2], false );
				}

				return false;
			}

			if ( 0 === $ttl ) {
				$ttl = ChangeService::target_lock_ttl();
			}

			self::reconcile( $row[0], $ttl );
			$last = $row;
		}

		if ( $rotate && null !== $last && count( $rows ) >= $batch ) {
			update_option( self::STALE_AFTER_OPTION, $last[1] . '|' . $last[2], false );
		} elseif ( $rotate && '' !== $after ) {
			delete_option( self::STALE_AFTER_OPTION );
		}

		return true;
	}

	/**
	 * Counts of open intents: fresh (a write in progress) and stale (a crashed write not repaired yet).
	 *
	 * @return array{fresh: int, stale: int}|null Null when the read failed.
	 */
	public static function open_intents(): ?array {
		return ( new ResyncRepository() )->open_intents( self::stale_cutoff() );
	}

	/**
	 * The time before which an intent is stale (GMT).
	 *
	 * @return string
	 */
	private static function stale_cutoff(): string {
		return gmdate( 'Y-m-d H:i:s', time() - self::INTENT_STALE_SECONDS );
	}

	/**
	 * Reconcile an unfinished intent under the target lock.
	 *
	 * @param int $translation_id Translation id.
	 * @param int $ttl            The lock TTL, 0 to read it.
	 * @return string The outcome (none when the row holds no intent, busy when the lock is held or was lost).
	 */
	public static function reconcile( int $translation_id, int $ttl = 0 ): string {
		if ( ! Store::available() ) {
			return 'none';
		}

		$state = ( new ResyncRepository() )->state( $translation_id );

		if ( null === $state || '' === $state['intent'] ) {
			return 'none';
		}

		$ttl    = $ttl > 0 ? $ttl : ChangeService::target_lock_ttl();
		$result = Lock::with(
			ChangeService::lock_name( 'post', $translation_id, $state['language_id'] ),
			$ttl,
			static fn (): string => self::reconcile_locked( $translation_id, $ttl )
		);

		return is_string( $result ) ? $result : 'busy';
	}

	/**
	 * Reconcile an unfinished intent under the caller's target lock. A failed put-back keeps the intent, so a
	 * half-written translation never loses its crash record; a lock taken over mid-put-back stops it (`busy`).
	 *
	 * @param int $translation_id Translation id.
	 * @param int $ttl            The TTL the caller took the target lock with; 0: no renewal.
	 * @return string finished | recorded | cleared | put_back | trashed | failed | busy | gone | none | invalid
	 */
	public static function reconcile_locked( int $translation_id, int $ttl = 0 ): string {
		return self::reconcile_pass( $translation_id, $ttl, true );
	}

	/**
	 * One reconcile. A put-back refused because the row changed is planned once more (`$replan`); a second refusal
	 * leaves the intent for the next reconcile.
	 *
	 * @param int  $translation_id Translation id.
	 * @param int  $ttl            The TTL the caller took the target lock with; 0: no renewal.
	 * @param bool $replan         Whether a refused put-back is planned once more.
	 * @return string See reconcile_locked().
	 */
	private static function reconcile_pass( int $translation_id, int $ttl, bool $replan ): string {
		$repo  = new ResyncRepository();
		$state = $repo->state( $translation_id );

		if ( null === $state || '' === $state['intent'] ) {
			return 'none';
		}

		$intent = PendingChangeRepository::decode( $state['intent'] );

		if ( ! self::intent_valid( $intent ) ) {
			self::clear_intent( $translation_id );

			return 'invalid';
		}

		$kind      = Scalar::str( $intent['kind'] );
		$intent_id = Scalar::str( $intent['id'] );
		$keys      = Scalar::strings( $intent['keys'] ?? [] );
		$row       = PostTarget::row_read( $translation_id );
		$digest    = null === $row ? '' : $row['digest'];
		$read      = PostTarget::read_checked( $translation_id, $keys );
		$recorded  = self::intent_recorded( $intent, $translation_id, $state['language_id'] );

		// A failed read is neither "gone" nor "not recorded": the intent stands and nothing is written.
		if ( false === $read || null === $recorded ) {
			ChangeService::log( sprintf( 'translation %d or its change row could not be read for its repair; its intent stands.', $translation_id ) );

			return 'failed';
		}

		$plan     = self::reconcile_plan( $intent, null === $read ? null : self::live_of( $read ), $recorded );
		$previous = is_array( $intent['previous'] ?? null ) ? $intent['previous'] : [];
		$lost     = false;
		$renew    = $ttl > 0 ? ChangeService::renewal( $translation_id, $state['language_id'], $ttl, $lost ) : null;

		switch ( $plan ) {
			case 'finish':
				return self::finish_intent( $intent, $translation_id, $state ) ? 'finished' : 'failed';

			case 'record':
			case 'record_expected':
			case 'record_live':
				if ( 'record_live' === $plan && null !== $read && is_array( $intent['state'] ?? null ) && [] !== $intent['state'] ) {
					// Recorded as it stands, incomplete, so the Outdated flag stays for a translator.
					$intent['state'] = array_merge(
						$intent['state'],
						[
							'translation_hash'        => $read['hash'],
							'translation_fields_hash' => $read['digest'],
							'complete'                => 0,
						]
					);
				}

				// A written key still at its value before the write never got its update: recorded, not complete.
				if ( 'record_expected' === $plan && null !== $read && is_array( $intent['state'] ?? null ) && [] !== $intent['state'] && is_array( $intent['expected'] ?? null )
					&& [] !== self::unwritten_fields( self::meta_hashes( $previous['meta'] ?? [] ), self::hash_map( $intent['expected']['fields'] ?? [] ), $read['fields'] )
				) {
					$intent['state']['complete'] = 0;
				}

				if ( ! self::record_intent( $intent, $translation_id, $state['language_id'], self::result_of( $plan, $intent, $read ) ) && 'undo' !== $kind ) {
					$declined = self::declined( $kind, $plan, $translation_id, $intent_id, $previous, $renew, $lost, $digest, null === $read ? null : $read['fields'] );

					return PostTarget::ROW_MOVED === $declined ? self::row_moved( $translation_id, $ttl, $replan ) : $declined;
				}

				return self::finish_intent( $intent, $translation_id, $state ) ? 'recorded' : 'failed';

			case 'put_back':
				if ( [] !== $previous ) {
					self::mark_restoring( $translation_id, $intent_id );
					$error = self::put_back( $translation_id, self::reconciler_previous( $previous ), [], $renew, $renew, $digest, null === $read ? null : $read['fields'] );

					if ( $lost ) {
						return self::lost_lock( $translation_id );
					}

					if ( PostTarget::ROW_MOVED === $error ) {
						return self::row_moved( $translation_id, $ttl, $replan );
					}

					if ( '' !== $error ) {
						ChangeService::log( sprintf( 'translation %d could not be put back%s; its intent stands.', $translation_id, self::failed_reason( $error ) ) );

						return 'failed';
					}
				}

				self::clear_intent( $translation_id, $intent_id );

				return 'put_back';

			case 'restore':
				if ( null === $read ) {
					self::clear_intent( $translation_id, $intent_id );

					return 'gone';
				}

				$restore = self::restore_plan( $intent, self::live_of( $read ) );
				$version = self::reconciler_previous( $restore['to'] );

				if ( PostTarget::slug_restorable( $translation_id, $restore['to']['post_name'] ?? null, Scalar::str( $intent['own_slug'] ?? '' ), null === $row ? null : $row['post_name'] ) ) {
					$version['post_name'] = $restore['to']['post_name'];
				}

				if ( ! $restore['post'] ) {
					unset( $version['post_title'], $version['post_content'], $version['post_excerpt'] );
				}

				if ( [] !== $restore['to'] ) {
					$error = self::put_back( $translation_id, $version, $restore['keep'], $renew, $renew, $digest, $read['fields'] );

					if ( $lost ) {
						return self::lost_lock( $translation_id );
					}

					if ( PostTarget::ROW_MOVED === $error ) {
						return self::row_moved( $translation_id, $ttl, $replan );
					}

					if ( '' !== $error ) {
						ChangeService::log( sprintf( 'the put-back of translation %d could not be finished%s; its intent stands.', $translation_id, self::failed_reason( $error ) ) );

						return 'failed';
					}
				}

				self::clear_intent( $translation_id, $intent_id );

				return 'put_back';

			case 'finish_restore':
				if ( [] !== $previous ) {
					$error = self::put_back( $translation_id, self::reconciler_previous( $previous ), null === $read ? [] : self::undo_keep( $intent, self::live_of( $read ) ), $renew, $renew, $digest, null === $read ? null : $read['fields'] );

					if ( $lost ) {
						return self::lost_lock( $translation_id );
					}

					if ( PostTarget::ROW_MOVED === $error ) {
						return self::row_moved( $translation_id, $ttl, $replan );
					}

					if ( '' !== $error ) {
						ChangeService::log( sprintf( 'the undo of translation %d could not be finished%s; its intent stands.', $translation_id, self::failed_reason( $error ) ) );

						return 'failed';
					}
				}

				// The restore happened: record the row as reverted when it still can be; restore state and link either way.
				self::record_intent( $intent, $translation_id, $state['language_id'], self::result_of( 'record', $intent, PostTarget::read( $translation_id, $keys ) ) );

				return self::finish_intent( $intent, $translation_id, $state ) ? 'recorded' : 'failed';

			case 'trashed':
				self::clear_intent( $translation_id, $intent_id );

				return 'trashed';

			case 'gone':
				self::clear_intent( $translation_id, $intent_id );

				return 'gone';

			default:
				self::clear_intent( $translation_id, $intent_id );

				return 'cleared';
		}
	}

	/**
	 * A reconcile whose target lock was taken over mid-put-back: stop; the new owner finishes the intent.
	 *
	 * @param int $translation_id Translation id.
	 * @return string busy
	 */
	private static function lost_lock( int $translation_id ): string {
		ChangeService::log( sprintf( 'the repair of translation %d stopped after its post write: its target lock was taken over.', $translation_id ) );

		return 'busy';
	}

	/**
	 * The reason suffix for a failed put-back's log line.
	 *
	 * @param string $error The put-back's answer.
	 * @return string
	 */
	private static function failed_reason( string $error ): string {
		return PostTarget::ROW_UNREADABLE === $error ? ' (' . PostTarget::ROW_UNREADABLE . ')' : '';
	}

	/**
	 * A put-back the compare-and-set refused (nothing written): plan once more, then leave the intent.
	 *
	 * @param int  $translation_id Translation id.
	 * @param int  $ttl            The TTL the caller took the target lock with; 0: no renewal.
	 * @param bool $replan         Whether this reconcile may plan once more.
	 * @return string The outcome of the second plan, or failed.
	 */
	private static function row_moved( int $translation_id, int $ttl, bool $replan ): string {
		ChangeService::log( sprintf( 'the put-back of translation %d was not made: its row changed after the reconcile read it; %s.', $translation_id, $replan ? 'planned again from the row as it is now' : 'its intent stands for the next reconcile' ) );

		return $replan ? self::reconcile_pass( $translation_id, $ttl, false ) : 'failed';
	}

	/**
	 * The live read as the plan judges it.
	 *
	 * @param array<string, mixed> $read The live read.
	 * @phpstan-param LiveRead $read
	 * @return array<string, mixed>
	 * @phpstan-return Live
	 */
	private static function live_of( array $read ): array {
		return [
			'post_hash'       => $read['hash'],
			'fields_digest'   => $read['digest'],
			'fields'          => $read['fields'],
			'status'          => $read['status'],
			'missed_schedule' => PostTarget::missed_schedule( $read['post'] ),
		];
	}

	/**
	 * Result columns of a recorded intent: the live read, or for `record_expected` the expected result, so a
	 * person's save after the crash is seen and undo refuses.
	 *
	 * @param string                    $plan   The plan.
	 * @param array<mixed>              $intent Intent.
	 * @param array<string, mixed>|null $read   The live read.
	 * @phpstan-param LiveRead|null $read
	 * @return array<string, string> result_hash, result_status, result_modified, result_fields_hash.
	 */
	private static function result_of( string $plan, array $intent, ?array $read ): array {
		$expected = is_array( $intent['expected'] ?? null ) ? $intent['expected'] : [];

		if ( 'record_expected' !== $plan && null !== $read ) {
			return [
				'result_hash'        => $read['hash'],
				'result_status'      => $read['status'],
				'result_modified'    => $read['modified'],
				'result_fields_hash' => $read['digest'],
			];
		}

		return [
			'result_hash'        => Scalar::str( $expected['post_hash'] ?? '' ),
			'result_status'      => null === $read ? '' : $read['status'],
			'result_modified'    => null === $read ? '' : $read['modified'],
			'result_fields_hash' => Scalar::str( $expected['fields_digest'] ?? '' ),
		];
	}

	/**
	 * A stored `previous` as the reconciler writes it back: text and fields only. A status or slug difference is a
	 * person's, except a slug the write's own save left (the restoring mark's `own_slug`, put back by the caller).
	 *
	 * @param array<mixed> $previous The stored previous version.
	 * @return array<mixed>
	 */
	private static function reconciler_previous( array $previous ): array {
		unset( $previous['post_status'], $previous['post_name'] );

		return $previous;
	}

	/**
	 * An intent that could not be recorded. An approve decided meanwhile: the decision stands, so a write that
	 * landed as expected is put back. An apply whose row could not be inserted keeps its intent.
	 *
	 * @param string                          $kind           apply or approve.
	 * @param string                          $plan           The plan that was recorded.
	 * @param int                             $translation_id Translation id.
	 * @param string                          $intent_id      The intent id.
	 * @param array<mixed>                    $previous       The stored previous version.
	 * @param \Closure|null                   $renew          The lock renewal after the post write, null for none.
	 * @param bool                            $lost           Set by `$renew` when the lock was lost.
	 * @param string                          $digest         The row digest read with the plan ('' unreadable).
	 * @param array<string, string|null>|null $fields         The fields read with the plan (the put-back's compare-and-set).
	 * @return string failed | put_back | cleared | busy | PostTarget::ROW_MOVED (refused, nothing written)
	 */
	private static function declined( string $kind, string $plan, int $translation_id, string $intent_id, array $previous, ?\Closure $renew, bool &$lost, string $digest, ?array $fields ): string {
		if ( 'apply' === $kind ) {
			ChangeService::log( sprintf( 'the applied row of translation %d could not be inserted; its intent stands.', $translation_id ) );

			return 'failed';
		}

		if ( 'record' === $plan && [] !== $previous ) {
			self::mark_restoring( $translation_id, $intent_id );
			$error = self::put_back( $translation_id, self::reconciler_previous( $previous ), [], $renew, $renew, $digest, $fields );

			if ( $lost ) {
				return self::lost_lock( $translation_id );
			}

			if ( PostTarget::ROW_MOVED === $error ) {
				return PostTarget::ROW_MOVED;
			}

			if ( '' !== $error ) {
				ChangeService::log( sprintf( 'translation %d could not be put back after its change was decided elsewhere%s; its intent stands.', $translation_id, self::failed_reason( $error ) ) );

				return 'failed';
			}

			self::clear_intent( $translation_id, $intent_id );

			return 'put_back';
		}

		self::clear_intent( $translation_id, $intent_id );

		return 'cleared';
	}

	/**
	 * Whether an intent's change is recorded already (apply: newest applied row has the intent id; approve: row
	 * applied; undo: row reverted).
	 *
	 * @param array<mixed> $intent         Intent.
	 * @param int          $translation_id Translation id.
	 * @param int          $language_id    Language.
	 * @return bool|null Null when the row could not be read.
	 */
	private static function intent_recorded( array $intent, int $translation_id, int $language_id ): ?bool {
		global $wpdb;

		$repo = new PendingChangeRepository();
		$kind = Scalar::str( $intent['kind'] ?? '' );

		// Null means no row or a failed read; the read ran last, so last_error tells which.
		$failed = static fn (): bool => $wpdb instanceof \wpdb && '' !== $wpdb->last_error;

		if ( 'apply' === $kind ) {
			$newest = $repo->newest_applied_narrow( 'post', $translation_id, $language_id );

			if ( null === $newest ) {
				return $failed() ? null : false;
			}

			$summary = PendingChangeRepository::decode( $newest['summary'] );

			return Scalar::str( $summary['intent'] ?? '' ) === Scalar::str( $intent['id'] ?? '' );
		}

		$row = $repo->find( Scalar::int( $intent['row_id'] ?? 0 ) );

		if ( null === $row ) {
			return $failed() ? null : false;
		}

		return 'approve' === $kind
			? PendingChangeRepository::STATUS_APPLIED === $row['status']
			: PendingChangeRepository::STATUS_REVERTED === $row['status'];
	}

	/**
	 * Record an intent's change, decided by 0: insert the applied row, or move its row. An applied row's summary
	 * gets, in the same statement, what the service's own finish writes (undo compares it).
	 *
	 * @param array<mixed>          $intent         Intent.
	 * @param int                   $translation_id Translation id.
	 * @param int                   $language_id    Language.
	 * @param array<string, string> $result         result_hash, result_status, result_modified, result_fields_hash.
	 * @return bool Whether the row was inserted or moved.
	 */
	private static function record_intent( array $intent, int $translation_id, int $language_id, array $result ): bool {
		$repo     = new PendingChangeRepository();
		$kind     = Scalar::str( $intent['kind'] ?? '' );
		$expected = is_array( $intent['expected'] ?? null ) ? $intent['expected'] : [];
		$applied  = [
			'intent'             => Scalar::str( $intent['id'] ?? '' ),
			'fields_written'     => Scalar::strings( $intent['written'] ?? [] ),
			'result_fields_hash' => Scalar::str( $result['result_fields_hash'] ?? ( $expected['fields_digest'] ?? '' ) ),
		];
		unset( $result['result_fields_hash'] );

		if ( 'apply' === $kind ) {
			$change = [];

			foreach ( is_array( $intent['change'] ?? null ) ? $intent['change'] : [] as $column => $value ) {
				if ( is_string( $column ) ) {
					$change[ $column ] = $value;
				}
			}

			$summary = array_merge( is_array( $change['summary'] ?? null ) ? $change['summary'] : PendingChangeRepository::decode( Scalar::str( $change['summary'] ?? '' ) ), $applied );

			return $repo->insert(
				array_merge(
					$change,
					$result,
					[
						'object_type' => 'post',
						'object_id'   => $translation_id,
						'language_id' => $language_id,
						'status'      => PendingChangeRepository::STATUS_APPLIED,
						'payload'     => '',
						'summary'     => $summary,
						'previous'    => is_array( $intent['previous'] ?? null ) ? $intent['previous'] : null,
						'decided_at'  => PendingChangeRepository::now(),
						'decided_by'  => 0,
					]
				)
			) > 0;
		}

		$row_id = Scalar::int( $intent['row_id'] ?? 0 );

		if ( 'approve' === $kind ) {
			$row     = $repo->find( $row_id );
			$summary = [];

			foreach ( null === $row ? [] : PendingChangeRepository::decode( $row['summary'] ) as $key => $value ) {
				if ( is_string( $key ) ) {
					$summary[ $key ] = $value;
				}
			}

			return $repo->transition(
				$row_id,
				PendingChangeRepository::STATUS_PENDING,
				PendingChangeRepository::STATUS_APPLIED,
				array_merge(
					$result,
					[
						'payload'    => '',
						'reason'     => 'approved',
						'previous'   => is_array( $intent['previous'] ?? null ) ? $intent['previous'] : null,
						'decided_by' => 0,
						'summary'    => array_merge( $summary, $applied ),
					]
				)
			);
		}

		return $repo->transition( $row_id, PendingChangeRepository::STATUS_APPLIED, PendingChangeRepository::STATUS_REVERTED, [ 'decided_by' => 0 ] );
	}

	/**
	 * Finish an intent: write (or for an undo restore) the state row and link, touch the bases, clear the intent.
	 * A failed state or link write keeps the intent for the next reconcile.
	 *
	 * @param array<mixed>              $intent         Intent.
	 * @param int                       $translation_id Translation id.
	 * @param array<string, int|string> $state          The state row holding the intent.
	 * @phpstan-param StateRow $state
	 * @return bool False when a write failed (the intent stands).
	 */
	private static function finish_intent( array $intent, int $translation_id, array $state ): bool {
		$repo = new ResyncRepository();
		$kind = Scalar::str( $intent['kind'] ?? '' );

		if ( 'undo' === $kind ) {
			$restore = $intent['restore_state'] ?? null;
			$link    = is_array( $intent['link'] ?? null ) ? $intent['link'] : [];

			return self::after_undo(
				$translation_id,
				$state['language_id'],
				is_array( $restore ) ? $restore : null,
				Scalar::str( $link['status'] ?? '' ),
				Scalar::str( $link['expect'] ?? '' )
			);
		}

		$values = is_array( $intent['state'] ?? null ) ? $intent['state'] : [];

		if ( [] !== $values ) {
			$base_id = Scalar::int( $values['base_id'] ?? $state['base_id'] );
			unset( $values['base_id'], $values['source_id'], $values['language_id'], $values['intent'], $values['intent_at'], $values['synced_at'] );

			$values = array_merge( [ 'prev_base_id' => $state['base_id'] ], $values );

			// As a write: the intent ends after the flag is settled.
			if ( self::record_state( $translation_id, $state['source_id'], $state['language_id'], $base_id, $values, false ) <= 0 ) {
				ChangeService::log( sprintf( 'the state of translation %d could not be recorded; its intent stands.', $translation_id ) );

				return false;
			}

			$base = $base_id > 0 ? $repo->base( $base_id ) : null;
			$post = get_post( $translation_id );

			if ( null !== $base && $post instanceof \WP_Post ) {
				self::settle_flag( $translation_id, $state['language_id'], $state['source_id'], $base, ! empty( $values['complete'] ), Scalar::str( $intent['quality'] ?? $values['base_quality'] ?? '' ), $post->post_status );
			}

			self::clear_intent( $translation_id, Scalar::str( $intent['id'] ?? '' ) );
		} else {
			self::clear_intent( $translation_id, Scalar::str( $intent['id'] ?? '' ) );
		}

		$repo->touch_bases( array_merge( [ $state['base_id'], $state['prev_base_id'] ], array_map( [ Scalar::class, 'int' ], Scalar::strings( $intent['bases'] ?? [] ) ) ) );

		return true;
	}

	/**
	 * Whether a resync write clears the Outdated flag: complete, base not a revision guess, source fields unchanged.
	 *
	 * @param bool   $complete         The change's `complete`.
	 * @param string $base_quality     The change's base quality.
	 * @param string $source_digest    The source's live field digest.
	 * @param string $base_fields_hash The base's field digest.
	 * @return bool
	 */
	public static function clears_flag( bool $complete, string $base_quality, string $source_digest, string $base_fields_hash ): bool {
		return $complete && 'revision' !== $base_quality && $source_digest === $base_fields_hash;
	}

	/**
	 * Settle the Outdated flag after a resync write: one UPDATE conditional on the source's content hash, run only
	 * when clears_flag() holds.
	 *
	 * @param int                       $translation_id Translation id.
	 * @param int                       $language_id    Language id.
	 * @param int                       $source_id      Source id.
	 * @param array<string, int|string> $base           The base the change was built on.
	 * @phpstan-param BaseRow $base
	 * @param bool                      $complete       The change's `complete`.
	 * @param string                    $base_quality   The change's base quality.
	 * @param string                    $status         The translation's post_status after the write.
	 * @return bool Whether the flag was cleared.
	 */
	public static function settle_flag( int $translation_id, int $language_id, int $source_id, array $base, bool $complete, string $base_quality, string $status ): bool {
		if ( ! $complete || 'revision' === $base_quality ) {
			return false;
		}

		$keys = Store::decode_fields( Scalar::str( $base['fields'] ) )['keys'];

		if ( ! self::clears_flag( $complete, $base_quality, Store::fields_digest( PostTarget::field_values( $source_id, $keys ) ), Scalar::str( $base['fields_hash'] ) ) ) {
			return false;
		}

		if ( self::stale_mark_keeps_flag( $translation_id, $language_id ) ) {
			return false;
		}

		$new = 'publish' === $status ? \PerfLocale\Enum\TranslationStatus::Published->value : \PerfLocale\Enum\TranslationStatus::Empty->value;

		return ( new \PerfLocale\Database\Repository\TranslationGroupWrites( \PerfLocale\Plugin::get_instance()->cache() ) )->clear_needs_update_if_source( $translation_id, $language_id, $new, $source_id, Scalar::str( $base['source_hash'] ), self::legacy_hash( $base ) );
	}

	/**
	 * The legacy-formula hash of a base's source state; '' when the text is unreadable or not the base's state.
	 *
	 * @param array<string, mixed> $base Base row.
	 * @return string
	 */
	private static function legacy_hash( array $base ): string {
		$fields = self::base_fields( $base, null );

		if ( null === $fields ) {
			$revision = get_post( Scalar::int( $base['revision_id'] ?? 0 ) );
			$fields   = $revision instanceof \WP_Post && 'revision' === $revision->post_type
				? [
					'title'   => $revision->post_title,
					'content' => $revision->post_content,
					'excerpt' => $revision->post_excerpt,
				]
				: null;
		}

		if ( null === $fields || Store::content_hash( $fields['title'], $fields['content'], $fields['excerpt'] ) !== Scalar::str( $base['source_hash'] ?? '' ) ) {
			return '';
		}

		return StateHash::legacy_post( $fields['title'], $fields['content'], $fields['excerpt'] );
	}

	/**
	 * Whether stale-marked Sync Fields keep a translation's Outdated flag (until a person's save clears it).
	 * An unmarked translation costs one cached meta lookup.
	 *
	 * @param int $translation_id Translation id.
	 * @param int $language_id    Its language.
	 * @return bool
	 */
	public static function stale_mark_keeps_flag( int $translation_id, int $language_id ): bool {
		if ( ! metadata_exists( 'post', $translation_id, SyncMeta::SYNC_STALE_META ) ) {
			return false;
		}

		$link = \PerfLocale\Plugin::get_instance()->group_repo()->get_link_state( $translation_id, $language_id );

		return null !== $link && \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value === $link['status'] && ContentSync::keeps_needs_update( $translation_id );
	}

	/**
	 * After an undo: write the link status back (compare-and-set), restore or delete the state row, touch bases.
	 * A failed link or state write stops there and keeps the row's intent; a status changed meanwhile is no failure.
	 *
	 * @param int               $translation_id Translation id.
	 * @param int               $language_id    Language.
	 * @param array<mixed>|null $previous_state The state row the change replaced (without its intent), null when it had none.
	 * @param string            $link_status    The link status to write back ('' = leave).
	 * @param string            $expect_status  The link status the change left ('' = unconditional).
	 * @return bool False when a write failed.
	 */
	public static function after_undo( int $translation_id, int $language_id, ?array $previous_state, string $link_status, string $expect_status ): bool {
		global $wpdb;

		if ( ! Store::available() ) {
			return false;
		}

		$repo    = new ResyncRepository();
		$current = $repo->state( $translation_id );
		$touch   = null === $current ? [] : [ $current['base_id'], $current['prev_base_id'] ];

		if ( '' !== $link_status ) {
			$linked = \PerfLocale\Plugin::get_instance()->group_repo()->update_link_status(
				$translation_id,
				$language_id,
				$link_status,
				'',
				ObjectType::Post,
				'' === $expect_status ? null : $expect_status
			);

			// False with no database error is a compare-and-set miss: the status another writer set stands.
			if ( ! $linked && $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
				ChangeService::log( sprintf( 'the link status of translation %d could not be written back after its undo; its intent stands.', $translation_id ) );

				return false;
			}
		}

		if ( null === $previous_state ) {
			if ( ! $repo->delete_state( $translation_id ) ) {
				return false;
			}
		} else {
			$values = array_intersect_key(
				$previous_state,
				array_flip( [ 'prev_base_id', 'source_hash', 'fields_hash', 'translation_hash', 'translation_fields_hash', 'how', 'base_quality', 'complete', 'fields_owned', 'fields_unknown', 'block_map' ] )
			);

			$restored = self::record_state(
				$translation_id,
				Scalar::int( $previous_state['source_id'] ?? ( $current['source_id'] ?? 0 ) ),
				Scalar::int( $previous_state['language_id'] ?? $language_id ),
				Scalar::int( $previous_state['base_id'] ?? 0 ),
				$values,
				true
			);

			if ( $restored <= 0 ) {
				return false;
			}

			$touch[] = Scalar::int( $previous_state['base_id'] ?? 0 );
			$touch[] = Scalar::int( $previous_state['prev_base_id'] ?? 0 );
		}

		$repo->touch_bases( $touch );

		return true;
	}

	// ------------------------------------------------------------------
	// Housekeeping.
	// ------------------------------------------------------------------

	/**
	 * The resync housekeeping steps, each statement checked against the caller's time: stale-intent repairs, then
	 * orphan/gone-source cleanup, base retention, run-option sweep and checkpoint index reaping.
	 * The first three read windows from cursors in `$cursors`, and a run resumes the step the last one stopped in.
	 * Bases named by pending changes are read in one resumable pass (PENDING_PASS_OPTION); until it ends none is deleted.
	 *
	 * @param callable                                                                                       $in_time        Whether another statement may run.
	 * @param int                                                                                            $batch          Rows per statement.
	 * @param int                                                                                            $retention_days Days an unreferenced base is kept, plus a day and an hour (below 1 keeps every base).
	 * @param array{stage: string, states: int, sources: int, retention: array{at: string, id: int}|null} $cursors        Where the steps resume; moved.
	 * @return bool Whether every step finished (the next run starts at the first).
	 */
	public static function housekeep( callable $in_time, int $batch, int $retention_days, array &$cursors = self::IDLE_CURSORS ): bool {
		if ( ! Store::has_tables() ) {
			return true;
		}

		$repo = new ResyncRepository();

		// Repairs are capped so ones that keep failing never keep the retention from running; a driver ReSync does not
		// run on gets the deletions only.
		if ( Store::available() ) {
			$repair_until = microtime( true ) + self::REPAIR_SECONDS;
			self::reconcile_stale( static fn (): bool => $in_time() && microtime( true ) < $repair_until, $batch, true );
		}

		// A step returns $batch while its pass goes on, less once it ended, -1 when it must stop (time, a failed read).
		$steps = [
			'states'  => static function () use ( $repo, $batch, &$cursors ): int {
				return $repo->delete_orphan_states( $batch, $cursors['states'] ) < 0 ? -1 : ( 0 === $cursors['states'] ? 0 : $batch );
			},
			'sources' => static function () use ( $repo, $batch, &$cursors ): int {
				return $repo->delete_bases_of_gone_sources( $batch, $cursors['sources'] ) < 0 ? -1 : ( 0 === $cursors['sources'] ? 0 : $batch );
			},
		];

		if ( $retention_days >= 1 ) {
			// A touched base's last_used_at may lag its use by up to TOUCH_EVERY, and the web node's clock may differ from this one's.
			$cutoff             = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS - ResyncRepository::TOUCH_EVERY - self::SKEW_ALLOWANCE );
			$pass               = null; // Not loaded yet in this run.
			$steps['retention'] = static function () use ( $repo, $cutoff, $batch, $in_time, &$pass, &$cursors ): int {
				$from = $cursors['retention'];
				$ids  = $repo->old_bases( $cutoff, $batch, $cursors['retention'] );

				if ( null === $ids ) {
					return -1;
				}

				if ( [] === $ids ) {
					self::end_pending_pass( $pass );

					return 0;
				}

				if ( null === $pass ) {
					$pass = self::pending_pass();
				}

				if ( ! $pass['done'] ) {
					$pass['done'] = $repo->bases_named_by_pending( $in_time, $pass['after'], $pass['named'] );

					if ( ! $pass['done'] ) {
						$pass['stored'] = update_option(
							self::PENDING_PASS_OPTION,
							[
								'after' => $pass['after'],
								'named' => array_keys( $pass['named'] ),
							],
							false
						) || $pass['stored'];

						// These candidates are read again once the pass ends.
						$cursors['retention'] = $from;

						return -1;
					}
				}

				$named = $pass['named'];
				$used  = array_values( array_filter( $ids, static fn ( int $id ): bool => isset( $named[ $id ] ) ) );

				// A base a pending payload names is touched, so it is no candidate until the retention passes again.
				$repo->touch_bases( $used );
				$repo->delete_bases( array_values( array_diff( $ids, $used ) ), $cutoff );

				if ( null === $cursors['retention'] ) {
					self::end_pending_pass( $pass );

					return 0;
				}

				return $batch;
			};
		}

		$after            = 0;
		$steps['options'] = static function () use ( $batch, &$after ): int {
			return self::sweep_run_options( $batch, $after );
		};
		$steps['index']   = static fn (): int => UnitTranslator::reap_index( $batch );

		$resume = isset( $steps[ $cursors['stage'] ] ) ? $cursors['stage'] : '';

		foreach ( $steps as $name => $step ) {
			if ( '' !== $resume && $resume !== $name ) {
				continue;
			}

			$resume = '';

			if ( $cursors['stage'] !== $name ) {
				$cursors = array_merge( $cursors, [ 'stage' => $name ], array_intersect_key( self::IDLE_CURSORS, [ $name => true ] ) );
			}

			// A statement that found fewer than BATCH rows finished its step.
			do {
				if ( ! $in_time() ) {
					return false;
				}

				$done = $step();
			} while ( $done >= $batch );

			if ( $done < 0 ) {
				return false;
			}
		}

		$cursors = self::IDLE_CURSORS;

		return true;
	}

	/**
	 * The pending-payload pass an earlier run stored, or a new one.
	 *
	 * @return array{after: int, named: array<int, true>, done: bool, stored: bool}
	 */
	private static function pending_pass(): array {
		$saved = get_option( self::PENDING_PASS_OPTION, null );
		$named = [];

		if ( ! is_array( $saved ) ) {
			return [
				'after'  => 0,
				'named'  => [],
				'done'   => false,
				'stored' => null !== $saved,
			];
		}

		foreach ( (array) ( $saved['named'] ?? [] ) as $id ) {
			$id = Scalar::int( $id );

			if ( $id > 0 ) {
				$named[ $id ] = true;
			}
		}

		return [
			'after'  => max( 0, Scalar::int( $saved['after'] ?? 0 ) ),
			'named'  => $named,
			'done'   => false,
			'stored' => true,
		];
	}

	/**
	 * Drop the stored pass once the retention step has finished.
	 *
	 * @param array{after: int, named: array<int, true>, done: bool, stored: bool}|null $pass The pass of this run, null when not loaded.
	 * @return void
	 */
	private static function end_pending_pass( ?array &$pass ): void {
		if ( null !== $pass && $pass['done'] && $pass['stored'] ) {
			delete_option( self::PENDING_PASS_OPTION );
			$pass['stored'] = false;
		}
	}

	/**
	 * Delete one page of run options of jobs that no longer run and are older than RUN_OPTION_DAYS.
	 * The cursor moves past every name read, so options that stay never hold the sweep up.
	 *
	 * @param int $batch Maximum options.
	 * @param int $after The option_id the page starts after; set to the last one read.
	 * @return int Options read.
	 */
	private static function sweep_run_options( int $batch, int &$after ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_id, option_name FROM %i WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id LIMIT %d',
				$wpdb->options,
				$wpdb->esc_like( 'perflocale_resync_run_' ) . '%',
				$after,
				max( 1, $batch )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows   = is_array( $rows ) ? $rows : [];
		$cutoff = time() - self::RUN_OPTION_DAYS * DAY_IN_SECONDS;

		foreach ( $rows as $row ) {
			$id    = Scalar::int( $row['option_id'] ?? 0 );
			$after = max( $after, $id );
			$name  = Scalar::str( $row['option_name'] ?? '' );
			$job   = \PerfLocale\Background\JobState::get( substr( $name, strlen( 'perflocale_resync_run_' ) ) );

			// Null is no row or a failed read (last_error tells which); an unreadable job keeps its run option.
			if ( null === $job && '' !== $wpdb->last_error ) {
				continue;
			}

			if ( null !== $job ) {
				$status  = Scalar::str( $job['status'] ?? '' );
				$updated = Scalar::int( $job['updated_at'] ?? 0 );

				if ( in_array( $status, [ 'queued', 'running' ], true ) || $updated > $cutoff ) {
					continue;
				}
			}

			// By id: an option stored again under the same name since the read is another row and stays.
			$sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_id = %d AND option_name = %s', $wpdb->options, $id, $name );

			if ( is_string( $sql ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; the options cache entry is dropped below.
				$wpdb->query( $sql );
				wp_cache_delete( $name, 'options' );
			}
		}

		return count( $rows );
	}

	// ------------------------------------------------------------------
	// Revisions.
	// ------------------------------------------------------------------

	/**
	 * A post's last text save: its newest non-autosave revision's date, else its own (GMT).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function last_text_save( \WP_Post $post ): string {
		$revision = self::newest_revision( $post->ID );

		return null === $revision ? $post->post_date_gmt : $revision['date_gmt'];
	}

	/**
	 * Why a translation has no base, which sets the refusal's next step: 'no_revisions', 'unsaved_draft' or ''.
	 *
	 * @param \WP_Post $translation Translation.
	 * @param \WP_Post $source      Source.
	 * @return string
	 */
	public static function no_base_reason( \WP_Post $translation, \WP_Post $source ): string {
		if ( ! wp_revisions_enabled( $source ) ) {
			return 'no_revisions';
		}

		// Saving the draft helps only when the source has a revision for that save to find.
		$unsaved = self::NO_DATE === $translation->post_date_gmt && null === self::newest_revision( $translation->ID );

		return $unsaved && null !== self::newest_revision( $source->ID ) ? 'unsaved_draft' : '';
	}

	/**
	 * A post's newest non-autosave revision.
	 *
	 * @param int $post_id Post id.
	 * @return array{id: int, date_gmt: string}|null
	 */
	public static function newest_revision( int $post_id ): ?array {
		return self::source_revision_at( $post_id, '' );
	}

	/**
	 * A post's newest non-autosave revision at or before a time ('' = any).
	 *
	 * @param int    $post_id Post id.
	 * @param string $t_ref   UTC datetime, '' for the newest.
	 * @return array{id: int, date_gmt: string}|null
	 */
	public static function source_revision_at( int $post_id, string $t_ref ): ?array {
		global $wpdb;

		if ( $post_id <= 0 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$autosave = '%' . $wpdb->esc_like( '-autosave-v' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The posts table is bound with %i.
		$row = '' === $t_ref
			? $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ID, post_date_gmt FROM %i WHERE post_parent = %d AND post_type = %s AND post_name NOT LIKE %s ORDER BY post_date_gmt DESC, ID DESC LIMIT 1',
					$wpdb->posts,
					$post_id,
					'revision',
					$autosave
				),
				ARRAY_A
			)
			: $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ID, post_date_gmt FROM %i WHERE post_parent = %d AND post_type = %s AND post_name NOT LIKE %s AND post_date_gmt <= %s ORDER BY post_date_gmt DESC, ID DESC LIMIT 1',
					$wpdb->posts,
					$post_id,
					'revision',
					$autosave,
					$t_ref
				),
				ARRAY_A
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $row ) || Scalar::int( $row['ID'] ?? 0 ) <= 0 ) {
			return null;
		}

		return [
			'id'       => Scalar::int( $row['ID'] ),
			'date_gmt' => Scalar::str( $row['post_date_gmt'] ?? '' ),
		];
	}

	/**
	 * Byte length of one post's text columns, without reading the text; null when missing or the read failed.
	 *
	 * @param int $post_id Post id.
	 * @return int|null
	 */
	private static function post_bytes( int $post_id ): ?int {
		global $wpdb;

		if ( $post_id <= 0 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$bytes = PostReads::bytes_sql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The posts table is bound with %i; the byte expression is built from fixed column names.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT $bytes FROM %i p WHERE p.ID = %d", $wpdb->posts, $post_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $value || '' !== $wpdb->last_error ? null : Scalar::int( $value );
	}

	/**
	 * Forget the per-request memos (tests, long-running processes).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$started     = [];
		self::$filled      = [];
		self::$mt_keys     = [];
		self::$batch_depth = 0;
		self::$batch       = [];
		self::$batch_since = 0.0;
	}
}
