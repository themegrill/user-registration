<?php
/**
 * Login rate limiter for plugin login endpoints.
 *
 * Tracks failed login attempts per (account, IP) pair and per client IP using
 * WordPress transients with atomic consume-before-authenticate semantics and
 * escalating lockout durations.
 *
 * @package UserRegistration/Classes
 * @since   x.x.x
 */

defined( 'ABSPATH' ) || exit;

/**
 * UR_Login_Rate_Limiter class.
 */
class UR_Login_Rate_Limiter {

	/**
	 * Transient prefix for per-(account, IP) rate-limit buckets.
	 *
	 * @var string
	 */
	const ACCOUNT_KEY_PREFIX = 'ur_login_rl_ua_';

	/**
	 * Transient prefix for per-IP rate-limit buckets.
	 *
	 * @var string
	 */
	const IP_KEY_PREFIX = 'ur_login_rl_ip_';

	/**
	 * Default maximum failed attempts per (account, IP) pair before lockout.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Default maximum failed attempts per IP before lockout.
	 *
	 * @var int
	 */
	const DEFAULT_IP_MAX_ATTEMPTS = 50;

	/**
	 * Default attempt counting window in seconds (15 minutes).
	 *
	 * @var int
	 */
	const DEFAULT_ATTEMPT_WINDOW = 900;

	/**
	 * Default base lockout duration in seconds (15 minutes).
	 *
	 * @var int
	 */
	const DEFAULT_LOCKOUT_DURATION = 900;

	/**
	 * Default maximum escalating lockout duration in seconds (24 hours).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_LOCKOUT_DURATION = 86400;

	/**
	 * Check whether login rate limiting is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'user_registration_login_rate_limit_enabled', true );
	}

	/**
	 * Resolve the client IP address for rate limiting.
	 *
	 * Uses REMOTE_ADDR directly by default so attackers cannot bypass rate
	 * limiting by rotating spoofable X-Forwarded-For or X-Real-IP headers.
	 * Sites behind a CDN or reverse proxy can register trusted proxy IPs via
	 * the `user_registration_login_trusted_proxies` filter, or supply a custom
	 * IP via `user_registration_login_rate_limit_ip`.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ip     = rest_is_ip_address( $raw_ip ) ? $raw_ip : '';
		}

		$trusted_proxies = (array) apply_filters( 'user_registration_login_trusted_proxies', array() );

		if ( '' !== $ip && in_array( $ip, $trusted_proxies, true ) ) {
			$forwarded = self::extract_forwarded_ip( $trusted_proxies );
			if ( '' !== $forwarded ) {
				$ip = $forwarded;
			}
		}

		return (string) apply_filters( 'user_registration_login_rate_limit_ip', $ip );
	}

	/**
	 * Check whether a WP_Error code from wp_signon() represents a non-credential
	 * validation or account-status error that should not count as a failed password attempt.
	 *
	 * @param string $error_code WP_Error code.
	 *
	 * @return bool
	 */
	public static function is_excluded_error_code( $error_code ) {
		$excluded = array(
			'empty_username',
			'empty_password',
			'pending_approval',
			'denied_access',
			'user_email_not_verified',
			'user_payment_pending',
			'user_not_approved',
			'disable_user',
		);

		$excluded = (array) apply_filters( 'user_registration_login_rate_limit_excluded_errors', $excluded );

		return in_array( (string) $error_code, $excluded, true );
	}

	/**
	 * Check if either the (account, IP) pair or the client IP is currently locked out.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return bool
	 */
	public static function is_locked( $identifier, $ip = null ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		return self::get_remaining_lockout_seconds( $identifier, $ip ) > 0;
	}

	/**
	 * Get the remaining lockout duration in seconds across (account, IP) and IP buckets.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return int Remaining seconds (0 if not locked).
	 */
	public static function get_remaining_lockout_seconds( $identifier, $ip = null ) {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		$now       = time();
		$remaining = 0;

		foreach ( self::get_bucket_keys( $identifier, $ip ) as $key ) {
			$state = self::get_bucket_state( $key );
			if ( $state['locked_until'] > $now ) {
				$remaining = max( $remaining, $state['locked_until'] - $now );
			}
		}

		return $remaining;
	}

