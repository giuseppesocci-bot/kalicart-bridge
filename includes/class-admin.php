<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Admin
 *
 * wp-admin dashboard page: catalog health, quarantine list, endpoint explorer,
 * settings toggles (badge, robots, sitemap).
 */
class KaliCart_Bridge_Admin {

    public static function init(): void {
        add_action( 'admin_menu',             [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_kalicart_health', [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_kalicart_save_settings', [ __CLASS__, 'ajax_save_settings' ] );
        add_filter( 'plugin_row_meta', [ __CLASS__, 'plugin_row_meta' ], 10, 2 );
    }

    public static function register_menu(): void {
        add_menu_page(
            'KaliCart Bridge',
            'KaliCart Bridge',
            'manage_woocommerce',
            'kalicart-bridge',
            [ __CLASS__, 'render_page' ],
            self::menu_icon_svg(),
            58
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_kalicart-bridge' ) return;

        wp_enqueue_style(
            'kalicart-bridge-admin',
            KALICART_BRIDGE_URL . 'admin/assets/admin.css',
            [],
            KALICART_BRIDGE_VERSION
        );

        wp_enqueue_script(
            'kalicart-bridge-admin',
            KALICART_BRIDGE_URL . 'admin/assets/admin.js',
            [ 'wp-util' ],
            KALICART_BRIDGE_VERSION,
            true
        );

        wp_localize_script( 'kalicart-bridge-admin', 'KaliBridge', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'kalicart_bridge' ),
            'badge_position'      => get_option('kalicart_bridge_badge_position','bottom-right'),
            'checkout_enabled'   => (bool) get_option('kalicart_bridge_checkout_enabled', false),
            'well_known_enabled' => (bool) get_option('kalicart_bridge_well_known_enabled', true),
            'agent_hints_enabled' => (bool) get_option('kalicart_bridge_agent_hints_enabled', false),
            'hint_search'        => (bool) get_option('kalicart_bridge_hint_search', false),
            'hint_zero'          => (bool) get_option('kalicart_bridge_hint_zero', false),
            'hint_category'      => (bool) get_option('kalicart_bridge_hint_category', false),
            'rest_base'     => rest_url( KALICART_BRIDGE_API_NS ),
            'badge_enabled' => (bool) get_option( 'kalicart_bridge_badge_enabled', true ),
            'robots_enabled' => (bool) get_option( 'kalicart_bridge_robots_enabled', true ),
            'global_consent' => (bool) get_option( 'kalicart_bridge_global_consent', true ),
            'sitemap_enabled' => (bool) get_option( 'kalicart_bridge_sitemap_enabled', true ),
            'return_policy_url'  => get_option( 'kalicart_bridge_return_policy_url', '' ),
            'site_url'           => trailingslashit( get_site_url() ),
            'i18n'               => self::js_i18n(),
        ] );
    }

