<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: Social Connect registrants get the configured Free membership only when the opt-in is on.
 *
 * Runs the real `MembershipService` plan lookup and assignment plus the `Admin` registration hook; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine, PEAR.NamingConventions.ValidClassName.Invalid

namespace WPEverest\URMembership\Admin\Repositories {
	class MembersSubscriptionRepository {
		public function get_member_subscriptions( $user_id ) {
			return $GLOBALS['subscriptions'][ $user_id ] ?? array(); }
	}
}

namespace WPEverest\URMembership\Admin\Services {
	class RecordingLogger {
		public function error( $message, $context = array() ) {
			$GLOBALS['logged'][] = $message; }
	}
}

namespace WPEverest\URMembership\HookTest {
	class MembershipService {
		public function assign_default_free_membership_for_social_connect( $user_id ) {
			$GLOBALS['assigned'][] = $user_id;
			return array( 'status' => true ); }
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	const SOCIAL_BYPASS_META = 'user_registration_social_connect_bypass_current_password';

	function ur_check_module_activation( $module ) {
		return $GLOBALS['membership_active']; }
	function ur_string_to_bool( $value ) {
		return in_array( $value, array( true, 1, '1', 'yes', 'true' ), true ); }
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['user_meta'][ $user_id ][ $key ] ?? ''; }
	function get_userdata( $user_id ) {
		return $GLOBALS['users'][ $user_id ] ?? false; }

	eval(
		'namespace WPEverest\URMembership\Admin\Services;'
		. 'use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;'
		. 'class MembershipService { private $logger; public function __construct() { $this->logger = new RecordingLogger(); }'
		. ' public function list_active_memberships() { return $GLOBALS["plans"]; }'
		. ' public function create_membership_order_and_subscription( $data ) { $GLOBALS["created"][] = $data; return $GLOBALS["create_result"]; } '
		. security_function( 'modules/membership/includes/Admin/Services/MembershipService.php', 'get_default_free_membership_id_for_social_connect' )
		. security_function( 'modules/membership/includes/Admin/Services/MembershipService.php', 'assign_default_free_membership_for_social_connect' )
		. ' }'
	);
	eval(
		'namespace WPEverest\URMembership\HookTest;'
		. 'class HookRunner { '
		. security_function( 'modules/membership/includes/Admin.php', 'maybe_assign_default_free_membership_on_social_connect' )
		. ' }'
	);

	/** Reset every double to a site with Membership on, no plans and no users. */
	function reset_site() {
		$GLOBALS['membership_active'] = true;
		$GLOBALS['options']           = array();
		$GLOBALS['plans']             = array();
		$GLOBALS['subscriptions']     = array();
		$GLOBALS['user_meta']         = array();
		$GLOBALS['users']             = array();
		$GLOBALS['created']           = array();
		$GLOBALS['logged']            = array();
		$GLOBALS['assigned']          = array();
		$GLOBALS['create_result']     = array( 'status' => true );
	}

	function plan( $id, $type ) {
		return array(
			'ID'    => $id,
			'type'  => $type,
			'title' => "Plan $id",
		);
	}

	function service() {
		return new \WPEverest\URMembership\Admin\Services\MembershipService();
	}

	/**
	 * Fire the registration hook and return the user IDs that were assigned a membership.
	 *
	 * @param mixed $option  Value of the Assign Free Membership opt-in.
	 * @param mixed $bypass  Value of the Social Connect bypass meta; empty for a normal signup.
	 * @param bool  $module  Whether the Membership module is active.
	 * @param int   $user_id Registrant user ID.
	 */
	function run_hook( $option, $bypass, $module = true, $user_id = 7 ) {
		reset_site();
		$GLOBALS['membership_active']                                                     = $module;
		$GLOBALS['options']['user_registration_membership_assign_free_on_social_connect'] = $option;
		$GLOBALS['user_meta'][ $user_id ][ SOCIAL_BYPASS_META ]                           = $bypass;
		( new \WPEverest\URMembership\HookTest\HookRunner() )->maybe_assign_default_free_membership_on_social_connect( array(), 0, $user_id );
		return $GLOBALS['assigned'];
	}

