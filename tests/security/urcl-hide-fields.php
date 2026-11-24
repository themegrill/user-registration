<?php
/**
 * A client-sent urcl_hide_fields list cannot waive a field that has no conditional logic.
 *
 * @package UserRegistration/Tests
 */

// Execute only the targeted helpers; no live site or database is loaded.
require __DIR__ . '/bootstrap.php';
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual helper bodies using the existing isolated fixture.
eval( security_function( 'includes/functions-ur-core.php', 'ur_string_to_bool' ) );
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual helper bodies using the existing isolated fixture.
eval( security_function( 'includes/functions-ur-core.php', 'ur_is_field_hidden_by_conditional_logic' ) );

/**
 * Build a minimal form field object.
 *
 * @param string $name        Field name.
 * @param string $conditional Conditional logic setting value.
 */
function urcl_field( $name, $conditional ) {
	return (object) array(
		'general_setting' => (object) array( 'field_name' => $name ),
		'advance_setting' => (object) array( 'enable_conditional_logic' => $conditional ),
	);
}

$_POST = array( 'urcl_hide_fields' => '["first_name"]' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '' ) ), 'Forged list cannot hide a field without conditional logic' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', 'false' ) ), 'Forged list cannot hide a field with conditional logic off' );
security_assert( ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'Listed field with conditional logic on is hidden' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'last_name', '1' ) ), 'Unlisted field with conditional logic on stays required' );

$_POST = array( 'urcl_hide_fields' => 'not-json' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'Malformed list hides nothing' );

$_POST = array( 'urcl_hide_fields' => array( 'first_name' ) );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'Array-typed list hides nothing and does not throw' );

$_POST = array();
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'Missing list hides nothing' );

/**
 * Pass-through double so the real is_field_required() body can run, not just the helper.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value to filter.
 */
function apply_filters( $hook, $value ) {
	return $value;
}
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual validator body using the existing isolated fixture.
eval( security_function( 'includes/validation/class-ur-form-validation.php', 'is_field_required' ) );
/**
 * Build a required field with the given conditional logic setting.
 *
 * @param string $conditional Conditional logic setting value.
 */
function urcl_required_field( $conditional ) {
	$field                            = urcl_field( 'first_name', $conditional );
	$field->general_setting->required = 'yes';
	return $field;
}
$_POST = array( 'urcl_hide_fields' => '["first_name"]' );
security_assert( is_field_required( urcl_required_field( '' ) ), 'Required field without conditional logic stays required under a forged list' );
security_assert( ! is_field_required( urcl_required_field( '1' ) ), 'Required field with conditional logic is waived when listed' );
$_POST = array();
security_assert( is_field_required( urcl_required_field( '1' ) ), 'Required field with conditional logic stays required when not listed' );

// Every changed call site must go through the helper and never read the raw parameter again.
$call_sites = array(
	array( 'includes/validation/class-ur-form-validation.php', 'is_field_required' ),
	array( 'includes/validation/class-ur-form-validation.php', 'ur_missing_field_validation' ),
	array( 'includes/form/class-ur-form-field-text.php', 'validation' ),
	array( 'includes/functions-ur-core.php', 'user_registration_validate_edit_profile_form_field_data' ),
);
foreach ( $call_sites as $site ) {
	$source = security_function( $site[0], $site[1] );
	security_assert( false !== strpos( $source, 'ur_is_field_hidden_by_conditional_logic(' ), $site[1] . ' uses the helper' );
	security_assert( false === strpos( $source, "\$_POST['urcl_hide_fields']" ), $site[1] . ' does not read the raw parameter' );
}
