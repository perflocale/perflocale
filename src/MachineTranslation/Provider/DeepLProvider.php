<?php
/**
 * DeepL translation provider.
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
 * Machine translation via the DeepL API.
 */
final class DeepLProvider extends AbstractProvider {

	/**
	 * DeepL API base URL.
	 */
	private const API_URL_PRO  = 'https://api.deepl.com/v2';
	private const API_URL_FREE = 'https://api-free.deepl.com/v2';

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'DeepL';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'deepl';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return $this->get_api_key() !== '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'); 'text' omits tag_handling, so the answer is unescaped.
	 *
	 * @throws \RuntimeException When DeepL returns an unexpected response format or all retries fail.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): string {
		/**
		 * Filter text before sending to machine translation.
		 *
		 * @param string $text Text to translate.
		 * @param string $provider Provider ID.
		 * @param string $lang Target language.
		 */
		$text = apply_filters( 'perflocale/machine_translation/text_before_send', $text, 'deepl', $target_lang );

		$is_html = 'text' !== $format;

		// DeepL's tag-handling parser rejects text without a parent tag (a plain title): wrap it, unwrap the answer.
		// The text path sends no tag_handling, so it needs no wrap.
		$body = [
			'text'        => [ $is_html ? self::wrap_for_tag_handling( $text ) : $text ],
			'source_lang' => $this->map_language_code( $source_lang, false ),
			'target_lang' => $this->map_language_code( $target_lang, true ),
		];

		if ( $is_html ) {
			$body['tag_handling'] = 'html';
		}

		$formality = $this->formality_param();

		if ( $formality !== '' ) {
			$body['formality'] = $formality;
		}

		$response = $this->make_request(
			$this->get_api_url() . '/translate',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'DeepL-Auth-Key ' . $this->get_api_key(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			],
			3,
			$fast_fail
		);

		$data = $this->parse_json_response( $response['body'] );

		if ( ! isset( $data['translations'][0]['text'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'DeepL returned an unexpected response format.', 'perflocale' )
			);
		}

		$translated = $is_html
			? self::unwrap_from_tag_handling( $data['translations'][0]['text'] )
			: (string) $data['translations'][0]['text'];

		$this->track_usage( $text );

		/**
		 * Filter machine translation result.
		 *
		 * @param string $translated Translated text.
		 * @param string $text Original text.
		 * @param string $provider Provider ID.
		 */
		return apply_filters( 'perflocale/machine_translation/result', $translated, $text, 'deepl' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $format Destination format hint ('html'|'text'), as in translate().
	 *
	 * @throws \RuntimeException When DeepL batch returns an unexpected response or all retries fail.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		$filtered = [];

		foreach ( array_values( $texts ) as $text ) {
			/** This filter is documented in DeepLProvider::translate() above. */
			$filtered[] = (string) apply_filters(
				'perflocale/machine_translation/text_before_send',
				(string) $text,
				'deepl',
				$target_lang
			);
		}

		$is_html = 'text' !== $format;

		// The parent-tag wrap of translate(), only for the tag-handling path.
		$request_texts = $is_html
			? array_map( [ self::class, 'wrap_for_tag_handling' ], $filtered )
			: $filtered;

		$body = [
			'text'        => $request_texts,
			'source_lang' => $this->map_language_code( $source_lang, false ),
			'target_lang' => $this->map_language_code( $target_lang, true ),
		];

		if ( $is_html ) {
			$body['tag_handling'] = 'html';
		}

		$formality = $this->formality_param();

		if ( $formality !== '' ) {
			$body['formality'] = $formality;
		}

		$response = $this->make_request(
			$this->get_api_url() . '/translate',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'DeepL-Auth-Key ' . $this->get_api_key(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			],
			3,
			$fast_fail
		);

		$data = $this->parse_json_response( $response['body'] );

		if ( ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'DeepL batch translation returned an unexpected response.', 'perflocale' )
			);
		}

		// One translation per text, in order: mapped by index, a count mismatch shifts every later translation.
		// Checked here too because a third party may call this public class directly.
		if ( count( $data['translations'] ) !== count( $filtered ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: number of translations returned, 2: number of texts sent */
						__( 'DeepL returned %1$d translations for %2$d texts; refusing to map them by position.', 'perflocale' ),
						count( $data['translations'] ),
						count( $filtered )
					)
				)
			);
		}

		$results   = [];
		$originals = array_values( $texts );

		foreach ( $data['translations'] as $i => $translation ) {
			$translated = $is_html
				? self::unwrap_from_tag_handling( $translation['text'] ?? '' )
				: (string) ( $translation['text'] ?? '' );
			// The result filter receives the raw pre-filter source, as in translate().
			$results[] = (string) apply_filters(
				'perflocale/machine_translation/result',
				$translated,
				(string) ( $originals[ $i ] ?? '' ),
				'deepl'
			);
		}

		// Usage counts the filtered texts: what was sent.
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
	 * @throws \RuntimeException When the /usage probe fails (auth, network, or invalid response).
	 */
	public function test_connection(): bool {
		try {
			$response = $this->make_request(
				$this->get_api_url() . '/usage',
				[
					'method'  => 'GET',
					'headers' => [
						'Authorization' => 'DeepL-Auth-Key ' . $this->get_api_key(),
					],
					'timeout' => 10,
				],
				1
			);

			$data = $this->parse_json_response( $response['body'] );

			return isset( $data['character_count'] );
		} catch ( \RuntimeException $e ) {
			throw $e;
		}
	}

	/**
	 * The API key: env var or constant PERFLOCALE_DEEPL_API_KEY first, then the database (Settings::get()).
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_deepl_api_key' );
	}

	/**
	 * Resolve the configured formality into a DeepL `formality` parameter.
	 *
	 * Always `prefer_`: plain formality makes DeepL refuse a target without it (HTTP 400), and a list of targets would drift.
	 *
	 * @return string DeepL formality value, or '' when unset/default.
	 */
	private function formality_param(): string {
		$formality = (string) $this->settings->get( 'mt_deepl_formality', 'default' );

		if ( $formality === '' || $formality === 'default' ) {
			return '';
		}

		// Already a prefer_ value (filtered in by a site): pass through.
		if ( str_starts_with( $formality, 'prefer_' ) ) {
			return $formality;
		}

		return in_array( $formality, [ 'more', 'less' ], true ) ? 'prefer_' . $formality : '';
	}

	/**
	 * Get the API URL based on whether the key is a free or pro key.
	 *
	 * DeepL free API keys end with ':fx'.
	 *
	 * @return string
	 */
	private function get_api_url(): string {
		$key = $this->get_api_key();

		if ( str_ends_with( $key, ':fx' ) ) {
			return self::API_URL_FREE;
		}

		return self::API_URL_PRO;
	}

	/**
	 * Normalize a language code to DeepL's expected format.
	 *
	 * Source codes are base only (EN, not EN-US); a target needs a variant for EN and PT (EN-US, PT-PT by default).
	 *
	 * @param string $code Language code (base, locale-underscore, or dash-variant).
	 * @param bool   $is_target true for target_lang (keep or default the variant), false for source_lang (strip it).
	 * @return string DeepL-compatible language code.
	 */
	private function map_language_code( string $code, bool $is_target = true ): string {
		// Normalize: lowercase + underscore-to-dash (accepts en_US, en-us, EN-US).
		$normalized = strtolower( str_replace( '_', '-', $code ) );

		// Target-side variant defaults for languages DeepL requires a variant on.
		$target_defaults = [
			'en' => 'EN-US', // EN requires variant (EN-US or EN-GB) as target.
			'pt' => 'PT-PT', // PT requires variant (PT-PT or PT-BR) as target.
		];

		// DeepL only allows / requires variants on specific target languages.
		// All other target codes must be base (DE not DE-DE, FR not FR-FR, etc.).
		$variant_ok_for_target = [
			'en' => true,
			'pt' => true,
		];

		$base = strpos( $normalized, '-' ) !== false
			? explode( '-', $normalized, 2 )[0]
			: $normalized;

		if ( $is_target ) {
			// Base code has no variant → return uppercase base.
			if ( ! isset( $variant_ok_for_target[ $base ] ) ) {
				return strtoupper( $base );
			}

			// Variant allowed. If caller passed one explicitly, keep it.
			if ( strpos( $normalized, '-' ) !== false ) {
				return strtoupper( $normalized );
			}

			// Caller passed base code for a variant-required language → apply default.
			return $target_defaults[ $base ];
		}

		// Source side: DeepL accepts only base codes. Strip variant suffix if any.
		return strtoupper( $base );
	}

	/**
	 * Parent element DeepL's tag-handling parser needs; an unusual name, so it does not collide with authored markup.
	 *
	 * DeepL only: the other providers accept text without a parent, and a decoder that escapes the tag would leak it.
	 */
	private const WRAP_TAG = 'perflocale-mt-root';

	/**
	 * Wrap a text in the parent element DeepL's `tag_handling=html` parser requires ("text without parent").
	 *
	 * @param string $text Source text.
	 * @return string Wrapped text.
	 */
	private static function wrap_for_tag_handling( string $text ): string {
		return '<' . self::WRAP_TAG . '>' . $text . '</' . self::WRAP_TAG . '>';
	}

	/**
	 * Strip the wrap from a translation, only when both tags are at the ends: an unwrapped answer passes untouched.
	 *
	 * @param string $text Translated text from DeepL.
	 * @return string Unwrapped text.
	 */
	private static function unwrap_from_tag_handling( string $text ): string {
		$open  = '<' . self::WRAP_TAG . '>';
		$close = '</' . self::WRAP_TAG . '>';

		if ( str_starts_with( $text, $open ) && str_ends_with( $text, $close ) ) {
			return substr( $text, strlen( $open ), -strlen( $close ) );
		}

		return $text;
	}
}
