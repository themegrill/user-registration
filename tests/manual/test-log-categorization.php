<?php
/**
 * Regression check for UR_Log_List_Table::describe_handle().
 *
 * Standalone (no WP bootstrap needed) so it can run as a fast guard against
 * the categorization bugs fixed in #1738: a bare `strpos($handle, 'email')`,
 * and blanket `urm-`/`ur-`/`user-registration-` prefix catch-alls were
 * mis-categorizing unrelated handles. Run: php tests/manual/test-log-categorization.php
 */

define( 'ABSPATH', __DIR__ . '/' );
class WP_List_Table {} // phpcs:ignore -- minimal stand-in, only static methods under test are called.
function __( $text, $domain = 'default' ) { return $text; } // phpcs:ignore
function apply_filters( $tag, $value ) { return $value; } // phpcs:ignore

require dirname( __DIR__, 2 ) . '/includes/admin/class-ur-log-list-table.php';

$failures = 0;
$total    = 0;

/**
 * @param string $handle   Handle to describe.
 * @param string $category Expected category slug.
 * @param bool   $known    Expected known flag.
 * @param string $label    Case description for failure output.
 */
function check( $handle, $category, $known, $label ) {
	global $failures, $total;
	++$total;

	$info = UR_Log_List_Table::describe_handle( $handle );
	$ok   = $info['category'] === $category && $info['known'] === $known;

	if ( ! $ok ) {
		++$failures;
		printf(
			"FAIL: %s\n  handle=%s expected category=%s known=%s, got category=%s known=%s\n",
			$label,
			$handle,
			$category,
			$known ? 'true' : 'false',
			$info['category'],
			$info['known'] ? 'true' : 'false'
		);
	} else {
		printf( "ok: %s\n", $label );
	}
}

// Registered handle -> exact category from the lookup table, not guessed.
check( 'ur-captcha-logs', 'system', true, 'registered source resolves via lookup table' );

// Payment gateway handles match only the urm-pg- prefix regex.
check( 'urm-pg-paypal', 'payments', true, 'payment gateway prefix categorized as payments' );

// Regression: a handle merely containing "email" must not be force-categorized
// as email just on substring match -- only registered email sources should.
check( 'newsletter-email-digest', 'system', false, 'unregistered handle containing "email" is not miscategorized' );

// Regression: bare "urm-" prefix must not blanket-catch as membership --
// only handles present in the registered sources table do.
check( 'urm-some-unregistered-thing', 'system', false, 'unregistered urm- handle is not miscategorized as membership' );

// Regression: bare "ur-"/"user-registration-" prefix must not blanket-catch as add-ons.
check( 'ur-some-unregistered-addon', 'system', false, 'unregistered ur- handle is not miscategorized as add-ons' );
check( 'user-registration-some-unregistered-thing', 'system', false, 'unregistered user-registration- handle is not miscategorized as add-ons' );

// Truly unknown handle falls back to system/unknown, not a guessed category.
check( 'totally-unrelated-handle', 'system', false, 'unknown handle falls back to system/unknown' );

printf( "\n%d/%d passed\n", $total - $failures, $total );

exit( $failures > 0 ? 1 : 0 );
