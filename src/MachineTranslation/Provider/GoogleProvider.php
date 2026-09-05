<?php
/**
 * Google Cloud Translation provider.
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
 * Machine translation via Google Cloud Translation API v2.
 */
final class GoogleProvider extends AbstractProvider {

	private const API_URL = 'https://translation.googleapis.com/language/translate/v2';

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Google Translate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'google';
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
	 * name binding: PERFLOCALE_GOOGLE_API_KEY ↔ mt_google_api_key).
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_google_api_key' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch(). Google's own docs recommend
	 *   format=text for plain text: format=html returns entity-escaped
	 *   output (&#39;, &amp;).
	 *
	 * @throws \RuntimeException When Google Translate returns an unexpected response or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'google', $target_lang );

		$response = $this->make_request(
			self::API_URL,
			[
				'method'  => 'POST',
				// The key travels as a header, never in the JSON body: Google
				// reads its system parameters only from the query string or a
				// header, so a body `key` arrives as an unauthenticated call
				// and every request fails 403 "unregistered callers". A header
				// is preferred over ?key= because query strings land in proxy
				// and access logs.
				'headers' => [
					'Content-Type'   => 'application/json',
					'X-goog-api-key' => $this->get_api_key(),
				],
				'body'    => wp_json_encode(
					[
						'q'      => $text,
						// Google Translate v2 accepts both base ('en') and variant
						// ('zh-CN') forms. Normalize underscore-locale input.
						'source' => $this->base_language_code( $source_lang ),
						'target' => $this->normalize_language_code( $target_lang ),
						'format' => 'text' === $format ? 'text' : 'html',
					]
				),
				'timeout' => 30,
			],
			3,
			$fast_fail
		);

		$data = $this->parse_json_response( $response['body'] );

		if ( ! isset( $data['data']['translations'][0]['translatedText'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'Google Translate returned an unexpected response.', 'perflocale' )
			);
		}

		$translated = $data['data']['translations'][0]['translatedText'];

		$this->track_usage( $text );

		return apply_filters( 'perflocale/machine_translation/result', $translated, $text, 'google' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Google Translate v2 accepts `q` as either a single string or an
	 * array - a single HTTP round-trip handles the whole batch. Billing
	 * is per-character regardless, so batching is pure latency + cost-
	 * overhead reduction (no per-call TCP/TLS handshake on each string).
	 *
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch().
	 *
	 * @throws \RuntimeException When Google Translate batch returns an unexpected response or all retries fail.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		$values = array_values( $texts );

		$filtered = [];

		foreach ( $values as $text ) {
			$filtered[] = (string) apply_filters(
				'perflocale/machine_translation/text_before_send',
				(string) $text,
				'google',
				$target_lang
			);
		}

		$response = $this->make_request(
			self::API_URL,
			[
				'method'  => 'POST',
				// See translate(): the key is a header, never a body field.
				'headers' => [
					'Content-Type'   => 'application/json',
					'X-goog-api-key' => $this->get_api_key(),
				],
				'body'    => wp_json_encode(
					[
						'q'      => $filtered,
						'source' => $this->base_language_code( $source_lang ),
						'target' => $this->normalize_language_code( $target_lang ),
						'format' => 'text' === $format ? 'text' : 'html',
					]
				),
				'timeout' => 60,
			],
			3,
			$fast_fail
		);

		$data = $this->parse_json_response( $response['body'] );

		if ( ! isset( $data['data']['translations'] ) || ! is_array( $data['data']['translations'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'Google Translate returned an unexpected batch response.', 'perflocale' )
			);
		}

		// Cardinality contract: one translation per text sent, in order. The
		// batch is then mapped BY INDEX onto the caller's strings, so a short
		// or over-long reply does not degrade gracefully - it silently shifts
		// every later translation onto the wrong source. Core's
		// TranslationService already refuses a mismatched batch, but this
		// provider is a public class a third party can call directly, and the
		// check that protects them costs one integer comparison per batch.
		if ( count( $data['data']['translations'] ) !== count( $filtered ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: number of translations returned, 2: number of texts sent */
						__( 'Google Translate returned %1$d translations for %2$d texts; refusing to map them by position.', 'perflocale' ),
						count( $data['data']['translations'] ),
						count( $filtered )
					)
				)
			);
		}

		$results = [];

		foreach ( $data['data']['translations'] as $i => $translation ) {
			$text = (string) ( $translation['translatedText'] ?? '' );
			// Pass the original source text (by index) to the result filter,
			// matching translate()'s signature + the documented hook contract.
			// Google returns translations in request order, so $values[$i] is
			// the corresponding source string.
			$original  = (string) ( $values[ $i ] ?? '' );
			$results[] = (string) apply_filters( 'perflocale/machine_translation/result', $text, $original, 'google' );
		}

		// Track usage once for the whole batch - matches per-char billing
		// but avoids N update_option() calls inside a tight loop.
		$total_chars = 0;
		foreach ( $filtered as $text ) {
			$total_chars += mb_strlen( (string) $text );
		}
		$this->track_usage_chars( $total_chars );

		return $results;
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
