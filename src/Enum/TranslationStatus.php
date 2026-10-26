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
 * Status of a translation link, stored verbatim in `translation_links.status` (raw SQL literals use the same values).
 *
 * The stored value is set at link creation and only `needs_update` adds to what the post says: post readers derive
 * the status with effective() or its SQL twin. Removing a case is a schema change: rows may hold it.
 */
enum TranslationStatus: string {

	/**
	 * Translation in progress (linked post usually a draft).
	 */
	case Draft = 'draft';

	/**
	 * Linked post awaits review; derived by effective(), never stored by the plugin.
	 */
	case Pending = 'pending';

	/**
	 * Translation is live.
	 */
	case Published = 'published';

	/**
	 * Source content changed since the translation was saved (set by ContentChangeDetector).
	 */
	case NeedsUpdate = 'needs_update';

	/**
	 * This language's slot has no translated content yet.
	 */
	case Empty = 'empty';

	/**
	 * Effective status of a post link: the stored status reconciled with the linked post's status.
	 *
	 * Must equal TranslationLinkRepository::effective_status_sql() for every (stored, post status) pair.
	 *
	 * @param string      $stored      Stored `translation_links.status`.
	 * @param string|null $post_status Linked post's `post_status`, or null when there is no post.
	 * @return string
	 */
	public static function effective( string $stored, ?string $post_status ): string {
		if ( $post_status === 'trash' || $post_status === 'auto-draft' ) {
			return self::Empty->value;
		}

		if ( $stored === self::NeedsUpdate->value ) {
			return self::NeedsUpdate->value;
		}

		return match ( $post_status ) {
			'publish' => self::Published->value,
			'draft'   => self::Draft->value,
			'pending' => self::Pending->value,
			default   => $stored,
		};
	}

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
