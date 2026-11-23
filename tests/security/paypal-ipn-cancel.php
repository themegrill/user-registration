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
		public static function __callStatic( $name, $args ) {
			$GLOBALS['log_codes'][] = $args[2]['error_code'] ?? $name; }
	}
	class EmailService {}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	const MERCHANT   = 'shop@example.com';
	const PAYPAL_SUB = 'I-REAL';
	const PAYMENT_AT = '12:00:00 Nov 23, 2026 PST';

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
			if ( in_array( $name, array( 'update', 'update_if_subscription_id_matches' ), true ) ) {
				$GLOBALS['updates'][] = array_merge( array( $name ), $args );
				return 'update_if_subscription_id_matches' === $name ? $GLOBALS['update_result'] : 1; }
			return $GLOBALS['repo'][ $name ] ?? array();
		}
	}

	eval(
		'namespace WPEverest\URMembership\Admin\Services\Paypal; use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging; use WPEverest\URMembership\Admin\Services\EmailService; class IpnHarness { public $members_orders_repository, $members_subscription_repository, $membership_repository, $orders_repository;'
		. 'public function validate_ipn( $mode ) { return true; }'
		. security_function( 'modules/membership/includes/Admin/Services/Paypal/PaypalService.php', 'handle_membership_paypal_ipn' )
		. '}'
	);

	/** Deliver a PayPal-verified IPN for membership 7 / member 9 / subscription row 3 and return every write it caused. */
	function deliver_ipn( $fields, $stored_paypal_id = PAYPAL_SUB, $status = 'active', $update_result = 1 ) {
		$GLOBALS['updates']                       = array();
		$GLOBALS['log_codes']                     = array();
		$GLOBALS['update_result']                 = $update_result;
		$GLOBALS['repo']                          = array(
			'get_member_orders'                        => array(
				'ID'              => 5,
				'status'          => 'completed',
				'user_id'         => 9,
				'subscription_id' => 3,
			),
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
				'status'          => $status,
				'subscription_id' => $stored_paypal_id,
			),
		);
		$harness                                  = new WPEverest\URMembership\Admin\Services\Paypal\IpnHarness();
		$harness->members_orders_repository       = new DoubleRepo();
		$harness->members_subscription_repository = new DoubleRepo();
		$harness->membership_repository           = new DoubleRepo();
		$harness->orders_repository               = new DoubleRepo();
		$harness->handle_membership_paypal_ipn( array_merge( array( 'custom' => '7-9-0-3' ), $fields ) );
		return $GLOBALS['updates'];
	}

	function cancel_fields( $business, $subscr_id ) {
		return array(
			'txn_type'  => 'subscr_cancel',
			'business'  => $business,
			'subscr_id' => $subscr_id,
		); }

	security_assert( array() === deliver_ipn( cancel_fields( 'attacker@evil.test', 'I-ATTACKER' ) ), 'A cancel IPN addressed to another merchant does not cancel the member' );
	security_assert( array() === deliver_ipn( cancel_fields( 'attacker@evil.test', PAYPAL_SUB ) ), 'A foreign merchant cannot cancel even with the right PayPal subscription ID' );
	security_assert( array() === deliver_ipn( cancel_fields( '', PAYPAL_SUB ) ), 'A cancel IPN with no merchant does not cancel the member' );
	security_assert( array() === deliver_ipn( cancel_fields( MERCHANT, 'I-OTHER' ) ), 'The site\x27s own merchant cannot cancel through a different PayPal subscription' );
	security_assert( array() === deliver_ipn( cancel_fields( MERCHANT, '' ) ), 'A cancel IPN with no PayPal subscription ID does not cancel the member' );
	security_assert( array() === deliver_ipn( cancel_fields( MERCHANT, 'I-FORGED' ), '' ), 'A membership with no stored PayPal ID (one-time) cannot be cancelled by an IPN' );

	$writes = deliver_ipn( cancel_fields( strtoupper( MERCHANT ), PAYPAL_SUB ) );
	security_assert( 1 === count( $writes ), 'The merchant cancelling the stored PayPal subscription cancels the member once' );
	security_assert( array( 'update_if_subscription_id_matches', '3', array( 'status' => 'canceled' ), PAYPAL_SUB ) === $writes[0], 'The write is conditional on the stored PayPal ID' );

	security_assert( array() === deliver_ipn( cancel_fields( MERCHANT, PAYPAL_SUB ), PAYPAL_SUB, 'canceled' ), 'A repeated cancel IPN for an already cancelled membership writes nothing' );
	// The row was rebound to another PayPal subscription (e.g. an upgrade) after the ID check: the conditional write matches no row.
	$writes = deliver_ipn( cancel_fields( MERCHANT, PAYPAL_SUB ), PAYPAL_SUB, 'active', 0 );
	security_assert( 1 === count( $writes ) && 'update_if_subscription_id_matches' === $writes[0][0], 'A cancel never falls back to an unconditional write' );
	security_assert( in_array( 'IPN_STALE_CANCEL', $GLOBALS['log_codes'], true ), 'A cancel that matched no row is reported as stale' );
	deliver_ipn( cancel_fields( MERCHANT, PAYPAL_SUB ), PAYPAL_SUB, 'active', false );
	security_assert( in_array( 'IPN_CANCEL_DB_ERROR', $GLOBALS['log_codes'], true ) && ! in_array( 'IPN_STALE_CANCEL', $GLOBALS['log_codes'], true ), 'A database error is reported as such, not as a stale cancel' );

	// Every other IPN type must come from this site's merchant too, including types that carry payment fields.
	foreach ( array( 'subscr_signup', 'subscr_payment', 'web_accept', 'subscr_eot', 'cart' ) as $type ) {
		$fields = array(
			'txn_type'       => $type,
			'business'       => 'attacker@evil.test',
			'subscr_id'      => 'I-ATTACKER',
			'payment_date'   => PAYMENT_AT,
			'payment_status' => 'Completed',
		);
		security_assert( array() === deliver_ipn( $fields ), "A foreign merchant's {$type} IPN changes nothing" );
	}
}
