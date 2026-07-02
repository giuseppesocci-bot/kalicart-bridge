<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Catalog_Engine
 *
 * Pure computational normalisation of WooCommerce data.
 * No LLM. No external service. Merchant taxonomy preserved.
 */
class KaliCart_Bridge_Catalog_Engine {

    // ── Static lookup tables ────────────────────────────────────────────────

    const GENDER_KEYWORDS = [
        'male'    => [ 'uomo', 'uomini', 'man', 'men', 'male', 'homme', 'herren', 'hombre' ],
        'female'  => [ 'donna', 'donne', 'woman', 'women', 'female', 'femme', 'damen', 'mujer' ],
        'unisex'  => [ 'unisex', 'neutro', 'neutral', 'mixte', 'gemischt' ],
        'kids'    => [ 'bambino', 'bambina', 'bambini', 'kid', 'kids', 'child', 'children', 'enfant', 'kinder', 'niño', 'niña' ],
    ];

    const COLOR_FAMILIES = [
        'red'    => [ 'red', 'rosso', 'rouge', 'rojo', 'rot', 'crimson', 'scarlet', 'bordeaux', 'burgundy', 'maroon', 'coral', 'salmon', 'brick' ],
        'blue'   => [ 'blue', 'blu', 'bleu', 'azul', 'blau', 'navy', 'cobalt', 'azure', 'sky', 'indigo', 'royal', 'denim', 'celeste', 'azzurro', 'teal', 'turchese', 'turquoise', 'cyan', 'aqua', 'petrolio', 'petrol' ],
        'green'  => [ 'green', 'verde', 'vert', 'grün', 'olive', 'khaki', 'mint', 'sage', 'forest', 'lime', 'military', 'militare', 'camouflage', 'camo' ],
        'black'  => [ 'black', 'nero', 'noir', 'negro', 'schwarz', 'onyx', 'jet' ],
        'white'  => [ 'white', 'bianco', 'blanc', 'blanco', 'weiß', 'ivory', 'cream', 'off-white', 'off white', 'panna' ],
        'grey'   => [ 'grey', 'gray', 'grigio', 'gris', 'grau', 'silver', 'argento', 'anthracite', 'antracite', 'charcoal', 'slate' ],
        'brown'  => [ 'brown', 'marrone', 'brun', 'marrón', 'braun', 'camel', 'caramel', 'tan', 'beige', 'sand', 'sabbia', 'nude', 'taupe', 'chocolate', 'tobacco', 'cognac', 'rust', 'ruggine', 'cotto', 'terra' ],
        'yellow' => [ 'yellow', 'giallo', 'jaune', 'amarillo', 'gelb', 'gold', 'oro', 'mustard', 'senape', 'amber', 'lemon' ],
        'orange' => [ 'orange', 'arancione', 'arancio', 'naranja', 'apricot', 'albicocca', 'peach', 'pesca', 'tangerine' ],
        'pink'   => [ 'pink', 'rosa', 'rose', 'fuchsia', 'magenta', 'blush', 'flamingo', 'cipria', 'powder' ],
        'purple' => [ 'purple', 'viola', 'lilla', 'violet', 'violette', 'violett', 'lilac', 'lavender', 'lavanda', 'plum', 'prugna', 'mauve', 'wisteria' ],
        'multi'  => [ 'multicolor', 'multicolore', 'fantasia', 'stampa', 'print', 'pattern', 'floral', 'fiori', 'stripes', 'righe', 'check', 'scacchi', 'pois', 'dots', 'animal', 'animalier' ],
    ];

    const SIZE_TYPE_CLOTHING = [ 'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', '2xl', '3xl', '4xl', 'one size', 'taglia unica', 'tu' ];
    const SIZE_TYPE_NUMERIC  = [ '34','36','38','40','42','44','46','48','50','52','54','56','58','60' ];
    const SIZE_TYPE_SHOES    = [ '35','35.5','36','36.5','37','37.5','38','38.5','39','39.5','40','40.5','41','41.5','42','42.5','43','43.5','44','44.5','45','45.5','46','47','48' ];

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Query products with normalisation. Returns array of normalized product data.
     *
     * @param array $args {
     *   search     string
     *   category   int|string   (term_id or slug)
     *   per_page   int
     *   page       int
     *   orderby    string  (date|price|title|popularity)
     *   order      string  (ASC|DESC)
     *   in_stock   bool
     *   min_price  float
     *   max_price  float
     *   gender     string  (male|female|unisex|kids)
     *   color      string  (color family key)
     * }
     */
    public static function query_products( array $args = [] ): array {
        $defaults = [
            'search'    => '',
            'category'  => '',
            'per_page'  => 20,
            'page'      => 1,
            'orderby'   => 'date',
            'order'     => 'DESC',
            'in_stock'  => null,
            'on_sale'   => null,
            'min_price' => null,
            'max_price' => null,
            'gender'    => '',
            'color'     => '',
            'size'      => '',
            'modified_after' => '',
            'fields'    => 'full',
        ];
        $args = wp_parse_args( $args, $defaults );

        // Determine if PHP post-filters are active. When yes, WP_Query must collect all
        // matching candidates (posts_per_page=-1) so slicing is done on the full filtered set.
        $has_php_postfilter = ! empty( $args['gender'] ) || ! empty( $args['color'] )
                              || ! empty( $args['size'] )
                              || $args['on_sale'] === true
                              || $args['min_price'] !== null || $args['max_price'] !== null;

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $has_php_postfilter ? -1 : (int) $args['per_page'],
            'paged'          => $has_php_postfilter ? 1  : (int) $args['page'],
            'orderby'        => self::map_orderby( $args['orderby'] ),
            'order'          => strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC',
        ];

