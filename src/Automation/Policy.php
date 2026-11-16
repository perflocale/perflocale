<?php
/**
 * Automation policy.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides what happens to one automatic change: publish it, keep it as a draft, or hold it for review.
 *
 * Only Content ReSync's areas (RESYNC_AREAS) ask this class; the string and new-translation areas do not.
 * The area's default, then `perflocale/automation/decision`, then the rules,
 * which only make it stricter: `publish` needs the acting user's publish right, and a change no user started
 * (WP-Cron, WP-CLI, user 0) is never looser than the default. resolve() is pure, so the rule order is unit tested.
 */
final class Policy {

	/** Areas. */
	public const AREA_NEW_TRANSLATION  = 'new_translation';
	public const AREA_STRING           = 'string';
	public const AREA_PUBLISHED_UPDATE = 'published_update';
	public const AREA_DRAFT_UPDATE     = 'draft_update';

	/**
	 * The areas of a resync write to a post.
	 *
	 * @var string[]
	 */
	public const RESYNC_AREAS = [ self::AREA_PUBLISHED_UPDATE, self::AREA_DRAFT_UPDATE ];

	/** Decisions. */
	public const PUBLISH           = 'publish';
	public const PUBLISH_WHEN_SAFE = 'publish_when_safe';
	public const DRAFT             = 'draft';
	public const REVIEW            = 'review';

	/**
	 * The values each area offers, its default first.
	 *
	 * @var array<string, string[]>
	 */
	public const AREA_VALUES = [
		self::AREA_NEW_TRANSLATION  => [ self::DRAFT, self::PUBLISH ],
		self::AREA_STRING           => [ self::PUBLISH, self::REVIEW ],
		self::AREA_PUBLISHED_UPDATE => [ self::REVIEW, self::PUBLISH_WHEN_SAFE, self::PUBLISH ],
		self::AREA_DRAFT_UPDATE     => [ self::DRAFT, self::REVIEW ],
	];

	/**
	 * The strictest value of each area.
	 *
	 * @var array<string, string>
	 */
	public const AREA_STRICTEST = [
		self::AREA_NEW_TRANSLATION  => self::DRAFT,
		self::AREA_STRING           => self::REVIEW,
		self::AREA_PUBLISHED_UPDATE => self::REVIEW,
		self::AREA_DRAFT_UPDATE     => self::REVIEW,
	];

	/**
	 * The flags that hold a change under every policy, in the order the reason names them.
	 *
	 * @var string[]
	 */
	public const DAMAGING_FLAGS = [ 'unsafe_markup', 'kses', 'structure', 'anchor', 'exceptions', 'ambiguous', 'heuristic_base', 'protected_source' ];

	/** The flag of a rejected machine output. */
	public const FLAG_QUALITY = 'mt_quality';

	/** The note that the revision walk stopped at its budget, so every block counts as a person's. */
	public const FLAG_REVISIONS_UNREAD = 'revisions_unread';

	/**
	 * The notes: flags shown to the reviewer that never make a change incomplete or unsafe.
	 *
	 * @var string[]
	 */
	public const NOTE_FLAGS = [ self::FLAG_REVISIONS_UNREAD ];

	/** Actors. */
	public const ACTOR_USER   = 'user';
	public const ACTOR_CRON   = 'cron';
	public const ACTOR_CLI    = 'cli';
	public const ACTOR_ERASED = 'erased';

	/**
	 * The default decision of an area.
	 *
	 * @param string $area Area.
	 * @return string
	 */
	public static function area_default( string $area ): string {
		return self::AREA_VALUES[ $area ][0] ?? self::REVIEW;
	}

	/**
	 * The actor of a change made now: a signed-in user, else WP-Cron, else WP-CLI.
	 *
	 * @return string
	 */
	public static function current_actor(): string {
		return self::actor_for( get_current_user_id() );
	}

	/**
	 * The actor of a change made for a user id: user when positive, else as current_actor() says without a user.
	 *
	 * @param int $user_id User id the change acts for.
	 * @return string
	 */
	public static function actor_for( int $user_id ): string {
		if ( $user_id > 0 ) {
			return self::ACTOR_USER;
		}

		if ( wp_doing_cron() ) {
			return self::ACTOR_CRON;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::ACTOR_CLI;
		}

		return self::ACTOR_USER;
	}

