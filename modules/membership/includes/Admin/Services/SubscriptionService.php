<?php

namespace WPEverest\URMembership\Admin\Services;

use DateTime;
use WPEverest\URM\Mollie\Services\PaymentService as MollieService;
use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Services\Paypal\PaypalService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;
use WPEverest\URMembership\Admin\Services\MembersService;
use WPEverest\URMembership\Admin\Services\UpgradeMembershipService;
use WPEverest\URMembership\Admin\Services\CouponService;
use WPEverest\URMembership\Admin\Services\Paypal\NewPaypalService;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

class SubscriptionService {

	protected $members_subscription_repository, $members_orders_repository, $membership_repository, $orders_repository, $subscription_repository;

	public function __construct() {
		$this->members_subscription_repository = new MembersSubscriptionRepository();
		$this->subscription_repository         = new SubscriptionRepository();
		$this->members_orders_repository       = new MembersOrderRepository();
		$this->membership_repository           = new MembershipRepository();
		$this->orders_repository               = new OrdersRepository();
	}

	/**
	 * Prepare data for subscriptions
	 *
	 * @param $data
	 * @param $member
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function prepare_subscription_data( $data, $member ) {

		$current_user    = wp_get_current_user();
		$membership      = get_post( $data['membership_data']['membership'], ARRAY_A );
		$membership_meta = json_decode( wp_unslash( get_post_meta( $membership['ID'], 'ur_membership', true ) ), true );
		$status          = 'pending';

		$is_team_membership   = false;
		$is_team_subscription = false;
		$is_team_one_time     = false;
		$team_duration_value  = null;
		$team_duration_period = null;
		$billing_amount       = $membership_meta['amount'] ?? 0;

		if ( isset( $data['team'] ) && ! empty( $data['team'] ) ) {
			$team_data      = $data['team'];
			$team_plan_type = isset( $team_data['team_plan_type'] ) ? $team_data['team_plan_type'] : null;

			if ( 'subscription' === $team_plan_type || 'one-time' === $team_plan_type ) {
				$is_team_membership      = true;
				$is_team_subscription    = ( 'subscription' === $team_plan_type );
				$is_team_one_time        = ( 'one-time' === $team_plan_type );
				$team_duration_value_raw = isset( $team_data['team_duration_value'] ) ? $team_data['team_duration_value'] : null;
				if ( null !== $team_duration_value_raw && '' !== trim( (string) $team_duration_value_raw ) ) {
					$team_duration_value = absint( $team_duration_value_raw );
					$team_duration_value = ( 0 !== $team_duration_value ) ? $team_duration_value : null;
				} else {
					$team_duration_value = null;
				}
				$team_duration_period = isset( $team_data['team_duration_period'] ) ? sanitize_text_field( $team_data['team_duration_period'] ) : null;

				$seat_model = isset( $team_data['seat_model'] ) ? $team_data['seat_model'] : 'fixed';

				if ( 'fixed' === $seat_model ) {
					$billing_amount = isset( $team_data['team_price'] ) ? floatval( $team_data['team_price'] ) : 0;
				} else {
					$pricing_model = isset( $team_data['pricing_model'] ) ? $team_data['pricing_model'] : 'per_seat';
					$team_seats    = isset( $data['team_seats'] ) ? absint( $data['team_seats'] ) : 0;

					if ( 'per_seat' === $pricing_model ) {
						$per_seat_price = isset( $team_data['per_seat_price'] ) ? floatval( $team_data['per_seat_price'] ) : 0;
						$billing_amount = $team_seats * $per_seat_price;
					} elseif ( 'tier' === $pricing_model ) {
						if ( isset( $data['tier'] ) && isset( $data['tier']['tier_per_seat_price'] ) ) {
							$tier_per_seat_price = floatval( $data['tier']['tier_per_seat_price'] );
							$billing_amount      = $team_seats * $tier_per_seat_price;
						}
					}
				}
			}
		}

		if ( $is_team_subscription && $team_duration_value && $team_duration_period ) {
			$expiry_date = self::get_expiry_date( $data['membership_data']['start_date'], $team_duration_period, $team_duration_value );
			$status      = 'pending';
		} elseif ( $is_team_one_time ) {
			$expiry_date = '';
			$status      = 'pending';
		} elseif ( 'subscription' == $membership_meta['type'] ) { // TODO: calculate with trail date
			$expiry_date = self::get_expiry_date( $data['membership_data']['start_date'], $membership_meta['subscription']['duration'], $membership_meta['subscription']['value'] );
			$status      = 'pending';
		}

		// UR-4386: a ONE-TIME (paid) plan fully covered by a 100% coupon is a free order (no gateway),
		// so activate it immediately like a free plan. Subscriptions are excluded here — they are
		// activated by their gateway flow (e.g. the Stripe delayed-start schedule).
		$is_full_discount_paid = false;
		if ( 'paid' === $membership_meta['type'] && ! empty( $data['coupon_data'] ) ) {
			$plan_amount    = floatval( $membership_meta['amount'] ?? 0 );
			$discount_type  = $data['coupon_data']['coupon_discount_type'] ?? 'fixed';
			$discount_value = floatval( $data['coupon_data']['coupon_discount'] ?? 0 );
			$discount       = ( 'percent' === $discount_type ) ? ( $plan_amount * $discount_value / 100 ) : $discount_value;
			$is_full_discount_paid = ( $plan_amount > 0 ) && ( 0.0 === round( max( 0, $plan_amount - $discount ), 2 ) );
		}

		// Only auto-activate for admin Members UI (or free / 100% coupon). A logged-in
		// administrator submitting a frontend/preview form must still start as pending.
		$is_frontend = isset( $data['context'] ) && 'frontend' === $data['context'];
		$is_admin_created = ! $is_frontend && 0 !== (int) $current_user->ID;

		if ( $is_admin_created || 'free' == $membership_meta['type'] || $is_full_discount_paid ) {
			$status = 'active';
		}

		if ( $current_user->ID != 0 ) {
			$is_purchasing_multiple = isset( $data['is_purchasing_multiple'] ) && ur_string_to_bool( $data['is_purchasing_multiple'] );

			if ( $is_purchasing_multiple && 'free' != $membership_meta['type'] ) {
				$status = 'pending';
			}
		}

		if ( $is_team_subscription && $team_duration_period ) {
			$billing_cycle = $team_duration_period;
		} elseif ( $is_team_one_time ) {
			$billing_cycle = '';
		} else {
			$billing_cycle = ( 'subscription' === $membership_meta['type'] ) ? $membership_meta['subscription']['duration'] : '';
		}

		$subscription_data = array(
			'user_id'           => $member->ID,
			'item_id'           => $membership['ID'],
			'start_date'        => $data['membership_data']['start_date'],
			'expiry_date'       => $expiry_date ?? '',
			'next_billing_date' => $expiry_date ?? '',
			'billing_amount'    => $billing_amount,
			'status'            => $status,
			'cancel_sub'        => $membership_meta['cancel_subscription'] ?? 'immediately',
			'billing_cycle'     => $billing_cycle,
		);

		if ( isset( $data['coupon_data'] ) && ! empty( $data['coupon_data'] ) ) {
			$subscription_data['coupon'] = $data['coupon_data']['coupon_code'];
		}

		if ( isset( $membership_meta['trial_status'] ) && 'on' == $membership_meta['trial_status'] ) {

			$trial_data = array(
				'trial_start_date' => date( 'Y-m-d' ),
				'trial_end_date'   => self::get_expiry_date( date( 'Y-m-d' ), $membership_meta['trial_data']['duration'], $membership_meta['trial_data']['value'] ),
			);

			$subscription_data               = array_merge( $subscription_data, $trial_data );
			$subscription_data['start_date'] = $trial_data['trial_end_date'];

			if ( $is_team_one_time ) {
				$subscription_data['expiry_date'] = '';
			} elseif ( $is_team_subscription && $team_duration_value && $team_duration_period ) {
				$subscription_data['expiry_date'] = self::get_expiry_date( $trial_data['trial_end_date'], $team_duration_period, $team_duration_value );
			} else {
				$subscription_data['expiry_date'] = self::get_expiry_date( $trial_data['trial_end_date'], $membership_meta['subscription']['duration'], $membership_meta['subscription']['value'] );
			}
			$subscription_data['next_billing_date'] = $subscription_data['expiry_date'];
		}

		return $subscription_data;
	}

	/**
	 * Get Expiry date
	 *
	 * @param $start_date
	 * @param $period
	 * @param $value
	 *
	 * @return false|string
	 * @throws \Exception
	 */
	public static function get_expiry_date( $start_date, $period, $value ) {
		$allowedPeriods = array( 'day', 'week', 'month', 'year' );
		if ( ! in_array( $period, $allowedPeriods ) ) {
			return false;
		}
		$start_date   = new \DateTime( $start_date ?? '' );
		$intervalSpec = 'P' . $value . strtoupper( substr( $period, 0, 1 ) );
		$interval     = new \DateInterval( $intervalSpec );
		$start_date->add( $interval );

		return $start_date->format( 'Y-m-d' );
	}

