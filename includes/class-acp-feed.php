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
 * - SPEC-IS-CONTRACT (1.0.138, decided 2026-09-26): the current stable spec
 *   is the contract - the plugin is neither looser nor stricter. Required:
 *   item_id, title, description, url, brand, seller_name, image_url,
 *   availability, price (+ group_id, listing_has_variations, variant_dict on
 *   variant rows). Everything else is optional: emitted only when known and
 *   valid, validated when present, never a reason to block the whole feed.
 * - Row policy: product missing image or brand = excluded + counted (brand
 *   is Required; the merchant-declared brand_fallback is the only fallback,
 *   never fabricated); every emitted row = schema-conformant (per-row
 *   validator, hard gate). Generator and validator read the same SCHEMA.
 * - Atomic: build+validate a temp file, stream-gzip it, replace the last
 *   good snapshot ONLY if everything passes. A failure never destroys the
 *   previous valid feed. Transient lock against concurrent runs.
 * - item_id = wc-{product_id} / wc-{variation_id}: stable, unique, <=100
 *   chars by construction. No SKU-based ids, no dedup needed.
 * - Direct filesystem streams (fopen/fwrite/fread/fclose) are used
 *   DELIBERATELY: WP_Filesystem has no streaming API and would require
 *   loading the whole catalog in memory (rejected in external review);
 *   rename() is required because the atomic snapshot swap depends on
 *   same-filesystem rename semantics. unlink is wp_delete_file everywhere.
 * - Stable filename (acp-products.jsonl[.gz]) inside a tokenized directory:
 *   ready for SFTP upload, not guessable, not advertised in discovery/robots.
 */
class KaliCart_Bridge_ACP_Feed {

	const OPTION    = 'kalicart_bridge_acp_feed';
	const CRON_HOOK = 'kalicart_bridge_acp_feed_generate';
	const LOCK      = 'kalicart_bridge_acp_feed_lock';

