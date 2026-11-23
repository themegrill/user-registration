<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: one switch pauses every AI change while reading stays available, and a risky ability only shows a
 * preview until it is called again with the single-use code that preview handed out. The code works once, only for
 * the exact same request and the same user, and expires.
 *
 * Runs the real `UR_Abilities`, `UR_Abilities_Access` and `UR_Abilities_Safety` classes with WordPress test doubles;
 * no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {
	public $code;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}
}

class UR_Abilities_Data {
	public static function __callStatic( $name, $args ) {
		return array();
	}
}

class Addon_Abilities {
	public static $runs = array();

	public static function allowed() {
		return true;
	}

	public static function run( $input = null ) {
		self::$runs[] = $input;
		return array( 'ok' => true );
	}

	public static function preview( $input = null ) {
		return 'This would cancel ' . ( $input['member'] ?? 'nobody' ) . '.';
	}

	public static function bad_preview() {
		return '';
	}

	public static function failing_preview() {
		return new WP_Error( 'not_found', 'No such member.', array( 'status' => 404 ) );
	}
}

$registered = array();
$filters    = array();
$options    = array();
$transients = array();
$user_id    = 7;
$counter    = 0;

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
function ur_get_license_plan() {
	return false;
}
function ur_utm_url( $url ) {
	return $url;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
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

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-access.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-safety.php';

function member_ability( $overrides = array() ) {
	return $overrides + array(
		'label'       => 'Cancel membership',
		'description' => 'Cancel a membership.',
		'level'       => 'write',
		'input'       => array( 'member' => array( 'type' => 'string' ) ),
		'required'    => array( 'member' ),
		'output'      => UR_Abilities::object_schema( array( 'ok' => array( 'type' => 'boolean' ) ) ),
		'permission'  => array( 'Addon_Abilities', 'allowed' ),
		'execute'     => array( 'Addon_Abilities', 'run' ),
		'confirm'     => true,
		'preview'     => array( 'Addon_Abilities', 'preview' ),
	);
}

add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['people'] = array(
			'label'     => 'People',
			'abilities' => array(
				'see-member'     => member_ability(
					array(
						'level'   => 'read',
						'confirm' => false,
					)
				),
				'rename-member'  => member_ability( array( 'confirm' => false ) ),
				'cancel-member'  => member_ability(),
				'remove-member'  => member_ability( array( 'level' => 'delete' ) ),
				'no-preview'     => member_ability( array( 'preview' => null ) ),
			),
		);
		return $areas;
	}
);
UR_Abilities_Safety::init();

function abilities_registered( $options ) {
	$GLOBALS['registered'] = array();
	$GLOBALS['options']    = $options;
	UR_Abilities::register_abilities();
	return $GLOBALS['registered'];
}

$open = array(
	'user_registration_enable_abilities'      => 'yes',
	'user_registration_ability_access_people' => 'delete',
);

// The pause switch hides every change and leaves reading alone.
$names = array_keys( abilities_registered( $open ) );
security_assert( in_array( 'user-registration/rename-member', $names, true ) && in_array( 'user-registration/remove-member', $names, true ), 'Changes are available while the pause switch is off' );

$names = array_keys( abilities_registered( $open + array( 'user_registration_pause_ai_changes' => 'yes' ) ) );
security_assert( ! in_array( 'user-registration/rename-member', $names, true ) && ! in_array( 'user-registration/cancel-member', $names, true ) && ! in_array( 'user-registration/remove-member', $names, true ), 'The pause switch hides every ability that changes or deletes data' );
security_assert( in_array( 'user-registration/see-member', $names, true ) && in_array( 'user-registration/list-forms', $names, true ), 'The pause switch leaves reading available' );
security_assert( false === UR_Abilities_Safety::maybe_pause( false, 'x', 'y', 'read' ), 'The pause switch never switches back on an ability another rule hid' );

// The pause switch is added to the access card from a hook.
$card     = array( 'sections' => array( 'ai_abilities_access' => array( 'settings' => array( array( 'id' => 'user_registration_ability_access_forms' ) ) ) ) );
$settings = UR_Abilities_Safety::add_settings( $card );
$first    = $settings['sections']['ai_abilities_access']['settings'][0];
security_assert( 'user_registration_pause_ai_changes' === $first['id'] && 'toggle' === $first['type'] && 'no' === $first['default'], 'The pause switch is a toggle at the top of the access card, off by default' );
security_assert( 2 === count( $settings['sections']['ai_abilities_access']['settings'] ), 'The area settings stay after the pause switch' );
security_assert( array( 'sections' => array() ) === UR_Abilities_Safety::add_settings( array( 'sections' => array() ) ), 'Without the access card the pause switch adds nothing' );
security_assert( 'odd' === UR_Abilities_Safety::add_settings( 'odd' ), 'Settings of an unexpected shape are returned untouched' );

// A risky ability shows a preview first.
$all = abilities_registered( $open );
security_assert( isset( $all['user-registration/cancel-member'] ) && ! isset( $all['user-registration/no-preview'] ), 'An ability that needs a preview but has none is never registered' );
security_assert( isset( $all['user-registration/cancel-member']['input_schema']['properties']['confirm_token'] ), 'A previewed ability accepts a confirmation code' );
security_assert( ! isset( $all['user-registration/rename-member']['input_schema']['properties']['confirm_token'] ), 'An ability without a preview does not accept a code' );
security_assert( array( 'member' ) === $all['user-registration/cancel-member']['input_schema']['required'], 'The code is never required' );
security_assert( 2 === count( $all['user-registration/cancel-member']['output_schema']['oneOf'] ), 'The answer may be the normal result or a preview' );