	/**
	 * Whether a value is allowed for an area.
	 *
	 * @param string $area  Area.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function is_allowed( string $area, mixed $value ): bool {
		return is_string( $value ) && in_array( $value, self::AREA_VALUES[ $area ] ?? [], true );
	}

	/**
	 * Apply the rules to a (possibly filtered) decision; pure, missing facts count as "not the case", each fact only
	 * tightens. Order: a value outside the set, publishing without the right, a person's edit or block, a damaging flag,
	 * the post lock, a missed schedule, then the area's rule.
	 *
	 * @param array{area: string, decision: mixed, can_publish: bool, safe?: bool, damaging?: string, quality?: bool, edited_since?: bool, person_block?: bool, post_locked?: bool, missed_schedule?: bool} $facts The area, the filtered value, whether publishing is allowed, and the extra facts.
	 * @return array{decision: string, reason: string}
	 */
	public static function resolve( array $facts ): array {
		$area = $facts['area'];

		if ( ! isset( self::AREA_VALUES[ $area ] ) ) {
			return [
				'decision' => self::REVIEW,
				'reason'   => 'unknown_area',
			];
		}

		$decision  = $facts['decision'];
		$strictest = self::AREA_STRICTEST[ $area ];
		$answer    = static fn ( mixed $decision, string $reason ): array => [
			'decision' => Scalar::str( $decision ),
			'reason'   => $reason,
		];

		if ( ! self::is_allowed( $area, $decision ) ) {
			return $answer( $strictest, 'invalid_decision' );
		}

		if ( ( self::PUBLISH === $decision || self::PUBLISH_WHEN_SAFE === $decision ) && ! $facts['can_publish'] ) {
			return $answer( $strictest, 'no_publish_cap' );
		}

		if ( ! empty( $facts['edited_since'] ) ) {
			return $answer( $strictest, 'human_edit' );
		}

		if ( ! empty( $facts['person_block'] ) ) {
			return $answer( $strictest, 'person_block' );
		}

		$damaging = Scalar::str( $facts['damaging'] ?? '' );

		if ( '' !== $damaging ) {
			return $answer( $strictest, $damaging );
		}

		if ( ! empty( $facts['post_locked'] ) ) {
			return $answer( $strictest, 'post_locked' );
		}

		if ( ! empty( $facts['missed_schedule'] ) ) {
			return $answer( $strictest, 'missed_schedule' );
		}

		if ( self::AREA_PUBLISHED_UPDATE === $area ) {
			if ( self::PUBLISH_WHEN_SAFE === $decision ) {
				return empty( $facts['safe'] ) ? $answer( self::REVIEW, 'not_safe' ) : $answer( self::PUBLISH, 'policy' );
			}

			if ( self::PUBLISH === $decision && ! empty( $facts['quality'] ) ) {
				return $answer( self::REVIEW, 'mt_quality' );
			}
		}

		return $answer( $decision, 'policy' );
	}

	/**
	 * The facts a proposal's counts and flags give: `safe` (nothing machine translated, left in the source,
	 * near matched or on an unknown field base; no flag but a note), `damaging` (first DAMAGING_FLAGS hit), `quality`.
	 *
	 * @param array<string, mixed> $counts The summary counts.
	 * @param array<mixed>         $flags  The flags.
	 * @return array{safe: bool, damaging: string, quality: bool}
	 */
	public static function facts_of( array $counts, array $flags ): array {
		$flags    = Scalar::strings( $flags );
		$damaging = '';

		foreach ( self::DAMAGING_FLAGS as $flag ) {
			if ( in_array( $flag, $flags, true ) ) {
				$damaging = $flag;
				break;
			}
		}

		$safe = [] === self::counted_flags( $flags );

		foreach ( [ 'machine_translated', 'left_source', 'fuzzy', 'unknown_base' ] as $count ) {
			if ( Scalar::int( $counts[ $count ] ?? 0 ) > 0 ) {
				$safe = false;
			}
		}

		return [
			'safe'     => $safe,
			'damaging' => $damaging,
			'quality'  => in_array( self::FLAG_QUALITY, $flags, true ),
		];
	}