	/**
	 * Atomically check lockout/capacity and reserve an attempt slot before authentication.
	 *
	 * Checking and incrementing in one locked critical section closes the
	 * concurrent-burst TOCTOU window where parallel requests could otherwise
	 * all pass is_locked() before wp_signon() finishes.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return bool True if the attempt is allowed to proceed, false if locked/over capacity.
	 */
	public static function consume_attempt( $identifier, $ip = null ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		$resolved_ip    = null === $ip ? self::get_client_ip() : (string) $ip;
		$account_key    = self::get_account_key( $identifier, $resolved_ip );
		$ip_key         = self::get_ip_key( $resolved_ip );
		$account_limit  = max( 1, (int) apply_filters( 'user_registration_login_max_attempts', self::DEFAULT_MAX_ATTEMPTS ) );
		$ip_limit       = max( 1, (int) apply_filters( 'user_registration_login_ip_max_attempts', self::DEFAULT_IP_MAX_ATTEMPTS ) );
		$account_burned = false;

		if ( '' !== $account_key ) {
			if ( ! self::try_consume_bucket( $account_key, $account_limit ) ) {
				return false;
			}
			$account_burned = true;
		}

		if ( '' !== $ip_key ) {
			if ( ! self::try_consume_bucket( $ip_key, $ip_limit ) ) {
				if ( $account_burned ) {
					self::decrement_bucket( $account_key );
				}
				return false;
			}
		}

		return true;
	}

	/**
	 * Finalize a failed attempt that was reserved by consume_attempt(), activating
	 * escalating lockout once a bucket reaches its threshold.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return bool True if this failure triggered a lockout, false otherwise.
	 */
	public static function finalize_failed_attempt( $identifier, $ip = null ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$resolved_ip   = null === $ip ? self::get_client_ip() : (string) $ip;
		$account_key   = self::get_account_key( $identifier, $resolved_ip );
		$ip_key        = self::get_ip_key( $resolved_ip );
		$account_limit = max( 1, (int) apply_filters( 'user_registration_login_max_attempts', self::DEFAULT_MAX_ATTEMPTS ) );
		$ip_limit      = max( 1, (int) apply_filters( 'user_registration_login_ip_max_attempts', self::DEFAULT_IP_MAX_ATTEMPTS ) );
		$locked        = false;

		if ( '' !== $account_key && self::maybe_lock_bucket( $account_key, $account_limit, $identifier, $resolved_ip ) ) {
			$locked = true;
		}

		if ( '' !== $ip_key && self::maybe_lock_bucket( $ip_key, $ip_limit, $identifier, $resolved_ip ) ) {
			$locked = true;
		}

		return $locked;
	}

	/**
	 * Refund a reserved attempt when authentication returns an excluded non-credential error.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return void
	 */
	public static function refund_attempt( $identifier, $ip = null ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$resolved_ip = null === $ip ? self::get_client_ip() : (string) $ip;
		$account_key = self::get_account_key( $identifier, $resolved_ip );
		$ip_key      = self::get_ip_key( $resolved_ip );

		if ( '' !== $account_key ) {
			self::decrement_bucket( $account_key );
		}

		if ( '' !== $ip_key ) {
			self::decrement_bucket( $ip_key );
		}
	}

	/**
	 * Clear failed attempt state for an (account, IP) pair after a successful login,
	 * and refund the single IP attempt reserved for this successful request.
	 *
	 * The rest of the per-IP bucket is preserved so a single valid account cannot
	 * wipe an IP bucket used for password spraying against other accounts.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return void
	 */
	public static function clear_attempts( $identifier, $ip = null ) {
		$resolved_ip = null === $ip ? self::get_client_ip() : (string) $ip;
		$account_key = self::get_account_key( $identifier, $resolved_ip );
		$ip_key      = self::get_ip_key( $resolved_ip );

		if ( '' !== $account_key ) {
			$lock_type = self::acquire_lock( $account_key );
			try {
				delete_transient( $account_key );
			} finally {
				self::release_lock( $account_key, $lock_type );
			}
		}

		if ( '' !== $ip_key ) {
			self::decrement_bucket( $ip_key );
		}
	}

	/**
	 * Build an escaped user-facing lockout message with the remaining wait time.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return string
	 */
	public static function get_lockout_message( $identifier, $ip = null ) {
		$remaining = self::get_remaining_lockout_seconds( $identifier, $ip );

		if ( $remaining > 0 ) {
			$wait_time = human_time_diff( time(), time() + $remaining );
			$message   = sprintf(
				/* translators: %s: human-readable time duration until login can be retried. */
				__( 'Too many failed login attempts. Please try again in %s.', 'user-registration' ),
				$wait_time
			);
		} else {
			$message = __( 'Too many login attempts in progress. Please try again shortly.', 'user-registration' );
		}

		return esc_html( (string) apply_filters( 'user_registration_login_lockout_message', $message, $remaining, $identifier ) );
	}

