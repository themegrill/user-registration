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

function abilities_with( $options = array() ) {
	$GLOBALS['registered'] = array();
	$GLOBALS['options']    = $options;
	UR_Abilities::register_abilities();
	return array_map(
		function ( $name ) {
			return substr( $name, strlen( 'user-registration/' ) );
		},
		array_keys( $GLOBALS['registered'] )
	);
}

$modules = UR_Abilities_Access::MODULES_OPTION;
$changes = UR_Abilities_Access::WRITE_OPTION;

// Every module, read only, until the admin changes it.
$GLOBALS['options'] = array();
security_assert( array_keys( UR_Abilities::get_areas() ) === UR_Abilities_Access::modules(), 'Every module is chosen until the admin saves the list' );
security_assert( false === UR_Abilities_Access::can_change(), 'Changes are off until the admin turns them on' );
$GLOBALS['options'][ $changes ] = 'banana';
security_assert( false === UR_Abilities_Access::can_change(), 'A stored value that is not a clear yes keeps changes off' );

$default = abilities_with();
security_assert( in_array( 'list-forms', $default, true ) && in_array( 'list-coupons', $default, true ), 'Read abilities of every module are available by default' );
security_assert( ! in_array( 'update-coupon', $default, true ) && ! in_array( 'delete-coupon', $default, true ) && ! in_array( 'update-settings', $default, true ), 'Changing and deleting abilities are hidden by default' );
abilities_with();
$writers = array_filter(
	$GLOBALS['registered'],
	function ( $ability ) {
		return false === $ability['meta']['annotations']['readonly'];
	}
);
security_assert( array() === $writers, 'Every ability the AI sees by default only reads' );

// Allow changes opens every changing ability, but only in the chosen modules.
$write = abilities_with( array( $changes => 'yes' ) );
security_assert( in_array( 'update-coupon', $write, true ) && in_array( 'delete-coupon', $write, true ) && in_array( 'update-settings', $write, true ), 'Allow changes shows changing and deleting abilities of every module' );
$some = abilities_with(
	array(
		$modules => array( 'coupons', 'members' ),
		$changes => 'yes',
	)
);
security_assert( in_array( 'update-coupon', $some, true ) && in_array( 'list-members', $some, true ), 'A chosen module gets read and change' );
security_assert( ! in_array( 'list-forms', $some, true ) && ! in_array( 'update-settings', $some, true ) && ! in_array( 'get-settings', $some, true ), 'A module that is not chosen is hidden completely, reading included' );
$reading = abilities_with( array( $modules => array( 'coupons' ) ) );
security_assert( in_array( 'list-coupons', $reading, true ) && ! in_array( 'update-coupon', $reading, true ) && ! in_array( 'list-forms', $reading, true ), 'A chosen module alone is read only' );
security_assert( array() === abilities_with( array( $modules => array() ) ), 'Choosing no module hides everything' );
security_assert( array() === abilities_with( array( $modules => '' ) ), 'An empty stored value also hides everything, it never opens everything' );
$odd = abilities_with( array( $modules => array( 'coupons', array( 'x' ), 5 ) ) );
security_assert( in_array( 'list-coupons', $odd, true ) && ! in_array( 'list-forms', $odd, true ), 'Odd entries in the stored list are ignored' );

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
$off = abilities_with( array( 'user_registration_ability_modules' => array( 'coupons' ) ) );
security_assert( ! in_array( 'list-forms', $off, true ), 'A hidden built-in ability cannot be replaced by an addon using its name' );

// Another module can hide abilities through the filter.
$seen = array();
add_filter(
	'user_registration_ability_enabled',
	function ( $enabled, $slug, $name, $level ) use ( &$seen ) {
		$seen[ $name ] = array( $slug, $level );
		return 'get-member' === $name ? false : $enabled;
	}
);
$paused = abilities_with();
security_assert( ! in_array( 'get-member', $paused, true ) && in_array( 'list-members', $paused, true ), 'A module can hide one ability through the filter' );
security_assert( array( 'members', 'read' ) === $seen['get-member'] && array( 'coupons', 'write' ) === $seen['update-coupon'], 'The filter receives the area and the level' );

// The settings: one card with the module list and the changes switch.
$GLOBALS['options'] = array();
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

security_assert( array( 'ai_integration', 'ai_abilities_access' ) === array_keys( $cards ), 'The existing card is kept, followed by one access card' );
security_assert( array( $modules, $changes ) === array_keys( $by_id ), 'The card has exactly two settings: the module list and the changes switch' );
security_assert( 'multiselect' === $by_id[ $modules ]['type'] && 'Enabled Abilities' === $by_id[ $modules ]['title'], 'The module list is a multiselect called Enabled Abilities' );
security_assert( isset( $by_id[ $modules ]['options']['forms'], $by_id[ $modules ]['options']['members'], $by_id[ $modules ]['options']['settings'] ) && 'Coupons' === $by_id[ $modules ]['options']['coupons'], 'Every area, addon areas included, is an option under its own name' );
security_assert( ! isset( $by_id[ $modules ]['options']['broken'] ) && ! isset( $by_id[ $modules ]['options']['nameless'] ), 'An area without a usable definition is not offered' );
security_assert( array_keys( $by_id[ $modules ]['options'] ) === $by_id[ $modules ]['default'], 'A new site starts with every module chosen' );
security_assert( 'toggle' === $by_id[ $changes ]['type'] && 'no' === $by_id[ $changes ]['default'], 'Changes are a toggle, off by default' );
security_assert( 'user_registration_enable_abilities' === $card['display_condition']['field'] && 'yes' === $card['display_condition']['value'], 'The card shows only while AI abilities are switched on' );

$odd = UR_Abilities_Access::add_settings( 'not-settings' );
security_assert( 'not-settings' === $odd, 'Settings of an unexpected shape are returned untouched' );

UR_Abilities_Access::init();
security_assert( ! empty( $GLOBALS['filters']['user_registration_settings_advanced_ai_integration'] ), 'The settings are added through the AI Integration section filter' );
