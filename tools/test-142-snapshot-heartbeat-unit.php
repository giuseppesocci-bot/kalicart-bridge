<?php
// Standalone unit proof for the post-publication heartbeat. No WordPress, DB or network.
define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'snapshot-heartbeat-test-key' );
define( 'KALICART_BRIDGE_GLOBAL', 'https://global.test' );
define( 'KALICART_BRIDGE_VERSION', '1.0.142-test' );
define( 'KALICART_BRIDGE_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$options = [ 'kalicart_bridge_global_consent' => true ];
$actions = [];
$scheduled = [];
$posts = [];
$http_code = 200;
$snapshot_seq = 10;

function add_action( $hook, $callback ) { global $actions; $actions[ $hook ] = $callback; }
function wp_next_scheduled( $hook ) { global $scheduled; return $scheduled[ $hook ][0] ?? false; }
function wp_schedule_event( $when, $recurrence, $hook ) { global $scheduled; $scheduled[ $hook ] = [ $when, $recurrence ]; return true; }
function wp_schedule_single_event( $when, $hook ) { global $scheduled; $scheduled[ $hook ] = [ $when, 'single' ]; return true; }
function wp_unschedule_event( $when, $hook ) { global $scheduled; unset( $scheduled[ $hook ] ); return true; }
function as_get_scheduled_actions( $args, $format ) { global $scheduled; return isset( $scheduled[ $args['hook'] ] ) ? [ 1 ] : []; }
function as_next_scheduled_action( $hook, $args, $group ) { global $scheduled; return $scheduled[ $hook ][0] ?? false; }
function as_unschedule_all_actions( $hook, $args, $group ) { global $scheduled; unset( $scheduled[ $hook ] ); return 1; }
function as_schedule_single_action( $when, $hook, $args, $group, $unique ) { global $scheduled; $scheduled[ $hook ] = [ $when, $group, $unique ]; return 1; }
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function delete_option( $key ) { global $options; unset( $options[ $key ] ); return true; }
function home_url( $path = '/' ) { return 'https://shop.test/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_get_environment_type() { return 'production'; }
function wp_generate_uuid4() { return '11111111-2222-4333-8444-555555555555'; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_bloginfo( $what ) { return '7.1-test'; }
function is_wp_error( $v ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_post( $url, $args ) {
	global $posts, $http_code;
	$posts[] = [ 'url' => $url, 'body' => json_decode( $args['body'], true ) ];
	return [ 'response' => [ 'code' => $http_code ], 'body' => $http_code < 300 ? '{"decision":null}' : '{"error":"temporary"}' ];
}

class ActionScheduler_Store { const STATUS_PENDING = 'pending'; }
class KaliCart_Bridge_Site_Health { public static function validate_decision( $v ) { return $v; } }
class KaliCart_Bridge_Snapshot {
	public static function heartbeat_fields(): array {
		global $snapshot_seq;
		return [ 'snapshot' => [ 'schema' => 'kalicart-snapshot/1', 'state' => 'ok', 'seq' => $snapshot_seq ], 'capabilities' => [ 'snapshot-1' ] ];
	}
}

require dirname( __DIR__ ) . '/includes/class-identity.php';

$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey( $pair );
$pk = sodium_crypto_sign_publickey( $pair );
$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
$box_key = sodium_crypto_generichash( AUTH_KEY, sodium_crypto_generichash( 'kalicart-bridge-identity-v1', '', 32 ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$options[ KaliCart_Bridge_Identity::OPTION ] = [
	'installation_id' => '11111111-2222-4333-8444-555555555555',
	'public_key' => rtrim( strtr( base64_encode( $pk ), '+/', '-_' ), '=' ),
	'fingerprint' => 'fp-test',
	'sealed' => base64_encode( $nonce . sodium_crypto_secretbox( $sk, $nonce, $box_key ) ),
	'home_url' => home_url( '/' ),
	'status' => 'verified',
];

$fails = 0; $n = 0;
$ok = function ( $condition, $label ) use ( &$fails, &$n ) {
	$n++; echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $condition ) { $fails++; }
};

KaliCart_Bridge_Identity::init();
unset( $scheduled[ KaliCart_Bridge_Identity::CRON_HOOK ] );
$secret_method = new ReflectionMethod( KaliCart_Bridge_Identity::class, 'secret_key' );
$secret_method->setAccessible( true );
$ok( '' === KaliCart_Bridge_Identity::unavailable_reason() && is_string( $secret_method->invoke( null, get_option( KaliCart_Bridge_Identity::OPTION ) ) ),
	'identity verified and local key usable' );
KaliCart_Bridge_Identity::snapshot_published();
$first = $scheduled[ KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK ] ?? null;
KaliCart_Bridge_Identity::snapshot_published();
$ok( $first && $first === $scheduled[ KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK ], 'publications close together: a single pending action' );

unset( $scheduled[ KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK ] );
KaliCart_Bridge_Identity::snapshot_heartbeat();
$ok( 1 === count( $posts ) && 10 === $posts[0]['body']['snapshot']['seq'], 'the worker sends the current generation' );
$ok( 10 === (int) get_option( KaliCart_Bridge_Identity::OPTION )['last_snapshot_heartbeat_seq'], 'the delivered seq is persisted' );
KaliCart_Bridge_Identity::snapshot_heartbeat();
$ok( 1 === count( $posts ), 'same seq: no duplicate call' );

$snapshot_seq = 11;
$http_code = 503;
KaliCart_Bridge_Identity::snapshot_heartbeat();
$retry = $scheduled[ KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK ] ?? null;
$state = get_option( KaliCart_Bridge_Identity::OPTION );
$ok( 2 === count( $posts ) && 1 === (int) $state['snapshot_heartbeat_attempt'], 'temporary error: attempt counted without losing the seq' );
$ok( $retry && $retry[0] >= time() + 299, 'temporary error: retry with a backoff of at least 5 minutes' );
$snapshot_seq = 12;
KaliCart_Bridge_Identity::snapshot_published();
$early = $scheduled[ KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK ] ?? null;
$ok( $early && $early[0] < $retry[0] && $early[0] <= time() + 61,
	'new publication: brings the previous retry forward without queueing one per generation' );

echo "\nsnapshot-heartbeat-142: " . ( $fails ? 'FAIL' : 'OK' ) . " (" . ( $n - $fails ) . "/$n)\n";
exit( $fails ? 1 : 0 );
