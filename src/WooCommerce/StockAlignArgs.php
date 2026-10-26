<?php
/**
 * WooCommerce stock alignment: the arguments of the PHP API, checked.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the arguments of stock_drift() and align_stock() (and their perflocale_* wrappers).
 * Unknown keys are refused: a typo such as `product_id` would otherwise align every product.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockAlignArgs {

	/**
	 * Most product ids one call may name.
	 */
	public const MAX_PRODUCT_IDS = 500;

	/**
	 * Keys stock_drift() takes.
	 */
	public const DRIFT_KEYS = [ 'product_ids', 'limit' ];

	/**
	 * Keys align_stock() takes.
	 */
	public const ALIGN_KEYS = [ 'from', 'product_ids', 'dry_run' ];

	/**
	 * stock_drift() arguments.
	 *
	 * @param array<mixed> $args Arguments.
	 * @return array{product_ids: list<int>, limit: int}|\WP_Error
	 */
	public static function drift( array $args ): array|\WP_Error {
		$unknown = self::unknown( $args, self::DRIFT_KEYS );

		if ( null !== $unknown ) {
			return $unknown;
		}

		$limit = array_key_exists( 'limit', $args ) ? $args['limit'] : 0;

		if ( ! is_int( $limit ) || $limit < 0 ) {
			return self::error( 'perflocale_wc_bad_args', __( 'limit must be 0 or a whole number above 0.', 'perflocale' ) );
		}

		$ids = self::product_ids( array_key_exists( 'product_ids', $args ) ? $args['product_ids'] : [] );

		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}

		return [
			'product_ids' => $ids,
			'limit'       => $limit,
		];
	}

	/**
	 * align_stock() arguments. A string `from` is always a language slug, never a function name.
	 *
	 * @param array<mixed> $args   Arguments.
	 * @param list<string> $active Slugs of the active languages.
	 * @return array{from: string|callable, product_ids: list<int>, dry_run: bool}|\WP_Error
	 */
	public static function align( array $args, array $active ): array|\WP_Error {
		$unknown = self::unknown( $args, self::ALIGN_KEYS );

		if ( null !== $unknown ) {
			return $unknown;
		}

		$dry_run = array_key_exists( 'dry_run', $args ) ? $args['dry_run'] : true;

		if ( ! is_bool( $dry_run ) ) {
			return self::error(
				'perflocale_wc_bad_args',
				sprintf(
					/* translators: %s: argument name. */
					__( '%s must be true or false.', 'perflocale' ),
					'dry_run'
				)
			);
		}

		$from = self::from( array_key_exists( 'from', $args ) ? $args['from'] : 'default', $active );

		if ( $from instanceof \WP_Error ) {
			return $from;
		}

		$ids = self::product_ids( array_key_exists( 'product_ids', $args ) ? $args['product_ids'] : [] );

		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}

		return [
			'from'        => $from,
			'product_ids' => $ids,
			'dry_run'     => $dry_run,
		];
	}

	/**
	 * The reference: 'default', an active language slug, or a non-string callable (PHP code only).
	 *
	 * @param mixed        $from   Value given.
	 * @param list<string> $active Slugs of the active languages.
	 * @return string|callable|\WP_Error
	 */
	public static function from( mixed $from, array $active ): string|callable|\WP_Error {
		if ( is_string( $from ) ) {
			if ( 'default' === $from || in_array( $from, $active, true ) ) {
				return $from;
			}
		} elseif ( is_callable( $from ) ) {
			return $from;
		}

		return self::error(
			'perflocale_wc_bad_from',
			sprintf(
				/* translators: %s: the value given for "from". */
				__( '"%s" is not an active language. Use "default" or the slug of an active language.', 'perflocale' ),
				is_scalar( $from ) ? (string) $from : gettype( $from )
			)
		);
	}

	/**
	 * Product ids: empty (every product), or 1 to MAX_PRODUCT_IDS ids above 0 (ints or digit strings).
	 *
	 * @param mixed $value Value given.
	 * @return list<int>|\WP_Error
	 */
	public static function product_ids( mixed $value ): array|\WP_Error {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_PRODUCT_IDS ) {
			return self::bad_ids();
		}

		$ids = [];

		foreach ( $value as $id ) {
			if ( is_string( $id ) && '' !== $id && \PerfLocale\Helper::is_ascii_digits( $id ) ) {
				$id = (int) $id;
			}

			if ( ! is_int( $id ) || $id < 1 ) {
				return self::bad_ids();
			}

			$ids[ $id ] = $id;
		}

		return array_values( $ids );
	}

	/**
	 * The product_ids error, naming a given variation id (also in its data, `variation`; 0 when none).
	 *
	 * @param int $variation A variation id that was given (0: none).
	 * @return \WP_Error
	 */
	public static function bad_ids( int $variation = 0 ): \WP_Error {
		$message = __( 'product_ids must list 1 to 500 product ids (whole numbers above 0).', 'perflocale' );

		if ( $variation > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: a variation id. */
				__( '#%d is a variation: pass its product.', 'perflocale' ),
				$variation
			);
		}

		return new \WP_Error(
			'perflocale_wc_bad_product_ids',
			$message,
			[
				'status'    => 400,
				'variation' => $variation,
			]
		);
	}

	/**
	 * The error for the first key that is not allowed, or null.
	 *
	 * @param array<mixed> $args    Arguments.
	 * @param list<string> $allowed Allowed keys.
	 * @return \WP_Error|null
	 */
	private static function unknown( array $args, array $allowed ): ?\WP_Error {
		foreach ( array_keys( $args ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return self::error(
					'perflocale_wc_bad_args',
					sprintf(
						/* translators: 1: the unknown argument, 2: the allowed arguments, comma-separated. */
						__( 'Unknown argument "%1$s". Allowed: %2$s.', 'perflocale' ),
						(string) $key,
						implode( ', ', $allowed )
					)
				);
			}
		}

		return null;
	}

	/**
	 * An argument error (status 400 for the ajax layer).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 400 ] );
	}
}
