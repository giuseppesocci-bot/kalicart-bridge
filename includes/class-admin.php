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
        ] );
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
