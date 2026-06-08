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
];
foreach ( $kalicart_bridge_options as $kalicart_bridge_option ) {
    delete_option( $kalicart_bridge_option );
}

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
