<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test doubles share this file.
/**
 * Regression: saving a profile must show a field's validation message whether the validator returns one message
 * or a list of them. A validator that returned a single message, such as the checkbox option check, ended the
 * request with a fatal error.
 *
 * Runs the real `UR_Form_Validation::run_field_validations_on_profile_update()`; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';

function do_action( $hook ) {}

function apply_filters( $hook, $value ) {
	return $GLOBALS['validator_message'];
}

function remove_all_filters( $hook ) {
	$GLOBALS['filters_cleared'] = ( $GLOBALS['filters_cleared'] ?? 0 ) + 1;
}

function ur_option_checked( $key, $default = false ) {
	return false;
}

function ur_add_notice( $message, $type = 'success' ) {
	$GLOBALS['notices'][] = $message;
}

eval(
	'class ProfileValidationRunner {
		public $form_id = 0;
		public ' . security_function( 'includes/validation/class-ur-form-validation.php', 'run_field_validations_on_profile_update' ) . '
	}'
);

function notices_after( $field_key, $validator_message ) {
	$GLOBALS['validator_message'] = $validator_message;
	$GLOBALS['notices']           = array();
	$GLOBALS['filters_cleared']   = 0;
	( new ProfileValidationRunner() )->run_field_validations_on_profile_update( $field_key, (object) array(), (object) array(), 0 );
	return $GLOBALS['notices'];
}

security_assert( array( 'Please select a valid checkbox option.' ) === notices_after( 'checkbox', 'Please select a valid checkbox option.' ), 'A validator that returns one message is shown to the member' );
security_assert( 1 === $GLOBALS['filters_cleared'], 'The message is cleared after a single message too, so it cannot spill into the next field' );
security_assert( array( 'Too long.' ) === notices_after( 'description', array( 'Description' => 'Too long.', 'individual' => true ) ), 'A validator that returns a list shows its first message' );
security_assert( array() === notices_after( 'first_name', '' ), 'A field with no validation message adds no notice' );
security_assert( array() === notices_after( 'user_email', 'Ignored.' ), 'The email and username fields are still skipped on profile update' );
