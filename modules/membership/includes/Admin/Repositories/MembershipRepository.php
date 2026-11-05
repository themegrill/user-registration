<?php

namespace WPEverest\URMembership\Admin\Repositories;

use WPEverest\URMembership\Admin\Interfaces\MembershipInterface;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\TableList;

class MembershipRepository extends BaseRepository implements MembershipInterface {
	protected $table, $members_meta, $posts_meta_table, $subscription_table;

	public function __construct() {
		$this->table              = TableList::posts_table();
		$this->members_meta       = TableList::users_meta_table();
		$this->posts_meta_table   = TableList::posts_meta_table();
		$this->subscription_table = TableList::subscriptions_table();
	}


	/**
	 * @return array
	 */
	public function get_all_membership() {
		// TODO : maybe change this raw queries to wp_Query
		global $wpdb;
		$sql = "
				SELECT wpp.ID,
				       wpp.post_title,
				       wpp.post_content,
				       wpp.post_status,
				       wpp.post_type,
				       wpm.meta_value
				FROM $this->table wpp
				         JOIN $this->posts_meta_table wpm on wpm.post_id = wpp.ID
				WHERE wpm.meta_key = 'ur_membership'
				  AND wpp.post_type = 'ur_membership'
				  AND wpp.post_status = 'publish'
				ORDER BY 1 DESC
		";

		$memberships        = $wpdb->get_results(
			$sql,
			ARRAY_A
		);
		$membership_service = new MembershipService();
		return $membership_service->prepare_membership_data( $memberships );
	}

	/**
	 * Get all memberships without status filter for content restriction
	 *
	 * @return array
	 */
	public function get_all_memberships_without_status_filter() {
		global $wpdb;
		$sql = "
				SELECT wpp.ID,
				       wpp.post_title,
				       wpp.post_content,
				       wpp.post_status,
				       wpp.post_type,
				       wpm.meta_value
				FROM $this->table wpp
				         JOIN $this->posts_meta_table wpm on wpm.post_id = wpp.ID
				WHERE wpm.meta_key = 'ur_membership'
				  AND wpp.post_type = 'ur_membership'
				ORDER BY 1 DESC
		";

		$memberships = $wpdb->get_results(
			$sql,
			ARRAY_A
		);

		$membership_service = new MembershipService();
		return $membership_service->prepare_membership_data_without_status_filter( $memberships );
	}

	/**
	 * get_single_membership_by_ID
	 *
	 * @param $id
	 *
	 * @return array|object|\stdClass|void|null
	 */
	public function get_single_membership_by_ID( $id ) {
		// TODO : maybe change this raw queries to wp_Query
		global $wpdb;

		return $wpdb->get_row(
			$this->wpdb()->prepare(
				"SELECT wpp.ID,
				       wpp.post_title,
				       wpp.post_content,
				       wpp.post_status,
				       wpp.post_type,
				       wpm.meta_value
				FROM $this->table wpp
				         JOIN $this->posts_meta_table wpm on wpm.post_id = wpp.ID
				WHERE wpm.meta_key = 'ur_membership'
				  AND wpp.post_type = 'ur_membership'
				  AND wpp.post_status = 'publish'
				AND wpp.ID = %d
				ORDER BY 1 DESC",
				$id
			),
			ARRAY_A
		);
	}


	public function get_multiple_membership_by_ID( $ids, $order = true ) {
		global $wpdb;

		if ( ! is_array( $ids ) ) {
			$ids = explode( ',', $ids );
		}

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "
			SELECT wpp.ID,
				wpp.post_title,
				wpp.post_content,
				wpp.post_status,
				wpp.post_type,
				wpm.meta_value
			FROM {$this->table} wpp
			INNER JOIN {$this->posts_meta_table} wpm
				ON wpm.post_id = wpp.ID
			WHERE wpm.meta_key = 'ur_membership'
			AND wpp.post_type = 'ur_membership'
			AND wpp.post_status = 'publish'
			AND wpp.ID IN ($placeholders)
		";

		if ( $order ) {
			$sql .= ' ORDER BY wpp.ID DESC';
			$query_args = $ids;
		} else {
			$sql .= " ORDER BY FIELD(wpp.ID, $placeholders)";
			$query_args = array_merge( $ids, $ids );
		}

		$query = $wpdb->prepare( $sql, $query_args );

		$memberships = $wpdb->get_results( $query, ARRAY_A );

		$membership_service = new MembershipService();
		return $membership_service->prepare_membership_data( $memberships );
	}

