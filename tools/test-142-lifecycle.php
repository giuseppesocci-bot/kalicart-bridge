<?php
/**
 * 1.0.142 lifecycle regression suite. Runs without a WordPress installation:
 *
 *   php tools/test-142-lifecycle.php
 */

$root  = dirname( __DIR__ );
$fails = 0;
$tests = 0;
$ok = static function ( bool $condition, string $message ) use ( &$fails, &$tests ): void {
	$tests++;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $message . "\n";
	if ( ! $condition ) {
		$fails++;
	}
};

/** Extract a named function body using PHP tokens (not a brace-fragile regex). */
$function_body = static function ( string $source, string $wanted ): string {
	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				if ( $wanted !== $tokens[ $j ][1] ) {
					break;
				}
				while ( $j < $count && '{' !== $tokens[ $j ] ) {
					$j++;
				}
				$depth = 0;
				$body  = '';
				for ( ; $j < $count; $j++ ) {
					$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
					$body .= $text;
					if ( '{' === $text ) {
						$depth++;
					} elseif ( '}' === $text && 0 === --$depth ) {
						return $body;
					}
				}
			}
		}
	}
	return '';
};

$main      = (string) file_get_contents( $root . '/kalicart-bridge.php' );
$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
$snapshot  = (string) file_get_contents( $root . '/includes/class-snapshot.php' );
$readme    = (string) file_get_contents( $root . '/readme.txt' );
$schedule  = $function_body( $main, 'kalicart_bridge_schedule_deactivation_cleanup' );
$shutdown  = $function_body( $main, 'kalicart_bridge_deactivation_shutdown' );
$activation_pos = strpos( $main, 'register_activation_hook' );
$deactivation_pos = strpos( $main, 'function kalicart_bridge_deactivation_shutdown' );
$activation = false !== $activation_pos && false !== $deactivation_pos
	? substr( $main, $activation_pos, $deactivation_pos - $activation_pos )
	: '';

