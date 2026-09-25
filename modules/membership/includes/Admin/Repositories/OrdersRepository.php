<?php

namespace WPEverest\URMembership\Admin\Repositories;

use WPEverest\URMembership\Admin\Interfaces\OrdersInterface;
use WPEverest\URMembership\TableList;

class OrdersRepository extends BaseRepository implements OrdersInterface {
	/**
	 * @var string
	 */
	protected $table, $posts_table, $post_meta_table, $users_table, $subscriptions_table, $orders_meta_table;

	/**
	 * Constructor of this class
	 */
	public function __construct() {
		$this->table               = TableList::orders_table();
		$this->posts_table         = TableList::posts_table();
		$this->post_meta_table     = TableList::posts_meta_table();
		$this->users_table         = TableList::users_table();
		$this->subscriptions_table = TableList::subscriptions_table();
		$this->orders_meta_table   = TableList::order_meta_table();
	}

	/**
	 * Get all Orders
	 *
	 * @param $args
	 *
	 * @return array|object|\stdClass[]
	 */
	public function get_all( $args ) {
		global $wpdb;

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table )
		);

		if ( $table_exists !== $this->table ) {
			return array(
				'items'        => array(),
				'total'        => 0,
				'total_pages'  => 0,
				'current_page' => 1,
				'per_page'     => absint( $args['per_page'] ?? 20),
			);
		}

		$sql = "
					SELECT urmo.ID AS order_id,
						wpp.ID as post_id,
						urmo.transaction_id,
						wpu.display_name,
						wpp.post_title,
						wpp.post_content,
						urmo.payment_method,
						wpu.user_email,
						urmo.status,
						urmo.total_amount,
						urmo.created_at,
						urmo.subscription_id,
						urmo.order_type
					FROM $this->table urmo
					JOIN $this->posts_table wpp ON urmo.item_id = wpp.ID
					JOIN $this->users_table wpu ON urmo.user_id = wpu.ID
					WHERE 1 = 1
				";
		if ( isset( $args['membership_id'] ) ) {
			$sql .= $wpdb->prepare( ' AND wpp.ID = %d', $args['membership_id'] );
		}
		if ( isset( $args['s'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['s'] ) . '%';
			$sql   .= $wpdb->prepare(
				' AND (wpu.display_name LIKE %s OR wpu.user_email LIKE %s OR urmo.transaction_id LIKE %s)',
				$search,
				$search,
				$search
			);
		}
		if ( isset( $args['payment_method'] ) ) {
			$sql .= $wpdb->prepare( ' AND urmo.payment_method = %s', $args['payment_method'] );
		}
		if ( isset( $args['status'] ) ) {
			$sql .= $wpdb->prepare( ' AND urmo.status = %s', $args['status'] );
		}

		$allowed_orderby = array( 'created_at', 'status', 'ID' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$sql            .= sprintf( ' ORDER BY %s %s', $orderby, $order );

		$result = $this->wpdb()->get_results( $sql, ARRAY_A );

		return ! $result ? array() : $result;
	}

	/**
	 * Create a new order
	 *
	 * @param $data
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function create( $data ) {
		$result   = $this->wpdb()->insert(
			$this->table,
			$data['orders_data']
		);
		$order_id = $this->wpdb()->insert_id;
		if ( isset( $data['orders_meta_data'] ) && ! empty( $data['orders_meta_data'] ) ) {
			foreach ( $data['orders_meta_data'] as $order_meta ) {
				$order_meta['order_id'] = $order_id;
				$this->wpdb()->insert(
					TableList::order_meta_table(),
					$order_meta
				);
			}
		}

		return $this->retrieve( $order_id );
	}

	/**
	 * Get order details
	 *
	 * @param $order_id
	 *
	 * @return array|object|\stdClass|void
	 */
	public function get_order_detail( $order_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"
				SELECT urmo.ID AS order_id,
					wpp.ID as post_id,
					wpu.ID as user_id,
					urmo.subscription_id as subscription_id,
					urmo.transaction_id,
					wpm.meta_value as plan_details,
					wpu.user_nicename,
					wpu.display_name,
					wpu.user_email,
					wpp.post_title,
					wpp.post_content,
					urmo.payment_method,
					urmo.status,
					urmo.notes,
					urmo.trial_status,
					urmo.total_amount,
					urms.status as subscription_status,
					urms.trial_start_date,
					urms.trial_end_date,
					urms.start_date as subscription_start_date,
					urms.expiry_date,
					urms.next_billing_date,
					urms.billing_cycle,
					urms.billing_amount,
					urms.coupon,
					urmo.created_at
				FROM $this->table urmo
					JOIN $this->posts_table wpp ON urmo.item_id = wpp.ID
					JOIN $this->post_meta_table wpm ON wpp.ID = wpm.post_id
					JOIN $this->users_table wpu ON urmo.user_id = wpu.ID
					JOIN $this->subscriptions_table urms ON urmo.subscription_id = urms.ID
				WHERE wpm.meta_key = 'ur_membership'
				AND urmo.ID = %d
		",
				$order_id
			),
			ARRAY_A
		);

		$orders_meta_table = TableList::order_meta_table();
		$payment_date      = $this->wpdb()->get_var(
			$this->wpdb()->prepare(
				"SELECT meta_value FROM {$orders_meta_table} WHERE meta_key=%s AND order_id=%d LIMIT 1",
				'payment_date',
				$order_id
			)
		);

		if ( ! empty( $payment_date ) ) {
			$result['created_at'] = $payment_date;
		}

		return ! $result ? array() : $result;
	}

	public function get_order_metas( $order_id ) {
		$ordermeta_table = $this->wpdb()->prefix . 'ur_membership_ordermeta';

		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"
				SELECT wpom.*
				FROM $this->table urmo
				JOIN $ordermeta_table wpom ON urmo.ID = wpom.order_id
				WHERE urmo.ID = %d
				AND wpom.meta_key = 'delayed_until'
				AND wpom.meta_value > NOW()
				ORDER BY urmo.ID DESC
				",
				$order_id
			),
			ARRAY_A
		);

		return ! $result ? array() : $result;
	}

	/**
	 * Get specific order by their subscription ID
	 *
	 * @param $subscription_id
	 *
	 * @return array|mixed|object|\stdClass|void
	 */
	public function get_order_by_subscription( $subscription_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"
				SELECT * from $this->table
				WHERE subscription_id = %d
				ORDER BY ID DESC LIMIT 1
		",
				$subscription_id
			),
			ARRAY_A
		);

		return ! $result ? array() : $result;
	}

	/**
	 * Get order by transaction ID (e.g. Stripe payment intent id).
	 *
	 * @param string $transaction_id Transaction ID.
	 * @return array Order row or empty array if not found.
	 */
	public function get_order_by_transaction_id( $transaction_id ) {
		if ( empty( $transaction_id ) ) {
			return array();
		}
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT * FROM $this->table WHERE transaction_id = %s LIMIT 1",
				$transaction_id
			),
			ARRAY_A
		);

		return ! $result ? array() : $result;
	}

	public function get_all_delayed_orders( $date ) {
		$sql = sprintf(
			"
					SELECT
					       wpum.meta_value as sub_data
					FROM wp_ur_membership_orders urmo
					         JOIN wp_ur_membership_ordermeta wpom ON urmo.ID = wpom.order_id
					         JOIN wp_usermeta wpum ON urmo.user_id = wpum.user_id
					WHERE wpom.meta_key = 'delayed_until'
					  AND wpom.meta_value = '%s'
					  AND wpum.meta_key = 'urm_next_subscription_data'
				",
			$date
		);

		$result = $this->wpdb()->get_results( $sql, ARRAY_A );

		return ! $result ? array() : $result;
	}

	public function delete_order_meta( $conditions ) {
		$result = $this->wpdb()->delete( $this->orders_meta_table, $conditions );
		return ! $result ? array() : $result;
	}

	public function get_order_meta_by_order_id_and_meta_key( $order_id, $meta_key ) {
		$ordermeta_table = $this->wpdb()->prefix . 'ur_membership_ordermeta';

		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"
				SELECT *
				FROM {$ordermeta_table}
				WHERE order_id = %d
				AND meta_key = %s
				LIMIT 1
				",
				$order_id,
				$meta_key
			),
			ARRAY_A
		);

		return ! $result ? array() : $result;
	}

	public function update_order_meta( $order_meta ) {
		$this->wpdb()->insert(
			TableList::order_meta_table(),
			$order_meta
		);
	}

	/**
	 * Get pending PayPal one-time payment orders created on or after a given timestamp.
	 *
	 * @param int $since Unix timestamp.
	 * @return array
	 */
	public function get_pending_paypal_one_time_orders() {
		$result = $this->wpdb()->get_results(
			"SELECT * FROM {$this->table}
			 WHERE payment_method = 'paypal'
			 AND order_type = 'paid'
			 AND status = 'pending'",
			ARRAY_A
		);

		return $result ? $result : array();
	}

	/**
	 * Get completed PayPal one-time orders whose linked subscription is still pending.
	 * Used by the backfill to activate subscriptions when the order completed but
	 * the subscription activation step was missed.
	 *
	 * @return array
	 */
	public function get_completed_paypal_onetime_with_pending_subscription() {
		$subs_table = $this->wpdb()->prefix . 'ur_membership_subscriptions';

		$result = $this->wpdb()->get_results(
			"SELECT o.*
			 FROM {$this->table} o
			 JOIN {$subs_table} s ON s.ID = o.subscription_id
			 WHERE o.payment_method = 'paypal'
			 AND o.order_type = 'paid'
			 AND o.status = 'completed'
			 AND s.status = 'pending'",
			ARRAY_A
		);

		return $result ? $result : array();
	}
}
