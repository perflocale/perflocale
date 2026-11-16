<?php
/**
 * URL base values set by code.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Admin\Typed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks `perflocale/url_bases/value` once per offered key and language, at compile time only, and keeps the history
 * of values the map published (DESIGN-step1 3.13).
 *
 * @phpstan-import-type Work from Compiler
 * @phpstan-import-type ValueSet from Values
 * @phpstan-import-type Lang from Inputs
 * @phpstan-type CodeCell array{value: string, status: string, reason: string, detail: string}
 *
 * @internal
 */
final class CodeValues {

	public const HOOK = 'perflocale/url_bases/value';

	/**
	 * Longest answer parsed, in bytes (4 segments of 200 bytes plus slack).
	 */
	private const MAX_ANSWER = 4096;

	/**
	 * Set while the callbacks run: a callback that compiles again gets no code values (no recursion).
	 *
	 * @var bool
	 */
	private static bool $asking = false;

	/**
	 * Every answer that differs from the value without code, accepted or refused: key => lang => cell.
	 *
	 * @var array<string, array<string, CodeCell>>
	 */
	private array $report = [];

	/**
	 * Ask the callbacks for each key that is on, in each active non-default language, under that language's locale.
	 *
	 * @param array<string, Work>                  $work      Keys with rules.
	 * @param array<string, array<string, string>> $derived   Shop-derived values by key.
	 * @param list<Lang>                           $languages Language rows.
	 * @param ValueSet                             $values    Stored values.
	 * @return array<string, array<string, string>> key => lang => parsed answer ('' = the registered base).
	 */
	public function ask( array $work, array $derived, array $languages, array $values ): array {
		if ( self::$asking ) {
			return [];
		}

		self::$asking = true;
		$out          = [];
		$blog         = get_current_blog_id();
		$depth        = self::depth();

		try {
			foreach ( $languages as $lang ) {
				if ( ! $lang['is_active'] || $lang['is_default'] ) {
					continue;
				}

				// Pop only what was pushed: switch_to_locale() returns false without pushing.
				$switched = '' !== $lang['locale'] && function_exists( 'switch_to_locale' ) && switch_to_locale( $lang['locale'] );

				try {
					foreach ( $work as $key => $w ) {
						if ( $w['on'] ) {
							$this->ask_one( $out, $key, $w, $lang, $derived[ $key ] ?? null, $values['keys'][ $key ]['tr'][ $lang['slug'] ] ?? '', $blog );
						}

						// A callback left another site current or a switch open (ms_is_switched() would skip the shutdown compile).
						if ( get_current_blog_id() !== $blog || self::depth() !== $depth ) {
							for ( $n = self::depth() - $depth; $n > 0; --$n ) {
								restore_current_blog();
							}

							if ( get_current_blog_id() !== $blog || self::depth() < $depth ) {
								switch_to_blog( $blog );
							}

							// None of its answers is trusted.
							$this->report = [ $key => [ $lang['slug'] => self::cell( '', 'refused', 'code_switched', '' ) ] ];

							return [];
						}
					}
				} finally {
					if ( $switched && function_exists( 'restore_previous_locale' ) ) {
						restore_previous_locale();
					}
				}
			}
		} finally {
			self::$asking = false;
		}

		return $out;
	}

	/**
	 * Entries on core's site switch stack.
	 *
	 * @return int
	 */
	private static function depth(): int {
		return count( (array) ( $GLOBALS['_wp_switched_stack'] ?? [] ) );
	}

