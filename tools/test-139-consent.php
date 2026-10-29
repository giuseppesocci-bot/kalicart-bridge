<?php
/**
 * 1.0.139: consent A (federated-catalog-1.1) receipt log.
 * Leaves the site exactly as found: the log option is saved and restored, the real
 * consent flag is never changed (legacy status is checked by reading it only).
 * Run: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-139-consent.php
 */
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$C = 'KaliCart_Bridge_Federation_Consent';
$had  = get_option( $C::OPTION, null );
$flag = get_option( 'kalicart_bridge_global_consent', false );
try {
	delete_option( $C::OPTION );
	$ok( $C::version() === ( $flag ? $C::LEGACY_ID : '' ), 'consent on without a receipt = legacy (computed, no receipt written): ' . $C::version() );
	$ok( 0 === count( $C::records() ), 'the legacy state does not fabricate receipts' );

	$g = $C::record( 'granted', 1 );
	$ok( 'federated-catalog-1.1' === $g['text_id'] && 'granted' === $g['action'] && null === $g['previous_record_hash'], 'activation recorded: text_id, action, first of the chain' );
	$ok( hash( 'sha256', $C::CANONICAL_TEXT ) === $g['canonical_text_hash'] && hash( 'sha256', $C::localized_text() ) === $g['consent_text_hash'], 'hash of the canonical text and of the displayed text' );
	$ok( preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $g['occurred_at_utc'] ) && 1 === $g['wp_user_id'] && KALICART_BRIDGE_VERSION === $g['plugin_version'], 'UTC time, user, plugin version' );
	if ( $flag ) { $ok( 'federated-catalog-1.1' === $C::version(), 'with the receipt the version in force is 1.1' ); }
	$again = $C::record( 'granted', 1 );
	$ok( 1 === count( $C::records() ) && $again['record_hash'] === $g['record_hash'], 'identical second activation: idempotent, no extra record' );
	$r = $C::record( 'revoked', 1 );
	$ok( 2 === count( $C::records() ) && $r['previous_record_hash'] === $g['record_hash'], 'revocation recorded and linked to the previous record (chain)' );
	$C::record( 'revoked', 1 );
	$ok( 2 === count( $C::records() ), 'second revocation: idempotent' );
	$C::record( 'granted', 2 );
	$ok( 3 === count( $C::records() ) && $C::verify_chain(), 'new activation: chain of 3 records verified' );
	$log = get_option( $C::OPTION );
	$log[1]['wp_user_id'] = 99;
	update_option( $C::OPTION, $log, false );
	$ok( false === $C::verify_chain(), 'altered record: the chain no longer verifies' );
	$log = get_option( $C::OPTION );
	$log[1]['wp_user_id'] = 1;
	array_splice( $log, 1, 1 );
	update_option( $C::OPTION, $log, false );
	$ok( false === $C::verify_chain(), 'record removed from the middle: the chain no longer verifies' );
	delete_option( $C::OPTION );
	$C::record( 'granted', 1 ); $C::record( 'revoked', 1 ); $C::record( 'granted', 1 );
	$log = get_option( $C::OPTION ); array_shift( $log ); update_option( $C::OPTION, $log, false );
	$ok( 2 === count( $C::records() ) && null !== $C::records()[0]['previous_record_hash'] && false === $C::verify_chain(), 'first record removed (truncated prefix): the chain no longer verifies' );
	$threw = false;
	try { $C::record( 'boh', 1 ); } catch ( InvalidArgumentException $e ) { $threw = true; }
	$ok( $threw, 'unknown action rejected' );
	delete_option( $C::OPTION );
	for ( $i = 0; $i < 150; $i++ ) { $C::record( 0 === $i % 2 ? 'granted' : 'revoked', 1 ); }
	$ok( 150 === count( $C::records() ) && $C::verify_chain() && null === $C::records()[0]['previous_record_hash'], 'append-only log: 150 state changes, full chain from the first record (no truncation)' );
	$ok( ! defined( $C . '::MAX_RECORDS' ), 'no cap that evicts receipts' );
	$un = (string) file_get_contents( KALICART_BRIDGE_DIR . 'uninstall.php' );
	$ok( false !== strpos( $un, "'" . $C::OPTION . "'" ), 'uninstall deletes the receipt log (like the commerce ledger)' );
	$ok( false !== strpos( $C::CANONICAL_TEXT, 'random identifier of this installation' ) && false !== strpos( $C::CANONICAL_TEXT, 'consent text version' ) && false !== strpos( $C::CANONICAL_TEXT, 'never error messages, file paths or stack traces' ), 'text A: the daily signal lists identifier, consent version, diagnostics' );
	$pp = implode( ' ', [ $C::localized_text() ] );
	$src = (string) file_get_contents( KALICART_BRIDGE_DIR . 'includes/class-federation-consent.php' );
	$pol = substr( $src, strpos( $src, 'function privacy_policy_content' ), 6000 );
	$ok( false === strpos( $pol, '24 months' ) && false === strpos( $pol, 'proof of consent' ), 'no "proof of consent" held by Global: receipt A is not sent' );
	$need = [ '/.well-known/kalicart/', 'wp-content/uploads/kalicart/', 'never exact stock quantities', 'once a day', 'revoked or the plugin is deactivated or deleted', '90 days', '30 days', 'anonymous daily totals', 'minimal revocation record', 'signing keys', 'is not sent to KaliCart Global', 'No data about customers, visitors, orders or payments' ];
	$miss = array_values( array_filter( $need, function ( $w ) use ( $pol ) { return false === strpos( $pol, $w ); } ) );
	$ok( [] === $miss, 'WordPress privacy text: paths, no quantities, frequency, deletion, retention ' . implode( ',', $miss ) );
	$rd = (string) file_get_contents( KALICART_BRIDGE_DIR . 'readme.txt' );
	$ok( false !== strpos( $rd, '**Retention:**' ) && false === strpos( $rd, '24 months' ) && false !== strpos( $rd, 'is not sent to KaliCart Global' ) && false !== strpos( $rd, '/.well-known/kalicart/' ), 'readme: retention and paths' );
	$s = $C::summary();
	$ok( isset( $s['version'], $s['legacy'], $s['chain_ok'] ) && ! array_key_exists( 'wp_user_id', $s ), 'summary for the admin panel: version and state, no user data' );
} finally {
	if ( null === $had ) { delete_option( $C::OPTION ); } else { update_option( $C::OPTION, $had, false ); }
}
$ok( get_option( 'kalicart_bridge_global_consent', false ) === $flag && ( null === $had ? false === get_option( $C::OPTION, false ) : true ), 'site left as found (real consent untouched, log restored)' );
echo "\nconsent-139: " . ( $fails ? "FAIL" : "OK" ) . " (" . ( $n - $fails ) . "/$n)\n";

