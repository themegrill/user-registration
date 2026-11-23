<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: the admin chooses what AI assistants can do in each ability area, and anything above the chosen level
 * is hidden from them completely. The settings are built from the areas, so an addon area gets its own setting
 * without extra code. Read only is the default, an unknown stored value cannot widen access, and a module can hide
 * abilities through a filter.
 *
 * Runs the real `UR_Abilities` and `UR_Abilities_Access` classes with WordPress test doubles; no live site or
 * database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {}

class UR_Abilities_Data {
	public static function __callStatic( $name, $args ) {
		return array();
	}
}

class Addon_Abilities {
	public static function allowed() {
		return true;
	}

	public static function run() {
		return array();
	}
}

$registered = array();
$filters    = array();
$options    = array();

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
function ur_option_checked() {
	return true;
}
function ur_get_license_plan() {
	return false;
}
function ur_utm_url( $url ) {
	return $url;
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['registered'][ $name ] = $args;
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-access.php';

function addon_ability( $level ) {
	return array(
		'label'       => 'Addon ability',
		'description' => 'Does something.',
		'level'       => $level,
		'output'      => UR_Abilities::object_schema( array( 'ok' => array( 'type' => 'boolean' ) ) ),
		'permission'  => array( 'Addon_Abilities', 'allowed' ),
		'execute'     => array( 'Addon_Abilities', 'run' ),
	);
}

add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['coupons']  = array(
			'label'     => 'Coupons',
			'abilities' => array(
				'list-coupons'  => addon_ability( 'read' ),
				'update-coupon' => addon_ability( 'write' ),
				'delete-coupon' => addon_ability( 'delete' ),
			),
		);
		$areas['settings'] = array(
			'label'     => 'Global settings',
			'abilities' => array(
				'get-settings'    => addon_ability( 'read' ),
				'update-settings' => addon_ability( 'write' ),
			),
		);
		$areas['broken']   = 'not-an-area';
		$areas['nameless'] = array( 'abilities' => array( 'x' => addon_ability( 'read' ) ) );
		return $areas;
	}
);

function abilities_with_access( $access = array() ) {
	$GLOBALS['registered'] = array();
	$GLOBALS['options']    = array();
	foreach ( $access as $slug => $level ) {
		$GLOBALS['options'][ UR_Abilities_Access::option_id( $slug ) ] = $level;
	}
	UR_Abilities::register_abilities();
	return array_map(
		function ( $name ) {
			return substr( $name, strlen( 'user-registration/' ) );
		},
		array_keys( $GLOBALS['registered'] )
	);
}

// Read only until the admin changes it, and a bad stored value cannot widen access.
$GLOBALS['options'] = array();
security_assert( 'read' === UR_Abilities_Access::get( 'forms' ), 'An area is read only until the admin changes it' );
$GLOBALS['options']['user_registration_ability_access_forms'] = 'banana';
security_assert( 'read' === UR_Abilities_Access::get( 'forms' ), 'An unknown stored value falls back to read only' );
$GLOBALS['options']['user_registration_ability_access_forms'] = array( 'delete' );
security_assert( 'read' === UR_Abilities_Access::get( 'forms' ), 'A stored value of the wrong type falls back to read only' );
security_assert( 'user_registration_ability_access_my-area' === UR_Abilities_Access::option_id( 'My-Area<>' ), 'The option name is built from a cleaned slug' );

// Each level includes the ones before it.
$matrix = array(
	'off'    => array( false, false, false ),
	'read'   => array( true, false, false ),
	'write'  => array( true, true, false ),
	'delete' => array( true, true, true ),
);
foreach ( $matrix as $access => $expected ) {
	$GLOBALS['options']['user_registration_ability_access_forms'] = $access;
	$actual = array(
		UR_Abilities_Access::allows( 'forms', 'read' ),
		UR_Abilities_Access::allows( 'forms', 'write' ),
		UR_Abilities_Access::allows( 'forms', 'delete' ),
	);
	security_assert( $expected === $actual, 'Access ' . $access . ' allows exactly the levels up to it' );
}
security_assert( false === UR_Abilities_Access::allows( 'forms', 'admin' ), 'An unknown level is never allowed' );

// Anything above the chosen level is not registered, so the AI cannot see it.
$default = abilities_with_access();
security_assert( in_array( 'list-forms', $default, true ) && in_array( 'list-coupons', $default, true ), 'Read abilities are available by default' );
security_assert( ! in_array( 'update-coupon', $default, true ) && ! in_array( 'delete-coupon', $default, true ) && ! in_array( 'update-settings', $default, true ), 'Write and delete abilities are hidden by default' );

