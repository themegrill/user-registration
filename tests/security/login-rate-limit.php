<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Isolated security regression fixture.
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.VariableComment.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

require __DIR__ . '/bootstrap.php';

$GLOBALS['mock_transients'] = array();
$GLOBALS['mock_filters']    = array();
$GLOBALS['mock_actions']    = array();
$GLOBALS['mock_locks']      = array();

function get_transient( $key ) {
	if ( ! isset( $GLOBALS['mock_transients'][ $key ] ) ) {
		return false;
	}
	$entry = $GLOBALS['mock_transients'][ $key ];
	if ( $entry['expires'] > 0 && time() > $entry['expires'] ) {
		unset( $GLOBALS['mock_transients'][ $key ] );
		return false;
	}
	return $entry['value'];
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['mock_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? time() + $expiration : 0,
	);
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['mock_transients'][ $key ] );
	return true;
}

function apply_filters( $tag, $value ) {
	$args = array_slice( func_get_args(), 1 );
	if ( ! empty( $GLOBALS['mock_filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['mock_filters'][ $tag ] as $cb ) {
			$args[0] = call_user_func_array( $cb, $args );
		}
	}
	return $args[0];
}

function do_action( $tag ) {
	$GLOBALS['mock_actions'][ $tag ][] = array_slice( func_get_args(), 1 );
}

function rest_is_ip_address( $ip ) {
	return false !== filter_var( $ip, FILTER_VALIDATE_IP );
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function human_time_diff( $from, $to = 0 ) {
	$mins = (int) round( abs( $to - $from ) / 60 );
	return $mins . ' ' . ( 1 === $mins ? 'min' : 'mins' );
}

class SecurityMockWpdb {
	public function prepare( $query ) {
		$args = array_slice( func_get_args(), 1 );
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
	}

	public function get_var( $query ) {
		if ( 0 === strpos( $query, 'SELECT GET_LOCK(' ) ) {
			$GLOBALS['mock_locks'][] = 'acquire';
			return '1';
		}
		if ( 0 === strpos( $query, 'SELECT RELEASE_LOCK(' ) ) {
			$GLOBALS['mock_locks'][] = 'release';
			return '1';
		}
		return null;
	}
}

$GLOBALS['wpdb'] = new SecurityMockWpdb();

require dirname( __DIR__, 2 ) . '/includes/class-ur-login-rate-limiter.php';

// 1. get_client_ip() retains validated REMOTE_ADDR and ignores spoofable proxy headers unless REMOTE_ADDR is a trusted proxy.
$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
$_SERVER['HTTP_X_REAL_IP']       = '5.6.7.8';
security_assert( '203.0.113.10' === UR_Login_Rate_Limiter::get_client_ip(), 'Retain validated REMOTE_ADDR and ignore spoofable proxy headers' );

$GLOBALS['mock_filters']['user_registration_login_trusted_proxies'] = array(
	function () {
		return array( '203.0.113.10' );
	},
);

$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 198.51.100.2, 203.0.113.10';
security_assert( '198.51.100.2' === UR_Login_Rate_Limiter::get_client_ip(), 'Extract right-most untrusted forwarded IP when REMOTE_ADDR is a trusted proxy' );
unset( $GLOBALS['mock_filters']['user_registration_login_trusted_proxies'] );

$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
security_assert( '' === UR_Login_Rate_Limiter::get_client_ip(), 'Return empty string when REMOTE_ADDR is invalid' );

// 2. Atomic consume_attempt() blocks a 6th concurrent burst request even before finalize_failed_attempt() runs.
$user = 'alice_login';
$ip_a = '198.51.100.42';
$ip_b = '198.51.100.99';
for ( $i = 1; $i <= 5; $i++ ) {
	security_assert( true === UR_Login_Rate_Limiter::consume_attempt( $user, $ip_a ), "Concurrent slot {$i} reserved" );
}
security_assert( false === UR_Login_Rate_Limiter::consume_attempt( $user, $ip_a ), 'Concurrent 6th attempt blocked before wp_signon finishes' );

// 3. Bucket updates acquire and release the DB advisory lock.
security_assert( in_array( 'acquire', $GLOBALS['mock_locks'], true ) && in_array( 'release', $GLOBALS['mock_locks'], true ), 'Bucket update acquires and releases advisory lock' );

// 4. Finalizing the 5th failure activates escalating lockout for (user, IP A) without locking out legitimate user on IP B.
security_assert( true === UR_Login_Rate_Limiter::finalize_failed_attempt( $user, $ip_a ), '5th failure activates lockout' );
security_assert( true === UR_Login_Rate_Limiter::is_locked( 'ALICE_LOGIN', $ip_a ), 'Lockout matches case-insensitively on same IP' );
security_assert( false === UR_Login_Rate_Limiter::is_locked( $user, $ip_b ), 'Attacker on IP A cannot DoS-lock legitimate user on IP B' );

// 5. Active lockout is not extended by repeated attempts while locked.
$remaining_initial = UR_Login_Rate_Limiter::get_remaining_lockout_seconds( $user, $ip_a );
security_assert( false === UR_Login_Rate_Limiter::consume_attempt( $user, $ip_a ), 'Locked pair is rejected by consume_attempt()' );
security_assert( UR_Login_Rate_Limiter::get_remaining_lockout_seconds( $user, $ip_a ) <= $remaining_initial, 'Repeated attempts while locked do not extend active lockout' );

// 6. Second lockout cycle escalates duration from 15 mins to 30 mins.
$account_key           = UR_Login_Rate_Limiter::ACCOUNT_KEY_PREFIX . md5( strtolower( $user ) . '|' . strtolower( $ip_a ) );
$state                 = get_transient( $account_key );
$state['locked_until'] = time() - 1;
set_transient( $account_key, $state, 900 );
for ( $i = 1; $i <= 5; $i++ ) {
	UR_Login_Rate_Limiter::consume_attempt( $user, $ip_a );
	UR_Login_Rate_Limiter::finalize_failed_attempt( $user, $ip_a );
}
$remaining_second = UR_Login_Rate_Limiter::get_remaining_lockout_seconds( $user, $ip_a );
security_assert( $remaining_second >= 1795 && $remaining_second <= 1800, 'Second lockout escalates to 30 minutes' );

// 7. Legitimate login on 5th attempt succeeds and clear_attempts() resets pair bucket while only refunding its own IP slot.
$good_user = 'bob_login';
$good_ip   = '198.51.100.55';
for ( $i = 1; $i <= 4; $i++ ) {
	UR_Login_Rate_Limiter::consume_attempt( $good_user, $good_ip );
	UR_Login_Rate_Limiter::finalize_failed_attempt( $good_user, $good_ip );
}
security_assert( true === UR_Login_Rate_Limiter::consume_attempt( $good_user, $good_ip ), '5th attempt is allowed so correct password can succeed' );
UR_Login_Rate_Limiter::clear_attempts( $good_user, $good_ip );
security_assert( false === UR_Login_Rate_Limiter::is_locked( $good_user, $good_ip ), 'clear_attempts() clears pair bucket on success' );
$ip_state = get_transient( UR_Login_Rate_Limiter::IP_KEY_PREFIX . md5( $good_ip ) );
security_assert( is_array( $ip_state ) && 4 === $ip_state['attempts'], 'clear_attempts() refunds only the successful attempt and preserves prior IP failures' );

// 8. Non-credential account status errors are excluded and refunded cleanly.
security_assert(
	UR_Login_Rate_Limiter::is_excluded_error_code( 'pending_approval' )
	&& UR_Login_Rate_Limiter::is_excluded_error_code( 'denied_access' )
	&& UR_Login_Rate_Limiter::is_excluded_error_code( 'user_email_not_verified' )
	&& UR_Login_Rate_Limiter::is_excluded_error_code( 'empty_password' )
	&& ! UR_Login_Rate_Limiter::is_excluded_error_code( 'incorrect_password' ),
	'Account status errors are excluded while incorrect_password is counted'
);
$pending_user = 'pending_user';
$pending_ip   = '198.51.100.66';
for ( $i = 1; $i <= 6; $i++ ) {
	security_assert( true === UR_Login_Rate_Limiter::consume_attempt( $pending_user, $pending_ip ), "Pending user retry {$i} allowed" );
	UR_Login_Rate_Limiter::refund_attempt( $pending_user, $pending_ip );
}
security_assert( false === UR_Login_Rate_Limiter::is_locked( $pending_user, $pending_ip ), 'Refunded status errors never trigger lockout' );

// 9. Per-IP password spraying across 50 distinct usernames locks the IP.
$spray_ip = '198.51.100.77';
for ( $i = 1; $i <= 50; $i++ ) {
	UR_Login_Rate_Limiter::consume_attempt( "spray_user_{$i}", $spray_ip );
	UR_Login_Rate_Limiter::finalize_failed_attempt( "spray_user_{$i}", $spray_ip );
}
security_assert( false === UR_Login_Rate_Limiter::consume_attempt( 'fresh_target_user', $spray_ip ), 'Locked IP blocks fresh usernames after 50 spraying failures' );

// 10. Filtered lockout message is escaped before output.
$GLOBALS['mock_filters']['user_registration_login_lockout_message'] = array(
	function () {
		return '<script>alert(1)</script>';
	},
);
security_assert( '&lt;script&gt;alert(1)&lt;/script&gt;' === UR_Login_Rate_Limiter::get_lockout_message( $user, $ip_a ), 'Lockout message output is HTML-escaped' );
unset( $GLOBALS['mock_filters']['user_registration_login_lockout_message'] );
