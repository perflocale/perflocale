<?php
/**
 * Streaming reader for PerfLocale export files.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Parse diagnostics in plain text: DataImporter answers with its own messages by the exception code, and only WP-CLI prints this text, in a terminal.

/**
 * Reads a PerfLocale export file in pieces, so an import never holds the whole file or decoded envelope in memory.
 *
 * The index checks the whole JSON structure before any write; rows() decodes one row at a time; errors carry ERR_* codes.
 * See docs-ai/11-code-rationale.md, "ImportReader: streaming index".
 */
final class ImportReader {

	/**
	 * The file is not valid JSON.
	 */
	public const ERR_SYNTAX = 1;

	/**
	 * One value is larger than the caller's limit.
	 */
	public const ERR_TOO_LARGE = 2;

	/**
	 * The file could not be read.
	 */
	public const ERR_READ = 3;

	/**
	 * A copy (copy_export()) could not be written.
	 */
	public const ERR_WRITE = 4;

	/**
	 * The index would keep more bytes in all than limit() allows.
	 */
	public const ERR_TOO_MUCH_KEPT = 5;

	/**
	 * The file has more top-level members than limit() allows.
	 */
	public const ERR_TOO_MANY_MEMBERS = 6;

	/**
	 * `data`, or a network export's `sites`, has more members than limit() allows.
	 */
	public const ERR_TOO_MANY_DATA_MEMBERS = 7;

	/**
	 * A top-level member is larger than its key's cap (a settings key); the exception message is the key.
	 */
	public const ERR_TOO_LARGE_SETTING = 8;

	/**
	 * Bytes read from the file per refill.
	 */
	private const CHUNK = 1048576;

	/**
	 * Longest object key accepted, in bytes.
	 */
	private const MAX_KEY_BYTES = 65536;

	/**
	 * Deepest nesting of objects and arrays: json_decode()'s default depth 512 counts the innermost scalar level too.
	 */
	private const MAX_DEPTH = 511;

	/**
	 * File path, kept so each row walk opens its own handle.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Open file handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Unread bytes of the current read buffer start at $pos.
	 *
	 * @var string
	 */
	private string $buf = '';

	/**
	 * Read position in $buf.
	 *
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * File offset of $buf[0].
	 *
	 * @var int
	 */
	private int $base = 0;

	/**
	 * Whether the file has no more bytes to read.
	 *
	 * @var bool
	 */
	private bool $eof = false;

	/**
	 * Top-level members other than `data`, decoded, in file order.
	 *
	 * @var array<array-key, mixed>
	 */
	private array $envelope = [];

	/**
	 * Whether the envelope has a `data` member at all.
	 *
	 * @var bool
	 */
	private bool $has_data = false;

	/**
	 * Whether `data` is a JSON object or array (the shape the importer reads).
	 *
	 * @var bool
	 */
	private bool $data_is_container = false;

	/**
	 * Slices of a network export (index_network()): site key => `export` offset, whether it is an object, its `site_url`.
	 *
	 * @var array<array-key, array{offset: int|null, is_object: bool, site_url: mixed}>
	 */
	private array $sites = [];

	/**
	 * Stream that walked-over values are copied to (copy_export()).
	 *
	 * @var resource|null
	 */
	private $sink = null;

	/**
	 * Most bytes an index keeps in all: decoded value text plus walked member keys.
	 *
	 * @var int
	 */
	private int $max_kept = PHP_INT_MAX;

	/**
	 * Most top-level members.
	 *
	 * @var int
	 */
	private int $max_members = PHP_INT_MAX;

	/**
	 * Most members of `data`, or slices of a network export.
	 *
	 * @var int
	 */
	private int $max_data_members = PHP_INT_MAX;

	/**
	 * Bytes the current index has kept.
	 *
	 * @var int
	 */
	private int $kept = 0;

	/**
	 * Top-level members the current index walked over without reading them, in file order.
	 *
	 * @var list<string>
	 */
	private array $skipped = [];

	/**
	 * Members of `data` in file order: a file row list has `offset` (of its `[` or `{`) and `count`,
	 * a scalar member has `value`, a member held in memory has `rows`.
	 *
	 * @var array<array-key, array{offset?: int, count?: int, value?: mixed, rows?: array<array-key, mixed>}>
	 */
	private array $tables = [];

