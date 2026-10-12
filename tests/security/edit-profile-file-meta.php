<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: rendering a member's edit screen must not write to the viewing admin's file meta.
 *
 * Runs the real `user_registration_edit_profile_row_template()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, Squiz.Commenting.FunctionComment.MissingParamTag

require __DIR__ . '/bootstrap.php';

$existing_file = tempnam( sys_get_temp_dir(), 'ur-file-meta-' );
file_put_contents( $existing_file, 'x' );

function get_current_user_id() {
	return $GLOBALS['viewer']; }
function is_admin() {
	return $GLOBALS['in_admin']; }
function ur_get_form_id_by_userid( $user_id ) {
	$GLOBALS['form_lookups'][] = $user_id;
	return 5; }
function ur_string_to_bool( $value ) {
	return in_array( $value, array( true, 1, '1', 'yes', 'true' ), true ); }
function ur_readonly_profile_details_fields() {
	return array(); }
function ur_clean( $value ) {
	return $value; }
function ur_get_required_fields() {
	return array(); }
function ur_string_translation( $form_id, $key, $value ) {
	return $value; }
function apply_filters( $tag, $value ) {
	return $value; }
function get_attached_file( $attachment_id ) {
	return 11 === (int) $attachment_id ? $GLOBALS['existing_file'] : ''; }
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['meta_writes'][] = array( $user_id, $key, $value );
	return true; }
function user_registration_form_field( $key, $field, $value, $row_count, $is_edit ) {
	return $field; }

$GLOBALS['existing_file'] = $existing_file;
eval( security_function( 'includes/functions-ur-core.php', 'user_registration_edit_profile_row_template' ) );

/** A file field followed by a text field, like a real form row. */
function profile_row( $value ) {
	$item = function ( $key, $name ) {
		return (object) array(
			'field_key'       => $key,
			'general_setting' => (object) array(
				'field_name' => $name,
				'label'      => $name,
			),
			'advance_setting' => (object) array(),
		);
	};
	return array(
		array( $item( 'file', 'file_1' ), $item( 'text', 'about' ) ),
		array(
			'user_registration_file_1' => array(
				'field_key' => 'file',
				'value'     => $value,
			),
			'user_registration_about'  => array(
				'field_key' => 'text',
				'value'     => 'hello',
			),
		),
	);
}

function render_row( $value, $viewer, $in_admin, $request, $caps ) {
	$GLOBALS['viewer']       = $viewer;
	$GLOBALS['in_admin']     = $in_admin;
	$GLOBALS['caps']         = $caps;
	$GLOBALS['meta_writes']  = array();
	$GLOBALS['form_lookups'] = array();
	$_REQUEST                = $request;
	list( $data, $profile )  = profile_row( $value );
	ob_start();
	user_registration_edit_profile_row_template( array( $data ), $profile );
	ob_end_clean();
}

$admin  = 1;
$member = 7;

$edit_request = array(
	'user_id' => $member,
	'action'  => 'edit',
);
$view_request = array(
	'user_id' => $member,
	'action'  => 'view',
);

// Admin opens a member's edit screen; the member's meta has a deleted attachment (12) among a live one (11).
render_row( '11,12', $admin, true, $edit_request, array( 'edit_user' ) );
security_assert( array( array( $member, 'user_registration_file_1', '11' ) ) === $GLOBALS['meta_writes'], 'The cleaned value is written to the member' );
security_assert( ! in_array( $admin, array_column( $GLOBALS['meta_writes'], 0 ), true ), 'The viewing admin\'s own file meta is never written' );
security_assert( array( $member ) === array_values( array_unique( $GLOBALS['form_lookups'] ) ), 'Fields after the file field still use the member\'s form, not the admin\'s' );

// The view action takes the same path.
render_row( '12', $admin, true, $view_request, array( 'edit_user' ) );
security_assert( array( array( $member, 'user_registration_file_1', '' ) ) === $GLOBALS['meta_writes'], 'Viewing a member with only missing files clears the member, not the admin' );

// Nothing was dropped: loading the page writes nothing at all.
render_row( '11', $admin, true, $edit_request, array( 'edit_user' ) );
security_assert( array() === $GLOBALS['meta_writes'], 'No write when every file still exists' );

// The capability is still required for the profile owner being written.
render_row( '11,12', $admin, true, $edit_request, array() );
security_assert( array() === $GLOBALS['meta_writes'], 'No write without edit_user' );

// Frontend Edit Profile: the profile is the current user's own.
render_row( '11,12', $member, false, array(), array( 'edit_user' ) );
security_assert( array( array( $member, 'user_registration_file_1', '11' ) ) === $GLOBALS['meta_writes'], 'Frontend Edit Profile cleans the current user' );

// An admin editing their own profile in wp-admin keeps working.
render_row( '11,12', $admin, true, array(), array( 'edit_user' ) );
security_assert( array( array( $admin, 'user_registration_file_1', '11' ) ) === $GLOBALS['meta_writes'], 'An admin\'s own profile is cleaned for the admin' );

// The same member and viewer through the frontend template never touch another user.
render_row( '11,12', $admin, false, array( 'user_id' => $member ), array( 'edit_user' ) );
security_assert( array( $admin ) === array_column( $GLOBALS['meta_writes'], 0 ), 'The frontend template only ever writes the current user' );

unlink( $existing_file );
