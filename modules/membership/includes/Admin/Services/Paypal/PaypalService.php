<?php

namespace WPEverest\URMembership\Admin\Services\Paypal;

use DateTime;
use Exception;
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
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

class PaypalService {
	/**
	 * @var MembersOrderRepository
	 */
	protected $members_orders_repository, $members_subscription_repository, $membership_repository, $orders_repository, $subscription_repository;

	/**
	 * Constructor of this class.
	 */
	public function __construct() {
		$this->members_orders_repository       = new MembersOrderRepository();
		$this->members_subscription_repository = new MembersSubscriptionRepository();
		$this->membership_repository           = new MembershipRepository();
		$this->orders_repository               = new OrdersRepository();
		$this->subscription_repository         = new SubscriptionRepository();
	}

	/**
	 * Build url
	 *
	 * @param $data
	 * @param $membership
	 * @param $member_email
	 * @param $subscription_id
	 * @param $member_id
	 *
	 * @return array|string|string[]
	 */
	public function build_url( $data, $membership, $member_email, $subscription_id, $member_id, $response_data = array() ) {

		$is_upgrading                 = ! empty( $data['upgrade'] ) ? $data['upgrade'] : false;
		$paypal_options               = is_array( $data['payment_gateways']['paypal'] ) ? $data['payment_gateways']['paypal'] : array();
		$mode                         = get_option( 'user_registration_global_paypal_mode', 'test' ) == 'test' ? 'test' : 'production';
		$paypal_options['mode']       = $mode;
		$paypal_options['email']      = get_option( sprintf( 'user_registration_global_paypal_%s_email_address', $mode ), get_option( 'user_registration_global_paypal_email_address', $paypal_options['email'] ?? '' ) );
		$paypal_options['cancel_url'] = get_option( 'user_registration_global_paypal_cancel_url', home_url() );
		$paypal_options['return_url'] = get_option( 'user_registration_global_paypal_return_url', wp_login_url() );
		$redirect                     = ( 'production' === $paypal_options['mode'] ) ? 'https://www.paypal.com/cgi-bin/webscr/?' : 'https://www.sandbox.paypal.com/cgi-bin/webscr/?';
		$membership_data              = $this->membership_repository->get_single_membership_by_ID( $membership );
		$membership_metas             = wp_unslash( json_decode( $membership_data['meta_value'], true ) );
		if ( ! empty( $data['team_id'] ) && ! empty( $data['team_data'] ) ) {
			$membership_type = $data['team_data']['team_plan_type'] ?? 'unknown';
			if ( 'one-time' === $membership_type ) {
				$membership_type = 'paid';
			}
		} else {
			$membership_type = $membership_metas['type'] ?? 'unknown'; // free, paid, or subscription
		}

		PaymentGatewayLogging::log_transaction_start(
			'paypal',
			sprintf( ' [Member ID #%s] Building PayPal payment URL.', $member_id ) . "\n" . wp_json_encode(
				array(
					'member_id'       => $member_id,
					'membership_id'   => $membership,
					'member_email'    => $member_email,
					'subscription_id' => $subscription_id,
					'is_upgrading'    => $is_upgrading,
					'membership_type' => $membership_type,
				),
				JSON_PRETTY_PRINT
			)
		);

		$membership_amount = 0;
		if ( ! empty( $data['team_id'] ) && ! empty( $data['team_data'] ) ) {
			$team_data  = $data['team_data'];
			$seat_model = $team_data['seat_model'] ?? '';

			if ( 'fixed' === $seat_model ) {
				$membership_amount = (float) $team_data['team_price'];
			} else {
				$team_seats = absint( $team_data['team_seats'] ?? 0 );
				if ( $team_seats <= 0 ) {
					PaymentGatewayLogging::log_error(
						'paypal',
						'Payment stopped - Invalid team seats',
						array(
							'error_code' => 'INVALID_TEAM_SEATS',
							'amount'     => $membership_amount,
							'member_id'  => $member_id,
						)
					);
					if ( empty( $data['upgrade'] ) ) {
						wp_delete_user( absint( $member_id ) );
					}
					wp_send_json_error(
						array(
							'message' => __( 'Paypal Payment stopped, Invalid team seats.', 'user-registration' ),
						)
					);
				}
				$pricing_model = $team_data['pricing_model'] ?? '';
				if ( 'per_seat' === $pricing_model ) {
					$membership_amount = $team_seats * (float) $team_data['per_seat_price'];
				} else {
					$tier = $data['team_tier_info'] ?? '';
					if ( ! $tier ) {
						PaymentGatewayLogging::log_error(
							'paypal',
							'Payment stopped - Invalid pricing tier',
							array(
								'error_code' => 'INVALID_TIER',
								'amount'     => $membership_amount,
								'member_id'  => $member_id,
							)
						);
						if ( empty( $data['upgrade'] ) ) {
							wp_delete_user( absint( $member_id ) );
						}
						wp_send_json_error(
							array(
								'message' => __( 'Paypal Payment stopped, Invalid pricing tier.', 'user-registration' ),
							)
						);
					}
					$membership_amount = $team_seats * (float) $data['team_tier_info']['tier_per_seat_price'];
				}
			}
		} else {
			$membership_amount = (float) $membership_metas['amount'];
		}
		$is_automatic       = 'automatic' === get_option( 'user_registration_renewal_behaviour', 'automatic' );
		$discount_amount    = 0;
		$membership_process = urm_get_membership_process( $member_id );
		$is_renewing        = ! empty( $membership_process['renew'] ) && in_array( $data['current_membership_id'], $membership_process['renew'] );

		$base_membership_amount = $membership_amount;

		$local_currency         = ! empty( $response_data['switched_currency'] ) ? $response_data['switched_currency'] : '';
		$ur_zone_id             = ! empty( $response_data['urm_zone_id'] ) ? $response_data['urm_zone_id'] : '';
		$currency               = get_option( 'user_registration_payment_currency', 'USD' );
		$pricing_data           = null;
		$local_currency_data    = array();
		$local_currency_applies = false;

		if ( ! empty( $local_currency ) && ! empty( $ur_zone_id ) && ur_check_module_activation( 'local-currency' ) && UR_PRO_ACTIVE && class_exists( CoreFunctions::class ) ) {
			$currency            = $local_currency;
			$pricing_data        = CoreFunctions::ur_get_pricing_zone_by_id( $ur_zone_id );
			$local_currency_data = ! empty( $data['local_currency'] ) ? $data['local_currency'] : array();

			if ( ! empty( $local_currency_data ) && ur_string_to_bool( $local_currency_data['is_enable'] ) ) {
				$local_currency_applies = true;
				$membership_amount      = CoreFunctions::ur_get_amount_after_conversion( $membership_amount, $currency, $pricing_data, $local_currency_data, $ur_zone_id );
			}
		}

		$final_amount    = $membership_amount;
		$is_automatic    = 'automatic' === get_option( 'user_registration_renewal_behaviour', 'automatic' );
		$discount_amount = 0;

		if ( isset( $data['upgrade'] ) && $data['upgrade'] ) {
			// $data['amount'] is the prorated delta in the base currency — scale it by the plan's local/base ratio.
			$chargeable_amount_base = (float) $data['amount'];
			$final_amount           = ( $local_currency_applies && $base_membership_amount > 0 )
				? $membership_amount * ( $chargeable_amount_base / $base_membership_amount )
				: $chargeable_amount_base;
		} elseif ( isset( $data['coupon'] ) && ! empty( $data['coupon'] ) && ur_check_module_activation( 'coupon' ) ) {
			$coupon_service    = new CouponService();
			$coupon_validation = $coupon_service->validate(
				array(
					'coupon'        => $data['coupon'],
					'membership_id' => absint( $membership ),
				)
			);

			if ( $coupon_validation['status'] ) {
				$coupon_details  = ur_get_coupon_details( $data['coupon'] );
				$discount_amount = ( 'fixed' === $coupon_details['coupon_discount_type'] ) ? $coupon_details['coupon_discount'] : $membership_amount * $coupon_details['coupon_discount'] / 100;

				if ( $local_currency_applies && 'fixed' === $coupon_details['coupon_discount_type'] ) {
					$discounted_base  = max( 0, $base_membership_amount - $discount_amount );
					$discounted_local = CoreFunctions::ur_get_amount_after_conversion(
						$discounted_base,
						$currency,
						$pricing_data,
						$local_currency_data,
						$ur_zone_id,
						$base_membership_amount
					);
					$discount_amount = max( 0, $membership_amount - $discounted_local );
				}

				$final_amount = floatval( user_registration_sanitize_amount( $membership_amount ) - $discount_amount );
			}
		}

		if ( ( 'subscription' === ( $membership_type ) && ! $is_renewing ) || ( $is_automatic && $is_renewing ) ) {
			$transaction = '_xclick-subscriptions';
		} else {
			$transaction = '_xclick';
		}

		$paypal_verification_token = wp_generate_uuid4();
		update_user_meta( $member_id, 'urm_paypal_verification_token', $paypal_verification_token );
		$query_args = 'membership=' . absint( $membership ) . '&member_id=' . absint( $member_id ) . '&current_membership_id=' . absint( $data['current_membership_id'] ) . '&hash=' . wp_hash( $membership . ',' . $member_id . ',' . $paypal_verification_token );
		$return_url = $paypal_options['return_url'] ?? wp_login_url();
		$return_url = esc_url_raw(
			add_query_arg(
				array(
					'ur-membership-return' => base64_encode( $query_args ),
				),
				apply_filters( 'user_registration_paypal_return_url', $return_url, array() )
			)
		);

		$tax_rate   = 0.0;
		$tax_amount = 0.0;
		if ( ! empty( $response_data['tax_rate'] ) && ! empty( $response_data['tax_calculation_method'] ) && ur_string_to_bool( $response_data['tax_calculation_method'] ) ) {
			$tax_rate   = floatval( $response_data['tax_rate'] );
			$tax_amount = round( $final_amount * $tax_rate / 100, 2 );
			// $final_amount stays pre-tax; PayPal Standard adds tax via 'tax'/'tax_rate' params.
		}

		// Build item name with pricing information
		$item_name = $membership_data['post_title'];
		if ( 'subscription' === $membership_type && ! empty( $data['subscription'] ) ) {
			$subscription_value    = $data['subscription']['value'];
			$subscription_duration = $data['subscription']['duration'];
			$currency_symbol       = get_option( 'user_registration_payment_currency', 'USD' ) === 'USD' ? '$' : get_option( 'user_registration_payment_currency', 'USD' );
			$item_name            .= ' - ' . $currency_symbol . $final_amount . ' for ' . $subscription_value . ' ' . $subscription_duration;
		}

		// override with team subscription data
		if ( ! empty( $data['team_id'] ) && ! empty( $data['team_data'] ) && 'subscription' === $membership_type ) {
			$subscription_value    = $data['team_data']['team_duration_value'];
			$subscription_duration = $data['team_data']['team_duration_period'];
			$currency_symbol       = get_option( 'user_registration_payment_currency', 'USD' ) === 'USD' ? '$' : get_option( 'user_registration_payment_currency', 'USD' );
			$item_name            .= ' - ' . $currency_symbol . $final_amount . ' for ' . $subscription_value . ' ' . $subscription_duration;
		}

		$notify_url = apply_filters(
			'user_registration_paypal_membership_notify_url',
			add_query_arg( 'ur-membership-listener', 'IPN', home_url( 'index.php' ) )
		);
		PaymentGatewayLogging::log_general(
			'paypal',
			'PayPal notify_url configured',
			'info',
			array(
				'notify_url' => $notify_url,
				'member_id'  => $member_id,
			)
		);
		$paypal_args = array(
			'business'      => sanitize_email( $paypal_options['email'] ),
			'cancel_return' => $paypal_options['cancel_url'],
			'notify_url'    => $notify_url,
			'cbt'           => $membership_data['post_title'],
			'charset'       => get_bloginfo( 'charset' ),
			'cmd'           => $transaction,
			'currency_code' => $currency,
			'custom'        => $membership . '-' . $member_id . '-' . $data['current_membership_id'] . '-' . $subscription_id,
			'return'        => $return_url,
			'rm'            => '2',
			'tax'           => $tax_amount,
			'upload'        => '1',
			'sra'           => '1',
			'src'           => '1',
			'no_note'       => '1',
			'no_shipping'   => '1',
			'shipping'      => '0',
			'item_name'     => $item_name,
			'email'         => sanitize_email( $member_email ),
		);
		if ( '_xclick-subscriptions' === $transaction ) {
			if ( ! empty( $data['team_id'] ) && ! empty( $data['team_data'] ) ) {
				$paypal_args['t3'] = ! empty( $data ['team_data']['team_duration_period'] ) ? strtoupper( substr( $data['team_data']['team_duration_period'], 0, 1 ) ) : '';
				$paypal_args['p3'] = ! empty( $data ['team_data']['team_duration_value'] ) ? $data ['team_data']['team_duration_value'] : 1;
			} else {
				$paypal_args['t3'] = ! empty( $data ['subscription'] ) ? strtoupper( substr( $data['subscription']['duration'], 0, 1 ) ) : '';
				$paypal_args['p3'] = ! empty( $data ['subscription']['value'] ) ? $data ['subscription']['value'] : 1;
			}
			$paypal_args['a3'] = floatval( user_registration_sanitize_amount( $final_amount ) );
			if ( $tax_rate > 0 ) {
				$paypal_args['tax_rate'] = number_format( $tax_rate, 2, '.', '' );
				unset( $paypal_args['tax'] );
			}
			$new_subscription_data      = json_decode( get_user_meta( $member_id, 'urm_next_subscription_data', true ), true );
			$previous_subscription_data = json_decode( get_user_meta( $member_id, 'urm_previous_subscription_data', true ), true );

			if ( 'on' === $data['trial_status'] ) {

				$paypal_args['t1'] = ! empty( $data ['trial_data'] ) ? strtoupper( substr( $data['trial_data']['duration'], 0, 1 ) ) : '';
				$paypal_args['p1'] = ! empty( $data ['trial_data'] ) ? $data ['trial_data']['value'] : 1;

				if ( $is_upgrading && empty( $new_subscription_data['delayed_until'] ) && ! empty( $previous_subscription_data['trial_end_date'] ) ) {

					$date1             = new DateTime( $previous_subscription_data['trial_end_date'] );
					$date2             = new DateTime( 'today' );
					$interval          = $date1->diff( $date2 );
					$paypal_args['t1'] = 'D';
					$paypal_args['p1'] = $interval->days;
				}

				$paypal_args['a1'] = '0';
			}

			if ( ! empty( $coupon_details ) || ( $is_upgrading && ! empty( $new_subscription_data ) && ! empty( $new_subscription_data['delayed_until'] ) ) || ( $is_upgrading && $data['chargeable_amount'] < $final_amount ) ) {
				$amount = $is_upgrading ? user_registration_sanitize_amount( $data['amount'] ) : ( user_registration_sanitize_amount( $final_amount ) );

				if ( ! empty( $data['team_id'] ) && ! empty( $data['team_data'] ) ) {
					$paypal_args['t2'] = ! empty( $data ['team_data']['team_duration_period'] ) ? strtoupper( substr( $data['team_data']['team_duration_period'], 0, 1 ) ) : '';
					$paypal_args['p2'] = ! empty( $data ['team_data']['team_duration_value'] ) ? $data ['team_data']['team_duration_value'] : 1;
				} else {
					$paypal_args['t2'] = ! empty( $data ['subscription'] ) ? strtoupper( substr( $data['subscription']['duration'], 0, 1 ) ) : '';
					$paypal_args['p2'] = ! empty( $data ['subscription']['value'] ) ? $data ['subscription']['value'] : 1;
				}
				$paypal_args['a2'] = floatval( $amount );
			}
		} else {
			$paypal_args['amount'] = $final_amount;
		}

		$redirect .= http_build_query( $paypal_args );
		$final_url = str_replace( ' & amp;', ' & ', $redirect );

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			sprintf( ' [Member ID #%s] PayPal payment URL built successfully.', $member_id ) . "\n" . wp_json_encode(
				array(
					'final_amount'     => $final_amount,
					'transaction_type' => $transaction,
					'item_name'        => $item_name,
					'currency'         => get_option( 'user_registration_payment_currency', 'USD' ),
					'member_id'        => $member_id,
					'membership_id'    => $membership,
					'subscription_id'  => $subscription_id,
					'payment_mode'     => $paypal_options['mode'],
					'membership_type'  => $membership_type,
				),
				JSON_PRETTY_PRINT
			) . "\n "
		);

