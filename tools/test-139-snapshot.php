<?php
/**
 * 1.0.139 signed catalog snapshot tests.
 * Run: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-139-snapshot.php [build]
 */
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$S  = 'KaliCart_Bridge_Snapshot';
$PR = 'KaliCart_Bridge_Snapshot_Profile';
$thr2 = function ( $id ) { return 2; };

$stock_qty_paths = function ( $v, $path = '' ) use ( &$stock_qty_paths ) {
	$hits = [];
	if ( ! is_array( $v ) ) { return $hits; }
	foreach ( $v as $k => $x ) {
		$p = $path . '/' . $k;
		if ( 'quantity' === $k && preg_match( '#/stock$#', $path ) ) { $hits[] = $p; }
		if ( is_string( $x ) && 0 === strpos( $x, 'Last unit available' ) ) { $hits[] = $p; }
		if ( 'numeric_stock_quantity' === $x ) { $hits[] = $p; }
		$hits = array_merge( $hits, $stock_qty_paths( $x, $p ) );
	}
	return $hits;
};

// ── 1. projection ──
$row = [
	'id' => 99, 'name' => 'X', 'secret_meta' => 'must go', 'type' => 'variable',
	'stock' => [ 'status' => 'instock', 'in_stock' => true, 'quantity' => 1, 'quantity_tracked' => true, 'confidence' => 'numeric_stock_quantity', 'agent_note' => 'Last unit available. Race condition possible — complete checkout immediately.' ],
	'variants' => [ [ 'sku' => 'a', 'variation_id' => 501, 'stock' => [ 'quantity' => 7, 'quantity_tracked' => true, 'confidence' => 'numeric_stock_quantity' ] ] ],
	'variations' => [ [ 'variation_id' => 502, 'stock' => [ 'quantity' => 1, 'quantity_tracked' => true, 'confidence' => 'numeric_stock_quantity', 'agent_note' => 'Last unit available. Race condition possible — complete checkout immediately.' ] ] ],
	'group' => [ 'components' => [ [ 'id' => 3, 'quantity' => 2, 'min_quantity' => 1, 'max_quantity' => 4 ] ] ],
	'metadata' => [ 'stock_confidence' => 'numeric_stock_quantity', 'purchase_readiness' => [ 'status' => 'x' ] ],
];
$p1 = $S::project( $row, true, $thr2 );
$ok( ! isset( $p1['secret_meta'] ), 'field outside the allowlist removed' );
$ok( [] === $stock_qty_paths( $p1 ), 'no stock quantities or derived values' );
$ok( 2 === $p1['group']['components'][0]['quantity'] && 4 === $p1['group']['components'][0]['max_quantity'], 'bundle quantities intact' );
$ok( true === $p1['stock']['low_stock'] && false === $p1['variants'][0]['stock']['low_stock'] && true === $p1['variations'][0]['stock']['low_stock'], 'low_stock relative to the threshold' );
$ok( 'availability_status_only' === $p1['stock']['confidence'] && 'availability_status_only' === $p1['metadata']['stock_confidence'], 'reduced confidence' );
$p2 = $S::project( $row, false, $thr2 );
$ok( ! array_key_exists( 'low_stock', $p2['stock'] ) && ! array_key_exists( 'low_stock', $p2['variants'][0]['stock'] ), 'low_stock absent if the shop does not show scarcity' );
$row0 = $row; $row0['stock']['quantity'] = 0;
$ok( false === $S::project( $row0, true, $thr2 )['stock']['low_stock'], 'quantity 0 is not "last items"' );
// per-variation threshold: variation 501 has threshold 10, variation 502 threshold 0
$per = function ( $id ) { return [ 99 => 2, 501 => 10, 502 => 0 ][ $id ] ?? 2; };
$p5  = $S::project( $row, true, $per );
$ok( true === $p5['variants'][0]['stock']['low_stock'], 'variation with its own threshold above the parent (7<=10): low_stock true' );
$ok( false === $p5['variations'][0]['stock']['low_stock'], 'variation with its own threshold below the parent (1>0): low_stock false' );
add_filter( 'kalicart_snapshot_product', $f1 = function ( $inc, $r = [] ) { $r['secret_meta'] = 'x'; $r['stock']['quantity'] = 5; return $r; }, 10, 2 );
$p3 = $S::project( $row, true, $thr2 );
remove_filter( 'kalicart_snapshot_product', $f1 );
$ok( ! isset( $p3['secret_meta'] ) && [] === $stock_qty_paths( $p3 ), 'a filter does not reintroduce fields or quantities' );
add_filter( 'kalicart_snapshot_product', $f2 = function () { return false; } );
$ok( [] === $S::project( $row, true, $thr2 ), 'filter returning false excludes the product' );
remove_filter( 'kalicart_snapshot_product', $f2 );
add_filter( 'kalicart_snapshot_product', $f4 = function ( $o ) { $o['x'] = 1; return $o; } );
$ok( [] === $S::project( $row, true, $thr2 ), 'a filter that throws excludes the product' );
remove_filter( 'kalicart_snapshot_product', $f4 );

