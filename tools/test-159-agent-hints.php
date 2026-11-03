<?php
/**
 * 1.0.159: quote answers a missing variation with the variations to choose from;
 * catalog/categories and catalog/meta accept `fields`.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-159-agent-hints.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
update_option( 'kalicart_bridge_quote_enabled', true );

$v = new WC_Product_Variable(); $v->set_name( 'Hint var' ); $v->set_status( 'publish' );
$attr = new WC_Product_Attribute(); $attr->set_name( 'Size' ); $attr->set_options( [ 'S', 'M' ] ); $attr->set_visible( true ); $attr->set_variation( true );
$v->set_attributes( [ $attr ] ); $vid = $v->save();
$ids = [];
foreach ( [ 'S' => '30', 'M' => '32' ] as $size => $price ) {
	$x = new WC_Product_Variation(); $x->set_parent_id( $vid ); $x->set_attributes( [ 'size' => $size ] ); $x->set_regular_price( $price ); $ids[ $size ] = $x->save();
}
WC_Product_Variable::sync( $vid );
$s = new WC_Product_Simple(); $s->set_name( 'Hint simple' ); $s->set_regular_price( '10' ); $s->set_status( 'publish' ); $sid = $s->save();

global $wpdb; $q0 = $wpdb->num_queries;
$r = KaliCart_Bridge_Quote::calculate( [ [ 'product_id' => $sid, 'quantity' => 1 ], [ 'product_id' => $vid, 'quantity' => 1 ] ], [ 'country' => 'FR', 'postcode' => '75001' ] );
$ok( 'unknown' === ( $r['status'] ?? '' ) && 'variation_required' === ( $r['reason'] ?? '' ), 'variable product without variation_id -> variation_required' );
$ok( 1 === ( $r['line'] ?? null ) && $vid === ( $r['product_id'] ?? null ), 'the answer names the line and the product' );
$listed = array_column( $r['variations'] ?? [], 'variation_id' ); sort( $listed ); $want = array_values( $ids ); sort( $want );
$ok( $listed === $want, 'every variation listed with its id' );
$first = $r['variations'][0] ?? [];
$ok( isset( $first['attributes'], $first['price'], $first['in_stock'] ) && ! isset( $first['stock_quantity'], $first['quantity'] ), 'each variation has attributes, price and availability, never the stock quantity' );
$ok( false === ( $r['variations_truncated'] ?? true ) && '' !== (string) ( $r['next_step'] ?? '' ), 'not truncated, next step stated' );

$r2 = KaliCart_Bridge_Quote::calculate( [ [ 'product_id' => $vid, 'variation_id' => $ids['M'], 'quantity' => 1 ] ], [ 'country' => 'FR', 'postcode' => '75001' ] );
$ok( 'variation_required' !== ( $r2['reason'] ?? '' ), 'with variation_id the quote proceeds (status ' . ( $r2['status'] ?? '?' ) . ( isset( $r2['reason'] ) ? ', reason ' . $r2['reason'] : '' ) . ')' );

foreach ( [ '/catalog/categories', '/catalog/meta' ] as $route ) {
	$req = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . $route ); $req->set_query_params( [ 'fields' => 'summary' ] );
	$res = rest_do_request( $req );
	$ok( 200 === $res->get_status(), $route . '?fields=summary -> 200' );
	$req = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . $route ); $req->set_query_params( [ 'nonsense' => '1' ] );
	$ok( 400 === rest_do_request( $req )->get_status(), $route . ' still rejects unknown parameters' );
}
$doc = rest_do_request( new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/discovery' ) )->get_data();
$payload = wp_json_encode( $doc['checkout_quote']['payload'] ?? ( $doc['data']['checkout_quote']['payload'] ?? [] ) );
$ok( false !== strpos( (string) $payload, 'variation_required' ) && false !== strpos( (string) $payload, 'postcode is required' ), 'discovery documents variation_id and postcode requirements' );
echo ( $fails ? "FAILED $fails/$n" : "ALL $n PASSED" ), "\n";
