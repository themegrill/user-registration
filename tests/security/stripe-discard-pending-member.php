<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a declined Stripe registration may discard its pending member only for the right requester.
 *
 * Runs the real `StripeService::can_discard_pending_member()`; no live site, database or Stripe account is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine, PEAR.NamingConventions.ValidClassName.Invalid

namespace WPEverest\URMembership {
	class AJAX {
		/** The guest's registration session proves ownership of member 9 only. */
		public static function verify_pending_member_session( $member_id ) {
			return 9 === $member_id; }
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	$GLOBALS['viewer'] = 0;
	$GLOBALS['caps']   = array();

	function get_current_user_id() {
		return $GLOBALS['viewer']; }
	function is_user_logged_in() {
		return 0 !== $GLOBALS['viewer']; }

	eval( 'class StripeDiscardRunner { ' . security_function( 'modules/membership/includes/Admin/Services/Stripe/StripeService.php', 'can_discard_pending_member' ) . ' public function check( $id ) { return $this->can_discard_pending_member( $id ); } }' );

	function can_discard( $member_id, $viewer = 0, $caps = array() ) {
		$GLOBALS['viewer'] = $viewer;
		$GLOBALS['caps']   = $caps;
		return ( new StripeDiscardRunner() )->check( $member_id );
	}

	security_assert( true === can_discard( 9 ), 'The logged-out browser that registered member 9 may discard that pending member' );
	security_assert( false === can_discard( 8 ), 'A logged-out browser may not discard another pending member' );
	security_assert( true === can_discard( 4, 4 ), 'A logged-in member may discard their own pending member' );
	security_assert( true === can_discard( 4, 1, array( 'edit_users' ) ), 'An admin may discard a pending member' );
	security_assert( false === can_discard( 4, 2 ), 'Another logged-in member may not discard a pending member' );
	security_assert( false === can_discard( 9, 2 ), 'A session proof is only honoured for logged-out requests' );
}
