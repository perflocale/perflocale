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
	 * {@inheritDoc}
	 */
	public function placeholders_as_elements(): bool {
		return true;
	}

	/**
	 * The optional API key (env var or constant PERFLOCALE_LIBRE_API_KEY, then the database).
	 *
	 * A self-hosted instance often needs none: the configured URL is what is_configured() checks.
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_libre_api_key' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'), see AbstractProvider::translate_batch().
	 *
	 * @throws \RuntimeException When the LibreTranslate API returns an unexpected response or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'libretranslate', $target_lang );

		$base_url = rtrim( (string) $this->settings->get( 'mt_libre_url' ), '/' );

		// LibreTranslate accepts only base ISO 639-1 codes (no variants).
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
	 * Translates with a pair the instance lists on /languages: an instance loads only some languages.
	 *
	 * @throws \RuntimeException When /languages or the probe translate() call fails, or no pair is listed.
	 */
	public function test_connection(): bool {
		$url      = $this->settings->get( 'mt_libre_url' );
		$response = $this->make_request(
			rtrim( is_string( $url ) ? $url : '', '/' ) . '/languages',
			[
				'method'  => 'GET',
				'timeout' => 30,
			],
			1
		);

		foreach ( $this->parse_json_response( is_string( $response['body'] ?? null ) ? $response['body'] : '' ) as $language ) {
			$source = is_array( $language ) && is_string( $language['code'] ?? null ) ? $language['code'] : '';

			foreach ( is_array( $language ) ? (array) ( $language['targets'] ?? [] ) : [] as $target ) {
				// Base codes only: translate() sends base codes.
				if ( is_string( $target ) && $target !== $source && $this->base_language_code( $source ) === $source && $this->base_language_code( $target ) === $target ) {
					$this->translate( 'test', $source, $target );

					return true;
				}
			}
		}

		throw new \RuntimeException( esc_html__( 'LibreTranslate lists no language pair to test with.', 'perflocale' ) );
	}
}
