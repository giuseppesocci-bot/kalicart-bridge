<?php
/**
 * 1.0.139, contract §16.4 (collaudo ChatGPT 07:41): no lost dirty marks.
 *  A. N real processes mark N distinct buckets at the same instant: all N stay.
 *  B. Same bucket from M processes at once: one marker, with a new token.
 *  C. A change (from another real process) during publish of a DIRTY job: stays dirty, build queued.
 *  D. Same during a FULL job, plus a daily request during publish: both survive.
 * Uses buckets >= 900000 (no products there) and removes them at the end.
 * Run: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-139-concurrency.php
 */
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$S = 'KaliCart_Bridge_Snapshot';
global $wpdb;
$wp   = trim( (string) shell_exec( 'command -v wp' ) );
$path = escapeshellarg( rtrim( ABSPATH, '/' ) );
$BASE = 900000;
$cleanup = function () use ( $wpdb, $S, $BASE ) {
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $S::DIRTY_PREFIX ) . '%' ) );
	foreach ( $rows as $r ) {
		if ( (int) substr( $r, strlen( $S::DIRTY_PREFIX ) ) >= $BASE ) {
			$wpdb->delete( $wpdb->options, [ 'option_name' => $r ] );
		}
	}
};
/** Starts $cmds as separate processes that all fire at the same instant; waits for all. */
$spawn = function ( array $php_list ) use ( $wp, $path ) {
	$at    = microtime( true ) + 4.0;
	$procs = []; $pipes_all = [];
	foreach ( $php_list as $php ) {
		$code    = sprintf( '$t=%F; while(microtime(true)<$t){usleep(200);} %s', $at, $php );
		$root    = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ? ' --allow-root' : '';
		$cmd     = sprintf( '%s --path=%s%s eval %s', escapeshellcmd( $wp ), $path, $root, escapeshellarg( $code ) );
		$procs[]     = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$pipes_all[] = $pipes;
	}
	$bad = 0;
	foreach ( $procs as $k => $p ) {
		$out = stream_get_contents( $pipes_all[ $k ][1] ) . stream_get_contents( $pipes_all[ $k ][2] );
		$rc  = proc_close( $p );
		if ( 0 !== $rc || '' !== trim( $out ) ) { $bad++; echo "  [figlio $k rc=$rc] " . substr( trim( $out ), 0, 300 ) . "\n"; }
	}
	return $bad;
};
$cleanup();
$ok( '' !== $wp, "wp-cli trovato ($wp)" );
// Isolation: no real build (WP Cron / AS) may consume the markers while A and B count them
// (first run 07:48: action 11178 ran via WP Cron mid-test and legitimately cleared 2).
foreach ( as_get_scheduled_actions( [ 'hook' => $S::HOOK_BUILD, 'group' => $S::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ], 'ids' ) as $aid ) { ActionScheduler::store()->cancel_action( $aid ); }
$held = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $S::LOCK_KEY, time() . ':test-isolamento' ) );
$ok( 1 === (int) $held, 'lock del build tenuto dal test durante A e B' );

// A. distinct buckets, all at once.
$N = 24;
$spawn( array_map( function ( $i ) use ( $BASE ) { return "KaliCart_Bridge_Snapshot::mark_dirty(" . ( $BASE + $i ) . ");"; }, range( 0, $N - 1 ) ) );
$d = array_filter( $S::dirty_list(), function ( $b ) use ( $BASE ) { return $b >= $BASE && $b < $BASE + 100; }, ARRAY_FILTER_USE_KEY );
$ok( count( $d ) === $N, "A: $N processi su $N bucket distinti -> " . count( $d ) . " marcatori (nessuna modifica persa)" );
$ok( count( array_unique( $d ) ) === $N, 'A: token tutti distinti' );

