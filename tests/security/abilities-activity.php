<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: every AI action is written to the plugin's own log as one line, and the log never holds member
 * details. Refused calls, failed calls and previews are recorded as well as finished ones, the line names the
 * user and the AI app. The lines follow the global Enable Log switch like every other plugin log.
 *
 * Runs the real `UR_Abilities_Activity` class with WordPress test doubles; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {
	private $code;

	public function __construct( $code = '' ) {
		$this->code = $code;
	}

	public function get_error_code() {
		return $this->code;
	}
}

$actions = array();
$user_id = 3;

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-activity.php';
require __DIR__ . '/abilities-activity-stubs.php';

function last_line() {
	$lines = ur_get_logger()->lines;
	return end( $lines );
}

function logged_count() {
	return count( ur_get_logger()->lines );
}

// A finished read: one line, with the item count and no content.
$read = UR_Abilities_Activity::wrap_execute(
	'list-memberships',
	'read',
	function () {
		return array( 'items' => array( array( 'name' => 'Paid Membership' ), array( 'name' => 'Free' ), array() ) );
	}
);
$result = $read( array( 'page' => 1 ) );
list( $level, $line, $source ) = last_line();
security_assert( 3 === count( $result['items'] ), 'The ability still returns its result untouched' );
security_assert( 1 === logged_count() && 'info' === $level && 'user-registration-ai' === $source, 'A finished read is one info line under the AI source' );
security_assert( 1 === preg_match( '/^AI read list-memberships by user 3: success, 3 items, \d+ ms$/', $line ), 'The line names the level, ability, user, result, item count and time' );
security_assert( false === strpos( $line, 'Paid Membership' ) && false === strpos( $line, 'Free' ), 'What the ability returned is never written' );

// Ids are written, names and other values are not.
$ask = UR_Abilities_Activity::wrap_execute(
	'get-member',
	'read',
	function () {
		return array( 'id' => 42 );
	}
);
$ask(
	array(
		'id'        => 42,
		'member_id' => 7,
		'form_id'   => '9',
		'email'     => 'ana@example.com',
		'name'      => 'Ana Smith',
		'note'      => 'private note',
		'search_id' => 'abc',
	)
);
list( , $line ) = last_line();
security_assert( false !== strpos( $line, 'id:42,member_id:7' ), 'The record ids the AI asked about are written' );
security_assert( false === strpos( $line, 'ana@example.com' ) && false === strpos( $line, 'Ana' ) && false === strpos( $line, 'private note' ), 'Emails, names and other input values are never written' );
security_assert( false === strpos( $line, 'form_id' ) && false === strpos( $line, 'abc' ), 'Only whole-number ids are written' );

// A refused call is recorded with the reason.
$before  = logged_count();
$refuse  = UR_Abilities_Activity::wrap_permission(
	'update-member',
	'write',
	function () {
		return false;
	}
);
$allowed = UR_Abilities_Activity::wrap_permission(
	'list-forms',
	'read',
	function () {
		return true;
	}
);
security_assert( false === $refuse( array( 'id' => 5 ) ) && true === $allowed(), 'The permission result is passed on untouched' );
list( $level, $line ) = last_line();
security_assert( $before + 1 === logged_count(), 'An allowed permission check writes nothing, the run itself is recorded' );
security_assert( 'warning' === $level && 1 === preg_match( '/^AI write update-member by user 3: refused, forbidden, id:5, \d+ ms$/', $line ), 'A refused call is a warning with the reason' );

$locked = UR_Abilities_Activity::wrap_permission(
	'list-coupons',
	'read',
	function () {
		return new WP_Error( 'ur_ability_plan_required' );
	}
);
$locked();
list( $level, $line ) = last_line();
security_assert( 'warning' === $level && false !== strpos( $line, 'refused, ur_ability_plan_required' ), 'A refusal because of the plan carries its own reason' );

// A failed run, a preview and a crash.
$fail = UR_Abilities_Activity::wrap_execute(
	'get-order',
	'read',
	function () {
		return new WP_Error( 'ur_not_found' );
	}
);
$error = $fail( array( 'id' => 8 ) );
list( $level, $line ) = last_line();
security_assert( is_wp_error( $error ) && 'error' === $level && false !== strpos( $line, 'error, ur_not_found, id:8' ), 'A failed run is an error line with its reason, and the error is passed on' );

$preview = UR_Abilities_Activity::wrap_execute(
	'cancel-membership',
	'write',
	function () {
		return array(
			'needs_confirmation' => true,
			'preview'            => 'This would cancel Ana.',
		);
	}
);
$preview( array( 'id' => 12 ) );
list( $level, $line ) = last_line();
security_assert( 'info' === $level && false !== strpos( $line, 'AI write cancel-membership by user 3: preview, id:12' ), 'A preview is recorded as waiting, and its text is not' );
security_assert( false === strpos( $line, 'Ana' ), 'The preview text is never written' );

$crash = UR_Abilities_Activity::wrap_execute(
	'list-orders',
	'read',
	function () {
		throw new RuntimeException( 'boom' );
	}
);
$thrown = null;
try {
	$crash( array() );
} catch ( RuntimeException $e ) {
	$thrown = $e;
}
list( $level, $line ) = last_line();
security_assert( $thrown && 'boom' === $thrown->getMessage(), 'A crash is passed on as before' );
security_assert( 'error' === $level && false !== strpos( $line, 'error, exception' ), 'A crash is recorded as an error' );

// The entry is handed to the hook without member details.
$row = end( $GLOBALS['actions']['user_registration_ai_activity_logged'] )[0];
security_assert( 3 === $row['user_id'] && 'list-orders' === $row['ability'] && 'error' === $row['outcome'], 'The hook receives the entry' );
security_assert( array( 'user_id', 'app', 'ability', 'level', 'outcome', 'code', 'targets', 'items', 'duration_ms' ) === array_keys( $row ), 'The entry has only the allowed fields' );

// The AI app is the name of the application password. The doubles are declared only now, so the lines above had none.
$no_app_before = false === strpos( $line, '(' );
if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		public static function get_user_application_passwords( $user_id ) {
			return array(
				array(
					'uuid' => 'aaa',
					'name' => 'Cursor',
				),
				array(
					'uuid' => 'bbb',
					'name' => 'Claude',
				),
			);
		}
	}
}
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
	function rest_get_authenticated_app_password() {
		return 'bbb';
	}
}
$read( array() );
list( , $line ) = last_line();
security_assert( false !== strpos( $line, 'by user 3 (Claude): success' ), 'The AI app is named after its application password' );
security_assert( $no_app_before, 'A call that did not come through an application password names no app' );

// The AI lines follow the global Enable Log switch like every other plugin log, so the logger gets no exception.
$logger = security_function( 'includes/class-ur-logger.php', 'log' );
security_assert( false === strpos( $logger, 'user-registration-ai' ) && false !== strpos( $logger, 'user_registration_enable_log' ), 'The logger holds back the AI source while Enable Log is off' );
security_assert( 'user-registration-ai' === UR_Abilities_Activity::SOURCE, 'Every AI line is written under the AI source' );
