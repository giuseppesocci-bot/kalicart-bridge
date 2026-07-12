<?php
/** Self-contained deterministic unit test; run with `php tools/test-rate-guard-local.php`. */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'ABSPATH', __DIR__ );

$filters = array();
$cache   = array();
function add_filter( $name, $callback ) { global $filters; $filters[ $name ][] = $callback; }
function remove_filter( $name, $callback ) { global $filters; $filters[ $name ] = array_filter( $filters[ $name ] ?? array(), static fn( $item ) => $item !== $callback ); }
function apply_filters( $name, $value ) { global $filters; foreach ( $filters[ $name ] ?? array() as $callback ) { $value = $callback( $value ); } return $value; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $value ) ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function maybe_unserialize( $value ) { return @unserialize( $value ); }
function wp_cache_delete( $key, $group ) { global $cache; unset( $cache[ $group ][ $key ] ); }
function wp_cache_set( $key, $value, $group ) { global $cache; $cache[ $group ][ $key ] = $value; }
function wp_generate_uuid4() { static $n = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$n ); }

final class Fake_WPDB {
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $rows = array();
	public ?string $release_lock_name = null;
	public int $release_after = 0;
	public int $insert_attempts = 0;
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_row( $prepared ) {
		$name = $prepared['args'][0];
		return isset( $this->rows[ $name ] ) ? (object) array( 'option_value' => $this->rows[ $name ] ) : null;
	}
	public function query( $prepared ) {
		$sql  = ltrim( $prepared['sql'] );
		$args = $prepared['args'];
		$this->last_error = '';
		if ( str_starts_with( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $args[0] ] ) ) {
				if ( $args[0] === $this->release_lock_name && ++$this->insert_attempts >= $this->release_after ) {
					unset( $this->rows[ $args[0] ] );
				}
				return 0;
			}
			$this->rows[ $args[0] ] = $args[1];
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE' ) ) {
			if ( ! isset( $this->rows[ $args[1] ] ) || $this->rows[ $args[1] ] !== $args[2] ) return 0;
			$this->rows[ $args[1] ] = $args[0];
			return 1;
		}
		if ( str_starts_with( $sql, 'DELETE' ) ) {
			if ( ! isset( $this->rows[ $args[0] ] ) || $this->rows[ $args[0] ] !== $args[1] ) return 0;
			unset( $this->rows[ $args[0] ] );
			return 1;
		}
		$this->last_error = 'unsupported query';
		return false;
	}
}

$wpdb = new Fake_WPDB();
require_once dirname( __DIR__ ) . '/includes/class-rate-guard.php';