    private static function js_i18n(): array {
        return [
            'unknown_error'  => __( 'Unknown error', 'kalicart-bridge' ),
            'error'          => __( 'Error:', 'kalicart-bridge' ),
            'loading'        => __( 'Loading…', 'kalicart-bridge' ),
            'no_issues'      => __( 'No issues. Catalog looks great!', 'kalicart-bridge' ),
            'products'       => __( 'products', 'kalicart-bridge' ),
            'configure'      => __( 'Configure', 'kalicart-bridge' ),
            'updated'        => __( 'Updated:', 'kalicart-bridge' ),
            'no_products_for'        => __( 'No products for', 'kalicart-bridge' ),
            'no_products_quarantine' => __( 'No products in quarantine.', 'kalicart-bridge' ),
            'showing'        => __( 'Showing', 'kalicart-bridge' ),
            'clear_filter'   => __( 'Clear filter', 'kalicart-bridge' ),
            'open_product'   => __( 'Open product', 'kalicart-bridge' ),
            'admin_only'     => __( 'admin only', 'kalicart-bridge' ),
            'preview'        => __( 'Preview', 'kalicart-bridge' ),
            'heads_up'       => __( 'Heads up', 'kalicart-bridge' ),
            'test_link'      => __( 'Test link:', 'kalicart-bridge' ),
            'configured'     => __( 'CONFIGURED', 'kalicart-bridge' ),
            'required'       => __( 'REQUIRED', 'kalicart-bridge' ),
            'why_title'    => __( 'Why product titles matter', 'kalicart-bridge' ),
            'why_title_d'  => __( 'Agents use the title as the first matching signal. Titles should contain at least three useful words, usually product type, brand and model or variant.', 'kalicart-bridge' ),
            'why_desc'     => __( 'Why descriptions matter', 'kalicart-bridge' ),
            'why_desc_d'   => __( 'Descriptions give agents the extra attributes that are often missing from filters, such as fit, material, use case, style and compatibility.', 'kalicart-bridge' ),
            'why_cat'      => __( 'Why categories matter', 'kalicart-bridge' ),
            'why_cat_d'    => __( 'Categories let agents browse and narrow the catalog even when the user does not know the exact product name used by the merchant.', 'kalicart-bridge' ),
            'why_price'    => __( 'Why price matters', 'kalicart-bridge' ),
            'why_price_d'  => __( 'Price is required for budget checks, comparisons and purchase decisions. Products without a valid price cannot be trusted in commerce-intent results.', 'kalicart-bridge' ),
            'why_image'    => __( 'Why images matter', 'kalicart-bridge' ),
            'why_image_d'  => __( 'Images do not block agent queries, but they improve visual verification and reduce ambiguity when products have similar names or variants.', 'kalicart-bridge' ),
            'why_sku'      => __( 'Why SKUs matter', 'kalicart-bridge' ),
            'why_sku_d'    => __( 'SKUs do not block agent queries, but they help identify, deduplicate and reconcile exact products across syncs, variants and downstream systems.', 'kalicart-bridge' ),
            'why_stock'    => __( 'Why stock matters', 'kalicart-bridge' ),
            'why_stock_d'  => __( 'Availability tells agents whether a product can be proposed now or should be excluded from purchase-ready results.', 'kalicart-bridge' ),
            'ep_ucp'        => __( 'UCP profile — dev.ucp.shopping.catalog.search + catalog.lookup (v2026-04-08)', 'kalicart-bridge' ),
            'ep_wellknown'  => __( 'KaliCart Bridge discovery entry point for agents probing well-known paths', 'kalicart-bridge' ),
            'ep_discovery'  => __( 'Discovery document — entry point for every agent, full capability map', 'kalicart-bridge' ),
            'ep_mcp'        => __( 'Model Context Protocol (MCP) server — JSON-RPC 2.0 over POST; the catalog as agent tools (search_products, get_product, list_products, list_categories, get_meta)', 'kalicart-bridge' ),
            'ep_meta'       => __( 'Accepted filter values, category slugs, price range', 'kalicart-bridge' ),
            'ep_search'     => __( 'Full-text + filtered search — supports q, category, on_sale, in_stock, price', 'kalicart-bridge' ),
            'ep_products'   => __( 'Browse products by filters (no text query needed)', 'kalicart-bridge' ),
            'ep_product'    => __( 'Single product — price, stock, variants[], barcodes, purchase_readiness', 'kalicart-bridge' ),
            'ep_categories' => __( 'Full merchant category tree with has_products flag', 'kalicart-bridge' ),
            'ep_health'     => __( 'Catalog quality report — quarantine list, suggestions, scores', 'kalicart-bridge' ),
            'ep_checkout'   => __( 'POST — agent creates cart session, returns cart_url and checkout_url for buyer', 'kalicart-bridge' ),
            'warn_badge'     => __( 'Disabling the AI catalog badge removes a key discovery signal for agents browsing the storefront DOM. Agents that rely on body anchors will not find your catalog.', 'kalicart-bridge' ),
            'warn_robots'    => __( 'Disabling the robots.txt directive removes the crawl permission for AI agents. Some agents check robots.txt before querying any endpoint.', 'kalicart-bridge' ),
            'warn_global'    => __( 'Disabling Global indexing consent removes your catalog from KaliCart Global federated search. Agents using the federated index will no longer discover your products there. Direct agent access to this store stays active.', 'kalicart-bridge' ),
            'warn_sitemap'   => __( 'Disabling the agentic sitemap removes the structured endpoint map that agents use to enumerate your catalog surfaces.', 'kalicart-bridge' ),
            'warn_wellknown' => __( 'Disabling .well-known discovery files removes the first-probe signal used by agents that check standard discovery paths before loading your storefront.', 'kalicart-bridge' ),
        ];
    }

    public static function render_page(): void {
        include KALICART_BRIDGE_DIR . 'admin/admin-page.php';
    }

