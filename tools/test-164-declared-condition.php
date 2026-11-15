<?php
/**
 * 1.0.164: the ChatGPT feed carries `condition` only when the merchant declares it.
 * Declared spec value (new | refurbished | used) -> passed through; no declaration or a
 * non-standard value -> field omitted (never assumed 'new'). No inference, no translation.
 * Run in an isolated WooCommerce: wp eval-file wp-content/plugins/kalicart-bridge/tools/test-164-declared-condition.php
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Cart' ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture required.' ); }
$fails = 0; $n = 0;
$ok = function ( $c, $m ) use ( &$fails, &$n ) { $n++; if ( $c ) { echo "PASS $m\n"; } else { $fails++; echo "FAIL $m\n"; } };
// The isolated fixture has no media: give test products a resolvable primary image.
$img = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'kb164', 'post_mime_type' => 'image/jpeg' ] );
add_filter( 'wp_get_attachment_image_src', static fn( $i, $id ) => (int) $id === (int) $img ? [ 'https://example.com/kb164.jpg', 800, 800, false ] : $i, 10, 2 );
// Brand is required by the spec: attach a merchant brand term.
if ( ! taxonomy_exists( 'product_brand' ) ) { register_taxonomy( 'product_brand', 'product' ); }
$brand = static fn( int $id ) => wp_set_object_terms( $id, 'KB164 Brand', 'product_brand' );
$row = static function ( WC_Product $p, ?WC_Product $parent = null ) {
	$m = new ReflectionMethod( 'KaliCart_Bridge_ACP_Feed', 'base_row' ); $m->setAccessible( true );
	$opts = KaliCart_Bridge_ACP_Feed::get_options(); $countries = [ 'IT' ]; $stats = [];
	$args = [ $p, $opts, $countries, &$stats, $parent ];
	return $m->invokeArgs( null, $args );
};
$attr = static function ( string $value ) {
	$a = new WC_Product_Attribute(); $a->set_name( 'Condition' ); $a->set_options( [ $value ] ); $a->set_visible( true ); $a->set_variation( false );
	return $a;
};
$make = static function ( string $name, ?string $cond_attr = null, ?string $cond_meta = null ) use ( $attr, $img, $brand ) {
	$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_description( 'A product with a real description for the feed.' );
	$p->set_status( 'publish' ); $p->set_regular_price( '10' ); $p->set_image_id( $img );
	if ( null !== $cond_attr ) { $p->set_attributes( [ $attr( $cond_attr ) ] ); }
	if ( null !== $cond_meta ) { $p->update_meta_data( '_condition', $cond_meta ); }
	$p->save(); $brand( $p->get_id() ); return wc_get_product( $p->get_id() );
};

$r = $row( $make( 'Undeclared product' ) );
$ok( is_array( $r ) && ! array_key_exists( 'condition', $r ), 'no declaration: condition omitted (not new)' );
$ok( is_array( $r ) && [] === KaliCart_Bridge_ACP_Feed::validate_row( $r ), 'row without condition is valid for the spec' );

$r = $row( $make( 'Declared used', 'Used' ) );
$ok( 'used' === ( $r['condition'] ?? null ), 'attribute Condition=Used -> used' );
$ok( [] === KaliCart_Bridge_ACP_Feed::validate_row( $r ), 'row with declared condition is valid' );

$r = $row( $make( 'Declared new', 'new' ) );
$ok( 'new' === ( $r['condition'] ?? null ), 'attribute condition=new -> new' );

$r = $row( $make( 'Non-standard value', 'kasutatud' ) );
$ok( ! array_key_exists( 'condition', $r ), 'non-standard value (kasutatud) -> omitted, no translation' );

$r = $row( $make( 'Mixed value', 'New, Used' ) );
$ok( ! array_key_exists( 'condition', $r ), 'ambiguous value (New, Used) -> omitted' );

$r = $row( $make( 'Meta refurbished', null, 'refurbished' ) );
$ok( 'refurbished' === ( $r['condition'] ?? null ), 'meta _condition=refurbished -> refurbished' );

$r = $row( $make( 'Attribute wins over meta', 'used', 'new' ) );
$ok( 'used' === ( $r['condition'] ?? null ), 'attribute takes precedence over meta' );

// Variation: declared on the parent only.
$parent = new WC_Product_Variable(); $parent->set_name( 'Variable used item' ); $parent->set_description( 'A variable product with a real description.' ); $parent->set_status( 'publish' ); $parent->set_image_id( $img );
$size = new WC_Product_Attribute(); $size->set_name( 'Size' ); $size->set_options( [ 'S', 'M' ] ); $size->set_visible( true ); $size->set_variation( true );
$parent->set_attributes( [ $size, $attr( 'used' ) ] ); $parent->save(); $brand( $parent->get_id() );
$v = new WC_Product_Variation(); $v->set_parent_id( $parent->get_id() ); $v->set_attributes( [ 'size' => 'S' ] ); $v->set_regular_price( '15' ); $v->set_status( 'publish' ); $v->save();
$r = $row( wc_get_product( $v->get_id() ), wc_get_product( $parent->get_id() ) );
$ok( 'used' === ( $r['condition'] ?? null ), 'variation inherits condition declared on the parent' );

// Source check: no hardcoded condition left in the feed generator.
$src = file_get_contents( WP_PLUGIN_DIR . '/kalicart-bridge/includes/class-acp-feed.php' );
$ok( false === strpos( $src, "'condition'            => 'new'" ), 'no hardcoded condition=new in the feed generator' );

echo $fails ? "FAILED $fails/$n\n" : "ALL $n PASSED\n";
