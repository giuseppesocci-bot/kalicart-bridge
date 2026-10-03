<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Identity — signed installation identity (1.0.138).
 *
 * Contract: KaliCart CONTRATTO identita'/push, REVISIONE 2 (2026-09-27).
 * - One Ed25519 key per installation (sodium, bundled with WordPress). The
 *   private key is stored in wp_options encrypted with a key derived from
 *   AUTH_KEY (domain-separated); if AUTH_KEY changes the key is treated as lost
 *   and a new one is proven (recovery).
 * - The key is bound to the domain ONLY by a proof that KaliCart Global reads
 *   on this site: register -> nonce -> the nonce is published in the Bridge's
 *   own /.well-known/kalicart-bridge.json and discovery -> prove (signed).
 * - Daily signed heartbeat (versions, consent, and at most a normalized fatal
 *   error code of this plugin - never messages or paths). Signed "leaving" on
 *   deactivation and uninstall, sent BEFORE the well-known files are removed.
 * - Only with the merchant's Federated Catalog consent, only on production
 *   environments, only for root installs. Nothing here can block the plugin,
 *   the catalog or the feed: every failure is recorded and retried later.
 * - Signature profile (RFC 9421): Ed25519, keyid = RFC 7638 thumbprint,
 *   covered ("@method" "@target-uri" "content-digest" "content-type"),
 *   created/expires, tag "kalicart-bridge-v1". The target URI is the canonical
 *   KALICART_BRIDGE_GLOBAL origin + path, whatever the transport.
 */
class KaliCart_Bridge_Identity {

