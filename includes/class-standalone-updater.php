<?php
/**
 * Standalone update channel for distributions hosted by KaliCart.
 *
 * Removal is intentionally self-contained: delete this file, remove the
 * STANDALONE UPDATER block from kalicart-bridge.php, and remove Update URI
 * from the plugin header.
 */

defined( 'ABSPATH' ) || exit;

final class KaliCart_Bridge_Standalone_Updater {
    private const DEFAULT_MANIFEST_URL = 'https://bridge.kalicart.com/updates/kalicart-bridge.json';
    private const UPDATE_URI          = 'https://bridge.kalicart.com/plugin/kalicart-bridge/';
    private const ALLOWED_HOST        = 'bridge.kalicart.com';

    public static function init(): void {
        add_filter( 'update_plugins_bridge.kalicart.com', [ __CLASS__, 'check_update' ], 10, 4 );
    }

    /**
     * Supply update metadata to WordPress for this plugin only.
     *
     * @param array|false $update      Existing update value.
     * @param array       $plugin_data Parsed plugin headers.
     * @param string      $plugin_file Plugin basename.
     * @param string[]    $locales     Requested locales.
     * @return array|false
     */
    public static function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
        unset( $plugin_data, $locales );

        if ( plugin_basename( KALICART_BRIDGE_FILE ) !== $plugin_file ) {
            return $update;
        }

        $manifest = self::fetch_manifest();
        if ( false === $manifest ) {
            return $update;
        }

        $version = isset( $manifest['version'] ) ? trim( (string) $manifest['version'] ) : '';
        $package = isset( $manifest['download_url'] )
            ? trim( (string) $manifest['download_url'] )
            : trim( (string) ( $manifest['download_link'] ?? '' ) );

        if ( '' === $version || ! preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
            return $update;
        }
        if ( ! version_compare( $version, KALICART_BRIDGE_VERSION, '>' ) ) {
            return false;
        }
        if ( ! self::is_allowed_url( $package ) ) {
            return $update;
        }

        return [
            'id'           => self::UPDATE_URI,
            'slug'         => 'kalicart-bridge',
            'version'      => $version,
            'url'          => esc_url_raw( (string) ( $manifest['homepage'] ?? 'https://bridge.kalicart.com/' ) ),
            'package'      => esc_url_raw( $package ),
            'tested'       => sanitize_text_field( (string) ( $manifest['tested'] ?? '' ) ),
            'requires_php' => sanitize_text_field( (string) ( $manifest['requires_php'] ?? '' ) ),
            'requires'     => sanitize_text_field( (string) ( $manifest['requires'] ?? '' ) ),
            'autoupdate'   => false,
        ];
    }

    /**
     * @return array<string,mixed>|false
     */
    private static function fetch_manifest() {
        $url = defined( 'KALICART_BRIDGE_UPDATE_MANIFEST_URL' )
            ? (string) KALICART_BRIDGE_UPDATE_MANIFEST_URL
            : self::DEFAULT_MANIFEST_URL;
        $url = (string) apply_filters( 'kalicart_bridge_update_manifest_url', $url );

        if ( ! self::is_allowed_url( $url ) ) {
            return false;
        }

        $response = wp_remote_get( $url, [
            'timeout'             => 8,
            'redirection'         => 2,
            'sslverify'           => true,
            'limit_response_size' => 1024 * 1024,
            'headers'             => [
                'Accept'     => 'application/json',
                'User-Agent' => 'KaliCart-Bridge/' . KALICART_BRIDGE_VERSION,
            ],
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $manifest = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $manifest ) ? $manifest : false;
    }

    private static function is_allowed_url( string $url ): bool {
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return false;
        }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        return 'https' === $scheme && self::ALLOWED_HOST === $host;
    }
}