$cancel = $all['user-registration/cancel-member']['execute_callback'];
$first  = $cancel( array( 'member' => 'Ana' ) );
security_assert( true === $first['needs_confirmation'] && 'This would cancel Ana.' === $first['preview'], 'The first call returns a plain preview' );
security_assert( array() === Addon_Abilities::$runs, 'Nothing runs on the first call' );
security_assert( 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $first['confirm_token'] ) && 60 === $first['expires_in'], 'The code is 32 letters and digits and lasts a minute' );

$done = $cancel(
	array(
		'member'        => 'Ana',
		'confirm_token' => $first['confirm_token'],
	)
);
security_assert( array( 'ok' => true ) === $done && 1 === count( Addon_Abilities::$runs ), 'The second call with the code runs the ability once' );
security_assert( array( 'member' => 'Ana' ) === Addon_Abilities::$runs[0], 'The ability never sees the code' );

$again = $cancel(
	array(
		'member'        => 'Ana',
		'confirm_token' => $first['confirm_token'],
	)
);
security_assert( is_wp_error( $again ) && 'ur_ability_confirm_invalid' === $again->code && 400 === $again->data['status'], 'A used code is refused' );
security_assert( 1 === count( Addon_Abilities::$runs ), 'A used code runs nothing' );

// A code only works for the exact request.
$other = $cancel( array( 'member' => 'Ana' ) );
$wrong = $cancel(
	array(
		'member'        => 'Bob',
		'confirm_token' => $other['confirm_token'],
	)
);
security_assert( is_wp_error( $wrong ) && 1 === count( Addon_Abilities::$runs ), 'A code made for one request cannot run another' );
$retry = $cancel(
	array(
		'member'        => 'Ana',
		'confirm_token' => $other['confirm_token'],
	)
);
security_assert( is_wp_error( $retry ), 'A code that was tried on the wrong request is spent' );

// A code only works for the same user, and only before it expires.
$mine           = $cancel( array( 'member' => 'Ana' ) );
$GLOBALS['user_id'] = 8;
$stolen         = $cancel(
	array(
		'member'        => 'Ana',
		'confirm_token' => $mine['confirm_token'],
	)
);
$GLOBALS['user_id'] = 7;
security_assert( is_wp_error( $stolen ) && 1 === count( Addon_Abilities::$runs ), 'Another user cannot use the code' );

$old = $cancel( array( 'member' => 'Ana' ) );
unset( $GLOBALS['transients'][ UR_Abilities_Safety::TRANSIENT_PREFIX . $old['confirm_token'] ] );
$late = $cancel(
	array(
		'member'        => 'Ana',
		'confirm_token' => $old['confirm_token'],
	)
);
security_assert( is_wp_error( $late ) && 1 === count( Addon_Abilities::$runs ), 'An expired code is refused' );
$made = $cancel( array( 'member' => 'Ana' ) );
security_assert( 60 === $GLOBALS['transients'][ UR_Abilities_Safety::TRANSIENT_PREFIX . $made['confirm_token'] ][1], 'The code is stored for the time it is valid' );

$guess = $cancel(
	array(
		'member'        => 'Ana',
		'confirm_token' => 'not-a-real-code',
	)
);
security_assert( is_wp_error( $guess ) && 1 === count( Addon_Abilities::$runs ), 'A made-up code runs nothing' );

// The same request is recognised whatever the order of its keys.
$ordered = $cancel(
	array(
		'member' => 'Ana',
		'note'   => 'x',
	)
);
$runs    = count( Addon_Abilities::$runs );
$swapped = $cancel(
	array(
		'note'          => 'x',
		'member'        => 'Ana',
		'confirm_token' => $ordered['confirm_token'],
	)
);
security_assert( array( 'ok' => true ) === $swapped && $runs + 1 === count( Addon_Abilities::$runs ), 'The same request in another key order still matches its code' );

// A delete ability previews too, and runs nothing yet.
$remove = $all['user-registration/remove-member']['execute_callback'];
$ask    = $remove( array( 'member' => 'Ana' ) );
security_assert( true === $ask['needs_confirmation'] && $runs + 1 === count( Addon_Abilities::$runs ), 'A delete ability shows a preview and runs nothing yet' );

// A preview that fails never hands out a code.
add_filter(
	'user_registration_ability_areas',
	function ( $areas ) {
		$areas['people']['abilities']['empty-preview']  = member_ability( array( 'preview' => array( 'Addon_Abilities', 'bad_preview' ) ) );
		$areas['people']['abilities']['failed-preview'] = member_ability( array( 'preview' => array( 'Addon_Abilities', 'failing_preview' ) ) );
		return $areas;
	}
);
$all = abilities_registered( $open );
$before = count( $GLOBALS['transients'] );
$empty  = $all['user-registration/empty-preview']['execute_callback']( array( 'member' => 'Ana' ) );
$failed = $all['user-registration/failed-preview']['execute_callback']( array( 'member' => 'Ana' ) );
security_assert( is_wp_error( $empty ) && 'ur_ability_preview_failed' === $empty->code, 'An empty preview is an error, not a code' );
security_assert( is_wp_error( $failed ) && 'not_found' === $failed->code, 'An error from the preview is passed on' );
security_assert( $before === count( $GLOBALS['transients'] ), 'A failed preview stores no code' );

// An ability without a preview step runs straight away as before.
$plain = $all['user-registration/rename-member']['execute_callback']( array( 'member' => 'Ana' ) );
security_assert( array( 'ok' => true ) === $plain, 'An ability without a preview runs straight away' );

// The lifetime of a code can be changed, within limits.
add_filter(
	'user_registration_ability_confirm_ttl',
	function () {
		return 5000;
	}
);
$long = $all['user-registration/cancel-member']['execute_callback']( array( 'member' => 'Ana' ) );
security_assert( 300 === $long['expires_in'], 'A code never lasts longer than five minutes' );
