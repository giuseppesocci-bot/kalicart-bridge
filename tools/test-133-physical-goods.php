<?php
/**
 * KaliCart Bridge 1.0.133 — shipping_required disclosure and physical_only filter.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-133-physical-goods.php
 *
 * Creates its own fixtures (one virtual simple product, one variable product whose
 * variations are ALL virtual, one ordinary physical product) and removes them again.
 * The variable fixture is the case WooCommerce itself gets wrong:
 * WC_Product_Variable::get_virtual() returns false unconditionally, so the parent
 * needs_shipping() is always true no matter how the variations are configured.
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'KaliCart_Bridge_Catalog_Engine' ) || ! class_exists( 'WC_Product_Variable' ) ) {
	exit( 1 );
}

$failures    = [];
$report      = [];
$created_ids = [];

$check = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

// ── Fixtures ────────────────────────────────────────────────────────────────
$simple_virtual = new WC_Product_Simple();
$simple_virtual->set_name( 'KALICART TEST virtual simple' );
$simple_virtual->set_regular_price( 10 );
$simple_virtual->set_virtual( true );
$simple_virtual->set_status( 'publish' );
$created_ids[] = $sv_id = $simple_virtual->save();

$simple_physical = new WC_Product_Simple();
$simple_physical->set_name( 'KALICART TEST physical simple' );
$simple_physical->set_regular_price( 20 );
$simple_physical->set_status( 'publish' );
$created_ids[] = $sp_id = $simple_physical->save();

$variable = new WC_Product_Variable();
$variable->set_name( 'KALICART TEST virtual variable' );
$variable->set_status( 'publish' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Livello' );
$attribute->set_options( [ 'Base', 'Avanzato' ] );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( [ $attribute ] );
$created_ids[] = $vv_id = $variable->save();
foreach ( [ 'Base' => 30, 'Avanzato' => 40 ] as $level => $price ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $vv_id );
	$variation->set_attributes( [ 'livello' => $level ] );
	$variation->set_regular_price( $price );
	$variation->set_virtual( true );
	$variation->set_status( 'publish' );
	$created_ids[] = $variation->save();
}
WC_Product_Variable::sync( $vv_id );

$engine = new ReflectionMethod( 'KaliCart_Bridge_Catalog_Engine', 'product_needs_shipping' );
$engine->setAccessible( true );
$needs_shipping = static fn( int $id ): bool => (bool) $engine->invoke( null, wc_get_product( $id ) );

// ── 1. The fact itself ──────────────────────────────────────────────────────
$check( true === $needs_shipping( $sp_id ), 'a physical simple product must need shipping' );
$check( false === $needs_shipping( $sv_id ), 'a virtual simple product must not need shipping' );
$check( false === $needs_shipping( $vv_id ), 'a variable product whose variations are all virtual must not need shipping' );

// The regression this guards: WooCommerce's own accessor disagrees, by design.
$check(
	true === wc_get_product( $vv_id )->needs_shipping(),
	'precondition: WC_Product_Variable::needs_shipping() is expected to be true here; if WooCommerce changed this, revisit product_needs_shipping()'
);
$report['woocommerce_parent_flag_is_blind'] = true;

// ── 2. Disclosure in fields=summary ─────────────────────────────────────────
foreach ( [ $sp_id => true, $sv_id => false, $vv_id => false ] as $id => $expected ) {
	$summary = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $id ), 'summary' );
	$check( array_key_exists( 'shipping_required', $summary ), "summary for {$id} must carry shipping_required" );
	$check( $expected === ( $summary['shipping_required'] ?? null ), "summary shipping_required for {$id} must be " . var_export( $expected, true ) );
}

// ── 3. The opt-in filter ────────────────────────────────────────────────────
$ids_of = static function ( array $args ): array {
	$result = KaliCart_Bridge_Catalog_Engine::query_products( $args );
	return array_column( $result['products'] ?? [], 'id' );
};

$unfiltered = $ids_of( [ 'search' => 'KALICART TEST', 'per_page' => 50, 'fields' => 'summary' ] );
$check( in_array( $sp_id, $unfiltered, true ), 'without the filter the physical fixture is returned' );
$check( in_array( $sv_id, $unfiltered, true ), 'without the filter the virtual fixture is STILL returned: the Bridge serves the merchant catalog as published' );
$check( in_array( $vv_id, $unfiltered, true ), 'without the filter the virtual variable fixture is still returned' );

$filtered = $ids_of( [ 'search' => 'KALICART TEST', 'per_page' => 50, 'fields' => 'summary', 'physical_only' => true ] );
$check( in_array( $sp_id, $filtered, true ), 'physical_only must keep the physical fixture' );
$check( ! in_array( $sv_id, $filtered, true ), 'physical_only must drop the virtual simple fixture' );
$check( ! in_array( $vv_id, $filtered, true ), 'physical_only must drop the all-virtual variable fixture' );

$explicit_false = $ids_of( [ 'search' => 'KALICART TEST', 'per_page' => 50, 'fields' => 'summary', 'physical_only' => false ] );
$check( $explicit_false === $unfiltered, 'physical_only=false must be a no-op, not an inverted filter' );

$report['unfiltered'] = count( $unfiltered );
$report['physical_only'] = count( $filtered );

// ── Cleanup ─────────────────────────────────────────────────────────────────
foreach ( array_reverse( array_filter( $created_ids ) ) as $id ) {
	wp_delete_post( (int) $id, true );
}
$leftover = get_posts( [
	'post_type'   => [ 'product', 'product_variation' ],
	'post_status' => 'any',
	'fields'      => 'ids',
	'numberposts' => -1,
	's'           => 'KALICART TEST',
] );
$check( empty( $leftover ), 'every fixture created by this run must be removed' );

// ── Report ──────────────────────────────────────────────────────────────────
if ( empty( $failures ) ) {
	echo 'PASS test-133-physical-goods ' . esc_html( (string) wp_json_encode( $report ) ) . "\n";
	exit( 0 );
}
echo "FAIL test-133-physical-goods\n";
foreach ( $failures as $failure ) {
	echo ' - ' . esc_html( $failure ) . "\n";
}
exit( 1 );
