<?php
defined( 'ABSPATH' ) || exit;

/**
 * A bounded WooCommerce quote calculation with no checkout session or order.
 * The public route is available only when the merchant enables the quote option.
 */
final class KaliCart_Bridge_Quote {
	private const MAX_ITEMS = 20;
	private const CORE_SHIPPING_METHODS = [ 'flat_rate', 'free_shipping', 'local_pickup', 'pickup_location' ];
	private const PICKUP_METHODS = [ 'local_pickup', 'pickup_location' ];
	private const MAX_BODY_BYTES = 8192;
	private const REQUESTS_PER_MINUTE = 30;

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'pre_dispatch' ], 1, 3 );
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'no_store' ], 20, 3 );
	}

	public static function register_routes(): void {
		// The quote is a merchant setting, on by default since 1.0.154 (one-time migration with notice).
		if ( ! get_option( 'kalicart_bridge_quote_enabled', false ) ) { return; }
		register_rest_route( KALICART_BRIDGE_API_NS, '/checkout/quote', [
			'methods' => 'POST',
			'callback' => [ __CLASS__, 'request' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function no_store( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request
			|| '/' . KALICART_BRIDGE_API_NS . '/checkout/quote' !== $request->get_route()
			|| ! $result instanceof WP_REST_Response ) { return $result; }
		$result->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$result->header( 'Pragma', 'no-cache' );
		return $result;
	}

	public static function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request
			|| 'POST' !== $request->get_method()
			|| '/' . KALICART_BRIDGE_API_NS . '/checkout/quote' !== $request->get_route()
			|| ! get_option( 'kalicart_bridge_quote_enabled', false ) ) { return $result; }
		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'content-type' ), 2 )[0] ) );
		if ( 'application/json' !== $content_type ) {
			return new WP_REST_Response( [ 'error' => 'Content-Type must be application/json.' ], 415 );
		}
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new WP_REST_Response( [ 'error' => 'Quote request exceeds size limit.' ], 413 );
		}
		$limit = self::rate_limit();
		if ( true !== $limit ) { return $limit; }
		return $result;
	}

	/** Local operational counter: no WordPress transient, session or funnel write. */
	private static function rate_limit() {
		$dir = trailingslashit( get_temp_dir() ) . 'kalicart-quote-' . sha1( home_url( '/' ) . ':' . get_current_blog_id() );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- private directory for the native atomic lock.
			return new WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'rate_limit_unavailable' ], 503 );
		}
		clearstatcache( true, $dir );
		if ( is_link( $dir ) || ( fileperms( $dir ) & 0077 ) !== 0 || ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- require a writable private lock directory.
			return new WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'rate_limit_unavailable' ], 503 );
		}
		$path = trailingslashit( $dir ) . 'rate.json';
		$handle = @fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- native flock provides an atomic cross-process limit.
		if ( false === $handle ) {
			return new WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'rate_limit_unavailable' ], 503 );
		}
		try {
			if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
				return new WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'quote_busy' ], 429 );
			}
			$state = json_decode( stream_get_contents( $handle ), true );
			$now = time();
			if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || $now - (int) $state['start'] >= 60 ) {
				$state = [ 'start' => $now, 'count' => 0 ];
			}
			if ( (int) $state['count'] >= self::REQUESTS_PER_MINUTE ) {
				$response = new WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'quote_rate_limited' ], 429 );
				$response->header( 'Retry-After', (string) max( 1, 60 - ( $now - (int) $state['start'] ) ) );
				return $response;
			}
			$state['count'] = (int) $state['count'] + 1;
			rewind( $handle );
			if ( ! ftruncate( $handle, 0 ) || false === fwrite( $handle, wp_json_encode( $state ) ) || ! fflush( $handle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- write under native flock.
				return new WP_REST_Response( [ 'status' => 'unknown', 'reason' => 'rate_limit_unavailable' ], 503 );
			}
			return true;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- release native flock handle.
		}
	}

	public static function request( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body )
			|| array_diff( array_keys( $body ), [ 'items', 'destination' ] )
			|| ! isset( $body['items'], $body['destination'] )
			|| ! is_array( $body['items'] ) || ! is_array( $body['destination'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Expected items and destination objects.' ], 400 );
		}
		foreach ( $body['items'] as $line ) {
			if ( ! is_array( $line ) || array_diff( array_keys( $line ), [ 'product_id', 'variation_id', 'quantity' ] ) ) {
				return new WP_REST_Response( [ 'error' => 'Invalid item fields.' ], 400 );
			}
		}
		if ( array_diff( array_keys( $body['destination'] ), [ 'country', 'state', 'postcode', 'city' ] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid destination fields.' ], 400 );
		}
		return new WP_REST_Response( self::calculate( $body['items'], $body['destination'] ), 200 );
	}

	private static function unknown( string $reason ): array {
		return [ 'status' => 'unknown', 'reason' => $reason ];
	}

	private static function shipping_cache_ready(): bool {
		$version = get_transient( 'shipping-transient-version' );
		$counts  = get_transient( 'wc_shipping_method_count' );
		return is_string( $version ) && '' !== $version && is_array( $counts )
			&& isset( $counts['version'], $counts['legacy'], $counts['enabled'], $counts['disabled'] )
			&& $counts['version'] === $version;
	}


	/**
	 * 1.0.155: the temporary session is a full WooCommerce session handler, so cart hooks of other plugins find the
	 * API they expect (has_session, get_customer_unique_id, cookies...). Nothing is persisted: no cookie, no
	 * session row, no cleanup; data lives in memory for this one calculation.
	 */
	private static function temporary_session(): WC_Session {
		if ( ! class_exists( 'WC_Session_Handler' ) ) {
			return new class extends WC_Session {};
		}
		return new class extends WC_Session_Handler {
			public function __construct() {
				parent::__construct();
				$this->_customer_id = 't_' . substr( md5( uniqid( 'kalicart-quote', true ) ), 0, 30 );
				$this->_data = [];
			}
			public function init() {}
			public function init_session_cookie() {}
			public function maybe_set_customer_session_cookie() {}
			public function set_customer_session_cookie( $set ) {}
			public function has_session() { return false; }
			public function get_session_cookie() { return false; }
			public function save_data( $old_session_key = '' ) {}
			public function destroy_session() { $this->_data = []; }
			public function forget_session() { $this->_data = []; }
			public function cleanup_sessions() {}
			public function delete_session( $customer_id ) {}
			public function update_session_timestamp( $customer_id, $timestamp ) {}
			public function destroy_session_if_empty() {}
			public function maybe_update_nonce_user_logged_out( $uid, $action ) { return $uid; }
		};
	}

	/** Short, non-sensitive reason for a failed calculation: error type and the plugin it came from. */
	private static function diagnostic( Throwable $error ): array {
		$out = [ 'error' => ( new ReflectionClass( $error ) )->getShortName() ];
		if ( preg_match( '/Call to undefined method [\\\w@]+::(\w+)\(\)/', $error->getMessage(), $m ) ) {
			$out['undefined_method'] = $m[1];
		}
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		foreach ( array_merge( [ [ 'file' => $error->getFile() ] ], $error->getTrace() ) as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( 0 === strpos( $file, $plugins ) ) {
				$slug = strtok( substr( $file, strlen( $plugins ) ), '/' );
				if ( $slug && 'kalicart-bridge' !== $slug && 'woocommerce' !== $slug ) { $out['plugin'] = sanitize_key( $slug ); break; }
			}
		}
		return $out;
	}

	/** Missing address fields must not silently select a broader zone or tax. */
	private static function incomplete_destination_reason( string $country, string $state, string $postcode, string $city, bool $requires_shipping ): ?string {
		global $wpdb;
		if ( $requires_shipping ) {
			if ( '' === $postcode ) { return 'postcode_required_for_shipping'; }
			if ( '' === $state ) {
				$table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
				$has_states = $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE location_type = 'state' AND location_code LIKE %s LIMIT 1",
					$wpdb->esc_like( $country . ':' ) . '%'
				) );
				if ( $has_states ) { return 'state_required_for_shipping'; }
			}
		}
		if ( ! wc_tax_enabled() || 'shipping' !== get_option( 'woocommerce_tax_based_on' ) ) { return null; }
		$rates = $wpdb->prefix . 'woocommerce_tax_rates';
		if ( '' === $state && $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$rates} WHERE tax_rate_state <> '' AND tax_rate_country IN ('', %s) LIMIT 1", $country
		) ) ) { return 'state_required_for_tax'; }
		$locations = $wpdb->prefix . 'woocommerce_tax_rate_locations';
		foreach ( [ 'postcode' => $postcode, 'city' => $city ] as $type => $value ) {
			if ( '' !== $value ) { continue; }
			$has_specific = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$locations} l JOIN {$rates} r ON r.tax_rate_id = l.tax_rate_id WHERE l.location_type = %s AND r.tax_rate_country IN ('', %s) LIMIT 1",
				$type, $country
			) );
			if ( $has_specific ) { return $type . '_required_for_tax'; }
		}
		return null;
	}

	private static function quoted_line( array $item ): array {
		return [
			'product_id' => (int) $item['product_id'],
			'variation_id' => (int) $item['variation_id'],
			'quantity' => (int) $item['quantity'],
			'line_subtotal' => wc_format_decimal( $item['line_subtotal'], wc_get_price_decimals() ),
			'line_total' => wc_format_decimal( $item['line_total'], wc_get_price_decimals() ),
			'line_tax' => wc_format_decimal( $item['line_tax'], wc_get_price_decimals() ),
		];
	}

	/**
	 * Runs woocommerce_check_cart_items for the temporary cart and returns the error notices it raised (at most
	 * three, plain text). The shopper's own cart, if loaded, is detached from the hook while it runs, so its
	 * contents are never checked or changed here. Notices land in the temporary session only.
	 */
	private static function run_cart_checks( WC_Cart $temp_cart, $previous_cart ): array {
		$detached = [];
		if ( $previous_cart instanceof WC_Cart && $previous_cart !== $temp_cart ) {
			foreach ( [ 'check_cart_items', 'check_cart_coupons' ] as $method ) {
				$priority = has_action( 'woocommerce_check_cart_items', [ $previous_cart, $method ] );
				if ( false !== $priority ) {
					remove_action( 'woocommerce_check_cart_items', [ $previous_cart, $method ], $priority );
					$detached[] = [ $method, $priority ];
				}
			}
		}
		try {
			do_action( 'woocommerce_check_cart_items' );
		} finally {
			foreach ( $detached as [ $method, $priority ] ) {
				add_action( 'woocommerce_check_cart_items', [ $previous_cart, $method ], $priority );
			}
		}
		if ( ! function_exists( 'wc_get_notices' ) ) { return []; }
		$errors = [];
		foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
			$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
			if ( '' !== $text ) { $errors[] = mb_substr( $text, 0, 200 ); }
			if ( count( $errors ) >= 3 ) { break; }
		}
		return $errors;
	}

	private static function remove_cart_hooks( WC_Cart $cart ): void {
		foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored' ] as $hook ) {
			remove_action( $hook, [ $cart, 'calculate_totals' ], 20 );
		}
		remove_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_items' ], 1 );
		remove_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_coupons' ], 1 );
		remove_action( 'woocommerce_after_checkout_validation', [ $cart, 'check_customer_coupons' ], 1 );
	}

	/**
	 * @param array $items Each line: product_id, optional variation_id, quantity.
	 * @param array $destination country, optional state, postcode and city.
	 * Third-party hooks run in the temporary cart. WordPress SQL writes and HTTP
	 * requests are blocked for this calculation; arbitrary PHP I/O is outside
	 * this guard and must not be described as sandboxed.
	 */
	public static function calculate( array $items, array $destination ): array {
		$side_effects = [];
		$read_only_sql = static function( $sql ) use ( &$side_effects ) {
			// WordPress and WooCommerce use these database reads during a quote.
			if ( ! preg_match( '/^\s*(?:\/\*.*?\*\/\s*)*(?:SELECT|SHOW|DESCRIBE|EXPLAIN)\b/is', $sql )
				|| preg_match( '/\b(?:INTO\s+OUTFILE|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql ) ) {
				$side_effects[] = 'database_write';
				throw new RuntimeException( 'Quote database write blocked.' );
			}
			return $sql;
		};
		$no_http = static function( $result, $args, $url ) use ( &$side_effects ) {
			$side_effects[] = 'http_request';
			return new WP_Error( 'kalicart_quote_http_blocked', 'Quote outbound HTTP blocked.' );
		};
		add_filter( 'query', $read_only_sql, PHP_INT_MIN );
		add_filter( 'pre_http_request', $no_http, PHP_INT_MIN, 3 );
		try {
			$result = self::calculate_guarded( $items, $destination );
			return $side_effects ? self::unknown( 'side_effect_blocked' ) : $result;
		} catch ( Throwable $error ) {
			return self::unknown( $side_effects ? 'side_effect_blocked' : 'calculation_failed' )
				+ ( $side_effects ? [] : [ 'diagnostic' => self::diagnostic( $error ) ] );
		} finally {
			remove_filter( 'query', $read_only_sql, PHP_INT_MIN );
			remove_filter( 'pre_http_request', $no_http, PHP_INT_MIN );
		}
	}

	private static function calculate_guarded( array $items, array $destination ): array {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Cart' ) ) {
			return self::unknown( 'woocommerce_unavailable' );
		}
		if ( count( $items ) < 1 || count( $items ) > self::MAX_ITEMS ) {
			return self::unknown( 'invalid_item_count' );
		}
		$country = $destination['country'] ?? null;
		$state = $destination['state'] ?? '';
		$postcode = $destination['postcode'] ?? '';
		$city = $destination['city'] ?? '';
		if ( ! is_string( $country ) || ! preg_match( '/^[A-Z]{2}$/', $country )
			|| ! is_string( $state ) || strlen( $state ) > 16
			|| ! is_string( $postcode ) || strlen( $postcode ) > 20
			|| ! is_string( $city ) || strlen( $city ) > 100 ) {
			return self::unknown( 'invalid_destination' );
		}
		if ( wc_tax_enabled() && 'billing' === get_option( 'woocommerce_tax_based_on' ) ) {
			return self::unknown( 'billing_address_required_for_tax' );
		}
		$contents = [];
		$stock_requested = [];
		$sold_individually_requested = [];
		$requires_shipping = false;
		foreach ( $items as $index => $line ) {
			if ( ! is_array( $line ) || ! isset( $line['product_id'], $line['quantity'] )
				|| ! is_int( $line['product_id'] ) || $line['product_id'] < 1
				|| ! is_int( $line['quantity'] ) || $line['quantity'] < 1 || $line['quantity'] > 999
				|| ( isset( $line['variation_id'] ) && ( ! is_int( $line['variation_id'] ) || $line['variation_id'] < 1 ) ) ) {
				return self::unknown( 'invalid_item' );
			}
			$product = wc_get_product( $line['variation_id'] ?? $line['product_id'] );
			if ( ! $product || ! $product->is_type( [ 'simple', 'variation' ] )
				|| 'publish' !== get_post_status( $product->get_id() )
				|| ! $product->is_purchasable() || ! $product->is_in_stock()
				|| ! $product->has_enough_stock( $line['quantity'] )
				|| ( $product->is_sold_individually() && $line['quantity'] > 1 )
				|| '' === $product->get_price() ) {
				return self::unknown( 'item_not_quotable' );
			}
			$stock_owner_id = (int) $product->get_stock_managed_by_id();
			$stock_requested[ $stock_owner_id ] = ( $stock_requested[ $stock_owner_id ] ?? 0 ) + $line['quantity'];
			if ( $product->is_sold_individually() ) {
				$sold_individually_requested[ $product->get_id() ] = ( $sold_individually_requested[ $product->get_id() ] ?? 0 ) + $line['quantity'];
				if ( $sold_individually_requested[ $product->get_id() ] > 1 ) {
					return self::unknown( 'item_not_quotable' );
				}
			}
			$stock_owner = $stock_owner_id === $product->get_id() ? $product : wc_get_product( $stock_owner_id );
			if ( ! $stock_owner || ! $stock_owner->has_enough_stock( $stock_requested[ $stock_owner_id ] ) ) {
				return self::unknown( 'basket_stock_insufficient' );
			}
			$variation_id = 0;
			$product_id = $line['product_id'];
			if ( $product->is_type( 'variation' ) ) {
				$variation_id = $product->get_id();
				if ( $product->get_parent_id() !== $product_id ) { return self::unknown( 'variation_parent_mismatch' ); }
			} elseif ( isset( $line['variation_id'] ) ) {
				return self::unknown( 'variation_parent_mismatch' );
			}
			$requires_shipping = $requires_shipping || $product->needs_shipping();
			$contents[ 'kali_quote_' . $index ] = [
				'key' => 'kali_quote_' . $index, 'product_id' => $product_id,
				'variation_id' => $variation_id, 'variation' => $variation_id ? $product->get_variation_attributes() : [],
				'quantity' => $line['quantity'], 'data' => $product,
				'data_hash' => wc_get_cart_item_data_hash( $product ),
				'line_subtotal' => 0, 'line_total' => 0, 'line_subtotal_tax' => 0,
				'line_tax' => 0, 'line_tax_data' => [ 'subtotal' => [], 'total' => [] ],
			];
		}
		if ( $requires_shipping && ! self::shipping_cache_ready() ) {
			return self::unknown( 'shipping_cache_not_ready' );
		}
		$incomplete = self::incomplete_destination_reason( $country, $state, $postcode, $city, $requires_shipping );
		if ( null !== $incomplete ) { return self::unknown( $incomplete ); }
		// 1.0.154: no pre-check on legacy methods or on the zone's method list. The rates below are WooCommerce's
		// own calculation; SQL writes and HTTP are blocked during it, and every returned rate declares its method.

		$previous_cart = WC()->cart;
		$previous_session = WC()->session;
		$previous_customer = WC()->customer;
		$shipping = WC()->shipping();
		$previous_packages = $shipping->packages;
		$disable_session_hooks = static function() { return false; };
		$temp_cart = null;
		try {
			WC()->session = self::temporary_session();
			WC()->customer = new WC_Customer( 0, true );
			WC()->customer->set_shipping_country( $country );
			WC()->customer->set_shipping_state( $state );
			WC()->customer->set_shipping_postcode( $postcode );
			WC()->customer->set_shipping_city( $city );
			WC()->customer->set_billing_country( $country );
			WC()->customer->set_billing_state( $state );
			WC()->customer->set_billing_postcode( $postcode );
			WC()->customer->set_billing_city( $city );
			add_filter( 'woocommerce_cart_session_initialize', $disable_session_hooks );
			$temp_cart = new WC_Cart();
			remove_filter( 'woocommerce_cart_session_initialize', $disable_session_hooks );
			WC()->cart = $temp_cart;
			$temp_cart->set_cart_contents( $contents );
			if ( $requires_shipping && 1 !== count( $temp_cart->get_shipping_packages() ) ) {
				return self::unknown( 'shipping_packages_not_single' );
			}
			$temp_cart->calculate_totals();
			// A delivered total must not be the pickup price: when WooCommerce preselects pickup and a delivery
			// rate exists, recalculate with the cheapest delivery rate (the buyer can still pick up at checkout).
			if ( $requires_shipping ) {
				$first = $shipping->get_packages();
				$delivery = [];
				foreach ( ( $first[0]['rates'] ?? [] ) as $rate ) {
					if ( ! in_array( $rate->get_method_id(), self::PICKUP_METHODS, true ) ) { $delivery[] = $rate; }
				}
				$chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
				$chosen_rate = isset( $chosen[0] ) ? ( $first[0]['rates'][ $chosen[0] ] ?? null ) : null;
				if ( $delivery && ( ! $chosen_rate || in_array( $chosen_rate->get_method_id(), self::PICKUP_METHODS, true ) ) ) {
					usort( $delivery, static fn( $a, $b ) => (float) $a->get_cost() <=> (float) $b->get_cost() );
					WC()->session->set( 'chosen_shipping_methods', [ $delivery[0]->get_id() ] );
					$temp_cart->calculate_totals();
				}
			}
			// 1.0.156: WooCommerce's own cart checks (minimum order, plugin limits) run against the temporary cart and
			// session. An error means the order cannot be placed, so no delivered total is quoted for it.
			$cart_errors = self::run_cart_checks( $temp_cart, $previous_cart );
			if ( $cart_errors ) {
				return self::unknown( 'cart_not_orderable' ) + [ 'cart_errors' => $cart_errors ];
			}
			$calculated_contents = $temp_cart->get_cart();
			if ( $temp_cart->get_applied_coupons() ) {
				return self::unknown( 'cart_lines_changed' );
			}
			// Lines the shop adds itself (mandatory charges, gifts) are part of what the buyer pays: they stay in the
			// total and are declared in added_lines. The requested lines must be unchanged.
			$added_keys = array_values( array_diff( array_keys( $calculated_contents ), array_keys( $contents ) ) );
			if ( count( $added_keys ) > self::MAX_ITEMS ) {
				return self::unknown( 'cart_lines_changed' );
			}
			foreach ( $contents as $key => $expected ) {
				$actual = $calculated_contents[ $key ] ?? null;
				if ( ! is_array( $actual )
					|| (int) ( $actual['product_id'] ?? 0 ) !== $expected['product_id']
					|| (int) ( $actual['variation_id'] ?? 0 ) !== $expected['variation_id']
					|| (int) ( $actual['quantity'] ?? 0 ) !== $expected['quantity'] ) {
					return self::unknown( 'cart_lines_changed' );
				}
			}
			$packages = $shipping->get_packages();
			if ( $requires_shipping && ( 1 !== count( $packages ) || empty( $packages[0]['rates'] ) ) ) {
				return self::unknown( 'shipping_rate_unavailable' );
			}
			$rates = [];
			$third_party_rates = false;
			$selected_rate_ids = [];
			foreach ( $packages as $package ) {
				foreach ( $package['rates'] ?? [] as $rate ) {
					$third_party = ! in_array( $rate->get_method_id(), self::CORE_SHIPPING_METHODS, true );
					$third_party_rates = $third_party_rates || $third_party;
					$rates[] = [ 'id' => $rate->get_id(), 'method_id' => $rate->get_method_id(), 'label' => $rate->get_label(),
						'cost' => wc_format_decimal( $rate->get_cost(), wc_get_price_decimals() ),
						'pickup' => in_array( $rate->get_method_id(), self::PICKUP_METHODS, true ), 'third_party' => $third_party ];
				}
			}
			foreach ( $temp_cart->get_shipping_methods() as $selected_rate ) {
				if ( $selected_rate instanceof WC_Shipping_Rate ) { $selected_rate_ids[] = $selected_rate->get_id(); }
			}
			$quoted_lines = [];
			foreach ( array_keys( $contents ) as $key ) {
				$quoted_lines[] = self::quoted_line( $calculated_contents[ $key ] );
			}
			$added_lines = [];
			foreach ( $added_keys as $key ) {
				$item = $calculated_contents[ $key ];
				$name = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data']->get_name() : '';
				$added_lines[] = self::quoted_line( $item ) + [ 'name' => mb_substr( wp_strip_all_tags( (string) $name ), 0, 120 ) ];
			}
			$fees = [];
			foreach ( $temp_cart->get_fees() as $fee ) {
				$fees[] = [
					'name' => (string) $fee->name,
					'total' => wc_format_decimal( $fee->total, wc_get_price_decimals() ),
					'tax' => wc_format_decimal( $fee->tax ?? 0, wc_get_price_decimals() ),
				];
			}
			return [
				'status' => 'quoted', 'currency' => get_woocommerce_currency(),
				'destination' => [ 'country' => $country, 'state' => $state, 'postcode' => $postcode, 'city' => $city ],
				'lines' => $quoted_lines, 'added_lines' => $added_lines, 'fees' => $fees,
				'subtotal' => wc_format_decimal( $temp_cart->get_subtotal(), wc_get_price_decimals() ),
				'shipping' => wc_format_decimal( $temp_cart->get_shipping_total(), wc_get_price_decimals() ),
				'tax' => wc_format_decimal( $temp_cart->get_total_tax(), wc_get_price_decimals() ),
				'total' => wc_format_decimal( $temp_cart->get_total( 'edit' ), wc_get_price_decimals() ),
				'rates' => $rates, 'selected_rate_ids' => $selected_rate_ids, 'third_party_rates' => $third_party_rates,
				'rate_source' => 'woocommerce_cart_calculation',
				'calculated_at' => gmdate( 'c' ), 'checkout_may_change' => true,
			];
		} catch ( Throwable $error ) {
			return self::unknown( 'calculation_failed' ) + [ 'diagnostic' => self::diagnostic( $error ) ];
		} finally {
			remove_filter( 'woocommerce_cart_session_initialize', $disable_session_hooks );
			if ( $temp_cart ) { self::remove_cart_hooks( $temp_cart ); }
			WC()->cart = $previous_cart;
			WC()->session = $previous_session;
			WC()->customer = $previous_customer;
			$shipping->packages = $previous_packages;
		}
	}
}
