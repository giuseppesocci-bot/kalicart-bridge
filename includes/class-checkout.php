<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Checkout
 *
 * Checkout session endpoints — optional, toggled by merchant in settings.
 *
 * POST   /wp-json/kalicart/v1/checkout/session       — create session (single or multi-product)
 * GET    /wp-json/kalicart/v1/checkout/session/{id}  — get session
 * DELETE /wp-json/kalicart/v1/checkout/session/{id}  — cancel session
 *
 * Each session returns:
 *   cart_url     — adds all items to cart, lands on WooCommerce cart page (review)
 *   checkout_url — adds all items to cart, redirects directly to checkout
 *
 * No OAuth, no PII, no payment on the agent side.
 */
class KaliCart_Bridge_Checkout {

    const SESSION_TTL = 30 * MINUTE_IN_SECONDS;

    public static function init(): void {
        add_action( 'rest_api_init',     [ __CLASS__, 'register_routes' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_session_redirect' ] );
    }

    public static function register_routes(): void {
        $ns = KALICART_BRIDGE_API_NS;

        register_rest_route( $ns, '/checkout/session', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_session' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public — agent creates session, human pays
        ] );

        register_rest_route( $ns, '/checkout/session/(?P<id>[a-f0-9]{32})', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_session' ],
                'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'cancel_session' ],
                'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public
            ],
        ] );
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public static function create_session( WP_REST_Request $req ): WP_REST_Response {
        $body = $req->get_json_params();

        // Supporta sia array di prodotti che singolo prodotto
        if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
            $raw_items = $body['items'];
        } elseif ( isset( $body['product_id'] ) ) {
            // Retrocompatibilità — singolo prodotto
            $raw_items = [ [
                'product_id'   => $body['product_id'],
                'quantity'     => $body['quantity'] ?? 1,
                'variation_id' => $body['variation_id'] ?? 0,
            ] ];
        } else {
            return self::error( 'Provide either "items" array or "product_id".', 400 );
        }

        if ( empty( $raw_items ) || count( $raw_items ) > 20 ) {
            return self::error( 'items must contain between 1 and 20 products.', 400 );
        }

        // Valida e normalizza ogni item
        $items      = [];
        $line_total = 0.0;

        foreach ( $raw_items as $idx => $raw ) {
            $product_id   = absint( $raw['product_id'] ?? 0 );
            $quantity     = max( 1, absint( $raw['quantity'] ?? 1 ) );
            $variation_id = absint( $raw['variation_id'] ?? 0 );

            if ( ! $product_id ) {
                return self::error( "items[$idx]: product_id is required.", 400 );
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || $product->get_status() !== 'publish' ) {
                return self::error( "items[$idx]: product $product_id not found.", 404 );
            }
            if ( ! $product->is_in_stock() ) {
                return self::error( "items[$idx]: product $product_id is out of stock.", 409 );
            }
            if ( $product->is_type( 'variable' ) && ! $variation_id ) {
                return self::error(
                    "items[$idx]: variable product $product_id requires variation_id. GET /catalog/product/$product_id to see available variations.",
                    422
                );
            }

            $price_product = $variation_id ? wc_get_product( $variation_id ) : $product;
            if ( ! $price_product ) {
                return self::error( "items[$idx]: variation $variation_id not found.", 404 );
            }

            $unit_price  = (float) $price_product->get_price();
            $item_total  = $unit_price * $quantity;
            $line_total += $item_total;

            $items[] = [
                'product_id'   => $product_id,
                'product_name' => $product->get_name(),
                'product_url'  => get_permalink( $product_id ),
                'variation_id' => $variation_id ?: null,
                'quantity'     => $quantity,
                'unit_price'   => $unit_price,
                'item_total'   => $item_total,
            ];
        }

        $session_id  = md5( uniqid( 'kb_', true ) );
        $currency    = get_woocommerce_currency();

        // Costruisci query string multi-prodotto per WC
        // WC accetta: ?add-to-cart=ID&quantity=Q per singoli
        // Per multi: usiamo il nostro handler via session_id
        $base_args   = [ 'kalicart_session' => $session_id ];
        $cart_url     = add_query_arg( array_merge( $base_args, [ 'kalicart_dest' => 'cart' ] ),     home_url( '/' ) );
        $checkout_url = add_query_arg( array_merge( $base_args, [ 'kalicart_dest' => 'checkout' ] ), home_url( '/' ) );

        $session_data = [
            'session_id'   => $session_id,
            'items'        => $items,
            'item_count'   => count( $items ),
            'subtotal'     => $line_total,
            'currency'     => $currency,
            'cart_url'     => $cart_url,
            'checkout_url' => $checkout_url,
            'status'       => 'pending',
            'note'         => 'Present both URLs to the user. cart_url: user reviews cart before paying. checkout_url: goes directly to checkout. Do not attempt to pay programmatically.',
            'created_at'   => gmdate( 'c' ),
            'expires_at'   => gmdate( 'c', time() + self::SESSION_TTL ),
        ];

        set_transient( 'kalicart_session_' . $session_id, $session_data, self::SESSION_TTL );

        return new WP_REST_Response( array_merge( [ 'success' => true ], $session_data ), 201 );
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    public static function get_session( WP_REST_Request $req ): WP_REST_Response {
        $id   = sanitize_text_field( $req->get_param( 'id' ) );
        $data = get_transient( 'kalicart_session_' . $id );
        if ( ! $data ) return self::error( 'Session not found or expired.', 404 );
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    // ── CANCEL ────────────────────────────────────────────────────────────────

    public static function cancel_session( WP_REST_Request $req ): WP_REST_Response {
        $id = sanitize_text_field( $req->get_param( 'id' ) );
        if ( ! get_transient( 'kalicart_session_' . $id ) ) {
            return self::error( 'Session not found or already expired.', 404 );
        }
        delete_transient( 'kalicart_session_' . $id );
        return new WP_REST_Response( [ 'success' => true, 'cancelled' => true, 'session_id' => $id ], 200 );
    }

    // ── SESSION REDIRECT ──────────────────────────────────────────────────────
    //
    // Gestisce ?kalicart_session=ID&kalicart_dest=cart|checkout
    // Aggiunge tutti i prodotti della sessione al carrello WC e redirige.

    /**
     * Permission callback for public checkout session endpoints.
     * No authentication required by design — sessions use token-based access.
     */
    public static function public_catalog_permission(): bool {
        return true;
    }

    public static function handle_session_redirect(): void {
        $session_id = sanitize_text_field( wp_unslash( $_GET['kalicart_session'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $dest       = sanitize_text_field( wp_unslash( $_GET['kalicart_dest'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $session_id || ! in_array( $dest, [ 'cart', 'checkout' ], true ) ) return;

        $data = get_transient( 'kalicart_session_' . $session_id );
        if ( ! $data ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Svuota il carrello corrente e aggiungi tutti gli item della sessione
        WC()->cart->empty_cart();

        foreach ( $data['items'] as $item ) {
            WC()->cart->add_to_cart(
                $item['product_id'],
                $item['quantity'],
                $item['variation_id'] ?? 0
            );
        }

        // Aggiorna stato sessione
        $data['status'] = 'cart_loaded';
        set_transient( 'kalicart_session_' . $session_id, $data, self::SESSION_TTL );

        $redirect = $dest === 'checkout' ? wc_get_checkout_url() : wc_get_cart_url();
        wp_safe_redirect( $redirect );
        exit;
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private static function error( string $message, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'message' => $message ], $status );
    }
}
