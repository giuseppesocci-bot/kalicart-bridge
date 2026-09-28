<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Site_Health — 1.0.139 (contract §7, §10).
 *
 * - validate_decision(): the decision_v1 block from KaliCart Global's heartbeat
 *   answer is DATA. Only known fields with allowed values are kept; `action` is
 *   forced to null (1.0.139: never a request); an unknown reason_code is kept as
 *   a label and marked "not recognized", never turned into a call to action.
 * - Three Site Health tests, `recommended` at most, never `critical`, only while
 *   the Federated Catalog is active. Every text states the measured fact and what
 *   happens by itself. None asks the merchant or the hosting to change any rule or
 *   configuration (project rule).
 */
class KaliCart_Bridge_Site_Health {

	const HEARTBEAT_LATE_S = 3 * DAY_IN_SECONDS;

	const OUTCOMES   = [ 'served', 'degraded', 'not_served' ];
	const TRANSPORTS = [ 'rest', 'snapshot', 'none' ];
	const OWNERS     = [ 'merchant', 'hosting', 'kalicart' ];
	const REASONS    = [
		'consent_absent', 'plugin_inactive', 'identity_domain_mismatch', 'environment_clone_suspected',
		'environment_nonproduction', 'identity_pending', 'identity_failed', 'identity_recovery_pending',
		'heartbeat_stale', 'heartbeat_absent', 'schema_unsupported', 'capability_missing',
		'disallowed_by_robots', 'store_coming_soon', 'site_maintenance', 'site_unreachable',
		'global_import_error', 'ok_rest', 'ok_snapshot', 'catalog_empty', 'blocked_by_bot_challenge',
		'snapshot_missing', 'snapshot_invalid_signature', 'snapshot_expired', 'snapshot_stale_cache',
		'snapshot_hash_mismatch', 'snapshot_partial', 'snapshot_storage_failure',
		'snapshot_seq_conflict', 'snapshot_older_than_current', 'snapshot_overdue',
	];

	public static function init(): void {
		add_filter( 'site_status_tests', [ __CLASS__, 'register' ] );
	}

	/**
	 * Keeps only the contract fields of decision_v1 with allowed values.
	 * @return array<string,mixed>|null
	 */
	public static function validate_decision( $d ): ?array {
		if ( ! is_array( $d ) || 'decision_v1' !== ( $d['version'] ?? null ) ) {
			return null;
		}
		$reason = is_string( $d['reason_code'] ?? null ) && preg_match( '/^[a-z0-9_]{1,48}$/', $d['reason_code'] ) ? $d['reason_code'] : null;
		if ( null === $reason ) {
			return null;
		}
		$measured = is_string( $d['measured_at'] ?? null ) && strlen( $d['measured_at'] ) <= 40 ? $d['measured_at'] : null;
		$ts       = $measured ? strtotime( $measured ) : false;
		return [
			'version'     => 'decision_v1',
			'outcome'     => in_array( $d['outcome'] ?? null, self::OUTCOMES, true ) ? $d['outcome'] : 'unknown',
			'transport'   => in_array( $d['transport'] ?? null, self::TRANSPORTS, true ) ? $d['transport'] : 'unknown',
			'reason_code' => $reason,
			'known'       => in_array( $reason, self::REASONS, true ),
			'owner'       => in_array( $d['owner'] ?? null, self::OWNERS, true ) ? $d['owner'] : 'unknown',
			'action'      => null, // 1.0.139: never a request, whatever arrives
			'measured_at' => false !== $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null,
		];
	}

	public static function register( array $tests ): array {
		if ( ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return $tests; // not part of the Federated Catalog: nothing to report
		}
		$tests['direct']['kalicart_bridge_heartbeat'] = [ 'label' => __( 'KaliCart Bridge status delivery', 'kalicart-bridge' ), 'test' => [ __CLASS__, 'test_heartbeat' ] ];
		$tests['direct']['kalicart_bridge_snapshot']  = [ 'label' => __( 'KaliCart Bridge catalog files', 'kalicart-bridge' ), 'test' => [ __CLASS__, 'test_snapshot' ] ];
		$tests['direct']['kalicart_bridge_decision']  = [ 'label' => __( 'KaliCart Global catalog reading', 'kalicart-bridge' ), 'test' => [ __CLASS__, 'test_decision' ] ];
		return $tests;
	}

