<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test doubles share this file.
/**
 * Regression for issue #2029:
 * 1. Client-reported "failed" confirmation during a Stripe upgrade must check Stripe first; when the new
 *    subscription or PaymentIntent actually succeeded, the upgrade completes instead of leaving the old
 *    subscription active. A genuine decline (including when the local row still holds the old sub ID) must
 *    still fail and revert.
 * 2. revert_subscription() must complete the upgrade (cancel old sub, clear upgrade meta) instead of silently
 *    returning when Stripe already charged the member.
 * 3. Delayed (end-of-cycle) upgrades must never cancel the old subscription or wipe delayed meta early.
 * 4. Refunding an old plan's or replaced Stripe subscription's charge after an upgrade must only mark the old
 *    order refunded and leave the new active subscription intact locally and in Stripe.
 *
 * Runs the real StripeService methods with lightweight test doubles; no live site, DB or Stripe account used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.PHP.Eval.Discouraged, WordPress.Security.NonceVerification.Missing, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.WP.AlternativeFunctions.json_encode_json_encode

namespace Stripe {
	class Stripe {
		public static function setApiKey( $key ) {}
	}

	class Subscription {
		public static $records = array();

		public static function retrieve( $id ) {
			if ( isset( self::$records[ $id ] ) ) {
				return self::$records[ $id ];
			}
			throw new \Stripe\Exception\ApiErrorException( 'Not found: ' . $id );
		}
	}

	class SubscriptionSchedule {
		public static $records = array();

		public static function retrieve( $id ) {
			if ( isset( self::$records[ $id ] ) ) {
				return self::$records[ $id ];
			}
			throw new \Stripe\Exception\ApiErrorException( 'Not found: ' . $id );
		}
	}

	class PaymentIntent {
		public static $records = array();

		public static function retrieve( $id ) {
			if ( isset( self::$records[ $id ] ) ) {
				return self::$records[ $id ];
			}
			throw new \Stripe\Exception\ApiErrorException( 'Not found: ' . $id );
		}
	}

	class Invoice {
		public static $records = array();

		public static function retrieve( $id ) {
			if ( isset( self::$records[ $id ] ) ) {
				return self::$records[ $id ];
			}
			throw new \Stripe\Exception\ApiErrorException( 'Not found: ' . $id );
		}
	}
}

namespace Stripe\Exception {
	class ApiErrorException extends \Exception {}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	$GLOBALS['user_meta']        = array();
	$GLOBALS['transients']       = array();
	$GLOBALS['order_updates']    = array();
	$GLOBALS['sub_updates']      = array();
	$GLOBALS['deleted_orders']   = array();
	$GLOBALS['canceled_subs']    = array();
	$GLOBALS['refunded_calls']   = array();
	$GLOBALS['latest_order']     = array();
	$GLOBALS['subscriptions_db'] = array();
	$GLOBALS['orders_by_tx']     = array();

	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function get_user_meta( $user_id, $key = '', $single = false ) {
		return $GLOBALS['user_meta'][ $user_id ][ $key ] ?? '';
	}

	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}

	function delete_user_meta( $user_id, $key, $value = '' ) {
		unset( $GLOBALS['user_meta'][ $user_id ][ $key ] );
		return true;
	}

	function delete_transient( $key ) {
		unset( $GLOBALS['transients'][ $key ] );
		return true;
	}

	function urm_get_membership_process( $member_id ) {
		$val = get_user_meta( $member_id, 'urm_membership_process', true );
		return is_array( $val ) ? $val : array();
	}

	class PaymentGatewayLogging {
		public static function log_webhook_received( $gateway, $message, $context = array() ) {}
		public static function log_webhook_processed( $gateway, $message, $context = array() ) {}
		public static function log_general( $gateway, $message, $level = 'info' ) {}
		public static function log_error( $gateway, $message, $context = array() ) {}
	}

	class SubscriptionService {
		public function cancel_subscription( $order, $subscription, $force_cancel = false ) {
			$GLOBALS['canceled_subs'][] = array(
				'via'          => 'SubscriptionService',
				'subscription' => $subscription['subscription_id'] ?? '',
				'force'        => $force_cancel,
			);
			return array( 'status' => true );
		}
	}

	class OrdersRepoDouble {
		public function get_member_orders( $member_id ) {
			return $GLOBALS['latest_order'];
		}

		public function get_order_by_transaction_id( $tx_id ) {
			return $GLOBALS['orders_by_tx'][ $tx_id ] ?? array();
		}

		public function update( $id, $data ) {
			$GLOBALS['order_updates'][] = array(
				'id'   => $id,
				'data' => $data,
			);
			if ( ( $GLOBALS['latest_order']['ID'] ?? 0 ) === $id ) {
				$GLOBALS['latest_order'] = array_merge( $GLOBALS['latest_order'], $data );
			}
			foreach ( $GLOBALS['orders_by_tx'] as $tx => $row ) {
				if ( ( $row['ID'] ?? 0 ) === $id ) {
					$GLOBALS['orders_by_tx'][ $tx ] = array_merge( $row, $data );
				}
			}
			return true;
		}

		public function delete_member_order( $member_id, $flag = true ) {
			$GLOBALS['deleted_orders'][] = $member_id;
		}
	}

	class SubscriptionRepoDouble {
		public function retrieve( $id ) {
			return $GLOBALS['subscriptions_db'][ $id ] ?? array();
		}

		public function update( $id, $data ) {
			$GLOBALS['sub_updates'][] = array(
				'id'   => $id,
				'data' => $data,
			);
			if ( isset( $GLOBALS['subscriptions_db'][ $id ] ) ) {
				$GLOBALS['subscriptions_db'][ $id ] = array_merge( $GLOBALS['subscriptions_db'][ $id ], $data );
			}
			return true;
		}
	}

	$stripe_file = 'modules/membership/includes/Admin/Services/Stripe/StripeService.php';

	eval(
		'class StripeUpgradeSafetyRunner {
			public $members_orders_repository;
			public $orders_repository;
			public $members_subscription_repository;
			public function __construct() {
				$this->members_orders_repository       = new OrdersRepoDouble();
				$this->orders_repository               = $this->members_orders_repository;
				$this->members_subscription_repository = new SubscriptionRepoDouble();
			}
			public static function get_stripe_settings() {
				return array( "secret_key" => "sk_test", "mode" => "test" );
			}
			private function update_order_error( $response, $message, $log = "", $context = array() ) {
				$response["status"]  = false;
				$response["message"] = $message;
				return $response;
			}
			public function cancel_subscription( $order, $subscription, $force_cancel = false ) {
				$GLOBALS["canceled_subs"][] = array(
					"via"          => "StripeService",
					"subscription" => $subscription["subscription_id"] ?? "",
					"force"        => $force_cancel,
				);
				return array( "status" => true );
			}
			public function refund( $order, $subscription ) {
				$GLOBALS["refunded_calls"][] = $order["ID"] ?? 0;
				return array( "status" => true );
			}
			public function check_refund_canceled( $order, $charge = null ) {
				return $this->is_refund_subscription_canceled( $order, $charge );
			}
			public function run_cancel_previous_upgrade( int $member_id, array $prev = array(), string $new_id = "" ): bool {
				return $this->cancel_previous_upgrade_subscription( $member_id, $prev, $new_id );
			}
			' . security_function( $stripe_file, 'extract_stripe_id' ) . '
			' . security_function( $stripe_file, 'update_order' ) . '
			' . security_function( $stripe_file, 'verify_and_complete_paid_upgrade_order' ) . '
			' . security_function( $stripe_file, 'ensure_paid_upgrade_records_active' ) . '
			' . security_function( $stripe_file, 'cancel_previous_upgrade_subscription' ) . '
			' . security_function( $stripe_file, 'complete_paid_upgrade' ) . '
			' . security_function( $stripe_file, 'revert_subscription' ) . '
			' . security_function( $stripe_file, 'stripe_charge_succeeded' ) . '
			' . security_function( $stripe_file, 'handle_refunded_charge' ) . '
			' . security_function( $stripe_file, 'order_belongs_to_current_subscription' ) . '
			' . security_function( $stripe_file, 'apply_refund_to_order_and_subscription' ) . '
			' . security_function( $stripe_file, 'is_refund_subscription_canceled' ) . '
		}'
	);

	function reset_fixtures() {
		$GLOBALS['user_meta']                  = array();
		$GLOBALS['transients']                 = array();
		$GLOBALS['order_updates']              = array();
		$GLOBALS['sub_updates']                = array();
		$GLOBALS['deleted_orders']             = array();
		$GLOBALS['canceled_subs']              = array();
		$GLOBALS['refunded_calls']             = array();
		$GLOBALS['latest_order']               = array();
		$GLOBALS['subscriptions_db']           = array();
		$GLOBALS['orders_by_tx']               = array();
		\Stripe\Subscription::$records         = array();
		\Stripe\SubscriptionSchedule::$records = array();
		\Stripe\PaymentIntent::$records        = array();
		\Stripe\Invoice::$records              = array();
	}

	$runner = new StripeUpgradeSafetyRunner();

	// 1. Client reports "failed" on upgrade after new Stripe subscription is already active.
	reset_fixtures();
	$member_id = 42;
	$_POST     = array(
		'member_id'             => $member_id,
		'current_membership_id' => 10,
	);
	update_user_meta( $member_id, 'urm_membership_process', array( 'upgrade' => array( 10 => array( 'to' => 20 ) ) ) );
	update_user_meta(
		$member_id,
		'urm_previous_subscription_data',
		json_encode(
			array(
				'ID'              => 536,
				'item_id'         => 10,
				'subscription_id' => 'sub_old',
				'status'          => 'active',
			)
		)
	);
	$GLOBALS['latest_order']                  = array(
		'ID'              => 237,
		'subscription_id' => 536,
		'payment_method'  => 'stripe',
		'order_type'      => 'subscription',
		'status'          => 'pending',
		'transaction_id'  => 'pi_new',
	);
	$GLOBALS['subscriptions_db'][536]         = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_new',
		'status'          => 'pending',
	);
	\Stripe\Subscription::$records['sub_new'] = (object) array(
		'id'     => 'sub_new',
		'status' => 'active',
	);

	$res = $runner->update_order(
		array(
			'payment_status' => 'failed',
			'payment_result' => array( 'error' => array() ),
		)
	);
	security_assert( true === $res['status'], 'Client-reported failed upgrade still confirms when Stripe shows new subscription active' );
	security_assert( 'completed' === $GLOBALS['latest_order']['status'], 'Pending upgrade order is marked completed from Stripe state' );
	security_assert( 'active' === $GLOBALS['subscriptions_db'][536]['status'], 'Pending upgrade subscription is marked active from Stripe state' );

	// 2. Genuine decline before new subscription is created: local row still holds sub_old (active in Stripe).
	reset_fixtures();
	$_POST = array(
		'member_id'             => $member_id,
		'current_membership_id' => 10,
	);
	update_user_meta( $member_id, 'urm_membership_process', array( 'upgrade' => array( 10 => array( 'to' => 20 ) ) ) );
	update_user_meta(
		$member_id,
		'urm_previous_subscription_data',
		json_encode(
			array(
				'ID'              => 536,
				'item_id'         => 10,
				'subscription_id' => 'sub_old',
				'status'          => 'active',
			)
		)
	);
	$GLOBALS['latest_order']                  = array(
		'ID'              => 238,
		'subscription_id' => 536,
		'payment_method'  => 'stripe',
		'order_type'      => 'subscription',
		'status'          => 'pending',
		'transaction_id'  => '',
	);
	$GLOBALS['subscriptions_db'][536]         = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_old',
		'status'          => 'active',
	);
	\Stripe\Subscription::$records['sub_old'] = (object) array(
		'id'     => 'sub_old',
		'status' => 'active',
	);

	$res = $runner->update_order(
		array(
			'payment_status' => 'failed',
			'payment_result' => array( 'error' => array( 'message' => 'Your card was declined.' ) ),
		)
	);
	security_assert( false === $res['status'], 'Active OLD subscription ID on local row is never mistaken for a paid upgrade' );

	// 3. Genuine decline on incomplete new subscription still fails and reverts.
	reset_fixtures();
	$_POST = array(
		'member_id'             => $member_id,
		'current_membership_id' => 10,
	);
	update_user_meta( $member_id, 'urm_membership_process', array( 'upgrade' => array( 10 => array( 'to' => 20 ) ) ) );
	update_user_meta(
		$member_id,
		'urm_previous_subscription_data',
		json_encode(
			array(
				'ID'              => 536,
				'item_id'         => 10,
				'subscription_id' => 'sub_old',
				'status'          => 'active',
			)
		)
	);
	update_user_meta(
		$member_id,
		'urm_stripe_replaced_subscription',
		array(
			'old' => 'sub_old',
			'new' => 'sub_inc',
		)
	);
	$GLOBALS['latest_order']                  = array(
		'ID'              => 239,
		'subscription_id' => 536,
		'payment_method'  => 'stripe',
		'order_type'      => 'subscription',
		'status'          => 'pending',
		'transaction_id'  => '',
	);
	$GLOBALS['subscriptions_db'][536]         = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_inc',
		'status'          => 'pending',
	);
	\Stripe\Subscription::$records['sub_inc'] = (object) array(
		'id'     => 'sub_inc',
		'status' => 'incomplete',
	);

	$res = $runner->update_order(
		array(
			'payment_status' => 'failed',
			'payment_result' => array( 'error' => array( 'message' => 'Authentication failed.' ) ),
		)
	);
	security_assert( false === $res['status'], 'Incomplete new subscription returns failed status' );
	$runner->revert_subscription( $member_id );
	security_assert( 10 === $GLOBALS['subscriptions_db'][536]['item_id'], 'Genuine decline reverts subscription back to old plan' );
	security_assert( 'sub_old' === $GLOBALS['subscriptions_db'][536]['subscription_id'], 'Genuine decline restores old Stripe subscription ID' );
	security_assert( '' === get_user_meta( $member_id, 'urm_stripe_replaced_subscription', true ), 'Genuine decline clears replaced subscription marker' );

	// 4. revert_subscription() when charge succeeded completes upgrade, cancels old sub, and clears meta.
	reset_fixtures();
	$_POST = array(
		'member_id'             => $member_id,
		'current_membership_id' => 10,
	);
	update_user_meta( $member_id, 'urm_membership_process', array( 'upgrade' => array( 10 => array( 'to' => 20 ) ) ) );
	update_user_meta(
		$member_id,
		'urm_previous_subscription_data',
		json_encode(
			array(
				'ID'              => 536,
				'item_id'         => 10,
				'subscription_id' => 'sub_old',
				'status'          => 'active',
			)
		)
	);
	update_user_meta(
		$member_id,
		'urm_previous_order_data',
		json_encode(
			array(
				'ID'             => 236,
				'payment_method' => 'stripe',
			)
		)
	);
	update_user_meta( $member_id, 'urm_next_subscription_data', json_encode( array( 'membership' => 20 ) ) );
	$GLOBALS['latest_order']                  = array(
		'ID'              => 237,
		'subscription_id' => 536,
		'payment_method'  => 'stripe',
		'order_type'      => 'subscription',
		'status'          => 'completed',
		'transaction_id'  => 'pi_new',
	);
	$GLOBALS['subscriptions_db'][536]         = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_new',
		'status'          => 'active',
	);
	\Stripe\Subscription::$records['sub_new'] = (object) array(
		'id'     => 'sub_new',
		'status' => 'active',
	);

	$runner->revert_subscription( $member_id );
	security_assert( 1 === count( $GLOBALS['canceled_subs'] ) && 'sub_old' === $GLOBALS['canceled_subs'][0]['subscription'] && true === $GLOBALS['canceled_subs'][0]['force'], 'revert_subscription() force-cancels old subscription when new charge succeeded' );
	security_assert( '' === get_user_meta( $member_id, 'urm_previous_subscription_data', true ), 'revert_subscription() clears urm_previous_subscription_data after completing paid upgrade' );
	security_assert( '' === get_user_meta( $member_id, 'urm_previous_order_data', true ), 'revert_subscription() clears urm_previous_order_data after completing paid upgrade' );
	security_assert( empty( urm_get_membership_process( $member_id )['upgrade'] ), 'revert_subscription() clears urm_membership_process upgrade entry' );
	security_assert( 1 === get_user_meta( $member_id, 'urm_is_user_upgraded', true ), 'revert_subscription() marks user as upgraded' );
	security_assert( empty( $GLOBALS['deleted_orders'] ), 'revert_subscription() does not delete the paid upgrade order' );

	// 5. Delayed (end-of-cycle) upgrade must NOT cancel old sub or wipe delayed meta.
	reset_fixtures();
	update_user_meta(
		$member_id,
		'urm_previous_subscription_data',
		json_encode(
			array(
				'ID'              => 536,
				'item_id'         => 10,
				'subscription_id' => 'sub_old',
				'status'          => 'active',
			)
		)
	);
	update_user_meta(
		$member_id,
		'urm_next_subscription_data',
		json_encode(
			array(
				'membership'    => 20,
				'delayed_until' => '2026-12-01',
			)
		)
	);
	$delayed_res = $runner->run_cancel_previous_upgrade(
		$member_id,
		array(
			'ID'              => 536,
			'item_id'         => 10,
			'subscription_id' => 'sub_old',
		),
		'sub_new'
	);
	security_assert( false === $delayed_res, 'Delayed upgrade skips immediate cancellation of old subscription' );
	security_assert( empty( $GLOBALS['canceled_subs'] ), 'Delayed upgrade makes no cancel API call' );
	security_assert( '' !== get_user_meta( $member_id, 'urm_next_subscription_data', true ), 'Delayed upgrade keeps urm_next_subscription_data intact' );

	// 6. Second hazard: full refund of OLD plan order (#236) after upgrade must NOT cancel NEW subscription (#536 -> sub_new).
	reset_fixtures();
	$GLOBALS['orders_by_tx']['pi_old'] = array(
		'ID'              => 236,
		'user_id'         => $member_id,
		'item_id'         => 10,
		'subscription_id' => 536,
		'status'          => 'completed',
		'transaction_id'  => 'pi_old',
	);
	$GLOBALS['subscriptions_db'][536]  = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_new',
		'status'          => 'active',
	);
	$runner->handle_refunded_charge(
		array(
			'id'   => 'evt_ref_old',
			'data' => array(
				'object' => array(
					'payment_intent' => 'pi_old',
					'refunded'       => true,
				),
			),
		)
	);
	security_assert( 'refunded' === $GLOBALS['orders_by_tx']['pi_old']['status'], 'Refunding old plan charge marks old order refunded' );
	security_assert( 'active' === $GLOBALS['subscriptions_db'][536]['status'], 'Refunding old plan charge keeps new subscription active locally' );
	security_assert( empty( $GLOBALS['canceled_subs'] ), 'Refunding old plan charge does NOT cancel new subscription in Stripe' );
	security_assert( true === $runner->check_refund_canceled( $GLOBALS['orders_by_tx']['pi_old'] ), 'Backfill treats refunded old-plan order as already handled' );

	// 7. Second hazard (same-plan renewal/replacement): invoice belongs to sub_old while local row points to sub_new.
	reset_fixtures();
	$GLOBALS['orders_by_tx']['pi_same_old'] = array(
		'ID'              => 240,
		'user_id'         => $member_id,
		'item_id'         => 20,
		'subscription_id' => 536,
		'status'          => 'completed',
		'transaction_id'  => 'pi_same_old',
	);
	$GLOBALS['subscriptions_db'][536]       = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_new',
		'status'          => 'active',
	);
	\Stripe\Invoice::$records['in_old']     = array(
		'id'           => 'in_old',
		'subscription' => 'sub_old',
	);
	$charge_same_plan                       = array(
		'payment_intent' => 'pi_same_old',
		'invoice'        => 'in_old',
		'refunded'       => true,
	);
	$runner->handle_refunded_charge(
		array(
			'id'   => 'evt_ref_same',
			'data' => array( 'object' => $charge_same_plan ),
		)
	);
	security_assert( 'refunded' === $GLOBALS['orders_by_tx']['pi_same_old']['status'], 'Refunding replaced same-plan charge marks old order refunded' );
	security_assert( 'active' === $GLOBALS['subscriptions_db'][536]['status'], 'Refunding replaced same-plan charge keeps current subscription active' );
	security_assert( empty( $GLOBALS['canceled_subs'] ), 'Refunding replaced same-plan charge does NOT cancel current Stripe subscription' );
	security_assert( true === $runner->check_refund_canceled( $GLOBALS['orders_by_tx']['pi_same_old'], $charge_same_plan ), 'Backfill skips replaced same-plan order on subsequent runs' );

	// 8. Full refund of CURRENT plan charge MUST still cancel local and Stripe subscription.
	reset_fixtures();
	$GLOBALS['orders_by_tx']['pi_new']  = array(
		'ID'              => 237,
		'user_id'         => $member_id,
		'item_id'         => 20,
		'subscription_id' => 536,
		'status'          => 'completed',
		'transaction_id'  => 'pi_new',
	);
	$GLOBALS['subscriptions_db'][536]   = array(
		'ID'              => 536,
		'item_id'         => 20,
		'subscription_id' => 'sub_new',
		'status'          => 'active',
	);
	\Stripe\Invoice::$records['in_new'] = array(
		'id'           => 'in_new',
		'subscription' => 'sub_new',
	);
	$runner->handle_refunded_charge(
		array(
			'id'   => 'evt_ref_new',
			'data' => array(
				'object' => array(
					'payment_intent' => 'pi_new',
					'invoice'        => 'in_new',
					'refunded'       => true,
				),
			),
		)
	);
	security_assert( 'refunded' === $GLOBALS['orders_by_tx']['pi_new']['status'], 'Refunding current plan charge marks order refunded' );
	security_assert( 'canceled' === $GLOBALS['subscriptions_db'][536]['status'], 'Refunding current plan charge cancels local subscription' );
	security_assert( 1 === count( $GLOBALS['canceled_subs'] ) && 'sub_new' === $GLOBALS['canceled_subs'][0]['subscription'], 'Refunding current plan charge cancels current Stripe subscription' );

	echo "ALL_ASSERTIONS_PASSED\n";
}
