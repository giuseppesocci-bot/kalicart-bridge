<?php
/**
 * KaliCart Bridge 1.0.136 — CONSEGNA-VIRTUALE-v1 e SOURCE-DELIVERY-v1.
 *
 * Nasce dal lavoro di Codex del 2026-09-22 (tools/test-136-distribution-format.php
 * nel suo clone), ripreso senza `distribution_format`: il Bridge espone i due
 * flag di WooCommerce e corregge `fulfilment`, la combinazione la fa chi legge.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-136-delivery-flags.php
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$created  = [];
$check = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$simple = static function ( string $name, bool $virtual, bool $downloadable ) use ( &$created ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name ); $p->set_regular_price( 20 ); $p->set_status( 'publish' );
	$p->set_virtual( $virtual ); $p->set_downloadable( $downloadable );
	$id = $p->save(); $created[] = $id; return $id;
};

$physical = $simple( 'KC136 physical',               false, false );
$hybrid   = $simple( 'KC136 physical with download', false, true );
$digital  = $simple( 'KC136 digital download',       true,  true );
$key      = $simple( 'KC136 steam key',              true,  false );

//                  shipping_required virtual downloadable fulfilment
$expected = [
	$physical => [ true,  false, false, 'shipped' ],
	$hybrid   => [ true,  false, true,  'shipped' ],
	$digital  => [ false, true,  true,  'downloadable' ],
	$key      => [ false, true,  false, 'virtual' ],
];
foreach ( $expected as $id => [ $ships, $virtual, $downloadable, $fulfilment ] ) {
	$sum = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $id ), 'summary' );
	$det = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $id ), 'detail' )['shipping'] ?? [];
	$check( $ships        === ( $sum['shipping_required'] ?? null ), "summary shipping_required {$id}" );
	$check( $virtual      === ( $sum['virtual'] ?? null ),           "summary virtual {$id}" );
	$check( $downloadable === ( $sum['downloadable'] ?? null ),      "summary downloadable {$id}" );
	$check( $fulfilment   === ( $sum['fulfilment'] ?? null ),        "summary fulfilment {$id}" );
	$check( $virtual      === ( $det['virtual'] ?? null ),           "detail virtual {$id}" );
	$check( $downloadable === ( $det['downloadable'] ?? null ),      "detail downloadable {$id}" );
	$check( $fulfilment   === ( $det['fulfilment'] ?? null ),        "detail fulfilment {$id}" );
	$check( ! array_key_exists( 'distribution_format', $sum ),       "niente distribution_format: il Bridge espone, non interpreta ({$id})" );
}

// Una chiave non si ritira: nessun metodo di ritiro nominato, anche se il negozio ne ha.
$kdet = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $key ), 'detail' )['shipping'] ?? [];
$check( false === ( $kdet['local_pickup_available'] ?? null ), 'un virtuale non ha ritiro in sede' );
// `??` non va usato qui: su una chiave che vale null restituirebbe il default.
$check( array_key_exists( 'local_pickup', $kdet ) && null === $kdet['local_pickup'], 'un virtuale non elenca metodi di ritiro' );
$check( false !== stripos( (string) ( $kdet['delivery_note'] ?? '' ), 'virtual' ), 'la nota dice che e\' virtuale' );

// I flag sono quelli del merchant, NON dedotti da needs_shipping(): un filtro li separa.
$no_ship = static fn( $needs, $product ) => ( $product && (int) $product->get_id() === (int) $GLOBALS['kc136_filtered'] ) ? false : $needs;
$GLOBALS['kc136_filtered'] = $physical;
add_filter( 'woocommerce_product_needs_shipping', $no_ship, 10, 2 );
$f = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $physical ), 'summary' );
remove_filter( 'woocommerce_product_needs_shipping', $no_ship, 10 );
$check( false === ( $f['shipping_required'] ?? null ), 'filtro: WooCommerce dice che non si spedisce' );
$check( false === ( $f['virtual'] ?? null ), 'filtro: il prodotto resta NON virtuale per il merchant' );
$check( 'pickup_only' === ( $f['fulfilment'] ?? null ), 'filtro: oggetto che esiste e non si spedisce -> pickup_only, l\'unico caso vero' );

// Variabile misto: una variante su disco, una chiave. Si spedisce (una si spedisce),
// non e' virtuale ne' scaricabile nel suo insieme.
$make_var = static function ( string $name, array $opts ) use ( &$created ): int {
	$v = new WC_Product_Variable(); $v->set_name( $name ); $v->set_status( 'publish' );
	$a = new WC_Product_Attribute(); $a->set_name( 'Formato' ); $a->set_options( array_keys( $opts ) );
	$a->set_visible( true ); $a->set_variation( true ); $v->set_attributes( [ $a ] );
	$vid = $v->save(); $created[] = $vid;
	foreach ( $opts as $opt => [ $virt, $dl ] ) {
		$x = new WC_Product_Variation(); $x->set_parent_id( $vid );
		$x->set_attributes( [ 'formato' => strtolower( $opt ) ] ); $x->set_regular_price( 20 );
		$x->set_virtual( $virt ); $x->set_downloadable( $dl ); $x->set_status( 'publish' );
		$created[] = $x->save();
	}
	WC_Product_Variable::sync( $vid );
	return $vid;
};
$mixed = $make_var( 'KC136 mixed', [ 'Disc' => [ false, false ], 'Key' => [ true, false ] ] );
$m = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $mixed ), 'summary' );
$check( true === ( $m['shipping_required'] ?? null ) && 'shipped' === ( $m['fulfilment'] ?? null ), 'misto: si spedisce se una variante si spedisce' );
$check( false === ( $m['virtual'] ?? null ) && false === ( $m['downloadable'] ?? null ), 'misto: virtuale/scaricabile solo se lo sono tutte' );

$keys = $make_var( 'KC136 keys', [ 'Steam' => [ true, false ], 'GOG' => [ true, false ] ] );
$k = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $keys ), 'summary' );
$check( 'virtual' === ( $k['fulfilment'] ?? null ) && true === ( $k['virtual'] ?? null ), 'variabile di sole chiavi -> virtual' );

foreach ( array_reverse( array_filter( $created ) ) as $id ) {
	wp_delete_post( (int) $id, true );
}
$left = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 's' => 'KC136' ] );
$check( empty( $left ), 'tutte le fixture KC136 rimosse' );

if ( empty( $failures ) ) {
	echo "PASS test-136-delivery-flags\n";
	exit( 0 );
}
echo "FAIL test-136-delivery-flags\n";
foreach ( $failures as $f ) {
	echo ' - ' . esc_html( $f ) . "\n";
}
exit( 1 );
