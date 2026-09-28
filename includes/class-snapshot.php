<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Snapshot — static, signed snapshot of the public catalog
 * (1.0.139). Contract: CONTRATTO-1.0.139-SNAPSHOT (closed 2026-09-28; §15 > §14 > rest).
 *
 * Why: some storefronts put an anti-bot challenge in front of dynamic PHP paths
 * (REST) while serving static files. KaliCart Global can then read the same
 * public catalog from files this plugin writes on the site. Same data (the REST
 * `fields=full` rows, minus exact stock quantities), same recipient, a read by
 * KaliCart Global: nothing is sent by this class.
 *
 * - Only with Federated Catalog consent, an installation key, production, root
 *   install and WooCommerce. Revoke / deactivate / uninstall delete everything.
 * - Partition id-range-v1 (contract §16.1): bucket = floor(parent id / 1024),
 *   invariant ranges, queried by primary-key range. A change marks only its
 *   bucket dirty (token); only dirty buckets are rebuilt; unchanged shards keep
 *   their name and hash, so KaliCart Global downloads only what changed.
 * - Built in the background (Action Scheduler, 20 s per run, resumable),
 *   never during a product save. Debounced 60 s; one full walk per day as
 *   reconciliation (catches ERP/SQL writes that bypass the hooks).
 * - Publication order: shards -> manifest (tmp + rename) -> pointer in the
 *   /.well-known mirrors -> next heartbeat. Shards of the previous manifest
 *   stay one cycle, then go.
 * - Nothing here may break the plugin: every failure is recorded and retried.
 */
class KaliCart_Bridge_Snapshot {

	const OPTION        = 'kalicart_bridge_snapshot';
	const HOOK_BUILD    = 'kalicart_bridge_snapshot_build';
	const HOOK_DAILY    = 'kalicart_bridge_snapshot_daily';
	const GROUP         = 'kalicart-bridge';
	const BATCH         = 100;
	const SHARD_MAX_N   = 250;
	const SHARD_MAX_B   = 4194304;   // 4 MB uncompressed
	const RUN_SECONDS   = 20;
	const EXPIRES_S     = 7 * DAY_IN_SECONDS;
	const OVERDUE_S     = 2 * DAY_IN_SECONDS;
	const GIVE_UP_S     = DAY_IN_SECONDS;
	const FIRST_SPACE_B = 52428800;  // 50 MB before the first generation

	/** Top-level fields of the federated-1 projection (REST 1.0.138 fields=full). */
	const ALLOWLIST = [
		'id', 'sku', 'type', 'name', 'slug', 'url', 'status', 'catalog_visibility', 'description',
		'short_description', 'categories', 'tags', 'brand', 'images', 'price', 'list_price', 'stock',
		'attributes', 'variants', 'variations', 'variation_summary', 'colors', 'sizes', 'gender', 'group',
		'barcodes', 'dimensions', 'weight', 'shipping', 'discovery', 'purchase_readiness', 'metadata',
		'quarantine', 'rating', 'active_coupons', 'checkout_url', 'created_at', 'updated_at',
	];

	public static function init(): void {
		add_action( self::HOOK_BUILD, [ __CLASS__, 'build' ] );
		add_action( self::HOOK_DAILY, [ __CLASS__, 'daily' ] );
		foreach ( [ 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation',
			'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock',
			'woocommerce_trash_product', 'woocommerce_delete_product', 'woocommerce_delete_product_variation',
			'before_delete_post', 'wp_trash_post', 'untrashed_post' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_change' ], 10, 1 );
		}
		add_action( 'deleted_post', [ __CLASS__, 'after_delete' ], 10, 1 );
		add_action( 'init', static function () {
			if ( self::eligible() && ! wp_next_scheduled( self::HOOK_DAILY ) ) {
				wp_schedule_event( time() + wp_rand( 300, 3600 ), 'daily', self::HOOK_DAILY ); // jitter
			}
		}, 30 );
	}

	// ── eligibility and state ───────────────────────────────────────────────

	public static function unavailable_reason(): string {
		if ( ! get_option( 'kalicart_bridge_global_consent', false ) ) {
			return 'no_consent';
		}
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return 'no_woocommerce';
		}
		if ( ! class_exists( 'KaliCart_Bridge_Identity' ) || '' !== KaliCart_Bridge_Identity::unavailable_reason() ) {
			return 'identity_unavailable';
		}
		return '';
	}

	public static function eligible(): bool {
		return '' === self::unavailable_reason();
	}

