<?php
/**
 * Cached registration remains independent of a page nonce.
 *
 * @package UserRegistration/Tests
 */

// Execute only the targeted handler; no live site or database is loaded.
require __DIR__ . '/bootstrap.php';
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Load the actual handler body using the existing isolated fixture.
eval( security_function( 'includes/functions-ur-core.php', 'ur_process_registration' ) );
/**
 * Stop the isolated registration request at the original logger boundary.
 *
 * @throws RuntimeException Always, to stop before side effects.
 */
function ur_get_logger() {
	throw new RuntimeException( 'registration-reached-handler' );
}
foreach ( array( '', 'invalid', 'valid' ) as $nonce ) {
	try {
		ur_process_registration( $nonce );
	} catch ( RuntimeException $e ) {
		security_assert( 'registration-reached-handler' === $e->getMessage(), 'Missing or expired cached-page nonce does not block registration' );
	}
}
