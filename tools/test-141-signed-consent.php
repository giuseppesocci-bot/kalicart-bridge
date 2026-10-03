<?php
/**
 * KaliCart Bridge 1.0.141 signed provider-consent delivery.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-141-signed-consent.php
 *
 * All HTTP is intercepted. Options and synthetic ledger rows are restored.
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures   = [];
$created_ids = [];
$check = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$missing = new stdClass();
$option_names = [
	'kalicart_bridge_global_consent',
	'kalicart_bridge_federation_registered_at',
	KaliCart_Bridge_Commerce_Consent::OPTION,
	KaliCart_Bridge_Identity::OPTION,
];
$original = [];
foreach ( $option_names as $option_name ) {
	$original[ $option_name ] = get_option( $option_name, $missing );
}

$pair = sodium_crypto_sign_keypair();
$sk   = sodium_crypto_sign_secretkey( $pair );
$pk   = sodium_crypto_sign_publickey( $pair );
$x    = rtrim( strtr( base64_encode( $pk ), '+/', '-_' ), '=' );
$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
$salt  = sodium_crypto_generichash( KaliCart_Bridge_Identity::LABEL, '', 32 );
$box_key = sodium_crypto_generichash( (string) AUTH_KEY, $salt, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$installation_id = wp_generate_uuid4();
$identity_state = [
	'installation_id' => $installation_id,
	'public_key'       => $x,
	'fingerprint'      => KaliCart_Bridge_Identity::fingerprint( $x ),
	'sealed'           => base64_encode( $nonce . sodium_crypto_secretbox( $sk, $nonce, $box_key ) ),
	'home_url'         => home_url( '/' ),
	'status'           => 'verified',
	'clock_offset'     => 0,
];

$mode = 'signed_ok';
$signed_calls = [];
$unsigned_calls = [];
$header = static function( array $headers, string $name ): string {
	foreach ( $headers as $key => $value ) {
		if ( strtolower( (string) $key ) === strtolower( $name ) ) {
			return (string) $value;
		}
	}
	return '';
};
$intercept = static function( $preempt, array $args, string $url ) use (
	&$mode, &$signed_calls, &$unsigned_calls, $pk, $installation_id, $header, $check
) {
	if ( 0 !== strpos( $url, KALICART_BRIDGE_GLOBAL ) ) {
		return $preempt;
	}
	if ( false !== strpos( $url, '/v1/bridge/identity/provider-consent' ) ) {
		$raw = (string) ( $args['body'] ?? '' );
		$body = json_decode( $raw, true );
		$digest = $header( (array) ( $args['headers'] ?? [] ), 'Content-Digest' );
		$signature_input = $header( (array) ( $args['headers'] ?? [] ), 'Signature-Input' );
		$signature = $header( (array) ( $args['headers'] ?? [] ), 'Signature' );
		$params = 0 === strpos( $signature_input, 'sig1=' ) ? substr( $signature_input, 5 ) : '';
		preg_match( '/^sig1=:([A-Za-z0-9+\/=]+):$/', $signature, $signature_match );
		$sig = isset( $signature_match[1] ) ? base64_decode( $signature_match[1], true ) : false;
		$base = implode( "\n", [
			'"@method": POST',
			'"@target-uri": ' . KALICART_BRIDGE_GLOBAL . '/v1/bridge/identity/provider-consent',
			'"content-digest": ' . $digest,
			'"content-type": application/json',
			'"@signature-params": ' . $params,
		] );
		$valid = is_array( $body )
			&& hash_equals( 'sha-256=:' . base64_encode( hash( 'sha256', $raw, true ) ) . ':', $digest )
			&& is_string( $sig )
			&& sodium_crypto_sign_verify_detached( $sig, $base, $pk );
		$signed_calls[] = [ 'body' => $body, 'valid' => $valid ];
		$check( $valid, 'Signed receipt request did not verify byte-for-byte.' );
		$check( $installation_id === ( $body['installation_id'] ?? '' ), 'Signed receipt used the wrong installation ID.' );
		$check( KaliCart_Bridge_Identity::host() === ( $body['host'] ?? '' ), 'Signed receipt used the wrong host.' );
		$check( isset( $body['request_id'], $body['receipt'] ), 'Signed envelope is incomplete.' );
		$check( ! isset( $body['receipt']['wp_user_id'] ) && ! isset( $body['receipt']['consent_text'] ), 'Signed receipt exposed local-only evidence.' );

		if ( 'signed_ok' === $mode ) {
			return [
				'headers' => [],
				'body' => wp_json_encode( [ 'ok' => true, 'receipt_status' => 'verified', 'authorization_state' => 'authorized', 'delivery_state' => 'approved' ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null,
			];
		}
		if ( 'signed_missing' === $mode ) {
			return [
				'headers' => [], 'body' => '',
				'response' => [ 'code' => 404, 'message' => 'Not Found' ], 'cookies' => [], 'filename' => null,
			];
		}
		return [
			'headers' => [], 'body' => wp_json_encode( [ 'ok' => false, 'error' => 'bad_signature' ] ),
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'cookies' => [], 'filename' => null,
		];
	}
	if ( false !== strpos( $url, '/v1/bridge/provider-consent' ) ) {
		$unsigned_calls[] = json_decode( (string) ( $args['body'] ?? '' ), true );
		return [
			'headers' => [],
			'body' => wp_json_encode( [ 'ok' => true, 'receipt_status' => 'pending', 'authorization_state' => 'not_active', 'delivery_state' => 'not_configured' ] ),
			'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null,
		];
	}
	return new WP_Error( 'unexpected_global_request', 'Unexpected Global URL in signed consent test.' );
};
add_filter( 'pre_http_request', $intercept, 10, 3 );

global $wpdb;
$table = KaliCart_Bridge_Commerce_Consent::table_name();

try {
	KaliCart_Bridge_Commerce_Consent::ensure_schema();
	update_option( 'kalicart_bridge_global_consent', true, false );
	update_option( 'kalicart_bridge_federation_registered_at', gmdate( 'c' ), false );
	update_option( KaliCart_Bridge_Identity::OPTION, $identity_state, false );
	delete_option( KaliCart_Bridge_Commerce_Consent::OPTION );
	$check( '' === KaliCart_Bridge_Identity::unavailable_reason(), 'Synthetic verified identity is unavailable.' );

	$grant = KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'granted', get_current_user_id() );
	$created_ids[] = $grant['consent_id'];
	$check( 'accepted' === $grant['receipt_status'], 'Signed 200 was not stored as accepted delivery.' );
	$check( 1 === count( $signed_calls ) && 0 === count( $unsigned_calls ), 'Successful signed delivery also called the unsigned endpoint.' );

	$mode = 'signed_missing';
	$revoke = KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'revoked', get_current_user_id() );
	$created_ids[] = $revoke['consent_id'];
	$check( 'accepted' === $revoke['receipt_status'], '404 compatibility fallback did not deliver the receipt.' );
	$check( 2 === count( $signed_calls ) && 1 === count( $unsigned_calls ), 'Old-Global fallback did not use exactly one unsigned request.' );
	$check( $revoke['consent_id'] === ( $unsigned_calls[0]['consent_id'] ?? '' ), 'Fallback changed the receipt payload.' );

	$mode = 'signed_bad';
	$grant2 = KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'granted', get_current_user_id() );
	$created_ids[] = $grant2['consent_id'];
	$check( 'failed' === $grant2['receipt_status'], 'A signature rejection was hidden as successful delivery.' );
	$check( 3 === count( $signed_calls ) && 1 === count( $unsigned_calls ), 'A signature rejection bypassed verification through the unsigned endpoint.' );
} finally {
	remove_filter( 'pre_http_request', $intercept, 10 );
	foreach ( array_filter( $created_ids ) as $consent_id ) {
		foreach ( range( 1, 8 ) as $attempt ) {
			$timestamp = wp_next_scheduled( KaliCart_Bridge_Commerce_Consent::RETRY_HOOK, [ $consent_id, $attempt ] );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, KaliCart_Bridge_Commerce_Consent::RETRY_HOOK, [ $consent_id, $attempt ] );
			}
		}
		$wpdb->delete( $table, [ 'consent_id' => $consent_id ], [ '%s' ] );
	}
	foreach ( $original as $option_name => $value ) {
		if ( $missing === $value ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $value, false );
		}
	}
}

echo wp_json_encode( [
	'success' => empty( $failures ),
	'failures' => $failures,
	'report' => [ 'signed_calls' => count( $signed_calls ), 'unsigned_calls' => count( $unsigned_calls ) ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
	exit( 1 );
}
