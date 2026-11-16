<?php
/**
 * The classified fields of one object.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every key the hosts store for one object, classified once through `perflocale/fields/map`; never built on a page view.
 * The memo holds one entry and every build replaces it (a CLI run over many posts holds one map): never a cache
 * across writes.
 */
final class FieldMap {

	public const KIND_POST       = 'post';
	public const KIND_TERM       = 'term';
	public const KIND_ATTACHMENT = 'attachment';
	public const KIND_BLOCK      = 'block';
	public const KIND_OPTION     = 'option';
	public const KIND_WIDGET     = 'widget';

	/**
	 * The one memoised map: [memo key, map].
	 *
	 * @var array{0: string, 1: FieldMap}|null
	 */
	private static ?array $memo = null;

	/**
	 * The post map of the current key pass: [the pass's apply_of(), memo key, map].
	 *
	 * @var array{0: string, 1: string, 2: FieldMap}|null
	 */
	private static ?array $pass = null;

	/**
	 * The type-level map of the current filter apply: [hook, its apply_of(), blog, post type, map].
	 *
	 * @var array{0: string, 1: string, 2: int, 3: string, 4: FieldMap}|null
	 */
	private static ?array $type_pass = null;

	/**
	 * Whether definitions_unavailable() answered true since the last take_skipped().
	 *
	 * @var bool
	 */
	private static bool $skipped = false;

	/**
	 * Core's own post meta a switched run still carries (template, block footnotes).
	 *
	 * @var array<int, string>
	 */
	private const SWITCHED_CORE_META = [ '_wp_page_template', 'footnotes' ];

	/**
	 * WooCommerce's own product data keys (11.2.2's product data store list without core's bookkeeping keys): the
	 * most a switched run lets travel for WooCommerce, whatever the loaded store reports.
	 *
	 * @var array<int, string>
	 */
	private const WC_PRODUCT_META = [
		'_visibility', '_sku', '_global_unique_id', '_price', '_regular_price', '_sale_price', '_sale_price_dates_from',
		'_sale_price_dates_to', 'total_sales', '_tax_status', '_tax_class', '_manage_stock', '_stock', '_stock_status',
		'_backorders', '_low_stock_amount', '_sold_individually', '_weight', '_length', '_width', '_height', '_upsell_ids',
		'_crosssell_ids', '_purchase_note', '_default_attributes', '_product_attributes', '_virtual', '_downloadable',
		'_download_limit', '_download_expiry', '_featured', '_downloadable_files', '_wc_rating_count', '_wc_average_rating',
		'_wc_review_count', '_variation_description', '_file_paths', '_product_image_gallery', '_wc_video_gallery',
		'_product_version', '_cogs_total_value',
	];

	/**
	 * WooCommerce's built-in product subtype data its base store does not list (external, grouped, variation COGS):
	 * written as `_<prop>` from the classes' extra data, so they are kept outside the store narrowing.
	 *
	 * @var array<int, string>
	 */
	private const WC_SUBTYPE_META = [ '_product_url', '_button_text', '_children', '_cogs_value_is_additive' ];

	/**
	 * The WooCommerce keys a switched run lets travel, as a set, read once per request: [] when the read failed.
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $wc_keys = null;

	/**
	 * Descriptors in the order the producers added them.
	 *
	 * @var array<int, FieldDescriptor>
	 */
	private array $descriptors = [];

