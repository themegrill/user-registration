<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a pending-member credential is a server-stored random token that cannot be recomputed.
 *
 * Runs the real `ur_is_valid_login_error_key()` and `AJAX::verify_pending_member_session()`; no site or database is loaded.
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

eval( security_function( 'includes/functions-ur-core.php', 'ur_is_valid_login_error_key' ) );
eval( security_function( 'includes/functions-ur-core.php', 'ur_get_pending_member_token' ) );
eval( 'class PendingSessionRunner { public static ' . security_function( 'modules/membership/includes/AJAX.php', 'verify_pending_member_session' ) . ' }' );

// The random login-error key passes the shared allowlist.
security_assert( ur_is_valid_login_error_key( 'ur_login_error_' . bin2hex( random_bytes( 16 ) ) ), 'Random error key is accepted' );

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
