<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: every AI ability that changes or deletes data is refused without the right permission, is hidden while
 * its area is below its level, only takes the input it declares, and waits for a confirmation when it is risky.
 * A refused call is written to the activity log. Covers the built-in areas and the Content rules module area.
 *
 * Runs the real `UR_Abilities`, `UR_Abilities_Access`, `UR_Abilities_Safety`, `UR_Abilities_Activity` and
 * `URCR_Abilities` classes with WordPress test doubles; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {
	public $code;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class UR_Abilities_Data {
	public static function __callStatic( $name, $args ) {
		return array();
	}
}

class Addon_Abilities {
	public static $runs = 0;

	public static function allowed() {
		return true;
	}

	public static function run() {
		++self::$runs;
		return array( 'ok' => true );
	}

	public static function preview() {
		return 'This would change something.';
	}
}

$registered = array();
$filters    = array();
$options    = array( 'user_registration_enable_abilities' => 'yes' );
$transients = array();
$caps       = array();
$user_id    = 7;
$counter    = 0;
$rule_posts = array();

function add_action() {}
function add_filter( $hook, $callback ) {
	$GLOBALS['filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function ur_option_checked( $name ) {
	return 'yes' === ( $GLOBALS['options'][ $name ] ?? 'no' );
}
function ur_check_module_activation() {
	return true;
}
function ur_get_license_plan() {
	return (object) array( 'item_plan' => 'professional' );
}
function ur_utm_url( $url ) {
	return $url;
}
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the wp_json_encode() double.
}
function get_current_user_id() {
	return $GLOBALS['user_id'];
}
function wp_generate_password() {
	return str_pad( (string) ++$GLOBALS['counter'], 32, 'a', STR_PAD_LEFT );
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['transients'][ $key ] = array( $value, $ttl );
}
function get_transient( $key ) {
	return $GLOBALS['transients'][ $key ][0] ?? false;
}
function delete_transient( $key ) {
	unset( $GLOBALS['transients'][ $key ] );
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['registered'][ $name ] = $args;
}
function get_post( $id ) {
	return $GLOBALS['rule_posts'][ $id ] ?? null;
}
function wp_list_pluck( $items, $field ) {
	return array_map(
		function ( $item ) use ( $field ) {
			return is_array( $item ) ? ( $item[ $field ] ?? null ) : null;
		},
		(array) $items
	);
}
function urcr_is_access_rule_enabled( $rule ) {
	return isset( $rule['enabled'] ) && true === $rule['enabled'];
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-activity.php';
require __DIR__ . '/abilities-activity-stubs.php';
if ( ! function_exists( 'ur_string_to_bool' ) ) {
	function ur_string_to_bool( $value ) {
		return true === $value || in_array( $value, array( 'yes', '1', 1, 'true', 'on' ), true );
	}
}
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-access.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-safety.php';
require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-abilities.php';

/**
 * Choose every module, with changes allowed or not, and register the abilities again.
 *
 * @param string $level off, read, write or delete.
 * @return array Registered abilities keyed by name.
 */
function register_at( $level ) {
	$GLOBALS['options'][ UR_Abilities_Access::MODULES_OPTION ] = 'off' === $level ? array() : array_keys( UR_Abilities::get_areas() );
	$GLOBALS['options'][ UR_Abilities_Access::WRITE_OPTION ]   = in_array( $level, array( 'write', 'delete' ), true ) ? 'yes' : 'no';
	$GLOBALS['registered'] = array();
	UR_Abilities::register_abilities();
	return $GLOBALS['registered'];
}

// Every level of every area, built-in and from the Content rules module.
$levels = array();
foreach ( UR_Abilities::get_areas() as $slug => $area ) {
	foreach ( $area['abilities'] as $name => $ability ) {
		$levels[ 'user-registration/' . $name ] = array( $slug, UR_Abilities::level( $ability ), $ability );
	}
}
$changing = array_filter(
	$levels,
	function ( $entry ) {
		return 'read' !== $entry[1];
	}
);
security_assert( isset( $levels['user-registration/create-content-rule'], $levels['user-registration/update-setting'], $levels['user-registration/set-form-layout'] ), 'The new areas and abilities are part of the registration' );

// Allow changes hides every changing ability until it is on.
$at_off    = register_at( 'off' );
$at_read   = register_at( 'read' );
$at_write  = register_at( 'write' );
$at_delete = register_at( 'delete' );
security_assert( array() === $at_off, 'No module chosen hides every ability' );
foreach ( $changing as $name => $entry ) {
	security_assert( ! isset( $at_read[ $name ] ), $name . ' is hidden while changes are not allowed' );
	security_assert( isset( $at_write[ $name ] ), $name . ' is there once changes are allowed' );
	security_assert( isset( $at_delete[ $name ] ), $name . ' is there once changes are allowed, deleting included' );
}

// Without the screen's permission, every changing ability is refused, and the refusal is logged.
$GLOBALS['caps'] = array();
foreach ( $changing as $name => $entry ) {
	$before  = count( ur_get_logger()->lines );
	$allowed = $at_delete[ $name ]['permission_callback'](
		array(
			'id'      => 5,
			'form_id' => 5,
		)
	);
	security_assert( true !== $allowed, $name . ' is refused for a user without the permission its screen asks for' );
	$line = end( ur_get_logger()->lines );
	security_assert( count( ur_get_logger()->lines ) === $before + 1 && 'warning' === $line[0] && false !== strpos( $line[1], 'refused' ), $name . ' writes a refused call to the activity log' );
}

// Input is strict: unknown fields are refused, and what an ability needs is declared.
foreach ( $changing as $name => $entry ) {
	$schema = $at_delete[ $name ]['input_schema'];
	security_assert( 'object' === $schema['type'] && false === $schema['additionalProperties'], $name . ' refuses input fields it does not declare' );
	foreach ( (array) $schema['required'] as $field ) {
		security_assert( isset( $schema['properties'][ $field ] ), $name . ' declares its required field ' . $field );
	}
}

// Risky abilities wait for a confirmation. Every delete is risky.
$risky = array( 'remove-form-field', 'update-form-settings', 'approve-member', 'deny-member', 'delete-member', 'assign-membership', 'extend-subscription', 'cancel-subscription', 'delete-subscription', 'delete-membership', 'delete-membership-group', 'create-membership', 'update-membership', 'create-membership-group', 'update-membership-group', 'disable-module', 'update-setting', 'create-content-rule', 'update-content-rule', 'set-content-rule-status', 'trash-content-rule' );
foreach ( $changing as $name => $entry ) {
	if ( 'delete' === $entry[1] || in_array( substr( $name, strlen( 'user-registration/' ) ), $risky, true ) ) {
		security_assert( ! empty( $entry[2]['confirm'] ) && isset( $at_delete[ $name ]['input_schema']['properties']['confirm_token'] ), $name . ' waits for a confirmation code' );
		security_assert( isset( $at_delete[ $name ]['output_schema']['oneOf'] ), $name . ' can answer with a preview instead of running' );
	}
}

// A per-request confirmation only stops the risky requests of an ability.
add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['addon'] = array(
			'label'     => 'Addon',
			'abilities' => array(
				'addon-switch' => array(
					'label'       => 'Switch',
					'description' => 'Switch something on or off.',
					'level'       => 'write',
					'input'       => array( 'on' => array( 'type' => 'boolean' ) ),
					'output'      => UR_Abilities::object_schema( array( 'ok' => array( 'type' => 'boolean' ) ) ),
					'permission'  => array( 'Addon_Abilities', 'allowed' ),
					'execute'     => array( 'Addon_Abilities', 'run' ),
					'confirm'     => function ( $input ) {
						return ! empty( $input['on'] );
					},
					'preview'     => array( 'Addon_Abilities', 'preview' ),
				),
			),
		);
		return $areas;
	}
);
$switch = register_at( 'delete' )['user-registration/addon-switch']['execute_callback'];
$off    = $switch( array( 'on' => false ) );
security_assert( 1 === Addon_Abilities::$runs && true === $off['ok'], 'A request the check calls safe runs straight away' );
$on = $switch( array( 'on' => true ) );
security_assert( 1 === Addon_Abilities::$runs && true === $on['needs_confirmation'], 'A request the check calls risky only gets a preview' );
$confirmed = $switch( array( 'on' => true ) + array( 'confirm_token' => $on['confirm_token'] ) );
security_assert( 2 === Addon_Abilities::$runs && true === $confirmed['ok'], 'The risky request runs once it is confirmed' );

