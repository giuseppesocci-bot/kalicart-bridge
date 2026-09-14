<?php
defined( 'ABSPATH' ) || exit;

/**
 * Merchant authorization ledger for optional federated distribution providers.
 *
 * The direct merchant feed is intentionally outside this class. Provider consent
 * is local first, append-only, and independently receipted by KaliCart Global.
 */
class KaliCart_Bridge_Commerce_Consent {
	const OPTION         = 'kalicart_bridge_commerce_consents';
	const SCHEMA_OPTION  = 'kalicart_bridge_commerce_schema_version';
	const SCHEMA_VERSION = '1';
	const PROTOCOL       = 'kalicart-commerce-consent/1';
	const RETRY_HOOK     = 'kalicart_bridge_provider_consent_retry';

	const PROVIDER_OPENAI = 'openai_acp';
	const PURPOSE_OPENAI  = 'product_discovery';
	const TERMS_VERSION   = 'commerce-consent-1.0';
	const PRIVACY_VERSION = 'bridge-privacy-2026-09-14';
	const GLOBAL_TERMS_VERSION = 'global-terms-2026-09-14';

	/** Canonical, native-English text whose SHA-256 is sent in every receipt. */
	const CANONICAL_CONSENT_TEXT = 'I authorize KaliCart Global to include this store\'s public product catalog in the OpenAI / ChatGPT product discovery pilot and, if the channel is enabled, to distribute the related public product data through OpenAI systems for commercial discovery. No customer, order, payment or credential data is shared. This authorization applies only to federated distribution by KaliCart Global, is separate from both Federated Catalog participation and the direct merchant feed, does not guarantee acceptance or delivery by OpenAI, and may be revoked at any time. I have read the KaliCart Bridge Privacy Notice and KaliCart Global Terms applicable to commerce-consent-1.0.';

	public static function init(): void {
		self::ensure_schema();
		add_action( self::RETRY_HOOK, [ __CLASS__, 'retry_receipt' ], 10, 2 );
		add_action( 'admin_post_kalicart_provider_consent_export', [ __CLASS__, 'export_evidence' ] );
	}

	/** @return array<string,array<string,mixed>> */
	public static function provider_registry(): array {
		return [
			self::PROVIDER_OPENAI => [
				'provider'      => self::PROVIDER_OPENAI,
				'label'         => __( 'OpenAI / ChatGPT product discovery', 'kalicart-bridge' ),
				'purpose'       => self::PURPOSE_OPENAI,
				'terms_version' => self::TERMS_VERSION,
				'privacy_url'   => 'https://bridge.kalicart.com/privacy/',
				'terms_url'     => 'https://global.kalicart.com/terms/',
			],
		];
	}

	public static function ensure_schema(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			protocol varchar(64) NOT NULL,
			consent_id char(36) NOT NULL,
			provider varchar(64) NOT NULL,
			purpose varchar(64) NOT NULL,
			action varchar(16) NOT NULL,
			occurred_at_utc varchar(32) NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			plugin_version varchar(32) NOT NULL,
			terms_version varchar(64) NOT NULL,
			consent_locale varchar(20) NOT NULL,
			canonical_text_hash char(64) NOT NULL,
			consent_text longtext NOT NULL,
			consent_text_hash char(64) NOT NULL,
			previous_record_hash char(64) NULL,
			record_hash char(64) NOT NULL,
			receipt_status varchar(16) NOT NULL DEFAULT 'pending',
			receipt_updated_at_utc varchar(32) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY consent_id (consent_id),
			KEY provider_occurred (provider,occurred_at_utc)
		) {$charset};";
		dbDelta( $sql );
		add_option( self::OPTION, [ 'schema_version' => 1, 'providers' => [] ], '', false );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function is_global_active(): bool {
		return (bool) get_option( 'kalicart_bridge_global_consent', false )
			&& '' !== (string) get_option( 'kalicart_bridge_federation_registered_at', '' );
	}