	/**
	 * The flags that make a change incomplete and unsafe: every flag but the notes.
	 *
	 * @param array<mixed> $flags The flags.
	 * @return list<string>
	 */
	public static function counted_flags( array $flags ): array {
		return array_values( array_diff( Scalar::strings( $flags ), self::NOTE_FLAGS ) );
	}

	/**
	 * Decide one change.
	 *
	 * @param array<string, mixed> $change area, language_id, user_id, actor, trigger_name, object_type, object_id, source_id, post_type; for a resync change also its summary (counts and flags), so a filter can decide on them.
	 * @param array<string, mixed> $args   Context passed to the filter.
	 * @param array<string, mixed> $facts  The extra facts of resolve().
	 * @return array{decision: string, reason: string}
	 */
	public static function decide( array $change, array $args = [], array $facts = [] ): array {
		$area = Scalar::str( $change['area'] ?? '' );
		$base = self::area_default( $area );

		/**
		 * Filters the automation decision for one Content ReSync change (area published_update or draft_update);
		 * the rules after it only make it stricter.
		 *
		 * @hook perflocale/automation/decision
		 * @since 1.0.8
		 *
		 * @param string               $decision The area's default: 'review' (published_update) or 'draft' (draft_update).
		 * @param array<string, mixed> $change   The change (area, object, language, user, trigger).
		 * @param array<string, mixed> $args     Context.
		 */
		$filtered = apply_filters( 'perflocale/automation/decision', $base, $change, $args );

		if ( ! self::is_allowed( $area, $filtered ) && isset( self::AREA_VALUES[ $area ] ) ) {
			_doing_it_wrong(
				'perflocale/automation/decision',
				esc_html(
					sprintf(
						/* translators: 1: area name, 2: allowed values */
						__( 'The automation decision for area "%1$s" must be one of: %2$s. The strictest value is used.', 'perflocale' ),
						$area,
						implode( ', ', self::AREA_VALUES[ $area ] )
					)
				),
				'1.0.8'
			);
		}

		return self::resolve(
			[
				'area'            => $area,
				'decision'        => $filtered,
				'can_publish'     => self::can_publish( $change ),
				'safe'            => ! empty( $facts['safe'] ),
				'damaging'        => Scalar::str( $facts['damaging'] ?? '' ),
				'quality'         => ! empty( $facts['quality'] ),
				'edited_since'    => ! empty( $facts['edited_since'] ),
				'person_block'    => ! empty( $facts['person_block'] ),
				'post_locked'     => ! empty( $facts['post_locked'] ),
				'missed_schedule' => ! empty( $facts['missed_schedule'] ),
			]
		);
	}

	/**
	 * Whether this change may be published for the user it acts for. For `published_update` that is
	 * `edit_post` on the translation; `draft_update` offers no publish value.
	 *
	 * @param array<string, mixed> $change The change.
	 * @return bool
	 */
	public static function can_publish( array $change ): bool {
		$actor   = Scalar::str( $change['actor'] ?? self::ACTOR_USER );
		$user_id = Scalar::int( $change['user_id'] ?? 0 );
		$area    = Scalar::str( $change['area'] ?? '' );

		if ( self::ACTOR_ERASED === $actor ) {
			return false;
		}

		// No user started this change: it may publish only where publishing is the area's default.
		if ( $user_id <= 0 || self::ACTOR_USER !== $actor ) {
			return self::PUBLISH === self::area_default( $area );
		}

		if ( self::AREA_STRING === $area ) {
			return user_can( $user_id, 'perflocale_manage_translations' );
		}

		if ( self::AREA_PUBLISHED_UPDATE === $area ) {
			$object_id = Scalar::int( $change['object_id'] ?? 0 );

			return $object_id > 0 && user_can( $user_id, 'edit_post', $object_id );
		}

		if ( self::AREA_NEW_TRANSLATION === $area ) {
			$post_type = Scalar::str( $change['post_type'] ?? '' );
			$object    = '' !== $post_type ? get_post_type_object( $post_type ) : null;

			if ( ! $object instanceof \WP_Post_Type ) {
				return false;
			}

			$cap = isset( $object->cap->publish_posts ) && is_string( $object->cap->publish_posts ) ? $object->cap->publish_posts : 'publish_posts';

			return user_can( $user_id, $cap );
		}

		return false;
	}
}
