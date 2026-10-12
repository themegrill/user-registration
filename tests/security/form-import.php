<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.GlobalVariablesOverride.Prohibited

require __DIR__ . '/bootstrap.php';
function sanitize_title( $s ) {
	return strtolower( str_replace( ' ', '-', $s ) ); }
function get_posts( $args ) {
	return array(); }
function wp_insert_post( $post, $error = false ) {
	$GLOBALS['insertions'][] = (array) $post;
	return 100 + count( $GLOBALS['insertions'] ); }
function is_wp_error( $v ) {
	return false; }
function esc_html__( $s, $domain = null ) {
	return $s; }
function admin_url( $s ) {
	return $s; }
function esc_url( $s ) {
	return $s; }
eval( 'class SecurityImport { public static ' . security_function( 'includes/admin/class-ur-admin-import-export-forms.php', 'import_form' ) . '}' );
function import_fixture( $data ) {
	$file = tempnam( sys_get_temp_dir(), 'ur-import-' );
	file_put_contents( $file, json_encode( $data ) );
	$_FILES                = array(
		'jsonfile' => array(
			'name'     => 'form.json',
			'tmp_name' => $file,
		),
	);
	$GLOBALS['insertions'] = array();
	try {
		return security_response( array( 'SecurityImport', 'import_form' ) );
	} finally {
		unlink( $file ); }
}
$post = array(
	'ID'           => 42,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'New form',
	'post_content' => '[[{"field_key":"email"}]]',
	'post_author'  => 99,
	'meta_input'   => array( '_wp_attached_file' => 'unexpected' ),
);
foreach ( array( array( 'form_post' => $post ), array( 'forms' => array( array( 'form_post' => $post ), array( 'form_post' => $post ) ) ) ) as $fixture ) {
	security_assert( import_fixture( $fixture )->success, 'Support legacy and multi-form exports' );
	foreach ( $GLOBALS['insertions'] as $inserted ) {
		security_assert( ! isset( $inserted['ID'] ) && ! isset( $inserted['post_author'] ) && ! isset( $inserted['meta_input'] ), 'Never pass arbitrary post fields' );
		security_assert( 'user_registration' === $inserted['post_type'], 'Create only forms' );
		security_assert( $post['post_content'] === $inserted['post_content'], 'Preserve form field JSON' );
	}
}
$bad = array( 'forms' => array( array( 'form_post' => $post ), array( 'form_post' => array( 'post_title' => array() ) ) ) );
security_assert( ! import_fixture( $bad )->success && empty( $GLOBALS['insertions'] ), 'Validate all forms before any insertion' );
foreach ( array( 'draft', 'private' ) as $status ) {
	$post['post_status'] = $status;
	import_fixture( array( 'form_post' => $post ) );
	security_assert( $status === $GLOBALS['insertions'][0]['post_status'], 'Preserve non-public form status' );
}
