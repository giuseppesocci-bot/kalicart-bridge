<?php
/** 1.0.145: CATEGORY-ORDER-v1, deterministic order for same-name categories. php tools/test-145-category-order.php */
$root = dirname( __DIR__ ); $fails = 0; $tests = 0;
$ok = static function ( bool $c, string $m ) use ( &$fails, &$tests ): void { $tests++; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } };

define( 'ABSPATH', '/tmp/' );
class WP_Term { public $term_id; public $name; public $slug; public $parent; public function __construct( $id, $name, $slug, $parent = 0 ) { $this->term_id = $id; $this->name = $name; $this->slug = $slug; $this->parent = $parent; } }
$GLOBALS['T'] = [];
function kt( $id, $name, $slug, $parent = 0 ) { return $GLOBALS['T'][ $id ] = new WP_Term( $id, $name, $slug, $parent ); }
function get_term( $id, $tax ) { return $GLOBALS['T'][ $id ] ?? null; }
function is_wp_error( $x ) { return false; }
function get_ancestors( $id, $tax, $type ) { $out = []; $t = $GLOBALS['T'][ $id ] ?? null; while ( $t && $t->parent ) { $out[] = $t->parent; $t = $GLOBALS['T'][ $t->parent ] ?? null; } return $out; }
require $root . '/includes/class-catalog-engine.php';
$E = 'KaliCart_Bridge_Catalog_Engine';
$ids  = static fn( array $a ) => implode( ',', array_map( static fn( $t ) => $t->term_id, $a ) );
$perms = static function ( array $a ) use ( &$perms ) { if ( count( $a ) < 2 ) return [ $a ]; $r = []; foreach ( $a as $i => $x ) { $rest = $a; unset( $rest[ $i ] ); foreach ( $perms( array_values( $rest ) ) as $p ) { $r[] = array_merge( [ $x ], $p ); } } return $r; };

$women = kt( 1, 'Women', 'women' ); $men = kt( 2, 'Men', 'men' ); $kids = kt( 3, 'Kids', 'kids' );
$saleW = kt( 10, 'Sale', 'sale-women', 1 ); $saleM = kt( 11, 'Sale', 'sale-men', 2 ); $saleK = kt( 12, 'Sale', 'sale-kids', 3 );
$acc = kt( 20, 'Accessories', 'accessories' ); $shoes = kt( 21, 'Shoes', 'shoes' );
$saleTop = kt( 13, 'Sale', 'sale' );                   // root "Sale": path "Sale"
$saleW2  = kt( 14, 'Sale', 'sale-women-2', 1 );        // same path as 10: falls back to term_id

// 1. no ties: output identical to input (byte-for-byte the 1.0.144 order)
$in = [ $acc, $men, $shoes, $women ];
$ok( $ids( $E::stable_category_terms( $in ) ) === $ids( $in ), 'no same-name terms: order untouched' );
$odd = [ $shoes, $acc ];   // even an input that is not name-sorted is left as is
$ok( $ids( $E::stable_category_terms( $odd ) ) === '21,20', 'non-tied positions are never reordered' );

// 2. ties: every input permutation yields the same order (path, then term_id)
$set = [ $saleW, $saleM, $saleK, $saleTop ];
$seen = []; foreach ( $perms( $set ) as $p ) { $seen[ $ids( $E::stable_category_terms( $p ) ) ] = true; }
$ok( 1 === count( $seen ), 'all 24 permutations of 4 "Sale" terms give one order' );
$ok( array_keys( $seen )[0] === '12,11,13,10', 'order by path: Kids > Sale, Men > Sale, Sale, Women > Sale' );

$seen = []; foreach ( $perms( [ $saleW2, $saleW ] ) as $p ) { $seen[ $ids( $E::stable_category_terms( $p ) ) ] = true; }
$ok( [ '10,14' ] === array_keys( $seen ), 'identical name and path: term_id breaks the tie' );

// 3. ties inside a mixed list keep surrounding positions
$mixed = [ $acc, $saleW, $saleM, $shoes ];
$ok( $ids( $E::stable_category_terms( $mixed ) ) === '20,11,10,21', 'tie run sorted in place, neighbours fixed' );

// 4. collation-equal names (case) are treated as one tie group, then exact name decides
$lowSale = kt( 15, 'sale', 'sale-lower' );
$a = $ids( $E::stable_category_terms( [ $lowSale, $saleTop ] ) ); $b = $ids( $E::stable_category_terms( [ $saleTop, $lowSale ] ) );
$ok( $a === $b && $a === '13,15', 'case-variant names: deterministic ("Sale" before "sale")' );

// 5. robustness
$ok( [] === $E::stable_category_terms( [] ), 'empty input' );
$ok( '20' === $ids( $E::stable_category_terms( [ $acc ] ) ), 'single term' );
$ok( '20,21' === $ids( $E::stable_category_terms( [ 'x', null, $acc, $shoes ] ) ), 'non-term entries dropped' );
$ok( '20' === $ids( $E::stable_category_terms( [ 7 => $acc ] ) ), 'non-zero keys reindexed' );

// 6. wiring: both product_cat read paths go through the helper; data version unchanged
$src  = (string) file_get_contents( $root . '/includes/class-catalog-engine.php' );
$main = (string) file_get_contents( $root . '/kalicart-bridge.php' );
$ok( false !== strpos( $src, "foreach ( self::stable_category_terms( \$terms ) as \$term ) {" ), 'get_product_categories uses stable order' );
$ok( false !== strpos( $src, "wp_list_pluck( self::stable_category_terms( \$cat_terms ), 'slug' )" ), 'gender-filtered summary slugs use stable order' );
$ok( 2 === substr_count( $src, "get_the_terms( \$p->get_id(), 'product_cat' )" ), 'no other direct product_cat reads in the engine' );
$ok( (bool) preg_match( "/define\\( 'KALICART_BRIDGE_CATALOG_DATA_VERSION', (\\d+) \\);/", $main, $m ) && 2 <= (int) $m[1], 'catalog_data_version is at least 2 (category order contract)' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$r0 = strpos( $readme, '= 1.0.145 =' ); $r1 = strpos( $readme, '= 1.0.144 =' );
$ok( false !== $r0 && false !== $r1 && false !== strpos( substr( $readme, $r0, $r1 - $r0 ), 'from 1 to 2' ), 'readme 1.0.145 declares the data version change' );
$ok( false === strpos( $src, 'catalog_data_version stays 1' ), 'no stale "stays 1" claim in the engine' );

echo "\ncategory-order-145: " . ( $fails ? 'FAIL' : 'OK' ) . " ($tests tests)\n"; exit( $fails ? 1 : 0 );
