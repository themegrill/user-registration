<?php
/**
 * Login rate limiter for plugin login endpoints.
 *
 * Tracks failed login attempts per account identifier and per client IP using
 * WordPress transients with escalating lockout durations.
 *
 * @package UserRegistration/Classes
 * @since   5.3.3
 */

defined( 'ABSPATH' ) || exit;

/**
 * UR_Login_Rate_Limiter class.
 */
class UR_Login_Rate_Limiter {

	/**
	 * Transient prefix for per-account rate-limit buckets.
	 *
	 * @var string
	 */
	const ACCOUNT_KEY_PREFIX = 'ur_login_rl_u_';

	/**
	 * Transient prefix for per-IP rate-limit buckets.
	 *
	 * @var string
	 */
	const IP_KEY_PREFIX = 'ur_login_rl_ip_';

	/**
	 * Default maximum failed attempts per account before lockout.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Default maximum failed attempts per IP before lockout.
	 *
	 * @var int
	 */
	const DEFAULT_IP_MAX_ATTEMPTS = 20;

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
	 * Uses REMOTE_ADDR directly rather than ur_get_ip_address() so attackers
	 * cannot bypass the IP bucket by rotating spoofable X-Forwarded-For or
	 * X-Real-IP headers. Sites behind a trusted proxy can override via filter.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ip     = rest_is_ip_address( $raw_ip ) ? $raw_ip : '';
		}

		return (string) apply_filters( 'user_registration_login_rate_limit_ip', $ip );
	}

	/**
	 * Check if either the account identifier or the client IP is currently locked out.
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
	 * Get the remaining lockout duration in seconds across account and IP buckets.
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
	 * Record a failed login attempt for the account identifier and client IP.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return bool True if this attempt triggered a lockout, false otherwise.
	 */
	public static function record_failed_attempt( $identifier, $ip = null ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$resolved_ip = null === $ip ? self::get_client_ip() : (string) $ip;
		$locked      = false;

		$account_key = self::get_account_key( $identifier );
		if ( '' !== $account_key ) {
			$account_limit = max( 1, (int) apply_filters( 'user_registration_login_max_attempts', self::DEFAULT_MAX_ATTEMPTS ) );
			if ( self::increment_bucket( $account_key, $account_limit, $identifier, $resolved_ip ) ) {
				$locked = true;
			}
		}

		$ip_key = self::get_ip_key( $resolved_ip );
		if ( '' !== $ip_key ) {
			$ip_limit = max( 1, (int) apply_filters( 'user_registration_login_ip_max_attempts', self::DEFAULT_IP_MAX_ATTEMPTS ) );
			if ( self::increment_bucket( $ip_key, $ip_limit, $identifier, $resolved_ip ) ) {
				$locked = true;
			}
		}

		return $locked;
	}

	/**
	 * Clear failed attempt state for an account after a successful login.
	 *
	 * Only the per-account bucket is cleared so a single valid account cannot
	 * reset an IP bucket used for password spraying against other accounts.
	 *
	 * @param string $identifier Canonical username or submitted login string.
	 *
	 * @return void
	 */
	public static function clear_attempts( $identifier ) {
		$account_key = self::get_account_key( $identifier );

		if ( '' !== $account_key ) {
			$lock_type = self::acquire_lock( $account_key );
			try {
				delete_transient( $account_key );
			} finally {
				self::release_lock( $account_key, $lock_type );
			}
		}
	}

	/**
	 * Build a user-facing lockout message with the remaining wait time.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return string
	 */
	public static function get_lockout_message( $identifier, $ip = null ) {
		$remaining = max( 1, self::get_remaining_lockout_seconds( $identifier, $ip ) );
		$wait_time = human_time_diff( time(), time() + $remaining );

		$message = sprintf(
			/* translators: %s: human-readable time duration until login can be retried. */
			esc_html__( 'Too many failed login attempts. Please try again in %s.', 'user-registration' ),
			esc_html( $wait_time )
		);

		return (string) apply_filters( 'user_registration_login_lockout_message', $message, $remaining, $identifier );
	}

	/**
	 * Increment a single rate-limit bucket and activate escalating lockout when threshold is reached.
	 *
	 * @param string $key        Transient key.
	 * @param int    $max_limit  Maximum failed attempts before lockout.
	 * @param string $identifier Canonical username or submitted login string.
	 * @param string $ip         Client IP address.
	 *
	 * @return bool True if the bucket entered lockout on this increment.
	 */
	private static function increment_bucket( $key, $max_limit, $identifier, $ip ) {
		$now            = time();
		$attempt_window = max( 60, (int) apply_filters( 'user_registration_login_attempt_window', self::DEFAULT_ATTEMPT_WINDOW ) );
		$base_lockout   = max( 60, (int) apply_filters( 'user_registration_login_lockout_duration', self::DEFAULT_LOCKOUT_DURATION ) );
		$max_lockout    = max( $base_lockout, (int) apply_filters( 'user_registration_login_max_lockout_duration', self::DEFAULT_MAX_LOCKOUT_DURATION ) );

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

			$state['attempts']++;

			if ( $state['attempts'] >= $max_limit ) {
				$state['lockouts']++;
				$exponent              = min( 20, max( 0, $state['lockouts'] - 1 ) );
				$locked_duration       = min( $max_lockout, (int) ( $base_lockout * pow( 2, $exponent ) ) );
				$state['attempts']     = 0;
				$state['locked_until'] = $now + $locked_duration;
				$lockouts_count        = $state['lockouts'];
				$triggered_lock        = true;

				set_transient( $key, $state, $locked_duration + $attempt_window );
			} else {
				set_transient( $key, $state, $attempt_window );
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
	 * Collect all applicable transient keys for an account identifier and IP.
	 *
	 * @param string      $identifier Canonical username or submitted login string.
	 * @param string|null $ip         Optional client IP override.
	 *
	 * @return array
	 */
	private static function get_bucket_keys( $identifier, $ip = null ) {
		$keys        = array();
		$account_key = self::get_account_key( $identifier );
		$resolved_ip = null === $ip ? self::get_client_ip() : (string) $ip;
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
	 * Build the normalized transient key for an account identifier.
	 *
	 * @param string $identifier Canonical username or submitted login string.
	 *
	 * @return string Empty string when identifier is empty.
	 */
	private static function get_account_key( $identifier ) {
		$normalized = strtolower( trim( (string) $identifier ) );

		if ( '' === $normalized ) {
			return '';
		}

		return self::ACCOUNT_KEY_PREFIX . md5( $normalized );
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
