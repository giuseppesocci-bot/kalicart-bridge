<?php
/**
 * 1.0.144 fast re-entry after activation/upgrade. Runs without WordPress:
 *
 *   php tools/test-144-reentry.php
 */
$root = dirname( __DIR__ );
$fails = 0; $tests = 0;
$ok = static function ( bool $c, string $m ) use ( &$fails, &$tests ): void { $tests++; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } };
$main = (string) file_get_contents( $root . '/kalicart-bridge.php' );
$id   = (string) file_get_contents( $root . '/includes/class-identity.php' );
$un   = (string) file_get_contents( $root . '/uninstall.php' );

$a0 = strpos( $main, 'register_activation_hook' );
$a1 = strpos( $main, 'function kalicart_bridge_deactivation_shutdown' );
$activation = substr( $main, $a0, $a1 - $a0 );
$ok( false !== strpos( $activation, 'KaliCart_Bridge_Identity::request_reentry();' ), 'activation queues the re-entry heartbeat' );
$ok( ! preg_match( '/wp_remote_|::heartbeat\(|::tick\(/', $activation ), 'activation performs no network call' );
$i0 = strpos( $main, '$version_changed = get_option' );
$i1 = strpos( $main, '}, 20 );', $i0 );
$ok( false !== strpos( substr( $main, $i0, $i1 - $i0 ), 'KaliCart_Bridge_Identity::request_reentry();' ), 'upgrade (version change) queues the re-entry heartbeat' );

$r0 = strpos( $id, 'public static function request_reentry' );
$r1 = strpos( $id, 'private static function record' );
$req = substr( $id, $r0, $r1 - $r0 );
$ok( false !== strpos( $req, "update_option( self::REENTRY_OPT, 1, false )" ) && false !== strpos( $req, 'wp_schedule_single_event' ), 'request_reentry = local flag + one background event' );
$ok( ! preg_match( '/wp_remote_|self::post\(|self::heartbeat\(/', $req ), 'request_reentry has no network' );
$ok( false !== strpos( $req, '! wp_next_scheduled( self::REENTRY_HOOK )' ), 'never more than one pending re-entry event' );
$ok( false !== strpos( $id, "add_action( self::REENTRY_HOOK, [ __CLASS__, 'tick' ] )" ), 're-entry event runs the normal signed tick' );
$t0 = strpos( $id, 'public static function tick' );
$tick = substr( $id, $t0, strpos( $id, 'public static function request_reentry' ) - $t0 );
$ok( false !== strpos( $tick, '$reentry || time() -' ), 'tick sends the heartbeat when re-entry is pending, regardless of the 20 h interval' );
$ok( preg_match( '/if \( self::heartbeat\( \$s, \$sk \) \) \{\s*if \( \$reentry \) \{\s*delete_option\( self::REENTRY_OPT \);/', $tick ) === 1, 'verified identity: flag cleared only inside the successful-heartbeat branch' );
$ok( substr_count( $tick, 'self::reentry_failed()' ) === 2, 'failed re-entry (prove or heartbeat) goes through the bounded retry' );
$f0 = strpos( $id, 'private static function reentry_failed' );
$fail = substr( $id, $f0, strpos( $id, 'private static function schedule_reentry' ) - $f0 );
$ok( false !== strpos( $fail, 'self::schedule_reentry( 30 * MINUTE_IN_SECONDS )' ) && false !== strpos( $fail, '>= self::REENTRY_MAX_ATTEMPTS' ), 'retry every 30 min, capped at REENTRY_MAX_ATTEMPTS, then the daily tick takes over' );
$ok( false !== strpos( $tick, '$sent = self::prove_now( $s, $sk );' ) && false !== strpos( $tick, "\$sent ? delete_option( self::REENTRY_OPT ) : self::reentry_failed();" ), 'fresh identity: the post-proof heartbeat counts as re-entry (no second heartbeat)' );
$p0 = strpos( $id, 'private static function prove_now' );
$prove = substr( $id, $p0, strpos( $id, 'private static function heartbeat' ) - $p0 );
$ok( false !== strpos( $prove, 'return self::heartbeat( $s, $sk );' ) && ! preg_match( '/return;\s/', $prove ), 'prove_now reports whether its heartbeat was delivered' );
$ok( strpos( $tick, "'verified' !== ( \$s['status'] ?? '' )" ) < strpos( $tick, 'self::heartbeat( $s, $sk )' ), 'unverified identity keeps the existing prove path first' );

$d0 = strpos( $main, 'function kalicart_bridge_deactivation_shutdown' );
$shutdown = substr( $main, $d0, strpos( $main, 'function kalicart_bridge_schedule_deactivation_cleanup' ) - $d0 );
$ok( false !== strpos( $shutdown, 'KaliCart_Bridge_Identity::REENTRY_HOOK,' ) && false !== strpos( $shutdown, 'KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK,' ), 'deactivation clears the re-entry and snapshot-heartbeat WP-Cron hooks' );
$ok( false !== strpos( $un, "'kalicart_bridge_identity_reentry'," ) && false !== strpos( $un, "wp_clear_scheduled_hook( 'kalicart_bridge_identity_reentry' )" ), 'uninstall removes flag and event' );

echo "\nreentry-144: " . ( $fails ? 'FAIL' : 'OK' ) . " ($tests/$tests)\n";
exit( $fails ? 1 : 0 );
