<?php
/**
 * Provider answers a background slice finished for the post it was on.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers of one post's provider calls, by the hash of what was sent, kept in the bulk job's own row so the next web
 * slice sends only the rest; a literal require, as only job slices use it.
 *
 * @since 1.0.8
 * @internal
 */
final class PieceAnswers {

	/**
	 * Largest map unpack() inflates: far above what a job row's capped result holds deflated.
	 */
	private const MAX_INFLATED = 16777216;

	/**
	 * Prefix of answers stored as plain JSON (no zlib); never the start of base64.
	 */
	private const PLAIN = 'json:';

	/**
	 * Settings that change a provider's answer to the same text.
	 */
	private const OUTPUT_SETTINGS = [ 'mt_deepl_formality', 'mt_microsoft_region', 'mt_libre_url', 'mt_agency_url', 'mt_agency_batch' ];

	/**
	 * Answers by key().
	 *
	 * @var array<string, list<string>>
	 */
	private array $answers;

	/**
	 * Called with this object after each new answer.
	 *
	 * @var callable(self): void
	 */
	private $keep;

	/**
	 * Fingerprint of the OUTPUT_SETTINGS values.
	 *
	 * @var string
	 */
	private string $settings;

	/**
	 * Answers added by this run.
	 *
	 * @var int
	 */
	private int $added = 0;

	/**
	 * The call a stop interrupted, as named to the user ('' when none).
	 *
	 * @var string
	 */
	public string $in_flight = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, list<string>> $answers  Stored answers (unpack()).
	 * @param object                      $settings Plugin settings (get()).
	 * @param callable                    $keep     Called with this object after each new answer.
	 * @phpstan-param callable(self): void $keep
	 */
	public function __construct( array $answers, object $settings, callable $keep ) {
		$values = [];

		foreach ( self::OUTPUT_SETTINGS as $name ) {
			$values[ $name ] = method_exists( $settings, 'get' ) ? $settings->get( $name ) : null;
		}

		$this->answers  = $answers;
		$this->keep     = $keep;
		$this->settings = (string) wp_json_encode( $values );
	}

	/**
	 * Key of one call: provider, settings, languages, format and texts.
	 *
	 * @param string       $provider Provider id.
	 * @param string       $source   Source language slug.
	 * @param string       $target   Target language slug.
	 * @param string       $format   'html' or 'text'.
	 * @param list<string> $texts    Texts sent.
	 * @return string
	 */
	public function key( string $provider, string $source, string $target, string $format, array $texts ): string {
		return hash( 'sha256', (string) wp_json_encode( [ $provider, $this->settings, $source, $target, $format, $texts ] ) );
	}

	/**
	 * The stored answers of a call that sent $count texts, or null.
	 *
	 * @param string $key   key().
	 * @param int    $count Texts sent.
	 * @return list<string>|null
	 */
	public function get( string $key, int $count ): ?array {
		$answers = $this->answers[ $key ] ?? null;

		return null !== $answers && count( $answers ) === $count ? $answers : null;
	}

	/**
	 * Keep a call's answers and hand them to the job.
	 *
	 * @param string       $key     key().
	 * @param list<string> $answers Answers.
	 * @return void
	 */
	public function put( string $key, array $answers ): void {
		$this->answers[ $key ] = $answers;
		++$this->added;
		( $this->keep )( $this );
	}

	/**
	 * Answers held.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->answers );
	}

	/**
	 * Answers this run added.
	 *
	 * @return int
	 */
	public function added(): int {
		return $this->added;
	}

	/**
	 * The answers as stored: deflated JSON in base64 (several times more fits in the row's capped result); without zlib
	 * the JSON itself after PLAIN, so a run on such a host keeps its answers too.
	 *
	 * @return string '' when they cannot be packed.
	 */
	public function pack(): string {
		$json = wp_json_encode( $this->answers );

		if ( ! is_string( $json ) ) {
			return '';
		}

		$data = function_exists( 'gzdeflate' ) ? gzdeflate( $json, 6 ) : false;

		return is_string( $data ) ? base64_encode( $data ) : self::PLAIN . $json; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to JSON-safe text.
	}

	/**
	 * Why stored answers cannot be read: '' when all $n of them decode, 'zlib' when this PHP cannot inflate them,
	 * 'damaged' for anything else (no data for $n > 0, an invalid entry, another count). A resumed job stops then.
	 *
	 * @param string $data pack() output.
	 * @param int    $n    The answer count stored beside it.
	 * @return string
	 */
	public static function unreadable( string $data, int $n ): string {
		if ( '' === $data ) {
			return 0 === $n ? '' : 'damaged';
		}

		if ( str_starts_with( $data, self::PLAIN ) ) {
			$json = strlen( $data ) <= self::MAX_INFLATED ? substr( $data, strlen( self::PLAIN ) ) : false;
		} else {
			if ( ! function_exists( 'gzinflate' ) ) {
				return 'zlib';
			}

			$raw  = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- pack()'s own encoding.
			$json = is_string( $raw ) ? @gzinflate( $raw, self::MAX_INFLATED ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Damaged data is reported, not raised.
		}

		$map = is_string( $json ) ? json_decode( $json, true, 3 ) : null;

		if ( ! is_array( $map ) || count( $map ) !== $n ) {
			return 'damaged';
		}

		foreach ( $map as $key => $answers ) {
			if ( ! is_string( $key ) || ! is_array( $answers ) || array_values( $answers ) !== $answers || count( array_filter( $answers, 'is_string' ) ) !== count( $answers ) ) {
				return 'damaged';
			}
		}

		return '';
	}

	/**
	 * Stored answers; [] for anything pack() did not write.
	 *
	 * @param mixed $data pack() output.
	 * @return array<string, list<string>>
	 */
	public static function unpack( mixed $data ): array {
		$plain = is_string( $data ) && str_starts_with( $data, self::PLAIN ) && strlen( $data ) <= self::MAX_INFLATED;
		$raw   = ! $plain && is_string( $data ) && '' !== $data && function_exists( 'gzinflate' ) ? base64_decode( $data, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- pack()'s own encoding.
		$json  = $plain ? substr( (string) $data, strlen( self::PLAIN ) ) : ( is_string( $raw ) && function_exists( 'gzinflate' ) ? @gzinflate( $raw, self::MAX_INFLATED ) : false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Damaged data is read as none.
		$map  = is_string( $json ) ? json_decode( $json, true, 3 ) : null;
		$out  = [];

		foreach ( is_array( $map ) ? $map : [] as $key => $answers ) {
			$texts = [];

			foreach ( is_array( $answers ) && array_values( $answers ) === $answers ? $answers : [ null ] as $answer ) { // Not array_is_list(): Plugin Check flags it as WordPress 6.5.
				if ( ! is_string( $answer ) ) {
					continue 2;
				}

				$texts[] = $answer;
			}

			if ( is_string( $key ) ) {
				$out[ $key ] = $texts;
			}
		}

		return $out;
	}
}
