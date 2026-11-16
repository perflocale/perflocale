<?php
/**
 * Stored rewrite rules, read by the URL bases compiler.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stored `rewrite_rules` as the compiler reads them: each key's own rules, its effective base, literal-prefix
 * overlaps and a pure match loop in core's order (WP::parse_request(), no HTTP).
 *
 * @phpstan-import-type KeyDesc from Registry
 *
 * @internal
 */
final class Rules {

	/**
	 * Path segment the self-test puts where a rule expects an object slug.
	 */
	public const SAMPLE = 'zz-pfl-sample';

	/**
	 * Query vars of generic catch-all rules (3.10 rule 3c), beside post type query vars and endpoints.
	 */
	private const GENERIC_VARS = [ 'pagename', 'name', 'attachment', 'attachment_id', 'page', 'paged', 'feed', 'embed', 'tb', 'cpage', 'p', 'post_type', 'preview', 'withcomments', 'withoutcomments' ];

	/**
	 * Vars that identify a post; a rule holding one is never a taxonomy's own rule.
	 */
	private const POST_IDENTITY = [ 'pagename', 'name', 'attachment', 'p', 'page_id', 'post_type' ];

	/**
	 * Object identity vars a generic catch-all must capture: a fixed value (`pagename=landing`) is a fixed route.
	 */
	private const IDENTITY = [ 'pagename', 'name', 'attachment', 'attachment_id', 'p', 'page_id' ];

	/**
	 * Core rules anchored on a file name at the end of any path (WP_Rewrite::rewrite_rules()): a base swap never changes their match.
	 */
	private const CORE_SUFFIX = [ '.*wp-(atom|rdf|rss|rss2|feed|commentsrss2)\\.php$', '.*wp-app\\.php(/.*)?$', '.*wp-register.php$', '.*wp-signup.php$', '.*wp-activate.php$' ];

	/**
	 * Stored rules in core's order: regex => query.
	 *
	 * @var array<string, string>
	 */
	private array $all;

	/**
	 * Rules without PerfLocale's language-prefixed copies (those repeat every rule under a language group).
	 *
	 * @var array<string, string>
	 */
	private array $plain = [];

	/**
	 * Per stored rule, in order: [ head group id, literal prefix after it ]; built on the first first_match().
	 *
	 * @var list<array{0: int, 1: string}>|null
	 */
	private ?array $heads = null;

	/**
	 * Head groups: id => literal alternatives; 0 => [ '' ] (no head group).
	 *
	 * @var list<list<string>>
	 */
	private array $groups = [ [ '' ] ];

	/**
	 * Per plain rule: [ regex without "^", core suffix rule, literal (norm form), regex after the literal ]; built on the first overlaps().
	 *
	 * @var array<string, array{0: string, 1: bool, 2: string, 3: string}>|null
	 */
	private ?array $lits = null;

	/**
	 * Rules.
	 *
	 * @param array<mixed> $rules   Stored `rewrite_rules`.
	 * @param string       $lang_qv PerfLocale's language query var.
	 * @param list<string> $generic Extra generic vars (endpoints, post type query vars, permalink tags).
	 * @param string       $root    WP_Rewrite root: `index.php/` for PATH_INFO permalinks, else ''.
	 * @param list<string> $pt_vars Post type query vars; a rule holding one is never a taxonomy's own rule.
	 * @param list<string> $ident   Permalink tag vars that name an object (`category_name`, `author_name`).
	 */
	public function __construct( array $rules, private readonly string $lang_qv, private readonly array $generic = [], private readonly string $root = '', private readonly array $pt_vars = [], private readonly array $ident = [] ) {
		$this->all = [];

		foreach ( $rules as $regex => $query ) {
			if ( is_string( $regex ) && is_string( $query ) ) {
				$this->all[ $regex ] = $query;

				if ( ! array_key_exists( $lang_qv, self::query_vars( $query ) ) ) {
					$this->plain[ $regex ] = $query;
				}
			}
		}
	}