	public static function localized_consent_text(): string {
		return __( 'I authorize KaliCart Global to include this store\'s public product catalog in the OpenAI / ChatGPT product discovery pilot and, if the channel is enabled, to distribute the related public product data through OpenAI systems for commercial discovery. No customer, order, payment or credential data is shared. This authorization applies only to federated distribution by KaliCart Global, is separate from both Federated Catalog participation and the direct merchant feed, does not guarantee acceptance or delivery by OpenAI, and may be revoked at any time. I have read the KaliCart Bridge Privacy Notice and KaliCart Global Terms applicable to commerce-consent-1.0.', 'kalicart-bridge' );
	}

	/** @return array<string,mixed> */
	public static function current( string $provider = self::PROVIDER_OPENAI ): array {
		$all      = get_option( self::OPTION, [] );
		$registry = self::provider_registry();
		$base     = [
			'provider'                => $provider,
			'purpose'                 => $registry[ $provider ]['purpose'] ?? '',
			'authorized'              => false,
			'consent_id'              => null,
			'action'                  => null,
			'occurred_at'             => null,
			'terms_version'           => $registry[ $provider ]['terms_version'] ?? '',
			'consent_locale'          => null,
			'canonical_text_hash'     => null,
			'consent_text_hash'       => null,
			'previous_record_hash'    => null,
			'record_hash'             => null,
			'receipt_status'          => null,
			'receipt_updated_at_utc'  => null,
			'global_receipt_status'   => null,
			'global_authorization_state' => null,
			'delivery_state'          => 'not_configured',
			'last_error'              => null,
		];
		if ( ! is_array( $all ) || ! isset( $all['providers'][ $provider ] ) || ! is_array( $all['providers'][ $provider ] ) ) {
			return $base;
		}
		return array_merge( $base, $all['providers'][ $provider ] );
	}

	/** Public, non-PII state embedded in the Bridge discovery document. */
	public static function discovery_state(): array {
		$providers = [];
		foreach ( self::provider_registry() as $provider => $config ) {
			$current = self::current( $provider );
			$providers[ $provider ] = [
				'authorized'            => true === $current['authorized'],
				'purpose'               => $config['purpose'],
				'consent_id'            => $current['consent_id'],
				'occurred_at'           => $current['occurred_at'],
				'terms_version'         => $config['terms_version'],
				'canonical_text_hash'   => $current['canonical_text_hash'],
				'consent_text_hash'     => $current['consent_text_hash'],
				'previous_record_hash'  => $current['previous_record_hash'],
				'record_hash'           => $current['record_hash'],
			];
		}
		return [
			'schema_version' => 1,
			'global_active'  => self::is_global_active(),
			'providers'      => $providers,
		];
	}

