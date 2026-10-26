<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
if ( ! class_exists( 'KaliCart_Bridge_Quote' ) ) {
	require_once '/root/worktrees/bridge-151-quote/includes/class-checkout-quote.php';
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
		$checks['unknown_method_rejected'] = 'unknown' === ( $unknown_method['status'] ?? null )
			&& 'shipping_method_not_verifiable' === ( $unknown_method['reason'] ?? null );
	} finally {
		$wpdb->delete( $table, [ 'instance_id' => $unknown_method_row ] );
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
	$rate_file = trailingslashit( get_temp_dir() ) . 'kalicart-quote-' . sha1( home_url( '/' ) . ':' . get_current_blog_id() ) . '.json';
	file_put_contents( $rate_file, wp_json_encode( [ 'start' => time(), 'count' => 30 ] ) );
	try {
		$limited = rest_do_request( $http );
		$checks['rate_limited'] = 429 === $limited->get_status()
			&& 'quote_rate_limited' === ( $limited->get_data()['reason'] ?? null );
	} finally {
		unlink( $rate_file );
		update_option( 'kalicart_bridge_quote_enabled', false );
	}
	echo wp_json_encode( [ 'result' => $result, 'checks' => $checks, 'http_status' => $http_result->get_status(), 'http_body' => $http_body, 'limited_status' => $limited->get_status(), 'limited_body' => $limited->get_data(), 'writes' => $writes ] ) . "\n";
	if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Quote fixture failed.' ); }
} finally {
	wp_delete_post( $product_id, true );
	wp_delete_post( $variation_id, true );
	wp_delete_post( $parent_id, true );
	$zone->delete();
	delete_option( $settings_option );
}
