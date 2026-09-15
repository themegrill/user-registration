<?php

namespace WPEverest\URMembership\Admin\Repositories;

use WPEverest\URMembership\Admin\Interfaces\MembersSubscriptionInterface;
use WPEverest\URMembership\TableList;

class MembersSubscriptionRepository extends BaseRepository implements MembersSubscriptionInterface {
	/**
	 * @var string
	 */
	protected $table, $users_table, $posts_table, $posts_meta_table, $orders_table;

	/**
	 * Constructor of this class
	 */
	public function __construct() {
		$this->table            = TableList::subscriptions_table();
		$this->users_table      = TableList::users_table();
		$this->posts_table      = TableList::posts_table();
		$this->posts_meta_table = TableList::posts_meta_table();
		$this->orders_table     = TableList::orders_table();
	}

	/**
	 * Activate subscriptions that were left 'pending' even though their order is 'completed'.
	 *
	 * The real-time payment confirmation can mark an order completed without flipping the linked
	 * subscription to active, and the event-window backfill skips orders that are already
	 * completed — leaving such subscriptions stranded as 'pending' forever. A completed order is
	 * proof of payment, so this heals them. Gateway-agnostic; runs off local data only.
	 *
	 * @return int Number of subscriptions activated.
	 */
	public function activate_pending_with_completed_orders() {
		// Only activate subscriptions whose start date has already arrived, so a future-dated /
		// scheduled subscription is never activated early. A completed order with a non-future
		// start date that is still pending is an anomaly left by a partial confirmation.
		$updated = $this->wpdb()->query(
			"UPDATE $this->table s
			 JOIN $this->orders_table o ON o.subscription_id = s.ID AND o.status = 'completed'
			 SET s.status = 'active'
			 WHERE s.status = 'pending'
			   AND s.start_date IS NOT NULL
			   AND s.start_date <= NOW()"
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Get members subscription by their ID
	 *
	 * @param $member_id
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function get_member_subscription( $member_id ) {
		$result = $this->wpdb()->get_results(
			$this->wpdb()->prepare(
				"SELECT wums.* FROM $this->users_table wpu
		         JOIN $this->table wums ON wpu.ID = wums.user_id
		         WHERE wpu.ID = %d",
				$member_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	// TODO - Handle Multiple ( Remove after multiple memberships merge )
	/**
	 * Get members subscription by their ID
	 *
	 * @param $member_id
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function get_member_subscriptions( $member_id ) {
		$result = $this->wpdb()->get_results(
			$this->wpdb()->prepare(
				"SELECT wums.* FROM $this->users_table wpu
		         JOIN $this->table wums ON wpu.ID = wums.user_id
		         WHERE wpu.ID = %d",
				$member_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Get members subscription by their subscription ID
	 *
	 * @param $subscription_id
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function get_subscription_data_by_subscription_id( $subscription_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT * FROM $this->table WHERE ID = %d",
				$subscription_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Get members subscription by their ID and Membership ID
	 *
	 * @param $member_id
	 * @param $membership_id
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function get_subscription_data_by_member_and_membership_id( $member_id, $membership_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT wums.* FROM $this->users_table wpu
		         JOIN $this->table wums ON wpu.ID = wums.user_id
		         WHERE wpu.ID = %d AND wums.item_id = %d",
				$member_id,
				$membership_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Get membership by members subscription ID.
	 *
	 * @param $subscription_id
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function get_membership_by_subscription_id( $subscription_id, $secondary = false ) {
		$compare_id = ! $secondary ? 'wpus.ID = %d' : 'wpus.subscription_id = %s';

		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT wpp.ID,
                	   wpus.user_id,
                	   wpus.ID as sub_id,
                	   wpus.item_id,
                	   wpus.status,
				       wpp.post_title,
				       wpp.post_content,
				       wpp.post_status,
				       wpp.post_type,
				       wpm.meta_value
				FROM $this->table wpus
				         JOIN $this->posts_table wpp on wpus.item_id = wpp.ID
				         JOIN $this->posts_meta_table wpm on wpm.post_id = wpp.ID
				WHERE wpm.meta_key = 'ur_membership'
				  AND wpp.post_type = 'ur_membership'
				  AND wpp.post_status = 'publish'
				  AND $compare_id",
				$subscription_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Return all subscription which are about to be billed on the specified date
	 *
	 * @param $check_date
	 *
	 * @return array|object|stdClass[]
	 */
	public function get_about_to_expire_subscriptions( $check_date ) {
		$sql = sprintf(
			"
						SELECT wu.user_email,
						       wu.user_login as username,
						       wu.ID as member_id,
						       wp.post_title as membership_plan_name,
						       wums.item_id as membership,
						       wums.ID as subscription_id,
						       wums.next_billing_date,
						       wums.expiry_date
						FROM  $this->table wums
					    LEFT JOIN $this->users_table wu ON wums.user_id = wu.ID
					    LEFT JOIN $this->posts_table wp ON wums.item_id = wp.ID
						WHERE NOT wums.status = 'canceled'
						AND DATE(wums.next_billing_date) = DATE('%s')
						",
			$check_date
		);

		$result = $this->wpdb()->get_results( $sql, ARRAY_A );

		if ( ! $result ) {
			return array();
		}

		// PHP filter, not a SQL join - the per-subscription usermeta key can't be indexed and forces a full table scan.
		return array_values(
			array_filter(
				$result,
				function ( $subscription ) {
					return ! get_user_meta( $subscription['member_id'], 'urm_pending_cancel_' . $subscription['subscription_id'], true );
				}
			)
		);
	}

	/**
	 * Return all subscription which are about to be billed on the specified date
	 *
	 * @param $check_date
	 *
	 * @return array|object|stdClass[]
	 */
	public function get_expired_subscriptions( $check_date ) {
		$sql = sprintf(
			"
						SELECT wu.user_email,
						       wu.user_login as username,
						       wu.ID as member_id,
						       wp.post_title as membership_plan_name,
						       wums.item_id as membership,
						       wums.ID as subscription_id,
						       wums.next_billing_date,
						       wums.expiry_date
						FROM  $this->table wums
					    LEFT JOIN $this->users_table wu ON wums.user_id = wu.ID
					    LEFT JOIN $this->posts_table wp ON wums.item_id = wp.ID
						WHERE wums.status = 'expired'
						AND wums.expiry_date >= '%s'
						",
			$check_date
		);

		$result = $this->wpdb()->get_results( $sql, ARRAY_A );

		return ! $result ? array() : $result;
	}

	/**
	 * Return all active subscriptions that have passed their expiry date
	 *
	 * @param $check_date
	 *
	 * @return array|object|stdClass[]
	 */
	public function get_subscriptions_to_expire( $check_date ) {
		$sql = sprintf(
			"
						SELECT wu.user_email,
						       wu.user_login as username,
						       wu.ID as member_id,
						       wp.post_title as membership_plan_name,
						       wums.item_id as membership,
						       wums.ID as subscription_id,
						       wums.subscription_id as gateway_subscription_id,
						       wums.next_billing_date,
						       wums.expiry_date
						FROM  $this->table wums
					    LEFT JOIN $this->users_table wu ON wums.user_id = wu.ID
					    LEFT JOIN $this->posts_table wp ON wums.item_id = wp.ID
						WHERE wums.status = 'active'
						AND wums.expiry_date <= '%s'
						",
			$check_date
		);

		$result = $this->wpdb()->get_results( $sql, ARRAY_A );

		return ! $result ? array() : $result;
	}

	/**
	 * Get subscription by subscription ID
	 *
	 * @param int $subscription_id The Subscription ID.
	 *
	 * @return array|false|mixed|object|\stdClass|void
	 */
	public function get_subscription_by_subscription_id( $subscription_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT wums.* FROM $this->table wums
		         WHERE wums.ID = %d",
				$subscription_id
			),
			ARRAY_A
		);

		return ! $result ? false : $result;
	}

	/**
	 * Fetches the list of subscriptions to retry based on payment retry settings and billing expiry.
	 */
	public function get_subscriptions_to_retry() {
		// Check if payment retry is enabled
		if ( ! ur_string_to_bool( get_option( 'user_registration_payment_retry_enabled', 'no' ) ) ) {
			return array();
		}

		// Get retry settings
		$retry_count    = (int) get_option( 'user_registration_payment_retry_count', 3 );
		$retry_interval = (int) get_option( 'user_registration_payment_retry_interval', 3 );

		// Calculate a retry window in days
		$retry_window_days = $retry_count * $retry_interval;

		// Calculate the date from which we need to fetch subscriptions
		// We want subscriptions updated within the last X days based on the retry window
		$current_date = current_time( 'mysql' );
		$check_date   = date( 'Y-m-d H:i:s', strtotime( "-{$retry_window_days} days", strtotime( $current_date ) ) );

		$sql = sprintf(
			"
			SELECT wu.user_email,
			       wu.user_login as username,
			       wu.ID as member_id,
				   wo.payment_method,
				   wo.status as order_status,
				   wo.order_type,
			       wp.post_title as membership_plan_name,
			       wums.item_id as membership,
			       wums.ID as subscription_id,
				   wums.subscription_id as sub_id,
			       wums.status,
			       wums.updated_at
			FROM $this->table wums
			LEFT JOIN $this->users_table wu ON wums.user_id = wu.ID
			LEFT JOIN $this->posts_table wp ON wums.item_id = wp.ID
			LEFT JOIN $this->orders_table wo ON wums.ID = wo.subscription_id
			WHERE (wums.status = 'failed' OR wums.status = 'expired')
			AND wums.updated_at >= '%s'
			ORDER BY wums.updated_at ASC
			",
			$check_date
		);

		$result = $this->wpdb()->get_results( $sql, ARRAY_A );

		return ! $result ? array() : $result;
	}

	public function get_subscription_by_subscription_id_meta( $subscription_id ) {
		$result = $this->wpdb()->get_row(
			$this->wpdb()->prepare(
				"SELECT wums.* FROM {$this->table} wums
				WHERE wums.subscription_id = %s",
				$subscription_id
			),
			ARRAY_A
		);

		return $result ? $result : false;
	}

	/**
	 * Get all subscriptions paid via PayPal REST that have a PayPal subscription ID stored.
	 *
	 * @return array
	 */
	public function get_paypal_subscriptions_for_backfill() {
		$result = $this->wpdb()->get_results(
			"SELECT DISTINCT wums.*
			 FROM {$this->table} wums
			 LEFT JOIN {$this->orders_table} wo ON wums.ID = wo.subscription_id
			 WHERE (
			     wo.payment_method = 'paypal'
			     OR wums.subscription_id LIKE 'I-%'
			 )
			 AND wums.subscription_id != ''
			 AND wums.subscription_id IS NOT NULL",
			ARRAY_A
		);

		return $result ? $result : array();
	}
}
