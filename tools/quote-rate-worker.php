<?php
// Standalone cross-process rate-guard fixture. No WordPress or merchant load.
define( 'ABSPATH', __DIR__ );
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function home_url( $path = '/' ) { return 'https://quote-rate-fixture.invalid' . $path; }
function get_current_blog_id() { return 1; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class WP_REST_Response {
	private $data;
	private $status;
	public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_status() { return $this->status; }
	public function get_data() { return $this->data; }
	public function header( $name, $value ) {}
}
require_once __DIR__ . '/../includes/class-checkout-quote.php';
$method = new ReflectionMethod( KaliCart_Bridge_Quote::class, 'rate_limit' );
$result = $method->invoke( null );
echo ( true === $result ? 'accepted' : $result->get_status() . ':' . ( $result->get_data()['reason'] ?? 'unknown' ) ) . "\n";
