<?php
/**
 * KaliCart Bridge 1.0.138 — installation identity, end to end against a
 * KaliCart Global identity endpoint (CONTRATTO REVISIONE 2).
 *
 * Run with a Global test endpoint (identity routes only, observe mode):
 *   KALICART_IDENTITY_TEST_TRANSPORT=http://127.0.0.1:3099 \
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-138-identity.php
 */
$fails = 0;
$check = function ( string $name, bool $ok ) use ( &$fails ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) { $fails++; }
};
$transport = getenv( 'KALICART_IDENTITY_TEST_TRANSPORT' );
if ( ! $transport ) { echo "set KALICART_IDENTITY_TEST_TRANSPORT\n"; exit( 1 ); }
define( 'KALICART_BRIDGE_IDENTITY_TRANSPORT', $transport );
$I = 'KaliCart_Bridge_Identity';

$check( 'identity available here (consent, production, root install, sodium)', '' === $I::unavailable_reason() );
$before = get_option( $I::OPTION );
delete_option( $I::OPTION ); // start clean for the test
$I::tick();
$s = $I::summary();
echo 'summary: ' . wp_json_encode( $s ) . "\n";
$st = get_option( $I::OPTION );
echo 'last_reason: ' . ( $st['last_reason'] ?? '' ) . ' last_error: ' . ( $st['last_error'] ?? '' ) . "\n";
$check( 'tick registers and proves the domain -> verified', 'verified' === $s['status'] );
$check( 'heartbeat sent right after the proof', $s['heartbeat_at'] > 0 );
$check( 'private key stored sealed, never in clear', ! empty( $st['sealed'] ) && ! isset( $st['secret_key'] ) );
$block = $I::discovery_block();
$check( 'discovery block carries host, fingerprint and nonce', $block && $block['key_fingerprint'] === $s['fingerprint'] && ! empty( $block['nonce'] ) && 'www.project2209.com' === $block['host'] );
$wk = @file_get_contents( rtrim( ABSPATH, '/' ) . '/.well-known/kalicart-bridge.json' );
$check( 'static /.well-known/kalicart-bridge.json publishes the same block', $wk && false !== strpos( $wk, $s['fingerprint'] ) && false !== strpos( $wk, $block['nonce'] ) );
$resp = rest_do_request( new WP_REST_Request( 'GET', '/kalicart/v1/discovery' ) );
$disc = $resp->get_data();
$check( 'dynamic discovery publishes the block', isset( $disc['kalicart_identity']['key_fingerprint'] ) && $disc['kalicart_identity']['key_fingerprint'] === $s['fingerprint'] );

// second tick within 20 h: no new proof, no heartbeat spam
$hb = $s['heartbeat_at'];
$I::tick();
$check( 'second tick does not re-prove nor re-send the heartbeat', $I::summary()['heartbeat_at'] === $hb && 'verified' === $I::summary()['status'] );

// AUTH_KEY change = key loss -> new key, same installation, fresh proof (recovery)
$old_fp = $s['fingerprint'];
$st = get_option( $I::OPTION );
$st['sealed'] = base64_encode( random_bytes( 80 ) ); // undecryptable, as after an AUTH_KEY change
update_option( $I::OPTION, $st, false );
$I::tick();
$s2 = $I::summary();
$check( 'undecryptable key -> recovery with a new key, verified again', 'verified' === $s2['status'] && $s2['fingerprint'] !== $old_fp );
$check( 'recovery keeps the installation id', ( get_option( $I::OPTION )['installation_id'] ?? '' ) === ( $st['installation_id'] ?? '-' ) );

// fatal capture is normalized (no message, no path)
update_option( $I::FATAL_OPT, [ 'code' => 'php_fatal', 'component' => 'kalicart-bridge', 'ts' => time(), 'hash' => 'abcdef0123456789' ], false );
$st = get_option( $I::OPTION ); $st['last_heartbeat_at'] = 0; update_option( $I::OPTION, $st, false );
$I::tick();
$check( 'heartbeat with normalized fatal accepted and cleared', false === get_option( $I::FATAL_OPT, false ) );

// leaving (deactivation path) then heartbeat re-activates
$I::leaving( 'deactivated' );
echo "leaving sent\n";
$st = get_option( $I::OPTION ); $st['last_heartbeat_at'] = 0; update_option( $I::OPTION, $st, false );
$I::tick();
$check( 'after leaving, a new heartbeat is accepted (reactivation)', $I::summary()['heartbeat_at'] > 0 && '' === ( get_option( $I::OPTION )['last_error'] ?? 'x' ) );

echo $fails ? "\n$fails FAIL\n" : "\nALL PASS\n";
