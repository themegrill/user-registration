<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Login form ?urm_error= parameter must only read and delete plugin-owned ur_login_error_* transients.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

require __DIR__ . '/bootstrap.php';

class StopAfterNoticeCheck extends RuntimeException {}

$GLOBALS['transients']        = array();
$GLOBALS['transient_reads']   = array();
$GLOBALS['transient_deletes'] = array();
$GLOBALS['notices']           = array();
$GLOBALS['test_filters']      = array();

function get_transient( $transient ) {
	$GLOBALS['transient_reads'][] = $transient;
	return array_key_exists( $transient, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $transient ] : false;
}

function delete_transient( $transient ) {
	$GLOBALS['transient_deletes'][] = $transient;
	unset( $GLOBALS['transients'][ $transient ] );
	return true;
}

function ur_add_notice( $message, $notice_type = 'success' ) {
	$GLOBALS['notices'][] = array(
		'message' => $message,
		'type'    => $notice_type,
	);
}

function apply_filters( $tag, $value ) {
	if ( isset( $GLOBALS['test_filters'][ $tag ] ) ) {
		return ( $GLOBALS['test_filters'][ $tag ] )( $value );
	}
	return $value;
}

// Intercept right after the urm_error block in templates/myaccount/form-login.php.
$GLOBALS['options'] = array();
function ur_run_login_template_error_check( $get_params ) {
	$_GET                         = $get_params;
	$GLOBALS['transient_reads']   = array();
	$GLOBALS['transient_deletes'] = array();
	$GLOBALS['notices']           = array();

	try {
		include dirname( __DIR__, 2 ) . '/templates/myaccount/form-login.php';
	} catch ( StopAfterNoticeCheck $e ) {
		// Expected stop right after the notice handling block.
		return;
	}
}

function ur_option_checked( $option_name, $default = false ) {
	// Stop template execution right after the notice block and before form markup.
	throw new StopAfterNoticeCheck();
}

// 1. Arbitrary WordPress or third-party transient must not be read or deleted.
$GLOBALS['transients'] = array(
	'secret_admin_token' => 'top-secret-value',
);
ur_run_login_template_error_check( array( 'urm_error' => 'secret_admin_token' ) );
security_assert( empty( $GLOBALS['transient_reads'] ), 'Arbitrary transient must not be read via urm_error.' );
security_assert( empty( $GLOBALS['transient_deletes'] ), 'Arbitrary transient must not be deleted via urm_error.' );
security_assert( 'top-secret-value' === ( $GLOBALS['transients']['secret_admin_token'] ?? null ), 'Arbitrary transient must remain stored.' );
security_assert( empty( $GLOBALS['notices'] ), 'Arbitrary transient must not produce a notice.' );

// 2. Other URM transients outside ur_login_error_ must not be read or deleted.
$GLOBALS['transients'] = array(
	'urm_pending_login_42' => 'pending-hash',
);
ur_run_login_template_error_check( array( 'urm_error' => 'urm_pending_login_42' ) );
security_assert( empty( $GLOBALS['transient_reads'] ), 'Non-login-error URM transient must not be read.' );
security_assert( empty( $GLOBALS['transient_deletes'] ), 'Non-login-error URM transient must not be deleted.' );
security_assert( 'pending-hash' === ( $GLOBALS['transients']['urm_pending_login_42'] ?? null ), 'Non-login-error URM transient must remain stored.' );
security_assert( empty( $GLOBALS['notices'] ), 'Non-login-error URM transient must not produce a notice.' );

// 3. Bare prefix without an error suffix must be ignored.
$GLOBALS['transients'] = array(
	'ur_login_error_' => 'bare-prefix-value',
);
ur_run_login_template_error_check( array( 'urm_error' => 'ur_login_error_' ) );
security_assert( empty( $GLOBALS['transient_reads'] ), 'Bare ur_login_error_ prefix must not be read.' );
security_assert( empty( $GLOBALS['transient_deletes'] ), 'Bare ur_login_error_ prefix must not be deleted.' );
security_assert( 'bare-prefix-value' === ( $GLOBALS['transients']['ur_login_error_'] ?? null ), 'Bare prefix transient must remain stored.' );
security_assert( empty( $GLOBALS['notices'] ), 'Bare prefix must not produce a notice.' );

