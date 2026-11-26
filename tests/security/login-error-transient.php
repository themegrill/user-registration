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

$GLOBALS['transients'] = array();
function get_transient( $key ) {
	return $GLOBALS['transients'][ $key ] ?? false; }
function wp_salt( $scheme ) {
	return 'test-salt'; }

eval( security_function( 'includes/functions-ur-core.php', 'ur_is_login_error_key' ) );
eval( 'class PendingSessionRunner { public static ' . security_function( 'modules/membership/includes/AJAX.php', 'verify_pending_member_session' ) . ' }' );

// Key allowlist: only the plugin's own error keys pass.
security_assert( ur_is_login_error_key( 'ur_login_error_' . bin2hex( random_bytes( 16 ) ) ), 'Own error key is accepted' );
security_assert( ur_is_login_error_key( 'ur_login_error_5f3a9c1b2d4e6' ), 'Legacy uniqid error key is accepted' );
foreach ( array( 'urm_pending_login_5', 'ur_login_error_', 'ur_login_error_a b', "ur_login_error_abc\n", '_transient_timeout_ur_login_error_a', 'siteurl', array( 'x' ), '' ) as $bad ) {
	security_assert( ! ur_is_login_error_key( $bad ), 'Foreign key rejected: ' . wp_json_encode_safe( $bad ) );
}
function wp_json_encode_safe( $v ) {
	return json_encode( $v ); }

// Credential: the old recomputable HMAC must not authenticate once the transient is gone.
$hmac                           = hash_hmac( 'sha256', '7', 'test-salt' );
$_COOKIE['urm_pending_login_7'] = $hmac;
security_assert( false === PendingSessionRunner::verify_pending_member_session( 7 ), 'Recomputed HMAC without transient is refused' );

$token                                     = bin2hex( random_bytes( 32 ) );
$GLOBALS['transients']['urm_pending_login_7'] = $token;
$_COOKIE['urm_pending_login_7']            = $token;
security_assert( true === PendingSessionRunner::verify_pending_member_session( 7 ), 'Matching stored token is accepted' );
$_COOKIE['urm_pending_login_7'] = 'wrong';
security_assert( false === PendingSessionRunner::verify_pending_member_session( 7 ), 'Mismatched cookie is refused' );
