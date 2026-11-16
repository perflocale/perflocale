<?php
/**
 * Automation personal data: export, erasure, user removal.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\Database\Repository\PendingChangeRepository;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Personal data of the automation changes table: `user_id` (who a change acts for) and `decided_by`.
 *
 * The export never lists proposed or replaced text, or another user's id. Erasure sets the subject's ids
 * to 0 with the actor `erased`, which holds no right.
 */
final class PersonalData {

	/** Exporter / eraser slug (PrivacyIntegration registers both, ReSync on or off). */
	public const SLUG = 'perflocale-automation';

	/** Changes per exporter page. */
	private const EXPORT_PER_PAGE = 200;

	/** Rows per anonymising statement. */
	private const ERASE_BATCH = 5000;

	/** Option `<prefix><user id>` (not autoloaded): {page, last, at} of the export's last page, so the next page reads after it. */
	public const CURSOR_PREFIX = 'perflocale_pd_export_';

	/**
	 * Export the changes a user started or decided.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          1-based page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}|\WP_Error Error when the rows cannot be read, so the export stops instead of completing without them.
	 */
	public static function export( string $email_address, int $page = 1 ): array|\WP_Error {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User || ! ChangeService::available() ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$user_id = (int) $user->ID;
		$page    = max( 1, $page );
		$cursor  = $page > 1 ? get_option( self::CURSOR_PREFIX . $user_id ) : false;

		// WordPress asks the pages in order: page n reads after page n-1's last id (written in the last hour); else by offset.
		$after = is_array( $cursor ) && Scalar::int( $cursor['page'] ?? 0 ) === $page - 1 && Scalar::int( $cursor['at'] ?? 0 ) > time() - HOUR_IN_SECONDS ? Scalar::int( $cursor['last'] ?? 0 ) : 0;
		$rows  = ( new PendingChangeRepository() )->rows_for_user( $user_id, self::EXPORT_PER_PAGE, ( $page - 1 ) * self::EXPORT_PER_PAGE, $after );

		if ( null === $rows ) {
			return new \WP_Error(
				'perflocale_export_automation_failed',
				__( 'PerfLocale could not read its Content ReSync records. Run the export again.', 'perflocale' )
			);
		}

		$names = self::language_names();
		$data  = [];

		foreach ( $rows as $row ) {
			$started = $row['user_id'] === $user_id;
			$decided = $row['decided_by'] === $user_id;
			$role    = $started && $decided
				? __( 'Started and decided', 'perflocale' )
				: ( $started ? __( 'Started', 'perflocale' ) : __( 'Decided', 'perflocale' ) );

			$data[] = [
				'group_id'    => self::SLUG,
				'group_label' => __( 'PerfLocale Content ReSync updates', 'perflocale' ),
				'item_id'     => self::SLUG . '-' . $row['id'],
				'data'        => [
					[
						'name'  => __( 'Change', 'perflocale' ),
						'value' => (string) $row['id'],
					],
					[
						'name'  => __( 'Kind of update', 'perflocale' ),
						'value' => self::area_label( $row['area'] ),
					],
					[
						'name'  => __( 'Object', 'perflocale' ),
						'value' => self::object_label( $row['object_type'], $row['object_id'] ),
					],
					[
						'name'  => __( 'Language', 'perflocale' ),
						'value' => $names[ $row['language_id'] ] ?? (string) $row['language_id'],
					],
					[
						'name'  => __( 'Status', 'perflocale' ),
						'value' => ChangeService::status_label( $row['status'] ),
					],
					[
						'name'  => __( 'Created at (UTC)', 'perflocale' ),
						'value' => $row['created_at'],
					],
					[
						'name'  => __( 'Decided at (UTC)', 'perflocale' ),
						'value' => $row['decided_at'],
					],
					[
						'name'  => __( 'Your role', 'perflocale' ),
						'value' => $role,
					],
				],
			];
		}

		$done = count( $rows ) < self::EXPORT_PER_PAGE;

		if ( $done ) {
			delete_option( self::CURSOR_PREFIX . $user_id );
		} else {
			update_option(
				self::CURSOR_PREFIX . $user_id,
				[
					'page' => $page,
					'last' => $rows[ count( $rows ) - 1 ]['id'],
					'at'   => time(),
				],
				false
			);
		}

		return [
			'data' => $data,
			'done' => $done,
		];
	}

