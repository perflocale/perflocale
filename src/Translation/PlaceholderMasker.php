<?php
/**
 * Placeholder masking for machine-translation safety.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces variable-interpolation tokens and inline HTML with opaque
 * sentinels before MT, restoring them verbatim after.
 *
 * MT providers routinely garble `%s`, `%1$d`, `{name}`, `<a>...</a>` —
 * "Pay %s now" can come back as "Pague ahora %s" (reordered, sometimes
 * fine) or "Pague %S ahora" (mangled, never fine). Same with HTML.
 *
 * The mask format `[[PFL_PH_N]]` is chosen because:
 *   - Brackets survive every MT provider we test (DeepL, Google,
 *     Microsoft, LibreTranslate) without modification.
 *   - The PFL_PH prefix is unique enough that we can be confident no
 *     source text contains it accidentally.
 *   - Numeric N keeps restoration order deterministic.
 */
final class PlaceholderMasker {

	/**
	 * Mask token format. {0} is the index.
	 */
	private const TOKEN_FORMAT = '[[PFL_PH_%d]]';

	/**
	 * SEO template variables, double-percent (Yoast/SEOPress style):
	 * `%%sitename%%`, `%%sep%%`, `%%title%%`. MUST be masked before
	 * PRINTF_RE — its `%%` (escaped percent) rule would otherwise mask only
	 * the DELIMITERS and leave the variable name exposed for the provider
	 * to translate (observed live: DeepL turned %%sitename%% into
	 * %%Webseitenname%%, which the SEO plugin can no longer resolve).
	 */
	private const SEO_VAR_DOUBLE_RE = '/%%[a-zA-Z][a-zA-Z0-9_-]*%%/';

	/**
	 * SEO template variables, single-percent (Rank Math style): `%title%`,
	 * `%sep%`, `%sitename%`. Requires a leading letter and no spaces, so
	 * prose like "50% and 20%" can never match. Runs after the double-
	 * percent rule so it can't split a `%%var%%` token.
	 */
	private const SEO_VAR_SINGLE_RE = '/%[a-zA-Z][a-zA-Z0-9_-]*%/';

	/**
	 * printf-style placeholders: `%s`, `%d`, `%1$s`, `%2$d`, `%%`, etc.
	 *
	 * Matches `%[1$]?[sdfgxXcouebE%]` — covers the common formats WP
	 * gettext strings use without overmatching English text containing
	 * literal `%`.
	 */
	private const PRINTF_RE = '/%(?:\d+\$)?[sdfgxXcouebE%]/';

	/**
	 * Brace-style placeholders: `{name}`, `{0}`, `{{var}}`. Matches the
	 * outer delimiters; the inner content stays inside the masked block
	 * so MT can't reorder partial brace pairs.
	 */
	private const BRACE_RE = '/\{\{?[a-zA-Z0-9_.-]+\}?\}/';

	/**
	 * Inline HTML tags: `<a href="...">`, `</strong>`, `<br />`, etc.
	 * Greedy-non-tag content match so we don't accidentally consume
	 * text between tags. The masker treats opening + closing as
	 * independent placeholders so the MT engine can move text between
	 * them.
	 */
	private const HTML_RE = '/<\/?[a-zA-Z][a-zA-Z0-9]*\b(?:\s+[a-zA-Z-]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?)*\s*\/?>/';

	/**
	 * Replace every placeholder in $text with a unique sentinel.
	 *
	 * @param string $text Source text containing zero or more placeholders.
	 * @return array{0: string, 1: array<int, string>} Tuple: [masked text, ordered placeholder list].
	 *         The placeholder list is parallel-indexed to the sentinels in the masked text:
	 *         token `[[PFL_PH_0]]` restores to $list[0], `[[PFL_PH_1]]` to $list[1], etc.
	 */
	public static function mask( string $text ): array {
		$placeholders = [];
		$counter      = 0;

		$callback = static function ( $match ) use ( &$placeholders, &$counter ) {
			$placeholders[] = $match[0];
			return sprintf( self::TOKEN_FORMAT, $counter++ );
		};

		// Order matters: mask HTML first so brace-style content INSIDE
		// HTML attributes (e.g. `<a href="?ref={code}">`) gets captured
		// as part of the HTML token rather than as a separate brace
		// placeholder that would orphan when restored.
		$masked = (string) preg_replace_callback( self::HTML_RE, $callback, $text );
		$masked = (string) preg_replace_callback( self::BRACE_RE, $callback, $masked );
		// SEO variables BEFORE printf: PRINTF_RE's escaped-percent rule (`%%`)
		// would otherwise consume a %%var%%'s delimiters and expose the name.
		$masked = (string) preg_replace_callback( self::SEO_VAR_DOUBLE_RE, $callback, $masked );
		$masked = (string) preg_replace_callback( self::SEO_VAR_SINGLE_RE, $callback, $masked );
		$masked = (string) preg_replace_callback( self::PRINTF_RE, $callback, $masked );

		return [ $masked, $placeholders ];
	}

	/**
	 * Restore placeholders into $text using the list returned by mask().
	 *
	 * MT engines can:
	 *   - Re-order sentinels (`[[PFL_PH_1]]` before `[[PFL_PH_0]]`) — fine,
	 *     the indexed lookup still maps each to the correct value.
	 *   - Capitalise sentinels (rare; LibreTranslate has been observed
	 *     uppercasing) — we match case-insensitively.
	 *   - Insert whitespace inside the brackets — strip extra whitespace
	 *     during the match.
	 *   - DROP a sentinel (very rare; usually means the source text was
	 *     truncated by a glossary rule) — restoration leaves the missing
	 *     placeholder unfilled in the output; caller decides whether to
	 *     accept the result or fall back to source.
	 *
	 * @param string             $masked_translation Output from MT with sentinels embedded.
	 * @param array<int, string> $placeholders       Original-order placeholder list from mask().
	 * @return string Restored translation with placeholders re-injected.
	 */
	public static function restore( string $masked_translation, array $placeholders ): string {
		if ( $placeholders === [] ) {
			return $masked_translation;
		}

		return (string) preg_replace_callback(
			'/\[\[\s*PFL_PH_(\d+)\s*\]\]/i',
			static function ( $m ) use ( $placeholders ) {
				$idx = (int) $m[1];
				return $placeholders[ $idx ] ?? $m[0];
			},
			$masked_translation
		);
	}

	/**
	 * Verify a translated string preserves every placeholder from its
	 * source. Used by the bulk-translate job to reject MT output that
	 * dropped a `%s` or HTML tag — silently shipping the broken output
	 * would put a malformed gettext string in front of every visitor.
	 *
	 * @param string $source      Original source text.
	 * @param string $translation Candidate translation.
	 * @return bool True if every placeholder present in source also appears
	 *              (verbatim) in the translation.
	 */
	public static function preserves_placeholders( string $source, string $translation ): bool {
		[ , $source_phs ] = self::mask( $source );

		if ( $source_phs === [] ) {
			return true;
		}

		foreach ( $source_phs as $ph ) {
			if ( strpos( $translation, $ph ) === false ) {
				return false;
			}
		}

		return true;
	}
}
