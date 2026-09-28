<?php
/**
 * 1.0.139: decision_v1 validation and Site Health tests (contract §7, §10).
 * Writes nothing: the identity and snapshot states are overlaid in memory with
 * pre_option_* filters, so the live site and WP-Cron are never touched.
 * Run: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-139-site-health.php
 */
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$H = 'KaliCart_Bridge_Site_Health';
switch_to_locale( 'en_US' ); // texts are checked on the English source; translations checked apart
$overlay = function ( string $opt, $value ) { $f = function () use ( $value ) { return $value; }; add_filter( 'pre_option_' . $opt, $f ); return $f; };
$clear   = function ( string $opt, $f ) { remove_filter( 'pre_option_' . $opt, $f ); };
$base_id = (array) get_option( 'kalicart_bridge_identity', [] );
$dec = function ( array $o = [] ) { return $o + [ 'version' => 'decision_v1', 'outcome' => 'not_served', 'transport' => 'none', 'reason_code' => 'site_unreachable', 'owner' => 'hosting', 'action' => null, 'measured_at' => '2026-09-28T10:00:00Z' ]; };

// ── 1. validate_decision ──
$ok( null === $H::validate_decision( null ) && null === $H::validate_decision( 'x' ) && null === $H::validate_decision( [ 'version' => 'decision_v2', 'reason_code' => 'ok_rest' ] ), 'non array / versione diversa: scartata' );
$ok( null === $H::validate_decision( $dec( [ 'reason_code' => 'Bad Code!' ] ) ) && null === $H::validate_decision( $dec( [ 'reason_code' => str_repeat( 'a', 49 ) ] ) ), 'reason_code fuori formato: scartata' );
$v = $H::validate_decision( $dec( [ 'action' => [ 'type' => 'push', 'url' => 'https://x' ], 'extra' => 'boh', 'message' => '<b>clicca</b>' ] ) );
$ok( null === $v['action'] && ! isset( $v['extra'] ) && ! isset( $v['message'] ), 'action forzata a null, campi sconosciuti eliminati' );
$ok( [ 'version', 'outcome', 'transport', 'reason_code', 'known', 'owner', 'action', 'measured_at' ] === array_keys( $v ), 'solo i campi del contratto' );
$v = $H::validate_decision( $dec( [ 'outcome' => 'great', 'transport' => 'ftp', 'owner' => 'giuseppe' ] ) );
$ok( 'unknown' === $v['outcome'] && 'unknown' === $v['transport'] && 'unknown' === $v['owner'], 'enum sconosciuti -> unknown' );
$v = $H::validate_decision( $dec( [ 'reason_code' => 'future_code_2027' ] ) );
$ok( false === $v['known'] && 'future_code_2027' === $v['reason_code'], 'codice sconosciuto conservato come etichetta, known=false' );
$ok( 31 === count( $H::REASONS ) && 31 === count( array_unique( $H::REASONS ) ), '31 reason_code noti, nessun doppione' );
$ok( '2026-09-28T08:00:00Z' === $H::validate_decision( $dec( [ 'measured_at' => '2026-09-28T10:00:00+02:00' ] ) )['measured_at'] && null === $H::validate_decision( $dec( [ 'measured_at' => 'ieri' ] ) )['measured_at'], 'measured_at normalizzato in UTC, invalido -> null' );

// ── 2. register ──
$f = $overlay( 'kalicart_bridge_global_consent', 0 );
$ok( [] === $H::register( [] ), 'senza consenso nessun test registrato' );
$clear( 'kalicart_bridge_global_consent', $f );
$f = $overlay( 'kalicart_bridge_global_consent', 1 );
$t = $H::register( [] );
$ok( isset( $t['direct']['kalicart_bridge_heartbeat'], $t['direct']['kalicart_bridge_snapshot'], $t['direct']['kalicart_bridge_decision'] ), 'con consenso tre test diretti' );
$clear( 'kalicart_bridge_global_consent', $f );

