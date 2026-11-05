<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

require __DIR__ . '/bootstrap.php';
function wp_get_referer() {
	return $_REQUEST['_wp_http_referer'] ?? ''; }
function wp_create_nonce( $action ) {
	return 'nonce:' . $action; }
eval( 'class SecurityNonce { public static ' . security_function( 'includes/class-ur-ajax.php', 'get_recent_nonce' ) . '}' );
function refresh( $post ) {
	$_POST = $post;
	return security_response( array( 'SecurityNonce', 'get_recent_nonce' ) ); }
$_REQUEST = array( '_wp_http_referer' => 'https://example.test/' );
security_assert( ! refresh( array( 'nonce_for' => 'unexpected' ) )->success, 'Do not mint login nonce for arbitrary actions' );
$_REQUEST = array();
security_assert( refresh( array( 'nonce_for' => 'login' ) )->success, 'Guest login refresh without a referer remains supported' );
security_assert( 'nonce:ur_login_form_save_nonce' === refresh( array( 'nonce_for' => 'login' ) )->data, 'Exact login nonce action' );
security_assert( ! refresh( array( 'nonce_for' => 'registration', 'form_ids' => '1' ) )->success, 'Registration no longer uses a nonce' );
security_assert( ! refresh( array() )->success, 'Reject missing action' );
security_assert( ! refresh( array( 'nonce_for' => array() ) )->success, 'Reject nonscalar action' );
