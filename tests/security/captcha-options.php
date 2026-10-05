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
