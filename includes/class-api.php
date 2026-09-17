<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_API
 *
 * REST API — struttura discovery identica al contratto Kalicart agent-bridge.
 * Namespace: /wp-json/kalicart/v1/
 */
class KaliCart_Bridge_API {

    private static int $internal_catalog_depth = 0;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        KaliCart_Bridge_Catalog_Engine::init_cache_hooks();
    }

    /**
     * Trusted in-process bridge for MCP tool dispatch. The outer MCP request has its
     * own limiter; counting its internal REST callback again would double-charge it.
     * No request parameter or header can enter this context.
     */
    public static function internal_catalog_call( callable $callback, WP_REST_Request $request ): WP_REST_Response {
        self::$internal_catalog_depth++;
        try {
            $response = call_user_func( $callback, $request );
            if ( ! ( $response instanceof WP_REST_Response ) ) {
                return self::error( 'Internal catalog callback returned an invalid response.', 500 );
            }
            return $response;
        } finally {
            self::$internal_catalog_depth = max( 0, self::$internal_catalog_depth - 1 );
        }
    }

    // ── MULTILINGUAL CANONICALIZATION ─────────────────────────────────────────
    // The canonical catalog is served in the site default language only. On a
    // DB-translating multilingual site (WPML/WCML, Polylang) each translation is a
    // real post/term in the DB; without forcing the language context the Bridge
    // would enumerate every translation as a duplicate. We pin the request context
    // to the default language at the very start of every public catalog request so
    // that all WP_Query / get_terms() / get_the_terms() calls inherit it. No-op on
    // monolingual sites and on output-translating plugins (Weglot, GTranslate proxy)
    // where the DB holds a single language.

    /**
     * Resolve the site default language slug, plugin-agnostically.
     * Returns null on monolingual sites (nothing to force).
     */
    public static function default_language(): ?string {
        if ( function_exists( 'pll_default_language' ) ) {
            $lang = pll_default_language( 'slug' );
            return $lang ? (string) $lang : null;
        }
        if ( has_filter( 'wpml_default_language' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook
            $lang = apply_filters( 'wpml_default_language', null );
            return $lang ? (string) $lang : null;
        }
        return null; // monolingual or output-translating: nothing to canonicalize
    }

    /**
     * Pin the current request to the site default language. Hooked at the start of
     * every public catalog REST callback. Idempotent and no-op on monolingual sites.
     */
    public static function force_default_language(): void {
        $default = self::default_language();
        if ( $default === null ) {
            return;
        }
        if ( function_exists( 'pll_default_language' ) && function_exists( 'PLL' ) ) {
            $pll = PLL();
            if ( $pll && isset( $pll->curlang ) && ! empty( $pll->model ) ) {
                $lang_obj = $pll->model->get_language( $default );
                if ( $lang_obj ) {
                    $pll->curlang = $lang_obj;
                }
            }
        }
        if ( has_filter( 'wpml_default_language' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook
            do_action( 'wpml_switch_language', $default );
        }
    }

    /**
     * Canonicalize a (possibly translated) post ID to its default-language
     * counterpart. Returns the default-language ID, or 0 if the ID has no
     * mapping into the default language (orphan / unmappable).
     */
    public static function canonicalize_post_id( int $id, string $type = 'post' ): int {
        $default = self::default_language();
        if ( $default === null ) {
            return $id; // monolingual: identity
        }
        if ( function_exists( 'pll_get_post' ) ) {
            $lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $id ) : null;
            if ( $lang && $lang === $default ) {
                return $id; // already default
            }
            $mapped = pll_get_post( $id, $default );
            // pll_get_post returns 0/null/false when no translation exists,
            // and the same id when the post has no language assigned (orphan).
            if ( ! $mapped ) {
                return $lang ? 0 : 0; // translated-but-unmapped OR orphan => hidden
            }
            return (int) $mapped;
        }
        if ( has_filter( 'wpml_object_id' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook
            $mapped = apply_filters( 'wpml_object_id', $id, $type, false, $default );
            return $mapped ? (int) $mapped : 0;
        }
        return $id;
    }

    public static function register_routes(): void {
        $ns = KALICART_BRIDGE_API_NS;

        register_rest_route( $ns, '/discovery', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'discovery' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
            'args'                => [
                // Used only by KaliCart Global's receipt verifier to bypass intermediary caches.
                'kalicart_consent_probe' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn( $value ): bool => is_numeric( $value ),
                ],
            ],
        ] );

        // UCP profile over REST — always reachable even when /.well-known/ucp is
        // intercepted by the webserver static .well-known location. Mirror of
        // /.well-known/ucp.json.
        register_rest_route( $ns, '/ucp', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'ucp_profile' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/search', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_search' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
            'args'                => self::common_filter_args( true ),
        ] );

        register_rest_route( $ns, '/catalog/products', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_products' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
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
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/product/(?P<id>[\d]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_product' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ],
                'fields' => [
                    'default'           => 'verification',
                    'validate_callback' => fn( $v ) => in_array( $v, [ 'verification', 'summary', 'full' ], true ),
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ] );

        register_rest_route( $ns, '/catalog/categories', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_categories' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/catalog/meta', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'catalog_meta' ],
            'permission_callback' => '__return_true', // Read-only public catalog data — no authentication required by design
        ] );

        register_rest_route( $ns, '/openapi', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'openapi' ],
            'permission_callback' => '__return_true', // Read-only public API description — no authentication required by design
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
		$limited = self::catalog_rate_limit( $req );
		if ( $limited !== null ) {
			return $limited;
		}
        $param_error = self::catalog_unknown_param_error( $req, [ 'kalicart_consent_probe' ] );
        if ( $param_error !== null ) {
            return $param_error;
        }
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
                'Use agent_quick_start for product discovery and verification.',

            'agent_quick_start' => [
                'shopping_search_url_template' => $base . '/search?q={q}&per_page=10&fields=summary',
                'filtered_search_endpoint'     => $base . '/search?fields=summary',
                'verify_selected_url_template' => $base . '/product/{id}',
                'rules' => [
                    'Use search for every shopper product request. Do not enumerate /catalog/products.',
                    'If the requested facts are already present in the summary response, answer without fetching product detail.',
                    'Rank and compare candidates from summary data only. Fetch product detail for the final selected product only, unless the user explicitly requests a detail-level comparison of multiple products.',
                ],
            ],

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
                    'data'                => 'Read from WooCommerce at request time, not from a sync snapshot. Caching, plugins or the store being offline can still make a response differ from the storefront: verify price, stock and variant on the product page before committing.',
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
                'global_indexable'        => (bool) get_option( 'kalicart_bridge_global_consent', false ),
                'federated_search_source' => (bool) get_option( 'kalicart_bridge_global_consent', false ),
                'agent_read_surface'      => true,
            ],

            'commerce_distribution' => KaliCart_Bridge_Commerce_Consent::discovery_state(),

            'crawler_policy' => [
                'allow_llm_training'   => false,
                'allow_live_agent_reads' => true,
                'allow_global_indexing' => (bool) get_option( 'kalicart_bridge_global_consent', false ),
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
                'note'                => 'Data is read from WooCommerce at query time. That is the perimeter of the guarantee: it is not a promise that the response matches the storefront at the instant you read it. Verify the final product before quoting price, stock or variant.',
            ],

            'authentication' => [
                'required' => false,
                'scheme'   => 'none',
                'notes'    => 'All public_catalog.* endpoints require no authentication. /catalog/health requires WooCommerce admin capability.',
            ],

            'public_catalog' => [
                'search_url_template'   => $base . '/search?q={q}&fields=summary',
                'search_url_note'       => 'Replace {q} with the bare product spine only. All other attributes go in separate query parameters.',
                'products_url'          => $base . '/products?fields=summary',
                'product_url_template'  => $base . '/product/{id}',
                'product_full_url_template' => $base . '/product/{id}?fields=full',
                'product_verification_url_template' => $base . '/product/{id}',
                'categories_url'        => $base . '/categories',
                'meta_url'              => $base . '/meta',
                'shipping_policy'       => 'Included in discovery and meta as merchant_shipping_policy. Product responses include product-level shipping hints.',
                'coupon_policy'         => 'Product responses include active_coupons when live WooCommerce coupons appear applicable. Treat as conditional checkout savings.',
                'health_url'            => rest_url( KALICART_BRIDGE_API_NS . '/catalog/health' ),
                'authentication'        => 'none',
                'read_only'             => true,
                'cache'                 => 'public, max-age=300, stale-while-revalidate=900',

                'request_contract' => [
                    'search_endpoint' => [
                        'url'                    => $base . '/search?fields=summary',
                        'text_parameter'         => 'q',
                        'result_count_parameter' => 'per_page',
                        'invalid_aliases'        => [ 'query' => 'q', 'limit' => 'per_page' ],
                        'copy_paste_example'     => $base . '/search?q=Nike&per_page=10&fields=summary',
                    ],
                    'products_endpoint' => [
                        'url'                    => $base . '/products?fields=summary',
                        'role'                   => 'browse/list products with filters; not full-text search',
                        'does_not_accept'        => [ 'q', 'search', 'query', 'limit' ],
                        'use_for_text_search'    => $base . '/search?q={q}&fields=summary',
                        'result_count_parameter' => 'per_page',
                        'copy_paste_example'     => $base . '/products?per_page=10&fields=summary',
                    ],
                    'runtime_guidance' => 'Unknown query parameters return 400 and no search runs. If an agent uses search/query instead of q, limit instead of per_page, or sends q/search to /catalog/products, the response includes invalid_parameters, parameter_corrections, correct_endpoint and suggested_url.',
                ],

                'query_construction' => [
                    'rule'    => 'CRITICAL. q must contain ONLY the bare product noun (the spine). Every attribute (category, gender, color, price) MUST go in its own structured filter, never inside q. size is NOT a search filter — use product detail after candidate selection. Stacking attributes into q returns 0 results.',
                    'correct' => [ '?q=t-shirt&gender=male&max_price=50', '?q=costume&gender=female&color=blue', '?q=scarpe&category=scarpe-uomo' ],
                    'wrong'   => [ '?q=t-shirt+uomo+nike  (→ 0 results: attributes stacked in q)', '?q=costume+da+bagno+blu  (→ 0 results)' ],
                    'zero_results_recovery' => 'If 0 results: retry with a barer q (drop attributes from q into filters). Then check /catalog/categories to find the right category slug. Zero results means this search found nothing — not that the store does not carry the product. Report absence only as "not found with these terms", after bare-spine and category-browse have both returned 0.',
                ],

                'search_filters' => [
                    'q'          => 'Bare product spine ONLY (single product noun). e.g. costume, t-shirt, scarpe. NEVER put brand, color, gender or price in q. size is not a filter.',
                    'size_note'  => 'size is not a search filter. Use product detail and variations after candidate selection to read available sizes.',
                    'category'   => 'WooCommerce category slug, e.g. abbigliamento or scarpe-uomo. Get valid slugs from /catalog/categories.',
                    'gender'     => 'Gender facet. Canonical values only: male, female, unisex, kids. Input is trimmed and lowercased; no translation is performed. Soft filter: a product with a different gender is excluded, one whose gender cannot be determined is retained and marked unknown_retained.',
                    'color'      => 'Color family: red, blue, green, black, white, grey, brown, yellow, orange, pink, purple, multi. Also accepts IT: rosso, blu, verde, nero, bianco, grigio, marrone, giallo, arancione, rosa, viola.',
                    'min_price'  => 'Minimum current price (numeric, merchant currency).',
                    'max_price'  => 'Maximum current price (numeric, merchant currency).',
                    'in_stock'   => 'Boolean. true = in_stock products only.',
                    'physical_only' => 'Boolean. true returns only products that require shipping according to WooCommerce product semantics. It does NOT mean "physical": a downloadable product that still ships is kept, and a pickup-only product is not identified by this flag, because WooCommerce models collection as a shipping method and not as a product property. On a variable product the value is decided by the variations. Opt-in: omitted, the catalog is returned as the merchant published it. Every summary record carries shipping_required and fulfilment, so this filter changes what is returned, never what is disclosed. To tell shipped, downloadable and collected-in-store apart, read fulfilment.',
					'on_sale'    => 'Boolean. true returns products with an active WooCommerce sale price. For variable products this can mean only some size/color variants; verify price.sale_scope and the selected variation. Coupon-only savings are not included.',
                    'per_page'   => 'Results per page (1–100, default 20).',
                    'page'       => 'Page number (1–' . self::catalog_max_page() . ', default 1).',
                    'orderby'    => 'Sort: date (default), price, title, popularity.',
                    'order'      => 'ASC or DESC (default DESC).',
                    'fields'     => 'List/search verbosity. summary = slim per-item projection for low-cost triage; full = complete records. Single-product /catalog/product/{id} defaults to compact verification data; append ?fields=full only when description or images are required. per_page 1-100 applies to list/search.',
                ],

                'introspection' => [
                    'meta_url'  => $base . '/meta',
                    'rule'      => 'Before exploratory search, GET meta_url for populated category slugs, accepted filters, asynchronous facet snapshots and price range. Use categories_url for the complete taxonomy including empty categories.',
                ],
            ],

            'ucp_profile_url'  => home_url( '/.well-known/ucp.json' ),

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
					'status'        => 'pending | cart_loaded | error',
					'status_note'   => 'pending after creation; cart_loaded after the buyer successfully opens a Bridge cart or checkout URL.',
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
                'source'     => 'product verification endpoint: /catalog/product/{id} exposes top-level product attributes plus available variations by default; variants[].attributes remains variation-level evidence',
				'agent_rule' => 'Do not quote exact final price or promise a sale until a variation is selected. Price and sale eligibility may differ per size/color. Use price.sale_scope and purchase_readiness.blocking_fields before handoff.',
                'list_context_note' => 'In list and search responses, variants is an empty array for variable products (performance). Fetch /catalog/product/{id} for the compact verification record and variants list. variants is always an array, never null.',
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
                '4) Use q, never query. Use per_page, never limit. Use /catalog/search for text search and /catalog/products only for browse/listing.',
                '5) Use fields=summary for triage on both /catalog/search and /catalog/products, and rank all candidates from summary data. Fetch /catalog/product/{id} only for the final selected product, unless the user explicitly requests a detail-level comparison. It returns compact verification data by default; append ?fields=full only when description or images are required.',
                '6) Never stack brand, color, gender or price inside q. size is not a search filter — read it from product variations after candidate selection.',
                '7) Read price.current for the actual catalog price. Check stock.in_stock before presenting offers.',
                '8) If active_coupons is present, present coupons as conditional checkout savings only; never replace price.current.',
                '9) Use merchant_shipping_policy/product shipping hints for reasoning, but exact shipping is verified by WooCommerce checkout.',
                '10) This catalog is live WooCommerce — no sync delay. Always current.',
                '11) Taxonomy is merchant-native: use /catalog/categories to enumerate valid category paths, not assumed global taxonomy.',
                '12) Check stock.confidence before reporting availability. If null quantity, report status only.',
                '13) For variable products, read purchase_readiness.blocking_fields before quoting price or proposing checkout.',
                '14) All totals are preliminary until verified at cart/checkout. Label estimates as such.',
                '15) Verify semantic fit before proposing checkout — category match is not sufficient.',
            ] ),

            'placement_guidance' => 'The <link rel="kalicart-agent"> in <head> and the body anchor badge both carry this discovery URL. Head covers head-reading agents; body anchor covers DOM/content-parsing agents. Both are in raw HTML — not injected via JavaScript.',

            'recommended_head_link_html' => '<link rel="kalicart-agent" type="application/json" href="' . esc_url( $discovery ) . '" />' . "\n" . '<link rel="service-desc" type="application/vnd.oai.openapi+json" href="' . esc_url( rest_url( KALICART_BRIDGE_API_NS . '/openapi' ) ) . '" />',

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
                'search'      => $base . '/search?fields=summary',
                'products'    => $base . '/products?fields=summary',
                'product'     => $base . '/product/{id}',
                'product_full'=> $base . '/product/{id}?fields=full',
                'product_verification' => $base . '/product/{id}',
                'categories'  => $base . '/categories',
                'meta'        => $base . '/meta',
                'openapi'     => rest_url( KALICART_BRIDGE_API_NS . '/openapi' ),
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

    /**
     * OpenAPI 3.1 description of the public read-only catalog API. Advertised via
     * <link rel="service-desc"> and the api-catalog linkset so generic agents and
     * API tooling can consume the catalog without knowing the KaliCart convention.
     * Served as JSON; the service-desc link declares the application/vnd.oai.openapi+json type.
     */
    public static function openapi( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
		if ( $limited !== null ) {
			return $limited;
		}
        $param_error = self::catalog_unknown_param_error( $req, [] );
        if ( $param_error !== null ) {
            return $param_error;
        }
        $ns_base = rest_url( KALICART_BRIDGE_API_NS );

        $filter_params = [
            [ 'name' => 'category',  'in' => 'query', 'description' => 'Merchant-native WooCommerce category slug (see /catalog/categories).', 'schema' => [ 'type' => 'string' ] ],
            [ 'name' => 'gender',    'in' => 'query', 'description' => 'Soft gender facet. Canonical values only: male, female, unisex, kids. Unknown product gender is retained with evidence.', 'schema' => [ 'type' => 'string' ] ],
            [ 'name' => 'color',     'in' => 'query', 'description' => 'Strict color-family filter using canonical values from /catalog/meta.', 'schema' => [ 'type' => 'string' ] ],
            [ 'name' => 'min_price', 'in' => 'query', 'description' => 'Minimum catalog price in decimal major currency units. Product price intervals overlap the requested range.', 'schema' => [ 'type' => 'number' ] ],
            [ 'name' => 'max_price', 'in' => 'query', 'description' => 'Maximum catalog price in decimal major currency units. Product price intervals overlap the requested range.', 'schema' => [ 'type' => 'number' ] ],
            [ 'name' => 'in_stock',  'in' => 'query', 'description' => 'Only in-stock products when true.', 'schema' => [ 'type' => 'boolean' ] ],
            [ 'name' => 'on_sale',   'in' => 'query', 'description' => 'Only on-sale products when true.', 'schema' => [ 'type' => 'boolean' ] ],
            [ 'name' => 'physical_only', 'in' => 'query', 'description' => 'Only products that require shipping when true, per WooCommerce product semantics. Not a physical/digital switch: read fulfilment for shipped vs downloadable vs collected in store. Opt-in; omitted, nothing is filtered.', 'schema' => [ 'type' => 'boolean' ] ],
            [ 'name' => 'orderby',   'in' => 'query', 'description' => 'Sort field.', 'schema' => [ 'type' => 'string', 'enum' => [ 'date', 'price', 'title', 'popularity' ], 'default' => 'date' ] ],
            [ 'name' => 'order',     'in' => 'query', 'description' => 'Sort direction.', 'schema' => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ] ],
            [ 'name' => 'per_page',  'in' => 'query', 'description' => 'Items per page (1-100). Parameter name is per_page; do not use limit.', 'schema' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ] ],
            [ 'name' => 'page',      'in' => 'query', 'description' => 'Page number.', 'schema' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::catalog_max_page(), 'default' => 1 ] ],
        ];

        $fields_param_search = [ 'name' => 'fields', 'in' => 'query', 'description' => 'Response verbosity. Default is summary: a slim per-item projection (id, sku, name, url, self-describing price, stock.in_stock, shipping_required, fulfilment, discovery, categories, gender including null, type, updated_at) for low-cost triage; open /catalog/product/{id} for verification. Pass fields=full for complete records.', 'schema' => [ 'type' => 'string', 'enum' => [ 'summary', 'full' ], 'default' => 'summary' ] ];

        $fields_param_products = [ 'name' => 'fields', 'in' => 'query', 'description' => 'Response verbosity. Default is full (complete records); when any filter parameter (category, gender, color, min_price, max_price, in_stock, on_sale, physical_only, orderby, order) is present and fields is omitted, the response switches to summary for low-cost triage. Pass fields explicitly to override.', 'schema' => [ 'type' => 'string', 'enum' => [ 'summary', 'full' ], 'default' => 'full' ] ];

        $q_param = [ 'name' => 'q', 'in' => 'query', 'description' => 'Full-text search query. Parameter name is exactly q; do not use query. Use only on /catalog/search, never on /catalog/products.', 'schema' => [ 'type' => 'string' ] ];

        $list_response = [
            'description' => 'Matching products with pagination.',
            'content'     => [ 'application/json' => [ 'schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'products' => [ 'type' => 'array', 'items' => [ 'oneOf' => [ [ '$ref' => '#/components/schemas/Product' ], [ '$ref' => '#/components/schemas/ProductSummary' ] ] ] ],
                    'total'    => [ 'type' => 'integer' ],
                    'page'     => [ 'type' => 'integer' ],
                    'per_page' => [ 'type' => 'integer' ],
                ],
            ] ] ],
        ];

        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => get_bloginfo( 'name' ) . ' — KaliCart Bridge Catalog API',
                'version'     => KALICART_BRIDGE_VERSION,
                'description' => 'Read-only, machine-readable WooCommerce catalog for AI agents. No authentication, no external calls. Prices and stock are live. Checkout and payment are not exposed — the merchant storefront remains the purchase authority. The same catalog is also available over the Model Context Protocol at /mcp.',
            ],
            'servers' => [ [ 'url' => $ns_base ] ],
            'paths'   => [
                '/discovery' => [ 'get' => [
                    'operationId' => 'getDiscovery',
                    'summary'     => 'Capability and policy discovery document. Start here.',
                    'responses'   => [ '200' => [ 'description' => 'Discovery document.' ] ],
                ] ],
                '/catalog/search' => [ 'get' => [
                    'operationId' => 'searchCatalog',
                    'summary'     => 'Full-text + filter product search. Requires at least one of: q, category, gender, color, on_sale, in_stock.',
                    'parameters'  => array_merge( [ $q_param ], $filter_params, [ $fields_param_search ] ),
                    'responses'   => [ '200' => $list_response, '400' => [ 'description' => 'No search criterion supplied.' ] ],
                ] ],
                '/catalog/products' => [ 'get' => [
                    'operationId' => 'listProducts',
                    'summary'     => 'Paginated product listing with optional filters. This endpoint does not accept q/search/query; use /catalog/search?q=... for text search.',
                    'parameters'  => array_merge( $filter_params, [ $fields_param_products ] ),
                    'responses'   => [ '200' => $list_response, '400' => [ 'description' => 'Invalid search-style parameter supplied to listing endpoint.' ] ],
                ] ],
                '/catalog/product/{id}' => [ 'get' => [
                    'operationId' => 'getProduct',
                    'summary'     => 'Compact verification data for one product by default; full descriptive detail is explicit.',
                    'parameters'  => [
                        [ 'name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'WooCommerce product ID.', 'schema' => [ 'type' => 'integer' ] ],
                        [ 'name' => 'fields', 'in' => 'query', 'description' => 'verification (default; summary is accepted as an alias) returns purchase-relevant facts and variations; full adds descriptions, images and complete metadata.', 'schema' => [ 'type' => 'string', 'enum' => [ 'verification', 'summary', 'full' ], 'default' => 'verification' ] ],
                    ],
                    'responses'   => [ '200' => [ 'description' => 'Product detail.', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/Product' ] ] ] ], '404' => [ 'description' => 'Product not found.' ] ],
                ] ],
                '/catalog/categories' => [ 'get' => [
                    'operationId' => 'listCategories',
                    'summary'     => 'Complete merchant-native WooCommerce category tree, including empty categories.',
                    'responses'   => [ '200' => [ 'description' => 'Category tree.' ] ],
                ] ],
                '/catalog/meta' => [ 'get' => [
                    'operationId' => 'getMeta',
                    'summary'     => 'Populated category list, filter vocabulary and snapshot freshness, plus the catalog price range.',
                    'responses'   => [ '200' => [
                        'description' => 'Catalog filter vocabulary.',
                        'content'     => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/CatalogMetaResponse' ] ] ],
                    ] ],
                ] ],
            ],
            'components' => [ 'schemas' => [
                'CatalogPrice' => [
                    'type'                 => 'object',
                    'additionalProperties' => true,
                    'properties'           => [
                        'type'            => [ 'type' => 'string', 'enum' => [ 'fixed', 'range' ] ],
                        'currency'        => [ 'type' => 'string' ],
                        'encoding'        => [ 'type' => 'string' ],
                        'price_type'      => [ 'type' => 'string' ],
                        'vat_included'    => [ 'type' => 'boolean' ],
                        'tax_enabled'     => [ 'type' => 'boolean' ],
                        'regular'         => [ 'type' => [ 'number', 'null' ] ],
                        'sale'            => [ 'type' => [ 'number', 'null' ] ],
                        'current'         => [ 'type' => [ 'number', 'null' ] ],
                        // PRICE-INTERVAL-v1: su type=range `current` e' l'estremo BASSO
                        // dei prezzi attivi e `max_current` quello alto. Su type=fixed
                        // i due estremi coincidono e max_current non viene emesso.
                        // `type` e' gia' dichiarato in testa a queste properties.
                        'max_current'     => [ 'type' => [ 'number', 'null' ] ],
                        'min_regular'     => [ 'type' => [ 'number', 'null' ] ],
                        'max_regular'     => [ 'type' => [ 'number', 'null' ] ],
                        'min_sale'        => [ 'type' => [ 'number', 'null' ] ],
                        'max_sale'        => [ 'type' => [ 'number', 'null' ] ],
                        'on_sale'         => [ 'type' => 'boolean' ],
                        'sale_scope'      => [ 'type' => 'string', 'enum' => [ 'none', 'single_product', 'some_variants', 'all_variants' ] ],
                        'discounted_variations_count'       => [ 'type' => [ 'integer', 'null' ] ],
                        'priced_variations_count'           => [ 'type' => [ 'integer', 'null' ] ],
                        'variant_selection_required_for_sale' => [ 'type' => 'boolean' ],
                        'discount_pct'       => [ 'type' => [ 'number', 'null' ] ],
                        'discount_amount'    => [ 'type' => [ 'number', 'null' ] ],
                        'discount_pct_scope' => [ 'type' => [ 'string', 'null' ] ],
                        'sale_note'          => [ 'type' => [ 'string', 'null' ] ],
                        'display'            => [ 'type' => [ 'string', 'null' ] ],
                    ],
                ],
                'DealStatistics' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'on_sale_total'       => [ 'type' => 'integer' ],
                        'products_on_sale'    => [ 'type' => 'integer' ],
                        'variations_on_sale'  => [ 'type' => 'integer' ],
                        'sale_entities_total' => [ 'type' => 'integer' ],
                        'lowest_sale_price'   => [ 'type' => [ 'number', 'null' ] ],
                        'note'                => [ 'type' => 'string' ],
                    ],
                ],
                'CatalogMetaResponse' => [
                    'type'                 => 'object',
                    'additionalProperties' => true,
                    'properties'           => [
                        'success'           => [ 'type' => 'boolean' ],
                        'total_products'    => [ 'type' => 'integer' ],
                        'currency'          => [ 'type' => 'string' ],
                        'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'additionalProperties' => true ] ],
                        'available_genders' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        'available_colors'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        'deal_statistics'   => [ '$ref' => '#/components/schemas/DealStatistics' ],
                        'price_range'       => [
                            'type'       => 'object',
                            'properties' => [
                                'min' => [ 'type' => [ 'number', 'null' ] ],
                                'max' => [ 'type' => [ 'number', 'null' ] ],
                            ],
                        ],
                        'accepted_filters'  => [ 'type' => 'object', 'additionalProperties' => true ],
                        'generated_at'      => [ 'type' => 'string', 'format' => 'date-time' ],
                    ],
                ],
                'Product' => [
                    'type'                 => 'object',
                    'description'          => 'Normalized catalog product. Read price.current for the catalog price and stock.in_stock for availability.',
                    'additionalProperties' => true,
                    'properties'           => [
                        'id'         => [ 'type' => 'integer' ],
                        'name'       => [ 'type' => 'string' ],
                        'brand'      => [ 'type' => [ 'string', 'null' ], 'description' => 'Merchant-declared brand (Woo brand taxonomies); null when the merchant has not set one.' ],
                        'url'        => [ 'type' => 'string', 'format' => 'uri' ],
                        'price'      => [ '$ref' => '#/components/schemas/CatalogPrice' ],
                        'stock'      => [ 'type' => 'object', 'additionalProperties' => true ],
                        'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        'attributes' => [
                            'type'        => 'array',
                            'description' => 'Top-level WooCommerce product attributes. Variant-specific selections remain in variants[].attributes.',
                            'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
                        ],
                    ],
                ],
                'ProductSummary' => [
                    'type'                 => 'object',
                    'description'          => 'Slim per-item projection returned when fields=summary applies (default on /catalog/search; automatic on /catalog/products when filter parameters are present). Open /catalog/product/{id} for compact verification.',
                    'additionalProperties' => false,
                    'properties'           => [
                        'id'                 => [ 'type' => 'integer' ],
                        'sku'                => [ 'type' => [ 'string', 'null' ] ],
                        'name'               => [ 'type' => 'string' ],
                        'brand'              => [ 'type' => [ 'string', 'null' ], 'description' => 'Merchant-declared brand (Woo brand taxonomies); null when the merchant has not set one.' ],
                        'url'                => [ 'type' => 'string', 'format' => 'uri' ],
                        'price'              => [ '$ref' => '#/components/schemas/CatalogPrice' ],
                        'stock'              => [ 'type' => 'object', 'additionalProperties' => true ],
                        'shipping_required'  => [ 'type' => 'boolean', 'description' => 'Whether the product requires shipping according to WooCommerce product semantics. On a variable product the variations decide: false only when none of them requires shipping. Unknown resolves to true. Filter on it with physical_only; the shipping quote, zones and thresholds stay in /catalog/product/{id}.' ],
                        'discovery'          => [ 'type' => 'object', 'additionalProperties' => true, 'description' => 'Where the merchant lets this product be found, mirroring WooCommerce catalog visibility one to one. in_catalog: listed in the shop catalog, so reachable by browsing and categories. in_search: returned for a text query. A product excluded from the catalog but kept in search is on sale and purchasable — it is looked up rather than browsed to, and its absence from category listings is not unavailability. A product excluded from both is not served at all.' ],
                        'fulfilment'         => [ 'type' => 'string', 'enum' => [ 'shipped', 'downloadable', 'pickup_only' ], 'description' => 'How the buyer obtains this product. shipped: delivered to an address. downloadable: obtained as a download. pickup_only: requires no shipping and is not a download, so it is a physical item collected from the merchant. Derived from the product, not from the store shipping methods; /catalog/product/{id} names the collection methods the store has configured.' ],
                        'categories'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        'gender'             => [ 'type' => [ 'string', 'null' ] ],
                        'type'               => [ 'type' => 'string' ],
                        'selection_required' => [ 'type' => 'boolean' ],
                        'updated_at'         => [ 'type' => 'string', 'format' => 'date-time' ],
                    ],
                ],
            ] ],
        ];

        $response = new WP_REST_Response( $spec, 200 );
        $response->header( 'Cache-Control', 'public, max-age=3600' );
        return $response;
    }

    public static function ucp_profile( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
		if ( $limited !== null ) {
			return $limited;
		}
        $param_error = self::catalog_unknown_param_error( $req, [] );
        if ( $param_error !== null ) {
            return $param_error;
        }
        $data     = json_decode( KaliCart_Bridge_Signals::ucp_profile_json(), true );
        $response = new WP_REST_Response( is_array( $data ) ? $data : [], 200 );
        $response->header( 'Cache-Control', 'public, max-age=3600' );
        return $response;
    }

    public static function catalog_search( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
        if ( $limited !== null ) {
            return $limited;
        }
        $bounds_error = self::catalog_pagination_error( $req );
        if ( $bounds_error !== null ) {
            return $bounds_error;
        }
        self::force_default_language();
        $param_error = self::catalog_param_alias_error( $req, 'search' );
        if ( $param_error ) {
            return $param_error;
        }
        $unsupported_filter = self::catalog_unsupported_filter_error( $req );
        if ( $unsupported_filter ) {
            return $unsupported_filter;
        }

        $args = self::extract_query_args( $req );
        if ( ! self::query_param_present( $req, 'fields' ) ) {
            $args['fields'] = 'summary';
        }
        $q    = substr( sanitize_text_field( $req->get_param( 'q' ) ?? '' ), 0, 200 );

        if ( empty( $q ) && empty( $args['category'] ) && empty( $args['gender'] ) && empty( $args['color'] ) && $args['on_sale'] !== true && $args['in_stock'] !== true ) {
            return self::error( 'At least one of: q, category, gender, color, on_sale, in_stock is required.', 400 );
        }

        $args['search'] = $q;
        $result = KaliCart_Bridge_Catalog_Engine::query_products( $args );
        $engine_error = self::catalog_engine_error( $result );
        if ( $engine_error !== null ) {
            return $engine_error;
        }

        $result['query'] = array_filter( [
            'q'        => $q ?: null,
            'category' => $args['category'] ?: null,
            'gender'   => $args['gender'] ?: null,
            'color'    => $args['color'] ?: null,
            'min_price'=> $args['min_price'],
            'max_price'=> $args['max_price'],
            'in_stock' => $args['in_stock'],
            'on_sale'  => $args['on_sale'] ?? null,
            'physical_only' => $args['physical_only'] ?? null,
        ], fn( $v ) => $v !== null && $v !== '' );
        self::add_price_query_interpretation( $result, $args );

        if ( (int) ( $result['total'] ?? 0 ) > 0 && $args['fields'] === 'summary' ) {
            // Non sovrascrivere una guidance gia' emessa dal motore: la triage di
            // summary e' un consiglio di navigazione, NO_CONFIRMED_GENDER_MATCHES
            // e' un fatto sul risultato e vale di piu'.
            if ( empty( $result['result_guidance'] ) ) {
                $result['result_guidance'] = self::summary_triage_guidance();
            }
        } elseif ( (int) ( $result['total'] ?? 0 ) === 0 ) {
            if ( empty( $result['result_guidance'] ) ) {
                $result['result_guidance'] = self::zero_results_guidance( $q, $args );
            }
        }

        return self::ok( $result );
    }

    // ── PRODUCTS ──────────────────────────────────────────────────────────────

    public static function catalog_products( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
        if ( $limited !== null ) {
            return $limited;
        }
        $bounds_error = self::catalog_pagination_error( $req );
        if ( $bounds_error !== null ) {
            return $bounds_error;
        }
        self::force_default_language();
        $param_error = self::catalog_param_alias_error( $req, 'products' );
        if ( $param_error ) {
            return $param_error;
        }
        $unsupported_filter = self::catalog_unsupported_filter_error( $req );
        if ( $unsupported_filter ) {
            return $unsupported_filter;
        }

        $args   = self::extract_query_args( $req );
        if ( ! self::query_param_present( $req, 'fields' ) && self::products_request_has_commerce_filters( $req ) ) {
            $args['fields'] = 'summary';
        }
        $result = KaliCart_Bridge_Catalog_Engine::query_products( $args );
        $engine_error = self::catalog_engine_error( $result );
        if ( $engine_error !== null ) {
            return $engine_error;
        }
        if ( (int) ( $result['total'] ?? 0 ) > 0 && $args['fields'] === 'summary' ) {
            // Non sovrascrivere una guidance gia' emessa dal motore: la triage di
            // summary e' un consiglio di navigazione, NO_CONFIRMED_GENDER_MATCHES
            // e' un fatto sul risultato e vale di piu'.
            if ( empty( $result['result_guidance'] ) ) {
                $result['result_guidance'] = self::summary_triage_guidance();
            }
        } elseif ( (int) ( $result['total'] ?? 0 ) === 0 && empty( $result['result_guidance'] ) ) {
            $result['result_guidance'] = self::zero_results_guidance( '', $args );
        }
        self::add_price_query_interpretation( $result, $args );
        return self::ok( $result );
    }

    // ── SINGLE PRODUCT ────────────────────────────────────────────────────────

    public static function catalog_product( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
        if ( $limited !== null ) {
            return $limited;
        }
        self::force_default_language();
        $param_error = self::catalog_unknown_param_error( $req, [ 'fields' ] );
        if ( $param_error !== null ) {
            return $param_error;
        }
        $full = self::catalog_product_full_response( $req );
        if ( $full->get_status() !== 200 ) {
            return $full;
        }

        if ( $req->get_param( 'fields' ) === 'full' ) {
            return $full;
        }

        return self::ok( self::verification_product_projection( $full->get_data() ) );
    }

    private static function catalog_product_full_response( WP_REST_Request $req ): WP_REST_Response {
        $requested_id = absint( $req->get_param( 'id' ) );

        // Multilingual contract: the canonical catalog exists only in the site
        // default language. A translated ID is canonicalized to its default-language
        // counterpart; an ID with no mapping into the default language (a non-default
        // translation without a default sibling, or a language-less orphan) is 404.
        $id = self::canonicalize_post_id( $requested_id, 'product' );
        if ( $id === 0 ) {
            return self::error( 'Product not found.', 404 );
        }

        $p = wc_get_product( $id );

        if ( ! $p || $p->get_status() !== 'publish' ) {
            return self::error( 'Product not found.', 404 );
        }

        // CATALOG-VISIBILITY-v1 — un prodotto che il merchant ha messo su "Nascosto"
        // non esiste per un agente, nemmeno chiedendolo per ID: altrimenti basterebbe
        // conoscere il numero per aggirare la scelta del merchant. Gli altri due stati
        // NON sono esclusioni: "solo catalogo" e "solo ricerca" limitano DOVE si trova
        // il prodotto, non se si puo' verificare quello che si e' gia' trovato, e la
        // verifica per ID e' per definizione un accesso a colpo sicuro.
        // "Nascosto" non si verifica nemmeno per ID: e' l'unico stato in cui il
        // merchant dice "questo non si mostra", e conoscere il numero non deve
        // bastare per aggirarlo. Gli altri due limitano DOVE si trova il prodotto,
        // non se esiste: la verifica per ID arriva sempre dopo che lo si e' gia'
        // trovato, quindi li' non toglie nulla a nessuno.
        if ( 'hidden' === $p->get_catalog_visibility() ) {
            return self::error( 'Product not found.', 404 );
        }

        return self::ok( KaliCart_Bridge_Catalog_Engine::normalize_product( $p, 'detail' ) );
    }

    // ── CATEGORIES ────────────────────────────────────────────────────────────

    public static function catalog_categories( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
        if ( $limited !== null ) {
            return $limited;
        }
        self::force_default_language();
        $param_error = self::catalog_unknown_param_error( $req, [] );
        if ( $param_error !== null ) {
            return $param_error;
        }
        $tree = KaliCart_Bridge_Catalog_Engine::get_categories_tree();

        // S1: count total nodes including nested children for transparency
        $count_nodes = function( array $nodes ) use ( &$count_nodes ): int {
            $n = 0;
            foreach ( $nodes as $node ) {
                $n++;
                if ( ! empty( $node['children'] ) ) {
                    $n += $count_nodes( $node['children'] );
                }
            }
            return $n;
        };
        $total_all = $count_nodes( $tree );

        return self::ok( [
            'note'        => 'Complete merchant-native WooCommerce category taxonomy, including empty categories. Hierarchical: root categories are at top level and subcategories are in children[]. Use category slug in /catalog/search?category={slug}. /catalog/meta returns only populated categories as a compact flat list; use this endpoint when the complete taxonomy matters.',
            'categories'  => $tree,
            'total_root'  => count( $tree ),
            'total_all'   => $total_all,
        ] );
    }

    // ── META ──────────────────────────────────────────────────────────────────

    public static function catalog_meta( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::catalog_rate_limit( $req );
        if ( $limited !== null ) {
            return $limited;
        }
        self::force_default_language();
        $param_error = self::catalog_unknown_param_error( $req, [] );
        if ( $param_error !== null ) {
            return $param_error;
        }
        // Language-aware cache key: a value computed under one language context must
        // never be served under another. Suffix is the default language slug (or
        // 'mono' on monolingual sites).
        $cache_key = 'kalicart_bridge_meta_' . ( self::default_language() ?? 'mono' );
        $cached    = get_transient( $cache_key );
        if ( $cached ) return self::ok( $cached );

        // Categories flat list (default-language only on multilingual sites)
        $flat_args = [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 500 ];
        $flat_lang = self::default_language();
        if ( $flat_lang !== null ) {
            $flat_args['lang'] = $flat_lang;
        }
        $terms = get_terms( $flat_args );
        $categories = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $categories[] = [ 'slug' => $t->slug, 'name' => $t->name, 'count' => $t->count ];
            }
        }

        // S2: read pre-computed catalog facets (built by cron every 6h, stored as option).
        // Missing values or provenance trigger an asynchronous rebuild; the public
        // request never scans the full catalog.
        $facets = KaliCart_Bridge_Catalog_Engine::get_cached_catalog_facets( $flat_lang ?? null );
        $facets_missing = $facets === null;
        if ( $facets_missing ) {
			$facets = [ 'genders' => [], 'colors' => [] ];
        }
        $available_genders = $facets['genders'] ?? [];
        $kc_available_genders = array_values( array_column( $available_genders, 'value' ) );
        $kc_available_colors  = array_values( array_column( $facets['colors'] ?? [], 'value' ) );
        $kc_facets_ts         = (int) get_option( 'kalicart_bridge_catalog_facets_at_' . ( self::default_language() ?? 'mono' ), 0 );
        $kc_facets_computed_at = $kc_facets_ts > 0 ? gmdate( 'c', $kc_facets_ts ) : null;
        $kc_max_staleness_hours = 12;
        if ( $facets_missing || $kc_facets_ts <= 0 ) {
            $kc_freshness_status = 'unknown';
        } elseif ( time() - $kc_facets_ts > $kc_max_staleness_hours * HOUR_IN_SECONDS ) {
            $kc_freshness_status = 'stale';
        } else {
            $kc_freshness_status = 'fresh';
        }
        if ( 'fresh' !== $kc_freshness_status ) {
            self::schedule_catalog_facets_rebuild( $flat_lang ?? null );
        }
        $available_colors  = $facets['colors']  ?? [];

        // Public parent lookup values are WooCommerce's canonical catalog range.
        // Raw _price rows can include stale, private or non-purchasable variations.
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress owns and validates $wpdb->prefix; identifiers cannot be values in a prepared statement on the minimum supported WP version.
        $price_range  = $wpdb->get_row(
            "SELECT MIN(lookup.min_price) AS min_price, MAX(lookup.max_price) AS max_price
             FROM {$wpdb->prefix}wc_product_meta_lookup lookup
             INNER JOIN {$wpdb->posts} product ON product.ID = lookup.product_id
             WHERE product.post_type = 'product'
               AND product.post_status = 'publish'
               AND lookup.min_price > 0"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed table names; catalog_meta caches the bounded aggregate.

        // deal_statistics: pre-computed signals so agents know if it's worth filtering on_sale
        $on_sale_ids    = wc_get_product_ids_on_sale();
		$sale_counts    = self::sale_entity_counts( $on_sale_ids );
		$on_sale_total  = $sale_counts['products_on_sale'];
        $lowest_sale_price = self::lowest_active_sale_price( $on_sale_ids );
        $deal_statistics = [
			'on_sale_total'       => $on_sale_total, // Backward-compatible product-level field.
			'products_on_sale'    => $sale_counts['products_on_sale'],
			'variations_on_sale'  => $sale_counts['variations_on_sale'],
			'sale_entities_total' => $sale_counts['sale_entities_total'],
            'lowest_sale_price'   => $lowest_sale_price,
			'note'                => 'products_on_sale/on_sale_total count published product-level entities flagged on sale by WooCommerce. variations_on_sale counts individually discounted size/color variants. Bridge search also applies its documented >=1% verification. A variable product can have only some variants on sale; verify price.sale_scope and the selected variation.',
        ];

        $meta = [
            'total_products'    => self::published_product_count(),
            'currency'          => get_woocommerce_currency(),
            'categories'        => $categories,
            'categories_scope'  => [
                'population'                 => 'populated_categories_only',
                'shape'                      => 'flat',
                'complete_taxonomy_endpoint' => rest_url( KALICART_BRIDGE_API_NS . '/catalog/categories' ),
                'note'                       => 'This compact list excludes empty categories. Use complete_taxonomy_endpoint for the hierarchical taxonomy including empty categories.',
            ],
            'available_genders' => $available_genders,
            'available_colors'  => $available_colors,
            'deal_statistics'   => $deal_statistics,
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
                'min' => $price_range && $price_range->min_price !== null ? (float) $price_range->min_price : null,
                'max' => $price_range && $price_range->max_price !== null ? (float) $price_range->max_price : null,
            ],
            // 1.0.130 — DUE VOCABOLARI DISTINTI, e non vanno resi uguali.
            //   accepted_values  : il contratto. Stabile, decide la validita'.
            //   available_values : la fotografia di QUESTO catalogo. Informativa,
            //                      non decide mai: e' aggiornata al massimo ogni
            //                      12 ore dal cron dei facet, e rifiutare su un
            //                      dato vecchio mezza giornata negherebbe una
            //                      ricerca legittima su un prodotto appena
            //                      pubblicato.
            // Gli ALIAS sono stati RIMOSSI: lo schema li prometteva e il runtime
            // li rifiutava. E tradurre `uomo`->`male` o `azzurro`->`blue`
            // significherebbe mettere nel Bridge un'interpretazione che sta
            // nell'agente, e obbligare noi a scegliere fra `blue` e `light_blue`.
            // `q` resta linguaggio naturale; i facet sono linguaggio macchina.
            'filter_vocabulary' => [
                'normalization' => 'Formal only: input is trimmed and lowercased. No translation, no aliases, no fuzzy matching.',
                'validation'    => 'Exact membership in accepted_values. A value outside it is rejected with INVALID_FILTER_VALUE and search_executed:false — no search is run.',
                'availability'  => [
                    'meaning'            => 'available_values is the latest asynchronous catalog snapshot. It never rejects a request and must not be used as proof that a value is currently absent.',
                    'computed_at'        => $kc_facets_computed_at,
                    'max_staleness_hours' => $kc_max_staleness_hours,
                    'freshness_status'   => $kc_freshness_status,
                ],
            ],
            'accepted_filters' => [
                'gender' => [
                    'accepted_values'  => KaliCart_Bridge_Catalog_Engine::accepted_facet_values( 'gender' ),
                    'available_values' => $kc_available_genders,
                    'mode'             => 'soft',
                    'mode_note'        => 'A product whose gender differs is excluded; a product whose gender cannot be determined is RETAINED as a candidate and marked unknown_retained. Every result carries filter_evidence.gender, and the response carries gender_summary with the counts over the whole result set.',
                ],
                'color' => [
                    'accepted_values'  => KaliCart_Bridge_Catalog_Engine::accepted_facet_values( 'color' ),
                    'available_values' => $kc_available_colors,
                    'mode'             => 'strict',
                    'mode_note'        => 'A product with no detected colour family is excluded. This is deliberately the opposite of gender: colours are read from attributes, genders are inferred and excluding the unknown ones would lose too much.',
                ],
                'orderby'  => [
                    'values'      => [ 'date', 'price', 'title', 'popularity' ],
                    'default'     => 'date',
                    'default_order' => 'DESC',
					'note'        => 'price sorts by WooCommerce product lookup minimum price with a stable product-ID tiebreaker. Price filters are verified against price.current for authoritative variable-product results.',
                ],
                'boolean'  => [
                    'in_stock' => 'true returns in-stock products only',
                    'physical_only' => 'Boolean. true returns only products that require shipping according to WooCommerce product semantics. It does NOT mean "physical": a downloadable product that still ships is kept, and a pickup-only product is not identified by this flag, because WooCommerce models collection as a shipping method and not as a product property. On a variable product the value is decided by the variations. Opt-in: omitted, the catalog is returned as the merchant published it. Every summary record carries shipping_required and fulfilment, so this filter changes what is returned, never what is disclosed. To tell shipped, downloadable and collected-in-store apart, read fulfilment.',
					'on_sale'  => 'true returns products with an active WooCommerce sale price. A variable product may have only some variants discounted; price.sale_scope and the selected variation are authoritative. Coupon-only savings not included.',
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
        $param_error = self::catalog_unknown_param_error( $req, [ 'force' ] );
        if ( $param_error !== null ) {
            return $param_error;
        }
        $report = KaliCart_Bridge_Quarantine::get_report( (bool) $req->get_param( 'force' ) );
        return self::ok( $report );
    }

    // ── PERMISSIONS ───────────────────────────────────────────────────────────

    public static function require_admin(): bool {
        return current_user_can( 'manage_woocommerce' );
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private static function catalog_engine_error( array $result ): ?WP_REST_Response {
        $error = $result['_error'] ?? null;
        if ( ! is_array( $error ) ) {
            return null;
        }
        $status  = min( 599, max( 400, (int) ( $error['status'] ?? 422 ) ) );
        $message = (string) ( $error['message'] ?? 'Catalog query could not be completed safely.' );
        unset( $error['status'], $error['message'] );
        return self::error( $message, $status, [
            'error_code' => (string) ( $error['code'] ?? 'KALICART_CATALOG_QUERY_ERROR' ),
            'details'    => $error,
        ] );
    }

    private static function catalog_max_page(): int {
        return min( 100000, max( 1, (int) apply_filters( 'kalicart_bridge_catalog_max_page', 1000 ) ) );
    }

	/** Count WooCommerce's active sale IDs by public entity type without loading products one by one. */
	private static function sale_entity_counts( array $on_sale_ids ): array {
		$counts = [
			'products_on_sale'    => 0,
			'variations_on_sale'  => 0,
			'sale_entities_total' => 0,
		];
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $on_sale_ids ) ) ) );
		if ( empty( $ids ) ) {
			return $counts;
		}

		global $wpdb;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$sql = "SELECT post_type, COUNT(*) AS entity_count
				FROM {$wpdb->posts}
				WHERE ID IN ({$placeholders})
				  AND post_status = 'publish'
				  AND post_type IN ('product', 'product_variation')
				GROUP BY post_type";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded classification of trusted WooCommerce sale IDs; catalog_meta caches the result.
			foreach ( (array) $rows as $row ) {
				if ( 'product' === (string) $row->post_type ) {
					$counts['products_on_sale'] += max( 0, (int) $row->entity_count );
				} elseif ( 'product_variation' === (string) $row->post_type ) {
					$counts['variations_on_sale'] += max( 0, (int) $row->entity_count );
				}
			}
		}
		$counts['sale_entities_total'] = $counts['products_on_sale'] + $counts['variations_on_sale'];
		return $counts;
	}

    /** Lowest positive sale price among WooCommerce's currently active public sale entities. */
    private static function lowest_active_sale_price( array $on_sale_ids ): ?float {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $on_sale_ids ) ) ) );
        if ( empty( $ids ) ) {
            return null;
        }

        global $wpdb;
        $lowest = null;
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
            $sql = "SELECT MIN(CAST(sale.meta_value AS DECIMAL(19,4))) AS lowest_sale_price
                FROM {$wpdb->posts} entity
                INNER JOIN {$wpdb->postmeta} sale
                    ON sale.post_id = entity.ID AND sale.meta_key = '_sale_price'
                LEFT JOIN {$wpdb->posts} parent ON parent.ID = entity.post_parent
                WHERE entity.ID IN ({$placeholders})
                  AND entity.post_status = 'publish'
                  AND entity.post_type IN ('product', 'product_variation')
                  AND (entity.post_type = 'product' OR (parent.post_type = 'product' AND parent.post_status = 'publish'))
                  AND sale.meta_value <> ''
                  AND CAST(sale.meta_value AS DECIMAL(19,4)) > 0";
            $value = $wpdb->get_var( $wpdb->prepare( $sql, ...$chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded aggregate over trusted active sale IDs; catalog_meta caches the result.
            if ( $value !== null && ( $lowest === null || (float) $value < $lowest ) ) {
                $lowest = (float) $value;
            }
        }
        return $lowest;
    }

    /** Explicit for in-process MCP calls, which do not pass through REST arg validation. */
    private static function catalog_pagination_error( WP_REST_Request $request ): ?WP_REST_Response {
        $page_raw = $request->get_param( 'page' );
        if ( $page_raw !== null ) {
            $page = filter_var( $page_raw, FILTER_VALIDATE_INT );
            if ( $page === false || $page < 1 || $page > self::catalog_max_page() ) {
                return self::error( 'page is outside the safe catalog pagination range.', 422, [
                    'error_code' => 'KALICART_CATALOG_PAGE_OUT_OF_RANGE',
                    'details'    => [ 'minimum' => 1, 'maximum' => self::catalog_max_page() ],
                ] );
            }
        }

        $per_page_raw = $request->get_param( 'per_page' );
        if ( $per_page_raw !== null ) {
            $per_page = filter_var( $per_page_raw, FILTER_VALIDATE_INT );
            if ( $per_page === false || $per_page < 1 || $per_page > 100 ) {
                return self::error( 'per_page must be an integer between 1 and 100.', 422, [
                    'error_code' => 'KALICART_CATALOG_PER_PAGE_OUT_OF_RANGE',
                ] );
            }
        }
        return null;
    }

    private static function catalog_rate_limited_response( int $window ): WP_REST_Response {
        $response = self::error( 'Too many catalog requests. Please slow down and retry.', 429, [
            'error_code' => 'KALICART_CATALOG_RATE_LIMITED',
        ] );
        $response->header( 'Retry-After', (string) max( 1, $window ) );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    private static function catalog_rate_limit( ?WP_REST_Request $request = null ): ?WP_REST_Response {
        if ( self::$internal_catalog_depth > 0 ) {
            return null;
        }
		$cost = 1;
		if ( $request instanceof WP_REST_Request ) {
			$route = (string) $request->get_route();
			$query = $request->get_query_params();
			if ( false !== strpos( $route, '/catalog/products' ) || false !== strpos( $route, '/catalog/search' ) ) {
				$per_page = min( 100, max( 1, absint( $query['per_page'] ?? 20 ) ) );
				if ( isset( $query['fields'] ) ) {
					$fields = (string) $query['fields'];
				} elseif ( false !== strpos( $route, '/catalog/search' ) || self::products_request_has_commerce_filters( $request ) ) {
					$fields = 'summary';
				} else {
					$fields = 'full';
				}
				$cost     = 'full' === $fields ? (int) ceil( $per_page / 10 ) : (int) ceil( $per_page / 50 );
				foreach ( [ 'gender', 'color', 'on_sale', 'physical_only', 'min_price', 'max_price' ] as $derived ) {
					if ( isset( $query[ $derived ] ) && '' !== (string) $query[ $derived ] && 'false' !== strtolower( (string) $query[ $derived ] ) ) {
						$cost += 2;
						break;
					}
				}
			} elseif ( preg_match( '#/catalog/product/\d+$#', $route ) ) {
				$cost = 3;
			} elseif ( false !== strpos( $route, '/catalog/meta' ) || false !== strpos( $route, '/catalog/categories' ) ) {
				$cost = 2;
			}
		}
		$cost = min( 20, max( 1, $cost ) );

		$result = KaliCart_Bridge_Rate_Guard::check( 'catalog', $cost, [
			'client_limit'  => max( 0, (int) apply_filters( 'kalicart_bridge_catalog_rate_limit_per_client', 60 ) ),
            'client_window' => min( HOUR_IN_SECONDS, max( 1, (int) apply_filters( 'kalicart_bridge_catalog_rate_limit_per_client_secs', 60 ) ) ),
			'global_limit'  => max( 0, (int) apply_filters( 'kalicart_bridge_catalog_rate_limit_global', 40 ) ),
            'global_window' => min( HOUR_IN_SECONDS, max( 1, (int) apply_filters( 'kalicart_bridge_catalog_rate_limit_global_secs', 10 ) ) ),
        ] );
        if ( ! $result['allowed'] ) {
            return self::catalog_rate_limited_response( $result['retry_after'] );
        }
        return null;
    }

    private static function extract_query_args( WP_REST_Request $req ): array {
        return [
            'search'    => '',
            'category'  => substr( sanitize_text_field( $req->get_param( 'category' ) ?? '' ), 0, 200 ),
            'per_page'  => min( 100, max( 1, absint( $req->get_param( 'per_page' ) ?? 20 ) ) ),
            'page'      => max( 1, absint( $req->get_param( 'page' ) ?? 1 ) ),
            'orderby'   => in_array( $req->get_param( 'orderby' ), [ 'date', 'price', 'title', 'popularity' ], true ) ? $req->get_param( 'orderby' ) : 'date',
            'order'     => strtoupper( $req->get_param( 'order' ) ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
            'in_stock'  => $req->get_param( 'in_stock' ) !== null ? filter_var( $req->get_param( 'in_stock' ), FILTER_VALIDATE_BOOLEAN ) : null,
            'on_sale'   => $req->get_param( 'on_sale' ) !== null ? filter_var( $req->get_param( 'on_sale' ), FILTER_VALIDATE_BOOLEAN ) : null,
            'min_price' => $req->get_param( 'min_price' ) !== null ? (float) $req->get_param( 'min_price' ) : null,
            'max_price' => $req->get_param( 'max_price' ) !== null ? (float) $req->get_param( 'max_price' ) : null,
            'gender'    => substr( sanitize_text_field( $req->get_param( 'gender' ) ?? '' ), 0, 64 ),
            'color'     => substr( sanitize_text_field( $req->get_param( 'color' ) ?? '' ), 0, 64 ),
            'modified_after' => self::sanitize_iso8601( $req->get_param( 'modified_after' ) ),
            'physical_only' => $req->get_param( 'physical_only' ) !== null ? filter_var( $req->get_param( 'physical_only' ), FILTER_VALIDATE_BOOLEAN ) : null,
            'fields'    => $req->get_param( 'fields' ) === 'summary' ? 'summary' : 'full',
        ];
    }

    /**
     * Validate an incremental-sync cursor as an ISO-8601 datetime.
     * Returns the normalized 'Y-m-d H:i:s' (GMT) string, or '' if absent/invalid.
     * Invalid input is dropped (treated as full sync) rather than erroring.
     */
    private static function sanitize_iso8601( $raw ): string {
        $raw = is_string( $raw ) ? substr( trim( $raw ), 0, 64 ) : '';
        if ( $raw === '' ) {
            return '';
        }
        $ts = strtotime( $raw );
        if ( $ts === false ) {
            return '';
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }

    private static function common_filter_args( bool $with_q ): array {
        $short_text = static fn( $value ): bool => is_scalar( $value ) && strlen( (string) $value ) <= 200;
        $facet_text = static fn( $value ): bool => is_scalar( $value ) && strlen( (string) $value ) <= 64;
        $facet_norm = static fn( $value ): string => KaliCart_Bridge_Catalog_Engine::normalize_facet_value( substr( (string) sanitize_text_field( (string) $value ), 0, 64 ) );
        // Si restituisce un WP_Error invece di false: `rest_invalid_param` dice
        // soltanto che qualcosa non va, e costringe l'agente a indovinare cosa.
        // L'errore strutturato porta gia' accepted_values, cosi' si corregge al
        // primo colpo senza una chiamata informativa aggiuntiva. `search_executed`
        // e' parte sostanziale del contratto, non messaggistica: un filtro non
        // valido non deve MAI produrre risultati.
        $facet_valid = static function ( $value, $request, $param ) {
            $err = KaliCart_Bridge_Catalog_Engine::validate_facet_value( (string) $param, $value );
            if ( null === $err ) {
                return true;
            }
            return new WP_Error( 'INVALID_FILTER_VALUE', sprintf(
                /* translators: 1: parameter name, 2: received value */
                __( '%1$s: "%2$s" is not an accepted value. See accepted_values.', 'kalicart-bridge' ),
                (string) $param,
                (string) $err['received']
            ), [ 'status' => 400 ] + $err );
        };
        $max_page   = self::catalog_max_page();
        $args = [
            'category'  => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => $short_text ],
            'per_page'  => [ 'default' => 20, 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $v ): bool => filter_var( $v, FILTER_VALIDATE_INT ) !== false && (int) $v >= 1 && (int) $v <= 100 ],
            'page'      => [ 'default' => 1,  'sanitize_callback' => 'absint', 'validate_callback' => static fn( $v ): bool => filter_var( $v, FILTER_VALIDATE_INT ) !== false && (int) $v >= 1 && (int) $v <= $max_page ],
            'orderby'   => [ 'default' => 'date', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $v ): bool => in_array( $v, [ 'date', 'price', 'title', 'popularity' ], true ) ],
            'order'     => [ 'default' => 'DESC', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $v ): bool => in_array( strtoupper( (string) $v ), [ 'ASC', 'DESC' ], true ) ],
            'in_stock'  => [ 'default' => null ],
            'on_sale'   => [ 'default' => null ],
            'min_price' => [ 'default' => null, 'validate_callback' => static fn( $v ): bool => $v === null || ( is_numeric( $v ) && is_finite( (float) $v ) ) ],
            'max_price' => [ 'default' => null, 'validate_callback' => static fn( $v ): bool => $v === null || ( is_numeric( $v ) && is_finite( (float) $v ) ) ],
            // 1.0.130 — i facet sono linguaggio macchina e vanno validati contro il
            // vocabolario canonico, non solo per lunghezza. Prima un valore
            // inesistente come `gender=inventato` passava e restituiva 96 prodotti
            // a genere sconosciuto: un risultato plausibile e sbagliato, che un
            // agente non ha modo di riconoscere. La normalizzazione (trim +
            // lowercase) avviene nel sanitize, la validita' nel validate.
            'gender'    => [ 'default' => '', 'sanitize_callback' => $facet_norm, 'validate_callback' => $facet_valid ],
            'color'     => [ 'default' => '', 'sanitize_callback' => $facet_norm, 'validate_callback' => $facet_valid ],
            // Incremental sync: federated indexers pass an ISO-8601 timestamp to fetch
            // only products modified since their last sync (post_modified_gmt). Read-only.
            'modified_after' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => $facet_text ],
            // Opt-in, never a default. The Bridge serves the merchant's catalog as the
            // merchant published it; a caller bound to physical goods (a distribution
            // channel that refuses digital items, say) declares that constraint here
            // instead of the Bridge deciding it for every merchant.
            'physical_only' => [ 'default' => null ],
            'fields'    => [ 'default' => 'full', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $v ): bool => in_array( $v, [ 'full', 'summary' ], true ) ],
        ];
        if ( $with_q ) {
            $args['q'] = [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => $short_text ];
        }
        return $args;
    }

    private static function ok( array $data ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    private static function error( string $message, int $status = 400, array $extra = [] ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => false, 'message' => $message ], $extra ), $status );
    }

    private static function query_param_present( WP_REST_Request $req, string $name ): bool {
        return array_key_exists( $name, $req->get_query_params() );
    }

    private static function catalog_unknown_param_error( WP_REST_Request $req, array $accepted ): ?WP_REST_Response {
        $received = array_keys( $req->get_query_params() );
        $unknown  = array_values( array_diff( $received, $accepted ) );
        if ( empty( $unknown ) ) {
            return null;
        }
        sort( $unknown );
        sort( $accepted );
        return self::error( 'Unknown query parameters are not accepted.', 400, [
            'error_code'          => 'KALICART_UNKNOWN_QUERY_PARAMETERS',
            'invalid_parameters'  => $unknown,
            'accepted_parameters' => array_values( $accepted ),
            'search_executed'     => false,
        ] );
    }

    private static function schedule_catalog_facets_rebuild( ?string $lang ): void {
        $cron_args = [ $lang ];
        $next      = wp_next_scheduled( 'kalicart_bridge_facets_rebuild', $cron_args );
        // A recurring rebuild hours away does not repair missing provenance. Queue
        // a near-term single event, while avoiding duplicates already due shortly.
        if ( ! $next || $next > time() + MINUTE_IN_SECONDS ) {
            wp_schedule_single_event( time() + 1, 'kalicart_bridge_facets_rebuild', $cron_args );
        }
    }

    private static function catalog_unsupported_filter_error( WP_REST_Request $req ): ?WP_REST_Response {
        $size = $req->get_param( 'size' );
        if ( $size === null || ! is_scalar( $size ) || trim( (string) $size ) === '' ) {
            return null;
        }
        return self::error(
            'size is not a catalog search filter. Fetch /catalog/product/{id} and inspect purchasable variants for size availability.',
            400,
            [
                'error_code'         => 'KALICART_UNSUPPORTED_FILTER',
                'unsupported_filter' => 'size',
                'next_step'          => 'Select candidate products first, then verify the requested size in /catalog/product/{id}.variants.',
            ]
        );
    }

    private static function products_request_has_commerce_filters( WP_REST_Request $req ): bool {
        foreach ( [ 'category', 'gender', 'color', 'min_price', 'max_price', 'in_stock', 'on_sale', 'physical_only', 'orderby', 'order' ] as $name ) {
            if ( self::query_param_present( $req, $name ) ) {
                return true;
            }
        }
        return false;
    }

    private static function catalog_param_alias_error( WP_REST_Request $req, string $endpoint ): ?WP_REST_Response {
        $invalid = [];

        if ( self::query_param_present( $req, 'query' ) ) {
            $invalid['query'] = 'q';
        }
        if ( self::query_param_present( $req, 'limit' ) ) {
            $invalid['limit'] = 'per_page';
        }
        if ( self::query_param_present( $req, 'price_min' ) ) {
            $invalid['price_min'] = 'min_price';
        }
        if ( self::query_param_present( $req, 'price_max' ) ) {
            $invalid['price_max'] = 'max_price';
        }
        if ( self::query_param_present( $req, 'search' ) ) {
            $invalid['search'] = $endpoint === 'products' ? '/catalog/search?q=...' : 'q';
        }
        if ( $endpoint === 'products' && self::query_param_present( $req, 'q' ) ) {
            $invalid['q'] = '/catalog/search?q=...';
        }

        $accepted = [ 'category', 'per_page', 'page', 'orderby', 'order', 'in_stock', 'on_sale', 'physical_only', 'min_price', 'max_price', 'gender', 'color', 'modified_after', 'fields' ];
        if ( 'search' === $endpoint ) {
            $accepted[] = 'q';
        }
        $recognized_noncanonical = [ 'query', 'limit', 'price_min', 'price_max', 'search', 'size' ];
        if ( 'products' === $endpoint ) {
            $recognized_noncanonical[] = 'q';
        }
        $unknown = array_values( array_diff( array_keys( $req->get_query_params() ), $accepted, $recognized_noncanonical ) );
        sort( $unknown );

        if ( empty( $invalid ) && empty( $unknown ) ) {
            return null;
        }
        if ( empty( $invalid ) ) {
            return self::catalog_unknown_param_error( $req, $accepted );
        }

        $target = ( $endpoint === 'products' && ( isset( $invalid['q'] ) || isset( $invalid['query'] ) || isset( $invalid['search'] ) ) )
            ? 'search'
            : $endpoint;

        $message = $target === 'search'
            ? 'Invalid catalog search parameters. Use the parameter_corrections map and suggested_url.'
            : 'Invalid catalog listing parameters. Use the parameter_corrections map and suggested_url.';

        return self::error( $message, 400, [
            'error_code'            => 'KALICART_INVALID_CATALOG_PARAMETERS',
            'invalid_parameters'    => array_values( array_unique( array_merge( array_keys( $invalid ), $unknown ) ) ),
            'parameter_corrections' => $invalid,
            'accepted_parameters'   => $accepted,
            'correct_endpoint'      => add_query_arg( 'fields', 'summary', rest_url( KALICART_BRIDGE_API_NS . '/catalog/' . $target ) ),
            'suggested_url'         => self::suggested_catalog_url( $req, $target ),
            'agent_guidance'        => 'Use /catalog/search?q=... for text search and only accepted_parameters. Do not use search, query, limit, price_min or price_max. Follow suggested_url exactly.',
            'search_executed'       => false,
        ] );
    }

    private static function suggested_catalog_url( WP_REST_Request $req, string $target ): string {
        $params = [
            'fields' => self::query_param_present( $req, 'fields' ) && $req->get_param( 'fields' ) === 'full' ? 'full' : 'summary',
        ];

        if ( $target === 'search' ) {
            $q = $req->get_param( 'q' );
            if ( $q === null || $q === '' ) {
                $q = $req->get_param( 'query' );
            }
            if ( $q === null || $q === '' ) {
                $q = $req->get_param( 'search' );
            }
            $q = sanitize_text_field( $q ?? '' );
            if ( $q !== '' ) {
                $params['q'] = $q;
            }
        }

        foreach ( [ 'category', 'gender', 'color', 'min_price', 'max_price', 'in_stock', 'on_sale', 'physical_only', 'orderby', 'order', 'page', 'modified_after' ] as $key ) {
            if ( self::query_param_present( $req, $key ) ) {
                $value = $req->get_param( $key );
                if ( $value !== null && $value !== '' ) {
                    $params[ $key ] = sanitize_text_field( (string) $value );
                }
            }
        }

        foreach ( [ 'price_min' => 'min_price', 'price_max' => 'max_price' ] as $alias => $canonical ) {
            if ( ! isset( $params[ $canonical ] ) && self::query_param_present( $req, $alias ) ) {
                $value = $req->get_param( $alias );
                if ( $value !== null && $value !== '' ) {
                    $params[ $canonical ] = sanitize_text_field( (string) $value );
                }
            }
        }

        if ( self::query_param_present( $req, 'per_page' ) || self::query_param_present( $req, 'limit' ) ) {
            $raw = self::query_param_present( $req, 'per_page' ) ? $req->get_param( 'per_page' ) : $req->get_param( 'limit' );
            $params['per_page'] = min( 100, max( 1, absint( $raw ) ) );
        }

        return add_query_arg( $params, rest_url( KALICART_BRIDGE_API_NS . '/catalog/' . $target ) );
    }

    private static function add_price_query_interpretation( array &$result, array $args ): void {
        if ( $args['min_price'] === null && $args['max_price'] === null ) {
            return;
        }
        $result['query_interpretation'] = [
            'currency'         => get_woocommerce_currency(),
            'price_unit'       => 'decimal_major_units',
            'requested_range'  => [
                'min' => $args['min_price'],
                'max' => $args['max_price'],
            ],
            'price_match_rule' => 'product_price_interval_overlaps_requested_range',
        ];
    }

    private static function zero_results_guidance( string $q, array $args ): array {
        $current_query = array_filter( [
            'q'         => $q ?: null,
            'category'  => $args['category'] ?: null,
            'gender'    => $args['gender'] ?: null,
            'color'     => $args['color'] ?: null,
            'min_price' => $args['min_price'],
            'max_price' => $args['max_price'],
            'in_stock'  => $args['in_stock'],
            'on_sale'   => $args['on_sale'] ?? null,
            'physical_only' => $args['physical_only'] ?? null,
        ], fn( $v ) => $v !== null && $v !== '' );

        $generic = [
            'code'          => 'ZERO_RESULTS_RECOVERY',
            'reason'        => 'No products matched the current query and filters.',
            'next_steps'    => [
                'If q contains attributes, retry with a barer product noun in q and move attributes into filters.',
                'Fetch /catalog/meta to inspect accepted filters and price range.',
                'Fetch /catalog/categories to browse valid merchant category slugs.',
                'Zero results proves nothing about the catalog, only about the query. After bare-q search and category browse both return 0, report it as not found with these terms — not as unavailable.',
            ],
            'current_query' => $current_query,
        ];

        $filters = self::active_zero_result_filters( $q, $args );
        if ( 1 === count( $filters ) ) {
            $only = array_key_first( $filters );
            return self::specific_zero_result_guidance( $only, $filters[ $only ], $current_query, [
                'method'      => 'original_query_complete_evaluation',
                'total'       => 0,
                'verified_at' => gmdate( 'c' ),
            ] ) ?? $generic;
        }

        // Combination attribution is deliberately bounded. Exactly two filters
        // permit two drop-one probes; more complex queries retain generic guidance
        // because assigning a cause would require a combinatorial search.
        if ( 2 !== count( $filters ) ) {
            return $generic;
        }

        $names  = array_keys( $filters );
        $probes = [];
        foreach ( $names as $drop ) {
            $probe_args = self::drop_zero_result_filter( $args, $drop );
            $probe_q    = 'q' === $drop ? '' : $q;
            $probe_args['search']   = $probe_q;
            $probe_args['page']     = 1;
            $probe_args['per_page'] = 1;
            $probe_args['fields']   = 'summary';
            $probe_result = KaliCart_Bridge_Catalog_Engine::query_products( $probe_args );
            if ( isset( $probe_result['_error'] ) || ! isset( $probe_result['total'] ) ) {
                return $generic;
            }
            $probes[ $drop ] = (int) $probe_result['total'];
        }

        if ( $probes[ $names[0] ] > 0 && $probes[ $names[1] ] > 0 ) {
            return [
                'code'                 => 'FILTER_COMBINATION_ELIMINATED_RESULTS',
                'reason'               => 'Each filter produced results when the other was removed, but their combination produced none.',
                'current_query'        => $current_query,
                'classification_proof' => [
                    'method'          => 'two_drop_one_existence_probes',
                    'evaluation_complete' => true,
                    'dropped_filter_totals' => $probes,
                    'verified_at'     => gmdate( 'c' ),
                ],
                'next_steps'           => [ 'Relax one of the two filters and retry.' ],
            ];
        }

        foreach ( $names as $candidate ) {
            $other = $names[0] === $candidate ? $names[1] : $names[0];
            // Dropping the other filter leaves the candidate alone. A zero there,
            // while the other filter alone has matches, proves the candidate value
            // is currently unobserved without consulting the facet snapshot.
            if ( 0 === $probes[ $other ] && $probes[ $candidate ] > 0 ) {
                $specific = self::specific_zero_result_guidance( $candidate, $filters[ $candidate ], $current_query, [
                    'method'                => 'two_drop_one_existence_probes',
                    'evaluation_complete'   => true,
                    'dropped_filter_totals' => $probes,
                    'verified_at'           => gmdate( 'c' ),
                ] );
                if ( $specific !== null ) {
                    return $specific;
                }
            }
        }

        return $generic;
    }

    private static function active_zero_result_filters( string $q, array $args ): array {
        $filters = [];
        if ( '' !== $q ) {
            $filters['q'] = $q;
        }
        foreach ( [ 'category', 'gender', 'color' ] as $name ) {
            if ( ! empty( $args[ $name ] ) ) {
                $filters[ $name ] = $args[ $name ];
            }
        }
        if ( $args['min_price'] !== null || $args['max_price'] !== null ) {
            $filters['price_range'] = [ 'min' => $args['min_price'], 'max' => $args['max_price'] ];
        }
        if ( $args['in_stock'] === true ) {
            $filters['in_stock'] = true;
        }
        if ( ( $args['on_sale'] ?? null ) === true ) {
            $filters['on_sale'] = true;
        }
        if ( ( $args['physical_only'] ?? null ) === true ) {
            $filters['physical_only'] = true;
        }
        return $filters;
    }

    private static function drop_zero_result_filter( array $args, string $name ): array {
        if ( 'q' === $name ) {
            $args['search'] = '';
        } elseif ( 'price_range' === $name ) {
            $args['min_price'] = null;
            $args['max_price'] = null;
        } elseif ( in_array( $name, [ 'category', 'gender', 'color' ], true ) ) {
            $args[ $name ] = '';
        } elseif ( in_array( $name, [ 'in_stock', 'on_sale', 'physical_only' ], true ) ) {
            $args[ $name ] = null;
        }
        return $args;
    }

    private static function specific_zero_result_guidance( string $name, $value, array $current_query, array $proof ): ?array {
        if ( in_array( $name, [ 'gender', 'color' ], true )
            && null === KaliCart_Bridge_Catalog_Engine::validate_facet_value( $name, $value ) ) {
            return [
                'code'                 => 'VALID_FILTER_VALUE_NOT_OBSERVED',
                'reason'               => 'The filter value is valid under the current contract, but a complete current query found no product carrying it.',
                'filter'               => $name,
                'value'                => $value,
                'current_query'        => $current_query,
                'classification_proof' => $proof,
                'next_steps'           => [ 'Remove this filter or choose another accepted value, then retry.' ],
            ];
        }
        if ( 'category' === $name ) {
            $term = get_term_by( 'slug', (string) $value, 'product_cat' );
            if ( $term instanceof WP_Term ) {
                return [
                    'code'                 => 'VALID_CATEGORY_NO_RESULTS',
                    'reason'               => 'The category slug exists in the current WooCommerce taxonomy, but the current complete query found no matching products.',
                    'category'             => (string) $value,
                    'current_query'        => $current_query,
                    'classification_proof' => $proof + [ 'term_id' => (int) $term->term_id ],
                    'next_steps'           => [ 'Browse /catalog/categories and choose another category, or remove the category filter.' ],
                ];
            }
        }
        return null;
    }

    private static function summary_triage_guidance(): array {
        return [
            'code'          => 'SUMMARY_TRIAGE',
            'response_mode' => 'summary',
            'next_step'     => 'rank_from_summary_then_verify_one_selected_product',
            'fact_coverage' => [
                // `shipping_requirement` is the yes/no fact (does this product ship at
                // all) and the summary settles it. `shipping` stays in
                // detail_required_for because the quote, zones and free-shipping
                // thresholds are a different question and still need the detail call.
                'complete_for' => [ 'product_identity', 'catalog_price', 'sale_status', 'availability_status', 'product_url', 'category', 'gender_or_explicit_null', 'selection_required', 'shipping_requirement' ],
                'detail_required_for' => [ 'exact_variants_or_sizes', 'stock_precision_beyond_status', 'shipping_cost_and_zones', 'coupons', 'purchase_readiness', 'description', 'images' ],
            ],
            'detail_fetch_policy' => [
                'default_max_products'       => 1,
                'rank_candidates_from_summary_only' => true,
                'selected_product_only'      => true,
                'non_selected_product_detail'=> 'do_not_fetch',
                'fetch_after_selection'      => true,
                'skip_when_summary_suffices' => true,
                'multiple_products_only_when'=> 'user_explicitly_requests_detail_level_comparison; finding_or_ranking_multiple_candidates_does_not_qualify',
                'verification_url_template'  => rest_url( KALICART_BRIDGE_API_NS . '/catalog/product/{id}' ),
                'full_detail_only_when'      => 'description_or_images_required',
            ],
        ];
    }

    private static function verification_product_projection( array $product ): array {
        $compact_price = static function( array $price ): array {
            return array_filter( [
                'currency'        => $price['currency'] ?? null,
                'encoding'        => $price['encoding'] ?? 'decimal_major_units',
                // PRICE-INTERVAL-v1 — vedi class-catalog-engine.php: su un variabile
                // `current` e' l'estremo basso. `type` e `max_current` lo dichiarano.
                'type'            => $price['type'] ?? null,
                'current'         => $price['current'] ?? null,
                'max_current'     => $price['max_current'] ?? null,
                'regular'         => $price['regular'] ?? $price['min_regular'] ?? null,
                'max_regular'     => $price['max_regular'] ?? null,
                'display'         => $price['display'] ?? null,
                'on_sale'         => $price['on_sale'] ?? false,
                'discount_pct'    => $price['discount_pct'] ?? null,
                'discount_amount' => $price['discount_amount'] ?? null,
            ], fn( $value ) => $value !== null );
        };

        $compact_stock = static function( array $stock ): array {
            return array_filter( [
                'in_stock'         => $stock['in_stock'] ?? null,
                'quantity'         => $stock['quantity'] ?? null,
                'quantity_tracked' => $stock['quantity_tracked'] ?? null,
                'confidence'       => $stock['confidence'] ?? null,
                'agent_note'       => $stock['agent_note'] ?? null,
            ], fn( $value ) => $value !== null );
        };

        $variants = array_map( static function( array $variant ) use ( $compact_price, $compact_stock ): array {
            $variant_stock = $variant['stock'] ?? [
                'in_stock' => $variant['in_stock'] ?? null,
            ];
            return [
                'variation_id' => $variant['variation_id'] ?? null,
                'attributes'   => $variant['attributes'] ?? [],
                'price'        => $compact_price( $variant['price'] ?? [] ),
                'stock'        => $compact_stock( $variant_stock ),
                'sku'          => $variant['sku'] ?? null,
                'purchasable'  => $variant['purchasable'] ?? null,
            ];
        }, $product['variants'] ?? [] );

        $coupons = array_map( static function( array $coupon ): array {
            return array_filter( [
                'code'                        => $coupon['code'] ?? null,
                'discount_type'               => $coupon['discount_type'] ?? null,
                'amount'                      => $coupon['amount'] ?? null,
                'currency'                    => $coupon['currency'] ?? null,
                'estimated_saving_on_product' => $coupon['estimated_saving_on_product'] ?? null,
                'free_shipping'               => $coupon['free_shipping'] ?? null,
                'combinable_with_sale'        => $coupon['combinable_with_sale'] ?? null,
                'verification_required'       => $coupon['verification_required'] ?? true,
            ], fn( $value ) => $value !== null );
        }, $product['active_coupons'] ?? [] );

        $shipping = $product['shipping'] ?? [];
        return [
            'response_mode'      => 'verification',
            'verification_scope' => [
                'role'                    => 'final_selected_product',
                'additional_product_fetch'=> 'only_for_explicit_detail_level_comparison',
            ],
            'id'                 => $product['id'] ?? null,
            'sku'                => $product['sku'] ?? null,
            'type'               => $product['type'] ?? null,
            'name'               => $product['name'] ?? null,
            'brand'              => $product['brand'] ?? null,
            'url'                => $product['url'] ?? null,
            'checkout_url'       => $product['checkout_url'] ?? null,
            'price'              => $compact_price( $product['price'] ?? [] ),
            'stock'              => $compact_stock( $product['stock'] ?? [] ),
            'attributes'         => $product['attributes'] ?? [],
            // La proiezione di verifica elencava le condizioni di spedizione SEMPRE,
            // con `?? null`: anche quando il motore aveva gia' smesso di emetterle
            // perche' il prodotto non si spedisce, qui rientravano dalla finestra e
            // l'agente rileggeva la contraddizione. Si sceglie il blocco, non i
            // singoli campi.
            'shipping'           => ( ( $shipping['shipping_required'] ?? null ) === false )
                ? [
                    'shipping_required'      => false,
                    'fulfilment'             => $shipping['fulfilment'] ?? 'no_shipping_required',
                    'local_pickup_available' => $shipping['local_pickup_available'] ?? false,
                    'local_pickup'           => $shipping['local_pickup'] ?? null,
                    'delivery_note'          => $shipping['delivery_note'] ?? null,
                    'merchant_policy_url'    => rest_url( KALICART_BRIDGE_API_NS . '/catalog/meta' ),
                    'authority'              => $shipping['authority'] ?? 'woocommerce_checkout',
                ]
                : [
                'shipping_required'                       => $shipping['shipping_required'] ?? null,
                'fulfilment'                               => $shipping['fulfilment'] ?? 'shipped',
                'free_shipping_available'                  => $shipping['free_shipping_available'] ?? null,
                'free_shipping_thresholds'                 => $shipping['free_shipping_thresholds'] ?? [],
                'free_shipping_eligible_by_product_price'  => $shipping['free_shipping_eligible_by_product_price'] ?? null,
                'amount_to_nearest_free_shipping_threshold'=> $shipping['amount_to_nearest_free_shipping_threshold'] ?? null,
                'merchant_policy_url'                      => rest_url( KALICART_BRIDGE_API_NS . '/catalog/meta' ),
                'authority'                                => $shipping['authority'] ?? 'woocommerce_checkout',
            ],
            'active_coupons'     => $coupons,
            'purchase_readiness' => $product['purchase_readiness'] ?? null,
            'variants'           => $variants,
            'updated_at'         => $product['updated_at'] ?? null,
            'authority_note'     => 'Catalog verification data. attributes contains product-level WooCommerce attributes; variants[].attributes contains variation-level selections. Final shipping, coupon acceptance and payable total remain WooCommerce checkout authority.',
        ];
    }

    private static function published_product_count(): int {
        // Multilingual: wp_count_posts() bypasses the language context entirely and
        // counts every translation, inflating the total. On a DB-translating site we
        // count via a language-scoped WP_Query so the total reflects the canonical
        // (default-language) catalog only. No-op on monolingual sites (no 'lang' arg).
        $default_lang = self::default_language();
        if ( $default_lang !== null ) {
            $q = new WP_Query( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => false,
                'lang'           => $default_lang,
            ] );
            return (int) $q->found_posts;
        }
        $counts = wp_count_posts( 'product' );
        return (int) ( $counts->publish ?? 0 );
    }
}
