<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_API
 *
 * REST API — struttura discovery identica al contratto Kalicart agent-bridge.
 * Namespace: /wp-json/kalicart/v1/
 */
class KaliCart_Bridge_API {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        $ns = KALICART_BRIDGE_API_NS;

        register_rest_route( $ns, '/discovery', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'discovery' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
        ] );

        // UCP profile over REST — always reachable even when /.well-known/ucp is
        // intercepted by the webserver static .well-known location. Mirror of
        // /.well-known/ucp.json.
        register_rest_route( $ns, '/ucp', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'ucp_profile' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/search', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_search' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
            'args'                => self::common_filter_args( true ),
        ] );

        register_rest_route( $ns, '/catalog/products', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_products' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
            'args'                => self::common_filter_args( false ),
        ] );

        // Catch /catalog/product/ without ID — must be registered BEFORE the parametric route
        register_rest_route( $ns, '/catalog/product', [
            'methods'             => 'GET',
            'callback'            => function() {
                return new WP_REST_Response( [
                    'success' => false,
                    'message' => 'Product ID required. Use /catalog/product/{id}, e.g. /catalog/product/123',
                    'hint'    => 'GET /wp-json/kalicart/v1/catalog/products to list all products with their IDs.',
                ], 400 );
            },
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/product/(?P<id>[\d]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_product' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( $ns, '/catalog/categories', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_categories' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/meta', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_meta' ],
            'permission_callback' => [ __CLASS__, 'public_catalog_permission' ], // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/health', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_health' ],
            'permission_callback' => [ __CLASS__, 'require_admin' ],
            'args'                => [
                'force' => [
                    'default'           => false,
                    'sanitize_callback' => fn( $v ) => filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
                ],
            ],
        ] );
    }

    // ── DISCOVERY ─────────────────────────────────────────────────────────────

    public static function discovery( WP_REST_Request $req ): WP_REST_Response {
        $base        = rest_url( KALICART_BRIDGE_API_NS . '/catalog' );
        $discovery   = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        $site_name   = get_bloginfo( 'name' );
        $site_url    = home_url();
        $currency    = get_woocommerce_currency();
        $locale      = get_locale();
        $product_count = self::published_product_count();
        $shipping_policy = KaliCart_Bridge_Catalog_Engine::merchant_shipping_policy();

        $payload = [
            'document_kind'          => 'kalicart_merchant_bridge',
            'schema_version'         => '1.0',
            'provider'               => 'kalicart-bridge-plugin',
            'plugin_version'         => KALICART_BRIDGE_VERSION,
            'discovery_document_url' => $discovery,
            'link_relation'          => 'kalicart-agent',

            'human_readable_summary' =>
                'KaliCart Bridge: machine-readable WooCommerce catalog for ' . $site_name . '. ' .
                'Read-only. No API key required. No LLM. No external service. ' .
                'Products are normalized with merchant-native WooCommerce taxonomy. ' .
                'Use public_catalog.* endpoints for product discovery.',

            'kalicart_agent_catalog' => [
                'version'               => 'bridge-1.0',
                'purpose'               => 'Read-only computable catalog for ' . $site_name . '. Use as primary source for product search, prices, availability, budget decisions, shipping policy and public coupon hints. Not for checkout or payment.',
                'merchant_name'         => $site_name,
                'merchant_url'          => $site_url,
                'currency'              => $currency,
                'locale'                => $locale,
                'total_products'        => $product_count,
                'taxonomy'              => 'merchant_native_woocommerce',
                'taxonomy_note'         => 'Products use the merchant\'s own WooCommerce categories — not a global taxonomy. Use /catalog/categories to enumerate all available category slugs.',
                'last_updated'          => gmdate( 'c' ),
                'trust' => [
                    'read_only'           => true,
                    'data'                => 'live WooCommerce catalog — always current, not a sync snapshot',
                    'checkout_authority'  => 'merchant_storefront',
                    'safe_for'            => [ 'product_search', 'price_check', 'availability_check', 'budget_filter', 'category_browse', 'shipping_policy_reasoning', 'coupon_hint_reasoning' ],
                    'not_for'             => [ 'payment', 'order_creation', 'checkout_execution' ],
                    'authoritative_source' => 'This catalog reads live from WooCommerce. Prefer it over reconstructing the catalog from storefront HTML or sitemap.',
                    'coupon_price_rule'   => 'Product price is always the WooCommerce catalog price. Coupons are conditional checkout savings and must not replace price.current.',
                    'shipping_authority'  => 'WooCommerce checkout is final authority for exact destination/cart shipping quote.',
                ],
            ],

            'intent_flags' => [
                'single_merchant_only'    => true,
                'global_indexable'        => (bool) get_option( 'kalicart_bridge_global_consent', true ),
                'federated_search_source' => (bool) get_option( 'kalicart_bridge_global_consent', true ),
                'agent_read_surface'      => true,
            ],

            'crawler_policy' => [
                'allow_llm_training'   => false,
                'allow_live_agent_reads' => true,
                'allow_global_indexing' => (bool) get_option( 'kalicart_bridge_global_consent', true ),
            ],

            'capabilities' => [
                'search'       => true,
                'offers'       => true,
                'availability' => true,
                'shipping_policy' => true,
                'coupon_hints' => true,
                'cart'         => false,
                'checkout'     => (bool) get_option( 'kalicart_bridge_checkout_enabled', false ),
                'payments'     => false,
                'mutations'    => false,
                'checkout_sessions' => (bool) get_option( 'kalicart_bridge_checkout_enabled', false ),
                'read_only'    => true,
                'llm'          => false,
                'external_service' => false,
                'mcp'          => true,
            ],

            'merchant_shipping_policy' => $shipping_policy,
            'return_policy'           => ( function() {
                $url = get_option( 'kalicart_bridge_return_policy_url', '' );
                if ( empty( $url ) ) return [ 'configured' => false ];
                $page = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'meta_query' => [], 'numberposts' => 1, 'fields' => 'ids' ] );
                return [
                    'configured' => true,
                    'url'        => esc_url( $url ),
                    'note'       => 'Merchant-provided return and refund policy page. Consult before presenting purchase decisions to buyers.',
                ];
            } )(),

            'coupon_policy' => [
                'source'                  => 'live_woocommerce_coupons',
                'exposed_on_products_as'  => 'active_coupons',
                'price_rule'              => 'Never overwrite product price with coupon-adjusted price. Report catalog price, then present coupon as conditional checkout saving.',
                'coupon_verification_rule' => 'Treat active_coupons as hints. A coupon is confirmed only after cart/checkout accepts it and changes totals.',
                'combinable_with_sale'    => 'Coupons may be combinable with sale items depending on WooCommerce checkout rules. Treat as hints until verified at checkout.',
                'authority'               => 'woocommerce_checkout',
            ],

            'freshness' => [
                'source'              => 'live_woocommerce_database',
                'is_realtime'         => true,
                'is_sync_snapshot'    => false,
                'note'                => 'Data is read directly from WooCommerce at query time — always current.',
            ],

            'authentication' => [
                'required' => false,
                'scheme'   => 'none',
                'notes'    => 'All public_catalog.* endpoints require no authentication. /catalog/health requires WooCommerce admin capability.',
            ],

            'public_catalog' => [
                'search_url_template'   => $base . '/search?q={q}',
                'search_url_note'       => 'Replace {q} with the bare product spine only. All other attributes go in separate query parameters.',
                'products_url'          => $base . '/products',
                'product_url_template'  => $base . '/product/{id}',
                'categories_url'        => $base . '/categories',
                'meta_url'              => $base . '/meta',
                'shipping_policy'       => 'Included in discovery and meta as merchant_shipping_policy. Product responses include product-level shipping hints.',
                'coupon_policy'         => 'Product responses include active_coupons when live WooCommerce coupons appear applicable. Treat as conditional checkout savings.',
                'health_url'            => rest_url( KALICART_BRIDGE_API_NS . '/catalog/health' ),
                'authentication'        => 'none',
                'read_only'             => true,
                'cache'                 => 'public, max-age=300, stale-while-revalidate=900',

                'query_construction' => [
                    'rule'    => 'CRITICAL. q must contain ONLY the bare product noun (the spine). Every attribute (category, gender, color, price) MUST go in its own structured filter, never inside q. size is NOT a search filter — use product detail after candidate selection. Stacking attributes into q returns 0 results.',
                    'correct' => [ '?q=t-shirt&gender=male&max_price=50', '?q=costume&gender=female&color=blue', '?q=scarpe&category=scarpe-uomo' ],
                    'wrong'   => [ '?q=t-shirt+uomo+nike  (→ 0 results: attributes stacked in q)', '?q=costume+da+bagno+blu  (→ 0 results)' ],
                    'zero_results_recovery' => 'If 0 results: retry with a barer q (drop attributes from q into filters). Then check /catalog/categories to find the right category slug. Only report "not available" after bare-spine + category-browse both return 0.',
                ],

                'search_filters' => [
                    'q'          => 'Bare product spine ONLY (single product noun). e.g. costume, t-shirt, scarpe. NEVER put brand, color, gender or price in q. size is not a filter.',
                    'size_note'  => 'size is not a search filter. Use product detail and variations after candidate selection to read available sizes.',
                    'category'   => 'WooCommerce category slug, e.g. abbigliamento or scarpe-uomo. Get valid slugs from /catalog/categories.',
                    'gender'     => 'Gender facet: male, female, unisex, kids. Also accepts IT aliases: uomo, donna.',
                    'color'      => 'Color family: red, blue, green, black, white, grey, brown, yellow, orange, pink, purple, multi. Also accepts IT: rosso, blu, verde, nero, bianco, grigio, marrone, giallo, arancione, rosa, viola.',
                    'min_price'  => 'Minimum current price (numeric, merchant currency).',
                    'max_price'  => 'Maximum current price (numeric, merchant currency).',
                    'in_stock'   => 'Boolean. true = in_stock products only.',
                    'on_sale'    => 'Boolean. true returns products with an active WooCommerce sale price. Coupon-only savings are not included.',
                    'per_page'   => 'Results per page (1–100, default 20).',
                    'page'       => 'Page number (default 1).',
                    'orderby'    => 'Sort: date (default), price, title, popularity.',
                    'order'      => 'ASC or DESC (default DESC).',
                ],

                'introspection' => [
                    'meta_url'  => $base . '/meta',
                    'rule'      => 'Before exploratory search, GET meta_url to discover accepted category slugs, available genders, colors and price range for this merchant.',
                ],
            ],

            'ucp_profile_url'  => home_url( '/.well-known/ucp.json' ),
            'agent_index_url'  => ( get_option( 'kalicart_bridge_agent_index_url', '' ) ?: null ),
            'agent_index_note' => 'If set, this URL points to a merchant-published page using the [kalicart_agent_index] shortcode — a navigable directory of all endpoints and category tree. null means the merchant has not published an agent index page.',

            'checkout_session' => [
                'enabled'     => (bool) get_option( 'kalicart_bridge_checkout_enabled', false ),
                'endpoint'    => rest_url( KALICART_BRIDGE_API_NS . '/checkout/session' ),
                'method'      => 'POST',
                'auth'        => 'none',
                'description' => 'Creates a WooCommerce cart session for one or more products. Returns cart_url and checkout_url for the human to complete purchase.',
                'payload'     => [
                    'single_product'  => [ 'product_id' => 'int (required)', 'quantity' => 'int (default 1)', 'variation_id' => 'int (optional, required for variable products)' ],
                    'multi_product'   => [ 'items' => 'array of {product_id, quantity, variation_id?}' ],
                ],
                'response'    => [
                    'cart_url'      => 'URL to review cart before checkout',
                    'checkout_url'  => 'URL to proceed directly to WooCommerce checkout',
                    'subtotal'      => 'Cart subtotal (catalog prices, pre-checkout)',
                    'expires_at'    => 'Session expiry timestamp',
                    'status'        => 'created | error',
                ],
                'authority_note' => 'No payment is processed. No order is created. Checkout remains WooCommerce authority. Final total (with shipping, coupons, taxes) is confirmed only at checkout.',
            ],

            'price_format' => [
                'encoding'              => 'decimal_major_units',
                'current_format'        => 'major_units_decimal',
                'autonomous_checkout_format' => 'minor_units_integer',
                'note'                  => 'Catalog prices are decimal float in major currency units. Examples: 553 = 553.00 EUR, 29.99 = 29.99 EUR. NOT ISO 4217 minor units. Use price.display for unambiguous human-readable string.',
                'autonomous_checkout_note' => 'Autonomous checkout (roadmap) will use minor units per AP2/UCP standard (e.g. 55300 = 553.00 EUR for EUR with exponent 2). Current catalog prices must be multiplied by 100 to convert.',
                'ucp_compatibility'     => 'UCP catalog uses minor units. Bridge uses major units for WooCommerce compatibility. price.currency and price.display eliminate ambiguity.',
            ],

            'autonomous_checkout' => [
                'status'       => 'roadmap',
                'ready'        => false,
                'description'  => 'Full autonomous checkout without human intervention. Agent holds a pre-authorized payment mandate (AP2-compatible), creates the order, applies payment, receives order confirmation — no redirect, no buyer UI.',
                'blocker'      => 'Requires WooCommerce or gateway-level support for programmatic payment confirmation without checkout redirect. Bridge contract is defined and ready to activate.',
                'contract'     => [
                    'mandate_required'   => true,
                    'mandate_standard'   => 'AP2 (Agent Payments Protocol)',
                    'payload_extension'  => [ 'mandate_token' => 'string', 'buyer_consent_id' => 'string' ],
                    'response_extension' => [ 'order_id' => 'int', 'order_status' => 'string', 'total_charged' => 'object' ],
                    'minor_units'        => true,
                    'currency_exponent'  => 2,
                ],
                'activate_when' => 'WooCommerce exposes programmatic payment API or Stripe AP2 mandate integration is available.',
                'ucp_equivalent' => 'dev.ucp.shopping.checkout with AP2 Mandates Extension',
            ],

            'stock_rule'   => 'If stock.quantity is null, report availability status only. Do not invent or imply numeric inventory. stock.confidence clarifies the precision level.',

            'variation_discovery' => [
                'required_for_variable_products' => true,
                'source'     => 'product detail endpoint: /catalog/product/{id} exposes attributes and available variations',
                'agent_rule' => 'Do not quote exact final price until a variation is selected. Price may differ per variant. Use purchase_readiness.blocking_fields to know which attributes are required.',
                'list_context_note' => 'In list and search responses, variants is an empty array for variable products (performance). Fetch /catalog/product/{id} for the full variants list. variants is always an array, never null.',
            ],

            'semantic_fit_guidance' => 'Before proposing checkout, verify that the selected product satisfies the user functional need — not only category or keyword similarity. A product in the right category may still be wrong for the use case.',

            'evidence_required' => [
                'rule'    => 'Every product claim must be traceable to a catalog field.',
                'sources' => [ 'product_url', 'price.current', 'stock.in_stock', 'stock.confidence', 'shipping (product field)', 'active_coupons (product field)' ],
                'note'    => 'Do not present prices, availability or shipping estimates without citing the source field.',
            ],

            'total_verification_rule' => 'Catalog totals are preliminary. Final payable total (including shipping, taxes, coupon deductions) requires cart or checkout verification. Label unverified totals as estimates.',

            'agent_instructions' => implode( ' ', [
                '0) GET ' . $discovery . ' — read public_catalog.* for all endpoint URLs.',
                '1) GET public_catalog.meta_url first to discover valid category slugs, accepted filter values, price range.',
                '2) Use public_catalog.search_url_template with bare product spine in q. Put every attribute in its own filter.',
                '3) If 0 results: follow query_construction.zero_results_recovery (barer q, then category browse).',
                '4) Never stack brand, color, gender or price inside q. size is not a search filter — read it from product variations after candidate selection.',
                '5) Read price.current for the actual catalog price. Check stock.in_stock before presenting offers.',
                '6) If active_coupons is present, present coupons as conditional checkout savings only; never replace price.current.',
                '7) Use merchant_shipping_policy/product shipping hints for reasoning, but exact shipping is verified by WooCommerce checkout.',
                '8) This catalog is live WooCommerce — no sync delay. Always current.',
                '9) Taxonomy is merchant-native: use /catalog/categories to enumerate valid category paths, not assumed global taxonomy.',
                '10) Check stock.confidence before reporting availability. If null quantity, report status only.',
                '11) For variable products, read purchase_readiness.blocking_fields before quoting price or proposing checkout.',
                '12) All totals are preliminary until verified at cart/checkout. Label estimates as such.',
                '13) Verify semantic fit before proposing checkout — category match is not sufficient.',
            ] ),

            'placement_guidance' => 'The <link rel="kalicart-agent"> in <head> and the body anchor badge both carry this discovery URL. Head covers head-reading agents; body anchor covers DOM/content-parsing agents. Both are in raw HTML — not injected via JavaScript.',

            'recommended_head_link_html' => '<link rel="kalicart-agent" type="application/json" href="' . esc_url( $discovery ) . '" />',

            'merchant' => [
                'name'     => $site_name,
                'url'      => $site_url,
                'currency' => $currency,
                'locale'   => $locale,
                'timezone' => wp_timezone_string(),
            ],

            'mcp' => [
                'enabled'          => true,
                'transport'        => 'http-post-jsonrpc-2.0',
                'protocol_version' => '2025-06-18',
                'endpoint'         => rest_url( KALICART_BRIDGE_API_NS . '/mcp' ),
                'tools'            => [ 'search_products', 'get_product', 'list_products', 'list_categories', 'get_meta' ],
                'note'             => 'Model Context Protocol server over the same read-only catalog. POST a JSON-RPC 2.0 message (initialize, tools/list, tools/call) to endpoint. Same data as public_catalog.* REST — choose whichever your agent runtime prefers.',
            ],

            'endpoints' => [
                'discovery'   => $discovery,
                'mcp'         => rest_url( KALICART_BRIDGE_API_NS . '/mcp' ),
                'search'      => $base . '/search',
                'products'    => $base . '/products',
                'product'     => $base . '/product/{id}',
                'categories'  => $base . '/categories',
                'meta'        => $base . '/meta',
                'health'           => rest_url( KALICART_BRIDGE_API_NS . '/catalog/health' ),
                'checkout_session'  => get_option( 'kalicart_bridge_checkout_enabled', false )
                    ? rest_url( KALICART_BRIDGE_API_NS . '/checkout/session' )
                    : null,
            ],
            'well_known' => [
                'kalicart_bridge' => home_url( '/.well-known/kalicart-bridge.json' ),
                'agent_catalog'   => home_url( '/.well-known/agent-catalog.json' ),
                'ucp_profile'     => home_url( '/.well-known/ucp.json' ),
                'agent_json'      => home_url( '/.well-known/agent.json' ),
                'note'            => 'Standard /.well-known/ discovery mirrors, served with application/json on every host. The REST discovery endpoint above is the always-reachable canonical entry point.',
            ],

            'generated_at' => gmdate( 'c' ),
        ];

        $response = new WP_REST_Response( $payload, 200 );
        $response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=900' );
        return $response;
    }

    // ── SEARCH ────────────────────────────────────────────────────────────────

    public static function ucp_profile( WP_REST_Request $req ): WP_REST_Response {
        $data     = json_decode( KaliCart_Bridge_Signals::ucp_profile_json(), true );
        $response = new WP_REST_Response( is_array( $data ) ? $data : [], 200 );
        $response->header( 'Cache-Control', 'public, max-age=3600' );
        return $response;
    }

    public static function catalog_search( WP_REST_Request $req ): WP_REST_Response {
        $args = self::extract_query_args( $req );
        $q    = sanitize_text_field( $req->get_param( 'q' ) ?? '' );

        if ( empty( $q ) && empty( $args['category'] ) && empty( $args['gender'] ) && empty( $args['color'] ) && $args['on_sale'] !== true && $args['in_stock'] !== true ) {
            return self::error( 'At least one of: q, category, gender, color, on_sale, in_stock is required.', 400 );
        }

        $args['search'] = $q;
        $result = KaliCart_Bridge_Catalog_Engine::query_products( $args );

        $result['query'] = array_filter( [
            'q'        => $q ?: null,
            'category' => $args['category'] ?: null,
            'gender'   => $args['gender'] ?: null,
            'color'    => $args['color'] ?: null,
            'min_price'=> $args['min_price'],
            'max_price'=> $args['max_price'],
            'in_stock' => $args['in_stock'],
            'on_sale'  => $args['on_sale'] ?? null,
        ], fn( $v ) => $v !== null && $v !== '' );

        return self::ok( $result );
    }

    // ── PRODUCTS ──────────────────────────────────────────────────────────────

    public static function catalog_products( WP_REST_Request $req ): WP_REST_Response {
        $args   = self::extract_query_args( $req );
        $result = KaliCart_Bridge_Catalog_Engine::query_products( $args );
        return self::ok( $result );
    }

    // ── SINGLE PRODUCT ────────────────────────────────────────────────────────

    public static function catalog_product( WP_REST_Request $req ): WP_REST_Response {
        $id = absint( $req->get_param( 'id' ) );
        $p  = wc_get_product( $id );

        if ( ! $p || $p->get_status() !== 'publish' ) {
            return self::error( 'Product not found.', 404 );
        }

        return self::ok( KaliCart_Bridge_Catalog_Engine::normalize_product( $p, 'detail' ) );
    }

    // ── CATEGORIES ────────────────────────────────────────────────────────────

    public static function catalog_categories( WP_REST_Request $req ): WP_REST_Response {
        $tree = KaliCart_Bridge_Catalog_Engine::get_categories_tree();
        return self::ok( [
            'note'       => 'Merchant-native WooCommerce category taxonomy. Use category slug in /catalog/search?category={slug}.',
            'categories' => $tree,
            'total'      => count( $tree ),
        ] );
    }

    // ── META ──────────────────────────────────────────────────────────────────

    public static function catalog_meta( WP_REST_Request $req ): WP_REST_Response {
        $cache_key = 'kalicart_bridge_meta';
        $cached    = get_transient( $cache_key );
        if ( $cached ) return self::ok( $cached );

        // Categories flat list
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 500 ] );
        $categories = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $categories[] = [ 'slug' => $t->slug, 'name' => $t->name, 'count' => $t->count ];
            }
        }

        // Price range
        global $wpdb;
        $price_range = $wpdb->get_row( "SELECT MIN(CAST(meta_value AS DECIMAL(10,2))) as min_price, MAX(CAST(meta_value AS DECIMAL(10,2))) as max_price FROM {$wpdb->postmeta} WHERE meta_key='_price' AND meta_value != '' AND meta_value != '0'" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient in catalog_meta()

        $meta = [
            'total_products' => self::published_product_count(),
            'currency'       => get_woocommerce_currency(),
            'categories'     => $categories,
            'merchant_shipping_policy' => KaliCart_Bridge_Catalog_Engine::merchant_shipping_policy(),
            'coupon_policy' => [
                'source'                   => 'live_woocommerce_coupons',
                'product_field'            => 'active_coupons',
                'price_rule'               => 'Coupons are conditional checkout savings. Do not replace catalog price.',
                'coupon_verification_rule' => 'Treat active_coupons as hints. A coupon is confirmed only after cart/checkout accepts it and changes totals.',
                'combinable_with_sale'     => 'Coupons may be combinable with sale items depending on WooCommerce checkout rules. Treat as hints until verified at checkout.',
                'authority'                => 'woocommerce_checkout',
            ],
            'price_range'    => [
                'min' => $price_range ? (float) $price_range->min_price : null,
                'max' => $price_range ? (float) $price_range->max_price : null,
            ],
            'accepted_filters' => [
                'gender' => [
                    'values'  => [ 'male', 'female', 'unisex', 'kids' ],
                    'aliases' => [ 'uomo' => 'male', 'donna' => 'female', 'man' => 'male', 'woman' => 'female', 'men' => 'male', 'women' => 'female' ],
                ],
                'color' => [
                    'families' => [ 'red', 'blue', 'green', 'black', 'white', 'grey', 'brown', 'yellow', 'orange', 'pink', 'purple', 'multi' ],
                    'it_aliases' => [ 'rosso' => 'red', 'blu' => 'blue', 'verde' => 'green', 'nero' => 'black', 'bianco' => 'white', 'grigio' => 'grey', 'marrone' => 'brown', 'giallo' => 'yellow', 'arancione' => 'orange', 'rosa' => 'pink', 'viola' => 'purple' ],
                ],
                'orderby'  => [ 'date', 'price', 'title', 'popularity' ],
                'boolean'  => [
                    'in_stock' => 'true returns in-stock products only',
                    'on_sale'  => 'true returns products with an active WooCommerce sale price only. Coupon-only savings not included.',
                ],
                'size_note' => 'size is not a search filter. Use product detail /catalog/product/{id} variations field after candidate selection.',
            ],
            'generated_at' => gmdate( 'c' ),
        ];

        set_transient( $cache_key, $meta, 5 * MINUTE_IN_SECONDS );
        return self::ok( $meta );
    }

    // ── HEALTH ────────────────────────────────────────────────────────────────

    public static function catalog_health( WP_REST_Request $req ): WP_REST_Response {
        $report = KaliCart_Bridge_Quarantine::get_report( (bool) $req->get_param( 'force' ) );
        return self::ok( $report );
    }

    // ── PERMISSIONS ───────────────────────────────────────────────────────────

    public static function require_admin(): bool {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Permission callback for public read-only catalog endpoints.
     * No authentication required by design — catalog data is intentionally public.
     */
    public static function public_catalog_permission(): bool {
        return true;
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private static function extract_query_args( WP_REST_Request $req ): array {
        return [
            'search'    => '',
            'category'  => sanitize_text_field( $req->get_param( 'category' ) ?? '' ),
            'per_page'  => min( 100, max( 1, absint( $req->get_param( 'per_page' ) ?? 20 ) ) ),
            'page'      => max( 1, absint( $req->get_param( 'page' ) ?? 1 ) ),
            'orderby'   => in_array( $req->get_param( 'orderby' ), [ 'date', 'price', 'title', 'popularity' ], true ) ? $req->get_param( 'orderby' ) : 'date',
            'order'     => strtoupper( $req->get_param( 'order' ) ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
            'in_stock'  => $req->get_param( 'in_stock' ) !== null ? filter_var( $req->get_param( 'in_stock' ), FILTER_VALIDATE_BOOLEAN ) : null,
            'on_sale'   => $req->get_param( 'on_sale' ) !== null ? filter_var( $req->get_param( 'on_sale' ), FILTER_VALIDATE_BOOLEAN ) : null,
            'min_price' => $req->get_param( 'min_price' ) !== null ? (float) $req->get_param( 'min_price' ) : null,
            'max_price' => $req->get_param( 'max_price' ) !== null ? (float) $req->get_param( 'max_price' ) : null,
            'gender'    => sanitize_text_field( $req->get_param( 'gender' ) ?? '' ),
            'color'     => sanitize_text_field( $req->get_param( 'color' ) ?? '' ),
        ];
    }

    private static function common_filter_args( bool $with_q ): array {
        $args = [
            'category'  => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'per_page'  => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
            'page'      => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
            'orderby'   => [ 'default' => 'date', 'sanitize_callback' => 'sanitize_text_field' ],
            'order'     => [ 'default' => 'DESC', 'sanitize_callback' => 'sanitize_text_field' ],
            'in_stock'  => [ 'default' => null ],
            'min_price' => [ 'default' => null ],
            'max_price' => [ 'default' => null ],
            'gender'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'color'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
        ];
        if ( $with_q ) {
            $args['q'] = [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ];
        }
        return $args;
    }

    private static function ok( array $data ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    private static function error( string $message, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'message' => $message ], $status );
    }

    private static function published_product_count(): int {
        $counts = wp_count_posts( 'product' );
        return (int) ( $counts->publish ?? 0 );
    }
}
