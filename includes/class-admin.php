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
        add_action( 'wp_ajax_kalicart_federation_activate', [ __CLASS__, 'ajax_federation_activate' ] );
        add_action( 'wp_ajax_kalicart_federation_revoke',   [ __CLASS__, 'ajax_federation_revoke' ] );
        add_action( 'wp_ajax_kalicart_external_visibility_check', [ __CLASS__, 'ajax_external_visibility_check' ] );
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
            'productsUrl' => admin_url( 'edit.php?post_type=product' ),
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
            'badge_enabled' => (bool) get_option( 'kalicart_bridge_badge_enabled', false ),
            'robots_enabled' => (bool) get_option( 'kalicart_bridge_robots_enabled', true ),
            'global_consent' => (bool) get_option( 'kalicart_bridge_global_consent', false ),
            'federation_registered_at' => get_option( 'kalicart_bridge_federation_registered_at', '' ),
            'sitemap_enabled' => (bool) get_option( 'kalicart_bridge_sitemap_enabled', true ),
            'return_policy_url'  => get_option( 'kalicart_bridge_return_policy_url', '' ),
            'coupons_agent_enabled'   => (bool) get_option( 'kalicart_bridge_coupons_agent_enabled', false ),
            'coupons_agent_whitelist' => array_map( 'intval', (array) get_option( 'kalicart_bridge_coupons_agent_whitelist', [] ) ),
            'coupons_eligible'        => self::eligible_coupons_for_ui(),
            'currency'                => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
            'site_url'           => trailingslashit( get_site_url() ),
            'i18n'               => self::js_i18n(),
        ] );
    }

    /**
     * Coupon eligible for the agent-exposure UI list.
     * Eligible = published + currently active (not expired, usage limit not reached).
     * Product-level applicability is intentionally NOT checked here: the read path
     * decides per-product. Expired coupons simply do not appear.
     *
     * @return array<int,array{id:int,code:string,type:string,amount:float}>
     */
    private static function eligible_coupons_for_ui(): array {
        if ( ! class_exists( 'WC_Coupon' ) ) return [];

        $posts = get_posts( [
            'post_type'   => 'shop_coupon',
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );
        if ( empty( $posts ) ) return [];

        $out = [];
        foreach ( $posts as $post ) {
            $coupon = new WC_Coupon( $post->post_name );
            if ( ! $coupon || ! $coupon->get_code() ) continue;

            // Same activity gate as the read path (expiry + usage limit), inlined to
            // avoid coupling the admin class to the catalog engine internals.
            $expires = $coupon->get_date_expires();
            if ( $expires && $expires->getTimestamp() < time() ) continue;
            $limit = $coupon->get_usage_limit();
            if ( $limit && $coupon->get_usage_count() >= $limit ) continue;

            $out[] = [
                'id'     => (int) $post->ID,
                'code'   => $coupon->get_code(),
                'type'   => $coupon->get_discount_type(),
                'amount' => (float) $coupon->get_amount(),
            ];
        }
        return $out;
    }

    private static function js_i18n(): array {
        return [
            'unknown_error'  => __( 'Unknown error', 'kalicart-bridge' ),
            'federation_registered'        => __( 'Registered on', 'kalicart-bridge' ),
            'federation_consent_required'  => __( 'Tick the consent box above first.', 'kalicart-bridge' ),
            'federation_activate_failed'   => __( 'Activation failed. Please try again.', 'kalicart-bridge' ),
            'external_check_failed'        => __( 'Could not reach KaliCart Global. Try again in a moment.', 'kalicart-bridge' ),
            'external_check_not_probed'    => __( 'Not observed from outside yet. Activate the Federated Catalog above to trigger a check.', 'kalicart-bridge' ),
            'external_check_label_reachable'   => __( 'Discovery reachable from outside:', 'kalicart-bridge' ),
            'external_check_label_detected'    => __( 'Bridge detected:', 'kalicart-bridge' ),
            'external_check_label_checked'     => __( 'Last checked:', 'kalicart-bridge' ),
            'external_check_reachable'     => __( 'Reachable', 'kalicart-bridge' ),
            'external_check_unreachable'   => __( 'Not reachable', 'kalicart-bridge' ),
            'external_check_never'         => __( 'Never checked', 'kalicart-bridge' ),
            'external_check_stale'         => __( 'This observation is more than 7 days old \u2014 consider re-activating to refresh it.', 'kalicart-bridge' ),
            'external_check_ago_days'      => __( 'days ago', 'kalicart-bridge' ),
            'external_check_ago_hours'     => __( 'hours ago', 'kalicart-bridge' ),
            'external_check_ago_now'       => __( 'just now', 'kalicart-bridge' ),
            'yes'                           => __( 'Yes', 'kalicart-bridge' ),
            'no'                            => __( 'No', 'kalicart-bridge' ),
            'error'          => __( 'Error:', 'kalicart-bridge' ),
            'loading'        => __( 'Loading…', 'kalicart-bridge' ),
            'no_issues'      => __( 'No issues. Catalog looks great!', 'kalicart-bridge' ),
            'products'       => __( 'products', 'kalicart-bridge' ),
            'configure'      => __( 'Configure', 'kalicart-bridge' ),
            'updated'        => __( 'Updated:', 'kalicart-bridge' ),
            'no_products_for'        => __( 'No products for', 'kalicart-bridge' ),
            'no_products_quarantine' => __( 'No products in quarantine.', 'kalicart-bridge' ),
            'showing'        => __( 'Showing', 'kalicart-bridge' ),
            /* translators: 1: number of items shown, 2: total number of items */
            'showing_first'  => __( 'Showing the most recent %1$s of %2$s - use the filters below for the full list.', 'kalicart-bridge' ),
            'critical_signals' => __( 'Critical signals', 'kalicart-bridge' ),
            'improvements'     => __( 'Improvements', 'kalicart-bridge' ),
            'btn_title'        => __( 'Short titles', 'kalicart-bridge' ),
            'btn_description'  => __( 'No description', 'kalicart-bridge' ),
            'btn_category'     => __( 'No category', 'kalicart-bridge' ),
            'btn_price'        => __( 'No price', 'kalicart-bridge' ),
            'btn_image'        => __( 'No image', 'kalicart-bridge' ),
            'btn_sku'          => __( 'No SKU', 'kalicart-bridge' ),
            'btn_stock'        => __( 'Out of stock', 'kalicart-bridge' ),
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
            'warn_badge'     => __( 'The AI catalog badge is an optional body-DOM discovery anchor and is off by default. Head-reading agents, the REST API, MCP and the ChatGPT feed do not depend on it.', 'kalicart-bridge' ),
            'warn_robots'    => __( 'Disabling the robots.txt directive removes the crawl permission for AI agents. Some agents check robots.txt before querying any endpoint.', 'kalicart-bridge' ),
            'warn_global'    => __( 'Disabling Global indexing consent removes your catalog from KaliCart Global federated search. Agents using the federated index will no longer discover your products there. Direct agent access to this store stays active.', 'kalicart-bridge' ),
            'warn_sitemap'   => __( 'Disabling the agentic sitemap removes the structured endpoint map that agents use to enumerate your catalog surfaces.', 'kalicart-bridge' ),
            'warn_wellknown' => __( 'Disabling .well-known discovery files removes the first-probe signal used by agents that check standard discovery paths before loading your storefront.', 'kalicart-bridge' ),
            'warn_coupons'   => __( 'When enabled, only the coupons you tick below are exposed to agents. Selected coupons appear in catalog results as conditional checkout savings; WooCommerce checkout remains the final authority on validity. Coupons you do not tick — including private or targeted codes — are never sent to agents.', 'kalicart-bridge' ),
            'coupons_none'   => __( 'No active coupons available to expose.', 'kalicart-bridge' ),
            'coupons_hint'   => __( 'Tick the coupons you want agents to see. Expired or used-up coupons are not listed.', 'kalicart-bridge' ),
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
            'sitemap_enabled'  => filter_input( INPUT_POST, 'sitemap_enabled', FILTER_VALIDATE_BOOLEAN ),
            'badge_position'   => sanitize_text_field( wp_unslash( $_POST['badge_position'] ?? 'bottom-right' ) ),
            'checkout_enabled'  => filter_input( INPUT_POST, 'checkout_enabled',  FILTER_VALIDATE_BOOLEAN ),
            'well_known_enabled' => filter_input( INPUT_POST, 'well_known_enabled', FILTER_VALIDATE_BOOLEAN ),
            'hint_search'        => filter_input( INPUT_POST, 'hint_search',        FILTER_VALIDATE_BOOLEAN ),
            'hint_zero'          => filter_input( INPUT_POST, 'hint_zero',          FILTER_VALIDATE_BOOLEAN ),
            'hint_category'      => filter_input( INPUT_POST, 'hint_category',      FILTER_VALIDATE_BOOLEAN ),
            'return_policy_url'  => esc_url_raw( filter_input( INPUT_POST, 'return_policy_url', FILTER_SANITIZE_URL ) ?? '' ),
            'coupons_agent_enabled' => filter_input( INPUT_POST, 'coupons_agent_enabled', FILTER_VALIDATE_BOOLEAN ),
        ];

        // Coupon whitelist: comma-separated coupon POST IDs, sanitized to a clean int array.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each id is run through absint() below.
        $coupon_ids_raw = wp_unslash( $_POST['coupons_agent_whitelist'] ?? '' );
        $coupon_ids = array_values( array_unique( array_filter( array_map(
            'absint',
            explode( ',', is_string( $coupon_ids_raw ) ? $coupon_ids_raw : '' )
        ) ) ) );

        update_option( 'kalicart_bridge_badge_enabled',   $settings['badge_enabled'] );
        update_option( 'kalicart_bridge_robots_enabled',  $settings['robots_enabled'] );
        update_option( 'kalicart_bridge_sitemap_enabled', $settings['sitemap_enabled'] );
        update_option( 'kalicart_bridge_badge_position',   $settings['badge_position'] );
        update_option( 'kalicart_bridge_checkout_enabled',  $settings['checkout_enabled'] );
        update_option( 'kalicart_bridge_well_known_enabled', $settings['well_known_enabled'] );
        update_option( 'kalicart_bridge_hint_search',        $settings['hint_search'] );
        update_option( 'kalicart_bridge_hint_zero',          $settings['hint_zero'] );
        update_option( 'kalicart_bridge_hint_category',      $settings['hint_category'] );        update_option( 'kalicart_bridge_return_policy_url',  $settings['return_policy_url'] ?: null );
        update_option( 'kalicart_bridge_coupons_agent_enabled', $settings['coupons_agent_enabled'] );
        update_option( 'kalicart_bridge_coupons_agent_whitelist', $coupon_ids );
        if ( $settings['well_known_enabled'] ) {
            KaliCart_Bridge_Signals::write_well_known_files();
        }

        // Flush rewrite rules if sitemap setting changed
        flush_rewrite_rules();

        wp_send_json_success( [ 'saved' => true ] );
    }

    /**
     * Federation activation: explicit opt-in. On click, with consent ON, the plugin
     * announces the public site URL to KaliCart Global (POST /v1/bridge/announce).
     * Server-side wp_remote_post, HTTPS. The ONLY datum sent is the public site URL.
     * Disclosed in readme "External services" + privacy policy.
     */
    public static function ajax_federation_activate(): void {
        check_ajax_referer( 'kalicart_bridge', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        // Il click su Attiva E' l'atto di consenso esplicito e informato (disclosure + privacy
        // link sono nel blocco sopra il bottone). Accende il consenso PRIMA dell'announce, cosi'
        // il discovery JSON pubblica ON quando il probe arriva a leggerlo.
        update_option( 'kalicart_bridge_global_consent', true );

        $site_url = trailingslashit( get_site_url() );
        $resp = wp_remote_post( KALICART_BRIDGE_GLOBAL . '/v1/bridge/announce', [
            'timeout'   => 8,
            'sslverify' => true,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [ 'domain' => $site_url ] ),
        ] );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'reason' => 'announce_failed', 'detail' => $resp->get_error_message() ], 502 );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'reason' => 'announce_http_' . $code ], 502 );
        }
        update_option( 'kalicart_bridge_federation_registered_at', gmdate( 'c' ) );
        wp_send_json_success( [ 'registered_at' => get_option( 'kalicart_bridge_federation_registered_at' ), 'consent' => true ] );
    }

    /**
     * External Agent Visibility Check (2026-07-12).
     * Read-only: does NOT trigger a new probe, does NOT touch federation consent.
     * Surfaces what KaliCart Global's own maintenance/announce probe last observed
     * from OUTSIDE this site (discovery reachability, Bridge detection) - the same
     * data an external agent's request would depend on. Trigger for a fresh probe
     * remains "Activate Federated Catalog" above; this only reads the result.
     * Server-side wp_remote_get, HTTPS. The ONLY datum sent is the public site URL,
     * as a query parameter (no body, no credentials).
     */
    public static function ajax_external_visibility_check(): void {
        check_ajax_referer( 'kalicart_bridge', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $site_url = trailingslashit( get_site_url() );
        $resp = wp_remote_get( KALICART_BRIDGE_GLOBAL . '/v1/bridge/status?domain=' . rawurlencode( $site_url ), [
            'timeout'   => 8,
            'sslverify' => true,
            'headers'   => [ 'Accept' => 'application/json' ],
        ] );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'reason' => 'status_failed', 'detail' => $resp->get_error_message() ], 502 );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'reason' => 'status_http_' . $code ], 502 );
        }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $data ) || empty( $data['ok'] ) ) {
            wp_send_json_error( [ 'reason' => 'status_invalid_response' ], 502 );
        }
        update_option( 'kalicart_bridge_last_external_check', $data );
        wp_send_json_success( $data );
    }

    /**
     * Federation revoke: explicit opt-out. ORDER IS LOAD-BEARING:
     *   1) turn the local consent flag OFF -> the public discovery document now
     *      publishes allow_global_indexing=false (the source of truth for the probe);
     *   2) THEN notify Global (POST /v1/bridge/deregister) to park immediately.
     * If step 2 fails, consent is already OFF and the next probe confirms the revoke,
     * so the merchant is never left silently re-included. Fail-safe by construction.
     */
    public static function ajax_federation_revoke(): void {
        check_ajax_referer( 'kalicart_bridge', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        // (1) spegni il consenso PRIMA: il discovery JSON pubblica OFF da subito.
        update_option( 'kalicart_bridge_global_consent', false );

        // (2) push di deregister per il parcheggio immediato (best-effort).
        $site_url = trailingslashit( get_site_url() );
        $resp = wp_remote_post( KALICART_BRIDGE_GLOBAL . '/v1/bridge/deregister', [
            'timeout'   => 8,
            'sslverify' => true,
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [ 'domain' => $site_url ] ),
        ] );
        delete_option( 'kalicart_bridge_federation_registered_at' );

        // Il consenso e' gia' OFF: anche se il push fallisce, la revoca e' garantita al probe.
        $pushed = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) < 300;
        wp_send_json_success( [ 'consent' => false, 'pushed' => $pushed ] );
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
