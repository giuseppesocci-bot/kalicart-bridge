<?php
/**
 * KaliCart Bridge 1.0.146 - NO_BRAND list, feed diagnostics samples, readiness row actions.
 *
 * Read-only on real data: no product, term or option is written. The feed is
 * generated with the private generate_inner() into a throw-away upload dir
 * (upload_dir filter); the wc-486 "price 0" fixture is an in-memory price
 * filter, never saved; last_stats for the panel render is injected with an
 * option_ read filter.
 *
 * Run:  wp eval-file wp-content/plugins/kalicart-bridge/tools/test-146-diagnostics.php
 * Opt.: KB146_OLD_QUARANTINE=/path/to/1.0.145/includes/class-quarantine.php
 *       (score / quarantine / catalog-health parity with 1.0.145 on the same data)
 */
defined( 'ABSPATH' ) || exit;
switch_to_locale( 'en_US' );

$fails = 0; $tests = 0;
$ok = static function ( bool $c, string $m ) use ( &$fails, &$tests ): void { $tests++; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fails++; } };
$call = static function ( string $cls, string $m, array $args = [] ) { $r = new ReflectionMethod( $cls, $m ); $r->setAccessible( true ); return $r->invokeArgs( null, $args ); };
global $wpdb;
$Q = 'KaliCart_Bridge_Quarantine';
$F = 'KaliCart_Bridge_ACP_Feed';
$users = get_users( [ 'role__in' => [ 'administrator' ], 'number' => 1, 'fields' => 'ID' ] );
if ( $users ) { wp_set_current_user( (int) $users[0] ); }

