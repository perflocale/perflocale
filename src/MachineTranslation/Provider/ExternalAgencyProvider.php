<?php
/**
 * External agency translation provider.
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
 * Sends translation requests to an external agency.
 *
 * The agency answers synchronously (`{"translation": "..."}`); an add-on supplies an asynchronous
 * one through `perflocale/mt/agency_async_response`.
 */
final class ExternalAgencyProvider extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'external_agency';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'External Agency';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		$url = (string) $this->settings->get( 'mt_agency_url' );

		return $url !== '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'). Sent as 'format' for text only: the HTML
	 *   payload stays what strict agency endpoints validate.
	 *
	 * @throws \RuntimeException When the agency URL is unconfigured, the response lacks a translation, or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		$url     = $this->get_agency_url();
		$api_key = $this->get_agency_api_key();

		if ( $url === '' ) {
			throw new \RuntimeException(
				esc_html__( 'External agency URL is not configured.', 'perflocale' )
			);
		}

		/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
		$text = (string) apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'external_agency', $target_lang );

		// Dash form ('en-us', not 'en_US'): the BCP 47 shape an agency most likely accepts.
		$request_id = wp_generate_uuid4();
		$payload    = [
			'text'        => $text,
			'source_lang' => $this->normalize_language_code( $source_lang ),
			'target_lang' => $this->normalize_language_code( $target_lang ),
			'request_id'  => $request_id,
		];

		if ( 'text' === $format ) {
			$payload['format'] = 'text';
		}

		$headers = [
			'Content-Type' => 'application/json',
		];

		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = $this->make_request(
			$url,
			[
				'method'  => 'POST',
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			],
			3,
			$fast_fail
		);

		$data = $this->parse_json_response( $response['body'] );

		// If the agency responds immediately with a translation, return it.
		if ( isset( $data['translation'] ) && is_string( $data['translation'] ) ) {
			$this->track_usage( $text );

			/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
			return (string) apply_filters( 'perflocale/machine_translation/result', $data['translation'], $text, 'external_agency' );
		}

		/**
		 * Filter the response when the agency returns no synchronous translation.
		 *
		 * Return a non-empty string to use as the translation (for example one a callback delivered for
		 * `$request_id`); null, the default, throws a RuntimeException.
		 *
		 * @hook perflocale/mt/agency_async_response
		 * @param string|null $translation Default null (= throw).
		 * @param array       $response    Decoded agency response body.
		 * @param string      $request_id  UUIDv4 generated for this request.
		 * @param array       $payload     Outbound payload sent to the agency.
		 * @param array       $context     Source/target lang + provider id.
		 */
		$async_translation = apply_filters(
			'perflocale/mt/agency_async_response',
			null,
			$data,
			$request_id,
			$payload,
			[
				'source_lang' => $source_lang,
				'target_lang' => $target_lang,
				'provider_id' => $this->get_id(),
			]
		);

		if ( is_string( $async_translation ) && $async_translation !== '' ) {
			$this->track_usage( $text );

			/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
			return (string) apply_filters( 'perflocale/machine_translation/result', $async_translation, $text, 'external_agency' );
		}

		throw new \RuntimeException(
			esc_html__( 'External agency response did not contain a "translation" string. Asynchronous callbacks are not supported by this provider; the agency must reply synchronously, or an addon must hook the perflocale/mt/agency_async_response filter to supply a deferred translation.', 'perflocale' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When the agency URL is unconfigured or the probe call fails.
	 */
	public function test_connection(): bool {
		$url     = $this->get_agency_url();
		$api_key = $this->get_agency_api_key();

		if ( $url === '' ) {
			throw new \RuntimeException(
				esc_html__( 'External agency URL is not configured.', 'perflocale' )
			);
		}

		$headers = [
			'Content-Type' => 'application/json',
		];

		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = $this->make_request(
			$url,
			[
				'method'  => 'GET',
				'headers' => $headers,
				'timeout' => 10,
			],
			1
		);

		// The probe never parses the body, so it settles the armed success itself.
		$this->accept_response();

		return true;
	}

	/**
	 * Get the agency webhook URL from settings.
	 *
	 * @return string
	 */
	private function get_agency_url(): string {
		return (string) $this->settings->get( 'mt_agency_url', '' );
	}

	/**
	 * The agency API key: env var or constant PERFLOCALE_AGENCY_API_KEY first, then the database (Settings::get()).
	 *
	 * @return string
	 */
	private function get_agency_api_key(): string {
		return (string) $this->settings->get( 'mt_agency_api_key', '' );
	}
}