$write = abilities_with_access( array( 'coupons' => 'write' ) );
security_assert( in_array( 'update-coupon', $write, true ) && ! in_array( 'delete-coupon', $write, true ), 'Read and change shows write abilities but not delete' );
security_assert( ! in_array( 'update-settings', $write, true ), 'Changing one area does not open another' );

$delete = abilities_with_access( array( 'coupons' => 'delete' ) );
security_assert( in_array( 'delete-coupon', $delete, true ), 'Read, change and delete shows delete abilities' );

$off = abilities_with_access( array( 'forms' => 'off' ) );
security_assert( ! in_array( 'list-forms', $off, true ) && ! in_array( 'get-form', $off, true ), 'Off hides even the read abilities of that area' );
security_assert( in_array( 'list-members', $off, true ) && in_array( 'list-coupons', $off, true ), 'Turning one area off leaves the other areas alone' );
security_assert( 9 + 3 === count( abilities_with_access() ), 'The nine built-in abilities and the three addon reads are the default set' );

// A hidden name stays reserved, so an addon cannot take over a built-in ability that is switched off.
add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['impostor'] = array(
			'label'     => 'Impostor',
			'abilities' => array( 'list-forms' => addon_ability( 'read' ) ),
		);
		return $areas;
	}
);
$off = abilities_with_access( array( 'forms' => 'off' ) );
security_assert( ! in_array( 'list-forms', $off, true ), 'A switched-off built-in ability cannot be replaced by an addon using its name' );

// Another module can hide abilities through the filter.
$seen = array();
add_filter(
	'user_registration_ability_enabled',
	function ( $enabled, $slug, $name, $level ) use ( &$seen ) {
		$seen[ $name ] = array( $slug, $level );
		return 'get-member' === $name ? false : $enabled;
	}
);
$paused = abilities_with_access();
security_assert( ! in_array( 'get-member', $paused, true ) && in_array( 'list-members', $paused, true ), 'A module can hide one ability through the filter' );
security_assert( array( 'members', 'read' ) === $seen['get-member'] && array( 'coupons', 'write' ) === $seen['update-coupon'], 'The filter receives the area and the level' );

// The settings are built from the areas.
$settings = UR_Abilities_Access::add_settings(
	array(
		'title'    => '',
		'sections' => array( 'advanced_settings' => array( 'title' => 'Others' ) ),
	)
);
$section  = $settings['sections']['ai_abilities_access'] ?? array();
$by_id    = array();
foreach ( $section['settings'] ?? array() as $field ) {
	$by_id[ $field['id'] ] = $field;
}

security_assert( isset( $settings['sections']['advanced_settings'] ), 'The existing settings are kept' );
security_assert( isset( $by_id['user_registration_ability_access_forms'], $by_id['user_registration_ability_access_coupons'], $by_id['user_registration_ability_access_settings'] ), 'Every area, including addon areas, gets its own setting' );
security_assert( ! isset( $by_id['user_registration_ability_access_broken'], $by_id['user_registration_ability_access_nameless'] ), 'An area without a usable definition gets no setting' );
security_assert( array( 'off', 'read' ) === array_keys( $by_id['user_registration_ability_access_forms']['options'] ), 'An area with only read abilities offers off and read only' );
security_assert( array( 'off', 'read', 'write' ) === array_keys( $by_id['user_registration_ability_access_settings']['options'] ), 'An area that can change data also offers read and change' );
security_assert( array( 'off', 'read', 'write', 'delete' ) === array_keys( $by_id['user_registration_ability_access_coupons']['options'] ), 'An area that can delete offers every level' );
security_assert( 'read' === $by_id['user_registration_ability_access_coupons']['default'] && 'select' === $by_id['user_registration_ability_access_coupons']['type'], 'Each setting is a dropdown that starts at read only' );
security_assert( 'user_registration_enable_abilities' === $by_id['user_registration_ability_access_coupons']['display_condition']['field'], 'The settings show only while AI abilities are switched on' );
security_assert( 'Coupons' === $by_id['user_registration_ability_access_coupons']['title'], 'The setting is named after the area' );

$odd = UR_Abilities_Access::add_settings( 'not-settings' );
security_assert( 'not-settings' === $odd, 'Settings of an unexpected shape are returned untouched' );

UR_Abilities_Access::init();
security_assert( ! empty( $GLOBALS['filters']['user_registration_settings_advanced_others'] ), 'The settings are added through the Others section filter' );
