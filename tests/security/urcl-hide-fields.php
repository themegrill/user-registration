<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test doubles share this fixture.
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
eval( security_function( 'includes/functions-ur-core.php', 'ur_get_conditional_row_field_names' ) );
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual helper bodies using the existing isolated fixture.
eval( security_function( 'includes/functions-ur-core.php', 'ur_is_field_hidden_by_conditional_logic' ) );
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Run the real validator method on a stub class, since it reads $this.
eval( 'class Urcl_Validator_Double { public $form_id = 0; ' . security_function( 'includes/validation/class-ur-form-validation.php', 'is_field_required' ) . ' }' );

/**
 * Pass-through double so the real is_field_required() body can run.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value to filter.
 */
function apply_filters( $hook, $value ) {
	return $value;
}

/**
 * Form meta double, read from the fixture below.
 *
 * @param int    $form_id Form ID.
 * @param string $key     Meta key.
 */
function get_post_meta( $form_id, $key ) {
	return ( URCL_FORM_ID === $form_id && isset( $GLOBALS['urcl_meta'][ $key ] ) ) ? $GLOBALS['urcl_meta'][ $key ] : '';
}

/** Form handler double: rows of grids of fields, read from the fixture below. */
class Urcl_Form_Double {
	/**
	 * Return the fixture content for the fixture form only.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Query args.
	 */
	public function get_form( $form_id, $args = array() ) {
		return URCL_FORM_ID === $form_id ? $GLOBALS['urcl_content'] : array();
	}
}

/** Plugin instance double exposing the form handler double. */
function UR() {
	return (object) array( 'form' => new Urcl_Form_Double() );
}

/**
 * Build a minimal form field object.
 *
 * @param string $name        Field name.
 * @param mixed  $conditional Conditional logic setting value.
 */
function urcl_field( $name, $conditional ) {
	return (object) array(
		'general_setting' => (object) array( 'field_name' => $name ),
		'advance_setting' => (object) array( 'enable_conditional_logic' => $conditional ),
	);
}

/**
 * Build a required field with the given conditional logic setting.
 *
 * @param string $name        Field name.
 * @param mixed  $conditional Conditional logic setting value.
 */
function urcl_required_field( $name, $conditional ) {
	$field                            = urcl_field( $name, $conditional );
	$field->general_setting->required = 'yes';
	return $field;
}

const URCL_FORM_ID = 7;

// Row 3 has conditional logic and holds first_name; row 4 has none and holds last_name.
$GLOBALS['urcl_meta']    = array(
	'user_registration_form_row_ids'  => '[3,4]',
	'user_registration_form_row_data' => '[{"row_id":3,"conditional_logic_enabled":"true"},{"row_id":4,"conditional_logic_enabled":"false"}]',
);
$GLOBALS['urcl_content'] = array(
	array( array( urcl_field( 'first_name', '' ) ) ),
	array( array( urcl_field( 'last_name', '' ) ) ),
);

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

$_POST = array( 'urcl_hide_fields' => '"first_name"' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'A JSON string instead of a list hides nothing' );

// WordPress adds slashes to $_POST, so a real request arrives escaped.
$_POST = array( 'urcl_hide_fields' => '[\"first_name\"]' );
security_assert( ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '1' ) ), 'Slashed list from a real request is understood' );
security_assert( ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', 'yes' ) ), 'A yes flag counts as conditional logic on' );
security_assert( ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', true ) ), 'A true flag counts as conditional logic on' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( (object) array( 'general_setting' => (object) array( 'field_name' => 'first_name' ) ) ), 'A field with no advanced settings is not hidden' );

// Fields in a row that has conditional logic are hidden by the row, so the field flag is not needed.
$_POST = array( 'urcl_hide_fields' => '["first_name","last_name"]' );
security_assert( ur_is_field_hidden_by_conditional_logic( urcl_field( 'first_name', '' ), URCL_FORM_ID ), 'Listed field inside a conditional row is hidden' );
security_assert( ! ur_is_field_hidden_by_conditional_logic( urcl_field( 'last_name', '' ), URCL_FORM_ID ), 'Listed field inside a row without conditional logic is not hidden' );

// Drive the real is_field_required() body, not just the helper.
$validator = new Urcl_Validator_Double();
$_POST     = array( 'urcl_hide_fields' => '["first_name"]' );
security_assert( $validator->is_field_required( urcl_required_field( 'first_name', '' ), array(), 99 ), 'Required field without conditional logic stays required under a forged list' );
security_assert( ! $validator->is_field_required( urcl_required_field( 'first_name', '1' ), array(), 99 ), 'Required field with conditional logic is waived when listed' );
security_assert( ! $validator->is_field_required( urcl_required_field( 'first_name', '' ), array(), URCL_FORM_ID ), 'Required field inside a conditional row is waived when listed' );
$_POST = array( 'urcl_hide_fields' => '["last_name"]' );
security_assert( $validator->is_field_required( urcl_required_field( 'last_name', '' ), array(), URCL_FORM_ID ), 'Required field inside a row without conditional logic stays required under a forged list' );
$_POST = array();
security_assert( $validator->is_field_required( urcl_required_field( 'first_name', '1' ), array(), URCL_FORM_ID ), 'Required field with conditional logic stays required when not listed' );

// Run the real saved-data filter: only fields that can really be hidden are dropped.
/**
 * Saved form fields double: first_name sits in the conditional row, last_name in a plain row, city has its own flag.
 *
 * @param int $form_id Form ID.
 */
function ur_get_form_field_data( $form_id ) {
	return URCL_FORM_ID === $form_id ? array( urcl_field( 'first_name', '' ), urcl_field( 'last_name', '' ), urcl_field( 'city', '1' ) ) : array();
}
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual filter body using the existing isolated fixture.
eval( security_function( 'includes/functions-ur-core.php', 'user_registration_conditional_user_meta_filter' ) );
$saved = array(
	'first_name' => 'a',
	'last_name'  => 'b',
	'city'       => 'c',
);
$_POST = array(
	'action'           => 'user_registration_user_form_submit',
	'urcl_hide_fields' => '["first_name","last_name","city"]',
);
security_assert( array( 'last_name' => 'b' ) === user_registration_conditional_user_meta_filter( $saved, 5, URCL_FORM_ID ), 'Filter drops only fields that can be hidden (own flag or conditional row)' );
$_POST['action'] = 'update_profile';
$profile_saved   = array(
	'user_registration_first_name' => 'a',
	'user_registration_last_name'  => 'b',
	'user_registration_city'       => 'c',
);
security_assert( array( 'user_registration_last_name' => 'b' ) === user_registration_conditional_user_meta_filter( $profile_saved, 5, URCL_FORM_ID ), 'Profile filter drops only hideable fields, using the prefixed keys' );
$_POST = array(
	'action'           => 'user_registration_user_form_submit',
	'urcl_hide_fields' => array( 'first_name' ),
);
security_assert( user_registration_conditional_user_meta_filter( $saved, 5, URCL_FORM_ID ) === $saved, 'Array-typed list drops nothing and does not throw' );
security_assert( user_registration_conditional_user_meta_filter( $saved, 0, URCL_FORM_ID ) === $saved, 'No user means nothing is filtered' );

// Every changed call site must go through the helper and never read the raw parameter again.
$call_sites = array(
	array( 'includes/functions-ur-core.php', 'user_registration_conditional_user_meta_filter' ),
	array( 'includes/frontend/class-ur-frontend-form-handler.php', 'ur_update_user_meta' ),
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