    public static function plugin_row_meta( array $links, string $file ): array {
        if ( $file !== plugin_basename( KALICART_BRIDGE_FILE ) ) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url( 'https://bridge.kalicart.com/docs/' ),
            esc_html__( 'Documentation', 'kalicart-bridge' )
        );

        return $links;
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'kalicart_bridge', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $force  = (bool) intval( wp_unslash( $_POST['force'] ?? '0' ) );
        $report = KaliCart_Bridge_Quarantine::get_report( $force );
        wp_send_json_success( $report );
    }

    public static function ajax_save_settings(): void {
        check_ajax_referer( 'kalicart_bridge', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $settings = [
            'badge_enabled'   => filter_input( INPUT_POST, 'badge_enabled',   FILTER_VALIDATE_BOOLEAN ),
            'robots_enabled'  => filter_input( INPUT_POST, 'robots_enabled',  FILTER_VALIDATE_BOOLEAN ),
            'global_consent'  => filter_input( INPUT_POST, 'global_consent',  FILTER_VALIDATE_BOOLEAN ),
            'sitemap_enabled'  => filter_input( INPUT_POST, 'sitemap_enabled', FILTER_VALIDATE_BOOLEAN ),
            'badge_position'   => sanitize_text_field( wp_unslash( $_POST['badge_position'] ?? 'bottom-right' ) ),
            'checkout_enabled'  => filter_input( INPUT_POST, 'checkout_enabled',  FILTER_VALIDATE_BOOLEAN ),
            'well_known_enabled' => filter_input( INPUT_POST, 'well_known_enabled', FILTER_VALIDATE_BOOLEAN ),
            'hint_search'        => filter_input( INPUT_POST, 'hint_search',        FILTER_VALIDATE_BOOLEAN ),
            'hint_zero'          => filter_input( INPUT_POST, 'hint_zero',          FILTER_VALIDATE_BOOLEAN ),
            'hint_category'      => filter_input( INPUT_POST, 'hint_category',      FILTER_VALIDATE_BOOLEAN ),
            'agent_index_url'    => esc_url_raw( filter_input( INPUT_POST, 'agent_index_url', FILTER_SANITIZE_URL ) ?? '' ),
            'return_policy_url'  => esc_url_raw( filter_input( INPUT_POST, 'return_policy_url', FILTER_SANITIZE_URL ) ?? '' ),
        ];

        update_option( 'kalicart_bridge_badge_enabled',   $settings['badge_enabled'] );
        update_option( 'kalicart_bridge_robots_enabled',  $settings['robots_enabled'] );
        update_option( 'kalicart_bridge_global_consent',  $settings['global_consent'] );
        update_option( 'kalicart_bridge_sitemap_enabled', $settings['sitemap_enabled'] );
        update_option( 'kalicart_bridge_badge_position',   $settings['badge_position'] );
        update_option( 'kalicart_bridge_checkout_enabled',  $settings['checkout_enabled'] );
        update_option( 'kalicart_bridge_well_known_enabled', $settings['well_known_enabled'] );
        update_option( 'kalicart_bridge_hint_search',        $settings['hint_search'] );
        update_option( 'kalicart_bridge_hint_zero',          $settings['hint_zero'] );
        update_option( 'kalicart_bridge_hint_category',      $settings['hint_category'] );
        update_option( 'kalicart_bridge_agent_index_url',    $settings['agent_index_url'] ?: null );
        update_option( 'kalicart_bridge_return_policy_url',  $settings['return_policy_url'] ?: null );
        if ( $settings['well_known_enabled'] ) {
            KaliCart_Bridge_Signals::write_well_known_files();
        }

        // Flush rewrite rules if sitemap setting changed
        flush_rewrite_rules();

        wp_send_json_success( [ 'saved' => true ] );
    }


    // ── Menu icon    // ── Menu icon ─────────────────────────────────────────────────────────────

    private static function menu_icon_svg(): string {
        // Base64-encoded inline SVG for the menu icon
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 1196" fill="#f3f1f1">'
            . '<rect x="280" y="184" width="212" height="824"/>'
            . '<path d="M677 184H900V411L720 504Z"/>'
            . '<path d="M575 691L780 568L1018 1008H790Z"/>'
            . '<path d="M900 411L780 568L575 691L720 504Z"/>'
            . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }
}
