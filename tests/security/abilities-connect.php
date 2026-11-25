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
// The WordPress 6.9 check looks for the Abilities API.
function wp_register_ability() {}
function human_time_diff( $from, $to ) {
	return ( $to - $from ) . ' seconds';
}
function _n( $single, $plural, $number ) {
	return 1 === $number ? $single : $plural;
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
$ready = UR_Abilities_Connect::checks_summary( UR_Abilities_Connect::checks() );
security_assert( true === $ready['ok'] && 'Your site is ready' === $ready['text'], 'When every check passes the summary says the site is ready, so the list can stay folded away' );
$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-connect.php' );
$top    = strpos( $source, 'class="ur-ai-topbar"' );
$main   = strpos( $source, 'class="ur-ai-connect-main"' );
$active = strpos( $source, 'class="ur-ai-active"' );
$asking = strpos( $source, 'class="ur-ai-tryasking"' );
security_assert( false !== $top && $top < $main && $main < $active && $active < $asking, 'The Connect tab reads in this order: the top bar, the connection, the active connections, then the questions' );
$bar_end = strpos( $source, 'class="ur-ai-connect-main"' );
$bar     = substr( $source, $top, $bar_end - $top );
security_assert( false !== strpos( $bar, 'ur-ai-apps ur-ai-apps--center' ) && false !== strpos( $bar, 'id="ur-ai-ready-status"' ) && false !== strpos( $bar, 'id="ur-ai-ready-pop"' ) && false !== strpos( $bar, 'id="ur-ai-checks"' ) && false !== strpos( $bar, 'id="ur-ai-check"' ), 'The top bar holds the app switcher in the middle and, on the right, the status with its checks and Check again in a popover' );
security_assert( false !== strpos( $source, '.ur-ai-ready{position:absolute;top:50%;right:0' ) && false !== strpos( $source, '.ur-ai-ready__pop[hidden]{display:none}' ), 'The status sits at the top right and the popover stays closed until it is opened' );
security_assert( false === strpos( $source, 'ur-ai-hero' ) && false === strpos( $source, 'ur-ai-connect__step' ) && false === strpos( $source, 'ur-ai-ready-toggle' ), 'There is no panel inside the card, no step numbers and no Show checks link' );
security_assert( false === strpos( $source, 'ur-ai-asks__tab' ) && false === strpos( $source, 'ur-ai-ask__count' ) && false === strpos( $source, 'ur-ai-tryasking__title' ) && false !== strpos( $source, 'id="ur-ai-ask-text"' ) && false !== strpos( $source, 'id="ur-ai-ask-next"' ), 'Try asking is one quiet line with Copy and Another question: no Read, Write and Delete tabs, no counter and no heading of its own' );
security_assert( false === strpos( $source, 'Another question' ) && false !== strpos( $source, 'class="ur-ai-iconbtn" id="ur-ai-ask-next"' ) && false !== strpos( $source, 'Show a different question' ) && false !== strpos( $source, 'aria-label=' ), 'The next question is a small shuffle button with a label for screen readers, not a text link' );
$ask_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-connect.js' );
security_assert( false === strpos( $source, 'ur-ai-ask-hint' ) && false === strpos( $source, 'ur-ai-try__hint' ) && false === strpos( $ask_js, 'ask.hint' ) && false === strpos( $source, 'Switch on the matching ability' ), 'There is no hint line under the question' );
security_assert( false === strpos( $source, 'text-decoration:underline' ) && false !== strpos( $source, 'color:#475bb2;text-decoration:none;cursor:pointer' ), 'The links in this tab, such as Prefer manual configuration and Remove all AI passwords, have no underline' );
security_assert( false !== strpos( $source, '<span class="ur-ai-try__actions">' ) && false !== strpos( $source, '.ur-ai-try__actions{flex:none;display:flex;' ) && false !== strpos( $source, 'margin-left:auto' ) && false !== strpos( $source, '.ur-ai-try__text{flex:1 1 auto;min-width:0' ), 'The shuffle and Copy buttons are one group fixed at the right of the row, and only the question text takes the remaining width, so they never move with its length' );
security_assert( false !== strpos( $source, 'class="ur-ai-conn__user" href="' ) && false !== strpos( $source, 'esc_url( get_edit_user_link( $user->ID ) )' ) && false !== strpos( $source, 'esc_html( $user->user_login )' ), 'The AI user name in the Active connections card links to that user profile page, built with esc_url and esc_html' );
security_assert( false !== strpos( $source, "sprintf( esc_html__( 'These apps reach your site as the AI user %s.'" ) && false !== strpos( $source, "sprintf( esc_html__( 'No AI app is connected yet. The AI user %s is ready.'" ), 'Both sentences keep the text escaped and put the link in as the one unescaped piece' );
security_assert( false !== strpos( $source, 'class="ur-ai-iconbtn" id="ur-ai-copy-pass"' ) && false !== strpos( $source, 'class="ur-ai-iconbtn" id="ur-ai-ask-copy"' ) && false !== strpos( $ask_js, 'ur-ai-iconbtn ur-ai-copy-prompt' ) && false !== strpos( $ask_js, 'cfg.copyIcon' ), 'All three copy buttons are icons: the one in each prompt box, the one for the password and the one in Try asking' );
security_assert( 1 === preg_match( '/function copy_icon\(\)/', $source ) && 2 === substr_count( substr( $source, strpos( $source, 'function copy_icon()' ), 1200 ), '<svg' ) && false !== strpos( $source, 'ur-ai-iconbtn__done' ) && false !== strpos( $source, 'aria-hidden="true"' ), 'The icon is a copy symbol plus a check mark, both decorative, drawn from one helper' );
security_assert( false !== strpos( $ask_js, 'hasClass("ur-ai-iconbtn")' ) && false !== strpos( $ask_js, 'is-copied' ) && false !== strpos( $ask_js, '"aria-label": i18n.copied' ), 'After copying, an icon button shows a check mark for a moment, and its label says Copied for screen readers' );
security_assert( false !== strpos( $ask_js, 'Math.random()' ) && false !== strpos( $ask_js, 'next === askIndex' ), 'The shuffle picks at random and never shows the same question twice in a row' );
security_assert( false !== strpos( $ask_js, 'Object.keys(cfg.asks' ) && false === strpos( $ask_js, 'askLevel' ) && false === strpos( $ask_js, 'ur-ai-asks__tab' ), 'The questions are one list built from all three kinds, with no tab to pick a kind' );
security_assert( false !== strpos( $source, 'Active connections' ) && false === strpos( $source, 'Connected apps:' ), 'The list of connected apps is called Active connections' );

$GLOBALS['flags']['plugin'] = false;
$GLOBALS['flags']['ssl']    = false;
$GLOBALS['flags']['enabled'] = false;
$GLOBALS['flags']['abilities'] = array();
$bad                        = by_id( UR_Abilities_Connect::checks() );
security_assert( ! $bad['adapter']['ok'] && ! $bad['secure']['ok'] && ! $bad['enabled']['ok'] && ! $bad['visible']['ok'], 'A missing adapter, an insecure address, a switch that is off and no visible ability each fail' );
$problems = UR_Abilities_Connect::checks_summary( UR_Abilities_Connect::checks() );
security_assert( false === $problems['ok'] && '4 things need attention' === $problems['text'], 'When checks fail the summary counts them, so the list opens by itself' );
$one = UR_Abilities_Connect::checks_summary( array( array( 'ok' => true ), array( 'ok' => false ) ) );
security_assert( false === $one['ok'] && '1 thing needs attention' === $one['text'], 'One failure reads as one thing, not one things' );
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

// The Connect part goes inside the AI Integration card, under the master switch, with no setting of its own to save.
$settings = UR_Abilities_Connect::add_settings(
	array(
		'sections' => array(
			'ai_integration'      => array(
				'title'    => 'AI Integration',
				'settings' => array( array( 'id' => 'user_registration_enable_abilities' ) ),
			),
			'ai_abilities_access' => array( 'title' => 'Access' ),
		),
	)
);
security_assert( array( 'ai_integration', 'ai_abilities_access' ) === array_keys( $settings['sections'] ), 'No card of its own is added; the Connect part joins the AI Integration card' );
$inside = $settings['sections']['ai_integration']['settings'];
security_assert( 2 === count( $inside ) && 'user_registration_enable_abilities' === $inside[0]['id'] && 'ur_ai_connect' === $inside[1]['type'] && ! isset( $inside[1]['id'] ), 'It sits under the master switch and has no id, so saving the page never touches it' );
$condition = $inside[1]['display_condition'] ?? array();
security_assert( 'user_registration_enable_abilities' === ( $condition['field'] ?? '' ) && 'yes' === ( $condition['value'] ?? '' ) && 'equals' === ( $condition['operator'] ?? '' ), 'The Connect part shows only while Enable AI Abilities is on' );
security_assert( 'odd' === UR_Abilities_Connect::add_settings( 'odd' ) && array( 'sections' => array() ) === UR_Abilities_Connect::add_settings( array( 'sections' => array() ) ), 'Settings of an unexpected shape are returned untouched' );

// Claude Desktop reaches a remote server through mcp-remote, and the login stays out of the command line.
$desktop = json_decode( $snippets['desktop'], true );
$entry   = $desktop['mcpServers']['shop-example-com'] ?? array();
security_assert( 'npx' === ( $entry['command'] ?? '' ) && in_array( $result['endpoint'], $entry['args'] ?? array(), true ) && 'Basic ' . $result['code'] === ( $entry['env']['UR_AI_AUTH'] ?? '' ), 'Claude Desktop gets an mcp-remote entry with the login in its environment' );

// The drawer offers four apps. Claude is one tab with one password and two things to paste.
$apps = UR_Abilities_Connect::apps();
security_assert( array( 'claude', 'codex', 'cursor', 'other' ) === array_keys( $apps ), 'The drawer offers Claude, Codex, Cursor and Other, in that order' );
security_assert( 'Claude' === $apps['claude']['label'] && array( 'claude', 'desktop' ) === array_column( $apps['claude']['snippets'], 'key' ) && array( 'Claude Code', 'Claude Desktop' ) === array_column( $apps['claude']['snippets'], 'label' ), 'Claude holds both the Claude Code command and the Claude Desktop config' );
$missing = array();
foreach ( $apps as $app ) {
	foreach ( $app['snippets'] as $item ) {
		if ( ! isset( $snippets[ $item['key'] ] ) ) {
			$missing[] = $item['key'];
		}
	}
}
security_assert( array() === $missing, 'Every thing to paste points at a snippet that exists' );

// The questions to try come in three kinds, and the read ones only look.
$prompts = UR_Abilities_Connect::prompts();
security_assert( array( 'read', 'write', 'delete' ) === array_keys( $prompts ), 'There are questions for reading, writing and deleting, in that order' );
$read = $prompts['read']['questions'];
security_assert( count( $read ) >= 4 && array() === array_filter( $read, function ( $p ) { return preg_match( '/\b(create|delete|approve|deny|turn on|turn off|change|remove|extend|resend|cancel|assign)\b/i', $p ); } ), 'The read questions only ask to see things, never to change them' );
security_assert( count( $prompts['write']['questions'] ) >= 3 && array() === array_filter( $prompts['write']['questions'], function ( $p ) { return preg_match( '/\b(delete|remove)\b/i', $p ); } ), 'The write questions change things without deleting them' );
security_assert( count( $prompts['delete']['questions'] ) >= 2 && array() === array_filter( $prompts['delete']['questions'], function ( $p ) { return ! preg_match( '/\bdelete\b/i', $p ); } ), 'Every delete question asks to delete' );
security_assert( array() === array_filter( $prompts, function ( $group ) { return '' === $group['label'] || isset( $group['hint'] ); } ), 'Each kind has a label and no hint text of its own' );

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
