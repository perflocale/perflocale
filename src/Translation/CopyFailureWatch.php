<?php
/**
 * A translation's failed copy steps: watched while a recovery re-runs them, and named for a notice or report.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to perflocale/translation/meta_copy_failed for one post while the copy steps run; loaded on first use.
 *
 * @internal
 */
final class CopyFailureWatch {

	/**
	 * The watched post's failed steps, once its copy failed.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $failed = null;

	/**
	 * Constructor.
	 *
	 * @param int $post_id The translation the copy steps write.
	 */
	private function __construct( private int $post_id ) {}

	/**
	 * Run the copy steps; the post's failed steps (`_perflocale_meta_copy_errors` entries), or null when none failed.
	 *
	 * @param int      $post_id The translation.
	 * @param callable $copy    The copy steps.
	 * @phpstan-impure
	 * @return array<int, mixed>|null
	 */
	public static function run( int $post_id, callable $copy ): ?array {
		$watch = new self( $post_id );
		$seen  = static function ( mixed $failed_id = 0, mixed $source_id = 0, mixed $slug = '', mixed $errors = [] ) use ( $watch ): void {
			if ( is_numeric( $failed_id ) && (int) $failed_id === $watch->post_id ) {
				$watch->failed = is_array( $errors ) ? array_values( $errors ) : [];
			}
		};

		add_action( 'perflocale/translation/meta_copy_failed', $seen, 10, 4 );

		try {
			$copy();
		} finally {
			remove_action( 'perflocale/translation/meta_copy_failed', $seen, 10 );
		}

		return $watch->failed;
	}

	/**
	 * The items failed copy steps left out, for a notice or report: "custom fields (_a, b), featured image".
	 *
	 * @param mixed $errors `_perflocale_meta_copy_errors` entries.
	 * @return string
	 */
	public static function items( mixed $errors ): string {
		$labels = [];

		foreach ( is_array( $errors ) ? $errors : [] as $err ) {
			$step = is_array( $err ) && is_string( $err['step'] ?? null ) ? $err['step'] : '';
			$keys = is_array( $err ) && 'post_meta' === $step && is_array( $err['rows'] ?? null ) ? array_keys( $err['rows'] ) : [];

			$labels[ $step ] = match ( $step ) {
				/* translators: %s: comma-separated meta keys. */
				'post_meta'         => [] !== $keys ? sprintf( __( 'custom fields (%s)', 'perflocale' ), implode( ', ', $keys ) ) : __( 'post meta + custom fields', 'perflocale' ),
				'featured_image'    => __( 'featured image', 'perflocale' ),
				'taxonomy_terms'    => __( 'categories and tags', 'perflocale' ),
				'unresolved_fields' => __( 'custom fields whose definition was not loaded', 'perflocale' ),
				default             => $step,
			};
		}

		$items = implode( ', ', array_filter( $labels, static fn( string $label ): bool => '' !== $label ) );

		return '' !== $items ? $items : __( 'custom fields, image or terms', 'perflocale' );
	}

	/**
	 * Whether the entries name custom fields the database refused (a stopped insert), which fail the same way again.
	 *
	 * @param mixed $errors `_perflocale_meta_copy_errors` entries.
	 * @return bool
	 */
	public static function refused( mixed $errors ): bool {
		foreach ( is_array( $errors ) ? $errors : [] as $err ) {
			if ( is_array( $err ) && 'post_meta' === ( $err['step'] ?? '' ) && is_array( $err['rows'] ?? null ) && [] !== $err['rows'] ) {
				return true;
			}
		}

		return false;
	}
}
