<?php
/** Run: php tests/security/smart-tags.php /path/to/wordpress [plugin-source-root] */
$wp = rtrim( $argv[1] ?? '', '/' ) . '/';
$repo = $argv[2] ?? dirname( __DIR__, 2 );
if ( ! is_file( $wp . 'wp-includes/shortcodes.php' ) ) { fwrite( STDERR, "Supply a WordPress core directory; no site/database is loaded.\n" ); exit( 2 ); }
define( 'ABSPATH', $wp ); define( 'WPINC', 'wp-includes' );
require $wp . 'wp-includes/plugin.php';
require $wp . 'wp-includes/compat.php';
if ( is_file( $wp . 'wp-includes/utf8.php' ) ) { require $wp . 'wp-includes/utf8.php'; }
require $wp . 'wp-includes/formatting.php';
require $wp . 'wp-includes/kses.php';
require $wp . 'wp-includes/http.php';
require $wp . 'wp-includes/shortcodes.php';
function _canonical_charset( $charset ) { return 'UTF-8'; }
function is_utf8_charset( $s = null ) { return true; }
function get_option( $key, $default = false ) { return 'blog_charset' === $key ? 'UTF-8' : $default; }
function wp_allowed_protocols() { return array( 'http', 'https' ); }
function get_the_ID() { return false; }
function url_to_postid( $url ) { return 0; }
function get_the_title( $id ) { return $GLOBALS['test_title'] ?? ''; }
function __( $s, $domain = null ) { return $s; }
function esc_html__( $s, $domain = null ) { return esc_html( $s ); }
function ur_get_ip_address() { return $_SERVER['HTTP_X_REAL_IP']; }
function rest_is_ip_address( $ip ) { return filter_var( $ip, FILTER_VALIDATE_IP ); }
function security_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
$GLOBALS['checks'] = 0;
require $repo . '/includes/class-ur-smart-tags.php';
$smart = new UR_Smart_Tags();
add_shortcode( 'security_probe', function () { return 'EXECUTED'; } );
foreach ( array( '[security_probe]', '[[security_probe]]', str_repeat( '[', 20 ) . 'security_probe' . str_repeat( ']', 20 ), '[security_probe][security_probe]' ) as $payload ) {
	foreach ( array( 'https://example.test/' . $payload, 'https://' . $payload . '/', $payload ) as $url ) {
		$_SERVER['HTTP_REFERER'] = $url;
		foreach ( array( 'referrer_url', 'page_url' ) as $tag ) {
			$result = $smart->process( '{{' . $tag . '}}' );
			security_assert( false === strpos( do_shortcode( do_shortcode( $result ) ), 'EXECUTED' ), 'Header must remain data: ' . $tag );
		}
	}
	$_SERVER['HTTP_X_REAL_IP'] = $payload;
	security_assert( '' === $smart->process( '{{user_ip_address}}' ), 'Reject invalid IP' );
	$GLOBALS['test_title'] = $payload;
	security_assert( false === strpos( do_shortcode( $smart->process( '{{page_title}}' ) ), 'EXECUTED' ), 'Title must remain data' );
}
foreach ( array( 'https://example.test/a?b=c', 'https://[2001:db8::1]/path' ) as $url ) {
	$_SERVER['HTTP_REFERER'] = $url;
	foreach ( array( 'referrer_url', 'page_url' ) as $tag ) { security_assert( $url === html_entity_decode( $smart->process( '{{' . $tag . '}}' ) ), 'Preserve legitimate URL including IPv6' ); }
}
foreach ( array( '192.0.2.1', '2001:db8::1' ) as $ip ) { $_SERVER['HTTP_X_REAL_IP'] = $ip; security_assert( $ip === $smart->process( '{{user_ip_address}}' ), 'Preserve valid IP' ); }
security_assert( 'EXECUTED' === do_shortcode( $smart->process( '[security_probe]' ) ), 'Preserve administrator-authored shortcodes' );
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function ur_format_field_values( $key, $value ) { return $value; }
class UR_Emailer { static function user_data_smart_tags( $email ) { return $GLOBALS['email_values'] ?? array( 'username' => 'tester', 'display_name' => '[[security_probe]]', 'first_name' => '[security_probe]' ); } }
security_assert( false === strpos( do_shortcode( do_shortcode( $smart->process( '{{display_name}} {{first_name}}', array( 'email' => 'test@example.test' ) ) ) ), 'EXECUTED' ), 'Email-context replacement also keeps user names inert' );
function get_user_by( $field, $value ) { return (object) array( 'ID' => 7 ); }
function get_current_user_id() { return 7; }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['split_values'][$key] ?? ''; }
$GLOBALS['split_values'] = array( 'first_name' => '[security_probe', 'last_name' => ']' );
$GLOBALS['email_values'] = array_merge( array( 'username' => 'tester' ), $GLOBALS['split_values'] );
foreach ( array( array(), array( 'email' => 'test@example.test' ) ) as $context ) {
 foreach ( array( '{{first_name}} {{last_name}}', '{{first_name}} ]', '[security_probe {{last_name}}' ) as $template ) {
  security_assert( false === strpos( do_shortcode( do_shortcode( $smart->process( $template, $context ) ) ), 'EXECUTED' ), 'Fragments cannot combine across values or template boundaries' );
 }
}
echo $GLOBALS['checks'] . " assertions passed\n";
