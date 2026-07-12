<?php
defined( 'ABSPATH' ) || exit;

// Assertions below target source copy; isolate them from the site's admin locale.
switch_to_locale( 'en_US' );

$failures = [];
$check = static function ( string $name, bool $condition ) use ( &$failures ): void {
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
	if ( ! $condition ) {
		$failures[] = $name;
	}
};

$check( 'Agent Commerce class loaded', class_exists( 'KaliCart_Bridge_ACP_Feed' ) );

$users = get_users( [ 'role__in' => [ 'administrator', 'shop_manager' ], 'number' => 1, 'fields' => 'ID' ] );
if ( $users ) {
	wp_set_current_user( (int) $users[0] );
}
$check( 'test user can manage WooCommerce', current_user_can( 'manage_woocommerce' ) );

$original_opts = get_option( KaliCart_Bridge_ACP_Feed::OPTION, null );
$original_return_policy = get_option( 'kalicart_bridge_return_policy_url', null );
$test_opts = is_array( $original_opts ) ? $original_opts : [];
$test_opts['target_countries'] = 'IT';
$test_opts['brand_fallback'] = 'Merchant Own Label';
$test_opts['last_stats'] = [
	'rows' => 1,
	'products' => 1,
	'excluded_no_image' => 1,
	'rows_missing_brand' => 1,
	'fallback_brand_rows' => 1,
	'excluded_invalid' => 0,
	'invalid_examples' => [],
	'generated_at' => gmdate( 'c' ),
];
update_option( KaliCart_Bridge_ACP_Feed::OPTION, $test_opts, false );
update_option( 'kalicart_bridge_return_policy_url', 'https://example.com/returns', false );

ob_start();
KaliCart_Bridge_ACP_Feed::render_panel();
$html = (string) ob_get_clean();

if ( null === $original_opts ) {
	delete_option( KaliCart_Bridge_ACP_Feed::OPTION );
} else {
	update_option( KaliCart_Bridge_ACP_Feed::OPTION, $original_opts, false );
}
if ( null === $original_return_policy ) {
	delete_option( 'kalicart_bridge_return_policy_url' );
} else {
	update_option( 'kalicart_bridge_return_policy_url', $original_return_policy, false );
}

$_GET['tab'] = 'agent-commerce';
ob_start();
include KALICART_BRIDGE_DIR . 'admin/admin-page.php';
$admin_html = (string) ob_get_clean();
unset( $_GET['tab'] );

$check( 'tab panel heading', false !== strpos( $html, 'ChatGPT Product Feed (OpenAI)' ) && false !== strpos( $admin_html, '>ChatGPT Feed<' ) );
$check( 'no separate submenu renderer', ! method_exists( 'KaliCart_Bridge_ACP_Feed', 'register_menu' ) );
$check( 'form returns to main Agent Commerce tab', false !== strpos( $html, 'page=kalicart-bridge' ) && false !== strpos( $html, 'tab=agent-commerce' ) );
$check( 'Agent Commerce remains the active server-rendered tab', false !== strpos( $admin_html, 'class="kali-tab kali-tab--active" data-tab="agent-commerce"' ) && false !== strpos( $admin_html, 'id="kali-tab-agent-commerce" class="kali-panel" style="display:block"' ) );
$check( 'return policy is not duplicated as an input', false === strpos( $html, 'name="return_policy_url"' ) );
$check( 'return policy comes from Settings', false !== strpos( $html, 'Configured in the Settings tab: https://example.com/returns' ) );
$check( 'agent-readable contract', false !== strpos( $html, 'None of this affects the agent-readable catalog, search, REST API, MCP or UCP surfaces.' ) );
$check( 'OpenAI brand warning copy', false !== strpos( $html, 'Brand is required by OpenAI’s direct product feed specification.' ) );
$check( 'fallback is not reported as complete', false !== strpos( $html, '>Fallback applied<' ) && false !== strpos( $html, 'rows filled by the merchant fallback' ) );
$check( 'missing brand is non-blocking with explicit onus', false !== strpos( $html, 'Rows submitted without brand.' ) && false !== strpos( $html, 'you knowingly assume that responsibility' ) );
$check( 'fallback has a neutral placeholder', false !== strpos( $html, 'placeholder="Your merchant-owned brand"' ) );
$check( 'merchant responsibility is explicit', false !== strpos( $html, 'the merchant declares it accurate and accepts responsibility' ) );
$check( 'OpenAI image warning copy', false !== strpos( $html, 'A primary product image is required by OpenAI’s direct product feed specification.' ) );
$check( 'ChatGPT-only scope is explicit', false !== strpos( $html, 'Every status and setting in this section refers only to the optional file delivered to OpenAI.' ) );
$check( 'delivery is approval-gated', false !== strpos( $html, 'OpenAI approves the merchant and assigns SFTP or API delivery credentials.' ) );
$check( 'no submit-this-url claim', false === stripos( $html, 'submit this URL' ) );

if ( ! taxonomy_exists( 'product_brand' ) ) {
	$failures[] = 'product_brand taxonomy unavailable';
	echo 'FAIL product_brand taxonomy unavailable' . PHP_EOL;
} else {
	$product = new WC_Product_Simple();
	$product->set_name( 'KaliCart temporary brand test' );
	$product->set_status( 'draft' );
	$product->set_regular_price( '1.00' );
	$product_id = $product->save();
	$term = wp_insert_term( 'KaliCart Test & Brand', 'product_brand' );
	if ( is_wp_error( $term ) ) {
		echo 'FAIL temporary brand term creation' . PHP_EOL;
		$failures[] = 'temporary brand term creation';
	} else {
		wp_set_object_terms( $product_id, [ (int) $term['term_id'] ], 'product_brand', false );
		$loaded = wc_get_product( $product_id );
		$brand = $loaded ? KaliCart_Bridge_Catalog_Engine::resolve_brand( $loaded ) : null;
		$check( 'brand is plain text, not HTML entity', 'KaliCart Test & Brand' === $brand );
		wp_delete_term( (int) $term['term_id'], 'product_brand' );
	}
	wp_delete_post( $product_id, true );
}

restore_previous_locale();

if ( $failures ) {
	echo PHP_EOL . 'AGENT-COMMERCE: FAIL (' . count( $failures ) . ')' . PHP_EOL;
	exit( 1 );
}
echo PHP_EOL . 'AGENT-COMMERCE: OK' . PHP_EOL;
