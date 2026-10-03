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
 * A read of TranslatePress data failed.
 *
 * A failed SELECT returns no rows, exactly as an empty result does; only
 * `$wpdb->last_error` tells them apart. The import stops at this exception
 * ({@see TranslatePressImporter::import()}) instead of reading the missing
 * rows as "no translation", and the run is reported as not finished.
 *
 * The message names what was being read, without table names or values;
 * the database's own error is in {@see self::$db_error}, for the debug log
 * only.
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
