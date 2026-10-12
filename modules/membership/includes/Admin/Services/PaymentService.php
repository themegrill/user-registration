<?php

namespace WPEverest\URMembership\Admin\Services;

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Services\Paypal\PaypalService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;
use WPEverest\URM\Mollie\Services\PaymentService as MollieService;
use WPEverest\URMembership\Admin\Services\Paypal\NewPaypalService;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

class PaymentService {
	/**
	 * @var
	 */
	protected $payment_method, $membership, $member_email;

	/**
	 * @param $payment_method
	 * @param $membership
	 * @param $member_email
	 */
	public function __construct( $payment_method, $membership, $member_email ) {
		$this->payment_method = $payment_method;
		$this->membership     = $membership;
		$this->member_email   = $member_email;
	}

	/**
	 * Get Payment Data
	 *
	 * @param $data
	 *
	 * @return mixed
	 */
	public function get_payment_data( $data ) {

		$member_repo = new MembershipRepository();
		$membership  = $member_repo->get_single_membership_by_ID( $data['membership'] );

		$membership_meta = json_decode( wp_unslash( $membership['meta_value'] ), true );
		if ( isset( $data['coupon'] ) && ! empty( $data['coupon'] ) ) {
			$membership_meta['coupon'] = $data['coupon'];
		}
		if ( isset( $data['upgrade'] ) && $data['upgrade'] && empty( $data['team_id'] ) ) {
			$membership_meta['amount']                       = $data['chargeable_amount'];
			$membership_meta['upgrade']                      = true;
			$membership_meta['remaining_subscription_value'] = $data['remaining_subscription_value'];
			$membership_meta['trial_status']                 = ( isset( $data['trial_status'] ) && 'on' == ( $data['trial_status'] ) ) ? $data['trial_status'] : ( isset( $membership_meta['trial_status'] ) ? $membership_meta['trial_status'] : '' );
		}
		if ( isset( $data['team_id'] ) && ! empty( $data['team_id'] ) ) {
			$team_id    = $data['team_id'];
			$team_data  = get_post_meta( $team_id, 'urm_team_data', true );
			$team_seats = get_post_meta( $team_id, 'urm_team_seats', true );
			if ( $team_data ) {
				$membership_meta['team_data'] = $team_data;
				if ( $team_seats ) {
					$membership_meta['team_data']['team_seats'] = $team_seats;
				}
				if ( isset( $team_data['pricing_model'] ) && 'tier' === $team_data['pricing_model'] ) {
					$tier_info = get_post_meta( $team_id, 'urm_tier_info', true );
					if ( $tier_info ) {
						$membership_meta['team_tier_info'] = $tier_info;
					}
				}
			}
			$membership_meta['team_id'] = $data['team_id'];
		}
		return $membership_meta;
	}

	/**
	 * Build return Response
	 *
	 * @param $response_data
	 *
	 * @return array|void
	 */
	public function build_response( $response_data ) {
		$payment_data = $this->get_payment_data( $response_data );

		if ( isset( $payment_data['upgrade'] ) && $payment_data['upgrade'] ) {
			$subscription_service            = new SubscriptionService();
			$subscription_repository         = new SubscriptionRepository();
			$previous_subscription_data      = $response_data['subscription_data'];
			$response_data['payment_method'] = $this->payment_method;
			update_user_meta( $response_data['member_id'], 'urm_previous_subscription_data', json_encode( $previous_subscription_data ) );
			$subscription_data = $subscription_service->prepare_upgrade_subscription_data( $response_data['membership'], $response_data['member_id'], $response_data );

			if ( 'bank' === $this->payment_method || ! empty( $response_data['delayed_until'] ) ) {
				update_user_meta( $response_data['member_id'], 'urm_next_subscription_data', json_encode( $response_data ) );
			} elseif ( 'paypal' === $this->payment_method || 'mollie' === $this->payment_method ) {
				update_user_meta( $response_data['member_id'], 'urm_next_subscription_data', json_encode( $response_data ) );
			} else {
				$subscription_repository->update( $response_data['subscription_id'], $subscription_data );
			}
			unset( $response_data['subscription_data'] );
		}

		$payment_data['item_id']                = isset( $response_data['membership'] ) ? $response_data['membership'] : 0;
		$payment_data['selected_membership_id'] = isset( $response_data['selected_membership_id'] ) ? $response_data['selected_membership_id'] : 0;
		$payment_data['current_membership_id']  = isset( $response_data['current_membership_id'] ) ? $response_data['current_membership_id'] : 0;
		switch ( $this->payment_method ) {
			case 'stripe':
				return $this->build_stripe_response( $payment_data, $response_data );
				break;
			case 'paypal':
				return $this->build_paypal_response( $payment_data, $response_data['subscription_id'], $response_data['member_id'], $response_data );
			case 'authorize':
				return $this->build_authorize_response( $payment_data, $response_data );
			case 'mollie':
				return $this->build_mollie_response( $payment_data, $response_data['subscription_id'], $response_data['member_id'], $response_data );
			case 'bank':
				return $this->build_direct_bank_response( $payment_data, $response_data['subscription_id'], $response_data['member_id'] );
			default:
				return $this->build_free_upgrade_response( $response_data );
		}
	}

