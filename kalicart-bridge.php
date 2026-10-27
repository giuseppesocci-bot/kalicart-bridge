<?php
/**
 * Plugin Name:       KaliCart Bridge – Agentic Commerce Catalog, ChatGPT Product Feed & MCP
 * Plugin URI:        https://bridge.kalicart.com
 * Description:       Makes your WooCommerce catalog agent-readable: product feed checked against the OpenAI spec, catalog REST API and MCP server, with the optional KaliCart Global connection. No LLM inside the plugin.
 * Version:           1.0.154
 * Author:            KaliCart
 * Author URI:        https://kalicart.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kalicart-bridge
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.1
 * WC requires at least: 7.0
 * WC tested up to:      11.1
 * Requires Plugins:    woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KALICART_BRIDGE_VERSION', '1.0.154' );
// Version of the catalog DATA format (fields, normalisation, exclusion/quarantine rules),
// independent of the plugin version. Bump it ONLY when what the catalog returns changes:
// KaliCart Global re-reads every catalog in full when this value changes, not on every
// plugin release (compatibility, text or lifecycle releases leave it unchanged).
if ( ! defined( 'KALICART_BRIDGE_CATALOG_DATA_VERSION' ) ) {
	define( 'KALICART_BRIDGE_CATALOG_DATA_VERSION', 5 ); // 5: Woo shop-display price and tax basis; re-read unchanged products.
}
define( 'KALICART_BRIDGE_FILE',    __FILE__ );
define( 'KALICART_BRIDGE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'KALICART_BRIDGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'KALICART_BRIDGE_API_NS',  'kalicart/v1' );
define( 'KALICART_BRIDGE_GLOBAL',  'https://dashboard.kalicart.com' ); // federation endpoint (announce/deregister)

/**
 * Remove only the technical lifecycle state created by the external 1.0.122
 * release. Merchant consent, registration and all other settings are preserved.
 */
function kalicart_bridge_cleanup_external_release_state(): void {
	if ( '1.0.122' !== get_option( 'kalicart_bridge_federation_lifecycle_version', '' ) ) {
		return;
	}

	wp_clear_scheduled_hook( 'kalicart_bridge_federation_announce' );
	delete_option( 'kalicart_bridge_federation_lifecycle_version' );
	delete_option( 'kalicart_bridge_federation_announce_attempts' );
	delete_option( 'kalicart_bridge_federation_last_error' );
}
add_action( 'init', 'kalicart_bridge_cleanup_external_release_state', 1 );

/**
 * Plugin UI language follows the WordPress site locale. Bundled languages:
 * English, French, Italian, German, Spanish. Any other locale falls back to
 * English (the source strings). This affects ONLY the plugin's own
 * human-facing strings — never catalog/agent data.
 */

add_action( 'init', function () {
    load_plugin_textdomain( 'kalicart-bridge', false, dirname( plugin_basename( KALICART_BRIDGE_FILE ) ) . '/languages' );
} );