	/**
	 * Erase a user's ids from the automation tables, one batch per call (`done` false while rows remain).
	 * A failed statement reports one retained item and stops: WordPress would otherwise call again forever.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          Page (unused).
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email_address, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page.
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User || ! ChangeService::available() ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		$user_id = (int) $user->ID;
		$repo    = new PendingChangeRepository();
		$changed = $repo->anonymize_user( $user_id, self::ERASE_BATCH );
		$failed  = [
			'items_removed'  => max( 0, (int) $changed ),
			'items_retained' => 1,
			'messages'       => [ __( 'PerfLocale could not update its Content ReSync records, so they may still contain your user ID. Run the erasure again.', 'perflocale' ) ],
			'done'           => true,
		];

		if ( null === $changed ) {
			return $failed;
		}

		// An unread remainder is not a finished erasure.
		$left = $repo->has_rows_for_user( $user_id );

		if ( null === $left ) {
			return $failed;
		}

		$messages = [];

		// $changed counts ids removed: a record naming the user as starter and decider counts twice.
		if ( $changed > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of places a user id was removed from */
				_n(
					'Your user ID was removed from %d place in the PerfLocale Content ReSync records.',
					'Your user ID was removed from %d places in the PerfLocale Content ReSync records.',
					$changed,
					'perflocale'
				),
				$changed
			);
		}

		return [
			'items_removed'  => $changed,
			'items_retained' => 0,
			'messages'       => $messages,
			'done'           => ! $left,
		];
	}

	/**
	 * `delete_user` / `wpmu_delete_user`: zero the user's job attribution, then their automation ids.
	 * One call, so Bootstrap's always-registered closure holds a single call.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public static function on_user_deleted( int $user_id ): void {
		\PerfLocale\Background\JobState::anonymize_for_user( $user_id );
		self::forget_user( $user_id );
	}

	/**
	 * Remove a deleted user's ids from this blog's automation tables, in batches until none remain, and their export cursor.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function forget_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		delete_option( self::CURSOR_PREFIX . $user_id );

		if ( ! ChangeService::available() ) {
			return;
		}

		$repo = new PendingChangeRepository();

		do {
			$changed = $repo->anonymize_user( $user_id, self::ERASE_BATCH );
		} while ( null !== $changed && $changed > 0 );
	}

	/**
	 * The kind of update a change is, in words; an unknown area as it is.
	 *
	 * @param string $area The change's area.
	 * @return string
	 */
	public static function area_label( string $area ): string {
		return match ( $area ) {
			Policy::AREA_PUBLISHED_UPDATE => __( 'Update of a published translation', 'perflocale' ),
			Policy::AREA_DRAFT_UPDATE     => __( 'Update of an unpublished translation', 'perflocale' ),
			Policy::AREA_STRING           => __( 'Update of a string translation', 'perflocale' ),
			Policy::AREA_NEW_TRANSLATION  => __( 'New translation', 'perflocale' ),
			default                       => $area,
		};
	}

	/**
	 * The object a change is about, in words ("Post 12"); an unknown type as "type #id".
	 *
	 * @param string $type The object type.
	 * @param int    $id   The object id.
	 * @return string
	 */
	public static function object_label( string $type, int $id ): string {
		return match ( $type ) {
			/* translators: %d: post id. */
			'post'   => sprintf( __( 'Post %d', 'perflocale' ), $id ),
			/* translators: %d: string id. */
			'string' => sprintf( __( 'String %d', 'perflocale' ), $id ),
			default  => $type . ' #' . $id,
		};
	}

	/**
	 * Language names by id, each with its slug: "German (de)".
	 *
	 * @return array<int, string>
	 */
	private static function language_names(): array {
		$out = [];

		foreach ( \PerfLocale\Plugin::get_instance()->lang_repo()->find_all() as $lang ) {
			$slug = Scalar::str( Scalar::prop( $lang, 'slug' ) );
			$name = Scalar::str( Scalar::prop( $lang, 'name' ) );

			/* translators: 1: language name, 2: language slug. */
			$out[ Scalar::int( Scalar::prop( $lang, 'id' ) ) ] = '' !== $name ? sprintf( __( '%1$s (%2$s)', 'perflocale' ), $name, $slug ) : $slug;
		}

		return $out;
	}
}
