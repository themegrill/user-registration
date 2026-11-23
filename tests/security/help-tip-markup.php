<?php
/**
 * Help tips are rendered as HTML by Tooltipster, so markup must never survive into data-tip.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.PHP.Eval.Discouraged, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.WP.AlternativeFunctions.file_system_read_fwrite

$wp = rtrim( $argv[1] ?? '', '/\\' ) . '/';
if ( ! is_file( $wp . 'wp-includes/kses.php' ) ) {
	fwrite( STDERR, "Supply a WordPress core directory; no site/database is loaded.\n" );
	exit( 2 ); }
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);
define( 'ABSPATH', $wp );
define( 'WPINC', 'wp-includes' );
require $wp . 'wp-includes/plugin.php';
require $wp . 'wp-includes/compat.php';
if ( is_file( $wp . 'wp-includes/utf8.php' ) ) {
	require $wp . 'wp-includes/utf8.php'; }
require $wp . 'wp-includes/formatting.php';
require $wp . 'wp-includes/kses.php';
foreach ( array( '../class-wp-token-map', 'html5-named-character-references', 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $html_api_file ) {
	require_once $wp . 'wp-includes/html-api/' . $html_api_file . '.php'; }
function _canonical_charset( $charset ) {
	return 'UTF-8'; }
function is_utf8_charset( $s = null ) {
	return true; }
function get_option( $key, $default = false ) {
	return 'blog_charset' === $key ? 'UTF-8' : $default; }
function wp_allowed_protocols() {
	return array( 'http', 'https' ); }

function security_assert( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	} ++$GLOBALS['checks']; }
$GLOBALS['checks'] = 0;

$core_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/functions-ur-core.php' );
foreach ( array( 'ur_sanitize_tooltip', 'ur_sanitize_tip_html', 'ur_help_tip' ) as $fn ) {
	preg_match( '/^function ' . $fn . '\b.*?^}$/ms', $core_source, $m );
	security_assert( ! empty( $m[0] ), 'Found ' . $fn );
	eval( $m[0] );
}

/** What Tooltipster receives: the attribute value after the browser decodes it. */
function decoded_tip( $html ) {
	preg_match( '/data-tip="([^"]*)"/', $html, $m );
	return html_entity_decode( $m[1] ?? '', ENT_QUOTES ); }

$payloads = array(
	'<img src=x onerror=alert(document.cookie)>',
	'"><script>alert(1)</script>',
	'<a href="javascript:alert(1)">x</a>',
	'&lt;img src=x onerror=alert(1)&gt;',
	'&amp;lt;img src=x onerror=alert(1)&amp;gt;',
	'<strong onmouseover=alert(1)>hi</strong>',
	'<iframe srcdoc="<script>alert(1)</script>"></iframe>',
	'<form action=javascript:alert(1)><input></form>',
);

/** True when the decoded tip still holds something Tooltipster could turn into script. */
function is_active_markup( $tip ) {
	return 0 !== preg_match( '/<\s*(script|iframe|object|embed|svg|math|style|link|meta|base|body|video|audio)\b|<[^>]*\son\w+\s*=|<[^>]*javascript:/i', $tip ); }

foreach ( $payloads as $payload ) {
	foreach ( array( false, true ) as $allow_html ) {
		$html = ur_help_tip( $payload, $allow_html );
		security_assert( 4 === substr_count( $html, '"' ), 'Attribute stays closed for ' . $payload );
		security_assert( ! is_active_markup( decoded_tip( $html ) ), 'No active markup reaches Tooltipster for ' . $payload );
	}
}

security_assert( 'Need help ?' === decoded_tip( ur_help_tip( 'Need help ?' ) ), 'Plain tip text is unchanged' );
security_assert( 'a<br />b' === decoded_tip( ur_help_tip( 'a<br />b', true ) ), 'Allowed formatting survives when HTML is allowed' );
security_assert( false !== strpos( decoded_tip( ur_help_tip( "create one <a href='?page=x&action=y'>here</a>." ) ), '<a href=' ), 'Links in admin-written tips keep working' );

security_assert( '' === decoded_tip( ur_help_tip( null ) ), 'A null tip is an empty tip without deprecation notices' );
$utf8_tip = decoded_tip( ur_help_tip( "caf\xE9 tip" ) );
security_assert( 0 === strpos( $utf8_tip, 'caf' ) && false !== strpos( $utf8_tip, ' tip' ), 'Invalid UTF-8 does not wipe the whole tip' );
$compare_tip = decoded_tip( ur_help_tip( 'Must be < 10 and > 5' ) );
security_assert( false !== strpos( $compare_tip, '10 and' ) && false !== strpos( $compare_tip, ' 5' ) && ! is_active_markup( $compare_tip ), 'Comparison text in a tip is kept as text' );
security_assert( 0 === preg_match( '/<b>/', decoded_tip( ur_help_tip( 'a < b' ) ) ), 'A lone < does not turn into a tag' );
security_assert( false !== strpos( decoded_tip( ur_help_tip( 't', false, 'a" onmouseover="x' ) ), '' ) && 0 === preg_match( '/class="[^"]*"\s+onmouseover/', ur_help_tip( 't', false, 'a" onmouseover="x' ) ), 'The class name can not break out of its attribute' );

// Run the exact expressions the templates print into data-tip, with the bank notice built as membership.php builds it.
$template_dir = dirname( __DIR__, 2 );
foreach ( array( 'templates/myaccount/membership.php' ) as $file ) {
	preg_match( '/data-tip="<\?php echo (.+?); \?>"/', file_get_contents( $template_dir . '/' . $file ), $m );
	security_assert( ! empty( $m[1] ), 'Found the data-tip expression in ' . $file );
	foreach ( $payloads as $payload ) {
		$notice  = 'Pay by bank</br><button class="view-bank-data">View</button><div class="upgrade-info urm-d-none">' . $payload . '</div>';
		$printed = eval( 'return ' . $m[1] . ';' );
		security_assert( ! is_active_markup( decoded_tip( 'data-tip="' . $printed . '"' ) ), 'No active markup in the membership notice for ' . $payload );
		$kept = decoded_tip( 'data-tip="' . $printed . '"' );
		security_assert( false !== strpos( $kept, '<button class="view-bank-data">' ) && false !== strpos( $kept, 'upgrade-info' ), 'The bank notice keeps its button and info block for ' . $payload );
	}
}
security_assert( false === strpos( file_get_contents( $template_dir . '/modules/membership/includes/Templates/membership-registration-form.php' ), 'echo $tooltip' ), 'Membership form tooltip is printed through ur_help_tip' );
echo $GLOBALS['checks'] . ' assertions completed' . PHP_EOL;
