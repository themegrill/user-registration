<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: one click makes a user just for the AI and its application password, and gives each AI app what it
 * needs. The user is made once and reused. The password is shown once and never stored. The check says what is ready
 * and what is not. Only a user who manages the site, with a valid nonce, can make or remove a connection.
 *
 * Runs the real `UR_Abilities_Connect` class with WordPress test doubles; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class WP_User {
	public $ID;
	public $user_login;
	public $roles;

	public function __construct( $id, $login, $roles ) {
		$this->ID         = $id;
		$this->user_login = $login;
		$this->roles      = $roles;
	}
}

class WP_Application_Passwords {
	public static $store = array();

	public static function get_user_application_passwords( $user_id ) {
		return self::$store[ $user_id ] ?? array();
	}

	public static function create_new_application_password( $user_id, $args ) {
		$plain                      = 'abcdefghijklmnopqrstuvwx';
		self::$store[ $user_id ][] = array(
			'uuid' => 'uuid-' . ( count( self::$store[ $user_id ] ?? array() ) + 1 ),
			'name' => $args['name'],
		);
		return array( $plain, end( self::$store[ $user_id ] ) );
	}

	public static function chunk_password( $password ) {
		return trim( chunk_split( $password, 4, ' ' ) );
	}

	public static function delete_application_password( $user_id, $uuid ) {
		foreach ( self::$store[ $user_id ] ?? array() as $i => $item ) {
			if ( $item['uuid'] === $uuid ) {
				unset( self::$store[ $user_id ][ $i ] );
				return true;
			}
		}
		return false;
	}
}

class Routes_Double {
	public function get_routes() {
		return $GLOBALS['routes'];
	}
}

