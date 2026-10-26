<?php
/**
 * The containers a menu or pattern event reaches beyond those its own request rewrote.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Translation\TemplateBlockRefs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a menu or pattern event (translation created, linked, trashed, deleted, restored or moved) to the translated
 * templates and parts its request did not reach. Args: `task` (the event) and `after` (the resume point). Each page
 * first checks the event still stands; a read or write failure fails the job for a retry from the last container.
 */
final class TemplateRefsFanoutJob extends AbstractJob {

	/**
	 * Containers read per page.
	 */
	private const PAGE = 50;

	/**
	 * Seconds of work per slice.
	 */
	private const SLICE_SECONDS = 10;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'template_refs_fanout';
	}

	/**
	 * {@inheritDoc}
	 *
	 * `edit_theme_options`: who edits templates and template parts.
	 */
	public function get_required_capability(): string {
		return 'edit_theme_options';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return 0;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Result: `read`, `updated` and `references` counts, and `after` (the last container done, the resume point).
	 *
	 * @param array<string, mixed> $args     Worker args (`__perflocale_slice` from the worker).
	 * @param callable             $progress Progress reporter.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the containers could not be read or a container could not be written.
	 */
	public function execute( array $args, callable $progress ): array {
		$slice  = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : [];
		$resume = is_array( $slice['resume'] ?? null ) ? $slice['resume'] : [];
		$count  = static fn( $value ): int => is_numeric( $value ) ? max( 0, (int) $value ) : 0;
		$result = [
			'read'       => $count( $resume['read'] ?? 0 ),
			'updated'    => $count( $resume['updated'] ?? 0 ),
			'references' => $count( $resume['references'] ?? 0 ),
			'after'      => $count( $resume['after'] ?? ( $args['after'] ?? 0 ) ),
		];
		$task   = TemplateBlockRefs::fan_out_task( $args['task'] ?? null );
		$sliced = [] !== $slice;
		$until  = microtime( true ) + self::SLICE_SECONDS;

		if ( null === $task ) {
			return $result;
		}

		do {
			// A sliced run checks its time budget after each container.
			$page = TemplateBlockRefs::fan_out_job_page( $task, $result['after'], self::PAGE, $sliced ? $until : null );

			if ( $page['error'] ) {
				throw new \RuntimeException( esc_html( self::failure_message( $page['failed'] > 0, $page['cause'] ) ) );
			}

			$result['read']       += $page['read'];
			$result['updated']    += $page['updated'];
			$result['references'] += $page['references'];
			$result['after']       = $page['next'];

			$progress( $result['read'], 0 === $page['next'] ? max( 1, $result['read'] ) : $result['read'] + self::PAGE );

			if ( 0 === $page['next'] ) {
				return $result;
			}
		} while ( ! $sliced || microtime( true ) < $until );

		$result['__perflocale_continue'] = true;

		return $result;
	}

	/**
	 * The Jobs-row message of a failed page, one per cause. The runner retries every cause (a missing default language
	 * too: the job goes on once one is set).
	 *
	 * @param bool   $write Whether a container's write failed (otherwise the page could not be read).
	 * @param string $cause A write: 'conflict' (every compare-and-set attempt lost to a concurrent edit) or
	 *                      'database'. A read: 'language' (no default language) or 'database'.
	 * @return string
	 */
	public static function failure_message( bool $write, string $cause ): string {
		if ( $write ) {
			return 'conflict' === $cause
				? __( 'A template or template part was being edited at the same time, so its menu or pattern reference was not updated yet. It is tried again later.', 'perflocale' )
				: __( 'A template or template part could not be saved because the database did not accept the change. It is tried again later.', 'perflocale' );
		}

		return 'language' === $cause
			? __( 'The templates and template parts could not be updated because the site has no default language. Set one under PerfLocale → Languages. It is tried again later.', 'perflocale' )
			: __( 'The templates and template parts to update could not be read because the database returned an error. It is tried again later.', 'perflocale' );
	}
}
