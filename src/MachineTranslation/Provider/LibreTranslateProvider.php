<?php
/**
 * LibreTranslate provider.
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
 * Machine translation via LibreTranslate (self-hosted).
 */
final class LibreTranslateProvider extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'LibreTranslate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'libretranslate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return (string) $this->settings->get( 'mt_libre_url' ) !== '';
	}

	/**
	 * Get the (optional) API key from settings.
	 *
	 * Self-hosted LibreTranslate instances often don't require a key, so an
	 * empty result here is normal. The configured URL is the actual gating
	 * signal — see is_configured(). Resolved transparently as env var →
	 * PHP constant → database via Settings::get() (see CONSTANT_MAP:
	 * PERFLOCALE_LIBRE_API_KEY ↔ mt_libre_api_key).
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_libre_api_key' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch().
	 *
	 * @throws \RuntimeException When the LibreTranslate API returns an unexpected response or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'libretranslate', $target_lang );

		$base_url = rtrim( (string) $this->settings->get( 'mt_libre_url' ), '/' );

		// LibreTranslate accepts only base ISO 639-1 codes (no variants).
		// Strip any variant suffix and normalize underscore-locale input.
		$body = [
			'q'      => $text,
			'source' => $this->base_language_code( $source_lang ),
			'target' => $this->base_language_code( $target_lang ),
			'format' => 'text' === $format ? 'text' : 'html',
		];

		$api_key = $this->get_api_key();

		if ( $api_key !== '' ) {
			$body['api_key'] = $api_key;
		}

		$response = $this->make_request(
			$base_url . '/translate',
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			],
			3,
			$fast_fail
		);

		$data = $this->parse_json_response( $response['body'] );

		if ( ! isset( $data['translatedText'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'LibreTranslate returned an unexpected response.', 'perflocale' )
			);
		}

		$translated = $data['translatedText'];

		$this->track_usage( $text );

		return apply_filters( 'perflocale/machine_translation/result', $translated, $text, 'libretranslate' );
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
