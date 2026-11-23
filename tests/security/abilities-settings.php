<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: AI settings have their own section, Advanced, AI Integration, placed before Others. The master switch
 * lives there, no longer in Others, and the other AI cards join the section through a filter, so each part adds
 * its own card from its own code.
 *
 * Runs the real `UR_Abilities_Settings` class with WordPress test doubles; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

$filters         = array();
$current_section = '';

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
		$value = is_callable( $callback ) ? $callback( $value ) : $value;
	}
	return $value;
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-settings.php';

// The section sits before Others.
$sections = array(
	'import-export'   => 'Import/Export',
	'plugin-deletion' => 'Plugin Deletion',
	'others'          => 'Others',
);
$result   = UR_Abilities_Settings::add_section( $sections );
security_assert( array( 'import-export', 'plugin-deletion', 'ai-integration', 'others' ) === array_keys( $result ), 'AI Integration is added before Others and the order of the rest is kept' );
security_assert( 'AI Integration' === $result['ai-integration'] && 'Others' === $result['others'], 'The sections keep their labels' );
security_assert( array( 'a', 'ai-integration' ) === array_keys( UR_Abilities_Settings::add_section( array( 'a' => 'A' ) ) ), 'Without Others the section goes last' );
security_assert( 'odd' === UR_Abilities_Settings::add_section( 'odd' ), 'Sections of an unexpected shape are returned untouched' );

// Only the AI Integration section gets settings.
$base = array( 'untouched' => true );
foreach ( array( '', 'others', 'plugin-deletion', 'import-export' ) as $section ) {
	$GLOBALS['current_section'] = $section;
	security_assert( $base === UR_Abilities_Settings::get_settings( $base ), 'The ' . ( $section ? $section : 'default' ) . ' section is left alone' );
}

$GLOBALS['current_section'] = 'ai-integration';
$settings                   = UR_Abilities_Settings::get_settings( $base );
$card                       = $settings['sections']['ai_integration'];
$toggle                     = $card['settings'][0];
security_assert( 'card' === $card['type'] && 'AI Integration' === $card['title'], 'The section opens with an AI Integration card' );
security_assert( 'user_registration_enable_abilities' === $toggle['id'] && 'toggle' === $toggle['type'] && 'no' === $toggle['default'], 'The master switch is a toggle that keeps its option name and starts off' );

// Other parts add their cards through the filter.
add_filter(
	'user_registration_settings_advanced_ai_integration',
	function ( $settings ) {
		$settings['sections']['more'] = array( 'title' => 'More' );
		return $settings;
	}
);
$settings = UR_Abilities_Settings::get_settings( $base );
security_assert( array( 'ai_integration', 'more' ) === array_keys( $settings['sections'] ), 'A card added through the filter joins the section after the master switch' );

// The master switch is gone from Others, and the section is hooked in.
$others = security_function( 'includes/admin/settings/class-ur-settings-advanced.php', 'get_others_settings' );
security_assert( false === strpos( $others, 'user_registration_enable_abilities' ) && false !== strpos( $others, 'user_registration_allow_usage_tracking' ), 'The master switch no longer sits in Others, whose other setting stays' );

UR_Abilities_Settings::init();
security_assert( ! empty( $GLOBALS['filters']['user_registration_get_sections_advanced'] ) && ! empty( $GLOBALS['filters']['user_registration_get_settings_advanced'] ), 'The section is hooked into the Advanced sections and settings' );