	/**
	 * Build Free upgrade Response
	 *
	 * @param array $response_data
	 *
	 * @return array
	 */
	public function build_free_upgrade_response( $response_data = array() ) {
		$is_plan_change = ! empty( $response_data['upgrade'] ) && ! empty( $response_data['current_membership_id'] )
			&& absint( $response_data['current_membership_id'] ) !== absint( $response_data['membership'] ?? 0 )
			&& empty( $response_data['delayed_until'] )
			&& $this->is_paid_to_free_change( $response_data['current_membership_id'], $response_data['membership'] ?? 0 );

		if ( $is_plan_change ) {
			$email_service = new EmailService();
			$email_service->send_email( $response_data, 'membership_downgraded_free_user' );
			$email_service->send_email( $response_data, 'membership_downgraded_free_admin' );
		}

		return array(
			'thank_you_page_url' => urm_get_thank_you_page(),
		);
	}

	/**
	 * Checks whether a membership change is genuinely from a paid plan to a free one.
	 *
	 * @param int $current_membership_id
	 * @param int $new_membership_id
	 *
	 * @return bool
	 */
	public function is_paid_to_free_change( $current_membership_id, $new_membership_id ) {
		$membership_repository = new MembershipRepository();

		$current_membership = $membership_repository->get_single_membership_by_ID( $current_membership_id );
		$new_membership      = $membership_repository->get_single_membership_by_ID( $new_membership_id );

		if ( empty( $current_membership['meta_value'] ) || empty( $new_membership['meta_value'] ) ) {
			return false;
		}

		$current_type = json_decode( wp_unslash( $current_membership['meta_value'] ), true )['type'] ?? '';
		$new_type     = json_decode( wp_unslash( $new_membership['meta_value'] ), true )['type'] ?? '';

		return 'free' !== $current_type && 'free' === $new_type;
	}

	/**
	 * Build PayPal Response
	 *
	 * @param $data
	 * @param $subscription_id
	 * @param $member_id
	 *
	 * @return array
	 */
	public function build_paypal_response( $data, $subscription_id, $member_id, $response_data = array() ) {
		$paypal_service = new NewPaypalService();
		$url            = $paypal_service->build_url( $data, $this->membership, $this->member_email, $subscription_id, $member_id, $response_data );

		if ( is_wp_error( $url ) ) {
			return array(
				'payment_url'   => '',
				'payment_error' => $url->get_error_message(),
			);
		}

		return array(
			'payment_url' => $url,
		);
	}

	/**
	 * Build direct bank response
	 *
	 * @param $payment_data
	 * @param $response_data
	 *
	 * @return array
	 */
	public function build_direct_bank_response( $payment_data, $response_data ) {
		$bank_data = get_option( 'user_registration_global_bank_details', isset( $payment_data['payment_gateways']['bank']['content'] ) ? $payment_data['payment_gateways']['bank']['content'] : '' );

		return array( 'data' => $bank_data );
	}

	public function build_stripe_response( $payment_data, $response_data ) {
		$stripe_service = new StripeService();

		return $stripe_service->process_stripe_payment( $payment_data, $response_data );
	}

