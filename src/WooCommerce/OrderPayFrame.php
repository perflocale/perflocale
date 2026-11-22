<?php
/**
 * Pay frames: while WooCommerce pays an existing order, currency and decimals follow that order.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brackets on MultiCurrency::$pay_frames around WooCommerce's pay routes; loaded only on those routes.
 *
 * A bracket binds the order object WooCommerce hands to its first needs_payment() call (PayPal Buttons: the order the
 * route saves after its key check); no request id is read.
 *
 * @internal
 */
final class OrderPayFrame {

	/** A push beyond this many entries refuses instead of growing. */
	private const CAP = 16;

	/**
	 * Whether the temporary hooks are registered.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * An arm at the cap pushed nothing: the next first needs_payment() call refuses.
	 *
	 * @var bool
	 */
	private static bool $refuse_next = false;

	/**
	 * Per `woocommerce_thankyou_paypal` call, innermost last: whether it opened a bracket.
	 *
	 * @var array<int, bool>
	 */
	private static array $thanks = [];

	/**
	 * Per thank-you template render, innermost last: whether it opened a display bracket.
	 *
	 * @var array<int, bool>
	 */
	private static array $shows = [];

	/**
	 * Order-received currency per "query var id:URL key" (null: that key is not that order's).
	 *
	 * @var array<string, ?string>
	 */
	private static array $page_orders = [];

	/**
	 * Order ids PayPal's IPN `custom` names (id and key, as get_paypal_order() reads them).
	 *
	 * @var array<int, int>
	 */
	private static array $ipn_ids = [];

	/**
	 * Classic order-pay POST, `wp` 19: before pay_action (20) loads the order.
	 *
	 * @return void
	 */
	public static function open_post(): void {
		self::open( 'post', true, null, true );
	}

	/**
	 * Classic order-pay POST, `wp` 21.
	 *
	 * @return void
	 */
	public static function close_post(): void {
		self::close( 'post' );
	}

	/**
	 * Order-pay page, `before_woocommerce_pay`: before either branch loads the order.
	 *
	 * @return void
	 */
	public static function open_get(): void {
		self::open( 'get', true, null, true );
	}

	/**
	 * Order-pay page end, `after_woocommerce_pay`.
	 *
	 * @return void
	 */
	public static function close_get(): void {
		self::close( 'get' );
	}

	/**
	 * PayPal PDT return or IPN: the order loads at full store decimals for PayPal's amount check; never binds.
	 *
	 * @param string $kind 'thanks' or 'ipn'.
	 * @return void
	 */
	public static function open_lossless( string $kind ): void {
		self::open( $kind, true, null, false );
	}

	/**
	 * PDT, `woocommerce_thankyou_paypal` PHP_INT_MIN: the page loaded the order first, so drop that HPOS copy.
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public static function open_thanks( mixed $order_id = 0 ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer key compared, as WooCommerce's shortcode does.
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$id  = is_numeric( $order_id ) ? (int) $order_id : 0;

		// Only for the order whose key is in the URL: a key lookup, no order load. The CPT store returns a numeric string.
		$found = $id > 0 && '' !== $key && function_exists( 'wc_get_order_id_by_order_key' ) ? wc_get_order_id_by_order_key( $key ) : 0;
		$open  = is_numeric( $found ) && (int) $found === $id;

		self::$thanks[] = $open;

		if ( $open ) {
			// A stale copy may be read: the handler's needs_payment() gets false, so PDT marks nothing paid.
			if ( ! self::uncache( $id ) ) {
				self::$refuse_next = true;
			}

			self::open_lossless( 'thanks' );
		}
	}

	/**
	 * PDT, `woocommerce_thankyou_paypal` PHP_INT_MAX: the rest of the page loads the order at the request decimals.
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public static function close_thanks( mixed $order_id = 0 ): void {
		if ( true === array_pop( self::$thanks ) ) {
			self::close( 'thanks' );
			self::uncache( $order_id );
		}
	}

	/**
	 * `woocommerce_before_template_part` (requests with `key`): the thank-you template with an order opens a display bracket.
	 *
	 * @param mixed $name Template name.
	 * @param mixed $path Template path.
	 * @param mixed $file Located file.
	 * @param mixed $args Template args.
	 * @return void
	 */
	public static function on_template( mixed $name, mixed $path = '', mixed $file = '', mixed $args = [] ): void {
		if ( 'checkout/thankyou.php' === $name && is_array( $args ) && is_object( $args['order'] ?? null ) ) {
			self::open_show( $args );
		}
	}