	/**
	 * Open a file for reading.
	 *
	 * @param string $path File path.
	 * @return self|null Null when the file cannot be opened.
	 */
	public static function open( string $path ): ?self {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming read; WP_Filesystem has no streaming API. @ suppresses the warning the null return reports.
		$handle = @fopen( $path, 'rb' );

		if ( ! is_resource( $handle ) ) {
			return null;
		}

		$reader         = new self();
		$reader->path   = $path;
		$reader->handle = $handle;

		return $reader;
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen in open().
			fclose( $this->handle );
		}

		$this->handle = null;
	}

	/**
	 * Bound what index() and index_network() keep; unbounded until called.
	 *
	 * @param int $max_kept_bytes   Most bytes kept in all: decoded value text plus walked member keys.
	 * @param int $max_members      Most top-level members.
	 * @param int $max_data_members Most members of `data` (index()) or slices in `sites` (index_network()).
	 * @return void
	 */
	public function limit( int $max_kept_bytes, int $max_members, int $max_data_members ): void {
		$this->max_kept         = max( 0, $max_kept_bytes );
		$this->max_members      = max( 0, $max_members );
		$this->max_data_members = max( 0, $max_data_members );
	}

	/**
	 * Top-level members the last index walked over because the caller's $read did not name them.
	 *
	 * @return list<string>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * Read the structure once: decode the top-level members $read names, record each `data` list's offset and count.
	 *
	 * A top-level JSON array is an envelope with no members; repeated keys keep the last value, as json_decode() does.
	 *
	 * @param int                           $max_value_bytes Largest top-level member (or non-list `data` member) decoded whole.
	 * @param (callable(string): bool)|null $read            Whether a top-level member other than `data` is decoded; null: all.
	 * @param (callable(string): ?int)|null $member_cap      A smaller byte cap for a top-level member, by key.
	 * @return void
	 * @throws \UnexpectedValueException On invalid JSON, a value or count over a limit, or a read error.
	 */
	public function index( int $max_value_bytes, ?callable $read = null, ?callable $member_cap = null ): void {
		$this->envelope          = [];
		$this->tables            = [];
		$this->has_data          = false;
		$this->data_is_container = false;
		$this->kept              = 0;
		$this->skipped           = [];

		$this->seek( 0 );

		$first = $this->peek();

		if ( '[' === $first ) {
			$this->value( -1 );
			$this->expect_end();

			return;
		}

		if ( '{' !== $first ) {
			throw new \UnexpectedValueException( 'not a JSON object', self::ERR_SYNTAX );
		}

		$this->members(
			function ( string $key ) use ( $max_value_bytes, $read, $member_cap ): void {
				if ( 'data' !== $key ) {
					unset( $this->envelope[ $key ] );

					if ( null !== $read && ! $read( $key ) ) {
						$this->skip_member( $key );

						return;
					}

					$cap = null !== $member_cap ? $member_cap( $key ) : null;
					if ( is_int( $cap ) && $cap < $max_value_bytes ) {
						try {
							$raw = $this->capture( $cap );
						} catch ( \UnexpectedValueException $e ) {
							if ( self::ERR_TOO_LARGE === $e->getCode() ) {
								throw new \UnexpectedValueException( $key, self::ERR_TOO_LARGE_SETTING, $e );
							}

							throw $e;
						}

						$this->envelope[ $key ] = self::decode( $raw );

						return;
					}

					$this->envelope[ $key ] = self::decode( $this->capture( $max_value_bytes ) );

					return;
				}

				$this->has_data          = true;
				$this->tables            = [];
				$this->data_is_container = false;

				$c = $this->peek();

				if ( '{' === $c ) {
					$this->data_is_container = true;

					$this->members(
						function ( string $table ) use ( $max_value_bytes ): void {
							unset( $this->tables[ $table ] );
							$this->tables[ $table ] = $this->index_table( $max_value_bytes );
						},
						$this->max_data_members,
						self::ERR_TOO_MANY_DATA_MEMBERS
					);

					return;
				}

				// `data` as a JSON array (a hand-made file): decoded whole, each entry an in-memory table named by position.
				$value = self::decode( $this->capture( $max_value_bytes ) );

				if ( is_array( $value ) ) {
					$this->data_is_container = true;

					if ( count( $value ) > $this->max_data_members ) {
						throw new \UnexpectedValueException( 'more than ' . $this->max_data_members . ' members in data', self::ERR_TOO_MANY_DATA_MEMBERS );
					}

					foreach ( $value as $name => $member ) {
						$this->tables[ $name ] = is_array( $member ) ? [ 'rows' => $member ] : [ 'value' => $member ];
					}

					return;
				}
			},
			$this->max_members,
			self::ERR_TOO_MANY_MEMBERS
		);

		$this->expect_end();
	}

	/**
	 * Read a network export's structure: decode the top-level members $read names, record each slice's `site_url` and `export` offset.
	 *
	 * Each slice is copied out (copy_export()) and imported from its own file, so a large network is never held whole.
	 *
	 * @param int                           $max_value_bytes Largest member decoded whole, in bytes.
	 * @param (callable(string): bool)|null $read            Whether a top-level member other than `sites` is decoded; null: all.
	 * @return void
	 * @throws \UnexpectedValueException On invalid JSON, a value or count over a limit, or a read error.
	 */
	public function index_network( int $max_value_bytes, ?callable $read = null ): void {
		$this->envelope = [];
		$this->sites    = [];
		$this->kept     = 0;
		$this->skipped  = [];

		$this->seek( 0 );

		if ( '{' !== $this->peek() ) {
			throw new \UnexpectedValueException( 'not a JSON object', self::ERR_SYNTAX );
		}

		$this->members(
			function ( string $key ) use ( $max_value_bytes, $read ): void {
				if ( 'sites' !== $key || '{' !== $this->peek() ) {
					unset( $this->envelope[ $key ] );

					if ( 'sites' === $key ) {
						$this->sites = [];
					} elseif ( null !== $read && ! $read( $key ) ) {
						$this->skip_member( $key );

						return;
					}

					$this->envelope[ $key ] = self::decode( $this->capture( $max_value_bytes ) );

					return;
				}

				$this->sites = [];

				$this->members(
					function ( string $site ) use ( $max_value_bytes ): void {
						$entry = [
							'offset'    => null,
							'is_object' => false,
							'site_url'  => null,
						];

						if ( '{' !== $this->peek() ) {
							$this->value( -1 );
						} else {
							$this->members(
								function ( string $field ) use ( &$entry, $max_value_bytes ): void {
									if ( 'export' === $field ) {
										$entry['is_object'] = '{' === $this->peek();
										$entry['offset']    = $this->offset();
										$this->value( -1 );

										return;
									}

									if ( 'site_url' !== $field ) {
										$this->value( -1 );

										return;
									}

									$entry['site_url'] = self::decode( $this->capture( $max_value_bytes ) );
								}
							);
						}

						unset( $this->sites[ $site ] );
						$this->sites[ $site ] = $entry;
					},
					$this->max_data_members,
					self::ERR_TOO_MANY_DATA_MEMBERS
				);
			},
			$this->max_members,
			self::ERR_TOO_MANY_MEMBERS
		);

		$this->expect_end();
	}

	/**
	 * The slices index_network() found, in file order.
	 *
	 * @return array<array-key, array{offset: int|null, is_object: bool, site_url: mixed}>
	 */
	public function sites(): array {
		return $this->sites;
	}

	/**
	 * Copy one slice's `export` envelope, byte for byte, to a file.
	 *
	 * @param int|string $site Site key from sites().
	 * @param string     $dest Destination file (created or emptied).
	 * @return bool False when the slice has no export object or the copy could not be written.
	 * @throws \UnexpectedValueException On invalid JSON in the slice's structure or a read error.
	 */
	public function copy_export( $site, string $dest ): bool {
		$entry = $this->sites[ $site ] ?? null;

		if ( null === $entry || null === $entry['offset'] || ! $entry['is_object'] ) {
			return false;
		}

		$cursor = self::open( $this->path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write; WP_Filesystem has no streaming API.
		$out = fopen( $dest, 'wb' );

		if ( null === $cursor || ! is_resource( $out ) ) {
			if ( null !== $cursor ) {
				$cursor->close();
			}

			return false;
		}

		$ok = true;

		try {
			$cursor->seek( (int) $entry['offset'] );
			$cursor->sink = $out;
			$cursor->value( -1 );
		} catch ( \UnexpectedValueException $e ) {
			if ( self::ERR_WRITE !== $e->getCode() ) {
				throw $e;
			}

			$ok = false;
		} finally {
			$cursor->sink = null;
			$cursor->close();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.
			$ok = fclose( $out ) && $ok;
		}

		return $ok;
	}

	/**
	 * Top-level members other than `data`, decoded.
	 *
	 * @return array<array-key, mixed>
	 */
	public function envelope(): array {
		return $this->envelope;
	}

	/**
	 * Whether the file has a `data` member that is an object or an array.
	 *
	 * @return bool
	 */
	public function has_table_data(): bool {
		return $this->has_data && $this->data_is_container;
	}

	/**
	 * Members of `data` in file order, each as its row count, or as its decoded value when it is not a list.
	 *
	 * The importer's planning helpers rely on a list's count being empty() exactly when the list is.
	 *
	 * @return array<array-key, mixed>
	 */
	public function table_summary(): array {
		$out = [];

		foreach ( $this->tables as $name => $entry ) {
			if ( isset( $entry['rows'] ) ) {
				$out[ $name ] = count( $entry['rows'] );
			} elseif ( isset( $entry['offset'] ) ) {
				$out[ $name ] = (int) ( $entry['count'] ?? 0 );
			} else {
				$out[ $name ] = $entry['value'] ?? null;
			}
		}

		return $out;
	}

	/**
	 * Forget members of `data`: every later call treats them as absent, so their rows are never read.
	 *
	 * @param array<int, int|string> $names Member names.
	 * @return void
	 */
	public function forget_tables( array $names ): void {
		foreach ( $names as $name ) {
			unset( $this->tables[ $name ] );
		}
	}

	/**
	 * Whether a member of `data` is a row list (or object of rows).
	 *
	 * @param int|string $table Member name.
	 * @return bool
	 */
	public function is_row_list( $table ): bool {
		return isset( $this->tables[ $table ] ) && ( isset( $this->tables[ $table ]['offset'] ) || isset( $this->tables[ $table ]['rows'] ) );
	}

	/**
	 * Number of rows in a member of `data` (0 when absent or not a list).
	 *
	 * @param int|string $table Member name.
	 * @return int
	 */
	public function count( $table ): int {
		$entry = $this->tables[ $table ] ?? null;

		if ( null === $entry ) {
			return 0;
		}

		if ( isset( $entry['rows'] ) ) {
			return count( $entry['rows'] );
		}

		return (int) ( $entry['count'] ?? 0 );
	}

	/**
	 * The rows of one member of `data`, decoded one at a time.
	 *
	 * Each walk opens its own handle, so two walks may run at once and the index is never disturbed.
	 *
	 * @param int|string $table         Member name.
	 * @param int        $max_row_bytes Largest row read, in bytes.
	 * @return \Generator<array-key, mixed> Row key (position, or an object's member key) => decoded row.
	 * @throws \UnexpectedValueException On invalid JSON, a row over the limit, or a read error.
	 */
	public function rows( $table, int $max_row_bytes ): \Generator {
		$entry = $this->tables[ $table ] ?? null;

		if ( null === $entry ) {
			return;
		}

		if ( isset( $entry['rows'] ) ) {
			yield from $entry['rows'];

			return;
		}

		if ( ! isset( $entry['offset'] ) ) {
			return;
		}

		$cursor = self::open( $this->path );

		if ( null === $cursor ) {
			throw new \UnexpectedValueException( 'could not reopen the file', self::ERR_READ );
		}

		try {
			$cursor->seek( (int) $entry['offset'] );

			$open  = $cursor->peek();
			$close = '[' === $open ? ']' : '}';

			++$cursor->pos;

			if ( $close === $cursor->peek() ) {
				return;
			}

			$i = 0;

			while ( true ) {
				$key = $i;

				if ( '}' === $close ) {
					$key = $cursor->key();
					$cursor->expect( ':' );
				}

				$row = self::decode( (string) $cursor->value( $max_row_bytes ) );

				yield $key => $row;

				++$i;

				$c = $cursor->peek();

				if ( ',' === $c ) {
					++$cursor->pos;
					continue;
				}

				if ( $close === $c ) {
					++$cursor->pos;

					return;
				}

				throw new \UnexpectedValueException( 'expected , or ' . $close, self::ERR_SYNTAX );
			}
		} finally {
			$cursor->close();
		}
	}

	/**
	 * Index one member of `data`: a list or object of rows is skipped and counted; anything else is decoded within the limit.
	 *
	 * @param int $max_value_bytes Limit for a member that is not a list.
	 * @return array{offset?: int, count?: int, value?: mixed}
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function index_table( int $max_value_bytes ): array {
		$c = $this->peek();

		if ( '[' !== $c && '{' !== $c ) {
			return [ 'value' => self::decode( $this->capture( $max_value_bytes ) ) ];
		}

		$offset = $this->offset();
		$count  = 0;
		$close  = '[' === $c ? ']' : '}';

		++$this->pos;

		if ( $close === $this->peek() ) {
			++$this->pos;

			return [
				'offset' => $offset,
				'count'  => 0,
			];
		}

		while ( true ) {
			if ( '}' === $close ) {
				$this->key();
				$this->expect( ':' );
			}

			$this->value( -1 );
			++$count;

			$c = $this->peek();

			if ( ',' === $c ) {
				++$this->pos;
				continue;
			}

			if ( $close === $c ) {
				++$this->pos;
				break;
			}

			throw new \UnexpectedValueException( 'expected , or ' . $close, self::ERR_SYNTAX );
		}

		return [
			'offset' => $offset,
			'count'  => $count,
		];
	}

	/**
	 * Walk the members of the object at the current position; the callback must consume each value.
	 *
	 * Each key counts towards the bytes the index keeps (limit()).
	 *
	 * @param callable(string): void $on_member Member callback.
	 * @param int                    $max_count Most members.
	 * @param int                    $err_count Error code for more members than $max_count.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value or count is over a limit, or the file cannot be read or written.
	 */
	private function members( callable $on_member, int $max_count = PHP_INT_MAX, int $err_count = self::ERR_TOO_MANY_MEMBERS ): void {
		$this->expect( '{' );

		if ( '}' === $this->peek() ) {
			++$this->pos;

			return;
		}

		$count = 0;

		while ( true ) {
			if ( ++$count > $max_count ) {
				throw new \UnexpectedValueException( 'more than ' . $max_count . ' members', $err_count );
			}

			$key = $this->key();
			$this->keep( strlen( $key ) + 2 );
			$this->expect( ':' );
			$on_member( $key );

			$c = $this->peek();

			if ( ',' === $c ) {
				++$this->pos;
				continue;
			}

			if ( '}' === $c ) {
				++$this->pos;

				return;
			}

			throw new \UnexpectedValueException( 'expected , or }', self::ERR_SYNTAX );
		}
	}

	/**
	 * Capture the value at the current position for the index to keep, within $max bytes and what limit() leaves.
	 *
	 * @param int $max Largest value, in bytes.
	 * @return string The value's text.
	 * @throws \UnexpectedValueException When the JSON is malformed, the value is over a limit, or the file cannot be read.
	 */
	private function capture( int $max ): string {
		$room = $this->max_kept - $this->kept;

		try {
			$raw = (string) $this->value( max( 0, min( $max, $room ) ) );
		} catch ( \UnexpectedValueException $e ) {
			if ( self::ERR_TOO_LARGE === $e->getCode() && $room < $max ) {
				throw new \UnexpectedValueException( 'more than ' . $this->max_kept . ' bytes kept in all', self::ERR_TOO_MUCH_KEPT, $e );
			}

			throw $e;
		}

		$this->kept += strlen( $raw );

		return $raw;
	}

	/**
	 * Count bytes the index keeps.
	 *
	 * @param int $bytes Bytes.
	 * @return void
	 * @throws \UnexpectedValueException When the total passes limit().
	 */
	private function keep( int $bytes ): void {
		$this->kept += $bytes;

		if ( $this->kept > $this->max_kept ) {
			throw new \UnexpectedValueException( 'more than ' . $this->max_kept . ' bytes kept in all', self::ERR_TOO_MUCH_KEPT );
		}
	}

	/**
	 * Walk over a top-level member without reading it, and list it in skipped().
	 *
	 * @param string $key Member key.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed or the file cannot be read.
	 */
	private function skip_member( string $key ): void {
		$this->value( -1 );

		if ( ! in_array( $key, $this->skipped, true ) ) {
			$this->skipped[] = $key;
		}
	}

	/**
	 * Read an object key.
	 *
	 * @return string
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function key(): string {
		if ( '"' !== $this->peek() ) {
			throw new \UnexpectedValueException( 'expected a key', self::ERR_SYNTAX );
		}

		$key = self::decode( (string) $this->value( self::MAX_KEY_BYTES ) );

		if ( ! is_string( $key ) ) {
			throw new \UnexpectedValueException( 'key is not a string', self::ERR_SYNTAX );
		}

		return $key;
	}

	/**
	 * Consume one byte, which must be $char (after whitespace).
	 *
	 * @param string $char Expected byte.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function expect( string $char ): void {
		if ( $char !== $this->peek() ) {
			throw new \UnexpectedValueException( 'expected ' . $char, self::ERR_SYNTAX );
		}

		++$this->pos;
	}

	/**
	 * Only whitespace may follow the top-level value.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function expect_end(): void {
		if ( null !== $this->peek() ) {
			throw new \UnexpectedValueException( 'unexpected bytes after the JSON value', self::ERR_SYNTAX );
		}
	}

	/**
	 * Consume one JSON value at the current position.
	 *
	 * Only brackets and strings are checked here; json_decode() checks the rest when the text is decoded.
	 *
	 * @param int $max Capture up to this many bytes and return them; -1 to walk over the value without keeping it.
	 * @return string|null The value's text, or null when not capturing.
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function value( int $max ): ?string {
		$c = $this->peek();

		if ( null === $c ) {
			throw new \UnexpectedValueException( 'unexpected end of file', self::ERR_SYNTAX );
		}

		$out = $max >= 0 ? '' : null;

		if ( '"' === $c ) {
			$this->string_token( $out, $max );
		} elseif ( '{' === $c || '[' === $c ) {
			$this->container( $out, $max );
		} elseif ( '}' === $c || ']' === $c || ',' === $c || ':' === $c ) {
			throw new \UnexpectedValueException( 'expected a value', self::ERR_SYNTAX );
		} else {
			$this->scalar_token( $out, $max );
		}

		return $out;
	}

	/**
	 * Consume a string token (the current byte is its opening quote).
	 *
	 * @param string|null $out Capture buffer, null when not capturing.
	 * @param int         $max Capture limit.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function string_token( ?string &$out, int $max ): void {
		$this->take( 1, $out, $max );

		while ( true ) {
			if ( $this->pos >= strlen( $this->buf ) ) {
				if ( ! $this->fill() ) {
					throw new \UnexpectedValueException( 'unterminated string', self::ERR_SYNTAX );
				}

				continue;
			}

			$run = strcspn( $this->buf, '"\\', $this->pos );

			if ( $run > 0 ) {
				$this->take( $run, $out, $max );
			}

			if ( $this->pos >= strlen( $this->buf ) ) {
				continue;
			}

			if ( '"' === $this->buf[ $this->pos ] ) {
				$this->take( 1, $out, $max );

				return;
			}

			// A backslash and the byte it escapes travel together, so an escaped quote never ends the string.
			if ( ! $this->ensure( 2 ) ) {
				throw new \UnexpectedValueException( 'unterminated string', self::ERR_SYNTAX );
			}

			$this->take( 2, $out, $max );
		}
	}

	/**
	 * Consume an object or array, nested values included.
	 *
	 * @param string|null $out Capture buffer, null when not capturing.
	 * @param int         $max Capture limit.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function container( ?string &$out, int $max ): void {
		$closers = '';

		while ( true ) {
			if ( $this->pos >= strlen( $this->buf ) && ! $this->fill() ) {
				throw new \UnexpectedValueException( 'unterminated object or array', self::ERR_SYNTAX );
			}

			$c = $this->buf[ $this->pos ];

			if ( '{' === $c || '[' === $c ) {
				if ( strlen( $closers ) >= self::MAX_DEPTH ) {
					throw new \UnexpectedValueException( 'nested deeper than ' . self::MAX_DEPTH, self::ERR_SYNTAX );
				}

				$closers .= '{' === $c ? '}' : ']';
				$this->take( 1, $out, $max );
			} elseif ( '}' === $c || ']' === $c ) {
				if ( '' === $closers || substr( $closers, -1 ) !== $c ) {
					throw new \UnexpectedValueException( 'mismatched ' . $c, self::ERR_SYNTAX );
				}

				$closers = substr( $closers, 0, -1 );
				$this->take( 1, $out, $max );

				if ( '' === $closers ) {
					return;
				}
			} elseif ( '"' === $c ) {
				$this->string_token( $out, $max );
			} else {
				$run = strcspn( $this->buf, '"{}[]', $this->pos );
				$this->take( $run, $out, $max );
			}
		}
	}

	/**
	 * Consume a number or literal.
	 *
	 * @param string|null $out Capture buffer, null when not capturing.
	 * @param int         $max Capture limit.
	 * @return void
	 */
	private function scalar_token( ?string &$out, int $max ): void {
		while ( true ) {
			if ( $this->pos >= strlen( $this->buf ) && ! $this->fill() ) {
				return;
			}

			$run = strcspn( $this->buf, ",:}] \t\r\n[{\"", $this->pos );

			if ( $run > 0 ) {
				$this->take( $run, $out, $max );
			}

			if ( $this->pos < strlen( $this->buf ) ) {
				return;
			}
		}
	}

	/**
	 * Move $n bytes from the buffer into the capture, or to the copy stream, or past them.
	 *
	 * @param int         $n   Bytes; all must be in the buffer.
	 * @param string|null $out Capture buffer, null when not capturing.
	 * @param int         $max Capture limit.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function take( int $n, ?string &$out, int $max ): void {
		if ( null !== $out ) {
			if ( strlen( $out ) + $n > $max ) {
				throw new \UnexpectedValueException( 'value larger than ' . $max . ' bytes', self::ERR_TOO_LARGE );
			}

			$out .= substr( $this->buf, $this->pos, $n );
		} elseif ( is_resource( $this->sink ) && $n > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming copy.
			if ( fwrite( $this->sink, (string) substr( $this->buf, $this->pos, $n ) ) !== $n ) {
				throw new \UnexpectedValueException( 'write failed', self::ERR_WRITE );
			}
		}

		$this->pos += $n;
	}

	/**
	 * Skip whitespace and return the next byte without consuming it.
	 *
	 * @return string|null Null at the end of the file.
	 */
	private function peek(): ?string {
		while ( true ) {
			$len = strlen( $this->buf );

			if ( $this->pos < $len ) {
				$this->pos += strspn( $this->buf, " \t\r\n", $this->pos );

				if ( $this->pos < $len ) {
					return $this->buf[ $this->pos ];
				}
			}

			if ( ! $this->fill() ) {
				return null;
			}
		}
	}

	/**
	 * Make at least $n unread bytes available.
	 *
	 * @param int $n Bytes.
	 * @return bool False when the file ends first.
	 */
	private function ensure( int $n ): bool {
		$have = strlen( $this->buf ) - $this->pos;

		while ( $have < $n ) {
			if ( ! $this->fill() ) {
				return false;
			}

			$have = strlen( $this->buf ) - $this->pos;
		}

		return true;
	}

	/**
	 * Read the next chunk into the buffer, dropping the bytes already consumed.
	 *
	 * @return bool False at the end of the file.
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function fill(): bool {
		if ( $this->eof || ! is_resource( $this->handle ) ) {
			return false;
		}

		if ( $this->pos > 0 ) {
			$this->buf   = (string) substr( $this->buf, $this->pos );
			$this->base += $this->pos;
			$this->pos   = 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming read; WP_Filesystem reads whole files only.
		$chunk = fread( $this->handle, self::CHUNK );

		if ( false === $chunk ) {
			throw new \UnexpectedValueException( 'read error', self::ERR_READ );
		}

		if ( '' === $chunk ) {
			$this->eof = true;

			return false;
		}

		$this->buf .= $chunk;

		return true;
	}

	/**
	 * File offset of the read position.
	 *
	 * @return int
	 */
	private function offset(): int {
		return $this->base + $this->pos;
	}

	/**
	 * Move the read position to a file offset.
	 *
	 * @param int $offset File offset.
	 * @return void
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private function seek( int $offset ): void {
		if ( ! is_resource( $this->handle ) || 0 !== fseek( $this->handle, $offset ) ) {
			throw new \UnexpectedValueException( 'seek failed', self::ERR_READ );
		}

		$this->buf  = '';
		$this->pos  = 0;
		$this->base = $offset;
		$this->eof  = false;
	}

	/**
	 * Decode one value's text.
	 *
	 * @param string $raw JSON text.
	 * @return mixed
	 * @throws \UnexpectedValueException When the JSON is malformed, a value is over the size limit, or the file cannot be read or written.
	 */
	private static function decode( string $raw ) {
		$value = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \UnexpectedValueException( 'invalid JSON value: ' . json_last_error_msg(), self::ERR_SYNTAX );
		}

		return $value;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
