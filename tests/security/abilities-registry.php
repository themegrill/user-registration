<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: every AI ability goes through one shared registration, so the built-in nine keep their names and
 * read-only behaviour, and an addon can add its own from its own code through a filter. A broken or duplicate
 * ability is skipped, level decides the safety hints, and a plan-locked area tells the AI which plan it needs.
 *
 * Runs the real `UR_Abilities` class with WordPress test doubles; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {
	/**
	 * Error code.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Error data.
	 *
	 * @var mixed
	 */
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
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
$enabled    = true;
$site_plan  = '';

function add_action() {}
function add_filter( $hook, $callback ) {
	$GLOBALS['filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function ur_option_checked() {
	return $GLOBALS['enabled'];
}
function ur_get_license_plan() {
	return $GLOBALS['site_plan'] ? (object) array( 'item_plan' => $GLOBALS['site_plan'] ) : false;
}
function ur_utm_url( $url, $args ) {
	return $url . '?content=' . $args['content'];
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['registered'][ $name ] = $args;
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-activity.php';
require __DIR__ . '/abilities-activity-stubs.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-access.php';

function abilities_after_register( $enabled = true, $site_plan = '' ) {
	$GLOBALS['registered'] = array();
	$GLOBALS['enabled']    = $enabled;
	$GLOBALS['site_plan']  = $site_plan;
	UR_Abilities::register_abilities();
	return $GLOBALS['registered'];
}

function addon_ability( $overrides = array() ) {
	return $overrides + array(
		'label'       => 'Addon ability',
		'description' => 'Does something.',
		'output'      => UR_Abilities::object_schema( array( 'ok' => array( 'type' => 'boolean' ) ) ),
		'permission'  => array( 'Addon_Abilities', 'allowed' ),
		'execute'     => array( 'Addon_Abilities', 'run' ),
	);
}

// The built-in nine keep their names and stay read only.
$core = array_keys( abilities_after_register() );
security_assert(
	array(
		'user-registration/list-forms',
		'user-registration/get-form',
		'user-registration/get-registration-stats',
		'user-registration/list-members',
		'user-registration/get-member',
		'user-registration/list-memberships',
		'user-registration/list-subscriptions',
		'user-registration/list-orders',
		'user-registration/get-order',
	) === $core,
	'The nine existing abilities keep their names and order'
);
foreach ( $GLOBALS['registered'] as $name => $ability ) {
	security_assert( true === $ability['meta']['annotations']['readonly'] && false === $ability['meta']['annotations']['destructive'], $name . ' stays read only' );
}
security_assert( array() === abilities_after_register( false ), 'Nothing is registered while the admin has abilities switched off' );

// An addon adds its own area from its own code.
add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['addon'] = array(
			'label'     => 'Addon',
			'abilities' => array(
				'addon-read'   => addon_ability(),
				'addon-write'  => addon_ability( array( 'level' => 'write' ) ),
				'addon-delete' => addon_ability( array( 'level' => 'delete' ) ),
				'addon-broken' => addon_ability( array( 'execute' => 'no_such_function' ) ),
				'addon-bare'   => array( 'label' => 'No callbacks' ),
				'bad-input'    => addon_ability( array( 'input' => 'not-an-array' ) ),
				'bad-required' => addon_ability( array( 'required' => 'id' ) ),
				'bad-output'   => addon_ability( array( 'output' => 'text' ) ),
				'bad-label'    => addon_ability( array( 'label' => array( 'x' ) ) ),
				'list-forms'   => addon_ability(),
			),
		);
		return $areas;
	}
);
// The admin has opened the addon area up to deleting, so every level is registered.
$GLOBALS['options']['user_registration_ability_access_addon'] = 'delete';
$all = abilities_after_register();

security_assert( isset( $all['user-registration/addon-read'] ), 'An addon area is registered through the filter' );
security_assert( 9 + 3 === count( $all ), 'A broken, an incomplete and a duplicate-named ability are skipped' );
security_assert( ! isset( $all['user-registration/addon-broken'], $all['user-registration/addon-bare'] ), 'An ability without working callbacks is never registered' );
security_assert( ! isset( $all['user-registration/bad-input'], $all['user-registration/bad-required'], $all['user-registration/bad-output'], $all['user-registration/bad-label'] ), 'A definition with the wrong kind of value is skipped and never stops the other abilities' );
security_assert( 'Addon ability' !== $all['user-registration/list-forms']['label'], 'A duplicate name cannot replace a built-in ability' );

$read   = $all['user-registration/addon-read']['meta']['annotations'];
$write  = $all['user-registration/addon-write']['meta']['annotations'];
$delete = $all['user-registration/addon-delete']['meta']['annotations'];
security_assert( true === $read['readonly'] && true === $read['idempotent'], 'A read ability is marked read only and repeatable' );
security_assert( false === $write['readonly'] && false === $write['destructive'], 'A write ability is marked as changing data but not destructive' );
security_assert( false === $delete['readonly'] && true === $delete['destructive'], 'A delete ability is marked destructive so the AI app can ask first' );
security_assert( true === $all['user-registration/addon-read']['permission_callback'](), 'A free area runs the ability own permission check' );

// A plan-locked area tells the AI which plan it needs.
add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['coupons'] = array(
			'label'     => 'Coupons',
			'plan'      => array( 'plus', 'professional' ),
			'abilities' => array( 'list-coupons' => addon_ability() ),
		);
		return $areas;
	}
);
$locked = abilities_after_register( true, 'personal' )['user-registration/list-coupons']['permission_callback']();
security_assert( is_wp_error( $locked ) && 'ur_ability_plan_required' === $locked->code, 'A site without the plan gets a plan error' );
security_assert( 403 === $locked->data['status'] && array( 'plus', 'professional' ) === $locked->data['required_plans'], 'The error is a 403 that names the plans' );
security_assert( false !== strpos( $locked->data['upgrade_url'], 'content=coupons' ), 'The error carries the upgrade link for the area' );
security_assert( is_wp_error( abilities_after_register( true )['user-registration/list-coupons']['permission_callback']() ), 'A site with no license is locked out of a paid area' );
security_assert( true === abilities_after_register( true, 'professional' )['user-registration/list-coupons']['permission_callback'](), 'A site on a listed plan can use the area' );
security_assert( true === abilities_after_register( true, ' plus' )['user-registration/list-coupons']['permission_callback'](), 'A plan name with a leading space from the license lookup still matches' );
security_assert( true === abilities_after_register( true, 'Professional ' )['user-registration/list-coupons']['permission_callback'](), 'Capital letters and trailing spaces in the plan name do not lock a licensed site out' );

// A filter that returns something other than a list of areas cannot break registration.
add_filter(
	'user_registration_ability_areas',
	function () {
		return false;
	}
);
security_assert( array() === abilities_after_register(), 'A broken filter result registers nothing and does not fail' );
