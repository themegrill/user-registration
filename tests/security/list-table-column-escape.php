<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';
function get_user_meta( $user_id, $key ) {
	return $GLOBALS['meta'][ $key ] ?? ''; }
$payload = '<img src=x onerror=alert(1)>';
foreach ( array( 'includes/admin/settings/class-ur-users-list-table.php', 'includes/admin/settings/class-ur-members-list-table.php' ) as $file ) {
	$class = 'SecurityColumn' . md5( $file );
	eval( "class $class { public " . security_function( $file, 'output_custom_column_data' ) . '}' );
	$table = new $class();

	$GLOBALS['meta'] = array( 'user_registration_note' => $payload );
	security_assert( false === strpos( $table->output_custom_column_data( '', 'note', 1 ), '<' ), 'Escape single meta value' );

	$GLOBALS['meta'] = array( 'user_registration_note' => array( 'ok', $payload ) );
	security_assert( false === strpos( $table->output_custom_column_data( '', 'note', 1 ), '<' ), 'Escape multi value meta' );
}