	/**
	 * Record a positive or negative merchant act, update local state, then send
	 * the minimal receipt. The caller must enforce checkbox, nonce and capability.
	 *
	 * @return array<string,mixed>
	 */
	public static function record_action( string $provider, string $action, int $user_id ): array {
		if ( ! isset( self::provider_registry()[ $provider ] ) ) {
			throw new InvalidArgumentException( 'unsupported_provider' );
		}
		if ( ! in_array( $action, [ 'granted', 'revoked' ], true ) ) {
			throw new InvalidArgumentException( 'unsupported_action' );
		}
		if ( 'granted' === $action && ! self::is_global_active() ) {
			throw new RuntimeException( 'global_not_active' );
		}

		return self::with_lock( $provider, static function() use ( $provider, $action, $user_id ): array {
			$current = self::current( $provider );
			if ( ( 'granted' === $action && true === $current['authorized'] )
				|| ( 'revoked' === $action && false === $current['authorized'] ) ) {
				if ( ! empty( $current['consent_id'] ) && in_array( $current['receipt_status'], [ 'pending', 'failed' ], true ) ) {
					$result = self::send_receipt( (string) $current['consent_id'] );
					if ( ! $result['ok'] && $result['retryable'] ) {
						self::schedule_retries( (string) $current['consent_id'] );
					}
					$current = self::current( $provider );
				}
				$current['idempotent'] = true;
				return $current;
			}

			global $wpdb;
			$config       = self::provider_registry()[ $provider ];
			$consent_id   = wp_generate_uuid4();
			$occurred_at  = gmdate( 'Y-m-d\TH:i:s\Z' );
			$locale       = self::normalize_receipt_locale( function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : get_locale() );
			$consent_text = self::localized_consent_text();
			$previous     = $wpdb->get_var( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is plugin-owned; the provider value uses a placeholder.
				'SELECT record_hash FROM ' . self::table_name() . ' WHERE provider=%s ORDER BY id DESC LIMIT 1',
				$provider
			) );
			$immutable = [
				'protocol'              => self::PROTOCOL,
				'consent_id'            => $consent_id,
				'provider'              => $provider,
				'purpose'               => $config['purpose'],
				'action'                => $action,
				'occurred_at_utc'       => $occurred_at,
				'wp_user_id'            => $user_id,
				'plugin_version'        => KALICART_BRIDGE_VERSION,
				'terms_version'         => $config['terms_version'],
				'consent_locale'        => (string) $locale,
				'canonical_text_hash'   => hash( 'sha256', self::CANONICAL_CONSENT_TEXT ),
				'consent_text'          => $consent_text,
				'consent_text_hash'     => hash( 'sha256', $consent_text ),
				'previous_record_hash'  => $previous ? (string) $previous : null,
			];
			$record_hash = self::record_hash( $immutable );
			$inserted = $wpdb->insert(
				self::table_name(),
				array_merge( $immutable, [
					'record_hash'            => $record_hash,
					'receipt_status'         => 'pending',
					'receipt_updated_at_utc' => $occurred_at,
				] ),
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
			if ( false === $inserted ) {
				throw new RuntimeException( 'consent_log_write_failed' );
			}

			$state = [
				'provider'                => $provider,
				'purpose'                 => $config['purpose'],
				'authorized'              => 'granted' === $action,
				'consent_id'              => $consent_id,
				'action'                  => $action,
				'occurred_at'             => $occurred_at,
				'terms_version'           => $config['terms_version'],
				'consent_locale'          => (string) $locale,
				'canonical_text_hash'     => $immutable['canonical_text_hash'],
				'consent_text_hash'       => $immutable['consent_text_hash'],
				'previous_record_hash'    => $immutable['previous_record_hash'],
				'record_hash'             => $record_hash,
				'receipt_status'          => 'pending',
				'receipt_updated_at_utc'  => $occurred_at,
				'global_receipt_status'   => 'pending',
				'global_authorization_state' => 'not_active',
				'delivery_state'          => 'revoked' === $action ? 'suspended_pending_verification' : 'not_configured',
				'last_error'              => null,
			];
			self::set_current( $provider, $state );

			$result = self::send_receipt( $consent_id );
			if ( ! $result['ok'] && $result['retryable'] ) {
				self::schedule_retries( $consent_id );
			}
			return self::current( $provider );
		} );
	}

	/** @return array{ok:bool,retryable:bool,reason:?string} */
	public static function send_receipt( string $consent_id ): array {
		$row = self::receipt_row( $consent_id );
		if ( ! $row ) {
			return [ 'ok' => false, 'retryable' => false, 'reason' => 'receipt_not_found' ];
		}
		$payload = [
			'protocol'             => self::PROTOCOL,
			'domain'               => trailingslashit( get_site_url() ),
			'consent_id'           => $row['consent_id'],
			'provider'             => $row['provider'],
			'purpose'              => $row['purpose'],
			'action'               => $row['action'],
			'occurred_at'          => $row['occurred_at_utc'],
			'plugin_version'       => $row['plugin_version'],
			'terms_version'        => $row['terms_version'],
			'consent_locale'       => $row['consent_locale'],
			'canonical_text_hash'  => $row['canonical_text_hash'],
			'consent_text_hash'    => $row['consent_text_hash'],
			'previous_record_hash' => $row['previous_record_hash'] ?: null,
			'record_hash'          => $row['record_hash'],
		];
		$response = wp_remote_post( KALICART_BRIDGE_GLOBAL . '/v1/bridge/provider-consent', [
			'timeout'   => 10,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
		] );
		if ( is_wp_error( $response ) ) {
			self::update_receipt_state( $row, 'pending', null, $response->get_error_message() );
			return [ 'ok' => false, 'retryable' => true, 'reason' => 'network_error' ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['ok'] ) ) {
			self::update_receipt_state( $row, 'accepted', $body, null );
			return [ 'ok' => true, 'retryable' => false, 'reason' => null ];
		}
		$reason    = is_array( $body ) && isset( $body['error'] ) ? sanitize_key( $body['error'] ) : 'http_' . $code;
		$retryable = 409 === $code || 429 === $code || $code >= 500;
		self::update_receipt_state( $row, $retryable ? 'pending' : 'failed', $body, $reason );
		return [ 'ok' => false, 'retryable' => $retryable, 'reason' => $reason ];
	}