	/**
	 * Descriptor indexes per key.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $by_key = [];

	/**
	 * Constructor.
	 *
	 * @param string     $kind    Object kind.
	 * @param int|string $object  Object id (0 for a type-level map); the storage id of an option map.
	 * @param string     $subtype Post type, taxonomy, block name or widget id base.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int|string $object,
		public readonly string $subtype = ''
	) {
	}

	/**
	 * The map of one object, built through the producers and memoised.
	 *
	 * @param string                    $kind    Object kind.
	 * @param int|string                $id      Object id (0 for the type-level map).
	 * @param string                    $subtype Post type, taxonomy, block name or widget id base.
	 * @param bool                      $memoise Whether this build replaces the memo entry.
	 * @param array<string, mixed>|null $stored  Stored values (key => value) the caller already read, or null.
	 * @return FieldMap
	 */
	public static function for_object( string $kind, int|string $id, string $subtype = '', bool $memoise = true, ?array $stored = null ): FieldMap {
		$map = new self( $kind, $id, $subtype );

		/**
		 * Filters the field map of one object: each addon adds its host's descriptors. Never runs on a page view.
		 *
		 * @internal Not public: passes the internal FieldMap class; for the ACF and Meta Box add-ons.
		 * @hook  perflocale/fields/map
		 * @since 1.0.8
		 *
		 * @param FieldMap                  $map     The map being built.
		 * @param int|string                $object  Object id, or the storage id of an option map.
		 * @param string                    $kind    Object kind: post, term, attachment, block, option, widget.
		 * @param string                    $subtype Post type, taxonomy, block name or widget id base.
		 * @param array<string, mixed>|null $stored  Stored values (key => value) read once by the caller, or null: read your own cache.
		 */
		/**
		 * A filter may return anything; only a map is taken.
		 *
		 * @var mixed $filtered
		 */
		$filtered = apply_filters( 'perflocale/fields/map', $map, $id, $kind, $subtype, $stored );

		if ( $filtered instanceof self ) {
			$map = $filtered;
		}

		if ( $memoise ) {
			self::$memo = [ self::key_of( $kind, $id, $subtype ), $map ];
		}

		return $map;
	}

	/**
	 * The map of a post (0: the post type's templates).
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type.
	 * @return FieldMap
	 */
	public static function for_post( int $post_id, string $post_type ): FieldMap {
		return self::for_object( self::KIND_POST, $post_id, $post_type );
	}

	/**
	 * A post's map for the hosts' key lists: one build per apply of `perflocale/mt/translatable_meta_keys`, shared by
	 * its callbacks (nothing writes between them); outside that filter, a fresh build as for_post().
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type.
	 * @return FieldMap
	 */
	public static function for_key_pass( int $post_id, string $post_type ): FieldMap {
		$hook = 'perflocale/mt/translatable_meta_keys';

		if ( ! doing_filter( $hook ) ) {
			return self::for_post( $post_id, $post_type );
		}

		$pass = self::apply_of( $hook );
		$key  = self::key_of( self::KIND_POST, $post_id, $post_type );

		if ( null !== self::$pass && $pass === self::$pass[0] && $key === self::$pass[1] ) {
			return self::$pass[2];
		}

		$map        = self::for_post( $post_id, $post_type );
		self::$pass = [ $pass, $key, $map ];

		return $map;
	}

	/**
	 * A post type's type-level map, shared by the callbacks of one filter apply (each field addon's key list asks for
	 * it; nothing writes between them); outside a filter a fresh build. Never takes the memo slot.
	 *
	 * @param string $post_type Post type.
	 * @return FieldMap
	 */
	public static function for_type_pass( string $post_type ): FieldMap {
		$hook = current_filter();

		// False outside a filter; a hook name is never empty.
		if ( false === $hook ) {
			return self::for_object( self::KIND_POST, 0, $post_type, false );
		}

		$at = [ $hook, self::apply_of( $hook ), get_current_blog_id(), $post_type ];

		if ( null !== self::$type_pass && array_slice( self::$type_pass, 0, 4 ) === $at ) {
			return self::$type_pass[4];
		}

		$map             = self::for_object( self::KIND_POST, 0, $post_type, false );
		self::$type_pass = [ $at[0], $at[1], $at[2], $at[3], $map ];

		return $map;
	}

	/**
	 * Which apply of a hook runs: its apply count with its depth in the filter stack. The count alone is not enough:
	 * after a nested apply of the same hook the outer one sees the nested apply's count.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	private static function apply_of( string $hook ): string {
		$stack = $GLOBALS['wp_current_filter'] ?? [];

		return did_filter( $hook ) . ':' . ( is_array( $stack ) ? count( array_keys( $stack, $hook, true ) ) : 0 );
	}

	/**
	 * The map of an ACF storage id or a Meta Box settings page (`mb:{option}`).
	 * Pass the stored values when read (one LIKE scan per storage id per request), so producers never scan again.
	 *
	 * @param string                    $storage_id Storage id.
	 * @param array<string, mixed>|null $stored     The page's stored values (key => value), or null.
	 * @return FieldMap
	 */
	public static function for_option( string $storage_id, ?array $stored = null ): FieldMap {
		return self::for_object( self::KIND_OPTION, $storage_id, '', true, $stored );
	}

