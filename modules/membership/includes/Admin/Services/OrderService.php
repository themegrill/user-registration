<?php

namespace WPEverest\URMembership\Admin\Services;

use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

class OrderService {
	/**
	 * @var OrdersRepository
	 */
	protected $orders_repository, $response;

	public function __construct() {
		$this->orders_repository = new OrdersRepository();
		$this->response          = array(
			'status' => true,
		);
	}

	public function prepare_orders_data( $data, $member_id, $subscription, $upgrade_details = null, $is_renewal = false ) {

		$current_user = wp_get_current_user();
		// Only treat as admin-created when the order is built from the admin Members UI.
		$is_frontend = isset( $data['context'] ) && 'frontend' === $data['context'];
		$is_admin    = ! $is_frontend && ( ! empty( $current_user->roles ) && in_array( 'administrator', (array) $current_user->roles, true ) );

		$membership = get_post( $data['membership_data']['membership'], ARRAY_A );

		$membership_meta = json_decode( wp_unslash( get_post_meta( $membership['ID'], 'ur_membership', true ) ), true );
		$transaction_id  = '';
		if ( 'bank' == $data['membership_data']['payment_method'] ) {
			$transaction_id = str_replace( '-', '', wp_generate_uuid4() );
		}
		$order_type = '';
		$total      = 0;

		if ( isset( $data['team'] ) && ! empty( $data['team'] ) ) {
			$team_data      = $data['team'];
			$team_plan_type = isset( $team_data['team_plan_type'] ) ? $team_data['team_plan_type'] : null;
			if ( $team_plan_type ) {
				if ( 'subscription' === $team_plan_type ) {
					$order_type = 'subscription';
				} else {
					$order_type = 'paid';
				}
			}
			$seat_model = isset( $team_data['seat_model'] ) ? $team_data['seat_model'] : 'fixed';
			if ( 'fixed' === $seat_model ) {
				$total = isset( $team_data['team_price'] ) ? floatval( $team_data['team_price'] ) : 0;
			} else {
				$pricing_model = isset( $team_data['pricing_model'] ) ? $team_data['pricing_model'] : 'per_seat';
				$team_seats    = isset( $data['team_seats'] ) ? absint( $data['team_seats'] ) : 0;

				if ( 'per_seat' === $pricing_model ) {
					// Per seat pricing: team_seats × per_seat_price
					$per_seat_price = isset( $team_data['per_seat_price'] ) ? floatval( $team_data['per_seat_price'] ) : 0;
					$total          = $team_seats * $per_seat_price;
				} elseif ( 'tier' === $pricing_model ) {
					// Tier pricing: team_seats × tier_per_seat_price (from selected tier)
					if ( isset( $data['tier'] ) && isset( $data['tier']['tier_per_seat_price'] ) ) {
						$tier_per_seat_price = floatval( $data['tier']['tier_per_seat_price'] );
						$total               = $team_seats * $tier_per_seat_price;
					}
				}
			}
		} else {
			$order_type = sanitize_text_field( $membership_meta['type'] );
			$total      = number_format( $membership_meta['amount'], 2, '.', '' );
		}

		// Pre-discount base, used as the ratio reference for manual local pricing below.
		$base_amount = (float) $total;

		$coupon_discount_amount = 0;
		$has_trial              = isset( $membership_meta['trial_status'] ) && 'on' == $membership_meta['trial_status'];

		if ( $has_trial ) {
			$total = 0;
		} elseif ( ur_check_module_activation( 'coupon' ) && ! empty( $data['coupon_data'] ) ) {
			$coupon_discount_amount = ( isset( $data['coupon_data']['coupon_discount_type'] ) && 'fixed' === $data['coupon_data']['coupon_discount_type'] )
				? floatval( $data['coupon_data']['coupon_discount'] )
				: $total * floatval( $data['coupon_data']['coupon_discount'] ) / 100;
			$total                  = max( 0, $total - $coupon_discount_amount );
		}

		// For upgrades, replace $total with the pre-tax prorated amount so that
		// tax and total_amount are both calculated on the correct base.
		// A plan with its own trial bills nothing up front on any gateway, so its upgrade order stays 0.
		$is_proration_upgrade_order = false;
		if ( ! empty( $upgrade_details ) && isset( $upgrade_details['chargeable_amount'] ) ) {
			$total                      = $has_trial ? 0 : floatval( $upgrade_details['chargeable_amount'] );
			$is_proration_upgrade_order = true;
		}

		$local_currency_converted_amount = 0;

		if ( ! empty( $data['local_currency_details'] ) ) {
			$local_currency = ! empty( $data['local_currency_details']['switched_currency'] ) ? $data['local_currency_details']['switched_currency'] : '';
			$ur_zone_id     = ! empty( $data['local_currency_details']['urm_zone_id'] ) ? $data['local_currency_details']['urm_zone_id'] : '';

			if ( ! empty( $local_currency ) && ! empty( $ur_zone_id ) && ur_check_module_activation( 'local-currency' ) && UR_PRO_ACTIVE && class_exists( CoreFunctions::class ) ) {
				$pricing_data        = CoreFunctions::ur_get_pricing_zone_by_id( $ur_zone_id );
				$local_currency_data = ! empty( $membership_meta['local_currency'] ) ? $membership_meta['local_currency'] : array();

				if ( ! empty( $local_currency_data ) && ur_string_to_bool( $local_currency_data['is_enable'] ) ) {
					$currency                        = $local_currency;
					// Pass $base_amount so manual pricing scales the flat price by the discount
					// ratio (a 100% coupon converts 0 to 0, not back to the full local price).
					$total                           = CoreFunctions::ur_get_amount_after_conversion( $total, $currency, $pricing_data, $local_currency_data, $ur_zone_id, $base_amount );
					$local_currency_converted_amount = CoreFunctions::ur_get_amount_after_conversion( $membership_meta['amount'], $currency, $pricing_data, $local_currency_data, $ur_zone_id );
				}
			}
		}

		$tax_details = isset( $data['tax_data'] ) ? $data['tax_data'] : array();

		if ( ! empty( $data['tax_data']['tax_rate'] ) ) {
			$tax_rate   = floatval( $data['tax_data']['tax_rate'] );
			$tax_amount = $total * $tax_rate / 100;
			$total      = $total + $tax_amount;

			$tax_details['tax_amount']      = number_format( $tax_amount, 2, '.', '' );
			$tax_details['total_after_tax'] = number_format( $total, 2, '.', '' );
		}

		$creator = $is_admin ? 'admin' : 'member';
		$type    = $is_renewal ? 'Renewal' : ( ! empty( $upgrade_details ) ? 'Upgrade' : '' );
		if ( ! empty( $type ) ) {
			$note = sprintf( __( '%1$s created order for %2$s of %3$s', 'user-registration' ), $creator, $type, $membership['post_title'] );
		} else {
			$note = sprintf( __( '%1$s created order for %2$s', 'user-registration' ), $creator, $membership['post_title'] );
		}

		$orders_data = array(
			'item_id'         => absint( $data['membership_data']['membership'] ),
			'subscription_id' => absint( $subscription['ID'] ),
			'user_id'         => absint( $member_id ),
			'created_by'      => isset( $current_user ) && $current_user->ID != 0 ? $current_user->ID : absint( $member_id ),
			'transaction_id'  => $transaction_id,
			'payment_method'  => ( $data['membership_data']['payment_method'] ) ? sanitize_text_field( $data['membership_data']['payment_method'] ) : '',
			'total_amount'    => number_format( $total, 2, '.', '' ),
			// UR-4386: a one-time free order (paid plan + 100% coupon, payment_method="free") has no
			// gateway step, so complete it immediately like a free plan.
			'status'          => ( 'free' === $membership_meta['type'] || $is_admin || ( 'free' === ( $data['membership_data']['payment_method'] ?? '' ) && 0.0 === (float) $total && $coupon_discount_amount > 0 ) ) ? 'completed' : 'pending',
			'order_type'      => $order_type,
			'trial_status'    => ( ! empty( $upgrade_details ) && ( 'on' === $upgrade_details['trial_status'] ) ) ? 'on' : ( isset( $membership_meta['trial_status'] ) ? sanitize_text_field( $membership_meta['trial_status'] ) : 'off' ),
			'notes'           => $note,
		);

		$orders_meta = array(
			array(
				'meta_key'   => 'is_admin_created',
				'meta_value' => $is_admin,
			),
		);

		if ( ! empty( $tax_details ) ) {
			$orders_meta[] = array(
				'meta_key'   => 'tax_data',
				'meta_value' => json_encode( $tax_details ),
			);
		}

		if ( $is_proration_upgrade_order ) {
			$orders_meta[] = array(
				'meta_key'   => 'is_proration_upgrade',
				'meta_value' => '1',
			);
		}

		if ( ! empty( $data['coupon_data'] ) && $coupon_discount_amount > 0 ) {
			$orders_meta[] = array(
				'meta_key'   => 'coupon_data',
				'meta_value' => json_encode(
					array(
						'coupon_code'          => isset( $data['coupon_data']['coupon_code'] ) ? $data['coupon_data']['coupon_code'] : '',
						'coupon_discount_type' => isset( $data['coupon_data']['coupon_discount_type'] ) ? $data['coupon_data']['coupon_discount_type'] : '',
						'coupon_discount'      => isset( $data['coupon_data']['coupon_discount'] ) ? $data['coupon_data']['coupon_discount'] : 0,
						'discount_amount'      => number_format( $coupon_discount_amount, 2, '.', '' ),
					)
				),
			);
		}

		if ( ! empty( $currency ) ) {
			$orders_meta[] = array(
				'meta_key'   => 'local_currency',
				'meta_value' => $currency,
			);
		}

		if ( ! empty( $local_currency_converted_amount ) ) {
			$orders_meta[] = array(
				'meta_key'   => 'local_currency_converted_amount',
				'meta_value' => $local_currency_converted_amount,

			);
		}

		if ( ! empty( $upgrade_details ) && ! empty( $upgrade_details['delayed_until'] ) ) {
			$orders_meta[] = array(
				'meta_key'   => 'delayed_until',
				'meta_value' => $upgrade_details['delayed_until'],
			);
		}

		return array(
			'orders_data'      => $orders_data,
			'orders_meta_data' => $orders_meta,
		);
	}
}
