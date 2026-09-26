<?php
/**
 * KaliCart Bridge 1.0.137 — the plugin declares only what it implements.
 *
 * The UCP profile (declared UCP 2026-04-08, a REST service on /wp-json/kalicart/v1
 * and catalog.search/lookup) promised a binding the plugin never implemented: a
 * UCP client POSTing catalog/search got 404 (measured 2026-09-25). UCP Catalog is
 * served by KaliCart Global. This test keeps the promise from coming back.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-137-no-ucp-profile.php
 */

$fails = 0;
$check = function ( string $name, bool $ok ) use ( &$fails ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		$fails++;
	}
};

$routes = rest_get_server()->get_routes();
$check( 'no REST route /kalicart/v1/ucp', ! isset( $routes['/' . KALICART_BRIDGE_API_NS . '/ucp'] ) );
$check( 'no ucp_profile_json() left', ! method_exists( 'KaliCart_Bridge_Signals', 'ucp_profile_json' ) );

$req  = new WP_REST_Request( 'GET', '/' . KALICART_BRIDGE_API_NS . '/discovery' );
$res  = rest_do_request( $req );
$json = wp_json_encode( $res->get_data() );
$check( 'discovery answers 200', 200 === $res->get_status() );
$check( 'discovery carries no dev.ucp.shopping', false === stripos( $json, 'dev.ucp.shopping' ) );
$check( 'discovery carries no ucp profile link', false === stripos( $json, 'ucp.json' ) && false === stripos( $json, 'ucp_profile' ) );

$robots = apply_filters( 'robots_txt', '', true );
$check( 'robots.txt no longer allows /.well-known/ucp.json', false === stripos( $robots, 'ucp' ) );

KaliCart_Bridge_Signals::write_well_known_files();
$dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
$check( 'our ucp.json mirror is removed on upgrade', ! file_exists( $dir . 'ucp.json' ) || false === strpos( (string) file_get_contents( $dir . 'ucp.json' ), 'kalicart' ) );
$check( 'the other mirrors are still written', file_exists( $dir . 'kalicart-bridge.json' ) && file_exists( $dir . 'agent.json' ) );
$check( 'mirror carries no ucp link', false === stripos( (string) file_get_contents( $dir . 'kalicart-bridge.json' ), 'ucp' ) );

// A ucp.json placed by someone else is never touched.
$foreign = "{\"owner\":\"someone-else\"}\n";
file_put_contents( $dir . 'ucp.json', $foreign );
KaliCart_Bridge_Signals::write_well_known_files();
$check( 'a foreign ucp.json survives', file_exists( $dir . 'ucp.json' ) && file_get_contents( $dir . 'ucp.json' ) === $foreign );
wp_delete_file( $dir . 'ucp.json' );

echo $fails ? "\n{$fails} FAILURE(S)\n" : "\nALL 1.0.137 NO-UCP CHECKS PASSED\n";
