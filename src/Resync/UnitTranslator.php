<?php
/**
 * Machine translation of the units of one pair.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\MachineTranslation\AbstractProvider;
use PerfLocale\MachineTranslation\NotSavedException;
use PerfLocale\MachineTranslation\ProviderInterface;
use PerfLocale\MachineTranslation\ShortcodeMask;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Settings;
use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Translation\PlaceholderMasker;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine translates a pair's units and fields (masked, in per-format/per-context sub-batches) before anything is
 * written. Finished requests' outputs are kept as chunked transients, so a stopped pair never pays a request twice;
 * each kept key is indexed in an option row first so uninstall can remove it by name. Design: docs-ai (UnitTranslator).
 *
 * @phpstan-type Request array{index: int|string, text: string, format: string, context?: string}
 * @phpstan-type StoredEntry array{h: string, o: string|null}
 * @phpstan-type Kept array{key: string, chunks: int}
 * @phpstan-type Reuse array{texts: array<int|string, string>, rejected: list<int|string>, pending: list<Request>, kept: Kept, next: int, expires: int}
 */
final class UnitTranslator {

	/** Providers whose batch API takes several texts per request. */
	public const BATCH_NATIVE = [ 'deepl', 'google', 'microsoft' ];

	/** Units per request for a batch-native provider. */
	public const BATCH_UNITS = 25;

	/** The index prefix of a custom field's request. */
	public const FIELD_PREFIX = 'field:';

	/** The version of the masking, sanitizing and output-check rules: outputs kept under other rules are not reused. */
	private const MASK_RULES = 5;

	/** Units after which unkept outputs are kept before the next request. */
	public const KEEP_EVERY_UNITS = 25;

	/** Seconds of requests after which unkept outputs are kept before the next request. */
	public const KEEP_EVERY_SECONDS = 1.0;

	/** Lifetime of a kept set from its first write; a second under a day so chunk 0 (a second longer) ends within a day. */
	public const KEEP_SECONDS = 86399;

	/** Most bytes of kept outputs in one write (entries counted with KEEP_ENTRY_BYTES); a larger write is not made. */
	public const KEEP_BYTES = 2097152;

	/** Bytes after which a checkpoint starts a new chunk instead of rewriting the current one. */
	public const KEEP_CHUNK_BYTES = 65536;

	/** Bytes one kept entry counts besides its output (hash, index, framing). */
	public const KEEP_ENTRY_BYTES = 128;

	/** Prefix of the per-key index option rows (`<expiry>|<chunks>|<key>`, not autoloaded); SiteCleanup reads them. */
	public const KEEP_INDEX = 'perflocale_resync_kept_';

	/** Outcomes after which a pair runs again on the same inputs, so its kept outputs stay; any other removes them. */
	public const KEEP_OUTCOMES = [ 'deferred', 'failed', 'busy', 'unavailable', 'limit', 'needs_estimate' ];

	/**
	 * Units per request for a provider (filterable).
	 *
	 * @param string $provider_id Provider id.
	 * @return int
	 */
	public static function batch_size( string $provider_id ): int {
		$units = in_array( $provider_id, self::BATCH_NATIVE, true ) ? self::BATCH_UNITS : 1;

		/**
		 * Filters how many units a resync sends per provider request.
		 *
		 * @hook perflocale/resync/provider_batch_units
		 * @since 1.0.8
		 *
		 * @param int    $units       Units per request: 25 for DeepL, Google and Microsoft, else 1.
		 * @param string $provider_id The provider.
		 */
		return max( 1, Scalar::int( apply_filters( 'perflocale/resync/provider_batch_units', $units, $provider_id ) ) );
	}

	/**
	 * Mask a unit's text: a text unit as MetaTranslator masks meta values; an html unit is sent as it is (the service
	 * holds its shortcode tags back with ShortcodeMask).
	 *
	 * @param string $text   The text.
	 * @param string $format `html` or `text`.
	 * @return array{0: string, 1: array<int, string>} The masked text and the placeholders, in token order.
	 */
	public static function mask( string $text, string $format ): array {
		return BlockAttributeSource::FORMAT_TEXT === $format ? PlaceholderMasker::mask( $text ) : [ $text, [] ];
	}