// B. same bucket, many processes.
$M  = 12;
$e0 = $S::mark_dirty( $BASE + 500 );
$spawn( array_fill( 0, $M, 'KaliCart_Bridge_Snapshot::mark_dirty(' . ( $BASE + 500 ) . ');' ) );
$e1 = $S::dirty_list()[ $BASE + 500 ] ?? null;
$ok( 1 === count( array_filter( array_keys( $S::dirty_list() ), function ( $b ) use ( $BASE ) { return $b === $BASE + 500; } ) ), "B: $M processi sullo stesso bucket -> un solo marcatore" );
$ok( isset( $S::dirty_list()[ $BASE + 500 ] ) && $e1 !== $e0, 'B: il marcatore porta un token nuovo (non quello di prima)' );
$cleanup();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $S::LOCK_KEY, '%:test-isolamento' ) );

// Settle: publish once so the next job is a DIRTY job.
$run = function () use ( $S ) { for ( $i = 0; $i < 60; $i++ ) { $S::build(); if ( empty( get_option( $S::OPTION )['job'] ) ) { break; } } };
$pending = function () use ( $S ) {
	return as_get_scheduled_actions( [ 'hook' => $S::HOOK_BUILD, 'group' => $S::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ], 'ids' );
};
$run();
$pub = get_option( $S::OPTION )['published'] ?? [];
$b0  = (int) ( $pub['buckets'] ? array_keys( $pub['buckets'] )[0] : 0 );

// C. dirty job; another real process marks the same bucket during publish.
foreach ( $pending() as $aid ) { ActionScheduler::store()->cancel_action( $aid ); }
$S::mark_dirty( $b0 );
$seen = $S::dirty_list()[ $b0 ] ?? 0;
$fired = 0;
$hook = function ( $job ) use ( &$fired, $spawn, $b0 ) {
	if ( $fired++ ) { return; }
	$spawn( [ "KaliCart_Bridge_Snapshot::mark_dirty($b0);" ] );
};
add_action( 'kalicart_snapshot_before_clear_dirty', $hook );
$run();
remove_action( 'kalicart_snapshot_before_clear_dirty', $hook );
$after = $S::dirty_list()[ $b0 ] ?? null;
$ok( 1 === $fired, 'C: pubblicazione di un lavoro dirty eseguita, modifica concorrente iniettata' );
$ok( null !== $after && $after !== $seen, "C: il bucket $b0 modificato durante publish resta sporco (token nuovo)" );
$ok( count( $pending() ) >= 1, 'C: un nuovo passo e\' in coda' );
$run();
$ok( ! isset( $S::dirty_list()[ $b0 ] ), 'C: il passo successivo lo ripulisce' );

// D. full job; mark + daily request during publish.
foreach ( $pending() as $aid ) { ActionScheduler::store()->cancel_action( $aid ); }
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = '1'", $S::FULL_KEY ) );
$fired = 0;
$hook = function ( $job ) use ( &$fired, $spawn, $b0, $S ) {
	if ( $fired++ || 'full' !== $job['mode'] ) { return; }
	$spawn( [ "KaliCart_Bridge_Snapshot::mark_dirty($b0);", 'KaliCart_Bridge_Snapshot::daily();' ] );
};
add_action( 'kalicart_snapshot_before_clear_dirty', $hook );
$run();
remove_action( 'kalicart_snapshot_before_clear_dirty', $hook );
$full_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $S::FULL_KEY ) );
$ok( 1 === $fired, 'D: pubblicazione di un lavoro full eseguita, modifiche concorrenti iniettate' );
$ok( isset( $S::dirty_list()[ $b0 ] ), "D: il bucket $b0 modificato durante la pubblicazione full resta sporco" );
$ok( null !== $full_row && '1' !== $full_row, 'D: la nuova richiesta giornaliera sopravvive (la vecchia e\' stata consumata)' );
$run();
$ok( ! $S::dirty_list() && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $S::FULL_KEY ) ), 'D: il lavoro successivo consuma tutto' );
$st = get_option( $S::OPTION );
$ok( 'ok' === ( $st['state'] ?? '' ) && ! isset( $st['dirty'] ) && ! isset( $st['full_requested'] ), 'stato finale ok, nessuna chiave dirty nell\'opzione principale' );
foreach ( $pending() as $aid ) { ActionScheduler::store()->cancel_action( $aid ); }
$cleanup();
echo "\nsnapshot-concurrency-139: " . ( $fails ? 'FAIL' : 'OK' ) . " (" . ( $n - $fails ) . "/$n)\n";