	/**
	 * Whether any rules are stored.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return [] !== $this->all;
	}

	/**
	 * The leading literal characters of a rule regex, escapes unescaped; a quantified last character is left out.
	 *
	 * @param string $regex Rule regex.
	 * @param string $root  WP_Rewrite root, taken literally (its "." is unescaped in the rules).
	 * @return string
	 */
	public static function literal_prefix( string $regex, string $root = '' ): string {
		return self::literal_span( $regex, $root )[0];
	}

	/**
	 * The literal prefix and the number of regex bytes it takes (after a leading `^`).
	 *
	 * @param string $regex Rule regex.
	 * @param string $root  WP_Rewrite root, taken literally.
	 * @return array{0: string, 1: int}
	 */
	public static function literal_span( string $regex, string $root = '' ): array {
		$regex = ltrim( $regex, '^' );
		$out   = '';
		$len   = strlen( $regex );
		$i     = 0;

		if ( '' !== $root && str_starts_with( $regex, $root ) ) {
			$out = $root;
			$i   = strlen( $root );
		}

		while ( $i < $len ) {
			$c    = $regex[ $i ];
			$step = 1;

			if ( '\\' === $c ) {
				$n = $regex[ $i + 1 ] ?? '';

				if ( '' === $n || 1 === preg_match( '/^[A-Za-z0-9]$/', $n ) ) {
					break;
				}

				$c    = $n;
				$step = 2;
			} elseif ( str_contains( '.[]()?*+{}|$^', $c ) ) {
				break;
			}

			$next = $regex[ $i + $step ] ?? '';

			if ( '' !== $next && str_contains( '?*+{', $next ) ) {
				break;
			}

			$out .= $c;
			$i   += $step;
		}

		return [ $out, $i ];
	}

