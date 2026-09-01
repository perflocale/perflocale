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
	 * Get the API key from settings.
	 *
	 * Resolved transparently as env var → PHP constant → database via
	 * Settings::get() (see Settings::CONSTANT_MAP for the env/constant
	 * name binding: PERFLOCALE_MICROSOFT_API_KEY ↔ mt_microsoft_api_key).
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_microsoft_api_key' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch(). Maps to Azure's textType
	 *   (plain|html).
	 *
	 * @throws \RuntimeException When the Microsoft Translator API returns an unexpected response or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'microsoft', $target_lang );

		// Microsoft Translator v3 expects BCP 47 codes. Accepts base forms
		// ('en', 'de') and specific variants where meaningful ('zh-Hans',
		// 'pt-pt', 'sr-Latn'). Normalize underscore-locale input so
		// WP-style codes ('en_US', 'de_DE') resolve to valid BCP 47 form.
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

		$data = $this->parse_json_response( $response['body'] );

		if ( ! isset( $data[0]['translations'][0]['text'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'Microsoft Translator returned an unexpected response.', 'perflocale' )
			);
		}

		$translated = $data[0]['translations'][0]['text'];

		$this->track_usage( $text );

		return apply_filters( 'perflocale/machine_translation/result', $translated, $text, 'microsoft' );
	}

	/**
	 * Azure /translate request ceilings: at most 100 array elements and
	 * 50,000 total characters per call. Chunks are built with character
	 * headroom so a batch near the ceiling isn't rejected outright.
	 */
	private const BATCH_MAX_ITEMS = 100;
	private const BATCH_MAX_CHARS = 45000;

	/**
	 * {@inheritDoc}
	 *
	 * Azure Translator v3 accepts an array of texts natively, so a whole
	 * chunk travels in ONE request instead of the inherited per-text loop.
	 * Besides latency, one request per chunk is atomic on the billing side:
	 * with the loop, a mid-batch failure discards translations Azure has
	 * already billed, and the caller's re-run bills them a second time.
	 *
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch(). Maps to Azure's textType
	 *   (plain|html).
	 *
	 * @throws \RuntimeException When Microsoft Translator returns an unexpected batch response or all retries fail.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
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

		// Split into API-compliant chunks, preserving each text's index so
		// the results can be reassembled in the caller's order. An oversized
		// single text gets a chunk of its own — it would exceed the ceiling
		// on the per-text path too, so the failure mode is unchanged.
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

			$data = $this->parse_json_response( $response['body'] );
			$keys = array_keys( $chunk );

			// Azure returns one element per input, in order. A short / long /
			// malformed response would scramble the positional mapping, so
			// treat any shape mismatch as a hard failure.
			if ( count( $data ) !== count( $keys ) ) {
				throw new \RuntimeException(
					esc_html__( 'Microsoft Translator returned an unexpected batch response.', 'perflocale' )
				);
			}

			$pos = 0;

			foreach ( $data as $item ) {
				if ( ! isset( $item['translations'][0]['text'] ) || ! is_string( $item['translations'][0]['text'] ) ) {
					throw new \RuntimeException(
						esc_html__( 'Microsoft Translator returned an unexpected batch response.', 'perflocale' )
					);
				}

				$orig_i = $keys[ $pos ];
				++$pos;

				$results[ $orig_i ] = (string) apply_filters(
					'perflocale/machine_translation/result',
					$item['translations'][0]['text'],
					(string) ( $values[ $orig_i ] ?? '' ),
					'microsoft'
				);
			}

			// Bill per completed chunk: Azure has charged for it even if a
			// LATER chunk fails, so the usage counter must not lose it.
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
