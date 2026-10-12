<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a member cannot add a plan that is outside every "Allow multiple memberships" group.
 *
 * Runs the real `SubscriptionService::can_purchase_multiple()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine, PEAR.NamingConventions.ValidClassName.Invalid

namespace WPEverest\URMembership\Admin\Repositories {
	class MembershipGroupRepository {
		public function get_membership_group_by_membership_id( $membership_id ) {
			$group_id = $GLOBALS['plan_group'][ $membership_id ] ?? 0;
			return $group_id ? array( 'ID' => $group_id ) : array(); }
	}
	class MembersRepository {
		public function get_member_membership_by_id( $user_id ) {
			return array_map(
				function ( $plan ) {
					return array( 'post_id' => $plan ); },
				$GLOBALS['member_plans']
			); }
	}
}

namespace WPEverest\URMembership\Admin\Services {
	class MembershipService {}
	class MembershipGroupService {
		public function check_if_multiple_memberships_allowed( $group_id ) {
			return in_array( $group_id, $GLOBALS['multiple_groups'], true ); }
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	const UR_PRO_ACTIVE = true;

	function urm_check_if_plus_and_above_plan() {
		return true; }
	function ur_check_module_activation( $module ) {
		return true; }
	function get_current_user_id() {
		return $GLOBALS['viewer']; }
	function esc_html__( $text, $domain = null ) {
		return $text; }

	eval(
		'namespace WPEverest\URMembership\Admin\Services;'
		. 'use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;'
		. 'use WPEverest\URMembership\Admin\Repositories\MembersRepository;'
		. 'class SubscriptionRunner { public '
		. security_function( 'modules/membership/includes/Admin/Services/SubscriptionService.php', 'can_purchase_multiple' )
		. ' }'
	);

	/**
	 * Whether the viewer may add $selected on top of the plans they already hold.
	 *
	 * @param int   $selected        Selected plan ID.
	 * @param array $plan_groups     Plan ID => group ID (a plan missing here is in no group).
	 * @param array $member_plans    Plans the member holds.
	 * @param array $multiple_groups Groups that allow multiple memberships.
	 * @param int   $viewer          User ID, 0 for a logged-out visitor.
	 */
	function can_add_plan( $selected, $plan_groups, $member_plans, $multiple_groups, $viewer = 5 ) {
		$GLOBALS['plan_group']      = $plan_groups;
		$GLOBALS['member_plans']    = $member_plans;
		$GLOBALS['multiple_groups'] = $multiple_groups;
		$GLOBALS['viewer']          = $viewer;
		$runner                     = new \WPEverest\URMembership\Admin\Services\SubscriptionRunner();
		$result                     = $runner->can_purchase_multiple( array( 'selected_membership_id' => $selected ) );
		return $result['status'];
	}

	// Plans 10 and 11 are in no group.
	security_assert( false === can_add_plan( 11, array(), array( 10 ), array() ), 'A logged-in member cannot add a plan that is in no group' );
	security_assert( false === can_add_plan( 11, array(), array( 10 ), array(), 0 ), 'A logged-out visitor cannot add a plan that is in no group' );

	// Plans 20 and 21 are in group 1, plan 30 is in group 2.
	$groups = array(
		20 => 1,
		21 => 1,
		30 => 2,
	);
	security_assert( true === can_add_plan( 21, $groups, array( 20 ), array( 1 ) ), 'A second plan of a group that allows multiple memberships is allowed' );
	security_assert( false === can_add_plan( 21, $groups, array( 20 ), array() ), 'A second plan of a group that does not allow multiple memberships is refused' );
	security_assert( true === can_add_plan( 30, $groups, array( 20 ), array() ), 'A plan of another group the member holds nothing in is allowed' );
	security_assert( true === can_add_plan( 30, $groups, array( 10 ), array() ), 'A grouped plan on top of an ungrouped one is allowed' );
	security_assert( false === can_add_plan( 20, $groups, array( 20, 30 ), array( 2 ) ), 'Holding plans in other groups does not open up a group that disallows multiple memberships' );
}
