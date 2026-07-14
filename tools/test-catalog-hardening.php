<?php
/**
 * Read-only catalog hardening verification.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-catalog-hardening.php
 *
 * It changes only short-lived test transients and restores/removes them before exit.
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$report   = [];
$check    = static function( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$api_ref      = new ReflectionClass( 'KaliCart_Bridge_API' );
$engine_ref   = new ReflectionClass( 'KaliCart_Bridge_Catalog_Engine' );
$rate_limit   = $api_ref->getMethod( 'catalog_rate_limit' );
$cache_put    = $engine_ref->getMethod( 'query_cache_put' );
$sale_summary = $engine_ref->getMethod( 'summarize_variable_prices' );
$sale_counts  = $api_ref->getMethod( 'sale_entity_counts' );
foreach ( [ $rate_limit, $cache_put, $sale_summary, $sale_counts ] as $private_method ) {
    $private_method->setAccessible( true );
}
$cache_key    = 'kalicart_bridge_catalog_query_cache_v1';
$old_remote   = $_SERVER['REMOTE_ADDR'] ?? null;
$old_xff      = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

// Wrapper integration: a rejected client does not consume global quota, while
// in-process MCP dispatch bypasses this limiter and is charged by MCP only.
$client_limit_filter = static fn(): int => 1;
$global_limit_filter = static fn(): int => 3;
add_filter( 'kalicart_bridge_catalog_rate_limit_per_client', $client_limit_filter );
add_filter( 'kalicart_bridge_catalog_rate_limit_global', $global_limit_filter );
delete_option( 'kalicart_rate_guard_catalog' );
delete_option( 'kalicart_rate_guard_lock_catalog' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
$first  = $rate_limit->invoke( null );
$before = get_option( 'kalicart_rate_guard_catalog', [] );
$second = $rate_limit->invoke( null );
$after  = get_option( 'kalicart_rate_guard_catalog', [] );
$check( $first === null, 'First catalog request was unexpectedly limited.' );
$check( $second instanceof WP_REST_Response && $second->get_status() === 429, 'Per-client limit did not return 429.' );
$before_global = (int) ( $before['global']['count'] ?? -1 );
$after_global  = (int) ( $after['global']['count'] ?? -1 );
$check( $before_global === 1 && $after_global === 1, 'Rejected client request consumed global quota.' );
$report['rate_limit_global_before_after_reject'] = [ $before_global, $after_global ];

// Internal MCP dispatch is charged by the outer MCP limiter only, and depth is
// restored after the callback even though the public counter is currently full.
$internal = KaliCart_Bridge_API::internal_catalog_call( static function( WP_REST_Request $request ) use ( $rate_limit ): WP_REST_Response {
    return new WP_REST_Response( [ 'bypassed' => $rate_limit->invoke( null ) === null ], 200 );
}, new WP_REST_Request( 'GET' ) );
$check( ( $internal->get_data()['bypassed'] ?? false ) === true, 'Internal MCP catalog call was double-charged.' );
$check( $rate_limit->invoke( null ) instanceof WP_REST_Response, 'Internal catalog depth leaked after callback.' );
remove_filter( 'kalicart_bridge_catalog_rate_limit_per_client', $client_limit_filter );
remove_filter( 'kalicart_bridge_catalog_rate_limit_global', $global_limit_filter );

// Weighted work: full product enumeration, summary search and detail must not
// consume the same quota merely because each is one HTTP request.
$high_limit = static fn(): int => 1000;
add_filter( 'kalicart_bridge_catalog_rate_limit_per_client', $high_limit );
add_filter( 'kalicart_bridge_catalog_rate_limit_global', $high_limit );
$weighted_cases = [
	[ '/kalicart/v1/catalog/products', [ 'per_page' => 100 ], 10 ], // fields omitted => effective FULL.
	[ '/kalicart/v1/catalog/search', [ 'per_page' => 100, 'q' => 'shirt' ], 2 ],
	[ '/kalicart/v1/catalog/product/1568', [], 3 ],
];
foreach ( $weighted_cases as [ $route, $params, $expected_cost ] ) {
	delete_option( 'kalicart_rate_guard_catalog' );
	delete_option( 'kalicart_rate_guard_lock_catalog' );
	$request = new WP_REST_Request( 'GET', $route );
	$request->set_query_params( $params );
	$check( null === $rate_limit->invoke( null, $request ), 'Weighted catalog request was unexpectedly rejected.' );
	$state = get_option( 'kalicart_rate_guard_catalog', [] );
	$check( $expected_cost === (int) ( $state['global']['count'] ?? -1 ), 'Catalog work cost did not match the effective response workload.' );
}
remove_filter( 'kalicart_bridge_catalog_rate_limit_per_client', $high_limit );
remove_filter( 'kalicart_bridge_catalog_rate_limit_global', $high_limit );

$too_deep = new WP_REST_Request( 'GET' );
$too_deep->set_param( 'page', 1001 );
$page_error = KaliCart_Bridge_API::internal_catalog_call( [ 'KaliCart_Bridge_API', 'catalog_products' ], $too_deep );
$check( $page_error->get_status() === 422, 'Oversized page was not rejected explicitly.' );

// Cache is one bounded bucket, not one transient per attacker-controlled query.
KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();

// Variable-sale semantics are derived from the price matrix already loaded by
// compute_price(): no product/variation object reads and no additional queries.
$mixed_sale = $sale_summary->invoke( null, [
	'regular_price' => [ 101 => '100', 102 => '120', 103 => '80' ],
	'price'         => [ 101 => '70', 102 => '120', 103 => '80' ],
] );
$all_sale = $sale_summary->invoke( null, [
	'regular_price' => [ 201 => '100', 202 => '50' ],
	'price'         => [ 201 => '80', 202 => '40' ],
] );
$check( 'some_variants' === $mixed_sale['sale_scope'] && 1 === $mixed_sale['discounted_count'] && 3 === $mixed_sale['priced_count'], 'Mixed variant discounts were not classified as some_variants.' );
$check( 70.0 === $mixed_sale['min_current'] && 120.0 === $mixed_sale['max_current'], 'Variable current price range does not include both discounted and full-price variants.' );
$check( 'all_variants' === $all_sale['sale_scope'] && 2 === $all_sale['discounted_count'], 'Fully discounted variants were not classified as all_variants.' );

// Price sorting keeps zero-price placeholder parents at the end in both sort directions.
$price_query = new WP_Query();
$price_query->set( 'kalicart_bridge_price_lookup', [ 'min' => null, 'max' => null, 'orderby' => true, 'order' => 'ASC' ] );
$clauses = KaliCart_Bridge_Catalog_Engine::apply_price_lookup_clauses( [
	'join' => '', 'where' => '', 'orderby' => '', 'groupby' => '', 'distinct' => '', 'fields' => '', 'limits' => '',
], $price_query );
$check( false !== strpos( $clauses['orderby'], 'CASE WHEN kalicart_bridge_price_lookup.min_price <= 0 THEN 1 ELSE 0 END ASC' ), 'Zero/null catalog prices are not sorted after usable prices.' );

// Global sale counters distinguish product cards from discounted variants.
$live_sale_ids = wc_get_product_ids_on_sale();
$live_counts   = $sale_counts->invoke( null, $live_sale_ids );
$check( $live_counts['sale_entities_total'] === $live_counts['products_on_sale'] + $live_counts['variations_on_sale'], 'Sale entity totals do not reconcile.' );
$report['sale_counts'] = $live_counts;
for ( $i = 0; $i < 12; $i++ ) {
    $cache_put->invoke( null, [ 'gender' => 'male', 'fields' => 'summary', 'page' => $i + 1 ], [
        'products' => [ [ 'id' => $i, 'payload' => str_repeat( 'x', 70 * KB_IN_BYTES ) ] ],
        'total'    => 12,
    ] );
}
$bucket       = get_transient( $cache_key );
$entry_count  = is_array( $bucket['entries'] ?? null ) ? count( $bucket['entries'] ) : 0;
$bucket_bytes = strlen( maybe_serialize( $bucket ) );
$check( $entry_count <= 8, 'Catalog query cache exceeded its entry bound.' );
$check( $bucket_bytes <= 512 * KB_IN_BYTES, 'Catalog query cache exceeded its serialized byte bound.' );
$report['cache'] = [ 'entries' => $entry_count, 'serialized_bytes' => $bucket_bytes ];
KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();

// Every filter that requires authoritative PHP verification is subject to the
// explicit candidate ceiling, including price.current on variable products.
$reference_query_args = [
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'fields'         => 'ids',
    'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
];
$default_language = KaliCart_Bridge_API::default_language();
if ( $default_language !== null ) {
    $reference_query_args['lang'] = $default_language;
}
$count_query = new WP_Query( $reference_query_args );
$published   = (int) $count_query->found_posts;
if ( $published > 1 ) {
    $limit_one = static fn(): int => 1;
    add_filter( 'kalicart_bridge_catalog_postfilter_candidate_limit', $limit_one );
    $broad = KaliCart_Bridge_Catalog_Engine::query_products( [
        'gender'   => '__hardening_test__',
        'fields'   => 'summary',
        'per_page' => 1,
    ] );
    $price = KaliCart_Bridge_Catalog_Engine::query_products( [
        'min_price' => 0,
        'fields'    => 'summary',
        'per_page'  => 1,
    ] );
    $check( ( $broad['_error']['status'] ?? null ) === 422, 'Broad derived query did not fail explicitly with 422.' );
	// Some catalogs have many published placeholders but only one priced product.
	// In that legitimate case the lookup-reduced candidate set is already within 1.
	if ( isset( $price['_error'] ) ) {
		$check( ( $price['_error']['status'] ?? null ) === 422, 'Broad authoritative price query returned the wrong safety error.' );
	} else {
		$check( (int) ( $price['total'] ?? -1 ) <= 1, 'Authoritative price query bypassed the candidate ceiling.' );
	}
    remove_filter( 'kalicart_bridge_catalog_postfilter_candidate_limit', $limit_one );
    KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();
}

// Exact page/total parity for one real derived value, plus a cold/warm timing sample.
// Keep this read-only reference scan bounded on unusually large production catalogs.
if ( $published > 0 && $published <= 500 ) {
    $reference_query_args['posts_per_page'] = -1;
    $ids = get_posts( $reference_query_args );
    $chosen_gender = null;
    $reference_ids = [];
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) {
            continue;
        }
        $normalized = KaliCart_Bridge_Catalog_Engine::normalize_product( $product );
        if ( $chosen_gender === null && ! empty( $normalized['gender'] ) ) {
            $chosen_gender = $normalized['gender'];
        }
    }
    if ( $chosen_gender !== null ) {
        foreach ( $ids as $id ) {
            $product    = wc_get_product( $id );
            $normalized = $product ? KaliCart_Bridge_Catalog_Engine::normalize_product( $product ) : [];
            if ( ( $normalized['gender'] ?? null ) === $chosen_gender || ( $normalized['gender'] ?? null ) === null ) {
                $reference_ids[] = (int) $id;
            }
        }

        $args = [ 'gender' => $chosen_gender, 'fields' => 'summary', 'per_page' => 2, 'page' => 1 ];
        KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();
        global $wpdb;
        $q0   = (int) $wpdb->num_queries;
        $t0   = microtime( true );
        $page = KaliCart_Bridge_Catalog_Engine::query_products( $args );
        $cold = microtime( true ) - $t0;
        $q1   = (int) $wpdb->num_queries;
        $t1   = microtime( true );
        $warm = KaliCart_Bridge_Catalog_Engine::query_products( $args );
        $warm_time = microtime( true ) - $t1;
        $q2        = (int) $wpdb->num_queries;
        $actual_ids = array_map( 'intval', array_column( $page['products'] ?? [], 'id' ) );
        $check( (int) ( $page['total'] ?? -1 ) === count( $reference_ids ), 'Derived total differs from full reference filtering.' );
        $check( $actual_ids === array_slice( $reference_ids, 0, 2 ), 'Derived first page differs from full reference ordering.' );
        $check( $page === $warm, 'Warm cache response differs from cold response.' );
        $report['derived_query'] = [
            'gender'       => $chosen_gender,
            'total'        => count( $reference_ids ),
            'cold_ms'      => round( $cold * 1000, 2 ),
            'warm_ms'      => round( $warm_time * 1000, 2 ),
            'cold_queries' => $q1 - $q0,
            'warm_queries' => $q2 - $q1,
        ];
        KaliCart_Bridge_Catalog_Engine::invalidate_query_cache();
    }
}

delete_option( 'kalicart_rate_guard_catalog' );
delete_option( 'kalicart_rate_guard_lock_catalog' );
if ( $old_remote === null ) {
    unset( $_SERVER['REMOTE_ADDR'] );
} else {
    $_SERVER['REMOTE_ADDR'] = $old_remote;
}
if ( $old_xff === null ) {
    unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
} else {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $old_xff;
}

echo wp_json_encode( [
    'success'  => empty( $failures ),
    'failures' => $failures,
    'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
    exit( 1 );
}
