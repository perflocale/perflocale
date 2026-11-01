<?php
/**
 * External agency translation provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation\Provider;

use PerfLocale\MachineTranslation\AbstractProvider;
use PerfLocale\MachineTranslation\ItemsFailedException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends translation requests to an external agency.
 *
 * One text per request (`{"translation": "..."}`, or an add-on's answer through `perflocale/mt/agency_async_response`);
 * with "My endpoint accepts batches" on, lists (`{"texts": [...]}` in, `{"translations": [...]}` out, no async filter).
 */
final class ExternalAgencyProvider extends AbstractProvider {

	/**
	 * Most texts in one batch request (filter perflocale/mt/agency_batch_max_texts).
	 */
	private const BATCH_MAX_TEXTS = 50;

	/**
	 * Most characters in one batch request (filter perflocale/mt/agency_batch_max_chars); a longer text goes alone.
	 */
	private const BATCH_MAX_CHARS = 20000;

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

		// Settled below: success with a translation (the agency's or the async filter's), a failure without one.
		$data = $this->decode_json_response( is_string( $response['body'] ) ? $response['body'] : '' );

		// If the agency responds immediately with a translation, return it.
		if ( isset( $data['translation'] ) && is_string( $data['translation'] ) ) {
			$this->accept_response();
			$this->track_usage( $text );

			/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
			return (string) apply_filters( 'perflocale/machine_translation/result', $data['translation'], $text, 'external_agency' );
		}

		/**
		 * Filter the response when the agency returns no synchronous translation.
		 *
		 * Return a non-empty string to use as the translation (for example one a callback delivered for
		 * `$request_id`); null, the default, throws a RuntimeException. Single-text requests only: not used in batch mode.
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
			$this->accept_response();
			$this->track_usage( $text );

			/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
			return (string) apply_filters( 'perflocale/machine_translation/result', $async_translation, $text, 'external_agency' );
		}

		$this->refuse_response(
			esc_html__( 'External agency response did not contain a "translation" string. Asynchronous callbacks are not supported by this provider; the agency must reply synchronously, or an addon must hook the perflocale/mt/agency_async_response filter to supply a deferred translation.', 'perflocale' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'), as in translate().
	 *
	 * @throws \RuntimeException When a request or its answer fails; then no translation is returned.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		if ( ! $this->batches_natively() ) {
			return parent::translate_batch( $texts, $source_lang, $target_lang, $fast_fail, $format );
		}

		return $this->without_blank( $texts, fn( array $sent ): array => $this->send_lists( $sent, $source_lang, $target_lang, $fast_fail, $format ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function batches_natively(): bool {
		return (bool) $this->settings->get( 'mt_agency_batch', false );
	}

	/**
	 * The texts in as few list requests as the text and character limits allow, answers in order.
	 *
	 * @param array<int, string> $texts       Texts, none blank.
	 * @param string             $source_lang Source language.
	 * @param string             $target_lang Target language.
	 * @param bool               $fast_fail   Fewer retries, shorter timeout.
	 * @param string             $format      'html' or 'text'.
	 * @return array<int, string>
	 * @throws \RuntimeException When the agency URL is not configured.
	 * @throws ItemsFailedException With that request's texts, when a request or its answer fails.
	 */
	private function send_lists( array $texts, string $source_lang, string $target_lang, bool $fast_fail, string $format ): array {
		if ( [] === $texts ) {
			return [];
		}

		$url = $this->get_agency_url();

		if ( $url === '' ) {
			throw new \RuntimeException(
				esc_html__( 'External agency URL is not configured.', 'perflocale' )
			);
		}

		$filtered = [];

		foreach ( $texts as $text ) {
			/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
			$sent       = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'external_agency', $target_lang );
			$filtered[] = is_string( $sent ) ? $sent : $text;
		}

		/**
		 * Filter the most texts the External Agency provider sends in one batch request.
		 *
		 * @hook  perflocale/mt/agency_batch_max_texts
		 * @since 1.0.7.5
		 *
		 * @param int $max_texts Default 50.
		 */
		$max_texts = max( 1, (int) apply_filters( 'perflocale/mt/agency_batch_max_texts', self::BATCH_MAX_TEXTS ) );

		/**
		 * Filter the most characters the External Agency provider sends in one batch request; a longer text goes alone.
		 *
		 * @hook  perflocale/mt/agency_batch_max_chars
		 * @since 1.0.7.5
		 *
		 * @param int $max_chars Default 20000.
		 */
		$max_chars = max( 1, (int) apply_filters( 'perflocale/mt/agency_batch_max_chars', self::BATCH_MAX_CHARS ) );

