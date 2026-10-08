<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';
// WordPress provides this compatibility function when the plugin runs on PHP 7.4.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ) {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}
function add_action() {} function add_filter() {}
function apply_filters( $hook, $value ) {
	return $value; }
function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}
class UR_Settings_Page {
	public $label;
}
$core = 'includes/functions-ur-core.php';
foreach ( array( 'ur_clean', 'ur_string_to_bool', 'ur_option_checked', 'ur_sanitize_value_by_type', 'ur_save_settings_options' ) as $function ) {
	eval( security_function( $core, $function ) );
}
require dirname( __DIR__, 2 ) . '/includes/admin/settings/class-ur-settings-captcha.php';
$captcha           = new UR_Settings_Captcha();
$GLOBALS['writes'] = array();
$captcha->save_captcha_settings(
	array(
		'default_role'                            => 'administrator',
		'users_can_register'                      => '1',
		'urm_enable_no_conflict'                  => '1',
		'user_registration_captcha_setting_recaptcha_site_key' => 'wrong-section',
		'user_registration_captcha_save_settings' => 'button',
	),
	'captcha-settings'
);
security_assert( array( 'urm_enable_no_conflict' => true ) === $GLOBALS['writes'], 'Global CAPTCHA saves reject unrelated keys, cross-section keys, and buttons' );
$GLOBALS['options'] = $GLOBALS['writes'];
security_assert( true === get_option( 'urm_enable_no_conflict', false ), 'Conflict manager can read enabled boolean toggle' );
$captcha->save_captcha_settings( array( 'urm_enable_no_conflict' => '' ), 'captcha-settings' );
security_assert( false === $GLOBALS['writes']['urm_enable_no_conflict'], 'Blank global toggle saves false' );
$GLOBALS['options'] = $GLOBALS['writes'];
security_assert( ! get_option( 'urm_enable_no_conflict', false ), 'Conflict manager can read disabled boolean toggle' );

foreach ( $captcha->get_captcha_global_settings() as $section ) {
	if ( 'captcha-settings' === $section['id'] ) {
		continue;
	}
	$form_data = array(
		'default_role'           => 'administrator',
		'urm_enable_no_conflict' => '1',
	);
	$expected  = array();
	foreach ( $section['settings'] as $field ) {
		if ( 'button' === $field['type'] ) {
			continue;
		}
		$value                     = 'toggle' === $field['type'] ? '1' : ( isset( $field['options'] ) ? key( $field['options'] ) : 'test-key' );
		$form_data[ $field['id'] ] = $value;
		$expected[ $field['id'] ]  = 'toggle' === $field['type'] ? true : $value;
	}
	$expected['user_registration_captcha_setting_recaptcha_version']                    = $section['id'];
	$expected[ 'user_registration_captcha_setting_recaptcha_enable_' . $section['id'] ] = true;
	$GLOBALS['writes'] = array();
	$captcha->save_captcha_settings( $form_data, $section['id'] );
	security_assert( $expected === $GLOBALS['writes'], 'Save every real field for provider ' . $section['id'] . ' without unrelated or cross-section writes' );
}
foreach ( array(
	'1' => true,
	''  => false,
) as $value => $expected ) {
	$captcha->save_captcha_settings( array( 'user_registration_captcha_setting_invisible_recaptcha_v2' => (string) $value ), 'v2' );
	$GLOBALS['options'] = $GLOBALS['writes'];
	security_assert( ur_option_checked( 'user_registration_captcha_setting_invisible_recaptcha_v2', false ) === $expected, 'Invisible CAPTCHA readers accept boolean toggles' );
}
$GLOBALS['writes'] = array();
$captcha->save_captcha_settings( array( 'default_role' => 'administrator' ), 'unknown-section' );
security_assert( empty( $GLOBALS['writes'] ), 'Unknown sections cannot write options or create enable flags' );
foreach ( array( null, 'broken', 1 ) as $data ) {
	$captcha->save_captcha_settings( $data, 'v2' );
	security_assert( empty( $GLOBALS['writes'] ), 'Malformed section data cannot write options' );
}
$captcha->save_captcha_settings( array( 'urm_enable_no_conflict' => array( '1' ) ), 'captcha-settings' );
security_assert( empty( $GLOBALS['writes'] ), 'Reject non-scalar CAPTCHA values' );

