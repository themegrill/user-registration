<?php
/** Isolated handler tests. No database or WordPress site is loaded. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ . '/' );
function security_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['security_assertions'] = ( $GLOBALS['security_assertions'] ?? 0 ) + 1;
}
register_shutdown_function( function () { if ( ! error_get_last() ) { echo ( $GLOBALS['security_assertions'] ?? 0 ) . " assertions completed\n"; } } );
/** Load exact handler bodies without booting unrelated plugin hooks. */
function security_function( $relative, $name ) {
	$tokens = token_get_all( file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative ) );
	for ( $i = 0; $i < count( $tokens ); ++$i ) {
		if ( ! is_array( $tokens[$i] ) || T_FUNCTION !== $tokens[$i][0] ) { continue; }
		$j = $i + 1;
		while ( is_array( $tokens[$j] ) && T_WHITESPACE === $tokens[$j][0] ) { ++$j; }
		if ( ! is_array( $tokens[$j] ) || $name !== $tokens[$j][1] ) { continue; }
		$code = ''; $depth = 0; $started = false;
		for ( $k = $i; $k < count( $tokens ); ++$k ) {
			$token = $tokens[$k]; $code .= is_array( $token ) ? $token[1] : $token;
			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { ++$depth; $started = true; }
			if ( '}' === $token && 0 === --$depth && $started ) { return $code; }
		}
	}
	throw new RuntimeException( 'Missing source function: ' . $name );
}
class SecurityResponse extends RuntimeException {
	public $success; public $data; public $status;
	public function __construct( $success, $data, $status = null ) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
function wp_send_json_error( $data = null, $status = null ) { throw new SecurityResponse( false, $data, $status ); }
function wp_send_json_success( $data = null, $status = null ) { throw new SecurityResponse( true, $data, $status ); }
function security_response( $callback ) { try { $callback(); } catch ( SecurityResponse $e ) { return $e; } throw new RuntimeException( 'Missing response' ); }
function __( $s, $domain = null ) { return $s; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'] ?? array(), true ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function update_option( $key, $value ) { $GLOBALS['writes'][$key] = $value; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function wp_verify_nonce( $value, $action ) { return 'valid' === $value; }
function check_ajax_referer( $action, $key ) { if ( ! wp_verify_nonce( $_POST[$key] ?? '', $action ) ) { wp_send_json_error( 'nonce', 403 ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
