<?php
/**
 * Regression check for the scheduled-downgrade cron's correlation guard.
 *
 * `get_all_delayed_orders()` used to join a due `delayed_until` order-meta row to
 * `urm_next_subscription_data` by user_id alone. That user meta is a single value
 * overwritten by ANY later upgrade/downgrade attempt (delayed or not, per
 * PaymentService::build_response()), so a member with one still-due delayed order
 * and a newer, unrelated urm_next_subscription_data would have the newer data
 * misapplied to the older order: wrong plan switch, wrong subscription cancelled.
 *
 * Fixed by requiring the order's own order_id to match what's decoded from
 * urm_next_subscription_data before acting on it.
 *
 * Needs a real WP bootstrap (DB-backed repositories) but no PayPal HTTP calls --
 * both fixtures use payment_method 'bank', which cancel_subscription_by_id()
 * resolves locally with no network call.
 *
 * Run: php tests/manual/test-paypal-delayed-order-stale-meta.php
 */

define( 'WP_USE_THEMES', false );
require_once dirname( __DIR__, 5 ) . '/wp-load.php';

use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\TableList;

$failures = 0;
$total    = 0;

/**
 * @param bool   $condition Assertion result.
 * @param string $label     Case description for failure output.
 */
function check( $condition, $label ) {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		printf( "ok: %s\n", $label );
	} else {
		++$failures;
		printf( "FAIL: %s\n", $label );
	}
}

// A real, existing subscription plan post -- prepare_upgrade_subscription_data() reads its meta.
$membership_id = 5252;
if ( ! get_post( $membership_id ) ) {
	fwrite( STDERR, "Fixture membership post #$membership_id not found -- adjust \$membership_id for this site.\n" );
	exit( 1 );
}

global $wpdb;
$subscriptions_table = TableList::subscriptions_table();
$subscription_repo    = new SubscriptionRepository();
$orders_repo          = new OrdersRepository();
$subscription_service = new SubscriptionService();

$yesterday = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );

/**
 * Create one subscription + one due delayed order for a fresh test user.
 *
 * @return array{user_id: int, subscription_id: int, order_id: int}
 */
function make_fixture( $membership_id, $subscription_repo, $orders_repo, $delayed_until ) {
	static $n = 0;
	++$n;

	$user_id = wp_insert_user(
		array(
			'user_login' => 'urm-test-stale-meta-' . $n . '-' . time(),
			'user_email' => 'urm-test-stale-meta-' . $n . '-' . time() . '@example.test',
			'user_pass'  => wp_generate_password(),
		)
	);

	$subscription = $subscription_repo->create(
		array(
			'item_id'           => $membership_id,
			'user_id'           => $user_id,
			'start_date'        => gmdate( 'Y-m-d 00:00:00' ),
			'expiry_date'       => $delayed_until,
			'next_billing_date' => $delayed_until,
			'billing_cycle'     => 'day',
			'billing_amount'    => 20,
			'status'            => 'active',
			'subscription_id'   => 'TEST-OLD-' . $n,
		)
	);

	$order = $orders_repo->create(
		array(
			'orders_data'      => array(
				'item_id'         => $membership_id,
				'user_id'         => $user_id,
				'subscription_id' => $subscription['ID'],
				'created_by'      => $user_id,
				'transaction_id'  => 'TEST-TXN-' . $n,
				'payment_method'  => 'bank',
				'total_amount'    => 10,
				'status'          => 'completed',
				'order_type'      => 'subscription',
				'trial_status'    => 'off',
				'notes'           => '',
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			'orders_meta_data' => array(
				array(
					'meta_key'   => 'delayed_until',
					'meta_value' => $delayed_until,
				),
			),
		)
	);

	update_user_meta( $user_id, 'urm_previous_subscription_data', wp_json_encode( $subscription ) );

	return array(
		'user_id'         => (int) $user_id,
		'subscription_id' => (int) $subscription['ID'],
		'order_id'        => (int) $order['ID'],
	);
}

// --- Scenario 1: urm_next_subscription_data matches this order's own subscription/date -- happy path. ---
$matching = make_fixture( $membership_id, $subscription_repo, $orders_repo, $yesterday );
update_user_meta(
	$matching['user_id'],
	'urm_next_subscription_data',
	wp_json_encode(
		array(
			'membership'                   => $membership_id,
			'member_id'                    => $matching['user_id'],
			'subscription_id'              => $matching['subscription_id'],
			'order_id'                     => $matching['order_id'],
			'payment_method'               => 'bank',
			'delayed_until'                => $yesterday,
			'remaining_subscription_value' => 1,
		)
	)
);

// --- Scenario 2: urm_next_subscription_data was overwritten by a second delayed attempt on the SAME
// subscription submitted before either took effect (so subscription_id/delayed_until alone wouldn't catch
// it) -- the order_id itself is the only thing that still tells them apart, and it must be skipped. ---
$stale = make_fixture( $membership_id, $subscription_repo, $orders_repo, $yesterday );
update_user_meta(
	$stale['user_id'],
	'urm_next_subscription_data',
	wp_json_encode(
		array(
			'membership'                   => $membership_id,
			'member_id'                    => $stale['user_id'],
			'subscription_id'              => $stale['subscription_id'],
			// A different order for the same subscription/date -- a second delayed attempt, not this one.
			'order_id'                     => $stale['order_id'] + 999,
			'payment_method'               => 'bank',
			'delayed_until'                => $yesterday,
			'remaining_subscription_value' => 1,
		)
	)
);

$subscription_service->run_daily_delayed_membership_subscriptions();

// Scenario 1: the matching row was processed -- switched to the new plan, delayed_until meta cleared.
$matching_row = $subscription_repo->retrieve( $matching['subscription_id'] );
check( 'active' === $matching_row['status'], 'matching order: subscription left active' );
$matching_delayed_meta = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->prefix}ur_membership_ordermeta WHERE order_id = %d AND meta_key = 'delayed_until'",
		$matching['order_id']
	)
);
check( null === $matching_delayed_meta, 'matching order: delayed_until meta cleaned up after processing' );

// Scenario 2: the stale row must be untouched -- still on its original subscription_id, delayed_until meta intact.
$stale_row = $subscription_repo->retrieve( $stale['subscription_id'] );
check( 'active' === $stale_row['status'], 'stale order: subscription left active (unmodified)' );
check( str_starts_with( (string) $stale_row['subscription_id'], 'TEST-OLD-' ), 'stale order: subscription_id NOT switched to the mismatched next_subscription_data' );
$stale_delayed_meta = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->prefix}ur_membership_ordermeta WHERE order_id = %d AND meta_key = 'delayed_until'",
		$stale['order_id']
	)
);
check( null !== $stale_delayed_meta, 'stale order: delayed_until meta left in place for a future retry' );

// --- Cleanup ---
foreach ( array( $matching, $stale ) as $fixture ) {
	$wpdb->delete( $subscriptions_table, array( 'ID' => $fixture['subscription_id'] ) );
	$wpdb->delete( "{$wpdb->prefix}ur_membership_orders", array( 'ID' => $fixture['order_id'] ) );
	$wpdb->delete( "{$wpdb->prefix}ur_membership_ordermeta", array( 'order_id' => $fixture['order_id'] ) );
	delete_user_meta( $fixture['user_id'], 'urm_previous_subscription_data' );
	delete_user_meta( $fixture['user_id'], 'urm_next_subscription_data' );
	wp_delete_user( $fixture['user_id'] );
}

printf( "\n%d/%d passed\n", $total - $failures, $total );

exit( $failures > 0 ? 1 : 0 );
