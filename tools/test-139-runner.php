<?php
/**
 * 1.0.139: the snapshot build must continue itself through the REAL Action
 * Scheduler runner. Forces one batch per step
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
$ok( 1 === count( $pending() ), 'a single step queued at the start' );
$runner = ActionScheduler_QueueRunner::instance();
// Inspect right after the FIRST executed step, inside the real runner.
$steps = 0; $b = null;
add_action( 'action_scheduler_after_execute', function ( $action_id, $action ) use ( &$steps, &$b, $ok, $pending, $S ) {
	if ( $S::HOOK_BUILD !== $action->get_hook() ) { return; }
	$steps++;
	if ( 1 !== $steps ) { return; }
	$st = get_option( $S::OPTION );
	$ok( ! empty( $st['job'] ), 'after the first step the job is not finished (catalog > 1 batch)' );
	$ok( 1 === count( $pending() ), 'after the first step, inside the runner, the continuation is queued (' . count( $pending() ) . ')' );
	// A change arrives while the job runs (before step 2): its bucket must stay dirty.
	$b = KaliCart_Bridge_Snapshot_Profile::bucket_of( (int) ( $st['job']['cursor'] ?? 1 ) );
	$S::on_change( (int) ( $st['job']['cursor'] ?? 0 ) );
}, 10, 2 );
for ( $i = 0; $i < 20 && ( 0 === $steps || ! empty( get_option( $S::OPTION )['job'] ) ); $i++ ) {
	$runner->run( 'Test' );
}
$st = get_option( $S::OPTION );
$ok( empty( $st['job'] ) && (int) $st['seq'] === $seq0 + 1 && 'ok' === $st['state'], "job completed by the runner in $steps steps, seq " . $st['seq'] );
$ok( isset( $S::dirty_list()[ $b ] ), "token: bucket $b changed during the job stays dirty" );
$ok( count( $pending() ) >= 1, 'token: a new step is queued for that bucket' );
$ok( null === $lockrow(), 'lock released' );
// Lock: a second concurrent step does not run, it re-queues.
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $S::LOCK_KEY, time() . ':other' ) );
$before = get_option( $S::OPTION );
$S::build();
$ok( get_option( $S::OPTION ) == $before, 'with the lock taken, a second step does not touch the state' );
$ok( time() . ':other' === $lockrow() || strpos( (string) $lockrow(), ':other' ), 'a lock held by another process is not released by a non-owner' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $S::LOCK_KEY ) );
echo "\nsnapshot-runner-139: " . ( $fails ? 'FAIL' : 'OK' ) . " (" . ( $n - $fails ) . "/$n)\n";