$all = [];
$run = function ( string $test ) use ( $H, &$all ) { $r = call_user_func( [ $H, $test ] ); $all[] = $r; return $r; };

// ── 3. heartbeat ──
$idv = $base_id; $idv['status'] = 'verified';
if ( '' === KaliCart_Bridge_Identity::summary()['unavailable'] ) {
	$idv['last_heartbeat_at'] = time() - 3600;
	$f = $overlay( 'kalicart_bridge_identity', $idv ); $r = $run( 'test_heartbeat' ); $clear( 'kalicart_bridge_identity', $f );
	$ok( 'good' === $r['status'], 'heartbeat di un\'ora fa: good' );
	$idv['last_heartbeat_at'] = time() - 5 * DAY_IN_SECONDS;
	$f = $overlay( 'kalicart_bridge_identity', $idv ); $r = $run( 'test_heartbeat' ); $clear( 'kalicart_bridge_identity', $f );
	$ok( 'recommended' === $r['status'] && false !== strpos( $r['label'], '5 days' ), 'heartbeat di 5 giorni fa: recommended, 5 days' );
	$idv['last_heartbeat_at'] = 0;
	$f = $overlay( 'kalicart_bridge_identity', $idv ); $r = $run( 'test_heartbeat' ); $clear( 'kalicart_bridge_identity', $f );
	$ok( 'recommended' === $r['status'] && false !== strpos( $r['label'], 'yet' ), 'verificato e mai inviato: recommended' );
	$idn = $base_id; $idn['status'] = 'new';
	$f = $overlay( 'kalicart_bridge_identity', $idn ); $r = $run( 'test_heartbeat' ); $clear( 'kalicart_bridge_identity', $f );
	$ok( 'good' === $r['status'], 'non ancora verificato: good (parte dopo la verifica)' );
} else {
	$ok( false, 'identita\' non disponibile su questo sito: test heartbeat non eseguibili' );
}

// ── 4. snapshot ──
if ( KaliCart_Bridge_Snapshot::eligible() ) {
	$ss = (array) get_option( 'kalicart_bridge_snapshot', [] );
	[ $now ] = KaliCart_Bridge_Identity::server_now();
	$pub = ( is_array( $ss['published'] ?? null ) ? $ss['published'] : [] ) + [ 'seq' => 1, 'manifest_url' => '', 'manifest_sha256' => '', 'catalog_hash' => '', 'count' => 1, 'location' => 'well-known' ];
	$cases = [
		'ok'              => [ 'state' => 'ok', 'published' => [ 'generated_at' => $now - 60, 'complete' => true ] + $pub ],
		'partial'         => [ 'state' => 'ok', 'published' => [ 'generated_at' => $now - 60, 'complete' => false ] + $pub ],
		'overdue'         => [ 'state' => 'ok', 'published' => [ 'generated_at' => $now - 30 * DAY_IN_SECONDS, 'complete' => true ] + $pub ],
		'storage_failure' => [ 'state' => 'storage_failure', 'published' => [ 'generated_at' => $now - 60, 'complete' => true ] + $pub ],
	];
	foreach ( $cases as $want => $st ) {
		$f = $overlay( 'kalicart_bridge_snapshot', $ss ? array_merge( $ss, $st ) : $st );
		$state = KaliCart_Bridge_Snapshot::current_state(); $r = $run( 'test_snapshot' );
		$clear( 'kalicart_bridge_snapshot', $f );
		$ok( $want === $state && ( 'ok' === $want ? 'good' : 'recommended' ) === $r['status'], "snapshot $want -> " . $r['status'] );
	}
} else {
	$ok( false, 'snapshot non eleggibile su questo sito: test snapshot non eseguibili' );
}

