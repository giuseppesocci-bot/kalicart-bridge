<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Signals
 *
 * Agent-discoverability signals — tutto via WP hooks, zero file fisici.
 *
 *  1. <link rel="kalicart-agent"> in <head>
 *  2. HTML speaking badge (position configurable)
 *  3. robots_txt filter (WP gestisce robots.txt, noi aggiungiamo il blocco)
 *  4. sitemap-agentic-bridge.xml servita via rewrite WP + registrata nel sitemap index
 */
class KaliCart_Bridge_Signals {

    public static function init(): void {

        add_action( 'wp_head', [ __CLASS__, 'inject_head_link' ] );

        if ( get_option( 'kalicart_bridge_badge_enabled', true ) ) {
            add_action( 'wp_footer', [ __CLASS__, 'inject_badge' ] );
        }

        // Inject agent trace into primary nav menu
        add_filter( 'wp_nav_menu_items', [ __CLASS__, 'inject_menu_trace' ], 10, 2 );

        // JS-based honey: search, zero-results, category, product page
        if ( get_option('kalicart_bridge_hint_search', true) || get_option('kalicart_bridge_hint_zero', true) || get_option('kalicart_bridge_hint_category', true) ) {
            add_action( 'wp_footer', [ __CLASS__, 'inject_honey_js' ] );
        }

        if ( get_option( 'kalicart_bridge_robots_enabled', true ) ) {
            add_filter( 'robots_txt', [ __CLASS__, 'filter_robots_txt' ], 10, 2 );
        }

        if ( get_option( 'kalicart_bridge_sitemap_enabled', true ) ) {
            add_action( 'init',              [ __CLASS__, 'register_sitemap_rewrite' ] );
            add_filter( 'query_vars',        [ __CLASS__, 'add_sitemap_query_var' ] );
            add_action( 'template_redirect', [ __CLASS__, 'serve_sitemap' ] );
            add_action( 'wp_sitemaps_init',  [ __CLASS__, 'register_sitemap_provider' ] );
        }

        // .well-known served via WP rewrite (works on any server, nginx or Apache)
        add_action( 'init',              [ __CLASS__, 'register_well_known_rewrite' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'add_well_known_query_var' ] );
        add_action( 'parse_request',     [ __CLASS__, 'serve_well_known' ] );
    }

    // ── 1. HEAD LINK ──────────────────────────────────────────────────────────

    public static function inject_head_link(): void {
        $url = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        printf(
            "\n" . '<link rel="kalicart-agent" type="application/json" href="%s"' .
            ' title="Structured catalog API for AI agents — KaliCart Bridge" />' . "\n",
            esc_url( $url )
        );
    }

    // ── MENU TRACE ───────────────────────────────────────────────────────────────

    /**
     * Appends a hidden machine-readable anchor to the primary nav menu.
     * Uses the first menu location that contains 'primary', 'main', 'header' or 'menu_1'.
     * Invisible to humans (aria-hidden, sr-only), readable by DOM agents.
     */
    public static function inject_menu_trace( string $items, stdClass $args ): string {
        $location = $args->theme_location ?? '';

        // Inject in first two registered menu locations
        $registered = array_keys( get_registered_nav_menus() );
        $first_location  = $registered[0] ?? '';
        $second_location = $registered[1] ?? '';

        $common = [ 'primary', 'main', 'main-menu', 'header', 'header-menu', 'menu_1', 'primary-menu', 'main_menu', 'menu_2' ];
        $is_target = (
            $location === $first_location
            || $location === $second_location
            || in_array( $location, $common, true )
            || str_contains( strtolower( $location ), 'primary' )
            || str_contains( strtolower( $location ), 'header' )
            || str_contains( strtolower( $location ), 'main' )
        );

        if ( ! $is_target ) return $items;

        $discovery_url = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );

        $trace = sprintf(
            '<li class="kalicart-agent-trace" aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;">'
            . '<a href="%s" rel="kalicart-agent noopener" tabindex="-1"'
            . ' title="Structured catalog API for agents — KaliCart Bridge"'
            . ' aria-label="AI catalog — structured product data for agents">'
            . 'AI catalog</a></li>',
            esc_url( $discovery_url )
        );