$ok( '' !== $schedule && false !== strpos( $schedule, "register_shutdown_function( 'kalicart_bridge_deactivation_shutdown' )" ), 'deactivation callback only registers the named shutdown' );
$ok( ! preg_match( '/\b(?:update_option|delete_option|wp_clear_scheduled_hook|flush_rewrite_rules|wp_remote_[a-z_]+|leaving)\b/', $schedule ), 'deactivation callback has no persistent write, rewrite or network operation' );
$ok( false !== strpos( $main, "register_deactivation_hook( __FILE__, 'kalicart_bridge_schedule_deactivation_cleanup' )" ), 'WordPress hook uses the minimal callback' );
$ok( '' !== $shutdown && ! preg_match( '/\b(?:flush_rewrite_rules|wp_remote_[a-z_]+|leaving|glob|as_unschedule_all_actions)\b/', $shutdown ), 'shutdown has no network, rewrite, glob or mass Action Scheduler call' );
$ok( false !== strpos( $shutdown, "wp_cache_delete( 'active_plugins', 'options' )" ) && false !== strpos( $shutdown, "get_site_option( 'active_sitewide_plugins'" ), 'shutdown refreshes local and multisite activation state' );
$guard_pos = strpos( $shutdown, 'in_array( $plugin, $active, true )' );
$cleanup_pos = strpos( $shutdown, 'wp_clear_scheduled_hook( $hook )' );
$ok( false !== $guard_pos && false !== $cleanup_pos && $guard_pos < $cleanup_pos, 'shutdown exits for a still-active plugin before any cleanup' );
// 1.0.143: the stored rewrite rules are never emptied by the lifecycle.
$all_php = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$path = $f->getPathname();
	if ( substr( $path, -4 ) === '.php' && false === strpos( $path, '/tools/' ) && false === strpos( $path, '/.git/' ) ) {
		$all_php .= (string) file_get_contents( $path );
	}
}
$ok( ! preg_match( "/delete_option\\(\\s*'rewrite_rules'/", $all_php ), '1.0.143: no code path deletes the stored rewrite rules' );
$ok( ! preg_match( '/flush_rewrite_rules\\(\\s*\\)/', $all_php ) && ! preg_match( '/flush_rewrite_rules\\(\\s*true/', $all_php ), '1.0.143: no hard rewrite flush anywhere in the plugin' );
$ok( false === strpos( $shutdown, 'rewrite' ), '1.0.143: deactivation leaves rewrite rules untouched' );
$ok( false !== strpos( $activation, "update_option( 'kalicart_bridge_rewrite_pending', 1, false )" ) && false === strpos( $activation, 'flush_rewrite_rules' ), '1.0.143: activation only sets the pending flag' );
$ok( 1 === substr_count( $main, 'flush_rewrite_rules( false )' ), '1.0.143: a single soft refresh in the init callback' );
$init_pos = strpos( $main, "\$rewrite_pending = (bool) get_option( 'kalicart_bridge_rewrite_pending', false );" );
$flush_pos = strpos( $main, 'flush_rewrite_rules( false )' );
$clear_pos = strpos( $main, "delete_option( 'kalicart_bridge_rewrite_pending' )" );
$confirm  = strpos( $main, "add_action( 'wp_loaded', static function () {" );
$ok( false !== $init_pos && $init_pos < $flush_pos && $flush_pos < $confirm && $confirm < $clear_pos && false !== strpos( $main, '}, PHP_INT_MAX );' ), '1.0.143: pending flag cleared only on wp_loaded after the core refresh' );
$ok( 2 === substr_count( $main, "update_option( 'kalicart_bridge_rewrite_pending', 1, false )" ), '1.0.143: pending is set on activation and before every init refresh (upgrades included)' );
$ok( false !== strpos( $uninstall, "'kalicart_bridge_rewrite_pending'" ), '1.0.143: uninstall removes the pending flag' );
$ok( false !== strpos( $snapshot, 'public static function deactivation_cleanup' ) && false !== strpos( $snapshot, "'owned_paths'" ), 'snapshot cleanup uses a persisted finite ownership list' );
$ok( false === strpos( $uninstall, '::leaving(' ) && ! preg_match( '/wp_remote_[a-z_]+\s*\(/', $uninstall ), 'uninstall is local-only' );
$last_cleanup = max( strrpos( $uninstall, 'wp_delete_file' ), strrpos( $uninstall, 'delete_transient' ), strrpos( $uninstall, 'DROP TABLE' ) );
$first_option_delete = strpos( $uninstall, 'foreach ( $kalicart_bridge_options as $kalicart_bridge_option )' );
$ok( false !== $first_option_delete && $first_option_delete > $last_cleanup, 'uninstall deletes options only after files, transients and table cleanup' );
$ok( false !== strpos( $uninstall, "'kalicart_bridge_identity'" ), 'uninstall removes the installation identity' );
$ok( false === strpos( $readme, 'it sends a signed leaving signal when the plugin is deactivated or deleted' ), 'external-service disclosure matches the local-only lifecycle' );

// Minimal WordPress fixture for path, cleanup, uninstall and reinstall behavior.
$tmp = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/kalicart-142-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) . '' );
@mkdir( $tmp . '/site/.well-known/kalicart', 0777, true );
@mkdir( $tmp . '/uploads/kalicart', 0777, true );
@mkdir( $tmp . '/uploads/kalicart-bridge/ABCDEFGHIJKLMNOP', 0777, true );

