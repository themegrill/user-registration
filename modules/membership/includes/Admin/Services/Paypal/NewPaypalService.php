<?php
/**
 * New PayPal REST service.
 *
 * This class keeps the old PaypalService untouched and provides a REST-based implementation
 * with safe fallback to the legacy service for parity-critical scenarios.
 *
 * Recommended file name:
 * /WPEverest/URMembership/Admin/Services/Paypal/NewPaypalService.php
 */

namespace WPEverest\URMembership\Admin\Services\Paypal;

use DateTime;
use DateTimeZone;
use Exception;
use WP_Error;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Services\CouponService;
use WPEverest\URMembership\Admin\Services\EmailService;
use WPEverest\URMembership\Admin\Services\MembersService;
use WPEverest\URMembership\Admin\Services\OrderService;
use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

defined( 'ABSPATH' ) || exit;

class NewPaypalService {

	/**
	 * @var MembersOrderRepository
	 */
	protected $members_orders_repository;

	/**
	 * @var MembersSubscriptionRepository
	 */
	protected $members_subscription_repository;

	/**
	 * @var MembershipRepository
	 */
	protected $membership_repository;

	/**
	 * @var OrdersRepository
	 */
	protected $orders_repository;

	/**
	 * @var SubscriptionRepository
	 */
	protected $subscription_repository;

