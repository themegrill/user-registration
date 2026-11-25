<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing

require __DIR__ . '/bootstrap.php';
eval( security_function( 'includes/functions-ur-core.php', 'ur_escape_shortcodes' ) );

$payload = '[caption width="1" caption="PWNED"]x[/caption]';
$escaped = ur_escape_shortcodes( $payload );
security_assert( false === strpos( $escaped, '[' ) && false === strpos( $escaped, ']' ), 'Square brackets are neutralised so no later do_shortcode() pass can run the payload' );
security_assert( false !== strpos( $escaped, 'PWNED' ), 'Message text is kept readable' );

$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/shortcodes/class-ur-shortcode-my-account.php' );
security_assert( 1 === preg_match( "/ur_escape_shortcodes\(\s*sanitize_text_field\(\s*rawurldecode\(\s*wp_unslash\(\s*\\\$_GET\['message'\]/", $source ), 'Lost-password error message from the query string is shortcode-escaped before it is queued as a notice' );
