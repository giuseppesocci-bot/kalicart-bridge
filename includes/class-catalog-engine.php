<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Catalog_Engine
 *
 * Pure computational normalisation of WooCommerce data.
 * No LLM. No external service. Merchant taxonomy preserved.
 */
class KaliCart_Bridge_Catalog_Engine {

    private const QUERY_CACHE_TRANSIENT = 'kalicart_bridge_catalog_query_cache_v1';

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

    /**
     * Register cache invalidation hooks. The cache is a single bounded bucket,
     * therefore invalidation is one delete rather than a transient-prefix scan.
     */
    public static function init_cache_hooks(): void {
        foreach ( [
            'woocommerce_new_product',
            'woocommerce_update_product',
            'woocommerce_delete_product',
            'woocommerce_new_product_variation',
            'woocommerce_update_product_variation',
            'woocommerce_delete_product_variation',
            'woocommerce_product_set_stock',
            'woocommerce_variation_set_stock',
            'woocommerce_new_coupon',
            'woocommerce_update_coupon',
            'woocommerce_delete_coupon',
        ] as $hook ) {
            add_action( $hook, [ __CLASS__, 'invalidate_query_cache' ] );
        }

        foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
            add_action( $hook, static function( $term_id, $term_taxonomy_id, $taxonomy ): void {
                if ( self::catalog_taxonomy_affects_cache( (string) $taxonomy ) ) {
                    self::invalidate_query_cache();
                }
            }, 10, 3 );
        }

        add_action( 'set_object_terms', static function( $object_id, $terms, $term_taxonomy_ids, $taxonomy ): void {
            if ( in_array( get_post_type( (int) $object_id ), [ 'product', 'product_variation' ], true )
                 && self::catalog_taxonomy_affects_cache( (string) $taxonomy ) ) {
                self::invalidate_query_cache();
            }
        }, 10, 4 );

