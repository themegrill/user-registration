<?php
// phpcs:ignoreFile -- Standalone integration regression runner.
/** Real WordPress services/DB, HTTP-only stubs. Run: wp eval-file <this file>. */
if ( 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) {
 define( 'WP_USE_THEMES', false );
 require_once dirname( __DIR__, 5 ) . '/wp-load.php';
}
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Services\Paypal\NewPaypalService;
global $wpdb, $failures, $total, $plan, $subs, $orders, $service, $remote, $cancels, $cancel_error, $events, $on_read;
$wpdb->query( 'START TRANSACTION' );
register_shutdown_function( function () use ( $wpdb ) { $wpdb->query( 'ROLLBACK' ); } );
add_filter( 'pre_wp_mail', '__return_true' );
$failures = 0; $total = 0;
function check_cancel_timing( $condition, $label ) {
 global $failures, $total;
 ++$total;
 if ( ! $condition ) { ++$failures; }
 printf( "%s: %s\n", $condition ? 'ok' : 'FAIL', $label );
}
foreach ( array( 'test', 'live' ) as $mode ) {
 foreach ( array( 'client_id', 'client_secret' ) as $field ) {
  add_filter( 'pre_option_user_registration_global_paypal_' . $mode . '_' . $field, function () { return 'test-only'; } );
 }
}
$remote = array(); $cancels = array(); $cancel_error = false; $events = array(); $on_read = null;
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$remote, &$cancels, &$cancel_error, &$events, &$on_read ) {
 $response = function ( $data, $code = 200 ) { return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $data ) ); };
 if ( false !== strpos( $url, '/v1/oauth2/token' ) ) { return $response( array( 'access_token' => 'fixture-token' ) ); }
 if ( false !== strpos( $url, '/v1/notifications/webhooks-events?' ) ) {
  parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
  return $response( array( 'events' => array_values( array_filter( $events, function ( $event ) use ( $query ) { return $event['event_type'] === $query['event_type']; } ) ), 'links' => array() ) );
 }
 if ( preg_match( '~/v1/billing/subscriptions/([^/?]+)(/cancel)?$~', $url, $m ) ) {
  $id = $m[1];
  if ( ! empty( $m[2] ) ) {
   $cancels[] = $id;
   if ( $cancel_error ) { return new WP_Error( 'fixture_timeout', 'Cancellation timed out' ); }
   $remote[$id]['status'] = 'CANCELLED';
   return $response( array(), 204 );
  }
  if ( $on_read ) { $on_read( $id ); }
  return isset( $remote[$id] ) ? ( is_wp_error( $remote[$id] ) ? $remote[$id] : $response( $remote[$id] ) ) : new WP_Error( 'unexpected_id', $id );
 }
 return new WP_Error( 'unexpected_http', 'Blocked unexpected request: ' . $url );
}, 10, 3 );
$plan = wp_insert_post( array( 'post_type' => 'ur_membership', 'post_status' => 'publish', 'post_title' => 'PayPal regression fixture' ) );
update_post_meta( $plan, 'ur_membership', wp_json_encode( array( 'type' => 'subscription', 'amount' => 10, 'trial_status' => 'off', 'subscription' => array( 'duration' => 'day', 'value' => 1 ) ) ) );
$subs = new SubscriptionRepository(); $orders = new OrdersRepository(); $service = new NewPaypalService();
function cancel_fixture() {
 global $plan, $subs, $orders, $remote, $cancels, $cancel_error;
 $cancels = array(); $cancel_error = false;
 $uid = wp_insert_user( array( 'user_login' => 'paypal-regression-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_uuid4() . '@example.test' ) );
 $until = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
 $row = $subs->create( array( 'user_id' => $uid, 'item_id' => $plan, 'status' => 'active', 'subscription_id' => 'OLD-' . $uid, 'billing_cycle' => 'day', 'billing_amount' => 20, 'start_date' => gmdate( 'Y-m-d H:i:s' ), 'expiry_date' => $until, 'next_billing_date' => $until ) );
 $order = $orders->create( array( 'orders_data' => array( 'user_id' => $uid, 'item_id' => $plan, 'subscription_id' => $row['ID'], 'created_by' => $uid, 'payment_method' => 'paypal', 'total_amount' => 10, 'status' => 'pending', 'order_type' => 'subscription', 'trial_status' => 'off', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), 'orders_meta_data' => array( array( 'meta_key' => 'delayed_until', 'meta_value' => $until ) ) ) );
 $next = array( 'membership' => $plan, 'member_id' => $uid, 'subscription_id' => $row['ID'], 'order_id' => $order['ID'], 'delayed_until' => $until, 'payment_method' => 'paypal', 'remaining_subscription_value' => 1 );
 update_user_meta( $uid, 'urm_previous_subscription_data', wp_json_encode( $row ) );
 update_user_meta( $uid, 'urm_previous_order_data', wp_json_encode( array( 'payment_method' => 'paypal' ) ) );
 update_user_meta( $uid, 'urm_next_subscription_data', wp_json_encode( $next ) );
 update_user_meta( $uid, 'urm_membership_process', array( 'upgrade' => array( $plan => $plan ) ) );
 update_user_meta( $uid, NewPaypalService::SCHEDULED_SUBSCRIPTION_META_PREFIX . $row['ID'], 'NEW-' . $uid );
 update_user_meta( $uid, 'urm_paypal_subscription_paypal_id', 'NEW-' . $uid );
 $remote['OLD-' . $uid] = array( 'id' => 'OLD-' . $uid, 'status' => 'ACTIVE' );
 $remote['NEW-' . $uid] = array( 'id' => 'NEW-' . $uid, 'status' => 'ACTIVE', 'start_time' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ) );
 return array( $uid, $row['ID'], $row, $order );
}
function subscription_event( $fixture, $type, $id ) {
 global $service, $plan;
 return $service->handle_webhook_event( array( 'id' => wp_generate_uuid4(), 'event_type' => 'BILLING.SUBSCRIPTION.' . $type, 'resource' => array( 'id' => $id, 'custom_id' => $plan . '-' . $fixture[0] . '-' . $plan . '-' . $fixture[1] ) ) );
}
$f = cancel_fixture();
$service->handle_upgrade_for_paypal( $f[0], $f[1] );
check_cancel_timing( array( 'OLD-' . $f[0] ) === $cancels, 'approved redirect cancels only the outgoing subscription' );
check_cancel_timing( $f[2] === $subs->retrieve( $f[1] ), 'approval preserves the paid local period and plan' );
$service->handle_upgrade_for_paypal( $f[0], $f[1] );
check_cancel_timing( 1 === count( $cancels ), 'repeated approval does not cancel twice' );
$f = cancel_fixture();
check_cancel_timing( true === subscription_event( $f, 'ACTIVATED', 'NEW-' . $f[0] ), 'webhook-only approval succeeds without return redirect' );
check_cancel_timing( array( 'OLD-' . $f[0] ) === $cancels, 'webhook-only approval cancels outgoing billing' );
check_cancel_timing( $f[2] === $subs->retrieve( $f[1] ), 'webhook-only approval preserves local paid access' );
subscription_event( $f, 'CANCELLED', 'OLD-' . $f[0] );
check_cancel_timing( 'active' === $subs->retrieve( $f[1] )['status'], 'expected cancellation preserves access' );
subscription_event( $f, 'CANCELLED', 'OLD-' . $f[0] );
check_cancel_timing( 'active' === $subs->retrieve( $f[1] )['status'], 'duplicate expected cancellation preserves access' );
$events = array( array( 'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED', 'create_time' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'resource' => array( 'id' => 'OLD-' . $f[0], 'status' => 'CANCELLED' ) ) );
$service->run_missed_subscription_backfill( time() - HOUR_IN_SECONDS, time() );
check_cancel_timing( 'active' === $subs->retrieve( $f[1] )['status'], 'status backfill preserves intentionally cancelled paid period' );
$events = array();
foreach ( array( 'APPROVAL_PENDING', 'SUSPENDED', 'CANCELLED', 'ERROR', 'MISSING_MARKER' ) as $state ) {
 $f = cancel_fixture();
 $remote['NEW-' . $f[0]] = 'ERROR' === $state ? new WP_Error( 'fixture_timeout', 'Lookup failed' ) : array( 'status' => $state );
 if ( 'MISSING_MARKER' === $state ) { delete_user_meta( $f[0], NewPaypalService::SCHEDULED_SUBSCRIPTION_META_PREFIX . $f[1] ); }
 $service->handle_upgrade_for_paypal( $f[0], $f[1] );
 check_cancel_timing( array() === $cancels, $state . ': unapproved replacement cannot cancel outgoing billing' );
}
foreach ( array( 'CANCELLED', 'SUSPENDED' ) as $event ) {
 $f = cancel_fixture(); $remote['NEW-' . $f[0]]['status'] = 'APPROVAL_PENDING';
 subscription_event( $f, $event, 'OLD-' . $f[0] );
 check_cancel_timing( 'canceled' === $subs->retrieve( $f[1] )['status'], 'abandoned checkout does not suppress ' . $event );
}
$f = cancel_fixture(); $cancel_error = true;
check_cancel_timing( false === subscription_event( $f, 'ACTIVATED', 'NEW-' . $f[0] ), 'cancellation failure makes the webhook retryable' );
check_cancel_timing( $f[2] === $subs->retrieve( $f[1] ), 'failed cancellation preserves local subscription' );

// Full return handler: a valid local hash is not proof of PayPal approval.
$f = cancel_fixture();
update_user_meta( $f[0], 'urm_paypal_verification_token', 'fixture-nonce' );
$params = http_build_query( array( 'member_id' => $f[0], 'membership' => $plan, 'current_membership_id' => $plan, 'hash' => wp_hash( $plan . ',' . $f[0] . ',fixture-nonce' ) ) );
$_GET = array();
$stop_redirect = function () { throw new RuntimeException( 'redirect-completed' ); };
add_filter( 'wp_redirect', $stop_redirect, -100 );
try { $service->handle_paypal_redirect_response( $params, '' ); } catch ( RuntimeException $e ) { }
remove_filter( 'wp_redirect', $stop_redirect, -100 );
check_cancel_timing( array() === $cancels, 'return URL without verified subscription cannot cancel outgoing billing' );
check_cancel_timing( 'pending' === $orders->retrieve( $f[3]['ID'] )['status'], 'unverified redirect does not complete checkout' );

// An approval arriving after start_time must still stop outgoing billing before switching.
$f = cancel_fixture();
$remote['NEW-' . $f[0]]['start_time'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 );
update_user_meta( $f[0], 'urm_paypal_verification_token', 'fixture-nonce' );
$params = http_build_query( array( 'member_id' => $f[0], 'membership' => $plan, 'current_membership_id' => $plan, 'hash' => wp_hash( $plan . ',' . $f[0] . ',fixture-nonce' ) ) );
$_GET = array( 'subscription_id' => 'NEW-' . $f[0] );
add_filter( 'wp_redirect', $stop_redirect, -100 );
try { $service->handle_paypal_redirect_response( $params, '' ); } catch ( RuntimeException $e ) { }
remove_filter( 'wp_redirect', $stop_redirect, -100 );
check_cancel_timing( array( 'OLD-' . $f[0] ) === $cancels, 'late-approved redirect still cancels outgoing subscription' );
check_cancel_timing( 'OLD-' . $f[0] === $subs->retrieve( $f[1] )['subscription_id'], 'late-approved redirect leaves the scheduled switch to cron' );
check_cancel_timing( 'pending' === $orders->retrieve( $f[3]['ID'] )['status'], 'late approval alone never completes an unpaid order' );
$_GET = array();

// Isolate cron selection to a single fixture while exercising the real SQL query.
function run_fixture_cron( $f ) {
 $repository = new class( $f[3]['ID'] ) extends OrdersRepository {
  private $id;
  public function __construct( $id ) { parent::__construct(); $this->id = (int) $id; }
  public function get_all_delayed_orders( $date ) {
   return array_filter( parent::get_all_delayed_orders( $date ), function ( $row ) { return (int) $row['order_id'] === $this->id; } );
  }
 };
 $cron = new WPEverest\URMembership\Admin\Services\SubscriptionService();
 $property = new ReflectionProperty( $cron, 'orders_repository' );
 $property->setAccessible( true ); $property->setValue( $cron, $repository );
 $cron->run_daily_delayed_membership_subscriptions();
}
foreach ( array( 'due-today', 'unpaid', 'lookup-error', 'cancel-error', 'missing-marker', 'superseded-during-lookup' ) as $case ) {
 $f = cancel_fixture();
 $due = gmdate( 'Y-m-d H:i:s', time() - ( in_array( $case, array( 'due-today', 'unpaid' ), true ) ? 60 : 2 * DAY_IN_SECONDS ) );
 $next = json_decode( get_user_meta( $f[0], 'urm_next_subscription_data', true ), true );
 $next['delayed_until'] = $due;
 update_user_meta( $f[0], 'urm_next_subscription_data', wp_json_encode( $next ) );
 $wpdb->update( $wpdb->prefix . 'ur_membership_ordermeta', array( 'meta_value' => $due ), array( 'order_id' => $f[3]['ID'], 'meta_key' => 'delayed_until' ) );
 $remote['NEW-' . $f[0]]['start_time'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 );
 $remote['NEW-' . $f[0]]['billing_info'] = array( 'next_billing_time' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ), 'last_payment' => array( 'time' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'amount' => array( 'value' => '10.00' ) ) );
 if ( 'unpaid' === $case ) { unset( $remote['NEW-' . $f[0]]['billing_info']['last_payment'] ); }
 if ( 'lookup-error' === $case ) { $remote['OLD-' . $f[0]] = new WP_Error( 'fixture_timeout', 'Cannot verify outgoing agreement' ); }
 if ( 'cancel-error' === $case ) { $cancel_error = true; }
 if ( 'missing-marker' === $case ) { delete_user_meta( $f[0], NewPaypalService::SCHEDULED_SUBSCRIPTION_META_PREFIX . $f[1] ); }
 if ( 'superseded-during-lookup' === $case ) {
  $reads = 0;
  $on_read = function ( $id ) use ( $f, $next, &$reads ) {
   global $wpdb;
   if ( 'NEW-' . $f[0] !== $id || ++$reads < 2 ) { return; }
   $next['order_id'] += 1000;
   // Simulate a different process: its write cannot invalidate this request's cache.
   $wpdb->update( $wpdb->usermeta, array( 'meta_value' => wp_json_encode( $next ) ), array( 'user_id' => $f[0], 'meta_key' => 'urm_next_subscription_data' ) );
  };
 }
 run_fixture_cron( $f );
 $on_read = null;
 $actual = $subs->retrieve( $f[1] );
 if ( in_array( $case, array( 'due-today', 'unpaid' ), true ) ) {
  check_cancel_timing( 'NEW-' . $f[0] === $actual['subscription_id'], $case . ': due timestamp switches gateway ID today' );
  check_cancel_timing( ( 'unpaid' === $case ? 'pending' : 'active' ) === $actual['status'], $case . ': access depends on payment, not approval' );
  if ( 'unpaid' === $case ) {
   subscription_event( $f, 'ACTIVATED', 'NEW-' . $f[0] );
   check_cancel_timing( 'pending' === $subs->retrieve( $f[1] )['status'], 'late ACTIVATED webhook cannot activate unpaid replacement' );
   check_cancel_timing( 'pending' === $orders->retrieve( $f[3]['ID'] )['status'], 'late ACTIVATED webhook cannot complete unpaid order' );
   $events = array( array( 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'create_time' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'resource' => array( 'id' => 'NEW-' . $f[0], 'status' => 'ACTIVE' ) ) );
   $service->run_missed_subscription_backfill( time() - HOUR_IN_SECONDS, time() );
   check_cancel_timing( 'pending' === $subs->retrieve( $f[1] )['status'], 'status backfill cannot activate unpaid replacement' );
   $events = array();
   $remote['NEW-' . $f[0]]['billing_info']['last_payment'] = array( 'time' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'amount' => array( 'value' => '10.00' ) );
   $sale = array( 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => array( 'id' => 'SALE-' . $f[0], 'billing_agreement_id' => 'NEW-' . $f[0], 'state' => 'completed', 'amount' => array( 'total' => '10.00', 'currency' => 'USD' ), 'create_time' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
   check_cancel_timing( true === $service->handle_webhook_event( $sale ), 'first successful sale recovers pending replacement' );
   check_cancel_timing( 'active' === $subs->retrieve( $f[1] )['status'], 'paid replacement becomes active' );
   check_cancel_timing( 'completed' === $orders->retrieve( $f[3]['ID'] )['status'], 'first sale completes the scheduled order' );
   $paid_row = $subs->retrieve( $f[1] );
   $service->handle_webhook_event( $sale );
   check_cancel_timing( $paid_row === $subs->retrieve( $f[1] ), 'duplicate first sale never adds another period' );
  }
 } else {
  check_cancel_timing( $f[2] === $actual, $case . ': cron leaves local subscription unchanged' );
  check_cancel_timing( '' !== get_user_meta( $f[0], 'urm_next_subscription_data', true ), $case . ': cron retains retry state' );
 }
}

$f = cancel_fixture();
$competing_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$row_lock = NewPaypalService::SUBSCRIPTION_ROW_LOCK_PREFIX . $f[1];
$held = $competing_connection->get_var( $competing_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $row_lock ) );
check_cancel_timing( '1' === (string) $held, 'competing database connection acquires the row lock' );
try {
 $result = ( new \WPEverest\URMembership\Admin\Services\SubscriptionService() )->upgrade_membership( array( 'current_subscription_id' => $f[1] ) );
 check_cancel_timing( false === $result['response']['status'], 'checkout defers while another database connection holds the cron row lock' );
 check_cancel_timing( $f[2] === $subs->retrieve( $f[1] ), 'blocked checkout does not alter the subscription' );
} finally {
 $competing_connection->get_var( $competing_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $row_lock ) );
 $competing_connection->close();
}

printf( "\n%d/%d passed\n", $total - $failures, $total );
exit( $failures ? 1 : 0 );