		$chunks  = [];
		$current = [];
		$chars   = 0;

		foreach ( $filtered as $i => $text ) {
			$length = mb_strlen( $text );

			if ( [] !== $current && ( count( $current ) >= $max_texts || $chars + $length > $max_chars ) ) {
				$chunks[] = $current;
				$current  = [];
				$chars    = 0;
			}

			$current[] = $i;
			$chars    += $length;
		}

		$chunks[] = $current;
		$results  = [];

		foreach ( $chunks as $positions ) {
			$list = array_map( static fn( int $i ): string => $filtered[ $i ], $positions );

			try {
				$answers = $this->send_list( $url, $list, $source_lang, $target_lang, $fast_fail, $format );
			} catch ( \RuntimeException $e ) {
				throw ItemsFailedException::of( $e, $positions[0], $positions[ count( $positions ) - 1 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped where it was thrown.
			}

			foreach ( $positions as $k => $i ) {
				/** This filter is documented in src/MachineTranslation/Provider/DeepLProvider.php */
				$result    = apply_filters( 'perflocale/machine_translation/result', $answers[ $k ], $filtered[ $i ], 'external_agency' );
				$results[] = is_string( $result ) ? $result : $answers[ $k ];
			}
		}

		return $results;
	}

	/**
	 * One batch request: {"texts": [...]} in, {"translations": [...]} out, one string per text in the same order.
	 *
	 * @param string             $url         Agency URL.
	 * @param array<int, string> $texts       Texts.
	 * @param string             $source_lang Source language.
	 * @param string             $target_lang Target language.
	 * @param bool               $fast_fail   Fewer retries, shorter timeout.
	 * @param string             $format      'html' or 'text'.
	 * @return array<int, string>
	 * @throws \RuntimeException When the request fails or the answer breaks the contract.
	 */
	private function send_list( string $url, array $texts, string $source_lang, string $target_lang, bool $fast_fail, string $format ): array {
		$payload = [
			'texts'       => $texts,
			'source_lang' => $this->normalize_language_code( $source_lang ),
			'target_lang' => $this->normalize_language_code( $target_lang ),
			'request_id'  => wp_generate_uuid4(),
		];

		if ( 'text' === $format ) {
			$payload['format'] = 'text';
		}

		$headers = [
			'Content-Type' => 'application/json',
		];
		$api_key = $this->get_agency_api_key();

		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = $this->make_request(
			$url,
			[
				'method'  => 'POST',
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => 60,
			],
			3,
			$fast_fail
		);

		// Success is settled only once the list is usable: an unusable 2xx answer counts as a breaker failure.
		$data         = $this->decode_json_response( is_string( $response['body'] ) ? $response['body'] : '' );
		$translations = $data['translations'] ?? null;

		// Not array_is_list(): Plugin Check flags it as WordPress 6.5.
		if ( ! is_array( $translations ) || array_values( $translations ) !== $translations ) {
			$this->reject_response( 'malformed' );

			throw new \RuntimeException(
				esc_html__( 'External agency batch response has no "translations" list. Turn off "My endpoint accepts batches" if the endpoint takes one text per request.', 'perflocale' )
			);
		}

		// Mapped by position: a different count would put translations on the wrong texts.
		if ( count( $translations ) !== count( $texts ) ) {
			$this->reject_response( 'malformed' );

			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: number of translations returned, 2: number of texts sent */
						__( 'External agency returned %1$d translations for %2$d texts; nothing was saved. The endpoint must answer one translation per text, in the same order.', 'perflocale' ),
						count( $translations ),
						count( $texts )
					)
				)
			);
		}

		$out   = [];
		$chars = 0;

		foreach ( $translations as $k => $translation ) {
			if ( ! is_string( $translation ) ) {
				$this->reject_response( 'malformed' );

				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: 1: position of the translation, 2: number of translations */
							__( 'External agency translation %1$d of %2$d is not a string; nothing was saved. The endpoint must answer a string for each text.', 'perflocale' ),
							$k + 1,
							count( $translations )
						)
					)
				);
			}

			$out[]  = $translation;
			$chars += mb_strlen( $texts[ $k ] );
		}

		$this->accept_response();
		$this->track_usage_chars( $chars );

		return $out;
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
