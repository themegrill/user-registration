<?php

namespace WPEverest\URMembership\Payment\Admin;

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;

class OrderService {
	protected $response, $is_membership_active, $orders_repository, $subscription_repository;

	public function __construct() {
		$this->is_membership_active    = ur_check_module_activation( 'membership' );
		$this->response                = array(
			'status' => true,
		);
		$this->orders_repository       = ( $this->is_membership_active ) ? new OrdersRepository() : '';
		$this->subscription_repository = ( $this->is_membership_active ) ? new SubscriptionRepository() : '';
	}

	public function validation( $method, $data ) {

		switch ( $method ) {
			case 'view':
				return $this->validate_view( $data );
				break;
			case 'approval':
				return $this->validate_payment_approval_request( $data );
			default:
				break;
		}
	}

	/**
	 * extracted
	 *
	 * @param $order
	 *
	 * @return array|void
	 */
	public function validate_payment_approval_request( $order ) {
		if ( empty( $order ) ) {
			return array(
				'status'  => false,
				'message' => __( 'Order not found', 'user-registration' ),
				'code'    => 204,
			);
		}
		if ( 'pending' !== $order['status'] ) {
			return array(
				'status'  => false,
				'message' => __( 'Order is already completed.', 'user-registration' ),
				'code'    => 422,
			);
		}
		if ( 'bank' !== $order['payment_method'] ) {
			return array(
				'status'  => false,
				'message' => __( 'Invalid payment method.', 'user-registration' ),
				'code'    => 422,
			);
		}

		return array(
			'status' => true,
		);
	}

	public function validate_view( $data ) {
		if ( ! isset( $data['order_id'] ) ) {
			$this->response['status']  = false;
			$this->response['code']    = 422;
			$this->response['message'] = __( 'Field Order ID is required.', 'user-registration' );

		} elseif ( $data['order_id'] == 0 && ! isset( $data['user_id'] ) ) {
			$this->response['status']  = false;
			$this->response['code']    = 422;
			$this->response['message'] = __( 'Field User ID is required.', 'user-registration' );
		}

		return $this->response;
	}

	public function create_view_template( $order_id, $user_id ) {
		if ( $order_id ) {
			$order_detail = $this->orders_repository->get_order_detail( $order_id );
			if ( ! empty( $order_detail['plan_details'] ) ) {
				$order_detail['plan_details'] = json_decode( $order_detail['plan_details'], true );
			}
			if ( ! empty( $order_detail['coupon'] ) ) {
				$order_detail['coupon_discount']      = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount', true );
				$order_detail['coupon_discount_type'] = get_user_meta( $order_detail['user_id'], 'ur_coupon_discount_type', true );

			}
		} elseif ( $user_id ) {
			$order_detail = $this->get_user_form_order_detail( $user_id );
		}

		if ( empty( $order_detail ) ) {
			return array(
				'status'  => false,
				'code'    => 404,
				'message' => __( 'Order detail not found', 'user-registration' ),
			);
		}

		include __DIR__ . '/../Views/payment-details.php';
		$html = ob_get_clean();

		return array(
			'status'   => true,
			'template' => $html,
		);
	}

