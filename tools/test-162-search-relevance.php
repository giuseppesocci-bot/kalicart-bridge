<?php
/**
 * 1.0.162: /catalog/search with q and no orderby returns WordPress relevance order (title matches first),
 * stable across pages; an explicit orderby is respected; searches without q are unchanged.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-162-search-relevance.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
add_filter( 'kalicart_bridge_catalog_rate_limit_per_client', static fn() => 0 );
add_filter( 'kalicart_bridge_catalog_rate_limit_global', static fn() => 0 );
$tag = 'zq' . wp_rand( 1000, 9999 );
$make = static function ( string $name, string $content, string $date ) {
	$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_description( $content ); $p->set_regular_price( '10' ); $p->set_status( 'publish' );
	$id = $p->save(); wp_update_post( [ 'ID' => $id, 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date( $date ) ] ); return $id;
};
$title  = $make( "Materasso{$tag} Alfa", 'Un materasso comodo.', '2020-01-01 10:00:00' );
$desc   = $make( "Guanciale{$tag} Beta", "Perfetto con ogni materasso{$tag}.", '2026-01-01 10:00:00' );
$twin_a = $make( "Topper{$tag} Uno", 'Topper.', '2021-05-05 10:00:00' );
$twin_b = $make( "Topper{$tag} Due", 'Topper.', '2021-05-05 10:00:00' );
$search = static function ( array $q ) {
	$r = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/catalog/search' ); $r->set_query_params( $q + [ 'fields' => 'summary' ] );
	$res = rest_do_request( $r ); $d = $res->get_data(); $d = $d['data'] ?? $d;
	return [ $res->get_status(), array_map( static fn( $p ) => (int) $p['id'], $d['products'] ?? [] ) ];
};
[ $st, $ids ] = $search( [ 'q' => "materasso{$tag}" ] );
$ok( 200 === $st && [ $title, $desc ] === array_slice( $ids, 0, 2 ), 'q without orderby: the title match comes before the newer description match (' . implode( ',', $ids ) . ')' );
[ $st, $ids ] = $search( [ 'q' => "materasso{$tag}", 'orderby' => 'date' ] );
$ok( 200 === $st && [ $desc, $title ] === array_slice( $ids, 0, 2 ), 'explicit orderby=date is respected (newest first)' );
[ $st, $ids ] = $search( [ 'q' => "materasso{$tag}", 'orderby' => 'relevance' ] );
$ok( 200 === $st && $title === ( $ids[0] ?? 0 ), 'explicit orderby=relevance is accepted' );
[ , $p1 ] = $search( [ 'q' => "topper{$tag}", 'per_page' => 1, 'page' => 1 ] );
[ , $p2 ] = $search( [ 'q' => "topper{$tag}", 'per_page' => 1, 'page' => 2 ] );
[ , $p1b ] = $search( [ 'q' => "topper{$tag}", 'per_page' => 1, 'page' => 1 ] );
$ok( count( $p1 ) === 1 && count( $p2 ) === 1 && $p1 !== $p2 && $p1 === $p1b && in_array( $p1[0], [ $twin_a, $twin_b ], true ) && in_array( $p2[0], [ $twin_a, $twin_b ], true ), 'equal relevance and date: pages are distinct and repeatable (ID tiebreak)' );
[ $st, $ids ] = $search( [ 'q' => "materasso{$tag}", 'in_stock' => 'true' ] );
$ok( 200 === $st && $title === ( $ids[0] ?? 0 ), 'relevance also with a filter (in_stock)' );
$r = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/catalog/products' ); $r->set_query_params( [ 'orderby' => 'relevance', 'per_page' => 5, 'fields' => 'summary' ] );
$ok( 200 === rest_do_request( $r )->get_status(), '/catalog/products with orderby=relevance and no q falls back to date (200)' );
$meta = rest_do_request( new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/catalog/meta' ) )->get_data();
$ok( false !== strpos( wp_json_encode( $meta ), 'relevance' ), 'meta lists relevance among the sort values' );
echo ( $fails ? "FAILED $fails/$n" : "ALL $n PASSED" ), "\n";
