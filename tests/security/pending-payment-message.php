<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Pending payment login message must treat the admin-edited text as data, not as a sprintf() format.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName

require __DIR__ . '/bootstrap.php';

eval( 'class SecurityPendingPayment {' . security_function( 'includes/class-ur-user-approval.php', 'format_pending_payment_message' ) . '}' );

$url     = 'https://example.test/pay?a=1&b=2';
$cases   = array(
	'default link'           => array( 'Pay: <a href="%s">link</a>', 'Pay: <a href="' . $url . '">link</a>' ),
	'literal percent'        => array( 'Pay 100% now: <a href="%s">link</a>', 'Pay 100% now: <a href="' . $url . '">link</a>' ),
	'second placeholder'     => array( 'A %s B %s', 'A ' . $url . ' B ' . $url ),
	'escaped percent'        => array( 'Pay 100%% now: %s', 'Pay 100% now: ' . $url ),
	'positional placeholder' => array( 'Go: %1$s', 'Go: ' . $url ),
	'no placeholder'         => array( 'Pay at the desk', 'Pay at the desk' ),
);
$handler = new SecurityPendingPayment();
foreach ( $cases as $name => $case ) {
	security_assert( $handler->format_pending_payment_message( $case[0], $url ) === $case[1], 'Pending payment message: ' . $name );
}