	/**
	 * Cancel subscription
	 *
	 * @param $order
	 * @param $subscription
	 *
	 * @return array|bool[]|void
	 */
	public function cancel_subscription( $order, $subscription, $force_cancel = false ) {
		switch ( $order['payment_method'] ) {
			case 'paypal':
				$paypal_service = new NewPaypalService();

				return $paypal_service->cancel_subscription( $order, $subscription, $force_cancel );

			case 'stripe':
				$stripe_service = new StripeService();

				return $stripe_service->cancel_subscription( $order, $subscription );

			case 'mollie':
				$mollie_service = new MollieService();

				return $mollie_service->cancel_subscription( $order, $subscription );
			case 'bank':
				return array( 'status' => true );
			default:
				return apply_filters( 'user_registration_membership_cancel_subscription', array( 'status' => false ), $order, $subscription );
		}
	}
	public function reactivate_subscription( $order, $subscription ) {
		$logger   = ur_get_logger();
		$response = array( 'status' => false );
		switch ( $order['payment_method'] ) {
			case 'paypal':
				$paypal_service = new NewPaypalService();
				$logger->notice( 'PayPal reactivation Reached', array( 'source' => 'urm-reactivation-log' ) );
				return $paypal_service->reactivate_subscription( $subscription['subscription_id'] );
				break;
			case 'stripe':
				$stripe_service = new StripeService();
				return $stripe_service->reactivate_subscription( $subscription['subscription_id'] );
				break;
			case 'bank':
				return array( 'status' => true );
			default:
				return apply_filters( 'urm_reactivate_membership_subscription', $response, $order, $subscription );
		}
	}
	public function daily_membership_renewal_check() {
		$days_before_value = get_option( 'user_registration_membership_renewal_reminder_days_before', 1 );

		if ( $days_before_value <= 0 ) {
			return;
		}
		$period        = get_option( 'user_registration_membership_renewal_reminder_period', 'weeks' );
		$value_in_days = convert_to_days( $days_before_value, $period );

		$date       = new \DateTime( 'today' );
		$check_date = $date->modify( "+$value_in_days day" )->format( 'Y-m-d H:i:s' );

		$subscriptions = $this->members_subscription_repository->get_about_to_expire_subscriptions( $check_date );

		if ( empty( $subscriptions ) ) {
			return;
		}
		$email_service = new EmailService();
		foreach ( $subscriptions as $subscription ) {
			$user_id = $subscription['member_id'];

			// Skip memberships already scheduled to cancel; they will not renew, so no renewal reminder.
			if ( get_user_meta( $user_id, 'urm_pending_cancel_' . ( $subscription['subscription_id'] ?? '' ), true ) ) {
				continue;
			}

			$checked_date = get_user_meta( $user_id, 'urm_billing_reminder_sent_for_date', true );
			if ( $checked_date === $subscription['next_billing_date'] ) {
				continue;
			}
			$email_service->send_email( $subscription, 'membership_renewal' );
			update_user_meta( $subscription['member_id'], 'urm_billing_reminder_sent_for_date', $subscription['next_billing_date'] );
		}
	}

	/**
	 * send_cancel_emails
	 *
	 * @param $subscription_id
	 *
	 * @return void
	 */
	public function send_cancel_emails( $subscription_id ) {
		$email_service             = new EmailService();
		$current_user_subscription = $this->members_subscription_repository->get_membership_by_subscription_id( $subscription_id, false );

		$member_id = $current_user_subscription['user_id'];

		$latest_order = $this->members_orders_repository->get_member_orders( $member_id );

		$membership = $this->membership_repository->get_single_membership_by_ID( $current_user_subscription['item_id'] );

		$membership_metas          = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$membership_metas['title'] = $membership['post_title'];

		$subscription = $this->members_subscription_repository->get_member_subscription( $member_id );

		$email_data = array(
			'subscription'     => $subscription,
			'order'            => $latest_order,
			'membership_metas' => $membership_metas,
			'member_id'        => $member_id,
		);
		$email_service->send_email( $email_data, 'membership_cancellation_email_user' );
		$email_service->send_email( $email_data, 'membership_cancellation_email_admin' );
	}