// ── 1. NO_BRAND: count == list total, every row satisfies the predicate ──────
$report = $call( $Q, 'build_report' );
$total  = (int) ( $report['issues']['no_brand'] ?? -1 );
$ids    = $report['issue_product_ids']['NO_BRAND'] ?? null;
$ok( is_array( $ids ), 'issue_product_ids has NO_BRAND' );
$ids = (array) $ids;
$all_brand = $Q::issue_ids( 'NO_BRAND' ); // unbounded, same SQL
$ok( $total === count( $all_brand ), "NO_BRAND count ($total) == unbounded id list total (" . count( $all_brand ) . ')' );
$ok( $ids === array_slice( $all_brand, 0, $Q::ISSUE_LIST_CAP ), 'report NO_BRAND ids = first 100 of the unbounded list (same order, recent first)' );
// 1.0.146 DB bound: every improvement list <= 100 while its true total may exceed it.
foreach ( [ 'NO_IMAGE' => 'no_image', 'NO_SKU' => 'no_sku', 'NO_BRAND' => 'no_brand' ] as $kb_code => $kb_key ) {
	$kb_list = $report['issue_product_ids'][ $kb_code ];
	$kb_all  = $Q::issue_ids( $kb_code );
	$ok( count( $kb_list ) <= 100 && count( $kb_list ) === min( 100, (int) $report['issues'][ $kb_key ] ) && count( $kb_all ) === (int) $report['issues'][ $kb_key ], "$kb_code: list " . count( $kb_list ) . ' <= 100, total ' . (int) $report['issues'][ $kb_key ] . ' from COUNT, unbounded query agrees' );
	// Stubbed cap (no fixture products needed): a cap of 3 keeps the first 3 while the total stays complete.
	$kb_small = $Q::issue_ids( $kb_code, 3 );
	$ok( count( $kb_small ) === min( 3, count( $kb_all ) ) && $kb_small === array_slice( $kb_all, 0, 3 ), "$kb_code: LIMIT is applied in SQL and keeps the deterministic order (cap 3 of " . count( $kb_all ) . ')' );
}
$ok( $total > 100 ? 100 === count( $ids ) : true, "real data: NO_BRAND total $total > 100 with a list of " . count( $ids ) );
$ok( count( $Q::issue_ids( 'NO_SKU' ) ) === (int) $report['issues']['no_sku'], 'native filter kalicart_missing=sku stays unbounded (all ' . (int) $report['issues']['no_sku'] . ' ids)' );
$sugg = array_values( array_filter( $report['suggestions'], static fn( $s ) => 'NO_BRAND' === ( $s['code'] ?? '' ) ) );
$ok( 0 === $total ? ! $sugg : ( isset( $sugg[0] ) && (int) $sugg[0]['affected'] === $total ), 'suggestion affected == list total' );
$list = $report['issue_products']['NO_BRAND'] ?? [];
$ok( count( $list ) === min( 100, $total ), 'NO_BRAND view = min(100, total) rows (' . count( $list ) . ')' );
$ok( 0 === $total || count( $list ) > 0, 'positive count => non-empty view, so the "no products" message cannot show' );
$taxes = "'product_brand','pwb-brand','pa_brand','pa_marca'";
$bad = 0;
foreach ( $list as $row ) {
	$id = (int) $row['id'];
	$n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy IN ($taxes)", $id ) ); // phpcs:ignore
	if ( 'publish' !== get_post_status( $id ) || 'product' !== get_post_type( $id ) || $n > 0 ) { $bad++; }
	if ( 'NO_BRAND' !== ( $row['flags'][0]['code'] ?? '' ) || admin_url( 'post.php?post=' . $id . '&action=edit' ) !== $row['url'] ) { $bad++; }
}
$ok( 0 === $bad, 'every listed NO_BRAND row: publish product, no brand term, NO_BRAND flag, edit link' );
if ( $all_brand ) {
	$in = implode( ',', array_map( 'intval', $all_brand ) );
	$viol = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN ($in) AND tt.taxonomy IN ($taxes)" ); // phpcs:ignore
	$nonpub = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ($in) AND NOT (post_type = 'product' AND post_status = 'publish')" ); // phpcs:ignore
	$ok( 0 === $viol && 0 === $nonpub, 'the full NO_BRAND id set satisfies the predicate (not only the first 100)' );
}
$q = new WP_Query( array_merge( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ], $call( $F, 'missing_data_query_args', [ 'brand' ] ) ) );
$ok( (int) $q->found_posts === $total, 'native Products filter kalicart_missing=brand total (' . (int) $q->found_posts . ') == NO_BRAND total' );
echo 'INFO NO_BRAND overlapping NO_IMAGE=' . count( array_intersect( $ids, $report['issue_product_ids']['NO_IMAGE'] ) ) . ' NO_SKU=' . count( array_intersect( $ids, $report['issue_product_ids']['NO_SKU'] ) ) . "\n";
$in_quar = 0;
foreach ( $report['quarantined_products'] as $qp ) { foreach ( $qp['flags'] ?? [] as $fl ) { if ( 'NO_BRAND' === $fl['code'] ) { $in_quar++; } } }
$ok( 0 === $in_quar, 'NO_BRAND never enters the quarantine list' );
$js = (string) file_get_contents( KALICART_BRIDGE_DIR . 'admin/assets/admin.js' );
$ok( 1 === preg_match( "/NO_BRAND:\\s*'no_brand'/", $js ) && false !== strpos( $js, 'why_brand_d' ), 'admin.js: ISSUE_COUNT_KEY.NO_BRAND -> no_brand, intro wired' );

$old = getenv( 'KB146_OLD_QUARANTINE' );
if ( $old && is_readable( $old ) ) {
	$src = preg_replace( '/^<\?php/', '', (string) file_get_contents( $old ) );
	$src = str_replace( 'class KaliCart_Bridge_Quarantine {', 'class KaliCart_Bridge_Quarantine_145 {', $src );
	eval( $src ); // phpcs:ignore Squiz.PHP.Eval -- test-only: load the 1.0.145 class side by side.
	$r145 = $call( 'KaliCart_Bridge_Quarantine_145', 'build_report' );
	foreach ( [ 'total_products', 'in_stock_count', 'out_of_stock_count', 'healthy_count', 'quarantine_count', 'average_score', 'suggestions', 'quarantined_products', 'out_of_stock_products' ] as $k ) {
		$ok( $r145[ $k ] == $report[ $k ], "unchanged vs 1.0.145: $k" ); // phpcs:ignore Universal.Operators.StrictComparisons
	}
	$ni = $report['issues']; unset( $ni['no_brand'] );
	$ok( $ni === $r145['issues'], 'issues unchanged vs 1.0.145 except the new no_brand' );
	$nl = $report['issue_product_ids']; unset( $nl['NO_BRAND'] );
	$ol = $r145['issue_product_ids'];
	$ol['NO_IMAGE'] = array_slice( $ol['NO_IMAGE'], 0, 100 ); $ol['NO_SKU'] = array_slice( $ol['NO_SKU'], 0, 100 );
	$ok( $nl === $ol, 'issue id lists unchanged vs 1.0.145 (NO_IMAGE/NO_SKU: same first 100)' );
	$np = $report['issue_products']; unset( $np['NO_BRAND'] );
	$ok( $np == $r145['issue_products'], 'NO_IMAGE / NO_SKU lists unchanged vs 1.0.145' ); // phpcs:ignore
} else {
	echo "SKIP 1.0.145 parity (set KB146_OLD_QUARANTINE)\n";
}