// Content rules ask for a confirmation by effect: when the rule is on after the change, or was on before it.
$whole_site = array( array( 'type' => 'whole_site' ) );
$one_page   = array(
	array(
		'type'   => 'wp_pages',
		'values' => array( 2 ),
	),
);
security_assert( true === URCR_Abilities::needs_confirmation( array( 'targets' => $whole_site ) ), 'A new whole-site rule needs a confirmation' );
security_assert( true === URCR_Abilities::needs_confirmation( array( 'targets' => $one_page ) ), 'A new rule for one page needs a confirmation too' );
$GLOBALS['rule_posts'][42] = (object) array(
	'post_type'    => 'urcr_access_rule',
	'post_status'  => 'publish',
	'post_content' => wp_json_encode(
		array(
			'enabled'         => false,
			'target_contents' => $whole_site,
		)
	),
);
security_assert( true === URCR_Abilities::needs_confirmation( array( 'id' => 42, 'enabled' => true ) ), 'Switching a rule on needs a confirmation' );
security_assert( false === URCR_Abilities::needs_confirmation( array( 'id' => 42, 'enabled' => false ) ), 'Switching a rule that is already off, off again, runs straight away' );
security_assert( false === URCR_Abilities::needs_confirmation( array( 'id' => 42, 'targets' => $one_page ) ), 'Changing a switched-off rule runs straight away' );
$GLOBALS['rule_posts'][43] = (object) array(
	'post_type'    => 'urcr_access_rule',
	'post_status'  => 'publish',
	'post_content' => wp_json_encode(
		array(
			'enabled'         => true,
			'target_contents' => $whole_site,
		)
	),
);
security_assert( true === URCR_Abilities::needs_confirmation( array( 'id' => 43, 'enabled' => false ) ), 'Switching a rule that is on off needs a confirmation, it can open the site up' );
security_assert( true === URCR_Abilities::needs_confirmation( array( 'id' => 43, 'targets' => $one_page ) ), 'Changing a rule that is on needs a confirmation' );