	/**
	 * The vars of a rule query (`index.php?a=$matches[1]&b=x`), in order.
	 *
	 * @param string $query Rule query.
	 * @return array<string, string>
	 */
	public static function query_vars( string $query ): array {
		$pos  = strpos( $query, '?' );
		$qs   = false === $pos ? $query : substr( $query, $pos + 1 );
		$vars = [];

		foreach ( explode( '&', $qs ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$eq = strpos( $pair, '=' );
			$vars[ false === $eq ? $pair : substr( $pair, 0, $eq ) ] = false === $eq ? '' : substr( $pair, $eq + 1 );
		}

		return $vars;
	}

	/**
	 * A key's own rules: those whose query identifies its post type, archive or taxonomy (WP_Rewrite's forms).
	 *
	 * @param KeyDesc $desc Key descriptor.
	 * @return array<string, string>
	 */
	public function own_rules( array $desc ): array {
		$out = [];

		foreach ( $this->plain as $regex => $query ) {
			if ( $this->is_own( self::query_vars( $query ), $desc ) ) {
				$out[ $regex ] = $query;
			}
		}

		return $out;
	}

	/**
	 * Own rules under the registered base (`$expected`); else those with a literal beyond the root (stale registration).
	 *
	 * Walked permastruct directories (`%category%/` of `/%category%/%postname%/`) carry a taxonomy's vars without its base.
	 *
	 * @param array<string, string> $own      Own rules.
	 * @param string                $expected Front and registered base, norm form.
	 * @return array<string, string>
	 */
	public function based( array $own, string $expected ): array {
		$under = [];
		$any   = [];
		$root  = trim( $this->root, '/' );

		foreach ( $own as $regex => $query ) {
			$lit = Validator::norm_path( self::literal_prefix( (string) $regex, $this->root ) );

			if ( '' !== $expected && ( $lit === $expected || str_starts_with( $lit, $expected . '/' ) ) ) {
				$under[ $regex ] = $query;
			} elseif ( '' !== $lit && $lit !== $root ) {
				$any[ $regex ] = $query;
			}
		}

		return [] !== $under ? $under : $any;
	}

	/**
	 * The WP_Rewrite root these rules were read with.
	 *
	 * @return string
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * What core's page rules route after a page path: literal suffixes (`feed`, `page`, `wc/file/transient`; norm form)
	 * and the regex tails of the others (`/comment-page-([0-9]{1,})/?$`, the numeric page), matched against `/<rest>`.
	 *
	 * A tail that also matches a slug-shaped rest (`/(.+)/?$`) is left out: it would take object URLs.
	 *
	 * @return array{0: list<string>, 1: list<string>}
	 */
	public function page_suffixes(): array {
		$lits  = [];
		$tails = [];
		$head  = $this->root . '(.?.+?)';

		foreach ( $this->plain as $regex => $query ) {
			if ( ! str_starts_with( $regex, $head ) || ( self::query_vars( $query )['pagename'] ?? '' ) !== '$matches[1]' ) {
				continue;
			}

			$tail = substr( $regex, strlen( $head ) );
			$path = str_starts_with( $tail, '/' ) ? substr( $tail, 1 ) : '';

			if ( 1 === preg_match( '#^\(([a-z0-9_-]+(?:\|[a-z0-9_-]+)*)\)(?:/|$)#', $path, $m ) ) {
				array_push( $lits, ...explode( '|', $m[1] ) );
				continue;
			}

			[ $lit, $n ] = self::literal_span( $path );
			$rest        = substr( $path, $n );

			if ( '' !== $lit && ( str_ends_with( $lit, '/' ) || '' === $rest || str_starts_with( $rest, '/' ) || str_starts_with( $rest, '$' ) || str_starts_with( $rest, '(/' ) ) ) {
				$lits[] = $lit;
			} elseif ( ! self::slug_shaped( $tail ) ) {
				$tails[] = $tail;
			}
		}

		return [ array_values( array_unique( array_filter( array_map( [ Validator::class, 'norm_path' ], $lits ) ) ) ), array_values( array_unique( $tails ) ) ];
	}

	/**
	 * Whether a page-rule tail also matches a slug-shaped rest, with and without "-" (a `[a-z]+` class): it takes object URLs.
	 *
	 * @param string $tail Regex tail after the page path.
	 * @return bool
	 */
	private static function slug_shaped( string $tail ): bool {
		foreach ( [ self::SAMPLE, 'zzpflsample' ] as $slug ) {
			foreach ( [ '/' . $slug, '/' . $slug . '/' . $slug . '/' ] as $rest ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed plugin rule is skipped, as core does.
				if ( 0 !== @preg_match( '#^' . $tail . '#', $rest ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a rule's vars identify the key.
	 *
	 * @param array<string, string> $vars Rule vars.
	 * @param KeyDesc               $desc Key descriptor.
	 * @return bool
	 */
	private function is_own( array $vars, array $desc ): bool {
		$obj   = $desc['object'];
		$qv    = $desc['qv'];
		$match = static fn( string $var ): bool => isset( $vars[ $var ] ) && str_starts_with( $vars[ $var ], '$matches[' );

		if ( 'archive' === $desc['role'] ) {
			return ( $vars['post_type'] ?? null ) === $obj && ! $match( 'name' ) && ! $match( 'pagename' ) && ( false === $qv || ! isset( $vars[ $qv ] ) );
		}

		if ( 'single' === $desc['role'] ) {
			if ( false !== $qv ) {
				return $match( $qv );
			}

			return ( $vars['post_type'] ?? null ) === $obj && ( $match( 'name' ) || $match( 'pagename' ) );
		}

		foreach ( array_merge( self::POST_IDENTITY, $this->pt_vars ) as $var ) {
			if ( isset( $vars[ $var ] ) ) {
				return false;
			}
		}

		if ( false !== $qv ) {
			return $match( $qv );
		}

		return ( $vars['taxonomy'] ?? null ) === $obj && $match( 'term' );
	}

	/**
	 * The effective base: the common literal prefix of the key's own rules, segment-bounded, norm form; null when none.
	 *
	 * @param array<string, string> $own  Own rules.
	 * @param string                $root WP_Rewrite root.
	 * @return string|null
	 */
	public static function eff( array $own, string $root = '' ): ?string {
		$lits = array_map( static fn( $r ): string => self::literal_prefix( (string) $r, $root ), array_keys( $own ) );

		if ( [] === $lits ) {
			return null;
		}

		$common = (string) array_shift( $lits );

		foreach ( $lits as $lit ) {
			$n = 0;
			$m = min( strlen( $common ), strlen( $lit ) );

			while ( $n < $m && $common[ $n ] === $lit[ $n ] ) {
				++$n;
			}

			$common = substr( $common, 0, $n );
		}

		// Segment-bounded: every literal continues with "/" or ends where the common prefix ends.
		foreach ( array_map( static fn( $r ): string => self::literal_prefix( (string) $r, $root ), array_keys( $own ) ) as $lit ) {
			$next = $lit[ strlen( $common ) ] ?? '/';

			if ( '/' !== $next && ! str_ends_with( $common, '/' ) ) {
				$cut    = strrpos( $common, '/' );
				$common = false === $cut ? '' : substr( $common, 0, $cut + 1 );
				break;
			}
		}

		$eff = Validator::norm_path( trim( $common, '/' ) );

		return '' === $eff ? null : $eff;
	}

	/**
	 * The key's primary own rule (the object or archive route itself, no feed, embed or paging), else its first one.
	 *
	 * @param array<string, string> $own  Own rules.
	 * @param string                $root WP_Rewrite root.
	 * @return array{0:string,1:string}|null [ regex, query ]
	 */
	public static function primary( array $own, string $root = '' ): ?array {
		foreach ( $own as $regex => $query ) {
			$extra = array_diff( array_keys( self::query_vars( $query ) ), [ 'post_type', 'name', 'pagename', 'taxonomy', 'term', 'page' ] );

			if ( count( $extra ) <= 1 && null !== self::sample( (string) $regex, $root ) && ! array_intersect( $extra, [ 'feed', 'embed', 'paged', 'tb', 'cpage', 'attachment' ] ) ) {
				return [ (string) $regex, $query ];
			}
		}

		foreach ( $own as $regex => $query ) {
			return [ (string) $regex, $query ];
		}

		return null;
	}

	/**
	 * A path a rule regex matches, built by filling its groups; null for a shape the self-test cannot fill.
	 *
	 * @param string $regex Rule regex.
	 * @param string $root  WP_Rewrite root, taken literally.
	 * @return string|null
	 */
	public static function sample( string $regex, string $root = '' ): ?string {
		$rest = ltrim( $regex, '^' );
		$out  = '';

		if ( '' !== $root && str_starts_with( $rest, $root ) ) {
			$out  = $root;
			$rest = substr( $rest, strlen( $root ) );
		}

		$fill = [
			'(?:/([0-9]+))?'    => '',
			'(?:/([0-9]{1,}))?' => '',
			'(.?.+?)'           => self::SAMPLE,
			'([^/]+)'           => self::SAMPLE,
			'(.+?)'             => self::SAMPLE,
			'(.+)'              => self::SAMPLE,
			'([0-9]{1,})'       => '2',
			'([0-9]+)'          => '2',
			'/?$'               => '/',
			'/?'                => '/',
			'$'                 => '',
		];

		while ( '' !== $rest ) {
			foreach ( $fill as $token => $value ) {
				if ( str_starts_with( $rest, $token ) ) {
					$out .= $value;
					$rest = substr( $rest, strlen( $token ) );
					continue 2;
				}
			}

			$c = $rest[0];

			if ( '\\' === $c && isset( $rest[1] ) && 1 !== preg_match( '/^[A-Za-z0-9]$/', $rest[1] ) ) {
				$out .= $rest[1];
				$rest = substr( $rest, 2 );
				continue;
			}

			if ( str_contains( '.[]()?*+{}|$^\\', $c ) ) {
				return null;
			}

			$out .= $c;
			$rest = substr( $rest, 1 );
		}

		return $out;
	}

	/**
	 * Overlap of a candidate path (as core matches it, front included) with the stored rules (3.10 rule 3).
	 *
	 * @param string                $cand   Candidate path, norm form, no slashes at the ends.
	 * @param array<string, string> $own    The key's own rules.
	 * @param string                $front  The key's front and root part, norm form.
	 * @param array<int, string>    $shared Declared shared namespaces (norm form, ending in "/").
	 * @return array{conflict: string|null, unv: list<string>} First conflicting rule; rules that cannot be proven disjoint.
	 */
	public function overlaps( string $cand, array $own, string $front, array $shared ): array {
		$c       = $cand . '/';
		$bare    = ( '' !== $this->root && str_starts_with( $c, $this->root ) ) ? substr( $c, strlen( $this->root ) ) : $c;
		$front_s = '' !== $front ? $front . '/' : '';
		$unv     = [];

		$this->lits ??= $this->plain_lits();

		foreach ( $this->plain as $regex => $query ) {
			[ $re, $core, $lit, $regex_rest ] = $this->lits[ $regex ];

			if ( $core ) {
				continue;
			}

			// Core matches a rule without the root against the path without it (WP::parse_request()).
			$cc = ( '' === $this->root || str_starts_with( $re, $this->root ) ) ? $c : $bare;

			if ( ! str_starts_with( $cc, $lit ) && ! str_starts_with( $lit, $cc ) ) {
				continue;
			}

			// A literal that ends mid-segment before a "/" cannot match a longer segment ("produkte/?$" vs "produktex/").
			$after = substr( $regex_rest, 0, 1 );

			if ( '' !== $lit && ! str_ends_with( $lit, '/' ) && '/' === $after && strlen( $cc ) > strlen( $lit ) && '/' !== $cc[ strlen( $lit ) ] ) {
				continue;
			}

			// (a) the key's own rules; (b) declared shared namespaces.
			if ( isset( $own[ $regex ] ) || ( '' !== $lit && $this->in_shared( $lit, $shared ) ) ) {
				continue;
			}

			$broader = strlen( $lit ) < strlen( $cc );

			// (c) generic catch-alls, only where their literal ends at the root or the key's front (content is checked by rule 2).
			$at_front = '' === $lit || ( str_ends_with( $lit, '/' ) && '' !== $front_s && str_starts_with( $front_s, $lit ) );

			if ( $broader && $at_front && $this->is_generic( self::query_vars( $query ) ) ) {
				continue;
			}

			if ( $broader && self::disjoint( $regex_rest, (string) substr( $cc, strlen( $lit ) ) ) ) {
				continue;
			}

			if ( '' === $lit ) {
				$unv[] = $regex;
				continue;
			}

			return [
				'conflict' => $regex,
				'unv'      => $unv,
			];
		}

		return [
			'conflict' => null,
			'unv'      => $unv,
		];
	}

	/**
	 * The candidate-independent parts of overlaps() per plain rule, computed once.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: string, 3: string}>
	 */
	private function plain_lits(): array {
		$out = [];

		foreach ( array_keys( $this->plain ) as $regex ) {
			$re             = ltrim( $regex, '^' );
			$core           = in_array( '' !== $this->root && str_starts_with( $re, $this->root ) ? substr( $re, strlen( $this->root ) ) : $re, self::CORE_SUFFIX, true );
			[ $raw, $used ] = self::literal_span( $regex, $this->root );
			$lit            = Validator::norm_path( $raw );
			$out[ $regex ]  = [ $re, $core, ( '' !== $lit && str_ends_with( $raw, '/' ) ) ? $lit . '/' : $lit, substr( $re, $used ) ];
		}

		return $out;
	}

	/**
	 * Whether a rule literal lies in a declared shared namespace; a broader literal (`events/` for `events/calendar/`) does not.
	 *
	 * @param string             $lit    Rule literal (norm form).
	 * @param array<int, string> $shared Shared namespaces.
	 * @return bool
	 */
	private function in_shared( string $lit, array $shared ): bool {
		foreach ( $shared as $ns ) {
			if ( str_starts_with( $lit, $ns ) || $lit . '/' === $ns ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a rule query is a generic catch-all (a page, post or attachment by name, endpoints).
	 *
	 * @param string $query Rule query.
	 * @return bool
	 */
	public function generic( string $query ): bool {
		return $this->is_generic( self::query_vars( $query ) );
	}

	/**
	 * Whether rule vars are only those of a generic catch-all (page, post name, attachment, a post type, endpoints),
	 * each object identity captured from the path; a fixed `post_type` needs a captured identity too.
	 *
	 * @param array<string, string> $vars Rule vars.
	 * @return bool
	 */
	private function is_generic( array $vars ): bool {
		if ( [] === $vars || [] !== array_diff( array_keys( $vars ), array_merge( self::GENERIC_VARS, $this->generic ) ) ) {
			return false;
		}

		$ident = array_merge( self::IDENTITY, $this->pt_vars, $this->ident );
		$named = false;

		foreach ( $vars as $var => $value ) {
			if ( in_array( (string) $var, $ident, true ) ) {
				if ( ! str_starts_with( $value, '$matches[' ) ) {
					return false;
				}

				$named = true;
			}
		}

		return $named || ! isset( $vars['post_type'] );
	}

	/**
	 * Whether the rest of a rule (after its literal) can never match a path starting with `$rest`.
	 *
	 * Provable shapes only, on a first atom that is not optional: a digit class, or a group of literal words none of which fits.
	 *
	 * @param string $regex_rest Rule regex after its literal prefix.
	 * @param string $rest       Candidate path after the literal, ending in "/".
	 * @return bool
	 */
	private static function disjoint( string $regex_rest, string $rest ): bool {
		$atom = '' !== $rest ? self::first_atom( $regex_rest ) : null;

		if ( null === $atom || self::optional( $atom[2] ) ) {
			return false;
		}

		if ( 'group' === $atom[0] ) {
			$alts = self::alternatives( $atom[1] );

			if ( null === $alts ) {
				return false;
			}

			if ( [] === array_filter( $alts, static fn( string $a ): bool => 1 !== preg_match( '/^[a-z0-9_-]+$/', $a ) ) ) {
				foreach ( $alts as $word ) {
					if ( str_starts_with( $rest, $word ) || str_starts_with( $word, rtrim( $rest, '/' ) ) ) {
						return false;
					}
				}

				return true;
			}

			$atom = 1 === count( $alts ) ? self::first_atom( $alts[0] ) : null;

			if ( null === $atom || self::optional( $atom[2] ) ) {
				return false;
			}
		}

		if ( ( 'class' === $atom[0] && '[0-9]' === $atom[1] ) || ( 'escape' === $atom[0] && '\\d' === $atom[1] ) ) {
			return 1 !== preg_match( '/^[0-9]/', $rest );
		}

		return false;
	}

	/**
	 * The first atom of a regex: [ group|class|escape|char, body (a group without its parentheses), quantifier ].
	 *
	 * @param string $re Regex.
	 * @return array{0: string, 1: string, 2: string}|null Null for a shape not parsed (lookaround, unbalanced).
	 */
	private static function first_atom( string $re ): ?array {
		if ( '' === $re ) {
			return null;
		}

		if ( '(' === $re[0] ) {
			$end = self::group_end( $re );

			if ( null === $end ) {
				return null;
			}

			$body = substr( $re, 1, $end - 1 );

			if ( str_starts_with( $body, '?:' ) ) {
				$body = substr( $body, 2 );
			} elseif ( str_starts_with( $body, '?' ) ) {
				return null;
			}

			return [ 'group', $body, self::quantifier( substr( $re, $end + 1 ) ) ];
		}

		if ( '[' === $re[0] ) {
			$end = strpos( $re, ']', 2 );

			return false === $end ? null : [ 'class', substr( $re, 0, $end + 1 ), self::quantifier( substr( $re, $end + 1 ) ) ];
		}

		$len = '\\' === $re[0] ? 2 : 1;

		return [ 1 === $len ? 'char' : 'escape', substr( $re, 0, $len ), self::quantifier( substr( $re, $len ) ) ];
	}

	/**
	 * Offset of the parenthesis closing the group that opens the regex, or null.
	 *
	 * @param string $re Regex starting with "(".
	 * @return int|null
	 */
	private static function group_end( string $re ): ?int {
		$depth = 0;
		$len   = strlen( $re );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $re[ $i ];

			if ( '\\' === $ch ) {
				++$i;
			} elseif ( '[' === $ch ) {
				$close = strpos( $re, ']', $i + 2 );

				if ( false === $close ) {
					return null;
				}

				$i = $close;
			} elseif ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch && 0 === --$depth ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * The top-level alternatives of a group body, or null when it holds a nested group or class.
	 *
	 * @param string $body Group body.
	 * @return list<string>|null
	 */
	private static function alternatives( string $body ): ?array {
		if ( 1 === preg_match( '/[()\[\]]/', $body ) ) {
			return 1 === preg_match( '/\|/', $body ) ? null : [ $body ];
		}

		return explode( '|', $body );
	}

	/**
	 * The quantifier at the start of a regex rest ('' for none).
	 *
	 * @param string $after Regex after an atom.
	 * @return string
	 */
	private static function quantifier( string $after ): string {
		return 1 === preg_match( '/^(?:[?*+]|\{[0-9]+(?:,[0-9]*)?\})\??/', $after, $m ) ? $m[0] : '';
	}

	/**
	 * Whether a quantifier lets its atom match nothing.
	 *
	 * @param string $q Quantifier.
	 * @return bool
	 */
	private static function optional( string $q ): bool {
		return '' !== $q && ( '?' === $q[0] || '*' === $q[0] || str_starts_with( $q, '{0' ) );
	}

	/**
	 * The rule core matches first for a path, as WP::parse_request() does (raw, then urldecoded).
	 *
	 * @param string        $path  Request path, root included (PATH_INFO); its end slashes are trimmed as core does.
	 * @param callable|null $pages Verbose page rules: fn( string $pagename ): bool, whether such a page exists.
	 * @return array{0:string,1:string}|null [ regex, query ]
	 */
	public function first_match( string $path, ?callable $pages = null ): ?array {
		$rel  = trim( $path, '/' );
		$file = '';

		// PATH_INFO permalinks: core prepends the requested file plus one slash once a rule starting with it is reached.
		if ( '' !== $this->root && str_starts_with( $rel . '/', $this->root ) ) {
			$file = rtrim( $this->root, '/' );
			$rel  = trim( substr( $rel, strlen( $file ) ), '/' );
		}

		$match = $rel;
		$heads = $this->heads ??= $this->index();
		$seen  = null;
		$dec   = '';
		$hits  = [];
		$i     = -1;

		foreach ( $this->all as $regex => $query ) {
			$m = [];
			++$i;

			if ( '' !== $file && str_starts_with( $regex, $file ) && $file !== $rel ) {
				$match = $file . '/' . $rel;
			}

			if ( $seen !== $match ) {
				$seen = $match;
				$dec  = urldecode( $match );
				$hits = [];
			}

			[ $gid, $lit ] = $heads[ $i ];

			$hits[ $gid ] ??= array_values( array_filter( $this->groups[ $gid ], static fn( string $a ): bool => str_starts_with( $match, $a ) || str_starts_with( $dec, $a ) ) );

			$may = false;

			foreach ( $hits[ $gid ] as $a ) {
				if ( str_starts_with( $match, $a . $lit ) || str_starts_with( $dec, $a . $lit ) ) {
					$may = true;
					break;
				}
			}

			// No head string starts the path: the anchored regex cannot match it (index()).
			if ( ! $may ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed plugin rule must not stop the self-test; core skips it too.
			if ( 1 !== @preg_match( '#^' . $regex . '#', $match, $m ) && 1 !== @preg_match( '#^' . $regex . '#', urldecode( $match ), $m ) ) {
				continue;
			}

			if ( null !== $pages && 1 === preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $v ) && ! $pages( (string) ( $m[ (int) $v[1] ] ?? '' ) ) ) {
				continue;
			}

			return [ (string) $regex, $query ];
		}

		return null;
	}

	/**
	 * Head strings per rule: a path the anchored rule matches starts with a head group alternative plus the literal
	 * after it. Any construct the scan cannot place exactly (top-level "|", options, verbs, \Q, \E, \c) gets [ 0, '' ]: always tried.
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	private function index(): array {
		$ids   = [];
		$heads = [];

		foreach ( array_keys( $this->all ) as $regex ) {
			$re = ltrim( (string) $regex, '^' );

			if ( ! self::plain_top( $re ) ) {
				$heads[] = [ 0, '' ];
				continue;
			}

			$group = self::head_group( $re );

			if ( null === $group ) {
				$heads[] = [ 0, self::literal_span( $re )[0] ];
				continue;
			}

			$key = implode( "\0", $group[0] );

			if ( ! isset( $ids[ $key ] ) ) {
				$ids[ $key ]    = count( $this->groups );
				$this->groups[] = $group[0];
			}

			$heads[] = [ $ids[ $key ], self::literal_span( substr( $re, $group[1] ) )[0] ];
		}

		return $heads;
	}

	/**
	 * Whether a regex has no top-level "|" and only constructs whose bytes this scan reads as PCRE does.
	 *
	 * @param string $re Rule regex without its leading "^".
	 * @return bool
	 */
	private static function plain_top( string $re ): bool {
		$depth = 0;
		$len   = strlen( $re );

		for ( $i = 0; $i < $len; ++$i ) {
			$c = $re[ $i ];

			if ( '\\' === $c ) {
				$n = $re[ $i + 1 ] ?? '';

				// \E is no item: a quantifier after it applies to the byte before ("\(\E?" = "\(?").
				if ( '' === $n || 'Q' === $n || 'E' === $n || 'c' === $n ) {
					return false;
				}

				++$i;
			} elseif ( '[' === $c ) {
				$end = self::class_end( $re, $i );

				if ( null === $end ) {
					return false;
				}

				$i = $end;
			} elseif ( '(' === $c ) {
				$n = $re[ $i + 1 ] ?? '';
				$o = $re[ $i + 2 ] ?? '';

				// Verbs and option settings ((?x) changes what "#", "[" and spaces mean) are not read here.
				if ( '*' === $n || ( '?' === $n && ( '' === $o || ! str_contains( ':=!<>P\'', $o ) ) ) ) {
					return false;
				}

				++$depth;
			} elseif ( ')' === $c ) {
				if ( --$depth < 0 ) {
					return false;
				}
			} elseif ( '|' === $c && 0 === $depth ) {
				return false;
			}
		}

		return 0 === $depth;
	}

	/**
	 * Offset of the "]" that closes the character class opening at $i, or null when unsure.
	 *
	 * @param string $re Regex.
	 * @param int    $i  Offset of "[".
	 * @return int|null
	 */
	private static function class_end( string $re, int $i ): ?int {
		$len = strlen( $re );
		$j   = $i + 1;

		if ( '^' === ( $re[ $j ] ?? '' ) ) {
			++$j;
		}

		// A "]" first in the class is a member.
		if ( ']' === ( $re[ $j ] ?? '' ) ) {
			++$j;
		}

		for ( ; $j < $len; ++$j ) {
			$c = $re[ $j ];
			$n = $re[ $j + 1 ] ?? '';

			if ( '\\' === $c ) {
				if ( '' === $n || 'Q' === $n || 'c' === $n ) {
					return null;
				}

				++$j;
			} elseif ( '[' === $c && '' !== $n && str_contains( ':.=', $n ) ) {
				return null;
			} elseif ( ']' === $c ) {
				return $j;
			}
		}

		return null;
	}

	/**
	 * A leading group of literal alternatives, "(a|b)" or "(?:a|b)" not quantified: [ alternatives, offset after it ].
	 *
	 * @param string $re Regex without its leading "^".
	 * @return array{0: list<string>, 1: int}|null
	 */
	private static function head_group( string $re ): ?array {
		if ( ! str_starts_with( $re, '(' ) ) {
			return null;
		}

		$i = str_starts_with( $re, '(?:' ) ? 3 : 1;

		if ( 1 === $i && '?' === ( $re[1] ?? '' ) ) {
			return null;
		}

		$alts = [ '' ];
		$k    = 0;
		$len  = strlen( $re );

		for ( ; $i < $len; ++$i ) {
			$c = $re[ $i ];

			if ( ')' === $c ) {
				$q = $re[ $i + 1 ] ?? '';

				return '' !== $q && str_contains( '?*+{', $q ) ? null : [ $alts, $i + 1 ];
			}

			if ( '|' === $c ) {
				$alts[] = '';
				++$k;
				continue;
			}

			if ( '\\' === $c ) {
				$c = $re[ $i + 1 ] ?? '';

				if ( '' === $c || 1 === preg_match( '/^[A-Za-z0-9]$/', $c ) ) {
					return null;
				}

				++$i;
			} elseif ( str_contains( '^$.[]()?*+{}', $c ) ) {
				return null;
			}

			$alts[ $k ] .= $c;
		}

		return null;
	}

	/**
	 * Fingerprint (md5) of the stored option value the rules were read from (map `rw`).
	 *
	 * @param string $raw Stored option bytes.
	 * @return string
	 */
	public static function fingerprint( string $raw ): string {
		return md5( $raw );
	}
}