	public function build_mollie_response( $data, $subscription_id, $member_id, $response_data = array() ) {
		$success_params    = array();
		$data['plan_name'] = 'membership';

		$base_amount            = floatval( $data['amount'] );
		$currency               = get_option( 'user_registration_payment_currency', 'USD' );
		$local_currency         = ! empty( $response_data['switched_currency'] ) ? $response_data['switched_currency'] : '';
		$ur_zone_id             = ! empty( $response_data['urm_zone_id'] ) ? $response_data['urm_zone_id'] : '';
		$pricing_data           = null;
		$local_currency_data    = array();
		$local_currency_applies = false;

		if ( ! empty( $local_currency ) && ! empty( $ur_zone_id ) && UR_PRO_ACTIVE && ur_check_module_activation( 'local-currency' ) && class_exists( CoreFunctions::class ) ) {
			$pricing_data        = CoreFunctions::ur_get_pricing_zone_by_id( $ur_zone_id );
			$local_currency_data = ! empty( $data['local_currency'] ) ? $data['local_currency'] : array();

			if ( ! empty( $local_currency_data ) && ur_string_to_bool( $local_currency_data['is_enable'] ) ) {
				$currency               = $local_currency;
				$local_currency_applies = true;
				$data['amount']         = CoreFunctions::ur_get_amount_after_conversion( $base_amount, $currency, $pricing_data, $local_currency_data, $ur_zone_id );
			}
		}

		$data['currency'] = $currency;

		// Skip for upgrades: SubscriptionService already bakes the coupon into chargeable_amount.
		$coupon_discount = 0;
		if ( ur_check_module_activation( 'coupon' ) && ! empty( $data['coupon'] ) && empty( $data['upgrade'] ) ) {
			$coupon_service    = new CouponService();
			$coupon_validation = $coupon_service->validate(
				array(
					'coupon'        => $data['coupon'],
					'membership_id' => isset( $data['item_id'] ) ? absint( $data['item_id'] ) : 0,
				)
			);

			if ( $coupon_validation['status'] ) {
				$coupon_details = ur_get_coupon_details( $data['coupon'] );
				if ( ! empty( $coupon_details['coupon_discount_type'] ) && isset( $coupon_details['coupon_discount'] ) ) {
					$coupon_discount = ( 'fixed' === $coupon_details['coupon_discount_type'] )
						? floatval( $coupon_details['coupon_discount'] )
						: floatval( $data['amount'] ) * floatval( $coupon_details['coupon_discount'] ) / 100;

					// Fixed discounts are configured in the base currency — convert before subtracting.
					if ( $local_currency_applies && 'fixed' === $coupon_details['coupon_discount_type'] ) {
						$discounted_base  = max( 0, $base_amount - $coupon_discount );
						$discounted_local = CoreFunctions::ur_get_amount_after_conversion(
							$discounted_base,
							$currency,
							$pricing_data,
							$local_currency_data,
							$ur_zone_id,
							$base_amount
						);
						$coupon_discount = max( 0, floatval( $data['amount'] ) - $discounted_local );
					}

					$data['amount']  = max( 0, floatval( $data['amount'] ) - $coupon_discount );
				}
			}
		}

		// Apply tax to the discounted amount, mirroring Stripe's approach in process_stripe_payment.
		if ( ! empty( $response_data['tax_rate'] ) && ! empty( $response_data['tax_calculation_method'] ) && ur_string_to_bool( $response_data['tax_calculation_method'] ) ) {
			$tax_rate         = (float) $response_data['tax_rate'];
			$base_amount      = floatval( $data['amount'] );
			$tax_amount       = round( $base_amount * $tax_rate / 100, 2 );
			$data['amount']   = round( $base_amount + $tax_amount, 2 );
			$data['tax_info'] = array(
				'base_amount'     => $base_amount,
				'tax_rate'        => $tax_rate,
				'tax_amount'      => $tax_amount,
				'coupon_discount' => $coupon_discount,
			);
		}

		$mollie = new MollieService();

		if ( 'subscription' === $data['type'] ) {
			$success_params = $mollie->mollie_process_subscription_payment( $data, $member_id, $success_params, true, $response_data );
		} else {
			$success_params = $mollie->mollie_process_payment( $data, $member_id, $success_params, true, array(), $response_data );
		}

		if ( isset( $success_params['mollie_redirect'] ) ) {
			return array(
				'payment_url' => $success_params['mollie_redirect'],
			);
		}
	}

	public function build_authorize_response( $payment_data, $response_data ) {
		include_once UR_AUTHORIZE_NET_DIR . 'includes/class-user-registration-authorize-net-service.php';
		$authorize = new \User_Registration_Authorize_Net_Service();
		$authorize->process_authorize_payment( $payment_data, $response_data );
	}
}