	// Plan resolution: the configured Free plan wins, anything else falls back to the first active Free plan.
	reset_site();
	$GLOBALS['plans'] = array( plan( 10, 'paid' ), plan( 11, 'free' ), plan( 12, 'free' ) );
	$GLOBALS['options']['user_registration_membership_social_connect_default_plan'] = 12;
	security_assert( 12 === service()->get_default_free_membership_id_for_social_connect(), 'The configured Free plan is used' );
	$GLOBALS['options']['user_registration_membership_social_connect_default_plan'] = 10;
	security_assert( 11 === service()->get_default_free_membership_id_for_social_connect(), 'A configured paid plan is rejected in favour of the first Free plan' );
	$GLOBALS['options']['user_registration_membership_social_connect_default_plan'] = 99;
	security_assert( 11 === service()->get_default_free_membership_id_for_social_connect(), 'A configured plan that no longer exists falls back to the first Free plan' );
	$GLOBALS['options']['user_registration_membership_social_connect_default_plan'] = '';
	security_assert( 11 === service()->get_default_free_membership_id_for_social_connect(), 'An unset plan falls back to the first Free plan' );
	$GLOBALS['plans'] = array( plan( 10, 'paid' ) );
	security_assert( 0 === service()->get_default_free_membership_id_for_social_connect(), 'No Free plan yields 0' );

	// Assignment.
	reset_site();
	security_assert( false === service()->assign_default_free_membership_for_social_connect( 5 ) && array() === $GLOBALS['created'], 'An unknown user is skipped' );

	$GLOBALS['users'][5]         = (object) array(
		'user_login' => 'sam',
		'user_email' => 'sam@example.test',
	);
	$GLOBALS['subscriptions'][5] = array( array( 'item_id' => 11 ) );
	$GLOBALS['plans']            = array( plan( 11, 'free' ) );
	security_assert( false === service()->assign_default_free_membership_for_social_connect( 5 ) && array() === $GLOBALS['created'], 'A user who already has a subscription is not given a second one' );

	$GLOBALS['subscriptions'] = array();
	$GLOBALS['plans']         = array();
	security_assert( false === service()->assign_default_free_membership_for_social_connect( 5 ), 'No Free plan means no assignment' );
	security_assert( 1 === count( $GLOBALS['logged'] ) && array() === $GLOBALS['created'], 'A missing Free plan is logged and nothing is created' );

	$GLOBALS['plans']  = array( plan( 11, 'free' ) );
	$GLOBALS['logged'] = array();
	$result            = service()->assign_default_free_membership_for_social_connect( 5 );
	security_assert( array( 'status' => true ) === $result, 'A new user without a subscription gets the Free plan' );
	security_assert( 1 === count( $GLOBALS['created'] ), 'Exactly one membership order is created' );
	security_assert( 11 === $GLOBALS['created'][0]['membership'] && 'free' === $GLOBALS['created'][0]['payment_method'], 'The order is for the Free plan with the free payment method' );
	security_assert( 'sam' === $GLOBALS['created'][0]['username'] && 'sam@example.test' === $GLOBALS['created'][0]['email'] && true === $GLOBALS['created'][0]['is_initial_registration'], 'The order carries the registrant and is flagged as an initial registration' );

	$GLOBALS['create_result'] = array(
		'status'  => false,
		'message' => 'boom',
	);
	$GLOBALS['logged']        = array();
	security_assert( false === service()->assign_default_free_membership_for_social_connect( 5 ) && 1 === count( $GLOBALS['logged'] ), 'A failed order creation is logged and reported as not assigned' );

	// Registration hook: only an opted-in Social Connect signup is assigned.
	security_assert( array( 7 ) === run_hook( '1', true ), 'An opted-in Social Connect signup is assigned a membership' );
	security_assert( array( 7 ) === run_hook( 'yes', true ), 'The opt-in also accepts the yes value' );
	security_assert( array() === run_hook( '0', true ), 'Opt-in off: no membership is assigned' );
	security_assert( array() === run_hook( false, true ), 'Opt-in never saved: no membership is assigned' );
	security_assert( array() === run_hook( '1', '' ), 'A normal (non-social) registration is never assigned by this hook' );
	security_assert( array() === run_hook( '1', true, false ), 'Membership module off: no membership is assigned' );
	security_assert( array() === run_hook( '1', true, true, 0 ), 'A zero user ID is ignored' );
}
