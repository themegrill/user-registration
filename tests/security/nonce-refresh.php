<?php
require __DIR__ . '/bootstrap.php';
function wp_get_referer() { return $_REQUEST['_wp_http_referer'] ?? ''; }
function home_url() { return 'https://example.test'; }
function wp_create_nonce( $action ) { return 'nonce:' . $action; }
function ur_get_form_fields( $id ) { return in_array( (int) $id, array( 1, 2, 4 ), true ) ? array( 'email' ) : array(); }
function get_post( $id ) { return (object) array( 'post_type' => 3 === (int) $id ? 'page' : 'user_registration', 'post_status' => 2 === (int) $id ? 'draft' : 'publish' ); }
eval( 'class SecurityNonce { public static ' . security_function( 'includes/class-ur-ajax.php', 'get_recent_nonce' ) . '}' );
function refresh( $post ) { $_POST = $post; return security_response( array( 'SecurityNonce', 'get_recent_nonce' ) ); }
$_REQUEST = array( '_wp_http_referer' => 'https://example.test/' );
security_assert( ! refresh( array( 'nonce_for' => 'unexpected' ) )->success, 'Do not mint login nonce for arbitrary actions' );
$_REQUEST = array();
security_assert( refresh( array( 'nonce_for' => 'login' ) )->success, 'Guest login refresh without a referer remains supported' );
security_assert( refresh( array( 'nonce_for' => 'registration', 'form_ids' => '1' ) )->success, 'Guest published form refresh' );
foreach ( array( '2', '3', '-1', '1abc', '0', '1,3', implode( ',', range( 1, 101 ) ) ) as $ids ) {
	security_assert( ! refresh( array( 'nonce_for' => 'registration', 'form_ids' => $ids ) )->success, 'Reject invalid, inaccessible or excessive form IDs' );
}
security_assert( ! refresh( array( 'nonce_for' => array() ) )->success, 'Reject nonscalar action' );
security_assert( ! refresh( array( 'nonce_for' => 'registration', 'form_ids' => array() ) )->success, 'Reject nonscalar IDs' );
$GLOBALS['caps'] = array( 'edit_post' );
security_assert( refresh( array( 'nonce_for' => 'registration', 'form_ids' => '2' ) )->success, 'Authorized form preview can refresh draft' );

$GLOBALS['caps'] = array();
security_assert( 'nonce:ur_login_form_save_nonce' === refresh( array( 'nonce_for' => 'login' ) )->data, 'Exact login nonce action' );
security_assert( array( 1 => 'nonce:ur_frontend_form_id-1', 4 => 'nonce:ur_frontend_form_id-4' ) === refresh( array( 'nonce_for' => 'registration', 'form_ids' => '1,4' ) )->data, 'Return per-form nonce map' );
