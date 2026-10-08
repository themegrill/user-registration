<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: the Stripe payment backfill recovers paid renewals on every API version, and a failed
 * invoice that Stripe will retry does not take an active member's access away.
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.DateTime.RestrictedFunctions.date_date, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.WP.AlternativeFunctions.json_encode_json_encode
namespace Stripe {
	/**
	 * Stands in for the SDK's StripeObject; camelCase SDK methods are served through __call.
	 */
	class StripeObject {
		public $created = 1759300000;
		private $values;
		public function __construct( $values ) {
			$this->values = $values;
		}
		public function __get( $name ) {
			return $this->values[ $name ] ?? null;
		}
		public function __isset( $name ) {
			return isset( $this->values[ $name ] );
		}
		/**
		 * Serves `toArray()` (an invoice) and `autoPagingIterator()` (an event list).
		 *
		 * @param string $name      Called method name.
		 * @param array  $arguments Call arguments.
		 * @return array
		 */
		public function __call( $name, $arguments ) {
			security_assert( in_array( $name, array( 'toArray', 'autoPagingIterator' ), true ), 'Unexpected SDK method ' . $name );
			return $this->values;
		}
	}
	class Event {
		public static function all( $params ) {
			return new StripeObject(
				array_map(
					function ( $invoice ) {
						return (object) array( 'data' => (object) array( 'object' => new StripeObject( $invoice ) ) );
					},
					$GLOBALS['stripe_invoices']
				)
			);
		}
	}
	class Invoice {
		public static function retrieve( $params ) {
			$GLOBALS['invoice_lookups'][] = $params['id'];
			if ( $GLOBALS['stripe_throws'] ) {
				throw new \Exception( 'No such invoice' );
			}
			return new StripeObject( array( 'payments' => array( 'data' => $GLOBALS['stripe_payments'] ) ) );
		}
	}
	class PaymentIntent {
		public static function retrieve( $id ) {
			return (object) array( 'amount_received' => 2900 );
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	class PaymentGatewayLogging {
		public static function log_error( $gateway, $message ) {
			$GLOBALS['logged_errors'][] = $message;
		}
		public static function log_webhook_received( $gateway, $message, $context = array() ) {}
		public static function log_webhook_processed( $gateway, $message, $context = array() ) {
			$GLOBALS['processed_logs'][] = $message;
		}
	}
	class SecurityLogger {
		public function info( $message, $context = array() ) {}
		public function error( $message, $context = array() ) {
			$GLOBALS['logged_errors'][] = $message;
		}
	}
	class SecurityRepository {
		public $calls = array();
		public $row;
		public function __call( $name, $arguments ) {
			$this->calls[] = array( $name, $arguments );
			return $this->row;
		}
	}
	function ur_get_logger() {
		return new SecurityLogger();
	}
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
	function wp_die() {
		throw new SecurityResponse( false, 'wp_die', 500 );
	}
	function get_user_meta( $user_id, $key, $single = false ) {
		return '';
	}
	function delete_transient( $key ) {}

	$stripe_service = 'modules/membership/includes/Admin/Services/Stripe/StripeService.php';
	$methods        = '';
	foreach ( array( 'extract_stripe_id', 'get_invoice_payment_intent_id', 'run_missed_payment_backfill', 'handle_failed_invoice', 'handle_succeeded_invoice' ) as $method ) {
		$methods .= security_function( $stripe_service, $method );
	}
	eval( 'class StripeBackfillHarness { public $members_subscription_repository; public $orders_repository; ' . $methods . '}' );

	$harness            = function ( $subscription_row, $order_row = false ) {
		$GLOBALS['invoice_lookups']                    = array();
		$GLOBALS['logged_errors']                      = array();
		$GLOBALS['processed_logs']                     = array();
		$GLOBALS['stripe_throws']                      = false;
		$GLOBALS['stripe_payments']                    = array();
		$service                                       = new StripeBackfillHarness();
		$service->members_subscription_repository      = new SecurityRepository();
		$service->members_subscription_repository->row = $subscription_row;
		$service->orders_repository                    = new SecurityRepository();
		$service->orders_repository->row               = $order_row;
		return $service;
	};
	$method_names       = function ( $repository ) {
		return array_column( $repository->calls, 0 );
	};
	$created_orders     = function ( $service ) {
		return array_values(
			array_filter(
				$service->orders_repository->calls,
				function ( $call ) {
					return 'create' === $call[0];
				}
			)
		);
	};
	$paid_by_pi         = array(
		array(
			'status'  => 'paid',
			'payment' => array(
				'type'           => 'payment_intent',
				'payment_intent' => 'pi_basil',
			),
		),
	);
	$local_subscription = array(
		'ID'      => 7,
		'user_id' => 76,
		'item_id' => 12,
		'sub_id'  => 7,
		'status'  => 'active',
	);

	// 1. Basil-or-later invoice: subscription under parent.subscription_details, payment under payments.
	$GLOBALS['stripe_invoices'] = array(
		array(
			'id'          => 'in_basil',
			'amount_paid' => 2900,
			'parent'      => array( 'subscription_details' => array( 'subscription' => 'sub_basil' ) ),
		),
	);
	$service                    = $harness( $local_subscription );
	$GLOBALS['stripe_payments'] = $paid_by_pi;
	$service->run_missed_payment_backfill( 1759000000 );
	security_assert( array( 'get_subscription_by_subscription_id_meta', 'sub_basil' ) === array( $service->members_subscription_repository->calls[0][0], $service->members_subscription_repository->calls[0][1][0] ), 'The subscription is read from parent.subscription_details' );
	$orders = $created_orders( $service );
	security_assert( 1 === count( $orders ), 'A paid basil renewal is backfilled as an order' );
	security_assert( 'pi_basil' === $orders[0][1][0]['orders_data']['transaction_id'], 'The order carries the PaymentIntent from invoice.payments' );
	security_assert( 29.0 === (float) $orders[0][1][0]['orders_data']['total_amount'], 'The order total comes from the PaymentIntent' );

	// 2. Pre-basil invoice keeps working and needs no extra invoice lookup.
	$GLOBALS['stripe_invoices'] = array(
		array(
			'id'             => 'in_legacy',
			'subscription'   => 'sub_legacy',
			'payment_intent' => 'pi_legacy',
		),
	);
	$service                    = $harness( $local_subscription );
	$service->run_missed_payment_backfill( 1759000000 );
	security_assert( 'pi_legacy' === $created_orders( $service )[0][1][0]['orders_data']['transaction_id'], 'A legacy invoice is backfilled from its own payment_intent' );
	security_assert( array() === $GLOBALS['invoice_lookups'], 'No invoice lookup when payment_intent is on the invoice' );

	// 3. An invoice for a subscription this site does not own costs no Stripe lookup.
	$GLOBALS['stripe_invoices'] = array(
		array(
			'id'     => 'in_foreign',
			'parent' => array( 'subscription_details' => array( 'subscription' => 'sub_foreign' ) ),
		),
	);
	$service                    = $harness( false );
	$service->run_missed_payment_backfill( 1759000000 );
	security_assert( array() === $GLOBALS['invoice_lookups'], 'Foreign subscriptions are skipped before any invoice lookup' );
	security_assert( array() === $created_orders( $service ), 'No order for a foreign subscription' );

	// 4. A failed lookup skips that invoice only and the next invoice is still recovered.
	$GLOBALS['stripe_invoices'] = array(
		array(
			'id'     => 'in_unreachable',
			'parent' => array( 'subscription_details' => array( 'subscription' => 'sub_basil' ) ),
		),
		array(
			'id'             => 'in_next',
			'subscription'   => 'sub_basil',
			'payment_intent' => 'pi_next',
		),
	);
	$service                    = $harness( $local_subscription );
	$GLOBALS['stripe_throws']   = true;
	$service->run_missed_payment_backfill( 1759000000 );
	security_assert( 1 === count( $GLOBALS['logged_errors'] ) && false !== strpos( $GLOBALS['logged_errors'][0], 'in_unreachable' ), 'A failed lookup is logged with its invoice' );
	security_assert( 'pi_next' === $created_orders( $service )[0][1][0]['orders_data']['transaction_id'], 'The backfill continues after a failed lookup' );

	// 5. A $0 invoice (no payments) still creates no order.
	$GLOBALS['stripe_invoices'] = array(
		array(
			'id'     => 'in_trial',
			'parent' => array( 'subscription_details' => array( 'subscription' => 'sub_basil' ) ),
		),
	);
	$service                    = $harness( $local_subscription );
	$service->run_missed_payment_backfill( 1759000000 );
	security_assert( array() === $created_orders( $service ), 'No order for an invoice without a PaymentIntent' );

	// 6. The webhook turns a failed lookup into a logged error response so Stripe retries the event.
	$service                  = $harness( $local_subscription );
	$GLOBALS['stripe_throws'] = true;
	$response                 = null;
	try {
		$service->handle_succeeded_invoice( array( 'data' => array( 'object' => array( 'id' => 'in_webhook' ) ) ), 'sub_basil' );
	} catch ( SecurityResponse $e ) {
		$response = $e;
	}
	security_assert( null !== $response && 500 === $response->status, 'A failed lookup aborts the webhook with an error status' );
	security_assert( 1 === count( $GLOBALS['logged_errors'] ) && false !== strpos( $GLOBALS['logged_errors'][0], 'INVOICE_PAYMENT_LOOKUP_FAILED' ), 'The webhook logs the failed lookup' );

	$failed_invoice = function ( $next_payment_attempt ) {
		return array(
			'id'   => 'evt_failed',
			'data' => array(
				'object' => array(
					'id'                   => 'in_failed',
					'next_payment_attempt' => $next_payment_attempt,
				),
			),
		);
	};
	$status_writes  = function ( $service ) {
		return array_values(
			array_filter(
				$service->members_subscription_repository->calls,
				function ( $call ) {
					return 'update' === $call[0];
				}
			)
		);
	};

	// 7. Stripe will retry: an active or trial member keeps the status.
	foreach ( array( 'active', 'trial' ) as $status ) {
		$service = $harness( array_merge( $local_subscription, array( 'status' => $status ) ) );
		$service->handle_failed_invoice( $failed_invoice( 1759900000 ), 'sub_basil' );
		security_assert( array() === $status_writes( $service ), 'A retried failure keeps the ' . $status . ' status' );
		security_assert( 1 === count( $GLOBALS['processed_logs'] ), 'The kept status is logged' );
	}

	// 8. Stripe has stopped retrying: the subscription goes back to pending as before.
	$service = $harness( $local_subscription );
	$service->handle_failed_invoice( $failed_invoice( null ), 'sub_basil' );
	$writes = $status_writes( $service );
	security_assert( 1 === count( $writes ) && array( 'status' => 'pending' ) === $writes[0][1][1], 'A final failure sets the subscription to pending' );

	// 9. A first payment that is still pending stays pending while Stripe retries.
	$service = $harness( array_merge( $local_subscription, array( 'status' => 'pending' ) ) );
	$service->handle_failed_invoice( $failed_invoice( 1759900000 ), 'sub_basil' );
	security_assert( array( 'status' => 'pending' ) === $status_writes( $service )[0][1][1], 'A pending subscription is still written as pending' );
}
