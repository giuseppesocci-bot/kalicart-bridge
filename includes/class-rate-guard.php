<?php
/**
 * Shared, database-backed abuse guard for public KaliCart endpoints.
 *
 * Counter state is kept in one non-autoloaded option per scope. Reads bypass
 * the object cache and every mutation is serialized by an owner-safe database
 * mutex, so a persistent cache cannot make concurrent requests lose updates.
 *
 * @package KaliCart_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class KaliCart_Bridge_Rate_Guard {

	private const STATE_VERSION    = 1;
	private const MAX_SCOPE_LENGTH = 40;
	private const MAX_CLIENTS_HARD = 2048;
	private const LOCK_TTL         = 10.0;

	/** Execute a short internal critical section under the same owner-safe DB mutex. */
	public static function synchronized( string $scope, callable $callback ): array {
		$scope = self::normalize_scope( $scope );
		if ( '' === $scope ) {
			return array( 'acquired' => false, 'value' => null );
		}
		$lock = self::acquire_lock( $scope );
		if ( false === $lock ) {
			return array( 'acquired' => false, 'value' => null );
		}
		try {
			return array( 'acquired' => true, 'value' => call_user_func( $callback ) );
		} finally {
			self::release_lock( $scope, $lock );
		}
	}

	/**
	 * Charge a request against one global and one per-client fixed window.
	 *
	 * Limits accept client_limit, client_window, global_limit, global_window and
	 * max_clients. A zero limit disables that side of the guard. The result is a
	 * transport-neutral array so REST surfaces can preserve their own envelopes.
	 *
	 * @return array{allowed:bool,retry_after:int,reason:string}
	 */
	public static function check( string $scope, int $cost, array $limits ): array {
		$scope = self::normalize_scope( $scope );
		if ( '' === $scope ) {
			return self::rejected( 1, 'invalid_scope' );
		}

		$cost          = max( 1, $cost );
		$client_limit  = max( 0, (int) ( $limits['client_limit'] ?? 0 ) );
		$client_window = max( 1, (int) ( $limits['client_window'] ?? 60 ) );
		$global_limit  = max( 0, (int) ( $limits['global_limit'] ?? 0 ) );
		$global_window = max( 1, (int) ( $limits['global_window'] ?? 10 ) );
		$max_clients   = min(
			self::MAX_CLIENTS_HARD,
			max( 1, (int) ( $limits['max_clients'] ?? 1024 ) )
		);

		if ( 0 === $client_limit && 0 === $global_limit ) {
			return self::allowed();
		}
		if ( ( $global_limit > 0 && $cost > $global_limit ) || ( $client_limit > 0 && $cost > $client_limit ) ) {
			return self::rejected( max( $client_window, $global_window ), 'cost_exceeds_limit' );
		}

		$state_name = self::state_option_name( $scope );
		$client_key = md5( self::client_ip() );
		$now        = time();

		// Cheap but fresh preflight: global first, then client. It sheds a saturated
		// flood without mutex contention; the same checks are authoritative below.
		$preflight = self::read_state( $state_name );
		if ( ! $preflight['ok'] ) {
			return self::rejected( 1, 'storage_unavailable' );
		}
		$preflight_result = self::limit_result(
			$preflight['state'],
			$client_key,
			$cost,
			$client_limit,
			$client_window,
			$global_limit,
			$global_window,
			$now
		);
		if ( ! $preflight_result['allowed'] ) {
			return $preflight_result;
		}

		$lock = self::acquire_lock( $scope );
		if ( false === $lock ) {
			return self::rejected( 1, 'lock_unavailable' );
		}

		try {
			$fresh = self::read_state( $state_name );
			if ( ! $fresh['ok'] ) {
				return self::rejected( 1, 'storage_unavailable' );
			}

			$state = $fresh['state'];
			$check = self::limit_result(
				$state,
				$client_key,
				$cost,
				$client_limit,
				$client_window,
				$global_limit,
				$global_window,
				$now
			);
			if ( ! $check['allowed'] ) {
				return $check; // A client rejection never consumes global allowance.
			}

			// Bound persistent state: expired sources disappear on every accepted
			// mutation, and a new source fails closed when the live map is full.
			foreach ( $state['clients'] as $key => $window ) {
				if ( (int) $window['expires'] <= $now ) {
					unset( $state['clients'][ $key ] );
				}
			}
			if ( $client_limit > 0 && ! isset( $state['clients'][ $client_key ] ) && count( $state['clients'] ) >= $max_clients ) {
				return self::rejected( 1, 'client_capacity' );
			}

			if ( $global_limit > 0 ) {
				if ( (int) $state['global']['expires'] <= $now ) {
					$state['global'] = array( 'count' => 0, 'expires' => $now + $global_window );
				}
				$state['global']['count'] += $cost;
			}
			if ( $client_limit > 0 ) {
				if ( ! isset( $state['clients'][ $client_key ] ) ) {
					$state['clients'][ $client_key ] = array( 'count' => 0, 'expires' => $now + $client_window );
				}
				$state['clients'][ $client_key ]['count'] += $cost;
			}

			$state['version'] = self::STATE_VERSION;
			if ( ! self::write_state( $state_name, $state, $fresh['exists'], $fresh['raw'] ) ) {
				return self::rejected( 1, 'storage_unavailable' );
			}
			return self::allowed();
		} finally {
			self::release_lock( $scope, $lock );
		}
	}

	/** Resolve a stable limiter identity without trusting spoofable forwarding data. */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return 'unknown';
		}
		if ( ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';
		if ( '' === $xff ) {
			return $remote;
		}

		// Preserve the right (proxy-appended) edge and discard a partial hop if
		// truncation cut through the attacker-controlled prefix.
		if ( strlen( $xff ) > 2048 ) {
			$xff  = substr( $xff, -2048 );
			$comma = strpos( $xff, ',' );
			if ( false === $comma ) {
				return $remote;
			}
			$xff = substr( $xff, $comma + 1 );
		}

		$hops = array_slice( array_map( 'trim', explode( ',', $xff ) ), -20 );
		foreach ( array_reverse( $hops ) as $hop ) {
			// A malformed nearest hop makes the forwarding chain untrustworthy.
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return $remote;
			}
			if ( self::is_trusted_proxy( $hop ) ) {
				continue;
			}
			return $hop;
		}
		return $remote;
	}

	/** Public for deterministic security tests and hosting diagnostics. */
	public static function ip_in_cidr( string $ip, string $cidr ): bool {
		$ip   = trim( $ip );
		$cidr = trim( $cidr );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || '' === $cidr ) {
			return false;
		}
		if ( false === strpos( $cidr, '/' ) ) {
			return filter_var( $cidr, FILTER_VALIDATE_IP ) && hash_equals( $cidr, $ip );
		}

		$parts = explode( '/', $cidr );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] || ! ctype_digit( $parts[1] ) ) {
			return false;
		}
		$subnet = filter_var( $parts[0], FILTER_VALIDATE_IP );
		if ( false === $subnet ) {
			return false;
		}
		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits = (int) $parts[1];
		$max  = 8 * strlen( $ip_bin );
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$tail  = $bits % 8;
		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $tail ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $tail ) ) & 0xff;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}

	private static function is_trusted_proxy( string $ip ): bool {
		$entries = (array) apply_filters( 'kalicart_bridge_trusted_proxies', array() );
		foreach ( $entries as $entry ) {
			if ( is_scalar( $entry ) && self::ip_in_cidr( $ip, (string) $entry ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_scope( string $scope ): string {
		$scope = trim( $scope );
		if ( strlen( $scope ) > self::MAX_SCOPE_LENGTH || 1 !== preg_match( '/^[a-zA-Z0-9_-]+$/', $scope ) ) {
			return '';
		}
		return strtolower( $scope );
	}

	private static function state_option_name( string $scope ): string {
		return 'kalicart_rate_guard_' . $scope;
	}

	private static function lock_option_name( string $scope ): string {
		return 'kalicart_rate_guard_lock_' . $scope;
	}

	private static function empty_state(): array {
		return array(
			'version' => self::STATE_VERSION,
			'global'  => array( 'count' => 0, 'expires' => 0 ),
			'clients' => array(),
		);
	}

	/** @return array{ok:bool,exists:bool,raw:?string,state:array} */
	private static function read_state( string $name ): array {
		$row = self::read_raw_option( $name );
		if ( ! $row['ok'] ) {
			return array( 'ok' => false, 'exists' => false, 'raw' => null, 'state' => self::empty_state() );
		}
		if ( ! $row['exists'] ) {
			return array( 'ok' => true, 'exists' => false, 'raw' => null, 'state' => self::empty_state() );
		}

		$state = maybe_unserialize( $row['raw'] );
		if ( ! is_array( $state )
			|| (int) ( $state['version'] ?? 0 ) !== self::STATE_VERSION
			|| ! isset( $state['global'], $state['clients'] )
			|| ! is_array( $state['global'] )
			|| ! is_array( $state['clients'] )
			|| ! isset( $state['global']['count'], $state['global']['expires'] )
			|| ! is_numeric( $state['global']['count'] )
			|| ! is_numeric( $state['global']['expires'] ) ) {
			return array( 'ok' => false, 'exists' => true, 'raw' => $row['raw'], 'state' => self::empty_state() );
		}

		$global = array(
			'count'   => max( 0, (int) $state['global']['count'] ),
			'expires' => max( 0, (int) $state['global']['expires'] ),
		);
		$clients = array();
		if ( count( $state['clients'] ) > self::MAX_CLIENTS_HARD ) {
			return array( 'ok' => false, 'exists' => true, 'raw' => $row['raw'], 'state' => self::empty_state() );
		}
		foreach ( $state['clients'] as $key => $window ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $key )
				|| ! is_array( $window ) || ! isset( $window['count'], $window['expires'] )
				|| ! is_numeric( $window['count'] ) || ! is_numeric( $window['expires'] ) ) {
				return array( 'ok' => false, 'exists' => true, 'raw' => $row['raw'], 'state' => self::empty_state() );
			}
			$clients[ $key ] = array(
				'count'   => max( 0, (int) $window['count'] ),
				'expires' => max( 0, (int) $window['expires'] ),
			);
		}

		return array(
			'ok'     => true,
			'exists' => true,
			'raw'    => $row['raw'],
			'state'  => array( 'version' => self::STATE_VERSION, 'global' => $global, 'clients' => $clients ),
		);
	}

	/** @return array{ok:bool,exists:bool,raw:?string} */
	private static function read_raw_option( string $name ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- limiter correctness requires a fresh database read.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return array( 'ok' => false, 'exists' => false, 'raw' => null );
		}
		return null === $row
			? array( 'ok' => true, 'exists' => false, 'raw' => null )
			: array( 'ok' => true, 'exists' => true, 'raw' => (string) $row->option_value );
	}

	private static function write_state( string $name, array $state, bool $exists, ?string $observed ): bool {
		global $wpdb;
		$serialized = maybe_serialize( $state );
		if ( $exists ) {
			$written = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-serialized CAS prevents lost increments.
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
					$serialized,
					$name,
					(string) $observed
				)
			);
		} else {
			$written = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- unique option_name makes first state insert atomic.
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					$serialized
				)
			);
		}
		if ( 1 !== (int) $written ) {
			return false;
		}
		wp_cache_delete( $name, 'options' );
		if ( ! $exists ) {
			// A prior get_option() miss may have cached this name in notoptions.
			wp_cache_delete( 'notoptions', 'options' );
		}
		return true;
	}

	/** @return array{owner:string,raw:string}|false */
	private static function acquire_lock( string $scope ) {
		global $wpdb;
		$name = self::lock_option_name( $scope );
		// Allow ordinary short DB critical sections to finish without producing a
		// false 429, while retaining a strict ~20 ms fail-closed contention budget.
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$owner = wp_generate_uuid4();
			$value = array( 'owner' => $owner, 'expires' => microtime( true ) + self::LOCK_TTL );
			$raw   = maybe_serialize( $value );
			$added = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- database unique key is the mutex primitive.
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					$raw
				)
			);
			if ( 1 === (int) $added ) {
				wp_cache_delete( $name, 'options' );
				return array( 'owner' => $owner, 'raw' => $raw );
			}
			if ( false === $added || '' !== (string) $wpdb->last_error ) {
				return false;
			}

			$current = self::read_raw_option( $name );
			if ( ! $current['ok'] || ! $current['exists'] ) {
				usleep( 1000 );
				continue;
			}
			$decoded = maybe_unserialize( $current['raw'] );
			$expires = is_array( $decoded ) && isset( $decoded['expires'] ) && is_numeric( $decoded['expires'] )
				? (float) $decoded['expires']
				: 0.0;
			if ( $expires <= microtime( true ) ) {
				self::compare_delete( $name, (string) $current['raw'] );
				continue;
			}
			usleep( 1000 );
		}
		return false;
	}

	private static function release_lock( string $scope, array $lock ): void {
		// The exact serialized value contains the owner token and expiry. CAS delete
		// cannot remove a successor's lock after a stale-owner race.
		self::compare_delete( self::lock_option_name( $scope ), $lock['raw'] );
	}

	private static function compare_delete( string $name, string $observed_raw ): bool {
		global $wpdb;
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-safe mutex release/takeover.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name,
				$observed_raw
			)
		);
		if ( 1 !== (int) $deleted ) {
			return false;
		}
		wp_cache_delete( $name, 'options' );
		return true;
	}

	private static function limit_result(
		array $state,
		string $client_key,
		int $cost,
		int $client_limit,
		int $client_window,
		int $global_limit,
		int $global_window,
		int $now
	): array {
		$global = $state['global'];
		if ( $global_limit > 0 && (int) $global['expires'] > $now && (int) $global['count'] + $cost > $global_limit ) {
			return self::rejected( max( 1, (int) $global['expires'] - $now ), 'global' );
		}
		if ( $client_limit > 0 && isset( $state['clients'][ $client_key ] ) ) {
			$client = $state['clients'][ $client_key ];
			if ( (int) $client['expires'] > $now && (int) $client['count'] + $cost > $client_limit ) {
				return self::rejected( max( 1, (int) $client['expires'] - $now ), 'client' );
			}
		}
		return self::allowed();
	}

	private static function allowed(): array {
		return array( 'allowed' => true, 'retry_after' => 0, 'reason' => '' );
	}

	private static function rejected( int $retry_after, string $reason ): array {
		return array( 'allowed' => false, 'retry_after' => max( 1, $retry_after ), 'reason' => $reason );
	}
}