$failures = array();
$check = static function( $condition, $message ) use ( &$failures ) { if ( ! $condition ) $failures[] = $message; };
$state = static function( $scope ) use ( $wpdb ) { return maybe_unserialize( $wpdb->rows[ 'kalicart_rate_guard_' . $scope ] ?? '' ); };
$limits = array( 'client_limit' => 1, 'client_window' => 60, 'global_limit' => 3, 'global_window' => 60, 'max_clients' => 4 );
$check( 'invalid_scope' === KaliCart_Bridge_Rate_Guard::check( '../unit', 1, $limits )['reason'], 'invalid scope collided with a valid state bucket' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
$check( KaliCart_Bridge_Rate_Guard::check( 'unit', 1, $limits )['allowed'], 'first request rejected' );
$before = $state( 'unit' )['global']['count'];
$reject = KaliCart_Bridge_Rate_Guard::check( 'unit', 1, $limits );
$check( ! $reject['allowed'] && 'client' === $reject['reason'], 'client cap not enforced' );
$check( $before === $state( 'unit' )['global']['count'], 'client reject consumed global quota' );

wp_cache_set( 'kalicart_rate_guard_unit', array( 'global' => array( 'count' => 0 ) ), 'options' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
$check( KaliCart_Bridge_Rate_Guard::check( 'unit', 1, $limits )['allowed'], 'stale-cache request rejected' );
$check( 2 === $state( 'unit' )['global']['count'], 'stale cache lost an increment' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
$check( KaliCart_Bridge_Rate_Guard::check( 'unit', 1, $limits )['allowed'], 'third global unit rejected' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
$global = KaliCart_Bridge_Rate_Guard::check( 'unit', 1, $limits );
$check( ! $global['allowed'] && 'global' === $global['reason'], 'global cap not enforced' );

$ref   = new ReflectionClass( 'KaliCart_Bridge_Rate_Guard' );
$read  = $ref->getMethod( 'read_state' );
$write = $ref->getMethod( 'write_state' );
$cas   = $ref->getMethod( 'compare_delete' );
foreach ( array( $read, $write, $cas ) as $method ) $method->setAccessible( true );
$a = $read->invoke( null, 'kalicart_rate_guard_unit' );
$b = $read->invoke( null, 'kalicart_rate_guard_unit' );
$a['state']['global']['count']++;
$b['state']['global']['count']++;
$check( $write->invoke( null, 'kalicart_rate_guard_unit', $a['state'], true, $a['raw'] ), 'first interleaved CAS failed' );
$check( ! $write->invoke( null, 'kalicart_rate_guard_unit', $b['state'], true, $b['raw'] ), 'stale interleaved CAS overwrote state' );

$lock_name = 'kalicart_rate_guard_lock_unit';
$new_raw   = maybe_serialize( array( 'owner' => 'new', 'expires' => microtime( true ) + 10 ) );
$old_raw   = maybe_serialize( array( 'owner' => 'old', 'expires' => microtime( true ) - 10 ) );
$wpdb->rows[ $lock_name ] = $new_raw;
$check( ! $cas->invoke( null, $lock_name, $old_raw ) && $wpdb->rows[ $lock_name ] === $new_raw, 'stale owner deleted successor lock' );

// A valid short-lived owner that releases within the contention budget is
// waited for instead of producing a false 429.
$wait_scope = 'wait_unit';
$wait_lock  = 'kalicart_rate_guard_lock_' . $wait_scope;
$wpdb->rows[ $wait_lock ]       = maybe_serialize( array( 'owner' => 'brief', 'expires' => microtime( true ) + 1 ) );
$wpdb->release_lock_name        = $wait_lock;
$wpdb->release_after            = 5;
$wpdb->insert_attempts          = 0;
$_SERVER['REMOTE_ADDR']         = '192.0.2.80';
$wait_result = KaliCart_Bridge_Rate_Guard::check( $wait_scope, 1, array( 'client_limit' => 2, 'client_window' => 60, 'global_limit' => 2, 'global_window' => 60 ) );
$check( $wait_result['allowed'] && $wpdb->insert_attempts >= 5, 'brief valid mutex was not acquired within the wait budget' );
$wpdb->release_lock_name = null;

$trusted = static fn() => array( '10.0.0.0/8', '0.0.0.0/abc' );
add_filter( 'kalicart_bridge_trusted_proxies', $trusted );
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.44, 10.0.0.2';
$check( '192.0.2.44' === KaliCart_Bridge_Rate_Guard::client_ip(), 'right-edge proxy resolution failed' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.44, bad-hop, 10.0.0.2';
$check( '10.0.0.1' === KaliCart_Bridge_Rate_Guard::client_ip(), 'malformed XFF did not fail closed' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = str_repeat( '198.18.0.1,', 300 ) . '192.0.2.55, 10.0.0.2';
$check( '192.0.2.55' === KaliCart_Bridge_Rate_Guard::client_ip(), 'bounded XFF parser lost the trustworthy right edge' );
$check( ! KaliCart_Bridge_Rate_Guard::ip_in_cidr( '203.0.113.2', '0.0.0.0/abc' ), 'malformed CIDR accepted' );

echo json_encode( array( 'ok' => empty( $failures ), 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL;
exit( empty( $failures ) ? 0 : 1 );
