<?php
/**
 * URL bases compiler.
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
 * Builds the runtime map (format v1, DESIGN-step1 3.2) and the report the screens show, from one set of inputs.
 *
 * Never writes. A value that fails a rule is left out of the map and reported with its reason.
 *
 * @phpstan-import-type KeyDesc from Registry
 * @phpstan-import-type ValueSet from Values
 * @phpstan-type Work array{desc: KeyDesc, eff: string, front: string, base: string, own: array<string, string>, primary: array{0: string, 1: string}, on: bool, bare: bool}
 * @phpstan-type Accepted array{0: string, 1: string, 2: string}
 * @phpstan-type Cell array{value: string, status: string, reason: string, detail: string}
 * @phpstan-type HistRow array{value: string, status: string, reason: string, detail: string, kind: string}
 * @phpstan-type KeyRow array{label: string, grp: string, role: string, on: bool, declared: bool, status: string, reason: string, detail: string, eff: string, stale: bool, derived: bool, registered: string, bare: bool, bare_by: string}
 * @phpstan-type TestRow array{sample: string, swapped: string|null, original: array<string, string|null>}
 * @phpstan-type Report array{state: string, keys: array<string, KeyRow>, entries: array<string, array<string, Cell>>, code?: array<string, array<string, Cell>>, history: array<string, array<string, list<HistRow>>>, providers: list<array{provider: string, code: string, detail: string}>, unv: list<string>, selftest: array<string, TestRow>, caps: array{keys: int, languages: int, bytes: int, max_per_segment: int, max_bytes: int}, errors: list<string>, groups: array<string, string>}
 * @phpstan-type Durable array{grp: string, reg?: list<string>, hist?: array<string, list<string>>}
 * @phpstan-type Build array{map: array<string, mixed>|null, report: Report, fp: string, durable: array<string, Durable>}
 *
 * @internal
 */
final class Compiler {

	public const MAX_ENTRIES_PER_SEGMENT = 16;

	public const MAX_UNVERIFIED = 16;

	public const MAX_BYTES = 65536;

	/**
	 * Most `perflocale/url_bases/max_map_bytes` may allow: every request unserializes the autoloaded map.
	 */
	public const CEILING_BYTES = 262144;

	public const SHOP_CHILDREN_CAP = 200;

	public const MAX_SHARED_PER_KEY = 8;

	/**
	 * Keys that have rules: descriptor plus eff, front, base, own rules, switch.
	 *
	 * @var array<string, Work>
	 */
	private array $work = [];

	/**
	 * Declared shared namespaces under each key's base (map keys[k].shr): paths relative to eff by first segment.
	 *
	 * @var array<string, array<string, list<string>>>
	 */
	private array $shr = [];

	/**
	 * Accepted alias entries by key: [ lang, value, kind ].
	 *
	 * @var array<string, array<int, Accepted>>
	 */
	private array $entries = [];

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private array $report;

	/**
	 * Rules that cannot be proven disjoint, by regex.
	 *
	 * @var array<string, true>
	 */
	private array $unv = [];

	/**
	 * Shop exceptions (map keys[k].exc) of the keys on the translated shop URI: key => lang => first segment => URIs.
	 *
	 * @var array<string, array<string, array<string, list<string>>>>
	 */
	private array $exc = [];

	/**
	 * Shop-derived keys whose base is the shop URI itself (no tail), by key.
	 *
	 * @var array<string, true>
	 */
	private array $at_shop = [];

	/**
	 * Shop child URIs relative to the translated shop URI, by language (read once for the single and archive key).
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $children = [];

	/**
	 * Shop-derived values by key: lang => value (keys that follow the shop page only).
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $derived = [];

	/**
	 * Values the shop page and its translations hold now, in every language, by shop-derived key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $shop_uris = [];

	/**
	 * Accepted values set by code (3.13): key => lang => value ('' = the registered base).
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $code = [];

	/**
	 * Current and history values of the stored map by key, for rule 4 (CodeValues::held()).
	 *
	 * @var array<string, list<string>>
	 */
	private array $held = [];

	/**
	 * Typed values never live that would serve only as aliases (an off key's, the default language's): key => lang =>
	 * value. They neither redirect nor reserve their word.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $unused = [];

	/**
	 * Old bases carried forward that the stored values do not hold yet, by key (written by Publication).
	 *
	 * @var array<string, Durable>
	 */
	private array $durable = [];

	/**
	 * Inputs read beyond Inputs (shop data), for the fingerprint.
	 *
	 * @var array<string, mixed>
	 */
	private array $extra = [];

	/**
	 * Stored rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * The permalink structure's front and first tag, for the content check of rule 2.
	 *
	 * @var array{front: string, kind: string, taxonomy: string}
	 */
	private array $lead;

	/**
	 * Core permalink tags: kind (post, author, digits, term), taxonomy, query var.
	 */
	private const TAGS = [
		'%postname%' => [ 'post', '', 'name' ],
		'%post_id%'  => [ 'digits', '', 'p' ],
		'%author%'   => [ 'author', '', 'author_name' ],
		'%year%'     => [ 'digits', '', 'year' ],
		'%monthnum%' => [ 'digits', '', 'monthnum' ],
		'%day%'      => [ 'digits', '', 'day' ],
		'%hour%'     => [ 'digits', '', 'hour' ],
		'%minute%'   => [ 'digits', '', 'minute' ],
		'%second%'   => [ 'digits', '', 'second' ],
		'%category%' => [ 'term', 'category', 'category_name' ],
		'%post_tag%' => [ 'term', 'post_tag', 'tag' ],
	];

	/**
	 * Keys (or `*`) whose old bases in the stored map are not carried forward, and forget entries (Values::entry_id()).
	 *
	 * @var list<string>
	 */
	private array $forget;

	/**
	 * Compiler for one set of inputs.
	 *
	 * @param Inputs       $in      Inputs.
	 * @param Content      $content Content lookups.
	 * @param list<string> $forget  Keys forgotten besides the values' pending `forget` list; `*` for all.
	 */
	public function __construct( private readonly Inputs $in, private readonly Content $content, array $forget = [] ) {
		global $wp_rewrite;

		$this->forget = array_merge( $forget, $in->values['forget'] ?? [] );

		$generic = [];
		$pt_vars = [];

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( Typed::arr( $wp_rewrite->endpoints ) as $ep ) {
				$generic[] = Typed::str( Typed::arr( $ep )[2] ?? null );
			}
		}

		foreach ( get_post_types( [], 'objects' ) as $pto ) {
			$pt_vars[] = Typed::str( $pto->query_var );
		}

		$structure  = Typed::str( $in->option( 'permalink_structure' ) );
		$this->lead = self::lead( $structure );
		$ident      = $this->lead['vars'];
		$generic    = array_merge( $generic, $pt_vars, $ident );
		$root       = 1 === preg_match( '#^/*index\.php#', $structure ) ? 'index.php/' : '';

		unset( $this->lead['vars'] );