// ── 2. partition, hooks, URL validation, clock ──
$ok( 0 === $PR::bucket_of( 1023 ) && 1 === $PR::bucket_of( 1024 ) && [ 2048, 3071 ] === $PR::bucket_range( 2 ), 'id-range-v1: fixed bounds of 1024' );
$ok( false !== has_action( 'before_delete_post', [ $S, 'on_change' ] ), 'before_delete_post hook registered' );
$h = wp_parse_url( home_url(), PHP_URL_HOST );
$ok( $S::valid_public_url( "https://$h/.well-known/kalicart/" ), 'https URL on the same host is valid' );
$ok( $S::valid_public_url( "https://$h:443/x/" ), 'explicit port 443 is valid' );
$ok( ! $S::valid_public_url( "http://$h/x/" ), 'http rejected' );
$ok( ! $S::valid_public_url( "https://$h:8443/x/" ), 'port 8443 rejected' );
$ok( ! $S::valid_public_url( "https://cdn.example.com/x/" ), 'different host (CDN) rejected' );
$ok( ! $S::valid_public_url( "https://u:p@$h/x/" ), 'userinfo rejected' );
[ $srv, $known ] = KaliCart_Bridge_Identity::server_now();
$off = (int) ( get_option( 'kalicart_bridge_identity' )['clock_offset'] ?? 0 );
$ok( abs( $srv - ( time() + $off ) ) <= 1, "Global clock (offset $off s, known=" . ( $known ? 'yes' : 'no' ) . ')' );

