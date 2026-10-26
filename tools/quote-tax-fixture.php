<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
require_once '/root/worktrees/bridge-151-quote/includes/class-checkout-quote.php';

$previous = [
	'woocommerce_calc_taxes' => get_option( 'woocommerce_calc_taxes' ),
	'woocommerce_tax_based_on' => get_option( 'woocommerce_tax_based_on' ),
	'woocommerce_prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
];
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_tax_based_on', 'shipping' );
update_option( 'woocommerce_prices_include_tax', 'no' );
$product = new WC_Product_Simple();
$product->set_name( 'KaliCart tax fixture' );
$product->set_status( 'publish' );
$product->set_regular_price( '100' );
$product->set_tax_status( 'taxable' );
$product_id = $product->save();
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'KaliCart tax FR fixture' );
$zone->set_zone_order( 0 );
$zone->set_zone_locations( [ (object) [ 'code' => 'FR', 'type' => 'country' ] ] );
$zone->save();
$method_id = $zone->add_shipping_method( 'flat_rate' );
$settings_option = 'woocommerce_flat_rate_' . $method_id . '_settings';
update_option( $settings_option, [ 'title' => 'Tax fixture flat', 'tax_status' => 'none', 'cost' => '10' ] );
$rate_id = WC_Tax::_insert_tax_rate( [
	'tax_rate_country' => 'FR', 'tax_rate_state' => '', 'tax_rate' => '20.0000',
	'tax_rate_name' => 'KaliCart tax fixture', 'tax_rate_priority' => 1,
	'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0,
	'tax_rate_class' => '',
] );
try {
	wc_load_cart();
	wc_get_shipping_method_count();
	$old_customer = WC()->customer;
	WC()->customer = new WC_Customer( 0, true );
	WC()->customer->set_shipping_country( 'FR' );
	WC_Tax::get_rates( '', WC()->customer );
	WC()->customer = $old_customer;
	$writes = [];
	$watch = static function( $sql ) use ( &$writes ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql ) ) { $writes[] = substr( $sql, 0, 150 ); }
		return $sql;
	};
	add_filter( 'query', $watch );
	try {
		$quote = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 1 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
	} finally { remove_filter( 'query', $watch ); }
	$checks = [
		'tax_in_total' => 'quoted' === ( $quote['status'] ?? null )
			&& '20.00' === ( $quote['tax'] ?? null ) && '130.00' === ( $quote['total'] ?? null ),
		'no_db_writes' => [] === $writes,
	];
	global $wpdb;
	$location_table = $wpdb->prefix . 'woocommerce_tax_rate_locations';
	$wpdb->insert( $location_table, [ 'tax_rate_id' => $rate_id, 'location_code' => 'PARIS', 'location_type' => 'city' ] );
	try {
		$missing_city = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 1 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$checks['city_guard'] = 'unknown' === ( $missing_city['status'] ?? null )
			&& 'city_required_for_tax' === ( $missing_city['reason'] ?? null );
	} finally {
		$wpdb->delete( $location_table, [ 'tax_rate_id' => $rate_id, 'location_code' => 'PARIS', 'location_type' => 'city' ] );
	}
	update_option( 'woocommerce_tax_based_on', 'billing' );
	$billing = KaliCart_Bridge_Quote::calculate(
		[ [ 'product_id' => $product_id, 'quantity' => 1 ] ],
		[ 'country' => 'FR', 'postcode' => '75001' ]
	);
	$checks['billing_guard'] = 'unknown' === ( $billing['status'] ?? null )
		&& 'billing_address_required_for_tax' === ( $billing['reason'] ?? null );
	update_option( 'woocommerce_tax_based_on', 'shipping' );
	$shipping_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
	$wpdb->insert( $shipping_locations, [
		'zone_id' => $zone->get_id(), 'location_code' => 'FR:IDF', 'location_type' => 'state',
	] );
	try {
		$missing_state = KaliCart_Bridge_Quote::calculate(
			[ [ 'product_id' => $product_id, 'quantity' => 1 ] ],
			[ 'country' => 'FR', 'postcode' => '75001' ]
		);
		$checks['shipping_state_guard'] = 'unknown' === ( $missing_state['status'] ?? null )
			&& 'state_required_for_shipping' === ( $missing_state['reason'] ?? null );
	} finally {
		$wpdb->delete( $shipping_locations, [
			'zone_id' => $zone->get_id(), 'location_code' => 'FR:IDF', 'location_type' => 'state',
		] );
	}
	echo wp_json_encode( [ 'quote' => $quote, 'checks' => $checks, 'writes' => $writes ] ) . "\n";
	if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Tax fixture failed.' ); }
} finally {
	WC_Tax::_delete_tax_rate( $rate_id );
	wp_delete_post( $product_id, true );
	$zone->delete();
	delete_option( $settings_option );
	foreach ( $previous as $key => $value ) { update_option( $key, $value ); }
}