		return $final_url;
	}

	/**
	 * Handle paypal redirect response.
	 *
	 * @param $params
	 * @param $payer_id
	 *
	 * @return void
	 */
	public function handle_paypal_redirect_response( $params, $payer_id ) {
		parse_str( $params, $url_params );

		$membership_id = $url_params['membership'];
		$member_id     = $url_params['member_id'];

		$supplied_hash             = $url_params['hash'];
		$paypal_verification_token = get_user_meta( $member_id, 'urm_paypal_verification_token', true );
		$expected_hash             = wp_hash( $membership_id . ',' . $member_id . ',' . $paypal_verification_token );

		if ( ! hash_equals( $supplied_hash, $expected_hash ) ) {
			return;
		}
		delete_user_meta( $member_id, 'urm_paypal_verification_token' );

		$member_order = $this->members_orders_repository->get_member_orders( $member_id );

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'PayPal redirect callback received - awaiting IPN confirmation',
			array(
				'webhook_type' => 'redirect_callback',
				'payer_id'     => $payer_id,
				'member_id'    => $member_id,
				'order_status' => $member_order['status'] ?? 'unknown',
			)
		);

		// If IPN already fired and completed the order, redirect to thank-you immediately.
		if ( 'completed' === ( $member_order['status'] ?? '' ) ) {
			ur_membership_redirect_to_thank_you_page( $member_id, $member_order );
		}

		// Payment activation (order/subscription status, email, upgrade) is handled
		// exclusively by the IPN handler which verifies the transaction with PayPal.
		// The redirect callback only provides UX — it must not activate membership on its own.
		$login_option = ur_get_user_login_option( $member_id );
		$data         = apply_filters( 'user_registration_membership_before_register_member', isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array() );

		if ( 'auto_login' === $login_option ) {
			$member_service = new MembersService();
			$password       = isset( $data['password'] ) ? $data['password'] : '';
			$member_service->login_member( $member_id, true, $password );
		}
		delete_transient( 'urm_pending_login_' . $member_id );
		ur_membership_redirect_to_thank_you_page( $member_id, $member_order );
	}

	/**
	 * handle_upgrade_for_paypal
	 *
	 * @param $member_id
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
			'Handling PayPal membership upgrade',
			'notice',
			array(
				'event_type'          => 'upgrade_processing',
				'member_id'           => $member_id,
				'old_subscription_id' => $get_user_old_subscription['ID'] ?? 'unknown',
				'new_subscription_id' => $subscription_id,
				'has_delayed_start'   => ! empty( $new_subscription_data['delayed_until'] ),
			)
		);

		if ( ! empty( $new_subscription_data ) ) {
			if ( empty( $new_subscription_data['delayed_until'] ) ) {
				PaymentGatewayLogging::log_general(
					'paypal',
					'Cancelling previous PayPal subscription',
					'notice',
					array(
						'event_type'          => 'upgrade_cancel_previous',
						'member_id'           => $member_id,
						'old_subscription_id' => $get_user_old_subscription['subscription_id'] ?? 'unknown',
					)
				);

				$cancel_subscription = $this->cancel_subscription( $get_user_old_order, $get_user_old_subscription );

				if ( ! empty( $cancel_subscription['status'] ) && $cancel_subscription['status'] ) {
					PaymentGatewayLogging::log_general(
						'paypal',
						'Previous subscription cancelled successfully',
						'success',
						array(
							'event_type'          => 'upgrade_cancel_success',
							'member_id'           => $member_id,
							'old_subscription_id' => $get_user_old_subscription['subscription_id'] ?? 'unknown',
						)
					);
				} else {
					$message = ! empty( $cancel_subscription['message'] ) ? $cancel_subscription['message'] : __( 'Paypal subscription cancellation failed', 'user-registration' );
					PaymentGatewayLogging::log_error(
						'paypal',
						'Previous subscription cancellation failed',
						array(
							'error_code'          => 'UPGRADE_CANCEL_FAILED',
							'error_message'       => $message,
							'member_id'           => $member_id,
							'old_subscription_id' => $get_user_old_subscription['subscription_id'] ?? 'unknown',
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

			PaymentGatewayLogging::log_general(
				'paypal',
				'New subscription activated after upgrade',
				'success',
				array(
					'event_type'          => 'upgrade_new_activated',
					'member_id'           => $member_id,
					'new_subscription_id' => $subscription_id,
					'new_membership_id'   => $new_subscription_data['membership'] ?? 'unknown',
				)
			);
		}

		$membership_process = urm_get_membership_process( $member_id );
		if ( ! empty( $membership_process ) && isset( $membership_process['upgrade'][ $get_user_old_subscription['item_id'] ] ) ) {
			unset( $membership_process['upgrade'][ $get_user_old_subscription['item_id'] ] );
			update_user_meta( $member_id, 'urm_membership_process', $membership_process );
		}

		update_user_meta( $member_id, 'urm_is_user_upgraded', 1 );

		PaymentGatewayLogging::log_transaction_success(
			'paypal',
			'Membership upgrade completed successfully',
			array(
				'event_type'          => 'upgrade_completed',
				'member_id'           => $member_id,
				'new_subscription_id' => $subscription_id,
			)
		);
	}

	/**
	 * Handle membership paypal ipn
	 *
	 * @param $data
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function handle_membership_paypal_ipn( $data ) {

		// Check if $post_data_array has been populated.
		if ( ! is_array( $data ) || empty( $data ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Empty or invalid IPN data received',
				array(
					'error_code' => 'EMPTY_IPN_DATA',
					'raw_data'   => $data,
				)
			);
			return;
		}

		PaymentGatewayLogging::log_webhook_received(
			'paypal',
			'PayPal IPN callback received',
			array(
				'webhook_type'   => $data['txn_type'] ?? 'unknown',
				'txn_id'         => $data['txn_id'] ?? 'N/A',
				'payment_status' => $data['payment_status'] ?? 'N/A',
				'payer_email'    => $data['payer_email'] ?? 'N/A',
				'mc_gross'       => $data['mc_gross'] ?? 'N/A',
				'mc_currency'    => $data['mc_currency'] ?? 'N/A',
				'custom'         => $data['custom'] ?? 'N/A',
				'raw_data'       => json_encode( $data ),
			)
		);

		$txn_type = $data['txn_type'];
		if ( ! isset( $data['custom'] ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Custom parameter not found in IPN data',
				array(
					'error_code' => 'MISSING_CUSTOM_PARAM',
					'txn_id'     => $data['txn_id'] ?? 'N/A',
					'txn_type'   => $txn_type,
				)
			);
			return;
		}

		$custom                = explode( '-', $data['custom'] );
		$membership_id         = $custom[0];
		$member_id             = $custom[1];
		$current_membership_id = $custom[2];
		$subscription_id       = $custom[3];
		$latest_order          = $this->members_orders_repository->get_member_orders( $member_id );
		$membership            = $this->membership_repository->get_single_membership_by_ID( $membership_id );
		$membership_metas      = wp_unslash( json_decode( $membership['meta_value'], true ) );
		$membership_type       = $membership_metas['type'] ?? 'unknown'; // free, paid, or subscription
		$paypal_options        = is_array( $membership_metas['payment_gateways']['paypal'] ) ? $membership_metas['payment_gateways']['paypal'] : array();

		$mode                         = get_option( 'user_registration_global_paypal_mode', 'test' ) === 'test' ? 'test' : 'production';
		$paypal_options['mode']       = $mode;
		$paypal_options['email']      = get_option( sprintf( 'user_registration_global_paypal_%s_email_address', $mode ), get_option( 'user_registration_global_paypal_email_address', $paypal_options['email'] ?? '' ) );
		$paypal_options['cancel_url'] = get_option( 'user_registration_global_paypal_cancel_url', home_url() );
		$paypal_options['return_url'] = get_option( 'user_registration_global_paypal_return_url', wp_login_url() );

		$membership_metas['post_title'] = $membership['post_title'];
		$receiver_email                 = $paypal_options['email'];
		$amount                         = $membership_metas['amount'];
		$payment_mode                   = $paypal_options['mode'];
		$subscription                   = $this->members_subscription_repository->get_subscription_data_by_subscription_id( $subscription_id );

		// initialize email service.
		$email_service = new EmailService();
		$email_data    = array(
			'subscription'     => $subscription,
			'order'            => $latest_order,
			'membership_metas' => $membership_metas,
			'member_id'        => absint( $member_id ),
			'membership'       => $membership_id,
		);

		if ( ! $this->validate_ipn( $payment_mode ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'IPN validation failed - Invalid response from PayPal',
				array(
					'error_code'    => 'IPN_VALIDATION_FAILED',
					'txn_id'        => $data['txn_id'] ?? 'N/A',
					'txn_type'      => $txn_type,
					'payment_mode'  => $payment_mode,
					'member_id'     => $member_id,
					'membership_id' => $membership_id,
				)
			);
			return;
		}

		PaymentGatewayLogging::log_payment_validation(
			'paypal',
			'IPN validation successful',
			array(
				'validation_result' => 'valid',
				'validation_method' => 'paypal_ipn',
				'txn_id'            => $data['txn_id'] ?? 'N/A',
				'member_id'         => $member_id,
				'membership_id'     => $membership_id,
				'membership_type'   => $membership_type,
			)
		);

		if ( empty( $subscription ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Subscription not found in database',
				array(
					'error_code'      => 'SUBSCRIPTION_NOT_FOUND',
					'subscription_id' => $subscription_id,
					'member_id'       => $member_id,
					'txn_id'          => $data['txn_id'] ?? 'N/A',
				)
			);
			return;
		}

		// Bind the client-supplied custom field to server-created state before any activation.
		$order_bound = ! empty( $latest_order )
			&& (int) $latest_order['user_id'] === (int) $member_id
			&& (int) $latest_order['subscription_id'] === (int) $subscription_id;
		$item_bound  = (int) ( $subscription['item_id'] ?? 0 ) === (int) $membership_id;

		if ( ! $order_bound || ! $item_bound ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'IPN custom field does not match server order/subscription',
				array(
					'error_code'      => 'IPN_CUSTOM_MISMATCH',
					'member_id'       => $member_id,
					'membership_id'   => $membership_id,
					'subscription_id' => $subscription_id,
					'txn_id'          => $data['txn_id'] ?? 'N/A',
				)
			);
			return;
		}

		if ( 'subscr_cancel' === $txn_type ) { // handle cancel ipn.
			PaymentGatewayLogging::log_subscription_cancellation(
				'paypal',
				'Subscription cancellation IPN received from PayPal',
				array(
					'subscription_id'     => $subscription_id,
					'member_id'           => $member_id,
					'cancellation_reason' => 'paypal_ipn',
					'txn_id'              => $data['txn_id'] ?? 'N/A',
					'subscr_id'           => $data['subscr_id'] ?? 'N/A',
				)
			);
			// updating the status to cancel it for sure
			$is_updated = $this->members_subscription_repository->update(
				$subscription_id,
				array(
					'status' => 'canceled',
				)
			);

			if ( $is_updated ) {
				PaymentGatewayLogging::log_general(
					'paypal',
					'Subscription status changed to canceled',
					'notice',
					array(
						'event_type'      => 'status_change',
						'old_status'      => $subscription['status'] ?? 'unknown',
						'new_status'      => 'canceled',
						'subscription_id' => $subscription_id,
						'member_id'       => $member_id,
					)
				);
			}

			return;
		}
		// return if first ipn received, change the status of order and subscription to complete and active respectively.
		if ( 'subscr_signup' === $txn_type || 'web_accept' === $txn_type ) {

			PaymentGatewayLogging::log_webhook_processed(
				'paypal',
				'Processing subscription signup/payment IPN',
				array(
					'webhook_type'      => $txn_type,
					'processing_result' => 'started',
					'member_id'         => $member_id,
					'subscription_id'   => $subscription_id,
					'order_id'          => $latest_order['ID'],
					'txn_id'            => $data['txn_id'] ?? 'N/A',
					'membership_type'   => $membership_type,
				)
			);

			// Validate receiver email and payment amount before any state changes.
			$ipn_error = '';
			if ( empty( $receiver_email ) || ! is_email( $receiver_email )
				|| strtolower( $data['business'] ?? '' ) !== strtolower( trim( $receiver_email ) ) ) {
				$ipn_error = esc_html__( 'Payment failed: recipient emails do not match', 'user-registration' );
			} elseif ( 'web_accept' === $txn_type
				&& ( empty( $latest_order['total_amount'] )
					|| number_format( (float) ( $data['mc_gross'] ?? 0 ), 2 ) !== number_format( (float) $latest_order['total_amount'], 2 ) ) ) {
				// Amount check applies only to web_accept (one-time payment), against the
				// server-stored order total. subscr_signup carries no payment — the first
				// real charge arrives via subscr_payment.
				$ipn_error = esc_html__( 'Payment failed: payment amounts do not match ', 'user-registration' );
			}

			if ( ! empty( $ipn_error ) ) {
				PaymentGatewayLogging::log_transaction_failure(
					'paypal',
					$ipn_error,
					array(
						'subscription_id' => $subscription_id,
						'member_id'       => $member_id,
					)
				);
				$this->members_orders_repository->update( $latest_order['ID'], array( 'status' => 'failed' ) );
				return;
			}

			$this->members_orders_repository->update(
				$latest_order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => sanitize_text_field( $data['txn_id'] ?? '' ),
				)
			);

			PaymentGatewayLogging::log_general(
				'paypal',
				'Order status changed to completed',
				'success',
				array(
					'event_type' => 'status_change',
					'old_status' => $latest_order['status'] ?? 'unknown',
					'new_status' => 'completed',
					'order_id'   => $latest_order['ID'],
					'member_id'  => $member_id,
					'amount'     => $data['mc_gross'] ?? $amount,
				)
			);

			if ( 'paid' === $membership_metas['type'] ) {
				$this->members_subscription_repository->update(
					$subscription_id,
					array(
						'status'     => 'active',
						'start_date' => date( 'Y-m-d 00:00:00' ),
					)
				);

				PaymentGatewayLogging::log_general(
					'paypal',
					'Subscription status changed to active',
					'success',
					array(
						'event_type'      => 'status_change',
						'old_status'      => $subscription['status'] ?? 'unknown',
						'new_status'      => 'active',
						'subscription_id' => $subscription_id,
						'member_id'       => $member_id,
						'membership_type' => 'paid',
					)
				);
			} else {
				$this->members_subscription_repository->update(
					$subscription_id,
					array(
						'subscription_id' => sanitize_text_field( $data['subscr_id'] ),
						'start_date'      => date( 'Y-m-d 00:00:00' ),
					)
				);

				PaymentGatewayLogging::log_general(
					'paypal',
					'Subscription updated with PayPal subscription ID',
					'info',
					array(
						'event_type'       => 'subscription_update',
						'subscription_id'  => $subscription_id,
						'paypal_subscr_id' => $data['subscr_id'] ?? 'N/A',
						'member_id'        => $member_id,
					)
				);
			}
			$order_detail = $this->orders_repository->get_order_detail( $latest_order['ID'] );
			if ( ! empty( $order_detail['coupon'] ) ) {
				$order_detail['coupon_discount']      = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount', true );
				$order_detail['coupon_discount_type'] = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount_type', true );

			}
			$email_data ['order'] = $order_detail;
			$email_service->send_email( $email_data, 'payment_successful' );

			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				'Subscriber signup IPN successful',
				array(
					'member_id'       => $member_id,
					'subscription_id' => $subscription_id,
				)
			);

			$email_service->send_email( $email_data, 'payment_successful_admin' );

			$reg_data = get_user_meta( $member_id, 'ur_membership_registration_data', true );
			if ( ! empty( $reg_data ) && empty( get_user_meta( $member_id, 'ur_membership_welcome_email_sent', true ) ) ) {
				do_action( 'urm_member_registered', $reg_data, $member_id );
				update_user_meta( $member_id, 'ur_membership_welcome_email_sent', true );
			}

			return;
		}
		$payment_date = \DateTime::createFromFormat( 'H:i:s M d, Y T', $data['payment_date'] ?? date( 'Y-m-d' ), new \DateTimeZone( 'PDT' ) );

		$payment_date = $payment_date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
		// handle first ipn of the day appart from the signup ipn.
		if ( 'subscr_payment' === $txn_type && $payment_date == date( 'Y-m-d' ) ) {
			// Verify receiver email and amount before activating — this path must not
			// skip the checks that run below for later payments.
			if ( empty( $receiver_email ) || ! is_email( $receiver_email )
				|| strtolower( $data['business'] ?? '' ) !== strtolower( trim( $receiver_email ) )
				|| empty( $latest_order['total_amount'] )
				|| number_format( (float) ( $data['mc_gross'] ?? 0 ), 2 ) !== number_format( (float) $latest_order['total_amount'], 2 ) ) {
				PaymentGatewayLogging::log_transaction_failure(
					'paypal',
					esc_html__( 'Payment failed: receiver email or amount mismatch', 'user-registration' ),
					array(
						'subscription_id' => $subscription_id,
						'member_id'       => $member_id,
					)
				);
				$this->members_orders_repository->update( $latest_order['ID'], array( 'status' => 'failed' ) );

				return;
			}
			$this->members_subscription_repository->update(
				$subscription_id,
				array(
					'status'     => 'active',
					'start_date' => date( 'Y-m-d 00:00:00' ),
				)
			);
			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				'Subscriber first IPN successful',
				array(
					'member_id'       => $member_id,
					'subscription_id' => $subscription_id,
				)
			);

			return;
		}
		$payment_status = strtolower( $data['payment_status'] );
		$transaction_id = '';
		$order_id       = null;

		// Verify receiver's email address.
		if ( empty( $receiver_email ) || ! is_email( $receiver_email ) || strtolower( $data['business'] ) !== strtolower( trim( $receiver_email ) ) ) {
			$error = esc_html__( 'Payment failed: recipient emails do not match', 'user-registration' );
		} elseif ( empty( $latest_order['total_amount'] ) || number_format( (float) ( $data['mc_gross'] ?? 0 ), 2 ) !== number_format( (float) $latest_order['total_amount'], 2 ) ) {
			// Verify amount against the server-stored order total, not the client-influenced base amount.
			$error = esc_html__( 'Payment failed: payment amounts do not match ', 'user-registration' );
		}

		if ( ! empty( $error ) ) {
			PaymentGatewayLogging::log_transaction_failure(
				'paypal',
				$error,
				array(
					'subscription_id' => $subscription_id,
					'member_id'       => $member_id,
				)
			);
			$this->members_orders_repository->update( $latest_order['ID'], array( 'status' => 'failed' ) );

			return;
		}

		if ( 'subscr_payment' == $txn_type && $payment_date > date( 'Y-m-d' ) ) { // only create new order if ipn comes after the payment date since min subscription period is of a day.

			// create new order for ipn
			$order_info                                   = array(
				'membership_data' => array(
					'membership'     => $membership_id,
					'payment_method' => 'paypal',
				),
			);
			$order_service                                = new OrderService();
			$order_repository                             = new OrdersRepository();
			$order_data                                   = $order_service->prepare_orders_data( $order_info, $member_id, $subscription );
			$order_data['orders_data']['status']          = $payment_status;
			$order_data['orders_data']['total_amount']    = $membership_metas['amount'];
			$transaction_id                               = $data['txn_id'];
			$order_data['orders_data']['transaction_id']  = $transaction_id;
			$order_data['orders_data']['subscription_id'] = $subscription_id;

			$order_id = $order_repository->create( $order_data );
			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				'New order created',
				array(
					'order_id'  => $order_id,
					'member_id' => $member_id,
				)
			);

			if ( $order_id ) {
				// update subscription
				if ( isset( $data['next_payment_date'] ) ) {
					$start_date        = new \DateTime( $data['next_payment_date'] );
					$next_billing_date = $start_date->format( 'Y-m-d' );
				} else {
					$next_billing_date = SubscriptionService::get_expiry_date( date( 'Y-m-d' ), $membership_metas['subscription']['duration'], $membership_metas['subscription']['value'] );
				}
				$this->members_subscription_repository->update(
					$subscription_id,
					array(
						'start_date'        => date( 'Y-m-d 00:00:00' ),
						'status'            => 'active',
						'next_billing_date' => $next_billing_date,
						'subscription_id'   => sanitize_text_field( $data['subscr_id'] ),
					)
				);
			}
		} elseif ( 'subscr_eot' == $txn_type ) {
			// Verify further if eot is ever received for time specified subscriptions
		}
		if ( 'completed' === $payment_status && ! empty( $order_id ) ) {
			PaymentGatewayLogging::log_transaction_success(
				'paypal',
				'Payment completed successfully',
				array(
					'subscription_id' => $subscription_id,
					'member_id'       => $member_id,
				)
			);
			$this->members_orders_repository->update(
				$latest_order['ID'],
				array(
					'status'         => 'completed',
					'transaction_id' => $transaction_id,
				)
			);
		}

		$membership_process = urm_get_membership_process( $member_id );
		$is_upgrading       = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $current_membership_id ] );

		if ( $is_upgrading ) {
			PaymentGatewayLogging::log_general(
				'paypal',
				'Processing membership upgrade for recurring payment',
				'notice',
				array(
					'event_type'      => 'upgrade_initiated',
					'member_id'       => $member_id,
					'subscription_id' => $subscription_id,
					'payment_type'    => 'recurring',
				)
			);

			$this->handle_upgrade_for_paypal( $member_id, $subscription_id );
		}

		$is_renewing = ! empty( $membership_process['renew'] ) && in_array( $membership_id, $membership_process['renew'] );

		// Read before the renewal below clears the counter.
		$retry_attempts = intval( get_user_meta( $member_id, 'urm_is_payment_retrying', true ) );

		// A failed IPN must not roll the billing dates forward or clear the retry counter.
		if ( $is_renewing && 'completed' === $payment_status ) {
			$subscription_service = new SubscriptionService();
			$subscription_service->update_subscription_data_for_renewal( $subscription, $membership_metas );
		}

		// urm_is_payment_retrying is a counter, not a one-shot flag - notify on every failed attempt, never after a completed payment.
		if ( 1 <= $retry_attempts && 'completed' !== $payment_status ) {
			$email_service = new EmailService();
			$email_data    = array(
				'subscription'     => $subscription,
				'order'            => $latest_order,
				'membership_metas' => $membership_metas,
				'member_id'        => $member_id,
			);
			$email_service->send_email( $email_data, 'payment_retry_failed' );
		}
		delete_transient( 'urm_pending_login_' . $member_id );
	}

	/**
	 * Login to paypal
	 *
	 * @param $url
	 * @param $client_id
	 * @param $client_secret
	 *
	 * @return array|void
	 */
	public static function login_paypal( $url, $client_id, $client_secret ) {
		$url .= 'v1/oauth2/token';
		try {
			$ch = curl_init();

			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials' );
			curl_setopt( $ch, CURLOPT_POST, true );

			$response    = curl_exec( $ch );
			$result      = json_decode( $response );
			$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			curl_close( $ch );

			return array(
				'access_token' => isset( $result->access_token ) ? $result->access_token : null,
				'status_code'  => $status_code,
			);
		} catch ( \Exception $e ) {

			ur_get_logger()->debug( $e->getMessage() );
		}
	}

	/**
	 * Cancel subscription
	 *
	 * @param $order
	 * @param $subscription
	 *
	 * @return array|bool[]
	 */
	public function cancel_subscription( $order, $subscription ) {
		$paypal_options['mode']          = get_option( 'user_registration_global_paypal_mode', 'test' ) == 'test' ? 'test' : 'live';
		$paypal_options['client_id']     = get_option( sprintf( 'user_registration_global_paypal_%s_client_id', $paypal_options['mode'] ), get_option( 'user_registration_global_paypal_client_id', '' ) );
		$paypal_options['client_secret'] = get_option( sprintf( 'user_registration_global_paypal_%s_client_secret', $paypal_options['mode'] ), get_option( 'user_registration_global_paypal_client_secret', '' ) );

		$client_id     = $paypal_options['client_id'];
		$client_secret = $paypal_options['client_secret'];
		$url           = ( 'production' === $paypal_options['mode'] ) ? 'https://api-m.paypal.com/' : 'https://api-m.sandbox.paypal.com/';
		$login_request = self::login_paypal( $url, $client_id, $client_secret );
		if ( 200 !== $login_request['status_code'] ) {
			$message = esc_html__( 'Invalid response from paypal, check Client ID or Secret.', 'user-registration' );
			PaymentGatewayLogging::log_transaction_failure( 'paypal', $message );

			return array(
				'status'  => false,
				'message' => $message,
			);
		}

		if ( empty( $subscription['subscription_id'] ) ) {
			$message = esc_html__( 'Paypal Subscription ID not present, please contact your administrator.', 'user-registration' );
			PaymentGatewayLogging::log_transaction_failure( 'paypal', $message );

			return array(
				'status'  => false,
				'message' => $message,
			);
		}
		$url .= sprintf( 'v1/billing/subscriptions/%s/suspend', $subscription['subscription_id'] );

		$bearerToken = $login_request['access_token']; // Replace with your actual Bearer token

		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $bearerToken,
		);
		$data    = json_encode(
			array(
				'reason' => 'User initiated cancellation',
			)
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $ch );
		$result   = json_decode( $response );

		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			PaymentGatewayLogging::log_transaction_failure( 'paypal', 'cURL error: ' . curl_error( $ch ) );
		}
		curl_close( $ch );

		PaymentGatewayLogging::log_api_response(
			'paypal',
			'PayPal cancellation API response',
			array(
				'status_code' => $status_code,
				'response'    => $response,
			)
		);

		if ( 204 === $status_code ) {
			$message = esc_html__( 'Subscription successfully canceled from paypal.', 'user-registration' );
			PaymentGatewayLogging::log_general(
				'paypal',
				$message,
				'success',
				array(
					'event_type'      => 'cancellation_success',
					'subscription_id' => $subscription['subscription_id'] ?? 'unknown',
					'status_code'     => $status_code,
				)
			);

			return array(
				'status' => true,
			);
		}
		$message = esc_html__( 'Subscription cancellation failed from Paypal.', 'user-registration' );
		PaymentGatewayLogging::log_transaction_failure( 'paypal', $message, array( 'response' => $response ) );

		return array(
			'status'  => false,
			'message' => $message,
		);
	}
	/**
	 * Reactivates already cancelled subscription.
	 *
	 * @param $subscription_id Subscription Id.
	 */
	public function reactivate_subscription( $subscription_id ) {
		$paypal_options['mode']          = get_option( 'user_registration_global_paypal_mode', 'test' ) == 'test' ? 'test' : 'live';
		$paypal_options['client_id']     = get_option( sprintf( 'user_registration_global_paypal_%s_client_id', $paypal_options['mode'] ), get_option( 'user_registration_global_paypal_client_id', '' ) );
		$paypal_options['client_secret'] = get_option( sprintf( 'user_registration_global_paypal_%s_client_secret', $paypal_options['mode'] ), get_option( 'user_registration_global_paypal_client_secret', '' ) );
		$client_id                       = $paypal_options['client_id'];
		$client_secret                   = $paypal_options['client_secret'];
		$url                             = ( 'production' === $paypal_options['mode'] ) ? 'https://api-m.paypal.com/' : 'https://api-m.sandbox.paypal.com/';

		$login_request = self::login_paypal( $url, $client_id, $client_secret );
		if ( 200 !== $login_request['status_code'] ) {
			$message = esc_html__( 'Invalid response from paypal, check Client ID or Secret.', 'user-registration' );
			PaymentGatewayLogging::log_transaction_failure( 'paypal', $message );

			return array(
				'status'  => false,
				'message' => $message,
			);
		}
		$url .= sprintf( 'v1/billing/subscriptions/%s/activate', $subscription_id );

		$bearerToken = $login_request['access_token'];

		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $bearerToken,
		);
		$data    = json_encode(
			array(
				'reason' => 'User initiated reactivation',
			)
		);
		$ch      = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $ch );
		$result   = json_decode( $response );

		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			PaymentGatewayLogging::log_transaction_failure( 'paypal', 'cURL error: ' . curl_error( $ch ) );
		}
		curl_close( $ch );

		PaymentGatewayLogging::log_api_response(
			'paypal',
			'PayPal reactivation API response',
			array(
				'status_code' => $status_code,
				'response'    => $response,
			)
		);

		if ( 204 === $status_code ) {
			$message = esc_html__( 'Subscription successfully reactivated from paypal.', 'user-registration' );
			PaymentGatewayLogging::log_subscription_reactivation( 'paypal', $message );

			return array(
				'status' => true,
			);
		}
		$message = esc_html__( 'Subscription reactivation failed from Paypal.', 'user-registration' );
		PaymentGatewayLogging::log_transaction_failure( 'paypal', $message );

		return array(
			'status'  => false,
			'message' => $message,
		);
	}

	/**
	 * validate_ipn
	 *
	 * @param $payment_mode
	 *
	 * @return bool
	 */
	public function validate_ipn( $payment_mode ) {
		PaymentGatewayLogging::log_general( 'paypal', 'Checking IPN response validity', 'info' );
		// Get received values from post data.
		$validate_ipn        = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$validate_ipn['cmd'] = '_notify-validate';
		// Send back post vars to paypal.
		$params = array(
			'body'        => $validate_ipn,
			'timeout'     => 60,
			'httpversion' => '1.1',
			'compress'    => false,
			'decompress'  => false,
			'user-agent'  => 'User Registration IPN Verification',
		);

		$remote_post_url = ( ! empty( $payment_mode ) && in_array( $payment_mode, array( 'production', 'live' ), true ) ) ? 'https://ipnpb.paypal.com/cgi-bin/webscr' : 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
		// Post back to get a response.
		$response = wp_safe_remote_post( $remote_post_url, $params );

		PaymentGatewayLogging::log_api_response(
			'paypal',
			'IPN validation response received',
			array(
				'status_code'   => $response['response']['code'] ?? 0,
				'response_body' => $response['body'] ?? '',
			)
		);

		// Check to see if the request was valid.
		if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && strstr( $response['body'], 'VERIFIED' ) ) {
			PaymentGatewayLogging::log_general( 'paypal', 'Received valid response from PayPal IPN', 'info' );
			return true;
		}

		PaymentGatewayLogging::log_transaction_failure(
			'paypal',
			'Received invalid response from PayPal IPN',
			array(
				'status_code' => $response['response']['code'] ?? 0,
				'error'       => is_wp_error( $response ) ? $response->get_error_message() : 'Invalid response',
			)
		);

		return false;
	}

	public function validate_setup( $membership_type ) {
		$paypal_enabled        = get_option( 'user_registration_paypal_enabled', '' );
		$paypal_toggle_default = ur_string_to_bool( get_option( 'urm_is_new_installation', false ) );
		$has_user_changed      = ur_string_to_bool( get_option( 'urm_paypal_updated_connection_status', false ) );
		$is_paypal_enabled     = ( $paypal_enabled ) ? $paypal_enabled : ( $has_user_changed ? $paypal_enabled : ! $paypal_toggle_default );

		if ( ! $is_paypal_enabled ) {
			return true;
		}
		if ( 'free' === $membership_type ) {
			return false;
		}

		$mode          = get_option( 'user_registration_global_paypal_mode', 'test' ) == 'test' ? 'test' : 'live';
		$email         = get_option( sprintf( 'user_registration_global_paypal_%s_email_address', $mode ), get_option( 'user_registration_global_paypal_email_address' ) );
		$client_id     = get_option( sprintf( 'user_registration_global_paypal_%s_client_id', $mode ), get_option( 'user_registration_global_paypal_client_id' ) );
		$client_secret = get_option( sprintf( 'user_registration_global_paypal_%s_client_secret', $mode ), get_option( 'user_registration_global_paypal_client_secret' ) );

		$has_rest_credentials = ! empty( $client_id ) && ! empty( $client_secret );

		// Subscription and one-time (paid): valid with REST credentials OR legacy email.
		return ! $has_rest_credentials && empty( $email );
	}

	public function retry_subscription( $subscription ) {
		$response = array(
			'status'  => false,
			'message' => '',
		);

		// Get PayPal configuration
		$paypal_options['mode']          = get_option( 'user_registration_global_paypal_mode', 'test' );
		$paypal_options['client_id']     = get_option( 'user_registration_global_paypal_client_id', '' );
		$paypal_options['client_secret'] = get_option( 'user_registration_global_paypal_client_secret', '' );

		$client_id     = $paypal_options['client_id'];
		$client_secret = $paypal_options['client_secret'];
		$url           = ( 'production' === $paypal_options['mode'] ) ? 'https://api-m.paypal.com/' : 'https://api-m.sandbox.paypal.com/';

		// Validate subscription ID
		if ( empty( $subscription['sub_id'] ) ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'PayPal subscription ID not found for retry',
				array(
					'error_code' => 'MISSING_SUBSCRIPTION_ID',
					'user_id'    => $subscription['user_id'] ?? 'unknown',
				)
			);

			$response['message'] = __( 'Subscription ID not found', 'user-registration' );

			return $response;
		}

		PaymentGatewayLogging::log_general(
			'paypal',
			'Retrying PayPal subscription payment',
			'notice',
			array(
				'event_type'      => 'retry_initiated',
				'subscription_id' => $subscription['sub_id'],
				'user_id'         => $subscription['user_id'] ?? 'unknown',
				'item_id'         => $subscription['item_id'] ?? 'unknown',
			)
		);

		// Login to PayPal
		$login_request = self::login_paypal( $url, $client_id, $client_secret );
		if ( 200 !== $login_request['status_code'] ) {
			$message = esc_html__( 'Invalid response from PayPal, check Client ID or Secret.', 'user-registration' );
			PaymentGatewayLogging::log_transaction_failure(
				'paypal',
				$message,
				array(
					'error_code'      => 'PAYPAL_LOGIN_FAILED',
					'subscription_id' => $subscription['sub_id'],
					'user_id'         => $subscription['user_id'] ?? 'unknown',
				)
			);

			$response['message'] = $message;

			return $response;
		}

		try {
			$bearer_token = $login_request['access_token'];

			// Get subscription details from PayPal
			$subscription_url = $url . sprintf( 'v1/billing/subscriptions/%s', $subscription['sub_id'] );

			$headers = array(
				'Content-Type: application/json',
				'Accept: application/json',
				'Authorization: Bearer ' . $bearer_token,
			);

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $subscription_url );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

			$response_body = curl_exec( $ch );
			$status_code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			if ( curl_errno( $ch ) ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					'cURL error during subscription retry',
					array(
						'curl_error'      => curl_error( $ch ),
						'subscription_id' => $subscription['sub_id'],
					)
				);
				$response['message'] = __( 'Connection error with PayPal', 'user-registration' );

				return $response;
			}

			$subscription_data = json_decode( $response_body, true );

			PaymentGatewayLogging::log_api_response(
				'paypal',
				'PayPal subscription details retrieved',
				array(
					'status_code'         => $status_code,
					'subscription_id'     => $subscription['sub_id'],
					'subscription_status' => $subscription_data['status'] ?? 'unknown',
				)
			);

			// Check if subscription exists and is in a state that needs retry
			if ( 200 !== $status_code ) {
				PaymentGatewayLogging::log_error(
					'paypal',
					'Failed to retrieve subscription from PayPal',
					array(
						'status_code'     => $status_code,
						'subscription_id' => $subscription['sub_id'],
						'response'        => $response_body,
					)
				);

				$response['message'] = __( 'Subscription not found in PayPal', 'user-registration' );

				return $response;
			}

			// Check subscription status
			$paypal_status = $subscription_data['status'] ?? '';

			if ( in_array( $paypal_status, array( 'SUSPENDED', 'CANCELLED' ) ) ) {
				// Only if the paypal status is suspended or cancelled.
				PaymentGatewayLogging::log_general(
					'paypal',
					'Attempting to reactivate suspended/cancelled PayPal subscription',
					'notice',
					array(
						'subscription_id' => $subscription['sub_id'],
						'current_status'  => $paypal_status,
						'user_id'         => $subscription['user_id'] ?? 'unknown',
					)
				);

				$reactivate_url = $url . sprintf( 'v1/billing/subscriptions/%s/activate', $subscription['sub_id'] );
				$activate_data  = json_encode(
					array(
						'reason' => 'Payment retry - System initiated reactivation',
					)
				);

				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $reactivate_url );
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $activate_data );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

				$activate_response = curl_exec( $ch );
				$activate_status   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

				if ( curl_errno( $ch ) ) {
					PaymentGatewayLogging::log_error(
						'paypal',
						'cURL error during subscription reactivation',
						array(
							'curl_error'      => curl_error( $ch ),
							'subscription_id' => $subscription['sub_id'],
						)
					);
				}

				PaymentGatewayLogging::log_api_response(
					'paypal',
					'PayPal subscription reactivation response',
					array(
						'status_code'     => $activate_status,
						'subscription_id' => $subscription['sub_id'],
					)
				);

				if ( 204 === $activate_status ) {
					PaymentGatewayLogging::log_transaction_success(
						'paypal',
						'Subscription payment retry successful - Subscription reactivated',
						array(
							'subscription_id' => $subscription['sub_id'],
							'user_id'         => $subscription['user_id'] ?? 'unknown',
							'old_status'      => $paypal_status,
							'new_status'      => 'active',
						)
					);

					$response['status']  = true;
					$response['message'] = __( 'Subscription payment retried and reactivated successfully', 'user-registration' );

					return $response;
				} else {
					PaymentGatewayLogging::log_error(
						'paypal',
						'Subscription reactivation failed',
						array(
							'status_code'     => $activate_status,
							'subscription_id' => $subscription['sub_id'],
							'response'        => $activate_response,
						)
					);

					$response['message'] = __( 'Subscription reactivation failed', 'user-registration' );

					return $response;
				}
			} elseif ( 0 === strcasecmp( 'active', $paypal_status ) ) {
				PaymentGatewayLogging::log_general(
					'paypal',
					'Subscription is already active - no retry needed',
					'notice',
					array(
						'subscription_id' => $subscription['sub_id'],
						'status'          => $paypal_status,
						'user_id'         => $subscription['user_id'] ?? 'unknown',
					)
				);

				$response['status']  = true;
				$response['message'] = __( 'Subscription is already active', 'user-registration' );

				return $response;
			} else {
				PaymentGatewayLogging::log_error(
					'paypal',
					'Subscription is in an unrecoverable state',
					array(
						'subscription_id' => $subscription['sub_id'],
						'status'          => $paypal_status,
						'user_id'         => $subscription['user_id'] ?? 'unknown',
					)
				);

				$response['message'] = sprintf( __( 'Subscription status is %s and cannot be retried', 'user-registration' ), $paypal_status );

				return $response;
			}
		} catch ( \Exception $e ) {
			PaymentGatewayLogging::log_error(
				'paypal',
				'Exception during subscription retry',
				array(
					'error_message'   => $e->getMessage(),
					'error_code'      => $e->getCode(),
					'subscription_id' => $subscription['sub_id'] ?? 'unknown',
					'user_id'         => $subscription['user_id'] ?? 'unknown',
				)
			);

			$response['message'] = $e->getMessage();

			return $response;
		}
	}
}