	/**
	 * Ask for one key in one language and record the answer.
	 *
	 * @param array<string, array<string, string>> $out     Answers so far.
	 * @param string                               $key     Key.
	 * @param Work                                 $w       Work row.
	 * @param Lang                                 $lang    Language row.
	 * @param array<string, string>|null           $derived The key's shop-derived values, or null.
	 * @param string                               $stored  Stored value (norm form, '' none).
	 * @param int                                  $blog    Blog id.
	 * @return void
	 */
	private function ask_one( array &$out, string $key, array $w, array $lang, ?array $derived, string $stored, int $blog ): void {
		$slug   = $lang['slug'];
		$stored = null !== $derived ? $derived[ $slug ] ?? '' : $stored;
		$shown  = rawurldecode( $stored );

		ob_start();

		try {
			$answer = self::filter(
				$shown,
				[
					'key'        => $key,
					'lang'       => $slug,
					'locale'     => $lang['locale'],
					'group'      => $w['desc']['grp'],
					'kind'       => null !== $derived ? 'derived' : self::kind( $w['desc'] ),
					'registered' => rawurldecode( $w['base'] ),
					'stored'     => $shown,
					'derived'    => null !== $derived,
					'blog_id'    => $blog,
				]
			);
		} catch ( \Throwable $e ) {
			$this->report[ $key ][ $slug ] = self::cell( '', 'refused', 'code_error', self::excerpt( $e->getMessage() ) );

			return;
		} finally {
			ob_end_clean();
		}

		if ( null === $answer || $answer === $shown ) {
			return;
		}

		if ( ! is_string( $answer ) ) {
			$this->report[ $key ][ $slug ] = self::cell( '', 'refused', 'code_type', '' );

			return;
		}

		// The accents follow the value's language, not the locale of whoever triggered the compile.
		[ $parsed, $code ] = strlen( $answer ) > self::MAX_ANSWER ? [ null, 'too_long' ] : Validator::from_input( remove_accents( $answer, $lang['locale'] ) );

		if ( null === $parsed ) {
			$this->report[ $key ][ $slug ] = self::cell( self::excerpt( $answer ), 'refused', $code, '' );

			return;
		}

		$parsed = $parsed === $w['base'] ? '' : $parsed;

		if ( $parsed === $stored ) {
			return;
		}

		// The shop archive is the translated Shop page's own address: only that page changes it.
		if ( null !== $derived ) {
			$this->report[ $key ][ $slug ] = self::cell( $parsed, 'refused', 'code_derived', '' );

			return;
		}

		$out[ $key ][ $slug ]          = $parsed;
		$this->report[ $key ][ $slug ] = self::cell( $parsed, 'ok', '', '' );
	}

	/**
	 * Run the filter; third-party callbacks may return any type.
	 *
	 * @param string               $value   The value without code.
	 * @param array<string, mixed> $context Context.
	 * @return mixed
	 */
	private static function filter( string $value, array $context ): mixed {
		/**
		 * The value of one URL base in one language, set by code; runs only when PerfLocale compiles its URL bases,
		 * under that language's locale, never on a visitor request. A function of the context only.
		 *
		 * @hook  perflocale/url_bases/value
		 * @since 1.0.8
		 * @api
		 *
		 * @param string               $value   The value without code, as typed ('' none).
		 * @param array<string, mixed> $context key, lang, locale, group, kind (single, archive, term, attribute,
		 *                                      derived), registered, stored, derived, blog_id.
		 * @return string|null A value ('' = the registered base); null or $value keeps the value without code.
		 */
		return apply_filters( self::HOOK, $value, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::HOOK is perflocale/url_bases/value.
	}

	/**
	 * Rules 1-5 on a set of answers at once, so the result does not depend on key order; refused ones are dropped.
	 *
	 * @param array<string, array<string, string>>                                   $set   key => lang => answer.
	 * @param callable(string, string): array{0: string, 1: string, 2: list<string>} $check Compiler::check() of a key and value.
	 * @return array<string, array<string, string>> The accepted answers.
	 */
	public function validate( array $set, callable $check ): array {
		$refused = [];

		foreach ( $set as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $value ) {
				$why = '' !== $value ? $check( $key, $value ) : [ '', '', [] ];

				if ( '' !== $why[0] ) {
					$refused[] = [ $key, $lang, $value, $why[0], $why[1] ];
				}
			}
		}

		foreach ( $refused as [ $key, $lang, $value, $reason, $detail ] ) {
			unset( $set[ $key ][ $lang ] );
			$this->report[ $key ][ $lang ] = self::cell( $value, 'refused', $reason, $detail );
		}

		return array_filter( $set );
	}

