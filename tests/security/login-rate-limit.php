<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Isolated security regression fixture.
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

require __DIR__ . '/bootstrap.php';

$GLOBALS['mock_transients'] = array();
$GLOBALS['mock_filters']    = array();
$GLOBALS['mock_actions']    = array();
$GLOBALS['mock_locks']      = array();

$GLOBALS['mock_stale_cache'] = array();

function wp_using_ext_object_cache() {
	return false;
}

function wp_cache_delete( $key, $group = '' ) {
	if ( 'options' === $group && 0 === strpos( $key, '_transient_' ) ) {
		unset( $GLOBALS['mock_stale_cache'][ substr( $key, strlen( '_transient_' ) ) ] );
	}
	return true;
}

function get_transient( $key ) {
	if ( isset( $GLOBALS['mock_stale_cache'][ $key ] ) ) {
		return $GLOBALS['mock_stale_cache'][ $key ];
	}
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

function add_filter( $tag, $cb ) {
	$GLOBALS['mock_filters'][ $tag ][] = $cb;
	return true;
}

class WP_User {
	public $ID         = 0;
	public $user_login = '';
	public $user_email = '';
	public $roles      = array();
}

function do_action( $tag ) {
	$GLOBALS['mock_actions'][ $tag ][] = array_slice( func_get_args(), 1 );
}

function rest_is_ip_address( $ip ) {
	return filter_var( $ip, FILTER_VALIDATE_IP );
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

eval( security_function( 'includes/functions-ur-core.php', 'ur_get_ip_address' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
require dirname( __DIR__, 2 ) . '/includes/class-ur-login-rate-limiter.php';

// 0. Disabled by default: no state is written and no advisory lock is taken.
security_assert( false === UR_Login_Rate_Limiter::is_enabled(), 'Rate limiting is disabled by default' );
for ( $i = 1; $i <= 10; $i++ ) {
	UR_Login_Rate_Limiter::consume_attempt( 'default_off_user', '203.0.113.5' );
	UR_Login_Rate_Limiter::finalize_failed_attempt( 'default_off_user', '203.0.113.5' );
}
UR_Login_Rate_Limiter::refund_attempt( 'default_off_user', '203.0.113.5' );
UR_Login_Rate_Limiter::clear_attempts( 'default_off_user', '203.0.113.5' );
security_assert( true === UR_Login_Rate_Limiter::consume_attempt( 'default_off_user', '203.0.113.5' ) && false === UR_Login_Rate_Limiter::is_locked( 'default_off_user', '203.0.113.5' ), 'Disabled limiter never blocks or locks' );
security_assert( array() === $GLOBALS['mock_transients'] && array() === $GLOBALS['mock_locks'], 'Disabled limiter writes no transients and takes no locks' );

// Opt in, as a site owner would via the filter.
$GLOBALS['mock_filters']['user_registration_login_rate_limit_enabled'] = array(
	static function () {
		return true;
	},
);

// 1. get_client_ip() delegates to ur_get_ip_address() and honors user_registration_trusted_proxies (including CIDRs).
$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
$_SERVER['HTTP_X_REAL_IP']       = '5.6.7.8';
security_assert( '203.0.113.10' === UR_Login_Rate_Limiter::get_client_ip(), 'Retain validated REMOTE_ADDR and ignore spoofable proxy headers' );

$GLOBALS['mock_filters']['user_registration_trusted_proxies'] = array(
	function () {
		return array( '203.0.113.0/24' );
	},
);

$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 198.51.100.2, 203.0.113.10';
security_assert( '198.51.100.2' === UR_Login_Rate_Limiter::get_client_ip(), 'Extract right-most untrusted forwarded IP when REMOTE_ADDR matches trusted proxy CIDR' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';
$_SERVER['HTTP_X_REAL_IP']        = '8.8.8.8';
security_assert( '198.51.100.2' === UR_Login_Rate_Limiter::get_client_ip(), 'Client-set CF-Connecting-IP and X-Real-IP are ignored when X-Forwarded-For yields a client IP' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.11, 203.0.113.10';
security_assert( '203.0.113.10' === UR_Login_Rate_Limiter::get_client_ip(), 'When all X-Forwarded-For hops are trusted proxies, fall back to REMOTE_ADDR rather than X-Real-IP' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $GLOBALS['mock_filters']['user_registration_trusted_proxies'] );

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
security_assert( true === UR_Login_Rate_Limiter::is_locked( 'fresh_target_user', $spray_ip ), 'IP bucket is actually locked, not merely full, after 50 spraying failures' );
$ip_lock_events = array_filter(
	$GLOBALS['mock_actions']['user_registration_login_locked_out'],
	static function ( $args ) use ( $spray_ip ) {
		return $spray_ip === $args[1];
	}
);
security_assert( 1 === count( $ip_lock_events ), 'Locked-out action fired once for the sprayed IP' );
security_assert( false === UR_Login_Rate_Limiter::consume_attempt( 'fresh_target_user', $spray_ip ), 'Locked IP blocks fresh usernames after 50 spraying failures' );

// 9b. A verified password settles its slot at the authenticate filter, before add-ons that exit (2FA/SMS) can leave it reserved.
$otp_ip = '198.51.100.88';
$all_ok = true;
for ( $i = 1; $i <= 10; $i++ ) {
	unset( $GLOBALS['mock_filters']['authenticate'] );
	$all_ok = $all_ok && UR_Login_Rate_Limiter::consume_attempt( 'otp_user', $otp_ip );
	UR_Login_Rate_Limiter::settle_on_verified_password( 'otp_user', $otp_ip );
	apply_filters( 'authenticate', new WP_User() ); // The add-on then redirects and exits, so no later settle runs.
}
security_assert( $all_ok && true === UR_Login_Rate_Limiter::consume_attempt( 'otp_user', $otp_ip ), 'Ten exit-after-verification logins never exhaust the account or IP bucket' );
UR_Login_Rate_Limiter::refund_attempt( 'otp_user', $otp_ip );

$settle_ip = '198.51.100.89';
for ( $i = 1; $i <= 3; $i++ ) {
	UR_Login_Rate_Limiter::consume_attempt( "other_{$i}", $settle_ip );
	UR_Login_Rate_Limiter::finalize_failed_attempt( "other_{$i}", $settle_ip );
}
unset( $GLOBALS['mock_filters']['authenticate'] );
UR_Login_Rate_Limiter::consume_attempt( 'settle_user', $settle_ip );
UR_Login_Rate_Limiter::settle_on_verified_password( 'settle_user', $settle_ip );
apply_filters( 'authenticate', new WP_User() );
UR_Login_Rate_Limiter::clear_attempts( 'settle_user', $settle_ip ); // The normal success path runs too when nothing exits.
$settle_state = get_transient( UR_Login_Rate_Limiter::IP_KEY_PREFIX . md5( $settle_ip ) );
security_assert( is_array( $settle_state ) && 3 === $settle_state['attempts'], 'Settling twice refunds the IP slot only once' );
security_assert( false === get_transient( UR_Login_Rate_Limiter::IP_KEY_PREFIX . md5( $otp_ip ) ) || 0 === get_transient( UR_Login_Rate_Limiter::IP_KEY_PREFIX . md5( $otp_ip ) )['attempts'], 'Exit-after-verification logins leave no stale IP counter' );

// 10. Filtered lockout message is escaped before output.
$GLOBALS['mock_filters']['user_registration_login_lockout_message'] = array(
	function () {
		return '<script>alert(1)</script>';
	},
);
security_assert( '&lt;script&gt;alert(1)&lt;/script&gt;' === UR_Login_Rate_Limiter::get_lockout_message( $user, $ip_a ), 'Lockout message output is HTML-escaped' );
unset( $GLOBALS['mock_filters']['user_registration_login_lockout_message'] );

// 11. End-to-end integration through ur_process_login() (lockout + unknown-email branches).

/**
 * Minimal WP_Error test double for ur_process_login() integration tests.
 */
class WP_Error {
	public $errors = array();
	public function __construct( $code = '', $message = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ] = array( $message );
		}
	}
	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return empty( $codes ) ? '' : $codes[0];
	}
	public function get_error_message() {
		$code = $this->get_error_code();
		return '' === $code ? '' : $this->errors[ $code ][0];
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function ur_clean( $value ) {
	return is_array( $value ) ? array_map( 'ur_clean', $value ) : sanitize_text_field( $value );
}

function ur_option_checked( $option, $default = false ) {
	return (bool) get_option( $option, $default );
}

function ur_string_to_bool( $string ) {
	return is_bool( $string ) ? $string : ( 'yes' === strtolower( (string) $string ) || 1 === $string || 'true' === strtolower( (string) $string ) || '1' === $string );
}

function ur_captcha_type_has_keys() {
	return false;
}

function ur_is_ajax_login_enabled() {
	return true;
}

function sanitize_user( $username ) {
	return trim( (string) $username );
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function is_multisite() {
	return false;
}

function is_ssl() {
	return false;
}

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['mock_users'] ?? array() as $mock_user ) {
		if ( 'login' === $field && $mock_user->user_login === $value ) {
			return $mock_user;
		}
		if ( 'email' === $field && $mock_user->user_email === $value ) {
			return $mock_user;
		}
	}
	return false;
}

function wp_signon( $credentials, $secure_cookie = '' ) {
	$GLOBALS['mock_signon_calls'] = ( $GLOBALS['mock_signon_calls'] ?? 0 ) + 1;
	return $GLOBALS['mock_signon_result'];
}

eval( security_function( 'includes/functions-ur-core.php', 'ur_process_login' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$alice             = new WP_User();
$alice->ID         = 42;
$alice->user_login = 'alice_login';
$alice->user_email = 'alice_login@example.com';
$alice->roles      = array( 'subscriber' );

$GLOBALS['mock_users']         = array( $alice );
$GLOBALS['mock_signon_calls']  = 0;
$GLOBALS['mock_signon_result'] = new WP_Error( 'incorrect_password', 'Incorrect password' );
$GLOBALS['options']            = array();
unset( $GLOBALS['mock_filters']['user_registration_trusted_proxies'] );

// 11a. Five wrong passwords through ur_process_login() lock the account; 6th attempt via email short-circuits before wp_signon().
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
$_POST                  = array(
	'username' => 'alice_login',
	'password' => 'wrong-pass',
);
for ( $i = 1; $i <= 4; $i++ ) {
	$resp = security_response(
		static function () {
			ur_process_login( 'valid' );
		}
	);
	security_assert( false === $resp->success && false !== strpos( $resp->data['message'], 'incorrect' ), "ur_process_login attempt {$i} returns normal incorrect-password error" );
}
$resp_5 = security_response(
	static function () {
		ur_process_login( 'valid' );
	}
);
security_assert( false === $resp_5->success && false !== strpos( $resp_5->data['message'], 'Too many failed login attempts' ), 'ur_process_login 5th failed attempt triggers lockout message' );
security_assert( 5 === $GLOBALS['mock_signon_calls'], 'wp_signon() was called for the first 5 attempts' );

$_POST['username'] = 'alice_login@example.com';
$resp_6            = security_response(
	static function () {
		ur_process_login( 'valid' );
	}
);
security_assert( false === $resp_6->success && false !== strpos( $resp_6->data['message'], 'Too many failed login attempts' ), 'ur_process_login 6th attempt via email is blocked by canonical lockout' );
security_assert( 5 === $GLOBALS['mock_signon_calls'], 'Locked attempt in ur_process_login() short-circuits before wp_signon()' );

// 11b. Unknown-email branch (default username-or-email mode) counts failures and locks on the 5th attempt.
$_SERVER['REMOTE_ADDR'] = '203.0.113.201';
$_POST                  = array(
	'username' => 'ghost@example.com',
	'password' => 'anything',
);
for ( $i = 1; $i <= 4; $i++ ) {
	$resp = security_response(
		static function () {
			ur_process_login( 'valid' );
		}
	);
	security_assert( false === $resp->success && false !== strpos( $resp->data['message'], 'could not be found' ), "Unknown-email attempt {$i} returns unknown_email error" );
}
$resp_unknown_5 = security_response(
	static function () {
		ur_process_login( 'valid' );
	}
);
security_assert( false === $resp_unknown_5->success && false !== strpos( $resp_unknown_5->data['message'], 'Too many failed login attempts' ), 'Unknown-email 5th attempt triggers lockout through ur_process_login()' );
$resp_unknown_6 = security_response(
	static function () {
		ur_process_login( 'valid' );
	}
);
security_assert( false === $resp_unknown_6->success && false !== strpos( $resp_unknown_6->data['message'], 'Too many failed login attempts' ), 'Unknown-email 6th attempt stays locked at top of ur_process_login()' );
security_assert( 5 === $GLOBALS['mock_signon_calls'], 'Unknown-email branch never reaches wp_signon()' );

// 11c. Unknown-email branch in email-only login mode also counts failures and locks on the 5th attempt.
$GLOBALS['options']['user_registration_general_setting_login_options_with'] = 'email';
$_SERVER['REMOTE_ADDR'] = '203.0.113.202';
$_POST                  = array(
	'username' => 'ghost-email-only@example.com',
	'password' => 'anything',
);
for ( $i = 1; $i <= 4; $i++ ) {
	$resp = security_response(
		static function () {
			ur_process_login( 'valid' );
		}
	);
	security_assert( false === $resp->success && false !== strpos( $resp->data['message'], 'could not be found' ), "Email-only unknown-email attempt {$i} returns unknown_email error" );
}
$resp_email_only_5 = security_response(
	static function () {
		ur_process_login( 'valid' );
	}
);
security_assert( false === $resp_email_only_5->success && false !== strpos( $resp_email_only_5->data['message'], 'Too many failed login attempts' ), 'Email-only unknown-email 5th attempt triggers lockout through ur_process_login()' );

// 12. Regression: a read inside the bucket lock must bypass the request's stale option cache (simultaneous requests lost increments).
$race_ip  = '203.0.113.210';
$race_key = UR_Login_Rate_Limiter::IP_KEY_PREFIX . md5( $race_ip );
set_transient(
	$race_key,
	array(
		'attempts'     => 2,
		'lockouts'     => 0,
		'locked_until' => 0,
	),
	900
);
$GLOBALS['mock_stale_cache'][ $race_key ] = array(
	'attempts'     => 0,
	'lockouts'     => 0,
	'locked_until' => 0,
);
UR_Login_Rate_Limiter::consume_attempt( 'race_user', $race_ip );
security_assert( 3 === get_transient( $race_key )['attempts'], 'Locked read ignores stale cached counter and keeps the concurrent increment' );
