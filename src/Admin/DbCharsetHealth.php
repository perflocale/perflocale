<?php
/**
 * Site Health test of the database connection charset.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\MachineTranslation\ExpectedWrite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommends utf8mb4 when the connection uses a charset machine translation writes unbound on. Loaded only when the
 * Status screen or the weekly check runs it.
 *
 * @internal
 */
final class DbCharsetHealth {

	/**
	 * The test result.
	 *
	 * @return array<string, mixed>
	 */
	public static function test(): array {
		$charset = ExpectedWrite::unbound_charset();
		$unread  = null === $charset && ! ExpectedWrite::charset_known();
		$unsafe  = null !== $charset;

		if ( $unread ) {
			$label = __( 'Your database connection charset could not be checked', 'perflocale' );
			$text  = __( 'The database server did not report the connection charset, so machine translation may not save updates to translated posts and custom fields. Set DB_CHARSET to utf8mb4 in wp-config.php.', 'perflocale' );
		} elseif ( $unsafe ) {
			$label = __( 'Your database connection does not use utf8mb4', 'perflocale' );
			/* translators: %s: database connection charset, e.g. gbk. */
			$text = sprintf( __( 'Your database connection uses %s. Multilingual content needs utf8mb4: switch DB_CHARSET to utf8mb4 to keep every character and to protect edits during machine translation.', 'perflocale' ), $charset );
		} else {
			$label = __( 'Your database connection charset is supported', 'perflocale' );
			$text  = __( 'Machine translation protects edits that people make while it runs.', 'perflocale' );
		}

		return [
			'label'       => $label,
			'status'      => $unread || $unsafe ? 'recommended' : 'good',
			'badge'       => [
				'label' => __( 'Translation', 'perflocale' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'actions'     => '',
			'test'        => 'perflocale_db_charset',
		];
	}
}