	/**
	 * Mask a custom field's value in either format as MetaTranslator does; in html the service holds shortcodes back.
	 *
	 * @param string $text The value.
	 * @return array{0: string, 1: array<int, string>} The masked text and the placeholders, in token order.
	 */
	public static function mask_field( string $text ): array {
		return PlaceholderMasker::mask( $text );
	}

	/**
	 * Mask a request: a custom field with mask_field(), any other unit with mask().
	 *
	 * @param array<string, mixed> $request The request.
	 * @return array{0: string, 1: array<int, string>}
	 * @phpstan-param Request $request
	 */
	public static function mask_request( array $request ): array {
		return is_string( $request['index'] ) && str_starts_with( $request['index'], self::FIELD_PREFIX )
			? self::mask_field( $request['text'] )
			: self::mask( $request['text'], $request['format'] );
	}

	/**
	 * A masked text as the provider receives it: in html with the shortcode tags held back as the service holds them.
	 *
	 * @param string $masked   The masked text.
	 * @param string $format   `html` or `text`.
	 * @param bool   $elements Placeholders as elements ({@see self::elements()}).
	 * @return string
	 */
	public static function held( string $masked, string $format, bool $elements = false ): string {
		$held = BlockAttributeSource::FORMAT_TEXT === $format ? null : ShortcodeMask::mask( $masked, $elements );

		return null === $held ? $masked : $held->text();
	}

	/**
	 * Whether a provider receives shortcode placeholders as elements.
	 *
	 * @param ProviderInterface $provider The provider.
	 * @return bool
	 */
	public static function elements( ProviderInterface $provider ): bool {
		return $provider instanceof AbstractProvider && $provider->placeholders_as_elements();
	}

	/**
	 * Whether a field value has no letter outside its placeholders and, in html, its held shortcode tags (copied
	 * unsent, as MetaTranslator does).
	 *
	 * @param string $text   The value.
	 * @param string $format `html` or `text`.
	 * @return bool
	 */
	public static function verbatim( string $text, string $format = 'html' ): bool {
		$masked = self::mask_field( $text )[0];
		$bare   = preg_replace( '/\[\[PFL_PH_\d+\]\]/', '', BlockAttributeSource::FORMAT_TEXT === $format ? $masked : ShortcodeMask::bare( $masked ) );

		// Text that is not valid UTF-8 (preg_match() false) is sent, as MetaTranslator sends it.
		return null !== $bare && 0 === preg_match( '/\p{L}/u', $bare );
	}

	/**
	 * Put the placeholders back into an output.
	 *
	 * @param string             $text         The output.
	 * @param array<int, string> $placeholders The placeholders of mask().
	 * @return string
	 */
	public static function unmask( string $text, array $placeholders ): string {
		return PlaceholderMasker::restore( $text, $placeholders );
	}

	/**
	 * The characters the requests send (masked texts as held(), plus contexts when billed).
	 *
	 * @param array<int, array<string, mixed>> $requests       Requests.
	 * @param bool                             $context_billed Whether the context counts.
	 * @param bool                             $elements       Shortcode placeholders as elements ({@see self::elements()}).
	 * @phpstan-param list<Request> $requests
	 * @return int
	 */
	public static function chars( array $requests, bool $context_billed = false, bool $elements = false ): int {
		$total = 0;

		foreach ( $requests as $request ) {
			$total += \PerfLocale\Util\Utf8::length( self::held( self::mask_request( $request )[0], $request['format'], $elements ) );

			if ( $context_billed ) {
				$total += \PerfLocale\Util\Utf8::length( $request['context'] ?? '' );
			}
		}

		return $total;
	}

	/**
	 * How many provider requests the requests need.
	 *
	 * @param array<int, array<string, mixed>> $requests Requests.
	 * @param int                              $size     Units per request.
	 * @phpstan-param list<Request> $requests
	 * @return int
	 */
	public static function request_count( array $requests, int $size ): int {
		return count( self::batches( $requests, $size ) );
	}