// ── 2. Feed samples from a throw-away generation ─────────────────────────────
// Generate in the SITE locale, like the cron does (variation labels are localised).
restore_previous_locale();
$opts      = $F::get_options();
$real_up   = wp_upload_dir();
$real_file = trailingslashit( $real_up['basedir'] ) . 'kalicart-bridge/' . $opts['token'] . '/acp-products.jsonl';
$tmp       = trailingslashit( sys_get_temp_dir() ) . 'kb146-' . wp_generate_password( 10, false );
$up_filter = static function ( $u ) use ( $tmp ) { $u['basedir'] = $tmp; return $u; }; // baseurl untouched: image URLs stay real
add_filter( 'upload_dir', $up_filter, 999 );
$tmp_file = $tmp . '/kalicart-bridge/' . $opts['token'] . '/acp-products.jsonl';
$rows_of = static function ( string $f ): array { $o = []; foreach ( file( $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $l ) { $r = json_decode( $l, true ); $o[ $r['item_id'] ] = $r; } return $o; };

$s1 = $call( $F, 'generate_inner' );
$ok( empty( $s1['error'] ) && is_file( $tmp_file ), 'throw-away generation ok (real feed file untouched)' );
$j1 = is_file( $tmp_file ) ? (string) file_get_contents( $tmp_file ) : '';
$r1 = $rows_of( $tmp_file );
$map = [ 'description_fallback' => 'fallback_description_rows', 'brand_fallback' => 'fallback_brand_rows', 'brand_excluded' => 'excluded_no_brand', 'image_excluded' => 'excluded_no_image', 'invalid' => 'excluded_invalid' ];
foreach ( $map as $reason => $counter ) {
	$n = count( $s1['samples'][ $reason ] ?? [] );
	$ok( $n === min( $F::SAMPLE_CAP, (int) $s1[ $counter ] ), "samples[$reason] = min(100, $counter=" . (int) $s1[ $counter ] . ") -> $n" );
}
$ser = strlen( maybe_serialize( $s1 ) );
$ok( $ser < 5 * $F::SAMPLE_CAP * 700 + 4096, "last_stats bounded: $ser bytes serialized" );
echo 'INFO counters rows=' . $s1['rows'] . ' products=' . $s1['products'] . ' brand_fallback=' . $s1['fallback_brand_rows'] . ' desc_fallback=' . $s1['fallback_description_rows'] . ' no_image=' . $s1['excluded_no_image'] . ' invalid=' . $s1['excluded_invalid'] . "\n";

$bad = 0;
foreach ( $s1['samples']['description_fallback'] as $s ) {
	$disp = wc_get_product( $s['parent_id'] ?: $s['product_id'] );
	$row  = $r1[ $s['item_id'] ] ?? null;
	if ( ! $row || ! $disp || $row['description'] !== trim( wp_strip_all_tags( $disp->get_name() ) ) || '' !== trim( wp_strip_all_tags( $disp->get_description() . $disp->get_short_description() ) ) ) { $bad++; }
}
$ok( 0 === $bad, 'each description_fallback sample is a file row whose description is the product name and the product has no description' );
$bad = 0;
foreach ( $s1['samples']['image_excluded'] as $s ) {
	$p = wc_get_product( $s['product_id'] ); $d = $s['parent_id'] ? wc_get_product( $s['parent_id'] ) : $p;
	if ( isset( $r1[ $s['item_id'] ] ) || ! $p || wp_get_attachment_image_url( $p->get_image_id() ?: $d->get_image_id(), 'full' ) ) { $bad++; }
}
$ok( 0 === $bad, 'each image_excluded sample has no image and is absent from the file' );
$bad = 0;
foreach ( $s1['samples']['brand_fallback'] as $s ) {
	$d = wc_get_product( $s['parent_id'] ?: $s['product_id'] );
	$row = $r1[ $s['item_id'] ] ?? null;
	if ( ! $d || '' !== $call( $F, 'resolve_brand', [ $d ] ) || ( $row && $row['brand'] !== trim( (string) $opts['brand_fallback'] ) ) ) { $bad++; }
}
$ok( 0 === $bad, 'each brand_fallback sample has no product brand and its file row carries the fallback' );
$fb_rows = 0;
foreach ( $r1 as $iid => $row ) { if ( $row['brand'] === trim( (string) $opts['brand_fallback'] ) ) { $d = wc_get_product( ! empty( $row['group_id'] ) ? (int) substr( $row['group_id'], 3 ) : (int) substr( $iid, 3 ) ); if ( $d && '' === $call( $F, 'resolve_brand', [ $d ] ) ) { $fb_rows++; } else { echo 'INFO fallback-brand row with a product brand? ' . $iid . "\n"; } } }
// 1.0.146: per-row counters count only rows WRITTEN to the file (brand fallback
// used to be counted before the empty-price exit and the validator).
$ok( $fb_rows === (int) $s1['fallback_brand_rows'], "fallback_brand_rows (" . (int) $s1['fallback_brand_rows'] . ") == file rows with the fallback brand from products without a brand term ($fb_rows)" );
$missing = array_filter( $s1['samples']['brand_fallback'], static fn( $s ) => ! isset( $r1[ $s['item_id'] ] ) );
$ok( ! $missing, 'every brand_fallback sample id is a row in the file' );
$missing = array_filter( $s1['samples']['description_fallback'], static fn( $s ) => ! isset( $r1[ $s['item_id'] ] ) );
$desc_rows = count( array_filter( $r1, static fn( $r ) => $r['description'] === $r['title'] ) );
$ok( ! $missing && (int) $s1['fallback_description_rows'] <= $desc_rows, 'every description_fallback sample id is a row in the file' );

$ok( is_readable( $real_file ) && $j1 === (string) file_get_contents( $real_file ), 'throw-away JSONL byte-identical to the live feed file (rows unchanged by 1.0.146)' );
if ( is_readable( $real_file ) && $j1 !== (string) file_get_contents( $real_file ) ) {
	$la = explode( "\n", (string) file_get_contents( $real_file ) ); $lb = explode( "\n", $j1 );
	echo 'INFO lines live=' . count( $la ) . ' throw-away=' . count( $lb ) . ' unique item_ids=' . count( $r1 ) . "\n";
	foreach ( $la as $i => $x ) { if ( ( $lb[ $i ] ?? null ) !== $x ) { $k = strspn( $x ^ (string) ( $lb[ $i ] ?? '' ), "\0" ); echo 'INFO first diff line ' . $i . "\n  live: " . substr( $x, max( 0, $k - 60 ), 140 ) . "\n  test: " . substr( (string) ( $lb[ $i ] ?? '' ), max( 0, $k - 60 ), 140 ) . "\n"; break; } }
}
$ok( isset( $r1['wc-5623'] ) && $r1['wc-5623']['description'] === $r1['wc-5623']['title'] && in_array( 'wc-5623', array_column( $s1['samples']['description_fallback'], 'item_id' ), true ), 'wc-5623 stays in the file with the name fallback and is named in the description samples' );

// variations resolve to the parent edit screen
$var_samples = [];
foreach ( $s1['samples'] as $list_s ) { foreach ( $list_s as $s ) { if ( $s['parent_id'] ) { $var_samples[] = $s; } } }
if ( ! $var_samples ) {
	$vid = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1" ); // phpcs:ignore
	if ( $vid ) {
		$v = wc_get_product( $vid ); $par = wc_get_product( $v->get_parent_id() );
		$st = [ 'samples' => [ 'image_excluded' => [] ] ];
		$call( $F, 'note_sample', [ &$st, 'image_excluded', $v, $par ] );
		$var_samples = $st['samples']['image_excluded'];
	}
}
$bad = 0;
foreach ( $var_samples as $s ) {
	if ( (int) wp_get_post_parent_id( $s['product_id'] ) !== (int) $s['parent_id'] || $F::sample_edit_url( $s ) !== admin_url( 'post.php?post=' . $s['parent_id'] . '&action=edit' ) . '#variable_product_options' ) { $bad++; }
}
$ok( $var_samples && 0 === $bad, 'variation samples (' . count( $var_samples ) . ') link to the parent edit screen, variation id kept' );

// ── 3. wc-486 with price 0 (in-memory fixture): excluded + named ─────────────
$zero = static function ( $v, $p ) { return 486 === (int) $p->get_id() ? '0' : $v; };
add_filter( 'woocommerce_product_get_regular_price', $zero, 999, 2 );
add_filter( 'woocommerce_product_get_price', $zero, 999, 2 );
$s2 = $call( $F, 'generate_inner' );
remove_filter( 'woocommerce_product_get_regular_price', $zero, 999 );
remove_filter( 'woocommerce_product_get_price', $zero, 999 );
$r2 = $rows_of( $tmp_file );
$inv = array_values( array_filter( $s2['samples']['invalid'], static fn( $s ) => 'wc-486' === $s['item_id'] ) );
$ok( ! isset( $r2['wc-486'] ) && (int) $s2['excluded_invalid'] === (int) $s1['excluded_invalid'] + 1, 'wc-486 at price 0 is excluded by the validator (counter +1)' );
$ok( $inv && false !== strpos( $inv[0]['errors'], 'price must be positive' ) && 486 === $inv[0]['product_id'] && 0 === $inv[0]['parent_id'] && '' !== $inv[0]['title'], 'wc-486 is named in the invalid samples with its reason' );
$fb486 = in_array( 'wc-486', array_column( $s1['samples']['brand_fallback'], 'item_id' ), true ) ? 1 : 0;
$ok( (int) $s2['fallback_brand_rows'] === (int) $s1['fallback_brand_rows'] - $fb486 && ! in_array( 'wc-486', array_column( $s2['samples']['brand_fallback'], 'item_id' ), true ), 'a row dropped by the validator is not counted as brand fallback (wc-486 at price 0: -' . $fb486 . ')' );
$r1_minus = $r1; unset( $r1_minus['wc-486'] );
$ok( $r2 === $r1_minus, 'all other rows unchanged by the fixture' );
remove_filter( 'upload_dir', $up_filter, 999 );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $fi ) { $fi->isDir() ? rmdir( $fi->getPathname() ) : unlink( $fi->getPathname() ); } // phpcs:ignore
rmdir( $tmp ); // phpcs:ignore

