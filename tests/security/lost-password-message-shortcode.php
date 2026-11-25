<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, WordPress.Security.NonceVerification.Recommended

require __DIR__ . '/bootstrap.php';

$GLOBALS['notices'] = array();
function nocache_headers() {}
function ur_string_to_bool( $value ) {
	return (bool) $value; }
function apply_filters( $hook, $value ) {
	return $value; }
function wp_enqueue_script() {}
function ur_get_recaptcha_node() {
	return ''; }
function ur_get_template() {}
function ur_add_notice( $message, $type = 'success' ) {
	$GLOBALS['notices'][] = array( $type, $message ); }
eval( security_function( 'includes/functions-ur-core.php', 'ur_escape_shortcodes' ) );
eval( 'class LostPasswordUnderTest { public static ' . security_function( 'includes/shortcodes/class-ur-shortcode-my-account.php', 'lost_password' ) . ' }' );

/** Run the real handler with a given `message` query value and return the queued error notices. */
function queued_error_messages( $message ) {
	$GLOBALS['notices'] = array();
	$_GET               = array(
		'ur-lp-error' => 'invalid',
		'message'     => $message,
	);
	LostPasswordUnderTest::lost_password();
	return array_column( $GLOBALS['notices'], 1 );
}

$payload = '[caption width="1" caption="PWNED"]x[/caption]';
foreach ( array( 'plain' => $payload, 'url-encoded' => rawurlencode( $payload ), 'double-encoded' => rawurlencode( rawurlencode( $payload ) ) ) as $label => $input ) {
	$messages = queued_error_messages( $input );
	security_assert( 1 === count( $messages ), "A single error notice is queued for the $label message" );
	security_assert( false === strpos( $messages[0], '[' ) && false === strpos( $messages[0], ']' ), "No square brackets reach the queued notice for the $label message, so a later do_shortcode() pass cannot run it" );
	security_assert( false !== strpos( $messages[0], 'PWNED' ), "The $label message text stays readable" );
}

$messages = queued_error_messages( 'Please try again.' );
security_assert( array( 'Please try again.' ) === $messages, 'A normal error message is queued unchanged' );
