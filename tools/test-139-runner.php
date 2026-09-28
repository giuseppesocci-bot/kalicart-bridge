<?php
/**
 * 1.0.139: the snapshot build must continue itself through the REAL Action
 * Scheduler runner (collaudo ChatGPT 07:05). Forces one batch per step
 * (kalicart_snapshot_run_seconds = 0), then runs the queue in-process.
 * Run: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-139-runner.php
 */
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$S = 'KaliCart_Bridge_Snapshot';
$pending = function () use ( $S ) {
	return as_get_scheduled_actions( [ 'hook' => $S::HOOK_BUILD, 'group' => $S::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ], 'ids' );
};
foreach ( $pending() as $aid ) { ActionScheduler::store()->cancel_action( $aid ); }
add_filter( 'kalicart_snapshot_run_seconds', function () { return 0; } );
$seq0 = (int) ( get_option( $S::OPTION )['seq'] ?? 0 );
update_option( $S::OPTION, array_merge( get_option( $S::OPTION, [] ), [ 'job' => null ] ), false );
global $wpdb;
$lockrow = function () use ( $wpdb, $S ) { return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $S::LOCK_KEY ) ); };
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = '1'", $S::FULL_KEY ) );
$S::schedule_build( 0 );
$ok( 1 === count( $pending() ), 'un solo passo in coda all\'inizio' );
$runner = ActionScheduler_QueueRunner::instance();
// Inspect right after the FIRST executed step, inside the real runner.
$steps = 0; $b = null;
add_action( 'action_scheduler_after_execute', function ( $action_id, $action ) use ( &$steps, &$b, $ok, $pending, $S ) {
	if ( $S::HOOK_BUILD !== $action->get_hook() ) { return; }
	$steps++;
	if ( 1 !== $steps ) { return; }
	$st = get_option( $S::OPTION );
	$ok( ! empty( $st['job'] ), 'dopo il primo passo il lavoro non e\' finito (catalogo > 1 lotto)' );
	$ok( 1 === count( $pending() ), 'dopo il primo passo, dentro il runner, la continuazione e\' in coda (' . count( $pending() ) . ')' );
	// A change arrives while the job runs (before step 2): its bucket must stay dirty.
	$b = KaliCart_Bridge_Snapshot_Profile::bucket_of( (int) ( $st['job']['cursor'] ?? 1 ) );
	$S::on_change( (int) ( $st['job']['cursor'] ?? 0 ) );
}, 10, 2 );
for ( $i = 0; $i < 20 && ( 0 === $steps || ! empty( get_option( $S::OPTION )['job'] ) ); $i++ ) {
	$runner->run( 'Test' );
}
$st = get_option( $S::OPTION );
$ok( empty( $st['job'] ) && (int) $st['seq'] === $seq0 + 1 && 'ok' === $st['state'], "lavoro completato dal runner in $steps passi, seq " . $st['seq'] );
$ok( isset( $S::dirty_list()[ $b ] ), "token: il bucket $b modificato durante il lavoro resta sporco" );
$ok( count( $pending() ) >= 1, 'token: un nuovo passo e\' in coda per quel bucket' );
$ok( null === $lockrow(), 'lock rilasciato' );
// Lock: a second concurrent step does not run, it re-queues.
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $S::LOCK_KEY, time() . ':altro' ) );
$before = get_option( $S::OPTION );
$S::build();
$ok( get_option( $S::OPTION ) == $before, 'con il lock preso un secondo passo non tocca lo stato' );
$ok( time() . ':altro' === $lockrow() || strpos( (string) $lockrow(), ':altro' ), 'il lock di un altro processo non viene rilasciato da chi non lo possiede' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $S::LOCK_KEY ) );
echo "\nsnapshot-runner-139: " . ( $fails ? 'FAIL' : 'OK' ) . " (" . ( $n - $fails ) . "/$n)\n";
