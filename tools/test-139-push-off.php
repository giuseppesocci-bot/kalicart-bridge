<?php
/**
 * 1.0.139: push banner/badge/Site Health present but off (contract §9, BOZZA §9 properties).
 * Writes nothing: consent is overlaid in memory with pre_option_*.
 * Run: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-139-push-off.php
 */
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$P = 'KaliCart_Bridge_Push_Notice'; $H = 'KaliCart_Bridge_Site_Health';
switch_to_locale( 'en_US' ); // texts are checked on the English source; translations checked apart
$consent = function ( $v ) { $f = function () use ( $v ) { return $v; }; add_filter( 'pre_option_kalicart_bridge_global_consent', $f ); return $f; };
$unset   = function ( $f ) { remove_filter( 'pre_option_kalicart_bridge_global_consent', $f ); };
$d = function ( array $o = [] ) use ( $H ) { $raw = $o + [ 'version' => 'decision_v1', 'outcome' => 'degraded', 'transport' => 'none', 'reason_code' => 'blocked_by_bot_challenge', 'owner' => 'hosting', 'measured_at' => '2026-09-28T10:00:00Z' ]; $v = $H::validate_decision( $raw ); if ( $v && array_key_exists( 'action', $o ) ) { $v['action'] = $o['action']; } return $v; };
$act = [ 'action' => [ 'type' => 'push_offer' ] ];

// ── 1. off in this build ──
$ok( false === $P::PUSH_AVAILABLE, 'PUSH_AVAILABLE e\' false (costante, non filtro ne\' opzione)' );
$ok( false === has_action( 'admin_notices', [ $P, 'render' ] ), 'nessun banner agganciato ad admin_notices' );
$src = (string) file_get_contents( KALICART_BRIDGE_DIR . 'includes/class-push-notice.php' );
$ok( false === strpos( $src, 'apply_filters' ) && false === strpos( $src, "get_option( 'kalicart_bridge_push" ) && false === strpos( $src, 'wp_ajax' ), 'nessun filtro, opzione o handler AJAX per accenderlo o concederlo' );
$f = $consent( 1 );
$ok( false === $P::should_show( $d( $act ) ) && 0 === $P::badge_count( $d( $act ) ) && null === $P::site_health( $d( $act ) ), 'push_available=false: mai banner, badge o Site Health, anche con action e consenso' );
$ok( false === $P::should_show( $d() , true ), 'decisione reale 1.0.139 (action forzata a null): mai banner anche con lo switch acceso' );
ob_start(); $P::render(); $out = ob_get_clean();
$ok( '' === $out, 'render() non stampa nulla' );

// ── 2. rules with the switch on (test only) ──
$ok( true === $P::should_show( $d( $act ), true ) && 1 === $P::badge_count( $d( $act ), true ), 'switch acceso + action + reason noto + hosting: banner e badge 1' );
$ok( false === $P::should_show( $d( $act + [ 'reason_code' => 'future_code_2027' ] ), true ), 'reason sconosciuto: mai banner' );
$ok( false === $P::should_show( $d( $act + [ 'owner' => 'kalicart', 'reason_code' => 'global_import_error' ] ), true ) && false === $P::should_show( $d( $act + [ 'owner' => 'kalicart' ] ), true ), 'errore di proprieta\' KaliCart: mai banner' );
$ok( false === $P::should_show( $d( $act + [ 'outcome' => 'served' ] ), true ), 'negozio servito: mai banner (misura positiva -> ritiro)' );
$ok( false === $P::should_show( $d( $act + [ 'owner' => 'giuseppe' ] ), true ), 'owner sconosciuto: mai banner' );
$ok( false === $P::should_show( $d( $act + [ 'reason_code' => 'disallowed_by_robots', 'owner' => 'merchant' ] ), true ) && false === $P::should_show( $d( $act + [ 'reason_code' => 'store_coming_soon', 'owner' => 'merchant' ] ), true ), 'robots / coming soon: il push non li risolve, mai banner' );
$ok( false === $P::should_show( $d( $act ), true, true ), '"Non ora" / X: nessun banner' );
$ok( $P::should_show( $d( $act ), true ) === $P::should_show( $d( $act ), true ), 'stesso input -> stesso esito' );
$sh = $P::site_health( $d( $act ), true );
$ok( is_array( $sh ) && 'recommended' === $sh['status'], 'Site Health del push: recommended, mai critical' );
$unset( $f );
$f = $consent( 0 );
$ok( false === $P::should_show( $d( $act ), true ), 'consenso A revocato: mai banner' );
$unset( $f );

// ── 3. texts ──
$u = $P::texts( 'unreadable' ); $s = $P::texts( 'stale' );
$ok( null === $P::texts( 'boh' ) && 3 === count( $u['visible'] ) && 5 === count( $u['expand'] ), 'tre frasi visibili + espansione di cinque' );
$ok( $u['visible'][0] !== $s['visible'][0] && $u['visible'][2] === $s['visible'][2] && $u['expand'] === $s['expand'] && $u['allow'] === $s['allow'] && $u['not_now'] === $s['not_now'], 'la variante sceglie le prime frasi; domanda, espansione e pulsanti identici' );
$ok( 1 === substr_count( $s['visible'][0] . ' ' . $s['visible'][1], 'Federated Catalog' ) && 1 === substr_count( $u['visible'][0] . ' ' . $u['visible'][1], 'Federated Catalog' ), 'variante non aggiornato: "Federated Catalog" una volta sola' );
$ok( 'Allow KaliCart Bridge to send the catalog?' === $u['visible'][2] && 'Allow sending' === $u['allow'] && 'Not now' === $u['not_now'], 'stesso verbo nella domanda e nel pulsante' );
$ok( false !== strpos( implode( ' ', $u['expand'] ), 'dashboard.kalicart.com' ) && false !== strpos( implode( ' ', $u['expand'] ), 'No customer or order data is sent.' ), 'espansione: destinatario col dominio, nessun dato clienti/ordini' );
$ok( false === stripos( implode( ' ', $u['visible'] ), 'customer' ) && false === stripos( implode( ' ', $u['visible'] ), 'revoke' ), 'garanzie e revoca solo nell\'espansione' );
$ok( 64 === strlen( $P::text_hash( $u ) ) && $P::text_hash( $u ) !== $P::text_hash( $s ), 'hash del testo mostrato, diverso per variante' );
$map = array_count_values( $P::VARIANTS );
$ok( 3 === ( $map['unreadable'] ?? 0 ) && 6 === ( $map['stale'] ?? 0 ) && [] === array_diff( array_keys( $P::VARIANTS ), $H::REASONS ), 'varianti: solo reason_code noti del contratto' );
$ask = '/\b(please|you should|you must|contact|ask your|configure|disable|enable|whitelist|allowlist|add a rule|update your|set up|turn off|turn on)\b/i';
$ok( ! preg_match( $ask, implode( ' ', array_merge( $u['visible'], $u['expand'], $s['visible'] ) ) ), 'nessuna richiesta di cambiare regole o configurazione' );

restore_previous_locale();
echo "\npush-off-139: " . ( $fails ? "FAIL" : "OK" ) . " (" . ( $n - $fails ) . "/$n)\n";
