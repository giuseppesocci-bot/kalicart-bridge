<?php
/**
 * Discovery controls and lifecycle verification.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-discovery-controls.php
 *
 * The script restores every option and generated file state before exit.
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$report   = [];
$check    = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$options = [
	'kalicart_bridge_hint_search',
	'kalicart_bridge_hint_zero',
	'kalicart_bridge_hint_category',
	'kalicart_bridge_well_known_enabled',
];
$original = [];
foreach ( $options as $name ) {
	$original[ $name ] = get_option( $name, false );
}

try {
	$cases = [
		'search_form_only' => [ true, false, false ],
		'search_page_only' => [ false, true, false ],
		'catalog_pages_only' => [ false, false, true ],
	];
	foreach ( $cases as $case => [ $search, $results, $catalog ] ) {
		update_option( 'kalicart_bridge_hint_search', $search );
		update_option( 'kalicart_bridge_hint_zero', $results );
		update_option( 'kalicart_bridge_hint_category', $catalog );
		ob_start();
		KaliCart_Bridge_Signals::inject_honey_js();
		$output = (string) ob_get_clean();
		$check( false !== strpos( $output, 'var showSearch=' . ( $search ? 'true' : 'false' ) . ';' ), $case . ': search-form toggle leaked.' );
		$check( false !== strpos( $output, 'var showZero=' . ( $results ? 'true' : 'false' ) . ';' ), $case . ': search-page toggle leaked.' );
		$check( false !== strpos( $output, 'var showCategory=' . ( $catalog ? 'true' : 'false' ) . ';' ), $case . ': category/product toggle leaked.' );
		$check( false !== strpos( $output, 'var isSearchWithResults = showZero &&' ), $case . ': result pages are not controlled by their dedicated toggle.' );
	}

	// A genuinely new installation receives three independent opt-in defaults.
	delete_option( 'kalicart_bridge_hint_search' );
	delete_option( 'kalicart_bridge_hint_zero' );
	delete_option( 'kalicart_bridge_hint_category' );
	kalicart_bridge_ensure_default_options();
	$check( false === (bool) get_option( 'kalicart_bridge_hint_search' ), 'Search-form hint is not off by default.' );
	$check( false === (bool) get_option( 'kalicart_bridge_hint_zero' ), 'Search-page hint is not off by default.' );
	$check( false === (bool) get_option( 'kalicart_bridge_hint_category' ), 'Category/product hint is not off by default.' );

	// Reactivation defaults must preserve an existing merchant choice.
	update_option( 'kalicart_bridge_hint_search', true );
	kalicart_bridge_ensure_default_options();
	$check( true === (bool) get_option( 'kalicart_bridge_hint_search' ), 'Default initialization overwrote an existing toggle.' );

	update_option( 'kalicart_bridge_well_known_enabled', false );
	KaliCart_Bridge_Signals::remove_well_known_files();
	$disabled = wp_remote_get( add_query_arg( 'kb_test', time(), home_url( '/.well-known/kalicart-bridge.json' ) ), [
		'timeout'     => 20,
		'redirection' => 0,
	] );
	$disabled_status = is_wp_error( $disabled ) ? 0 : (int) wp_remote_retrieve_response_code( $disabled );
	$check( 404 === $disabled_status, 'Disabled .well-known route did not return 404.' );

	update_option( 'kalicart_bridge_well_known_enabled', true );
	KaliCart_Bridge_Signals::write_well_known_files();
	$enabled = wp_remote_get( add_query_arg( 'kb_test', time(), home_url( '/.well-known/kalicart-bridge.json' ) ), [
		'timeout'     => 20,
		'redirection' => 0,
	] );
	$enabled_status = is_wp_error( $enabled ) ? 0 : (int) wp_remote_retrieve_response_code( $enabled );
	$check( 200 === $enabled_status, 'Enabled .well-known route did not return 200.' );
	$report['well_known_status'] = [ 'disabled' => $disabled_status, 'enabled' => $enabled_status ];
} finally {
	foreach ( $original as $name => $value ) {
		update_option( $name, $value );
	}
	if ( $original['kalicart_bridge_well_known_enabled'] ) {
		KaliCart_Bridge_Signals::write_well_known_files();
	} else {
		KaliCart_Bridge_Signals::remove_well_known_files();
	}
}

echo wp_json_encode( [
	'success'  => empty( $failures ),
	'failures' => $failures,
	'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
	exit( 1 );
}