	private static function result( string $test, string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => 'recommended' === $status ? 'recommended' : 'good', // never critical
			'badge'       => [ 'label' => 'KaliCart Bridge', 'color' => 'blue' ],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => $test,
		];
	}

	/** Signed status (heartbeat) not delivered to KaliCart Global for more than 3 days. */
	public static function test_heartbeat(): array {
		$t = 'kalicart_bridge_heartbeat';
		if ( ! class_exists( 'KaliCart_Bridge_Identity' ) ) {
			return self::result( $t, 'good', __( 'KaliCart Bridge status delivery is not active on this site', 'kalicart-bridge' ), __( 'This site does not use the signed status of this version.', 'kalicart-bridge' ) );
		}
		$s = KaliCart_Bridge_Identity::summary();
		if ( '' !== $s['unavailable'] || 'verified' !== $s['status'] ) {
			return self::result( $t, 'good', __( 'KaliCart Bridge status delivery starts after verification', 'kalicart-bridge' ), __( 'KaliCart Global verifies this installation automatically; the signed status is sent after that.', 'kalicart-bridge' ) );
		}
		$age = time() - (int) $s['heartbeat_at'];
		if ( (int) $s['heartbeat_at'] > 0 && $age <= self::HEARTBEAT_LATE_S ) {
			return self::result( $t, 'good', __( 'KaliCart Global receives this store\'s status', 'kalicart-bridge' ), __( 'The plugin sends a signed status to KaliCart Global about once a day.', 'kalicart-bridge' ) );
		}
		$days = (int) $s['heartbeat_at'] > 0 ? (int) floor( $age / DAY_IN_SECONDS ) : 0;
		return self::result( $t, 'recommended',
			$days > 0
				/* translators: %d: number of days */
				? sprintf( _n( 'KaliCart Global has not received this store\'s status for %d day', 'KaliCart Global has not received this store\'s status for %d days', $days, 'kalicart-bridge' ), $days )
				: __( 'KaliCart Global has not received this store\'s status yet', 'kalicart-bridge' ),
			__( 'The plugin sends a signed status about once a day through WP-Cron, which runs when the site receives visits. It retries automatically. After 7 days without it, KaliCart Global pauses this store in the Federated Catalog until the next status arrives.', 'kalicart-bridge' ) );
	}

	/** Local state of the catalog files written by the plugin. */
	public static function test_snapshot(): array {
		$t = 'kalicart_bridge_snapshot';
		$state = class_exists( 'KaliCart_Bridge_Snapshot' ) ? KaliCart_Bridge_Snapshot::current_state() : 'disabled';
		switch ( $state ) {
			case 'storage_failure':
				return self::result( $t, 'recommended', __( 'The plugin cannot write the catalog files', 'kalicart-bridge' ),
					__( 'The last attempt found not enough free disk space or a folder that cannot be written. The store and its public catalog API keep working; the plugin retries automatically.', 'kalicart-bridge' ) );
			case 'partial':
				return self::result( $t, 'recommended', __( 'The catalog files cover part of the catalog', 'kalicart-bridge' ),
					__( 'The last generation did not finish within its time limits, so the published files are marked as incomplete. The plugin continues automatically on its next runs.', 'kalicart-bridge' ) );
			case 'overdue':
				return self::result( $t, 'recommended', __( 'The catalog files have not been regenerated recently', 'kalicart-bridge' ),
					__( 'The files are regenerated by WP-Cron, which runs when the site receives visits. They are regenerated automatically on the next run.', 'kalicart-bridge' ) );
			case 'ok':
				return self::result( $t, 'good', __( 'The catalog files are up to date', 'kalicart-bridge' ), __( 'The plugin publishes the catalog files and updates them whenever products are edited.', 'kalicart-bridge' ) );
			default:
				return self::result( $t, 'good', __( 'The catalog files are not published yet', 'kalicart-bridge' ), __( 'The plugin creates them automatically after the installation is verified.', 'kalicart-bridge' ) );
		}
	}

	/** What KaliCart Global measured, as stated in its last answer (decision_v1). */
	public static function test_decision(): array {
		$t = 'kalicart_bridge_decision';
		$d = class_exists( 'KaliCart_Bridge_Identity' ) ? self::validate_decision( KaliCart_Bridge_Identity::summary()['decision'] ?? null ) : null;
		if ( ! $d ) {
			return self::result( $t, 'good', __( 'No reading report from KaliCart Global yet', 'kalicart-bridge' ), __( 'KaliCart Global reports how it reads the catalog in its answer to the signed status.', 'kalicart-bridge' ) );
		}
		if ( ! $d['known'] ) {
			/* translators: %s: status code reported by KaliCart Global */
			return self::result( $t, 'good', __( 'KaliCart Global reported a status this plugin version does not recognize', 'kalicart-bridge' ), sprintf( __( 'Status code: %s. No action is requested by this report.', 'kalicart-bridge' ), $d['reason_code'] ) );
		}
		if ( 'served' === $d['outcome'] || ! in_array( $d['owner'], [ 'merchant', 'hosting' ], true ) ) {
			return self::result( $t, 'good', __( 'KaliCart Global reads this store\'s catalog', 'kalicart-bridge' ), __( 'No action is needed on this site.', 'kalicart-bridge' ) );
		}
		$text = self::reason_text( $d['reason_code'] );
		if ( ! $text ) {
			return self::result( $t, 'good', __( 'KaliCart Global reads this store\'s catalog', 'kalicart-bridge' ), __( 'No action is needed on this site.', 'kalicart-bridge' ) );
		}
		return self::result( $t, 'recommended', $text[0], $text[1] );
	}

	/** Measured fact + what happens by itself. Never a configuration request. */
	private static function reason_text( string $reason ): ?array {
		$stale = __( 'The store keeps working; in the Federated Catalog its prices and availability may be out of date.', 'kalicart-bridge' );
		switch ( $reason ) {
			case 'blocked_by_bot_challenge':
				return [ __( 'A protection service answers KaliCart Global with an anti-bot challenge', 'kalicart-bridge' ),
					__( 'The service that protects this site answered KaliCart Global\'s catalog requests with a challenge.', 'kalicart-bridge' ) . ' ' . $stale . ' ' . __( 'KaliCart Global retries automatically.', 'kalicart-bridge' ) ];
			case 'site_unreachable':
				return [ __( 'KaliCart Global could not read this store', 'kalicart-bridge' ),
					__( 'In its last checks the site did not answer or returned an error.', 'kalicart-bridge' ) . ' ' . $stale . ' ' . __( 'KaliCart Global retries automatically.', 'kalicart-bridge' ) ];
			case 'disallowed_by_robots':
				return [ __( 'This site\'s robots.txt excludes KaliCart Global', 'kalicart-bridge' ),
					__( 'KaliCart Global respects robots.txt and does not read the paths it disallows, so this catalog is not read. This follows the site\'s own robots rules.', 'kalicart-bridge' ) ];
			case 'store_coming_soon':
				return [ __( 'The store is not public yet', 'kalicart-bridge' ),
					__( 'WooCommerce reports the store in "coming soon" mode, so KaliCart Global does not show its products. They appear again automatically when the store is public.', 'kalicart-bridge' ) ];
			case 'site_maintenance':
				return [ __( 'The site is in maintenance mode', 'kalicart-bridge' ),
					__( 'WordPress reports maintenance mode, so KaliCart Global does not show this store\'s products for now. They appear again automatically after maintenance.', 'kalicart-bridge' ) ];
			case 'snapshot_missing':
				return [ __( 'KaliCart Global did not find the catalog files', 'kalicart-bridge' ),
					__( 'The public catalog files written by this plugin were not found when KaliCart Global read them. The plugin regenerates them automatically.', 'kalicart-bridge' ) ];
			case 'snapshot_expired':
			case 'snapshot_overdue':
				return [ __( 'KaliCart Global found old catalog files', 'kalicart-bridge' ),
					__( 'The catalog files read by KaliCart Global have not been regenerated recently. WP-Cron regenerates them automatically when the site receives visits.', 'kalicart-bridge' ) ];
			case 'snapshot_stale_cache':
			case 'snapshot_hash_mismatch':
				return [ __( 'KaliCart Global received an old or altered copy of the catalog files', 'kalicart-bridge' ),
					__( 'A copy of the catalog files did not match the one this plugin published, possibly served by a cache. KaliCart Global ignores such copies and reads again later.', 'kalicart-bridge' ) ];
			case 'snapshot_partial':
				return [ __( 'KaliCart Global read incomplete catalog files', 'kalicart-bridge' ),
					__( 'The published files cover part of the catalog; nothing is removed from the Federated Catalog because of them. The plugin completes them automatically.', 'kalicart-bridge' ) ];
			case 'snapshot_storage_failure':
				return [ __( 'The plugin cannot write the catalog files', 'kalicart-bridge' ),
					__( 'The site reported not enough free disk space or a folder that cannot be written. The store and its public catalog API keep working; the plugin retries automatically.', 'kalicart-bridge' ) ];
			case 'identity_pending':
			case 'identity_failed':
			case 'identity_recovery_pending':
				return [ __( 'KaliCart Global has not verified this installation yet', 'kalicart-bridge' ),
					__( 'KaliCart Global verifies this installation by reading a public file on the site. It retries automatically.', 'kalicart-bridge' ) ];
			default:
				return null; // consent, exit, heartbeat and KaliCart-side codes: nothing to report here
		}
	}
}

