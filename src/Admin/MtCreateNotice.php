<?php
/**
 * The notice that machine translation was not saved, carried to the post's edit screen.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the Create action was set to machine-translate a new translation and
 * the machine translation was refused or failed, the translation is created
 * as an untranslated copy of the original, as before, and the screen the
 * user lands on says why.
 *
 * The reason travels as a short-lived record for that user and that post (a
 * transient, ten minutes), read once on the screen that shows it and
 * deleted. Not as text or a code in the redirect URL: the reason is the
 * provider's or the save guard's own message, which a code cannot carry,
 * and a link someone else crafts cannot put a notice on anyone's screen.
 *
 * Shown by the block editor (its notices, from the editor script's data),
 * the classic editor (an admin notice) and, when the new post has no edit
 * screen, the Translations screen the action falls back to.
 *
 * Auto-translate on publish runs later, in a background job with no user,
 * and records its refusal or failures on the published post for the user
 * who published it (the publishing request notes who that was), or for the
 * post's author when nobody did: a scheduled publish, WP-CLI. The record
 * waits up to seven days for that user's next load of the post's edit
 * screen. A later run that saves every translation removes it.
 */
final class MtCreateNotice {

	/**
	 * How long a record waits for its screen.
	 */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * How long an auto-translate on publish record waits for its screen.
	 */
	private const PUBLISH_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * How long the publishing user is kept for the background job.
	 */
	private const PUBLISHER_TTL = DAY_IN_SECONDS;

	/**
	 * Longest reason kept, in characters.
	 */
	private const MAX_REASON = 500;

	/**
	 * Notices already taken in this request, by post id ('' when none), so
	 * the editor script's data and the admin notice read one record once.
	 *
	 * @var array<int, string>
	 */
	private static array $taken = [];

	/**
	 * Transient name of a record.
	 *
	 * @param int $user_id User.
	 * @param int $post_id Created translation.
	 * @return string
	 */
	private static function key( int $user_id, int $post_id ): string {
		return 'perflocale_mt_create_notice_' . $user_id . '_' . $post_id;
	}

	/**
	 * Transient name of the user who published a post, for its auto-translate job.
	 *
	 * @param int $post_id Published post.
	 * @return string
	 */
	private static function publisher_key( int $post_id ): string {
		return 'perflocale_mt_publisher_' . $post_id;
	}

	/**
	 * Note the current user as the one who published a post, for the
	 * auto-translate job that runs later without a user. Nothing without a
	 * current user (a scheduled publish, WP-CLI).
	 *
	 * @param int $post_id Published post.
	 * @return void
	 */
	public static function remember_publisher( int $post_id ): void {
		$user_id = get_current_user_id();

		if ( $user_id > 0 && $post_id > 0 ) {
			set_transient( self::publisher_key( $post_id ), $user_id, self::PUBLISHER_TTL );
		}
	}

	/**
	 * The user who published a post, once: the note is deleted.
	 *
	 * @param int $post_id Published post.
	 * @return int The user's id, or 0 when none was noted.
	 */
	public static function take_publisher( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		$key    = self::publisher_key( $post_id );
		$stored = get_transient( $key );

		if ( false === $stored ) {
			return 0;
		}

		delete_transient( $key );

		return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
	}

	/**
	 * Record what auto-translate on publish could not save, for a user, on
	 * the published post; replaces the post's earlier publish record. No
	 * failures removes it.
	 *
	 * Each failure is one language not saved: `reason` the refusal or
	 * failure message, `own` when it already says it was not saved (a save
	 * guard's refusal), `language` the language's name, '' when the refusal
	 * covers every language.
	 *
	 * @param int                              $user_id  The user to show it to.
	 * @param int                              $post_id  Published post.
	 * @param array<int, array<string, mixed>> $failures Failures, in language order.
	 * @return void
	 */
	public static function remember_publish( int $user_id, int $post_id, array $failures ): void {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return;
		}

		$items = [];

		foreach ( $failures as $failure ) {
			$reason   = $failure['reason'] ?? '';
			$language = $failure['language'] ?? '';
			$reason   = is_string( $reason ) ? self::plain( $reason ) : '';

			if ( '' !== $reason ) {
				$items[] = [
					'reason'   => $reason,
					'own'      => true === ( $failure['own'] ?? false ),
					'language' => is_string( $language ) ? self::plain( $language ) : '',
				];
			}
		}

		$key    = self::key( $user_id, $post_id );
		$stored = get_transient( $key );
		$record = is_array( $stored ) ? $stored : [];

		if ( [] === $items ) {
			if ( ! isset( $record['publish'] ) ) {
				return;
			}

			unset( $record['publish'] );

			if ( [] === $record ) {
				delete_transient( $key );
			} else {
				set_transient( $key, $record, self::TTL );
			}

			return;
		}

		$record['publish'] = $items;

