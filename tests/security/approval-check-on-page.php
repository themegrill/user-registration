<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * The page-load approval check logs out members who are not approved, in both approval modes.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName

require __DIR__ . '/bootstrap.php';

$GLOBALS['state'] = array();

function is_user_logged_in() {
	return true; }
function get_current_user_id() {
	return 7; }
function ur_get_form_id_by_userid( $user_id ) {
	return 12; }
function ur_get_user_login_option( $user_id ) {
	return $GLOBALS['state']['option']; }
function ur_get_user_approval_status( $user_id ) {
	return $GLOBALS['state']['status']; }
function add_action() {}
function do_action() {}
function apply_filters( $hook, $value ) {
	return $value; }
function wp_logout() {
	$GLOBALS['state']['logged_out'] = true; }
class UR_Admin_User_Manager {
	/** Loose comparison on purpose, like the real manager: the stored status is the string '1'. */
	public function is_approved() {
		return 1 == $GLOBALS['state']['status']; }
}

eval( 'class SecurityApprovalCheck {' . security_function( 'includes/class-ur-user-approval.php', 'check_status_on_page' ) . '}' );

function approval_check_logs_out( $option, $status, $caps = array() ) {
	$GLOBALS['state'] = array(
		'option' => $option,
		'status' => $status,
	);
	$GLOBALS['caps']  = $caps;
	( new SecurityApprovalCheck() )->check_status_on_page();

	return ! empty( $GLOBALS['state']['logged_out'] );
}

foreach ( array( 'admin_approval', 'admin_approval_after_email_confirmation' ) as $approval_mode ) {
	security_assert( ! approval_check_logs_out( $approval_mode, '1' ), $approval_mode . ': approved member stays logged in' );
	security_assert( approval_check_logs_out( $approval_mode, '0' ), $approval_mode . ': pending member is logged out' );
	security_assert( approval_check_logs_out( $approval_mode, '-1' ), $approval_mode . ': denied member is logged out' );
	security_assert( ! approval_check_logs_out( $approval_mode, '0', array( 'manage_options' ) ), $approval_mode . ': admins are never logged out' );
}
security_assert( ! approval_check_logs_out( 'default', '0' ), 'Sites without admin approval are not affected' );
