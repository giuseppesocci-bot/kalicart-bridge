<?php
/**
 * KaliCart Bridge 1.0.132 provider-consent verification.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/kalicart-bridge/tools/test-132-commerce-consent.php
 *
 * Network delivery is intercepted. Existing options are restored and only the
 * exact rows created by this run are removed.
 */

defined( 'ABSPATH' ) || exit( 1 );

// Optional standalone class path for testing a candidate before it replaces the
// currently installed plugin source. Normal packaged runs do not use this.
if ( ! class_exists( 'KaliCart_Bridge_Commerce_Consent' ) ) {
	$standalone_class = getenv( 'KALICART_BRIDGE_CONSENT_CLASS' );
	if ( is_string( $standalone_class ) && is_readable( $standalone_class ) ) {
		require_once $standalone_class;
	}
}
if ( ! class_exists( 'KaliCart_Bridge_Commerce_Consent' ) ) {
	exit( 1 );
}

$failures   = [];
$report     = [];
$created_ids = [];
$check = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$missing = new stdClass();
$option_names = [
	'kalicart_bridge_global_consent',
	'kalicart_bridge_federation_registered_at',
	KaliCart_Bridge_Commerce_Consent::OPTION,
];
$original = [];
foreach ( $option_names as $option_name ) {
	$original[ $option_name ] = get_option( $option_name, $missing );
}

