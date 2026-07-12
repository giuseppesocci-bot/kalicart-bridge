<?php
/**
 * Exact-cart attribution verification for classic and Checkout Block wrappers.
 * Creates temporary pending orders only and deletes them before exit.
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$report   = [];
$check    = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$product = null;
foreach ( get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids' ] ) as $product_id ) {
	$candidate = wc_get_product( $product_id );
	if ( $candidate && $candidate->is_type( 'simple' ) && $candidate->is_purchasable() && $candidate->is_in_stock() ) {
		$product = $candidate;
		break;
	}
}
if ( ! $product ) {
	echo wp_json_encode( [ 'success' => false, 'failures' => [ 'No suitable simple product.' ] ], JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 1 );
}

$original_session = WC()->session;
if ( ! WC()->session ) {
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
}

$funnel_name   = 'kalicart_bridge_agent_funnel_v2_' . gmdate( 'Ymd' ) . '_orders_linked';
$funnel_exists = false !== get_option( $funnel_name, false );
$funnel_backup = get_option( $funnel_name, null );
$fingerprint   = new ReflectionMethod( 'KaliCart_Bridge_Checkout', 'session_items_fingerprint' );
$fingerprint->setAccessible( true );
$created_orders = [];
$created_sessions = [];

$run_case = static function( string $mode, int $order_quantity, bool $expect_link ) use (
	$product,
	$fingerprint,
	&$created_orders,
	&$created_sessions,
	$check
): array {
	$session_id = bin2hex( random_bytes( 16 ) );
	$load_token = bin2hex( random_bytes( 16 ) );
	$expires    = time() + KaliCart_Bridge_Checkout::SESSION_TTL;
	$items      = [ [
		'product_id'   => $product->get_id(),
		'variation_id' => null,
		'quantity'     => 1,
	] ];
	$hash = (string) $fingerprint->invoke( null, $items );
	$session = [
		'session_id'             => $session_id,
		'items'                  => $items,
		'status'                 => 'cart_loaded',
		'cart_fingerprint'       => $hash,
		'load_token'             => $load_token,
		'attribution_expires_at' => $expires,
	];
	set_transient( 'kalicart_session_' . $session_id, $session, KaliCart_Bridge_Checkout::SESSION_TTL );
	$created_sessions[] = $session_id;
	WC()->session->set( 'kalicart_bridge_session_id', [
		'session_id'       => $session_id,
		'cart_fingerprint' => $hash,
		'load_token'       => $load_token,
		'expires_at'       => $expires,
	] );

	$order = wc_create_order( [ 'created_via' => 'kalicart-hardening-test' ] );
	if ( is_wp_error( $order ) ) {
		$check( false, $mode . ': temporary order creation failed.' );
		return [];
	}
	$order->add_product( $product, $order_quantity );
	$order->save();
	$created_orders[] = $order->get_id();
	if ( 'classic' === $mode ) {
		KaliCart_Bridge_Checkout::attribute_order_classic( $order->get_id(), [], $order );
	} else {
		KaliCart_Bridge_Checkout::attribute_order_blocks( $order );
	}
	$reloaded = wc_get_order( $order->get_id() );
	$linked   = $reloaded && $session_id === (string) $reloaded->get_meta( '_kalicart_bridge_session_id', true );
	$claimed  = false !== get_option( 'kalicart_session_claimed_' . $session_id, false );
	$check( $expect_link === $linked, $mode . ': order linkage did not match the exact-cart expectation.' );
	$check( $expect_link === $claimed, $mode . ': atomic session claim did not match the linkage result.' );
	return [ 'session_id' => $session_id, 'order' => $reloaded, 'linked' => $linked ];
};

$classic = $run_case( 'classic', 1, true );
$blocks  = $run_case( 'blocks', 1, true );
$changed = $run_case( 'changed-cart', 2, false );

// One session can never attribute a second order, even if its marker is replayed.
if ( ! empty( $classic['session_id'] ) && $classic['order'] instanceof WC_Order ) {
	$session = get_transient( 'kalicart_session_' . $classic['session_id'] );
	if ( is_array( $session ) ) {
		WC()->session->set( 'kalicart_bridge_session_id', [
			'session_id'       => $classic['session_id'],
			'cart_fingerprint' => $session['cart_fingerprint'],
			'load_token'       => $session['load_token'],
			'expires_at'       => $session['attribution_expires_at'],
		] );
		$second = wc_create_order( [ 'created_via' => 'kalicart-hardening-test' ] );
		if ( ! is_wp_error( $second ) ) {
			$second->add_product( $product, 1 );
			$second->save();
			$created_orders[] = $second->get_id();
			KaliCart_Bridge_Checkout::attribute_order_blocks( $second );
			$second = wc_get_order( $second->get_id() );
			$check( '' === (string) $second->get_meta( '_kalicart_bridge_session_id', true ), 'Claimed session attributed a second order.' );
		}
	}
}

WC()->session->__unset( 'kalicart_bridge_session_id' );
foreach ( $created_sessions as $session_id ) {
	delete_transient( 'kalicart_session_' . $session_id );
	delete_option( 'kalicart_session_claimed_' . $session_id );
}
foreach ( $created_orders as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->delete( true );
	}
}
if ( $funnel_exists ) {
	update_option( $funnel_name, $funnel_backup, false );
} else {
	delete_option( $funnel_name );
}
WC()->session = $original_session;

$report['classic_linked'] = (bool) ( $classic['linked'] ?? false );
$report['blocks_linked']  = (bool) ( $blocks['linked'] ?? false );
$report['changed_linked'] = (bool) ( $changed['linked'] ?? false );
echo wp_json_encode( [
	'success'  => empty( $failures ),
	'failures' => $failures,
	'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
	exit( 1 );
}
