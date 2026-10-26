<?php
/**
 * String scanner - finds translatable strings in PHP files.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\StringRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans PHP files for WordPress i18n calls, with batched inserts and a file size cap.
 */
final class StringScanner {

	/**
	 * Maximum file size to scan (2 MB). Larger files are skipped.
	 */
	private const MAX_FILE_SIZE = 2 * 1024 * 1024;

	/**
	 * Regex patterns for translation function calls.
	 *
	 * Atomic groups `(?>...)` stop catastrophic backtracking on malformed files (long backslash runs, open quotes).
	 */
	private const PATTERNS = [
		// The simple i18n functions; a single-argument call has the domain 'default'.
		'simple'         => '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\s*\(\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*(?:,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*)?\)/s',

		// The context-aware i18n functions.
		'context'        => '/\b(?:_x|_ex|esc_html_x|esc_attr_x)\s*\(\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*\)/s',

		// _n() - singular + plural.
		'plural'         => '/\b_n\s*\(\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*[^,]+,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*\)/s',

		// _nx() - plural with context.
		'plural_context' => '/\b_nx\s*\(\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*[^,]+,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*\)/s',

		// _n_noop(), _nx_noop() - nooped plurals (for later translation).
		'noop'           => '/\b_n_noop\s*\(\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*,\s*(?:\'((?>\\\\.|[^\'\\\\])*)\'|"((?>\\\\.|[^"\\\\])*)")\s*\)/s',
	];

	/**
	 * @var StringRepository
	 */
	private readonly StringRepository $repo;

	/**
	 * Precompiled exclusion regex (built once from excluded paths).
	 *
	 * @var string|null
	 */
	private ?string $exclusion_regex = null;

	/**
	 * Whether the most recent scan() read everything it walked.
	 *
	 * @var bool
	 */
	private bool $last_scan_complete = true;

