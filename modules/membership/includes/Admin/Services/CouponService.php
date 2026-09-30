<?php

namespace WPEverest\URMembership\Admin\Services;

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;
use WPEverest\URMembership\TableList;

class CouponService {
	/**
	 * @var string
	 */
	protected $table, $membership_repository;

	/**
	 * Constructor of this class.
	 */
	public function __construct() {
		$this->table                 = TableList::posts_table();
		$this->membership_repository = new MembershipRepository();
	}

	/**
	 * Set Coupon Response.
	 *
	 * @param $status
	 * @param $code
	 * @param $message
	 * @param array $data
	 *
	 * @return array
	 */
	public function set_coupon_response( $status, $code, $message, array $data = array() ) {
		return array(
			'status'  => $status,
			'message' => esc_html__( $message, 'user-registration' ),
			'data'    => wp_json_encode( $data ),
			'code'    => $code,
		);
	}

	/**
	 * Validate coupon data
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function validate( $data ) {

		if ( ! isset( $data['coupon'] ) ) {
			return $this->set_coupon_response( false, 422, 'Coupon field is required.' );
		}
		if ( ! isset( $data['membership_id'] ) ) {
			return $this->set_coupon_response( false, 404, 'Membership Id is required.' );
		}
		$coupon        = sanitize_text_field( $data['coupon'] );
		$membership_id = absint( $data['membership_id'] );
		// get coupon details
		$coupon_details = ur_get_coupon_details( $coupon );

		if ( empty( $coupon_details ) ) {
			return $this->set_coupon_response( false, 404, 'Coupon does not exist', );
		}

		if ( isset( $coupon_details['coupon_status'] ) && ! $coupon_details['coupon_status'] ) {
			return $this->set_coupon_response( false, 422, 'Coupon is Inactive' );
		}

		$current_date = current_time( 'timestamp' );
		$end_date     = ! empty( $coupon_details['coupon_end_date'] ) ? strtotime( $coupon_details['coupon_end_date'] ) : 'never';

		if ( 'never' !== $end_date && $end_date < $current_date ) {
			return $this->set_coupon_response( false, 422, 'Coupon expired.' );
		}

		if ( 'membership' !== $coupon_details['coupon_for'] ) {
			return $this->set_coupon_response( false, 422, 'Invalid coupon type.', );
		}

		$coupon_membership = (array) json_decode( $coupon_details['coupon_membership'], true );
		if ( ! in_array( $membership_id, $coupon_membership ) ) {
			return $this->set_coupon_response( false, 422, 'Coupon cannot be applied for the selected membership.' );
		}

		if ( strtotime( $coupon_details['coupon_start_date'] ) > $current_date ) {
			return $this->set_coupon_response( false, 422, 'Coupon is not valid until ' . date_i18n( get_option( 'date_format' ), strtotime( $coupon_details['coupon_start_date'] ) ) . '.', );
		}
		$membership_details = $this->membership_repository->get_single_membership_by_ID( $membership_id );

		if ( empty( $membership_details['meta_value'] ) ) {
			return $this->set_coupon_response( false, 404, 'Membership does not exist.' );
		}

		$membership_meta = json_decode( $membership_details['meta_value'], true );
		if ( 'free' === $membership_meta['type'] ) {
			return $this->set_coupon_response( false, 422, 'Invalid membership type (Free).', );
		}

		$membership_amount = $membership_meta['amount'];

		if ( ! empty( $data['upgrade_amount'] ) ) {
			$membership_amount = $data['upgrade_amount'];
		}

		$discount_amount = ( $coupon_details['coupon_discount_type'] === 'fixed' ) ? $coupon_details['coupon_discount'] : $membership_amount * $coupon_details['coupon_discount'] / 100;

		if ( $discount_amount > $membership_amount ) {
			return $this->set_coupon_response( false, 422, 'Coupon is Invalid. Discount amount is greater than membership amount.', );
		}

		$final_data = array(
			'coupon_details'  => $coupon_details,
			'membership_meta' => $membership_meta,
		);
		$amount     = $membership_amount - $discount_amount;

		$currency = get_option( 'user_registration_payment_currency', 'USD' );

		if ( ! empty( $data['switched_currency'] ) && ! empty( $data['urm_zone_id'] ) ) {
			$local_currency = ! empty( $data['switched_currency'] ) ? $data['switched_currency'] : '';
			$ur_zone_id     = ! empty( $data['urm_zone_id'] ) ? $data['urm_zone_id'] : '';

			if ( ! empty( $local_currency ) && ! empty( $ur_zone_id ) && ur_check_module_activation( 'local-currency' ) && class_exists( CoreFunctions::class ) ) {
				$currency            = $local_currency;
				$pricing_data        = CoreFunctions::ur_get_pricing_zone_by_id( $ur_zone_id );
				$local_currency_data = ! empty( $membership_meta['local_currency'] ) ? $membership_meta['local_currency'] : array();

				if ( ! empty( $local_currency_data ) && ur_string_to_bool( $local_currency_data['is_enable'] ) ) {
					// Pass $membership_amount as the ratio reference so a manual (flat) local price scales with the discount.
					$local_full_amount = CoreFunctions::ur_get_amount_after_conversion( $membership_amount, $currency, $pricing_data, $local_currency_data, $ur_zone_id );
					$amount            = CoreFunctions::ur_get_amount_after_conversion( $amount, $currency, $pricing_data, $local_currency_data, $ur_zone_id, $membership_amount );

					if ( $coupon_details['coupon_discount_type'] === 'fixed' ) {
						$coupon_details['coupon_discount'] = max( 0, $local_full_amount - $amount );
					}
				}
			}
		}

		if ( ! empty( $data['tax_rate'] ) ) {
			$tax_rate   = floatval( $data['tax_rate'] );
			$tax_amount = $amount * $tax_rate / 100;
			$amount     = $amount + $tax_amount;
		}

		$message                         = 'Coupon applied successfully.';
		$final_data['discounted_amount'] = $amount;
		$final_data['discount_amount']   = $discount_amount;

		$final_data['coupon_details']['coupon_discount'] = ( $coupon_details['coupon_discount_type'] === 'fixed' ) ? html_entity_decode( ur_get_currency_symbol( $currency ) ) . $coupon_details['coupon_discount'] : $coupon_details['coupon_discount'];

		if ( 'subscription' === $membership_meta['type'] ) {
			return $this->set_coupon_response( true, 200, $message, $final_data );
		} elseif ( 'paid' === $membership_meta['type'] ) {
			return $this->set_coupon_response( true, 200, $message, $final_data );
		}

		return $this->set_coupon_response( true, 200, array(), array() );
	}
}
