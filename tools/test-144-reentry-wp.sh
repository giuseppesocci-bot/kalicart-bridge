#!/usr/bin/env bash
# 1.0.144 behavioural re-entry test on a THROWAWAY WordPress install (never a real store).
# Usage: tools/test-144-reentry-wp.sh /path/to/test-wordpress   (plugin already installed there)
# A must-use plugin simulates KaliCart Global (no network) and records every POST.
set -u
W="$1"; WP="wp --path=$W --allow-root"; MU="$W/wp-content/mu-plugins/kc-sim-global.php"; LOG="$W/wp-content/kc-net.log"; FAIL="$W/wp-content/kc-hb-fail"
mkdir -p "$W/wp-content/mu-plugins"
cat > "$MU" <<'PHP'
<?php
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	file_put_contents( WP_CONTENT_DIR . '/kc-net.log', basename( $path ) . "\n", FILE_APPEND );
	$ok = fn( $b ) => [ 'headers' => [], 'body' => wp_json_encode( $b ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
	if ( str_ends_with( $path, '/register' ) ) return $ok( [ 'nonce' => 'n123' ] );
	if ( str_ends_with( $path, '/prove' ) ) return $ok( [ 'outcome' => 'verified', 'proof_level' => 'test' ] );
	if ( str_ends_with( $path, '/heartbeat' ) ) {
		if ( file_exists( WP_CONTENT_DIR . '/kc-hb-fail' ) ) return [ 'headers' => [], 'body' => '{"error":"down"}', 'response' => [ 'code' => 503, 'message' => 'x' ], 'cookies' => [] ];
		return $ok( [ 'ok' => true ] );
	}
	return new WP_Error( 'blocked', 'test' );
}, 10, 3 );
PHP
fails=0; check(){ if [ "$1" = "$2" ]; then echo "PASS $3"; else echo "FAIL $3 (got '$1', want '$2')"; fails=$((fails+1)); fi; }
flag(){ $WP option get kalicart_bridge_identity_reentry 2>/dev/null || echo none; }
$WP option update kalicart_bridge_global_consent 1 >/dev/null; $WP option delete kalicart_bridge_identity >/dev/null 2>&1; rm -f "$FAIL"
$WP plugin deactivate kalicart-bridge >/dev/null 2>&1; rm -f "$LOG"; $WP plugin activate kalicart-bridge >/dev/null 2>&1
check "$( [ -f "$LOG" ] && wc -l < "$LOG" || echo 0 )" 0 "activation sends nothing"
$WP cron event run kalicart_bridge_identity_reentry >/dev/null 2>&1
check "$(tr '\n' ' ' < "$LOG")" "register prove heartbeat " "fresh identity: register, prove, ONE heartbeat"
check "$(flag)" none "flag cleared after the delivered heartbeat"
rm -f "$LOG"; $WP eval 'KaliCart_Bridge_Identity::tick();' >/dev/null 2>&1
check "$( [ -f "$LOG" ] && wc -l < "$LOG" || echo 0 )" 0 "no second heartbeat on the next tick"
touch "$FAIL"; $WP plugin deactivate kalicart-bridge >/dev/null 2>&1; $WP plugin activate kalicart-bridge >/dev/null 2>&1; rm -f "$LOG"
for i in 1 2 3 4 5 6 7; do $WP cron event run kalicart_bridge_identity_reentry >/dev/null 2>&1; done
check "$(wc -l < "$LOG")" 6 "failing heartbeat retried at most 6 times"
check "$(flag)" none "after the cap the flag is dropped (daily tick takes over)"
check "$($WP cron event list --fields=hook 2>/dev/null | grep -c identity_reentry)" 0 "no re-entry event left"
rm -f "$FAIL" "$LOG" "$MU"; $WP option update kalicart_bridge_global_consent 0 >/dev/null
echo; [ $fails -eq 0 ] && echo "reentry-144-wp: OK" || echo "reentry-144-wp: FAIL ($fails)"; exit $fails
