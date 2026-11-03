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

// The email and login cells are built inline in each list table's row code, so check the escaping in the source.
foreach ( array( 'modules/membership/includes/Admin/Members/MembersListTable.php', 'includes/admin/settings/class-ur-members-list-table.php', 'includes/admin/settings/class-ur-users-list-table.php' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
	security_assert( false !== strpos( $source, 'esc_html( $email )' ), 'Escape the email cell in ' . $file );
	security_assert( 0 === preg_match( '/user_login\s*(<\/a>|\.\s*\'<\/p>)/', $source ), 'Login cell is not printed raw in ' . $file );
}
