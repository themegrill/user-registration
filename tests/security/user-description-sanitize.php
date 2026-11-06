<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing

require __DIR__ . '/bootstrap.php';
function wp_kses( $value, $context ) {
	return strip_tags( $value, '<a><b>' ); }
function map_deep( $value, $callback ) {
	if ( is_array( $value ) ) {
		return array_map(
			function ( $item ) use ( $callback ) {
				return map_deep( $item, $callback ); },
			$value
		);
	}
	return call_user_func( $callback, $value ); }
eval( security_function( 'includes/functions-ur-core.php', 'ur_sanitize_user_description' ) );

$payload = '<b>hi</b><script>alert(1)</script>';
security_assert( '<b>hi</b>alert(1)' === ur_sanitize_user_description( $payload ), 'Strip scripts from the bio for users without unfiltered_html' );
$GLOBALS['caps'] = array( 'unfiltered_html' );
security_assert( $payload === ur_sanitize_user_description( $payload ), 'Keep core behavior for users with unfiltered_html' );
security_assert( ur_sanitize_user_description( array( $payload ) ) === array( $payload ), 'Keep arrays untouched for users with unfiltered_html' );
$GLOBALS['caps'] = array();
security_assert( 7 === ur_sanitize_user_description( 7 ), 'Leave non-string scalars alone' );
security_assert( array( 'k' => array( '<b>hi</b>alert(1)' ) ) === ur_sanitize_user_description( array( 'k' => array( $payload ) ) ), 'Strip scripts inside array values for users without unfiltered_html' );
security_assert( 1 === preg_match( "/add_filter\( 'sanitize_user_meta_description', 'ur_sanitize_user_description' \)/", file_get_contents( dirname( __DIR__, 2 ) . '/includes/functions-ur-core.php' ) ), 'Bio filter is registered for every writer' );
