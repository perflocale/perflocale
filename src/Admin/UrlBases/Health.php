<?php
/**
 * URL bases Site Health test.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\UrlBases;

use PerfLocale\Router\UrlBases\CodeValues;
use PerfLocale\Router\UrlBases\Publication;
use PerfLocale\Router\UrlBases\Validator;
use PerfLocale\Router\UrlBases\Values;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health: suspended values, unverified routes, refused providers, a pending or failed update, cap usage.
 *
 * @phpstan-import-type Report from \PerfLocale\Router\UrlBases\Compiler
 *
 * @internal
 */
final class Health {

	public const ID = 'perflocale_url_bases';

	/**
	 * The test result (core's direct-test shape).
	 *
	 * @return array<string, mixed>
	 */
	public static function test(): array {
		\PerfLocale\Router\UrlBases\Lifecycle::validate_none();
		$stored = get_option( Publication::MAP, null );

		if ( null === $stored && ! Values::exists() && ! has_filter( CodeValues::HOOK ) ) {
			return self::result( 'good', __( 'Translated URL bases are not set up', 'perflocale' ), esc_html__( 'No URL base is translated, so PerfLocale adds no work to page requests for them.', 'perflocale' ) );
		}

		if ( null !== $stored && ( ! is_array( $stored ) || 1 !== ( $stored['v'] ?? null ) ) ) {
			return self::result( 'critical', __( 'The URL bases map cannot be read', 'perflocale' ), esc_html__( 'Links and redirects use the bases of Settings > Permalinks. Open PerfLocale > Settings > URL & Routing > URL Bases and save to rebuild it.', 'perflocale' ) );
		}

		$build  = Publication::dry_run();
		$report = $build['report'];
		$issues = self::issues( $report );
		$dirty  = Publication::dirty();
		$info   = [];

		// Over the size cap no update comes, so none is promised (the cap_size issue says why).
		if ( [] !== $dirty['reasons'] ) {
			$issues[] = __( 'An update of the URL bases is waiting: it runs on the next admin page load of an administrator.', 'perflocale' );
		} elseif ( null !== $build['map'] && ! in_array( 'cap_size', $report['errors'], true ) && ( ! is_array( $stored ) || ( $stored['fp'] ?? '' ) !== $build['fp'] ) ) {
			$issues[] = __( 'The URL bases changed since they were published (values set by code count too): they update within an hour, or now: save the URL Bases settings or run wp perflocale url-bases compile.', 'perflocale' );
		}

		foreach ( $dirty['retire'] as $adapter => $attempts ) {
			if ( $attempts >= Publication::RETIRE_ATTEMPTS ) {
				/* translators: %s: plugin id, e.g. "yoast" */
				$issues[] = sprintf( __( 'The URL cache of %s could not be cleared after the last URL bases change: clear it in that plugin.', 'perflocale' ), $adapter );
			}
		}

		foreach ( $report['unv'] as $regex ) {
			$info[] = $regex;
		}

		$bare = [];

		foreach ( $report['keys'] as $k ) {
			if ( '' !== $k['bare_by'] ) {
				/* translators: 1: URL base label, 2: why its bare address keeps its own route */
				$bare[] = sprintf( __( '%1$s: %2$s', 'perflocale' ), $k['label'], self::bare_note( $k['bare_by'], $k['eff'] ) );
			}
		}

		$caps = $report['caps'];
		/* translators: 1: bases in use, 2: largest number of bases on one first word, 3: size in KB */
		$usage = sprintf( __( 'Usage: %1$d of 200 bases, %2$d of 16 on one first word, %3$s of %4$s KB.', 'perflocale' ), $caps['keys'], $caps['max_per_segment'], number_format_i18n( $caps['bytes'] / 1024, 1 ), number_format_i18n( $caps['max_bytes'] / 1024 ) );

		// Over 64 KB (a raised limit): every page loads and unserializes the map; name the cost measured here.
		if ( is_array( $stored ) ) {
			$bytes = serialize( $stored ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The stored bytes, as get_option() reads them.

			if ( strlen( $bytes ) > \PerfLocale\Router\UrlBases\Compiler::MAX_BYTES ) {
				/* translators: 1: size in KB, 2: microseconds */
				$usage .= ' ' . sprintf( __( 'The published map is %1$s KB, over 64 KB: every page loads it, which takes about %2$s microseconds per page on this server.', 'perflocale' ), number_format_i18n( strlen( $bytes ) / 1024, 1 ), number_format_i18n( self::unserialize_us( $bytes ) ) );
			}
		}

		$desc = '';

		if ( [] !== $issues ) {
			$desc .= '<ul>' . implode( '', array_map( static fn( string $l ): string => '<li>' . esc_html( $l ) . '</li>', $issues ) ) . '</ul>';
		}

		if ( [] !== $info ) {
			$desc .= '<p>' . esc_html__( 'Plugin routes PerfLocale cannot check against the bases; the plugin route wins when both match:', 'perflocale' ) . '</p><ul>' . implode( '', array_map( static fn( string $r ): string => '<li><code>' . esc_html( $r ) . '</code></li>', $info ) ) . '</ul>';
		}

		if ( [] !== $bare ) {
			$desc .= '<ul>' . implode( '', array_map( static fn( string $l ): string => '<li>' . esc_html( $l ) . '</li>', $bare ) ) . '</ul>';
		}

		$desc .= '<p>' . esc_html( $usage ) . '</p>';

		$action = '<a href="' . esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=url-routing&subtab=url-bases' ) ) . '">' . esc_html__( 'Open the URL Bases settings', 'perflocale' ) . '</a>';

		if ( [] !== $issues ) {
			return self::result( 'recommended', __( 'Some translated URL bases are not in use', 'perflocale' ), $desc, $action );
		}

		return self::result( 'good', __( 'Translated URL bases are in use', 'perflocale' ), $desc, $action );
	}

	/**
	 * Plain-text problems of a compile report.
	 *
	 * @param Report $report Report.
	 * @return list<string>
	 */
	public static function issues( array $report ): array {
		$out = [];

		foreach ( $report['keys'] as $key => $k ) {
			if ( in_array( $k['status'], [ 'suspended', 'no_rules' ], true ) ) {
				/* translators: 1: URL base label, 2: reason */
				$out[] = sprintf( __( '%1$s: %2$s.', 'perflocale' ), $k['label'], Validator::reason_text( $k['reason'], $k['detail'] ) );
			} elseif ( $k['stale'] ) {
				/* translators: %s: URL base label */
				$out[] = sprintf( __( '%s: the stored permalinks differ from this setup; open Settings > Permalinks and save.', 'perflocale' ), $k['label'] );
			} elseif ( 'undeclared' === $k['status'] ) {
				/* translators: %s: key, e.g. "pt:event" */
				$out[] = sprintf( __( 'Saved values of %s are not used: the content type is not registered.', 'perflocale' ), $key );
			}
		}

		foreach ( $report['entries'] as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $cell ) {
				if ( 'suspended' === $cell['status'] && 'ok' === ( $report['keys'][ $key ]['status'] ?? '' ) ) {
					/* translators: 1: URL base label, 2: language code, 3: value, 4: reason */
					$out[] = sprintf( __( '%1$s in %2$s, "%3$s": %4$s.', 'perflocale' ), $report['keys'][ $key ]['label'], $lang, rawurldecode( $cell['value'] ), Validator::reason_text( $cell['reason'], $cell['detail'] ) );
				}
			}
		}

		foreach ( $report['code'] ?? [] as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $cell ) {
				if ( 'refused' !== $cell['status'] ) {
					continue;
				}

				$label = $report['keys'][ $key ]['label'] ?? $key;
				$why   = Validator::reason_text( $cell['reason'], $cell['detail'] );
				$out[] = '' !== $cell['value']
					/* translators: 1: URL base label, 2: language code, 3: value, 4: reason */
					? sprintf( __( '%1$s in %2$s, value set by code "%3$s": %4$s.', 'perflocale' ), $label, $lang, rawurldecode( $cell['value'] ), $why )
					/* translators: 1: URL base label, 2: language code, 3: reason */
					: sprintf( __( '%1$s in %2$s, value set by code: %3$s.', 'perflocale' ), $label, $lang, $why );
			}
		}

