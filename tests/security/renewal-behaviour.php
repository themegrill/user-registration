<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a subscription's reminder emails follow the renewal behaviour it was created under,
 * not the current setting.
 *
 * Runs the real `urm_get_renewal_behaviour()`, `urm_save_renewal_behaviour()` and the behaviour gate of the two
 * reminder emails; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

require __DIR__ . '/bootstrap.php';

$GLOBALS['umeta']   = array();
$GLOBALS['options'] = array();

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['umeta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['umeta'][ $user_id ][ $key ] = $value;
	return true; }

eval( security_function( 'modules/functions-ur-modules.php', 'urm_get_renewal_behaviour' ) );
eval( security_function( 'modules/functions-ur-modules.php', 'urm_save_renewal_behaviour' ) );

$email_service = 'modules/membership/includes/Admin/Services/EmailService.php';
eval(
	'class ReminderGateRunner { public '
	. security_function( $email_service, 'send_membership_renewal_email' )
	. ' public '
	. security_function( $email_service, 'send_membership_expiring_soon_email' )
	. ' }'
);

function set_site_behaviour( $behaviour ) {
	$GLOBALS['options']['user_registration_renewal_behaviour'] = $behaviour; }

// Without a saved value a subscription follows the current setting, as before.
set_site_behaviour( 'manual' );
security_assert( 'manual' === urm_get_renewal_behaviour( 7, 21 ), 'A subscription without a saved behaviour follows the current setting' );
$GLOBALS['options'] = array();
security_assert( 'automatic' === urm_get_renewal_behaviour( 7, 21 ), 'The setting defaults to automatic' );

// A saved value wins over the current setting.
update_user_meta( 7, 'urm_renewal_behaviour_21', 'manual' );
set_site_behaviour( 'automatic' );
security_assert( 'manual' === urm_get_renewal_behaviour( 7, 21 ), 'A subscription created under manual stays manual after the setting becomes automatic' );
update_user_meta( 7, 'urm_renewal_behaviour_22', 'automatic' );
set_site_behaviour( 'manual' );
security_assert( 'automatic' === urm_get_renewal_behaviour( 7, 22 ), 'A subscription created under automatic stays automatic after the setting becomes manual' );
update_user_meta( 7, 'urm_renewal_behaviour_23', 'junk' );
security_assert( 'manual' === urm_get_renewal_behaviour( 7, 23 ), 'A saved value that is not a behaviour falls back to the current setting' );
security_assert( 'manual' === urm_get_renewal_behaviour( 7, 0 ), 'Without a subscription the current setting is used' );

// Saving records the setting of that moment.
set_site_behaviour( 'manual' );
urm_save_renewal_behaviour( 9, 30 );
set_site_behaviour( 'automatic' );
urm_save_renewal_behaviour( 9, 31 );
security_assert( 'manual' === $GLOBALS['umeta'][9]['urm_renewal_behaviour_30'] && 'automatic' === $GLOBALS['umeta'][9]['urm_renewal_behaviour_31'], 'A subscription saves the behaviour of the moment it is created or renewed' );
urm_save_renewal_behaviour( 0, 32 );
urm_save_renewal_behaviour( 9, 0 );
security_assert( ! isset( $GLOBALS['umeta'][0] ) && ! isset( $GLOBALS['umeta'][9]['urm_renewal_behaviour_0'] ), 'Nothing is saved without a member and a subscription' );

// The reminder emails follow the subscription, not the site setting.
$runner = new ReminderGateRunner();
set_site_behaviour( 'manual' );
security_assert( false === $runner->send_membership_expiring_soon_email( array( 'member_id' => 7, 'subscription_id' => 22 ) ), 'A subscription under automatic renewal gets no expiring soon email even on a manual site' );
set_site_behaviour( 'automatic' );
security_assert( false === $runner->send_membership_renewal_email( array( 'member_id' => 7, 'subscription_id' => 21 ) ), 'A subscription under manual renewal gets no renewal reminder even on an automatic site' );

// The crons no longer stop on the site setting, or a subscription of the other behaviour would get no reminder.
$crons = (string) file_get_contents( dirname( __DIR__, 2 ) . '/modules/membership/includes/Crons.php' );
security_assert( false === strpos( $crons, 'user_registration_renewal_behaviour' ), 'The reminder crons are not gated on the site renewal setting' );