	/**
	 * `woocommerce_after_template_part` (requests with `key`).
	 *
	 * @param mixed $name Template name.
	 * @return void
	 */
	public static function after_thankyou( mixed $name ): void {
		if ( 'checkout/thankyou.php' === $name ) {
			self::close_show();
		}
	}

	/**
	 * `pre_render_block` PHP_INT_MAX (requests with `key`): last, so a block another filter short-circuited opens nothing.
	 *
	 * @param mixed $pre   Short-circuit value.
	 * @param mixed $block Parsed block.
	 * @return mixed
	 */
	public static function on_block( mixed $pre, mixed $block = [] ): mixed {
		if ( null === $pre && self::confirmation_block( $block ) ) {
			self::open_show_block();
		}

		return $pre;
	}

	/**
	 * `render_block` PHP_INT_MIN (requests with `key`).
	 *
	 * @param mixed $content Block HTML, passed through.
	 * @param mixed $block   Parsed block.
	 * @return mixed
	 */
	public static function after_block( mixed $content, mixed $block = [] ): mixed {
		if ( self::confirmation_block( $block ) ) {
			self::close_show();
		}

		return $content;
	}

	/**
	 * Whether a parsed block is one of WooCommerce's order-confirmation blocks (each loads the order itself).
	 *
	 * @param mixed $block Parsed block.
	 * @return bool
	 */
	private static function confirmation_block( mixed $block ): bool {
		return is_array( $block ) && is_string( $block['blockName'] ?? null ) && str_starts_with( $block['blockName'], 'woocommerce/order-confirmation-' );
	}

