<?php
/**
 * KaliCart Bridge 1.0.134 — fulfilment a tre valori e visibilita' di catalogo.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-134-fulfilment-visibility.php
 *
 * Crea le proprie fixture e le rimuove. Copre le due idee della versione:
 *  - il catalogo e' lo specchio del negozio: dice cosa arriva a casa, cosa si
 *    scarica, cosa si ritira, e non mostra cio' che il merchant ha nascosto;
 *  - un prodotto che non si spedisce non porta con se' le condizioni di
 *    spedizione del negozio, che per lui non esistono.
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

// Variabile con TUTTE le varianti virtuali e scaricabili -> download.
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

$ful = new ReflectionMethod( 'KaliCart_Bridge_Catalog_Engine', 'product_fulfilment' );
$ful->setAccessible( true );
$fulfilment = static fn( int $id ): string => (string) $ful->invoke( null, wc_get_product( $id ) );

// ── 1. I tre modi di ottenere un prodotto ───────────────────────────────────
$check( 'shipped'      === $fulfilment( $shipped ),      'un prodotto spedibile e\' shipped' );
$check( 'shipped'      === $fulfilment( $ship_with_dl ), 'scaricabile MA spedibile resta shipped: downloadable non implica non spedibile' );
$check( 'downloadable' === $fulfilment( $download ),     'virtuale e scaricabile e\' downloadable' );
$check( 'pickup_only'  === $fulfilment( $pickup ),       'non spedibile e NON scaricabile e\' pickup_only: esiste, quindi si ritira' );
$check( 'downloadable' === $fulfilment( $var_id ),       'variabile con tutte le varianti scaricabili e\' downloadable (il parent Woo direbbe di no)' );

// ── 2. Nessuna contraddizione sulla spedizione ──────────────────────────────
foreach ( [ $download, $pickup ] as $id ) {
	$s = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $id ), 'detail' )['shipping'] ?? [];
	$check( false === ( $s['shipping_required'] ?? null ), "shipping_required false per {$id}" );
	foreach ( [ 'free_shipping_available', 'free_shipping_thresholds', 'free_shipping_eligible_by_product_price', 'amount_to_nearest_free_shipping_threshold' ] as $k ) {
		$check( ! array_key_exists( $k, $s ), "un prodotto non spedibile non deve portare {$k} ({$id})" );
	}
	$check( ! empty( $s['delivery_note'] ), "serve una spiegazione di come si ottiene ({$id})" );
}
$shipped_block = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $shipped ), 'detail' )['shipping'] ?? [];
$check( array_key_exists( 'free_shipping_available', $shipped_block ), 'il prodotto spedibile conserva le condizioni di spedizione' );

// ── 3. Il summary dichiara come si ottiene ──────────────────────────────────
$sum = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $pickup ), 'summary' );
$check( 'pickup_only' === ( $sum['fulfilment'] ?? null ), 'fulfilment e\' nel summary: e\' li\' che l\'agente sceglie' );

// ── 4. Lo specchio: visibilita' di catalogo ─────────────────────────────────
$ids_of = static function ( array $args ): array {
	return array_column( KaliCart_Bridge_Catalog_Engine::query_products( $args )['products'] ?? [], 'id' );
};
// Lo specchio: il Bridge mostra il prodotto dove il negozio lo mostrerebbe.
// "Nascosto" sparisce da tutto; gli altri due stati limitano DOVE si trova, non
// se esiste, perche' su WooCommerce quei prodotti sono in vendita.
$found = $ids_of( [ 'search' => 'KC134', 'per_page' => 50, 'fields' => 'summary' ] );
$check( in_array( $search_only, $found, true ),   '"solo ricerca" si trova cercandolo, come sul sito' );
$check( in_array( $shipped, $found, true ),       'un prodotto visibile si trova cercandolo' );
$check( ! in_array( $catalog_only, $found, true ),'"solo negozio" e\' escluso dalla ricerca, come sul sito' );
$check( ! in_array( $hidden, $found, true ),      '"nascosto" non compare nella ricerca' );

$browsed = $ids_of( [ 'per_page' => 100, 'fields' => 'summary' ] );
$check( in_array( $catalog_only, $browsed, true ),   '"solo negozio" compare in navigazione' );
$check( in_array( $shipped, $browsed, true ),        'un prodotto visibile compare normalmente' );
$check( ! in_array( $search_only, $browsed, true ),  '"solo ricerca" non compare in navigazione' );
$check( ! in_array( $hidden, $browsed, true ),       '"nascosto" non compare in navigazione' );

// Il fatto va esposto a Global, che oggi non lo riceve affatto.
$full = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $search_only ), 'detail' );
$check( 'search' === ( $full['catalog_visibility'] ?? null ), 'il payload porta catalog_visibility cosi\' com\'e\'' );

// ── Cleanup ─────────────────────────────────────────────────────────────────
foreach ( array_reverse( array_filter( $created ) ) as $id ) {
	wp_delete_post( (int) $id, true );
}
$leftover = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 's' => 'KC134' ] );
$check( empty( $leftover ), 'ogni fixture creata da questo test deve essere rimossa' );

if ( empty( $failures ) ) {
	echo 'PASS test-134-fulfilment-visibility' . "\n";
	exit( 0 );
}
echo "FAIL test-134-fulfilment-visibility\n";
foreach ( $failures as $f ) { echo ' - ' . esc_html( $f ) . "\n"; }
exit( 1 );