        // B2: orderby=price requires meta_key and must exclude products with no price
        if ( $args['orderby'] === 'price' ) {
            $query_args['meta_key'] = '_price';
            $query_args['meta_query'][] = [
                'key'     => '_price',
                'value'   => '',
                'compare' => '!=',
            ];
        }

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        if ( ! empty( $args['category'] ) ) {
            $query_args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => is_numeric( $args['category'] ) ? 'term_id' : 'slug',
                'terms'    => $args['category'],
                'include_children' => true,
            ];
        }

        if ( $args['in_stock'] === true ) {
            $query_args['meta_query'][] = [
                'key'   => '_stock_status',
                'value' => 'instock',
            ];
        }

        if ( $args['min_price'] !== null || $args['max_price'] !== null ) {
            if ( $args['min_price'] !== null ) {
                $query_args['meta_query'][] = [
                    'key'     => '_price',
                    'value'   => (float) $args['min_price'],
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ];
            }
            if ( $args['max_price'] !== null ) {
                $query_args['meta_query'][] = [
                    'key'     => '_price',
                    'value'   => (float) $args['max_price'],
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ];
            }
        }

        if ( $args['on_sale'] === true ) {
            $sale_ids = wc_get_product_ids_on_sale();
            if ( ! empty( $sale_ids ) ) {
                $query_args['post__in'] = empty( $query_args['post__in'] )
                    ? $sale_ids
                    : array_intersect( $query_args['post__in'], $sale_ids );
            } else {
                $query_args['post__in'] = [ 0 ]; // no sale products
            }
        }

        if ( isset( $query_args['meta_query'] ) && count( $query_args['meta_query'] ) > 1 ) {
            $query_args['meta_query']['relation'] = 'AND';
        }

        // Incremental sync cursor: restrict to products modified at/after the given
        // GMT timestamp. Enables federated indexers to pull only the delta.
        if ( ! empty( $args['modified_after'] ) ) {
            $query_args['date_query'] = [
                [
                    'column'    => 'post_modified_gmt',
                    'after'     => $args['modified_after'],
                    'inclusive' => true,
                ],
            ];
        }

        // Multilingual: pin enumeration to the site default language so translated
        // products are not enumerated as duplicates. No-op on monolingual sites.
        $default_lang = KaliCart_Bridge_API::default_language();
        if ( $default_lang !== null ) {
            $query_args['lang'] = $default_lang; // Polylang reads this; harmless otherwise
        }

        $query    = new WP_Query( $query_args );
        $products = [];

        // fields=summary: slim projection for agent triage. With no PHP post-filter the
        // summary context short-circuits the heavy per-product work entirely. With a
        // post-filter active the full normalize is required to evaluate it (gender/colors/
        // sizes/on_sale), so survivors are re-emitted through the summary context below.
        $want_summary = ( $args['fields'] ?? 'full' ) === 'summary';

        foreach ( $query->posts as $post ) {
            $wc_product = wc_get_product( $post->ID );
            if ( ! $wc_product ) continue;

            if ( $want_summary && ! $has_php_postfilter ) {
                $products[] = self::normalize_product( $wc_product, 'summary' );
                continue;
            }

            $normalized = self::normalize_product( $wc_product );

            // Post-filter by gender/color (computed fields, not storable as meta easily)
            if ( ! empty( $args['gender'] ) && $normalized['gender'] !== $args['gender'] && $normalized['gender'] !== null ) {
                continue;
            }
            if ( ! empty( $args['color'] ) ) {
                $color_families = array_column( $normalized['colors'], 'family' );
                if ( ! in_array( $args['color'], $color_families, true ) ) {
                    continue;
                }
            }

            // Post-filter size: soft filter — returns products where at least one attribute value
            // matches the requested size string (case-insensitive). Applied after search.
            if ( ! empty( $args['size'] ) ) {
                $size_needle = strtolower( trim( $args['size'] ) );
                $has_size = false;
                foreach ( $normalized['sizes']['values'] ?? [] as $sv ) {
                    if ( strtolower( trim( $sv ) ) === $size_needle ) {
                        $has_size = true;
                        break;
                    }
                }
                if ( ! $has_size ) continue;
            }

            // Post-filter on_sale: wc_get_product_ids_on_sale() does not apply the Bridge
            // 1% discount threshold — exclude products where compute_price() set on_sale=false.
            if ( $args['on_sale'] === true && ! ( $normalized['price']['on_sale'] ?? false ) ) {
                continue;
            }

            // Post-filter price for variable products: WooCommerce _price meta on the parent
            // may be stale (legacy from before product was converted to variable). Use the
            // authoritative price.current computed from get_variation_prices() instead.
            if ( $wc_product->is_type( 'variable' ) ) {
                $current = $normalized['price']['current'] ?? null;
                if ( $args['min_price'] !== null && ( $current === null || $current < (float) $args['min_price'] ) ) {
                    continue;
                }
                if ( $args['max_price'] !== null && ( $current === null || $current > (float) $args['max_price'] ) ) {
                    continue;
                }
            }

            $products[] = $want_summary
                ? self::normalize_product( $wc_product, 'summary' )
                : $normalized;
        }

        // B3 + PAGINATION: with post-filters active, $products is the full filtered set
        // (WP_Query was run with posts_per_page=-1 when post-filters are active — see below).
        // Slice to the requested page/per_page here.
        $has_postfilter = ! empty( $args['gender'] ) || ! empty( $args['color'] )
                          || ! empty( $args['size'] )
                          || $args['on_sale'] === true
                          || ( $args['min_price'] !== null || $args['max_price'] !== null );

        if ( $has_postfilter ) {
            $filtered_total = count( $products );
            $per_page       = (int) $args['per_page'];
            $page           = max( 1, (int) $args['page'] );
            $offset         = ( $page - 1 ) * $per_page;
            $total_pages    = $per_page > 0 ? (int) ceil( $filtered_total / $per_page ) : 1;
            $products       = array_slice( $products, $offset, $per_page );

            return [
                'products'    => $products,
                'total'       => $filtered_total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => $total_pages,
            ];
        }

        return [
            'products'    => $products,
            'total'       => (int) $query->found_posts,
            'page'        => (int) $args['page'],
            'per_page'    => (int) $args['per_page'],
            'total_pages' => (int) $query->max_num_pages,
        ];
    }

    /**
     * Normalize a single WC_Product into agent-ready array.
     */
    public static function normalize_product( WC_Product $p, string $context = 'list' ): array {
        // Summary projection: slim listing for agent triage. Returns ONLY the fields an
        // agent needs to shortlist candidates; open /catalog/product/{id} for the few that
        // matter. Short-circuits BEFORE the heavy per-product work (attribute terms, images,
        // gender/color inference, quarantine, purchase_readiness, shipping, variants).
        if ( 'summary' === $context ) {
            $price      = self::compute_price( $p );
            $cat_terms  = get_the_terms( $p->get_id(), 'product_cat' );
            $categories = ( $cat_terms && ! is_wp_error( $cat_terms ) )
                ? array_values( wp_list_pluck( $cat_terms, 'slug' ) )
                : [];

            return [
                'id'         => $p->get_id(),
                'sku'        => $p->get_sku() ?: null,
                'name'       => $p->get_name(),
                'brand'      => self::resolve_brand( $p ),
                'url'        => get_permalink( $p->get_id() ),
                'price'      => [
                    'current' => $price['current'] ?? null,
                    'display' => $price['display'] ?? null,
                    'regular' => $price['regular'] ?? $price['min_regular'] ?? null,
                    'on_sale' => (bool) ( $price['on_sale'] ?? false ),
                ],
                'stock'      => [ 'in_stock' => $p->is_in_stock() ],
                'categories' => $categories,
                'type'       => $p->get_type(),
                'selection_required' => $p->is_type( 'variable' ),
                'updated_at' => $p->get_date_modified() ? $p->get_date_modified()->date( 'c' ) : null,
            ];
        }

        // ── Compute once, reuse everywhere ───────────────────────────────────
        $id                 = $p->get_id();
        $type               = $p->get_type();
        $price_data         = self::compute_price( $p );
        $categories         = self::get_product_categories( $p );
        $tags               = self::get_product_tags( $p );
        $attributes         = self::get_normalized_attributes( $p );
        $images             = self::get_images( $p );
        $gender             = self::infer_gender( $p, $categories, $tags, $attributes );
        $colors             = self::extract_colors( $attributes, $p->get_name(), $tags );
        $sizes              = self::extract_sizes( $attributes );
        $quarantine         = self::compute_quarantine_flags( $p, $categories, $images );
        $stock              = self::compute_stock( $p );           // computed once
        $purchase_readiness = self::compute_purchase_readiness( $p ); // computed once
        $barcodes           = self::get_barcodes( $p );            // computed once
        // variations only in detail context — avoids N×get_variations() queries in list/search
        $variations = ( $context === 'detail' && $type === 'variable' ) ? self::get_variations( $p ) : null;

        // variants[] — detail: full list; list: lightweight single entry for simple, empty array for variable (UCP: always an array)
        if ( $context === 'detail' ) {
            $variants = $type === 'variable'
                ? $variations
                : [ [
                    'variation_id'        => $id,
                    'attributes'          => [],
                    'price'               => $price_data,
                    'in_stock'            => $p->is_in_stock(),
                    'availability_status' => $p->is_in_stock() ? 'in_stock' : 'out_of_stock',
                    'sku'                 => $p->get_sku() ?: null,
                    'barcodes'            => $barcodes,
                ] ];
        } else {
            // list context: simple products get single variant, variable products get [] (use /product/{id} for full variants)
            $variants = $type !== 'variable'
                ? [ [
                    'variation_id'        => $id,
                    'attributes'          => [],
                    'price'               => $price_data,
                    'in_stock'            => $p->is_in_stock(),
                    'availability_status' => $p->is_in_stock() ? 'in_stock' : 'out_of_stock',
                    'sku'                 => $p->get_sku() ?: null,
                    'barcodes'            => $barcodes,
                ] ]
                : [];

            // Reinforce guidance for variable products in list/search context:
            // attributes lists possible options; per-variant stock requires product detail.
            if ( $type === 'variable' ) {
                $attr_names = array_column( $attributes, 'name' );
                $purchase_readiness['variant_options_note'] = sprintf(
                    'attributes lists possible options (%s). Per-variant price and stock require product detail: /catalog/product/%d',
                    implode( ', ', $attr_names ),
                    $id
                );

                // variation_summary: lightweight signal for agents to decide if detail call is needed.
                // Uses get_variation_prices() which is already called by compute_price() — cache hit, no extra query.
                // in_stock_variations_count is a proxy (variants with a price): exact per-variant stock is in detail.
                $vp = $p->get_variation_prices( true );
                $vp_prices = array_filter( $vp['price'] ?? [], fn( $v ) => $v !== '' && (float) $v > 0 );
                $total_variations       = count( $p->get_children() );
                $in_stock_variations    = count( $vp_prices );
                $cheapest_price         = ! empty( $vp_prices ) ? (float) min( $vp_prices ) : null;
                $variation_summary = [
                    'total_variations'       => $total_variations,
                    'in_stock_variations_count' => $in_stock_variations,
                    'cheapest_available_price'  => $cheapest_price,
                    'note' => 'in_stock_variations_count is a proxy (variants with a price). Exact per-variant stock and cheapest_available_variant require product detail.',
                ];
            }
        }

        return [
            'id'              => $id,
            'sku'             => $p->get_sku() ?: null,
            'type'            => $type,
            'name'            => $p->get_name(),
            'brand'           => self::resolve_brand( $p ),
            'slug'            => $p->get_slug(),
            'url'             => get_permalink( $id ),
            'status'          => $p->get_status(),
            'description'     => wp_strip_all_tags( $p->get_description() ) ?: null,
            'short_description' => wp_strip_all_tags( $p->get_short_description() ) ?: null,
            'price'           => $price_data,
            'list_price'      => isset( $price_data['regular'] ) ? [ 'amount' => $price_data['regular'], 'currency' => get_woocommerce_currency() ] : null,
            'stock'           => $stock,
            'checkout_url'    => self::checkout_url_for_product( $p ),
            'shipping'        => self::product_shipping_policy( $p, $price_data ),
            'active_coupons'  => self::active_coupons_for_product( $p, $price_data ),
            'categories'      => $categories,
            'tags'            => $tags,
            'attributes'      => $attributes,
            'images'          => $images,
            'gender'          => $gender,
            'colors'          => $colors,
            'sizes'           => $sizes,
            'weight'          => $p->get_weight() ? (float) $p->get_weight() : null,
            'dimensions'      => self::get_dimensions( $p ),
            'rating'          => [
                'average' => (float) $p->get_average_rating(),
                'count'   => (int) $p->get_rating_count(),
            ],
            'quarantine'         => $quarantine,
            'purchase_readiness' => $purchase_readiness,
            'barcodes'           => $barcodes,
            'metadata'           => [
                'purchase_readiness' => $purchase_readiness,
                'stock_confidence'   => $stock['confidence'] ?? null,
                'bridge_version'     => KALICART_BRIDGE_VERSION,
            ],
            'variation_summary'  => $variation_summary ?? null,
            'variations'         => $variations,
            'variants'           => $variants,
            'updated_at'         => $p->get_date_modified() ? $p->get_date_modified()->date( 'c' ) : null,
            'created_at'         => $p->get_date_created() ? $p->get_date_created()->date( 'c' ) : null,
        ];
    }

    // ── Shipping / coupons ─────────────────────────────────────────────────

    public static function merchant_shipping_policy(): array {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            return [
                'available' => false,
                'authority' => 'woocommerce_checkout',
                'note' => 'WooCommerce shipping zones are not available.',
            ];
        }

        $zones = WC_Shipping_Zones::get_zones();
        $default_zone = WC_Shipping_Zones::get_zone( 0 );
        if ( $default_zone ) {
            $zones[] = [
                'zone_id' => 0,
                'zone_name' => $default_zone->get_zone_name(),
                'zone_locations' => $default_zone->get_zone_locations(),
                'shipping_methods' => $default_zone->get_shipping_methods(),
            ];
        }

        $out_zones = [];
        $free_thresholds = [];
        foreach ( $zones as $zone ) {
            $methods = [];
            foreach ( (array) ( $zone['shipping_methods'] ?? [] ) as $method ) {
                if ( ! is_object( $method ) || isset( $method->enabled ) && $method->enabled !== 'yes' ) {
                    continue;
                }
                $method_id = (string) ( $method->id ?? '' );
                $settings = is_callable( [ $method, 'get_instance_form_fields' ] ) ? (array) $method->instance_settings : [];
                $row = [
                    'method_id' => $method_id,
                    'instance_id' => isset( $method->instance_id ) ? (int) $method->instance_id : null,
                    'title' => is_callable( [ $method, 'get_title' ] ) ? $method->get_title() : ( $method->title ?? $method_id ),
                    'enabled' => true,
                ];
                if ( $method_id === 'free_shipping' ) {
                    $min = isset( $method->min_amount ) && $method->min_amount !== '' ? (float) $method->min_amount : null;
                    $row['requires'] = $method->requires ?? null;
                    $row['min_amount'] = $min;
                    if ( $min !== null ) {
                        $free_thresholds[] = $min;
                    }
                } elseif ( $method_id === 'flat_rate' ) {
                    $row['cost'] = isset( $method->cost ) && $method->cost !== '' ? self::normalize_cost( $method->cost ) : null;
                } elseif ( isset( $method->cost ) && $method->cost !== '' ) {
                    $row['cost'] = self::normalize_cost( $method->cost );
                }
                if ( ! empty( $settings ) ) {
                    $row['settings_public_note'] = 'Method has WooCommerce settings; exact final price remains checkout authority.';
                }
                $methods[] = $row;
            }
            if ( empty( $methods ) ) {
                continue;
            }
            $zone_row = [
                'id' => (int) ( $zone['zone_id'] ?? 0 ),
                'name' => (string) ( $zone['zone_name'] ?? 'Rest of the world' ),
                'locations' => self::normalize_shipping_zone_locations( (array) ( $zone['zone_locations'] ?? [] ) ),
            ];
            if ( empty( $zone['zone_locations'] ) ) {
                $zone_row['locations_note'] = 'Zone has no explicit regions configured in WooCommerce. Coverage cannot be inferred from this document — WooCommerce checkout determines applicability.';
            }
            $zone_row['methods'] = $methods;
            $out_zones[] = $zone_row;
        }

        $free_thresholds = array_values( array_unique( array_filter( $free_thresholds, fn( $v ) => $v !== null ) ) );
        sort( $free_thresholds, SORT_NUMERIC );

        return [
            'available' => true,
            'currency' => get_woocommerce_currency(),
            'authority' => 'woocommerce_checkout',
            'calculation_model' => 'policy_snapshot_not_destination_quote',
            'free_shipping_available' => ! empty( $free_thresholds ),
            'free_shipping_thresholds' => $free_thresholds,
            'zones' => $out_zones,
            'note' => 'Use this policy for agent reasoning. Exact shipping cost depends on destination, cart contents, coupons and WooCommerce checkout rules; checkout remains final authority.',
        ];
    }

    private static function normalize_shipping_zone_locations( array $locations ): array {
        return array_values( array_map( function( $loc ) {
            return [
                'type' => isset( $loc->type ) ? (string) $loc->type : null,
                'code' => isset( $loc->code ) ? (string) $loc->code : null,
            ];
        }, $locations ) );
    }


    private static function get_shipping_zones(): array {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        $out   = [];
        $zones = WC_Shipping_Zones::get_zones( array(), 'json' );
        if ( empty( $zones ) ) $zones = WC_Shipping_Zones::get_zones();

        // Zona 0 = "Rest of World"
        $zone0 = new WC_Shipping_Zone( 0 );
        $zones_all = array_merge( [ [ 'zone_id' => 0, 'zone_name' => 'Rest of World', 'zone_locations' => [], 'shipping_methods' => $zone0->get_shipping_methods( true ) ] ], $zones );

        foreach ( $zones_all as $zone_data ) {
            $zone_name      = $zone_data['zone_name'];
            $locations      = array_map( fn( $l ) => $l->code, $zone_data['zone_locations'] ?? [] );
            $methods_raw    = $zone_data['shipping_methods'] ?? [];
            $methods        = [];
            $free_threshold = null;

            foreach ( $methods_raw as $method ) {
                // Skip disabled methods — check enabled option directly
                if ( $method->enabled !== 'yes' && ! $method->is_enabled() ) continue;

                if ( $method->id === 'free_shipping' ) {
                    $min = (float) ( $method->min_amount ?? 0 );
                    if ( $min > 0 ) $free_threshold = $min;
                    $methods[] = [
                        'id'          => 'free_shipping',
                        'title'       => $method->get_title(),
                        'cost'        => 0,
                        'currency'    => get_woocommerce_currency(),
                        'min_amount'  => $min ?: null,
                        'requires'    => $method->requires ?: null,
                    ];
                } elseif ( $method->id === 'flat_rate' ) {
                    $cost = isset( $method->cost ) ? (float) $method->cost : null;
                    $methods[] = [
                        'id'       => 'flat_rate',
                        'title'    => $method->get_title(),
                        'cost'     => $cost,
                        'currency' => get_woocommerce_currency(),
                    ];
                } elseif ( $method->id === 'local_pickup' ) {
                    $methods[] = [
                        'id'    => 'local_pickup',
                        'title' => $method->get_title(),
                        'cost'  => 0,
                    ];
                }
            }

            if ( empty( $methods ) ) continue;

            $out[] = [
                'zone'            => $zone_name,
                'locations'       => $locations,
                'methods'         => $methods,
                'free_threshold'  => $free_threshold,
            ];
        }

        $cached = $out;
        return $out;
    }

    private static function product_shipping_policy( WC_Product $p, array $price_data ): array {
        $policy = self::merchant_shipping_policy();
        $price = self::effective_product_price_for_conditions( $price_data );
        $thresholds = $policy['free_shipping_thresholds'] ?? [];
        $nearest = null;
        foreach ( $thresholds as $threshold ) {
            if ( $price !== null && $price <= (float) $threshold ) {
                $nearest = (float) $threshold;
                break;
            }
        }
        return [
            'shipping_required' => $p->needs_shipping(),
            'shipping_class' => $p->get_shipping_class() ?: null,
            'weight' => $p->get_weight() ? (float) $p->get_weight() : null,
            'weight_unit' => get_option( 'woocommerce_weight_unit', 'kg' ),
            'free_shipping_available' => (bool) ( $policy['free_shipping_available'] ?? false ),
            'free_shipping_thresholds' => $thresholds,
            'free_shipping_eligible_by_product_price' => $price !== null && ! empty( $thresholds )
                ? array_reduce( $thresholds, fn( $carry, $t ) => $carry || $price >= (float) $t, false )
                : null,
            'amount_to_nearest_free_shipping_threshold' => ( $price !== null && $nearest !== null )
                ? max( 0, round( $nearest - $price, 2 ) )
                : null,
            'zones'     => self::get_shipping_zones(),
            'authority' => 'woocommerce_checkout',
            'note' => 'Product-level shipping policy only. Exact shipping is computed by WooCommerce checkout for the destination and full cart.',
        ];
    }

    private static function absolute_storefront_url( string $url ): string {
        $url = trim( $url );
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url );
        }
        if ( str_starts_with( $url, '?' ) ) {
            return esc_url_raw( home_url( '/' . $url ) );
        }
        return esc_url_raw( home_url( '/' . ltrim( $url, '/' ) ) );
    }



    private static function get_variations( WC_Product $p ): array {
        if ( ! $p->is_type( 'variable' ) ) return [];
        $out = [];
        foreach ( $p->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->exists() ) continue;
            $attrs = [];
            foreach ( $variation->get_variation_attributes() as $key => $value ) {
                $clean_key = str_replace( 'attribute_', '', $key );
                $attrs[ $clean_key ] = $value ?: null; // null = any value
            }
            $price_data   = self::compute_price( $variation );
            $var_in_stock = $variation->is_in_stock();
            $var_manage   = $variation->managing_stock();
            $var_qty      = $var_manage ? $variation->get_stock_quantity() : null;
            $out[] = [
                'variation_id'        => $variation_id,
                'attributes'          => $attrs,
                'price'               => $price_data,
                'in_stock'            => $var_in_stock,
                'availability_status' => $var_in_stock ? 'in_stock' : ( $variation->get_stock_status() === 'onbackorder' ? 'backorder' : 'out_of_stock' ),
                'stock'               => array_filter( [
                    'in_stock'         => $var_in_stock,
                    'quantity'         => $var_qty,
                    'quantity_tracked' => $var_manage,
                    'confidence'       => $var_manage ? 'numeric_stock_quantity' : 'availability_status_only',
                    'agent_note'       => ( $var_manage && $var_qty === 1 )
                                            ? 'Last unit available. Race condition possible — complete checkout immediately.'
                                            : null,
                ], fn( $v ) => $v !== null ),
                'sku'                 => $variation->get_sku() ?: null,
                'barcodes'            => self::get_barcodes( $variation ),
                'purchasable'         => $variation->is_purchasable(),
            ];
        }
        return $out;
    }


    private static function get_barcodes( WC_Product $p ): array {
        $out  = [];
        $ean  = $p->get_meta( '_ean', true ) ?: $p->get_meta( 'ean', true );
        $gtin = $p->get_meta( '_gtin', true ) ?: $p->get_meta( 'gtin', true );
        $upc  = $p->get_meta( '_upc', true ) ?: $p->get_meta( 'upc', true );
        if ( $ean )  $out[] = [ 'type' => 'EAN',  'value' => (string) $ean ];
        if ( $gtin ) $out[] = [ 'type' => 'GTIN', 'value' => (string) $gtin ];
        if ( $upc )  $out[] = [ 'type' => 'UPC',  'value' => (string) $upc ];
        return $out;
    }

    private static function compute_stock( WC_Product $p ): array {
        $type         = $p->get_type();
        $manage_stock = $p->managing_stock();
        $quantity     = $manage_stock ? $p->get_stock_quantity() : null;
        $is_variable  = $type === 'variable';

        if ( $is_variable ) {
            $confidence = 'variant_dependent';
            $agent_note = 'Select a variation before reporting numeric stock.';
            $quantity   = null; // suppress aggregate count — misleading for variable products
        } elseif ( $manage_stock && $quantity !== null ) {
            $confidence = 'numeric_stock_quantity';
            // Race condition warning: a single unit may be claimed by concurrent agents.
            // Complete checkout immediately — do not present as safely available without verifying.
            $agent_note = $quantity === 1 ? 'Last unit available. Race condition possible — complete checkout immediately.' : null;
        } else {
            $confidence = 'availability_status_only';
            $agent_note = 'Merchant does not expose numeric stock quantity. Treat as available for purchase, not as confirmed inventory count.';
        }

        // UCP-compatible availability_status values
        $wc_status = $p->get_stock_status();
        $ucp_status = match( $wc_status ) {
            'instock'     => 'in_stock',
            'outofstock'  => 'out_of_stock',
            'onbackorder' => 'backorder',
            default       => $wc_status,
        };

        $backorder_raw = $p->get_backorders();
        $out = [
            'status'              => $wc_status,
            'availability_status' => $ucp_status,
            'in_stock'            => $p->is_in_stock(),
            'quantity'            => $quantity,
            'quantity_tracked'    => $manage_stock,
            'backorder'           => $backorder_raw,
            'backorder_allowed'   => in_array( $backorder_raw, [ 'notify', 'yes' ], true ),
            'manage_stock'        => $manage_stock,
            'confidence'          => $confidence,
        ];
        if ( $agent_note ) {
            $out['agent_note'] = $agent_note;
        }
        return $out;
    }

    private static function compute_purchase_readiness( WC_Product $p ): array {
        $type = $p->get_type();

        if ( $type === 'variable' ) {
            // B5: if the parent product is OOS, no variant selection makes sense
            if ( ! $p->is_in_stock() ) {
                return [
                    'status'                   => 'out_of_stock',
                    'blocking_fields'          => [],
                    'can_add_to_cart_directly' => false,
                    'agent_rule'               => 'Product is out of stock. Do not present for purchase.',
                ];
            }
            $attributes = $p->get_variation_attributes();
            $blocking   = array_keys( $attributes );
            return [
                'status'                   => 'variant_selection_required',
                'blocking_fields'          => $blocking,
                'can_add_to_cart_directly' => false,
                'agent_rule'               => 'Do not quote a final price until a variation is selected. Price may differ per variant.',
            ];
        }

        if ( $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock() ) {
            return [
                'status'                 => 'direct_cart_possible',
                'blocking_fields'        => [],
                'can_add_to_cart_directly' => true,
                'agent_rule'             => 'Product can be added to cart directly. Use checkout_url.',
            ];
        }

        return [
            'status'                 => 'requires_product_page',
            'blocking_fields'        => [],
            'can_add_to_cart_directly' => false,
            'agent_rule'             => 'Product requires the product page for purchase (external, grouped or not purchasable).',
        ];
    }

    private static function checkout_url_for_product( WC_Product $p ): string {
        if ( $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock() ) {
            $cart_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'cart' ) : '';
            if ( ! $cart_url || $cart_url === '#' ) {
                $cart_url = home_url( '/cart/' );
            }
            return esc_url_raw( add_query_arg( 'add-to-cart', $p->get_id(), $cart_url ) );
        }
        return esc_url_raw( get_permalink( $p->get_id() ) );
    }

    private static function effective_product_price_for_conditions( array $price_data ): ?float {
        if ( ( $price_data['type'] ?? '' ) === 'range' ) {
            if ( isset( $price_data['min_sale'] ) && $price_data['min_sale'] !== null ) return (float) $price_data['min_sale'];
            if ( isset( $price_data['min_regular'] ) && $price_data['min_regular'] !== null ) return (float) $price_data['min_regular'];
            return null;
        }
        return isset( $price_data['current'] ) && $price_data['current'] !== null ? (float) $price_data['current'] : null;
    }

    public static function active_coupons_for_product( WC_Product $p, array $price_data = [] ): array {
        if ( ! class_exists( 'WC_Coupon' ) ) return [];

        // Agent coupon exposure is opt-in. Master switch OFF or an empty whitelist
        // means the catalog stays silent on coupons: active_coupons is always [].
        // The whitelist stores coupon POST IDs (stable across code renames).
        if ( ! get_option( 'kalicart_bridge_coupons_agent_enabled', false ) ) return [];
        $whitelist = get_option( 'kalicart_bridge_coupons_agent_whitelist', [] );
        if ( ! is_array( $whitelist ) || empty( $whitelist ) ) return [];
        $whitelist = array_map( 'intval', $whitelist );

        $coupon_posts = get_posts( [
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'post__in' => $whitelist,
            'numberposts' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
        ] );
        if ( empty( $coupon_posts ) ) return [];

        $out = [];
        foreach ( $coupon_posts as $post ) {
            // Whitelist gate: coupon ID must be explicitly selected by the merchant.
            // Additive — the activity/applicability checks below still apply.
            if ( ! in_array( (int) $post->ID, $whitelist, true ) ) continue;

            $coupon = new WC_Coupon( $post->post_name );
            if ( ! $coupon || ! $coupon->get_code() ) continue;
            if ( ! self::coupon_is_currently_active( $coupon ) ) continue;
            if ( ! self::coupon_can_apply_to_product( $coupon, $p ) ) continue;

            $normalized = self::normalize_coupon_for_agent( $coupon, $p, $price_data );

            // Skip coupons with no computable value on this product
            $has_value     = ! empty( $normalized['estimated_saving_on_product'] ) && $normalized['estimated_saving_on_product'] > 0;
            $free_shipping = ! empty( $normalized['free_shipping'] );
            $cart_only     = $normalized['applicable_at'] === 'cart_only';

            if ( ! $has_value && ! $free_shipping && $cart_only ) continue;

            $out[] = $normalized;
        }
        return $out;
    }

    private static function coupon_is_currently_active( WC_Coupon $coupon ): bool {
        $expires = $coupon->get_date_expires();
        if ( $expires && $expires->getTimestamp() < time() ) return false;
        $usage_limit = $coupon->get_usage_limit();
        if ( $usage_limit && $coupon->get_usage_count() >= $usage_limit ) return false;
        return true;
    }

    private static function coupon_can_apply_to_product( WC_Coupon $coupon, WC_Product $p ): bool {
        $product_id = $p->get_id();
        $parent_id = $p->get_parent_id();
        $ids = array_filter( [ $product_id, $parent_id ] );

        if ( array_intersect( $ids, $coupon->get_excluded_product_ids() ) ) return false;

        $product_cats = wc_get_product_cat_ids( $product_id );
        $excluded_cats = $coupon->get_excluded_product_categories();
        if ( array_intersect( $product_cats, $excluded_cats ) ) return false;

        $included_products = $coupon->get_product_ids();
        if ( ! empty( $included_products ) && ! array_intersect( $ids, $included_products ) ) {
            return false;
        }

        $included_cats = $coupon->get_product_categories();
        if ( ! empty( $included_cats ) && ! array_intersect( $product_cats, $included_cats ) ) {
            return false;
        }

        return true;
    }

    private static function normalize_coupon_for_agent( WC_Coupon $coupon, WC_Product $p, array $price_data ): array {
        $price = self::effective_product_price_for_conditions( $price_data );
        $minimum = $coupon->get_minimum_amount() !== '' ? (float) $coupon->get_minimum_amount() : null;
        $maximum = $coupon->get_maximum_amount() !== '' ? (float) $coupon->get_maximum_amount() : null;
        $type = $coupon->get_discount_type();
        $amount = (float) $coupon->get_amount();
        $estimated = null;
        if ( $price !== null ) {
            if ( in_array( $type, [ 'percent', 'recurring_percent' ], true ) ) {
                $estimated = round( $price * ( $amount / 100 ), 2 );
            } elseif ( in_array( $type, [ 'fixed_product', 'fixed_cart', 'recurring_fee' ], true ) ) {
                $estimated = min( $price, $amount );
            }
        }
        $expires = $coupon->get_date_expires();
        return [
            'code' => $coupon->get_code(),
            'discount_type' => $type,
            'amount' => $amount,
            'currency' => get_woocommerce_currency(),
            'minimum_amount' => $minimum,
            'maximum_amount' => $maximum,
            'free_shipping' => (bool) $coupon->get_free_shipping(),
            'individual_use' => (bool) $coupon->get_individual_use(),
            'expires_at' => $expires ? $expires->date( 'c' ) : null,
            'estimated_saving_on_product' => $estimated,
            'applicable_at'               => in_array( $type, [ 'fixed_cart', 'recurring_fee' ], true ) ? 'cart_only' : 'product_or_cart',
            'combinable_with_sale'        => 'unknown — verify at checkout',
            'verification_required'       => true,
            'price_rule' => 'Do not replace product price.current with coupon value. Present coupon as conditional checkout saving.',
            'authority' => 'woocommerce_checkout',
            'note' => 'Coupon is active and appears applicable to this product/category. Final validity is checked by WooCommerce checkout against full cart, customer and destination.',
        ];
    }

    // ── Price ────────────────────────────────────────────────────────────────

    /**
     * Merchant-declared brand, from the standard Woo brand taxonomies.
     * MIRROR PRINCIPLE: exposes the brand only if the merchant set it;
     * never inferred, never fabricated. Null when absent.
     */
    public static function resolve_brand( WC_Product $p ): ?string {
        foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand', 'pa_marca' ] as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $terms = wp_get_post_terms( $p->get_id(), $tax, [ 'fields' => 'names' ] );
                if ( ! is_wp_error( $terms ) && $terms ) {
                    return (string) $terms[0];
                }
            }
        }
        return null;
    }

    private static function compute_price( WC_Product $p ): array {
        $currency = get_woocommerce_currency();

        if ( $p->is_type( 'variable' ) ) {
            /** @var WC_Product_Variable $p */
            $prices = $p->get_variation_prices( true );
            $min_regular = ! empty( $prices['regular_price'] ) ? (float) min( $prices['regular_price'] ) : null;
            $max_regular = ! empty( $prices['regular_price'] ) ? (float) max( $prices['regular_price'] ) : null;
            $min_sale    = ! empty( $prices['sale_price'] ) ? (float) min( array_filter( $prices['sale_price'] ) ) : null;
            $max_sale    = ! empty( $prices['sale_price'] ) ? (float) max( array_filter( $prices['sale_price'] ) ) : null;

            $on_sale = $p->is_on_sale();
            $discount_pct = null;
            if ( $on_sale && $min_regular && $min_sale ) {
                $discount_pct = round( ( ( $min_regular - $min_sale ) / $min_regular ) * 100, 1 );
                if ( $discount_pct < 1 ) {
                    $on_sale      = false;
                    $discount_pct = null;
                }
            }

            $display_min = $min_sale ?? $min_regular;
            $display_max = $max_sale ?? $max_regular;
            $display = $display_min !== null
                ? ( $display_min === $display_max ? wc_price( $display_min ) : wc_price( $display_min ) . ' – ' . wc_price( $display_max ) )
                : null;
            $display = $display !== null ? html_entity_decode( wp_strip_all_tags( $display ), ENT_QUOTES ) : null;

            $vat_included = wc_prices_include_tax();
            $tax_enabled  = wc_tax_enabled();

            // current: lowest active price — canonical readable field for all product types
            $current_range = $min_sale ?? $min_regular;

            $discount_amount_range = ( $on_sale && $min_regular !== null && $min_sale !== null )
                ? round( $min_regular - $min_sale, 2 )
                : null;

            return [
                'type'            => 'range',
                'currency'        => $currency,
                'encoding'        => 'decimal_major_units',
                'price_type'      => 'STATIC',
                'vat_included'    => $vat_included,
                'tax_enabled'     => $tax_enabled,
                'current'         => $current_range,
                'min_regular'     => $min_regular,
                'max_regular'     => $max_regular,
                'min_sale'        => $min_sale,
                'max_sale'        => $max_sale,
                'on_sale'         => $on_sale,
                'discount_pct'    => $discount_pct,
                'discount_amount' => $discount_amount_range,
                'display'         => $display,
            ];
        }

        $regular = $p->get_regular_price() !== '' ? (float) $p->get_regular_price() : null;
        $sale    = $p->get_sale_price() !== '' ? (float) $p->get_sale_price() : null;
        $current = $p->get_price() !== '' ? (float) $p->get_price() : null;
        $on_sale = $p->is_on_sale();

        $discount_pct = null;
        if ( $on_sale && $regular && $sale ) {
            $discount_pct = round( ( ( $regular - $sale ) / $regular ) * 100, 1 );
            if ( $discount_pct < 1 ) {
                $on_sale      = false;
                $discount_pct = null;
            }
        }

        $vat_included = wc_prices_include_tax();
        $tax_enabled  = wc_tax_enabled();

        $discount_amount_fixed = ( $on_sale && $regular !== null && $sale !== null )
            ? round( $regular - $sale, 2 )
            : null;

        return [
            'type'            => 'fixed',
            'currency'        => $currency,
            'encoding'        => 'decimal_major_units',
            'price_type'      => 'STATIC',
            'vat_included'    => $vat_included,
            'tax_enabled'     => $tax_enabled,
            'regular'         => $regular,
            'sale'            => $sale,
            'current'         => $current,
            'on_sale'         => $on_sale,
            'discount_pct'    => $discount_pct,
            'discount_amount' => $discount_amount_fixed,
            'display'         => $current !== null ? str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( wc_price( $current ) ), ENT_QUOTES ) ) : null,
        ];
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public static function get_product_categories( WC_Product $p ): array {
        $terms = get_the_terms( $p->get_id(), 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) return [];

        $out = [];
        foreach ( $terms as $term ) {
            $out[] = [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'path' => self::get_category_path( $term ),
            ];
        }
        return $out;
    }

    private static function get_category_path( WP_Term $term ): string {
        $ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
        $ancestors = array_reverse( $ancestors );
        $parts     = [];
        foreach ( $ancestors as $ancestor_id ) {
            $ancestor = get_term( $ancestor_id, 'product_cat' );
            if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                $parts[] = $ancestor->name;
            }
        }
        $parts[] = $term->name;
        return implode( ' > ', $parts );
    }

    // ── Tags ─────────────────────────────────────────────────────────────────

    private static function get_product_tags( WC_Product $p ): array {
        $terms = get_the_terms( $p->get_id(), 'product_tag' );
        if ( ! $terms || is_wp_error( $terms ) ) return [];

        return array_map( fn( $t ) => [
            'id'   => $t->term_id,
            'name' => $t->name,
            'slug' => $t->slug,
        ], $terms );
    }

    // ── Attributes ───────────────────────────────────────────────────────────

    private static function get_normalized_attributes( WC_Product $p ): array {
        $attributes = $p->get_attributes();
        $out        = [];

        foreach ( $attributes as $attr_key => $attr ) {
            $name   = wc_attribute_label( $attr_key, $p );
            $values = [];

            if ( $attr->is_taxonomy() ) {
                $terms = wc_get_product_terms( $p->get_id(), $attr_key, [ 'fields' => 'names' ] );
                $values = is_array( $terms ) ? $terms : [];
            } else {
                $raw = $attr->get_options();
                $values = is_array( $raw ) ? $raw : explode( ' | ', $raw );
            }

            $out[] = [
                'key'        => $attr_key,
                'name'       => $name,
                'values'     => array_values( array_filter( array_map( 'trim', $values ) ) ),
                'visible'    => (bool) $attr->get_visible(),
                'variation'  => (bool) $attr->get_variation(),
                'taxonomy'   => $attr->is_taxonomy(),
            ];
        }

        return $out;
    }

    // ── Images ───────────────────────────────────────────────────────────────

    private static function get_images( WC_Product $p ): array {
        $images = [];

        $main_id = $p->get_image_id();
        if ( $main_id ) {
            $src = wp_get_attachment_image_src( $main_id, 'woocommerce_single' );
            if ( $src ) {
                $images[] = [ 'id' => $main_id, 'src' => $src[0], 'main' => true ];
            }
        }

        foreach ( $p->get_gallery_image_ids() as $gid ) {
            $src = wp_get_attachment_image_src( $gid, 'woocommerce_single' );
            if ( $src ) {
                $images[] = [ 'id' => $gid, 'src' => $src[0], 'main' => false ];
            }
        }

        return $images;
    }

    // ── Gender inference ─────────────────────────────────────────────────────

    public static function infer_gender( WC_Product $p, array $categories, array $tags, array $attributes ): ?string {
        // 1. Dedicated taxonomy attribute (pa_gender, pa_sesso, pa_genere...)
        $gender_attr_keys = [ 'pa_gender', 'pa_sesso', 'pa_genere', 'pa_sex' ];
        foreach ( $attributes as $attr ) {
            if ( in_array( $attr['key'], $gender_attr_keys, true ) && ! empty( $attr['values'] ) ) {
                $val = strtolower( trim( $attr['values'][0] ) );
                foreach ( self::GENDER_KEYWORDS as $family => $keywords ) {
                    if ( in_array( $val, $keywords, true ) ) return $family;
                }
            }
        }

        // 2. Keyword scan: category paths + tag names + product name
        $haystack = '';
        foreach ( $categories as $cat ) {
            $haystack .= ' ' . strtolower( $cat['path'] );
        }
        foreach ( $tags as $tag ) {
            $haystack .= ' ' . strtolower( $tag['name'] );
        }
        $haystack .= ' ' . strtolower( $p->get_name() );

        foreach ( self::GENDER_KEYWORDS as $family => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/u', $haystack ) ) {
                    return $family;
                }
            }
        }

        return null; // indeterminate
    }

    // ── Colors ───────────────────────────────────────────────────────────────

    public static function extract_colors( array $attributes, string $name, array $tags ): array {
        $color_attr_keys = [ 'pa_color', 'pa_colore', 'pa_colour', 'pa_farbe', 'pa_couleur' ];
        $raw_values      = [];

        foreach ( $attributes as $attr ) {
            if ( in_array( $attr['key'], $color_attr_keys, true ) ) {
                $raw_values = array_merge( $raw_values, $attr['values'] );
            }
        }

        // Fallback: scan product name and tags
        if ( empty( $raw_values ) ) {
            $haystack = strtolower( $name );
            foreach ( $tags as $tag ) {
                $haystack .= ' ' . strtolower( $tag['name'] );
            }
            $raw_values[] = $haystack; // will be matched below
        }

        $found = [];
        foreach ( $raw_values as $val ) {
            $val_lower = strtolower( trim( $val ) );
            foreach ( self::COLOR_FAMILIES as $family => $keywords ) {
                foreach ( $keywords as $kw ) {
                    if ( strpos( $val_lower, $kw ) !== false ) {
                        $found[ $family ] = [
                            'family' => $family,
                            'raw'    => $val,
                        ];
                        break;
                    }
                }
            }
        }

        return array_values( $found );
    }

    // ── Sizes ────────────────────────────────────────────────────────────────

    public static function extract_sizes( array $attributes ): array {
        // Match by key (taxonomy) or by normalized name (custom attributes without pa_ prefix).
        // This handles both WooCommerce taxonomy attributes (pa_taglia) and custom attributes (size, Size).
        $size_attr_keys  = [ 'pa_size', 'pa_taglia', 'pa_größe', 'pa_taille', 'pa_talla', 'pa_misura' ];
        $size_attr_names = [ 'size', 'taglia', 'größe', 'taille', 'talla', 'misura', 'maat', 'storlek' ];

        foreach ( $attributes as $attr ) {
            $key_match  = in_array( $attr['key'], $size_attr_keys, true );
            $name_match = in_array( strtolower( $attr['name'] ?? '' ), $size_attr_names, true );

            if ( ( $key_match || $name_match ) && ! empty( $attr['values'] ) ) {
                $type   = self::detect_size_type( $attr['values'] );
                return [
                    'type'   => $type,
                    'values' => $attr['values'],
                ];
            }
        }

        return [];
    }

    private static function detect_size_type( array $values ): string {
        $normalized = array_map( fn( $v ) => strtolower( trim( $v ) ), $values );

        $clothing_hits = count( array_intersect( $normalized, self::SIZE_TYPE_CLOTHING ) );
        $numeric_hits  = count( array_intersect( $normalized, self::SIZE_TYPE_NUMERIC ) );
        $shoes_hits    = count( array_intersect( $normalized, self::SIZE_TYPE_SHOES ) );

        $total = count( $values );
        $known_hits = $clothing_hits + $numeric_hits + $shoes_hits;

        // If the majority of values do not match any known size vocabulary,
        // classify as alphanumeric — the agent reads the values and decides.
        // This covers cup sizes (36C), hardware codes (M8), and any unknown format.
        if ( $known_hits < $total / 2 ) return 'alphanumeric';

        if ( $shoes_hits > $clothing_hits && $shoes_hits > $numeric_hits ) return 'shoes';
        if ( $clothing_hits >= $numeric_hits ) return 'clothing';
        return 'numeric';
    }

    // ── Dimensions ───────────────────────────────────────────────────────────

    private static function get_dimensions( WC_Product $p ): ?array {
        $dims = [
            'length' => $p->get_length(),
            'width'  => $p->get_width(),
            'height' => $p->get_height(),
            'unit'   => get_option( 'woocommerce_dimension_unit', 'cm' ),
        ];
        $has = $dims['length'] || $dims['width'] || $dims['height'];
        if ( ! $has ) return null;

        return array_map( fn( $v ) => $v !== '' ? (float) $v : null, array_filter( $dims, fn( $k ) => $k !== 'unit', ARRAY_FILTER_USE_KEY ) )
            + [ 'unit' => $dims['unit'] ];
    }

    // ── Quarantine ───────────────────────────────────────────────────────────


    /**
     * Shipping cost: numeric values as float (machine-computable),
     * WooCommerce cost formulas (e.g. "10 + 2*[qty]") kept as string — never silently truncated.
     */
    private static function normalize_cost( $cost ) {
        // Use sprintf('%.2f') + (float) cast to produce a float with exactly 2 decimal digits.
        // round() alone is insufficient when PHP serialize_precision is set to a high value
        // (e.g. 17) on some hosts, causing json_encode to emit the full IEEE 754 representation
        // (e.g. 4.9000000000000003552...). sprintf forces the value into a 2-decimal string
        // before re-casting, which json_encode then serializes cleanly on any serialize_precision.
        // Formulas (non-numeric strings like "10 + 2*[qty]") are kept as string.
        return is_numeric( $cost ) ? (float) sprintf( '%.2f', (float) $cost ) : (string) $cost;
    }

    public static function compute_quarantine_flags( WC_Product $p, array $categories, array $images ): array {
        $flags = [];

        if ( self::title_word_count( $p->get_name() ) < 3 ) {
            $flags[] = [ 'code' => 'TITLE_TOO_SHORT', 'severity' => 'high', 'label' => 'Title has fewer than 3 words' ];
        }

        if ( strlen( trim( $p->get_description() . ' ' . $p->get_short_description() ) ) < 40 ) {
            $flags[] = [ 'code' => 'NO_DESCRIPTION', 'severity' => 'high', 'label' => 'Description is missing or too short' ];
        }

        if ( empty( $categories ) ) {
            $flags[] = [ 'code' => 'NO_CATEGORY', 'severity' => 'high', 'label' => 'No category assigned' ];
        }

        $price = (float) $p->get_price();
        if ( $price <= 0 && $p->get_status() === 'publish' ) {
            $flags[] = [ 'code' => 'ZERO_PRICE', 'severity' => 'medium', 'label' => 'Price is zero or not set' ];
        }

        $improvement_flags = [];
        if ( empty( $images ) ) {
            $improvement_flags[] = [ 'code' => 'NO_IMAGE', 'severity' => 'image', 'label' => 'Missing product image' ];
        }
        if ( empty( $p->get_sku() ) ) {
            $improvement_flags[] = [ 'code' => 'NO_SKU', 'severity' => 'sku', 'label' => 'Missing SKU — use product id for identification' ];
        }

        $all_flags = array_merge( $flags, $improvement_flags );

        return [
            'in_quarantine' => ! empty( $flags ),
            'score'         => self::compute_quality_score( $all_flags ),
            'flags'         => $flags,
            'improvements'  => [
                'no_image' => empty( $images ),
                'no_sku'   => empty( $p->get_sku() ),
            ],
        ];
    }

    public static function compute_quality_score( array $flags ): int {
        $deductions = 0;
        foreach ( $flags as $flag ) {
            $deductions += match ( $flag['severity'] ) {
                'high'   => 30,
                'medium' => 15,
                'low'    => 5,
                'image'  => 8,
                'sku'    => 4,
                default  => 0,
            };
        }
        return max( 0, 100 - $deductions );
    }

    private static function title_word_count( string $title ): int {
        $words = preg_split( '/\s+/', trim( wp_strip_all_tags( $title ) ) );
        if ( empty( $words ) ) return 0;

        return count( array_filter( $words, fn( $word ) => preg_match( '/[\p{L}\p{N}]/u', $word ) ) );
    }

    // ── Categories tree ──────────────────────────────────────────────────────

    /**
     * Compute available gender and color values actually present in the catalog.
     * Heavy operation — runs via WP-Cron (kalicart_bridge_facets_rebuild) every 6 hours.
     * Results stored in option kalicart_bridge_catalog_facets_{lang}.
     *
     * Direct callers: cron handler + admin force-rebuild. Never call inline on a web request.
     *
     * @param string|null $lang Polylang language slug, or null on monolingual sites.
     * @return array{ genders: list<array{value:string,count:int}>, colors: list<array{value:string,count:int}> }
     */
    public static function compute_catalog_facets( ?string $lang = null ): array {
        $args = [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ];
        if ( $lang !== null ) {
            $args['lang'] = $lang;
        }
        $ids = get_posts( $args );

        $gender_counts = [];
        $color_counts  = [];

        foreach ( $ids as $pid ) {
            $p = wc_get_product( $pid );
            if ( ! $p ) continue;
            $attrs = self::get_normalized_attributes( $p );
            $tags  = self::get_product_tags( $p );
            $cats  = self::get_product_categories( $p );

            $g = self::infer_gender( $p, $cats, $tags, $attrs );
            if ( $g ) {
                $gender_counts[ $g ] = ( $gender_counts[ $g ] ?? 0 ) + 1;
            }

            foreach ( self::extract_colors( $attrs, $p->get_name(), $tags ) as $col ) {
                $fam = $col['family'] ?? null;
                if ( $fam ) {
                    $color_counts[ $fam ] = ( $color_counts[ $fam ] ?? 0 ) + 1;
                }
            }
        }

        arsort( $gender_counts );
        arsort( $color_counts );

        $genders = [];
        foreach ( $gender_counts as $v => $cnt ) {
            $genders[] = [ 'value' => $v, 'count' => $cnt ];
        }
        $colors = [];
        foreach ( $color_counts as $v => $cnt ) {
            $colors[] = [ 'value' => $v, 'count' => $cnt ];
        }

        $result = [ 'genders' => $genders, 'colors' => $colors ];

        // Persist result so meta endpoint can read it without re-computing.
        $option_key = 'kalicart_bridge_catalog_facets_' . ( $lang ?? 'mono' );
        update_option( $option_key, $result, false ); // autoload=false

        return $result;
    }

    /**
     * Read pre-computed catalog facets from option storage.
     * Returns null if facets have never been computed (cron not yet run).
     *
     * @param string|null $lang
     * @return array|null
     */
    public static function get_cached_catalog_facets( ?string $lang = null ): ?array {
        $option_key = 'kalicart_bridge_catalog_facets_' . ( $lang ?? 'mono' );
        $cached = get_option( $option_key, null );
        return is_array( $cached ) ? $cached : null;
    }

    public static function get_categories_tree(): array {
        $term_args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ];
        $default_lang = KaliCart_Bridge_API::default_language();
        if ( $default_lang !== null ) {
            $term_args['lang'] = $default_lang; // Polylang term-language filter
        }
        $terms = get_terms( $term_args );

        if ( is_wp_error( $terms ) ) return [];

        $map = [];
        $catalog_base = rest_url( KALICART_BRIDGE_API_NS . '/catalog' );
        foreach ( $terms as $term ) {
            $map[ $term->term_id ] = [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'parent'      => $term->parent ?: null,
                'count'       => $term->count,        // direct products only (WC native)
                'has_products' => $term->count > 0,   // false = empty leaf, skip in agent queries
                'products_url' => add_query_arg( 'category', $term->slug, $catalog_base . '/products' ),
                'search_url_template' => add_query_arg(
                    [
                        'q' => '{spine}',
                        'category' => $term->slug,
                    ],
                    $catalog_base . '/search'
                ),
                'children'    => [],
            ];
        }

        $roots = [];
        foreach ( $map as $id => &$node ) {
            if ( $node['parent'] && isset( $map[ $node['parent'] ] ) ) {
                $map[ $node['parent'] ]['children'][] = &$node;
            } else {
                // Skip Uncategorized at root level
                if ( $node['slug'] !== 'uncategorized' ) {
                    $roots[] = &$node;
                }
            }
        }

        return $roots;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function map_orderby( string $orderby ): string {
        return match ( $orderby ) {
            'price'      => 'meta_value_num',
            'title'      => 'title',
            'popularity' => 'comment_count',
            default      => 'date',
        };
    }
}
