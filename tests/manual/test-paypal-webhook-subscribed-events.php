<?php
// phpcs:ignoreFile -- Standalone integration regression runner.
/** Real WordPress services, HTTP-only stubs. Run: wp eval-file <this file>. */
if ( 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) {
 define( 'WP_USE_THEMES', false );
 require_once dirname( __DIR__, 5 ) . '/wp-load.php';
}
use WPEverest\URMembership\Admin\Services\Paypal\NewPaypalService;
global $failures, $total, $service, $create_payload;
$failures = 0; $total = 0;
function check_subscribed_events( $condition, $label ) {
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
$create_payload = null;
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$create_payload ) {
 $response = function ( $data, $code = 200 ) { return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $data ) ); };
 if ( false !== strpos( $url, '/v1/oauth2/token' ) ) { return $response( array( 'access_token' => 'fixture-token' ) ); }
 if ( false !== strpos( $url, '/v1/notifications/webhooks' ) && 'GET' === $args['method'] ) { return $response( array( 'webhooks' => array() ) ); }
 if ( false !== strpos( $url, '/v1/notifications/webhooks' ) && 'POST' === $args['method'] ) {
  $create_payload = json_decode( $args['body'], true );
  return $response( array( 'id' => 'WH-FIXTURE' ) );
 }
 return new WP_Error( 'unexpected_http', 'Blocked unexpected request: ' . $url );
}, 10, 3 );
$service = new NewPaypalService();
$result  = $service->register_or_update_webhook( array( 'mode' => 'test', 'client_id' => 'x', 'secret_key' => 'y' ) );
check_subscribed_events( 'WH-FIXTURE' === $result, 'webhook registration succeeds' );
$subscribed = is_array( $create_payload ) && isset( $create_payload['event_types'] ) ? array_column( $create_payload['event_types'], 'name' ) : array();
check_subscribed_events( in_array( 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', $subscribed, true ), 'subscribes to BILLING.SUBSCRIPTION.PAYMENT.FAILED so failed renewals are reported in real time' );
check_subscribed_events( in_array( 'BILLING.SUBSCRIPTION.RE-ACTIVATED', $subscribed, true ), 'subscribes to BILLING.SUBSCRIPTION.RE-ACTIVATED' );
printf( "\n%d/%d checks passed.\n", $total - $failures, $total );
if ( $failures ) { exit( 1 ); }
