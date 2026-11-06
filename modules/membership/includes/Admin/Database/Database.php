<?php
/**
 * URMembership Database.
 *
 * @package  URMembership/Frontend
 */

namespace WPEverest\URMembership\Admin\Database;

use WPEverest\URMembership\TableList;

/**
 * Database Class
 */
class Database {

	/**
	 * Checks whether all required membership tables exist in the database.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		foreach ( self::get_tables() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Retrieves an array of tables used in the URMembership plugin.
	 *
	 * @return array An associative array with table names as keys and their corresponding table names as values.
	 */
	public static function get_tables() {
		return array(
			'subscription_events_table' => TableList::subscription_events_table(),
			'orders_meta_table'         => TableList::order_meta_table(),
			'orders_table'              => TableList::orders_table(),
			'subscriptions_table'       => TableList::subscriptions_table(),
		);
	}

	/**
	 * Creates the necessary tables for the URMembership plugin.
	 *
	 * This function creates the subscriptions table, orders table, and orders meta table.
	 * No foreign keys are declared because MySQL rejects them when `wp_users` is MyISAM; the rows that
	 * `ON DELETE CASCADE` used to remove are deleted in PHP by the repositories instead.
	 *
	 * @return void
	 */
	public static function create_tables() {
		$orders_table        = TableList::orders_table();
		$orders_meta_table   = TableList::order_meta_table();
		$subscriptions_table = TableList::subscriptions_table();
		$posts_table         = TableList::posts_table();
		$posts_meta_table    = TableList::posts_meta_table();
		global $wpdb;

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}
		$sqls = array();
		// create subscriptions table.
		array_push(
			$sqls,
			"CREATE TABLE IF NOT EXISTS $subscriptions_table (
                    	ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
     					item_id bigint(20) unsigned NOT NULL,
    					user_id bigint(20) unsigned NOT NULL,
						start_date datetime NOT NULL,
						expiry_date datetime  NULL,
						next_billing_date datetime  NULL,
    					billing_cycle ENUM('day', 'week', 'month', 'year') NOT NULL,
						trial_start_date DATETIME NULL,
						trial_end_date DATETIME NULL,
    					billing_amount DECIMAL(10, 2) NOT NULL,
    					cancel_sub ENUM('immediately', 'expiry') NOT NULL DEFAULT 'immediately',
    					status ENUM('active', 'canceled', 'expired', 'trial', 'pending') NOT NULL DEFAULT 'active',
    					coupon VARCHAR(250) NULL,
    					subscription_id VARCHAR(250) NULL,
						created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
						updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    					PRIMARY KEY (ID),
    					KEY user_id (user_id)
    					) $collate
                    "
		);

		// create orders table.
		array_push(
			$sqls,
			"CREATE TABLE IF NOT EXISTS $orders_table (
					  ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					  item_id bigint(20) unsigned NOT NULL,
					  user_id bigint(20) unsigned NOT NULL,
					  subscription_id bigint(20) unsigned NOT NULL,
					  created_by bigint(20) unsigned NOT NULL,
                      transaction_id varchar(100) NOT NULL,
                      payment_method varchar(100) NOT NULL DEFAULT '',
					  total_amount decimal(26,8) NOT NULL,
                      status enum('pending', 'failed', 'completed', 'refunded') NOT NULL DEFAULT 'pending',
                      order_type enum('free', 'paid', 'subscription') NOT NULL DEFAULT 'free',
                      trial_status enum('on', 'off') NOT NULL DEFAULT 'off',
					  notes longtext,
					  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    				  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					  PRIMARY KEY (ID),
					  INDEX idx_user_id (user_id),
					  INDEX idx_subscription_id (subscription_id),
					  INDEX idx_created_by (created_by),
					  INDEX idx_order_type (order_type),
					  INDEX idx_transaction_id (transaction_id)
					) $collate
					"
		);

		// create orders meta tables.
		array_push(
			$sqls,
			"CREATE TABLE IF NOT EXISTS $orders_meta_table (
                      meta_id bigint(20) NOT NULL AUTO_INCREMENT,
                      order_id bigint(20) unsigned NOT NULL,
                      meta_key varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
                      meta_value longtext COLLATE utf8mb4_unicode_520_ci,
                      PRIMARY KEY (meta_id),
                      KEY order_id (order_id)
                    ) $collate
                    "
		);

		$subscription_events_table = TableList::subscription_events_table();

		array_push(
			$sqls,
			"CREATE TABLE IF NOT EXISTS $subscription_events_table (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				subscription_id BIGINT(20) UNSIGNED NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL,

				event_type VARCHAR(50) NOT NULL,
				event_status VARCHAR(30) NULL,

				title VARCHAR(255) NOT NULL,
				message TEXT NULL,

				reference_id VARCHAR(255) NULL,
				meta LONGTEXT NULL,

				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

				PRIMARY KEY (id),
				KEY subscription_id (subscription_id),
				KEY user_id (user_id),
				KEY event_type (event_type)
			) $collate"
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sqls as $sql ) {
			$wpdb->query( $sql );
		}
	}

	/**
	 * Drops all tables used in the URMembership plugin.
	 *
	 * This function iterates over the tables obtained from the `get_tables` method
	 * and drops each table using the WordPress `$wpdb` global object. The `DROP TABLE IF EXISTS`
	 * SQL statement is used to ensure that the table is only dropped if it exists.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( self::get_tables() as $table ) {
			$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore
		}
	}
}
