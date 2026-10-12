<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: redelivering an already-processed `invoice.payment_succeeded` event must not extend the
 * membership again, and a first delivery takes its dates from the invoice's billing period, not from today.
 *
 * Runs the real `StripeService::handle_succeeded_invoice()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.DateTime.RestrictedFunctions.date_date, WordPress.WP.AlternativeFunctions.json_encode_json_encode

require __DIR__ . '/bootstrap.php';

class PaymentGatewayLogging {
	public static function log_webhook_received( $gateway, $message, $context = array() ) {}
	public static function log_webhook_processed( $gateway, $message, $context = array() ) {}
	public static function log_general( $gateway, $message, $level = '' ) {
		$GLOBALS['general_logs'][] = $message;
	}
	public static function log_error( $gateway, $message ) {
		$GLOBALS['logged_errors'][] = $message;
	}
}
class SubscriptionService {
	public static function get_expiry_date( $start, $period, $value ) {
		return ( new DateTime( $start ) )->modify( "+{$value} {$period}" )->format( 'Y-m-d' );
	}
	public function update_subscription_data_for_renewal( $subscription, $metas ) {
		$GLOBALS['renewals'][] = $subscription['sub_id'];
	}
}
class OrderService {
	public function prepare_orders_data( $info, $member_id, $subscription ) {
		return array( 'orders_data' => array() );
	}
}
class OrdersRepository {
	public function get_order_by_transaction_id( $transaction_id ) {
		return $GLOBALS['existing_order'];
	}
	public function get_order_by_subscription( $subscription_id ) {
		return false;
	}
	public function create( $data ) {
		$GLOBALS['orders_created'][] = $data;
		return 99;
	}
	public function update( $id, $data ) {}
}
class SubscriptionRepositoryDouble {
	public $writes = array();
	public $row;
	public function get_membership_by_subscription_id( $id, $secondary = false ) {
		return array(
			'user_id' => 76,
			'item_id' => 12,
			'sub_id'  => 7,
			'status'  => $this->row['status'],
		);
	}
	public function retrieve( $id ) {
		return $this->row;
	}
	public function update( $id, $data ) {
		$this->writes[] = $data;
		return 1;
	}
}
class MembershipRepositoryDouble {
	public function get_single_membership_by_ID( $id ) {
		return array( 'meta_value' => json_encode( array( 'type' => 'subscription', 'amount' => 29, 'subscription' => array( 'duration' => 'month', 'value' => 1 ) ) ) );
	}
}
function get_user_meta( $user_id, $key, $single = false ) {
	return '';
}
function delete_transient( $key ) {}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_die() {
	throw new SecurityResponse( false, 'wp_die', 500 );
}
function urm_get_membership_process( $member_id ) {
	return $GLOBALS['membership_process'];
}

$stripe_service = 'modules/membership/includes/Admin/Services/Stripe/StripeService.php';
$methods        = '';
foreach ( array( 'handle_succeeded_invoice', 'get_invoice_payment_intent_id', 'get_invoice_period', 'extract_stripe_id', 'is_renewing_membership' ) as $method ) {
	$methods .= security_function( $stripe_service, $method );
}
eval( 'class StripeReplayHarness { public $members_subscription_repository; public $membership_repository; ' . $methods . '}' );

// 2026-10-08 00:00 UTC to 2026-11-08 00:00 UTC: the billing period the invoice paid for.
const PERIOD_START = 1791417600;
const PERIOD_END   = 1794096000;

/**
 * Deliver one invoice.payment_succeeded event for a subscription row and return what the handler wrote.
 *
 * @param array $row     Stored subscription row (status, expiry_date).
 * @param array $invoice Invoice fields merged over a paid renewal invoice.
 * @param array $options `order` is the order already stored for the PaymentIntent (none by default, as on a first
 *                       delivery); `renewing` marks a renewal the member started.
 */
$deliver = function ( $row, $invoice = array(), $options = array() ) {
	$GLOBALS['general_logs']       = array();
	$GLOBALS['logged_errors']      = array();
	$GLOBALS['orders_created']     = array();
	$GLOBALS['renewals']           = array();
	$GLOBALS['existing_order']     = $options['order'] ?? array();
	$GLOBALS['membership_process'] = empty( $options['renewing'] ) ? array() : array( 'renew' => array( 12 ) );

	$service                                       = new StripeReplayHarness();
	$service->members_subscription_repository      = new SubscriptionRepositoryDouble();
	$service->members_subscription_repository->row = $row;
	$service->membership_repository                = new MembershipRepositoryDouble();

	$invoice += array(
		'id'             => 'in_renewal',
		'payment_intent' => 'pi_renewal',
		'amount_paid'    => 2900,
		'lines'          => array(
			'data' => array(
				array(
					'period' => array(
						'start' => PERIOD_START,
						'end'   => PERIOD_END,
					),
				),
			),
		),
	);
	$service->handle_succeeded_invoice( array( 'id' => 'evt_1', 'data' => array( 'object' => $invoice ) ), 'sub_renewal' );

	return $service->members_subscription_repository->writes;
};

$settled = array( 'order' => array( 'ID' => 55, 'status' => 'completed' ) );
$renewed = array(
	'status'      => 'active',
	'expiry_date' => '2026-11-08 00:00:00',
);
$due     = array( 'expiry_date' => '2026-10-08 00:00:00' ) + $renewed;

// 1. Stripe resends an event whose invoice the subscription already reflects: nothing changes.
$writes = $deliver( $renewed, array(), $settled );
security_assert( array() === $writes, 'A redelivered invoice leaves the subscription dates and status alone' );
security_assert( array() === $GLOBALS['orders_created'], 'A redelivered invoice creates no second order' );
security_assert( 1 === count( $GLOBALS['general_logs'] ) && false !== strpos( $GLOBALS['general_logs'][0], 'invoice_already_applied' ), 'A redelivered invoice is logged as already applied, not as a new renewal order' );

// 2. The same when the stored expiry carries a time of day written by a Stripe sync.
$writes = $deliver( array( 'expiry_date' => '2026-11-08 13:45:10' ) + $renewed, array(), $settled );
security_assert( array() === $writes, 'A redelivery does not move an expiry that already covers the invoice period' );

// 3. An older invoice resent after a later renewal must not pull the dates back or extend them.
$writes = $deliver( array( 'expiry_date' => '2026-12-08 00:00:00' ) + $renewed, array(), $settled );
security_assert( array() === $writes, 'An older invoice does not rewrite a later expiry' );

// 4. A replayed old invoice carries no new payment: it leaves an active, expired or canceled subscription alone.
foreach ( array( 'active', 'expired', 'canceled' ) as $status ) {
	$writes = $deliver( array( 'status' => $status ) + $renewed, array(), $settled );
	security_assert( array() === $writes, 'A replayed invoice leaves a ' . $status . ' subscription alone' );
}

// 4b. A member whose first payment was never recognised (the order was linked by the backfill) is still activated, without touching the dates.
foreach ( array( 'pending', 'trial' ) as $status ) {
	$writes = $deliver( array( 'status' => $status ) + $renewed, array(), $settled );
	security_assert( array( array( 'status' => 'active' ) ) === $writes, 'A replayed invoice activates a ' . $status . ' subscription and nothing else' );
}

// 5. A replay that lands while the member has a renewal pending does not run the renewal.
$deliver( $renewed, array(), $settled + array( 'renewing' => true ) );
security_assert( array() === $GLOBALS['renewals'], 'A replayed invoice does not renew a pending renewal' );

// 6. The first delivery of a renewal takes its dates from the invoice period, not from the delivery day.
$writes = $deliver( $due );
security_assert( 1 === count( $writes ) && 1 === count( $GLOBALS['orders_created'] ), 'The first delivery of a renewal creates its order and updates the subscription once' );
security_assert( '2026-10-08 00:00:00' === $writes[0]['start_date'], 'The start date is the invoice period start' );
security_assert( '2026-11-08 00:00:00' === $writes[0]['expiry_date'] && '2026-11-08 00:00:00' === $writes[0]['next_billing_date'], 'The expiry and next billing date are the invoice period end' );
security_assert( 'active' === $writes[0]['status'], 'The renewed subscription is active' );

// 7. That first delivery still renews a pending renewal.
$deliver( $due, array(), array( 'renewing' => true ) );
security_assert( array( 7 ) === $GLOBALS['renewals'], 'The first delivery runs the pending renewal' );

// 8. A Stripe sync or the retry cron already wrote the period end before the webhook arrived: the delivery is still a first one.
$deliver( $renewed, array(), array( 'renewing' => true ) );
security_assert( array( 7 ) === $GLOBALS['renewals'] && 1 === count( $GLOBALS['orders_created'] ), 'A first delivery after a Stripe sync still creates its order and runs the renewal' );

// 9. The backfill saved the order but never touched the dates: the late webhook still extends the subscription.
$writes = $deliver( $due, array(), $settled );
security_assert( 1 === count( $writes ) && '2026-11-08 00:00:00' === $writes[0]['expiry_date'], 'A late webhook for a backfilled order extends the subscription' );

// 10. An order still pending (checkout not confirmed) does not make the webhook a replay.
$writes = $deliver( array( 'status' => 'pending' ) + $renewed, array(), array( 'order' => array( 'ID' => 55, 'status' => 'pending' ) ) );
security_assert( 1 === count( $writes ) && 'active' === $writes[0]['status'], 'A webhook for a pending order activates the subscription' );
$deliver( $renewed, array(), array( 'order' => array( 'ID' => 55, 'status' => 'pending' ), 'renewing' => true ) );
security_assert( array( 7 ) === $GLOBALS['renewals'], 'A webhook for a pending order still runs the pending renewal' );

// 11. A late renewal on an expired subscription extends it and reactivates it.
$writes = $deliver( array( 'status' => 'expired', 'expiry_date' => '2026-10-08 00:00:00' ) + $renewed );
security_assert( 1 === count( $writes ) && 'active' === $writes[0]['status'] && '2026-11-08 00:00:00' === $writes[0]['expiry_date'], 'A late renewal reactivates and extends an expired subscription' );

// 12. A subscription that never had an expiry (zero date) is extended by its first invoice, even with a settled order.
$writes = $deliver( array( 'expiry_date' => '0000-00-00 00:00:00' ) + $renewed, array(), $settled );
security_assert( 1 === count( $writes ) && '2026-11-08 00:00:00' === $writes[0]['expiry_date'], 'An unset expiry is filled from the invoice period' );

// 13. The first paid invoice after a trial activates the member even when the locally computed expiry is a few days past Stripe's.
$writes = $deliver( array( 'status' => 'trial', 'expiry_date' => '2026-11-10 00:00:00' ) + $renewed );
security_assert( array( array( 'status' => 'active' ) ) === $writes && 1 === count( $GLOBALS['orders_created'] ), 'A trial member becomes active on their first paid invoice without their later expiry being pulled back' );

// 13b. Stripe does not guarantee event order: an older invoice delivered after a later renewal records its payment but never moves the dates back.
$writes = $deliver( array( 'expiry_date' => '2026-12-08 00:00:00' ) + $renewed );
security_assert( 1 === count( $GLOBALS['orders_created'] ), 'An out-of-order older invoice still records its payment' );
security_assert( ! isset( $writes[0]['expiry_date'] ) && ! isset( $writes[0]['start_date'] ) && ! isset( $writes[0]['next_billing_date'] ), 'An out-of-order older invoice does not move the dates back' );

// 14. A canceled subscription stays canceled on a first delivery.
$writes = $deliver( array( 'status' => 'canceled' ) + $due );
security_assert( 'canceled' === $writes[0]['status'], 'A canceled subscription stays canceled' );

// 15. An invoice that carries no period keeps the previous behaviour instead of dropping the payment.
$writes = $deliver( $renewed, array( 'lines' => array( 'data' => array() ) ), $settled );
security_assert( 1 === count( $writes ) && gmdate( 'Y-m-d 00:00:00' ) === $writes[0]['start_date'], 'An invoice without a billing period still renews from today' );

// 15b. A line without a period start cannot anchor a billing period, so it is not trusted for a 1970 start date.
$writes = $deliver( $renewed, array( 'lines' => array( 'data' => array( array( 'period' => array( 'end' => PERIOD_END ) ) ) ) ), $settled );
security_assert( 1 === count( $writes ) && gmdate( 'Y-m-d 00:00:00' ) === $writes[0]['start_date'], 'A line without a period start is ignored' );

// 16. Several lines on the invoice (a proration credit next to the recurring charge): the latest period end wins.
$writes = $deliver(
	$due,
	array(
		'lines' => array(
			'data' => array(
				array( 'period' => array( 'start' => PERIOD_START, 'end' => PERIOD_START + 86400 ) ),
				array( 'period' => array( 'start' => PERIOD_START, 'end' => PERIOD_END ) ),
			),
		),
	)
);
security_assert( '2026-11-08 00:00:00' === $writes[0]['expiry_date'], 'The recurring line decides the new expiry' );
