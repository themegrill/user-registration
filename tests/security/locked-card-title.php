<?php
/**
 * Regression for #1846: the locked-section upsell card shows the add-on's full
 * name in every license state, not the short sidebar label.
 *
 * Run from the plugin root: php tests/security/locked-card-title.php
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';

define( 'WP_PLUGIN_DIR', sys_get_temp_dir() );
function esc_html__( $s ) {
	return $s; }
function apply_filters( $tag, $value ) {
	return $value; }
function add_filter() {}
function ur_utm_url( $url ) {
	return $url; }
function ur_string_to_bool( $v ) {
	return true === $v || 'true' === $v || '1' === $v || 1 === $v; }
function is_plugin_active() {
	return false; }
function ur_get_license_plan() {
	return $GLOBALS['license']; }

$source = 'includes/functions-ur-core.php';
eval( '?><?php ' . security_function( $source, 'ur_premium_settings_tab' ) . security_function( $source, 'ur_get_premium_settings_tab' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

/** Card title ur_get_premium_settings_tab() produces for a section under a license. */
function card_title( $tab, $section, $license ) {
	$GLOBALS['license']         = $license;
	$GLOBALS['current_tab']     = $tab;
	$GLOBALS['current_section'] = $section;
	$settings                   = array(
		'sections' => array( 'premium_setting_section' => array( 'title' => 'Sidebar Default' ) ),
	);
	$result                     = ur_get_premium_settings_tab( $settings );
	return $result['sections']['premium_setting_section']['title'] ?? null;
}

$cases = array(
	array( 'security', '2fa', 'Two Factor Authentication' ),
	array( 'email', 'templates', 'Email Templates' ),
	array( 'integration', 'pdf-submission', 'PDF Form Submission' ),
);

$licenses = array(
	'no license'                  => false,
	'licensed, plan includes it'  => (object) array( 'item_plan' => 'professional' ),
	'licensed, plan lacks add-on' => (object) array( 'item_plan' => 'unlisted-plan' ),
);

foreach ( $cases as list( $tab, $section, $expected ) ) {
	foreach ( $licenses as $state => $license ) {
		security_assert( card_title( $tab, $section, $license ) === $expected, "$tab/$section ($state) card title is not '$expected'" );
	}
}