require_once KALICART_BRIDGE_DIR . 'includes/class-rate-guard.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-catalog-engine.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-quarantine.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-api.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-mcp.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-signals.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-acp-feed.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-commerce-consent.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-federation-consent.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-site-health.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-push-notice.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-identity.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-snapshot-profile.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-snapshot.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-admin.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-checkout.php';
require_once KALICART_BRIDGE_DIR . 'includes/class-checkout-quote.php';
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

    // 1.0.154 one-time migration: existing installs stored `false` at activation, so "never chosen" cannot be
    // told apart from "turned off". Turn quote and checkout handoff on once and never run again: a merchant who
    // turns them off afterwards stays off.
    if ( 'done' !== get_option( 'kalicart_bridge_commerce_defaults_1_0_154', '' ) ) {
        update_option( 'kalicart_bridge_quote_enabled', true );
        update_option( 'kalicart_bridge_checkout_enabled', true );
        update_option( 'kalicart_bridge_commerce_defaults_1_0_154', 'done', false );
    }
    KaliCart_Bridge_API::init();
    KaliCart_Bridge_MCP::init();
    KaliCart_Bridge_Signals::init();
    KaliCart_Bridge_ACP_Feed::init();
    KaliCart_Bridge_Commerce_Consent::init();
    KaliCart_Bridge_Identity::init();
    KaliCart_Bridge_Snapshot::init();
    KaliCart_Bridge_Federation_Consent::init(); // privacy policy guide text
    KaliCart_Bridge_Site_Health::init();
    KaliCart_Bridge_Push_Notice::init(); // 1.0.139: off (PUSH_AVAILABLE=false), hooks nothing
    // Version-gated migration — runs once per plugin version, on init (needs $wp_rewrite).
    // Removes legacy extension-less static files (served as text/plain by the webserver)
    // and refreshes rewrite rules so serve_well_known() answers on every install.
    // 1.0.143: activation only leaves a pending flag; the single soft refresh
    // happens here, after our rules were registered at init priority 10.
    add_action( 'init', function () {
        $version_changed = get_option( 'kalicart_bridge_wk_version' ) !== KALICART_BRIDGE_VERSION;
        $rewrite_pending = (bool) get_option( 'kalicart_bridge_rewrite_pending', false );
        if ( ! $version_changed && ! $rewrite_pending ) {
            return;
        }
        if ( $version_changed ) {
            if ( get_option( 'kalicart_bridge_well_known_enabled', true ) ) {
                KaliCart_Bridge_Signals::write_well_known_files();
            } else {
                KaliCart_Bridge_Signals::remove_well_known_files();
            }
            KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();
        }
        // The refresh below is deferred by WordPress to wp_loaded. Keep (or set)
        // the pending flag until it has really completed, so a request that dies
        // before then is retried on the next one, upgrades included.
        if ( ! $rewrite_pending ) {
            update_option( 'kalicart_bridge_rewrite_pending', 1, false );
        }
        // Soft refresh only, never an empty rule set: WordPress rebuilds the
        // stored rules at wp_loaded while the previous rules stay in place, so
        // no other plugin sees empty rules and requests a hard (.htaccess) flush.
        flush_rewrite_rules( false );
        // Runs after the core refresh (wp_loaded, priority 10): confirmation only.
        add_action( 'wp_loaded', static function () {
            delete_option( 'kalicart_bridge_rewrite_pending' );
        }, PHP_INT_MAX );
        if ( $version_changed ) {
            KaliCart_Bridge_Identity::request_reentry();
            KaliCart_Bridge_Snapshot::reconcile_lifecycle();
            update_option( 'kalicart_bridge_wk_version', KALICART_BRIDGE_VERSION );
        }
    }, 20 );
    KaliCart_Bridge_Quarantine::init_hooks();
    KaliCart_Bridge_Shortcodes::init();
    if ( get_option( 'kalicart_bridge_checkout_enabled', false ) ) {
        KaliCart_Bridge_Checkout::init();
    }
    KaliCart_Bridge_Quote::init();

    if ( is_admin() ) {
        KaliCart_Bridge_Admin::init();
    }
} );

// ── JSON float serialization fix ────────────────────────────────────────────
// PHP hosts with serialize_precision != -1 (e.g. =17) cause json_encode to emit
// full IEEE 754 float representations (e.g. 4.9000000000000003552...).
// ini_set( 'serialize_precision', -1 ) is the only reliable cross-host fix;
// it is scoped to /kalicart/v1/ REST responses only and does not affect other plugins.
add_filter( 'rest_post_dispatch', function ( $result, $server, $request ) {
    if ( strpos( $request->get_route(), '/kalicart/v1/' ) !== false ) {
        ini_set( 'serialize_precision', -1 ); // phpcs:ignore WordPress.PHP.IniSet.Risky
    }
    return $result;
}, 10, 3 );

// ── Catalog facets cron ─────────────────────────────────────────────────────
// Rebuilds available_genders / available_colors every 6 hours.
// Heavy computation (O(n) over all products) — must never run inline on web requests.
add_action( 'kalicart_bridge_facets_rebuild', function ( $scheduled_lang = null ) {
    if ( ! class_exists( 'KaliCart_Bridge_Catalog_Engine' ) ) return;
	$lang = is_string( $scheduled_lang ) && '' !== $scheduled_lang
		? sanitize_key( $scheduled_lang )
		: KaliCart_Bridge_API::default_language();
    KaliCart_Bridge_Catalog_Engine::compute_catalog_facets( $lang );
}, 10, 1 );

if ( ! wp_next_scheduled( 'kalicart_bridge_facets_rebuild' ) ) {
    wp_schedule_event( time(), 'twicedaily', 'kalicart_bridge_facets_rebuild' );
}