	/**
	 * Get a form payment's detail from the user's stored invoices.
	 *
	 * @param int $user_id       User ID.
	 * @param int $invoice_index Index in the user's ur_payment_invoices meta.
	 * @return array Order detail, empty when the user does not exist.
	 */
	public function get_user_form_order_detail( $user_id, $invoice_index = 0 ) {
		$user = get_user_by( 'ID', $user_id );
		if ( ! empty( $user ) ) {
			$meta_value = get_user_meta( $user->ID, 'ur_payment_invoices', true );
			$invoice    = isset( $meta_value[ $invoice_index ] ) ? $meta_value[ $invoice_index ] : ( $meta_value[0] ?? array() );

			$invoice_item         = ! empty( $invoice['invoice_item'][0] ) ? json_decode( $invoice['invoice_item'][0], true ) : array();
			$total_items          = array(
				'user_id'        => $user->ID,
				'display_name'   => $user->user_login,
				'user_nicename'  => $user->user_nicename,
				'user_email'     => $user->user_email,
				'transaction_id' => $invoice['invoice_no'] ?? '',
				'post_title'     => $invoice['invoice_plan'] ?? '',
				'status'         => get_user_meta( $user->ID, 'ur_payment_status', true ),
				'created_at'     => $invoice['invoice_date'] ?? '',
				'type'           => $invoice_item[0]['label'] ?? '',
				'payment_method' => get_user_meta( $user->ID, 'ur_payment_method', true ),
				'total_amount'   => get_user_meta( $user->ID, 'ur_payment_total_amount', true ),
				'product_amount' => get_user_meta( $user->ID, 'ur_payment_product_amount', true ),
			);
			$coupon               = get_user_meta( $user->ID, 'ur_coupon_code', true );
			$coupon_discount      = get_user_meta( $user->ID, 'ur_coupon_discount', true );
			$coupon_discount_type = get_user_meta( $user->ID, 'ur_coupon_discount_type', true );
			if ( ! empty( $coupon ) ) {
				$total_items['coupon']               = $coupon;
				$total_items['coupon_discount']      = $coupon_discount;
				$total_items['coupon_discount_type'] = $coupon_discount_type;
			}

			return $total_items;
		}

		return array();
	}

	/**
	 * @param $user_id
	 *
	 * @return bool
	 */
	public function delete_user_form_order( $user_id ) {
		$user_meta = get_user_meta( $user_id );
		$status    = true;
		foreach ( $user_meta as $meta_key => $meta_value ) {
			if ( strpos( $meta_key, 'ur_payment_' ) === 0 ) {
				if ( ! delete_user_meta( $user_id, $meta_key ) ) {
					$status = false;
					break;
				}
			}
		}

		return $status;
	}

	/**
	 * @param $user_ids
	 *
	 * @return bool
	 */
	public function delete_multiple_user_form_order( $user_ids ) {
		$status = true;
		foreach ( $user_ids as $user_id ) {
			if ( ! $this->delete_user_form_order( $user_id ) ) {
				$status = false;
				break;
			}
		}

		return $status;
	}

