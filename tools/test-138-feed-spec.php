<?php
/**
 * KaliCart Bridge 1.0.138 — the OpenAI product feed follows the current stable
 * spec: neither looser nor stricter (decided 2026-09-26, debt item 6).
 * Spec: developers.openai.com/commerce/specs/file-upload/products (read 2026-09-26).
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-138-feed-spec.php
 */

$fails = 0;
$check = function ( string $name, bool $ok ) use ( &$fails ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		$fails++;
	}
};
$F = 'KaliCart_Bridge_ACP_Feed';
$has = function ( array $errors, string $needle ): bool {
	foreach ( $errors as $e ) {
		if ( false !== strpos( $e, $needle ) ) {
			return true;
		}
	}
	return false;
};

// Minimal conformant row: only the spec's Required fields.
$min = [
	'item_id' => 'wc-1', 'title' => 'Mug', 'description' => 'A ceramic mug.', 'url' => 'https://shop.test/mug',
	'brand' => 'Acme', 'seller_name' => 'Shop', 'image_url' => 'https://shop.test/mug.jpg',
	'availability' => 'in_stock', 'price' => '10.00 EUR',
];
$check( 'minimal row with only Required fields is conformant', [] === $F::validate_row( $min ) );

// brand Required
$r = $min; unset( $r['brand'] );
$check( 'row without brand is rejected (brand Required)', $has( $F::validate_row( $r ), 'missing required brand' ) );

// optional fields no longer required
foreach ( [ 'seller_url', 'return_policy', 'store_country', 'target_countries' ] as $f ) {
	$check( "absent $f does not invalidate the row", ! $has( $F::validate_row( $min ), $f ) );
}
// ...but validated when present
$r = $min + [ 'target_countries' => [ 'IT', 'xx' ] ];
$check( 'bad target country rejected when present', $has( $F::validate_row( $r ), 'bad country code xx' ) );
$r = $min + [ 'return_policy' => 'not-a-url' ];
$check( 'bad return_policy rejected when present', $has( $F::validate_row( $r ), 'return_policy' ) );

// URLs: absolute http or https (https preferred, not required)
$r = $min; $r['url'] = 'http://shop.test/mug';
$check( 'http URL accepted (spec: http or https, prefer https)', [] === $F::validate_row( $r ) );
$r = $min; $r['image_url'] = '/mug.jpg';
$check( 'relative image_url rejected', $has( $F::validate_row( $r ), 'image_url' ) );

// additional_image_urls: array in JSONL
$r = $min + [ 'additional_image_urls' => [ 'https://shop.test/a.jpg', 'https://shop.test/b.jpg' ] ];
$check( 'additional_image_urls as array accepted', [] === $F::validate_row( $r ) );
$r = $min + [ 'additional_image_urls' => 'https://shop.test/a.jpg,https://shop.test/b.jpg' ];
$check( 'additional_image_urls as comma string rejected in JSONL', $has( $F::validate_row( $r ), 'must be an array' ) );

// GTIN
$check( 'GTIN-13 valid check digit', $F::is_valid_gtin( '4006381333931' ) );
$check( 'GTIN-12 (UPC) valid', $F::is_valid_gtin( '036000291452' ) );
$check( 'GTIN-8 valid', $F::is_valid_gtin( '96385074' ) );
$check( 'GTIN-14 valid', $F::is_valid_gtin( '00012345600012' ) );
$check( 'GTIN wrong check digit rejected', ! $F::is_valid_gtin( '4006381333932' ) );
$check( 'GTIN length 10 rejected', ! $F::is_valid_gtin( '1234567890' ) );
$check( 'GTIN leading zeros preserved', $F::is_valid_gtin( '00012345600012' ) );
$r = $min + [ 'gtin' => '4006381333932' ];
$check( 'row with bad GTIN rejected by validator', $has( $F::validate_row( $r ), 'gtin' ) );

