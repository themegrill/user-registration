<?php

namespace WPEverest\URMembership\Admin\Services\Stripe;

use Stripe\Exception\ApiErrorException;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Services\CouponService;
use WPEverest\URMembership\Admin\Services\EmailService;
use WPEverest\URMembership\Admin\Services\OrderService;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;
use WPEverest\URMembership\Admin\Services\UpgradeMembershipService;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

/**
 * Stripe Service Class.
 */
class StripeService {

	/**
	 * Members orders repository instance.
	 *
	 * @var MembersOrderRepository
	 */
	protected $members_orders_repository;

	/**
	 * Members subscription repository instance.
	 *
	 * @var MembersSubscriptionRepository
	 */
	protected $members_subscription_repository;

	/**
	 * Membership repository instance.
	 *
	 * @var MembershipRepository
	 */
	protected $membership_repository;

	/**
	 * Orders repository instance.
	 *
	 * @var OrdersRepository
	 */
	protected $orders_repository;

	/**
	 * Construct.
	 */
	public function __construct() {

		// Initialize necessary repositories.
		$this->members_orders_repository       = new MembersOrderRepository();
		$this->members_subscription_repository = new MembersSubscriptionRepository();
		$this->membership_repository           = new MembershipRepository();
		$this->orders_repository               = new OrdersRepository();

		if ( ! class_exists( 'Stripe\Stripe' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'lib/stripe-php/init.php';
		}

		try {
			$stripe_settings = self::get_stripe_settings();

			if ( empty( $stripe_settings['secret_key'] ) ) {
				throw new \Exception( 'Stripe secret key is not configured' );
			}

			// Set your secret key.
			\Stripe\Stripe::setApiKey( $stripe_settings['secret_key'] );

		} catch ( \Exception $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Failed to initialize Stripe service' . "\n" . wp_json_encode(
					array(
						'error_code'    => 'INITIALIZATION_FAILED',
						'error_message' => $e->getMessage(),
					),
					JSON_PRETTY_PRINT
				)
			);
		}
	}

	/**
	 * Get Stripe settings.
	 *
	 * @return array
	 */
	public static function get_stripe_settings() {
		$is_enabled        = get_option( 'user_registration_stripe_enabled', '' );
		$stripe_default    = ur_string_to_bool( get_option( 'urm_is_new_installation', false ) );
		$has_user_changed  = ur_string_to_bool( get_option( 'urm_stripe_updated_connection_status', false ) );
		$is_stripe_enabled = ( $is_enabled ) ? $is_enabled : ( $has_user_changed ? $stripe_default : ! $stripe_default );

		$mode            = get_option( 'user_registration_stripe_test_mode', false ) ? 'test' : 'live';
		$publishable_key = get_option( sprintf( 'user_registration_stripe_%s_publishable_key', $mode ) );
		$secret_key      = get_option( sprintf( 'user_registration_stripe_%s_secret_key', $mode ) );

		return array(
			'is_stipe_enabled' => $is_stripe_enabled,
			'mode'             => $mode,
			'publishable_key'  => $publishable_key,
			'secret_key'       => $secret_key,
		);
	}

	/**
	 * Validate that a Stripe PaymentMethod's livemode matches the configured mode.
	 *
	 * Returns ['valid' => true] on match, or ['valid' => false, 'message' => '...'] on mismatch.
	 *
	 * @param string $payment_method_id Stripe PaymentMethod ID (pm_...).
	 * @return array
	 */
	public function validate_card_mode( $payment_method_id ) {
		$stripe_settings = self::get_stripe_settings();
		$mode            = $stripe_settings['mode'];

		try {
			$payment_method    = \Stripe\PaymentMethod::retrieve( $payment_method_id );
			$current_member_id = get_current_user_id();
			$ur_log            = new PaymentGatewayLogging();
			$ur_log->log(
				'stripe',
				'[Member ID #' . $current_member_id . '] Payment method retrieved.' . "\n" . wp_json_encode(
					array(
						'member_id'      => $current_member_id,
						'payment_method' => $payment_method,
					),
					JSON_PRETTY_PRINT
				)
			);

			if ( ! $payment_method->livemode && 'live' === $mode ) {
				return array(
					'valid'   => false,
					'message' => __( 'Test card numbers are not accepted in live mode. Please use a real payment card.', 'user-registration' ),
				);
			}

			if ( $payment_method->livemode && 'test' === $mode ) {
				return array(
					'valid'   => false,
					'message' => __( 'Real card numbers are not accepted in test mode. Please use a Stripe test card.', 'user-registration' ),
				);
			}

			return array( 'valid' => true );

		} catch ( \Exception $e ) {
			return array(
				'valid'   => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Default for webhook registration.
	 *
	 * @return string[]
	 */
	public static function get_handled_webhook_events() {
		return array(
			'invoice.payment_succeeded',
			'invoice.payment_failed',
			'payment_intent.payment_failed',
			'charge.refunded',
			// Fires when a delayed-start Subscription Schedule (100% coupon) actually starts and
			// materializes its subscription, so we can back-fill the real subscription id. UR-4386.
			'customer.subscription.created',
		);
	}

	/**
	 * Create webhook endpoint in Stripe for a given mode.
	 *
	 * @param string $mode 'test' or 'live'.
	 * @return array
	 */
	public static function create_webhook( $mode ) {
		if ( ! class_exists( 'Stripe\Stripe' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'lib/stripe-php/init.php';
		}
		$secret_key = get_option( 'user_registration_stripe_' . $mode . '_secret_key', '' );
		if ( empty( $secret_key ) ) {
			return array(
				'success' => false,
				'message' => 'No API key configured for ' . $mode . ' mode',
			);
		}

		$webhook_id_option     = 'user_registration_stripe_webhook_id_' . $mode;
		$webhook_secret_option = 'user_registration_stripe_webhook_secret_' . $mode;
		$existing_webhook_id   = get_option( $webhook_id_option, '' );

		if ( ! empty( $existing_webhook_id ) ) {
			try {
				\Stripe\Stripe::setApiKey( $secret_key );
				$webhook = \Stripe\WebhookEndpoint::retrieve( $existing_webhook_id );
				if ( $webhook && $webhook->id ) {
					$required_events = apply_filters( 'urm_stripe_webhook_enabled_events', self::get_handled_webhook_events(), $mode );
					$current_events  = isset( $webhook->enabled_events ) ? (array) $webhook->enabled_events : array();
					$is_catch_all    = in_array( '*', $current_events, true );
					$missing_events  = array_diff( $required_events, $current_events );

					if ( ! $is_catch_all && ! empty( $missing_events ) ) {
						$updated_events = array_values( array_unique( array_merge( $current_events, $required_events ) ) );
						\Stripe\WebhookEndpoint::update(
							$webhook->id,
							array( 'enabled_events' => $updated_events )
						);
					}

					return array(
						'success'    => true,
						'message'    => 'Webhook already exists for ' . $mode . ' mode',
						'webhook_id' => $webhook->id,
						'mode'       => $mode,
					);
				}
			} catch ( \Exception $e ) {
				delete_option( $webhook_id_option );
				delete_option( $webhook_secret_option );
			}
		}

		try {
			\Stripe\Stripe::setApiKey( $secret_key );
			$webhook_url    = rest_url( 'user-registration/stripe-webhook' );
			$default_events = self::get_handled_webhook_events();
			$enabled_events = apply_filters( 'urm_stripe_webhook_enabled_events', $default_events, $mode );
			$webhook        = \Stripe\WebhookEndpoint::create(
				array(
					'url'            => $webhook_url,
					'enabled_events' => array_values( array_filter( (array) $enabled_events ) ),
					'api_version'    => '2023-10-16',
				)
			);

			$signing_secret = isset( $webhook->secret ) ? $webhook->secret : ( isset( $webhook->signing_secret ) ? $webhook->signing_secret : '' );
			if ( ! empty( $signing_secret ) ) {
				update_option( $webhook_secret_option, $signing_secret );
			}
			update_option( $webhook_id_option, $webhook->id );

			return array(
				'success'        => true,
				'message'        => 'Webhook created successfully for ' . $mode . ' mode',
				'webhook_id'     => $webhook->id,
				'webhook_secret' => $signing_secret,
				'mode'           => $mode,
			);
		} catch ( ApiErrorException $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Webhook creation failed' . "\n" . wp_json_encode(
					array(
						'error_code'    => 'WEBHOOK_CREATE_FAILED',
						'error_message' => $e->getMessage(),
						'mode'          => $mode,
					),
					JSON_PRETTY_PRINT
				)
			);
			return array(
				'success' => false,
				'message' => $e->getMessage(),
				'mode'    => $mode,
			);
		}
	}

	/**
	 * Ensure webhook exists for current mode (for backwards compatibility on first payment).
	 */
	public static function ensure_webhook_for_current_mode() {
		$settings = self::get_stripe_settings();
		$mode     = isset( $settings['mode'] ) ? $settings['mode'] : 'test';
		$secret   = get_option( 'user_registration_stripe_webhook_secret_' . $mode, '' );
		if ( ! empty( $secret ) ) {
			return;
		}
		$legacy = get_option( 'user_registration_stripe_webhook_secret', '' );
		if ( ! empty( $legacy ) ) {
			return;
		}
		$result = self::create_webhook( $mode );
		if ( ! empty( $result['success'] ) ) {
			PaymentGatewayLogging::log_general(
				'stripe',
				'Webhook created on first payment' . "\n" . wp_json_encode(
					array(
						'event_type' => 'webhook_ensure',
						'mode'       => $mode,
					),
					JSON_PRETTY_PRINT
				),
				'notice'
			);
		}
	}

	/**
	 * Deprecation function for creating price and product in stripe.
	 *
	 * @deprecated 5.1.5 Use sync_product_and_price_in_stripe().
	 * @param array $post_data          Post data.
	 * @param array $meta_data          Meta data.
	 * @param bool  $should_create_new_price Should create new price.
	 * @return array|void
	 */
	public function create_stripe_product_and_price( $post_data, $meta_data, $should_create_new_price ) {
		$product_version = UR_PRO_ACTIVE ? '6.1.4' : '5.1.5';
		ur_deprecated_function( __METHOD__, $product_version, 'sync_product_and_price_in_stripe' );
		return $this->sync_product_and_price_in_stripe( $post_data );
	}

	/**
	 * Create or Update product and price in stripe based on provided data.
	 *
	 * @param array $membership Membership data.
	 * @return array|void
	 */
	public function sync_product_and_price_in_stripe( $membership ) {

		if ( empty( $membership ) ) {
			return;
		}

		$stripe_settings = self::get_stripe_settings();
		if ( empty( $stripe_settings['secret_key'] ) ) {
			return;
		}

		$membership_metas = $membership['meta_value'] ?? array();

		if ( ! is_array( $membership_metas ) ) {
			$membership_metas = json_decode( $membership_metas, true );
		}

		if ( empty( $membership_metas['type'] ) ) {
			return;
		}

		$membership_id   = $membership['ID'];
		$membership_type = $membership_metas['type'];

		$product_name = $membership['post_title'] ?? __( 'Membership Plan', 'user-registration' );
		$amount       = $membership_metas['amount'] ?? 0;
		$currency     = get_option( 'user_registration_payment_currency', 'USD' );

		$stripe_meta = $membership_metas['payment_gateways']['stripe'] ?? array();
		$product_id  = $stripe_meta['product_id'] ?? '';
		$price_id    = $stripe_meta['price_id'] ?? '';

		$amount_in_stripe = $this->calculate_stripe_amount( $amount, $currency );
		$product          = null;

		// Ensure Product Exists.
		if ( ! empty( $product_id ) ) {
			try {
				$product = \Stripe\Product::retrieve( $product_id );
			} catch ( \Stripe\Exception\InvalidRequestException $e ) {
				$product = null;
			}
		}

		if ( ! $product ) {
			$product = \Stripe\Product::create(
				array(
					'name'     => $product_name,
					'metadata' => array(
						'membership_id' => $membership_id,
					),
				)
			);
		}

		$product_id = $product->id;

		// Ensure Price Exists / Is Correct.
		$price_id = $this->ensure_price_in_stripe(
			$membership_type,
			$product_id,
			$price_id,
			$amount_in_stripe,
			$currency,
			$membership_metas
		);

		// Update meta.
		if ( ! isset( $membership_metas['payment_gateways']['stripe'] ) || ! is_array( $membership_metas['payment_gateways']['stripe'] ) ) {
			$membership_metas['payment_gateways']['stripe'] = array();
		}

		$membership_metas['payment_gateways']['stripe']['product_id'] = $product_id;
		$membership_metas['payment_gateways']['stripe']['price_id']   = $price_id;

		update_post_meta(
			$membership_id,
			'ur_membership',
			wp_json_encode( $membership_metas )
		);

		return array( 'success' => true );
	}

	/**
	 * Process Stripe payment.
	 *
	 * @param array $payment_data  Payment data.
	 * @param array $response_data Response data.
	 * @return array
	 */
	public function process_stripe_payment( $payment_data, $response_data ) {
		self::ensure_webhook_for_current_mode();
		$currency   = get_option( 'user_registration_payment_currency', 'USD' );
		$user_email = $response_data['email'];
		$member_id  = $response_data['member_id'];
		$username   = ! empty( $response_data['username'] ) ? $response_data['username'] : '';
		$amount     = 0;
		$team_id    = $payment_data['team_id'] ?? 0;
		if ( $team_id && ! empty( $payment_data['team_data'] ) ) {
			$team_data  = $payment_data['team_data'];
			$seat_model = $team_data['seat_model'] ?? '';

			if ( 'fixed' === $seat_model ) {
				$amount = (float) $team_data['team_price'];
			} else {
				$team_seats = absint( $team_data['team_seats'] ?? 0 );
				if ( $team_seats <= 0 ) {
					PaymentGatewayLogging::log_error(
						'stripe',
						'Payment stopped - Invalid team seats' . "\n" . wp_json_encode(
							array(
								'error_code' => 'INVALID_TEAM_SEATS',
								'amount'     => $amount,
								'member_id'  => $member_id,
							),
							JSON_PRETTY_PRINT
						)
					);
					if ( empty( $payment_data['upgrade'] ) ) {
						wp_delete_user( absint( $member_id ) );
						if ( $team_id ) {
							wp_delete_post( absint( $team_id ) );
						}
					}
					wp_send_json_error(
						array(
							'message' => __( 'Stripe Payment stopped, Invalid team seats.', 'user-registration' ),
						)
					);
				}
				$pricing_model = $team_data['pricing_model'] ?? '';
				if ( 'per_seat' === $pricing_model ) {
					$amount = $team_seats * (float) $team_data['per_seat_price'];
				} else {
					$tier = $payment_data['team_tier_info'] ?? '';
					if ( ! $tier ) {
						PaymentGatewayLogging::log_error(
							'stripe',
							'Payment stopped - Invalid pricing tier' . "\n" . wp_json_encode(
								array(
									'error_code' => 'INVALID_TIER',
									'amount'     => $amount,
									'member_id'  => $member_id,
								),
								JSON_PRETTY_PRINT
							)
						);
						if ( empty( $payment_data['upgrade'] ) ) {
							wp_delete_user( absint( $member_id ) );
							if ( $team_id ) {
								wp_delete_post( absint( $team_id ) );
							}
						}
						wp_send_json_error(
							array(
								'message' => __( 'Stripe Payment stopped, Invalid pricing tier.', 'user-registration' ),
							)
						);
					}
					$amount = $team_seats * (float) $payment_data['team_tier_info']['tier_per_seat_price'];
				}
			}

			$membership_type = $team_data['team_plan_type'] ?? 'unknown';
			if ( 'one-time' === $membership_type ) {
				$membership_type = 'paid';
			}
		} else {
			$amount          = $payment_data['amount'];
			$membership_type = $payment_data['type'] ?? 'unknown';
		}

		$local_currency = ! empty( $response_data['switched_currency'] ) ? $response_data['switched_currency'] : '';
		$ur_zone_id     = ! empty( $response_data['urm_zone_id'] ) ? $response_data['urm_zone_id'] : '';

		if ( ! empty( $local_currency ) && ! empty( $ur_zone_id ) && UR_PRO_ACTIVE && ur_check_module_activation( 'local-currency' ) && class_exists( CoreFunctions::class ) ) {
			$pricing_data        = CoreFunctions::ur_get_pricing_zone_by_id( $ur_zone_id );
			$local_currency_data = ! empty( $payment_data['local_currency'] ) ? $payment_data['local_currency'] : array();

			// Switch currency only when the membership has local currency enabled; otherwise the
			// unconverted global amount ships in the local currency (20 USD as 20 NPR ≈ $0.13). Mirrors PayPal.
			if ( ! empty( $local_currency_data ) && ur_string_to_bool( $local_currency_data['is_enable'] ) ) {
				$currency = $local_currency;
				$amount   = CoreFunctions::ur_get_amount_after_conversion( $amount, $currency, $pricing_data, $local_currency_data, $ur_zone_id );
			}
		}

		PaymentGatewayLogging::log_transaction_start(
			'stripe',
			sprintf( ' [Member ID #%s] Processing Stripe payment', $member_id ) . "\n" . wp_json_encode(
				array(
					'member_id'       => $member_id,
					'amount'          => $amount,
					'currency'        => $currency,
					'membership_type' => $membership_type,
					'email'           => $user_email,
				),
				JSON_PRETTY_PRINT
			),
		);

		$response = array(
			'type' => $membership_type,
		);

		if ( isset( $payment_data['upgrade'] ) && $payment_data['upgrade'] ) {
			// $amount already holds the resolved (and converted) chargeable_amount — nothing to do.
		} elseif ( isset( $payment_data['coupon'] ) && ! empty( $payment_data['coupon'] ) && ur_check_module_activation( 'coupon' ) ) {
			$coupon_service    = new CouponService();
			$coupon_validation = $coupon_service->validate(
				array(
					'coupon'        => $payment_data['coupon'],
					'membership_id' => isset( $payment_data['item_id'] ) ? absint( $payment_data['item_id'] ) : 0,
				)
			);

			if ( $coupon_validation['status'] ) {
				$coupon_details = ur_get_coupon_details( $payment_data['coupon'] );

				if ( isset( $coupon_details['coupon_discount_type'] ) && isset( $coupon_details['coupon_discount'] ) ) {
					$discount_amount = ( 'fixed' === $coupon_details['coupon_discount_type'] ) ? $coupon_details['coupon_discount'] : $amount * $coupon_details['coupon_discount'] / 100;
					$amount          = $amount - $discount_amount;
				}
			}
		}

		if ( ! empty( $response_data['tax_rate'] ) && ! empty( $response_data['tax_calculation_method'] ) && ur_string_to_bool( $response_data['tax_calculation_method'] ) ) {
			$tax_rate   = (float) $response_data['tax_rate'];
			$tax_amount = $amount * $tax_rate / 100;
			$amount     = $amount + $tax_amount;
		}

		// UR-4386: allow a 100%-coupon subscription (amount 0) past the min-amount guards below;
		// it is created with a delayed start in create_subscription().
		$is_full_discount_subscription = ( 'subscription' === $membership_type )
			&& ( (float) $amount <= 0.0 )
			&& ! empty( $payment_data['coupon'] )
			&& ur_check_module_activation( 'coupon' );

		if ( 'JPY' === $currency ) {
			$amount = (int) round( abs( $amount ) );
		} else {
			$amount = (int) round( abs( $amount ) * 100 );
		}

		if ( $amount < 1 && ! $is_full_discount_subscription ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Payment stopped - Amount less than minimum' . "\n" . wp_json_encode(
					array(
						'error_code' => 'AMOUNT_TOO_LOW',
						'amount'     => $amount,
						'member_id'  => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			if ( empty( $payment_data['upgrade'] ) ) {
				wp_delete_user( absint( $member_id ) );
			}
			wp_send_json_error(
				array(
					'message' => __( 'Stripe Payment stopped, Total amount after discount cannot be less than One.', 'user-registration' ),
				)
			);
		}
		// Return if invalid amount. A full-discount subscription legitimately has a $0 first
		// charge (delayed-start schedule), so it must not be aborted here. UR-4386.
		if ( ! $is_full_discount_subscription && ( empty( $amount ) || user_registration_sanitize_amount( 0, $currency ) == $amount ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Payment stopped - Invalid or empty amount' . "\n" . wp_json_encode(
					array(
						'error_code' => 'INVALID_AMOUNT',
						'amount'     => $amount,
						'member_id'  => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			if ( empty( $payment_data['upgrade'] ) ) {
				wp_delete_user( absint( $member_id ) );
			}
			wp_send_json_error(
				array(
					'message' => __( 'Stripe Payment stopped, Invalid/Empty amount', 'user-registration' ),
				)
			);
		}

		try {
			$existing_customer_id = get_user_meta( $member_id, 'ur_payment_customer', true );

			if ( ! empty( $existing_customer_id ) ) {
				// Reuse the existing Stripe customer (e.g. during upgrades/renewals).
				try {
					$customer = \Stripe\Customer::retrieve( $existing_customer_id );
					if ( isset( $customer->deleted ) && $customer->deleted ) {
						$existing_customer_id = '';
					}
				} catch ( \Stripe\Exception\ApiErrorException $e ) {
					$existing_customer_id = '';
				}
			}

			if ( empty( $existing_customer_id ) ) {
				PaymentGatewayLogging::log_api_request(
					'stripe',
					sprintf( ' [Member ID #%s] Creating Stripe customer', $member_id ) . "\n" . wp_json_encode(
						array(
							'endpoint'  => 'Customer::create',
							'email'     => $user_email,
							'member_id' => $member_id,
						),
						JSON_PRETTY_PRINT
					),
				);

				$customer = \Stripe\Customer::create(
					array(
						'email' => $user_email,
						'name'  => $username,
					)
				);

				update_user_meta( $member_id, 'ur_payment_customer', $customer->id );

				PaymentGatewayLogging::log_api_response(
					'stripe',
					sprintf( ' [Member ID #%s] Stripe customer created successfully.', $member_id ) . "\n" . wp_json_encode(
						array(
							'customer_id' => $customer->id,
							'member_id'   => $member_id,
						),
						JSON_PRETTY_PRINT
					),
				);
			} else {
				PaymentGatewayLogging::log_api_response(
					'stripe',
					sprintf( ' [Member ID #%s] Reusing existing Stripe customer.', $member_id ) . "\n" . wp_json_encode(
						array(
							'customer_id' => $customer->id,
							'member_id'   => $member_id,
						),
						JSON_PRETTY_PRINT
					),
				);
			}

			$response['stripe_cus_id'] = $customer->id;
			if ( 'paid' === $membership_type ) {
				PaymentGatewayLogging::log_api_request(
					'stripe',
					sprintf( ' [Member ID #%s] Creating payment intent.', $member_id ) . "\n" . wp_json_encode(
						array(
							'endpoint'    => 'PaymentIntent::create',
							'amount'      => $amount,
							'currency'    => $currency,
							'customer_id' => $customer->id,
						),
						JSON_PRETTY_PRINT
					),
				);

				$intent = \Stripe\PaymentIntent::create(
					array(
						'amount'               => $amount,
						'currency'             => $currency,
						'payment_method_types' => array( 'card' ),
						'customer'             => $customer->id,
					)
				);

				$response['client_secret'] = $intent->client_secret;
				$this->orders_repository->update( $response_data['order_id'], array( 'transaction_id' => $intent->id ) );
				PaymentGatewayLogging::log_transaction_success(
					'stripe',
					sprintf( ' [Member ID #%s] Payment intent created successfully.', $member_id ) . "\n" . wp_json_encode(
						array(
							'payment_intent_id' => $intent->id,
							'membership_type'   => $membership_type,
							JSON_PRETTY_PRINT,
						),
					)
				);
			}

							return $response;
		} catch ( ApiErrorException $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe API error occurred' . "\n" . wp_json_encode(
					array(
						'error_code'    => 'STRIPE_API_ERROR',
						'error_message' => $e->getMessage(),
						'member_id'     => $member_id,
					),
					JSON_PRETTY_PRINT
				),
			);

			if ( empty( $payment_data['upgrade'] ) ) {
				wp_delete_user( absint( $member_id ) );
			}

			wp_send_json_error(
				array(
					'message' => __( 'Stripe Payment stopped, Incomplete Stripe setup.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Return a failed order response, optionally logging an error.
	 *
	 * @param array       $response    Base response array.
	 * @param string      $message     User-facing error message.
	 * @param string|null $log_message Optional. Message for PaymentGatewayLogging::log_error.
	 * @param array       $log_context Optional. Context for log
	 * @return array
	 */
	private function update_order_error( array $response, $message, $log_message = null, $log_context = array() ) {
		$response['status']  = false;
		$response['message'] = $message;
		if ( $log_message !== null && $log_message !== '' ) {
			PaymentGatewayLogging::log_error( 'stripe', $log_message, $log_context );
		}
		return $response;
	}

	/**
	 * Update order after Stripe payment confirmation.
	 *
	 * @param array $data Data from frontend.
	 * @return array
	 */
	public function update_order( $data ) {
		$payment_status         = sanitize_text_field( $data['payment_status'] );
		$member_id              = isset( $_POST['member_id'] ) ? absint( wp_unslash( $_POST['member_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$membership_process     = urm_get_membership_process( $member_id );
		$selected_membership_id = isset( $_POST['selected_membership_id'] ) && '' !== $_POST['selected_membership_id'] ? absint( wp_unslash( $_POST['selected_membership_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_membership_id  = isset( $_POST['current_membership_id'] ) && '' !== $_POST['current_membership_id'] ? absint( wp_unslash( $_POST['current_membership_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$is_purchasing_multiple = ! empty( $membership_process['multiple'] ) && in_array( $selected_membership_id, $membership_process['multiple'], true );
		$is_upgrading           = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $current_membership_id ] );
		$transaction_id         = $data['payment_result']['paymentIntent']['id'] ?? '';
		$team_id                = isset( $_POST['team_id'] ) && '' !== $_POST['team_id'] ? absint( wp_unslash( $_POST['team_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_id               = $data['order_id'] ?? '';

		if ( empty( $transaction_id ) || preg_match( '/^sub_/', $transaction_id ) ) {
			$transaction_id = $data['payment_result']['latest_invoice']['payment_intent']['id'] ?? '';
		}

		$three_d_secure_2_source = $data['payment_result']['latest_invoice']['payment_intent']['next_action']['use_stripe_sdk']['three_d_secure_2_source'] ?? '';

		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			sprintf( ' [Member ID #%s] Stripe payment confirmation callback received.', $member_id ) . "\n" . wp_json_encode(
				array(
					'webhook_type'           => 'payment_confirmation',
					'transaction_id'         => $transaction_id,
					'payment_status'         => $payment_status,
					'member_id'              => $member_id,
					'is_upgrade'             => $is_upgrading,
					'is_purchasing_multiple' => $is_purchasing_multiple,
				),
				JSON_PRETTY_PRINT
			),
		);

		$response = array(
			'status' => true,
		);

		// A failed client-side confirmation (card validation error, decline, 3DS
		// abandonment) is a normal gateway outcome, not a verification anomaly:
		// surface the gateway's own message and leave the order pending for retry.
		if ( 'failed' === $payment_status ) {
			$client_error_message = sanitize_text_field( wp_unslash( $data['payment_result']['error']['message'] ?? '' ) );
			$client_error_code    = sanitize_text_field( wp_unslash( $data['payment_result']['error']['code'] ?? '' ) );

			if ( '' === $client_error_message ) {
				$client_error_message = __( 'Payment could not be completed. Please check your card details and try again.', 'user-registration' );
			}

			return $this->update_order_error(
				$response,
				$client_error_message,
				'Stripe payment confirmation failed on the client side.',
				array(
					'error_code' => '' !== $client_error_code ? $client_error_code : 'PAYMENT_FAILED',
					'member_id'  => $member_id,
					'order_id'   => $order_id,
				)
			);
		}

		$stripe_settings = self::get_stripe_settings();

		if ( empty( $stripe_settings['secret_key'] ) ) {
			return $this->update_order_error( $response, __( 'Stripe secret key is not configured.', 'user-registration' ) );
		}

		\Stripe\Stripe::setApiKey( $stripe_settings['secret_key'] );

		$pi_id = sanitize_text_field( $transaction_id );

		if ( empty( $pi_id ) ) {
			$pi_id = sanitize_text_field( ! empty( $data['payment_result']['latest_invoice']['payment_intent']['id'] ) ? $data['payment_result']['latest_invoice']['payment_intent']['id'] : '' );
		}

		if ( empty( $pi_id ) ) {
			$existing_order = $this->members_orders_repository->get_member_orders( $member_id );
			if ( ! empty( $existing_order ) && 'completed' === $existing_order['status'] ) {
				$response['status']  = true;
				$response['message'] = __( 'Payment already verified.', 'user-registration' );
				return $response;
			}

			return $this->update_order_error(
				$response,
				__( 'Payment verification failed: missing payment identifier.', 'user-registration' ),
				'Payment confirmation rejected: missing PaymentIntent ID',
				array(
					'error_code' => 'MISSING_PAYMENT_INTENT',
					'member_id'  => $member_id,
				)
			);
		}

		try {
			$intent = \Stripe\PaymentIntent::retrieve( $pi_id );
		} catch ( \Stripe\Exception\ApiErrorException $ex ) {
			return $this->update_order_error(
				$response,
				__( 'Payment verification failed.', 'user-registration' ),
				'Stripe API error occurred while retrieving PaymentIntent',
				array(
					'error_code'        => 'STRIPE_API_ERROR',
					'error_message'     => $ex->getMessage(),
					'member_id'         => $member_id,
					'payment_intent_id' => $pi_id,
				)
			);
		}

		if ( ! $intent->livemode && 'live' === $stripe_settings['mode'] ) {
			$response['status']  = false;
			$response['message'] = __( 'Test card numbers are not accepted in live mode. Please use a real payment card.', 'user-registration' );
			return $response;
		}

		if ( $intent->livemode && 'test' === $stripe_settings['mode'] ) {
			$response['status']  = false;
			$response['message'] = __( 'Real card numbers are not accepted in test mode. Please use a Stripe test card.', 'user-registration' );
			return $response;
		}

		if ( 'succeeded' !== $intent->status ) {
			$response['status']  = false;
			$response['message'] = __( 'Payment not completed.', 'user-registration' );
			return $response;
		}

		$payment_status = $intent->status;

		$latest_order = $this->orders_repository->get_order_by_transaction_id( $intent->id );
		$latest_order = is_array( $latest_order ) ? $latest_order : ( $latest_order ? (array) $latest_order : array() );

		if ( empty( $latest_order ) || (int) $member_id !== (int) $latest_order['user_id'] ) {
			return $this->update_order_error(
				$response,
				__( 'Order not found for this member.', 'user-registration' ),
				'Order not found for member',
				array(
					'error_code' => 'ORDER_NOT_FOUND',
					'member_id'  => $member_id,
				)
			);
		}

		if ( ! empty( $latest_order ) && 'stripe' !== $latest_order['payment_method'] ) {
			return $this->update_order_error(
				$response,
				__( 'Payment method mismatch: order payment method is not stripe', 'user-registration' ),
				'Payment method mismatch: order payment method is not stripe',
				array(
					'error_code'     => 'PAYMENT_METHOD_MISMATCH',
					'member_id'      => $member_id,
					'order_id'       => $latest_order['ID'],
					'payment_method' => $latest_order['payment_method'],
				)
			);
			$response['status']  = false;
			$response['message'] = __( 'Invalid payment method for this order.', 'user-registration' );
			return $response;
		}

		// Exclude the order that actually owns this transaction (already fetched above), not the
		// client-supplied order_id which can arrive empty on the registration/first-purchase
		// flow. Otherwise the order's own transaction is treated as a duplicate and the payment
		// is reported "already verified" without ever completing the order/subscription.
		if ( $this->members_orders_repository->does_transaction_id_exists( $transaction_id, $latest_order['ID'] ) ) {

			$duplicate_order = $this->orders_repository->get_order_by_transaction_id( $transaction_id );
			if ( ! empty( $duplicate_order ) && absint( $duplicate_order['user_id'] ) === $member_id ) {
				$response['status']  = true;
				$response['message'] = __( 'Payment already verified.', 'user-registration' );
				return $response;
			}

			$response['status']  = false;
			$response['message'] = __( 'Duplicate transaction id.', 'user-registration' );

			return $response;
		}

		$membership       = $this->membership_repository->get_single_membership_by_ID( $latest_order['item_id'] );
		$membership_metas = wp_unslash( json_decode( $membership['meta_value'], true ) );
		if ( $team_id ) {
			$team_data = get_post_meta( $team_id, 'urm_team_data', true );
			if ( ! $team_data ) {
				return $this->update_order_error(
					$response,
					__( 'Invalid team data.', 'user-registration' ),
					'Invalid team data',
					array(
						'error_code' => 'INVALID_TEAM_DATA',
						'member_id'  => $member_id,
					)
				);
			}
			$membership_type = $team_data['team_plan_type'] ?? 'unknown';
		} else {
			$membership_type = $membership_metas['type'] ?? 'unknown';
		}

		if ( 'failed' === $payment_status ) {
			$is_renewing = ! empty( $membership_process['renew'] ) && in_array( $latest_order['item_id'], $membership_process['renew'], true );

			$error_msg = __( 'Stripe Payment failed.', 'user-registration' );
			$error_msg = $data['payment_result']['error']['message'] ?? $error_msg;

			PaymentGatewayLogging::log_transaction_failure(
				'stripe',
				'Stripe payment failed',
				array(
					'error_code'      => 'PAYMENT_FAILED',
					'error_message'   => $error_msg,
					'member_id'       => $member_id,
					'transaction_id'  => $transaction_id,
					'membership_type' => $membership_type,
					'is_renewing'     => $is_renewing,
				)
			);

			$this->members_orders_repository->update(
				$latest_order['ID'],
				array(
					'status' => 'failed',
				)
			);

			do_action( 'ur_membership_order_status_failed', $latest_order['ID'], $latest_order, 'failed' );

			if ( ! $is_upgrading && ! $is_renewing && ! $is_purchasing_multiple ) {
				if ( absint( $member_id ) === get_current_user_id() || current_user_can( 'edit_users' ) ) {
					wp_delete_user( absint( $member_id ) );
				}
				$this->members_orders_repository->delete_member_order( $member_id );
			}
			if ( $is_renewing ) {
				unset( $membership_process['upgrade'][ $latest_order['item_id'] ] );
				update_user_meta( $member_id, 'urm_membership_process', $membership_process );

				do_action( 'user_registration_membership_renewal_failed', $member_id, $latest_order['item_id'] );
			}

			$response['message'] = $error_msg;
			$response['status']  = false;

			return $response;
		} elseif ( 'succeeded' === $payment_status ) {
			$member_order = $this->members_orders_repository->get_member_orders( $member_id );

			// The retrieved PaymentIntent must belong to the order being completed. Verification runs
			// against the order that owns the transaction while completion applies to the member's newest
			// order; without this guard a succeeded PaymentIntent from an earlier order can be replayed to
			// complete a newer pending order, renewing/extending a membership with no new payment.
			if ( empty( $member_order ) || (int) $latest_order['ID'] !== (int) $member_order['ID'] ) {
				return $this->update_order_error(
					$response,
					__( 'Payment verification failed.', 'user-registration' ),
					'Payment confirmation rejected: PaymentIntent does not belong to the order being completed',
					array(
						'error_code'        => 'PAYMENT_INTENT_ORDER_MISMATCH',
						'member_id'         => $member_id,
						'payment_intent_id' => $pi_id,
						'verified_order_id' => $latest_order['ID'],
						'target_order_id'   => $member_order['ID'] ?? 0,
					)
				);
			}

			if ( 'completed' === $member_order['status'] ) {
				$response['message'] = $is_upgrading ? __( 'Membership upgraded successfully.', 'user-registration' ) : get_option( 'user_registration_successful_membership_creation_message', esc_html__( 'New member has been successfully created.', 'user-registration' ) );
				$response['status']  = true;

				return $response;
			}

			$membership                     = $this->membership_repository->get_single_membership_by_ID( $member_order['item_id'] );
			$membership_metas               = wp_unslash( json_decode( $membership['meta_value'], true ) );
			$membership_metas['post_title'] = $membership['post_title'];
			$member_subscription            = $this->members_subscription_repository->get_subscription_data_by_member_and_membership_id( $member_id, $member_order['item_id'] );
			$is_order_updated               = $this->members_orders_repository->update(
				$member_order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $transaction_id,
				)
			);

			if ( $is_order_updated && in_array( $member_order['order_type'], array( 'paid', 'subscription' ), true ) ) {
				$this->members_subscription_repository->update(
					$member_subscription['ID'],
					array(
						'status'     => 'active',
						'start_date' => date( 'Y-m-d 00:00:00' ),
					)
				);

				if ( $is_upgrading ) {
					PaymentGatewayLogging::log_general(
						'stripe',
						'Upgrade payment completed - Order status changed to completed' . "\n" . wp_json_encode(
							array(
								'event_type'      => 'upgrade_payment_completed',
								'old_status'      => $member_order['status'] ?? 'unknown',
								'new_status'      => 'completed',
								'order_id'        => $member_order['ID'],
								'member_id'       => $member_id,
								'transaction_id'  => $transaction_id,
								'membership_type' => $membership_type,
							),
							JSON_PRETTY_PRINT
						),
						'success'
					);
				} else {
					PaymentGatewayLogging::log_general(
						'stripe',
						'Order status changed to completed' . "\n" . wp_json_encode(
							array(
								'event_type'      => 'status_change',
								'old_status'      => $member_order['status'] ?? 'unknown',
								'new_status'      => 'completed',
								'order_id'        => $member_order['ID'],
								'member_id'       => $member_id,
								'transaction_id'  => $transaction_id,
								'membership_type' => $membership_type,
							),
							JSON_PRETTY_PRINT
						),
						'success'
					);
				}

				PaymentGatewayLogging::log_general(
					'stripe',
					'Subscription status changed to active' . "\n" . wp_json_encode(
						array(
							'event_type'      => $is_upgrading ? 'upgrade_subscription_activated' : 'status_change',
							'old_status'      => $member_subscription['status'] ?? 'unknown',
							'new_status'      => 'active',
							'subscription_id' => $member_subscription['ID'],
							'member_id'       => $member_id,
							'membership_type' => $membership_type,
							'is_upgrade'      => $is_upgrading,
						),
						JSON_PRETTY_PRINT
					),
					'success'
				);

				$response = $this->sendEmail( $member_order['ID'], $member_subscription, $membership_metas, $member_id, $response, $is_upgrading );
			}
		}

		return $response;
	}

	/**
	 * Sends an email.
	 *
	 * @param int   $id                 The ID of the order.
	 * @param mixed $member_subscription The subscription details of the member.
	 * @param array $membership_metas   Metadata related to the membership.
	 * @param int   $member_id          The ID of the member.
	 * @param array $response           The response data.
	 * @param bool  $is_upgrading       Whether this is an upgrade.
	 *
	 * @return array The result of the email operation.
	 */
	public function sendEmail( int $ID, $member_subscription, array $membership_metas, int $member_id, array $response, $is_upgrading = false ): array {
		$email_service = new EmailService();
		$order_detail  = $this->orders_repository->get_order_detail( $ID );

		if ( ! empty( $order_detail['coupon'] ) ) {
			$order_detail['coupon_discount']      = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount', true );
			$order_detail['coupon_discount_type'] = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount_type', true );
		}

		$email_data = array(
			'subscription'     => $member_subscription,
			'order'            => $order_detail,
			'membership_metas' => $membership_metas,
			'member_id'        => $member_id,
			'membership'       => $member_subscription['item_id'],
		);

		$mail_send = $email_service->send_email( $email_data, 'payment_successful' );

		if ( ! $mail_send ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Payment email could not be sent' . "\n" . wp_json_encode(
					array(
						'error_code' => 'EMAIL_SEND_FAILED',
						'member_id'  => $member_id,
						'order_id'   => $ID,
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		$email_service->send_email( $email_data, 'payment_successful_admin' );

		$reg_data = get_user_meta( $member_id, 'ur_membership_registration_data', true );
		if ( ! empty( $reg_data ) && empty( get_user_meta( $member_id, 'ur_membership_welcome_email_sent', true ) ) ) {
			do_action( 'urm_member_registered', $reg_data, $member_id );
			update_user_meta( $member_id, 'ur_membership_welcome_email_sent', true );
		}

		return array(
			'message' => $is_upgrading ? __( 'Membership upgraded successfully.', 'user-registration' ) : get_option( 'user_registration_successful_membership_creation_message', esc_html__( 'New member has been successfully created.', 'user-registration' ) ),
			'status'  => true,
		);
	}

	/**
	 * Create Stripe subscription.
	 *
	 * @param string $customer_id       Customer ID.
	 * @param string $payment_method_id Payment method ID.
	 * @param int    $member_id         Member ID.
	 * @param bool   $is_upgrading      Whether this is an upgrade.
	 * @param int    $team_id           Team ID.
	 * @return array|\WP_Error
	 */
	public function create_subscription( $customer_id, $payment_method_id, $member_id, $is_upgrading, $team_id ) {
		self::ensure_webhook_for_current_mode();
		$member_order     = $this->members_orders_repository->get_member_orders( $member_id );
		$membership       = $this->membership_repository->get_single_membership_by_ID( $member_order['item_id'] );
		$membership_metas = wp_unslash( json_decode( $membership['meta_value'], true ) );

		$membership_metas['post_title'] = $membership['post_title'];

		$member_subscription = $this->members_subscription_repository->get_subscription_data_by_member_and_membership_id( $member_id, $membership['ID'] );
		$is_automatic        = 'automatic' === get_option( 'user_registration_renewal_behaviour', 'automatic' );

		$membership_process = urm_get_membership_process( $member_id );
		$is_renewing        = ! empty( $membership_process['renew'] ) && in_array( $member_order['item_id'], $membership_process['renew'], true );

		$response = array(
			'status' => false,
		);

		if ( $team_id ) {
			$team_data = get_post_meta( $team_id, 'urm_team_data', true );
			if ( ! $team_data ) {
				PaymentGatewayLogging::log_error(
					'stripe',
					'Invalid team data' . "\n" . wp_json_encode(
						array(
							'error_code' => 'INVALID_TEAM_DATA',
							'member_id'  => $member_id,
						),
						JSON_PRETTY_PRINT
					)
				);

				return $response;
			}
			$membership_type = $team_data['team_plan_type'] ?? 'unknown';
			if ( 'one-time' === $membership_type ) {
				$membership_type = 'paid';
				PaymentGatewayLogging::log_error(
					'stripe',
					'Not a subscription membership' . "\n" . wp_json_encode(
						array(
							'error_code' => 'NOT_SUBSCRIPTION',
							'member_id'  => $member_id,
						),
						JSON_PRETTY_PRINT
					)
				);

				return $response;
			} else {
				$subscription_value    = $team_data['team_duration_value'];
				$subscription_duration = $team_data['team_duration_period'];
			}
		} else {
			$membership_type       = $membership_metas['type'] ?? 'unknown';
			$subscription_value    = $membership_metas['subscription']['value'];
			$subscription_duration = $membership_metas['subscription']['duration'];
		}

		PaymentGatewayLogging::log_api_request(
			'stripe',
			'Creating Stripe subscription',
			array(
				'customer_id'       => $customer_id,
				'payment_method_id' => $payment_method_id,
				'member_id'         => $member_id,
				'is_upgrading'      => $is_upgrading,
				'membership_type'   => $membership_type,
			)
		);

		if ( empty( $member_subscription ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Subscription not found for member' . "\n" . wp_json_encode(
					array(
						'error_code' => 'SUBSCRIPTION_NOT_FOUND',
						'member_id'  => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);

			return $response;
		}

		$stripe_product_details = $membership_metas['payment_gateways']['stripe'] ?? array();

		if ( ! is_array( $stripe_product_details ) || empty( $stripe_product_details['price_id'] ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe product details missing or invalid for membership' . "\n" . wp_json_encode(
					array(
						'error_code'     => 'STRIPE_PRODUCT_NOT_CONFIGURED',
						'member_id'      => $member_id,
						'stripe_details' => is_array( $stripe_product_details ) ? $stripe_product_details : gettype( $stripe_product_details ),
					),
					JSON_PRETTY_PRINT
				)
			);
			$response['status']  = false;
			$response['message'] = __( 'Stripe is not properly configured for this membership. Please save the membership settings to re-sync with Stripe.', 'user-registration' );
			return $response;
		}

		try {

			$order_detail     = $this->orders_repository->get_order_detail( $member_order['ID'] );
			$order_repository = new OrdersRepository();
			$local_currency   = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_detail['order_id'], 'local_currency' );
			$tax_data_meta    = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_detail['order_id'], 'tax_data' );
			$tax_data         = ! empty( $tax_data_meta['meta_value'] ) ? json_decode( $tax_data_meta['meta_value'], true ) : array();

			$currency = ! empty( $local_currency['meta_value'] )
				? strtoupper( $local_currency['meta_value'] )
				: strtoupper( get_option( 'user_registration_payment_currency', 'USD' ) );

			$total_amount = ! empty( $member_order['total_amount'] )
				? (float) $member_order['total_amount']
				: 0.0;

			if ( in_array( $currency, array( 'JPY', 'KRW', 'VND', 'CLP', 'IDR' ), true ) ) {
				$total_amount = (int) round( $total_amount );
			} else {
				$total_amount = (int) round( $total_amount * 100 );
			}

			$customer            = \Stripe\Customer::retrieve( $customer_id );
			$payment_method      = \Stripe\PaymentMethod::retrieve( $payment_method_id );
			$current_stripe_mode = self::get_stripe_settings()['mode'];
			if ( ! $payment_method->livemode && 'live' === $current_stripe_mode ) {
				$response['status']  = false;
				$response['message'] = __( 'Test card numbers are not accepted in live mode. Please use a real payment card.', 'user-registration' );
				return $response;
			}

			if ( $payment_method->livemode && 'test' === $current_stripe_mode ) {
				$response['status']  = false;
				$response['message'] = __( 'Real card numbers are not accepted in test mode. Please use a Stripe test card.', 'user-registration' );
				return $response;
			}
			$payment_method->attach(
				array(
					'customer' => $customer->id,
				)
			);

			\Stripe\Customer::update(
				$customer->id,
				array(
					'invoice_settings' => array(
						'default_payment_method' => $payment_method->id,
					),
				)
			);

			// Re-resolve the Stripe price for team pricing, local currency, or a missing stored price_id.
			$is_local_currency_subscription = ! $team_id && ! empty( $local_currency['meta_value'] );
			$stored_price_id                = $stripe_product_details['price_id'] ?? '';

			// Use the pre-tax amount so Stripe applies the tax rate on top (visible on its invoice).
			$price_amount = $total_amount;
			if ( $is_local_currency_subscription && ! empty( $tax_data['tax_rate'] ) ) {
				$price_amount = (int) round( $total_amount / ( 1 + floatval( $tax_data['tax_rate'] ) / 100 ) );
			}

			if ( $team_id || $is_local_currency_subscription || empty( $stored_price_id ) ) {
				$effective_price_id = $this->ensure_price_in_stripe(
					'subscription',
					$stripe_product_details['product_id'] ?? '',
					$stored_price_id,
					$price_amount,
					$currency,
					array(
						'subscription_duration' => $subscription_duration,
						'subscription_value'    => $subscription_value,
					)
				);
			} else {
				$effective_price_id = $stored_price_id;
			}

			// UR-4386: 100% coupon on a subscription => first period free. Create a Stripe
			// Subscription Schedule starting one billing period out (nothing charged now; full
			// price bills from the next cycle) instead of a normal subscription.
			$is_full_discount_subscription = ( ! $team_id )
				&& ( 'subscription' === $membership_type )
				&& ( 0.0 === (float) $member_order['total_amount'] )
				&& ! empty( $order_detail['coupon'] )
				&& ur_check_module_activation( 'coupon' );

			if ( $is_full_discount_subscription ) {
				// Server-side re-validation: the coupon must exist, be active, and truly zero the
				// plan amount, so a forged $0 order cannot obtain a free period.
				$coupon_details = ur_get_coupon_details( $order_detail['coupon'] );
				$plan_amount    = floatval( $membership_metas['amount'] ?? 0 );
				$discount_type  = $coupon_details['coupon_discount_type'] ?? 'fixed';
				$discount_value = floatval( $coupon_details['coupon_discount'] ?? 0 );
				$discount       = ( 'percent' === $discount_type ) ? ( $plan_amount * $discount_value / 100 ) : $discount_value;
				$is_zeroed      = ! empty( $coupon_details )
					&& ! empty( $coupon_details['coupon_status'] )
					&& ( $plan_amount > 0 )
					&& ( 0.0 === round( max( 0, $plan_amount - $discount ), 2 ) );

				if ( $is_zeroed ) {
					$start_ts      = ( new \DateTime( "+ {$subscription_value} {$subscription_duration}" ) )->getTimestamp();

					// UR-4386: the price above came from the discounted order total (0) => a $0 recurring
					// price for local-currency / missing-price subs. Re-resolve against the FULL amount.
					$schedule_full_amount = $plan_amount;
					if ( $is_local_currency_subscription ) {
						$lc_full = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_detail['order_id'], 'local_currency_converted_amount' );
						if ( ! empty( $lc_full['meta_value'] ) ) {
							$schedule_full_amount = (float) $lc_full['meta_value'];
						}
					}

					// Pre-tax minor units; tax is added on top via the phase's default_tax_rates.
					if ( in_array( $currency, array( 'JPY', 'KRW', 'VND', 'CLP', 'IDR' ), true ) ) {
						$schedule_price_minor = (int) round( $schedule_full_amount );
					} else {
						$schedule_price_minor = (int) round( $schedule_full_amount * 100 );
					}

					// Force a fresh price for local currency (stored price_id is in the global currency).
					$effective_price_id = $this->ensure_price_in_stripe(
						'subscription',
						$stripe_product_details['product_id'] ?? '',
						$is_local_currency_subscription ? '' : $stored_price_id,
						$schedule_price_minor,
						$currency,
						array(
							'subscription_duration' => $subscription_duration,
							'subscription_value'    => $subscription_value,
						)
					);

					// Single open-ended phase starting one billing period in the future: nothing is
					// charged until start_date, then the plan bills at full price indefinitely.
					$phase         = array(
						'items' => array(
							array( 'price' => $effective_price_id ),
						),
					);

					// Reflect membership tax on the scheduled subscription's invoices.
					if ( ! empty( $tax_data['tax_rate'] ) ) {
						$stripe_tax_rate_id = $this->get_or_create_stripe_tax_rate( floatval( $tax_data['tax_rate'] ) );
						if ( ! empty( $stripe_tax_rate_id ) ) {
							$phase['default_tax_rates'] = array( $stripe_tax_rate_id );
						}
					}

					$schedule_args = array(
						'customer'         => $customer->id,
						'start_date'       => $start_ts,
						'end_behavior'     => 'release',
						'phases'           => array( $phase ),
						'default_settings' => array(
							'default_payment_method' => $payment_method->id,
						),
					);

					try {
						$schedule = \Stripe\SubscriptionSchedule::create( $schedule_args );
					} catch ( \Exception $schedule_ex ) {
						// UR-4386: schedule not created -> keep order/subscription pending (no access);
						// don't delete the registered user.
						PaymentGatewayLogging::log_error(
							'stripe',
							'Stripe subscription schedule creation failed (100% coupon)' . "\n" . wp_json_encode(
								array(
									'error_code'    => 'STRIPE_SCHEDULE_CREATE_FAILED',
									'error_message' => $schedule_ex->getMessage(),
									'member_id'     => $member_id,
								),
								JSON_PRETTY_PRINT
							)
						);
						$this->members_orders_repository->update( $member_order['ID'], array( 'status' => 'pending' ) );
						$this->members_subscription_repository->update( $member_order['subscription_id'], array( 'status' => 'pending' ) );

						$response['subscription'] = array(
							'id'             => '',
							'status'         => 'pending',
							'latest_invoice' => array( 'payment_intent' => null ),
						);
						$response['message'] = __( 'Registration complete, but the subscription could not be scheduled yet. Your membership is pending.', 'user-registration' );
						$response['status']  = true;

						return $response;
					}

					PaymentGatewayLogging::log_api_response(
						'stripe',
						'Stripe subscription schedule created (100% coupon, delayed start)',
						array(
							'schedule_id' => $schedule->id,
							'start_date'  => $start_ts,
							'member_id'   => $member_id,
						)
					);

					// Store the schedule id; the webhook back-fills the real subscription id once it starts.
					update_user_meta( $member_id, 'urm_stripe_schedule_id', sanitize_text_field( $schedule->id ) );

					// UR-4386: schedule created (sub_sched_ returned) -> billing is set up, so activate
					// now. First charge is one period later; a later charge failure reverts to pending
					// via handle_failed_invoice().
					$this->members_orders_repository->update(
						$member_order['ID'],
						array(
							'status'         => 'completed',
							'transaction_id' => $schedule->id,
						)
					);

					$this->members_subscription_repository->update(
						$member_order['subscription_id'],
						array(
							'subscription_id' => sanitize_text_field( $schedule->id ),
							'status'          => 'active',
						)
					);

					$this->sendEmail( $member_order['ID'], $member_subscription, $membership_metas, $member_id, $response );

					// Subscription-shaped payload the checkout JS expects (active + null payment_intent => no SCA).
					$response['subscription'] = array(
						'id'             => $schedule->id,
						'status'         => 'active',
						'schedule'       => $schedule->id,
						'latest_invoice' => array( 'payment_intent' => null ),
					);
					$response['message'] = __( 'New member has been successfully created. The subscription is scheduled to start after the free coupon period.', 'user-registration' );
					$response['status']  = true;

					return $response;
				}
			}

			$subscription_details = array(
				'customer'         => $customer->id,
				'items'            => array(
					array(
						'price' => $effective_price_id,
					),
				),
				'payment_settings' => array(
					'save_default_payment_method' => 'on_subscription',
				),
			);

			$subscription_details['expand'] = array( 'latest_invoice.payments' );

			// Handle trial period.
			if ( isset( $membership_metas['trial_status'] ) && 'on' === $membership_metas['trial_status'] && ! $team_id ) {
				$trail_period                      = strtotime( date( 'Y-m-d H:i:s', strtotime( '+' . $membership_metas['trial_data']['value'] . ' ' . $membership_metas['trial_data']['duration'] ) ) );
				$subscription_details['trial_end'] = $trail_period;
			}

			// Handle coupon section.
			if ( ! empty( $order_detail['coupon'] ) ) {
				$coupon_details = ur_get_coupon_details( $order_detail['coupon'] );
				if ( ! empty( $coupon_details['stripe_coupon_id'] ) ) {
					$subscription_details['discounts'] = array( array( 'coupon' => $coupon_details['stripe_coupon_id'] ) );
				}
			}

			// Apply tax rate to Stripe subscription so tax is reflected in Stripe invoices.
			if ( ! empty( $tax_data['tax_rate'] ) ) {
				$stripe_tax_rate_id = $this->get_or_create_stripe_tax_rate( floatval( $tax_data['tax_rate'] ) );
				if ( ! empty( $stripe_tax_rate_id ) ) {
					$subscription_details['default_tax_rates'] = array( $stripe_tax_rate_id );
				}
			}

			if ( $is_upgrading ) {
				PaymentGatewayLogging::log_general(
					'stripe',
					'Processing Stripe membership upgrade' . "\n" . wp_json_encode(
						array(
							'event_type'      => 'upgrade_processing',
							'member_id'       => $member_id,
							'membership_type' => $membership_type,
						),
						JSON_PRETTY_PRINT
					),
					'notice'
				);

				$previous_subscription = json_decode( get_user_meta( $member_id, 'urm_previous_subscription_data', true ), true );

				if ( $previous_subscription ) {
					$previous_membership = $this->membership_repository->get_single_membership_by_ID( $previous_subscription['item_id'] );

					if ( ! empty( $previous_membership['meta_value'] ) ) {
						$previous_membership_metas = json_decode( wp_unslash( $previous_membership['meta_value'] ), true );

						if ( isset( $previous_membership_metas['type'], $previous_membership_metas['amount'] ) && 'free' !== $previous_membership_metas['type'] ) {
							$new_price     = isset( $membership_metas['amount'] ) ? (float) $membership_metas['amount'] : 0;
							$current_price = (float) $previous_membership_metas['amount'];

							// Convert both plans' base prices into $currency (resolved above from the order).
							$global_currency = strtoupper( get_option( 'user_registration_payment_currency', 'USD' ) );

							if ( $currency !== $global_currency && class_exists( CoreFunctions::class ) ) {
								if ( ! empty( $membership_metas['local_currency'] ) && ur_string_to_bool( $membership_metas['local_currency']['is_enable'] ) ) {
									$new_zone_id = CoreFunctions::ur_get_zone_id_by_currency( $membership_metas['local_currency'], $currency );
									if ( ! empty( $new_zone_id ) ) {
										$new_pricing_data = CoreFunctions::ur_get_pricing_zone_by_id( $new_zone_id );
										$new_price        = CoreFunctions::ur_get_amount_after_conversion( $new_price, $currency, $new_pricing_data, $membership_metas['local_currency'], $new_zone_id );
									}
								}

								if ( ! empty( $previous_membership_metas['local_currency'] ) && ur_string_to_bool( $previous_membership_metas['local_currency']['is_enable'] ) ) {
									$current_zone_id = CoreFunctions::ur_get_zone_id_by_currency( $previous_membership_metas['local_currency'], $currency );
									if ( ! empty( $current_zone_id ) ) {
										$current_pricing_data = CoreFunctions::ur_get_pricing_zone_by_id( $current_zone_id );
										$current_price        = CoreFunctions::ur_get_amount_after_conversion( $current_price, $currency, $current_pricing_data, $previous_membership_metas['local_currency'], $current_zone_id );
									}
								}
							}

							$previous_membership_metas['amount'] = $current_price;

							$membership_upgrade_service      = new UpgradeMembershipService();
							$previous_membership_metas['ID'] = $previous_membership['ID'];
							$upgrade_details                 = $membership_upgrade_service->get_upgrade_details( $previous_membership_metas );
							$upgrade_type                    = $upgrade_details['upgrade_type'] ?? '';
							$first_month_price               = $new_price - $current_price;

							if ( 'full' === $upgrade_type ) {
								$first_month_price = $new_price;
							}

							if ( ( $new_price > $current_price ) && ( ! empty( $order_detail['coupon'] ) || 'pro-rata' == $upgrade_type ) ) {
								if ( isset( $order_detail['coupon'] ) && ! empty( $order_detail['coupon'] ) && ur_check_module_activation( 'coupon' ) ) {
									$coupon_details  = ur_get_coupon_details( $order_detail['coupon'] );
									$discount_amount = ( 'fixed' === $coupon_details['coupon_discount_type'] ) ? $coupon_details['coupon_discount'] : $first_month_price * $coupon_details['coupon_discount'] / 100;

									if ( 'full' === $upgrade_type ) {
										$amount = $discount_amount;
									} else {
										$amount = $current_price + $discount_amount;
									}
								} elseif ( 'pro-rata' === $upgrade_type ) {
									$proration_result = $membership_upgrade_service->handle_subscription_to_paid_or_subscription_membership_upgrade(
										$previous_membership_metas,
										$membership_metas,
										$previous_subscription,
										false
									);
									$chargeable       = isset( $proration_result['chargeable_amount'] ) ? floatval( $proration_result['chargeable_amount'] ) : floatval( $new_price );
									$amount           = max( 0, $new_price - $chargeable );
								}

								$amount = ( 'JPY' === $currency ) ? (int) round( $amount ) : (int) round( $amount * 100 );

								PaymentGatewayLogging::log_general(
									'stripe',
									'Creating upgrade discount coupon' . "\n" . wp_json_encode(
										array(
											'event_type' => 'upgrade_coupon_created',
											'member_id'  => $member_id,
											'old_price'  => $current_price,
											'new_price'  => $new_price,
											'discount_amount' => $amount,
										),
										JSON_PRETTY_PRINT
									),
									'debug'
								);

								$coupon = \Stripe\Coupon::create(
									array(
										'amount_off' => $amount,
										'currency'   => strtolower( $currency ),
										'duration'   => 'once',
										'name'       => 'UpgradeCoupon',
									)
								);

								$subscription_details['discounts'] = array( array( 'coupon' => $coupon->id ) );
							}
						}
					}
				}

				$next_subscription = json_decode( get_user_meta( $member_id, 'urm_next_subscription_data', true ), true );
				if ( ! empty( $next_subscription['delayed_until'] ) ) {
					$subscription_details['trial_end'] = strtotime( $next_subscription['delayed_until'] );

					PaymentGatewayLogging::log_general(
						'stripe',
						'Upgrade scheduled with delayed start' . "\n" . wp_json_encode(
							array(
								'event_type'    => 'upgrade_delayed_start',
								'member_id'     => $member_id,
								'delayed_until' => $next_subscription['delayed_until'],
							),
							JSON_PRETTY_PRINT
						),
						'notice'
					);
				} elseif (
					! empty( $previous_subscription['trial_end_date'] ) &&
					isset( $membership_metas['type'], $membership_metas['trial_status'] ) &&
					'subscription' === $membership_metas['type'] &&
					'on' === $membership_metas['trial_status']
				) {
					$subscription_details['trial_end'] = strtotime( $previous_subscription['trial_end_date'] );

					PaymentGatewayLogging::log_general(
						'stripe',
						'Upgrade preserving trial period' . "\n" . wp_json_encode(
							array(
								'event_type'     => 'upgrade_trial_preserved',
								'member_id'      => $member_id,
								'trial_end_date' => $previous_subscription['trial_end_date'],
							),
							JSON_PRETTY_PRINT
						),
						'notice'
					);
				}
			}

			if ( ( ! $is_automatic && ! $is_upgrading ) || $is_renewing ) {
				$value    = $subscription_value;
				$duration = $subscription_duration;

				$subscription_details['cancel_at'] = ( new \DateTime( "+ $value $duration" ) )->getTimestamp();
				if ( $is_renewing ) {
					$next_billing_date = new \DateTime( $member_subscription['next_billing_date'] );

					// Reset next billing date to today if it is in the past.
					$today = new \DateTime( 'today' );
					if ( $next_billing_date < $today ) {
						$next_billing_date = $today;
					}

					$next_billing_date                 = $next_billing_date->modify( "+ $value $duration" )->getTimestamp();
					$subscription_details['cancel_at'] = $next_billing_date;
				}
			}

			PaymentGatewayLogging::log_debug(
				'stripe',
				'Creating subscription with details',
				array(
					'price_id'    => $stripe_product_details['price_id'],
					'customer_id' => $customer_id,
					'member_id'   => $member_id,
					'has_trial'   => isset( $subscription_details['trial_end'] ),
					'has_coupon'  => isset( $subscription_details['discounts'] ),
				)
			);

			$subscription        = \Stripe\Subscription::create( $subscription_details );
			$subscription_status = $subscription->status ?? '';
			PaymentGatewayLogging::log_api_response(
				'stripe',
				'Stripe subscription created',
				array(
					'subscription_id'     => $subscription->id,
					'subscription_status' => $subscription_status,
					'member_id'           => $member_id,
					'membership_type'     => $membership_type,
				)
			);

			$payments_data  = isset( $subscription->latest_invoice->payments->data ) ? (array) $subscription->latest_invoice->payments->data : array();
			$payment_intent = ! empty( $payments_data ) ? ( $payments_data[0]->payment->payment_intent ?? null ) : null;

			if ( empty( $payment_intent ) && isset( $subscription->latest_invoice->payment_intent ) ) {
				$payment_intent = $subscription->latest_invoice->payment_intent;
			}

			// Retrieve the PaymentIntent (newer Stripe API returns only an id) and expose it on the
			// invoice so the front-end can run 3D Secure / SCA.
			if ( ! empty( $payment_intent ) && is_string( $payment_intent ) ) {
				try {
					$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent );
				} catch ( \Exception $e ) {
					$payment_intent = null;
				}
			}

			if ( ! empty( $payment_intent ) && ! is_string( $payment_intent ) && isset( $subscription->latest_invoice ) ) {
				$subscription->latest_invoice->payment_intent = $payment_intent;
			}

			$three_ds2_source = '';
			if ( ! empty( $payment_intent ) && ! is_string( $payment_intent ) ) {
				$three_ds2_source = $payment_intent->next_action->use_stripe_sdk->three_d_secure_2_source ?? '';
			}

			if ( 'active' === $subscription_status || 'trialing' === $subscription_status || ( 'incomplete' === $subscription_status && ! empty( $three_ds2_source ) ) ) {
				$status = ( 'incomplete' === $subscription_status && ! empty( $three_ds2_source ) ) ? 'pending' : 'completed';
				$pi_id  = '';
				if ( ! empty( $payment_intent ) ) {
					$pi_id = is_string( $payment_intent ) ? $payment_intent : ( $payment_intent->id ?? '' );
				}
				$transaction_id = ! empty( $pi_id ) ? $pi_id : $subscription->id;
				$this->members_orders_repository->update(
					$member_order['ID'],
					array(
						'status'         => $status,
						'transaction_id' => $transaction_id,
					)
				);

				switch ( $subscription_status ) {
					case 'trialing':
						$subscription_status = ( $is_upgrading && ! empty( $next_subscription['delayed_until'] ) ) ? 'active' : 'trial';
						break;
					case 'incomplete':
						if ( ! empty( $three_ds2_source ) ) {
							$subscription_status = 'pending';
						}
						break;
					default:
						break;
				}

				$this->members_subscription_repository->update(
					$member_order['subscription_id'],
					array(
						'subscription_id' => sanitize_text_field( $subscription->id ),
						'status'          => $subscription_status,
					)
				);

				PaymentGatewayLogging::log_debug(
					'stripe',
					'[Member ID #' . $member_id . '] Subscription record updated with Stripe subscription ID' . "\n" . wp_json_encode(
						array(
							'local_subscription_id'  => $member_order['subscription_id'],
							'stripe_subscription_id' => $subscription->id,
							'subscription_status'    => $subscription_status,
						),
						JSON_PRETTY_PRINT
					)
				);

				// Send the confirmation email / log success only once the first invoice is paid.
				// On 3D Secure/SCA the order is still pending here; the email is sent later in handle_stripe_payment_confirmation().
				if ( 'completed' === $status ) {
					PaymentGatewayLogging::log_transaction_success(
						'stripe',
						'Stripe subscription activated successfully',
						array(
							'subscription_id'     => $subscription->id,
							'subscription_status' => $subscription_status,
							'order_id'            => $member_order['ID'],
							'member_id'           => $member_id,
							'membership_type'     => $membership_type,
						)
					);

					$this->sendEmail( $member_order['ID'], $member_subscription, $membership_metas, $member_id, $response );

					$response['message'] = get_option( 'user_registration_successful_membership_creation_message', esc_html__( 'New member has been successfully created.', 'user-registration' ) );
				} else {
					PaymentGatewayLogging::log_general(
						'stripe',
						'Stripe subscription created, awaiting 3D Secure confirmation' . "\n" . wp_json_encode(
							array(
								'subscription_id'     => $subscription->id,
								'subscription_status' => $subscription_status,
								'order_id'            => $member_order['ID'],
								'member_id'           => $member_id,
								'membership_type'     => $membership_type,
							),
							JSON_PRETTY_PRINT
						),
						'notice'
					);

					$response['message'] = __( 'Payment requires additional verification.', 'user-registration' );
				}

				$response['subscription'] = $subscription;
				$response['status']       = true;
			} elseif ( 'incomplete' === $subscription_status ) {
				PaymentGatewayLogging::log_general(
					'stripe',
					'Stripe subscription requires 3D Secure verification' . "\n" . wp_json_encode(
						array(
							'subscription_id'     => $subscription->id,
							'subscription_status' => $subscription_status,
							'member_id'           => $member_id,
							'membership_type'     => $membership_type,
							'requires_action'     => true,
						),
						JSON_PRETTY_PRINT
					),
					'notice'
				);

				$this->members_subscription_repository->update(
					$member_order['subscription_id'],
					array(
						'subscription_id' => sanitize_text_field( $subscription->id ),
						'status'          => 'pending',
					)
				);

				$response['subscription'] = $subscription;
				$response['message']      = __( 'Payment requires additional verification.', 'user-registration' );
				$response['status']       = true;
			}

			return $response;
		} catch ( \Exception $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe subscription creation failed' . "\n" . wp_json_encode(
					array(
						'error_code'      => 'STRIPE_SUBSCRIPTION_ERROR',
						'error_message'   => $e->getMessage(),
						'member_id'       => $member_id,
						'membership_type' => $membership_type,
					),
					JSON_PRETTY_PRINT
				)
			);

			if ( ! $is_upgrading && ! $is_renewing ) {
				if ( absint( $member_id ) === get_current_user_id() || current_user_can( 'edit_users' ) ) {
					wp_delete_user( absint( $member_id ) );
				}
				$this->members_orders_repository->delete_member_order( $member_id );
				$customer = \Stripe\Customer::retrieve( $customer_id );
				$customer->delete();
			}

			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Sync coupon to Stripe.
	 *
	 * @param array $data            Coupon data.
	 * @param array $old_coupon_data Old coupon data.
	 *
	 * @return array
	 * @throws ApiErrorException When Stripe API error occurs.
	 */
	public function sync_coupon( $data, $old_coupon_data ) {

		$currency         = get_option( 'user_registration_payment_currency', 'USD' );
		$all_coupon       = \Stripe\Coupon::all();
		$coupon_exists    = false;
		$stripe_coupon_id = '';
		foreach ( $all_coupon->data as $key => $coupon ) {
			if ( isset( $coupon->metadata->coupon ) && $coupon->metadata->coupon === strtolower( $data['post_data']['post_content'] ) ) {
				$coupon_exists    = true;
				$stripe_coupon_id = $coupon->id;
			}
		}
		// Delete coupon if new changes introduced.
		if ( $coupon_exists && ( $old_coupon_data['coupon_discount'] !== $data['post_meta_data']['coupon_discount'] || $old_coupon_data['coupon_discount_type'] !== $data['post_meta_data']['coupon_discount_type'] ) ) {
			$coupon = \Stripe\Coupon::retrieve( $stripe_coupon_id );
			$coupon->delete();
		}
		$amount = $data['post_meta_data']['coupon_discount'];
		if ( 'JPY' === $currency ) {
			$amount = (int) round( abs( $amount ) );
		} else {
			$amount = (int) round( abs( $amount ) * 100 );
		}

		$coupon_details = array(
			'currency' => strtolower( $currency ),
			'duration' => 'once',
			'metadata' => array(
				'coupon' => strtolower( $data['post_data']['post_content'] ),
			),
		);
		if ( 'fixed' === $data['post_meta_data']['coupon_discount_type'] ) {
			$coupon_details['amount_off'] = $amount;
		} elseif ( 'percent' === $data['post_meta_data']['coupon_discount_type'] ) {
			$coupon_details['percent_off'] = $data['post_meta_data']['coupon_discount'];
		}
		$coupon           = \Stripe\Coupon::create( $coupon_details );
		$stripe_coupon_id = $coupon->id;

		$data['post_meta_data']['stripe_coupon_id'] = $stripe_coupon_id;

		return $data;
	}

	/**
	 * Cancel Stripe subscription.
	 *
	 * @param array $order        Order data.
	 * @param array $subscription Subscription data.
	 * @return array
	 */
	public function cancel_subscription( $order, $subscription ) {

		$response = array(
			'status' => false,
		);

		if ( empty( $subscription['subscription_id'] ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe subscription ID not found for cancellation' . "\n" . wp_json_encode(
					array(
						'error_code' => 'MISSING_SUBSCRIPTION_ID',
						'order_id'   => $order['ID'] ?? 'unknown',
					),
					JSON_PRETTY_PRINT
				)
			);

			return $response;
		}

		PaymentGatewayLogging::log_general(
			'stripe',
			'Cancelling Stripe subscription' . "\n" . wp_json_encode(
				array(
					'event_type'      => 'cancellation_initiated',
					'subscription_id' => $subscription['subscription_id'],
					'order_id'        => $order['ID'] ?? 'unknown',
				),
				JSON_PRETTY_PRINT
			),
			'notice'
		);

		try {
			$stripe_subscription = \Stripe\Subscription::retrieve( $subscription['subscription_id'] );
			if ( $stripe_subscription ) {
				$deleted_sub = \Stripe\Subscription::update(
					$subscription['subscription_id'],
					array(
						'cancel_at_period_end' => true,
					)
				);
			}
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Failed to cancel Stripe subscription' . "\n" . wp_json_encode(
					array(
						'error_code'      => 'STRIPE_CANCELLATION_ERROR',
						'subscription_id' => $subscription['subscription_id'],
						'error_message'   => $e->getMessage(),
						'order_id'        => $order['ID'] ?? 'unknown',
					),
					JSON_PRETTY_PRINT
				)
			);

			return $response;
		}

		if ( isset( $deleted_sub['canceled_at'] ) && '' !== $deleted_sub['canceled_at'] ) {
			$response['status'] = true;

			PaymentGatewayLogging::log_general(
				'stripe',
				'Subscription cancelled successfully' . "\n" . wp_json_encode(
					array(
						'event_type'           => 'cancellation_success',
						'subscription_id'      => $subscription['subscription_id'],
						'canceled_at'          => date( 'Y-m-d H:i:s', $deleted_sub['canceled_at'] ),
						'cancel_at_period_end' => $deleted_sub['cancel_at_period_end'] ?? false,
					),
					JSON_PRETTY_PRINT
				),
				'success'
			);
		}

		return $response;
	}

	/**
	 * Reactivates stripe subscription if it has been soft cancelled.
	 *
	 * @param string $subscription_id Stripe's Subscription Id.
	 *
	 * @return array $response array Response with status flag and message.
	 */
	public function reactivate_subscription( $subscription_id ) {
		$response           = array(
			'status' => false,
		);
		$subscription       = \Stripe\Subscription::retrieve( $subscription_id );
		$local_subscription = $this->members_subscription_repository->get_subscription_by_subscription_id_meta( $subscription_id );
		if ( isset( $subscription->id ) ) {

			if ( 'active' === $subscription->status ) {

				if ( true === $subscription->cancel_at_period_end ) {
					\Stripe\Subscription::update(
						$subscription_id,
						array(
							'cancel_at_period_end' => false,
						)
					);
				}

				return array(
					'status'  => true,
					'message' => __( 'Subscription reactivated successfully.', 'user-registration' ),
				);
			} elseif ( 'canceled' !== $subscription->status && true === $subscription->cancel_at_period_end ) {
				\Stripe\Subscription::update(
					$subscription_id,
					array(
						'cancel_at_period_end' => false,
					)
				);

				return array(
					'status'  => true,
					'message' => __( 'Subscription reactivated successfully.', 'user-registration' ),
				);
			} else {
				PaymentGatewayLogging::log_general(
					'stripe',
					'Subscription not reactivable' . "\n" . wp_json_encode(
						array(
							'subscription_id'     => $subscription_id,
							'subscription_status' => $subscription->status,
							'event_type'          => 'reactivation_failed',
						),
						JSON_PRETTY_PRINT
					),
					'info'
				);
			}
		} else {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Subscription not found in Stripe' . "\n" . wp_json_encode(
					array(
						'error_code'      => 'SUBSCRIPTION_NOT_FOUND_IN_STRIPE',
						'subscription_id' => $subscription_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			wp_send_json_error(
				array(
					'message' => __( 'Error reactivating the stripe subscription.', 'user-registration' ),
				)
			);
		}

		return $response;
	}

	/**
	 * Handle Stripe webhook.
	 *
	 * @param array  $event           Event data.
	 * @param string $subscription_id Subscription ID.
	 * @return void
	 */
	public function handle_webhook( $event, $subscription_id ) {
		// Verify that the event was sent by Stripe.
		if ( isset( $event['id'] ) ) {
			try {
				$event_id        = sanitize_text_field( $event['id'] );
				$event           = json_decode( wp_json_encode( \Stripe\Event::retrieve( $event_id ) ), true );
				$subscription_id = $event['data']['object']['subscription'] ?? $subscription_id;
			} catch ( \Exception $e ) {
				PaymentGatewayLogging::log_webhook_received(
					'stripe',
					$e->getMessage(),
					array(
						'webhook_type'    => 'invoice.payment_failed',
						'subscription_id' => $subscription_id,
						'event_id'        => $event['id'] ?? 'unknown',
					)
				);

				wp_die();
			}
		} else {
			wp_die();
		}
		switch ( $event['type'] ) {
			case 'invoice.payment_succeeded':
				$this->handle_succeeded_invoice( $event, $subscription_id );
				break;
			case 'invoice.payment_failed':
				$this->handle_failed_invoice( $event, $subscription_id );
				break;
			case 'payment_intent.payment_failed':
				$this->handle_failed_payment_intent( $event );
				break;
			case 'charge.refunded':
				$this->handle_refunded_charge( $event );
				break;
			case 'customer.subscription.created':
				$this->handle_scheduled_subscription_started( $event );
				break;
			default:
				break;
		}
	}

	/**
	 * Back-fill the real Stripe subscription id when a delayed-start Subscription Schedule
	 * (created for a 100% coupon, UR-4386) starts. Until it starts, the local order/subscription
	 * store the schedule id (sub_sched_...); this swaps in the materialized subscription id so
	 * renewals and cancellations keep working.
	 *
	 * @param array $event Stripe event.
	 * @return void
	 */
	public function handle_scheduled_subscription_started( $event ) {
		$object      = isset( $event['data']['object'] ) ? $event['data']['object'] : array();
		$schedule_id = isset( $object['schedule'] ) ? $object['schedule'] : '';
		$real_sub_id = isset( $object['id'] ) ? $object['id'] : '';

		// Only scheduled subscriptions carry a schedule id; ignore all other subscription creations.
		if ( empty( $schedule_id ) || empty( $real_sub_id ) ) {
			return;
		}

		$local = $this->members_subscription_repository->get_membership_by_subscription_id( $schedule_id, true );
		if ( empty( $local ) ) {
			return;
		}

		$this->members_subscription_repository->update(
			$local['sub_id'],
			array( 'subscription_id' => sanitize_text_field( $real_sub_id ) )
		);

		update_user_meta( $local['user_id'], 'ur_stripe_subscription_id', sanitize_text_field( $real_sub_id ) );

		PaymentGatewayLogging::log_general(
			'stripe',
			'Delayed-start schedule materialized; back-filled subscription id' . "\n" . wp_json_encode(
				array(
					'event_type'      => 'schedule_subscription_backfill',
					'schedule_id'     => $schedule_id,
					'subscription_id' => $real_sub_id,
					'member_id'       => $local['user_id'],
				),
				JSON_PRETTY_PRINT
			),
			'notice'
		);
	}

	/**
	 * Handle successful invoice webhook.
	 *
	 * @param array  $event           Event.
	 * @param string $subscription_id Subscription ID.
	 * @return void
	 */
	public function handle_succeeded_invoice( $event, $subscription_id ) {
		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			'Invoice payment succeeded webhook received',
			array(
				'webhook_type'    => 'invoice.payment_succeeded',
				'subscription_id' => $subscription_id,
				'event_id'        => $event['id'] ?? 'unknown',
			)
		);

		if ( null === $subscription_id ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Subscription ID is null in webhook' . "\n" . wp_json_encode(
					array(
						'error_code'   => 'NULL_SUBSCRIPTION_ID',
						'webhook_type' => 'invoice.payment_succeeded',
					),
					JSON_PRETTY_PRINT
				)
			);

			return;
		}

		$current_subscription = $this->members_subscription_repository->get_membership_by_subscription_id( $subscription_id, true );

		if ( empty( $current_subscription ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Subscription not found for subscription ID' . "\n" . wp_json_encode(
					array(
						'error_code'      => 'SUBSCRIPTION_NOT_FOUND',
						'subscription_id' => $subscription_id,
					),
					JSON_PRETTY_PRINT
				)
			);

			return;
		}
		// A 'trial' sub, or a 'pending' delayed-start sub (UR-4386 100% coupon), activates on its
		// first successful charge; anything else keeps its current status.
		$subscription_status = in_array( $current_subscription['status'], array( 'trial', 'pending' ), true ) ? 'active' : $current_subscription['status'];

		$member_id         = $current_subscription['user_id'];
		$membership_id     = $current_subscription['item_id'];
		$invoice_id        = $event['data']['object']['id'];
		$payment_intent_id = $event['data']['object']['payment_intent'] ?? null;
		$invoice_amount    = $event['data']['object']['amount_due']; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		// $0 trial invoices have no payment_intent. The initial order was already created
		// during subscription setup, so skip duplicate order creation here.
		if ( empty( $payment_intent_id ) ) {
			PaymentGatewayLogging::log_general(
				'stripe',
				'Skipping order creation for zero-amount trial invoice' . "\n" . wp_json_encode(
					array(
						'event_type'      => 'trial_invoice_skipped',
						'subscription_id' => $subscription_id,
						'invoice_id'      => $invoice_id,
						'member_id'       => $member_id,
					),
					JSON_PRETTY_PRINT
				),
				'notice'
			);
			return;
		}

		// Create new order.
		$order_info = array(
			'membership_data' => array(
				'membership'     => $membership_id,
				'payment_method' => 'stripe',
			),
		);

		$membership       = $this->membership_repository->get_single_membership_by_ID( $membership_id );
		$membership_metas = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$membership_type  = $membership_metas['type'] ?? 'unknown';

		$order_service    = new OrderService();
		$order_repository = new OrdersRepository();

		$existing_order = $order_repository->get_order_by_transaction_id( $payment_intent_id );
		if ( empty( $existing_order ) ) {
			// Fallback: if registration created an order before the payment_intent_id was persisted,
			// update that order instead of creating a duplicate.
			$existing_by_subscription = $order_repository->get_order_by_subscription( $current_subscription['sub_id'] );
			if ( ! empty( $existing_by_subscription ) && empty( $existing_by_subscription['transaction_id'] ) && 'on' !== ( $existing_by_subscription['trial_status'] ?? 'off' ) ) {
				PaymentGatewayLogging::log_general(
					'stripe',
					'Found existing order by subscription ID — updating instead of creating duplicate' . "\n" . wp_json_encode(
						array(
							'event_type'      => 'existing_order_updated_by_webhook',
							'order_id'        => $existing_by_subscription['ID'],
							'subscription_id' => $current_subscription['sub_id'],
							'transaction_id'  => $payment_intent_id,
						),
						JSON_PRETTY_PRINT
					),
					'notice'
				);
				$order_repository->update(
					$existing_by_subscription['ID'],
					array(
						'transaction_id' => sanitize_text_field( $payment_intent_id ),
						'status'         => 'completed',
					)
				);
				$order_id = $existing_by_subscription['ID'];
			} else {
				$order_data                                   = $order_service->prepare_orders_data( $order_info, $member_id, $current_subscription );
				$order_data['orders_data']['status']          = 'completed';
				$order_data['orders_data']['user_id']         = $member_id;
				$order_data['orders_data']['created_by']      = $member_id;
				$order_data['orders_data']['trial_status']    = 'off';
				$order_data['orders_data']['notes']           = sanitize_text_field( esc_html__( 'Generated with stripe webhook', 'user-registration' ) );
				$order_data['orders_data']['total_amount']    = $membership_metas['amount'];
				$order_data['orders_data']['transaction_id']  = $payment_intent_id ? sanitize_text_field( $payment_intent_id ) : '';
				$order_data['orders_data']['subscription_id'] = $current_subscription['sub_id'];

				$order_id = $order_repository->create( $order_data );
			}
		} else {
			$order_id = $existing_order['ID'] ?? $existing_order['id'] ?? '';
		}

		PaymentGatewayLogging::log_general(
			'stripe',
			'New renewal order created via webhook' . "\n" . wp_json_encode(
				array(
					'event_type'      => 'renewal_order_created',
					'order_id'        => $order_id,
					'subscription_id' => $subscription_id,
					'invoice_id'      => $invoice_id,
					'amount'          => $membership_metas['amount'],
					'member_id'       => $member_id,
					'membership_type' => $membership_type,
				),
				JSON_PRETTY_PRINT
			),
			'notice'
		);

		$next_billing_date = SubscriptionService::get_expiry_date( date( 'Y-m-d' ), $membership_metas['subscription']['duration'], $membership_metas['subscription']['value'] );

		$this->members_subscription_repository->update(
			$current_subscription['sub_id'],
			array(
				'start_date'        => date( 'Y-m-d 00:00:00' ),
				'next_billing_date' => $next_billing_date,
				'expiry_date'       => $next_billing_date,
				'status'            => $subscription_status,
			)
		);
		$membership_process = urm_get_membership_process( $member_id );
		$is_renewing        = ! empty( $membership_process['renew'] ) && in_array( $membership_id, $membership_process['renew'], true );

		if ( $is_renewing ) {
			$subscription_service = new SubscriptionService();
			$subscription_service->update_subscription_data_for_renewal( $current_subscription, $membership_metas );
		}

		PaymentGatewayLogging::log_webhook_processed(
			'stripe',
			'Subscription renewed successfully',
			array(
				'subscription_id'     => $subscription_id,
				'new_billing_date'    => $next_billing_date,
				'subscription_status' => $subscription_status,
				'member_id'           => $member_id,
				'membership_type'     => $membership_type,
			)
		);

		if ( $is_renewing ) {
			delete_transient( 'urm_pending_login_' . $member_id );
		}
	}

	/**
	 * Handle failed invoice webhook.
	 *
	 * @param array  $event           Event.
	 * @param string $subscription_id Subscription ID.
	 * @return void
	 */
	public function handle_failed_invoice( $event, $subscription_id ) {
		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			'Invoice payment failed webhook received',
			array(
				'webhook_type'    => 'invoice.payment_failed',
				'subscription_id' => $subscription_id,
				'event_id'        => isset( $event['id'] ) ? $event['id'] : 'unknown',
			)
		);

		if ( empty( $subscription_id ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Subscription ID is empty in invoice.payment_failed webhook' . "\n" . wp_json_encode(
					array(
						'error_code'   => 'NULL_SUBSCRIPTION_ID',
						'webhook_type' => 'invoice.payment_failed',
					),
					JSON_PRETTY_PRINT
				)
			);

			return;
		}

		$current_subscription = $this->members_subscription_repository->get_membership_by_subscription_id( $subscription_id, true );

		if ( empty( $current_subscription ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Subscription not found for subscription ID' . "\n" . wp_json_encode(
					array(
						'error_code'      => 'SUBSCRIPTION_NOT_FOUND',
						'subscription_id' => $subscription_id,
					),
					JSON_PRETTY_PRINT
				)
			);

			return;
		}

		$this->members_subscription_repository->update(
			$current_subscription['sub_id'],
			array(
				'status' => 'pending',
			)
		);

		$member_id = $current_subscription['user_id'];
		PaymentGatewayLogging::log_webhook_processed(
			'stripe',
			'Invoice payment failed handled',
			array(
				'subscription_id' => $subscription_id,
				'sub_id'          => $current_subscription['sub_id'],
				'member_id'       => $member_id,
			)
		);
		delete_transient( 'urm_pending_login_' . $member_id );
	}

	/**
	 * Handle failed payment intent webhook.
	 *
	 * @param array $event Event.
	 * @return void
	 */
	public function handle_failed_payment_intent( $event ) {
		$payment_intent    = isset( $event['data']['object'] ) ? $event['data']['object'] : array();
		$payment_intent_id = isset( $payment_intent['id'] ) ? $payment_intent['id'] : '';

		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			'Payment intent payment failed webhook received',
			array(
				'webhook_type'      => 'payment_intent.payment_failed',
				'payment_intent_id' => $payment_intent_id,
				'event_id'          => isset( $event['id'] ) ? $event['id'] : 'unknown',
			)
		);

		if ( empty( $payment_intent_id ) ) {
			return;
		}

		$order = $this->orders_repository->get_order_by_transaction_id( $payment_intent_id );

		$order_id = ( ! empty( $order ) && isset( $order['ID'] ) ) ? $order['ID'] : ( isset( $order['id'] ) ? $order['id'] : null );
		if ( $order_id ) {
			$this->orders_repository->update(
				$order_id,
				array(
					'status' => 'failed',
				)
			);
			PaymentGatewayLogging::log_webhook_processed(
				'stripe',
				'Order marked as failed for failed payment intent',
				array(
					'order_id'          => $order_id,
					'payment_intent_id' => $payment_intent_id,
				)
			);
		}
		delete_transient( 'urm_pending_login_' . $order['user_id'] );
	}

	/**
	 * Handle charge.refunded webhook: mark the linked order as refunded.
	 *
	 * @param array $event Stripe event array.
	 * @return void
	 */
	public function handle_refunded_charge( $event ) {
		$charge            = isset( $event['data']['object'] ) ? $event['data']['object'] : array();
		$payment_intent_id = isset( $charge['payment_intent'] ) ? $charge['payment_intent'] : null;

		PaymentGatewayLogging::log_webhook_received(
			'stripe',
			'Charge refunded webhook received',
			array(
				'webhook_type'      => 'charge.refunded',
				'payment_intent_id' => $payment_intent_id ?? 'unknown',
				'event_id'          => isset( $event['id'] ) ? $event['id'] : 'unknown',
			)
		);

		if ( empty( $payment_intent_id ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'charge.refunded webhook: no payment_intent on charge.',
				array( 'error_code' => 'NO_PAYMENT_INTENT' )
			);
			return;
		}

		$order = $this->orders_repository->get_order_by_transaction_id( $payment_intent_id );
		if ( empty( $order ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				sprintf( 'charge.refunded: no local order for PaymentIntent %s.', $payment_intent_id ),
				array(
					'error_code'        => 'ORDER_NOT_FOUND',
					'payment_intent_id' => $payment_intent_id,
				)
			);
			return;
		}

		$this->orders_repository->update( $order['ID'], array( 'status' => 'refunded' ) );

		PaymentGatewayLogging::log_webhook_processed(
			'stripe',
			sprintf( 'Order %d marked as refunded (PaymentIntent %s).', $order['ID'], $payment_intent_id ),
			array(
				'order_id'          => $order['ID'],
				'payment_intent_id' => $payment_intent_id,
			)
		);
	}

	/**
	 * Validate Stripe setup.
	 *
	 * @return bool
	 */
	public function validate_setup() {
		$stripe_settings = self::get_stripe_settings();

		return ( empty( $stripe_settings['publishable_key'] ) || empty( $stripe_settings['secret_key'] ) || empty( $stripe_settings['is_stipe_enabled'] ) );
	}

	/**
	 * Revert subscription.
	 *
	 * @param int $member_id Member ID.
	 *
	 * @return void
	 */
	public function revert_subscription( int $member_id ): void {
		$last_subscription = json_decode( get_user_meta( $member_id, 'urm_previous_subscription_data', true ), true );
		$subscription_id   = $last_subscription['ID'];
		unset( $last_subscription['ID'] );
		$last_order   = $this->members_orders_repository->get_member_orders( $member_id );
		$subscription = $this->members_subscription_repository->retrieve( $subscription_id );

		if ( 'stripe' === ( $last_order['payment_method'] ?? '' ) && $this->stripe_charge_succeeded( $last_order, $subscription ) ) {
			return;
		}

		$this->members_subscription_repository->update( $subscription_id, $last_subscription );
		$this->members_orders_repository->delete_member_order( $member_id, false );
		$refund_response = $this->refund( $last_order, $subscription ); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		delete_user_meta( $member_id, 'urm_previous_subscription_data' );
		$membership_process = urm_get_membership_process( $member_id );

		if ( ! empty( $membership_process ) && isset( $membership_process['upgrade'][ $_POST['current_membership_id'] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			unset( $membership_process['upgrade'][ $_POST['current_membership_id'] ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $member_id, 'urm_membership_process', $membership_process );
		}
	}

	private function stripe_charge_succeeded( $order, $subscription ) {
		try {
			if ( 'subscription' === ( $order['order_type'] ?? '' ) && ! empty( $subscription['subscription_id'] ) ) {
				$stripe_subscription = \Stripe\Subscription::retrieve( $subscription['subscription_id'] );
				return in_array( $stripe_subscription->status, array( 'active', 'trialing' ), true );
			}
			if ( ! empty( $order['transaction_id'] ) ) {
				$intent = \Stripe\PaymentIntent::retrieve( $order['transaction_id'] );
				return 'succeeded' === $intent->status;
			}
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			PaymentGatewayLogging::log_error( 'stripe', 'Failed to verify Stripe charge before revert: ' . $e->getMessage() );
		}
		return false;
	}

	/**
	 * Refund an order.
	 *
	 * @param array $order        Order data.
	 * @param array $subscription Subscription data.
	 * @return array
	 */
	public function refund( $order, $subscription ) {
		$response = array(
			'status' => false,
		);
		if ( 'stripe' !== $order['payment_method'] || 0 === absint( $order['total_amount'] ) ) {
			return $response;
		}
		if ( 'subscription' === $order['order_type'] ) {
			return $this->refund_subscription( $subscription, null, true );
		} else {
			return $this->refund_transaction( $order['transaction_id'] );
		}
	}

	/**
	 * Refund subscription.
	 *
	 * @param array    $subscription       Subscription data.
	 * @param int|null $refund_amount      Refund amount.
	 * @param bool     $cancel_subscription Whether to cancel subscription.
	 * @param bool     $cancel_at_end_period Whether to cancel at period end.
	 * @return array
	 */
	public function refund_subscription( $subscription, $refund_amount = null, $cancel_subscription = false, $cancel_at_end_period = false ) {
		try {
			$response        = array(
				'status' => false,
			);
			$subscription_id = $subscription['subscription_id'];
			if ( empty( $subscription_id ) ) {
				return $response;
			}

			$subscription = \Stripe\Subscription::retrieve( $subscription_id );

			$invoice_id = $subscription->latest_invoice;
			if ( ! $invoice_id ) {
				return $response;
			}
			$invoice = \Stripe\Invoice::retrieve( $invoice_id );

			$charge_id = $invoice->charge;
			if ( ! $charge_id ) {
				return $response;
			}

			$refund_params = array(
				'charge' => $charge_id,
			);
			if ( null !== $refund_amount ) {
				$refund_params['amount'] = $refund_amount;
			}

			$refund = \Stripe\Refund::create( $refund_params );

			$cancel_result = null;
			if ( $cancel_subscription ) {
				\Stripe\Subscription::update(
					$subscription_id,
					array(
						'cancel_at_period_end' => $cancel_at_end_period,
					)
				);

				if ( ! $cancel_at_end_period ) {
					$subscription->cancel();
				}
			}

			return array(
				'success'      => true,
				'refund'       => $refund,
				'cancellation' => $cancel_result,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Refund transaction using payment intent ID.
	 *
	 * @param string $payment_intent_id Payment intent ID.
	 * @return array
	 */
	public function refund_transaction( $payment_intent_id ) {
		try {
			// Full refund using payment intent.
			$refund = \Stripe\Refund::create(
				array(
					'payment_intent' => $payment_intent_id,
				)
			);

			return array(
				'success' => true,
				'refund'  => $refund,
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Checks if the product exists or not in stripe.
	 *
	 * @param string $product_id Product ID.
	 *
	 * @since 4.4.2
	 * @return array
	 */
	public function check_exists_product_in_stripe( $product_id ) {
		try {
			$product = \Stripe\Product::retrieve( $product_id );

			return array(
				'success' => true,
				'product' => $product,
			);
		} catch ( \Stripe\Exception\ApiErrorException $ex ) {
			return array(
				'success' => false,
				'message' => $ex->getMessage(),
			);
		} catch ( \Exception $ex ) {
			return array(
				'success' => false,
				'message' => $ex->getMessage(),
			);
		}
	}

	/**
	 * Checks if price id exists or not in stripe.
	 *
	 * @param string $price_id Price ID.
	 *
	 * @since 4.4.2
	 * @return array
	 */
	public function check_price_exists_in_stripe( $price_id ) {
		try {
			$price = \Stripe\Price::retrieve( $price_id );

			return array(
				'success' => true,
				'price'   => $price,
			);
		} catch ( \Stripe\Exception\ApiErrorException $ex ) {
			return array(
				'success' => false,
				'message' => $ex->getMessage(),
			);
		}
	}

	/**
	 * Creates price for existing product if price_id not found.
	 *
	 * @param string $product_id Product ID.
	 * @param array  $meta_data  Meta data.
	 *
	 * @since 4.4.2
	 * @return array
	 */
	public function create_stripe_price_for_existing_product( $product_id, $meta_data ) {
		$currency = get_option( 'user_registration_payment_currency', 'USD' );

		try {
			// Calculate amount in cents (or leave as-is for JPY).
			$amount = ( 'JPY' === $currency ) ? (int) round( abs( $meta_data['amount'] ) ) : (int) round( abs( $meta_data['amount'] ) * 100 );

			// Prepare price data.
			$price_details = array(
				'unit_amount' => $amount,
				'currency'    => strtolower( $currency ),
				'product'     => $product_id,
			);

			// Add recurring details if it's a subscription.
			if ( 'subscription' === $meta_data['type'] ) {
				$price_details['recurring'] = array(
					'interval'       => $meta_data['subscription']['duration'],
					'interval_count' => $meta_data['subscription']['value'],
				);
			}

			// Create new price in Stripe.
			$price = \Stripe\Price::create( $price_details );

			return array(
				'success' => true,
				'price'   => $price,
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Error creating Stripe price' . "\n" . wp_json_encode(
					array(
						'error_code'    => 'PRICE_CREATION_FAILED',
						'error_message' => $e->getMessage(),
					),
					JSON_PRETTY_PRINT
				)
			);

			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Retries subscription for Stripe subscription payments.
	 *
	 * @param array $subscription Subscription data.
	 * @return array
	 */
	public function retry_subscription( $subscription ) {
		$response = array(
			'status'  => false,
			'message' => '',
		);

		if ( empty( $subscription['sub_id'] ) ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe subscription ID not found for retry' . "\n" . wp_json_encode(
					array(
						'error_code' => 'MISSING_SUBSCRIPTION_ID',
					),
					JSON_PRETTY_PRINT
				)
			);

			$response['message'] = __( 'Subscription ID not found', 'user-registration' );

			return $response;
		}

		PaymentGatewayLogging::log_general(
			'stripe',
			'Retrying Stripe subscription payment' . "\n" . wp_json_encode(
				array(
					'event_type'      => 'retry_initiated',
					'subscription_id' => $subscription['sub_id'],
					'user_id'         => $subscription['user_id'] ?? 'unknown',
					'item_id'         => $subscription['item_id'] ?? 'unknown',
				),
				JSON_PRETTY_PRINT
			),
			'notice'
		);

		try {
			$stripe_subscription = \Stripe\Subscription::retrieve( $subscription['sub_id'] );

			if ( ! $stripe_subscription ) {
				PaymentGatewayLogging::log_error(
					'stripe',
					'Stripe subscription not found for retry' . "\n" . wp_json_encode(
						array(
							'subscription_id' => $subscription['sub_id'],
						),
						JSON_PRETTY_PRINT
					)
				);

				$response['message'] = __( 'Subscription not found in Stripe', 'user-registration' );

				return $response;
			}

			if ( in_array( $stripe_subscription->status, array( 'past_due', 'unpaid' ), true ) ) {
				// Update subscription to retry payment.
				$updated_subscription = \Stripe\Subscription::update(
					$subscription['sub_id'],
					array(
						'default_payment_method' => $stripe_subscription->default_payment_method,
						'off_session'            => true,
					)
				);

				PaymentGatewayLogging::log_api_response(
					'stripe',
					'Stripe subscription updated for retry',
					array(
						'subscription_id' => $subscription['sub_id'],
						'old_status'      => $stripe_subscription->status,
						'new_status'      => $updated_subscription->status,
					)
				);

				if ( 'active' === $updated_subscription->status || 'trialing' === $updated_subscription->status ) {
					PaymentGatewayLogging::log_transaction_success(
						'stripe',
						'Subscription payment retry successful',
						array(
							'subscription_id' => $subscription['sub_id'],
							'user_id'         => $subscription['user_id'] ?? 'unknown',
							'status'          => $updated_subscription->status,
						)
					);

					$response['status']  = true;
					$response['message'] = __( 'Subscription payment retried successfully', 'user-registration' );
				} else {
					PaymentGatewayLogging::log_error(
						'stripe',
						'Subscription payment retry - Unexpected status' . "\n" . wp_json_encode(
							array(
								'subscription_id' => $subscription['sub_id'],
								'status'          => $updated_subscription->status,
							),
							JSON_PRETTY_PRINT
						)
					);

					// Notify user via email about a failed retry attempt.
					$current_subscription = $this->members_subscription_repository->get_membership_by_subscription_id( $subscription['sub_id'], true );
					if ( ! empty( $current_subscription ) ) {
						$member_id = $current_subscription['user_id'];

						if ( 1 === (int) get_user_meta( $member_id, 'urm_is_payment_retrying', true ) ) {
							$latest_order     = $this->members_orders_repository->get_member_orders( $member_id );
							$membership       = $this->membership_repository->get_single_membership_by_ID( $current_subscription['item_id'] );
							$membership_metas = wp_unslash( json_decode( $membership['meta_value'], true ) );
							$email_service    = new EmailService();
							$email_data       = array(
								'subscription'     => $current_subscription,
								'order'            => $latest_order,
								'membership_metas' => $membership_metas,
								'member_id'        => $member_id,
							);
							$email_service->send_email( $email_data, 'payment_retry_failed' );
						}
						$response['message'] = __( 'Subscription retry did not resolve the issue', 'user-registration' );
					}
				}
			} elseif ( 'active' === $stripe_subscription->status || 'trialing' === $stripe_subscription->status ) {
				// Scenario: if automatic retry is enabled in stripe dashboard, it might be already active via smart retry.
				PaymentGatewayLogging::log_general(
					'stripe',
					'Subscription is already active - no retry needed' . "\n" . wp_json_encode(
						array(
							'subscription_id' => $subscription['sub_id'],
							'status'          => $stripe_subscription->status,
							'user_id'         => $subscription['user_id'] ?? 'unknown',
						),
						JSON_PRETTY_PRINT
					),
					'notice'
				);
				$response['status']  = true;
				$response['message'] = __( 'Subscription is already active', 'user-registration' );
			} else {
				PaymentGatewayLogging::log_error(
					'stripe',
					'Subscription cannot be recovered.' . "\n" . wp_json_encode(
						array(
							'subscription_id' => $subscription['sub_id'],
							'status'          => $stripe_subscription->status,
						),
						JSON_PRETTY_PRINT
					)
				);

				$response['message'] = sprintf( __( 'Subscription status is %s and cannot be retried', 'user-registration' ), $stripe_subscription->status );
			}

			return $response;
		} catch ( ApiErrorException $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe subscription retry failed' . "\n" . wp_json_encode(
					array(
						'error_code'      => $e->getStripeCode(),
						'error_message'   => $e->getMessage(),
						'subscription_id' => $subscription['subscription_id'],
						'user_id'         => $subscription['user_id'] ?? 'unknown',
					),
					JSON_PRETTY_PRINT
				)
			);

			$response['message'] = $e->getMessage();

			return $response;
		}
	}

	/**
	 * Validate Stripe API credentials.
	 *
	 * @return bool True if credentials are valid, false otherwise.
	 */
	public function validate_credentials() {
		try {
			\Stripe\Account::retrieve();
			PaymentGatewayLogging::log_general(
				'stripe',
				'Stripe credentials validated successfully' . "\n" . wp_json_encode(
					array(
						'event_type' => 'credential_validation',
					),
					JSON_PRETTY_PRINT
				),
				'success'
			);

			return true;
		} catch ( \Stripe\Exception\AuthenticationException $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe authentication failed - Invalid credentials' . "\n" . wp_json_encode(
					array(
						'error_code'    => 'AUTHENTICATION_FAILED',
						'error_message' => $e->getMessage(),
					),
					JSON_PRETTY_PRINT
				)
			);

			return false;
		} catch ( \Exception $e ) {

			PaymentGatewayLogging::log_error(
				'stripe',
				'Stripe credential validation failed' . "\n" . wp_json_encode(
					array(
						'error_code'    => 'VALIDATION_ERROR',
						'error_message' => $e->getMessage(),
					),
					JSON_PRETTY_PRINT
				)
			);

			return false;
		}
	}

	/**
	 * Calculate the amount to be sent to Stripe based on the currency and amount.
	 *
	 * @param int    $amount   The amount in the standard currency format (e.g., 10.00 for $10).
	 * @param string $currency The currency code (e.g., 'USD', 'JPY').
	 * @return int The amount in the smallest currency unit (e.g., cents for USD).
	 */
	private function calculate_stripe_amount( $amount, $currency ) {

		$zero_decimal = array( 'JPY', 'KRW', 'VND', 'CLP', 'IDR' );

		if ( in_array( strtoupper( $currency ), $zero_decimal, true ) ) {
			return (int) round( $amount );
		}

		return (int) round( $amount * 100 );
	}

	/**
	 * Ensure the price exists in Stripe and is up to date, otherwise create a new price.
	 *
	 * @param string $type             The type of the price, e.g., 'paid' or 'subscription'.
	 * @param string $product_id       The Stripe product ID to which the price is associated.
	 * @param string $price_id         The existing Stripe price ID, if any.
	 * @param int    $amount           The amount in the smallest currency unit.
	 * @param string $currency         The currency code (e.g., 'USD', 'JPY').
	 * @param array  $membership_metas The membership metadata which may contain subscription details.
	 * @return string The final price ID.
	 */
	private function ensure_price_in_stripe(
		$type,
		$product_id,
		$price_id,
		$amount,
		$currency,
		$membership_metas
	) {
		$interval = $membership_metas['subscription_duration']
			?? $membership_metas['subscription']['duration']
			?? null;

		$interval_count = (int) (
			$membership_metas['subscription_value']
			?? $membership_metas['subscription']['value']
			?? 1
		);

		// Create price if missing.
		if ( empty( $price_id ) ) {
			return $this->create_price_in_stripe(
				$type,
				$product_id,
				$amount,
				$currency,
				$interval,
				$interval_count
			);
		}

		// Try retrieving existing price.
		$existing_price = null;

		try {
			$existing_price = \Stripe\Price::retrieve( $price_id );
		} catch ( \Stripe\Exception\InvalidRequestException $e ) {
			return $this->create_price_in_stripe(
				$type,
				$product_id,
				$amount,
				$currency,
				$interval,
				$interval_count
			);
		}

		if ( ! $existing_price ) {
			return $this->create_price_in_stripe(
				$type,
				$product_id,
				$amount,
				$currency,
				$interval,
				$interval_count
			);
		}

		// Check if price amount has been updated.
		$needs_new = (
			$existing_price->unit_amount !== $amount
		);

		if ( empty( $existing_price->recurring ) && 'subscription' === $type ) {
			// If price is not recurring but type is subscription, we need to create a new price.
			$needs_new = true;
		} elseif ( ! empty( $existing_price->recurring ) && 'subscription' !== $type ) {
			// If price is recurring but type is not subscription, we need a new price.
			$needs_new = true;
		} elseif ( ! empty( $existing_price->recurring ) && 'subscription' === $type ) {
			// If price is recurring but interval or interval count has been updated, we need to create a new price.
			$needs_new = $needs_new || $existing_price->recurring->interval !== $interval ||
				$existing_price->recurring->interval_count !== $interval_count;
		}

		if ( $needs_new ) {
			return $this->create_price_in_stripe(
				$type,
				$product_id,
				$amount,
				$currency,
				$interval,
				$interval_count
			);
		}

		return $price_id;
	}

	/**
	 * Create a new price in Stripe for the given product with the specified details.
	 *
	 * @param string      $type           The type of the price, e.g., 'paid' or 'subscription'.
	 * @param string      $product_id     The Stripe product ID to which the price will be associated.
	 * @param int         $amount         The amount for the price in the smallest currency unit.
	 * @param string      $currency       The currency code (e.g., 'USD', 'JPY').
	 * @param string|null $interval       The interval for recurring prices (e.g., 'month', 'year').
	 * @param integer     $interval_count The number of intervals between billing cycles.
	 * @return string The ID of the newly created Stripe price.
	 */
	private function create_price_in_stripe(
		$type,
		$product_id,
		$amount,
		$currency,
		$interval = null,
		$interval_count = 1
	) {

		$data = array(
			'unit_amount' => $amount,
			'currency'    => $currency,
			'product'     => $product_id,
		);

		if ( 'subscription' === $type ) {
			if ( 'year' === $interval && $interval_count > 3 ) {
				throw new \Exception(
					__( 'Stripe does not support yearly subscription periods greater than 3 years.', 'user-registration' )
				);
			}
			$data['recurring'] = array(
				'interval'       => $interval,
				'interval_count' => $interval_count,
			);
		}

		$price = \Stripe\Price::create( $data );

		return $price->id;
	}

	/**
	 * Backfill missed subscription update record.
	 *
	 * @param string $last_synced Last synced timestamp to fetch events from.
	 * @return void
	 */
	public function run_missed_subscription_backfill( $last_synced ) {
		$logger = ur_get_logger();
		$logger->info( '[Backfill][Stripe][Status] ---------- STARTED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][Stripe][Status] Starting subscription status sync.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => gmdate( 'Y-m-d H:i:s', $last_synced ),
					'window_end'   => gmdate( 'Y-m-d H:i:s' ),
					'event_types'  => array( 'customer.subscription.updated', 'customer.subscription.deleted' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$events = \Stripe\Event::all(
			array(
				'types'   => array( 'customer.subscription.updated', 'customer.subscription.deleted' ),
				'created' => array(
					'gte' => $last_synced,
				),
				'limit'   => 100,
			)
		);

		$count_updated = 0;
		$count_skipped = 0;

		foreach ( $events->autoPagingIterator() as $event ) {
			$subscription    = $event->data->object;
			$subscription_id = $subscription->id;

			if ( empty( $subscription_id ) ) {
				++$count_skipped;
				continue;
			}

			$membership_subscription = $this->members_subscription_repository->get_subscription_by_subscription_id_meta( $subscription_id );

			if ( empty( $membership_subscription ) ) {
				++$count_skipped;
				continue;
			}

			if ( $subscription->status !== $membership_subscription['status'] ) {

				if ( 'active' === $subscription->status && 'canceled' === $membership_subscription['status'] && $subscription->cancel_at_period_end ) {
					// If Stripe subscription is active but our record is canceled and Stripe subscription is set to cancel at period end, we should not update the status to active as it will cause confusion. We will wait for the next event to update the status.
					++$count_skipped;
					continue;
				}

				$update_data = array(
					'status' => $subscription->status,
				);

				$current_period_end = $subscription->current_period_end ?? null;
				if ( ! empty( $current_period_end ) ) {
					$next_billing_date                = gmdate( 'Y-m-d H:i:s', $current_period_end );
					$update_data['next_billing_date'] = $next_billing_date;
					$update_data['expiry_date']       = $next_billing_date;
				}

				$this->members_subscription_repository->update(
					$membership_subscription['ID'],
					$update_data
				);

				++$count_updated;
				$logger->info(
					sprintf( '[Backfill][Stripe][Status] Updated subscription ID %d to status "%s"', $membership_subscription['ID'], $subscription->status ),
					array( 'source' => 'urm-missed-payment-backfill' )
				);

				// When subscription becomes active, also complete its pending order.
				if ( 'active' === $subscription->status ) {
					$pending_order = $this->orders_repository->get_order_by_subscription( $membership_subscription['ID'] );
					if ( ! empty( $pending_order ) && 'pending' === ( $pending_order['status'] ?? '' ) ) {
						$this->orders_repository->update(
							$pending_order['ID'],
							array( 'status' => 'completed' )
						);
						$logger->info(
							sprintf( '[Backfill][Stripe][Status] Completed pending order %d alongside subscription %d activation.', $pending_order['ID'], $membership_subscription['ID'] ),
							array( 'source' => 'urm-missed-payment-backfill' )
						);
					}
				}
			}
		}

		$logger->info(
			'[Backfill][Stripe][Status] Done.' . "\n" . wp_json_encode(
				array(
					'event_type' => 'backfill_done',
					'updated'    => $count_updated,
					'skipped'    => $count_skipped,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);
		$logger->info( '[Backfill][Stripe][Status] ---------- ENDED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	/**
	 * Backfill missed payments events record.
	 *
	 * @param string $last_synced Last synced timestamp to fetch events from.
	 * @return void
	 */
	public function run_missed_payment_backfill( $last_synced ) {
		$logger = ur_get_logger();
		$logger->info( '[Backfill][Stripe][Payments] ---------- STARTED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][Stripe][Payments] Starting subscription payment backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => gmdate( 'Y-m-d H:i:s', $last_synced ),
					'window_end'   => gmdate( 'Y-m-d H:i:s' ),
					'event_types'  => array( 'invoice.payment_succeeded', 'invoice.paid' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$events = \Stripe\Event::all(
			array(
				'types'   => array( 'invoice.payment_succeeded', 'invoice.paid' ),
				'created' => array(
					'gte' => $last_synced,
				),
				'limit'   => 100,
			)
		);

		$count_created = 0;
		$count_skipped = 0;

		foreach ( $events->autoPagingIterator() as $event ) {
			$invoice           = $event->data->object;
			$subscription_id   = $invoice->subscription ?? null;
			$payment_intent_id = $invoice->payment_intent ?? null;

			if ( empty( $subscription_id ) || empty( $payment_intent_id ) ) {
				++$count_skipped;
				continue;
			}

			$membership_subscription = $this->members_subscription_repository->get_subscription_by_subscription_id_meta( $subscription_id );

			if ( ! empty( $membership_subscription ) ) {
				$payment = $this->orders_repository->get_order_by_transaction_id( $payment_intent_id );

				if ( empty( $payment ) ) {
					$payment = $this->orders_repository->get_order_by_transaction_id( $subscription_id );
					if ( ! empty( $payment ) ) {
						$this->orders_repository->delete( $payment['ID'] );
					}

					$subscription   = $this->members_subscription_repository->retrieve( $membership_subscription['ID'] );
					$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
					$paid_amount    = $payment_intent->amount_received / 100; // Convert from cents to dollars
					$created_at     = gmdate( 'Y-m-d H:i:s', $invoice->created );

					$order_data = array(
						'orders_data'      => array(
							'user_id'         => absint( $subscription['user_id'] ),
							'item_id'         => $subscription['item_id'],
							'subscription_id' => $subscription['ID'],
							'created_by'      => $subscription['user_id'],
							'transaction_id'  => $payment_intent_id,
							'payment_method'  => 'stripe',
							'total_amount'    => $paid_amount,
							'status'          => 'completed',
							'order_type'      => 'subscription',
							'trial_status'    => 'off',
							'notes'           => 'Backfilled order for missed payment event',
							'created_at'      => $created_at,
						),
						'orders_meta_data' => array(
							array(
								'meta_key'   => 'is_admin_created',
								'meta_value' => false,
							),
						),
					);
					$this->orders_repository->create( $order_data );

					++$count_created;
					$logger->info(
						sprintf( '[Backfill][Stripe][Payments] Created missing order for PaymentIntent %s (subscription %s)', $payment_intent_id, $subscription_id ),
						array( 'source' => 'urm-missed-payment-backfill' )
					);
				} else {
					++$count_skipped;
				}
			} else {
				++$count_skipped;
			}
		}

		$logger->info(
			'[Backfill][Stripe][Payments] Done.' . "\n" . wp_json_encode(
				array(
					'event_type'     => 'backfill_done',
					'orders_created' => $count_created,
					'skipped'        => $count_skipped,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);
		$logger->info( '[Backfill][Stripe][Payments] ---------- ENDED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	/**
	 * Backfill missed one-time (paid type) Stripe payment records.
	 *
	 * Finds payment_intent.succeeded events that have no associated subscription
	 * (i.e. one-time payments) and activates any local orders/subscriptions that
	 * are still pending for the matching PaymentIntent ID.
	 *
	 * @param int $last_synced Unix timestamp — fetch events created on or after this time.
	 * @return void
	 */
	public function run_missed_onetime_payment_backfill( $last_synced ) {
		$logger = ur_get_logger();
		$logger->info( '[Backfill][Stripe][OneTime] ---------- STARTED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][Stripe][OneTime] Starting one-time payment backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => gmdate( 'Y-m-d H:i:s', $last_synced ),
					'window_end'   => gmdate( 'Y-m-d H:i:s' ),
					'event_types'  => array( 'payment_intent.succeeded' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$events = \Stripe\Event::all(
			array(
				'types'   => array( 'payment_intent.succeeded' ),
				'created' => array(
					'gte' => $last_synced,
				),
				'limit'   => 100,
			)
		);

		$total_found   = 0;
		$total_updated = 0;

		foreach ( $events->autoPagingIterator() as $event ) {
			$payment_intent = $event->data->object;

			// Skip subscription invoices — those are handled by run_missed_payment_backfill.
			if ( ! empty( $payment_intent->invoice ) ) {
				continue;
			}

			$payment_intent_id = $payment_intent->id ?? null;
			if ( empty( $payment_intent_id ) ) {
				continue;
			}

			++$total_found;

			$order = $this->orders_repository->get_order_by_transaction_id( $payment_intent_id );
			if ( empty( $order ) ) {
				$logger->info(
					sprintf( '[Backfill][Stripe][OneTime] No local order found for PaymentIntent %s — skipping.', $payment_intent_id ),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				continue;
			}

			if ( 'pending' !== $order['status'] ) {
				$logger->info(
					sprintf( '[Backfill][Stripe][OneTime] Order %d for PaymentIntent %s already has status "%s" — skipping.', $order['ID'], $payment_intent_id, $order['status'] ),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				continue;
			}

			// Mark the order as completed.
			$this->orders_repository->update(
				$order['ID'],
				array( 'status' => 'completed' )
			);

			// Activate the linked subscription.
			if ( ! empty( $order['subscription_id'] ) ) {
				$membership_subscription = $this->members_subscription_repository->retrieve( $order['subscription_id'] );
				if ( ! empty( $membership_subscription ) && 'pending' === $membership_subscription['status'] ) {
					$this->members_subscription_repository->update(
						$membership_subscription['ID'],
						array(
							'status'     => 'active',
							'start_date' => gmdate( 'Y-m-d 00:00:00' ),
						)
					);
					$logger->info(
						sprintf( '[Backfill][Stripe][OneTime] Activated subscription %d for order %d (PaymentIntent %s)', $membership_subscription['ID'], $order['ID'], $payment_intent_id ),
						array( 'source' => 'urm-missed-payment-backfill' )
					);
				}
			}

			++$total_updated;
			$logger->info(
				sprintf( '[Backfill][Stripe][OneTime] Completed order %d for PaymentIntent %s', $order['ID'], $payment_intent_id ),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
		}

		$logger->info(
			sprintf( '[Backfill][Stripe][OneTime] Total found: %d, Total updated: %d', $total_found, $total_updated ),
			array( 'source' => 'urm-missed-payment-backfill' )
		);
		$logger->info( '[Backfill][Stripe][OneTime] ---------- ENDED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	/**
	 * Backfill refunded orders missed by webhooks.
	 *
	 * Fetches charge.refunded events from Stripe within the backfill window and
	 * marks matching local orders as refunded.
	 *
	 * @param int $last_synced Unix timestamp of the previous sync.
	 * @return void
	 */
	public function run_missed_refund_backfill( $last_synced ) {
		$logger = ur_get_logger();
		$logger->info( '[Backfill][Stripe][Refunds] ---------- STARTED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][Stripe][Refunds] Starting refund backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => gmdate( 'Y-m-d H:i:s', $last_synced ),
					'window_end'   => gmdate( 'Y-m-d H:i:s' ),
					'event_types'  => array( 'charge.refunded' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$events = \Stripe\Event::all(
			array(
				'types'   => array( 'charge.refunded' ),
				'created' => array( 'gte' => $last_synced ),
				'limit'   => 100,
			)
		);

		$total_found   = 0;
		$total_updated = 0;

		foreach ( $events->autoPagingIterator() as $event ) {
			$charge            = $event->data->object;
			$payment_intent_id = $charge->payment_intent ?? null;

			if ( empty( $payment_intent_id ) ) {
				continue;
			}

			++$total_found;

			$order = $this->orders_repository->get_order_by_transaction_id( $payment_intent_id );
			if ( empty( $order ) ) {
				$logger->info(
					sprintf( '[Backfill][Stripe][Refunds] No local order for PaymentIntent %s — skipping.', $payment_intent_id ),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				continue;
			}

			if ( 'refunded' === $order['status'] ) {
				$logger->info(
					sprintf( '[Backfill][Stripe][Refunds] Order %d already refunded — skipping.', $order['ID'] ),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				continue;
			}

			$this->orders_repository->update( $order['ID'], array( 'status' => 'refunded' ) );
			++$total_updated;
			$logger->info(
				sprintf( '[Backfill][Stripe][Refunds] Marked order %d as refunded (PaymentIntent %s)', $order['ID'], $payment_intent_id ),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
		}

		$logger->info(
			'[Backfill][Stripe][Refunds] Done.' . "\n" . wp_json_encode(
				array(
					'event_type'    => 'backfill_done',
					'total_found'   => $total_found,
					'total_updated' => $total_updated,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);
		$logger->info( '[Backfill][Stripe][Refunds] ---------- ENDED ----------', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	/**
	 * Returns an existing Stripe Tax Rate ID for the given percentage, creating one if needed.
	 * IDs are cached per mode (test/live) in WordPress options to avoid duplicate Tax Rates in Stripe.
	 */
	private function get_or_create_stripe_tax_rate( $percentage ) {
		$mode        = self::get_stripe_settings()['mode'] ?? 'test';
		$option_key  = 'urm_stripe_tax_rate_' . $mode . '_' . str_replace( '.', '_', (string) $percentage );
		$tax_rate_id = get_option( $option_key );

		if ( ! empty( $tax_rate_id ) ) {
			return $tax_rate_id;
		}

		try {
			$tax_rate = \Stripe\TaxRate::create(
				array(
					'display_name' => 'Tax',
					'percentage'   => $percentage,
					'inclusive'    => false,
				)
			);
			update_option( $option_key, $tax_rate->id );
			return $tax_rate->id;
		} catch ( \Exception $e ) {
			PaymentGatewayLogging::log_error(
				'stripe',
				'Failed to create Stripe Tax Rate',
				array(
					'error_code'    => 'TAX_RATE_CREATE_FAILED',
					'percentage'    => $percentage,
					'error_message' => $e->getMessage(),
				)
			);
			return '';
		}
	}
}
