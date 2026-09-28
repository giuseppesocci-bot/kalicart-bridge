<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Push_Notice — 1.0.139 (contract §9): banner, menu badge and Site
 * Health result of the future catalog push (consent A v2).
 *
 * OFF in 1.0.139. PUSH_AVAILABLE is a constant on purpose, not an option or a
 * filter: nothing outside a new plugin release can turn it on. While it is false
 * init() hooks nothing, should_show() is false and no banner, badge or Site
 * Health result can appear. The texts are the ones agreed on 2026-09-27 (three
 * visible sentences, the reason_code chooses the first two; one expansion that
 * is part of the same banner and of the same receipt hash). There is no grant
 * handler here: the push itself is not in this version.
 */
class KaliCart_Bridge_Push_Notice {

	const PUSH_AVAILABLE = false;
	const TEXT_ID        = 'federated-catalog-2.0';

	/** reason_code -> first-sentence variant. Anything else never shows a banner. */
	const VARIANTS = [
		'blocked_by_bot_challenge' => 'unreadable',
		'site_unreachable'         => 'unreadable',
		'snapshot_missing'         => 'unreadable',
		'snapshot_expired'         => 'stale',
		'snapshot_overdue'         => 'stale',
		'snapshot_stale_cache'     => 'stale',
		'snapshot_hash_mismatch'   => 'stale',
		'snapshot_partial'         => 'stale',
		'snapshot_storage_failure' => 'stale',
	];

	public static function init(): void {
		if ( ! self::PUSH_AVAILABLE ) {
			return; // 1.0.139: nothing is hooked
		}
		add_action( 'admin_notices', [ __CLASS__, 'render' ] );
	}

	/** Variant for a reason_code, or null (unknown / not push-related). */
	public static function variant( string $reason ): ?string {
		return self::VARIANTS[ $reason ] ?? null;
	}

	/**
	 * Whether the banner may be shown for a validated decision_v1 block.
	 * Never: push unavailable, no consent A, unknown reason, KaliCart-side
	 * problem, served store, no action from KaliCart Global, dismissed.
	 * $available exists only so tests can check the rules with the switch on.
	 */
	public static function should_show( ?array $d, bool $available = self::PUSH_AVAILABLE, bool $dismissed = false ): bool {
		if ( ! $available || $dismissed || ! $d ) {
			return false;
		}
		if ( ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return false;
		}
		if ( empty( $d['known'] ) || 'kalicart' === ( $d['owner'] ?? '' ) || 'unknown' === ( $d['owner'] ?? 'unknown' ) || 'served' === ( $d['outcome'] ?? '' ) ) {
			return false;
		}
		if ( empty( $d['action'] ) ) {
			return false; // KaliCart Global asks for nothing: 1.0.139 always forces action=null
		}
		return null !== self::variant( (string) $d['reason_code'] );
	}

	/** Badge count on the plugin menu: 1 while the banner applies, else 0. */
	public static function badge_count( ?array $d, bool $available = self::PUSH_AVAILABLE ): int {
		return self::should_show( $d, $available ) ? 1 : 0;
	}

	/**
	 * The banner text, visible part and expansion. The receipt hash covers both.
	 * @return array{variant:string,visible:string[],expand:string[],allow:string,not_now:string,more:string}|null
	 */
	public static function texts( string $variant ): ?array {
		// The variant chooses the first two sentences (2026-09-27: "Federated Catalog"
		// once only in the stale variant); question, expansion and buttons are shared.
		if ( 'unreadable' === $variant ) {
			$first  = __( 'KaliCart Global cannot read your store\'s catalog.', 'kalicart-bridge' );
			$second = __( 'Your store still works, but prices and availability in the Federated Catalog may be out of date.', 'kalicart-bridge' );
		} elseif ( 'stale' === $variant ) {
			$first  = __( 'Your store\'s catalog is not updating in the Federated Catalog.', 'kalicart-bridge' );
			$second = __( 'Your store still works, but prices and availability may be out of date.', 'kalicart-bridge' );
		} else {
			return null;
		}
		return [
			'variant' => $variant,
			'visible' => [
				$first,
				$second,
				__( 'Allow KaliCart Bridge to send the catalog?', 'kalicart-bridge' ),
			],
			'expand'  => [
				__( 'What is sent: public catalog data: products, prices, availability, variants and image URLs.', 'kalicart-bridge' ),
				__( 'No customer or order data is sent.', 'kalicart-bridge' ),
				__( 'Recipient: KaliCart Global (dashboard.kalicart.com).', 'kalicart-bridge' ),
				__( 'When: after catalog changes and once a day for reconciliation.', 'kalicart-bridge' ),
				__( 'You can revoke permission at any time in KaliCart Bridge.', 'kalicart-bridge' ),
			],
			'allow'   => __( 'Allow sending', 'kalicart-bridge' ),
			'not_now' => __( 'Not now', 'kalicart-bridge' ),
			'more'    => __( 'What is sent', 'kalicart-bridge' ),
		];
	}

	/** sha256 of exactly what the banner shows (visible + expansion + buttons). */
	public static function text_hash( array $t ): string {
		return hash( 'sha256', implode( "\n", array_merge( $t['visible'], $t['expand'], [ $t['allow'], $t['not_now'] ] ) ) );
	}

	/** Site Health result for the push case, or null when it does not apply. */
	public static function site_health( ?array $d, bool $available = self::PUSH_AVAILABLE ): ?array {
		if ( ! self::should_show( $d, $available ) ) {
			return null;
		}
		$t = self::texts( (string) self::variant( (string) $d['reason_code'] ) );
		return [
			'label'       => $t['visible'][0],
			'status'      => 'recommended', // never critical: the store works
			'badge'       => [ 'label' => 'KaliCart Bridge', 'color' => 'blue' ],
			'description' => '<p>' . esc_html( $t['visible'][1] ) . '</p>',
			'actions'     => '<a href="' . esc_url( admin_url( 'admin.php?page=kalicart-bridge' ) ) . '">' . esc_html( $t['more'] ) . '</a>',
			'test'        => 'kalicart_bridge_push',
		];
	}

	/** Banner markup. Only reachable when PUSH_AVAILABLE is true. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'KaliCart_Bridge_Identity' ) ) {
			return;
		}
		$d = KaliCart_Bridge_Site_Health::validate_decision( KaliCart_Bridge_Identity::summary()['decision'] ?? null );
		if ( ! self::should_show( $d ) ) {
			return;
		}
		$t = self::texts( (string) self::variant( (string) $d['reason_code'] ) );
		echo '<div class="notice notice-warning kalicart-push-notice" data-text-id="' . esc_attr( self::TEXT_ID ) . '" data-text-hash="' . esc_attr( self::text_hash( $t ) ) . '"><p>' . esc_html( implode( ' ', $t['visible'] ) ) . '</p>';
		echo '<details><summary>' . esc_html( $t['more'] ) . '</summary><p>' . esc_html( implode( ' ', $t['expand'] ) ) . '</p></details>';
		echo '<p><button type="button" class="button button-primary" disabled>' . esc_html( $t['allow'] ) . '</button> <button type="button" class="button">' . esc_html( $t['not_now'] ) . '</button></p></div>';
	}
}