	/**
	 * The report rows: key => lang => cell.
	 *
	 * @return array<string, array<string, CodeCell>>
	 */
	public function report(): array {
		return $this->report;
	}

	/**
	 * Compile inputs for the map fingerprint, details left out (an exception message may differ per run).
	 *
	 * @return array<string, array<string, array{0: string, 1: string}>>
	 */
	public function inputs(): array {
		$out = [];

		foreach ( $this->report as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $cell ) {
				$out[ $key ][ $lang ] = [ $cell['value'], $cell['reason'] ];
			}
		}

		return $out;
	}

	/**
	 * The stored history of one key and language after this compile, as a settings change keeps it: values the
	 * previous map published that are no longer current go first; its other history values follow the stored ones.
	 *
	 * @param list<string> $published The previous map's current values.
	 * @param list<string> $older     The previous map's history values.
	 * @param list<string> $hist      The stored history.
	 * @param list<string> $skip      Values that are no history: current, stored, registered.
	 * @return list<string>
	 */
	public static function history( array $published, array $older, array $hist, array $skip ): array {
		$skip[] = '';
		$hist   = array_values( array_diff( $hist, $skip ) );
		$new    = array_diff( $published, $skip, $hist );
		$extra  = array_diff( $older, $skip, $hist, $new );

		return array_slice( array_values( array_unique( array_merge( $new, $hist, $extra ) ) ), 0, Values::HISTORY_CAP );
	}

	/**
	 * Current and history values the previous map holds, by key (none for a forgotten key): rule 4 sees them in the
	 * same build in which they move to the stored history.
	 *
	 * @param array<string, mixed>|null $map    The stored map.
	 * @param list<string>              $forget Forgotten keys, `*` or entries.
	 * @return array<string, list<string>>
	 */
	public static function held( ?array $map, array $forget ): array {
		$out = [];

		if ( null === $map || in_array( '*', $forget, true ) ) {
			return $out;
		}

		foreach ( Typed::arr( $map['alias'] ?? null ) as $list ) {
			foreach ( Typed::arr( $list ) as $e ) {
				$e   = Typed::map( $e );
				$key = Typed::str( $e['key'] ?? null );

				if ( ! in_array( $e['kind'] ?? '', [ 'current', 'history' ], true ) || ! is_array( $e['segs'] ?? null ) || in_array( $key, $forget, true ) ) {
					continue;
				}

				$segs = implode( '/', Typed::strs( $e['segs'] ) );

				if ( ! in_array( Values::entry_id( $key, Typed::str( $e['lang'] ?? null ), $segs ), $forget, true ) ) {
					$out[ $key ][] = $segs;
				}
			}
		}

		return $out;
	}

	/**
	 * A report cell.
	 *
	 * @param string $value  The answer (norm form, or as returned when it did not parse), '' when it was not text.
	 * @param string $status ok or refused.
	 * @param string $reason Reason code.
	 * @param string $detail Detail.
	 * @return CodeCell
	 */
	private static function cell( string $value, string $status, string $reason, string $detail ): array {
		return [
			'value'  => $value,
			'status' => $status,
			'reason' => $reason,
			'detail' => $detail,
		];
	}

	/**
	 * Kind of a key for the context.
	 *
	 * @param array{grp: string, role: string, object: string} $desc Key descriptor.
	 * @return string
	 */
	private static function kind( array $desc ): string {
		if ( 'taxonomy' !== $desc['role'] ) {
			return $desc['role'];
		}

		return 'woocommerce' === $desc['grp'] && str_starts_with( $desc['object'], 'pa_' ) ? 'attribute' : 'term';
	}

	/**
	 * At most 200 characters of a callback's text, without control characters (screens and terminals show it).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function excerpt( string $text ): string {
		$text = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $text );

		return mb_substr( $text, 0, 200, 'UTF-8' );
	}
}
