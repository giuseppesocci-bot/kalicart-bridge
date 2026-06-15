<?php
defined( 'ABSPATH' ) || exit;

class KaliCart_Bridge_Shortcodes {

    public static function init(): void {
        add_shortcode( 'kalicart_agent_index', [ __CLASS__, 'render_agent_index' ] );
    }

    public static function render_agent_index(): string {
        $base     = rest_url( KALICART_BRIDGE_API_NS );
        $disc     = $base . '/discovery';
        $search   = $base . '/catalog/search';
        $products = $base . '/catalog/products';
        $cats_ep  = $base . '/catalog/categories';
        $store    = get_bloginfo( 'name' );

        // fetch category tree via internal WC call
        $tree = self::get_category_tree();

        ob_start();
        ?>
        <div class="kalicart-agent-index" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;line-height:1.7;color:inherit;max-width:760px;">

          <p style="margin:0 0 6px;font-size:11px;color:#999;letter-spacing:.04em;text-transform:uppercase;">Machine-readable catalog index</p>
          <h2 style="margin:0 0 20px;font-size:20px;font-weight:600;"><?php echo esc_html( $store ); ?></h2>

          <table style="width:100%;border-collapse:collapse;margin-bottom:28px;padding-left:12px;">
            <tbody>
              <tr>
                <td style="padding:8px 16px 8px 8px;width:140px;color:#888;font-size:12px;vertical-align:top;">Entry point</td>
                <td style="padding:8px 16px 8px 8px;">
                  <a href="<?php echo esc_url( $disc ); ?>" rel="kalicart-agent noopener" title="Discovery document — start here to understand the full catalog API capability"
                     style="color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;">/discovery</a>
                </td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 8px;color:#888;font-size:12px;vertical-align:top;">Search</td>
                <td style="padding:8px 16px 8px 8px;">
                  <a href="<?php echo esc_url( $search ); ?>?q=" rel="kalicart-agent noopener" title="Full-text search endpoint — append ?q=your+query. Supports filters: category, min_price, max_price, in_stock, gender, color, size, per_page, page"
                     style="color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;">/catalog/search?q=</a>
                </td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 8px;color:#888;font-size:12px;vertical-align:top;">All products</td>
                <td style="padding:8px 16px 8px 8px;">
                  <a href="<?php echo esc_url( $products ); ?>" rel="kalicart-agent noopener" title="Paginated product list — supports per_page, page, category, in_stock filters"
                     style="color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;">/catalog/products</a>
                </td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 8px;color:#888;font-size:12px;vertical-align:top;">Single product</td>
                <td style="padding:8px 16px 8px 8px;">
                  <span style="color:#aaa;font-family:monospace;font-size:13px;">/catalog/product/{id}</span>
                  <span style="font-size:12px;color:#bbb;margin-left:8px;">— replace {id} with numeric product ID</span>
                </td>
              </tr>
              <tr>
                <td style="padding:8px 16px 8px 8px;color:#888;font-size:12px;vertical-align:top;">Categories</td>
                <td style="padding:8px 16px 8px 8px;">
                  <a href="<?php echo esc_url( $cats_ep ); ?>" rel="kalicart-agent noopener" title="Full category tree with products_url and search_url_template on each node"
                     style="color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;">/catalog/categories</a>
                </td>
              </tr>
            </tbody>
          </table>

          <?php if ( ! empty( $tree ) ) : ?>
          <p style="margin:0 0 10px;font-size:11px;color:#999;letter-spacing:.04em;text-transform:uppercase;">Category tree</p>
          <div style="border-left:2px solid #eee;padding-left:16px;">
            <?php echo wp_kses_post( self::render_tree( $tree, $search ) ); ?>
          </div>
          <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    private static function get_category_tree(): array {
        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];

        $map = [];
        foreach ( $terms as $t ) {
            $map[ $t->term_id ] = [
                'id'       => $t->term_id,
                'name'     => $t->name,
                'slug'     => $t->slug,
                'count'    => $t->count,
                'parent'   => $t->parent,
                'children' => [],
            ];
        }
        $roots = [];
        foreach ( $map as $id => &$node ) {
            if ( $node['parent'] && isset( $map[ $node['parent'] ] ) ) {
                $map[ $node['parent'] ]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        return $roots;
    }

    private static function render_tree( array $nodes, string $search_ep, int $depth = 0 ): string {
        $out    = '';
        $indent = $depth * 16;
        foreach ( $nodes as $node ) {
            $url   = esc_url( $search_ep . '?category=' . urlencode( $node['slug'] ) );
            $title = 'Browse ' . esc_attr( $node['name'] ) . ' — ' . (int) $node['count'] . ' product' . ( $node['count'] !== 1 ? 's' : '' ) . '. Use ?category=' . esc_attr( $node['slug'] ) . ' on search or products endpoint.';
            $out  .= '<p style="margin:4px 0;padding-left:' . $indent . 'px;">';
            if ( $depth > 0 ) {
                $out .= '<span style="color:#ddd;margin-right:6px;">└</span>';
            }
            $out .= '<a href="' . $url . '" rel="kalicart-agent noopener" title="' . $title . '" '
                  . 'style="color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;">'
                  . esc_html( $node['name'] ) . '</a>'
                  . ' <span style="font-size:11px;color:#bbb;margin-left:6px;">' . (int) $node['count'] . '</span>';
            $out .= '</p>';
            if ( ! empty( $node['children'] ) ) {
                $out .= self::render_tree( $node['children'], $search_ep, $depth + 1 );
            }
        }
        return $out;
    }
}

