<?php
// phpcs:ignoreFile
/**
 * Regression check for scheduled-downgrade double billing.
 *
 * handle_upgrade_for_paypal() used to skip cancelling the OLD PayPal subscription
 * entirely whenever the new one was a scheduled downgrade (delayed_until set),
 * leaving that cancellation to the next day's cron. The old subscription then
 * kept billing with PayPal right up to delayed_until -- the same instant the new
 * one starts -- so both charge around the same date.
 *
 * Fixed by cancelling the old PayPal subscription immediately once the new
 * (deferred) one is approved, without touching the local row (that stays on the
 * cron, at delayed_until).
 *
 * No real PayPal HTTP: a pre_http_request stub records calls to the OAuth token
 * and subscription-cancel endpoints and fails the test on any other request, so
 * this both proves the cancel call happens and that nothing else does.
 *
 * Run: php tests/manual/test-paypal-scheduled-downgrade-cancel-timing.php
 */

define( 'WP_USE_THEMES', false );
require_once dirname( __DIR__, 5 ) . '/wp-load.php';

use WPEverest\URMembership\Admin\Services\Paypal\NewPaypalService;

$failures = 0;
$total    = 0;

/**
 * @param bool   $condition Assertion result.
 * @param string $label     Case description for failure output.
 */
function check_cancel_timing( $condition, $label ) {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		printf( "ok: %s\n", $label );
	} else {
		++$failures;
		printf( "FAIL: %s\n", $label );
	}
}

const TEST_OLD_PAYPAL_SUBSCRIPTION_ID = 'TEST-OLD-PAYPAL-SUB';

$captured_requests = array();

$stub = function ( $preempt, $parsed_args, $url ) use ( &$captured_requests ) {
	if ( false !== strpos( $url, '/v1/oauth2/token' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'TEST-ACCESS-TOKEN' ) ),
		);
	}

	if ( false !== strpos( $url, '/v1/billing/subscriptions/' ) && false !== strpos( $url, '/cancel' ) ) {
		$captured_requests[] = array(
			'url'  => $url,
			'body' => $parsed_args['body'] ?? '',
		);

		return array(
			'response' => array( 'code' => 204 ),
			'body'     => '',
		);
	}

	// Anything else means the fix reached further than expected (e.g. touching the local row via a live status check) -- fail loudly instead of silently hitting the network.
	return new WP_Error( 'unexpected_paypal_call', 'Unexpected PayPal API call: ' . $url );
};

add_filter( 'pre_http_request', $stub, 10, 3 );

$member_id = wp_insert_user(
	array(
		'user_login' => 'urm-test-cancel-timing-' . time(),
		'user_email' => 'urm-test-cancel-timing-' . time() . '@example.test',
		'user_pass'  => wp_generate_password(),
	)
);

update_user_meta(
	$member_id,
	'urm_previous_order_data',
	wp_json_encode( array( 'payment_method' => 'paypal' ) )
);
update_user_meta(
	$member_id,
	'urm_previous_subscription_data',
	wp_json_encode(
		array(
			'subscription_id' => TEST_OLD_PAYPAL_SUBSCRIPTION_ID,
			'item_id'         => 0,
		)
	)
);
update_user_meta(
	$member_id,
	'urm_next_subscription_data',
	wp_json_encode( array( 'delayed_until' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ) ) )
);

( new NewPaypalService() )->handle_upgrade_for_paypal( $member_id, 0 );

remove_filter( 'pre_http_request', $stub, 10 );

check_cancel_timing( 1 === count( $captured_requests ), 'exactly one PayPal call made (the cancel), no live-status check on the local row' );
check_cancel_timing(
	! empty( $captured_requests ) && false !== strpos( $captured_requests[0]['url'], rawurlencode( TEST_OLD_PAYPAL_SUBSCRIPTION_ID ) ),
	'the OLD subscription was the one cancelled, at approval time -- not deferred to the next day'
);

// The cron still needs these to finalize the switch at delayed_until -- the redirect must not clean them up early.
check_cancel_timing( '' !== get_user_meta( $member_id, 'urm_next_subscription_data', true ), 'urm_next_subscription_data left in place for the daily cron' );
check_cancel_timing( '' !== get_user_meta( $member_id, 'urm_previous_subscription_data', true ), 'urm_previous_subscription_data left in place for the daily cron' );

delete_user_meta( $member_id, 'urm_previous_order_data' );
delete_user_meta( $member_id, 'urm_previous_subscription_data' );
delete_user_meta( $member_id, 'urm_next_subscription_data' );
wp_delete_user( $member_id );

printf( "\n%d/%d passed\n", $total - $failures, $total );

exit( $failures > 0 ? 1 : 0 );