	/**
	 * Atomically reserve one attempt slot in a bucket if it is neither locked nor at capacity.
	 *
	 * @param string $key       Transient key.
	 * @param int    $max_limit Maximum attempts allowed in the current cycle.
	 *
	 * @return bool True if reserved, false if locked or already at capacity.
	 */
	private static function try_consume_bucket( $key, $max_limit ) {
		$now            = time();
		$attempt_window = max( 60, (int) apply_filters( 'user_registration_login_attempt_window', self::DEFAULT_ATTEMPT_WINDOW ) );
		$lock_type      = self::acquire_lock( $key );

		try {
			$state = self::get_bucket_state( $key );

			if ( $state['locked_until'] > $now || $state['attempts'] >= $max_limit ) {
				return false;
			}

			$state['attempts']++;
			set_transient( $key, $state, $attempt_window );

			return true;
		} finally {
			self::release_lock( $key, $lock_type );
		}
	}

	/**
	 * Transition a bucket into escalating lockout if its attempts have reached the limit.
	 *
	 * @param string $key        Transient key.
	 * @param int    $max_limit  Maximum failed attempts before lockout.
	 * @param string $identifier Canonical username or submitted login string.
	 * @param string $ip         Client IP address.
	 *
	 * @return bool True if the bucket is now locked.
	 */
	private static function maybe_lock_bucket( $key, $max_limit, $identifier, $ip ) {
		$now             = time();
		$attempt_window  = max( 60, (int) apply_filters( 'user_registration_login_attempt_window', self::DEFAULT_ATTEMPT_WINDOW ) );
		$base_lockout    = max( 60, (int) apply_filters( 'user_registration_login_lockout_duration', self::DEFAULT_LOCKOUT_DURATION ) );
		$max_lockout     = max( $base_lockout, (int) apply_filters( 'user_registration_login_max_lockout_duration', self::DEFAULT_MAX_LOCKOUT_DURATION ) );
		$lock_type       = self::acquire_lock( $key );
		$triggered_lock  = false;
		$locked_duration = 0;
		$lockouts_count  = 0;

		try {
			$state = self::get_bucket_state( $key );

			// Do not extend an already active lockout.
			if ( $state['locked_until'] > $now ) {
				return true;
			}

			if ( $state['attempts'] >= $max_limit ) {
				$state['lockouts']++;
				$exponent              = min( 20, max( 0, $state['lockouts'] - 1 ) );
				$locked_duration       = min( $max_lockout, (int) ( $base_lockout * pow( 2, $exponent ) ) );
				$state['attempts']     = 0;
				$state['locked_until'] = $now + $locked_duration;
				$lockouts_count        = $state['lockouts'];
				$triggered_lock        = true;

				set_transient( $key, $state, $locked_duration + $attempt_window );
			}
		} finally {
			self::release_lock( $key, $lock_type );
		}

		if ( $triggered_lock ) {
			/**
			 * Fires when a login rate-limit lockout is triggered.
			 *
			 * @param string $identifier Account identifier that triggered lockout.
			 * @param string $ip         Client IP address.
			 * @param int    $duration   Lockout duration in seconds.
			 * @param int    $lockouts   Consecutive lockout count.
			 */
			do_action( 'user_registration_login_locked_out', $identifier, $ip, $locked_duration, $lockouts_count );
		}

		return $triggered_lock;
	}

	/**
	 * Atomically decrement the attempt counter in a bucket by 1.
	 *
	 * @param string $key Transient key.
	 *
	 * @return void
	 */
	private static function decrement_bucket( $key ) {
		$attempt_window = max( 60, (int) apply_filters( 'user_registration_login_attempt_window', self::DEFAULT_ATTEMPT_WINDOW ) );
		$lock_type      = self::acquire_lock( $key );

		try {
			$state = self::get_bucket_state( $key );
			if ( $state['attempts'] > 0 ) {
				$state['attempts']--;
				if ( 0 === $state['attempts'] && 0 === $state['lockouts'] && 0 === $state['locked_until'] ) {
					delete_transient( $key );
				} else {
					set_transient( $key, $state, $attempt_window );
				}
			}
		} finally {
			self::release_lock( $key, $lock_type );
		}
	}

