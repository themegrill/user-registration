<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound

require __DIR__ . '/bootstrap.php';

/** Hostile markup as the shortcode output would carry it into the page for a later do_shortcode() pass. */
const HOSTILE_OUTPUT = '<ul class="user-registration-error"><li>[caption width="1" caption="PWNED"]x[/caption]</li></ul>';

function do_shortcode( $shortcode ) {
	return HOSTILE_OUTPUT; }
eval( security_function( 'includes/functions-ur-core.php', 'ur_escape_shortcodes' ) );
eval( 'namespace Elementor; class Plugin { public static $instance; } Plugin::$instance = (object) array( "editor" => new class() { public function is_edit_mode() { return false; } } );' );

$widgets = array(
	'My Account'      => 'class-ur-widgets-myaccount.php',
	'Login'           => 'class-ur-widgets-login.php',
	'Registration'    => 'class-ur-widgets-registration.php',
	'Edit Profile'    => 'class-ur-widgets-edit-profile.php',
	'Edit Password'   => 'class-ur-widgets-edit-password.php',
	'Profile Details' => 'class-ur-widgets-profile-details.php',
);

$index = 0;
foreach ( $widgets as $label => $file ) {
	$class = 'WidgetUnderTest' . ( ++$index );
	eval( "class $class { private function get_shortcode() { return '[test]'; } " . 'public ' . security_function( 'includes/3rd-party/elementor/widgets/' . $file, 'render' ) . ' }' );
	$render = new ReflectionMethod( $class, 'render' );
	$render->setAccessible( true );
	ob_start();
	$render->invoke( new $class() );
	$output = ob_get_clean();
	security_assert( false === strpos( $output, '[caption' ) && false === strpos( $output, '[/caption' ), "$label widget output carries no live shortcode for a later do_shortcode() pass" );
	security_assert( false !== strpos( $output, 'PWNED' ) && false !== strpos( $output, '<ul class="user-registration-error">' ), "$label widget keeps its markup and text intact" );
}

// Popup widgets render admin-authored popup data and UR_Widget is never registered, so they stay raw on purpose.
$allowed   = array( 'class-ur-widget.php', 'class-ur-widgets-popup.php', 'class-ur-oxygen-widget-popup.php' );
$unescaped = array();
foreach ( array( 'elementor', 'oxygen' ) as $builder ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes/3rd-party/' . $builder, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' === $file->getExtension() && ! in_array( $file->getFilename(), $allowed, true ) && preg_match( '/echo\s+do_shortcode\s*\(/', file_get_contents( $file->getPathname() ) ) ) {
			$unescaped[] = $file->getFilename();
		}
	}
}
security_assert( array() === $unescaped, 'Every Elementor and Oxygen widget escapes its shortcode output; unescaped: ' . implode( ', ', $unescaped ) );
