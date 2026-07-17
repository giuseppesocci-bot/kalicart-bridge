<?php
/**
 * Automatic KaliCart Global federation lifecycle.
 *
 * Federation is a built-in Bridge capability: activation and the 1.0.122
 * migration announce the public store URL; uninstall requests immediate
 * deregistration. Network work runs through WP-Cron, never on storefront REST,
 * catalog, MCP or checkout requests.
 */

defined( 'ABSPATH' ) || exit;

class KaliCart_Bridge_Federation {
	private const ANNOUNCE_HOOK    = 'kalicart_bridge_federation_announce';
	private const LIFECYCLE_VERSION = '1.0.122';
	private const MAX_ATTEMPTS      = 5;

	public static function init(): void {
		add_action( self::ANNOUNCE_HOOK, array( __CLASS__, 'announce' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 5 );
	}

	/**
	 * New activation: make the local discovery contract indexable immediately,
	 * then queue the external announcement outside the activation request.
	 */
	public static function activate(): void {
		update_option( 'kalicart_bridge_global_consent', true );
		update_option( 'kalicart_bridge_federation_lifecycle_version', self::LIFECYCLE_VERSION );
		delete_option( 'kalicart_bridge_federation_announce_attempts' );
		delete_option( 'kalicart_bridge_federation_last_error' );
		self::schedule_announce( 1 );
	}

	/**
	 * Existing active installs do not run the activation hook during an update.
	 * This version-gated migration enrolls 1.0.121 installations exactly once.
	 */
	public static function maybe_migrate(): void {
		if ( self::LIFECYCLE_VERSION === get_option( 'kalicart_bridge_federation_lifecycle_version', '' ) ) {
			return;
		}

		update_option( 'kalicart_bridge_global_consent', true );
		update_option( 'kalicart_bridge_federation_lifecycle_version', self::LIFECYCLE_VERSION );
		delete_option( 'kalicart_bridge_federation_announce_attempts' );
		delete_option( 'kalicart_bridge_federation_last_error' );
		self::schedule_announce( 1 );
	}

	/**
	 * Announce only the canonical public site URL. Failures are retried with a
	 * bounded exponential delay; normal site traffic never waits for Global.
	 */
	public static function announce(): void {
		if ( ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return;
		}

		$site_url = trailingslashit( get_site_url() );
		$response = wp_remote_post(
			KALICART_BRIDGE_GLOBAL . '/v1/bridge/announce',
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( array( 'domain' => $site_url ) ),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				update_option( 'kalicart_bridge_federation_registered_at', gmdate( 'c' ) );
				delete_option( 'kalicart_bridge_federation_announce_attempts' );
				delete_option( 'kalicart_bridge_federation_last_error' );
				return;
			}
			$error = 'http_' . $code;
		} else {
			$error = sanitize_text_field( $response->get_error_code() );
		}

		$attempts = min( self::MAX_ATTEMPTS, (int) get_option( 'kalicart_bridge_federation_announce_attempts', 0 ) + 1 );
		update_option( 'kalicart_bridge_federation_announce_attempts', $attempts, false );
		update_option( 'kalicart_bridge_federation_last_error', $error, false );

		if ( $attempts < self::MAX_ATTEMPTS ) {
			$delay = min( HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** ( $attempts - 1 ) ) );
			self::schedule_announce( $delay );
		}
	}

	private static function schedule_announce( int $delay ): void {
		if ( ! wp_next_scheduled( self::ANNOUNCE_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::ANNOUNCE_HOOK );
		}
	}
}
