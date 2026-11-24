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

	public static function preview() {
		return 'This would change something.';
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
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-safety.php';

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
			'groups'    => array(
				'view'   => array( 'label' => 'See coupons' ),
				'change' => array(
					'label'     => 'Change coupons',
					'abilities' => array( 'create-coupon', 'update-coupon' ),
				),
			),
			'abilities' => array(
				'list-coupons'  => addon_ability( 'read' ),
				'create-coupon' => addon_ability( 'write' ),
				'update-coupon' => array(
					'confirm' => true,
					'preview' => array( 'Addon_Abilities', 'preview' ),
				) + addon_ability( 'write' ),
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

// An area's abilities share a few switches: all reads one, each defined group one, any other ability its own.
$coupon_groups = UR_Abilities_Access::get_groups( UR_Abilities::get_areas()['coupons'] );
security_assert( array( 'view', 'change', 'delete-coupon' ) === array_keys( $coupon_groups ), 'Reads share one switch, a defined group has one, and an ability left out of the groups keeps its own' );
security_assert( array( 'create-coupon', 'update-coupon' ) === $coupon_groups['change']['abilities'] && 'Change coupons' === $coupon_groups['change']['label'], 'A defined group covers exactly the abilities it lists, under its own name' );
security_assert( 'See coupons' === $coupon_groups['view']['label'] && 'delete' === $coupon_groups['delete-coupon']['level'], 'The read switch takes the area\'s own name, and a switch knows the highest level it covers' );
$settings_groups = UR_Abilities_Access::get_groups( UR_Abilities::get_areas()['settings'] );
security_assert( array( 'view', 'update-settings' ) === array_keys( $settings_groups ) && 'Addon ability' === $settings_groups['update-settings']['label'], 'An area without groups still merges its reads and names each other switch after its ability' );

// Until a switch is saved, it follows the access chosen for the area.
security_assert( 'user_registration_ability_group_coupons_change' === UR_Abilities_Access::group_option_id( 'coupons', 'change' ), 'The switch is stored under the area and group' );
$GLOBALS['options'] = array( 'user_registration_ability_access_coupons' => 'write' );
security_assert( true === UR_Abilities_Access::is_enabled( 'coupons', 'update-coupon', 'write' ) && false === UR_Abilities_Access::is_enabled( 'coupons', 'delete-coupon', 'delete' ), 'A switch never saved follows the level an existing site chose for the area' );
$GLOBALS['options']['user_registration_ability_group_coupons_change']        = '';
$GLOBALS['options']['user_registration_ability_group_coupons_delete-coupon'] = '1';
security_assert( false === UR_Abilities_Access::is_enabled( 'coupons', 'update-coupon', 'write' ) && false === UR_Abilities_Access::is_enabled( 'coupons', 'create-coupon', 'write' ), 'A saved off switch turns off every ability it covers, whatever the area level' );
security_assert( true === UR_Abilities_Access::is_enabled( 'coupons', 'delete-coupon', 'delete' ), 'A saved on switch wins over the area level' );
$GLOBALS['options']['user_registration_ability_group_coupons_delete-coupon'] = 'banana';
security_assert( false === UR_Abilities_Access::is_enabled( 'coupons', 'delete-coupon', 'delete' ), 'A stored value that is not a clear yes keeps the switch off' );

$GLOBALS['registered'] = array();
$GLOBALS['options']    = array(
	'user_registration_ability_group_coupons_delete-coupon' => '1',
	'user_registration_ability_group_coupons_view' => '',
);
UR_Abilities::register_abilities();
security_assert( isset( $GLOBALS['registered']['user-registration/delete-coupon'] ) && ! isset( $GLOBALS['registered']['user-registration/update-coupon'] ), 'Deleting can be switched on without changing' );
security_assert( ! isset( $GLOBALS['registered']['user-registration/list-coupons'] ) && isset( $GLOBALS['registered']['user-registration/list-forms'] ), 'One area\'s read switch leaves the other areas alone' );

// The settings are built from the areas: one card with each area's switches.
$GLOBALS['options'] = array( 'user_registration_ability_access_settings' => 'write' );
$settings           = UR_Abilities_Access::add_settings(
	array(
		'title'    => '',
		'sections' => array( 'ai_integration' => array( 'title' => 'AI Integration' ) ),
	)
);
$cards              = $settings['sections'];
$card               = $cards['ai_abilities_access'] ?? array();
$by_id              = array();
foreach ( $card['settings'] ?? array() as $field ) {
	$by_id[ $field['id'] ] = $field;
}
$coupon_ids = array_keys(
	array_filter(
		$by_id,
		function ( $field ) {
			return 'coupons' === $field['custom_attributes']['data-ur-ability-area'];
		}
	)
);

security_assert( array( 'ai_integration', 'ai_abilities_access' ) === array_keys( $cards ), 'The existing card is kept, followed by one access card' );
security_assert( 'ur-ability-access' === $card['id'] && 'card' === $card['type'], 'All switches sit in one card' );
security_assert( isset( $by_id['user_registration_ability_group_settings_view'], $by_id['user_registration_ability_group_coupons_view'], $by_id['user_registration_ability_group_forms_view'] ), 'Every area, including addon areas, gets its switches' );
security_assert( ! isset( $by_id['user_registration_ability_group_nameless_view'], $by_id['user_registration_ability_group_broken_view'] ), 'An area without a usable definition gets no switches' );
security_assert( array( 'user_registration_ability_group_coupons_view', 'user_registration_ability_group_coupons_change', 'user_registration_ability_group_coupons_delete-coupon' ) === $coupon_ids, 'An area gets one switch per group, reads first' );
security_assert( 'Addon ability, Addon ability' === $by_id['user_registration_ability_group_coupons_change']['desc'] && 'yes' === $by_id['user_registration_ability_group_coupons_change']['custom_attributes']['data-ur-ability-confirm'], 'A switch lists the abilities it covers and says when one of them asks first' );
security_assert(
	array_reduce(
		$by_id,
		function ( $carry, $field ) {
			return $carry && 'toggle' === $field['type'] && '' !== $field['title'] && isset( $field['custom_attributes']['data-ur-ability-area'] );
		},
		true
	),
	'Each switch is a toggle with a name that says its area'
);
security_assert( 'yes' === $by_id['user_registration_ability_group_coupons_view']['default'] && 'no' === $by_id['user_registration_ability_group_coupons_change']['default'], 'A new site starts with only the read switches on' );
security_assert( 'yes' === $by_id['user_registration_ability_group_settings_update-settings']['default'], 'An area an existing site set to read and change starts with its change switches on' );
security_assert( 'user_registration_enable_abilities' === $card['display_condition']['field'] && 'yes' === $card['display_condition']['value'], 'The card shows only while AI abilities are switched on' );
security_assert( ! isset( $by_id['user_registration_ability_group_coupons_view']['display_condition'] ), 'The switches leave the showing and hiding to the card' );
security_assert( in_array( 'user-registration-ability-access', $GLOBALS['enqueued'], true ), 'The page gets the script that lays the switches out by area' );

$odd = UR_Abilities_Access::add_settings( 'not-settings' );
security_assert( 'not-settings' === $odd, 'Settings of an unexpected shape are returned untouched' );

UR_Abilities_Access::init();
security_assert( ! empty( $GLOBALS['filters']['user_registration_settings_advanced_ai_integration'] ), 'The settings are added through the AI Integration section filter' );
