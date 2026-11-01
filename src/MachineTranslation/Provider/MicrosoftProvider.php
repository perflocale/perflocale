<?php
/**
 * Microsoft Azure Translator provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation\Provider;

use PerfLocale\MachineTranslation\AbstractProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Machine translation via Microsoft Azure Translator API.
 */
final class MicrosoftProvider extends AbstractProvider {

	private const API_URL = 'https://api.cognitive.microsofttranslator.com/translate?api-version=3.0';

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Microsoft Translator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'microsoft';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return $this->get_api_key() !== '';
	}

	/**
	 * The API key: env var or constant PERFLOCALE_MICROSOFT_API_KEY first, then the database (Settings::get()).
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_microsoft_api_key' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'), sent as Azure's textType (plain|html).
	 *
	 * @throws \RuntimeException When the Microsoft Translator API returns an unexpected response or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'microsoft', $target_lang );

		// BCP 47 codes, variants kept ('zh-hans', 'sr-latn'); 'en_US' becomes 'en-us'.
		$url = self::API_URL
			. '&from=' . rawurlencode( $this->normalize_language_code( $source_lang ) )
			. '&to=' . rawurlencode( $this->normalize_language_code( $target_lang ) )
			. '&textType=' . ( 'text' === $format ? 'plain' : 'html' );

		$region = (string) $this->settings->get( 'mt_microsoft_region', 'global' );

		$response = $this->make_request(
			$url,
			[
				'method'  => 'POST',
				'headers' => [
					'Ocp-Apim-Subscription-Key'    => $this->get_api_key(),
					'Ocp-Apim-Subscription-Region' => $region,
					'Content-Type'                 => 'application/json',
				],
				'body'    => wp_json_encode( [ [ 'Text' => $text ] ] ),
				'timeout' => 30,
			],
			3,
			$fast_fail
		);

		$data = $this->decode_json_response( is_string( $response['body'] ) ? $response['body'] : '' );

		$translated = self::text_at( $data, 0, 'translations', 0, 'text' );

		if ( null === $translated ) {
			$this->refuse_response( esc_html__( 'Microsoft Translator returned an unexpected response.', 'perflocale' ) );
		}

		$this->accept_response();

		$this->track_usage( $text );

		return apply_filters( 'perflocale/machine_translation/result', $translated, $text, 'microsoft' );
	}

	/**
	 * Azure /translate ceilings per call: 100 elements, 50,000 characters (chunks keep character headroom).
	 */
	private const BATCH_MAX_ITEMS = 100;
	private const BATCH_MAX_CHARS = 45000;

	/**
	 * {@inheritDoc}
	 *
	 * One request per chunk, not the inherited per-text loop: a mid-batch failure there discards texts Azure billed.
	 *
	 * @param string $format Destination format hint ('html'|'text'), sent as Azure's textType (plain|html).
	 *
	 * @throws \RuntimeException When Microsoft Translator returns an unexpected batch response or all retries fail.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		return $this->without_blank( $texts, fn( array $sent ): array => $this->send_batch( $sent, $source_lang, $target_lang, $fast_fail, $format ) );
	}

	/**
	 * One request for a list of texts that are not whitespace.
	 *
	 * @param array<int, string> $texts       Texts.
	 * @param string             $source_lang Source language.
	 * @param string             $target_lang Target language.
	 * @param bool               $fast_fail   Fewer retries, shorter timeout.
	 * @param string             $format      'html' or 'text'.
	 * @return array<int, string>
	 * @throws \RuntimeException When the request or its answer fails.
	 */
	private function send_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail, string $format ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		$values = array_values( $texts );

		$filtered = [];

		foreach ( $values as $text ) {
			/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
			$filtered[] = (string) apply_filters(
				'perflocale/machine_translation/text_before_send',
				(string) $text,
				'microsoft',
				$target_lang
			);
		}

		$url = self::API_URL
			. '&from=' . rawurlencode( $this->normalize_language_code( $source_lang ) )
			. '&to=' . rawurlencode( $this->normalize_language_code( $target_lang ) )
			. '&textType=' . ( 'text' === $format ? 'plain' : 'html' );

		$region = (string) $this->settings->get( 'mt_microsoft_region', 'global' );

		// Chunks keep each text's index; an oversized text gets a chunk of its own (it fails on any path).
		$chunks      = [];
		$current     = [];
		$current_len = 0;

		foreach ( $filtered as $i => $text ) {
			$len = mb_strlen( $text );

			if ( $current !== [] && ( count( $current ) >= self::BATCH_MAX_ITEMS || ( $current_len + $len ) > self::BATCH_MAX_CHARS ) ) {
				$chunks[]    = $current;
				$current     = [];
				$current_len = 0;
			}

			$current[ $i ] = $text;
			$current_len  += $len;
		}

		if ( $current !== [] ) {
			$chunks[] = $current;
		}

		$results = [];

		foreach ( $chunks as $chunk ) {
			$body = [];

			foreach ( $chunk as $text ) {
				$body[] = [ 'Text' => $text ];
			}

			$response = $this->make_request(
				$url,
				[
					'method'  => 'POST',
					'headers' => [
						'Ocp-Apim-Subscription-Key'    => $this->get_api_key(),
						'Ocp-Apim-Subscription-Region' => $region,
						'Content-Type'                 => 'application/json',
					],
					'body'    => wp_json_encode( $body ),
					'timeout' => 60,
				],
				3,
				$fast_fail
			);

			$data = $this->decode_json_response( is_string( $response['body'] ) ? $response['body'] : '' );
			$keys = array_keys( $chunk );

			// One element per input, in order: any shape mismatch would scramble the mapping, so it fails.
			$answers = [];

			foreach ( $data as $item ) {
				$text = self::text_at( $item, 'translations', 0, 'text' );

				if ( null === $text ) {
					$this->refuse_response( esc_html__( 'Microsoft Translator returned an unexpected batch response.', 'perflocale' ) );
				}

				$answers[] = $text;
			}

			if ( count( $answers ) !== count( $keys ) ) {
				$this->refuse_response( esc_html__( 'Microsoft Translator returned an unexpected batch response.', 'perflocale' ) );
			}

			$this->accept_response();

			foreach ( $answers as $pos => $answer ) {
				$orig_i = $keys[ $pos ];

				$results[ $orig_i ] = (string) apply_filters(
					'perflocale/machine_translation/result',
					$answer,
					(string) ( $values[ $orig_i ] ?? '' ),
					'microsoft'
				);
			}

			// Usage per completed chunk: Azure charged for it even when a later chunk fails.
			$chunk_chars = 0;

			foreach ( $chunk as $text ) {
				$chunk_chars += mb_strlen( $text );
			}

			$this->track_usage_chars( $chunk_chars );
		}

		ksort( $results );

		return array_values( $results );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the probe translate() call fails (auth, network, or unexpected response).
	 */
	public function test_connection(): bool {
		try {
			$this->translate( 'test', 'en', 'es' );
			return true;
		} catch ( \RuntimeException $e ) {
			throw $e;
		}
	}
}
