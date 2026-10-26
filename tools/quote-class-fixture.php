<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
require_once '/root/worktrees/bridge-151-quote/includes/class-checkout-quote.php';

$product = new WC_Product_Simple();
$product->set_name( 'KaliCart quote fixture' );
$product->set_status( 'publish' );
$product->set_regular_price( '20' );
$product->set_virtual( false );
$product_id = $product->save();
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
	$unsupported = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 2 ] ], [ 'country' => 'FR', 'postcode' => '75001' ]
	);
	remove_filter( 'woocommerce_package_rates', $external );
	$checks['external_hook_unknown'] = 'unknown' === ( $unsupported['status'] ?? null )
		&& 'external_calculation_hook' === ( $unsupported['reason'] ?? null );
	echo wp_json_encode( [ 'result' => $result, 'checks' => $checks, 'writes' => $writes ] ) . "\n";
	if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Quote fixture failed.' ); }
} finally {
	wp_delete_post( $product_id, true );
	$zone->delete();
	delete_option( $settings_option );
}
