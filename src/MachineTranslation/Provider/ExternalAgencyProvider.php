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
 * POSTs content to a configured URL and expects the translated text in
 * the immediate JSON response (`{"translation": "..."}`). The agency must
 * respond synchronously — asynchronous callback workflows are not
 * implemented in core. Addons can hook the
 * `perflocale/mt/agency_async_response` filter to short-circuit the throw
 * and supply a deferred translation (for example by matching the
 * `request_id` against a callback already received out-of-band).
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
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch(). Carried to the agency as a
	 *   'format' payload field on the text path only: the default payload
	 *   stays byte-compatible with agency endpoints that validate it
	 *   strictly, and absence of the field means HTML.
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

		// External agency API shape is site-specific; normalize to BCP 47
		// dash-form so WP-style underscore locales ('en_US') resolve to
		// standard codes the agency is most likely to accept.
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
		 * Filter the response when the agency does not return a synchronous
		 * translation. Lets an addon implement async-callback support
		 * out-of-tree by, for example, matching `$request_id` against a
		 * callback already received out-of-band and returning the cached
		 * translated text.
		 *
		 * Return a non-empty string to use as the translation. Return null
		 * (the default) to raise a RuntimeException — without an async
		 * handler the agency must respond synchronously with the
		 * translation; otherwise nothing meaningful can be persisted.
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

		// This probe only cares that the endpoint answered 2xx - it never
		// parses the body, so it has to settle the armed success itself.
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
	 * Get the agency API key from settings.
	 *
	 * Resolved transparently as env var → PHP constant → database via
	 * Settings::get() (see Settings::CONSTANT_MAP for the env/constant
	 * name binding: PERFLOCALE_AGENCY_API_KEY ↔ mt_agency_api_key).
	 *
	 * @return string
	 */
	private function get_agency_api_key(): string {
		return (string) $this->settings->get( 'mt_agency_api_key', '' );
	}
}