/** Exercise the exact AJAX login handler with its real field definitions. */
function do_action() {}
function get_permalink( $id ) {
	return 'https://example.test/'; }
function admin_url( $path = '' ) {
	return 'https://example.test/' . $path; }
function ur_get_captcha_integrations() {
	return array(); }
function ur_login_option_with() {
	return array( 'username' => 'Username' ); }
function wp_kses_post( $value ) {
	return strip_tags( $value, '<strong>' ); }
function ur_find_my_account_in_page( $id ) {
	return 42 === $id; }
function ur_find_lost_password_in_page( $id ) {
	return '43' === (string) $id; }
foreach ( array( 'get_login_form_settings', 'get_login_field_settings' ) as $function ) {
	eval( security_function( $core, $function ) );
}
eval( 'class SecurityLoginAjax { public static ' . security_function( 'includes/class-ur-ajax.php', 'login_settings_save_action' ) . ' }' );
$save_login = function ( $items, $nonce = 'valid', $caps = array( 'manage_options' ) ) {
	$GLOBALS['writes'] = array();
	$GLOBALS['caps']   = $caps;
	$_POST             = array(
		'security' => $nonce,
		'data'     => array( 'setting_data' => $items ),
	);
	return security_response( array( 'SecurityLoginAjax', 'login_settings_save_action' ) );
};
$items      = array();
foreach ( array(
	'default_role'                                         => 'administrator',
	'users_can_register'                                   => '1',
	'active_plugins'                                       => 'evil',
	'user_registration_captcha_setting_recaptcha_site_key' => 'cross-section',
	'user_registration_label_login'                        => '<script>bad</script>Sign in',
	'user_registration_login_options_remember_me'          => '1',
) as $key => $value ) {
	$items[] = array(
		'option' => $key,
		'value'  => $value,
	);
}
$response = $save_login( $items );
security_assert( $response->success, 'Valid login settings save succeeds' );
security_assert(
	array(
		'user_registration_login_options_remember_me' => true,
		'user_registration_label_login'               => 'badSign in',
	) === $GLOBALS['writes'],
	'Only declared login fields are saved and sanitized'
);
$response = $save_login( $items, 'expired' );
security_assert( ! $response->success && empty( $GLOBALS['writes'] ), 'Invalid nonce cannot save login settings' );
$response = $save_login( $items, 'valid', array( 'manage_user_registration' ) );
security_assert( ! $response->success && empty( $GLOBALS['writes'] ), 'Login saves require manage_options' );
foreach ( array(
	null,
	'broken',
	array(
		null,
		array(
			'option' => array( 'default_role' ),
			'value'  => 'administrator',
		),
		array(
			'option' => 'user_registration_label_login',
			'value'  => array( 'nested' ),
		),
	),
) as $items ) {
	$save_login( $items );
	security_assert( empty( $GLOBALS['writes'] ), 'Malformed login submissions do not write options' );
}
$save_login(
	array(
		array(
			'option' => 'user_registration_login_options_login_redirect_url',
			'value'  => '42',
		),
	)
);
security_assert( '42' === $GLOBALS['writes']['user_registration_login_page_id'] && '42' === $GLOBALS['writes']['user_registration_login_options_login_redirect_url'], 'Declared login redirect preserves page synchronization' );
$save_login(
	array(
		array(
			'option' => 'user_registration_login_options_remember_me',
			'value'  => '',
		),
	)
);
security_assert( false === $GLOBALS['writes']['user_registration_login_options_remember_me'], 'Login toggle can be disabled' );
$response = $save_login(
	array(
		array(
			'option' => 'user_registration_login_options_enable_recaptcha',
			'value'  => '1',
		),
	)
);
security_assert( ! $response->success && empty( $GLOBALS['writes'] ), 'Login CAPTCHA validation still rejects missing provider' );
$response = $save_login(
	array(
		array(
			'option' => 'user_registration_login_options_prevent_core_login',
			'value'  => '1',
		),
		array(
			'option' => 'user_registration_login_options_login_redirect_url',
			'value'  => '41',
		),
	)
);
security_assert( ! $response->success && empty( $GLOBALS['writes'] ), 'Core login prevention still validates the destination page' );
