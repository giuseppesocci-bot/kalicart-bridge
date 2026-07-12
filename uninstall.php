<?php
/**
 * KaliCart Bridge — Uninstall
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Removes all options and transients created by the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options
$kalicart_bridge_options = [
    'kalicart_bridge_badge_enabled',
    'kalicart_bridge_badge_position',
    'kalicart_bridge_robots_enabled',
    'kalicart_bridge_sitemap_enabled',
    'kalicart_bridge_checkout_enabled',
    'kalicart_bridge_well_known_enabled',
    'kalicart_bridge_agent_hints_enabled',
    'kalicart_bridge_hint_search',
    'kalicart_bridge_hint_zero',
    'kalicart_bridge_hint_category',
    'kalicart_bridge_global_consent',
    'kalicart_bridge_agent_index_url',
    'kalicart_bridge_return_policy_url',
    'kalicart_bridge_wk_version',
    'kalicart_bridge_catalog_facets_mono',
    'kalicart_rate_guard_checkout',
	'kalicart_rate_guard_checkout_long',
	'kalicart_rate_guard_checkout_access',
    'kalicart_rate_guard_catalog',
    'kalicart_rate_guard_mcp',
	'kalicart_rate_guard_telemetry_html',
    'kalicart_rate_guard_lock_checkout',
	'kalicart_rate_guard_lock_checkout_long',
	'kalicart_rate_guard_lock_checkout_access',
    'kalicart_rate_guard_lock_catalog',
    'kalicart_rate_guard_lock_mcp',
	'kalicart_rate_guard_lock_telemetry_html',
];
foreach ( $kalicart_bridge_options as $kalicart_bridge_option ) {
    delete_option( $kalicart_bridge_option );
}

// Cron
wp_clear_scheduled_hook( 'kalicart_bridge_facets_rebuild' );
wp_clear_scheduled_hook( 'kalicart_bridge_cleanup_claims' );

// Checkout session claim rows (kalicart_session_claimed_{id}): dynamically keyed, one per
// attributed checkout — not in the fixed options list above, needs a LIKE-pattern sweep.
global $wpdb;
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'kalicart_session_claimed_' ) . '%'
) );

// Dynamic hardening/session records cannot be enumerated in the fixed list above.
foreach ( [
	'kalicart_checkout_idem_',
	'kalicart_bridge_agent_funnel_v2_',
	'kalicart_rate_guard_lock_idem_',
	'_transient_kalicart_session_',
	'_transient_timeout_kalicart_session_',
	'_transient_kalicart_checkout_idem_',
	'_transient_timeout_kalicart_checkout_idem_',
	'_transient_kalicart_bridge_meta_',
	'_transient_timeout_kalicart_bridge_meta_',
] as $kalicart_bridge_dynamic_prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $kalicart_bridge_dynamic_prefix ) . '%'
	) );
}

// Transients
$kalicart_bridge_transients = [
    'kalicart_bridge_health_v2',
    'kalicart_bridge_meta',
    'kalicart_bridge_robots_checked',
    'kalicart_bridge_sitemap_written',
    'kalicart_bridge_catalog_query_cache_v1',
];
foreach ( $kalicart_bridge_transients as $kalicart_bridge_transient ) {
    delete_transient( $kalicart_bridge_transient );
}
