<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.WP.GlobalVariablesOverride.Prohibited

require __DIR__ . '/bootstrap.php';
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0700, true ); }
function wp_is_writable( $path ) {
	return is_writable( $path ); }
function trailingslashit( $path ) {
	return rtrim( $path, '/' ) . '/'; }
function apply_filters( $hook, $value ) {
	return $value; }
define( 'DAY_IN_SECONDS', 86400 );
$root = sys_get_temp_dir() . '/ur-directory-test-' . bin2hex( random_bytes( 8 ) );
define( 'UR_UPLOAD_PATH', $root . '/' );
$source = 'includes/functions-ur-core.php';
if ( false === strpos( file_get_contents( dirname( __DIR__, 2 ) . '/' . $source ), 'function ur_protect_public_upload_directory' ) ) {
	throw new RuntimeException( 'Upload protection is absent' ); }
eval( security_function( $source, 'ur_protect_public_upload_directory' ) );
eval( security_function( $source, 'ur_get_tmp_dir' ) );
eval( security_function( $source, 'ur_clean_tmp_files' ) );
try {
	$path = ur_get_tmp_dir();
	foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $name ) {
		security_assert( is_file( $path . '/' . $name ), 'Create protection file: ' . $name );
		touch( $path . '/' . $name, time() - 90000 ); }
	$apache = file_get_contents( $path . '/.htaccess' );
	preg_match( '/<FilesMatch "([^"]+)">/', $apache, $match );
	foreach ( array( 'avatar.jpg', 'avatar.jpeg', 'avatar.png', 'avatar.GIF' ) as $name ) {
		security_assert( 1 === preg_match( '~' . $match[1] . '~', $name ), 'Keep public image URLs' ); }
	foreach ( array( 'poc.txt', 'shell.php', 'shell.php.jpg', 'shell.phtml.png', 'secret.pdf', '.htaccess' ) as $name ) {
		security_assert( 0 === preg_match( '~' . $match[1] . '~', $name ), 'Deny non-images and script extensions' ); }
	$xml = simplexml_load_file( $path . '/web.config' );
	security_assert( 'false' === (string) $xml->{'system.webServer'}->security->requestFiltering->fileExtensions['allowUnlisted'], 'IIS uses image allowlist' );
	file_put_contents( $path . '/expired.jpg', 'test' );
	touch( $path . '/expired.jpg', time() - 90000 );
	ur_clean_tmp_files();
	security_assert( ! file_exists( $path . '/expired.jpg' ), 'Remove expired image' );
	foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $name ) {
		security_assert( is_file( $path . '/' . $name ), 'Cleanup preserves protections' ); }
	file_put_contents( $path . '/.htaccess', '# custom' );
	ur_get_tmp_dir();
	security_assert( '# custom' === file_get_contents( $path . '/.htaccess' ), 'Preserve custom server config' );
} finally {
	foreach ( array( 'index.html', '.htaccess', 'web.config', 'expired.jpg' ) as $name ) {
		if ( is_file( $root . '/temp-uploads/' . $name ) ) {
			unlink( $root . '/temp-uploads/' . $name ); }
	}
	if ( is_dir( $root . '/temp-uploads' ) ) {
		rmdir( $root . '/temp-uploads' ); }
	if ( is_dir( $root ) ) {
		rmdir( $root ); }
}