// ── 4. Panel render (last_stats injected via read filter, never saved) ───────
switch_to_locale( 'en_US' ); // assertions below target source copy
$render = static function ( array $stats ) use ( $F ): string {
	$inject = static function ( $v ) use ( $stats ) { $v = is_array( $v ) ? $v : []; $v['last_stats'] = $stats; return $v; };
	add_filter( 'option_' . $F::OPTION, $inject, 999 );
	ob_start(); $F::render_panel(); $h = (string) ob_get_clean();
	remove_filter( 'option_' . $F::OPTION, $inject, 999 );
	return $h;
};
$row_html = static function ( string $h, string $label ): string { $p = strpos( $h, '<strong>' . $label . '</strong>' ); if ( false === $p ) { return ''; } $st = strpos( $h, 'kali-acp-row__status', $p ); $e = false === $st ? false : strpos( $h, '</div></div>', $st ); return substr( $h, $p, false === $e ? 2000 : $e - $p ); }; // one row: label .. end of its status cell
$h2 = $render( $s2 );
$ok( false !== strpos( $h2, 'id="kb-acp-diag-invalid"' ) && false !== strpos( $h2, 'post=486&#038;action=edit' ), 'panel: invalid section links wc-486 to its edit screen' );
$sv = $row_html( $h2, 'Schema validation' );
$ok( false !== strpos( $sv, 'kali-acp-action' ) && false !== strpos( $sv, 'Fix' ) && false !== strpos( $sv, 'aria-hidden="true"' ), 'panel: "Schema validation" with invalid rows has a Fix action (icon + text)' );
$h1 = $render( $s1 );
$sv1 = $row_html( $h1, 'Schema validation' );
$ok( '' !== $sv1 && false === strpos( $sv1, 'kali-acp-action' ), 'panel: "Schema validation" passed -> no Fix action' );
$dr = $row_html( $h1, 'Product description' );
$ok( 1 !== (int) $s1['fallback_description_rows'] || 1 === preg_match( '/post=5623&(amp;|#038;)action=edit/', $dr ), 'panel: description Fix opens wc-5623 directly' );
$br = $row_html( $h1, 'Product brand' );
$ok( (int) $s1['fallback_brand_rows'] === 0 || ( false !== strpos( $br, 'Check brand' ) && false !== strpos( $br, '#kb-acp-diag-brand_fallback' ) && false === strpos( $br, '>Fix<' ) ), 'panel: brand fallback row says "Check brand" and points to the feed rows' );
$ir = $row_html( $h1, 'Primary image' );
$ok( (int) $s1['excluded_no_image'] === 0 || ( false !== strpos( $ir, 'Fix' ) && false !== strpos( $ir, 'Last feed generation:' ) ), 'panel: image row has Fix and declares the last-feed source' );
$ok( false !== strpos( $h1, 'current catalog data' ) && false !== strpos( $h1, 'kalicart_missing=image' ), 'panel: live filter linked separately and labelled "current catalog data"' );
$ok( false !== strpos( $row_html( $h1, 'OpenAI feed delivery' ), 'How to activate' ) && false !== strpos( $h1, 'id="kb-acp-guide"' ), 'panel: delivery row -> "How to activate" to the instructions' );
// Row colour states (1.0.146 UI decision): intervention vs ready/informative.
$row_open = static function ( string $h, string $label ): string { $p = strpos( $h, '<strong>' . $label . '</strong>' ); if ( false === $p ) { return ''; } $q = strrpos( substr( $h, 0, $p ), '<div class="kali-acp-row ' ); return false === $q ? '' : substr( $h, $q, 60 ); };
$ok( false !== strpos( $row_open( $h2, 'Schema validation' ), 'kali-acp-row--action' ) && false !== strpos( $row_open( $h1, 'Schema validation' ), 'kali-acp-row--ok' ), 'row state: schema invalid = action, passed = ok' );
$ok( 0 === (int) $s1['fallback_brand_rows'] || false !== strpos( $row_open( $h1, 'Product brand' ), 'kali-acp-row--check' ), 'row state: brand fallback = check (not an error)' );
$ok( false !== strpos( $row_open( $h1, 'OpenAI feed delivery' ), 'kali-acp-row--info' ) && false === strpos( $row_html( $h1, 'OpenAI feed delivery' ), 'kali-pill--warn' ), 'row state: OpenAI delivery is informative, not a defect' );
$old_stats = $s1; unset( $old_stats['samples'] );
$h0 = $render( $old_stats );
$ok( false !== strpos( $h0, 'The list will appear after the next feed generation.' ) && false === strpos( $h0, '<ul class="kali-acp-samples">' ), 'panel: pre-1.0.146 stats show counts and "list after next generation", never a live rebuild' );
$evil = $s1; $evil['samples']['description_fallback'] = [ [ 'item_id' => 'wc-1"><script>x()</script>', 'product_id' => 1, 'parent_id' => 0, 'title' => '<img src=x onerror=alert(1)>' ] ];
$hx = $render( $evil );
$ok( false === strpos( $hx, '<script>x()' ) && false === strpos( $hx, '<img src=x' ), 'panel: sample data is escaped' );

echo "\n" . ( $fails ? "FAILED $fails/$tests" : "ALL $tests PASSED" ) . "\n";
