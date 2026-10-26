<?php
/**
 * Where the HTML comments of a content really are, by the HTML5 tree builder.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the real HTML comments of a content, read by WordPress's HTML5 tree builder.
 *
 * Load only once WP_HTML_Processor exists. Below WordPress 6.7 nothing is listed,
 * so every save gets core's behaviour; comments after the point where the
 * processor stops are not listed, so a block closing there is not kept.
 */
final class BlockCommentLocator extends \WP_HTML_Processor {

	/**
	 * Lowest WordPress version the locator works on.
	 */
	public const MIN_WP = '6.7';

	/**
	 * Most open elements above any token of the content; deeper content lists
	 * none. Before WordPress 7.0 the processor stops at about 98 open elements.
	 */
	public const MAX_DEPTH = 128;

	/**
	 * Most bytes of the open element names above a listed comment.
	 */
	public const MAX_CONTEXT_BYTES = 4096;

	/**
	 * Fragment of the offset self-test: its real comment starts at byte 27
	 * (the one in the attribute value is text).
	 */
	private const SELF_TEST = '<p title="<!-- x -->">a</p><!-- b -->';

	/**
	 * Whether offset() reads this WordPress version's token offsets; null
	 * until the first comment that needs one in the request.
	 *
	 * @var bool|null
	 */
	private static ?bool $offsets_read = null;

	/**
	 * Real HTML comments at the offsets asked for, as [ offset => context id, contexts ].
	 *
	 * A context is [ parent id, element name ], id 0 is no element, and equal ids mean
	 * equal open elements. Null below self::MIN_WP, when the processor cannot start,
	 * or past self::MAX_DEPTH or self::MAX_CONTEXT_BYTES.
	 *
	 * @param string            $html   Content.
	 * @param array<int, mixed> $wanted Offsets to list, as keys; null lists every comment.
	 * @return array{0: array<int, int>, 1: array<int, array{0: int, 1: string}>}|null
	 */
	public static function comments( string $html, ?array $wanted = null ): ?array {
		$version = $GLOBALS['wp_version'] ?? null;

		if ( ! is_string( $version ) || version_compare( $version, self::MIN_WP, '<' ) ) {
			return null;
		}

		try {
			$processor = self::create_fragment( $html );

			if ( ! $processor instanceof self ) {
				return null;
			}

			$out      = [];
			$contexts = [ [ 0, '' ] ];
			$ids      = [];
			$bytes    = [ 0 ];
			$previous = null;
			$path     = [];
			$id       = 0;

			while ( $processor->next_token() ) {
				// The depth counts the token itself.
				if ( $processor->get_current_depth() > self::MAX_DEPTH + 1 ) {
					return null;
				}

				if ( '#comment' !== $processor->get_token_type() || self::COMMENT_AS_HTML_COMMENT !== $processor->get_comment_type() ) {
					continue;
				}

				$at = self::offsets_read() ? $processor->offset() : null;

				if ( null === $at ) {
					return null;
				}

				if ( null !== $wanted && ! isset( $wanted[ $at ] ) ) {
					continue;
				}

				$crumbs = $processor->get_breadcrumbs();

				// Same open elements as the last comment listed: same context.
				if ( $crumbs !== $previous ) {
					if ( array_values( $crumbs ) !== $crumbs ) { // Not array_is_list(): Plugin Check flags it as WordPress 6.5.
						return null;
					}

					$count = '#comment' === end( $crumbs ) ? count( $crumbs ) - 1 : count( $crumbs );
					$same  = 0;
					$max   = null === $previous ? 0 : min( $count, count( $path ) );

					while ( $same < $max && $crumbs[ $same ] === $previous[ $same ] ) {
						++$same;
					}

					$path = array_slice( $path, 0, $same );
					$id   = $same > 0 ? $path[ $same - 1 ] : 0;

					for ( $i = $same; $i < $count; $i++ ) {
						$name = (string) $crumbs[ $i ];
						$key  = $id . "\x1F" . $name;

						if ( ! isset( $ids[ $key ] ) ) {
							$ids[ $key ] = count( $contexts );
							$contexts[]  = [ $id, $name ];
							$bytes[]     = $bytes[ $id ] + strlen( $name );
						}

						$id     = $ids[ $key ];
						$path[] = $id;
					}

					$previous = $crumbs;

					if ( $bytes[ $id ] > self::MAX_CONTEXT_BYTES ) {
						return null;
					}
				}

				$out[ $at ] = $id;
			}

			return [ $out, $contexts ];
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * A context of self::comments() as text: the open element names joined
	 * with '>' (for example 'HTML>BODY>DIV').
	 *
	 * @param array<int, array{0: int, 1: string}> $contexts Contexts from self::comments().
	 * @param int                                  $id       Context id.
	 * @return string
	 */
	public static function context( array $contexts, int $id ): string {
		$names = [];

		for ( $at = $id; $at > 0 && isset( $contexts[ $at ] ); $at = $contexts[ $at ][0] ) {
			$names[] = $contexts[ $at ][1];
		}

		return implode( '>', array_reverse( $names ) );
	}

	/**
	 * Whether offset() reads the right byte offset on this WordPress version,
	 * since it reads core's internal bookmarks; tested once per request. When
	 * it does not, nothing is listed and one log line says so.
	 *
	 * @return bool
	 */
	private static function offsets_read(): bool {
		if ( null !== self::$offsets_read ) {
			return self::$offsets_read;
		}

		self::$offsets_read = false;

		try {
			$processor = self::create_fragment( self::SELF_TEST );

			while ( $processor instanceof self && $processor->next_token() ) {
				if ( '#comment' === $processor->get_token_type() && self::COMMENT_AS_HTML_COMMENT === $processor->get_comment_type() ) {
					self::$offsets_read = 27 === $processor->offset();
					break;
				}
			}
		} catch ( \Throwable $e ) {
			self::$offsets_read = false;
		}

		if ( ! self::$offsets_read ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the protection is off on this WordPress version.
			error_log( 'PerfLocale: "Do not translate" blocks are filtered on this WordPress version because a parser check failed.' );
		}

		return self::$offsets_read;
	}

	/**
	 * Byte offset of the current token in the content, null for a token the
	 * processor added itself.
	 *
	 * @return int|null
	 */
	private function offset(): ?int {
		if ( ! $this->set_bookmark( 'pfl_comment' ) ) {
			return null;
		}

		$span = $this->bookmarks['_pfl_comment'] ?? null;

		$this->release_bookmark( 'pfl_comment' );

		return $span instanceof \WP_HTML_Span ? (int) $span->start : null;
	}
}
