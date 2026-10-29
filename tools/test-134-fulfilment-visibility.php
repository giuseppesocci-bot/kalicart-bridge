<?php
/**
 * KaliCart Bridge 1.0.134 — three-valued fulfilment and catalog visibility.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-134-fulfilment-visibility.php
 *
 * Creates its own fixtures and removes them. Covers the two ideas of this version:
 *  - the catalog mirrors the shop: it says what is delivered, what is downloaded
 *    and what is picked up, and it does not show what the merchant has hidden;
 *  - a product that is not shipped does not carry the shop's shipping
 *    conditions, which do not apply to it.
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'KaliCart_Bridge_Catalog_Engine' ) || ! class_exists( 'WC_Product_Variable' ) ) {
	exit( 1 );
}

$failures = [];
$created  = [];
$check = static function ( bool $cond, string $msg ) use ( &$failures ): void {
	if ( ! $cond ) { $failures[] = $msg; }
};

$simple = static function ( string $name, bool $virtual, bool $downloadable, string $visibility ) use ( &$created ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name ); $p->set_regular_price( 20 ); $p->set_status( 'publish' );
	$p->set_virtual( $virtual ); $p->set_downloadable( $downloadable );
	$p->set_catalog_visibility( $visibility );
	$id = $p->save(); $created[] = $id; return $id;
};

$shipped      = $simple( 'KC134 shipped',      false, false, 'visible' );
$ship_with_dl = $simple( 'KC134 shipped with download', false, true,  'visible' );
$download     = $simple( 'KC134 download',     true,  true,  'visible' );
$pickup       = $simple( 'KC134 pickup',       true,  false, 'visible' );
$hidden       = $simple( 'KC134 hidden',       false, false, 'hidden' );
$search_only  = $simple( 'KC134 searchonly',   false, false, 'search' );
$catalog_only = $simple( 'KC134 catalogonly',  false, false, 'catalog' );

// Variable product with ALL variations virtual and downloadable -> download.
$var = new WC_Product_Variable();
$var->set_name( 'KC134 variable download' ); $var->set_status( 'publish' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Formato' ); $attr->set_options( [ 'PDF', 'EPUB' ] );
$attr->set_visible( true ); $attr->set_variation( true );
$var->set_attributes( [ $attr ] );
$var_id = $var->save(); $created[] = $var_id;
foreach ( [ 'PDF', 'EPUB' ] as $fmt ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var_id ); $v->set_attributes( [ 'formato' => strtolower( $fmt ) ] );
	$v->set_regular_price( 9 ); $v->set_virtual( true ); $v->set_downloadable( true );
	$v->set_status( 'publish' ); $created[] = $v->save();
}
WC_Product_Variable::sync( $var_id );

// 1.0.136: product_fulfilment() was merged into product_delivery(), which also returns the raw flags.
$ful = new ReflectionMethod( 'KaliCart_Bridge_Catalog_Engine', 'product_delivery' );
$ful->setAccessible( true );
$fulfilment = static fn( int $id ): string => (string) ( $ful->invoke( null, wc_get_product( $id ) )['fulfilment'] ?? '' );

// ── 1. The three ways to obtain a product ───────────────────────────────────
$check( 'shipped'      === $fulfilment( $shipped ),      'a shippable product is shipped' );
$check( 'shipped'      === $fulfilment( $ship_with_dl ), 'downloadable BUT shippable stays shipped: downloadable does not imply not shippable' );
$check( 'downloadable' === $fulfilment( $download ),     'virtual and downloadable is downloadable' );
// 1.0.136 (CONSEGNA-VIRTUALE-v1): this assertion used to say pickup_only ("it exists, so it is
// picked up"). That was wrong: VIRTUAL and not downloadable is a licence key, a course, a service.
$check( 'virtual'      === $fulfilment( $pickup ),       'virtual and NOT downloadable is virtual, not an item to pick up' );
$check( 'downloadable' === $fulfilment( $var_id ),       'variable product with all variations downloadable is downloadable (the Woo parent would say no)' );

// ── 2. No contradiction about shipping ──────────────────────────────────────
foreach ( [ $download, $pickup ] as $id ) {
	$s = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $id ), 'detail' )['shipping'] ?? [];
	$check( false === ( $s['shipping_required'] ?? null ), "shipping_required false for {$id}" );
	foreach ( [ 'free_shipping_available', 'free_shipping_thresholds', 'free_shipping_eligible_by_product_price', 'amount_to_nearest_free_shipping_threshold' ] as $k ) {
		$check( ! array_key_exists( $k, $s ), "a non-shippable product must not carry {$k} ({$id})" );
	}
	$check( ! empty( $s['delivery_note'] ), "an explanation of how it is obtained is required ({$id})" );
}
$shipped_block = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $shipped ), 'detail' )['shipping'] ?? [];
$check( array_key_exists( 'free_shipping_available', $shipped_block ), 'the shippable product keeps its shipping conditions' );

// ── 3. The summary states how the product is obtained ───────────────────────
$sum = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $pickup ), 'summary' );
$check( 'virtual' === ( $sum['fulfilment'] ?? null ), 'fulfilment is in the summary: that is where the agent chooses' );

// ── 4. The mirror: catalog visibility ───────────────────────────────────────
$ids_of = static function ( array $args ): array {
	return array_column( KaliCart_Bridge_Catalog_Engine::query_products( $args )['products'] ?? [], 'id' );
};
// The mirror: the Bridge shows a product where the shop would show it.
// "Hidden" disappears everywhere; the other two states limit WHERE it is found, not
// whether it exists, because on WooCommerce those products are for sale.
$found = $ids_of( [ 'search' => 'KC134', 'per_page' => 50, 'fields' => 'summary' ] );
$check( in_array( $search_only, $found, true ),   '"search only" is found by searching, as on the site' );
$check( in_array( $shipped, $found, true ),       'a visible product is found by searching' );
$check( ! in_array( $catalog_only, $found, true ),'"shop only" is excluded from search, as on the site' );
$check( ! in_array( $hidden, $found, true ),      '"hidden" does not appear in search' );

$browsed = $ids_of( [ 'per_page' => 100, 'fields' => 'summary' ] );
$check( in_array( $catalog_only, $browsed, true ),   '"shop only" appears when browsing' );
$check( in_array( $shipped, $browsed, true ),        'a visible product appears normally' );
$check( ! in_array( $search_only, $browsed, true ),  '"search only" does not appear when browsing' );
$check( ! in_array( $hidden, $browsed, true ),       '"hidden" does not appear when browsing' );

// The fact must be exposed to Global, which did not receive it before this version.
$full = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $search_only ), 'detail' );
$check( 'search' === ( $full['catalog_visibility'] ?? null ), 'the payload carries catalog_visibility as it is' );

// ── Cleanup ─────────────────────────────────────────────────────────────────
foreach ( array_reverse( array_filter( $created ) ) as $id ) {
	wp_delete_post( (int) $id, true );
}
$leftover = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 's' => 'KC134' ] );
$check( empty( $leftover ), 'every fixture created by this test must be removed' );

if ( empty( $failures ) ) {
	echo 'PASS test-134-fulfilment-visibility' . "\n";
	exit( 0 );
}
echo "FAIL test-134-fulfilment-visibility\n";
foreach ( $failures as $f ) { echo ' - ' . esc_html( $f ) . "\n"; }
exit( 1 );
