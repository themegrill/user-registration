<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: the AI user holds no admin rights. While one ability the admin switched on runs for that user, it is
 * lent exactly the rights the ability asks for, and nothing else. Nothing is lent outside a call, to a refused
 * check, to a right outside the allowed list, or to a user without the AI right. The AI user cannot sign in with
 * a password.
 *
 * Runs the real `UR_Abilities_Role` class with a small stand-in for WordPress rights; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {
	public $code;

	public function __construct( $code = '' ) {
		$this->code = $code;
	}
}

class WP_User {
	public $roles;
	public $allcaps;

	public function __construct( $roles, $caps ) {
		$this->roles   = $roles;
		$this->allcaps = $caps;
	}
}

$filters = array();
$current = null;

function add_filter( $hook, $callback ) {
	$GLOBALS['filters'][ $hook ][] = $callback;
}
function apply_filters_ref( $hook, $args ) {
	$value = $args[0];
	foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
		$args[0] = $value;
		$value   = $callback( ...$args );
	}
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_get_current_user() {
	return $GLOBALS['current'];
}
function get_userdata( $id ) {
	return $GLOBALS['current'];
}
function get_role( $name ) {
	return false;
}
if ( ! class_exists( 'Role_Test_Logger' ) ) {
	class Role_Test_Logger {
		public $lines = array();

		public function warning( $message, $context = array() ) {
			$this->lines[] = array( $message, $context['source'] ?? '' );
		}
	}
}
function ur_get_logger() {
	$GLOBALS['role_logger'] = $GLOBALS['role_logger'] ?? new Role_Test_Logger();
	return $GLOBALS['role_logger'];
}
function add_role( $name, $label, $caps ) {
	$GLOBALS['role_added'] = array( $name, $label, $caps );
}

