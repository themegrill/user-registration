<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: the upgrade path is checked against the plan the member really owns, not a client-supplied ID.
 *
 * Runs the real `SubscriptionService::can_upgrade()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine, PEAR.NamingConventions.ValidClassName.Invalid

namespace WPEverest\URMembership\Admin\Services {
	class MembershipService {
		public function get_membership_details( $id ) {
			return array( 'id' => $id ); }
	}
	class UpgradeMembershipService {
		public function get_upgrade_details( $details ) {
			if ( ! empty( $GLOBALS['keyed'] ) ) {
				// Group mode: the whole per-plan map comes back once the current plan has a path.
				return isset( $GLOBALS['paths'][ $details['ID'] ] ) ? array(
					'upgrade_path' => array_map(
						function ( $ids ) {
							return array_map(
								function ( $id ) {
									return array( 'membership_id' => $id );
								},
								$ids
							);
						},
						$GLOBALS['paths']
					),
				) : array();
			}
			return array( 'upgrade_path' => $GLOBALS['paths'][ $details['ID'] ] ?? '' ); }
	}
	class RepoDouble {
		public function retrieve( $id ) {
			return $GLOBALS['subscriptions'][ $id ] ?? array(); }
		public function get_latest_completed_order_by_subscription_and_item( $subscription_id, $item_id ) {
			return array( 'ID' => 1 ); }
		public function get_single_membership_by_ID( $id ) {
			return array( 'meta_value' => '{}' ); }
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	function get_current_user_id() {
		return 5; }

	eval(
		'namespace WPEverest\URMembership\Admin\Services;'
		. 'class UpgradeRunner {'
		. ' public $subscription_repository; public $orders_repository; public $membership_repository;'
		. ' public function __construct() { $this->subscription_repository = $this->orders_repository = $this->membership_repository = new RepoDouble(); }'
		. ' public '
		. security_function( 'modules/membership/includes/Admin/Services/SubscriptionService.php', 'can_upgrade' )
		. ' }'
	);

	/**
	 * Run can_upgrade() as the viewer (user 5), who owns subscription 100 on plan 10.
	 *
	 * @param array $data Request data.
	 * @return bool
	 */
	function upgrade_allowed( $data ) {
		// Plan 10 may only go to 11; unowned plan 20 may go to the privileged plan 30.
		$GLOBALS['paths']         = array(
			10 => array( 11 ),
			20 => array( 30 ),
		);
		$GLOBALS['subscriptions'] = array(
			100 => array(
				'ID'      => 100,
				'user_id' => 5,
				'item_id' => 10,
			),
			200 => array(
				'ID'      => 200,
				'user_id' => 9,
				'item_id' => 10,
			),
		);
		$runner                   = new \WPEverest\URMembership\Admin\Services\UpgradeRunner();
		$result                   = $runner->can_upgrade( $data );
		return $result['status'];
	}

	security_assert(
		true === upgrade_allowed(
			array(
				'current_subscription_id' => 100,
				'current_membership_id'   => 10,
				'selected_membership_id'  => 11,
			)
		),
		'An honest upgrade along the configured path is allowed'
	);
	security_assert(
		false === upgrade_allowed(
			array(
				'current_subscription_id' => 100,
				'current_membership_id'   => 10,
				'selected_membership_id'  => 30,
			)
		),
		'An honest upgrade outside the configured path is refused'
	);
	security_assert(
		false === upgrade_allowed(
			array(
				'current_subscription_id' => 100,
				'current_membership_id'   => 20,
				'selected_membership_id'  => 30,
			)
		),
		'A forged current_membership_id cannot borrow another plan\'s upgrade path'
	);
	security_assert(
		true === upgrade_allowed(
			array(
				'current_subscription_id' => 100,
				'current_membership_id'   => 20,
				'selected_membership_id'  => 11,
			)
		),
		'The path is derived from the owned subscription even when the submitted plan differs'
	);
	security_assert(
		false === upgrade_allowed(
			array(
				'current_subscription_id' => 200,
				'current_membership_id'   => 10,
				'selected_membership_id'  => 11,
			)
		),
		'Another member\'s subscription cannot be upgraded'
	);
	security_assert( false === upgrade_allowed( array( 'selected_membership_id' => 11 ) ), 'A request without a subscription is refused' );

	$GLOBALS['keyed'] = true;
	security_assert(
		true === upgrade_allowed(
			array(
				'current_subscription_id' => 100,
				'current_membership_id'   => 10,
				'selected_membership_id'  => 11,
			)
		),
		'Group paths: an honest upgrade is allowed'
	);
	security_assert(
		false === upgrade_allowed(
			array(
				'current_subscription_id' => 100,
				'current_membership_id'   => 20,
				'selected_membership_id'  => 30,
			)
		),
		'Group paths: a forged current_membership_id cannot borrow another plan\'s path'
	);
}
