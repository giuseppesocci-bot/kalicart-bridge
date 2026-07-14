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

	/** MCP revision supported by this stateless tools-only implementation. */
	const PROTOCOL_VERSION = '2025-06-18';
	const SUPPORTED_PROTOCOL_VERSIONS = array( '2025-06-18' );

	/** Tools exposed. Each maps 1:1 to an existing public_catalog REST callback. */
	const TOOLS = array( 'search_products', 'get_product', 'list_products', 'list_categories', 'get_meta' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Intercept before WP_REST_Request::has_valid_params() parses JSON. This is
		// required for the body bound and rate limiter to protect malformed input too.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 1, 3 );
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

		// Compatibility metadata for clients that probe below the MCP URL. This is
		// not advertised as OAuth protected-resource metadata: the server is public
		// and keyless, so empty authorization/bearer arrays would be invalid RFC 9728.
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

	public static function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}
		if ( 'POST' !== $request->get_method() || '/' . KALICART_BRIDGE_API_NS . '/mcp' !== $request->get_route() ) {
			return $result;
		}
		return self::handle( $request );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		return self::handle_request( $request );
	}

	private static function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$origin_error = self::validate_origin( $request );
		if ( $origin_error instanceof WP_REST_Response ) {
			return $origin_error;
		}

		// Charge every origin-valid POST before content checks, body reads or JSON parsing.
		$limited = self::check_rate_limit( 1 );
		if ( $limited instanceof WP_REST_Response ) {
			return $limited;
		}

		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'content-type' ), 2 )[0] ) );
		if ( 'application/json' !== $content_type ) {
			return self::respond(
				self::rpc_error( null, -32600, 'Content-Type must be application/json' ),
				415
			);
		}

		$protocol_header = trim( (string) $request->get_header( 'mcp-protocol-version' ) );
		if ( '' !== $protocol_header && ! in_array( $protocol_header, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			return self::respond(
				self::rpc_error( null, -32600, 'Unsupported MCP-Protocol-Version' ),
				400
			);
		}

		$max_bytes      = self::max_body_bytes();
		$content_length = trim( (string) $request->get_header( 'content-length' ) );
		if ( '' !== $content_length && ctype_digit( $content_length ) && (int) $content_length > $max_bytes ) {
			return self::respond(
				self::rpc_error( null, -32000, 'Request body exceeds the MCP size limit' ),
				413
			);
		}

		// The Content-Length guard avoids parsing known-oversized bodies. WordPress
		// has already accepted the request, so the actual byte length is checked too.
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > $max_bytes ) {
			return self::respond(
				self::rpc_error( null, -32000, 'Request body exceeds the MCP size limit' ),
				413
			);
		}

		$body = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::respond(
				self::rpc_error( null, -32700, 'Parse error: body is not valid JSON' ),
				400
			);
		}
		if ( ! is_array( $body ) || '{' !== substr( ltrim( $raw ), 0, 1 ) ) {
			return self::respond(
				self::rpc_error( null, -32600, 'Invalid Request: MCP 2025-06-18 accepts one JSON-RPC object per POST; batching is not supported' ),
				400
			);
		}

		$validation_error = self::validate_jsonrpc_request( $body );
		if ( null !== $validation_error ) {
			return self::respond( $validation_error, 400 );
		}

		// Preserve JSON object/array shape. Associative decoding maps both an empty
		// object and an empty array to [], while MCP tool arguments require an object.
		$shape = json_decode( $raw );
		if ( is_object( $shape ) && property_exists( $shape, 'params' ) && ! is_object( $shape->params ) ) {
			return self::respond( self::rpc_error( $body['id'] ?? null, -32602, 'Invalid params: expected an object' ), 400 );
		}
		$work_cost = self::request_work_cost( $body );
		if ( $work_cost > 1 ) {
			$work_limited = self::check_rate_limit( $work_cost - 1 );
			if ( $work_limited instanceof WP_REST_Response ) {
				return $work_limited;
			}
		}
		$r     = self::dispatch( $body, $shape );
		if ( null === $r ) {
			// Notification — acknowledged with no body.
			return self::no_content_response();
		}
		return self::respond( $r );
	}

	private static function normalize_origin( string $url ): string {
		$parts  = wp_parse_url( trim( $url ) );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host   = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}
		if ( false !== strpos( $host, ':' ) && '[' !== substr( $host, 0, 1 ) ) {
			$host = '[' . $host . ']';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' );
	}

	private static function validate_origin( WP_REST_Request $request ): ?WP_REST_Response {
		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' === $origin ) {
			return null; // Native/non-browser MCP clients normally send no Origin.
		}
		$normalized = self::normalize_origin( $origin );
		$defaults   = array_filter( array_unique( array_map(
			array( __CLASS__, 'normalize_origin' ),
			array( home_url( '/' ), site_url( '/' ), rest_url() )
		) ) );
		$allowed = (array) apply_filters( 'kalicart_bridge_mcp_allowed_origins', $defaults );
		$allowed = array_values( array_filter( array_map( static function( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			return '*' === (string) $value ? '*' : self::normalize_origin( (string) $value );
		}, $allowed ) ) );
		if ( '' === $normalized || ( ! in_array( '*', $allowed, true ) && ! in_array( $normalized, $allowed, true ) ) ) {
			return self::respond( self::rpc_error( null, -32600, 'Origin is not allowed' ), 403 );
		}
		return null;
	}

	private static function valid_jsonrpc_id( $id ): bool {
		return null === $id || is_string( $id ) || is_int( $id ) || is_float( $id );
	}

	private static function validate_jsonrpc_request( array $message ): ?array {
		$id = array_key_exists( 'id', $message ) && self::valid_jsonrpc_id( $message['id'] )
			? $message['id']
			: null;
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return self::rpc_error( $id, -32600, 'Invalid Request: jsonrpc must be "2.0"' );
		}
		if ( array_key_exists( 'id', $message ) && ! self::valid_jsonrpc_id( $message['id'] ) ) {
			return self::rpc_error( null, -32600, 'Invalid Request: id must be a string, number or null' );
		}
		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) || '' === $message['method'] ) {
			return self::rpc_error( $id, -32600, 'Invalid Request: method must be a non-empty string' );
		}
		if ( array_key_exists( 'params', $message ) && ! is_array( $message['params'] ) ) {
			return self::rpc_error( $id, -32602, 'Invalid params: params must be an object or array' );
		}
		return null;
	}

	private static function respond( array $rpc, int $status = 200 ): WP_REST_Response {
		$resp = new WP_REST_Response( $rpc, $status );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	private static function no_content_response(): WP_REST_Response {
		$resp = new WP_REST_Response( null, 202 );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	// ── /.well-known/oauth-protected-resource (RFC 9728, keyless) ────────────────

	public static function oauth_protected_resource_metadata(): WP_REST_Response {
		$resp = new WP_REST_Response(
			array(
				'resource'               => rest_url( KALICART_BRIDGE_API_NS . '/mcp' ),
				'resource_name'          => 'KaliCart Bridge MCP — ' . get_bloginfo( 'name' ),
				'resource_documentation' => rest_url( KALICART_BRIDGE_API_NS . '/discovery' ),
				'authentication'         => 'none',
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
		if ( method_exists( 'KaliCart_Bridge_Signals', 'count_mcp_event' ) ) {
			KaliCart_Bridge_Signals::count_mcp_event( $dims );
		}
	}

	// ── JSON-RPC dispatch ─────────────────────────────────────────────────────────

	private static function max_body_bytes(): int {
		return min( 2 * MB_IN_BYTES, max( 1024, (int) apply_filters( 'kalicart_bridge_mcp_max_body_bytes', 256 * 1024 ) ) );
	}

	/** Weight catalog work while keeping one framing debit for every POST. */
	private static function request_work_cost( array $message ): int {
		if ( 'tools/call' !== ( $message['method'] ?? '' ) || ! is_array( $message['params'] ?? null ) ) {
			return 1;
		}
		$params = $message['params'];
		$name   = is_string( $params['name'] ?? null ) ? $params['name'] : '';
		$args   = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];
		if ( in_array( $name, [ 'list_categories', 'get_meta' ], true ) ) {
			return 2;
		}
		if ( 'get_product' === $name ) {
			return 3;
		}
		if ( ! in_array( $name, [ 'search_products', 'list_products' ], true ) ) {
			return 1;
		}
		$per_page = is_int( $args['per_page'] ?? null ) ? min( 100, max( 1, $args['per_page'] ) ) : 10;
		$cost     = (int) ceil( $per_page / 50 ); // MCP list/search always request summary fields.
		foreach ( [ 'gender', 'color', 'on_sale', 'min_price', 'max_price' ] as $derived ) {
			if ( array_key_exists( $derived, $args ) && null !== $args[ $derived ] && false !== $args[ $derived ] && '' !== $args[ $derived ] ) {
				$cost += 2;
				break;
			}
		}
		return min( 20, max( 1, $cost ) );
	}

	private static function rate_limited_response( int $retry_after, string $message = 'MCP request rate limit exceeded' ): WP_REST_Response {
		$resp = self::respond( self::rpc_error( null, -32002, $message ), 429 );
		$resp->header( 'Retry-After', (string) max( 1, $retry_after ) );
		return $resp;
	}

	/** @return true|WP_REST_Response */
	private static function check_rate_limit( int $cost ) {
		$result = KaliCart_Bridge_Rate_Guard::check( 'mcp', max( 1, $cost ), array(
			'client_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_mcp_rate_limit_per_client', 30 ) ),
			'client_window' => max( 1, (int) apply_filters( 'kalicart_bridge_mcp_rate_limit_per_client_secs', 60 ) ),
			'global_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_mcp_rate_limit_global', 40 ) ),
			'global_window' => max( 1, (int) apply_filters( 'kalicart_bridge_mcp_rate_limit_global_secs', 10 ) ),
		) );
		if ( ! $result['allowed'] ) {
			$message = in_array( $result['reason'], array( 'lock_unavailable', 'storage_unavailable' ), true )
				? 'MCP rate limiter is temporarily unavailable'
				: 'MCP request rate limit exceeded';
			return self::rate_limited_response( $result['retry_after'], $message );
		}
		return true;
	}

	// JSON-RPC dispatch (all request bounds above have already passed).
	/**
	 * @return array|null JSON-RPC response, or null for notifications (no reply).
	 */
	private static function dispatch( array $msg, $shape = null ) {
		$is_notification = ! array_key_exists( 'id', $msg );
		$id              = $msg['id'] ?? null;

		$method = $msg['method'];
		$params = isset( $msg['params'] ) ? $msg['params'] : array();
		$params_shape = is_object( $shape ) && property_exists( $shape, 'params' ) ? $shape->params : null;

		switch ( $method ) {
			case 'initialize':
				if ( ! is_object( $params_shape )
					|| ! isset( $params_shape->capabilities, $params_shape->clientInfo )
					|| ! is_object( $params_shape->capabilities )
					|| ! is_object( $params_shape->clientInfo )
					|| ! isset( $params['protocolVersion'], $params['capabilities'], $params['clientInfo'] )
					|| ! is_string( $params['protocolVersion'] )
					|| '' === $params['protocolVersion']
					|| ! is_array( $params['capabilities'] )
					|| ! is_array( $params['clientInfo'] )
					|| ! isset( $params['clientInfo']['name'], $params['clientInfo']['version'] )
					|| ! is_string( $params['clientInfo']['name'] )
					|| ! is_string( $params['clientInfo']['version'] )
					|| '' === $params['clientInfo']['name']
				) {
					return $is_notification ? null : self::rpc_error( $id, -32602, 'Invalid initialize params' );
				}
				self::track( array( 'method' => 'initialize', 'client' => self::client_label( $params ) ) );
				$result = self::r_initialize( $params );
				break;

			case 'tools/list':
				self::track( array( 'method' => 'tools/list' ) );
				$result = array( 'tools' => self::tool_definitions() );
				break;

			case 'tools/call':
				// Owns its full envelope (tool errors are results, not protocol errors).
				if ( ! is_object( $params_shape ) ) {
					return $is_notification ? null : self::rpc_error( $id, -32602, 'Invalid tools/call params: expected an object' );
				}
				$arguments_are_object = ! property_exists( $params_shape, 'arguments' ) || is_object( $params_shape->arguments );
				$result               = self::r_tools_call( $id, $params, $arguments_are_object );
				return $is_notification ? null : $result;

			case 'ping':
				self::track( array( 'method' => 'ping' ) );
				$result = (object) array();
				break;

			default:
				// notifications/* are valid only without an id. A request carrying an id
				// must receive a response rather than being silently discarded.
				if ( 0 === strpos( $method, 'notifications/' ) ) {
					if ( ! $is_notification ) {
						return self::rpc_error( $id, -32600, 'Invalid Request: notification methods must not include id' );
					}
					self::track( array( 'method' => 'notification' ) );
					return null;
				}
				if ( $is_notification ) {
					self::track( array( 'method' => 'unknown' ) );
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
		$selected  = self::PROTOCOL_VERSION;

		return array(
			'protocolVersion' => $selected,
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
					'Prices are catalog prices in major currency units. For variable products, on_sale may apply to only some size/color variants: inspect price.sale_scope and verify the selected variant before quoting a discount. Checkout stays on the merchant storefront; this server never takes payment.',
				)
			),
		);
	}

	// ── tools/call ────────────────────────────────────────────────────────────────

	private static function validate_tool_arguments( string $name, array $args ): ?string {
		$filter_types = array(
			'q'         => 'string',
			'category'  => 'string',
			'gender'    => 'string',
			'color'     => 'string',
			'per_page'  => 'integer',
			'page'      => 'integer',
			'orderby'   => 'string',
			'order'     => 'string',
			'in_stock'  => 'boolean',
			'on_sale'   => 'boolean',
			'min_price' => 'number',
			'max_price' => 'number',
		);

		if ( 'get_product' === $name ) {
			if ( array_keys( $args ) !== array( 'id' ) || ! is_int( $args['id'] ?? null ) || $args['id'] < 1 ) {
				return 'get_product requires one positive integer id';
			}
			return null;
		}
		if ( in_array( $name, array( 'list_categories', 'get_meta' ), true ) ) {
			return empty( $args ) ? null : $name . ' does not accept arguments';
		}
		if ( ! in_array( $name, array( 'search_products', 'list_products' ), true ) ) {
			return null;
		}

		$allowed = array_keys( $filter_types );
		if ( 'list_products' === $name ) {
			$allowed = array_values( array_diff( $allowed, array( 'q' ) ) );
		}
		foreach ( $args as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return 'unsupported argument: ' . (string) $key;
			}
			$type_ok = match ( $filter_types[ $key ] ) {
				'string'  => is_string( $value ),
				'integer' => is_int( $value ),
				'boolean' => is_bool( $value ),
				'number'  => ( is_int( $value ) || is_float( $value ) ) && ! is_bool( $value ),
				default   => false,
			};
			if ( ! $type_ok ) {
				return $key . ' has the wrong type; expected ' . $filter_types[ $key ];
			}
		}

		$max_page = min( 100000, max( 1, (int) apply_filters( 'kalicart_bridge_catalog_max_page', 1000 ) ) );
		if ( isset( $args['per_page'] ) && ( $args['per_page'] < 1 || $args['per_page'] > 100 ) ) {
			return 'per_page must be between 1 and 100';
		}
		if ( isset( $args['page'] ) && ( $args['page'] < 1 || $args['page'] > $max_page ) ) {
			return 'page is outside the safe catalog pagination range';
		}
		if ( isset( $args['gender'] ) && ! in_array( $args['gender'], array( 'male', 'female', 'unisex', 'kids' ), true ) ) {
			return 'gender is not an accepted enum value';
		}
		if ( isset( $args['color'] ) && ! in_array( $args['color'], array( 'red', 'blue', 'green', 'black', 'white', 'grey', 'brown', 'yellow', 'orange', 'pink', 'purple', 'multi' ), true ) ) {
			return 'color is not an accepted enum value';
		}
		if ( isset( $args['orderby'] ) && ! in_array( $args['orderby'], array( 'date', 'price', 'title', 'popularity' ), true ) ) {
			return 'orderby is not an accepted enum value';
		}
		if ( isset( $args['order'] ) && ! in_array( $args['order'], array( 'ASC', 'DESC' ), true ) ) {
			return 'order must be ASC or DESC';
		}
		if ( isset( $args['min_price'] ) && $args['min_price'] < 0 ) {
			return 'min_price must not be negative';
		}
		if ( isset( $args['max_price'] ) && $args['max_price'] < 0 ) {
			return 'max_price must not be negative';
		}
		if ( isset( $args['min_price'], $args['max_price'] ) && $args['min_price'] > $args['max_price'] ) {
			return 'min_price must not exceed max_price';
		}
		return null;
	}

	private static function r_tools_call( $id, array $params, bool $arguments_are_object ): array {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		if ( ! $arguments_are_object || ( array_key_exists( 'arguments', $params ) && ! is_array( $params['arguments'] ) ) ) {
			self::track( array( 'method' => 'tools/call', 'tool' => '(invalid)', 'outcome' => 'error' ) );
			return self::tool_result( $id, array( 'error' => 'Invalid tool arguments: arguments must be an object' ), true );
		}
		$args = isset( $params['arguments'] ) ? $params['arguments'] : array();

		if ( ! in_array( $name, self::TOOLS, true ) ) {
			self::track( array( 'method' => 'tools/call', 'tool' => '(invalid)', 'outcome' => 'error' ) );
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}
		$argument_error = self::validate_tool_arguments( $name, $args );
		if ( null !== $argument_error ) {
			self::track( array( 'method' => 'tools/call', 'tool' => $name, 'outcome' => 'error' ) );
			return self::tool_result( $id, array( 'error' => 'Invalid tool arguments: ' . $argument_error ), true );
		}

		try {
			$data = self::run_tool( $name, $args );
		} catch ( \Throwable $e ) {
			self::track( array( 'method' => 'tools/call', 'tool' => $name, 'outcome' => 'error' ) );
			// Never expose exception messages: WooCommerce/DB exceptions may contain
			// filesystem paths, SQL fragments or other internal implementation details.
			return self::tool_result( $id, array( 'error' => 'Tool execution failed' ), true );
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
				return self::unwrap( self::call_catalog_api( 'catalog_product', $req ) );

			case 'list_categories':
				return self::unwrap( self::call_catalog_api( 'catalog_categories', new WP_REST_Request( 'GET' ) ) );

			case 'get_meta':
				return self::unwrap( self::call_catalog_api( 'catalog_meta', new WP_REST_Request( 'GET' ) ) );
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
		return self::unwrap( self::call_catalog_api( $callback, $req ) );
	}

	/**
	 * Internal PHP-only context: MCP owns rate limiting for these calls, so the
	 * reused REST callback must not debit the public catalog limiter a second time.
	 */
	private static function call_catalog_api( string $callback, WP_REST_Request $req ): WP_REST_Response {
		$callable = array( 'KaliCart_Bridge_API', $callback );
		if ( method_exists( 'KaliCart_Bridge_API', 'internal_catalog_call' ) ) {
			return KaliCart_Bridge_API::internal_catalog_call( $callable, $req );
		}
		return call_user_func( $callable, $req );
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
				'description' => 'true returns products with an active WooCommerce sale price; for variable products only some variants may be discounted, so verify price.sale_scope and the selected variant (coupon-only savings excluded).',
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
				'maximum'     => min( 100000, max( 1, (int) apply_filters( 'kalicart_bridge_catalog_max_page', 1000 ) ) ),
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
					'type'                 => 'object',
					'properties'           => (object) $search_props,
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'list_products',
				'title'       => 'List products',
				'description' => 'List compact summary records (paginated), optionally filtered. Same filters as search_products but without a free-text query; use get_product only after selection.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => (object) $filter_props,
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'get_product',
				'title'       => 'Verify selected product',
				'description' => 'After ranking summaries, call once for the final selected product. Returns compact price, stock, variants, shipping and coupon evidence.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => (object) array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'WooCommerce product ID (from a search/list result).',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'list_categories',
				'title'       => 'List categories',
				'description' => 'Return the merchant-native WooCommerce category tree (slugs + names). Use a slug as the category argument of search_products/list_products.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'additionalProperties' => false,
				),
			),
			array(
				'name'        => 'get_meta',
				'title'       => 'Get catalog meta',
				'description' => 'Return accepted filter values (category slugs, genders, colours), the price range and the merchant shipping policy. Call this first to ground a search.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'additionalProperties' => false,
				),
			),
		);
	}

	// ── GET → 405 (no server-initiated SSE stream offered here) ──────────────────────

	public static function no_sse( WP_REST_Request $req ): WP_REST_Response {
		$origin_error = self::validate_origin( $req );
		if ( $origin_error instanceof WP_REST_Response ) {
			return $origin_error;
		}
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
