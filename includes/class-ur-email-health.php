<?php
/**
 * Email Health Diagnostic Service
 *
 * Inspects User Registration email settings, form login requirements,
 * sender domain alignment, and active SMTP / mail transport configuration.
 *
 * @class    UR_Email_Health
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Email_Health' ) ) :

	/**
	 * UR_Email_Health Class.
	 */
	class UR_Email_Health {

		/**
		 * Known third-party SMTP / transactional mail plugins.
		 *
		 * @var array<string, string>
		 */
		private static $known_smtp_plugins = array(
			'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
			'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
			'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
			'post-smtp/postman-smtp.php'        => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
			'smtp-mailer/main.php'              => 'SMTP Mailer',
			'wp-smtp/wp-smtp.php'               => 'WP SMTP',
			'mailgun/mailgun.php'               => 'Mailgun',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
			'wp-ses/wp-ses.php'                 => 'WP Offload SES',
			'gravitysmtp/gravitysmtp.php'       => 'Gravity SMTP',
			'suremails/suremails.php'           => 'SureMails',
			'yay-smtp/yay-smtp.php'             => 'YaySMTP',
			'brevo-wp/brevo-wp.php'             => 'Brevo',
			'mailin/sendinblue.php'             => 'Brevo',
		);

		/**
		 * UR callbacks on mail hooks that must be ignored when attributing third-party transports.
		 *
		 * @var array<int, string>
		 */
		private static $own_callbacks = array(
			'UR_Emailer::ur_sender_email',
			'UR_Emailer::ur_sender_name',
			'UR_Emailer::ur_get_content_type',
			'ur_email_send_failed_handler',
		);

		/**
		 * Build the full Email Health diagnostic report.
		 *
		 * @param string $incoming_issue Optional issue context ('spam' | 'not_received' | 'failed').
		 * @return array<string, mixed>
		 */
		public static function get_report( $incoming_issue = '' ) {
			$incoming_issue  = self::sanitize_incoming_issue( $incoming_issue );
			$site_domain     = self::get_site_domain();
			$admin_email     = (string) get_option( 'admin_email', '' );
			$recent_failures = self::get_recent_failures();
			$smtp            = self::detect_smtp( $recent_failures['last_error'] );
			$from_address    = self::get_from_address_info( $site_domain, $admin_email );
			$confirmation    = self::get_email_confirmation_info();
			$emails_disabled = ur_option_checked( 'user_registration_email_setting_disable_email' );

			$report = array(
				'site_domain'        => $site_domain,
				'admin_email'        => $admin_email,
				'emails_disabled'    => $emails_disabled,
				'email_confirmation' => $confirmation,
				'from_address'       => $from_address,
				'smtp'               => $smtp,
				'recent_failures'    => $recent_failures,
				'incoming_issue'     => $incoming_issue,
			);

			$report['sidebar_dot'] = self::compute_sidebar_dot( $report );

			return $report;
		}

		/**
		 * Compute the sidebar indicator state for Email Health without running heavy form queries if not needed.
		 *
		 * @param string $incoming_issue Optional incoming issue context.
		 * @return string 'critical' | 'warning' | ''
		 */
		public static function get_sidebar_dot_status( $incoming_issue = '' ) {
			$report = self::get_report( $incoming_issue );

			return $report['sidebar_dot'];
		}

		/**
		 * Detect active SMTP / transactional mail transport configuration.
		 *
		 * @param string $recent_error Latest captured delivery failure message.
		 * @return array<string, mixed>
		 */
		public static function detect_smtp( $recent_error = '' ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$can_install  = current_user_can( 'install_plugins' );
			$can_activate = current_user_can( 'activate_plugins' );
			$setup_url    = admin_url( 'admin.php?page=smart-smtp&urm_setup=google_workspace&is_active=true&source=urm#/primary-connection' );
			$logs_url     = admin_url( 'admin.php?page=smart-smtp#/mail-logs' );

			if ( is_plugin_active( 'smart-smtp/smart-smtp.php' ) ) {
				return self::inspect_smart_smtp( $recent_error, $setup_url, $logs_url, $can_install, $can_activate );
			}

			foreach ( self::$known_smtp_plugins as $plugin_file => $plugin_name ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return array(
						'status'               => 'other-smtp',
						'provider_type'        => '',
						'provider_label'       => $plugin_name,
						'other_smtp_name'      => $plugin_name,
						'force_from'           => self::probe_force_from(),
						'account_email'        => '',
						'setup_url'            => $setup_url,
						'logs_url'             => $logs_url,
						'smart_smtp_installed' => file_exists( WP_PLUGIN_DIR . '/smart-smtp/smart-smtp.php' ),
						'can_install_plugins'  => $can_install,
						'can_activate_plugins' => $can_activate,
					);
				}
			}

			$probed_owner = self::probe_third_party_transport();
			if ( '' !== $probed_owner ) {
				return array(
					'status'               => 'other-smtp',
					'provider_type'        => '',
					'provider_label'       => $probed_owner,
					'other_smtp_name'      => $probed_owner,
					'force_from'           => self::probe_force_from(),
					'account_email'        => '',
					'setup_url'            => $setup_url,
					'logs_url'             => $logs_url,
					'smart_smtp_installed' => file_exists( WP_PLUGIN_DIR . '/smart-smtp/smart-smtp.php' ),
					'can_install_plugins'  => $can_install,
					'can_activate_plugins' => $can_activate,
				);
			}

			return array(
				'status'               => 'none',
				'provider_type'        => '',
				'provider_label'       => '',
				'other_smtp_name'      => '',
				'force_from'           => false,
				'account_email'        => '',
				'setup_url'            => $setup_url,
				'logs_url'             => $logs_url,
				'smart_smtp_installed' => file_exists( WP_PLUGIN_DIR . '/smart-smtp/smart-smtp.php' ),
				'can_install_plugins'  => $can_install,
				'can_activate_plugins' => $can_activate,
			);
		}

		/**
		 * Inspect SmartSMTP options to determine connection state and sender handling.
		 *
		 * @param string $recent_error Latest captured delivery failure message.
		 * @param string $setup_url    Deep link to SmartSMTP primary connection setup.
		 * @param string $logs_url     Deep link to SmartSMTP mail logs.
		 * @param bool   $can_install  Whether current user can install plugins.
		 * @param bool   $can_activate Whether current user can activate plugins.
		 * @return array<string, mixed>
		 */
		private static function inspect_smart_smtp( $recent_error, $setup_url, $logs_url, $can_install, $can_activate ) {
			$provider_type = (string) get_option( 'smart_smtp_provider_type', 'default' );
			$config        = array();

			if ( '' !== $provider_type && 'default' !== $provider_type ) {
				$raw_config = get_option( 'smart_smtp_' . $provider_type . '_configuration', array() );
				if ( is_array( $raw_config ) ) {
					$config = $raw_config;
				}
			}

			$provider_labels = array(
				'gmail' => __( 'SmartSMTP (Google Workspace)', 'user-registration' ),
				'brevo' => __( 'SmartSMTP (Brevo)', 'user-registration' ),
				'other' => __( 'SmartSMTP (Custom SMTP)', 'user-registration' ),
			);
			$provider_label  = isset( $provider_labels[ $provider_type ] ) ? $provider_labels[ $provider_type ] : __( 'SmartSMTP', 'user-registration' );
			$status          = 'unconfigured';

			if ( 'gmail' === $provider_type ) {
				if ( ! empty( $config['access_token'] ) ) {
					$status = self::is_gmail_token_expired( $config, $recent_error ) ? 'token-expired' : 'connected';
				}
			} elseif ( 'brevo' === $provider_type ) {
				$status = ! empty( $config['smtp_api_key'] ) ? 'connected' : 'unconfigured';
			} elseif ( 'other' === $provider_type ) {
				$status = ! empty( $config['smtp_host'] ) ? 'connected' : 'unconfigured';
			} elseif ( '' !== $provider_type && 'default' !== $provider_type && ! empty( $config ) ) {
				$status = 'connected';
			}

			$account_email = '';
			if ( ! empty( $config['account_email'] ) ) {
				$account_email = sanitize_email( $config['account_email'] );
			} elseif ( ! empty( $config['smtp_from_email_address'] ) ) {
				$account_email = sanitize_email( $config['smtp_from_email_address'] );
			}

			return array(
				'status'               => $status,
				'provider_type'        => $provider_type,
				'provider_label'       => $provider_label,
				'other_smtp_name'      => '',
				'force_from'           => ! empty( $config['smtp_force_from_email'] ),
				'account_email'        => $account_email,
				'setup_url'            => $setup_url,
				'logs_url'             => $logs_url,
				'smart_smtp_installed' => true,
				'can_install_plugins'  => $can_install,
				'can_activate_plugins' => $can_activate,
			);
		}

		/**
		 * Determine whether a SmartSMTP Gmail token is expired and cannot auto-refresh.
		 *
		 * @param array  $config       SmartSMTP Gmail configuration array.
		 * @param string $recent_error Latest captured delivery failure message.
		 * @return bool
		 */
		private static function is_gmail_token_expired( $config, $recent_error ) {
			$raw_token     = isset( $config['access_token'] ) ? $config['access_token'] : '';
			$refresh_token = isset( $config['refresh_token'] ) ? (string) $config['refresh_token'] : '';
			$token_data    = is_array( $raw_token ) ? $raw_token : json_decode( (string) $raw_token, true );

			if ( is_array( $token_data ) ) {
				if ( '' === $refresh_token && ! empty( $token_data['refresh_token'] ) ) {
					$refresh_token = (string) $token_data['refresh_token'];
				}
				if ( isset( $token_data['created'], $token_data['expires_in'] ) ) {
					$expires_at = (int) $token_data['created'] + (int) $token_data['expires_in'];
					// Only mark expired when no refresh token exists to silently renew the session.
					if ( $expires_at < time() && '' === $refresh_token ) {
						return true;
					}
				}
			}

			if ( '' !== $recent_error ) {
				$lower = strtolower( $recent_error );
				if ( false !== strpos( $lower, 'invalid_grant' ) || false !== strpos( $lower, 'authentication' ) || false !== strpos( $lower, 'reconnect' ) || false !== strpos( $lower, '401' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Probe pluggable wp_mail(), pre_wp_mail, and throwaway PHPMailer for non-core transports.
		 *
		 * @return string Owning plugin/integration name, or empty string when using server PHP mail.
		 */
		private static function probe_third_party_transport() {
			$replacement = self::wp_mail_replacement_owner();
			if ( '' !== $replacement ) {
				return $replacement;
			}

			$diverter = self::first_hook_owner( 'pre_wp_mail' );
			if ( '' !== $diverter ) {
				return $diverter;
			}

			$phpmailer = self::probe_phpmailer();
			if ( $phpmailer && isset( $phpmailer->Mailer ) && 'mail' !== $phpmailer->Mailer ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$switcher = self::transport_switcher_owner();
				return '' !== $switcher ? $switcher : __( 'Custom SMTP', 'user-registration' );
			}

			return '';
		}

		/**
		 * Check whether a third-party plugin or SMTP configuration forces the From address.
		 *
		 * @return bool
		 */
		private static function probe_force_from() {
			$sentinel = 'ur-probe@example.invalid';
			$filtered = (string) apply_filters( 'wp_mail_from', $sentinel );
			if ( $sentinel !== $filtered && '' !== $filtered ) {
				return true;
			}

			$phpmailer = self::probe_phpmailer( $sentinel );
			if ( $phpmailer && isset( $phpmailer->From ) && '' !== $phpmailer->From && $sentinel !== $phpmailer->From ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return true;
			}

			return false;
		}

		/**
		 * Build a throwaway PHPMailer instance and fire phpmailer_init without sending any mail.
		 *
		 * @param string $seed_from Optional sentinel From address.
		 * @return PHPMailer\PHPMailer\PHPMailer|null
		 */
		private static function probe_phpmailer( $seed_from = '' ) {
			if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}

			try {
				$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );
				if ( '' !== $seed_from ) {
					$phpmailer->setFrom( $seed_from, 'UR Probe', false );
				}
				/** This action is documented in wp-includes/pluggable.php */
				do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );

				return $phpmailer;
			} catch ( Throwable $e ) {
				return null;
			}
		}

		/**
		 * Identify the phpmailer_init callback that switches Mailer away from PHP mail().
		 *
		 * @return string
		 */
		private static function transport_switcher_owner() {
			global $wp_filter;

			if ( empty( $wp_filter['phpmailer_init'] ) || ! isset( $wp_filter['phpmailer_init']->callbacks ) ) {
				return '';
			}

			foreach ( $wp_filter['phpmailer_init']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! isset( $callback['function'] ) || ! is_callable( $callback['function'] ) ) {
						continue;
					}
					if ( self::is_own_callback( $callback['function'] ) ) {
						continue;
					}
					try {
						$candidate = new PHPMailer\PHPMailer\PHPMailer( true );
						call_user_func_array( $callback['function'], array( &$candidate ) );
						if ( isset( $candidate->Mailer ) && 'mail' !== $candidate->Mailer ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							return self::describe_callback_owner( $callback['function'] );
						}
					} catch ( Throwable $e ) {
						continue;
					}
				}
			}

			return '';
		}

		/**
		 * Resolve the first non-UR callback owner registered on a hook.
		 *
		 * @param string $hook Hook name.
		 * @return string
		 */
		private static function first_hook_owner( $hook ) {
			global $wp_filter;

			if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
				return '';
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! isset( $callback['function'] ) || ! is_callable( $callback['function'] ) ) {
						continue;
					}
					if ( self::is_own_callback( $callback['function'] ) ) {
						continue;
					}
					$owner = self::describe_callback_owner( $callback['function'] );
					if ( '' !== $owner ) {
						return $owner;
					}
				}
			}

			return '';
		}

		/**
		 * Detect whether a plugin replaced pluggable wp_mail() directly.
		 *
		 * @return string
		 */
		private static function wp_mail_replacement_owner() {
			if ( ! function_exists( 'wp_mail' ) ) {
				return '';
			}

			try {
				$reflection = new ReflectionFunction( 'wp_mail' );
				$core_file  = wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' );
				$actual     = wp_normalize_path( (string) $reflection->getFileName() );

				return $actual === $core_file ? '' : self::describe_file_owner( $actual );
			} catch ( ReflectionException $e ) {
				return '';
			}
		}

		/**
		 * Determine whether a hook callback belongs to User Registration itself.
		 *
		 * @param callable $callback Hook callback.
		 * @return bool
		 */
		private static function is_own_callback( $callback ) {
			if ( is_string( $callback ) ) {
				return in_array( $callback, self::$own_callbacks, true );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				return in_array( $class . '::' . $callback[1], self::$own_callbacks, true );
			}

			return false;
		}

		/**
		 * Map a callable to a human-readable plugin, theme, or custom handler name.
		 *
		 * @param callable $callback Hook callback.
		 * @return string
		 */
		private static function describe_callback_owner( $callback ) {
			try {
				if ( is_array( $callback ) && 2 === count( $callback ) ) {
					$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
					$reflection = new ReflectionMethod( $class, $callback[1] );
				} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
					$reflection = new ReflectionMethod( $callback );
				} elseif ( is_object( $callback ) && ! $callback instanceof Closure && method_exists( $callback, '__invoke' ) ) {
					$reflection = new ReflectionMethod( $callback, '__invoke' );
				} else {
					$reflection = new ReflectionFunction( $callback );
				}

				return self::describe_file_owner( (string) $reflection->getFileName() );
			} catch ( ReflectionException $e ) {
				return '';
			}
		}

		/**
		 * Turn a file path into a display name for the owning plugin, mu-plugin, theme, or snippet.
		 *
		 * @param string $file Absolute file path.
		 * @return string
		 */
		private static function describe_file_owner( $file ) {
			if ( '' === $file ) {
				return '';
			}

			$file       = wp_normalize_path( $file );
			$mu_dir     = wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
			$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
			$theme_root = wp_normalize_path( get_theme_root() );

			if ( 0 === strpos( $file, $mu_dir ) ) {
				return __( 'a must-use plugin', 'user-registration' );
			}

			if ( 0 === strpos( $file, $plugin_dir ) ) {
				$relative = ltrim( substr( $file, strlen( $plugin_dir ) ), '/' );
				$slug     = strtok( $relative, '/' );

				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				foreach ( get_plugins() as $plugin_file => $data ) {
					if ( strtok( $plugin_file, '/' ) === $slug && ! empty( $data['Name'] ) ) {
						return (string) $data['Name'];
					}
				}

				return (string) $slug;
			}

			if ( 0 === strpos( $file, $theme_root ) ) {
				return (string) wp_get_theme()->get( 'Name' );
			}

			return __( 'custom mail handler', 'user-registration' );
		}

		/**
		 * Inspect Email Confirmation notification status and whether any registration form requires it.
		 *
		 * @return array<string, mixed>
		 */
		private static function get_email_confirmation_info() {
			$disabled           = ! ur_option_checked( 'user_registration_enable_email_confirmation', true );
			$global_login       = (string) get_option( 'user_registration_general_setting_login_options', 'default' );
			$confirmation_modes = array( 'email_confirmation', 'admin_approval_after_email_confirmation' );
			$required_forms     = array();

			$form_ids = get_posts(
				array(
					'post_type'      => 'user_registration',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			if ( ! empty( $form_ids ) ) {
				foreach ( $form_ids as $form_id ) {
					$login_option = (string) ur_get_single_post_meta( $form_id, 'user_registration_form_setting_login_options', $global_login );
					if ( in_array( $login_option, $confirmation_modes, true ) ) {
						$title = get_the_title( $form_id );
						/* translators: %d: form ID */
						$required_forms[] = '' !== $title ? $title : sprintf( __( 'Form #%d', 'user-registration' ), $form_id );
					}
				}
			} elseif ( in_array( $global_login, $confirmation_modes, true ) ) {
				$required_forms[] = __( 'Default Registration', 'user-registration' );
			}

			return array(
				'disabled'                   => $disabled,
				'form_requires_confirmation' => ! empty( $required_forms ),
				'first_form_title'           => ! empty( $required_forms ) ? $required_forms[0] : '',
				'form_count'                 => count( $required_forms ),
			);
		}

		/**
		 * Parse configured From address and compare its domain against the site domain.
		 *
		 * @param string $site_domain Normalized site host without leading www.
		 * @param string $admin_email Site admin email fallback.
		 * @return array<string, mixed>
		 */
		private static function get_from_address_info( $site_domain, $admin_email ) {
			$raw   = (string) get_option( 'user_registration_email_from_address', $admin_email );
			$parts = explode( ',', $raw );
			// Field has `multiple` attribute; validate domain alignment against the primary sender address.
			$first = strtolower( trim( (string) $parts[0] ) );

			$from_domain = '';
			if ( false !== strpos( $first, '@' ) ) {
				$at_parts    = explode( '@', $first );
				$from_domain = strtolower( trim( (string) end( $at_parts ) ) );
			}

			$matches = false;
			if ( '' !== $site_domain && '' !== $from_domain ) {
				$matches = ( $from_domain === $site_domain ) || self::ends_with( $from_domain, '.' . $site_domain );
			} elseif ( '' === $site_domain ) {
				$matches = true;
			}

			return array(
				'raw'            => $raw,
				'first'          => $first,
				'matches_domain' => $matches,
				'suggested'      => '' !== $site_domain ? 'noreply@' . $site_domain : $admin_email,
			);
		}

		/**
		 * Read recent mail delivery failures recorded by UR_Emailer.
		 *
		 * @return array<string, mixed>
		 */
		private static function get_recent_failures() {
			$failed_data = get_transient( 'user_registration_mail_send_failed_count' );
			$count       = is_array( $failed_data ) && isset( $failed_data['failed_count'] ) ? (int) $failed_data['failed_count'] : 0;
			$raw_error   = is_array( $failed_data ) && isset( $failed_data['error_message'] ) ? (string) $failed_data['error_message'] : '';
			$clean_error = trim( wp_strip_all_tags( $raw_error ) );

			// Suppress legacy Status Log boilerplate stored before ur_email_send_failed_handler() was fixed.
			if ( false !== strpos( $clean_error, 'ur_mail_logs' ) && false !== strpos( $clean_error, 'Status Log' ) ) {
				$clean_error = '';
			}

			return array(
				'count'      => $count,
				'last_error' => $clean_error,
			);
		}

		/**
		 * Determine the Email Health sidebar dot state.
		 *
		 * @param array $report Diagnostic report array.
		 * @return string 'critical' | 'warning' | ''
		 */
		private static function compute_sidebar_dot( $report ) {
			$has_critical = ! empty( $report['emails_disabled'] )
				|| ! empty( $report['email_confirmation']['disabled'] )
				|| ( isset( $report['smtp']['status'] ) && 'token-expired' === $report['smtp']['status'] )
				|| ( isset( $report['recent_failures']['count'] ) && (int) $report['recent_failures']['count'] > 0 )
				|| ! empty( $report['recent_failures']['last_error'] );

			if ( $has_critical ) {
				return 'critical';
			}

			if ( ! empty( $report['incoming_issue'] ) ) {
				return 'warning';
			}

			return '';
		}

		/**
		 * Return the site host without a leading www. prefix.
		 *
		 * @return string
		 */
		private static function get_site_domain() {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$host = strtolower( trim( $host ) );
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			return $host;
		}

		/**
		 * Sanitize incoming issue query/request parameter.
		 *
		 * @param string $issue Raw issue identifier.
		 * @return string
		 */
		public static function sanitize_incoming_issue( $issue ) {
			$issue = sanitize_key( (string) $issue );
			return in_array( $issue, array( 'spam', 'not_received', 'failed' ), true ) ? $issue : '';
		}

		/**
		 * Check whether a string ends with a given suffix (PHP 7.4 compatible).
		 *
		 * @param string $haystack Full string.
		 * @param string $needle   Expected suffix.
		 * @return bool
		 */
		private static function ends_with( $haystack, $needle ) {
			$length = strlen( $needle );
			if ( 0 === $length ) {
				return true;
			}

			return substr( $haystack, -$length ) === $needle;
		}
	}
endif;