// ── 5. decision ──
$with = function ( $d ) use ( $overlay, $clear, $base_id, $run ) { $i = $base_id; $i['decision'] = $d; $f = $overlay( 'kalicart_bridge_identity', $i ); $r = $run( 'test_decision' ); $clear( 'kalicart_bridge_identity', $f ); return $r; };
$ok( 'good' === $with( null )['status'], 'nessuna decisione: good' );
$ok( 'good' === $with( $dec( [ 'outcome' => 'served', 'transport' => 'rest', 'reason_code' => 'ok_rest', 'owner' => 'kalicart' ] ) )['status'], 'served/ok_rest: good' );
$r = $with( $dec( [ 'reason_code' => 'future_code_2027' ] ) );
$ok( 'good' === $r['status'] && false !== strpos( $r['description'], 'future_code_2027' ), 'codice sconosciuto: good, mostrato come etichetta' );
$ok( 'good' === $with( $dec( [ 'reason_code' => 'global_import_error', 'owner' => 'kalicart' ] ) )['status'], 'problema lato KaliCart: good, nulla da chiedere al sito' );
$ok( 'good' === $with( $dec( [ 'reason_code' => 'consent_absent', 'owner' => 'merchant' ] ) )['status'], 'codice senza testo (consenso): good' );
$texted = [ 'blocked_by_bot_challenge', 'site_unreachable', 'disallowed_by_robots', 'store_coming_soon', 'site_maintenance', 'snapshot_missing', 'snapshot_expired', 'snapshot_overdue', 'snapshot_stale_cache', 'snapshot_hash_mismatch', 'snapshot_partial', 'snapshot_storage_failure', 'identity_pending', 'identity_failed', 'identity_recovery_pending' ];
$bad = [];
foreach ( $texted as $code ) {
	foreach ( [ 'merchant', 'hosting' ] as $own ) {
		$r = $with( $dec( [ 'reason_code' => $code, 'owner' => $own, 'outcome' => 'degraded' ] ) );
		if ( 'recommended' !== $r['status'] ) { $bad[] = "$code/$own"; }
	}
	if ( 'good' !== $with( $dec( [ 'reason_code' => $code, 'owner' => 'merchant', 'outcome' => 'served' ] ) )['status'] ) { $bad[] = "$code/served"; }
}
$ok( [] === $bad, count( $texted ) . ' codici con testo: recommended se merchant/hosting e non servito, good se servito ' . implode( ',', $bad ) );

// ── 6. texts: never critical, never a request ──
$ok( [] === array_filter( $all, function ( $r ) { return 'critical' === $r['status']; } ), 'nessun risultato critical (' . count( $all ) . ' risultati)' );
$ok( [] === array_filter( $all, function ( $r ) { return '' !== $r['actions']; } ), 'nessun pulsante/azione nei risultati' );
$ask = '/\b(please|you should|you must|contact|ask your|configure|change|disable|enable|whitelist|allowlist|allow[- ]?list|add a rule|update your|set up|turn off|turn on)\b/i';
$hit = [];
foreach ( $all as $r ) { $txt = $r['label'] . ' ' . wp_strip_all_tags( $r['description'] ); if ( preg_match( $ask, $txt, $m ) ) { $hit[] = $r['test'] . ':' . $m[0]; } }
$src = (string) file_get_contents( KALICART_BRIDGE_DIR . 'includes/class-site-health.php' );
preg_match_all( "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $mm );
foreach ( $mm[1] as $s ) { if ( preg_match( $ask, stripslashes( $s ), $m ) ) { $hit[] = 'src:' . $m[0] . ' in "' . substr( $s, 0, 60 ) . '"'; } }
$ok( [] === $hit && count( $mm[1] ) > 30, 'nessun testo chiede di cambiare regole o configurazione (' . count( $mm[1] ) . ' stringhe) ' . implode( ' | ', array_unique( $hit ) ) );

$ok( (array) get_option( 'kalicart_bridge_identity', [] ) === $base_id, 'stato identita\' reale intatto' );
restore_previous_locale();
echo "\nsite-health-139: " . ( $fails ? "FAIL" : "OK" ) . " (" . ( $n - $fails ) . "/$n)\n";
