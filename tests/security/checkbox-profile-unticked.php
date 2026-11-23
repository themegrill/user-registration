<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test doubles share this file.
/**
 * Regression: a member who unticks every option of a checkbox field and saves their profile must not get an
 * "invalid option" error. The profile save sends 0 for a field with nothing ticked. A value that is not one of
 * the field's options is still rejected.
 *
 * Runs the real `UR_Form_Field_Checkbox::validation()`; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';

function add_filter( $hook, $callback ) {
	$GLOBALS['filters'][ $hook ] = $callback;
}

function ur_string_to_bool( $value ) {
	return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );
}

function ur_sanitize_tooltip( $value ) {
	return htmlspecialchars( $value );
}

eval(
	'class CheckboxValidationRunner {
		public ' . security_function( 'includes/form/class-ur-form-field-checkbox.php', 'validation' ) . '
	}'
);

function checkbox_message_for( $value ) {
	$GLOBALS['filters'] = array();
	$field              = (object) array(
		'general_setting' => (object) array(
			'field_name' => 'Choices',
			'options'    => array( 'First Choice', 'Second Choice', 'Third Choice' ),
		),
		'advance_setting' => (object) array(),
	);

	( new CheckboxValidationRunner() )->validation( $field, (object) array( 'value' => $value ), 'checkbox_message', 0 );

	return isset( $GLOBALS['filters']['checkbox_message'] ) ? call_user_func( $GLOBALS['filters']['checkbox_message'], '' ) : null;
}

security_assert( null === checkbox_message_for( array( 'First Choice' ) ), 'One ticked option is accepted' );
security_assert( null === checkbox_message_for( array( 'First Choice', 'Third Choice' ) ), 'Two ticked options are accepted' );
security_assert( null === checkbox_message_for( '0' ), 'Unticking every option is accepted' );
security_assert( null === checkbox_message_for( 0 ), 'Unticking every option is accepted when the number is not text' );
security_assert( null === checkbox_message_for( '' ), 'An empty value is accepted' );
security_assert( null === checkbox_message_for( array() ), 'An empty list is accepted' );
security_assert( 'Please select a valid checkbox option.' === checkbox_message_for( array( 'Made up' ) ), 'A value that is not an option is rejected' );
security_assert( 'Please select a valid checkbox option.' === checkbox_message_for( '1' ), 'A single value that is not an option is rejected' );
security_assert( 'Please select a valid checkbox option.' === checkbox_message_for( array( '0' ) ), 'A listed 0 that is not an option is rejected' );