	/**
	 * get_membership_plan_details
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function get_membership_plan_details( $data ) {
		$currencies = ur_payment_integration_get_currencies();
		$currency   = get_option( 'user_registration_payment_currency', 'USD' );
		$symbol     = $currencies[ $currency ]['symbol'];

		$subscription_id = '';
		$member_order    = null;

		if ( ! empty( $data['context'] ) && 'thank_you_page' === $data['context'] && ! empty( $data['transaction_id'] ) ) {
			$order_by_txn = $this->orders_repository->get_order_by_transaction_id( $data['transaction_id'] );

			// Only trust this order if it belongs to the member the page is rendered for,
			// otherwise a submitted transaction_id could surface another member's order.
			if ( ! empty( $order_by_txn ) && ! empty( $order_by_txn['ID'] )
				&& isset( $order_by_txn['user_id'], $data['member_id'] )
				&& (int) $order_by_txn['user_id'] === (int) $data['member_id']
			) {
				$member_order = $order_by_txn;
			}
		}

		if ( empty( $member_order ) ) {
			$member_order = $this->members_orders_repository->get_member_orders( $data['member_id'] );
		}

		if ( isset( $data['subscription']['ID'] ) ) {
			$subscription_id = $data['subscription']['ID'] ?? 0;
		} else {
			$subscription_id = ! empty( $member_order ) ? ( $member_order['subscription_id'] ?? '' ) : '';
		}

		$subscription  = $this->members_subscription_repository->get_subscription_by_subscription_id( $subscription_id );
		$membership_id = isset( $data['membership'] ) ? $data['membership'] : ( isset( $subscription['item_id'] ) ? $subscription['item_id'] : 0 );
		$membership    = $this->membership_repository->get_single_membership_by_ID( $membership_id );

		$membership_metas               = ! empty( $membership['meta_value'] ) ? wp_unslash( json_decode( $membership['meta_value'], true ) ) : array();
		$membership_metas['post_title'] = $membership['post_title'] ?? '';
		$member_order                   = $member_order ? $member_order : $this->members_orders_repository->get_member_orders( $data['member_id'] );
		$order                          = ! empty( $member_order['ID'] ) ? $this->orders_repository->get_order_detail( $member_order['ID'] ) : array();
		$total                          = $order['total_amount'] ?? 0;
		$membership_tab_url             = esc_url( ur_get_my_account_url() . 'ur-membership' );

		$order_repository = new OrdersRepository();
		$local_currency   = ! empty( $order['order_id'] ) ? $order_repository->get_order_meta_by_order_id_and_meta_key( $order['order_id'], 'local_currency' ) : array();

		$currency = ! empty( $local_currency['meta_value'] ) ? $local_currency['meta_value'] : $currency;
		$symbol   = ur_get_currency_symbol( $currency );

		// Convert the plan's base price into $currency so it matches $symbol/$total above.
		$plan_amount = isset( $membership_metas['amount'] ) ? (float) $membership_metas['amount'] : 0;

		if (
			$currency !== get_option( 'user_registration_payment_currency', 'USD' ) &&
			! empty( $membership_metas['local_currency'] ) &&
			ur_string_to_bool( $membership_metas['local_currency']['is_enable'] ) &&
			class_exists( CoreFunctions::class )
		) {
			$plan_zone_id = CoreFunctions::ur_get_zone_id_by_currency( $membership_metas['local_currency'], $currency );

			if ( ! empty( $plan_zone_id ) ) {
				$plan_pricing_data = CoreFunctions::ur_get_pricing_zone_by_id( $plan_zone_id );
				$plan_amount       = CoreFunctions::ur_get_amount_after_conversion( $plan_amount, $currency, $plan_pricing_data, $membership_metas['local_currency'], $plan_zone_id );
			}
		}

		if ( ! empty( $data['context'] ) && 'thank_you_page' == $data['context'] ) {
			$data['payment_method'] = ! empty( $member_order['payment_method'] ) ? $member_order['payment_method'] : '';
			$data['transaction_id'] = ! empty( $member_order['transaction_id'] ) ? $member_order['transaction_id'] : '';
		}

		if ( ! empty( $order['coupon'] ) && isset( $membership_metas ) && ( 'paid' === $membership_metas['type'] || ( 'subscription' === $membership_metas['type'] && 'on' !== ( $order['trial_status'] ?? '' ) ) ) ) {
			$coupon_meta       = ur_get_coupon_meta_by_code( $order['coupon'] );
			$order_coupon_meta = $order_repository->get_order_meta_by_order_id_and_meta_key( $order['order_id'], 'coupon_data' );

			if ( empty( $order_coupon_meta['meta_value'] ) && 'bank' !== $order['payment_method'] ) {
				// Legacy order: total_amount stored pre-discount — recompute discounted total for display.
				if ( ! empty( $coupon_meta ) ) {
					$coupon_discount = isset( $coupon_meta->coupon_discount ) ? (float) $coupon_meta->coupon_discount : 0;
					$discount_amount = ( isset( $coupon_meta->coupon_discount_type ) && 'fixed' === $coupon_meta->coupon_discount_type ) ? $coupon_discount : $order['total_amount'] * $coupon_discount / 100;
					$total           = $order['total_amount'] - $discount_amount;
				} else {
					$coupon_discount = isset( $order['coupon_discount'] ) ? (float) $order['coupon_discount'] : 0;
					$discount_amount = ( isset( $order['coupon_discount_type'] ) && 'fixed' === $order['coupon_discount_type'] ) ? $coupon_discount : $order['total_amount'] * $coupon_discount / 100;
					$total           = $order['total_amount'] - $discount_amount;
				}
			}
			// New orders have coupon_data meta — total_amount already reflects the actual paid amount.
		}
		$billing_cycle = ( 'subscription' === ( $membership_metas['type'] ?? '' ) ) ? ( ( 'day' === $membership_metas['subscription']['duration'] ) ? esc_html( 'Daily', 'user-registration' ) : ( esc_html( ucfirst( $membership_metas['subscription']['duration'] . 'ly' ) ) ) ) : 'N/A';
		$trial_period  = ( 'subscription' === ( $membership_metas['type'] ?? '' ) && 'on' === $order['trial_status'] ) ? ( $membership_metas['trial_data']['value'] . ' ' . $membership_metas['trial_data']['duration'] . ( $membership_metas['trial_data']['value'] > 1 ? 's' : '' ) ) : 'N/A';

		$next_billing_date = 'subscription' === ( $membership_metas['type'] ?? '' ) && ! empty( $subscription['next_billing_date'] ) ? date( 'Y, F d', strtotime( $subscription['next_billing_date'] ) ) : 'N/A';
		$expiry_date       = 'subscription' === ( $membership_metas['type'] ?? '' ) && ! empty( $subscription['expiry_date'] ) ? date( 'Y, F d', strtotime( $subscription['expiry_date'] ) ) : 'N/A';
		$trial_start_date  = 'subscription' === ( $membership_metas['type'] ?? '' ) && 'on' === $order['trial_status'] && ! empty( $subscription['trial_start_date'] ) ? date( 'Y, F d', strtotime( $subscription['trial_start_date'] ) ) : 'N/A';
		$trial_end_date    = 'subscription' === ( $membership_metas['type'] ?? '' ) && 'on' === $order['trial_status'] && ! empty( $subscription['trial_end_date'] ) ? date( 'Y, F d', strtotime( $subscription['trial_end_date'] ) ) : 'N/A';
		$membership_type   = ucwords( $membership_metas['type'] ?? '' ) == 'Paid' ? __( 'One-Time Payment', 'user-registration' ) : ucwords( $membership_metas['type'] ?? '' );

		$team_data  = null;
		$team_seats = null;
		$tier_info  = null;
		if ( ! empty( $member_order['ID'] ) ) {
			$ordermeta_table = $this->orders_repository->wpdb()->prefix . 'ur_membership_ordermeta';
			$team_id         = $this->orders_repository->wpdb()->get_var(
				$this->orders_repository->wpdb()->prepare(
					"SELECT meta_value FROM {$ordermeta_table} WHERE meta_key=%s AND order_id=%d LIMIT 1",
					'urm_team_id',
					$member_order['ID']
				)
			);

			if ( ! empty( $team_id ) ) {
				$team_data  = get_post_meta( $team_id, 'urm_team_data', true );
				$team_seats = get_post_meta( $team_id, 'urm_team_seats', true );
				$tier_info  = get_post_meta( $team_id, 'urm_tier_info', true );
			}
		}

		$return_array = array(
			'username'                          => esc_html( ucwords( isset( $data['username'] ) ? $data['username'] : '' ) ),
			'membership_plan_name'              => esc_html( ucwords( $membership_metas['post_title'] ) ),
			'membership_plan_type'              => esc_html( $membership_type ),
			'membership_plan_payment_method'    => esc_html( ucwords( isset( $data['order']['payment_method'] ) ? $data['order']['payment_method'] : ( $data['payment_method'] ?? '' ) ) ),
			'membership_plan_trial_status'      => esc_html( ucwords( $order['trial_status'] ?? '' ) ),
			'membership_plan_trial_start_date'  => esc_html( $trial_start_date ),
			'membership_plan_trial_end_date'    => esc_html( $trial_end_date ),
			'membership_plan_trial_period'      => esc_html( $trial_period ),
			'membership_plan_next_billing_date' => esc_html( $next_billing_date ),
			'membership_plan_expiry_date'       => esc_html( $expiry_date ),
			'membership_plan_status'            => isset( $subscription['status'] ) ? esc_html( ucwords( $subscription['status'] ) ) : '',
			'membership_plan_payment_date'      => ! empty( $order['created_at'] ) ? esc_html( date( 'Y, F d', strtotime( $order['created_at'] ) ) ) : '',
			'membership_plan_billing_cycle'     => esc_html( ucwords( $billing_cycle ) ),
			'membership_plan_payment_amount'    => ( ! empty( $currencies[ $currency ]['symbol_pos'] ) && 'left' === $currencies[ $currency ]['symbol_pos'] ) ? $symbol . number_format( $plan_amount, 2 ) : number_format( $plan_amount, 2 ) . $symbol,
			'membership_plan_payment_status'    => esc_html( ucwords( $order['status'] ?? '' ) ),
			'membership_plan_trial_amount'      => ( ! empty( $currencies[ $currency ]['symbol_pos'] ) && 'left' === $currencies[ $currency ]['symbol_pos'] ) ? $symbol . number_format( ( 'on' === ( $order['trial_status'] ?? '' ) ) ? ( $order['total_amount'] ?? 0 ) : 0, 2 ) : number_format( ( 'on' === ( $order['trial_status'] ?? '' ) ) ? ( $order['total_amount'] ?? 0 ) : 0, 2 ) . $symbol,
			'membership_plan_coupon_discount'   => (
				isset( $order['coupon_discount'] )
					? (
						( isset( $order['coupon_discount_type'] ) && $order['coupon_discount_type'] == 'percent' )
							? $order['coupon_discount'] . '%'
							: $symbol . $order['coupon_discount']
					)
					: (
						isset( $coupon_meta->coupon_discount )
							? (
								( isset( $coupon_meta->coupon_discount_type ) && $coupon_meta->coupon_discount_type == 'percent' )
									? $coupon_meta->coupon_discount . '%'
									: $symbol . $coupon_meta->coupon_discount
							)
							: ''
					)
			),
			'membership_plan_coupon'            => esc_html( $order['coupon'] ?? '' ),
			'membership_plan_total'             => ( ! empty( $currencies[ $currency ]['symbol_pos'] ) && 'left' === $currencies[ $currency ]['symbol_pos'] ) ? $symbol . number_format( $total, 2 ) : number_format( $total, 2 ) . $symbol,
			'membership_renewal_link'           => "<a href=$membership_tab_url>" . __( 'Renew Now', 'user-registration' ) . '</a>',
			'membership_plan_transaction_id'    => ! empty( $data['transaction_id'] ) ? $data['transaction_id'] : '',
		);

		if ( ! empty( $team_data ) ) {
			$return_array['team'] = $team_data;
		}
		if ( ! empty( $team_seats ) ) {
			$return_array['team_seats'] = $team_seats;
		}
		if ( ! empty( $tier_info ) ) {
			$return_array['tier'] = $tier_info;
		}

		return $return_array;
	}

	/**
	 * Handles the membership upgrade process.
	 *
	 * This function is called when a user chooses to upgrade their membership. It calculates the upgrade cost, cancels the previous subscription,
	 * creates a new order and redirects the user to the payment gateway.
	 *
	 * @param array $data The data passed from the front end.
	 *
	 * @return array The response from the payment gateway.
	 */
	public function upgrade_membership( $data ) {
		$order_service = new OrderService();

		$current_subscription_id                   = $data['current_subscription_id'];
		$subscription                              = $this->subscription_repository->retrieve( $data['current_subscription_id'] );

		if ( empty( $subscription ) || (int) $subscription['user_id'] !== get_current_user_id() ) {
			return array(
				'response' => array(
					'status'  => false,
					'message' => __( 'You do not have permission to upgrade this subscription.', 'user-registration' ),
				),
			);
		}

		$user                                      = get_userdata( $subscription['user_id'] );
		$payment_method                            = $data['selected_pg'];
		$membership                                = $this->membership_repository->get_single_membership_by_ID( $subscription['item_id'] );
		$current_membership_details                = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$current_membership_details['post_title']  = $membership['post_title'];
		$membership                                = $this->membership_repository->get_single_membership_by_ID( $data['selected_membership_id'] );
		$selected_membership_details               = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$selected_membership_details['post_title'] = $membership['post_title'];
		$selected_membership_details['membership'] = $data['selected_membership_id'];

		// Validate that the submitted payment method is one the destination membership actually supports.
		if ( ! ( new MembershipService() )->is_valid_payment_method_for_membership( $selected_membership_details, $payment_method, $data ) ) {
			return array(
				'response' => array(
					'status'  => false,
					'message' => __( 'Invalid payment method for this membership.', 'user-registration' ),
				),
			);
		}

		$selected_membership_details['payment_method'] = $payment_method;

		$lock_name = 'urm_upgrade_lock_' . (int) $user->ID;
		$lock      = $this->subscription_repository->acquire_lock( $lock_name );

		$membership_process = urm_get_membership_process( $user->ID );
		$in_progress        = $membership_process['upgrade'][ $data['current_membership_id'] ] ?? array();
		// A stale guard means a previous attempt died or was abandoned before completing; let it be retried.
		$is_upgrading = ! empty( $in_progress ) && ( time() - (int) ( $in_progress['started_at'] ?? 0 ) ) < 15 * MINUTE_IN_SECONDS;

		if ( false === $lock || $is_upgrading ) {
			if ( true === $lock ) {
				$this->subscription_repository->release_lock( $lock_name );
			}
			$response['response']['status']  = false;
			$response['response']['message'] = __( 'Membership upgrade process already initiated.', 'user-registration' );

			return $response;
		}

		if ( 'free' !== $payment_method ) {
			$membership_process['upgrade'][ $data['current_membership_id'] ] = array(
				'from'            => $data['current_membership_id'],
				'to'              => $data['selected_membership_id'],
				'subscription_id' => $data['current_subscription_id'],
				'started_at'      => time(),
			);

			update_user_meta( $user->ID, 'urm_membership_process', $membership_process );
		}

		if ( true === $lock ) {
			$this->subscription_repository->release_lock( $lock_name );
		}

		$current_membership_details['ID']  = $data['current_membership_id'];
		$selected_membership_details['ID'] = $data['selected_membership_id'];
		$upgrade_details                   = $this->calculate_membership_upgrade_cost( $current_membership_details, $selected_membership_details, $subscription );

		if ( isset( $upgrade_details['status'] ) && ! $upgrade_details['status'] ) {
			$this->release_upgrade_guard( $user->ID, $data['current_membership_id'] );

			return array(
				'response' => $upgrade_details,
			);
		}

		$members_data = array(
			'context'         => 'frontend',
			'membership_data' => $selected_membership_details,
		);

		// Forward the local-currency zone so the new order is charged in it (see OrderService::prepare_orders_data()).
		if ( ! empty( $data['switched_currency'] ) && ! empty( $data['urm_zone_id'] ) ) {
			$members_data['local_currency_details'] = array(
				'switched_currency' => $data['switched_currency'],
				'urm_zone_id'       => $data['urm_zone_id'],
			);
		}

		if ( ! empty( $data['tax_rate'] ) ) {
			$members_data['tax_data'] = array(
				'tax_rate'               => floatval( $data['tax_rate'] ),
				'tax_calculation_method' => ur_string_to_bool( $data['tax_calculation_method'] ?? '1' ),
			);
		}

		if ( ! empty( $data['coupon'] ) ) {
			$members_data['coupon'] = $data['coupon'];
			$coupon_service         = new CouponService();
			$coupon_data            = array(
				'coupon'         => $data['coupon'],
				'membership_id'  => $data['selected_membership_id'],
				'upgrade_amount' => $upgrade_details['chargeable_amount'],
			);

			$response = $coupon_service->validate( $coupon_data );

			if ( $response['status'] ) {
				$response                             = json_decode( $response['data'], true );
				$upgrade_details['chargeable_amount'] = $response['discounted_amount'];
			}
		}

		$membership_details = ! empty( $data['selected_membership_id'] ) ? json_decode( get_post_meta( $data['selected_membership_id'], 'ur_membership', true ), true ) : array();

		if ( ! empty( $membership_details ) ) {
			$members_data['role'] = ! empty( $membership_details['role'] ) ? $membership_details['role'] : 'subscriber';
		}

		// UR-4573: Flag the upgrade so the previous membership's role is swapped out (instead of
		// accumulating) while the user's base role is preserved.
		$members_data['is_upgrade']    = true;
		$members_data['previous_role'] = ! empty( $current_membership_details['role'] ) ? sanitize_text_field( $current_membership_details['role'] ) : '';

		$members_data['defer_role'] = 'free' !== $payment_method;
		$member_service = new MembersService();
		$member_service->update_user_meta( $members_data, $user->ID );

		if ( isset( $data['upgrade'] ) && $data['upgrade'] && 'subscription' === $current_membership_details['type'] && 'bank' !== $payment_method && 'off' === $selected_membership_details['trial_status'] && ! isset( $upgrade_details['delayed_until'] ) ) {

			$cancel_subscription = $this->subscription_repository->cancel_subscription_by_id( $current_subscription_id, false );

			if ( ! $cancel_subscription['status'] ) {
				$response['status'] = false;

				$this->release_upgrade_guard( $user->ID, $data['current_membership_id'] );

				return $response;
			} else {
				$this->subscription_repository->cancel_subscription_by_id( $current_subscription_id, false );
			}
		}

		// save previous order
		$latest_order = $this->members_orders_repository->get_member_orders( $user->ID );
		update_user_meta( $user->ID, 'urm_previous_order_data', json_encode( $latest_order ) );

		$orders_data = $order_service->prepare_orders_data( $members_data, $user->ID, $subscription, $upgrade_details ); // prepare data for orders table.
		$order       = $this->orders_repository->create( $orders_data );

		$payment_service       = new PaymentService( $payment_method, $data['selected_membership_id'], $user->data->user_email );
		$ur_authorize_net_data = isset( $data['ur_authorize_net'] ) ? $data['ur_authorize_net'] : array();
		$coupon                = isset( $data['coupon'] ) ? $data['coupon'] : '';

		$data = array(
			'membership'             => $data['selected_membership_id'],
			'subscription_id'        => $subscription['ID'],
			'member_id'              => $user->ID,
			'email'                  => $user->user_email,
			'transaction_id'         => $orders_data['orders_data']['transaction_id'],
			'upgrade'                => true,
			'subscription_data'      => $subscription,
			'ur_authorize_net'       => $ur_authorize_net_data,
			'selected_membership_id' => $data['selected_membership_id'],
			'current_membership_id'  => $data['current_membership_id'],
			'order_id'               => $order['ID'],
			'tax_rate'               => ! empty( $data['tax_rate'] ) ? $data['tax_rate'] : '',
			'tax_calculation_method' => ! empty( $data['tax_calculation_method'] ) ? $data['tax_calculation_method'] : '',
			'switched_currency'      => ! empty( $data['switched_currency'] ) ? $data['switched_currency'] : '',
			'urm_zone_id'            => ! empty( $data['urm_zone_id'] ) ? $data['urm_zone_id'] : '',
		);

		if ( ! empty( $coupon ) ) {
			$data['coupon'] = $coupon;
		}

		$data               = $data + $upgrade_details;
		$response           = $payment_service->build_response( $data );
		$response['status'] = false;

		if ( isset( $response['payment_url'] ) || isset( $response['data'] ) || 'stripe' === $payment_method || 'free' === $payment_method ) {
			$response['status'] = true;

		} else {
			$this->release_upgrade_guard( $user->ID, $data['current_membership_id'] );
			$this->orders_repository->delete( $order['ID'] );
		}

		return array(
			'extra'    => array(
				'member_id'                => $user->ID,
				'username'                 => $user->user_login,
				'transaction_id'           => $orders_data['orders_data']['transaction_id'],
				'order_id'                 => $order['ID'],
				'updated_membership_title' => $selected_membership_details['post_title'],
			),
			'response' => $response,
		);
	}

