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

		if ( $delete_all ) {
			// Delete all orders for the member
			$deleted = $this->wpdb()->query(
				$this->wpdb()->prepare(
					"DELETE FROM $this->table WHERE user_id = %d",
					$member_id
				)
			);
		} else {
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

	public function does_transaction_id_exists( $transaction_id, $order_id ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE transaction_id = %s AND id != %d LIMIT 1",
				$transaction_id,
				$order_id
			)
		);

		return ! empty( $result );
	}
}
