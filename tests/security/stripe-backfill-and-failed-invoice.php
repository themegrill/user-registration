<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: the Stripe payment backfill recovers paid renewals on every API version, and a failed
 * invoice that Stripe will retry does not take an active member's access away.
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.WP.AlternativeFunctions.json_encode_json_encode
namespace Stripe {
	/**
	 * Stands in for the SDK's StripeObject; camelCase SDK methods are served through __call.
	 */
	class StripeObject {
		private $values;
		public function __construct( $values ) {
			$this->values = $values;
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
						return (object) array( 'data' => (object) array( 'object' => new StripeObject( $invoice + array( 'created' => 1759300000 ) ) ) );
					},
					$GLOBALS['stripe_invoices']
				)
			);
		}
		public static function retrieve( $id ) {
			return $GLOBALS['stripe_event'];
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
		public function warning( $message, $context = array() ) {
			$GLOBALS['logged_warnings'][] = $message;
		}
	}
	/**
	 * Scheduler-side double: records the window each Stripe backfill receives and fails on request.
	 */
	class StripeService {
		public static function get_stripe_settings() {
			return array( 'secret_key' => 'sk_test_double' );
		}
		public function __call( $name, $arguments ) {
			$GLOBALS['backfill_windows'][ $name ] = $arguments[0];
			if ( 'run_missed_payment_backfill' === $name && 'throw' === $GLOBALS['stripe_outcome'] ) {
				throw new \Exception( 'Stripe API error' );
			}
		}
		public function has_backfill_failure() {
			return 'skipped' === $GLOBALS['stripe_outcome'];
		}
	}
	/**
	 * Records every repository call and answers each method from $returns (false when unset).
	 */
	class SecurityRepository {
		public $calls = array();
		public $returns;
		public function __construct( $returns ) {
			$this->returns = $returns;
		}
		public function __call( $name, $arguments ) {
			$this->calls[] = array( $name, $arguments );
			return $this->returns[ $name ] ?? false;
		}
		public function calls_to( $name ) {
			return array_values(
				array_filter(
					$this->calls,
					function ( $call ) use ( $name ) {
						return $name === $call[0];
					}
				)
			);
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
	foreach ( array( 'extract_stripe_id', 'get_invoice_payment_intent_id', 'run_missed_payment_backfill', 'handle_failed_invoice', 'handle_succeeded_invoice', 'has_backfill_failure' ) as $method ) {
		$methods .= security_function( $stripe_service, $method );
	}
	eval( 'class StripeBackfillHarness { public $members_subscription_repository; public $orders_repository; private $backfill_failed = false; ' . $methods . '}' );

	$local_subscription = array(
		'ID'      => 7,
		'user_id' => 76,
		'item_id' => 12,
		'sub_id'  => 7,
		'status'  => 'active',
	);
	$harness            = function ( $subscription_row, $order_returns = array() ) {
		$GLOBALS['invoice_lookups']               = array();
		$GLOBALS['logged_errors']                 = array();
		$GLOBALS['processed_logs']                = array();
		$GLOBALS['stripe_throws']                 = false;
		$GLOBALS['stripe_payments']               = array();
		$service                                  = new StripeBackfillHarness();
		$service->members_subscription_repository = new SecurityRepository(
			array(
				'get_subscription_by_subscription_id_meta' => $subscription_row,
				'get_membership_by_subscription_id'        => $subscription_row,
				'retrieve'                                 => $subscription_row,
			)
		);
		$service->orders_repository               = new SecurityRepository(
			$order_returns + array(
				'create' => array( 'ID' => 99 ),
				'update' => 1,
			)
		);
		return $service;
	};
	$backfill           = function ( $invoices, $subscription_row, $payments = array(), $order_returns = array() ) use ( $harness ) {
		$GLOBALS['stripe_invoices'] = $invoices;
		$service                    = $harness( $subscription_row, $order_returns );
		$GLOBALS['stripe_payments'] = $payments;
		$service->run_missed_payment_backfill( 1759000000 );
		return $service;
	};
	$transaction_ids    = function ( $service ) {
		return array_map(
			function ( $call ) {
				return $call[1][0]['orders_data']['transaction_id'];
			},
			$service->orders_repository->calls_to( 'create' )
		);
	};
	$basil_invoice      = function ( $id ) {
		return array(
			'id'     => $id,
			'parent' => array( 'subscription_details' => array( 'subscription' => 'sub_basil' ) ),
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

	// 1. Basil-or-later invoice: subscription under parent.subscription_details, payment under payments.
	$service = $backfill( array( $basil_invoice( 'in_basil' ) ), $local_subscription, $paid_by_pi );
	security_assert( array( 'sub_basil' ) === $service->members_subscription_repository->calls_to( 'get_subscription_by_subscription_id_meta' )[0][1], 'The subscription is read from parent.subscription_details' );
	security_assert( array( 'pi_basil' ) === $transaction_ids( $service ), 'A paid basil renewal is backfilled as an order with its PaymentIntent' );
	security_assert( 29.0 === (float) $service->orders_repository->calls_to( 'create' )[0][1][0]['orders_data']['total_amount'], 'The order total comes from the PaymentIntent' );

	// 2. invoice.paid and invoice.payment_succeeded list the same invoice: one lookup, one order.
	$service = $backfill( array( $basil_invoice( 'in_twice' ), $basil_invoice( 'in_twice' ) ), $local_subscription, $paid_by_pi );
	security_assert( array( 'in_twice' ) === $GLOBALS['invoice_lookups'], 'An invoice listed by two events is looked up once' );
	security_assert( 1 === count( $transaction_ids( $service ) ), 'An invoice listed by two events creates one order' );

	// 3. Pre-basil invoice keeps working and needs no extra invoice lookup.
	$legacy  = array(
		'id'             => 'in_legacy',
		'subscription'   => 'sub_legacy',
		'payment_intent' => 'pi_legacy',
	);
	$service = $backfill( array( $legacy ), $local_subscription );
	security_assert( array( 'pi_legacy' ) === $transaction_ids( $service ), 'A legacy invoice is backfilled from its own payment_intent' );
	security_assert( array() === $GLOBALS['invoice_lookups'], 'No invoice lookup when payment_intent is on the invoice' );

	// 4. An invoice for a subscription this site does not own costs no Stripe lookup.
	$service = $backfill( array( $basil_invoice( 'in_foreign' ) ), false, $paid_by_pi );
	security_assert( array() === $GLOBALS['invoice_lookups'], 'Foreign subscriptions are skipped before any invoice lookup' );
	security_assert( array() === $transaction_ids( $service ), 'No order for a foreign subscription' );

	// 5. The signup invoice is linked to a registration order saved without a PaymentIntent, not duplicated.
	$unlinked = array( 'get_unlinked_order_by_subscription' => array( 'ID' => 41 ) );
	$service  = $backfill( array( $basil_invoice( 'in_signup' ) + array( 'billing_reason' => 'subscription_create' ) ), $local_subscription, $paid_by_pi, $unlinked );
	security_assert( array( 7 ) === $service->orders_repository->calls_to( 'get_unlinked_order_by_subscription' )[0][1], 'The unlinked order is looked up on the local subscription' );
	security_assert( array() === $transaction_ids( $service ), 'No duplicate order next to the unlinked registration order' );
	$updates = $service->orders_repository->calls_to( 'update' );
	security_assert(
		1 === count( $updates ) && array(
			41,
			array(
				'transaction_id' => 'pi_basil',
				'status'         => 'completed',
			),
		) === $updates[0][1],
		'The unlinked registration order receives the PaymentIntent'
	);

	// 6. A renewal invoice is never linked to an unlinked order; it gets its own order.
	$service = $backfill( array( $basil_invoice( 'in_renewal' ) + array( 'billing_reason' => 'subscription_cycle' ) ), $local_subscription, $paid_by_pi, $unlinked );
	security_assert( array( 'pi_basil' ) === $transaction_ids( $service ), 'A renewal gets its own order' );
	security_assert( array() === $service->orders_repository->calls_to( 'update' ), 'A renewal does not overwrite an unlinked order' );

	// 7. A failed lookup skips that invoice only and the next invoice is still recovered.
	$GLOBALS['stripe_invoices'] = array( $basil_invoice( 'in_unreachable' ), array_merge( $legacy, array( 'subscription' => 'sub_basil' ) ) );
	$service                    = $harness( $local_subscription );
	$GLOBALS['stripe_throws']   = true;
	$service->run_missed_payment_backfill( 1759000000 );
	security_assert( 1 === count( $GLOBALS['logged_errors'] ) && false !== strpos( $GLOBALS['logged_errors'][0], 'in_unreachable' ), 'A failed lookup is logged with its invoice' );
	security_assert( array( 'pi_legacy' ) === $transaction_ids( $service ), 'The backfill continues after a failed lookup' );
	security_assert( $service->has_backfill_failure(), 'A failed lookup flags the run so the scheduler keeps the window' );

	// 7b. An order that cannot be saved, or linked, flags the run so the window is searched again.
	$service = $backfill( array( $basil_invoice( 'in_db_down' ) ), $local_subscription, $paid_by_pi, array( 'create' => array() ) );
	security_assert( $service->has_backfill_failure() && false !== strpos( $GLOBALS['logged_errors'][0] ?? '', 'Could not save the order' ), 'A failed order write flags the run' );
	$service = $backfill( array( $basil_invoice( 'in_link_down' ) + array( 'billing_reason' => 'subscription_create' ) ), $local_subscription, $paid_by_pi, $unlinked + array( 'update' => false ) );
	security_assert( $service->has_backfill_failure() && false !== strpos( $GLOBALS['logged_errors'][0] ?? '', 'Could not link' ), 'A failed order link flags the run' );

	// 8. A $0 invoice (no payments) still creates no order.
	$service = $backfill( array( $basil_invoice( 'in_trial' ) ), $local_subscription );
	security_assert( array() === $transaction_ids( $service ), 'No order for an invoice without a PaymentIntent' );
	security_assert( ! $service->has_backfill_failure(), 'A run without lookup errors does not flag the run' );

	// 9. The webhook turns a failed lookup into a logged error response so Stripe retries the event.
	$service                  = $harness( $local_subscription );
	$GLOBALS['stripe_throws'] = true;
	$response                 = security_response(
		function () use ( $service ) {
			$service->handle_succeeded_invoice( array( 'data' => array( 'object' => array( 'id' => 'in_webhook' ) ) ), 'sub_basil' );
		}
	);
	security_assert( 500 === $response->status, 'A failed lookup aborts the webhook with an error status' );
	security_assert( 1 === count( $GLOBALS['logged_errors'] ) && false !== strpos( $GLOBALS['logged_errors'][0], 'INVOICE_PAYMENT_LOOKUP_FAILED' ), 'The webhook logs the failed lookup' );

	$failed_invoice = function ( $status, $next_payment_attempt ) use ( $harness, $local_subscription ) {
		$service = $harness( array_merge( $local_subscription, array( 'status' => $status ) ) );
		$service->handle_failed_invoice(
			array(
				'id'   => 'evt_failed',
				'data' => array(
					'object' => array(
						'id'                   => 'in_failed',
						'next_payment_attempt' => $next_payment_attempt,
					),
				),
			),
			'sub_basil'
		);
		return $service->members_subscription_repository->calls_to( 'update' );
	};

	// 10. Stripe will retry: an active member keeps the status, and the hold is logged.
	security_assert( array() === $failed_invoice( 'active', 1759900000 ), 'A retried failure keeps the active status' );
	security_assert( 1 === count( $GLOBALS['processed_logs'] ), 'The kept status is logged' );

	// 11. Every other case still goes to pending: no retry left, an ended trial, or a pending first payment.
	foreach ( array( array( 'active', null ), array( 'trial', 1759900000 ), array( 'pending', 1759900000 ) ) as $case ) {
		$writes = $failed_invoice( $case[0], $case[1] );
		security_assert( 1 === count( $writes ) && array( 'status' => 'pending' ) === $writes[0][1][1], 'A ' . $case[0] . ' subscription goes to pending when next_payment_attempt is ' . wp_json_encode( $case[1] ) );
	}

	// 12. A webhook from an endpoint on a current API version names the subscription only under parent.subscription_details.
	eval( 'class StripeWebhookHarness { public $handled = array(); ' . security_function( $stripe_service, 'handle_webhook' ) . security_function( $stripe_service, 'extract_stripe_id' ) . ' public function handle_failed_invoice( $event, $subscription_id ) { $this->handled[] = $subscription_id; } }' );
	$webhook = function ( $invoice ) {
		$GLOBALS['stripe_event'] = array(
			'id'   => 'evt_1',
			'type' => 'invoice.payment_failed',
			'data' => array( 'object' => $invoice ),
		);
		$service                 = new StripeWebhookHarness();
		$service->handle_webhook( array( 'id' => 'evt_1' ), null );
		return $service->handled;
	};
	security_assert( array( 'sub_basil' ) === $webhook( array( 'parent' => array( 'subscription_details' => array( 'subscription' => 'sub_basil' ) ) ) ), 'The webhook reads the subscription from parent.subscription_details' );
	security_assert( array( 'sub_legacy' ) === $webhook( array( 'subscription' => 'sub_legacy' ) ), 'The top-level subscription still wins on older API versions' );

	// 13. Stripe keeps its own sync time and only moves it forward after a fully successful backfill.
	eval( 'class StripeSchedulerHarness { ' . security_function( 'modules/membership/includes/Admin/Services/SubscriptionService.php', 'urm_backfill_missed_payment_events' ) . '}' );
	$schedule = function ( $outcome, $stripe_sync ) {
		$GLOBALS['options']          = array(
			'ur_membership_payment_gateways'             => array( 'stripe' => array() ),
			'urm_last_stripe_payment_backfill_sync_time' => $stripe_sync,
		);
		$GLOBALS['writes']           = array();
		$GLOBALS['logged_warnings']  = array();
		$GLOBALS['backfill_windows'] = array();
		$GLOBALS['stripe_outcome']   = $outcome;
		( new StripeSchedulerHarness() )->urm_backfill_missed_payment_events( 1000, 2000 );
		return $GLOBALS['writes']['urm_last_stripe_payment_backfill_sync_time'] ?? null;
	};
	security_assert( 2000 === $schedule( 'ok', 0 ), 'A successful first run stores the current time' );
	security_assert( 1000 === $GLOBALS['backfill_windows']['run_missed_payment_backfill'], 'A first run starts from the shared sync time' );
	security_assert( 1000 === $schedule( 'throw', 0 ), 'A Stripe error on a first run keeps the start of the failed window' );
	security_assert( 1 === count( $GLOBALS['logged_warnings'] ), 'A kept window is logged' );
	security_assert( 1500 === $schedule( 'throw', 1500 ), 'A Stripe error keeps the stored Stripe sync time' );
	security_assert( 1500 === $schedule( 'skipped', 1500 ), 'A skipped invoice keeps the stored Stripe sync time' );
	security_assert( 2000 === $schedule( 'ok', 1500 ), 'The next successful run moves the Stripe sync time forward' );
	security_assert( 1500 === $GLOBALS['backfill_windows']['run_missed_payment_backfill'], 'The retried run searches from the kept Stripe sync time' );
	security_assert( 1000 === $GLOBALS['backfill_windows']['run_missed_subscription_backfill'] && 1000 === $GLOBALS['backfill_windows']['run_missed_dispute_backfill'], 'Status and dispute backfills stay on the shared sync time' );
}
