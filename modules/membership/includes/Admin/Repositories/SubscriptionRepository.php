<?php

namespace WPEverest\URMembership\Admin\Repositories;

use WPEverest\URMembership\Admin\Interfaces\SubscriptionInterface;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\TableList;

class SubscriptionRepository extends BaseRepository implements SubscriptionInterface {

	/**
	 * @var string
	 */
	protected $table;

	/**
	 * @var
	 */
	protected $orders_repository;

	/**
	 * Constructor of this class.
	 */
	public function __construct() {
		$this->table             = TableList::subscriptions_table();
		$this->orders_repository = new OrdersRepository();
	}

	/**
	 * cancel_subscription_by_id
	 *
	 * @param int  $subscription_id Subscription ID.
	 * @param bool $send_email      Whether to send cancellation emails.
	 * @param bool $is_upgrade      Whether this cancel is part of an upgrade.
	 * @param bool $force_cancel    Force immediate gateway cancel (PayPal cancel vs suspend; Stripe cancel now vs period end).
	 *
	 * @return array|bool[]|mixed|null
	 */
	public function cancel_subscription_by_id( $subscription_id, $send_email = true, $is_upgrade = false, $force_cancel = false ) {
		$subscription = $this->retrieve( $subscription_id );

		if ( empty( $subscription ) || ! is_array( $subscription ) ) {
			return array(
				'status'  => false,
				'message' => esc_html__( 'Subscription not found.', 'user-registration' ),
			);
		}

		$order = $this->orders_repository->get_order_by_subscription( $subscription_id );

		$subscription_service = new SubscriptionService();
		if ( 'canceled' === $subscription['status'] ) {
			return array(
				'status'  => false,
				'message' => esc_html__( 'Subscription is already canceled.', 'user-registration' ),
			);
		}

		if ( ! $is_upgrade && ( 'free' === ( $order['order_type'] ?? '' ) || 'paid' === ( $order['order_type'] ?? '' ) || empty( $order['payment_method'] ) ) ) {
			$this->update(
				$subscription_id,
				array(
					'status' => 'canceled',
				)
			);
			if ( $send_email ) {
				$subscription_service->send_cancel_emails( $subscription_id );
			}

			return array(
				'status'  => true,
				'message' => esc_html__( 'Subscription Cancelled Successfully', 'user-registration' ),
			);
		} else {
			if ( $is_upgrade ) {
				$get_user_old_subscription = json_decode( get_user_meta( $subscription['user_id'], 'urm_previous_subscription_data', true ), true );

				if ( ! empty( $get_user_old_subscription ) ) {
					$subscription = $get_user_old_subscription;
				}

				if ( empty( $subscription['subscription_id'] ) ) {
					return array(
						'status'  => false,
						'message' => esc_html__( 'Subscription id not present.', 'user-registration' ),
					);
				}
				$get_user_old_order = json_decode( get_user_meta( $subscription['user_id'], 'urm_previous_order_data', true ), true );

				if ( ! empty( $get_user_old_order ) ) {
					$order = $get_user_old_order;
				}
			}

			$cancel_sub = $subscription_service->cancel_subscription( $order, $subscription, $force_cancel );

			if ( $cancel_sub['status'] ) {
				$expiry_date = $subscription['expiry_date'] ?? '';

				// Force cancel ends billing now — mark canceled locally even if expiry is still in the future.
				if ( ! $force_cancel && ! empty( $expiry_date ) && strtotime( $expiry_date ) > time() ) {
					update_user_meta( $subscription['user_id'], 'urm_pending_cancel_' . $subscription_id, $expiry_date );
				} else {
					$this->update( $subscription_id, array( 'status' => 'canceled' ) );
					delete_user_meta( $subscription['user_id'], 'urm_pending_cancel_' . $subscription_id );
				}
				if ( $send_email ) {
					$subscription_service->send_cancel_emails( $subscription_id );
				}

				return array(
					'status'  => true,
					'message' => esc_html__( 'Subscription Cancelled Successfully', 'user-registration' ),
				);
			} else {
				return array(
					'status'  => false,
					'message' => esc_html__( 'We could not cancel your subscription automatically. Please try again, or contact support if the problem continues.', 'user-registration' ),
				);
			}
		}
	}

	public function reactivate_subscription_by_id( $subscription_id, $send_email = true ) {
		$subscription       = $this->retrieve( $subscription_id );
		$pending_cancel_key = 'urm_pending_cancel_' . $subscription_id;

		if ( 'active' === $subscription['status'] ) {
			if ( ! get_user_meta( $subscription['user_id'], $pending_cancel_key, true ) ) {
				return array(
					'status'  => false,
					'message' => esc_html__( 'Subscription is already active.', 'user-registration' ),
				);
			}

			$order  = $this->orders_repository->get_order_by_subscription( $subscription_id );
			$result = ( new SubscriptionService() )->reactivate_subscription( $order, $subscription );

			if ( empty( $result['status'] ) ) {
				return array(
					'status'  => false,
					'message' => $result['message'] ?? esc_html__( 'Failed to reactivate subscription with payment gateway.', 'user-registration' ),
				);
			}

			delete_user_meta( $subscription['user_id'], $pending_cancel_key );

			return array( 'status' => true );
		}

		if ( 'expired' !== $subscription['status'] ) {
			$order = $this->orders_repository->get_order_by_subscription( $subscription_id );

			$subscription_service = new SubscriptionService();
			$subscription_service->reactivate_subscription( $order, $subscription );

			// Only restore to 'active' if the order's payment has actually been approved/completed
			// (e.g. Bank Transfer orders stay 'pending' until an admin approves them); otherwise
			// restore to 'pending' instead of bypassing the manual payment verification flow.
			$reactivated_status = ( 'completed' === ( $order['status'] ?? '' ) ) ? 'active' : 'pending';

			$result = $this->update(
				$subscription_id,
				array(
					'status' => $reactivated_status,
				)
			);
			if ( ! $result ) {
				return array(
					'status'  => false,
					'message' => __( 'Failed to update subscription.', 'user-registration' ),
				);
			}
			return array(
				'status' => true,
			);
		}
		return array(
			'status' => false,
		);
	}

