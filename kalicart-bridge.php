<?php
/**
 * Plugin Name:       KaliCart Bridge
 * Plugin URI:        https://bridge.kalicart.com
 * Description:       Makes your WooCommerce catalog machine-readable and agent-accessible. Exposes normalized product data via REST API — no LLM, no external service, no cloud dependency.
 * Version:           1.0.100
 * Author:            KaliCart
 * Author URI:        https://kalicart.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kalicart-bridge
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.0
 * WC requires at least: 7.0
 * WC tested up to:      10.8
 * Requires Plugins:    woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KALICART_BRIDGE_VERSION', '1.0.100' );
define( 'KALICART_BRIDGE_FILE',    __FILE__ );
define( 'KALICART_BRIDGE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'KALICART_BRIDGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'KALICART_BRIDGE_API_NS',  'kalicart/v1' );
define( 'KALICART_BRIDGE_GLOBAL',  'https://dashboard.kalicart.com' ); // federation endpoint (announce/deregister)

/**
 * Plugin UI language follows the WordPress site locale. Bundled languages:
 * English, French, Italian, German, Spanish. Any other locale falls back to
 * English (the source strings). This affects ONLY the plugin's own
 * human-facing strings — never catalog/agent data.
 */

add_action( 'init', function () {
    load_plugin_textdomain( 'kalicart-bridge', false, dirname( plugin_basename( KALICART_BRIDGE_FILE ) ) . '/languages' );
} );

require_once KALICART_BRIDGE_DIR . 'includes/class-catalog-engine.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-quarantine.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-api.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-mcp.php';
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
    KaliCart_Bridge_MCP::init();
    KaliCart_Bridge_Signals::init();
    // Version-gated migration — runs once per plugin version, on init (needs $wp_rewrite).
    // Removes legacy extension-less static files (served as text/plain by the webserver)
    // and flushes rewrite rules so serve_well_known() answers on every install.
    add_action( 'init', function () {
        if ( get_option( 'kalicart_bridge_wk_version' ) === KALICART_BRIDGE_VERSION ) {
            return;
        }
        if ( get_option( 'kalicart_bridge_well_known_enabled', true ) ) {
            KaliCart_Bridge_Signals::write_well_known_files();
        } else {
            KaliCart_Bridge_Signals::cleanup_well_known_static_files();
        }
        flush_rewrite_rules();
        update_option( 'kalicart_bridge_wk_version', KALICART_BRIDGE_VERSION );
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
    // Hard dependency guard: refuse activation when WooCommerce is not active.
    // Covers WordPress < 6.5, where the "Requires Plugins" header is ignored.
    // On WP 6.5+ this is redundant (core disables the Activate button) but harmless.
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'KaliCart Bridge requires WooCommerce to be installed and active. Please activate WooCommerce first, then activate KaliCart Bridge.', 'kalicart-bridge' ),
            esc_html__( 'WooCommerce required', 'kalicart-bridge' ),
            [ 'back_link' => true ]
        );
    }

    update_option( 'kalicart_bridge_badge_enabled',   true );
    update_option( 'kalicart_bridge_badge_position',  'bottom-right' );
    update_option( 'kalicart_bridge_robots_enabled',  true );
    update_option( 'kalicart_bridge_sitemap_enabled', true );
    update_option( 'kalicart_bridge_checkout_enabled', false );
    update_option( 'kalicart_bridge_well_known_enabled', true );
    // Agent hints (DOM signals): opt-in, default OFF — merchant activates when desired
    update_option( 'kalicart_bridge_agent_hints_enabled', false );
    update_option( 'kalicart_bridge_hint_search',      false );
    update_option( 'kalicart_bridge_hint_zero',        false );
    update_option( 'kalicart_bridge_hint_category',    false );
    // Flush rewrite rules per registrare sitemap-agentic-bridge.xml
    flush_rewrite_rules();
    KaliCart_Bridge_Signals::write_well_known_files();

} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