$delivery_mode = 'network_error';
$payloads      = [];
$scheduled_retries = [];
$scheduled_at      = [];
$intercept = static function( $preempt, array $args, string $url ) use ( &$delivery_mode, &$payloads ) {
	if ( false === strpos( $url, '/v1/bridge/provider-consent' ) ) {
		return $preempt;
	}
	if ( false !== strpos( $url, '/status' ) ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [
				'ok'                  => true,
				'receipt_status'      => 'accepted',
				'authorization_state' => 'active',
				'delivery_state'      => 'not_configured',
			] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
	$payloads[] = json_decode( (string) ( $args['body'] ?? '' ), true );
	if ( 'network_error' === $delivery_mode ) {
		return new WP_Error( 'test_network_error', 'Synthetic transport failure.' );
	}
	return [
		'headers'  => [],
		'body'     => wp_json_encode( [
			'ok'                  => true,
			'receipt_status'      => 'accepted',
			'authorization_state' => 'active',
			'delivery_state'      => 'not_configured',
		] ),
		'response' => [ 'code' => 202, 'message' => 'Accepted' ],
		'cookies'  => [],
		'filename' => null,
	];
};
add_filter( 'pre_http_request', $intercept, 10, 3 );
$intercept_schedule = static function( $preempt, $event ) use ( &$scheduled_retries, &$scheduled_at ) {
	if ( is_object( $event ) && KaliCart_Bridge_Commerce_Consent::RETRY_HOOK === ( $event->hook ?? '' ) ) {
		$scheduled_retries[] = $event->args;
		$attempt = (int) ( $event->args[1] ?? 0 );
		$scheduled_at[ $attempt ][] = (int) ( $event->timestamp ?? 0 );
		return true;
	}
	return $preempt;
};
add_filter( 'pre_schedule_event', $intercept_schedule, 10, 2 );

global $wpdb;
$table = KaliCart_Bridge_Commerce_Consent::table_name();

try {
	$test_root = getenv( 'KALICART_BRIDGE_TEST_ROOT' );
	if ( ! is_string( $test_root ) || '' === $test_root ) {
		$test_root = KALICART_BRIDGE_DIR;
	}
	$admin_page = (string) file_get_contents( trailingslashit( $test_root ) . 'admin/admin-page.php' );
	$provider_position = strpos( $admin_page, 'id="providerConsentBlock"' );
	$tabs_position     = strpos( $admin_page, '<!-- TABS -->' );
	$check( false !== $provider_position && false !== $tabs_position && $provider_position < $tabs_position, 'Provider authorization is not always visible above the tabbed merchant-feed UI.' );
	$check( false !== strpos( $admin_page, 'id="providerConsentCheckbox" value="1">' ), 'Provider authorization checkbox is missing or preselected.' );
	$check( false === strpos( $admin_page, 'providerConsentEvidenceId' ), 'The dashboard still renders an empty consent ID field.' );
	$check( false !== strpos( $admin_page, 'class="kali-external-visibility__header"' ), 'External visibility is still rendered as an undifferentiated horizontal strip.' );
	$check( false !== strpos( $admin_page, '<details class="kali-external-visibility__help">' ), 'External visibility explanation is not available on demand.' );
	KaliCart_Bridge_Admin::enqueue_assets( 'toplevel_page_kalicart-bridge' );
	$style_version  = (string) ( wp_styles()->registered['kalicart-bridge-admin']->ver ?? '' );
	$script_version = (string) ( wp_scripts()->registered['kalicart-bridge-admin']->ver ?? '' );
	$check( 0 === strpos( $style_version, KALICART_BRIDGE_VERSION . '.' ) && KALICART_BRIDGE_VERSION !== $style_version, 'Admin CSS is still keyed only by the plugin version and can remain stale during a release candidate.' );
	$check( 0 === strpos( $script_version, KALICART_BRIDGE_VERSION . '.' ) && KALICART_BRIDGE_VERSION !== $script_version, 'Admin JavaScript is still keyed only by the plugin version and can remain stale during a release candidate.' );
	$mo_expectations = [
		'it_IT' => [ 'provider' => 'Canali di distribuzione federata', 'pending' => 'in attesa di consegna a KaliCart Global' ],
		'de_DE' => [ 'provider' => 'Föderierte Vertriebskanäle', 'pending' => 'Übermittlung an KaliCart Global ausstehend' ],
		'fr_FR' => [ 'provider' => 'Canaux de distribution fédérée', 'pending' => 'en attente de transmission à KaliCart Global' ],
		'es_ES' => [ 'provider' => 'Canales de distribución federada', 'pending' => 'en espera de entrega a KaliCart Global' ],
	];
	foreach ( $mo_expectations as $locale => $expected_translations ) {
		$mo = new MO();
		$loaded = $mo->import_from_file( trailingslashit( $test_root ) . 'languages/kalicart-bridge-' . $locale . '.mo' );
		$check( $loaded, $locale . ' MO file could not be loaded.' );
		$check( $expected_translations['provider'] === $mo->translate( 'Federated distribution channels' ), $locale . ' MO did not contain the provider-consent translation.' );
		$check( $expected_translations['pending'] === $mo->translate( 'awaiting delivery to KaliCart Global' ), $locale . ' MO did not contain the pending-delivery translation.' );
	}
	$probe_request = new WP_REST_Request( 'GET', '/kalicart/v1/discovery' );
	$probe_request->set_query_params( [ 'kalicart_consent_probe' => (string) round( microtime( true ) * 1000 ) ] );
	$probe_response = KaliCart_Bridge_API::discovery( $probe_request );
	$check( 200 === $probe_response->get_status(), 'Global receipt cache-busting parameter was rejected by /discovery.' );
	$invalid_probe = new WP_REST_Request( 'GET', '/kalicart/v1/discovery' );
	$invalid_probe->set_query_params( [ 'invented' => '1' ] );
	$check( 400 === KaliCart_Bridge_API::discovery( $invalid_probe )->get_status(), 'Discovery stopped rejecting unknown non-protocol query parameters.' );

	KaliCart_Bridge_Commerce_Consent::ensure_schema();
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact plugin-owned table.
	$check( in_array( 'retry_attempt', $columns, true ), 'The retry attempt migration column is missing.' );
	$check( in_array( 'next_retry_at_utc', $columns, true ), 'The next retry timestamp migration column is missing.' );
	delete_option( KaliCart_Bridge_Commerce_Consent::OPTION );
	update_option( 'kalicart_bridge_global_consent', false, false );
	delete_option( 'kalicart_bridge_federation_registered_at' );

	$initial = KaliCart_Bridge_Commerce_Consent::current();
	$check( false === $initial['authorized'], 'Upgrade/default state authorized OpenAI without an explicit merchant act.' );
	$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact plugin-owned table.
	$blocked = false;
	try {
		KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'granted', get_current_user_id() );
	} catch ( RuntimeException $error ) {
		$blocked = 'global_not_active' === $error->getMessage();
	}
	$check( $blocked, 'Provider grant was not rejected while the Federated Catalog prerequisite was absent.' );
	$check( $count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'A refused grant wrote a ledger row.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact plugin-owned table.

	update_option( 'kalicart_bridge_global_consent', true, false );
	update_option( 'kalicart_bridge_federation_registered_at', gmdate( 'c' ), false );
	$granted = KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'granted', get_current_user_id() );
	$created_ids[] = $granted['consent_id'];
	$check( true === $granted['authorized'], 'Explicit grant did not set the provider authorization locally.' );
	$check( 'pending' === $granted['receipt_status'], 'Transport failure did not leave a retryable pending receipt.' );
	$check( in_array( [ $granted['consent_id'], 1 ], $scheduled_retries, true ), 'The 60-second receipt retry was not requested.' );
	$check( ! in_array( [ $granted['consent_id'], 2 ], $scheduled_retries, true ), 'A later retry was scheduled before the preceding attempt ran.' );
	$check( false !== has_action( 'init', [ 'KaliCart_Bridge_Commerce_Consent', 'recover_pending_receipts' ] ), 'Pending receipt recovery is not attached to plugin boot.' );
	$check( false !== has_action( KaliCart_Bridge_Identity::CRON_HOOK, [ 'KaliCart_Bridge_Commerce_Consent', 'recover_pending_receipts' ] ), 'Pending receipt recovery is not attached to the daily heartbeat.' );

	// A boot/heartbeat with a pending row must recreate its missing event. Then
	// every retry chains exactly one later event, continuing into following days.
	$recovery_before = count( array_filter( $scheduled_retries, static function( array $args ) use ( $granted ): bool {
		return [ $granted['consent_id'], 1 ] === $args;
	} ) );
	KaliCart_Bridge_Commerce_Consent::recover_pending_receipts();
	$recovery_after = count( array_filter( $scheduled_retries, static function( array $args ) use ( $granted ): bool {
		return [ $granted['consent_id'], 1 ] === $args;
	} ) );
	$check( $recovery_before + 1 === $recovery_after, 'Boot/heartbeat recovery did not recreate the missing first retry event.' );
	for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
		KaliCart_Bridge_Commerce_Consent::retry_receipt( $granted['consent_id'], $attempt );
		$check( in_array( [ $granted['consent_id'], $attempt + 1 ], $scheduled_retries, true ), 'Retry attempt ' . $attempt . ' did not chain attempt ' . ( $attempt + 1 ) . '.' );
	}
	$expected_delays = [ 1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 43200, 6 => 86400 ];
	foreach ( $expected_delays as $attempt => $expected_delay ) {
		$events = $scheduled_at[ $attempt ] ?? [];
		$check( ! empty( $events ), 'No schedule timestamp was captured for retry attempt ' . $attempt . '.' );
		if ( ! empty( $events ) ) {
			$observed = end( $events ) - time();
			$check( abs( $observed - $expected_delay ) <= 5, 'Retry attempt ' . $attempt . ' used an unexpected backoff.' );
		}
	}

	$delivery_mode = 'accepted';
	KaliCart_Bridge_Commerce_Consent::retry_receipt( $granted['consent_id'], 6 );
	$again = KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'granted', get_current_user_id() );
	$check( $granted['consent_id'] === $again['consent_id'], 'Retrying the same grant created a second consent ID.' );
	$check( true === ( $again['idempotent'] ?? false ), 'The repeated grant was not reported as idempotent.' );
	$check( 'accepted' === $again['receipt_status'], 'The idempotent grant did not redeliver its pending receipt.' );
	$check( 0 === (int) $again['retry_attempt'] && empty( $again['next_retry_at_utc'] ), 'Accepted delivery did not clear retry metadata.' );

	$admin_js = (string) file_get_contents( trailingslashit( $test_root ) . 'admin/assets/admin.js' );
	$check( false !== strpos( $admin_js, "state.receipt_status === 'accepted' && state.global_receipt_status" ), 'Provider UI can still display a Global status before local delivery is acknowledged.' );
	$check( false !== strpos( $admin_js, 'awaiting delivery to KaliCart Global' ), 'Provider UI lacks the truthful pending-delivery fallback.' );
	$check( false === strpos( $admin_js, 'providerConsentEvidenceId' ), 'Admin JavaScript still targets the removed consent ID field.' );
	$check( false !== strpos( $admin_js, 'class="kali-visibility-facts"' ), 'External visibility results are not rendered as responsive status facts.' );
	$check( false !== strpos( $admin_js, 'loadExternalVisibility();' ), 'External visibility does not load its latest observation automatically.' );
	$admin_css = (string) file_get_contents( trailingslashit( $test_root ) . 'admin/assets/admin.css' );
	$check( false !== strpos( $admin_css, '.kali-visibility-facts' ) && false !== strpos( $admin_css, 'grid-template-columns: repeat(2, minmax(0, 1fr))' ), 'External visibility lacks its responsive status grid.' );

	$grant_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE consent_id=%s", $granted['consent_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact plugin-owned table.
	$immutable = [
		'protocol'             => (string) $grant_row['protocol'],
		'consent_id'           => (string) $grant_row['consent_id'],
		'provider'             => (string) $grant_row['provider'],
		'purpose'              => (string) $grant_row['purpose'],
		'action'               => (string) $grant_row['action'],
		'occurred_at_utc'      => (string) $grant_row['occurred_at_utc'],
		'wp_user_id'           => (int) $grant_row['wp_user_id'],
		'plugin_version'       => (string) $grant_row['plugin_version'],
		'terms_version'        => (string) $grant_row['terms_version'],
		'consent_locale'       => (string) $grant_row['consent_locale'],
		'canonical_text_hash'  => (string) $grant_row['canonical_text_hash'],
		'consent_text'         => (string) $grant_row['consent_text'],
		'consent_text_hash'    => (string) $grant_row['consent_text_hash'],
		'previous_record_hash' => '' === (string) $grant_row['previous_record_hash'] ? null : (string) $grant_row['previous_record_hash'],
	];
	$expected_hash = hash( 'sha256', (string) wp_json_encode( $immutable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	$check( hash_equals( $expected_hash, (string) $grant_row['record_hash'] ), 'The stored grant record hash does not verify.' );
	$check( hash( 'sha256', KaliCart_Bridge_Commerce_Consent::CANONICAL_CONSENT_TEXT ) === $grant_row['canonical_text_hash'], 'The canonical native-English text hash is wrong.' );

	foreach ( $payloads as $payload ) {
		$check( ! array_key_exists( 'wp_user_id', $payload ), 'A Global receipt exposed the local WordPress user ID.' );
		$check( ! array_key_exists( 'consent_text', $payload ), 'A Global receipt exposed the localized legal text.' );
	}
	$public_state = KaliCart_Bridge_Commerce_Consent::discovery_state();
	$public_json  = (string) wp_json_encode( $public_state );
	$check( false === strpos( $public_json, 'wp_user_id' ) && false === strpos( $public_json, 'consent_text"' ), 'Public discovery exposed private evidence fields.' );

	update_option( 'kalicart_bridge_global_consent', false, false );
	$check( true === KaliCart_Bridge_Commerce_Consent::current()['authorized'], 'Disabling Global rewrote the provider authorization.' );
	$check( false === KaliCart_Bridge_Commerce_Consent::discovery_state()['global_active'], 'Discovery did not suspend delivery when Global was disabled.' );
	update_option( 'kalicart_bridge_global_consent', true, false );

	$revoked = KaliCart_Bridge_Commerce_Consent::record_action( KaliCart_Bridge_Commerce_Consent::PROVIDER_OPENAI, 'revoked', get_current_user_id() );
	$created_ids[] = $revoked['consent_id'];
	$check( false === $revoked['authorized'], 'Provider revocation did not turn the provider authorization off.' );
	$check( true === (bool) get_option( 'kalicart_bridge_global_consent' ), 'Provider revocation changed the Federated Catalog consent.' );
	$check( '' !== (string) get_option( 'kalicart_bridge_federation_registered_at' ), 'Provider revocation removed the federation registration.' );
	$check( $granted['record_hash'] === $revoked['previous_record_hash'], 'The revocation does not chain to the preceding grant.' );
	$report = [
		'grant_receipt'       => $again['receipt_status'],
		'revoke_receipt'      => $revoked['receipt_status'],
		'admin_css_version'    => $style_version,
		'admin_js_version'     => $script_version,
		'payload_count'       => count( $payloads ),
		'hash_chain_verified' => empty( $failures ),
	];
} finally {
	remove_filter( 'pre_http_request', $intercept, 10 );
	remove_filter( 'pre_schedule_event', $intercept_schedule, 10 );
	foreach ( array_filter( $created_ids ) as $consent_id ) {
		foreach ( range( 1, 8 ) as $attempt ) {
			$timestamp = wp_next_scheduled( KaliCart_Bridge_Commerce_Consent::RETRY_HOOK, [ $consent_id, $attempt ] );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, KaliCart_Bridge_Commerce_Consent::RETRY_HOOK, [ $consent_id, $attempt ] );
			}
		}
		$wpdb->delete( $table, [ 'consent_id' => $consent_id ], [ '%s' ] );
	}
	foreach ( $original as $option_name => $value ) {
		if ( $missing === $value ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $value, false );
		}
	}
}

foreach ( array_filter( $created_ids ) as $consent_id ) {
	$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE consent_id=%s", $consent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact plugin-owned table.
	$check( 0 === $remaining, 'Test cleanup left a synthetic consent row: ' . $consent_id );
}

echo wp_json_encode( [
	'success'  => empty( $failures ),
	'failures' => $failures,
	'report'   => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

if ( ! empty( $failures ) ) {
	exit( 1 );
}