	private static function state(): array {
		$s = get_option( self::OPTION, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function save( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	private static function patch( array $p ): array {
		$s = array_merge( self::state(), $p );
		unset( $s['dirty'], $s['epoch'], $s['full_requested'] ); // pre-§16.4 keys
		self::save( $s );
		return $s;
	}

	// ── change tracking (dirty buckets with tokens) ─────────────────────────

	/** Parent product id of a post id (variation -> parent), or 0 when not a product. */
	private static function product_parent( $arg ): int {
		if ( $arg instanceof WC_Product ) {
			return $arg->is_type( 'variation' ) ? (int) $arg->get_parent_id() : (int) $arg->get_id();
		}
		$id = is_numeric( $arg ) ? (int) $arg : 0;
		if ( $id <= 0 ) {
			return 0;
		}
		$type = get_post_type( $id );
		if ( 'product' === $type ) {
			return $id;
		}
		if ( 'product_variation' === $type ) {
			return (int) wp_get_post_parent_id( $id );
		}
		return 0;
	}

	/** Hooks: mark the bucket of the PARENT dirty (also for deletions, before the post goes). */
	public static function on_change( $arg = null ): void {
		if ( ! self::eligible() ) {
			return;
		}
		$pid = self::product_parent( $arg );
		if ( $pid <= 0 ) {
			return;
		}
		$b = KaliCart_Bridge_Snapshot_Profile::bucket_of( $pid );
		if ( 'before_delete_post' === current_filter() && is_numeric( $arg ) ) {
			self::$deleting[ (int) $arg ] = $b; // re-marked once the row is really gone
		}
		self::mark_dirty( $b );
		self::schedule_build( 60 );
	}

	/** @var array<int,int> post id => bucket, between before_delete_post and deleted_post. */
	private static $deleting = [];

	/** A marker set before the DELETE could be consumed by a build that still saw the row: mark again after. */
	public static function after_delete( $post_id = 0 ): void {
		$id = (int) $post_id;
		if ( isset( self::$deleting[ $id ] ) ) {
			self::mark_dirty( self::$deleting[ $id ] );
			unset( self::$deleting[ $id ] );
			self::schedule_build( 60 );
		}
	}

	/*
	 * Concurrency (contract §16.4, token revision after collaudo ChatGPT 08:19).
	 * The main option is written ONLY by the build step, under the lock (and by
	 * delete_all, which is terminal). Change events never touch it: each dirty
	 * bucket is its own options row (DIRTY_PREFIX.<bucket>, value = random opaque
	 * token), written with one atomic upsert, so two saves on different buckets
	 * cannot lose each other. No global order is needed: a job captures
	 * [bucket => token] (and the full-request token) when it starts, and at the
	 * end deletes only the exact tokens it captured (compare-and-delete). A
	 * change after the capture writes a new token and survives.
	 * Supported databases: MySQL / MariaDB (WooCommerce's own requirement).
	 */
	const DIRTY_PREFIX = 'kalicart_bridge_snapshot_dirty_';
	const FULL_KEY     = 'kalicart_bridge_snapshot_full';
	const LOCK_KEY     = 'kalicart_bridge_snapshot_lock';
	const LOCK_STALE_S = 600;

	/** Random opaque token (unique per change event). */
	private static function token(): string {
		try {
			return bin2hex( random_bytes( 12 ) );
		} catch ( \Throwable $e ) {
			return wp_generate_password( 24, false, false );
		}
	}

	/** Upsert one row atomically (no read-modify-write). */
	private static function put_row( string $name, string $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
			$name, $value
		) );
	}

	/** Compare-and-delete: removes the row only if it still holds $value. */
	private static function clear_row_if( string $name, string $value ): bool {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
	}

	private static function get_row( string $name ): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $v ? null : (string) $v;
	}

