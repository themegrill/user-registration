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

$_POST = array();
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'Missing list hides nothing' );
