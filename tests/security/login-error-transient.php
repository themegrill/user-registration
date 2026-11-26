<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: `urm_error` may only reach login-error transients, and a pending-member
 * credential is a server-stored random token that cannot be recomputed.
 *
 * Runs the real `ur_is_login_error_key()` and `AJAX::verify_pending_member_session()`; no site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.Security.NonceVerification

require __DIR__ . '/bootstrap.php';

function get_user_meta( $id, $key, $single ) {
	return UR_PENDING_SESSION_META_KEY === $key ? ( $GLOBALS['usermeta'][ $id ] ?? '' ) : ''; }
function delete_user_meta( $id, $key ) {
	if ( UR_PENDING_SESSION_META_KEY === $key ) {
		unset( $GLOBALS['usermeta'][ $id ] ); } }
define( 'UR_PENDING_SESSION_META_KEY', 'urm_pending_session' );

eval( security_function( 'includes/functions-ur-core.php', 'ur_is_login_error_key' ) );
eval( security_function( 'includes/functions-ur-core.php', 'ur_get_pending_member_token' ) );
eval( 'class PendingSessionRunner { public static ' . security_function( 'modules/membership/includes/AJAX.php', 'verify_pending_member_session' ) . ' }' );

// Key allowlist: only the plugin's own error keys pass.
security_assert( ur_is_login_error_key( 'ur_login_error_' . bin2hex( random_bytes( 16 ) ) ), 'Own error key is accepted' );
security_assert( ur_is_login_error_key( 'ur_login_error_5f3a9c1b2d4e6' ), 'Legacy uniqid error key is accepted' );
foreach ( array( 'urm_pending_login_5', 'ur_login_error_', 'ur_login_error_a b', "ur_login_error_abc\n", '_transient_timeout_ur_login_error_a', 'siteurl', array( 'x' ), '' ) as $bad ) {
	security_assert( ! ur_is_login_error_key( $bad ), 'Foreign key rejected: ' . json_encode( $bad ) );
}

// Credential: a random token in user meta authorizes the browser; the recomputable HMAC and the auto-login transient do not.
$cookie = 'urm_pending_login_7';
$setup  = function ( $token, $expires ) {
	$GLOBALS['usermeta'][7] = array(
		'token'   => $token,
		'expires' => $expires,
	); };
$token  = bin2hex( random_bytes( 32 ) );

$_COOKIE[ $cookie ] = hash_hmac( 'sha256', '7', 'any-salt' );
security_assert( false === PendingSessionRunner::verify_pending_member_session( 7 ), 'No stored token: recomputed HMAC is refused' );

$setup( $token, time() + 3600 );
$_COOKIE[ $cookie ] = $token;
security_assert( true === PendingSessionRunner::verify_pending_member_session( 7 ), 'Matching stored token is accepted' );
security_assert( true === PendingSessionRunner::verify_pending_member_session( 7 ), 'Token survives the auto-login transient being cleared' );

$_COOKIE[ $cookie ] = 'wrong';
security_assert( false === PendingSessionRunner::verify_pending_member_session( 7 ), 'Mismatched cookie is refused' );

$_COOKIE[ $cookie ] = array( 'x' );
security_assert( false === PendingSessionRunner::verify_pending_member_session( 7 ), 'Array cookie is refused without a warning' );

$setup( $token, time() - 1 );
$_COOKIE[ $cookie ] = $token;
security_assert( false === PendingSessionRunner::verify_pending_member_session( 7 ), 'Expired token is refused' );
security_assert( ! isset( $GLOBALS['usermeta'][7] ), 'Expired token is purged' );

// Early strip: an invalid urm_error never reaches a theme override.
eval( security_function( 'includes/functions-ur-core.php', 'ur_strip_invalid_login_error_arg' ) );
$_GET     = array( 'urm_error' => 'urm_pending_login_7' );
$_REQUEST = $_GET;
ur_strip_invalid_login_error_arg();
security_assert( ! isset( $_GET['urm_error'], $_REQUEST['urm_error'] ), 'Foreign urm_error is stripped from GET and REQUEST' );
$_GET = $_REQUEST = array( 'urm_error' => 'ur_login_error_abc123' );
ur_strip_invalid_login_error_arg();
security_assert( 'ur_login_error_abc123' === $_GET['urm_error'], 'Own urm_error is kept' );
$_GET = $_REQUEST = array( 'urm_error' => '<b>ur_login_error_abc123</b>' );
ur_strip_invalid_login_error_arg();
security_assert( ! isset( $_GET['urm_error'], $_REQUEST['urm_error'] ), 'Tag-wrapped urm_error is stripped, not sanitized into validity' );
$_GET     = array();
$_REQUEST = array( 'urm_error' => 'urm_pending_login_7' );
ur_strip_invalid_login_error_arg();
security_assert( ! isset( $_REQUEST['urm_error'] ), 'POST-only urm_error is stripped from REQUEST' );
$_GET = $_REQUEST = array( 'urm_error' => array( 'x' ) );
ur_strip_invalid_login_error_arg();
security_assert( ! isset( $_GET['urm_error'] ), 'Array urm_error is stripped' );
security_assert( ! ur_is_login_error_key( 'ur_login_error_' . str_repeat( 'a', 65 ) ), 'Oversized key is rejected' );
