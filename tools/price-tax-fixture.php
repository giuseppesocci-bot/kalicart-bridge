<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
    throw new RuntimeException( 'Isolated WooCommerce fixture required.' );
}
require_once dirname( __DIR__ ) . '/includes/class-catalog-engine.php';

$keys = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_default_country', 'woocommerce_tax_based_on', 'woocommerce_currency' ];
$previous = [];
foreach ( $keys as $key ) { $previous[ $key ] = get_option( $key ); }
$product_id = null;
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
    $p = wc_get_product( $product_id );
    $method = new ReflectionMethod( 'KaliCart_Bridge_Catalog_Engine', 'compute_price' );
    $price = $method->invoke( null, $p );
    $checks = [
        'shop_price_71_50' => abs( (float) $price['current'] - 71.5 ) < 0.001,
        'shop_regular_77' => abs( (float) $price['regular'] - 77 ) < 0.001,
        'shop_sale_71_50' => abs( (float) $price['sale'] - 71.5 ) < 0.001,
        'tax_basis_included' => true === $price['vat_included'],
    ];
    update_option( 'woocommerce_tax_display_shop', 'excl' );
    $excluded = $method->invoke( null, $p );
    $checks['excluded_65'] = abs( (float) $excluded['current'] - 65 ) < 0.001;
    $checks['tax_basis_excluded'] = false === $excluded['vat_included'];
    echo wp_json_encode( [ 'checks' => $checks, 'inclusive' => $price, 'exclusive' => $excluded ] ) . "\n";
    if ( in_array( false, $checks, true ) ) { throw new RuntimeException( 'Price fixture failed.' ); }
} finally {
    if ( $product_id ) { wp_delete_post( $product_id, true ); }
    if ( $rate_id ) { WC_Tax::_delete_tax_rate( $rate_id ); }
    foreach ( $previous as $key => $value ) { update_option( $key, $value ); }
}
