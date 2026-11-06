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

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names come from TableList.
		$order_ids = $this->wpdb()->get_col( $this->wpdb()->prepare( "SELECT ID FROM $this->table WHERE user_id = %d ORDER BY ID DESC", $member_id ) );

		if ( '' !== $this->wpdb()->last_error ) {
			return false;
		}

		// Only the latest order for the member, unless all of them are asked for.
		if ( ! $delete_all ) {
			$order_ids = array_slice( $order_ids, 0, 1 );
		}

		if ( empty( $order_ids ) ) {
			return false;
		}

		$order_ids_list = implode( ',', array_map( 'absint', $order_ids ) );

		// Without foreign keys the meta goes first; the same ID set is used for both deletes so an order created meanwhile is left whole.
		if ( false === $this->wpdb()->query( 'DELETE FROM ' . TableList::order_meta_table() . " WHERE order_id IN ( $order_ids_list )" ) ) {
			return false;
		}

		$deleted = $this->wpdb()->query( "DELETE FROM $this->table WHERE ID IN ( $order_ids_list )" );
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
