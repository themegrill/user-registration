<?php

namespace WPEverest\URMembership\Admin\Repositories;

use BlockArt\BlockTypes\Tab;
use WPEverest\URMembership\Admin\Interfaces\MembersInterface;
use WPEverest\URMembership\TableList;

class MembersRepository extends BaseRepository implements MembersInterface {
	/**
	 * @var string
	 */
	protected $table, $subscription_table, $posts_table, $orders_table;

	/**
	 *  Constructor for this class
	 */
	public function __construct() {
		$this->table              = TableList::users_table();
		$this->subscription_table = TableList::subscriptions_table();
		$this->posts_table        = TableList::posts_table();
		$this->orders_table       = TableList::orders_table();
	}

	/**
	 * Create new Membership
	 *
	 * @param $data
	 *
	 * @return false|\WP_Error|\WP_User
	 */
	public function create( $data ) {
		$new_user_id = wp_insert_user( $data['user_data'] );

		// A WP_Error is truthy, so return it before it becomes a WP_User with ID 0.
		if ( is_wp_error( $new_user_id ) ) {
			return $new_user_id;
		}

		if ( $new_user_id ) {
			$user = new \WP_User( $new_user_id );
			update_user_meta( $new_user_id, 'ur_registration_source', 'membership' );

			$user->set_role( $data['role'] );

			if ( isset( $data['coupon_data'] ) && ! empty( $data['coupon_data'] ) ) {
				update_user_meta( $new_user_id, 'ur_coupon_discount_type', $data['coupon_data']['coupon_discount_type'] );
				update_user_meta( $new_user_id, 'ur_coupon_discount', $data['coupon_data']['coupon_discount'] );
			}

			return $user;
		}

		return false;
	}

	/**
	 * update
	 *
	 * @param $data
	 *
	 * @return false|\WP_User
	 */
	public function update( $id, $data ) {
		$updated_user_id = wp_update_user( $data['user_data'] );
		if ( $updated_user_id ) {
			$user = new \WP_User( $updated_user_id );
			if ( ! empty( $data['role'] ) ) {
				$user->set_role( $data['role'] );
			}
			return $user;
		}

		return false;
	}
	/**
	 * Get Member by their membership id.
	 *
	 * @param $id
	 *
	 * @return array|object|\stdClass|void|null
	 */
	public function get_member_membership_by_id( $id ) {
		$result = $this->wpdb()->get_results(
			$this->wpdb()->prepare(
				"SELECT wp.ID as post_id,
                urs.ID as subscription_id,
                -- wo.ID as order_id,
                urs.user_id,
                urs.cancel_sub,
                wp.post_title,
                wp.post_content,
                urs.status,
                urs.trial_start_date,
                urs.trial_end_date,
                urs.billing_amount,
                urs.expiry_date,
                urs.start_date,
                urs.next_billing_date,
                urs.billing_cycle
				FROM $this->subscription_table AS urs
				JOIN $this->posts_table AS wp
						ON wp.ID = urs.item_id
				-- LEFT JOIN $this->orders_table AS wo
				-- 		ON urs.ID = wo.subscription_id
				WHERE urs.user_id = %d
				AND wp.post_type = 'ur_membership'
				ORDER BY urs.ID DESC",
				$id
			),
			ARRAY_A
		);

		return $result;
	}

	public function get_all_members( $args ) {
		global $wpdb;

		$membership_id = isset( $args['membership_id'] ) ? intval( $args['membership_id'] ) : null;
		$search        = ! empty( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$include       = ! empty( $args['include'] ) && is_array( $args['include'] ) ? array_map( 'intval', $args['include'] ) : array();

		$allowed_orderby = array(
			'user_registered',
			'user_login',
			'user_email',
			'post_title',
			'expiry_date',
			'status',
			'ID',
			'subscription_id',
		);
		$order_by        = ! empty( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'user_registered';

		// Only allow ASC or DESC for order
		$order = ( ! empty( $args['order'] ) && strtoupper( $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

		$sql = "
		SELECT
			wpu.ID,
			wpu.user_login,
			wpu.user_email,
			wpu.user_registered,
			wpp.post_title,
			wums.status
		FROM $this->table wpu
		JOIN $this->subscription_table wums ON wpu.ID = wums.user_id
		JOIN $this->posts_table wpp ON wums.item_id = wpp.ID
		WHERE wpp.post_status = 'publish'
		AND 1 = 1
	";

		$prepare_args = array();

		if ( $membership_id ) {
			$sql           .= ' AND wpp.ID = %d';
			$prepare_args[] = $membership_id;
		}
		if ( ! empty( $search ) ) {
			$sql           .= ' AND (wpu.display_name LIKE %s OR wpu.user_email LIKE %s)';
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$prepare_args[] = $like;
			$prepare_args[] = $like;
		}
		if ( ! empty( $include ) ) {
			$in_ids = implode( ',', $include );
			$sql   .= " AND wpu.ID IN ($in_ids)";
			// $include is already sanitized to integers
		}

		$sql .= " ORDER BY $order_by $order";

		// Prepare only if there are arguments to bind
		if ( ! empty( $prepare_args ) ) {
			$sql = $wpdb->prepare( $sql, $prepare_args );
		}

		$rows = $this->wpdb()->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		$final = array();

		foreach ( $rows as $row ) {
			$user_id = $row['ID'];

			if ( ! isset( $final[ $user_id ] ) ) {
				$final[ $user_id ] = array(
					'ID'              => $row['ID'],
					'user_login'      => $row['user_login'],
					'user_email'      => $row['user_email'],
					'user_registered' => $row['user_registered'],
					'subscriptions'   => array(),
					'status'          => 'inactive',
				);
			}

			// Add subscription title
			$final[ $user_id ]['subscriptions'][] = $row['post_title'];

			// If any active → overall status becomes active
			if ( $row['status'] === 'active' ) {
				$final[ $user_id ]['status'] = 'active';
			}
		}

		// Convert subscriptions to comma-separated string
		foreach ( $final as &$user ) {
			$user['subscriptions'] = implode( ', ', $user['subscriptions'] );
		}

		return array_values( $final );
	}

	// TODO - Handle Multiple ( Remove after multiple memberships merge )
	/**
	 * Get Member by their membership id.
	 *
	 * @param $id
	 *
	 * @return array|object|\stdClass|void|null
	 */
	public function get_member_memberships_by_id( $id ) {
		return $this->wpdb()->get_results(
			$this->wpdb()->prepare(
				"SELECT wp.ID as post_id,
                    urs.ID as subscription_id,
                    urs.user_id,
                    urs.cancel_sub,
                    wp.post_title,
                    wp.post_content,
                    urs.status,
                    urs.trial_start_date,
                    urs.trial_end_date,
                    urs.billing_amount,
                    urs.expiry_date,
                    urs.start_date,
                    urs.next_billing_date,
                    urs.billing_cycle
            FROM $this->subscription_table urs
            JOIN $this->posts_table wp ON wp.ID = urs.item_id
            WHERE urs.user_id = %d
              AND wp.post_type = 'ur_membership'",
				$id
			),
			ARRAY_A
		);
	}
}
