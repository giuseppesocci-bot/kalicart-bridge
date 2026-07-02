<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_ACP_Feed
 *
 * OpenAI Product Feed generator (Agentic Commerce Protocol, discovery tier).
 * Spec: https://developers.openai.com/commerce/specs/file-upload/products
 *
 * Contract (reviewed 2026-07-02, external review incorporated):
 * - DISCOVERY ONLY: is_eligible_search=true, is_eligible_checkout=false.
 *   Checkout stays on the merchant storefront (Bridge read-only philosophy).
 * - Delivery is PUSH on a channel OpenAI assigns after merchant approval
 *   (application at chatgpt.com/merchants). The plugin generates and
 *   validates the file; it never claims a "submit this URL" flow.
 * - Row policy: missing GLOBAL config (return policy, countries) = full
 *   generation block; product missing image or brand = excluded + counted;
 *   every emitted row = schema-conformant (per-row validator, hard gate).
 * - Atomic: build+validate a temp file, stream-gzip it, replace the last
 *   good snapshot ONLY if everything passes. A failure never destroys the
 *   previous valid feed. Transient lock against concurrent runs.
 * - item_id = wc-{product_id} / wc-{variation_id}: stable, unique, <=100
 *   chars by construction. No SKU-based ids, no dedup needed.
 * - Stable filename (acp-products.jsonl[.gz]) inside a tokenized directory:
 *   ready for SFTP upload, not guessable, not advertised in discovery/robots.
 */
class KaliCart_Bridge_ACP_Feed {

