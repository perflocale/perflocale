<?php
/**
 * Content ReSync: rule 1.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Database\Repository\PendingChangeRepository;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A translation a person edited since the last automatic write is held, never overwritten. Hash only: a live hash
 * other than the last automatic write's, an `edited` row, or no applied row at all means a person's text (REST,
 * XLIFF, WP-CLI and Abilities edits count too). Fields are judged per key by the engine.
 */
final class HumanEdit {

	/**
	 * The pure rule.
	 *
	 * @param array<string, mixed>|null $newest      The target's newest applied row (narrow read), null when none.
	 * @param string                    $live_hash   The translation's live hash.
	 * @param bool                      $has_content Whether the translation has content (an empty one is never a person's edit).
	 * @return bool True when a person edited the translation since the last automatic write.
	 */
	public static function judge( ?array $newest, string $live_hash, bool $has_content ): bool {
		if ( ! $has_content ) {
			return false;
		}

		if ( null === $newest ) {
			return true;
		}

		if ( 'edited' === Scalar::str( $newest['reason'] ?? '' ) ) {
			return true;
		}

		return Scalar::str( $newest['result_hash'] ?? '' ) !== $live_hash;
	}

	/**
	 * Whether a person edited a translation since the last automatic write.
	 *
	 * @param int                  $translation_id Translation id.
	 * @param int                  $language_id    Language id.
	 * @param array<string, mixed> $live           The live read (PostTarget::read()): `hash` and `post`.
	 * @return bool
	 */
	public static function since_last_automatic_write( int $translation_id, int $language_id, array $live ): bool {
		return self::since( ( new PendingChangeRepository() )->newest_applied_narrow( 'post', $translation_id, $language_id ), $live );
	}

	/**
	 * Whether a person edited a translation since the last automatic write, on a newest row already read.
	 *
	 * @param array<string, int|string>|null $newest The newest applied row, narrow; null for none.
	 * @param array<string, mixed>           $live   The live read (PostTarget::read()): `hash` and `post`.
	 * @return bool
	 */
	public static function since( ?array $newest, array $live ): bool {
		$post    = $live['post'] ?? null;
		$content = $post instanceof \WP_Post ? $post->post_content : Scalar::str( $live['content'] ?? '' );

		return self::judge( $newest, Scalar::str( $live['hash'] ?? '' ), '' !== $content );
	}
}
