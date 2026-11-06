<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: sites that already had AI abilities keep them after the update, on free and on Pro, because the
 * decision comes from a one-time flag and not from comparing versions. New installs stay off, and a site that
 * has already been decided is never switched on again.
 *
 * Runs the real `UR_Install::maybe_keep_abilities_for_existing_site()`; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';

function add_option( $key, $value ) {
	if ( ! array_key_exists( $key, $GLOBALS['options'] ) ) {
		$GLOBALS['options'][ $key ] = $value;
	}
}

eval(
	'class AbilitiesInstallRunner {
		private static function is_new_install() { return $GLOBALS["new_install"]; }
		public static ' . security_function( 'includes/class-ur-install.php', 'maybe_keep_abilities_for_existing_site' ) . '
	}'
);

function abilities_setting_after( $is_new_install, $options = array() ) {
	$GLOBALS['new_install'] = $is_new_install;
	$GLOBALS['options']     = $options;
	AbilitiesInstallRunner::maybe_keep_abilities_for_existing_site();
	return $GLOBALS['options']['user_registration_enable_abilities'] ?? null;
}

const FLAG = 'urm_is_legacy_abilities_user';
const SET  = 'user_registration_enable_abilities';

security_assert( 'yes' === abilities_setting_after( false ), 'An existing site keeps its abilities after the update' );
security_assert( 1 === $GLOBALS['options'][ FLAG ], 'An existing site is recorded as having had abilities' );
security_assert( null === abilities_setting_after( true ), 'A new install stays off' );
security_assert( 0 === $GLOBALS['options'][ FLAG ], 'A new install is recorded so a later update cannot switch it on' );
security_assert( 'no' === abilities_setting_after( false, array( SET => 'no' ) ), 'An admin who saved it off stays off' );
security_assert( null === abilities_setting_after( false, array( FLAG => 0 ) ), 'A site that started as a new install is not switched on by a later update' );
security_assert( 'no' === abilities_setting_after( false, array( FLAG => 1, SET => 'no' ) ), 'An admin who turns it off after the update stays off through later updates' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
