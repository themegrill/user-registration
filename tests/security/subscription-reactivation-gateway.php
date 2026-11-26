<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: reactivating a canceled subscription fails, and leaves the record untouched, when the
 * payment gateway refuses the reactivation, whatever the historical order status says.
 *
 * Runs the real `SubscriptionRepository::reactivate_subscription_by_id()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

require __DIR__ . '/bootstrap.php';

$GLOBALS['gateway_result'] = array();

function esc_html__( $text ) {
	return $text; }
function get_user_meta() {
	return ''; }

class SubscriptionService {
	public function reactivate_subscription() {
		return $GLOBALS['gateway_result']; }
}

class OrdersDouble {
	public $status = 'completed';

	public function get_order_by_subscription() {
		return array(
			'payment_method' => 'stripe',
			'status'         => $this->status,
		); }
}

eval(
	'class RepositoryRunner { public $orders_repository; public $updates = array(); '
	. 'public function retrieve() { return array( "status" => "canceled", "user_id" => 7 ); } '
	. 'public function update( $id, $data ) { $this->updates[] = $data; return true; } public '
	. security_function( 'modules/membership/includes/Admin/Repositories/SubscriptionRepository.php', 'reactivate_subscription_by_id' )
	. ' }'
);

function run_reactivation( $gateway_result, $order_status ) {
	$GLOBALS['gateway_result']         = $gateway_result;
	$runner                            = new RepositoryRunner();
	$runner->orders_repository         = new OrdersDouble();
	$runner->orders_repository->status = $order_status;

	return array( $runner->reactivate_subscription_by_id( 5 ), $runner->updates );
}

list( $result, $updates ) = run_reactivation( array( 'status' => false ), 'completed' );
security_assert( false === $result['status'], 'A gateway refusal fails the reactivation despite a completed order' );
security_assert( array() === $updates, 'A gateway refusal leaves the subscription record untouched' );

list( $result, $updates ) = run_reactivation( array(), 'completed' );
security_assert( false === $result['status'] && array() === $updates, 'An empty gateway response is treated as a refusal' );

list( $result, $updates ) = run_reactivation(
	array(
		'status'  => false,
		'message' => 'Not reactivable',
	),
	'completed'
);
security_assert( 'Not reactivable' === $result['message'], 'The gateway message is passed back' );

list( $result, $updates ) = run_reactivation( array( 'status' => true ), 'completed' );
security_assert( true === $result['status'] && 'active' === $updates[0]['status'], 'A confirmed reactivation with a completed order becomes active' );

list( $result, $updates ) = run_reactivation( array( 'status' => true ), 'pending' );
security_assert( true === $result['status'] && 'pending' === $updates[0]['status'], 'A confirmed reactivation with a pending order stays pending' );
