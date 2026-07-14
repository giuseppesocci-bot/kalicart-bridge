<?php
/**
 * Read-only checkout transport/input/idempotency hardening verification.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-checkout-hardening.php
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$report   = [];
$check    = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$missing = new stdClass();
$option_names = [
	'kalicart_rate_guard_checkout',
	'kalicart_rate_guard_lock_checkout',
	'kalicart_rate_guard_checkout_long',
	'kalicart_rate_guard_lock_checkout_long',
	'kalicart_bridge_agent_funnel',
	'kalicart_bridge_agent_funnel_v2_' . gmdate( 'Ymd' ) . '_sessions_created',
	'kalicart_bridge_agent_funnel_v2_' . gmdate( 'Ymd' ) . '_carts_loaded',
	'kalicart_bridge_agent_funnel_v2_' . gmdate( 'Ymd' ) . '_orders_linked',
];
$backup = [];
foreach ( $option_names as $name ) {
	$backup[ $name ] = get_option( $name, $missing );
}
$old_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '198.51.100.40';

$high_client = static fn(): int => 1000;
$high_global = static fn(): int => 1000;
add_filter( 'kalicart_bridge_rate_limit_per_client', $high_client );
add_filter( 'kalicart_bridge_rate_limit_global', $high_global );
add_filter( 'kalicart_bridge_rate_limit_per_client_hour', $high_client );
add_filter( 'kalicart_bridge_rate_limit_global_hour', $high_global );
delete_option( 'kalicart_rate_guard_checkout' );
delete_option( 'kalicart_rate_guard_lock_checkout' );
delete_option( 'kalicart_rate_guard_checkout_long' );
delete_option( 'kalicart_rate_guard_lock_checkout_long' );

$make_request = static function( $payload, array $headers = [], bool $raw = false ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/' . KALICART_BRIDGE_API_NS . '/checkout/session' );
	$request->set_header( 'content-type', 'application/json' );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	$request->set_body( $raw ? (string) $payload : (string) wp_json_encode( $payload ) );
	return $request;
};
$dispatch = static function( $payload, array $headers = [], bool $raw = false ) use ( $make_request ): WP_REST_Response {
	$response = KaliCart_Bridge_Checkout::pre_dispatch( null, null, $make_request( $payload, $headers, $raw ) );
	return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( [ 'unexpected' => true ], 599 );
};

// Every checkout session response is private, including errors returned before
// session creation. This is the regression gate for stale proxy responses.
$cache_probe_request  = $make_request( (object) [] );
$cache_probe_response = KaliCart_Bridge_Checkout::prevent_session_response_caching(
	new WP_REST_Response( [ 'success' => false ], 400 ),
	null,
	$cache_probe_request
);
$cache_headers = $cache_probe_response->get_headers();
$check( 'private, no-store, max-age=0' === ( $cache_headers['Cache-Control'] ?? '' ), 'Checkout response is missing the private no-store cache policy.' );
$check( 'no-cache' === ( $cache_headers['Pragma'] ?? '' ) && '0' === ( $cache_headers['Expires'] ?? '' ), 'Checkout response is missing legacy cache prevention headers.' );

// The public transport is bounded before WordPress's JSON parameter parser.
$plain = $dispatch( (object) [], [ 'content-type' => 'text/plain' ] );
$check( 415 === $plain->get_status(), 'Checkout accepted a non-JSON Content-Type.' );
$large_header = $dispatch( (object) [], [ 'content-length' => (string) ( 2 * MB_IN_BYTES ) ] );
$check( 413 === $large_header->get_status(), 'Checkout accepted an oversized Content-Length.' );
$large_body = $dispatch( str_repeat( ' ', 65537 ), [], true );
$check( 413 === $large_body->get_status(), 'Checkout accepted an oversized actual body.' );
$malformed = $dispatch( '{', [], true );
$check( 400 === $malformed->get_status(), 'Malformed checkout JSON did not fail with 400.' );
$state = get_option( 'kalicart_rate_guard_checkout', [] );
$check( 4 === (int) ( $state['global']['count'] ?? -1 ), 'Rejected checkout bodies were not charged exactly once.' );

// Find one real product whose quantity can safely vary for idempotency checks.
$product_id = 0;
foreach ( get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids' ] ) as $candidate_id ) {
	$product = wc_get_product( $candidate_id );
	if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		continue;
	}
	$max = $product->get_max_purchase_quantity();
	if ( $max < 0 || $max >= 2 ) {
		$product_id = (int) $candidate_id;
		break;
	}
}

$session_ids = [];
$idem_key    = 'kalicart-hardening-' . wp_generate_uuid4();
$idem_slot   = hash( 'sha256', $idem_key );
$idem_store  = 'kalicart_checkout_idem_v2_' . get_current_blog_id() . '_' . substr( $idem_slot, 0, 2 );
$legacy_idem_store = 'kalicart_checkout_idem_' . get_current_blog_id() . '_' . md5( $idem_key );
if ( $product_id > 0 ) {
	$payload = [ 'product_id' => $product_id, 'quantity' => 1 ];
	$first   = $dispatch( $payload, [ 'idempotency-key' => $idem_key ] );
	$replay  = $dispatch( $payload, [ 'idempotency-key' => $idem_key ] );
	$changed = $dispatch( [ 'product_id' => $product_id, 'quantity' => 2 ], [ 'idempotency-key' => $idem_key ] );
	$plain_new = $dispatch( $payload );
	$first_id  = (string) ( $first->get_data()['session_id'] ?? '' );
	$replay_id = (string) ( $replay->get_data()['session_id'] ?? '' );
	$new_id    = (string) ( $plain_new->get_data()['session_id'] ?? '' );
	$session_ids = array_filter( [ $first_id, $new_id ] );
	$check( 201 === $first->get_status() && 32 === strlen( $first_id ), 'First idempotent checkout session failed.' );
	$check( 201 === $replay->get_status() && $first_id === $replay_id, 'Idempotent replay created a different session.' );
	$check( 409 === $changed->get_status(), 'Idempotency key reuse with another payload did not return 409.' );
	$check( 201 === $plain_new->get_status() && $new_id !== $first_id, 'Request without Idempotency-Key did not create a new session.' );
	$loaded = get_transient( 'kalicart_session_' . $first_id );
	if ( is_array( $loaded ) ) {
		$loaded['status']                 = 'cart_loaded';
		$loaded['cart_fingerprint']       = str_repeat( 'a', 64 );
		$loaded['load_token']             = str_repeat( 'b', 32 );
		$loaded['attribution_expires_at'] = time() + 120;
		$loaded['expires_at']             = gmdate( 'c', time() + 120 );
		set_transient( 'kalicart_session_' . $first_id, $loaded, KaliCart_Bridge_Checkout::SESSION_TTL );
		$late_replay      = $dispatch( $payload, [ 'idempotency-key' => $idem_key ] );
		$late_replay_data = $late_replay->get_data();
		$check( 201 === $late_replay->get_status() && 'pending' === ( $late_replay_data['status'] ?? '' ), 'Replay after cart load did not preserve the original public response.' );
		$check( ! isset( $late_replay_data['load_token'], $late_replay_data['cart_fingerprint'], $late_replay_data['attribution_expires_at'] ), 'Replay exposed internal attribution state.' );
		$check( ( $first->get_data()['expires_at'] ?? null ) === ( $late_replay_data['expires_at'] ?? null ), 'Replay changed the original public expiry.' );
	}
	delete_transient( 'kalicart_session_' . $first_id );
	$tombstone = $dispatch( $payload, [ 'idempotency-key' => $idem_key ] );
	$check( 409 === $tombstone->get_status() && empty( $tombstone->get_data()['session_id'] ), 'Missing original session allowed Idempotency-Key reuse.' );

	foreach ( [
		[ 'product_id' => (string) $product_id ],
		[ 'product_id' => $product_id, 'quantity' => -1 ],
		[ 'product_id' => $product_id, 'quantity' => 1.5 ],
		[ 'product_id' => $product_id, 'quantity' => PHP_INT_MAX ],
		[ 'product_id' => [ $product_id ] ],
		[ 'items' => [
			[ 'product_id' => $product_id, 'quantity' => 999 ],
			[ 'product_id' => $product_id, 'quantity' => 999 ],
		] ],
	] as $bad_payload ) {
		$bad = $dispatch( $bad_payload );
		$check( 400 === $bad->get_status(), 'Schema-invalid checkout item was coerced instead of rejected.' );
	}
	$report['product_id'] = $product_id;
} else {
	$failures[] = 'No published purchasable simple product with quantity >= 2 was available.';
}

foreach ( $session_ids as $session_id ) {
	delete_transient( 'kalicart_session_' . $session_id );
}
global $wpdb;
$bucket = get_option( $idem_store, null );
if ( is_array( $bucket ) && is_array( $bucket['entries'] ?? null ) ) {
	unset( $bucket['entries'][ $idem_slot ] );
	if ( empty( $bucket['entries'] ) ) {
		delete_option( $idem_store );
	} else {
		update_option( $idem_store, $bucket, false );
	}
}
delete_transient( $legacy_idem_store );
delete_option( $legacy_idem_store );

remove_filter( 'kalicart_bridge_rate_limit_per_client', $high_client );
remove_filter( 'kalicart_bridge_rate_limit_global', $high_global );
remove_filter( 'kalicart_bridge_rate_limit_per_client_hour', $high_client );
remove_filter( 'kalicart_bridge_rate_limit_global_hour', $high_global );
foreach ( $backup as $name => $value ) {
	if ( $value === $missing ) {
		delete_option( $name );
	} else {
		update_option( $name, $value, false );
	}
}
if ( null === $old_remote ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $old_remote;
}

echo wp_json_encode( [
	'success'  => empty( $failures ),
	'failures' => $failures,
	'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
	exit( 1 );
}
