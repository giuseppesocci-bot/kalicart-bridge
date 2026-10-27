<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'Isolated WooCommerce fixture required.' );
}
require_once dirname( __DIR__ ) . '/includes/class-catalog-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-api.php';
if ( ! defined( 'KALICART_BRIDGE_VERSION' ) ) { define( 'KALICART_BRIDGE_VERSION', '1.0.152-fixture' ); }

$keys = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_default_country', 'woocommerce_tax_based_on', 'woocommerce_currency' ];
$previous = [];
foreach ( $keys as $key ) { $previous[ $key ] = get_option( $key ); }
$product_ids = [];
$rate_id = null;
try {
    update_option( 'woocommerce_calc_taxes', 'yes' );
    update_option( 'woocommerce_prices_include_tax', 'no' );
    update_option( 'woocommerce_tax_display_shop', 'incl' );
    update_option( 'woocommerce_default_country', 'IT' );
    update_option( 'woocommerce_tax_based_on', 'base' );
    update_option( 'woocommerce_currency', 'EUR' );
    $rate_id = WC_Tax::_insert_tax_rate( [
        'tax_rate_country' => 'IT', 'tax_rate_state' => '', 'tax_rate' => '10.0000',
        'tax_rate_name' => 'KaliCart fixture', 'tax_rate_priority' => 1,
        'tax_rate_compound' => 0, 'tax_rate_shipping' => 0,
        'tax_rate_class' => '',
    ] );
    $p = new WC_Product_Simple();
    $p->set_name( 'KaliCart price fixture' );
    $p->set_status( 'publish' );
    $p->set_regular_price( '70' );
    $p->set_sale_price( '65' );
    $p->set_tax_status( 'taxable' );
    $p->set_tax_class( '' );
    $product_id = $p->save();
    $product_ids[] = $product_id;
    $p = wc_get_product( $product_id );
    $method = new ReflectionMethod( 'KaliCart_Bridge_Catalog_Engine', 'compute_price' );
    $price = $method->invoke( null, $p );
    $summary = KaliCart_Bridge_Catalog_Engine::normalize_product( $p, 'summary' );
    $detail = KaliCart_Bridge_Catalog_Engine::normalize_product( $p, 'detail' );
    $checks = [
        'shop_price_71_50' => abs( (float) $price['current'] - 71.5 ) < 0.001,
        'shop_regular_77' => abs( (float) $price['regular'] - 77 ) < 0.001,
        'shop_sale_71_50' => abs( (float) $price['sale'] - 71.5 ) < 0.001,
        'tax_basis_included' => true === $price['vat_included'],
        'summary_price_71_50' => abs( (float) $summary['price']['current'] - 71.5 ) < 0.001,
        'summary_tax_basis_included' => true === $summary['price']['vat_included'],
        'detail_price_71_50' => abs( (float) $detail['price']['current'] - 71.5 ) < 0.001,
        'detail_tax_basis_included' => true === $detail['price']['vat_included'],
    ];
    $child = new WC_Product_Simple();
    $child->set_name( 'KaliCart grouped child' );
    $child->set_status( 'publish' );
    $child->set_regular_price( '90' );
    $child->set_sale_price( '80' );
    $child->set_tax_status( 'taxable' );
    $child_id = $child->save();
    $product_ids[] = $child_id;
    $group = new WC_Product_Grouped();
    $group->set_name( 'KaliCart grouped fixture' );
    $group->set_status( 'publish' );
    $group->set_children( [ $product_id, $child_id ] );
    $group_id = $group->save();
    $product_ids[] = $group_id;
    $group_price = $method->invoke( null, wc_get_product( $group_id ) );
    $checks['group_min_71_50'] = abs( (float) $group_price['current'] - 71.5 ) < 0.001;
    $checks['group_max_88'] = abs( (float) $group_price['max_current'] - 88 ) < 0.001;

    $parent = new WC_Product_Variable();
    $parent->set_name( 'KaliCart variable fixture' );
    $parent->set_status( 'publish' );
    $attr = new WC_Product_Attribute();
    $attr->set_name( 'Color' );
    $attr->set_options( [ 'Blue', 'Red' ] );
    $attr->set_visible( true );
    $attr->set_variation( true );
    $parent->set_attributes( [ $attr ] );
    $parent_id = $parent->save();
    $product_ids[] = $parent_id;
    foreach ( [ [ 'Blue', '70', '65' ], [ 'Red', '90', '80' ] ] as [ $color, $regular, $sale ] ) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id( $parent_id );
        $variation->set_attributes( [ 'color' => $color ] );
        $variation->set_regular_price( $regular );
        $variation->set_sale_price( $sale );
        $variation->set_tax_status( 'taxable' );
        $variation_id = $variation->save();
        $product_ids[] = $variation_id;
    }
    WC_Product_Variable::sync( $parent_id );
    wc_delete_product_transients( $parent_id );
    $variable_price = $method->invoke( null, wc_get_product( $parent_id ) );
    $checks['variable_min_71_50'] = abs( (float) $variable_price['current'] - 71.5 ) < 0.001;
    $checks['variable_max_88'] = abs( (float) $variable_price['max_current'] - 88 ) < 0.001;
    $checks['variable_tax_basis_included'] = true === $variable_price['vat_included'];
    $zero_tax = new WC_Product_Simple();
    $zero_tax->set_name( 'KaliCart price fixture zero tax' );
    $zero_tax->set_status( 'publish' );
    $zero_tax->set_regular_price( '70' );
    $zero_tax->set_tax_status( 'none' );
    $zero_id = $zero_tax->save();
    $product_ids[] = $zero_id;
    KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();
    $sorted = KaliCart_Bridge_Catalog_Engine::query_products( [
        'search' => 'KaliCart price fixture', 'orderby' => 'price', 'order' => 'ASC',
        'per_page' => 10, 'fields' => 'summary',
    ] );
    $sorted_ids = array_column( $sorted['products'] ?? [], 'id' );
    $checks['sorts_display_price'] = [ $zero_id, $product_id ] === $sorted_ids;
    $sorted_desc = KaliCart_Bridge_Catalog_Engine::query_products( [
        'search' => 'KaliCart price fixture', 'orderby' => 'price', 'order' => 'DESC',
        'per_page' => 1, 'page' => 1, 'fields' => 'summary',
    ] );
    $checks['sorts_display_price_desc_page'] = [ $product_id ] === array_column( $sorted_desc['products'] ?? [], 'id' );
    $minimum = KaliCart_Bridge_Catalog_Engine::query_products( [
        'search' => 'KaliCart price fixture', 'min_price' => 71,
        'per_page' => 10, 'fields' => 'summary',
    ] );
    $checks['minimum_keeps_tax_adjusted_price'] = [ $product_id ] === array_column( $minimum['products'] ?? [], 'id' );
    $maximum = KaliCart_Bridge_Catalog_Engine::query_products( [
        'search' => 'KaliCart price fixture', 'max_price' => 70,
        'per_page' => 10, 'fields' => 'summary',
    ] );
    $checks['maximum_excludes_tax_adjusted_price'] = [ $zero_id ] === array_column( $maximum['products'] ?? [], 'id' );
    update_option( 'woocommerce_tax_display_shop', 'excl' );
    $excluded = $method->invoke( null, $p );
    $checks['excluded_65'] = abs( (float) $excluded['current'] - 65 ) < 0.001;
    $checks['tax_basis_excluded'] = false === $excluded['vat_included'];
    update_option( 'woocommerce_prices_include_tax', 'yes' );
    $p->set_regular_price( '77' );
    $p->set_sale_price( '71.5' );
    $p->save();
    $entered_inclusive = $method->invoke( null, wc_get_product( $product_id ) );
    $checks['entered_inclusive_display_exclusive_65'] = abs( (float) $entered_inclusive['current'] - 65 ) < 0.001;
    $checks['entered_inclusive_display_exclusive_70'] = abs( (float) $entered_inclusive['regular'] - 70 ) < 0.001;
    echo wp_json_encode( [ 'checks' => $checks, 'inclusive' => $price, 'exclusive' => $excluded ] ) . "\n";
    if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Price fixture failed.' ); }
} finally {
    foreach ( array_reverse( $product_ids ) as $id ) { wp_delete_post( $id, true ); }
    if ( $rate_id ) { WC_Tax::_delete_tax_rate( $rate_id ); }
    foreach ( $previous as $key => $value ) { update_option( $key, $value ); }
}