// Settings and deletes live in a class that is stubbed here, so these read its source.
$data_php = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-data.php' );
security_assert( preg_match( "/LOCKED_SETTING_TABS = array\([^)]*'payment'/", $data_php ), 'The Payment tab is never changed by AI' );
security_assert( false !== strpos( $data_php, 'return ! in_array( $id, $safe, true ) || in_array( $id, $risky, true );' ) && false === strpos( $data_php, "strpos( \$id, 'role' )" ), 'Every setting shows a preview unless it is on the harmless list, not by name' );
security_assert( preg_match( "/SAFE_SETTINGS = array\(\);/", $data_php ), 'The harmless list starts empty' );
security_assert( false !== strpos( $data_php, 'user_can( $users[0], ' . chr( 39 ) . 'manage_options' . chr( 39 ) . ' )' ) && false !== strpos( $data_php, 'You cannot delete an administrator.' ), 'Deleting an administrator is refused, like approve and deny' );

// No ability may declare an admin right in its caps: the AI user is lent every declared right, so it would pass every
// per-object check, including the guards that keep plan abilities from changing roles.
foreach ( array( '/includes/class-ur-abilities.php', '/modules/content-restriction/class-urcr-abilities.php' ) as $abilities_file ) {
	preg_match_all( "/'caps'\s*=>\s*array\(([^)]*)\)/", file_get_contents( dirname( __DIR__, 2 ) . $abilities_file ), $declared );
	security_assert( ! preg_match( '/promote_users|manage_options|install_plugins/', implode( ' ', $declared[1] ) ), $abilities_file . ' declares no admin right in caps' );
}

// A plan update that fails to sync with Stripe puts the plan back. The service needs a live site, so this reads its source.
$plan_php = file_get_contents( dirname( __DIR__, 2 ) . '/modules/membership/includes/Admin/Services/MembershipService.php' );
security_assert( 2 === substr_count( $plan_php, '$this->restore_plan( $before );' ), 'Both Stripe failures, an answer without success and an exception, put the plan back' );
security_assert( 1 === preg_match( '/snapshot_plan\( \$data \);\s+\$updated_id = wp_insert_post\(/', $plan_php ), 'The plan is remembered right before it is saved' );
