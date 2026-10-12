<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: every membership checkout link is recognised for a logged-in member.
 *
 * Runs the real `UR_Shortcodes::has_membership_checkout_intent()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';

eval( 'class UrShortcodesIntent { public static ' . security_function( 'includes/class-ur-shortcodes.php', 'has_membership_checkout_intent' ) . ' }' );

function is_checkout_link( $query ) {
	parse_str( $query, $params );
	return UrShortcodesIntent::has_membership_checkout_intent( $params );
}

// My Account renew and upgrade links carry the membership as `current`.
security_assert( is_checkout_link( 'action=renew&current=12&subscription_id=3&thank_you=40' ), 'The My Account renew link opens the checkout' );
security_assert( is_checkout_link( 'action=upgrade&current=12&thank_you=40' ), 'The My Account upgrade link opens the checkout' );
// The membership listing and the Masteriyo upgrade link.
security_assert( is_checkout_link( 'membership_id=12&action=multiple&thank_you=40' ), 'The purchase link of the membership listing opens the checkout' );
security_assert( is_checkout_link( 'action=upgrade&thank_you=40' ), 'The Masteriyo upgrade link opens the checkout' );

// Anything else keeps the logged-in notice.
security_assert( ! is_checkout_link( '' ), 'The registration page without parameters is not a checkout' );
security_assert( ! is_checkout_link( 'action=renew' ), 'An action alone is not a checkout' );
security_assert( ! is_checkout_link( 'current=12' ), 'A generic current parameter is not a checkout' );
security_assert( ! is_checkout_link( 'membership_id=12&action=register' ), 'A link without a thank you page is not a checkout' );