	const OPTION    = 'kalicart_bridge_acp_feed';
	const CRON_HOOK = 'kalicart_bridge_acp_feed_generate';
	const LOCK      = 'kalicart_bridge_acp_feed_lock';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'generate' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
	}

	// ── options ─────────────────────────────────────────────────────────────

	public static function get_options(): array {
		$defaults = [
			'enabled'           => false,
			'brand_fallback'    => '', // opt-in only: empty = products without brand are excluded
			'return_policy_url' => self::default_return_policy_url(),
			'target_countries'  => implode( ',', self::default_target_countries() ),
			'token'             => '',
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

	private static function store_country(): string {
		$c = (string) get_option( 'woocommerce_default_country', '' );
		return strtoupper( explode( ':', $c )[0] ?? '' );
	}

	/** Derived from WooCommerce selling locations, as declared. */
	private static function default_target_countries(): array {
		$mode = get_option( 'woocommerce_allowed_countries', 'all' );
		if ( 'specific' === $mode ) {
			$list = (array) get_option( 'woocommerce_specific_allowed_countries', [] );
			$list = array_values( array_filter( array_map( 'strtoupper', $list ) ) );
			if ( $list ) {
				return $list;
			}
		}
		$base = self::store_country();
		return $base ? [ $base ] : [];
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

	// ── paths ───────────────────────────────────────────────────────────────

	private static function feed_dir( array $opts ): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'kalicart-bridge/' . $opts['token'];
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( trailingslashit( $up['basedir'] ) . 'kalicart-bridge/index.html', '' );
			@file_put_contents( $dir . '/index.html', '' );
		}
		return $dir;
	}

	public static function feed_url(): string {
		$opts = self::get_options();
		$up   = wp_upload_dir();
		return trailingslashit( $up['baseurl'] ) . 'kalicart-bridge/' . $opts['token'] . '/acp-products.jsonl';
	}

	// ── generation (atomic, locked, gated) ──────────────────────────────────

	public static function generate(): array {
		if ( get_transient( self::LOCK ) ) {
			return [ 'error' => 'locked', 'detail' => 'Another generation is in progress.' ];
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );
		$stats = self::generate_inner();
		delete_transient( self::LOCK );

		$opts_stored               = get_option( self::OPTION, [] );
		$opts_stored               = is_array( $opts_stored ) ? $opts_stored : [];
		$opts_stored['last_stats'] = $stats;
		update_option( self::OPTION, $opts_stored, false );
		return $stats;
	}

	private static function generate_inner(): array {
		$opts  = self::get_options();
		$stats = [
			'rows' => 0, 'products' => 0, 'excluded_no_image' => 0, 'excluded_no_brand' => 0,
			'excluded_invalid' => 0, 'invalid_examples' => [], 'generated_at' => gmdate( 'c' ),
		];

		// GLOBAL config gate: incomplete required store config = full block.
		$countries = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $opts['target_countries'] ) ) ) ) );
		$config_errors = [];
		if ( '' === (string) $opts['return_policy_url'] ) {
			$config_errors[] = 'return_policy_url is required (set it in this page or publish the Woo Refund/Returns page)';
		}
		if ( '' === self::store_country() ) {
			$config_errors[] = 'WooCommerce store base country is not set';
		}
		foreach ( $countries as $c ) {
			if ( ! preg_match( '/^[A-Z]{2}$/', $c ) ) {
				$config_errors[] = 'invalid target country code: ' . $c;
			}
		}
		if ( ! $countries ) {
			$config_errors[] = 'target_countries is empty';
		}
		if ( $config_errors ) {
			$stats['error'] = 'config_incomplete';
			$stats['config_errors'] = $config_errors;
			return $stats; // last good snapshot untouched
		}

		$dir  = self::feed_dir( $opts );
		$path = $dir . '/acp-products.jsonl';
		$tmp  = $path . '.tmp';
		$fh   = fopen( $tmp, 'w' );
		if ( ! $fh ) {
			$stats['error'] = 'cannot_write';
			return $stats;
		}

		$paged = 1;
		do {
			$q = new WP_Query( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'paged'          => $paged,
				'fields'         => 'ids',
			] );
			foreach ( $q->posts as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product || ! $product->is_visible() || 'grouped' === $product->get_type() ) {
					continue;
				}
				$rows      = self::rows_for_product( $product, $opts, $countries, $stats );
				$row_count = 0;
				foreach ( $rows as $row ) {
					$errors = self::validate_row( $row );
					if ( $errors ) {
						$stats['excluded_invalid']++;
						if ( count( $stats['invalid_examples'] ) < 5 ) {
							$stats['invalid_examples'][] = $row['item_id'] . ': ' . implode( '; ', $errors );
						}
						continue; // every emitted row must be conformant
					}
					fwrite( $fh, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
					$stats['rows']++;
					$row_count++;
				}
				if ( $row_count ) {
					$stats['products']++;
				}
			}
			$more = $paged < (int) $q->max_num_pages;
			$paged++;
		} while ( $more );
		fclose( $fh );

		if ( 0 === $stats['rows'] ) {
			@unlink( $tmp );
			$stats['error'] = 'empty_feed';
			return $stats; // never replace a good snapshot with an empty one
		}

		// streamed gzip from the validated temp file (no full-file memory load)
		$gz_tmp = $tmp . '.gz';
		$in     = fopen( $tmp, 'rb' );
		$gz     = gzopen( $gz_tmp, 'w9' );
		if ( ! $in || ! $gz ) {
			@unlink( $tmp );
			$stats['error'] = 'gzip_failed';
			return $stats;
		}
		while ( ! feof( $in ) ) {
			gzwrite( $gz, fread( $in, 512 * 1024 ) );
		}
		fclose( $in );
		gzclose( $gz );

		// atomic swap: only now the last good snapshot is replaced
		rename( $tmp, $path );
		rename( $gz_tmp, $path . '.gz' );
		return $stats;
	}

	// ── row building ────────────────────────────────────────────────────────

	private static function rows_for_product( WC_Product $product, array $opts, array $countries, array &$stats ): array {
		$rows = [];
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || ! $v->is_purchasable() ) {
					continue;
				}
				$row = self::base_row( $v, $opts, $countries, $stats, $product );
				if ( $row ) {
					$row['group_id']               = 'wc-' . $product->get_id();
					$row['listing_has_variations'] = true;
					$dict = [];
					foreach ( $v->get_attributes() as $attr => $val ) {
						if ( '' === (string) $val ) {
							continue;
						}
						$dict[ wc_attribute_label( str_replace( 'attribute_', '', $attr ), $product ) ] = (string) $val;
					}
					if ( $dict ) {
						$row['variant_dict'] = $dict;
					}
					$rows[] = $row;
				}
			}
			if ( ! $rows ) {
				$row = self::base_row( $product, $opts, $countries, $stats );
				if ( $row ) {
					$rows[] = $row;
				}
			}
			return $rows;
		}
		$row = self::base_row( $product, $opts, $countries, $stats );
		return $row ? [ $row ] : [];
	}

	private static function base_row( WC_Product $p, array $opts, array $countries, array &$stats, ?WC_Product $parent = null ): ?array {
		$display = $parent ?: $p;

		$image = wp_get_attachment_image_url( $p->get_image_id() ?: $display->get_image_id(), 'full' );
		if ( ! $image ) {
			$stats['excluded_no_image']++;
			return null; // required by spec: exclude + count
		}
		$brand = self::resolve_brand( $display );
		if ( '' === $brand ) {
			$brand = trim( (string) $opts['brand_fallback'] ); // explicit opt-in only
			if ( '' === $brand ) {
				$stats['excluded_no_brand']++;
				return null; // required by spec: exclude + count, never fabricate
			}
		}
		$currency = get_woocommerce_currency();
		$regular  = $p->get_regular_price();
		$priceval = ( '' !== (string) $regular ) ? $regular : $p->get_price();
		if ( '' === (string) $priceval ) {
			return null;
		}

		$title = $display->get_name() . ( $parent ? ' - ' . wc_get_formatted_variation( $p, true, false, false ) : '' );
		$desc  = self::plain( $display->get_description() ?: $display->get_short_description() ?: $display->get_name() );

		$row = [
			'is_eligible_search'   => true,
			'is_eligible_checkout' => false,
			'item_id'              => 'wc-' . $p->get_id(),
			'title'                => self::clip( $title, 150 ),
			'description'          => self::clip( $desc, 5000 ),
			'url'                  => $display->get_permalink(),
			'brand'                => self::clip( $brand, 70 ),
			'image_url'            => $image,
			'price'                => wc_format_decimal( $priceval, 2 ) . ' ' . $currency,
			'availability'         => self::availability( $p ),
			'condition'            => 'new',
			'seller_name'          => self::clip( get_bloginfo( 'name' ), 70 ),
			'seller_url'           => home_url( '/' ),
			'return_policy'        => (string) $opts['return_policy_url'],
			'target_countries'     => $countries,
			'store_country'        => self::store_country(),
		];

		if ( $p->is_on_sale() && '' !== (string) $p->get_sale_price() ) {
			$sale = (float) $p->get_sale_price();
			if ( $sale > 0 && $sale <= (float) $priceval ) {
				$row['sale_price'] = wc_format_decimal( $sale, 2 ) . ' ' . $currency;
			}
		}
		$gallery = array_filter( array_map( fn( $id ) => wp_get_attachment_image_url( $id, 'full' ), $display->get_gallery_image_ids() ) );
		if ( $gallery ) {
			// spec: comma-separated String, not an array
			$row['additional_image_urls'] = implode( ',', array_slice( $gallery, 0, 10 ) );
		}
		if ( method_exists( $p, 'get_global_unique_id' ) ) {
			$gtin = preg_replace( '/\D/', '', (string) $p->get_global_unique_id() );
			if ( preg_match( '/^\d{8,14}$/', $gtin ) ) {
				$row['gtin'] = $gtin; // only if valid: 8-14 digits, no dashes/spaces
			}
		}
		$cats = wp_get_post_terms( $display->get_id(), 'product_cat', [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $cats ) && $cats ) {
			$row['product_category'] = implode( ' > ', $cats );
		}
		if ( '' !== (string) $p->get_weight() ) {
			// spec: numeric weight + separate item_weight_unit
			$row['weight']           = wc_format_decimal( $p->get_weight() );
			$row['item_weight_unit'] = self::weight_unit();
		}
		if ( $p->is_virtual() || $p->is_downloadable() ) {
			$row['is_digital'] = true;
		}
		if ( $display->get_review_count() > 0 ) {
			$row['review_count'] = (int) $display->get_review_count();
			$row['star_rating']  = number_format( (float) $display->get_average_rating(), 1, '.', '' ); // spec: String
		}
		return $row;
	}

	private static function weight_unit(): string {
		$u = get_option( 'woocommerce_weight_unit', 'kg' );
		return 'lbs' === $u ? 'lb' : $u; // Woo 'lbs' -> spec 'lb'
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
		return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max ) ) : $s;
	}

	// ── per-row schema validator (hard gate) ────────────────────────────────

	/** Returns a list of violations; empty array = conformant row. */
	public static function validate_row( array $row ): array {
		$e = [];
		foreach ( [ 'item_id', 'title', 'description', 'url', 'brand', 'image_url', 'price', 'availability', 'seller_name', 'seller_url', 'return_policy', 'store_country' ] as $f ) {
			if ( ! isset( $row[ $f ] ) || '' === (string) $row[ $f ] ) {
				$e[] = "missing required $f";
			}
		}
		if ( empty( $row['target_countries'] ) || ! is_array( $row['target_countries'] ) ) {
			$e[] = 'missing required target_countries';
		} else {
			foreach ( $row['target_countries'] as $c ) {
				if ( ! preg_match( '/^[A-Z]{2}$/', (string) $c ) ) {
					$e[] = 'bad country code ' . $c;
				}
			}
		}
		if ( ! isset( $row['is_eligible_search'], $row['is_eligible_checkout'] ) || ! is_bool( $row['is_eligible_search'] ) || ! is_bool( $row['is_eligible_checkout'] ) ) {
			$e[] = 'eligibility flags must be boolean';
		}
		foreach ( [ 'item_id' => 100, 'title' => 150, 'description' => 5000, 'brand' => 70, 'seller_name' => 70 ] as $f => $max ) {
			if ( isset( $row[ $f ] ) && mb_strlen( (string) $row[ $f ] ) > $max ) {
				$e[] = "$f exceeds $max chars";
			}
		}
		foreach ( [ 'url', 'image_url', 'seller_url', 'return_policy' ] as $f ) {
			if ( isset( $row[ $f ] ) && ( 0 !== strpos( (string) $row[ $f ], 'https://' ) || ! filter_var( $row[ $f ], FILTER_VALIDATE_URL ) ) ) {
				$e[] = "$f must be a valid https URL";
			}
		}
		if ( isset( $row['additional_image_urls'] ) ) {
			if ( ! is_string( $row['additional_image_urls'] ) ) {
				$e[] = 'additional_image_urls must be a comma-separated string';
			} else {
				foreach ( explode( ',', $row['additional_image_urls'] ) as $u ) {
					if ( 0 !== strpos( trim( $u ), 'https://' ) ) {
						$e[] = 'additional_image_urls contains a non-https URL';
						break;
					}
				}
			}
		}
		$price_re = '/^\d+(\.\d{1,2})? [A-Z]{3}$/';
		if ( isset( $row['price'] ) && ! preg_match( $price_re, (string) $row['price'] ) ) {
			$e[] = 'price format must be "N.NN CUR"';
		}
		if ( isset( $row['sale_price'] ) ) {
			if ( ! preg_match( $price_re, (string) $row['sale_price'] ) ) {
				$e[] = 'sale_price format must be "N.NN CUR"';
			} elseif ( (float) $row['sale_price'] > (float) $row['price'] ) {
				$e[] = 'sale_price greater than price';
			}
		}
		if ( isset( $row['availability'] ) && ! in_array( $row['availability'], [ 'in_stock', 'out_of_stock', 'pre_order', 'backorder', 'unknown' ], true ) ) {
			$e[] = 'availability not in enum';
		}
		if ( isset( $row['condition'] ) && ! in_array( $row['condition'], [ 'new', 'refurbished', 'used' ], true ) ) {
			$e[] = 'condition not in enum';
		}
		if ( isset( $row['gtin'] ) && ! preg_match( '/^\d{8,14}$/', (string) $row['gtin'] ) ) {
			$e[] = 'gtin must be 8-14 digits';
		}
		if ( isset( $row['store_country'] ) && ! preg_match( '/^[A-Z]{2}$/', (string) $row['store_country'] ) ) {
			$e[] = 'store_country must be ISO 3166-1 alpha-2';
		}
		if ( isset( $row['star_rating'] ) ) {
			if ( ! is_string( $row['star_rating'] ) || ! is_numeric( $row['star_rating'] ) || (float) $row['star_rating'] < 0 || (float) $row['star_rating'] > 5 ) {
				$e[] = 'star_rating must be a numeric string 0-5';
			}
		}
		if ( isset( $row['weight'] ) && empty( $row['item_weight_unit'] ) ) {
			$e[] = 'item_weight_unit required when weight is set';
		}
		if ( isset( $row['item_weight_unit'] ) && ! in_array( $row['item_weight_unit'], [ 'kg', 'g', 'lb', 'oz' ], true ) ) {
			$e[] = 'item_weight_unit not in enum';
		}
		if ( ! empty( $row['listing_has_variations'] ) && empty( $row['group_id'] ) ) {
			$e[] = 'group_id required for variation rows';
		}
		return $e;
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
		echo '<p>Generates an OpenAI-compatible product feed for ChatGPT product discovery (Agentic Commerce Protocol, discovery tier - checkout stays on your storefront). <strong>Application and approval required:</strong> apply at <a href="https://chatgpt.com/merchants" target="_blank" rel="noopener">chatgpt.com/merchants</a>; after approval, OpenAI provides the delivery channel for the generated file.</p>';
		if ( $stats ) {
			if ( ! empty( $stats['error'] ) ) {
				echo '<div class="notice notice-error"><p><strong>Last generation blocked (' . esc_html( $stats['error'] ) . '):</strong> ' . esc_html( implode( ' / ', $stats['config_errors'] ?? [ $stats['detail'] ?? '' ] ) ) . '. The previous valid feed, if any, was preserved.</p></div>';
			} else {
				echo '<p><strong>Last generation:</strong> ' . esc_html( $stats['generated_at'] ) . ' - ' . (int) $stats['rows'] . ' conformant rows / ' . (int) $stats['products'] . ' products';
				if ( $stats['excluded_no_image'] ) {
					echo ' - <span style="color:#b45309">' . (int) $stats['excluded_no_image'] . ' excluded: no image</span>';
				}
				if ( $stats['excluded_no_brand'] ) {
					echo ' - <span style="color:#b45309">' . (int) $stats['excluded_no_brand'] . ' excluded: no brand</span>';
				}
				if ( $stats['excluded_invalid'] ) {
					echo ' - <span style="color:#b91c1c">' . (int) $stats['excluded_invalid'] . ' excluded: schema violations</span>';
				}
				echo '</p>';
				if ( ! empty( $stats['invalid_examples'] ) ) {
					echo '<p style="color:#b91c1c"><small>' . esc_html( implode( ' | ', $stats['invalid_examples'] ) ) . '</small></p>';
				}
				echo '<p><a class="button" href="' . esc_url( self::feed_url() ) . '" download>Download generated feed</a> <a class="button" href="' . esc_url( self::feed_url() . '.gz' ) . '" download>Download .gz</a></p>';
			}
		}
		echo '<form method="post">';
		wp_nonce_field( 'kb_acp_save', 'kb_acp_nonce' );
		echo '<table class="form-table">';
		echo '<tr><th>Enable daily generation</th><td><input type="checkbox" name="enabled" ' . checked( $opts['enabled'], true, false ) . '></td></tr>';
		echo '<tr><th>Brand fallback (opt-in)</th><td><input type="text" class="regular-text" name="brand_fallback" value="' . esc_attr( $opts['brand_fallback'] ) . '"><p class="description">Only for own-label stores: used when a product has no brand taxonomy/attribute. Leave empty to exclude brandless products (they are counted above). Never fabricates a brand silently.</p></td></tr>';
		echo '<tr><th>Return policy URL</th><td><input type="url" class="regular-text" name="return_policy_url" value="' . esc_attr( $opts['return_policy_url'] ) . '"><p class="description">Required. Missing value blocks generation entirely. Defaults to your Refund and Returns page.</p></td></tr>';
		echo '<tr><th>Target countries</th><td><input type="text" class="regular-text" name="target_countries" value="' . esc_attr( $opts['target_countries'] ) . '"><p class="description">Comma-separated ISO 3166-1 alpha-2 codes. Defaults to your WooCommerce selling locations.</p></td></tr>';
		echo '</table>';
		submit_button( 'Save' );
		echo '<button class="button button-secondary" name="regenerate" value="1">Save &amp; regenerate now</button>';
		echo '</form></div>';
	}
}

