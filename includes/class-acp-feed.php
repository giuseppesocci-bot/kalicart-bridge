<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_ACP_Feed
 *
 * OpenAI Product Feed generator (Agentic Commerce Protocol, discovery tier).
 * Spec: https://developers.openai.com/commerce/specs/file-upload/products
 *
 * Generates a JSON Lines feed (one product/variant per line) that a merchant
 * submits to OpenAI to appear in ChatGPT Shopping search results.
 * DISCOVERY ONLY by design: is_eligible_search=true, is_eligible_checkout=false.
 * Checkout stays on the merchant storefront - the Bridge read-only philosophy,
 * which also matches OpenAI's shift toward merchant-owned checkout.
 *
 * The feed file is a dedicated export for ONE consumer (OpenAI's indexer).
 * It is deliberately NOT advertised in discovery/robots/sitemap: the generic
 * agent surface remains the paginated catalog API (no monoliths, lesson
 * 2026-06-25). The file URL carries a random token.
 */
class KaliCart_Bridge_ACP_Feed {

	const OPTION = 'kalicart_bridge_acp_feed';
	const CRON_HOOK = 'kalicart_bridge_acp_feed_generate';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'generate' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
	}

	// ── options ─────────────────────────────────────────────────────────────

	public static function get_options(): array {
		$defaults = [
			'enabled'            => false,
			'brand_fallback'     => get_bloginfo( 'name' ),
			'return_policy_url'  => self::default_return_policy_url(),
			'target_countries'   => self::default_store_country(),
			'token'              => '',
		];
		$opts = get_option( self::OPTION, [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts = array_merge( $defaults, $opts );
		if ( '' === $opts['token'] ) {
			$opts['token'] = wp_generate_password( 20, false, false );
			update_option( self::OPTION, $opts, false );
		}
		return $opts;
	}

	private static function default_return_policy_url(): string {
		$page = get_page_by_path( 'refund_returns' );
		if ( $page && 'publish' === $page->post_status ) {
			return (string) get_permalink( $page );
		}
		return '';
	}

	private static function default_store_country(): string {
		$c = (string) get_option( 'woocommerce_default_country', '' );
		return strtoupper( explode( ':', $c )[0] ?? '' );
	}

	public static function maybe_schedule(): void {
		$opts = self::get_options();
		if ( $opts['enabled'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! $opts['enabled'] && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	// ── generation ──────────────────────────────────────────────────────────

	/** Feed directory inside uploads, with an index guard. */
	private static function feed_dir(): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'kalicart-bridge';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.html', '' );
		}
		return $dir;
	}

	public static function feed_url(): string {
		$opts = self::get_options();
		$up   = wp_upload_dir();
		return trailingslashit( $up['baseurl'] ) . 'kalicart-bridge/acp-products-' . $opts['token'] . '.jsonl';
	}

	/**
	 * Build the full feed. Batched, memory-safe. Returns stats.
	 */
	public static function generate(): array {
		$opts    = self::get_options();
		$dir     = self::feed_dir();
		$path    = $dir . '/acp-products-' . $opts['token'] . '.jsonl';
		$tmp     = $path . '.tmp';
		$fh      = fopen( $tmp, 'w' );
		$stats   = [ 'rows' => 0, 'products' => 0, 'skipped' => 0, 'missing_brand' => 0, 'missing_image' => 0, 'generated_at' => gmdate( 'c' ) ];
		if ( ! $fh ) {
			return $stats;
		}

		$paged    = 1;
		$seen_ids = [];
		do {
			$q = new WP_Query( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'paged'          => $paged,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			] );
			foreach ( $q->posts as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product || ! $product->is_visible() || 'grouped' === $product->get_type() ) {
					$stats['skipped']++;
					continue;
				}
				$rows = self::rows_for_product( $product, $opts, $stats );
				foreach ( $rows as $row ) {
					if ( isset( $seen_ids[ $row['item_id'] ] ) ) {
						// item_id MUST be unique per variant (spec). Duplicate SKUs across
						// variants get a deterministic suffix instead of being dropped.
						$row['item_id'] .= '-' . substr( md5( $row['url'] . wp_json_encode( $row['variant_dict'] ?? '' ) ), 0, 6 );
						$stats['deduped_ids'] = (int) ( $stats['deduped_ids'] ?? 0 ) + 1;
					}
					$seen_ids[ $row['item_id'] ] = true;
					fwrite( $fh, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
					$stats['rows']++;
				}
				if ( $rows ) {
					$stats['products']++;
				}
			}
			$more = $paged < (int) $q->max_num_pages;
			$paged++;
		} while ( $more );

		fclose( $fh );
		rename( $tmp, $path );

		// gzip copy for large catalogs
		$gz = gzopen( $path . '.gz', 'w9' );
		if ( $gz ) {
			gzwrite( $gz, file_get_contents( $path ) );
			gzclose( $gz );
		}

		$opts_stored               = get_option( self::OPTION, [] );
		$opts_stored['last_stats'] = $stats;
		update_option( self::OPTION, is_array( $opts_stored ) ? $opts_stored : [ 'last_stats' => $stats ], false );
		return $stats;
	}

	/** One row per simple/external product, one per variation for variable products. */
	private static function rows_for_product( WC_Product $product, array $opts, array &$stats ): array {
		$rows = [];
		if ( $product->is_type( 'variable' ) ) {
			$parent_row = null;
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || ! $v->is_purchasable() ) {
					continue;
				}
				$row = self::base_row( $v, $opts, $stats, $product );
				if ( $row ) {
					$row['group_id']               = 'wc-' . $product->get_id();
					$row['listing_has_variations'] = true;
					$dict = [];
					foreach ( $v->get_attributes() as $attr => $val ) {
						if ( '' === (string) $val ) {
							continue;
						}
						$label          = wc_attribute_label( str_replace( 'attribute_', '', $attr ), $product );
						$dict[ $label ] = (string) $val;
					}
					if ( $dict ) {
						$row['variant_dict'] = $dict;
					}
					$rows[] = $row;
				}
			}
			if ( ! $rows ) {
				// variable without purchasable variations: fall back to the parent as one row
				$row = self::base_row( $product, $opts, $stats );
				if ( $row ) {
					$rows[] = $row;
				}
			}
			return $rows;
		}
		$row = self::base_row( $product, $opts, $stats );
		return $row ? [ $row ] : [];
	}

	private static function base_row( WC_Product $p, array $opts, array &$stats, ?WC_Product $parent = null ): ?array {
		$display    = $parent ?: $p;
		$title      = self::clip( $display->get_name() . ( $parent ? ' - ' . wc_get_formatted_variation( $p, true, false, false ) : '' ), 150 );
		$desc       = self::clip( self::plain( $display->get_description() ?: $display->get_short_description() ?: $display->get_name() ), 5000 );
		$image      = wp_get_attachment_image_url( $p->get_image_id() ?: $display->get_image_id(), 'full' );
		if ( ! $image ) {
			$stats['missing_image']++;
			return null; // image_url is REQUIRED by spec
		}
		$brand = self::resolve_brand( $display );
		if ( '' === $brand ) {
			$brand = (string) $opts['brand_fallback'];
			$stats['missing_brand']++;
		}
		$currency = get_woocommerce_currency();
		$regular  = $p->get_regular_price();
		$priceval = ( '' !== $regular ) ? $regular : $p->get_price();
		if ( '' === (string) $priceval ) {
			return null; // price is REQUIRED
		}

		$row = [
			'is_eligible_search'   => true,
			'is_eligible_checkout' => false,
			'item_id'              => ( $parent ? $p->get_sku( 'edit' ) : $p->get_sku() ) ?: 'wc-' . $p->get_id(),
			'title'                => $title,
			'description'          => $desc,
			'url'                  => $display->get_permalink(),
			'brand'                => self::clip( $brand, 70 ),
			'image_url'            => $image,
			'price'                => wc_format_decimal( $priceval, 2 ) . ' ' . $currency,
			'availability'         => self::availability( $p ),
			'seller_name'          => get_bloginfo( 'name' ),
			'seller_url'           => home_url( '/' ),
			'return_policy'        => (string) $opts['return_policy_url'],
			'target_countries'     => array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $opts['target_countries'] ) ) ) ) ),
			'store_country'        => self::default_store_country(),
		];

		if ( $p->is_on_sale() && '' !== (string) $p->get_sale_price() ) {
			$row['sale_price'] = wc_format_decimal( $p->get_sale_price(), 2 ) . ' ' . $currency;
		}
		$gallery = array_slice( array_filter( array_map( fn( $id ) => wp_get_attachment_image_url( $id, 'full' ), $display->get_gallery_image_ids() ) ), 0, 10 );
		if ( $gallery ) {
			$row['additional_image_urls'] = $gallery;
		}
		if ( method_exists( $p, 'get_global_unique_id' ) && $p->get_global_unique_id() ) {
			$row['gtin'] = $p->get_global_unique_id();
		}
		$cats = wp_get_post_terms( $display->get_id(), 'product_cat', [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $cats ) && $cats ) {
			$row['product_category'] = implode( ' > ', $cats );
		}
		if ( $p->get_weight() ) {
			$row['weight'] = $p->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
		}
		if ( $p->is_virtual() || $p->is_downloadable() ) {
			$row['is_digital'] = true;
		}
		if ( $display->get_review_count() > 0 ) {
			$row['review_count'] = (int) $display->get_review_count();
			$row['star_rating']  = (float) $display->get_average_rating();
		}
		$row['condition'] = 'new';
		return $row;
	}

	private static function resolve_brand( WC_Product $p ): string {
		foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand', 'pa_marca' ] as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = wp_get_post_terms( $p->get_id(), $tax, [ 'fields' => 'names' ] );
				if ( ! is_wp_error( $terms ) && $terms ) {
					return (string) $terms[0];
				}
			}
		}
		return '';
	}

	private static function availability( WC_Product $p ): string {
		switch ( $p->get_stock_status() ) {
			case 'instock':
				return 'in_stock';
			case 'onbackorder':
				return 'backorder';
			case 'outofstock':
				return 'out_of_stock';
			default:
				return 'unknown';
		}
	}

	private static function plain( string $html ): string {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
	}

	private static function clip( string $s, int $max ): string {
		return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max - 1 ) . chr( 0xE2 ) . chr( 0x80 ) . chr( 0xA6 ) : $s;
	}

	// ── admin ───────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_submenu_page(
			'kalicart-bridge',
			'ChatGPT Shopping',
			'ChatGPT Shopping',
			'manage_woocommerce',
			'kalicart-bridge-acp',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( isset( $_POST['kb_acp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kb_acp_nonce'] ) ), 'kb_acp_save' ) ) {
			$opts = self::get_options();
			$opts['enabled']           = ! empty( $_POST['enabled'] );
			$opts['brand_fallback']    = sanitize_text_field( wp_unslash( $_POST['brand_fallback'] ?? '' ) );
			$opts['return_policy_url'] = esc_url_raw( wp_unslash( $_POST['return_policy_url'] ?? '' ) );
			$opts['target_countries']  = strtoupper( preg_replace( '/[^A-Za-z,\s]/', '', wp_unslash( $_POST['target_countries'] ?? '' ) ) );
			update_option( self::OPTION, $opts, false );
			self::maybe_schedule();
			if ( isset( $_POST['regenerate'] ) ) {
				self::generate();
			}
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		$opts  = self::get_options();
		$stats = $opts['last_stats'] ?? null;
		echo '<div class="wrap"><h1>ChatGPT Shopping - OpenAI Product Feed</h1>';
		echo '<p>Generates an OpenAI Product Feed (Agentic Commerce Protocol, discovery tier) so this store can appear in ChatGPT Shopping results. Checkout stays on your storefront. Apply at <a href="https://chatgpt.com/merchants" target="_blank" rel="noopener">chatgpt.com/merchants</a> and submit the feed URL below.</p>';
		if ( $stats ) {
			echo '<p><strong>Last generation:</strong> ' . esc_html( $stats['generated_at'] ) . ' - ' . (int) $stats['rows'] . ' rows / ' . (int) $stats['products'] . ' products';
			if ( $stats['missing_brand'] ) {
				echo ' - <span style="color:#b45309">' . (int) $stats['missing_brand'] . ' without brand (fallback used)</span>';
			}
			if ( $stats['missing_image'] ) {
				echo ' - <span style="color:#b91c1c">' . (int) $stats['missing_image'] . ' skipped: missing image (required by spec)</span>';
			}
			echo '</p><p><strong>Feed URL:</strong> <code>' . esc_html( self::feed_url() ) . '</code> (+ <code>.gz</code>)</p>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'kb_acp_save', 'kb_acp_nonce' );
		echo '<table class="form-table">';
		echo '<tr><th>Enable daily generation</th><td><input type="checkbox" name="enabled" ' . checked( $opts['enabled'], true, false ) . '></td></tr>';
		echo '<tr><th>Brand fallback</th><td><input type="text" class="regular-text" name="brand_fallback" value="' . esc_attr( $opts['brand_fallback'] ) . '"><p class="description">Used when a product has no brand taxonomy/attribute. brand is required by the spec.</p></td></tr>';
		echo '<tr><th>Return policy URL</th><td><input type="url" class="regular-text" name="return_policy_url" value="' . esc_attr( $opts['return_policy_url'] ) . '"><p class="description">Required by the spec. Defaults to your Refund and Returns page.</p></td></tr>';
		echo '<tr><th>Target countries</th><td><input type="text" class="regular-text" name="target_countries" value="' . esc_attr( $opts['target_countries'] ) . '"><p class="description">Comma-separated ISO 3166-1 alpha-2 codes (e.g. US, IT, DE).</p></td></tr>';
		echo '</table>';
		submit_button( 'Save' );
		echo '<button class="button button-secondary" name="regenerate" value="1">Save &amp; regenerate now</button>';
		echo '</form></div>';
	}
}