	/**
	 * Batches of the most recent scan() whose stored strings could not be re-marked as seen.
	 *
	 * @var int
	 */
	private int $last_scan_unmarked_batches = 0;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->repo = new StringRepository( $cache );
	}

	/**
	 * Scan a directory for translatable strings.
	 *
	 * @param string $directory Directory to scan.
	 * @param string $domain Optional domain filter (only scan this domain).
	 * @param int    $batch_size Flush to DB every N strings.
	 * @return array{found: int, inserted: int}
	 */
	public function scan( string $directory, string $domain = '', int $batch_size = 500 ): array {
		$this->last_scan_complete         = true;
		$this->last_scan_unmarked_batches = 0;

		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			$this->last_scan_complete = false;

			return [
				'found'    => 0,
				'inserted' => 0,
			];
		}

		$exclusion_regex = $this->get_exclusion_regex();
		$found           = 0;
		$inserted        = 0;
		$batch           = [];

		// Exclusions match the path below the scan root, never the realpath: a site living under
		// `/var/www/test/` would otherwise have every file excluded.
		$scan_root = realpath( $directory );
		$scan_root = false === $scan_root ? '' : rtrim( $scan_root, '/' );

		/**
		 * Maximum size of a single PHP file the scanner reads; bigger files are skipped. At least 64 KB.
		 *
		 * @hook perflocale/strings/scanner/max_file_bytes
		 * @param int $bytes Default 2097152 (2 MB).
		 */
		$max_file_size = (int) apply_filters( 'perflocale/strings/scanner/max_file_bytes', self::MAX_FILE_SIZE );
		$max_file_size = max( 64 * 1024, $max_file_size );

		/**
		 * Strings buffered before each bulk insert. Clamped to 50-5000.
		 *
		 * Receives, and overrides, the batch size the caller passed to scan().
		 *
		 * @hook perflocale/strings/scanner/batch_size
		 * @param int $size Default whatever the caller passed (500).
		 */
		$batch_size = (int) apply_filters( 'perflocale/strings/scanner/batch_size', $batch_size );
		$batch_size = max( 50, min( 5000, $batch_size ) );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					$directory,
					\RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				// Only scan PHP files (case-insensitive).
				if ( strtolower( $file->getExtension() ) !== 'php' ) {
					continue;
				}

				$filepath = $file->getRealPath();

				if ( $filepath === false ) {
					// A dangling symlink is skipped; any other unresolved path is in a directory PHP can list but not
					// enter, so the scan is incomplete unless the path below the directory is excluded.
					$pathname = (string) $file->getPathname();

					if ( ! is_link( $pathname ) ) {
						$base       = rtrim( $directory, '/\\' );
						$unresolved = str_starts_with( $pathname, $base ) ? substr( $pathname, strlen( $base ) ) : $pathname;

						if ( '' === $exclusion_regex || ! preg_match( $exclusion_regex, '/' . ltrim( $unresolved, '/' ) ) ) {
							$this->last_scan_complete = false;
						}
					}

					continue;
				}

				// Skip excluded paths, measured from the scan root.
				if ( '' !== $exclusion_regex ) {
					$relative = $filepath;

					if ( '' !== $scan_root && str_starts_with( $filepath, $scan_root ) ) {
						$relative = substr( $filepath, strlen( $scan_root ) );
					}

					// Leading slash, so the segment regex also matches the first directory below the root.
					if ( preg_match( $exclusion_regex, '/' . ltrim( $relative, '/' ) ) ) {
						continue;
					}
				}

				// Skip files over size limit to prevent memory exhaustion.
				$filesize = $file->getSize();

				if ( $filesize === 0 || $filesize > $max_file_size ) {
					continue;
				}

				$strings = $this->scan_file( $filepath, $domain );
				$found  += count( $strings );

				foreach ( $strings as $string ) {
					$batch[] = $string;

					if ( count( $batch ) >= $batch_size ) {
						$inserted += $this->repo->bulk_insert( $batch, $marked );
						$batch     = [];

						if ( ! $marked ) {
							++$this->last_scan_unmarked_batches;
						}
					}
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			// A directory that cannot be opened (or a symlink loop) ends the walk of the whole target.
			$this->last_scan_complete = false;

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PerfLocale scanner: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( ! empty( $batch ) ) {
			$inserted += $this->repo->bulk_insert( $batch, $marked );

			if ( ! $marked ) {
				++$this->last_scan_unmarked_batches;
			}
		}

		return [
			'found'    => $found,
			'inserted' => $inserted,
		];
	}

	/**
	 * Whether the most recent scan() read every directory and PHP file it walked.
	 *
	 * If not, unread strings were not re-marked as seen. Size-capped, excluded and dangling files count as read.
	 *
	 * @return bool
	 */
	public function last_scan_complete(): bool {
		return $this->last_scan_complete;
	}

	/**
	 * Batches of the most recent scan() whose stored strings kept their last_seen_at (a query failed).
	 *
	 * @return int
	 */
	public function last_scan_unmarked_batches(): int {
		return $this->last_scan_unmarked_batches;
	}

	/**
	 * Scan a single file for translatable strings.
	 *
	 * @param string $filepath Absolute file path.
	 * @param string $domain Optional domain filter.
	 * @return array<int, array{domain: string, context: string, original: string, file_path: string, line_number: int}>
	 */
	public function scan_file( string $filepath, string $domain = '' ): array {
		if ( ! is_readable( $filepath ) ) {
			$this->last_scan_complete = false;

			return [];
		}

		$content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( $content === false ) {
			$this->last_scan_complete = false;

			return [];
		}

		if ( $content === '' ) {
			return [];
		}

		$relative_path = str_replace( ABSPATH, '', $filepath );
		$strings       = [];
		$line_offsets  = $this->build_line_offsets( $content );

		// Simple i18n functions - __, _e, esc_html__, esc_attr__, esc_html_e, esc_attr_e.
		if ( preg_match_all( self::PATTERNS['simple'], $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$text         = $this->decode_captured( $match, 1, 2 );
				$match_domain = $this->decode_captured( $match, 3, 4 );
				$match_domain = $match_domain !== '' ? $match_domain : 'default';

				if ( $text === '' ) {
					continue;
				}

				if ( $domain !== '' && $match_domain !== $domain ) {
					continue;
				}

				$strings[] = [
					'domain'      => $match_domain,
					'context'     => '',
					'original'    => $text,
					'file_path'   => $relative_path,
					'line_number' => $this->offset_to_line( $line_offsets, $match[0][1] ),
				];
			}
		}

		// Context-aware i18n functions - _x, _ex, esc_html_x, esc_attr_x.
		if ( preg_match_all( self::PATTERNS['context'], $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$text         = $this->decode_captured( $match, 1, 2 );
				$context      = $this->decode_captured( $match, 3, 4 );
				$match_domain = $this->decode_captured( $match, 5, 6 );

				if ( $text === '' ) {
					continue;
				}

				if ( $domain !== '' && $match_domain !== $domain ) {
					continue;
				}

				$strings[] = [
					'domain'      => $match_domain,
					'context'     => $context,
					'original'    => $text,
					'file_path'   => $relative_path,
					'line_number' => $this->offset_to_line( $line_offsets, $match[0][1] ),
				];
			}
		}

		// Plural: _n() - two entries per match (singular + plural).
		if ( preg_match_all( self::PATTERNS['plural'], $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$singular     = $this->decode_captured( $match, 1, 2 );
				$plural       = $this->decode_captured( $match, 3, 4 );
				$match_domain = $this->decode_captured( $match, 5, 6 );

				if ( $domain !== '' && $match_domain !== $domain ) {
					continue;
				}

				$line = $this->offset_to_line( $line_offsets, $match[0][1] );

				if ( $singular !== '' ) {
					$strings[] = [
						'domain'      => $match_domain,
						'context'     => 'singular',
						'original'    => $singular,
						'file_path'   => $relative_path,
						'line_number' => $line,
					];
				}

				if ( $plural !== '' ) {
					$strings[] = [
						'domain'      => $match_domain,
						'context'     => 'plural',
						'original'    => $plural,
						'file_path'   => $relative_path,
						'line_number' => $line,
					];
				}
			}
		}

		// Plural with context: _nx().
		if ( preg_match_all( self::PATTERNS['plural_context'], $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$singular     = $this->decode_captured( $match, 1, 2 );
				$plural       = $this->decode_captured( $match, 3, 4 );
				$context      = $this->decode_captured( $match, 5, 6 );
				$match_domain = $this->decode_captured( $match, 7, 8 );

				if ( $domain !== '' && $match_domain !== $domain ) {
					continue;
				}

				$line = $this->offset_to_line( $line_offsets, $match[0][1] );

				if ( $singular !== '' ) {
					$strings[] = [
						'domain'      => $match_domain,
						'context'     => $context,
						'original'    => $singular,
						'file_path'   => $relative_path,
						'line_number' => $line,
					];
				}

				if ( $plural !== '' ) {
					$strings[] = [
						'domain'      => $match_domain,
						'context'     => $context . ' (plural)',
						'original'    => $plural,
						'file_path'   => $relative_path,
						'line_number' => $line,
					];
				}
			}
		}

		// Nooped plurals: _n_noop().
		if ( preg_match_all( self::PATTERNS['noop'], $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$singular     = $this->decode_captured( $match, 1, 2 );
				$plural       = $this->decode_captured( $match, 3, 4 );
				$match_domain = $this->decode_captured( $match, 5, 6 );

				if ( $domain !== '' && $match_domain !== $domain ) {
					continue;
				}

				$line = $this->offset_to_line( $line_offsets, $match[0][1] );

				if ( $singular !== '' ) {
					$strings[] = [
						'domain'      => $match_domain,
						'context'     => 'singular',
						'original'    => $singular,
						'file_path'   => $relative_path,
						'line_number' => $line,
					];
				}

				if ( $plural !== '' ) {
					$strings[] = [
						'domain'      => $match_domain,
						'context'     => 'plural',
						'original'    => $plural,
						'file_path'   => $relative_path,
						'line_number' => $line,
					];
				}
			}
		}

		// Free content memory before returning.
		unset( $content, $line_offsets );

		return $strings;
	}

	/**
	 * Decode a captured i18n literal to its runtime value, by the quote style that matched.
	 *
	 * Not stripslashes(): it mis-decodes both styles, and the original must hash as gettext's runtime input.
	 *
	 * @param array<int, array{0: string, 1: int}> $match  One PREG_SET_ORDER match.
	 * @param int                                   $single Capture index of the single-quoted body.
	 * @param int                                   $double Capture index of the double-quoted body.
	 * @return string Decoded runtime string.
	 */
	private function decode_captured( array $match, int $single, int $double ): string {
		if ( isset( $match[ $single ] ) && $match[ $single ][1] !== -1 ) {
			return $this->decode_single_quoted( (string) $match[ $single ][0] );
		}

		if ( isset( $match[ $double ] ) && $match[ $double ][1] !== -1 ) {
			return $this->decode_double_quoted( (string) $match[ $double ][0] );
		}

		return '';
	}

	/**
	 * Decode a single-quoted PHP string body: only \\ and \' are escapes.
	 *
	 * @param string $body Raw bytes between the quotes.
	 * @return string
	 */
	private function decode_single_quoted( string $body ): string {
		return str_replace( [ '\\\\', "\\'" ], [ '\\', "'" ], $body );
	}

	/**
	 * Decode a double-quoted PHP string body as PHP does, so the original equals gettext's runtime input.
	 *
	 * @param string $body Raw bytes between the quotes.
	 * @return string
	 */
	private function decode_double_quoted( string $body ): string {
		return (string) preg_replace_callback(
			'/\\\\(\\\\|"|\$|n|r|t|v|e|f|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
			static function ( array $m ): string {
				switch ( $m[1] ) {
					case '\\':
						return '\\';
					case '"':
						return '"';
					case '$':
						return '$';
					case 'n':
						return "\n";
					case 'r':
						return "\r";
					case 't':
						return "\t";
					case 'v':
						return "\v";
					case 'e':
						return "\033";
					case 'f':
						return "\f";
				}

				if ( $m[1][0] === 'x' ) {
					return chr( hexdec( substr( $m[1], 1 ) ) );
				}

				if ( $m[1][0] === 'u' ) {
					return self::codepoint_to_utf8( (int) hexdec( substr( $m[1], 2, -1 ) ) );
				}

				return chr( octdec( $m[1] ) ); // 1-3 octal digits.
			},
			$body
		);
	}

	/**
	 * Encode a Unicode codepoint as UTF-8 without mbstring.
	 *
	 * Not mb_chr(): WordPress polyfills it only from 7.1 and it is missing without mbstring.
	 * Invalid codepoints (out of range, surrogates) give U+FFFD, as mb_chr() does.
	 *
	 * @param int $codepoint Unicode codepoint.
	 * @return string UTF-8 encoded character.
	 */
	private static function codepoint_to_utf8( int $codepoint ): string {
		if ( $codepoint < 0 || $codepoint > 0x10FFFF || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ) {
			return "\xEF\xBF\xBD"; // U+FFFD REPLACEMENT CHARACTER.
		}

		if ( $codepoint < 0x80 ) {
			return chr( $codepoint );
		}

		if ( $codepoint < 0x800 ) {
			return chr( 0xC0 | ( $codepoint >> 6 ) )
				. chr( 0x80 | ( $codepoint & 0x3F ) );
		}

		if ( $codepoint < 0x10000 ) {
			return chr( 0xE0 | ( $codepoint >> 12 ) )
				. chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) )
				. chr( 0x80 | ( $codepoint & 0x3F ) );
		}

		return chr( 0xF0 | ( $codepoint >> 18 ) )
			. chr( 0x80 | ( ( $codepoint >> 12 ) & 0x3F ) )
			. chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $codepoint & 0x3F ) );
	}

	/**
	 * Build line offset index for O(log n) line number lookups.
	 *
	 * @param string $content File content.
	 * @return array<int, int>
	 */
	private function build_line_offsets( string $content ): array {
		$offsets = [ 0 ];
		$offset  = 0;

		while ( ( $offset = strpos( $content, "\n", $offset ) ) !== false ) {
			++$offset;
			$offsets[] = $offset;
		}

		return $offsets;
	}

	/**
	 * Convert byte offset to line number via binary search.
	 *
	 * @param array<int, int> $offsets Line offset index.
	 * @param int             $offset Byte offset.
	 * @return int 1-based line number.
	 */
	private function offset_to_line( array $offsets, int $offset ): int {
		$low  = 0;
		$high = count( $offsets ) - 1;

		while ( $low <= $high ) {
			$mid = intdiv( $low + $high, 2 );

			if ( $offsets[ $mid ] <= $offset ) {
				$low = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}

		return $low;
	}

	/**
	 * PHP files below the directory up to $limit, for a dispatch estimate; excluded directories are not entered.
	 *
	 * An unreadable directory answers $limit (size unknown).
	 *
	 * @param string $directory Directory.
	 * @param int    $limit     Stop counting at this number.
	 * @return int
	 */
	public function count_php_files( string $directory, int $limit ): int {
		if ( $limit <= 0 || ! is_dir( $directory ) ) {
			return 0;
		}

		$regex = $this->get_exclusion_regex();
		$base  = strlen( rtrim( $directory, '/\\' ) );
		$count = 0;

		try {
			$tree = new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS ),
				static fn( \SplFileInfo $file, string $path, \RecursiveDirectoryIterator $it ): bool => '' === $regex || ! $it->hasChildren() || ! preg_match( $regex, '/' . trim( substr( $path, $base ), '/\\' ) . '/' )
			);

			foreach ( new \RecursiveIteratorIterator( $tree ) as $file ) {
				if ( $file instanceof \SplFileInfo && strtolower( $file->getExtension() ) === 'php' && ++$count >= $limit ) {
					break;
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			return $limit;
		}

		return $count;
	}

	/**
	 * Build a single regex from excluded paths (compiled once, reused per scan).
	 *
	 * @return string Regex pattern or empty string if no exclusions.
	 */
	private function get_exclusion_regex(): string {
		if ( $this->exclusion_regex !== null ) {
			return $this->exclusion_regex;
		}

		$excluded = [ 'vendor/', 'node_modules/', '.git/', 'tests/', 'test/', 'cache/', 'build/', 'dist/' ];

		$defaults = $excluded;
		/** @hook perflocale/strings/scanner/excluded_paths Filter excluded directory patterns. */
		$excluded = apply_filters( 'perflocale/strings/scanner/excluded_paths', $excluded );

		if ( ! is_array( $excluded ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/strings/scanner/excluded_paths", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/strings/scanner/excluded_paths returned %s - must be an array of path-prefix strings. Falling back to the default exclusion list.', 'perflocale' ),
						get_debug_type( $excluded )
					)
				),
				'1.0.0'
			);
			$excluded = $defaults;
		}

		if ( empty( $excluded ) ) {
			$this->exclusion_regex = '';
			return '';
		}

		// One alternation, each prefix anchored to slashes on both sides: 'cache/' must not match /litespeed-cache/.
		$parts = array_map( static fn( string $path ): string => preg_quote( trim( $path, '/' ), '/' ), $excluded );
		$parts = array_filter( $parts, static fn( string $part ): bool => $part !== '' );

		if ( empty( $parts ) ) {
			$this->exclusion_regex = '';
			return '';
		}

		$this->exclusion_regex = '/\/(?:' . implode( '|', $parts ) . ')\//';

		return $this->exclusion_regex;
	}
}
