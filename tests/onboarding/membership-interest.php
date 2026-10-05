<?php
/**
 * Regression check for the setup wizard's membership answer: `php tests/onboarding/membership-interest.php`.
 *
 * Reuses the isolated harness in tests/security; no WordPress site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, Squiz.Commenting.FunctionComment.Missing, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler

require dirname( __DIR__ ) . '/security/bootstrap.php';

eval( security_function( 'includes/RestApi/controllers/version1/class-ur-getting-started.php', 'resolve_membership_interest' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Loads the exact handler body without booting WordPress.

$cases = array(
	array( 'paid_membership', 'yes', 'yes' ),
	array( 'free_membership', 'yes', 'yes' ),
	array( 'normal', 'no', 'no' ),
	array( 'normal', 'later', 'later' ),
	array( 'paid_membership', '', 'yes' ),
	array( 'normal', '', 'no' ),
	array( 'normal', 'yes', '' ),
	array( 'paid_membership', 'no', '' ),
	array( 'paid_membership', 'later', '' ),
	array( 'normal', 'maybe', '' ),
);

foreach ( $cases as $case ) {
	security_assert(
		resolve_membership_interest( $case[0], $case[1] ) === $case[2],
		sprintf( 'type=%s answer=%s should resolve to "%s"', $case[0], $case[1], $case[2] )
	);
}
