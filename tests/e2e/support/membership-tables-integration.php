<?php
// phpcs:ignoreFile
/**
 * Regression check for the membership tables on a site whose `wp_users` table is MyISAM.
 *
 * `Database::create_tables()` declared FOREIGN KEYs to `wp_users`, which MySQL rejects unless both
 * tables are InnoDB, so on such sites none of the four membership tables were created and the Members
 * list stayed empty. The keys are gone; this asserts the tables now exist with no foreign keys, and
 * that every delete the keys used to cascade (order -> meta, subscription -> orders/meta/events,
 * user -> subscriptions/orders/meta/events) is still done, and nothing beyond that.
 *
 * Writes fixture rows only (own users/subscriptions/orders); run with wp eval-file on a disposable WordPress.
 */

if ( '1' !== getenv( 'UR_SECURITY_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Disposable install required.' );
}

use WPEverest\URMembership\Admin\Database\Database;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\TableList;

global $wpdb, $assertions;
$assertions = 0;

function check( $condition, $label ) {
	global $assertions;
	if ( ! $condition ) {
		throw new RuntimeException( 'Failed: ' . $label );
	}
	++$assertions;
}

function count_rows( $table, $column, $value ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $column = %d", $value ) );
}

/** Insert one subscription with an order, order meta and an event; return their ids. */
function make_membership_rows( $user_id ) {
	global $wpdb;
	$wpdb->insert( TableList::subscriptions_table(), array( 'item_id' => 1, 'user_id' => $user_id, 'start_date' => '2026-01-01 00:00:00', 'billing_cycle' => 'month', 'billing_amount' => 5 ) );
	$subscription_id = $wpdb->insert_id;
	$wpdb->insert( TableList::orders_table(), array( 'item_id' => 1, 'user_id' => $user_id, 'subscription_id' => $subscription_id, 'created_by' => $user_id, 'transaction_id' => 't' . $subscription_id, 'total_amount' => 5 ) );
	$order_id = $wpdb->insert_id;
	$wpdb->insert( TableList::order_meta_table(), array( 'order_id' => $order_id, 'meta_key' => 'k', 'meta_value' => 'v' ) );
	$wpdb->insert( TableList::subscription_events_table(), array( 'subscription_id' => $subscription_id, 'user_id' => $user_id, 'event_type' => 'e', 'title' => 't' ) );

	return array( $subscription_id, $order_id );
}

// Tables exist, with no foreign keys, whatever engine wp_users uses.
Database::create_tables();
check( Database::tables_exist(), 'all four membership tables exist' );
$foreign_keys = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = \'FOREIGN KEY\' AND TABLE_NAME LIKE \'' . $wpdb->esc_like( $wpdb->prefix . 'ur_membership_' ) . '%\'' );
check( 0 === $foreign_keys, 'no membership table declares a foreign key' );

$user_a = wp_insert_user( array( 'user_login' => 'fk_a_' . wp_rand(), 'user_pass' => 'x', 'user_email' => 'fk_a_' . wp_rand() . '@example.com' ) );
$user_b = wp_insert_user( array( 'user_login' => 'fk_b_' . wp_rand(), 'user_pass' => 'x', 'user_email' => 'fk_b_' . wp_rand() . '@example.com' ) );

// Deleting an order removes its meta only.
list( $sub_1, $order_1 ) = make_membership_rows( $user_a );
( new OrdersRepository() )->delete( $order_1 );
check( 0 === count_rows( TableList::order_meta_table(), 'order_id', $order_1 ), 'deleting an order removes its meta' );
check( 1 === count_rows( TableList::subscriptions_table(), 'ID', $sub_1 ), 'deleting an order keeps its subscription' );

// Deleting a subscription removes its orders, meta and events, and leaves other subscriptions alone.
list( $sub_2, $order_2 ) = make_membership_rows( $user_a );
list( $sub_3, $order_3 ) = make_membership_rows( $user_b );
( new SubscriptionRepository() )->delete( $sub_2 );
check( 0 === count_rows( TableList::orders_table(), 'ID', $order_2 ) && 0 === count_rows( TableList::order_meta_table(), 'order_id', $order_2 ) && 0 === count_rows( TableList::subscription_events_table(), 'subscription_id', $sub_2 ), 'deleting a subscription removes its orders, order meta and events' );
check( 1 === count_rows( TableList::orders_table(), 'ID', $order_3 ) && 1 === count_rows( TableList::order_meta_table(), 'order_id', $order_3 ), 'deleting a subscription leaves another member\'s rows' );

