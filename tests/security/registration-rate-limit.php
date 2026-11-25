<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Registration submissions are rate limited per client IP when the limiter is enabled.
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, Universal.Files.SeparateFunctionsFromOO

require __DIR__ . '/bootstrap.php';

const HOUR_IN_SECONDS = 3600;

function apply_filters( $hook, $value ) {
	return $GLOBALS['filters'][ $hook ] ?? $value; }
function get_transient( $key ) {
	return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration ) {
	$GLOBALS['transients'][ $key ] = $value;
	$GLOBALS['ttl'][ $key ]        = $expiration;
	return true; }
function ur_get_ip_address() {
	return $GLOBALS['ip']; }
function wp_create_nonce( $action ) {
	return 'nonce'; }
function is_user_logged_in() {
	return ! empty( $GLOBALS['logged_in'] ); }
class Recording_Logger {
	public function __call( $level, $args ) {
		$GLOBALS['log'][] = $level; }
}
function ur_get_logger() {
	return new Recording_Logger(); }
/**
 * Any call past the guard (captcha, validation, user insert) fails the test.
 *
 * @throws RuntimeException Always.
 */
function ur_string_to_bool() {
	throw new RuntimeException( 'Processing continued after the rate limit rejected the request' ); }
function ur_get_form_setting_by_key() {
	throw new RuntimeException( 'Processing continued after the rate limit rejected the request' ); }

$core = 'includes/functions-ur-core.php';
eval( security_function( $core, 'ur_is_registration_rate_limited' ) );
eval( security_function( $core, 'ur_process_registration' ) );

$GLOBALS['transients'] = array();
$GLOBALS['ip']         = '203.0.113.7';

// Disabled by default: nothing is counted, nothing is rejected.
for ( $i = 0; $i < 50; $i++ ) {
	security_assert( false === ur_is_registration_rate_limited(), 'Limiter is a no-op unless enabled' );
}
security_assert( array() === $GLOBALS['transients'], 'Disabled limiter writes no state' );

// Enabled: N allowed, N+1 rejected.
$GLOBALS['filters'] = array(
	'user_registration_registration_rate_limit_enabled' => true,
	'user_registration_registration_rate_limit_max_attempts' => 3,
	'user_registration_registration_rate_limit_window'  => 600,
);
for ( $i = 1; $i <= 3; $i++ ) {
	security_assert( false === ur_is_registration_rate_limited(), "Submission $i is allowed" );
}
security_assert( true === ur_is_registration_rate_limited(), 'Submission 4 from the same IP is rejected' );
security_assert( true === ur_is_registration_rate_limited(), 'Rejected submissions stay rejected' );
$key = 'ur_reg_rl_' . md5( '203.0.113.7' );
security_assert( 3 === $GLOBALS['transients'][ $key ], 'Rejected submissions are not counted' );
security_assert( 600 === $GLOBALS['ttl'][ $key ], 'Window filter sets the counter lifetime' );

// Another IP has its own budget.
$GLOBALS['ip'] = '203.0.113.8';
security_assert( false === ur_is_registration_rate_limited(), 'A different IP is not limited' );

// Unresolvable IP cannot be keyed, so it is not limited.
$GLOBALS['ip'] = '';
security_assert( false === ur_is_registration_rate_limited(), 'Empty IP is skipped' );

// Zero or negative max still allows at least one attempt.
$GLOBALS['ip'] = '203.0.113.9';
$GLOBALS['filters']['user_registration_registration_rate_limit_max_attempts'] = 0;
security_assert( false === ur_is_registration_rate_limited(), 'A max below 1 is clamped to 1' );
security_assert( true === ur_is_registration_rate_limited(), 'Second submission hits the clamped max' );

// The real handler rejects the over-limit request before any other processing.
$GLOBALS['ip']                 = '203.0.113.7';
$GLOBALS['filters']            = array( 'user_registration_registration_rate_limit_enabled' => true );
$GLOBALS['transients'][ $key ] = 10;
$_POST['form_id']              = '5';
$response                      = security_response( 'ur_process_registration' );
security_assert( false === $response->success, 'Handler returns an error when over the limit' );
security_assert( 'Too many registration attempts. Please try again later.' === $response->data['message'], 'Error message does not reveal the limit or the IP' );

// Under the limit the handler moves on to normal processing (the stub throws once it does).
$GLOBALS['transients'][ $key ] = 0;
$continued                     = false;
try {
	ur_process_registration();
} catch ( RuntimeException $e ) {
	$continued = false !== strpos( $e->getMessage(), 'Processing continued' );
}
security_assert( $continued, 'Handler continues to normal processing when under the limit' );

// Logged-in submitters are never counted or rejected, even over the limit.
$GLOBALS['logged_in']          = true;
$GLOBALS['caps']               = array( 'administrator' );
$GLOBALS['transients'][ $key ] = 10;
$continued                     = false;
try {
	ur_process_registration();
} catch ( RuntimeException $e ) {
	$continued = false !== strpos( $e->getMessage(), 'Processing continued' );
}
security_assert( $continued, 'Logged-in submitters bypass the limiter' );

// A fallback (non-AJAX) submission gets the message stored for the form to render, not a JSON response.
$GLOBALS['logged_in']        = false;
$GLOBALS['caps']             = array();
$GLOBALS['transients']       = array( $key => 10 );
$_POST['ur_fallback_submit'] = '1';
$fallback_response           = null;
try {
	ur_process_registration();
} catch ( SecurityResponse $e ) {
	$fallback_response = $e;
}
security_assert( null === $fallback_response, 'Fallback submissions do not terminate with a JSON response' );
security_assert( array( 'Too many registration attempts. Please try again later.' ) === $GLOBALS['transients']['ur_form_errors_5_nonce'], 'Fallback submissions store the message for the form to show' );
