<?php
defined( 'ABSPATH' ) || exit;

/**
 * A bounded WooCommerce quote calculation with no checkout session or order.
 * This calculator has no public route until the isolation gate is complete.
 */
final class KaliCart_Bridge_Quote {
	private const MAX_ITEMS = 20;
	private const CORE_SHIPPING_METHODS = [ 'flat_rate', 'free_shipping', 'local_pickup' ];

	private static function unknown( string $reason ): array {
		return [ 'status' => 'unknown', 'reason' => $reason ];
	}

	private static function shipping_cache_ready(): bool {
		$version = get_transient( 'shipping-transient-version' );
		$counts  = get_transient( 'wc_shipping_method_count' );
		return is_string( $version ) && '' !== $version && is_array( $counts )
			&& isset( $counts['version'], $counts['legacy'], $counts['enabled'], $counts['disabled'] )
			&& $counts['version'] === $version;
	}

	/**
	 * The quote must never execute a third-party totals/rates callback whose
	 * side effects are unknown. WooCommerce core callbacks remain in place.
	 */
	private static function has_external_calculation_hooks(): bool {
		global $wp_filter;
		$hooks = [
			'woocommerce_before_calculate_totals', 'woocommerce_after_calculate_totals',
			'woocommerce_cart_calculate_fees', 'woocommerce_package_rates',
			'woocommerce_cart_shipping_packages', 'woocommerce_shipping_packages',
			'woocommerce_before_get_rates_for_package', 'woocommerce_after_get_rates_for_package',
		];
		$core = wp_normalize_path( WC_ABSPATH );
		foreach ( $hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) { continue; }
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$callback = $entry['function'];
					try {
						$ref = is_array( $callback )
							? new ReflectionMethod( $callback[0], $callback[1] )
							: new ReflectionFunction( $callback );
						$file = $ref->getFileName();
					} catch ( ReflectionException | TypeError $error ) {
						return true;
					}
					if ( ! is_string( $file ) || 0 !== strpos( wp_normalize_path( $file ), $core ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private static function core_shipping_only( array $package ): bool {
		global $wpdb;
		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		if ( ! $zone instanceof WC_Shipping_Zone ) { return false; }
		$table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
		$method_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT method_id FROM {$table} WHERE zone_id = %d AND is_enabled = 1",
			$zone->get_id()
		) );
		if ( ! is_array( $method_ids ) || [] === $method_ids ) { return false; }
		foreach ( $method_ids as $id ) {
			if ( ! in_array( $id, self::CORE_SHIPPING_METHODS, true ) ) { return false; }
		}
		return true;
	}

	private static function remove_cart_hooks( WC_Cart $cart ): void {
		foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored' ] as $hook ) {
			remove_action( $hook, [ $cart, 'calculate_totals' ], 20 );
		}
		remove_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_items' ], 1 );
		remove_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_coupons' ], 1 );
		remove_action( 'woocommerce_after_checkout_validation', [ $cart, 'check_customer_coupons' ], 1 );
	}

	/**
	 * @param array $items Each line: product_id, optional variation_id, quantity.
	 * @param array $destination country, optional state, postcode and city.
	 */
	public static function calculate( array $items, array $destination ): array {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Cart' ) ) {
			return self::unknown( 'woocommerce_unavailable' );
		}
		if ( count( $items ) < 1 || count( $items ) > self::MAX_ITEMS ) {
			return self::unknown( 'invalid_item_count' );
		}
		$country = $destination['country'] ?? null;
		$state = $destination['state'] ?? '';
		$postcode = $destination['postcode'] ?? '';
		$city = $destination['city'] ?? '';
		if ( ! is_string( $country ) || ! preg_match( '/^[A-Z]{2}$/', $country )
			|| ! is_string( $state ) || strlen( $state ) > 16
			|| ! is_string( $postcode ) || strlen( $postcode ) > 20
			|| ! is_string( $city ) || strlen( $city ) > 100 ) {
			return self::unknown( 'invalid_destination' );
		}
		if ( self::has_external_calculation_hooks() ) {
			return self::unknown( 'external_calculation_hook' );
		}
		if ( wc_tax_enabled() && 'billing' === get_option( 'woocommerce_tax_based_on' ) ) {
			return self::unknown( 'billing_address_required_for_tax' );
		}
		$contents = [];
		$requires_shipping = false;
		foreach ( $items as $index => $line ) {
			if ( ! is_array( $line ) || ! isset( $line['product_id'], $line['quantity'] )
				|| ! is_int( $line['product_id'] ) || $line['product_id'] < 1
				|| ! is_int( $line['quantity'] ) || $line['quantity'] < 1 || $line['quantity'] > 999
				|| ( isset( $line['variation_id'] ) && ( ! is_int( $line['variation_id'] ) || $line['variation_id'] < 1 ) ) ) {
				return self::unknown( 'invalid_item' );
			}
			$product = wc_get_product( $line['variation_id'] ?? $line['product_id'] );
			if ( ! $product || ! $product->is_type( [ 'simple', 'variation' ] )
				|| 'publish' !== get_post_status( $product->get_id() )
				|| ! $product->is_purchasable() || ! $product->is_in_stock() || '' === $product->get_price() ) {
				return self::unknown( 'item_not_quotable' );
			}
			$variation_id = 0;
			$product_id = $line['product_id'];
			if ( $product->is_type( 'variation' ) ) {
				$variation_id = $product->get_id();
				if ( $product->get_parent_id() !== $product_id ) { return self::unknown( 'variation_parent_mismatch' ); }
			} elseif ( isset( $line['variation_id'] ) ) {
				return self::unknown( 'variation_parent_mismatch' );
			}
			$requires_shipping = $requires_shipping || $product->needs_shipping();
			$contents[ 'kali_quote_' . $index ] = [
				'key' => 'kali_quote_' . $index, 'product_id' => $product_id,
				'variation_id' => $variation_id, 'variation' => $variation_id ? $product->get_variation_attributes() : [],
				'quantity' => $line['quantity'], 'data' => $product,
				'data_hash' => wc_get_cart_item_data_hash( $product ),
				'line_subtotal' => 0, 'line_total' => 0, 'line_subtotal_tax' => 0,
				'line_tax' => 0, 'line_tax_data' => [ 'subtotal' => [], 'total' => [] ],
			];
		}
		if ( $requires_shipping && ! self::shipping_cache_ready() ) {
			return self::unknown( 'shipping_cache_not_ready' );
		}