        add_action( 'transition_post_status', static function( $new_status, $old_status, $post ): void {
            if ( $new_status === $old_status || ! ( $post instanceof WP_Post ) ) {
                return;
            }
            if ( in_array( $post->post_type, [ 'product', 'product_variation', 'shop_coupon' ], true ) ) {
                self::invalidate_query_cache();
            }
        }, 10, 3 );
    }

    /** Public because WooCommerce hooks pass varying argument lists. */
    public static function invalidate_query_cache( ...$unused ): void {
        delete_transient( self::QUERY_CACHE_TRANSIENT );
        delete_transient( 'kalicart_bridge_meta_' . ( KaliCart_Bridge_API::default_language() ?? 'mono' ) );
    }

    private static function catalog_taxonomy_affects_cache( string $taxonomy ): bool {
        return in_array( $taxonomy, [ 'product_cat', 'product_tag', 'product_brand', 'pwb-brand' ], true )
               || strpos( $taxonomy, 'pa_' ) === 0;
    }

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
            'search'         => '',
            'category'       => '',
            'per_page'       => 20,
            'page'           => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'in_stock'       => null,
            'on_sale'        => null,
            'min_price'      => null,
            'max_price'      => null,
            'gender'         => '',
            'color'          => '',
            'modified_after' => '',
            'physical_only'  => null,
            'fields'         => 'full',
            // CAMMINATA-STABILE-v1 (1.0.135) — see map_orderby() and
            // apply_after_id_clause(): a threshold to resume a paged read
            // without the pages already read shifting under the reader.
            'after_id'       => null,
        ];
        $args = wp_parse_args( $args, $defaults );

        // Woo's lookup table stores entered prices. If shop display uses the
        // opposite tax basis, lookup bounds/order are not comparable with the
        // prices returned by compute_price(). Keep the SQL prefilter only where
        // its bound is conservative; verify and sort display prices in PHP.
        $display_includes_tax = wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' );
        $entered_includes_tax = wc_tax_enabled() && wc_prices_include_tax();
        $tax_basis_differs = wc_tax_enabled() && $display_includes_tax !== $entered_includes_tax;
        $display_price_sort = $tax_basis_differs && 'price' === $args['orderby'];
        $sql_min_price = $tax_basis_differs && $display_includes_tax ? null : $args['min_price'];
        $sql_max_price = $tax_basis_differs && ! $display_includes_tax ? null : $args['max_price'];

        $cached = self::query_cache_get( $args );
        if ( $cached !== null ) {
            return $cached;
        }

        // Derived fields require a bounded PHP verification pass. Price filters use
        // WooCommerce's lookup table to reduce candidates, then verify price.current
        // from the product object so variable-parent _price drift cannot leak through.
        $has_php_postfilter = ! empty( $args['gender'] ) || ! empty( $args['color'] )
                              || $args['on_sale'] === true
                              || $args['physical_only'] === true
                              || $args['min_price'] !== null || $args['max_price'] !== null
                              || $display_price_sort;
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$lookup_price = $sql_min_price !== null || $sql_max_price !== null
			|| ( $args['orderby'] === 'price' && ! $display_price_sort );

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $has_php_postfilter ? self::postfilter_batch_size() : (int) $args['per_page'],
            'paged'          => $has_php_postfilter ? 1 : (int) $args['page'],
            'orderby'        => $args['orderby'] === 'price' ? 'ID' : self::map_orderby( $args['orderby'] ),
            'order'          => $order,
        ];

        // CAMMINATA-STABILE-v1 (1.0.135) — walking a catalog in pages with
        // `orderby=date` is not repeatable. Mind the reason, which is not the
        // obvious one: WP_Query sorts by `post_date`, the PUBLICATION
        // date, so editing a product does not move it. What moves everything
        // is **publishing a new one**: with `order=DESC` it enters at the top and
        // shifts every following page by one position, so whoever is reading
        // page five reads one product again and skips another. Same effect for a
        // product restored from the trash or with its publication date changed
        // by hand. The ID instead never changes, and a new product
        // gets a higher one: so it ends up at the bottom, where the reader has not
        // arrived yet, without moving anything already read. Verified on
        // 2026-09-18 on two stores whose ID order does NOT match the order
        // of creation (one with 1,104 mismatches out of 1,231, left over from a
        // migration): there too the most recent products have the highest IDs.
        //
        // `after_id` is a THRESHOLD, not a pointer: it asks for "the ones after
        // number N". If product N was deleted in the meantime, `ID > N`
        // keeps working, and the question "what do you answer if the cursor points to
        // a product that no longer exists" does not arise.
        //
        // Known limit: a product that COMES BACK with an ID already passed (restored
        // from the trash, or reimported with an explicit ID) is skipped until the
        // next full read. A bounded error that heals by itself.
        $after_id = $args['after_id'] === null ? null : (int) $args['after_id'];
        if ( $after_id !== null && $after_id > 0 ) {
            $query_args['kalicart_bridge_after_id'] = $after_id;
        }

		if ( $lookup_price ) {
			$query_args['kalicart_bridge_price_lookup'] = [
				'min'     => $sql_min_price,
				'max'     => $sql_max_price,
				'orderby' => $args['orderby'] === 'price' && ! $display_price_sort,
				'order'   => $order,
			];
		}

        if ( $has_php_postfilter ) {
            // Dates, prices and titles are not unique. ID is the deterministic
            // tiebreaker required to walk a result set in bounded pages.
			$primary_orderby                           = $args['orderby'] === 'price' ? 'ID' : self::map_orderby( $args['orderby'] );
            $query_args['orderby']                = [ $primary_orderby => $order, 'ID' => $order ];
            $query_args['fields']                 = 'ids';
            $query_args['update_post_meta_cache'] = false;
            $query_args['update_post_term_cache'] = false;
            $query_args['lazy_load_term_meta']    = false;
        }

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        // CATALOG-VISIBILITY-v1 — the agentic catalog is the MIRROR of the store.
        // Up to 1.0.133 only `post_status` was filtered here, so a product
        // set to "Hidden" was served anyway: an explicit choice of the
        // merchant ignored silently.
        //
        // The two WooCommerce terms are respected one by one, which is also the
        // simplest way to write it: browsing drops what is excluded from the
        // catalog, text search drops what is excluded from search. It follows
        // by itself that "Hidden" (both terms) disappears from everything, "Shop only"
        // lives on the shelves and "Search results only" is found by whoever searches for it —
        // exactly as on the merchant's site. If the product exists and sells
        // on WooCommerce, the Bridge shows it where the store would show it.
        //
        // We go no further until we know WHY a merchant demotes a product
        // (spare part off the shop window? item linked from a campaign? old
        // stock?). Today that data does not exist: 0 products out of 5,373 federated
        // carry it, because the Bridge never sent it. From this version it
        // sends it, so the question will get a measured answer and not a guess.
        $query_args['tax_query'][] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => [ empty( $args['search'] ) ? 'exclude-from-catalog' : 'exclude-from-search' ],
            'operator' => 'NOT IN',
        ];

        if ( ! empty( $args['category'] ) ) {
            $query_args['tax_query'][] = [
                'taxonomy'         => 'product_cat',
                'field'            => is_numeric( $args['category'] ) ? 'term_id' : 'slug',
                'terms'            => $args['category'],
                'include_children' => true,
            ];
        }

        if ( $args['in_stock'] === true ) {
            $query_args['meta_query'][] = [ 'key' => '_stock_status', 'value' => 'instock' ];
        }

        if ( $args['on_sale'] === true ) {
            $sale_ids = wc_get_product_ids_on_sale();
            $query_args['post__in'] = ! empty( $sale_ids ) ? $sale_ids : [ 0 ];
        }

        if ( isset( $query_args['meta_query'] ) && count( $query_args['meta_query'] ) > 1 ) {
            $query_args['meta_query']['relation'] = 'AND';
        }

        if ( ! empty( $args['modified_after'] ) ) {
            $query_args['date_query'] = [ [
                'column'    => 'post_modified_gmt',
                'after'     => $args['modified_after'],
                'inclusive' => true,
            ] ];
        }

        $default_lang = KaliCart_Bridge_API::default_language();
        if ( $default_lang !== null ) {
            $query_args['lang'] = $default_lang;
        }

		$query    = self::run_product_query( $query_args );
        $products = [];

        if ( $has_php_postfilter ) {
            $candidate_total = (int) $query->found_posts;
            $candidate_limit = self::postfilter_candidate_limit();
            if ( $candidate_limit > 0 && $candidate_total > $candidate_limit ) {
                $price_sort_broad = $display_price_sort;
                $result = [ '_error' => [
                    'code'            => 'KALICART_CATALOG_QUERY_TOO_BROAD',
                    'status'          => 422,
                    'message'         => $price_sort_broad
                        ? 'Price ordering needs a narrower result set to calculate shopper display prices safely. Add q or category and retry.'
                        : 'This derived-filter query is too broad to evaluate safely. Add q or category and retry.',
                    'candidate_count' => $candidate_total,
                    'candidate_limit' => $candidate_limit,
                    'guidance'        => $price_sort_broad
                        ? 'Narrow the candidate set with a product noun (q) or a WooCommerce category slug, then retry price ordering.'
                        : 'Narrow the candidate set with a bare product noun (q) or a WooCommerce category slug before applying gender, color or on_sale. Verify size only in the selected product variations.',
                ] ];
                self::query_cache_put( $args, $result );
                return $result;
            }

            $want_summary   = ( $args['fields'] ?? 'full' ) === 'summary';
            $per_page       = max( 1, (int) $args['per_page'] );
            $page           = max( 1, (int) $args['page'] );
            $result_start   = ( $page - 1 ) * $per_page;
            $result_end     = $result_start + $per_page;
            $filtered_total = 0;
            $gender_evaluated = 0;
            $gender_matched = 0;
            $gender_unknown = 0;
            $gender_excluded = 0;
            $page_states    = [];
            $display_sort_rows = [];
            $batch_page     = 1;
            $batch_pages    = (int) $query->max_num_pages;

            do {
                $batch_ids = array_map( 'intval', $query->posts );
                // WP_Query fields=ids skips normal cache priming. Prime only this
                // bounded batch to avoid N meta/term queries in wc_get_product().
                if ( function_exists( '_prime_post_caches' ) && ! empty( $batch_ids ) ) {
                    _prime_post_caches( $batch_ids, true, true );
                }
                foreach ( $batch_ids as $product_id ) {
                    $wc_product = wc_get_product( (int) $product_id );
                    if ( ! $wc_product ) {
                        continue;
                    }
                    self::$gender_pass_state = null;
                    self::$gender_pass_detected = null;
                    $passes_filters = self::matches_derived_filters( $wc_product, $args );

                    // Gender is evaluated only after every strict filter has passed.
                    // These counters therefore describe the exact baseline against
                    // which the soft filter can change (or fail to change) results.
                    if ( ! empty( $args['gender'] ) && self::$gender_pass_state !== null ) {
                        $gender_evaluated++;
                        if ( 'matched' === self::$gender_pass_state ) {
                            $gender_matched++;
                        } elseif ( 'unknown_retained' === self::$gender_pass_state ) {
                            $gender_unknown++;
                        } elseif ( 'excluded' === self::$gender_pass_state ) {
                            $gender_excluded++;
                        }
                    }

                    if ( ! $passes_filters ) {
                        continue;
                    }

                    if ( ! empty( $args['gender'] ) ) {
                        $page_states[ (int) $product_id ] = [
                            'status'   => self::$gender_pass_state,
                            'detected' => self::$gender_pass_detected,
                        ];
                    }

                    $match_index = $filtered_total;
                    $filtered_total++;
                    if ( $display_price_sort ) {
                        $display_sort_rows[] = [
                            'id' => (int) $product_id,
                            'price' => self::compute_price( $wc_product )['current'] ?? null,
                        ];
                        continue;
                    }
                    if ( $match_index < $result_start || $match_index >= $result_end ) {
                        continue;
                    }

                    // Full projection cost is paid only for survivors on this page.
                    $projection_context = ! empty( $args['gender'] )
                        ? [ 'gender' => self::$gender_pass_detected ]
                        : null;
                    $products[] = self::normalize_product( $wc_product, $want_summary ? 'summary' : 'list', $projection_context );
                }

                $batch_page++;
                if ( $batch_page <= $batch_pages ) {
                    $query_args['paged']         = $batch_page;
                    $query_args['no_found_rows'] = true;
					$query = self::run_product_query( $query_args );
                }
            } while ( $batch_page <= $batch_pages );

            if ( $display_price_sort ) {
                usort( $display_sort_rows, static function ( $a, $b ) use ( $order ) {
                    $a_valid = $a['price'] !== null && (float) $a['price'] > 0;
                    $b_valid = $b['price'] !== null && (float) $b['price'] > 0;
                    if ( $a_valid !== $b_valid ) return $a_valid ? -1 : 1;
                    $cmp = (float) ( $a['price'] ?? 0 ) <=> (float) ( $b['price'] ?? 0 );
                    if ( $cmp === 0 ) $cmp = $a['id'] <=> $b['id'];
                    return $order === 'DESC' ? -$cmp : $cmp;
                } );
                foreach ( array_slice( $display_sort_rows, $result_start, $per_page ) as $row ) {
                    $wc_product = wc_get_product( $row['id'] );
                    if ( ! $wc_product ) continue;
                    $projection_context = ! empty( $args['gender'] )
                        ? [ 'gender' => $page_states[ $row['id'] ]['detected'] ?? null ]
                        : null;
                    $products[] = self::normalize_product( $wc_product,
                        $want_summary ? 'summary' : 'list', $projection_context );
                }
            }

            $result = [
                'products'    => $products,
                'total'       => $filtered_total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil( $filtered_total / $per_page ),
            ];

            // `gender` is the ONLY soft filter of the Bridge: a different gender
            // excludes, an unknown gender KEEPS the product as a candidate.
            // Defensible (genders are inferred poorly, excluding them would lose
            // too much) but invisible until now: on a catalog where 96 products out of 109
            // have no detected gender, any value seemed to filter.
            // color, stock, promotions and prices stay strict: without evidence the
            // product is out. The asymmetry is declared, not suffered.
            if ( ! empty( $args['gender'] ) ) {
                // The loop above does NOT stop at a full page: it goes through all the
                // candidates to compute $filtered_total. So the counts are
                // over the whole set and result_set_evaluation_complete is true.
                // If one day the loop had to stop early, this
                // flag must be set to false and the two global counts to null — NEVER to
                // zero, and never estimated: a partial number that looks complete is
                // worse than a missing number.
                $result['gender_summary'] = [
                    'requested'                         => (string) $args['gender'],
                    'mode'                              => 'soft',
                    'scope'                             => 'result_set',
                    'result_set_evaluation_complete'    => true,
                    'result_set_matched_count'          => $gender_matched,
                    'result_set_unknown_retained_count' => $gender_unknown,
                ];
                $result['filter_effect']['gender'] = [
                    'evaluated_scope'        => 'candidates_matching_all_other_filters',
                    'evaluated_count'        => $gender_evaluated,
                    'matched_count'          => $gender_matched,
                    'unknown_retained_count' => $gender_unknown,
                    'excluded_count'         => $gender_excluded,
                    'evaluation_complete'    => true,
                    'changed_result_set'     => $gender_excluded > 0,
                ];
                if ( $gender_evaluated > 0 && 0 === $gender_matched && 0 === $gender_excluded ) {
                    $result['result_guidance'] = [
                        'code'    => 'GENDER_FILTER_NO_EFFECT',
                        'message' => 'The gender filter was evaluated completely but did not change the result set: no product had a confirmed matching gender and every evaluated product was retained because its gender was unknown.',
                    ];
                } elseif ( 0 === $gender_matched && $gender_unknown > 0 ) {
                    $result['result_guidance'] = [
                        'code'    => 'NO_CONFIRMED_GENDER_MATCHES',
                        'message' => 'No product in this result set has a confirmed gender matching the filter. The products returned are candidates whose gender could not be determined and were retained rather than discarded.',
                    ];
                }
                foreach ( $result['products'] as $kc_i => $kc_p ) {
                    $kc_id = (int) ( $kc_p['id'] ?? 0 );
                    if ( ! isset( $page_states[ $kc_id ] ) ) {
                        continue;
                    }
                    $result['products'][ $kc_i ]['filter_evidence']['gender'] = [
                        'requested' => (string) $args['gender'],
                        'detected'  => $page_states[ $kc_id ]['detected'],
                        'status'    => $page_states[ $kc_id ]['status'],
                    ];
                }
            }

            self::query_cache_put( $args, $result );
            return $result;
        }

        $want_summary = ( $args['fields'] ?? 'full' ) === 'summary';
        foreach ( $query->posts as $post ) {
            $wc_product = wc_get_product( $post->ID );
            if ( ! $wc_product ) {
                continue;
            }
            $products[] = self::normalize_product( $wc_product, $want_summary ? 'summary' : 'list' );
        }

        $result = [
            'products'    => $products,
            'total'       => (int) $query->found_posts,
            'page'        => (int) $args['page'],
            'per_page'    => (int) $args['per_page'],
            'total_pages' => (int) $query->max_num_pages,
        ];
        self::query_cache_put( $args, $result );
        return $result;
    }

	/**
	 * Run only Bridge product queries with the WooCommerce price lookup clause.
	 * The callback is installed for the duration of the query and also checks a
	 * private query var, so unrelated queries in the same request are untouched.
	 */
	private static function run_product_query( array $query_args ): WP_Query {
		$needs_price  = ! empty( $query_args['kalicart_bridge_price_lookup'] );
		$needs_after  = ! empty( $query_args['kalicart_bridge_after_id'] );
		if ( ! $needs_price && ! $needs_after ) {
			return new WP_Query( $query_args );
		}

		if ( $needs_price ) { add_filter( 'posts_clauses', [ __CLASS__, 'apply_price_lookup_clauses' ], 20, 2 ); }
		if ( $needs_after ) { add_filter( 'posts_clauses', [ __CLASS__, 'apply_after_id_clause' ], 21, 2 ); }
		try {
			return new WP_Query( $query_args );
		} finally {
			if ( $needs_price ) { remove_filter( 'posts_clauses', [ __CLASS__, 'apply_price_lookup_clauses' ], 20 ); }
			if ( $needs_after ) { remove_filter( 'posts_clauses', [ __CLASS__, 'apply_after_id_clause' ], 21 ); }
		}
	}

	/** Public because WordPress invokes filter callbacks outside class scope. */
	public static function apply_after_id_clause( array $clauses, WP_Query $query ): array {
		$after = (int) $query->get( 'kalicart_bridge_after_id' );
		if ( $after <= 0 ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		return $clauses;
	}

	/** Public because WordPress invokes filter callbacks outside class scope. */
	public static function apply_price_lookup_clauses( array $clauses, WP_Query $query ): array {
		$filter = $query->get( 'kalicart_bridge_price_lookup' );
		if ( ! is_array( $filter ) ) {
			return $clauses;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		$alias = 'kalicart_bridge_price_lookup';
		if ( strpos( $clauses['join'], " {$alias} " ) === false ) {
			$clauses['join'] .= " INNER JOIN {$table} AS {$alias} ON ({$wpdb->posts}.ID = {$alias}.product_id)";
		}
		$clauses['where'] .= " AND {$alias}.min_price IS NOT NULL";
		if ( $filter['min'] !== null ) {
			// Conservative overlap prefilter: price.current is verified from the
			// product below, so do not discard a variable product whose price range
			// crosses the requested minimum.
			$clauses['where'] .= $wpdb->prepare(
				' AND kalicart_bridge_price_lookup.max_price >= %f',
				(float) $filter['min']
			);
		}
		if ( $filter['max'] !== null ) {
			$clauses['where'] .= $wpdb->prepare(
				' AND kalicart_bridge_price_lookup.min_price <= %f',
				(float) $filter['max']
			);
		}
		if ( ! empty( $filter['orderby'] ) ) {
			$order              = 'ASC' === ( $filter['order'] ?? '' ) ? 'ASC' : 'DESC';
			// WooCommerce keeps zero-priced placeholder parents in the lookup table.
			// They remain discoverable, but must sort after products with a usable price
			// in both directions so an ascending page is not filled with null prices.
			$clauses['orderby'] = "CASE WHEN {$alias}.min_price <= 0 THEN 1 ELSE 0 END ASC, {$alias}.min_price {$order}, {$wpdb->posts}.ID {$order}";
		}

		return $clauses;
	}

    /**
     * Evaluate only the computed fields requested by the caller. This intentionally
     * avoids images, shipping, coupons, quarantine and purchase-readiness work for
     * candidates that will not appear on the requested page.
     */
    private static function matches_derived_filters( WC_Product $product, array $args ): bool {
        $attributes = null;
        $categories = null;
        $tags       = null;

        // Evaluated before colour and gender on purpose: this is a hard constraint
        // declared by the caller, not an inference. Excluding here means the
        // attribute and taxonomy work below is never paid for a product the caller
        // has already ruled out. Strict, like every filter except gender: a product
        // WooCommerce says needs no shipping is out, it is not "unknown".
        if ( $args['physical_only'] === true && ! self::product_needs_shipping( $product ) ) {
            return false;
        }

        if ( ! empty( $args['color'] ) ) {
            $attributes = $attributes ?? self::get_normalized_attributes( $product );
            $tags       = $tags ?? self::get_product_tags( $product );
            $families   = array_column( self::extract_colors( $attributes, $product->get_name(), $tags ), 'family' );
            if ( ! in_array( $args['color'], $families, true ) ) {
                return false;
            }
        }

        // wc_get_product_ids_on_sale() already reduced the SQL candidate set. This
        // lightweight check preserves Bridge's documented >=1% sale threshold.
        if ( $args['on_sale'] === true && ! ( self::compute_price( $product )['on_sale'] ?? false ) ) {
            return false;
        }

		if ( $args['min_price'] !== null || $args['max_price'] !== null ) {
			// The price of a product is an INTERVAL: for a simple product the two ends
			// coincide, for a variable one they do not. The product is kept if its interval
			// touches the requested one — the same overlap semantics already declared
			// by the SQL prefilter, which would otherwise be cancelled here.
			$price_data = self::compute_price( $product );
			$lo = $price_data['current'] ?? null;
			$hi = $price_data['max_current'] ?? $lo;
			if ( $lo === null
				|| ( $args['min_price'] !== null && (float) $hi < (float) $args['min_price'] )
				|| ( $args['max_price'] !== null && (float) $lo > (float) $args['max_price'] ) ) {
				return false;
			}
		}

        if ( ! empty( $args['gender'] ) ) {
            $attributes = $attributes ?? self::get_normalized_attributes( $product );
            $categories = self::get_product_categories( $product );
            $tags       = $tags ?? self::get_product_tags( $product );
            $gender     = self::infer_gender( $product, $categories, $tags, $attributes );
            self::$gender_pass_detected = $gender;
            // Preserve the soft-gender contract: an explicitly different value is
            // excluded, while an unclassified product remains a candidate.
            if ( $gender !== $args['gender'] && $gender !== null ) {
                self::$gender_pass_state = 'excluded';
                return false;
            }
            self::$gender_pass_state = ( null === $gender ) ? 'unknown_retained' : 'matched';
        }

        return true;
    }

    /**
     * Whether WooCommerce would ask for a shipping address for this product.
     *
     * MISURATO 2026-09-15, WooCommerce 11.1.0 — `WC_Product_Variable::get_virtual()`
     * returns `false` UNCONDITIONALLY (class-wc-product-variable.php: "Variable
     * products themselves cannot be virtual"). The inherited
     * `needs_shipping()` is `! is_virtual()`, so on a variable product it is
     * ALWAYS true no matter how its variations are configured. A merchant selling
     * digital goods as variations — course tiers, ebook formats, license sizes —
     * is reported as physical by WooCommerce's own accessor. Federation-wide that
     * blind spot covered 2.072 variable products out of 5.375, none of which could
     * ever report false. The parent flag is therefore not a usable answer here and
     * the variations have to be consulted.
     *
     * Semantics follow the cart: a product needs shipping if ANY of its variations
     * does. That short-circuits on the first physical variation, so an ordinary
     * variable product costs one variation load, not a full walk; only an
     * entirely-digital product pays for the whole set. Unknown stays physical —
     * no children, or none loadable, returns true, so a product is never hidden by
     * a lookup failure.
     */
    private static function product_needs_shipping( WC_Product $p ): bool {
        if ( ! $p->is_type( 'variable' ) ) {
            return $p->needs_shipping();
        }
        $children = $p->get_children();
        if ( empty( $children ) ) {
            return true;
        }
        $inspected = 0;
        foreach ( $children as $child_id ) {
            $variation = wc_get_product( (int) $child_id );
            if ( ! $variation ) {
                continue;
            }
            $inspected++;
            if ( $variation->needs_shipping() ) {
                return true;
            }
        }
        return $inspected > 0 ? false : true;
    }

    private static function postfilter_batch_size(): int {
        return min( 500, max( 25, (int) apply_filters( 'kalicart_bridge_catalog_postfilter_batch_size', 100 ) ) );
    }

    private static function postfilter_candidate_limit(): int {
        return max( 0, (int) apply_filters( 'kalicart_bridge_catalog_postfilter_candidate_limit', 1500 ) );
    }

    private static function query_cache_key( array $args ): string {
        ksort( $args );
        $identity = [
            'blog_id'         => get_current_blog_id(),
            'language'        => KaliCart_Bridge_API::default_language() ?? 'mono',
            'currency'        => get_woocommerce_currency(),
            'tax_display_shop' => get_option( 'woocommerce_tax_display_shop' ),
            'prices_include_tax' => wc_prices_include_tax(),
            'tax_enabled' => wc_tax_enabled(),
            'candidate_limit' => self::postfilter_candidate_limit(),
            'args'            => $args,
        ];
        return hash( 'sha256', (string) wp_json_encode( $identity ) );
    }

    private static function query_cache_ttl(): int {
        return min( 300, max( 0, (int) apply_filters( 'kalicart_bridge_catalog_cache_ttl', 60 ) ) );
    }

    private static function query_cacheable( array $args ): bool {
        // Cache only compact derived scans. Ordinary SQL pages do not need it; full
        // records are larger and depend on more mutable shipping/coupon settings.
        return ( $args['fields'] ?? 'full' ) === 'summary'
               && ( ! empty( $args['gender'] ) || ! empty( $args['color'] )
					|| ( $args['on_sale'] ?? null ) === true
					|| $args['min_price'] !== null || $args['max_price'] !== null );
    }

    private static function query_cache_get( array $args ): ?array {
        if ( self::query_cache_ttl() === 0 || ! self::query_cacheable( $args ) ) {
            return null;
        }
        $bucket = get_transient( self::QUERY_CACHE_TRANSIENT );
        if ( ! is_array( $bucket ) || ! isset( $bucket['entries'] ) || ! is_array( $bucket['entries'] ) ) {
            return null;
        }
        $entry = $bucket['entries'][ self::query_cache_key( $args ) ] ?? null;
        if ( ! is_array( $entry ) || (int) ( $entry['expires'] ?? 0 ) < time() || ! is_array( $entry['value'] ?? null ) ) {
            return null;
        }
        return $entry['value'];
    }

    /**
     * A single LRU-like transient prevents attacker-controlled query strings from
     * creating an unlimited number of database transient rows. Both entry count and
     * serialized byte size are hard bounded.
     */
    private static function query_cache_put( array $args, array $result ): void {
        $ttl = self::query_cache_ttl();
        if ( $ttl === 0 || ! self::query_cacheable( $args ) ) {
            return;
        }

        $max_entries    = min( 64, max( 1, (int) apply_filters( 'kalicart_bridge_catalog_cache_max_entries', 8 ) ) );
        $max_total      = min( 8 * MB_IN_BYTES, max( 64 * KB_IN_BYTES, (int) apply_filters( 'kalicart_bridge_catalog_cache_max_bytes', 512 * KB_IN_BYTES ) ) );
        $max_entry      = min( $max_total, max( 16 * KB_IN_BYTES, (int) apply_filters( 'kalicart_bridge_catalog_cache_max_entry_bytes', 128 * KB_IN_BYTES ) ) );
        $serialized_len = strlen( maybe_serialize( $result ) );
        if ( $serialized_len > $max_entry ) {
            return;
        }

        $now     = time();
        $stored  = microtime( true );
        $bucket  = get_transient( self::QUERY_CACHE_TRANSIENT );
        $entries = is_array( $bucket ) && is_array( $bucket['entries'] ?? null ) ? $bucket['entries'] : [];
        foreach ( $entries as $key => $entry ) {
            if ( ! is_array( $entry ) || (int) ( $entry['expires'] ?? 0 ) < $now ) {
                unset( $entries[ $key ] );
            }
        }

        $entries[ self::query_cache_key( $args ) ] = [
            'stored'  => $stored,
            'expires' => $now + $ttl,
            'bytes'   => $serialized_len,
            'value'   => $result,
        ];
        uasort( $entries, static fn( array $a, array $b ): int => (float) ( $a['stored'] ?? 0 ) <=> (float) ( $b['stored'] ?? 0 ) );

        while ( count( $entries ) > $max_entries || strlen( maybe_serialize( [ 'entries' => $entries ] ) ) > $max_total ) {
            $oldest = array_key_first( $entries );
            if ( $oldest === null ) {
                break;
            }
            unset( $entries[ $oldest ] );
        }

        set_transient( self::QUERY_CACHE_TRANSIENT, [ 'entries' => $entries ], $ttl );
    }

    /**
     * Normalize a single WC_Product into agent-ready array.
     */
    public static function normalize_product( WC_Product $p, string $context = 'list', ?array $projection_context = null ): array {
        // Summary projection: slim listing for agent triage. Returns ONLY the fields an
        // agent needs to shortlist candidates; open /catalog/product/{id} for the few that
        // matter. It performs only the bounded attribute/category work required for
        // gender, then short-circuits before images, color inference, quarantine,
        // purchase_readiness, shipping and variants.
        if ( 'summary' === $context ) {
            $price = self::compute_price( $p );
            if ( is_array( $projection_context ) && array_key_exists( 'gender', $projection_context ) ) {
                // A gender-filtered scan has already paid for inference. Reuse its
                // evidence instead of repeating attribute/category work on the page.
                $gender     = $projection_context['gender'];
                $cat_terms  = get_the_terms( $p->get_id(), 'product_cat' );
                $categories = ( $cat_terms && ! is_wp_error( $cat_terms ) )
                    ? array_values( wp_list_pluck( self::stable_category_terms( $cat_terms ), 'slug' ) )
                    : [];
            } else {
                // Gender is part of the compact contract even when it is unknown.
                // The extra inference work is bounded by per_page, never catalog size.
                $category_records = self::get_product_categories( $p );
                $categories       = array_values( array_column( $category_records, 'slug' ) );
                $attributes       = self::get_normalized_attributes( $p );
                $tags             = self::get_product_tags( $p );
                $gender           = self::infer_gender( $p, $category_records, $tags, $attributes );
            }

            // PRICE-INTERVAL-v1 — `current` is the LOWEST ACTIVE price and `regular`
            // falls on `min_regular` for variable products: both are ENDS, not "the
            // price". Without `type` and `max_current` the agent cannot tell from the
            // summary, and it is exactly here that agent_instructions tell it to
            // triage; the interval was readable only inside the `display`
            // string, which is not computable. The high ends are ADDED only
            // on type=range: on a fixed price they would repeat current/regular for every
            // product of the catalog, and fields=summary exists to stay light. Same
            // choice as the Global card, which omits them on fixed prices.
            $price_type  = $price['type'] ?? 'fixed';
            $group_raw   = self::group_raw( $p );   // zero-cost read: ids and minimums, no product loaded
            $price_block = [
                'currency' => get_woocommerce_currency(),
                'encoding' => 'decimal_major_units',
                'type'    => $price_type,
                'current' => $price['current'] ?? null,
                'display' => $price['display'] ?? null,
                'regular' => $price['regular'] ?? $price['min_regular'] ?? null,
                'vat_included' => $price['vat_included'] ?? null,
                'tax_enabled' => $price['tax_enabled'] ?? null,
            ];
            if ( 'range' === $price_type ) {
                // `range_over` tells apart the two intervals the summary can
                // carry: on a variable product you choose ONE, on a grouped one you
                // buy the components one by one. Without it, the ends are
                // identical and triage cannot know what it is comparing.
                $price_block['range_over']  = $price['range_over'] ?? 'variants';
                $price_block['max_current'] = $price['max_current'] ?? null;
                $price_block['max_regular'] = $price['max_regular'] ?? null;
            }
            $price_block += [
                'on_sale'    => (bool) ( $price['on_sale'] ?? false ),
                'sale_scope' => $price['sale_scope'] ?? ( ! empty( $price['on_sale'] ) ? 'single_product' : 'none' ),
                'discounted_variations_count' => $price['discounted_variations_count'] ?? null,
                'priced_variations_count'     => $price['priced_variations_count'] ?? null,
                'variant_selection_required_for_sale' => (bool) ( $price['variant_selection_required_for_sale'] ?? false ),
            ];

            $delivery = self::product_delivery( $p );

            return [
                'id'         => $p->get_id(),
                'sku'        => $p->get_sku() ?: null,
                'name'       => $p->get_name(),
                'brand'      => self::resolve_brand( $p ),
                'url'        => get_permalink( $p->get_id() ),
                'price'      => $price_block,
                'stock'      => [ 'in_stock' => $p->is_in_stock() ],
                // SHIPPING-REQUIRED-v1 — says whether WooCommerce would ask for a shipping
                // address, and ONLY that: it is not "physical versus digital". A
                // downloadable product that ships anyway stays true, and store pickup
                // does not distinguish it at all, because Woo models it as a shipping
                // method and not as a property of the product. For the three answers that
                // are really needed there is `fulfilment`. It is the ONE shipping
                // fact the summary carries: the quote, zones and thresholds stay in
                // /catalog/product/{id}. Without it an agent that must reason about
                // physical goods has to open every candidate one by one to find out.
                'shipping_required' => $delivery['shipping_required'],
                // FULFILMENT-v1 — how it is obtained: shipped | downloadable | virtual | pickup_only.
                // The catalog is the mirror of the store, so it does not hide a
                // product because it does not ship: it says what arrives at home, what is
                // downloaded and what is picked up on site. It is in the summary because that is
                // where the agent chooses, and choosing without it means proposing
                // a delivery that does not exist.
                'fulfilment' => $delivery['fulfilment'],
                // SOURCE-DELIVERY-v1 (1.0.136) — the two WooCommerce facts from which
                // `fulfilment` is derived, exactly as the merchant ticked them.
                // `fulfilment` answers "how do you get it" and to do so it must
                // summarize: a downloadable but not virtual product becomes
                // `shipped`, because WooCommerce ships it. Without the two flags, a
                // reader cannot notice that this `shipped` carries a
                // download — a boxed game with a digital bonus, or a
                // digital game missing the "virtual" tick (the case that
                // opened this item, 2026-09-21). The Bridge does not decide which of the
                // two: it exposes it, and the reader decides.
                'virtual'      => $delivery['virtual'],
                'downloadable' => $delivery['downloadable'],
                // DISCOVERY-SCOPE-v1 — the Bridge STATES what the store states.
                // A product outside the catalog but in search must not be inferred
                // from its absence elsewhere: an agent that does not see it while browsing cannot
                // know whether it does not exist, is sold out or the merchant removed it
                // from the shop window. They are three different conclusions and only one is true.
                // The two booleans are the two WooCommerce terms made readable,
                // one by one, without adding meaning.
                'discovery' => [
                    'in_catalog' => self::product_in_catalog( $p ),
                    'in_search'  => self::product_in_search( $p ),
                ],
                'categories' => $categories,
                'gender'     => $gender,
                'type'       => $p->get_type(),
                // SCELTA-RICHIESTA-v1 (1.0.135) — it was `is_type('variable')`, that is
                // only one of the three reasons why a product cannot be bought straight away. On
                // grouped #489 the summary said selection_required=false next to
                // a price that was the minimum of 73-797: an agent in triage
                // took it for a one-click purchase at 73 €. The other two reasons
                // are components to choose (bundles with optional items) and groups
                // sold piece by piece, which are read here without loading anything:
                // the minimum is already in the meta, and we just computed
                // the interval. The summary stays light and stops lying.
                'selection_required' => 'range' === $price_type
                    || ( $group_raw !== null && ! empty( $group_raw['has_optional'] ) ),
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
        $quarantine         = self::compute_quarantine_flags( $p, $categories, $images, $price_data );
        $stock              = self::compute_stock( $p );           // computed once
        $purchase_readiness = self::compute_purchase_readiness( $p ); // computed once
        $barcodes           = self::get_barcodes( $p );            // computed once
        $group              = self::group_components( $p, $context ); // null when it is not a group
        // variations only in detail context — avoids N×get_variations() queries in list/search
        $variations = ( $context === 'detail' && $type === 'variable' ) ? self::get_variations( $p ) : null;

        // variants[] — detail: full list; list: lightweight single entry, empty array for variable (UCP: always an array)
        //
        // RIGA-SINTETICA-v1 (1.0.135) — the single entry with `variation_id === id` is
        // the promise that there is ONE purchasable thing at ONE price. On grouped #489
        // it was false twice: the purchasable thing does not exist (you buy the three
        // components) and the price was the minimum of a 73-797 interval. An agent
        // reading variants[0].price took 73 € for an item worth 797.
        //
        // The condition is not on the type — that would be yet another patch, and tomorrow
        // the type we did not foresee arrives — but on the price we
        // just computed: the row is emitted where the price is ONE. Where it is an
        // interval, variants stays [] and the truth is in `group.components`
        // (groups) or in `variations` (variable products, detail context).
        $single_priced = ( $price_data['type'] ?? 'fixed' ) === 'fixed';

        if ( $context === 'detail' ) {
            $variants = $type === 'variable'
                ? $variations
                : ( ! $single_priced ? [] : [ [
                    'variation_id'        => $id,
                    'attributes'          => [],
                    'price'               => $price_data,
                    'in_stock'            => $p->is_in_stock(),
                    'availability_status' => $p->is_in_stock() ? 'in_stock' : 'out_of_stock',
                    'sku'                 => $p->get_sku() ?: null,
                    'barcodes'            => $barcodes,
                ] ] );
        } else {
            // list context: a single priced product gets one synthetic variant;
            // variable products and priced ranges get [] (use /product/{id}).
            $variants = ( $type !== 'variable' && $single_priced )
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
            // The surface exposes, it does not interpret: Global receives the visibility
            // state as it is and can decide with data instead of by
            // deduction. Today 0 products out of 5,373 federated carry it.
            'catalog_visibility' => $p->get_catalog_visibility(),
            'discovery' => [
                'in_catalog' => self::product_in_catalog( $p ),
                'in_search'  => self::product_in_search( $p ),
                'note' => self::product_in_catalog( $p )
                    ? ( self::product_in_search( $p )
                        ? 'Listed in the shop catalog and findable by search, like any ordinary product.'
                        : 'The merchant lists this product in the shop catalog but keeps it out of search results. It is on the shelf; it is just not returned for a text query.' )
                    : 'The merchant keeps this product out of the shop catalog but findable by search. It is on sale and purchasable: it is simply not browsed to, it is looked up. Do not read its absence from category listings as unavailability.',
            ],
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
            // GROUPS, NAMED FOR WHAT THEY ARE (1.0.135) — `null` for anything that is not
            // a group. Before this version the payload did not contain the word
            // "component": a bundle was an ordinary product with a price and
            // no content, and the buyer did not know what they were buying.
            'group'              => $group,
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
        static $cached = null;
        if ( $cached !== null ) return $cached;
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
        $free_shipping_unconditional = false;
        $free_shipping_coupon_only   = false;
        foreach ( $zones as $zone ) {
            $methods = [];
            $zone_id = (int) ( $zone['zone_id'] ?? 0 );
            // Woo filters methods whose plugin is not loaded. Count the enabled raw
            // rows too, so an omitted shipping method cannot look like no shipping.
            $zone_object = new WC_Shipping_Zone( $zone_id );
            $raw_methods = $zone_object->get_data_store()->get_methods( $zone_id, true );
            $represented_ids = [];
            foreach ( (array) ( $zone['shipping_methods'] ?? [] ) as $method ) {
                if ( ! is_object( $method ) || isset( $method->enabled ) && $method->enabled !== 'yes' ) {
                    continue;
                }
                $method_id = (string) ( $method->id ?? '' );
                if ( ! in_array( $method_id, [ 'free_shipping', 'flat_rate', 'local_pickup' ], true ) ) {
                    continue;
                }
                $represented_ids[] = (int) ( $method->instance_id ?? 0 );
                $settings = is_callable( [ $method, 'get_instance_form_fields' ] ) ? (array) $method->instance_settings : [];
                $row = [
                    'method_id' => $method_id,
                    'instance_id' => isset( $method->instance_id ) ? (int) $method->instance_id : null,
                    'title' => is_callable( [ $method, 'get_title' ] ) ? $method->get_title() : ( $method->title ?? $method_id ),
                    'enabled' => true,
                ];
                if ( $method_id === 'free_shipping' ) {
                    // REAL THRESHOLD OR NOTHING (1.0.135) — here every non-null
                    // `min_amount` was collected, zero included, and
                    // `requires` was ignored. Two consequences measured on 2026-09-18 on
                    // a store that has TWO free_shipping methods:
                    //   requires='coupon'      min_amount=0   -> threshold "0"
                    //   requires='min_amount'  min_amount=99  -> threshold 99
                    // That zero entered the list and made every product
                    // "already eligible by price", while next to it the text still said
                    // how much was missing to 99. Result: 1,207 products, 22% of the
                    // federation, saying at the same time "you are entitled to free
                    // shipping" and "you are 96.01 short".
                    //
                    // And the real defect is not the zero: it is that this method requires a
                    // COUPON. Announcing it as free by price tells an
                    // agent something the store does not grant.
                    //
                    // `requires` means: '' or 'min_amount' -> the price is enough;
                    // 'either' -> coupon OR minimum, so the price is enough;
                    // 'coupon' or 'both' -> the price alone is never enough.
                    $min = isset( $method->min_amount ) && $method->min_amount !== '' ? (float) $method->min_amount : null;
                    $requires = (string) ( $method->requires ?? '' );
                    $row['requires'] = $method->requires ?? null;
                    $row['min_amount'] = $min;
                    $price_alone_suffices = in_array( $requires, [ '', 'min_amount', 'either' ], true );
                    if ( $price_alone_suffices && $min !== null && $min > 0 ) {
                        $free_thresholds[] = $min;
                    }
                    if ( $price_alone_suffices && ( $min === null || $min <= 0 ) ) {
                        // No minimum to reach: it is not a zero threshold, it is
                        // free shipping with no amount condition.
                        $free_shipping_unconditional = true;
                    }
                    if ( in_array( $requires, [ 'coupon', 'both' ], true ) ) {
                        $free_shipping_coupon_only = true;
                    }
                } elseif ( $method_id === 'flat_rate' ) {
                    $row['cost'] = isset( $method->cost ) && $method->cost !== '' ? self::normalize_cost( $method->cost ) : null;
                    $row['calculation_type'] = (string) ( $method->type ?? 'class' );
                    $class_costs = [];
                    foreach ( $settings as $key => $value ) {
                        if ( ( $key === 'no_class_cost' || preg_match( '/^class_cost_[A-Za-z0-9_-]+$/', (string) $key ) ) && $value !== '' && $value !== null ) {
                            $class_costs[ $key ] = self::normalize_cost( $value );
                        }
                    }
                    $row['class_costs'] = $class_costs;
                } elseif ( isset( $method->cost ) && $method->cost !== '' ) {
                    $row['cost'] = self::normalize_cost( $method->cost );
                }
                if ( ! empty( $settings ) ) {
                    $row['settings_public_note'] = 'Method has WooCommerce settings; exact final price remains checkout authority.';
                }
                $methods[] = $row;
            }
            $unexported_methods = 0;
            foreach ( (array) $raw_methods as $raw_method ) {
                if ( ! in_array( (int) ( $raw_method->instance_id ?? 0 ), $represented_ids, true ) ) {
                    ++$unexported_methods;
                }
            }
            $zone_row = [
                'id' => $zone_id,
                'name' => (string) ( $zone['zone_name'] ?? 'Rest of the world' ),
                'order' => $zone_id === 0 ? null : (int) ( $zone['zone_order'] ?? 0 ),
                'rest_of_world' => $zone_id === 0,
                'zones_complete' => true,
                'locations' => self::normalize_shipping_zone_locations( (array) ( $zone['zone_locations'] ?? [] ) ),
                'unexported_methods' => $unexported_methods,
            ];
            if ( $zone_id !== 0 && empty( $zone['zone_locations'] ) ) {
                $zone_row['locations_note'] = 'Zone has no explicit regions configured in WooCommerce. Coverage cannot be inferred from this document — WooCommerce checkout determines applicability.';
            }
            $zone_row['methods'] = $methods;
            $out_zones[] = $zone_row;
        }

        $free_thresholds = array_values( array_unique( array_filter( $free_thresholds, fn( $v ) => $v !== null ) ) );
        sort( $free_thresholds, SORT_NUMERIC );

        $cached = [
            'available' => true,
            'currency' => get_woocommerce_currency(),
            'authority' => 'woocommerce_checkout',
            'calculation_model' => 'policy_snapshot_not_destination_quote',
            'free_shipping_available' => ! empty( $free_thresholds ),
            'free_shipping_thresholds' => $free_thresholds,
            'free_shipping_unconditional' => $free_shipping_unconditional,
            'free_shipping_requires_coupon' => $free_shipping_coupon_only,
            'zones_complete' => true,
            'zones' => $out_zones,
            'note' => 'Use this policy for agent reasoning. Exact shipping cost depends on destination, cart contents, coupons and WooCommerce checkout rules; checkout remains final authority.',
        ];
        return $cached;
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

        // Product and merchant views must come from the same ordered Woo zones.
        $policy = self::merchant_shipping_policy();
        $out = [];
        foreach ( (array) ( $policy['zones'] ?? [] ) as $zone ) {
            $methods = [];
            $free_threshold = null;
            foreach ( (array) ( $zone['methods'] ?? [] ) as $method ) {
                $id = (string) ( $method['method_id'] ?? '' );
                $row = [
                    'id' => $id,
                    'title' => $method['title'] ?? null,
                    'currency' => $policy['currency'] ?? null,
                ];
                if ( $id === 'free_shipping' ) {
                    $row['cost'] = 0;
                    $row['min_amount'] = $method['min_amount'] ?? null;
                    $row['requires'] = $method['requires'] ?? null;
                    if ( in_array( (string) $row['requires'], [ '', 'min_amount', 'either' ], true ) && (float) $row['min_amount'] > 0 ) {
                        $free_threshold = $free_threshold === null ? (float) $row['min_amount'] : min( $free_threshold, (float) $row['min_amount'] );
                    }
                } elseif ( $id === 'flat_rate' ) {
                    $row['cost'] = $method['cost'] ?? null;
                    $row['calculation_type'] = $method['calculation_type'] ?? null;
                    $row['class_costs'] = $method['class_costs'] ?? [];
                } elseif ( $id === 'local_pickup' ) {
                    $row['cost'] = $method['cost'] ?? 0;
                }
                $methods[] = $row;
            }
            $out[] = [
                'id' => $zone['id'],
                'zone' => $zone['name'],
                'order' => $zone['order'],
                'rest_of_world' => $zone['rest_of_world'],
                'zones_complete' => true,
                'locations' => array_values( array_map( fn( $loc ) => $loc['code'], $zone['locations'] ) ),
                'locations_typed' => $zone['locations'],
                'methods' => $methods,
                'unexported_methods' => $zone['unexported_methods'],
                'free_threshold' => $free_threshold,
            ];
        }

        $cached = $out;
        return $out;
    }

    /** The product appears on the store's shelves (browsing and categories). */
    private static function product_in_catalog( WC_Product $p ): bool {
        return ! in_array( $p->get_catalog_visibility(), [ 'search', 'hidden' ], true );
    }

    /** The product can be found by searching for it. */
    private static function product_in_search( WC_Product $p ): bool {
        return ! in_array( $p->get_catalog_visibility(), [ 'catalog', 'hidden' ], true );
    }

    /**
     * How the product is obtained, decided by the PRODUCT and not by the store.
     *
     *   shipped       WooCommerce ships it (needs_shipping)
     *   downloadable  not shipped, downloaded
     *   virtual       not shipped, not downloaded, and VIRTUAL: a key,
     *                 an access, a service, a course
     *   pickup_only   not shipped, not downloaded and NOT virtual: WooCommerce
     *                 says an object that exists is not to be shipped — it is picked up
     *
     * CONSEGNA-VIRTUALE-v1 (1.0.136). Up to 1.0.135 the third case did not
     * exist: everything that was neither shipped nor downloaded was `pickup_only`,
     * "physical object to pick up on site". The reasoning ("otherwise what would
     * you pick up?") held for an object, but for WooCommerce "virtual" means
     * INTANGIBLE. Measured on 2026-09-22: on one store 476 `pickup_only` products
     * were courses and consultations; and a Steam key — the most
     * common way to sell a digital game — is virtual and not downloadable, so
     * it was described as a box to pick up. An object picked up in
     * store is not virtual: it is a physical product with pickup among the shipping
     * methods, and WooCommerce ships it (`shipped`, with pickup listed
     * among the methods). `pickup_only` remains for the only case where it is true.
     *
     * `virtual` and `downloadable` are the merchant's flags, read with
     * `is_virtual()` / `is_downloadable()`, NOT derived from `needs_shipping()`: a
     * filter on `woocommerce_product_needs_shipping` separates them, and it is exactly
     * when they separate that they are needed.
     *
     * On a variable product the variations decide: the parent always says "physical" (see
     * product_needs_shipping()) and never "downloadable" — WC_Product_Variable::
     * get_downloadable() returns false unconditionally since Woo 11.1.0:
     * it ships if any variation ships; it is virtual / downloadable only if
     * all of them are. The walk stops as soon as the three answers are decided, so an
     * ordinary physical variable product costs ONE variation load, as before;
     * only an entirely digital product pays for the full walk. Unknown -> physical:
     * without readable variations the product is `shipped`, never hidden because of a
     * read error.
     */
    private static function product_delivery( WC_Product $p ): array {
        if ( ! $p->is_type( 'variable' ) ) {
            $ships        = $p->needs_shipping();
            $virtual      = $p->is_virtual();
            $downloadable = $p->is_downloadable();
        } else {
            $ships = false; $virtual = true; $downloadable = true; $inspected = 0;
            foreach ( $p->get_children() as $child_id ) {
                $variation = wc_get_product( (int) $child_id );
                if ( ! $variation ) {
                    continue;
                }
                $inspected++;
                $ships        = $ships || $variation->needs_shipping();
                $virtual      = $virtual && $variation->is_virtual();
                $downloadable = $downloadable && $variation->is_downloadable();
                if ( $ships && ! $virtual && ! $downloadable ) {
                    break;
                }
            }
            if ( 0 === $inspected ) {
                $ships = true; $virtual = false; $downloadable = false;
            }
        }

        if ( $ships ) {
            $fulfilment = 'shipped';
        } elseif ( $downloadable ) {
            $fulfilment = 'downloadable';
        } elseif ( $virtual ) {
            $fulfilment = 'virtual';
        } else {
            $fulfilment = 'pickup_only';
        }

        return [
            'shipping_required' => $ships,
            'virtual'           => $virtual,
            'downloadable'      => $downloadable,
            'fulfilment'        => $fulfilment,
        ];
    }


    /**
     * The store pickup methods configured by the merchant, if any.
     * The Bridge already collects the zones; here only pickup is extracted, which is
     * the only documented way to obtain a product that is not shipped.
     */
    private static function local_pickup_methods(): array {
        $out = [];
        foreach ( self::get_shipping_zones() as $zone ) {
            foreach ( $zone['methods'] ?? [] as $method ) {
                if ( ( $method['id'] ?? $method['method_id'] ?? '' ) !== 'local_pickup' ) {
                    continue;
                }
                $out[] = [
                    'zone'  => $zone['zone'] ?? $zone['name'] ?? null,
                    'title' => $method['title'] ?? null,
                ];
            }
        }
        return $out;
    }

    private static function product_shipping_policy( WC_Product $p, array $price_data ): array {
        // FULFILMENT-COHERENCE-v1 — a product that does not ship cannot
        // carry the store's shipping conditions. Before this
        // version the block said `shipping_required: false` and on the next line
        // `free_shipping_available: true` with the amount missing to the threshold: two facts
        // contradicting each other in the same object, and an agent reading the
        // second concludes the opposite of the first. The store policy applies to
        // what the store ships; for the rest it says how the product is obtained.
        $delivery = self::product_delivery( $p );
        if ( ! $delivery['shipping_required'] ) {
            $fulfilment  = $delivery['fulfilment'];
            $is_download = 'downloadable' === $fulfilment;
            $is_virtual  = 'virtual' === $fulfilment;
            // Store pickup concerns only what exists physically: it does not apply
            // to a download or a key, and naming it would suggest
            // that there is something to go and collect.
            $pickup      = ( $is_download || $is_virtual ) ? [] : self::local_pickup_methods();
            return [
                'shipping_required' => false,
                'fulfilment' => $fulfilment,
                'virtual' => $delivery['virtual'],
                'downloadable' => $delivery['downloadable'],
                'local_pickup_available' => ! empty( $pickup ),
                'local_pickup' => $pickup ?: null,
                'delivery_note' => $is_download
                    ? 'This product is downloaded, not delivered. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.'
                    : ( $is_virtual
                    ? 'This product is virtual: it is neither shipped nor downloaded, and is obtained as a code, an access or a service provided by the merchant. Nothing is collected in store. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.'
                    : ( $pickup
                        ? 'This product is not shipped and is not a download: it is a physical item collected in store. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.'
                        : 'This product is not shipped and is not a download, so it is collected from the merchant. The store has no local pickup method configured, so the collection point is not described here: ask the merchant. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.' ) ),
                'zones'     => [],
                'authority' => 'woocommerce_checkout',
                'note' => 'Fulfilment for a product WooCommerce reports as not requiring shipping. Store shipping conditions are deliberately omitted: they do not apply here.',
            ];
        }

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
            'shipping_required' => true,
            'fulfilment' => 'shipped',
            'virtual' => $delivery['virtual'],
            'downloadable' => $delivery['downloadable'],
            'shipping_class' => $p->get_shipping_class() ?: null,
            'weight' => $p->get_weight() ? (float) $p->get_weight() : null,
            'weight_unit' => get_option( 'woocommerce_weight_unit', 'kg' ),
            'free_shipping_available' => (bool) ( $policy['free_shipping_available'] ?? false ),
            'free_shipping_thresholds' => $thresholds,
            // Thresholds now contain only REAL minimums reachable by price
            // alone (see merchant_shipping_policy). The two cases that used to hide
            // in there disguised as zero have a field of their own: the
            // block states which condition applies, it does not fill the fields with null.
            'free_shipping_unconditional' => (bool) ( $policy['free_shipping_unconditional'] ?? false ),
            'free_shipping_requires_coupon' => (bool) ( $policy['free_shipping_requires_coupon'] ?? false ),
            'free_shipping_eligible_by_product_price' => ! empty( $policy['free_shipping_unconditional'] )
                ? true
                : ( $price !== null && ! empty( $thresholds )
                    ? array_reduce( $thresholds, fn( $carry, $t ) => $carry || $price >= (float) $t, false )
                    : null ),
            'amount_to_nearest_free_shipping_threshold' => ! empty( $policy['free_shipping_unconditional'] )
                ? null
                : ( ( $price !== null && $nearest !== null ) ? max( 0, round( $nearest - $price, 2 ) ) : null ),
            'free_shipping_note' => ! empty( $policy['free_shipping_requires_coupon'] )
                ? 'The store also offers free shipping that requires a coupon. That path is not reachable by cart value alone and is not reflected in the thresholds above.'
                : null,
            'zones'     => self::get_shipping_zones(),
            'zones_complete' => true,
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



    /** @var array<string,string> request-local cache of term names, keyed taxonomy|slug */
    private static $term_label_cache = [];

    private static function variation_value_label( string $attribute, string $value ): string {
        if ( ! taxonomy_exists( $attribute ) ) {
            return $value;
        }
        $ck = $attribute . '|' . $value;
        if ( ! array_key_exists( $ck, self::$term_label_cache ) ) {
            $term = get_term_by( 'slug', $value, $attribute );
            self::$term_label_cache[ $ck ] = ( $term && ! is_wp_error( $term ) )
                ? html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
                : $value;
        }
        return self::$term_label_cache[ $ck ];
    }

    private static function variation_attribute_name( string $attribute, WC_Product $p ): string {
        return html_entity_decode( wc_attribute_label( $attribute, $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    private static function get_variations( WC_Product $p ): array {
        if ( ! $p->is_type( 'variable' ) ) return [];
        // A non-variation color with exactly one merchant value applies to every
        // variant. A multi-value or selectable parent color never does.
        $fixed_color_attrs = array_values( array_filter(
            self::get_normalized_attributes( $p ),
            static fn( $a ) => empty( $a['variation'] ) && count( $a['values'] ?? [] ) === 1
                && self::is_color_attribute( $a['key'] ?? '', $a['name'] ?? '' )
        ) );
        $fixed_colors = array_map(
            static fn( $c ) => $c + [ 'scope' => 'product_fixed_attribute' ],
            self::extract_colors( $fixed_color_attrs, '', [] )
        );
        $out = [];
        foreach ( $p->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->exists() ) continue;
            $attrs  = [];
            $labels = [];
            $names  = [];
            $variant_color_attrs = [];
            $has_color_choice = false;
            foreach ( $variation->get_variation_attributes() as $key => $value ) {
                $clean_key = str_replace( 'attribute_', '', $key );
                $attrs[ $clean_key ] = $value ?: null; // null = any value
                // 1.0.147: the merchant's own wording next to the stored value. For a
                // taxonomy attribute the stored value is the term slug ("15-lt"); the
                // label is the term name ("1,5 lt"). For a custom attribute the stored
                // value is already the merchant's text. Exposed, never interpreted.
                $labels[ $clean_key ] = $value ? self::variation_value_label( $clean_key, (string) $value ) : null;
                $names[ $clean_key ]  = self::variation_attribute_name( $clean_key, $p );
                if ( self::is_color_attribute( $clean_key, $names[ $clean_key ] ) ) {
                    $has_color_choice = true;
                    if ( $value !== '' ) {
                        $variant_color_attrs[] = [
                            'key' => $clean_key,
                            'name' => $names[ $clean_key ],
                            // The merchant term label is preferred; the stored slug
                            // remains available if the label is not a known family.
                            'values' => array_values( array_unique( array_filter( [
                                $labels[ $clean_key ], (string) $value,
                            ], static fn( $v ) => $v !== null && $v !== '' ) ) ),
                        ];
                    }
                }
            }
            $variant_colors = $has_color_choice
                ? array_map(
                    static fn( $c ) => $c + [ 'scope' => 'variation_attribute' ],
                    self::extract_colors( $variant_color_attrs, '', [] )
                )
                : $fixed_colors;
            $price_data   = self::compute_price( $variation );
            $var_in_stock = $variation->is_in_stock();
            $var_manage   = $variation->managing_stock();
            $var_qty      = $var_manage ? $variation->get_stock_quantity() : null;
            $out[] = [
                'variation_id'        => $variation_id,
                'attributes'          => $attrs,
                'attribute_labels'    => $labels,
                'attribute_names'     => $names,
                'colors'              => $variant_colors,
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

    /**
     * A GROUP IS A GROUP (1.0.135) — WooCommerce models several products sold
     * together, and the Bridge did not model it at all: it squeezed them into the shape of
     * variable products, which means the opposite. On a variable product `variants` means
     * "pick one"; on a group it would mean "you take them all". Same key,
     * reversed meaning, and no signal to tell them apart.
     *
     * Measured on 2026-09-17 on a grouped product and a WPC bundle of the test store: the
     * phantom entry in `variants` had `variation_id` EQUAL to the product id and
     * empty `attributes`, `variation_summary` was null, and the components did not
     * appear anywhere. An agent read a bundle at 245,10 € without knowing
     * that it is four products, nor which ones.
     *
     * THERE IS NOT A SINGLE READ PATH and there cannot be: native `grouped`
     * keeps the children in get_children(), WPC Product Bundles in the `woosb_ids` meta,
     * WooCommerce Product Bundles in its own API. They are declared, narrow
     * readers — not patches — because the EXPOSED shape stays one: this block.
     *
     * Of the third type we know the name but do not have the plugin (paid) and
     * so we cannot test it: it is declared a group with `resolved: false`
     * instead of guessing its format. The payload says what we know, and also
     * says what it does not know.
     */
    /**
     * RAW READ — who the components are, without loading even one.
     *
     * Used where cost matters (fields=summary runs on every catalog row):
     * ids, quantities and minimums are already in the meta or in get_children(),
     * so knowing WHETHER a choice is required must not cost N wc_get_product calls.
     * The real load stays in group_components(), which calls this.
     */
    private static function group_raw( WC_Product $p ): ?array {
        static $memo = [];
        $id = $p->get_id();
        if ( array_key_exists( $id, $memo ) ) {
            return $memo[ $id ];
        }

        $out  = null;
        $type = $p->get_type();

        if ( $p->is_type( 'grouped' ) ) {
            $items = [];
            foreach ( (array) $p->get_children() as $child_id ) {
                $items[ (int) $child_id ] = [ 'qty' => 1, 'min' => null, 'max' => null ];
            }
            $out = [ 'group_type' => 'grouped', 'resolved' => true, 'items' => $items, 'has_optional' => false ];
        } else {
            $woosb = get_post_meta( $id, 'woosb_ids', true );
            if ( ! empty( $woosb ) ) {
                $items    = [];
                $optional = false;
                $list     = is_array( $woosb ) ? $woosb : maybe_unserialize( $woosb );
                foreach ( (array) $list as $item ) {
                    if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
                    $min = isset( $item['min'] ) && $item['min'] !== '' ? (int) $item['min'] : null;
                    if ( $min !== null && $min === 0 ) { $optional = true; }
                    $items[ (int) $item['id'] ] = [
                        'qty' => isset( $item['qty'] ) && $item['qty'] !== '' ? (int) $item['qty'] : 1,
                        'min' => $min,
                        'max' => isset( $item['max'] ) && $item['max'] !== '' ? (int) $item['max'] : null,
                    ];
                }
                $out = [ 'group_type' => 'woosb', 'resolved' => true, 'items' => $items, 'has_optional' => $optional ];
            } elseif ( in_array( $type, [ 'bundle', 'yith_bundle', 'composite' ], true ) ) {
                $out = [ 'group_type' => $type, 'resolved' => false, 'items' => [], 'has_optional' => false ];
            }
        }

        $memo[ $id ] = $out;
        return $out;
    }

    /**
     * GROUP DISCOUNT — what the group takes off ON ITS OWN, on top of the components.
     *
     * On a WPC bundle of the test store the chain is 266 -> 258
     * -> 245,10: the components' list price, the sum of their current prices (they are
     * discounted individually), and finally the 5% the bundle takes off on top. The
     * payload published only the ends, that is a single 7.9%, and the 258 in the middle
     * disappeared. An agent could not answer the question that matters — is
     * the bundle worth it or is buying them separately? — because the two discounts were merged into one.
     * Here only the missing term is read; the other two are already given by
     * group_components(). The surface exposes the three numbers, not the conclusion.
     */
    private static function group_own_discount( WC_Product $p, string $group_type ): ?array {
        if ( $group_type !== 'woosb' ) {
            return null;
        }
        if ( get_post_meta( $p->get_id(), 'woosb_disable_auto_price', true ) === 'on' ) {
            // The bundle price is written by hand: there is no discount to
            // declare, there is a price. It is already in price.current.
            return null;
        }
        $pct = get_post_meta( $p->get_id(), 'woosb_discount', true );
        if ( $pct !== '' && $pct !== null && (float) $pct > 0 ) {
            return [
                'type'       => 'percentage',
                'value'      => (float) $pct,
                'applies_to' => 'components_current_total',
            ];
        }
        $amt = get_post_meta( $p->get_id(), 'woosb_discount_amount', true );
        if ( $amt !== '' && $amt !== null && (float) $amt > 0 ) {
            return [
                'type'       => 'fixed_amount',
                'value'      => (float) $amt,
                'applies_to' => 'components_current_total',
            ];
        }
        return null;
    }

    private static function group_components( WC_Product $p, string $context = 'list' ): ?array {
        static $memo = [];
        $key = $p->get_id() . '|' . $context;
        if ( array_key_exists( $key, $memo ) ) {
            return $memo[ $key ];
        }

        $raw = self::group_raw( $p );
        if ( $raw === null ) {
            return $memo[ $key ] = null;
        }

        // The third type: we know the name, not the format, and the plugin is
        // paid — we cannot test it. It is declared a group with
        // `resolved: false` instead of guessing. The keys stay the same as in the
        // resolved case: a reader must not branch on the presence of fields,
        // `resolved` is enough to know which ones to trust.
        if ( ! $raw['resolved'] ) {
            return $memo[ $key ] = [
                'group_type'               => $raw['group_type'],
                'resolved'                 => false,
                'sold_as'                  => $p->is_purchasable() ? 'one_item' : 'individual_components',
                'components_count'         => null,
                'components'               => [],
                'components_list_total'    => null,
                'components_current_total' => null,
                'group_discount'           => null,
                'agent_note'               => 'This product is a group sold together, but the Bridge cannot read its components: they are stored by a plugin whose format it does not support. Treat price as the price of the whole group and open the product page for the contents.',
            ];
        }

        $components    = [];
        $list_total    = 0.0;
        $current_total = 0.0;
        $totals_known  = ! empty( $raw['items'] );
        foreach ( $raw['items'] as $cid => $meta ) {
            $c = wc_get_product( $cid );
            if ( ! $c ) { $totals_known = false; continue; }
            $qty     = max( 1, (int) $meta['qty'] );
            $regular = $c->get_regular_price() !== '' ? (float) $c->get_regular_price() : null;
            $current = $c->get_price() !== '' ? (float) $c->get_price() : null;
            if ( $regular === null || $current === null ) { $totals_known = false; }
            else { $list_total += $regular * $qty; $current_total += $current * $qty; }
            $row = [
                'product_id'   => (int) $cid,
                'sku'          => $c->get_sku() ?: null,
                'name'         => $c->get_name(),
                'quantity'     => $qty,
                'min_quantity' => $meta['min'],
                'max_quantity' => $meta['max'],
                'optional'     => $meta['min'] !== null && (int) $meta['min'] === 0,
                'in_stock'     => $c->is_in_stock(),
            ];
            if ( $context === 'detail' ) {
                $row['url']           = get_permalink( $cid ) ?: null;
                $row['price_current'] = $current;
                $row['price_regular'] = $regular;
            }
            $components[] = $row;
        }

        $sold_as = $p->is_purchasable() ? 'one_item' : 'individual_components';

        return $memo[ $key ] = [
            'group_type'               => $raw['group_type'],
            'resolved'                 => true,
            'sold_as'                  => $sold_as,
            'components_count'         => count( $components ),
            'components'               => $components,
            'components_list_total'    => $totals_known ? round( $list_total, 2 ) : null,
            'components_current_total' => $totals_known ? round( $current_total, 2 ) : null,
            'group_discount'           => self::group_own_discount( $p, $raw['group_type'] ),
            'agent_note'               => $sold_as === 'one_item'
                ? 'Sold as one item: price.current is what the buyer pays for the whole group. The saving has two separate parts, and merging them misreads the offer: components_list_total is what the components list at, components_current_total is what the same items cost bought separately TODAY (their own sales included), and group_discount is what this group takes off on top of that. Compare components_current_total with price.current to answer "is the group worth it compared with buying them separately".'
                : 'A group of products presented together: it is NOT bought as one item, each component is purchased on its own, and price is the range of the components. components_current_total is the cost of taking all of them, not a price to quote.',
        ];
    }

    private static function compute_purchase_readiness( WC_Product $p ): array {
        $type = $p->get_type();

        // ESAURITO-E-ESAURITO-v1 (1.0.135) — this check was there TWICE, in the
        // variable branch and in the group branch, identical word for word, and
        // it was missing from the simple branch. So the same fact came out with two
        // different vocabularies: measured on 2026-09-18 on the test store, #426 is an
        // out-of-stock `simple` and received `requires_product_page` with the reason
        // "external product, or not purchasable in its current state" — vague where
        // an exact one existed, and false on top of that: it is not external, and the
        // product is purchasable. It is sold out. An out-of-stock variable, in the same
        // response, received `out_of_stock`.
        //
        // "Out of stock" is not a property of the product TYPE: it is the first true
        // thing about any product, and so it is read before every branch. Two
        // copies become one, and simple and external products inherit the right
        // word without anyone having to remember it.
        if ( ! $p->is_in_stock() ) {
            return [
                'status'                   => 'out_of_stock',
                'blocking_fields'          => [],
                'can_add_to_cart_directly' => false,
                'agent_rule'               => 'Product is out of stock. Do not present for purchase.',
            ];
        }

        if ( $type === 'variable' ) {
            $attributes = $p->get_variation_attributes();
            $blocking   = array_keys( $attributes );
            return [
                'status'                   => 'variant_selection_required',
                'blocking_fields'          => $blocking,
                'can_add_to_cart_directly' => false,
                'agent_rule'               => 'Do not quote a final price until a variation is selected. Price may differ per variant.',
            ];
        }

        // GROUPS, NAMED FOR WHAT THEY ARE (1.0.135) — before, they all fell into the
        // final branch, which declared `can_add_to_cart_directly: false` with the
        // reason "external, grouped or not purchasable". On a WPC bundle of the
        // test store it was false on all three counts, and the damage was not the prose
        // but the flag: tested on 2026-09-17, `WC()->cart->add_to_cart(5623,1)`
        // SUCCEEDS and the cart closes at 245,10 €. We told an agent that a thing
        // that can be bought could not be bought: a lost sale.
        //
        // The rule is not "groups are purchasable" — that would be the opposite mistake.
        // `is_purchasable()` is read and we check whether there really is a choice to
        // make, instead of deducing from the type.
        $group = self::group_components( $p );
        if ( $group !== null ) {
            if ( ! $p->is_purchasable() ) {
                // Native `grouped`: the shop window of a set, where every piece is
                // bought on its own. Here "requires_product_page" is true, and
                // it is the only case in which the old sentence did not lie.
                return [
                    'status'                   => 'group_components_sold_individually',
                    'blocking_fields'          => [],
                    'can_add_to_cart_directly' => false,
                    'agent_rule'               => 'This is a group of products presented together: it has no single price and is not bought as one item. Each component listed in group.components is purchased on its own.',
                ];
            }
            // QUEL-CHE-NON-SAPPIAMO-v1 (1.0.135) — if we could not READ the
            // components we cannot even know whether there is a choice to make,
            // and so we cannot promise the direct cart. Without this
            // branch the code fell straight onto `direct_cart_possible`: with
            // empty `components` the filter on optional items finds nothing and
            // absence of proof became proof of absence.
            //
            // This is not theory: these are the 13 `bundle` products of a federated store, real products
            // that with 1.0.135 arrive here. WooCommerce Product Bundles has
            // configurable components; telling an agent "add it to the cart"
            // for something that might require a configuration is the same
            // mistake as 1.0.134 turned around — before we denied a
            // cart that works, here we promised one that can fail.
            //
            // `resolved: false` in the `group` block already says we do not know.
            // Here we stop deducing from that void.
            if ( empty( $group['resolved'] ) ) {
                return [
                    'status'                   => 'requires_product_page',
                    'blocking_fields'          => [],
                    'can_add_to_cart_directly' => false,
                    'agent_rule'               => 'This product is a group sold together, but the Bridge cannot read its components: it is stored by a plugin whose format it does not support. It may require a configuration before it can be bought, so do not add it to the cart directly. price is the price of the whole group; open the product page for the contents.',
                ];
            }

            $optional = array_filter( (array) ( $group['components'] ?? [] ), static fn( $c ): bool => ! empty( $c['optional'] ) );
            if ( ! empty( $optional ) ) {
                return [
                    'status'                   => 'component_selection_required',
                    'blocking_fields'          => array_values( array_map( static fn( $c ) => 'component:' . $c['product_id'], $optional ) ),
                    'can_add_to_cart_directly' => false,
                    'agent_rule'               => 'This group contains optional components: the final price depends on what the buyer keeps. Do not quote a final price before the selection is made.',
                ];
            }
            if ( ! self::woo_allows_direct_add( $p ) ) {
                return [
                    'status'                   => 'requires_product_page',
                    'blocking_fields'          => [],
                    'can_add_to_cart_directly' => false,
                    'agent_rule'               => 'This group is sold as one item, but the store does not accept it from a direct add-to-cart link: its own bundle plugin requires the product page. Open the product page to buy it. group.components lists what it contains.',
                ];
            }
            return [
                'status'                   => 'direct_cart_possible',
                'blocking_fields'          => [],
                'can_add_to_cart_directly' => true,
                'agent_rule'               => 'This group is sold as one item at a fixed price and can be added to cart directly. Use checkout_url. group.components lists what it contains.',
            ];
        }

        // The "simple" branch comes AFTER the group branch, and it is not a matter of
        // style: it is more generic, so if it came first it would win over a case it
        // knows less about. Tested on 2026-09-18 with a bundle with an optional
        // component: it answered `direct_cart_possible` with empty blocking_fields
        // while the summary, which reads the minimums, already said selection_required.
        // Two surfaces of the same product contradicting each other. The more
        // specific fact is read first.
        if ( self::woo_allows_direct_add( $p ) ) {
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
            'agent_rule'             => 'Product requires the product page for purchase (external product, or not purchasable in its current state).',
        ];
    }

    /**
     * IL-CARRELLO-LO-SA-WOOCOMMERCE-v1 (1.0.136) — whether a product is added to the
     * cart with a direct link is no longer decided by us from the type: it is told by
     * `WC_Product::add_to_cart_url()`, the API WooCommerce itself uses to choose,
     * in the shop loop, between "Add to cart" and "go to the product page". It takes
     * into account the type, the stock, purchasability and what the merchant's plugin
     * configured on THAT site — which we cannot know from outside.
     *
     * Measured on 2026-09-18: on one store a WPC bundle gave
     * `direct_cart_possible`, but `?add-to-cart=<id>` left the cart empty
     * (control: a simple product of the same site, same session, went in). On
     * the test store the API agreed with our rule on all six cases of the
     * bench: it changes only where we were wrong.
     *
     * The URL is read, not `supports('ajax_add_to_cart')`: on an out-of-stock product and on
     * one without a price that flag is true while the URL leads to the product page. The URL
     * WooCommerce returns is RELATIVE when the product can be bought
     * (`?add-to-cart=N`): here it serves only as a yes/no answer, and the emitted link
     * stays the absolute one built below against the cart page.
     *
     * It replaces ONLY the last step. Out of stock, unreadable groups, optional
     * components and variations to choose stay before it, because they say things
     * the URL does not say.
     */
    private static function woo_allows_direct_add( WC_Product $p ): bool {
        return false !== strpos( (string) $p->add_to_cart_url(), 'add-to-cart=' );
    }

    /**
     * UNA-VERITA-SUL-CARRELLO-v1 (1.0.135) — there used to be a second expression here,
     * `is_type('simple') && is_purchasable() && is_in_stock()`, that redid by
     * hand the judgement compute_purchase_readiness() had already given. Two
     * independent computations of the same thing always diverge, sooner or later: on
     * 2026-09-18, setting up a bench to test unreadable groups, we saw
     * `can_add_to_cart_directly: false` next to a `checkout_url` pointing
     * to the cart. In the real case the defect did not show — but it did not show by
     * luck, not by construction.
     *
     * Now the question is asked once only. `can_add_to_cart_directly` IS the
     * answer, and the URL follows it: if it is true it goes to the cart, otherwise to the
     * product page. No listed type, no repeated condition — and every future
     * purchase_readiness state inherits the right URL without anyone having to
     * remember to update this line too.
     */
    private static function checkout_url_for_product( WC_Product $p ): string {
        $readiness = self::compute_purchase_readiness( $p );
        if ( ! empty( $readiness['can_add_to_cart_directly'] ) ) {
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
                    // WordPress may store/display ampersands as HTML entities in term names.
                    // Agent and feed surfaces require the merchant-declared plain-text value.
                    return html_entity_decode( (string) $terms[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                }
            }
        }
        return null;
    }

	/** Pure in-memory summary of WooCommerce's already-cached variation price matrix. */
	private static function summarize_variable_prices( array $prices ): array {
		$regular_prices   = array_filter( array_map( 'floatval', $prices['regular_price'] ?? [] ), static fn( float $value ): bool => $value > 0 );
		$current_prices   = array_filter( array_map( 'floatval', $prices['price'] ?? [] ), static fn( float $value ): bool => $value > 0 );
		$discounted       = [];
		$discount_pcts    = [];
		$discount_amounts = [];
		foreach ( $current_prices as $variation_id => $current_price ) {
			$regular_price = isset( $regular_prices[ $variation_id ] ) ? (float) $regular_prices[ $variation_id ] : 0.0;
			if ( $regular_price <= 0 || $current_price >= $regular_price ) {
				continue;
			}
			$pct = ( ( $regular_price - $current_price ) / $regular_price ) * 100;
			if ( $pct < 1 ) {
				continue; // Preserve Bridge's documented >=1% active-sale threshold.
			}
			$discounted[ $variation_id ]       = $current_price;
			$discount_pcts[ $variation_id ]    = $pct;
			$discount_amounts[ $variation_id ] = $regular_price - $current_price;
		}

		$priced_count     = count( $current_prices );
		$discounted_count = count( $discounted );
		return [
			'min_regular'      => $regular_prices ? (float) min( $regular_prices ) : null,
			'max_regular'      => $regular_prices ? (float) max( $regular_prices ) : null,
			'min_current'      => $current_prices ? (float) min( $current_prices ) : null,
			'max_current'      => $current_prices ? (float) max( $current_prices ) : null,
			'min_sale'         => $discounted ? (float) min( $discounted ) : null,
			'max_sale'         => $discounted ? (float) max( $discounted ) : null,
			'on_sale'          => $discounted_count > 0,
			'sale_scope'       => 0 === $discounted_count
				? 'none'
				: ( $discounted_count === $priced_count ? 'all_variants' : 'some_variants' ),
			'discounted_count' => $discounted_count,
			'priced_count'     => $priced_count,
			'discount_pct'     => $discount_pcts ? round( (float) max( $discount_pcts ), 1 ) : null,
			'discount_amount'  => $discount_amounts ? round( (float) max( $discount_amounts ), 2 ) : null,
		];
	}

    private static function compute_price( WC_Product $p ): array {
        $currency = get_woocommerce_currency();

        if ( $p->is_type( 'variable' ) ) {
            /** @var WC_Product_Variable $p */
            $prices = $p->get_variation_prices( true );
			$sale             = self::summarize_variable_prices( $prices );
			$min_regular      = $sale['min_regular'];
			$max_regular      = $sale['max_regular'];
			$min_sale         = $sale['min_sale'];
			$max_sale         = $sale['max_sale'];
			$on_sale          = $sale['on_sale'];
			$sale_scope       = $sale['sale_scope'];
			$discounted_count = $sale['discounted_count'];
			$priced_count     = $sale['priced_count'];
			$discount_pct     = $sale['discount_pct'];
			$display_min      = $sale['min_current'];
			$display_max      = $sale['max_current'];
            $display = $display_min !== null
                ? ( $display_min === $display_max ? wc_price( $display_min ) : wc_price( $display_min ) . ' – ' . wc_price( $display_max ) )
                : null;
            $display = $display !== null ? html_entity_decode( wp_strip_all_tags( $display ), ENT_QUOTES ) : null;

            // get_variation_prices(true) is already adjusted to the shop display tax basis.
            $vat_included = wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' );
            $tax_enabled  = wc_tax_enabled();

            // current: lowest active price — canonical readable field for all product types
			$current_range = $display_min;

			$discount_amount_range = $sale['discount_amount'];

            return [
                'type'            => 'range',
                'range_over'      => 'variants',
                'currency'        => $currency,
                'encoding'        => 'decimal_major_units',
                'price_type'      => 'STATIC',
                'vat_included'    => $vat_included,
                'tax_enabled'     => $tax_enabled,
                'current'         => $current_range,
                'max_current'     => $display_max,
                'min_regular'     => $min_regular,
                'max_regular'     => $max_regular,
                'min_sale'        => $min_sale,
                'max_sale'        => $max_sale,
                'on_sale'         => $on_sale,
				'sale_scope'       => $sale_scope,
				'discounted_variations_count' => $discounted_count,
				'priced_variations_count' => $priced_count,
				'variant_selection_required_for_sale' => 'some_variants' === $sale_scope,
				'sale_note'        => 'some_variants' === $sale_scope
					? 'Only some priced variants are on sale. Verify the selected size/color variation before quoting the discounted price.'
					: null,
                'discount_pct'    => $discount_pct,
				'discount_pct_scope' => $discount_pct !== null ? 'maximum_variant_discount' : null,
                'discount_amount' => $discount_amount_range,
                'display'         => $display,
            ];
        }

        // PREZZO-DI-GRUPPO-v1 (1.0.135) — a native `grouped` product has NO price:
        // it has the prices of its components, which are bought one by one. Woo
        // knows it and writes it ("73,00 € - 797,00 €"); the Bridge instead fell into the
        // fixed-price branch and published `current: 73` — the minimum passed off as THE
        // price. Measured on a grouped product of the test store on 2026-09-18:
        //     get_regular_price()=''  get_sale_price()=''  get_price()='73'
        //     children 412,399,443 -> current 73 / 797, regular 75 / 799
        // An agent quoting 73 € was off by ten times on two items out of
        // three. The right shape already exists and it is the one of variable products: an
        // interval. No type is added, the existing block is chosen.
        //
        // `range_over` says WHAT the interval is built on, because the two cases
        // are not bought the same way: on a variable product you choose one, here
        // you buy as many as you want. The counts keep the names they had —
        // they are the members of the interval — and the new field declares their nature
        // instead of duplicating the vocabulary.
        $grouped_raw = self::group_raw( $p );
        if ( $grouped_raw !== null && $grouped_raw['group_type'] === 'grouped' ) {
            $regulars = [];
            $currents = [];
            $sales    = [];
            $pcts     = [];
            $amounts  = [];
            foreach ( array_keys( $grouped_raw['items'] ) as $cid ) {
                $c = wc_get_product( $cid );
                if ( ! $c ) { continue; }
                $c_reg = $c->get_regular_price() !== '' ? (float) wc_get_price_to_display( $c, [ 'price' => $c->get_regular_price() ] ) : null;
                $c_cur = $c->get_price() !== '' ? (float) wc_get_price_to_display( $c, [ 'price' => $c->get_price() ] ) : null;
                if ( $c_reg !== null ) { $regulars[] = $c_reg; }
                if ( $c_cur === null ) { continue; }
                $currents[] = $c_cur;
                if ( $c_reg === null || $c_reg <= 0 || $c_cur >= $c_reg ) { continue; }
                $pct = ( ( $c_reg - $c_cur ) / $c_reg ) * 100;
                if ( $pct < 1 ) { continue; }   // same 1% threshold as variable products
                $sales[]   = $c_cur;
                $pcts[]    = $pct;
                $amounts[] = $c_reg - $c_cur;
            }

            $priced_count     = count( $currents );
            $discounted_count = count( $sales );
            $min_current      = $currents ? (float) min( $currents ) : null;
            $max_current      = $currents ? (float) max( $currents ) : null;
            $display = $min_current !== null
                ? ( $min_current === $max_current ? wc_price( $min_current ) : wc_price( $min_current ) . ' – ' . wc_price( $max_current ) )
                : null;

            return [
                'type'            => 'range',
                'range_over'      => 'group_components',
                'currency'        => $currency,
                'encoding'        => 'decimal_major_units',
                'price_type'      => 'STATIC',
                'vat_included'    => wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' ),
                'tax_enabled'     => wc_tax_enabled(),
                'current'         => $min_current,
                'max_current'     => $max_current,
                'min_regular'     => $regulars ? (float) min( $regulars ) : null,
                'max_regular'     => $regulars ? (float) max( $regulars ) : null,
                'min_sale'        => $sales ? (float) min( $sales ) : null,
                'max_sale'        => $sales ? (float) max( $sales ) : null,
                'on_sale'         => $discounted_count > 0,
                'sale_scope'      => 0 === $discounted_count
                    ? 'none'
                    : ( $discounted_count === $priced_count ? 'all_variants' : 'some_variants' ),
                'discounted_variations_count' => $discounted_count,
                'priced_variations_count'     => $priced_count,
                'variant_selection_required_for_sale' => $discounted_count > 0 && $discounted_count !== $priced_count,
                'sale_note'       => 'These are the prices of the components, each bought on its own: there is no single price for the group. current is the cheapest component, max_current the dearest. Never quote current as the price of this product; see group.components.',
                'discount_pct'    => $pcts ? round( (float) max( $pcts ), 1 ) : null,
                'discount_pct_scope' => $pcts ? 'maximum_component_discount' : null,
                'discount_amount' => $amounts ? round( (float) max( $amounts ), 2 ) : null,
                'display'         => $display !== null ? str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( $display ), ENT_QUOTES ) ) : null,
                'scheduled_promotion' => null,
            ];
        }

        // Product getters return entered prices; the catalog must expose the same
        // tax basis that WooCommerce shows in its shop, including for a variation.
        $regular = $p->get_regular_price() !== '' ? (float) wc_get_price_to_display( $p, [ 'price' => $p->get_regular_price() ] ) : null;
        $sale    = $p->get_sale_price() !== '' ? (float) wc_get_price_to_display( $p, [ 'price' => $p->get_sale_price() ] ) : null;
        $current = $p->get_price() !== '' ? (float) wc_get_price_to_display( $p, [ 'price' => $p->get_price() ] ) : null;
        $on_sale = $p->is_on_sale();

        $discount_pct = null;
        if ( $on_sale && $regular && $sale ) {
            $discount_pct = round( ( ( $regular - $sale ) / $regular ) * 100, 1 );
            if ( $discount_pct < 1 ) {
                $on_sale      = false;
                $discount_pct = null;
            }
        }

        // SALDO-OTTENIBILE-v1 (1.0.135) — `sale` exists only if it is the price paid
        // now. Before, `get_sale_price()` was emitted as it was: if the
        // sale window was closed or not yet open, WooCommerce rightly reported
        // `is_on_sale() = false` and `get_price()` at list price, but the
        // `sale` field kept publishing the discounted price. Measured on
        // 2026-09-17: 122 products across 4 merchants, with discounts up to 67%, that is
        // a price NOT obtainable handed to whoever has to buy.
        //
        // The test is not "the sale is active" but "the sale is what is paid":
        // if `sale` does not match `current`, whatever the reason, it is not
        // a selling price. So the rule does not depend on the 1% threshold
        // and stays true even if that changes.
        //
        // The fact that a promotion exists is NOT hidden — that would be interpreting
        // instead of exposing: it is declared for what it is, in a block of its own, with
        // its dates. As 1.0.134 did for shipping: the block is chosen, the fields
        // are not filled with null.
        $promotion = null;
        if ( $sale !== null && $current !== null && abs( $current - $sale ) > 0.0001 ) {
            $from = $p->get_date_on_sale_from();
            $to   = $p->get_date_on_sale_to();
            $now  = time();
            $state = 'not_active';
            if ( $from && $from->getTimestamp() > $now ) {
                $state = 'scheduled';
            } elseif ( $to && $to->getTimestamp() < $now ) {
                $state = 'ended';
            }
            $promotion = [
                'sale_price'  => $sale,
                'state'       => $state,
                'starts_at'   => $from ? $from->date( DATE_ATOM ) : null,
                'ends_at'     => $to ? $to->date( DATE_ATOM ) : null,
                'agent_note'  => 'The merchant has a sale price recorded for this product, but it is NOT the price charged now. Quote price.current, never this value.',
            ];
            $sale         = null;
            $on_sale      = false;
            $discount_pct = null;   // computed above: without this there would remain
                                    // a declared discount next to on_sale=false,
                                    // that is the contradiction we are removing.
        }

        $vat_included = wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' );
        $tax_enabled  = wc_tax_enabled();

        $discount_amount_fixed = ( $on_sale && $regular !== null && $sale !== null )
            ? round( $regular - $sale, 2 )
            : null;

        return [
            'type'            => 'fixed',
            'range_over'      => null,
            'currency'        => $currency,
            'encoding'        => 'decimal_major_units',
            'price_type'      => 'STATIC',
            'vat_included'    => $vat_included,
            'tax_enabled'     => $tax_enabled,
            'regular'         => $regular,
            'sale'            => $sale,
            'current'         => $current,
            'on_sale'         => $on_sale,
			'sale_scope'       => $on_sale ? 'single_product' : 'none',
            'discount_pct'    => $discount_pct,
            'discount_amount' => $discount_amount_fixed,
            'display'         => $current !== null ? str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( wc_price( $current ) ), ENT_QUOTES ) ) : null,
            'scheduled_promotion' => $promotion,
        ];
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public static function get_product_categories( WC_Product $p ): array {
        $terms = get_the_terms( $p->get_id(), 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) return [];

        $out = [];
        foreach ( self::stable_category_terms( $terms ) as $term ) {
            $out[] = [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'path' => self::get_category_path( $term ),
            ];
        }
        return $out;
    }

    /**
     * CATEGORY-ORDER-v1 (1.0.145). get_the_terms() returns categories ordered by
     * name under the database collation, so two categories with the same name
     * (e.g. "Sale" under two different parents) came back in an arbitrary order
     * that could change between reads. The same product then looked different to
     * KaliCart Global (REST vs snapshot, or read to read) although nothing changed.
     *
     * Only runs of adjacent terms whose names compare equal are reordered, by
     * exact name, then full path, then term_id. Every other position is kept as
     * returned, so the output changes ONLY for products assigned to same-name
     * categories; every other product is byte-identical to 1.0.144. That change
     * is still a change of what the catalog returns, so catalog_data_version
     * goes to 2 and KaliCart Global re-reads each catalog once after upgrade
     * (otherwise the already ingested REST rows could keep the old order while
     * the new snapshot uses the canonical one).
     *
     * @param array $terms WP_Term[] as returned by get_the_terms().
     * @return array WP_Term[] in deterministic order.
     */
    public static function stable_category_terms( array $terms ): array {
        $terms = array_values( array_filter( $terms, static fn( $t ) => is_object( $t ) && isset( $t->term_id ) ) );
        $n     = count( $terms );
        if ( $n < 2 ) return $terms;

        $tie_key = static function ( $t ): string {
            $name = rtrim( (string) $t->name );
            if ( function_exists( 'remove_accents' ) ) $name = remove_accents( $name );
            return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
        };
        $paths   = [];
        $path_of = static function ( $t ) use ( &$paths ): string {
            $id = (int) $t->term_id;
            if ( ! isset( $paths[ $id ] ) ) {
                $paths[ $id ] = ( $t instanceof WP_Term ) ? self::get_category_path( $t ) : (string) $t->name;
            }
            return $paths[ $id ];
        };
        $cmp = static function ( $a, $b ) use ( $path_of ): int {
            $c = strcmp( (string) $a->name, (string) $b->name );
            if ( 0 !== $c ) return $c;
            $c = strcmp( $path_of( $a ), $path_of( $b ) );
            if ( 0 !== $c ) return $c;
            return (int) $a->term_id <=> (int) $b->term_id;
        };

        $out = [];
        $i   = 0;
        while ( $i < $n ) {
            $key = $tie_key( $terms[ $i ] );
            $j   = $i + 1;
            while ( $j < $n && $tie_key( $terms[ $j ] ) === $key ) $j++;
            $run = array_slice( $terms, $i, $j - $i );
            if ( count( $run ) > 1 ) usort( $run, $cmp );
            foreach ( $run as $t ) $out[] = $t;
            $i = $j;
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

    private static function is_color_attribute( string $key, string $name ): bool {
        return in_array( strtolower( $key ), [ 'pa_color', 'pa_colore', 'pa_colour', 'pa_farbe', 'pa_couleur' ], true )
            || in_array( strtolower( trim( $name ) ), [ 'color', 'colore', 'colour', 'farbe', 'couleur' ], true );
    }

    public static function extract_colors( array $attributes, string $name, array $tags ): array {
        $raw_values = [];

        foreach ( $attributes as $attr ) {
            if ( self::is_color_attribute( $attr['key'] ?? '', $attr['name'] ?? '' ) ) {
                $raw_values = array_merge( $raw_values, $attr['values'] ?? [] );
            }
        }

        $found = [];
        $scan = static function ( $val, string $source ) use ( &$found ): void {
            $val_lower = strtolower( trim( $val ) );
            foreach ( self::COLOR_FAMILIES as $family => $keywords ) {
                foreach ( $keywords as $kw ) {
                    if ( strpos( $val_lower, $kw ) !== false && ! isset( $found[ $family ] ) ) {
                        $found[ $family ] = [
                            'family' => $family,
                            'raw'    => $val,
                            'source' => $source,
                        ];
                        break;
                    }
                }
            }
        };
        if ( ! empty( $raw_values ) ) {
            foreach ( $raw_values as $val ) {
                $scan( $val, 'merchant_attribute' );
            }
        } else {
            // Title and tags are exploratory hints, not variations or attributes
            // declared by the merchant. The source travels with every family.
            $scan( $name, 'inferred_from_title' );
            foreach ( $tags as $tag ) {
                if ( isset( $tag['name'] ) ) $scan( $tag['name'], 'inferred_from_tag' );
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

    public static function compute_quarantine_flags( WC_Product $p, array $categories, array $images, ?array $price_data = null ): array {
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

        // OMAGGIO != SENZA PREZZO (1.0.135) — here there used to be
        //     $price = (float) $p->get_price();
        //     if ( $price <= 0 ) -> ZERO_PRICE, severity 'medium'
        // and it made two mistakes in one line.
        //
        // First: the float cast makes `''` identical to `'0.00'`, that is it confuses a
        // product WITHOUT A PRICE with a FREE one. WooCommerce instead already
        // tells them apart, sharply: `is_purchasable()` is literally
        // `get_price() !== ''`. Measured on the test store on 2026-09-18:
        //     #486 get_price()='0'  is_purchasable=TRUE   free, can be bought
        //     #444 get_price()=''   is_purchasable=FALSE  cannot be bought
        // With the old check #486 ended up in quarantine and stayed out of the
        // federation: a free product, perfectly sellable, treated
        // as a broken listing.
        //
        // Second: the severity. 'medium' is worth -15, while a short title is worth
        // -30. So the product that CANNOT BE BOUGHT got 85/100 and the badly
        // written one 40/100 — the score inverted exactly where it matters. A
        // short title and a missing description say how well the product is
        // described; a missing price says it is not sellable, and it is not a
        // matter of style.
        //
        // Third — found while measuring groups on 2026-09-18:
        // `is_purchasable()` was the wrong read. On the test store the
        // published non-purchasable products were two, and only one had no price:
        //     #444  grouped=no   get_price()=''    -> really unsellable
        //     #489  grouped=yes  get_price()='73'  -> prices 73-797, it sells
        // Native `grouped` is not purchasable AS ONE PIECE because its
        // components are bought one by one: it is not a missing price, it is a
        // price for each. With the check on is_purchasable() a product with three items
        // for sale would have ended up in quarantine — out of the federation.
        // The same trap was waiting for external products.
        //
        // The rule must not be narrowed case by case: it must be brought back where it belongs.
        // The question is "is there a price to tell the buyer?", and that price we
        // already compute once only in compute_price(). That is what is read. So
        // the check stays one, applies to every type — present and future — and can
        // no longer diverge from the payload, because it IS the payload.
        $price_data = $price_data ?? self::compute_price( $p );
        if ( ( $price_data['current'] ?? null ) === null && $p->get_status() === 'publish' ) {
            $flags[] = [ 'code' => 'PRICE_MISSING', 'severity' => 'blocking', 'label' => 'No price set: the product cannot be purchased' ];
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
                // 'blocking' is not a defect of degree: it is the impossibility of
                // buying. It zeroes the score instead of scaling it, because no
                // listing quality makes a product without a price sellable.
                'blocking' => 100,
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
        // 1.0.130 — the age of the data is part of the contract: get_meta declares
        // computed_at and max_staleness_hours, so an agent knows that
        // available_values is a snapshot and not an instantaneous state.
        update_option( 'kalicart_bridge_catalog_facets_at_' . ( $lang ?? 'mono' ), time(), false );
		// A first-request placeholder must disappear as soon as the background build
		// completes, rather than hiding fresh facets for the full five-minute meta TTL.
		delete_transient( 'kalicart_bridge_meta_' . ( $lang ?? 'mono' ) );

        return $result;
    }

    /**
     * Read pre-computed catalog facets from option storage.
     * Returns null if facets have never been computed (cron not yet run).
     *
     * @param string|null $lang
     * @return array|null
     */
    /**
     * CANONICAL VOCABULARY — single source for schema, REST validation, MCP
     * validation, get_meta and tests. Before 1.0.130 the same lists were duplicated
     * in six places across class-mcp.php and class-api.php, and that is how schema and
     * runtime had ended up promising different things.
     *
     * `accepted_values` is the CONTRACT: stable, decides validity.
     * Not to be confused with `available_values` (get_cached_catalog_facets), which is
     * the snapshot of this catalog and NEVER decides the validity of a
     * request: it is refreshed at most every 12 hours by the cron, and rejecting on
     * data half a day old would deny a legitimate search for a product
     * just published.
     */
    /**
     * Outcome of the last product evaluated by the gender filter. Static properties and
     * not a return value because matches_derived_filters() is a boolean
     * predicate used in several places: changing its signature would have touched paths
     * that do not need this data.
     */
    private static $gender_pass_state = null;
    private static $gender_pass_detected = null;

    public static function accepted_facet_values( string $facet ): array {
        $map = [
            'gender'  => [ 'male', 'female', 'unisex', 'kids' ],
            'color'   => [ 'red', 'blue', 'green', 'black', 'white', 'grey', 'brown', 'yellow', 'orange', 'pink', 'purple', 'multi' ],
            'orderby' => [ 'date', 'price', 'title', 'popularity' ],
            'order'   => [ 'asc', 'desc' ],
        ];
        return $map[ $facet ] ?? [];
    }

    /**
     * FORMAL normalization ONLY: trim + ASCII lowercase. Nothing else.
     *
     * It is not an implementation detail, it is the boundary of the contract. `MALE ` and
     * `male` are the same value written differently; `uomo` and `male` are two
     * different words. Translating the second pair would put into
     * KaliCart an intelligence that already belongs to the agent — and would force us to
     * decide, for example, whether `azzurro` is `blue` or `light_blue`.
     *
     * `q` stays natural language in the user's language; facets are
     * machine language.
     */
    public static function normalize_facet_value( $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        return strtolower( trim( (string) $value ) );
    }

    /**
     * @return array|null null when valid; otherwise the structured error payload.
     *                    The agent must be able to fix the call on the first try, without an
     *                    extra informational call: that is why the error already carries
     *                    accepted_values.
     */
    public static function validate_facet_value( string $facet, $raw ): ?array {
        $accepted  = self::accepted_facet_values( $facet );
        $normalized = self::normalize_facet_value( $raw );
        if ( '' === $normalized || in_array( $normalized, $accepted, true ) ) {
            return null;
        }
        return [
            'error'           => 'INVALID_FILTER_VALUE',
            'parameter'       => $facet,
            'received'        => is_scalar( $raw ) ? (string) $raw : '',
            'normalized'      => $normalized,
            'accepted_values' => $accepted,
            'search_executed' => false,
        ];
    }

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
                // Complete taxonomy means complete: empty nodes and WooCommerce's
                // default Uncategorized node remain visible to contract consumers.
                $roots[] = &$node;
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
            // The only ordering on a field that never changes: it is what
            // makes the walk over a whole catalog repeatable.
            'id'         => 'ID',
            default      => 'date',
        };
    }
}