	/**
	 * The sub-batches per format, then per context (a provider takes one context per request), of up to `$size`.
	 *
	 * @param array<int, array<string, mixed>> $requests Requests by position.
	 * @param int                              $size     Units per request.
	 * @phpstan-param array<int, Request> $requests
	 * @return list<array{0: string, 1: list<int>, 2: string}> Format, request positions and context.
	 */
	public static function batches( array $requests, int $size ): array {
		$by_format = [];

		foreach ( $requests as $position => $request ) {
			$format  = BlockAttributeSource::FORMAT_TEXT === $request['format'] ? BlockAttributeSource::FORMAT_TEXT : BlockAttributeSource::FORMAT_HTML;
			$context = "\0" . ( $request['context'] ?? '' );

			$by_format[ $format ][ $context ][] = $position;
		}

		$out = [];

		foreach ( $by_format as $format => $by_context ) {
			foreach ( $by_context as $context => $positions ) {
				foreach ( array_chunk( $positions, max( 1, $size ) ) as $chunk ) {
					$out[] = [ (string) $format, $chunk, substr( (string) $context, 1 ) ];
				}
			}
		}

		return $out;
	}

	/**
	 * The stored form of finished requests: per index the request hash and output (null: rejected).
	 *
	 * @param array<int, array<string, mixed>> $requests The requests that were sent.
	 * @param array<int|string, string>        $texts    Accepted outputs by index.
	 * @param array<int, int|string>           $rejected Indexes whose output was rejected.
	 * @phpstan-param list<Request> $requests
	 * @return array<string, array{h: string, o: string|null}>
	 */
	public static function store_entries( array $requests, array $texts, array $rejected ): array {
		$out = [];

		foreach ( $requests as $request ) {
			$index = $request['index'];

			if ( isset( $texts[ $index ] ) ) {
				$out[ (string) $index ] = [
					'h' => self::request_hash( $request ),
					'o' => $texts[ $index ],
				];
			} elseif ( in_array( $index, $rejected, true ) ) {
				$out[ (string) $index ] = [
					'h' => self::request_hash( $request ),
					'o' => null,
				];
			}
		}

		return $out;
	}

	/**
	 * Split requests into those a stored output answers and those still to send.
	 *
	 * @param array<int, array<string, mixed>> $requests The requests.
	 * @param array<mixed>                     $stored   Stored entries ({@see store_entries()}).
	 * @phpstan-param list<Request> $requests
	 * @return array{texts: array<int|string, string>, rejected: list<int|string>, pending: list<Request>}
	 */
	public static function reuse( array $requests, array $stored ): array {
		$texts    = [];
		$rejected = [];
		$pending  = [];

		foreach ( $requests as $request ) {
			$entry = $stored[ (string) $request['index'] ] ?? null;

			if ( ! is_array( $entry ) || ! is_string( $entry['h'] ?? null ) || ! hash_equals( $entry['h'], self::request_hash( $request ) ) ) {
				$pending[] = $request;
				continue;
			}

			if ( is_string( $entry['o'] ?? null ) ) {
				$texts[ $request['index'] ] = $entry['o'];
			} else {
				$rejected[] = $request['index'];
			}
		}

		return [
			'texts'    => $texts,
			'rejected' => $rejected,
			'pending'  => $pending,
		];
	}

	/**
	 * The hash a stored output is matched by: format, text and context.
	 *
	 * @param array<string, mixed> $request A request.
	 * @phpstan-param Request $request
	 * @return string
	 */
	private static function request_hash( array $request ): string {
		return StateHash::stored( $request['format'], $request['text'], $request['context'] ?? '' );
	}

