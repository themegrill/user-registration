<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: a succeeded PaymentIntent that paid less than the order total, or in another currency, must not complete the order.
 *
 * Runs the real `StripeService::is_payment_intent_covering_order()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

require __DIR__ . '/bootstrap.php';

$stripe_service = 'modules/membership/includes/Admin/Services/Stripe/StripeService.php';
eval(
	'class StripeAmountChecker {'
	. 'const PAYMENT_AMOUNT_TOLERANCE = 1;'
	. 'public $orders_repository;'
	. security_function( $stripe_service, 'calculate_stripe_amount' )
	. security_function( $stripe_service, 'is_payment_intent_covering_order' )
	. 'public function check( $intent, $fixture_order ) { return $this->is_payment_intent_covering_order( $intent, $fixture_order ); } }'
);

$checker                    = new StripeAmountChecker();
$checker->orders_repository = new class() {
	public function get_order_meta_by_order_id_and_meta_key( $order_id, $meta_key ) {
		return array(); }
};

$intent        = function ( $amount, $currency = 'usd' ) {
	return (object) array(
		'amount'   => $amount,
		'currency' => $currency,
	);
};
$fixture_order = array(
	'ID'           => 1,
	'total_amount' => '50.00',
);

// The reported exploit: a $0.50 PaymentIntent against a $50.00 order.
security_assert( false === $checker->check( $intent( 50 ), $fixture_order ), 'A $0.50 intent must not cover a $50.00 order' );
security_assert( true === $checker->check( $intent( 5000 ), $fixture_order ), 'An intent equal to the order total covers it' );
security_assert( true === $checker->check( $intent( 5001 ), $fixture_order ), 'An overpayment is not an underpayment attack' );
security_assert( true === $checker->check( $intent( 4999 ), $fixture_order ), 'One minor unit of tax rounding is tolerated' );
security_assert( false === $checker->check( $intent( 4998 ), $fixture_order ), 'Two minor units short is rejected' );
security_assert( false === $checker->check( $intent( 5000, 'eur' ), $fixture_order ), 'A different currency is rejected' );
security_assert( true === $checker->check( $intent( 0 ), array_merge( $fixture_order, array( 'total_amount' => '0.00' ) ) ), 'A zero-total order (100% coupon) needs no payment' );
security_assert( true === $checker->check( $intent( 5000, 'USD' ), $fixture_order ), 'Currency comparison ignores case' );

$checker->orders_repository = new class() {
	public function get_order_meta_by_order_id_and_meta_key( $order_id, $meta_key ) {
		return array( 'meta_value' => 'NPR' ); }
};
security_assert( true === $checker->check( $intent( 1300, 'npr' ), $fixture_order ), 'A local-currency order is not compared against the base-currency total' );
