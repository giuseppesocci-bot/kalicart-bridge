<?php
/**
 * Standalone deterministic tests for the automatic federation lifecycle.
 * Run: php tools/test-federation-lifecycle.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'KALICART_BRIDGE_GLOBAL', 'https://dashboard.kalicart.com' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$options   = array();
$scheduled = array();
$responses = array();
$requests  = array();
$failures  = 0;

function add_action( $hook, $callback, $priority = 10 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return true;
}
function get_option( $key, $default = false ) {
	global $options;
	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	global $options;
	$options[ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	global $options;
	unset( $options[ $key ] );
	return true;
}
function wp_next_scheduled( $hook ) {
	global $scheduled;
	return $scheduled[ $hook ] ?? false;
}
function wp_schedule_single_event( $timestamp, $hook ) {
	global $scheduled;
	$scheduled[ $hook ] = $timestamp;
	return true;
}
function get_site_url() {
	return 'https://shop.example';
}
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function sanitize_text_field( $value ) {
	return preg_replace( '/[^a-z0-9_-]/i', '', (string) $value );
}
class WP_Error {
	private string $code;
	public function __construct( string $code ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_remote_post( $url, $args ) {
	global $responses, $requests;
	$requests[] = array( 'url' => $url, 'args' => $args );
	return array_shift( $responses );
}
function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

require dirname( __DIR__ ) . '/includes/class-federation.php';

$check = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
};

KaliCart_Bridge_Federation::activate();
$check( true === get_option( 'kalicart_bridge_global_consent' ), 'Activation must publish the indexable state.' );
$check( '1.0.122' === get_option( 'kalicart_bridge_federation_lifecycle_version' ), 'Activation must mark the lifecycle version.' );
$check( 1 === count( $scheduled ), 'Activation must schedule exactly one announcement.' );
$check( 0 === count( $requests ), 'Activation must not block on a network request.' );

// WP-Cron removes the due event before invoking its callback.
$scheduled = array();
$responses[] = new WP_Error( 'timeout' );
KaliCart_Bridge_Federation::announce();
$check( 1 === get_option( 'kalicart_bridge_federation_announce_attempts' ), 'A failed announcement must increment the bounded retry counter.' );
$check( 1 === count( $scheduled ), 'A failed announcement must schedule one retry.' );

$scheduled = array();
$responses[] = array( 'response' => array( 'code' => 204 ) );
KaliCart_Bridge_Federation::announce();
$check( '' !== get_option( 'kalicart_bridge_federation_registered_at', '' ), 'A successful announcement must store registration time.' );
$check( false === get_option( 'kalicart_bridge_federation_announce_attempts', false ), 'Success must clear retry state.' );
$check( 2 === count( $requests ), 'The test must perform only the two explicit cron requests.' );
$check( '{"domain":"https:\/\/shop.example\/"}' === $requests[0]['args']['body'], 'Announcement payload must contain only the canonical public URL.' );
$check( 0 === $requests[0]['args']['redirection'], 'Federation requests must not follow redirects.' );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "Federation lifecycle tests passed.\n";
