<?php

namespace WPEverest\URMembership\Admin\Repositories;

use DateTime;
use WPEverest\URMembership\Admin\Interfaces\MembersInterface;
use WPEverest\URMembership\Admin\Interfaces\MembersOrderInterface;
use WPEverest\URMembership\TableList;

class MembersOrderRepository extends BaseRepository implements MembersOrderInterface {
	/**
	 * @var string
	 */
	protected $table, $users_table;

	/**
	 *
	 */
	public function __construct() {
		$this->table       = TableList::orders_table();
		$this->users_table = TableList::users_table();
	}

	/**
	 * get_member_orders
	 *
	 * @param $member_id
	 *
	 * @return array|false|object|\stdClass|void
	 */
	public function get_member_orders( $member_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT wumo.* FROM $this->users_table wpu
		         JOIN $this->table  wumo ON wpu.ID = wumo.user_id
		         WHERE wpu.ID = %d  ORDER BY wumo.created_at DESC",
				$member_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Mark a pending order completed, only if nobody else has done so first.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $transaction_id Gateway transaction ID.
	 *
	 * @return bool True for the one caller that moved the order from pending to completed.
	 */
	public function complete_pending_order( $order_id, $transaction_id ) {
		// phpcs:disable WordPress.DB.PreparedSQL -- Bound by prepare(); the sniff trips on the internal table name.
		$updated = $this->wpdb()->query(
			$this->wpdb()->prepare(
				"UPDATE $this->table SET status = 'completed', transaction_id = %s WHERE ID = %d AND status = 'pending'",
				$transaction_id,
				$order_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return 1 === $updated;
	}

	/**
	 * get_member_all_orders
	 *
	 * @param $member_id
	 *
	 * @return array|false|object|\stdClass|void
	 */
	public function get_member_all_orders( $member_id ) {
		$result = $this->wpdb()->get_results(
			$this->wpdb()->prepare(
				"SELECT wumo.* FROM $this->users_table wpu
		         JOIN $this->table  wumo ON wpu.ID = wumo.user_id
		         WHERE wpu.ID = %d  ORDER BY wumo.created_at DESC",
				$member_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	public function delete_member_order( $member_id, $delete_all = true ) {
		( new OrdersRepository() )->release_member_coupon_claims( $member_id, ! $delete_all );

		$orders_meta_table = TableList::order_meta_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names come from TableList.
		if ( $delete_all ) {
			$meta_deleted = $this->wpdb()->query(
				$this->wpdb()->prepare(
					"DELETE m FROM $orders_meta_table m INNER JOIN $this->table o ON o.ID = m.order_id WHERE o.user_id = %d",
					$member_id
				)
			);

			if ( false === $meta_deleted ) {
				return false;
			}

			// Delete all orders for the member
			$deleted = $this->wpdb()->query(
				$this->wpdb()->prepare(
					"DELETE FROM $this->table WHERE user_id = %d",
					$member_id
				)
			);
		} else {
			$meta_deleted = $this->wpdb()->query(
				$this->wpdb()->prepare(
					"DELETE FROM $orders_meta_table WHERE order_id = ( SELECT ID FROM ( SELECT ID FROM $this->table WHERE user_id = %d ORDER BY ID DESC LIMIT 1 ) AS latest )",
					$member_id
				)
			);

			if ( false === $meta_deleted ) {
				return false;
			}

			// Delete only the latest order for the member

			$deleted = $this->wpdb()->query(
				$this->wpdb()->prepare(
					"DELETE FROM $this->table
                 WHERE id = (
                     SELECT id FROM (
                         SELECT id FROM $this->table
                         WHERE user_id = %d
                         ORDER BY id DESC
                         LIMIT 1
                     ) AS latest
                 )",
					$member_id
				)
			);
		}

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		return $deleted !== false && $deleted > 0;
	}

	public function create_member_order( $order_data ) {
		$data = array(
			'user_id'        => absint( $order_data['ur_member_id'] ),
			'item_id'        => absint( $order_data['ur_membership_plan'] ),
			'total_amount'   => floatval( $order_data['ur_membership_amount'] ),
			'status'         => sanitize_text_field( $order_data['ur_transaction_status'] ),
			'payment_method' => 'manual',
			'created_by'     => get_current_user_id(),
			'created_at'     => date( 'Y-m-d H:i:s', strtotime( $order_data['ur_payment_date'] ) ),
		);

		$inserted = $this->wpdb()->insert(
			$this->table,
			$data,
			array( '%d', '%d', '%f', '%s', '%s', '%d', '%s' ),
		);
		if ( $inserted ) {
			$order_id = $this->wpdb()->insert_id;
			return $order_id;
		}
		return false;
	}

	/**
	 * Check whether another order already holds a transaction ID.
	 *
	 * @since 5.3.2
	 *
	 * @param string $transaction_id Transaction ID.
	 * @param int    $order_id       Order ID to leave out, 0 to check every order.
	 * @param string $status         Only match orders with this status, empty for any.
	 * @return bool
	 */
	public function does_transaction_id_exists( $transaction_id, $order_id, $status = '' ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE transaction_id = %s AND id != %d AND ( %s = '' OR status = %s ) LIMIT 1",
				$transaction_id,
				$order_id,
				$status,
				$status
			)
		);

		return ! empty( $result );
	}
}
