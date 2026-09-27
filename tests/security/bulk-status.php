<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.WP.GlobalVariablesOverride.Prohibited

require __DIR__ . '/bootstrap.php';
function check_admin_referer( $action ) {
	security_assert( 'bulk-users' === $action, 'Use native users form nonce' );
	if ( 'valid' !== ( $_REQUEST['_wpnonce'] ?? '' ) ) {
		throw new SecurityResponse( false, 'nonce', 403 ); } }
function _get_list_table( $name ) {
	return new class() { public function current_action() {
			return $_REQUEST['action'];
	} }; }
function get_current_user_id() {
	return 1; }
function wp_safe_redirect( $url ) {
	throw new SecurityResponse( true, $url ); }
function add_query_arg( $key, $value, $url = null ) {
	return $url; }
function ur_resend_verification_email( $id ) {
	$GLOBALS['writes'][ $id ] = 'resent'; }
class UR_Admin_User_Manager {
	const APPROVED = 1;
	const DENIED   = -1;
	private $id;
	public function __construct( $id ) {
		$this->id = $id; }
	public static function is_user_allowed_to_change_status() {
		return current_user_can( 'manage_options' ); }
	public function can_status_be_changed_by( $id ) {
		return 1 !== $this->id; }
	public function save_status( $status ) {
		$GLOBALS['writes'][ $this->id ] = $status; }
	public function is_email_pending() {
		return true; }
}
eval( 'class SecurityUsers {' . security_function( 'includes/admin/class-ur-admin-user-list-manager.php', 'trigger_bulk_action' ) . '}' );
$handler         = new SecurityUsers();
$GLOBALS['caps'] = array( 'manage_options' );
foreach ( array( 'approve', 'deny', 'await_confirmation' ) as $action ) {
	foreach ( array( '', 'invalid', 'valid' ) as $nonce ) {
		$_REQUEST          = array(
			'action'   => $action,
			'users'    => array( 7 ),
			'new_role' => '',
			'_wpnonce' => $nonce,
		);
		$GLOBALS['writes'] = array();
		$response          = security_response( array( $handler, 'trigger_bulk_action' ) );
		security_assert( ( 'valid' === $nonce ) === $response->success, 'Reject forged bulk action' );
		security_assert( empty( $GLOBALS['writes'] ) === ( 'valid' !== $nonce ), 'No mutation before nonce validation' );
	}
}
$_REQUEST = array( 'action' => 'unrelated' );
$handler->trigger_bulk_action();
$GLOBALS['caps']   = array();
$_REQUEST          = array(
	'action'   => 'deny',
	'users'    => array( 7 ),
	'_wpnonce' => 'valid',
);
$GLOBALS['writes'] = array();
$rejected          = false;
try {
	$handler->trigger_bulk_action();
} catch ( Exception $e ) {
	$rejected = true; }
security_assert( $rejected && empty( $GLOBALS['writes'] ), 'Unauthorized callers are rejected without writes' );
$GLOBALS['caps'] = array( 'manage_options' );
$_REQUEST        = array(
	'action'   => 'deny',
	'users'    => array( 1, 7 ),
	'_wpnonce' => 'valid',
);
security_response( array( $handler, 'trigger_bulk_action' ) );
security_assert( ! isset( $GLOBALS['writes'][1] ) && isset( $GLOBALS['writes'][7] ), 'Keep per-user restrictions for mixed selections' );
