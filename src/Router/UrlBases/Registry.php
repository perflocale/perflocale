<?php
/**
 * URL bases key registry.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Admin\Typed;
use PerfLocale\Contract\UrlBasesProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The keys the compiler may translate: the core provider plus the `perflocale/url_bases/providers` filter, checked
 * against the rules of the provider contract. Runs in compile contexts only.
 *
 * @phpstan-type KeyDesc array{key: string, grp: string, role: string, label: string, object: string, slug: string, with_front: bool, qv: string|false, hierarchical: bool, declared: bool}
 * @phpstan-type Issue array{provider: string, code: string, detail: string}
 * @phpstan-type Decl array{class: string, grp: string, label: string, keys: array<mixed>, inputs: array<mixed>, shared: array<mixed>}
 *
 * @internal
 */
final class Registry {

	public const KEY_PATTERN = '/^(pt|pta|tx):[a-z0-9_-]{1,32}$/';

	public const GROUP_PATTERN = '/^[a-z0-9_-]{1,32}$/';

	/**
	 * A provider's group id also needs a letter: a digit-only id would become an integer array key.
	 */
	/**
	 * Inputs and shared namespaces kept per provider: each costs every compile (an IN() placeholder, a rule scan).
	 */
	public const PROVIDER_CAP = 32;

	private const PROVIDER_GROUP = '/^(?=[a-z0-9_-]*[a-z])[a-z0-9_-]{1,32}$/';

	/**
	 * Groups only PerfLocale's own providers may use.
	 */
	private const RESERVED_GROUPS = [
		'core'        => Providers\Core::class,
		'woocommerce' => 'PerfLocale\\Router\\UrlBases\\Providers\\WooCommerce',
	];

	/**
	 * Key descriptors by key.
	 *
	 * @var array<string, KeyDesc>
	 */
	private array $keys = [];

	/**
	 * Group labels by group id, `core` first.
	 *
	 * @var array<string, string>
	 */
	private array $groups = [];

	/**
	 * Option names whose change alters a provider's bases.
	 *
	 * @var list<string>
	 */
	private array $inputs = [];

	/**
	 * Declared shared namespaces (norm form, ending in `/`).
	 *
	 * @var list<string>
	 */
	private array $shared = [];

	/**
	 * Refused providers and keys.
	 *
	 * @var list<Issue>
	 */
	private array $issues = [];

	/**
	 * PerfLocale's own add-on providers: the WooCommerce add-on's while it is booted (no hook of its own on any request).
	 *
	 * @return list<UrlBasesProvider>
	 */
	private static function own_providers(): array {
		return \PerfLocale\Helper::get_instance()->is_addon_active( 'woocommerce' ) ? [ new Providers\WooCommerce() ] : [];
	}

	/**
	 * Build the registry for the current blog.
	 *
	 * @return self
	 */
	public static function build(): self {
		/** @var \PerfLocale\Settings $settings */
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$types    = Typed::strs( $settings->get_translatable_post_types() );
		$taxes    = Typed::strs( $settings->get_translatable_taxonomies() );
		$registry = new self();

		// Third-party callbacks: one that throws is reported, and the core keys still compile.
		try {
			/**
			 * Providers of URL bases owned by other plugins; runs only when PerfLocale compiles its URL bases.
			 *
			 * @hook  perflocale/url_bases/providers
			 * @since 1.0.8
			 * @api
			 *
			 * @param \PerfLocale\Contract\UrlBasesProvider[] $providers Providers; anything else is ignored and named in Site Health.
			 * @return \PerfLocale\Contract\UrlBasesProvider[]
			 */
			$providers = Typed::arr( apply_filters( 'perflocale/url_bases/providers', self::own_providers() ) );
		} catch ( \Throwable $e ) {
			$registry->issue( 'perflocale/url_bases/providers', 'provider_error', $e->getMessage() );
			$providers = [];
		}

		$claims = [];
		$valid  = [];

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof UrlBasesProvider ) {
				$registry->issue( is_object( $provider ) ? get_class( $provider ) : gettype( $provider ), 'not_provider', '' );
				continue;
			}

			// Third-party code, read once: what is validated is what registers; one that throws is refused.
			try {
				$decl = self::read_provider( $provider );
			} catch ( \Throwable $e ) {
				$registry->issue( get_class( $provider ), 'provider_error', $e->getMessage() );
				continue;
			}

