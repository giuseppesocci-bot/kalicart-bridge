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
	$ok( $C::version() === ( $flag ? $C::LEGACY_ID : '' ), 'consenso attivo senza ricevuta = legacy (calcolato, nessuna ricevuta scritta): ' . $C::version() );
	$ok( 0 === count( $C::records() ), 'lo stato legacy non fabbrica ricevute' );

	$g = $C::record( 'granted', 1 );
	$ok( 'federated-catalog-1.1' === $g['text_id'] && 'granted' === $g['action'] && null === $g['previous_record_hash'], 'attivazione registrata: text_id, azione, primo della catena' );
	$ok( hash( 'sha256', $C::CANONICAL_TEXT ) === $g['canonical_text_hash'] && hash( 'sha256', $C::localized_text() ) === $g['consent_text_hash'], 'hash del testo canonico e del testo mostrato' );
	$ok( preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $g['occurred_at_utc'] ) && 1 === $g['wp_user_id'] && KALICART_BRIDGE_VERSION === $g['plugin_version'], 'ora UTC, utente, versione del plugin' );
	if ( $flag ) { $ok( 'federated-catalog-1.1' === $C::version(), 'con la ricevuta la versione in vigore e\' 1.1' ); }
	$again = $C::record( 'granted', 1 );
	$ok( 1 === count( $C::records() ) && $again['record_hash'] === $g['record_hash'], 'seconda attivazione identica: idempotente, nessun record in piu\'' );
	$r = $C::record( 'revoked', 1 );
	$ok( 2 === count( $C::records() ) && $r['previous_record_hash'] === $g['record_hash'], 'revoca registrata e legata alla precedente (catena)' );
	$C::record( 'revoked', 1 );
	$ok( 2 === count( $C::records() ), 'seconda revoca: idempotente' );
	$C::record( 'granted', 2 );
	$ok( 3 === count( $C::records() ) && $C::verify_chain(), 'nuova attivazione: catena di 3 record verificata' );
	$log = get_option( $C::OPTION );
	$log[1]['wp_user_id'] = 99;
	update_option( $C::OPTION, $log, false );
	$ok( false === $C::verify_chain(), 'record alterato: la catena non verifica piu\'' );
	$log = get_option( $C::OPTION );
	$log[1]['wp_user_id'] = 1;
	array_splice( $log, 1, 1 );
	update_option( $C::OPTION, $log, false );
	$ok( false === $C::verify_chain(), 'record tolto dal mezzo: la catena non verifica piu\'' );
	delete_option( $C::OPTION );
	$C::record( 'granted', 1 ); $C::record( 'revoked', 1 ); $C::record( 'granted', 1 );
	$log = get_option( $C::OPTION ); array_shift( $log ); update_option( $C::OPTION, $log, false );
	$ok( 2 === count( $C::records() ) && null !== $C::records()[0]['previous_record_hash'] && false === $C::verify_chain(), 'primo record tolto (prefisso troncato): la catena non verifica piu\'' );
	$threw = false;
	try { $C::record( 'boh', 1 ); } catch ( InvalidArgumentException $e ) { $threw = true; }
	$ok( $threw, 'azione sconosciuta rifiutata' );
	delete_option( $C::OPTION );
	for ( $i = 0; $i < 150; $i++ ) { $C::record( 0 === $i % 2 ? 'granted' : 'revoked', 1 ); }
	$ok( 150 === count( $C::records() ) && $C::verify_chain() && null === $C::records()[0]['previous_record_hash'], 'registro append-only: 150 cambi di stato, catena completa dal primo record (nessun troncamento)' );
	$ok( ! defined( $C . '::MAX_RECORDS' ), 'nessun tetto che espella ricevute' );
	$un = (string) file_get_contents( KALICART_BRIDGE_DIR . 'uninstall.php' );
	$ok( false !== strpos( $un, "'" . $C::OPTION . "'" ), 'uninstall elimina il registro delle ricevute (come il ledger commerce)' );
	$ok( false !== strpos( $C::CANONICAL_TEXT, 'random identifier of this installation' ) && false !== strpos( $C::CANONICAL_TEXT, 'consent text version' ) && false !== strpos( $C::CANONICAL_TEXT, 'never error messages, file paths or stack traces' ), 'testo A: il segnale giornaliero elenca identificatore, versione consenso, diagnostica' );
	$pp = implode( ' ', [ $C::localized_text() ] );
	$src = (string) file_get_contents( KALICART_BRIDGE_DIR . 'includes/class-federation-consent.php' );
	$pol = substr( $src, strpos( $src, 'function privacy_policy_content' ), 6000 );
	$ok( false === strpos( $pol, '24 months' ) && false === strpos( $pol, 'proof of consent' ), 'nessuna "prova del consenso" presso il Global: la ricevuta A non viene inviata' );
	$need = [ '/.well-known/kalicart/', 'wp-content/uploads/kalicart/', 'never exact stock quantities', 'once a day', 'revoked or the plugin is deactivated or deleted', '90 days', '30 days', 'anonymous daily totals', 'minimal revocation record', 'signing keys', 'is not sent to KaliCart Global', 'No data about customers, visitors, orders or payments' ];
	$miss = array_values( array_filter( $need, function ( $w ) use ( $pol ) { return false === strpos( $pol, $w ); } ) );
	$ok( [] === $miss, 'testo Privacy di WordPress (contratto §11): percorsi, niente quantita\', frequenza, cancellazione, conservazione ' . implode( ',', $miss ) );
	$rd = (string) file_get_contents( KALICART_BRIDGE_DIR . 'readme.txt' );
	$ok( false !== strpos( $rd, '**Retention:**' ) && false === strpos( $rd, '24 months' ) && false !== strpos( $rd, 'is not sent to KaliCart Global' ) && false !== strpos( $rd, '/.well-known/kalicart/' ), 'readme: conservazione e percorsi' );
	$s = $C::summary();
	$ok( isset( $s['version'], $s['legacy'], $s['chain_ok'] ) && ! array_key_exists( 'wp_user_id', $s ), 'summary per il pannello: versione e stato, nessun dato dell\'utente' );
} finally {
	if ( null === $had ) { delete_option( $C::OPTION ); } else { update_option( $C::OPTION, $had, false ); }
}
$ok( get_option( 'kalicart_bridge_global_consent', false ) === $flag && ( null === $had ? false === get_option( $C::OPTION, false ) : true ), 'sito lasciato com\'era (consenso reale intatto, registro ripristinato)' );
echo "\nconsent-139: " . ( $fails ? "FAIL" : "OK" ) . " (" . ( $n - $fails ) . "/$n)\n";