define( 'ABSPATH', $tmp . '/site/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'AUTH_KEY', 'test-only-auth-key' );
define( 'KALICART_BRIDGE_DIR', $root . '/' );
define( 'KALICART_BRIDGE_VERSION', '1.0.142-test' );

$GLOBALS['kalicart_test_options'] = [];
$GLOBALS['kalicart_test_events']  = [];
$GLOBALS['kalicart_uploads']      = $tmp . '/uploads';

function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( wp_normalize_path( $path ), '/' ) . '/'; }
function plugin_dir_path( $file ) { return trailingslashit( dirname( $file ) ); }
function get_option( $name, $default = false ) { return $GLOBALS['kalicart_test_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['kalicart_test_options'][ $name ] = $value; $GLOBALS['kalicart_test_events'][] = 'update_option:' . $name; return true; }
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) { if ( array_key_exists( $name, $GLOBALS['kalicart_test_options'] ) ) return false; return update_option( $name, $value, $autoload ); }
function delete_option( $name ) { unset( $GLOBALS['kalicart_test_options'][ $name ] ); $GLOBALS['kalicart_test_events'][] = 'delete_option:' . $name; return true; }
function wp_upload_dir( $time = null, $create_dir = true ) { return [ 'basedir' => $GLOBALS['kalicart_uploads'], 'baseurl' => 'https://example.test/uploads', 'error' => false ]; }
function wp_delete_file( $path ) { $GLOBALS['kalicart_test_events'][] = 'file:' . basename( $path ); if ( is_file( $path ) || is_link( $path ) ) return unlink( $path ); return false; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['kalicart_test_events'][] = 'cron:' . $hook; return 1; }
function wp_unschedule_hook( $hook ) { $GLOBALS['kalicart_test_events'][] = 'cron:' . $hook; return 1; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( $time, $recurrence, $hook ) { $GLOBALS['kalicart_test_events'][] = 'schedule:' . $hook; return true; }
function wp_schedule_single_event( $time, $hook ) { $GLOBALS['kalicart_test_events'][] = 'schedule:' . $hook; return true; }
function wp_rand( $min, $max ) { return $min; }
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) { return str_repeat( 'A', $length ); }
function wp_generate_uuid4() { static $n = 0; $n++; return sprintf( '00000000-0000-4000-8000-%012d', $n ); }
function delete_transient( $name ) { $GLOBALS['kalicart_test_events'][] = 'transient:' . $name; return true; }
function home_url( $path = '' ) { return 'https://example.test' . ( '/' === substr( $path, 0, 1 ) ? $path : '/' . $path ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function WP_Filesystem() {
	$GLOBALS['wp_filesystem'] = new class {
		public function rmdir( $dir, $recursive = false ) { return @rmdir( $dir ); }
	};
	return true;
}

class KaliCart_Test_WPDB {
	public $options = 'wp_options';
	public $prefix = 'wp_';
	public $rows_affected = 0;
	public function prepare( $query, ...$args ) { return $query; }
	public function query( $query ) { $GLOBALS['kalicart_test_events'][] = false !== stripos( $query, 'DROP TABLE' ) ? 'drop_table' : 'db_query'; return 1; }
	public function get_results( $query, $output = null ) { return []; }
	public function get_var( $query ) { return null; }
	public function esc_like( $value ) { return $value; }
}
$GLOBALS['wpdb'] = new KaliCart_Test_WPDB();

require_once $root . '/includes/class-snapshot-profile.php';
require_once $root . '/includes/class-snapshot.php';
require_once $root . '/includes/class-signals.php';
require_once $root . '/includes/class-identity.php';

$shard   = 'c-' . str_repeat( 'a', 32 ) . '.jsonl.gz';
$linked_shard = 'c-' . str_repeat( 'b', 32 ) . '.jsonl.gz';
$outside = $tmp . '/outside.txt';
file_put_contents( $outside, 'merchant' );
file_put_contents( ABSPATH . '.well-known/kalicart/manifest.json', '{}' );
file_put_contents( ABSPATH . '.well-known/kalicart/' . $shard, 'snapshot' );
file_put_contents( ABSPATH . '.well-known/kalicart/merchant.txt', 'merchant' );
$snapshot_symlink = @symlink( $outside, ABSPATH . '.well-known/kalicart/' . $linked_shard );
$GLOBALS['kalicart_test_options']['kalicart_bridge_snapshot'] = [
	'seq'       => 7,
	'state'     => 'ok',
	'published' => [
		'dir'         => ABSPATH . '.well-known/kalicart/',
		'owned_paths' => [ $shard, $linked_shard, '../../outside.txt', 'merchant.txt' ],
	],
];
$GLOBALS['kalicart_test_events'] = [];
KaliCart_Bridge_Snapshot::deactivation_cleanup();
$ok( ! file_exists( ABSPATH . '.well-known/kalicart/manifest.json' ) && ! file_exists( ABSPATH . '.well-known/kalicart/' . $shard ), 'deactivation removes the manifest first and its recorded shard' );
$manifest_event = array_search( 'file:manifest.json', $GLOBALS['kalicart_test_events'], true );
$shard_event    = array_search( 'file:' . $shard, $GLOBALS['kalicart_test_events'], true );
$ok( false !== $manifest_event && false !== $shard_event && $manifest_event < $shard_event, 'deactivation deletes the manifest before shard content' );
$ok( file_exists( ABSPATH . '.well-known/kalicart/merchant.txt' ) && file_exists( $outside ), 'deactivation rejects unowned and traversal paths' );
$ok( ! $snapshot_symlink || ( is_link( ABSPATH . '.well-known/kalicart/' . $linked_shard ) && 'merchant' === file_get_contents( $outside ) ), 'deactivation never follows a recorded shard symlink' );
$ok( [ 'seq' => 7, 'state' => 'disabled' ] === get_option( 'kalicart_bridge_snapshot' ), 'deactivation preserves only sequence and disabled state' );
$ok( in_array( 'cron:kalicart_bridge_snapshot_build', $GLOBALS['kalicart_test_events'], true ) && in_array( 'cron:kalicart_bridge_snapshot_daily', $GLOBALS['kalicart_test_events'], true ), 'deactivation clears the two known snapshot WP-Cron hooks' );

file_put_contents( ABSPATH . '.well-known/agent.json', '{"vendor":"KaliCart"}' );
file_put_contents( ABSPATH . '.well-known/api-catalog.json', '{"vendor":"merchant"}' );
$signal_symlink = @symlink( $outside, ABSPATH . '.well-known/kalicart-bridge.json' );
KaliCart_Bridge_Signals::remove_well_known_files();
$ok( ! file_exists( ABSPATH . '.well-known/agent.json' ) && file_exists( ABSPATH . '.well-known/api-catalog.json' ), 'well-known cleanup deletes only content identified as KaliCart' );
$ok( ! $signal_symlink || ( is_link( ABSPATH . '.well-known/kalicart-bridge.json' ) && 'merchant' === file_get_contents( $outside ) ), 'well-known cleanup never follows a symlink' );

// Generate an old identity, uninstall twice, then ensure reinstall generates a
// different installation ID without any network function being available.
$ensure_key = new ReflectionMethod( 'KaliCart_Bridge_Identity', 'ensure_key' );
$ensure_key->setAccessible( true );
$can_generate_identity = function_exists( 'sodium_crypto_sign_keypair' );
if ( $can_generate_identity ) {
	[ $old_identity ] = $ensure_key->invoke( null );
} else {
	$old_identity = [ 'installation_id' => 'old-test-installation' ];
	$GLOBALS['kalicart_test_options']['kalicart_bridge_identity'] = $old_identity;
}
$GLOBALS['kalicart_test_options']['kalicart_bridge_acp_feed'] = [ 'token' => 'ABCDEFGHIJKLMNOP' ];
file_put_contents( $tmp . '/uploads/kalicart-bridge/ABCDEFGHIJKLMNOP/acp-products.jsonl', "{}\n" );
$GLOBALS['kalicart_test_events'] = [];
define( 'WP_UNINSTALL_PLUGIN', true );
include $root . '/uninstall.php';
$first_uninstall_events = $GLOBALS['kalicart_test_events'];
$ok( ! array_key_exists( 'kalicart_bridge_identity', $GLOBALS['kalicart_test_options'] ), 'uninstall removes identity' );
$first_delete = null;
$cleanup_after_delete = false;
foreach ( $first_uninstall_events as $index => $event ) {
	if ( null === $first_delete && 0 === strpos( $event, 'delete_option:' ) ) {
		$first_delete = $index;
	}
	if ( null !== $first_delete && ( 0 === strpos( $event, 'file:' ) || 0 === strpos( $event, 'transient:' ) || 'drop_table' === $event ) ) {
		$cleanup_after_delete = true;
	}
}
$ok( null !== $first_delete && ! $cleanup_after_delete, 'runtime uninstall performs options as its final cleanup phase' );

$GLOBALS['kalicart_test_events'] = [];
include $root . '/uninstall.php';
$ok( ! array_key_exists( 'kalicart_bridge_identity', $GLOBALS['kalicart_test_options'] ), 'uninstall is idempotent' );
if ( $can_generate_identity ) {
	[ $new_identity ] = $ensure_key->invoke( null );
	$ok( $old_identity['installation_id'] !== $new_identity['installation_id'], 'uninstall followed by reinstall creates a new installation identity' );
} else {
	$identity_source = (string) file_get_contents( $root . '/includes/class-identity.php' );
	$ok( false !== strpos( $identity_source, "'installation_id' => \$same_site ? \$s['installation_id'] : wp_generate_uuid4()" ), 'identity factory creates a new installation ID after the uninstall option is absent (static fallback: sodium unavailable)' );
}

$remove_tree = static function ( string $path ) use ( &$remove_tree ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) return;
	foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $item ) {
		$remove_tree( $item->getPathname() );
	}
	@rmdir( $path );
};
$remove_tree( $tmp );

echo "\nlifecycle-142: " . ( $fails ? 'FAIL' : 'OK' ) . ' (' . ( $tests - $fails ) . "/$tests)\n";
exit( $fails ? 1 : 0 );
