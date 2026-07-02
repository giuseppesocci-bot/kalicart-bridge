<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_MCP
 *
 * Model Context Protocol (MCP) server — JSON-RPC 2.0 over HTTP POST.
 *
 * A SECOND transport over the exact same read-only catalog logic already
 * exposed by KaliCart_Bridge_API. It adds NO business logic and NO new data:
 * every tool dispatches to an existing public_catalog REST callback and returns
 * its data. No external calls, no LLM, no cloud, no API key — local catalog only.
 *
 * Endpoint: POST /wp-json/kalicart/v1/mcp
 * Handled JSON-RPC methods: initialize, tools/list, tools/call, ping,
 *                           notifications/* (acknowledged, no body).
 *
 * Stateless: no Mcp-Session-Id is issued; every POST is self-contained.
 * The "brain" is always the connecting agent (e.g. Claude Desktop); this plugin
 * only exposes callable tools — exactly like any other MCP server an agent wires in.
 */
class KaliCart_Bridge_MCP {

	/**
	 * MCP protocol revision implemented as baseline. The server echoes the
	 * client's requested protocolVersion when present (the core methods used
	 * here are stable across revisions).
	 */
	const PROTOCOL_VERSION = '2025-06-18';

	/** Tools exposed. Each maps 1:1 to an existing public_catalog REST callback. */
	const TOOLS = array( 'search_products', 'get_product', 'list_products', 'list_categories', 'get_meta' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			KALICART_BRIDGE_API_NS,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'no_sse' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// RFC 9728 Protected Resource Metadata, at the path MCP clients actually
		// probe (<mcp-url>/.well-known/...). This server is keyless by design:
		// serving the metadata with an EMPTY authorization_servers list tells
		// compliant clients explicitly that no OAuth flow is required, instead
		// of leaving them to interpret a 404 (observed: real MCP clients probing
		// this path before connecting).
		register_rest_route(
			KALICART_BRIDGE_API_NS,
			'/mcp/.well-known/oauth-protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'oauth_protected_resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ── HTTP entry ──────────────────────────────────────────────────────────────

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = json_decode( $request->get_body(), true );
		}
		if ( ! is_array( $body ) ) {
			return self::respond( self::rpc_error( null, -32700, 'Parse error: body is not valid JSON' ) );
		}

		// JSON-RPC batch (numeric-indexed list). PHP 8.0-safe list check.
		if ( isset( $body[0] ) && is_array( $body[0] ) ) {
			$out = array();
			foreach ( $body as $msg ) {
				$r = self::dispatch( is_array( $msg ) ? $msg : array() );
				if ( null !== $r ) {
					$out[] = $r;
				}
			}
			return empty( $out )
				? new WP_REST_Response( null, 202 )
				: new WP_REST_Response( $out, 200 );
		}

		$r = self::dispatch( $body );
		if ( null === $r ) {
			// Notification — acknowledged with no body.
			return new WP_REST_Response( null, 202 );
		}
		return self::respond( $r );
	}