// sale_price strictly less than price, same currency
$r = $min + [ 'sale_price' => '10.00 EUR' ];
$check( 'sale_price equal to price rejected', $has( $F::validate_row( $r ), 'strictly less' ) );
$r = $min + [ 'sale_price' => '9.00 USD' ];
$check( 'sale_price in another currency rejected', $has( $F::validate_row( $r ), 'currency' ) );
$r = $min + [ 'sale_price' => '9.00 EUR' ];
$check( 'valid sale_price accepted', [] === $F::validate_row( $r ) );

// price positive
$r = $min; $r['price'] = '0.00 EUR';
$check( 'zero price rejected', $has( $F::validate_row( $r ), 'positive' ) );

// variants
$v = $min + [ 'group_id' => 'wc-9', 'listing_has_variations' => true ];
$check( 'variant row without variant_dict rejected', $has( $F::validate_row( $v ), 'variant_dict required' ) );
$v['variant_dict'] = [ 'Color' => 'Red' ];
$check( 'complete variant row accepted', [] === $F::validate_row( $v ) );
$v['group_id'] = 'wc-1';
$check( 'group_id equal to item_id rejected', $has( $F::validate_row( $v ), 'group_id must differ' ) );

// star_rating needs positive review_count
$r = $min + [ 'star_rating' => '4.50' ];
$check( 'star_rating without review_count rejected', $has( $F::validate_row( $r ), 'review_count' ) );
$r = $min + [ 'star_rating' => '4.50', 'review_count' => 3 ];
$check( 'star_rating with review_count accepted', [] === $F::validate_row( $r ) );

// no invented length limits (brand, seller_name, item_id have none in the spec)
$r = $min; $r['brand'] = str_repeat( 'B', 120 ); $r['seller_name'] = str_repeat( 'S', 120 );
$check( 'long brand and seller_name not rejected (no limit in spec)', [] === $F::validate_row( $r ) );
$r = $min; $r['title'] = str_repeat( 'T', 151 );
$check( 'title over 150 rejected', $has( $F::validate_row( $r ), 'title exceeds' ) );

// Generator end-to-end on this store
$stats = $F::generate();
$check( 'generation runs without blocking error', empty( $stats['error'] ) );
$check( 'stats count brand exclusions (new key)', array_key_exists( 'excluded_no_brand', $stats ) && ! array_key_exists( 'rows_missing_brand', $stats ) );
$up   = wp_upload_dir();
$opts = $F::get_options();
$path = trailingslashit( $up['basedir'] ) . 'kalicart-bridge/' . $opts['token'] . '/acp-products.jsonl';
$lines = is_readable( $path ) ? file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : [];
$bad = 0; $nobrand = 0; $strimg = 0;
foreach ( $lines as $l ) {
	$row = json_decode( $l, true );
	if ( ! is_array( $row ) || [] !== $F::validate_row( $row ) ) { $bad++; }
	if ( empty( $row['brand'] ) ) { $nobrand++; }
	if ( isset( $row['additional_image_urls'] ) && ! is_array( $row['additional_image_urls'] ) ) { $strimg++; }
}
$check( 'file has rows (' . count( $lines ) . ')', count( $lines ) > 0 );
$check( 'every emitted row re-validates', 0 === $bad );
$check( 'no emitted row without brand', 0 === $nobrand );
$check( 'no emitted row with string additional_image_urls', 0 === $strimg );
echo 'stats: rows=' . (int) $stats['rows'] . ' excluded_no_brand=' . (int) $stats['excluded_no_brand'] . ' excluded_no_image=' . (int) $stats['excluded_no_image'] . ' excluded_invalid=' . (int) $stats['excluded_invalid'] . ' gtin_omitted=' . (int) $stats['gtin_omitted'] . "\n";
if ( ! empty( $stats['invalid_examples'] ) ) { echo 'invalid: ' . implode( ' | ', $stats['invalid_examples'] ) . "\n"; }

echo $fails ? "\n$fails FAIL\n" : "\nALL PASS\n";
