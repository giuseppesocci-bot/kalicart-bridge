<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Snapshot_Profile — byte-exact profile of the static catalog
 * snapshot (1.0.139). Contract: CONTRATTO-1.0.139-SNAPSHOT §4, §14.3, §15.
 *
 * - Every product line is the JCS (RFC 8785) canonical JSON of the projected
 *   product, UTF-8. The line written to the shard IS the canonical form, so
 *   content_hash = sha256(line) and KaliCart Global can hash the raw bytes.
 * - catalog_hash = sha256 over, for each product in ascending numeric id:
 *   "<decimal id>\n<content_hash>\n".
 * - The manifest is signed (Ed25519, installation key) over JCS(manifest
 *   without "sig"); signature base64url without padding.
 * Pure functions only: no I/O, no WordPress state.
 */
class KaliCart_Bridge_Snapshot_Profile {

	const SCHEMA     = 'kalicart-snapshot/1';
	const PROJECTION = 'federated-1';
	const SHARD_RE   = '/^c-[0-9a-f]{32}\.jsonl\.gz$/';

	/** RFC 8785 canonical JSON. Throws on values JSON cannot represent. */
	public static function jcs( $v ): string {
		if ( null === $v ) {
			return 'null';
		}
		if ( true === $v ) {
			return 'true';
		}
		if ( false === $v ) {
			return 'false';
		}
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		if ( is_float( $v ) ) {
			return self::number( $v );
		}
		if ( is_string( $v ) ) {
			// U+2028/U+2029 literal as in ECMAScript JSON.stringify (collaudo ChatGPT 06:27).
			$s = json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
			if ( false === $s ) {
				throw new InvalidArgumentException( 'invalid UTF-8 string' );
			}
			return $s;
		}
		if ( is_object( $v ) ) {
			$v = get_object_vars( $v );
			if ( ! $v ) {
				return '{}';
			}
			return self::object( $v );
		}
		if ( is_array( $v ) ) {
			if ( ! $v ) {
				return '[]';
			}
			if ( array_keys( $v ) === range( 0, count( $v ) - 1 ) ) {
				return '[' . implode( ',', array_map( [ __CLASS__, 'jcs' ], $v ) ) . ']';
			}
			return self::object( $v );
		}
		throw new InvalidArgumentException( 'unsupported type' );
	}

	private static function object( array $v ): string {
		$keys = array_map( 'strval', array_keys( $v ) );
		// RFC 8785: members sorted by UTF-16 code units. Pure PHP: no mbstring/iconv
		// dependency (both are optional extensions; collaudo ChatGPT 06:34).
		$units = [];
		foreach ( $keys as $k ) {
			$units[ $k ] = self::utf16_units( $k );
		}
		// Lexicographic on code units (PHP's array <=> compares lengths first: not usable).
		usort( $keys, static function ( $a, $b ) use ( $units ) {
			$x = $units[ $a ];
			$y = $units[ $b ];
			$n = min( count( $x ), count( $y ) );
			for ( $i = 0; $i < $n; $i++ ) {
				if ( $x[ $i ] !== $y[ $i ] ) {
					return $x[ $i ] <=> $y[ $i ];
				}
			}
			return count( $x ) <=> count( $y );
		} );
		$out = [];
		foreach ( $keys as $k ) {
			$out[] = self::jcs( $k ) . ':' . self::jcs( $v[ $k ] );
		}
		return '{' . implode( ',', $out ) . '}';
	}

	/** UTF-16 code units of a UTF-8 string (surrogate pairs for astral code points). */
	public static function utf16_units( string $s ): array {
		$out = [];
		$len = strlen( $s );
		for ( $i = 0; $i < $len; ) {
			$c = ord( $s[ $i ] );
			if ( $c < 0x80 ) {
				$cp = $c; $i += 1;
			} elseif ( $c < 0xE0 ) {
				$cp = ( ( $c & 0x1F ) << 6 ) | ( ord( $s[ $i + 1 ] ) & 0x3F ); $i += 2;
			} elseif ( $c < 0xF0 ) {
				$cp = ( ( $c & 0x0F ) << 12 ) | ( ( ord( $s[ $i + 1 ] ) & 0x3F ) << 6 ) | ( ord( $s[ $i + 2 ] ) & 0x3F ); $i += 3;
			} else {
				$cp = ( ( $c & 0x07 ) << 18 ) | ( ( ord( $s[ $i + 1 ] ) & 0x3F ) << 12 ) | ( ( ord( $s[ $i + 2 ] ) & 0x3F ) << 6 ) | ( ord( $s[ $i + 3 ] ) & 0x3F ); $i += 4;
			}
			if ( $cp > 0xFFFF ) {
				$cp   -= 0x10000;
				$out[] = 0xD800 | ( $cp >> 10 );
				$out[] = 0xDC00 | ( $cp & 0x3FF );
			} else {
				$out[] = $cp;
			}
		}
		return $out;
	}

