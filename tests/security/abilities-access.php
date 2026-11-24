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

class WP_Error {
	public function get_error_code() {
		return 'code';
	}

	public function get_error_data() {
		return '';
	}
}

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
function ur_string_to_bool( $value ) {
	return true === $value || in_array( $value, array( 'yes', '1', 1, 'true', 'on' ), true );
}
function UR() {
	return new class() {
		public function plugin_url() {
			return 'https://example.test/plugin';
		}
	};
}
const UR_VERSION = '1.0.0';
$enqueued        = array();
function wp_register_style() {}
function wp_enqueue_style( $handle ) {
	$GLOBALS['enqueued'][] = $handle;
}
function wp_add_inline_style() {}
function wp_enqueue_script( $handle ) {
	$GLOBALS['enqueued'][] = $handle;
}
function wp_localize_script() {}
function wp_register_ability( $name, $args ) {
	$GLOBALS['registered'][ $name ] = $args;
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-activity.php';
require __DIR__ . '/abilities-activity-stubs.php';
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
abilities_with_access();
$writers = array_filter(
	$GLOBALS['registered'],
	function ( $ability ) {
		return false === $ability['meta']['annotations']['readonly'];
	}
);
security_assert( array() === $writers, 'Every ability the AI sees by default only reads' );

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

// Each ability has its own switch. Until it is saved, it follows the access chosen for the area.
security_assert( 'user_registration_ability_enabled_update-coupon' === UR_Abilities_Access::ability_option_id( 'update-coupon' ), 'The switch is stored under the ability name' );
$GLOBALS['options'] = array( 'user_registration_ability_access_coupons' => 'write' );
security_assert( true === UR_Abilities_Access::is_enabled( 'coupons', 'update-coupon', 'write' ) && false === UR_Abilities_Access::is_enabled( 'coupons', 'delete-coupon', 'delete' ), 'A switch never saved follows the level an existing site chose for the area' );
$GLOBALS['options']['user_registration_ability_enabled_update-coupon'] = '';
$GLOBALS['options']['user_registration_ability_enabled_delete-coupon'] = '1';
security_assert( false === UR_Abilities_Access::is_enabled( 'coupons', 'update-coupon', 'write' ), 'A saved off switch wins over the area level' );
security_assert( true === UR_Abilities_Access::is_enabled( 'coupons', 'delete-coupon', 'delete' ), 'A saved on switch wins over the area level' );
$GLOBALS['options']['user_registration_ability_enabled_delete-coupon'] = 'banana';
security_assert( false === UR_Abilities_Access::is_enabled( 'coupons', 'delete-coupon', 'delete' ), 'A stored value that is not a clear yes keeps the ability off' );

$GLOBALS['registered'] = array();
$GLOBALS['options']    = array(
	'user_registration_ability_enabled_delete-coupon' => '1',
	'user_registration_ability_enabled_list-coupons'  => '',
);
UR_Abilities::register_abilities();
security_assert( isset( $GLOBALS['registered']['user-registration/delete-coupon'] ) && ! isset( $GLOBALS['registered']['user-registration/update-coupon'] ), 'One ability can be switched on without the others of its level' );
security_assert( ! isset( $GLOBALS['registered']['user-registration/list-coupons'] ) && isset( $GLOBALS['registered']['user-registration/list-forms'] ), 'One read ability can be switched off without touching the others' );

// The settings are built from the areas: a card per area with a switch per ability.
$GLOBALS['options'] = array( 'user_registration_ability_access_settings' => 'write' );
$settings           = UR_Abilities_Access::add_settings(
	array(
		'title'    => '',
		'sections' => array( 'ai_integration' => array( 'title' => 'AI Integration' ) ),
	)
);
$cards              = $settings['sections'];
$coupons            = $cards['ai_abilities_access_coupons'] ?? array();
$by_id              = array();
foreach ( $coupons['settings'] ?? array() as $field ) {
	$by_id[ $field['id'] ] = $field;
}

security_assert( 'ai_integration' === array_key_first( $cards ), 'The existing cards of the section are kept, first' );
security_assert( isset( $cards['ai_abilities_access_payments'], $cards['ai_abilities_access_coupons'], $cards['ai_abilities_access_settings'] ), 'Every area, including addon areas, gets its own card' );
security_assert( ! isset( $cards['ai_abilities_access_broken'], $cards['ai_abilities_access_nameless'] ), 'An area without a usable definition gets no card' );
security_assert( 'Coupons' === $coupons['title'] && 'ur-ability-access-coupons' === $coupons['id'] && 'card' === $coupons['type'], 'The card is named after the area' );
security_assert( array( 'user_registration_ability_enabled_list-coupons', 'user_registration_ability_enabled_update-coupon', 'user_registration_ability_enabled_delete-coupon' ) === array_keys( $by_id ), 'The card has one switch per ability, reads first, then changes, then deletes' );
security_assert(
	array_reduce(
		$by_id,
		function ( $carry, $field ) {
			return $carry && 'toggle' === $field['type'] && 'Addon ability' === $field['title'] && isset( $field['custom_attributes']['data-ur-ability-level'] );
		},
		true
	),
	'Each switch is a toggle named after its ability that says its level'
);
security_assert( 'yes' === $by_id['user_registration_ability_enabled_list-coupons']['default'] && 'no' === $by_id['user_registration_ability_enabled_update-coupon']['default'], 'A new site starts with only the read switches on' );
$settings_card = array_column( $cards['ai_abilities_access_settings']['settings'], 'default', 'id' );
security_assert( 'yes' === $settings_card['user_registration_ability_enabled_update-settings'], 'An area an existing site set to read and change starts with its change switches on' );
security_assert( 'user_registration_enable_abilities' === $coupons['display_condition']['field'] && 'yes' === $coupons['display_condition']['value'], 'Each card shows only while AI abilities are switched on' );
security_assert( ! isset( $by_id['user_registration_ability_enabled_list-coupons']['display_condition'] ), 'The switches leave the showing and hiding to the card' );
security_assert( in_array( 'user-registration-ability-access', $GLOBALS['enqueued'], true ), 'The page gets the script for the quick choices' );

$odd = UR_Abilities_Access::add_settings( 'not-settings' );
security_assert( 'not-settings' === $odd, 'Settings of an unexpected shape are returned untouched' );

UR_Abilities_Access::init();
security_assert( ! empty( $GLOBALS['filters']['user_registration_settings_advanced_ai_integration'] ), 'The settings are added through the AI Integration section filter' );