	/**
	 * replace_old_form_shortcode_with_new
	 *
	 * @param $form_id
	 *
	 * @return bool
	 */
	public function replace_old_form_shortcode_with_new( $form_id ) {
		global $wpdb;
		$new_shortcode = '[user_registration_form id="' . $form_id . '"]';

		$sql = "
				SELECT ID, post_title, post_content
				FROM $this->table
				WHERE post_content LIKE '%[user_registration_membership_member_registration_form]%'
				  AND post_type = 'page';
				";

		$results = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $results ) ) {
			foreach ( $results as $post ) {
				$updated_content = str_replace(
					'[user_registration_membership_member_registration_form]',
					$new_shortcode,
					$post['post_content']
				);
				$wpdb->update(
					$this->table,
					array( 'post_content' => $updated_content ),
					array( 'ID' => $post['ID'] ),
					array( '%s', '%d' )
				);

			}
		} else {
			return false;
		}

		return true;
	}

	/**
	 * get_membership_forms
	 *
	 * @return array|mixed|object|\stdClass[]|null
	 */
	public function get_membership_forms() {
		$like = '"field_key":"membership"';
		$sql  = "
				 SELECT *
			    FROM $this->table
			    WHERE post_type = 'user_registration'
			    AND post_status = 'publish'
			    AND post_content LIKE '%" . $like . "%'
				";

		return $this->wpdb()->get_results( $sql, ARRAY_A );
	}

	/**
	 * assign_users_to_new_form
	 *
	 * @param $form_id
	 *
	 * @return void
	 */
	public function assign_users_to_new_form( $form_id ) {
		$sql = "
				 SELECT user_id
			    FROM $this->members_meta
			    WHERE meta_key = 'ur_registration_source'
			    AND meta_value = 'membership'
				";

		$users = $this->wpdb()->get_results( $sql, ARRAY_A );
		if ( empty( $users ) ) {
			return;
		}
		foreach ( $users as $user ) {

			if ( ! add_user_meta( $user['user_id'], 'ur_form_id', $form_id, true ) ) {
				// If the meta already exists, update it
				update_user_meta( $user['user_id'], 'ur_form_id', $form_id );
			}
		}
	}

	/**
	 * check_deletable_membership
	 *
	 * @param $id
	 * @param $statuses
	 *
	 * @return array|false|object|\stdClass|null
	 */
	public function check_deletable_membership( $id, $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return false;
		}

		$placeholders = array();
		$values       = array();

		foreach ( $statuses as $status ) {
			$placeholders[] = '%s';
			$values[]       = trim( $status );
		}

		$placeholders_string = implode( ',', $placeholders );
		$values              = array_merge( array( $id ), $values );

		return $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT COUNT(DISTINCT user_id) total from $this->subscription_table
        		WHERE item_id = %d AND status IN  ($placeholders_string)",
				$values
			),
			ARRAY_A
		);
	}

	/**
	 * Count distinct members per membership plan for one subscription status.
	 *
	 * @since 5.3.0
	 *
	 * @param string $status Subscription status.
	 * @return array Membership ID => member count.
	 */
	public function count_members_by_membership( $status ) {
		global $wpdb;
		$sql  = "SELECT item_id, COUNT(DISTINCT user_id) AS total FROM {$this->subscription_table} WHERE status = %s GROUP BY item_id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Internal table name, status is prepared.
		return array_map( 'intval', array_column( $rows, 'total', 'item_id' ) );
	}

	/**
	 * Delete membership post using wp_delete_post to trigger WordPress hooks
	 *
	 * @param int $id Membership post ID
	 * @return bool|int|\mysqli_result|null
	 */
	public function delete( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$post = get_post( $id );
		if ( ! $post || 'ur_membership' !== $post->post_type ) {
			return false;
		}

		$result = wp_delete_post( $id, true );
		return $result ? true : false;
	}

	/**
	 * Delete multiple memberships using wp_delete_post to trigger WordPress hooks
	 *
	 * @param string $ids Comma-separated membership IDs
	 * @return bool|int|\mysqli_result|null
	 */
	public function delete_multiple( $ids ) {
		if ( empty( $ids ) ) {
			return false;
		}

		$ids_array = explode( ',', $ids );
		$ids_array = array_map( 'absint', $ids_array );
		$ids_array = array_filter( $ids_array );

		if ( empty( $ids_array ) ) {
			return false;
		}

		$deleted_count = 0;
		foreach ( $ids_array as $id ) {
			$result = $this->delete( $id );
			if ( $result ) {
				++$deleted_count;
			}
		}

		return $deleted_count > 0;
	}
}