	private static function respond( array $rpc ): WP_REST_Response {
		$resp = new WP_REST_Response( $rpc, 200 );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	// ── /.well-known/oauth-protected-resource (RFC 9728, keyless) ────────────────

	public static function oauth_protected_resource_metadata(): WP_REST_Response {
		$resp = new WP_REST_Response(
			array(
				'resource'                 => rest_url( KALICART_BRIDGE_API_NS . '/mcp' ),
				'authorization_servers'    => array(),
				'bearer_methods_supported' => array(),
				'resource_name'            => 'KaliCart Bridge MCP — ' . get_bloginfo( 'name' ),
				'resource_documentation'   => rest_url( KALICART_BRIDGE_API_NS . '/discovery' ),
			),
			200
		);
		$resp->header( 'Cache-Control', 'public, max-age=3600' );
		return $resp;
	}

	// ── telemetry (chi usa il server MCP e con che risultati) ────────────────────

	/** clientInfo from initialize — the client's own declared identity. */
	private static function client_label( array $params ): string {
		$ci   = ( isset( $params['clientInfo'] ) && is_array( $params['clientInfo'] ) ) ? $params['clientInfo'] : array();
		$name = isset( $ci['name'] ) ? sanitize_text_field( (string) $ci['name'] ) : '';
		$ver  = isset( $ci['version'] ) ? sanitize_text_field( (string) $ci['version'] ) : '';
		if ( '' === $name ) {
			return '(undeclared)';
		}
		return substr( '' !== $ver ? $name . '/' . $ver : $name, 0, 60 );
	}

	private static function track( array $dims ): void {
		if ( class_exists( 'KaliCart_Bridge_Signals' ) && method_exists( 'KaliCart_Bridge_Signals', 'count_mcp_event' ) ) {
			KaliCart_Bridge_Signals::count_mcp_event( $dims );
		}
	}

	// ── JSON-RPC dispatch ─────────────────────────────────────────────────────────

	/**
	 * @return array|null JSON-RPC response, or null for notifications (no reply).
	 */
	private static function dispatch( array $msg ) {
		$is_notification = ! array_key_exists( 'id', $msg );
		$id              = $msg['id'] ?? null;

		if ( empty( $msg['method'] ) || ! is_string( $msg['method'] ) ) {
			return $is_notification ? null : self::rpc_error( $id, -32600, 'Invalid Request: missing method' );
		}

		$method = $msg['method'];
		$params = ( isset( $msg['params'] ) && is_array( $msg['params'] ) ) ? $msg['params'] : array();

		switch ( $method ) {
			case 'initialize':
				self::track( array( 'method' => 'initialize', 'client' => self::client_label( $params ) ) );
				$result = self::r_initialize( $params );
				break;

			case 'tools/list':
				self::track( array( 'method' => 'tools/list' ) );
				$result = array( 'tools' => self::tool_definitions() );
				break;

			case 'tools/call':
				// Owns its full envelope (tool errors are results, not protocol errors).
				return self::r_tools_call( $id, $params );

			case 'ping':
				self::track( array( 'method' => 'ping' ) );
				$result = (object) array();
				break;

			default:
				// Any notifications/* (initialized, cancelled, progress…) — acknowledge silently.
				if ( $is_notification || 0 === strpos( $method, 'notifications/' ) ) {
					self::track( array( 'method' => 'notification' ) );
					return null;
				}
				self::track( array( 'method' => 'unknown' ) );
				return self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}

		if ( $is_notification ) {
			return null;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function r_initialize( array $params ): array {
		$client_pv = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$name      = get_bloginfo( 'name' );

		return array(
			'protocolVersion' => '' !== $client_pv ? $client_pv : self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => (object) array(),
			),
			'serverInfo'      => array(
				'name'    => 'kalicart-bridge',
				'title'   => 'KaliCart Bridge — ' . $name,
				'version' => KALICART_BRIDGE_VERSION,
			),
			'instructions'    => implode(
				' ',
				array(
					'Read-only WooCommerce catalog for ' . $name . '.',
					'Call get_meta first to learn valid category slugs, accepted filter values and the price range.',
					'Use search_products with ONLY a bare product noun in "q"; put every attribute (category, gender, color, price) in its own argument — never inside "q".',
					'Prices are catalog prices in major currency units. Checkout stays on the merchant storefront; this server never takes payment.',
				)
			),
		);
	}

	// ── tools/call ────────────────────────────────────────────────────────────────

	private static function r_tools_call( $id, array $params ): array {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) ? $params['arguments'] : array();

		if ( ! in_array( $name, self::TOOLS, true ) ) {
			self::track( array( 'method' => 'tools/call', 'tool' => '(invalid)', 'outcome' => 'error' ) );
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}

		try {
			$data = self::run_tool( $name, $args );
		} catch ( \Throwable $e ) {
			self::track( array( 'method' => 'tools/call', 'tool' => $name, 'outcome' => 'error' ) );
			return self::tool_result( $id, array( 'error' => 'Tool execution failed: ' . $e->getMessage() ), true );
		}

		$is_error = ( isset( $data['success'] ) && false === $data['success'] );
		self::track( array( 'method' => 'tools/call', 'tool' => $name, 'outcome' => $is_error ? 'error' : 'ok' ) );
		return self::tool_result( $id, $data, $is_error );
	}

	private static function tool_result( $id, array $data, bool $is_error ): array {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => is_string( $json ) ? $json : '{}',
					),
				),
				'structuredContent' => $data,
				'isError'           => $is_error,
			),
		);
	}

	/**
	 * Dispatch a tool to the existing REST callback and return its data array.
	 * Zero logic duplication: the same KaliCart_Bridge_API methods that serve
	 * /wp-json/kalicart/v1/catalog/* are reused verbatim.
	 */
	private static function run_tool( string $name, array $args ): array {
		switch ( $name ) {
			case 'search_products':
				return self::via_api( 'catalog_search', $args );

			case 'list_products':
				return self::via_api( 'catalog_products', $args );

			case 'get_product':
				$req = new WP_REST_Request( 'GET' );
				$req->set_param( 'id', isset( $args['id'] ) ? absint( $args['id'] ) : 0 );
				$req->set_param( 'fields', 'verification' );
				return self::unwrap( KaliCart_Bridge_API::catalog_product( $req ) );

			case 'list_categories':
				return self::unwrap( KaliCart_Bridge_API::catalog_categories( new WP_REST_Request( 'GET' ) ) );

			case 'get_meta':
				return self::unwrap( KaliCart_Bridge_API::catalog_meta( new WP_REST_Request( 'GET' ) ) );
		}
		return array();
	}

	/** Build a WP_REST_Request from tool arguments and call a catalog callback. */
	private static function via_api( string $callback, array $args ): array {
		$req         = new WP_REST_Request( 'GET' );
		$passthrough = array( 'q', 'category', 'gender', 'color', 'per_page', 'page', 'orderby', 'order', 'in_stock', 'on_sale', 'min_price', 'max_price' );
		foreach ( $passthrough as $key ) {
			if ( array_key_exists( $key, $args ) && null !== $args[ $key ] ) {
				$req->set_param( $key, $args[ $key ] );
			}
		}
		if ( ! array_key_exists( 'per_page', $args ) ) {
			$req->set_param( 'per_page', 10 );
		}
		$req->set_param( 'fields', 'summary' );
		return self::unwrap( call_user_func( array( 'KaliCart_Bridge_API', $callback ), $req ) );
	}

	private static function unwrap( WP_REST_Response $resp ): array {
		$data = $resp->get_data();
		return is_array( $data ) ? $data : array( 'value' => $data );
	}

	// ── tools/list definitions ─────────────────────────────────────────────────────

	private static function tool_definitions(): array {
		$gender_enum = array( 'male', 'female', 'unisex', 'kids' );
		$color_enum  = array( 'red', 'blue', 'green', 'black', 'white', 'grey', 'brown', 'yellow', 'orange', 'pink', 'purple', 'multi' );
		$orderby     = array( 'date', 'price', 'title', 'popularity' );

		$filter_props = array(
			'category'  => array(
				'type'        => 'string',
				'description' => 'WooCommerce category slug (e.g. "scarpe-uomo"). Enumerate valid slugs via list_categories or get_meta.',
			),
			'gender'    => array(
				'type'        => 'string',
				'enum'        => $gender_enum,
				'description' => 'Gender facet. IT aliases uomo/donna are also accepted.',
			),
			'color'     => array(
				'type'        => 'string',
				'enum'        => $color_enum,
				'description' => 'Colour family. IT aliases (rosso, blu, nero…) are also accepted.',
			),
			'min_price' => array(
				'type'        => 'number',
				'description' => 'Minimum current price (merchant currency, major units).',
			),
			'max_price' => array(
				'type'        => 'number',
				'description' => 'Maximum current price (merchant currency, major units).',
			),
			'in_stock'  => array(
				'type'        => 'boolean',
				'description' => 'true returns in-stock products only.',
			),
			'on_sale'   => array(
				'type'        => 'boolean',
				'description' => 'true returns products with an active WooCommerce sale price (coupon-only savings excluded).',
			),
			'per_page'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 10,
				'description' => 'Results per page (1–100).',
			),
			'page'      => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => 'Page number.',
			),
			'orderby'   => array(
				'type'        => 'string',
				'enum'        => $orderby,
				'description' => 'Sort field.',
			),
			'order'     => array(
				'type'        => 'string',
				'enum'        => array( 'ASC', 'DESC' ),
				'description' => 'Sort direction.',
			),
		);

		$search_props = array_merge(
			array(
				'q' => array(
					'type'        => 'string',
					'description' => 'Bare product noun ONLY (the spine), e.g. "t-shirt", "scarpe". Never put brand, colour, gender or price in q — use the dedicated arguments. size is not a search filter.',
				),
			),
			$filter_props
		);

		return array(
			array(
				'name'        => 'search_products',
				'title'       => 'Search products',
				'description' => 'Search the catalog by a bare product noun plus structured filters. Returns compact summary records for candidate ranking; use get_product only for the final selected product.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) $search_props,
				),
			),
			array(
				'name'        => 'list_products',
				'title'       => 'List products',
				'description' => 'List compact summary records (paginated), optionally filtered. Same filters as search_products but without a free-text query; use get_product only after selection.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) $filter_props,
				),
			),
			array(
				'name'        => 'get_product',
				'title'       => 'Verify selected product',
				'description' => 'After ranking summaries, call once for the final selected product. Returns compact price, stock, variants, shipping and coupon evidence.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'WooCommerce product ID (from a search/list result).',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'list_categories',
				'title'       => 'List categories',
				'description' => 'Return the merchant-native WooCommerce category tree (slugs + names). Use a slug as the category argument of search_products/list_products.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
			),
			array(
				'name'        => 'get_meta',
				'title'       => 'Get catalog meta',
				'description' => 'Return accepted filter values (category slugs, genders, colours), the price range and the merchant shipping policy. Call this first to ground a search.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
			),
		);
	}

	// ── GET → 405 (no server-initiated SSE stream offered here) ──────────────────────

	public static function no_sse( WP_REST_Request $req ): WP_REST_Response {
		$resp = new WP_REST_Response(
			array( 'error' => 'GET not supported. This MCP endpoint accepts JSON-RPC 2.0 over HTTP POST only.' ),
			405
		);
		$resp->header( 'Allow', 'POST' );
		return $resp;
	}

	// ── helpers ─────────────────────────────────────────────────────────────────────

	private static function rpc_error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