			$grp  = $decl['grp'];
			$keys = array_map( 'strval', array_keys( $decl['keys'] ) );

			if ( 1 !== preg_match( self::PROVIDER_GROUP, $grp ) ) {
				$registry->issue( get_class( $provider ), 'bad_group', $grp );
				continue;
			}

			if ( isset( self::RESERVED_GROUPS[ $grp ] ) && get_class( $provider ) !== self::RESERVED_GROUPS[ $grp ] ) {
				$registry->issue( get_class( $provider ), 'reserved_group', $grp );
				continue;
			}

			if ( isset( $valid[ $grp ] ) ) {
				$registry->issue( get_class( $provider ), 'duplicate_group', $grp );
				continue;
			}

			$valid[ $grp ] = $decl;

			foreach ( $keys as $key ) {
				$claims[ $key ][] = $grp;
			}
		}

		foreach ( $claims as $key => $grps ) {
			if ( count( $grps ) > 1 ) {
				$registry->issue( implode( ', ', $grps ), 'key_claimed_twice', $key );
			}
		}

		// Providers first: only one that registered takes its keys out of core.
		$served = [];

		foreach ( $valid as $grp => $decl ) {
			$registry->add_provider( $decl, $types, $taxes, false, $claims );

			foreach ( $claims as $key => $grps ) {
				if ( in_array( $grp, $grps, true ) ) {
					$served[ $key ] = [ $grp ];
				}
			}
		}

		$registry->add_provider( self::read_provider( new Providers\Core( $types, $taxes ) ), $types, $taxes, true, $served );
		$registry->groups = [ 'core' => $registry->groups['core'] ] + $registry->groups;

		return $registry;
	}

	/**
	 * One read of every declaration of a provider.
	 *
	 * @param UrlBasesProvider $provider Provider.
	 * @return Decl
	 */
	private static function read_provider( UrlBasesProvider $provider ): array {
		return [
			'class'  => get_class( $provider ),
			'grp'    => $provider->group(),
			'label'  => $provider->label(),
			'keys'   => Typed::arr( $provider->keys() ),
			'inputs' => Typed::arr( $provider->inputs() ),
			'shared' => Typed::arr( $provider->shared() ),
		];
	}

	/**
	 * Add one provider's keys, label, inputs and shared namespaces; a claimed key leaves `core`.
	 *
	 * @param Decl                        $decl   Declarations, read once.
	 * @param list<string>                $types  Translatable post types.
	 * @param list<string>                $taxes  Translatable taxonomies.
	 * @param bool                        $core   The core provider.
	 * @param array<string, list<string>> $claims Key => claiming groups.
	 * @return void
	 */
	private function add_provider( array $decl, array $types, array $taxes, bool $core, array $claims ): void {
		$grp    = $decl['grp'];
		$class  = $decl['class'];
		$keys   = [];
		$issues = [];
		$inputs = [];
		$shared = [];

		// Third-party values: the contract's types are not enforced on array members.
		foreach ( $decl['keys'] as $key => $label ) {
			$key = (string) $key;

			// A claimed key leaves core; a key claimed twice belongs to nobody.
			if ( ( $core && isset( $claims[ $key ] ) ) || count( $claims[ $key ] ?? [] ) > 1 ) {
				continue;
			}

			$desc = is_string( $label ) ? self::describe( $key, $grp, $label, $types, $taxes ) : 'bad_label';

			if ( is_string( $desc ) ) {
				if ( ! $core ) {
					$issues[] = [ $desc, $key ];
				}
				continue;
			}

			$keys[ $key ] = $desc;
		}

		$label = $decl['label'];

		if ( ! $core ) {
			foreach ( $decl['inputs'] as $option ) {
				if ( is_string( $option ) && 1 === preg_match( '/^[A-Za-z0-9_:.-]{1,191}$/', $option ) ) {
					$inputs[] = $option;
				}
			}

			foreach ( $decl['shared'] as $prefix ) {
				$norm = is_string( $prefix ) ? Validator::norm_path( trim( $prefix, '/' ) ) : '';

				if ( '' !== $norm ) {
					$shared[] = $norm . '/';
				}
			}
		}

		if ( count( $inputs ) > self::PROVIDER_CAP ) {
			$inputs   = array_slice( $inputs, 0, self::PROVIDER_CAP );
			$issues[] = [ 'provider_cap', 'inputs' ];
		}

		if ( count( $shared ) > self::PROVIDER_CAP ) {
			$shared   = array_slice( $shared, 0, self::PROVIDER_CAP );
			$issues[] = [ 'provider_cap', 'shared' ];
		}

		$this->keys           = array_merge( $this->keys, $keys );
		$this->groups[ $grp ] = $label;
		$this->inputs         = array_merge( $this->inputs, $inputs );
		$this->shared         = array_merge( $this->shared, $shared );

		foreach ( $issues as [ $code, $key ] ) {
			$this->issue( $class, $code, $key );
		}
	}

	/**
	 * A key's descriptor, or the refusal code.
	 *
	 * @param string       $key   Key.
	 * @param string       $grp   Group.
	 * @param string       $label Field label.
	 * @param list<string> $types Translatable post types.
	 * @param list<string> $taxes Translatable taxonomies.
	 * @return KeyDesc|string
	 */
	public static function describe( string $key, string $grp, string $label, array $types, array $taxes ): array|string {
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return 'bad_key';
		}

		[ $kind, $name ] = explode( ':', $key, 2 );

		if ( 'tx' === $kind ) {
			$obj = get_taxonomy( $name );

			if ( ! $obj instanceof \WP_Taxonomy || ! $obj->public || ! is_array( $obj->rewrite ) || '' === Providers\Core::slug( $obj->rewrite ) ) {
				return 'not_registered';
			}

			if ( ! in_array( $name, $taxes, true ) ) {
				return 'not_translatable';
			}

			return [
				'key'          => $key,
				'grp'          => $grp,
				'role'         => 'taxonomy',
				'label'        => $label,
				'object'       => $name,
				'slug'         => Providers\Core::slug( $obj->rewrite ),
				'with_front'   => ! empty( $obj->rewrite['with_front'] ),
				'qv'           => is_string( $obj->query_var ) && '' !== $obj->query_var ? $obj->query_var : false,
				'hierarchical' => ! empty( $obj->rewrite['hierarchical'] ),
				'declared'     => true,
			];
		}

		$obj = get_post_type_object( $name );

		if ( ! $obj instanceof \WP_Post_Type || ! $obj->public || ! is_array( $obj->rewrite ) || '' === Providers\Core::slug( $obj->rewrite ) || in_array( $name, [ 'post', 'page', 'attachment' ], true ) ) {
			return 'not_registered';
		}

		if ( ! in_array( $name, $types, true ) ) {
			return 'not_translatable';
		}

		$slug = Providers\Core::slug( $obj->rewrite );

		if ( 'pta' === $kind ) {
			$archive = Providers\Core::archive_slug( $obj );

			if ( null === $archive ) {
				return 'archive_same';
			}

			$slug = $archive;
		}

		$qv = Typed::str( $obj->query_var );

		return [
			'key'          => $key,
			'grp'          => $grp,
			'role'         => 'pta' === $kind ? 'archive' : 'single',
			'label'        => $label,
			'object'       => $name,
			'slug'         => $slug,
			'with_front'   => ! empty( $obj->rewrite['with_front'] ),
			'qv'           => '' !== $qv ? $qv : false,
			'hierarchical' => $obj->hierarchical,
			'declared'     => true,
		];
	}

	/**
	 * Record a refused provider or key.
	 *
	 * @param string $provider Provider class or group.
	 * @param string $code     Reason code.
	 * @param string $detail   Key or group id.
	 * @return void
	 */
	private function issue( string $provider, string $code, string $detail ): void {
		$this->issues[] = [
			'provider' => $provider,
			'code'     => $code,
			'detail'   => $detail,
		];
	}

	/**
	 * Key descriptors by key.
	 *
	 * @return array<string, KeyDesc>
	 */
	public function keys(): array {
		return $this->keys;
	}

	/**
	 * Group labels by group id.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Option names whose change alters a provider's bases.
	 *
	 * @return list<string>
	 */
	public function inputs(): array {
		return array_values( array_unique( $this->inputs ) );
	}

	/**
	 * Declared shared namespaces.
	 *
	 * @return list<string>
	 */
	public function shared(): array {
		return array_values( array_unique( $this->shared ) );
	}

	/**
	 * Refused providers and keys.
	 *
	 * @return list<Issue>
	 */
	public function issues(): array {
		return $this->issues;
	}
}