	/** ECMAScript Number::toString of the shortest round-trip digits. */
	public static function number( float $f ): string {
		if ( ! is_finite( $f ) ) {
			throw new InvalidArgumentException( 'non-finite number' );
		}
		if ( 0.0 === $f ) {
			return '0';
		}
		$sign = $f < 0 ? '-' : '';
		// Shortest digits that round-trip, without touching php.ini: the correctly
		// rounded p-digit value is the closest one, so the first p that round-trips
		// gives the same digits as serialize_precision=-1 (checked on 10^6 numbers).
		$a = abs( $f );
		$r = sprintf( '%.16e', $a );
		for ( $p = 1; $p <= 17; $p++ ) {
			$try = sprintf( '%.' . ( $p - 1 ) . 'e', $a );
			if ( (float) $try === $a ) {
				$r = $try;
				break;
			}
		}
		// Split into significant digits and decimal exponent: value = 0.DIGITS x 10^n.
		$exp = 0;
		if ( preg_match( '/^([0-9.]+)[eE]([+-]?\d+)$/', $r, $m ) ) {
			$r   = $m[1];
			$exp = (int) $m[2];
		}
		$parts  = explode( '.', $r );
		$int    = $parts[0];
		$frac   = $parts[1] ?? '';
		$digits = ltrim( $int . $frac, '0' );
		$lead   = strlen( $int . $frac ) - strlen( ltrim( $int . $frac, '0' ) );
		$n      = strlen( $int ) - $lead + $exp;
		$digits = rtrim( $digits, '0' );
		$k      = strlen( $digits );
		if ( $k <= $n && $n <= 21 ) {
			$s = $digits . str_repeat( '0', $n - $k );
		} elseif ( 0 < $n && $n <= 21 ) {
			$s = substr( $digits, 0, $n ) . '.' . substr( $digits, $n );
		} elseif ( -6 < $n && $n <= 0 ) {
			$s = '0.' . str_repeat( '0', -$n ) . $digits;
		} else {
			$e = $n - 1;
			$s = $digits[0] . ( $k > 1 ? '.' . substr( $digits, 1 ) : '' ) . 'e' . ( $e < 0 ? '-' : '+' ) . abs( $e );
		}
		return $sign . $s;
	}

	public static function content_hash( string $line ): string {
		return hash( 'sha256', $line );
	}

	const PARTITION_SCHEME = 'id-range-v1';
	const PARTITION_WIDTH  = 1024;
	const FORMAT_VERSION   = 1;

	/** Bucket of a (parent) product id: fixed id ranges, invariant forever (contract §16.1). */
	public static function bucket_of( int $id ): int {
		return intdiv( max( 0, $id ), self::PARTITION_WIDTH );
	}

	/** @return array{0:int,1:int} first and last id of a bucket */
	public static function bucket_range( int $b ): array {
		return [ $b * self::PARTITION_WIDTH, ( $b + 1 ) * self::PARTITION_WIDTH - 1 ];
	}

	/** bucket_hash = sha256 over "<id>\n<content_hash>\n" in ascending numeric id. */
	public static function bucket_hash( array $by_id ): string {
		ksort( $by_id, SORT_NUMERIC );
		$ctx = hash_init( 'sha256' );
		foreach ( $by_id as $id => $h ) {
			hash_update( $ctx, (string) (int) $id . "\n" . $h . "\n" );
		}
		return hash_final( $ctx );
	}

	/** catalog_hash = sha256 over "<bucket>\n<bucket_hash>\n" in ascending bucket. */
	public static function catalog_hash( array $by_bucket ): string {
		ksort( $by_bucket, SORT_NUMERIC );
		$ctx = hash_init( 'sha256' );
		foreach ( $by_bucket as $b => $h ) {
			hash_update( $ctx, (string) (int) $b . "\n" . $h . "\n" );
		}
		return hash_final( $ctx );
	}

	/** Bytes that the manifest signature covers. */
	public static function signing_input( array $manifest ): string {
		unset( $manifest['sig'] );
		return self::jcs( $manifest );
	}

	public static function shard_name( string $gz_bytes ): string {
		return 'c-' . substr( hash( 'sha256', $gz_bytes ), 0, 32 ) . '.jsonl.gz';
	}
}
