<?php
/**
 * Machine translation provider that accepts context.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional extension of {@see ProviderInterface} for providers that take context with a text: plain text about its
 * surroundings that guides the translation and is never translated or returned. Implementers must pass every context
 * string through `perflocale/machine_translation/text_before_send` as they pass texts, so redaction applies to it.
 *
 * @since 1.0.8
 */
interface ContextAwareProviderInterface extends ProviderInterface {

	/**
	 * Translate texts, each with its context.
	 *
	 * @param array<int, string> $texts       Texts to translate.
	 * @param array<int, string> $contexts    Context per text, parallel to `$texts`; '' for none.
	 * @param string             $source_lang Source language code.
	 * @param string             $target_lang Target language code.
	 * @param bool               $fast_fail   Cap retries and timeout.
	 * @param string             $format      'html' or 'text'.
	 * @return array<int, string> Translated texts, in the order of `$texts`.
	 *
	 * @throws \RuntimeException If the translation fails.
	 */
	public function translate_batch_with_context( array $texts, array $contexts, string $source_lang, string $target_lang, bool $fast_fail = false, string $format = 'html' ): array;

	/**
	 * Whether the provider bills the context characters, so the monthly character limit counts them.
	 *
	 * @return bool
	 */
	public function context_billed(): bool;
}