// ── 3. real build ──
if ( in_array( 'build', $args ?? [], true ) ) {
	$run = function () use ( $S ) { for ( $i = 0; $i < 40; $i++ ) { $S::build(); $st = get_option( $S::OPTION ); if ( empty( $st['job'] ) ) { break; } } return get_option( $S::OPTION ); };
	$S::daily();                               // full walk
	$st  = $run();
	$pub = $st['published'] ?? null;
	$ok( is_array( $pub ) && 'ok' === $st['state'], 'full build (state ' . ( $st['state'] ?? '?' ) . ' ' . ( $st['last_error'] ?? '' ) . ')' );
	$dir = $pub['dir'];
	$m   = json_decode( file_get_contents( $dir . 'manifest.json' ), true );
	$id  = get_option( 'kalicart_bridge_identity' );
	$pk  = sodium_base642bin( $id['public_key'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	$ok( sodium_crypto_sign_verify_detached( sodium_base642bin( $m['sig'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING ), $PR::signing_input( $m ), $pk ), 'manifest signature' );
	$ok( 1 === $m['format_version'] && 'id-range-v1' === $m['partition']['scheme'] && 1024 === $m['partition']['width'], 'versioned format and declared partition' );
	$ok( $m['expires_at'] - $m['generated_at'] === 7 * DAY_IN_SECONDS && in_array( $m['time_source'], [ 'global', 'local' ], true ), '7-day expiry, time source ' . $m['time_source'] );
	$byb = []; $total = 0; $leaks = []; $ids = [];
	foreach ( $m['buckets'] as $bk ) {
		$hashes = [];
		foreach ( $bk['shards'] as $sh ) {
			$gz = file_get_contents( $dir . $sh['path'] );
			$okh = hash( 'sha256', $gz ) === $sh['sha256'] && strlen( $gz ) === $sh['bytes'] && preg_match( $PR::SHARD_RE, $sh['path'] );
			if ( ! $okh ) { $ok( false, 'shard ' . $sh['path'] ); }
			foreach ( array_filter( explode( "\n", gzdecode( $gz ) ) ) as $l ) {
				$o = json_decode( $l, true );
				$hashes[ $o['id'] ] = hash( 'sha256', $l );
				if ( $PR::bucket_of( $o['id'] ) !== $bk['id'] ) { $ok( false, "id {$o['id']} outside bucket {$bk['id']}" ); }
				if ( isset( $ids[ $o['id'] ] ) ) { $ok( false, "duplicate id {$o['id']}" ); }
				$ids[ $o['id'] ] = 1;
				$leaks = array_merge( $leaks, $stock_qty_paths( $o, '#' . $o['id'] ) );
			}
		}
		$ok( count( $hashes ) === $bk['count'] && $PR::bucket_hash( $hashes ) === $bk['bucket_hash'], "bucket {$bk['id']}: count and bucket_hash" );
		$byb[ $bk['id'] ] = $bk['bucket_hash'];
		$total += $bk['count'];
	}
	$ok( $total === $m['count'] && $PR::catalog_hash( $byb ) === $m['catalog_hash'], "total count ($total) and catalog_hash" );
	$ok( [] === $leaks, 'no stock quantities in the real snapshot' );
	$wk = json_decode( (string) @file_get_contents( rtrim( ABSPATH, '/' ) . '/.well-known/kalicart-bridge.json' ), true );
	$ok( ( $wk['catalog_snapshot']['seq'] ?? 0 ) === $m['seq'], 'pointer updated' );

	// Incremental: one dirty bucket, nothing changed -> same shards, same catalog_hash, seq+1.
	$names_before = array_merge( ...array_map( function ( $b ) { return array_column( $b['shards'], 'path' ); }, $m['buckets'] ) );
	$b0 = $m['buckets'][0]['id'];
	$S::mark_dirty( $b0 );
	$st2 = $run();
	$m2  = json_decode( file_get_contents( $dir . 'manifest.json' ), true );
	$names_after = array_merge( ...array_map( function ( $b ) { return array_column( $b['shards'], 'path' ); }, $m2['buckets'] ) );
	$ok( $m2['seq'] === $m['seq'] + 1 && $m2['catalog_hash'] === $m['catalog_hash'] && $names_before === $names_after, 'rebuild of the dirty bucket only: same shards, same catalog_hash, seq+1' );
	$ok( ! $S::dirty_list(), 'dirty bucket cleared' );
	// Token: a change that arrives during the job keeps the bucket dirty.
	$S::mark_dirty( $b0 );
	$s3 = get_option( $S::OPTION ); $S::build(); // job starts and (small catalog) ends in one step
	$ok( ! $S::dirty_list(), 'token: clean if nothing changed during the job' );
	echo "  manifest: {$pub['manifest_url']}  bucket: " . count( $m['buckets'] ) . "  prodotti: {$m['count']}  bytes gz: {$pub['bytes']}\n";
}
echo "\nsnapshot-139: " . ( $fails ? 'FAIL' : 'OK' ) . " (" . ( $n - $fails ) . "/$n)\n";
