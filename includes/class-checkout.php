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
	private static int $pre_dispatch_depth = 0;

    public static function init(): void {
        add_action( 'rest_api_init',     [ __CLASS__, 'register_routes' ] );
		// Own checkout POSTs before WordPress parses JSON, so oversized or malformed
		// request bodies consume bounded work and are charged to the abuse guard.
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'pre_dispatch' ], 1, 3 );
		// Session IDs are bearer tokens. Never allow a reverse proxy or browser cache
		// to retain POST/GET/DELETE responses, including errors and idempotent replays.
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'prevent_session_response_caching' ], 20, 3 );
        add_action( 'template_redirect', [ __CLASS__, 'handle_session_redirect' ] );
        // Checkout attribution: classic checkout and Store API (Checkout Block) fire
        // DIFFERENT hooks with different signatures (verified against WooCommerce 10.8.1
        // core: includes/class-wc-checkout.php vs src/StoreApi/Routes/V1/CheckoutOrder.php).
        // Both funnel into the same idempotent attribute_order().
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'attribute_order_classic' ], 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'attribute_order_blocks' ], 10, 1 );

        // A Bridge attribution marker is valid only while the cart loaded from that
        // session remains untouched. These hooks cover the mutations performed by both
        // classic cart/checkout and the Store API used by Cart/Checkout Blocks.
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'invalidate_attribution_marker' ], 10, 0 );
        add_action( 'woocommerce_after_cart_item_quantity_update', [ __CLASS__, 'invalidate_attribution_marker' ], 10, 0 );
        add_action( 'woocommerce_cart_item_removed', [ __CLASS__, 'invalidate_attribution_marker' ], 10, 0 );
        add_action( 'woocommerce_cart_item_restored', [ __CLASS__, 'invalidate_attribution_marker' ], 10, 0 );
    }

	public static function prevent_session_response_caching( $result, $server, $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}
		$route = (string) $request->get_route();
		$base  = '/' . KALICART_BRIDGE_API_NS . '/checkout/session';
		if ( $route !== $base && 0 !== strpos( $route, $base . '/' ) ) {
			return $result;
		}
		if ( ! ( $result instanceof WP_REST_Response ) ) {
			return $result;
		}
		$result->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$result->header( 'Pragma', 'no-cache' );
		$result->header( 'Expires', '0' );
		return $result;
	}

	public static function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}
		if ( 'POST' !== $request->get_method() || '/' . KALICART_BRIDGE_API_NS . '/checkout/session' !== $request->get_route() ) {
			return $result;
		}

		$rl = self::check_rate_limit();
		if ( true !== $rl ) {
			return $rl;
		}

		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'content-type' ), 2 )[0] ) );
		if ( 'application/json' !== $content_type ) {
			return self::error( 'Content-Type must be application/json.', 415 );
		}

		$max_bytes      = self::max_body_bytes();
		$content_length = trim( (string) $request->get_header( 'content-length' ) );
		if ( '' !== $content_length && ctype_digit( $content_length ) && (int) $content_length > $max_bytes ) {
			return self::error( 'Request body exceeds the checkout size limit.', 413 );
		}
		if ( strlen( (string) $request->get_body() ) > $max_bytes ) {
			return self::error( 'Request body exceeds the checkout size limit.', 413 );
		}

		self::$pre_dispatch_depth++;
		try {
			return self::create_session( $request );
		} finally {
			self::$pre_dispatch_depth = max( 0, self::$pre_dispatch_depth - 1 );
		}
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
		if ( ! is_array( $body ) ) {
			return self::error( 'Request body must be a JSON object.', 400 );
		}

        // Rate limit (mitigation, not absolute protection - WooCommerce's own Store API
        // rate limiter states the same). Two layers: per-client and a short global cap,
        // both proxy-aware (the X-Forwarded-For chain is used only behind explicitly trusted
        // proxies and is walked from the nearest hop outward). Uses transients: "good enough"
        // throttling,
        // NOT the strict atomicity used below for the session claim, where correctness of
        // the funnel/attribution actually depends on it.
		if ( 0 === self::$pre_dispatch_depth ) {
			$rl = self::check_rate_limit();
			if ( true !== $rl ) {
				return $rl; // WP_REST_Response 429 with Retry-After.
			}
		}

        // Idempotency-Key: an agent that retries the same request (network failure or
        // double submit) must receive the same session, not a new one. Same key + same
        // payload replays the original response; same key + different payload is a 409.
        // This hardens an existing path; it is not a new surface.
        $idem_key   = trim( (string) $req->get_header( 'Idempotency-Key' ) );
        $idem_store = '';
		$idem_slot  = '';
		$idem_legacy_store = '';
        $idem_hash  = '';
		$idem_owner = '';
        if ( '' !== $idem_key ) {
            if ( strlen( $idem_key ) > 255 ) {
                return self::error( 'Idempotency-Key must be at most 255 characters.', 400 );
            }
            $idem_hash  = hash( 'sha256', (string) wp_json_encode( self::idem_canonicalize( is_array( $body ) ? $body : array() ) ) );
			$idem_slot  = hash( 'sha256', $idem_key );
			$idem_store = 'kalicart_checkout_idem_v2_' . get_current_blog_id() . '_' . substr( $idem_slot, 0, 2 );
			$idem_legacy_store = 'kalicart_checkout_idem_' . get_current_blog_id() . '_' . md5( $idem_key );
			$prev = self::read_idempotency_record( $idem_store, $idem_slot, $idem_legacy_store );
			$previous = self::idempotency_previous_response( $prev, $idem_hash );
			if ( $previous instanceof WP_REST_Response ) {
				return $previous;
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
		$item_cost = (int) ceil( count( $raw_items ) / 5 );
		if ( $item_cost > 1 ) {
			$work_limit = self::check_rate_limit( $item_cost - 1 );
			if ( true !== $work_limit ) {
				return $work_limit;
			}
		}

        // Valida e normalizza ogni item
        $items                = [];
        $line_total           = 0.0;
        $requested_quantities = [];

        foreach ( $raw_items as $idx => $raw ) {
            if ( ! is_array( $raw ) ) {
                return self::error( "items[$idx] must be an object.", 400 );
            }

			$product_id_raw   = $raw['product_id'] ?? null;
			$quantity_raw     = $raw['quantity'] ?? 1;
			$variation_id_raw = $raw['variation_id'] ?? 0;
			if ( ! is_int( $product_id_raw ) || $product_id_raw < 1 ) {
				return self::error( "items[$idx]: product_id must be a positive integer.", 400 );
			}
			$hard_quantity_limit = min( 100000, max( 1, (int) apply_filters( 'kalicart_bridge_checkout_quantity_hard_limit', 999 ) ) );
			if ( ! is_int( $quantity_raw ) || $quantity_raw < 1 || $quantity_raw > $hard_quantity_limit ) {
				return self::error( "items[$idx]: quantity must be a positive integer.", 400 );
			}
			if ( ! is_int( $variation_id_raw ) || $variation_id_raw < 0 ) {
				return self::error( "items[$idx]: variation_id must be a non-negative integer.", 400 );
			}
			$product_id   = $product_id_raw;
			$quantity     = $quantity_raw;
			$variation_id = $variation_id_raw;

            $requested_product_id = $product_id;
            $product              = wc_get_product( $product_id );
            if ( ! $product || $product->get_status() !== 'publish' ) {
                return self::error( "items[$idx]: product $product_id not found.", 404 );
            }

            // WooCommerce also accepts a variation ID directly as product_id and
            // normalizes it to parent_id + variation_id when adding to the cart.
            // Normalize it here too so validation, fingerprints and order attribution
            // all describe the same purchasable line.
            if ( $product->is_type( 'variation' ) ) {
                if ( $variation_id && $variation_id !== $requested_product_id ) {
                    return self::error( "items[$idx]: variation $variation_id is not valid for product $requested_product_id.", 422 );
                }
                $price_product = $product;
                $variation_id  = $requested_product_id;
                $product_id    = (int) $price_product->get_parent_id();
                $product       = wc_get_product( $product_id );
                if ( ! $product || $product->get_status() !== 'publish' ) {
                    return self::error( "items[$idx]: parent product $product_id not found.", 404 );
                }
            } else {
                $price_product = $variation_id ? wc_get_product( $variation_id ) : $product;
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

            if ( ! $price_product ) {
                return self::error( "items[$idx]: variation $variation_id not found.", 404 );
            }
            if ( $variation_id && ( ! $price_product->is_type( 'variation' ) || (int) $price_product->get_parent_id() !== $product_id ) ) {
                return self::error( "items[$idx]: variation $variation_id is not valid for product $product_id.", 422 );
            }
            if ( ! $price_product->is_in_stock() ) {
                return self::error( "items[$idx]: product $product_id is out of stock.", 409 );
            }

            // Validate the effective purchasable object after normalizing both accepted
            // request formats. For variable products the stock/sold-individually ceiling
            // belongs to the selected variation, not to its parent product. Duplicate rows
            // for the same cart item are accumulated so they cannot bypass the ceiling.
            $quantity_key = $product_id . ':' . $variation_id;
            $requested_quantities[ $quantity_key ] = (int) ( $requested_quantities[ $quantity_key ] ?? 0 ) + $quantity;
            $requested_quantity = $requested_quantities[ $quantity_key ];
			if ( $requested_quantity > $hard_quantity_limit ) {
				return self::error( sprintf( 'Quantity %d for product %d exceeds the checkout safety limit (%d).', $requested_quantity, $product_id, $hard_quantity_limit ), 400 );
			}
            $max_quantity       = $price_product->get_max_purchase_quantity();
            if ( $max_quantity >= 0 && $requested_quantity > $max_quantity ) {
                return self::error( sprintf( 'Quantity %d for product %d exceeds the maximum purchasable quantity (%d).', $requested_quantity, $product_id, $max_quantity ), 400 );
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

		// Reserve this idempotency key atomically after validation and re-check it
		// inside the fixed bucket's DB mutex. Concurrent identical requests can no longer
		// both pass the initial transient miss and create different sessions.
		if ( '' !== $idem_key ) {
			$idem_owner = wp_generate_uuid4();
			$reservation = KaliCart_Bridge_Rate_Guard::synchronized(
				'idem_bucket_' . substr( $idem_slot, 0, 2 ),
				static function() use ( $idem_store, $idem_slot, $idem_hash, $idem_owner, $idem_legacy_store ): array {
					return self::reserve_idempotency_record( $idem_store, $idem_slot, $idem_hash, $idem_owner, $idem_legacy_store );
				}
			);
			if ( ! $reservation['acquired'] ) {
				return self::error( 'Idempotency coordination is temporarily unavailable.', 503 );
			}
			$outcome = is_array( $reservation['value'] ) ? $reservation['value'] : [ 'kind' => 'storage_error' ];
			if ( 'conflict' === $outcome['kind'] ) {
				return self::error( 'Idempotency-Key already used with a different request payload.', 409 );
			}
			if ( 'replay' === $outcome['kind'] ) {
				return new WP_REST_Response( $outcome['response'], 201 );
			}
			if ( 'processing' === $outcome['kind'] ) {
				return self::idempotency_processing_response();
			}
			if ( 'unavailable' === $outcome['kind'] ) {
				return self::idempotency_unavailable_response();
			}
			if ( 'reserved' !== $outcome['kind'] ) {
				return self::error( 'Idempotency storage is temporarily unavailable.', 503 );
			}
		}

		// Charge the long storage budget only after input validation and after an
		// idempotency replay/conflict has been ruled out. Only genuinely new sessions
		// can consume the bounded 30-minute storage allowance.
		$storage_budget = self::check_storage_budget();
		if ( true !== $storage_budget ) {
			self::release_idempotency_reservation( $idem_store, $idem_slot, $idem_owner );
			return $storage_budget;
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

		$session_store = 'kalicart_session_' . $session_id;
		if ( ! set_transient( $session_store, $session_data, self::SESSION_TTL ) ) {
			self::release_idempotency_reservation( $idem_store, $idem_slot, $idem_owner );
			return self::error( 'Checkout session storage is temporarily unavailable.', 503 );
		}

        $response = array_merge( [ 'success' => true ], $session_data );
        if ( '' !== $idem_key ) {
			if ( ! self::complete_idempotency_record( $idem_store, $idem_slot, $idem_hash, $idem_owner, $session_id, $response ) ) {
				delete_transient( $session_store );
				self::release_idempotency_reservation( $idem_store, $idem_slot, $idem_owner );
				return self::error( 'Idempotency storage is temporarily unavailable.', 503 );
			}
        }
        // Funnel: a genuinely NEW session only. Idempotent replays (returned above, before
        // this line) and 409 conflicts never reach here, so they are never double-counted.
        self::bump_funnel( 'sessions_created' );
        return new WP_REST_Response( $response, 201 );
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    public static function get_session( WP_REST_Request $req ): WP_REST_Response {
		$limited = self::check_access_rate_limit();
		if ( true !== $limited ) {
			return $limited;
		}
        $id   = sanitize_text_field( $req->get_param( 'id' ) );
		$data = self::read_checkout_session( $id );
        if ( ! $data ) return self::error( 'Session not found or expired.', 404 );
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    // ── CANCEL ────────────────────────────────────────────────────────────────

    public static function cancel_session( WP_REST_Request $req ): WP_REST_Response {
        $id = sanitize_text_field( $req->get_param( 'id' ) );
		if ( ! self::read_checkout_session( $id ) ) {
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
	public static function cancel_session_permission( WP_REST_Request $req ) {
		$limited = self::check_access_rate_limit();
		if ( true !== $limited ) {
			return new WP_Error( 'kalicart_checkout_rate_limited', 'Too many checkout session requests.', [ 'status' => 429 ] );
		}
        $id = (string) $req->get_param( 'id' );
        if ( ! self::is_valid_session_token( $id ) ) {
            return false;
        }
		return (bool) self::read_checkout_session( $id );
    }

    public static function handle_session_redirect(): void {
        $session_id = sanitize_text_field( wp_unslash( $_GET['kalicart_session'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $dest       = sanitize_text_field( wp_unslash( $_GET['kalicart_dest'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! self::is_valid_session_token( $session_id ) || ! in_array( $dest, [ 'cart', 'checkout' ], true ) ) return;
		$limited = self::check_access_rate_limit();
		if ( true !== $limited ) {
			wp_die(
				esc_html__( 'Too many checkout link requests. Please retry shortly.', 'kalicart-bridge' ),
				esc_html__( 'Too many requests', 'kalicart-bridge' ),
				[ 'response' => 429 ]
			);
		}

        // Soft check: has this session ALREADY produced an attributed order (claimed by
        // attribute_order(), see below)? If so, do NOT populate the cart or reveal anything
        // about the original order - this is a bearer-token link, and resuming/redirecting
        // to the first order's checkout would leak it to whoever holds this URL next. Show
        // a generic message and stop. The authoritative, race-safe check is the atomic claim
        // at order-processed time; this is a fast, honest early exit for the common case.
        if ( self::session_is_claimed( $session_id ) ) {
            wp_die(
                esc_html__( 'This checkout link has already been used and cannot be reused.', 'kalicart-bridge' ),
                esc_html__( 'Link already used', 'kalicart-bridge' ),
                [ 'response' => 410 ]
            );
        }

		$data = self::read_checkout_session( $session_id );
        if ( ! $data ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Funnel guard: was this session ALREADY at cart_loaded before this visit? A buyer
        // can click the cart/checkout link more than once in the same session; count the
        // transition once, not once per click.
        $was_loaded = ( ( $data['status'] ?? '' ) === 'cart_loaded' );

        $expected_fingerprint = self::session_items_fingerprint( $data['items'] ?? null );
        if ( '' === $expected_fingerprint || ! function_exists( 'WC' ) || ! WC()->cart ) {
            self::clear_attribution_marker();
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Clear any marker left by a previous Bridge cart before mutating this one. The
        // add/remove hooks below therefore cannot accidentally preserve stale attribution
        // while this handler performs its own controlled cart replacement.
        self::clear_attribution_marker();

        // Svuota il carrello corrente e aggiungi tutti gli item della sessione
        WC()->cart->empty_cart();

        $fully_loaded = true;
        foreach ( $data['items'] as $item ) {
            $cart_item_key = WC()->cart->add_to_cart(
                $item['product_id'],
                $item['quantity'],
                $item['variation_id'] ?? 0
            );
            if ( false === $cart_item_key ) {
                $fully_loaded = false;
                break;
            }
        }

        // add_to_cart() can be rejected by live stock/purchasability filters after the REST
        // session was created. Verify the resulting cart as a whole: partial or altered
        // loads must never create an attribution marker or send a buyer to checkout.
        $loaded_fingerprint = $fully_loaded ? self::current_cart_fingerprint() : '';
        $fully_loaded       = $fully_loaded
            && '' !== $loaded_fingerprint
            && hash_equals( $expected_fingerprint, $loaded_fingerprint );

        if ( ! $fully_loaded ) {
            WC()->cart->empty_cart();
            $data['status'] = 'pending';
            unset( $data['cart_fingerprint'], $data['load_token'], $data['attribution_expires_at'] );
            // This write refreshes the transient TTL, so keep its public expiry metadata
            // consistent instead of returning the timestamp from an earlier load.
            $data['expires_at'] = gmdate( 'c', time() + self::SESSION_TTL );
            set_transient( 'kalicart_session_' . $session_id, $data, self::SESSION_TTL );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Bind this successful load to both the server-side Bridge session and the current
        // WooCommerce session. A fresh random token prevents an older browser load of the
        // same link from inheriting the newest attribution state.
        $load_token                     = bin2hex( random_bytes( 16 ) );
        $attribution_expires_at         = time() + self::SESSION_TTL;
        $data['status']                 = 'cart_loaded';
        $data['cart_fingerprint']       = $expected_fingerprint;
        $data['load_token']             = $load_token;
        $data['attribution_expires_at'] = $attribution_expires_at;
        $data['expires_at']             = gmdate( 'c', $attribution_expires_at );
        $session_stored                 = set_transient( 'kalicart_session_' . $session_id, $data, self::SESSION_TTL );
        if ( ! $session_stored ) {
            // Storage uncertainty must not turn into attribution uncertainty. The cart can
            // still be reviewed, but without a durable session record it cannot be linked.
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }
        if ( ! $was_loaded ) {
            self::bump_funnel( 'carts_loaded' );
        }

        // Structured, short-lived marker. The order hook will independently re-read the
        // Bridge transient and compare the actual order lines before claiming attribution.
        if ( WC()->session ) {
            WC()->session->set( 'kalicart_bridge_session_id', [
                'session_id'       => $session_id,
                'cart_fingerprint' => $expected_fingerprint,
                'load_token'       => $load_token,
                'expires_at'       => $attribution_expires_at,
            ] );
        }

        $redirect = $dest === 'checkout' ? wc_get_checkout_url() : wc_get_cart_url();
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Any buyer-initiated cart mutation breaks the exact association with the Bridge
     * session. The marker is deliberately not restored even if the buyer later rebuilds
     * an identical cart manually; only opening the Bridge link again can create a new one.
     */
    public static function invalidate_attribution_marker(): void {
        self::clear_attribution_marker();
    }

    private static function clear_attribution_marker(): void {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( 'kalicart_bridge_session_id' );
        }
    }

    /**
     * Add one product/variation quantity to a canonical map. Bridge sessions accept only
     * integer quantities, so fractional or malformed quantities fail closed.
     */
    private static function add_to_quantity_map( array &$map, $product_id, $variation_id, $quantity ): bool {
        $product_id   = absint( $product_id );
        $variation_id = absint( $variation_id );
        if ( ! is_numeric( $quantity ) ) {
            return false;
        }
        $numeric_quantity = (float) $quantity;
        if ( $product_id < 1 || $numeric_quantity < 1 || floor( $numeric_quantity ) !== $numeric_quantity ) {
            return false;
        }
        $key         = $product_id . ':' . $variation_id;
        $map[ $key ] = (int) ( $map[ $key ] ?? 0 ) + (int) $numeric_quantity;
        return true;
    }

    private static function quantity_map_fingerprint( array $map ): string {
        if ( empty( $map ) ) {
            return '';
        }
        ksort( $map, SORT_STRING );
        return hash( 'sha256', (string) wp_json_encode( $map ) );
    }

    private static function session_items_fingerprint( $items ): string {
        if ( ! is_array( $items ) || empty( $items ) ) {
            return '';
        }
        $map = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! self::add_to_quantity_map(
                $map,
                $item['product_id'] ?? 0,
                $item['variation_id'] ?? 0,
                $item['quantity'] ?? 0
            ) ) {
                return '';
            }
        }
        return self::quantity_map_fingerprint( $map );
    }

    private static function current_cart_fingerprint(): string {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return '';
        }
        $map = [];
        foreach ( (array) WC()->cart->get_cart() as $item ) {
            if ( ! is_array( $item ) || ! self::add_to_quantity_map(
                $map,
                $item['product_id'] ?? 0,
                $item['variation_id'] ?? 0,
                $item['quantity'] ?? 0
            ) ) {
                return '';
            }
        }
        return self::quantity_map_fingerprint( $map );
    }

    private static function order_items_fingerprint( WC_Order $order ): string {
        $map = [];
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! is_object( $item )
                || ! is_callable( [ $item, 'get_product_id' ] )
                || ! is_callable( [ $item, 'get_variation_id' ] )
                || ! is_callable( [ $item, 'get_quantity' ] )
                || ! self::add_to_quantity_map(
                    $map,
                    $item->get_product_id(),
                    $item->get_variation_id(),
                    $item->get_quantity()
                )
            ) {
                return '';
            }
        }
        return self::quantity_map_fingerprint( $map );
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

    /**
     * Read through the same uncached storage used by the atomic INSERT IGNORE claim.
     * Mixing that write with get_option() would let the notoptions cache hide a newly
     * inserted claim, especially when a persistent object cache is active.
     */
    private static function session_is_claimed( string $session_id ): bool {
        global $wpdb;
        $claim_key = 'kalicart_session_claimed_' . $session_id;
        $claimed = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $claim_key
        ) );
        // Fail closed: a storage error must never repopulate a potentially used link.
        return null !== $claimed || '' !== $wpdb->last_error;
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

		$idem_like = $wpdb->esc_like( 'kalicart_checkout_idem_' ) . '%';
		$idem_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$idem_like
		) );
		foreach ( (array) $idem_rows as $row ) {
			$record = maybe_unserialize( (string) $row->option_value );
			if ( 0 === strpos( (string) $row->option_name, 'kalicart_checkout_idem_v2_' ) ) {
				continue; // Fixed/bounded buckets prune under their own mutex on access.
			}
			$expires = is_array( $record ) ? (int) ( $record['expires'] ?? 0 ) : 0;
			if ( $expires < time() ) {
				$wpdb->delete( $wpdb->options, [ 'option_id' => $row->option_id ] );
			}
		}

		$funnel_prefix = 'kalicart_bridge_agent_funnel_v2_';
		$funnel_like   = $wpdb->esc_like( $funnel_prefix ) . '%';
		$funnel_rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$funnel_like
		) );
		$oldest_day = gmdate( 'Ymd', time() - ( 30 * DAY_IN_SECONDS ) );
		$today      = gmdate( 'Ymd' );
		foreach ( (array) $funnel_rows as $row ) {
			if ( ! preg_match( '/^' . preg_quote( $funnel_prefix, '/' ) . '(\d{8})_/', (string) $row->option_name, $match )
				|| $match[1] < $oldest_day || $match[1] > $today ) {
				$wpdb->delete( $wpdb->options, [ 'option_id' => $row->option_id ] );
			}
		}
    }

    // ── RATE LIMITING ────────────────────────────────────────────────────────
	/** Read bearer session data without populating option-miss caches for random tokens. */
	private static function read_checkout_session( string $session_id ) {
		if ( ! self::is_valid_session_token( $session_id ) ) {
			return null;
		}
		$key = 'kalicart_session_' . $session_id;
		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $key, 'transient' );
			return is_array( $value ) ? $value : null;
		}

		global $wpdb;
		$value_name   = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
			$value_name,
			$timeout_name
		) );
		$found = [];
		foreach ( (array) $rows as $row ) {
			$found[ (string) $row->option_name ] = (string) $row->option_value;
		}
		if ( ! isset( $found[ $value_name ], $found[ $timeout_name ] ) || (int) $found[ $timeout_name ] < time() ) {
			return null;
		}
		$value = maybe_unserialize( $found[ $value_name ] );
		return is_array( $value ) ? $value : null;
	}

	private static function max_body_bytes(): int {
		return min( MB_IN_BYTES, max( 1024, (int) apply_filters( 'kalicart_bridge_checkout_max_body_bytes', 64 * 1024 ) ) );
	}

	private static function check_rate_limit( int $cost = 1 ) {
		$result = KaliCart_Bridge_Rate_Guard::check( 'checkout', max( 1, $cost ), [
            'client_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_rate_limit_per_client', 10 ) ),
            'client_window' => max( 1, (int) apply_filters( 'kalicart_bridge_rate_limit_per_client_secs', 60 ) ),
            'global_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_rate_limit_global', 60 ) ),
            'global_window' => max( 1, (int) apply_filters( 'kalicart_bridge_rate_limit_global_secs', 10 ) ),
        ] );
        if ( ! $result['allowed'] ) {
            return self::rate_limited_response( $result['retry_after'] );
        }
        return true;
    }

	private static function check_storage_budget() {
		$result = KaliCart_Bridge_Rate_Guard::check( 'checkout_long', 1, [
			'client_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_rate_limit_per_client_hour', 30 ) ),
			'client_window' => HOUR_IN_SECONDS,
			'global_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_rate_limit_global_hour', 600 ) ),
			'global_window' => HOUR_IN_SECONDS,
		] );
		return $result['allowed'] ? true : self::rate_limited_response( $result['retry_after'] );
	}

	private static function check_access_rate_limit() {
		$result = KaliCart_Bridge_Rate_Guard::check( 'checkout_access', 1, [
			'client_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_checkout_access_rate_limit_per_client', 60 ) ),
			'client_window' => 60,
			'global_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_checkout_access_rate_limit_global', 300 ) ),
			'global_window' => 60,
		] );
		return $result['allowed'] ? true : self::rate_limited_response( $result['retry_after'] );
	}

    private static function rate_limited_response( int $retry_after ): WP_REST_Response {
        $resp = new WP_REST_Response(
            [ 'success' => false, 'error' => 'rate_limited', 'message' => 'Too many checkout session requests. Please slow down.' ],
            429
        );
        $resp->header( 'Retry-After', (string) max( 1, $retry_after ) );
        return $resp;
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
        $marker = WC()->session->get( 'kalicart_bridge_session_id' );
        if ( ! $marker ) {
            return; // No marker: this order did not originate from a Bridge checkout session.
        }

        // Legacy scalar markers and malformed data are intentionally not trusted. A valid
        // marker must be bound to the still-live Bridge transient, its most recent cart
        // load, and the exact product/variation quantities now present in the order.
        if ( ! is_array( $marker ) ) {
            self::clear_attribution_marker();
            return;
        }
        $session_id       = (string) ( $marker['session_id'] ?? '' );
        $cart_fingerprint = (string) ( $marker['cart_fingerprint'] ?? '' );
        $load_token       = (string) ( $marker['load_token'] ?? '' );
        $expires_at       = (int) ( $marker['expires_at'] ?? 0 );
        if ( ! self::is_valid_session_token( $session_id )
            || ! preg_match( '/^[a-f0-9]{64}$/', $cart_fingerprint )
            || ! self::is_valid_session_token( $load_token )
            || $expires_at < time()
        ) {
            self::clear_attribution_marker();
            return;
        }

		$session = self::read_checkout_session( $session_id );
        if ( ! is_array( $session )
            || 'cart_loaded' !== ( $session['status'] ?? '' )
            || $expires_at !== (int) ( $session['attribution_expires_at'] ?? 0 )
            || ! isset( $session['cart_fingerprint'], $session['load_token'] )
            || ! hash_equals( $cart_fingerprint, (string) $session['cart_fingerprint'] )
            || ! hash_equals( $load_token, (string) $session['load_token'] )
            || ! hash_equals( $cart_fingerprint, self::session_items_fingerprint( $session['items'] ?? null ) )
            || ! hash_equals( $cart_fingerprint, self::order_items_fingerprint( $order ) )
        ) {
            self::clear_attribution_marker();
            return;
        }

        // Consume the browser marker before touching the durable claim. Storage failure or
        // a lost claim race must fail closed instead of leaking attribution to a later order.
        self::clear_attribution_marker();

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
        self::bump_funnel( 'orders_linked' );
    }

    // ── FUNNEL METRICS (local, no cloud) ────────────────────────────────────
    //
    // One numeric option per day+metric. MySQL increments it atomically, so simultaneous
    // sessions/orders cannot overwrite each other. The report also reads the legacy
    // aggregate option to preserve counts written before this hardening.

    private static function bump_funnel( string $metric ): void {
		if ( ! in_array( $metric, [ 'sessions_created', 'carts_loaded', 'orders_linked' ], true ) ) {
			return;
		}
		global $wpdb;
		$name = 'kalicart_bridge_agent_funnel_v2_' . gmdate( 'Ymd' ) . '_' . $metric;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
			 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1, autoload = 'no'",
			$name
		) );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
    }

    /**
     * Funnel totals for the panel: 30 calendar days including today, plus the net paid
     * value of orders linked in that same window. HPOS-safe: uses paginated
     * wc_get_orders()/WC_Order CRUD, never raw postmeta queries.
     */
    public static function get_funnel_report(): array {
        $stats = get_option( 'kalicart_bridge_agent_funnel', [] );
        if ( ! is_array( $stats ) ) {
            $stats = [];
        }
        $now    = time();
        $cutoff = gmdate( 'Y-m-d', $now - ( 29 * DAY_IN_SECONDS ) );
        $totals = [ 'sessions_created' => 0, 'carts_loaded' => 0, 'orders_linked' => 0 ];
        foreach ( $stats as $day => $bucket ) {
            if ( $day < $cutoff || ! is_array( $bucket ) ) {
                continue;
            }
            foreach ( $totals as $k => $v ) {
                $totals[ $k ] += (int) ( $bucket[ $k ] ?? 0 );
            }
        }

		global $wpdb;
		$prefix = 'kalicart_bridge_agent_funnel_v2_';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		) );
		$today = gmdate( 'Y-m-d', $now );
		foreach ( (array) $rows as $row ) {
			if ( ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d{8})_(sessions_created|carts_loaded|orders_linked)$/', (string) $row->option_name, $match ) ) {
				continue;
			}
			$day = substr( $match[1], 0, 4 ) . '-' . substr( $match[1], 4, 2 ) . '-' . substr( $match[1], 6, 2 );
			if ( $day >= $cutoff && $day <= $today ) {
				$totals[ $match[2] ] += max( 0, (int) $row->option_value );
			}
		}

        $window_start = strtotime( $cutoff . ' 00:00:00 UTC' );
        $query_args   = [
            'meta_key'     => '_kalicart_bridge_source',
            'meta_value'   => 'agent_checkout',
            'date_created' => $window_start . '...' . $now,
            'return'       => 'objects',
            'limit'        => 100,
            'paginate'     => true,
            'orderby'      => 'ID',
            'order'        => 'ASC',
        ];
        // A paid-looking status is not proof of payment: COD/BACS/cheque orders can reach
        // processing with date_paid still NULL. Conversely, a refunded order can retain a
        // genuine payment date and must contribute its post-refund net value (often zero).
        $net        = 0.0;
        $paid_count = 0;
        $page       = 1;
        do {
            $query_args['page'] = $page;
            $result             = wc_get_orders( $query_args );
            $orders             = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : (array) $result;
            $max_pages          = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

            foreach ( $orders as $order ) {
                if ( ! ( $order instanceof WC_Order ) || ! $order->get_date_paid() ) {
                    continue;
                }
                $paid_count++;
                $net += (float) $order->get_total() - (float) $order->get_total_refunded();
            }
            $page++;
        } while ( $page <= $max_pages );
        $totals['orders_paid_count']    = $paid_count;
        $totals['net_paid_value']       = $net;
        $totals['currency']             = get_woocommerce_currency();
        return $totals;
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

	/** Fresh read of one of 256 fixed, bounded idempotency buckets. */
	private static function read_idempotency_bucket( string $store ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$store
		) );
		if ( '' !== (string) $wpdb->last_error ) {
			return [ 'ok' => false, 'exists' => false, 'raw' => null, 'state' => null ];
		}
		if ( null === $row ) {
			return [ 'ok' => true, 'exists' => false, 'raw' => null, 'state' => [ 'version' => 1, 'entries' => [] ] ];
		}
		$raw   = (string) $row->option_value;
		$state = maybe_unserialize( $raw );
		if ( ! is_array( $state ) || 1 !== (int) ( $state['version'] ?? 0 ) || ! is_array( $state['entries'] ?? null ) ) {
			return [ 'ok' => false, 'exists' => true, 'raw' => $raw, 'state' => null ];
		}
		$max_entries = self::idempotency_bucket_limit();
		if ( count( $state['entries'] ) > $max_entries ) {
			return [ 'ok' => false, 'exists' => true, 'raw' => $raw, 'state' => null ];
		}
		foreach ( $state['entries'] as $slot => $record ) {
			if ( ! is_string( $slot ) || ! preg_match( '/^[a-f0-9]{64}$/', $slot )
				|| ! is_array( $record ) || ! isset( $record['payload_hash'], $record['status'], $record['expires'] ) ) {
				return [ 'ok' => false, 'exists' => true, 'raw' => $raw, 'state' => null ];
			}
		}
		return [ 'ok' => true, 'exists' => true, 'raw' => $raw, 'state' => $state ];
	}

	private static function idempotency_bucket_limit(): int {
		return min( 256, max( 16, (int) apply_filters( 'kalicart_bridge_checkout_idempotency_bucket_limit', 64 ) ) );
	}

	private static function prune_idempotency_entries( array $entries ): array {
		$now = time();
		foreach ( $entries as $slot => $record ) {
			if ( ! is_array( $record ) || (int) ( $record['expires'] ?? 0 ) < $now ) {
				unset( $entries[ $slot ] );
			}
		}
		return $entries;
	}

	private static function write_idempotency_bucket( string $store, array $row, array $state ): bool {
		global $wpdb;
		$encoded = maybe_serialize( $state );
		if ( $row['exists'] ) {
			$written = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
				$encoded,
				$store,
				(string) $row['raw']
			) );
		} else {
			$written = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$store,
				$encoded
			) );
		}
		if ( 1 === (int) $written ) {
			wp_cache_delete( $store, 'options' );
			if ( ! $row['exists'] ) {
				wp_cache_delete( 'notoptions', 'options' );
			}
			return true;
		}
		return false;
	}

	private static function response_from_idempotency_record( array $record ): ?array {
		if ( 'complete' !== ( $record['status'] ?? '' ) || empty( $record['session_id'] ) ) {
			return null;
		}
		$session = self::read_checkout_session( (string) $record['session_id'] );
		if ( ! is_array( $session ) ) {
			return null;
		}
		$public_keys = [ 'session_id', 'items', 'item_count', 'subtotal', 'currency', 'cart_url', 'checkout_url', 'note', 'created_at' ];
		$public      = array_intersect_key( $session, array_flip( $public_keys ) );
		$public['status']     = 'pending';
		$public['expires_at'] = (string) ( $record['public_expires_at'] ?? ( $session['expires_at'] ?? '' ) );
		return array_merge( [ 'success' => true ], $public );
	}

	private static function read_idempotency_record( string $store, string $slot, string $legacy_store ) {
		$row = self::read_idempotency_bucket( $store );
		if ( ! $row['ok'] ) {
			return [ '_storage_error' => true ];
		}
		$record = $row['state']['entries'][ $slot ] ?? null;
		if ( is_array( $record ) && (int) ( $record['expires'] ?? 0 ) >= time() ) {
			$response = self::response_from_idempotency_record( $record );
			if ( null !== $response ) {
				$record['response'] = $response;
				return $record;
			}
			if ( in_array( ( $record['status'] ?? '' ), [ 'processing', 'complete' ], true ) ) {
				return $record;
			}
			return null; // Completed record whose underlying session has expired/vanished.
		}
		// Compatibility with 1.0.116/early 1.0.118 transient records. New writes
		// never use this cache-sensitive path.
		$legacy = get_transient( $legacy_store );
		return is_array( $legacy ) ? $legacy : null;
	}

	private static function reserve_idempotency_record( string $store, string $slot, string $payload_hash, string $owner, string $legacy_store ): array {
		$row = self::read_idempotency_bucket( $store );
		if ( ! $row['ok'] ) {
			return [ 'kind' => 'storage_error' ];
		}
		$entries = self::prune_idempotency_entries( $row['state']['entries'] );
		$record  = $entries[ $slot ] ?? null;
		if ( is_array( $record ) ) {
			if ( ! hash_equals( (string) $record['payload_hash'], $payload_hash ) ) {
				return [ 'kind' => 'conflict' ];
			}
			$response = self::response_from_idempotency_record( $record );
			if ( null !== $response ) {
				return [ 'kind' => 'replay', 'response' => $response ];
			}
			if ( 'processing' === ( $record['status'] ?? '' ) ) {
				return [ 'kind' => 'processing' ];
			}
			if ( 'complete' === ( $record['status'] ?? '' ) ) {
				return [ 'kind' => 'unavailable' ]; // Tombstone: never duplicate within TTL.
			}
			unset( $entries[ $slot ] );
		}
		$legacy = get_transient( $legacy_store );
		if ( is_array( $legacy ) && isset( $legacy['payload_hash'] ) ) {
			if ( ! hash_equals( (string) $legacy['payload_hash'], $payload_hash ) ) {
				return [ 'kind' => 'conflict' ];
			}
			if ( isset( $legacy['response'] ) && is_array( $legacy['response'] ) ) {
				return [ 'kind' => 'replay', 'response' => $legacy['response'] ];
			}
		}
		if ( count( $entries ) >= self::idempotency_bucket_limit() ) {
			return [ 'kind' => 'capacity' ];
		}
		$entries[ $slot ] = [
			'payload_hash' => $payload_hash,
			'status'       => 'processing',
			'owner'        => $owner,
			'expires'      => time() + 60,
		];
		$state = [ 'version' => 1, 'entries' => $entries ];
		return [ 'kind' => self::write_idempotency_bucket( $store, $row, $state ) ? 'reserved' : 'storage_error' ];
	}

	private static function complete_idempotency_record( string $store, string $slot, string $payload_hash, string $owner, string $session_id, array $response ): bool {
		$result = KaliCart_Bridge_Rate_Guard::synchronized(
			'idem_bucket_' . substr( $slot, 0, 2 ),
			static function() use ( $store, $slot, $payload_hash, $owner, $session_id, $response ): bool {
				$row = self::read_idempotency_bucket( $store );
				if ( ! $row['ok'] ) {
					return false;
				}
				$entries = self::prune_idempotency_entries( $row['state']['entries'] );
				$record  = $entries[ $slot ] ?? null;
				if ( ! is_array( $record ) ) {
					return false;
				}
				if ( 'processing' !== ( $record['status'] ?? '' )
					|| ! isset( $record['owner'] )
					|| ! hash_equals( (string) $record['owner'], $owner )
					|| ! hash_equals( (string) $record['payload_hash'], $payload_hash ) ) {
					return false;
				}
				$entries[ $slot ] = [
					'payload_hash' => $payload_hash,
					'status'       => 'complete',
					'session_id'   => $session_id,
					'public_expires_at' => (string) ( $response['expires_at'] ?? '' ),
					'expires'      => time() + self::SESSION_TTL,
				];
				return self::write_idempotency_bucket( $store, $row, [ 'version' => 1, 'entries' => $entries ] );
			}
		);
		return $result['acquired'] && true === $result['value'];
	}

	private static function idempotency_previous_response( $previous, string $payload_hash ): ?WP_REST_Response {
		if ( is_array( $previous ) && ! empty( $previous['_storage_error'] ) ) {
			return self::error( 'Idempotency storage is temporarily unavailable.', 503 );
		}
		if ( ! is_array( $previous ) || ! isset( $previous['payload_hash'] ) ) {
			return null;
		}
		if ( ! hash_equals( (string) $previous['payload_hash'], $payload_hash ) ) {
			return self::error( 'Idempotency-Key already used with a different request payload.', 409 );
		}
		if ( isset( $previous['response'] ) && is_array( $previous['response'] ) ) {
			return new WP_REST_Response( $previous['response'], 201 );
		}
		if ( 'complete' === ( $previous['status'] ?? '' ) ) {
			return self::idempotency_unavailable_response();
		}
		return self::idempotency_processing_response();
	}

	private static function idempotency_unavailable_response(): WP_REST_Response {
		$response = self::error( 'The original idempotent session is unavailable; this key cannot be reused until it expires.', 409 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function idempotency_processing_response(): WP_REST_Response {
		$response = self::error( 'A request with this Idempotency-Key is still being processed. Retry shortly.', 409 );
		$response->header( 'Retry-After', '1' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function release_idempotency_reservation( string $store, string $slot, string $owner ): void {
		if ( '' === $store || '' === $slot || '' === $owner ) {
			return;
		}
		KaliCart_Bridge_Rate_Guard::synchronized(
			'idem_bucket_' . substr( $slot, 0, 2 ),
			static function() use ( $store, $slot, $owner ): void {
				$row = self::read_idempotency_bucket( $store );
				if ( ! $row['ok'] ) {
					return;
				}
				$entries = self::prune_idempotency_entries( $row['state']['entries'] );
				$record  = $entries[ $slot ] ?? null;
				if ( 'processing' === ( $record['status'] ?? '' )
					&& isset( $record['owner'] )
					&& hash_equals( (string) $record['owner'], $owner ) ) {
					unset( $entries[ $slot ] );
					self::write_idempotency_bucket( $store, $row, [ 'version' => 1, 'entries' => $entries ] );
				}
			}
		);
	}

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
