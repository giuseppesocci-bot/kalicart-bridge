<?php
/**
 * 1.0.158: daily funnel totals for the heartbeat and private stock quantities.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-158-funnel-stock.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };

// ── funnel ──
$today = gmdate( 'Ymd' );
update_option( 'kalicart_bridge_agent_funnel_v2_' . $today . '_sessions_created', 3, false );
update_option( 'kalicart_bridge_agent_funnel_v2_' . $today . '_carts_loaded', 2, false );
update_option( 'kalicart_bridge_agent_funnel_v2_' . $today . '_orders_linked', 1, false );
$old = gmdate( 'Ymd', time() - 20 * DAY_IN_SECONDS );
update_option( 'kalicart_bridge_agent_funnel_v2_' . $old . '_sessions_created', 9, false );
$before = array_column( KaliCart_Bridge_Checkout::daily_funnel_counts( 7 )['days'], null, 'day' )[ gmdate( 'Y-m-d' ) ] ?? [];
$p = new WC_Product_Simple(); $p->set_name( 'Funnel item' ); $p->set_regular_price( '20' ); $p->set_status( 'publish' ); $pid = $p->save();
$paid = wc_create_order(); $paid->add_product( wc_get_product( $pid ), 2 ); $paid->calculate_totals( false );
$paid->update_meta_data( '_kalicart_bridge_source', 'agent_checkout' ); $paid->set_date_paid( time() ); $paid->set_status( 'processing' ); $paid->save();
$unpaid = wc_create_order(); $unpaid->add_product( wc_get_product( $pid ), 1 ); $unpaid->calculate_totals( false );
$unpaid->update_meta_data( '_kalicart_bridge_source', 'agent_checkout' ); $unpaid->set_status( 'on-hold' ); $unpaid->save();
$plain = wc_create_order(); $plain->add_product( wc_get_product( $pid ), 1 ); $plain->calculate_totals( false ); $plain->set_date_paid( time() ); $plain->save();
wc_create_refund( [ 'order_id' => $paid->get_id(), 'amount' => 5, 'reason' => 'test' ] );

$f = KaliCart_Bridge_Checkout::daily_funnel_counts( 7 );
$days = array_column( $f['days'], null, 'day' );
$t = $days[ gmdate( 'Y-m-d' ) ] ?? [];
$ok( 7 === count( $f['days'] ), 'seven days always sent, zeros included' );
$ok( ( $t['sessions_created'] ?? -1 ) === 3 && ( $t['carts_loaded'] ?? -1 ) === 2 && ( $t['orders_linked'] ?? -1 ) === 1, 'today counters read from the daily options' );
$ok( ( $t['orders_paid'] ?? -1 ) - ( $before['orders_paid'] ?? 0 ) === 1, 'only paid agent orders counted (unpaid and non-agent orders excluded)' );
$ok( abs( (float) ( $t['net_paid_value'] ?? -1 ) - (float) ( $before['net_paid_value'] ?? 0 ) - 35.0 ) < 0.001 && 1 === preg_match( '/^\d+\.\d{2}$/', (string) ( $t['net_paid_value'] ?? '' ) ), 'net paid value = 40 - 5 refund, as a 2-decimal string' );
$ok( ! isset( $days[ gmdate( 'Y-m-d', time() - 20 * DAY_IN_SECONDS ) ] ), 'days outside the window are not sent' );
$keys = array_keys( $t ); sort( $keys );
$ok( $keys === [ 'carts_loaded', 'day', 'net_paid_value', 'orders_linked', 'orders_paid', 'sessions_created' ], 'no field beyond the aggregates (no ids, no customer data)' );
$ok( is_string( $f['currency'] ) && '' !== $f['currency'], 'currency declared' );

// ── stock quantity private by default ──
delete_option( 'kalicart_bridge_stock_quantity_public' );
update_option( 'woocommerce_notify_low_stock_amount', 2 );
update_option( 'woocommerce_stock_format', '' );
$s = new WC_Product_Simple(); $s->set_name( 'Stock item' ); $s->set_regular_price( '10' ); $s->set_status( 'publish' );
$s->set_manage_stock( true ); $s->set_stock_quantity( 2 ); $sid = $s->save();
$row = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $sid ), 'detail' );
$st = $row['stock'] ?? [];
$ok( ! array_key_exists( 'quantity', $st ), 'product: exact quantity not exposed by default' );
$ok( true === ( $st['low_stock'] ?? null ), 'product: low_stock true at the WooCommerce threshold' );
$ok( 'availability_status_only' === ( $st['confidence'] ?? '' ), 'product: confidence downgraded' );
$s2 = wc_get_product( $sid ); $s2->set_stock_quantity( 50 ); $s2->save();
$st = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $sid ), 'detail' )['stock'] ?? [];
$ok( false === ( $st['low_stock'] ?? null ), 'product: low_stock false above the threshold' );
update_option( 'woocommerce_stock_format', 'no_amount' );
$st = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $sid ), 'detail' )['stock'] ?? [];
$ok( ! array_key_exists( 'low_stock', $st ) && ! array_key_exists( 'quantity', $st ), 'store hides stock amounts: no low_stock either' );
update_option( 'woocommerce_stock_format', '' );
update_option( 'kalicart_bridge_stock_quantity_public', true );
$st = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $sid ), 'detail' )['stock'] ?? [];
$ok( 50 === (int) ( $st['quantity'] ?? -1 ) && 'numeric_stock_quantity' === ( $st['confidence'] ?? '' ), 'merchant opt-in: exact quantity exposed as before' );
delete_option( 'kalicart_bridge_stock_quantity_public' );

$v = new WC_Product_Variable(); $v->set_name( 'Var item' ); $v->set_status( 'publish' );
$attr = new WC_Product_Attribute(); $attr->set_name( 'Size' ); $attr->set_options( [ 'S', 'M' ] ); $attr->set_visible( true ); $attr->set_variation( true );
$v->set_attributes( [ $attr ] ); $vid = $v->save();
$var = new WC_Product_Variation(); $var->set_parent_id( $vid ); $var->set_attributes( [ 'size' => 'S' ] ); $var->set_regular_price( '12' );
$var->set_manage_stock( true ); $var->set_stock_quantity( 1 ); $var->save();
WC_Product_Variable::sync( $vid );
$d = KaliCart_Bridge_Catalog_Engine::normalize_product( wc_get_product( $vid ), 'detail' );
$vs = $d['variations'][0]['stock'] ?? ( $d['variants'][0]['stock'] ?? [] );
$ok( ! array_key_exists( 'quantity', $vs ) && true === ( $vs['low_stock'] ?? null ), 'variation: quantity hidden, low_stock true' );
$ok( ! isset( $vs['agent_note'] ), 'variation: no "last unit" note that would reveal the quantity' );

// snapshot projection keeps the low_stock computed upstream
$proj = KaliCart_Bridge_Snapshot::project( [ 'id' => $sid, 'stock' => [ 'in_stock' => true, 'quantity_tracked' => true, 'low_stock' => true, 'confidence' => 'availability_status_only' ] ], true, fn( $id ) => 2 );
$ok( true === ( $proj['stock']['low_stock'] ?? null ), 'snapshot keeps low_stock when the quantity was already hidden' );

echo ( $fails ? "FAILED $fails/$n" : "ALL $n PASSED" ), "\n";