	public static function retry_receipt( string $consent_id, int $attempt = 1 ): void {
		$row = self::receipt_row( sanitize_text_field( $consent_id ) );
		if ( ! $row || 'accepted' === $row['receipt_status'] ) {
			return;
		}
		self::send_receipt( $row['consent_id'] );
	}

	/** @return array<string,mixed> */
	public static function refresh_remote_status( string $provider = self::PROVIDER_OPENAI ): array {
		$current = self::current( $provider );
		if ( empty( $current['consent_id'] ) ) {
			return $current;
		}
		$url = add_query_arg( [
			'domain'     => trailingslashit( get_site_url() ),
			'consent_id' => $current['consent_id'],
		], KALICART_BRIDGE_GLOBAL . '/v1/bridge/provider-consent/status' );
		$response = wp_remote_get( $url, [
			'timeout'   => 8,
			'sslverify' => true,
			'headers'   => [ 'Accept' => 'application/json' ],
		] );
		if ( is_wp_error( $response ) ) {
			return $current;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['ok'] ) ) {
			return $current;
		}
		$current['global_receipt_status']      = sanitize_key( $body['receipt_status'] ?? '' );
		$current['global_authorization_state'] = sanitize_key( $body['authorization_state'] ?? '' );
		$current['delivery_state']             = sanitize_key( $body['delivery_state'] ?? 'not_configured' );
		$current['receipt_updated_at_utc']     = gmdate( 'Y-m-d\TH:i:s\Z' );
		$current['last_error']                 = null;
		if ( 'rejected' === $current['global_receipt_status'] ) {
			$current['receipt_status'] = 'failed';
		}
		self::set_current( $provider, $current );
		return $current;
	}

	public static function export_url( string $format ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=kalicart_provider_consent_export&format=' . rawurlencode( $format ) ),
			'kalicart_provider_consent_export'
		);
	}

	public static function export_evidence(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'kalicart-bridge' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kalicart_provider_consent_export' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above; format only selects representation.
		$format = sanitize_key( wp_unslash( $_GET['format'] ?? 'json' ) );
		if ( ! in_array( $format, [ 'json', 'csv' ], true ) ) {
			wp_die( esc_html__( 'Unsupported export format.', 'kalicart-bridge' ), '', [ 'response' => 400 ] );
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned and contains no input.
		$rows = array_map( static function( array $row ): array {
			$row['record_hash_valid'] = hash_equals( (string) $row['record_hash'], self::record_hash( self::immutable_from_row( $row ) ) );
			return $row;
		}, $rows ?: [] );
		$stamp = gmdate( 'Y-m-d' );
		nocache_headers();
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="kalicart-provider-consent-evidence-' . $stamp . '.json"' );
			echo wp_json_encode( [
				'protocol'    => self::PROTOCOL,
				'store_url'   => trailingslashit( get_site_url() ),
				'exported_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'records'     => $rows,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML; wp_json_encode is the serializer.
			exit;
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kalicart-provider-consent-evidence-' . $stamp . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed download; no filesystem path is opened.
		$columns = [ 'id','protocol','consent_id','provider','purpose','action','occurred_at_utc','wp_user_id','plugin_version','terms_version','consent_locale','canonical_text_hash','consent_text','consent_text_hash','previous_record_hash','record_hash','record_hash_valid','receipt_status','receipt_updated_at_utc' ];
		fputcsv( $out, $columns );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( static function( $column ) use ( $row ) {
				$value = $row[ $column ] ?? '';
				if ( is_bool( $value ) ) $value = $value ? 'true' : 'false';
				if ( is_string( $value ) && preg_match( '/^[=+\-@]/', $value ) ) $value = "'" . $value;
				return $value;
			}, $columns ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes php://output stream.
		exit;
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'kalicart_bridge_consent_log';
	}

	private static function with_lock( string $provider, callable $callback ): array {
		$lock = 'kalicart_bridge_consent_lock_' . sanitize_key( $provider );
		if ( ! add_option( $lock, time(), '', false ) ) {
			$created = (int) get_option( $lock, 0 );
			if ( $created > 0 && $created < time() - 60 ) {
				delete_option( $lock );
			}
			if ( ! add_option( $lock, time(), '', false ) ) {
				throw new RuntimeException( 'consent_action_in_progress' );
			}
		}
		try {
			return $callback();
		} finally {
			delete_option( $lock );
		}
	}

	private static function set_current( string $provider, array $state ): void {
		$all = get_option( self::OPTION, [] );
		if ( ! is_array( $all ) ) $all = [];
		$all['schema_version'] = 1;
		if ( ! isset( $all['providers'] ) || ! is_array( $all['providers'] ) ) $all['providers'] = [];
		$all['providers'][ $provider ] = $state;
		update_option( self::OPTION, $all, false );
	}

	private static function record_hash( array $immutable ): string {
		return hash( 'sha256', (string) wp_json_encode( $immutable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function immutable_from_row( array $row ): array {
		return [
			'protocol'             => (string) ( $row['protocol'] ?? self::PROTOCOL ),
			'consent_id'           => (string) $row['consent_id'],
			'provider'             => (string) $row['provider'],
			'purpose'              => (string) $row['purpose'],
			'action'               => (string) $row['action'],
			'occurred_at_utc'      => (string) $row['occurred_at_utc'],
			'wp_user_id'           => (int) $row['wp_user_id'],
			'plugin_version'       => (string) $row['plugin_version'],
			'terms_version'        => (string) $row['terms_version'],
			'consent_locale'       => (string) $row['consent_locale'],
			'canonical_text_hash'  => (string) $row['canonical_text_hash'],
			'consent_text'         => (string) $row['consent_text'],
			'consent_text_hash'    => (string) $row['consent_text_hash'],
			'previous_record_hash' => '' === (string) ( $row['previous_record_hash'] ?? '' ) ? null : (string) $row['previous_record_hash'],
		];
	}

	private static function receipt_row( string $consent_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is plugin-owned; the consent ID uses a placeholder.
			'SELECT * FROM ' . self::table_name() . ' WHERE consent_id=%s LIMIT 1',
			$consent_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function update_receipt_state( array $row, string $status, ?array $remote, ?string $error ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$wpdb->update(
			self::table_name(),
			[ 'receipt_status' => $status, 'receipt_updated_at_utc' => $now ],
			[ 'consent_id' => $row['consent_id'] ],
			[ '%s', '%s' ],
			[ '%s' ]
		);
		$current = self::current( $row['provider'] );
		if ( $current['consent_id'] !== $row['consent_id'] ) {
			return;
		}
		$current['receipt_status']         = $status;
		$current['receipt_updated_at_utc'] = $now;
		$current['last_error']             = $error;
		if ( is_array( $remote ) ) {
			$current['global_receipt_status']      = sanitize_key( $remote['receipt_status'] ?? $current['global_receipt_status'] );
			$current['global_authorization_state'] = sanitize_key( $remote['authorization_state'] ?? $current['global_authorization_state'] );
			$current['delivery_state']             = sanitize_key( $remote['delivery_state'] ?? $current['delivery_state'] );
		}
		self::set_current( $row['provider'], $current );
	}

	private static function schedule_retries( string $consent_id ): void {
		foreach ( [ [ 60, 1 ], [ 5 * MINUTE_IN_SECONDS, 2 ] ] as $retry ) {
			$args = [ $consent_id, $retry[1] ];
			if ( ! wp_next_scheduled( self::RETRY_HOOK, $args ) ) {
				wp_schedule_single_event( time() + $retry[0], self::RETRY_HOOK, $args );
			}
		}
	}

	private static function normalize_receipt_locale( string $locale ): string {
		if ( preg_match( '/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale ) ) {
			return $locale;
		}
		if ( preg_match( '/^([a-z]{2})_([A-Z]{2})/', $locale, $matches ) ) {
			return $matches[1] . '_' . $matches[2];
		}
		return 'en_US';
	}
}
