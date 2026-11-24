<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Profile save handlers must never allow a user to change their own password without the current password.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Security.NonceVerification.Missing, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

require __DIR__ . '/bootstrap.php';

class SecurityRedirect extends RuntimeException {}

function get_user_meta( $user_id, $key = '', $single = false ) {
	if ( 'ur_form_id' === $key ) {
		return array( 10 );
	}
	return array();
}
function wp_get_current_user() {
	return (object) array(
		'ID'         => $GLOBALS['current_user_id'],
		'roles'      => $GLOBALS['current_user_roles'],
		'user_email' => 'member@example.com',
	);
}
function get_current_user_id() {
	return (int) $GLOBALS['current_user_id'];
}
function get_user_by( $field, $value ) {
	return (object) array(
		'ID'   => (int) $value,
		'data' => (object) array(
			'user_email'   => 'member@example.com',
			'display_name' => 'Member',
			'user_pass'    => '$P$Bexistinghash',
		),
	);
}
function apply_filters( $tag, $value ) {
	if ( isset( $GLOBALS['test_filters'][ $tag ] ) ) {
		return ( $GLOBALS['test_filters'][ $tag ] )( $value );
	}
	return $value;
}
function do_action() {}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function ur_get_field_type( $field_key ) {
	return 'user_pass' === $field_key ? 'password' : 'text';
}
function ur_string_to_bool( $value ) {
	return in_array( $value, array( true, 1, '1', 'true', 'yes' ), true );
}
function ur_string_translation( $form_id, $key, $default ) {
	return $default;
}
function ur_get_user_profile_field_only() {
	return array( 'user_email', 'display_name', 'first_name' );
}
function ur_get_user_table_fields() {
	return array( 'user_email', 'user_pass', 'display_name' );
}
function ur_get_registered_user_meta_fields() {
	return array( 'first_name', 'last_name' );
}
function ur_get_form_field_data( $form_id ) {
	return array(
		(object) array(
			'field_key'       => 'display_name',
			'general_setting' => (object) array(
				'field_name' => 'display_name',
				'label'      => 'Display Name',
			),
		),
		(object) array(
			'field_key'       => 'user_pass',
			'general_setting' => (object) array(
				'field_name' => 'user_pass',
				'label'      => 'User Password',
			),
		),
	);
}
function user_registration_sanitize_profile_update( $post, $field_key, $key ) {
	return isset( $post[ $key ] ) ? $post[ $key ] : '';
}
function ur_clean( $value ) {
	return $value;
}
function ur_notice_count( $notice_type = '' ) {
	return 0;
}
function ur_add_notice( $message, $notice_type = 'success' ) {}
function ur_get_account_endpoint_url( $endpoint ) {
	return 'https://example.com/my-account/' . $endpoint;
}
function wp_safe_redirect( $location ) {
	throw new SecurityRedirect( $location );
}
function sanitize_email( $email ) {
	return trim( (string) $email );
}
function is_wp_error( $thing ) {
	return false;
}
function wp_update_user( $userdata ) {
	$GLOBALS['updated_users'][] = $userdata;
	return $userdata['ID'];
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['updated_meta'][ $key ] = $value;
}
function UR() {
	return (object) array(
		'form' => new class() {
			public function get_form() {
				return array(
					array(
						array(
							(object) array(
								'field_key'       => 'display_name',
								'general_setting' => (object) array(
									'field_name' => 'display_name',
									'label'      => 'Display Name',
									'required'   => '0',
								),
								'advance_setting' => (object) array(),
							),
							(object) array(
								'field_key'       => 'user_email',
								'general_setting' => (object) array(
									'field_name' => 'user_email',
									'label'      => 'User Email',
									'required'   => '1',
								),
								'advance_setting' => (object) array(),
							),
							(object) array(
								'field_key'       => 'user_pass',
								'general_setting' => (object) array(
									'field_name' => 'user_pass',
									'label'      => 'User Password',
									'required'   => '1',
								),
								'advance_setting' => (object) array(),
							),
						),
					),
				);
			}
		},
	);
}