	const OPTION      = 'kalicart_bridge_identity';
	const FATAL_OPT   = 'kalicart_bridge_identity_fatal';
	const CRON_HOOK   = 'kalicart_bridge_identity_tick';
	const TAG         = 'kalicart-bridge-v1';
	const LABEL       = 'kalicart-bridge-identity-v1';
	const HEARTBEAT_S = 20 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'tick' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		register_shutdown_function( [ __CLASS__, 'capture_fatal' ] );
	}

	// ── state ──────────────────────────────────────────────────────────────

	private static function state(): array {
		$s = get_option( self::OPTION, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function save( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	/** Why identity is not active here, or '' when it can run. */
	public static function unavailable_reason(): string {
		if ( ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return 'no_consent';
		}
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return 'no_sodium';
		}
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return 'not_production';
		}
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== trim( $path, '/' ) ) {
			return 'subdirectory_install';
		}
		if ( '' === self::host() ) {
			return 'no_host';
		}
		return '';
	}

	public static function host(): string {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	// ── keys ───────────────────────────────────────────────────────────────

	private static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function box_key(): string {
		$secret = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		return sodium_crypto_generichash( $secret, sodium_crypto_generichash( self::LABEL, '', 32 ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function fingerprint( string $x ): string {
		return self::b64url( hash( 'sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}', true ) );
	}

	/** Secret key, or null when missing or no longer decryptable (AUTH_KEY changed). */
	private static function secret_key( array $s ): ?string {
		if ( empty( $s['sealed'] ) ) {
			return null;
		}
		$raw = base64_decode( (string) $s['sealed'], true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$sk    = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::box_key() );
		return ( false === $sk || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $sk ) ) ? null : $sk;
	}

	/** Makes sure a usable key exists for this exact home URL; returns [state, secret key]. */
	private static function ensure_key(): array {
		$s    = self::state();
		$home = home_url( '/' );
		$sk   = self::secret_key( $s );
		if ( $sk && ( $s['home_url'] ?? '' ) === $home && ! empty( $s['installation_id'] ) ) {
			return [ $s, $sk ];
		}
		$pair  = sodium_crypto_sign_keypair();
		$sk    = sodium_crypto_sign_secretkey( $pair );
		$x     = self::b64url( sodium_crypto_sign_publickey( $pair ) );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		// A different home URL means a clone or a move: a new installation. A lost
		// key on the same site keeps the installation id and proves again.
		$same_site = ( $s['home_url'] ?? '' ) === $home && ! empty( $s['installation_id'] );
		$s = [
			'installation_id' => $same_site ? $s['installation_id'] : wp_generate_uuid4(),
			'public_key'      => $x,
			'fingerprint'     => self::fingerprint( $x ),
			'sealed'          => base64_encode( $nonce . sodium_crypto_secretbox( $sk, $nonce, self::box_key() ) ),
			'home_url'        => $home,
			'status'          => 'new',
			'key_created_at'  => time(),
			'recovered'       => ! empty( $s['fingerprint'] ),
		];
		self::save( $s );
		return [ $s, $sk ];
	}

	/**
	 * 1.0.139: KaliCart Global's clock as learned from its signed responses
	 * (server_time). Returns [unix time, known]. When the offset was never learned
	 * the local clock is returned with known=false (collaudo ChatGPT 06:35).
	 */
	public static function server_now(): array {
		$s = self::state();
		$known = array_key_exists( 'clock_offset', $s );
		return [ time() + (int) ( $s['clock_offset'] ?? 0 ), $known ];
	}

	/**
	 * 1.0.139: detached Ed25519 signature with the EXISTING installation key
	 * (never creates one). Used for the catalog snapshot manifest. Null when
	 * there is no usable key yet.
	 */
	public static function sign_detached( string $message ): ?array {
		$s  = self::state();
		$sk = self::secret_key( $s );
		if ( ! $sk || empty( $s['fingerprint'] ) || empty( $s['installation_id'] ) || ( $s['home_url'] ?? '' ) !== home_url( '/' ) ) {
			return null;
		}
		return [
			'sig'             => self::b64url( sodium_crypto_sign_detached( $message, $sk ) ),
			'key_fingerprint' => (string) $s['fingerprint'],
			'installation_id' => (string) $s['installation_id'],
			'host'            => self::host(),
		];
	}

	// ── signed requests ────────────────────────────────────────────────────

	private static function now( array $s ): int {
		return time() + (int) ( $s['clock_offset'] ?? 0 );
	}

	/**
	 * POST a signed JSON body. Returns [http code, decoded body|null, error|null].
	 * The target URI is always the canonical Global origin; the transport may be
	 * overridden (tests) with KALICART_BRIDGE_IDENTITY_TRANSPORT.
	 */
	private static function post( string $path, array $body, array $s, string $sk, int $timeout = 15 ): array {
		$body = [ 'request_id' => wp_generate_uuid4() ] + $body;
		$raw  = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$digest  = 'sha-256=:' . base64_encode( hash( 'sha256', $raw, true ) ) . ':';
		$created = self::now( $s );
		$params  = '("@method" "@target-uri" "content-digest" "content-type");created=' . $created . ';expires=' . ( $created + 60 )
			. ';keyid="' . $s['fingerprint'] . '";alg="ed25519";tag="' . self::TAG . '"';
		$ctype = 'application/json';
		$base  = implode( "\n", [
			'"@method": POST',
			'"@target-uri": ' . KALICART_BRIDGE_GLOBAL . $path,
			'"content-digest": ' . $digest,
			'"content-type": ' . $ctype,
			'"@signature-params": ' . $params,
		] );
		$sig = base64_encode( sodium_crypto_sign_detached( $base, $sk ) );
		$transport = defined( 'KALICART_BRIDGE_IDENTITY_TRANSPORT' ) ? rtrim( (string) KALICART_BRIDGE_IDENTITY_TRANSPORT, '/' ) : KALICART_BRIDGE_GLOBAL;
		$resp = wp_remote_post( $transport . $path, [
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => [
				'Content-Type'    => $ctype,
				'Content-Digest'  => $digest,
				'Signature-Input' => 'sig1=' . $params,
				'Signature'       => 'sig1=:' . $sig . ':',
			],
			'body'        => $raw,
		] );
		if ( is_wp_error( $resp ) ) {
			return [ 0, null, $resp->get_error_code() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$data = is_array( $data ) ? $data : null;
		if ( $data && isset( $data['server_time'] ) && is_numeric( $data['server_time'] ) ) {
			$s2 = self::state();
			$s2['clock_offset'] = (int) $data['server_time'] - time();
			self::save( $s2 );
		}
		return [ $code, $data, ( $code >= 200 && $code < 300 ) ? null : ( $data['error'] ?? 'http_' . $code ) ];
	}

	/**
	 * Reusable signed POST for subsystems that already have an explicit Global
	 * delivery contract. It never creates or rotates identity material: until the
	 * installation is verified the caller can retain its existing fallback.
	 *
	 * @return array{0:int,1:?array,2:?string}
	 */
	public static function post_signed( string $path, array $body, int $timeout = 15 ): array {
		$reason = self::unavailable_reason();
		if ( '' !== $reason ) {
			return [ 0, null, 'identity_unavailable_' . $reason ];
		}
		$s  = self::state();
		$sk = self::secret_key( $s );
		if ( ! $sk || 'verified' !== ( $s['status'] ?? '' ) || empty( $s['installation_id'] ) ) {
			return [ 0, null, 'identity_not_verified' ];
		}
		return self::post( $path, [
			'host'            => self::host(),
			'installation_id' => (string) $s['installation_id'],
		] + $body, $s, $sk, $timeout );
	}

	// ── lifecycle ──────────────────────────────────────────────────────────

	/** Daily job (and right after consent): prove if needed, then heartbeat. Never throws. */
	public static function tick(): void {
		try {
			if ( '' !== self::unavailable_reason() ) {
				return;
			}
			[ $s, $sk ] = self::ensure_key();
			if ( 'verified' !== ( $s['status'] ?? '' ) ) {
				self::prove_now( $s, $sk );
				return;
			}
			if ( time() - (int) ( $s['last_heartbeat_at'] ?? 0 ) >= self::HEARTBEAT_S ) {
				self::heartbeat( $s, $sk );
			}
		} catch ( \Throwable $e ) {
			$s = self::state();
			$s['last_error'] = 'exception';
			self::save( $s );
		}
	}

	private static function record( array $patch ): array {
		$s = array_merge( self::state(), $patch );
		self::save( $s );
		return $s;
	}

	private static function prove_now( array $s, string $sk ): void {
		$host = self::host();
		[ $code, $data, $err ] = self::post( '/v1/bridge/identity/register', [
			'host'            => $host,
			'installation_id' => $s['installation_id'],
			'public_key'      => $s['public_key'],
			'plugin_version'  => KALICART_BRIDGE_VERSION,
		], $s, $sk );
		if ( $err || empty( $data['nonce'] ) ) {
			self::record( [ 'last_attempt_at' => time(), 'last_outcome' => 'register_failed', 'last_error' => (string) $err ] );
			return;
		}
		// Publish the nonce where Global reads it, then ask Global to read it.
		$s = self::record( [ 'nonce' => (string) $data['nonce'], 'nonce_ts' => (int) round( microtime( true ) * 1000 ) ] );
		if ( get_option( 'kalicart_bridge_well_known_enabled', true ) && class_exists( 'KaliCart_Bridge_Signals' ) ) {
			KaliCart_Bridge_Signals::write_well_known_files();
		}
		[ $code, $data, $err ] = self::post( '/v1/bridge/identity/prove', [
			'host'            => $host,
			'installation_id' => $s['installation_id'],
			'nonce'           => $s['nonce'],
		], $s, $sk, 25 );
		$outcome = $err ? 'prove_failed' : (string) ( $data['outcome'] ?? 'unknown' );
		$patch   = [ 'last_attempt_at' => time(), 'last_outcome' => $outcome, 'last_error' => (string) $err, 'last_reason' => (string) ( $data['reason'] ?? '' ) ];
		if ( 'verified' === $outcome ) {
			$patch['status']      = 'verified';
			$patch['verified_at'] = time();
			$patch['proof_level'] = (string) ( $data['proof_level'] ?? '' );
		}
		$s = self::record( $patch );
		if ( 'verified' === $outcome ) {
			self::heartbeat( $s, $sk );
		}
	}

	private static function heartbeat( array $s, string $sk, ?bool $consent = null ): void {
		$fatal = get_option( self::FATAL_OPT, null );
		[ $code, $data, $err ] = self::post( '/v1/bridge/identity/heartbeat', [
			'host'            => self::host(),
			'installation_id' => $s['installation_id'],
			'consent'         => null === $consent ? (bool) get_option( 'kalicart_bridge_global_consent', false ) : $consent,
			'plugin_version'  => KALICART_BRIDGE_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'fatal'           => is_array( $fatal ) ? $fatal : null,
			// 1.0.139: which consent text is in force (federated-catalog-1.1 or
			// federated-catalog-1-legacy). A label only: the receipt stays on the site.
			'consent_version' => class_exists( 'KaliCart_Bridge_Federation_Consent' ) ? KaliCart_Bridge_Federation_Consent::version() : '',
		] + ( class_exists( 'KaliCart_Bridge_Snapshot' ) ? KaliCart_Bridge_Snapshot::heartbeat_fields() : [] ), $s, $sk, 10 );
		if ( ! $err ) {
			delete_option( self::FATAL_OPT );
			// 1.0.139: KaliCart Global's decision (decision_v1). Stored as data only:
			// shown in Site Health and the panel, never turned into a request here.
			$decision = ( is_array( $data ) && isset( $data['decision'] ) && class_exists( 'KaliCart_Bridge_Site_Health' ) )
				? KaliCart_Bridge_Site_Health::validate_decision( $data['decision'] ) // known fields only, action always null
				: null;
			self::record( [ 'last_heartbeat_at' => time(), 'last_error' => '', 'decision' => $decision ] );
		} elseif ( 'not_verified' === $err || 'keyid_mismatch' === $err ) {
			// Global no longer recognises this key for the domain: prove again.
			self::record( [ 'status' => 'new', 'last_error' => (string) $err ] );
		} else {
			self::record( [ 'last_error' => (string) $err ] );
		}
	}

	/** Consent revoked in the panel: tell Global now (best effort, short timeout). */
	public static function consent_revoked(): void {
		try {
			$s  = self::state();
			$sk = self::secret_key( $s );
			if ( $sk && 'verified' === ( $s['status'] ?? '' ) ) {
				self::heartbeat( $s, $sk, false );
			}
		} catch ( \Throwable $e ) { // never block the revoke
			return;
		}
	}

	/** Deactivation / uninstall: signed "leaving", sent BEFORE files are removed. Best effort. */
	public static function leaving( string $reason ): void {
		try {
			$s  = self::state();
			$sk = self::secret_key( $s );
			if ( ! $sk || 'verified' !== ( $s['status'] ?? '' ) ) {
				return;
			}
			self::post( '/v1/bridge/identity/leaving', [
				'host'            => self::host(),
				'installation_id' => $s['installation_id'],
				'reason'          => in_array( $reason, [ 'deactivated', 'uninstalled' ], true ) ? $reason : 'deactivated',
			], $s, $sk, 5 );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Block published in the Bridge's discovery and /.well-known mirrors, or null. */
	public static function discovery_block(): ?array {
		$s = self::state();
		if ( empty( $s['fingerprint'] ) || empty( $s['nonce'] ) || ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return null;
		}
		return [
			'v'               => 1,
			'host'            => self::host(),
			'installation_id' => (string) $s['installation_id'],
			'key_fingerprint' => (string) $s['fingerprint'],
			'nonce'           => (string) $s['nonce'],
			'ts'              => (int) ( $s['nonce_ts'] ?? 0 ),
		];
	}

	/** Summary for the admin panel (no secrets). */
	public static function summary(): array {
		$s = self::state();
		return [
			'unavailable'  => self::unavailable_reason(),
			'status'       => (string) ( $s['status'] ?? '' ),
			'last_outcome' => (string) ( $s['last_outcome'] ?? '' ),
			'verified_at'  => (int) ( $s['verified_at'] ?? 0 ),
			'heartbeat_at' => (int) ( $s['last_heartbeat_at'] ?? 0 ),
			'fingerprint'  => (string) ( $s['fingerprint'] ?? '' ),
			'decision'     => is_array( $s['decision'] ?? null ) ? $s['decision'] : null,
		];
	}

	/**
	 * Records a fatal error raised inside this plugin, normalized: code,
	 * component, time and a short non-reversible hash of file:line. Never the
	 * message, never a path.
	 */
	public static function capture_fatal(): void {
		$e = error_get_last();
		if ( ! $e || ! in_array( (int) $e['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
			return;
		}
		if ( 0 !== strpos( (string) $e['file'], KALICART_BRIDGE_DIR ) ) {
			return;
		}
		$code = E_PARSE === (int) $e['type'] ? 'php_parse' : ( E_COMPILE_ERROR === (int) $e['type'] ? 'php_compile' : 'php_fatal' );
		update_option( self::FATAL_OPT, [
			'code'      => $code,
			'component' => 'kalicart-bridge',
			'ts'        => time(),
			'hash'      => substr( hash( 'sha256', basename( (string) $e['file'] ) . ':' . (int) $e['line'] . ':' . KALICART_BRIDGE_VERSION ), 0, 16 ),
		], false );
	}
}