	/**
	 * The memoised map of an object, when it is the one entry; no build.
	 *
	 * @param string     $kind    Object kind.
	 * @param int|string $id      Object id.
	 * @param string     $subtype Subtype.
	 * @return FieldMap|null
	 */
	public static function memo( string $kind, int|string $id, string $subtype = '' ): ?FieldMap {
		if ( null !== self::$memo && self::$memo[0] === self::key_of( $kind, $id, $subtype ) ) {
			return self::$memo[1];
		}

		return null;
	}

	/**
	 * The memo entry's key, or null when nothing is memoised.
	 *
	 * @return string|null
	 */
	public static function memo_key(): ?string {
		return null === self::$memo ? null : self::$memo[0];
	}

	/**
	 * Drop the memo entry.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memo = null;
	}

	/**
	 * Whether host field definitions are unknown here: switched to another blog, the PHP, JSON and registered
	 * definitions are still the boot blog's. A caller that gets true skips its field work (see take_skipped()).
	 *
	 * @return bool
	 */
	public static function definitions_unavailable(): bool {
		// The stack's first entry is the blog the request booted on.
		$stack = $GLOBALS['_wp_switched_stack'] ?? [];

		if ( ! is_array( $stack ) || [] === $stack || \PerfLocale\Util\Scalar::int( reset( $stack ) ) === get_current_blog_id() ) {
			return false;
		}

		self::$skipped = true;

		return true;
	}