		$this->rules  = new Rules( Typed::arr( $in->option( 'rewrite_rules' ) ), \PerfLocale\Router\UrlConverter::query_var(), array_values( array_filter( $generic ) ), $root, array_values( array_filter( $pt_vars ) ), $ident );
		$this->report = [
			'state'     => 'inactive',
			'keys'      => [],
			'entries'   => [],
			'history'   => [],
			'providers' => $in->registry->issues(),
			'unv'       => [],
			'selftest'  => [],
			'caps'      => [
				'keys'            => 0,
				'languages'       => 0,
				'bytes'           => 0,
				'max_per_segment' => 0,
				'max_bytes'       => self::MAX_BYTES,
			],
			'errors'    => [],
			'groups'    => $in->registry->groups(),
		];
	}

	/**
	 * The structure's front (`index.php` root included) and first tag; its tags' query vars count as generic when rule 2
	 * can check that first tag. Lifecycle's content listeners share it.
	 *
	 * @param string $structure Permalink structure.
	 * @return array{front: string, kind: string, taxonomy: string, vars: list<string>}
	 */
	public static function lead( string $structure ): array {
		$pos  = strpos( $structure, '%' );
		$out  = [
			'front'    => false === $pos ? '' : Validator::norm_path( trim( substr( $structure, 0, $pos ), '/' ) ),
			'kind'     => 'other',
			'taxonomy' => '',
			'vars'     => [],
		];
		$tags = preg_match_all( '/%[a-z0-9_]+%/', $structure, $m ) ? $m[0] : [];
		$vars = [];

		foreach ( $tags as $i => $tag ) {
			$def = self::TAGS[ $tag ] ?? null;

			if ( null === $def && function_exists( 'get_taxonomy' ) ) {
				$tax = get_taxonomy( trim( $tag, '%' ) );
				$def = $tax instanceof \WP_Taxonomy && is_string( $tax->query_var ) && '' !== $tax->query_var ? [ 'term', $tax->name, $tax->query_var ] : null;
			}

			if ( null === $def ) {
				// A plugin tag first: rule 2 cannot check it, so its rules stay unverified.
				if ( 0 === $i ) {
					return $out;
				}
				continue;
			}

			if ( 0 === $i ) {
				$out['kind']     = $def[0];
				$out['taxonomy'] = $def[1];
			}

			$vars[] = $def[2];
		}

		$out['vars'] = 'other' === $out['kind'] ? [] : $vars;

		return $out;
	}

	/**
	 * Compile.
	 *
	 * @return Build
	 */
	public function build(): array {
		$values = $this->in->values;
		$langs  = $this->languages();

		if ( $this->in->read_failed ) {
			$this->report['errors'][] = 'read_failed';
		}

		if ( ! $this->rules->exists() ) {
			$this->report['errors'][] = 'rules_missing';
		}

		$this->collect_keys( $values );

		foreach ( $this->work as $key => $w ) {
			$derived = $this->shop_values( $key, $w, $langs );

			if ( null !== $derived ) {
				$this->derived[ $key ]                   = $derived;
				$this->report['keys'][ $key ]['derived'] = true;
			}
		}

		$this->unused = $this->unused_values( $values );
		$this->held   = CodeValues::held( $this->in->prev_map, $this->forget );

		// Values set by code: checked as typed ones, first against the stored values, then against each other.
		if ( has_filter( CodeValues::HOOK ) ) {
			$code       = new CodeValues();
			$check      = fn( string $key, string $value ): array => $this->check( $key, $this->work[ $key ], $value, false );
			$this->code = $code->validate( $code->ask( $this->work, $this->derived, $this->in->languages, $values ), $check );
			$this->code = $code->validate( $this->code, $check );

			if ( [] !== $code->report() ) {
				$this->report['code'] = $code->report();
				$this->extra['code']  = $code->inputs();
			}
		}

		foreach ( $this->work as $key => $w ) {
			$this->candidates( $key, $w, $values, $langs );
		}

		$this->check_unverified();
		$this->check_caps( $langs );
		$this->check_shared();
		$this->self_test( $langs );

		$map = $this->assemble( $values );
		$cap = self::max_bytes();

		if ( self::MAX_BYTES !== $cap ) {
			$this->extra['cap'] = $cap;
		}

		$this->report['caps']['max_bytes'] = $cap;

		$fp = $this->in->fingerprint( $this->extra );

		if ( null !== $map ) {
			$map['fp'] = $fp;
			$bytes     = strlen( serialize( $map ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The stored size, as update_option() writes it.

			$this->report['caps']['bytes'] = $bytes;

			if ( $bytes > $cap ) {
				$this->report['errors'][] = 'cap_size';
			}
		}

		// A failed content lookup proves no address free and no page or shop path absent.
		if ( $this->content->failed && ! in_array( 'read_failed', $this->report['errors'], true ) ) {
			$this->report['errors'][] = 'read_failed';
		}

		$this->report['state'] = null === $map ? 'inactive' : Typed::str( $map['state'] );
		$this->report['unv']   = array_keys( $this->unv );

		return [
			'map'     => $map,
			'report'  => $this->report,
			'fp'      => $fp,
			'durable' => $this->durable,
		];
	}

	/**
	 * The map's byte cap: the filtered value when it is a positive int, at most CEILING_BYTES.
	 *
	 * @return int
	 */
	private static function max_bytes(): int {
		$cap = self::cap_filter();

		return is_int( $cap ) && $cap > 0 ? min( $cap, self::CEILING_BYTES ) : self::MAX_BYTES;
	}

	/**
	 * The filtered cap; callbacks may return any type.
	 *
	 * @return mixed
	 */
	private static function cap_filter(): mixed {
		/**
		 * Most bytes of the serialized URL bases map; read only when PerfLocale compiles. Every page request loads and
		 * unserializes the map, so Site Health names its size and per-page cost over 64 KB.
		 *
		 * @hook  perflocale/url_bases/max_map_bytes
		 * @since 1.0.8
		 * @api
		 *
		 * @param int $bytes Default 65536; a non-int or a value below 1 is ignored, values above 262144 count as 262144.
		 */
		return apply_filters( 'perflocale/url_bases/max_map_bytes', self::MAX_BYTES );
	}

	/**
	 * Active non-default languages: slug => id.
	 *
	 * @return array<string, int>
	 */
	private function languages(): array {
		$out = [];

		foreach ( $this->in->languages as $lang ) {
			if ( $lang['is_active'] && ! $lang['is_default'] ) {
				$out[ $lang['slug'] ] = $lang['id'];
			}
		}

		return $out;
	}

	/**
	 * A report row for a key.
	 *
	 * @param string $label Label.
	 * @param string $grp   Group.
	 * @param string $role  Role.
	 * @return KeyRow
	 */
	private static function key_row( string $label, string $grp, string $role ): array {
		return [
			'label'      => $label,
			'grp'        => $grp,
			'role'       => $role,
			'on'         => false,
			'declared'   => true,
			'status'     => 'ok',
			'reason'     => '',
			'detail'     => '',
			'eff'        => '',
			'stale'      => false,
			'derived'    => false,
			'registered' => '',
			'bare'       => false,
			'bare_by'    => '',
		];
	}

	/**
	 * Declared keys, plus stored keys whose provider is gone (kept as aliases only while their type is registered).
	 *
	 * @param ValueSet $values Values.
	 * @return void
	 */
	private function collect_keys( array $values ): void {
		$keys   = $this->in->registry->keys();
		$groups = $this->in->registry->groups();

		foreach ( $values['keys'] as $key => $entry ) {
			$stored_grp = $entry['grp'] ?? 'core';
			$gone       = 'core' !== $stored_grp && ! isset( $groups[ $stored_grp ] );
			// An undeclared shop-derived key stores no values, only its registered bases: its published ones stay aliases.
			$has = ! empty( $entry['tr'] ) || ! empty( $entry['hist'] ) || ! empty( $entry['reg'] );

			if ( ! $has || ( isset( $keys[ $key ] ) && ! ( $gone && 'core' === $keys[ $key ]['grp'] ) ) ) {
				continue;
			}

			$name = explode( ':', $key, 2 )[1] ?? '';
			$desc = Registry::describe( $key, $stored_grp, $key, [ $name ], [ $name ] );

			if ( is_array( $desc ) ) {
				$desc['declared'] = false;
				$keys[ $key ]     = $desc;
				continue;
			}

			$row                          = self::key_row( $key, $stored_grp, '' );
			$row['status']                = 'undeclared';
			$row['reason']                = $desc;
			$this->report['keys'][ $key ] = $row;
		}

		$structure = Typed::str( $this->in->option( 'permalink_structure' ) );
		$pos       = strpos( $structure, '%' );
		$front     = false === $pos ? '' : Validator::norm_path( trim( substr( $structure, 0, $pos ), '/' ) );
		$root      = 1 === preg_match( '#^/*index\.php#', $structure ) ? 'index.php' : '';

		foreach ( $keys as $key => $desc ) {
			// The registered base is the literal part of the slug, before a %tag% segment (WooCommerce %product_cat%).
			$parts    = explode( '/', $desc['slug'] );
			$slug     = Validator::norm_path( implode( '/', array_slice( $parts, 0, self::tag_index( $parts ) ) ) );
			$prefix   = $desc['with_front'] ? $front : $root;
			$expected = trim( ( '' !== $prefix ? $prefix . '/' : '' ) . $slug, '/' );
			$own      = $this->rules->own_rules( $desc );
			$based    = $this->rules->based( $own, $expected );
			$eff      = Rules::eff( $based, $this->rules->root() );
			$primary  = Rules::primary( $based, $this->rules->root() );
			$entry    = $values['keys'][ $key ] ?? [];
			$on      = $values['on'] && ( $values['groups'][ $desc['grp'] ] ?? true ) && ( $entry['on'] ?? true ) && $desc['declared'];
			$row     = self::key_row( $desc['label'], $desc['grp'], $desc['role'] );

			$row['on']       = $on;
			$row['declared'] = $desc['declared'];

			if ( null === $eff || null === $primary ) {
				$row['status']                = 'no_rules';
				$row['reason']                = 'no_rules';
				$this->report['keys'][ $key ] = $row;
				continue;
			}

			$base = $slug;

			if ( $expected !== $eff ) {
				// The stored rules win over the live registration; the screens ask for a permalink save.
				$row['stale'] = true;
				$base         = ( '' !== $prefix && str_starts_with( $eff, $prefix . '/' ) ) ? substr( $eff, strlen( $prefix ) + 1 ) : $eff;
				$prefix       = (string) substr( $eff, 0, max( 0, strlen( $eff ) - strlen( $base ) - 1 ) );
			}

			// Every published address must be one a forget can name (Values::is_address()): its own base is never aliased.
			if ( '' !== $base && ! Values::is_address( $base ) ) {
				$row['status']                = 'suspended';
				$row['reason']                = 'cap_address';
				$row['detail']                = (string) Values::MAX_ADDRESS_BYTES;
				$this->report['keys'][ $key ] = $row;
				continue;
			}

			// The runtime tries Runtime::DEPTH segments for a base start: a longer front would compile links it cannot match.
			if ( '' !== $prefix && substr_count( $prefix, '/' ) + 1 >= Runtime::DEPTH ) {
				$row['status']                = 'suspended';
				$row['reason']                = 'deep_front';
				$row['detail']                = (string) ( Runtime::DEPTH - 1 );
				$this->report['keys'][ $key ] = $row;
				continue;
			}

			[ $bare, $bare_by ] = $this->bare( $desc, $eff, $own );

			$this->work[ $key ] = [
				'desc'    => $desc,
				'eff'     => $eff,
				'front'   => $prefix,
				'base'    => $base,
				'own'     => $own,
				'primary' => $primary,
				'on'      => $on,
				'bare'    => $bare,
			];

			$row['eff']                   = $eff;
			$row['registered']            = $base;
			$row['bare']                  = $bare;
			$row['bare_by']               = $bare_by;
			$this->report['keys'][ $key ] = $row;
		}

		// A single/archive pair on one base (WooCommerce /shop/%product_cat%/) is one switch: the runtime cannot tell
		// their alias entries apart, so one key off would be shadowed by the other.
		$off = [];

		foreach ( $this->work as $key => $w ) {
			$pair = $this->work[ 'pt:' . $w['desc']['object'] ] ?? null;

			if ( 'archive' === $w['desc']['role'] && null !== $pair && $pair['base'] === $w['base'] && $pair['front'] === $w['front'] && ! ( $w['on'] && $pair['on'] ) ) {
				$off[] = $key;
				$off[] = 'pt:' . $w['desc']['object'];
			}
		}

		foreach ( $off as $key ) {
			if ( isset( $this->work[ $key ] ) ) {
				$this->work[ $key ]['on'] = false;
			}

			if ( isset( $this->report['keys'][ $key ] ) ) {
				$this->report['keys'][ $key ]['on'] = false;
			}
		}
	}

	/**
	 * Map `bare`: whether the key itself routes its bare base (`eff/`, nothing after: an archive), and who does if not.
	 *
	 * A single key counts its post type's archive rule on the same base too.
	 *
	 * @param KeyDesc               $desc Key descriptor.
	 * @param string                $eff  Effective base.
	 * @param array<string, string> $own  Own rules.
	 * @return array{0: bool, 1: string} [ bare, '' | 'page:<path>' | 'post:<slug>' | 'rule:<regex>' ]
	 */
	private function bare( array $desc, string $eff, array $own ): array {
		$hit = $this->rules->first_match( $eff . '/', SelfTest::verbose_pages( $this->content ) );

		if ( null === $hit ) {
			return [ false, '' ];
		}

		$vars    = Rules::query_vars( $hit[1] );
		$archive = 'single' === $desc['role'] && ( $vars['post_type'] ?? null ) === $desc['object'] && ! isset( $vars['name'] ) && ! isset( $vars['pagename'] ) && ( false === $desc['qv'] || ! isset( $vars[ $desc['qv'] ] ) );

		if ( isset( $own[ $hit[0] ] ) || $archive ) {
			return [ true, '' ];
		}

		// A catch-all owns the address only through content: a page or a root post of that name.
		if ( $this->rules->generic( $hit[1] ) ) {
			$path = $this->unrooted( $eff );

			if ( null !== $this->content->page_with_prefix( $path ) ) {
				return [ false, 'page:' . $path ];
			}

			return [ false, isset( $vars['name'] ) && ! str_contains( $path, '/' ) && null !== $this->content->root_post( $path ) ? 'post:' . $path : '' ];
		}

		return [ false, 'rule:' . $hit[0] ];
	}

	/**
	 * A path without the `index.php/` root: content (page paths, slugs) never holds it.
	 *
	 * @param string $path Path in norm form.
	 * @return string
	 */
	private function unrooted( string $path ): string {
		$root = $this->rules->root();

		return '' !== $root && str_starts_with( $path . '/', $root ) ? trim( substr( $path, strlen( $root ) ), '/' ) : $path;
	}

	/**
	 * Index of the first segment holding a rewrite tag, or the segment count.
	 *
	 * @param list<string> $parts Slug segments.
	 * @return int
	 */
	private static function tag_index( array $parts ): int {
		foreach ( $parts as $i => $seg ) {
			if ( 1 === preg_match( '/%[a-z0-9_]+%/', $seg ) ) {
				return $i;
			}
		}

		return count( $parts );
	}

	/**
	 * Validate and record a key's current, history and previous values.
	 *
	 * @param string             $key    Key.
	 * @param Work               $w      Work row.
	 * @param ValueSet           $values Values.
	 * @param array<string, int> $langs  Active non-default languages.
	 * @return void
	 */
	private function candidates( string $key, array $w, array $values, array $langs ): void {
		$entry   = $values['keys'][ $key ] ?? [];
		$derived = $this->derived[ $key ] ?? null;
		$prev    = $this->previous_entries( $key );
		// Values the map published stay history only while the stored values know the key (a wiped option drops them).
		$pub = isset( $values['keys'][ $key ] ) ? $prev : [];

		$durable = [];

		// A shop-derived key that is off adds no current value (the shop page's own URIs serve as pages again), and the
		// archive key no old ones either; the product key's published values stay aliases until "Forget old URLs".
		$quiet = null !== $derived && ! $w['on'];

		foreach ( array_keys( $langs ) as $lang ) {
			if ( null !== $derived ) {
				$current = $quiet ? '' : $derived[ $lang ] ?? '';
				$hist    = array_merge( $prev['current'][ $lang ] ?? [], $prev['history'][ $lang ] ?? [], $entry['hist'][ $lang ] ?? [] );
				$hist    = array_slice( array_values( array_diff( array_unique( $hist ), [ $current ] ) ), 0, Values::HISTORY_CAP );

				if ( $hist !== ( $entry['hist'][ $lang ] ?? [] ) ) {
					$durable['hist'][ $lang ] = $hist;
				}
			} else {
				$stored  = $entry['tr'][ $lang ] ?? '';
				$current = $this->code[ $key ][ $lang ] ?? $stored;
				$hist    = CodeValues::history( $pub['current'][ $lang ] ?? [], $pub['history'][ $lang ] ?? [], $entry['hist'][ $lang ] ?? [], [ $current, $stored, $w['base'] ] );

				if ( $hist !== ( $entry['hist'][ $lang ] ?? [] ) ) {
					$durable['hist'][ $lang ] = $hist;
				}

				// A typed value under a code value stays the alias it was while the map holds it (Forget drops it).
				if ( $stored !== $current && in_array( $stored, array_merge( $prev['current'][ $lang ] ?? [], $prev['history'][ $lang ] ?? [] ), true ) ) {
					$hist[] = $stored;
				}

				// Off and never live: checked for the screen, never in the map.
				if ( ! isset( $this->code[ $key ][ $lang ] ) && isset( $this->unused[ $key ][ $lang ] ) ) {
					[ $reason, $detail ] = $this->check( $key, $w, $stored, false );

					$this->report['entries'][ $key ][ $lang ] = [
						'value'  => $stored,
						'status' => '' === $reason ? 'unused' : 'suspended',
						'reason' => $reason,
						'detail' => $detail,
					];

					$current = '';
				}

				// A typed value new to a cell whose record is full is refused: no recorded address is ever dropped.
				if ( '' !== $current && $current === $stored && $current !== $w['base'] && $this->pub_full( $key, $lang, $current ) ) {
					$this->report['entries'][ $key ][ $lang ] = [
						'value'  => $current,
						'status' => 'suspended',
						'reason' => 'cap_provenance',
						'detail' => (string) Values::PUB_CAP,
					];

					$current = '';
				}
			}

			if ( $quiet && '' !== ( $derived[ $lang ] ?? '' ) ) {
				[ $reason, $detail ] = $this->check( $key, $w, $derived[ $lang ], true );

				$this->report['entries'][ $key ][ $lang ] = [
					'value'  => $derived[ $lang ],
					'status' => '' === $reason ? 'ok' : 'suspended',
					'reason' => $reason,
					'detail' => $detail,
				];
			}

			if ( '' !== $current && $current !== $w['base'] ) {
				$this->accept( $key, $w, $lang, $current, 'current', null !== $derived );
			}

			foreach ( $quiet && 'archive' === $w['desc']['role'] ? [] : $hist as $old ) {
				if ( '' !== $old && $old !== $current && $old !== $w['base'] ) {
					$this->accept( $key, $w, $lang, $old, 'history', $this->owned_by_shop( $key, $old ) );
				}
			}
		}

		// The default language uses the registered base; its values from when it was not the default stay aliases (rule 8).
		$def = $this->in->default_language();

		if ( null !== $def ) {
			$lang = $def['slug'];
			$kept = null === $derived ? CodeValues::history( $pub['current'][ $lang ] ?? [], $pub['history'][ $lang ] ?? [], $entry['hist'][ $lang ] ?? [], [ $entry['tr'][ $lang ] ?? '', $w['base'] ] ) : [];
			$old  = null !== $derived
				? array_merge( $prev['current'][ $lang ] ?? [], $prev['history'][ $lang ] ?? [], $entry['hist'][ $lang ] ?? [] )
				: array_merge( isset( $entry['tr'][ $lang ] ) && ! isset( $this->unused[ $key ][ $lang ] ) ? [ $entry['tr'][ $lang ] ] : [], $kept );

			if ( null === $derived && $kept !== ( $entry['hist'][ $lang ] ?? [] ) ) {
				$durable['hist'][ $lang ] = $kept;
			}
			$old  = array_slice( array_values( array_diff( array_unique( $old ), [ '', $w['base'] ] ) ), 0, Values::HISTORY_CAP );

			if ( null !== $derived && $old !== ( $entry['hist'][ $lang ] ?? [] ) ) {
				$durable['hist'][ $lang ] = $old;
			}

			foreach ( $quiet && 'archive' === $w['desc']['role'] ? [] : $old as $value ) {
				$this->accept( $key, $w, $lang, $value, 'history', $this->owned_by_shop( $key, $value ) );
			}
		}

		foreach ( $entry['tr'] ?? [] as $lang => $value ) {
			if ( ! isset( $langs[ $lang ] ) && null === $derived ) {
				$this->report['entries'][ $key ][ $lang ] = [
					'value'  => $value,
					'status' => 'inactive',
					'reason' => '',
					'detail' => '',
				];
			}
		}

		// One previous registered base: the newest the stored map or the values know that differs from today's.
		foreach ( $prev['previous'] as $old ) {
			if ( $old !== $w['base'] ) {
				if ( ! Validator::overlaps( $old, $w['base'] ) ) {
					$this->accept( $key, $w, '*', $old, 'previous', false );
				}
				break;
			}
		}

		// The registered bases go to the values too, so a lost map keeps the previous one (Publication stores them).
		$reg = array_slice( array_values( array_unique( array_merge( [ $w['base'] ], $prev['previous'] ) ) ), 0, Values::REG_CAP );

		if ( $reg !== ( $entry['reg'] ?? [] ) ) {
			$durable['reg'] = $reg;
		}

		$has = ! empty( $entry['tr'] ) || ! empty( $entry['hist'] ) || ! empty( $entry['reg'] ) || [] !== ( $this->entries[ $key ] ?? [] ) || null !== $derived;

		if ( [] !== $durable && $has ) {
			$this->durable[ $key ] = [ 'grp' => $w['desc']['grp'] ] + $durable;
		}
	}

	/**
	 * Typed values that were never live and would serve only as aliases: of a key that is off, or of the default
	 * language. Live is what the stored map serves or `pub` recorded; unsure (a failed read, an unreadable map, a record
	 * behind the map, a forgotten key's map) counts as live.
	 *
	 * @param ValueSet $values Values.
	 * @return array<string, array<string, string>>
	 */
	private function unused_values( array $values ): array {
		$out = [];

		if ( $this->in->map_unread || $this->in->read_failed || Values::pub_behind( $values, $this->in->prev_map, $this->forget ) ) {
			return $out;
		}

		$def   = $this->in->default_language()['slug'] ?? '';
		$langs = array_column( $this->in->languages, 'slug' );

		foreach ( $this->work as $key => $w ) {
			if ( isset( $this->derived[ $key ] ) || in_array( '*', $this->forget, true ) || in_array( $key, $this->forget, true ) ) {
				continue;
			}

			$prev   = $this->previous_entries( $key );
			$served = [ $key => $prev['current'] ];

			foreach ( $prev['history'] as $l => $list ) {
				$served[ $key ][ $l ] = array_merge( $served[ $key ][ $l ] ?? [], $list );
			}

			$live = Values::witnesses( $values, $served );

			foreach ( $values['keys'][ $key ]['tr'] ?? [] as $lang => $segs ) {
				if ( ( ! $w['on'] || $lang === $def ) && ! Values::was_live( $live, $key, $lang, $segs, $langs ) ) {
					$out[ $key ][ $lang ] = $segs;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether the published record of a key and language is full and holds neither `$value` nor does the stored map.
	 *
	 * @param string $key   Key.
	 * @param string $lang  Language slug.
	 * @param string $value Value.
	 * @return bool
	 */
	private function pub_full( string $key, string $lang, string $value ): bool {
		$pub  = $this->in->values['pub'][ $key ][ $lang ] ?? [];
		$prev = $this->previous_entries( $key );

		return 2 === $this->in->values['v'] && count( $pub ) >= Values::PUB_CAP && ! in_array( $value, array_merge( $pub, $prev['current'][ $lang ] ?? [], $prev['history'][ $lang ] ?? [] ), true );
	}

	/**
	 * Whether a shop-derived key's value is the address of the shop page or one of its translations now (no page check).
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return bool
	 */
	private function owned_by_shop( string $key, string $value ): bool {
		return in_array( $value, $this->shop_uris[ $key ] ?? [], true );
	}

	/**
	 * Old values of a key by kind: the stored map's (none while it is forgotten), and the registered bases the values keep.
	 *
	 * @param string $key Key.
	 * @return array{current: array<string, list<string>>, history: array<string, list<string>>, previous: list<string>}
	 */
	private function previous_entries( string $key ): array {
		$out = [
			'current'  => [],
			'history'  => [],
			'previous' => [],
		];

		// A forgotten key keeps only the registered bases its values hold (a Replace import's file).
		$map = in_array( '*', $this->forget, true ) || in_array( $key, $this->forget, true ) ? [] : ( $this->in->prev_map ?? [] );

		$registered = [];
		$previous   = [];

		foreach ( Typed::arr( $map['alias'] ?? null ) as $list ) {
			foreach ( Typed::arr( $list ) as $e ) {
				$e = Typed::map( $e );

				if ( ( $e['key'] ?? '' ) !== $key || ! is_array( $e['segs'] ?? null ) ) {
					continue;
				}

				$segs = implode( '/', Typed::strs( $e['segs'] ) );
				$kind = Typed::str( $e['kind'] ?? null );
				$lang = Typed::str( $e['lang'] ?? null );

				// A confirmed forget entry leaves the map too (a previous registered base is lang `*`).
				if ( in_array( Values::entry_id( $key, 'registered' === $kind || 'previous' === $kind ? '*' : $lang, $segs ), $this->forget, true ) ) {
					continue;
				}

				if ( 'registered' === $kind ) {
					$registered[] = $segs;
				} elseif ( 'previous' === $kind ) {
					$previous[] = $segs;
				} elseif ( 'current' === $kind || 'history' === $kind ) {
					$out[ $kind ][ $lang ][] = $segs;
				}
			}
		}

		$out['previous'] = array_values( array_unique( array_merge( $registered, $this->in->values['keys'][ $key ]['reg'] ?? [], $previous ) ) );

		return $out;
	}

	/**
	 * Run rules 1-5 on one value and record it as accepted or suspended.
	 *
	 * @param string $key     Key.
	 * @param Work   $w       Work row.
	 * @param string $lang    Language slug, or `*`.
	 * @param string $value   Value (norm form, relative to the front).
	 * @param string $kind    current, history or previous.
	 * @param bool   $derived An address the shop page group holds now (no page check: the shop page owns it).
	 * @return void
	 */
	private function accept( string $key, array $w, string $lang, string $value, string $kind, bool $derived ): void {
		[ $reason, $detail, $unv ] = $this->check( $key, $w, $value, $derived, $kind );

		$status = '' === $reason ? 'ok' : 'suspended';

		if ( 'current' === $kind ) {
			$this->report['entries'][ $key ][ $lang ] = [
				'value'  => $value,
				'status' => $status,
				'reason' => $reason,
				'detail' => $detail,
			];
		} else {
			$this->report['history'][ $key ][ $lang ][] = [
				'value'  => $value,
				'status' => $status,
				'reason' => $reason,
				'detail' => $detail,
				'kind'   => $kind,
			];
		}

		if ( '' !== $reason ) {
			return;
		}

		foreach ( $unv as $regex ) {
			$this->unv[ $regex ] = true;
		}

		$this->entries[ $key ][] = [ $lang, $value, $kind ];
	}

	/**
	 * Rules 1-5 (DESIGN-step1 3.10) for one value.
	 *
	 * @param string $key     Key.
	 * @param Work   $w       Work row.
	 * @param string $value   Value.
	 * @param bool   $derived Follows the shop page.
	 * @param string $kind    current, history or previous.
	 * @return array{0: string, 1: string, 2: list<string>} [ reason, detail, unverified rules ]
	 */
	private function check( string $key, array $w, string $value, bool $derived, string $kind = 'current' ): array {
		if ( ! Values::is_address( $value ) ) {
			return [ 'cap_address', (string) Values::MAX_ADDRESS_BYTES, [] ];
		}

		$code = Validator::syntax( $value );

		if ( '' !== $code ) {
			return [ $code, '', [] ];
		}

		$first = explode( '/', $value )[0];

		foreach ( array_merge( array_values( $this->in->prefixes ), array_keys( $this->in->prefixes ), $this->in->old_codes ) as $marker ) {
			if ( '' !== $marker && $first === $marker ) {
				return [ 'language_prefix', $marker, [] ];
			}
		}

		if ( Validator::overlaps( $value, $w['base'] ) ) {
			return [ 'own_registered', '', [] ];
		}

		$full = trim( ( '' !== $w['front'] ? $w['front'] . '/' : '' ) . $value, '/' );

		// Rule 4: other keys' bases, values and histories as full paths; a type's single and archive bases may nest.
		foreach ( $this->work as $other => $o ) {
			if ( $other === $key ) {
				continue;
			}

			$pair = $o['desc']['object'] === $w['desc']['object'] && 'taxonomy' !== $o['desc']['role'] && 'taxonomy' !== $w['desc']['role'];

			// A single/archive pair on one registered base (WooCommerce `shop`) shares its values too.
			if ( $pair && $o['base'] === $w['base'] && $o['front'] === $w['front'] ) {
				continue;
			}

			// A current value wins over its single/archive sibling's old values (a shop base kept by the other key's history).
			foreach ( $this->paths_of( $other, $o, ! $pair || 'current' !== $kind ) as $path ) {
				if ( $pair ? $path === $full : Validator::overlaps( $path, $full ) ) {
					return [ 'other_key', $o['desc']['label'] . ' (' . $other . ')', [] ];
				}
			}
		}

		// Rule 2: content that already owns the address; the permalink structure's first tag where the value sits.
		if ( ! $derived ) {
			$page = $this->content->page_with_prefix( $this->unrooted( $full ) );

			if ( null !== $page ) {
				return [ 'page', $page, [] ];
			}
		}

		if ( $w['front'] === $this->lead['front'] ) {
			$fseg = explode( '/', $value )[0];
			$kind = $this->lead['kind'];

			if ( 'post' === $kind && null !== $this->content->root_post( $fseg ) ) {
				return [ 'root_post', $fseg, [] ];
			}

			if ( 'term' === $kind && null !== $this->content->term( $this->lead['taxonomy'], $fseg ) ) {
				return [ 'category' === $this->lead['taxonomy'] ? 'category' : 'term', $fseg, [] ];
			}

			if ( 'author' === $kind && null !== $this->content->author( $fseg ) ) {
				return [ 'author', $fseg, [] ];
			}
		}

		// Rule 3: routes by literal-prefix overlap; a shared namespace counts only under this key's own base.
		$shared  = array_values( array_filter( $this->in->registry->shared(), static fn( string $ns ): bool => '' !== $w['eff'] && str_starts_with( $ns, $w['eff'] . '/' ) ) );
		$overlap = $this->rules->overlaps( $full, $w['own'], $w['front'], $shared );

		if ( null !== $overlap['conflict'] ) {
			return [ 'route', $overlap['conflict'], [] ];
		}

		// Rule 5: subdirectory multisite selects the site from the full path before plugins load.
		$site = Inputs::site_path();

		if ( '' !== $site ) {
			$paths = [ $site . $full . '/' ];

			foreach ( $this->in->prefixes as $prefix ) {
				$paths[] = $site . $prefix . '/' . $full . '/';
			}

			foreach ( $paths as $path ) {
				$other = $this->content->other_site( $path );

				if ( null !== $other ) {
					return [ 'site_path', $other, [] ];
				}
			}
		}

		return [ '', '', $overlap['unv'] ];
	}

	/**
	 * Full paths another key holds: its registered base, its values and (unless left out) histories in every language.
	 *
	 * @param string $key  Key.
	 * @param Work   $w    Work row.
	 * @param bool   $hist With the histories.
	 * @return list<string>
	 */
	private function paths_of( string $key, array $w, bool $hist = true ): array {
		$front = '' !== $w['front'] ? $w['front'] . '/' : '';
		$out   = [ trim( $front . $w['base'], '/' ) ];
		$entry = $this->in->values['keys'][ $key ] ?? [];

		foreach ( $this->derived[ $key ] ?? array_diff_key( $entry['tr'] ?? [], $this->unused[ $key ] ?? [] ) as $value ) {
			$out[] = $front . $value;
		}

		foreach ( array_merge( array_values( $this->code[ $key ] ?? [] ), $this->held[ $key ] ?? [] ) as $value ) {
			if ( '' !== $value ) {
				$out[] = $front . $value;
			}
		}

		foreach ( $hist ? $entry['hist'] ?? [] : [] as $list ) {
			foreach ( $list as $value ) {
				$out[] = $front . $value;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * More than 16 unverifiable rules: every key is suspended (Q17).
	 *
	 * @return void
	 */
	private function check_unverified(): void {
		if ( count( $this->unv ) <= self::MAX_UNVERIFIED ) {
			return;
		}

		foreach ( array_keys( $this->entries ) as $key ) {
			$this->suspend_key( $key, 'too_many_unverified', (string) count( $this->unv ) );
		}

		$this->report['errors'][] = 'too_many_unverified';
	}

	/**
	 * Caps: 200 keys, 64 languages, 16 entries per first segment as assembled (registered entries included).
	 *
	 * @param array<string, int> $langs Languages.
	 * @return void
	 */
	private function check_caps( array $langs ): void {
		$this->report['caps']['languages'] = count( $langs );

		if ( count( $langs ) > Values::MAX_LANGUAGES ) {
			$this->report['errors'][] = 'cap_languages';
		}

		$keys = array_keys( array_filter( $this->entries ) );

		$this->report['caps']['keys'] = count( $keys );

		foreach ( array_slice( $keys, Values::MAX_KEYS ) as $key ) {
			$this->suspend_key( $key, 'cap_keys', '' );
		}

		// Registered entries alone over the cap: whole keys go, last key first.
		foreach ( $this->buckets() as $first => $list ) {
			$owners = array_values( array_unique( array_column( array_filter( $list, static fn( array $e ): bool => -1 === $e[1] ), 0 ) ) );
			rsort( $owners, SORT_STRING );

			foreach ( array_slice( $owners, 0, max( 0, count( $owners ) - self::MAX_ENTRIES_PER_SEGMENT ) ) as $key ) {
				$this->suspend_key( $key, 'cap_first_segment', (string) $first );
			}
		}

		$rank = [
			'history'    => 0,
			'previous'   => 1,
			'current'    => 2,
			'registered' => 3,
		];

		foreach ( $this->buckets() as $list ) {
			if ( count( $list ) <= self::MAX_ENTRIES_PER_SEGMENT ) {
				continue;
			}

			usort( $list, static fn( array $a, array $b ): int => ( $rank[ $a[2] ] ?? 9 ) <=> ( $rank[ $b[2] ] ?? 9 ) );

			foreach ( array_slice( $list, 0, count( $list ) - self::MAX_ENTRIES_PER_SEGMENT ) as [ $key, $i ] ) {
				if ( $i >= 0 && isset( $this->entries[ $key ][ $i ] ) ) {
					$this->mark( $key, $this->entries[ $key ][ $i ], 'cap_first_segment' );
					unset( $this->entries[ $key ][ $i ] );
				}
			}
		}
	}

	/**
	 * Shared namespaces under each base that a stored rule routes (the runtime leaves their paths alone); more than
	 * MAX_SHARED_PER_KEY under one base suspends that key.
	 *
	 * @return void
	 */
	private function check_shared(): void {
		$shared = $this->in->registry->shared();
		$lits   = [];

		if ( [] === $shared ) {
			return;
		}

		foreach ( array_keys( Typed::arr( $this->in->option( 'rewrite_rules' ) ) ) as $regex ) {
			$lits[] = Validator::norm_path( Rules::literal_prefix( (string) $regex, $this->rules->root() ) );
		}

		foreach ( $this->work as $key => $w ) {
			$out = [];
			$n   = 0;

			foreach ( $shared as $ns ) {
				$ns = rtrim( $ns, '/' );

				if ( [] === ( $this->entries[ $key ] ?? [] ) || '' === $w['eff'] || ! str_starts_with( $ns, $w['eff'] . '/' ) ) {
					continue;
				}

				foreach ( $lits as $lit ) {
					if ( $lit === $ns || str_starts_with( $lit, $ns . '/' ) ) {
						$rel                                  = substr( $ns, strlen( $w['eff'] ) + 1 );
						$out[ explode( '/', $rel )[0] ][] = $rel;
						++$n;
						break;
					}
				}
			}

			if ( $n > self::MAX_SHARED_PER_KEY ) {
				$this->suspend_key( $key, 'cap_shared', (string) $n );
			} elseif ( [] !== $out ) {
				ksort( $out, SORT_STRING );
				$this->shr[ $key ] = array_map( static fn( array $l ): array => array_values( array_unique( $l ) ), $out );
			}
		}
	}

	/**
	 * Map entries by first segment as assemble() writes them: [ key, entry index or -1 for the registered one, kind ].
	 *
	 * @return array<string, list<array{0: string, 1: int, 2: string}>>
	 */
	private function buckets(): array {
		$out = [];

		foreach ( $this->entries as $key => $list ) {
			if ( [] === $list ) {
				continue;
			}

			foreach ( $list as $i => $e ) {
				$out[ explode( '/', $e[1] )[0] ][] = [ $key, $i, $e[2] ];
			}

			$out[ explode( '/', $this->work[ $key ]['base'] )[0] ][] = [ $key, -1, 'registered' ];
		}

		return $out;
	}

	/**
	 * Route self-test (rule 7): a key whose swapped sample misses its own rules is suspended, naming the winner.
	 *
	 * @param array<string, int> $langs Languages.
	 * @return void
	 */
	private function self_test( array $langs ): void {
		$run = SelfTest::run( $this->rules, $this->work, $this->entries, $langs, $this->content );

		$this->report['selftest'] = $run['results'];

		foreach ( $run['failed'] as $key => $winner ) {
			$this->suspend_key( $key, 'self_test', $winner );
		}
	}

	/**
	 * Suspend every accepted entry of a key.
	 *
	 * @param string $key    Key.
	 * @param string $reason Reason code.
	 * @param string $detail Detail.
	 * @return void
	 */
	private function suspend_key( string $key, string $reason, string $detail ): void {
		foreach ( $this->entries[ $key ] ?? [] as $e ) {
			$this->mark( $key, $e, $reason, $detail );
		}

		$this->entries[ $key ] = [];

		if ( isset( $this->report['keys'][ $key ] ) ) {
			$this->report['keys'][ $key ]['status'] = 'suspended';
			$this->report['keys'][ $key ]['reason'] = $reason;
			$this->report['keys'][ $key ]['detail'] = $detail;
		}
	}

	/**
	 * Mark one reported entry suspended.
	 *
	 * @param string   $key    Key.
	 * @param Accepted $e      Entry.
	 * @param string   $reason Reason.
	 * @param string   $detail Detail.
	 * @return void
	 */
	private function mark( string $key, array $e, string $reason, string $detail = '' ): void {
		[ $lang, $value, $kind ] = $e;

		if ( 'current' === $kind ) {
			if ( isset( $this->report['entries'][ $key ][ $lang ] ) ) {
				$this->report['entries'][ $key ][ $lang ]['status'] = 'suspended';
				$this->report['entries'][ $key ][ $lang ]['reason'] = $reason;
				$this->report['entries'][ $key ][ $lang ]['detail'] = $detail;
			}
			return;
		}

		$rows = $this->report['history'][ $key ][ $lang ] ?? [];

		foreach ( $rows as $i => $row ) {
			if ( $row['value'] === $value ) {
				$rows[ $i ] = [
					'value'  => $row['value'],
					'status' => 'suspended',
					'reason' => $reason,
					'detail' => $detail,
					'kind'   => $row['kind'],
				];
			}
		}

		if ( [] !== $rows ) {
			$this->report['history'][ $key ][ $lang ] = $rows;
		}
	}

	/**
	 * Shop-derived values of `pt:product` / `pta:product` (3.9): the translated shop URI, the base's tail kept.
	 *
	 * @param string             $key   Key.
	 * @param Work               $w     Work row.
	 * @param array<string, int> $langs Languages.
	 * @return array<string, string>|null Lang => value, or null when the key does not follow the shop page.
	 */
	private function shop_values( string $key, array $w, array $langs ): ?array {
		if ( 'woocommerce' !== $w['desc']['grp'] || 'product' !== $w['desc']['object'] || 'taxonomy' === $w['desc']['role'] ) {
			return null;
		}

		$shop_id = Typed::int( $this->in->option( 'woocommerce_shop_page_id' ) );
		$shop    = $shop_id > 0 ? $this->content->page_uri( $shop_id ) : null;
		$base    = $w['base'];

		if ( null === $shop || ( $base !== $shop && ! str_starts_with( $base, $shop . '/' ) ) ) {
			return null;
		}

		$tail = substr( $base, strlen( $shop ) );
		// Only a published translation has an address: any other status keeps the registered base in its language.
		$trans = array_filter( $this->content->translations( $shop_id ), fn( int $tid ): bool => $tid === $shop_id || $this->content->published( $tid ) );

		$out   = [];
		$extra = [ 'id' => [ $shop_id, $shop ] ];

		if ( '' === $tail ) {
			$this->at_shop[ $key ] = true;
		}

		$this->shop_uris[ $key ] = [ $base ];

		foreach ( $trans as $lang_id => $tid ) {
			$uri = $this->content->page_uri( $tid, $lang_id );

			if ( null !== $uri ) {
				$this->shop_uris[ $key ][] = $uri . $tail;
			}
		}

		$extra['uris'] = $this->shop_uris[ $key ];

		foreach ( $langs as $lang => $lang_id ) {
			$tid = $trans[ $lang_id ] ?? 0;
			$uri = $tid > 0 ? $this->content->page_uri( $tid, $lang_id ) : null;

			if ( null === $uri ) {
				continue;
			}

			$out[ $lang ]   = $uri . $tail;
			$extra[ $lang ] = [ $tid, $uri ];

			// Only a base equal to the shop URI has the shop's child pages right after it (a tail such as `items` does not).
			if ( '' !== $tail ) {
				continue;
			}

			$children = $this->children[ $lang ] ??= $this->content->descendants( $tid, $lang_id, self::SHOP_CHILDREN_CAP );

			$extra[ $lang ][] = md5( implode( '|', $children ) );

			if ( count( $children ) > self::SHOP_CHILDREN_CAP ) {
				unset( $out[ $lang ] );
				$this->report['entries'][ $key ][ $lang ] = [
					'value'  => $uri . $tail,
					'status' => 'suspended',
					'reason' => 'shop_children',
					'detail' => '',
				];
				continue;
			}

			foreach ( $children as $child ) {
				$this->exc[ $key ][ $lang ][ explode( '/', $child )[0] ][] = $child;
			}
		}

		$this->extra[ 'shop:' . $key ] = $extra;

		return $out;
	}

	/**
	 * Core's page suffixes a page keeps after the key's base: none unless every old value of the key is a page core
	 * serves (a renamed shop or old base keeps its 301); none that an object the key routes there could take (a term of
	 * the slug's %tag% taxonomy; a post of its type when no tag or an old base had none, also by a numbered tail).
	 *
	 * @param string $key Key.
	 * @param Work   $w   Work row.
	 * @return array{0: list<string>, 1: list<string>} [ literal suffixes, regex tails ]
	 */
	private function page_suffixes( string $key, array $w ): array {
		$front = '' !== $w['front'] ? $w['front'] . '/' : '';
		$taxes = preg_match_all( '/%([a-z0-9_]+)%/', $w['desc']['slug'], $m ) ? $m[1] : [];

		$this->extra[ 'sfx:' . $key ] = [];
		$posts                        = [] === $taxes;

		foreach ( $this->entries[ $key ] ?? [] as [ , $value, $kind ] ) {
			if ( ! $this->content->core_page( $this->unrooted( $front . $value ) ) ) {
				return [ [], [] ];
			}

			// An old registered base may come from a structure without a %tag%: product URLs right after it.
			$posts = $posts || 'previous' === $kind;
		}

		foreach ( $taxes as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				return [ [], [] ];
			}
		}

		[ $lits, $tails ] = $this->rules->page_suffixes();
		$out              = [];

		foreach ( $lits as $sfx ) {
			$first = explode( '/', $sfx )[0];

			foreach ( $taxes as $tax ) {
				if ( null !== $this->content->term( $tax, $first ) ) {
					continue 2;
				}
			}

			if ( ! $posts || ! $this->content->post_named( $w['desc']['object'], $first ) ) {
				$out[] = $sfx;
			}
		}

		// A numbered tail (/N) takes a product slugged `2024`, as a literal one takes a product `trackback`.
		$tails = $posts ? array_values( array_filter( $tails, fn( string $tail ): bool => ! $this->content->post_matching( $w['desc']['object'], $tail ) ) ) : $tails;

		$this->extra[ 'sfx:' . $key ] = [ $out, $tails ];

		return [ $out, $tails ];
	}

	/**
	 * Shop exceptions of one key in a stable order.
	 *
	 * @param array<string, array<string, list<string>>> $by_lang Lang => first segment => URIs.
	 * @return array<string, array<string, list<string>>>
	 */
	private static function sorted_exc( array $by_lang ): array {
		ksort( $by_lang, SORT_STRING );

		foreach ( $by_lang as $lang => $by_first ) {
			ksort( $by_first, SORT_STRING );

			foreach ( $by_first as $first => $uris ) {
				sort( $uris, SORT_STRING );
				$by_first[ $first ] = $uris;
			}

			$by_lang[ $lang ] = $by_first;
		}

		return $by_lang;
	}

	/**
	 * The map (3.2), or null when nothing is translated or aliased.
	 *
	 * @param ValueSet $values Values.
	 * @return array<string, mixed>|null
	 */
	private function assemble( array $values ): ?array {
		$rules = Typed::arr( $this->in->option( 'rewrite_rules' ) );
		$keys  = [];
		$tr    = [];
		$alias = [];
		$off   = [];
		$own   = [];
		$state = 'aliases';

		foreach ( $this->entries as $key => $list ) {
			if ( [] === $list ) {
				continue;
			}

			$w            = $this->work[ $key ];
			$keys[ $key ] = [
				'grp'  => $w['desc']['grp'],
				'role' => $w['desc']['role'],
				'eff'  => $w['eff'],
				// Off, a key on the shop URI keeps only product aliases: the bare shop URI is the page's own address again.
				'bare' => $w['bare'] && ( $w['on'] || ! isset( $this->at_shop[ $key ] ) ),
			];
			$own[ $key ]  = [ $w['primary'][0] => $w['primary'][1] ];

			$shr = $this->shr[ $key ] ?? [];

			// Off, a key on the shop URI leaves core's page suffixes after it to the page (/de/laden/page/2/): literal ones
			// in shr (no span at all), numbered ones (comment-page-N, /N) in pgs for the swap.
			[ $lits, $tails ] = ! $w['on'] && isset( $this->at_shop[ $key ] ) ? $this->page_suffixes( $key, $w ) : [ [], [] ];

			foreach ( $lits as $sfx ) {
				$shr[ explode( '/', $sfx )[0] ][] = $sfx;
			}

			if ( [] !== $shr ) {
				ksort( $shr, SORT_STRING );
				$keys[ $key ]['shr'] = array_map( static fn( array $l ): array => array_values( array_unique( $l ) ), $shr );
			}

			if ( [] !== $tails ) {
				$keys[ $key ]['pgs'] = $tails;
			}

			if ( isset( $this->exc[ $key ] ) ) {
				$keys[ $key ]['exc'] = self::sorted_exc( $this->exc[ $key ] );
			}

			if ( ! $w['on'] ) {
				$off[ $key ] = 1;
			}

			$list[] = [ '*', $w['base'], 'registered' ];

			foreach ( $list as [ $lang, $value, $kind ] ) {
				if ( 'current' === $kind && $w['on'] ) {
					$tr[ $key ][ $lang ] = $value;
					$state               = 'active';
				}

				$alias[ explode( '/', $value )[0] ][] = [
					'key'  => $key,
					'segs' => explode( '/', $value ),
					'lang' => $lang,
					'kind' => $kind,
				];
			}
		}

		if ( [] === $keys ) {
			return null;
		}

		$order = [
			'current'    => 0,
			'history'    => 1,
			'previous'   => 2,
			'registered' => 3,
		];

		foreach ( $alias as $first => $list ) {
			// Longest first, so a nested base is found before its shorter neighbour.
			usort( $list, static fn( array $a, array $b ): int => [ count( $b['segs'] ), $order[ $a['kind'] ] ] <=> [ count( $a['segs'] ), $order[ $b['kind'] ] ] );
			$alias[ $first ] = $list;

			$this->report['caps']['max_per_segment'] = max( $this->report['caps']['max_per_segment'], count( $list ) );
		}

		ksort( $alias, SORT_STRING );

		return [
			'v'        => 1,
			'gen'      => 0,
			'fp'       => '',
			'state'    => $values['on'] ? $state : 'aliases',
			'redirect' => $values['redirect'],
			'keys'     => $keys,
			'tr'       => $values['on'] ? $tr : [],
			'alias'    => $alias,
			// v1 slot kept empty: shop exceptions are per key (keys[k].exc), so other keys never see them.
			'exc'      => [],
			'off'      => $off,
			'own'      => $own,
			'unv'      => array_slice( array_keys( $this->unv ), 0, self::MAX_UNVERIFIED ),
			'rw'       => md5( $this->in->options['rewrite_rules'] ?? '' ),
			// OQ1: count|first|last of the stored rules; the runtime checks newer rules before a swap when it differs.
			'rg'       => count( $rules ) . '|' . array_key_first( $rules ) . '|' . array_key_last( $rules ),
		];
	}
}