	private function release_upgrade_guard( $member_id, $current_membership_id ) {
		$membership_process = urm_get_membership_process( $member_id );
		if ( ! empty( $membership_process['upgrade'][ $current_membership_id ] ) ) {
			unset( $membership_process['upgrade'][ $current_membership_id ] );
			update_user_meta( $member_id, 'urm_membership_process', $membership_process );
		}
	}

	/**
	 * Calculate upgrade cost and details between memberships.
	 *
	 * Determines the chargeable amount for upgrading from a current to a selected membership.
	 * Considers subscription duration, trial periods, and subscription status.
	 *
	 * @param array $current_membership_details Current membership details.
	 * @param array $selected_membership_details Selected membership details.
	 * @param array $subscription Subscription details.
	 *
	 * @return array Upgrade calculation results.
	 */
	public function calculate_membership_upgrade_cost( $current_membership_details, $selected_membership_details, $subscription ) {
		$upgrade_type               = $current_membership_details['type'] . '->' . $selected_membership_details['type'];
		$upgrade_membership_service = new UpgradeMembershipService();

		$result['status'] = true;

		// Does the NEW plan have a trial? Drives the order's trial_status field.
		$new_plan_has_trial = isset( $selected_membership_details['trial_status'] ) && 'on' === $selected_membership_details['trial_status'];

		// Is the CURRENT subscription still actively in its trial period?
		// Proration is only skipped when the current sub hasn't been billed yet.
		$current_sub_in_trial = ! empty( $subscription['trial_end_date'] ) && $subscription['trial_end_date'] > date( 'Y-m-d H:i:s' );

		// Passed to the upgrade handler: controls whether proration is bypassed.
		$is_trial = $current_sub_in_trial;

		switch ( $upgrade_type ) {
			case 'free->free':
			case 'paid->free':
				$result['chargeable_amount'] = 0;
				break;
			case 'subscription->free':
				$result['chargeable_amount'] = 0;
				$result['delayed_until']     = $subscription['expiry_date'];
				break;
			case 'free->paid':
				$result['chargeable_amount'] = $selected_membership_details['amount'];
				break;
			case 'free->subscription':
				$result['chargeable_amount']            = $selected_membership_details['amount'];
				$result['remaining_subscription_value'] = $selected_membership_details['subscription']['value'];
				break;
			case 'paid->paid':
				$result = $upgrade_membership_service->handle_paid_to_paid_membership_upgrade( $current_membership_details, $selected_membership_details, $subscription );
				break;
			case 'paid->subscription':
				$result = $upgrade_membership_service->handle_paid_to_subscription_membership_upgrade( $current_membership_details, $selected_membership_details, $subscription );
				break;
			case 'subscription->subscription':
			case 'subscription->paid':
				$result = $upgrade_membership_service->handle_subscription_to_paid_or_subscription_membership_upgrade( $current_membership_details, $selected_membership_details, $subscription, $is_trial );
				break;
		}

		if ( ! $result['status'] ) {
			return $result;
		}

		return array(
			'trial_status'                 => $new_plan_has_trial ? 'on' : 'off',
			'chargeable_amount'            => isset( $result['chargeable_amount'] ) ? $result['chargeable_amount'] : 0,
			'remaining_subscription_value' => ! empty( $result['remaining_subscription_value'] ) ? $result['remaining_subscription_value'] : 0,
			'delayed_until'                => ! empty( $result['delayed_until'] ) ? $result['delayed_until'] : '',
		);
	}