	/**
	 * @var PaypalService
	 */
	protected $legacy_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->members_orders_repository       = new MembersOrderRepository();
		$this->members_subscription_repository = new MembersSubscriptionRepository();
		$this->membership_repository           = new MembershipRepository();
		$this->orders_repository               = new OrdersRepository();
		$this->subscription_repository         = new SubscriptionRepository();
		$this->legacy_service                  = new PaypalService(); // keep old service as-is.
	}

	/**
	 * Build approval URL using PayPal REST APIs.
	 *
	 * Falls back to legacy flow when:
	 * - REST credentials are missing
	 * - subscription plan id is missing
	 * - upgrade/trial/proration case cannot be mapped safely to REST
	 *
	 * @param array      $data
	 * @param int        $membership
	 * @param string     $member_email
	 * @param int|string $subscription_id
	 * @param int        $member_id
	 * @param array      $response_data
	 *
	 * @return string|WP_Error
	 */
	public function build_url( $data, $membership, $member_email, $subscription_id, $member_id, $response_data = array() ) {
		$context = $this->prepare_paypal_context( $data, $membership, $member_email, $subscription_id, $member_id, $response_data );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		PaymentGatewayLogging::log_transaction_start(
			'paypal',
			sprintf(
				'[Member ID #%s] Building PayPal REST payment URL.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'event_type'      => 'transaction_start',
					'member_id'       => $member_id,
					'membership_id'   => $membership,
					'member_email'    => $member_email,
					'subscription_id' => $subscription_id,
					'membership_type' => $context['membership_type'],
					'mode'            => $context['paypal_options']['mode'],
					'is_upgrading'    => $context['is_upgrading'],
					'is_renewing'     => $context['is_renewing'],
					'has_team'        => $context['has_team'],
				),
				JSON_PRETTY_PRINT
			)
		);

		$use_legacy = $this->should_fallback_to_legacy_paypal( $context );
		$logger     = ur_get_logger();
		$logger->info(
			sprintf( '[Member #%d] Deciding PayPal flow.', $member_id ) . "\n" .
			wp_json_encode(
				array(
					'use_legacy'        => $use_legacy,
					'is_new_install'    => ! ur_is_paypal_old_installation(),
					'is_subscription'   => $context['is_subscription'],
					'has_client_id'     => ! empty( $context['paypal_options']['client_id'] ),
					'has_client_secret' => ! empty( $context['paypal_options']['secret_key'] ),
				),
				JSON_PRETTY_PRINT
			),
			array(
				'source'    => 'urm-pg-paypal',
				'member_id' => $member_id,
				'function'  => __FUNCTION__,
			)
		);

		if ( $use_legacy ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				'Falling back to legacy PayPal Standard flow',
				'notice',
				array(
					'member_id'        => $member_id,
					'membership_id'    => $membership,
					'fallback_reasons' => $context['fallback_reasons'],
				)
			);

			return $this->legacy_service->build_url( $data, $membership, $member_email, $subscription_id, $member_id, $response_data );
		}

		if ( $context['is_subscription_upgrade_revise'] ) {
			return $this->revise_paypal_subscription_for_upgrade( $context );
		}

		// Proration upgrade to a subscription plan: create a new subscription with the prorated amount as a setup fee,
		// so the member pays the proration now and the full plan price on each subsequent billing cycle.
		if ( $context['is_upgrading'] && ! empty( $context['response_data']['chargeable_amount'] ) && $context['is_subscription'] ) {
			return $this->create_paypal_subscription_order( $context );
		}

		// Proration upgrade to a non-subscription (one-time) plan: charge only the prorated difference.
		if ( $context['is_upgrading'] && ! empty( $context['response_data']['chargeable_amount'] ) ) {
			return $this->create_paypal_one_time_order( $context );
		}

		if ( $context['is_subscription'] ) {
			return $this->create_paypal_subscription_order( $context );
		}

		return $this->create_paypal_one_time_order( $context );
	}

	/**
	 * Prepare a normalized context for all payment cases.
	 *
	 * @param array      $data
	 * @param int        $membership
	 * @param string     $member_email
	 * @param int|string $subscription_id
	 * @param int        $member_id
	 * @param array      $response_data
	 *
	 * @return array|WP_Error
	 */
	private function prepare_paypal_context( $data, $membership, $member_email, $subscription_id, $member_id, $response_data = array() ) {
		$is_upgrading           = ! empty( $data['upgrade'] );
		$paypal_options         = is_array( isset( $data['payment_gateways']['paypal'] ) ? $data['payment_gateways']['paypal'] : null ) ? $data['payment_gateways']['paypal'] : array();
		$mode                   = $this->get_paypal_mode();
		$paypal_options['mode'] = $mode;

		$cancel_url                   = get_option( 'user_registration_global_paypal_cancel_url', home_url() );
		$return_url                   = get_option(
			'user_registration_global_paypal_return_url',
			wp_login_url()
		);
		$paypal_options['cancel_url'] = apply_filters( 'urm_paypal_override_cancel_url', '' === $cancel_url ? home_url() : $cancel_url );
		$paypal_options['return_url'] = apply_filters( 'urm_paypal_override_return_url', '' === $return_url ? wp_login_url() : $return_url );

		// REST credentials.
		$mode_key                     = $this->get_paypal_mode_key();
		$paypal_options['client_id']  = get_option(
			sprintf( 'user_registration_global_paypal_%s_client_id', $mode_key ),
			isset( $paypal_options['client_id'] ) ? $paypal_options['client_id'] : get_option( 'user_registration_global_paypal_client_id', '' )
		);
		$paypal_options['secret_key'] = get_option(
			sprintf( 'user_registration_global_paypal_%s_client_secret', $mode_key ),
			isset( $paypal_options['secret_key'] ) ? $paypal_options['secret_key'] : get_option( 'user_registration_global_paypal_client_secret', '' )
		);

		// Optional fallback email for compatibility and validation.
		$paypal_options['email'] = get_option(
			sprintf( 'user_registration_global_paypal_%s_email_address', $mode_key ),
			get_option( 'user_registration_global_paypal_email_address', '' )
		);

		$membership_data = $this->membership_repository->get_single_membership_by_ID( $membership );
		if ( empty( $membership_data ) ) {
			return new WP_Error(
				'paypal_membership_not_found',
				__( 'Membership not found.', 'user-registration' )
			);
		}

		$membership_metas = wp_unslash( json_decode( $membership_data['meta_value'], true ) );
		if ( ! is_array( $membership_metas ) ) {
			$membership_metas = array();
		}

		$has_team = ! empty( $data['team_id'] ) && ! empty( $data['team_data'] );

		if ( $has_team ) {
			$membership_type = isset( $data['team_data']['team_plan_type'] ) ? $data['team_data']['team_plan_type'] : 'unknown';
			if ( 'one-time' === $membership_type ) {
				$membership_type = 'paid';
			}
		} else {
			$membership_type = isset( $membership_metas['type'] ) ? $membership_metas['type'] : 'unknown';
		}

		$membership_amount = $this->resolve_membership_amount_or_fail( $data, $membership_metas, $member_id );
		if ( is_wp_error( $membership_amount ) ) {
			return $membership_amount;
		}

		$is_automatic       = 'automatic' === get_option( 'user_registration_renewal_behaviour', 'automatic' );
		$membership_process = urm_get_membership_process( $member_id );
		$is_renewing        = ! empty( $membership_process['renew'] ) && in_array( $data['current_membership_id'], $membership_process['renew'], true );

		$base_membership_amount = $membership_amount;

		$currency               = get_option( 'user_registration_payment_currency', 'USD' );
		$local_currency         = isset( $response_data['switched_currency'] ) ? $response_data['switched_currency'] : '';
		$ur_zone_id             = isset( $response_data['urm_zone_id'] ) ? $response_data['urm_zone_id'] : '';
		$pricing_data           = null;
		$local_currency_data    = array();
		$local_currency_applies = false;

		if ( ! empty( $local_currency ) && ! empty( $ur_zone_id ) && ur_check_module_activation( 'local-currency' ) ) {
			$pricing_data        = CoreFunctions::ur_get_pricing_zone_by_id( $ur_zone_id );
			$local_currency_data = ! empty( $data['local_currency'] ) ? $data['local_currency'] : array();

			if ( ! empty( $local_currency_data ) && ur_string_to_bool( $local_currency_data['is_enable'] ) ) {
				$currency               = $local_currency;
				$local_currency_applies = true;
				$membership_amount      = CoreFunctions::ur_get_amount_after_conversion(
					$membership_amount,
					$currency,
					$pricing_data,
					$local_currency_data,
					$ur_zone_id
				);
			}
		}

		$final_amount   = (float) $membership_amount;
		$regular_amount = $final_amount; // Pre-discount, pre-tax — used as plan base price for recurring cycles.
		$coupon_details = array();
		$discount_value = 0.0;

		if ( $is_upgrading ) {
			// $data['amount'] is the prorated delta in the base currency — scale it by the plan's local/base ratio.
			$chargeable_amount_base = (float) ( isset( $data['amount'] ) ? $data['amount'] : $final_amount );
			$final_amount           = ( $local_currency_applies && $base_membership_amount > 0 )
				? $membership_amount * ( $chargeable_amount_base / $base_membership_amount )
				: $chargeable_amount_base;
		} elseif ( ! empty( $data['coupon'] ) && ur_check_module_activation( 'coupon' ) ) {
			$coupon_service    = new CouponService();
			$coupon_validation = $coupon_service->validate(
				array(
					'coupon'        => $data['coupon'],
					'membership_id' => absint( $membership ),
				)
			);

			if ( $coupon_validation['status'] ) {
				$coupon_details       = ur_get_coupon_details( $data['coupon'] );
				$coupon_discount_type = isset( $coupon_details['coupon_discount_type'] ) ? $coupon_details['coupon_discount_type'] : '';
				$discount_value       = ( 'fixed' === $coupon_discount_type )
					? (float) ( isset( $coupon_details['coupon_discount'] ) ? $coupon_details['coupon_discount'] : 0 )
					: ( $final_amount * (float) ( isset( $coupon_details['coupon_discount'] ) ? $coupon_details['coupon_discount'] : 0 ) / 100 );

				// Fixed discounts are configured in the base currency — convert before subtracting.
				if ( $local_currency_applies && 'fixed' === $coupon_discount_type ) {
					$discounted_base  = max( 0.0, $base_membership_amount - $discount_value );
					$discounted_local = CoreFunctions::ur_get_amount_after_conversion(
						$discounted_base,
						$currency,
						$pricing_data,
						$local_currency_data,
						$ur_zone_id,
						$base_membership_amount
					);
					$discount_value = max( 0.0, $final_amount - $discounted_local );
				}

				$final_amount = max( 0.0, (float) user_registration_sanitize_amount( $final_amount - $discount_value ) );
			}
		}

		$pre_tax_final_amount = $final_amount; // Saved before tax — used as base price in subscription plans.
		$tax_rate             = 0.0;
		$tax_amount           = 0.0;
		if (
			! empty( $response_data['tax_rate'] ) &&
			! empty( $response_data['tax_calculation_method'] ) &&
			ur_string_to_bool( $response_data['tax_calculation_method'] )
		) {
			$tax_rate     = (float) $response_data['tax_rate'];
			$tax_amount   = round( $final_amount * $tax_rate / 100, 2 );
			$final_amount = $final_amount + $tax_amount;
		}

		$paypal_verification_token = wp_generate_uuid4();
		update_user_meta( $member_id, 'urm_paypal_verification_token', $paypal_verification_token );

		$query_args = 'membership=' . absint( $membership ) .
			'&member_id=' . absint( $member_id ) .
			'&current_membership_id=' . absint( $data['current_membership_id'] ) .
			'&hash=' . wp_hash( $membership . ',' . $member_id . ',' . $paypal_verification_token );

		$return_url = esc_url_raw(
			add_query_arg(
				array(
					'ur-membership-return' => base64_encode( $query_args ),
				),
				apply_filters( 'user_registration_paypal_return_url', home_url( '/' ), array() )
			)
		);

		$item_name = isset( $membership_data['post_title'] ) ? $membership_data['post_title'] : __( 'Membership Purchase', 'user-registration' );

		if ( 'subscription' === $membership_type ) {
			$duration_data = $has_team
				? array(
					'value'    => isset( $data['team_data']['team_duration_value'] ) ? $data['team_data']['team_duration_value'] : 1,
					'duration' => isset( $data['team_data']['team_duration_period'] ) ? $data['team_data']['team_duration_period'] : '',
				)
				: ( isset( $data['subscription'] ) ? $data['subscription'] : array() );

			if ( ! empty( $duration_data['duration'] ) ) {
				$currency_symbol = 'USD' === $currency ? '$' : $currency;
				$item_name      .= ' - ' . $currency_symbol . number_format( (float) $final_amount, 2, '.', '' ) . ' for ' . $duration_data['value'] . ' ' . $duration_data['duration'];
			}
		}

		$paypal_subscription_id = get_user_meta( $member_id, 'urm_paypal_subscription_paypal_id', true );
		if ( empty( $paypal_subscription_id ) ) {
			$member_subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $subscription_id );
			if ( ! empty( $member_subscription['subscription_id'] ) ) {
				$paypal_subscription_id = $member_subscription['subscription_id'];
			}
		}

		$context = array(
			'data'                            => $data,
			'membership'                      => $membership,
			'membership_data'                 => $membership_data,
			'membership_metas'                => $membership_metas,
			'member_email'                    => $member_email,
			'member_id'                       => $member_id,
			'subscription_id'                 => $subscription_id,
			'response_data'                   => $response_data,
			'membership_type'                 => $membership_type,
			'is_subscription'                 => 'subscription' === $membership_type,
			'is_upgrading'                    => $is_upgrading,
			'is_renewing'                     => $is_renewing,
			'is_automatic'                    => $is_automatic,
			'currency'                        => $currency,
			'final_amount'                    => number_format( (float) $final_amount, 2, '.', '' ),
			'raw_final_amount'                => (float) $final_amount,
			'return_url'                      => $return_url,
			'cancel_url'                      => $paypal_options['cancel_url'],
			'paypal_options'                  => $paypal_options,
			'item_name'                       => $item_name,
			'coupon_details'                  => $coupon_details,
			'discount_value'                  => $discount_value,
			'tax_rate'                        => $tax_rate,
			'tax_amount'                      => number_format( $tax_amount, 2, '.', '' ),
			'pre_tax_amount'                  => number_format( $pre_tax_final_amount, 2, '.', '' ),
			'regular_amount'                  => number_format( $regular_amount, 2, '.', '' ),
			'has_team'                        => $has_team,
			'team_quantity'                   => $this->resolve_team_quantity( $data ),
			'fallback_reasons'                => array(),
			'existing_paypal_subscription_id' => $paypal_subscription_id,
			'is_subscription_upgrade_revise'  => false,
		);

		// UR-4386: a valid 100% coupon zeroes a subscription's amount. Bill nothing now and start
		// one period later (future start_time) with a plain REGULAR plan, instead of a $0 TRIAL cycle.
		$context['is_full_discount_sub'] = $context['is_subscription']
			&& ! $is_upgrading
			&& ! empty( $coupon_details )
			&& 0.0 === (float) $final_amount;

		if (
			$context['is_subscription'] &&
			$context['is_upgrading'] &&
			! empty( $context['existing_paypal_subscription_id'] ) &&
			empty( $response_data['chargeable_amount'] ) &&
			empty( $data['trial_status'] )
		) {
			$context['is_subscription_upgrade_revise'] = true;
		}

		return $context;
	}

	/**
	 * Decide whether to fall back to legacy flow.
	 *
	 * @param array $context
	 *
	 * @return bool
	 */
	private function should_fallback_to_legacy_paypal( &$context ) {
		$paypal_options = $context['paypal_options'];

		if ( ! ur_is_paypal_old_installation() ) {
			return false;
		}

		if ( empty( $paypal_options['client_id'] ) || empty( $paypal_options['secret_key'] ) ) {
			$context['fallback_reasons'][] = 'missing_rest_credentials';

			$test_admin_email = get_option( 'user_registration_global_paypal_test_admin_email', '' );
			$live_admin_email = get_option( 'user_registration_global_paypal_live_admin_email', '' );
			$mode             = $this->get_paypal_mode();

			if ( ( 'test' === $mode && ! empty( $test_admin_email ) ) || ( 'production' === $mode && ! empty( $live_admin_email ) ) ) {
				$context['fallback_reasons'][] = 'Old flow due to missing client id and secrete key';

				return true;
			}

			return false;
		}

		// Legacy trial/proration/delayed upgrade flows are not a clean 1:1 REST map.
		// if (
		// $context['is_subscription'] &&
		// $context['is_upgrading'] &&
		// (
		// ! empty( $context['data']['trial_status'] ) ||
		// ! empty( $context['data']['chargeable_amount'] )
		// )
		// ) {
		// $context['fallback_reasons'][] = 'subscription_upgrade_with_trial_or_proration';
		// return true;
		// }

		// Team subscription must have quantity when plan is quantity based.
		// if (
		// $context['is_subscription'] &&
		// $context['has_team'] &&
		// ! empty( $context['data']['team_data']['seat_model'] ) &&
		// 'fixed' !== $context['data']['team_data']['seat_model'] &&
		// empty( $context['team_quantity'] )
		// ) {
		// $context['fallback_reasons'][] = 'team_subscription_missing_quantity';
		// return true;
		// }

		return false;
	}

	/**
	 * Resolve membership amount for normal/team pricing.
	 *
	 * @param array $data
	 * @param array $membership_metas
	 * @param int   $member_id
	 *
	 * @return float|WP_Error
	 */
	private function resolve_membership_amount_or_fail( $data, $membership_metas, $member_id ) {
		$membership_amount = 0.0;

		if ( ! empty( $data['team_id'] ) && ! empty( $data['team_data'] ) ) {
			$team_data  = $data['team_data'];
			$seat_model = isset( $team_data['seat_model'] ) ? $team_data['seat_model'] : '';

			if ( 'fixed' === $seat_model ) {
				$membership_amount = (float) ( isset( $team_data['team_price'] ) ? $team_data['team_price'] : 0 );
			} else {
				$team_seats = absint( isset( $team_data['team_seats'] ) ? $team_data['team_seats'] : 0 );

				if ( $team_seats <= 0 ) {
					PaymentGatewayLogging::log_error(
						'paypal',
						sprintf(
							'[Member ID #%s] Invalid team seats for payment.',
							$member_id
						) . "\n" . wp_json_encode(
							array(
								'event_type' => 'error',
								'error_code' => 'INVALID_TEAM_SEATS',
								'member_id'  => $member_id,
							),
							JSON_PRETTY_PRINT
						)
					);

					if ( empty( $data['upgrade'] ) ) {
						wp_delete_user( absint( $member_id ) );
					}

					return new WP_Error(
						'paypal_invalid_team_seats',
						__( 'PayPal payment stopped. Invalid team seats.', 'user-registration' )
					);
				}

				$pricing_model = isset( $team_data['pricing_model'] ) ? $team_data['pricing_model'] : '';

				if ( 'per_seat' === $pricing_model ) {
					$membership_amount = $team_seats * (float) ( isset( $team_data['per_seat_price'] ) ? $team_data['per_seat_price'] : 0 );
				} else {
					$tier = isset( $data['team_tier_info'] ) ? $data['team_tier_info'] : '';

					if ( empty( $tier ) ) {
						PaymentGatewayLogging::log_error(
							'paypal',
							sprintf(
								'[Member ID #%s] Invalid team pricing tier.',
								$member_id
							) . "\n" . wp_json_encode(
								array(
									'event_type' => 'error',
									'error_code' => 'INVALID_TIER',
									'member_id'  => $member_id,
								),
								JSON_PRETTY_PRINT
							)
						);

						if ( empty( $data['upgrade'] ) ) {
							wp_delete_user( absint( $member_id ) );
						}

						return new WP_Error(
							'paypal_invalid_pricing_tier',
							__( 'PayPal payment stopped. Invalid pricing tier.', 'user-registration' )
						);
					}

					$membership_amount = $team_seats * (float) ( isset( $data['team_tier_info']['tier_per_seat_price'] ) ? $data['team_tier_info']['tier_per_seat_price'] : 0 );
				}
			}
		} else {
			$membership_amount = (float) ( isset( $membership_metas['amount'] ) ? $membership_metas['amount'] : 0 );
		}

		return (float) $membership_amount;
	}

	/**
	 * Resolve quantity for team subscriptions.
	 *
	 * @param array $data
	 *
	 * @return string
	 */
	private function resolve_team_quantity( $data ) {
		if ( empty( $data['team_id'] ) || empty( $data['team_data'] ) ) {
			return '';
		}

		$team_data     = $data['team_data'];
		$seat_model    = isset( $team_data['seat_model'] ) ? $team_data['seat_model'] : '';
		$pricing_model = isset( $team_data['pricing_model'] ) ? $team_data['pricing_model'] : '';

		if ( 'fixed' === $seat_model ) {
			return '';
		}

		$team_seats = absint( isset( $team_data['team_seats'] ) ? $team_data['team_seats'] : 0 );
		if ( $team_seats <= 0 ) {
			return '';
		}

		if ( in_array( $pricing_model, array( 'per_seat', 'tier' ), true ) ) {
			return (string) $team_seats;
		}

		return '';
	}

	/**
	 * Create a one-time PayPal order.
	 *
	 * @param array $context
	 *
	 * @return string|WP_Error
	 */
	private function create_paypal_one_time_order( $context ) {
		$custom_id = $this->build_custom_id( $context );

		$amount_data = array(
			'currency_code' => $context['currency'],
			'value'         => $context['final_amount'],
		);

		if ( ! empty( $context['tax_rate'] ) && (float) $context['tax_amount'] > 0 ) {
			$pre_tax_value            = number_format( (float) $context['final_amount'] - (float) $context['tax_amount'], 2, '.', '' );
			$amount_data['breakdown'] = array(
				'item_total' => array(
					'currency_code' => $context['currency'],
					'value'         => $pre_tax_value,
				),
				'tax_total'  => array(
					'currency_code' => $context['currency'],
					'value'         => $context['tax_amount'],
				),
			);
		}

		$payload = array(
			'intent'              => 'CAPTURE',
			'purchase_units'      => array(
				array(
					'reference_id' => (string) $custom_id,
					'custom_id'    => (string) $custom_id,
					'description'  => sanitize_text_field( $context['item_name'] ),
					'amount'       => $amount_data,
				),
			),
			'application_context' => array(
				'return_url'          => $context['return_url'],
				'cancel_url'          => $context['cancel_url'],
				'user_action'         => 'PAY_NOW',
				'shipping_preference' => 'NO_SHIPPING',
				'brand_name'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			'payer'               => array(
				'email_address' => sanitize_email( $context['member_email'] ),
			),
		);

		$response = $this->create_paypal_rest_order( $payload, $context['paypal_options'] );

		if ( is_wp_error( $response ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal REST order creation failed.',
					$context['member_id']
				) . "\n" . wp_json_encode(
					array(
						'event_type' => 'error',
						'error_code' => $response->get_error_code(),
						'message'    => $response->get_error_message(),
						'member_id'  => $context['member_id'],
					),
					JSON_PRETTY_PRINT
				)
			);
			return $response;
		}

		if ( ! empty( $response['id'] ) ) {
			update_user_meta( $context['member_id'], 'urm_paypal_order_id', sanitize_text_field( $response['id'] ) );
			update_user_meta( $context['member_id'], 'urm_paypal_order_amount', (float) $context['final_amount'] );
			update_user_meta( $context['member_id'], 'urm_paypal_order_currency', sanitize_text_field( $context['currency'] ) );
		}

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf(
				'[Member ID #%s] PayPal REST one-time order created successfully.',
				$context['member_id']
			) . "\n" . wp_json_encode(
				array(
					'event_type'      => 'order_created',
					'member_id'       => $context['member_id'],
					'membership_id'   => $context['membership'],
					'subscription_id' => $context['subscription_id'],
					'paypal_order_id' => isset( $response['id'] ) ? $response['id'] : '',
					'amount'          => $context['final_amount'],
					'currency'        => $context['currency'],
				),
				JSON_PRETTY_PRINT
			)
		);

		return $this->extract_paypal_approval_url( $response );
	}

	/**
	 * Create a subscription with PayPal REST subscriptions API.
	 *
	 * @param array $context
	 *
	 * @return string|WP_Error
	 */
	private function create_paypal_subscription_order( $context ) {
		$plan_id = isset( $context['data']['paypal_plan_id'] ) ? $context['data']['paypal_plan_id'] : '';

		$plan_id = $this->get_or_create_paypal_plan_id( $context );
		if ( is_wp_error( $plan_id ) ) {
			return $plan_id;
		}

		$custom_id = $this->build_custom_id( $context );

		$payload = array(
			'plan_id'             => sanitize_text_field( $plan_id ),
			'custom_id'           => (string) $custom_id,
			'subscriber'          => array(
				'email_address' => sanitize_email( $context['member_email'] ),
			),
			'application_context' => array(
				'brand_name'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'user_action'         => 'SUBSCRIBE_NOW',
				'shipping_preference' => 'NO_SHIPPING',
				'return_url'          => $context['return_url'],
				'cancel_url'          => $context['cancel_url'],
			),
		);

		if ( ! empty( $context['team_quantity'] ) ) {
			$payload['quantity'] = (string) $context['team_quantity'];
		}

		// $plan_override = $this->build_subscription_plan_override( $context );
		// if ( ! empty( $plan_override ) ) {
		// $payload['plan'] = $plan_override;
		// }
		$has_trial = ! empty( $context['data']['trial_status'] ) && 'on' === $context['data']['trial_status'];

		// UR-4386: full-discount subs use a plain REGULAR plan + future start_time (below), not a
		// discounted/$0 TRIAL override cycle.
		$plan_override = array();
		if ( ! $has_trial && empty( $context['is_full_discount_sub'] ) ) {
			$plan_override = $this->build_subscription_plan_override( $context );
		}

		// For proration subscription upgrades: charge the prorated amount as a PayPal setup fee.
		// The plan itself is at the full regular price; the setup fee covers the billing difference.
		if ( $context['is_upgrading'] && ! empty( $context['response_data']['chargeable_amount'] ) ) {
			$setup_fee_value = number_format( (float) $context['pre_tax_amount'], 2, '.', '' );
			if ( ! isset( $plan_override['payment_preferences'] ) ) {
				$plan_override['payment_preferences'] = array();
			}
			$plan_override['payment_preferences']['setup_fee']                = array(
				'value'         => $setup_fee_value,
				'currency_code' => $context['currency'],
			);
			$plan_override['payment_preferences']['setup_fee_failure_action'] = 'CANCEL';
			$plan_override['payment_preferences']['auto_bill_outstanding']    = true;
		}

		if ( ! empty( $plan_override ) ) {
			$payload['plan'] = $plan_override;
		}

		// Start time. UR-4386: for a 100% coupon the first period is free, so start billing one full
		// billing period out (nothing charged now); otherwise start almost immediately.
		if ( ! empty( $context['is_full_discount_sub'] ) ) {
			$sub_data = ! empty( $context['has_team'] )
				? array(
					'duration' => $context['data']['team_data']['team_duration_period'] ?? 'month',
					'value'    => $context['data']['team_data']['team_duration_value'] ?? 1,
				)
				: ( $context['data']['subscription'] ?? array() );
			$value    = max( 1, (int) ( $sub_data['value'] ?? 1 ) );
			$duration = strtolower( (string) ( $sub_data['duration'] ?? 'month' ) );
			$payload['start_time'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( "+{$value} {$duration}" ) );
		} else {
			$payload['start_time'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 60 );
		}

		$response = $this->create_paypal_subscription( $payload, $context['paypal_options'] );

		if ( is_wp_error( $response ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal subscription creation failed.',
					$context['member_id']
				) . "\n" . wp_json_encode(
					array(
						'error_code' => $response->get_error_code(),
						'message'    => $response->get_error_message(),
						'member_id'  => $context['member_id'],
					),
					JSON_PRETTY_PRINT
				)
			);
			return $response;
		}

		if ( ! empty( $response['id'] ) ) {
			update_user_meta( $context['member_id'], 'urm_paypal_subscription_paypal_id', sanitize_text_field( $response['id'] ) );
		}

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf(
				'[Member ID #%s] PayPal REST subscription created successfully.',
				$context['member_id']
			) . "\n" . wp_json_encode(
				array(
					'member_id'              => $context['member_id'],
					'membership_id'          => $context['membership'],
					'subscription_id'        => $context['subscription_id'],
					'paypal_subscription_id' => isset( $response['id'] ) ? $response['id'] : '',
					'team_quantity'          => $context['team_quantity'],
				),
				JSON_PRETTY_PRINT
			)
		);

		return $this->extract_paypal_approval_url( $response );
	}

	/**
	 * Revise an existing PayPal subscription for clean upgrade path.
	 *
	 * @param array $context
	 *
	 * @return string|WP_Error
	 */
	private function revise_paypal_subscription_for_upgrade( $context ) {
		$paypal_subscription_id = $context['existing_paypal_subscription_id'];
		$new_plan_id            = isset( $context['data']['paypal_plan_id'] ) ? $context['data']['paypal_plan_id'] : '';

		if ( empty( $paypal_subscription_id ) || empty( $new_plan_id ) ) {
			return new WP_Error(
				'paypal_revise_missing_data',
				__( 'Missing PayPal subscription ID or new plan ID for subscription upgrade.', 'user-registration' )
			);
		}

		$payload = array(
			'plan_id'             => sanitize_text_field( $new_plan_id ),
			'application_context' => array(
				'return_url' => $context['return_url'],
				'cancel_url' => $context['cancel_url'],
			),
		);

		if ( ! empty( $context['team_quantity'] ) ) {
			$payload['quantity'] = (string) $context['team_quantity'];
		}

		$response = $this->revise_paypal_subscription(
			$paypal_subscription_id,
			$payload,
			$context['paypal_options']
		);

		if ( is_wp_error( $response ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal subscription revise failed.',
					$context['member_id']
				) . "\n" . wp_json_encode(
					array(
						'message'                => $response->get_error_message(),
						'member_id'              => $context['member_id'],
						'paypal_subscription_id' => $paypal_subscription_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			return $response;
		}

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf(
				'[Member ID #%s] PayPal subscription revised successfully.',
				$context['member_id']
			) . "\n" . wp_json_encode(
				array(
					'member_id'              => $context['member_id'],
					'membership_id'          => $context['membership'],
					'subscription_id'        => $context['subscription_id'],
					'paypal_subscription_id' => $paypal_subscription_id,
					'new_plan_id'            => $new_plan_id,
				),
				JSON_PRETTY_PRINT
			)
		);

		return $this->extract_paypal_approval_url( $response );
	}

	/**
	 * Build optional plan override.
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	private function build_subscription_plan_override( $context ) {
		$override = array();

		// Tax override.
		if ( $context['tax_rate'] > 0 ) {
			$override['taxes'] = array(
				'percentage' => number_format( (float) $context['tax_rate'], 2, '.', '' ),
				'inclusive'  => false,
			);
		}

		// Price override when coupon/local currency changes effective amount.
		$needs_custom_price = ! empty( $context['coupon_details'] ) || ! empty( $context['response_data']['switched_currency'] );

		if ( $needs_custom_price ) {
			$subscription_data = ! empty( $context['has_team'] ) ? array(
				'duration' => isset( $context['data']['team_data']['team_duration_period'] ) ? $context['data']['team_data']['team_duration_period'] : '',
				'value'    => isset( $context['data']['team_data']['team_duration_value'] ) ? $context['data']['team_data']['team_duration_value'] : 1,
			) : ( isset( $context['data']['subscription'] ) ? $context['data']['subscription'] : array() );

			$duration = strtoupper( substr( (string) ( isset( $subscription_data['duration'] ) ? $subscription_data['duration'] : '' ), 0, 1 ) );
			$value    = max( 1, (int) ( isset( $subscription_data['value'] ) ? $subscription_data['value'] : 1 ) );

			$interval_unit_map = array(
				'D' => 'DAY',
				'W' => 'WEEK',
				'M' => 'MONTH',
				'Y' => 'YEAR',
			);

			if ( isset( $interval_unit_map[ $duration ] ) ) {
				$frequency_spec = array(
					'interval_unit'  => $interval_unit_map[ $duration ],
					'interval_count' => $value,
				);

				if ( ! empty( $context['coupon_details'] ) ) {
					// Coupon applied: first cycle at discounted price, all subsequent cycles at regular price.
					$discounted_price           = ! empty( $context['tax_rate'] ) ? $context['pre_tax_amount'] : $context['final_amount'];
					$override['billing_cycles'] = array(
						array(
							'frequency'      => $frequency_spec,
							'tenure_type'    => 'TRIAL',
							'sequence'       => 1,
							'total_cycles'   => 1,
							'pricing_scheme' => array(
								'fixed_price' => array(
									'currency_code' => $context['currency'],
									'value'         => $discounted_price,
								),
							),
						),
						array(
							'frequency'      => $frequency_spec,
							'tenure_type'    => 'REGULAR',
							'sequence'       => 2,
							'total_cycles'   => 0,
							'pricing_scheme' => array(
								'fixed_price' => array(
									'currency_code' => $context['currency'],
									'value'         => $context['regular_amount'],
								),
							),
						),
					);
				} else {
					// Currency switch only: single REGULAR cycle at converted price.
					$override['billing_cycles'] = array(
						array(
							'frequency'      => $frequency_spec,
							'tenure_type'    => 'REGULAR',
							'sequence'       => 1,
							'total_cycles'   => 0,
							'pricing_scheme' => array(
								'fixed_price' => array(
									'currency_code' => $context['currency'],
									'value'         => ! empty( $context['tax_rate'] ) ? $context['pre_tax_amount'] : $context['final_amount'],
								),
							),
						),
					);
				}
			}
		}

		return $override;
	}

	/**
	 * Build custom id.
	 *
	 * @param array $context
	 *
	 * @return string
	 */
	private function build_custom_id( $context ) {
		return $context['membership'] . '-' . $context['member_id'] . '-' . $context['data']['current_membership_id'] . '-' . $context['subscription_id'];
	}

	/**
	 * Extract PayPal approval URL from response.
	 *
	 * @param array|WP_Error $response
	 *
	 * @return string|WP_Error
	 */
	private function extract_paypal_approval_url( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['links'] ) || ! is_array( $response['links'] ) ) {
			return new WP_Error(
				'paypal_missing_links',
				__( 'PayPal response does not contain approval links.', 'user-registration' )
			);
		}

		foreach ( $response['links'] as $link ) {
			if ( ! empty( $link['rel'] ) && in_array( $link['rel'], array( 'approve', 'payer-action' ), true ) && ! empty( $link['href'] ) ) {
				return esc_url_raw( $link['href'] );
			}
		}

		return new WP_Error(
			'paypal_missing_approve_url',
			__( 'PayPal approval URL not found.', 'user-registration' )
		);
	}

	/**
	 * Handle redirect response after buyer returns from PayPal.
	 *
	 * For REST one-time orders:
	 * - buyer approves order
	 * - we capture the order here
	 *
	 * For REST subscriptions:
	 * - buyer approves subscription
	 * - PayPal redirects back with subscription_id/token
	 * - we can confirm subscription and then finalize local records
	 *
	 * @param string $params
	 * @param string $payer_id
	 *
	 * @return void
	 */
	public function handle_paypal_redirect_response( $params, $payer_id ) {
		parse_str( $params, $url_params );

		$membership_id = absint( isset( $url_params['membership'] ) ? $url_params['membership'] : 0 );
		$member_id     = absint( isset( $url_params['member_id'] ) ? $url_params['member_id'] : 0 );

		if ( empty( $membership_id ) || empty( $member_id ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'PayPal redirect aborted: missing membership_id or member_id in return params.' . "\n" . wp_json_encode(
					array(
						'membership_id' => $membership_id,
						'member_id'     => $member_id,
						'raw_params'    => $params,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		$supplied_hash             = isset( $url_params['hash'] ) ? $url_params['hash'] : '';
		$paypal_verification_token = get_user_meta( $member_id, 'urm_paypal_verification_token', true );
		$expected_hash             = wp_hash( $membership_id . ',' . $member_id . ',' . $paypal_verification_token );

		if ( empty( $supplied_hash ) || ! hash_equals( $supplied_hash, $expected_hash ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal redirect hash validation failed.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'membership_id' => $membership_id,
						'member_id'     => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		delete_user_meta( $member_id, 'urm_paypal_verification_token' );

		$member_order = $this->members_orders_repository->get_member_orders( $member_id );
		if ( empty( $member_order ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] Member order not found during PayPal redirect.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'membership_id' => $membership_id,
						'member_id'     => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		$membership = $this->membership_repository->get_single_membership_by_ID( $membership_id );
		if ( empty( $membership ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal redirect aborted: membership not found.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'membership_id' => $membership_id,
						'member_id'     => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		$membership_metas               = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$membership_metas               = is_array( $membership_metas ) ? $membership_metas : array();
		$membership_metas['post_title'] = isset( $membership['post_title'] ) ? $membership['post_title'] : '';
		$membership_type                = isset( $membership_metas['type'] ) ? $membership_metas['type'] : 'unknown';
		$membership_process             = urm_get_membership_process( $member_id );

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			sprintf(
				'[Member ID #%s] PayPal redirect callback received.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'webhook_type'    => 'redirect_callback',
					'payer_id'        => $payer_id,
					'membership_id'   => $membership_id,
					'member_id'       => $member_id,
					'membership_type' => $membership_type,
				),
				JSON_PRETTY_PRINT
			)
		);

		$order_token            = sanitize_text_field( isset( $_GET['token'] ) ? $_GET['token'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paypal_subscription_id = sanitize_text_field( isset( $_GET['subscription_id'] ) ? $_GET['subscription_id'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$member_subscription    = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $member_order['subscription_id'] );
		$is_renewing            = ! empty( $membership_process['renew'] ) && in_array( $member_order['item_id'], $membership_process['renew'], true );
		$is_upgrading           = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $url_params['current_membership_id'] ?? '' ] );
		// Also treat as one-time if PayPal returned a token with no subscription_id (proration upgrade).
		$is_rest_one_time_payment = ( 'paid' === $member_order['order_type'] || 'one-time' === $membership_type )
			|| ( ! empty( $order_token ) && empty( $paypal_subscription_id ) );

		$payment_verified = false;

		// if buyer already returned and internal order is completed, just redirect .
		// if ( 'completed' === ( isset( $member_order['status'] ) ? $member_order['status'] : '' ) ) {
		// ur_membership_redirect_to_thank_you_page( $member_id, $member_order );
		// }

		// REST one-time order capture.
		if ( ! empty( $order_token ) && $is_rest_one_time_payment ) {
			$expected_order_id = get_user_meta( $member_id, 'urm_paypal_order_id', true );

			if ( empty( $expected_order_id ) || ! hash_equals( (string) $expected_order_id, (string) $order_token ) ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					sprintf(
						'[Member ID #%s] PayPal order token does not match the order created for this member.',
						$member_id
					) . "\n" . wp_json_encode(
						array(
							'paypal_order_id'    => $order_token,
							'expected_order_id'  => $expected_order_id,
							'member_id'          => $member_id,
						),
						JSON_PRETTY_PRINT
					)
				);
				return;
			}

			$paypal_credentials = $this->get_paypal_rest_credentials();
			$capture_response   = $this->capture_paypal_order( $order_token, $paypal_credentials );

			if ( is_wp_error( $capture_response ) ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					sprintf(
						'[Member ID #%s] PayPal order capture failed after redirect.',
						$member_id
					) . "\n" . wp_json_encode(
						array(
							'paypal_order_id' => $order_token,
							'member_id'       => $member_id,
							'message'         => $capture_response->get_error_message(),
						),
						JSON_PRETTY_PRINT
					)
				);
				return;
			}

			$capture_status = isset( $capture_response['purchase_units'][0]['payments']['captures'][0]['status'] )
				? $capture_response['purchase_units'][0]['payments']['captures'][0]['status']
				: ( isset( $capture_response['status'] ) ? $capture_response['status'] : '' );

			if ( 'COMPLETED' !== strtoupper( $capture_status ) ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					sprintf(
						'[Member ID #%s] PayPal order capture did not complete after redirect.',
						$member_id
					) . "\n" . wp_json_encode(
						array(
							'paypal_order_id' => $order_token,
							'member_id'       => $member_id,
							'capture_status'  => $capture_status,
						),
						JSON_PRETTY_PRINT
					)
				);
				return;
			}

			$captured_amount_data = isset( $capture_response['purchase_units'][0]['payments']['captures'][0]['amount'] )
				? $capture_response['purchase_units'][0]['payments']['captures'][0]['amount']
				: ( isset( $capture_response['purchase_units'][0]['amount'] ) ? $capture_response['purchase_units'][0]['amount'] : array() );

			$expected_amount   = (float) get_user_meta( $member_id, 'urm_paypal_order_amount', true );
			$expected_currency = get_user_meta( $member_id, 'urm_paypal_order_currency', true );
			$captured_amount   = isset( $captured_amount_data['value'] ) ? (float) $captured_amount_data['value'] : 0.0;
			$captured_currency = isset( $captured_amount_data['currency_code'] ) ? $captured_amount_data['currency_code'] : '';

			$amount_mismatch   = $expected_amount > 0 && ( $captured_amount + 0.01 ) < $expected_amount;
			$currency_mismatch = ! empty( $expected_currency ) && ! empty( $captured_currency ) && 0 !== strcasecmp( $expected_currency, $captured_currency );

			$payee_email       = isset( $capture_response['purchase_units'][0]['payee']['email_address'] ) ? $capture_response['purchase_units'][0]['payee']['email_address'] : '';
			$configured_email  = isset( $paypal_credentials['email'] ) ? $paypal_credentials['email'] : '';
			$payee_mismatch    = ! empty( $payee_email ) && ! empty( $configured_email ) && 0 !== strcasecmp( $payee_email, $configured_email );

			if ( $amount_mismatch || $currency_mismatch || $payee_mismatch ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					sprintf(
						'[Member ID #%s] PayPal captured amount, currency, or payee does not match the expected order.',
						$member_id
					) . "\n" . wp_json_encode(
						array(
							'paypal_order_id'   => $order_token,
							'member_id'         => $member_id,
							'expected_amount'   => $expected_amount,
							'captured_amount'   => $captured_amount,
							'expected_currency' => $expected_currency,
							'captured_currency' => $captured_currency,
							'configured_payee'  => $configured_email,
							'captured_payee'    => $payee_email,
						),
						JSON_PRETTY_PRINT
					)
				);
				return;
			}

			update_user_meta( $member_id, 'urm_paypal_order_capture_response', wp_json_encode( $capture_response ) );

			$transaction_id = $this->extract_capture_id_from_order_response( $capture_response );
			$this->members_orders_repository->update(
				$member_order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $transaction_id,
				)
			);

			if ( ! empty( $member_subscription ) ) {
				$this->members_subscription_repository->update(
					$member_subscription['ID'],
					array(
						'status'     => 'active',
						'start_date' => date( 'Y-m-d 00:00:00' ),
					)
				);
			}

			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal one-time order captured and status updated after redirect.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'paypal_order_id' => $order_token,
						'transaction_id'  => $transaction_id,
						'order_status'    => 'completed',
						'member_id'       => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			$payment_verified = true;
		}

		// REST subscription return.
		if ( ! empty( $paypal_subscription_id ) && 'subscription' === $membership_type ) {
			update_user_meta( $member_id, 'urm_paypal_subscription_paypal_id', $paypal_subscription_id );

			$subscription_details = $this->get_paypal_subscription(
				$paypal_subscription_id,
				$this->get_paypal_rest_credentials()
			);

			if ( is_wp_error( $subscription_details ) ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					sprintf(
						'[Member ID #%s] Failed to confirm PayPal subscription after redirect.',
						$member_id
					) . "\n" . wp_json_encode(
						array(
							'paypal_subscription_id' => $paypal_subscription_id,
							'member_id'              => $member_id,
							'message'                => $subscription_details->get_error_message(),
						),
						JSON_PRETTY_PRINT
					)
				);
				return;
			}

			$new_status = 'ACTIVE' === strtoupper( isset( $subscription_details['status'] ) ? $subscription_details['status'] : '' ) ? 'active' : 'pending';

			// Try to get the real transaction ID from PayPal's subscription transactions API.
			$transaction_id     = $paypal_subscription_id;
			$transaction_source = 'subscription_id_placeholder';

			$txn_response = $this->get_paypal_subscription_transactions(
				$paypal_subscription_id,
				$this->get_paypal_rest_credentials()
			);

			if ( ! is_wp_error( $txn_response ) && ! empty( $txn_response['transactions'] ) ) {
				foreach ( $txn_response['transactions'] as $txn ) {
					if ( ! empty( $txn['id'] ) && 'Completed' === ( isset( $txn['status'] ) ? $txn['status'] : '' ) ) {
						$transaction_id     = sanitize_text_field( $txn['id'] );
						$transaction_source = 'subscription_transactions_api';
						break;
					}
				}
			}

			PaymentGatewayLogging::log_general(
				'paypal',
				sprintf(
					'[Member ID #%s] Subscription transaction ID resolution on redirect.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'paypal_subscription_id' => $paypal_subscription_id,
						'transaction_id'         => $transaction_id,
						'source'                 => $transaction_source,
						'api_error'              => is_wp_error( $txn_response ) ? $txn_response->get_error_message() : null,
						'transactions_found'     => ! is_wp_error( $txn_response ) ? count( isset( $txn_response['transactions'] ) ? $txn_response['transactions'] : array() ) : 0,
					),
					JSON_PRETTY_PRINT
				),
				'subscription_id_placeholder' === $transaction_source ? 'notice' : 'info'
			);

			$this->members_orders_repository->update(
				$member_order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $transaction_id,
				)
			);

			$resolved_status = 'on' === ( isset( $member_order['trial_status'] ) ? $member_order['trial_status'] : '' ) ? 'trial' : $new_status;

			if ( ! empty( $member_subscription ) ) {
				$this->members_subscription_repository->update(
					$member_subscription['ID'],
					array(
						'status'          => $resolved_status,
						'start_date'      => date( 'Y-m-d 00:00:00' ),
						'subscription_id' => sanitize_text_field( $paypal_subscription_id ),
					)
				);
			}

			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal subscription confirmed and status updated after redirect.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'paypal_subscription_id' => $paypal_subscription_id,
						'transaction_id'         => $transaction_id,
						'transaction_source'     => $transaction_source,
						'paypal_status'          => isset( $subscription_details['status'] ) ? $subscription_details['status'] : '',
						'subscription_status'    => $resolved_status,
						'member_id'              => $member_id,
					),
					JSON_PRETTY_PRINT
				)
			);
			$payment_verified = true;
		}

		if ( ! $payment_verified && ! $is_upgrading ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf(
					'[Member ID #%s] PayPal redirect aborted: no token or subscription_id present — possible forged return URL.',
					$member_id
				)
			);
			return;
		}

		// Reload local subscription after any update.
		$member_subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $member_order['subscription_id'] );

		if ( $is_renewing && ! empty( $member_subscription ) ) {
			$subscription_service = new SubscriptionService();
			$subscription_service->update_subscription_data_for_renewal( $member_subscription, $membership_metas );
		}

		$this->send_payment_success_email( $member_order['ID'], $member_subscription, $membership_metas, $member_id, $membership_id );

		// UR-4710: PayPal is offsite, so role was deferred at registration/upgrade time.
		// Redirect confirms payment here — grant it now instead of waiting for next login.
		( new MembersService() )->maybe_grant_pending_role( $member_id );

		if ( $is_upgrading && ! empty( $member_subscription['ID'] ) ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				sprintf(
					'[Member ID #%s] Processing membership upgrade after PayPal redirect.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'member_id'       => $member_id,
						'subscription_id' => $member_subscription['ID'],
					),
					JSON_PRETTY_PRINT
				),
				'notice'
			);

			$this->handle_upgrade_for_paypal( $member_id, $member_subscription['ID'] );
		}

		$this->handle_auto_login_after_payment( $member_id );

		delete_transient( 'urm_pending_login_' . $member_id );
		$member_order = $this->members_orders_repository->retrieve( $member_order['ID'] );

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf(
				'[Member ID #%s] PayPal redirect flow completed. Redirecting to thank-you page.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'member_id'     => $member_id,
					'membership_id' => $membership_id,
					'order_status'  => isset( $member_order['status'] ) ? $member_order['status'] : '',
					'is_renewing'   => $is_renewing,
					'is_upgrading'  => $is_upgrading,
				),
				JSON_PRETTY_PRINT
			)
		);

		ur_membership_redirect_to_thank_you_page( $member_id, $member_order );
	}

	/**
	 * Shared helper to send payment success email.
	 *
	 * @param int   $order_id
	 * @param array $member_subscription
	 * @param array $membership_metas
	 * @param int   $member_id
	 * @param int   $membership_id
	 *
	 * @return void
	 */
	private function send_payment_success_email( $order_id, $member_subscription, $membership_metas, $member_id, $membership_id ) {
		$email_service = new EmailService();
		$order_detail  = $this->orders_repository->get_order_detail( $order_id );

		if ( ! empty( $order_detail['coupon'] ) ) {
			$order_detail['coupon_discount']      = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount', true );
			$order_detail['coupon_discount_type'] = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount_type', true );
		}

		$email_data = array(
			'subscription'     => $member_subscription,
			'order'            => $order_detail,
			'membership_metas' => $membership_metas,
			'member_id'        => $member_id,
			'membership'       => $membership_id,
		);

		$mail_send = $email_service->send_email( $email_data, 'payment_successful' );

		if ( ! $mail_send ) {
			PaymentGatewayLogging::log_transaction_failure(
				'paypal',
				sprintf(
					'[Member ID #%s] Payment successful email failed.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'member_id'       => $member_id,
						'subscription_id' => isset( $member_subscription['ID'] ) ? $member_subscription['ID'] : '',
					),
					JSON_PRETTY_PRINT
				)
			);
		} else {
			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				sprintf(
					'[Member ID #%s] Payment successful email sent.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'member_id'       => $member_id,
						'subscription_id' => isset( $member_subscription['ID'] ) ? $member_subscription['ID'] : '',
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
	}

	/**
	 * Handle auto login after payment.
	 *
	 * @param int $member_id
	 *
	 * @return void
	 */
	private function handle_auto_login_after_payment( $member_id ) {
		$login_option = ur_get_user_login_option( $member_id );
		$data         = apply_filters(
			'user_registration_membership_before_register_member',
			isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array() // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		if ( 'auto_login' === $login_option ) {
			$member_service = new MembersService();
			$password       = isset( $data['password'] ) ? $data['password'] : '';
			$member_service->login_member( $member_id, true, $password );
		}
	}

	/**
	 * Handle upgrade flow using existing logic.
	 *
	 * @param int        $member_id
	 * @param int|string $subscription_id
	 *
	 * @return void
	 */
	public function handle_upgrade_for_paypal( $member_id, $subscription_id ) {
		$get_user_old_subscription = json_decode( get_user_meta( $member_id, 'urm_previous_subscription_data', true ), true );
		$get_user_old_order        = json_decode( get_user_meta( $member_id, 'urm_previous_order_data', true ), true );
		$new_subscription_data     = json_decode( get_user_meta( $member_id, 'urm_next_subscription_data', true ), true );
		$subscription_service      = new SubscriptionService();

		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf(
				'[Member ID #%s] Handling membership upgrade in new PayPal REST service.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'member_id'           => $member_id,
					'old_subscription_id' => isset( $get_user_old_subscription['ID'] ) ? $get_user_old_subscription['ID'] : 'unknown',
					'new_subscription_id' => $subscription_id,
				),
				JSON_PRETTY_PRINT
			),
			'notice'
		);

		if ( ! empty( $new_subscription_data ) ) {
			if ( empty( $new_subscription_data['delayed_until'] ) && ! empty( $get_user_old_subscription['subscription_id'] ) ) {
				$cancel_subscription = $subscription_service->cancel_subscription( $get_user_old_order, $get_user_old_subscription, true );

				if ( empty( $cancel_subscription['status'] ) ) {
					PaymentGatewayLogging::log_error(
						'paypal',
						sprintf(
							'[Member ID #%s] Failed to cancel previous subscription during upgrade.',
							$member_id
						) . "\n" . wp_json_encode(
							array(
								'member_id'           => $member_id,
								'old_subscription_id' => isset( $get_user_old_subscription['subscription_id'] ) ? $get_user_old_subscription['subscription_id'] : '',
								'message'             => isset( $cancel_subscription['message'] ) ? $cancel_subscription['message'] : '',
							),
							JSON_PRETTY_PRINT
						)
					);
				}

				delete_user_meta( $member_id, 'urm_previous_order_data' );
				delete_user_meta( $member_id, 'urm_previous_subscription_data' );
				delete_user_meta( $member_id, 'urm_next_subscription_data' );
			}

			$subscription_data           = $subscription_service->prepare_upgrade_subscription_data( $new_subscription_data['membership'], $new_subscription_data['member_id'], $new_subscription_data );
			$subscription_data['status'] = 'active';
			$this->subscription_repository->update( $subscription_id, $subscription_data );
		}

		$membership_process = urm_get_membership_process( $member_id );
		if ( ! empty( $membership_process ) && isset( $membership_process['upgrade'][ $get_user_old_subscription['item_id'] ] ) ) {
			unset( $membership_process['upgrade'][ $get_user_old_subscription['item_id'] ] );
			update_user_meta( $member_id, 'urm_membership_process', $membership_process );
		}

		update_user_meta( $member_id, 'urm_is_user_upgraded', 1 );

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf(
				'[Member ID #%s] Membership upgrade completed successfully.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'member_id'           => $member_id,
					'new_subscription_id' => $subscription_id,
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Handle REST webhook event payload.
	 *
	 * This is a best-effort implementation for common REST webhook events.
	 * Wire this to your webhook controller endpoint after signature verification if you add it later.
	 *
	 * @param array $event
	 *
	 * @return bool
	 */
	public function handle_webhook_event( $event ) {
		if ( empty( $event ) || ! is_array( $event ) ) {
			return false;
		}

		$event_type = sanitize_text_field( isset( $event['event_type'] ) ? $event['event_type'] : '' );
		$resource   = isset( $event['resource'] ) ? $event['resource'] : array();

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			sprintf(
				'PayPal REST webhook processing started for event: %s.',
				$event_type
			) . "\n" . wp_json_encode(
				array(
					'event_type'  => $event_type,
					'resource_id' => isset( $resource['id'] ) ? $resource['id'] : '',
					'event_id'    => isset( $event['id'] ) ? $event['id'] : '',
				),
				JSON_PRETTY_PRINT
			)
		);

		switch ( $event_type ) {
			case 'CHECKOUT.ORDER.APPROVED':
			case 'PAYMENT.CAPTURE.COMPLETED':
				$result = $this->handle_order_webhook_event( $event_type, $resource );
				break;

			case 'PAYMENT.CAPTURE.REFUNDED':
			case 'PAYMENT.SALE.REFUNDED':
				$result = $this->handle_refund_webhook_event( $event_type, $resource );
				break;

			case 'BILLING.SUBSCRIPTION.CREATED':
			case 'BILLING.SUBSCRIPTION.ACTIVATED':
			case 'BILLING.SUBSCRIPTION.UPDATED':
			case 'BILLING.SUBSCRIPTION.SUSPENDED':
			case 'BILLING.SUBSCRIPTION.CANCELLED':
			case 'BILLING.SUBSCRIPTION.EXPIRED':
			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				$result = $this->handle_subscription_webhook_event( $event_type, $resource );
				break;

			case 'PAYMENT.SALE.COMPLETED':
				$result = $this->handle_subscription_sale_webhook_event( $resource );
				break;

			default:
				PaymentGatewayLogging::log_general(
					'paypal',
					sprintf(
						'Unhandled PayPal webhook event type: %s.',
						$event_type
					) . "\n" . wp_json_encode(
						array(
							'event_type' => $event_type,
						),
						JSON_PRETTY_PRINT
					),
					'info'
				);
				$result = true;
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf(
				'PayPal REST webhook processing completed for event: %s.',
				$event_type
			) . "\n" . wp_json_encode(
				array(
					'event_type' => $event_type,
					'result'     => $result ? 'success' : 'failed',
				),
				JSON_PRETTY_PRINT
			),
			$result ? 'success' : 'warning'
		);

		return $result;
	}

	/**
	 * Handle order-related REST webhook.
	 *
	 * @param string $event_type
	 * @param array  $resource
	 *
	 * @return bool
	 */
	private function handle_order_webhook_event( $event_type, $resource ) {
		$custom_id = isset( $resource['purchase_units'][0]['custom_id'] ) ? $resource['purchase_units'][0]['custom_id'] : '';

		if ( empty( $custom_id ) ) {
			// PAYMENT.CAPTURE.COMPLETED sends a capture resource with no purchase_units,
			// so custom_id is absent. Look up the order by transaction ID instead.
			if ( 'PAYMENT.CAPTURE.COMPLETED' === $event_type && ! empty( $resource['id'] ) ) {
				$transaction_id = sanitize_text_field( $resource['id'] );
				$order          = $this->orders_repository->get_order_by_transaction_id( $transaction_id );

				if ( ! empty( $order ) ) {
					if ( 'completed' === $order['status'] ) {
						PaymentGatewayLogging::log_general(
							'paypal',
							sprintf(
								'PAYMENT.CAPTURE.COMPLETED webhook skipped: order already completed (transaction %s).',
								$transaction_id
							),
							'info'
						);
						return true;
					}

					// Redirect was missed — complete via transaction ID lookup.
					$this->orders_repository->update( $order['ID'], array( 'status' => 'completed' ) );

					$member_subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $order['subscription_id'] );
					if ( ! empty( $member_subscription['ID'] ) ) {
						$this->members_subscription_repository->update(
							$member_subscription['ID'],
							array(
								'status'     => 'active',
								'start_date' => date( 'Y-m-d 00:00:00' ),
							)
						);
					}

					PaymentGatewayLogging::log_transaction_success(
						'paypal',
						sprintf(
							'PAYMENT.CAPTURE.COMPLETED: completed order %d via transaction ID lookup (transaction %s).',
							$order['ID'],
							$transaction_id
						)
					);
					return true;
				}
			}

			// No custom_id and could not resolve via transaction ID — delegate to non-membership handlers.
			return apply_filters(
				'user_registration_paypal_webhook_event_fallback',
				false,
				array(
					'event_type' => $event_type,
					'resource'   => $resource,
				)
			);
		}

		$parsed = $this->parse_custom_id( $custom_id );
		if ( empty( $parsed['member_id'] ) ) {
			return false;
		}

		$member_id    = absint( $parsed['member_id'] );
		$member_order = $this->members_orders_repository->get_member_orders( $member_id );

		if ( empty( $member_order ) ) {
			// No membership order for this member_id — may be a normal-registration event.
			// Delegate to non-membership handlers if any are registered.
			return apply_filters(
				'user_registration_paypal_webhook_event_fallback',
				false,
				array(
					'event_type' => $event_type,
					'resource'   => $resource,
				)
			);
		}

		$transaction_id       = '';
		$current_order_status = isset( $member_order['status'] ) ? $member_order['status'] : '';

		if ( ! empty( $resource['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
			$transaction_id = sanitize_text_field( $resource['purchase_units'][0]['payments']['captures'][0]['id'] );
		} elseif ( ! empty( $resource['id'] ) ) {
			$transaction_id = sanitize_text_field( $resource['id'] );
		}

		if ( 'completed' === $current_order_status ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				sprintf(
					'[Member ID #%s] Order webhook skipped: order already completed by redirect handler.',
					$member_id
				) . "\n" . wp_json_encode(
					array(
						'event_type'     => $event_type,
						'member_id'      => $member_id,
						'current_status' => $current_order_status,
						'transaction_id' => isset( $member_order['transaction_id'] ) ? $member_order['transaction_id'] : '',
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);
			return true;
		}

		// Redirect was missed — webhook acting as fallback to complete the order.
		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf(
				'[Member ID #%s] Order not yet completed — webhook acting as fallback.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'event_type'     => $event_type,
					'member_id'      => $member_id,
					'current_status' => $current_order_status,
					'transaction_id' => $transaction_id,
				),
				JSON_PRETTY_PRINT
			),
			'notice'
		);

		$this->members_orders_repository->update(
			$member_order['ID'],
			array(
				'status'         => 'completed',
				'transaction_id' => $transaction_id,
			)
		);

		$member_subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $member_order['subscription_id'] );
		if ( ! empty( $member_subscription['ID'] ) ) {
			$this->members_subscription_repository->update(
				$member_subscription['ID'],
				array(
					'status'     => 'active',
					'start_date' => date( 'Y-m-d 00:00:00' ),
				)
			);
		}

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf(
				'[Member ID #%s] Order webhook fallback completed successfully.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'event_type'     => $event_type,
					'member_id'      => $member_id,
					'transaction_id' => $transaction_id,
				),
				JSON_PRETTY_PRINT
			)
		);

		return true;
	}

	/**
	 * Handle PAYMENT.SALE.COMPLETED webhook (subscription renewal retry succeeded).
	 *
	 * @param array $resource
	 * @return bool
	 */
	private function handle_subscription_payment_completed( $resource ) {
		$paypal_subscription_id = sanitize_text_field( isset( $resource['billing_agreement_id'] ) ? $resource['billing_agreement_id'] : '' );

		if ( empty( $paypal_subscription_id ) ) {
			return true;
		}

		$current_subscription = $this->members_subscription_repository->get_membership_by_subscription_id( $paypal_subscription_id, true );

		if ( empty( $current_subscription ) ) {
			return true;
		}

		// Only recover from pending; already-active needs no change, terminal states must not be resurrected.
		if ( 'pending' !== $current_subscription['status'] ) {
			return true;
		}

		// Don't activate a future-dated subscription early (mirrors activate_pending_with_completed_orders()).
		$sub_data   = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $current_subscription['sub_id'] );
		$start_date = isset( $sub_data['start_date'] ) ? $sub_data['start_date'] : null;

		if ( ! empty( $start_date ) && strtotime( $start_date ) > time() ) {
			return true;
		}

		$this->members_subscription_repository->update(
			$current_subscription['sub_id'],
			array( 'status' => 'active' )
		);

		PaymentGatewayLogging::log_webhook_processed(
			'paypal',
			'PAYMENT.SALE.COMPLETED: subscription restored to active after successful retry',
			array(
				'paypal_subscription_id' => $paypal_subscription_id,
				'sub_id'                 => $current_subscription['sub_id'],
				'member_id'              => $current_subscription['user_id'],
			)
		);

		return true;
	}

	/**
	 * Handle PAYMENT.CAPTURE.REFUNDED and PAYMENT.SALE.REFUNDED webhooks.
	 *
	 * For CAPTURE.REFUNDED the original capture ID is extracted from the 'up'
	 * HATEOAS link in the refund resource and matched against order.transaction_id.
	 * For SALE.REFUNDED the original sale ID is in resource.sale_id.
	 *
	 * @param string $event_type
	 * @param array  $resource
	 * @return bool
	 */
	private function handle_refund_webhook_event( $event_type, $resource ) {
		if ( 'PAYMENT.CAPTURE.REFUNDED' === $event_type ) {
			$transaction_id = null;
			$links          = isset( $resource['links'] ) ? $resource['links'] : array();
			foreach ( $links as $link ) {
				if ( 'up' === ( isset( $link['rel'] ) ? $link['rel'] : '' ) && ! empty( $link['href'] ) ) {
					$transaction_id = basename( rtrim( $link['href'], '/' ) );
					break;
				}
			}
		} else {
			// PAYMENT.SALE.REFUNDED: sale_id is the original sale transaction ID.
			$transaction_id = isset( $resource['sale_id'] ) ? $resource['sale_id'] : null;
		}

		if ( empty( $transaction_id ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf( 'PayPal %s webhook: could not determine original transaction ID.', $event_type ),
				array(
					'error_code' => 'NO_TRANSACTION_ID',
					'event_type' => $event_type,
				)
			);
			return false;
		}

		$order = $this->orders_repository->get_order_by_transaction_id( $transaction_id );
		if ( empty( $order ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				sprintf( 'PayPal %s webhook: no local order for transaction %s.', $event_type, $transaction_id ),
				array(
					'error_code'     => 'ORDER_NOT_FOUND',
					'transaction_id' => $transaction_id,
				)
			);
			return false;
		}

		$this->orders_repository->update( $order['ID'], array( 'status' => 'refunded' ) );

		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf( 'PayPal %s: marked order %d as refunded (transaction %s).', $event_type, $order['ID'], $transaction_id ),
			'success'
		);

		return true;
	}

	/**
	 * Handle subscription-related REST webhook.
	 *
	 * @param string $event_type
	 * @param array  $resource
	 *
	 * @return bool
	 */
	private function handle_subscription_webhook_event( $event_type, $resource ) {
		$custom_id = isset( $resource['custom_id'] ) ? $resource['custom_id'] : '';
		$parsed    = $this->parse_custom_id( $custom_id );

		$member_id              = absint( isset( $parsed['member_id'] ) ? $parsed['member_id'] : 0 );
		$subscription_row_id    = isset( $parsed['subscription_id'] ) ? $parsed['subscription_id'] : '';
		$paypal_subscription_id = sanitize_text_field( isset( $resource['id'] ) ? $resource['id'] : '' );

		if ( empty( $member_id ) || empty( $subscription_row_id ) ) {
			return false;
		}

		$member_subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $subscription_row_id );
		if ( empty( $member_subscription ) ) {
			return false;
		}

		$status_map = array(
			'BILLING.SUBSCRIPTION.CREATED'        => 'pending',
			'BILLING.SUBSCRIPTION.ACTIVATED'      => 'active',
			'BILLING.SUBSCRIPTION.UPDATED'        => isset( $member_subscription['status'] ) ? $member_subscription['status'] : 'active',
			'BILLING.SUBSCRIPTION.SUSPENDED'      => 'canceled',
			'BILLING.SUBSCRIPTION.CANCELLED'      => 'canceled',
			'BILLING.SUBSCRIPTION.EXPIRED'        => 'expired',
			'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'pending',
		);

		$new_status      = isset( $status_map[ $event_type ] ) ? $status_map[ $event_type ] : ( isset( $member_subscription['status'] ) ? $member_subscription['status'] : 'pending' );
		$current_status  = isset( $member_subscription['status'] ) ? $member_subscription['status'] : '';
		$terminal_states = array( 'canceled', 'expired' );

		if ( 'BILLING.SUBSCRIPTION.PAYMENT.FAILED' === $event_type && in_array( $current_status, $terminal_states, true ) ) {
			return true; // ignore late failure events for already-terminated subscriptions
		}

		$this->members_subscription_repository->update(
			$member_subscription['ID'],
			array(
				'status'          => $new_status,
				'subscription_id' => $paypal_subscription_id,
			)
		);

		if ( 'active' === $new_status ) {
			$member_order         = $this->members_orders_repository->get_member_orders( $member_id );
			$current_order_status = isset( $member_order['status'] ) ? $member_order['status'] : '';

			if ( ! empty( $member_order['ID'] ) ) {
				if ( 'completed' === $current_order_status ) {
					$current_transaction_id = isset( $member_order['transaction_id'] ) ? $member_order['transaction_id'] : '';

					if ( empty( $current_transaction_id ) ) {
						// Order completed (by redirect) but transaction_id was never stored — fill it in now.
						$this->members_orders_repository->update(
							$member_order['ID'],
							array( 'transaction_id' => $paypal_subscription_id )
						);

						PaymentGatewayLogging::log_general(
							'paypal',
							sprintf(
								'[Member ID #%s] Subscription webhook: order already completed but transaction_id was empty — synced.',
								$member_id
							) . "\n" . wp_json_encode(
								array(
									'event_type' => $event_type,
									'member_id'  => $member_id,
									'paypal_subscription_id' => $paypal_subscription_id,
								),
								JSON_PRETTY_PRINT
							),
							'info'
						);
					} else {
						PaymentGatewayLogging::log_general(
							'paypal',
							sprintf(
								'[Member ID #%s] Subscription webhook order update skipped: order already completed by redirect handler.',
								$member_id
							) . "\n" . wp_json_encode(
								array(
									'event_type'           => $event_type,
									'member_id'            => $member_id,
									'paypal_subscription_id' => $paypal_subscription_id,
									'current_order_status' => $current_order_status,
									'transaction_id'       => $current_transaction_id,
								),
								JSON_PRETTY_PRINT
							),
							'info'
						);
					}
				} else {
					// Redirect was missed — webhook acting as fallback to complete the order.
					PaymentGatewayLogging::log_general(
						'paypal',
						sprintf(
							'[Member ID #%s] Subscription order not yet completed — webhook acting as fallback.',
							$member_id
						) . "\n" . wp_json_encode(
							array(
								'event_type'             => $event_type,
								'member_id'              => $member_id,
								'paypal_subscription_id' => $paypal_subscription_id,
								'current_order_status'   => $current_order_status,
							),
							JSON_PRETTY_PRINT
						),
						'notice'
					);

					$this->members_orders_repository->update(
						$member_order['ID'],
						array(
							'status'         => 'completed',
							'transaction_id' => $paypal_subscription_id,
						)
					);
				}
			}
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf(
				'[Member ID #%s] Subscription webhook processed.',
				$member_id
			) . "\n" . wp_json_encode(
				array(
					'event_type'             => $event_type,
					'member_id'              => $member_id,
					'subscription_row_id'    => $subscription_row_id,
					'paypal_subscription_id' => $paypal_subscription_id,
					'new_status'             => $new_status,
				),
				JSON_PRETTY_PRINT
			),
			'success'
		);

		return true;
	}

	/**
	 * Handle PAYMENT.SALE.COMPLETED webhook for subscription payments.
	 * Stores the real PayPal sale/transaction ID, replacing any subscription-ID placeholder.
	 *
	 * @param array $resource Webhook resource payload.
	 *
	 * @return bool
	 */
	private function handle_subscription_sale_webhook_event( $resource ) {
		$transaction_id         = sanitize_text_field( isset( $resource['id'] ) ? $resource['id'] : '' );
		$paypal_subscription_id = sanitize_text_field( isset( $resource['billing_agreement_id'] ) ? $resource['billing_agreement_id'] : '' );

		if ( empty( $transaction_id ) || empty( $paypal_subscription_id ) ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				'[PAYMENT.SALE.COMPLETED] Skipped — missing transaction_id or billing_agreement_id.' . "\n" . wp_json_encode(
					array(
						'transaction_id'         => $transaction_id ?: null,
						'paypal_subscription_id' => $paypal_subscription_id ?: null,
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);
			return false;
		}

		// Already have an order for this exact sale — nothing to update.
		$existing = $this->orders_repository->get_order_by_transaction_id( $transaction_id );
		if ( ! empty( $existing ) ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				'[PAYMENT.SALE.COMPLETED] Order already exists for this transaction — skipped.' . "\n" . wp_json_encode(
					array(
						'transaction_id' => $transaction_id,
						'order_id'       => $existing['ID'],
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);
			return true;
		}

		// Find local subscription record by PayPal subscription ID.
		$membership_subscription = $this->members_subscription_repository->get_subscription_by_subscription_id_meta( $paypal_subscription_id );
		if ( empty( $membership_subscription ) ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				'[PAYMENT.SALE.COMPLETED] No local subscription found for PayPal subscription ID.' . "\n" . wp_json_encode(
					array(
						'paypal_subscription_id' => $paypal_subscription_id,
						'transaction_id'         => $transaction_id,
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);
			return false;
		}

		$local_sub_id = $membership_subscription['ID'];
		$user_id      = $membership_subscription['user_id'];

		// Replace placeholder order whose transaction_id is the subscription ID (not a real sale ID).
		$placeholder = $this->orders_repository->get_order_by_transaction_id( $paypal_subscription_id );
		if ( ! empty( $placeholder ) && ! empty( $placeholder['ID'] ) ) {
			$this->orders_repository->update(
				$placeholder['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $transaction_id,
				)
			);
			PaymentGatewayLogging::log_general(
				'paypal',
				'[PAYMENT.SALE.COMPLETED] Placeholder order updated with real transaction ID.' . "\n" . wp_json_encode(
					array(
						'order_id'               => $placeholder['ID'],
						'member_id'              => $user_id,
						'paypal_subscription_id' => $paypal_subscription_id,
						'transaction_id'         => $transaction_id,
					),
					JSON_PRETTY_PRINT
				),
				'success'
			);
			return true;
		}

		// Update a pending order that has no transaction_id yet.
		$pending_order = $this->orders_repository->get_order_by_subscription( $local_sub_id );
		if (
			! empty( $pending_order['ID'] ) &&
			'pending' === ( $pending_order['status'] ?? '' ) &&
			'' === (string) ( $pending_order['transaction_id'] ?? '' )
		) {
			$this->orders_repository->update(
				$pending_order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $transaction_id,
				)
			);
			PaymentGatewayLogging::log_general(
				'paypal',
				'[PAYMENT.SALE.COMPLETED] Pending order completed with real transaction ID.' . "\n" . wp_json_encode(
					array(
						'order_id'               => $pending_order['ID'],
						'member_id'              => $user_id,
						'paypal_subscription_id' => $paypal_subscription_id,
						'transaction_id'         => $transaction_id,
					),
					JSON_PRETTY_PRINT
				),
				'success'
			);
			return true;
		}

		// No matching order found — likely a renewal payment handled by the backfill job.
		PaymentGatewayLogging::log_general(
			'paypal',
			'[PAYMENT.SALE.COMPLETED] No matching order found for transaction — skipped (backfill will handle renewals).' . "\n" . wp_json_encode(
				array(
					'paypal_subscription_id' => $paypal_subscription_id,
					'transaction_id'         => $transaction_id,
				),
				JSON_PRETTY_PRINT
			),
			'info'
		);

		return true;
	}

	/**
	 * Parse custom id format: membership-member_id-current_membership_id-subscription_id
	 *
	 * @param string $custom_id
	 *
	 * @return array
	 */
	private function parse_custom_id( $custom_id ) {
		$parts = explode( '-', (string) $custom_id );

		return array(
			'membership'            => isset( $parts[0] ) ? $parts[0] : '',
			'member_id'             => isset( $parts[1] ) ? $parts[1] : '',
			'current_membership_id' => isset( $parts[2] ) ? $parts[2] : '',
			'subscription_id'       => isset( $parts[3] ) ? $parts[3] : '',
		);
	}

	/**
	 * Validate setup.
	 *
	 * Returns true when setup is incomplete, keeping old behavior.
	 *
	 * @param string $membership_type
	 *
	 * @return bool
	 */
	public function validate_setup( $membership_type ) {
		$paypal_enabled        = get_option( 'user_registration_paypal_enabled', '' );
		$is_old_paypal_install = ur_is_paypal_old_installation();
		$has_user_changed      = ur_string_to_bool( get_option( 'urm_paypal_updated_connection_status', false ) );
		$is_paypal_enabled     = ( $paypal_enabled ) ? $paypal_enabled : ( $has_user_changed ? $paypal_enabled : $is_old_paypal_install );

		if ( ! $is_paypal_enabled ) {
			return true;
		}

		$mode     = $this->get_paypal_mode();
		$mode_key = $this->get_paypal_mode_key();

		$required = array(
			'client_id'     => get_option( sprintf( 'user_registration_global_paypal_%s_client_id', $mode_key ), get_option( 'user_registration_global_paypal_client_id', '' ) ),
			'client_secret' => get_option( sprintf( 'user_registration_global_paypal_%s_client_secret', $mode_key ), get_option( 'user_registration_global_paypal_client_secret', '' ) ),
		);

		// Keep compatibility with old one-time standard/email validation if needed by your UI.
		if ( 'subscription' !== $membership_type ) {
			$required['email'] = get_option(
				sprintf( 'user_registration_global_paypal_%s_email_address', $mode ),
				get_option( 'user_registration_global_paypal_email_address', '' )
			);
		}

		foreach ( $required as $value ) {
			if ( empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cancel subscription using REST API.
	 *
	 * @param array $order
	 * @param array $subscription
	 *
	 * @return array
	 */
	public function cancel_subscription( $order, $subscription, $force_cancel = false ) {
		if ( empty( $subscription['subscription_id'] ) ) {
			$message = esc_html__( 'PayPal subscription ID not present.', 'user-registration' );
			return array(
				'status'  => false,
				'message' => $message,
			);
		}

		$paypal_options = $this->get_paypal_rest_credentials();

		if ( $force_cancel ) {
			$response = $this->cancel_paypal_subscription(
				$subscription['subscription_id'],
				array(
					'reason' => 'Membership upgrade',
				),
				$paypal_options
			);
		} else {
			$response = $this->suspend_paypal_subscription(
				$subscription['subscription_id'],
				array(
					'reason' => 'User initiated cancellation',
				),
				$paypal_options
			);
		}

		if ( is_wp_error( $response ) ) {
			PaymentGatewayLogging::log_transaction_failure(
				'paypal',
				sprintf(
					'Subscription cancellation failed from PayPal for subscription %s.',
					$subscription['subscription_id']
				) . "\n" . wp_json_encode(
					array(
						'event_type'      => 'cancellation_failed',
						'message'         => $response->get_error_message(),
						'subscription_id' => $subscription['subscription_id'],
					),
					JSON_PRETTY_PRINT
				)
			);

			return array(
				'status'  => false,
				'message' => $response->get_error_message(),
			);
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf(
				'Subscription successfully cancelled from PayPal for subscription ***%s***.',
				$subscription['subscription_id']
			) . "\n" . wp_json_encode(
				array(
					'event_type'      => 'cancellation_success',
					'subscription_id' => $subscription['subscription_id'],
				),
				JSON_PRETTY_PRINT
			),
			'success'
		);

		return array(
			'status' => true,
		);
	}

	/**
	 * Reactivate subscription using REST API.
	 *
	 * @param string $subscription_id
	 *
	 * @return array
	 */
	public function reactivate_subscription( $subscription_id ) {
		$paypal_options = $this->get_paypal_rest_credentials();

		$response = $this->activate_paypal_subscription(
			$subscription_id,
			array(
				'reason' => 'User initiated reactivation',
			),
			$paypal_options
		);

		if ( is_wp_error( $response ) ) {
			PaymentGatewayLogging::log_transaction_failure(
				'paypal',
				sprintf(
					'Subscription reactivation failed from PayPal for subscription %s.',
					$subscription_id
				) . "\n" . wp_json_encode(
					array(
						'event_type'      => 'reactivation_failed',
						'message'         => $response->get_error_message(),
						'subscription_id' => $subscription_id,
					),
					JSON_PRETTY_PRINT
				)
			);

			return array(
				'status'  => false,
				'message' => $response->get_error_message(),
			);
		}

		PaymentGatewayLogging::log_subscription_reactivation(
			'paypal',
			sprintf(
				'Subscription successfully reactivated from PayPal for subscription ***%s***.',
				$subscription_id
			) . "\n" . wp_json_encode(
				array(
					'event_type'      => 'reactivation_success',
					'subscription_id' => $subscription_id,
				),
				JSON_PRETTY_PRINT
			)
		);

		return array(
			'status' => true,
		);
	}

	/**
	 * Retry subscription by reactivating suspended/cancelled subscription when possible.
	 *
	 * @param array $subscription
	 *
	 * @return array
	 */
	public function retry_subscription( $subscription ) {
		$response = array(
			'status'  => false,
			'message' => '',
		);

		$paypal_sub_id = isset( $subscription['sub_id'] ) ? $subscription['sub_id'] : ( isset( $subscription['subscription_id'] ) ? $subscription['subscription_id'] : '' );
		if ( empty( $paypal_sub_id ) ) {
			$response['message'] = __( 'Subscription ID not found', 'user-registration' );
			return $response;
		}

		$paypal_options      = $this->get_paypal_rest_credentials();
		$subscription_lookup = $this->get_paypal_subscription( $paypal_sub_id, $paypal_options );

		if ( is_wp_error( $subscription_lookup ) ) {
			$response['message'] = $subscription_lookup->get_error_message();
			return $response;
		}

		$paypal_status = strtoupper( isset( $subscription_lookup['status'] ) ? $subscription_lookup['status'] : '' );

		if ( in_array( $paypal_status, array( 'SUSPENDED', 'CANCELLED' ), true ) ) {
			$reactivate = $this->activate_paypal_subscription(
				$paypal_sub_id,
				array(
					'reason' => 'Payment retry - system initiated reactivation',
				),
				$paypal_options
			);

			if ( is_wp_error( $reactivate ) ) {
				$response['message'] = $reactivate->get_error_message();
				return $response;
			}

			$response['status']  = true;
			$response['message'] = __( 'Subscription payment retried and reactivated successfully', 'user-registration' );
			return $response;
		}

		if ( 'ACTIVE' === $paypal_status ) {
			$response['status']  = true;
			$response['message'] = __( 'Subscription is already active', 'user-registration' );
			return $response;
		}

		$response['message'] = sprintf(
			__( 'Subscription status is %s and cannot be retried', 'user-registration' ),
			$paypal_status
		);

		return $response;
	}

	/**
	 * Old IPN validation is not used for REST.
	 * Keep method for compatibility with existing callers.
	 *
	 * @param string $payment_mode
	 *
	 * @return bool
	 */
	public function validate_ipn( $payment_mode ) {
		// REST should use webhook verification instead of IPN.
		// Returning false prevents accidental IPN usage in new flow.
		PaymentGatewayLogging::log_general(
			'paypal',
			sprintf(
				'validate_ipn called on NewPaypalService; REST flow should use webhooks instead. Payment mode: %s.',
				$payment_mode
			) . "\n" . wp_json_encode(
				array(
					'event_type'   => 'compatibility_notice',
					'payment_mode' => $payment_mode,
				),
				JSON_PRETTY_PRINT
			),
			'info'
		);

		return false;
	}

	/**
	 * Optional compatibility method. If something still calls old IPN handler, use legacy service.
	 *
	 * @param array $data
	 *
	 * @return void
	 * @throws Exception
	 */
	public function handle_membership_paypal_ipn( $data ) {
		// REST should use webhooks, but delegate for compatibility where old listener still exists.
		$this->legacy_service->handle_membership_paypal_ipn( $data );
	}

	/**
	 * Get PayPal mode.
	 *
	 * @return string
	 */
	private function get_paypal_mode() {
		return get_option( 'user_registration_global_paypal_mode', 'test' ) === 'test' ? 'test' : 'production';
	}

	/**
	 * Get the option key segment for the current PayPal mode.
	 * Options are stored under _live_ / _test_ keys, but the mode value is 'production' / 'test'.
	 *
	 * @return string 'live' or 'test'
	 */
	private function get_paypal_mode_key() {
		return 'production' === $this->get_paypal_mode() ? 'live' : 'test';
	}

	/**
	 * Get PayPal REST credentials.
	 *
	 * @return array
	 */
	private function get_paypal_rest_credentials() {
		$mode     = $this->get_paypal_mode();
		$mode_key = $this->get_paypal_mode_key();

		return array(
			'mode'       => $mode,
			'client_id'  => get_option( sprintf( 'user_registration_global_paypal_%s_client_id', $mode_key ), get_option( 'user_registration_global_paypal_client_id', '' ) ),
			'secret_key' => get_option( sprintf( 'user_registration_global_paypal_%s_client_secret', $mode_key ), get_option( 'user_registration_global_paypal_client_secret', '' ) ),
			'email'      => get_option( sprintf( 'user_registration_global_paypal_%s_email_address', $mode_key ), get_option( 'user_registration_global_paypal_email_address', '' ) ),
		);
	}

	/**
	 * Get PayPal API base URL.
	 *
	 * @param string $mode
	 *
	 * @return string
	 */
	private function get_paypal_api_base_url( $mode ) {
		return 'production' === $mode
			? 'https://api-m.paypal.com'
			: 'https://api-m.sandbox.paypal.com';
	}

	/**
	 * Get OAuth access token.
	 *
	 * @param array $paypal_options
	 *
	 * @return string|WP_Error
	 */
	private function get_paypal_access_token( $paypal_options ) {
		$client_id  = trim( (string) ( isset( $paypal_options['client_id'] ) ? $paypal_options['client_id'] : '' ) );
		$secret_key = trim( (string) ( isset( $paypal_options['secret_key'] ) ? $paypal_options['secret_key'] : '' ) );
		$mode       = isset( $paypal_options['mode'] ) ? $paypal_options['mode'] : 'test';

		if ( '' === $client_id || '' === $secret_key ) {
			return new WP_Error(
				'paypal_missing_credentials',
				__( 'PayPal client ID or secret key is missing.', 'user-registration' )
			);
		}

		$response = wp_remote_post(
			$this->get_paypal_api_base_url( $mode ) . '/v1/oauth2/token',
			array(
				'timeout' => 45,
				'headers' => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'en_US',
					'Authorization'   => 'Basic ' . base64_encode( $client_id . ':' . $secret_key ),
					'Content-Type'    => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type' => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $body['access_token'] ) ) {
			return new WP_Error(
				'paypal_access_token_failed',
				isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unable to retrieve PayPal access token.', 'user-registration' ),
				$body
			);
		}

		return $body['access_token'];
	}

	/**
	 * Generic REST request helper.
	 *
	 * @param string     $method
	 * @param string     $path
	 * @param array|null $payload
	 * @param array      $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function paypal_rest_request( $method, $path, $payload, $paypal_options ) {
		$access_token = $this->get_paypal_access_token( $paypal_options );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$args = array(
			'timeout' => 45,
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization'     => 'Bearer ' . $access_token,
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
				'PayPal-Request-Id' => wp_generate_uuid4(),
			),
		);

		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request(
			$this->get_paypal_api_base_url( isset( $paypal_options['mode'] ) ? $paypal_options['mode'] : 'test' ) . $path,
			$args
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$parsed = ! empty( $body ) ? json_decode( $body, true ) : array();

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'paypal_rest_request_failed',
				isset( $parsed['message'] ) ? $parsed['message'] : __( 'PayPal API request failed.', 'user-registration' ),
				array(
					'status' => $status,
					'body'   => $parsed,
				)
			);
		}

		return is_array( $parsed ) ? $parsed : array( 'status' => $status );
	}

	/**
	 * Create order.
	 *
	 * @param array $payload
	 * @param array $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function create_paypal_rest_order( $payload, $paypal_options ) {
		return $this->paypal_rest_request( 'POST', '/v2/checkout/orders', $payload, $paypal_options );
	}

	/**
	 * Capture order.
	 *
	 * @param string $order_id
	 * @param array  $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function capture_paypal_order( $order_id, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			null,
			$paypal_options
		);
	}

	/**
	 * Create subscription.
	 *
	 * @param array $payload
	 * @param array $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function create_paypal_subscription( $payload, $paypal_options ) {
		return $this->paypal_rest_request( 'POST', '/v1/billing/subscriptions', $payload, $paypal_options );
	}

	/**
	 * Get subscription details.
	 *
	 * @param string $subscription_id
	 * @param array  $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function get_paypal_subscription( $subscription_id, $paypal_options ) {
		return $this->paypal_rest_request(
			'GET',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ),
			null,
			$paypal_options
		);
	}

	/**
	 * Fetch transaction list for a PayPal subscription.
	 *
	 * @param string $subscription_id
	 * @param array  $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function get_paypal_subscription_transactions( $subscription_id, $paypal_options ) {
		$start_time = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-1 hour' ) );
		$end_time   = gmdate( 'Y-m-d\TH:i:s\Z' );

		return $this->paypal_rest_request(
			'GET',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/transactions?start_time=' . $start_time . '&end_time=' . $end_time,
			null,
			$paypal_options
		);
	}

	/**
	 * Revise subscription.
	 *
	 * @param string $paypal_subscription_id
	 * @param array  $payload
	 * @param array  $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function revise_paypal_subscription( $paypal_subscription_id, $payload, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $paypal_subscription_id ) . '/revise',
			$payload,
			$paypal_options
		);
	}

	/**
	 * Suspend subscription.
	 *
	 * @param string $subscription_id
	 * @param array  $payload
	 * @param array  $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function suspend_paypal_subscription( $subscription_id, $payload, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/suspend',
			$payload,
			$paypal_options
		);
	}

	private function cancel_paypal_subscription( $subscription_id, $payload, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/cancel',
			$payload,
			$paypal_options
		);
	}

	public function cancel_suspended_subscription( $subscription_id ) {
		$paypal_options = $this->get_paypal_rest_credentials();
		return $this->cancel_paypal_subscription(
			$subscription_id,
			array( 'reason' => 'Subscription period ended' ),
			$paypal_options
		);
	}

	/**
	 * Activate subscription.
	 *
	 * @param string $subscription_id
	 * @param array  $payload
	 * @param array  $paypal_options
	 *
	 * @return array|WP_Error
	 */
	private function activate_paypal_subscription( $subscription_id, $payload, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/activate',
			$payload,
			$paypal_options
		);
	}

	/**
	 * Extract capture id from order response.
	 *
	 * @param array $capture_response
	 *
	 * @return string
	 */
	private function extract_capture_id_from_order_response( $capture_response ) {
		if ( ! empty( $capture_response['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
			return sanitize_text_field( $capture_response['purchase_units'][0]['payments']['captures'][0]['id'] );
		}

		if ( ! empty( $capture_response['id'] ) ) {
			return sanitize_text_field( $capture_response['id'] );
		}

		return '';
	}

	/**
	 * Check whether a PayPal resource (plan or product) still exists in the current account/mode.
	 *
	 * @param string $path          REST path, e.g. '/v1/billing/plans/{id}'.
	 * @param array  $paypal_options
	 * @return bool
	 */
	private function paypal_resource_exists( $path, $paypal_options ) {
		$response = $this->paypal_rest_request( 'GET', $path, null, $paypal_options );
		return ! is_wp_error( $response ) && ! empty( $response['id'] );
	}

	/**
	 * Get existing PayPal plan ID or create product + plan if missing.
	 *
	 * Subscription is created with plan_id, but plan needs product_id first.
	 *
	 * @param array $context
	 * @return string|WP_Error
	 */
	private function get_or_create_paypal_plan_id( $context ) {
		// 1. Runtime-provided plan id.
		$plan_id = isset( $context['data']['paypal_plan_id'] ) ? $context['data']['paypal_plan_id'] : '';
		if ( ! empty( $plan_id ) ) {
			return sanitize_text_field( $plan_id );
		}

		// 2. Cached membership-level plan id by config hash.
		$plan_cache_key = $this->build_paypal_plan_cache_key( $context );
		$plan_meta_key  = '_urm_paypal_plan_id_' . md5( $plan_cache_key );

		$cached_plan_id = get_post_meta( $context['membership'], $plan_meta_key, true );
		if ( ! empty( $cached_plan_id ) ) {
			// Verify the plan still exists in PayPal (handles sandbox resets and credential changes).
			if ( $this->paypal_resource_exists( '/v1/billing/plans/' . $cached_plan_id, $context['paypal_options'] ) ) {
				return sanitize_text_field( $cached_plan_id );
			}
			// Plan gone — clear plan and product caches so everything is rebuilt fresh.
			delete_post_meta( $context['membership'], $plan_meta_key );
			delete_post_meta( $context['membership'], '_urm_paypal_latest_plan_id' );
			delete_post_meta( $context['membership'], '_urm_paypal_product_id' );
		}

		// 3. Product is required before creating plan.
		$product_id = $this->get_or_create_paypal_product_id( $context );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		// 4. Create plan.
		$plan_payload  = $this->build_paypal_plan_payload( $context, $product_id );
		$plan_response = $this->create_paypal_plan( $plan_payload, $context['paypal_options'] );

		if ( is_wp_error( $plan_response ) ) {
			return $plan_response;
		}

		$plan_id = isset( $plan_response['id'] ) ? $plan_response['id'] : '';
		if ( empty( $plan_id ) ) {
			return new \WP_Error(
				'paypal_plan_create_failed',
				__( 'PayPal plan could not be created.', 'user-registration' )
			);
		}

		update_post_meta( $context['membership'], $plan_meta_key, sanitize_text_field( $plan_id ) );
		update_post_meta( $context['membership'], '_urm_paypal_latest_plan_id', sanitize_text_field( $plan_id ) );

		return sanitize_text_field( $plan_id );
	}

	/**
	 * Get or create PayPal product ID.
	 *
	 * @param array $context
	 * @return string|WP_Error
	 */
	private function get_or_create_paypal_product_id( $context ) {
		$product_id = get_post_meta( $context['membership'], '_urm_paypal_product_id', true );
		if ( ! empty( $product_id ) ) {
			// Verify the product still exists in PayPal (handles sandbox resets and credential changes).
			if ( $this->paypal_resource_exists( '/v1/catalogs/products/' . $product_id, $context['paypal_options'] ) ) {
				return sanitize_text_field( $product_id );
			}
			// Product gone — clear cache so a new one is created.
			delete_post_meta( $context['membership'], '_urm_paypal_product_id' );
		}

		$product_payload = array(
			'name'        => sanitize_text_field( isset( $context['membership_data']['post_title'] ) ? $context['membership_data']['post_title'] : 'Membership' ),
			'description' => sanitize_text_field( isset( $context['membership_data']['post_title'] ) ? $context['membership_data']['post_title'] : 'Membership subscription product' ),
			'type'        => 'SERVICE',
			'category'    => 'SOFTWARE',
		);

		$product_response = $this->create_paypal_product( $product_payload, $context['paypal_options'] );

		if ( is_wp_error( $product_response ) ) {
			return $product_response;
		}

		$product_id = isset( $product_response['id'] ) ? $product_response['id'] : '';
		if ( empty( $product_id ) ) {
			return new \WP_Error(
				'paypal_product_create_failed',
				__( 'PayPal product could not be created.', 'user-registration' )
			);
		}

		update_post_meta( $context['membership'], '_urm_paypal_product_id', sanitize_text_field( $product_id ) );

		return sanitize_text_field( $product_id );
	}

	/**
	 * Build PayPal plan payload from membership subscription data.
	 *
	 * @param array  $context
	 * @param string $product_id
	 * @return array
	 */
	private function build_paypal_plan_payload( $context, $product_id ) {
		$subscription_data = ! empty( $context['has_team'] )
		? array(
			'duration' => isset( $context['data']['team_data']['team_duration_period'] ) ? $context['data']['team_data']['team_duration_period'] : '',
			'value'    => isset( $context['data']['team_data']['team_duration_value'] ) ? $context['data']['team_data']['team_duration_value'] : 1,
		)
		: ( isset( $context['data']['subscription'] ) ? $context['data']['subscription'] : array() );

		$duration = strtoupper( substr( (string) ( isset( $subscription_data['duration'] ) ? $subscription_data['duration'] : '' ), 0, 1 ) );
		$value    = max( 1, (int) ( isset( $subscription_data['value'] ) ? $subscription_data['value'] : 1 ) );

		$interval_unit_map = array(
			'D' => 'DAY',
			'W' => 'WEEK',
			'M' => 'MONTH',
			'Y' => 'YEAR',
		);

		$interval_unit = isset( $interval_unit_map[ $duration ] ) ? $interval_unit_map[ $duration ] : 'MONTH';

		$billing_cycles = array(
			array(
				'frequency'      => array(
					'interval_unit'  => $interval_unit,
					'interval_count' => $value,
				),
				'tenure_type'    => 'REGULAR',
				'sequence'       => 1,
				'total_cycles'   => 0,
				'pricing_scheme' => array(
					'fixed_price' => array(
						'currency_code' => $context['currency'],
						'value'         => $context['regular_amount'],
					),
				),
			),
		);
		// Coupon: plan must define a TRIAL cycle so the subscription override can set a discounted
		// first-cycle price. Skipped for full-discount subs (UR-4386) — they use a plain REGULAR plan
		// + future start_time instead.
		if (
			! empty( $context['coupon_details'] ) &&
			empty( $context['is_full_discount_sub'] ) &&
			( empty( $context['data']['trial_status'] ) || 'on' !== $context['data']['trial_status'] )
		) {
			$billing_cycles = array(
				array(
					'frequency'      => array(
						'interval_unit'  => $interval_unit,
						'interval_count' => $value,
					),
					'tenure_type'    => 'TRIAL',
					'sequence'       => 1,
					'total_cycles'   => 1,
					'pricing_scheme' => array(
						'fixed_price' => array(
							'currency_code' => $context['currency'],
							'value'         => $context['regular_amount'],
						),
					),
				),
				array(
					'frequency'      => array(
						'interval_unit'  => $interval_unit,
						'interval_count' => $value,
					),
					'tenure_type'    => 'REGULAR',
					'sequence'       => 2,
					'total_cycles'   => 0,
					'pricing_scheme' => array(
						'fixed_price' => array(
							'currency_code' => $context['currency'],
							'value'         => $context['regular_amount'],
						),
					),
				),
			);
		}
		// Simple trial support.
		if (
		! empty( $context['data']['trial_status'] ) &&
		'on' === $context['data']['trial_status'] &&
		! empty( $context['data']['trial_data'] )
		) {
			$trial_duration = strtoupper( substr( (string) ( isset( $context['data']['trial_data']['duration'] ) ? $context['data']['trial_data']['duration'] : '' ), 0, 1 ) );
			$trial_value    = max( 1, (int) ( isset( $context['data']['trial_data']['value'] ) ? $context['data']['trial_data']['value'] : 1 ) );
			$trial_unit     = isset( $interval_unit_map[ $trial_duration ] ) ? $interval_unit_map[ $trial_duration ] : 'MONTH';

			$billing_cycles = array(
				array(
					'frequency'    => array(
						'interval_unit'  => $trial_unit,
						'interval_count' => $trial_value,
					),
					'tenure_type'  => 'TRIAL',
					'sequence'     => 1,
					'total_cycles' => 1,
				),
				array(
					'frequency'      => array(
						'interval_unit'  => $interval_unit,
						'interval_count' => $value,
					),
					'tenure_type'    => 'REGULAR',
					'sequence'       => 2,
					'total_cycles'   => 0,
					'pricing_scheme' => array(
						'fixed_price' => array(
							'currency_code' => $context['currency'],
							'value'         => $context['regular_amount'],
						),
					),
				),
			);
		}

		$payload = array(
			'product_id'          => sanitize_text_field( $product_id ),
			'name'                => sanitize_text_field( isset( $context['membership_data']['post_title'] ) ? $context['membership_data']['post_title'] : 'Membership Plan' ),
			'description'         => sanitize_text_field( isset( $context['item_name'] ) ? $context['item_name'] : 'Membership subscription plan' ),
			'status'              => 'ACTIVE',
			'billing_cycles'      => $billing_cycles,
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'setup_fee_failure_action'  => 'CONTINUE',
				'payment_failure_threshold' => 3,
			),
		);

		if ( ! empty( $context['team_quantity'] ) ) {
			$payload['quantity_supported'] = true;
		}

		if ( ! empty( $context['tax_rate'] ) && (float) $context['tax_rate'] > 0 ) {
			$payload['taxes'] = array(
				'percentage' => number_format( (float) $context['tax_rate'], 2, '.', '' ),
				'inclusive'  => false,
			);
		}

		return $payload;
	}

	/**
	 * Build a stable cache key for plan reuse.
	 *
	 * @param array $context
	 * @return string
	 */
	private function build_paypal_plan_cache_key( $context ) {
		$subscription_data = ! empty( $context['has_team'] )
		? array(
			'duration' => isset( $context['data']['team_data']['team_duration_period'] ) ? $context['data']['team_data']['team_duration_period'] : '',
			'value'    => isset( $context['data']['team_data']['team_duration_value'] ) ? $context['data']['team_data']['team_duration_value'] : 1,
		)
		: ( isset( $context['data']['subscription'] ) ? $context['data']['subscription'] : array() );

		return wp_json_encode(
			array(
				'membership_id'    => $context['membership'],
				'currency'         => $context['currency'],
				'amount'           => $context['regular_amount'],
				'duration'         => isset( $subscription_data['duration'] ) ? $subscription_data['duration'] : '',
				'value'            => isset( $subscription_data['value'] ) ? $subscription_data['value'] : 1,
				'team_quantity'    => isset( $context['team_quantity'] ) ? $context['team_quantity'] : '',
				'trial_status'     => isset( $context['data']['trial_status'] ) ? $context['data']['trial_status'] : '',
				'trial_data'       => isset( $context['data']['trial_data'] ) ? $context['data']['trial_data'] : array(),
				'tax_rate'         => isset( $context['tax_rate'] ) ? $context['tax_rate'] : 0,
				'has_coupon_cycle' => ! empty( $context['coupon_details'] ) && empty( $context['is_full_discount_sub'] ) && ( empty( $context['data']['trial_status'] ) || 'on' !== $context['data']['trial_status'] ),
			)
		);
	}

	/**
	 * Create PayPal product.
	 *
	 * @param array $payload
	 * @param array $paypal_options
	 * @return array|WP_Error
	 */
	private function create_paypal_product( $payload, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v1/catalogs/products',
			$payload,
			$paypal_options
		);
	}

	/**
	 * Create PayPal billing plan.
	 *
	 * @param array $payload
	 * @param array $paypal_options
	 * @return array|WP_Error
	 */
	private function create_paypal_plan( $payload, $paypal_options ) {
		return $this->paypal_rest_request(
			'POST',
			'/v1/billing/plans',
			$payload,
			$paypal_options
		);
	}

	/**
	 * Get the REST URL for this site's PayPal webhook endpoint.
	 *
	 * @return string
	 */
	// -------------------------------------------------------------------------
	// Missed-payment backfill (hourly cron)
	// -------------------------------------------------------------------------

	/**
	 * Whether REST API credentials (client ID + secret) are present for the current mode.
	 *
	 * @return bool
	 */
	public function has_rest_credentials() {
		$options = $this->get_paypal_rest_credentials();
		return ! empty( trim( (string) ( $options['client_id'] ?? '' ) ) )
			&& ! empty( trim( (string) ( $options['secret_key'] ?? '' ) ) );
	}

	/**
	 * Map a PayPal subscription status to the local system status string.
	 *
	 * @param string $paypal_status Raw PayPal status (e.g. "ACTIVE").
	 * @return string Local status string, or empty string if unmapped.
	 */
	private function map_paypal_subscription_status( $paypal_status ) {
		$map = array(
			'ACTIVE'           => 'active',
			'SUSPENDED'        => 'canceled',
			'CANCELLED'        => 'canceled',
			'EXPIRED'          => 'expired',
			'APPROVAL_PENDING' => 'pending',
			'APPROVED'         => 'pending',
		);
		$key = strtoupper( (string) $paypal_status );
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Fetch all PayPal webhook events of a given type within a time range.
	 * Follows HATEOAS next links to collect all pages.
	 *
	 * @param string $event_type     PayPal event type (e.g. BILLING.SUBSCRIPTION.ACTIVATED).
	 * @param string $start_time     ISO 8601 UTC start time.
	 * @param string $end_time       ISO 8601 UTC end time.
	 * @param array  $paypal_options REST credentials.
	 * @return array|\WP_Error Flat array of event objects, or WP_Error on failure.
	 */
	private function get_paypal_webhook_events( $event_type, $start_time, $end_time, $paypal_options ) {
		$all_events = array();
		$path       = sprintf(
			'/v1/notifications/webhooks-events?event_type=%s&start_time=%s&end_time=%s&page_size=50',
			rawurlencode( $event_type ),
			rawurlencode( $start_time ),
			rawurlencode( $end_time )
		);

		while ( ! empty( $path ) ) {
			$response = $this->paypal_rest_request( 'GET', $path, null, $paypal_options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$events     = isset( $response['events'] ) ? $response['events'] : array();
			$all_events = array_merge( $all_events, $events );

			// Follow HATEOAS next link for the next page.
			$path = null;
			if ( ! empty( $response['links'] ) ) {
				foreach ( $response['links'] as $link ) {
					if ( 'next' === ( $link['rel'] ?? '' ) && ! empty( $link['href'] ) ) {
						$parsed = parse_url( $link['href'] );
						$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
						if ( ! empty( $parsed['query'] ) ) {
							$path .= '?' . $parsed['query'];
						}
						break;
					}
				}
			}
		}

		return $all_events;
	}

	/**
	 * Fetch details of a PayPal checkout order (used for one-time payments).
	 *
	 * @param string $order_id       PayPal order ID.
	 * @param array  $paypal_options
	 * @return array|WP_Error
	 */
	private function get_paypal_order_details( $order_id, $paypal_options ) {
		return $this->paypal_rest_request(
			'GET',
			'/v2/checkout/orders/' . rawurlencode( $order_id ),
			null,
			$paypal_options
		);
	}

	private function get_paypal_sale_details( $sale_id, $paypal_options ) {
		return $this->paypal_rest_request(
			'GET',
			'/v1/payments/sale/' . rawurlencode( $sale_id ),
			null,
			$paypal_options
		);
	}

	private function map_paypal_sale_state( $state ) {
		$map = array(
			'completed'          => 'completed',
			'partially_refunded' => 'completed',
			'pending'            => 'pending',
			'refunded'           => 'refunded',
			'denied'             => 'failed',
		);
		$key = strtolower( (string) $state );
		return isset( $map[ $key ] ) ? $map[ $key ] : 'completed';
	}

	private function get_paypal_capture_details( $capture_id, $paypal_options ) {
		return $this->paypal_rest_request(
			'GET',
			'/v2/payments/captures/' . rawurlencode( $capture_id ),
			null,
			$paypal_options
		);
	}

	private function map_paypal_capture_status( $status ) {
		$map = array(
			'COMPLETED'          => 'completed',
			'PARTIALLY_REFUNDED' => 'completed',
			'PENDING'            => 'pending',
			'REFUNDED'           => 'refunded',
			'DECLINED'           => 'failed',
			'FAILED'             => 'failed',
		);
		$key = strtoupper( (string) $status );
		return isset( $map[ $key ] ) ? $map[ $key ] : 'completed';
	}

	/**
	 * Backfill subscription status changes missed by webhooks.
	 *
	 * Iterates every local subscription paid via PayPal that has a stored
	 * PayPal subscription ID, fetches the current status from PayPal, and
	 * updates the local record when the statuses differ. Also refreshes
	 * next_billing_date / expiry_date from PayPal's billing_info.
	 *
	 * A 100 ms pause between API calls prevents burst rate-limiting.
	 *
	 * @param int $last_synced Unix timestamp of the previous sync.
	 * @param int $now         Current Unix timestamp.
	 * @return void
	 */
	public function run_missed_subscription_backfill( $last_synced, $now ) {
		$logger = ur_get_logger();

		if ( ! $this->has_rest_credentials() ) {
			$logger->info(
				'[Backfill][Paypal][Subscription] Skipped.' . "\n" . wp_json_encode(
					array(
						'event_type' => 'backfill_skipped',
						'reason'     => 'no_rest_credentials',
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			return;
		}

		$paypal_options  = $this->get_paypal_rest_credentials();
		$effective_start = max( $now - ( 30 * DAY_IN_SECONDS ), ! empty( $last_synced ) ? $last_synced : $now - DAY_IN_SECONDS );
		$start_time      = gmdate( 'Y-m-d\TH:i:s\Z', $effective_start );
		$end_time        = gmdate( 'Y-m-d\TH:i:s\Z', $now );

		$logger->info( '[Backfill][Paypal][Subscription] ======= STARTED =======', array( 'source' => 'urm-missed-payment-backfill' ) );

		// Fetch events for all subscription status event types.
		$event_types  = array(
			'BILLING.SUBSCRIPTION.ACTIVATED',
			'BILLING.SUBSCRIPTION.UPDATED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
			'BILLING.SUBSCRIPTION.CANCELLED',
			'BILLING.SUBSCRIPTION.EXPIRED',
		);
		$all_events   = array();
		$count_errors = 0;

		foreach ( $event_types as $event_type ) {
			$events = $this->get_paypal_webhook_events( $event_type, $start_time, $end_time, $paypal_options );

			if ( is_wp_error( $events ) ) {
				$logger->info(
					'[Backfill][Paypal][Subscription] API error fetching events.' . "\n" . wp_json_encode(
						array(
							'event_type_queried' => $event_type,
							'error'              => $events->get_error_message(),
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_errors;
				continue;
			}

			$all_events = array_merge( $all_events, $events );
		}

		// Sort ascending by create_time so the most recent event for each subscription
		// is processed last and its status wins — avoids an old ACTIVATED event
		// overriding a newer SUSPENDED/CANCELLED event when both fall in the same window.
		usort(
			$all_events,
			function ( $a, $b ) {
				$ta = strtotime( isset( $a['create_time'] ) ? $a['create_time'] : '1970-01-01T00:00:00Z' );
				$tb = strtotime( isset( $b['create_time'] ) ? $b['create_time'] : '1970-01-01T00:00:00Z' );
				return $ta - $tb;
			}
		);

		$total = count( $all_events );

		$logger->info(
			'[Backfill][Paypal][Subscription] Starting subscription backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'event_types'  => $event_types,
					'window_start' => gmdate( 'Y-m-d H:i:s', $effective_start ),
					'window_end'   => gmdate( 'Y-m-d H:i:s', $now ),
					'total_found'  => $total,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$count_updated = 0;
		$count_skipped = 0;

		foreach ( $all_events as $event ) {
			$resource               = isset( $event['resource'] ) ? $event['resource'] : array();
			$paypal_subscription_id = isset( $resource['id'] ) ? $resource['id'] : '';
			$paypal_raw_status      = isset( $resource['status'] ) ? $resource['status'] : '';
			$event_type_name        = isset( $event['event_type'] ) ? $event['event_type'] : '';

			// PayPal's event list endpoint sometimes omits resource.status; derive it from event_type.
			if ( empty( $paypal_raw_status ) ) {
				$status_from_event_type = array(
					'BILLING.SUBSCRIPTION.ACTIVATED' => 'ACTIVE',
					'BILLING.SUBSCRIPTION.SUSPENDED' => 'SUSPENDED',
					'BILLING.SUBSCRIPTION.CANCELLED' => 'CANCELLED',
					'BILLING.SUBSCRIPTION.EXPIRED'   => 'EXPIRED',
				);
				$paypal_raw_status      = isset( $status_from_event_type[ $event_type_name ] ) ? $status_from_event_type[ $event_type_name ] : '';
			}

			if ( empty( $paypal_subscription_id ) ) {
				$logger->info(
					'[Backfill][Paypal][Subscription][Status] Skipped — no subscription ID in event.' . "\n" . wp_json_encode(
						array(
							'event_type' => 'skip',
							'reason'     => 'no_paypal_subscription_id',
							'event_id'   => $event['id'] ?? null,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			$paypal_status = $this->map_paypal_subscription_status( $paypal_raw_status );

			if ( empty( $paypal_status ) ) {
				$logger->info(
					'[Backfill][Paypal][Subscription][Status] Skipped — unrecognised PayPal status.' . "\n" . wp_json_encode(
						array(
							'event_type'             => 'skip',
							'reason'                 => 'unrecognised_paypal_status',
							'paypal_subscription_id' => $paypal_subscription_id,
							'paypal_raw_status'      => $paypal_raw_status,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			$subscription = $this->members_subscription_repository->get_subscription_by_subscription_id_meta( $paypal_subscription_id );

			if ( empty( $subscription ) ) {
				$logger->info(
					'[Backfill][Paypal][Subscription][Status] Skipped — no local subscription found.' . "\n" . wp_json_encode(
						array(
							'event_type'             => 'skip',
							'reason'                 => 'no_local_subscription',
							'paypal_subscription_id' => $paypal_subscription_id,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			$local_sub_id = $subscription['ID'];
			$user_id      = $subscription['user_id'];
			$local_status = $subscription['status'];

			if ( $paypal_status === $local_status ) {
				$logger->info(
					'[Backfill][Paypal][Subscription][Status] Skipped — status unchanged.' . "\n" . wp_json_encode(
						array(
							'event_type'             => 'skip',
							'reason'                 => 'status_unchanged',
							'local_sub_id'           => $local_sub_id,
							'paypal_subscription_id' => $paypal_subscription_id,
							'status'                 => $local_status,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			$update_data       = array( 'status' => $paypal_status );
			$next_billing_time = isset( $resource['billing_info']['next_billing_time'] ) ? $resource['billing_info']['next_billing_time'] : null;

			if ( ! empty( $next_billing_time ) ) {
				try {
					$dt        = new \DateTime( $next_billing_time );
					$formatted = $dt->format( 'Y-m-d H:i:s' );

					$update_data['next_billing_date'] = $formatted;
					$update_data['expiry_date']       = $formatted;
				} catch ( \Exception $e ) {
					$logger->info(
						'[Backfill][Paypal][Subscription][Status] Could not parse next_billing_time — dates not updated.' . "\n" . wp_json_encode(
							array(
								'event_type'        => 'date_parse_error',
								'local_sub_id'      => $local_sub_id,
								'next_billing_time' => $next_billing_time,
								'error'             => $e->getMessage(),
							),
							JSON_PRETTY_PRINT
						),
						array( 'source' => 'urm-missed-payment-backfill' )
					);
				}
			}

			$this->members_subscription_repository->update( $local_sub_id, $update_data );

			PaymentGatewayLogging::log_general(
				'paypal',
				'[Backfill][Paypal][Subscription][Status] Subscription status updated.' . "\n" . wp_json_encode(
					array(
						'event_type'             => 'status_updated',
						'member_id'              => $user_id,
						'subscription_id'        => $local_sub_id,
						'paypal_subscription_id' => $paypal_subscription_id,
						'status_from'            => $local_status,
						'status_to'              => $paypal_status,
						'next_billing_date'      => isset( $update_data['next_billing_date'] ) ? $update_data['next_billing_date'] : null,
					),
					JSON_PRETTY_PRINT
				),
				'success'
			);

			// When a subscription becomes active, also complete its pending order.
			if ( 'active' === $paypal_status && 'active' !== $local_status ) {
				$pending_order = $this->orders_repository->get_order_by_subscription( $local_sub_id );
				if ( ! empty( $pending_order ) && 'pending' === ( $pending_order['status'] ?? '' ) ) {
					$order_prev_status = $pending_order['status'];
					$this->orders_repository->update(
						$pending_order['ID'],
						array( 'status' => 'completed' )
					);
					$logger->info(
						'[Backfill][Paypal][Subscription][Status] Pending order completed alongside subscription activation.' . "\n" . wp_json_encode(
							array(
								'event_type'      => 'order_completed',
								'member_id'       => $user_id,
								'order_id'        => $pending_order['ID'],
								'subscription_id' => $local_sub_id,
								'status_from'     => $order_prev_status,
								'status_to'       => 'completed',
							),
							JSON_PRETTY_PRINT
						),
						array( 'source' => 'urm-missed-payment-backfill' )
					);
				}
			}

			++$count_updated;
		}

		$logger->info(
			'[Backfill][Paypal][Subscription] Done.' . "\n" . wp_json_encode(
				array(
					'event_type' => 'backfill_done',
					'total'      => $total,
					'updated'    => $count_updated,
					'skipped'    => $count_skipped,
					'errors'     => $count_errors,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$logger->info( '[Backfill][Paypal][Subscription] ======= ENDED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
	}


	/**
	 * Backfill missing subscription renewal payment records from PayPal.
	 *
	 * For each local PayPal subscription, fetches transactions from PayPal
	 * within the sync window. Any COMPLETED transaction without a matching
	 * local order is created. When the only existing order uses the PayPal
	 * subscription ID as a placeholder transaction_id (set on initial sign-up),
	 * that placeholder is deleted and replaced with the real transaction ID —
	 * mirroring the Stripe backfill behaviour.
	 *
	 * @param int $last_synced Unix timestamp of the previous sync.
	 * @param int $now         Current Unix timestamp.
	 * @return void
	 */
	public function run_missed_payment_backfill( $last_synced, $now ) {
		$logger = ur_get_logger();

		if ( ! $this->has_rest_credentials() ) {
			$logger->info(
				'[Backfill][PayPal][Subscription][Payments] Skipped.' . "\n" . wp_json_encode(
					array(
						'event_type' => 'backfill_skipped',
						'reason'     => 'no_rest_credentials',
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			return;
		}

		$paypal_options  = $this->get_paypal_rest_credentials();
		$effective_start = max( $now - ( 30 * DAY_IN_SECONDS ), ! empty( $last_synced ) ? $last_synced : $now - DAY_IN_SECONDS );
		$start_time      = gmdate( 'Y-m-d\TH:i:s\Z', $effective_start );
		$end_time        = gmdate( 'Y-m-d\TH:i:s\Z', $now );

		$logger->info( '[Backfill][PayPal][Subscription][Payments] ======= STARTED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][PayPal][Subscription][Payments] Starting subscription payment backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => $start_time,
					'window_end'   => $end_time,
					'event_types'  => array( 'PAYMENT.SALE.COMPLETED' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$events = $this->get_paypal_webhook_events( 'PAYMENT.SALE.COMPLETED', $start_time, $end_time, $paypal_options );

		if ( is_wp_error( $events ) ) {
			$logger->info(
				'[Backfill][PayPal][Subscription][Payments] API error fetching PAYMENT.SALE.COMPLETED events.' . "\n" . wp_json_encode(
					array(
						'event_type' => 'api_error',
						'error'      => $events->get_error_message(),
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			$logger->info( '[Backfill][PayPal][Subscription][Payments] ======= ENDED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
			return;
		}

		$total = count( $events );

		$count_created = 0;
		$count_updated = 0;
		$count_skipped = 0;
		$count_errors  = 0;

		foreach ( $events as $event ) {
			$resource               = isset( $event['resource'] ) ? $event['resource'] : array();
			$transaction_id         = isset( $resource['id'] ) ? $resource['id'] : '';
			$paypal_subscription_id = isset( $resource['billing_agreement_id'] ) ? $resource['billing_agreement_id'] : '';
			$gross_amount           = (float) ( $resource['amount']['total'] ?? 0 );
			$time_string            = isset( $resource['create_time'] ) ? $resource['create_time'] : '';
			$created_at             = ! empty( $time_string ) ? gmdate( 'Y-m-d H:i:s', strtotime( $time_string ) ) : gmdate( 'Y-m-d H:i:s' );

			if ( empty( $transaction_id ) || empty( $paypal_subscription_id ) ) {
				$logger->info(
					'[Backfill][PayPal][Subscription][Payments] Skipped — missing transaction or subscription ID in event.' . "\n" . wp_json_encode(
						array(
							'event_type'     => 'skip',
							'reason'         => 'missing_ids',
							'event_id'       => $event['id'] ?? null,
							'transaction_id' => $transaction_id ?: null,
							'paypal_sub_id'  => $paypal_subscription_id ?: null,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			// If an order for this transaction exists, sync its status with PayPal live.
			$existing_payment = $this->orders_repository->get_order_by_transaction_id( $transaction_id );
			if ( ! empty( $existing_payment ) ) {
				$sale_details = $this->get_paypal_sale_details( $transaction_id, $paypal_options );
				$live_state   = ! is_wp_error( $sale_details ) && isset( $sale_details['state'] ) ? $sale_details['state'] : 'completed';
				$live_status  = $this->map_paypal_sale_state( $live_state );
				$prev_status  = $existing_payment['status'] ?? '';

				$this->orders_repository->update(
					$existing_payment['ID'],
					array( 'status' => $live_status )
				);
				PaymentGatewayLogging::log_general(
					'paypal',
					'[Backfill][PayPal][Subscription][Payments] Existing order status synced from PayPal live.' . "\n" . wp_json_encode(
						array(
							'event_type'        => 'order_status_synced',
							'order_id'          => $existing_payment['ID'],
							'transaction_id'    => $transaction_id,
							'paypal_live_state' => $live_state,
							'status_from'       => $prev_status,
							'status_to'         => $live_status,
						),
						JSON_PRETTY_PRINT
					),
					'success'
				);
				++$count_updated;
				continue;
			}

			// Find local subscription by PayPal subscription ID.
			$membership_subscription = $this->members_subscription_repository->get_subscription_by_subscription_id_meta( $paypal_subscription_id );

			if ( empty( $membership_subscription ) ) {
				$logger->info(
					'[Backfill][PayPal][Subscription][Payments] Skipped — no local subscription found.' . "\n" . wp_json_encode(
						array(
							'event_type'             => 'skip',
							'reason'                 => 'no_local_subscription',
							'paypal_subscription_id' => $paypal_subscription_id,
							'transaction_id'         => $transaction_id,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			$local_sub_id = $membership_subscription['ID'];
			$user_id      = $membership_subscription['user_id'];

			// Replace placeholder order (transaction_id = PayPal subscription ID) if one exists.
			$placeholder = $this->orders_repository->get_order_by_transaction_id( $paypal_subscription_id );
			if ( ! empty( $placeholder ) && ! empty( $placeholder['ID'] ) ) {
				$logger->info(
					'[Backfill][PayPal][Subscription][Payments] Deleting placeholder order.' . "\n" . wp_json_encode(
						array(
							'event_type'             => 'placeholder_deleted',
							'local_sub_id'           => $local_sub_id,
							'placeholder_order_id'   => $placeholder['ID'],
							'paypal_subscription_id' => $paypal_subscription_id,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				$this->orders_repository->delete( $placeholder['ID'] );
			}

			// Update a pending order in place rather than creating a duplicate.
			$existing_pending = $this->orders_repository->get_order_by_subscription( $local_sub_id );
			if (
				! empty( $existing_pending['ID'] ) &&
				'pending' === ( $existing_pending['status'] ?? '' ) &&
				'' === (string) ( $existing_pending['transaction_id'] ?? '' )
			) {
				$this->orders_repository->update(
					$existing_pending['ID'],
					array(
						'status'         => 'completed',
						'transaction_id' => $transaction_id,
						'total_amount'   => $gross_amount,
					)
				);
				PaymentGatewayLogging::log_general(
					'paypal',
					'[Backfill][PayPal][Subscription][Payments] Pending order updated with real transaction.' . "\n" . wp_json_encode(
						array(
							'event_type'      => 'order_updated',
							'member_id'       => $user_id,
							'subscription_id' => $local_sub_id,
							'order_id'        => $existing_pending['ID'],
							'status_from'     => 'pending',
							'status_to'       => 'completed',
							'transaction_id'  => $transaction_id,
							'amount'          => $gross_amount,
						),
						JSON_PRETTY_PRINT
					),
					'success'
				);
				++$count_updated;
				continue;
			}

			// Create a new completed order for this payment event.
			$order_data = array(
				'orders_data'      => array(
					'user_id'         => absint( $membership_subscription['user_id'] ),
					'item_id'         => $membership_subscription['item_id'],
					'subscription_id' => $membership_subscription['ID'],
					'created_by'      => $membership_subscription['user_id'],
					'transaction_id'  => $transaction_id,
					'payment_method'  => 'paypal',
					'total_amount'    => $gross_amount,
					'status'          => 'completed',
					'order_type'      => 'subscription',
					'trial_status'    => 'off',
					'notes'           => 'Backfilled order for missed PayPal payment event',
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
			PaymentGatewayLogging::log_general(
				'paypal',
				'[Backfill][PayPal][Subscription][Payments] New order created for missed payment.' . "\n" . wp_json_encode(
					array(
						'event_type'       => 'order_created',
						'member_id'        => $user_id,
						'subscription_id'  => $local_sub_id,
						'status_from'      => null,
						'status_to'        => 'completed',
						'transaction_id'   => $transaction_id,
						'amount'           => $gross_amount,
						'transaction_time' => $created_at,
					),
					JSON_PRETTY_PRINT
				),
				'success'
			);
			++$count_created;
		}

		$logger->info(
			'[Backfill][PayPal][Subscription][Payments] Done.' . "\n" . wp_json_encode(
				array(
					'event_type'     => 'backfill_done',
					'total'          => $total,
					'orders_created' => $count_created,
					'orders_updated' => $count_updated,
					'skipped'        => $count_skipped,
					'errors'         => $count_errors,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$logger->info( '[Backfill][PayPal][Subscription][Payments] ======= ENDED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	/**
	 * Backfill pending one-time PayPal payment orders.
	 *
	 * For each local pending one-time order within the sync window, checks the
	 * PayPal order status using the order ID stored in user meta. If PayPal
	 * reports the order as COMPLETED, the local record is updated with the
	 * capture ID and marked as completed, and the linked subscription is
	 * activated.
	 *
	 * @param int $last_synced Unix timestamp of the previous sync.
	 * @param int $now         Current Unix timestamp.
	 * @return void
	 */
	public function run_missed_onetime_payment_backfill( $last_synced, $now ) {
		$logger = ur_get_logger();

		if ( ! $this->has_rest_credentials() ) {
			$logger->info(
				'[Backfill][PayPal][OneTime] Skipped.' . "\n" . wp_json_encode(
					array(
						'event_type' => 'backfill_skipped',
						'reason'     => 'no_rest_credentials',
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			return;
		}

		$paypal_options  = $this->get_paypal_rest_credentials();
		$effective_start = max( $last_synced, $now - ( 30 * DAY_IN_SECONDS ) );
		$start_time      = gmdate( 'Y-m-d\TH:i:s\Z', $effective_start );
		$end_time        = gmdate( 'Y-m-d\TH:i:s\Z', $now );

		$logger->info( '[Backfill][PayPal][OneTime] ======= STARTED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][PayPal][OneTime] Starting one-time payment backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => $start_time,
					'window_end'   => $end_time,
					'event_types'  => array( 'PAYMENT.CAPTURE.COMPLETED' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$events = $this->get_paypal_webhook_events( 'PAYMENT.CAPTURE.COMPLETED', $start_time, $end_time, $paypal_options );

		$count_completed = 0;
		$count_skipped   = 0;

		if ( is_wp_error( $events ) ) {
			$logger->info(
				'[Backfill][PayPal][OneTime] API error fetching PAYMENT.CAPTURE.COMPLETED events.' . "\n" . wp_json_encode(
					array( 'error' => $events->get_error_message() ),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			$events = array();
		}

		foreach ( $events as $event ) {
			$resource   = isset( $event['resource'] ) ? $event['resource'] : array();
			$capture_id = isset( $resource['id'] ) ? $resource['id'] : '';
			$custom_id  = isset( $resource['custom_id'] ) ? $resource['custom_id'] : '';

			if ( empty( $capture_id ) || empty( $custom_id ) ) {
				++$count_skipped;
				continue;
			}

			// If an order for this capture exists, sync its status with PayPal live.
			$existing_order = $this->orders_repository->get_order_by_transaction_id( $capture_id );
			if ( ! empty( $existing_order ) ) {
				$capture_details = $this->get_paypal_capture_details( $capture_id, $paypal_options );
				$live_state      = ! is_wp_error( $capture_details ) && isset( $capture_details['status'] ) ? $capture_details['status'] : 'COMPLETED';
				$live_status     = $this->map_paypal_capture_status( $live_state );
				$prev_status     = $existing_order['status'] ?? '';

				$this->orders_repository->update(
					$existing_order['ID'],
					array( 'status' => $live_status )
				);
				PaymentGatewayLogging::log_general(
					'paypal',
					'[Backfill][PayPal][OneTime] Existing order status synced from PayPal live.' . "\n" . wp_json_encode(
						array(
							'event_type'        => 'order_status_synced',
							'order_id'          => $existing_order['ID'],
							'capture_id'        => $capture_id,
							'paypal_live_state' => $live_state,
							'status_from'       => $prev_status,
							'status_to'         => $live_status,
						),
						JSON_PRETTY_PRINT
					),
					'success'
				);
				++$count_completed;
				continue;
			}

			$parsed          = $this->parse_custom_id( $custom_id );
			$subscription_id = absint( isset( $parsed['subscription_id'] ) ? $parsed['subscription_id'] : 0 );

			if ( empty( $subscription_id ) ) {
				$logger->info(
					'[Backfill][PayPal][OneTime] Skipped — no subscription_id in custom_id.' . "\n" . wp_json_encode(
						array(
							'event_type' => 'skip',
							'reason'     => 'no_subscription_id',
							'custom_id'  => $custom_id,
							'capture_id' => $capture_id,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$count_skipped;
				continue;
			}

			$order = $this->orders_repository->get_order_by_subscription( $subscription_id );

			if ( empty( $order ) || 'pending' !== ( isset( $order['status'] ) ? $order['status'] : '' ) ) {
				++$count_skipped;
				continue;
			}

			$this->orders_repository->update(
				$order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $capture_id,
				)
			);

			$subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $subscription_id );
			if ( ! empty( $subscription['ID'] ) && 'pending' === ( isset( $subscription['status'] ) ? $subscription['status'] : '' ) ) {
				$this->members_subscription_repository->update(
					$subscription['ID'],
					array(
						'status'     => 'active',
						'start_date' => gmdate( 'Y-m-d 00:00:00' ),
					)
				);
				$logger->info(
					'[Backfill][PayPal][OneTime] Subscription activated.' . "\n" . wp_json_encode(
						array(
							'event_type'      => 'subscription_activated',
							'subscription_id' => $subscription['ID'],
							'order_id'        => $order['ID'],
							'capture_id'      => $capture_id,
						),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
			}

			++$count_completed;
			$logger->info(
				'[Backfill][PayPal][OneTime] Order completed.' . "\n" . wp_json_encode(
					array(
						'event_type'      => 'order_completed',
						'order_id'        => $order['ID'],
						'subscription_id' => $subscription_id,
						'capture_id'      => $capture_id,
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
		}

		// Second pass: activate subscriptions for orders already completed locally
		// but whose subscription status was never flipped to active.
		$orphaned        = $this->orders_repository->get_completed_paypal_onetime_with_pending_subscription();
		$count_activated = 0;

		$logger->info(
			'[Backfill][PayPal][OneTime] Checking completed orders with pending subscriptions.' . "\n" . wp_json_encode(
				array(
					'event_type'  => 'orphaned_check',
					'total_found' => count( $orphaned ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		foreach ( $orphaned as $order ) {
			$order_id            = isset( $order['ID'] ) ? $order['ID'] : '?';
			$subscription_row_id = isset( $order['subscription_id'] ) ? $order['subscription_id'] : '';

			if ( empty( $subscription_row_id ) ) {
				continue;
			}

			$subscription = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $subscription_row_id );
			if ( empty( $subscription['ID'] ) ) {
				continue;
			}

			$this->members_subscription_repository->update(
				$subscription['ID'],
				array(
					'status'     => 'active',
					'start_date' => gmdate( 'Y-m-d 00:00:00' ),
				)
			);
			++$count_activated;
			$logger->info(
				'[Backfill][PayPal][OneTime] Orphaned subscription activated.' . "\n" . wp_json_encode(
					array(
						'event_type'      => 'orphaned_activated',
						'order_id'        => $order_id,
						'subscription_id' => $subscription['ID'],
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
		}

		$logger->info(
			'[Backfill][PayPal][OneTime] Done.' . "\n" . wp_json_encode(
				array(
					'event_type'              => 'backfill_done',
					'events_found'            => count( $events ),
					'completed'               => $count_completed,
					'subscriptions_activated' => $count_activated,
					'skipped'                 => $count_skipped,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);
		$logger->info( '[Backfill][PayPal][OneTime] ======= ENDED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	/**
	 * Backfill refunded orders missed by webhooks.
	 *
	 * Queries the PayPal webhook events log for PAYMENT.CAPTURE.REFUNDED and
	 * PAYMENT.SALE.REFUNDED events within the backfill window and marks matching
	 * local orders as refunded. Only events recorded after webhook registration
	 * will appear; this is best-effort for older refunds.
	 *
	 * @param int $last_synced Unix timestamp of the previous sync.
	 * @param int $now         Current Unix timestamp.
	 * @return void
	 */
	public function run_missed_refund_backfill( $last_synced, $now ) {
		$logger = ur_get_logger();

		if ( ! $this->has_rest_credentials() ) {
			$logger->info(
				'[Backfill][PayPal][Refunds] Skipped.' . "\n" . wp_json_encode(
					array(
						'event_type' => 'backfill_skipped',
						'reason'     => 'no_rest_credentials',
					),
					JSON_PRETTY_PRINT
				),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			return;
		}

		$paypal_options  = $this->get_paypal_rest_credentials();
		$effective_start = max( $last_synced, $now - ( 30 * DAY_IN_SECONDS ) );
		$start_time      = gmdate( 'Y-m-d\TH:i:s\Z', $effective_start );
		$end_time        = gmdate( 'Y-m-d\TH:i:s\Z', $now );

		$logger->info( '[Backfill][PayPal][Refunds] ======= STARTED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
		$logger->info(
			'[Backfill][PayPal][Refunds] Starting refund backfill.' . "\n" . wp_json_encode(
				array(
					'event_type'   => 'backfill_start',
					'window_start' => $start_time,
					'window_end'   => $end_time,
					'event_types'  => array( 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.SALE.REFUNDED' ),
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		$all_events = array();
		foreach ( array( 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.SALE.REFUNDED' ) as $event_type ) {
			$events = $this->get_paypal_webhook_events( $event_type, $start_time, $end_time, $paypal_options );
			if ( is_wp_error( $events ) ) {
				$logger->info(
					'[Backfill][PayPal][Refunds] API error fetching ' . $event_type . ' events.' . "\n" . wp_json_encode(
						array( 'error' => $events->get_error_message() ),
						JSON_PRETTY_PRINT
					),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
			} else {
				$all_events = array_merge( $all_events, $events );
			}
		}

		$total_found   = count( $all_events );
		$total_updated = 0;
		$total_skipped = 0;

		$logger->info(
			sprintf( '[Backfill][PayPal][Refunds] Total refund events found: %d', $total_found ),
			array( 'source' => 'urm-missed-payment-backfill' )
		);

		foreach ( $all_events as $event ) {
			$event_type = isset( $event['event_type'] ) ? $event['event_type'] : '';
			$resource   = isset( $event['resource'] ) ? $event['resource'] : array();

			if ( 'PAYMENT.CAPTURE.REFUNDED' === $event_type ) {
				$transaction_id = null;
				$links          = isset( $resource['links'] ) ? $resource['links'] : array();
				foreach ( $links as $link ) {
					if ( 'up' === ( isset( $link['rel'] ) ? $link['rel'] : '' ) && ! empty( $link['href'] ) ) {
						$transaction_id = basename( rtrim( $link['href'], '/' ) );
						break;
					}
				}
			} else {
				$transaction_id = isset( $resource['sale_id'] ) ? $resource['sale_id'] : null;
			}

			if ( empty( $transaction_id ) ) {
				++$total_skipped;
				continue;
			}

			$order = $this->orders_repository->get_order_by_transaction_id( $transaction_id );
			if ( empty( $order ) ) {
				$logger->info(
					sprintf( '[Backfill][PayPal][Refunds] No local order for transaction %s — skipping.', $transaction_id ),
					array( 'source' => 'urm-missed-payment-backfill' )
				);
				++$total_skipped;
				continue;
			}

			if ( 'refunded' === $order['status'] ) {
				++$total_skipped;
				continue;
			}

			$this->orders_repository->update( $order['ID'], array( 'status' => 'refunded' ) );
			++$total_updated;
			$logger->info(
				sprintf( '[Backfill][PayPal][Refunds] Marked order %d as refunded (transaction %s)', $order['ID'], $transaction_id ),
				array( 'source' => 'urm-missed-payment-backfill' )
			);
		}

		$logger->info(
			'[Backfill][PayPal][Refunds] Done.' . "\n" . wp_json_encode(
				array(
					'event_type'    => 'backfill_done',
					'total_found'   => $total_found,
					'total_updated' => $total_updated,
					'total_skipped' => $total_skipped,
				),
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-missed-payment-backfill' )
		);
		$logger->info( '[Backfill][PayPal][Refunds] ======= ENDED =======', array( 'source' => 'urm-missed-payment-backfill' ) );
	}

	// -------------------------------------------------------------------------

	public function get_webhook_url() {
		return rest_url( 'user-registration/paypal-webhook' );
	}

	/**
	 * Register or update the PayPal webhook for this site.
	 *
	 * Lists existing webhooks on the PayPal account, patches event types if our URL
	 * is already registered, or creates a new webhook. Returns the webhook ID on success.
	 * The caller is responsible for persisting the returned ID.
	 *
	 * @param array $paypal_options Credentials array (mode, client_id, secret_key).
	 * @return string|WP_Error Webhook ID on success, WP_Error on failure.
	 */
	public function register_or_update_webhook( $paypal_options ) {
		$webhook_url = $this->get_webhook_url();
		$event_types = array(
			array( 'name' => 'CHECKOUT.ORDER.APPROVED' ),
			array( 'name' => 'PAYMENT.CAPTURE.COMPLETED' ),
			array( 'name' => 'PAYMENT.CAPTURE.REFUNDED' ),
			array( 'name' => 'PAYMENT.SALE.COMPLETED' ),
			array( 'name' => 'PAYMENT.SALE.REFUNDED' ),
			array( 'name' => 'BILLING.SUBSCRIPTION.CREATED' ),
			array( 'name' => 'BILLING.SUBSCRIPTION.ACTIVATED' ),
			array( 'name' => 'BILLING.SUBSCRIPTION.UPDATED' ),
			array( 'name' => 'BILLING.SUBSCRIPTION.SUSPENDED' ),
			array( 'name' => 'BILLING.SUBSCRIPTION.CANCELLED' ),
			array( 'name' => 'BILLING.SUBSCRIPTION.EXPIRED' ),
		);

		PaymentGatewayLogging::log_general(
			'paypal',
			'Starting PayPal webhook auto-registration.' . "\n" . wp_json_encode(
				array(
					'webhook_url' => $webhook_url,
					'mode'        => isset( $paypal_options['mode'] ) ? $paypal_options['mode'] : '',
				),
				JSON_PRETTY_PRINT
			),
			'info'
		);

		// PayPal requires HTTPS for webhook URLs. Skip registration on non-HTTPS
		// sites (e.g. local dev) and log a notice so the user knows why.
		if ( 0 !== strpos( $webhook_url, 'https://' ) ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				'PayPal webhook registration skipped: webhook URL must use HTTPS.' . "\n" . wp_json_encode(
					array( 'webhook_url' => $webhook_url ),
					JSON_PRETTY_PRINT
				),
				'notice'
			);
			return new WP_Error(
				'paypal_webhook_https_required',
				__( 'PayPal webhook registration requires an HTTPS URL. Registration skipped for non-HTTPS sites.', 'user-registration' )
			);
		}

		$existing = $this->paypal_rest_request( 'GET', '/v1/notifications/webhooks', null, $paypal_options );

		if ( is_wp_error( $existing ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Failed to list existing PayPal webhooks during registration.' . "\n" . wp_json_encode(
					array( 'error' => $existing->get_error_message() ),
					JSON_PRETTY_PRINT
				)
			);
			return $existing;
		}

		$existing_id = null;
		$webhooks    = isset( $existing['webhooks'] ) ? $existing['webhooks'] : array();

		PaymentGatewayLogging::log_general(
			'paypal',
			'PayPal webhooks listed, scanning for existing registration.' . "\n" . wp_json_encode(
				array(
					'total_webhooks' => count( $webhooks ),
					'looking_for'    => $webhook_url,
				),
				JSON_PRETTY_PRINT
			),
			'info'
		);

		$existing_event_types = array();

		foreach ( $webhooks as $webhook ) {
			if ( isset( $webhook['url'] ) && $webhook['url'] === $webhook_url ) {
				$existing_id          = $webhook['id'];
				$existing_event_types = isset( $webhook['event_types'] ) ? $webhook['event_types'] : array();
				break;
			}
		}

		if ( $existing_id ) {
			$desired_names  = array_column( $event_types, 'name' );
			$existing_names = array_column( $existing_event_types, 'name' );
			sort( $desired_names );
			sort( $existing_names );

			if ( $desired_names === $existing_names ) {
				PaymentGatewayLogging::log_general(
					'paypal',
					'Existing PayPal webhook already has all required event types — no update needed.' . "\n" . wp_json_encode(
						array( 'webhook_id' => $existing_id ),
						JSON_PRETTY_PRINT
					),
					'info'
				);
				return $existing_id;
			}

			PaymentGatewayLogging::log_general(
				'paypal',
				'Existing PayPal webhook found, patching event types.' . "\n" . wp_json_encode(
					array( 'webhook_id' => $existing_id ),
					JSON_PRETTY_PRINT
				),
				'info'
			);

			$patch_payload = array(
				array(
					'op'    => 'replace',
					'path'  => '/event_types',
					'value' => $event_types,
				),
			);

			$update = $this->paypal_rest_request(
				'PATCH',
				'/v1/notifications/webhooks/' . rawurlencode( $existing_id ),
				$patch_payload,
				$paypal_options
			);

			if ( is_wp_error( $update ) ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					'Failed to patch PayPal webhook event types.' . "\n" . wp_json_encode(
						array(
							'webhook_id' => $existing_id,
							'error'      => $update->get_error_message(),
						),
						JSON_PRETTY_PRINT
					)
				);
				return $update;
			}

			PaymentGatewayLogging::log_general(
				'paypal',
				'PayPal webhook event types patched successfully.' . "\n" . wp_json_encode(
					array( 'webhook_id' => $existing_id ),
					JSON_PRETTY_PRINT
				),
				'success'
			);

			return $existing_id;
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			'No existing PayPal webhook found, creating new registration.' . "\n" . wp_json_encode(
				array( 'webhook_url' => $webhook_url ),
				JSON_PRETTY_PRINT
			),
			'info'
		);

		$create_result = $this->paypal_rest_request(
			'POST',
			'/v1/notifications/webhooks',
			array(
				'url'         => $webhook_url,
				'event_types' => $event_types,
			),
			$paypal_options
		);

		if ( is_wp_error( $create_result ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Failed to create PayPal webhook.' . "\n" . wp_json_encode(
					array(
						'webhook_url' => $webhook_url,
						'error'       => $create_result->get_error_message(),
					),
					JSON_PRETTY_PRINT
				)
			);
			return $create_result;
		}

		$webhook_id = isset( $create_result['id'] ) ? $create_result['id'] : '';

		if ( empty( $webhook_id ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'PayPal webhook registration response missing ID.' . "\n" . wp_json_encode(
					array( 'response' => $create_result ),
					JSON_PRETTY_PRINT
				)
			);
			return new WP_Error(
				'paypal_webhook_no_id',
				__( 'PayPal webhook created but no ID returned.', 'user-registration' )
			);
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			'PayPal webhook registered successfully.' . "\n" . wp_json_encode(
				array(
					'webhook_id'  => $webhook_id,
					'webhook_url' => $webhook_url,
				),
				JSON_PRETTY_PRINT
			),
			'success'
		);

		return $webhook_id;
	}

	/**
	 * Verify a PayPal webhook signature via the PayPal REST verification API.
	 *
	 * @param array  $headers        Associative array of PayPal webhook headers (transmission_id, transmission_time, cert_url, auth_algo, transmission_sig).
	 * @param string $body           Raw webhook request body.
	 * @param string $webhook_id     Stored PayPal webhook ID for this site.
	 * @param array  $paypal_options Credentials (mode, client_id, secret_key).
	 * @return bool True if PayPal confirms the signature is valid.
	 */
	public function verify_webhook_signature( $headers, $body, $webhook_id, $paypal_options ) {
		$event = json_decode( $body, true );

		if ( empty( $event ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Webhook signature verification aborted: empty or invalid JSON body.'
			);
			return false;
		}

		$payload = array(
			'transmission_id'   => isset( $headers['transmission_id'] ) ? $headers['transmission_id'] : '',
			'transmission_time' => isset( $headers['transmission_time'] ) ? $headers['transmission_time'] : '',
			'cert_url'          => isset( $headers['cert_url'] ) ? $headers['cert_url'] : '',
			'auth_algo'         => isset( $headers['auth_algo'] ) ? $headers['auth_algo'] : '',
			'transmission_sig'  => isset( $headers['transmission_sig'] ) ? $headers['transmission_sig'] : '',
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $event,
		);

		PaymentGatewayLogging::log_general(
			'paypal',
			'Calling PayPal webhook signature verification API.' . "\n" . wp_json_encode(
				array(
					'transmission_id' => $payload['transmission_id'],
					'auth_algo'       => $payload['auth_algo'],
					'webhook_id'      => $webhook_id,
				),
				JSON_PRETTY_PRINT
			),
			'info'
		);

		$result = $this->paypal_rest_request(
			'POST',
			'/v1/notifications/verify-webhook-signature',
			$payload,
			$paypal_options
		);

		if ( is_wp_error( $result ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'PayPal verification API request failed.' . "\n" . wp_json_encode(
					array( 'error' => $result->get_error_message() ),
					JSON_PRETTY_PRINT
				)
			);
			return false;
		}

		$status = isset( $result['verification_status'] ) ? strtoupper( (string) $result['verification_status'] ) : '';

		PaymentGatewayLogging::log_general(
			'paypal',
			'PayPal webhook signature verification API response received.' . "\n" . wp_json_encode(
				array( 'verification_status' => $status ),
				JSON_PRETTY_PRINT
			),
			'SUCCESS' === $status ? 'success' : 'warning'
		);

		return 'SUCCESS' === $status;
	}
}