// 4. Non-scalar array input must be ignored safely.
$GLOBALS['transients'] = array(
	'secret_admin_token' => 'top-secret-value',
);
ur_run_login_template_error_check( array( 'urm_error' => array( 'secret_admin_token' ) ) );
security_assert( empty( $GLOBALS['transient_reads'] ), 'Array urm_error input must not read transients.' );
security_assert( empty( $GLOBALS['notices'] ), 'Array urm_error input must not produce a notice.' );

// 5. Valid plugin login error transient must still be displayed and deleted once.
$GLOBALS['transients'] = array(
	'ur_login_error_67432a1b2c3d4' => 'Invalid username or password.',
);
ur_run_login_template_error_check( array( 'urm_error' => 'ur_login_error_67432a1b2c3d4' ) );
security_assert( array( 'ur_login_error_67432a1b2c3d4' ) === $GLOBALS['transient_reads'], 'Valid login error transient should be read.' );
security_assert( array( 'ur_login_error_67432a1b2c3d4' ) === $GLOBALS['transient_deletes'], 'Valid login error transient should be deleted after display.' );
security_assert( ! array_key_exists( 'ur_login_error_67432a1b2c3d4', $GLOBALS['transients'] ), 'Valid login error transient should be removed from storage.' );
security_assert(
	array(
		array(
			'message' => 'Invalid username or password.',
			'type'    => 'error',
		),
	) === $GLOBALS['notices'],
	'Valid login error transient should add an error notice.'
);

// 6. Valid prefix with expired/missing transient still triggers the not-found message filter.
$GLOBALS['transients']   = array();
$GLOBALS['test_filters'] = array(
	'user_registration_login_errors_not_found_message' => function () {
		return 'Login error expired.';
	},
);
ur_run_login_template_error_check( array( 'urm_error' => 'ur_login_error_expired123' ) );
security_assert(
	array(
		array(
			'message' => 'Login error expired.',
			'type'    => 'error',
		),
	) === $GLOBALS['notices'],
	'Missing valid-prefix transient should still use the not-found filter.'
);

// 7. Non-overridable pre-template hook strips invalid urm_error before outdated theme overrides run.
eval( security_function( 'includes/functions-ur-core.php', 'ur_sanitize_login_error_query_param' ) );

$_GET = array( 'urm_error' => 'secret_admin_token' );
ur_sanitize_login_error_query_param( 'myaccount/form-login.php' );
security_assert( ! isset( $_GET['urm_error'] ), 'Pre-template guard must unset arbitrary urm_error values.' );

$_GET = array( 'urm_error' => 'ur_login_error_' );
ur_sanitize_login_error_query_param( 'myaccount/form-login.php' );
security_assert( ! isset( $_GET['urm_error'] ), 'Pre-template guard must unset bare ur_login_error_ prefix.' );

$_GET = array( 'urm_error' => array( 'secret_admin_token' ) );
ur_sanitize_login_error_query_param( 'myaccount/form-login.php' );
security_assert( ! isset( $_GET['urm_error'] ), 'Pre-template guard must unset array urm_error values.' );

$_GET = array( 'urm_error' => 'ur_login_error_67432a1b2c3d4' );
ur_sanitize_login_error_query_param( 'myaccount/form-login.php' );
security_assert( 'ur_login_error_67432a1b2c3d4' === ( $_GET['urm_error'] ?? null ), 'Pre-template guard must preserve valid ur_login_error_* keys.' );

$_GET = array( 'urm_error' => 'secret_admin_token' );
ur_sanitize_login_error_query_param( 'myaccount/dashboard.php' );
security_assert( 'secret_admin_token' === ( $_GET['urm_error'] ?? null ), 'Pre-template guard must only act on myaccount/form-login.php.' );
