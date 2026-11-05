<?php
/**
 * 1.0.161: every 429 carries the wait in the body (retry_after, seconds) as well as in the Retry-After header.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-161-retry-after.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 2, 250 );
$same = static fn( $res, $body_wait ) => $body_wait >= 1 && $body_wait === ( function () use ( $res ) { $h = array_change_key_case( $res->get_headers(), CASE_LOWER ); return (int) ( $h['retry-after'] ?? 0 ); } )();

// Catalog limiter.
add_filter( 'kalicart_bridge_catalog_rate_limit_per_client', static fn() => 2 );
$res = null;
for ( $i = 0; $i < 4; $i++ ) { $res = rest_do_request( new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/catalog/meta' ) ); if ( 429 === $res->get_status() ) { break; } }
$d = $res->get_data();
$ok( 429 === $res->get_status() && 'KALICART_CATALOG_RATE_LIMITED' === ( $d['error_code'] ?? '' ), 'catalog: 429 reached with a lowered limit' );
$ok( $same( $res, (int) ( $d['retry_after'] ?? 0 ) ), 'catalog: body retry_after equals the Retry-After header (' . ( $d['retry_after'] ?? '-' ) . ')' );

// Quote limiter: fill the minute counter.
update_option( 'kalicart_bridge_quote_enabled', true );
$dir = trailingslashit( get_temp_dir() ) . 'kalicart-quote-' . sha1( home_url( '/' ) . ':' . get_current_blog_id() );
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0700 ); }
file_put_contents( $dir . '/rate.json', wp_json_encode( [ 'start' => time(), 'count' => 999 ] ) );
$req = new WP_REST_Request( 'POST', '/' . KALICART_BRIDGE_API_NS . '/checkout/quote' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( [ 'items' => [ [ 'product_id' => 1, 'quantity' => 1 ] ], 'destination' => [ 'country' => 'FR', 'postcode' => '75001' ] ] ) );
$res = rest_do_request( $req ); $d = $res->get_data();
$ok( 429 === $res->get_status() && 'quote_rate_limited' === ( $d['reason'] ?? '' ), 'quote: 429 quote_rate_limited' );
$ok( $same( $res, (int) ( $d['retry_after'] ?? 0 ) ), 'quote: body retry_after equals the Retry-After header (' . ( $d['retry_after'] ?? '-' ) . ')' );
file_put_contents( $dir . '/rate.json', wp_json_encode( [ 'start' => 0, 'count' => 0 ] ) );

// Checkout access limiter.
add_filter( 'kalicart_bridge_checkout_access_rate_limit_per_client', static fn() => 1 );
$res = null;
for ( $i = 0; $i < 3; $i++ ) { $res = rest_do_request( new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/checkout/session/' . str_repeat( 'a', 32 ) ) ); if ( 429 === $res->get_status() ) { break; } }
$d = $res->get_data();
$ok( 429 === $res->get_status(), 'checkout session: 429 reached with a lowered limit' );
$ok( $same( $res, (int) ( $d['retry_after'] ?? 0 ) ), 'checkout session: body retry_after equals the Retry-After header (' . ( $d['retry_after'] ?? '-' ) . ')' );

// MCP limiter.
add_filter( 'kalicart_bridge_mcp_rate_limit_per_client', static fn() => 1 );
$res = null;
for ( $i = 0; $i < 4; $i++ ) {
	$req = new WP_REST_Request( 'POST', '/' . KALICART_BRIDGE_API_NS . '/mcp' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $i + 1, 'method' => 'tools/list' ] ) );
	$res = rest_do_request( $req ); if ( 429 === $res->get_status() ) { break; }
}
$d = $res->get_data();
$ok( 429 === $res->get_status() && -32002 === ( $d['error']['code'] ?? 0 ), 'mcp: 429 with JSON-RPC error -32002' );
$ok( $same( $res, (int) ( $d['error']['data']['retry_after'] ?? 0 ) ), 'mcp: error.data.retry_after equals the Retry-After header (' . ( $d['error']['data']['retry_after'] ?? '-' ) . ')' );
echo ( $fails ? "FAILED $fails/$n" : "ALL $n PASSED" ), "\n";