	/**
	 * approve_payment_status
	 *
	 * @param $order
	 *
	 * @return bool[]
	 */
	public function approve_payment_status( $order, $subscription_id ) {
		try {
			$order_id = $order['order_id'];
			$user_id  = $order['user_id'];

			// Get membership type for logging
			$membership_repository = new MembershipRepository();
			$membership            = $membership_repository->get_single_membership_by_ID( $order['post_id'] );
			$membership_metas      = wp_unslash( json_decode( $membership['meta_value'], true ) );
			$membership_type       = $membership_metas['type'] ?? 'unknown';

			PaymentGatewayLogging::log_general(
				'bank',
				'Admin approving bank payment order',
				'notice',
				array(
					'event_type'      => 'admin_approval_initiated',
					'order_id'        => $order_id,
					'member_id'       => $user_id,
					'amount'          => $order['total_amount'] ?? 'unknown',
					'membership_type' => $membership_type,
				)
			);

			$this->orders_repository->wpdb()->query( 'START TRANSACTION' ); // Start the transaction.
			$subscription_service = new SubscriptionService();
			$approve_order        = $this->orders_repository->update( $order_id, array( 'status' => 'completed' ) );
			if ( $approve_order ) {
				$subscription_data      = array( 'status' => 'active' );
				$prev_subscription_data = json_decode( get_user_meta( $user_id, 'urm_previous_subscription_data', true ), true );
				$membership_process     = urm_get_membership_process( $user_id );
				$is_upgrading           = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $prev_subscription_data['item_id'] ] );
				$is_renewing            = ! empty( $membership_process['renew'] ) && in_array( $order['item_id'], $membership_process['renew'] );

				if ( $is_upgrading ) {
					$next_subscription_data      = json_decode( get_user_meta( $user_id, 'urm_next_subscription_data', true ), true );
					$subscription_data           = $subscription_service->prepare_upgrade_subscription_data( $next_subscription_data['membership'], $next_subscription_data['member_id'], $next_subscription_data );
					$subscription_data['status'] = 'active';
				}
				$approve_order = $this->orders_repository->get_order_detail( $order_id );
				if ( 'on' === $approve_order['trial_status'] ) {
					$subscription_data['status'] = 'trial';
				}
				if ( $is_renewing ) {
					$members_subscription_repo      = new MembersSubscriptionRepository();
					$membership_repository          = new MembershipRepository();
					$member_subscription            = $members_subscription_repo->get_subscription_data_by_subscription_id( $subscription_id );
					$membership                     = $membership_repository->get_single_membership_by_ID( $member_subscription['item_id'] );
					$membership_metas               = wp_unslash( json_decode( $membership['meta_value'], true ) );
					$membership_metas['post_title'] = $membership['post_title'];
					$subscription_service->update_subscription_data_for_renewal( $member_subscription, $membership_metas );
					$subscription_data['start_date'] = date( 'Y-m-d 00:00:00' );
				}

				$this->subscription_repository->update( $subscription_id, $subscription_data );

				PaymentGatewayLogging::log_general(
					'bank',
					'Bank payment order approved - Status changed to completed',
					'notice',
					array(
						'event_type'      => 'status_change',
						'old_status'      => 'pending',
						'new_status'      => 'completed',
						'order_id'        => $order_id,
						'member_id'       => $user_id,
						'membership_type' => $membership_type,
						'is_upgrade'      => $is_upgrading,
						'is_renewal'      => $is_renewing,
					)
				);

				PaymentGatewayLogging::log_general(
					'bank',
					'Subscription status changed to ' . $subscription_data['status'],
					'notice',
					array(
						'event_type'      => 'status_change',
						'old_status'      => 'pending',
						'new_status'      => $subscription_data['status'],
						'subscription_id' => $subscription_id,
						'member_id'       => $user_id,
						'membership_type' => $membership_type,
						'is_upgrade'      => $is_upgrading,
						'is_renewal'      => $is_renewing,
					)
				);

				PaymentGatewayLogging::log_transaction_success(
					'bank',
					'Bank payment approved and completed successfully',
					array(
						'event_type'      => 'admin_approval_completed',
						'order_id'        => $order_id,
						'subscription_id' => $subscription_id,
						'member_id'       => $user_id,
						'final_status'    => $subscription_data['status'],
						'membership_type' => $membership_type,
					)
				);

				$this->orders_repository->wpdb()->query( 'COMMIT' );
				$this->response['message'] = __( 'Order has been approved successfully.', 'user-registration' );

				if ( ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $prev_subscription_data['item_id'] ] ) ) {
					unset( $membership_process['upgrade'][ $prev_subscription_data['item_id'] ] );
					update_user_meta( $user_id, 'urm_membership_process', $membership_process );
				}

				delete_user_meta( $user_id, 'urm_next_subscription_data' );
				$this->response['member_id'] = $user_id;
				return $this->response;

			}
			PaymentGatewayLogging::log_error(
				'bank',
				'Failed to approve bank payment order',
				array(
					'error_code' => 'APPROVAL_UPDATE_FAILED',
					'order_id'   => $order_id,
					'member_id'  => $user_id,
				)
			);

			$this->response['status']  = false;
			$this->response['message'] = __( 'Something went wrong while updating payment status', 'user-registration' );

			return $this->response;
		} catch ( \Exception $e ) {
			PaymentGatewayLogging::log_error(
				'bank',
				'Bank payment approval failed with exception',
				array(
					'error_code'    => 'APPROVAL_EXCEPTION',
					'error_message' => $e->getMessage(),
					'order_id'      => $order_id ?? 'unknown',
					'member_id'     => $user_id ?? 'unknown',
				)
			);

			// Rollback the transaction if any operation fails.
			$this->orders_repository->wpdb()->query( 'ROLLBACK' );
			$this->response['status']  = false;
			$this->response['message'] = $e->getMessage();

			return $this->response;
		}
	}
}
