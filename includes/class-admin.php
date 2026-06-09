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
        add_filter( 'plugins_api', [ __CLASS__, 'plugins_api_info' ], 10, 3 );
        add_filter( 'plugin_row_meta', [ __CLASS__, 'plugin_row_meta' ], 10, 2 );
    }

    public static function register_menu(): void {
        add_menu_page(
            'KaliCart Bridge',
            'KaliCart',
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
            'hint_search'        => (bool) get_option('kalicart_bridge_hint_search', true),
            'hint_zero'          => (bool) get_option('kalicart_bridge_hint_zero', true),
            'hint_category'      => (bool) get_option('kalicart_bridge_hint_category', true),
            'rest_base'     => rest_url( KALICART_BRIDGE_API_NS ),
            'badge_enabled' => (bool) get_option( 'kalicart_bridge_badge_enabled', true ),
            'robots_enabled' => (bool) get_option( 'kalicart_bridge_robots_enabled', true ),
            'sitemap_enabled' => (bool) get_option( 'kalicart_bridge_sitemap_enabled', true ),
            'return_policy_url'  => get_option( 'kalicart_bridge_return_policy_url', '' ),
            'site_url'           => trailingslashit( get_site_url() ),
        ] );
    }

    public static function render_page(): void {
        include KALICART_BRIDGE_DIR . 'admin/admin-page.php';
    }

    public static function plugin_row_meta( array $links, string $file ): array {
        if ( $file !== plugin_basename( KALICART_BRIDGE_FILE ) ) {
            return $links;
        }

        $details_url = add_query_arg( [
            'tab'       => 'plugin-information',
            'plugin'    => 'kalicart-bridge',
            'TB_iframe' => 'true',
            'width'     => '772',
            'height'    => '600',
        ], admin_url( 'plugin-install.php' ) );

        $links[] = sprintf(
            '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
            esc_url( $details_url ),
            esc_html__( 'View details', 'kalicart-bridge' )
        );
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
            'agent_index_url'    => esc_url_raw( filter_input( INPUT_POST, 'agent_index_url', FILTER_SANITIZE_URL ) ?? '' ),
            'return_policy_url'  => esc_url_raw( filter_input( INPUT_POST, 'return_policy_url', FILTER_SANITIZE_URL ) ?? '' ),
        ];

        update_option( 'kalicart_bridge_badge_enabled',   $settings['badge_enabled'] );
        update_option( 'kalicart_bridge_robots_enabled',  $settings['robots_enabled'] );
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

    // ── plugins_api ─────────────────────────────────────────────────────────────

    public static function plugins_api_info( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ( $args->slug ?? '' ) !== 'kalicart-bridge' ) return $result;

        $data = new stdClass();
        $data->name              = 'KaliCart Bridge';
        $data->slug              = 'kalicart-bridge';
        $data->version           = KALICART_BRIDGE_VERSION;
        $data->author            = '<a href="https://kalicart.com">KaliCart</a>';
        $data->homepage          = 'https://bridge.kalicart.com/';
        $data->requires          = '6.0';
        $data->tested            = '7.0';
        $data->requires_php      = '8.0';
        $data->download_link     = 'https://bridge.kalicart.com/download/kalicart-bridge-' . KALICART_BRIDGE_VERSION . '.zip';
        $data->short_description = 'Makes your WooCommerce catalog machine-readable and agent-accessible via structured REST API.';
        $data->sections          = [
            'description'  => self::popup_description(),
            'installation' => self::popup_installation(),
            'faq'          => self::popup_faq(),
            'changelog'    => self::popup_changelog(),
        ];
        return $data;
    }

    private static function popup_description(): string {
        return '<p>KaliCart Bridge exposes your live WooCommerce catalog as a structured REST API for AI shopping agents. Every endpoint reads directly from the WooCommerce database — no sync, no snapshot, no external service.</p>'
            . '<p>Agents discover your catalog via a standardized <code>&lt;link rel="kalicart-agent"&gt;</code> tag injected automatically in every page head, and via an HTML badge in the storefront body.</p>'
            . '<h4>What agents get</h4><ul>'
            . '<li>Normalized products — price (sale, discount %), stock, variants, categories, gender inference, color families, sizes</li>'
            . '<li>Merchant shipping policy — zones, methods, free-shipping thresholds</li>'
            . '<li>Active coupons per product with application rules</li>'
            . '<li>Full category tree with merchant-native WooCommerce taxonomy</li>'
            . '<li>Discovery document with agent instructions and query construction rules</li>'
            . '</ul>'
            . '<h4>Endpoints</h4><ul>'
            . '<li><code>/wp-json/kalicart/v1/discovery</code> — entry point</li>'
            . '<li><code>/wp-json/kalicart/v1/catalog/meta</code> — accepted filters and price range</li>'
            . '<li><code>/wp-json/kalicart/v1/catalog/search</code> — full-text + structured filters</li>'
            . '<li><code>/wp-json/kalicart/v1/catalog/products</code> — paginated listing</li>'
            . '<li><code>/wp-json/kalicart/v1/catalog/product/{id}</code> — single product</li>'
            . '<li><code>/wp-json/kalicart/v1/catalog/categories</code> — category tree</li>'
            . '<li><code>/wp-json/kalicart/v1/catalog/health</code> — catalog quality report (admin only)</li>'
            . '<li><code>/wp-json/kalicart/v1/checkout/session</code> — checkout sessions (optional, off by default)</li>'
            . '</ul>';
    }

    private static function popup_installation(): string {
        return '<ol>'
            . '<li>Upload and activate the plugin. WooCommerce must be active.</li>'
            . '<li>All signals are enabled on activation: <code>&lt;link&gt;</code> tag, badge, robots.txt directive, agentic sitemap.</li>'
            . '<li>Visit <strong>WP Admin → KaliCart</strong> to see the catalog health dashboard and configure badge position.</li>'
            . '<li>Discovery document is immediately live at <code>/wp-json/kalicart/v1/discovery</code>.</li>'
            . '</ol>';
    }

    private static function popup_faq(): string {
        return '<h4>Does this send data to external services?</h4><p>No. All endpoints read live from your WooCommerce database. No data leaves your server.</p>'
            . '<h4>Do I need an API key?</h4><p>No. Public endpoints require no authentication. Only <code>/catalog/health</code> requires WooCommerce admin capability.</p>'
            . '<h4>Are product changes reflected immediately?</h4><p>Yes. Every request reads live from the database — price changes, stock updates and new products are instantly visible.</p>'
            . '<h4>Does it use my WooCommerce categories?</h4><p>Yes. The plugin never remaps your taxonomy — agents receive your native WooCommerce category structure.</p>'
            . '<h4>Can I disable the badge or robots.txt?</h4><p>Yes. All signals can be toggled individually from WP Admin → KaliCart → Settings.</p>';
    }

    private static function popup_changelog(): string {
        // Read from readme.txt == Changelog == section — always in sync with the distributed ZIP
        $readme = KALICART_BRIDGE_DIR . 'readme.txt';
        if ( file_exists( $readme ) ) {
            $content = file_get_contents( $readme );
            if ( $content ) {
                $marker = '== Changelog ==';
                $pos    = strpos( $content, $marker );
                if ( $pos !== false ) {
                    $changelog_raw = substr( $content, $pos + strlen( $marker ) );
                    // Convert WP readme format to HTML
                    $html = '';
                    foreach ( explode( "\n", $changelog_raw ) as $line ) {
                        $line = trim( $line );
                        if ( preg_match( '/^= (.+) =$/', $line, $m ) ) {
                            $html .= '<h4>' . esc_html( $m[1] ) . '</h4><ul>';
                        } elseif ( str_starts_with( $line, '* ' ) ) {
                            $html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
                        } elseif ( $line === '' && str_contains( $html, '<li>' ) ) {
                            $html .= '</ul>';
                        }
                    }
                    if ( $html ) return $html;
                }
            }
        }
        // Fallback
        return '<h4>' . esc_html( KALICART_BRIDGE_VERSION ) . '</h4><ul><li>See changelog.txt for full history.</li></ul>';
    }

    // ── Menu icon ─────────────────────────────────────────────────────────────

    private static function menu_icon_svg(): string {
        // Base64-encoded inline SVG for the menu icon
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<circle cx="12" cy="12" r="3"/>'
            . '<path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M2 12h3M19 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/>'
            . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }
}
