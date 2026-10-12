<?php
/**
 * Member name regression; run with wp eval-file on disposable WordPress.
 *
 * @package UserRegistration/Tests
 */

// WordPress globals and raw legacy data are deliberate disposable regression fixtures.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.PHP.YodaConditions

if ( '1' !== getenv( 'UR_SECURITY_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Disposable install required.' ); }
$count   = 0;
$check   = function ( $value, $message ) use ( &$count ) {
	if ( ! $value ) {
		throw new RuntimeException( esc_html( $message ) );
	} ++$count;
};
$user_id = wp_insert_user(
	array(
		'user_login' => 'names-' . wp_generate_password( 12, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
try {
	foreach ( array( 'first_name', 'last_name', 'nickname' ) as $key ) {
		update_user_meta( $user_id, $key, '<svg onload="alert(1)"></svg>Name' );
		$check( 'Name' === get_user_meta( $user_id, $key, true ), $key . ' sanitized across all writers' );
	}
	$user                     = get_userdata( $user_id );
	$profile                  = ur_get_non_urm_user_profile_fields( $user_id );
	$fields                   = array(
		'user_registration_first_name' => '<svg onload="alert(1)"></svg>Alice',
		'user_registration_last_name'  => '<script>alert(1)</script>Smith',
	);
	list( $profile, $fields ) = urm_process_profile_fields( $profile, $fields, array(), 0, $user_id );
	urm_update_user_profile_data( $user, $profile, $fields, 0 );
	$check( 'Alice' === get_user_meta( $user_id, 'first_name', true ) && 'Smith' === get_user_meta( $user_id, 'last_name', true ), 'Omitted username does not clear login or bypass name sanitizer' );
	$check( $user->user_login === get_userdata( $user_id )->user_login, 'Existing login preserved' );
	// Core update failure must not commit queued name/custom metadata.
	if ( ! defined( 'DOING_AJAX' ) ) {
		define( 'DOING_AJAX', true ); }
	$die_handler = function () {
		return function () {
			throw new RuntimeException( 'expected-profile-error' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die_handler );
	ob_start();
	try {
		$fields['user_registration_user_email'] = get_userdata( 1 )->user_email;
		$fields['user_registration_first_name'] = 'Must not save';
		add_filter( 'user_registration_email_change_confirmation', '__return_false' );
		urm_update_user_profile_data( $user, $profile, $fields, 0 );
		throw new RuntimeException( 'Expected core update failure' );
	} catch ( RuntimeException $e ) {
		$check( 'expected-profile-error' === $e->getMessage(), 'Failure returned to AJAX caller' );
	} finally {
		$response = ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $die_handler );
		remove_filter( 'user_registration_email_change_confirmation', '__return_false' );
	}
	$check( false === json_decode( $response, true )['success'], 'Core failure reports error' );
	$check( 'Alice' === get_user_meta( $user_id, 'first_name', true ), 'No queued metadata saved after failed core update' );
	// Emulate legacy unsafe stored names, not merely sanitized new writes.
	global $wpdb;
	$wpdb->update(
		$wpdb->usermeta,
		array( 'meta_value' => '<svg onload="alert(1)"></svg>Legacy' ),
		array(
			'user_id'  => $user_id,
			'meta_key' => 'last_name',
		)
	);
	clean_user_cache( $user_id );
	require_once UR_ABSPATH . 'modules/membership/includes/Admin/Subscriptions/ListTable.php';
	$table = ( new ReflectionClass( 'WPEverest\\URMembership\\Admin\\Subscriptions\\ListTable' ) )->newInstanceWithoutConstructor();
	$html  = $table->column_user_id( (object) array( 'user_id' => $user_id ) );
	$check( ! str_contains( $html, '<svg' ) && str_contains( $html, '&lt;svg' ), 'Legacy name escaped in subscription anchor' );
} finally {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );
}
echo esc_html( $count . " assertions passed\n" );
