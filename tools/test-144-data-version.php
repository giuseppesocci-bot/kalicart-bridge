<?php
/** 1.0.144: catalog_data_version declared in discovery, well-known mirror and signed heartbeat. php tools/test-144-data-version.php */
$root = dirname( __DIR__ ); $fails = 0; $tests = 0;
$ok = static function ( bool $c, string $m ) use ( &$fails, &$tests ): void { $tests++; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } };
$main = (string) file_get_contents( $root . '/kalicart-bridge.php' );
$api  = (string) file_get_contents( $root . '/includes/class-api.php' );
$sig  = (string) file_get_contents( $root . '/includes/class-signals.php' );
$id   = (string) file_get_contents( $root . '/includes/class-identity.php' );
$ok( (bool) preg_match( "/define\\( 'KALICART_BRIDGE_CATALOG_DATA_VERSION', (\\d+) \\);/", $main, $m ) && (int) $m[1] >= 1, 'constant defined as a positive integer' );
$ok( false === strpos( $main, "define( 'KALICART_BRIDGE_CATALOG_DATA_VERSION', '" ), 'not a version string (bump.py must never rewrite it)' );
$ok( false !== strpos( $api, "'catalog_data_version'   => (int) KALICART_BRIDGE_CATALOG_DATA_VERSION," ), 'REST discovery declares it next to plugin_version' );
$ok( false !== strpos( $sig, "'catalog_data_version' => (int) KALICART_BRIDGE_CATALOG_DATA_VERSION," ), '.well-known mirror declares it' );
$h0 = strpos( $id, "self::post( '/v1/bridge/identity/heartbeat'" );
$ok( false !== $h0 && false !== strpos( substr( $id, $h0, 900 ), "'catalog_data_version' =>" ), 'signed heartbeat carries it' );
$r0 = strpos( $id, "self::post( '/v1/bridge/identity/register'" );
$ok( false === strpos( substr( $id, $r0, 400 ), 'catalog_data_version' ), 'register payload unchanged' );
echo "\ndata-version-144: " . ( $fails ? 'FAIL' : 'OK' ) . " ($tests/$tests)\n"; exit( $fails ? 1 : 0 );