	/** Dirty buckets: [bucket => token]. */
	public static function dirty_list(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( self::DIRTY_PREFIX ) . '%'
		), ARRAY_A );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$b = substr( (string) $r['option_name'], strlen( self::DIRTY_PREFIX ) );
			if ( '' !== $b && ctype_digit( $b ) ) {
				$out[ (int) $b ] = (string) $r['option_value'];
			}
		}
		ksort( $out, SORT_NUMERIC );
		return $out;
	}

	public static function mark_dirty( int $b ): string {
		if ( $b < 0 ) {
			return '';
		}
		$t = self::token();
		self::put_row( self::DIRTY_PREFIX . $b, $t );
		return $t;
	}

	/** Daily: full walk as reconciliation (rewrites only what actually changed). */
	public static function daily(): void {
		if ( self::eligible() ) {
			self::put_row( self::FULL_KEY, self::token() );
			self::schedule_build( 5 );
		}
	}

	private static function delete_markers(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name IN (%s, %s)",
			$wpdb->esc_like( self::DIRTY_PREFIX ) . '%', self::FULL_KEY, 'kalicart_bridge_snapshot_epoch' // pre-token counter row
		) );
	}


	/**
	 * Enqueues a build step. Deduplicates on PENDING actions only: while a build
	 * step runs, its own action is "running", and as_has_scheduled_action() /
	 * the unique flag would see it and refuse the continuation (collaudo
	 * ChatGPT 07:05). Concurrent steps are prevented by the lock in build().
	 */
	public static function schedule_build( $delay = 5 ): void {
		$delay = is_numeric( $delay ) ? max( 0, (int) $delay ) : 5;
		if ( ! self::eligible() ) {
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
			$pending = as_get_scheduled_actions( [
				'hook' => self::HOOK_BUILD, 'group' => self::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1,
			], 'ids' );
			if ( $pending ) {
				return;
			}
			as_schedule_single_action( time() + $delay, self::HOOK_BUILD, [], self::GROUP );
		} elseif ( ! wp_next_scheduled( self::HOOK_BUILD ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK_BUILD );
		}
	}

	/**
	 * Mutex across processes with an ownership token. Acquire: INSERT IGNORE
	 * (atomic on the unique option_name). Stale takeover (a crashed step):
	 * compare-and-swap on the old value, so two takers cannot both win.
	 * Release: delete only if the row still holds OUR token.
	 */
	private static $lock_token = '';

	private static function lock(): bool {
		global $wpdb;
		$token = time() . ':' . wp_generate_password( 16, false, false );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_KEY, $token ) );
		if ( 1 === (int) $wpdb->rows_affected ) {
			self::$lock_token = $token;
			return true;
		}
		$old = self::get_row( self::LOCK_KEY );
		if ( null !== $old && time() - (int) strtok( $old, ':' ) > self::LOCK_STALE_S ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, self::LOCK_KEY, $old ) );
			if ( 1 === (int) $wpdb->rows_affected ) {
				self::$lock_token = $token;
				return true;
			}
		}
		return false;
	}

	private static function unlock(): void {
		if ( '' !== self::$lock_token ) {
			self::clear_row_if( self::LOCK_KEY, self::$lock_token );
			self::$lock_token = '';
		}
	}


	// ── paths ──────────────────────────────────────────────────────────────

	/** Contract §14.1: https, the store's own host, effective port 443, no userinfo. */
	public static function valid_public_url( string $url ): bool {
		$u    = wp_parse_url( $url );
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $u ) || ! is_array( $home ) ) {
			return false;
		}
		return 'https' === strtolower( (string) ( $u['scheme'] ?? '' ) )
			&& strtolower( (string) ( $u['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) )
			&& in_array( (int) ( $u['port'] ?? 443 ), [ 443 ], true )
			&& empty( $u['user'] ) && empty( $u['pass'] );
	}

	/** [dir, base url, location] or null when no valid location exists. */
	public static function location(): ?array {
		if ( get_option( 'kalicart_bridge_well_known_enabled', true ) ) {
			$base = home_url( '/.well-known/kalicart/' );
			$dir  = rtrim( ABSPATH, '/' ) . '/.well-known/kalicart/';
			if ( self::valid_public_url( $base ) && ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) && wp_is_writable( $dir ) ) {
				return [ $dir, $base, 'well-known' ];
			}
		}
		$up = wp_upload_dir( null, false );
		if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) && ! empty( $up['baseurl'] ) ) {
			$base = trailingslashit( $up['baseurl'] ) . 'kalicart/';
			$dir  = trailingslashit( $up['basedir'] ) . 'kalicart/';
			if ( self::valid_public_url( $base ) && ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) && wp_is_writable( $dir ) ) {
				return [ $dir, $base, 'uploads' ];
			}
		}
		return null;
	}

	private static function guard_dir( string $dir ): void {
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected, WordPress.PHP.NoSilencedErrors.Discouraged -- plugin-owned catalog folder (/.well-known/kalicart/ or uploads/kalicart/).
		}
	}

	private static function write_atomic( string $path, string $bytes ): bool {
		$tmp = $path . '.tmp-' . wp_generate_password( 8, false );
		if ( false === @file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected, WordPress.PHP.NoSilencedErrors.Discouraged -- plugin-owned catalog folder (/.well-known/kalicart/ or uploads/kalicart/).
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic replace (same directory); WP_Filesystem::move() is not atomic on non-direct transports.
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}

	private static function space_ok( string $dir, int $need ): bool {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space() may be disabled or fail on shared hosting; false means unknown.
		return false === $free || $free >= $need; // unknown free space: do not block
	}

	// ── projection federated-1 ──────────────────────────────────────────────

	/**
	 * Closed projection of a REST fields=full row: top-level allowlist, then
	 * removal of every stock quantity and of what is derived from it (contract
	 * §14.4, §15.3). Bundle composition quantities stay. $threshold resolves the
	 * WooCommerce low-stock threshold of the product or variation an entry
	 * describes (variation -> parent -> store, as WooCommerce does).
	 */
	public static function project( array $row, bool $show_scarcity, callable $threshold ): array {
		$out = array_intersect_key( $row, array_flip( self::ALLOWLIST ) );
		$pid = (int) ( $row['id'] ?? 0 );
		if ( isset( $out['stock'] ) && is_array( $out['stock'] ) ) {
			$out['stock'] = self::redact_stock( $out['stock'], $show_scarcity, $threshold, $pid );
		}
		foreach ( [ 'variants', 'variations' ] as $k ) {
			if ( isset( $out[ $k ] ) && is_array( $out[ $k ] ) ) {
				foreach ( $out[ $k ] as $i => $v ) {
					if ( is_array( $v ) && isset( $v['stock'] ) && is_array( $v['stock'] ) ) {
						$vid = (int) ( $v['variation_id'] ?? $v['id'] ?? $pid );
						$out[ $k ][ $i ]['stock'] = self::redact_stock( $v['stock'], $show_scarcity, $threshold, $vid );
					}
				}
			}
		}
		if ( isset( $out['metadata']['stock_confidence'] ) && 'numeric_stock_quantity' === $out['metadata']['stock_confidence'] ) {
			$out['metadata']['stock_confidence'] = 'availability_status_only';
		}
		// Exclusion only (contract §5.1): the filter answers "include this product?";
		// its return value NEVER replaces the payload. A failing hook excludes.
		try {
			$include = apply_filters( 'kalicart_snapshot_product', true, $row );
		} catch ( \Throwable $e ) {
			$include = false;
		}
		return false === $include ? [] : $out;
	}

	private static function redact_stock( array $st, bool $show_scarcity, callable $threshold, int $id ): array {
		$qty = array_key_exists( 'quantity', $st ) && is_numeric( $st['quantity'] ) ? (int) $st['quantity'] : null;
		unset( $st['quantity'] );
		if ( ( $st['confidence'] ?? '' ) === 'numeric_stock_quantity' ) {
			$st['confidence'] = 'availability_status_only';
		}
		if ( isset( $st['agent_note'] ) && 0 === strpos( (string) $st['agent_note'], 'Last unit available' ) ) {
			unset( $st['agent_note'] );
		}
		if ( $show_scarcity ) {
			$st['low_stock'] = ( null !== $qty && ! empty( $st['quantity_tracked'] ) && $qty > 0 && $qty <= (int) $threshold( $id ) );
		}
		return $st;
	}

	private static function show_scarcity(): bool {
		return 'no_amount' !== (string) get_option( 'woocommerce_stock_format', '' );
	}

	/** WooCommerce's own resolution: variation -> parent -> store. */
	public static function woo_threshold( int $id ): int {
		$p = $id > 0 ? wc_get_product( $id ) : null;
		if ( $p && function_exists( 'wc_get_low_stock_amount' ) ) {
			return (int) wc_get_low_stock_amount( $p );
		}
		return max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
	}

	/** Rows exactly as KaliCart Global's pull builds them: list full + detail for variables. */
	private static function rows_after( int $after_id, int $limit ): array {
		if ( method_exists( 'KaliCart_Bridge_API', 'force_default_language' ) ) {
			KaliCart_Bridge_API::force_default_language();
		}
		$res  = KaliCart_Bridge_Catalog_Engine::query_products( [
			'fields' => 'full', 'orderby' => 'id', 'order' => 'asc', 'per_page' => $limit, 'after_id' => $after_id,
		] );
		$rows = [];
		foreach ( (array) ( $res['products'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			if ( 'variable' === ( $row['type'] ?? '' ) ) {
				$p = wc_get_product( (int) $row['id'] );
				if ( $p ) {
					$row = array_merge( $row, KaliCart_Bridge_Catalog_Engine::normalize_product( $p, 'detail' ) );
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	// ── build ──────────────────────────────────────────────────────────────

	public static function build(): void {
		if ( ! self::lock() ) {
			self::schedule_build( 30 ); // another step is running: try again later
			return;
		}
		try {
			self::step();
		} catch ( \Throwable $e ) {
			self::patch( [ 'state' => 'error', 'last_error' => 'exception' ] );
		} finally {
			self::unlock();
		}
	}

	/** Seconds of work per step (filterable for tests and slow hosts). */
	private static function run_seconds(): float {
		return (float) apply_filters( 'kalicart_snapshot_run_seconds', self::RUN_SECONDS );
	}

	/**
	 * One bounded step. A job is either "full" (walk every product once, used
	 * for the first generation, the daily reconciliation and any doubt) or
	 * "dirty" (walk only the dirty buckets' id ranges). Buckets are closed one
	 * at a time; the manifest is published when the job ends.
	 */
	private static function step(): void {
		if ( ! self::eligible() ) {
			self::delete_all();
			return;
		}
		$loc = self::location();
		if ( ! $loc ) {
			self::patch( [ 'state' => 'storage_failure', 'last_error' => 'no_valid_location' ] );
			return;
		}
		[ $dir, $base, $where ] = $loc;
		self::guard_dir( $dir );
		$s   = self::state();
		$pub = is_array( $s['published'] ?? null ) ? $s['published'] : null;
		$job = is_array( $s['job'] ?? null ) ? $s['job'] : null;

		if ( ! $job || ( $job['dir'] ?? '' ) !== $dir ) {
			$full_req = self::get_row( self::FULL_KEY );
			$full     = ! $pub || ( $pub['dir'] ?? '' ) !== $dir || null !== $full_req;
			$dirty    = self::dirty_list(); // captured BEFORE reading any product: later changes get new tokens
			if ( ! $full && ! $dirty ) {
				return; // nothing to do
			}
			$need = ! empty( $pub['bytes'] ) ? 3 * (int) $pub['bytes'] : self::FIRST_SPACE_B;
			if ( $full && ! self::space_ok( $dir, $need ) ) {
				self::patch( [ 'state' => 'storage_failure', 'last_error' => 'disk_space' ] );
				return;
			}
			ksort( $dirty, SORT_NUMERIC );
			$job = [
				'mode'    => $full ? 'full' : 'dirty',
				'dir'     => $dir, 'base' => $base, 'location' => $where,
				'started' => time(),
				'queue'   => $full ? [] : array_map( 'intval', array_keys( $dirty ) ),
				'tokens'  => $dirty,                      // [bucket => token] captured at start
				'cursor'  => 0,                           // last product id processed
				'cur_b'   => null,                        // bucket being filled
				'done'    => [],                          // bucket => meta (or null = empty)
			];
			if ( null !== $full_req ) {
				self::clear_row_if( self::FULL_KEY, $full_req ); // a newer request survives
			}
			@file_put_contents( $dir . 'building.part', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected, WordPress.PHP.NoSilencedErrors.Discouraged -- plugin-owned catalog folder (/.well-known/kalicart/ or uploads/kalicart/).
		}

		$t0   = microtime( true );
		$show = self::show_scarcity();
		$thr  = [ __CLASS__, 'woo_threshold' ];
		$finished = false;
		$budget   = self::run_seconds();
		do {
			if ( 'dirty' === $job['mode'] && null === $job['cur_b'] ) {
				if ( ! $job['queue'] ) {
					$finished = true;
					break;
				}
				$b             = array_shift( $job['queue'] );
				$job['cur_b']  = $b;
				$job['cursor'] = KaliCart_Bridge_Snapshot_Profile::bucket_range( $b )[0] - 1;
				$job['acc']    = [];
			}
			$rows = self::rows_after( (int) $job['cursor'], self::BATCH );
			$ended = ! $rows;
			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				$b  = KaliCart_Bridge_Snapshot_Profile::bucket_of( $id );
				if ( 'dirty' === $job['mode'] && $b !== $job['cur_b'] ) {
					$ended = true; // walked past the dirty range
					break;
				}
				if ( 'full' === $job['mode'] && null !== $job['cur_b'] && $b !== $job['cur_b'] ) {
					if ( ! self::close_bucket( $job ) ) {
						return;
					}
				}
				if ( null === $job['cur_b'] ) {
					$job['cur_b'] = $b;
					$job['acc']   = [];
				}
				$job['cursor'] = $id;
				$proj = self::project( $row, $show, $thr );
				if ( ! $proj ) {
					continue;
				}
				$line = KaliCart_Bridge_Snapshot_Profile::jcs( $proj );
				file_put_contents( $dir . 'building.part', $line . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- streaming append to the private build buffer in the plugin-owned catalog folder.
				$job['acc'][ $id ] = [ KaliCart_Bridge_Snapshot_Profile::content_hash( $line ), strlen( $line ) + 1 ];
			}
			if ( $ended ) {
				if ( null !== $job['cur_b'] && ! self::close_bucket( $job ) ) {
					return;
				}
				if ( 'full' === $job['mode'] ) {
					$finished = true;
					break;
				}
			}
		} while ( microtime( true ) - $t0 < $budget );
		$partial = ! $finished && ( time() - (int) $job['started'] ) > self::GIVE_UP_S;
		if ( ! $finished && ! $partial ) {
			self::patch( [ 'job' => $job, 'state' => 'building' ] );
			self::schedule_build( 0 ); // continuation
			return;
		}
		self::publish( $job, ! $partial );
	}

	/** Writes the current bucket as one or more content-addressed sub-shards. */
	private static function close_bucket( array &$job ): bool {
		$dir  = $job['dir'];
		$b    = (int) $job['cur_b'];
		$acc  = (array) ( $job['acc'] ?? [] );
		$body = (string) @file_get_contents( $dir . 'building.part' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- local plugin-owned file, not a URL.
		@file_put_contents( $dir . 'building.part', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected, WordPress.PHP.NoSilencedErrors.Discouraged -- plugin-owned catalog folder (/.well-known/kalicart/ or uploads/kalicart/).
		$job['cur_b'] = null;
		$job['acc']   = [];
		if ( ! $acc ) {
			$job['done'][ $b ] = null; // empty bucket: absent from the manifest
			return true;
		}
		$lines  = explode( "\n", rtrim( $body, "\n" ) );
		$ids    = array_keys( $acc );
		$shards = [];
		$chunk  = '';
		$n      = 0;
		$bytes  = 0;
		foreach ( $lines as $i => $line ) {
			$chunk .= $line . "\n";
			$n++;
			$bytes += strlen( $line ) + 1;
			$last   = ( $i === count( $lines ) - 1 );
			if ( $last || $n >= self::SHARD_MAX_N || $bytes >= self::SHARD_MAX_B ) {
				$gz = gzencode( $chunk, 6 );
				if ( false === $gz || ! self::space_ok( $dir, 2 * strlen( $gz ) ) ) {
					self::patch( [ 'state' => 'storage_failure', 'last_error' => 'disk_space', 'job' => null ] );
					return false;
				}
				$name = KaliCart_Bridge_Snapshot_Profile::shard_name( $gz );
				if ( ! file_exists( $dir . $name ) && ! self::write_atomic( $dir . $name, $gz ) ) {
					self::patch( [ 'state' => 'storage_failure', 'last_error' => 'shard_write', 'job' => null ] );
					return false;
				}
				$shards[] = [ 'path' => $name, 'sha256' => hash( 'sha256', $gz ), 'bytes' => strlen( $gz ), 'count' => $n ];
				$chunk = '';
				$n     = 0;
				$bytes = 0;
			}
		}
		$hashes = [];
		foreach ( $acc as $id => $v ) {
			$hashes[ $id ] = $v[0];
		}
		[ $first, $last ] = KaliCart_Bridge_Snapshot_Profile::bucket_range( $b );
		$job['done'][ $b ] = [
			'id' => $b, 'first_id' => $first, 'last_id' => $last, 'count' => count( $acc ),
			'bucket_hash' => KaliCart_Bridge_Snapshot_Profile::bucket_hash( $hashes ), 'shards' => $shards,
		];
		return true;
	}

	private static function publish( array $job, bool $complete ): void {
		if ( ! self::eligible() ) { // revoked while building
			self::delete_all();
			return;
		}
		$s    = self::state();
		$prev = is_array( $s['published'] ?? null ) ? $s['published'] : null;
		// Buckets: a full job replaces everything; a dirty job merges its buckets.
		$buckets = ( 'dirty' === $job['mode'] && $prev ) ? (array) ( $prev['buckets'] ?? [] ) : [];
		foreach ( $job['done'] as $b => $meta ) {
			if ( null === $meta ) {
				unset( $buckets[ $b ] );
			} else {
				$buckets[ $b ] = $meta;
			}
		}
		ksort( $buckets, SORT_NUMERIC );
		[ $now, $known ] = KaliCart_Bridge_Identity::server_now();
		$by_bucket = [];
		foreach ( $buckets as $b => $meta ) {
			$by_bucket[ $b ] = $meta['bucket_hash'];
		}
		$probe = KaliCart_Bridge_Identity::sign_detached( 'probe' );
		if ( ! $probe ) {
			self::patch( [ 'state' => 'error', 'last_error' => 'no_key', 'job' => null ] );
			return;
		}
		$seq      = (int) ( $s['seq'] ?? 0 ) + 1;
		$manifest = [
			'format_version'     => KaliCart_Bridge_Snapshot_Profile::FORMAT_VERSION,
			'min_reader_version' => 1,
			'critical'           => [ 'partition', 'buckets', 'complete', 'seq', 'expires_at' ],
			'schema'             => KaliCart_Bridge_Snapshot_Profile::SCHEMA,
			'projection'         => KaliCart_Bridge_Snapshot_Profile::PROJECTION,
			'host'               => $probe['host'],
			'installation_id'    => $probe['installation_id'],
			'key_fingerprint'    => $probe['key_fingerprint'],
			'seq'                => $seq,
			'generated_at'       => $now,
			'expires_at'         => $now + self::EXPIRES_S,
			'time_source'        => $known ? 'global' : 'local',
			'complete'           => $complete,
			'count'              => (int) array_sum( array_column( $buckets, 'count' ) ),
			'catalog_hash'       => KaliCart_Bridge_Snapshot_Profile::catalog_hash( $by_bucket ),
			'variants_covered'   => [ 'language' => get_locale(), 'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ],
			'partition'          => [ 'scheme' => KaliCart_Bridge_Snapshot_Profile::PARTITION_SCHEME, 'width' => KaliCart_Bridge_Snapshot_Profile::PARTITION_WIDTH ],
			'buckets'            => array_values( $buckets ),
		];
		$manifest['sig'] = KaliCart_Bridge_Identity::sign_detached( KaliCart_Bridge_Snapshot_Profile::signing_input( $manifest ) )['sig'];
		$json            = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! self::write_atomic( $job['dir'] . 'manifest.json', $json ) ) {
			self::patch( [ 'state' => 'storage_failure', 'last_error' => 'manifest_write', 'job' => null ] );
			return;
		}
		// Retention: shards of this manifest and of the previous one (one cycle).
		$keep = [];
		foreach ( $buckets as $meta ) {
			$keep = array_merge( $keep, array_column( $meta['shards'], 'path' ) );
		}
		if ( $prev && ( $prev['dir'] ?? '' ) === $job['dir'] ) {
			$keep = array_merge( $keep, (array) ( $prev['shard_paths'] ?? [] ) );
		}
		self::prune( $job['dir'], array_unique( $keep ) );
		if ( $prev && ( $prev['dir'] ?? '' ) !== $job['dir'] ) {
			self::delete_dir_files( (string) $prev['dir'] );
		}
		// Compare-and-clear (contract §16.4): a marker goes only if it still holds
		// the exact token this job captured; a change that arrived meanwhile stays dirty.
		do_action( 'kalicart_snapshot_before_clear_dirty', $job );
		foreach ( (array) ( $job['tokens'] ?? [] ) as $b => $tok ) {
			$covered = array_key_exists( $b, $job['done'] ) || ( 'full' === $job['mode'] && $complete );
			if ( $covered ) {
				self::clear_row_if( self::DIRTY_PREFIX . $b, (string) $tok ); // no-op if re-marked since
			}
		}
		$paths = [];
		foreach ( $buckets as $meta ) {
			$paths = array_merge( $paths, array_column( $meta['shards'], 'path' ) );
		}
		self::patch( [
			'seq'        => $seq,
			'job'        => null,
			'state'      => $complete ? 'ok' : 'partial',
			'last_error' => '',
			'published'  => [
				'seq'             => $seq,
				'dir'             => $job['dir'],
				'manifest_url'    => $job['base'] . 'manifest.json',
				'manifest_sha256' => hash( 'sha256', $json ),
				'location'        => $job['location'],
				'catalog_hash'    => $manifest['catalog_hash'],
				'count'           => $manifest['count'],
				'complete'        => $complete,
				'generated_at'    => $now,
				'time_source'     => $manifest['time_source'],
				'bytes'           => (int) array_sum( array_map( static function ( $m ) { return array_sum( array_column( $m['shards'], 'bytes' ) ); }, $buckets ) ),
				'buckets'         => $buckets,
				'shard_paths'     => $paths,
			],
		] );
		wp_delete_file( $job['dir'] . 'building.part' );
		if ( ! self::eligible() ) { // revoked during publication
			self::delete_all();
			return;
		}
		if ( self::dirty_list() || null !== self::get_row( self::FULL_KEY ) ) {
			self::schedule_build( 60 ); // changes arrived during the job
		}
		if ( get_option( 'kalicart_bridge_well_known_enabled', true ) && class_exists( 'KaliCart_Bridge_Signals' ) ) {
			KaliCart_Bridge_Signals::write_well_known_files(); // pointer last
		}
	}

	private static function prune( string $dir, array $keep ): void {
		foreach ( (array) glob( $dir . 'c-*.jsonl.gz' ) as $f ) {
			if ( ! in_array( basename( $f ), $keep, true ) ) {
				wp_delete_file( $f );
			}
		}
		foreach ( (array) glob( $dir . '*.tmp-*' ) as $f ) {
			wp_delete_file( $f );
		}
	}

	private static function delete_dir_files( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) || 'kalicart' !== basename( rtrim( $dir, '/' ) ) ) {
			return;
		}
		wp_delete_file( $dir . 'manifest.json' ); // manifest first (contract §2)
		foreach ( (array) glob( $dir . 'c-*.jsonl.gz' ) as $f ) {
			wp_delete_file( $f );
		}
		foreach ( [ 'building.part', 'index.php' ] as $f ) {
			wp_delete_file( $dir . $f );
		}
		foreach ( (array) glob( $dir . '*.tmp-*' ) as $f ) {
			wp_delete_file( $f );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- non-recursive: a folder with other files stays.
	}

	/** Revoke, deactivation, uninstall: every snapshot file goes, manifest first. */
	public static function delete_all(): void {
		$s = self::state();
		foreach ( [ $s['published']['dir'] ?? '', $s['job']['dir'] ?? '' ] as $d ) {
			self::delete_dir_files( (string) $d );
		}
		self::delete_dir_files( rtrim( ABSPATH, '/' ) . '/.well-known/kalicart/' );
		$up = wp_upload_dir( null, false );
		if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
			self::delete_dir_files( trailingslashit( $up['basedir'] ) . 'kalicart/' );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BUILD, [], self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK_BUILD );
		wp_clear_scheduled_hook( self::HOOK_DAILY );
		self::delete_markers();
		self::save( [ 'seq' => (int) ( $s['seq'] ?? 0 ), 'state' => 'disabled' ] ); // seq never reused (§15.1)
	}

	// ── outward blocks ─────────────────────────────────────────────────────

	public static function pointer(): ?array {
		$p = self::state()['published'] ?? null;
		if ( ! self::eligible() || ! is_array( $p ) || empty( $p['manifest_url'] ) ) {
			return null;
		}
		return [ 'manifest' => (string) $p['manifest_url'], 'seq' => (int) $p['seq'] ];
	}

	public static function current_state(): string {
		$s = self::state();
		if ( ! self::eligible() ) {
			return 'disabled';
		}
		$p = $s['published'] ?? null;
		if ( ! is_array( $p ) ) {
			return in_array( $s['state'] ?? '', [ 'storage_failure', 'error' ], true ) ? 'storage_failure' : 'disabled';
		}
		[ $now ] = KaliCart_Bridge_Identity::server_now();
		if ( $now - (int) $p['generated_at'] > self::OVERDUE_S ) {
			return 'overdue';
		}
		if ( 'storage_failure' === ( $s['state'] ?? '' ) ) {
			return 'storage_failure';
		}
		return empty( $p['complete'] ) ? 'partial' : 'ok';
	}

	/** Extra heartbeat fields (contract §7, §14.1, §15.3). */
	public static function heartbeat_fields(): array {
		$p     = self::state()['published'] ?? null;
		$block = [ 'schema' => KaliCart_Bridge_Snapshot_Profile::SCHEMA, 'state' => self::current_state() ];
		if ( is_array( $p ) && self::eligible() ) {
			$block += [
				'seq'             => (int) $p['seq'],
				'manifest_url'    => (string) $p['manifest_url'],
				'manifest_sha256' => (string) $p['manifest_sha256'],
				'catalog_hash'    => (string) $p['catalog_hash'],
				'count'           => (int) $p['count'],
				'complete'        => (bool) $p['complete'],
				'location'        => (string) $p['location'],
				'generated_at'    => (int) $p['generated_at'],
			];
		}
		return [
			'snapshot'      => $block,
			'capabilities'  => [ 'snapshot-1', 'partition-id-range-v1' ],
			'store_signals' => [
				'coming_soon' => 'yes' === get_option( 'woocommerce_coming_soon', 'no' ),
				'maintenance' => file_exists( rtrim( ABSPATH, '/' ) . '/.maintenance' ),
			],
		];
	}

	public static function summary(): array {
		$s = self::state();
		$p = is_array( $s['published'] ?? null ) ? $s['published'] : [];
		return [
			'state'        => self::current_state(),
			'seq'          => (int) ( $p['seq'] ?? 0 ),
			'count'        => (int) ( $p['count'] ?? 0 ),
			'buckets'      => count( (array) ( $p['buckets'] ?? [] ) ),
			'dirty'        => count( self::dirty_list() ),
			'generated_at' => (int) ( $p['generated_at'] ?? 0 ),
			'location'     => (string) ( $p['location'] ?? '' ),
			'last_error'   => (string) ( $s['last_error'] ?? '' ),
		];
	}
}