eval( security_function( 'includes/functions-ur-template.php', 'user_registration_form_data' ) );
eval( security_function( 'includes/functions-ur-core.php', 'urm_update_user_profile_data' ) );
eval( 'class SecurityFormHandler { public static ' . security_function( 'includes/class-ur-form-handler.php', 'save_profile_details' ) . ' }' );

// 1. Subscriber spoofing is_admin_user=1 on AJAX profile save must not expose user_pass.
$GLOBALS['current_user_id']    = 5;
$GLOBALS['current_user_roles'] = array( 'subscriber' );
$GLOBALS['caps']               = array();
$_REQUEST                      = array(
	'action'        => 'user_registration_update_profile_details',
	'is_admin_user' => '1',
);
$fields                        = user_registration_form_data( 5, 10 );
security_assert( ! isset( $fields['user_registration_user_pass'] ), 'Subscriber spoofing is_admin_user=1 on AJAX profile save must not get user_pass' );

// 2. Subscriber spoofing is_admin_user=1 on non-AJAX profile save must not expose user_pass.
$_REQUEST = array(
	'action'        => 'save_profile_details',
	'is_admin_user' => '1',
);
$fields   = user_registration_form_data( 5, 10 );
security_assert( ! isset( $fields['user_registration_user_pass'] ), 'Subscriber spoofing is_admin_user=1 on non-AJAX profile save must not get user_pass' );

// 3. Malformed array action parameter must not raise a type error or expose user_pass.
$_REQUEST = array(
	'action' => array( 'edit' ),
);
$fields   = user_registration_form_data( 5, 10 );
security_assert( ! isset( $fields['user_registration_user_pass'] ), 'Array action parameter is handled safely without exposing user_pass' );

// 4. Admin editing own profile (even with string user_id and action=edit or is_admin_user=1) must not get user_pass.
$GLOBALS['current_user_id']    = 1;
$GLOBALS['current_user_roles'] = array( 'administrator' );
$GLOBALS['caps']               = array( 'edit_user' );
$_REQUEST                      = array(
	'action'        => 'edit',
	'is_admin_user' => '1',
);
$fields                        = user_registration_form_data( '1', 10 );
security_assert( ! isset( $fields['user_registration_user_pass'] ), 'Admin editing own profile must not get user_pass' );

// 5. Subscriber without edit_user capability passing another user_id with action=edit must not get user_pass.
$GLOBALS['current_user_id']    = 5;
$GLOBALS['current_user_roles'] = array( 'subscriber' );
$GLOBALS['caps']               = array();
$_REQUEST                      = array(
	'action' => 'edit',
);
$fields                        = user_registration_form_data( 1, 10 );
security_assert( ! isset( $fields['user_registration_user_pass'] ), 'Unprivileged user must not get user_pass for another user' );

// 6. Admin viewing/editing another user in wp-admin still gets user_pass.
$GLOBALS['current_user_id']    = 1;
$GLOBALS['current_user_roles'] = array( 'administrator' );
$GLOBALS['caps']               = array( 'edit_user' );
$_REQUEST                      = array(
	'action' => 'edit',
);
$fields                        = user_registration_form_data( 5, 10 );
security_assert( isset( $fields['user_registration_user_pass'] ), 'Admin editing another user still gets user_pass' );

// 7. Admin saving another user via AJAX still gets user_pass without needing client is_admin_user flag.
$_REQUEST = array(
	'action' => 'user_registration_update_profile_details',
);
$fields   = user_registration_form_data( 5, 10 );
security_assert( isset( $fields['user_registration_user_pass'] ), 'Admin saving another user via AJAX still gets user_pass' );

// 8. Admin calling user_registration_form_data on unrelated action does not expose user_pass.
$_REQUEST = array(
	'action' => 'export_users',
);
$fields   = user_registration_form_data( 5, 10 );
security_assert( ! isset( $fields['user_registration_user_pass'] ), 'Unrelated admin actions must not expose user_pass' );

