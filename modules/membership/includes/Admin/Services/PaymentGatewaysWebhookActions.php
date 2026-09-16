<?php

namespace WPEverest\URMembership\Admin\Services;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPEverest\URMembership\Admin\Services\Paypal\NewPaypalService;
use WPEverest\URMembership\Admin\Services\Paypal\PaypalService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;

/**
 * PaymentGatewaysWebhookActions
 */
class PaymentGatewaysWebhookActions {

	/**
	 * @var NewPaypalService
	 */
	protected $paypal_service;

	/**
	 * @var PaypalService
	 */
	protected $legacy_paypal_service;

	/**
	 * PayPal's own copy of the event, set when the signature could not be verified but PayPal
	 * confirmed the event through its API. Processed in place of the posted body.
	 *
	 * @var array
	 */
	protected $paypal_authoritative_event = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->paypal_service        = new NewPaypalService();
		$this->legacy_paypal_service = new PaypalService();

		$this->init();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'handle_paypal_redirect_response' ), 1 );
		add_action( 'init', array( $this, 'handle_membership_paypal_ipn' ), 1 );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'user-registration',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_stripe_webhook' ),
				'permission_callback' => array( $this, 'verify_stripe_webhook_signature' ),
			)
		);

		register_rest_route(
			'user-registration',
			'/paypal-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_paypal_webhook' ),
				'permission_callback' => array( $this, 'verify_paypal_webhook_signature' ),
			)
		);
	}

	/**
	 * Handle PayPal buyer return redirect.
	 *
	 * Supports:
	 * - REST one-time order return (token)
	 * - REST subscription return (subscription_id)
	 * - legacy PayerID param if present
	 *
	 * @return void
	 */
	public function handle_paypal_redirect_response() {
		if ( ! isset( $_GET['ur-membership-return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$get_params = base64_decode( sanitize_text_field( wp_unslash( $_GET['ur-membership-return'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payer_id   = isset( $_GET['PayerID'] ) ? sanitize_text_field( wp_unslash( $_GET['PayerID'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'PayPal redirect received in PaymentGatewaysWebhookActions'
			. "\n" . wp_json_encode(
				array(
					'has_payer_id'    => ! empty( $payer_id ),
					'has_token'       => isset( $_GET['token'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'has_subscription_id' => isset( $_GET['subscription_id'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				),
				JSON_PRETTY_PRINT
			)
		);

		$this->paypal_service->handle_paypal_redirect_response( $get_params, $payer_id );
	}

	/**
	 * Handle legacy PayPal IPN callback.
	 *
	 * This is kept only for backward compatibility with old PayPal Standard/IPN flow.
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function handle_membership_paypal_ipn() {
		if ( ! isset( $_GET['ur-membership-listener'] ) || 'IPN' !== $_GET['ur-membership-listener'] ) {
			return;
		}

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'PayPal IPN POST received at membership listener',
			array(
				'webhook_type' => 'ipn',
				'raw_get'      => $_GET,
			)
		);

		$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'Legacy PayPal IPN listener triggered',
			array(
				'listener' => 'IPN',
			)
		);

		$this->legacy_paypal_service->handle_membership_paypal_ipn( $data );
	}

	/**
	 * Verify Stripe webhook signature.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_stripe_webhook_signature( WP_REST_Request $request ) {
		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			'Stripe webhook received, starting signature verification.',
			array()
		);

		$body = $request->get_body();

		$stripe_signature = $request->get_header( 'stripe-signature' );
		if ( empty( $stripe_signature ) ) {
			$stripe_signature = $request->get_header( 'stripe_signature' );
		}

		$legacy      = apply_filters( 'user_registration_stripe_webhook_secret', get_option( 'user_registration_stripe_webhook_secret', '' ) );
		$secret_test = get_option( 'user_registration_stripe_webhook_secret_test', '' );
		$secret_live = get_option( 'user_registration_stripe_webhook_secret_live', '' );
		$candidates  = array_filter( array( $legacy, $secret_test, $secret_live ) );

		if ( empty( $candidates ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe webhook rejected: no signing secret configured.',
				array(
					'error_code' => 'MISSING_WEBHOOK_SECRET',
				)
			);
			return false;
		}

		if ( empty( $stripe_signature ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe webhook verification failed.',
				array(
					'error_code'    => 'MISSING_STRIPE_SIGNATURE',
					'error_message' => 'Stripe-Signature header not found',
				)
			);
			return false;
		}

		new StripeService();

		foreach ( $candidates as $secret ) {
			try {
				\Stripe\Webhook::constructEvent( $body, $stripe_signature, $secret );

				PaymentGatewayLogging::log_general(
					'stripe',
					'Stripe webhook signature verification successful.',
					'success'
				);

				return true;
			} catch ( \Exception $e ) {
				continue;
			}
		}

		PaymentGatewayLogging::log_error(
			'stripe',
			'Stripe webhook verification failed.',
			array(
				'error_code'    => 'SIGNATURE_VERIFICATION_FAILED',
				'error_message' => 'No matching signing secret',
			)
		);

		return false;
	}

	/**
	 * Handle Stripe webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_stripe_webhook( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Empty Stripe webhook body.',
				),
				400
			);
		}

		$event = json_decode( $body, true );

		if ( empty( $event ) || ! is_array( $event ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid Stripe webhook payload.',
				),
				400
			);
		}

		$event           = json_decode( $body, true );
		$subscription_id = isset( $event['data']['object']['subscription'] ) ? $event['data']['object']['subscription'] : null;

		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			'Stripe webhook payload parsed successfully.',
			array(
				'event_type'      => isset( $event['type'] ) ? $event['type'] : '',
				'subscription_id' => $subscription_id,
			)
		);

		$stripe_service = new StripeService();
		$stripe_service->handle_webhook( $event, $subscription_id );

		return new WP_REST_Response(
			array(
				'success' => true,
			),
			200
		);
	}

	/**
	 * Verify PayPal webhook signature via the PayPal REST verification API.
	 *
	 * Rejects the request when no webhook ID is configured (fail-closed).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_paypal_webhook_signature( WP_REST_Request $request ) {
		$global_mode   = get_option( 'user_registration_global_paypal_mode', 'test' );
		$option_suffix = ( 'production' === $global_mode ) ? 'live' : 'test';
		$webhook_id    = get_option( 'user_registration_global_paypal_' . $option_suffix . '_webhook_id', '' );

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'PayPal REST webhook received, starting signature verification.' . "\n" . wp_json_encode(
				array(
					'mode'              => $global_mode,
					'webhook_id_stored' => ! empty( $webhook_id ),
				),
				JSON_PRETTY_PRINT
			)
		);

		if ( empty( $webhook_id ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'PayPal webhook ID not configured — request rejected.'
			);
			return false;
		}

		$headers = array(
			'transmission_id'   => $request->get_header( 'paypal-transmission-id' ),
			'transmission_time' => $request->get_header( 'paypal-transmission-time' ),
			'cert_url'          => $request->get_header( 'paypal-cert-url' ),
			'auth_algo'         => $request->get_header( 'paypal-auth-algo' ),
			'transmission_sig'  => $request->get_header( 'paypal-transmission-sig' ),
		);

		$paypal_options = array(
			'mode'       => $global_mode,
			'client_id'  => get_option( 'user_registration_global_paypal_' . $option_suffix . '_client_id', '' ),
			'secret_key' => get_option( 'user_registration_global_paypal_' . $option_suffix . '_client_secret', '' ),
		);

		$verified = $this->paypal_service->verify_webhook_signature(
			$headers,
			$request->get_body(),
			$webhook_id,
			$paypal_options
		);

		if ( ! $verified ) {
			// UR-4827: PayPal will not verify a signature once the delivery is old, so an event that
			// fails its first attempt can never pass on retry and the payment behind it is lost while
			// PayPal retries for days. Ask PayPal for its own copy instead, and process that copy so a
			// forged body carrying a genuine event ID gains nothing.
			$event    = json_decode( $request->get_body(), true );
			$event_id = is_array( $event ) && ! empty( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';

			$confirmed = $this->paypal_service->fetch_webhook_event( $event_id, $paypal_options );

			if ( is_wp_error( $confirmed ) ) {
				// Undetermined: could be a network blip or an expired token. Reject so PayPal retries.
				PaymentGatewayLogging::log_error(
					'paypal',
					'PayPal webhook signature verification failed and the event could not be confirmed — request rejected.' . "\n" . wp_json_encode(
						array(
							'event_id' => $event_id ?: null,
							'error'    => $confirmed->get_error_message(),
						),
						JSON_PRETTY_PRINT
					)
				);
				return false;
			}

			if ( empty( $confirmed['id'] ) || ! hash_equals( (string) $event_id, (string) $confirmed['id'] ) ) {
				// PayPal has no such event. Acknowledge with 200 so it is not retried for days, and do
				// not process anything.
				PaymentGatewayLogging::log_error(
					'paypal',
					'PayPal webhook rejected: signature invalid and PayPal does not recognise the event.' . "\n" . wp_json_encode(
						array( 'event_id' => $event_id ?: null ),
						JSON_PRETTY_PRINT
					)
				);

				return new WP_Error(
					'urm_paypal_webhook_unverified',
					__( 'PayPal webhook could not be authenticated.', 'user-registration' ),
					array( 'status' => 200 )
				);
			}

			$this->paypal_authoritative_event = $confirmed;

			PaymentGatewayLogging::log_general(
				'paypal',
				'PayPal webhook signature failed but PayPal confirmed the event through its API — processing PayPal\'s copy.' . "\n" . wp_json_encode(
					array(
						'event_id'   => $event_id,
						'event_type' => isset( $confirmed['event_type'] ) ? $confirmed['event_type'] : '',
					),
					JSON_PRETTY_PRINT
				),
				'notice'
			);

			return true;
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			'PayPal webhook signature verified successfully.',
			'success'
		);

		return true;
	}

	/**
	 * Handle PayPal REST webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_paypal_webhook( WP_REST_Request $request ) {
		// Signature failed but PayPal vouched for the event: act on PayPal's copy, never the posted body.
		if ( ! empty( $this->paypal_authoritative_event ) ) {
			$event                            = $this->paypal_authoritative_event;
			$this->paypal_authoritative_event = array();

			$result = $this->paypal_service->handle_webhook_event( $event );

			return new WP_REST_Response(
				array( 'success' => (bool) $result ),
				200
			);
		}

		$body = $request->get_body();

		if ( empty( $body ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Empty PayPal webhook body.',
				),
				400
			);
		}

		$event = json_decode( $body, true );

		if ( empty( $event ) || ! is_array( $event ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid PayPal webhook payload.',
				),
				400
			);
		}

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'PayPal REST webhook payload parsed successfully.',
			array(
				'event_type'  => isset( $event['event_type'] ) ? $event['event_type'] : '',
				'resource_id' => isset( $event['resource']['id'] ) ? $event['resource']['id'] : '',
			)
		);

		$result = $this->paypal_service->handle_webhook_event( $event );

		if ( ! $result ) {
			$result = apply_filters( 'user_registration_paypal_webhook_event_fallback', false, $event );
		}

		if ( false === $result ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'PayPal webhook could not be processed.',
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
			),
			200
		);
	}
}
