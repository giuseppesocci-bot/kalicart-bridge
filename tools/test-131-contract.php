<?php
/**
 * KaliCart Bridge 1.0.131 catalog contract verification.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-131-contract.php
 *
 * The test is catalog-data agnostic. It temporarily removes only the facet
 * timestamp, intercepts the repair schedule instead of persisting it, and restores
 * the original option before exit.
 */

defined( 'ABSPATH' ) || exit( 1 );

$failures = [];
$report   = [];
$check    = static function( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$call = static function( string $callback, string $route, array $query = [] ): WP_REST_Response {
    $request = new WP_REST_Request( 'GET', $route );
    $request->set_query_params( $query );
    if ( 'catalog_product' === $callback && preg_match( '#/catalog/product/(\d+)$#', $route, $match ) ) {
        $request->set_url_params( [ 'id' => (int) $match[1] ] );
    }
    return KaliCart_Bridge_API::internal_catalog_call( [ 'KaliCart_Bridge_API', $callback ], $request );
};

// Compact summaries are self-describing and carry gender even when it is null.
$summary_response = $call( 'catalog_products', '/kalicart/v1/catalog/products', [ 'fields' => 'summary', 'per_page' => 3 ] );
$summary_data     = $summary_response->get_data();
$check( 200 === $summary_response->get_status(), 'Summary listing did not return 200.' );
$check( ! empty( $summary_data['products'] ), 'Summary listing returned no product on a non-empty test catalog.' );
foreach ( $summary_data['products'] ?? [] as $product ) {
    $check( array_key_exists( 'gender', $product ), 'A summary product omitted gender instead of returning an explicit null.' );
    $check( get_woocommerce_currency() === ( $product['price']['currency'] ?? null ), 'A summary price omitted the merchant currency.' );
    $check( 'decimal_major_units' === ( $product['price']['encoding'] ?? null ), 'A summary price omitted decimal_major_units encoding.' );
}

// Price-filter responses echo the exact unit and interval-overlap semantics.
$price_response = $call( 'catalog_products', '/kalicart/v1/catalog/products', [
    'fields'    => 'summary',
    'per_page'  => 1,
    'min_price' => 0,
] );
$price_data = $price_response->get_data();
$check( 200 === $price_response->get_status(), 'Price-filtered listing did not return 200.' );
$check( 'decimal_major_units' === ( $price_data['query_interpretation']['price_unit'] ?? null ), 'Price query interpretation omitted major units.' );
$check( 'product_price_interval_overlaps_requested_range' === ( $price_data['query_interpretation']['price_match_rule'] ?? null ), 'Price query interpretation omitted interval-overlap semantics.' );

// Unknown parameters fail closed. The historically dangerous `search` alias on
// /catalog/products returns an actionable correction instead of a plausible list.
$unknown_response = $call( 'catalog_search', '/kalicart/v1/catalog/search', [ 'q' => 'test', 'invented' => '1' ] );
$unknown_data     = $unknown_response->get_data();
$check( 400 === $unknown_response->get_status(), 'Unknown catalog query parameter was not rejected.' );
$check( in_array( 'invented', $unknown_data['invalid_parameters'] ?? [], true ), 'Unknown parameter error did not identify the parameter.' );
$check( false === ( $unknown_data['search_executed'] ?? null ), 'Unknown parameter error did not prove that search was skipped.' );

$search_alias_response = $call( 'catalog_products', '/kalicart/v1/catalog/products', [ 'search' => 'materasso' ] );
$search_alias_data     = $search_alias_response->get_data();
$check( 400 === $search_alias_response->get_status(), 'search on /catalog/products was not rejected.' );
$check( in_array( 'search', $search_alias_data['invalid_parameters'] ?? [], true ), 'search alias error omitted invalid_parameters.' );
$check( isset( $search_alias_data['parameter_corrections']['search'], $search_alias_data['correct_endpoint'], $search_alias_data['suggested_url'] ), 'search alias error omitted its correction chain.' );
$check( false !== strpos( (string) ( $search_alias_data['suggested_url'] ?? '' ), '/catalog/search' ), 'search alias correction did not point to /catalog/search.' );
$check( false !== strpos( (string) ( $search_alias_data['suggested_url'] ?? '' ), 'q=materasso' ), 'search alias correction did not preserve the search text as q.' );

// The compact verification record exposes parent/product attributes independently
// from variants[].attributes and agrees with fields=full.
$first_id = (int) ( $summary_data['products'][0]['id'] ?? 0 );
if ( $first_id > 0 ) {
    $verification = $call( 'catalog_product', '/kalicart/v1/catalog/product/' . $first_id )->get_data();
    $full         = $call( 'catalog_product', '/kalicart/v1/catalog/product/' . $first_id, [ 'fields' => 'full' ] )->get_data();
    $check( array_key_exists( 'attributes', $verification ), 'Verification projection omitted top-level product attributes.' );
    $check( ( $verification['attributes'] ?? null ) === ( $full['attributes'] ?? null ), 'Verification product attributes differ from fields=full.' );
    foreach ( $verification['variants'] ?? [] as $variant ) {
        $check( array_key_exists( 'attributes', $variant ), 'A verification variant omitted variation-level attributes.' );
    }
}

// Gender evidence is computed on the strict-filter baseline and reconciles exactly.
$category = (string) ( $summary_data['products'][0]['categories'][0] ?? '' );
$gender_query = [ 'fields' => 'summary', 'per_page' => 3, 'gender' => 'male' ];
if ( '' !== $category ) {
    $gender_query['category'] = $category;
}
$gender_response = $call( 'catalog_products', '/kalicart/v1/catalog/products', $gender_query );
$gender_data     = $gender_response->get_data();
$effect          = $gender_data['filter_effect']['gender'] ?? [];
$evaluated       = (int) ( $effect['evaluated_count'] ?? -1 );
$matched         = (int) ( $effect['matched_count'] ?? -1 );
$unknown         = (int) ( $effect['unknown_retained_count'] ?? -1 );
$excluded        = (int) ( $effect['excluded_count'] ?? -1 );
$check( 200 === $gender_response->get_status(), 'Gender-filtered listing did not return 200.' );
$check( 'candidates_matching_all_other_filters' === ( $effect['evaluated_scope'] ?? null ), 'Gender effect has the wrong evaluation scope.' );
$check( true === ( $effect['evaluation_complete'] ?? null ), 'Gender effect was not marked complete.' );
$check( $evaluated >= 0 && $evaluated === $matched + $unknown + $excluded, 'Gender effect counters do not reconcile.' );
$check( ( $excluded > 0 ) === ( $effect['changed_result_set'] ?? null ), 'Gender changed_result_set disagrees with excluded_count.' );
if ( $evaluated > 0 && 0 === $matched && 0 === $excluded ) {
    $check( 'GENDER_FILTER_NO_EFFECT' === ( $gender_data['result_guidance']['code'] ?? null ), 'Proven gender no-op did not return GENDER_FILTER_NO_EFFECT.' );
}
if ( isset( $gender_data['result_guidance'] ) ) {
    $check( isset( $gender_data['result_guidance']['code'] ), 'Result guidance omitted code.' );
    $check( ! isset( $gender_data['result_guidance']['guidance_code'] ), 'Legacy guidance_code leaked into a result.' );
}
foreach ( $gender_data['products'] ?? [] as $product ) {
    $check( array_key_exists( 'gender', $product ), 'Gender-filtered summary omitted gender.' );
    $check( ( $product['gender'] ?? null ) === ( $product['filter_evidence']['gender']['detected'] ?? null ), 'Summary gender disagrees with reused filter evidence.' );
}
$report['gender_effect'] = $effect;

// A missing facet timestamp is explicit unknown provenance and requests an async
// repair without running the O(n) facet builder in this request.
$lang       = KaliCart_Bridge_API::default_language() ?? 'mono';
$time_key   = 'kalicart_bridge_catalog_facets_at_' . $lang;
$cache_key  = 'kalicart_bridge_meta_' . $lang;
$missing    = '__kalicart_missing__';
$old_time   = get_option( $time_key, $missing );
$scheduled  = false;
$intercept_schedule = static function( $pre, $event ) use ( &$scheduled ) {
    if ( is_object( $event ) && 'kalicart_bridge_facets_rebuild' === ( $event->hook ?? '' ) ) {
        $scheduled = true;
        return true;
    }
    return $pre;
};
add_filter( 'pre_schedule_event', $intercept_schedule, 10, 2 );
delete_option( $time_key );
delete_transient( $cache_key );
$meta_response = $call( 'catalog_meta', '/kalicart/v1/catalog/meta' );
$meta_data     = $meta_response->get_data();
$availability  = $meta_data['filter_vocabulary']['availability'] ?? [];
$next_rebuild  = wp_next_scheduled( 'kalicart_bridge_facets_rebuild', [ KaliCart_Bridge_API::default_language() ?? null ] );
$check( array_key_exists( 'computed_at', $availability ) && null === $availability['computed_at'], 'Missing facet timestamp was not exposed as computed_at:null.' );
$check( 12 === ( $availability['max_staleness_hours'] ?? null ), 'Facet max staleness contract changed unexpectedly.' );
$check( 'unknown' === ( $availability['freshness_status'] ?? null ), 'Missing facet timestamp was not classified as unknown.' );
$check( $scheduled || ( $next_rebuild && $next_rebuild <= time() + MINUTE_IN_SECONDS ), 'Missing facet timestamp did not request a near-term asynchronous rebuild.' );
$check( 'populated_categories_only' === ( $meta_data['categories_scope']['population'] ?? null ), 'get_meta did not declare its populated-only category scope.' );
$check( false !== strpos( (string) ( $meta_data['categories_scope']['complete_taxonomy_endpoint'] ?? '' ), '/catalog/categories' ), 'get_meta did not identify the complete taxonomy endpoint.' );
remove_filter( 'pre_schedule_event', $intercept_schedule, 10 );
if ( $missing === $old_time ) {
    delete_option( $time_key );
} else {
    update_option( $time_key, $old_time, false );
}
delete_transient( $cache_key );

// If this catalog currently has an empty leaf category, the zero-result reason is
// proven from the live taxonomy rather than from cached available_values.
$empty_terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 20 ] );
if ( ! is_wp_error( $empty_terms ) ) {
    foreach ( $empty_terms as $term ) {
        if ( (int) $term->count !== 0 ) {
            continue;
        }
        $children = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids' ] );
        if ( is_wp_error( $children ) || ! empty( $children ) ) {
            continue;
        }
        $empty_category = $call( 'catalog_products', '/kalicart/v1/catalog/products', [ 'category' => $term->slug, 'fields' => 'summary' ] )->get_data();
        if ( 0 === (int) ( $empty_category['total'] ?? -1 ) ) {
            $check( 'VALID_CATEGORY_NO_RESULTS' === ( $empty_category['result_guidance']['code'] ?? null ), 'A proven valid empty category received generic zero guidance.' );
            $report['empty_category'] = $term->slug;
            break;
        }
    }
}

$openapi = $call( 'openapi', '/kalicart/v1/openapi' )->get_data();
$summary_schema = $openapi['components']['schemas']['ProductSummary']['properties'] ?? [];
$product_schema = $openapi['components']['schemas']['Product']['properties'] ?? [];
$check( isset( $summary_schema['gender'] ), 'OpenAPI ProductSummary omitted gender.' );
$check( isset( $product_schema['attributes'] ), 'OpenAPI Product omitted top-level attributes.' );

echo wp_json_encode( [
    'success'  => empty( $failures ),
    'failures' => $failures,
    'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
    exit( 1 );
}
