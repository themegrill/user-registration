<?php
/**
 * Restriction metadata regression; run with wp eval-file on disposable WordPress.
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
$post_id = wp_insert_post(
	array(
		'post_title'  => 'Meta regression',
		'post_status' => 'publish',
		'post_type'   => 'post',
	)
);
$users   = array();
try {
	foreach ( array( 'contributor', 'editor', 'administrator' ) as $role ) {
		$id      = wp_insert_user(
			array(
				'user_login' => 'meta-' . wp_generate_password( 12, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		$users[] = $id;
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => $id,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $id );
		foreach ( array( 'urcr_meta_content', 'urcr_meta_override_global_settings' ) as $key ) {
			$check( ( 'administrator' === $role ) === current_user_can( 'add_post_meta', $post_id, $key ), $role . ' add-meta authorization' );
			$check( ( 'administrator' === $role ) === current_user_can( 'edit_post_meta', $post_id, $key ), $role . ' edit-meta authorization' );
		}
	}
	update_post_meta( $post_id, 'urcr_meta_content', '<p>Allowed <strong>message</strong></p><svg onload="alert(1)"></svg><script>alert(1)</script>' );
	$message = get_post_meta( $post_id, 'urcr_meta_content', true );
	$check( ! str_contains( $message, '<svg' ) && ! str_contains( $message, '<script' ) && ! str_contains( $message, 'onload=' ), 'Every metadata write strips executable markup' );
	$check( str_contains( $message, '<strong>message</strong>' ), 'Safe message formatting preserved' );
	update_post_meta( $post_id, 'urcr_meta_override_global_settings', 'on' );
	$check( 'on' === get_post_meta( $post_id, 'urcr_meta_override_global_settings', true ), 'Valid override preserved' );
	update_post_meta( $post_id, 'urcr_meta_override_global_settings', '<svg onload="alert(1)">' );
	$check( '' === get_post_meta( $post_id, 'urcr_meta_override_global_settings', true ), 'Override normalized' );
	// The legacy save handler must check nonce and capability before writes.
	require_once UR_ABSPATH . 'modules/content-restriction/admin/class-urcr-admin-meta-boxes.php';
	$box = ( new ReflectionClass( 'URCR_Admin_Meta_Box' ) )->newInstanceWithoutConstructor();
	wp_set_current_user( $users[2] );
	update_post_meta( $post_id, 'urcr_meta_content', 'Unchanged' );
	$_POST = array(
		'urcr_meta_content' => 'Must not save',
		'custom_nonce'      => 'invalid',
	);
	$box->save_metabox( $post_id, get_post( $post_id ) );
	$check( 'Unchanged' === get_post_meta( $post_id, 'urcr_meta_content', true ), 'Bad nonce prevents metabox writes' );
	wp_set_current_user( $users[1] );
	$_POST['custom_nonce'] = wp_create_nonce( 'custom_nonce_action' );
	$box->save_metabox( $post_id, get_post( $post_id ) );
	$check( 'Unchanged' === get_post_meta( $post_id, 'urcr_meta_content', true ), 'Editor cannot bypass metadata capability with metabox save' );
	wp_set_current_user( $users[2] );
	global $post;
	$post = get_post( $post_id );
	ob_start();
	$box->render_metabox( get_post( $post_id ) );
	$markup = ob_get_clean();
	preg_match( '/name="custom_nonce" value="([^"]+)"/', $markup, $nonce_match );
	$check( ! empty( $nonce_match[1] ) && wp_verify_nonce( $nonce_match[1], 'custom_nonce_action' ), 'Metabox renders its save nonce' );
	$_POST['custom_nonce']      = $nonce_match[1];
	$_POST['urcr_meta_content'] = '<strong>Saved</strong><svg onload="alert(1)"></svg>';
	$box->save_metabox( $post_id, get_post( $post_id ) );
	$check( '<strong>Saved</strong>' === get_post_meta( $post_id, 'urcr_meta_content', true ), 'Authorized nonce saves safe message' );
	$_POST = array();
	// Emulate a payload stored before this patch, bypassing today's write sanitizer.
	update_post_meta( $post_id, 'urcr_meta_override_global_settings', 'on' );
	global $wpdb, $post;
	$wpdb->update(
		$wpdb->postmeta,
		array( 'meta_value' => '<p>Legacy message</p><svg onload="alert(1)"></svg>' ),
		array(
			'post_id'  => $post_id,
			'meta_key' => 'urcr_meta_content',
		)
	);
	wp_cache_delete( $post_id, 'post_meta' );
	$post = get_post( $post_id );
	wp_set_current_user( 0 );
	$html = do_shortcode( '[urcr_restrict enable_content_restriction="true" access_all_roles="all_logged_in_users" access_control="access"]SECRET[/urcr_restrict]' );
	$check( ! str_contains( $html, '<svg' ) && ! str_contains( $html, 'onload=' ), 'Legacy shortcode payload escaped on read' );
	$check( str_contains( $html, 'Legacy message' ) && ! str_contains( $html, 'SECRET' ), 'Restriction message preserved, protected body absent' );
	ob_start();
	urcr_get_template(
		'base-restriction-template.php',
		array(
			'message'    => '<strong>Safe</strong><svg onload="alert(1)"></svg>',
			'login_url'  => '',
			'signup_url' => '',
		)
	);
	$html = ob_get_clean();
	$check( ! str_contains( $html, '<svg' ) && str_contains( $html, '<strong>Safe</strong>' ), 'Template protects arbitrary message inputs' );
} finally {
	wp_set_current_user( 0 );
	wp_delete_post( $post_id, true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) {
		wp_delete_user( $id ); }
}
echo esc_html( $count . " assertions passed\n" );
