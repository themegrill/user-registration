<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: a paid Stripe renewal must resolve its PaymentIntent on every Stripe API version.
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed
namespace Stripe {
	class Invoice {
		public static function retrieve( $params ) {
			$GLOBALS['retrieve_calls'][] = $params;
			if ( $GLOBALS['stripe_throws'] ) {
				throw new \Exception( 'No such invoice' );
			}
			return new self();
		}
		/**
		 * Stands in for the SDK's camelCase `toArray()`, which cannot be renamed.
		 *
		 * @param string $name      Called method name.
		 * @param array  $arguments Call arguments.
		 * @return array
		 */
		public function __call( $name, $arguments ) {
			security_assert( 'toArray' === $name, 'Only toArray() is expected on the Invoice double' );
			return array( 'payments' => array( 'data' => $GLOBALS['stripe_payments'] ) );
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	$stripe_service = 'modules/membership/includes/Admin/Services/Stripe/StripeService.php';
	eval( 'class StripeInvoiceResolver {' . security_function( $stripe_service, 'extract_stripe_id' ) . security_function( $stripe_service, 'get_invoice_payment_intent_id' ) . '}' );
	$resolver = new ReflectionMethod( 'StripeInvoiceResolver', 'get_invoice_payment_intent_id' );
	$resolver->setAccessible( true );
	$resolve = function ( $invoice, $payments = array(), $throws = false ) use ( $resolver ) {
		$GLOBALS['retrieve_calls']  = array();
		$GLOBALS['stripe_payments'] = $payments;
		$GLOBALS['stripe_throws']   = $throws;
		return $resolver->invoke( new StripeInvoiceResolver(), $invoice );
	};

	// Pre-basil event: the field is on the invoice, so no extra API call is needed.
	security_assert(
		'pi_legacy' === $resolve(
			array(
				'id'             => 'in_1',
				'payment_intent' => 'pi_legacy',
			)
		),
		'Legacy invoice payment_intent is used as is'
	);
	security_assert( array() === $GLOBALS['retrieve_calls'], 'No API call when the invoice already carries payment_intent' );

	// An expanded payment_intent object resolves to its id without an API call.
	security_assert(
		'pi_expanded' === $resolve(
			array(
				'id'             => 'in_x',
				'payment_intent' => array( 'id' => 'pi_expanded' ),
			)
		),
		'Expanded payment_intent object resolves to its id'
	);
	security_assert( array() === $GLOBALS['retrieve_calls'], 'No API call for an expanded payment_intent' );

	// Basil-or-later event: the field is gone, the paid payment lists the PaymentIntent.
	$paid = array(
		array(
			'status'  => 'paid',
			'payment' => array(
				'type'           => 'payment_intent',
				'payment_intent' => 'pi_basil',
			),
		),
	);
	security_assert( 'pi_basil' === $resolve( array( 'id' => 'in_2' ), $paid ), 'PaymentIntent is read from invoice.payments' );
	security_assert(
		array(
			'id'     => 'in_2',
			'expand' => array( 'payments' ),
		) === $GLOBALS['retrieve_calls'][0],
		'payments is expanded on the invoice fetch'
	);

	// An open (unpaid) attempt is not a payment.
	$open = array(
		array(
			'status'  => 'open',
			'payment' => array( 'payment_intent' => 'pi_open' ),
		),
	);
	security_assert( '' === $resolve( array( 'id' => 'in_3' ), $open ), 'Unpaid payment attempts are ignored' );

	// The paid payment wins when an unpaid attempt is listed first.
	security_assert( 'pi_basil' === $resolve( array( 'id' => 'in_4' ), array_merge( $open, $paid ) ), 'Paid payment is chosen over an open one' );

	// $0 trial invoice: no payments at all.
	security_assert( '' === $resolve( array( 'id' => 'in_5' ), array() ), 'A $0 invoice has no PaymentIntent' );

	// Out-of-band payment: paid but not through a PaymentIntent.
	$oob = array(
		array(
			'status'  => 'paid',
			'payment' => array(
				'type'   => 'charge',
				'charge' => 'ch_1',
			),
		),
	);
	security_assert( '' === $resolve( array( 'id' => 'in_6' ), $oob ), 'A non-PaymentIntent payment yields no PaymentIntent' );

	// Lookup failure must reach the caller, never be mistaken for a $0 invoice.
	$failed = null;
	try {
		$resolve( array( 'id' => 'in_7' ), array(), true );
	} catch ( \Exception $e ) {
		$failed = $e;
	}
	security_assert( null !== $failed && 'No such invoice' === $failed->getMessage(), 'A failed lookup throws to the caller' );
}
