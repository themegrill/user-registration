<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test doubles share this file.
/**
 * Regression: a Stripe subscription renewal completes its order before the confirmation step runs, so the
 * confirmation must still count as a renewal. When Stripe took no payment (trial or free coupon) the only
 * proof is the subscription saved on that order; a completed order on its own proves nothing.
 *
 * Runs the real `StripeService::update_order()`; no live site, database or Stripe account is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.PHP.Eval.Discouraged, WordPress.Security.NonceVerification.Missing

namespace Stripe {
	class Stripe {
		public static function setApiKey( $key ) {}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	class PaymentGatewayLogging {
		public static function log_webhook_received( $gateway, $message, $context = array() ) {}
	}

	class OrdersDouble {
		public function get_member_orders( $member_id ) {
			return $GLOBALS['latest_order'];
		}
	}

	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function urm_get_membership_process( $member_id ) {
		return $GLOBALS['process'];
	}

	eval(
		'class StripeUpdateOrderRunner {
			public $members_orders_repository;
			public function __construct() { $this->members_orders_repository = new OrdersDouble(); }
			public static function get_stripe_settings() { return array( "secret_key" => "sk_test", "mode" => "test" ); }
			private function update_order_error( $response, $message, $log = "", $context = array() ) {
				$response["status"]  = false;
				$response["message"] = $message;
				return $response;
			}
			' . security_function( 'modules/membership/includes/Admin/Services/Stripe/StripeService.php', 'update_order' ) . '
		}'
	);

	const PLAN = 15;

	function confirm_status( $process, $order, $payment_result ) {
		$GLOBALS['process']      = array_merge(
			array(
				'renew'    => array(),
				'upgrade'  => array(),
				'multiple' => array(),
			),
			$process
		);
		$GLOBALS['latest_order'] = $order;
		$_POST                   = array(
			'member_id'             => 7,
			'current_membership_id' => PLAN,
		);

		$data = array(
			'payment_status' => 'succeeded',
			'payment_result' => $payment_result,
		);

		return ( new StripeUpdateOrderRunner() )->update_order( $data )['status'];
	}

	$renewing  = array( 'renew' => array( PLAN ) );
	$upgrading = array( 'upgrade' => array( PLAN => 3 ) );
	$settled   = array(
		'status'         => 'completed',
		'transaction_id' => 'sub_saved',
	);

	security_assert( true === confirm_status( $renewing, $settled, array( 'id' => 'sub_saved' ) ), 'A trial or free renewal is confirmed by the subscription saved on its completed order' );
	security_assert( false === confirm_status( $renewing, $settled, array() ), 'A completed order with no payment details does not confirm a renewal' );
	security_assert( false === confirm_status( $renewing, $settled, array( 'id' => 'sub_other' ) ), 'A subscription that is not saved on the order does not confirm a renewal' );
	security_assert( false === confirm_status( $renewing, array_merge( $settled, array( 'status' => 'pending' ) ), array( 'id' => 'sub_saved' ) ), 'An unpaid order does not confirm a renewal' );
	security_assert( false === confirm_status( $renewing, array_merge( $settled, array( 'transaction_id' => '' ) ), array( 'id' => '' ) ), 'An empty transaction does not match an empty request' );
	security_assert( false === confirm_status( $renewing, array_merge( $settled, array( 'transaction_id' => 'seti_saved' ) ), array( 'id' => 'seti_saved' ) ), 'Only a Stripe subscription can stand in for a payment' );
	security_assert( true === confirm_status( $upgrading, $settled, array( 'id' => 'sub_saved' ) ), 'A trial or free upgrade is confirmed by the subscription saved on its completed order' );
	security_assert( false === confirm_status( $upgrading, $settled, array() ), 'A completed order with no payment details does not confirm an upgrade' );
	security_assert( true === confirm_status( array(), $settled, array() ), 'A first purchase whose order already completed stays confirmed' );

	$confirm_payment = security_function( 'modules/membership/includes/AJAX.php', 'confirm_payment' );
	preg_match( '/\$is_renewing\s*=\s*([^;]+);/', $confirm_payment, $is_renewing );
	security_assert( isset( $is_renewing[1] ) && false === strpos( $is_renewing[1], 'has_pending_order' ), 'A renewal whose order Stripe already completed still reaches the date update' );
}