		set_transient( $key, $record, self::PUBLISH_TTL );
	}

	/**
	 * Record why the new translation was created without machine translation.
	 *
	 * @param int    $post_id    Created translation.
	 * @param string $reason     The refusal or failure message.
	 * @param bool   $says_not_saved The reason already says the translation was
	 *                           not saved (a save guard's refusal), so the
	 *                           notice does not say it again.
	 * @return void
	 */
	public static function remember( int $post_id, string $reason, bool $says_not_saved = false ): void {
		$user_id = get_current_user_id();
		$reason  = self::plain( $reason );

		if ( $user_id <= 0 || $post_id <= 0 || '' === $reason ) {
			return;
		}

		set_transient(
			self::key( $user_id, $post_id ),
			[
				'reason' => $reason,
				'own'    => $says_not_saved,
			],
			self::TTL
		);
	}

	/**
	 * The current user's notice for a post, once: the record is deleted.
	 *
	 * @param int $post_id Post.
	 * @return string The notice text, or ''.
	 */
	public static function take( int $post_id ): string {
		if ( isset( self::$taken[ $post_id ] ) ) {
			return self::$taken[ $post_id ];
		}

		$user_id = get_current_user_id();
		$notice  = '';

		if ( $user_id > 0 && $post_id > 0 ) {
			$key    = self::key( $user_id, $post_id );
			$stored = get_transient( $key );

			if ( false !== $stored ) {
				delete_transient( $key );
			}

			$record = is_array( $stored ) ? $stored : [];
			$reason = $record['reason'] ?? '';
			$parts  = [];

			if ( is_string( $reason ) && '' !== $reason ) {
				$parts[] = self::message( $reason, true === ( $record['own'] ?? false ) );
			}

			if ( isset( $record['publish'] ) && is_array( $record['publish'] ) ) {
				$parts = array_merge( $parts, self::publish_lines( $record['publish'] ) );
			}

			$notice = implode( ' ', $parts );
		}

		self::$taken[ $post_id ] = $notice;

		return $notice;
	}

	/**
	 * The notice text for a reason.
	 *
	 * @param string $reason     Reason.
	 * @param bool   $says_not_saved The reason already says the translation was not saved.
	 * @return string
	 */
	public static function message( string $reason, bool $says_not_saved = false ): string {
		$reason = rtrim( $reason, " \t\n\r\0\x0B." ) . '.';

		if ( $says_not_saved ) {
			return sprintf(
				/* translators: %s: why machine translation was not saved, a sentence that already says it was not saved. */
				__( '%s This translation is an untranslated copy of the original.', 'perflocale' ),
				$reason
			);
		}

		return sprintf(
			/* translators: %s: why machine translation was not saved, one or more sentences. */
			__( 'Machine translation was not saved: %s This translation is an untranslated copy of the original.', 'perflocale' ),
			$reason
		);
	}

	/**
	 * The notice lines of a publish record: one per reason, naming the
	 * languages it left unchanged, in the order recorded.
	 *
	 * @param array<mixed> $items The record's items.
	 * @return array<int, string>
	 */
	private static function publish_lines( array $items ): array {
		$groups = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['reason'] ?? null ) || '' === $item['reason'] ) {
				continue;
			}

			$own   = true === ( $item['own'] ?? false );
			$group = ( $own ? 'own:' : 'other:' ) . $item['reason'];

			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = [
					'reason'    => $item['reason'],
					'own'       => $own,
					'languages' => [],
				];
			}

			$language = is_string( $item['language'] ?? null ) ? $item['language'] : '';

			if ( '' !== $language && ! in_array( $language, $groups[ $group ]['languages'], true ) ) {
				$groups[ $group ]['languages'][] = $language;
			}
		}

		$lines = [];

		foreach ( $groups as $group ) {
			$lines[] = self::publish_message( $group['reason'], $group['own'], $group['languages'] );
		}

		return $lines;
	}

	/**
	 * The notice text for an auto-translate on publish reason.
	 *
	 * @param string             $reason         Reason.
	 * @param bool               $says_not_saved The reason already says the translation was not saved.
	 * @param array<int, string> $languages      Names of the languages it left unchanged; none when
	 *                                           the reason covers every language.
	 * @return string
	 */
	public static function publish_message( string $reason, bool $says_not_saved, array $languages ): string {
		$reason = rtrim( $reason, " \t\n\r\0\x0B." ) . '.';
		$tail   = [] === $languages
			? __( 'Its translations were not changed.', 'perflocale' )
			: sprintf(
				/* translators: %s: a language name, or a list of language names. */
				_n( 'The %s translation was not changed.', 'The %s translations were not changed.', count( $languages ), 'perflocale' ),
				wp_sprintf( '%l', $languages )
			);

		if ( $says_not_saved ) {
			return $reason . ' ' . $tail;
		}

		return sprintf(
			/* translators: 1: why machine translation was not saved, one or more sentences. 2: which translations were not changed, a sentence. */
			__( 'Machine translation was not saved: %1$s %2$s', 'perflocale' ),
			$reason,
			$tail
		);
	}

	/**
	 * The admin notice on the classic editor and on the Translations screen.
	 * The block editor shows the notice from its script's data instead.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		$post_id = 0;
		$page    = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the record is the current user's and is checked against edit_post.

		if ( 'post.php' === ( $GLOBALS['pagenow'] ?? '' ) && isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
			$post    = get_post( $post_id );

			if ( ! $post instanceof \WP_Post || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
				return;
			}
		} elseif ( 'perflocale-translations' === $page && isset( $_GET['perflocale_created'] ) && is_numeric( $_GET['perflocale_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
			$post_id = absint( $_GET['perflocale_created'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
		}

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$notice = self::take( $post_id );

		if ( '' === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( $notice )
		);
	}

	/**
	 * The notice for the block editor's script data, or ''.
	 *
	 * @param int $post_id Post being edited.
	 * @return string
	 */
	public static function editor_message( int $post_id ): string {
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		return self::take( $post_id );
	}

	/**
	 * A message as plain text: no tags, entities decoded, credentials masked,
	 * one line, capped.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private static function plain( string $text ): string {
		$text = \PerfLocale\Util\SecretMasker::mask( wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_REASON ) : substr( $text, 0, self::MAX_REASON );
	}
}
