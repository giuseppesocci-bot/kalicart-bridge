<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
if ( ! class_exists( 'KaliCart_Bridge_Quote' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-checkout-quote.php';
}

$product = new WC_Product_Simple();
$product->set_name( 'KaliCart quote fixture' );
$product->set_status( 'publish' );
$product->set_regular_price( '20' );
$product->set_virtual( false );
$product_id = $product->save();
$parent = new WC_Product_Variable();
$parent->set_name( 'KaliCart variable quote fixture' );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_status( 'publish' );
$variation->set_regular_price( '30' );
$variation_id = $variation->save();
$variation_two = new WC_Product_Variation();
$variation_two->set_parent_id( $parent_id );
$variation_two->set_status( 'publish' );
$variation_two->set_regular_price( '35' );
$variation_two_id = $variation_two->save();
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'KaliCart quote FR fixture' );
$zone->set_zone_order( 0 );
$zone->set_zone_locations( [ (object) [ 'code' => 'FR', 'type' => 'country' ] ] );
$zone->save();
$method_id = $zone->add_shipping_method( 'flat_rate' );
$settings_option = 'woocommerce_flat_rate_' . $method_id . '_settings';
update_option( $settings_option, [ 'title' => 'Fixture flat', 'tax_status' => 'none', 'cost' => '10 + [qty]*2' ] );
try {
	wc_load_cart();
	WC()->customer->set_shipping_country( 'IT' );
	WC()->cart->set_cart_contents( [ 'existing' => [
		'key' => 'existing', 'product_id' => $product_id, 'variation_id' => 0,
		'variation' => [], 'quantity' => 1, 'data' => wc_get_product( $product_id ),
		'line_total' => 20, 'line_subtotal' => 20, 'line_tax' => 0,
		'line_subtotal_tax' => 0, 'line_tax_data' => [ 'total' => [], 'subtotal' => [] ],
	] ] );
	$old_cart = WC()->cart;
	$old_session = WC()->session;
	$old_customer = WC()->customer;
	$old_packages = WC()->shipping()->packages;
	$old_cart_data = wp_json_encode( $old_cart->get_cart_for_session() );
	$old_session_cart = $old_session->get( 'cart' );
	$old_cookies = $_COOKIE;
	$old_headers = headers_list();
	// Deliberately warm WooCommerce's own shipping-method count outside the
	// measured quote; a cold cache must be rejected before calculation.
	wc_get_shipping_method_count();
	$writes = [];
	$watch = static function( $sql ) use ( &$writes ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql ) ) { $writes[] = substr( $sql, 0, 150 ); }
		return $sql;
	};
	add_filter( 'query', $watch );
	try {
		$result = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
	} finally {
		remove_filter( 'query', $watch );
	}
	$checks = [
		'quoted_54' => 'quoted' === ( $result['status'] ?? null ) && '54.00' === ( $result['total'] ?? null ),
		'cart_unchanged' => $old_cart === WC()->cart && $old_cart_data === wp_json_encode( WC()->cart->get_cart_for_session() ),
		'session_unchanged' => $old_session === WC()->session && $old_session_cart === WC()->session->get( 'cart' ),
		'customer_unchanged' => $old_customer === WC()->customer,
		'packages_unchanged' => $old_packages === WC()->shipping()->packages,
		'cookies_unchanged' => $old_cookies === $_COOKIE && $old_headers === headers_list(),
		'no_db_writes' => [] === $writes,
	];
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 2 );
	$product->save();
	$overstocked = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ], [ 'product_id' => $product_id, 'quantity' => 2 ] ],
		[ 'country' => 'FR', 'postcode' => '75001' ]
	);
	$checks['aggregate_stock_guard'] = 'basket_stock_insufficient' === ( $overstocked['reason'] ?? null );
	$product->set_manage_stock( false );
	$product->save();
	$product->set_sold_individually( true );
	$product->save();
	$sold_twice = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 1 ], [ 'product_id' => $product_id, 'quantity' => 1 ] ],
		[ 'country' => 'FR', 'postcode' => '75001' ]
	);
	$checks['sold_individually_guard'] = 'item_not_quotable' === ( $sold_twice['reason'] ?? null );
	$product->set_sold_individually( false );
	$product->save();
	$parent->set_manage_stock( true );
	$parent->set_stock_quantity( 2 );
	$parent->save();
	$shared_stock = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $parent_id, 'variation_id' => $variation_id, 'quantity' => 2 ],
		  [ 'product_id' => $parent_id, 'variation_id' => $variation_two_id, 'quantity' => 2 ] ],
		[ 'country' => 'FR', 'postcode' => '75001' ]
	);
	$checks['parent_stock_guard'] = 'basket_stock_insufficient' === ( $shared_stock['reason'] ?? null );
	$parent->set_manage_stock( false );
	$parent->save();
	delete_transient( 'wc_shipping_method_count' );
	$cold = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	$checks['cold_cache_unknown'] = 'unknown' === ( $cold['status'] ?? null )
		&& 'shipping_cache_not_ready' === ( $cold['reason'] ?? null );
	wc_get_shipping_method_count();
	$external = static function( $rates ) { return $rates; };
	add_filter( 'woocommerce_package_rates', $external );
	$with_external = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_filter( 'woocommerce_package_rates', $external );
	$checks['benign_external_hook_quoted'] = 'quoted' === ( $with_external['status'] ?? null )
		&& '54.00' === ( $with_external['total'] ?? null );
	$price_hook = static function( $cart ) {
		foreach ( $cart->get_cart() as $item ) { $item['data']->set_price( '18' ); }
	};
	add_action( 'woocommerce_before_calculate_totals', $price_hook );
	$repriced = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_action( 'woocommerce_before_calculate_totals', $price_hook );
	$checks['external_price_hook_honored'] = 'quoted' === ( $repriced['status'] ?? null )
		&& '50.00' === ( $repriced['total'] ?? null );
	$fee_hook = static function( $cart ) { $cart->add_fee( 'Handling', 2, false ); };
	add_action( 'woocommerce_cart_calculate_fees', $fee_hook );
	$with_fee = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_action( 'woocommerce_cart_calculate_fees', $fee_hook );
	$checks['external_fee_itemized'] = 'quoted' === ( $with_fee['status'] ?? null )
		&& '56.00' === ( $with_fee['total'] ?? null )
		&& '2.00' === ( $with_fee['fees'][0]['total'] ?? null );
	$mutate_hook = static function( $cart ) { $cart->cart_contents['kali_quote_0']['quantity'] = 3; };
	add_action( 'woocommerce_before_calculate_totals', $mutate_hook );
	$mutated = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_action( 'woocommerce_before_calculate_totals', $mutate_hook );
	$checks['cart_mutation_unknown'] = 'cart_lines_changed' === ( $mutated['reason'] ?? null );
	global $wpdb;
	$write_hook = static function() use ( $wpdb ) {
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = option_value WHERE option_name = 'kalicart_quote_fixture'" );
	};
	add_action( 'woocommerce_before_calculate_totals', $write_hook );
	$write_attempt = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_action( 'woocommerce_before_calculate_totals', $write_hook );
	$checks['write_hook_blocked'] = 'side_effect_blocked' === ( $write_attempt['reason'] ?? null );
	$http_hook = static function( $rates ) {
		wp_remote_get( 'https://quote-fixture.invalid/probe' );
		return $rates;
	};
	add_filter( 'woocommerce_package_rates', $http_hook );
	$http_attempt = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_filter( 'woocommerce_package_rates', $http_hook );
	$checks['http_hook_blocked'] = 'side_effect_blocked' === ( $http_attempt['reason'] ?? null );
	$writes = [];
	add_filter( 'query', $watch );
	try {
		$variant_quote = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $parent_id, 'variation_id' => $variation_id, 'quantity' => 1 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
	} finally {
		remove_filter( 'query', $watch );
	}
	$checks['selected_variation'] = 'quoted' === ( $variant_quote['status'] ?? null )
		&& '42.00' === ( $variant_quote['total'] ?? null ) && [] === $writes;
	global $wpdb;
	$table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
	$wpdb->insert( $table, [
		'zone_id' => $zone->get_id(), 'method_id' => 'kali_fixture_unknown',
		'method_order' => 10, 'is_enabled' => 1,
	] );
	$unknown_method_row = (int) $wpdb->insert_id;
	try {
		$unknown_method = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		// 1.0.154: a zone row whose plugin is not loaded is invisible to WooCommerce checkout too; the quote
		// carries only the rates WooCommerce computes, each with its method_id.
		$checks['unloaded_method_ignored_like_checkout'] = 'quoted' === ( $unknown_method['status'] ?? null )
			&& '54.00' === ( $unknown_method['total'] ?? null )
			&& [ 'flat_rate' ] === array_values( array_unique( array_column( $unknown_method['rates'] ?? [], 'method_id' ) ) )
			&& false === ( $unknown_method['third_party_rates'] ?? null );
	} finally {
		$wpdb->delete( $table, [ 'instance_id' => $unknown_method_row ] );
	}
	// 1.0.155: a cart hook that uses the full WooCommerce session API (common in pricing plugins) is supported.
	$session_api_hook = static function () {
		if ( WC()->session ) { WC()->session->has_session(); WC()->session->get_customer_unique_id(); WC()->session->set( 'kali_probe', 1 ); }
	};
	add_action( 'woocommerce_cart_calculate_fees', $session_api_hook );
	try {
		$with_session_api = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$checks['session_api_hook_quoted'] = 'quoted' === ( $with_session_api['status'] ?? null )
			&& '54.00' === ( $with_session_api['total'] ?? null ) && $old_session === WC()->session;
	} finally {
		remove_action( 'woocommerce_cart_calculate_fees', $session_api_hook );
	}
	// 1.0.156: a line the shop adds itself (mandatory charge) is kept in the total and declared in added_lines.
	$extra = new WC_Product_Simple();
	$extra->set_name( 'KaliCart mandatory charge fixture' );
	$extra->set_status( 'publish' );
	$extra->set_regular_price( '3' );
	$extra->set_virtual( true );
	$extra_id = $extra->save();
	$add_line_hook = static function ( $cart ) use ( $extra_id ) {
		if ( isset( $cart->cart_contents['kali_extra'] ) ) { return; }
		$charge = wc_get_product( $extra_id );
		$cart->cart_contents['kali_extra'] = [
			'key' => 'kali_extra', 'product_id' => $extra_id, 'variation_id' => 0, 'variation' => [],
			'quantity' => 1, 'data' => $charge, 'data_hash' => wc_get_cart_item_data_hash( $charge ),
			'line_subtotal' => 0, 'line_total' => 0, 'line_subtotal_tax' => 0, 'line_tax' => 0,
			'line_tax_data' => [ 'subtotal' => [], 'total' => [] ],
		];
	};
	add_action( 'woocommerce_before_calculate_totals', $add_line_hook );
	try {
		$with_added = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$checks['shop_added_line_quoted'] = 'quoted' === ( $with_added['status'] ?? null )
			&& '57.00' === ( $with_added['total'] ?? null )
			&& 1 === count( $with_added['lines'] ?? [] ) && $product_id === ( $with_added['lines'][0]['product_id'] ?? null )
			&& 1 === count( $with_added['added_lines'] ?? [] ) && $extra_id === ( $with_added['added_lines'][0]['product_id'] ?? null )
			&& '3.00' === ( $with_added['added_lines'][0]['line_total'] ?? null )
			&& 'KaliCart mandatory charge fixture' === ( $with_added['added_lines'][0]['name'] ?? null );
	} finally {
		remove_action( 'woocommerce_before_calculate_totals', $add_line_hook );
	}
	$plain = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	$checks['no_added_lines_by_default'] = 'quoted' === ( $plain['status'] ?? null ) && [] === ( $plain['added_lines'] ?? null );
	// 1.0.156: a WooCommerce cart error (minimum order) means the order cannot be placed: no quote, reason stated.
	$notices_before = wp_json_encode( $old_session->get( 'wc_notices' ) );
	$minimum_hook = static function () {
		if ( WC()->cart && (float) WC()->cart->get_subtotal() < 100 ) {
			wc_add_notice( 'Il totale minimo per completare l&#8217;ordine è di 100,00&nbsp;&euro;. <strong>Aggiungi</strong> prodotti.', 'error' );
		}
	};
	add_action( 'woocommerce_check_cart_items', $minimum_hook );
	try {
		$not_orderable = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$big_enough = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 5 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$checks['minimum_order_not_orderable'] = 'unknown' === ( $not_orderable['status'] ?? null )
			&& 'cart_not_orderable' === ( $not_orderable['reason'] ?? null )
			&& [ "Il totale minimo per completare l\u{2019}ordine è di 100,00 €. Aggiungi prodotti." ] === ( $not_orderable['cart_errors'] ?? null );
		$checks['minimum_order_met_quoted'] = 'quoted' === ( $big_enough['status'] ?? null );
		$checks['cart_checks_leave_real_session'] = $old_session === WC()->session && $old_cart === WC()->cart
			&& $notices_before === wp_json_encode( $old_session->get( 'wc_notices' ) )
			&& $old_cart_data === wp_json_encode( $old_cart->get_cart_for_session() )
			&& false !== has_action( 'woocommerce_check_cart_items', [ $old_cart, 'check_cart_items' ] );
	} finally {
		remove_action( 'woocommerce_check_cart_items', $minimum_hook );
	}
	// 1.0.155: a failing calculation states the error type and the plugin it came from.
	$failing_hook = static function () { throw new RuntimeException( 'fixture failure' ); };
	add_action( 'woocommerce_cart_calculate_fees', $failing_hook );
	try {
		$failed = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$checks['failure_diagnostic'] = 'calculation_failed' === ( $failed['reason'] ?? null )
			&& 'RuntimeException' === ( $failed['diagnostic']['error'] ?? null );
	} finally {
		remove_action( 'woocommerce_cart_calculate_fees', $failing_hook );
	}
	// 1.0.154: when WooCommerce preselects pickup, the delivered total uses the cheapest delivery rate.
	$pickup_instance = $zone->add_shipping_method( 'local_pickup' );
	$pickup_option = 'woocommerce_local_pickup_' . $pickup_instance . '_settings';
	update_option( $pickup_option, [ 'title' => 'Fixture pickup', 'cost' => '0', 'tax_status' => 'none' ] );
	$wpdb->update( $table, [ 'method_order' => -1 ], [ 'instance_id' => $pickup_instance ] );
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	try {
		$with_pickup = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$methods = array_column( $with_pickup['rates'] ?? [], 'method_id' );
		$checks['pickup_not_used_as_delivered_total'] = 'quoted' === ( $with_pickup['status'] ?? null )
			&& in_array( 'local_pickup', $methods, true ) && '54.00' === ( $with_pickup['total'] ?? null );
	} finally {
		$zone->delete_shipping_method( $pickup_instance );
		delete_option( $pickup_option );
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}
	update_option( 'kalicart_bridge_quote_enabled', true );
	KaliCart_Bridge_Quote::init();
	rest_get_server(); // WP-CLI may have initialized rest_api_init before eval-file.
	KaliCart_Bridge_Quote::register_routes();
	$http = new WP_REST_Request( 'POST', '/kalicart/v1/checkout/quote' );
	$http->set_header( 'Content-Type', 'application/json' );
	$http->set_body( wp_json_encode( [
		'items' => [ [ 'product_id' => $product_id, 'quantity' => 2 ] ],
		'destination' => [ 'country' => 'FR', 'postcode' => '75001' ],
	] ) );
	$writes = [];
	add_filter( 'query', $watch );
	try {
		$http_result = rest_do_request( $http );
	} finally {
		remove_filter( 'query', $watch );
	}
	$http_body = $http_result->get_data();
	$checks['http_opt_in_quote'] = 200 === $http_result->get_status()
		&& 'quoted' === ( $http_body['status'] ?? null )
		&& '54.00' === ( $http_body['total'] ?? null ) && [] === $writes;
	$rate_dir = trailingslashit( get_temp_dir() ) . 'kalicart-quote-' . sha1( home_url( '/' ) . ':' . get_current_blog_id() );
	$rate_file = trailingslashit( $rate_dir ) . 'rate.json';
	if ( ! is_dir( $rate_dir ) ) { mkdir( $rate_dir, 0700 ); }
	file_put_contents( $rate_file, wp_json_encode( [ 'start' => time(), 'count' => 30 ] ) );
	try {
		$limited = rest_do_request( $http );
		$checks['rate_limited'] = 429 === $limited->get_status()
			&& 'quote_rate_limited' === ( $limited->get_data()['reason'] ?? null );
	} finally {
		unlink( $rate_file );
		@rmdir( $rate_dir ); // Fixture cleanup; the directory is private to this isolated WordPress site.
		update_option( 'kalicart_bridge_quote_enabled', false );
	}
	echo wp_json_encode( [ 'result' => $result, 'checks' => $checks, 'http_status' => $http_result->get_status(), 'http_body' => $http_body, 'limited_status' => $limited->get_status(), 'limited_body' => $limited->get_data(), 'writes' => $writes ] ) . "\n";
	if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Quote fixture failed.' ); }
} finally {
	if ( isset( $extra_id ) ) { wp_delete_post( $extra_id, true ); }
	wp_delete_post( $product_id, true );
	wp_delete_post( $variation_id, true );
	wp_delete_post( $variation_two_id, true );
	wp_delete_post( $parent_id, true );
	$zone->delete();
	delete_option( $settings_option );
}