$users   = array();
$created = array();
$options = array();
$routes  = array( '/mcp/mcp-adapter-default-server' => array() );
$flags   = array(
	'apps'    => true,
	'ssl'     => true,
	'plugin'  => true,
	'enabled' => true,
	'abilities' => array( 'user-registration/list-forms' ),
);

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function add_filter() {}
function add_action() {}
function get_users( $args ) {
	return array_values(
		array_filter(
			$GLOBALS['users'],
			function ( $user ) use ( $args ) {
				return in_array( $args['role'], $user->roles, true );
			}
		)
	);
}
function username_exists( $login ) {
	foreach ( $GLOBALS['users'] as $user ) {
		if ( $user->user_login === $login ) {
			return $user->ID;
		}
	}
	return false;
}
function wp_insert_user( $data ) {
	$GLOBALS['created'][] = $data;
	$id                   = 100 + count( $GLOBALS['users'] );
	$GLOBALS['users'][]   = new WP_User( $id, $data['user_login'], array( $data['role'] ) );
	return $id;
}
function get_userdata( $id ) {
	foreach ( $GLOBALS['users'] as $user ) {
		if ( $user->ID === $id ) {
			return $user;
		}
	}
	return false;
}
function get_role( $name ) {
	return true;
}
function wp_is_application_passwords_available() {
	return $GLOBALS['flags']['apps'];
}
function wp_generate_password( $length = 12 ) {
	return str_repeat( 'x', $length );
}
function home_url() {
	return 'https://shop.example.com';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function sanitize_title( $text ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' );
}
function rest_url( $path ) {
	return 'https://shop.example.com/wp-json/' . $path;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function rest_get_server() {
	return new Routes_Double();
}
function is_plugin_active( $plugin ) {
	return $GLOBALS['flags']['plugin'];
}
function is_ssl() {
	return $GLOBALS['flags']['ssl'];
}
function wp_get_environment_type() {
	return 'production';
}
function wp_get_abilities() {
	return array_fill_keys( $GLOBALS['flags']['abilities'], true );
}
function ur_option_checked() {
	return $GLOBALS['flags']['enabled'];
}
function human_time_diff( $from, $to ) {
	return ( $to - $from ) . ' seconds';
}
function wp_send_json_error_double() {}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return new stdClass();
	}
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-role.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-connect.php';

function by_id( $checks ) {
	return array_column( $checks, null, 'id' );
}

// Nothing exists yet: the AI user is made, once, with a role and no password anyone sees.
$result = UR_Abilities_Connect::create_connection( 'Claude' );
security_assert( ! is_wp_error( $result ) && 'ur-ai-assistant' === $result['login'], 'One call makes the AI user' );
security_assert( 1 === count( $created ) && 'ur_ai_assistant' === $created[0]['role'] && 64 === strlen( $created[0]['user_pass'] ), 'It gets the AI role and a long random password' );
security_assert( 'ur-ai-assistant@shop.example.com' === $created[0]['user_email'], 'Its email is built from the site address' );
security_assert( 'false' === $created[0]['show_admin_bar_front'], 'It has no admin bar' );

// What comes back: the password once, and the code every AI app needs.
security_assert( 'abcd efgh ijkl mnop qrst uvwx' === $result['password'], 'The password is shown in groups, to read and copy' );
security_assert( base64_encode( 'ur-ai-assistant:abcdefghijklmnopqrstuvwx' ) === $result['code'], 'The login code is the user name and the password without spaces' );
security_assert( 'https://shop.example.com/wp-json/mcp/mcp-adapter-default-server' === $result['endpoint'], 'The connection address is the MCP Adapter route of the site' );
security_assert( 'Claude' === WP_Application_Passwords::$store[ $result['login'] === 'ur-ai-assistant' ? 100 : 0 ][0]['name'], 'The password is named after the AI app, so the log can say which app called' );
security_assert( ! isset( $options['user_registration_ai_password'] ) && false === strpos( wp_json_encode( $GLOBALS['options'] ), 'abcdefgh' ), 'The password is not stored anywhere' );

// The second time reuses the same user.
$second = UR_Abilities_Connect::create_connection( 'Cursor' );
security_assert( 1 === count( $created ) && 'ur-ai-assistant' === $second['login'] && 2 === count( WP_Application_Passwords::$store[100] ), 'A second app gets its own password on the same user, no second user' );

// Each app gets what it needs.
$snippets = $result['snippets'];
security_assert( 0 === strpos( $snippets['claude'], 'claude mcp add --transport http --scope user shop-example-com https://shop.example.com/wp-json/mcp/mcp-adapter-default-server --header "Authorization: Basic ' . $result['code'] . '"' ), 'Claude gets one command, saved for every folder' );
$cursor = json_decode( $snippets['cursor'], true );
security_assert( 'https://shop.example.com/wp-json/mcp/mcp-adapter-default-server' === $cursor['mcpServers']['shop-example-com']['url'] && 'Basic ' . $result['code'] === $cursor['mcpServers']['shop-example-com']['headers']['Authorization'], 'Cursor gets a config with the address and the login' );
security_assert( false !== strpos( $snippets['codex'], '[mcp_servers.shop-example-com]' ) && false !== strpos( $snippets['codex'], 'http_headers = { Authorization = "Basic ' . $result['code'] . '" }' ), 'Codex gets a config that signs in with Basic, not Bearer' );
security_assert( false !== strpos( $snippets['prompt'], $result['endpoint'] ) && false !== strpos( $snippets['prompt'], 'Basic ' . $result['code'] ) && false !== strpos( $snippets['prompt'], 'list my registration forms' ), 'The ready prompt carries the address and the login and ends with a first question' );

// Application passwords turned off: a clear answer and nothing is made.
$before                  = count( $users );
$GLOBALS['flags']['apps'] = false;
$off                     = UR_Abilities_Connect::create_connection( 'Claude' );
security_assert( is_wp_error( $off ) && 'ur_ai_app_passwords_off' === $off->get_error_code() && count( $users ) === $before, 'With application passwords off nothing is made, and the answer says why' );
$GLOBALS['flags']['apps'] = true;

// A long app name is cut, and a blank one gets a default.
UR_Abilities_Connect::create_connection( str_repeat( 'a', 300 ) );
UR_Abilities_Connect::create_connection( '   ' );
$names = array_column( WP_Application_Passwords::$store[100], 'name' );
security_assert( 100 === strlen( $names[2] ) && 'AI assistant' === $names[3], 'The app name is cut to 100 characters, and a blank one becomes AI assistant' );

// Removing every password cuts every app off at once, and keeps the user.
$removed = UR_Abilities_Connect::revoke_connections();
security_assert( 4 === $removed && array() === WP_Application_Passwords::get_user_application_passwords( 100 ) && 1 === count( $users ), 'Remove all deletes every AI password and keeps the user' );

// The check says what is ready and what is not.
$checks = by_id( UR_Abilities_Connect::checks() );
security_assert( array( 'wordpress', 'adapter', 'secure', 'passwords', 'enabled', 'visible', 'user' ) === array_keys( $checks ), 'The check covers WordPress, the adapter, the address, passwords, the switch, the abilities and the user' );
security_assert( ! $checks['user']['ok'] && '' !== $checks['user']['fix'], 'Without a password the user check fails with a fix' );
UR_Abilities_Connect::create_connection( 'Claude' );
$checks = by_id( UR_Abilities_Connect::checks() );
security_assert( $checks['user']['ok'] && $checks['adapter']['ok'] && $checks['secure']['ok'] && $checks['enabled']['ok'] && $checks['visible']['ok'], 'With everything in place every check passes' );

$GLOBALS['flags']['plugin'] = false;
$GLOBALS['flags']['ssl']    = false;
$GLOBALS['flags']['enabled'] = false;
$GLOBALS['flags']['abilities'] = array();
$bad                        = by_id( UR_Abilities_Connect::checks() );
security_assert( ! $bad['adapter']['ok'] && ! $bad['secure']['ok'] && ! $bad['enabled']['ok'] && ! $bad['visible']['ok'], 'A missing adapter, an insecure address, a switch that is off and no visible ability each fail' );
$GLOBALS['flags']['plugin'] = true;
$GLOBALS['routes']          = array();
$silent                     = by_id( UR_Abilities_Connect::checks() );
security_assert( ! $silent['adapter']['ok'], 'An adapter that is active but does not answer on its route fails too' );

// The last call.
security_assert( null === UR_Abilities_Connect::last_call(), 'Before any call there is no last call' );
$GLOBALS['options'][ UR_Abilities_Connect::LAST_CALL_OPTION ] = array(
	'time' => time() - 120,
	'app'  => 'Claude',
);
$last = UR_Abilities_Connect::last_call();
security_assert( 'Claude' === $last['app'], 'The last call names the AI app' );

// The card is added under the master switch, with no setting of its own to save.
$settings = UR_Abilities_Connect::add_settings(
	array(
		'sections' => array(
			'ai_integration'       => array( 'title' => 'AI Integration' ),
			'ai_abilities_access' => array( 'title' => 'Access' ),
		),
	)
);
security_assert( array( 'ai_integration', 'ai_connect', 'ai_abilities_access' ) === array_keys( $settings['sections'] ), 'The Connect card sits between the master switch and the access card' );
security_assert( 'ur_ai_connect' === $settings['sections']['ai_connect']['settings'][0]['type'] && ! isset( $settings['sections']['ai_connect']['settings'][0]['id'] ), 'It has no id, so saving the page never touches it' );
security_assert( 'odd' === UR_Abilities_Connect::add_settings( 'odd' ) && array( 'sections' => array() ) === UR_Abilities_Connect::add_settings( array( 'sections' => array() ) ), 'Settings of an unexpected shape are returned untouched' );

// The starter prompts only look.
$prompts = UR_Abilities_Connect::prompts();
security_assert( count( $prompts ) >= 4 && array() === array_filter( $prompts, function ( $p ) { return preg_match( '/\b(create|delete|approve|deny|turn on|turn off|change|remove)\b/i', $p ); } ), 'The starter prompts only ask to see things, never to change them' );

// Only a user who manages the site, with a valid nonce, can make or remove a connection.
function ajax_outcome( $method, $caps, $nonce ) {
	$GLOBALS['caps']       = $caps;
	$_POST                 = array( 'security' => $nonce, 'app' => 'Claude' );
	$before_passwords      = count( WP_Application_Passwords::get_user_application_passwords( 100 ) );
	$response              = security_response( array( 'UR_Abilities_Connect', $method ) );
	$GLOBALS['passwords_changed'] = count( WP_Application_Passwords::get_user_application_passwords( 100 ) ) !== $before_passwords;
	return $response;
}
$GLOBALS['flags']['routes'] = true;
$GLOBALS['routes']          = array( '/mcp/mcp-adapter-default-server' => array() );
$denied                     = ajax_outcome( 'ajax_create', array(), 'valid' );
security_assert( false === $GLOBALS['passwords_changed'] && 403 === $denied->status, 'A user without the rights to manage the site cannot make a connection' );
$no_users = ajax_outcome( 'ajax_create', array( 'manage_options' ), 'valid' );
security_assert( false === $GLOBALS['passwords_changed'] && 403 === $no_users->status, 'Managing the site is not enough: making a user needs the right to create users too' );
$bad_nonce = ajax_outcome( 'ajax_create', array( 'manage_options', 'create_users' ), 'forged' );
security_assert( false === $GLOBALS['passwords_changed'] && 'nonce' === $bad_nonce->data, 'A request with a wrong nonce is refused' );
$revoke_denied = ajax_outcome( 'ajax_revoke', array(), 'valid' );
security_assert( false === $GLOBALS['passwords_changed'] && 403 === $revoke_denied->status, 'Removing the passwords needs the same rights' );
