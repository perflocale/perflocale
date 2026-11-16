<?php
/**
 * Fixed code for a database error.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a log line names instead of a database's own text, which can quote queries and values. Loaded on first use.
 *
 * @internal
 */
final class DbErrorCode {

	/**
	 * A fixed code for a database error, matched on the driver's own message only (MySQL's text, or the error_message
	 * block of the SQLite integration's HTML): the rest quotes queries and values, so a value cannot pick the code.
	 *
	 * @param string $error The database error.
	 * @return string
	 */
	public static function of( string $error ): string {
		if ( 1 === preg_match( '~class="error_message"[^>]*>\s*(.*?)\s*</div>~s', $error, $m ) ) {
			$error = (string) preg_replace( '~^Error occurred at line \d+ in Function .*?\. Error message was: ~s', '', $m[1] );
		}

		$error = (string) preg_replace( '~^SQLSTATE\[\w+\]: [^:]*: (?:\d+ )?~', '', ltrim( $error ) );
		$codes = [
			'duplicate_key'  => '~^(?:Duplicate entry|UNIQUE constraint failed)~',
			'too_long'       => '~^Data too long~',
			'bad_value'      => '~^(?:Incorrect \w+ value|Out of range value|Truncated incorrect)~',
			'not_null'       => '~^(?:Column \'[^\']*\' cannot be null|Field \'[^\']*\' doesn\'t have a default|NOT NULL constraint failed)~',
			'missing_table'  => '~^(?:Table \'[^\']*\' doesn\'t exist|no such table)~',
			'missing_column' => '~^(?:Unknown column|no such column|table \S+ has no column named)~',
			'deadlock'       => '~^Deadlock found~',
			'lock_timeout'   => '~^(?:Lock wait timeout|database is locked)~',
			'connection'     => '~^(?:MySQL server has gone away|Lost connection)~',
			'server_busy'    => '~^(?:Too many connections|Query execution was interrupted|Can\'t connect|database table is locked)~',
			'key_too_long'   => '~^Specified key was too long~',
			'denied'         => '~^(?:\w+ command denied|Access denied|attempt to write a readonly database)~',
			'syntax'         => '~^(?:You have an error in your SQL syntax|near "[^"]*": syntax error)~',
		];

		foreach ( $codes as $code => $pattern ) {
			if ( 1 === preg_match( $pattern, $error ) ) {
				return $code;
			}
		}

		return 'other';
	}
}