		foreach ( $report['providers'] as $issue ) {
			/* translators: 1: provider class or group, 2: reason */
			$out[] = sprintf( __( 'URL bases provider %1$s ignored: %2$s.', 'perflocale' ), $issue['provider'], Validator::reason_text( $issue['code'], $issue['detail'] ) );
		}

		foreach ( $report['errors'] as $code ) {
			$out[] = Validator::reason_text( $code, number_format_i18n( $report['caps']['max_bytes'] / 1024 ) . ' KB' );
		}

		return $out;
	}

	/**
	 * Median microseconds of unserialize() on these bytes, 15 runs (the per-page cost of an autoloaded map).
	 *
	 * @param string $bytes Serialized map.
	 * @return int
	 */
	private static function unserialize_us( string $bytes ): int {
		$runs = [];

		for ( $i = 0; $i < 15; $i++ ) {
			$t = hrtime( true );
			unserialize( $bytes, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Timing the read every page does; no objects.
			$runs[] = hrtime( true ) - $t;
		}

		sort( $runs );

		return (int) round( $runs[7] / 1000 );
	}

	/**
	 * Information: the bare base address belongs to a page or another rule, so only addresses below it are translated.
	 *
	 * @param string $by  `page:<path>`, `post:<slug>` or `rule:<regex>`.
	 * @param string $eff Effective base.
	 * @return string
	 */
	public static function bare_note( string $by, string $eff ): string {
		[ $kind, $what ] = explode( ':', $by, 2 ) + [ '', '' ];

		if ( 'post' === $kind ) {
			/* translators: %s: address, e.g. "/portfolio/" */
			return sprintf( __( 'the address %s itself is a post; only addresses below it use the translated base', 'perflocale' ), '/' . $eff . '/' );
		}

		return 'page' === $kind
			/* translators: %s: address, e.g. "/portfolio/" */
			? sprintf( __( 'the address %s itself is a page; only addresses below it use the translated base', 'perflocale' ), '/' . $eff . '/' )
			/* translators: 1: address, e.g. "/portfolio/", 2: rewrite rule */
			: sprintf( __( 'the address %1$s itself is routed by the rule %2$s; only addresses below it use the translated base', 'perflocale' ), '/' . $eff . '/', $what );
	}

	/**
	 * A result in core's shape.
	 *
	 * @param string $status  good, recommended or critical.
	 * @param string $label   Label.
	 * @param string $desc    Description HTML (escaped).
	 * @param string $actions Actions HTML (escaped).
	 * @return array<string, mixed>
	 */
	private static function result( string $status, string $label, string $desc, string $actions = '' ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Translation', 'perflocale' ),
				'color' => 'blue',
			],
			'description' => str_starts_with( ltrim( $desc ), '<' ) ? $desc : '<p>' . $desc . '</p>',
			'actions'     => $actions,
			'test'        => self::ID,
		];
	}
}
