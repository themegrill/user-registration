<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a recovered payment clears the retry counter, and every failed retry attempt sends the
 * "retry failed" email, not only the first.
 *
 * Runs the real `SubscriptionService::update_subscription_data_for_renewal()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.DateTime.RestrictedFunctions.date_date

require __DIR__ . '/bootstrap.php';

$GLOBALS['umeta'] = array();

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['umeta'][ $user_id ][ $key ] = $value;
	return true; }
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['umeta'][ $user_id ][ $key ] );
	return true; }
function urm_get_membership_process( $user_id ) {
	return array(); }

class RepositoryDouble {
	public function update( $id, $data ) {
		return true; }
}

$service = 'modules/membership/includes/Admin/Services/SubscriptionService.php';
eval(
	'class RenewalRunner { public $members_subscription_repository; public '
	. security_function( $service, 'update_subscription_data_for_renewal' )
	. ' }'
);

$runner                                = new RenewalRunner();
$runner->members_subscription_repository = new RepositoryDouble();
$subscription                          = array(
	'ID'                => 1,
	'user_id'           => 7,
	'item_id'           => 3,
	'next_billing_date' => '2026-01-01 00:00:00',
);
$metas                                 = array(
	'subscription' => array(
		'value'    => 1,
		'duration' => 'month',
	),
);

// A recovered payment clears the counter so a later, unrelated failure starts from zero.
$GLOBALS['umeta'][7]['urm_is_payment_retrying'] = 2;
$runner->update_subscription_data_for_renewal( $subscription, $metas );
security_assert( ! isset( $GLOBALS['umeta'][7]['urm_is_payment_retrying'] ), 'A successful renewal clears the payment retry counter' );
security_assert( isset( $GLOBALS['umeta'][7]['urm_last_renewed_on'] ), 'The renewal still records when it happened' );

// Other members' counters are left alone.
$GLOBALS['umeta'][8]['urm_is_payment_retrying'] = 1;
$runner->update_subscription_data_for_renewal( $subscription, $metas );
security_assert( 1 === $GLOBALS['umeta'][8]['urm_is_payment_retrying'], 'Renewing one member does not clear another member\'s retry counter' );

// The retry-failed email must go out on every attempt, not only when the counter is exactly 1.
foreach ( array( 'Stripe/StripeService.php', 'Paypal/PaypalService.php' ) as $gateway ) {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/modules/membership/includes/Admin/Services/' . $gateway );
	security_assert( 1 === preg_match( '/1\s*<=\s*(?:\(int\)|intval)[^;]*urm_is_payment_retrying/', $source ), $gateway . ' sends the retry-failed email on every failed attempt' );
	security_assert( 0 === preg_match( '/1\s*===\s*(?:\(int\)|intval)[^;]*urm_is_payment_retrying/', $source ), $gateway . ' does not gate the retry-failed email on the counter being exactly 1' );
}
