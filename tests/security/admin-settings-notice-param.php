<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing

require __DIR__ . '/bootstrap.php';
eval( security_function( 'includes/admin/class-ur-admin-settings.php', 'escape_notice_param' ) );

$gadget = '<span class="tips" data-tooltip-content="&lt;img src=x onerror=alert(1)&gt;">hover</span>';
$output = escape_notice_param( $gadget );
security_assert( false === strpos( $output, '<' ), 'No markup survives, so Tooltipster never sees a .tips element' );
security_assert( 'hover' === escape_notice_param( '<b>hover</b>' ), 'Tags are stripped from plain notices' );
security_assert( 'Invalid &quot;key&quot;' === escape_notice_param( 'Invalid \"key\"' ), 'Quotes are escaped and unslashed' );
security_assert( '' === escape_notice_param( array( $gadget ) ), 'Array parameters are rejected' );
