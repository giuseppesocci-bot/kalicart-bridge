<?php
/**
 * Deterministic verification for the shared public-endpoint rate guard.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-rate-guard.php
 */

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$failures = array();
$report   = array();
$check    = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$delete_raw = static function( string $name ) use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	wp_cache_delete( $name, 'options' );
};
$read_raw = static function( string $name ) use ( $wpdb ) {
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	return null === $raw ? null : maybe_unserialize( $raw );
};

$state_name = 'kalicart_rate_guard_test_guard';
$lock_name  = 'kalicart_rate_guard_lock_test_guard';
$old_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$old_xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$delete_raw( $state_name );
$delete_raw( $lock_name );

// Proxy identity: untrusted forwarding is ignored, trusted chains are walked
// from the right edge, malformed CIDR/hops fail closed, and IPv6 CIDR is valid.
$trusted = static fn(): array => array( '10.0.0.0/8', '2001:db8:ffff::/48' );
add_filter( 'kalicart_bridge_trusted_proxies', $trusted );
$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10';
$check( '203.0.113.9' === KaliCart_Bridge_Rate_Guard::client_ip(), 'Spoofed XFF from an untrusted peer was accepted.' );
$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.44, 10.0.0.2';
$check( '192.0.2.44' === KaliCart_Bridge_Rate_Guard::client_ip(), 'Nearest untrusted proxy-chain hop was not selected.' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.44, malformed, 10.0.0.2';
$check( '10.0.0.1' === KaliCart_Bridge_Rate_Guard::client_ip(), 'Malformed proxy chain did not fail closed.' );
$check( ! KaliCart_Bridge_Rate_Guard::ip_in_cidr( '203.0.113.9', '0.0.0.0/abc' ), 'Malformed CIDR was accepted.' );
$check( KaliCart_Bridge_Rate_Guard::ip_in_cidr( '2001:db8:ffff::1', '2001:db8:ffff::/48' ), 'Valid IPv6 CIDR was rejected.' );
remove_filter( 'kalicart_bridge_trusted_proxies', $trusted );

$limits = array(
	'client_limit'  => 1,
	'client_window' => 60,
	'global_limit'  => 3,
	'global_window' => 60,
	'max_clients'   => 4,
);

// Client rejection must not spend the shared global allowance.
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
$first                  = KaliCart_Bridge_Rate_Guard::check( 'test_guard', 1, $limits );
$before                 = $read_raw( $state_name );
$second                 = KaliCart_Bridge_Rate_Guard::check( 'test_guard', 1, $limits );
$after                  = $read_raw( $state_name );
$check( $first['allowed'] && ! $second['allowed'] && 'client' === $second['reason'], 'Per-client limit did not reject the second request.' );
$check( 1 === (int) $before['global']['count'] && 1 === (int) $after['global']['count'], 'Rejected client consumed global allowance.' );

// A poisoned runtime cache must not hide the database count from a fresh check.
wp_cache_set(
	$state_name,
	array( 'version' => 1, 'global' => array( 'count' => 0, 'expires' => 0 ), 'clients' => array() ),
	'options'
);
$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
$third                  = KaliCart_Bridge_Rate_Guard::check( 'test_guard', 1, $limits );
$fresh                  = $read_raw( $state_name );
$check( $third['allowed'] && 2 === (int) $fresh['global']['count'], 'Runtime-stale option cache caused a lost increment.' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
$global_last            = KaliCart_Bridge_Rate_Guard::check( 'test_guard', 1, $limits );
$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
$global_reject          = KaliCart_Bridge_Rate_Guard::check( 'test_guard', 1, $limits );
$check( $global_last['allowed'] && ! $global_reject['allowed'] && 'global' === $global_reject['reason'], 'Global limit did not reject after its third unit.' );

// Deterministic interleaving: two writers observe the same row; CAS accepts the
// first and rejects the stale second instead of silently overwriting it.
$ref        = new ReflectionClass( 'KaliCart_Bridge_Rate_Guard' );
$read_state = $ref->getMethod( 'read_state' );
$write      = $ref->getMethod( 'write_state' );
$compare    = $ref->getMethod( 'compare_delete' );
foreach ( array( $read_state, $write, $compare ) as $method ) {
	$method->setAccessible( true );
}
$observed_a                  = $read_state->invoke( null, $state_name );
$observed_b                  = $read_state->invoke( null, $state_name );
$state_a                     = $observed_a['state'];
$state_b                     = $observed_b['state'];
$state_a['global']['count'] += 1;
$state_b['global']['count'] += 1;
$write_a = $write->invoke( null, $state_name, $state_a, true, $observed_a['raw'] );
$write_b = $write->invoke( null, $state_name, $state_b, true, $observed_b['raw'] );
$check( true === $write_a && false === $write_b, 'State CAS did not reject a stale interleaved writer.' );

// Owner-safe lock CAS cannot remove a successor's lock. A stale lock, however,
// is taken over by check() and released afterwards.
$old_lock = maybe_serialize( array( 'owner' => 'old', 'expires' => microtime( true ) - 10 ) );
$new_lock = maybe_serialize( array( 'owner' => 'new', 'expires' => microtime( true ) + 10 ) );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock_name, $new_lock ) );
$deleted_successor = $compare->invoke( null, $lock_name, $old_lock );
$check( false === $deleted_successor && null !== $read_raw( $lock_name ), 'Stale owner deleted a successor mutex.' );
$delete_raw( $lock_name );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock_name, $old_lock ) );
$delete_raw( $state_name );
$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
$takeover               = KaliCart_Bridge_Rate_Guard::check( 'test_guard', 1, $limits );
$check( $takeover['allowed'] && null === $read_raw( $lock_name ), 'Expired mutex was not safely taken over and released.' );

// The per-scope client map has a hard live-entry ceiling.
$capacity_state = 'kalicart_rate_guard_capacity_guard';
$capacity_lock  = 'kalicart_rate_guard_lock_capacity_guard';
$delete_raw( $capacity_state );
$delete_raw( $capacity_lock );
$capacity_limits = array(
	'client_limit'  => 10,
	'client_window' => 60,
	'global_limit'  => 0,
	'global_window' => 60,
	'max_clients'   => 2,
);
foreach ( array( '192.0.2.1', '192.0.2.2' ) as $ip ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$check( KaliCart_Bridge_Rate_Guard::check( 'capacity_guard', 1, $capacity_limits )['allowed'], 'Client map filled before its declared capacity.' );
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.3';
$capacity_reject        = KaliCart_Bridge_Rate_Guard::check( 'capacity_guard', 1, $capacity_limits );
$check( ! $capacity_reject['allowed'] && 'client_capacity' === $capacity_reject['reason'], 'Client map hard capacity was not enforced.' );

$report['cache_stale_count']      = (int) $fresh['global']['count'];
$report['interleaving_cas']       = array( $write_a, $write_b );
$report['client_reject_preserved'] = (int) $after['global']['count'];

$delete_raw( $state_name );
$delete_raw( $lock_name );
$delete_raw( $capacity_state );
$delete_raw( $capacity_lock );
if ( null === $old_remote ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $old_remote;
}
if ( null === $old_xff ) {
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
} else {
	$_SERVER['HTTP_X_FORWARDED_FOR'] = $old_xff;
}

echo wp_json_encode(
	array( 'ok' => empty( $failures ), 'failures' => $failures, 'report' => $report ),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
if ( ! empty( $failures ) ) {
	exit( 1 );
}
