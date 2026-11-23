<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a verified PayPal cancel IPN only cancels a membership it belongs to.
 *
 * Runs the real `handle_membership_paypal_ipn()`; no live site, database or PayPal account is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.WP.AlternativeFunctions.json_encode_json_encode, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

namespace WPEverest\URMembership\Admin\Services {
	class PaymentGatewayLogging {
		public static function __callStatic( $name, $args ) {}
	}
	class EmailService {}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;

	const MERCHANT   = 'shop@example.com';
	const PAYPAL_SUB = 'I-REAL';

	function is_email( $value ) {
		return false !== strpos( (string) $value, '@' ); }
	function home_url() {
		return 'https://site.test'; }
	function wp_login_url() {
		return 'https://site.test/login'; }
	function esc_html__( $s ) {
		return $s; }

	$GLOBALS['options'] = array( 'user_registration_global_paypal_test_email_address' => MERCHANT );

	class DoubleRepo {
		public function __call( $name, $args ) {
			if ( 'update_if_subscription_id_matches' === $name && ! empty( $GLOBALS['row_moved'] ) ) {
				return 0; }
			if ( in_array( $name, array( 'update', 'update_if_subscription_id_matches' ), true ) ) {
				$GLOBALS['updates'][] = $args;
				return 1; }
			return $GLOBALS['repo'][ $name ] ?? array();
		}
	}

	eval(
		'namespace WPEverest\URMembership\Admin\Services\Paypal; use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging; use WPEverest\URMembership\Admin\Services\EmailService; class IpnHarness { public $members_orders_repository, $members_subscription_repository, $membership_repository, $orders_repository;'
		. 'public function validate_ipn( $mode ) { return true; }'
		. security_function( 'modules/membership/includes/Admin/Services/Paypal/PaypalService.php', 'handle_membership_paypal_ipn' )
		. '}'
	);

	/** Deliver a PayPal-verified subscr_cancel IPN and report whether the membership was cancelled. */
	function cancel_ipn( $business, $subscr_id, $stored_paypal_id, $txn_type = 'subscr_cancel' ) {
		$GLOBALS['updates']                       = array();
		$GLOBALS['repo']                          = array(
			'get_member_orders'                        => array(
				'ID'              => 5,
				'status'          => 'completed',
				'user_id'         => 9,
				'subscription_id' => 3,
			),
			'retrieve'                                 => array(),
			'get_single_membership_by_ID'              => array(
				'meta_value' => json_encode(
					array(
						'type'             => 'subscription',
						'amount'           => '10',
						'payment_gateways' => array( 'paypal' => array( 'email' => MERCHANT ) ),
					)
				),
				'post_title' => 'Gold',
			),
			'get_subscription_data_by_subscription_id' => array(
				'ID'              => 3,
				'item_id'         => 7,
				'status'          => 'active',
				'subscription_id' => $stored_paypal_id,
			),
		);
		$harness                                  = new WPEverest\URMembership\Admin\Services\Paypal\IpnHarness();
		$harness->members_orders_repository       = new DoubleRepo();
		$harness->members_subscription_repository = new DoubleRepo();
		$harness->membership_repository           = new DoubleRepo();
		$harness->orders_repository               = new DoubleRepo();
		$harness->handle_membership_paypal_ipn(
			array(
				'txn_type'  => $txn_type,
				'custom'    => '7-9-0-3',
				'business'  => $business,
				'subscr_id' => $subscr_id,
			)
		);
		return 1 === count( $GLOBALS['updates'] ) && array( 'status' => 'canceled' ) === $GLOBALS['updates'][0][1];
	}

	security_assert( ! cancel_ipn( 'attacker@evil.test', 'I-ATTACKER', PAYPAL_SUB ), 'A cancel IPN addressed to another merchant does not cancel the member' );
	security_assert( ! cancel_ipn( 'attacker@evil.test', PAYPAL_SUB, PAYPAL_SUB ), 'A foreign merchant cannot cancel even with the right PayPal subscription ID' );
	security_assert( ! cancel_ipn( MERCHANT, 'I-OTHER', PAYPAL_SUB ), 'A cancel IPN for a different PayPal subscription does not cancel the member' );
	security_assert( cancel_ipn( strtoupper( MERCHANT ), PAYPAL_SUB, PAYPAL_SUB ), 'The merchant cancelling the stored PayPal subscription still cancels the member' );
	security_assert( cancel_ipn( MERCHANT, PAYPAL_SUB, '' ), 'A membership with no stored PayPal ID is still cancelled by the merchant' );
	// An upgrade rebinds the row to another PayPal subscription after the ID check but before the write.
	$GLOBALS['row_moved'] = true;
	security_assert( ! cancel_ipn( MERCHANT, PAYPAL_SUB, PAYPAL_SUB ), 'A cancel is not applied once the row moved to another PayPal subscription' );
	$GLOBALS['row_moved'] = false;

	foreach ( array( 'subscr_signup', 'subscr_payment', 'web_accept' ) as $type ) {
		cancel_ipn( 'attacker@evil.test', 'I-ATTACKER', PAYPAL_SUB, $type );
		security_assert( array() === $GLOBALS['updates'], "A foreign merchant's {$type} IPN still changes nothing" );   }
}