// Deleting only a member's latest order removes that order and its meta, not the earlier one.
list( , $earlier ) = make_membership_rows( $user_b );
list( , $latest )  = make_membership_rows( $user_b );
( new MembersOrderRepository() )->delete_member_order( $user_b, false );
check( 0 === count_rows( TableList::orders_table(), 'ID', $latest ) && 0 === count_rows( TableList::order_meta_table(), 'order_id', $latest ), 'delete_member_order(latest) removes the latest order and its meta' );
check( 1 === count_rows( TableList::orders_table(), 'ID', $earlier ) && 1 === count_rows( TableList::order_meta_table(), 'order_id', $earlier ), 'delete_member_order(latest) keeps the earlier order' );

// Deleting a user removes their subscriptions, orders, meta and events through the delete_user hook.
list( $sub_4, $order_4 ) = make_membership_rows( $user_a );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user_a );
check( 0 === count_rows( TableList::subscriptions_table(), 'user_id', $user_a ) && 0 === count_rows( TableList::orders_table(), 'user_id', $user_a ) && 0 === count_rows( TableList::order_meta_table(), 'order_id', $order_4 ) && 0 === count_rows( TableList::subscription_events_table(), 'subscription_id', $sub_4 ), 'deleting a user removes their subscriptions, orders, order meta and events' );
check( count_rows( TableList::subscriptions_table(), 'user_id', $user_b ) >= 1 && 1 === count_rows( TableList::orders_table(), 'ID', $order_3 ), 'deleting a user leaves other members\' rows' );

// Bulk-deleting orders (payment history screen) removes their meta.
list( , $bulk_1 ) = make_membership_rows( $user_b );
list( , $bulk_2 ) = make_membership_rows( $user_b );
( new OrdersRepository() )->delete_multiple( $bulk_1 . ',' . $bulk_2 );
check( 0 === count_rows( TableList::orders_table(), 'ID', $bulk_1 ) + count_rows( TableList::orders_table(), 'ID', $bulk_2 ) + count_rows( TableList::order_meta_table(), 'order_id', $bulk_1 ) + count_rows( TableList::order_meta_table(), 'order_id', $bulk_2 ), 'bulk-deleting orders removes their order meta' );

// delete_member_order() with $delete_all removes every order of the member with their meta.
list( , $all_1 ) = make_membership_rows( $user_b );
list( , $all_2 ) = make_membership_rows( $user_b );
( new MembersOrderRepository() )->delete_member_order( $user_b );
check( 0 === count_rows( TableList::orders_table(), 'user_id', $user_b ) && 0 === count_rows( TableList::order_meta_table(), 'order_id', $all_1 ) + count_rows( TableList::order_meta_table(), 'order_id', $all_2 ), 'delete_member_order(all) removes every order and its meta' );

// Bulk-deleting members (Members screen) removes their membership rows.
$user_c = wp_insert_user( array( 'user_login' => 'fk_c_' . wp_rand(), 'user_pass' => 'x', 'user_email' => 'fk_c_' . wp_rand() . '@example.com' ) );
list( $sub_c, $order_c ) = make_membership_rows( $user_c );
list( , $order_keep )    = make_membership_rows( $user_b );
( new MembersRepository() )->delete_multiple( (string) $user_c );
check( 0 === count_rows( TableList::subscriptions_table(), 'user_id', $user_c ) && 0 === count_rows( TableList::orders_table(), 'ID', $order_c ) && 0 === count_rows( TableList::order_meta_table(), 'order_id', $order_c ) && 0 === count_rows( TableList::subscription_events_table(), 'subscription_id', $sub_c ), 'bulk-deleting members removes their subscriptions, orders, order meta and events' );
check( 1 === count_rows( TableList::orders_table(), 'ID', $order_keep ), 'bulk-deleting members leaves another member\'s rows' );

wp_delete_user( $user_b );
echo $assertions . " assertions passed\n";
