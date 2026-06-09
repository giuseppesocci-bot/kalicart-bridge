<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Quarantine
 *
 * Catalog health — query SQL dirette, niente loop su wc_get_product().
 * Overview stats: veloci. Quarantine list: paginata, lazy.
 */
class KaliCart_Bridge_Quarantine {
    private const MIN_TITLE_WORDS = 3;
    private const MIN_DESCRIPTION_LENGTH = 40;

    public static function get_report( bool $force = false ): array {
        $cache_key = 'kalicart_bridge_health_v2';
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) return $cached;
        }
        $report = self::build_report();
        set_transient( $cache_key, $report, 5 * MINUTE_IN_SECONDS );
        return $report;
    }

    public static function bust_cache(): void {
        delete_transient( 'kalicart_bridge_health_v2' );
        delete_transient( 'kalicart_bridge_meta' );
    }

    /**
     * Hook into WooCommerce/WP product save events to bust the cache automatically.
     */
    public static function init_hooks(): void {
        // Fired after a product is saved in admin
        add_action( 'woocommerce_update_product', [ __CLASS__, 'bust_cache' ] );
        add_action( 'woocommerce_new_product',    [ __CLASS__, 'bust_cache' ] );
        // Fired when post status changes (publish, draft, trash...)
        add_action( 'transition_post_status', function( $new, $old, $post ) {
            if ( $post->post_type === 'product' ) {
                self::bust_cache();
            }
        }, 10, 3 );
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    private static function build_report(): array {
        global $wpdb;

        // ── Totale prodotti pubblicati ────────────────────────────────────────
        $total = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
        );

        // ── Titolo troppo corto o non parlante ───────────────────────────────
        $bad_title = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
             AND (
                TRIM(post_title) = ''
                OR (LENGTH(TRIM(post_title)) - LENGTH(REPLACE(TRIM(post_title), ' ', '')) + 1) < %d
             )",
            self::MIN_TITLE_WORDS
        ) );

        // ── Senza immagine (thumbnail) ────────────────────────────────────────
        $no_image = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_thumbnail_id'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value='')"
        );

        // ── Senza descrizione (né lunga né corta) ─────────────────────────────
        $no_desc = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
             AND CHAR_LENGTH(TRIM(CONCAT(COALESCE(post_content,''),' ',COALESCE(post_excerpt,'')))) < %d",
            self::MIN_DESCRIPTION_LENGTH
        ) );

        // ── Senza categoria (solo Uncategorized) ──────────────────────────────
        $uncategorized_id = (int) get_option( 'default_product_cat', 0 );
        // prodotti che hanno SOLO la categoria default o nessuna
        // Prodotti che NON hanno nessuna categoria reale (escludendo Uncategorized)
        $no_cat = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             WHERE p.post_type='product' AND p.post_status='publish'
             AND p.ID NOT IN (
                SELECT DISTINCT tr.object_id
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                WHERE tt.taxonomy='product_cat' AND tt.term_id != %d
             )",
            $uncategorized_id
        ) );

        // ── Prezzo zero o mancante ────────────────────────────────────────────
        $no_price = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_price'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value='' OR CAST(pm.meta_value AS DECIMAL(10,2))<=0)"
        );

        // ── Senza SKU ─────────────────────────────────────────────────────────
        $no_sku = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_sku'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value='')"
        );

        // ── In stock ──────────────────────────────────────────────────────────
        $in_stock = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_stock_status'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND pm.meta_value='instock'"
        );

        // ── Score qualità globale ─────────────────────────────────────────────
        // Quarantine reasons weigh more than improvement-only signals.
        $issues_weighted = ( $bad_title * 25 + $no_desc * 30 + $no_cat * 30 + $no_price * 25 + $no_image * 8 + $no_sku * 4 );
        $max_weighted    = $total * 122;
        $avg_score       = $total > 0 ? max( 0, (int) round( 100 - ( $issues_weighted / max( 1, $max_weighted ) ) * 100 ) ) : 100;
        // Penalità store-level: return policy non configurata
        if ( empty( get_option( 'kalicart_bridge_return_policy_url', '' ) ) ) {
            $avg_score = max( 0, $avg_score - 10 );
        }

        // ── Prodotti segnalati per issue ─────────────────────────────────────
        $issue_product_ids = self::get_issue_product_ids( $wpdb, $uncategorized_id );
        $quarantine_ids = array_values( array_unique( array_merge(
            $issue_product_ids['TITLE_TOO_SHORT'],
            $issue_product_ids['NO_DESCRIPTION'],
            $issue_product_ids['NO_CATEGORY'],
            $issue_product_ids['ZERO_PRICE']
        ) ) );
        $quarantine_count = count( $quarantine_ids );

        // ── Quarantine list (dettaglio, max 300) ──────────────────────────────
        $quarantined_products = self::build_quarantine_list( array_slice( $quarantine_ids, 0, 300 ), $uncategorized_id );
        $out_of_stock_products = self::build_out_of_stock_list( 300 );
        $issue_products = [
            'NO_IMAGE' => self::build_issue_list( array_slice( $issue_product_ids['NO_IMAGE'], 0, 300 ), 'NO_IMAGE', 'No image', 'image' ),
            'NO_SKU'   => self::build_issue_list( array_slice( $issue_product_ids['NO_SKU'], 0, 300 ), 'NO_SKU', 'No SKU', 'sku' ),
        ];

        // ── Suggestions ───────────────────────────────────────────────────────
        $suggestions = self::build_suggestions( $total, $bad_title, $no_image, $no_desc, $no_cat, $no_price, $no_sku );

        return [
            'generated_at'     => gmdate( 'c' ),
            'total_products'   => $total,
            'in_stock_count'   => $in_stock,
            'out_of_stock_count' => $total - $in_stock,
            'healthy_count'    => $total - $quarantine_count,
            'quarantine_count' => $quarantine_count,
            'average_score'    => $avg_score,
            'issues' => [
                'bad_title'  => $bad_title,
                'no_image'   => $no_image,
                'no_description' => $no_desc,
                'no_category' => $no_cat,
                'zero_price'  => $no_price,
                'no_sku'      => $no_sku,
            ],
            'suggestions'          => $suggestions,
            'quarantined_products' => $quarantined_products,
            'out_of_stock_products' => $out_of_stock_products,
            'issue_products'       => $issue_products,
            'issue_product_ids'    => $issue_product_ids,
        ];
    }

    // ── IDs dei prodotti con almeno un flag ───────────────────────────────────

    private static function get_issue_product_ids( $wpdb, int $uncategorized_id ): array {
        // Titolo troppo corto o non parlante
        $bad_title = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
             AND (
                TRIM(post_title) = ''
                OR (LENGTH(TRIM(post_title)) - LENGTH(REPLACE(TRIM(post_title), ' ', '')) + 1) < %d
             )",
            self::MIN_TITLE_WORDS
        ) );

        // Senza immagine
        $no_image = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_thumbnail_id'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value='')"
        );

        // Senza descrizione
        $no_desc = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
             AND CHAR_LENGTH(TRIM(CONCAT(COALESCE(post_content,''),' ',COALESCE(post_excerpt,'')))) < %d",
            self::MIN_DESCRIPTION_LENGTH
        ) );

        // Senza categoria reale
        $no_cat = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type='product' AND p.post_status='publish'
             AND p.ID NOT IN (
                SELECT DISTINCT tr.object_id
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                WHERE tt.taxonomy='product_cat' AND tt.term_id != %d
             )",
            $uncategorized_id
        ) );

        // Prezzo zero
        $no_price = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_price'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value='' OR CAST(pm.meta_value AS DECIMAL(10,2))<=0)"
        );

        // Senza SKU
        $no_sku = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_sku'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value='')"
        );

        return [
            'TITLE_TOO_SHORT' => array_map( 'intval', $bad_title ),
            'NO_IMAGE'       => array_map( 'intval', $no_image ),
            'NO_DESCRIPTION' => array_map( 'intval', $no_desc ),
            'NO_CATEGORY'    => array_map( 'intval', $no_cat ),
            'ZERO_PRICE'     => array_map( 'intval', $no_price ),
            'NO_SKU'         => array_map( 'intval', $no_sku ),
        ];
    }

    // ── Quarantine list dettagliata ───────────────────────────────────────────

    private static function build_quarantine_list( array $ids, int $uncategorized_id ): array {
        if ( empty( $ids ) ) return [];

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt,
                    pm_thumb.meta_value AS has_thumb,
                    pm_price.meta_value AS price,
                    pm_sku.meta_value   AS sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_thumb ON pm_thumb.post_id=p.ID AND pm_thumb.meta_key='_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} pm_price  ON pm_price.post_id=p.ID  AND pm_price.meta_key='_price'
             LEFT JOIN {$wpdb->postmeta} pm_sku    ON pm_sku.post_id=p.ID    AND pm_sku.meta_key='_sku'
             WHERE p.ID IN ($placeholders)",  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- intentional bulk query, cached via transient
            ...$ids
        ) );

        $out = [];
        foreach ( $rows as $row ) {
            $flags = [];
            if ( self::title_word_count( $row->post_title ) < self::MIN_TITLE_WORDS )
                                                                   $flags[] = [ 'code' => 'TITLE_TOO_SHORT', 'severity' => 'high',   'label' => 'Title has fewer than 3 words' ];
            if ( strlen( trim( $row->post_content . ' ' . $row->post_excerpt ) ) < self::MIN_DESCRIPTION_LENGTH )
                                                                   $flags[] = [ 'code' => 'NO_DESCRIPTION', 'severity' => 'high',   'label' => 'Description too short' ];
            if ( ! self::product_has_real_category( (int) $row->ID, $uncategorized_id ) )
                                                                   $flags[] = [ 'code' => 'NO_CATEGORY',    'severity' => 'high',   'label' => 'No category' ];
            if ( empty( $row->price ) || (float) $row->price <= 0 ) $flags[] = [ 'code' => 'ZERO_PRICE',    'severity' => 'medium', 'label' => 'Price is zero or missing' ];

            $deductions = 0;
            foreach ( $flags as $f ) {
                $deductions += match( $f['severity'] ) { 'high' => 30, 'medium' => 15, default => 5 };
            }

            $out[] = [
                'id'    => (int) $row->ID,
                'name'  => $row->post_title,
                'url'   => self::product_edit_url( (int) $row->ID ),
                'score' => max( 0, 100 - $deductions ),
                'flags' => $flags,
            ];
        }

        usort( $out, fn( $a, $b ) => $a['score'] <=> $b['score'] );
        return $out;
    }

    private static function product_edit_url( int $product_id ): string {
        return admin_url( 'post.php?post=' . $product_id . '&action=edit' );
    }

    private static function title_word_count( string $title ): int {
        $words = preg_split( '/\s+/', trim( wp_strip_all_tags( $title ) ) );
        if ( empty( $words ) ) return 0;

        return count( array_filter( $words, fn( $word ) => preg_match( '/[\p{L}\p{N}]/u', $word ) ) );
    }

    private static function build_issue_list( array $ids, string $code, string $label, string $severity = 'low' ): array {
        if ( empty( $ids ) ) return [];

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($placeholders) ORDER BY post_title ASC",  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- intentional bulk query, cached via transient
            ...$ids
        ) );

        $score_map = [ 'high' => 30, 'medium' => 15, 'low' => 5, 'image' => 8, 'sku' => 4 ];
        $deduction = $score_map[ $severity ] ?? 0;

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'id'    => (int) $row->ID,
                'name'  => $row->post_title,
                'url'   => self::product_edit_url( (int) $row->ID ),
                'score' => max( 0, 100 - $deduction ),
                'flags' => [
                    [ 'code' => $code, 'severity' => $severity, 'label' => $label ],
                ],
            ];
        }

        return $out;
    }

    private static function build_out_of_stock_list( int $limit = 300 ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional, cached via transient
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_stock_status'
             WHERE p.post_type='product' AND p.post_status='publish'
             AND pm.meta_value!='instock'
             ORDER BY p.post_title ASC
             LIMIT %d",
            $limit
        ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'id'    => (int) $row->ID,
                'name'  => $row->post_title,
                'url'   => self::product_edit_url( (int) $row->ID ),
                'score' => 100,
                'flags' => [
                    [ 'code' => 'OUT_OF_STOCK', 'severity' => 'medium', 'label' => 'Out of stock' ],
                ],
            ];
        }

        return $out;
    }

    private static function product_has_real_category( int $product_id, int $uncategorized_id ): bool {
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return false;
        foreach ( $terms as $term_id ) {
            if ( (int) $term_id !== $uncategorized_id ) return true;
        }
        return false;
    }

    // ── Suggestions ───────────────────────────────────────────────────────────

    private static function build_suggestions( int $total, int $bad_title, int $no_image, int $no_desc, int $no_cat, int $no_price, int $no_sku ): array {
        $s = [];

        $admin_url = admin_url( 'edit.php?post_type=product' );

        if ( $bad_title > 0 ) $s[] = [ 'priority' => 'high',   'code' => 'TITLE_TOO_SHORT', 'label' => 'Improve product titles',    'detail' => 'Short or non-speaking titles reduce catalog computability.',          'affected' => $bad_title, 'admin_url' => $admin_url ];
        if ( $no_image > 0 )  $s[] = [ 'priority' => 'low',    'code' => 'NO_IMAGE',       'label' => 'Add product images',       'detail' => 'Missing images reduce discoverability but do not block agent queries.', 'affected' => $no_image, 'admin_url' => $admin_url ];
        if ( $no_desc > 0 )   $s[] = [ 'priority' => 'high',   'code' => 'NO_DESCRIPTION', 'label' => 'Add product descriptions', 'detail' => 'Missing or very short descriptions are weak signals for AI agents.',   'affected' => $no_desc,  'admin_url' => $admin_url ];
        if ( $no_cat > 0 )    $s[] = [ 'priority' => 'high',   'code' => 'NO_CATEGORY',    'label' => 'Assign categories',        'detail' => 'Uncategorized products are invisible to category-based agent queries.','affected' => $no_cat,   'admin_url' => $admin_url ];
        if ( $no_price > 0 )  $s[] = [ 'priority' => 'medium', 'code' => 'ZERO_PRICE',     'label' => 'Fix zero-price products',  'detail' => 'Products with no price are excluded from commerce-intent pipelines.',  'affected' => $no_price, 'admin_url' => $admin_url ];
        if ( $no_sku > 0 )    $s[] = [ 'priority' => 'low',    'code' => 'NO_SKU',         'label' => 'Add SKU codes',            'detail' => 'SKUs enable precise product identification and deduplication by agents.','affected' => $no_sku,   'admin_url' => $admin_url ];

        // Return policy suggestion
        $return_policy_url = get_option( 'kalicart_bridge_return_policy_url', '' );
        if ( empty( $return_policy_url ) ) {
            $settings_url = admin_url( 'admin.php?page=kalicart-bridge#settings' );
            $s[] = [
                'priority'  => 'medium',
                'code'      => 'NO_RETURN_POLICY',
                'label'     => 'Configure return policy URL',
                'detail'    => 'Agents cannot inform buyers about your return conditions. Add your return policy page URL in Settings.',
                'affected'  => 0,
                'admin_url' => $settings_url,
            ];
        }

        return $s;
    }
}
