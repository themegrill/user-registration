<?php
/**
 * Registration nonce regression.
 *
 * @package UserRegistration/Tests
 */

// Execute only the targeted handler; no live site or database is loaded.
require __DIR__ . '/bootstrap.php';
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual handler body using the existing isolated fixture.
eval( security_function( 'includes/functions-ur-core.php', 'ur_process_registration' ) );
foreach ( array( '', 'invalid' ) as $nonce ) {
	$response = security_response(
		function () use ( $nonce ) {
			ur_process_registration( $nonce );
		}
	);
	security_assert( false === $response->success && 403 === $response->status, 'Reject absent/invalid nonce before processing or writes' );
}
/**
 * Stop the isolated valid-nonce request at the original logger boundary.
 *
 * @throws RuntimeException Always, to stop before side effects.
 */
function ur_get_logger() {
	throw new RuntimeException( 'valid-nonce-reached-handler' );
}
try {
	ur_process_registration( 'valid' );
} catch ( RuntimeException $e ) {
	security_assert( 'valid-nonce-reached-handler' === $e->getMessage(), 'Valid nonce reaches existing registration handler' );
}
