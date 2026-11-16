<?php
/**
 * URL bases route self-test.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For one sample URL per key and language, the stored rule core would match for the original and the swapped path
 * (pure preg_match loop in core's order, no HTTP, no WP-Cron). DESIGN-step1 3.10 rule 7.
 *
 * @internal
 */
final class SelfTest {

	/**
	 * Run the test.
	 *
	 * @param Rules                                                                                                              $rules   Stored rules.
	 * @param array<string, array{eff: string, front: string, own: array<string, string>, primary: array{0: string, 1: string}}> $work    Keys with rules.
	 * @param array<string, array<int, array{0: string, 1: string, 2: string}>>                                                  $entries Accepted entries by key.
	 * @param array<string, int>                                                                                                 $langs   Active non-default languages.
	 * @param Content                                                                                                            $content Page lookups (verbose page rules).
	 * @return array{results: array<string, array{sample: string, swapped: string|null, original: array<string, string|null>}>, failed: array<string, string>}
	 */
	public static function run( Rules $rules, array $work, array $entries, array $langs, Content $content ): array {
		$pages   = self::verbose_pages( $content );
		$results = [];
		$failed  = [];

		foreach ( $entries as $key => $list ) {
			$w   = $work[ $key ] ?? null;
			$raw = null !== $w ? Rules::sample( $w['primary'][0], $rules->root() ) : null;

			if ( null === $w || null === $raw || [] === $list ) {
				continue;
			}

			$rest = self::rest( $raw, $w['eff'] );

			// A sample whose segments are not the published base cannot show the swap works: fail closed.
			if ( null === $rest ) {
				$failed[ $key ] = '-';
				continue;
			}

			// The path the swap produces: the published eff, never the regex bytes (`index.php//product/` is unroutable).
			$sample = $w['eff'] . $rest;
			$hit    = $rules->first_match( $sample, $pages );
			$result = [
				'sample'   => $sample,
				'swapped'  => null !== $hit ? $hit[0] : null,
				'original' => [],
			];
			$front  = '' !== $w['front'] ? $w['front'] . '/' : '';

			foreach ( $list as [ $lang, $value, $kind ] ) {
				if ( 'current' === $kind && isset( $langs[ $lang ] ) ) {
					$orig                        = $rules->first_match( $front . $value . $rest, $pages );
					$result['original'][ $lang ] = null !== $orig ? $orig[0] : null;
				}
			}

			$results[ $key ] = $result;

			if ( null === $hit || ! isset( $w['own'][ $hit[0] ] ) ) {
				$failed[ $key ] = null !== $hit ? $hit[0] : '-';
			}
		}

		return [
			'results' => $results,
			'failed'  => $failed,
		];
	}

	/**
	 * A sample path after its first segments that norm() to `$eff`; null when they do not.
	 *
	 * @param string $sample Sample built from the primary rule.
	 * @param string $eff    Effective base.
	 * @return string|null
	 */
	private static function rest( string $sample, string $eff ): ?string {
		$segs = [];
		$i    = 0;
		$len  = strlen( $sample );

		for ( $n = substr_count( $eff, '/' ) + 1; $n > 0; $n-- ) {
			$i += strspn( $sample, '/', $i );

			if ( $i >= $len ) {
				return null;
			}

			$end    = strpos( $sample, '/', $i );
			$end    = false === $end ? $len : $end;
			$segs[] = substr( $sample, $i, $end - $i );
			$i      = $end;
		}

		return Validator::norm_path( implode( '/', $segs ) ) === $eff ? substr( $sample, $i ) : null;
	}

	/**
	 * The page check core runs for verbose `pagename` rules, when it runs one (DB slugs and status flags, as core).
	 *
	 * @param Content $content Page lookups.
	 * @return callable|null
	 */
	public static function verbose_pages( Content $content ): ?callable {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->use_verbose_page_rules ) {
			return null;
		}

		return static fn( string $path ): bool => $content->core_page( Validator::norm_path( $path ) );
	}
}
