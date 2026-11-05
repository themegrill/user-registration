<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Checkbox values saved from the profile page keep only the field's options and never carry markup.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

require __DIR__ . '/bootstrap.php';

function wp_kses( $value, $allowed ) {
	return strip_tags( $value, '<strong><em><br>' ); }
function ur_string_to_bool( $value ) {
	return in_array( $value, array( true, 1, '1', 'true', 'yes' ), true ); }
eval( security_function( 'includes/functions-ur-core.php', 'ur_sanitize_tooltip' ) );
eval( security_function( 'includes/functions-ur-core.php', 'ur_sanitize_checkbox_profile_value' ) );
eval( security_function( 'includes/functions-ur-core.php', 'urm_process_profile_fields' ) );
function do_action() {}
function wp_json_encode( $value ) {
	return json_encode( $value ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
function ur_get_form_field_data( $form_id ) {
	return $GLOBALS['form_fields']; }

$payload = '<span class="user-registration-help-tip" data-tip="&lt;img src=x onerror=alert(1)&gt;">x</span>';
$field   = (object) array(
	'general_setting' => (object) array( 'options' => array( 'Red', 'Blue' ) ),
	'advance_setting' => (object) array(),
);

security_assert( array( 'Red' ) === ur_sanitize_checkbox_profile_value( array( 'Red', $payload ), $field ), 'A payload that is not one of the options is dropped' );
security_assert( array( 'Red', 'Blue' ) === ur_sanitize_checkbox_profile_value( '["Red","Blue"]', $field ), 'A JSON string from the AJAX update is checked the same way' );
security_assert( array() === ur_sanitize_checkbox_profile_value( array( array( 'Red' ), $payload ), $field ), 'Nested arrays and unknown values are dropped' );

$choices_field = (object) array(
	'general_setting' => (object) array(),
	'advance_setting' => (object) array( 'choices' => 'Small, Large' ),
);
security_assert( array( 'Large' ) === ur_sanitize_checkbox_profile_value( array( 'Large', 'Huge' ), $choices_field ), 'Legacy advanced-setting choices are honoured' );

$legacy_field = (object) array(
	'general_setting' => (object) array(),
	'advance_setting' => (object) array(),
);
security_assert( array( 'x' ) === ur_sanitize_checkbox_profile_value( array( $payload ), $legacy_field ), 'A field with no options keeps only plain text' );
security_assert( 1 === ur_sanitize_checkbox_profile_value( '1', $legacy_field ) || '1' === ur_sanitize_checkbox_profile_value( '1', $legacy_field ), 'A single on/off value stays usable' );

security_assert( array() === ur_sanitize_checkbox_profile_value( 'Not configured', $field ), 'A single value that is not an option is dropped when the field has options' );
security_assert( 'Red' === ur_sanitize_checkbox_profile_value( 'Red', $field ), 'A single value that is an option is kept' );

$image_field = (object) array(
	'general_setting' => (object) array(
		'image_choice'  => 'true',
		'image_options' => array(
			(object) array(
				'label' => 'Cat',
				'image' => 'cat.png',
			),
			(object) array(
				'label' => 'Dog',
				'image' => 'dog.png',
			),
		),
	),
	'advance_setting' => (object) array(),
);
security_assert( array( 'Cat', 'Dog' ) === ur_sanitize_checkbox_profile_value( array( 'Cat', 'Dog', $payload ), $image_field ), 'Image choices are matched by their label' );

// The AJAX update runs the real caller: a payload sent for a configured checkbox never reaches the saved value.
$GLOBALS['form_fields'] = array(
	(object) array(
		'field_key'       => 'checkbox',
		'general_setting' => (object) array(
			'field_name' => 'colors',
			'options'    => array( 'Red', 'Blue' ),
		),
		'advance_setting' => (object) array(),
	),
);
$profile                = array(
	'user_registration_colors' => array(
		'type'      => 'checkbox',
		'field_key' => 'checkbox',
	),
);
$submitted              = array( 'user_registration_colors' => wp_json_encode( array( 'Red', $payload ) ) );
list( , $saved )        = urm_process_profile_fields( $profile, $submitted, array(), 5, 1 );
security_assert( array( 'Red' ) === $saved['user_registration_colors'], 'The AJAX profile update keeps only the field options' );

$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/admin/class-ur-admin-profile.php' );
security_assert( false === strpos( $source, "\$form_fields[ \$key ]['choices'] = \$unserialized_checkbox_value;" ), 'The admin profile no longer builds options from stored values' );
security_assert( false === strpos( $source, 'wp_kses_post( trim( $choice ) )' ), 'The admin profile prints checkbox labels as plain text' );
