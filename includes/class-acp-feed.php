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

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'generate' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
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
			'rows' => 0, 'products' => 0, 'excluded_no_image' => 0, 'excluded_no_brand' => 0, 'fallback_brand_rows' => 0,
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
			$stats['fallback_brand_rows']++;
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

	private static function readiness_label( ?bool $state, string $ready = 'Ready', string $action = 'Action needed' ): string {
		if ( null === $state ) {
			return '<span style="color:#646970">Not checked</span>';
		}
		return $state
			? '<strong style="color:#008a20">' . esc_html( $ready ) . '</strong>'
			: '<strong style="color:#b32d2e">' . esc_html( $action ) . '</strong>';
	}

	private static function readiness_row( string $label, string $status, string $detail ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $status ) . '</td><td>' . esc_html( $detail ) . '</td></tr>';
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
			if ( isset( $_POST['regenerate'] ) ) {
				self::generate();
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Agent Commerce settings saved.', 'kalicart-bridge' ) . '</p></div>';
		}

		$opts      = self::get_options();
		$stats     = isset( $opts['last_stats'] ) && is_array( $opts['last_stats'] ) ? $opts['last_stats'] : null;
		$generated = $stats && empty( $stats['error'] );
		$countries = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $opts['target_countries'] ) ) ) ) );
		$countries_ready = (bool) $countries;
		foreach ( $countries as $country ) {
			if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$countries_ready = false;
				break;
			}
		}
		$return_ready = 0 === strpos( (string) $opts['return_policy_url'], 'https://' )
			&& (bool) filter_var( $opts['return_policy_url'], FILTER_VALIDATE_URL );
		$image_state  = $stats && empty( $stats['error'] ) ? 0 === (int) ( $stats['excluded_no_image'] ?? 0 ) : null;
		$schema_state = $stats && empty( $stats['error'] ) ? 0 === (int) ( $stats['excluded_invalid'] ?? 0 ) : null;
		$brand_status = self::readiness_label( null );
		$brand_detail = 'Run feed generation to check declared brands.';
		if ( $stats && empty( $stats['error'] ) && array_key_exists( 'fallback_brand_rows', $stats ) ) {
			$brand_excluded = (int) ( $stats['excluded_no_brand'] ?? 0 );
			$brand_fallback = (int) $stats['fallback_brand_rows'];
			if ( $brand_fallback > 0 ) {
				$brand_status = '<strong style="color:#b45309">Fallback applied</strong>';
				$brand_detail = $brand_fallback . ' missing-brand ' . ( 1 === $brand_fallback ? 'row' : 'rows' ) . ' filled by the merchant fallback; ' . $brand_excluded . ' ' . ( 1 === $brand_excluded ? 'row' : 'rows' ) . ' excluded.';
			} else {
				$brand_status = self::readiness_label( 0 === $brand_excluded, 'Complete', 'Missing rows' );
				$brand_detail = $brand_excluded . ' feed rows excluded in the last run; no fallback was applied.';
			}
		}

		echo '<div class="kali-section-title">' . esc_html__( 'Agent Commerce', 'kalicart-bridge' ) . '</div>';
		echo '<p>' . esc_html__( 'Your live agent-readable catalog and the optional ChatGPT product feed are separate outputs of the same WooCommerce data.', 'kalicart-bridge' ) . '</p>';

		echo '<div class="card" style="max-width:960px">';
		echo '<h2>' . esc_html__( 'Agent-readable catalog', 'kalicart-bridge' ) . '</h2>';
		echo '<p><strong style="color:#008a20">' . esc_html__( 'Active', 'kalicart-bridge' ) . '</strong> &mdash; ' . esc_html__( 'Your products remain available through KaliCart search, REST API, MCP and UCP surfaces.', 'kalicart-bridge' ) . '</p>';
		echo '<p>' . esc_html__( 'Missing brand or primary image does not invalidate the agent-readable catalog. Agents can still search, inspect and verify those products from WooCommerce live data.', 'kalicart-bridge' ) . '</p>';
		echo '<p><a href="' . esc_url( rest_url( KALICART_BRIDGE_API_NS . '/discovery' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open discovery document', 'kalicart-bridge' ) . '</a></p>';
		echo '</div>';

		echo '<div class="card" style="max-width:960px">';
		echo '<h2>' . esc_html__( 'ChatGPT Product Feed (OpenAI)', 'kalicart-bridge' ) . '</h2>';
		echo '<p>' . esc_html__( 'This optional export follows OpenAI’s direct product feed specification. Application and approval are required; after approval, OpenAI provides the delivery channel. Checkout stays on your storefront.', 'kalicart-bridge' ) . '</p>';
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'ChatGPT feed only:', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'Every status and setting in this section refers only to the optional file delivered to OpenAI. It does not enable, disable or limit KaliCart search, REST API, MCP, UCP or the federated catalog.', 'kalicart-bridge' ) . '</p></div>';

		if ( $stats && ! empty( $stats['error'] ) ) {
			$details = $stats['config_errors'] ?? [ $stats['detail'] ?? '' ];
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Last generation blocked:', 'kalicart-bridge' ) . '</strong> ' . esc_html( implode( ' / ', array_filter( $details ) ) ) . '. ' . esc_html__( 'The previous valid feed, if any, was preserved.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( null === $stats ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Feed readiness has not been checked with the current settings. Save and generate a snapshot to run the validator.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['excluded_no_brand'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Missing product brand.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'Brand is required by OpenAI’s direct product feed specification. Affected feed rows are excluded from the ChatGPT product feed, but remain fully available through KaliCart’s agent-readable catalog, search, REST API, MCP and UCP surfaces.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['fallback_brand_rows'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Merchant brand fallback applied.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'These rows do not contain a product-level brand in WooCommerce. The merchant is responsible for declaring that the fallback is accurate for every affected product.', 'kalicart-bridge' ) . '</p></div>';
		}
		if ( $stats && empty( $stats['error'] ) && (int) ( $stats['excluded_no_image'] ?? 0 ) > 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Missing primary product image.', 'kalicart-bridge' ) . '</strong> ' . esc_html__( 'A primary product image is required by OpenAI’s direct product feed specification. Affected feed rows remain available in the agent-readable catalog but are excluded from the ChatGPT product feed.', 'kalicart-bridge' ) . '</p></div>';
		}

		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Check', 'kalicart-bridge' ) . '</th><th>' . esc_html__( 'Status', 'kalicart-bridge' ) . '</th><th>' . esc_html__( 'Meaning', 'kalicart-bridge' ) . '</th></tr></thead><tbody>';
		self::readiness_row( 'Return policy', self::readiness_label( $return_ready ), $return_ready ? 'Configured in the Settings tab: ' . (string) $opts['return_policy_url'] : 'Configure it once in the Settings tab; feed generation is blocked when missing.' );
		self::readiness_row( 'Target countries', self::readiness_label( $countries_ready ), $countries_ready ? implode( ', ', $countries ) : 'Use ISO 3166-1 alpha-2 country codes.' );
		self::readiness_row( 'Product brand', $brand_status, $brand_detail );
		self::readiness_row( 'Primary image', self::readiness_label( $image_state, 'Complete', 'Missing rows' ), null === $image_state ? 'Run feed generation to check.' : (int) ( $stats['excluded_no_image'] ?? 0 ) . ' feed rows excluded in the last run.' );
		self::readiness_row( 'Schema validation', self::readiness_label( $schema_state, 'Passed', 'Invalid rows' ), null === $schema_state ? 'Run feed generation to check.' : (int) ( $stats['excluded_invalid'] ?? 0 ) . ' invalid rows in the last run.' );
		self::readiness_row( 'Daily ChatGPT feed refresh', self::readiness_label( (bool) $opts['enabled'], 'Enabled', 'Manual only' ), $opts['enabled'] ? 'Regenerates the validated ChatGPT feed file once per day; delivery to OpenAI is a separate step.' : 'The ChatGPT feed changes only when generated manually and may become outdated after catalog changes.' );
		self::readiness_row( 'OpenAI feed delivery', self::readiness_label( false, 'Connected', 'Application required' ), 'This plugin currently prepares the file. OpenAI approves the merchant and assigns SFTP or API delivery credentials.' );
		echo '</tbody></table>';

		if ( $generated ) {
			echo '<p><strong>' . esc_html__( 'Last validated ChatGPT feed snapshot:', 'kalicart-bridge' ) . '</strong> ' . esc_html( (string) ( $stats['generated_at'] ?? '' ) ) . ' &mdash; ' . (int) ( $stats['rows'] ?? 0 ) . ' ' . esc_html__( 'conformant rows from', 'kalicart-bridge' ) . ' ' . (int) ( $stats['products'] ?? 0 ) . ' ' . esc_html__( 'products.', 'kalicart-bridge' ) . '</p>';
			if ( ! empty( $stats['invalid_examples'] ) ) {
				echo '<p style="color:#b32d2e"><small>' . esc_html( implode( ' | ', $stats['invalid_examples'] ) ) . '</small></p>';
			}
			echo '<p><a class="button" href="' . esc_url( self::feed_url() ) . '" download>' . esc_html__( 'Download JSONL', 'kalicart-bridge' ) . '</a> <a class="button" href="' . esc_url( self::feed_url() . '.gz' ) . '" download>' . esc_html__( 'Download JSONL.GZ', 'kalicart-bridge' ) . '</a></p>';
		}
		echo '</div>';

		echo '<div class="card" style="max-width:960px"><h2>' . esc_html__( 'ChatGPT feed generation settings', 'kalicart-bridge' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=kalicart-bridge&tab=agent-commerce' ) ) . '">';
		wp_nonce_field( 'kb_acp_save', 'kb_acp_nonce' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Generate the ChatGPT feed daily', 'kalicart-bridge' ) . '</th><td><input type="checkbox" name="enabled" ' . checked( $opts['enabled'], true, false ) . '><p class="description">' . esc_html__( 'Refreshes the local validated file only. Automatic delivery can be configured after OpenAI approves the merchant and supplies credentials.', 'kalicart-bridge' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Brand fallback (optional)', 'kalicart-bridge' ) . '</th><td><input type="text" class="regular-text" name="brand_fallback" value="' . esc_attr( $opts['brand_fallback'] ) . '" placeholder="' . esc_attr__( 'Your merchant-owned brand', 'kalicart-bridge' ) . '"><p class="description">' . esc_html__( 'Leave empty unless every otherwise brandless product is genuinely sold under this merchant-owned brand. By entering a value, the merchant declares it accurate and accepts responsibility for applying it to every missing-brand feed row. Multi-brand retailers should leave this empty.', 'kalicart-bridge' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Target countries', 'kalicart-bridge' ) . '</th><td><input type="text" class="regular-text" name="target_countries" value="' . esc_attr( $opts['target_countries'] ) . '"><p class="description">' . esc_html__( 'Comma-separated ISO 3166-1 alpha-2 codes. Defaults to WooCommerce selling locations.', 'kalicart-bridge' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( esc_html__( 'Save settings', 'kalicart-bridge' ) );
		echo '<button class="button button-secondary" name="regenerate" value="1">' . esc_html__( 'Save and generate/validate now', 'kalicart-bridge' ) . '</button>';
		echo '</form></div>';

		echo '<div class="card" style="max-width:960px"><h2>' . esc_html__( 'Delivery', 'kalicart-bridge' ) . '</h2>';
		echo '<ol><li>' . esc_html__( 'Configure and generate a validated snapshot.', 'kalicart-bridge' ) . '</li><li>' . esc_html__( 'Apply for direct-feed access at chatgpt.com/merchants.', 'kalicart-bridge' ) . '</li><li>' . esc_html__( 'After approval, upload the stable JSONL.GZ file through the delivery channel OpenAI assigns.', 'kalicart-bridge' ) . '</li></ol>';
		echo '<p>' . esc_html__( 'The feed format is region-neutral. Merchant eligibility and shopping-surface availability are determined by OpenAI and may vary by market.', 'kalicart-bridge' ) . '</p>';
		echo '</div>';
	}
}