	/**
	 * validate if a membership can validate from current to another
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function can_upgrade( $data ) {
		$membership_service       = new MembershipService();
		$upgrade_service          = new UpgradeMembershipService();
		$membership_details       = $membership_service->get_membership_details( $data['current_membership_id'] );
		$membership_details['ID'] = $data['current_membership_id'];

		$upgrade_details = $upgrade_service->get_upgrade_details( $membership_details );
		$status          = true;
		if ( empty( $upgrade_details['upgrade_path'] ) ) {
			return array(
				'status'  => false,
				'message' => __( 'Sorry, you cannot upgrade to the selected plan.', 'user-registration' ),
			);
		}

		if ( is_array( $upgrade_details['upgrade_path'] ) && isset( $upgrade_details['upgrade_path'][ $data['current_membership_id'] ] ) ) {
			$current_upgrade_path   = $upgrade_details['upgrade_path'][ $data['current_membership_id'] ];
			$selected_membership_id = $data['selected_membership_id'];
			$status                 = array_filter(
				$current_upgrade_path,
				function ( $item ) use ( $selected_membership_id ) {
					return $item['membership_id'] === $selected_membership_id;
				}
			);

		} else {
			$upgradable_memberships = explode( ',', $upgrade_details['upgrade_path'] );
			$status                 = in_array( $data['selected_membership_id'], $upgradable_memberships );
		}

		if ( ! $status ) {
			return array(
				'status'  => false,
				'message' => __( 'Sorry, you cannot upgrade to the selected plan.', 'user-registration' ),
			);
		}

		$subscription                = $this->subscription_repository->retrieve( $data['current_subscription_id'] );

		if ( empty( $subscription ) || (int) $subscription['user_id'] !== get_current_user_id() ) {
			return array(
				'status'  => false,
				'message' => __( 'You do not have permission to upgrade this subscription.', 'user-registration' ),
			);
		}

		$membership                  = $this->membership_repository->get_single_membership_by_ID( $data['selected_membership_id'] );
		$selected_membership_details = wp_unslash( json_decode( $membership['meta_value'], true ) );

		if ( isset( $selected_membership_details['trial_status'] ) && 'on' === $selected_membership_details['trial_status'] && ! empty( $subscription['trial_end_date'] ) && $subscription['trial_end_date'] < date( 'Y-m-d H:i:s' ) ) {
			return array(
				'status'  => false,
				'message' => __( 'Sorry, You’re not eligible for another trial. Please choose a regular membership plan.', 'user-registration' ),
			);
		}

		return array(
			'status' => true,
		);
	}

	/**
	 * Validate if a user with a membership can purchase another.
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function can_purchase_multiple( $data ) {
		$membership_service                = new MembershipService();
		$membership_group_repository       = new MembershipGroupRepository();
		$members_repository                = new MembersRepository();
		$membership_group_service          = new MembershipGroupService();
		$multiple_purchasable_with_current = array();
		$multiple_allowed                  = false;

		if ( UR_PRO_ACTIVE && urm_check_if_plus_and_above_plan() && ur_check_module_activation( 'membership-groups' ) ) {

			if ( isset( $data['selected_membership_id'] ) && ! empty( $data['selected_membership_id'] ) ) {
				$membership_id    = absint( $data['selected_membership_id'] );
				$membership_group = $membership_group_repository->get_membership_group_by_membership_id( $membership_id );

				if ( ! empty( $membership_group ) && isset( $membership_group['ID'] ) ) {
					$multiple_allowed = $membership_group_service->check_if_multiple_memberships_allowed( $membership_group['ID'] );
				}

				$user_membership_group_ids = array();
				$current_user_id           = get_current_user_id();

				if ( $current_user_id ) {
					$user_memberships          = $members_repository->get_member_membership_by_id( $current_user_id );
					$user_membership_group_ids = array_filter(
						array_map(
							function ( $user_memberships ) use ( $membership_group_repository ) {
								$group = $membership_group_repository->get_membership_group_by_membership_id( $user_memberships['post_id'] );
								if ( isset( $group['ID'] ) ) {
									return $group['ID'];
								}
							},
							$user_memberships
						)
					);

					$user_membership_group_ids = array_values( array_unique( $user_membership_group_ids ) );

					if ( ! in_array( $membership_group, $user_membership_group_ids ) ) {
						$multiple_allowed = true;
					}
				}
			}
		}

		if ( $multiple_allowed ) {

			return array(
				'status' => true,
			);

		} else {
			return array(
				'status'  => false,
				'message' => esc_html__( 'Sorry, multiple memberships are not allowed with your selected plan.', 'user-registration' ),
			);
		}
	}

	public function run_daily_delayed_membership_subscriptions() {
		$all_delayed_orders = $this->orders_repository->get_all_delayed_orders( date( 'Y-m-d 00:00:00' ) );

		ur_get_logger()->notice(
			sprintf(
				__( 'Scheduled Subscriptions job started for the date: (%s)', 'user-registration' ),
				date( 'd F,Y' )
			),
			array( 'source' => 'urm-membership-crons' )
		);

		if ( empty( $all_delayed_orders ) ) {
			ur_get_logger()->notice( __( 'No delayed orders found.', 'user-registration' ), array( 'source' => 'urm-membership-crons' ) );

			return;
		}

		ur_get_logger()->debug(
			'Delayed orders:' . "\n" . wp_json_encode(
				$all_delayed_orders,
				JSON_PRETTY_PRINT
			),
			array( 'source' => 'urm-membership-crons' )
		);

		$updated_subscription_for_users = array();

		foreach ( $all_delayed_orders as $data ) {
			$decoded_data = json_decode( $data['sub_data'], true );
			if ( ! isset( $decoded_data['subscription_id'] ) ) {
				continue;
			}
			$subscription_id = $decoded_data['subscription_id'];
			$user            = get_userdata( $decoded_data['member_id'] );
			if ( $user ) {
				$cancel_subscription = $this->subscription_repository->cancel_subscription_by_id( $subscription_id, false, true );
				ur_get_logger()->notice( $cancel_subscription['message'], array( 'source' => 'urm-membership-crons' ) );
				$previous_subscription             = json_decode( get_user_meta( $user->ID, 'urm_previous_subscription_data', true ), true );
				$updated_subscription_for_users[]  = $user->user_login;
				$decoded_data['subscription_data'] = $previous_subscription;
				$subscription_data                 = $this->prepare_upgrade_subscription_data( $decoded_data['membership'], $decoded_data['member_id'], $decoded_data );
				$subscription_data['status']       = 'active';
				$this->subscription_repository->update( $subscription_id, $subscription_data );
				$last_order = $this->members_orders_repository->get_member_orders( $user->ID );
				$this->orders_repository->delete_order_meta(
					array(
						'order_id' => $last_order['ID'],
						'meta_key' => 'delayed_until',
					)
				);
				delete_user_meta( $user->ID, 'urm_next_subscription_data' );
				delete_user_meta( $user->ID, 'urm_previous_subscription_data' );
				delete_user_meta( $user->ID, 'urm_previous_order_data' );
			}
		}

		ur_get_logger()->notice(
			sprintf(
				__( 'Subscription updated for %s', 'user-registration' ),
				implode( ',', $updated_subscription_for_users )
			),
			array( 'source' => 'urm-membership-crons' )
		);
	}

	/**
	 * Prepares subscription data for upgrading a membership.
	 *
	 * This method retrieves and processes the membership data for a given member,
	 * calculates the expiry and trial dates based on the membership type, and
	 * constructs an array of subscription data including user ID, membership ID,
	 * start date, expiry date, next billing date, billing amount, and status.
	 *
	 * @param int   $membership_id The ID of the membership to be upgraded.
	 * @param int   $member_id The ID of the member for whom the subscription is being upgraded.
	 * @param array $extra_data Additional data required for preparing the subscription, such as
	 *                          remaining subscription days, trial status, and total used trial days.
	 *
	 * @return array The prepared subscription data including trial information if applicable.
	 * @throws \Exception
	 */
	public function prepare_upgrade_subscription_data( $membership_id, $member_id, $extra_data ) {
		$current_subscription         = $extra_data['subscription_data'];
		$remaining_subscription_value = $extra_data['remaining_subscription_value'];
		$membership                   = get_post( $membership_id, ARRAY_A );
		$membership_meta              = json_decode( wp_unslash( get_post_meta( $membership['ID'], 'ur_membership', true ) ), true );
		$expiry_date                  = '';
		if ( 'subscription' == $membership_meta['type'] ) { // TODO: calculate with trial date
			$expiry_date = self::get_expiry_date( date( 'Y-m-d' ), $membership_meta['subscription']['duration'], $remaining_subscription_value );
		}
		$billing_cycle = ( 'subscription' === $membership_meta['type'] ) ? $membership_meta['subscription']['duration'] : '';

		$subscription_data = array(
			'user_id'           => $member_id,
			'item_id'           => $membership['ID'],
			'start_date'        => date( 'Y-m-d 00:00:00' ),
			'expiry_date'       => $expiry_date,
			'next_billing_date' => $expiry_date,
			'billing_amount'    => $membership_meta['amount'] ?? 0,
			'status'            => 'free' === $membership_meta['type'] ? 'active' : 'pending',
			'billing_cycle'     => $billing_cycle,
		);

		if ( isset( $extra_data['coupon'] ) && ! empty( $extra_data['coupon'] ) ) {
			$subscription_data['coupon'] = $extra_data['coupon'];
		}

		if ( isset( $membership_meta['trial_status'] ) && 'on' === $membership_meta['trial_status'] ) {
			$remaining_trial_days          = $membership_meta['trial_data']['value'];
			$dont_calculate_trial_end_date = ! empty( $current_subscription['trial_end_date'] ) && $current_subscription['trial_end_date'] > date( 'Y-m-d 00:00:00' );
			$trial_data                    = array(
				'trial_start_date' => date( 'Y-m-d' ),
				'trial_end_date'   => ! $dont_calculate_trial_end_date ? self::get_expiry_date( date( 'Y-m-d' ), $membership_meta['trial_data']['duration'], $remaining_trial_days ) : $current_subscription['trial_end_date'],
			);

			$subscription_data                      = array_merge( $subscription_data, $trial_data );
			$subscription_data['start_date']        = $trial_data['trial_end_date'];
			$subscription_data['expiry_date']       = self::get_expiry_date( $trial_data['trial_end_date'], $membership_meta['subscription']['duration'], $remaining_subscription_value );
			$subscription_data['next_billing_date'] = $subscription_data['expiry_date'];
		}

		return $subscription_data;
	}

