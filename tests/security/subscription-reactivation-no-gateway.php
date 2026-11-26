<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a subscription with no payment method (admin-assigned) still reactivates locally,
 * while a gateway nothing handles is refused.
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

require __DIR__ . '/bootstrap.php';

function ur_get_logger() {
	return new class() {
		public function notice() {} };
}
function apply_filters( $tag, $value ) {
	return $value; }

eval(
	'class ReactivationRunner { public '
	. security_function( 'modules/membership/includes/Admin/Services/SubscriptionService.php', 'reactivate_subscription' )
	. ' }'
);

$runner = new ReactivationRunner();

security_assert( true === $runner->reactivate_subscription( array( 'payment_method' => '' ), array() )['status'], 'An empty payment method reactivates locally' );
security_assert( true === $runner->reactivate_subscription( array(), array() )['status'], 'A missing payment method reactivates locally without a PHP notice' );
security_assert( true === $runner->reactivate_subscription( array( 'payment_method' => 'bank' ), array() )['status'], 'Bank transfer still reactivates locally' );
security_assert( false === $runner->reactivate_subscription( array( 'payment_method' => 'unknown' ), array() )['status'], 'A gateway nothing handles is refused' );