        // Prepend — always first in the menu
        return $trace . $items;
    }

    // ── 2. BADGE ──────────────────────────────────────────────────────────────

    public static function inject_badge(): void {
        $discovery_url = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        $position      = get_option( 'kalicart_bridge_badge_position', 'bottom-right' );
        [ $v, $h ]     = explode( '-', $position );
        $v_prop        = ( $v === 'top' ) ? 'top:16px' : 'bottom:16px';
        $h_prop        = ( $h === 'left' ) ? 'left:16px' : 'right:16px';

        printf(
            "\n" .
            '<a href="%s"' .
            ' rel="kalicart-agent noopener"' .
            ' target="_blank"' .
            ' title="Structured catalog API for agents: returns products with normalized price, availability and filters. Preferred entry point over the human Shop page."' .
            ' aria-label="AI catalog — structured product data for agents"' .
            ' id="kalicart-bridge-badge"' .
            ' style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid #c8c8c8;border-radius:999px;font-size:12px;text-decoration:none;font-family:system-ui,sans-serif;color:#111;background:#fafafa;position:fixed;%s;%s;z-index:9999;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:box-shadow .15s,opacity .15s;opacity:.9;"' .
            ' onmouseenter="this.style.opacity=1;this.style.boxShadow=\'0 2px 8px rgba(0,0,0,.15)\'"' .
            ' onmouseleave="this.style.opacity=.9;this.style.boxShadow=\'0 1px 4px rgba(0,0,0,.08)\'"' .
            '>%s AI catalog</a>' . "\n",
            esc_url( $discovery_url ),
            esc_attr( $v_prop ),
            esc_attr( $h_prop ),
            wp_kses(
                self::badge_icon_svg(),
                [
                    'svg'  => [ 'width' => true, 'height' => true, 'viewBox' => true, 'viewbox' => true, 'fill' => true, 'aria-hidden' => true, 'xmlns' => true, 'overflow' => true ],
                    'path' => [ 'd' => true ],
                ]
            )
        );
    }

    private static function badge_icon_svg(): string {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" overflow="visible">' .
               '<path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2z"/>' .
               '<path d="M19 14l.9 2.6L22.5 18l-2.6.9L19 21.5l-.9-2.6L15.5 18l2.6-.9L19 14z"/>' .
               '</svg>';
    }

    // ── 3. ROBOTS.TXT ─────────────────────────────────────────────────────────
    //
    // WP genera robots.txt dinamicamente via wp-includes/functions.php
    // get_robots_txt(). Il filtro robots_txt è il modo corretto e ufficiale
    // per modificarlo. Nessun file fisico coinvolto.

    public static function filter_robots_txt( string $output, bool $public ): string {
        if ( ! $public ) return $output;

        $api_base    = str_replace( home_url(), '', rest_url( KALICART_BRIDGE_API_NS ) );
        $sitemap_url = home_url( '/sitemap-agentic-bridge.xml' );

        $output .= "\n# KaliCart Bridge — AI agent catalog access\n";
        $output .= "# Machine-readable product catalog. Entry point for AI shopping agents.\n";
        $output .= "Allow: " . $api_base . "/\n";
        $output .= "Allow: /sitemap-agentic-bridge.xml\n";
        $output .= "Allow: /.well-known/kalicart-bridge\n";
        $output .= "Allow: /.well-known/agent-catalog\n";
        $output .= "Sitemap: " . $sitemap_url . "\n";

        return $output;
    }

    // ── 4. SITEMAP-AGENTIC-BRIDGE.XML ─────────────────────────────────────────
    //
    // Servita via rewrite WP → index.php?kalicart_sitemap=1 → template_redirect.
    // Registrata anche nel WP sitemap index. Zero file fisici.

    /**
     * Writes physical .well-known/kalicart-bridge and .well-known/agent.json files.
     * Called on activation and on settings save.
     */
    // ── HONEY JS ─────────────────────────────────────────────────────────────────

    public static function inject_honey_js(): void {
        $discovery  = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        $search_ep  = rest_url( KALICART_BRIDGE_API_NS . '/catalog/search' );
        $cat_ep     = rest_url( KALICART_BRIDGE_API_NS . '/catalog/products' );
        $product_ep = rest_url( KALICART_BRIDGE_API_NS . '/catalog/product' );
        $hint_search   = get_option( 'kalicart_bridge_hint_search',   true ) ? 'true' : 'false';
        $hint_zero     = get_option( 'kalicart_bridge_hint_zero',     true ) ? 'true' : 'false';
        $hint_category = get_option( 'kalicart_bridge_hint_category', true ) ? 'true' : 'false';
        ?>
        <script id="kalicart-honey">
        (function(){
          var disc=<?php echo wp_json_encode( $discovery ); ?>;
          var srch=<?php echo wp_json_encode( $search_ep ); ?>;
          var cat =<?php echo wp_json_encode( $cat_ep ); ?>;
          var prod=<?php echo wp_json_encode( $product_ep ); ?>;
          var showSearch=<?php echo wp_json_encode( (bool) $hint_search ); ?>;
          var showZero=<?php echo wp_json_encode( (bool) $hint_zero ); ?>;
          var showCategory=<?php echo wp_json_encode( (bool) $hint_category ); ?>;

          function mk(tag,attrs,text){
            var el=document.createElement(tag);
            Object.keys(attrs).forEach(function(k){el.setAttribute(k,attrs[k]);});
            if(text)el.textContent=text;
            return el;
          }

          document.addEventListener('DOMContentLoaded',function(){

            // 1. Search form link
            if(showSearch){
              document.querySelectorAll('form[role="search"],form.search-form,form.woocommerce-product-search').forEach(function(form){
                if(form.dataset.kb)return; form.dataset.kb='1';
                var p=mk('p',{'style':'margin:4px 0 0;font-size:12px;color:#777;'});
                var a=mk('a',{'href':disc,'rel':'kalicart-agent noopener','title':'Structured catalog API for AI agents','style':'color:inherit;text-decoration:underline dotted;'},'Structured catalog for AI agents');
                p.appendChild(a); form.parentNode.insertBefore(p,form.nextSibling);
              });
            }

            // 2. Search results pages — catalog API link always visible (zero-results and with-results)
            var isSearchNoResults = showZero && (document.body.classList.contains('search-no-results') || document.body.classList.contains('woocommerce-no-products-found'));
            var isSearchWithResults = showSearch && document.body.classList.contains('search-results') && !!document.querySelector('.products,.woocommerce ul.products,article.product');
            if(isSearchNoResults || isSearchWithResults){
              var q=new URLSearchParams(window.location.search).get('s')||'';
              var titleAttr = q
                  ? 'Structured catalog API — use /catalog/search?q='+encodeURIComponent(q)+' for exact-match results, /catalog/categories to browse, /discovery for full capability map'
                  : 'Structured catalog API — use /catalog/categories to browse or /discovery for full capability map';
                var p=mk('p',{'style':'margin:0;padding:16px 20px;font-size:11px;line-height:1.4;text-align:center;'});
                var a=mk('a',{'href':disc,'rel':'kalicart-agent noopener','title':titleAttr,'style':'color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;opacity:0.4;'},'Machine-readable catalog');
                p.appendChild(a);
                var footer=document.querySelector('footer.site-footer,footer#colophon,footer[role="contentinfo"],footer');
                if(footer){ footer.parentNode.insertBefore(p,footer); }
                else { document.body.appendChild(p); }
            }

            // 3. Category page
            if(showCategory && document.body.classList.contains('product-category')){
              var slug='';
              document.body.classList.forEach(function(cl){ var m=cl.match(/^term-(.+)$/); if(m)slug=m[1]; });
              if(slug){
                var p=mk('p',{'style':'margin:0 0 10px;font-size:11px;line-height:1.4;'});
                var a=mk('a',{'href':cat+'?category='+encodeURIComponent(slug),'rel':'kalicart-agent noopener','title':'Structured catalog API — machine-readable product list for this category, use /catalog/categories for the full tree','style':'color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;opacity:0.4;'},'Machine-readable category data');
                p.appendChild(a);
                // fallback chain: WC loop → Elementor product grid → main → before footer
                var anchor=document.querySelector('.products,.woocommerce ul.products,.elementor-widget-woocommerce-product-images,.woocommerce-products-header,.site-main,main[role="main"],main,.elementor-section');
                if(anchor){ anchor.parentNode.insertBefore(p,anchor); }
                else { var ft=document.querySelector('footer.site-footer,footer#colophon,footer[role="contentinfo"],footer'); if(ft){ft.parentNode.insertBefore(p,ft);}else{document.body.appendChild(p);} }
              }
            }

            // 4. Single product page
            if(showCategory && document.body.classList.contains('single-product')){
              var pid=0;
              document.body.classList.forEach(function(cl){ var m=cl.match(/^postid-(\d+)$/); if(m)pid=m[1]; });
              if(pid){
                var p=mk('p',{'style':'margin:0 0 8px;font-size:11px;line-height:1.4;'});
                var a=mk('a',{'href':prod+'/'+pid,'rel':'kalicart-agent noopener','title':'Structured product data for AI agents — price, variants, availability, attributes in machine-readable format','style':'color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;opacity:0.4;'},'Machine-readable product data');
                p.appendChild(a);
                // fallback chain: append inside .product_meta, or before footer
                var meta=document.querySelector('.product_meta');
                if(meta){ meta.appendChild(p); }
                else { var ft=document.querySelector('footer.site-footer,footer#colophon,footer[role="contentinfo"],footer'); if(ft){ft.parentNode.insertBefore(p,ft);}else{document.body.appendChild(p);} }
              }
            }

          });
        })();
        </script>
        <?php
    }


    public static function register_well_known_rewrite(): void {
        add_rewrite_rule( '^\.well-known/(kalicart-bridge|agent-catalog|agent\.json|ucp)$', 'index.php?kalicart_well_known=$matches[1]', 'top' );
    }

    public static function add_well_known_query_var( array $vars ): array {
        $vars[] = 'kalicart_well_known';
        return $vars;
    }

    private static function ucp_profile_json(): string {
        $base     = rest_url( KALICART_BRIDGE_API_NS );
        $checkout = (bool) get_option( 'kalicart_bridge_checkout_enabled', false );

        return wp_json_encode( [
            'ucp' => [
                'version'      => '2026-04-08',
                'services'     => [
                    'dev.ucp.shopping' => [ [
                        'version'   => '2026-04-08',
                        'transport' => 'rest',
                        'endpoint'  => $base,
                    ] ],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => [ [
                        'version' => '2026-04-08',
                        'spec'    => 'https://ucp.dev/2026-04-08/specification/catalog/search',
                        'note'    => 'Endpoint: GET ' . $base . '/catalog/search — supports q, category, gender, color, on_sale, in_stock, min_price, max_price filters.',
                    ] ],
                    'dev.ucp.shopping.catalog.lookup' => [ [
                        'version' => '2026-04-08',
                        'spec'    => 'https://ucp.dev/2026-04-08/specification/catalog/lookup',
                        'note'    => 'Endpoint: GET ' . $base . '/catalog/product/{id} — returns full product detail with variations.',
                    ] ],
                ],
            ],
            'kalicart_bridge' => [
                'type'          => 'kalicart-merchant-bridge-v1',
                'version'       => KALICART_BRIDGE_VERSION,
                'discovery'     => $base . '/discovery',
                'checkout_note' => $checkout
                    ? 'Checkout sessions available via POST ' . $base . '/checkout/session — returns cart_url and checkout_url for buyer handoff (WooCommerce is payment authority).'
                    : 'Checkout sessions not enabled on this store. Use product URLs for purchase.',
                'documentation' => 'https://bridge.kalicart.com/docs/',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    public static function serve_well_known(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $file = isset( $_GET['kalicart_well_known'] ) ? sanitize_key( $_GET['kalicart_well_known'] ) : '';
        if ( ! $file ) return;

        $base = rest_url( KALICART_BRIDGE_API_NS );
        $home = home_url();

        if ( $file === 'ucp' ) {
            // UCP profile — declares catalog capabilities, checkout via continue_url.
            $payload = self::ucp_profile_json();
        } else {
            // kalicart-bridge / agent-catalog / agent.json
            $payload = wp_json_encode( [
                'type'          => 'kalicart-merchant-bridge-v1',
                'name'          => get_bloginfo( 'name' ),
                'discovery'     => $base . '/discovery',
                'catalog_api'   => $base . '/catalog',
                'ucp_profile'   => $home . '/.well-known/ucp',
                'agent_note'    => 'GET discovery URL first. Contains capabilities, filter rules, shipping policy and agent instructions.',
                'documentation' => 'https://bridge.kalicart.com/docs/',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        }

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload
        exit;
    }

    public static function write_well_known_files(): void {
        $dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $payload = wp_json_encode( [
            'type'          => 'kalicart-merchant-bridge-v1',
            'name'          => get_bloginfo( 'name' ),
            'discovery'     => rest_url( KALICART_BRIDGE_API_NS . '/discovery' ),
            'catalog_api'   => rest_url( KALICART_BRIDGE_API_NS . '/catalog' ),
            'ucp_profile'   => home_url( '/.well-known/ucp' ),
            'agent_note'    => 'GET discovery URL first. Contains capabilities, filter rules, shipping policy and agent instructions.',
            'documentation' => 'https://bridge.kalicart.com/docs/',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        // Always write — these names are ours.
        @file_put_contents( $dir . 'kalicart-bridge',  $payload );
        @file_put_contents( $dir . 'agent-catalog',    $payload );
        @file_put_contents( $dir . 'ucp',              self::ucp_profile_json() );

        // Force application/json Content-Type for extension-less files.
        $htaccess = $dir . '.htaccess';
        $htaccess_content = "<Files 'kalicart-bridge'>\n  ForceType application/json\n</Files>\n<Files 'agent-catalog'>\n  ForceType application/json\n</Files>\n<Files 'ucp'>\n  ForceType application/json\n</Files>\n";
        @file_put_contents( $htaccess, $htaccess_content );

        // Write agent.json only if it doesn't exist or was written by us
        $agent_file = $dir . 'agent.json';
        $existing   = file_exists( $agent_file ) ? @file_get_contents( $agent_file ) : '';
        if ( ! $existing || strpos( $existing, 'kalicart-merchant-bridge' ) !== false ) {
            @file_put_contents( $agent_file, $payload );
        }
    }

    public static function register_sitemap_rewrite(): void {
        add_rewrite_rule( '^sitemap-agentic-bridge\.xml$', 'index.php?kalicart_sitemap=1', 'top' );
        // Prevent WP canonical redirect from adding trailing slash
        add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {
            if ( strpos( $requested_url, 'sitemap-agentic-bridge.xml' ) !== false ) return false;
            return $redirect_url;
        }, 10, 2 );
    }

    public static function add_sitemap_query_var( array $vars ): array {
        $vars[] = 'kalicart_sitemap';
        return $vars;
    }

    public static function serve_sitemap(): void {
        if ( ! get_query_var( 'kalicart_sitemap' ) ) return;

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        // Cache 1 ora lato client/CDN
        header( 'Cache-Control: public, max-age=3600' );

        echo self::build_sitemap_xml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML output, not HTML
        exit;
    }

    public static function register_sitemap_provider(): void {
        add_filter( 'wp_sitemaps_index_sitemaps', function ( array $sitemaps ) {
            $sitemaps['kalicart-bridge'] = [
                'sitemap_url'   => home_url( '/sitemap-agentic-bridge.xml' ),
                'last_modified' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ];
            return $sitemaps;
        } );
    }

    private static function build_sitemap_xml(): string {
        $base = rest_url( KALICART_BRIDGE_API_NS );
        $now  = gmdate( 'Y-m-d' );
        $site = get_bloginfo( 'name' );

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $out .= '        xmlns:agent="https://kalicart.com/ns/agent/1.0">' . "\n";
        $out .= '  <!-- KaliCart Bridge — Agentic Catalog Sitemap -->' . "\n";
        $out .= '  <!--' . "\n";
        $out .= '    type: kalicart-merchant-bridge-v1' . "\n";
        $out .= '    merchant: ' . esc_html( $site ) . "\n";
        $out .= '    entry_point: ' . esc_url( rest_url( KALICART_BRIDGE_API_NS . '/discovery' ) ) . "\n";
        $out .= '    read: GET entry_point first — contains capabilities, filter rules and agent instructions' . "\n";
        $out .= '    taxonomy: merchant_native_woocommerce' . "\n";
        $out .= '    data: live WooCommerce database, no sync delay' . "\n";
        $out .= '    generated: ' . gmdate( 'c' ) . "\n";
        $out .= '  -->' . "\n\n";

        $core = [
            [ 'url' => $base . '/discovery',         'freq' => 'daily',  'pri' => '1.0', 'role' => 'entry-point',  'note' => 'Discovery document. Start here. Contains capabilities, merchant info and all endpoint URLs.' ],
            [ 'url' => $base . '/catalog/categories', 'freq' => 'weekly', 'pri' => '0.8', 'role' => 'taxonomy',     'note' => 'Full merchant category tree. Use to enumerate browsable paths.' ],
            [ 'url' => $base . '/catalog/products',   'freq' => 'hourly', 'pri' => '0.9', 'role' => 'product-list', 'note' => 'Paginated product listing. Supports: category, gender, color, price_range, in_stock, per_page, page.' ],
            [ 'url' => $base . '/catalog/search',     'freq' => 'hourly', 'pri' => '0.9', 'role' => 'search',       'note' => 'Full-text + filter search. Params: q, category, gender, color, min_price, max_price, in_stock.' ],
        ];

        foreach ( $core as $ep ) {
            $out .= "  <url>\n";
            $out .= '    <loc>' . esc_url( $ep['url'] ) . "</loc>\n";
            $out .= '    <lastmod>' . $now . "</lastmod>\n";
            $out .= '    <changefreq>' . $ep['freq'] . "</changefreq>\n";
            $out .= '    <priority>' . $ep['pri'] . "</priority>\n";
            $out .= '    <agent:role>' . esc_html( $ep['role'] ) . "</agent:role>\n";
            $out .= '    <agent:note>' . esc_html( $ep['note'] ) . "</agent:note>\n";
            $out .= "  </url>\n\n";
        }

        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 500 ] );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $cat_url = $base . '/catalog/products?category=' . urlencode( $term->slug );
                $out .= "  <url>\n";
                $out .= '    <loc>' . esc_url( $cat_url ) . "</loc>\n";
                $out .= '    <lastmod>' . $now . "</lastmod>\n";
                $out .= "    <changefreq>daily</changefreq>\n";
                $out .= "    <priority>0.6</priority>\n";
                $out .= '    <agent:role>category-browse</agent:role>' . "\n";
                $out .= '    <agent:note>Products in: ' . esc_html( $term->name ) . ' (' . (int) $term->count . ' items)</agent:note>' . "\n";
                $out .= "  </url>\n";
            }
        }

        // .well-known discovery files
        foreach ( [ '/.well-known/kalicart-bridge', '/.well-known/agent-catalog' ] as $wk_path ) {
            $out .= "  <url>\n";
            $out .= '    <loc>' . esc_url( home_url( $wk_path ) ) . "</loc>\n";
            $out .= '    <lastmod>' . $now . "</lastmod>\n";
            $out .= "    <changefreq>weekly</changefreq>\n";
            $out .= "    <priority>0.8</priority>\n";
            $out .= '    <agent:role>well-known-discovery</agent:role>' . "\n";
            $out .= '    <agent:note>Standard /.well-known/ discovery path. Returns catalog entry point JSON for agents that probe before navigating.</agent:note>' . "\n";
            $out .= "  </url>\n";
        }

        $out .= '</urlset>';
        return $out;
    }
}