	public function renew_membership( $user, $selected_pg, $membership_id, $team_id ) {
		$member_id                            = $user->ID;
		$username                             = $user->user_login;
		$member_subscription                  = $this->members_subscription_repository->get_subscription_data_by_member_and_membership_id( $member_id, $membership_id );
		$membership                           = $this->membership_repository->get_single_membership_by_ID( $member_subscription['item_id'] );
		$membership_details                   = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$membership_details['payment_method'] = $selected_pg;
		$membership_details['post_title']     = $membership['post_title'];
		$membership_details['membership']     = $membership_id;
		$order_service                        = new OrderService();
		$members_data                         = array(
			'context'         => 'frontend',
			'membership_data' => $membership_details,
		);

		// Re-derive the member's local-currency zone from their most recent order for this renewal.
		$switched_currency = '';
		$switched_zone_id  = null;

		if ( UR_PRO_ACTIVE && ur_check_module_activation( 'local-currency' ) && class_exists( CoreFunctions::class ) ) {
			$previous_order = $this->orders_repository->get_order_by_subscription( $member_subscription['ID'] );

			if ( ! empty( $previous_order['ID'] ) ) {
				$previous_local_currency = $this->orders_repository->get_order_meta_by_order_id_and_meta_key( $previous_order['ID'], 'local_currency' );
				$switched_currency        = ! empty( $previous_local_currency['meta_value'] ) ? $previous_local_currency['meta_value'] : '';

				if ( ! empty( $switched_currency ) && ! empty( $membership_details['local_currency'] ) && ur_string_to_bool( $membership_details['local_currency']['is_enable'] ) ) {
					$switched_zone_id = CoreFunctions::ur_get_zone_id_by_currency( $membership_details['local_currency'], $switched_currency );
				}
			}
		}

		if ( ! empty( $switched_currency ) && ! empty( $switched_zone_id ) ) {
			$members_data['local_currency_details'] = array(
				'switched_currency' => $switched_currency,
				'urm_zone_id'       => $switched_zone_id,
			);
		}

		$membership_process = urm_get_membership_process( $member_id );
		if ( $membership_process && ! in_array( $membership_id, $membership_process['renew'] ) ) {
			$membership_process['renew'][] = $membership_id;
			update_user_meta( $member_id, 'urm_membership_process', $membership_process );
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Membership renew process already initiated.', 'user-registration' ),
				)
			);
		}

		$orders_data     = $order_service->prepare_orders_data( $members_data, $member_id, $member_subscription, array(), true ); // prepare data for orders table.
		$order           = $this->orders_repository->create( $orders_data );
		$payment_service = new PaymentService( $selected_pg, $membership['ID'], $user->data->user_email );
		$data            = array(
			'membership'        => $membership_id,
			'subscription_id'   => $member_subscription['ID'],
			'member_id'         => $member_id,
			'email'             => $user->user_email,
			'transaction_id'    => $orders_data['orders_data']['transaction_id'],
			'upgrade'           => false,
			'subscription_data' => $member_subscription,
			'order_id'          => $order['ID'],
		);

		if ( ! empty( $switched_currency ) && ! empty( $switched_zone_id ) ) {
			$data['switched_currency'] = $switched_currency;
			$data['urm_zone_id']       = $switched_zone_id;
		}

		$renew_response           = $payment_service->build_response( $data );
		$renew_response['status'] = false;

		if ( isset( $renew_response['payment_url'] ) || isset( $renew_response['data'] ) || 'stripe' === $selected_pg ) {
			$renew_response['status'] = true;
			if ( $team_id ) {
				$this->orders_repository->update_order_meta(
					array(
						'order_id'   => $order['ID'],
						'meta_key'   => 'urm_team_id',
						'meta_value' => $team_id,
					)
				);
			}
		} else {
			$this->orders_repository->delete( $order['ID'] );
		}

		return array(
			'extra'    => array(
				'member_id'                => $member_id,
				'username'                 => $username,
				'transaction_id'           => $orders_data['orders_data']['transaction_id'],
				'updated_membership_title' => $membership['post_title'],
				'order_id'                 => $order['ID'],
			),
			'response' => $renew_response,
		);
	}

	/**
	 * update_membership_renewal_metas
	 *
	 * @param $member_id
	 * @param $membership_id
	 *
	 * @return void
	 */
	public function update_membership_renewal_metas( $member_id, $membership_id ) {
		update_user_meta( $member_id, 'urm_is_member_renewing', true );
	}

	public function update_subscription_data_for_renewal( $member_subscription, $membership_metas ) {

		$subscription_value    = $membership_metas['subscription']['value'];
		$subscription_duration = $membership_metas['subscription']['duration'];
		$next_billing_date     = new \DateTime( $member_subscription['next_billing_date'] );

		$today = new \DateTime( 'today' );
		if ( $next_billing_date < $today ) {
			$next_billing_date = $today;
		}

		$next_billing_date = $next_billing_date->modify( "+ $subscription_value $subscription_duration" )->format( 'Y-m-d 00:00:00' );

		$this->members_subscription_repository->update(
			$member_subscription['ID'],
			array(
				'start_date'        => date( 'Y-m-d 00:00:00' ),
				'next_billing_date' => $next_billing_date,
				'expiry_date'       => $next_billing_date,
			)
		);
		update_user_meta( $member_subscription['user_id'], 'urm_last_renewed_on', date( 'Y-m-d 00:00:00' ) );
		$membership_process = urm_get_membership_process( $member_subscription['user_id'] );

		if ( ! empty( $membership_process ) && in_array( $member_subscription['item_id'], $membership_process['renew'] ) ) {
			unset( $membership_process['renew'][ array_search( $member_subscription['item_id'], $membership_process['renew'], true ) ] );
			update_user_meta( $member_subscription['user_id'], 'urm_membership_process', $membership_process );

			do_action( 'user_registration_membership_renewed', $member_subscription['user_id'], $member_subscription['item_id'] );
		}
	}

	/**
	 * daily_membership_expiring_soon_check
	 *
	 * @return void
	 */
	public function daily_membership_expiring_soon_check() {
		$days_before_value = get_option( 'user_registration_membership_expiring_soon_days_before', 1 );

		if ( $days_before_value <= 0 ) {
			return;
		}
		$period        = get_option( 'user_registration_membership_expiring_soon_period', 'weeks' );
		$value_in_days = convert_to_days( $days_before_value, $period );
		$date          = new \DateTime( 'today' );
		$check_date    = $date->modify( "+$value_in_days day" )->format( 'Y-m-d H:i:s' );

		$subscriptions = $this->members_subscription_repository->get_about_to_expire_subscriptions( $check_date );
		if ( empty( $subscriptions ) ) {
			return;
		}
		$email_service = new EmailService();
		foreach ( $subscriptions as $subscription ) {

			$user_id = $subscription['member_id'];

			// Skip memberships already scheduled to cancel; no "expiring soon / renew" reminder for them.
			if ( get_user_meta( $user_id, 'urm_pending_cancel_' . ( $subscription['subscription_id'] ?? '' ), true ) ) {
				continue;
			}

			$checked_date = get_user_meta( $user_id, 'urm_expiring_reminder_sent_for_date', true );

			if ( $checked_date === $subscription['next_billing_date'] ) {
				continue;
			}
			$email_service->send_email( $subscription, 'membership_expiring_soon' );
			update_user_meta( $subscription['member_id'], 'urm_expiring_reminder_sent_for_date', $subscription['next_billing_date'] );
		}
	}

	/**
	 * daily_membership_expiring_soon_check
	 *
	 * @return void
	 */
	public function daily_membership_ended_check() {
		// Look back a window (not a single day) and dedupe per subscription, so a late status flip,
		// cron-order drift, or a missed cron day can't permanently skip the ended email.
		$lookback_days = (int) apply_filters( 'urm_ended_email_lookback_days', 7 );
		$check_date    = ( new \DateTime( "-{$lookback_days} days" ) )->format( 'Y-m-d H:i:s' );
		$subscriptions = $this->members_subscription_repository->get_expired_subscriptions( $check_date );

		if ( empty( $subscriptions ) ) {
			return;
		}
		$email_service = new EmailService();
		foreach ( $subscriptions as $subscription ) {
			$sent_key = 'urm_ended_email_sent_' . ( $subscription['subscription_id'] ?? '' );
			if ( get_user_meta( $subscription['member_id'], $sent_key, true ) ) {
				continue;
			}
			$email_service->send_email( $subscription, 'membership_ended' );
			update_user_meta( $subscription['member_id'], $sent_key, $subscription['expiry_date'] );
		}
	}

	/**
	 * daily_membership_expiration_check
	 * Check for memberships that have passed their expiry date and mark them as expired
	 *
	 * @return void
	 */
	public function daily_membership_expiration_check() {
		// Grace period gives the hourly missed-payment backfill time to catch a renewal
		$grace_hours   = (int) apply_filters( 'urm_expiry_grace_hours', 12 );
		$date          = new \DateTime( "-{$grace_hours} hours" );
		$check_date    = $date->format( 'Y-m-d H:i:s' );
		$subscriptions = $this->members_subscription_repository->get_subscriptions_to_expire( $check_date );
		if ( empty( $subscriptions ) ) {
			ur_get_logger()->notice(
				sprintf(
					__( 'No memberships found to expire for date: %s', 'user-registration' ),
					$check_date
				),
				array( 'source' => 'urm-membership-expiration' )
			);
			return;
		}

		$expired_count = 0;
		$expired_users = array();

		foreach ( $subscriptions as $subscription ) {
			$subscription_id = $subscription['subscription_id'];
			$user_id         = $subscription['member_id'];
			$membership_id   = isset( $subscription['membership'] ) ? absint( $subscription['membership'] ) : 0;
			$order           = $this->orders_repository->get_order_by_subscription( $subscription_id );

			if ( ( $order['order_type'] ?? '' ) !== 'subscription' ) {
				continue;
			}
			// If this was a pending-cancel PayPal subscription, now truly cancel it on PayPal's end.
			$pending_cancel_meta = get_user_meta( $user_id, 'urm_pending_cancel_' . $subscription_id, true );
			if ( $pending_cancel_meta && 'paypal' === ( $order['payment_method'] ?? '' ) && ! empty( $subscription['gateway_subscription_id'] ) ) {
				( new NewPaypalService() )->cancel_suspended_subscription( $subscription['gateway_subscription_id'] );
			}
			delete_user_meta( $user_id, 'urm_pending_cancel_' . $subscription_id );
			// A pending-cancel subscription reaching its date is a cancellation, not a natural expiry.
			$new_status    = $pending_cancel_meta ? 'canceled' : 'expired';
			$update_result = $this->members_subscription_repository->update( $subscription_id, array( 'status' => $new_status ) );

			if ( $update_result ) {
				++$expired_count;
				$expired_users[] = $subscription['username'];

				// Log the expiration
				ur_get_logger()->notice(
					sprintf(
						'[Member ID #%d] Membership expired for user',
						$user_id
					) . "\n" . wp_json_encode(
						array(
							'id'              => $user_id,
							'username'        => $subscription['username'],
							'subscription_id' => $subscription_id,
						),
						JSON_PRETTY_PRINT
					) . "\n ",
					array( 'source' => 'urm-membership-expiration' )
				);

				// Cancellation email already went out at cancel time; the membership_ended cron only targets 'expired', so a canceled sub gets no expiry email either.
				$payload = array(
					'subscription_id' => $subscription_id,
					'member_id'       => $user_id,
					'event_type'      => $pending_cancel_meta ? 'canceled' : 'expired',
					'meta'            => array(
						'membership_id' => $membership_id,
						'mode'          => 'expiry',
					),
				);

				do_action( 'ur_membership_subscription_event_triggered', $payload );
			} else {
				ur_get_logger()->error(
					sprintf(
						__( 'Failed to expire membership for user %1$s (ID: %2$d) - Subscription ID: %3$d', 'user-registration' ),
						$subscription['username'],
						$user_id,
						$subscription_id
					),
					array( 'source' => 'urm-membership-expiration' )
				);
			}
		}

		ur_get_logger()->notice(
			sprintf(
				'Membership expiration check completed. %1$d memberships expired for users:',
				$expired_count
			) . "\n" . wp_json_encode(
				$expired_users,
				JSON_PRETTY_PRINT
			) . "\n ",
			array( 'source' => 'urm-membership-expiration' )
		);
	}


	/**
	 * Callback for payment retry check.
	 */
	public function daily_payment_retry_check() {

		$subscriptions = $this->members_subscription_repository->get_subscriptions_to_retry();
		$expired_count = 0;
		$expired_users = array();
		foreach ( $subscriptions as $subscription ) {
			// only handle the subscription case.
			if ( $subscription['order_type'] !== 'subscription' ) {
				continue;
			}

			// Update subscription status to expired
			// $update_result = $this->members_subscription_repository->update( $subscription_id, array( 'status' => 'expired' ) );
			$this->failed_payment_retry_callback( $subscription );
		}
	}

	/**
	 * Payment retry callback for a failed attempt.
	 */
	public function failed_payment_retry_callback( $subscription ) {
		// update the counter for failed payment retry.
		$retry_count = (int) get_user_meta( $subscription['member_id'], 'urm_is_payment_retrying', true );
		update_user_meta( $subscription['member_id'], 'urm_is_payment_retrying', $retry_count + 1 );
		switch ( $subscription['payment_method'] ) {
			case 'paypal':
				$paypal_service = new NewPaypalService();
				$paypal_service->retry_subscription( $subscription );
				break;
			case 'stripe':
				$stripe_service = new StripeService();
				$stripe_service->retry_subscription( $subscription );
				break;
			default:
				do_action( 'urm_handle_failed_payment_retry', $subscription );
				break;
		}
	}

	/**
	 * Check if user membership expired.
	 *
	 * @param int $user_id User ID.
	 * @param int $subscription_id Subscription ID.
	 * @return boolean
	 */
	public function is_user_membership_expired( $user_id, $subscription_id ) {
		$subscription = $this->members_subscription_repository->retrieve( $subscription_id );

		if ( empty( $subscription ) || $subscription['user_id'] != $user_id ) {
			return false;
		}

		if ( $subscription['status'] === 'expired' ) {
			return true;
		}

		if ( empty( $subscription['expiry_date'] ) ) {
			return false;
		}

		if ( empty( $subscription['billing_cycle'] ) ) {
			return false;
		}

		try {
			$expiry_date = new \DateTime( $subscription['expiry_date'] );
		} catch ( \Exception $e ) {
			return false;
		}

		$today = new \DateTime( 'today' );

		return $expiry_date <= $today;
	}

	/**
	 * Callback for missed payment check.
	 */
	public function membership_missed_payment_check() {

		// Prevent concurrent runs: if another process is still executing, bail out.
		if ( get_transient( 'urm_backfill_running' ) ) {
			ur_get_logger()->info(
				'[Backfill] Skipped — another backfill process is already running.',
				array( 'source' => 'urm-missed-payment-backfill' )
			);
			return;
		}

		// Lock for up to 10 minutes — long enough to cover all API calls.
		set_transient( 'urm_backfill_running', true, 10 * MINUTE_IN_SECONDS );

		try {
			$last_synced = (int) get_option( 'urm_last_missed_payment_events_check_sync_time', 0 );
			$now         = time();

			if ( $last_synced <= 0 ) {
				$last_synced = $now - 3 * MONTH_IN_SECONDS; // fallback to 3 months back if no previous sync time found, to avoid missing old events.
			}

			$this->urm_backfill_missed_payment_events( $last_synced, $now );

			update_option( 'urm_last_missed_payment_events_check_sync_time', $now );
		} finally {
			delete_transient( 'urm_backfill_running' );
		}
	}

	/**
	 * Backfill missed payment events from payment providers.
	 *
	 * This method fetches payment provider events for subscription updates and payment successes that occurred between the last synced time and now. It then processes these events to update the local subscription records and create any missing payment records in the database.
	 *
	 * @param int      $last_synced The timestamp of the last successful sync, used to fetch events that occurred after this time.
	 * @param int|null $now The current timestamp, used to limit the events fetched. If null, it defaults to the current time.
	 * @return void
	 */
	public function urm_backfill_missed_payment_events( $last_synced, $now = null ) {
		if ( $now === null ) {
			$now = time();
		}

		$payment_gateways = get_option( 'ur_membership_payment_gateways', array() );

		if ( ! empty( $payment_gateways ) ) {
			foreach ( $payment_gateways as $gateway_key => $gateway_details ) {
				switch ( $gateway_key ) {
					case 'stripe':
						$stripe_settings = StripeService::get_stripe_settings();
						if ( empty( $stripe_settings['secret_key'] ) ) {
							ur_get_logger()->info(
								'[Backfill][Stripe] Skipped — Stripe secret key not configured.',
								array( 'source' => 'urm-missed-payment-backfill' )
							);
							break;
						}
						try {
							$stripe_service = new StripeService();
							$stripe_service->run_missed_subscription_backfill( $last_synced );
							$stripe_service->run_missed_payment_backfill( $last_synced );
							$stripe_service->run_missed_onetime_payment_backfill( $last_synced );
							$stripe_service->run_missed_refund_backfill( $last_synced );
						} catch ( \Exception $e ) {
							ur_get_logger()->error(
								sprintf(
									__( 'Error fetching missed events for Stripe: %s', 'user-registration' ),
									$e->getMessage()
								),
								array( 'source' => 'urm-missed-payment-backfill' )
							);
							break;
						}
						break;
					case 'paypal':
						try {
							$paypal_service = new NewPaypalService();
							$paypal_service->run_missed_subscription_backfill( $last_synced, $now );
							$paypal_service->run_missed_payment_backfill( $last_synced, $now );
							$paypal_service->run_missed_onetime_payment_backfill( $last_synced, $now );
							$paypal_service->run_missed_refund_backfill( $last_synced, $now );
						} catch ( \Exception $e ) {
							ur_get_logger()->error(
								sprintf(
									__( 'Error fetching missed events for PayPal: %s', 'user-registration' ),
									$e->getMessage()
								),
								array( 'source' => 'urm-missed-payment-backfill' )
							);
						}
						break;
					default:
						do_action( 'urm_fetch_and_process_missed_payment_events_for_gateway', $gateway_key, $last_synced, $now );
						break;
				}
			}
		}
	}
}
