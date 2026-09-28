<?php
/**
 * Generates the shared signed fixture of CONTRATTO-1.0.139-SNAPSHOT §14.3:
 * manifest + 2 shards produced by PHP, verified by KaliCart Global (Node).
 * Deterministic: fixed seed key, fixed timestamps, gzencode (mtime 0).
 * Run: php tools/make-139-snapshot-fixture.php [outdir]
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-snapshot-profile.php';
$P   = 'KaliCart_Bridge_Snapshot_Profile';
$out = $argv[1] ?? __DIR__ . '/fixtures/snapshot-139';
if ( ! is_dir( $out ) ) { mkdir( $out, 0755, true ); }
foreach ( glob( $out . '/*' ) as $f ) { unlink( $f ); }

$b64url = static function ( $b ) { return rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' ); };
$pair = sodium_crypto_sign_seed_keypair( str_repeat( "\x07", 32 ) );
$sk   = sodium_crypto_sign_secretkey( $pair );
$x    = $b64url( sodium_crypto_sign_publickey( $pair ) );

// Tricky values on purpose: floats in every ECMAScript branch, UTF-16 key order
// (U+E000 sorts AFTER U+1F600 in UTF-16, BEFORE in UTF-8), escapes, empty containers.
$products = [
	[ 'id' => 7, 'name' => 'Caffè "Moka" / 250g', 'price' => [ 'current' => 12.5, 'regular' => 100.0, 'currency' => 'EUR' ], 'tags' => [], 'attributes' => new stdClass(), 'stock' => [ 'status' => 'instock', 'availability_status' => 'in_stock' ] ],
	[ 'id' => 12, 'name' => "Tab\tand\nnewline \u{1F600}", 'weight' => 0.00001, 'big' => 1e21, 'mid' => 1.2345678901234568e20, 'neg' => -0.5, 'k' => [ "\u{E000}" => 1, "\u{1F600}" => 2, 'a' => 3, 'B' => 4 ] ],
	[ 'id' => 3, 'name' => 'Ctrl' . chr( 1 ) . chr( 31 ), 'flag' => true, 'none' => null, 'small' => 1.5e-7, 'int' => 42 ],
	[ 'id' => 20, 'name' => "line\u{2028}sep\u{2029}par", "k\u{2028}" => 1, 'é' => 2, 'a' => 3, "\u{10437}" => 4, "\u{FFFF}" => 5, 'n' => [ 333333333.3333333, 1e30, 1e-27, 5e-324, -0.0, 1e-6, 1e-7 ] ],
];
// ids spread over two buckets (id-range-v1, width 1024) plus one big id.
$products[] = [ 'id' => 1030, 'name' => 'second bucket', 'price' => [ 'current' => 1.1 ] ];
$products[] = [ 'id' => 5000, 'name' => 'fifth bucket', 'tags' => [ 'a', 'b' ] ];
$lines = [];
foreach ( $products as $p ) { $lines[ $p['id'] ] = $P::jcs( $p ); }
ksort( $lines, SORT_NUMERIC );
$by_bucket = [];
foreach ( $lines as $id => $line ) { $by_bucket[ $P::bucket_of( $id ) ][ $id ] = $line; }
$buckets = []; $hashes = []; $bh = [];
foreach ( $by_bucket as $b => $blines ) {
	$chunks = array_chunk( $blines, 2, true ); // small sub-shards on purpose
	$shards = []; $bhash = [];
	foreach ( $chunks as $chunk ) {
		$body = '';
		foreach ( $chunk as $id => $line ) { $body .= $line . "\n"; $hashes[ $id ] = $bhash[ $id ] = $P::content_hash( $line ); }
		$gz = gzencode( $body, 9 );
		$name = $P::shard_name( $gz );
		file_put_contents( "$out/$name", $gz );
		$shards[] = [ 'path' => $name, 'sha256' => hash( 'sha256', $gz ), 'bytes' => strlen( $gz ), 'count' => count( $chunk ) ];
	}
	[ $first, $last ] = $P::bucket_range( $b );
	$bh[ $b ] = $P::bucket_hash( $bhash );
	$buckets[] = [ 'id' => $b, 'first_id' => $first, 'last_id' => $last, 'count' => count( $blines ), 'bucket_hash' => $bh[ $b ], 'shards' => $shards ];
}
$manifest = [
	'format_version' => $P::FORMAT_VERSION, 'min_reader_version' => 1, 'critical' => [ 'partition', 'buckets', 'complete', 'seq', 'expires_at' ],
	'schema' => $P::SCHEMA, 'projection' => $P::PROJECTION, 'host' => 'fixture.example',
	'installation_id' => '00000000-0000-4000-8000-000000000139', 'key_fingerprint' => $b64url( hash( 'sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}', true ) ),
	'seq' => 1, 'generated_at' => 1790553600, 'expires_at' => 1790553600 + 7 * 86400, 'time_source' => 'global', 'complete' => true,
	'count' => count( $lines ), 'catalog_hash' => $P::catalog_hash( $bh ),
	'variants_covered' => [ 'language' => 'it_IT', 'currency' => 'EUR' ],
	'partition' => [ 'scheme' => $P::PARTITION_SCHEME, 'width' => $P::PARTITION_WIDTH ], 'buckets' => $buckets,
];
$manifest['sig'] = $b64url( sodium_crypto_sign_detached( $P::signing_input( $manifest ), $sk ) );
file_put_contents( "$out/manifest.json", json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
file_put_contents( "$out/public_key_x.txt", $x . "\n" );
file_put_contents( "$out/expected.json", json_encode( [ 'lines' => $lines, 'content_hashes' => $hashes, 'catalog_hash' => $manifest['catalog_hash'], 'bucket_hashes' => $bh ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n" );
echo "fixture: $out (" . count( $buckets ) . " bucket, " . count( $lines ) . " prodotti)\n";
foreach ( $lines as $id => $l ) { echo "$id $l\n"; }
