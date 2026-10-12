<?php
/**
 * Regression for themegrill/user-registration-pro#1660: addon install must explain a missing download link.
 *
 * @package UserRegistration/Tests
 */

// Test doubles bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';
function esc_html__( $s ) {
	return $s; }
eval( security_function( 'includes/functions-ur-core.php', 'ur_get_addon_package_error' ) );

$GLOBALS['options']['user-registration_license_key'] = '';
// Real API shape with no license: item known, download_link present but empty.
$no_license = ur_get_addon_package_error(
	(object) array(
		'name'          => 'User Registration Brevo',
		'download_link' => '',
	)
);
security_assert( 'no_download_link' === $no_license['errorCode'], 'Empty download_link without a license is reported' );
security_assert( false !== strpos( $no_license['errorMessage'], 'No valid license' ), 'No-license message points at the license' );

$GLOBALS['options']['user-registration_license_key'] = 'set';
$with_license                                        = ur_get_addon_package_error(
	(object) array(
		'name'          => 'User Registration Brevo',
		'download_link' => '',
	)
);
security_assert( 'no_download_link' === $with_license['errorCode'], 'Empty download_link with a license is reported' );
security_assert( false === strpos( $with_license['errorMessage'], 'No valid license' ), 'A set license is not blamed as missing' );

// Real API shape for an unrecognised addon name: empty name and no download_link key at all.
security_assert(
	'addon_not_found' === ur_get_addon_package_error(
		(object) array(
			'name' => '',
			'msg'  => 'No item provided',
		)
	)['errorCode'],
	'Unrecognised addon name is reported without reading the missing download_link'
);
// UR_Updater_Key_API::version() returns false on transport errors, which json_decode() turns into null.
security_assert( 'updater_unavailable' === ur_get_addon_package_error( json_decode( false ) )['errorCode'], 'A failed updater request is not misreported as an unknown addon' );

security_assert(
	array() === ur_get_addon_package_error(
		(object) array(
			'name'          => 'User Registration Brevo',
			'download_link' => 'https://example.test/package.zip',
		)
	),
	'A usable response passes through'
);
