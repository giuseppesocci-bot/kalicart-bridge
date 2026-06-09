<?php
/**
 * Plugin Name:       KaliCart Bridge
 * Plugin URI:        https://kalicart.com
 * Description:       Makes your WooCommerce catalog machine-readable and agent-accessible. Exposes normalized product data via REST API — no LLM, no external service, no cloud dependency.
 * Version:           1.0.68
 * Author:            KaliCart
 * Author URI:        https://kalicart.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kalicart-bridge
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.0
 * WC requires at least: 7.0
 * WC tested up to:      10.8
 * Requires Plugins:    woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KALICART_BRIDGE_VERSION', '1.0.68' );
define( 'KALICART_BRIDGE_FILE',    __FILE__ );
define( 'KALICART_BRIDGE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'KALICART_BRIDGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'KALICART_BRIDGE_API_NS',  'kalicart/v1' );

require_once KALICART_BRIDGE_DIR . 'includes/class-catalog-engine.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-quarantine.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-api.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-signals.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-admin.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-checkout.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-shortcodes.php';

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KALICART_BRIDGE_FILE, true );
    }
} );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>KaliCart Bridge</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }

    KaliCart_Bridge_API::init();
    KaliCart_Bridge_Signals::init();
    // write_well_known_files needs $wp_rewrite — run on init, not plugins_loaded
    add_action( 'init', function () {
        if ( get_option( 'kalicart_bridge_well_known_enabled', true ) ) {
            KaliCart_Bridge_Signals::write_well_known_files();
        }
    }, 20 );
    KaliCart_Bridge_Quarantine::init_hooks();
    KaliCart_Bridge_Shortcodes::init();
    if ( get_option( 'kalicart_bridge_checkout_enabled', false ) ) {
        KaliCart_Bridge_Checkout::init();
    }

    if ( is_admin() ) {
        KaliCart_Bridge_Admin::init();
    }
} );

register_activation_hook( __FILE__, function () {
    update_option( 'kalicart_bridge_badge_enabled',   true );
    update_option( 'kalicart_bridge_badge_position',  'bottom-right' );
    update_option( 'kalicart_bridge_robots_enabled',  true );
    update_option( 'kalicart_bridge_sitemap_enabled', true );
    update_option( 'kalicart_bridge_checkout_enabled', false );
    update_option( 'kalicart_bridge_well_known_enabled', true );
    update_option( 'kalicart_bridge_hint_search',      true );
    update_option( 'kalicart_bridge_hint_zero',        true );
    update_option( 'kalicart_bridge_hint_category',    true );
    // Flush rewrite rules per registrare sitemap-agentic-bridge.xml
    flush_rewrite_rules();
    KaliCart_Bridge_Signals::write_well_known_files();

} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
