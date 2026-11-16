<?php
/**
 * Admin: the "update waiting" read and the review link.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Repository\PendingChangeReads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a held post change shows (edit screen, source panel rows, Translations page row): one indexed read per
 * admin page for the posts shown, linking to "Review update" for users who may decide. Never on front-end,
 * REST, AJAX or cron requests.
 */
final class ReviewUpdate {

	/** The review screen's page slug (no menu entry). */
	public const PAGE = 'perflocale-review-update';

	/**
	 * The review screen of one change.
	 *
	 * @param int $change_id Change id.
	 * @return string
	 */
	public static function url( int $change_id ): string {
		return add_query_arg(
			[
				'page'   => self::PAGE,
				'change' => $change_id,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The pending update of each given post the current user may decide:
	 * one statement on the `target` index for the whole screen.
	 *
	 * @param array<int, mixed> $post_ids The posts a screen shows (a translation, a source's translations, a page of rows).
	 * @return array<int, array{id: int, url: string}> Keyed by post id.
	 */
	public static function pending_updates( array $post_ids ): array {
		if ( [] === $post_ids || ! ResyncLinks::can_translate() || ! PendingChangeReads::available() ) {
			return [];
		}

		return self::links( array_map( static fn( array $row ): int => $row['id'], PendingChangeReads::for_objects( 'post', $post_ids ) ) );
	}

	/**
	 * The review link of each pending change already read the current user may decide; the read checked
	 * perflocale_translate (ResyncSwitch::waiting_updates(), pending_updates()).
	 *
	 * @param array<int, int> $changes Change id by post id.
	 * @return array<int, array{id: int, url: string}> Keyed by post id.
	 */
	public static function links( array $changes ): array {
		$out = [];

		foreach ( $changes as $object_id => $change_id ) {
			// ChangeService::may_decide() for a post and `approve`, without loading it.
			if ( $object_id <= 0 || ! \PerfLocale\Helper::user_can_edit_object( $object_id ) ) {
				continue;
			}

			$out[ $object_id ] = [
				'id'  => $change_id,
				'url' => self::url( $change_id ),
			];
		}

		return $out;
	}
}
