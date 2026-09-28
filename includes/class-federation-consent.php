<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Federation_Consent — local, append-only evidence of the
 * Federated Catalog consent (consent A), 1.0.139.
 *
 * Contract: CONTRATTO-1.0.139-SNAPSHOT §9, §14.6.
 * - Text federated-catalog-1.1 applies to NEW activations. Each activation and
 *   revocation appends one record: text id, action, UTC time, WordPress user,
 *   locale, SHA-256 of the canonical English text and of the localized text the
 *   user saw, plugin version, previous record hash and own record hash (chain).
 * - Stores activated before 1.0.139 kept only a boolean and a date: they stay on
 *   "federated-catalog-1 (legacy)". That status is COMPUTED (consent on, no
 *   record); no receipt is ever fabricated for them.
 * - Nothing here is sent anywhere. KaliCart Global learns the consent version
 *   from the signed heartbeat (consent_version), as a label, not the record.
 */
class KaliCart_Bridge_Federation_Consent {

	const OPTION       = 'kalicart_bridge_federation_consent_log';
	const TEXT_ID      = 'federated-catalog-1.1';
	const LEGACY_ID    = 'federated-catalog-1-legacy';

	/** Canonical, native-English text whose SHA-256 is recorded with every act. */
	const CANONICAL_TEXT = 'By activating the Federated Catalog, I authorize KaliCart Global to read this store\'s public product catalog and include it in the KaliCart Federated Catalog, where AI assistants can find these products. The catalog includes product names, descriptions, categories, prices, availability, variants, image URLs and product links, as shown to any visitor of this store. KaliCart Global reads it from this site\'s public catalog API and from public files that this plugin creates on this site (in /.well-known/kalicart/, or in wp-content/uploads/kalicart/ when that folder cannot be written); the files do not contain exact stock quantities, are updated when products change, and are deleted when I revoke this consent or deactivate or uninstall the plugin. To prove that the catalog comes from this store, the plugin creates a signing key for this installation and sends KaliCart Global a signed signal about once a day (site address, a random identifier of this installation, consent state and consent text version, plugin, WordPress and PHP versions, catalog file status, whether the store is in coming-soon or maintenance mode and, only after a plugin error, a short error code with its time and a short hash; never error messages, file paths or stack traces). No customer, order, payment or account data is shared. I can revoke this consent at any time from this page.';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'privacy_policy_content' ] );
	}

	/** Suggested text for Settings -> Privacy (WordPress privacy policy guide). */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$p = [
			__( 'If the store owner activates the KaliCart Federated Catalog, KaliCart Global (dashboard.kalicart.com) reads this store\'s public product catalog, from its public catalog API and from public catalog files written on this site, and includes it in a catalog where AI assistants can find the products.', 'kalicart-bridge' ),
			__( 'The catalog contains only public product data (names, descriptions, categories, prices, availability, variants, image URLs and product links). No data about customers, visitors, orders or payments is sent.', 'kalicart-bridge' ),
			__( 'About once a day the plugin sends KaliCart Global a signed technical status of this installation (site address, a random installation identifier, consent state and versions). It contains no personal data of customers or visitors.', 'kalicart-bridge' ),
			__( 'Public catalog files: while the Federated Catalog is active, the plugin writes the public catalog as static files in /.well-known/kalicart/ on this site, or in wp-content/uploads/kalicart/ when that folder cannot be written. They contain the same public product data, never exact stock quantities. They are updated when products are edited and once a day, and deleted when the Federated Catalog is revoked or the plugin is deactivated or deleted from WordPress. Like the product pages, anyone can read them; KaliCart Global reads them. If the plugin folder is removed outside WordPress, the files remain in those folders, and KaliCart Global does not use them without a current signed status from this site.', 'kalicart-bridge' ),
			__( 'Retention at KaliCart Global: the current state is kept while the store takes part; events and reading reports for 90 days, then only anonymous daily totals without site address, URL or IP address; server logs containing IP addresses for 30 days. 30 days after revocation or removal, the catalog, its copies, the events and the current identity state are deleted. Only a minimal revocation record (site address and revocation date, so that the store is not listed again by mistake) and the history of this installation\'s signing keys (public keys and verification dates, kept as a security record) remain. The consent receipt stays in this site\'s WordPress database until the plugin is deleted and is not sent to KaliCart Global.', 'kalicart-bridge' ),
		];
		$html = '';
		foreach ( $p as $x ) {
			$html .= '<p>' . esc_html( $x ) . '</p>';
		}
		$html .= '<p><a href="https://bridge.kalicart.com/privacy/">https://bridge.kalicart.com/privacy/</a></p>';
		wp_add_privacy_policy_content( 'KaliCart Bridge', wp_kses_post( $html ) );
	}

	/** The same text, translated. Must be the text shown next to the activation button. */
	public static function localized_text(): string {
		return __( 'By activating the Federated Catalog, I authorize KaliCart Global to read this store\'s public product catalog and include it in the KaliCart Federated Catalog, where AI assistants can find these products. The catalog includes product names, descriptions, categories, prices, availability, variants, image URLs and product links, as shown to any visitor of this store. KaliCart Global reads it from this site\'s public catalog API and from public files that this plugin creates on this site (in /.well-known/kalicart/, or in wp-content/uploads/kalicart/ when that folder cannot be written); the files do not contain exact stock quantities, are updated when products change, and are deleted when I revoke this consent or deactivate or uninstall the plugin. To prove that the catalog comes from this store, the plugin creates a signing key for this installation and sends KaliCart Global a signed signal about once a day (site address, a random identifier of this installation, consent state and consent text version, plugin, WordPress and PHP versions, catalog file status, whether the store is in coming-soon or maintenance mode and, only after a plugin error, a short error code with its time and a short hash; never error messages, file paths or stack traces). No customer, order, payment or account data is shared. I can revoke this consent at any time from this page.', 'kalicart-bridge' );
	}

	/** @return array<int,array<string,mixed>> */
	public static function records(): array {
		$log = get_option( self::OPTION, [] );
		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : [];
	}

	/** Last record, or null. */
	public static function last(): ?array {
		$r = self::records();
		return $r ? $r[ count( $r ) - 1 ] : null;
	}

	/**
	 * Consent version currently in force, for the signed heartbeat and the panel:
	 * federated-catalog-1.1 (activated with this text), federated-catalog-1-legacy
	 * (activated before 1.0.139, no record) or '' (no consent).
	 */
	public static function version(): string {
		if ( ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return '';
		}
		$last = self::last();
		if ( $last && 'granted' === ( $last['action'] ?? '' ) && ! empty( $last['text_id'] ) ) {
			return (string) $last['text_id'];
		}
		return self::LEGACY_ID;
	}

	/** Deterministic hash of a record's immutable fields (sorted keys, JSON). */
	private static function record_hash( array $immutable ): string {
		ksort( $immutable );
		return hash( 'sha256', (string) wp_json_encode( $immutable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Appends one act. Idempotent: granting while the last record is already a grant
	 * of the same text (or revoking while revoked) appends nothing.
	 *
	 * @return array<string,mixed> the record in force
	 */
	public static function record( string $action, int $user_id ): array {
		if ( ! in_array( $action, [ 'granted', 'revoked' ], true ) ) {
			throw new InvalidArgumentException( 'unsupported_action' );
		}
		$records = self::records();
		$last    = $records ? $records[ count( $records ) - 1 ] : null;
		if ( $last && ( $last['action'] ?? '' ) === $action && ( 'revoked' === $action || ( $last['text_id'] ?? '' ) === self::TEXT_ID ) ) {
			return $last;
		}
		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale( $user_id ) : (string) get_locale();
		$text   = self::localized_text();
		$immutable = [
			'text_id'             => self::TEXT_ID,
			'action'              => $action,
			'occurred_at_utc'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'wp_user_id'          => $user_id,
			'locale'              => substr( $locale, 0, 20 ),
			'canonical_text_hash' => hash( 'sha256', self::CANONICAL_TEXT ),
			'consent_text_hash'   => hash( 'sha256', $text ),
			'plugin_version'      => KALICART_BRIDGE_VERSION,
			'previous_record_hash' => $last['record_hash'] ?? null,
		];
		$record = $immutable + [ 'record_hash' => self::record_hash( $immutable ) ];
		$records[] = $record;
		// Full chain, never truncated: one record per real state change (record() is
		// idempotent), so the log stays small. Removed only on uninstall.
		update_option( self::OPTION, $records, false );
		return $record;
	}

	/** true when every record's hash and chain link verify. */
	public static function verify_chain(): bool {
		$prev = null;
		foreach ( self::records() as $k => $r ) {
			$stored = (string) ( $r['record_hash'] ?? '' );
			$imm    = $r;
			unset( $imm['record_hash'] );
			if ( self::record_hash( $imm ) !== $stored ) {
				return false;
			}
			// The first record must open the chain: a removed prefix is detected too.
			if ( ( $r['previous_record_hash'] ?? null ) !== $prev ) {
				return false;
			}
			$prev = $stored;
		}
		return true;
	}

	/** Panel/Site Health view: version, when, by whom (user id only). */
	public static function summary(): array {
		$last = self::last();
		return [
			'version'     => self::version(),
			'legacy'      => self::LEGACY_ID === self::version(),
			'last_action' => $last['action'] ?? null,
			'last_at'     => $last['occurred_at_utc'] ?? null,
			'records'     => count( self::records() ),
			'chain_ok'    => self::verify_chain(),
		];
	}
}

