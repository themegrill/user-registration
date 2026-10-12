<?php

namespace WPEverest\URMembership\Admin\Repositories;

use WPEverest\URMembership\TableList;

class BaseRepository implements \WPEverest\URMembership\Admin\Interfaces\BaseInterface {
	protected $table;

	/**
	 * Return global wpdb.
	 *
	 * @return \wpdb
	 */
	public function wpdb() {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Insert new Data
	 *
	 * @param $data
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function create( $data ) {
		$result    = $this->wpdb()->insert(
			$this->table,
			$data
		);
		$insert_id = $this->wpdb()->insert_id;

		return $this->retrieve( $insert_id );
	}

	/**
	 * Retrieve specific record by id.
	 *
	 * @param $id
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function retrieve( $id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT * FROM $this->table WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Update specific record by ID
	 *
	 * @param $id
	 * @param $data
	 *
	 * @return bool|int|mixed|\mysqli_result|null
	 */
	public function update( $id, $data ) {
		$result = $this->wpdb()->update(
			$this->table,
			$data,
			array( 'ID' => $id )
		);

		// Every order status change lands here, so a coupon use claimed at checkout is settled in one place.
		if ( false !== $result && isset( $data['status'] ) && TableList::orders_table() === $this->table ) {
			( new OrdersRepository() )->settle_coupon_claim( $id, $data['status'] );
		}

		return $result;
	}

	/**
	 * Delete Multiple record by ID's
	 *
	 * @param $ids
	 *
	 * @return bool|int|\mysqli_result|void|null
	 */
	public function delete_multiple( $ids ) {
		$id_array     = array_map( 'absint', explode( ',', $ids ) );
		$id_array     = array_filter( $id_array );
		if ( empty( $id_array ) ) {
			return false;
		}
		$placeholders = implode( ',', array_fill( 0, count( $id_array ), '%d' ) );

		return $this->wpdb()->query( $this->wpdb()->prepare( "DELETE FROM $this->table WHERE ID IN ($placeholders)", $id_array ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete single record by ID
	 *
	 * @param $id
	 *
	 * @return bool|int|\mysqli_result|null
	 */
	public function delete( $id ) {
		return $this->wpdb()->delete( $this->table, array( 'ID' => $id ) );
	}

	// Returns true if acquired, false on contention/timeout, null if the DB has no GET_LOCK support.
	public function acquire_lock( $name, $timeout = 5 ) {
		$result = $this->wpdb()->get_var( $this->wpdb()->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );

		return null === $result ? null : ( '1' === (string) $result );
	}

	public function release_lock( $name ) {
		return $this->wpdb()->get_var( $this->wpdb()->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
