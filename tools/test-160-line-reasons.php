<?php
/**
 * 1.0.160: the quote says which line cannot be quoted and why, with max_quantity where the quantity is the
 * problem; catalog/categories and catalog/meta accept every catalog list parameter with no effect.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-160-line-reasons.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
update_option( 'kalicart_bridge_quote_enabled', true );
$dest = [ 'country' => 'FR', 'postcode' => '75001' ];
$simple = function ( array $set ) {
	$p = new WC_Product_Simple(); $p->set_name( 'Reason ' . wp_generate_password( 6, false ) ); $p->set_status( 'publish' );
	foreach ( $set as $k => $v ) { $p->{'set_' . $k}( $v ); }
	return $p->save();
};
$q = fn( array $items ) => KaliCart_Bridge_Quote::calculate( $items, $dest );
$is = function ( array $r, string $reason, int $line, ?int $max = null ) {
	return 'unknown' === ( $r['status'] ?? '' ) && $reason === ( $r['reason'] ?? '' ) && $line === ( $r['line'] ?? null )
		&& isset( $r['product_id'], $r['next_step'] ) && ( null === $max ? ! isset( $r['max_quantity'] ) : $max === ( $r['max_quantity'] ?? null ) );
};
$fine = $simple( [ 'regular_price' => '10' ] );

$r = $q( [ [ 'product_id' => $fine, 'quantity' => 1 ], [ 'product_id' => 99999999, 'quantity' => 1 ] ] );
$ok( $is( $r, 'product_not_found', 1 ), 'missing product -> product_not_found on line 1' );
$r = $q( [ [ 'product_id' => $simple( [] ), 'quantity' => 1 ] ] );
$ok( $is( $r, 'price_missing', 0 ), 'product without price -> price_missing' );
$np = $simple( [ 'regular_price' => '10' ] );
$block = static fn( $yes, $p ) => $p->get_id() === $np ? false : $yes;
add_filter( 'woocommerce_is_purchasable', $block, 10, 2 );
$r = $q( [ [ 'product_id' => $np, 'quantity' => 1 ] ] );
remove_filter( 'woocommerce_is_purchasable', $block, 10 );
$ok( $is( $r, 'not_purchasable', 0 ), 'not purchasable -> not_purchasable' );
$r = $q( [ [ 'product_id' => $simple( [ 'regular_price' => '10', 'stock_status' => 'outofstock' ] ), 'quantity' => 1 ] ] );
$ok( $is( $r, 'out_of_stock', 0 ), 'out of stock -> out_of_stock' );
$r = $q( [ [ 'product_id' => $simple( [ 'regular_price' => '10', 'sold_individually' => true ] ), 'quantity' => 2 ] ] );
$ok( $is( $r, 'sold_individually', 0, 1 ), 'sold individually with quantity 2 -> sold_individually, max_quantity 1' );
$two = $simple( [ 'regular_price' => '10', 'manage_stock' => true, 'stock_quantity' => 2 ] );
$r = $q( [ [ 'product_id' => $two, 'quantity' => 3 ] ] );
$ok( $is( $r, 'quantity_not_available', 0, 2 ), 'stock 2, quantity 3 -> quantity_not_available, max_quantity 2' );
$r = $q( [ [ 'product_id' => $two, 'quantity' => 2 ], [ 'product_id' => $two, 'quantity' => 1 ] ] );
$ok( $is( $r, 'basket_stock_insufficient', 1, 2 ), 'two lines over the shared stock -> basket_stock_insufficient, max_quantity 2' );
$r = $q( [ [ 'product_id' => $two, 'quantity' => 2 ] ] );
$ok( 'quantity_not_available' !== ( $r['reason'] ?? '' ) && 'basket_stock_insufficient' !== ( $r['reason'] ?? '' ), 'stock 2, quantity 2 -> not refused for quantity (status ' . ( $r['status'] ?? '?' ) . ')' );

// A shop purchase limit unrelated to stock: input maximum 3 and a cart rule that enforces it.
$lim = $simple( [ 'regular_price' => '10' ] );
$max3 = static fn( $max, $p ) => $p->get_id() === $lim ? 3 : $max;
$rule = static function () use ( $lim ) {
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( (int) $item['product_id'] === $lim && $item['quantity'] > 3 ) { wc_add_notice( 'You can buy at most 3 of this product.', 'error' ); }
	}
};
add_filter( 'woocommerce_quantity_input_max', $max3, 10, 2 );
add_action( 'woocommerce_check_cart_items', $rule );
$r = $q( [ [ 'product_id' => $lim, 'quantity' => 5 ] ] );
remove_action( 'woocommerce_check_cart_items', $rule ); remove_filter( 'woocommerce_quantity_input_max', $max3, 10 );
$lines = $r['quantity_limits'] ?? [];
$ok( 'cart_not_orderable' === ( $r['reason'] ?? '' ) && ! empty( $r['cart_errors'] ), 'purchase limit enforced by the shop -> cart_not_orderable with the shop message' );
$ok( 1 === count( $lines ) && 0 === $lines[0]['line'] && 3 === $lines[0]['max_quantity'], 'quantity_limits names the line and max_quantity 3' );
$ok( false === strpos( wp_json_encode( $r ), 'stock_quantity' ), 'no stock quantity field in any answer' );

foreach ( [ '/catalog/categories', '/catalog/meta' ] as $route ) {
	$req = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . $route );
	$req->set_query_params( [ 'per_page' => '50', 'page' => '1', 'fields' => 'summary', 'in_stock' => 'true', 'q' => 'x', 'orderby' => 'price' ] );
	$ok( 200 === rest_do_request( $req )->get_status(), $route . ' accepts the catalog list parameters' );
	$req = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . $route ); $req->set_query_params( [ 'nonsense' => '1' ] );
	$ok( 400 === rest_do_request( $req )->get_status(), $route . ' still rejects unknown parameters' );
}
$req = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/catalog/products' ); $req->set_query_params( [ 'nonsense' => '1' ] );
$ok( 400 === rest_do_request( $req )->get_status(), '/catalog/products still rejects unknown parameters' );
$doc = rest_do_request( new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/discovery' ) )->get_data();
$cq = wp_json_encode( $doc['checkout_quote'] ?? ( $doc['data']['checkout_quote'] ?? [] ) );
$ok( false !== strpos( (string) $cq, 'quantity_not_available' ) && false !== strpos( (string) $cq, 'max_quantity' ), 'discovery documents the line reasons and max_quantity' );
echo ( $fails ? "FAILED $fails/$n" : "ALL $n PASSED" ), "\n";
