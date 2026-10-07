<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: the Members view and My Account only read real invoices from a member's stored list.
 *
 * Runs the real `ur_get_valid_payment_invoices()`; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.PHP.Eval.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

require __DIR__ . '/bootstrap.php';

eval( security_function( 'includes/functions-ur-core.php', 'ur_get_valid_payment_invoices' ) );

$first  = array(
	'invoice_date'   => 'a',
	'invoice_amount' => '10.00',
);
$second = array(
	'invoice_no'     => 'sub_2',
	'invoice_amount' => '10.00',
);

security_assert( array( $first, $second ) === ur_get_valid_payment_invoices( array( $first, $second ) ), 'A flat list is read as it is' );
security_assert( array( $second ) === ur_get_valid_payment_invoices( array( array( $first ), $second ) ), 'The nested list a renewal saved is skipped, not read as an invoice with no amount' );
security_assert( array() === ur_get_valid_payment_invoices( '' ), 'No stored invoices gives an empty list' );
security_assert( array() === ur_get_valid_payment_invoices( array( 'junk', 5, array() ) ), 'Entries that are not invoices are skipped' );

// Both readers go through the helper, and the amount is cast so a bad value cannot fatal number_format().
$members_menu = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/admin/settings/class-ur-members-menu.php' );
$frontend     = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ur-frontend.php' );
security_assert( false !== strpos( $members_menu, 'foreach ( ur_get_valid_payment_invoices( $meta_value ) as $values )' ), 'The Members view reads invoices through the helper' );
security_assert( false !== strpos( $frontend, 'foreach ( ur_get_valid_payment_invoices( $meta_value ) as $values )' ), 'My Account reads invoices through the helper' );
security_assert( false === strpos( $members_menu, 'number_format( $amount, 2 )' ), 'The Members view casts the amount before number_format()' );
