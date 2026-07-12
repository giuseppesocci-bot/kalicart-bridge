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
        // Checkout attribution: classic checkout and Store API (Checkout Block) fire
        // DIFFERENT hooks with different signatures (verified against WooCommerce 10.8.1
        // core: includes/class-wc-checkout.php vs src/StoreApi/Routes/V1/CheckoutOrder.php).
        // Both funnel into the same idempotent attribute_order().
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'attribute_order_classic' ], 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'attribute_order_blocks' ], 10, 1 );
    }

    public static function register_routes(): void {
        $ns = KALICART_BRIDGE_API_NS;

        // POST create session — intentionally public: an AI agent (no WP credentials)
        // creates the session; the human completes payment on-site. No PII is stored
        // (only product IDs, prices, and public URLs). Declared public per WP REST guidelines.
        register_rest_route( $ns, '/checkout/session', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_session' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/checkout/session/(?P<id>[a-f0-9]{32})', [
            [
                // GET — intentionally public: returns only the non-PII session payload
                // (products, prices, public URLs) to the bearer of the 32-hex session token.
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_session' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'validate_callback' => [ __CLASS__, 'is_valid_session_token' ],
                    ],
                ],
            ],
            [
                // DELETE — destructive. Access is gated by possession of the session
                // token (bearer model): the callback validates the token format and
                // requires the session to exist. Without a valid, existing token the
                // request is rejected before reaching the handler.
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'cancel_session' ],
                'permission_callback' => [ __CLASS__, 'cancel_session_permission' ],
                'args'                => [
                    'id' => [
                        'validate_callback' => [ __CLASS__, 'is_valid_session_token' ],
                    ],
                ],
            ],
        ] );
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public static function create_session( WP_REST_Request $req ): WP_REST_Response {
        $body = $req->get_json_params();

        // Rate limit (mitigation, not absolute protection - WooCommerce's own Store API
        // rate limiter states the same). Two layers: per-client and a short global cap,
        // both proxy-aware (first X-Forwarded-For entry, else REMOTE_ADDR - same extraction
        // as class-signals.php for consistency). Uses transients: "good enough" throttling,
        // NOT the strict atomicity used below for the session claim, where correctness of
        // the funnel/attribution actually depends on it.
        $rl = self::check_rate_limit();
        if ( true !== $rl ) {
            return $rl; // WP_REST_Response 429 with Retry-After
        }

        // Quantity cap: reject a session that WooCommerce's own cart would refuse anyway,
        // with a clear message instead of a silent reduction later. This is an early-reject
        // nicety, not a security boundary - WC_Cart::add_to_cart() (called by
        // handle_session_redirect()) already validates is_purchasable()/has_enough_stock()/
        // sold_individually independently of Bridge.
        foreach ( (array) ( $body['items'] ?? [] ) as $it ) {
            $pid = (int) ( $it['product_id'] ?? 0 );
            $qty = (int) ( $it['quantity'] ?? 0 );
            $p   = $pid ? wc_get_product( $pid ) : null;
            if ( $p ) {
                $max = $p->get_max_purchase_quantity();
                if ( $max > 0 && $qty > $max ) {
                    return self::error( sprintf( 'Quantity %d for product %d exceeds the maximum purchasable quantity (%d).', $qty, $pid, $max ), 400 );
                }
            }
        }

        // Idempotency-Key: an agent that retries the same request (network failure or
        // double submit) must receive the same session, not a new one. Same key + same
        // payload replays the original response; same key + different payload is a 409.
        // This hardens an existing path; it is not a new surface.
        $idem_key   = trim( (string) $req->get_header( 'Idempotency-Key' ) );
        $idem_store = '';
        $idem_hash  = '';
        if ( '' !== $idem_key ) {
            if ( strlen( $idem_key ) > 255 ) {
                return self::error( 'Idempotency-Key must be at most 255 characters.', 400 );
            }
            $idem_hash  = hash( 'sha256', (string) wp_json_encode( self::idem_canonicalize( is_array( $body ) ? $body : array() ) ) );
            $idem_store = 'kalicart_checkout_idem_' . get_current_blog_id() . '_' . md5( $idem_key );
            $prev = get_transient( $idem_store );
            if ( is_array( $prev ) && isset( $prev['payload_hash'], $prev['response'] ) ) {
                if ( hash_equals( (string) $prev['payload_hash'], $idem_hash ) ) {
                    return new WP_REST_Response( $prev['response'], 201 );
                }
                return self::error( 'Idempotency-Key already used with a different request payload.', 409 );
            }
        }

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

        // Cryptographically secure 128-bit token (32 hex chars), matching the
        // route pattern [a-f0-9]{32}. The session ID doubles as a bearer token:
        // possession of this unguessable value is what authorizes GET/DELETE.
        $session_id  = bin2hex( random_bytes( 16 ) );
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

        $response = array_merge( [ 'success' => true ], $session_data );
        if ( '' !== $idem_key ) {
            set_transient( $idem_store, array( 'payload_hash' => $idem_hash, 'session_id' => $session_id, 'response' => $response ), self::SESSION_TTL );
        }
        // Funnel: a genuinely NEW session only. Idempotent replays (returned above, before
        // this line) and 409 conflicts never reach here, so they are never double-counted.
        self::bump_funnel( 'sessions_created' );
        return new WP_REST_Response( $response, 201 );
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
     * Validate the session token format: exactly 32 hexadecimal chars (md5).
     * Used as args.validate_callback so malformed tokens are rejected at the
     * REST layer before any handler or permission logic runs.
     */
    public static function is_valid_session_token( $value ): bool {
        return is_string( $value ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $value );
    }

    /**
     * Permission callback for DELETE (cancel) — a destructive action.
     * Access is gated by possession of a valid session token (bearer model):
     * the token must be well-formed AND correspond to an existing session.
     * This prevents unauthenticated cancellation of arbitrary or guessed IDs.
     */
    public static function cancel_session_permission( WP_REST_Request $req ): bool {
        $id = (string) $req->get_param( 'id' );
        if ( ! self::is_valid_session_token( $id ) ) {
            return false;
        }
        return (bool) get_transient( 'kalicart_session_' . $id );
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

        // Soft check: has this session ALREADY produced an attributed order (claimed by
        // attribute_order(), see below)? If so, do NOT populate the cart or reveal anything
        // about the original order - this is a bearer-token link, and resuming/redirecting
        // to the first order's checkout would leak it to whoever holds this URL next. Show
        // a generic message and stop. The authoritative, race-safe check is the atomic claim
        // at order-processed time; this is a fast, honest early exit for the common case.
        if ( get_option( 'kalicart_session_claimed_' . $session_id ) ) {
            wp_die(
                esc_html__( 'This checkout link has already been used and cannot be reused.', 'kalicart-bridge' ),
                esc_html__( 'Link already used', 'kalicart-bridge' ),
                [ 'response' => 410 ]
            );
        }

        // Funnel guard: was this session ALREADY at cart_loaded before this visit? A buyer
        // can click the cart/checkout link more than once in the same session; count the
        // transition once, not once per click.
        $was_loaded = ( ( $data['status'] ?? '' ) === 'cart_loaded' );

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
        if ( ! $was_loaded ) {
            self::bump_funnel( 'carts_loaded' );
        }

        // Marker for order attribution: written unconditionally on every redirect into this
        // WC session (not gated on $was_loaded) so the most recent kalicart session always
        // owns whichever order completes next in this browser session. Read + removed by
        // attribute_order() once an order is successfully linked.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'kalicart_bridge_session_id', $session_id );
        }

        $redirect = $dest === 'checkout' ? wc_get_checkout_url() : wc_get_cart_url();
        wp_safe_redirect( $redirect );
        exit;
    }

    // ── CLAIM CLEANUP (WP-Cron, daily — see kalicart-bridge.php) ───────────────

    /**
     * Retention for kalicart_session_claimed_{id} rows. Longer than SESSION_TTL on
     * purpose: the claim must keep blocking a replayed/leaked link well after the
     * underlying kalicart_session_ transient itself has expired. Filterable.
     */
    private static function claim_retention_seconds(): int {
        return (int) apply_filters( 'kalicart_bridge_claim_retention_seconds', 30 * DAY_IN_SECONDS );
    }

    public static function cleanup_stale_claims(): void {
        global $wpdb;
        $cutoff = time() - self::claim_retention_seconds();
        $like   = $wpdb->esc_like( 'kalicart_session_claimed_' ) . '%';
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like
        ) );
        foreach ( (array) $rows as $row ) {
            $parts = explode( '|', (string) $row->option_value, 2 );
            $ts    = isset( $parts[1] ) ? (int) $parts[1] : 0;
            // No parseable timestamp (e.g. a row from before this format existed) counts
            // as stale too, rather than being kept forever.
            if ( $ts === 0 || $ts < $cutoff ) {
                $wpdb->delete( $wpdb->options, [ 'option_id' => $row->option_id ] );
            }
        }
    }

    // ── RATE LIMITING ────────────────────────────────────────────────────────
    //
    // Mitigation, not absolute protection - WooCommerce's own Store API rate limiter
    // documentation states the same about itself. Two layers: per-client (IP, proxy-aware)
    // and a short global cap across all clients, guarding against both a single abusive
    // client and a distributed burst from many IPs. Transient-backed: self-expiring, "good
    // enough" throttling. This is NOT the strict atomicity used for the session claim above,
    // where correctness of the funnel/attribution actually depends on exclusivity.

    /**
     * Default client IP: REMOTE_ADDR only. X-Forwarded-For is trusted ONLY when the
     * immediate connecting peer (REMOTE_ADDR) is itself a known proxy/CDN — otherwise a
     * client can simply send a different X-Forwarded-For value on every request and
     * defeat the rate limit entirely. Empty allowlist by default: safest default, the
     * merchant/hosting opts in via the filter, not the other way round.
     */
    private static function trusted_proxies(): array {
        return (array) apply_filters( 'kalicart_bridge_trusted_proxies', [] );
    }

    private static function ip_in_cidr( string $ip, string $cidr ): bool {
        if ( strpos( $cidr, '/' ) === false ) {
            return hash_equals( $cidr, $ip ); // exact match (also covers IPv6 entries)
        }
        [ $subnet, $bits ] = explode( '/', $cidr, 2 );
        if ( strpos( $ip, ':' ) !== false || strpos( $subnet, ':' ) !== false ) {
            return false; // CIDR matching here is IPv4-only; use exact-match entries for IPv6.
        }
        $bits = (int) $bits;
        $ip_l = ip2long( $ip );
        $sub_l = ip2long( $subnet );
        if ( $ip_l === false || $sub_l === false || $bits < 0 || $bits > 32 ) {
            return false;
        }
        $mask = $bits === 0 ? 0 : ( -1 << ( 32 - $bits ) );
        return ( $ip_l & $mask ) === ( $sub_l & $mask );
    }

    private static function remote_addr_is_trusted_proxy(): bool {
        $remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( $remote === '' ) {
            return false;
        }
        foreach ( self::trusted_proxies() as $entry ) {
            if ( self::ip_in_cidr( $remote, (string) $entry ) ) {
                return true;
            }
        }
        return false;
    }

    private static function client_ip(): string {
        if ( self::remote_addr_is_trusted_proxy() ) {
            $xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
            if ( $xff !== '' ) {
                return trim( explode( ',', $xff )[0] );
            }
        }
        return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
    }

    private static function rate_limit_per_client(): int {
        return (int) apply_filters( 'kalicart_bridge_rate_limit_per_client', 10 );
    }

    private static function rate_limit_per_client_secs(): int {
        return (int) apply_filters( 'kalicart_bridge_rate_limit_per_client_secs', 60 );
    }

    private static function rate_limit_global(): int {
        return (int) apply_filters( 'kalicart_bridge_rate_limit_global', 60 );
    }

    private static function rate_limit_global_secs(): int {
        return (int) apply_filters( 'kalicart_bridge_rate_limit_global_secs', 10 );
    }

    /**
     * Returns true if the request may proceed, or a 429 WP_REST_Response (with Retry-After)
     * if a limit was hit. Checks the tighter global window first (cheaper to exhaust,
     * protects against distributed bursts), then the per-client window.
     */
    private static function check_rate_limit() {
        $checks = [
            [ 'kalicart_rl_g', self::rate_limit_global(), self::rate_limit_global_secs() ],
            [ 'kalicart_rl_c_' . md5( self::client_ip() ), self::rate_limit_per_client(), self::rate_limit_per_client_secs() ],
        ];
        foreach ( $checks as [ $key, $limit, $window ] ) {
            $count = (int) get_transient( $key );
            if ( $count >= $limit ) {
                $resp = new WP_REST_Response(
                    [ 'success' => false, 'error' => 'rate_limited', 'message' => 'Too many checkout session requests. Please slow down.' ],
                    429
                );
                $resp->header( 'Retry-After', (string) $window );
                return $resp;
            }
            set_transient( $key, $count + 1, $window );
        }
        return true;
    }

    // ── ORDER ATTRIBUTION ────────────────────────────────────────────────────
    //
    // Classic checkout and Checkout Block (Store API) fire different hooks with
    // different signatures; both normalize to the same idempotent core.

    public static function attribute_order_classic( int $order_id, array $posted_data, $order ): void {
        self::attribute_order( $order );
    }

    public static function attribute_order_blocks( $order ): void {
        self::attribute_order( $order );
    }

    private static function attribute_order( $order ): void {
        if ( ! ( $order instanceof WC_Order ) ) {
            return;
        }
        // Idempotent: this specific order already attributed (e.g. hook fired twice for the
        // same order under some checkout flows) -> no re-write, no re-count.
        if ( $order->get_meta( '_kalicart_bridge_session_id', true ) ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        $session_id = WC()->session->get( 'kalicart_bridge_session_id' );
        if ( ! $session_id ) {
            return; // No marker: this order did not originate from a Bridge checkout session.
        }
        // Atomic claim: one kalicart session -> one attribution, safe under concurrency.
        // Raw INSERT IGNORE against wp_options, relying on the UNIQUE index on option_name
        // (confirmed on this install). Two simultaneous completions of the same session_id
        // race here at the database level, not in PHP: exactly one wins ($wpdb->rows_affected
        // === 1); the other's order still completes normally through WooCommerce, just
        // without Bridge attribution or a funnel count.
        global $wpdb;
        $claim_key = 'kalicart_session_claimed_' . $session_id;
        // Value carries order_id|unix_timestamp so cleanup_stale_claims() (daily cron) can
        // age these out; plain wp_options rows have no built-in expiry of their own.
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $claim_key, $order->get_id() . '|' . time()
        ) );
        if ( 1 !== (int) $wpdb->rows_affected ) {
            return; // Lost the race (or already claimed by an earlier completion): no attribution.
        }
        // HPOS-safe CRUD (never direct update_post_meta()).
        $order->update_meta_data( '_kalicart_bridge_session_id', $session_id );
        $order->update_meta_data( '_kalicart_bridge_source', 'agent_checkout' );
        $order->save();
        // Remove the marker only AFTER a successful save, so a second order placed later
        // in the same WC session does not inherit this session's attribution.
        WC()->session->__unset( 'kalicart_bridge_session_id' );
        self::bump_funnel( 'orders_linked' );
    }

    // ── FUNNEL METRICS (local, no cloud) ────────────────────────────────────
    //
    // Daily bucket counters, option kalicart_bridge_agent_funnel:
    // { "YYYY-MM-DD": { sessions_created, carts_loaded, orders_linked } }
    // Same 31-day retention pattern as kalicart_bridge_ai_traffic (class-signals.php).

    private static function bump_funnel( string $metric ): void {
        $day   = gmdate( 'Y-m-d' );
        $stats = get_option( 'kalicart_bridge_agent_funnel', [] );
        if ( ! is_array( $stats ) ) {
            $stats = [];
        }
        if ( count( $stats ) > 31 ) {
            ksort( $stats );
            $stats = array_slice( $stats, -31, null, true );
        }
        $stats[ $day ][ $metric ] = (int) ( $stats[ $day ][ $metric ] ?? 0 ) + 1;
        update_option( 'kalicart_bridge_agent_funnel', $stats, false );
    }

    /**
     * Funnel totals for the panel: 30-day rollup of the three counters, plus net paid
     * value of linked orders (paid statuses only, minus refunds). HPOS-safe: uses
     * wc_get_orders()/WC_Order CRUD, never raw postmeta queries.
     */
    public static function get_funnel_report(): array {
        $stats = get_option( 'kalicart_bridge_agent_funnel', [] );
        if ( ! is_array( $stats ) ) {
            $stats = [];
        }
        $cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $totals = [ 'sessions_created' => 0, 'carts_loaded' => 0, 'orders_linked' => 0 ];
        foreach ( $stats as $day => $bucket ) {
            if ( $day < $cutoff || ! is_array( $bucket ) ) {
                continue;
            }
            foreach ( $totals as $k => $v ) {
                $totals[ $k ] += (int) ( $bucket[ $k ] ?? 0 );
            }
        }

        $order_ids = wc_get_orders( [
            'meta_key'   => '_kalicart_bridge_source',
            'meta_value' => 'agent_checkout',
            'return'     => 'ids',
            'limit'      => 500, // known scaling point: raise or paginate if this is ever hit
            'status'     => wc_get_is_paid_statuses(), // plain array of status slugs, NOT associative — no array_keys()
        ] );
        // Paid-status membership is not proof of payment: a COD/BACS/cheque order reaches
        // 'processing' immediately, with date_paid left NULL (verified live on this
        // install). get_date_paid() is set only by WC_Order::payment_complete(), which real
        // gateways call on actual confirmation - the correct signal for "genuinely paid".
        $net          = 0.0;
        $paid_count   = 0;
        foreach ( $order_ids as $oid ) {
            $o = wc_get_order( $oid );
            if ( ! $o || ! $o->get_date_paid() ) {
                continue;
            }
            $paid_count++;
            $net += (float) $o->get_total() - (float) $o->get_total_refunded();
        }
        $totals['orders_paid_count']    = $paid_count;
        $totals['net_paid_value']       = $net;
        $totals['currency']             = get_woocommerce_currency();
        return $totals;
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    /**
     * Canonicalize a decoded JSON payload for stable hashing: sort associative
     * keys, preserve the order of list arrays (e.g. items[]), recurse into both.
     */
    private static function idem_canonicalize( $value ) {
        if ( is_array( $value ) ) {
            if ( $value !== array() && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
                ksort( $value );
            }
            foreach ( $value as $k => $v ) {
                $value[ $k ] = self::idem_canonicalize( $v );
            }
        }
        return $value;
    }

    private static function error( string $message, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'message' => $message ], $status );
    }
}
