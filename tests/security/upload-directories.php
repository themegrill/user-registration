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
	return 'user_registration_install_skip_create_files' === $hook ? ( $GLOBALS['skip_create_files'] ?? false ) : $value; }
function wp_upload_dir() {
	return array(); }
define( 'DAY_IN_SECONDS', 86400 );
$root = sys_get_temp_dir() . '/ur-directory-test-' . bin2hex( random_bytes( 8 ) );
define( 'UR_UPLOAD_PATH', $root . '/' );
define( 'UR_LOG_DIR', $root . '/logs' );
$source = 'includes/functions-ur-core.php';
if ( false === strpos( file_get_contents( dirname( __DIR__, 2 ) . '/' . $source ), 'function ur_protect_public_upload_directory' ) ) {
	throw new RuntimeException( 'Upload protection is absent' ); }
eval( security_function( $source, 'ur_get_public_upload_directory_rules' ) );
eval( security_function( $source, 'ur_protect_public_upload_directory' ) );
eval( 'class UploadDirectoryInstaller { public static ' . security_function( 'includes/class-ur-install.php', 'create_files' ) . ' }' );
eval( security_function( $source, 'ur_get_tmp_dir' ) );
eval( security_function( $source, 'ur_clean_tmp_files' ) );
try {
	$legacy_rules = ur_get_public_upload_directory_rules( true );
	security_assert( '6c4f97ea0835a3211542bae3cd400c929e8caf04433f053d2cd41ff1e230c898' === hash( 'sha256', $legacy_rules['.htaccess'] ), 'Migration matches original Apache bytes' );
	security_assert( '21ad81d0f6fa8ded49cc25d41ca9d8b515655f39b41a14839493a23e526efab8' === hash( 'sha256', $legacy_rules['web.config'] ), 'Migration matches original IIS bytes' );
	UploadDirectoryInstaller::create_files();
	security_assert( is_file( $root . '/index.html' ), 'Install an index at the upload root' );
	foreach ( array( '.htaccess', 'web.config' ) as $name ) {
		security_assert( ! file_exists( $root . '/' . $name ), 'Root must not impose an image policy on add-ons' );
		security_assert( is_file( $root . '/profile-pictures/' . $name ), 'Protect profile pictures on install' );
	}
	wp_mkdir_p( $root . '/file-uploads' );
	wp_mkdir_p( $root . '/private-notes' );
	file_put_contents( $root . '/file-uploads/document.pdf', 'pdf fixture' );
	foreach ( ur_get_public_upload_directory_rules( true ) as $name => $content ) {
		file_put_contents( $root . '/' . $name, $content );
		file_put_contents( $root . '/profile-pictures/' . $name, $content );
		file_put_contents( $root . '/temp-uploads/' . $name, $content );
	}
	$GLOBALS['skip_create_files'] = true;
	UploadDirectoryInstaller::create_files();
	security_assert( is_file( $root . '/.htaccess' ), 'Opt-out skips migration' );
	$GLOBALS['skip_create_files'] = false;
	UploadDirectoryInstaller::create_files();
	foreach ( array( '.htaccess', 'web.config' ) as $name ) {
		security_assert( ! file_exists( $root . '/' . $name ), 'Remove inherited legacy root policy' );
		foreach ( array( 'profile-pictures', 'temp-uploads' ) as $directory ) {
			security_assert( file_get_contents( $root . '/' . $directory . '/' . $name ) === ur_get_public_upload_directory_rules()[ $name ], 'Upgrade generated child rules' );
		}
		foreach ( array( 'file-uploads', 'private-notes' ) as $directory ) {
			security_assert( ! file_exists( $root . '/' . $directory . '/' . $name ), 'Add-on storage has no image-only policy' );
		}
		file_put_contents( $root . '/' . $name, '# custom root config' );
	}
	UploadDirectoryInstaller::create_files();
	foreach ( array( '.htaccess', 'web.config' ) as $name ) {
		security_assert( '# custom root config' === file_get_contents( $root . '/' . $name ), 'Preserve custom root rules' );
	}
	security_assert( 'pdf fixture' === file_get_contents( $root . '/file-uploads/document.pdf' ), 'Preserve add-on uploads' );
	$path = ur_get_tmp_dir();
	foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $name ) {
		security_assert( is_file( $path . '/' . $name ), 'Create protection file: ' . $name );
		touch( $path . '/' . $name, time() - 90000 ); }
	$apache = file_get_contents( $path . '/.htaccess' );
	security_assert( false === strpos( $apache, 'Options ' ), 'Do not require Apache Options overrides' );
	preg_match( '/<FilesMatch "([^"]+)">/', $apache, $match );
	foreach ( array( 'avatar.jpg', 'avatar.jpeg', 'avatar.png', 'avatar.GIF' ) as $name ) {
		security_assert( 1 === preg_match( '~' . $match[1] . '~', $name ), 'Keep public image URLs' ); }
	foreach ( array( 'poc.txt', 'shell.php', 'shell.php.jpg', 'shell.phtml.png', 'secret.pdf', '.htaccess' ) as $name ) {
		security_assert( 0 === preg_match( '~' . $match[1] . '~', $name ), 'Deny non-images and script extensions' ); }
	$xml = simplexml_load_file( $path . '/web.config' );
	security_assert( ! isset( $xml->{'system.webServer'}->handlers ), 'Do not override locked IIS handlers' );
	security_assert( 'false' === (string) $xml->{'system.webServer'}->security->requestFiltering->fileExtensions['allowUnlisted'], 'IIS uses image allowlist' );
	file_put_contents( $path . '/expired.jpg', 'test' );
	touch( $path . '/expired.jpg', time() - 90000 );
	ur_clean_tmp_files();
	security_assert( ! file_exists( $path . '/expired.jpg' ), 'Remove expired image' );
	foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $name ) {
		security_assert( is_file( $path . '/' . $name ), 'Cleanup preserves protections' ); }
	foreach ( array( '.htaccess', 'web.config' ) as $name ) {
		file_put_contents( $path . '/' . $name, '# custom' );
	}
	ur_get_tmp_dir();
	foreach ( array( '.htaccess', 'web.config' ) as $name ) {
		security_assert( '# custom' === file_get_contents( $path . '/' . $name ), 'Preserve custom server config' );
	}
} finally {
	if ( is_dir( $root ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $root );
	}
}