	/**
	 * Thank-you template start, `woocommerce_before_template_part`: binds the order whose key is in the URL (display only).
	 *
	 * The shortcode loaded it at the request decimals, so take() re-reads it in place inside the bracket.
	 *
	 * @param mixed $args Template args.
	 * @return void
	 */
	public static function open_show( mixed $args ): void {
		$order = self::wc_object( is_array( $args ) ? ( $args['order'] ?? null ) : null, 'WC_Order' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer key compared, as WooCommerce's shortcode does.
		$key   = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$owned = null !== $order && '' !== $key && method_exists( $order, 'get_order_key' ) && is_string( $order->get_order_key() )
			&& hash_equals( $order->get_order_key(), $key );
		$count = count( MultiCurrency::$pay_frames );

		if ( $owned ) {
			self::open( 'show', false, null, false );
		}

		$pushed         = count( MultiCurrency::$pay_frames ) > $count;
		self::$shows[] = $pushed;

		// A failed take leaves the entry without an opinion: the page shows the request values, as without it.
		if ( $pushed && null !== $order ) {
			self::take( $count, $order );
		}
	}

	/**
	 * Order-confirmation block start, `pre_render_block`: the block's own order load runs at the order currency's decimals.
	 *
	 * @return void
	 */
	public static function open_show_block(): void {
		$page  = self::page_order();
		$count = count( MultiCurrency::$pay_frames );

		if ( null !== $page['code'] ) {
			// The page or an earlier block may have cached a copy rounded at the request decimals.
			self::uncache( $page['id'] );
			self::open( 'show', false, null, false );
		}

		$pushed        = count( MultiCurrency::$pay_frames ) > $count;
		self::$shows[] = $pushed;

		if ( $pushed ) {
			MultiCurrency::$pay_frames[ $count ]['code'] = $page['code'];
		}
	}

	/**
	 * The order-received query var's order when the URL key is its key (a key lookup), with its stored currency.
	 *
	 * @return array{id: int, code: ?string}
	 */
	private static function page_order(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer key compared, as WooCommerce's blocks do.
		$key  = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$qv   = function_exists( 'get_query_var' ) ? get_query_var( 'order-received' ) : 0;
		$id   = is_numeric( $qv ) ? (int) $qv : 0;
		$memo = $id . ':' . $key;

		// Per id and key: a later render in the same request may name another order or key.
		if ( ! array_key_exists( $memo, self::$page_orders ) ) {
			$found = $id > 0 && '' !== $key && function_exists( 'wc_get_order_id_by_order_key' ) ? wc_get_order_id_by_order_key( $key ) : 0;
			$code  = null;

			if ( is_numeric( $found ) && (int) $found === $id && function_exists( 'wc_get_order' ) ) {
				$order = self::wc_object( wc_get_order( $id ), 'WC_Order' );
				$cur   = null !== $order && method_exists( $order, 'get_currency' ) ? $order->get_currency( 'edit' ) : '';
				$code  = is_string( $cur ) && 1 === preg_match( '/^[A-Z]{3}$/', $cur ) ? $cur : null;
			}

			if ( count( self::$page_orders ) >= self::CAP ) {
				self::$page_orders = [];
			}

			self::$page_orders[ $memo ] = $code;
		}

		return [ 'id' => $id, 'code' => self::$page_orders[ $memo ] ];
	}

	/**
	 * Thank-you template end, `woocommerce_after_template_part`.
	 *
	 * @return void
	 */
	public static function close_show(): void {
		if ( true === array_pop( self::$shows ) ) {
			self::close( 'show' );
		}
	}

	/**
	 * IPN, `woocommerce_api_request`: bracket only the handler PayPal's verification lets through (it exits inside).
	 *
	 * @return void
	 */
	public static function arm_ipn(): void {
		add_action( 'valid-paypal-standard-ipn-request', [ self::class, 'open_ipn' ], PHP_INT_MIN, 1 );
		add_action( 'valid-paypal-standard-ipn-request', [ self::class, 'close_ipn' ], PHP_INT_MAX, 0 );
	}

	/**
	 * IPN handler start: drops the order's cached copy (a persistent object cache keeps one rounded by another request).
	 *
	 * @param mixed $posted PayPal's verified IPN fields.
	 * @return void
	 */
	public static function open_ipn( mixed $posted = [] ): void {
		self::$ipn_ids = self::ipn_order_ids( $posted );

		// The amount check must not read a stale copy: answer busy, PayPal retries the IPN.
		if ( ! self::uncache_ipn() ) {
			wp_die( esc_html( self::refusal() ), '', [ 'response' => 503 ] );
		}

		self::open_lossless( 'ipn' );
	}

	/**
	 * IPN handler end (an exit inside it is closed at shutdown).
	 *
	 * @return void
	 */
	public static function close_ipn(): void {
		self::close( 'ipn' );
		self::uncache_ipn();
		self::$ipn_ids = [];
	}

	/**
	 * Order ids in the IPN `custom` field: its order_id and the order of its order_key (WooCommerce's fallback).
	 *
	 * @param mixed $posted IPN fields.
	 * @return array<int, int>
	 */
	private static function ipn_order_ids( mixed $posted ): array {
		$raw    = is_array( $posted ) && isset( $posted['custom'] ) && is_string( $posted['custom'] ) ? $posted['custom'] : '';
		$custom = '' !== $raw ? json_decode( $raw ) : null;

		if ( ! is_object( $custom ) ) {
			return [];
		}

		$ids = [];

		if ( isset( $custom->order_id ) && is_numeric( $custom->order_id ) ) {
			$ids[] = (int) $custom->order_id;
		}

		if ( isset( $custom->order_key ) && is_string( $custom->order_key ) && '' !== $custom->order_key && function_exists( 'wc_get_order_id_by_order_key' ) ) {
			$found = wc_get_order_id_by_order_key( $custom->order_key );

			if ( is_numeric( $found ) ) {
				$ids[] = (int) $found;
			}
		}

		return array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * Drop the cached copies of the IPN's orders.
	 *
	 * @return bool False: a copy could not be dropped.
	 */
	private static function uncache_ipn(): bool {
		$ok = true;

		foreach ( self::$ipn_ids as $id ) {
			$ok = self::uncache( $id ) && $ok;
		}

		return $ok;
	}

	/**
	 * `shutdown` PHP_INT_MIN: every bracket still open was stranded by an exit or a throw (pay redirect, IPN), so all close.
	 *
	 * Idempotent: also runs after the normal closes, or twice.
	 *
	 * @return void
	 */
	public static function close_lossless(): void {
		if ( [] !== MultiCurrency::$pay_frames ) {
			self::truncate( 0 );
		}

		self::uncache_ipn();
		self::$ipn_ids = [];
	}

	/**
	 * Order email, `woocommerce_allow_switching_email_locale` (WC_Email::setup_locale()): renders at the language decimals.
	 *
	 * @param mixed $allow Passed through.
	 * @return mixed
	 */
	public static function mail_open( mixed $allow ): mixed {
		$f = self::top();

		if ( null !== $f && $f['lossless'] && null === $f['code'] ) {
			self::open( 'mail', false, null, false );
		}

		return $allow;
	}

	/**
	 * Order email end, `woocommerce_allow_restoring_email_locale` (WC_Email::restore_locale()).
	 *
	 * @param mixed $allow Passed through.
	 * @return mixed
	 */
	public static function mail_close( mixed $allow ): mixed {
		self::close( 'mail' );

		return $allow;
	}

	/**
	 * Store API CheckoutOrder dispatch: a marker (no opinion) until the bind; closed only by the same request object.
	 *
	 * @param mixed $request WP_REST_Request of this dispatch.
	 * @return void
	 */
	public static function arm_rest( mixed $request ): void {
		if ( is_object( $request ) ) {
			self::open( 'rest', false, $request, true );
		}
	}

	/**
	 * PayPal Buttons create-order dispatch: binds at the route's save, after WooCommerce's key and status checks.
	 *
	 * @param mixed $request WP_REST_Request of this dispatch.
	 * @return void
	 */
	public static function arm_paypal_buttons( mixed $request ): void {
		if ( is_object( $request ) ) {
			self::open( 'ppbtn', false, $request, true );
		}
	}

	/**
	 * REST close, `rest_request_after_callbacks`: only this request's bracket.
	 *
	 * @param mixed $response Response, passed through.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function close_rest( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		// Topmost first: the same request object dispatched again inside its callback closes only the inner bracket.
		for ( $i = count( MultiCurrency::$pay_frames ) - 1; $i >= 0; $i-- ) {
			$req = MultiCurrency::$pay_frames[ $i ]['req'];

			if ( null !== $req && $req === $request ) {
				self::truncate( $i );
				break;
			}
		}

		return $response;
	}

	/**
	 * The entry that applies on the current blog (top of the stack), if any.
	 *
	 * @return array{kind: string, blog: int, code: ?string, lossless: bool, obj: int, ref: ?\WeakReference<object>, req: ?object, bind: bool, used: bool, ob: ?int}|null
	 */
	public static function top(): ?array {
		$i = self::top_index();

		return null === $i ? null : MultiCurrency::$pay_frames[ $i ];
	}

	/**
	 * Bind, `woocommerce_order_needs_payment` (PHP_INT_MIN): the first call of a bracket takes WooCommerce's object.
	 *
	 * Only that first call may refuse: a later false means paid without payment (form-handler :627, CheckoutOrder :210).
	 *
	 * @param mixed $needs    WooCommerce's value.
	 * @param mixed $order    Order asked.
	 * @param mixed $statuses Statuses valid for payment.
	 * @return mixed
	 */
	public static function bind( mixed $needs, mixed $order = null, mixed $statuses = [] ): mixed {
		$i = self::top_index();

		$order = self::wc_object( $order, 'WC_Order' );

		if ( null === $i || null === $order || ! method_exists( $order, 'get_currency' ) || ! method_exists( $order, 'get_changes' )
			|| ! method_exists( $order, 'get_data_store' ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'has_status' )
			|| ! method_exists( $order, 'get_total' ) ) {
			return $needs;
		}

		if ( self::$refuse_next ) {
			self::$refuse_next = false;
			return false;
		}

		$f = MultiCurrency::$pay_frames[ $i ];

		if ( ! $f['bind'] || $f['used'] || 'ppbtn' === $f['kind'] ) {
			return $needs;
		}

		MultiCurrency::$pay_frames[ $i ]['used'] = true;

		return self::take( $i, $order ) ? ( $order->has_status( $statuses ) && $order->get_total() > 0 ) : false;
	}

	/**
	 * PayPal Buttons, `woocommerce_before_order_object_save`: the route's own save (gateway set, payable status) marks its object.
	 *
	 * @param mixed $order Order being saved.
	 * @return void
	 */
	public static function mark_save( mixed $order ): void {
		$i     = self::top_index();
		$order = self::wc_object( $order, 'WC_Order' );

		if ( null === $i || null === $order || ! method_exists( $order, 'get_payment_method' ) || ! method_exists( $order, 'has_status' ) ) {
			return;
		}

		$f = MultiCurrency::$pay_frames[ $i ];

		if ( 'ppbtn' !== $f['kind'] || $f['used'] || 'paypal' !== $order->get_payment_method() || ! $order->has_status( [ 'pending', 'checkout-draft' ] ) ) {
			return;
		}

		MultiCurrency::$pay_frames[ $i ] = array_merge( $f, [ 'used' => true, 'ref' => \WeakReference::create( $order ) ] );
	}

	/**
	 * PayPal Buttons, `woocommerce_after_order_object_save`: binds the marked object once the save wrote it (no changes left).
	 *
	 * @param mixed $order Order saved.
	 * @return void
	 */
	public static function bind_saved( mixed $order ): void {
		$i     = self::top_index();
		$order = self::wc_object( $order, 'WC_Order' );

		if ( null === $i || null === $order ) {
			return;
		}

		$f = MultiCurrency::$pay_frames[ $i ];

		// Identity: a save nested inside the route's save is another object.
		if ( 'ppbtn' !== $f['kind'] || ! $f['bind'] || ! $f['used'] || null === $f['ref'] || $f['ref']->get() !== $order ) {
			return;
		}

		MultiCurrency::$pay_frames[ $i ]['bind'] = false;
		self::take( $i, $order );
	}

	/**
	 * PayPal create-order, `woocommerce_paypal_supported_currencies`: a Buttons order past its save but unbound sends nothing.
	 *
	 * Request.php:695 then throws inside create_paypal_order(), which returns null (400) before any PayPal call.
	 *
	 * @param mixed $currencies Supported codes.
	 * @return mixed
	 */
	public static function paypal_currencies( mixed $currencies ): mixed {
		$i = self::top_index();
		$f = null === $i ? null : MultiCurrency::$pay_frames[ $i ];

		if ( self::$refuse_next || ( null !== $f && 'ppbtn' === $f['kind'] && $f['used'] && null === $f['code'] ) ) {
			return [];
		}

		return $currencies;
	}

	/**
	 * Bind bracket $i to WooCommerce's object: its stored currency, re-read in place inside the frame.
	 *
	 * @param int    $i     Index.
	 * @param object $order WC_Order.
	 * @return bool False: refuse (the bracket keeps no currency).
	 */
	private static function take( int $i, object $order ): bool {
		$f = MultiCurrency::$pay_frames[ $i ];

		if ( ! method_exists( $order, 'get_currency' ) || ! method_exists( $order, 'get_changes' ) || ! method_exists( $order, 'get_data_store' ) || ! method_exists( $order, 'get_id' ) ) {
			return false;
		}

		$code = $order->get_currency( 'edit' );

		// Q2: unsaved changes would be lost by the re-read, so refuse.
		if ( ! is_string( $code ) || 1 !== preg_match( '/^[A-Z]{3}$/', $code ) || [] !== $order->get_changes() ) {
			return false;
		}

		$f['code'] = $code;
		$f['obj']  = spl_object_id( $order );
		$f['ref']  = \WeakReference::create( $order );

		MultiCurrency::$pay_frames[ $i ] = $f;

		// set_currency() reads an empty stored currency as get_woocommerce_currency(): empty here, so the check below refuses.
		$no_fallback = static fn() => '';
		add_filter( 'woocommerce_currency', $no_fallback, PHP_INT_MAX );

		try {
			// Loaded before the bind at other decimals (set_total() rounds at read): re-read in place inside the frame.
			// An unexpected shape throws, as calling it did: the catch refuses.
			$store = $order->get_data_store();

			if ( ! is_object( $store ) || ! method_exists( $store, 'read' ) ) {
				throw new \UnexpectedValueException( 'read' );
			}

			$store->read( $order );

			if ( ! self::uncache( $order->get_id() ) ) {
				throw new \UnexpectedValueException( 'remove' );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			$code = null;
		} finally {
			remove_filter( 'woocommerce_currency', $no_fallback, PHP_INT_MAX );
		}

		if ( null === $code || $order->get_currency( 'edit' ) !== $code ) {
			MultiCurrency::$pay_frames[ $i ] = array_merge( $f, [ 'code' => null, 'obj' => 0, 'ref' => null ] );
			return false;
		}

		return true;
	}

	/**
	 * Drop an order's HPOS cached copy (loaded at other decimals).
	 *
	 * @param mixed $order_id Order id.
	 * @return bool False: the cache is in use but cannot be cleared.
	 */
	private static function uncache( mixed $order_id ): bool {
		if ( ! is_numeric( $order_id ) || (int) $order_id <= 0 || ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			|| ! \Automattic\WooCommerce\Utilities\OrderUtil::orders_cache_usage_is_enabled() ) {
			return true;
		}

		$container = function_exists( 'wc_get_container' ) ? wc_get_container() : null;
		$cache     = is_object( $container ) && method_exists( $container, 'get' ) ? $container->get( 'Automattic\\WooCommerce\\Caches\\OrderCache' ) : null;

		if ( ! is_object( $cache ) || ! method_exists( $cache, 'remove' ) ) {
			return false;
		}

		$cache->remove( (int) $order_id );

		return true;
	}

	/**
	 * Classic POST verify, `woocommerce_before_pay_action`: an error notice stops process_payment().
	 *
	 * @param mixed $order Order WooCommerce pays.
	 * @return void
	 */
	public static function verify_post( mixed $order ): void {
		$i = self::top_of( 'post' );

		if ( null !== $i && ! self::bound_to( $i, $order ) ) {
			self::inert( $i );
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( self::refusal(), 'error' );
			}
		}
	}

	/**
	 * Order-pay form verify, `before_woocommerce_pay_form`: another object leaves the page at the request values.
	 *
	 * @param mixed $order Order on the form.
	 * @return void
	 */
	public static function verify_form( mixed $order ): void {
		$i = self::top_of( 'get' );

		if ( null !== $i && ! self::bound_to( $i, $order ) ) {
			self::inert( $i );
		}
	}

	/**
	 * Store API verify, `woocommerce_store_api_checkout_order_processed`.
	 *
	 * @param mixed $order Order WooCommerce pays.
	 * @return void
	 * @throws \Exception RouteException (Store API) on another object.
	 */
	public static function verify_rest( mixed $order ): void {
		$i = self::top_of( 'rest' );

		if ( null !== $i && ! self::bound_to( $i, $order ) ) {
			self::inert( $i );
			self::route_refusal();
		}
	}

	/**
	 * Store API verify, `woocommerce_rest_checkout_process_payment_with_context` (1): before the legacy adapter (999).
	 *
	 * @param mixed $context PaymentContext.
	 * @return void
	 * @throws \Exception RouteException (Store API) on another object.
	 */
	public static function verify_context( mixed $context ): void {
		// PaymentContext has __get() but no __isset(): call it, never isset() or ??.
		$context = self::wc_object( $context, 'Automattic\\WooCommerce\\StoreApi\\Payments\\PaymentContext' );
		$order   = null !== $context && method_exists( $context, '__get' ) ? $context->__get( 'order' ) : null;

		self::verify_rest( $order );
	}

	/**
	 * Receipt verify, `woocommerce_before_template_part`: another object's receipt is buffered and dropped.
	 *
	 * @param mixed $name Template name.
	 * @param mixed $path Template path.
	 * @param mixed $file Located file.
	 * @param mixed $args Template args.
	 * @return void
	 */
	public static function before_template( mixed $name, mixed $path = '', mixed $file = '', mixed $args = [] ): void {
		$i = 'checkout/order-receipt.php' === $name ? self::top_of( 'get' ) : null;

		if ( null !== $i && ! self::bound_to( $i, is_array( $args ) ? ( $args['order'] ?? null ) : null ) ) {
			ob_start();
			MultiCurrency::$pay_frames[ $i ]['ob'] = ob_get_level();
			self::inert( $i );
		}
	}

	/**
	 * `woocommerce_after_template_part`: ends a dropped receipt; closes the page bracket on its two early returns.
	 *
	 * @param mixed $name Template name.
	 * @return void
	 */
	public static function after_template( mixed $name ): void {
		$i = self::top_of( 'get' );

		if ( null === $i ) {
			return;
		}

		$ob = MultiCurrency::$pay_frames[ $i ]['ob'];

		if ( 'checkout/order-receipt.php' === $name && null !== $ob ) {
			while ( ob_get_level() >= $ob ) {
				ob_end_clean();
			}
			MultiCurrency::$pay_frames[ $i ]['ob'] = null;
			if ( function_exists( 'wc_print_notice' ) ) {
				wc_print_notice( esc_html( self::refusal() ), 'error' );
			}
		} elseif ( 'global/form-login.php' === $name || 'checkout/form-verify-email.php' === $name ) {
			// order_pay() returns after these without after_woocommerce_pay.
			self::truncate( $i );
		}
	}

	/**
	 * Store API recalculation, `woocommerce_order_before_calculate_totals`: rounds in that object's own currency.
	 *
	 * @param mixed $and_taxes Taxes flag.
	 * @param mixed $order     Order being recalculated.
	 * @return void
	 * @throws \Exception RouteException (Store API) on an unusable currency or at the cap.
	 */
	public static function before_calc( mixed $and_taxes, mixed $order = null ): void {
		$i = self::top_index();

		while ( null !== $i && 'calc' === MultiCurrency::$pay_frames[ $i ]['kind'] ) {
			$i = self::top_index( $i );
		}

		$order = self::wc_object( $order, 'WC_Order' );

		if ( null === $i || 'rest' !== MultiCurrency::$pay_frames[ $i ]['kind'] || null === $order || ! method_exists( $order, 'get_currency' ) ) {
			return;
		}

		$code = $order->get_currency( 'edit' );

		if ( ! is_string( $code ) || 1 !== preg_match( '/^[A-Z]{3}$/', $code ) || count( MultiCurrency::$pay_frames ) >= self::CAP ) {
			self::route_refusal();
		}

		MultiCurrency::$pay_frames[] = self::entry( 'calc', false, null, false, $code, $order );
	}

	/**
	 * `woocommerce_order_after_calculate_totals`: drop that object's recalculation frame.
	 *
	 * @param mixed $and_taxes Taxes flag.
	 * @param mixed $order     Order recalculated.
	 * @return void
	 */
	public static function after_calc( mixed $and_taxes, mixed $order = null ): void {
		for ( $i = count( MultiCurrency::$pay_frames ) - 1; $i >= 0; $i-- ) {
			$f = MultiCurrency::$pay_frames[ $i ];

			if ( 'calc' === $f['kind'] && null !== $f['ref'] && $f['ref']->get() === $order ) {
				self::truncate( $i );
				return;
			}
		}
	}

	/**
	 * Push a bracket.
	 *
	 * @param string      $kind     Bracket kind.
	 * @param bool        $lossless Store decimals until the bind.
	 * @param object|null $req      Store API request.
	 * @param bool        $bind     Whether it binds at needs_payment().
	 * @return void
	 */
	private static function open( string $kind, bool $lossless, ?object $req, bool $bind ): void {
		if ( count( MultiCurrency::$pay_frames ) >= self::CAP ) {
			// Never clears a pending refusal (a failed push is not a grant).
			self::$refuse_next = self::$refuse_next || $bind;
			return;
		}

		MultiCurrency::$pay_frames[] = self::entry( $kind, $lossless, $req, $bind, null, null );
		self::hooks( true );
	}

	/**
	 * A stack entry (format shared with the 1.0.8.1 guard).
	 *
	 * @param string         $kind     Kind.
	 * @param bool           $lossless Lossless while unbound.
	 * @param object|null    $req      Request.
	 * @param bool           $bind     Binds.
	 * @param string|null    $code     Currency code.
	 * @param object|null    $order    WC_Order.
	 * @return array{kind: string, blog: int, code: ?string, lossless: bool, obj: int, ref: ?\WeakReference<object>, req: ?object, bind: bool, used: bool, ob: ?int}
	 */
	private static function entry( string $kind, bool $lossless, ?object $req, bool $bind, ?string $code, ?object $order ): array {
		return [
			'kind'     => $kind,
			'blog'     => get_current_blog_id(),
			'code'     => $code,
			'lossless' => $lossless,
			'obj'      => null === $order ? 0 : spl_object_id( $order ),
			'ref'      => null === $order ? null : \WeakReference::create( $order ),
			'req'      => $req,
			'bind'     => $bind,
			'used'     => false,
			'ob'       => null,
		];
	}

	/**
	 * Truncate to the topmost bracket of a kind.
	 *
	 * @param string $kind Kind.
	 * @return void
	 */
	private static function close( string $kind ): void {
		for ( $i = count( MultiCurrency::$pay_frames ) - 1; $i >= 0; $i-- ) {
			if ( $kind === MultiCurrency::$pay_frames[ $i ]['kind'] ) {
				self::truncate( $i );
				return;
			}
		}
	}

	/**
	 * Drop entry $i and everything above it (nested or stranded by an exception).
	 *
	 * @param int $i Index.
	 * @return void
	 */
	private static function truncate( int $i ): void {
		array_splice( MultiCurrency::$pay_frames, $i );

		if ( [] === MultiCurrency::$pay_frames ) {
			self::hooks( false );
		}
	}

	/**
	 * Index of the top entry on the current blog, below $below when given.
	 *
	 * @param int|null $below Search below this index.
	 * @return int|null
	 */
	private static function top_index( ?int $below = null ): ?int {
		$blog = get_current_blog_id();

		for ( $i = ( $below ?? count( MultiCurrency::$pay_frames ) ) - 1; $i >= 0; $i-- ) {
			if ( $blog === MultiCurrency::$pay_frames[ $i ]['blog'] ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Index of the topmost bracket of a kind on the current blog.
	 *
	 * @param string $kind Kind.
	 * @return int|null
	 */
	private static function top_of( string $kind ): ?int {
		$blog = get_current_blog_id();

		for ( $i = count( MultiCurrency::$pay_frames ) - 1; $i >= 0; $i-- ) {
			$f = MultiCurrency::$pay_frames[ $i ];

			if ( $kind === $f['kind'] && $blog === $f['blog'] ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Whether bracket $i is bound to exactly this object.
	 *
	 * @param int   $i     Index.
	 * @param mixed $order Object to check.
	 * @return bool
	 */
	private static function bound_to( int $i, mixed $order ): bool {
		$ref = MultiCurrency::$pay_frames[ $i ]['ref'];

		return null !== MultiCurrency::$pay_frames[ $i ]['code'] && null !== $ref && is_object( $order ) && $ref->get() === $order;
	}

	/**
	 * A failed verify: the bracket keeps no opinion (request values) and never binds again.
	 *
	 * @param int $i Index.
	 * @return void
	 */
	private static function inert( int $i ): void {
		MultiCurrency::$pay_frames[ $i ] = array_merge(
			MultiCurrency::$pay_frames[ $i ],
			[
				'code'     => null,
				'lossless' => false,
				'used'     => true,
				'ref'      => null,
				'obj'      => 0,
			]
		);
	}

	/**
	 * A WooCommerce object of the named class, else null (by name: PHPStan has no WooCommerce classes; never autoloads).
	 *
	 * @param mixed  $value Value.
	 * @param string $class Class name.
	 * @return object|null
	 */
	private static function wc_object( mixed $value, string $class ): ?object {
		return is_object( $value ) && is_a( $value, $class ) ? $value : null;
	}

	/**
	 * The refusal text.
	 *
	 * @return string
	 */
	private static function refusal(): string {
		return __( 'This order cannot be paid for. Please contact us if you need assistance.', 'perflocale' );
	}

	/**
	 * Throw the Store API refusal.
	 *
	 * @return never
	 * @throws \Exception RouteException (Store API); RuntimeException without it.
	 */
	private static function route_refusal(): never {
		if ( class_exists( 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'perflocale_order_pay_refused', esc_html( self::refusal() ), 400 );
		}

		throw new \RuntimeException( esc_html( self::refusal() ) );
	}

	/**
	 * Register or remove the hooks that act only while a bracket is open.
	 *
	 * @param bool $on Register.
	 * @return void
	 */
	private static function hooks( bool $on ): void {
		if ( $on === self::$hooked ) {
			return;
		}

		self::$hooked = $on;

		// Filters return their value; the rest are actions.
		$filters = [
			[ 'woocommerce_order_needs_payment', 'bind', PHP_INT_MIN, 3 ],
			[ 'rest_request_after_callbacks', 'close_rest', PHP_INT_MAX, 3 ],
			[ 'woocommerce_paypal_supported_currencies', 'paypal_currencies', PHP_INT_MAX, 1 ],
			[ 'woocommerce_allow_switching_email_locale', 'mail_open', PHP_INT_MIN, 1 ],
			[ 'woocommerce_allow_restoring_email_locale', 'mail_close', PHP_INT_MAX, 1 ],
		];
		$actions = [
			[ 'woocommerce_before_pay_action', 'verify_post', PHP_INT_MIN, 1 ],
			[ 'before_woocommerce_pay_form', 'verify_form', PHP_INT_MIN, 1 ],
			[ 'woocommerce_before_template_part', 'before_template', PHP_INT_MIN, 4 ],
			[ 'woocommerce_after_template_part', 'after_template', PHP_INT_MAX, 1 ],
			[ 'after_woocommerce_pay', 'close_get', PHP_INT_MAX, 0 ],
			[ 'woocommerce_store_api_checkout_order_processed', 'verify_rest', PHP_INT_MIN, 1 ],
			[ 'woocommerce_rest_checkout_process_payment_with_context', 'verify_context', 1, 1 ],
			[ 'woocommerce_order_before_calculate_totals', 'before_calc', PHP_INT_MIN, 2 ],
			[ 'woocommerce_order_after_calculate_totals', 'after_calc', PHP_INT_MAX, 2 ],
			[ 'woocommerce_before_order_object_save', 'mark_save', PHP_INT_MIN, 1 ],
			[ 'woocommerce_after_order_object_save', 'bind_saved', PHP_INT_MIN, 1 ],
			[ 'shutdown', 'close_lossless', PHP_INT_MIN, 0 ],
		];

		foreach ( $filters as [ $hook, $method, $priority, $args ] ) {
			if ( $on ) {
				add_filter( $hook, [ self::class, $method ], $priority, $args );
			} else {
				remove_filter( $hook, [ self::class, $method ], $priority );
			}
		}

		foreach ( $actions as [ $hook, $method, $priority, $args ] ) {
			if ( $on ) {
				add_action( $hook, [ self::class, $method ], $priority, $args );
			} else {
				remove_action( $hook, [ self::class, $method ], $priority );
			}
		}
	}
}