// ── Checkout session claim cleanup cron ─────────────────────────────────────
// The atomic claim rows written by KaliCart_Bridge_Checkout::attribute_order()
// (kalicart_session_claimed_{id}, non-autoload) must not accumulate forever in
// wp_options: one row per attributed checkout, with no natural expiry of its own
// (unlike transients). Sweeps rows older than the retention window daily.
add_action( 'kalicart_bridge_cleanup_claims', function () {
    if ( class_exists( 'KaliCart_Bridge_Checkout' ) ) {
        KaliCart_Bridge_Checkout::cleanup_stale_claims();
    }
} );

if ( ! wp_next_scheduled( 'kalicart_bridge_cleanup_claims' ) ) {
    wp_schedule_event( time(), 'daily', 'kalicart_bridge_cleanup_claims' );
}

function kalicart_bridge_ensure_default_options(): void {
    add_option( 'kalicart_bridge_badge_enabled',       false );
    add_option( 'kalicart_bridge_badge_position',      'bottom-right' );
    add_option( 'kalicart_bridge_robots_enabled',      true );
    add_option( 'kalicart_bridge_sitemap_enabled',     true );
    // 1.0.154: delivered-total quote and checkout handoff are on by default (merchant can turn them off).
    add_option( 'kalicart_bridge_checkout_enabled',    true );
    add_option( 'kalicart_bridge_quote_enabled',       true );
    add_option( 'kalicart_bridge_well_known_enabled',  true );
    // Agent hints (DOM signals): opt-in, default OFF.
    add_option( 'kalicart_bridge_agent_hints_enabled', false );
    add_option( 'kalicart_bridge_hint_search',         false );
    add_option( 'kalicart_bridge_hint_zero',           false );
    add_option( 'kalicart_bridge_hint_category',       false );
}

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

    kalicart_bridge_ensure_default_options();
    KaliCart_Bridge_Commerce_Consent::ensure_schema();
    // Activation runs after init, so our rewrite rules have not been registered
    // in this request. Never empty the stored rules (an empty set makes other
    // plugins request a hard .htaccess flush): leave a local flag and let the
    // init callback refresh softly on the next request.
    update_option( 'kalicart_bridge_rewrite_pending', 1, false );
    // 1.0.144: queue one signed heartbeat soon (background) so Global can restore the store.
    KaliCart_Bridge_Identity::request_reentry();
    KaliCart_Bridge_Snapshot::reconcile_lifecycle();
    if ( get_option( 'kalicart_bridge_well_known_enabled', true ) ) {
        KaliCart_Bridge_Signals::write_well_known_files();
    }

} );

/**
 * Runs only after WordPress has had the opportunity to persist the inactive
 * plugin list. Every operation is local, finite and isolated: cleanup failure
 * must never turn a completed deactivation into an HTTP 500.
 */
function kalicart_bridge_deactivation_shutdown(): void {
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}

	// A registered deactivation callback does not prove that WordPress committed
	// the new active-plugin list: another callback may have failed later in the
	// same request, or the option update itself may have failed. Refresh both
	// caches and leave every artifact untouched unless deactivation is real.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'active_plugins', 'options' );
	$plugin        = plugin_basename( KALICART_BRIDGE_FILE );
	$active        = (array) get_option( 'active_plugins', [] );
	$network_active = is_multisite()
		? (array) get_site_option( 'active_sitewide_plugins', [] )
		: [];
	if ( in_array( $plugin, $active, true ) || isset( $network_active[ $plugin ] ) ) {
		return;
	}

	foreach ( [
		static function (): void {
			foreach ( [
				'kalicart_bridge_facets_rebuild',
				'kalicart_bridge_cleanup_claims',
				'kalicart_bridge_acp_feed_generate',
				KaliCart_Bridge_Commerce_Consent::RETRY_HOOK,
				KaliCart_Bridge_Identity::CRON_HOOK,
				KaliCart_Bridge_Identity::REENTRY_HOOK,
				KaliCart_Bridge_Identity::SNAPSHOT_HEARTBEAT_HOOK,
			] as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		},
		static function (): void {
			KaliCart_Bridge_Snapshot::deactivation_cleanup();
		},
		static function (): void {
			KaliCart_Bridge_Signals::remove_well_known_files();
		},
	] as $cleanup ) {
		try {
			$cleanup();
		} catch ( \Throwable $e ) {
			// Best effort after deactivation is committed. Never call the network,
			// emit output or rethrow from this path.
			continue;
		}
	}
}

/** The critical callback deliberately performs no persistent write. */
function kalicart_bridge_schedule_deactivation_cleanup(): void {
	register_shutdown_function( 'kalicart_bridge_deactivation_shutdown' );
}

register_deactivation_hook( __FILE__, 'kalicart_bridge_schedule_deactivation_cleanup' );
