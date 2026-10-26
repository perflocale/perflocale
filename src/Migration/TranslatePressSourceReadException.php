<?php
/**
 * Thrown when a TranslatePress table could not be read during an import.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A read of TranslatePress data failed: the import stops rather than read missing rows as "no translation".
 *
 * The message has no table names or values; the DB error in $db_error is for the debug log only.
 */
final class TranslatePressSourceReadException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $where    What was being read.
	 * @param string $db_error The database error.
	 */
	public function __construct( string $where, public readonly string $db_error ) {
		parent::__construct( $where );
	}
}
