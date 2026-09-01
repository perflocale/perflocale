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
 *
 * Supports formality control, HTML preservation, and batch translation.
 * Uses wp_remote_post() for all API calls.
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
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch(). The text path omits
	 *   tag_handling so DeepL treats the payload as raw text and returns
	 *   unescaped output.
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

		// DeepL's v2 tag-handling parser requires every input string to have
		// a parent tag (rejected with: "Tag handling parsing failed … 'text
		// without parent'") for plain-text inputs like post titles. Wrap
		// each text in a neutral root element before send and strip it back
		// off the response so plain-text and HTML payloads coexist. The wrap
		// exists solely for the tag-handling parser, so the text path (no
		// tag_handling) skips it.
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
	 * @param string $format Destination format hint ('html'|'text') — see
	 *   AbstractProvider::translate_batch().
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

		// See note in translate(): wrap each text in a neutral parent tag so
		// DeepL's v2 tag-handling parser doesn't reject plain-text inputs.
		// The wrap exists solely for that parser — the text path (no
		// tag_handling) sends the payload untouched.
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

		$results   = [];
		$originals = array_values( $texts );

		foreach ( $data['translations'] as $i => $translation ) {
			$translated = $is_html
				? self::unwrap_from_tag_handling( $translation['text'] ?? '' )
				: (string) ( $translation['text'] ?? '' );
			// The result filter receives the RAW pre-filter source, matching
			// translate()'s documented contract.
			$results[] = (string) apply_filters(
				'perflocale/machine_translation/result',
				$translated,
				(string) ( $originals[ $i ] ?? '' ),
				'deepl'
			);
		}

		// Track usage once for the whole batch, counted on the filtered
		// texts — what was actually sent.
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
	 * Get the API key from settings.
	 *
	 * Resolved transparently as env var → PHP constant → database via
	 * Settings::get() (see Settings::CONSTANT_MAP for the env/constant
	 * name binding: PERFLOCALE_DEEPL_API_KEY ↔ mt_deepl_api_key).
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return (string) $this->settings->get( 'mt_deepl_api_key' );
	}

	/**
	 * Resolve the configured formality into a DeepL `formality` parameter.
	 *
	 * DeepL supports formality for a subset of target languages and rejects
	 * the whole request with HTTP 400 when it is sent for any other target.
	 * The documented way to express "use it where available" is the
	 * `prefer_` prefix, which DeepL ignores instead of erroring — so a
	 * site-wide setting can't hard-fail translations into, say, English.
	 * Hard-coding the supported-language list here would drift as DeepL
	 * adds languages.
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
	 * DeepL has asymmetric source/target requirements:
	 * • source_lang accepts base codes only (EN, PT, ZH - not EN-US, PT-BR)
	 * • target_lang requires a variant for some languages (EN-US/EN-GB, PT-BR/PT-PT)
	 *
	 * Accepts any input shape: 'en', 'EN', 'en_US', 'en-us', 'EN-US'.
	 *
	 * @param string $code Language code (base, locale-underscore, or dash-variant).
	 * @param bool   $is_target true for target_lang (keep / default variant);
	 *   false for source_lang (strip variant).
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
	 * Sentinel tag used to wrap each input so DeepL's v2 tag-handling
	 * parser is happy. The element name is intentionally unusual to avoid
	 * collisions with content the user might already have authored
	 * (e.g. a literal `<x>` in their post would survive the round-trip,
	 * but `<perflocale-mt-root>` is far less likely to appear).
	 *
	 * NOTE: This wrap is DeepL-specific. Google (`format=html`),
	 * Microsoft (`textType=html`), and LibreTranslate (`format=html`)
	 * accept plain-text inputs without a parent element — only DeepL's
	 * v2 parser has the "text without parent" restriction. Adding the
	 * same wrap to those providers would add latency for no benefit and
	 * risk leakage if their decoders ever escape the tag literally.
	 */
	private const WRAP_TAG = 'perflocale-mt-root';

	/**
	 * Wrap a text payload in a neutral parent element before sending to
	 * DeepL with `tag_handling=html`. v2 of DeepL's parser rejects inputs
	 * that don't have a parent tag (plain-text post titles, single-word
	 * excerpts, etc.) with `'text without parent'`. Wrapping makes every
	 * input — plain-text or HTML — have one consistent root.
	 *
	 * @param string $text Source text.
	 * @return string Wrapped text.
	 */
	private static function wrap_for_tag_handling( string $text ): string {
		return '<' . self::WRAP_TAG . '>' . $text . '</' . self::WRAP_TAG . '>';
	}

	/**
	 * Strip the wrapping tag added by {@see wrap_for_tag_handling()} from
	 * a translation response. Defensive: only strips when both the open
	 * and close tags are present at the expected positions, so a response
	 * that didn't get wrapped (older request, future API change) passes
	 * through untouched.
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
