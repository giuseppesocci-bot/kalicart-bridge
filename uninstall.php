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

// Transients
$kalicart_bridge_transients = [
    'kalicart_bridge_health_v2',
    'kalicart_bridge_meta',
    'kalicart_bridge_robots_checked',
    'kalicart_bridge_sitemap_written',
];
foreach ( $kalicart_bridge_transients as $kalicart_bridge_transient ) {
    delete_transient( $kalicart_bridge_transient );
}