	/**
	 * Whether a post meta key travels on a switched run (definitions_unavailable()): core's, PerfLocale's own, and
	 * WooCommerce's own product data (WC_PRODUCT_META) while WooCommerce is active on this blog. Field plugins define
	 * the rest per blog, so a password there is unknown here; a failed read answers false.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function travels_switched( string $key ): bool {
		if ( in_array( $key, self::SWITCHED_CORE_META, true ) || str_starts_with( $key, '_perflocale_' ) ) {
			return true;
		}

		if ( null === self::$wc_keys ) {
			self::$wc_keys = array_fill_keys( self::WC_PRODUCT_META, true );

			// A loaded store can only narrow the list (a substituted store never adds a key); its failed read refuses.
			if ( class_exists( '\WC_Data_Store' ) ) {
				try {
					// is_callable(), not method_exists(): the WC_Data_Store wrapper forwards the call through __call().
					$store = \WC_Data_Store::load( 'product' );
					$read  = [ $store, 'get_internal_meta_keys' ];
					$keys  = is_object( $store ) && is_callable( $read ) ? call_user_func( $read ) : null;
				} catch ( \Throwable $e ) {
					$keys = null;
				}

				self::$wc_keys = is_array( $keys ) ? array_intersect_key( self::$wc_keys, array_flip( array_filter( $keys, 'is_string' ) ) ) : null;
			}

			// Subtype keys join only a list that was read (a failed store read refuses them too).
			self::$wc_keys = null === self::$wc_keys ? [] : self::$wc_keys + array_fill_keys( self::WC_SUBTYPE_META, true );
		}

		return isset( self::$wc_keys[ $key ] ) && self::wc_active_here();
	}

	/**
	 * Whether WooCommerce is active on the current blog, read from its plugin lists; a failed read answers false.
	 * Named by its usual file too: the booted blog may not have loaded it.
	 *
	 * @return bool
	 */
	private static function wc_active_here(): bool {
		$files = [ 'woocommerce/woocommerce.php' ];

		if ( defined( 'WC_PLUGIN_FILE' ) && is_string( WC_PLUGIN_FILE ) ) {
			$files[] = plugin_basename( WC_PLUGIN_FILE );
		}

		$blog    = get_option( 'active_plugins', null );
		$network = is_multisite() ? get_site_option( 'active_sitewide_plugins', null ) : [];

		if ( ! is_array( $blog ) || ! is_array( $network ) ) {
			return false;
		}

		foreach ( $files as $file ) {
			if ( in_array( $file, $blog, true ) || isset( $network[ $file ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether field work was skipped since the last call; resets.
	 *
	 * @return bool
	 */
	public static function take_skipped(): bool {
		$skipped       = self::$skipped;
		self::$skipped = false;

		return $skipped;
	}

	/**
	 * Add a descriptor.
	 *
	 * @param FieldDescriptor $descriptor Descriptor.
	 * @return void
	 */
	public function add( FieldDescriptor $descriptor ): void {
		$this->descriptors[]                = $descriptor;
		$this->by_key[ $descriptor->key ][] = count( $this->descriptors ) - 1;
	}

	/**
	 * Every descriptor, in producer order.
	 *
	 * @return array<int, FieldDescriptor>
	 */
	public function descriptors(): array {
		return $this->descriptors;
	}

	/**
	 * The descriptors of one key (several for a name shared by two flexible layouts, or a Meta Box blob).
	 *
	 * @param string $key Key.
	 * @return array<int, FieldDescriptor>
	 */
	public function descriptors_for( string $key ): array {
		$out = [];

		foreach ( $this->by_key[ $key ] ?? [] as $i ) {
			$out[] = $this->descriptors[ $i ];
		}

		return $out;
	}

	/**
	 * Whether any descriptor has the key.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->by_key[ $key ] );
	}

	/**
	 * Distinct keys the predicate accepts, in producer order; by default every class but `ignore`.
	 *
	 * @param callable|null $where `callable(FieldDescriptor): bool`.
	 * @return array<int, string>
	 * @phpstan-param (callable(FieldDescriptor): bool)|null $where
	 */
	public function keys( ?callable $where = null ): array {
		$seen = [];

		foreach ( $this->descriptors as $d ) {
			if ( null === $where ? FieldClass::IGNORE !== $d->class : $where( $d ) ) {
				$seen[ $d->key ] = true;
			}
		}

		return array_keys( $seen );
	}

	/**
	 * The class of a key when every descriptor of it agrees, else null.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function class_of( string $key ): ?string {
		$class = null;

		foreach ( $this->descriptors_for( $key ) as $d ) {
			if ( null !== $class && $class !== $d->class ) {
				return null;
			}

			$class = $d->class;
		}

		return $class;
	}

	/**
	 * The format of a text key when all its descriptors are text of one format, else null.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function format_of( string $key ): ?string {
		$format = null;

		foreach ( $this->descriptors_for( $key ) as $d ) {
			if ( FieldClass::TEXT !== $d->class || ( null !== $format && $format !== $d->format ) ) {
				return null;
			}

			$format = $d->format;
		}

		return $format;
	}

	/**
	 * The text leaves of the listed types with their format; a key classified twice differently is left out.
	 * Another host's descriptor of the same key still counts against it.
	 *
	 * @param array<int, string> $types Host field types.
	 * @param string             $host  `acf`, `mb`, or '' for every host.
	 * @return array<string, string> key => format.
	 */
	public function text_leaves( array $types, string $host = '' ): array {
		$out = [];

		foreach ( $this->descriptors as $d ) {
			if ( isset( $out[ $d->key ] ) || ! in_array( $d->type, $types, true ) || ( '' !== $host && $host !== $d->host ) ) {
				continue;
			}

			$format = $this->format_of( $d->key );

			if ( null === $format ) {
				continue;
			}

			foreach ( $this->descriptors_for( $d->key ) as $other ) {
				if ( ! in_array( $other->type, $types, true ) ) {
					continue 2;
				}
			}

			$out[ $d->key ] = $format;
		}

		return $out;
	}

	/**
	 * The enclosing rows of every key (first descriptor's), outermost first.
	 *
	 * @return array<string, array<int, array{0: string, 1: int, 2: string|null}>>
	 */
	public function chains(): array {
		$out = [];

		foreach ( $this->descriptors as $d ) {
			$out[ $d->key ] ??= $d->chain;
		}

		return $out;
	}

	/**
	 * The memo key of an object.
	 *
	 * @param string     $kind    Kind.
	 * @param int|string $id      Object id.
	 * @param string     $subtype Subtype.
	 * @return string
	 */
	private static function key_of( string $kind, int|string $id, string $subtype ): string {
		return get_current_blog_id() . ':' . $kind . ':' . $id . ':' . $subtype;
	}
}