	/**
	 * Single schema map (spec developers.openai.com/commerce/specs/file-upload/products,
	 * read 2026-09-26). The validator enforces exactly this; the generator emits
	 * optional fields only when known and valid under the same rules.
	 */
	const SCHEMA = [
		'required'         => [ 'item_id', 'title', 'description', 'url', 'brand', 'seller_name', 'image_url', 'availability', 'price' ],
		'variant_required' => [ 'group_id', 'listing_has_variations', 'variant_dict' ],
		'max_len'          => [ 'title' => 150, 'description' => 5000 ],
		'url_fields'       => [ 'url', 'image_url', 'seller_url', 'return_policy' ], // absolute http(s); https preferred
		'availability'     => [ 'in_stock', 'out_of_stock', 'pre_order', 'backorder', 'unknown' ],
		'condition'        => [ 'new', 'refurbished', 'used' ],
		'weight_unit'      => [ 'g', 'kg', 'oz', 'lb' ],
		'gtin_lengths'     => [ 8, 12, 13, 14 ],
	];

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'generate' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_products_list' ] );
		add_action( 'admin_post_kalicart_acp_export_exclusions', [ __CLASS__, 'export_exclusions_csv' ] );
	}

	// ── options ─────────────────────────────────────────────────────────────

	public static function get_options(): array {
		$defaults = [
			'enabled'           => false,
			'brand_fallback'    => '', // opt-in only: empty = products without brand are excluded
			'target_countries'  => implode( ',', self::default_target_countries() ),
			'token'             => '',
		];
		$opts = get_option( self::OPTION, [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts = array_merge( $defaults, $opts );
		// The main Settings tab is the single source of truth for return policy.
		$opts['return_policy_url'] = (string) get_option( 'kalicart_bridge_return_policy_url', '' );
		if ( '' === $opts['token'] ) {
			$opts['token'] = wp_generate_password( 20, false, false );
			$opts_to_store = $opts;
			unset( $opts_to_store['return_policy_url'] );
			update_option( self::OPTION, $opts_to_store, false );
		}
		return $opts;
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
		unset( $opts_stored['return_policy_url'] );
		$opts_stored['last_stats'] = $stats;
		update_option( self::OPTION, $opts_stored, false );
		return $stats;
	}

	private static function generate_inner(): array {
		$opts  = self::get_options();
		$stats = [
			'rows' => 0, 'products' => 0, 'excluded_no_image' => 0, 'excluded_no_brand' => 0, 'fallback_brand_rows' => 0, 'gtin_omitted' => 0, 'fallback_description_rows' => 0,
			'excluded_invalid' => 0, 'invalid_examples' => [], 'generated_at' => gmdate( 'c' ),
		];

		// Config gate (1.0.138): return policy, store country and target countries
		// are OPTIONAL in the spec ("requires market setup"): their absence never
		// blocks the feed. Only a value that is present AND malformed blocks, since
		// it would make every row non-conformant.
		$countries = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $opts['target_countries'] ) ) ) ) );
		$config_errors = [];
		foreach ( $countries as $c ) {
			if ( ! preg_match( '/^[A-Z]{2}$/', $c ) ) {
				$config_errors[] = 'invalid target country code: ' . $c;
			}
		}
		$rp = (string) $opts['return_policy_url'];
		if ( '' !== $rp && ! self::is_http_url( $rp ) ) {
			$config_errors[] = 'return policy URL is not an absolute http(s) URL';
		}
		if ( $config_errors ) {
			$stats['error'] = 'config_incomplete';
			$stats['config_errors'] = $config_errors;
			return $stats; // last good snapshot untouched
		}

		$dir  = self::feed_dir( $opts );
		$path = $dir . '/acp-products.jsonl';
		$tmp  = $path . '.tmp';
		$fh   = fopen( $tmp, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming export, see class header.
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
			// N+1 killer: post+meta+termini dell'intera pagina in 3 query;
			// le successive wc_get_product/wp_get_post_terms diventano cache-hit.
			_prime_post_caches( $q->posts, true, true );
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
					fwrite( $fh, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming export.
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
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming export.

		if ( 0 === $stats['rows'] ) {
			wp_delete_file( $tmp );
			$stats['error'] = 'empty_feed';
			return $stats; // never replace a good snapshot with an empty one
		}

		// streamed gzip from the validated temp file (no full-file memory load)
		$gz_tmp = $tmp . '.gz';
		$in     = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming gzip, no full-file memory load.
		$gz     = gzopen( $gz_tmp, 'w9' );
		if ( ! $in || ! $gz ) {
			wp_delete_file( $tmp );
			$stats['error'] = 'gzip_failed';
			return $stats;
		}
		while ( ! feof( $in ) ) {
			gzwrite( $gz, fread( $in, 512 * 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming gzip.
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming gzip.
		gzclose( $gz );

		// atomic swap: only now the last good snapshot is replaced
		rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic snapshot swap requires rename() semantics.
		rename( $gz_tmp, $path . '.gz' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic snapshot swap requires rename() semantics.
		return $stats;
	}

	// ── row building ────────────────────────────────────────────────────────

	private static function rows_for_product( WC_Product $product, array $opts, array $countries, array &$stats ): array {
		$rows = [];
		if ( $product->is_type( 'variable' ) ) {
			$kb_children = $product->get_children();
			if ( $kb_children ) {
				_prime_post_caches( $kb_children, false, false ); // post cache variazioni: 1 query
				update_postmeta_cache( $kb_children );            // meta variazioni: 1 query
			}
			foreach ( $kb_children as $vid ) {
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
				// SPEC-IS-CONTRACT (1.0.138): brand is Required, so a row without
				// it does not enter the file - excluded and counted, listed in
				// the panel. Supersedes the 2026-07-02 choice (row submitted
				// without brand). Never fabricated.
				$stats['excluded_no_brand']++;
				return null;
			} else {
				$stats['fallback_brand_rows']++;
			}
		}
		$currency = get_woocommerce_currency();
		$regular  = $p->get_regular_price();
		$priceval = ( '' !== (string) $regular ) ? $regular : $p->get_price();
		if ( '' === (string) $priceval ) {
			return null;
		}

		$title = $display->get_name() . ( $parent ? ' - ' . wc_get_formatted_variation( $p, true, false, false ) : '' );
		// Description is soft-required: it must never block a row alone - the product
		// name is the last-resort fallback. plain() runs on EACH candidate BEFORE the
		// ?: so empty markup (<p></p>, &nbsp;, empty block) falls through to the name
		// instead of winning the coalesce and collapsing to '' at the validator. Name
		// used as description is degraded-but-true, never fabricated; the panel flags it.
		$desc = self::plain( $display->get_description() );
		if ( '' === $desc ) { $desc = self::plain( $display->get_short_description() ); }
		if ( '' === $desc ) {
			$desc = self::plain( $display->get_name() );
			// Name used as description (product has no real description): degraded-but-true,
			// never fabricated. Count the row so the ChatGPT-feed readiness panel can report
			// how many feed rows carry the name in place of a real description.
			if ( '' !== $desc ) {
				$stats['fallback_description_rows']++;
			}
		}

		$row = [
			'is_eligible_search'   => true,
			'is_eligible_checkout' => false,
			'item_id'              => 'wc-' . $p->get_id(),
			'title'                => self::clip( $title, 150 ),
			'description'          => self::clip( $desc, 5000 ),
			'url'                  => $display->get_permalink(),
			'image_url'            => $image,
			'price'                => wc_format_decimal( $priceval, 2 ) . ' ' . $currency,
			'availability'         => self::availability( $p ),
			'condition'            => 'new',
			'seller_name'          => (string) get_bloginfo( 'name' ),
			'brand'                => $brand,
		];
		// Optional in the spec: emitted only when known and valid.
		$seller_url = home_url( '/' );
		if ( self::is_http_url( $seller_url ) ) {
			$row['seller_url'] = $seller_url;
		}
		if ( '' !== (string) $opts['return_policy_url'] ) {
			$row['return_policy'] = (string) $opts['return_policy_url'];
		}
		if ( $countries ) {
			$row['target_countries'] = $countries;
		}
		if ( '' !== self::store_country() ) {
			$row['store_country'] = self::store_country();
		}
		if ( $p->is_on_sale() && '' !== (string) $p->get_sale_price() ) {
			$sale = (float) $p->get_sale_price();
			if ( $sale > 0 && $sale < (float) $priceval ) { // spec: strictly less than price
				$row['sale_price'] = wc_format_decimal( $sale, 2 ) . ' ' . $currency;
			}
		}
		$gallery = array_filter( array_map( fn( $id ) => wp_get_attachment_image_url( $id, 'full' ), $display->get_gallery_image_ids() ) );
		if ( $gallery ) {
			// spec: in JSONL additional_image_urls is an ARRAY of URLs
			$row['additional_image_urls'] = array_values( array_slice( $gallery, 0, 10 ) );
		}
		if ( method_exists( $p, 'get_global_unique_id' ) ) {
			$raw_gtin = (string) $p->get_global_unique_id();
			$gtin     = preg_replace( '/[\s-]/', '', $raw_gtin );
			if ( self::is_valid_gtin( $gtin ) ) {
				$row['gtin'] = $gtin; // spec: 8/12/13/14 digits with a valid check digit
			} elseif ( '' !== trim( $raw_gtin ) ) {
				$stats['gtin_omitted']++; // optional field: omitted, the row stays
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
			$row['star_rating']  = number_format( (float) $display->get_average_rating(), 2, '.', '' ); // spec: decimal String, two decimals
		}
		return $row;
	}

	private static function weight_unit(): string {
		$u = get_option( 'woocommerce_weight_unit', 'kg' );
		return 'lbs' === $u ? 'lb' : $u; // Woo 'lbs' -> spec 'lb'
	}

	private static function resolve_brand( WC_Product $p ): string {
		// single source of truth: the engine's merchant-declared brand resolver
		return (string) KaliCart_Bridge_Catalog_Engine::resolve_brand( $p );
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
		// Decode entities first so &nbsp; -> U+00A0 and entity-encoded tags are stripped;
		// then collapse Unicode spaces and zero-width chars so visually-empty markup -> ''.
		$text = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[\s\p{Z}\p{Cf}]+/u', ' ', $text );
		return trim( $text );
	}

	private static function clip( string $s, int $max ): string {
		return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max ) ) : $s;
	}

	/** Absolute http(s) URL (spec: "absolute HTTP or HTTPS URLs; prefer HTTPS"). */
	public static function is_http_url( $url ): bool {
		$url = (string) $url;
		return ( 0 === strpos( $url, 'https://' ) || 0 === strpos( $url, 'http://' ) ) && (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	/** GS1 GTIN: 8, 12, 13 or 14 digits and a valid mod-10 check digit. */
	public static function is_valid_gtin( $gtin ): bool {
		$gtin = (string) $gtin;
		if ( ! preg_match( '/^\d+$/', $gtin ) || ! in_array( strlen( $gtin ), self::SCHEMA['gtin_lengths'], true ) ) {
			return false;
		}
		$digits = array_map( 'intval', str_split( $gtin ) );
		$check  = array_pop( $digits );
		$sum    = 0;
		foreach ( array_reverse( $digits ) as $i => $d ) {
			$sum += $d * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - ( $sum % 10 ) ) % 10 === $check;
	}

	// ── per-row schema validator (hard gate) ────────────────────────────────

	/** Returns a list of violations; empty array = conformant row. */
	public static function validate_row( array $row ): array {
		$e = [];
		foreach ( self::SCHEMA['required'] as $f ) {
			if ( ! isset( $row[ $f ] ) || '' === (string) $row[ $f ] ) {
				$e[] = "missing required $f";
			}
		}
		if ( isset( $row['target_countries'] ) ) {
			if ( ! is_array( $row['target_countries'] ) || ! $row['target_countries'] ) {
				$e[] = 'target_countries must be a non-empty array when present';
			} else {
				foreach ( $row['target_countries'] as $c ) {
					if ( ! preg_match( '/^[A-Z]{2}$/', (string) $c ) ) {
						$e[] = 'bad country code ' . $c;
					}
				}
			}
		}
		foreach ( [ 'is_eligible_search', 'is_eligible_checkout' ] as $f ) {
			if ( isset( $row[ $f ] ) && ! is_bool( $row[ $f ] ) ) {
				$e[] = "$f must be boolean";
			}
		}
		foreach ( self::SCHEMA['max_len'] as $f => $max ) {
			if ( isset( $row[ $f ] ) && mb_strlen( (string) $row[ $f ] ) > $max ) {
				$e[] = "$f exceeds $max chars";
			}
		}
		foreach ( self::SCHEMA['url_fields'] as $f ) {
			if ( isset( $row[ $f ] ) && ! self::is_http_url( $row[ $f ] ) ) {
				$e[] = "$f must be an absolute http(s) URL";
			}
		}
		if ( isset( $row['additional_image_urls'] ) ) {
			if ( ! is_array( $row['additional_image_urls'] ) ) {
				$e[] = 'additional_image_urls must be an array in JSONL';
			} else {
				foreach ( $row['additional_image_urls'] as $u ) {
					if ( ! self::is_http_url( $u ) ) {
						$e[] = 'additional_image_urls contains an invalid URL';
						break;
					}
				}
			}
		}
		$price_re = '/^\d+(\.\d{1,2})? [A-Z]{3}$/';
		if ( isset( $row['price'] ) ) {
			if ( ! preg_match( $price_re, (string) $row['price'] ) ) {
				$e[] = 'price format must be "N.NN CUR"';
			} elseif ( (float) $row['price'] <= 0 ) {
				$e[] = 'price must be positive';
			}
		}
		if ( isset( $row['sale_price'] ) ) {
			if ( ! preg_match( $price_re, (string) $row['sale_price'] ) ) {
				$e[] = 'sale_price format must be "N.NN CUR"';
			} elseif ( (float) $row['sale_price'] <= 0 || (float) $row['sale_price'] >= (float) ( $row['price'] ?? 0 ) ) {
				$e[] = 'sale_price must be greater than zero and strictly less than price';
			} elseif ( substr( (string) $row['sale_price'], -3 ) !== substr( (string) ( $row['price'] ?? '' ), -3 ) ) {
				$e[] = 'sale_price currency differs from price';
			}
		}
		if ( isset( $row['availability'] ) && ! in_array( $row['availability'], self::SCHEMA['availability'], true ) ) {
			$e[] = 'availability not in enum';
		}
		if ( isset( $row['condition'] ) && ! in_array( $row['condition'], self::SCHEMA['condition'], true ) ) {
			$e[] = 'condition not in enum';
		}
		if ( isset( $row['gtin'] ) && ! self::is_valid_gtin( $row['gtin'] ) ) {
			$e[] = 'gtin must be 8, 12, 13 or 14 digits with a valid check digit';
		}
		if ( isset( $row['store_country'] ) && ! preg_match( '/^[A-Z]{2}$/', (string) $row['store_country'] ) ) {
			$e[] = 'store_country must be ISO 3166-1 alpha-2';
		}
		if ( isset( $row['star_rating'] ) ) {
			if ( ! is_string( $row['star_rating'] ) || ! is_numeric( $row['star_rating'] ) || (float) $row['star_rating'] < 0 || (float) $row['star_rating'] > 5 ) {
				$e[] = 'star_rating must be a numeric string 0-5';
			}
			if ( empty( $row['review_count'] ) ) {
				$e[] = 'star_rating requires a positive review_count';
			}
		}
		if ( isset( $row['review_count'] ) && ( ! is_int( $row['review_count'] ) || $row['review_count'] < 0 ) ) {
			$e[] = 'review_count must be a nonnegative integer';
		}
		if ( isset( $row['weight'] ) ) {
			if ( ! is_numeric( $row['weight'] ) || (float) $row['weight'] <= 0 ) {
				$e[] = 'weight must be a positive decimal';
			}
			if ( empty( $row['item_weight_unit'] ) ) {
				$e[] = 'item_weight_unit required when weight is set';
			}
		}
		if ( isset( $row['item_weight_unit'] ) && ! in_array( $row['item_weight_unit'], self::SCHEMA['weight_unit'], true ) ) {
			$e[] = 'item_weight_unit not in enum';
		}
		$is_variant = ! empty( $row['listing_has_variations'] ) || isset( $row['group_id'] ) || isset( $row['variant_dict'] );
		if ( $is_variant ) {
			foreach ( self::SCHEMA['variant_required'] as $f ) {
				if ( empty( $row[ $f ] ) ) {
					$e[] = "$f required for variant rows";
				}
			}
			if ( isset( $row['group_id'], $row['item_id'] ) && (string) $row['group_id'] === (string) $row['item_id'] ) {
				$e[] = 'group_id must differ from item_id';
			}
			if ( isset( $row['variant_dict'] ) && ! is_array( $row['variant_dict'] ) ) {
				$e[] = 'variant_dict must be an object';
			}
		}
		return $e;
	}

	// ── admin ───────────────────────────────────────────────────────────────

	private static function readiness_label( ?bool $state, string $ready = '', string $action = '' ): string {
		$ready  = '' !== $ready ? $ready : __( 'Ready', 'kalicart-bridge' );
		$action = '' !== $action ? $action : __( 'Action needed', 'kalicart-bridge' );
		if ( null === $state ) {
			return '<span class="kali-pill kali-pill--muted">' . esc_html__( 'Not checked', 'kalicart-bridge' ) . '</span>';
		}
		return $state
			? '<span class="kali-pill kali-pill--ok">' . esc_html( $ready ) . '</span>'
			: '<span class="kali-pill kali-pill--warn">' . esc_html( $action ) . '</span>';
	}

	private static function readiness_row( string $label, string $status, string $detail ): void {
		echo '<div class="kali-acp-row"><div class="kali-acp-row__info"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $detail ) . '</span></div><div class="kali-acp-row__status">' . wp_kses_post( $status ) . '</div></div>';
	}

	/**
	 * Query fragments for feed-blocking data gaps, evaluated LIVE on current
	 * data (never a stored snapshot): scales to any catalog size and is
	 * always fresh. Semantics mirror the generator exactly: 'brand' = no
	 * term in any brand taxonomy resolve_brand() reads; 'image' = no
	 * featured image on the (parent) product.
	 */
	private static function missing_data_query_args( string $what ): array {
		if ( 'image' === $what ) {
			return [ 'meta_query' => [ [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ] ] ];
		}
		$tax_query = [ 'relation' => 'AND' ];
		foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand', 'pa_marca' ] as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$tax_query[] = [ 'taxonomy' => $tax, 'operator' => 'NOT EXISTS' ];
			}
		}
		return [ 'tax_query' => $tax_query ];
	}

	/** Native Products list, pre-filtered: bulk edit, search and sorting for free. */
	public static function filter_products_list( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$what = sanitize_key( wp_unslash( $_GET['kalicart_missing'] ?? '' ) );
		// Quality-signal reasons: served from the cached health report (post__in),
		// so the button count in the Quality Signals tab and this list ALWAYS
		// match by construction - one number, one source.
		$report_map = [
			'title'       => 'TITLE_TOO_SHORT',
			'description' => 'NO_DESCRIPTION',
			'category'    => 'NO_CATEGORY',
			'price'       => 'ZERO_PRICE',
			'sku'         => 'NO_SKU',
		];
		if ( isset( $report_map[ $what ] ) ) {
			$ids = KaliCart_Bridge_Quarantine::get_report()['issue_product_ids'][ $report_map[ $what ] ] ?? [];
			$query->set( 'post__in', $ids ? array_map( 'intval', $ids ) : [ 0 ] );
			$query->set( 'orderby', 'post__in' ); // recenti prima, come in tab
			return;
		}
		if ( ! in_array( $what, [ 'brand', 'image' ], true ) ) {
			return;
		}
		foreach ( self::missing_data_query_args( $what ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	private static function products_list_url( string $what ): string {
		return admin_url( 'edit.php?post_type=product&kalicart_missing=' . $what );
	}

	/** Full CSV export of feed-blocking gaps - the agency-scale workflow. */
	public static function export_exclusions_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'kb_acp_export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'kalicart-bridge' ) );
		}
		$what = sanitize_key( wp_unslash( $_GET['what'] ?? 'brand' ) );
		$what = in_array( $what, [ 'brand', 'image' ], true ) ? $what : 'brand';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=kalicart-missing-' . $what . '.csv' );
		echo "product_id,sku,name,edit_url\n";
		$paged = 1;
		do {
			$q = new WP_Query( array_merge( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'paged'          => $paged,
				'fields'         => 'ids',
			], self::missing_data_query_args( $what ) ) );
			foreach ( $q->posts as $pid ) {
				$product = wc_get_product( $pid );
				$name    = str_replace( '"', '""', $product ? $product->get_name() : '' );
				$sku     = str_replace( '"', '""', $product ? (string) $product->get_sku() : '' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/csv stream, not HTML: values are CSV-escaped (doubled quotes); esc_html would corrupt the data (e.g. & -> &amp;).
				echo (int) $pid . ',"' . $sku . '","' . $name . '","' . esc_url_raw( (string) get_edit_post_link( (int) $pid, 'raw' ) ) . '"' . "\n";
			}
			$more = $paged < (int) $q->max_num_pages;
			$paged++;
		} while ( $more );
		exit;
	}

	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kalicart-bridge' ) );
		}

		if ( isset( $_POST['kb_acp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kb_acp_nonce'] ) ), 'kb_acp_save' ) ) {
			$opts = self::get_options();
			$opts['enabled']           = ! empty( $_POST['enabled'] );
			$opts['brand_fallback']    = sanitize_text_field( wp_unslash( $_POST['brand_fallback'] ?? '' ) );
			$target_countries_raw      = sanitize_text_field( wp_unslash( $_POST['target_countries'] ?? '' ) );
			$opts['target_countries']  = strtoupper( preg_replace( '/[^A-Za-z,\s]/', '', $target_countries_raw ) );
			unset( $opts['last_stats'] ); // Configuration changed: never present stale readiness as current.
			unset( $opts['return_policy_url'] ); // Managed only by the main Settings tab.
			update_option( self::OPTION, $opts, false );
			self::maybe_schedule();
			// Single-action form: a valid POST always means save AND generate.
			// Never gate on the submit button's own name/value: it is absent
			// when the form is submitted via Enter, and when the loader
			// disables the button before serialization.
			self::generate();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'ChatGPT feed settings saved.', 'kalicart-bridge' ) . '</p></div>';
		}

		$opts      = self::get_options();
		$stats     = isset( $opts['last_stats'] ) && is_array( $opts['last_stats'] ) ? $opts['last_stats'] : null;
		$generated = $stats && empty( $stats['error'] );
		$countries = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $opts['target_countries'] ) ) ) ) );
		$countries_ready = $countries ? true : null; // optional: absent = not configured, never an error
		foreach ( $countries as $country ) {
			if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$countries_ready = false;
				break;
			}
		}
		$return_ready = '' === (string) $opts['return_policy_url'] ? null : self::is_http_url( $opts['return_policy_url'] );
		$image_state  = $stats && empty( $stats['error'] ) ? 0 === (int) ( $stats['excluded_no_image'] ?? 0 ) : null;
		$schema_state = $stats && empty( $stats['error'] ) ? 0 === (int) ( $stats['excluded_invalid'] ?? 0 ) : null;
		$brand_status = self::readiness_label( null );
		$brand_detail = __( 'Run feed generation to check declared brands.', 'kalicart-bridge' );
		// 1.0.138: stats written by 1.0.137 carry no 'excluded_no_brand': shown as not checked until the next run.
		if ( $stats && empty( $stats['error'] ) && array_key_exists( 'excluded_no_brand', $stats ) ) {
			$brand_missing  = (int) $stats['excluded_no_brand'];
			$brand_fallback = (int) ( $stats['fallback_brand_rows'] ?? 0 );
			if ( $brand_missing > 0 ) {
				$brand_status = '<span class="kali-pill kali-pill--warn">' . esc_html__( 'Excluded: brand missing', 'kalicart-bridge' ) . '</span>';
				/* translators: %d: feed rows excluded because the brand is missing */
				$brand_detail = sprintf( __( '%d feed rows excluded in the last run: brand is required by the OpenAI specification. Assign a brand to include them.', 'kalicart-bridge' ), $brand_missing );
			} elseif ( $brand_fallback > 0 ) {
				$brand_status = '<span class="kali-pill kali-pill--fallback">' . esc_html__( 'Fallback applied', 'kalicart-bridge' ) . '</span>';
				/* translators: %d: rows filled by the merchant brand fallback */
				$brand_detail = sprintf( __( '%d rows filled by the merchant-declared brand fallback.', 'kalicart-bridge' ), $brand_fallback );
			} else {
				$brand_status = self::readiness_label( true, __( 'Complete', 'kalicart-bridge' ) );
				$brand_detail = __( 'Every feed row carries a merchant-declared brand.', 'kalicart-bridge' );
			}
		}

		$desc_status = self::readiness_label( null );
		$desc_detail = __( 'Run feed generation to check descriptions.', 'kalicart-bridge' );
		if ( $stats && empty( $stats['error'] ) && array_key_exists( 'fallback_description_rows', $stats ) ) {
			$desc_fallback = (int) $stats['fallback_description_rows'];
			if ( $desc_fallback > 0 ) {
				$desc_status = '<span class="kali-pill kali-pill--warn">' . esc_html__( 'Name used as description', 'kalicart-bridge' ) . '</span>';
				$desc_detail = sprintf(
					/* translators: %d: feed rows that use the product name as description */
					_n(
						'%d feed row has no product description and uses the product name instead. Add a product description to provide complete product information.',
						'%d feed rows have no product description and use the product name instead. Add a product description to provide complete product information.',
						$desc_fallback,
						'kalicart-bridge'
					),
					$desc_fallback
				);
			} else {
				$desc_status = self::readiness_label( true, __( 'Complete', 'kalicart-bridge' ) );
				$desc_detail = __( 'Every feed row uses a product description from WooCommerce.', 'kalicart-bridge' );
			}
		}
		echo '<div class="kali-acp-card">';
		echo '<h2>' . esc_html__( 'ChatGPT Product Feed (OpenAI)', 'kalicart-bridge' ) . '</h2>';
		echo '<p>' . esc_html__( 'This optional export follows OpenAI’s direct product feed specification. Application and approval are required; after approval, OpenAI provides the delivery channel. Checkout stays on your storefront.', 'kalicart-bridge' ) . '</p>';
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'ChatGPT feed only:', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'Every status and setting in this section refers only to the optional file delivered to OpenAI. It does not enable, disable or limit KaliCart search, REST API, MCP or the federated catalog.', 'kalicart-bridge' ) . '</p></div>';

		if ( $stats && ! empty( $stats['error'] ) ) {
			$details = $stats['config_errors'] ?? [ $stats['detail'] ?? '' ];
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Last generation blocked:', 'kalicart-bridge' ) . '</strong> ' . esc_html( implode( ' / ', array_filter( $details ) ) ) . '. ' . esc_html__( 'The previous valid feed, if any, was preserved.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( null === $stats ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Feed readiness has not been checked with the current settings. Save and generate a snapshot to run the validator.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['excluded_no_brand'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Products without brand excluded.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'Brand is required by OpenAI’s direct product feed specification, so these rows are not in the file. Assign a brand (WooCommerce Brands taxonomy or a brand attribute) to include them. The products remain fully available through KaliCart’s agent-readable catalog, search, REST API and MCP surfaces.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['fallback_brand_rows'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Merchant brand fallback applied.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'These rows do not contain a product-level brand in WooCommerce. The merchant is responsible for declaring that the fallback is accurate for every affected product.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['fallback_description_rows'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Products using the name as description.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'These feed rows have no product description in WooCommerce and use the product name in its place. Add a product description to provide complete product information. The products remain fully available through KaliCart’s agent-readable catalog, search, REST API and MCP surfaces.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['excluded_no_image'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Missing primary product image.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'A primary product image is required by OpenAI’s direct product feed specification. Affected feed rows remain available in the agent-readable catalog but are excluded from the ChatGPT product feed.', 'kalicart-bridge' ) . '</p></div>';
		}

		echo '<div class="kali-acp-list">';
		self::readiness_row( __( 'Return policy', 'kalicart-bridge' ), null === $return_ready ? '<span class="kali-pill kali-pill--muted">' . esc_html__( 'Optional', 'kalicart-bridge' ) . '</span>' : self::readiness_label( $return_ready ), $return_ready ? __( 'Configured in the Settings tab: ', 'kalicart-bridge' ) . (string) $opts['return_policy_url'] : ( null === $return_ready ? __( 'Optional in the OpenAI specification. Configure it in the Settings tab to include it in every row.', 'kalicart-bridge' ) : __( 'The configured URL is not an absolute http(s) URL; feed generation is blocked until it is fixed.', 'kalicart-bridge' ) ) );
		self::readiness_row( __( 'Target countries', 'kalicart-bridge' ), null === $countries_ready ? '<span class="kali-pill kali-pill--muted">' . esc_html__( 'Optional', 'kalicart-bridge' ) . '</span>' : self::readiness_label( $countries_ready ), $countries ? implode( ', ', $countries ) : __( 'Optional in the OpenAI specification (depends on market setup). Use ISO 3166-1 alpha-2 country codes.', 'kalicart-bridge' ) );
		self::readiness_row( __( 'Product brand', 'kalicart-bridge' ), $brand_status, $brand_detail );
		self::readiness_row( __( 'Product description', 'kalicart-bridge' ), $desc_status, $desc_detail );
		self::readiness_row( __( 'Primary image', 'kalicart-bridge' ), self::readiness_label( $image_state, __( 'Complete', 'kalicart-bridge' ), __( 'Missing rows', 'kalicart-bridge' ) ), null === $image_state ? __( 'Run feed generation to check.', 'kalicart-bridge' ) : sprintf( /* translators: %d: rows excluded for missing image */ __( '%d feed rows excluded in the last run.', 'kalicart-bridge' ), (int) ( $stats['excluded_no_image'] ?? 0 ) ) );
		self::readiness_row( __( 'Schema validation', 'kalicart-bridge' ), self::readiness_label( $schema_state, __( 'Passed', 'kalicart-bridge' ), __( 'Invalid rows', 'kalicart-bridge' ) ), null === $schema_state ? __( 'Run feed generation to check.', 'kalicart-bridge' ) : sprintf( /* translators: %d: invalid rows */ __( '%d invalid rows in the last run.', 'kalicart-bridge' ), (int) ( $stats['excluded_invalid'] ?? 0 ) ) );
		self::readiness_row( __( 'Daily ChatGPT feed refresh', 'kalicart-bridge' ), self::readiness_label( (bool) $opts['enabled'], __( 'Enabled', 'kalicart-bridge' ), __( 'Manual only', 'kalicart-bridge' ) ), $opts['enabled'] ? __( 'Regenerates the validated ChatGPT feed file once per day; delivery to OpenAI is a separate step.', 'kalicart-bridge' ) : __( 'The ChatGPT feed changes only when generated manually and may become outdated after catalog changes.', 'kalicart-bridge' ) );
		self::readiness_row( __( 'OpenAI feed delivery', 'kalicart-bridge' ), self::readiness_label( false, __( 'Connected', 'kalicart-bridge' ), __( 'Application required', 'kalicart-bridge' ) ), __( 'This plugin currently prepares the file. OpenAI approves the merchant and assigns SFTP or API delivery credentials.', 'kalicart-bridge' ) );
		echo '</div>';

		if ( $generated ) {
			$excluded_invalid = (int) ( $stats['excluded_invalid'] ?? 0 );
            echo '<p><strong>' . esc_html__( 'Last validated ChatGPT feed snapshot:', 'kalicart-bridge' ) . '</strong> ' . esc_html( (string) ( $stats['generated_at'] ?? '' ) ) . ' &mdash; ' . (int) ( $stats['rows'] ?? 0 ) . ' ' . esc_html__( 'conformant rows from', 'kalicart-bridge' ) . ' ' . (int) ( $stats['products'] ?? 0 ) . ' ' . esc_html__( 'products.', 'kalicart-bridge' ) . ' ' . esc_html(
                sprintf(
                    /* translators: %d: rows excluded by the validator */
                    _n( '%d row excluded by the validator.', '%d rows excluded by the validator.', $excluded_invalid, 'kalicart-bridge' ),
                    $excluded_invalid
                )
            ) . '</p>';
			if ( ! empty( $stats['invalid_examples'] ) ) {
				echo '<p style="color:#b32d2e"><small>' . esc_html( implode( ' | ', $stats['invalid_examples'] ) ) . '</small></p>';
			}
			echo '<p><a class="kali-btn kali-btn--secondary" href="' . esc_url( self::feed_url() ) . '" download>' . esc_html__( 'Download JSONL', 'kalicart-bridge' ) . '</a> <a class="kali-btn kali-btn--secondary" href="' . esc_url( self::feed_url() . '.gz' ) . '" download>' . esc_html__( 'Download JSONL.GZ', 'kalicart-bridge' ) . '</a></p>';
		}
		echo '</div>';

		$live_counts = [];
		foreach ( [ 'brand', 'image' ] as $kb_gap ) {
			$kb_q = new WP_Query( array_merge(
				[ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ],
				self::missing_data_query_args( $kb_gap )
			) );
			$live_counts[ $kb_gap ] = (int) $kb_q->found_posts;
		}
		if ( $live_counts['brand'] || $live_counts['image'] ) {
			echo '<div class="kali-acp-card"><h2>' . esc_html__( 'ChatGPT feed data gaps', 'kalicart-bridge' ) . '</h2>';
			echo '<p>' . esc_html__( 'Live counts on your current catalog. Products without a primary image are excluded from the ChatGPT feed; products without a brand are excluded too, because brand is required. None of this affects the agent-readable catalog, search, REST API or MCP surfaces.', 'kalicart-bridge' ) . '</p>';
			echo '<div class="kali-acp-list">';
			$kb_rows = [
				'brand' => [ __( 'Missing brand (excluded)', 'kalicart-bridge' ), __( 'Brand is required by the OpenAI specification: these products are excluded from the ChatGPT feed. Assign a brand (WooCommerce Brands taxonomy or a brand attribute) to include them.', 'kalicart-bridge' ) ],
				'image' => [ __( 'Missing primary image', 'kalicart-bridge' ), __( 'Set a featured image in the product editor.', 'kalicart-bridge' ) ],
			];
			foreach ( $kb_rows as $kb_gap => $kb_row ) {
				if ( ! $live_counts[ $kb_gap ] ) {
					continue;
				}
				$kb_csv = wp_nonce_url( admin_url( 'admin-post.php?action=kalicart_acp_export_exclusions&what=' . $kb_gap ), 'kb_acp_export' );
				echo '<div class="kali-acp-row">';
				echo '<div class="kali-acp-row__info"><strong>' . esc_html( $kb_row[0] ) . ' <span class="kali-pill kali-pill--warn">' . (int) $live_counts[ $kb_gap ] . '</span></strong><span>' . esc_html( $kb_row[1] ) . '</span></div>';
				echo '<div class="kali-acp-row__actions"><a class="kali-btn kali-btn--secondary" href="' . esc_url( self::products_list_url( $kb_gap ) ) . '">' . esc_html__( 'Open in Products list', 'kalicart-bridge' ) . '</a> <a class="kali-btn kali-btn--secondary" href="' . esc_url( $kb_csv ) . '">' . esc_html__( 'Export CSV', 'kalicart-bridge' ) . '</a></div>';
				echo '</div>';
			}
			echo '</div></div>';
		}

		echo '<div class="kali-acp-card"><h2>' . esc_html__( 'ChatGPT feed generation settings', 'kalicart-bridge' ) . '</h2>';
		echo '<form method="post" id="kb-acp-form" action="' . esc_url( admin_url( 'admin.php?page=kalicart-bridge&tab=agent-commerce' ) ) . '">';
		wp_nonce_field( 'kb_acp_save', 'kb_acp_nonce' );
		echo '<div class="kali-toggle-group" style="margin-bottom:14px"><label class="kali-toggle-row"><div class="kali-toggle-info"><strong>' . esc_html__( 'Generate the ChatGPT feed daily', 'kalicart-bridge' ) . '</strong><span>' . esc_html__( 'Refreshes the local validated file only. Automatic delivery can be configured after OpenAI approves the merchant and supplies credentials.', 'kalicart-bridge' ) . '</span></div><div class="kali-toggle"><input type="checkbox" name="enabled" ' . checked( $opts['enabled'], true, false ) . '><span class="kali-toggle__slider"></span></div></label></div>';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Brand fallback (optional)', 'kalicart-bridge' ) . '</th><td><input type="text" class="regular-text" name="brand_fallback" value="' . esc_attr( $opts['brand_fallback'] ) . '" placeholder="' . esc_attr__( 'Your merchant-owned brand', 'kalicart-bridge' ) . '"><p class="description">' . esc_html__( 'Leave empty unless every otherwise brandless product is genuinely sold under this merchant-owned brand. By entering a value, the merchant declares it accurate and accepts responsibility for applying it to every missing-brand feed row. Multi-brand retailers should leave this empty.', 'kalicart-bridge' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Target countries', 'kalicart-bridge' ) . '</th><td><input type="text" class="regular-text" name="target_countries" value="' . esc_attr( $opts['target_countries'] ) . '"><p class="description">' . esc_html__( 'Comma-separated ISO 3166-1 alpha-2 codes. Defaults to WooCommerce selling locations.', 'kalicart-bridge' ) . '</p></td></tr>';
		echo '</table>';
		echo '<p style="margin-top:16px"><button type="submit" class="kali-btn kali-btn--primary" name="regenerate" value="1">' . esc_html__( 'Save and generate/validate now', 'kalicart-bridge' ) . '</button><span class="spinner" style="float:none;margin:0 0 0 10px"></span></p>';
		echo '</form></div>';

		echo '<div class="kali-acp-card"><h2>' . esc_html__( 'What to do with this file (OpenAI guidelines)', 'kalicart-bridge' ) . '</h2>';
		echo '<p>' . esc_html__( 'The file is a complete snapshot of your feed-eligible products, regenerated in full every time: products removed from your catalog disappear from the next snapshot automatically. OpenAI recommends refreshing it at least daily and always keeping the same filename.', 'kalicart-bridge' ) . '</p>';
		echo '<ol>';
		echo '<li><strong>' . esc_html__( 'Generate and validate here.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'Every row is checked against the OpenAI Product Feed specification before it enters the file.', 'kalicart-bridge' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Apply for direct-feed access.', 'kalicart-bridge' ) . '</strong> ' . sprintf(
			/* translators: %s: link to chatgpt.com/merchants */
			esc_html__( 'Submit your store at %s with your business contact. Approval is decided entirely by OpenAI; being on the waitlist costs nothing.', 'kalicart-bridge' ),
			'<a href="https://chatgpt.com/merchants" target="_blank" rel="noopener">chatgpt.com/merchants</a>'
		) . '</li>';
		echo '<li><strong>' . esc_html__( 'Deliver after approval.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'OpenAI assigns your delivery channel and credentials (SFTP push or API). Upload the JSONL.GZ file there on every refresh. OpenAI does not fetch any URL from your site: the download buttons above are for you, not for them.', 'kalicart-bridge' ) . '</li>';
		echo '</ol>';
		echo '<p>' . esc_html__( 'The feed format is region-neutral. Merchant eligibility and shopping-surface availability are determined by OpenAI and may vary by market.', 'kalicart-bridge' ) . ' ' . sprintf(
			/* translators: %s: link to OpenAI commerce documentation */
			esc_html__( 'Official documentation: %s.', 'kalicart-bridge' ),
			'<a href="https://developers.openai.com/commerce" target="_blank" rel="noopener">developers.openai.com/commerce</a>'
		) . '</p>';
		echo '</div>';
	}
}
