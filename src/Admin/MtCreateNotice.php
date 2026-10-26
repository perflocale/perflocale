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
 * Tells the user on the post's screen why machine translation was not saved (Create, or publish).
 *
 * A per-user, per-post transient read once, never a URL parameter: a crafted link must not show a notice.
 * See docs-ai/11-code-rationale.md, "MtCreateNotice: notice records".
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
	 * Longest reason kept, in characters.
	 */
	private const MAX_REASON = 500;

	/**
	 * Prefix of the user meta `perflocale_mt_notice_<blog>_<post>` => expiry of a waiting record.
	 *
	 * AdminController::mt_create_notice_waiting() reads the same key.
	 */
	public const WAITING_META_PREFIX = 'perflocale_mt_notice_';

	/**
	 * Notices taken in this request by post id, so script data and admin notice share one read.
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
	 * User meta key that says a record for a post on this site is waiting.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	private static function waiting_key( int $post_id ): string {
		return self::WAITING_META_PREFIX . get_current_blog_id() . '_' . $post_id;
	}

	/**
	 * Note that a record waits for a user, deleting the user's expired entries first.
	 *
	 * @param int $user_id User.
	 * @param int $post_id Post.
	 * @param int $ttl     Seconds the record lives.
	 * @return void
	 */
	private static function mark_waiting( int $user_id, int $post_id, int $ttl ): void {
		$all = get_user_meta( $user_id );
		$now = time();

		foreach ( is_array( $all ) ? $all : [] as $meta_key => $values ) {
			if (
				is_string( $meta_key )
				&& 1 === preg_match( '/^' . preg_quote( self::WAITING_META_PREFIX, '/' ) . '([0-9]+)_([0-9]+)$/', $meta_key, $m )
				&& is_array( $values )
				&& is_numeric( $values[0] ?? null )
				&& (int) $values[0] < $now
			) {
				delete_user_meta( $user_id, self::WAITING_META_PREFIX . $m[1] . '_' . $m[2] );
			}
		}

		update_user_meta( $user_id, self::waiting_key( $post_id ), $now + $ttl );
	}

	/**
	 * Transient name of the user who published a post (written by 1.0.7.3 and earlier for their queued event).
	 *
	 * @param int $post_id Published post.
	 * @return string
	 */
	private static function publisher_key( int $post_id ): string {
		return 'perflocale_mt_publisher_' . $post_id;
	}

	/**
	 * The user who published a post; the note is deleted unless $keep.
	 *
	 * @param int  $post_id Published post.
	 * @param bool $keep    Leave the note (a run that may still be retried).
	 * @return int The user's id, or 0 when none was noted.
	 */
	public static function take_publisher( int $post_id, bool $keep = false ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		$key    = self::publisher_key( $post_id );
		$stored = get_transient( $key );

		if ( false === $stored ) {
			return 0;
		}

		if ( ! $keep ) {
			delete_transient( $key );
		}

		return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
	}

	/**
	 * Record what auto-translate on publish could not save; replaces the publish record, and none removes it.
	 *
	 * Failure keys: `reason`, `own` (it says "not saved" itself), `language` ('' = all), `warning` (saved, with gaps).
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
					'warning'  => true === ( $failure['warning'] ?? false ),
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
				delete_user_meta( $user_id, self::waiting_key( $post_id ) );
			} else {
				set_transient( $key, $record, self::TTL );
				self::mark_waiting( $user_id, $post_id, self::TTL );
			}

			return;
		}

		$record['publish'] = $items;

		set_transient( $key, $record, self::PUBLISH_TTL );
		self::mark_waiting( $user_id, $post_id, self::PUBLISH_TTL );
	}

	/**
	 * Record why the new translation was created without machine translation.
	 *
	 * @param int    $post_id    Created translation.
	 * @param string $reason     The refusal or failure message.
	 * @param bool   $says_not_saved The reason already says it was not saved, so the notice does not repeat it.
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
		self::mark_waiting( $user_id, $post_id, self::TTL );
	}

	/**
	 * A remember_publish() item for a translation saved with a builder warning, or none.
	 *
	 * @param array<string, mixed> $result   translate_post() result.
	 * @param object               $language The target language.
	 * @return array<int, array<string, mixed>>
	 */
	public static function publish_warnings( array $result, object $language ): array {
		$warning = $result['builder_warning'] ?? '';
		$vars    = get_object_vars( $language );
		$name    = $vars['name'] ?? '';
		$slug    = $vars['slug'] ?? '';

		return is_string( $warning ) && '' !== $warning
			? [
				[
					'reason'   => $warning,
					'warning'  => true,
					'language' => is_string( $name ) && '' !== $name ? $name : ( is_string( $slug ) ? $slug : '' ),
				],
			]
			: [];
	}

	/**
	 * Record a warning of the machine translation that created the post; shown as it is.
	 *
	 * @param int    $post_id Created translation.
	 * @param string $warning What the translation left out.
	 * @return void
	 */
	public static function remember_warning( int $post_id, string $warning ): void {
		self::remember( $post_id, $warning );

		$key    = self::key( get_current_user_id(), $post_id );
		$record = get_transient( $key );

		if ( is_array( $record ) ) {
			$record['warning'] = true;
			set_transient( $key, $record, self::TTL );
		}
	}

	/**
	 * The current user's notice for a post, once (record and waiting entry are deleted).
	 *
	 * Without a waiting entry the record is not read.
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

		if ( $user_id > 0 && $post_id > 0 && AdminController::mt_create_notice_waiting( $post_id ) ) {
			$key    = self::key( $user_id, $post_id );
			$stored = get_transient( $key );

			if ( false !== $stored ) {
				delete_transient( $key );
			}

			delete_user_meta( $user_id, self::waiting_key( $post_id ) );

			$record = is_array( $stored ) ? $stored : [];
			$reason = $record['reason'] ?? '';
			$parts  = [];

			if ( is_string( $reason ) && '' !== $reason ) {
				$parts[] = true === ( $record['warning'] ?? false ) ? $reason : self::message( $reason, true === ( $record['own'] ?? false ) );
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
	 * The lines of a publish record: one per reason, with the languages it left unchanged.
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
			$warn  = true === ( $item['warning'] ?? false );
			$group = ( $warn ? 'warning:' : ( $own ? 'own:' : 'other:' ) ) . $item['reason'];

			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = [
					'reason'    => $item['reason'],
					'own'       => $own,
					'warning'   => $warn,
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
			$lines[] = $group['warning']
				? $group['reason'] . ( [] === $group['languages'] ? '' : ' ' . sprintf(
					/* translators: %s: a language name, or a list of language names. */
					_n( 'Affected translation: %s.', 'Affected translations: %s.', count( $group['languages'] ), 'perflocale' ),
					wp_sprintf( '%l', $group['languages'] )
				) )
				: self::publish_message( $group['reason'], $group['own'], $group['languages'] );
		}

		return $lines;
	}

	/**
	 * The notice text for an auto-translate on publish reason.
	 *
	 * @param string             $reason         Reason.
	 * @param bool               $says_not_saved The reason already says the translation was not saved.
	 * @param array<int, string> $languages      Languages it left unchanged; none when it covers every language.
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
	 * The admin notice on the classic editor and the Translations screen (the block editor uses script data).
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		$post_id        = 0;
		$on_post_screen = false;
		$page           = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the record is the current user's and is checked against edit_post.

		if ( 'post.php' === ( $GLOBALS['pagenow'] ?? '' ) && isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
			$post_id        = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
			$on_post_screen = true;
		} elseif ( 'perflocale-translations' === $page && isset( $_GET['perflocale_created'] ) && is_numeric( $_GET['perflocale_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
			$post_id = absint( $_GET['perflocale_created'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, as above.
		}

		if ( ! AdminController::mt_create_notice_waiting( $post_id ) ) {
			return;
		}

		if ( $on_post_screen ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
				return;
			}
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
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
		if ( ! AdminController::mt_create_notice_waiting( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		return self::take( $post_id );
	}

	/**
	 * A message as plain text: no tags, entities decoded, credentials masked, one line, capped.
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