	/**
	 * The version of a pair's kept outputs: everything besides each request that shapes a reply (provider,
	 * languages, reply-changing settings, masking rules, the caller's pair state). Another version answers nothing.
	 *
	 * @param TranslationService $service     The service.
	 * @param Settings           $settings    The settings.
	 * @param string             $source_lang Source language slug.
	 * @param string             $target_lang Target language slug.
	 * @param array<int, string> $state       The caller's state of the pair.
	 * @return string
	 */
	public static function version( TranslationService $service, Settings $settings, string $source_lang, string $target_lang, array $state ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					$service->get_provider()->get_id(),
					$source_lang,
					$target_lang,
					Scalar::str( $settings->get( 'mt_deepl_formality', 'default' ) ),
					Scalar::str( $settings->get( 'mt_libre_url', '' ) ),
					Scalar::str( $settings->get( 'mt_agency_url', '' ) ),
					self::MASK_RULES,
					array_values( $state ),
				]
			)
		);
	}

	/**
	 * What the outputs kept under a key answer, from a whole, current set only; any other set found there is
	 * removed now. The read goes on past one missing chunk; chunks after two missing in a row expire with their set.
	 *
	 * @param string                           $key      The key.
	 * @param string                           $version  {@see version()}.
	 * @param array<int, array<string, mixed>> $requests The requests.
	 * @phpstan-param list<Request> $requests
	 * @return array<string, mixed>
	 * @phpstan-return Reuse
	 */
	public static function kept( string $key, string $version, array $requests ): array {
		$stored = [];

		// Until two in a row are missing, so a chunk after a single missing one is found too.
		for ( $chunk = 0, $missing = 0; $missing < 2; $chunk++ ) {
			$value = get_transient( self::chunk_key( $key, $chunk ) );

			if ( false === $value ) {
				++$missing;
				continue;
			}

			$missing          = 0;
			$stored[ $chunk ] = $value;
		}

		$set = self::whole_set( $stored, $version );

		if ( null === $set ) {
			foreach ( array_keys( $stored ) as $found ) {
				delete_transient( self::chunk_key( $key, $found ) );
			}

			return self::reuse( $requests, [] ) + [
				'kept'    => [
					'key'    => '',
					'chunks' => 0,
				],
				'next'    => 0,
				'expires' => 0,
			];
		}

		return self::reuse( $requests, $set['entries'] ) + [
			'kept'    => [
				'key'    => $key,
				'chunks' => count( $stored ),
			],
			'next'    => count( $stored ),
			'expires' => $set['expires'],
		];
	}

	/**
	 * The entries and expiry of the chunks read when they form one gapless, current, unexpired set; else null.
	 *
	 * @param array<int, mixed> $stored  The chunks read, by chunk number.
	 * @param string            $version {@see version()}.
	 * @return array{entries: array<mixed>, expires: int}|null
	 */
	private static function whole_set( array $stored, string $version ): ?array {
		$head    = $stored[0] ?? null;
		$expires = is_array( $head ) ? ( $head['x'] ?? null ) : null;

		if ( ! is_int( $expires ) || $expires <= time() || array_key_last( $stored ) !== count( $stored ) - 1 ) {
			return null;
		}

		$entries = [];

		foreach ( $stored as $value ) {
			if ( ! is_array( $value ) || $version !== ( $value['v'] ?? null ) || $expires !== ( $value['x'] ?? null ) || ! is_array( $value['e'] ?? null ) ) {
				return null;
			}

			$entries = array_replace( $entries, $value['e'] );
		}

		return [
			'entries' => $entries,
			'expires' => $expires,
		];
	}

	/**
	 * The transient of a chunk: the key for chunk 0, `<key>_<n>` for the others.
	 *
	 * @param string $key   The key.
	 * @param int    $chunk The chunk, from 0.
	 * @return string
	 */
	private static function chunk_key( string $key, int $chunk ): string {
		return 0 === $chunk ? $key : $key . '_' . $chunk;
	}

	/**
	 * The bytes kept entries count: each its output and KEEP_ENTRY_BYTES.
	 *
	 * @param array<string, array{h: string, o: string|null}> $entries Entries ({@see store_entries()}).
	 * @return int
	 */
	private static function bytes( array $entries ): int {
		$bytes = 0;

		foreach ( $entries as $entry ) {
			$bytes += self::KEEP_ENTRY_BYTES + strlen( (string) $entry['o'] );
		}

		return $bytes;
	}

	/**
	 * Keep entries as one chunk of a set, when they fit KEEP_BYTES and the set has not expired. Chunk 0 lives a
	 * second longer so no chunk outlives it by the clock tick between two writes.
	 *
	 * @param string                                          $key     The key.
	 * @param int                                             $chunk   The chunk, from 0.
	 * @param string                                          $version {@see version()}.
	 * @param array<string, array{h: string, o: string|null}> $entries Entries ({@see store_entries()}).
	 * @param int                                             $expires The set's expiry (a Unix time).
	 * @return bool Whether they were written.
	 */
	public static function keep( string $key, int $chunk, string $version, array $entries, int $expires ): bool {
		$seconds = $expires - time() + ( 0 === $chunk ? 1 : 0 );

		return $seconds >= 1 && self::bytes( $entries ) <= self::KEEP_BYTES && set_transient(
			self::chunk_key( $key, $chunk ),
			[
				'v' => $version,
				'x' => $expires,
				'e' => $entries,
			],
			$seconds
		);
	}

	/**
	 * The checkpoint translate() calls: keeps outputs not yet kept in the current chunk, or a new one past
	 * KEEP_CHUNK_BYTES, so no write grows with the pair. Returns whether every output so far is kept.
	 *
	 * @param string                           $key      The key.
	 * @param string                           $version  {@see version()}.
	 * @param array<int, array<string, mixed>> $requests All requests of the pair.
	 * @param array<string, mixed>             $reuse    {@see kept()} for the requests.
	 * @param array<string, mixed>             $kept     What is kept under the key ({@see kept()}).
	 * @phpstan-param list<Request> $requests
	 * @phpstan-param array{kept: Kept, next: int, expires: int} $reuse
	 * @phpstan-param Kept $kept
	 * @return \Closure(array<int|string, string>, list<int|string>): bool
	 */
	public static function checkpoint( string $key, string $version, array $requests, array $reuse, array &$kept ): \Closure {
		$chunk   = $reuse['next'];
		$expires = $reuse['expires'];
		$current = [];
		$done    = [];
		$listed  = [ 0, 0 ];

		return static function ( array $texts, array $rejected ) use ( $key, $version, $requests, &$chunk, &$expires, &$current, &$done, &$kept, &$listed ): bool {
			/**
			 * The outputs so far, as translate() passes them.
			 *
			 * @var array<int|string, string> $texts
			 * @var list<int|string>          $rejected
			 */
			$new = array_diff_key( self::store_entries( $requests, $texts, $rejected ), $done );

			if ( [] === $new ) {
				return true;
			}

			$next = [] !== $current && self::bytes( $current ) + self::bytes( $new ) > self::KEEP_CHUNK_BYTES ? $chunk + 1 : $chunk;
			$keep = $next === $chunk ? $current + $new : $new;
			$at   = $expires > 0 ? $expires : time() + self::KEEP_SECONDS;

			// Index first, so no chunk exists that the index does not name.
			if ( $listed[0] !== $at || $listed[1] <= $next ) {
				if ( ! self::index( $key, $at, $next + 1 ) ) {
					return false;
				}

				$listed = [ $at, $next + 1 ];
			}

			if ( ! self::keep( $key, $next, $version, $keep, $at ) ) {
				return false;
			}

			$expires = $at;
			$chunk   = $next;
			$current = $keep;
			$done   += $new;
			$kept    = [
				'key'    => $key,
				'chunks' => max( $kept['chunks'], $chunk + 1 ),
			];

			return true;
		};
	}

	/**
	 * Remove a key's kept outputs and index row once the outcome no longer needs them (not KEEP_OUTCOMES).
	 *
	 * @param array<string, mixed> $kept   What is kept ({@see kept()}, {@see checkpoint()}).
	 * @param string               $status The pair's outcome.
	 * @phpstan-param Kept $kept
	 * @return void
	 */
	public static function settle( array $kept, string $status ): void {
		if ( '' === $kept['key'] || in_array( $status, self::KEEP_OUTCOMES, true ) ) {
			return;
		}

		$chunks = max( $kept['chunks'], self::listed( $kept['key'] )[1] );

		for ( $chunk = 0; $chunk < $chunks; $chunk++ ) {
			delete_transient( self::chunk_key( $kept['key'], $chunk ) );
		}

		delete_option( self::index_name( $kept['key'] ) );
	}

	/**
	 * The option row that lists a key.
	 *
	 * @param string $key The key.
	 * @return string
	 */
	private static function index_name( string $key ): string {
		return self::KEEP_INDEX . md5( $key );
	}

	/**
	 * A key's index row as [ expiry, chunks ]; [ 0, 0 ] for no row or another key's.
	 *
	 * @param string $key The key.
	 * @return array{0: int, 1: int}
	 */
	private static function listed( string $key ): array {
		$parts = explode( '|', Scalar::str( get_option( self::index_name( $key ), '' ) ), 3 );

		return 3 === count( $parts ) && $key === $parts[2] ? [ max( 0, (int) $parts[0] ), max( 0, (int) $parts[1] ) ] : [ 0, 0 ];
	}

	/**
	 * List a key in its index row, keeping the later expiry and most chunks so an unremoved older set stays listed.
	 *
	 * @param string $key     The key.
	 * @param int    $expires The set's expiry.
	 * @param int    $chunks  The chunks written, counting the one about to be.
	 * @return bool Whether the row lists them.
	 */
	private static function index( string $key, int $expires, int $chunks ): bool {
		$name   = self::index_name( $key );
		$stored = get_option( $name, '' );
		$listed = self::listed( $key );
		$value  = max( $expires, $listed[0] ) . '|' . max( $chunks, $listed[1] ) . '|' . $key;

		return $value === $stored || update_option( $name, $value, false );
	}

	/**
	 * Remove up to `$batch` index rows of sets expired over a minute ago, each only while it holds the value read.
	 * INSTR/SUBSTR instead of SUBSTRING_INDEX: SQLite runs them too.
	 *
	 * @param int $batch Rows per call.
	 * @return int Rows read.
	 */
	public static function reap_index( int $batch ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM %i WHERE option_name LIKE %s AND CAST(CASE WHEN INSTR(option_value, '|') > 0 THEN SUBSTR(option_value, 1, INSTR(option_value, '|') - 1) ELSE option_value END AS UNSIGNED) < %d LIMIT %d",
				$wpdb->options,
				$wpdb->esc_like( self::KEEP_INDEX ) . '%',
				time() - 60,
				max( 1, $batch )
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : [];

		foreach ( $rows as $row ) {
			$name = Scalar::str( $row['option_name'] ?? '' );

			if ( $wpdb->delete(
				$wpdb->options,
				[
					'option_name'  => $name,
					'option_value' => Scalar::str( $row['option_value'] ?? '' ),
				]
			) ) {
				wp_cache_delete( $name, 'options' );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return count( $rows );
	}

	/**
	 * Translate the requests; a rejected one, and every request of a call whose shortcode markers came back damaged, is
	 * named in `rejected` (its unit keeps the source text). The checkpoint runs every KEEP_EVERY_UNITS /
	 * KEEP_EVERY_SECONDS, before a stop and after the last request; `unkept` says the outputs were not all kept.
	 *
	 * @param TranslationService               $service     The service.
	 * @param array<int, array<string, mixed>> $requests    Requests: `index` (the unit index, or the field key), `text`, `format`.
	 * @param string                           $source_lang Source language slug.
	 * @param string                           $target_lang Target language slug.
	 * @param \Closure|null                    $clock       The job's clock, asked before each request (false stops); null for none.
	 * @param \Closure|null                    $checkpoint  Called with the outputs so far before the next request, null for none.
	 * @phpstan-param list<Request> $requests
	 * @phpstan-param (\Closure(int): bool)|null $clock
	 * @phpstan-param (\Closure(array<int|string, string>, list<int|string>): bool)|null $checkpoint
	 * @return array{texts: array<int|string, string>, rejected: list<int|string>, requests: int, chars: int, stopped: bool, unkept: bool}
	 * @throws \Exception On a provider failure, a \RuntimeException (nothing of the pair is written; what a checkpoint kept stays).
	 */
	public function translate( TranslationService $service, array $requests, string $source_lang, string $target_lang, ?\Closure $clock = null, ?\Closure $checkpoint = null ): array {
		$provider = $service->get_provider();
		$size     = self::batch_size( $provider->get_id() );
		$masked   = [];
		$billed   = false;

		foreach ( $requests as $request ) {
			if ( '' !== ( $request['context'] ?? '' ) ) {
				$billed = $service->context_billed( $provider );
				break;
			}
		}

		$texts    = [];
		$rejected = [];
		$elements = self::elements( $provider );

		foreach ( $requests as $position => $request ) {
			$masked[ $position ] = self::mask_request( $request );
		}

		$count   = 0;
		$chars   = 0;
		$stopped = false;
		$unkept  = false;

		$batches  = self::batches( $requests, $size );
		$last     = count( $batches ) - 1;
		$unsaved  = 0;
		$saved_at = microtime( true );

		foreach ( $batches as $number => [ $format, $positions, $context ] ) {
			if ( null !== $clock && ! $clock( $count ) ) {
				$unkept = null !== $checkpoint && $unsaved > 0 && ! $checkpoint( $texts, $rejected );

				$stopped = true;
				break;
			}

			$batch = [];

			foreach ( $positions as $position ) {
				$batch[] = $masked[ $position ][0];
				$chars  += \PerfLocale\Util\Utf8::length( self::held( $masked[ $position ][0], $format, $elements ) ) + ( $billed ? \PerfLocale\Util\Utf8::length( $context ) : 0 );
			}

			try {
				$outputs = '' === $context
					? $service->translate_batch_texts( $batch, $source_lang, $target_lang, $provider->get_id(), true, $format )
					: $service->translate_batch_texts_with_context( $batch, array_fill( 0, count( $batch ), $context ), $source_lang, $target_lang, $provider->get_id(), true, $format );
			} catch ( NotSavedException $e ) {
				$outputs = null;
			} catch ( \Exception $e ) {
				if ( null !== $checkpoint && $unsaved > 0 ) {
					$checkpoint( $texts, $rejected );
				}

				throw $e;
			}

			++$count;

			foreach ( $positions as $i => $position ) {
				$request = $requests[ $position ];

				if ( null === $outputs ) {
					$rejected[] = $request['index'];
					continue;
				}

				$answer   = Scalar::str( $outputs[ $i ] ?? '' );
				$restored = self::unmask( $answer, $masked[ $position ][1] );
				// The MT allowlist with the placeholders back, the source's own held elements kept (SaveProbe applies the user's rules).
				$output = TranslationService::sanitize_mt_value_kept( $restored, $request['text'] );
				$why    = MtQuality::reject_reason( $request['text'], $restored, $format, $answer, count( $masked[ $position ][1] ) );

				if ( '' === $why && $output !== $restored ) {
					$why = MtQuality::reject_reason( $request['text'], $output, $format, $restored );
				}

				if ( '' === $why ) {
					$texts[ $request['index'] ] = $output;
				} else {
					$rejected[] = $request['index'];
				}
			}

			$unsaved += count( $positions );

			if ( null !== $checkpoint && $number < $last && ( $unsaved >= self::KEEP_EVERY_UNITS || microtime( true ) - $saved_at >= self::KEEP_EVERY_SECONDS ) && $checkpoint( $texts, $rejected ) ) {
				$unsaved  = 0;
				$saved_at = microtime( true );
			}
		}

		// Paid outputs are kept before the caller's write, which may end busy or never finish; settle() removes them.
		if ( ! $stopped && null !== $checkpoint && $unsaved > 0 ) {
			$unkept = ! $checkpoint( $texts, $rejected );
		}

		return [
			'texts'    => $texts,
			'rejected' => $rejected,
			'requests' => $count,
			'chars'    => $chars,
			'stopped'  => $stopped,
			'unkept'   => $unkept,
		];
	}
}