	/**
	 * Extract the client IP from forwarded headers when REMOTE_ADDR is a trusted proxy.
	 *
	 * @param array $trusted_proxies List of configured trusted proxy IP addresses.
	 *
	 * @return string Validated client IP or empty string.
	 */
	private static function extract_forwarded_ip( $trusted_proxies ) {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( rest_is_ip_address( $cf_ip ) ) {
				return $cf_ip;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_list = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$parts    = array_reverse( array_map( 'trim', explode( ',', $raw_list ) ) );

			// Walk right-to-left to pick the first untrusted hop added by the trusted proxy.
			foreach ( $parts as $candidate ) {
				if ( rest_is_ip_address( $candidate ) && ! in_array( $candidate, $trusted_proxies, true ) ) {
					return $candidate;
				}
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$real_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( rest_is_ip_address( $real_ip ) ) {
				return $real_ip;
			}
		}

		return '';
	}

	/**
	 * Acquire a per-bucket lock to serialize concurrent read/modify/write updates.
	 *
	 * @param string $key Bucket transient key.
	 *
	 * @return string Lock mechanism acquired ('cache', 'db', or 'none').
	 */
	private static function acquire_lock( $key ) {
		$lock_name = 'ur_rl_' . md5( $key );

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_add' ) ) {
			for ( $i = 0; $i < 20; $i++ ) {
				if ( wp_cache_add( $lock_name, 1, 'ur_login_rate_limit', 5 ) ) {
					return 'cache';
				}
				usleep( 25000 );
			}
			return 'none';
		}

		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, 2 ) );
			if ( '1' === (string) $acquired ) {
				return 'db';
			}
		}

		return 'none';
	}

	/**
	 * Release a previously acquired per-bucket lock.
	 *
	 * @param string $key       Bucket transient key.
	 * @param string $lock_type Lock mechanism returned by acquire_lock().
	 *
	 * @return void
	 */
	private static function release_lock( $key, $lock_type ) {
		if ( 'none' === $lock_type ) {
			return;
		}

		$lock_name = 'ur_rl_' . md5( $key );

		if ( 'cache' === $lock_type && function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $lock_name, 'ur_login_rate_limit' );
			return;
		}

		global $wpdb;
		if ( 'db' === $lock_type && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
		}
	}

	/**
	 * Read and normalize a rate-limit bucket from transients.
	 *
	 * @param string $key Transient key.
	 *
	 * @return array{attempts: int, lockouts: int, locked_until: int}
	 */
	private static function get_bucket_state( $key ) {
		$raw = get_transient( $key );

		if ( ! is_array( $raw ) ) {
			return array(
				'attempts'     => 0,
				'lockouts'     => 0,
				'locked_until' => 0,
			);
		}

		return array(
			'attempts'     => isset( $raw['attempts'] ) ? max( 0, (int) $raw['attempts'] ) : 0,
			'lockouts'     => isset( $raw['lockouts'] ) ? max( 0, (int) $raw['lockouts'] ) : 0,
			'locked_until' => isset( $raw['locked_until'] ) ? max( 0, (int) $raw['locked_until'] ) : 0,
		);
	}

	/**
	 * Collect all applicable transient keys for an (account, IP) pair and IP.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return array
	 */
	private static function get_bucket_keys( $identifier, $ip = null ) {
		$keys        = array();
		$resolved_ip = null === $ip ? self::get_client_ip() : (string) $ip;
		$account_key = self::get_account_key( $identifier, $resolved_ip );
		$ip_key      = self::get_ip_key( $resolved_ip );

		if ( '' !== $account_key ) {
			$keys[] = $account_key;
		}

		if ( '' !== $ip_key ) {
			$keys[] = $ip_key;
		}

		return $keys;
	}

	/**
	 * Build the normalized transient key for an (account, IP) pair.
	 *
	 * Scoping the account bucket to the client IP prevents a remote attacker
	 * from locking out legitimate users or administrators on other IPs.
	 *
	 * @param string $identifier Canonical username or submitted login string.
	 * @param string $ip         Client IP address.
	 *
	 * @return string Empty string when identifier is empty.
	 */
	private static function get_account_key( $identifier, $ip = '' ) {
		$normalized_id = strtolower( trim( (string) $identifier ) );

		if ( '' === $normalized_id ) {
			return '';
		}

		$normalized_ip = strtolower( trim( (string) $ip ) );

		return self::ACCOUNT_KEY_PREFIX . md5( $normalized_id . '|' . $normalized_ip );
	}

	/**
	 * Build the normalized transient key for a client IP address.
	 *
	 * @param string $ip Client IP address.
	 *
	 * @return string Empty string when IP is empty.
	 */
	private static function get_ip_key( $ip ) {
		$normalized = strtolower( trim( (string) $ip ) );

		if ( '' === $normalized ) {
			return '';
		}

		return self::IP_KEY_PREFIX . md5( $normalized );
	}
}