	/**
	 * Query subscriptions.
	 *
	 * @param array $args {
	 *     Optional. Array of query arguments.
	 *
	 *     @type int    $page           Current page number. Default 1.
	 *     @type int    $per_page       Number of items per page. Default 20.
	 *     @type string $orderby        Column to order by. Default 'ID'.
	 *     @type string $order          Order direction (ASC|DESC). Default 'DESC'.
	 *     @type string $status         Filter by status. Default empty (all).
	 *     @type int    $user_id        Filter by user ID. Default 0 (all).
	 *     @type int    $item_id        Filter by item ID. Default 0 (all).
	 *     @type string $start_date     Filter by start date (from). Format: Y-m-d. Default empty.
	 *     @type string $end_date       Filter by end date (to). Format: Y-m-d. Default empty.
	 *     @type string $search         Search in subscription_id or email. Default empty.
	 * }
	 * @return array Array containing 'items' and 'total' count
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'page'       => 1,
			'per_page'   => 20,
			'orderby'    => 'ID',
			'order'      => 'DESC',
			'status'     => '',
			'user_id'    => 0,
			'item_id'    => 0,
			'start_date' => '',
			'end_date'   => '',
			'search'     => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table )
		);

		if ( $table_exists !== $this->table ) {
			return array(
				'items'        => array(),
				'total'        => 0,
				'total_pages'  => 0,
				'current_page' => 1,
				'per_page'     => absint( $args['per_page'] ),
			);
		}

		$args['order'] = strtoupper( $args['order'] );
		$args['order'] = in_array( $args['order'], array( 'ASC', 'DESC' ), true ) ? $args['order'] : 'DESC';

		$allowed_orderby = array(
			'ID',
			'item_id',
			'user_id',
			'start_date',
			'expiry_date',
			'next_billing_date',
			'billing_cycle',
			'trial_start_date',
			'trial_end_date',
			'billing_amount',
			'status',
			'created_at',
			'updated_at',
		);

		if ( ! in_array( $args['orderby'], $allowed_orderby, true ) ) {
			$args['orderby'] = 'ID';
		}

		$where_clauses  = array( '1=1' );
		$prepare_values = array();
		$join_clause    = '';
		$users_table    = TableList::users_table();

		if ( ! empty( $args['status'] ) ) {
			$where_clauses[]  = 'status = %s';
			$prepare_values[] = $args['status'];
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where_clauses[]  = 'user_id = %d';
			$prepare_values[] = absint( $args['user_id'] );
		}

		if ( ! empty( $args['item_id'] ) ) {
			$where_clauses[]  = 'item_id = %d';
			$prepare_values[] = absint( $args['item_id'] );
		}

		if ( ! empty( $args['start_date'] ) ) {
			$where_clauses[]  = 'DATE(start_date) >= %s';
			$prepare_values[] = sanitize_text_field( $args['start_date'] );
		}

		if ( ! empty( $args['end_date'] ) ) {
			$where_clauses[]  = 'DATE(start_date) <= %s';
			$prepare_values[] = sanitize_text_field( $args['end_date'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$join_clause      = "LEFT JOIN {$users_table} u ON {$this->table}.user_id = u.ID";
			$where_clauses[]  = '(subscription_id LIKE %s OR u.user_email LIKE %s or u.user_login LIKE %s)';
			$search_term      = '%' . $this->wpdb()->esc_like( $args['search'] ) . '%';
			$prepare_values[] = $search_term;
			$prepare_values[] = $search_term;
			$prepare_values[] = $search_term;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$count_query = "SELECT COUNT(*) FROM {$this->table} {$join_clause} WHERE {$where_sql}";

		if ( ! empty( $prepare_values ) ) {
			$count_query = $this->wpdb()->prepare( $count_query, $prepare_values );
		}

		$total_items = (int) $this->wpdb()->get_var( $count_query );

		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$query = "SELECT {$this->table}.* FROM {$this->table} {$join_clause}
			WHERE {$where_sql}
			ORDER BY {$this->table}.{$args['orderby']} {$args['order']}
			LIMIT %d OFFSET %d";

		$prepare_values[] = $per_page;
		$prepare_values[] = $offset;

		$query = $this->wpdb()->prepare( $query, $prepare_values );
		$items = $this->wpdb()->get_results( $query );

		return array(
			'items'        => $items,
			'total'        => $total_items,
			'total_pages'  => ceil( $total_items / $per_page ),
			'current_page' => $page,
			'per_page'     => $per_page,
		);
	}
}