/** A stand-in for current_user_can: the rights of the user, then what the filter lends. */
function can( $cap, $user = null ) {
	$user = $user ? $user : $GLOBALS['current'];
	$caps = apply_filters_ref( 'user_has_cap', array( $user->allcaps, array( $cap ), array( $cap ), $user ) );
	return ! empty( $caps[ $cap ] );
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-role.php';
UR_Abilities_Role::init();

$ai    = new WP_User( array( 'ur_ai_assistant' ), array( 'read' => true, 'ur_ai_abilities' => true ) );
$admin = new WP_User( array( 'administrator' ), array( 'read' => true, 'manage_options' => true, 'list_users' => true ) );
$stray = new WP_User( array( 'ur_ai_assistant' ), array( 'read' => true ) );

// Who the AI user is.
$GLOBALS['current'] = $ai;
security_assert( UR_Abilities_Role::is_ai_user() && UR_Abilities_Role::is_ai_user( $ai ) && ! UR_Abilities_Role::is_ai_user( $admin ), 'The AI user is the one with the AI role' );
UR_Abilities_Role::ensure_role();
security_assert( 'ur_ai_assistant' === $GLOBALS['role_added'][0] && array( 'read' => true, 'ur_ai_abilities' => true ) === $GLOBALS['role_added'][2], 'The role holds the read right and the one AI right, nothing else' );

// Outside a call nothing is lent.
security_assert( ! can( 'list_users' ) && ! can( 'manage_options' ) && ! can( 'edit_others_posts' ) && can( 'read' ), 'Outside an ability call the AI user has only the read right' );

// A permission check: rights it asks for are lent while it runs, and others are not.
$asked = array();
$check = UR_Abilities_Role::wrap_permission(
	'list-members',
	function () use ( &$asked ) {
		$asked = array( 'list_users' => can( 'list_users' ), 'delete_users' => can( 'delete_users' ), 'install_plugins' => can( 'install_plugins' ), 'edit_plugins' => can( 'edit_plugins' ) );
		return $asked['list_users'] && $asked['delete_users'];
	}
);
security_assert( true === $check(), 'A check for rights on the allowed list passes for the AI user' );
security_assert( ! $asked['install_plugins'] && ! $asked['edit_plugins'], 'A right outside the allowed list is never lent, even when asked for' );
security_assert( ! can( 'list_users' ), 'The rights are taken back as soon as the check ends' );

// Running the ability: only what the check asked for.
$seen = array();
$run  = UR_Abilities_Role::wrap_execute(
	'list-members',
	function () use ( &$seen ) {
		$seen = array( 'list_users' => can( 'list_users' ), 'delete_users' => can( 'delete_users' ), 'manage_options' => can( 'manage_options' ), 'promote_users' => can( 'promote_users' ) );
		return 'done';
	}
);
security_assert( 'done' === $run(), 'The ability runs and returns its result' );
security_assert( $seen['list_users'] && $seen['delete_users'], 'While it runs, the ability has the rights its check asked for' );
security_assert( ! $seen['manage_options'] && ! $seen['promote_users'], 'It does not get rights its check never asked for' );
security_assert( ! can( 'list_users' ) && ! can( 'delete_users' ), 'Nothing is left lent after the ability ends' );

// An ability that is not checked first lends nothing.
$unchecked_seen = null;
$unchecked      = UR_Abilities_Role::wrap_execute(
	'never-checked',
	function () use ( &$unchecked_seen ) {
		$unchecked_seen = can( 'list_users' );
	}
);
$unchecked();
security_assert( false === $unchecked_seen, 'An ability that did not pass its permission check in this request gets nothing' );

// A refused check lends nothing to the run.
$refused = UR_Abilities_Role::wrap_permission(
	'delete-member',
	function () {
		can( 'delete_users' );
		return false;
	}
);
$refused();
$after_refusal = null;
UR_Abilities_Role::wrap_execute(
	'delete-member',
	function () use ( &$after_refusal ) {
		$after_refusal = can( 'delete_users' );
	}
)();
security_assert( false === $after_refusal, 'A refused permission check lends nothing to the run' );

// Rights the ability's own code asks for deeper down are declared, and lent only if they are on the allowed list.
$declared = UR_Abilities_Role::wrap_permission(
	'approve-member',
	function () {
		return can( 'promote_users' );
	},
	array( 'edit_users', 'install_plugins' )
);
$declared();
$deep = array();
UR_Abilities_Role::wrap_execute(
	'approve-member',
	function () use ( &$deep ) {
		$deep = array( 'edit_users' => can( 'edit_users' ), 'install_plugins' => can( 'install_plugins' ), 'promote_users' => can( 'promote_users' ), 'manage_options' => can( 'manage_options' ) );
	}
)();
security_assert( $deep['edit_users'] && $deep['promote_users'], 'A declared right on the allowed list is lent with the ones the check asked for' );
security_assert( ! $deep['install_plugins'] && ! $deep['manage_options'], 'A declared right outside the list is not lent, and an admin right nobody asked for stays out' );

// A right the ability asked for but did not declare is lent to nobody, and the gap is written to the log.
$GLOBALS['current']     = $ai;
$GLOBALS['role_logger'] = null;
UR_Abilities_Role::wrap_permission(
	'list-members',
	function () {
		return can( 'list_users' );
	}
)();
$gap = null;
UR_Abilities_Role::wrap_execute(
	'list-members',
	function () use ( &$gap ) {
		$gap = can( 'manage_options' ) || can( 'install_plugins' );
	}
)();
$lines = $GLOBALS['role_logger']->lines ?? array();
security_assert( false === $gap, 'A right the ability never declared is not lent' );
security_assert( 1 === count( $lines ) && 'user-registration-ai' === $lines[0][1] && false !== strpos( $lines[0][0], 'list-members' ) && false !== strpos( $lines[0][0], 'manage_options' ), 'The gap is written to the log with the ability and the right, so it can be declared' );
security_assert( false === strpos( $lines[0][0], 'install_plugins' ), 'A right that can never be lent is not reported as a gap' );

$GLOBALS['role_logger'] = null;
UR_Abilities_Role::wrap_permission(
	'list-members',
	function () {
		return can( 'list_users' );
	}
)();
UR_Abilities_Role::wrap_execute(
	'list-members',
	function () {
		can( 'list_users' );
	}
)();
security_assert( empty( $GLOBALS['role_logger']->lines ), 'An ability that only asks for what it declared writes nothing' );

// The user must hold the AI right.
$GLOBALS['current'] = $stray;
$stray_asked        = null;
UR_Abilities_Role::wrap_permission(
	'list-members',
	function () use ( &$stray_asked ) {
		$stray_asked = can( 'list_users' );
		return $stray_asked;
	}
)();
security_assert( false === $stray_asked, 'A user with the role but not the AI right is lent nothing' );

// Everyone else is checked exactly as before.
$GLOBALS['current'] = $admin;
$plain              = UR_Abilities_Role::wrap_permission(
	'list-members',
	function () {
		return can( 'list_users' );
	}
);
security_assert( true === $plain() && ! can( 'delete_users' ), 'An admin is checked as before and nothing is lent to anyone else' );

// A crash inside an ability cannot leave rights lent.
$GLOBALS['current'] = $ai;
UR_Abilities_Role::wrap_permission(
	'list-members',
	function () {
		return can( 'list_users' );
	}
)();
$thrown = false;
try {
	UR_Abilities_Role::wrap_execute(
		'list-members',
		function () {
			throw new RuntimeException( 'boom' );
		}
	)();
} catch ( RuntimeException $e ) {
	$thrown = true;
}
security_assert( $thrown && ! can( 'list_users' ), 'A crash is passed on and leaves nothing lent' );

// The allowed list.
security_assert( UR_Abilities_Role::is_lendable( 'manage_options' ) && UR_Abilities_Role::is_lendable( 'edit_users' ) && UR_Abilities_Role::is_lendable( 'edit_others_user_registrations' ) && UR_Abilities_Role::is_lendable( 'delete_published_posts' ), 'The rights abilities need are on the list' );
foreach ( array( 'install_plugins', 'edit_plugins', 'edit_themes', 'update_core', 'create_users', 'unfiltered_html', 'do_not_allow', 'edit_files', 'export' ) as $never ) {
	security_assert( ! UR_Abilities_Role::is_lendable( $never ), 'The right ' . $never . ' can never be lent' );
}

// No password sign-in.
$blocked = UR_Abilities_Role::refuse_password_login( $ai );
security_assert( is_wp_error( $blocked ) && 'ur_ai_user_no_login' === $blocked->code, 'The AI user cannot sign in with a password' );
security_assert( $admin === UR_Abilities_Role::refuse_password_login( $admin ), 'Everyone else signs in as before' );
$already = new WP_Error( 'wrong_password' );
security_assert( $already === UR_Abilities_Role::refuse_password_login( $already ), 'A sign-in that already failed is left as it was' );
