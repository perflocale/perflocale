<?php
/**
 * A resync run waiting for "Start", and the last run's result.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The estimate step of a resync button: a run shown with its estimate is stored per token, and "Start" reads it
 * back only for the same user and blog and deletes it first, so the run is exactly the one the estimate described.
 * Only a per-post run may skip the step (starts_at_once()).
 */
final class RunRequest {

	/** Per-post "Resync from source" (block editor, Site Editor, classic panel, Translations row action). */
	public const KIND_POST = 'post';

	/** The Translations page bulk action "Update translations". */
	public const KIND_BULK = 'bulk';

	/** The Translations page "Update translations" dialog. */
	public const KIND_DIALOG = 'dialog';

	/** The Strings page "Translate outdated and missing strings". */
	public const KIND_STRINGS = 'strings';

	/** Every kind. */
	public const KINDS = [ self::KIND_POST, self::KIND_BULK, self::KIND_DIALOG, self::KIND_STRINGS ];

	/** A run request's lifetime, in seconds. */
	public const TTL = 1800;

	/** A result's lifetime, in seconds. */
	public const RESULT_TTL = 300;

	/** The run request transient's prefix. */
	public const REQUEST_PREFIX = 'perflocale_resync_request_';

	/** The result transient's prefix. */
	public const RESULT_PREFIX = 'perflocale_resync_result_';

	/** Seconds the start lock of a token is held. */
	public const START_LOCK_SECONDS = 60;

	/**
	 * Whether a run skips its estimate step: only a per-post run on a complete estimate that sends and creates nothing.
	 *
	 * @param string               $kind     The entry point's kind.
	 * @param array<string, mixed> $estimate The estimate envelope.
	 * @return bool
	 */
	public static function starts_at_once( string $kind, array $estimate ): bool {
		if ( self::KIND_POST !== $kind ) {
			return false;
		}

		return true === ( $estimate['complete'] ?? false )
			&& '' === Scalar::str( $estimate['stopped'] ?? '' )
			&& 0 === Scalar::int( $estimate['mt_chars'] ?? -1 )
			&& 0 === Scalar::int( $estimate['fills'] ?? -1 );
	}

	/**
	 * The capability the entry point of a kind requires.
	 *
	 * @param string $kind The kind.
	 * @return string
	 */
	public static function capability( string $kind ): string {
		return self::KIND_POST === $kind ? 'perflocale_translate' : 'perflocale_manage_translations';
	}

	/**
	 * Whether the current user holds any kind's capability: checked before a request is read, its own kind's after.
	 *
	 * @return bool
	 */
	public static function any_capability(): bool {
		foreach ( self::KINDS as $kind ) {
			if ( current_user_can( self::capability( $kind ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A token as given, when it has the shape of one; '' otherwise.
	 *
	 * @param mixed $token The raw value.
	 * @return string
	 */
	public static function valid_token( mixed $token ): string {
		$token = is_string( $token ) ? $token : '';

		return 1 === preg_match( '/^[a-f0-9]{32}\z/', $token ) ? $token : '';
	}

	/**
	 * Store a run request for the current user and return its token.
	 *
	 * @param string               $kind     The kind.
	 * @param array<string, mixed> $args     The run's arguments (normalised by the caller).
	 * @param array<string, mixed> $estimate The estimate the person sees.
	 * @param string               $back     The screen to return to.
	 * @param string               $retry    The per-post button's link ('' for other kinds).
	 * @return string The token, '' when it could not be stored.
	 */
	public static function create( string $kind, array $args, array $estimate, string $back, string $retry = '' ): string {
		$user = get_current_user_id();

		if ( $user <= 0 || ! in_array( $kind, self::KINDS, true ) ) {
			return '';
		}

		$token = bin2hex( random_bytes( 16 ) );
		$saved = set_transient(
			self::REQUEST_PREFIX . $token,
			[
				'user_id'  => $user,
				'blog_id'  => get_current_blog_id(),
				'kind'     => $kind,
				'args'     => $args,
				'estimate' => $estimate,
				'return'   => $back,
				'retry'    => $retry,
				'created'  => time(),
			],
			self::TTL
		);

		return $saved ? $token : '';
	}

	/**
	 * The token's run request, only when it belongs to the current user and blog and has not expired.
	 *
	 * @param string $token The token.
	 * @return array{user_id: int, blog_id: int, kind: string, args: array<string, mixed>, estimate: array<string, mixed>, return: string, retry: string, created: int}|null
	 * @phpstan-impure
	 */
	public static function get( string $token ): ?array {
		$token = self::valid_token( $token );
		$user  = get_current_user_id();

		if ( '' === $token || $user <= 0 ) {
			return null;
		}

		$stored = get_transient( self::REQUEST_PREFIX . $token );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$kind    = Scalar::str( $stored['kind'] ?? '' );
		$created = Scalar::int( $stored['created'] ?? 0 );

		if ( Scalar::int( $stored['user_id'] ?? 0 ) !== $user
			|| Scalar::int( $stored['blog_id'] ?? 0 ) !== get_current_blog_id()
			|| ! in_array( $kind, self::KINDS, true )
			|| $created <= 0
			|| time() - $created > self::TTL
			|| ! is_array( $stored['args'] ?? null )
			|| ! is_array( $stored['estimate'] ?? null )
		) {
			return null;
		}

		return [
			'user_id'  => $user,
			'blog_id'  => get_current_blog_id(),
			'kind'     => $kind,
			'args'     => self::keyed( $stored['args'] ),
			'estimate' => self::keyed( $stored['estimate'] ),
			'return'   => Scalar::str( $stored['return'] ?? '' ),
			'retry'    => Scalar::str( $stored['retry'] ?? '' ),
			'created'  => $created,
		];
	}

	/**
	 * Delete a run request.
	 *
	 * @param string $token The token.
	 * @return void
	 */
	public static function forget( string $token ): void {
		$token = self::valid_token( $token );

		if ( '' !== $token ) {
			delete_transient( self::REQUEST_PREFIX . $token );
		}
	}

	/**
	 * The name of a token's start lock (a second "Start" gets "already started").
	 *
	 * @param string $token The token.
	 * @return string
	 */
	public static function lock_name( string $token ): string {
		return 'resync_start_' . self::valid_token( $token );
	}

	/**
	 * Keep a run's result for the current user's next screen.
	 *
	 * @param array<string, mixed> $result The result summary.
	 * @return void
	 */
	public static function put_result( array $result ): void {
		$user = get_current_user_id();

		if ( $user > 0 ) {
			set_transient( self::RESULT_PREFIX . $user, $result, self::RESULT_TTL );
		}
	}

	/**
	 * Read the current user's last result once (it is deleted).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function take_result(): ?array {
		$user = get_current_user_id();

		if ( $user <= 0 ) {
			return null;
		}

		$key    = self::RESULT_PREFIX . $user;
		$stored = get_transient( $key );

		if ( false !== $stored ) {
			delete_transient( $key );
		}

		return is_array( $stored ) ? self::keyed( $stored ) : null;
	}

	/**
	 * The string-keyed entries of a stored array ([] for anything else).
	 *
	 * @param mixed $value The stored value.
	 * @return array<string, mixed>
	 */
	public static function keyed( mixed $value ): array {
		$out = [];

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) ) {
					$out[ $key ] = $item;
				}
			}
		}

		return $out;
	}
}
