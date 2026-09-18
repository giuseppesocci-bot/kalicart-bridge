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
            // CAMMINATA-STABILE-v1 (1.0.135) — vedi map_orderby() e
            // apply_after_id_clause(): soglia per riprendere una lettura a pagine
            // senza che le pagine gia' lette si spostino sotto chi legge.
            'after_id'       => null,
        ];
        $args = wp_parse_args( $args, $defaults );

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
                              || $args['min_price'] !== null || $args['max_price'] !== null;
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$lookup_price = $args['min_price'] !== null || $args['max_price'] !== null || $args['orderby'] === 'price';

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $has_php_postfilter ? self::postfilter_batch_size() : (int) $args['per_page'],
            'paged'          => $has_php_postfilter ? 1 : (int) $args['page'],
            'orderby'        => $args['orderby'] === 'price' ? 'ID' : self::map_orderby( $args['orderby'] ),
            'order'          => $order,
        ];

        // CAMMINATA-STABILE-v1 (1.0.135) — percorrere un catalogo a pagine con
        // `orderby=date` non e' ripetibile. Attenzione al motivo, che non e' quello
        // che verrebbe da dire: WP_Query ordina per `post_date`, la data di
        // PUBBLICAZIONE, quindi modificare un prodotto non lo sposta. Sposta tutto
        // invece **pubblicarne uno nuovo**: con `order=DESC` entra in testa e fa
        // scorrere di una posizione ogni pagina successiva, cosi' chi sta leggendo
        // la quinta rilegge un prodotto e ne salta un altro. Stesso effetto per un
        // prodotto ripescato dal cestino o con la data di pubblicazione cambiata a
        // mano. L'ID invece non cambia mai, e un prodotto nuovo
        // ne prende uno piu' alto: quindi finisce in fondo, dove il lettore non e'
        // ancora arrivato, senza spostare nulla di gia' letto. Verificato il
        // 2026-09-18 su due negozi il cui ordine per ID NON coincide con l'ordine
        // di creazione (illpumpyouup 1.104 discordanze su 1.231, residuo di una
        // migrazione): anche li' i prodotti piu' recenti hanno gli ID piu' alti.
        //
        // `after_id` e' una SOGLIA, non un puntatore: si chiede "quelli dopo il
        // numero N". Se il prodotto N nel frattempo e' stato cancellato, `ID > N`
        // continua a funzionare, e la domanda "cosa rispondi se il cursore punta a
        // un prodotto che non c'e' piu'" non si pone.
        //
        // Limite noto: un prodotto che RIENTRA con un ID gia' superato (ripescato
        // dal cestino, o reimportato con ID esplicito) viene saltato fino alla
        // lettura completa successiva. Errore limitato e che si sana da solo.
        $after_id = $args['after_id'] === null ? null : (int) $args['after_id'];
        if ( $after_id !== null && $after_id > 0 ) {
            $query_args['kalicart_bridge_after_id'] = $after_id;
        }

		if ( $lookup_price ) {
			$query_args['kalicart_bridge_price_lookup'] = [
				'min'     => $args['min_price'],
				'max'     => $args['max_price'],
				'orderby' => $args['orderby'] === 'price',
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

        // CATALOG-VISIBILITY-v1 — il catalogo agentico e' lo SPECCHIO del negozio.
        // Fino alla 1.0.133 qui si filtrava solo `post_status`, quindi un prodotto
        // messo su "Nascosto" veniva servito lo stesso: una scelta esplicita del
        // merchant ignorata in silenzio.
        //
        // Si rispettano i due termini di WooCommerce uno a uno, che e' anche il modo
        // piu' semplice di scriverlo: in navigazione cade chi e' escluso dal
        // catalogo, nella ricerca testuale chi e' escluso dalla ricerca. Ne segue da
        // solo che "Nascosto" (entrambi i termini) sparisce da tutto, "Solo negozio"
        // vive negli scaffali e "Solo risultati di ricerca" lo trova chi lo cerca —
        // esattamente come sul sito del merchant. Se su WooCommerce il prodotto
        // esiste e si vende, il Bridge lo mostra dove il negozio lo mostrerebbe.
        //
        // Non si va oltre finche' non si sa PERCHE' un merchant declassa un prodotto
        // (ricambio fuori vetrina? articolo linkato da una campagna? fondo di
        // magazzino?). Oggi quel dato non esiste: 0 prodotti su 5.373 federati lo
        // portano, perche' il Bridge non lo ha mai inviato. Da questa versione lo
        // invia, cosi' la domanda avra' una risposta misurata e non una supposizione.
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
                $result = [ '_error' => [
                    'code'            => 'KALICART_CATALOG_QUERY_TOO_BROAD',
                    'status'          => 422,
                    'message'         => 'This derived-filter query is too broad to evaluate safely. Add q or category and retry.',
                    'candidate_count' => $candidate_total,
                    'candidate_limit' => $candidate_limit,
                    'guidance'        => 'Narrow the candidate set with a bare product noun (q) or a WooCommerce category slug before applying gender, color or on_sale. Verify size only in the selected product variations.',
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

            $result = [
                'products'    => $products,
                'total'       => $filtered_total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil( $filtered_total / $per_page ),
            ];

            // `gender` e' l'UNICO filtro morbido del Bridge: un genere diverso
            // esclude, un genere ignoto CONSERVA il prodotto come candidato.
            // Difendibile (i generi si inferiscono male, escluderli perderebbe
            // troppo) ma finora invisibile: su un catalogo dove 96 prodotti su 109
            // non hanno genere rilevato, qualunque valore sembrava filtrare.
            // color, stock, promozioni e prezzi restano rigidi: senza evidenza il
            // prodotto esce. L'asimmetria e' dichiarata, non subita.
            if ( ! empty( $args['gender'] ) ) {
                // Il ciclo sopra NON si ferma a pagina piena: attraversa tutti i
                // candidati per calcolare $filtered_total. Quindi i conteggi sono
                // sull'intero insieme e result_set_evaluation_complete e' true.
                // Se un giorno il ciclo dovesse interrompersi in anticipo, questo
                // flag va messo a false e i due conteggi globali a null — MAI a
                // zero, e mai stimati: un numero parziale che sembra completo e'
                // peggio di un numero assente.
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
			// Il prezzo di un prodotto e' un INTERVALLO: per un semplice i due estremi
			// coincidono, per un variabile no. Si tiene il prodotto se il suo intervallo
			// tocca quello richiesto — stessa semantica di sovrapposizione gia' dichiarata
			// dal prefiltro SQL, che altrimenti veniva annullata qui.
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
                    ? array_values( wp_list_pluck( $cat_terms, 'slug' ) )
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

            // PRICE-INTERVAL-v1 — `current` e' il prezzo ATTIVO PIU' BASSO e `regular`
            // cade su `min_regular` sui variabili: entrambi sono ESTREMI, non "il
            // prezzo". Senza `type` e `max_current` l'agente non puo' saperlo dal
            // summary, ed e' proprio da qui che le agent_instructions gli dicono di
            // classificare; l'intervallo restava leggibile solo dentro la stringa
            // `display`, che non e' computabile. Gli estremi alti si AGGIUNGONO solo
            // su type=range: su un prezzo fisso ripeterebbero current/regular per ogni
            // prodotto del catalogo, e fields=summary esiste per pesare poco. Stessa
            // scelta della card del global, che li omette sui fixed.
            $price_type  = $price['type'] ?? 'fixed';
            $group_raw   = self::group_raw( $p );   // lettura a costo zero: id e minimi, nessun prodotto caricato
            $price_block = [
                'currency' => get_woocommerce_currency(),
                'encoding' => 'decimal_major_units',
                'type'    => $price_type,
                'current' => $price['current'] ?? null,
                'display' => $price['display'] ?? null,
                'regular' => $price['regular'] ?? $price['min_regular'] ?? null,
            ];
            if ( 'range' === $price_type ) {
                // `range_over` distingue i due intervalli che il summary puo'
                // portare: su un variabile se ne sceglie UNO, su un grouped si
                // comprano i componenti uno per uno. Senza, gli estremi sono
                // identici e il triage non puo' sapere cosa sta confrontando.
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

            return [
                'id'         => $p->get_id(),
                'sku'        => $p->get_sku() ?: null,
                'name'       => $p->get_name(),
                'brand'      => self::resolve_brand( $p ),
                'url'        => get_permalink( $p->get_id() ),
                'price'      => $price_block,
                'stock'      => [ 'in_stock' => $p->is_in_stock() ],
                // SHIPPING-REQUIRED-v1 — dice se WooCommerce chiederebbe un indirizzo di
                // spedizione, e SOLO quello: non e' "fisico contro digitale". Uno
                // scaricabile che si spedisce comunque resta true, e il ritiro in negozio
                // non lo distingue affatto, perche' Woo lo modella come metodo di
                // spedizione e non come proprieta' del prodotto. Per le tre risposte che
                // servono davvero c'e' `fulfilment`. E' la ONE shipping
                // fact the summary carries: the quote, zones and thresholds stay in
                // /catalog/product/{id}. Without it an agent that must reason about
                // physical goods has to open every candidate one by one to find out.
                'shipping_required' => self::product_needs_shipping( $p ),
                // FULFILMENT-v1 — come si ottiene: shipped | downloadable | pickup_only.
                // Il catalogo e' lo specchio del negozio, quindi non nasconde un
                // prodotto perche' non si spedisce: dice cosa arriva a casa, cosa si
                // scarica e cosa si ritira in sede. Sta nel summary perche' e' li'
                // che l'agente sceglie, e scegliere senza questo significa proporre
                // una consegna che non esiste.
                'fulfilment' => self::product_fulfilment( $p ),
                // DISCOVERY-SCOPE-v1 — il Bridge AFFERMA quel che afferma il negozio.
                // Un prodotto fuori dal catalogo ma in ricerca non deve essere dedotto
                // dall'assenza altrove: un agente che non lo vede navigando non puo'
                // sapere se non esiste, se e' esaurito o se il merchant lo ha tolto
                // dalla vetrina. Sono tre conclusioni diverse e solo una e' vera.
                // I due booleani sono i due termini di WooCommerce resi leggibili,
                // uno a uno, senza aggiungere significato.
                'discovery' => [
                    'in_catalog' => self::product_in_catalog( $p ),
                    'in_search'  => self::product_in_search( $p ),
                ],
                'categories' => $categories,
                'gender'     => $gender,
                'type'       => $p->get_type(),
                // SCELTA-RICHIESTA-v1 (1.0.135) — era `is_type('variable')`, cioe'
                // una sola delle tre ragioni per cui non si compra al volo. Sul
                // grouped #489 il summary diceva selection_required=false accanto a
                // un prezzo che era il minimo di 73-797: un agente in triage lo
                // prendeva per un acquisto da un clic a 73 €. Le altre due ragioni
                // sono i componenti da scegliere (bundle con opzionali) e i gruppi
                // venduti pezzo per pezzo, che qui si leggono senza caricare nulla:
                // il minimo sta gia' nel meta, e l'intervallo l'abbiamo appena
                // calcolato. Il summary resta leggero e smette di mentire.
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
        $group              = self::group_components( $p, $context ); // null se non e' un gruppo
        // variations only in detail context — avoids N×get_variations() queries in list/search
        $variations = ( $context === 'detail' && $type === 'variable' ) ? self::get_variations( $p ) : null;

        // variants[] — detail: full list; list: lightweight single entry, empty array for variable (UCP: always an array)
        //
        // RIGA-SINTETICA-v1 (1.0.135) — la voce unica con `variation_id === id` e'
        // la promessa che esiste UNA cosa comprabile a UN prezzo. Sul grouped #489
        // era falsa due volte: la cosa comprabile non c'e' (si comprano i tre
        // componenti) e il prezzo era il minimo di un intervallo 73-797. Un agente
        // che legge variants[0].price prendeva 73 € per un articolo da 797.
        //
        // La condizione non e' sul tipo — sarebbe l'ennesima toppa, e domani
        // arriva il tipo che non abbiamo previsto — ma sul prezzo che abbiamo
        // appena calcolato: la riga si emette dove il prezzo e' UNO. Dove e' un
        // intervallo, variants resta [] e la verita' sta in `group.components`
        // (gruppi) o in `variations` (variabili, contesto detail).
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
            // La superficie espone, non interpreta: Global riceve lo stato di
            // visibilita' cosi' com'e' e potra' decidere con i dati invece che per
            // deduzione. Oggi 0 prodotti su 5.373 federati lo portano.
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
            // I GRUPPI, DETTI PER QUELLO CHE SONO (1.0.135) — `null` per chi gruppo
            // non e'. Prima di questa versione nel payload non esisteva la parola
            // "componente": un bundle era un prodotto qualunque con un prezzo e
            // nessun contenuto, e chi comprava non sapeva cosa stesse comprando.
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
                    // SOGLIA VERA O NIENTE (1.0.135) — qui si raccoglieva ogni
                    // `min_amount` non nullo, zero compreso, e si ignorava
                    // `requires`. Due conseguenze misurate il 2026-09-18 su
                    // illpumpyouup.com, che ha DUE metodi free_shipping:
                    //   requires='coupon'      min_amount=0   -> soglia "0"
                    //   requires='min_amount'  min_amount=99  -> soglia 99
                    // Quello zero entrava nell'elenco e rendeva ogni prodotto
                    // "gia' idoneo per prezzo", mentre accanto restava scritto
                    // quanto mancava ai 99. Risultato: 1.207 prodotti, il 22% della
                    // federazione, che dicevano insieme "hai diritto alla spedizione
                    // gratis" e "ti mancano 96,01".
                    //
                    // E il difetto vero non e' lo zero: e' che quel metodo chiede un
                    // COUPON. Annunciarlo come gratuito per prezzo e' dire a un
                    // agente una cosa che il negozio non concede.
                    //
                    // `requires` vale: '' o 'min_amount' -> il prezzo basta;
                    // 'either' -> coupon OPPURE minimo, quindi il prezzo basta;
                    // 'coupon' o 'both' -> il prezzo da solo non basta mai.
                    $min = isset( $method->min_amount ) && $method->min_amount !== '' ? (float) $method->min_amount : null;
                    $requires = (string) ( $method->requires ?? '' );
                    $row['requires'] = $method->requires ?? null;
                    $row['min_amount'] = $min;
                    $price_alone_suffices = in_array( $requires, [ '', 'min_amount', 'either' ], true );
                    if ( $price_alone_suffices && $min !== null && $min > 0 ) {
                        $free_thresholds[] = $min;
                    }
                    if ( $price_alone_suffices && ( $min === null || $min <= 0 ) ) {
                        // Nessun minimo da raggiungere: non e' una soglia zero, e'
                        // spedizione gratuita senza condizioni di importo.
                        $free_shipping_unconditional = true;
                    }
                    if ( in_array( $requires, [ 'coupon', 'both' ], true ) ) {
                        $free_shipping_coupon_only = true;
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
            'free_shipping_unconditional' => $free_shipping_unconditional,
            'free_shipping_requires_coupon' => $free_shipping_coupon_only,
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

    /**
     * Se il prodotto si scarica.
     *
     * Stessa cecita' di `get_virtual()`: `WC_Product_Variable::get_downloadable()`
     * ritorna `false` INCONDIZIONATAMENTE ("Variable products themselves cannot be
     * downloadable", Woo 11.1.0), quindi sul parent non dice nulla e vanno lette
     * le varianti.
     *
     * Per dire che il PRODOTTO si scarica devono scaricarsi TUTTE le varianti: se
     * anche solo un modo di comprarlo non produce un download, il prodotto non e'
     * un download. Ignoto -> non scaricabile, coerente con "ignoto resta fisico".
     */
    private static function product_is_downloadable( WC_Product $p ): bool {
        if ( ! $p->is_type( 'variable' ) ) {
            return $p->is_downloadable();
        }
        $inspected = 0;
        foreach ( $p->get_children() as $child_id ) {
            $variation = wc_get_product( (int) $child_id );
            if ( ! $variation ) {
                continue;
            }
            $inspected++;
            if ( ! $variation->is_downloadable() ) {
                return false;
            }
        }
        return $inspected > 0;
    }

    /**
     * Come si ottiene il prodotto, deciso dal PRODOTTO e non dal negozio.
     *
     * Tre casi, che sono le tre cose che un catalogo-specchio deve saper dire:
     * cosa ti arriva a casa, cosa scarichi, cosa ritiri in sede.
     *
     * `pickup_only` non e' un'inferenza dalla configurazione del negozio — quello
     * sarebbe un fatto del negozio applicato a un prodotto, e in un negozio con
     * ritiro attivo avrebbe etichettato "si ritira in sede" anche un ebook. E'
     * una deduzione dal prodotto: se non si spedisce e non si scarica, allora
     * esiste fisicamente e da qualche parte si ritira. Dove, lo dicono i metodi
     * di ritiro del negozio, che restano un fatto separato.
     */
    /** Il prodotto compare negli scaffali del negozio (navigazione e categorie). */
    private static function product_in_catalog( WC_Product $p ): bool {
        return ! in_array( $p->get_catalog_visibility(), [ 'search', 'hidden' ], true );
    }

    /** Il prodotto e' trovabile cercandolo. */
    private static function product_in_search( WC_Product $p ): bool {
        return ! in_array( $p->get_catalog_visibility(), [ 'catalog', 'hidden' ], true );
    }

    private static function product_fulfilment( WC_Product $p ): string {
        if ( self::product_needs_shipping( $p ) ) {
            return 'shipped';
        }
        return self::product_is_downloadable( $p ) ? 'downloadable' : 'pickup_only';
    }

    /**
     * I metodi di ritiro in negozio configurati dal merchant, se ce ne sono.
     * Le zone il Bridge le raccoglie gia'; qui si estrae solo il ritiro, che e'
     * l'unico modo documentato di ottenere un prodotto che non viene spedito.
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
        // FULFILMENT-COHERENCE-v1 — un prodotto che non si spedisce non puo'
        // portarsi dietro le condizioni di spedizione del negozio. Prima di questa
        // versione il blocco diceva `shipping_required: false` e nella riga dopo
        // `free_shipping_available: true` con quanto mancava alla soglia: due fatti
        // che si contraddicono nello stesso oggetto, e un agente che legge il
        // secondo conclude l'opposto del primo. La policy del negozio vale per
        // cio' che il negozio spedisce; per il resto si dice come lo si ottiene.
        if ( ! self::product_needs_shipping( $p ) ) {
            $pickup      = self::local_pickup_methods();
            $fulfilment  = self::product_fulfilment( $p );
            $is_download = 'downloadable' === $fulfilment;
            return [
                'shipping_required' => false,
                'fulfilment' => $fulfilment,
                'local_pickup_available' => ! $is_download && ! empty( $pickup ),
                'local_pickup' => $is_download ? null : ( $pickup ?: null ),
                'delivery_note' => $is_download
                    ? 'This product is downloaded, not delivered. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.'
                    : ( $pickup
                        ? 'This product is not shipped and is not a download: it is a physical item collected in store. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.'
                        : 'This product is not shipped and is not a download, so it is collected from the merchant. The store has no local pickup method configured, so the collection point is not described here: ask the merchant. Shipping costs, free-shipping thresholds and delivery estimates do not apply to it.' ),
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
            'shipping_class' => $p->get_shipping_class() ?: null,
            'weight' => $p->get_weight() ? (float) $p->get_weight() : null,
            'weight_unit' => get_option( 'woocommerce_weight_unit', 'kg' ),
            'free_shipping_available' => (bool) ( $policy['free_shipping_available'] ?? false ),
            'free_shipping_thresholds' => $thresholds,
            // Le soglie ora contengono solo minimi VERI e raggiungibili col solo
            // prezzo (vedi merchant_shipping_policy). I due casi che prima ci si
            // nascondevano dentro travestiti da zero hanno un campo proprio: il
            // blocco dichiara quale condizione vale, non riempie i campi di null.
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

    /**
     * UN GRUPPO E' UN GRUPPO (1.0.135) — WooCommerce modella piu' prodotti venduti
     * insieme, e il Bridge non lo modellava affatto: li schiacciava nella forma dei
     * variabili, che significa l'opposto. Su un variabile `variants` vuol dire
     * "scegline uno"; su un gruppo vorrebbe dire "li prendi tutti". Stessa chiave,
     * significato rovesciato, e nessun segnale per distinguerli.
     *
     * Misurato il 2026-09-17 su grouped #489 e woosb #5623 di project2209: la voce
     * fantasma in `variants` aveva `variation_id` UGUALE all'id del prodotto e
     * `attributes` vuoto, `variation_summary` era null, e i componenti non
     * comparivano da nessuna parte. Un agente leggeva "Offerta back to winter,
     * 245,10 €" senza sapere che sono quattro prodotti, ne' quali.
     *
     * LA VIA DI LETTURA NON E' UNA SOLA e non puo' esserlo: il `grouped` nativo
     * tiene i figli in get_children(), WPC Product Bundles nel meta `woosb_ids`,
     * WooCommerce Product Bundles nella propria API. Sono lettori dichiarati e
     * stretti — non toppe — perche' la forma ESPOSTA resta una: questo blocco.
     *
     * Del terzo tipo conosciamo il nome ma non abbiamo il plugin (a pagamento) e
     * quindi non possiamo provarlo: lo si dichiara gruppo con `resolved: false`
     * invece di indovinarne il formato. Il payload dice cio' che sappiamo, e dice
     * anche cosa non sa.
     */
    /**
     * LETTURA GREZZA — chi sono i componenti, senza caricarne nemmeno uno.
     *
     * Serve dove il costo conta (fields=summary gira su ogni riga di catalogo):
     * gli id, le quantita' e i minimi stanno gia' nel meta o in get_children(),
     * quindi sapere SE una scelta e' richiesta non deve costare N wc_get_product.
     * Il caricamento vero resta in group_components(), che chiama questa.
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
     * SCONTO DEL GRUPPO — quello che il gruppo toglie DI SUO, sopra i componenti.
     *
     * Punto 8 del debito. Sul woosb #5623 di project2209 la catena e' 266 -> 258
     * -> 245,10: il listino dei componenti, la somma dei loro prezzi di oggi (sono
     * scontati singolarmente), e infine il 5% che il bundle toglie in piu'. Il
     * payload pubblicava solo gli estremi, cioe' un 7,9% unico, e il 258 di mezzo
     * spariva. Un agente non poteva rispondere alla domanda che conta — conviene
     * il bundle o comprarli separati? — perche' i due sconti erano fusi in uno.
     * Qui si legge il solo termine che manca; gli altri due li da' gia'
     * group_components(). La superficie espone i tre numeri, non la conclusione.
     */
    private static function group_own_discount( WC_Product $p, string $group_type ): ?array {
        if ( $group_type !== 'woosb' ) {
            return null;
        }
        if ( get_post_meta( $p->get_id(), 'woosb_disable_auto_price', true ) === 'on' ) {
            // Il prezzo del bundle e' scritto a mano: non c'e' uno sconto da
            // dichiarare, c'e' un prezzo. Sta gia' in price.current.
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

        // Il terzo tipo: conosciamo il nome, non il formato, e il plugin e' a
        // pagamento — non possiamo provarlo. Si dichiara gruppo con
        // `resolved: false` invece di indovinare. Le chiavi restano le stesse del
        // caso risolto: chi legge non deve ramificare sulla presenza dei campi,
        // gli basta `resolved` per sapere di quali fidarsi.
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

        // ESAURITO-E-ESAURITO-v1 (1.0.135) — questo controllo stava DUE volte, nel
        // ramo dei variabili e in quello dei gruppi, identico parola per parola, e
        // non c'era nel ramo dei semplici. Cosi' lo stesso fatto usciva con due
        // vocabolari diversi: misurato il 2026-09-18 su project2209, il #426 e' un
        // `simple` esaurito e riceveva `requires_product_page` con la motivazione
        // "external product, or not purchasable in its current state" — vaga dove
        // ne esisteva una esatta, e per giunta falsa: non e' un external, e il
        // prodotto e' acquistabile. E' finito. Un variabile esaurito, nella stessa
        // risposta, riceveva `out_of_stock`.
        //
        // "Esaurito" non e' una proprieta' del TIPO di prodotto: e' la prima cosa
        // vera di qualunque prodotto, e quindi si legge prima di ogni ramo. Due
        // copie diventano una, e i semplici e gli external ereditano la parola
        // giusta senza che nessuno debba ricordarsene.
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

        // I GRUPPI, DETTI PER QUELLO CHE SONO (1.0.135) — prima cadevano tutti nel
        // ramo finale, che dichiarava `can_add_to_cart_directly: false` con la
        // motivazione "external, grouped or not purchasable". Sul woosb #5623 di
        // project2209 era falsa su tutti e tre i punti, e il danno non era la prosa
        // ma il flag: provato il 2026-09-17, `WC()->cart->add_to_cart(5623,1)`
        // RIESCE e il carrello chiude a 245,10 €. Dicevamo a un agente che non si
        // poteva comprare una cosa che si compra: una vendita persa.
        //
        // La regola non e' "i gruppi sono acquistabili" — sarebbe l'errore opposto.
        // Si legge `is_purchasable()` e si guarda se c'e' davvero una scelta da
        // fare, invece di dedurre dal tipo.
        $group = self::group_components( $p );
        if ( $group !== null ) {
            if ( ! $p->is_purchasable() ) {
                // Il `grouped` nativo: la vetrina di un insieme, dove si compra
                // ogni pezzo per conto suo. Qui "requires_product_page" e' vero, ed
                // e' l'unico caso in cui la vecchia frase non mentiva.
                return [
                    'status'                   => 'group_components_sold_individually',
                    'blocking_fields'          => [],
                    'can_add_to_cart_directly' => false,
                    'agent_rule'               => 'This is a group of products presented together: it has no single price and is not bought as one item. Each component listed in group.components is purchased on its own.',
                ];
            }
            // QUEL-CHE-NON-SAPPIAMO-v1 (1.0.135) — se non abbiamo potuto LEGGERE i
            // componenti non possiamo nemmeno sapere se c'e' una scelta da fare,
            // e quindi non possiamo promettere il carrello diretto. Senza questo
            // ramo il codice cadeva dritto su `direct_cart_possible`: con
            // `components` vuoto il filtro sugli opzionali non trova niente e
            // l'assenza di prova diventava prova d'assenza.
            //
            // Non e' teoria: sono i 13 `bundle` di illpumpyouup.com, prodotti veri
            // che con la 1.0.135 arrivano qui. WooCommerce Product Bundles ha
            // componenti configurabili; dire a un agente "aggiungilo al carrello"
            // su una cosa che potrebbe pretendere una configurazione e' lo stesso
            // errore della 1.0.134 girato al contrario — prima negavamo un
            // carrello che funziona, qui ne promettevamo uno che puo' fallire.
            //
            // `resolved: false` nel blocco `group` dice gia' che non sappiamo.
            // Qui si smette di dedurre da quel vuoto.
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
            return [
                'status'                   => 'direct_cart_possible',
                'blocking_fields'          => [],
                'can_add_to_cart_directly' => true,
                'agent_rule'               => 'This group is sold as one item at a fixed price and can be added to cart directly. Use checkout_url. group.components lists what it contains.',
            ];
        }

        // Il ramo "semplice" sta DOPO quello dei gruppi, e non e' un dettaglio di
        // stile: e' piu' generico, quindi se viene prima vince su un caso che
        // conosce meno. Provato il 2026-09-18 con un bundle a componente
        // opzionale: rispondeva `direct_cart_possible` con blocking_fields vuoto
        // mentre il summary, che legge i minimi, diceva gia' selection_required.
        // Due superfici dello stesso prodotto che si contraddicono. Il fatto piu'
        // specifico si legge per primo.
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
            'agent_rule'             => 'Product requires the product page for purchase (external product, or not purchasable in its current state).',
        ];
    }

    /**
     * UNA-VERITA-SUL-CARRELLO-v1 (1.0.135) — qui c'era una seconda espressione,
     * `is_type('simple') && is_purchasable() && is_in_stock()`, che rifaceva a
     * mano il giudizio che compute_purchase_readiness() aveva gia' dato. Due
     * calcoli indipendenti della stessa cosa divergono, sempre, prima o poi: il
     * 2026-09-18, montando un banco per provare i gruppi illeggibili, si e' visto
     * `can_add_to_cart_directly: false` accanto a un `checkout_url` che puntava
     * al carrello. Nel caso reale il difetto non si vedeva — ma non si vedeva per
     * fortuna, non per costruzione.
     *
     * Ora la domanda si fa una volta sola. `can_add_to_cart_directly` E' la
     * risposta, e l'URL la segue: se e' vero si va al carrello, altrimenti alla
     * scheda. Nessun tipo elencato, nessuna condizione ripetuta — e ogni stato
     * futuro di purchase_readiness eredita l'URL giusto senza che nessuno debba
     * ricordarsi di aggiornare anche questa riga.
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

            $vat_included = wc_prices_include_tax();
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

        // PREZZO-DI-GRUPPO-v1 (1.0.135) — un `grouped` nativo NON ha un prezzo:
        // ha i prezzi dei suoi componenti, che si comprano uno per uno. Woo lo
        // sa e lo scrive ("73,00 € - 797,00 €"); il Bridge invece cadeva nel ramo
        // a prezzo fisso e pubblicava `current: 73` — il minimo spacciato per IL
        // prezzo. Misurato sul #489 di project2209 il 2026-09-18:
        //     get_regular_price()=''  get_sale_price()=''  get_price()='73'
        //     figli 412,399,443 -> correnti 73 / 797, listini 75 / 799
        // Un agente che quotava 73 € sbagliava di dieci volte su due articoli su
        // tre. La forma giusta esiste gia' ed e' quella dei variabili: un
        // intervallo. Non si aggiunge un tipo, si sceglie il blocco che c'e'.
        //
        // `range_over` dice su COSA e' costruito l'intervallo, perche' i due casi
        // non si comprano allo stesso modo: su un variabile se ne sceglie uno, qui
        // se ne compra quanti se ne vuole. I conteggi tengono i nomi che avevano —
        // sono i membri dell'intervallo — e il campo nuovo ne dichiara la natura
        // invece di duplicare il vocabolario.
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
                $c_reg = $c->get_regular_price() !== '' ? (float) $c->get_regular_price() : null;
                $c_cur = $c->get_price() !== '' ? (float) $c->get_price() : null;
                if ( $c_reg !== null ) { $regulars[] = $c_reg; }
                if ( $c_cur === null ) { continue; }
                $currents[] = $c_cur;
                if ( $c_reg === null || $c_reg <= 0 || $c_cur >= $c_reg ) { continue; }
                $pct = ( ( $c_reg - $c_cur ) / $c_reg ) * 100;
                if ( $pct < 1 ) { continue; }   // stessa soglia dell'1% dei variabili
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
                'vat_included'    => wc_prices_include_tax(),
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

        // SALDO-OTTENIBILE-v1 (1.0.135) — `sale` esiste solo se e' il prezzo che si
        // paga adesso. Prima si emetteva `get_sale_price()` cosi' com'era: se la
        // finestra del saldo era chiusa o non ancora aperta, WooCommerce riportava
        // giustamente `is_on_sale() = false` e `get_price()` al listino, ma il
        // campo `sale` continuava a pubblicare il prezzo scontato. Misurato il
        // 2026-09-17: 122 prodotti su 4 merchant, con sconti fino al 67%, cioe'
        // un prezzo NON ottenibile messo in mano a chi deve comprare.
        //
        // Il test non e' "il saldo e' attivo" ma "il saldo e' quello che si paga":
        // se `sale` non coincide con `current`, qualunque ne sia la ragione, non e'
        // un prezzo di vendita. Cosi' la regola non dipende dalla soglia dell'1%
        // (vedi punto 9 del debito) e resta vera anche se quella cambia.
        //
        // Il fatto che una promozione esista NON si nasconde — sarebbe interpretare
        // invece che esporre: si dichiara per quello che e', in un blocco suo, con
        // le sue date. Come la 1.0.134 per la spedizione: si sceglie il blocco, non
        // si riempiono i campi con null.
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
            $discount_pct = null;   // calcolato piu' sopra: senza questo resterebbe
                                    // uno sconto dichiarato accanto a on_sale=false,
                                    // cioe' la contraddizione che stiamo togliendo.
        }

        $vat_included = wc_prices_include_tax();
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

        // OMAGGIO != SENZA PREZZO (1.0.135) — qui c'era
        //     $price = (float) $p->get_price();
        //     if ( $price <= 0 ) -> ZERO_PRICE, severity 'medium'
        // e faceva due errori in una riga.
        //
        // Primo: il cast a float rende `''` identico a `'0.00'`, cioe' confonde un
        // prodotto SENZA PREZZO con uno in OMAGGIO. WooCommerce invece li
        // distingue gia', e in modo netto: `is_purchasable()` e' letteralmente
        // `get_price() !== ''`. Misurato su project2209 il 2026-09-18:
        //     #486 get_price()='0'  is_purchasable=TRUE   omaggio, si compra
        //     #444 get_price()=''   is_purchasable=FALSE  non si compra
        // Col vecchio controllo il #486 finiva in quarantena e restava fuori dalla
        // federazione: un prodotto regalato, perfettamente vendibile, trattato
        // come una scheda rotta.
        //
        // Secondo: la severita'. 'medium' vale -15, mentre un titolo corto vale
        // -30. Cosi' il prodotto che NON SI PUO' COMPRARE prendeva 85/100 e quello
        // scritto male 40/100 — il punteggio invertito proprio dove conta. Un
        // titolo corto e una descrizione assente dicono quanto bene il prodotto e'
        // descritto; un prezzo assente dice che non e' vendibile, e non e' una
        // questione di stile.
        //
        // Terzo — e questo me lo sono trovato addosso il 2026-09-18, misurando i
        // gruppi: `is_purchasable()` era la lettura sbagliata. Su project2209 i
        // prodotti publish non acquistabili erano due, e uno solo era senza prezzo:
        //     #444  grouped=no   get_price()=''    -> davvero invendibile
        //     #489  grouped=si   get_price()='73'  -> prezzi 73-797, si vende
        // Il `grouped` nativo non e' acquistabile COME UN PEZZO perche' i suoi
        // componenti si comprano uno per uno: non e' un prezzo mancante, e' un
        // prezzo per ciascuno. Col controllo su is_purchasable() sarebbe finito in
        // quarantena — fuori dalla federazione — un prodotto con tre articoli in
        // vendita. La stessa trappola aspettava gli external.
        //
        // La regola non va ristretta caso per caso: va riportata dove appartiene.
        // La domanda e' "c'e' un prezzo da dire a chi compra?", e quel prezzo lo
        // calcoliamo gia' una volta sola in compute_price(). Si legge quello. Cosi'
        // il controllo resta uno, vale per tutti i tipi — presenti e futuri — e non
        // puo' piu' divergere dal payload, perche' E' il payload.
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
                // 'blocking' non e' un difetto di grado: e' l'impossibilita' di
                // comprare. Azzera il punteggio invece di scalarlo, perche' nessuna
                // qualita' di scheda rende vendibile un prodotto senza prezzo.
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
        // 1.0.130 — l'eta' del dato fa parte del contratto: get_meta dichiara
        // computed_at e max_staleness_hours, cosi' un agente sa che
        // available_values e' una fotografia e non uno stato istantaneo.
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
     * VOCABOLARIO CANONICO — fonte unica per schema, validazione REST, validazione
     * MCP, get_meta e test. Prima della 1.0.130 gli stessi elenchi erano duplicati
     * in sei punti fra class-mcp.php e class-api.php, ed e' cosi' che schema e
     * runtime avevano finito per promettere cose diverse.
     *
     * `accepted_values` e' il CONTRATTO: stabile, decide la validita'.
     * Da non confondere con `available_values` (get_cached_catalog_facets), che e'
     * la fotografia di questo catalogo e NON decide mai la validita' di una
     * richiesta: e' aggiornata al massimo ogni 12 ore dal cron, e rifiutare su un
     * dato vecchio mezza giornata negherebbe una ricerca legittima su un prodotto
     * appena pubblicato.
     */
    /**
     * Esito dell'ultimo prodotto valutato dal filtro gender. Proprieta' statiche e
     * non valore di ritorno perche' matches_derived_filters() e' un predicato
     * booleano usato in piu' punti: cambiarne la firma avrebbe toccato percorsi
     * che non hanno bisogno di questo dato.
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
     * Normalizzazione SOLO FORMALE: trim + lowercase ASCII. Nient'altro.
     *
     * Non e' un dettaglio implementativo, e' il confine del contratto. `MALE ` e
     * `male` sono lo stesso valore scritto diversamente; `uomo` e `male` sono due
     * parole diverse. Tradurre la seconda coppia significherebbe mettere in
     * KaliCart un'intelligenza che sta gia' nell'agente — e obbligherebbe noi a
     * decidere, per esempio, se `azzurro` sia `blue` o `light_blue`.
     *
     * `q` resta linguaggio naturale nella lingua dell'utente; i facet sono
     * linguaggio macchina.
     */
    public static function normalize_facet_value( $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        return strtolower( trim( (string) $value ) );
    }

    /**
     * @return array|null null se valido; altrimenti il payload d'errore strutturato.
     *                    L'agente deve poter correggere al primo colpo, senza una
     *                    chiamata informativa aggiuntiva: per questo l'errore porta
     *                    gia' accepted_values.
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
            // L'unico ordinamento su un campo che non cambia mai: e' quello che
            // rende ripetibile la camminata di un catalogo intero.
            'id'         => 'ID',
            default      => 'date',
        };
    }
}
