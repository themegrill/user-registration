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

// 1. get_client_ip() retains the validated REMOTE_ADDR string even when rest_is_ip_address() returns bool true, and ignores spoofable proxy headers.
$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
$_SERVER['HTTP_X_REAL_IP']       = '5.6.7.8';
security_assert( '203.0.113.10' === UR_Login_Rate_Limiter::get_client_ip(), 'Retain validated REMOTE_ADDR and ignore spoofable proxy headers' );

$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
security_assert( '' === UR_Login_Rate_Limiter::get_client_ip(), 'Return empty string when REMOTE_ADDR is invalid' );

// 2. Per-account lockout triggers on the 5th failed attempt and persists across IP rotation and case changes.
$user = 'alice_login';
$ip   = '198.51.100.42';
for ( $i = 1; $i <= 4; $i++ ) {
	security_assert( false === UR_Login_Rate_Limiter::record_failed_attempt( $user, $ip ), "Attempt {$i} should not lock" );
}
security_assert( true === UR_Login_Rate_Limiter::record_failed_attempt( $user, $ip ), 'Attempt 5 triggers account lockout' );
security_assert( true === UR_Login_Rate_Limiter::is_locked( 'ALICE_LOGIN', '198.51.100.99' ), 'Account lockout persists across IP rotation and case change' );

// 3. Bucket updates acquire and release the DB lock cleanly.
security_assert( in_array( 'acquire', $GLOBALS['mock_locks'], true ) && in_array( 'release', $GLOBALS['mock_locks'], true ), 'Bucket increment acquires and releases advisory lock' );

// 4. Active lockout is not extended by repeated attempts while locked.
$remaining_initial = UR_Login_Rate_Limiter::get_remaining_lockout_seconds( $user, $ip );
UR_Login_Rate_Limiter::record_failed_attempt( $user, $ip );
security_assert( UR_Login_Rate_Limiter::get_remaining_lockout_seconds( $user, $ip ) <= $remaining_initial, 'Repeated attempts while locked do not extend active lockout' );

// 5. Second lockout cycle escalates duration from 15 mins to 30 mins.
$account_key           = UR_Login_Rate_Limiter::ACCOUNT_KEY_PREFIX . md5( strtolower( $user ) );
$state                 = get_transient( $account_key );
$state['locked_until'] = time() - 1;
set_transient( $account_key, $state, 900 );
for ( $i = 1; $i <= 5; $i++ ) {
	UR_Login_Rate_Limiter::record_failed_attempt( $user, '198.51.100.43' );
}
$remaining_second = UR_Login_Rate_Limiter::get_remaining_lockout_seconds( $user, '198.51.100.43' );
security_assert( $remaining_second >= 1795 && $remaining_second <= 1800, 'Second lockout escalates to 30 minutes' );

// 6. clear_attempts() resets the account bucket without resetting the per-IP bucket.
UR_Login_Rate_Limiter::clear_attempts( $user );
security_assert( false === UR_Login_Rate_Limiter::is_locked( $user, '198.51.100.43' ), 'clear_attempts() unlocks the account' );
$ip_state = get_transient( UR_Login_Rate_Limiter::IP_KEY_PREFIX . md5( '198.51.100.43' ) );
security_assert( is_array( $ip_state ) && 5 === $ip_state['attempts'], 'clear_attempts() preserves per-IP counter' );

// 7. Per-IP password spraying across 20 distinct usernames locks the IP.
$spray_ip = '198.51.100.77';
for ( $i = 1; $i <= 19; $i++ ) {
	UR_Login_Rate_Limiter::record_failed_attempt( "spray_user_{$i}", $spray_ip );
}
security_assert( true === UR_Login_Rate_Limiter::record_failed_attempt( 'spray_user_20', $spray_ip ), '20th failed attempt from one IP triggers IP lockout' );
security_assert( true === UR_Login_Rate_Limiter::is_locked( 'fresh_target_user', $spray_ip ), 'Locked IP blocks fresh usernames' );

// 8. ur_process_login() integrates UR_Login_Rate_Limiter for lockout check, failure recording, and success clearing.
$core_src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/functions-ur-core.php' );
security_assert(
	false !== strpos( $core_src, 'UR_Login_Rate_Limiter::is_locked(' )
	&& false !== strpos( $core_src, 'UR_Login_Rate_Limiter::record_failed_attempt(' )
	&& false !== strpos( $core_src, 'UR_Login_Rate_Limiter::clear_attempts(' ),
	'ur_process_login() checks lockout, records failures, and clears attempts on success'
);