		$previous_cart = WC()->cart;
		$previous_session = WC()->session;
		$previous_customer = WC()->customer;
		$shipping = WC()->shipping();
		$previous_packages = $shipping->packages;
		$disable_session_hooks = static function() { return false; };
		$temp_cart = null;
		try {
			WC()->session = new class extends WC_Session {};
			WC()->customer = new WC_Customer( 0, true );
			WC()->customer->set_shipping_country( $country );
			WC()->customer->set_shipping_state( $state );
			WC()->customer->set_shipping_postcode( $postcode );
			WC()->customer->set_shipping_city( $city );
			WC()->customer->set_billing_country( $country );
			WC()->customer->set_billing_state( $state );
			WC()->customer->set_billing_postcode( $postcode );
			WC()->customer->set_billing_city( $city );
			add_filter( 'woocommerce_cart_session_initialize', $disable_session_hooks );
			$temp_cart = new WC_Cart();
			remove_filter( 'woocommerce_cart_session_initialize', $disable_session_hooks );
			WC()->cart = $temp_cart;
			$temp_cart->set_cart_contents( $contents );
			if ( $requires_shipping ) {
				$packages = $temp_cart->get_shipping_packages();
				if ( 1 !== count( $packages ) || ! self::core_shipping_only( reset( $packages ) ) ) {
					return self::unknown( 'shipping_method_not_verifiable' );
				}
			}
			$temp_cart->calculate_totals();
			$packages = $shipping->get_packages();
			if ( $requires_shipping && ( 1 !== count( $packages ) || empty( $packages[0]['rates'] ) ) ) {
				return self::unknown( 'shipping_rate_unavailable' );
			}
			$rates = [];
			foreach ( $packages as $package ) {
				foreach ( $package['rates'] ?? [] as $rate ) {
					$rates[] = [ 'id' => $rate->get_id(), 'label' => $rate->get_label(), 'cost' => wc_format_decimal( $rate->get_cost(), wc_get_price_decimals() ) ];
				}
			}
			return [
				'status' => 'quoted', 'currency' => get_woocommerce_currency(),
				'subtotal' => wc_format_decimal( $temp_cart->get_subtotal(), wc_get_price_decimals() ),
				'shipping' => wc_format_decimal( $temp_cart->get_shipping_total(), wc_get_price_decimals() ),
				'tax' => wc_format_decimal( $temp_cart->get_total_tax(), wc_get_price_decimals() ),
				'total' => wc_format_decimal( $temp_cart->get_total( 'edit' ), wc_get_price_decimals() ),
				'rates' => $rates, 'calculated_at' => gmdate( 'c' ),
			];
		} catch ( Throwable $error ) {
			return self::unknown( 'calculation_failed' );
		} finally {
			remove_filter( 'woocommerce_cart_session_initialize', $disable_session_hooks );
			if ( $temp_cart ) { self::remove_cart_hooks( $temp_cart ); }
			WC()->cart = $previous_cart;
			WC()->session = $previous_session;
			WC()->customer = $previous_customer;
			$shipping->packages = $previous_packages;
		}
	}
}
