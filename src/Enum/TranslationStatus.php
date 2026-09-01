<?php
/**
 * Translation status enum.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Enum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents the status of a translation.
 *
 * The string values are persisted in the `translation_links.status` column.
 * Some cases are referenced via this enum (`TranslationStatus::X->value`)
 * and others via raw string literals in SQL builders — both paths are
 * canonical and the two MUST agree. Removing any case is a schema-level
 * change: existing rows may carry that value.
 *
 * Usage map (audited 2026-05-30):
 *   - Published, Empty, NeedsUpdate — referenced via enum AND raw strings
 *     throughout the codebase (active hot paths).
 *   - Draft        — referenced via raw `'draft'` SQL literals (5 sites);
 *                    no direct enum reference yet. The case stays so the
 *                    enum remains the canonical schema definition and
 *                    future code paths can swap to type-safe access.
 *   - Pending      — same shape as Draft (1 raw-string reference).
 *                    Review state — populated when an editor sends a
 *                    translation to review without publishing.
 *
 * Both Draft and Pending have full match() arms in label()/color() below
 * so the admin UI renders correctly for any row carrying either value.
 */
enum TranslationStatus: string {

	/**
	 * Translator is still working on it — `wp_posts.post_status='draft'`
	 * is the typical paired post status. Referenced from raw `'draft'`
	 * SQL literals in PostTranslationManager + import paths.
	 */
	case Draft = 'draft';

	/**
	 * Submitted to a reviewer / editor — awaiting approval before
	 * publish (e.g. the Visual Editor review flow). Referenced from
	 * raw `'pending'` SQL.
	 */
	case Pending = 'pending';

	/**
	 * Translation is live. Dominant status for active translations.
	 */
	case Published = 'published';

	/**
	 * Source content changed after the translation was last saved.
	 * ContentChangeDetector flips translations to this state when the
	 * source post's hash drifts; the admin Translations page surfaces
	 * the "needs update" badge so translators know what to re-review.
	 */
	case NeedsUpdate = 'needs_update';

	/**
	 * Group exists but this language's slot has no translated content
	 * yet (placeholder row). Created when the admin reserves a future
	 * translation but hasn't started writing one.
	 */
	case Empty = 'empty';

	/**
	 * Get human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- false positive: `$this` is a valid object reference inside enum methods (PHP 8.1+). The sniff pre-dates enums.
		return match ( $this ) {
			self::Draft       => __( 'Draft', 'perflocale' ),
			self::Pending     => __( 'Pending Review', 'perflocale' ),
			self::Published   => __( 'Published', 'perflocale' ),
			self::NeedsUpdate => __( 'Needs Update', 'perflocale' ),
			self::Empty       => __( 'Empty', 'perflocale' ),
		};
	}

	/**
	 * Get CSS color class for admin badges.
	 *
	 * @return string
	 */
	public function color(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- false positive: `$this` is a valid object reference inside enum methods (PHP 8.1+). The sniff pre-dates enums.
		return match ( $this ) {
			self::Draft       => 'blue',
			self::Pending     => 'amber',
			self::Published   => 'green',
			self::NeedsUpdate => 'red',
			self::Empty       => 'gray',
		};
	}
}
