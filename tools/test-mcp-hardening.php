<?php
/**
 * MCP transport, protocol and abuse-guard verification.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-mcp-hardening.php
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$report   = [];
$check    = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$mcp_ref   = new ReflectionClass( 'KaliCart_Bridge_MCP' );
$work_cost = $mcp_ref->getMethod( 'request_work_cost' );
$work_cost->setAccessible( true );
$option_names = [
	'kalicart_rate_guard_mcp',
	'kalicart_rate_guard_lock_mcp',
	'kalicart_bridge_ai_traffic',
];
$missing = new stdClass();
$backup  = [];
foreach ( $option_names as $name ) {
	$backup[ $name ] = get_option( $name, $missing );
}
$old_server = [
	'REMOTE_ADDR'          => $_SERVER['REMOTE_ADDR'] ?? null,
	'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
	'HTTP_USER_AGENT'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
];
$clear_rate = static function(): void {
	delete_option( 'kalicart_rate_guard_mcp' );
	delete_option( 'kalicart_rate_guard_lock_mcp' );
};
$make_request = static function( $payload, array $headers = [], bool $raw = false ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/' . KALICART_BRIDGE_API_NS . '/mcp' );
	$request->set_header( 'content-type', 'application/json' );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	$request->set_body( $raw ? (string) $payload : (string) wp_json_encode( $payload ) );
	return $request;
};
$call = static function( $payload, array $headers = [], bool $raw = false ) use ( $make_request ): WP_REST_Response {
	return KaliCart_Bridge_MCP::handle( $make_request( $payload, $headers, $raw ) );
};
$ping = [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => (object) [] ];
$initialize = [
	'jsonrpc' => '2.0',
	'id'      => 2,
	'method'  => 'initialize',
	'params'  => [
		'protocolVersion' => KaliCart_Bridge_MCP::PROTOCOL_VERSION,
		'capabilities'    => (object) [],
		'clientInfo'      => [ 'name' => 'kalicart-hardening-test', 'version' => '1' ],
	],
];

$_SERVER['REMOTE_ADDR']     = '198.51.100.10';
$_SERVER['HTTP_USER_AGENT'] = 'KaliCart-MCP-Hardening-Test';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$high_client = static fn(): int => 1000;
$high_global = static fn(): int => 1000;
add_filter( 'kalicart_bridge_mcp_rate_limit_per_client', $high_client );
add_filter( 'kalicart_bridge_mcp_rate_limit_global', $high_global );
$clear_rate();

// Origin is enforced before any limiter state or JSON work.
$evil = $call( $ping, [ 'origin' => 'https://evil.invalid' ] );
$check( 403 === $evil->get_status(), 'Cross-origin MCP POST was not rejected.' );
$check( false === get_option( 'kalicart_rate_guard_mcp', false ), 'Rejected Origin consumed limiter state.' );
$same = $call( $ping, [ 'origin' => home_url() ] );
$check( 200 === $same->get_status(), 'Same-origin MCP POST was rejected.' );

// Transport framing and pre-parser bounds.
$plain = $call( $ping, [ 'content-type' => 'text/plain' ] );
$check( 415 === $plain->get_status(), 'Non-JSON Content-Type was accepted.' );
$oversized_header = $call( $ping, [ 'content-length' => (string) ( 3 * MB_IN_BYTES ) ] );
$check( 413 === $oversized_header->get_status(), 'Oversized Content-Length was not rejected.' );
$body_limit = static fn(): int => 1024;
add_filter( 'kalicart_bridge_mcp_max_body_bytes', $body_limit );
$oversized_body = $call( str_repeat( ' ', 1025 ), [], true );
$check( 413 === $oversized_body->get_status(), 'Oversized actual body was not rejected.' );
remove_filter( 'kalicart_bridge_mcp_max_body_bytes', $body_limit );

// Strict JSON-RPC 2.0 and MCP 2025-06-18 single-message semantics.
$wrong_version = $call( [ 'jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping' ] );
$check( 400 === $wrong_version->get_status(), 'Wrong JSON-RPC version was accepted.' );
$bad_id = $call( [ 'jsonrpc' => '2.0', 'id' => [ 'bad' => true ], 'method' => 'ping' ] );
$check( 400 === $bad_id->get_status() && null === ( $bad_id->get_data()['id'] ?? null ), 'Structured JSON-RPC id was accepted or echoed.' );
$bad_params = $call( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'bad' ] );
$check( 400 === $bad_params->get_status(), 'Scalar JSON-RPC params were accepted.' );
$array_params = $call( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => [] ] );
$check( 400 === $array_params->get_status(), 'Array JSON-RPC params were accepted.' );
$batch = $call( [ $ping, $ping ] );
$check( 400 === $batch->get_status(), 'MCP JSON-RPC batch was accepted.' );

$notification = $call( [ 'jsonrpc' => '2.0', 'method' => 'ping', 'params' => (object) [] ] );
$check( 202 === $notification->get_status() && null === $notification->get_data(), 'Valid notification produced a response body.' );
$bad_notification = $call( [ 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'notifications/initialized', 'params' => (object) [] ] );
$check( 200 === $bad_notification->get_status() && isset( $bad_notification->get_data()['error'] ), 'Notification method carrying id was silently discarded.' );

// Version negotiation never claims an unknown revision; an unsupported request header fails.
$init_ok = $call( $initialize );
$check( 200 === $init_ok->get_status() && KaliCart_Bridge_MCP::PROTOCOL_VERSION === ( $init_ok->get_data()['result']['protocolVersion'] ?? null ), 'Valid initialize failed.' );
$future = $initialize;
$future['params']['protocolVersion'] = '2099-99-99';
$init_future = $call( $future );
$check( KaliCart_Bridge_MCP::PROTOCOL_VERSION === ( $init_future->get_data()['result']['protocolVersion'] ?? null ), 'Server echoed an unsupported protocol revision.' );
$bad_header = $call( $ping, [ 'mcp-protocol-version' => '2099-99-99' ] );
$check( 400 === $bad_header->get_status(), 'Unsupported MCP-Protocol-Version header was accepted.' );

// Tool input must follow the advertised JSON Schema; coercion is forbidden.
$array_arguments = $call( [
	'jsonrpc' => '2.0',
	'id'      => 9,
	'method'  => 'tools/call',
	'params'  => [ 'name' => 'get_meta', 'arguments' => [] ],
] );
$check( true === ( $array_arguments->get_data()['result']['isError'] ?? false ), 'Array tool arguments were accepted as an object.' );
$product_ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ] );
if ( ! empty( $product_ids ) ) {
	$string_id = $call( [
		'jsonrpc' => '2.0',
		'id'      => 10,
		'method'  => 'tools/call',
		'params'  => [ 'name' => 'get_product', 'arguments' => [ 'id' => (string) $product_ids[0] . 'junk' ] ],
	] );
	$check( true === ( $string_id->get_data()['result']['isError'] ?? false ), 'Schema-invalid string product id was coerced.' );
}
$page_too_high = $call( [
	'jsonrpc' => '2.0',
	'id'      => 11,
	'method'  => 'tools/call',
	'params'  => [ 'name' => 'list_products', 'arguments' => [ 'page' => 1001 ] ],
] );
$check( true === ( $page_too_high->get_data()['result']['isError'] ?? false ), 'Unsafe MCP catalog page was accepted.' );
$cost_cases = [
	[ [ 'method' => 'tools/call', 'params' => [ 'name' => 'get_product', 'arguments' => [ 'id' => 1 ] ] ], 3 ],
	[ [ 'method' => 'tools/call', 'params' => [ 'name' => 'list_products', 'arguments' => [ 'per_page' => 100 ] ] ], 2 ],
	[ [ 'method' => 'tools/call', 'params' => [ 'name' => 'search_products', 'arguments' => [ 'per_page' => 100, 'gender' => 'female' ] ] ], 4 ],
];
foreach ( $cost_cases as [ $message, $expected_cost ] ) {
	$check( $expected_cost === $work_cost->invoke( null, $message ), 'MCP catalog work cost was not weighted correctly.' );
}

// The rest_pre_dispatch hook must own malformed application/json before WordPress's parser.
$clear_rate();
$malformed_request = $make_request( '{', [], true );
$malformed = rest_do_request( $malformed_request );
$malformed_data = $malformed instanceof WP_REST_Response ? $malformed->get_data() : [];
$check( $malformed instanceof WP_REST_Response && 400 === $malformed->get_status(), 'Malformed JSON did not return 400.' );
$check( -32700 === ( $malformed_data['error']['code'] ?? null ), 'WordPress parsed malformed MCP JSON before the MCP pre-dispatch guard.' );
$state_after_malformed = get_option( 'kalicart_rate_guard_mcp', [] );
$check( 1 === (int) ( $state_after_malformed['global']['count'] ?? 0 ), 'Malformed JSON was not charged to the MCP limiter.' );

// Deterministic per-client/global limits using the shared fresh-DB guard.
remove_filter( 'kalicart_bridge_mcp_rate_limit_per_client', $high_client );
remove_filter( 'kalicart_bridge_mcp_rate_limit_global', $high_global );
$low_client = static fn(): int => 2;
$low_global = static fn(): int => 3;
add_filter( 'kalicart_bridge_mcp_rate_limit_per_client', $low_client );
add_filter( 'kalicart_bridge_mcp_rate_limit_global', $low_global );
$clear_rate();
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$a1 = $call( $ping );
$a2 = $call( $ping );
$a3 = $call( $ping );
$_SERVER['REMOTE_ADDR'] = '198.51.100.21';
$b1 = $call( $ping );
$_SERVER['REMOTE_ADDR'] = '198.51.100.22';
$c1 = $call( $ping );
$limited_state = get_option( 'kalicart_rate_guard_mcp', [] );
$check( 200 === $a1->get_status() && 200 === $a2->get_status() && 429 === $a3->get_status(), 'Per-client MCP limit failed.' );
$check( 200 === $b1->get_status() && 429 === $c1->get_status(), 'Global MCP limit failed.' );
$check( 3 === (int) ( $limited_state['global']['count'] ?? 0 ), 'Rejected MCP requests changed the global counter.' );
$report['rate_limit_global'] = (int) ( $limited_state['global']['count'] ?? -1 );
remove_filter( 'kalicart_bridge_mcp_rate_limit_per_client', $low_client );
remove_filter( 'kalicart_bridge_mcp_rate_limit_global', $low_global );

// Restore every option and server value touched by the suite.
foreach ( $backup as $name => $value ) {
	if ( $value === $missing ) {
		delete_option( $name );
	} else {
		update_option( $name, $value, false );
	}
}
foreach ( $old_server as $name => $value ) {
	if ( null === $value ) {
		unset( $_SERVER[ $name ] );
	} else {
		$_SERVER[ $name ] = $value;
	}
}

echo wp_json_encode( [
	'success'  => empty( $failures ),
	'failures' => $failures,
	'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
	exit( 1 );
}