// 9. Defense-in-depth: urm_update_user_profile_data blocks self password changes even if user_pass is in $profile.
$GLOBALS['current_user_id']    = 5;
$GLOBALS['current_user_roles'] = array( 'subscriber' );
$GLOBALS['caps']               = array( 'edit_user' );
$GLOBALS['updated_users']      = array();
$profile                       = array(
	'user_registration_display_name' => array( 'field_key' => 'display_name' ),
	'user_registration_user_pass'    => array( 'field_key' => 'user_pass' ),
);
$submitted                     = array(
	'user_registration_display_name' => 'Updated Name',
	'user_registration_user_pass'    => 'HackedPass!123',
);
$user                          = (object) array(
	'ID'         => 5,
	'user_email' => 'member@example.com',
);
urm_update_user_profile_data( $user, $profile, $submitted, 10 );
security_assert( 1 === count( $GLOBALS['updated_users'] ), 'Self profile save still updates allowed fields' );
security_assert( ! array_key_exists( 'user_pass', $GLOBALS['updated_users'][0] ), 'urm_update_user_profile_data must never write user_pass for the current user' );

// 10. Defense-in-depth: urm_update_user_profile_data still allows admin to update another user's password.
$GLOBALS['current_user_id']    = 1;
$GLOBALS['current_user_roles'] = array( 'administrator' );
$GLOBALS['caps']               = array( 'edit_user' );
$GLOBALS['updated_users']      = array();
urm_update_user_profile_data( $user, $profile, $submitted, 10 );
security_assert( 'HackedPass!123' === ( $GLOBALS['updated_users'][0]['user_pass'] ?? null ), 'Admin updating another user can still update user_pass' );

// 11. Defense-in-depth: urm_update_user_profile_data blocks user_pass for another user without edit_user capability.
$GLOBALS['current_user_id']    = 2;
$GLOBALS['current_user_roles'] = array( 'subscriber' );
$GLOBALS['caps']               = array();
$GLOBALS['updated_users']      = array();
urm_update_user_profile_data( $user, $profile, $submitted, 10 );
security_assert( ! array_key_exists( 'user_pass', $GLOBALS['updated_users'][0] ), 'Unprivileged caller must never write user_pass for another user' );

// 12. Non-AJAX save_profile_details executes end-to-end and omits user_pass even when injected into $profile.
$GLOBALS['current_user_id']    = 5;
$GLOBALS['current_user_roles'] = array( 'subscriber' );
$GLOBALS['caps']               = array();
$GLOBALS['updated_users']      = array();
$GLOBALS['test_filters']       = array(
	'user_registration_before_save_profile_details' => function ( $filtered_profile ) {
		$filtered_profile['user_registration_user_pass'] = array( 'field_key' => 'user_pass' );
		return $filtered_profile;
	},
);
$_SERVER['REQUEST_METHOD']     = 'POST';
$_POST                         = array(
	'action'                         => 'save_profile_details',
	'_wpnonce'                       => 'valid',
	'is_admin_user'                  => '1',
	'user_registration_display_name' => 'Safe Name',
	'user_registration_user_pass'    => 'HackedPass!123',
);
$_REQUEST                      = $_POST;
$redirected                    = false;
try {
	SecurityFormHandler::save_profile_details();
} catch ( SecurityRedirect $e ) {
	$redirected = true;
}
security_assert( $redirected, 'Non-AJAX save_profile_details completed and redirected' );
security_assert( 1 === count( $GLOBALS['updated_users'] ), 'Non-AJAX save_profile_details called wp_update_user for allowed fields' );
security_assert( 'Safe Name' === ( $GLOBALS['updated_users'][0]['display_name'] ?? null ), 'Non-AJAX save_profile_details still saved display_name' );
security_assert( ! array_key_exists( 'user_pass', $GLOBALS['updated_users'][0] ), 'Non-AJAX save_profile_details omitted user_pass from wp_update_user payload' );
