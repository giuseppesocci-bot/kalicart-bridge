<?php
/**
 * 1.0.163: quarantine only for products that cannot be bought (published without a price).
 * Short title, short description and missing category stay as flags and lower the score, but no longer quarantine.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-163-quarantine-blocking.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
$cat = wp_insert_term( 'Raamatud ' . wp_rand( 1000, 9999 ), 'product_cat' );
$cat_id = is_array( $cat ) ? (int) $cat['term_id'] : 0;
$long = 'A well described product with a description that is clearly longer than forty characters.';
$make = static function ( string $name, string $desc, ?string $price, bool $with_cat ) use ( $cat_id ) {
	$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_description( $desc ); $p->set_status( 'publish' );
	if ( null !== $price ) { $p->set_regular_price( $price ); }
	if ( $with_cat ) { $p->set_category_ids( [ $cat_id ] ); }
	return $p->save();
};
$q = static function ( int $id ) {
	$p = wc_get_product( $id );
	$cats = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'all' ] );
	$cats = array_values( array_filter( (array) $cats, static fn( $t ) => 'uncategorized' !== $t->slug ) );
	return KaliCart_Bridge_Catalog_Engine::compute_quarantine_flags( $p, $cats, [ 'x' ] );
};
$codes = static fn( array $r ) => array_column( $r['flags'], 'code' );

$book   = $make( 'Positrooniline inimene. Isaac Asimov. 1999', 'Heas seisukorras. Vaata pilte.', '18', true );
$short  = $make( 'Ratafià', $long, '20', true );
$nocat  = $make( 'Bracciale tennis argento rodiato', $long, '50', false );
$noprc  = $make( 'Product without any price at all', $long, null, true );
$free   = $make( 'Free sample of our tea blend', $long, '0', true );
$good   = $make( 'Well described product with a category', $long, '12', true );

$r = $q( $book );
$ok( ! $r['in_quarantine'] && in_array( 'NO_DESCRIPTION', $codes( $r ), true ), 'short description: flag kept, not quarantined' );
$ok( $r['score'] < 100, 'short description still lowers the score (' . $r['score'] . ')' );
$r = $q( $short );
$ok( ! $r['in_quarantine'] && in_array( 'TITLE_TOO_SHORT', $codes( $r ), true ), 'one-word title: flag kept, not quarantined' );
$r = $q( $nocat );
$ok( ! $r['in_quarantine'] && in_array( 'NO_CATEGORY', $codes( $r ), true ), 'no category: flag kept, not quarantined' );
$r = $q( $noprc );
$ok( $r['in_quarantine'] && in_array( 'PRICE_MISSING', $codes( $r ), true ), 'published without a price: quarantined (cannot be bought)' );
$r = $q( $free );
$ok( ! $r['in_quarantine'], 'free product (price 0) is not quarantined' );
$r = $q( $good );
$ok( ! $r['in_quarantine'] && [] === $r['flags'], 'complete product: no flags' );

// The full catalog payload (what the Global reads at admission) carries the same decision.
$prod = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $book ), 'full' );
$ok( isset( $prod['quarantine'] ) && false === $prod['quarantine']['in_quarantine'] && in_array( 'NO_DESCRIPTION', array_column( $prod['quarantine']['flags'], 'code' ), true ), 'full catalog payload: the short-description book is not in quarantine and keeps its flag' );

// The merchant panel: quarantine = only unbuyable products, the other issues keep their own lists.
delete_transient( 'kalicart_bridge_quarantine_report' );
$report = KaliCart_Bridge_Quarantine::get_report( true );
$qids = array_column( $report['quarantined_products'], 'id' );
$ok( in_array( $noprc, $qids, true ) && ! in_array( $book, $qids, true ) && ! in_array( $short, $qids, true ) && ! in_array( $nocat, $qids, true ), 'panel quarantine list holds only the product without a price' );
$ok( in_array( $book, array_column( $report['issue_products']['NO_DESCRIPTION'] ?? [], 'id' ), true ), 'panel: short description listed under its own issue' );
$ok( in_array( $short, array_column( $report['issue_products']['TITLE_TOO_SHORT'] ?? [], 'id' ), true ), 'panel: short title listed under its own issue' );
$ok( in_array( $nocat, array_column( $report['issue_products']['NO_CATEGORY'] ?? [], 'id' ), true ), 'panel: missing category listed under its own issue' );

echo 0 === $fails ? "ALL $n PASSED\n" : "FAILED $fails/$n\n";
