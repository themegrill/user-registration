<?php
/** Registration nonce regression. @package UserRegistration/Tests */
require __DIR__ . '/bootstrap.php';
eval( security_function( 'includes/functions-ur-core.php', 'ur_process_registration' ) );
foreach ( array( '', 'invalid' ) as $nonce ) {
	$response = security_response( function () use ( $nonce ) { ur_process_registration( $nonce ); } );
	security_assert( false === $response->success && 403 === $response->status, 'Reject absent/invalid nonce before processing or writes' );
}
function ur_get_logger() { throw new RuntimeException( 'valid-nonce-reached-handler' ); }
try {
	ur_process_registration( 'valid' );
} catch ( RuntimeException $e ) {
	security_assert( 'valid-nonce-reached-handler' === $e->getMessage(), 'Valid nonce reaches existing registration handler' );
}
