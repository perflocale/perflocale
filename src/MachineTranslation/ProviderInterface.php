<?php
/**
 * Machine translation provider interface.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for machine translation providers.
 *
 * All providers must implement this interface to be usable
 * by the PerfLocale machine translation system.
 */
interface ProviderInterface {

	/**
	 * Translate a single text string.
	 *
	 * @param string $text        Text to translate.
	 * @param string $source_lang Source language code (ISO 639-1).
	 * @param string $target_lang Target language code (ISO 639-1).
	 * @param bool   $fast_fail   Cap retries/timeout for synchronous contexts.
	 * @return string Translated text.
	 *
	 * @throws \RuntimeException If the translation fails.
	 */
	public function translate( string $text, string $source_lang, string $target_lang, bool $fast_fail = false ): string;

	/**
	 * Translate multiple texts in a single API call.
	 *
	 * @param array<int, string> $texts       Texts to translate.
	 * @param string             $source_lang Source language code.
	 * @param string             $target_lang Target language code.
	 * @param bool               $fast_fail   Cap retries/timeout for synchronous contexts.
	 * @return array<int, string> Translated texts (same order as input).
	 *
	 * @throws \RuntimeException If the translation fails.
	 */
	public function translate_batch( array $texts, string $source_lang, string $target_lang, bool $fast_fail = false ): array;

	/**
	 * Get the provider's display name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Get the provider's unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Check if the provider is properly configured (API key set, etc.).
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Test the connection to the translation API.
	 *
	 * @return bool True if the connection is successful.
	 *
	 * @throws \RuntimeException With details if the test fails.
	 */
	public function test_connection(): bool;

	/**
	 * Get the number of characters used in the current billing period.
	 *
	 * @return int Character count.
	 */
	public function get_usage(): int;
}
