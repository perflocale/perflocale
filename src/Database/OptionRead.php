<?php
/**
 * One option row read past every cache.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An option row as stored: found (its bytes), absent, or failed. A failed read is never absent (CODING-RULES A).
 */
final class OptionRead {

	/**
	 * A read result.
	 *
	 * @param string|null $bytes  The stored bytes; null when absent or failed.
	 * @param bool        $failed The query failed or reported an error: nothing is known.
	 */
	private function __construct( public readonly ?string $bytes, public readonly bool $failed ) {
	}

	/**
	 * Read one option row of the current blog.
	 *
	 * @param string $option Option name.
	 * @param bool   $locked A locking read (ConnectionGuard::lock_suffix()): inside a transaction it sees the latest row and holds it.
	 * @return self
	 */
	public static function of( string $option, bool $locked = false ): self {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return new self( null, true );
		}

		// One literal statement per lock (SQLite: no FOR UPDATE, see ConnectionGuard::lock_suffix()).
		if ( $locked && ConnectionGuard::applies() ) {
			$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1 FOR UPDATE', $wpdb->options, $option );
		} else {
			$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $option );
		}

		if ( ! is_string( $sql ) || '' === $sql ) {
			return new self( null, true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The row itself, past any cache; prepared above.
		$row = $wpdb->get_var( $sql );

		// An error with a result still proves nothing.
		if ( '' !== $wpdb->last_error ) {
			return new self( null, true );
		}

		return new self( is_string( $row ) ? $row : null, false );
	}

	/**
	 * Whether the row is proven absent (false when the read failed).
	 *
	 * @return bool
	 */
	public function absent(): bool {
		return ! $this->failed && null === $this->bytes;
	}
}
