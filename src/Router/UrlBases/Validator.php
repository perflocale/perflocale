<?php
/**
 * URL bases value validation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normal form, syntax and the compile-time rules a base value must pass (DESIGN-step1 3.4 encoding, 3.10 rules 1-5).
 *
 * @internal
 */
final class Validator {

	/**
	 * First segments a base may never take: WordPress routes and core query bases.
	 */
	private const RESERVED = [
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login.php',
		'wp-signup.php',
		'xmlrpc.php',
		'index.php',
		'admin',
		'dashboard',
		'login',
		'feed',
		'rss',
		'rss2',
		'atom',
		'rdf',
		'page',
		'comments',
		'comment-page',
		'search',
		'author',
		'embed',
		'trackback',
		'attachment',
		'date',
		'type',
		'wc-api',
		'wc-auth',
		'robots.txt',
		'favicon.ico',
		'sitemap.xml',
		'wp-sitemap.xml',
	];

	/**
	 * Normal form: bytes >= 0x80 as lower-case `%xx`, unreserved escapes decoded, other escapes lower-cased, case kept.
	 *
	 * Must stay byte-equal to the runtime's normalizer: the map stores values in this form.
	 *
	 * @param string $seg One path segment.
	 * @return string
	 */
	public static function norm( string $seg ): string {
		$out = '';
		$len = strlen( $seg );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $seg[ $i ];
			$o = ord( $c );

			if ( '%' === $c && $i + 2 < $len && 1 === preg_match( '/^[0-9A-Fa-f]{2}$/', substr( $seg, $i + 1, 2 ) ) ) {
				$hex  = substr( $seg, $i + 1, 2 );
				$chr  = chr( (int) hexdec( $hex ) );
				$out .= 1 === preg_match( '/^[A-Za-z0-9._~-]$/', $chr ) ? $chr : '%' . strtolower( $hex );
				$i   += 2;
				continue;
			}

			$out .= $o >= 0x80 ? '%' . sprintf( '%02x', $o ) : $c;
		}

		return $out;
	}

	/**
	 * Normal form of every `/`-separated segment, empty segments dropped.
	 *
	 * @param string $path Path without leading or trailing slash.
	 * @return string
	 */
	public static function norm_path( string $path ): string {
		$segs = array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s );

		return implode( '/', array_map( [ self::class, 'norm' ], $segs ) );
	}

	/**
	 * Whether a norm() segment hits the guard: an encoded slash, backslash or control byte, or a dot segment.
	 *
	 * @param string $seg Segment in norm form.
	 * @return bool
	 */
	public static function guard_hit( string $seg ): bool {
		return '.' === $seg || '..' === $seg || 1 === preg_match( '/%(?:2f|5c|[01][0-9a-f]|7f)|[\x00-\x1f\x7f\/\\\\]/', $seg );
	}

	/**
	 * A typed value in norm form, or the refusal code.
	 *
	 * Each segment goes through sanitize_title(), as WordPress stores slugs.
	 *
	 * @param string $text Typed value, e.g. `produkte` or `veranstaltungen/alle`.
	 * @return array{0: string|null, 1: string} [ value or null, code ('' when accepted) ]
	 */
	public static function from_input( string $text ): array {
		$text = trim( $text );

		if ( '' === $text ) {
			return [ '', '' ];
		}

		$parts = array_values( array_filter( explode( '/', trim( $text, "/ \t" ) ), static fn( string $s ): bool => '' !== trim( $s ) ) );

		if ( count( $parts ) > Values::MAX_SEGMENTS ) {
			return [ null, 'too_many_segments' ];
		}

		$out = [];

		foreach ( $parts as $part ) {
			$seg = self::norm( sanitize_title( $part ) );

			if ( '' === $seg ) {
				return [ null, 'empty_segment' ];
			}

			if ( strlen( $seg ) > Values::MAX_SEGMENT_BYTES ) {
				return [ null, 'too_long' ];
			}

			if ( self::guard_hit( $seg ) ) {
				return [ null, 'bad_characters' ];
			}

			$out[] = $seg;
		}

		$value = implode( '/', $out );
		$code  = self::syntax( $value );

		return '' === $code ? [ $value, '' ] : [ null, $code ];
	}

	/**
	 * Rule 1 on a norm value: URL-safe characters only, reserved and digit-only first segments.
	 *
	 * Imported and stored values reach the links raw, so `? # " < >` and spaces are refused here, not only in from_input().
	 *
	 * @param string $value Value in norm form.
	 * @return string Refusal code, '' when accepted.
	 */
	public static function syntax( string $value ): string {
		$first = explode( '/', $value )[0];

		if ( 1 !== preg_match( '#^(?:[A-Za-z0-9._~-]|%[0-9a-f]{2})+(?:/(?:[A-Za-z0-9._~-]|%[0-9a-f]{2})+)*$#D', $value ) ) {
			return 'bad_characters';
		}

		if ( in_array( $first, self::RESERVED, true ) || in_array( $first, self::wp_bases(), true ) ) {
			return 'reserved';
		}

		// Date archives route digit-led paths (`/2026/10/`).
		if ( 1 === preg_match( '/^[0-9]+$/', $first ) ) {
			return 'digits_only';
		}

		return '';
	}

	/**
	 * The bases WP_Rewrite routes at the root of a site (pagination, comments, author, search, feeds, REST).
	 *
	 * @return array<int, string>
	 */
	private static function wp_bases(): array {
		global $wp_rewrite;

		$out = [ function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json' ];

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( [ 'author_base', 'search_base', 'comments_base', 'pagination_base', 'comments_pagination_base', 'feed_base' ] as $prop ) {
				$out[] = (string) $wp_rewrite->$prop;
			}
		}

		return array_values( array_filter( $out ) );
	}

	/**
	 * Whether two segment paths overlap: one is a segment prefix of the other.
	 *
	 * @param string $a Value.
	 * @param string $b Value.
	 * @return bool
	 */
	public static function overlaps( string $a, string $b ): bool {
		return $a === $b || str_starts_with( $a, $b . '/' ) || str_starts_with( $b, $a . '/' );
	}

	/**
	 * Words of a refusal code for screens, CLI and Site Health.
	 *
	 * @param string $code   Reason code.
	 * @param string $detail The item it names (a page path, rule, key), already plain text.
	 * @return string
	 */
	public static function reason_text( string $code, string $detail = '' ): string {
		$texts = [
			'too_many_segments'   => __( 'too many parts: use at most 4 segments separated by "/"', 'perflocale' ),
			'empty_segment'       => __( 'a part is empty after cleaning: use letters, digits or "-"', 'perflocale' ),
			'too_long'            => __( 'a part is longer than 200 bytes', 'perflocale' ),
			'bad_characters'      => __( 'contains characters a URL base cannot use', 'perflocale' ),
			'reserved'            => __( 'WordPress uses this word at the start of its own URLs', 'perflocale' ),
			'digits_only'         => __( 'a base cannot start with digits only (date archives use them)', 'perflocale' ),
			/* translators: %s: language code or old language code, e.g. "de" */
			'language_prefix'     => __( 'it is the language code "%s"', 'perflocale' ),
			/* translators: %s: name of the other URL base, e.g. "Product (tx:product_cat)" */
			'other_key'           => __( 'it overlaps the URL base of %s', 'perflocale' ),
			'own_registered'      => __( 'it overlaps the base WordPress uses for this content type', 'perflocale' ),
			/* translators: %s: page path, e.g. "produkte/angebote" */
			'page'                => __( 'a published page uses the address "%s"', 'perflocale' ),
			/* translators: %s: post slug */
			'root_post'           => __( 'a published post uses the address "%s"', 'perflocale' ),
			/* translators: %s: category slug */
			'category'            => __( 'the category "%s" uses this address (the permalinks start with the category)', 'perflocale' ),
			/* translators: %s: term slug */
			'term'                => __( 'the term "%s" uses this address (the permalinks start with its taxonomy)', 'perflocale' ),
			/* translators: %s: user nicename */
			'author'              => __( 'the author "%s" uses this address (the permalinks start with the author)', 'perflocale' ),
			/* translators: %s: rewrite rule */
			'route'               => __( 'another plugin routes URLs under it (rule %s)', 'perflocale' ),
			/* translators: %s: site path */
			'site_path'           => __( 'another site of this network uses the path %s', 'perflocale' ),
			'too_many_unverified' => __( 'too many plugin routes PerfLocale cannot check (more than 16)', 'perflocale' ),
			/* translators: %s: rewrite rule */
			'self_test'           => __( 'a test address does not reach this content type (rule %s wins); save the permalinks or check the plugins that add rules', 'perflocale' ),
			'no_rules'            => __( 'WordPress has no rules for this base: open Settings > Permalinks and save', 'perflocale' ),
			/* translators: %s: number of parts, e.g. "7" */
			'deep_front'          => __( 'the permalink structure has more than %s fixed parts before this base', 'perflocale' ),
			'read_failed'         => __( 'the URL bases settings could not be read from the database: the published URL bases stay in use; reload the page to try again', 'perflocale' ),
			'rules_missing'       => __( 'WordPress has no rewrite rules stored: the published URL bases stay in use until Settings > Permalinks is saved', 'perflocale' ),
			/* translators: %s: number of bytes, e.g. "1024" */
			'cap_address'         => __( 'the address is longer than %s bytes or has an empty part, so PerfLocale cannot redirect it safely', 'perflocale' ),
			/* translators: %s: number of addresses, e.g. "12" */
			'cap_provenance'      => __( 'this base already has %s addresses that were in use in this language: stop redirecting the old ones you no longer need under "Stop redirecting old addresses", then save again', 'perflocale' ),
			'cap_first_segment'   => __( 'more than 16 URL bases start with the same word', 'perflocale' ),
			'cap_keys'            => __( 'more than 200 URL bases are configured', 'perflocale' ),
			'cap_languages'       => __( 'more than 64 languages', 'perflocale' ),
			/* translators: %s: size limit, e.g. "64 KB" */
			'cap_size'            => __( 'the URL bases are larger than %s; the previous ones stay in use, without the values refused now', 'perflocale' ),
			/* translators: %s: number of shared paths */
			'cap_shared'          => __( 'plugins declare %s shared paths under this base (at most 8)', 'perflocale' ),
			'shop_children'       => __( 'the shop page has more than 200 child pages in this language', 'perflocale' ),
			'not_provider'        => __( 'not a URL bases provider (it must implement PerfLocale\Contract\UrlBasesProvider)', 'perflocale' ),
			/* translators: %s: group id */
			'bad_group'           => __( 'invalid group id "%s" (use a-z, 0-9, "-" or "_", at most 32 characters, at least one letter)', 'perflocale' ),
			/* translators: %s: key */
			'bad_label'           => __( 'the label of %s is not text', 'perflocale' ),
			/* translators: %s: group id */
			'reserved_group'      => __( 'the group id "%s" is reserved', 'perflocale' ),
			/* translators: %s: group id */
			'duplicate_group'     => __( 'another provider already uses the group id "%s"', 'perflocale' ),
			/* translators: %s: key, e.g. "pt:event" */
			'key_claimed_twice'   => __( 'two providers declare %s; it is left out for both', 'perflocale' ),
			/* translators: %s: key */
			'bad_key'             => __( 'invalid key %s', 'perflocale' ),
			/* translators: %s: key */
			'not_registered'      => __( '%s is not a registered public type with a URL base', 'perflocale' ),
			/* translators: %s: key */
			'not_translatable'    => __( '%s is not translatable in PerfLocale > Settings > Translation', 'perflocale' ),
			/* translators: %s: key */
			'archive_same'        => __( '%s: the archive uses the same base as single items', 'perflocale' ),
			/* translators: %s: error message */
			'provider_error'      => __( 'the provider failed: %s', 'perflocale' ),
			/* translators: %s: "inputs" or "shared" */
			'provider_cap'        => __( 'the provider declares more than 32 %s entries; the first 32 are used', 'perflocale' ),
			/* translators: %s: error message */
			'code_error'          => __( 'the callback failed: %s', 'perflocale' ),
			'code_type'           => __( 'the callback returned no text', 'perflocale' ),
			'code_derived'        => __( 'it follows the translated Shop page: change or translate that page', 'perflocale' ),
			'code_switched'       => __( 'a callback left another site of the network current; no value set by code is used', 'perflocale' ),
		];

		$text = $texts[ $code ] ?? $code;

		return str_contains( $text, '%s' ) ? sprintf( $text, $detail ) : $text;
	}
}
